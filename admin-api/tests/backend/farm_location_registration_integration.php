<?php
declare(strict_types=1);

require_once __DIR__ . '/../support/test_base_url.php';

require_once dirname(__DIR__, 2) . '/backend/config/database.php';
require_once dirname(__DIR__, 2) . '/backend/lib/private_uploads.php';

define('REGISTER_URL', agrireach_test_base_url() . '/CAPSTONE%20AGRICULTURE/api/farmers/register.php');
define('LOGIN_URL', agrireach_test_base_url() . '/CAPSTONE%20AGRICULTURE/api/auth/login.php');

function assert_true(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

/** @return array{status:int,payload:array} */
function submit_registration(array $fields, string $frontImagePath, ?string $backImagePath): array
{
    $fields['valid_id_front'] = new CURLFile($frontImagePath, 'image/png', 'controlled-test-id-front.png');
    if ($backImagePath !== null) $fields['valid_id_back'] = new CURLFile($backImagePath, 'image/png', 'controlled-test-id-back.png');
    $curl = curl_init(REGISTER_URL);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $fields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $error = curl_error($curl);
    curl_close($curl);
    if (!is_string($body)) throw new RuntimeException('Registration request failed: ' . $error);
    $payload = json_decode($body, true);
    if (!is_array($payload)) throw new RuntimeException('Registration API returned invalid JSON.');
    return ['status' => $status, 'payload' => $payload];
}

/** @return array{status:int,payload:array} */
function login_controlled_farmer(string $email, string $password): array
{
    $curl = curl_init(LOGIN_URL);
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(['email' => $email, 'password' => $password], JSON_THROW_ON_ERROR),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json'],
    ]);
    $body = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    $payload = is_string($body) ? json_decode($body, true) : null;
    if (!is_array($payload)) throw new RuntimeException('Login API returned invalid JSON.');
    return ['status' => $status, 'payload' => $payload];
}

$connection = db();
$testFarmerIds = [];
$uploadedNames = [];
$suffix = (string) random_int(1000000, 9999999);
$frontImagePath = dirname(__DIR__, 2) . '/frontend/mobile/assets/images/icon.png';
$backImagePath = dirname(__DIR__, 2) . '/frontend/mobile/assets/images/splash-icon.png';
assert_true(is_file($frontImagePath) && getimagesize($frontImagePath) !== false, 'Controlled front PNG fixture is unavailable.');
assert_true(is_file($backImagePath) && getimagesize($backImagePath) !== false, 'Controlled back PNG fixture is unavailable.');

$base = [
    'first_name' => 'Controlled',
    'last_name' => 'Tester',
    'gender' => 'Prefer not to say',
    'custom_gender' => '',
    'residential_city_psgc_code' => '1600203000',
    'residential_barangay_psgc_code' => '1600203019',
    'residential_purok_sitio' => 'Purok 1',
    'residential_landmark' => 'Controlled home landmark',
    'residential_location_method' => 'Manual',
    'residential_latitude' => '',
    'residential_longitude' => '',
    'date_of_birth' => '1990-01-02',
    'password' => 'Controlled123',
    'confirm_password' => 'Controlled123',
    'farm_name' => 'Controlled Test Farm',
    'farm_type' => 'Crops',
    'agricultural_category' => 'Crops',
    'farm_city_psgc_code' => '1600203000',
    'farm_barangay_psgc_code' => '1600203025',
    'farm_purok_sitio' => 'Purok 3',
    'farm_landmark' => 'Near controlled test marker',
    'land_area' => '1.25',
    'main_product' => 'Rice',
    'id_type' => 'Philippine National ID',
    'id_number' => 'CONTROLLED-' . $suffix,
];

