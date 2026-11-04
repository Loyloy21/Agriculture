<?php
declare(strict_types=1);

// Single-administrator replacement. AgriReach has exactly one active admin (enforced by the database index
// uq_admins_single_active). Replacing that admin is a three-step hand-over, never a second admin:
//   1. The active admin invites the replacement (name + email). Only a SHA-256 hash of the emailed token is stored.
//   2. The replacement opens the link and sets their own password. This marks the process Ready; it grants nothing.
//   3. The active admin re-enters their password and transfers access. One transaction disables the old admin and
//      creates the new one; the old admin's sessions stop working on their next request (see includes/bootstrap.php).
// Emails go through the existing system sender (registration_email_settings, the same Gmail sender as approval and
// rejection emails). The sender belongs to the system, not to an admin, and is not touched by a transfer.

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/mailer.php';
// Reused: the project password rule (farmer_password_policy_error) and the Cloudflare-aware client address.
require_once __DIR__ . '/farmer_password_reset.php';

const ADMIN_INVITATION_TTL_SECONDS = 86400;
/** After setup, the current admin has 7 days to transfer before the replacement must be invited again. */
const ADMIN_READY_TTL_SECONDS = 604800;
const ADMIN_INVITATION_EMAIL_LIMIT = 5;
const ADMIN_INVITATION_EMAIL_WINDOW = 3600;
const ADMIN_TOKEN_CHECK_LIMIT = 30;
const ADMIN_TOKEN_FAILURE_LIMIT = 8;
const ADMIN_TOKEN_WINDOW = 900;
const ADMIN_REAUTH_FAILURE_LIMIT = 5;
const ADMIN_REAUTH_WINDOW = 900;

final class AdminReplacementException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 422, public readonly string $field = 'form')
    {
        parent::__construct($message);
    }
}

/** The client address (hashed) for rate limits and the audit trail; never stored in clear. */
function admin_account_ip_hash(): string
{
    return hash('sha256', 'agrireach-admin-account:' . password_reset_client_ip());
}

/** Audit entry. $detail is short, human-readable and never contains tokens, passwords or email credentials. */
function admin_account_event(PDO $connection, string $type, ?int $actorId, ?int $subjectId = null, ?int $invitationId = null, ?string $detail = null, ?string $ipHash = null): void
{
    $connection->prepare('INSERT INTO admin_account_events (event_type, actor_admin_id, subject_admin_id, invitation_id, detail, ip_hash, created_at_utc) VALUES (?, ?, ?, ?, ?, ?, ' . db_sql_utc_now($connection) . ')')
        ->execute([$type, $actorId, $subjectId, $invitationId, $detail === null ? null : mb_substr($detail, 0, 255), $ipHash]);
}

/** Events of $types in the last $seconds for one actor admin or one hashed client address. */
function admin_account_recent_events(PDO $connection, array $types, string $subject, int|string $value, int $seconds): int
{
    if (!in_array($subject, ['actor_admin_id', 'ip_hash'], true)) throw new InvalidArgumentException('Unsupported rate-limit subject.');
    $now = db_sql_utc_now($connection);
    $statement = $connection->prepare('SELECT COUNT(*) FROM admin_account_events WHERE event_type IN (' . implode(', ', array_fill(0, count($types), '?')) . ") AND {$subject} = ? AND created_at_utc > {$now} - INTERVAL '{$seconds}' SECOND");
    $statement->execute([...$types, $value]);
    return (int) $statement->fetchColumn();
}

/** The admin row when $adminId is the active administrator; null otherwise (inactive, blocked or unknown). */
function admin_active_account(PDO $connection, int $adminId): ?array
{
    $statement = $connection->prepare("SELECT id, full_name, username, email, password_hash FROM admins WHERE id = ? AND role = 'admin' AND status = 'active' AND account_status = 'Active' LIMIT 1");
    $statement->execute([$adminId]);
    return $statement->fetch() ?: null;
}

/** The trusted public base URL (APP_URL, see backend/config/app.php), or null when unset/invalid. */
function admin_replacement_app_url(): ?string
{
    return agrireach_public_base_url();
}

/** Who can open a link built from $url (see agrireach_url_scope()). */
function admin_replacement_app_url_scope(string $url): string
{
    return agrireach_url_scope($url);
}

