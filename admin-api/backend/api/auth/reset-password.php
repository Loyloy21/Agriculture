<?php
declare(strict_types=1);

// Step 3 of farmer Forgot Password: sets the new password with the one-time reset token, then
// invalidates the token, every other outstanding code and every farmer session. Only
// farmers.password_hash is written; approval, rejection, block and account status stay as they are.
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../../lib/farmer_password_reset.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    api_response(405, ['success' => false, 'message' => 'Method not allowed.']);
}

$body = api_json_body();
$token = is_string($body['resetToken'] ?? null) ? $body['resetToken'] : '';
$password = is_string($body['password'] ?? null) ? $body['password'] : '';
$confirmPassword = is_string($body['confirmPassword'] ?? null) ? $body['confirmPassword'] : '';

$expired = [
    'success' => false,
    'status' => 'invalid_token',
    'message' => 'This password reset has expired or was already used. Request a new code.',
];
if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $token)) api_response(400, $expired);

$errors = [];
$policyError = farmer_password_policy_error($password);
if ($policyError !== null) $errors['password'] = $policyError;
if ($confirmPassword === '') $errors['confirmPassword'] = 'Please confirm your new password.';
elseif (!hash_equals($password, $confirmPassword)) $errors['confirmPassword'] = 'Passwords do not match.';
if ($errors) {
    api_response(422, ['success' => false, 'status' => 'invalid', 'message' => reset($errors), 'errors' => $errors]);
}

$ipHash = password_reset_hash('ip', password_reset_client_ip());
try {
    $connection = db();
    $throttle = password_reset_throttled($connection, 'reset', null, $ipHash);
    if ($throttle !== null) password_reset_throttle_response($throttle);
    password_reset_record_event($connection, 'reset', null, $ipHash);

    $now = db_sql_utc_now($connection);
    $connection->beginTransaction();
    try {
        $lookup = $connection->prepare(
            "SELECT r.id, r.farmer_id, f.password_hash
             FROM farmer_password_resets r
             JOIN farmers f ON f.id = r.farmer_id
             WHERE r.reset_token_hash = ? AND r.verified_at_utc IS NOT NULL AND r.used_at_utc IS NULL
               AND r.revoked_at_utc IS NULL AND r.reset_token_expires_at_utc > {$now}
             LIMIT 1 FOR UPDATE"
        );
        $lookup->execute([hash('sha256', $token)]);
        $reset = $lookup->fetch();
        if (!$reset) {
            $connection->rollBack();
            api_response(400, $expired);
        }
        // Compared against the stored hash only; the old password itself is never available.
        if (is_string($reset['password_hash']) && $reset['password_hash'] !== '' && password_verify($password, $reset['password_hash'])) {
            $connection->rollBack();
            $message = 'Choose a password different from your current password.';
            api_response(422, ['success' => false, 'status' => 'invalid', 'message' => $message, 'errors' => ['password' => $message]]);
        }

        $farmerId = (int) $reset['farmer_id'];
        $resetId = (int) $reset['id'];
        $connection->prepare('UPDATE farmers SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $farmerId]);
        $connection->prepare("UPDATE farmer_password_resets SET used_at_utc = {$now} WHERE id = ?")->execute([$resetId]);
        $connection->prepare("UPDATE farmer_password_resets SET revoked_at_utc = {$now}, revoke_reason = 'completed' WHERE farmer_id = ? AND id <> ? AND used_at_utc IS NULL AND revoked_at_utc IS NULL")
            ->execute([$farmerId, $resetId]);
        $connection->prepare("UPDATE farmer_sessions SET revoked_at = {$now} WHERE farmer_id = ? AND revoked_at IS NULL")->execute([$farmerId]);
        $connection->commit();
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) $connection->rollBack();
        throw $exception;
    }
} catch (Throwable $exception) {
    error_log('Password reset failed: ' . get_class($exception) . ' ' . $exception->getMessage());
    api_response(500, ['success' => false, 'status' => 'error', 'message' => 'Your password could not be changed. Please try again.']);
}

api_response(200, [
    'success' => true,
    'status' => 'reset',
    'message' => 'Your password was changed and you were signed out on every device. Log in with your new password.',
]);
