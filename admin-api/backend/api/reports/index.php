<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/farmer_auth.php';
require_once dirname(__DIR__, 3) . '/backend/lib/private_uploads.php';
require_once dirname(__DIR__, 3) . '/backend/lib/report_photo_location.php';
require_once dirname(__DIR__, 3) . '/backend/lib/weather_snapshot.php';
require_once dirname(__DIR__, 3) . '/backend/lib/damage_report_details.php';
require_once dirname(__DIR__, 3) . '/backend/lib/damage_report_codes.php';

function mobile_report_status(string $status): string
{
    return $status === 'Received' ? 'Pending' : $status;
}

function mobile_report_row(array $row, array $photos = [], ?array $details = null): array
{
    $latitude = $row['submitted_latitude'] === null ? null : (float) $row['submitted_latitude'];
    $longitude = $row['submitted_longitude'] === null ? null : (float) $row['submitted_longitude'];
    $location = trim((string) ($row['submitted_address'] ?? ''));
    if ($location === '' && $latitude !== null && $longitude !== null) {
        $location = number_format($latitude, 5, '.', '') . ', ' . number_format($longitude, 5, '.', '');
    }
    $adminResponse = ($row['admin_response_message'] ?? null) ?: (($row['rejection_reason'] ?? null) ?: (($row['admin_notification_message'] ?? null) ?: null));
    // Publish only the saved final assessment, never an admin's draft cost.
    // Existing mobile report screens already display adminResponse; no picker,
    // native-map or mobile UI changes are required to show the assessed amount.
    $finalCost = $row['status'] === 'Resolved' && isset($row['final_assessed_cost']) ? (float) $row['final_assessed_cost'] : null;
    // Category-specific reports are resolved without a peso amount; their final remarks are still published.
    $finalRemarks = $row['status'] === 'Resolved' && trim((string) ($row['assessment_remarks'] ?? '')) !== '' ? trim((string) $row['assessment_remarks']) : null;
    if ($finalCost !== null) {
        $parts = array_unique(array_filter([$finalRemarks, $adminResponse], static fn($part) => $part !== null && $part !== ''));
        $adminResponse = 'Final assessed cost: PHP ' . number_format($finalCost, 2) . ($parts ? "\n" . implode("\n", $parts) : '');
    }

    return [
        'id' => (string) $row['report_code'],
        'farmId' => isset($row['farm_profile_id']) ? (int) $row['farm_profile_id'] : null,
        'farmName' => $row['farm_name'] ?? null,
        'farmType' => (string) $row['agricultural_category'],
        'date' => substr((string) $row['submitted_at'], 0, 10),
        'description' => (string) $row['damage_description'],
        'calamityType' => (string) $row['damage_type'],
        // Null for reports made after the category-specific form (no peso guess is asked of the farmer).
        'estimatedCost' => $row['estimated_damage_cost'] === null ? null : (float) $row['estimated_damage_cost'],
        // Farmer-submitted category details; admin-verified figures are shown to the farmer only once resolved.
        'damageDetails' => damage_details_present($details, (string) $row['agricultural_category'], $row['status'] === 'Resolved'),
        'status' => mobile_report_status((string) $row['status']),
        'photos' => $photos,
        'location' => $location,
        'latitude' => $latitude,
        'longitude' => $longitude,
        'locationSource' => $row['location_source'] ?? null,
        'locationPhotoOrder' => isset($row['location_photo_order']) ? (int) $row['location_photo_order'] : null,
        'locationConfirmedAt' => $row['location_confirmed_at'] ?? null,
        'timestamp' => str_replace(' ', 'T', (string) $row['submitted_at']),
        'adminResponse' => $adminResponse,
        'finalAssessedCost' => $finalCost,
        'assessmentRemarks' => $finalRemarks,
        'statusUpdatedAt' => isset($row['response_created_at']) && $row['response_created_at'] !== null ? str_replace(' ', 'T', (string) $row['response_created_at']) : null,
    ];
}

