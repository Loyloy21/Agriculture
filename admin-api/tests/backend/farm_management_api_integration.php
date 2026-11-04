<?php
declare(strict_types=1);

require_once __DIR__ . '/../support/test_base_url.php';

require_once __DIR__ . '/../../backend/config/database.php';
require_once __DIR__ . '/../../backend/lib/weather_advisories.php';
require_once __DIR__ . '/../../backend/lib/private_uploads.php';

function farm_test_expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function farm_test_request(string $method, string $path, string $token, ?array $json = null, ?array $multipart = null): array
{
    $curl = curl_init(agrireach_test_base_url() . '/CAPSTONE%20AGRICULTURE/api' . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => ["Authorization: Bearer {$token}", 'Accept: application/json']]);
    if ($json !== null) {
        curl_setopt($curl, CURLOPT_HTTPHEADER, ["Authorization: Bearer {$token}", 'Accept: application/json', 'Content-Type: application/json']);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($json, JSON_THROW_ON_ERROR));
    } elseif ($multipart !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, $multipart);
    $body = curl_exec($curl); $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE); $error = curl_error($curl); curl_close($curl);
    if (!is_string($body)) throw new RuntimeException('Local API request failed: ' . $error);
    $payload = json_decode($body, true);
    if (!is_array($payload)) throw new RuntimeException("Local API returned invalid JSON (HTTP {$status}).");
    return ['status' => $status, 'payload' => $payload];
}

