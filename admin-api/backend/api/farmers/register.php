<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/bootstrap.php';
require_once dirname(__DIR__, 3) . '/backend/lib/cabadbaran.php';
require_once dirname(__DIR__, 3) . '/backend/lib/private_uploads.php';
require_once dirname(__DIR__, 3) . '/backend/lib/phone.php';
require_once dirname(__DIR__, 3) . '/backend/lib/farmer_identity.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    api_response(405, ['success' => false, 'message' => 'Method not allowed.']);
}

// Middle and Extension Name are optional; older app builds that do not send them register as before.
[$nameErrors, $nameParts] = farmer_validate_name_parts(api_post_string('first_name'), api_post_string('middle_name'), api_post_string('last_name'), api_post_string('extension_name'));
$firstName = $nameParts['first'];
$middleName = $nameParts['middle'];
$lastName = $nameParts['last'];
$extensionName = $nameParts['extension'];
$selectedGender = api_post_string('gender');
$customGender = api_post_string('custom_gender');
$gender = $selectedGender === 'Prefer to self-describe' ? $customGender : $selectedGender;
$email = strtolower(api_post_string('email'));
$contactNumber = agrireach_ph_mobile(api_post_string('contact_number'));
$residentialCityCode = api_post_string('residential_city_psgc_code');
$residentialBarangayCode = api_post_string('residential_barangay_psgc_code');
$residentialPurokSitio = api_post_string('residential_purok_sitio');
$residentialLandmark = api_post_string('residential_landmark');
$residentialLocationMethod = api_post_string('residential_location_method') ?: 'Manual';
$residentialLatitudeValue = api_post_string('residential_latitude');
$residentialLongitudeValue = api_post_string('residential_longitude');
$dateOfBirth = api_post_string('date_of_birth');
$password = (string) ($_POST['password'] ?? '');
$confirmPassword = (string) ($_POST['confirm_password'] ?? '');
$farmName = api_post_string('farm_name');
$farmType = api_post_string('farm_type');
$agriculturalCategory = api_post_string('agricultural_category');
$farmCityCode = api_post_string('farm_city_psgc_code');
$farmBarangayCode = api_post_string('farm_barangay_psgc_code');
$farmPurokSitio = api_post_string('farm_purok_sitio');
$farmLandmark = api_post_string('farm_landmark');
$farmLocationMethod = api_post_string('farm_location_method');
$landAreaValue = api_post_string('land_area');
$mainProduct = api_post_string('main_product');
$latitudeValue = api_post_string('latitude');
$longitudeValue = api_post_string('longitude');
$idType = api_post_string('id_type');
$idNumber = api_post_string('id_number');

$errors = [];
$allowedGenders = ['Male', 'Female', 'Non-binary', 'Prefer not to say', 'Prefer to self-describe'];
$allowedFarmOptions = ['Crops', 'Livestock', 'Poultry', 'Fishpond'];
// The current app submits the physical RSBSA ID card (front + back + the number printed on it) for
// manual admin review. The older ID types stay accepted for app builds that still send them.
$allowedIdTypes = ['RSBSA ID', 'Philippine National ID', "Driver's License", 'Passport', "Voter's ID", 'Other Government ID'];
$isRsbsaId = $idType === RSBSA_ID_TYPE;
$frontLabel = $isRsbsaId ? 'Front of RSBSA ID' : 'ID Front';
$backLabel = $isRsbsaId ? 'Back of RSBSA ID' : 'ID Back';

