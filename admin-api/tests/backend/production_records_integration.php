<?php
declare(strict_types=1);

require_once __DIR__ . '/../support/test_base_url.php';

// Farmer production records end to end over the local test server: authentication and ownership, server-side
// validation, retry/concurrency/duplicate protection, calendar-month totals and crop percentages, month/year
// history, record details, and month arithmetic across a year change.
// Explicitly local-only. Creates only uniquely named .test farmers (and one inactive test commodity) and finally
// removes their own rows; pre-existing rows of the touched tables must be unchanged afterwards.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__, 2) . '/backend/config/database.php';
require_once dirname(__DIR__, 2) . '/backend/lib/production_records.php';

function production_test(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
function production_test_api_base(): string
{
    $base = getenv('AGRIREACH_TEST_API_BASE') ?: agrireach_test_base_url() . '/CAPSTONE%20AGRICULTURE/api';
    if (!in_array(parse_url($base, PHP_URL_HOST), ['127.0.0.1', 'localhost'], true)) throw new RuntimeException('Integration tests require a loopback URL.');
    return rtrim($base, '/');
}
/** @return array{0:int,1:mixed} status, decoded JSON */
function production_api(string $method, string $route, ?string $token, array|string|null $body = null): array
{
    $handle = curl_init(production_test_api_base() . $route);
    $headers = ['Accept: application/json', 'Content-Type: application/json'];
    if ($token !== null) $headers[] = 'Authorization: Bearer ' . $token;
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 60]);
    if ($body !== null) curl_setopt($handle, CURLOPT_POSTFIELDS, is_array($body) ? json_encode($body) : $body);
    $raw = curl_exec($handle);
    if ($raw === false) throw new RuntimeException('Local test HTTP connection failed (no credentials logged).');
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);
    return [$status, json_decode((string) $raw, true)];
}
function production_parallel(string $token, array $body): array
{
    $multi = curl_multi_init();
    $handles = [];
    try {
        for ($i = 0; $i < 2; $i++) {
            $handle = curl_init(production_test_api_base() . '/production/index.php');
            curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body),
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Accept: application/json', 'Content-Type: application/json'], CURLOPT_TIMEOUT => 60]);
            curl_multi_add_handle($multi, $handle);
            $handles[] = $handle;
        }
        do { curl_multi_exec($multi, $running); if ($running) curl_multi_select($multi, 0.1); } while ($running);
        return array_map(static fn($handle) => [(int) curl_getinfo($handle, CURLINFO_HTTP_CODE), json_decode(curl_multi_getcontent($handle), true)], $handles);
    } finally {
        foreach ($handles as $handle) { curl_multi_remove_handle($multi, $handle); curl_close($handle); }
        curl_multi_close($multi);
    }
}
function production_fingerprint(PDO $connection, string $table): string
{
    return hash('sha256', json_encode($connection->query('SELECT * FROM ' . $table . ' ORDER BY id')->fetchAll()));
}
function production_count(PDO $connection, int $farmerId): int
{
    $statement = $connection->prepare('SELECT COUNT(*) FROM production_records WHERE farmer_id = ?');
    $statement->execute([$farmerId]);
    return (int) $statement->fetchColumn();
}

// Month arithmetic (no database): previous month across a year change, labels and month starts.
$tz = new DateTimeZone('Asia/Manila');
production_test(production_previous_month(new DateTimeImmutable('2027-01-01', $tz))->format('Y-m') === '2026-12', 'January 2027 must give December 2026.');
production_test(production_previous_month(new DateTimeImmutable('2026-10-01', $tz))->format('Y-m') === '2026-09', 'October 2026 must give September 2026.');
production_test(production_previous_month(new DateTimeImmutable('2026-03-01', $tz))->format('Y-m') === '2026-02', 'March must give February (no day overflow).');
production_test(production_month_start(new DateTimeImmutable('2026-10-31 23:59:00', $tz))->format('Y-m-d') === '2026-10-01', 'Month start of Oct 31 is Oct 1.');
production_test(production_parse_month('2026-13') === null && production_parse_month('1999-12') === null && production_parse_month('2026-1') === null, 'Invalid months rejected.');
foreach (['125.5' => '125.5', '0.01' => '0.01', '1000000' => '1000000', ' 42 ' => '42'] as $input => $expected) production_test(production_quantity($input) === $expected, "Quantity {$input} accepted.");
foreach (['0', '0.00', '-5', 'abc', '1.234', '1e3', '1000000.01', '12 kg', '', '١٢'] as $input) production_test(production_quantity($input) === null, "Quantity {$input} rejected.");
production_test(production_quantity(true) === null && production_quantity([]) === null, 'Non-numeric JSON types rejected.');
echo "PASS: month arithmetic (incl. Jan 2027 -> Dec 2026) and quantity rules\n";

