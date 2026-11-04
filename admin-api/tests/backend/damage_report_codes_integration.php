<?php
declare(strict_types=1);

require_once __DIR__ . '/../support/test_base_url.php';

// Report codes (RSBSA reference + per-farmer sequence), the resolution rule for category-specific reports,
// review history, PDF states and filtered navigation, against the local test server and its database.
// Explicitly local-only. Creates only uniquely named .test farmers/admin; finally removes their own rows/files.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__, 2) . '/backend/config/database.php';
require_once dirname(__DIR__) . '/support/admin_test_account.php';
require_once dirname(__DIR__, 2) . '/backend/lib/damage_reports.php';
require_once dirname(__DIR__, 2) . '/backend/lib/damage_report_codes.php';
require_once dirname(__DIR__, 2) . '/frontend/admin/includes/damage_report_pdf.php';

// Child mode: allocate one code inside a transaction that stays open briefly, so parallel children overlap.
if (($argv[1] ?? '') === '--allocate') {
    $connection = db();
    $connection->beginTransaction();
    ['code' => $code, 'sequence' => $sequence] = damage_report_allocate_code($connection, (int) $argv[2]);
    usleep(300000);
    $connection->prepare("INSERT INTO damage_reports (report_code, report_sequence, farmer_id, agricultural_category, damage_type, damage_description, estimated_damage_cost, status) VALUES (?, ?, ?, 'Crops', 'Flooding', 'Controlled concurrency check', NULL, 'Received')")
        ->execute([$code, $sequence, (int) $argv[2]]);
    $connection->commit();
    echo $code;
    exit(0);
}

function codes_test(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function codes_url(string $area): string {
    $base = $area === 'api' ? (getenv('AGRIREACH_TEST_API_BASE') ?: agrireach_test_base_url() . '/CAPSTONE%20AGRICULTURE/api') : agrireach_test_base_url() . '/CAPSTONE%20AGRICULTURE/admin';
    if (!in_array(parse_url($base, PHP_URL_HOST), ['127.0.0.1', 'localhost'], true)) throw new RuntimeException('Integration tests require a loopback URL.');
    return rtrim($base, '/');
}
/** @return array{0:int,1:mixed,2:string,3:string} */
function codes_request(string $method, string $url, ?string $token, array|string|null $body = null, ?string $cookies = null): array {
    $handle = curl_init($url);
    $headers = ['Accept: application/json'];
    if ($token !== null) $headers[] = 'Authorization: Bearer ' . $token;
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 90]);
    if ($cookies !== null) curl_setopt_array($handle, [CURLOPT_COOKIEFILE => $cookies, CURLOPT_COOKIEJAR => $cookies]);
    if ($body !== null) curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
    $raw = curl_exec($handle);
    if ($raw === false) throw new RuntimeException('Local test HTTP connection failed (no credentials logged).');
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $type = (string) curl_getinfo($handle, CURLINFO_CONTENT_TYPE);
    curl_close($handle);
    return [$status, json_decode((string) $raw, true), (string) $raw, $type];
}
function codes_fingerprint(PDO $connection, string $table, string $order): string {
    return hash('sha256', json_encode($connection->query('SELECT * FROM ' . $table . ' ORDER BY ' . $order)->fetchAll()));
}

