<?php
declare(strict_types=1);

require_once __DIR__ . '/../support/test_base_url.php';

require_once __DIR__ . '/../../backend/config/database.php';
require_once __DIR__ . '/../../backend/lib/weather_advisories.php';

function api_expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function api_get(string $path, string $token): array
{
    $context = stream_context_create(['http' => [
        'method' => 'GET', 'ignore_errors' => true, 'timeout' => 10,
        'header' => "Accept: application/json\r\nAuthorization: Bearer {$token}\r\n",
    ]]);
    $body = file_get_contents(agrireach_test_base_url() . '/CAPSTONE%20AGRICULTURE/api' . $path, false, $context);
    if ($body === false) throw new RuntimeException('Local Apache API could not be reached.');
    $payload = json_decode($body, true);
    if (!is_array($payload)) throw new RuntimeException('Local API returned invalid JSON.');
    return $payload;
}

$connection = db();
$farmerIds = []; $advisoryIds = [];
try {
    $adminId = (int) $connection->query('SELECT id FROM admins ORDER BY id LIMIT 1')->fetch()['id'];
    api_expect($adminId > 0, 'A test admin is required.');
    $suffix = bin2hex(random_bytes(4));
    $farmerInsert = $connection->prepare("INSERT INTO farmers (full_name, contact_number, email, address, account_status, verification_status) VALUES (?, ?, ?, 'Controlled API test address', 'Active', 'Approved')");
    $farmInsert = $connection->prepare("INSERT INTO farm_profiles (farmer_id, farm_name, farm_type, agricultural_category, farm_address, city_psgc_code, city_name, province_name, barangay_psgc_code, barangay_name, location_review_status) VALUES (?, ?, 'Fishpond', 'Fishpond', ?, ?, 'Cabadbaran City', 'Agusan del Norte', ?, ?, ?)");
    $sessionInsert = $connection->prepare("INSERT INTO farmer_sessions (farmer_id, token_hash, expires_at) VALUES (?, ?, " . db_sql_utc_now($connection) . " + INTERVAL '1' HOUR)");
    $tokens = [];
    foreach ([['Eligible API Farmer', '1600203040', 'Tolosa', 'Verified'], ['Unreviewed API Farmer', '', '', 'Needs Review']] as $index => $record) {
        [$name, $barangayCode, $barangayName, $reviewStatus] = $record;
        $phone = '0997' . str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT); $email = "weather-api-{$index}-{$suffix}@example.test";
        $farmerInsert->execute([$name, $phone, $email]); $farmerId = (int) $connection->lastInsertId(); $farmerIds[] = $farmerId;
        $farmName = "Controlled API Farm {$index}"; $address = ($barangayName ?: 'Ambiguous') . ', Cabadbaran City'; $cityCode = $reviewStatus === 'Verified' ? CABADBARAN_CITY_CODE : '';
        $farmInsert->execute([$farmerId, $farmName, $address, $cityCode, $barangayCode, $barangayName, $reviewStatus]);
        $tokens[$index] = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='); $hash = hash('sha256', $tokens[$index]);
        $sessionInsert->execute([$farmerId, $hash]);
    }

    $today = date('Y-m-d'); $yesterday = date('Y-m-d', strtotime('-1 day')); $tomorrow = date('Y-m-d', strtotime('+1 day')); $future = date('Y-m-d', strtotime('+2 days')); $past = date('Y-m-d', strtotime('-2 days'));
    $insert = $connection->prepare("INSERT INTO weather_advisories (title, agricultural_category, weather_condition, location_coverage, coverage_scope, coverage_city_psgc_code, coverage_city_name, coverage_province_name, advisory_message, effective_date, expiration_date, status, published_at, created_by) VALUES (?, 'Fishpond', 'Heavy Rain', ?, ?, '1600203000', 'Cabadbaran City', 'Agusan del Norte', 'Controlled API recommendation.', ?, ?, 'Active', CURRENT_TIMESTAMP, ?)");
    $make = function (string $title, string $coverage, string $scope, string $effective, ?string $expiration, array $codes = []) use ($connection, $insert, $adminId, &$advisoryIds): int {
        $insert->execute([$title, $coverage, $scope, $effective, $expiration, $adminId]); $id = (int) $connection->lastInsertId(); $advisoryIds[] = $id;
        replace_weather_advisory_barangays($connection, $id, $codes); publish_weather_advisory_notifications($connection, $id, $adminId); return $id;
    };
    $make('Controlled current city', 'Entire Cabadbaran City, Agusan del Norte', 'City', $today, $tomorrow);
    $make('Controlled current Tolosa', 'Tolosa - Cabadbaran City, Agusan del Norte', 'Barangays', $today, $tomorrow, ['1600203040']);
    $make('Controlled current Mabini', 'Mabini - Cabadbaran City, Agusan del Norte', 'Barangays', $today, $tomorrow, ['1600203019']);
    $make('Controlled future city', 'Entire Cabadbaran City, Agusan del Norte', 'City', $future, null);
    $make('Controlled expired city', 'Entire Cabadbaran City, Agusan del Norte', 'City', $past, $yesterday);

    $eligible = api_get('/weather/advisories.php', $tokens[0]);
    api_expect(($eligible['success'] ?? false) === true && count($eligible['data']['items'] ?? []) === 2, 'Eligible farmer advisory filtering failed.');
    $unreviewed = api_get('/weather/advisories.php', $tokens[1]);
    api_expect(($unreviewed['success'] ?? false) === true && count($unreviewed['data']['items'] ?? []) === 0, 'Unreviewed farmer received an advisory.');
    api_expect(($unreviewed['data']['eligibility']['code'] ?? '') === 'location_review_required', 'Unreviewed location did not return an actionable reason.');
    $wrongCategory = api_get('/weather/advisories.php?category=Crops', $tokens[0]);
    api_expect(count($wrongCategory['data']['items'] ?? []) === 0, 'Category isolation failed.');
    api_expect(($wrongCategory['data']['eligibility']['code'] ?? '') === 'category_mismatch', 'Category mismatch reason was not returned.');
    $notifications = api_get('/notifications/index.php', $tokens[0]);
    api_expect(($notifications['success'] ?? false) === true && count(array_filter($notifications['data'] ?? [], static fn(array $item): bool => $item['relatedAdvisoryId'] !== null)) === 2, 'Notification eligibility/timing filtering failed.');
    echo "PASS: authenticated advisory and notification APIs isolate location, barangay, category, and dates\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . PHP_EOL);
    $exitCode = 1;
} finally {
    if ($advisoryIds) { $ids = implode(',', array_map('intval', $advisoryIds)); $connection->query("DELETE FROM weather_advisories WHERE id IN ({$ids})"); }
    if ($farmerIds) { $ids = implode(',', array_map('intval', $farmerIds)); $connection->query("DELETE FROM farmers WHERE id IN ({$ids})"); }
}
exit($exitCode ?? 0);