/** True when $exception means the admin replacement tables (migration 20261024_0008_admin_replacement) do not exist. */
function admin_replacement_schema_missing(Throwable $exception): bool
{
    return $exception instanceof PDOException && in_array((string) $exception->getCode(), ['42P01', '42S02'], true);
}

/**
 * What an invitation needs, checked before anything is created or sent: the database update, a usable APP_URL and a
 * sender that can actually deliver. A blocking item stops invitations; a non-blocking one is shown as a note.
 * @return array{database:array{ok:bool,blocking:bool,message:string},app_url:array{ok:bool,blocking:bool,message:string},sender:array{ok:bool,blocking:bool,message:string}}
 */
function admin_replacement_prerequisites(PDO $connection): array
{
    $item = static fn(bool $ok, bool $blocking, string $message): array => ['ok' => $ok, 'blocking' => $blocking, 'message' => $message];
    try {
        $connection->query('SELECT 1 FROM admin_replacement_invitations WHERE 1 = 0')->fetchAll();
        $database = $item(true, false, 'The admin replacement database update is applied.');
    } catch (PDOException $exception) {
        if (!admin_replacement_schema_missing($exception)) throw $exception;
        $database = $item(false, true, 'The admin replacement database update (migration 20261024_0008_admin_replacement) has not been applied to this database yet, so invitations cannot be stored.');
    }

    // Production servers need a public HTTPS address (agrireach_public_url_problem); development may use local ones.
    $url = admin_replacement_app_url();
    $problem = agrireach_public_url_problem();
    $temporary = temporary_test_settings();
    $appUrl = $problem !== null
        ? $item(false, true, $problem . ' A safe invitation link cannot be built until a public HTTPS address is configured.')
        : (agrireach_public_url_is_temporary() ? $item(false, false, 'TEMPORARY test address: invitation links use ' . $url . ' until '
            . (new DateTimeImmutable('@' . $temporary['EXPIRES_AT']))->setTimezone(new DateTimeZone('Asia/Manila'))->format('M j, g:i A')
            . ' or until the test tunnel is stopped. This is not a permanent deployment.') : match (admin_replacement_app_url_scope((string) $url)) {
            'this_computer' => $item(true, false, 'Invitation links use ' . $url . ' and open only on this computer (development server). The invited person must set their password here.'),
            'local_network' => $item(true, false, 'Invitation links use ' . $url . ' and open only on devices on the same network (development server).'),
            default => $item(true, false, 'Invitation links use ' . $url . '.'),
        });

    $configuration = registration_email_configuration($connection);
    $state = registration_email_sender_state($connection);
    $production = $configuration['app_environment'] === 'production';
    // A saved sender that cannot be decrypted makes the configuration fall back to the server defaults, so check it first.
    if ($state['source'] === 'Admin settings' && !$state['credential_available']) {
        $sender = $item(false, true, 'The saved system sender ' . $state['sender_email'] . ' cannot be used: its App Password cannot be decrypted on this server (encryption key missing or changed).');
    } elseif ($configuration['transport'] === 'log') {
        // Development/test servers may simulate (a note); a production server must deliver (blocking).
        $sender = $item(false, $production, 'This server only simulates email (log transport), so no invitation email would be delivered.');
    } elseif (!filter_var($configuration['from_address'], FILTER_VALIDATE_EMAIL) || $configuration['host'] === '' || ($configuration['authentication'] && ($configuration['username'] === '' || $configuration['password'] === ''))) {
        $sender = $item(false, true, 'The system email sender is not configured. Set it up in Email Settings.');
    } elseif (!$production && !filter_var($configuration['test_recipient'], FILTER_VALIDATE_EMAIL)) {
        $sender = $item(false, true, 'This development server requires a test recipient (MAIL_TEST_RECIPIENT) before it may send email.');
    } else {
        $sender = $item(true, false, 'Sent from ' . $configuration['from_address'] . ', the same sender as approval and rejection emails.' . ($production ? '' : ' Development server: delivered to the test recipient, not the invited person.'));
    }
    return ['database' => $database, 'app_url' => $appUrl, 'sender' => $sender];
}

/** Throws the first blocking prerequisite, so nothing is created or sent when an invitation could not work. */
function admin_replacement_require_prerequisites(PDO $connection): void
{
    foreach (admin_replacement_prerequisites($connection) as $item) {
        if ($item['blocking']) throw new AdminReplacementException($item['message'] . ' No invitation was created or sent.', 503);
    }
}

