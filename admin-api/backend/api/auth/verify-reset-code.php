<?php
declare(strict_types=1);

// Step 2 of farmer Forgot Password: exchanges the emailed code for a one-time reset token. Wrong,
// expired, used and unknown-email codes all get the same answer.
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../../lib/farmer_password_reset.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    api_response(405, ['success' => false, 'message' => 'Method not allowed.']);
}

$body = api_json_body();
$email = password_reset_normalize_email($body['email'] ?? '');
$code = preg_replace('/\s+/', '', is_string($body['code'] ?? null) ? $body['code'] : '');
$errors = [];
if ($email === null) $errors['email'] = 'Enter a valid email address.';
if (!preg_match('/^\d{' . PASSWORD_RESET_CODE_DIGITS . '}$/', (string) $code)) $errors['code'] = 'Enter the ' . PASSWORD_RESET_CODE_DIGITS . '-digit code from the email.';
if ($errors) {
    api_response(422, ['success' => false, 'status' => 'invalid', 'message' => reset($errors), 'errors' => $errors]);
}

$invalid = [
    'success' => false,
    'status' => 'invalid_code',
    'message' => 'That code is incorrect or has expired. Check the newest AgriReach email, or request a new code.',
];
$emailHash = password_reset_hash('email', $email);
$ipHash = password_reset_hash('ip', password_reset_client_ip());
try {
    $connection = db();
    $throttle = password_reset_throttled($connection, 'verify_failed', $emailHash, $ipHash);
    if ($throttle !== null) password_reset_throttle_response($throttle);

    $now = db_sql_utc_now($connection);
    $lookup = $connection->prepare(
        "SELECT r.id, r.code_verifier
         FROM farmer_password_resets r
         JOIN farmers f ON f.id = r.farmer_id
         WHERE f.email = ? AND r.verified_at_utc IS NULL AND r.used_at_utc IS NULL AND r.revoked_at_utc IS NULL AND r.expires_at_utc > {$now}
         ORDER BY r.id DESC LIMIT 1"
    );
    $lookup->execute([$email]);
    $reset = $lookup->fetch();
    $matches = password_verify((string) $code, $reset ? (string) $reset['code_verifier'] : PASSWORD_RESET_DUMMY_VERIFIER);

    if (!$reset || !$matches) {
        password_reset_record_event($connection, 'verify_failed', $emailHash, $ipHash);
        if ($reset) {
            // The CASE assignments come first: MySQL evaluates SET left to right, PostgreSQL uses the old row.
            $max = PASSWORD_RESET_MAX_CODE_ATTEMPTS;
            $connection->prepare(
                "UPDATE farmer_password_resets
                 SET revoked_at_utc = CASE WHEN failed_attempts + 1 >= {$max} THEN {$now} ELSE revoked_at_utc END,
                     revoke_reason = CASE WHEN failed_attempts + 1 >= {$max} THEN 'attempts' ELSE revoke_reason END,
                     failed_attempts = failed_attempts + 1
                 WHERE id = ?"
            )->execute([(int) $reset['id']]);
        }
        api_response(400, $invalid);
    }

    $token = password_reset_generate_token();
    $ttl = PASSWORD_RESET_TOKEN_TTL_SECONDS;
    $verify = $connection->prepare(
        "UPDATE farmer_password_resets
         SET verified_at_utc = {$now}, reset_token_hash = ?, reset_token_expires_at_utc = {$now} + INTERVAL '{$ttl}' SECOND
         WHERE id = ? AND verified_at_utc IS NULL AND used_at_utc IS NULL AND revoked_at_utc IS NULL AND expires_at_utc > {$now}"
    );
    $verify->execute([hash('sha256', $token), (int) $reset['id']]);
    // Another request used or replaced this code in the meantime.
    if ($verify->rowCount() !== 1) api_response(400, $invalid);
} catch (Throwable $exception) {
    error_log('Password reset code check failed: ' . get_class($exception) . ' ' . $exception->getMessage());
    api_response(500, ['success' => false, 'status' => 'error', 'message' => 'Password reset is temporarily unavailable. Please try again.']);
}

api_response(200, [
    'success' => true,
    'status' => 'verified',
    'message' => 'Code confirmed. Choose a new password.',
    'data' => ['resetToken' => $token, 'expiresInSeconds' => PASSWORD_RESET_TOKEN_TTL_SECONDS],
]);
