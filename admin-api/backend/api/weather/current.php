<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/farmer_auth.php';
require_once dirname(__DIR__, 3) . '/backend/lib/current_weather.php';

authenticated_farmer();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    api_response(405, ['success' => false, 'message' => 'Method not allowed.']);
}

$forceRefresh = filter_var($_GET['refresh'] ?? false, FILTER_VALIDATE_BOOL);
try {
    api_response(200, ['success' => true, 'data' => get_cabadbaran_current_weather($forceRefresh)]);
} catch (Throwable $exception) {
    api_response(503, ['success' => false, 'message' => 'Current Cabadbaran weather is temporarily unavailable. Please try again later.']);
}