$errors = $nameErrors;
if (!in_array($selectedGender, $allowedGenders, true)) $errors['gender'] = 'Select a valid gender.';
if ($selectedGender === 'Prefer to self-describe' && ($customGender === '' || mb_strlen($customGender) > 30)) $errors['customGender'] = 'Describe your gender using 30 characters or fewer.';
if ($gender === '' || mb_strlen($gender) > 30) $errors['gender'] = 'Select a valid gender.';
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 160) $errors['email'] = 'Enter a valid email address.';
if ($contactNumber === null) $errors['phone'] = 'Enter a valid Philippine mobile number.';
$residentialBarangayName = cabadbaran_barangay_name($residentialBarangayCode);
if ($residentialCityCode !== CABADBARAN_CITY_CODE) $errors['residentialBarangayCode'] = 'Residential registration is limited to Cabadbaran City.';
if ($residentialBarangayName === null) $errors['residentialBarangayCode'] = 'Select a valid Cabadbaran City residential barangay.';
if ($residentialPurokSitio === '' || mb_strlen($residentialPurokSitio) < 2 || mb_strlen($residentialPurokSitio) > 100 || !preg_match("/^[\p{L}\p{N}][\p{L}\p{N}\s.'#\/-]*$/u", $residentialPurokSitio)) {
    $errors['residentialPurokSitio'] = 'Enter a valid residential purok or sitio.';
}
if ($residentialLandmark !== '' && (mb_strlen($residentialLandmark) > 100 || !preg_match("/^[\p{L}\p{N}][\p{L}\p{N}\s.,'#\/()-]*$/u", $residentialLandmark))) {
    $errors['residentialLandmark'] = 'Enter a valid house, street, or landmark, or leave it blank.';
}
if (!in_array($residentialLocationMethod, ['Manual', 'Map'], true)) $errors['residentialLocationMethod'] = 'Select how you want to provide your residential location.';

$residentialLatitude = $residentialLatitudeValue === '' ? null : filter_var($residentialLatitudeValue, FILTER_VALIDATE_FLOAT);
$residentialLongitude = $residentialLongitudeValue === '' ? null : filter_var($residentialLongitudeValue, FILTER_VALIDATE_FLOAT);
if (($residentialLatitudeValue === '') !== ($residentialLongitudeValue === '')) $errors['residentialPin'] = 'Provide both residential latitude and longitude, or neither.';
if ($residentialLatitudeValue !== '' && ($residentialLatitude === false || $residentialLatitude < -90 || $residentialLatitude > 90)) $errors['residentialPin'] = 'The residential latitude is invalid.';
if ($residentialLongitudeValue !== '' && ($residentialLongitude === false || $residentialLongitude < -180 || $residentialLongitude > 180)) $errors['residentialPin'] = 'The residential longitude is invalid.';
if ($residentialLocationMethod === 'Manual' && ($residentialLatitudeValue !== '' || $residentialLongitudeValue !== '')) $errors['residentialPin'] = 'Manual residential addresses must not include an unconfirmed map pin.';
if ($residentialLocationMethod === 'Map' && ($residentialLatitudeValue === '' || $residentialLongitudeValue === '')) $errors['residentialPin'] = 'Choose and confirm your actual home point on the map.';

