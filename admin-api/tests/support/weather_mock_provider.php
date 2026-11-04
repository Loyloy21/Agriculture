<?php
declare(strict_types=1);

/**
 * Local-only mock of the Open-Meteo forecast endpoint, used by tests/weather_decision_support_integration.php.
 * Run with: php -S 127.0.0.1:PORT tests/weather_mock_provider.php
 * The scenario is read from the JSON file named by AGRIREACH_MOCK_SCENARIO_FILE; every request is logged
 * (coordinates only) to AGRIREACH_MOCK_LOG_FILE so tests can prove whether the provider was called.
 */
if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }

$scenarioFile = (string) getenv('AGRIREACH_MOCK_SCENARIO_FILE');
$logFile = (string) getenv('AGRIREACH_MOCK_LOG_FILE');
$scenario = is_file($scenarioFile) ? (json_decode((string) file_get_contents($scenarioFile), true) ?: []) : [];
$latitude = (float) ($_GET['latitude'] ?? 0);
$longitude = (float) ($_GET['longitude'] ?? 0);
if ($logFile !== '') file_put_contents($logFile, sprintf("%.5f,%.5f\n", $latitude, $longitude), FILE_APPEND | LOCK_EX);

$mode = (string) ($scenario['mode'] ?? 'ok');
if ($mode === 'timeout') {
    // Hold the connection open past the app's timeout, but stop as soon as the client has gone away.
    for ($i = 0; $i < 120; $i++) {
        usleep(100000);
        echo ' ';
        @flush();
        if (connection_aborted()) exit;
    }
    exit;
}
header('Content-Type: application/json');
if ($mode === 'http500') { http_response_code(500); echo '{"error":true,"reason":"mock failure"}'; exit; }
if ($mode === 'http429') { http_response_code(429); echo '{"error":true,"reason":"mock rate limit"}'; exit; }
if ($mode === 'badjson') { echo '<html>not json</html>'; exit; }

$minutesAgo = (int) ($scenario['observedMinutesAgo'] ?? 0);
$observed = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
$observed = $observed->modify('-' . $minutesAgo . ' minutes');
$observed = $observed->setTime((int) $observed->format('H'), intdiv((int) $observed->format('i'), 15) * 15);
$hourStart = $observed->setTime((int) $observed->format('H'), 0);

// hourly[] = values for hours h-5 .. h (the last is the current hour), then ONE future hour that must be ignored.
$hourlyValues = $scenario['hourly'] ?? [0, 0, 0, 0, 0, 0];
$hourlyTimes = [];
$hourlyPrecipitation = [];
$hourlyTimes[] = $hourStart->modify('-6 hours')->format('Y-m-d\TH:i');
$hourlyPrecipitation[] = 0.0;
foreach ($hourlyValues as $index => $value) {
    $hourlyTimes[] = $hourStart->modify('-' . (count($hourlyValues) - 1 - $index) . ' hours')->format('Y-m-d\TH:i');
    $hourlyPrecipitation[] = (float) $value;
}
$hourlyTimes[] = $hourStart->modify('+1 hour')->format('Y-m-d\TH:i');
$hourlyPrecipitation[] = 99.0; // future hour: the app must not use it

// Optional scenario controls: omit fields (simulating a provider that leaves values out) and move the grid point.
$omitCurrent = is_array($scenario['omit'] ?? null) ? $scenario['omit'] : [];
$omitHourly = ($scenario['omitHourly'] ?? false) === true;
if ($omitHourly) { $hourlyTimes = []; $hourlyPrecipitation = []; }
$temperature = $mode === 'implausible' ? 999.0 : (float) ($scenario['temperature'] ?? 29.0);
$gridLatitude = isset($scenario['gridLatitude']) ? (float) $scenario['gridLatitude'] : round($latitude, 1);
$gridLongitude = isset($scenario['gridLongitude']) ? (float) $scenario['gridLongitude'] : round($longitude, 1);
$currentBlock = [
    'time' => $observed->format('Y-m-d\TH:i'), 'interval' => 900,
    'temperature_2m' => $temperature, 'relative_humidity_2m' => (float) ($scenario['humidity'] ?? 80), 'apparent_temperature' => (float) ($scenario['apparent'] ?? 33.0),
    'precipitation' => (float) ($scenario['precipitation'] ?? 0.0), 'rain' => (float) ($scenario['precipitation'] ?? 0.0), 'weather_code' => (int) ($scenario['code'] ?? 3),
    'wind_speed_10m' => (float) ($scenario['wind'] ?? 8.0), 'wind_direction_10m' => 117, 'wind_gusts_10m' => (float) ($scenario['gusts'] ?? 15.0), 'is_day' => 1,
];
foreach ($omitCurrent as $key) unset($currentBlock[$key]);
echo json_encode([
    'latitude' => $gridLatitude, 'longitude' => $gridLongitude, 'elevation' => (float) ($scenario['elevation'] ?? 6.0), 'utc_offset_seconds' => 28800, 'timezone' => 'Asia/Manila',
    'current_units' => ['time' => 'iso8601', 'interval' => 'seconds', 'temperature_2m' => '°C', 'relative_humidity_2m' => '%', 'apparent_temperature' => '°C', 'precipitation' => 'mm', 'rain' => 'mm', 'weather_code' => 'wmo code', 'wind_speed_10m' => 'km/h', 'wind_direction_10m' => '°', 'wind_gusts_10m' => 'km/h', 'is_day' => ''],
    'current' => $currentBlock,
    'hourly_units' => ['time' => 'iso8601', 'precipitation' => 'mm'],
    'hourly' => ['time' => $hourlyTimes, 'precipitation' => $hourlyPrecipitation],
]);
