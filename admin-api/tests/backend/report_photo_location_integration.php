<?php
declare(strict_types=1);

require_once __DIR__ . '/../support/test_base_url.php';

// Explicitly local-only. Creates only uniquely named .test farmers; finally removes their own rows/files.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__, 2) . '/backend/config/database.php';
require_once dirname(__DIR__, 2) . '/backend/lib/report_photo_location.php';
require_once dirname(__DIR__, 2) . '/backend/lib/damage_reports.php';

function photo_test(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function photo_test_api_base(): string {
    $base = getenv('AGRIREACH_TEST_API_BASE') ?: agrireach_test_base_url() . '/CAPSTONE%20AGRICULTURE/api';
    $host = parse_url($base, PHP_URL_HOST);
    if (!in_array($host, ['127.0.0.1', 'localhost'], true)) throw new RuntimeException('Integration tests require a loopback API URL.');
    return rtrim($base, '/');
}
function photo_request(string $method, string $route, ?string $token, array|string|null $body = null): array {
    $handle = curl_init(photo_test_api_base() . $route);
    $headers = ['Accept: application/json'];
    if ($token !== null) $headers[] = 'Authorization: Bearer ' . $token;
    if (is_string($body)) $headers[] = 'Content-Type: application/json';
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 25]);
    if ($body !== null) curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
    $raw = curl_exec($handle);
    if ($raw === false) throw new RuntimeException('Local test HTTP connection failed (no credentials logged).');
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);
    return [$status, json_decode($raw, true), $raw];
}

function photo_parallel_upload(string $token, array $body): array {
    $multi = curl_multi_init();
    $handles = [];
    try {
        for ($i = 0; $i < 2; $i++) {
            $handle = curl_init(photo_test_api_base() . '/reports/index.php');
            curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Accept: application/json'], CURLOPT_TIMEOUT => 25]);
            curl_multi_add_handle($multi, $handle); $handles[] = $handle;
        }
        do { curl_multi_exec($multi, $running); if ($running) curl_multi_select($multi, 0.1); } while ($running);
        return array_map(static fn($handle) => [(int) curl_getinfo($handle, CURLINFO_HTTP_CODE), json_decode(curl_multi_getcontent($handle), true)], $handles);
    } finally {
        foreach ($handles as $handle) { curl_multi_remove_handle($multi, $handle); curl_close($handle); }
        curl_multi_close($multi);
    }
}

