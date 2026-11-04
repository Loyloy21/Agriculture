<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/mailer.php';

/*
 * Farmer Forgot Password with a one-time emailed code (no app deep links needed).
 *
 *   1. auth/forgot-password.php    email            -> always the same 200 response; a 6-digit code is
 *                                                       emailed only when a farmer account uses that email.
 *   2. auth/verify-reset-code.php  email + code      -> one-time reset token (15 minutes).
 *   3. auth/reset-password.php     token + password  -> new password_hash(), every farmer session revoked.
 *
 * Only a password_hash() verifier of the code and a SHA-256 hash of the reset token are stored. The old
 * password is never read back, shown, emailed or logged. Approval, rejection, block and account status
 * are never written: login.php still decides who may sign in.
 */

const PASSWORD_RESET_CODE_DIGITS = 6;
const PASSWORD_RESET_CODE_TTL_SECONDS = 600;
const PASSWORD_RESET_RESEND_COOLDOWN_SECONDS = 60;
const PASSWORD_RESET_MAX_CODE_ATTEMPTS = 5;
const PASSWORD_RESET_TOKEN_TTL_SECONDS = 900;
const PASSWORD_RESET_WINDOW_SECONDS = 3600;
/** Events allowed per window: [per email, per IP address]; null means not limited on that subject. */
const PASSWORD_RESET_LIMITS = [
    'request' => [5, 20],
    'verify_failed' => [10, 30],
    'reset' => [null, 20],
];
const PASSWORD_RESET_EVENT_RETENTION_SECONDS = 172800;
const PASSWORD_RESET_ROW_RETENTION_DAYS = 30;
/** Same bcrypt cost as real code verifiers, so an unknown email takes as long to check as a real one. */
const PASSWORD_RESET_DUMMY_VERIFIER = '$2y$10$e6oSOw565xdoT0K2Plw/IuVzSX5DIJnVU84OSPZ8deHXhS5igeoUi';

function password_reset_normalize_email(mixed $value): ?string
{
    $email = strtolower(trim(is_string($value) ? $value : ''));
    return $email !== '' && strlen($email) <= 160 && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
}

/** Same rule as registration (backend/api/farmers/register.php). */
function farmer_password_policy_error(string $password): ?string
{
    if (strlen($password) < 8 || !preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/\d/', $password)) {
        return 'Use at least 8 characters with uppercase, lowercase, and a number.';
    }
    return null;
}

/**
 * The client address used for IP rate limits. Requests from the Cloudflare tunnel reach Apache through
 * the local API gateway, so every one of them arrives from loopback; only then is Cloudflare's
 * client-address header used. Requests from any other address use that address.
 */