function report_distance_km(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $earthRadius = 6371.0;
    $latitudeDelta = deg2rad($lat2 - $lat1);
    $longitudeDelta = deg2rad($lon2 - $lon1);
    $a = sin($latitudeDelta / 2) ** 2
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($longitudeDelta / 2) ** 2;
    return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

function report_ini_bytes(string $value): int
{
    $value = trim($value);
    if ($value === '') return 0;
    $unit = strtolower(substr($value, -1));
    $number = (float) $value;
    return match ($unit) {
        'g' => (int) ($number * 1024 * 1024 * 1024),
        'm' => (int) ($number * 1024 * 1024),
        'k' => (int) ($number * 1024),
        default => (int) $number,
    };
}

$farmer = authenticated_farmer();
$connection = db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $requestedCode = trim((string) ($_GET['id'] ?? ''));
    if ($requestedCode !== '') {
        $statement = $connection->prepare(
            'SELECT dr.id AS database_id, dr.report_code, dr.farm_profile_id, fp.farm_name, dr.agricultural_category, dr.damage_type,
                    dr.damage_description, dr.estimated_damage_cost, dr.status, dr.submitted_at,
                    dr.final_assessed_cost, dr.assessment_remarks,
                    dr.submitted_address, dr.submitted_latitude, dr.submitted_longitude, dr.location_source, dr.location_photo_order, dr.location_confirmed_at,
                    ar.response_message AS admin_response_message, ar.rejection_reason,
                    ar.notification_message AS admin_notification_message, ar.created_at AS response_created_at
             FROM damage_reports dr
             LEFT JOIN farm_profiles fp ON fp.id = dr.farm_profile_id AND fp.farmer_id = dr.farmer_id
             LEFT JOIN admin_responses ar ON ar.id = (SELECT ar2.id FROM admin_responses ar2 WHERE ar2.report_id = dr.id ORDER BY ar2.created_at DESC, ar2.id DESC LIMIT 1)
             WHERE dr.report_code = ? AND dr.farmer_id = ? AND dr.is_fixture = 0 LIMIT 1'
        );
        $farmerId = (int) $farmer['id'];
        $statement->execute([$requestedCode, $farmerId]);
        $row = $statement->fetch();
        if (!$row) api_response(404, ['success' => false, 'message' => 'This damage report was not found.']);

        $photoStatement = $connection->prepare('SELECT id, captured_at, gps_latitude, gps_longitude, gps_source, gps_accuracy_m, gps_recorded_at, capture_time_source, location_status FROM report_images WHERE report_id = ? ORDER BY display_order ASC, id ASC');
        $databaseId = (int) $row['database_id'];
        $photoStatement->execute([$databaseId]);
        $photos = [];
        foreach ($photoStatement->fetchAll() as $photo) {
            $photos[] = [
                'id' => (string) $photo['id'],
                'uri' => '/reports/image.php?id=' . rawurlencode((string) $photo['id']),
                'capturedAt' => $photo['captured_at'] ? str_replace(' ', 'T', (string) $photo['captured_at']) . 'Z' : null,
                'latitude' => $photo['gps_latitude'] === null ? null : (float) $photo['gps_latitude'],
                'longitude' => $photo['gps_longitude'] === null ? null : (float) $photo['gps_longitude'],
                'locationSource' => $photo['gps_source'] ?? 'legacy_unknown',
                'accuracy' => $photo['gps_accuracy_m'] === null ? null : (float) $photo['gps_accuracy_m'],
                'locationTimestamp' => $photo['gps_recorded_at'] ? str_replace(' ', 'T', $photo['gps_recorded_at']) . 'Z' : null,
                'captureTimeSource' => $photo['capture_time_source'] ?? 'legacy_unknown',
                'locationStatus' => $photo['location_status'],
            ];
        }
        $details = damage_details_for_reports($connection, [$databaseId])[$databaseId] ?? null;
        api_response(200, ['success' => true, 'data' => mobile_report_row($row, $photos, $details)]);
    }

    $statement = $connection->prepare(
        'SELECT dr.id AS database_id, dr.report_code, dr.farm_profile_id, fp.farm_name, dr.agricultural_category, dr.damage_type, dr.damage_description,
                dr.estimated_damage_cost, dr.status, dr.submitted_at, dr.submitted_address,
                dr.final_assessed_cost, dr.assessment_remarks,
                dr.submitted_latitude, dr.submitted_longitude
         FROM damage_reports dr
         LEFT JOIN farm_profiles fp ON fp.id = dr.farm_profile_id AND fp.farmer_id = dr.farmer_id
         WHERE dr.farmer_id = ? AND dr.is_fixture = 0 ORDER BY dr.submitted_at DESC, dr.id DESC'
    );
    $farmerId = (int) $farmer['id'];
    $statement->execute([$farmerId]);
    $rows = $statement->fetchAll();
    $detailsByReport = damage_details_for_reports($connection, array_column($rows, 'database_id'));
    $reports = [];
    foreach ($rows as $row) {
        $reports[] = mobile_report_row($row, [], $detailsByReport[(int) $row['database_id']] ?? null);
    }
    api_response(200, ['success' => true, 'data' => $reports]);
}

