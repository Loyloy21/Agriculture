<?php
declare(strict_types=1);

require_once __DIR__ . '/../support/test_base_url.php';

// Admin Production Report end to end over the local test server: records are submitted through the FARMER mobile
// API (the same endpoint the app uses) and then read through the admin report (library, page, lookups, CSV export):
// periods (previous month by default, month/year transitions), every filter alone and combined, search (incl.
// Filipino aliases and record IDs), per-unit totals, pagination, access control and input validation.
// Explicitly local-only. Creates only uniquely named .test farmers and one admin; finally removes their own rows.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__, 2) . '/backend/config/database.php';
require_once dirname(__DIR__, 2) . '/backend/lib/admin_production.php';
require_once dirname(__DIR__) . '/support/admin_test_account.php';

function admin_prod_test(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
function admin_prod_base(string $area): string
{
    $base = $area === 'api' ? (getenv('AGRIREACH_TEST_API_BASE') ?: agrireach_test_base_url() . '/CAPSTONE%20AGRICULTURE/api') : agrireach_test_base_url() . '/CAPSTONE%20AGRICULTURE/admin';
    if (!in_array(parse_url($base, PHP_URL_HOST), ['127.0.0.1', 'localhost'], true)) throw new RuntimeException('Integration tests require a loopback URL.');
    return rtrim($base, '/');
}
/** @return array{0:int,1:mixed,2:string,3:string,4:string} status, JSON, body, content type, headers */
function admin_prod_request(string $method, string $url, ?string $token = null, array|string|null $body = null, ?string $cookies = null): array
{
    $handle = curl_init($url);
    $headers = ['Accept: application/json'];
    if ($token !== null) $headers[] = 'Authorization: Bearer ' . $token;
    if (is_string($body)) $headers[] = 'Content-Type: application/json';
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 90, CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => true]);
    if ($cookies !== null) curl_setopt_array($handle, [CURLOPT_COOKIEFILE => $cookies, CURLOPT_COOKIEJAR => $cookies]);
    if ($body !== null) curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
    $raw = curl_exec($handle);
    if ($raw === false) throw new RuntimeException('Local test HTTP connection failed (no credentials logged).');
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    $type = (string) curl_getinfo($handle, CURLINFO_CONTENT_TYPE);
    $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    curl_close($handle);
    $responseBody = substr((string) $raw, $headerSize);
    return [$status, json_decode($responseBody, true), $responseBody, $type, substr((string) $raw, 0, $headerSize)];
}
function admin_prod_fingerprint(PDO $connection, string $table): string
{
    return hash('sha256', json_encode($connection->query('SELECT * FROM ' . $table . ' ORDER BY id')->fetchAll()));
}
/** Library call with the default filters plus overrides, at a fixed "today". */
function admin_prod_summary(PDO $connection, array $overrides, ?DateTimeImmutable $today = null): array
{
    $filters = array_merge(admin_production_filters_from_input([]), $overrides);
    $period = admin_production_period($filters, $today);
    return admin_production_summary($connection, $filters, $period);
}