$birthDate = DateTimeImmutable::createFromFormat('!Y-m-d', $dateOfBirth);
if (!$birthDate || $birthDate->format('Y-m-d') !== $dateOfBirth || $birthDate > new DateTimeImmutable('today')) $errors['dateOfBirth'] = 'Enter a valid date of birth.';
if (strlen($password) < 8 || !preg_match('/[A-Z]/', $password) || !preg_match('/[a-z]/', $password) || !preg_match('/\d/', $password)) $errors['password'] = 'Use at least 8 characters with uppercase, lowercase, and a number.';
if (!hash_equals($password, $confirmPassword)) $errors['confirmPassword'] = 'Passwords do not match.';
if ($farmName === '' || mb_strlen($farmName) > 150) $errors['farmName'] = 'Enter a valid farm name.';
if (!in_array($farmType, $allowedFarmOptions, true)) $errors['farmType'] = 'Select a valid farm type.';
if (!in_array($agriculturalCategory, $allowedFarmOptions, true) || $agriculturalCategory !== $farmType) $errors['farmType'] = 'The farm category is invalid.';
$farmBarangayName = cabadbaran_barangay_name($farmBarangayCode);
if ($farmCityCode !== CABADBARAN_CITY_CODE) $errors['farmBarangayCode'] = 'Farm registration is limited to Cabadbaran City.';
if ($farmBarangayName === null) $errors['farmBarangayCode'] = 'Select a valid Cabadbaran City barangay.';
if ($farmPurokSitio === '' || mb_strlen($farmPurokSitio) < 2 || mb_strlen($farmPurokSitio) > 100 || !preg_match("/^[\p{L}\p{N}][\p{L}\p{N}\s.'#\/-]*$/u", $farmPurokSitio)) {
    $errors['farmPurokSitio'] = 'Enter a valid purok or sitio.';
}
if ($farmLandmark !== '' && (mb_strlen($farmLandmark) > 100 || !preg_match("/^[\p{L}\p{N}][\p{L}\p{N}\s.,'#\/()-]*$/u", $farmLandmark))) {
    $errors['farmLandmark'] = 'Enter a valid landmark or leave it blank.';
}
if (!in_array($farmLocationMethod, ['Manual', 'Map'], true)) $errors['farmLocationMethod'] = 'Select how you want to provide the farm location.';
if (!is_numeric($landAreaValue) || (float) $landAreaValue <= 0 || (float) $landAreaValue > 9999999999.99) $errors['farmSize'] = 'Enter a positive farm size.';
if ($mainProduct === '' || mb_strlen($mainProduct) > 150) $errors['mainProduct'] = 'Enter a valid main product.';

$latitude = $latitudeValue === '' ? null : filter_var($latitudeValue, FILTER_VALIDATE_FLOAT);
$longitude = $longitudeValue === '' ? null : filter_var($longitudeValue, FILTER_VALIDATE_FLOAT);
if (($latitudeValue === '') !== ($longitudeValue === '')) $errors['farmPin'] = 'Provide both latitude and longitude, or neither.';
if ($latitudeValue !== '' && ($latitude === false || $latitude < -90 || $latitude > 90)) $errors['farmPin'] = 'The farm latitude is invalid.';
if ($longitudeValue !== '' && ($longitude === false || $longitude < -180 || $longitude > 180)) $errors['farmPin'] = 'The farm longitude is invalid.';
if ($farmLocationMethod === 'Manual' && ($latitudeValue !== '' || $longitudeValue !== '')) $errors['farmPin'] = 'Manual farm addresses must not include an unconfirmed map pin.';
if ($farmLocationMethod === 'Map' && ($latitudeValue === '' || $longitudeValue === '')) $errors['farmPin'] = 'Choose and confirm the actual farm point on the map.';
if (!in_array($idType, $allowedIdTypes, true)) $errors['idType'] = 'Select a valid ID type.';
if ($isRsbsaId) {
    // New RSBSA ID registrations need the complete 15-digit reference number, pasted with or without
    // hyphens; it is stored as the canonical 00-00-00-000-000000 string (leading zeroes kept).
    $rsbsaError = rsbsa_reference_error($idNumber);
    if ($rsbsaError !== null) $errors['idNumber'] = $rsbsaError;
    else $idNumber = (string) rsbsa_reference_normalize($idNumber);
} else {
    // Older ID types keep their original free-form number rule.
    if (mb_strlen($idNumber) > 100) $errors['idNumber'] = 'The ID number must be 100 characters or fewer.';
    if ($idNumber !== '' && preg_match('/\p{C}/u', $idNumber) !== 0) $errors['idNumber'] = 'Remove unsupported characters from the ID number.';
}

$idBackRequired = $idType !== 'Passport';
$frontUpload = null;
$backUpload = null;
try {
    $frontUpload = validate_uploaded_image($_FILES['valid_id_front'] ?? null, FARMER_ID_IMAGE_MAX_BYTES, $frontLabel);
} catch (LengthException $exception) {
    api_response(413, ['success' => false, 'message' => 'The ' . $frontLabel . ' image is too large.', 'errors' => ['idFrontImageUri' => $exception->getMessage()]]);
} catch (InvalidArgumentException $exception) {
    $errors['idFrontImageUri'] = $exception->getMessage();
} catch (RuntimeException $exception) {
    error_log('Farmer ID Front upload failed before storage.');
    api_response(500, ['success' => false, 'message' => 'The ' . $frontLabel . ' image could not be uploaded. Please try again.']);
}

