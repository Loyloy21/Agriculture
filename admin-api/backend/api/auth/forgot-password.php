<?php
declare(strict_types=1);

// Step 1 of farmer Forgot Password: emails a one-time code. The response is identical whether or not
// the email belongs to an account; the account lookup and delivery happen after it is sent.
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../../lib/farmer_password_reset.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    api_response(405, ['success' => false, 'message' => 'Method not allowed.']);
}

$body = api_json_body();
$email = password_reset_normalize_email($body['email'] ?? '');
if ($email === null) {
    api_response(422, ['success' => false, 'status' => 'invalid', 'message' => 'Enter a valid email address.', 'errors' => ['email' => 'Enter a valid email address.']]);
}

$emailHash = password_reset_hash('email', $email);
$ipHash = password_reset_hash('ip', password_reset_client_ip());
try {
    $connection = db();
    $throttle = password_reset_throttled($connection, 'request', $emailHash, $ipHash);
    if ($throttle !== null) password_reset_throttle_response($throttle);
    password_reset_record_event($connection, 'request', $emailHash, $ipHash);
} catch (Throwable $exception) {
    error_log('Password reset request failed: ' . get_class($exception) . ' ' . $exception->getMessage());
    api_response(500, ['success' => false, 'status' => 'error', 'message' => 'Password reset is temporarily unavailable. Please try again.']);
}

password_reset_respond_and_continue(200, [
    'success' => true,
    'status' => 'requested',
    'message' => 'If an AgriReach farmer account uses this email, a ' . PASSWORD_RESET_CODE_DIGITS . '-digit code was sent to it. The code expires in ' . intdiv(PASSWORD_RESET_CODE_TTL_SECONDS, 60) . ' minutes.',
    'data' => [
        'codeLength' => PASSWORD_RESET_CODE_DIGITS,
        'expiresInSeconds' => PASSWORD_RESET_CODE_TTL_SECONDS,
        'resendAfterSeconds' => PASSWORD_RESET_RESEND_COOLDOWN_SECONDS,
    ],
]);

password_reset_issue_code($connection, $email);
password_reset_cleanup($connection);
exit;