$connection = db();
$tables = ['farmers', 'farm_profiles', 'damage_reports', 'production_records', 'commodities'];
$before = [];
foreach ($tables as $table) $before[$table] = production_fingerprint($connection, $table);
$stamp = bin2hex(random_bytes(8));
$created = [];
$inactiveCommodityId = null;
try {
    foreach (['a', 'b'] as $name) {
        $email = 'production-' . $stamp . '-' . $name . '@example.test';
        $contact = '+639' . str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
        $statement = $connection->prepare("INSERT INTO farmers (full_name, contact_number, email, address, account_status, verification_status) VALUES ('Controlled Production Test', ?, ?, 'Controlled test address', 'Active', 'Approved')");
        $statement->execute([$contact, $email]);
        $farmerId = (int) $connection->lastInsertId();
        $created[$name] = ['id' => $farmerId, 'email' => $email, 'farms' => []];
        foreach (['main' => 'Active', 'second' => 'Active', 'archived' => 'Archived'] as $farmKey => $status) {
            $statement = $connection->prepare("INSERT INTO farm_profiles (farmer_id, farm_name, farm_type, agricultural_category, farm_address, status) VALUES (?, ?, 'Crops', 'Crops', 'Controlled Cabadbaran test', ?)");
            $statement->execute([$farmerId, 'Controlled ' . $farmKey . ' farm ' . $name, $status]);
            $created[$name]['farms'][$farmKey] = (int) $connection->lastInsertId();
        }
        $token = bin2hex(random_bytes(32));
        $statement = $connection->prepare('INSERT INTO farmer_sessions (farmer_id, token_hash, expires_at) VALUES (?, ?, ' . db_sql_utc_now($connection) . " + INTERVAL '1' HOUR)");
        $statement->execute([$farmerId, hash('sha256', $token)]);
        $created[$name]['token'] = $token;
    }
    $statement = $connection->prepare("INSERT INTO commodities (name, agricultural_category, commodity_group, status) VALUES (?, 'Crops', 'Vegetables', 'Inactive')");
    $statement->execute(['Controlled inactive ' . $stamp]);
    $inactiveCommodityId = (int) $connection->lastInsertId();

    $a = $created['a'];
    $b = $created['b'];
    $token = $a['token'];

    // Authentication is required for every read and write.
    production_test(production_api('GET', '/production/index.php', null)[0] === 401, 'Summary without a session must be 401.');
    production_test(production_api('POST', '/production/index.php', null, ['quantityKg' => '1'])[0] === 401, 'Submission without a session must be 401.');
    production_test(production_api('GET', '/production/index.php', 'x' . str_repeat('0', 63))[0] === 401, 'Unknown session must be 401.');
    production_test(production_api('PUT', '/production/index.php', $token)[0] === 405, 'PUT must be 405.');
    echo "PASS: authentication required (401) and unsupported methods rejected (405)\n";

    // The form's catalog comes from the database: categories in form order, units, active commodities only.
    [$status, $payload] = production_api('GET', '/production/index.php?view=catalog', $token);
    production_test($status === 200 && is_array($payload['data']['commodities'] ?? null), 'Catalog must load.');
    $catalog = $payload['data'];
    production_test(array_column($catalog['categories'], 'code') === ['Crops', 'Livestock', 'Poultry', 'Fishpond'] && $catalog['categories'][3]['label'] === 'Aquaculture / Fishpond', 'Four production categories in form order.');
    $units = array_column($catalog['units'], null, 'code');
    production_test(array_keys($units) === ['kg', 'mt', 'head', 'piece', 'tray', 'liter'] && $units['mt']['kgPerUnit'] == 1000 && $units['head']['kgPerUnit'] === null && $units['head']['decimals'] === 0, 'Units: only kg and MT convert to kilograms.');
    $commodities = [];
    foreach ($catalog['commodities'] as $commodity) {
        production_test(!isset($commodities[$commodity['category'] . '|' . $commodity['name']]), 'No duplicate commodity ' . $commodity['name']);
        production_test(in_array($commodity['defaultUnit'], $commodity['units'], true) && $commodity['units'][0] === $commodity['defaultUnit'] && $commodity['group'] !== '' && $commodity['searchNames'] !== '', 'Complete catalog row ' . $commodity['name']);
        $commodities[$commodity['category'] . '|' . $commodity['name']] = $commodity;
    }
    $active = $connection->query("SELECT COUNT(*) FROM commodities WHERE status = 'Active'")->fetchColumn();
    production_test(count($catalog['commodities']) === (int) $active, 'Every active commodity (and only those) is listed.');
    production_test(!isset($commodities['Crops|Controlled inactive ' . $stamp]) && !isset($commodities['Crops|Corn']) && isset($commodities['Crops|White Corn']), 'Inactive commodities are not offered.');
    $pick = static function (string $key) use ($commodities): array {
        production_test(isset($commodities[$key]), "Catalog must contain {$key}.");
        return $commodities[$key];
    };
    [$cropA, $cropB, $cropC] = [$pick('Crops|Palay (Rice)'), $pick('Crops|White Corn'), $pick('Crops|Banana (Saba)')];
    // 0006 seeded Rice first; 0007 renamed it in place, so Palay (Rice) still has the lowest commodity ID.
    production_test($cropA['id'] === (int) $connection->query('SELECT MIN(id) FROM commodities')->fetchColumn(), 'Palay (Rice) keeps the ID of the original Rice commodity.');
    $eggs = $pick('Poultry|Chicken Eggs');
    $goat = $pick('Livestock|Native Goat');
    $milk = $pick("Livestock|Cow's Milk");
    $tilapia = $pick('Fishpond|Tilapia');
    production_test($eggs['units'] === ['piece', 'tray'] && $goat['units'] === ['head', 'kg'] && $milk['units'] === ['liter'] && $cropA['units'] === ['kg', 'mt'] && $tilapia['units'] === ['kg', 'mt'], 'Units match product types.');
    [$status, $payload] = production_api('GET', '/production/index.php?view=commodities', $token);
    production_test($status === 200 && count($payload['data']) === count($catalog['commodities']), 'The older commodities view still works.');
    echo "PASS: catalog = 4 categories, 6 units, " . count($catalog['commodities']) . " active commodities (no duplicates, default unit allowed); inactive excluded; original IDs kept\n";

    // Empty farmer: zero totals, no crops, no division by zero.
    [$status, $payload] = production_api('GET', '/production/index.php', $token);
    $summary = $payload['data'];
    production_test($status === 200 && $summary['currentMonth']['totalKg'] == 0 && $summary['currentMonth']['crops'] === [] && $summary['previousMonth']['totalKg'] == 0 && $summary['recent'] === [], 'A new farmer has empty totals.');
    $today = production_today();
    $currentMonth = production_month_start($today);
    $previousMonth = production_previous_month($currentMonth);
    production_test($summary['today'] === $today->format('Y-m-d') && $summary['currentMonth']['month'] === $currentMonth->format('Y-m') && $summary['previousMonth']['month'] === $previousMonth->format('Y-m'), 'Summary months follow the Manila calendar.');
    production_test($summary['currentMonth']['label'] === $currentMonth->format('F Y') && $summary['years'] === [(int) $today->format('Y')], 'Month labels and the default year.');
    echo "PASS: empty summary ({$summary['currentMonth']['label']} / {$summary['previousMonth']['label']}) is 0 kg without errors\n";

    $valid = static fn(array $overrides = []): array => array_merge([
        'farmId' => $a['farms']['main'], 'category' => 'Crops', 'commodityId' => $cropA['id'], 'harvestDate' => $today->format('Y-m-d'),
        'quantity' => '125.5', 'unitCode' => 'kg', 'remarks' => '', 'clientSubmissionId' => 'production_test_' . bin2hex(random_bytes(8)),
    ], $overrides);

    // Validation: each rejection names its field and stores nothing.
    $tomorrow = $today->modify('+1 day')->format('Y-m-d');
    $invalid = [
        [['farmId' => null], 'farmId'], [['farmId' => $b['farms']['main']], 'farmId'], [['farmId' => $a['farms']['archived']], 'farmId'], [['farmId' => '1 OR 1=1'], 'farmId'],
        [['category' => null], 'category'], [['category' => 'Aquaculture'], 'category'], [['category' => ['Crops']], 'category'],
        [['commodityId' => null], 'commodityId'], [['commodityId' => 'abc'], 'commodityId'], [['commodityId' => 2147480000], 'commodityId'],
        [['commodityId' => $inactiveCommodityId], 'commodityId'], [['commodityId' => true], 'commodityId'], [['commodityId' => 2], 'commodityId'],
        // A commodity from another category than the one selected.
        [['commodityId' => $goat['id']], 'commodityId'], [['category' => 'Poultry'], 'commodityId'], [['category' => 'Fishpond', 'commodityId' => $eggs['id']], 'commodityId'],
        [['harvestDate' => ''], 'harvestDate'], [['harvestDate' => '2026-02-30'], 'harvestDate'], [['harvestDate' => $tomorrow], 'harvestDate'],
        [['harvestDate' => '1999-12-31'], 'harvestDate'], [['harvestDate' => '23/10/2026'], 'harvestDate'], [['harvestDate' => 20261023], 'harvestDate'],
        [['quantity' => ''], 'quantity'], [['quantity' => '0'], 'quantity'], [['quantity' => '-5'], 'quantity'], [['quantity' => 'abc'], 'quantity'],
        [['quantity' => '1.234'], 'quantity'], [['quantity' => '1e3'], 'quantity'], [['quantity' => '1000000.01'], 'quantity'], [['quantity' => '12 kg'], 'quantity'],
        [['quantity' => null], 'quantity'],
        // Units: only the commodity's own units, with that unit's decimals and the kilogram limit.
        [['unitCode' => null], 'unitCode'], [['unitCode' => 'liter'], 'unitCode'], [['unitCode' => 'head'], 'unitCode'], [['unitCode' => 'ton'], 'unitCode'],
        [['category' => 'Poultry', 'commodityId' => $eggs['id'], 'unitCode' => 'kg', 'quantity' => '10'], 'unitCode'],
        [['category' => 'Poultry', 'commodityId' => $eggs['id'], 'unitCode' => 'piece', 'quantity' => '10.5'], 'quantity'],
        [['category' => 'Livestock', 'commodityId' => $goat['id'], 'unitCode' => 'head', 'quantity' => '2.5'], 'quantity'],
        [['unitCode' => 'mt', 'quantity' => '1.2345'], 'quantity'], [['unitCode' => 'mt', 'quantity' => '1000.001'], 'quantity'],
        [['remarks' => str_repeat('x', 1001)], 'remarks'], [['remarks' => "bad\x07bell"], 'remarks'], [['remarks' => ['array']], 'remarks'],
        [['clientSubmissionId' => 'short'], 'form'], [['clientSubmissionId' => null], 'form'],
    ];
    foreach ($invalid as [$overrides, $field]) {
        [$status, $payload] = production_api('POST', '/production/index.php', $token, $valid($overrides));
        production_test($status === 422 && isset($payload['errors'][$field]), 'Expected 422 on ' . $field . ' for ' . json_encode($overrides) . ', got ' . $status . ' ' . json_encode($payload['errors'] ?? null));
    }
    [$status] = production_api('POST', '/production/index.php', $token, 'not json');
    production_test($status === 400, 'A non-JSON body must be 400.');
    [$status, $payload] = production_api('POST', '/production/index.php', $token, []);
    production_test($status === 422 && count(array_intersect(['farmId', 'category', 'commodityId', 'harvestDate', 'quantity', 'unitCode', 'form'], array_keys($payload['errors']))) === 7, 'An empty submission reports every required field: ' . json_encode(array_keys($payload['errors'])));
    [$status, $payload] = production_api('POST', '/production/index.php', $token, $valid(['quantity' => '1000000.5']));
    production_test($status === 422 && str_contains($payload['errors']['quantity'], '1,000,000'), 'Over-limit quantity explains the limit.');
    [$status, $payload] = production_api('POST', '/production/index.php', $token, $valid(['unitCode' => 'mt', 'quantity' => '1000.5']));
    production_test($status === 422 && str_contains($payload['errors']['quantity'], '1,000 MT'), 'Over-limit metric tons explain the kilogram limit.');
    production_test(production_count($connection, $a['id']) === 0, 'Rejected submissions must not store anything.');
    echo "PASS: " . (count($invalid) + 4) . " invalid submissions rejected with field errors (other farmer's/archived farm, wrong category for the commodity, wrong unit, decimals per unit, future date); nothing stored\n";

    // Valid submission. The farmer comes from the session; a farmerId in the body is ignored.
    $first = $valid(['quantity' => '125.50', 'remarks' => "  First harvest of the season.\r\nDry weather.  ", 'farmerId' => $b['id']]);
    [$status, $payload] = production_api('POST', '/production/index.php', $token, $first);
    $record = $payload['data']['record'] ?? null;
    production_test($status === 201 && $payload['data']['alreadySaved'] === false && $record, 'A valid submission must be 201.');
    production_test($record['commodityId'] === $cropA['id'] && $record['commodityName'] === $cropA['name'] && $record['farmId'] === $a['farms']['main'] && $record['quantity'] == 125.5 && $record['unitCode'] === 'kg' && $record['unitSymbol'] === 'kg' && $record['quantityKg'] == 125.5
        && $record['harvestDate'] === $today->format('Y-m-d') && $record['remarks'] === "First harvest of the season.\nDry weather." && $record['status'] === 'Submitted' && $record['counted'] === true, 'Saved record fields.');
    $statement = $connection->prepare('SELECT farmer_id, farm_profile_id, commodity_id, harvest_date, quantity, unit_code, quantity_kg, remarks, status, client_submission_id FROM production_records WHERE id = ?');
    $statement->execute([(int) $record['id']]);
    $row = $statement->fetch();
    production_test((int) $row['farmer_id'] === $a['id'] && (string) $row['harvest_date'] === $today->format('Y-m-d') && production_centikilograms($row['quantity_kg']) === 12550 && production_milliunits($row['quantity']) === 125500 && $row['unit_code'] === 'kg'
        && $row['client_submission_id'] === $first['clientSubmissionId'] && $row['status'] === 'Submitted', 'The database row belongs to the authenticated farmer with exact values.');
    echo "PASS: valid record persisted for the session's farmer (body farmerId ignored), remarks normalised\n";

    // Retry of the same form returns the saved record; two concurrent retries still store one row.
    [$status, $payload] = production_api('POST', '/production/index.php', $token, $first);
    production_test($status === 200 && $payload['data']['alreadySaved'] === true && $payload['data']['record']['id'] === $record['id'], 'A retry returns the saved record.');
    $concurrent = $valid(['commodityId' => $cropB['id'], 'quantity' => '7']);
    $results = production_parallel($token, $concurrent);
    $statuses = array_column($results, 0);
    sort($statuses);
    production_test($statuses === [200, 201], 'One concurrent retry saves (201), the other returns that record (200): ' . json_encode($statuses));
    $ids = array_unique(array_map(static fn($result) => $result[1]['data']['record']['id'] ?? null, $results));
    production_test(count($ids) === 1 && production_count($connection, $a['id']) === 2, 'Concurrent retries of one form store one row.');
    echo "PASS: retries and concurrent double submission stored exactly one row each\n";

    // Same farm, commodity, date and quantity from a new form needs explicit confirmation.
    $repeat = $valid(['quantity' => '125.5']);
    [$status, $payload] = production_api('POST', '/production/index.php', $token, $repeat);
    production_test($status === 409 && isset($payload['errors']['duplicate']) && str_contains($payload['errors']['duplicate'], '125.5 kg'), 'An identical record must ask for confirmation (409).');
    production_test(production_count($connection, $a['id']) === 2, 'The unconfirmed repeat is not stored.');
    [$status, $payload] = production_api('POST', '/production/index.php', $token, $repeat + ['confirmDuplicate' => 'yes']);
    production_test($status === 409, 'Only confirmDuplicate=true confirms.');
    [$status, $payload] = production_api('POST', '/production/index.php', $token, ['confirmDuplicate' => true] + $repeat);
    production_test($status === 201 && production_count($connection, $a['id']) === 3, 'A confirmed separate harvest is stored.');
    $repeatId = (int) $payload['data']['record']['id'];
    [$status] = production_api('POST', '/production/index.php', $token, $valid(['quantity' => '0.1255', 'unitCode' => 'mt']));
    production_test($status === 422, 'MT allows three decimals only.');
    [$status, $payload] = production_api('POST', '/production/index.php', $token, $valid(['quantity' => '0.126', 'unitCode' => 'mt']));
    production_test($status === 201 && $payload['data']['record']['unitSymbol'] === 'MT' && $payload['data']['record']['quantityKg'] == 126, 'Same crop in MT is a different record and converts to kg exactly.');
    echo "PASS: possible duplicate (same farm/commodity/date/quantity/unit) requires confirmation; confirmed one saved; MT converts to kg\n";

    // Older app builds send quantityKg without a category or unit: still saved, as kilograms.
    $legacy = ['farmId' => $a['farms']['main'], 'commodityId' => $cropB['id'], 'harvestDate' => $today->format('Y-m-d'), 'quantityKg' => '42.5', 'remarks' => '', 'clientSubmissionId' => 'production_legacy_' . bin2hex(random_bytes(8))];
    [$status, $payload] = production_api('POST', '/production/index.php', $token, $legacy);
    production_test($status === 201 && $payload['data']['record']['unitCode'] === 'kg' && $payload['data']['record']['quantityKg'] == 42.5, 'An older app submission is saved in kg.');
    [$status, $payload] = production_api('POST', '/production/index.php', $token, ['quantityKg' => 'abc', 'commodityId' => $eggs['id'], 'clientSubmissionId' => 'production_legacy_' . bin2hex(random_bytes(8))] + $legacy);
    production_test($status === 422 && isset($payload['errors']['quantityKg'], $payload['errors']['unitCode']), 'Older app errors keep the quantityKg field name; eggs cannot be recorded in kg.');
    // Mixed farming: a Crops farm may record poultry products when the category and commodity agree.
    [$status, $payload] = production_api('POST', '/production/index.php', $token, $valid(['category' => 'Poultry', 'commodityId' => $eggs['id'], 'quantity' => '120', 'unitCode' => 'piece']));
    production_test($status === 201 && $payload['data']['record']['quantityKg'] === null && $payload['data']['record']['unitSymbol'] === 'pcs' && $payload['data']['record']['categoryLabel'] === 'Poultry', 'Eggs are saved in pieces without a kilogram value.');
    echo "PASS: older app (quantityKg) still saves as kg; mixed farming allowed when category and commodity agree; counts have no kg value\n";

    // Remove the setup rows so the month figures below are exact, then build a known month.
    $connection->prepare('DELETE FROM production_records WHERE farmer_id = ?')->execute([$a['id']]);
    $lastOfPrevious = $currentMonth->modify('-1 day');
    $twoMonthsAgo = production_previous_month($previousMonth);
    $lastYear = (int) $today->format('Y') - 1;
    $submit = static function (array $commodity, string $date, string $quantity, ?string $farmKey = 'main', string $unit = 'kg') use ($token, $valid, $a): array {
        [$status, $payload] = production_api('POST', '/production/index.php', $token, $valid(['category' => $commodity['category'], 'commodityId' => $commodity['id'], 'harvestDate' => $date, 'quantity' => $quantity, 'unitCode' => $unit, 'farmId' => $a['farms'][$farmKey]]));
        production_test($status === 201, "Seed record {$commodity['name']} {$date} {$quantity} {$unit} must save (got {$status}).");
        return $payload['data']['record'];
    };
    // Current month: 600 + 350 + 300 = 1,250 kg -> 48% / 28% / 24%, split across two records, two farms and kg/MT.
    $submit($cropA, $currentMonth->format('Y-m-d'), '400');
    $submit($cropA, $today->format('Y-m-d'), '0.2', 'second', 'mt');
    $submit($cropB, $today->format('Y-m-d'), '350');
    $submit($cropC, $currentMonth->format('Y-m-d'), '300');
    // Same month in other units: never added to the kilograms, nor to each other.
    $submit($eggs, $today->format('Y-m-d'), '1200', 'main', 'piece');
    $submit($eggs, $currentMonth->format('Y-m-d'), '30', 'main', 'tray');
    $submit($goat, $today->format('Y-m-d'), '5', 'main', 'head');
    $submit($milk, $today->format('Y-m-d'), '30.5', 'main', 'liter');
    // Previous month (incl. its last day): 80 kg + 0.02 MT = 100 kg. Two months ago: 55 kg. Last January: 12.5 kg.
    $submit($cropB, $lastOfPrevious->format('Y-m-d'), '80');
    $submit($cropB, $previousMonth->format('Y-m-d'), '0.02', 'main', 'mt');
    $submit($cropC, $twoMonthsAgo->format('Y-m-d'), '55');
    $submit($cropA, sprintf('%04d-01-15', $lastYear), '12.5');
    // A rejected record stays visible but is never counted.
    $rejected = $submit($cropC, $today->format('Y-m-d'), '999');
    $connection->prepare("UPDATE production_records SET status = 'Rejected' WHERE id = ?")->execute([(int) $rejected['id']]);

    [$status, $payload] = production_api('GET', '/production/index.php', $token);
    $summary = $payload['data'];
    $current = $summary['currentMonth'];
    production_test($status === 200 && $current['totalKg'] == 1250 && $current['recordCount'] === 8, 'Current month total must be 1,250 kg; 8 counted records in all units, got ' . json_encode($current));
    $other = array_map(static fn($total) => [$total['unitCode'], $total['total'], $total['recordCount']], $current['otherTotals']);
    production_test($other == [['head', 5, 1], ['piece', 1200, 1], ['tray', 30, 1], ['liter', 30.5, 1]], 'Other units are totalled separately per unit: ' . json_encode($other));
    $measures = array_map(static fn($measure) => $measure['name'] . ' ' . $measure['total'] . ' ' . $measure['unitSymbol'], $current['otherMeasures']);
    production_test($measures === ['Native Goat 5 head', 'Chicken Eggs 1200 pcs', 'Chicken Eggs 30 trays', "Cow's Milk 30.5 L"], 'Per-commodity other-unit totals: ' . json_encode($measures));
    $byName = array_column($current['crops'], null, 'name');
    production_test(array_column($current['crops'], 'name') === [$cropA['name'], $cropB['name'], $cropC['name']], 'Crops are ordered by kilograms, largest first.');
    production_test($byName[$cropA['name']]['totalKg'] == 600 && $byName[$cropA['name']]['percent'] == 48.0 && $byName[$cropA['name']]['recordCount'] === 2, 'Crop A: 600 kg, 48%.');
    production_test($byName[$cropB['name']]['totalKg'] == 350 && $byName[$cropB['name']]['percent'] == 28.0, 'Crop B: 350 kg, 28%.');
    production_test($byName[$cropC['name']]['totalKg'] == 300 && $byName[$cropC['name']]['percent'] == 24.0, 'Crop C: 300 kg, 24% (rejected 999 kg excluded).');
    production_test($summary['previousMonth']['totalKg'] == 100 && $summary['previousMonth']['recordCount'] === 2 && count($summary['previousMonth']['crops']) === 1 && $summary['previousMonth']['crops'][0]['percent'] == 100.0, 'Previous month = 100 kg incl. its last day; nothing leaks into the current month.');
    production_test(count($summary['recent']) === 5 && $summary['recent'][0]['harvestDate'] === $today->format('Y-m-d'), 'Five most recent records, newest harvest first.');
    production_test(in_array($lastYear, $summary['years'], true) && $summary['years'][0] === (int) $today->format('Y'), 'Years with records are offered, newest first.');
    echo "PASS: current month {$current['totalKg']} kg (48% / 28% / 24%), previous month 100 kg incl. its last day, rejected excluded\n";

    // Month detail: total, breakdown and its records (the rejected one listed, flagged as not counted).
    [$status, $payload] = production_api('GET', '/production/index.php?view=month&month=' . $currentMonth->format('Y-m'), $token);
    $month = $payload['data'];
    production_test($status === 200 && $month['totalKg'] == 1250 && count($month['records']) === 9 && count($month['otherTotals']) === 4, 'Month view lists all nine of its records and the other-unit totals.');
    $mtRecord = array_values(array_filter($month['records'], static fn($item) => $item['unitCode'] === 'mt'))[0];
    production_test($mtRecord['quantity'] == 0.2 && $mtRecord['quantityKg'] == 200 && $mtRecord['unitSymbol'] === 'MT', 'A metric-ton record keeps its MT quantity and its kg equivalent.');
    $rejectedRow = array_values(array_filter($month['records'], static fn($item) => $item['id'] === $rejected['id']))[0] ?? null;
    production_test($rejectedRow && $rejectedRow['status'] === 'Rejected' && $rejectedRow['counted'] === false, 'The rejected record is listed as not counted.');
    $dates = array_column($month['records'], 'harvestDate');
    $sorted = $dates;
    rsort($sorted);
    production_test($dates === $sorted, 'Month records are newest harvest first.');
    [$status, $payload] = production_api('GET', '/production/index.php?view=month&month=' . $twoMonthsAgo->format('Y-m'), $token);
    production_test($status === 200 && $payload['data']['totalKg'] == 55 && $payload['data']['crops'][0]['percent'] == 100.0, 'Two months ago = 55 kg.');
    [$status, $payload] = production_api('GET', '/production/index.php?view=month&month=2005-06', $token);
    production_test($status === 200 && $payload['data']['totalKg'] == 0 && $payload['data']['records'] === [] && $payload['data']['label'] === 'June 2005', 'An empty month is 0 kg with no records.');
    foreach (['2026-13', 'abc', '', $currentMonth->modify('first day of next month')->format('Y-m'), '1999-12'] as $badMonth) {
        production_test(production_api('GET', '/production/index.php?view=month&month=' . rawurlencode($badMonth), $token)[0] === 422, "Month {$badMonth} must be 422.");
    }
    echo "PASS: month detail totals/records, empty month, invalid and future months rejected\n";

    // Year view: 12 months; the history chart uses these totals.
    [$status, $payload] = production_api('GET', '/production/index.php?view=year&year=' . $today->format('Y'), $token);
    $year = $payload['data'];
    production_test($status === 200 && count($year['months']) === 12 && $year['year'] === (int) $today->format('Y'), 'Year view returns 12 months.');
    $monthsByKey = array_column($year['months'], null, 'month');
    production_test($monthsByKey[$currentMonth->format('Y-m')]['totalKg'] == 1250 && $monthsByKey[$currentMonth->format('Y-m')]['isFuture'] === false && $monthsByKey[$currentMonth->format('Y-m')]['recordCount'] === 8, 'Year view: current month total.');
    production_test(array_column($monthsByKey[$currentMonth->format('Y-m')]['otherTotals'], 'total', 'unitCode') == ['head' => 5, 'piece' => 1200, 'tray' => 30, 'liter' => 30.5], 'Year view: other units per month, kept apart.');
    production_test(array_column($year['otherTotals'], 'total', 'unitCode') == ['head' => 5, 'piece' => 1200, 'tray' => 30, 'liter' => 30.5], 'Year view: other units for the year, kept apart.');
    if ($previousMonth->format('Y') === $today->format('Y')) production_test($monthsByKey[$previousMonth->format('Y-m')]['totalKg'] == 100, 'Year view: previous month total.');
    $expectedYearTotal = 0;
    foreach ([[$currentMonth, 1250], [$previousMonth, 100], [$twoMonthsAgo, 55]] as [$monthStart, $kg]) if ($monthStart->format('Y') === $today->format('Y')) $expectedYearTotal += $kg;
    production_test($year['totalKg'] == $expectedYearTotal, 'Year total = sum of its months.');
    production_test($year['months'][11]['shortLabel'] === 'Dec' && ($currentMonth->format('m') === '12' || $year['months'][11]['isFuture'] === true), 'Later months of this year are flagged as future.');
    [$status, $payload] = production_api('GET', '/production/index.php?view=year&year=' . $lastYear, $token);
    // Two months ago is never January of last year, so last January holds only the 12.5 kg record.
    production_test($status === 200 && $payload['data']['months'][0]['totalKg'] == 12.5 && $payload['data']['months'][0]['label'] === 'January ' . $lastYear, 'Last year: January total.');
    foreach (['abc', '1999', (string) ((int) $today->format('Y') + 1)] as $badYear) production_test(production_api('GET', '/production/index.php?view=year&year=' . $badYear, $token)[0] === 422, "Year {$badYear} must be 422.");
    production_test(production_api('GET', '/production/index.php?view=unknown', $token)[0] === 422, 'Unknown view must be 422.');
    echo "PASS: year view 12 months (total {$year['totalKg']} kg), last year's January, invalid years rejected\n";

    // Record details and ownership.
    [$status, $payload] = production_api('GET', '/production/index.php?id=' . $rejected['id'], $token);
    production_test($status === 200 && $payload['data']['id'] === $rejected['id'] && $payload['data']['farmName'] === 'Controlled main farm a', 'Own record details load with the farm name.');
    production_test(production_api('GET', '/production/index.php?id=' . $rejected['id'], $b['token'])[0] === 404, "Another farmer's record must be 404.");
    foreach (['abc', '0', '-1', (string) $repeatId, '99999999'] as $badId) production_test(production_api('GET', '/production/index.php?id=' . rawurlencode($badId), $token)[0] === 404, "Record id {$badId} must be 404.");
    [$status, $payload] = production_api('GET', '/production/index.php', $b['token']);
    production_test($status === 200 && $payload['data']['currentMonth']['totalKg'] == 0 && $payload['data']['recent'] === [], "Farmer B sees none of farmer A's production.");
    echo "PASS: record details; other farmers' records are 404 and never counted in their totals\n";

    // An archived farm keeps its earlier records readable (the record does not depend on the farm staying active).
    $connection->prepare("UPDATE farm_profiles SET status = 'Archived' WHERE id = ?")->execute([$a['farms']['second']]);
    [$status, $payload] = production_api('GET', '/production/index.php', $token);
    production_test($status === 200 && $payload['data']['currentMonth']['totalKg'] == 1250, 'Archiving a farm does not change earlier totals.');
    echo "PASS: archiving a farm keeps its earlier production in the totals\n";
} finally {
    foreach ($created as $farmer) {
        // production_records, farm_profiles and farmer_sessions rows go with the farmer (ON DELETE CASCADE).
        $statement = $connection->prepare('DELETE FROM farmers WHERE id = ? AND email = ?');
        $statement->execute([$farmer['id'], $farmer['email']]);
    }
    if ($inactiveCommodityId !== null) {
        $statement = $connection->prepare("DELETE FROM commodities WHERE id = ? AND name = ?");
        $statement->execute([$inactiveCommodityId, 'Controlled inactive ' . $stamp]);
    }
    foreach ($before as $table => $fingerprint) production_test(production_fingerprint($connection, $table) === $fingerprint, "Pre-existing {$table} rows changed.");
    echo "Removed only this run's controlled farmers, their farms, sessions and production records, and the test commodity.\n";
}
