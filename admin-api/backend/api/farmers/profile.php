<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/farmer_auth.php';
require_once dirname(__DIR__, 3) . '/backend/lib/phone.php';
require_once dirname(__DIR__, 3) . '/backend/lib/farmer_identity.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    api_response(405, ['success' => false, 'message' => 'Method not allowed.']);
}

$farmer = authenticated_farmer();
$connection = db();
$farmerId = (int) $farmer['id'];
$body = api_json_body();
$action = (string) ($body['action'] ?? '');

if ($action === 'update_profile') {
    // An app build that does not send middleName/extensionName keeps the stored values, so saving a
    // profile never drops them or leaves full_name out of step with the name parts.
    $middleInput = array_key_exists('middleName', $body) ? (string) $body['middleName'] : (string) ($farmer['middle_name'] ?? '');
    $extensionInput = array_key_exists('extensionName', $body) ? (string) $body['extensionName'] : (string) ($farmer['extension_name'] ?? '');
    [$nameErrors, $nameParts] = farmer_validate_name_parts((string) ($body['firstName'] ?? ''), $middleInput, (string) ($body['lastName'] ?? ''), $extensionInput);
    $gender = trim((string) ($body['gender'] ?? ''));
    $dateOfBirth = trim((string) ($body['dateOfBirth'] ?? ''));
    $email = strtolower(trim((string) ($body['email'] ?? '')));
    $contactNumber = agrireach_ph_mobile((string) ($body['contactNumber'] ?? ''));
    $address = trim((string) ($body['address'] ?? ''));
    $errors = $nameErrors;
    if ($gender === ''|| mb_strlen($gender) > 30) $errors['gender'] = 'Enter a valid gender.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 160) $errors['email'] = 'Enter a valid email address.';
    if ($contactNumber === null) $errors['contactNumber'] = 'Enter a valid Philippine mobile number.';
    if (mb_strlen($address) < 5 || mb_strlen($address) > 255) $errors['address'] = 'Enter a complete address.';
    $birthDate = DateTimeImmutable::createFromFormat('!Y-m-d', $dateOfBirth);
    if (!$birthDate || $birthDate->format('Y-m-d') !== $dateOfBirth || $birthDate > new DateTimeImmutable('today')) $errors['dateOfBirth'] = 'Enter a valid date of birth.';
    if ($errors) api_response(422, ['success' => false, 'message' => 'Please correct the highlighted profile fields.', 'errors' => $errors]);

    [$phoneE164, $phoneLocal, $phoneDigits, $phoneInternational] = agrireach_ph_mobile_equivalents($contactNumber);
    $duplicate = $connection->prepare('SELECT id FROM farmers WHERE id <> ? AND (email = ? OR contact_number IN (?, ?, ?, ?)) LIMIT 1');
    $duplicate->execute([$farmerId, $email, $phoneE164, $phoneLocal, $phoneDigits, $phoneInternational]);
    if ($duplicate->fetch()) api_response(409, ['success' => false, 'message' => 'Another farmer account already uses that email or contact number.']);

    $statement = $connection->prepare("UPDATE farmers SET full_name = ?, first_name = ?, middle_name = NULLIF(?, ''), last_name = ?, extension_name = NULLIF(?, ''), gender = ?, date_of_birth = ?, email = ?, contact_number = ?, address = ? WHERE id = ?");
    $statement->execute([$nameParts['full'], $nameParts['first'], $nameParts['middle'], $nameParts['last'], $nameParts['extension'], $gender, $dateOfBirth, $email, $contactNumber, $address, $farmerId]);
    api_response(200, ['success' => true, 'data' => ['updated' => true]]);
}

if ($action === 'change_password') {
    $currentPassword = (string) ($body['currentPassword'] ?? '');
    $newPassword = (string) ($body['newPassword'] ?? '');
    $statement = $connection->prepare('SELECT password_hash FROM farmers WHERE id = ? LIMIT 1');
    $statement->execute([$farmerId]);
    $passwordHash = (string) (($statement->fetch() ?: [])['password_hash'] ?? '');
    if ($passwordHash === '' || !password_verify($currentPassword, $passwordHash)) api_response(422, ['success' => false, 'message' => 'The current password is incorrect.', 'errors' => ['currentPassword' => 'Current password is incorrect.']]);
    if (strlen($newPassword) < 8 || !preg_match('/[A-Z]/', $newPassword) || !preg_match('/[a-z]/', $newPassword) || !preg_match('/\d/', $newPassword)) api_response(422, ['success' => false, 'message' => 'The new password does not meet the requirements.', 'errors' => ['newPassword' => 'Use at least 8 characters with uppercase, lowercase, and a number.']]);
    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
    $update = $connection->prepare('UPDATE farmers SET password_hash = ? WHERE id = ?');
    $update->execute([$newHash, $farmerId]);
    api_response(200, ['success' => true, 'data' => ['updated' => true]]);
}