/** The token travels in the URL fragment, which browsers never send to the server (so it never reaches access logs). */
function admin_invitation_link(string $token): string
{
    // Throws rather than building a link on an unusable or local address.
    return agrireach_public_url('admin/admin-invitation.php') . '#token=' . $token;
}

function admin_utc_to_manila(?string $utc, string $format = 'M j, Y g:i A'): string
{
    if ($utc === null || $utc === '') return '';
    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Manila'))->format($format);
}

function admin_replacement_select_sql(): string
{
    return 'SELECT id, status, full_name, email, invited_by_admin_id, token_expires_at, email_status, email_failure_code, send_count, last_sent_at,
                   setup_completed_at, ready_expires_at, replacement_admin_id, closed_at, created_at
            FROM admin_replacement_invitations';
}

/** Marks overdue processes Expired (an unused invitation after 24 hours, a ready setup after 7 days). */
function admin_replacement_expire_overdue(PDO $connection): void
{
    $now = db_sql_utc_now($connection);
    $overdue = $connection->query("SELECT id FROM admin_replacement_invitations WHERE (status = 'Pending' AND token_expires_at <= {$now}) OR (status = 'Ready' AND ready_expires_at <= {$now})")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($overdue as $id) {
        $statement = $connection->prepare("UPDATE admin_replacement_invitations SET status = 'Expired', password_hash = NULL, closed_at = {$now} WHERE id = ? AND status IN ('Pending', 'Ready')");
        $statement->execute([(int) $id]);
        if ($statement->rowCount() === 1) admin_account_event($connection, 'invitation_expired', null, null, (int) $id);
    }
}

/** The open replacement process (Pending or Ready), if any. */
function admin_replacement_open(PDO $connection): ?array
{
    admin_replacement_expire_overdue($connection);
    return $connection->query(admin_replacement_select_sql() . " WHERE status IN ('Pending', 'Ready') ORDER BY id DESC LIMIT 1")->fetch() ?: null;
}

/** @return array{0:array<string,string>,1:array{full_name:string,email:string}} */
function admin_replacement_validate(PDO $connection, array $current, string $fullName, string $email): array
{
    $errors = [];
    $fullName = trim((string) preg_replace('/\s+/u', ' ', $fullName));
    $email = strtolower(trim($email));
    if (mb_strlen($fullName) < 2 || mb_strlen($fullName) > 120 || preg_match('/[\x00-\x1F\x7F<>]/u', $fullName)) $errors['full_name'] = 'Enter the replacement\'s full name (2 to 120 characters).';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = 'Enter a valid email address.';
    elseif (strlen($email) > 60) $errors['email'] = 'Use an email address of at most 60 characters (it becomes the admin login).';
    elseif (strcasecmp($email, (string) $current['username']) === 0 || strcasecmp($email, (string) ($current['email'] ?? '')) === 0) {
        $errors['email'] = 'This is your own administrator account. To change your own name, login or password, use Admin Profile and Change Password.';
    } else {
        $statement = $connection->prepare('SELECT id FROM admins WHERE username = ? OR email = ? LIMIT 1');
        $statement->execute([$email, $email]);
        if ($statement->fetch()) $errors['email'] = 'This email already belongs to a former administrator account. Former accounts are not reused; invite a different email address.';
        else {
            $statement = $connection->prepare('SELECT id FROM farmers WHERE email = ? LIMIT 1');
            $statement->execute([$email]);
            if ($statement->fetch()) $errors['email'] = 'This email is registered to a farmer account. Use a separate email address for the administrator account.';
        }
    }
    return [$errors, ['full_name' => $fullName, 'email' => $email]];
}

/** Sends through the existing system sender (same configuration and development routing as approval emails). */
function admin_account_send_email(string $recipient, string $recipientName, array $content): string
{
    $configuration = registration_email_configuration();
    $status = send_prepared_registration_email($configuration, $recipient, $recipientName, $content, true);
    // Controlled tests only (non-sending 'log' transport, never production): keep the message so a test can read the link.
    $directory = trim((string) getenv('AGRIREACH_TEST_MAIL_CAPTURE_DIR'));
    if ($directory !== '' && $status === 'Simulated' && $configuration['transport'] === 'log' && $configuration['app_environment'] !== 'production' && is_dir($directory)) {
        file_put_contents(rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . 'admin-account-' . hash('sha256', strtolower($recipient)) . '.json',
            (string) json_encode(['to' => strtolower($recipient), 'subject' => $content['subject'], 'text' => $content['text']]), LOCK_EX);
    }
    return $status;
}