function password_reset_client_ip(): string
{
    $remote = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if (in_array($remote, ['127.0.0.1', '::1'], true)) {
        $forwarded = trim((string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
        if (filter_var($forwarded, FILTER_VALIDATE_IP)) return $forwarded;
    }
    return $remote !== '' ? $remote : 'unknown';
}

/** Emails and IP addresses are kept in the rate-limit ledger only as hashes. */
function password_reset_hash(string $purpose, string $value): string
{
    return hash('sha256', 'agrireach-password-reset:' . $purpose . ':' . $value);
}

function password_reset_generate_code(): string
{
    return str_pad((string) random_int(0, 10 ** PASSWORD_RESET_CODE_DIGITS - 1), PASSWORD_RESET_CODE_DIGITS, '0', STR_PAD_LEFT);
}

function password_reset_generate_token(): string
{
    return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

/**
 * Whether $action is currently refused for this email/IP. Counts ledger rows, which are written for
 * unknown emails too, so the answer is the same whether or not an account exists.
 *
 * @return array{reason:'cooldown'|'limit',retryAfter:int}|null
 */
function password_reset_throttled(PDO $connection, string $action, ?string $emailHash, string $ipHash): ?array
{
    [$emailLimit, $ipLimit] = PASSWORD_RESET_LIMITS[$action];
    $now = db_sql_utc_now($connection);
    $window = PASSWORD_RESET_WINDOW_SECONDS;
    $statement = $connection->prepare(
        "SELECT {$now} AS now_utc,
                COUNT(CASE WHEN email_hash = ? THEN 1 END) AS email_count,
                MIN(CASE WHEN email_hash = ? THEN created_at_utc END) AS email_oldest,
                MAX(CASE WHEN email_hash = ? THEN created_at_utc END) AS email_latest,
                COUNT(CASE WHEN ip_hash = ? THEN 1 END) AS ip_count,
                MIN(CASE WHEN ip_hash = ? THEN created_at_utc END) AS ip_oldest
         FROM farmer_password_reset_events
         WHERE action = ? AND created_at_utc > {$now} - INTERVAL '{$window}' SECOND AND (email_hash = ? OR ip_hash = ?)"
    );
    $statement->execute([$emailHash, $emailHash, $emailHash, $ipHash, $ipHash, $action, $emailHash, $ipHash]);
    $row = $statement->fetch();
    $nowSeconds = (int) strtotime($row['now_utc'] . ' UTC');
    $age = static fn (?string $time): int => $time === null ? 0 : max(0, $nowSeconds - (int) strtotime($time . ' UTC'));

    $cooldown = 0;
    if ($action === 'request' && $row['email_latest'] !== null) {
        $cooldown = max(0, PASSWORD_RESET_RESEND_COOLDOWN_SECONDS - $age($row['email_latest']));
    }
    $limitWait = 0;
    foreach ([[$emailLimit, 'email'], [$ipLimit, 'ip']] as [$limit, $subject]) {
        if ($limit !== null && (int) $row[$subject . '_count'] >= $limit) {
            $limitWait = max($limitWait, $window - $age($row[$subject . '_oldest']));
        }
    }
    // A reached limit is reported as such (it is usually the longer wait); otherwise the resend cooldown.
    if ($limitWait > 0) return ['reason' => 'limit', 'retryAfter' => max(1, $limitWait, $cooldown)];
    if ($cooldown > 0) return ['reason' => 'cooldown', 'retryAfter' => $cooldown];
    return null;
}

function password_reset_record_event(PDO $connection, string $action, ?string $emailHash, string $ipHash): void
{
    $connection->prepare('INSERT INTO farmer_password_reset_events (action, email_hash, ip_hash, created_at_utc) VALUES (?, ?, ?, ' . db_sql_utc_now($connection) . ')')
        ->execute([$action, $emailHash, $ipHash]);
}

function password_reset_throttle_response(array $throttle): never
{
    $retryAfter = (int) $throttle['retryAfter'];
    header('Retry-After: ' . $retryAfter);
    if ($throttle['reason'] === 'cooldown') {
        api_response(429, [
            'success' => false,
            'status' => 'cooldown',
            'message' => 'Please wait ' . $retryAfter . ' seconds before requesting another code.',
            'retryAfterSeconds' => $retryAfter,
        ]);
    }
    $minutes = (int) ceil($retryAfter / 60);
    api_response(429, [
        'success' => false,
        'status' => 'rate_limited',
        'message' => 'Too many attempts. Please try again in about ' . $minutes . ($minutes === 1 ? ' minute.' : ' minutes.'),
        'retryAfterSeconds' => $retryAfter,
    ]);
}

/**
 * Sends the JSON response and closes the connection, then lets the script continue. Work done
 * afterwards (account lookup, SMTP delivery) cannot change the response or its timing.
 */
function password_reset_respond_and_continue(int $status, array $payload): void
{
    $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    ignore_user_abort(true);
    set_time_limit(120);
    http_response_code($status);
    header('Content-Length: ' . strlen($body));
    header('Connection: close');
    echo $body;
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
        return;
    }
    while (ob_get_level() > 0) ob_end_flush();
    flush();
}

function build_password_reset_email(string $farmerName, string $code): array
{
    $name = trim($farmerName) !== '' ? trim($farmerName) : 'Farmer';
    $minutes = intdiv(PASSWORD_RESET_CODE_TTL_SECONDS, 60);
    $safeName = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeCode = htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $notice = 'If you did not ask to reset your AgriReach password, ignore this email; your password stays the same. AgriReach staff will never ask you for this code or for your password.';

    return [
        // The code is kept out of the subject so it does not appear in lock-screen previews.
        'subject' => 'Your AgriReach password reset code',
        'html' => '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#27301f;line-height:1.6">'
            . '<div style="max-width:600px;margin:auto;padding:24px;border:1px solid #dce5d6;border-radius:14px">'
            . '<h1 style="color:#2f8f5b;font-size:24px">Reset your AgriReach password</h1>'
            . '<p>Hello ' . $safeName . ',</p>'
            . '<p>Enter this code in the AgriReach app to choose a new password:</p>'
            . '<p style="font-size:32px;font-weight:bold;letter-spacing:8px;margin:18px 0;color:#174d36">' . $safeCode . '</p>'
            . '<p>The code expires in ' . $minutes . ' minutes and can be used once.</p>'
            . '<p style="margin-top:24px;color:#718078;font-size:13px">' . htmlspecialchars($notice, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
            . '</div></body></html>',
        'text' => "Hello {$name},\n\nEnter this code in the AgriReach app to choose a new password:\n\n{$code}\n\n"
            . "The code expires in {$minutes} minutes and can be used once.\n\n{$notice}",
    ];
}

/**
 * Controlled tests only: with the non-sending 'log' transport outside production, and only when
 * AGRIREACH_TEST_MAIL_CAPTURE_DIR names an existing folder, the simulated message is written there so a
 * test can read the code. Never active with SMTP or in production.
 */
function password_reset_capture_test_email(array $config, string $recipient, array $content, string $status): void
{
    $directory = trim((string) getenv('AGRIREACH_TEST_MAIL_CAPTURE_DIR'));
    if ($directory === '' || $status !== 'Simulated' || $config['transport'] !== 'log' || $config['app_environment'] === 'production' || !is_dir($directory)) return;
    $file = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . 'password-reset-' . hash('sha256', strtolower($recipient)) . '.json';
    file_put_contents($file, (string) json_encode(['to' => strtolower($recipient), 'subject' => $content['subject'], 'text' => $content['text']]), LOCK_EX);
}

/** Delivers through the existing registration mailer: the admin's Gmail sender, development routing and 'log' transport. */
function send_password_reset_email(string $recipient, string $farmerName, string $code): string
{
    $configuration = registration_email_configuration();
    $content = build_password_reset_email($farmerName, $code);
    $status = send_prepared_registration_email($configuration, $recipient, $farmerName, $content, true);
    password_reset_capture_test_email($configuration, $recipient, $content, $status);
    return $status;
}

/** Runs after the response was sent. Issues and emails a new code only when a farmer account uses $email. */
function password_reset_issue_code(PDO $connection, string $email): void
{
    try {
        $lookup = $connection->prepare("SELECT id, email, first_name, full_name FROM farmers WHERE email = ? AND password_hash IS NOT NULL AND password_hash <> '' LIMIT 1");
        $lookup->execute([$email]);
        $farmer = $lookup->fetch();
        if (!$farmer) return;

        $farmerId = (int) $farmer['id'];
        $code = password_reset_generate_code();
        $now = db_sql_utc_now($connection);
        $ttl = PASSWORD_RESET_CODE_TTL_SECONDS;
        $connection->beginTransaction();
        // Only the newest code works: earlier codes and unused reset tokens stop working.
        $connection->prepare("UPDATE farmer_password_resets SET revoked_at_utc = {$now}, revoke_reason = 'superseded' WHERE farmer_id = ? AND used_at_utc IS NULL AND revoked_at_utc IS NULL")
            ->execute([$farmerId]);
        $connection->prepare("INSERT INTO farmer_password_resets (farmer_id, code_verifier, expires_at_utc, created_at_utc) VALUES (?, ?, {$now} + INTERVAL '{$ttl}' SECOND, {$now})")
            ->execute([$farmerId, password_hash($code, PASSWORD_BCRYPT, ['cost' => 10])]);
        $resetId = db_insert_id($connection, 'farmer_password_resets');
        $connection->commit();
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) $connection->rollBack();
        error_log('Password reset code could not be issued: ' . get_class($exception) . ' ' . $exception->getMessage());
        return;
    }

    $failureCode = null;
    try {
        $name = trim((string) ($farmer['first_name'] ?? '')) ?: (string) $farmer['full_name'];
        $deliveryStatus = send_password_reset_email((string) $farmer['email'], $name, $code);
    } catch (VerificationEmailException $exception) {
        $deliveryStatus = 'Failed';
        $failureCode = $exception->failureCode;
    } catch (Throwable $exception) {
        $deliveryStatus = 'Failed';
        $failureCode = 'delivery_error';
    }
    unset($code);
    if ($failureCode !== null) error_log('Password reset email was not delivered: ' . $failureCode);

    try {
        $connection->prepare('UPDATE farmer_password_resets SET delivery_status = ?, delivery_failure_code = ? WHERE id = ?')
            ->execute([$deliveryStatus, $failureCode, $resetId]);
    } catch (Throwable $exception) {
        error_log('Password reset delivery status could not be saved: ' . get_class($exception));
    }
}

/** Removes old ledger rows and long-expired reset rows. Runs after the response was sent. */
function password_reset_cleanup(PDO $connection): void
{
    try {
        $now = db_sql_utc_now($connection);
        $connection->exec("DELETE FROM farmer_password_reset_events WHERE created_at_utc < {$now} - INTERVAL '" . PASSWORD_RESET_EVENT_RETENTION_SECONDS . "' SECOND");
        $connection->exec("DELETE FROM farmer_password_resets WHERE created_at_utc < {$now} - INTERVAL '" . PASSWORD_RESET_ROW_RETENTION_DAYS . "' DAY");
    } catch (Throwable $exception) {
        error_log('Password reset cleanup failed: ' . get_class($exception));
    }
}
