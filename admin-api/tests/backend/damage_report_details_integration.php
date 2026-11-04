<?php
declare(strict_types=1);

require_once __DIR__ . '/../support/test_base_url.php';

// Category-specific damage details end to end over the local test server: farmer submission for all four
// categories, validation, retry/concurrency, isolation, older app builds and older reports, admin
// verification (separate from the farmer's values), protected images and PDFs.
// Explicitly local-only. Creates only uniquely named .test farmers/admin; finally removes their own rows/files.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__, 2) . '/backend/config/database.php';
require_once dirname(__DIR__, 2) . '/backend/lib/damage_reports.php';
require_once dirname(__DIR__) . '/support/admin_test_account.php';
require_once dirname(__DIR__, 2) . '/frontend/admin/includes/damage_report_pdf.php';

function details_test(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function details_number(mixed $value): ?float {
    // JSON numbers decode as int when whole (30.0 is sent as 30); compare by value.
    return $value === null ? null : (float) $value;
}
function details_test_url(string $area): string {
    $base = $area === 'api' ? (getenv('AGRIREACH_TEST_API_BASE') ?: agrireach_test_base_url() . '/CAPSTONE%20AGRICULTURE/api') : agrireach_test_base_url() . '/CAPSTONE%20AGRICULTURE/admin';
    if (!in_array(parse_url($base, PHP_URL_HOST), ['127.0.0.1', 'localhost'], true)) throw new RuntimeException('Integration tests require a loopback URL.');
    return rtrim($base, '/');
}
/** @return array{0:int,1:mixed,2:string,3:string} status, decoded JSON, raw body, content type */
function details_request(string $method, string $url, ?string $token, array|string|null $body = null, ?string $cookies = null): array {
    $handle = curl_init($url);
    $headers = ['Accept: application/json'];
    if ($token !== null) $headers[] = 'Authorization: Bearer ' . $token;
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 60, CURLOPT_FOLLOWLOCATION => false]);
    if ($cookies !== null) curl_setopt_array($handle, [CURLOPT_COOKIEFILE => $cookies, CURLOPT_COOKIEJAR => $cookies]);
    if ($body !== null) curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
    $raw = curl_exec($handle);
    if ($raw === false) throw new RuntimeException('Local test HTTP connection failed (no credentials logged).');
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $type = (string) curl_getinfo($handle, CURLINFO_CONTENT_TYPE);
    curl_close($handle);
    return [$status, json_decode((string) $raw, true), (string) $raw, $type];
}
function details_api(string $method, string $route, ?string $token, array|string|null $body = null): array {
    return details_request($method, details_test_url('api') . $route, $token, $body);
}
function details_parallel(string $token, array $body): array {
    $multi = curl_multi_init();
    $handles = [];
    try {
        for ($i = 0; $i < 2; $i++) {
            $handle = curl_init(details_test_url('api') . '/reports/index.php');
            curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Accept: application/json'], CURLOPT_TIMEOUT => 60]);
            curl_multi_add_handle($multi, $handle); $handles[] = $handle;
        }
        do { curl_multi_exec($multi, $running); if ($running) curl_multi_select($multi, 0.1); } while ($running);
        return array_map(static fn($handle) => [(int) curl_getinfo($handle, CURLINFO_HTTP_CODE), json_decode(curl_multi_getcontent($handle), true)], $handles);
    } finally {
        foreach ($handles as $handle) { curl_multi_remove_handle($multi, $handle); curl_close($handle); }
        curl_multi_close($multi);
    }
}
function details_fingerprint(PDO $connection, string $table): string {
    return hash('sha256', json_encode($connection->query('SELECT * FROM ' . $table . ' ORDER BY ' . ($table === 'damage_report_details' ? 'report_id' : 'id'))->fetchAll()));
}

