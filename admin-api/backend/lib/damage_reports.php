<?php
declare(strict_types=1);

require_once __DIR__ . '/damage_report_details.php';
require_once __DIR__ . '/farmer_identity.php';

function damage_report_allowed_statuses(): array
{
    return ['Received', 'Under Review', 'Approved', 'Rejected', 'Resolved'];
}

function damage_report_allowed_categories(): array
{
    return ['Crops', 'Livestock', 'Poultry', 'Fishpond'];
}

function damage_report_valid_date(string $value): bool
{
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return false;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date !== false && $date->format('Y-m-d') === $value;
}

function damage_report_filters_from_input(array $input): array
{
    $legacyDate = trim((string) ($input['submitted_date'] ?? ''));
    $dateFrom = trim((string) ($input['date_from'] ?? ''));
    $dateTo = trim((string) ($input['date_to'] ?? ''));
    if ($dateFrom === '' && $dateTo === '' && damage_report_valid_date($legacyDate)) {
        $dateFrom = $legacyDate;
        $dateTo = $legacyDate;
    }

    return [
        'search' => mb_substr(trim((string) ($input['search'] ?? '')), 0, 200),
        'category' => in_array((string) ($input['category'] ?? ''), damage_report_allowed_categories(), true)
            ? (string) $input['category']
            : '',
        'status' => in_array((string) ($input['status'] ?? ''), damage_report_allowed_statuses(), true)
            ? (string) $input['status']
            : '',
        'date_from' => damage_report_valid_date($dateFrom) ? $dateFrom : '',
        'date_to' => damage_report_valid_date($dateTo) ? $dateTo : '',
        'damage_type' => mb_substr(trim((string) ($input['damage_type'] ?? '')), 0, 100),
    ];
}

function damage_report_filter_error(array $filters): string
{
    if ($filters['date_from'] !== '' && $filters['date_to'] !== '' && $filters['date_from'] > $filters['date_to']) {
        return 'The start date must not be later than the end date.';
    }
    return '';
}

/** @return array{0:array,1:array} */
function damage_report_where(array $filters, ?int $reportId = null): array
{
    $filterError = damage_report_filter_error($filters);
    if ($filterError !== '') {
        throw new InvalidArgumentException($filterError);
    }

    $where = ['dr.is_fixture = 0'];
    $params = [];

    if ($reportId !== null) {
        $where[] = 'dr.id = ?';
        $params[] = $reportId;
    } else {
        if ($filters['search'] !== '') {
            // LOWER() on both sides keeps MySQL's case-insensitive matching on PostgreSQL too.
            $where[] = '(LOWER(f.full_name) LIKE LOWER(?) OR LOWER(fp.farm_name) LIKE LOWER(?) OR LOWER(dr.report_code) LIKE LOWER(?))';
            $searchValue = '%' . $filters['search'] . '%';
            array_push($params, $searchValue, $searchValue, $searchValue);
        }
        if ($filters['category'] !== '') {
            $where[] = 'dr.agricultural_category = ?';
            $params[] = $filters['category'];
        }
        if ($filters['status'] !== '') {
            $where[] = 'dr.status = ?';
            $params[] = $filters['status'];
        }
        if ($filters['date_from'] !== '') {
            $where[] = 'dr.submitted_at >= ?';
            $params[] = $filters['date_from'] . ' 00:00:00';
        }
        if ($filters['date_to'] !== '') {
            $where[] = 'dr.submitted_at < ?';
            $params[] = (new DateTimeImmutable($filters['date_to']))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
        }
        if ($filters['damage_type'] !== '') {
            $where[] = 'LOWER(dr.damage_type) LIKE LOWER(?)';
            $params[] = '%' . $filters['damage_type'] . '%';
        }
    }

    return [$where, $params];
}

function damage_report_count(PDO $connection, array $filters): int
{
    [$where, $params] = damage_report_where($filters);
    $sql = 'SELECT COUNT(*) AS total FROM damage_reports dr
            INNER JOIN farmers f ON f.id = dr.farmer_id
            LEFT JOIN farm_profiles fp ON fp.id = dr.farm_profile_id
            WHERE ' . implode(' AND ', $where);
    $statement = $connection->prepare($sql);
    $statement->execute($params);
    return (int) $statement->fetch()['total'];
}