if ($action === 'save_farm') {
    $name = trim((string) ($body['name'] ?? ''));
    $type = trim((string) ($body['type'] ?? ''));
    $category = trim((string) ($body['agriculturalCategory'] ?? ''));
    $location = trim((string) ($body['location'] ?? ''));
    $mainProduct = trim((string) ($body['mainProduct'] ?? ''));
    $size = filter_var($body['sizeHectares'] ?? null, FILTER_VALIDATE_FLOAT);
    $latitude = ($body['latitude'] ?? null) === null ? null : filter_var($body['latitude'], FILTER_VALIDATE_FLOAT);
    $longitude = ($body['longitude'] ?? null) === null ? null : filter_var($body['longitude'], FILTER_VALIDATE_FLOAT);
    $allowedTypes = ['Crops', 'Livestock', 'Poultry', 'Fishpond'];
    $errors = [];
    if (mb_strlen($name) < 2 || mb_strlen($name) > 150) $errors['farmName'] = 'Enter a valid farm name.';
    if (!in_array($type, $allowedTypes, true) || $category !== $type) $errors['farmType'] = 'Select a valid farm type.';
    if (mb_strlen($location) < 5 || mb_strlen($location) > 255) $errors['farmLocation'] = 'Enter a complete farm location.';
    if ($size === false || $size <= 0) $errors['farmSize'] = 'Enter a farm size greater than zero.';
    if (mb_strlen($mainProduct) < 2 || mb_strlen($mainProduct) > 150) $errors['mainProduct'] = 'Enter a valid main product.';
    if (($latitude === null) !== ($longitude === null) || ($latitude !== null && ($latitude === false || $latitude < -90 || $latitude > 90)) || ($longitude !== null && ($longitude === false || $longitude < -180 || $longitude > 180))) $errors['farmLocation'] = 'The farm coordinates are invalid.';
    if ($errors) api_response(422, ['success' => false, 'message' => 'Please correct the highlighted farm fields.', 'errors' => $errors]);

    $current = $connection->prepare("SELECT id FROM farm_profiles WHERE farmer_id = ? AND status = 'Active' ORDER BY is_primary DESC, id ASC LIMIT 1");
    $current->execute([$farmerId]);
    $currentFarm = $current->fetch();
    if ($currentFarm) {
        $farmId = (int) $currentFarm['id'];
        $statement = $connection->prepare("UPDATE farm_profiles SET farm_name = ?, farm_type = ?, agricultural_category = ?, land_area = ?, farm_address = ?, main_product = ?, latitude = ?, longitude = ?, location_review_status = 'Needs Review', city_psgc_code = NULL, city_name = NULL, province_name = NULL, barangay_psgc_code = NULL, barangay_name = NULL WHERE id = ? AND farmer_id = ?");
        $params = [$name, $type, $category, $size, $location, $mainProduct, $latitude, $longitude, $farmId, $farmerId];
    } else {
        $statement = $connection->prepare("INSERT INTO farm_profiles (farmer_id, farm_name, farm_type, agricultural_category, land_area, farm_address, main_product, latitude, longitude, status, is_primary) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active', 1)");
        $params = [$farmerId, $name, $type, $category, $size, $location, $mainProduct, $latitude, $longitude];
    }
    $statement->execute($params);
    api_response(200, ['success' => true, 'data' => ['updated' => true]]);
}

if ($action === 'delete_farm') {
    // UPDATE ... ORDER BY ... LIMIT is MySQL-only; the derived table selects the same single row on both databases.
    $statement = $connection->prepare("UPDATE farm_profiles SET status = 'Archived', is_primary = 0 WHERE id = (SELECT id FROM (SELECT id FROM farm_profiles WHERE farmer_id = ? AND status = 'Active' ORDER BY is_primary DESC, id ASC LIMIT 1) AS target_farm)");
    $statement->execute([$farmerId]);
    api_response(200, ['success' => true, 'data' => ['deleted' => true], 'message' => 'Farm archived. Existing reports were preserved.']);
}

api_response(422, ['success' => false, 'message' => 'The profile action is invalid.']);