if ($method !== 'POST') {
    header('Allow: GET, POST');
    api_response(405, ['success' => false, 'message' => 'Method not allowed.']);
}

$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
$maximumPostBytes = report_ini_bytes((string) ini_get('post_max_size'));
if ($maximumPostBytes > 0 && $contentLength > $maximumPostBytes) {
    api_response(413, [
        'success' => false,
        'message' => 'The damage report upload is too large.',
        'errors' => ['photos' => 'Reduce the number or size of the photos and try again.'],
    ]);
}

$farmProfileId = filter_var($_POST['farm_profile_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$farmType = api_post_string('farm_type');
$damageType = api_post_string('damage_type');
$customDamageType = api_post_string('custom_damage_type');
$description = api_post_string('damage_description');
$estimatedCostValue = api_post_string('estimated_damage_cost');
$submittedAddress = api_post_string('submitted_address');
$submittedLatitudeValue = api_post_string('submitted_latitude');
$submittedLongitudeValue = api_post_string('submitted_longitude');
$clientSubmissionId = api_post_string('client_submission_id');
$locationSource = api_post_string('location_source');
$locationPhotoOrderValue = api_post_string('location_photo_order');
$locationPhotoOrder = $locationPhotoOrderValue === '' ? null : filter_var($locationPhotoOrderValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => DAMAGE_IMAGE_MAX_COUNT]]);
$allowedCategories = ['Crops', 'Livestock', 'Poultry', 'Fishpond'];
$allowedDamageTypes = ['Flood', 'Storm / Typhoon', 'Drought', 'Fire', 'Earthquake', 'Landslide', 'Pest / Disease', 'Other'];
$errors = [];

if (!$farmProfileId) $errors['farmId'] = 'Select the affected farm.';
if (!in_array($farmType, $allowedCategories, true)) $errors['farmType'] = 'Select a valid farm type.';
// The current app sends category-specific details (details version 1): a SUSPECTED cause, the affected
// crop/animal/fish, and the loss in the category's own measure. It no longer asks for a peso estimate and
// "What happened?" is optional. Older app builds keep the original calamity/description/cost rules.
$damageDetails = null;
$estimatedCost = null;
if (api_post_string('damage_details_version') === (string) DAMAGE_DETAILS_VERSION) {
    if (in_array($farmType, $allowedCategories, true)) {
        [$detailErrors, $damageDetails] = damage_details_validate_submission($farmType, $_POST);
        $errors += $detailErrors;
        if ($damageDetails !== null) $damageType = damage_details_damage_type($damageDetails);
    }
    if (mb_strlen($description) > 5000) $errors['description'] = 'Keep the description to 5,000 characters or fewer.';
} else {
    if (!in_array($damageType, $allowedDamageTypes, true)) $errors['calamityType'] = 'Select a valid damage type.';
    if ($damageType === 'Other') {
        if ($customDamageType === '' || mb_strlen($customDamageType) > 100) $errors['customDamageType'] = 'Specify a valid damage type.';
        else $damageType = $customDamageType;
    }
    if ($description === '' || mb_strlen($description) > 5000) $errors['description'] = 'Enter a damage description.';
    if (!is_numeric($estimatedCostValue) || (float) $estimatedCostValue < 0 || (float) $estimatedCostValue > 9999999999999.99) $errors['estimatedCost'] = 'Enter a valid estimated cost.';
    else $estimatedCost = (float) $estimatedCostValue;
}
if (mb_strlen($submittedAddress) < 5 || mb_strlen($submittedAddress) > 255) $errors['damageAddress'] = 'Enter a clear damage location or landmark.';
if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $clientSubmissionId)) $errors['form'] = 'The report submission identifier is invalid. Please reopen the form.';

