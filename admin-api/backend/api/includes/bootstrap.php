<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/app.php';

// API clients must always receive JSON. Runtime diagnostics remain in the PHP error log.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
agrireach_apply_runtime_hardening();

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

// The public host must be called over HTTPS on a production server. No redirect: the app would resend its
// Bearer token over plain HTTP, so the request is refused instead (local and LAN development is unaffected).
if (PHP_SAPI !== 'cli' && agrireach_https_upgrade_url() !== null) {
    http_response_code(403);
    echo json_encode(['success' => false, 'code' => 'HTTPS_REQUIRED', 'message' => 'Use the HTTPS address of the AgriReach server.']);
    exit;
}

function api_response(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function api_post_string(string $key): string
{
    return trim((string) ($_POST[$key] ?? ''));
}

function api_validation_error(array $errors): never
{
    api_response(422, [
        'success' => false,
        'message' => 'Please correct the highlighted registration fields.',
        'errors' => $errors,
    ]);
}

function api_json_body(): array
{
    $rawBody = file_get_contents('php://input');
    if (!is_string($rawBody) || $rawBody === '') {
        return [];
    }

    try {
        $body = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        api_response(400, ['success' => false, 'message' => 'The request body is invalid.']);
    }

    if (!is_array($body)) {
        api_response(400, ['success' => false, 'message' => 'The request body is invalid.']);
    }

    return $body;
}