$connection = db();
$tables = ['damage_reports' => 'id', 'report_images' => 'id', 'damage_report_details' => 'report_id', 'farmer_report_sequences' => 'farmer_id', 'damage_report_verification_audit' => 'id', 'admin_responses' => 'id', 'damage_report_assessment_audit' => 'id'];
$before = [];
foreach ($tables as $table => $order) $before[$table] = codes_fingerprint($connection, $table, $order);
$stamp = bin2hex(random_bytes(6));
$created = [];
$adminId = null;
$adminUsername = 'codes-admin-' . $stamp;
$cookies = tempnam(sys_get_temp_dir(), 'agrireach-codes-cookies-');
$image = tempnam(sys_get_temp_dir(), 'agrireach-codes-photo-');
$checks = 0;
$check = static function (bool $condition, string $message) use (&$checks): void { codes_test($condition, $message); $checks++; };
try {
    file_put_contents($image, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a3ioAAAAASUVORK5CYII='));
    // Farmer A and B have RSBSA references (B's is a different number); C registered with another ID type.
    $farmerSpecs = ['a' => ['RSBSA ID', '998833333052323'], 'b' => ['RSBSA ID', '98-04-17-221-060123'], 'c' => ['Barangay ID', 'BRGY-' . $stamp]];
    foreach ($farmerSpecs as $name => [$idType, $idNumber]) {
        $email = 'report-codes-' . $stamp . '-' . $name . '@example.test';
        $statement = $connection->prepare("INSERT INTO farmers (full_name, contact_number, email, address, account_status, verification_status) VALUES (?, ?, ?, 'Controlled test address', 'Active', 'Approved')");
        $statement->execute(['Controlled Codes Farmer ' . strtoupper($name) . ' ' . $stamp, '+639' . str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT), $email]);
        $farmerId = (int) $connection->lastInsertId();
        $connection->prepare('INSERT INTO farmer_ids (farmer_id, id_type, id_number) VALUES (?, ?, ?)')->execute([$farmerId, $idType, $idNumber]);
        $created[$name] = ['id' => $farmerId, 'email' => $email, 'farms' => []];
        foreach (['Crops', 'Livestock'] as $category) {
            $connection->prepare("INSERT INTO farm_profiles (farmer_id, farm_name, farm_type, agricultural_category, farm_address, barangay_name, city_name, province_name, latitude, longitude) VALUES (?, ?, ?, ?, 'Controlled Cabadbaran test', 'Tolosa', 'Cabadbaran City', 'Agusan del Norte', 9.12, 125.53)")
                ->execute([$farmerId, 'Controlled ' . $category . ' farm ' . $name, $category, $category]);
            $created[$name]['farms'][$category] = (int) $connection->lastInsertId();
        }
        $token = bin2hex(random_bytes(32));
        $connection->prepare('INSERT INTO farmer_sessions (farmer_id, token_hash, expires_at) VALUES (?, ?, ' . db_sql_utc_now($connection) . " + INTERVAL '1' HOUR)")->execute([$farmerId, hash('sha256', $token)]);
        $created[$name]['token'] = $token;
    }
    $submit = static function (string $name, string $category, string $client, array $details = []) use (&$created, $image): array {
        $body = array_merge([
            'farm_profile_id' => (string) $created[$name]['farms'][$category], 'farm_type' => $category, 'damage_description' => 'Controlled report-code check.',
            'submitted_address' => 'Controlled Cabadbaran test location', 'submitted_latitude' => '9.1172482', 'submitted_longitude' => '125.5296631',
            'client_submission_id' => $client, 'location_confirmed' => '1', 'location_source' => 'photo', 'location_photo_order' => '1',
            'photos[0]' => new CURLFile($image, 'image/png', 'controlled.png'), 'photo_latitude[0]' => '9.1172482', 'photo_longitude[0]' => '125.5296631',
            'photo_gps_source[0]' => 'photo_exif', 'photo_gps_accuracy[0]' => '', 'photo_captured_at[0]' => '', 'photo_capture_time_source[0]' => 'none',
            'photo_location_timestamp[0]' => '', 'photo_location_status[0]' => 'available', 'damage_details_version' => '1',
        ], $category === 'Crops'
            ? ['subject_type' => 'Corn', 'suspected_cause' => 'Flooding', 'crop_damage_percent' => '40', 'planted_area_ha' => '2', 'affected_area_ha' => '0.8']
            : ['subject_type' => 'Pigs', 'suspected_cause' => 'Disease', 'total_before' => '100', 'amount_lost' => '30'], $details);
        return codes_request('POST', codes_url('api') . '/reports/index.php', $created[$name]['token'], $body);
    };
    $sequenceOf = static function (string $code) use ($connection): ?int {
        $statement = $connection->prepare('SELECT report_sequence FROM damage_reports WHERE report_code = ?');
        $statement->execute([$code]);
        $value = $statement->fetchColumn();
        return $value === false || $value === null ? null : (int) $value;
    };

    // 1. Codes: canonical RSBSA reference + per-farmer sequence, kept on idempotent retry.
    [$status, $first] = $submit('a', 'Crops', 'codes_a1_' . $stamp);
    $check($status === 201 && $first['data']['id'] === '99-88-33-333-052323-1', 'First report code wrong: ' . ($first['data']['id'] ?? $status));
    $check($sequenceOf('99-88-33-333-052323-1') === 1, 'Sequence 1 not stored.');
    [$status, $retry] = codes_request('POST', codes_url('api') . '/reports/index.php', $created['a']['token'], ['client_submission_id' => 'codes_a1_' . $stamp]);
    $check($status === 200 && $retry['data']['id'] === '99-88-33-333-052323-1', 'Retry did not return the same code.');
    [$status, $fullRetry] = $submit('a', 'Crops', 'codes_a1_' . $stamp);
    $check($status === 200 && $fullRetry['data']['id'] === '99-88-33-333-052323-1', 'Full re-upload retry did not return the same code.');
    [$status, $second] = $submit('a', 'Livestock', 'codes_a2_' . $stamp);
    $check($status === 201 && $second['data']['id'] === '99-88-33-333-052323-2', 'Second report did not get -2.');
    $notice = $connection->prepare('SELECT message FROM notifications WHERE farmer_id = ? ORDER BY id');
    $notice->execute([$created['a']['id']]);
    $check(str_contains(implode("\n", $notice->fetchAll(PDO::FETCH_COLUMN)), '99-88-33-333-052323-2'), 'Farmer notification lacks the new code.');

    // 2. Real concurrency: parallel processes, each holding its transaction open, get distinct numbers.
    $php = PHP_BINARY; $processes = [];
    for ($i = 0; $i < 6; $i++) {
        $processes[] = proc_open([$php, __FILE__, '--allocate', (string) $created['a']['id']], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
    }
    $codes = [];
    foreach ($processes as $i => $process) {
        $codes[] = trim((string) stream_get_contents($pipes[$i][1]));
        $error = trim((string) stream_get_contents($pipes[$i][2]));
        $exit = proc_close($process);
        $check($exit === 0, 'Concurrent allocation process failed: ' . $error);
    }
    sort($codes, SORT_NATURAL);
    $expected = array_map(static fn ($n) => '99-88-33-333-052323-' . $n, range(3, 8));
    $check($codes === $expected, 'Concurrent allocations collided or skipped: ' . implode(', ', $codes));
    // Concurrent duplicate HTTP submissions of one report converge on a single code.
    $multi = curl_multi_init(); $handles = [];
    for ($i = 0; $i < 2; $i++) {
        $handle = curl_init(codes_url('api') . '/reports/index.php');
        $body = ['farm_profile_id' => (string) $created['a']['farms']['Crops'], 'farm_type' => 'Crops', 'damage_description' => '', 'submitted_address' => 'Controlled Cabadbaran test location', 'submitted_latitude' => '9.1172482', 'submitted_longitude' => '125.5296631', 'client_submission_id' => 'codes_parallel_' . $stamp, 'location_confirmed' => '1', 'location_source' => 'manual', 'location_photo_order' => '', 'photos[0]' => new CURLFile($image, 'image/png', 'c.png'), 'photo_latitude[0]' => '', 'photo_longitude[0]' => '', 'photo_gps_source[0]' => 'none', 'photo_gps_accuracy[0]' => '', 'photo_captured_at[0]' => '', 'photo_capture_time_source[0]' => 'none', 'photo_location_timestamp[0]' => '', 'photo_location_status[0]' => 'no_gps', 'damage_details_version' => '1', 'subject_type' => 'Rice', 'suspected_cause' => 'Unsure', 'crop_damage_percent' => '10'];
        curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $created['a']['token'], 'Accept: application/json'], CURLOPT_TIMEOUT => 90]);
        curl_multi_add_handle($multi, $handle); $handles[] = $handle;
    }
    do { curl_multi_exec($multi, $running); if ($running) curl_multi_select($multi, 0.1); } while ($running);
    $parallel = array_map(static fn ($handle) => json_decode((string) curl_multi_getcontent($handle), true)['data']['id'] ?? null, $handles);
    foreach ($handles as $handle) { curl_multi_remove_handle($multi, $handle); curl_close($handle); }
    curl_multi_close($multi);
    $check($parallel[0] === '99-88-33-333-052323-9' && $parallel[1] === $parallel[0], 'Duplicate concurrent submissions did not converge on -9: ' . json_encode($parallel));

    // 3. Never renumber: a deleted report and an RSBSA correction do not free or change numbers.
    $connection->prepare('DELETE FROM damage_reports WHERE report_code = ? AND farmer_id = ?')->execute(['99-88-33-333-052323-9', $created['a']['id']]);
    $connection->prepare('UPDATE farmer_ids SET id_number = ? WHERE farmer_id = ?')->execute(['99-88-33-333-052399', $created['a']['id']]);
    [$status, $afterCorrection] = $submit('a', 'Crops', 'codes_a10_' . $stamp);
    $check($status === 201 && $afterCorrection['data']['id'] === '99-88-33-333-052399-10', 'After deletion/correction the sequence was reused or the new RSBSA not used: ' . ($afterCorrection['data']['id'] ?? ''));
    $check($sequenceOf('99-88-33-333-052323-2') === 2 && $sequenceOf('99-88-33-333-052323-1') === 1, 'Earlier codes changed after the RSBSA correction.');

    // 4. Other farmers: their own sequence; no RSBSA keeps the previous format; no cross-farmer access.
    [$status, $b1] = $submit('b', 'Crops', 'codes_b1_' . $stamp);
    $check($status === 201 && $b1['data']['id'] === '98-04-17-221-060123-1', 'Farmer B code wrong.');
    [$status, $c1] = $submit('c', 'Crops', 'codes_c1_' . $stamp);
    $check($status === 201 && preg_match('/^AR-\d{8}-[0-9A-F]{8}$/', $c1['data']['id']) === 1 && $sequenceOf($c1['data']['id']) === 1, 'Farmer without RSBSA did not keep the AR format with its own sequence.');
    $check(codes_request('GET', codes_url('api') . '/reports/index.php?id=' . rawurlencode('99-88-33-333-052323-1'), $created['b']['token'])[0] === 404, 'Another farmer can open a report by its RSBSA code.');
    $listB = codes_request('GET', codes_url('api') . '/reports/index.php', $created['b']['token'])[1]['data'];
    $check(count($listB) === 1 && !str_contains(json_encode($listB), '052323'), "Farmer B's list exposes farmer A's report or RSBSA number.");
    [, $retryOther] = codes_request('POST', codes_url('api') . '/reports/index.php', $created['b']['token'], ['client_submission_id' => 'codes_a1_' . $stamp]);
    $check(($retryOther['data']['id'] ?? null) !== '99-88-33-333-052323-1', "Farmer B retrieved A's report through A's retry identifier.");

    // 5. Admin: real login; history; resolution without money for category reports; legacy rule kept.
    $password = bin2hex(random_bytes(12)) . 'Aa1';
    $takeover = test_admin_takeover($connection, 'Controlled Codes Reviewer', $adminUsername, password_hash($password, PASSWORD_DEFAULT));
    $adminId = $takeover['id'];
    $admin = codes_url('admin');
    codes_request('GET', $admin . '/login.php', null, null, $cookies);
    $check(codes_request('POST', $admin . '/login.php', null, ['username' => $adminUsername, 'password' => $password], $cookies)[0] === 302, 'Controlled admin login failed.');
    $livestockCode = '99-88-33-333-052323-2';
    [, , $page] = codes_request('GET', $admin . '/damage-reports.php?search=' . rawurlencode($livestockCode), null, null, $cookies);
    $check((bool) preg_match('/name="csrf_token" value="([^"]+)"/', $page, $match), 'No CSRF token.');
    $csrf = html_entity_decode($match[1]);
    foreach (['role="tablist"', 'id="panel-overview"', 'id="panel-photos"', 'id="panel-assessment"', 'id="panel-technical"', 'id="panel-history"', 'RSBSA reference + report sequence', '30 of 100 head died · 30%', 'Showing 1–1 of 1 report'] as $expectedText) {
        $check(str_contains($page, $expectedText), 'Admin page lacks: ' . $expectedText);
    }
    $check(!str_contains($page, 'Estimated Damage Cost'), 'List still shows a peso column.');
    $reportId = (int) $connection->query('SELECT id FROM damage_reports WHERE report_code = ' . $connection->quote($livestockCode))->fetchColumn();
    $post = static fn (array $fields) => codes_request('POST', $admin . '/damage-reports.php', null, ['csrf_token' => $csrf, 'report_id' => (string) $reportId] + $fields, $cookies);
    $check($post(['new_status' => 'Under Review', 'response_message' => 'Controlled: review started.', 'rejection_reason' => '', 'action' => 'update_response'])[0] === 302, 'Status workflow did not accept Under Review.');
    [$status, $data] = $post(['action' => 'resolve_report', 'final_assessed_cost' => '', 'assessment_remarks' => 'Controlled final remarks.']);
    $check($status === 422 && isset($data['errors']['verified_figures']) && !isset($data['errors']['final_assessed_cost']), 'Category report resolved without verified figures, or a peso amount was demanded.');
    [$status, $data] = $post(['action' => 'save_assessment', 'final_assessed_cost' => '', 'assessment_remarks' => 'Controlled draft remarks.']);
    $check($status === 200 && $data['report']['status'] === 'Under Review', 'Draft save failed.');
    [$status, $data] = $post(['action' => 'save_verified_figures', 'verified_cause' => 'Disease', 'verified_total_before' => '100', 'verified_amount_lost' => '28', 'review_notes' => 'Controlled verification notes.']);
    $check($status === 200 && $data['changed'] === true, 'Verified figures not saved.');
    [$status, $data] = $post(['action' => 'resolve_report', 'final_assessed_cost' => '', 'assessment_remarks' => 'Controlled final remarks.']);
    $check($status === 200 && $data['report']['status'] === 'Resolved' && $data['report']['final_assessed_cost'] === null && $data['report']['estimated_damage_cost'] === null, 'Resolution without money failed or invented a peso value.');
    $resolvedNotice = $connection->prepare("SELECT message FROM notifications WHERE related_report_id = ? AND title = 'Damage report resolved'");
    $resolvedNotice->execute([$reportId]);
    $message = (string) $resolvedNotice->fetchColumn();
    $check(str_contains($message, 'Verified loss: 28 of 100 head died · 28%') && !str_contains($message, 'PHP'), 'Resolution notice wrong: ' . $message);
    [, $farmerView] = codes_request('GET', codes_url('api') . '/reports/index.php?id=' . rawurlencode($livestockCode), $created['a']['token']);
    $check($farmerView['data']['status'] === 'Resolved' && $farmerView['data']['finalAssessedCost'] === null && $farmerView['data']['assessmentRemarks'] === 'Controlled final remarks.' && $farmerView['data']['damageDetails']['verified']['summary'] === '28 of 100 head died · 28%', 'Farmer view of the resolved report is wrong.');
    $legacyCode = 'AR-OLD-CODES-' . strtoupper($stamp);
    $connection->prepare("INSERT INTO damage_reports (report_code, farmer_id, agricultural_category, damage_type, damage_description, estimated_damage_cost, status) VALUES (?, ?, 'Livestock', 'Flood', 'Controlled older report', 2500.00, 'Under Review')")->execute([$legacyCode, $created['b']['id']]);
    $legacyId = (int) $connection->lastInsertId();
    [$status, $data] = codes_request('POST', $admin . '/damage-reports.php', null, ['csrf_token' => $csrf, 'report_id' => (string) $legacyId, 'action' => 'resolve_report', 'final_assessed_cost' => '', 'assessment_remarks' => 'Controlled remarks.'], $cookies);
    $check($status === 422 && isset($data['errors']['final_assessed_cost']), 'Older report resolved without its final assessed cost.');

    // 6. History: only recorded events, in order.
    $resolved = damage_report_fetch($connection, damage_report_filters_from_input([]), $reportId, true, true)[0];
    $events = array_column($resolved['history'], 'event');
    $check($events === ['Report submitted', 'Status changed from Received to Under Review', 'Assessment draft saved', 'Admin-verified figures saved', 'Status changed from Under Review to Resolved'], 'History events wrong: ' . json_encode($events));
    $check($resolved['history'][3]['by'] === 'Controlled Codes Reviewer' && str_contains((string) $resolved['history'][3]['notes'], '28 of 100 head died · 28%'), 'Verification history lacks reviewer or figures.');
    $check($resolved['rsbsa_reference'] === '99-88-33-333-052399' && (int) $resolved['report_sequence'] === 2, 'Admin data lacks the current RSBSA reference or sequence.');

    // 7. PDF: final category report (no peso), pending report with blanks, older report keeps its peso values.
    $check(damage_report_pdf_is_final($resolved), 'Resolved, verified report not treated as final.');
    $finalHtml = html_entity_decode(damage_report_pdf_assessment_html($resolved, true) . damage_report_pdf_incident_html($resolved) . damage_report_pdf_header_html($resolved, true));
    foreach (['Admin Final Assessment', 'Admin-verified loss', '28 of 100 head died · 28%', 'Final cause', 'Disease', 'Controlled final remarks.', 'Controlled Codes Reviewer', 'Farmer-reported loss', '30 of 100 head died · 30%', 'Suspected cause (farmer, not verified)', '99-88-33-333-052323-2'] as $expectedText) {
        $check(str_contains($finalHtml, $expectedText), 'Final PDF lacks: ' . $expectedText);
    }
    $check(!str_contains($finalHtml, 'PHP') && !str_contains($finalHtml, 'PENDING'), 'Final category PDF shows a peso amount or a pending label.');
    $pendingId = (int) $connection->query('SELECT id FROM damage_reports WHERE report_code = ' . $connection->quote('99-88-33-333-052323-1'))->fetchColumn();
    $pending = damage_report_fetch($connection, damage_report_filters_from_input([]), $pendingId, true, true)[0];
    $check(!damage_report_pdf_is_final($pending), 'Pending report treated as final.');
    $pendingHtml = html_entity_decode(damage_report_pdf_header_html($pending, false) . damage_report_pdf_assessment_html($pending, false) . damage_report_pdf_signature_html($pending, false));
    $check(str_contains($pendingHtml, 'PENDING REVIEW - DRAFT, NOT FINAL') && str_contains($pendingHtml, 'pending - not final') && substr_count($pendingHtml, 'blank-line') >= 4 && str_contains($pendingHtml, 'Not verified yet'), 'Pending PDF does not leave unverified fields blank.');
    $legacy = damage_report_fetch($connection, damage_report_filters_from_input([]), $legacyId, true, true)[0];
    $legacyHtml = html_entity_decode(damage_report_pdf_incident_html($legacy) . damage_report_pdf_assessment_html($legacy, false));
    $check(str_contains($legacyHtml, 'PHP 2,500.00') && str_contains($legacyHtml, 'Not assessed yet') && damage_report_pdf_list_loss($legacy) === 'Not recorded (older report)', 'Older report PDF changed.');
    foreach ([$reportId, $pendingId, $legacyId] as $id) {
        [$status, , $raw, $type] = codes_request('GET', $admin . '/damage-reports-pdf.php?mode=single&report_id=' . $id, null, null, $cookies);
        $check($status === 200 && str_starts_with($raw, '%PDF-') && str_contains($type, 'application/pdf'), 'Authenticated PDF download failed for report ' . $id);
    }

    // 8. Filtered list and Previous/Next positions agree; counts are accurate.
    $filters = damage_report_filters_from_input(['search' => 'Controlled Codes Farmer A ' . $stamp]);
    $total = damage_report_count($connection, $filters);
    $ordered = array_column(damage_report_fetch($connection, $filters), 'id');
    $check($total === count($ordered) && $total === 9, 'Filtered count is wrong: ' . $total);
    foreach ([0, 4, $total - 1] as $index) {
        [$status, $item] = codes_request('GET', $admin . '/damage-report-detail-data.php?index=' . $index . '&search=' . rawurlencode('Controlled Codes Farmer A ' . $stamp), null, null, $cookies);
        $check($status === 200 && (int) $item['report']['id'] === (int) $ordered[$index] && $item['total'] === $total && is_array($item['report']['history']), 'Previous/Next position ' . $index . ' disagrees with the list.');
    }
    $check(codes_request('GET', $admin . '/damage-report-detail-data.php?index=' . $total . '&search=' . rawurlencode('Controlled Codes Farmer A ' . $stamp), null, null, $cookies)[0] === 404, 'Navigation past the filtered list did not stop.');
    [, , $pageTwo] = codes_request('GET', $admin . '/damage-reports.php?search=' . rawurlencode('Controlled Codes Farmer') . '&page=1', null, null, $cookies);
    $check(substr_count($pageTwo, 'class="btn btn-outline-success btn-sm view-report-btn"') === 10 && str_contains($pageTwo, 'Showing 1–10 of 12 reports'), 'First page does not show 10 of the 12 matching reports.');
    [, , $pageTwo] = codes_request('GET', $admin . '/damage-reports.php?search=' . rawurlencode('Controlled Codes Farmer') . '&page=2', null, null, $cookies);
    $check(substr_count($pageTwo, 'class="btn btn-outline-success btn-sm view-report-btn"') === 2 && str_contains($pageTwo, 'Showing 11–12 of 12 reports'), 'Second page wrong.');

    echo "PASS: report codes, {$checks} checks: RSBSA reference + per-farmer sequence (-1, -2), same code on retries, 6 overlapping transactions got distinct consecutive numbers, concurrent duplicate uploads converge, no reuse after deletion, RSBSA correction keeps old codes, AR format without RSBSA, no cross-farmer access.\n";
    echo "PASS: category report resolved without money only after verified figures (legacy still needs its cost), no peso in notice/PDF, recorded history in order, final/pending/older PDFs, filtered Previous/Next positions, 10-per-page counts.\n";
} finally {
    foreach ($created as $farmer) {
        $statement = $connection->prepare('SELECT ri.image_path FROM report_images ri JOIN damage_reports dr ON dr.id = ri.report_id WHERE dr.farmer_id = ?');
        $statement->execute([$farmer['id']]);
        foreach ($statement->fetchAll() as $row) {
            $root = realpath(dirname(__DIR__, 2) . '/storage/report-images');
            $file = realpath(dirname(__DIR__, 2) . '/storage/report-images/' . basename($row['image_path']));
            if ($root && $file && str_starts_with($file, $root . DIRECTORY_SEPARATOR) && preg_match('/^[a-f0-9]{40}\.(?:png|jpg)$/D', basename($file))) unlink($file);
        }
        $connection->prepare('DELETE FROM farmers WHERE id = ? AND email = ?')->execute([$farmer['id'], $farmer['email']]);
    }
    if ($adminId !== null) $connection->prepare('DELETE FROM admins WHERE id = ? AND username = ?')->execute([$adminId, $adminUsername]);
    if (isset($takeover)) test_admin_restore($connection, $takeover);
    foreach ([$cookies, $image] as $file) if (is_file($file)) unlink($file);
    foreach ($tables as $table => $order) codes_test(codes_fingerprint($connection, $table, $order) === $before[$table], "Pre-existing {$table} rows changed.");
    echo "Removed only this run's controlled farmers, admin, dependent records, generated uploads and temporary files.\n";
}
