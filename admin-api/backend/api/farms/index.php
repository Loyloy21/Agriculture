<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/farmer_auth.php';
require_once dirname(__DIR__, 3) . '/backend/lib/cabadbaran.php';

function mobile_farm(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'name' => (string) $row['farm_name'],
        'type' => (string) $row['farm_type'],
        'agriculturalCategory' => (string) $row['agricultural_category'],
        'barangayCode' => $row['barangay_psgc_code'] ?: null,
        'barangayName' => $row['barangay_name'] ?: null,
        'address' => (string) $row['farm_address'],
        'cityName' => $row['city_name'] ?: null,
        'provinceName' => $row['province_name'] ?: null,
        'landArea' => $row['land_area'] === null ? null : (float) $row['land_area'],
        'landAreaUnit' => 'hectares',
        'mainProduct' => (string) ($row['main_product'] ?? ''),
        'latitude' => $row['latitude'] === null ? null : (float) $row['latitude'],
        'longitude' => $row['longitude'] === null ? null : (float) $row['longitude'],
        'status' => (string) $row['status'],
        'isPrimary' => (bool) $row['is_primary'],
        'locationReviewStatus' => (string) $row['location_review_status'],
    ];
}

function farm_select_sql(): string
{
    return 'SELECT id, farmer_id, farm_name, farm_type, agricultural_category, land_area, farm_address,
                   city_psgc_code, city_name, province_name, barangay_psgc_code, barangay_name,
                   location_review_status, main_product, latitude, longitude, status, is_primary
            FROM farm_profiles';
}

function farm_payload_errors(array $body): array
{
    $errors = [];
    $name = trim((string) ($body['name'] ?? ''));
    $category = trim((string) ($body['agriculturalCategory'] ?? ''));
    $type = trim((string) ($body['type'] ?? $category));
    $barangayCode = trim((string) ($body['barangayCode'] ?? ''));
    $address = trim((string) ($body['address'] ?? ''));
    $landArea = filter_var($body['landArea'] ?? null, FILTER_VALIDATE_FLOAT);
    $unit = trim((string) ($body['landAreaUnit'] ?? 'hectares'));
    $mainProduct = trim((string) ($body['mainProduct'] ?? ''));
    $latitudeValue = $body['latitude'] ?? null;
    $longitudeValue = $body['longitude'] ?? null;
    $latitude = ($latitudeValue === null || $latitudeValue === '') ? null : filter_var($latitudeValue, FILTER_VALIDATE_FLOAT);
    $longitude = ($longitudeValue === null || $longitudeValue === '') ? null : filter_var($longitudeValue, FILTER_VALIDATE_FLOAT);
    $allowedCategories = ['Crops', 'Livestock', 'Poultry', 'Fishpond'];

    if (mb_strlen($name) < 2 || mb_strlen($name) > 150 || !preg_match('/[\p{L}]/u', $name)) $errors['name'] = 'Enter a valid farm name.';
    if (!in_array($category, $allowedCategories, true)) $errors['agriculturalCategory'] = 'Select a valid agricultural category.';
    if (!in_array($type, $allowedCategories, true) || $type !== $category) $errors['type'] = 'Farm type must match the selected agricultural category.';
    if (cabadbaran_barangay_name($barangayCode) === null) $errors['barangayCode'] = 'Select a valid Cabadbaran City barangay.';
    if (mb_strlen($address) < 5 || mb_strlen($address) > 255 || !preg_match('/[\p{L}]/u', $address)) $errors['address'] = 'Enter a clear address or landmark.';
    if ($landArea === false || $landArea <= 0 || $landArea > 9999999999.99) $errors['landArea'] = 'Enter a land area greater than zero.';
    if ($unit !== 'hectares') $errors['landAreaUnit'] = 'Land area must be provided in hectares.';
    if (mb_strlen($mainProduct) > 150) $errors['mainProduct'] = 'Main product must be 150 characters or fewer.';
    if (($latitude === null) !== ($longitude === null)) $errors['location'] = 'Provide both latitude and longitude, or leave both blank.';
    if ($latitude !== null && ($latitude === false || $latitude < -90 || $latitude > 90)) $errors['latitude'] = 'Enter a valid latitude.';
    if ($longitude !== null && ($longitude === false || $longitude < -180 || $longitude > 180)) $errors['longitude'] = 'Enter a valid longitude.';
    return $errors;
}