// An uncertain response can be retried without uploading a second copy or notifying twice.
$farmerId = (int) $farmer['id'];
$duplicate = $connection->prepare('SELECT * FROM damage_reports WHERE farmer_id = ? AND client_submission_id = ? AND is_fixture = 0 LIMIT 1');
$duplicate->execute([$farmerId, $clientSubmissionId]);
$existingReport = $duplicate->fetch();
if (!isset($errors['form']) && $existingReport) api_response(200, ['success' => true, 'message' => 'This damage report was already saved.', 'data' => mobile_report_row($existingReport, [], damage_details_for_reports($connection, [(int) $existingReport['id']])[(int) $existingReport['id']] ?? null)]);

$submittedLatitude = $submittedLatitudeValue === '' ? null : filter_var($submittedLatitudeValue, FILTER_VALIDATE_FLOAT);
$submittedLongitude = $submittedLongitudeValue === '' ? null : filter_var($submittedLongitudeValue, FILTER_VALIDATE_FLOAT);
if (($submittedLatitudeValue === '') !== ($submittedLongitudeValue === '')) $errors['damageLocation'] = 'Provide both damage latitude and longitude, or clear both fields.';
if ($submittedLatitudeValue !== '' && ($submittedLatitude === false || $submittedLatitude < -90 || $submittedLatitude > 90)) $errors['damageLatitude'] = 'Enter a valid latitude from -90 to 90.';
if ($submittedLongitudeValue !== '' && ($submittedLongitude === false || $submittedLongitude < -180 || $submittedLongitude > 180)) $errors['damageLongitude'] = 'Enter a valid longitude from -180 to 180.';

$selectedFarm = null;
if ($farmProfileId) {
    $farmStatement = $connection->prepare("SELECT id, agricultural_category, latitude, longitude FROM farm_profiles WHERE id = ? AND farmer_id = ? AND status = 'Active' LIMIT 1");
    $farmerIdForFarm = (int) $farmer['id'];
    $farmStatement->execute([$farmProfileId, $farmerIdForFarm]);
    $selectedFarm = $farmStatement->fetch();
    if (!$selectedFarm) $errors['farmId'] = 'Select one of your active farms.';
    elseif ((string) $selectedFarm['agricultural_category'] !== $farmType) $errors['farmType'] = 'The selected farm category does not match this report.';
}

$uploads = $_FILES['photos'] ?? null;
$uploadCount = is_array($uploads) && is_array($uploads['name'] ?? null) ? count($uploads['name']) : 0;
if ($uploadCount < 1 || $uploadCount > DAMAGE_IMAGE_MAX_COUNT) $errors['photos'] = 'Attach between 1 and ' . DAMAGE_IMAGE_MAX_COUNT . ' damage photos.';

$allowedMimeTypes = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
$preparedUploads = [];
$totalUploadBytes = 0;
if ($uploadCount > 0 && $uploadCount <= DAMAGE_IMAGE_MAX_COUNT) {
    $fileInfo = new finfo(FILEINFO_MIME_TYPE);
    for ($index = 0; $index < $uploadCount; $index++) {
        $temporaryPath = (string) ($uploads['tmp_name'][$index] ?? '');
        $size = (int) ($uploads['size'][$index] ?? 0);
        $error = (int) ($uploads['error'][$index] ?? UPLOAD_ERR_NO_FILE);
        $mimeType = $error === UPLOAD_ERR_OK && is_uploaded_file($temporaryPath) ? $fileInfo->file($temporaryPath) : false;
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE || $size > DAMAGE_IMAGE_MAX_BYTES) {
            api_response(413, [
                'success' => false,
                'message' => 'A damage photo is too large.',
                'errors' => ['photos' => 'Each damage photo must be 5MB or smaller.'],
            ]);
        }
        if ($error === UPLOAD_ERR_PARTIAL) {
            $errors['photos'] = 'A damage photo was only partially uploaded. Please choose it again.';
            break;
        }
        if (in_array($error, [UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION], true)) {
            error_log('Damage report upload could not be stored; PHP upload error code ' . $error . '.');
            api_response(500, [
                'success' => false,
                'message' => 'The server could not store the damage photo. Please try again later.',
            ]);
        }
        if ($error !== UPLOAD_ERR_OK || $size < 1 || !is_string($mimeType) || !isset($allowedMimeTypes[$mimeType]) || @getimagesize($temporaryPath) === false) {
            $errors['photos'] = 'Each damage photo must be a valid JPG or PNG no larger than 5MB.';
            break;
        }
        $totalUploadBytes += $size;
        if ($totalUploadBytes > DAMAGE_IMAGE_TOTAL_MAX_BYTES) {
            api_response(413, [
                'success' => false,
                'message' => 'The combined damage photo upload is too large.',
                'errors' => ['photos' => 'Keep the combined damage photos at or below 32MB.'],
            ]);
        }
        try {
            $metadata = report_photo_metadata($_POST, $index);
        } catch (InvalidArgumentException $exception) {
            $errors['photos'] = 'Photo ' . ($index + 1) . ': ' . $exception->getMessage();
            break;
        }
        $preparedUploads[] = $metadata + [
            'temporaryPath' => $temporaryPath,
            'extension' => $allowedMimeTypes[$mimeType],
        ];
    }
}

