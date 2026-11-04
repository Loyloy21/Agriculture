<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const FARMER_SESSION_DAYS = 30;

function farmer_bearer_token(): ?string
{
    $authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if ($authorization === '' && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $authorization = (string) ($headers['Authorization'] ?? $headers['authorization'] ?? '');
    }

    if (!preg_match('/^Bearer\s+([A-Za-z0-9_-]{40,120})$/', trim($authorization), $matches)) {
        return null;
    }

    return $matches[1];
}

function farmer_public_data(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'fullName' => (string) $row['full_name'],
        'firstName' => (string) ($row['first_name'] ?? ''),
        'middleName' => (string) ($row['middle_name'] ?? ''),
        'lastName' => (string) ($row['last_name'] ?? ''),
        'extensionName' => (string) ($row['extension_name'] ?? ''),
        'gender' => (string) ($row['gender'] ?? ''),
        'dateOfBirth' => $row['date_of_birth'] ?: null,
        'email' => (string) $row['email'],
        'contactNumber' => (string) $row['contact_number'],
        'address' => (string) $row['address'],
        'residentialLocation' => empty($row['residential_barangay_psgc_code']) ? null : [
            'cityCode' => $row['residential_city_psgc_code'] ?: null,
            'cityName' => $row['residential_city_name'] ?: null,
            'provinceName' => $row['residential_province_name'] ?: null,
            'barangayCode' => $row['residential_barangay_psgc_code'] ?: null,
            'barangayName' => $row['residential_barangay_name'] ?: null,
            'purokSitio' => $row['residential_purok_sitio'] ?: null,
            'landmark' => $row['residential_landmark'] ?: null,
            'locationMethod' => (string) ($row['residential_location_method'] ?? 'Legacy'),
            'boundaryStatus' => (string) ($row['residential_boundary_status'] ?? 'Not Checked'),
            'latitude' => $row['residential_latitude'] === null ? null : (float) $row['residential_latitude'],
            'longitude' => $row['residential_longitude'] === null ? null : (float) $row['residential_longitude'],
        ],
        'accountStatus' => (string) $row['account_status'],
        'verificationStatus' => (string) $row['verification_status'],
        'farm' => $row['farm_profile_id'] === null ? null : [
            'id' => (int) $row['farm_profile_id'],
            'name' => (string) $row['farm_name'],
            'type' => (string) $row['farm_type'],
            'agriculturalCategory' => (string) $row['agricultural_category'],
            'location' => (string) $row['farm_address'],
            'sizeHectares' => $row['land_area'] === null ? null : (float) $row['land_area'],
            'mainProduct' => (string) ($row['main_product'] ?? ''),
            'latitude' => $row['latitude'] === null ? null : (float) $row['latitude'],
            'longitude' => $row['longitude'] === null ? null : (float) $row['longitude'],
            'cityCode' => $row['city_psgc_code'] ?: null,
            'cityName' => $row['city_name'] ?: null,
            'provinceName' => $row['province_name'] ?: null,
            'barangayCode' => $row['barangay_psgc_code'] ?: null,
            'barangayName' => $row['barangay_name'] ?: null,
            'purokSitio' => $row['purok_sitio'] ?: null,
            'landmark' => $row['landmark'] ?: null,
            'locationMethod' => (string) ($row['location_method'] ?? 'Legacy'),
            'locationBoundaryStatus' => (string) ($row['location_boundary_status'] ?? 'Not Checked'),
            'locationReviewStatus' => (string) ($row['location_review_status'] ?? 'Needs Review'),
        ],
    ];
}

function authenticated_farmer(): array
{
    $token = farmer_bearer_token();
    if ($token === null) {
        api_response(401, ['success' => false, 'message' => 'Authentication is required.']);
    }

    $tokenHash = hash('sha256', $token);
    try {
        $connection = db();
        $statement = $connection->prepare(
            "SELECT fs.id AS session_id, f.id, f.full_name, f.first_name, f.middle_name, f.last_name, f.extension_name, f.gender, f.date_of_birth,
                    f.email, f.contact_number, f.address, f.account_status, f.verification_status,
                    f.residential_city_psgc_code, f.residential_city_name, f.residential_province_name,
                    f.residential_barangay_psgc_code, f.residential_barangay_name, f.residential_purok_sitio,
                    f.residential_landmark, f.residential_location_method, f.residential_boundary_status,
                    f.residential_latitude, f.residential_longitude,
                    fp.id AS farm_profile_id, fp.farm_name, fp.farm_type, fp.agricultural_category,
                    fp.farm_address, fp.land_area, fp.main_product, fp.latitude, fp.longitude,
                    fp.city_psgc_code, fp.city_name, fp.province_name, fp.barangay_psgc_code,
                    fp.barangay_name, fp.purok_sitio, fp.landmark, fp.location_method,
                    fp.location_boundary_status, fp.location_review_status
             FROM farmer_sessions fs
             JOIN farmers f ON f.id = fs.farmer_id
             LEFT JOIN farm_profiles fp ON fp.id = (
                 SELECT fp2.id FROM farm_profiles fp2
                 WHERE fp2.farmer_id = f.id AND fp2.status = 'Active'
                 ORDER BY fp2.is_primary DESC, fp2.id ASC LIMIT 1
             )
             WHERE fs.token_hash = ? AND fs.revoked_at IS NULL AND fs.expires_at > " . db_sql_utc_now($connection) . "
             LIMIT 1"
        );
        $statement->execute([$tokenHash]);
        $farmer = $statement->fetch();

        if (!$farmer) {
            api_response(401, ['success' => false, 'message' => 'Your session is invalid or has expired.']);
        }

        if ($farmer['verification_status'] !== 'Approved' || $farmer['account_status'] !== 'Active') {
            $revoke = $connection->prepare('UPDATE farmer_sessions SET revoked_at = ' . db_sql_utc_now($connection) . ' WHERE id = ?');
            $sessionId = (int) $farmer['session_id'];
            $revoke->execute([$sessionId]);
            api_response(401, ['success' => false, 'message' => 'Your account is no longer authorized to access the farmer app.']);
        }

        $touch = $connection->prepare('UPDATE farmer_sessions SET last_used_at = ' . db_sql_utc_now($connection) . ' WHERE id = ?');
        $sessionId = (int) $farmer['session_id'];
        $touch->execute([$sessionId]);

        return $farmer;
    } catch (Throwable $exception) {
        error_log('Farmer authentication failed: ' . $exception->getMessage());
        api_response(500, ['success' => false, 'message' => 'The authentication service is temporarily unavailable.']);
    }
}
