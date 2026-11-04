<?php
declare(strict_types=1);

require_once __DIR__ . '/../../backend/config/database.php';
require_once __DIR__ . '/../../backend/lib/weather_advisories.php';

function expect_value(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$connection = db();
$connection->beginTransaction();
try {
    $adminId = (int) $connection->query('SELECT id FROM admins ORDER BY id LIMIT 1')->fetch()['id'];
    expect_value($adminId > 0, 'A test admin is required.');
    $suffix = bin2hex(random_bytes(4));
    $farmerInsert = $connection->prepare("INSERT INTO farmers (full_name, contact_number, email, address, account_status, verification_status) VALUES (?, ?, ?, 'Controlled test address', 'Active', 'Approved')");
    $farmInsert = $connection->prepare("INSERT INTO farm_profiles (farmer_id, farm_name, farm_type, agricultural_category, farm_address, city_psgc_code, city_name, province_name, barangay_psgc_code, barangay_name, location_review_status) VALUES (?, ?, ?, ?, ?, ?, 'Cabadbaran City', 'Agusan del Norte', ?, ?, ?)");

    $farmers = [
        ['Inside Tolosa Poultry', 'Poultry', '1600203040', 'Tolosa', 'Verified'],
        ['Inside Mabini Crops', 'Crops', '1600203019', 'Mabini', 'Verified'],
        ['Unreviewed Poultry', 'Poultry', '', '', 'Needs Review'],
    ];
    foreach ($farmers as $index => &$item) {
        $name = $item[0]; $phone = '099800' . str_pad((string) random_int(0, 99999), 5, '0', STR_PAD_LEFT); $email = "weather-{$index}-{$suffix}@example.test";
        $farmerInsert->execute([$name, $phone, $email]); $item['id'] = (int) $connection->lastInsertId();
        $farmName = "Controlled {$item[1]} Farm"; $type = $item[1]; $category = $item[1]; $address = ($item[3] ?: 'Ambiguous') . ', Cabadbaran City'; $cityCode = $item[4] === 'Verified' ? CABADBARAN_CITY_CODE : ''; $barangayCode = $item[2]; $barangayName = $item[3]; $status = $item[4];
        $farmInsert->execute([$item['id'], $farmName, $type, $category, $address, $cityCode, $barangayCode, $barangayName, $status]);
    }
    unset($item);

    $today = date('Y-m-d'); $tomorrow = date('Y-m-d', strtotime('+1 day')); $yesterday = date('Y-m-d', strtotime('-1 day')); $future = date('Y-m-d', strtotime('+2 days'));
    $insertAdvisory = $connection->prepare("INSERT INTO weather_advisories (title, agricultural_category, weather_condition, location_coverage, coverage_scope, coverage_city_psgc_code, coverage_city_name, coverage_province_name, advisory_message, effective_date, expiration_date, status, published_at, created_by) VALUES (?, ?, 'Heavy Rain', ?, ?, '1600203000', 'Cabadbaran City', 'Agusan del Norte', 'Controlled recommendation.', ?, ?, ?, CURRENT_TIMESTAMP, ?)");

    $title = 'Controlled city poultry'; $category = 'Poultry'; $coverage = 'Entire Cabadbaran City, Agusan del Norte'; $scope = 'City'; $status = 'Active';
    $insertAdvisory->execute([$title, $category, $coverage, $scope, $yesterday, $tomorrow, $status, $adminId]); $cityId = (int) $connection->lastInsertId();
    expect_value(publish_weather_advisory_notifications($connection, $cityId, $adminId) === 1, 'City/category targeting failed.');
    expect_value(publish_weather_advisory_notifications($connection, $cityId, $adminId) === 0, 'Duplicate prevention failed.');

    $title = 'Controlled Mabini crops'; $category = 'Crops'; $coverage = 'Mabini - Cabadbaran City, Agusan del Norte'; $scope = 'Barangays';
    $insertAdvisory->execute([$title, $category, $coverage, $scope, $today, $tomorrow, $status, $adminId]); $barangayId = (int) $connection->lastInsertId();
    replace_weather_advisory_barangays($connection, $barangayId, ['1600203019']);
    expect_value(publish_weather_advisory_notifications($connection, $barangayId, $adminId) === 1, 'Barangay/category targeting failed.');

    $title = 'Controlled draft'; $category = 'Crops'; $coverage = 'Entire Cabadbaran City, Agusan del Norte'; $scope = 'City'; $status = 'Draft';
    $insertAdvisory->execute([$title, $category, $coverage, $scope, $today, $tomorrow, $status, $adminId]); $draftId = (int) $connection->lastInsertId();
    expect_value(publish_weather_advisory_notifications($connection, $draftId, $adminId) === 0, 'Draft created a notification.');

    $title = 'Controlled future'; $status = 'Active';
    // Expiration must not precede effective date in real admin validation; use no expiration for this direct visibility check.
    $noExpiry = null; $insertAdvisory->execute([$title, $category, $coverage, $scope, $future, $noExpiry, $status, $adminId]); $futureId = (int) $connection->lastInsertId();
    expect_value(publish_weather_advisory_notifications($connection, $futureId, $adminId) === 0, 'Future advisory created a notification early.');
    $visible = $connection->query("SELECT COUNT(*) AS total FROM weather_advisories WHERE id = {$futureId} AND status = 'Active' AND effective_date <= CURRENT_DATE AND (expiration_date IS NULL OR expiration_date >= CURRENT_DATE)")->fetch();
    expect_value((int) $visible['total'] === 0, 'Future advisory became visible early.');

    $eligibleCheck = weather_advisory_farmer_eligibility($connection, $cityId, (int) $farmers[0]['id']);
    expect_value(($eligibleCheck['eligible'] ?? false) === true, 'Eligible controlled farmer was excluded.');
    $categoryCheck = weather_advisory_farmer_eligibility($connection, $cityId, (int) $farmers[1]['id']);
    expect_value(($categoryCheck['code'] ?? '') === 'category_mismatch', 'Category mismatch reason was not reported.');
    $reviewCheck = weather_advisory_farmer_eligibility($connection, $cityId, (int) $farmers[2]['id']);
    expect_value(($reviewCheck['code'] ?? '') === 'location_review_required', 'Location review reason was not reported.');

    $connection->rollBack();
    echo "PASS: city, barangay, category, draft, timing, and duplicate checks\n";
} catch (Throwable $exception) {
    $connection->rollBack();
    fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
