<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/farmer_auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    api_response(405, ['success' => false, 'message' => 'Method not allowed.']);
}

$body = api_json_body();
$email = strtolower(trim((string) ($body['email'] ?? '')));
$password = (string) ($body['password'] ?? '');

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
    api_response(422, ['success' => false, 'status' => 'invalid', 'message' => 'Invalid email or password.']);
}

try {
    $connection = db();
    $statement = $connection->prepare(
        'SELECT f.id, f.full_name, f.first_name, f.middle_name, f.last_name, f.extension_name, f.gender, f.date_of_birth, f.email,
                f.contact_number, f.address, f.password_hash, f.account_status, f.verification_status,
                f.residential_city_psgc_code, f.residential_city_name, f.residential_province_name,
                f.residential_barangay_psgc_code, f.residential_barangay_name, f.residential_purok_sitio,
                f.residential_landmark, f.residential_location_method, f.residential_boundary_status,
                f.residential_latitude, f.residential_longitude,
                f.rejection_reason, fp.id AS farm_profile_id, fp.farm_name, fp.farm_type,
                fp.agricultural_category, fp.farm_address, fp.land_area, fp.main_product, fp.latitude, fp.longitude,
                fp.city_psgc_code, fp.city_name, fp.province_name, fp.barangay_psgc_code,
                fp.barangay_name, fp.purok_sitio, fp.landmark, fp.location_method,
                fp.location_boundary_status, fp.location_review_status
         FROM farmers f
         LEFT JOIN farm_profiles fp ON fp.id = (
             SELECT fp2.id FROM farm_profiles fp2
             WHERE fp2.farmer_id = f.id AND fp2.status = \'Active\'
             ORDER BY fp2.is_primary DESC, fp2.id ASC LIMIT 1
         )
         WHERE f.email = ? LIMIT 1'
    );
    $statement->execute([$email]);
    $farmer = $statement->fetch();

    $dummyHash = '$2y$12$mebfjQ20g/UOflrj4qs/F.nZHQBPXuDcrdYRrywdJrE/KvnYhaBw2';
    $passwordHash = is_array($farmer) && is_string($farmer['password_hash']) && $farmer['password_hash'] !== ''
        ? $farmer['password_hash']
        : $dummyHash;
    if (!password_verify($password, $passwordHash) || !$farmer || empty($farmer['password_hash'])) {
        api_response(401, ['success' => false, 'status' => 'invalid', 'message' => 'Invalid email or password.']);
    }

    if ($farmer['verification_status'] === 'Pending') {
        api_response(403, ['success' => false, 'status' => 'pending', 'message' => 'Your registration is still awaiting verification.']);
    }

    if ($farmer['verification_status'] === 'Rejected') {
        api_response(403, [
            'success' => false,
            'status' => 'rejected',
            'message' => 'Your farmer registration was rejected.',
            'rejectionReason' => $farmer['rejection_reason'] ?: null,
        ]);
    }

    if ($farmer['account_status'] === 'Blocked') {
        api_response(403, ['success' => false, 'status' => 'blocked', 'message' => 'This farmer account is blocked. Please contact the Department of Agriculture.']);
    }

    if ($farmer['account_status'] !== 'Active' || $farmer['verification_status'] !== 'Approved') {
        api_response(403, ['success' => false, 'status' => 'inactive', 'message' => 'This farmer account is inactive. Please contact the Department of Agriculture.']);
    }

    $plainToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $tokenHash = hash('sha256', $plainToken);
    $connection->beginTransaction();
    try {
        $utcNow = db_sql_utc_now($connection);
        $cleanup = $connection->prepare("DELETE FROM farmer_sessions WHERE farmer_id = ? AND (expires_at <= {$utcNow} OR revoked_at IS NOT NULL)");
        $farmerId = (int) $farmer['id'];
        $cleanup->execute([$farmerId]);

        $insert = $connection->prepare("INSERT INTO farmer_sessions (farmer_id, token_hash, expires_at, last_used_at) VALUES (?, ?, {$utcNow} + INTERVAL '30' DAY, {$utcNow})");
        $insert->execute([$farmerId, $tokenHash]);
        $connection->commit();
    } catch (Throwable $exception) {
        $connection->rollBack();
        throw $exception;
    }

    api_response(200, [
        'success' => true,
        'status' => 'authenticated',
        'token' => $plainToken,
        'expiresInDays' => FARMER_SESSION_DAYS,
        'farmer' => farmer_public_data($farmer),
    ]);
} catch (Throwable $exception) {
    error_log('Farmer login failed: ' . $exception->getMessage());
    api_response(500, ['success' => false, 'status' => 'error', 'message' => 'Login is temporarily unavailable. Please try again.']);
}
