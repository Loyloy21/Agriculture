<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/../../backend/lib/weather_point.php';
require_once __DIR__ . '/../../backend/lib/weather_decision.php';
require_admin();

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

// Read-only: the rule table plus ONE live evaluation for the Cabadbaran City-centre point,
// shown per category. Never creates advisories or notifications.
$table = weather_rule_table();
$result = weather_point_observation(CABADBARAN_WEATHER_LATITUDE, CABADBARAN_WEATHER_LONGITUDE, ['allowStale' => true]);
$live = ['status' => $result['status'], 'message' => $result['message'], 'reason' => $result['reason'], 'weather' => null, 'byCategory' => []];
if ($result['observation'] !== null) {
    $live['weather'] = $result['observation'] + ['observationAgeMinutes' => $result['observationAgeMinutes'], 'cacheAgeMinutes' => $result['cacheAgeMinutes']];
    $live['grid'] = weather_point_grid_summary($result['observation']);
    foreach (weather_advice_categories() as $category) {
        $live['byCategory'][$category] = weather_decision_advice($result['observation'], $category, ['stale' => $result['status'] === 'stale', 'ageMinutes' => $result['observationAgeMinutes']]);
    }
}
echo json_encode(['success' => true, 'rules' => $table, 'live' => $live, 'locationLabel' => CABADBARAN_WEATHER_LOCATION . ' (city-centre point)'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