/** Plain, escaped HTML + text email in the same style as the approval/rejection emails. */
function admin_account_email(string $subject, string $heading, array $paragraphs, ?array $button = null): array
{
    $html = '';
    foreach ($paragraphs as $paragraph) $html .= '<p>' . htmlspecialchars($paragraph, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
    if ($button) $html .= '<p style="margin:24px 0"><a href="' . htmlspecialchars($button[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" style="background:#174d36;color:#fff;padding:12px 18px;border-radius:8px;text-decoration:none;font-weight:bold">'
        . htmlspecialchars($button[0], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a></p><p style="font-size:13px;color:#718078">If the button does not work, copy this link into your browser: ' . htmlspecialchars($button[1], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
    $notice = 'AgriReach never sends passwords by email.';
    return [
        'subject' => $subject,
        'html' => '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#27301f;line-height:1.6"><div style="max-width:600px;margin:auto;padding:24px;border:1px solid #dce5d6;border-radius:14px">'
            . '<h1 style="color:#174d36;font-size:22px">' . htmlspecialchars($heading, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h1>' . $html
            . '<p style="margin-top:24px;color:#718078;font-size:13px">' . $notice . '</p></div></body></html>',
        'text' => $heading . "\n\n" . implode("\n\n", $paragraphs) . ($button ? "\n\n" . $button[0] . ': ' . $button[1] : '') . "\n\n" . $notice,
    ];
}

/** Emails a fresh invitation link; records the delivery outcome. Never throws for delivery failures. */
function admin_replacement_deliver(PDO $connection, int $invitationId, string $token, string $fullName, string $email, array $inviter, bool $resend): array
{
    $expires = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->modify('+' . ADMIN_INVITATION_TTL_SECONDS . ' seconds')->format('M j, Y g:i A');
    $content = admin_account_email('AgriReach administrator invitation', 'You are invited to become the AgriReach administrator', [
        "Hello {$fullName},",
        "{$inviter['full_name']} invited you to take over the AgriReach administrator account for the Department of Agriculture admin website.",
        'Open the link below to set your own password. Setting a password does not give you access yet: the current administrator must then confirm the transfer. Until then, nothing changes for them.',
        "This link can be used once and expires on {$expires} (Philippine time). If you did not expect this invitation, ignore this email.",
    ], ['Set up your administrator password', admin_invitation_link($token)]);
    try {
        $status = admin_account_send_email($email, $fullName, $content);
        $connection->prepare('UPDATE admin_replacement_invitations SET email_status = ?, email_failure_code = NULL, send_count = send_count + 1, last_sent_at = ' . db_sql_utc_now($connection) . ' WHERE id = ?')
            ->execute([$status, $invitationId]);
        admin_account_event($connection, $resend ? 'invitation_resent' : 'invitation_created', (int) $inviter['id'], null, $invitationId, ($resend ? 'Invitation link resent to ' : 'Invitation sent to ') . $email . ' (' . strtolower($status) . ')', admin_account_ip_hash());
        return ['sent' => true, 'status' => $status];
    } catch (VerificationEmailException $exception) {
        $connection->prepare("UPDATE admin_replacement_invitations SET email_status = 'Failed', email_failure_code = ? WHERE id = ?")->execute([$exception->failureCode, $invitationId]);
        admin_account_event($connection, 'invitation_send_failed', (int) $inviter['id'], null, $invitationId, 'Invitation email to ' . $email . ' failed: ' . $exception->failureCode, admin_account_ip_hash());
        return ['sent' => false, 'failureCode' => $exception->failureCode];
    }
}

function admin_replacement_guard_email_rate(PDO $connection, int $adminId): void
{
    if (admin_account_recent_events($connection, ['invitation_created', 'invitation_resent', 'invitation_send_failed'], 'actor_admin_id', $adminId, ADMIN_INVITATION_EMAIL_WINDOW) >= ADMIN_INVITATION_EMAIL_LIMIT) {
        throw new AdminReplacementException('Too many invitation emails in the last hour. Please wait before sending again.', 429);
    }
}

function admin_replacement_require_active(PDO $connection, int $adminId): array
{
    return admin_active_account($connection, $adminId) ?? throw new AdminReplacementException('Only the active administrator can manage the admin account.', 403);
}

/** Step 1: invite. Returns the invitation id and the delivery outcome (an unsent invitation stays open for a retry). */
function admin_replacement_invite(PDO $connection, int $adminId, string $fullName, string $email): array
{
    $current = admin_replacement_require_active($connection, $adminId);
    admin_replacement_require_prerequisites($connection);
    [$errors, $clean] = admin_replacement_validate($connection, $current, $fullName, $email);
    if ($errors) throw new AdminReplacementException((string) reset($errors), 422, (string) array_key_first($errors));
    admin_replacement_guard_email_rate($connection, $adminId);
    if (admin_replacement_open($connection)) throw new AdminReplacementException('A replacement process is already open. Resend or cancel it first.', 409);

    $token = password_reset_generate_token();
    try {
        $connection->prepare("INSERT INTO admin_replacement_invitations (status, full_name, email, invited_by_admin_id, token_hash, token_expires_at) VALUES ('Pending', ?, ?, ?, ?, " . db_sql_utc_now($connection) . " + INTERVAL '" . ADMIN_INVITATION_TTL_SECONDS . "' SECOND)")
            ->execute([$clean['full_name'], $clean['email'], $adminId, hash('sha256', $token)]);
    } catch (PDOException $exception) {
        if (db_is_duplicate_key($exception)) throw new AdminReplacementException('A replacement process is already open. Resend or cancel it first.', 409);
        throw $exception;
    }
    $id = db_insert_id($connection, 'admin_replacement_invitations');
    return ['invitationId' => $id] + admin_replacement_deliver($connection, $id, $token, $clean['full_name'], $clean['email'], $current, false);
}

/** Resend: a new link replaces the previous one (the old link stops working). */
function admin_replacement_resend(PDO $connection, int $adminId): array
{
    $current = admin_replacement_require_active($connection, $adminId);
    admin_replacement_require_prerequisites($connection);
    $open = admin_replacement_open($connection);
    if (!$open) throw new AdminReplacementException('There is no open invitation to resend.', 404);
    if ($open['status'] !== 'Pending') throw new AdminReplacementException('The replacement already set a password. Transfer access or cancel the process.', 409);
    admin_replacement_guard_email_rate($connection, $adminId);
    $token = password_reset_generate_token();
    $statement = $connection->prepare("UPDATE admin_replacement_invitations SET token_hash = ?, token_expires_at = " . db_sql_utc_now($connection) . " + INTERVAL '" . ADMIN_INVITATION_TTL_SECONDS . "' SECOND, email_status = 'Pending', email_failure_code = NULL WHERE id = ? AND status = 'Pending'");
    $statement->execute([hash('sha256', $token), (int) $open['id']]);
    if ($statement->rowCount() !== 1) throw new AdminReplacementException('The invitation changed while resending. Reload the page.', 409);
    return ['invitationId' => (int) $open['id']] + admin_replacement_deliver($connection, (int) $open['id'], $token, (string) $open['full_name'], (string) $open['email'], $current, true);
}

/** Cancel: the link and any completed setup stop working. The current admin is unaffected. */
function admin_replacement_cancel(PDO $connection, int $adminId): void
{
    admin_replacement_require_active($connection, $adminId);
    $open = admin_replacement_open($connection);
    if (!$open) throw new AdminReplacementException('There is no open replacement process to cancel.', 404);
    $statement = $connection->prepare("UPDATE admin_replacement_invitations SET status = 'Cancelled', password_hash = NULL, closed_at = " . db_sql_utc_now($connection) . " WHERE id = ? AND status IN ('Pending', 'Ready')");
    $statement->execute([(int) $open['id']]);
    if ($statement->rowCount() !== 1) throw new AdminReplacementException('The process changed while cancelling. Reload the page.', 409);
    admin_account_event($connection, 'invitation_cancelled', $adminId, null, (int) $open['id'], 'Replacement process for ' . $open['email'] . ' cancelled', admin_account_ip_hash());
}

/**
 * Step 2 (public invitation page): what a token currently allows. Rate-limited per client address.
 * @return array{state:'valid'|'used'|'cancelled'|'expired'|'invalid', invitation:?array}
 */
function admin_replacement_token_state(PDO $connection, string $token, bool $countAttempt = true): array
{
    $ip = admin_account_ip_hash();
    if ($countAttempt && (admin_account_recent_events($connection, ['invitation_rejected'], 'ip_hash', $ip, ADMIN_TOKEN_WINDOW) >= ADMIN_TOKEN_FAILURE_LIMIT
        || admin_account_recent_events($connection, ['invitation_viewed', 'invitation_rejected'], 'ip_hash', $ip, ADMIN_TOKEN_WINDOW) >= ADMIN_TOKEN_CHECK_LIMIT)) {
        throw new AdminReplacementException('Too many attempts from this device. Please try again in about 15 minutes.', 429);
    }
    admin_replacement_expire_overdue($connection);
    $invitation = null;
    if (preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) {
        $statement = $connection->prepare(admin_replacement_select_sql() . ' WHERE token_hash = ? LIMIT 1');
        $statement->execute([hash('sha256', $token)]);
        $invitation = $statement->fetch() ?: null;
    }
    $state = match ($invitation['status'] ?? null) {
        'Pending' => 'valid',
        'Ready', 'Completed' => 'used',
        'Cancelled' => 'cancelled',
        'Expired' => 'expired',
        default => 'invalid',
    };
    if ($countAttempt) admin_account_event($connection, $state === 'valid' ? 'invitation_viewed' : 'invitation_rejected', null, null, $invitation ? (int) $invitation['id'] : null, $state === 'valid' ? null : 'Invitation link ' . $state, $ip);
    return ['state' => $state, 'invitation' => $invitation];
}

function admin_replacement_state_message(string $state): string
{
    return match ($state) {
        'used' => 'This invitation was already used to set a password. Ask the current administrator to complete the transfer.',
        'cancelled' => 'This invitation was cancelled by the current administrator.',
        'expired' => 'This invitation has expired. Ask the current administrator to send a new one.',
        default => 'This invitation link is not valid. It may have been replaced by a newer email; use the latest invitation you received.',
    };
}

/** Step 2: the replacement sets their own password. Marks the process Ready; grants no access. Single use. */
function admin_replacement_complete_setup(PDO $connection, string $token, string $password, string $confirmation): array
{
    $check = admin_replacement_token_state($connection, $token);
    if ($check['state'] !== 'valid') throw new AdminReplacementException(admin_replacement_state_message($check['state']), 410);
    $policy = farmer_password_policy_error($password);
    if ($policy !== null) throw new AdminReplacementException($policy, 422, 'password');
    if (strlen($password) > 72) throw new AdminReplacementException('Use at most 72 characters.', 422, 'password');
    if (!hash_equals($password, $confirmation)) throw new AdminReplacementException('The passwords do not match.', 422, 'password_confirmation');

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $now = db_sql_utc_now($connection);
    $connection->beginTransaction();
    try {
        $statement = $connection->prepare("SELECT id, email FROM admin_replacement_invitations WHERE token_hash = ? AND status = 'Pending' AND token_expires_at > {$now} FOR UPDATE");
        $statement->execute([hash('sha256', $token)]);
        $row = $statement->fetch();
        if (!$row) throw new AdminReplacementException(admin_replacement_state_message('used'), 410);
        $connection->prepare("UPDATE admin_replacement_invitations SET status = 'Ready', password_hash = ?, setup_completed_at = {$now}, ready_expires_at = {$now} + INTERVAL '" . ADMIN_READY_TTL_SECONDS . "' SECOND WHERE id = ?")
            ->execute([$hash, (int) $row['id']]);
        admin_account_event($connection, 'setup_completed', null, null, (int) $row['id'], 'Password set by the invited replacement ' . $row['email'], admin_account_ip_hash());
        $connection->commit();
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) $connection->rollBack();
        throw $exception;
    }
    return ['fullName' => (string) $check['invitation']['full_name'], 'email' => (string) $check['invitation']['email']];
}

/**
 * Step 3: transfer. Re-authenticates the current admin, then in ONE transaction (rows locked): disables the old admin,
 * creates the replacement as the only active admin, and closes the process. The unique index on active admins makes a
 * second active admin impossible even under concurrent requests. Notifications are sent after the commit; a delivery
 * failure is recorded but never undoes or repeats the transfer.
 */
function admin_replacement_transfer(PDO $connection, int $adminId, string $currentPassword, bool $understood): array
{
    $ip = admin_account_ip_hash();
    if (admin_account_recent_events($connection, ['transfer_reauth_failed'], 'actor_admin_id', $adminId, ADMIN_REAUTH_WINDOW) >= ADMIN_REAUTH_FAILURE_LIMIT) {
        throw new AdminReplacementException('Too many incorrect password attempts. Please wait 15 minutes before trying again.', 429);
    }
    $current = admin_replacement_require_active($connection, $adminId);
    if (!$understood) throw new AdminReplacementException('Confirm that you understand you will lose administrator access.', 422, 'confirm_transfer');
    if (!password_verify($currentPassword, (string) $current['password_hash'])) {
        admin_account_event($connection, 'transfer_reauth_failed', $adminId, null, null, 'Incorrect current password at transfer', $ip);
        throw new AdminReplacementException('Your current password is incorrect. Nothing was changed.', 422, 'current_password');
    }

    $now = db_sql_utc_now($connection);
    $connection->beginTransaction();
    try {
        $lock = $connection->prepare("SELECT id FROM admins WHERE id = ? AND role = 'admin' AND status = 'active' AND account_status = 'Active' FOR UPDATE");
        $lock->execute([$adminId]);
        if (!$lock->fetch()) throw new AdminReplacementException('You are no longer the active administrator.', 403);
        $statement = $connection->prepare("SELECT id, full_name, email, password_hash FROM admin_replacement_invitations WHERE status = 'Ready' AND ready_expires_at > {$now} FOR UPDATE");
        $statement->execute();
        $invitation = $statement->fetch();
        if (!$invitation || (string) $invitation['password_hash'] === '') throw new AdminReplacementException('The replacement is not ready for transfer (the setup was cancelled, expired or already used).', 409);
        $conflict = $connection->prepare('SELECT id FROM admins WHERE username = ? OR email = ? LIMIT 1');
        $conflict->execute([$invitation['email'], $invitation['email']]);
        if ($conflict->fetch()) throw new AdminReplacementException('The replacement email is now used by another administrator account. Cancel this process and invite again.', 409);

        $disable = $connection->prepare("UPDATE admins SET status = 'inactive', account_status = 'Inactive' WHERE id = ? AND status = 'active'");
        $disable->execute([$adminId]);
        if ($disable->rowCount() !== 1) throw new AdminReplacementException('You are no longer the active administrator.', 403);
        $connection->prepare("INSERT INTO admins (full_name, username, password_hash, role, status, email, account_status) VALUES (?, ?, ?, 'admin', 'active', ?, 'Active')")
            ->execute([$invitation['full_name'], $invitation['email'], $invitation['password_hash'], $invitation['email']]);
        $newAdminId = db_insert_id($connection, 'admins');
        $close = $connection->prepare("UPDATE admin_replacement_invitations SET status = 'Completed', replacement_admin_id = ?, password_hash = NULL, closed_at = {$now} WHERE id = ? AND status = 'Ready'");
        $close->execute([$newAdminId, (int) $invitation['id']]);
        if ($close->rowCount() !== 1) throw new AdminReplacementException('The replacement process changed during the transfer.', 409);
        admin_account_event($connection, 'transfer_completed', $adminId, $newAdminId, (int) $invitation['id'], 'Admin access transferred from ' . $current['username'] . ' to ' . $invitation['email'], $ip);
        $connection->commit();
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) $connection->rollBack();
        $reason = $exception instanceof AdminReplacementException ? $exception->getMessage() : (db_is_duplicate_key($exception) ? 'another administrator became active' : 'database error ' . get_class($exception));
        try { admin_account_event($connection, 'transfer_failed', $adminId, null, null, 'Transfer not completed: ' . mb_substr($reason, 0, 180), $ip); } catch (Throwable) {}
        if ($exception instanceof AdminReplacementException) throw $exception;
        error_log('Admin transfer failed and was rolled back: ' . get_class($exception));
        throw new AdminReplacementException(db_is_duplicate_key($exception) ? 'Another administrator change happened at the same time. Your access is unchanged; reload the page.' : 'The transfer could not be completed. Your administrator access is unchanged.', db_is_duplicate_key($exception) ? 409 : 500);
    }

    // After the commit: notify both people through the existing sender. Failures are recorded, never retried here.
    $when = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('M j, Y g:i A');
    // Only a usable public address is linked; otherwise the notice simply has no button (never a localhost link).
    // Only link to the login when that route is really served at the public address: the temporary test gateway
    // exposes just the API and the invitation page, so its login link would be a 404.
    $loginUrl = agrireach_public_link_available('admin/login.php') ? agrireach_public_url('admin/login.php') : null;
    $where = $loginUrl !== null ? ''
        : ' The admin login is not published on the internet' . (agrireach_public_url_is_temporary() ? ' (the current address is a temporary test link for the invitation page only)' : '')
          . ', so sign in on the computer that runs the AgriReach admin website.';
    $notifications = [];
    $messages = [
        'new' => [$invitation['email'], $invitation['full_name'], admin_account_email('You are now the AgriReach administrator', 'Administrator access transferred to you', [
            "Hello {$invitation['full_name']},",
            "{$current['full_name']} transferred the AgriReach administrator account to you on {$when} (Philippine time).",
            "Sign in with the username {$invitation['email']} and the password you set during the invitation.{$where} The email sender used for farmer notifications was not changed.",
        ], $loginUrl ? ['Open the admin login', $loginUrl] : null), $newAdminId],
        'previous' => [(string) ($current['email'] ?? ''), $current['full_name'], admin_account_email('Your AgriReach administrator access was transferred', 'Administrator access transferred', [
            "Hello {$current['full_name']},",
            "You transferred the AgriReach administrator account to {$invitation['full_name']} ({$invitation['email']}) on {$when} (Philippine time).",
            'Your former administrator account can no longer sign in. Its history (reports, decisions and settings changes) is kept. If you did not make this change, contact the new administrator or the Department of Agriculture immediately.',
        ]), $adminId],
    ];
    foreach ($messages as $who => [$recipient, $name, $content, $subjectId]) {
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            $notifications[$who] = 'no_email';
            admin_account_event($connection, 'notification_failed', $adminId, $subjectId, (int) $invitation['id'], 'Transfer notice to the ' . $who . ' administrator not sent: no email address on the account');
            continue;
        }
        try {
            $notifications[$who] = admin_account_send_email($recipient, (string) $name, $content);
            admin_account_event($connection, 'notification_sent', $adminId, $subjectId, (int) $invitation['id'], 'Transfer notice to the ' . $who . ' administrator ' . strtolower($notifications[$who]));
        } catch (VerificationEmailException $exception) {
            $notifications[$who] = 'failed';
            admin_account_event($connection, 'notification_failed', $adminId, $subjectId, (int) $invitation['id'], 'Transfer notice to the ' . $who . ' administrator failed: ' . $exception->failureCode);
        }
    }
    return ['newAdminId' => $newAdminId, 'newAdminName' => (string) $invitation['full_name'], 'newAdminEmail' => (string) $invitation['email'], 'notifications' => $notifications];
}

/** A safe explanation of an email delivery failure code (no server details). */
function admin_replacement_failure_message(string $failureCode): string
{
    return match ($failureCode) {
        'smtp_configuration_missing', 'smtp_credentials_missing' => 'the system email sender is not fully configured (see Email Settings).',
        'test_recipient_required' => 'this development server requires a test recipient before it may send email.',
        'invalid_recipient' => 'the email address was not accepted.',
        'mailer_not_installed' => 'the server email library is unavailable.',
        default => 'the email server did not accept the message. Check the sender in Email Settings and try again.',
    };
}

/** Recent admin account events for the Settings page (no client addresses). */
function admin_account_recent_activity(PDO $connection, int $limit = 10): array
{
    $statement = $connection->query('SELECT e.event_type, e.detail, e.created_at_utc, a.full_name AS actor_name
        FROM admin_account_events e LEFT JOIN admins a ON a.id = e.actor_admin_id
        WHERE e.event_type NOT IN (\'invitation_viewed\') ORDER BY e.id DESC LIMIT ' . max(1, min($limit, 50)));
    return $statement->fetchAll();
}