try {
    $manual = $base + [
        'email' => 'farm-location-manual-' . $suffix . '@example.test',
        'contact_number' => '0917' . substr($suffix, 0, 7),
        'farm_location_method' => 'Manual',
        'latitude' => '',
        'longitude' => '',
    ];
    $manualResult = submit_registration($manual, $frontImagePath, $backImagePath);
    assert_true($manualResult['status'] === 201, 'Manual registration should return HTTP 201.');
    $manualId = (int) ($manualResult['payload']['application']['id'] ?? 0);
    assert_true($manualId > 0, 'Manual registration did not return an application ID.');
    $testFarmerIds[] = $manualId;

    $statement = $connection->prepare('SELECT fp.*, fi.front_image_path, fi.back_image_path, f.address AS residential_address, f.residential_barangay_name, f.residential_purok_sitio, f.residential_location_method, f.residential_latitude, f.residential_longitude FROM farm_profiles fp JOIN farmers f ON f.id = fp.farmer_id JOIN farmer_ids fi ON fi.farmer_id = fp.farmer_id WHERE fp.farmer_id = ?');
    $statement->execute([$manualId]);
    $manualFarm = $statement->fetch();
    assert_true((string) $manualFarm['location_method'] === 'Manual', 'Manual location method was not stored.');
    assert_true((string) $manualFarm['purok_sitio'] === 'Purok 3', 'Manual purok/sitio was not stored.');
    assert_true($manualFarm['latitude'] === null && $manualFarm['longitude'] === null, 'Manual registration must not invent coordinates.');
    assert_true(str_contains((string) $manualFarm['farm_address'], 'Poblacion 2'), 'Structured readable farm address was not built.');
    assert_true((string) $manualFarm['residential_barangay_name'] === 'Mabini', 'The separate residential barangay was not stored.');
    assert_true((string) $manualFarm['residential_purok_sitio'] === 'Purok 1', 'The residential purok/sitio was not stored.');
    assert_true((string) $manualFarm['residential_location_method'] === 'Manual', 'The residential location method was not stored.');
    $phoneCheck = $connection->prepare('SELECT contact_number FROM farmers WHERE id = ?');
    $phoneCheck->execute([$manualId]);
    assert_true((string) $phoneCheck->fetch()['contact_number'] === '+63917' . substr($suffix, 0, 7), 'Manual registration phone was not stored in the canonical +63 format.');
    $duplicatePhone = $manual;
    $duplicatePhone['email'] = 'farm-location-duplicate-' . $suffix . '@example.test';
    $duplicatePhone['contact_number'] = '+63917' . substr($suffix, 0, 7);
    $duplicatePhoneResult = submit_registration($duplicatePhone, $frontImagePath, $backImagePath);
    assert_true($duplicatePhoneResult['status'] === 409, 'The same mobile number in another format should be rejected.');
    assert_true($manualFarm['residential_latitude'] === null && $manualFarm['residential_longitude'] === null, 'Manual residential registration must not invent coordinates.');
    assert_true(str_contains((string) $manualFarm['residential_address'], 'Mabini'), 'The readable residential address was not built.');
    assert_true((string) $manualFarm['front_image_path'] !== '' && (string) $manualFarm['back_image_path'] !== '', 'Distinct ID Front and ID Back references were not stored.');
    $uploadedNames[] = basename((string) $manualFarm['front_image_path']);
    $uploadedNames[] = basename((string) $manualFarm['back_image_path']);

    $map = $base;
    $map['email'] = 'farm-location-map-' . $suffix . '@example.test';
    $map['contact_number'] = '+630918' . substr($suffix, 0, 7);
    $map['farm_name'] = 'Controlled Map Farm';
    $map['farm_location_method'] = 'Map';
    $map['latitude'] = '9.1172482';
    $map['longitude'] = '125.5296631';
    $map['residential_location_method'] = 'Map';
    $map['residential_latitude'] = '9.1236000';
    $map['residential_longitude'] = '125.5354000';
    $mapResult = submit_registration($map, $frontImagePath, $backImagePath);
    assert_true($mapResult['status'] === 201, 'Inside-city map registration should return HTTP 201.');
    $mapId = (int) ($mapResult['payload']['application']['id'] ?? 0);
    assert_true($mapId > 0, 'Map registration did not return an application ID.');
    $testFarmerIds[] = $mapId;

    $statement = $connection->prepare('SELECT fp.*, fi.front_image_path, fi.back_image_path, f.residential_location_method, f.residential_boundary_status, f.residential_latitude, f.residential_longitude FROM farm_profiles fp JOIN farmers f ON f.id = fp.farmer_id JOIN farmer_ids fi ON fi.farmer_id = fp.farmer_id WHERE fp.farmer_id = ?');
    $statement->execute([$mapId]);
    $mapFarm = $statement->fetch();
    assert_true((string) $mapFarm['location_method'] === 'Map', 'Map location method was not stored.');
    assert_true((string) $mapFarm['location_boundary_status'] === 'Inside Mapped Boundary', 'Mapped boundary result was not stored.');
    assert_true($mapFarm['latitude'] !== null && $mapFarm['longitude'] !== null, 'Confirmed map coordinates were not stored.');
    assert_true((string) $mapFarm['residential_location_method'] === 'Map', 'Confirmed home location method was not stored.');
    assert_true((string) $mapFarm['residential_boundary_status'] === 'Inside Mapped Boundary', 'Home city-boundary result was not stored.');
    assert_true((string) $mapFarm['residential_latitude'] !== (string) $mapFarm['latitude'] || (string) $mapFarm['residential_longitude'] !== (string) $mapFarm['longitude'], 'Home and farm pins were not stored independently.');
    $phoneCheck->execute([$mapId]);
    assert_true((string) $phoneCheck->fetch()['contact_number'] === '+63918' . substr($suffix, 0, 7), 'Pasted +6309 mobile number was not corrected before storage.');
    $adminView = $connection->prepare('SELECT f.contact_number, f.residential_barangay_name, f.residential_purok_sitio, f.residential_latitude, fp.barangay_name AS farm_barangay FROM farmers f LEFT JOIN farm_profiles fp ON fp.farmer_id = f.id AND fp.status = \'Active\' WHERE f.id = ? LIMIT 1');
    $adminView->execute([$mapId]);
    $adminRow = $adminView->fetch();
    assert_true(is_array($adminRow) && $adminRow['residential_barangay_name'] === 'Mabini' && $adminRow['residential_purok_sitio'] === 'Purok 1' && $adminRow['residential_latitude'] !== null && $adminRow['farm_barangay'] === 'Poblacion 2', 'Admin verification query cannot show the saved independent home and farm locations.');
    $uploadedNames[] = basename((string) $mapFarm['front_image_path']);
    $uploadedNames[] = basename((string) $mapFarm['back_image_path']);

    $approve = $connection->prepare("UPDATE farmers SET verification_status = 'Approved', verified_at = CURRENT_TIMESTAMP WHERE id = ?");
    $approve->execute([$mapId]);
    $loginResult = login_controlled_farmer($map['email'], $map['password']);
    assert_true($loginResult['status'] === 200, 'Approved controlled farmer could not log in.');
    $profileFarm = $loginResult['payload']['farmer']['farm'] ?? null;
    assert_true(is_array($profileFarm), 'Authenticated farmer profile did not include the registration farm.');
    assert_true(($profileFarm['purokSitio'] ?? null) === 'Purok 3', 'Mobile profile contract did not include the submitted purok/sitio.');
    assert_true(($profileFarm['locationMethod'] ?? null) === 'Map', 'Mobile profile contract did not include the location method.');
    $profileResidential = $loginResult['payload']['farmer']['residentialLocation'] ?? null;
    assert_true(is_array($profileResidential), 'Authenticated farmer profile did not include the structured residential location.');
    assert_true(($profileResidential['barangayName'] ?? null) === 'Mabini', 'Mobile profile contract did not include the residential barangay.');
    assert_true(($profileResidential['locationMethod'] ?? null) === 'Map', 'Mobile profile contract did not include the residential location method.');
    assert_true(($loginResult['payload']['farmer']['contactNumber'] ?? null) === '+63918' . substr($suffix, 0, 7), 'Login/profile contact display does not return the canonical +63 number.');

    $outside = $base;
    $outside['email'] = 'farm-location-outside-' . $suffix . '@example.test';
    $outside['contact_number'] = '0919' . substr($suffix, 0, 7);
    $outside['farm_name'] = 'Controlled Outside Farm';
    $outside['farm_location_method'] = 'Map';
    $outside['latitude'] = '8.5';
    $outside['longitude'] = '125.0';
    $outsideResult = submit_registration($outside, $frontImagePath, $backImagePath);
    assert_true($outsideResult['status'] === 422, 'Outside-city map registration should return HTTP 422.');
    assert_true(isset($outsideResult['payload']['errors']['farmPin']), 'Outside-city response should identify the farm pin field.');

    $statement = $connection->prepare('SELECT COUNT(*) AS total FROM farmers WHERE email = ?');
    $statement->execute([$outside['email']]);
    assert_true((int) $statement->fetch()['total'] === 0, 'Rejected outside-city registration left a farmer record.');

    $outsideHome = $base;
    $outsideHome['email'] = 'residential-location-outside-' . $suffix . '@example.test';
    $outsideHome['contact_number'] = '0990' . substr($suffix, 0, 7);
    $outsideHome['farm_location_method'] = 'Manual';
    $outsideHome['latitude'] = '';
    $outsideHome['longitude'] = '';
    $outsideHome['residential_location_method'] = 'Map';
    $outsideHome['residential_latitude'] = '8.5';
    $outsideHome['residential_longitude'] = '125.0';
    $outsideHomeResult = submit_registration($outsideHome, $frontImagePath, $backImagePath);
    assert_true($outsideHomeResult['status'] === 422, 'Outside-city residential map registration should return HTTP 422.');
    assert_true(isset($outsideHomeResult['payload']['errors']['residentialPin']), 'Outside-city response should identify the residential pin field.');
    $statement = $connection->prepare('SELECT COUNT(*) AS total FROM farmers WHERE email = ?');
    $statement->execute([$outsideHome['email']]);
    assert_true((int) $statement->fetch()['total'] === 0, 'Rejected outside-city residential registration left a farmer record.');

    $missingBack = $base;
    $missingBack['email'] = 'farm-id-missing-back-' . $suffix . '@example.test';
    $missingBack['contact_number'] = '0991' . substr($suffix, 0, 7);
    $missingBack['farm_location_method'] = 'Manual';
    $missingBack['latitude'] = '';
    $missingBack['longitude'] = '';
    $missingBackResult = submit_registration($missingBack, $frontImagePath, null);
    assert_true($missingBackResult['status'] === 422 && isset($missingBackResult['payload']['errors']['idBackImageUri']), 'A two-sided ID without ID Back should be rejected by the server.');

    $passport = $missingBack;
    $passport['email'] = 'farm-id-passport-' . $suffix . '@example.test';
    $passport['contact_number'] = '0992' . substr($suffix, 0, 7);
    $passport['id_type'] = 'Passport';
    $passportResult = submit_registration($passport, $frontImagePath, null);
    assert_true($passportResult['status'] === 201, 'Passport bio page should be accepted without an ID Back image.');
    $passportId = (int) ($passportResult['payload']['application']['id'] ?? 0);
    $testFarmerIds[] = $passportId;
    $statement = $connection->prepare('SELECT front_image_path, back_image_path FROM farmer_ids WHERE farmer_id = ?');
    $statement->execute([$passportId]); $passportImages = $statement->fetch();
    assert_true((string) ($passportImages['front_image_path'] ?? '') !== '' && $passportImages['back_image_path'] === null, 'Passport side storage did not preserve the single-page exception.');
    $uploadedNames[] = basename((string) $passportImages['front_image_path']);

    echo "PASS manual registration without GPS\n";
    echo "PASS Philippine phone normalization and login contact display\n";
    echo "PASS duplicate mobile number blocked across local and +63 formats\n";
    echo "PASS admin verification data for separate home and farm locations\n";
    echo "PASS separate structured residential and farm addresses\n";
    echo "PASS distinct protected ID Front and ID Back storage\n";
    echo "PASS two-sided ID validation and Passport bio-page exception\n";
    echo "PASS map registration with moved/confirmed inside-city point\n";
    echo "PASS independent confirmed home and farm map points\n";
    echo "PASS approved farmer login returns the structured farm location\n";
    echo "PASS outside-city point rejected without partial farmer data\n";
    echo "PASS outside-city home point rejected without partial farmer data\n";
} finally {
    foreach ($testFarmerIds as $farmerId) {
        $delete = $connection->prepare('DELETE FROM farmers WHERE id = ?');
        $delete->execute([$farmerId]);
    }
    foreach ($uploadedNames as $uploadedName) {
        if ($uploadedName !== '' && preg_match('/^[a-f0-9]{40,64}\.(?:png|jpg)$/', $uploadedName)) {
            $path = farmer_id_private_directory() . DIRECTORY_SEPARATOR . $uploadedName;
            if (is_file($path)) @unlink($path);
        }
    }
}
