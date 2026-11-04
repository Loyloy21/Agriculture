<?php
declare(strict_types=1);

/**
 * Admin Dashboard production row + shared report IDs (disposable database only; never Supabase, never sends email).
 *   - Report IDs: RSBSA reference + ONE per-farmer sequence shared by Damage and Production reports, through the real
 *     mobile APIs in both orders (Damage -> Production, Production -> Damage), parallel submissions, retries, a farmer
 *     without a reference, a correction (ID kept) and a deleted report (number never reused).
 *   - Dashboard: kilograms per category for the reporting month (harvest month, not submission date), Total produced
 *     = sum of the bars, distinct reports and farmers, two months, empty and error states, Latest production reports,
 *     View all and row links into the Production Report page (which opens that exact record).
 * Page renders run in a child process (each page can be loaded once per process).
 */

if (($argv[1] ?? '') === '--render') {
    require_once dirname(__DIR__, 2) . '/backend/config/database.php';
    $options = json_decode((string) file_get_contents($argv[2]), true);
    $connection = db();
    if (!empty($options['breakProduction'])) {
        // A real query failure: the table is renamed for this one page load and always renamed back.
        $connection->exec(db_is_pgsql($connection) ? 'ALTER TABLE production_records RENAME TO hidden_production_records' : 'RENAME TABLE production_records TO hidden_production_records');
        register_shutdown_function(static fn() => $connection->exec(db_is_pgsql($connection) ? 'ALTER TABLE hidden_production_records RENAME TO production_records' : 'RENAME TABLE hidden_production_records TO production_records'));
    }
    session_save_path(sys_get_temp_dir());
    session_id('dashboard-production-' . bin2hex(random_bytes(4)));
    session_start();
    $_SESSION['admin_id'] = (int) $connection->query("SELECT id FROM admins WHERE status = 'active' ORDER BY id LIMIT 1")->fetchColumn();
    $_SESSION['admin_role'] = 'admin';
    $_SESSION['admin_name'] = 'Controlled Dashboard Check';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = $options['query'] ?? [];
    ob_start();
    require dirname(__DIR__, 2) . '/frontend/admin/' . $options['page'];
    $html = (string) ob_get_clean();
    session_destroy();
    echo "\n@@HTML@@" . $html;
    exit;
}

require_once dirname(__DIR__) . '/support/test_base_url.php';
require_once dirname(__DIR__) . '/support/admin_test_account.php';
require_once dirname(__DIR__, 2) . '/backend/lib/admin_dashboard.php';
require_once dirname(__DIR__, 2) . '/backend/lib/admin_production.php';

$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
    echo "  ok  {$message}\n";
}
function api_base(): string
{
    $base = rtrim(getenv('AGRIREACH_TEST_API_BASE') ?: agrireach_test_base_url() . '/CAPSTONE%20AGRICULTURE/api', '/');
    if (!in_array(parse_url($base, PHP_URL_HOST), ['127.0.0.1', 'localhost'], true)) throw new RuntimeException('Integration tests require a loopback URL.');
    return $base;
}
/** Builds a curl handle for one API call (JSON body for production, multipart for damage). */
function api_handle(string $route, string $token, array|string|null $body = null, bool $json = true)
{
    $handle = curl_init(api_base() . $route);
    $headers = ['Accept: application/json', 'Authorization: Bearer ' . $token];
    if ($json && $body !== null) $headers[] = 'Content-Type: application/json';
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 90]);
    if ($body !== null) curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $json ? json_encode($body) : $body]);
    return $handle;
}
function api_call($handle): array
{
    $raw = curl_exec($handle);
    if ($raw === false) throw new RuntimeException('Local test HTTP connection failed (no credentials logged).');
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);
    return [$status, json_decode((string) $raw, true)];
}
/** Runs several prepared requests at the same time. */
function api_parallel(array $handles): array
{
    $multi = curl_multi_init();
    foreach ($handles as $handle) curl_multi_add_handle($multi, $handle);
    do { curl_multi_exec($multi, $running); if ($running) curl_multi_select($multi, 0.1); } while ($running);
    $results = [];
    foreach ($handles as $handle) { $results[] = [(int) curl_getinfo($handle, CURLINFO_HTTP_CODE), json_decode((string) curl_multi_getcontent($handle), true)]; curl_multi_remove_handle($multi, $handle); curl_close($handle); }
    curl_multi_close($multi);
    return $results;
}
function render(string $page, array $query = [], array $extra = []): string
{
    $file = tempnam(sys_get_temp_dir(), 'dsh');
    file_put_contents($file, json_encode(['page' => $page, 'query' => $query] + $extra));
    $process = proc_open([PHP_BINARY, __FILE__, '--render', $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $output = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
    proc_close($process);
    unlink($file);
    $position = strpos($output, '@@HTML@@');
    if ($position === false) throw new RuntimeException("Render of {$page} failed: " . substr($output . $errors, 0, 600));
    return substr($output, $position + 8);
}
/** The text of the first element with this id (tags stripped, whitespace collapsed). */
function section_text(string $html, string $headingId): string
{
    $start = strpos($html, 'id="' . $headingId . '"');
    if ($start === false) return '';
    $end = strpos($html, '</section>', $start);
    // Tags become spaces, so adjacent cells (</dt><dd>) do not run together.
    return trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags(str_replace('<', ' <', substr($html, $start, $end - $start))), ENT_QUOTES)));
}