// Reporting periods (no database): current month by default, month and year transitions.
$tz = new DateTimeZone('Asia/Manila');
$at = static fn(string $date): DateTimeImmutable => new DateTimeImmutable($date, $tz);
$range = static fn(string $period, string $today, array $extra = []): string => (static function (array $p): string { return $p['from']->format('Y-m-d') . '..' . $p['until']->modify('-1 day')->format('Y-m-d'); })(admin_production_period(array_merge(admin_production_filters_from_input(['period' => $period]), $extra), $at($today)));
admin_prod_test($range('previous_month', '2026-10-23') === '2026-09-01..2026-09-30', 'Previous month of October 2026 is September 2026.');
admin_prod_test(admin_production_filters_from_input([])['period'] === 'current_month' && $range('', '2026-10-23') === '2026-10-01..2026-10-31', 'The default period is the current month.');
admin_prod_test($range('previous_month', '2027-01-15') === '2026-12-01..2026-12-31', 'January 2027 -> December 2026.');
admin_prod_test($range('previous_month', '2026-03-31') === '2026-02-01..2026-02-28', 'March 31 -> all of February.');
admin_prod_test($range('current_month', '2026-10-23') === '2026-10-01..2026-10-31', 'Current month.');
admin_prod_test($range('last_3_months', '2027-01-15') === '2026-10-01..2026-12-31', 'Last 3 completed months across a year change.');
admin_prod_test($range('last_6_months', '2026-10-23') === '2026-04-01..2026-09-30', 'Last 6 completed months.');
admin_prod_test($range('current_year', '2026-10-23') === '2026-01-01..2026-12-31' && $range('previous_year', '2027-01-15') === '2026-01-01..2026-12-31', 'Current and previous year.');
admin_prod_test($range('custom', '2026-10-23', ['date_from' => '2026-09-10', 'date_to' => '2026-09-12']) === '2026-09-10..2026-09-12', 'Custom range is inclusive.');
foreach ([['2026-09-12', '2026-09-10'], ['2026-02-30', '2026-03-01'], ['', '2026-03-01'], ['1999-12-01', '2000-01-01'], ['2001-01-01', '2026-01-01']] as [$from, $to]) {
    try { admin_production_period(array_merge(admin_production_filters_from_input(['period' => 'custom']), ['date_from' => $from, 'date_to' => $to])); throw new RuntimeException("Custom {$from}..{$to} accepted"); }
    catch (InvalidArgumentException) {}
}
$normalized = admin_production_filters_from_input(['period' => 'everything', 'category' => 'Fish', 'status' => 'Approved', 'sort' => 'password', 'dir' => 'sideways', 'farmer_id' => '1 OR 1=1', 'commodity_id' => '-3', 'search' => str_repeat('a', 300)]);
admin_prod_test($normalized['period'] === 'current_month' && $normalized['category'] === '' && $normalized['status'] === 'counted' && $normalized['sort'] === 'harvest_date' && $normalized['dir'] === 'desc'
    && $normalized['farmer_id'] === '' && $normalized['commodity_id'] === '' && mb_strlen($normalized['search']) === 100, 'Unsupported filter values fall back to safe defaults.');
echo "PASS: reporting periods (default current month; previous month of Jan 2027 -> Dec 2026; last 3/6 months, years, custom) and filter validation\n";