if (isset($_FILES['valid_id_back']) && (int) ($_FILES['valid_id_back']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
    try {
        $backUpload = validate_uploaded_image($_FILES['valid_id_back'], FARMER_ID_IMAGE_MAX_BYTES, $backLabel);
    } catch (LengthException $exception) {
        api_response(413, ['success' => false, 'message' => 'The ' . $backLabel . ' image is too large.', 'errors' => ['idBackImageUri' => $exception->getMessage()]]);
    } catch (InvalidArgumentException $exception) {
        $errors['idBackImageUri'] = $exception->getMessage();
    } catch (RuntimeException $exception) {
        error_log('Farmer ID Back upload failed before storage.');
        api_response(500, ['success' => false, 'message' => 'The ' . $backLabel . ' image could not be uploaded. Please try again.']);
    }
} elseif ($idBackRequired) {
    $errors['idBackImageUri'] = $isRsbsaId ? 'Back of RSBSA ID is required.' : 'ID Back is required for this ID type.';
}

if ($errors) api_validation_error($errors);

$residentialBoundaryResult = null;
if ($residentialLocationMethod === 'Map') {
    $residentialBoundaryResult = cabadbaran_boundary_check((float) $residentialLatitude, (float) $residentialLongitude);
    if ($residentialBoundaryResult['status'] === 'unavailable') {
        api_response(503, [
            'success' => false,
            'message' => 'The Cabadbaran boundary check is temporarily unavailable. Try again or use the manual residential address option.',
            'errors' => ['residentialPin' => 'The home pin could not be checked right now. Try again or select the manual address option.'],
        ]);
    }
    if ($residentialBoundaryResult['status'] !== 'inside') {
        api_validation_error(['residentialPin' => 'The confirmed home pin must be inside the mapped Cabadbaran City boundary.']);
    }
}

$boundaryResult = null;
if ($farmLocationMethod === 'Map') {
    $boundaryResult = cabadbaran_boundary_check((float) $latitude, (float) $longitude);
    if ($boundaryResult['status'] === 'unavailable') {
        api_response(503, [
            'success' => false,
            'message' => 'The Cabadbaran boundary check is temporarily unavailable. Try again or use the manual farm address option.',
            'errors' => ['farmPin' => 'The farm pin could not be checked right now. Try again or select the manual address option.'],
        ]);
    }
    if ($boundaryResult['status'] !== 'inside') {
        api_validation_error(['farmPin' => 'The confirmed farm pin must be inside the mapped Cabadbaran City boundary.']);
    }
}

$farmAddress = cabadbaran_farm_address($farmPurokSitio, (string) $farmBarangayName, $farmLandmark);
if (mb_strlen($farmAddress) > 255) {
    api_validation_error(['farmLandmark' => 'Shorten the landmark or detailed address.']);
}
$address = cabadbaran_residential_address($residentialPurokSitio, (string) $residentialBarangayName, $residentialLandmark);
if (mb_strlen($address) > 255) {
    api_validation_error(['residentialLandmark' => 'Shorten the residential house, street, or landmark details.']);
}

try {
    $connection = db();
    [$phoneE164, $phoneLocal, $phoneDigits, $phoneInternational] = agrireach_ph_mobile_equivalents($contactNumber);
    $duplicate = $connection->prepare('SELECT EXISTS(SELECT 1 FROM farmers WHERE email = ? OR contact_number IN (?, ?, ?, ?)) OR EXISTS(SELECT 1 FROM admins WHERE email = ? OR contact_number IN (?, ?, ?, ?)) AS already_used');
    $duplicate->execute([$email, $phoneE164, $phoneLocal, $phoneDigits, $phoneInternational, $email, $phoneE164, $phoneLocal, $phoneDigits, $phoneInternational]);
    if ((int) $duplicate->fetch()['already_used'] === 1) {
        api_response(409, [
            'success' => false,
            'message' => 'An account already uses that email address or contact number.',
            'errors' => ['email' => 'Email or contact number is already registered.', 'phone' => 'Email or contact number is already registered.'],
        ]);
    }
} catch (Throwable $exception) {
    error_log('Farmer registration database preflight failed: ' . $exception->getMessage());
    api_response(500, ['success' => false, 'message' => 'The registration service is temporarily unavailable. Please try again.']);
}

$storedPaths = [];
$frontFilename = '';
$backFilename = null;
try {
    $uploadDirectory = ensure_private_farmer_id_directory();
    $frontFilename = generated_image_filename($frontUpload['extension']);
    $frontStoredPath = $uploadDirectory . DIRECTORY_SEPARATOR . $frontFilename;
    if (!move_uploaded_file($frontUpload['temporary_path'], $frontStoredPath)) {
        throw new RuntimeException('ID Front could not be moved into protected storage.');
    }
    $storedPaths[] = $frontStoredPath;

    if ($backUpload !== null) {
        $backFilename = generated_image_filename($backUpload['extension']);
        $backStoredPath = $uploadDirectory . DIRECTORY_SEPARATOR . $backFilename;
        if (!move_uploaded_file($backUpload['temporary_path'], $backStoredPath)) {
            throw new RuntimeException('ID Back could not be moved into protected storage.');
        }
        $storedPaths[] = $backStoredPath;
    }
} catch (Throwable $exception) {
    foreach ($storedPaths as $path) {
        if (is_file($path)) @unlink($path);
    }
    error_log('Farmer ID protected storage failed: ' . $exception->getMessage());
    api_response(500, ['success' => false, 'message' => 'The ID images could not be stored securely. Please try again.']);
}

$transactionStarted = false;
try {
    $connection->beginTransaction();
    $transactionStarted = true;
    $fullName = $nameParts['full'];
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    $residentialCityName = CABADBARAN_CITY_NAME;
    $residentialProvinceName = CABADBARAN_PROVINCE_NAME;
    $residentialBoundaryStatus = $residentialLocationMethod === 'Map' ? 'Inside Mapped Boundary' : 'Not Checked';
    $residentialBoundarySource = $residentialLocationMethod === 'Map' ? CABADBARAN_BOUNDARY_SOURCE : '';
    $residentialBoundaryCheckedAt = $residentialLocationMethod === 'Map' ? date('Y-m-d H:i:s') : null;
    $residentialLatitudeDatabaseValue = $residentialLatitude === null ? null : (float) $residentialLatitude;
    $residentialLongitudeDatabaseValue = $residentialLongitude === null ? null : (float) $residentialLongitude;
    $statement = $connection->prepare(
        "INSERT INTO farmers (
            full_name, first_name, middle_name, last_name, extension_name, gender, date_of_birth, contact_number, email, address,
            residential_city_psgc_code, residential_city_name, residential_province_name,
            residential_barangay_psgc_code, residential_barangay_name, residential_purok_sitio,
            residential_landmark, residential_location_method, residential_boundary_status,
            residential_boundary_source, residential_boundary_checked_at,
            residential_latitude, residential_longitude, password_hash, account_status, verification_status
         ) VALUES (?, ?, NULLIF(?, ''), ?, NULLIF(?, ''), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULLIF(?, ''), ?, ?, NULLIF(?, ''), ?, ?, ?, ?, 'Active', 'Pending')"
    );
    $statement->execute([
        $fullName,
        $firstName,
        $middleName,
        $lastName,
        $extensionName,
        $gender,
        $dateOfBirth,
        $contactNumber,
        $email,
        $address,
        $residentialCityCode,
        $residentialCityName,
        $residentialProvinceName,
        $residentialBarangayCode,
        $residentialBarangayName,
        $residentialPurokSitio,
        $residentialLandmark,
        $residentialLocationMethod,
        $residentialBoundaryStatus,
        $residentialBoundarySource,
        $residentialBoundaryCheckedAt,
        $residentialLatitudeDatabaseValue,
        $residentialLongitudeDatabaseValue,
        $passwordHash,
    ]);
    $farmerId = db_insert_id($connection, 'farmers');

    $latitudeDatabaseValue = $latitude === null ? null : (float) $latitude;
    $longitudeDatabaseValue = $longitude === null ? null : (float) $longitude;
    $landArea = (float) $landAreaValue;
    $cityName = CABADBARAN_CITY_NAME;
    $provinceName = CABADBARAN_PROVINCE_NAME;
    $locationReviewStatus = 'Verified';
    $locationBoundaryStatus = $farmLocationMethod === 'Map' ? 'Inside Mapped Boundary' : 'Not Checked';
    $locationBoundarySource = $farmLocationMethod === 'Map' ? CABADBARAN_BOUNDARY_SOURCE : '';
    $locationBoundaryCheckedAt = $farmLocationMethod === 'Map' ? date('Y-m-d H:i:s') : null;
    $statement = $connection->prepare(
        "INSERT INTO farm_profiles (
            farmer_id, farm_name, farm_type, agricultural_category, land_area, farm_address,
            city_psgc_code, city_name, province_name, barangay_psgc_code, barangay_name,
            purok_sitio, landmark, location_method, location_boundary_status,
            location_boundary_source, location_boundary_checked_at, location_review_status,
            latitude, longitude, main_product, status, is_primary
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULLIF(?, ''), ?, ?, NULLIF(?, ''), ?, ?, ?, ?, ?, 'Active', 1)"
    );
    $statement->execute([
        $farmerId,
        $farmName,
        $farmType,
        $agriculturalCategory,
        $landArea,
        $farmAddress,
        $farmCityCode,
        $cityName,
        $provinceName,
        $farmBarangayCode,
        $farmBarangayName,
        $farmPurokSitio,
        $farmLandmark,
        $farmLocationMethod,
        $locationBoundaryStatus,
        $locationBoundarySource,
        $locationBoundaryCheckedAt,
        $locationReviewStatus,
        $latitudeDatabaseValue,
        $longitudeDatabaseValue,
        $mainProduct,
    ]);

    $legacyImagePath = null;
    $statement = $connection->prepare(
        'INSERT INTO farmer_ids (farmer_id, id_type, id_number, image_path, front_image_path, back_image_path)
         VALUES (?, ?, NULLIF(?, \'\'), ?, ?, ?)'
    );
    $statement->execute([$farmerId, $idType, $idNumber, $legacyImagePath, $frontFilename, $backFilename]);

    $connection->commit();
    $transactionStarted = false;
    api_response(201, [
        'success' => true,
        'message' => 'Registration submitted for verification.',
        'application' => ['id' => $farmerId, 'verificationStatus' => 'Pending'],
    ]);
} catch (PDOException $exception) {
    if ($transactionStarted) $connection->rollBack();
    foreach ($storedPaths as $path) {
        if (is_file($path)) @unlink($path);
    }
    if (db_is_duplicate_key($exception)) {
        api_response(409, ['success' => false, 'message' => 'An account already uses that email address or contact number.', 'errors' => ['email' => 'Email or contact number is already registered.', 'phone' => 'Email or contact number is already registered.']]);
    }
    error_log('Farmer registration failed: ' . $exception->getMessage());
    api_response(500, ['success' => false, 'message' => 'The registration could not be completed. Please try again.']);
} catch (Throwable $exception) {
    if ($transactionStarted) $connection->rollBack();
    foreach ($storedPaths as $path) {
        if (is_file($path)) @unlink($path);
    }
    error_log('Farmer registration failed: ' . $exception->getMessage());
    api_response(500, ['success' => false, 'message' => 'The registration could not be completed. Please try again.']);
}
