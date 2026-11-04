<?php
declare(strict_types=1);

require_once __DIR__ . '/cabadbaran.php';
require_once __DIR__ . '/notifications.php';

function weather_advisory_barangays(PDO $connection, int $advisoryId): array
{
    $statement = $connection->prepare('SELECT barangay_code, barangay_name FROM weather_advisory_barangays WHERE advisory_id = ? ORDER BY barangay_name');
    $statement->execute([$advisoryId]);
    return $statement->fetchAll();
}

function replace_weather_advisory_barangays(PDO $connection, int $advisoryId, array $barangayCodes): void
{
    $delete = $connection->prepare('DELETE FROM weather_advisory_barangays WHERE advisory_id = ?');
    $delete->execute([$advisoryId]);
    if (!$barangayCodes) return;

    $insert = $connection->prepare('INSERT INTO weather_advisory_barangays (advisory_id, barangay_code, barangay_name) VALUES (?, ?, ?)');
    foreach ($barangayCodes as $code) {
        $name = cabadbaran_barangay_name((string) $code);
        if ($name === null) throw new InvalidArgumentException('Invalid Cabadbaran barangay code.');
        $code = (string) $code;
        $insert->execute([$advisoryId, $code, $name]);
    }
}

function weather_advisory_recipient_ids(PDO $connection, string $category, string $scope, array $barangayCodes): array
{
    $sql = "SELECT DISTINCT f.id
            FROM farmers f
            INNER JOIN farm_profiles fp ON fp.farmer_id = f.id
            WHERE f.verification_status = 'Approved'
              AND f.account_status = 'Active'
              AND fp.status = 'Active'
              AND fp.location_review_status = 'Verified'
              AND fp.city_psgc_code = ?
              AND fp.agricultural_category = ?";
    $params = [CABADBARAN_CITY_CODE, $category];
    if ($scope === 'Barangays') {
        if (!$barangayCodes) return [];
        $placeholders = implode(',', array_fill(0, count($barangayCodes), '?'));
        $sql .= " AND fp.barangay_psgc_code IN ($placeholders)";
        foreach ($barangayCodes as $code) {
            $params[] = (string) $code;
        }
    }
    $sql .= ' ORDER BY f.id';
    $statement = $connection->prepare($sql);
    $statement->execute($params);
    return array_map('intval', array_column($statement->fetchAll(), 'id'));
}

function weather_advisory_recipient_count(PDO $connection, string $category, string $scope, array $barangayCodes): int
{
    return count(weather_advisory_recipient_ids($connection, $category, $scope, $barangayCodes));
}

function weather_advisory_farmer_access(array $farmer): array
{
    if (empty($farmer['farm_profile_id'])) {
        return ['eligible' => false, 'code' => 'farm_profile_missing', 'message' => 'No registered farm profile is available for advisory matching.'];
    }
    $reviewStatus = (string) ($farmer['location_review_status'] ?? 'Needs Review');
    if ($reviewStatus !== 'Verified') {
        $message = $reviewStatus === 'Outside Coverage'
            ? 'The registered farm is marked outside Cabadbaran City.'
            : 'The registered farm location is awaiting barangay review. An administrator must classify it in Registration location review.';
        return ['eligible' => false, 'code' => $reviewStatus === 'Outside Coverage' ? 'outside_city' : 'location_review_required', 'message' => $message];
    }
    if (!hash_equals(CABADBARAN_CITY_CODE, (string) ($farmer['city_psgc_code'] ?? ''))) {
        return ['eligible' => false, 'code' => 'outside_city', 'message' => 'The registered farm is not verified within Cabadbaran City.'];
    }
    if (cabadbaran_barangay_name((string) ($farmer['barangay_psgc_code'] ?? '')) === null) {
        return ['eligible' => false, 'code' => 'barangay_unverified', 'message' => 'The registered Cabadbaran barangay is missing or invalid and requires admin review.'];
    }
    if (!in_array((string) ($farmer['agricultural_category'] ?? ''), ['Crops', 'Livestock', 'Poultry', 'Fishpond'], true)) {
        return ['eligible' => false, 'code' => 'category_unverified', 'message' => 'The registered agricultural category is missing or invalid.'];
    }
    return ['eligible' => true, 'code' => 'eligible', 'message' => 'The farm has a verified Cabadbaran location and agricultural category.'];
}

