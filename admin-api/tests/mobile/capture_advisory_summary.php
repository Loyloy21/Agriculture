<?php
declare(strict_types=1);

/**
 * Captures REAL API responses for the advisory-summary screen capture (tests/mobile/render_advisory_summary.cjs).
 * Local-only: starts the mock weather provider and a throw-away app server on loopback, creates one disposable
 * *.test farmer with four advisories, saves the JSON responses to tests/.artifacts/advisory-summary/ and removes
 * every record it created. It never contacts the real weather provider. Use a local test database only.
 *
 * usage: php tests/mobile/capture_advisory_summary.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__, 2) . '/backend/config/database.php';
require_once dirname(__DIR__, 2) . '/backend/lib/weather_advisories.php';

const CAPTURE_MOCK_PORT = 18199;
const CAPTURE_APP_PORT = 18180;
$out = dirname(__DIR__) . '/.artifacts/advisory-summary';
if (!is_dir($out)) mkdir($out, 0700, true);
$work = sys_get_temp_dir() . '/agrireach-advisory-capture-' . bin2hex(random_bytes(4));
mkdir($work . '/cache', 0700, true);
$scenarioFile = $work . '/scenario.json';

function capture_server(array $command, array $env, int $port): mixed
{
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', sys_get_temp_dir() . '/agrireach-advisory-capture.out', 'a'], 2 => ['file', sys_get_temp_dir() . '/agrireach-advisory-capture.err', 'a']], $pipes, null, array_merge(getenv(), $env));
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
        if ($socket) { fclose($socket); return $process; }
        usleep(100000);
    }
    throw new RuntimeException('Local capture server did not start.');
}
function capture_get(string $path, string $token): array
{
    $context = stream_context_create(['http' => ['method' => 'GET', 'ignore_errors' => true, 'timeout' => 20, 'header' => "Accept: application/json\r\nAuthorization: Bearer {$token}\r\n"]]);
    $body = file_get_contents('http://127.0.0.1:' . CAPTURE_APP_PORT . '/CAPSTONE%20AGRICULTURE/api' . $path, false, $context);
    $payload = is_string($body) ? json_decode($body, true) : null;
    if (!is_array($payload) || ($payload['success'] ?? false) !== true) throw new RuntimeException("{$path} failed: " . substr((string) $body, 0, 300));
    return $payload;
}

$connection = db();
$servers = []; $farmerId = null; $advisoryIds = [];
$exitCode = 0;
try {
    $adminId = (int) $connection->query("SELECT id FROM admins WHERE status = 'active' ORDER BY id LIMIT 1")->fetchColumn();
    $pin = null;
    for ($lat = 9.10; $lat <= 9.16 && !$pin; $lat += 0.01) for ($lon = 125.52; $lon <= 125.60 && !$pin; $lon += 0.01) if (cabadbaran_boundary_check($lat, $lon)['inside'] === true) $pin = [round($lat, 4), round($lon, 4)];
    if (!$pin) throw new RuntimeException('No pin inside the Cabadbaran boundary was found.');

    file_put_contents($scenarioFile, json_encode(['mode' => 'ok']));
    $servers[] = capture_server([PHP_BINARY, '-S', '127.0.0.1:' . CAPTURE_MOCK_PORT, dirname(__DIR__) . '/support/weather_mock_provider.php'], ['AGRIREACH_MOCK_SCENARIO_FILE' => $scenarioFile, 'AGRIREACH_MOCK_LOG_FILE' => $work . '/provider.log'], CAPTURE_MOCK_PORT);
    $servers[] = capture_server([PHP_BINARY, '-S', '127.0.0.1:' . CAPTURE_APP_PORT, '-t', dirname(__DIR__, 3), dirname(__DIR__) . '/support/router.php'], ['AGRIREACH_WEATHER_BASE_URL' => 'http://127.0.0.1:' . CAPTURE_MOCK_PORT . '/v1/forecast', 'AGRIREACH_WEATHER_CACHE_DIR' => $work . '/cache'], CAPTURE_APP_PORT);

    $suffix = bin2hex(random_bytes(4));
    $connection->prepare("INSERT INTO farmers (full_name, contact_number, email, address, account_status, verification_status) VALUES ('Juan Dela Cruz (test)', ?, ?, 'Controlled test address', 'Active', 'Approved')")
        ->execute(['0995' . str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT), "advisory-capture-{$suffix}@example.test"]);
    $farmerId = db_insert_id($connection, 'farmers');
    $connection->prepare("INSERT INTO farm_profiles (farmer_id, farm_name, farm_type, agricultural_category, farm_address, city_psgc_code, city_name, province_name, barangay_psgc_code, barangay_name, location_review_status, latitude, longitude, status, is_primary) VALUES (?, 'Tolosa rice field', 'Crops', 'Crops', 'Tolosa, Cabadbaran City', ?, 'Cabadbaran City', 'Agusan del Norte', '1600203040', 'Tolosa', 'Verified', ?, ?, 'Active', 1)")
        ->execute([$farmerId, CABADBARAN_CITY_CODE, $pin[0], $pin[1]]);
    $token = bin2hex(random_bytes(32));
    $connection->prepare('INSERT INTO farmer_sessions (farmer_id, token_hash, expires_at) VALUES (?, ?, ' . db_sql_utc_now($connection) . " + INTERVAL '1' HOUR)")->execute([$farmerId, hash('sha256', $token)]);

    $today = date('Y-m-d'); $until = date('Y-m-d', strtotime('+3 days'));
    $save = static function (array $fields) use ($connection, $adminId, $today, &$advisoryIds): int {
        $saved = weather_advisory_save($connection, null, $fields + ['category' => 'Crops', 'coverage_label' => cabadbaran_coverage_label('City'), 'scope' => 'City', 'barangay_codes' => [], 'effective_date' => $today, 'status' => 'Active'], $adminId);
        return $advisoryIds[] = $saved['id'];
    };
    $long = 'DELIKADO RON ANG MGA CROPS KUSOG BAG YO UP COMING MAONG KUNG MAKAYA AYAW MO OG TANOM SA OR PAG HARVEST NA DAYON SA MGA HINOG NA. '
        . 'Siguraduha nga limpyo ang mga kanal aron dili mabahaan ang umahan. Ayaw sa pag-abono o pag-spray samtang nag-uwan kay maanod lang. '
        . 'Bantayi ang mga ubos nga dapit ug ang suba. Kung kinahanglan, ibalhin ang mga himan ug binhi sa taas nga lugar.';
    $ids = [];
    $ids['legacy'] = $save(['title' => 'Uwan advisory (older, no summary)', 'weather_condition' => 'Heavy Rain', 'message' => $long, 'key_advice' => null, 'expiration_date' => $until]);
    $ids['short'] = $save(['title' => 'Normal weather this week', 'weather_condition' => 'Normal Weather', 'message' => 'Continue regular farm activities and keep monitoring weather updates.', 'key_advice' => null, 'expiration_date' => null]);
    $ids['long'] = $save(['title' => 'Bagyo: heavy rain expected', 'weather_condition' => 'Heavy Rain', 'message' => $long, 'key_advice' => 'Harvest mature crops now and clear drainage canals. Do not apply fertilizer or spray while it rains.', 'expiration_date' => $until]);
    $ids['urgent'] = $save(['title' => 'URGENT: strong wind tonight', 'weather_condition' => 'Strong Wind', 'message' => "Strong Wind warning for all crop farms.\n- Secure greenhouse covers, nets and light structures before 5 PM.\n- Stake or support tall crops such as corn and banana.\n- Do not spray: wind carries chemicals away.\n- Stay away from power lines and tall trees during the strongest gusts.", 'key_advice' => 'Secure greenhouse covers and stake tall crops before 5 PM. Stay away from power lines during strong gusts.', 'expiration_date' => $until]);

    $clean = ['temperature' => 29.0, 'humidity' => 60, 'apparent' => 33.0, 'wind' => 8.0, 'gusts' => 15.0, 'hourly' => [0, 0, 0, 0, 0, 0]];
    $scenarios = [
        'advice-urgent' => ['mode' => 'ok', 'code' => 95, 'wind' => 45.0, 'gusts' => 60.0, 'hourly' => [0, 0, 0, 5.0, 5.0, 8.0]] + $clean,
        'advice-normal' => ['mode' => 'ok'] + $clean,
        'advice-limited' => ['mode' => 'ok', 'omit' => ['relative_humidity_2m', 'wind_speed_10m', 'wind_gusts_10m']] + $clean,
        'advice-unavailable' => ['mode' => 'http500'],
    ];
    foreach ($scenarios as $name => $scenario) {
        array_map('unlink', glob($work . '/cache/*.json') ?: []);
        file_put_contents($scenarioFile, json_encode($scenario));
        file_put_contents("{$out}/{$name}.json", json_encode(capture_get('/weather/advice.php', $token), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        // The home screen's citywide card, from the same mock reading as the urgent advice.
        if ($name === 'advice-urgent') file_put_contents("{$out}/current.json", json_encode(capture_get('/weather/current.php?refresh=1', $token), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    file_put_contents("{$out}/advisories.json", json_encode(capture_get('/weather/advisories.php', $token), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    file_put_contents("{$out}/notifications.json", json_encode(capture_get('/notifications/index.php', $token), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    foreach (['urgent', 'legacy'] as $name) file_put_contents("{$out}/advisory-{$name}.json", json_encode(capture_get('/weather/advisories.php?id=' . $ids[$name], $token), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    echo "Captured real API responses to {$out}\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . PHP_EOL);
    $exitCode = 1;
} finally {
    if ($advisoryIds) $connection->query('DELETE FROM weather_advisories WHERE id IN (' . implode(',', array_map('intval', $advisoryIds)) . ')');
    if ($farmerId) $connection->prepare('DELETE FROM farmers WHERE id = ?')->execute([$farmerId]);
    foreach ($servers as $server) { proc_terminate($server); proc_close($server); }
    array_map('unlink', glob($work . '/cache/*') ?: []); @rmdir($work . '/cache'); array_map('unlink', glob($work . '/*') ?: []); @rmdir($work);
}
exit($exitCode);
