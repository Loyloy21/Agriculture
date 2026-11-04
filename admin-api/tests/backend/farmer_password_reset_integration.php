<?php
declare(strict_types=1);

/**
 * Farmer Forgot Password, end to end through the real PHP API.
 *
 * DISPOSABLE DATABASE AND NON-SENDING MAIL ONLY. The test refuses to run unless:
 *   - the database connection is not Supabase (point AGRIREACH_DB_CONFIG_FILE at a throwaway database),
 *   - mail resolves to the 'log' transport outside production (no SMTP sender can be decrypted),
 *   - AGRIREACH_TEST_MAIL_CAPTURE_DIR names a folder, where the API writes the simulated messages.
 * The API server must run with the same environment, for example:
 *   APP_ENV=development MAIL_TRANSPORT=log AGRIREACH_EMAIL_ENCRYPTION_KEY_FILE=C:/nonexistent.key \
 *   AGRIREACH_DB_CONFIG_FILE=... AGRIREACH_TEST_MAIL_CAPTURE_DIR=... php -S 127.0.0.1:8193 -t C:/xampp/htdocs tests/support/router.php
 *   AGRIREACH_TEST_BASE_URL=http://127.0.0.1:8193 php tests/backend/farmer_password_reset_integration.php
 * Only @example.test farmers created here are used, and they are deleted afterwards.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../support/test_base_url.php';
require_once dirname(__DIR__, 2) . '/backend/config/database.php';
require_once dirname(__DIR__, 2) . '/backend/lib/farmer_password_reset.php';

define('API', agrireach_test_base_url() . '/CAPSTONE%20AGRICULTURE/api/auth/');

$settings = db_connection_settings();
if ($settings['name'] === 'supabase' || str_contains(strtolower($settings['host']), 'supabase')) {
    fwrite(STDERR, "Refusing to run: this test writes fixtures and must use a disposable database, not Supabase.\n");
    exit(1);
}
$mail = registration_email_configuration();
if ($mail['transport'] !== 'log' || $mail['app_environment'] === 'production' || registration_email_encryption_key() !== null) {
    fwrite(STDERR, "Refusing to run: mail must use the non-sending 'log' transport outside production, with no readable email key.\n");
    exit(1);
}
$captureDir = trim((string) getenv('AGRIREACH_TEST_MAIL_CAPTURE_DIR'));
if ($captureDir === '' || !is_dir($captureDir)) {
    fwrite(STDERR, "Refusing to run: set AGRIREACH_TEST_MAIL_CAPTURE_DIR to an existing folder (the same one the API server uses).\n");
    exit(1);
}

$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
    $checks++;
}

/** @return array{status:int,payload:array,body:string,seconds:float,headers:string} */
function call(string $endpoint, array|string|null $body, string $ip, string $method = 'POST', ?string $bearer = null): array
{
    $curl = curl_init(API . $endpoint);
    $headers = ['Accept: application/json', 'Content-Type: application/json', 'CF-Connecting-IP: ' . $ip];
    if ($bearer !== null) $headers[] = 'Authorization: Bearer ' . $bearer;
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($body !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body, JSON_THROW_ON_ERROR));
    $started = microtime(true);
    $raw = curl_exec($curl);
    $seconds = microtime(true) - $started;
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    curl_close($curl);
    if (!is_string($raw)) throw new RuntimeException('Request to ' . $endpoint . ' failed.');
    $responseBody = substr($raw, $headerSize);
    $payload = json_decode($responseBody, true);
    return ['status' => $status, 'payload' => is_array($payload) ? $payload : [], 'body' => $responseBody, 'seconds' => $seconds, 'headers' => substr($raw, 0, $headerSize)];
}

function capture_file(string $email): string
{
    global $captureDir;
    return rtrim($captureDir, '/\\') . DIRECTORY_SEPARATOR . 'password-reset-' . hash('sha256', strtolower($email)) . '.json';
}

/** Waits for the simulated email written after the response; returns the code, or null when none arrives. */
function captured_code(string $email, float $waitSeconds = 6.0): ?string
{
    $deadline = microtime(true) + $waitSeconds;
    do {
        $file = capture_file($email);
        if (is_file($file)) {
            $message = json_decode((string) file_get_contents($file), true);
            @unlink($file);
            check(is_array($message) && $message['to'] === strtolower($email), 'captured email went to the registered address');
            check(preg_match('/\b(\d{6})\b/', (string) $message['text'], $match) === 1, 'captured email contains a 6-digit code');
            check(!str_contains((string) $message['subject'], $match[1]), 'code is kept out of the email subject');
            return $match[1];
        }
        usleep(100000);
    } while (microtime(true) < $deadline);
    return null;
}