$connection = db();
$tables = ['farmers', 'farm_profiles', 'damage_reports', 'production_records', 'commodities', 'admins'];
$before = [];
foreach ($tables as $table) $before[$table] = admin_prod_fingerprint($connection, $table);
$stamp = 'prodtest' . bin2hex(random_bytes(5));
$created = [];
$adminId = null;
$adminUsername = 'production-admin-' . $stamp;
$cookies = tempnam(sys_get_temp_dir(), 'agrireach-production-admin-');
try {
    foreach (['a' => ['Juan Dela Cruz ' . $stamp, ['main' => 'Crops', 'poultry' => 'Poultry']], 'b' => ['Maria Santos ' . $stamp, ['pond' => 'Fishpond']]] as $key => [$name, $farms]) {
        $email = 'production-admin-' . $stamp . '-' . $key . '@example.test';
        $contact = '+639' . str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
        $statement = $connection->prepare("INSERT INTO farmers (full_name, contact_number, email, address, account_status, verification_status) VALUES (?, ?, ?, 'Controlled test address', 'Active', 'Approved')");
        $statement->execute([$name, $contact, $email]);
        $farmerId = (int) $connection->lastInsertId();
        $created[$key] = ['id' => $farmerId, 'email' => $email, 'contact' => $contact, 'name' => $name, 'farms' => []];
        foreach ($farms as $farmKey => $category) {
            $statement = $connection->prepare("INSERT INTO farm_profiles (farmer_id, farm_name, farm_type, agricultural_category, farm_address, barangay_name) VALUES (?, ?, ?, ?, 'Controlled Cabadbaran test', 'Controlled Barangay')");
            $statement->execute([$farmerId, ucfirst($farmKey) . ' Area ' . $stamp, $category, $category]);
            $created[$key]['farms'][$farmKey] = (int) $connection->lastInsertId();
        }
        $token = bin2hex(random_bytes(32));
        $statement = $connection->prepare('INSERT INTO farmer_sessions (farmer_id, token_hash, expires_at) VALUES (?, ?, ' . db_sql_utc_now($connection) . " + INTERVAL '1' HOUR)");
        $statement->execute([$farmerId, hash('sha256', $token)]);
        $created[$key]['token'] = $token;
    }
    $catalog = admin_prod_request('GET', admin_prod_base('api') . '/production/index.php?view=catalog', $created['a']['token'])[1]['data'];
    $commodity = [];
    foreach ($catalog['commodities'] as $item) $commodity[$item['name']] = $item;

    // Farmers submit through the mobile API, exactly as the app does.
    $today = production_today();
    $thisMonth = production_month_start($today);
    $previous = production_previous_month($thisMonth);
    $twoAgo = production_previous_month($previous);
    $submitted = [];
    $submit = static function (string $farmer, string $farm, string $name, string $date, string $quantity, string $unit, string $remarks = '') use (&$submitted, $created, $commodity): array {
        $item = $commodity[$name];
        [$status, $payload] = admin_prod_request('POST', admin_prod_base('api') . '/production/index.php', $created[$farmer]['token'], json_encode([
            'farmId' => $created[$farmer]['farms'][$farm], 'category' => $item['category'], 'commodityId' => $item['id'], 'harvestDate' => $date,
            'quantity' => $quantity, 'unitCode' => $unit, 'remarks' => $remarks, 'clientSubmissionId' => 'admin_report_test_' . bin2hex(random_bytes(8)),
        ]));
        admin_prod_test($status === 201, "Mobile submission {$name} {$quantity} {$unit} must save (got {$status}: " . json_encode($payload['errors'] ?? $payload['message'] ?? null) . ').');
        return $submitted[] = $payload['data']['record'];
    };
    $p = static fn(int $day): string => $previous->setDate((int) $previous->format('Y'), (int) $previous->format('n'), min($day, (int) $previous->format('t')))->format('Y-m-d');
    $palay = $submit('a', 'main', 'Palay (Rice)', $p(15), '500', 'kg', '=HYPERLINK("http://example.invalid")');
    $eggs = $submit('a', 'poultry', 'Chicken Eggs', $p(17), '300', 'piece');
    $tilapia = $submit('b', 'pond', 'Tilapia', $p(18), '250', 'kg');
    $goat = $submit('a', 'main', 'Native Goat', $p(19), '5', 'head');
    $milkfish = $submit('b', 'pond', 'Milkfish (Bangus)', $previous->modify('last day of this month')->format('Y-m-d'), '0.1', 'mt');
    $rejected = $submit('a', 'main', 'White Corn', $p(1), '40', 'kg');
    $connection->prepare("UPDATE production_records SET status = 'Rejected' WHERE id = ?")->execute([(int) $rejected['id']]);
    $current = $submit('a', 'main', 'Palay (Rice)', $thisMonth->format('Y-m-d'), '70', 'kg');
    $older = $submit('b', 'pond', 'Tilapia', $twoAgo->format('Y-m-d'), '30', 'kg');
    echo 'PASS: ' . count($submitted) . " production records submitted through the farmer mobile API\n";

    // The admin report reads those same records (isolated here by the unique test name in the search).
    $mine = ['search' => $stamp, 'period' => 'previous_month'];
    $summary = admin_prod_summary($connection, $mine);
    admin_prod_test($summary['records'] === 5 && $summary['countedRecords'] === 5, 'Previous month: 5 counted records (rejected and other months excluded), got ' . $summary['records']);
    admin_prod_test($summary['totalKg'] == 850 && $summary['kgRecords'] === 3, 'Total kg = 500 + 250 + 100 (0.1 MT); eggs and goats are not kilograms.');
    admin_prod_test(array_column($summary['otherTotals'], 'total', 'unitCode') == ['head' => 5, 'piece' => 300], 'Head and pieces totalled separately: ' . json_encode($summary['otherTotals']));
    admin_prod_test($summary['farmers'] === 2 && $summary['categories'] === 4 && $summary['farms'] === 3 && $summary['commodities'] === 5, 'Reporting farmers, categories, farm areas, commodities.');
    $byCategory = array_column($summary['categoryBreakdown'], null, 'code');
    admin_prod_test(array_keys($byCategory) === ['Crops', 'Livestock', 'Poultry', 'Fishpond'] && $byCategory['Fishpond']['label'] === 'Aquaculture / Fishpond', 'Four categories in catalog order.');
    admin_prod_test($byCategory['Crops']['totalKg'] == 500 && $byCategory['Crops']['records'] === 1 && $byCategory['Fishpond']['totalKg'] == 350 && $byCategory['Fishpond']['records'] === 2 && $byCategory['Fishpond']['farmers'] === 1, 'Category kilograms, records and farmers.');
    admin_prod_test($byCategory['Poultry']['totalKg'] == 0 && $byCategory['Poultry']['otherTotals'][0]['total'] == 300 && $byCategory['Livestock']['otherTotals'][0]['unitCode'] === 'head', 'Poultry eggs and livestock heads stay in their own units.');
    admin_prod_test(abs($byCategory['Crops']['percent'] - 58.8) < 0.01 && abs($byCategory['Fishpond']['percent'] - 41.2) < 0.01, 'Category share of the kilogram total.');
    admin_prod_test(array_column($summary['commodityTotals'], 'name') === ['Palay (Rice)', 'Tilapia', 'Milkfish (Bangus)'] && $summary['commodityTotals'][2]['totalKg'] == 100, 'Commodity kg totals, largest first.');
    $trend = array_column($summary['trend'], null, 'month');
    admin_prod_test(count($summary['trend']) === 6 && $trend[$previous->format('Y-m')]['totalKg'] == 850 && $trend[$previous->format('Y-m')]['inPeriod'] && $trend[$twoAgo->format('Y-m')]['totalKg'] == 30 && !$trend[$twoAgo->format('Y-m')]['inPeriod'], 'Monthly trend in context, selected period marked.');
    echo "PASS: previous month: 850 kg (kg + MT only), 5 head and 300 pcs apart, 2 farmers, 4 categories, category/commodity/monthly breakdowns\n";

    $count = static fn(array $overrides): int => admin_prod_summary($connection, array_merge($mine, $overrides))['records'];
    admin_prod_test(admin_prod_summary($connection, array_merge($mine, ['period' => 'current_month']))['totalKg'] == 70 && $count(['period' => 'current_month']) === 1, 'Current month: only this month\'s harvest.');
    admin_prod_test(admin_prod_summary($connection, array_merge($mine, ['period' => 'last_3_months']))['totalKg'] == 880, 'Last 3 completed months add the earlier harvest, not the current month.');
    admin_prod_test($count(['period' => 'custom', 'date_from' => $p(15), 'date_to' => $p(17)]) === 2, 'Custom range by harvest date.');
    admin_prod_test($count(['category' => 'Fishpond']) === 2 && $count(['category' => 'Poultry']) === 1, 'Category filter.');
    admin_prod_test($count(['commodity_id' => (string) $commodity['Tilapia']['id']]) === 1 && $count(['category' => 'Fishpond', 'commodity_id' => (string) $commodity['Milkfish (Bangus)']['id']]) === 1, 'Commodity filter (alone and within its category).');
    [$cleared, $wasCleared] = admin_production_normalize_filters($connection, array_merge(admin_production_filters_from_input([]), ['category' => 'Crops', 'commodity_id' => (string) $commodity['Tilapia']['id']]));
    admin_prod_test($wasCleared && $cleared['commodity_id'] === '', 'A commodity of another category is cleared.');
    admin_prod_test($count(['farm_id' => (string) $created['a']['farms']['poultry']]) === 1 && $count(['farmer_id' => (string) $created['b']['id']]) === 2, 'Farm area and farmer filters.');
    admin_prod_test($count(['farmer_id' => (string) $created['a']['id'], 'category' => 'Crops', 'commodity_id' => (string) $commodity['Palay (Rice)']['id'], 'farm_id' => (string) $created['a']['farms']['main']]) === 1, 'All filters combined.');
    admin_prod_test($count(['farmer_id' => (string) $created['b']['id'], 'category' => 'Crops']) === 0 && admin_prod_summary($connection, array_merge($mine, ['farmer_id' => (string) $created['b']['id'], 'category' => 'Crops']))['totalKg'] == 0, 'Empty combination: zero, no errors.');
    $all = admin_prod_summary($connection, array_merge($mine, ['status' => 'all']));
    admin_prod_test($all['records'] === 6 && $all['countedRecords'] === 5 && $all['totalKg'] == 850, 'All statuses lists the rejected record without adding it to totals.');
    $onlyRejected = admin_prod_summary($connection, array_merge($mine, ['status' => 'Rejected']));
    admin_prod_test($onlyRejected['records'] === 1 && $onlyRejected['totalKg'] == 0, 'Rejected only: listed, never counted.');
    admin_prod_test($count(['status' => 'Verified']) === 0, 'No verified records exist (no workflow verifies them yet).');
    echo "PASS: filters alone and combined: periods, category, dependent commodity, farm area, farmer, status; summaries use the same filters\n";

    // Search: partial, case-insensitive, Filipino aliases, record ID; combined with filters.
    $search = static fn(string $text, array $extra = []): int => admin_prod_summary($connection, ['search' => $text . ' ' . $stamp, 'period' => 'previous_month'] + $extra)['records'];
    admin_prod_test($search('TILAPIA') === 1 && $search('bangus') === 1 && $search('itlog') === 1 && $search('humay') === 1 && $search('kambing') === 1, 'Commodity search incl. Filipino/Bisaya names.');
    admin_prod_test($search('juan') === 3 && $search('maria') === 2 && $search('pond area') === 2, 'Farmer and farm area search.');
    admin_prod_test(admin_prod_summary($connection, ['search' => admin_production_code((int) $tilapia['id']), 'period' => 'previous_month'])['records'] === 1, 'Record ID search (PR-000123).');
    $byNumber = array_merge(admin_production_filters_from_input([]), ['search' => (string) $tilapia['id'], 'period' => 'previous_month', 'farmer_id' => (string) $created['b']['id']]);
    admin_prod_test(in_array((int) $tilapia['id'], array_column(admin_production_records($connection, $byNumber, admin_production_period($byNumber), 50), 'id'), true), 'Record ID search by number (123).');
    admin_prod_test($search('tilapia', ['category' => 'Crops']) === 0, 'Search works together with filters.');
    admin_prod_test($search('%') === 0 && $search('_') === 0 && $search("' OR 1=1 --") === 0, 'Wildcards and quotes are matched literally.');
    echo "PASS: search (partial, case-insensitive, Filipino aliases, record IDs, with filters; wildcards/quotes literal)\n";

    // Records: pagination and sorting over all matching rows; totals do not depend on the page.
    $filters = array_merge(admin_production_filters_from_input([]), $mine, ['sort' => 'quantity', 'dir' => 'asc']);
    $period = admin_production_period($filters);
    $firstPage = admin_production_records($connection, $filters, $period, 2, 0);
    $secondPage = admin_production_records($connection, $filters, $period, 2, 2);
    $everything = admin_production_records($connection, $filters, $period, 100, 0);
    admin_prod_test(count($everything) === 5 && admin_production_count($connection, $filters, $period) === 5, 'Count matches the rows.');
    admin_prod_test(array_column($firstPage, 'quantity') == [0.1, 5] && array_column($secondPage, 'quantity') == [250, 300], 'Sorted pages without overlap.');
    $row = array_values(array_filter($everything, static fn($item) => $item['id'] === (int) $milkfish['id']))[0];
    admin_prod_test($row['code'] === admin_production_code((int) $milkfish['id']) && $row['farmerName'] === $created['b']['name'] && $row['farmName'] === 'Pond Area ' . $stamp && $row['categoryLabel'] === 'Aquaculture / Fishpond'
        && $row['commodityName'] === 'Milkfish (Bangus)' && $row['quantityText'] === '0.1 MT' && $row['unitName'] === 'Metric tons' && $row['quantityKg'] == 100 && $row['status'] === 'Submitted', 'Admin row = the farmer\'s record (farmer, farm, category, commodity, quantity, unit).');
    admin_prod_test(!array_key_exists('email', $row) && !array_key_exists('contactNumber', $row), 'No farmer contact details in report rows.');
    echo "PASS: paginated, sorted records; the admin sees exactly what the farmer submitted\n";

    // HTTP: access control first.
    $admin = admin_prod_base('admin');
    admin_prod_test(admin_prod_request('GET', $admin . '/production-report.php', null, null, $cookies)[0] === 302, 'Report page requires an admin session.');
    admin_prod_test(admin_prod_request('GET', $admin . '/production-report-export.php', null, null, $cookies)[0] === 302, 'Export requires an admin session.');
    admin_prod_test(admin_prod_request('GET', $admin . '/production-report-data.php?lookup=farmers', null, null, $cookies)[0] === 401, 'Lookups require an admin session (401 JSON).');
    admin_prod_test(admin_prod_request('GET', $admin . '/production-report-data.php?lookup=farmers', $created['a']['token'])[0] === 401, 'A farmer token cannot use admin lookups.');
    admin_prod_test(admin_prod_request('GET', $admin . '/production-report.php', $created['a']['token'])[0] === 302, 'A farmer token cannot open the admin report.');
    $password = bin2hex(random_bytes(12));
    $takeover = test_admin_takeover($connection, 'Controlled Production Reviewer', $adminUsername, password_hash($password, PASSWORD_DEFAULT));
    $adminId = $takeover['id'];
    admin_prod_request('GET', $admin . '/login.php', null, null, $cookies);
    admin_prod_test(admin_prod_request('POST', $admin . '/login.php', null, ['username' => $adminUsername, 'password' => $password], $cookies)[0] === 302, 'Admin login.');
    echo "PASS: access control (no session -> login/401; farmer tokens refused)\n";

    [$status, , $page] = admin_prod_request('GET', $admin . '/production-report.php?search=' . rawurlencode($stamp), null, null, $cookies);
    admin_prod_test($status === 200 && str_contains($page, admin_production_code((int) $current['id'])) && str_contains($page, '70 kg') && !str_contains($page, 'not in the selected reporting period') && !str_contains($page, admin_production_code((int) $palay['id'])), 'The admin page opens on the current month.');
    [$status, , $page] = admin_prod_request('GET', $admin . '/production-report.php?period=previous_month&search=' . rawurlencode($stamp), null, null, $cookies);
    admin_prod_test($status === 200 && str_contains($page, 'Production Report') && str_contains($page, 'href="production-report.php" aria-current="page"'), 'Report page opens from the Reports section of the sidebar.');
    admin_prod_test(str_contains($page, 'href="damage-reports.php"'), 'Damage Reports stays in the sidebar.');
    foreach ([$palay, $eggs, $tilapia, $goat, $milkfish] as $record) admin_prod_test(str_contains($page, admin_production_code((int) $record['id'])), 'Page lists ' . $record['commodityName']);
    admin_prod_test(!str_contains($page, admin_production_code((int) $rejected['id'])) && !str_contains($page, admin_production_code((int) $current['id'])), 'Rejected and current-month records are not in the previous-month list.');
    admin_prod_test(str_contains($page, '850 kg') && str_contains($page, '5 head · 300 pcs') && str_contains($page, $previous->format('F Y')), 'Summary shows kg and other units apart, for the previous month.');
    admin_prod_test(!str_contains($page, $created['a']['email']) && !str_contains($page, $created['a']['contact']), 'No farmer email or phone on the page.');
    admin_prod_test(str_contains($page, '1 production record</strong> harvested in ' . $thisMonth->format('F Y') . ' (current month) is not in the selected reporting period.') && str_contains($page, 'View current month'), 'An earlier period points to records harvested this month.');
    [, , $page] = admin_prod_request('GET', $admin . '/production-report.php?period=current_month&search=' . rawurlencode($stamp), null, null, $cookies);
    admin_prod_test(str_contains($page, admin_production_code((int) $current['id'])) && str_contains($page, '70 kg'), 'Current month on request.');
    [, , $page] = admin_prod_request('GET', $admin . '/production-report.php?period=previous_month&category=Crops&commodity_id=' . $commodity['Tilapia']['id'] . '&search=' . rawurlencode($stamp), null, null, $cookies);
    admin_prod_test(str_contains($page, 'commodity filter was cleared') && str_contains($page, admin_production_code((int) $palay['id'])), 'Incompatible commodity cleared on the page too.');
    [$status, , $page] = admin_prod_request('GET', $admin . '/production-report.php?period=custom&date_from=2026-09-12&date_to=2026-09-01', null, null, $cookies);
    admin_prod_test($status === 200 && str_contains($page, 'ends before it starts'), 'Invalid custom range explained.');
    [$status, , $page] = admin_prod_request('GET', $admin . '/production-report.php?search=' . rawurlencode($stamp) . '&sort=quantity&dir=asc&page=99', null, null, $cookies);
    admin_prod_test($status === 302, 'Out-of-range page redirects to the last page.');
    [$status] = admin_prod_request('GET', $admin . '/damage-reports.php', null, null, $cookies);
    admin_prod_test($status === 200, 'Damage Reports page still opens.');
    echo "PASS: admin page shows the mobile records, summaries, current month, cleared incompatible commodity, invalid range message, page clamp; Damage Reports intact\n";

    // Lookups: farmers and farm areas, searchable, no contact details.
    [$status, $payload] = admin_prod_request('GET', $admin . '/production-report-data.php?lookup=farmers&q=' . rawurlencode($stamp), null, null, $cookies);
    admin_prod_test($status === 200 && count($payload['data']) === 2 && !str_contains(json_encode($payload), $created['a']['email']) && !str_contains(json_encode($payload), $created['a']['contact']), 'Farmer search, no contact details.');
    [, $payload] = admin_prod_request('GET', $admin . '/production-report-data.php?lookup=farms&q=area&farmer_id=' . $created['a']['id'], null, null, $cookies);
    admin_prod_test(array_column($payload['data'], 'label') == ['Main Area ' . $stamp, 'Poultry Area ' . $stamp] || array_column($payload['data'], 'label') == ['Poultry Area ' . $stamp, 'Main Area ' . $stamp], 'Farm area search within one farmer.');
    admin_prod_test(admin_prod_request('GET', $admin . '/production-report-data.php?lookup=passwords', null, null, $cookies)[0] === 422, 'Unknown lookup rejected.');
    admin_prod_test(admin_prod_request('POST', $admin . '/production-report-data.php?lookup=farmers', null, ['x' => '1'], $cookies)[0] === 405, 'Lookups are read-only.');
    echo "PASS: farmer and farm area lookups\n";

    // CSV export: all matching records with original units; kg total never includes other units.
    [$status, , $csv, $type, $headers] = admin_prod_request('GET', $admin . '/production-report-export.php?period=previous_month&search=' . rawurlencode($stamp) . '&status=all', null, null, $cookies);
    admin_prod_test($status === 200 && str_starts_with($type, 'text/csv') && str_contains($headers, 'attachment; filename="AgriReach-Production-Report-') && str_starts_with($csv, "\xEF\xBB\xBF"), 'CSV download.');
    $lines = array_map(static fn(string $line): array => str_getcsv($line, ',', '"', ''), preg_split('/\r?\n/', trim(substr($csv, 3))));
    $records = array_values(array_filter($lines, static fn($line) => str_starts_with((string) ($line[0] ?? ''), 'PR-')));
    admin_prod_test(count($records) === 6, 'Export has every matching record (status all), not one page.');
    $exported = array_column($records, null, 0);
    $goatRow = $exported[admin_production_code((int) $goat['id'])];
    admin_prod_test($goatRow[7] === '5' && $goatRow[8] === 'Head' && $goatRow[9] === '', 'Head counts exported in their unit, without a kg value.');
    $milkRow = $exported[admin_production_code((int) $milkfish['id'])];
    admin_prod_test($milkRow[7] === '0.1' && $milkRow[8] === 'Metric tons' && $milkRow[9] === '100.00', 'MT exported with its kg equivalent.');
    admin_prod_test(str_starts_with($exported[admin_production_code((int) $palay['id'])][14], "'="), 'Spreadsheet formulas in remarks are neutralised.');
    admin_prod_test($exported[admin_production_code((int) $rejected['id'])][13] === 'No', 'Rejected record marked as not counted.');
    $totals = array_column(array_filter($lines, static fn($line) => count($line) >= 2), 1, 0);
    admin_prod_test(($totals['Kilograms (kg and MT records)'] ?? null) === '850.00' && ($totals['Head (not added to kilograms)'] ?? null) === '5' && ($totals['Pieces (not added to kilograms)'] ?? null) === '300', 'Export totals per unit.');
    admin_prod_test(str_contains($csv, 'Reporting period (by harvest/production date)') && str_contains($csv, $previous->format('F Y')), 'Export states the reporting period.');
    admin_prod_test(admin_prod_request('GET', $admin . '/production-report-export.php?period=custom&date_from=x&date_to=y', null, null, $cookies)[0] === 422, 'Invalid export filters rejected.');
    echo "PASS: CSV export (all matching records, original units, kg totals apart, formula-safe, period stated)\n";

    // A new mobile submission appears on the next admin load (no copy or sync step).
    $fresh = $submit('b', 'pond', 'Tilapia', $p(20), '12.5', 'kg');
    [, , $page] = admin_prod_request('GET', $admin . '/production-report.php?period=previous_month&search=' . rawurlencode($stamp), null, null, $cookies);
    admin_prod_test(str_contains($page, admin_production_code((int) $fresh['id'])) && str_contains($page, '862.5 kg'), 'A new mobile record appears on refresh and updates the total.');
    echo "PASS: a new farmer submission appears on the next admin refresh with updated totals\n";
} finally {
    foreach ($created as $farmer) {
        // Their farms, sessions and production records go with them (ON DELETE CASCADE).
        $connection->prepare('DELETE FROM farmers WHERE id = ? AND email = ?')->execute([$farmer['id'], $farmer['email']]);
    }
    if ($adminId !== null) $connection->prepare('DELETE FROM admins WHERE id = ? AND username = ?')->execute([$adminId, $adminUsername]);
    if (isset($takeover)) test_admin_restore($connection, $takeover);
    if (is_file($cookies)) unlink($cookies);
    foreach ($before as $table => $fingerprint) admin_prod_test(admin_prod_fingerprint($connection, $table) === $fingerprint, "Pre-existing {$table} rows changed.");
    echo "Removed only this run's controlled farmers, farms, sessions, production records and admin.\n";
}