$connection = db();
$before = [];
foreach (['damage_reports', 'report_images', 'damage_report_details'] as $table) $before[$table] = details_fingerprint($connection, $table);
$stamp = bin2hex(random_bytes(8));
$created = [];
$adminId = null;
$adminUsername = 'details-admin-' . $stamp;
$cookies = tempnam(sys_get_temp_dir(), 'agrireach-details-cookies-');
$image = tempnam(sys_get_temp_dir(), 'agrireach-details-photo-');
try {
    file_put_contents($image, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a3ioAAAAASUVORK5CYII='));
    foreach (['a' => ['Crops', 'Livestock', 'Poultry', 'Fishpond'], 'b' => ['Livestock']] as $name => $categories) {
        $email = 'damage-details-' . $stamp . '-' . $name . '@example.test';
        $contact = '+639' . str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
        $statement = $connection->prepare("INSERT INTO farmers (full_name, contact_number, email, address, account_status, verification_status) VALUES ('Controlled Damage Details Test', ?, ?, 'Controlled test address', 'Active', 'Approved')");
        $statement->execute([$contact, $email]);
        $farmerId = (int) $connection->lastInsertId();
        $created[$name] = ['id' => $farmerId, 'email' => $email, 'farms' => []];
        foreach ($categories as $category) {
            $statement = $connection->prepare("INSERT INTO farm_profiles (farmer_id, farm_name, farm_type, agricultural_category, farm_address, latitude, longitude) VALUES (?, ?, ?, ?, 'Controlled Cabadbaran test', 9.12, 125.53)");
            $statement->execute([$farmerId, 'Controlled ' . $category . ' farm', $category, $category]);
            $created[$name]['farms'][$category] = (int) $connection->lastInsertId();
        }
        $token = bin2hex(random_bytes(32));
        $statement = $connection->prepare('INSERT INTO farmer_sessions (farmer_id, token_hash, expires_at) VALUES (?, ?, ' . db_sql_utc_now($connection) . " + INTERVAL '1' HOUR)");
        $statement->execute([$farmerId, hash('sha256', $token)]);
        $created[$name]['token'] = $token;
    }
    $token = $created['a']['token'];
    $farms = $created['a']['farms'];
    $body = static function (string $category, string $client, array $details, ?int $farmId = null) use ($farms, $image): array {
        // $details override the defaults (for example a non-empty damage_description).
        return array_merge([
            'farm_profile_id' => (string) ($farmId ?? $farms[$category]), 'farm_type' => $category, 'damage_description' => '',
            'submitted_address' => 'Controlled Cabadbaran test location', 'submitted_latitude' => '9.1172482', 'submitted_longitude' => '125.5296631',
            'client_submission_id' => $client, 'location_confirmed' => '1', 'location_source' => 'photo', 'location_photo_order' => '1',
            'photos[0]' => new CURLFile($image, 'image/png', 'controlled.png'), 'photo_latitude[0]' => '9.1172482', 'photo_longitude[0]' => '125.5296631',
            'photo_gps_source[0]' => 'photo_exif', 'photo_gps_accuracy[0]' => '', 'photo_captured_at[0]' => '', 'photo_capture_time_source[0]' => 'none',
            'photo_location_timestamp[0]' => '', 'photo_location_status[0]' => 'available', 'damage_details_version' => '1',
        ], $details);
    };
    $crop = ['subject_type' => 'Corn', 'suspected_cause' => 'Flooding', 'crop_damage_percent' => '40', 'planted_area_ha' => '2', 'affected_area_ha' => '0.8'];
    $livestock = ['subject_type' => 'Pigs', 'suspected_cause' => 'Other', 'suspected_cause_other' => '  Contaminated   feed ', 'total_before' => '100', 'amount_lost' => '30', 'crop_damage_percent' => '40'];
    $poultry = ['subject_type' => 'Chickens', 'suspected_cause' => 'Unsure', 'total_before' => '500', 'amount_lost' => '120'];
    $fishKg = ['subject_type' => 'Tilapia', 'suspected_cause' => 'Low oxygen / fish kill', 'fish_unit' => 'kg', 'total_before' => '200', 'amount_lost' => '50'];
    $fishCount = ['subject_type' => 'Other', 'subject_type_other' => 'Pangasius', 'suspected_cause' => 'Poor water quality', 'fish_unit' => 'count', 'total_before' => '', 'amount_lost' => '50'];

    // Validation: every rejection names its field and stores nothing.
    $invalid = [
        ['Crops', ['crop_damage_percent' => '101'] + $crop, 'cropDamagePercent'],
        ['Crops', ['crop_damage_percent' => '-1'] + $crop, 'cropDamagePercent'],
        ['Crops', ['crop_damage_percent' => '40.125'] + $crop, 'cropDamagePercent'],
        ['Crops', ['crop_damage_percent' => ''] + $crop, 'cropDamagePercent'],
        ['Crops', ['planted_area_ha' => '1', 'affected_area_ha' => '2'] + $crop, 'affectedArea'],
        ['Crops', ['subject_type' => 'Pigs'] + $crop, 'subjectType'],
        ['Crops', ['suspected_cause' => 'Disease'] + $crop, 'suspectedCause'],
        ['Crops', ['suspected_cause' => ''] + $crop, 'suspectedCause'],
        ['Livestock', ['amount_lost' => '101'] + $livestock, 'amountLost'],
        ['Livestock', ['amount_lost' => '1.5'] + $livestock, 'amountLost'],
        ['Livestock', ['total_before' => '0', 'amount_lost' => '0'] + $livestock, 'totalBefore'],
        ['Livestock', ['total_before' => ''] + $livestock, 'totalBefore'],
        ['Livestock', ['total_before' => '-3'] + $livestock, 'totalBefore'],
        ['Livestock', ['suspected_cause_other' => ''] + $livestock, 'suspectedCauseOther'],
        ['Livestock', ['suspected_cause_other' => 'ab'] + $livestock, 'suspectedCauseOther'],
        ['Poultry', ['suspected_cause' => 'Pest infestation'] + $poultry, 'suspectedCause'],
        ['Poultry', ['subject_type' => 'Pigs'] + $poultry, 'subjectType'],
        ['Poultry', ['amount_lost' => '501'] + $poultry, 'amountLost'],
        ['Fishpond', ['fish_unit' => ''] + $fishKg, 'fishUnit'],
        ['Fishpond', ['fish_unit' => 'liters'] + $fishKg, 'fishUnit'],
        ['Fishpond', ['amount_lost' => '1.234'] + $fishKg, 'amountLost'],
        ['Fishpond', ['amount_lost' => '250'] + $fishKg, 'amountLost'],
        ['Fishpond', ['amount_lost' => '2.5'] + $fishCount, 'amountLost'],
        ['Fishpond', ['subject_type_other' => ''] + $fishCount, 'subjectTypeOther'],
        ['Fishpond', ['total_before' => '0'] + $fishCount, 'totalBefore'],
    ];
    foreach ($invalid as $index => [$category, $details, $field]) {
        [$status, $data] = details_api('POST', '/reports/index.php', $token, $body($category, 'details_invalid_' . $index . '_' . $stamp, $details));
        details_test($status === 422 && isset($data['errors'][$field]), "Invalid {$category} case {$index} was not rejected on {$field} (HTTP {$status}).");
    }
    [$status, $data] = details_api('POST', '/reports/index.php', $token, $body('Livestock', 'details_mismatch_' . $stamp, $livestock, $farms['Crops']));
    details_test($status === 422 && isset($data['errors']['farmType']), 'Details for another category than the farm were accepted.');
    [$status, $data] = details_api('POST', '/reports/index.php', $token, $body('Crops', 'details_long_' . $stamp, $crop + ['damage_description' => str_repeat('a', 5001)]));
    details_test($status === 422 && isset($data['errors']['description']), 'Over-long "What happened?" accepted.');
    [$status] = details_api('POST', '/reports/index.php', null, $body('Crops', 'details_anon_' . $stamp, $crop));
    details_test($status === 401, 'Unauthenticated submission accepted.');
    $count = $connection->prepare('SELECT COUNT(*) AS total FROM damage_reports WHERE farmer_id = ?');
    $count->execute([$created['a']['id']]);
    details_test((int) $count->fetch()['total'] === 0, 'Rejected submissions stored a report.');

    // Valid submissions: one per category and fish unit. No peso estimate is requested or stored.
    $cases = [
        'crop' => ['Crops', $crop, 'crop_percent', '%', 'Corn: 40% of this crop damaged · 0.8 of 2 ha affected', null, 'Flooding'],
        'livestock' => ['Livestock', $livestock, 'head_count', 'head', '30 of 100 head died · 30%', 30.0, 'Other: Contaminated feed'],
        'poultry' => ['Poultry', $poultry, 'bird_count', 'birds', '120 of 500 birds died · 24%', 24.0, 'Unsure (cause not known)'],
        'fishKg' => ['Fishpond', $fishKg, 'fish_weight_kg', 'kg', '50 of 200 kg lost · 25%', 25.0, 'Low oxygen / fish kill'],
        'fishCount' => ['Fishpond', $fishCount + ['damage_description' => 'Controlled: water turned muddy overnight.'], 'fish_count', 'fish', '50 fish lost · total stock not provided', null, 'Poor water quality'],
    ];
    $reports = [];
    foreach ($cases as $key => [$category, $details, $measure, $unit, $summary, $loss, $cause]) {
        [$status, $data] = details_api('POST', '/reports/index.php', $token, $body($category, 'details_' . $key . '_' . $stamp, $details));
        details_test($status === 201, "{$key} submission failed (HTTP {$status}).");
        $saved = $data['data'];
        details_test($saved['estimatedCost'] === null && $saved['farmType'] === $category, "{$key} stored a peso estimate or wrong category.");
        details_test($saved['damageDetails']['summary'] === $summary && $saved['damageDetails']['measureType'] === $measure && $saved['damageDetails']['unit'] === $unit, "{$key} response details wrong: " . $saved['damageDetails']['summary']);
        details_test($saved['damageDetails']['suspectedCauseLabel'] === $cause && $saved['calamityType'] === $cause, "{$key} cause label wrong.");
        details_test(details_number($saved['damageDetails']['lossPercent']) === $loss && $saved['damageDetails']['verified'] === null, "{$key} loss percentage wrong.");
        [$status, $detail] = details_api('GET', '/reports/index.php?id=' . rawurlencode($saved['id']), $token);
        details_test($status === 200 && $detail['data']['damageDetails'] === $saved['damageDetails'], "{$key} details not returned by the detail endpoint.");
        details_test(count($detail['data']['photos']) === 1 && $detail['data']['photos'][0]['latitude'] === 9.1172482, "{$key} photo/GPS evidence changed.");
        $row = $connection->prepare('SELECT dr.id, dr.estimated_damage_cost, dr.damage_type, dr.damage_description, d.* FROM damage_reports dr JOIN damage_report_details d ON d.report_id = dr.id WHERE dr.report_code = ?');
        $row->execute([$saved['id']]);
        $reports[$key] = $row->fetch() + ['code' => $saved['id']];
        details_test($reports[$key]['estimated_damage_cost'] === null && $reports[$key]['damage_type'] === $cause, "{$key} legacy columns not filled as designed.");
    }
    details_test($reports['livestock']['crop_damage_percent'] === null && $reports['livestock']['suspected_cause_other'] === 'Contaminated feed', 'Another category\'s figure was stored or the explanation was not normalized.');
    details_test($reports['fishCount']['total_before'] === null && $reports['fishCount']['subject_type'] === 'Pangasius', 'Unknown fish total was invented or the typed fish type lost.');
    details_test((float) $reports['fishKg']['amount_lost'] === 50.0 && $reports['fishKg']['measure_type'] === 'fish_weight_kg', 'Fish kilograms stored wrong.');
    details_test($reports['crop']['total_before'] === null && $reports['crop']['amount_lost'] === null && (float) $reports['crop']['crop_damage_percent'] === 40.0, 'Crop report stored counts.');

    // Farmer list, retry and concurrency, isolation, protected photo.
    [$status, $list] = details_api('GET', '/reports/index.php', $token);
    $listed = array_column($list['data'], null, 'id');
    foreach ($reports as $key => $report) details_test(($listed[$report['code']]['damageDetails']['summary'] ?? null) === $cases[$key][4], "{$key} missing from the farmer's list.");
    [$status, $retry] = details_api('POST', '/reports/index.php', $token, ['client_submission_id' => 'details_livestock_' . $stamp]);
    details_test($status === 200 && $retry['data']['id'] === $reports['livestock']['code'] && $retry['data']['damageDetails']['summary'] === '30 of 100 head died · 30%', 'Uncertain-response retry did not return the same report with its details.');
    $results = details_parallel($token, $body('Poultry', 'details_parallel_' . $stamp, $poultry));
    $statuses = array_column($results, 0); sort($statuses);
    details_test($statuses === [200, 201] && $results[0][1]['data']['id'] === $results[1][1]['data']['id'], 'Concurrent duplicates did not converge on one report.');
    details_test($results[0][1]['data']['damageDetails']['summary'] === $results[1][1]['data']['damageDetails']['summary'], 'Concurrent duplicate answered without details.');
    $totals = $connection->prepare('SELECT (SELECT COUNT(*) FROM damage_reports WHERE farmer_id = ?) AS reports, (SELECT COUNT(*) FROM damage_report_details d JOIN damage_reports dr ON dr.id = d.report_id WHERE dr.farmer_id = ?) AS details,
        (SELECT COUNT(*) FROM report_images ri JOIN damage_reports dr ON dr.id = ri.report_id WHERE dr.farmer_id = ?) AS images, (SELECT COUNT(*) FROM notifications n JOIN damage_reports dr ON dr.id = n.related_report_id WHERE dr.farmer_id = ?) AS notifications');
    $totals->execute(array_fill(0, 4, $created['a']['id']));
    $totals = $totals->fetch();
    details_test((int) $totals['reports'] === 6 && (int) $totals['details'] === 6 && (int) $totals['images'] === 6 && (int) $totals['notifications'] === 6, 'Retries duplicated reports, details, images or notifications: ' . json_encode($totals));
    $other = $created['b']['token'];
    details_test(details_api('GET', '/reports/index.php?id=' . rawurlencode($reports['livestock']['code']), $other)[0] === 404, 'Another farmer can read the report.');
    details_test(!in_array($reports['livestock']['code'], array_column(details_api('GET', '/reports/index.php', $other)[1]['data'], 'id'), true), 'Another farmer lists the report.');
    [, $retryOther] = details_api('POST', '/reports/index.php', $other, ['client_submission_id' => 'details_livestock_' . $stamp]);
    details_test(($retryOther['data']['id'] ?? null) !== $reports['livestock']['code'], 'Another farmer retrieved the report through its retry identifier.');
    [, $detail] = details_api('GET', '/reports/index.php?id=' . rawurlencode($reports['livestock']['code']), $token);
    $photoUri = $detail['data']['photos'][0]['uri'];
    details_test(details_api('GET', $photoUri, $token)[0] === 200 && details_api('GET', $photoUri, $other)[0] === 404 && details_api('GET', $photoUri, null)[0] === 401, 'Protected photo access changed.');

    // Older app builds (no details version) keep the calamity/description/peso rules; older reports stay readable.
    $legacyBody = $body('Crops', 'details_legacy_' . $stamp, []);
    unset($legacyBody['damage_details_version']);
    [$status, $data] = details_api('POST', '/reports/index.php', $token, array_merge($legacyBody, ['damage_type' => 'Flood', 'damage_description' => 'Controlled older-app report.']));
    details_test($status === 422 && isset($data['errors']['estimatedCost']), 'Older-app report without a cost was accepted.');
    [$status, $data] = details_api('POST', '/reports/index.php', $token, array_merge($legacyBody, ['damage_type' => 'Flood', 'damage_description' => 'Controlled older-app report.', 'estimated_damage_cost' => '1500.25']));
    details_test($status === 201 && details_number($data['data']['estimatedCost']) === 1500.25 && $data['data']['damageDetails'] === null && $data['data']['calamityType'] === 'Flood', 'Older-app submission changed.');
    $legacyCode = $data['data']['id'];
    $oldCode = 'AR-OLD-DETAILS-' . strtoupper(substr($stamp, 0, 10));
    $statement = $connection->prepare("INSERT INTO damage_reports (report_code, farmer_id, agricultural_category, damage_type, damage_description, estimated_damage_cost, status) VALUES (?, ?, 'Livestock', 'Flood', 'Controlled pre-migration style report', 2500.00, 'Received')");
    $statement->execute([$oldCode, $created['a']['id']]);
    $oldId = (int) $connection->lastInsertId();
    [$status, $old] = details_api('GET', '/reports/index.php?id=' . $oldCode, $token);
    details_test($status === 200 && $old['data']['damageDetails'] === null && details_number($old['data']['estimatedCost']) === 2500.0 && $old['data']['description'] === 'Controlled pre-migration style report', 'Older report no longer readable.');

    // Admin: real login, protected admin endpoints.
    $password = bin2hex(random_bytes(12)) . 'Aa1';
    $takeover = test_admin_takeover($connection, 'Controlled Details Reviewer', $adminUsername, password_hash($password, PASSWORD_DEFAULT));
    $adminId = $takeover['id'];
    $admin = details_test_url('admin');
    details_test(details_request('GET', $admin . '/damage-report-detail-data.php?index=0', null, null, $cookies)[0] === 302, 'Admin report data available without login.');
    details_request('GET', $admin . '/login.php', null, null, $cookies);
    [$status] = details_request('POST', $admin . '/login.php', null, ['username' => $adminUsername, 'password' => $password], $cookies);
    details_test($status === 302, 'Controlled admin login failed.');
    [, , $page] = details_request('GET', $admin . '/damage-reports.php?search=' . rawurlencode($reports['livestock']['code']), null, null, $cookies);
    details_test((bool) preg_match('/name="csrf_token" value="([^"]+)"/', $page, $match), 'Admin page has no CSRF token.');
    $csrf = html_entity_decode($match[1]);
    details_test(str_contains($page, 'id="verifiedFiguresSection"') && str_contains($page, 'Farmer-reported'), 'Admin page lacks the verified-figures section.');
    [$status, $data] = details_request('GET', $admin . '/damage-report-detail-data.php?index=0&search=' . rawurlencode($reports['livestock']['code']), null, null, $cookies);
    $adminReport = $data['report'];
    details_test($status === 200 && $adminReport['details']['summary'] === '30 of 100 head died · 30%' && $adminReport['details']['verified'] === null, 'Admin detail data lacks the farmer submission.');
    details_test(details_request('GET', $admin . '/report-image.php?id=' . (int) $adminReport['images'][0]['id'], null, null, $cookies)[0] === 200, 'Admin protected image unavailable.');
    details_test(details_request('GET', $admin . '/report-image.php?id=' . (int) $adminReport['images'][0]['id'], null)[0] !== 200, 'Admin image served without login.');

    $livestockId = (int) $reports['livestock']['id'];
    $verify = static fn (array $fields, ?string $csrfToken = null) => details_request('POST', $admin . '/damage-reports.php', null,
        ['action' => 'save_verified_figures', 'csrf_token' => $csrfToken ?? $csrf, 'report_id' => (string) $livestockId] + $fields, $cookies);
    $good = ['verified_cause' => 'Disease', 'verified_total_before' => '100', 'verified_amount_lost' => '28', 'review_notes' => 'Controlled site visit: two animals recovered.'];
    details_test($verify($good, 'wrong-token')[0] === 403, 'Verified figures saved without a valid CSRF token.');
    [$status] = $verify($good);
    details_test($status === 409, 'Verified figures saved before the report was Under Review.');
    [$status] = details_request('POST', $admin . '/damage-reports.php', null, ['csrf_token' => $csrf, 'report_id' => (string) $livestockId, 'new_status' => 'Under Review', 'response_message' => '', 'rejection_reason' => '', 'action' => 'update_response'], $cookies);
    details_test($status === 302, 'Existing status workflow could not move the report to Under Review.');
    [$status, $data] = $verify(['verified_amount_lost' => '101'] + $good);
    details_test($status === 422 && isset($data['errors']['verified_amount_lost']), 'Verified deaths above the verified total accepted.');
    [$status, $data] = $verify(['verified_amount_lost' => '2.5'] + $good);
    details_test($status === 422 && isset($data['errors']['verified_amount_lost']), 'Fractional verified head count accepted.');
    [$status, $data] = $verify(['verified_cause' => 'Pest infestation'] + $good);
    details_test($status === 422 && isset($data['errors']['verified_cause']), 'Verified cause from another category accepted.');
    [$status, $data] = $verify(['verified_cause' => 'Other', 'verified_cause_other' => ''] + $good);
    details_test($status === 422 && isset($data['errors']['verified_cause_other']), 'Verified Other cause without explanation accepted.');
    [$status, $data] = $verify($good);
    details_test($status === 200 && $data['changed'] === true, 'Verified figures were not saved.');
    $verified = $data['report']['details'];
    details_test($verified['verified']['summary'] === '28 of 100 head died · 28%' && details_number($verified['verified']['lossPercent']) === 28.0 && $verified['verified']['verifiedBy'] === 'Controlled Details Reviewer', 'Verified summary wrong.');
    details_test($verified['summary'] === '30 of 100 head died · 30%' && $verified['suspectedCauseLabel'] === 'Other: Contaminated feed', 'Farmer submission was altered by verification.');
    $stored = $connection->prepare('SELECT * FROM damage_report_details WHERE report_id = ?');
    $stored->execute([$livestockId]);
    $afterVerify = $stored->fetch();
    foreach (['subject_type', 'suspected_cause', 'suspected_cause_other', 'total_before', 'amount_lost', 'crop_damage_percent'] as $column) details_test($afterVerify[$column] === $reports['livestock'][$column], "Farmer column {$column} changed.");
    [$status, $data] = $verify($good);
    $stored->execute([$livestockId]);
    details_test($status === 200 && $data['changed'] === false && $stored->fetch()['verified_at'] === $afterVerify['verified_at'], 'Unchanged verification was rewritten.');
    [, $farmerView] = details_api('GET', '/reports/index.php?id=' . rawurlencode($reports['livestock']['code']), $token);
    details_test($farmerView['data']['status'] === 'Under Review' && $farmerView['data']['damageDetails']['verified'] === null, 'Farmer sees verified figures before the report is resolved.');
    details_test(damage_report_fetch($connection, damage_report_filters_from_input([]), (int) $reports['livestock']['id'], true)[0]['status'] === 'Under Review', 'Saving verified figures changed the status.');

    // Older report: nothing to verify; existing peso assessment and resolution workflow unchanged.
    $statement = $connection->prepare("UPDATE damage_reports SET status = 'Under Review' WHERE id = ? AND report_code = ?");
    $statement->execute([$oldId, $oldCode]);
    [$status] = details_request('POST', $admin . '/damage-reports.php', null, ['action' => 'save_verified_figures', 'csrf_token' => $csrf, 'report_id' => (string) $oldId] + $good, $cookies);
    details_test($status === 409, 'Older report accepted category figures.');
    [$status, $data] = details_request('POST', $admin . '/damage-reports.php', null, ['action' => 'resolve_report', 'csrf_token' => $csrf, 'report_id' => (string) $livestockId, 'final_assessed_cost' => '', 'assessment_remarks' => ''], $cookies);
    details_test($status === 422, 'Existing resolution rules changed.');
    [$status, $data] = details_request('POST', $admin . '/damage-reports.php', null, ['action' => 'resolve_report', 'csrf_token' => $csrf, 'report_id' => (string) $livestockId, 'final_assessed_cost' => '42000.00', 'assessment_remarks' => 'Controlled final findings.'], $cookies);
    details_test($status === 200 && $data['report']['status'] === 'Resolved' && $data['report']['estimated_damage_cost'] === null, 'Resolution failed or invented a farmer peso estimate.');
    [$status] = $verify(['verified_amount_lost' => '27'] + $good);
    details_test($status === 409, 'Verified figures changed after resolution.');
    [, $farmerView] = details_api('GET', '/reports/index.php?id=' . rawurlencode($reports['livestock']['code']), $token);
    details_test($farmerView['data']['damageDetails']['verified']['summary'] === '28 of 100 head died · 28%' && $farmerView['data']['damageDetails']['summary'] === '30 of 100 head died · 30%', 'Farmer does not see both submitted and verified figures after resolution.');

    // PDFs: printable sections with units and farmer-reported / admin-verified labels; nothing summed.
    $resolved = damage_report_fetch($connection, damage_report_filters_from_input([]), $livestockId, true, true)[0];
    $html = damage_report_pdf_incident_html($resolved) . damage_report_pdf_assessment_html($resolved, damage_report_pdf_is_final($resolved));
    foreach (['Incident &amp; Farmer Report', 'Admin Final Assessment', '30 of 100 head died · 30%', '28 of 100 head died · 28%', 'Final assessed cost (admin)', 'PHP 42,000.00', 'Suspected cause (farmer, not verified)', 'Controlled site visit'] as $expected) {
        details_test(str_contains(html_entity_decode($html), html_entity_decode($expected)), 'Single PDF lacks: ' . $expected);
    }
    $fishRow = damage_report_fetch($connection, damage_report_filters_from_input([]), (int) $reports['fishCount']['id'], true, true)[0];
    $fishHtml = html_entity_decode(damage_report_pdf_incident_html($fishRow) . damage_report_pdf_assessment_html($fishRow, false));
    details_test(str_contains($fishHtml, '50 fish lost · total stock not provided') && str_contains($fishHtml, 'Not verified yet') && str_contains($fishHtml, 'Controlled: water turned muddy overnight.') && !str_contains($fishHtml, '%'), 'Unknown fish total PDF invented a percentage.');
    $legacyRow = damage_report_fetch($connection, damage_report_filters_from_input([]), $oldId, true, true)[0];
    details_test($legacyRow['details'] === null && str_contains(damage_report_pdf_incident_html($legacyRow), 'PHP 2,500.00'), 'Older report PDF lost its peso estimate.');
    $mine = damage_report_fetch($connection, damage_report_filters_from_input(['search' => 'Controlled Damage Details Test']));
    $cells = array_map('damage_report_pdf_list_loss', $mine);
    details_test(in_array("Farmer-reported: Pigs - 30 of 100 head died · 30%\nAdmin-verified: 28 of 100 head died · 28%", $cells, true), 'List PDF lacks the farmer/admin loss lines.');
    details_test(in_array("Farmer-reported: Tilapia - 50 of 200 kg lost · 25%\nAdmin-verified: not yet", $cells, true) && in_array('Not recorded (older report)', $cells, true), 'List PDF loss cells wrong.');
    foreach ([['mode=single&report_id=' . $livestockId], ['mode=list&search=' . rawurlencode('Controlled Damage Details Test')]] as [$query]) {
        [$status, , $raw, $type] = details_request('GET', $admin . '/damage-reports-pdf.php?' . $query, null, null, $cookies);
        details_test($status === 200 && str_starts_with($raw, '%PDF-') && str_contains($type, 'application/pdf'), 'Admin PDF download failed: ' . $query);
    }
    details_test(details_request('GET', $admin . '/damage-reports-pdf.php?mode=single&report_id=' . $livestockId, null)[0] === 302, 'PDF available without admin login.');

    echo "PASS: four categories and both fish units persisted with farmer-reported summaries (no peso estimate); 25 field validations and category mismatch rejected without storing; list/detail retrieval; retry and concurrent duplicates converge on one report/details/image/notification; farmer isolation and protected photos.\n";
    echo "PASS: older app builds and older reports unchanged; admin login, CSRF, Under Review rule, verified figures validated in the report's own unit and kept separate from the farmer's, no-op save, status unchanged, existing resolution rules, farmer sees verified figures only after resolution; single/list PDFs label farmer-reported vs admin-verified with units and sum nothing.\n";
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
    if ($adminId !== null) {
        // Its audit/response rows belong to the reports removed above (ON DELETE CASCADE).
        $statement = $connection->prepare('DELETE FROM admins WHERE id = ? AND username = ?');
        $statement->execute([$adminId, $adminUsername]);
        test_admin_restore($connection, $takeover);
    }
    foreach ([$cookies, $image] as $file) if (is_file($file)) unlink($file);
    foreach ($before as $table => $fingerprint) details_test(details_fingerprint($connection, $table) === $fingerprint, "Pre-existing {$table} rows changed.");
    echo "Removed only this run's controlled farmers, admin, dependent records, generated uploads and temporary files.\n";
}