$connection = db();
// Read-only fingerprints: prove this run leaves all pre-existing report/image rows intact.
$beforeReports = hash('sha256', json_encode($connection->query('SELECT * FROM damage_reports ORDER BY id')->fetchAll()));
$beforeImages = hash('sha256', json_encode($connection->query('SELECT * FROM report_images ORDER BY id')->fetchAll()));
$created = [];
$temporaryImage = tempnam(sys_get_temp_dir(), 'agrireach-photo-test-');
$temporaryBad = tempnam(sys_get_temp_dir(), 'agrireach-upload-test-');
$stamp = bin2hex(random_bytes(8));
try {
    // Tiny synthetic PNG, no real farmer images and no optional GD dependency.
    file_put_contents($temporaryImage, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a3ioAAAAASUVORK5CYII='));
    file_put_contents($temporaryBad, 'This is not a photo.');
    foreach (['a', 'b'] as $name) {
        $email = 'photo-location-' . $stamp . '-' . $name . '@example.test';
        $contact = '+639' . str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
        $statement = $connection->prepare("INSERT INTO farmers (full_name, contact_number, email, address, account_status, verification_status) VALUES ('Controlled Photo Location Test', ?, ?, 'Controlled test address', 'Active', 'Approved')");
        $statement->execute([$contact, $email]);
        $farmerId = (int) $connection->lastInsertId();
        $created[$name] = ['id' => $farmerId, 'email' => $email];
        $statement = $connection->prepare("INSERT INTO farm_profiles (farmer_id, farm_name, farm_type, agricultural_category, farm_address, latitude, longitude) VALUES (?, 'Controlled farm', 'Crops', 'Crops', 'Controlled Cabadbaran test', 9.12, 125.53)");
        $statement->execute([$farmerId]);
        $created[$name]['farm'] = (int) $connection->lastInsertId();
        $token = bin2hex(random_bytes(32)); $hash = hash('sha256', $token);
        $statement = $connection->prepare('INSERT INTO farmer_sessions (farmer_id, token_hash, expires_at) VALUES (?, ?, ' . db_sql_utc_now($connection) . " + INTERVAL '1' HOUR)");
        $statement->execute([$farmerId, $hash]);
        $created[$name]['token'] = $token;
    }
    $token = $created['a']['token'];
    photo_test(photo_request('POST', '/location/validate.php', null, json_encode(['context' => 'damage', 'latitude' => 9.1172482, 'longitude' => 125.5296631]))[0] === 401, 'Damage point lookup must require authentication.');
    [$status, $data] = photo_request('POST', '/location/validate.php', $token, json_encode(['context' => 'damage', 'latitude' => 9.1172482, 'longitude' => 125.5296631]));
    photo_test($status === 200 && $data['data']['inside'] === true, 'Known local Cabadbaran point did not pass.');
    [$status, $data] = photo_request('POST', '/location/validate.php', $token, json_encode(['context' => 'damage', 'latitude' => 14.5995, 'longitude' => 120.9842]));
    photo_test($status === 422 && $data['code'] === 'PIN_OUTSIDE_CABADBARAN', 'Outside point was accepted.');
    $body = [
        'farm_profile_id' => (string) $created['a']['farm'], 'farm_type' => 'Crops', 'damage_type' => 'Flood',
        'damage_description' => 'Controlled six-photo metadata test, not a real damage report.', 'estimated_damage_cost' => '1.25',
        'submitted_address' => 'Controlled Cabadbaran test location', 'submitted_latitude' => '9.1172482', 'submitted_longitude' => '125.5296631',
        'client_submission_id' => 'photo_test_' . $stamp, 'location_confirmed' => '1', 'location_source' => 'photo', 'location_photo_order' => '1',
    ];
    $now = gmdate('Y-m-d\TH:i:s\Z');
    foreach (['photo_exif', 'asset_location', 'none', 'device_capture', 'photo_exif', 'none'] as $index => $source) {
        $body['photos[' . $index . ']'] = new CURLFile($temporaryImage, 'image/png', 'controlled-' . $index . '.png');
        $body['photo_latitude[' . $index . ']'] = $source === 'none' ? '' : ($index === 4 ? '9.13' : '9.1172482');
        $body['photo_longitude[' . $index . ']'] = $source === 'none' ? '' : '125.5296631';
        $body['photo_gps_source[' . $index . ']'] = $source;
        $body['photo_gps_accuracy[' . $index . ']'] = $source === 'device_capture' ? '8.25' : '';
        $body['photo_captured_at[' . $index . ']'] = $source === 'device_capture' ? $now : '';
        $body['photo_capture_time_source[' . $index . ']'] = $source === 'device_capture' ? 'camera_return' : 'none';
        $body['photo_location_timestamp[' . $index . ']'] = $source === 'device_capture' ? $now : '';
        $body['photo_location_status[' . $index . ']'] = $source === 'none' ? 'no_gps' : 'available';
    }
    photo_test(photo_request('POST', '/reports/index.php', null, $body)[0] === 401, 'Unauthenticated upload accepted.');
    $invalid = $body; $invalid['location_confirmed'] = '0';
    photo_test(photo_request('POST', '/reports/index.php', $token, $invalid)[0] === 422, 'Unconfirmed point accepted.');
    $invalid = $body; $invalid['farm_profile_id'] = (string) $created['b']['farm'];
    photo_test(photo_request('POST', '/reports/index.php', $token, $invalid)[0] === 422, 'Another farmer farm accepted.');
    $invalid = $body; $invalid['photo_latitude[0]'] = '95';
    photo_test(photo_request('POST', '/reports/index.php', $token, $invalid)[0] === 422, 'Invalid photo GPS accepted.');
    $invalid = $body; $invalid['photo_latitude[0]'] = '0'; $invalid['photo_longitude[0]'] = '0';
    photo_test(photo_request('POST', '/reports/index.php', $token, $invalid)[0] === 422, 'False 0,0 photo GPS accepted.');
    $invalid = $body; $invalid['photo_latitude[2]'] = '0'; $invalid['photo_longitude[2]'] = '0';
    photo_test(photo_request('POST', '/reports/index.php', $token, $invalid)[0] === 422, 'Unlocated photo carrying 0,0 accepted.');
    $invalid = $body; $invalid['submitted_latitude'] = '0'; $invalid['submitted_longitude'] = '0';
    photo_test(photo_request('POST', '/reports/index.php', $token, $invalid)[0] === 422, 'False 0,0 report pin accepted.');
    $invalid = $body; $invalid['photo_location_timestamp[3]'] = gmdate('Y-m-d\TH:i:s\Z', time() - 120);
    photo_test(photo_request('POST', '/reports/index.php', $token, $invalid)[0] === 422, 'Stale camera pairing accepted.');
    $invalid = $body; $invalid['photo_gps_accuracy[3]'] = '501';
    photo_test(photo_request('POST', '/reports/index.php', $token, $invalid)[0] === 422, 'Poor camera accuracy marked available.');
    $invalid = $body; $invalid['location_photo_order'] = '5';
    photo_test(photo_request('POST', '/reports/index.php', $token, $invalid)[0] === 422, 'Main pin did not match selected photo.');
    $invalid = $body; $invalid['location_source'] = 'manual'; $invalid['location_photo_order'] = ''; $invalid['submitted_latitude'] = '14.5995'; $invalid['submitted_longitude'] = '120.9842';
    photo_test(photo_request('POST', '/reports/index.php', $token, $invalid)[0] === 422, 'Out-of-city report accepted.');
    $invalid = $body; $invalid['submitted_latitude'] = $invalid['photo_latitude[0]'] = '14.5995'; $invalid['submitted_longitude'] = $invalid['photo_longitude[0]'] = '120.9842';
    [$status, $outsidePhoto] = photo_request('POST', '/reports/index.php', $token, $invalid);
    photo_test($status === 422 && $outsidePhoto['code'] === 'PIN_OUTSIDE_CABADBARAN', 'Out-of-city photo GPS was relabeled or accepted.');
    $invalid = $body; $invalid['photos[0]'] = new CURLFile($temporaryBad, 'image/png', 'not-image.png');
    photo_test(photo_request('POST', '/reports/index.php', $token, $invalid)[0] === 422, 'Non-image content accepted.');
    $largeFixture = fopen($temporaryBad, 'c+'); ftruncate($largeFixture, 5 * 1024 * 1024 + 1); fclose($largeFixture);
    photo_test(photo_request('POST', '/reports/index.php', $token, $invalid)[0] === 413, 'Oversized image did not return HTTP 413.');
    $invalid = $body;
    for ($index = 6; $index < 11; $index++) $invalid['photos[' . $index . ']'] = new CURLFile($temporaryImage, 'image/png', 'extra.png');
    photo_test(photo_request('POST', '/reports/index.php', $token, $invalid)[0] === 422, 'Eleven photos accepted.');

    [$status, $data] = photo_request('POST', '/reports/index.php', $token, $body);
    photo_test($status === 201 && ($data['success'] ?? false), 'Six-photo upload failed; HTTP ' . $status . '.');
    $code = $data['data']['id'];
    [$status, $detail] = photo_request('GET', '/reports/index.php?id=' . rawurlencode($code), $token);
    photo_test($status === 200 && count($detail['data']['photos']) === 6, 'Saved photos missing.');
    $photos = $detail['data']['photos'];
    photo_test($photos[0]['locationSource'] === 'photo_exif' && $photos[1]['locationSource'] === 'asset_location', 'Photo order/source lost.');
    photo_test($photos[2]['latitude'] === null && $photos[2]['longitude'] === null && $photos[2]['locationSource'] === 'none', 'Missing metadata invented.');
    photo_test($photos[3]['accuracy'] === 8.25 && $photos[3]['captureTimeSource'] === 'camera_return' && $photos[3]['locationTimestamp'] !== null, 'Camera provenance lost.');
    photo_test($photos[4]['latitude'] === 9.13 && $detail['data']['latitude'] === 9.1172482, 'Conflicting photo GPS overwrote main pin.');
    photo_test(photo_request('GET', $photos[0]['uri'], $token)[0] === 200, 'Owner protected image unavailable.');
    photo_test(photo_request('GET', $photos[0]['uri'], $created['b']['token'])[0] === 404, 'Other farmer can access photo.');
    photo_test(photo_request('GET', '/reports/index.php?id=' . rawurlencode($code), $created['b']['token'])[0] === 404, 'Other farmer can access report.');
    photo_test(photo_request('GET', $photos[0]['uri'], null)[0] === 401, 'Anonymous photo access accepted.');
    [$status, $retry] = photo_request('POST', '/reports/index.php', $token, ['client_submission_id' => $body['client_submission_id']]);
    photo_test($status === 200 && $retry['data']['id'] === $code, 'Uncertain-response retry did not return same report.');
    $statement = $connection->prepare('SELECT id FROM damage_reports WHERE farmer_id = ?');
    $statement->execute([$created['a']['id']]);
    $rows = $statement->fetchAll();
    photo_test(count($rows) === 1, 'Invalid attempts/retry inserted another report.');
    $reportId = (int) $rows[0]['id'];
    $admin = damage_report_fetch($connection, damage_report_filters_from_input([]), $reportId, true)[0];
    photo_test(count($admin['images']) === 6 && $admin['images'][3]['gps_source'] === 'device_capture', 'Admin related-image fields inconsistent.');
    photo_test($admin['location_photo_order'] == 1 && $admin['gps_validation_status'] === 'Not checked', 'Farm-boundary status invented.');
    $count = $connection->query('SELECT COUNT(*) AS total FROM notifications WHERE related_report_id = ' . $reportId)->fetch();
    photo_test((int) $count['total'] === 1, 'Retry created duplicate notification.');

    $manual = $body;
    $manual['client_submission_id'] = 'photo_manual_' . $stamp;
    $manual['location_source'] = 'manual'; $manual['location_photo_order'] = '';
    for ($index = 0; $index < 6; $index++) {
        foreach (['photo_latitude', 'photo_longitude', 'photo_gps_accuracy', 'photo_location_timestamp', 'photo_captured_at'] as $key) $manual[$key . '[' . $index . ']'] = '';
        $manual['photo_gps_source[' . $index . ']'] = 'none';
        $manual['photo_capture_time_source[' . $index . ']'] = 'none';
        $manual['photo_location_status[' . $index . ']'] = ['no_gps', 'permission_denied', 'timeout', 'stale_fix', 'services_disabled', 'unavailable'][$index];
    }
    [$status, $manualResult] = photo_request('POST', '/reports/index.php', $token, $manual);
    photo_test($status === 201, 'Explicit manual location with GPS-unavailable photos failed.');
    [, $manualDetail] = photo_request('GET', '/reports/index.php?id=' . rawurlencode($manualResult['data']['id']), $token);
    photo_test($manualDetail['data']['locationPhotoOrder'] === null && $manualDetail['data']['photos'][1]['locationStatus'] === 'permission_denied', 'Manual point or failure reasons were lost.');
    photo_test($manualDetail['data']['photos'][1]['latitude'] === null, 'Denied camera GPS was invented.');

    $ten = $body; $ten['client_submission_id'] = 'photo_ten_' . $stamp;
    for ($index = 6; $index < 10; $index++) $ten['photos[' . $index . ']'] = new CURLFile($temporaryImage, 'image/png', 'extra.png');
    [$status, $tenResult] = photo_request('POST', '/reports/index.php', $token, $ten);
    photo_test($status === 201, 'Ten-photo upload failed.');
    [, $tenDetail] = photo_request('GET', '/reports/index.php?id=' . rawurlencode($tenResult['data']['id']), $token);
    photo_test(count($tenDetail['data']['photos']) === 10 && $tenDetail['data']['photos'][9]['latitude'] === null, 'Ten-photo order or missing metadata failed.');

    $parallel = $body; $parallel['client_submission_id'] = 'photo_parallel_' . $stamp;
    $results = photo_parallel_upload($token, $parallel);
    $statuses = array_column($results, 0); sort($statuses);
    photo_test($statuses === [200, 201] && $results[0][1]['data']['id'] === $results[1][1]['data']['id'], 'Concurrent duplicate uploads did not converge on one report.');
    $parallelCode = $results[0][1]['data']['id'];
    $statement = $connection->prepare('SELECT (SELECT COUNT(*) FROM report_images ri WHERE ri.report_id = dr.id) AS photos, (SELECT COUNT(*) FROM notifications n WHERE n.related_report_id = dr.id) AS notifications FROM damage_reports dr WHERE report_code = ?');
    $statement->execute([$parallelCode]); $totals = $statement->fetch();
    photo_test((int) $totals['photos'] === 6 && (int) $totals['notifications'] === 1, 'Concurrent retry duplicated images or notification.');

    $legacyCode = 'AR-LEGACY-TEST-' . $stamp;
    $statement = $connection->prepare("INSERT INTO damage_reports (report_code, farmer_id, agricultural_category, damage_type, damage_description, status) VALUES (?, ?, 'Crops', 'Flood', 'Controlled legacy compatibility test', 'Received')");
    $statement->execute([$legacyCode, $created['a']['id']]);
    [$status, $legacy] = photo_request('GET', '/reports/index.php?id=' . $legacyCode, $token);
    photo_test($status === 200 && $legacy['data']['latitude'] === null && $legacy['data']['locationSource'] === null && $legacy['data']['photos'] === [], 'Legacy missing-data report no longer readable.');
    echo "PASS: authenticated six-photo multipart upload; original/asset/camera/missing/conflicting metadata; boundary/range/freshness/accuracy/type/count validation; owner image access; cross-account denial; main-photo consistency; idempotent retry; one notification; admin data.\n";
    echo "PASS: ten-photo limit, HTTP 413 oversized rejection, confirmed manual pin with all six GPS-unavailable states; concurrent uploads produce one report and notification; legacy null-location report.\n";
} finally {
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
    if (is_file($temporaryBad)) unlink($temporaryBad);
    photo_test(hash('sha256', json_encode($connection->query('SELECT * FROM damage_reports ORDER BY id')->fetchAll())) === $beforeReports, 'Pre-existing reports changed.');
    photo_test(hash('sha256', json_encode($connection->query('SELECT * FROM report_images ORDER BY id')->fetchAll())) === $beforeImages, 'Pre-existing photo records changed.');
    echo "Removed only this run's controlled farmers, dependent records, generated uploads and temporary fixtures.\n";
}