$farmer = authenticated_farmer();
$farmerId = (int) $farmer['id'];
$connection = db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $requestedId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if ($requestedId) {
        $statement = $connection->prepare(farm_select_sql() . ' WHERE id = ? AND farmer_id = ? LIMIT 1');
        $statement->execute([$requestedId, $farmerId]);
        $row = $statement->fetch();
        if (!$row) api_response(404, ['success' => false, 'message' => 'This farm was not found.']);
        api_response(200, ['success' => true, 'data' => mobile_farm($row)]);
    }

    $statement = $connection->prepare(farm_select_sql() . " WHERE farmer_id = ? ORDER BY status = 'Active' DESC, is_primary DESC, id ASC");
    $statement->execute([$farmerId]);
    $farms = array_map('mobile_farm', $statement->fetchAll());
    $barangays = [];
    foreach (cabadbaran_barangays() as $code => $name) $barangays[] = ['code' => $code, 'name' => $name];
    api_response(200, ['success' => true, 'data' => ['farms' => $farms, 'barangays' => $barangays]]);
}

if ($method !== 'POST') {
    header('Allow: GET, POST');
    api_response(405, ['success' => false, 'message' => 'Method not allowed.']);
}

$body = api_json_body();
$action = (string) ($body['action'] ?? '');
if ($action === 'archive') {
    $farmId = filter_var($body['farmId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$farmId) api_response(422, ['success' => false, 'message' => 'Select a valid farm.']);
    $connection->beginTransaction();
    try {
        $find = $connection->prepare("SELECT id, is_primary FROM farm_profiles WHERE id = ? AND farmer_id = ? AND status = 'Active' FOR UPDATE");
        $find->execute([$farmId, $farmerId]);
        $farm = $find->fetch();
        if (!$farm) {
            $connection->rollBack();
            api_response(404, ['success' => false, 'message' => 'This active farm was not found.']);
        }
        $archive = $connection->prepare("UPDATE farm_profiles SET status = 'Archived', is_primary = 0 WHERE id = ? AND farmer_id = ?");
        $archive->execute([$farmId, $farmerId]);
        if ((int) $farm['is_primary'] === 1) {
            // UPDATE ... ORDER BY ... LIMIT is MySQL-only; the derived table selects the same single row on both databases.
            $promote = $connection->prepare("UPDATE farm_profiles SET is_primary = 1 WHERE id = (SELECT id FROM (SELECT id FROM farm_profiles WHERE farmer_id = ? AND status = 'Active' ORDER BY id ASC LIMIT 1) AS next_primary)");
            $promote->execute([$farmerId]);
        }
        $connection->commit();
        api_response(200, ['success' => true, 'data' => ['archived' => true]]);
    } catch (Throwable $exception) {
        $connection->rollBack();
        error_log('Farm archive failed: ' . $exception->getMessage());
        api_response(500, ['success' => false, 'message' => 'The farm could not be archived.']);
    }
}

if (!in_array($action, ['create', 'update'], true)) api_response(422, ['success' => false, 'message' => 'The farm action is invalid.']);
$errors = farm_payload_errors($body);
if ($errors) api_response(422, ['success' => false, 'message' => 'Please correct the highlighted farm fields.', 'errors' => $errors]);

$farmId = $action === 'update' ? filter_var($body['farmId'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : null;
if ($action === 'update' && !$farmId) api_response(422, ['success' => false, 'message' => 'Select a valid farm.']);
$name = trim((string) $body['name']);
$category = trim((string) $body['agriculturalCategory']);
$type = trim((string) ($body['type'] ?? $category));
$barangayCode = trim((string) $body['barangayCode']);
$barangayName = (string) cabadbaran_barangay_name($barangayCode);
$address = trim((string) $body['address']);
$landArea = (float) $body['landArea'];
$mainProduct = trim((string) ($body['mainProduct'] ?? ''));
$latitude = ($body['latitude'] ?? null) === null || $body['latitude'] === '' ? null : (float) $body['latitude'];
$longitude = ($body['longitude'] ?? null) === null || $body['longitude'] === '' ? null : (float) $body['longitude'];

try {
    $duplicateSql = "SELECT id FROM farm_profiles WHERE farmer_id = ? AND status = 'Active' AND LOWER(farm_name) = LOWER(?) AND barangay_psgc_code = ?" . ($farmId ? ' AND id <> ?' : '') . ' LIMIT 1';
    $duplicate = $connection->prepare($duplicateSql);
    $duplicate->execute($farmId ? [$farmerId, $name, $barangayCode, $farmId] : [$farmerId, $name, $barangayCode]);
    if ($duplicate->fetch()) api_response(409, ['success' => false, 'message' => 'You already have an active farm with this name in the selected barangay.', 'errors' => ['name' => 'Use a different farm name or barangay.']]);

    if ($action === 'create') {
        $primaryCheck = $connection->prepare("SELECT 1 FROM farm_profiles WHERE farmer_id = ? AND status = 'Active' LIMIT 1");
        $primaryCheck->execute([$farmerId]);
        $isPrimary = $primaryCheck->fetch(PDO::FETCH_NUM) ? 0 : 1;
        $statement = $connection->prepare(
            "INSERT INTO farm_profiles (farmer_id, farm_name, farm_type, agricultural_category, land_area, farm_address,
                city_psgc_code, city_name, province_name, barangay_psgc_code, barangay_name, location_review_status,
                main_product, latitude, longitude, status, is_primary)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Verified', NULLIF(?, ''), ?, ?, 'Active', ?)"
        );
        $cityCode = CABADBARAN_CITY_CODE; $cityName = CABADBARAN_CITY_NAME; $provinceName = CABADBARAN_PROVINCE_NAME;
        $statement->execute([$farmerId, $name, $type, $category, $landArea, $address, $cityCode, $cityName, $provinceName, $barangayCode, $barangayName, $mainProduct, $latitude, $longitude, $isPrimary]);
        $farmId = db_insert_id($connection, 'farm_profiles');
    } else {
        $statement = $connection->prepare(
            "UPDATE farm_profiles SET farm_name = ?, farm_type = ?, agricultural_category = ?, land_area = ?, farm_address = ?,
                city_psgc_code = ?, city_name = ?, province_name = ?, barangay_psgc_code = ?, barangay_name = ?,
                location_review_status = 'Verified', main_product = NULLIF(?, ''), latitude = ?, longitude = ?
             WHERE id = ? AND farmer_id = ?"
        );
        $cityCode = CABADBARAN_CITY_CODE; $cityName = CABADBARAN_CITY_NAME; $provinceName = CABADBARAN_PROVINCE_NAME;
        $statement->execute([$name, $type, $category, $landArea, $address, $cityCode, $cityName, $provinceName, $barangayCode, $barangayName, $mainProduct, $latitude, $longitude, $farmId, $farmerId]);
        if ($statement->rowCount() === 0) {
            $exists = $connection->prepare('SELECT 1 FROM farm_profiles WHERE id = ? AND farmer_id = ? LIMIT 1');
            $exists->execute([$farmId, $farmerId]);
            if (!$exists->fetch(PDO::FETCH_NUM)) api_response(404, ['success' => false, 'message' => 'This farm was not found.']);
        }
    }
    $read = $connection->prepare(farm_select_sql() . ' WHERE id = ? AND farmer_id = ? LIMIT 1');
    $read->execute([$farmId, $farmerId]);
    api_response($action === 'create' ? 201 : 200, ['success' => true, 'message' => $action === 'create' ? 'Farm added.' : 'Farm updated.', 'data' => mobile_farm($read->fetch())]);
} catch (Throwable $exception) {
    error_log('Farm save failed: ' . $exception->getMessage());
    api_response(500, ['success' => false, 'message' => 'The farm could not be saved.']);
}
