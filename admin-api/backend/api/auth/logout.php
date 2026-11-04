<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/farmer_auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    api_response(405, ['success' => false, 'message' => 'Method not allowed.']);
}

$token = farmer_bearer_token();
if ($token !== null) {
    try {
        $tokenHash = hash('sha256', $token);
        $connection = db();
        $statement = $connection->prepare('UPDATE farmer_sessions SET revoked_at = COALESCE(revoked_at, ' . db_sql_utc_now($connection) . ') WHERE token_hash = ?');
        $statement->execute([$tokenHash]);
    } catch (Throwable $exception) {
        error_log('Farmer logout failed: ' . $exception->getMessage());
        api_response(500, ['success' => false, 'message' => 'Logout could not be completed. Please try again.']);
    }
}

api_response(200, ['success' => true, 'message' => 'Logged out successfully.']);