function damage_report_fetch(PDO $connection, array $filters, ?int $reportId = null, bool $includeRelated = false, bool $includeImagePaths = false, ?int $limit = null, int $offset = 0): array
{
    [$where, $params] = damage_report_where($filters, $reportId);
    $sql = 'SELECT dr.id, dr.report_code, dr.report_sequence, dr.agricultural_category, dr.damage_type, dr.damage_description, dr.estimated_damage_cost,
                   dr.final_assessed_cost, dr.assessment_remarks, dr.assessed_by_admin_id, dr.assessed_at, dr.resolved_at,
                   dr.status, dr.submitted_at, dr.submitted_address, dr.submitted_latitude, dr.submitted_longitude, dr.distance_from_registered_farm,
                   dr.location_source, dr.location_photo_order, dr.location_confirmed_at,
                   dr.gps_validation_status, dr.gps_validation_result, dr.image_metadata_validation_status, dr.timestamp_validation_status, dr.validation_warning,
                   f.full_name, f.contact_number, f.email, f.address, fi.id_number AS rsbsa_id_number,
                   fp.id AS farm_profile_id, fp.farm_name, fp.farm_type, fp.agricultural_category AS farm_category, fp.main_product,
                   fp.land_area, fp.farm_address, fp.barangay_name AS farm_barangay, fp.city_name AS farm_city, fp.province_name AS farm_province,
                   fp.latitude AS registered_latitude, fp.longitude AS registered_longitude,
                   ar.response_message AS admin_response_message, ar.rejection_reason AS admin_rejection_reason,
                   ar.notification_message AS admin_notification_message, ar.created_at AS admin_response_at,
                   assessor.full_name AS assessed_by_name
            FROM damage_reports dr
            INNER JOIN farmers f ON f.id = dr.farmer_id
            LEFT JOIN farm_profiles fp ON fp.id = dr.farm_profile_id
            LEFT JOIN farmer_ids fi ON fi.farmer_id = f.id AND fi.id_type = \'RSBSA ID\'
            LEFT JOIN admin_responses ar ON ar.id = (
                SELECT ar2.id FROM admin_responses ar2 WHERE ar2.report_id = dr.id ORDER BY ar2.created_at DESC, ar2.id DESC LIMIT 1
            )
            LEFT JOIN admins assessor ON assessor.id = dr.assessed_by_admin_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY dr.submitted_at DESC, dr.id DESC';

    if ($limit !== null) {
        if ($reportId !== null || $limit < 1 || $offset < 0) throw new InvalidArgumentException('Invalid report page.');
        $sql .= ' LIMIT ? OFFSET ?';
        $params[] = $limit;
        $params[] = $offset;
    }

    $statement = $connection->prepare($sql);
    $statement->execute($params);
    $reports = $statement->fetchAll();

    // Category-specific details (farmer-submitted and admin-verified, kept separate); null for older reports.
    $detailsByReport = damage_details_for_reports($connection, array_column($reports, 'id'));
    foreach ($reports as &$listed) {
        $listed['details'] = damage_details_present($detailsByReport[(int) $listed['id']] ?? null, (string) $listed['agricultural_category']);
        // Admin pages only: the farmer's current RSBSA reference (the report code keeps the one used at submission).
        $listed['rsbsa_reference'] = is_string($listed['rsbsa_id_number']) ? rsbsa_reference_normalize($listed['rsbsa_id_number']) : null;
        unset($listed['rsbsa_id_number']);
    }
    unset($listed);

    if (!$includeRelated || !$reports) {
        return $reports;
    }

    $imageFields = $includeImagePaths ? 'id, image_path, display_order,' : 'id, display_order,';
    $imageStatement = $connection->prepare(
        'SELECT ' . $imageFields . ' captured_at, gps_latitude, gps_longitude, metadata_validation_status,
          gps_source, gps_accuracy_m, gps_recorded_at, capture_time_source, location_status
         FROM report_images WHERE report_id = ? ORDER BY display_order ASC, id ASC'
    );
    $weatherStatement = $connection->prepare(
        'SELECT snapshot_status, unavailable_reason, unavailable_code, provider, observation_type,
                requested_latitude, requested_longitude, provider_latitude, provider_longitude, location_basis,
                observed_at_utc, retrieved_at_utc, report_submitted_at_utc, weather_code,
                weather_condition, temperature, apparent_temperature, humidity, rainfall, precipitation_mm,
                precipitation_last_6h_mm, wind_speed_kmh, wind_gust_kmh, wind_direction_deg, wind_information,
                units_json, rule_set_version, rule_category, rule_result_json, recorded_at
         FROM weather_data WHERE report_id = ? ORDER BY recorded_at DESC, id DESC LIMIT 1'
    );
    foreach ($reports as &$report) {
        $currentId = (int) $report['id'];
        $imageStatement->execute([$currentId]);
        $report['images'] = $imageStatement->fetchAll();
        $weatherStatement->execute([$currentId]);
        $weather = $weatherStatement->fetch() ?: null;
        if ($weather !== null) {
            $decoded = json_decode((string) ($weather['rule_result_json'] ?? ''), true);
            $weather['rule_result'] = is_array($decoded) ? $decoded : null;
            $weatherUnits = json_decode((string) ($weather['units_json'] ?? ''), true);
            $weather['units'] = is_array($weatherUnits) ? $weatherUnits : null;
            // Read-time only (nothing is written back): how far the provider's model grid point is from the damage point.
            $weather['provider_distance_km'] = null;
            if ($weather['requested_latitude'] !== null && $weather['provider_latitude'] !== null && $weather['requested_longitude'] !== null && $weather['provider_longitude'] !== null) {
                $dLat = deg2rad((float) $weather['provider_latitude'] - (float) $weather['requested_latitude']);
                $dLon = deg2rad((float) $weather['provider_longitude'] - (float) $weather['requested_longitude']);
                $h = sin($dLat / 2) ** 2 + cos(deg2rad((float) $weather['requested_latitude'])) * cos(deg2rad((float) $weather['provider_latitude'])) * sin($dLon / 2) ** 2;
                $weather['provider_distance_km'] = round(6371.0 * 2 * atan2(sqrt($h), sqrt(1 - $h)), 1);
            }
            unset($weather['rule_result_json'], $weather['units_json']);
        }
        $report['weather'] = $weather;
        $report['history'] = damage_report_history($connection, $report);
    }
    unset($report);

    return $reports;
}