function request_code(string $email, string $ip): array
{
    @unlink(capture_file($email));
    return call('forgot-password.php', ['email' => $email], $ip);
}

/** Staging-only time travel: makes earlier ledger rows for this email older, to step past the resend cooldown. */
function age_request_events(PDO $db, string $email, int $seconds): void
{
    $db->prepare("UPDATE farmer_password_reset_events SET created_at_utc = created_at_utc - INTERVAL '{$seconds}' SECOND WHERE email_hash = ?")
        ->execute([password_reset_hash('email', $email)]);
}

function farmer_row(PDO $db, int $id): array
{
    $statement = $db->prepare('SELECT * FROM farmers WHERE id = ?');
    $statement->execute([$id]);
    return $statement->fetch() ?: [];
}

function table_digest(PDO $db, string $table): string
{
    return hash('sha256', json_encode($db->query("SELECT * FROM {$table} ORDER BY 1")->fetchAll(), JSON_THROW_ON_ERROR));
}

$db = db();
$run = bin2hex(random_bytes(4));
$ipBase = '198.51.100.';
$ips = [];
$ip = static function (int $n) use (&$ips, $ipBase): string { $ips[$n] = $ipBase . (10 + $n); return $ips[$n]; };
$farmerIds = [];
$oldPassword = 'OldTyped9!' . $run;
$newPassword = 'NewTyped9!' . $run;
$createFarmer = static function (string $label, string $verification, string $account) use ($db, $run, $oldPassword, &$farmerIds): array {
    $email = "pwreset-{$run}-{$label}@example.test";
    $db->prepare("INSERT INTO farmers (full_name, first_name, last_name, contact_number, email, address, verification_status, account_status, password_hash, rejection_reason)
                  VALUES (?, ?, 'Fixture', ?, ?, 'Controlled test address', ?, ?, ?, ?)")
        ->execute(["Reset {$label} Fixture", "Reset{$label}", '+6390' . random_int(10000000, 99999999), $email, $verification, $account, password_hash($oldPassword, PASSWORD_DEFAULT), $verification === 'Rejected' ? 'Controlled reason' : null]);
    $id = db_insert_id($db, 'farmers');
    $farmerIds[] = $id;
    return ['id' => $id, 'email' => $email];
};
$unknown = static fn (string $label): string => "pwreset-{$run}-nobody-{$label}@example.test";

$adminBefore = [table_digest($db, 'admins'), table_digest($db, 'registration_email_settings'), table_digest($db, 'registration_email_templates')];
$serverLog = (string) getenv('AGRIREACH_TEST_SERVER_LOG');
$codesSeen = [];

try {
    $a = $createFarmer('approved', 'Approved', 'Active');
    $b = $createFarmer('pending', 'Pending', 'Active');
    $c = $createFarmer('blocked', 'Approved', 'Blocked');
    $d = $createFarmer('attempts', 'Approved', 'Active');
    $r = $createFarmer('rejected', 'Rejected', 'Active');

    // An existing session for A, which the reset must revoke.
    $login = call('login.php', ['email' => $a['email'], 'password' => $oldPassword], $ip(1));
    check($login['status'] === 200 && ($login['payload']['status'] ?? '') === 'authenticated', 'fixture farmer logs in with the old password');
    $oldToken = (string) $login['payload']['token'];

    // 1. Method, validation and identical responses for known and unknown emails.
    check(call('forgot-password.php', null, $ip(1), 'GET')['status'] === 405, 'GET forgot-password is 405');
    check(call('verify-reset-code.php', null, $ip(1), 'GET')['status'] === 405, 'GET verify-reset-code is 405');
    check(call('reset-password.php', null, $ip(1), 'GET')['status'] === 405, 'GET reset-password is 405');
    $bad = call('forgot-password.php', ['email' => 'not-an-email'], $ip(1));
    check($bad['status'] === 422 && isset($bad['payload']['errors']['email']), 'invalid email format is 422');
    check(call('forgot-password.php', '{bad json', $ip(1))['status'] === 400, 'malformed JSON is 400');

    $known = request_code(strtoupper($a['email']), $ip(1));
    $codeA1 = captured_code($a['email']);
    $none = request_code($unknown('one'), $ip(1));
    check($known['status'] === 200 && $none['status'] === 200, 'request is 200 for known and unknown emails');
    check($known['body'] === $none['body'], 'request response body is byte-identical for known and unknown emails');
    check(($known['payload']['data']['resendAfterSeconds'] ?? 0) === 60 && ($known['payload']['data']['expiresInSeconds'] ?? 0) === 600, 'response states the 60 s cooldown and 10 min expiry');
    check($codeA1 !== null, 'a code email was simulated for the registered email (entered in upper case)');
    check(captured_code($unknown('one'), 2.0) === null, 'no email is produced for an unknown address');
    printf("      timing: known %.3f s, unknown %.3f s (account lookup and delivery run after the response)\n", $known['seconds'], $none['seconds']);
    check(abs($known['seconds'] - $none['seconds']) < 1.0, 'known and unknown responses take a similar time');
    $codesSeen[] = $codeA1;

    // 2. Only a protected verifier is stored.
    $rows = $db->prepare('SELECT * FROM farmer_password_resets WHERE farmer_id = ?');
    $rows->execute([$a['id']]);
    $resetRows = $rows->fetchAll();
    check(count($resetRows) === 1, 'one reset row exists for the farmer');
    check(!str_contains(json_encode($resetRows), (string) $codeA1), 'the plaintext code is not stored anywhere in the reset row');
    check(password_verify((string) $codeA1, $resetRows[0]['code_verifier']) && str_starts_with($resetRows[0]['code_verifier'], '$2y$'), 'code_verifier is a bcrypt verifier of the code');
    check($resetRows[0]['delivery_status'] === 'Simulated', 'delivery was simulated (log transport), not sent');
    $events = json_encode($db->query('SELECT email_hash, ip_hash FROM farmer_password_reset_events')->fetchAll());
    check(!str_contains($events, '@') && !str_contains($events, $ipBase), 'the rate-limit ledger holds no raw emails or IP addresses');

    // 3. Resend cooldown, identical for known and unknown emails.
    $coolKnown = request_code($a['email'], $ip(1));
    $coolNone = request_code($unknown('one'), $ip(1));
    check($coolKnown['status'] === 429 && ($coolKnown['payload']['status'] ?? '') === 'cooldown', 'immediate resend is refused with the cooldown');
    check(($coolKnown['payload']['retryAfterSeconds'] ?? 0) >= 50 && ($coolKnown['payload']['retryAfterSeconds'] ?? 0) <= 60, 'cooldown reports the remaining seconds');
    check(str_contains($coolKnown['headers'], 'Retry-After:'), 'cooldown sends Retry-After');
    check($coolNone['status'] === 429 && ($coolNone['payload']['status'] ?? '') === 'cooldown', 'the cooldown applies to unknown emails too');
    check(captured_code($a['email'], 1.0) === null, 'no email is sent during the cooldown');

    // 4. A newer code replaces the older one.
    age_request_events($db, $a['email'], 61);
    check(request_code($a['email'], $ip(1))['status'] === 200, 'resend after the cooldown is accepted');
    $codeA2 = captured_code($a['email']);
    check($codeA2 !== null, 'the resend delivered a new code');
    $codesSeen[] = $codeA2;
    if ($codeA1 !== $codeA2) {
        $old = call('verify-reset-code.php', ['email' => $a['email'], 'code' => $codeA1], $ip(1));
        check($old['status'] === 400 && ($old['payload']['status'] ?? '') === 'invalid_code', 'the superseded code no longer works');
    }

    // 5. Code verification: format, wrong code, unknown email (identical answer).
    check(call('verify-reset-code.php', ['email' => $a['email'], 'code' => '12a456'], $ip(1))['status'] === 422, 'non-numeric code is 422');
    $wrongCode = $codeA2 === '000000' ? '000001' : '000000';
    $wrong = call('verify-reset-code.php', ['email' => $a['email'], 'code' => $wrongCode], $ip(1));
    $wrongUnknown = call('verify-reset-code.php', ['email' => $unknown('one'), 'code' => $wrongCode], $ip(1));
    check($wrong['status'] === 400 && $wrong['body'] === $wrongUnknown['body'], 'a wrong code and an unknown email get the same 400 answer');

    $verified = call('verify-reset-code.php', ['email' => $a['email'], 'code' => " {$codeA2} "], $ip(1));
    check($verified['status'] === 200 && ($verified['payload']['status'] ?? '') === 'verified', 'the newest code is accepted');
    $resetToken = (string) ($verified['payload']['data']['resetToken'] ?? '');
    check(preg_match('/^[A-Za-z0-9_-]{43}$/', $resetToken) === 1, 'a one-time reset token is returned');
    check(call('verify-reset-code.php', ['email' => $a['email'], 'code' => $codeA2], $ip(1))['status'] === 400, 'the code is single-use');
    $tokenRow = $db->prepare('SELECT reset_token_hash FROM farmer_password_resets WHERE farmer_id = ? AND verified_at_utc IS NOT NULL');
    $tokenRow->execute([$a['id']]);
    check($tokenRow->fetchColumn() === hash('sha256', $resetToken), 'only the SHA-256 of the reset token is stored');

    // 6. Password rules, then the reset itself.
    $farmerBefore = farmer_row($db, $a['id']);
    check(call('reset-password.php', ['resetToken' => 'short', 'password' => $newPassword, 'confirmPassword' => $newPassword], $ip(1))['status'] === 400, 'a malformed token is 400');
    $weak = call('reset-password.php', ['resetToken' => $resetToken, 'password' => 'weakpass1', 'confirmPassword' => 'weakpass1'], $ip(1));
    check($weak['status'] === 422 && ($weak['payload']['errors']['password'] ?? '') === 'Use at least 8 characters with uppercase, lowercase, and a number.', 'the registration password rule is enforced');
    $mismatch = call('reset-password.php', ['resetToken' => $resetToken, 'password' => $newPassword, 'confirmPassword' => $newPassword . 'x'], $ip(1));
    check($mismatch['status'] === 422 && isset($mismatch['payload']['errors']['confirmPassword']), 'a mismatched confirmation is 422');
    $same = call('reset-password.php', ['resetToken' => $resetToken, 'password' => $oldPassword, 'confirmPassword' => $oldPassword], $ip(1));
    check($same['status'] === 422 && isset($same['payload']['errors']['password']), 'the current password cannot be reused');
    check(!str_contains($same['body'], $oldPassword), 'the response never echoes the old password');
    $done = call('reset-password.php', ['resetToken' => $resetToken, 'password' => $newPassword, 'confirmPassword' => $newPassword], $ip(1));
    check($done['status'] === 200 && ($done['payload']['status'] ?? '') === 'reset', 'the password reset succeeds');
    check(call('reset-password.php', ['resetToken' => $resetToken, 'password' => 'Another9!' . $run, 'confirmPassword' => 'Another9!' . $run], $ip(1))['status'] === 400, 'the reset token is single-use');

    $farmerAfter = farmer_row($db, $a['id']);
    check(password_verify($newPassword, (string) $farmerAfter['password_hash']) && password_get_info((string) $farmerAfter['password_hash'])['algo'] === PASSWORD_DEFAULT, 'the new password is stored with password_hash(PASSWORD_DEFAULT)');
    unset($farmerBefore['password_hash'], $farmerAfter['password_hash']);
    check($farmerBefore === $farmerAfter, 'no other farmer column changed (approval, status, ownership, profile)');
    $sessions = $db->prepare('SELECT COUNT(*) FROM farmer_sessions WHERE farmer_id = ? AND revoked_at IS NULL');
    $sessions->execute([$a['id']]);
    check((int) $sessions->fetchColumn() === 0, 'every existing session of the farmer was revoked');
    check(call('me.php', null, $ip(1), 'GET', $oldToken)['status'] === 401, 'the old session token is rejected');
    $open = $db->prepare('SELECT COUNT(*) FROM farmer_password_resets WHERE farmer_id = ? AND used_at_utc IS NULL AND revoked_at_utc IS NULL');
    $open->execute([$a['id']]);
    check((int) $open->fetchColumn() === 0, 'no usable code or token remains for the farmer');

    check(call('login.php', ['email' => $a['email'], 'password' => $oldPassword], $ip(1))['status'] === 401, 'the old password no longer logs in');
    $relogin = call('login.php', ['email' => $a['email'], 'password' => $newPassword], $ip(1));
    check($relogin['status'] === 200 && ($relogin['payload']['status'] ?? '') === 'authenticated', 'the new password logs in');
    check(call('me.php', null, $ip(1), 'GET', (string) $relogin['payload']['token'])['status'] === 200, 'the new session works');

    // 7. Pending, blocked and rejected farmers: the password changes, the account decision does not.
    foreach ([[$b, 403, 'pending'], [$c, 403, 'blocked'], [$r, 403, 'rejected']] as [$farmer, $expectedStatus, $expectedLogin]) {
        $before = farmer_row($db, $farmer['id']);
        check(request_code($farmer['email'], $ip(2))['status'] === 200, "{$expectedLogin} farmer can request a code");
        $code = captured_code($farmer['email']);
        check($code !== null, "{$expectedLogin} farmer received a simulated code");
        $codesSeen[] = $code;
        $token = (string) (call('verify-reset-code.php', ['email' => $farmer['email'], 'code' => $code], $ip(2))['payload']['data']['resetToken'] ?? '');
        check(call('reset-password.php', ['resetToken' => $token, 'password' => $newPassword, 'confirmPassword' => $newPassword], $ip(2))['status'] === 200, "{$expectedLogin} farmer password reset succeeds");
        $after = farmer_row($db, $farmer['id']);
        unset($before['password_hash'], $after['password_hash']);
        check($before === $after, "{$expectedLogin} farmer: verification/account status unchanged");
        $attempt = call('login.php', ['email' => $farmer['email'], 'password' => $newPassword], $ip(2));
        check($attempt['status'] === $expectedStatus && ($attempt['payload']['status'] ?? '') === $expectedLogin, "{$expectedLogin} farmer login still reports '{$expectedLogin}'");
    }

    // 8. Attempt limit: five wrong codes lock that code, even the right one afterwards.
    check(request_code($d['email'], $ip(3))['status'] === 200, 'attempt-limit farmer requests a code');
    $codeD = captured_code($d['email']);
    check($codeD !== null, 'attempt-limit farmer received a code');
    $codesSeen[] = $codeD;
    for ($i = 0; $i < PASSWORD_RESET_MAX_CODE_ATTEMPTS; $i++) {
        $guess = str_pad((string) (((int) $codeD + 1 + $i) % 1000000), 6, '0', STR_PAD_LEFT);
        check(call('verify-reset-code.php', ['email' => $d['email'], 'code' => $guess], $ip(3))['status'] === 400, 'wrong guess ' . ($i + 1) . ' is refused');
    }
    $locked = call('verify-reset-code.php', ['email' => $d['email'], 'code' => $codeD], $ip(3));
    check($locked['status'] === 400, 'after five wrong guesses the correct code is refused too');
    $lockRow = $db->prepare("SELECT failed_attempts, revoke_reason FROM farmer_password_resets WHERE farmer_id = ? ORDER BY id DESC LIMIT 1");
    $lockRow->execute([$d['id']]);
    $lock = $lockRow->fetch();
    check((int) $lock['failed_attempts'] === 5 && $lock['revoke_reason'] === 'attempts', 'the code was revoked for too many attempts');

    // 9. Expiry of the code and of the reset token.
    age_request_events($db, $d['email'], 61);
    check(request_code($d['email'], $ip(3))['status'] === 200, 'a new code can be requested after the lock');
    $codeD2 = captured_code($d['email']);
    $codesSeen[] = $codeD2;
    $db->prepare("UPDATE farmer_password_resets SET expires_at_utc = " . db_sql_utc_now($db) . " - INTERVAL '1' SECOND WHERE farmer_id = ? AND revoked_at_utc IS NULL")->execute([$d['id']]);
    check(call('verify-reset-code.php', ['email' => $d['email'], 'code' => $codeD2], $ip(3))['status'] === 400, 'an expired code is refused');
    age_request_events($db, $d['email'], 61);
    request_code($d['email'], $ip(3));
    $codeD3 = captured_code($d['email']);
    $codesSeen[] = $codeD3;
    $tokenD = (string) (call('verify-reset-code.php', ['email' => $d['email'], 'code' => $codeD3], $ip(3))['payload']['data']['resetToken'] ?? '');
    $db->prepare("UPDATE farmer_password_resets SET reset_token_expires_at_utc = " . db_sql_utc_now($db) . " - INTERVAL '1' SECOND WHERE reset_token_hash = ?")->execute([hash('sha256', $tokenD)]);
    $late = call('reset-password.php', ['resetToken' => $tokenD, 'password' => $newPassword, 'confirmPassword' => $newPassword], $ip(3));
    check($late['status'] === 400 && ($late['payload']['status'] ?? '') === 'invalid_token', 'an expired reset token is refused');
    check(password_verify($oldPassword, (string) farmer_row($db, $d['id'])['password_hash']), 'refused resets leave the password unchanged');

    // 10. Rate limits by account (email, existence-independent) and by IP.
    $limited = $unknown('limit');
    for ($i = 1; $i <= PASSWORD_RESET_LIMITS['request'][0]; $i++) {
        check(request_code($limited, $ip(4))['status'] === 200, "email request {$i} within the hourly limit");
        age_request_events($db, $limited, 61);
    }
    $over = request_code($limited, $ip(4));
    check($over['status'] === 429 && ($over['payload']['status'] ?? '') === 'rate_limited', 'the sixth request in an hour for one email is rate limited (unknown email)');
    check(request_code($unknown('other-on-same-ip'), $ip(4))['status'] === 200, 'another email from the same IP is still allowed');

    for ($i = 1; $i <= PASSWORD_RESET_LIMITS['request'][1]; $i++) {
        $status = request_code($unknown('ip-' . $i), $ip(5))['status'];
        check($status === 200, "IP request {$i} within the hourly limit");
    }
    $ipOver = request_code($unknown('ip-over'), $ip(5));
    check($ipOver['status'] === 429 && ($ipOver['payload']['status'] ?? '') === 'rate_limited', 'the 21st request in an hour from one IP is rate limited');
    check(request_code($unknown('ip-over'), $ip(6))['status'] === 200, 'the same email from a different IP is allowed');

    $guessEmail = $unknown('guesses');
    for ($i = 1; $i <= PASSWORD_RESET_LIMITS['verify_failed'][0]; $i++) {
        check(call('verify-reset-code.php', ['email' => $guessEmail, 'code' => '123456'], $ip(7))['status'] === 400, "failed code check {$i} is answered normally");
    }
    $guessOver = call('verify-reset-code.php', ['email' => $guessEmail, 'code' => '123456'], $ip(7));
    check($guessOver['status'] === 429, 'failed code checks are rate limited per email');

    // 11. Nothing else changed, nothing was sent, nothing secret was logged.
    check($adminBefore === [table_digest($db, 'admins'), table_digest($db, 'registration_email_settings'), table_digest($db, 'registration_email_templates')], 'admin accounts and email settings/templates are unchanged');
    $statuses = $db->query("SELECT DISTINCT delivery_status FROM farmer_password_resets")->fetchAll(PDO::FETCH_COLUMN);
    check($statuses === ['Simulated'], 'every code delivery was simulated; no email was sent');
    if ($serverLog !== '' && is_file($serverLog)) {
        $log = (string) file_get_contents($serverLog);
        foreach (array_filter($codesSeen) as $seen) check(!preg_match('/\b' . preg_quote((string) $seen, '/') . '\b/', $log), 'the server log does not contain a reset code');
        check(!str_contains($log, $oldPassword) && !str_contains($log, $newPassword), 'the server log does not contain passwords');
        check(!str_contains($log, $resetToken), 'the server log does not contain a reset token');
    }
} finally {
    if ($farmerIds) {
        $placeholders = implode(', ', array_fill(0, count($farmerIds), '?'));
        // farmer_sessions and farmer_password_resets cascade with the farmer.
        $db->prepare("DELETE FROM farmers WHERE id IN ({$placeholders}) AND email LIKE 'pwreset-%@example.test'")->execute($farmerIds);
    }
    foreach ($ips as $address) $db->prepare('DELETE FROM farmer_password_reset_events WHERE ip_hash = ?')->execute([password_reset_hash('ip', $address)]);
    foreach (glob(rtrim($captureDir, '/\\') . DIRECTORY_SEPARATOR . 'password-reset-*.json') ?: [] as $file) @unlink($file);
}

$left = (int) $db->query("SELECT COUNT(*) FROM farmers WHERE email LIKE 'pwreset-%@example.test'")->fetchColumn();
if ($left !== 0) throw new RuntimeException('FAIL: fixture farmers were not cleaned up');
echo "PASS: farmer password reset, {$checks} checks: identical responses, protected verifier, cooldown, attempt and rate limits, single-use code and token, sessions revoked, statuses preserved, simulated mail only; fixtures cleaned up\n";