if ($errors) api_response(422, ['success' => false, 'message' => 'Please correct the highlighted report fields.', 'errors' => $errors]);
try {
    if ($locationPhotoOrder === false) throw new InvalidArgumentException('Select a valid attached photo for the damage location.');
    $boundary = report_confirmed_location($submittedLatitude, $submittedLongitude, api_post_string('location_confirmed'), $locationSource, $locationPhotoOrder, $preparedUploads);
} catch (InvalidArgumentException $exception) {
    api_response(422, ['success' => false, 'message' => $exception->getMessage(), 'errors' => ['damageLocation' => $exception->getMessage()]]);
}
if ($boundary['status'] === 'unavailable') api_response(503, ['success' => false, 'code' => 'BOUNDARY_DATA_UNAVAILABLE', 'message' => 'Cabadbaran boundary data is temporarily unavailable. Your report has not been saved; please retry.']);
if ($boundary['inside'] !== true) api_response(422, ['success' => false, 'code' => 'PIN_OUTSIDE_CABADBARAN', 'message' => 'The confirmed damage point is outside Cabadbaran City.', 'errors' => ['damageLocation' => 'Choose the actual damage location within Cabadbaran City. Photo coordinates have not been changed.']]);

$uploadDirectory = report_image_storage_directory();
if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
    api_response(500, ['success' => false, 'message' => 'The report photos could not be stored.']);
}

