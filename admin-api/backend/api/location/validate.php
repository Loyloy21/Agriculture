<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once dirname(__DIR__, 3) . '/backend/lib/cabadbaran.php';
header('X-AgriReach-Boundary-Validator: local-geojson-v1');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    api_response(405, ['success' => false, 'message' => 'Method not allowed.']);
}

$body = api_json_body();
$context = in_array($body['context'] ?? '', ['residential', 'damage'], true) ? $body['context'] : 'farm';
if ($context === 'damage') {
    require_once __DIR__ . '/../includes/farmer_auth.php';
    authenticated_farmer();
}
$field = $context === 'damage' ? 'damageLocation' : ($context === 'residential' ? 'residentialPin' : 'farmPin');
$locationLabel = $context === 'damage' ? 'damage' : ($context === 'residential' ? 'home' : 'farm');
$latitude = filter_var($body['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
$longitude = filter_var($body['longitude'] ?? null, FILTER_VALIDATE_FLOAT);
if ($latitude === false || $latitude < -90 || $latitude > 90 || $longitude === false || $longitude < -180 || $longitude > 180) {
    api_response(422, ['success' => false, 'code' => 'INVALID_PIN', 'message' => 'Select a valid ' . $locationLabel . ' point.', 'errors' => [$field => 'The selected map coordinates are invalid.']]);
}

$result = cabadbaran_boundary_check((float) $latitude, (float) $longitude);
if ($result['status'] === 'unavailable') {
    api_response(503, [
        'success' => false,
        'code' => 'BOUNDARY_DATA_UNAVAILABLE',
        'message' => $result['message'],
        'errors' => [$field => 'The ' . $locationLabel . ' pin cannot be checked right now. Please try again.'],
        'data' => ['status' => 'unavailable', 'source' => $result['source'], 'validationLevel' => 'city_boundary', 'barangaySpatialMatch' => 'not_supported'],
    ]);
}
if ($result['inside'] !== true) {
    api_response(422, ['success' => false, 'code' => 'PIN_OUTSIDE_CABADBARAN', 'message' => $result['message'], 'errors' => [$field => 'Move the pin to the actual ' . $locationLabel . ' location within Cabadbaran City.'], 'data' => ['status' => 'outside', 'source' => $result['source'], 'validationLevel' => 'city_boundary', 'barangaySpatialMatch' => 'not_supported']]);
}

api_response(200, [
    'success' => true,
    'message' => $result['message'],
    'data' => [
        'status' => 'inside',
        'inside' => true,
        'source' => $result['source'],
        'validationLevel' => 'city_boundary',
        'barangaySpatialMatch' => 'not_supported',
    ],
]);