$connection = db();
test_admin_refuse_live();
$stamp = bin2hex(random_bytes(4));
$image = tempnam(sys_get_temp_dir(), 'dsh') . '.png';
$created = [];
$exitCode = 0;
try {
    file_put_contents($image, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a3ioAAAAASUVORK5CYII='));
    $commodity = static function (string $category, string $unit) use ($connection): int {
        $statement = $connection->prepare("SELECT c.id FROM commodities c JOIN commodity_units cu ON cu.commodity_id = c.id WHERE c.agricultural_category = ? AND cu.unit_code = ? AND c.status = 'Active' ORDER BY c.id LIMIT 1");
        $statement->execute([$category, $unit]);
        return (int) ($statement->fetchColumn() ?: throw new RuntimeException("No {$category} commodity in {$unit}."));
    };

    // Fictional references: A and B registered with an RSBSA card, C with another ID.
    $refs = ['a' => '11-22-33-444-' . substr(str_pad((string) hexdec($stamp), 6, '0', STR_PAD_LEFT), -6), 'b' => '22-33-44-555-' . substr(str_pad((string) (hexdec($stamp) + 1), 6, '0', STR_PAD_LEFT), -6)];
    foreach (['a' => ['RSBSA ID', $refs['a']], 'b' => ['RSBSA ID', $refs['b']], 'c' => ['Philippine National ID', 'PNID-' . $stamp]] as $name => [$idType, $number]) {
        $connection->prepare("INSERT INTO farmers (full_name, contact_number, email, address, account_status, verification_status) VALUES (?, ?, ?, 'Controlled test address', 'Active', 'Approved')")
            ->execute(['Controlled Dashboard Farmer ' . strtoupper($name) . ' ' . $stamp, '+639' . str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT), "dashboard-{$stamp}-{$name}@example.test"]);
        $farmerId = db_insert_id($connection, 'farmers');
        $connection->prepare('INSERT INTO farmer_ids (farmer_id, id_type, id_number) VALUES (?, ?, ?)')->execute([$farmerId, $idType, $number]);
        $created[$name] = ['id' => $farmerId, 'farms' => []];
        foreach (['Crops', 'Fishpond', 'Poultry', 'Livestock'] as $category) {
            $connection->prepare("INSERT INTO farm_profiles (farmer_id, farm_name, farm_type, agricultural_category, farm_address, city_psgc_code, city_name, province_name, barangay_psgc_code, barangay_name, location_review_status, latitude, longitude, status) VALUES (?, ?, ?, ?, 'Controlled Cabadbaran test', '1600203000', 'Cabadbaran City', 'Agusan del Norte', '1600203040', 'Tolosa', 'Verified', 9.12, 125.53, 'Active')")
                ->execute([$farmerId, "Controlled {$category} {$name}", $category, $category]);
            $created[$name]['farms'][$category] = db_insert_id($connection, 'farm_profiles');
        }
        $token = bin2hex(random_bytes(32));
        $connection->prepare('INSERT INTO farmer_sessions (farmer_id, token_hash, expires_at) VALUES (?, ?, ' . db_sql_utc_now($connection) . " + INTERVAL '1' HOUR)")->execute([$farmerId, hash('sha256', $token)]);
        $created[$name]['token'] = $token;
    }
    $productionBody = static fn(string $name, string $category, string $unit, string $quantity, string $harvestDate, ?string $client = null): array => [
        'farmId' => $created[$name]['farms'][$category], 'category' => $category, 'commodityId' => $commodity($category, $unit), 'harvestDate' => $harvestDate,
        'quantity' => $quantity, 'unitCode' => $unit, 'remarks' => '', 'clientSubmissionId' => $client ?? 'dash_' . bin2hex(random_bytes(10)), 'confirmDuplicate' => true,
    ];
    $production = static fn(string $name, array $body) => api_handle('/production/index.php', $created[$name]['token'], $body);
    $damage = static fn(string $name, ?string $client = null) => api_handle('/reports/index.php', $created[$name]['token'], [
        'farm_profile_id' => (string) $created[$name]['farms']['Crops'], 'farm_type' => 'Crops', 'damage_description' => 'Controlled dashboard check.',
        'submitted_address' => 'Controlled Cabadbaran test location', 'submitted_latitude' => '9.1172482', 'submitted_longitude' => '125.5296631',
        'client_submission_id' => $client ?? 'dash_' . bin2hex(random_bytes(10)), 'location_confirmed' => '1', 'location_source' => 'photo', 'location_photo_order' => '1',
        'photos[0]' => new CURLFile($image, 'image/png', 'controlled.png'), 'photo_latitude[0]' => '9.1172482', 'photo_longitude[0]' => '125.5296631',
        'photo_gps_source[0]' => 'photo_exif', 'photo_gps_accuracy[0]' => '', 'photo_captured_at[0]' => '', 'photo_capture_time_source[0]' => 'none',
        'photo_location_timestamp[0]' => '', 'photo_location_status[0]' => 'available', 'damage_details_version' => '1',
        'subject_type' => 'Corn', 'suspected_cause' => 'Flooding', 'crop_damage_percent' => '40', 'planted_area_ha' => '2', 'affected_area_ha' => '0.8',
    ], false);
    $productionCode = static fn(array $result): ?string => $result[1]['data']['record']['reportCode'] ?? null;
    $damageCode = static fn(array $result): ?string => $result[1]['data']['id'] ?? null;
    $sequenceRow = static function (string $table, string $code) use ($connection): ?int {
        $statement = $connection->prepare("SELECT report_sequence FROM {$table} WHERE report_code = ?");
        $statement->execute([$code]);
        $value = $statement->fetchColumn();
        return $value === false || $value === null ? null : (int) $value;
    };

    // Reporting months: the dashboard default is the previous completed month in Asia/Manila.
    $today = production_today();
    $reportMonth = production_previous_month(production_month_start($today));
    $earlierMonth = production_previous_month($reportMonth);
    $in = static fn(DateTimeImmutable $month, int $day): string => $month->modify('+' . ($day - 1) . ' days')->format('Y-m-d');
    check(admin_dashboard_production_month(null, new DateTimeImmutable('2026-11-03', production_timezone()))->format('Y-m') === '2026-10', 'on Nov 3, 2026 the dashboard reports October 2026');
    check(admin_dashboard_production_month(null, new DateTimeImmutable('2026-01-01', production_timezone()))->format('Y-m') === '2025-12', 'on Jan 1 it reports December of the previous year');
    check(admin_dashboard_production_month('2099-01', $today)->format('Y-m') === $reportMonth->format('Y-m') && admin_dashboard_production_month('bad', $today)->format('Y-m') === $reportMonth->format('Y-m'), 'a future or invalid month falls back to the default');
    $before = admin_dashboard_production($connection, $reportMonth);
    $beforeEarlier = admin_dashboard_production($connection, $earlierMonth);

    // ---------- Shared sequence: Damage -> Production (farmer A) ----------
    $r = api_call($damage('a'));
    check($r[0] === 201 && $damageCode($r) === $refs['a'] . '-1', 'A: first-ever report is Damage and gets -1');
    $r = api_call($production('a', $productionBody('a', 'Crops', 'kg', '1200.5', $in($reportMonth, 5))));
    $aCrops = $r[1]['data']['record'] ?? [];
    check($r[0] === 201 && $productionCode($r) === $refs['a'] . '-2' && $sequenceRow('production_records', $refs['a'] . '-2') === 2, 'A: next report is Production and gets -2 (persisted with its sequence)');
    $retryBody = $productionBody('a', 'Fishpond', 'kg', '300', $in($reportMonth, 9), 'dash_retry_' . $stamp . '_aaaa');
    $first = api_call($production('a', $retryBody));
    $retry = api_call($production('a', $retryBody));
    check($first[0] === 201 && $retry[0] === 200 && $retry[1]['data']['alreadySaved'] === true && $productionCode($first) === $refs['a'] . '-3' && $productionCode($retry) === $refs['a'] . '-3', 'A: a retried Production submission returns the same report and ID (-3), no new number');
    $r = api_call($damage('a'));
    check($damageCode($r) === $refs['a'] . '-4', 'A: the following Damage report continues the shared sequence (-4)');

    // ---------- Shared sequence: Production -> Damage (farmer B) ----------
    $r = api_call($production('b', $productionBody('b', 'Poultry', 'kg', '480.25', $in($reportMonth, 12))));
    check($r[0] === 201 && $productionCode($r) === $refs['b'] . '-1', 'B: first-ever report is Production and gets -1');
    $r = api_call($damage('b'));
    check($r[0] === 201 && $damageCode($r) === $refs['b'] . '-2', 'B: next report is Damage and gets -2');

    // ---------- Simultaneous submissions of both types never share a number ----------
    $results = api_parallel([$production('b', $productionBody('b', 'Livestock', 'kg', '150', $in($reportMonth, 20))), $production('b', $productionBody('b', 'Poultry', 'piece', '600', $in($reportMonth, 21))), $damage('b')]);
    $codes = [$productionCode($results[0]), $productionCode($results[1]), $damageCode($results[2])];
    sort($codes);
    $expected = [$refs['b'] . '-3', $refs['b'] . '-4', $refs['b'] . '-5'];
    check(array_column($results, 0) === [201, 201, 201] && $codes === $expected, 'B: two Production + one Damage submitted at the same time get -3, -4, -5 (no duplicate)');

    // ---------- A farmer without an RSBSA reference keeps the previous formats but still uses the sequence ----------
    $r = api_call($production('c', $productionBody('c', 'Crops', 'kg', '250', $in($reportMonth, 7))));
    $cId = (int) ($r[1]['data']['record']['id'] ?? 0);
    check($r[0] === 201 && $productionCode($r) === production_legacy_code($cId) && $sequenceRow('production_records', production_legacy_code($cId)) === 1, 'C (no reference): Production gets "PR-" + id with sequence 1');
    $r = api_call($damage('c'));
    check($r[0] === 201 && str_starts_with((string) $damageCode($r), 'AR-') && $sequenceRow('damage_reports', (string) $damageCode($r)) === 2, 'C (no reference): Damage keeps the "AR-" format with sequence 2');

    // ---------- Earlier month, a rejected record and a fixture (both excluded) ----------
    api_call($production('a', $productionBody('a', 'Crops', 'kg', '900', $in($earlierMonth, 15))));
    api_call($production('b', $productionBody('b', 'Fishpond', 'kg', '75.5', $in($earlierMonth, 3))));
    $rejected = api_call($production('a', $productionBody('a', 'Livestock', 'kg', '5000', $in($reportMonth, 2))))[1]['data']['record'];
    $connection->prepare("UPDATE production_records SET status = 'Rejected' WHERE id = ?")->execute([(int) $rejected['id']]);
    $fixture = api_call($production('a', $productionBody('a', 'Crops', 'kg', '7777', $in($reportMonth, 3))))[1]['data']['record'];
    $connection->prepare('UPDATE production_records SET is_fixture = 1 WHERE id = ?')->execute([(int) $fixture['id']]);

    // ---------- Correction keeps the ID; deleting never frees a number ----------
    $connection->prepare("UPDATE production_records SET quantity = 1250.75, quantity_kg = 1250.75, status = 'Verified' WHERE id = ?")->execute([(int) $aCrops['id']]);
    $statement = $connection->prepare('SELECT report_code FROM production_records WHERE id = ?');
    $statement->execute([(int) $aCrops['id']]);
    check($statement->fetchColumn() === $refs['a'] . '-2', 'A: correcting a report (new kilograms, verified) keeps its ID');
    $statement = $connection->prepare('SELECT MAX(report_sequence) FROM production_records WHERE farmer_id = ?');
    $statement->execute([$created['a']['id']]);
    $highestA = (int) $statement->fetchColumn();
    $deleteMe = api_call($production('a', $productionBody('a', 'Poultry', 'kg', '1', $in($earlierMonth, 1))))[1]['data']['record'];
    $connection->prepare('DELETE FROM production_records WHERE id = ?')->execute([(int) $deleteMe['id']]);
    $r = api_call($damage('a'));
    $deletedNumber = (int) substr((string) $deleteMe['reportCode'], strrpos((string) $deleteMe['reportCode'], '-') + 1);
    check($damageCode($r) === $refs['a'] . '-' . ($deletedNumber + 1) && $deletedNumber > $highestA, 'A: after a report is deleted the next report takes the next number; the deleted number is not reused');
    $statement = $connection->prepare('SELECT report_code FROM damage_reports WHERE farmer_id = ? AND report_code = ?');
    $statement->execute([$created['a']['id'], $refs['a'] . '-1']);
    check($statement->fetchColumn() === $refs['a'] . '-1', 'A: earlier report IDs are unchanged (nothing renumbered)');

    // ---------- Dashboard numbers for the reporting month ----------
    $after = admin_dashboard_production($connection, $reportMonth);
    $delta = static function (array $after, array $before): array {
        $old = array_column($before['categories'], 'centikilograms', 'code');
        $result = [];
        foreach ($after['categories'] as $category) $result[$category['code']] = $category['centikilograms'] - ($old[$category['code']] ?? 0);
        return $result;
    };
    // Report month (harvest month): A Crops 1250.75 (corrected), A Fishpond 300, B Poultry 480.25, B Livestock 150, C Crops 250;
    // B eggs 600 pieces (no kg). Rejected 5000 kg and the 7777 kg fixture are excluded.
    check($delta($after, $before) == ['Crops' => 150075, 'Livestock' => 15000, 'Poultry' => 48025, 'Fishpond' => 30000], 'category kilograms for the month: Crops 1,500.75, Livestock 150, Poultry 480.25, Fishpond 300 (rejected and fixture excluded, corrected value used once)');
    check($after['centikilograms'] === array_sum(array_column($after['categories'], 'centikilograms')) && $after['centikilograms'] - $before['centikilograms'] === 243100, 'Total produced equals the sum of the bars (+2,431 kg)');
    $kilograms = array_column($after['categories'], 'centikilograms');
    $sorted = $kilograms; rsort($sorted);
    check($kilograms === $sorted && count($after['categories']) >= 4, 'bars are ordered largest first and every category is present');
    check($after['reports'] - $before['reports'] === 6 && $after['farmers'] - $before['farmers'] === 3 && $after['otherUnitReports'] - $before['otherUnitReports'] === 1, 'Reports +6 (each saved report once, retry not double-counted, egg pieces included) and Farmers +3 (distinct)');
    $statement = $connection->prepare('SELECT SUM(pr.quantity_kg) FROM production_records pr WHERE pr.is_fixture = 0 AND pr.status IN (\'Submitted\', \'Verified\') AND pr.harvest_date >= ? AND pr.harvest_date < ?');
    $statement->execute([$reportMonth->format('Y-m-d'), $reportMonth->modify('first day of next month')->format('Y-m-d')]);
    check(production_centikilograms($statement->fetchColumn() ?? 0) === $after['centikilograms'], 'the dashboard total matches an independent SUM over the same month');
    $summary = admin_production_summary($connection, admin_production_filters_from_input(['period' => 'custom', 'date_from' => $after['from'], 'date_to' => $after['to']]), admin_production_period(admin_production_filters_from_input(['period' => 'custom', 'date_from' => $after['from'], 'date_to' => $after['to']])));
    check(production_centikilograms($summary['totalKg']) === $after['centikilograms'] && $summary['records'] === $after['reports'] && $summary['farmers'] === $after['farmers'], 'View all (Production Report for the same month) shows the same kilograms, reports and farmers');
    $earlier = admin_dashboard_production($connection, $earlierMonth);
    check($delta($earlier, $beforeEarlier) == ['Crops' => 90000, 'Livestock' => 0, 'Poultry' => 0, 'Fishpond' => 7550] && $earlier['reports'] - $beforeEarlier['reports'] === 2, 'the earlier month counts only its own harvests (Crops 900, Fishpond 75.5; the deleted report is gone)');
    check(in_array($refs['b'] . '-1', array_column($after['latest'], 'code'), true) || count($after['latest']) === 5, 'Latest production reports lists persisted report IDs');

    // ---------- The running page: bars, summary, latest, links, empty and error states ----------
    $html = render('dashboard.php');
    $bars = section_text($html, 'productionCategoryTitle');
    $summaryText = section_text($html, 'productionSummaryTitle');
    check(str_contains($bars, 'Production by Category for ' . $reportMonth->format('F Y')) && str_contains($summaryText, $reportMonth->format('F Y') . ' production'), 'the page titles name the reporting month');
    foreach ($after['categories'] as $category) check(str_contains($bars, $category['code'] . ': ' . admin_dashboard_kg($category['centikilograms'])), "bar {$category['code']} shows " . admin_dashboard_kg($category['centikilograms']));
    check(str_contains($summaryText, 'Total produced ' . admin_dashboard_kg($after['centikilograms'])) && str_contains($summaryText, 'Reports ' . number_format($after['reports'])) && str_contains($summaryText, 'Farmers ' . number_format($after['farmers'])), 'summary card shows the same total, reports and farmers');
    check(str_contains($bars, 'counted in Reports, not added to kilograms'), 'reports in other units are explained, not silently dropped');
    $viewAll = 'production-report.php?period=custom&amp;date_from=' . $after['from'] . '&amp;date_to=' . $after['to'];
    check(str_contains($html, 'href="' . $viewAll . '"'), 'View all opens the Production Report for exactly that month');
    $latest = $after['latest'][0];
    $rowLink = 'production-report.php?period=custom&amp;date_from=' . $latest['from'] . '&amp;date_to=' . $latest['to'] . '&amp;record=' . $latest['id'];
    check(str_contains($html, 'href="' . $rowLink . '">' . htmlspecialchars($latest['code'], ENT_QUOTES) . '</a>'), 'a latest-report row links to that exact saved report by its persisted ID');
    $page = render('production-report.php', ['period' => 'custom', 'date_from' => $latest['from'], 'date_to' => $latest['to'], 'record' => (string) $latest['id']]);
    check(str_contains($page, 'Showing one production report: ' . htmlspecialchars($latest['code'], ENT_QUOTES)) && str_contains($page, '<script type="application/json" id="productionOpenRecord">' . $latest['id'] . '</script>') && substr_count($page, 'class="btn btn-outline-success btn-sm view-production-btn"') === 1, 'the row link opens the Production Report with only that record, set to open its details');
    $page = render('production-report.php', ['period' => 'custom', 'date_from' => $after['from'], 'date_to' => $after['to'], 'search' => $refs['b'] . '-1']);
    check(str_contains($page, htmlspecialchars($refs['b'] . '-1', ENT_QUOTES)), 'the Production Report search finds a report by its RSBSA report ID');
    $html = render('dashboard.php', ['production_month' => $earlierMonth->format('Y-m')]);
    check(str_contains(section_text($html, 'productionSummaryTitle'), 'Total produced ' . admin_dashboard_kg($earlier['centikilograms'])), 'a second month renders its own total');
    $html = render('dashboard.php', ['production_month' => '2001-01']);
    check(str_contains(section_text($html, 'productionCategoryTitle'), 'No production reports for January 2001.') && str_contains(section_text($html, 'productionSummaryTitle'), 'Total produced 0 kg'), 'a month without reports shows an honest empty state');
    $html = render('dashboard.php', [], ['breakProduction' => true]);
    check(str_contains(section_text($html, 'productionCategoryTitle'), 'Production data could not be loaded') && !str_contains(section_text($html, 'productionSummaryTitle'), 'Total produced') && str_contains($html, 'Latest damage reports') && str_contains($html, 'Damage report activity'), 'a failed production query shows an error (no 0 kg) and the rest of the dashboard still loads');
    check((int) $connection->query('SELECT COUNT(*) FROM production_records WHERE farmer_id = ' . (int) $created['a']['id'])->fetchColumn() > 0, 'production_records is back under its own name after the error-state check, with its rows');

    echo "PASS: dashboard production and shared report IDs ({$checks} checks)\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . PHP_EOL);
    $exitCode = 1;
} finally {
    @unlink($image);
    foreach ($created as $farmer) {
        // Remove only this run's farmers (their reports, images, sessions and counters cascade with them).
        $statement = $connection->prepare('SELECT image_path FROM report_images ri JOIN damage_reports dr ON dr.id = ri.report_id WHERE dr.farmer_id = ?');
        $statement->execute([$farmer['id']]);
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $path) {
            $root = realpath(dirname(__DIR__, 2) . '/storage/report-images');
            $file = realpath(dirname(__DIR__, 2) . '/storage/report-images/' . basename((string) $path));
            if ($root && $file && str_starts_with($file, $root . DIRECTORY_SEPARATOR) && preg_match('/^[a-f0-9]{40}\.(?:png|jpg)$/D', basename($file))) unlink($file);
        }
        $connection->prepare('DELETE FROM farmers WHERE id = ?')->execute([$farmer['id']]);
    }
}
exit($exitCode);
