<?php
declare(strict_types=1);

/**
 * Weather-Driven Decision Support: controlled end-to-end test.
 *
 * Local-only. Starts two throw-away PHP built-in servers on loopback:
 *   - a MOCK weather provider (tests/weather_mock_provider.php) so every weather condition, timeout,
 *     stale reading and bad response can be produced on demand, and
 *   - the AgriReach API itself, configured to use that mock provider and a private cache directory.
 * Creates only uniquely named *.test farmers (and their farms/reports) and removes them afterwards.
 * It never contacts the real weather provider and never touches existing farmers or reports.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__, 2) . '/backend/config/database.php';
require_once dirname(__DIR__, 2) . '/backend/lib/weather_decision.php';
require_once dirname(__DIR__, 2) . '/backend/lib/weather_point.php';
require_once dirname(__DIR__, 2) . '/backend/lib/damage_reports.php';
require_once dirname(__DIR__, 2) . '/frontend/admin/includes/damage_report_pdf.php';

const MOCK_PORT = 18099;
const APP_PORT = 18080;
const OUTSIDE_LAT = 14.5995;
const OUTSIDE_LON = 120.9842;

$passed = 0;
function check(bool $condition, string $message): void
{
    global $passed;
    if (!$condition) throw new RuntimeException($message);
    $passed++;
    echo "PASS: {$message}\n";
}
function approx(float $a, float $b, float $delta = 0.0001): bool { return abs($a - $b) <= $delta; }

$workDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'agrireach-weather-test-' . bin2hex(random_bytes(4));
mkdir($workDir . DIRECTORY_SEPARATOR . 'cache', 0700, true);
$scenarioFile = $workDir . DIRECTORY_SEPARATOR . 'scenario.json';
$logFile = $workDir . DIRECTORY_SEPARATOR . 'provider-calls.log';
$cacheDir = $workDir . DIRECTORY_SEPARATOR . 'cache';
file_put_contents($logFile, '');

function scenario(array $values): void { global $scenarioFile; file_put_contents($scenarioFile, json_encode($values)); }
function clear_cache(): void { global $cacheDir; foreach (glob($cacheDir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) unlink($file); }
function provider_calls(): int { global $logFile; return count(array_filter(explode("\n", (string) file_get_contents($logFile)))); }

function api(string $route, ?string $token, array|string|null $body = null, string $method = 'GET'): array
{
    $handle = curl_init('http://127.0.0.1:' . APP_PORT . '/CAPSTONE%20AGRICULTURE/api' . $route);
    $headers = ['Accept: application/json'];
    if ($token !== null) $headers[] = 'Authorization: Bearer ' . $token;
    if (is_string($body)) $headers[] = 'Content-Type: application/json';
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 40]);
    if ($body !== null) curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
    $raw = curl_exec($handle);
    if ($raw === false) throw new RuntimeException('Local test HTTP connection failed.');
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);
    return [$status, json_decode((string) $raw, true), (string) $raw];
}
function advice(?string $token, ?int $farmId = null, bool $refresh = false): array
{
    $query = array_filter(['farmId' => $farmId, 'refresh' => $refresh ? 1 : null]);
    return api('/weather/advice.php' . ($query ? '?' . http_build_query($query) : ''), $token);
}

function start_server(string $address, ?string $router, array $env, ?string $docroot = null): mixed
{
    $command = [PHP_BINARY, '-S', $address];
    if ($docroot !== null) { $command[] = '-t'; $command[] = $docroot; }
    if ($router !== null) $command[] = $router;
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir() . '/agrireach-weather-test-server.out', 'a'], 2 => ['file', sys_get_temp_dir() . '/agrireach-weather-test-server.err', 'a']], $pipes, null, array_merge(getenv(), $env));
    if (!is_resource($process)) throw new RuntimeException('Could not start a local test server.');
    [$host, $port] = explode(':', $address);
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $socket = @fsockopen($host, (int) $port, $errno, $errstr, 0.2);
        if ($socket) { fclose($socket); return $process; }
        usleep(100000);
    }
    throw new RuntimeException('Local test server did not start.');
}

$connection = db();
$mockServer = $appServer = null;
$created = [];
$storedImages = [];
$temporaryImage = tempnam(sys_get_temp_dir(), 'agrireach-weather-image-');
$exitCode = 0;

try {
    // Fingerprints: prove this run leaves pre-existing weather rows, reports and advisory recipients untouched.
    $fingerprint = static fn(string $table): string => hash('sha256', json_encode($connection->query("SELECT * FROM {$table} ORDER BY 1")->fetchAll()));
    $baseline = ['weather_data' => $fingerprint('weather_data'), 'damage_reports' => $fingerprint('damage_reports'), 'weather_advisory_recipients' => $fingerprint('weather_advisory_recipients'), 'weather_advisories' => $fingerprint('weather_advisories')];

    scenario(['mode' => 'ok']);
    $mockServer = start_server('127.0.0.1:' . MOCK_PORT, dirname(__DIR__, 2) . '/tests/support/weather_mock_provider.php', ['AGRIREACH_MOCK_SCENARIO_FILE' => $scenarioFile, 'AGRIREACH_MOCK_LOG_FILE' => $logFile]);
    $appServer = start_server('127.0.0.1:' . APP_PORT, dirname(__DIR__) . '/support/router.php', ['AGRIREACH_WEATHER_BASE_URL' => 'http://127.0.0.1:' . MOCK_PORT . '/v1/forecast', 'AGRIREACH_WEATHER_CACHE_DIR' => $cacheDir], dirname(__DIR__, 3));
    // Safety check: the provider override must be honoured only for loopback hosts.
    putenv('AGRIREACH_WEATHER_BASE_URL=https://evil.example/v1/forecast');
    check(current_weather_provider_base() === CURRENT_WEATHER_PROVIDER_BASE, 'a non-loopback provider override is ignored (farmer coordinates cannot be redirected)');
    putenv('AGRIREACH_WEATHER_BASE_URL');

    file_put_contents($temporaryImage, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a3ioAAAAASUVORK5CYII='));

    // ---------- Rule engine: boundaries, missing data, contradictions, heat index, categories (pure; own test file) ----------
    $unitOutput = [];
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/tests/backend/weather_rule_engine_unit.php') . ' 2>&1', $unitOutput, $unitExit);
    $unitText = implode(PHP_EOL, $unitOutput);
    $unitCount = preg_match('/ALL (\d+) CHECKS PASSED/', $unitText, $unitMatch) ? (int) $unitMatch[1] : 0;
    check($unitExit === 0 && $unitCount > 100, "the rule-engine unit tests ({$unitCount} checks) pass");

    // ---------- Fixtures ----------
    $stamp = bin2hex(random_bytes(6));
    $points = [];
    for ($lat = 9.08; $lat <= 9.20 && count($points) < 8; $lat += 0.03) {
        for ($lon = 125.45; $lon <= 125.62 && count($points) < 8; $lon += 0.03) {
            if (cabadbaran_boundary_check($lat, $lon)['inside'] === true) $points[] = [round($lat, 4), round($lon, 4)];
        }
    }
    check(count($points) >= 6, 'six distinct points inside the Cabadbaran boundary were found for farm pins');

    $makeFarmer = static function (string $name) use ($connection, $stamp, &$created): array {
        $email = "weather-{$stamp}-{$name}@example.test";
        $contact = '+639' . str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
        $statement = $connection->prepare("INSERT INTO farmers (full_name, contact_number, email, address, account_status, verification_status) VALUES ('Controlled Weather DSS Test', ?, ?, 'Controlled test address', 'Active', 'Approved')");
        $statement->execute([$contact, $email]);
        $id = (int) $connection->lastInsertId();
        $token = bin2hex(random_bytes(32)); $hash = hash('sha256', $token);
        $statement = $connection->prepare('INSERT INTO farmer_sessions (farmer_id, token_hash, expires_at) VALUES (?, ?, ' . db_sql_utc_now($connection) . " + INTERVAL '1' HOUR)");
        $statement->execute([$id, $hash]);
        return $created[$name] = ['id' => $id, 'email' => $email, 'token' => $token, 'farms' => []];
    };
    $makeFarm = static function (string $owner, string $key, string $category, ?float $lat, ?float $lon, bool $primary = false) use ($connection, &$created): void {
        $statement = $connection->prepare("INSERT INTO farm_profiles (farmer_id, farm_name, farm_type, agricultural_category, farm_address, city_psgc_code, city_name, province_name, barangay_psgc_code, barangay_name, location_review_status, latitude, longitude, status, is_primary)
            VALUES (?, ?, ?, ?, 'Controlled Cabadbaran test farm', '1600203000', 'Cabadbaran City', 'Agusan del Norte', '1600203021', 'Poblacion 1', 'Verified', ?, ?, 'Active', ?)");
        $name = 'Test ' . $key . ' farm'; $primaryFlag = $primary ? 1 : 0; $farmerId = $created[$owner]['id'];
        $statement->execute([$farmerId, $name, $category, $category, $lat, $lon, $primaryFlag]);
        $created[$owner]['farms'][$key] = ['id' => (int) $connection->lastInsertId(), 'category' => $category, 'lat' => $lat, 'lon' => $lon];
    };
    $a = $makeFarmer('a'); $b = $makeFarmer('b');
    $makeFarm('a', 'crops', 'Crops', $points[0][0], $points[0][1], true);
    $makeFarm('a', 'livestock', 'Livestock', $points[1][0], $points[1][1]);
    $makeFarm('a', 'poultry', 'Poultry', $points[2][0], $points[2][1]);
    $makeFarm('a', 'fishpond', 'Fishpond', $points[3][0], $points[3][1]);
    $makeFarm('a', 'nopin', 'Crops', null, null);
    $makeFarm('a', 'outside', 'Poultry', OUTSIDE_LAT, OUTSIDE_LON);
    $makeFarm('b', 'fishpond', 'Fishpond', $points[4][0], $points[4][1], true);
    $tokenA = $created['a']['token']; $tokenB = $created['b']['token'];
    $farmsA = $created['a']['farms'];

    // ---------- Each weather condition x each farm category, through the real API ----------
    $quiet = [0, 0, 0, 0, 0, 0];
    $conditions = [
        'thunderstorm' => ['code' => 95, 'humidity' => 60, 'hourly' => $quiet],
        'heavy_rain' => ['code' => 65, 'humidity' => 60, 'hourly' => [0, 0, 0, 0.2, 0.1, 8.0], 'precipitation' => 1.0],
        'rain_accumulation' => ['humidity' => 60, 'hourly' => [0, 0, 0, 5, 5, 5]],
        'continuous_rain' => ['humidity' => 60, 'hourly' => [0.6, 1.2, 0.8, 1.5, 0.9, 1.0], 'precipitation' => 0.2],
        'high_temperature' => ['temperature' => 33.5, 'humidity' => 75, 'apparent' => 38.0, 'hourly' => $quiet],
        'strong_wind' => ['humidity' => 60, 'wind' => 45.5, 'gusts' => 70.0, 'hourly' => $quiet],
        'no_threshold_exceeded' => ['temperature' => 29.0, 'humidity' => 60, 'apparent' => 33.0, 'wind' => 8.0, 'gusts' => 15.0, 'hourly' => [0, 0, 0.1, 0.2, 0, 0]],
    ];
    foreach ($conditions as $code => $values) {
        foreach (['crops', 'livestock', 'poultry', 'fishpond'] as $farmKey) {
            clear_cache();
            scenario(['mode' => 'ok'] + $values);
            [$status, $data] = advice($tokenA, $farmsA[$farmKey]['id']);
            $d = $data['data'] ?? [];
            check($status === 200 && ($data['success'] ?? false) && ($d['status'] ?? '') === 'current', "{$code} / {$farmsA[$farmKey]['category']}: current reading returned (HTTP {$status})");
            $expectedCategory = $farmsA[$farmKey]['category'];
            $matchedCodes = array_column($d['advice']['matched'], 'code');
            $none = $code === 'no_threshold_exceeded';
            check($none ? ($d['advice']['normal'] === true && $d['advice']['assessment'] === 'no_threshold_exceeded' && $matchedCodes === [] && $d['advice']['headline'] === 'No listed threshold exceeded') : ($matchedCodes === [$code] && $d['advice']['assessment'] === 'thresholds_exceeded' && $d['advice']['normal'] === false), "{$code} / {$expectedCategory}: matched condition is exactly " . ($none ? 'none ("No listed threshold exceeded")' : $code));
            $recommendation = $d['advice']['recommendations'][0];
            $expectedActions = weather_rule_guidance()[$code][$expectedCategory];
            if ($code === 'high_temperature') $expectedActions = array_merge(weather_heat_extra_actions('danger'), $expectedActions);
            check($recommendation['conditionCode'] === $code && $recommendation['category'] === $expectedCategory && $recommendation['actions'] === $expectedActions, "{$code} / {$expectedCategory}: category-specific recommended actions returned");
            check($d['advice']['observedAt'] === $d['weather']['observedAt'] && $recommendation['observedAt'] === $d['weather']['observedAt'] && str_starts_with($recommendation['source'], 'Open-Meteo'), "{$code} / {$expectedCategory}: advice carries observation time and data source");
            $summary = $d['summary'];
            $expectedLevel = $none ? 'normal' : (in_array($code, ['thunderstorm', 'heavy_rain', 'strong_wind', 'high_temperature'], true) ? 'urgent' : 'caution');
            $point = $summary['points'][0];
            check($summary['level'] === $expectedLevel && count($summary['points']) === 1 && $summary['also'] === [] && $summary['title'] === $d['advice']['headline'] && ($none ? ($point['detail'] === $expectedActions[0] && $point['action'] === $expectedActions[1]) : ($point['conditionCode'] === $code && $point['action'] === $expectedActions[0] && $point['detail'] === $d['advice']['matched'][0]['measured'])), "{$code} / {$expectedCategory}: card summary is level {$expectedLevel} with the measured value and the first action word for word");
            check($d['advice']['source'] === 'Weather-based advice' && str_contains($d['advice']['method'], 'Not AI') && $d['advice']['ruleSetVersion'] === WEATHER_RULE_SET_VERSION && str_contains($d['advice']['disclaimer'], 'official PAGASA warning'), "{$code} / {$expectedCategory}: labelled rule-based, not AI, not an official warning, with rule-set version");
            check($d['farm']['id'] === $farmsA[$farmKey]['id'] && $d['location']['basis'] === 'farm_pin' && approx($d['weather']['requestedLatitude'], (float) $farmsA[$farmKey]['lat'], 0.000001), "{$code} / {$expectedCategory}: weather was requested for the selected farm's own pin");
            check($d['weather']['providerLatitude'] !== null && $d['weather']['endpoint'] !== '' && $d['weather']['fetchedAt'] !== '' && $d['weather']['units']['windSpeed'] === 'km/h' && $d['location']['providerGrid']['representsModelGridCell'] === true && $d['location']['providerGrid']['distanceKm'] !== null, "{$code} / {$expectedCategory}: provider grid point, grid distance, endpoint, retrieval time and units are recorded");
        }
    }
    clear_cache(); scenario(['mode' => 'ok'] + $conditions['heavy_rain']);
    [, $data] = advice($tokenA, $farmsA['crops']['id']);
    check(approx((float) $data['data']['weather']['precipitationLast6h'], 8.3, 0.01) && approx((float) $data['data']['weather']['temperature'], 29.0, 0.001), 'mock values (6 h rain total 8.3 mm, 29.0 °C) reach the API unchanged; the future forecast hour (99 mm) is ignored');

    // ---------- Multiple farms, ownership, default farm ----------
    [$status, $data] = advice($tokenA);
    check($status === 200 && $data['data']['farm']['id'] === $farmsA['crops']['id'], 'without farmId the primary farm is used and named in the response');
    [$status, , ] = advice($tokenA, $created['b']['farms']['fishpond']['id']);
    check($status === 404, "another farmer's farm cannot be queried (404)");
    [$status, ] = advice(null, $farmsA['crops']['id']);
    check($status === 401, 'weather-based advice requires authentication');
    [$status, $data] = advice($tokenB);
    check($status === 200 && $data['data']['advice']['category'] === 'Fishpond' && $data['data']['farm']['id'] === $created['b']['farms']['fishpond']['id'], 'a different farmer receives advice for their own farm and category');
    $heavyGuidanceFish = weather_rule_guidance()['heavy_rain']['Fishpond'];
    check($data['data']['advice']['recommendations'][0]['actions'] === $heavyGuidanceFish, 'farmer B sees the real Fishpond heavy-rain guidance');
    [, $data] = advice($tokenA, $farmsA['poultry']['id']);
    check($data['data']['advice']['recommendations'][0]['actions'] === weather_rule_guidance()['heavy_rain']['Poultry'] && $data['data']['farm']['category'] === 'Poultry', 'farmer A selecting the Poultry farm sees Poultry guidance for the same weather');
    [$status] = api('/weather/advice.php?farmId=abc', $tokenA);
    check($status === 422, 'an invalid farmId is rejected (422)');

    // ---------- Accuracy-audit scenarios through the real API ----------
    $adviceFor = static function (array $scenarioValues, string $farmKey = 'crops') use ($tokenA, $farmsA): array {
        clear_cache(); scenario(['mode' => 'ok'] + $scenarioValues);
        return advice($tokenA, $farmsA[$farmKey]['id'])[1]['data'];
    };
    $clean = ['temperature' => 29.0, 'humidity' => 60, 'apparent' => 33.0, 'wind' => 8.0, 'gusts' => 15.0, 'hourly' => [0, 0, 0, 0, 0, 0]];
    // 1. Missing fields must never read as an all-clear.
    foreach ([
        'wind speed and gusts missing' => [['omit' => ['wind_speed_10m', 'wind_gusts_10m']], 'strong wind'],
        'humidity missing' => [['omit' => ['relative_humidity_2m']], 'high heat'],
        'hourly rainfall history missing' => [['omitHourly' => true], 'heavy rain'],
    ] as $label => [$extra, $expectedPhrase]) {
        $d = $adviceFor($extra + $clean);
        check($d['status'] === 'current' && $d['advice']['assessment'] === 'limited' && $d['advice']['normal'] === false && str_starts_with($d['advice']['headline'], 'Limited weather check') && str_contains($d['advice']['headline'], $expectedPhrase), "{$label}: API says \"Limited weather check\" and names '{$expectedPhrase}' as not checked");
        $limited = weather_rule_guidance()['limited_check']['Crops'];
        check($d['summary']['level'] === 'limited' && $d['summary']['title'] === $d['advice']['headline'] && str_contains($d['summary']['title'], $expectedPhrase) && $d['summary']['points'][0]['detail'] === $limited[0] && $d['summary']['points'][0]['action'] === $limited[1], "{$label}: card summary says 'Limited check', names what is missing and does not read as normal");
        check($d['advice']['matched'] === [] && $d['advice']['recommendations'][0]['conditionCode'] === 'limited_check' && !str_contains(json_encode($d['advice']), 'Continue regular'), "{$label}: no 'normal' wording or 'Continue regular activities' is returned");
    }
    clear_cache(); scenario(['mode' => 'ok', 'omit' => ['weather_code']] + $clean);
    $d = advice($tokenA, $farmsA['crops']['id'])[1]['data'];
    check($d['status'] === 'unavailable' && $d['reasonCode'] === 'provider_invalid_response' && $d['advice'] === null && $d['summary'] === null, 'a response without a weather code is refused as unusable (no advice invented)');
    // 2. Thunderstorm and contradictions.
    $d = $adviceFor(['code' => 96] + $clean);
    check($d['advice']['assessment'] === 'thresholds_exceeded' && array_column($d['advice']['matched'], 'code') === ['thunderstorm'] && $d['advice']['normal'] === false && str_contains($d['advice']['headline'], 'Thunderstorm'), 'thunderstorm code 96 with zero rain: API returns "Thunderstorm indicated", not normal');
    $d = $adviceFor(['code' => 65] + $clean);
    check($d['summary']['level'] === 'unclear' && $d['summary']['points'][0]['action'] === weather_rule_guidance()['data_unclear']['Crops'][1], 'contradictory rain data: card summary is "Data unclear" with the look-at-your-farm instruction');
    check($d['advice']['assessment'] === 'unclear' && $d['advice']['headline'] === 'Weather data unclear' && $d['advice']['normal'] === false && $d['advice']['conflicts'][0]['code'] === 'rain_code_mismatch', 'heavy-rain code 65 with zero rain values: API returns "Weather data unclear"');
    // 3. Rainfall boundaries use hourly totals; the 15-minute value is not multiplied.
    $d = $adviceFor(['hourly' => [0, 0, 0, 0, 0, 7.4], 'precipitation' => 1.9] + $clean);
    check(array_column($d['advice']['matched'], 'code') === [] && $d['advice']['assessment'] === 'no_threshold_exceeded', 'hourly 7.4 mm with a 15-minute 1.9 mm value: no false heavy-rain trigger');
    $d = $adviceFor(['hourly' => [0, 0, 0, 0, 0, 7.5], 'precipitation' => 0.0] + $clean);
    check(array_column($d['advice']['matched'], 'code') === ['heavy_rain'] && approx((float) $d['advice']['calculations']['latestHourMm'], 7.5), 'hourly 7.5 mm triggers heavy rain even when the 15-minute value is 0');
    $d = $adviceFor(['hourly' => [0, 0, 0, 7.4, 7.4, 7.4]] + $clean);
    check(array_column($d['advice']['matched'], 'code') === ['rain_accumulation'] && approx((float) $d['advice']['calculations']['last3hMm'], 22.2, 0.001), '7.4 mm in each of three hours (22.2 mm) is reported as "Rain building up"');
    // 4. Heat index, not apparent temperature.
    $d = $adviceFor(['temperature' => 33.5, 'humidity' => 75, 'apparent' => 38.0] + $clean);
    check($d['advice']['matched'][0]['code'] === 'high_temperature' && $d['advice']['matched'][0]['severity'] === 'danger' && approx((float) $d['advice']['calculations']['heatIndexC'], 47.5, 0.001) && str_contains($d['advice']['matched'][0]['measured'], 'Estimated heat index 47.5'), 'audit case 33.5 °C / 75% humidity (old result: "Normal") is now a Danger-class heat index of 47.5 °C');
    $d = $adviceFor(['temperature' => 27.0, 'humidity' => 50, 'apparent' => 45.0] + $clean);
    check($d['advice']['matched'] === [] && $d['advice']['assessment'] === 'no_threshold_exceeded', 'a high Open-Meteo apparent temperature (45 °C) alone does not trigger the heat rule');
    // 5. Grid distance, elevation and wording, for several farm locations.
    $gridCell = ['gridLatitude' => 9.033, 'gridLongitude' => 125.580, 'elevation' => 826];
    $distances = [];
    foreach (['crops', 'livestock', 'poultry', 'fishpond'] as $farmKey) {
        $d = $adviceFor($gridCell + $clean, $farmKey);
        $expected = round(weather_point_distance_km((float) $farmsA[$farmKey]['lat'], (float) $farmsA[$farmKey]['lon'], 9.033, 125.580), 1);
        $grid = $d['location']['providerGrid'];
        $distances[$farmKey] = $grid['distanceKm'];
        check(approx((float) $grid['distanceKm'], $expected, 0.0001) && $grid['elevationM'] === 826 && $grid['representsModelGridCell'] === true, "{$farmKey} farm: grid distance {$grid['distanceKm']} km and elevation 826 m match an independent calculation");
        check(str_contains(strtolower($d['location']['label']), 'model grid cell') && !str_contains($d['location']['label'], 'Your registered farm pin') && str_contains(implode(' ', $grid['notes']), '826 m'), "{$farmKey} farm: label says model grid cell (not an exact farm measurement) and warns about the 826 m grid elevation");
    }
    check(count(array_unique($distances)) > 1, 'different farm pins give different grid distances (distance is computed per selected farm)');
    $d = $adviceFor($gridCell + $clean, 'nopin');
    check($d['location']['basis'] === 'city_centre' && str_contains($d['location']['label'], 'model grid cell') && str_contains($d['location']['label'], 'no map pin') && $d['location']['providerGrid']['distanceKm'] !== null, 'a farm without a pin still shows the city-centre model-grid label and a distance');

    // ---------- Card summary: several warnings at once ----------
    $d = $adviceFor(['code' => 95, 'wind' => 45.0, 'gusts' => 60.0, 'hourly' => [0, 0, 0, 5.0, 5.0, 8.0]] + $clean, 'fishpond');
    $guide = weather_rule_guidance();
    $summary = $d['summary'];
    check(array_column($d['advice']['matched'], 'code') === ['thunderstorm', 'heavy_rain', 'strong_wind', 'rain_accumulation'], 'storm scenario triggers thunderstorm, heavy rain, strong wind and rain building up');
    check($summary['level'] === 'urgent' && array_column($summary['points'], 'conditionCode') === ['thunderstorm', 'heavy_rain', 'strong_wind'] && array_column($summary['points'], 'action') === [$guide['thunderstorm']['Fishpond'][0], $guide['heavy_rain']['Fishpond'][0], $guide['strong_wind']['Fishpond'][0]], 'storm summary keeps EVERY urgent warning, in priority order, each with its first Fishpond action word for word');
    check($summary['also'] === ['Rain building up'], 'the non-urgent extra condition is named, not dropped');
    $d = $adviceFor(['temperature' => 33.5, 'humidity' => 75, 'apparent' => 38.0] + $clean, 'livestock');
    check($d['summary']['level'] === 'urgent' && $d['summary']['points'][0]['action'] === weather_heat_extra_actions('danger')[0] && str_contains($d['summary']['points'][0]['detail'], 'Danger'), 'heat index in the Danger class: summary is urgent and its action is the Danger instruction');
    $d = $adviceFor(['hourly' => [0, 0, 0, 7.4, 7.4, 7.4]] + $clean);
    check($d['summary']['level'] === 'caution' && $d['summary']['points'][0]['action'] === $guide['rain_accumulation']['Crops'][0], 'rain building up only: summary is a caution with the first crops action');
    $summaryText = json_encode($d['summary']);
    check(!str_contains($summaryText, 'Thunderstorm') && !str_contains($summaryText, 'Strong wind') && !str_contains($summaryText, 'Heavy rain'), 'the summary names no condition the reading did not trigger');

    // ---------- Missing coordinates and out-of-city coordinates ----------
    clear_cache(); $callsBefore = provider_calls();
    [, $data] = advice($tokenA, $farmsA['nopin']['id']);
    $d = $data['data'];
    check($d['status'] === 'current' && $d['location']['basis'] === 'city_centre' && str_contains($d['location']['label'], 'no map pin') && approx($d['weather']['requestedLatitude'], CABADBARAN_WEATHER_LATITUDE, 0.001), 'a farm without a pin falls back to the Cabadbaran city centre and says so');
    $callsBefore = provider_calls();
    [$status, $data] = advice($tokenA, $farmsA['outside']['id']);
    $d = $data['data'];
    check($status === 200 && $d['status'] === 'unavailable' && $d['reasonCode'] === 'location_outside_city' && $d['advice'] === null && $d['weather'] === null, 'out-of-city farm coordinates give "unavailable" with a reason and no advice');
    check(provider_calls() === $callsBefore, 'the weather provider was NOT called for out-of-city coordinates');

    // ---------- Provider failures: honest unavailable states ----------
    foreach (['http500' => 'provider_http_error', 'http429' => 'provider_rate_limited', 'badjson' => 'provider_invalid_response', 'implausible' => 'provider_invalid_response'] as $mode => $expectedCode) {
        clear_cache(); scenario(['mode' => $mode]);
        [$status, $data] = advice($tokenA, $farmsA['crops']['id']);
        $d = $data['data'];
        check($status === 200 && $d['status'] === 'unavailable' && $d['reasonCode'] === $expectedCode && $d['advice'] === null && $d['summary'] === null && $d['weather'] === null && $d['reason'] !== '', "provider {$mode}: unavailable with reason {$expectedCode}, no advice, no invented weather");
    }
    clear_cache(); scenario(['mode' => 'timeout']);
    $startedAt = microtime(true);
    [$status, $data] = advice($tokenA, $farmsA['crops']['id']);
    $elapsed = microtime(true) - $startedAt;
    check($status === 200 && $data['data']['status'] === 'unavailable' && $data['data']['reasonCode'] === 'provider_timeout' && $elapsed < 15, sprintf('provider timeout: unavailable (provider_timeout) after %.1f s, request did not hang', $elapsed));
    sleep(1);

    // ---------- Stale readings ----------
    $ageCache = static function (int $minutes) use ($cacheDir): void {
        foreach (glob($cacheDir . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
            $cache = json_decode((string) file_get_contents($file), true);
            $cache['cachedAt'] = time() - $minutes * 60;
            $then = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->modify("-{$minutes} minutes")->format(DATE_ATOM);
            $cache['observation']['observedAt'] = $then; $cache['observation']['fetchedAt'] = $then;
            file_put_contents($file, json_encode($cache));
        }
    };
    clear_cache(); scenario(['mode' => 'ok'] + $conditions['heavy_rain']);
    advice($tokenA, $farmsA['crops']['id']);
    $callsBefore = provider_calls();
    [, $data] = advice($tokenA, $farmsA['crops']['id']);
    check($data['data']['status'] === 'current' && provider_calls() === $callsBefore && $data['data']['weather']['cacheAgeMinutes'] === 0, 'a reading under 10 minutes old is reused as current without another provider call');
    $ageCache(30); scenario(['mode' => 'http500']);
    [, $data] = advice($tokenA, $farmsA['crops']['id']);
    $d = $data['data'];
    check($d['status'] === 'stale' && $d['summary']['stale'] === true && $d['weather']['isStale'] === true && $d['advice']['basedOnStaleReading'] === true && $d['weather']['cacheAgeMinutes'] >= 29 && str_contains($d['statusMessage'], 'not current'), 'provider down + 30-minute-old cache: labelled STALE with its age and "not current", never "current"');
    check($d['advice']['matched'][0]['code'] === 'heavy_rain' && $d['advice']['assessment'] === 'thresholds_exceeded' && $d['advice']['observedAt'] === $d['weather']['observedAt'], 'stale advice still states the (old) observation time it is based on');
    check(array_column($d['advice']['limitations'], 'kind') === ['not_current'] && str_contains($d['advice']['limitations'][0]['reason'], 'minutes old'), 'stale advice lists "reading is not current" with its age as a limitation');
    $ageCache(180);
    [, $data] = advice($tokenA, $farmsA['crops']['id']);
    check($data['data']['status'] === 'unavailable' && $data['data']['advice'] === null, 'a 3-hour-old cached reading is too old to show: unavailable');
    clear_cache(); scenario(['mode' => 'ok', 'observedMinutesAgo' => 120] + $conditions['no_threshold_exceeded']);
    [, $data] = advice($tokenA, $farmsA['crops']['id']);
    check($data['data']['status'] === 'stale' && $data['data']['weather']['observationAgeMinutes'] >= 119, 'provider answers with a 2-hour-old observation: labelled stale, not current');
    check($data['data']['advice']['assessment'] === 'limited' && $data['data']['advice']['normal'] === false && $data['data']['advice']['basedOnStaleReading'] === true && str_contains($data['data']['advice']['headline'], 'not current') && !str_contains(json_encode($data['data']['advice']['recommendations']), 'No listed threshold exceeded'), 'a stale reading with nothing triggered is a "Limited weather check", never "No listed threshold exceeded" or normal');

    // ---------- Damage report: weather snapshot when the provider works ----------
    $notificationCount = static function (int $farmerId) use ($connection): int {
        $statement = $connection->prepare('SELECT COUNT(*) c FROM notifications WHERE farmer_id = ?');
        $statement->execute([$farmerId]);
        return (int) $statement->fetch()['c'];
    };
    $reportBody = static function (array $farm, string $submission, float $lat, float $lon, string $type) use ($temporaryImage): array {
        return [
            'farm_profile_id' => (string) $farm['id'], 'farm_type' => $farm['category'], 'damage_type' => 'Flood',
            'damage_description' => 'Controlled weather snapshot test, not a real damage report.', 'estimated_damage_cost' => '1.25',
            'submitted_address' => 'Controlled Cabadbaran test location', 'submitted_latitude' => (string) $lat, 'submitted_longitude' => (string) $lon,
            'client_submission_id' => $submission, 'location_confirmed' => '1', 'location_source' => 'manual',
            'photos[0]' => new CURLFile($temporaryImage, 'image/png', 'controlled.png'), 'photo_latitude[0]' => '', 'photo_longitude[0]' => '', 'photo_gps_source[0]' => 'none',
            'photo_gps_accuracy[0]' => '', 'photo_captured_at[0]' => '', 'photo_capture_time_source[0]' => 'none', 'photo_location_timestamp[0]' => '', 'photo_location_status[0]' => 'no_gps',
        ];
    };
    $reportRow = static function (string $code) use ($connection): array {
        $statement = $connection->prepare('SELECT id, farmer_id FROM damage_reports WHERE report_code = ?');
        $statement->execute([$code]);
        return $statement->fetch() ?: [];
    };
    $notificationsBeforeAdviceCalls = $notificationCount($created['a']['id']);
    $recipientsBefore = $fingerprint('weather_advisory_recipients');

    clear_cache(); scenario(['mode' => 'ok'] + $conditions['heavy_rain']);
    $farm = $farmsA['poultry']; $pointLat = (float) $farm['lat']; $pointLon = (float) $farm['lon'];
    $submissionOk = 'weather_ok_' . $stamp;
    $before = time();
    [$status, $data] = api('/reports/index.php', $tokenA, $reportBody($farm, $submissionOk, $pointLat, $pointLon, 'ok'), 'POST');
    check($status === 201 && ($data['success'] ?? false), "report with working weather provider saved (HTTP {$status})");
    $okReport = $reportRow($data['data']['id']);
    $weatherRows = $connection->query('SELECT * FROM weather_data WHERE report_id = ' . (int) $okReport['id'])->fetchAll();
    check(count($weatherRows) === 1 && $weatherRows[0]['snapshot_status'] === 'Available' && $weatherRows[0]['provider'] === 'Open-Meteo', 'exactly one real, Available weather snapshot was stored for the new report');
    $snapshot = $weatherRows[0];
    check(approx((float) $snapshot['requested_latitude'], $pointLat, 0.0000002) && approx((float) $snapshot['requested_longitude'], $pointLon, 0.0000002) && $snapshot['location_basis'] === 'damage_point', "snapshot is tied to the report's confirmed damage coordinates");
    check($snapshot['observed_at_utc'] !== null && $snapshot['retrieved_at_utc'] !== null && $snapshot['report_submitted_at_utc'] !== null && abs(strtotime($snapshot['report_submitted_at_utc'] . ' UTC') - $before) < 60, 'snapshot records observation time, retrieval time and the server submission time (UTC)');
    check($snapshot['weather_condition'] !== null && approx((float) $snapshot['temperature'], 29.0, 0.01) && approx((float) $snapshot['precipitation_mm'], 8.0, 0.01) && approx((float) $snapshot['wind_speed_kmh'], 8.0, 0.01) && $snapshot['provider_latitude'] !== null, 'snapshot stores the provider values exactly as returned (not placeholders)');
    check($snapshot['rule_category'] === 'Poultry' && $snapshot['rule_set_version'] === WEATHER_RULE_SET_VERSION, 'snapshot records the rule category (the report farm type) and rule-set version');
    $stored = json_decode((string) $snapshot['rule_result_json'], true);
    check($stored['matched'][0]['code'] === 'heavy_rain' && $stored['recommendations'][0]['actions'] === weather_rule_guidance()['heavy_rain']['Poultry'], 'stored rule result is heavy rain with the Poultry guidance');
    [, $liveAdvice] = advice($tokenA, $farm['id']);
    check($liveAdvice['data']['weather']['observedAt'] === $stored['observedAt'] && $liveAdvice['data']['advice']['recommendations'][0]['actions'] === $stored['recommendations'][0]['actions'], 'the same real advice and observation time appear in the farmer API and in the admin snapshot');
    $adminReport = damage_report_fetch($connection, damage_report_filters_from_input([]), (int) $okReport['id'], true)[0];
    check(($adminReport['weather']['snapshot_status'] ?? '') === 'Available' && ($adminReport['weather']['rule_result']['matched'][0]['code'] ?? '') === 'heavy_rain' && $adminReport['weather']['provider'] === 'Open-Meteo', 'Admin Damage Reports data (damage_report_fetch) returns the snapshot and the decoded rule result for the correct report');
    $pdfHtml = damage_report_pdf_weather_html($adminReport['weather']);
    check(str_contains($pdfHtml, 'Open-Meteo') && str_contains($pdfHtml, 'Heavy rain') && str_contains($pdfHtml, 'Weather-Based Advice Result') && str_contains($pdfHtml, 'Report submitted (server)'), 'PDF export shows the weather evidence and, separately, the rule result');

    check($stored['assessment'] === 'thresholds_exceeded' && $stored['ruleSetVersion'] === WEATHER_RULE_SET_VERSION && str_contains($stored['disclaimer'], 'official PAGASA warning') && ($adminReport['weather']['provider_distance_km'] ?? null) !== null, 'the stored snapshot carries the new assessment, disclaimer and rule-set version, and the admin data adds the model-grid distance at read time');
    check(str_contains($pdfHtml, 'Model grid distance') && str_contains($pdfHtml, 'not a PAGASA warning'), 'PDF export shows the model-grid distance and that the result is not a PAGASA warning');
    // Old snapshots (rule set 2026.10-1, no "assessment") are displayed as recorded and labelled, never re-evaluated or rewritten.
    $legacyWeather = ['snapshot_status' => 'Available', 'provider' => 'Open-Meteo', 'observation_type' => 'Current model conditions', 'weather_condition' => 'Overcast', 'rule_result' => ['source' => 'Weather-based advice', 'ruleSetVersion' => '2026.10-1', 'category' => 'Crops', 'normal' => true, 'matched' => [], 'recommendations' => [['conditionCode' => 'normal', 'conditionLabel' => 'No threshold exceeded', 'category' => 'Crops', 'actions' => ['Continue regular field activities.']]]]];
    $legacyHtml = damage_report_pdf_weather_html($legacyWeather);
    check(str_contains($legacyHtml, 'earlier rule set 2026.10-1 and not re-evaluated') && !str_contains($legacyHtml, 'Normal conditions'), 'a snapshot recorded under rule set 2026.10-1 is shown as recorded and "not re-evaluated", without a "Normal conditions" claim');
    foreach (['limited' => ['weatherCode' => null, 'headline' => 'Limited weather check'], 'unclear' => ['weatherCode' => 65, 'headline' => 'Weather data unclear']] as $state => $info) {
        $observation = ['provider' => 'Open-Meteo', 'observationType' => 'Current model conditions', 'observedAt' => '2026-10-12T10:00:00+08:00', 'temperature' => 29.0, 'humidity' => 60.0, 'windSpeed' => 8.0, 'windGusts' => 15.0, 'hourlyPrecipitation' => array_map(static fn($v) => ['time' => 't', 'precipitationMm' => $v], [0, 0, 0, 0, 0, 0]), 'weatherCode' => $info['weatherCode']];
        $stateHtml = damage_report_pdf_weather_html(['snapshot_status' => 'Available', 'provider' => 'Open-Meteo', 'rule_result' => weather_decision_advice($observation, 'Crops')]);
        check(str_contains($stateHtml, $info['headline']) && !str_contains($stateHtml, 'Normal conditions') && !str_contains($stateHtml, 'No listed threshold exceeded'), "PDF export of a '{$state}' result shows \"{$info['headline']}\" and no all-clear wording");
    }

    // ---------- Damage report: provider down ----------
    clear_cache(); scenario(['mode' => 'timeout']);
    $submissionDown = 'weather_down_' . $stamp;
    $startedAt = microtime(true);
    [$status, $data] = api('/reports/index.php', $tokenA, $reportBody($farmsA['livestock'], $submissionDown, (float) $farmsA['livestock']['lat'], (float) $farmsA['livestock']['lon'], 'down'), 'POST');
    $elapsed = microtime(true) - $startedAt;
    check($status === 201 && ($data['success'] ?? false) && $elapsed < 20, sprintf('report submitted while the weather API is down is saved (HTTP %d) in %.1f s', $status, $elapsed));
    $downReport = $reportRow($data['data']['id']);
    check($downReport !== [], 'the report row exists in the database');
    $downWeather = $connection->query('SELECT * FROM weather_data WHERE report_id = ' . (int) $downReport['id'])->fetchAll();
    check(count($downWeather) === 1 && $downWeather[0]['snapshot_status'] === 'Unavailable' && $downWeather[0]['unavailable_code'] === 'provider_timeout' && $downWeather[0]['unavailable_reason'] !== '', 'an "Unavailable" snapshot with the reason (provider_timeout) was stored');
    check($downWeather[0]['temperature'] === null && $downWeather[0]['humidity'] === null && $downWeather[0]['rainfall'] === null && $downWeather[0]['wind_information'] === null && $downWeather[0]['weather_condition'] === null && $downWeather[0]['rule_result_json'] === null, 'no placeholder weather values were generated');
    $downAdmin = damage_report_fetch($connection, damage_report_filters_from_input([]), (int) $downReport['id'], true)[0];
    $downHtml = damage_report_pdf_weather_html($downAdmin['weather']);
    check($downAdmin['weather']['snapshot_status'] === 'Unavailable' && str_contains($downHtml, 'Weather unavailable') && str_contains($downHtml, 'did not respond in time') && !str_contains($downHtml, 'Heavy rain'), 'admin data and PDF show "Weather unavailable" with the reason and no rule result');
    sleep(1);

    // Retry with the same submission id: idempotent, no second snapshot, no duplicate notification.
    scenario(['mode' => 'ok'] + $conditions['no_threshold_exceeded']); clear_cache();
    $notificationsBeforeRetry = $notificationCount($created['a']['id']);
    [$status] = api('/reports/index.php', $tokenA, $reportBody($farmsA['livestock'], $submissionDown, (float) $farmsA['livestock']['lat'], (float) $farmsA['livestock']['lon'], 'retry'), 'POST');
    $weatherCount = (int) $connection->query('SELECT COUNT(*) c FROM weather_data WHERE report_id = ' . (int) $downReport['id'])->fetch()['c'];
    check($status === 200 && $weatherCount === 1 && $notificationCount($created['a']['id']) === $notificationsBeforeRetry, 'a retried submission is idempotent: still one snapshot and no duplicate notification');

    // Out-of-city damage points are still refused and produce no report or weather row.
    $invalid = $reportBody($farmsA['crops'], 'weather_out_' . $stamp, OUTSIDE_LAT, OUTSIDE_LON, 'out');
    $callsBefore = provider_calls();
    [$status] = api('/reports/index.php', $tokenA, $invalid, 'POST');
    check($status === 422 && provider_calls() === $callsBefore, 'an out-of-city damage point is still rejected (422) and no weather is requested');

    // ---------- Non-interference ----------
    check($notificationCount($created['a']['id']) === $notificationsBeforeAdviceCalls + 2, 'only the two expected "report received" notifications were created (advice calls create none)');
    check($fingerprint('weather_advisory_recipients') === $recipientsBefore, 'weather advisory recipients are unchanged by advice requests and report weather snapshots');
    [$status, $data] = api('/weather/advisories.php', $tokenA);
    check($status === 200 && isset($data['data']['eligibility']) && array_key_exists('items', $data['data']), 'the existing admin-advisory endpoint still works unchanged');
    [$status, $data] = api('/weather/current.php', $tokenA);
    check(in_array($status, [200, 503], true), "the existing Today's Weather endpoint still responds (HTTP {$status})");

    echo "\nALL {$passed} CHECKS PASSED\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . PHP_EOL);
    $exitCode = 1;
} finally {
    foreach ([$appServer, $mockServer] as $process) {
        if (is_resource($process)) { proc_terminate($process); proc_close($process); }
    }
    foreach ($created as $farmer) {
        $statement = $connection->prepare('SELECT ri.image_path FROM report_images ri JOIN damage_reports dr ON dr.id = ri.report_id WHERE dr.farmer_id = ?');
        $statement->execute([$farmer['id']]);
        foreach ($statement->fetchAll() as $row) {
            $root = realpath(dirname(__DIR__, 2) . '/storage/report-images');
            $file = realpath(dirname(__DIR__, 2) . '/storage/report-images/' . basename($row['image_path']));
            if ($root && $file && str_starts_with($file, $root . DIRECTORY_SEPARATOR) && preg_match('/^[a-f0-9]{40}\.(?:png|jpg)$/D', basename($file))) unlink($file);
        }
        $statement = $connection->prepare('DELETE FROM farmers WHERE id = ? AND email = ?');
        $statement->execute([$farmer['id'], $farmer['email']]);
    }
    if (is_file($temporaryImage)) unlink($temporaryImage);
    foreach (glob($workDir . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . '*') ?: [] as $file) @unlink($file);
    foreach ([$scenarioFile, $logFile] as $file) if (is_file($file)) @unlink($file);
    @rmdir($workDir . DIRECTORY_SEPARATOR . 'cache'); @rmdir($workDir);
    if (isset($baseline) && is_array($baseline)) {
        $same = true;
        foreach (['weather_data', 'damage_reports', 'weather_advisory_recipients', 'weather_advisories'] as $table) {
            $now = hash('sha256', json_encode($connection->query("SELECT * FROM {$table} ORDER BY 1")->fetchAll()));
            if ($now !== $baseline[$table]) { $same = false; fwrite(STDERR, "WARNING: {$table} differs from its pre-test state.\n"); $exitCode = 1; }
        }
        echo $same ? "Cleanup verified: pre-existing weather_data, damage_reports, advisories and advisory recipients are byte-for-byte unchanged.\n" : '';
    }
}
exit($exitCode);