$connection = db();
$farmerIds = []; $temporaryImage = tempnam(sys_get_temp_dir(), 'agrireach-farm-test-'); $storedReportImages = [];
try {
    file_put_contents($temporaryImage, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true));
    $suffix = bin2hex(random_bytes(4));
    $farmerInsert = $connection->prepare("INSERT INTO farmers (full_name, contact_number, email, address, account_status, verification_status) VALUES (?, ?, ?, 'Controlled farm test address', 'Active', 'Approved')");
    $farmInsert = $connection->prepare("INSERT INTO farm_profiles (farmer_id, farm_name, farm_type, agricultural_category, land_area, farm_address, city_psgc_code, city_name, province_name, barangay_psgc_code, barangay_name, location_review_status, status, is_primary) VALUES (?, ?, 'Crops', 'Crops', 2.5, 'Tolosa landmark', '1600203000', 'Cabadbaran City', 'Agusan del Norte', '1600203040', 'Tolosa', 'Verified', 'Active', 1)");
    $sessionInsert = $connection->prepare("INSERT INTO farmer_sessions (farmer_id, token_hash, expires_at) VALUES (?, ?, " . db_sql_utc_now($connection) . " + INTERVAL '1' HOUR)");
    $tokens = [];
    foreach (['A', 'B'] as $index => $label) {
        $name = "Controlled Farm Farmer {$label}"; $phone = '0995' . str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT); $email = "farm-test-{$label}-{$suffix}@example.test";
        $farmerInsert->execute([$name, $phone, $email]); $farmerId = (int) $connection->lastInsertId(); $farmerIds[] = $farmerId;
        if ($index === 0) { $farmName = "Registration Farm {$label}"; $farmInsert->execute([$farmerId, $farmName]); }
        $tokens[$index] = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='); $hash = hash('sha256', $tokens[$index]); $sessionInsert->execute([$farmerId, $hash]);
    }

    $initial = farm_test_request('GET', '/farms/index.php', $tokens[0]);
    farm_test_expect($initial['status'] === 200 && count($initial['payload']['data']['farms'] ?? []) === 1, 'Registration farm was not returned exactly once.');
    $registrationFarmId = (int) $initial['payload']['data']['farms'][0]['id'];

    $emptyB = farm_test_request('GET', '/farms/index.php', $tokens[1]);
    farm_test_expect(count($emptyB['payload']['data']['farms'] ?? []) === 0, 'A farmer without farms did not receive an empty list.');
    $firstFarmB = farm_test_request('POST', '/farms/index.php', $tokens[1], [
        'action' => 'create', 'name' => 'Controlled First Fishpond', 'type' => 'Fishpond', 'agriculturalCategory' => 'Fishpond',
        'barangayCode' => '1600203040', 'address' => 'Tolosa fishpond landmark', 'landArea' => 1.75, 'landAreaUnit' => 'hectares',
        'mainProduct' => 'Tilapia', 'latitude' => null, 'longitude' => null,
    ]);
    farm_test_expect($firstFarmB['status'] === 201 && ($firstFarmB['payload']['data']['isPrimary'] ?? false) === true, 'Farmer B could not add a first farm.');
    $revokeB = $connection->prepare('UPDATE farmer_sessions SET revoked_at = ' . db_sql_utc_now($connection) . ' WHERE farmer_id = ?'); $revokeB->execute([$farmerIds[1]]);
    $tokens[1] = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='); $newHashB = hash('sha256', $tokens[1]); $sessionInsert->execute([$farmerIds[1], $newHashB]);
    $restoredB = farm_test_request('GET', '/farms/index.php', $tokens[1]);
    farm_test_expect(count($restoredB['payload']['data']['farms'] ?? []) === 1, 'Farmer B first farm did not persist into a fresh session.');

    $newFarm = farm_test_request('POST', '/farms/index.php', $tokens[0], [
        'action' => 'create', 'name' => 'Controlled Livestock Farm', 'type' => 'Livestock', 'agriculturalCategory' => 'Livestock',
        'barangayCode' => '1600203019', 'address' => 'Mabini livestock landmark', 'landArea' => 4.25, 'landAreaUnit' => 'hectares',
        'mainProduct' => 'Cattle', 'latitude' => 9.12, 'longitude' => 125.53,
    ]);
    farm_test_expect($newFarm['status'] === 201, 'Second farm was not created.');
    $secondFarmId = (int) ($newFarm['payload']['data']['id'] ?? 0);
    farm_test_expect($secondFarmId > 0, 'Second farm ID is missing.');

    $list = farm_test_request('GET', '/farms/index.php', $tokens[0]);
    farm_test_expect(count($list['payload']['data']['farms'] ?? []) === 2, 'Farmer A does not see both farms.');
    $forbidden = farm_test_request('GET', '/farms/index.php?id=' . $secondFarmId, $tokens[1]);
    farm_test_expect($forbidden['status'] === 404, 'Farmer B could access Farmer A farm.');

    $updated = farm_test_request('POST', '/farms/index.php', $tokens[0], [
        'action' => 'update', 'farmId' => $secondFarmId, 'name' => 'Controlled Livestock Farm Updated', 'type' => 'Livestock', 'agriculturalCategory' => 'Livestock',
        'barangayCode' => '1600203019', 'address' => 'Updated Mabini landmark', 'landArea' => 4.5, 'landAreaUnit' => 'hectares',
        'mainProduct' => 'Cattle', 'latitude' => 9.12, 'longitude' => 125.53,
    ]);
    farm_test_expect($updated['status'] === 200 && ($updated['payload']['data']['name'] ?? '') === 'Controlled Livestock Farm Updated', 'Second farm update failed.');

    $recipientIds = weather_advisory_recipient_ids($connection, 'Crops', 'City', []);
    farm_test_expect(count(array_keys($recipientIds, $farmerIds[0], true)) === 1, 'Multiple farms caused duplicate advisory recipients.');

    $photoFixtures = [
        dirname(__DIR__, 2) . '/frontend/mobile/assets/images/icon.png',
        dirname(__DIR__, 2) . '/frontend/mobile/assets/images/splash-icon.png',
        dirname(__DIR__, 2) . '/frontend/mobile/assets/images/react-logo.png',
        dirname(__DIR__, 2) . '/frontend/mobile/assets/images/expo-logo.png',
        dirname(__DIR__, 2) . '/frontend/mobile/assets/images/favicon.png',
        dirname(__DIR__, 2) . '/frontend/mobile/assets/images/android-icon-foreground.png',
    ];
    foreach ($photoFixtures as $fixture) farm_test_expect(is_file($fixture) && getimagesize($fixture) !== false, 'A controlled damage photo fixture is unavailable.');
    $reportFields = [
        'farm_profile_id' => (string) $secondFarmId, 'farm_type' => 'Livestock', 'damage_type' => 'Flood', 'custom_damage_type' => '',
        'damage_description' => 'Controlled integration report.', 'estimated_damage_cost' => '1000', 'submitted_address' => 'Mabini controlled damage point',
        'submitted_latitude' => '9.1201', 'submitted_longitude' => '125.5301', 'client_submission_id' => 'farmtest_' . bin2hex(random_bytes(8)),
        'location_confirmed' => '1', 'location_source' => 'manual',
    ];
    $reportBase = $reportFields;
    foreach ($photoFixtures as $index => $fixture) {
        $reportFields['photos[' . $index . ']'] = new CURLFile($fixture, 'image/png', 'controlled-' . ($index + 1) . '.png');
        $reportFields['photo_captured_at[' . $index . ']'] = '';
        $reportFields['photo_latitude[' . $index . ']'] = '';
        $reportFields['photo_longitude[' . $index . ']'] = '';
    }
    $report = farm_test_request('POST', '/reports/index.php', $tokens[0], null, $reportFields);
    farm_test_expect($report['status'] === 201, 'Damage report using selected second farm failed: ' . ($report['payload']['message'] ?? 'unknown error'));
    $reportCode = (string) ($report['payload']['data']['id'] ?? '');
    $check = $connection->prepare('SELECT farm_profile_id FROM damage_reports WHERE report_code = ? AND farmer_id = ?'); $check->execute([$reportCode, $farmerIds[0]]);
    farm_test_expect((int) ($check->fetch()['farm_profile_id'] ?? 0) === $secondFarmId, 'Report was not linked to the selected farm.');
    $images = $connection->prepare('SELECT ri.image_path, ri.display_order FROM report_images ri JOIN damage_reports dr ON dr.id = ri.report_id WHERE dr.report_code = ? ORDER BY ri.display_order, ri.id');
    $images->execute([$reportCode]); $imageRows = $images->fetchAll();
    farm_test_expect(count($imageRows) === 6, 'Damage report did not retain all six controlled photos.');
    farm_test_expect(array_map('intval', array_column($imageRows, 'display_order')) === [1, 2, 3, 4, 5, 6], 'Damage photo order was not preserved.');
    foreach ($imageRows as $imageRow) $storedReportImages[] = basename((string) $imageRow['image_path']);
    $detail = farm_test_request('GET', '/reports/index.php?id=' . rawurlencode($reportCode), $tokens[0]);
    farm_test_expect($detail['status'] === 200 && count($detail['payload']['data']['photos'] ?? []) === 6, 'Mobile report detail did not return every saved photo.');
    farm_test_expect(($detail['payload']['data']['farmName'] ?? '') === 'Controlled Livestock Farm Updated' && (int) ($detail['payload']['data']['farmId'] ?? 0) === $secondFarmId, 'Report row did not return its real linked farm name and ID.');

    $tooManyFields = $reportBase;
    $tooManyFields['client_submission_id'] = 'farmcount_' . bin2hex(random_bytes(8));
    for ($index = 0; $index < DAMAGE_IMAGE_MAX_COUNT + 1; $index++) {
        $fixture = $photoFixtures[$index % count($photoFixtures)];
        $tooManyFields['photos[' . $index . ']'] = new CURLFile($fixture, 'image/png', 'too-many-' . ($index + 1) . '.png');
    }
    $tooMany = farm_test_request('POST', '/reports/index.php', $tokens[0], null, $tooManyFields);
    farm_test_expect($tooMany['status'] === 422 && isset($tooMany['payload']['errors']['photos']), 'The server did not reject more than ten report photos.');

    file_put_contents($temporaryImage, str_repeat("\0", DAMAGE_IMAGE_MAX_BYTES + 1));
    $oversizedFields = $reportBase;
    $oversizedFields['client_submission_id'] = 'farmsize_' . bin2hex(random_bytes(8));
    $oversizedFields['photos[0]'] = new CURLFile($temporaryImage, 'image/png', 'oversized.png');
    $oversized = farm_test_request('POST', '/reports/index.php', $tokens[0], null, $oversizedFields);
    farm_test_expect($oversized['status'] === 413 && isset($oversized['payload']['errors']['photos']), 'The server did not reject a report photo larger than 5MB.');
    $reportCount = $connection->prepare('SELECT COUNT(*) AS total FROM damage_reports WHERE farmer_id = ?');
    $reportCount->execute([$farmerIds[0]]);
    farm_test_expect((int) $reportCount->fetch()['total'] === 1, 'A rejected upload left a partial damage report record.');
    $otherReports = farm_test_request('GET', '/reports/index.php', $tokens[1]);
    farm_test_expect(count($otherReports['payload']['data'] ?? []) === 0, 'Farmer B could see Farmer A report.');

    $archived = farm_test_request('POST', '/farms/index.php', $tokens[0], ['action' => 'archive', 'farmId' => $secondFarmId]);
    farm_test_expect($archived['status'] === 200, 'Farm with report was not safely archived.');
    $preserved = $connection->prepare('SELECT fp.status, dr.farm_profile_id FROM farm_profiles fp JOIN damage_reports dr ON dr.farm_profile_id = fp.id WHERE fp.id = ?'); $preserved->execute([$secondFarmId]); $preservedRow = $preserved->fetch();
    farm_test_expect(($preservedRow['status'] ?? '') === 'Archived' && (int) ($preservedRow['farm_profile_id'] ?? 0) === $secondFarmId, 'Archiving did not preserve the report link.');
    farm_test_expect($registrationFarmId > 0, 'Registration farm identity was lost.');
    echo "PASS: registration farm, multi-farm ownership, six ordered report photos, count/size rejection, isolation, archive, and recipient de-duplication\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . PHP_EOL); $exitCode = 1;
} finally {
    if ($farmerIds) { $ids = implode(',', array_map('intval', $farmerIds)); $connection->query("DELETE FROM farmers WHERE id IN ({$ids})"); }
    foreach ($storedReportImages as $filename) {
        if (preg_match('/^[a-f0-9]{40}\.(?:png|jpg)$/', $filename)) {
            $path = dirname(__DIR__, 2) . '/storage/report-images/' . $filename;
            if (is_file($path)) @unlink($path);
        }
    }
    if (is_string($temporaryImage) && is_file($temporaryImage)) @unlink($temporaryImage);
}
exit($exitCode ?? 0);
