<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/farmer_auth.php';
require_once dirname(__DIR__, 3) . '/backend/lib/weather_point.php';
require_once dirname(__DIR__, 3) . '/backend/lib/weather_decision.php';

/**
 * GET /weather/advice.php[?farmId=N][&refresh=1]
 *
 * "Weather-based advice": rule-based guidance computed from REAL provider readings for ONE of the
 * authenticated farmer's own active farms. Separate from admin-published advisories
 * (/weather/advisories.php) and from the citywide Today's Weather card (/weather/current.php).
 * Nothing here creates notifications or changes advisory recipients.
 */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    api_response(405, ['success' => false, 'message' => 'Method not allowed.']);
}

$farmer = authenticated_farmer();
$farmerId = (int) $farmer['id'];
$requestedFarmId = filter_input(INPUT_GET, 'farmId', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (isset($_GET['farmId']) && !$requestedFarmId) api_response(422, ['success' => false, 'message' => 'Select a valid farm.']);
$forceRefresh = filter_var($_GET['refresh'] ?? false, FILTER_VALIDATE_BOOL);

$meta = ['ruleSetVersion' => WEATHER_RULE_SET_VERSION, 'generatedAt' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format(DATE_ATOM)];
// One diagnostic line per request: farm id, coordinate source, outcome and reason only.
// Never the token, the exact coordinates or the address.
$logOutcome = static function (int|string $farmId, string $basis, string $status, ?string $reasonCode, ?int $ageMinutes): void {
    error_log(sprintf('weather-advice farm=%s basis=%s status=%s reason=%s age_min=%s', $farmId, $basis, $status, $reasonCode ?? '-', $ageMinutes === null ? '-' : (string) $ageMinutes));
};
$unavailable = static function (string $code, string $reason, ?array $farm = null, ?array $location = null) use ($meta, $logOutcome): never {
    $logOutcome($farm['id'] ?? '-', $location['basis'] ?? 'none', 'unavailable', $code, null);
    api_response(200, ['success' => true, 'data' => $meta + [
        'status' => 'unavailable', 'statusMessage' => $reason, 'reasonCode' => $code, 'reason' => $reason,
        'farm' => $farm, 'location' => $location, 'weather' => null, 'advice' => null, 'summary' => null,
    ]]);
};

try {
    $connection = db();
    $statement = $connection->prepare("SELECT id, farm_name, agricultural_category, barangay_name, farm_address, latitude, longitude, is_primary
                                       FROM farm_profiles WHERE farmer_id = ? AND status = 'Active' ORDER BY is_primary DESC, id ASC");
    $statement->execute([$farmerId]);
    $farms = $statement->fetchAll();
} catch (Throwable $exception) {
    error_log('Weather advice farm lookup failed: ' . $exception->getMessage());
    api_response(500, ['success' => false, 'message' => 'Weather-based advice is temporarily unavailable.']);
}

if (!$farms) $unavailable('no_active_farm', 'No active farm is registered, so weather-based advice cannot be shown.');
$selected = $farms[0];
if ($requestedFarmId) {
    $selected = null;
    foreach ($farms as $candidate) if ((int) $candidate['id'] === $requestedFarmId) { $selected = $candidate; break; }
    if ($selected === null) api_response(404, ['success' => false, 'message' => 'This farm was not found.']);
}

$farmSummary = [
    'id' => (int) $selected['id'], 'name' => (string) $selected['farm_name'], 'category' => (string) $selected['agricultural_category'],
    'barangayName' => $selected['barangay_name'] ?: null, 'address' => (string) $selected['farm_address'],
];
$latitude = $selected['latitude'] === null ? null : (float) $selected['latitude'];
$longitude = $selected['longitude'] === null ? null : (float) $selected['longitude'];
$location = weather_point_resolve_location($latitude, $longitude, 'farm_pin');
if (!$location['ok']) $unavailable((string) $location['reasonCode'], (string) $location['reason'], $farmSummary);

$locationSummary = [
    'latitude' => $location['latitude'], 'longitude' => $location['longitude'], 'basis' => $location['basis'],
    'label' => $location['basis'] === 'farm_pin'
        ? 'Weather-model grid cell for your registered farm pin. This is a model value for the cell, not a measurement at the farm.'
        : 'Weather-model grid cell for the Cabadbaran City centre. This farm has no map pin, so city-wide conditions are used. Add a pin in My Farms for a farm-specific cell.',
];

$result = weather_point_observation((float) $location['latitude'], (float) $location['longitude'], ['forceRefresh' => $forceRefresh]);
if ($result['status'] === 'unavailable') {
    $unavailable((string) $result['reasonCode'], (string) $result['reason'], $farmSummary, $locationSummary);
}

$logOutcome($farmSummary['id'], (string) $location['basis'], (string) $result['status'], $result['reasonCode'], $result['observationAgeMinutes']);
$stale = $result['status'] === 'stale';
$advice = weather_decision_advice($result['observation'], $farmSummary['category'], ['stale' => $stale, 'ageMinutes' => $result['observationAgeMinutes']]);
$locationSummary['providerGrid'] = weather_point_grid_summary($result['observation']);
api_response(200, ['success' => true, 'data' => $meta + [
    'status' => $result['status'],
    'statusMessage' => $result['message'],
    'reasonCode' => $result['reasonCode'],
    'reason' => $result['reason'],
    'farm' => $farmSummary,
    'location' => $locationSummary,
    'weather' => $result['observation'] + [
        'isStale' => $result['status'] === 'stale',
        'observationAgeMinutes' => $result['observationAgeMinutes'],
        'cacheAgeMinutes' => $result['cacheAgeMinutes'],
    ],
    'advice' => $advice,
    // Card summary of the advice above (no new wording); the full advice stays in 'advice'.
    'summary' => weather_advice_summary($advice),
]]);