function weather_advisory_farmer_eligibility(PDO $connection, int $advisoryId, int $farmerId): array
{
    $advisoryStatement = $connection->prepare(
        'SELECT id, status, agricultural_category, coverage_scope, coverage_city_psgc_code,
                effective_date, expiration_date
         FROM weather_advisories WHERE id = ? LIMIT 1'
    );
    $advisoryStatement->execute([$advisoryId]);
    $advisory = $advisoryStatement->fetch();
    if (!$advisory) {
        return ['eligible' => false, 'code' => 'advisory_not_found', 'message' => 'The advisory was not found.', 'reasons' => ['The advisory was not found.']];
    }

    $farmerStatement = $connection->prepare('SELECT id, verification_status, account_status FROM farmers WHERE id = ? LIMIT 1');
    $farmerStatement->execute([$farmerId]);
    $farmer = $farmerStatement->fetch();
    if (!$farmer) {
        return ['eligible' => false, 'code' => 'farmer_not_found', 'message' => 'The controlled farmer ID was not found.', 'reasons' => ['The controlled farmer ID was not found.']];
    }

    $reasons = [];
    $code = 'eligible';
    if ($farmer['verification_status'] !== 'Approved' || $farmer['account_status'] !== 'Active') {
        $code = 'account_ineligible';
        $reasons[] = 'Farmer account must be Approved and Active.';
    }
    if ($advisory['status'] !== 'Active') {
        if ($code === 'eligible') $code = 'not_active';
        $reasons[] = 'Advisory is not Active.';
    }
    $today = date('Y-m-d');
    if ((string) $advisory['effective_date'] > $today) {
        if ($code === 'eligible') $code = 'not_effective';
        $reasons[] = 'Effective date has not begun in Philippine time.';
    }
    if ($advisory['expiration_date'] && (string) $advisory['expiration_date'] < $today) {
        if ($code === 'eligible') $code = 'expired';
        $reasons[] = 'Advisory has expired in Philippine time.';
    }
    $matchingFarm = $connection->prepare(
        "SELECT fp.id FROM farm_profiles fp
         WHERE fp.farmer_id = ? AND fp.status = 'Active'
           AND fp.location_review_status = 'Verified'
           AND fp.city_psgc_code = ? AND fp.agricultural_category = ?
           AND (? = 'City' OR EXISTS (
               SELECT 1 FROM weather_advisory_barangays wab
               WHERE wab.advisory_id = ? AND wab.barangay_code = fp.barangay_psgc_code
           )) LIMIT 1"
    );
    $coverageCity = (string) $advisory['coverage_city_psgc_code'];
    $category = (string) $advisory['agricultural_category'];
    $scope = (string) $advisory['coverage_scope'];
    $matchingFarm->execute([$farmerId, $coverageCity, $category, $scope, $advisoryId]);
    if (!$matchingFarm->fetch(PDO::FETCH_NUM)) {
        $farmReview = $connection->prepare("SELECT COUNT(*) AS total,
                SUM(CASE WHEN location_review_status = 'Verified' THEN 1 ELSE 0 END) AS verified_count,
                SUM(CASE WHEN location_review_status = 'Verified' AND city_psgc_code = ? THEN 1 ELSE 0 END) AS city_count,
                SUM(CASE WHEN location_review_status = 'Verified' AND city_psgc_code = ? AND agricultural_category = ? THEN 1 ELSE 0 END) AS category_count
            FROM farm_profiles WHERE farmer_id = ? AND status = 'Active'");
        $farmReview->execute([$coverageCity, $coverageCity, $category, $farmerId]);
        $counts = $farmReview->fetch();
        if ((int) ($counts['total'] ?? 0) === 0) { $code = $code === 'eligible' ? 'farm_profile_missing' : $code; $reasons[] = 'No active farm is registered.'; }
        elseif ((int) ($counts['verified_count'] ?? 0) === 0) { $code = $code === 'eligible' ? 'location_review_required' : $code; $reasons[] = 'No active farm has a verified Cabadbaran barangay.'; }
        elseif ((int) ($counts['city_count'] ?? 0) === 0) { $code = $code === 'eligible' ? 'city_mismatch' : $code; $reasons[] = 'No active farm is inside the advisory city coverage.'; }
        elseif ((int) ($counts['category_count'] ?? 0) === 0) { $code = $code === 'eligible' ? 'category_mismatch' : $code; $reasons[] = 'No active farm matches the advisory agricultural category.'; }
        else { $code = $code === 'eligible' ? 'barangay_mismatch' : $code; $reasons[] = 'No active farm matches the selected barangay coverage.'; }
    }

    if (!$reasons) {
        return ['eligible' => true, 'code' => 'eligible', 'message' => 'Eligible: this farmer matches the active advisory.', 'reasons' => []];
    }
    return ['eligible' => false, 'code' => $code, 'message' => 'Excluded: ' . implode(' ', $reasons), 'reasons' => $reasons];
}

function weather_advisory_notification_message(array $advisory): string
{
    $period = 'Effective ' . $advisory['effective_date'] . ($advisory['expiration_date'] ? ' to ' . $advisory['expiration_date'] : ' until further notice');
    return 'Admin-created rule-based advisory. Coverage: ' . $advisory['location_coverage'] . '. ' . $period . '. ' . $advisory['advisory_message'];
}

/** Longest admin "Key advice" (characters): the one- or two-sentence preview farmers see first. */
const WEATHER_ADVISORY_KEY_ADVICE_MAX = 200;

/**
 * Farmer-facing preview of an advisory. Never a cut-off: it is the admin's Key advice, or the whole
 * message when that is already short, or a plain pointer to the full advice. advisory_message itself is
 * never changed.
 * @return array{text:string,source:string} source: key_advice | message | none
 */
function weather_advisory_preview(array $advisory): array
{
    $keyAdvice = trim((string) ($advisory['key_advice'] ?? ''));
    if ($keyAdvice !== '') return ['text' => $keyAdvice, 'source' => 'key_advice'];
    $message = trim((string) preg_replace('/\s+/u', ' ', (string) ($advisory['advisory_message'] ?? '')));
    if ($message !== '' && mb_strlen($message) <= WEATHER_ADVISORY_KEY_ADVICE_MAX) return ['text' => $message, 'source' => 'message'];
    $condition = (string) ($advisory['weather_condition'] ?? '');
    $about = $condition !== '' && $condition !== 'Other' ? ' ' . strtolower($condition) : '';
    return ['text' => 'Detailed' . $about . ' advice from the administrator. Open it to read the full message.', 'source' => 'none'];
}

/** "Heavy Rain · Crops · Until Oct 30, 2026": the condition, category and end date that change what to do. */
function weather_advisory_context(array $advisory): string
{
    $parts = [];
    $condition = (string) ($advisory['weather_condition'] ?? '');
    if ($condition !== '' && $condition !== 'Other') $parts[] = $condition;
    if (!empty($advisory['agricultural_category'])) $parts[] = (string) $advisory['agricultural_category'];
    if (($advisory['coverage_scope'] ?? '') === 'Barangays') $parts[] = 'Selected barangays';
    $parts[] = !empty($advisory['expiration_date']) ? 'Until ' . date('M j, Y', strtotime((string) $advisory['expiration_date'])) : 'Until further notice';
    return implode(' · ', $parts);
}

/**
 * Runs a farmer-facing advisory read that selects $column (key_advice). Until migration 0009 is applied,
 * an "undefined column" error is retried with NULL in its place so farmer screens keep working.
 */
function weather_advisory_fetch_all(PDO $connection, string $sql, array $params, string $column = 'wa.key_advice'): array
{
    try {
        $statement = $connection->prepare($sql);
        $statement->execute($params);
    } catch (PDOException $exception) {
        if (!in_array((string) $exception->getCode(), ['42703', '42S22'], true)) throw $exception;
        $statement = $connection->prepare(str_replace($column, 'NULL', $sql));
        $statement->execute($params);
    }
    return $statement->fetchAll();
}

function ensure_weather_advisory_notification(PDO $connection, array $advisory, int $farmerId, ?int $createdByOverride = null): bool
{
    $advisoryId = (int) $advisory['id'];
    $reserve = $connection->prepare(db_sql_insert_ignore($connection, 'weather_advisory_recipients', '(advisory_id, farmer_id) VALUES (?, ?)', ['advisory_id', 'farmer_id']));
    $reserve->execute([$advisoryId, $farmerId]);

    $existing = $connection->prepare('SELECT notification_id FROM weather_advisory_recipients WHERE advisory_id = ? AND farmer_id = ? FOR UPDATE');
    $existing->execute([$advisoryId, $farmerId]);
    $recipient = $existing->fetch();
    if (!$recipient || $recipient['notification_id'] !== null) return false;

    $message = weather_advisory_notification_message($advisory);
    $createdBy = $createdByOverride ?? (isset($advisory['created_by']) ? (int) $advisory['created_by'] : null);
    $notificationId = create_farmer_notification($connection, $farmerId, 'Weather Advisory', (string) $advisory['title'], $message, null, $advisoryId, $createdBy);
    $link = $connection->prepare('UPDATE weather_advisory_recipients SET notification_id = ?, notified_at = CURRENT_TIMESTAMP WHERE advisory_id = ? AND farmer_id = ?');
    $link->execute([$notificationId, $advisoryId, $farmerId]);
    return true;
}

function publish_weather_advisory_notifications(PDO $connection, int $advisoryId, int $adminId): int
{
    $statement = $connection->prepare('SELECT id, title, agricultural_category, advisory_message, effective_date, expiration_date, coverage_scope, location_coverage, status, created_by FROM weather_advisories WHERE id = ? LIMIT 1');
    $statement->execute([$advisoryId]);
    $advisory = $statement->fetch();
    if (!$advisory) throw new RuntimeException('Advisory not found.');
    if ($advisory['status'] !== 'Active') return 0;
    if ($advisory['effective_date'] > date('Y-m-d')) return 0;
    if ($advisory['expiration_date'] && $advisory['expiration_date'] < date('Y-m-d')) return 0;

    $barangays = weather_advisory_barangays($connection, $advisoryId);
    $barangayCodes = array_column($barangays, 'barangay_code');
    $farmerIds = weather_advisory_recipient_ids($connection, (string) $advisory['agricultural_category'], (string) $advisory['coverage_scope'], $barangayCodes);
    $created = 0;
    foreach ($farmerIds as $farmerId) {
        if (ensure_weather_advisory_notification($connection, $advisory, $farmerId, $adminId)) $created++;
    }
    return $created;
}

function sync_weather_advisory_notifications_for_farmer(PDO $connection, array $farmer): int
{
    $statement = $connection->prepare(
        "SELECT wa.id, wa.title, wa.advisory_message, wa.effective_date, wa.expiration_date,
                wa.location_coverage, wa.created_by
         FROM weather_advisories wa
         WHERE wa.status = 'Active'
           AND wa.effective_date <= CURRENT_DATE
           AND (wa.expiration_date IS NULL OR wa.expiration_date >= CURRENT_DATE)
           AND EXISTS (
               SELECT 1 FROM farm_profiles fp
               WHERE fp.farmer_id = ? AND fp.status = 'Active'
                 AND fp.location_review_status = 'Verified'
                 AND fp.city_psgc_code = wa.coverage_city_psgc_code
                 AND fp.agricultural_category = wa.agricultural_category
                 AND (wa.coverage_scope = 'City' OR EXISTS (
                     SELECT 1 FROM weather_advisory_barangays wab
                     WHERE wab.advisory_id = wa.id AND wab.barangay_code = fp.barangay_psgc_code
                 ))
           )"
    );
    $farmerId = (int) $farmer['id'];
    $statement->execute([$farmerId]);
    $advisories = $statement->fetchAll();

    $connection->beginTransaction();
    try {
        $created = 0;
        foreach ($advisories as $advisory) {
            if (ensure_weather_advisory_notification($connection, $advisory, (int) $farmer['id'])) $created++;
        }
        $connection->commit();
        return $created;
    } catch (Throwable $exception) {
        $connection->rollBack();
        throw $exception;
    }
}

/** A rejected admin advisory action whose message is safe to show to the administrator. */
final class WeatherAdvisoryActionException extends RuntimeException
{
}

/** Registration location review: classify a farm into a Cabadbaran barangay ($choice code) or 'OUTSIDE'. */
function weather_advisory_classify_farm_location(PDO $connection, int $farmProfileId, string $choice): void
{
    if ($choice === 'OUTSIDE') {
        $statement = $connection->prepare("UPDATE farm_profiles SET city_psgc_code = NULL, city_name = NULL, province_name = NULL, barangay_psgc_code = NULL, barangay_name = NULL, location_review_status = 'Outside Coverage' WHERE id = ?");
        $statement->execute([$farmProfileId]);
        return;
    }
    $barangayName = cabadbaran_barangay_name($choice);
    if ($barangayName === null) throw new WeatherAdvisoryActionException('Select a valid Cabadbaran barangay or mark the farm outside coverage.');
    $statement = $connection->prepare("UPDATE farm_profiles SET city_psgc_code = ?, city_name = ?, province_name = ?, barangay_psgc_code = ?, barangay_name = ?, location_review_status = 'Verified' WHERE id = ?");
    $statement->execute([CABADBARAN_CITY_CODE, CABADBARAN_CITY_NAME, CABADBARAN_PROVINCE_NAME, $choice, $barangayName, $farmProfileId]);
}

/**
 * Creates ($advisoryId null) or updates an advisory with its barangay coverage, then notifies eligible
 * farmers when it is Active - all in one transaction. Fields are validated by the caller.
 * $advisory: title, category, weather_condition, coverage_label, scope, barangay_codes, message, key_advice,
 *            effective_date, expiration_date (Y-m-d or null), status
 * @return array{id:int,notified:int}
 */
function weather_advisory_save(PDO $connection, ?int $advisoryId, array $advisory, int $adminId): array
{
    $connection->beginTransaction();
    try {
        $values = [$advisory['title'], $advisory['category'], $advisory['weather_condition'], $advisory['coverage_label'], $advisory['scope'], CABADBARAN_CITY_CODE, CABADBARAN_CITY_NAME, CABADBARAN_PROVINCE_NAME, $advisory['message'], $advisory['key_advice'], $advisory['effective_date'], $advisory['expiration_date'], $advisory['status'], $advisory['status']];
        if ($advisoryId === null) {
            $statement = $connection->prepare("INSERT INTO weather_advisories (title, agricultural_category, weather_condition, location_coverage, coverage_scope, coverage_city_psgc_code, coverage_city_name, coverage_province_name, advisory_message, key_advice, effective_date, expiration_date, status, published_at, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CASE WHEN ? = 'Active' THEN CURRENT_TIMESTAMP ELSE NULL END, ?)");
            $statement->execute([...$values, $adminId]);
            $advisoryId = db_insert_id($connection, 'weather_advisories');
        } else {
            $lock = $connection->prepare('SELECT id FROM weather_advisories WHERE id = ? FOR UPDATE');
            $lock->execute([$advisoryId]);
            if (!$lock->fetch()) throw new RuntimeException('Advisory not found.');
            $statement = $connection->prepare("UPDATE weather_advisories SET title = ?, agricultural_category = ?, weather_condition = ?, location_coverage = ?, coverage_scope = ?, coverage_city_psgc_code = ?, coverage_city_name = ?, coverage_province_name = ?, advisory_message = ?, key_advice = ?, effective_date = ?, expiration_date = ?, status = ?, published_at = CASE WHEN ? = 'Active' THEN COALESCE(published_at, CURRENT_TIMESTAMP) ELSE published_at END, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $statement->execute([...$values, $advisoryId]);
        }
        replace_weather_advisory_barangays($connection, $advisoryId, $advisory['barangay_codes']);
        $notified = $advisory['status'] === 'Active' ? publish_weather_advisory_notifications($connection, $advisoryId, $adminId) : 0;
        $connection->commit();
        return ['id' => $advisoryId, 'notified' => $notified];
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) $connection->rollBack();
        throw $exception;
    }
}

/**
 * Publishes ('Active') or archives an advisory; publishing notifies eligible farmers once.
 * Throws WeatherAdvisoryActionException for an expired advisory.
 * @return int new farmer notifications created
 */
function weather_advisory_set_status(PDO $connection, int $advisoryId, string $newStatus, int $adminId): int
{
    $connection->beginTransaction();
    try {
        if ($newStatus === 'Active') {
            $dateCheck = $connection->prepare('SELECT expiration_date FROM weather_advisories WHERE id = ? FOR UPDATE');
            $dateCheck->execute([$advisoryId]);
            $dateRow = $dateCheck->fetch();
            if (!$dateRow) throw new RuntimeException('Advisory not found.');
            if ($dateRow['expiration_date'] && $dateRow['expiration_date'] < date('Y-m-d')) throw new WeatherAdvisoryActionException('An expired advisory cannot be published. Update its expiration date first.');
        }
        $statement = $connection->prepare("UPDATE weather_advisories SET status = ?, published_at = CASE WHEN ? = 'Active' THEN COALESCE(published_at, CURRENT_TIMESTAMP) ELSE published_at END, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $statement->execute([$newStatus, $newStatus, $advisoryId]);
        if ($statement->rowCount() === 0) {
            $exists = $connection->prepare('SELECT id FROM weather_advisories WHERE id = ?');
            $exists->execute([$advisoryId]);
            if (!$exists->fetch()) throw new RuntimeException('Advisory not found.');
        }
        $notified = $newStatus === 'Active' ? publish_weather_advisory_notifications($connection, $advisoryId, $adminId) : 0;
        $connection->commit();
        return $notified;
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) $connection->rollBack();
        throw $exception;
    }
}

/**
 * Advisories for the admin list with coverage barangays and current eligible-recipient counts.
 * $filters: search, advisory_id (int|null), category ('' or valid), status ('' or valid)
 */
function weather_advisory_admin_list(PDO $connection, array $filters): array
{
    $where = []; $params = [];
    $search = (string) ($filters['search'] ?? '');
    if ($search !== '') { $where[] = '(LOWER(wa.title) LIKE LOWER(?) OR LOWER(wa.location_coverage) LIKE LOWER(?) OR LOWER(wa.weather_condition) LIKE LOWER(?))'; $term = "%{$search}%"; $params = [$term, $term, $term]; }
    if (!empty($filters['advisory_id'])) { $where[] = 'wa.id = ?'; $params[] = (int) $filters['advisory_id']; }
    if (($filters['category'] ?? '') !== '') { $where[] = 'wa.agricultural_category = ?'; $params[] = $filters['category']; }
    if (($filters['status'] ?? '') !== '') { $where[] = 'wa.status = ?'; $params[] = $filters['status']; }
    $barangayLists = db_is_pgsql($connection)
        ? "string_agg(wab.barangay_code, ',' ORDER BY wab.barangay_name) AS barangay_codes, string_agg(wab.barangay_name, ', ' ORDER BY wab.barangay_name) AS barangay_names"
        : "GROUP_CONCAT(wab.barangay_code ORDER BY wab.barangay_name SEPARATOR ',') AS barangay_codes, GROUP_CONCAT(wab.barangay_name ORDER BY wab.barangay_name SEPARATOR ', ') AS barangay_names";
    $sql = "SELECT wa.*, a.full_name AS creator_name, {$barangayLists} FROM weather_advisories wa LEFT JOIN admins a ON a.id = wa.created_by LEFT JOIN weather_advisory_barangays wab ON wab.advisory_id = wa.id" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' GROUP BY wa.id, a.full_name ORDER BY wa.created_at DESC, wa.id DESC';
    $statement = $connection->prepare($sql);
    $statement->execute($params);
    $advisories = $statement->fetchAll();
    foreach ($advisories as &$advisory) {
        $codes = $advisory['barangay_codes'] ? explode(',', (string) $advisory['barangay_codes']) : [];
        $advisory['recipient_count'] = weather_advisory_recipient_count($connection, (string) $advisory['agricultural_category'], (string) $advisory['coverage_scope'], $codes);
    }
    unset($advisory);
    return $advisories;
}

/** Registration farm locations awaiting barangay review. */
function weather_advisory_locations_needing_review(PDO $connection): array
{
    return $connection->query("SELECT fp.id, fp.farm_address, fp.farm_name, fp.agricultural_category, f.full_name, f.verification_status FROM farm_profiles fp JOIN farmers f ON f.id = fp.farmer_id WHERE fp.location_review_status = 'Needs Review' ORDER BY f.date_registered DESC")->fetchAll();
}