/**
 * Recorded review events of one report, oldest first: the submission, each status change or message
 * (admin_responses), each saved draft assessment, and each changed admin-verified save. Nothing is inferred.
 *
 * @return list<array{at:?string,event:string,status:?string,notes:?string,by:?string}>
 */
function damage_report_history(PDO $connection, array $report): array
{
    $reportId = (int) $report['id'];
    $events = [['at' => $report['submitted_at'], 'event' => 'Report submitted', 'status' => 'Received', 'notes' => null, 'by' => 'Farmer (mobile app)', 'order' => 0]];
    $responses = $connection->prepare('SELECT r.previous_status, r.new_status, r.response_message, r.rejection_reason, r.created_at, a.full_name
        FROM admin_responses r LEFT JOIN admins a ON a.id = r.admin_id WHERE r.report_id = ? ORDER BY r.created_at ASC, r.id ASC');
    $responses->execute([$reportId]);
    foreach ($responses->fetchAll() as $row) {
        $changed = (string) $row['previous_status'] !== (string) $row['new_status'];
        $notes = array_values(array_filter([trim((string) $row['response_message']), trim((string) $row['rejection_reason']) !== '' ? 'Rejection reason: ' . trim((string) $row['rejection_reason']) : ''], static fn ($part) => $part !== ''));
        $events[] = ['at' => $row['created_at'], 'event' => $changed ? 'Status changed from ' . $row['previous_status'] . ' to ' . $row['new_status'] : 'Message to farmer (status unchanged)',
            'status' => (string) $row['new_status'], 'notes' => $notes ? implode("\n", $notes) : null, 'by' => $row['full_name'], 'order' => $row['new_status'] === 'Resolved' ? 3 : 1];
    }
    // Resolutions are already listed through their admin response; draft saves are listed from the audit.
    $audits = $connection->prepare("SELECT d.new_assessed_cost, d.new_remarks, d.new_status, d.created_at, a.full_name
        FROM damage_report_assessment_audit d LEFT JOIN admins a ON a.id = d.admin_id WHERE d.report_id = ? AND d.action = 'Saved' ORDER BY d.created_at ASC, d.id ASC");
    $audits->execute([$reportId]);
    foreach ($audits->fetchAll() as $row) {
        $notes = trim((string) $row['new_remarks']);
        if ($row['new_assessed_cost'] !== null) $notes = trim('Draft assessed cost: PHP ' . number_format((float) $row['new_assessed_cost'], 2) . "\n" . $notes);
        $events[] = ['at' => $row['created_at'], 'event' => 'Assessment draft saved', 'status' => (string) $row['new_status'], 'notes' => $notes !== '' ? $notes : null, 'by' => $row['full_name'], 'order' => 2];
    }
    $verifications = $connection->prepare('SELECT v.verified_cause, v.verified_summary, v.review_notes, v.report_status, v.created_at, a.full_name
        FROM damage_report_verification_audit v LEFT JOIN admins a ON a.id = v.admin_id WHERE v.report_id = ? ORDER BY v.created_at ASC, v.id ASC');
    $verifications->execute([$reportId]);
    foreach ($verifications->fetchAll() as $row) {
        $notes = array_values(array_filter([(string) $row['verified_summary'], $row['verified_cause'] !== null ? 'Verified cause: ' . $row['verified_cause'] : '', trim((string) $row['review_notes'])], static fn ($part) => $part !== ''));
        $events[] = ['at' => $row['created_at'], 'event' => 'Admin-verified figures saved', 'status' => (string) $row['report_status'], 'notes' => $notes ? implode("\n", $notes) : null, 'by' => $row['full_name'], 'order' => 2];
    }
    // Same-second events (timestamps have one-second precision) keep the workflow order: submission, status
    // changes, drafts and verifications, then the resolution. usort is stable for equal keys.
    usort($events, static fn (array $a, array $b): int => [(string) $a['at'], $a['order']] <=> [(string) $b['at'], $b['order']]);
    return array_map(static function (array $event): array { unset($event['order']); return $event; }, $events);
}