$storedFiles = [];
try {
    foreach ($preparedUploads as $index => $preparedUpload) {
        $filename = bin2hex(random_bytes(20)) . '.' . $preparedUpload['extension'];
        $absolutePath = $uploadDirectory . '/' . $filename;
        if (!move_uploaded_file($preparedUpload['temporaryPath'], $absolutePath)) throw new RuntimeException('Unable to move report image.');
        $preparedUploads[$index]['filename'] = $filename;
        $storedFiles[] = $absolutePath;
    }

    $distance = null;
    $gpsStatus = 'Not checked';
    $gpsResult = 'Farmer-confirmed damage point is inside the mapped city boundary; farm boundary validation has not been performed.';
    if ($submittedLatitude !== null && $submittedLongitude !== null && $selectedFarm && $selectedFarm['latitude'] !== null && $selectedFarm['longitude'] !== null) {
        $distance = report_distance_km((float) $selectedFarm['latitude'], (float) $selectedFarm['longitude'], $submittedLatitude, $submittedLongitude);
        $gpsResult = 'Mapped city-boundary check passed. Point-to-point distance calculated; registered farm boundary validation has not been performed.';
    }

    $connection->beginTransaction();
    // RSBSA reference + per-farmer sequence, allocated under this farmer's counter lock (see damage_report_codes.php).
    ['code' => $reportCode, 'sequence' => $reportSequence] = damage_report_allocate_code($connection, $farmerId);
    $statement = $connection->prepare(
        "INSERT INTO damage_reports (report_code, report_sequence, farmer_id, farm_profile_id, agricultural_category, damage_type,
            damage_description, estimated_damage_cost, status, submitted_address, submitted_latitude, submitted_longitude, client_submission_id,
            distance_from_registered_farm, gps_validation_status, gps_validation_result,
            image_metadata_validation_status, timestamp_validation_status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Received', ?, ?, ?, ?, ?, ?, ?, 'Not checked', 'Not checked')"
    );
    $statement->execute([$reportCode, $reportSequence, $farmerId, $farmProfileId, $farmType, $damageType, $description, $estimatedCost, $submittedAddress, $submittedLatitude, $submittedLongitude, $clientSubmissionId, $distance, $gpsStatus, $gpsResult]);
    $reportId = db_insert_id($connection, 'damage_reports');
    $provenance = $connection->prepare('UPDATE damage_reports SET location_source = ?, location_photo_order = ?, location_confirmed_at = CURRENT_TIMESTAMP WHERE id = ?');
    $provenance->execute([$locationSource, $locationPhotoOrder, $reportId]);
    // Same transaction as the report: a report is never saved without its category details (or vice versa).
    if ($damageDetails !== null) damage_details_insert($connection, $reportId, $damageDetails);

    $imageStatement = $connection->prepare('INSERT INTO report_images (report_id, image_path, display_order, captured_at, gps_latitude, gps_longitude, metadata_validation_status, gps_source, gps_accuracy_m, gps_recorded_at, capture_time_source, location_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($preparedUploads as $index => $preparedUpload) {
        $relativePath = report_image_stored_path($preparedUpload['filename']);
        $displayOrder = $index + 1;
        $metadataStatus = 'Not checked';
        $imageStatement->execute([$reportId, $relativePath, $displayOrder, $preparedUpload['capturedAt'], $preparedUpload['latitude'], $preparedUpload['longitude'], $metadataStatus, $preparedUpload['gpsSource'], $preparedUpload['accuracy'], $preparedUpload['locationTimestamp'], $preparedUpload['captureTimeSource'], $preparedUpload['locationStatus']]);
    }

    $notificationType = 'Damage Report Submitted';
    $notificationTitle = 'Damage report received';
    $notificationMessage = 'Your damage report ' . $reportCode . ' was received and is awaiting review.';
    $notification = $connection->prepare('INSERT INTO notifications (farmer_id, notification_type, title, message, related_report_id) VALUES (?, ?, ?, ?, ?)');
    $notification->execute([$farmerId, $notificationType, $notificationTitle, $notificationMessage, $reportId]);
    $connection->commit();

    // The report is saved. Record the real weather at the confirmed damage point and server submission
    // time. This never throws: provider failure is stored as an "Unavailable" snapshot with its reason.
    weather_snapshot_record($connection, $reportId, (float) $submittedLatitude, (float) $submittedLongitude, $farmType, new DateTimeImmutable('now', new DateTimeZone('UTC')));

    $row = [
        'report_code' => $reportCode,
        'agricultural_category' => $farmType,
        'damage_type' => $damageType,
        'damage_description' => $description,
        'estimated_damage_cost' => $estimatedCost,
        'status' => 'Received',
        'submitted_at' => date('Y-m-d H:i:s'),
        'submitted_address' => $submittedAddress,
        'submitted_latitude' => $submittedLatitude,
        'submitted_longitude' => $submittedLongitude,
        'location_source' => $locationSource,
        'location_photo_order' => $locationPhotoOrder,
        'location_confirmed_at' => date('Y-m-d H:i:s'),
    ];
    $savedDetails = $damageDetails === null ? null : $damageDetails + ['details_version' => DAMAGE_DETAILS_VERSION, 'verified_at' => null];
    api_response(201, ['success' => true, 'message' => 'Damage report submitted.', 'data' => mobile_report_row($row, [], $savedDetails)]);
} catch (Throwable $exception) {
    try { $connection->rollBack(); } catch (Throwable) {}
    foreach ($storedFiles as $storedFile) if (is_file($storedFile)) @unlink($storedFile);
    if (db_is_duplicate_key($exception)) {
        $duplicate->execute([$farmerId, $clientSubmissionId]);
        $existingReport = $duplicate->fetch();
        if ($existingReport) api_response(200, ['success' => true, 'message' => 'This damage report was already saved.', 'data' => mobile_report_row($existingReport, [], damage_details_for_reports($connection, [(int) $existingReport['id']])[(int) $existingReport['id']] ?? null)]);
    }
    error_log('Mobile damage report submission failed: ' . $exception->getMessage());
    api_response(500, ['success' => false, 'message' => 'The damage report could not be submitted. Please try again.']);
}
