<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/farmer_auth.php';
require_once dirname(__DIR__, 3) . '/backend/lib/weather_advisories.php';

$farmer = authenticated_farmer();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    api_response(405, ['success' => false, 'message' => 'Method not allowed.']);
}

$requestedId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$requestedCategory = trim((string) ($_GET['category'] ?? ''));
$allowedCategories = ['Crops', 'Livestock', 'Poultry', 'Fishpond'];
if ($requestedCategory !== '' && !in_array($requestedCategory, $allowedCategories, true)) {
    api_response(422, ['success' => false, 'message' => 'Select a valid advisory category.']);
}

$connection = db();
$farmerId = (int) $farmer['id'];
$farmAccess = $connection->prepare("SELECT agricultural_category FROM farm_profiles WHERE farmer_id = ? AND status = 'Active' AND location_review_status = 'Verified' AND city_psgc_code = ?");
$cityCode = CABADBARAN_CITY_CODE;
$farmAccess->execute([$farmerId, $cityCode]);
$eligibleCategories = array_values(array_unique(array_column($farmAccess->fetchAll(), 'agricultural_category')));
$access = $eligibleCategories
    ? ['eligible' => true, 'code' => 'eligible', 'message' => 'Advisories are matched against all active verified farms.']
    : ['eligible' => false, 'code' => 'location_review_required', 'message' => 'No active farm has a verified Cabadbaran City location and category.'];

if (!$access['eligible']) {
    if ($requestedId) api_response(404, ['success' => false, 'message' => 'This weather advisory is not available for your registered farm.']);
    api_response(200, ['success' => true, 'data' => ['items' => [], 'eligibility' => $access]]);
}
if ($requestedCategory !== '' && !in_array($requestedCategory, $eligibleCategories, true)) {
    if ($requestedId) api_response(404, ['success' => false, 'message' => 'This weather advisory is not available for your registered farm category.']);
    api_response(200, ['success' => true, 'data' => [
        'items' => [],
        'eligibility' => [
            'eligible' => false,
            'code' => 'category_mismatch',
            'message' => 'No active farm uses the requested agricultural category.',
        ],
    ]]);
}

$baseSql = "SELECT wa.id, wa.title, wa.agricultural_category, wa.weather_condition, wa.location_coverage, wa.coverage_scope,
                   wa.advisory_message, wa.key_advice AS key_advice, wa.effective_date, wa.expiration_date, wa.published_at, wa.updated_at
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
              )";
if ($requestedCategory !== '') $baseSql .= ' AND wa.agricultural_category = ?';

try {
    try {
        sync_weather_advisory_notifications_for_farmer($connection, $farmer);
    } catch (Throwable $exception) {
        error_log('Farmer weather advisory notification sync failed: ' . $exception->getMessage());
    }
    if ($requestedId) {
        $rows = weather_advisory_fetch_all($connection, $baseSql . ' AND wa.id = ? LIMIT 1', $requestedCategory !== '' ? [$farmerId, $requestedCategory, $requestedId] : [$farmerId, $requestedId]);
    } else {
        // "published_at IS NULL" keeps MySQL's DESC order (NULLs last) on PostgreSQL, which sorts NULLs first.
        $rows = weather_advisory_fetch_all($connection, $baseSql . ' ORDER BY wa.effective_date DESC, wa.published_at IS NULL, wa.published_at DESC, wa.id DESC', $requestedCategory !== '' ? [$farmerId, $requestedCategory] : [$farmerId]);
    }
} catch (Throwable $exception) {
    error_log('Farmer weather advisory query failed: ' . $exception->getMessage());
    api_response(500, ['success' => false, 'message' => 'Weather advisories are temporarily unavailable.']);
}

if ($requestedId && !$rows) api_response(404, ['success' => false, 'message' => 'This weather advisory is not currently effective for your registered farm.']);
$advisories = array_map(static fn(array $row): array => [
    'id' => (string) $row['id'],
    'title' => (string) $row['title'],
    'category' => (string) $row['agricultural_category'],
    'weatherCondition' => (string) $row['weather_condition'],
    'locationCoverage' => (string) $row['location_coverage'],
    // The complete original message; keyAdvice/preview are the short farmer-facing summary.
    'message' => (string) $row['advisory_message'],
    'keyAdvice' => $row['key_advice'] === null || trim((string) $row['key_advice']) === '' ? null : trim((string) $row['key_advice']),
    'preview' => weather_advisory_preview($row),
    'context' => weather_advisory_context($row),
    'coverageScope' => (string) $row['coverage_scope'],
    'effectiveDate' => (string) $row['effective_date'],
    'expirationDate' => $row['expiration_date'] ?: null,
    'publishedAt' => $row['published_at'] ? str_replace(' ', 'T', (string) $row['published_at']) . '+08:00' : null,
    'updatedAt' => str_replace(' ', 'T', (string) $row['updated_at']) . '+08:00',
    'advisoryKind' => 'Admin-created rule-based advisory',
], $rows);

api_response(200, ['success' => true, 'data' => $requestedId ? $advisories[0] : [
    'items' => $advisories,
    'eligibility' => $access,
]]);
