<?php
declare(strict_types=1);

// Farmer production (harvest) reporting. Every read and write is limited to the signed-in farmer.
//   GET  ?view=summary (default)        current and previous calendar month, crop breakdown, recent records
//   GET  ?view=month&month=YYYY-MM      one month: total, crop breakdown and its records
//   GET  ?view=year&year=YYYY           twelve monthly totals for the history chart
//   GET  ?view=catalog                  categories, units and the active commodity catalog for the submission form
//   GET  ?view=commodities              active commodity list only (kept for older app builds)
//   GET  ?id=N                          one record (read-only)
//   POST JSON {farmId, category, commodityId, harvestDate, quantity, unitCode, remarks, clientSubmissionId, confirmDuplicate?}
//        (older app builds send quantityKg instead of quantity/unitCode; it is read as kilograms)
require_once __DIR__ . '/../includes/farmer_auth.php';
require_once dirname(__DIR__, 3) . '/backend/lib/production_records.php';

$farmer = authenticated_farmer();
$farmerId = (int) $farmer['id'];
$own = ['farmer_id' => $farmerId];
$connection = db();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $requestedId = trim((string) ($_GET['id'] ?? ''));
    $view = (string) ($_GET['view'] ?? 'summary');
    $currentMonth = production_month_start(production_today());
    try {
        if ($requestedId !== '') {
            $id = production_positive_int($requestedId);
            $record = $id === null ? null : production_find_record($connection, $own, $id);
            if (!$record) api_response(404, ['success' => false, 'message' => 'This production record was not found.']);
            api_response(200, ['success' => true, 'data' => $record]);
        }

        if ($view === 'catalog') {
            api_response(200, ['success' => true, 'data' => production_catalog($connection)]);
        }

        if ($view === 'commodities') {
            api_response(200, ['success' => true, 'data' => production_active_commodities($connection)]);
        }

        if ($view === 'month') {
            $monthStart = production_parse_month(trim((string) ($_GET['month'] ?? '')));
            if (!$monthStart || $monthStart > $currentMonth) api_response(422, ['success' => false, 'message' => 'Select a valid month up to the current month.']);
            $summary = production_month_summary($connection, $own, $monthStart);
            $summary['records'] = production_records($connection, $own, $monthStart, $monthStart->modify('first day of next month'));
            api_response(200, ['success' => true, 'data' => $summary]);
        }

        if ($view === 'year') {
            $year = production_positive_int(trim((string) ($_GET['year'] ?? '')));
            if ($year === null || $year < 2000 || $year > (int) $currentMonth->format('Y')) api_response(422, ['success' => false, 'message' => 'Select a valid year.']);
            $totals = production_year_totals($connection, $own, $year);
            $totals['years'] = production_years($connection, $own);
            api_response(200, ['success' => true, 'data' => $totals]);
        }

        if ($view !== 'summary') api_response(422, ['success' => false, 'message' => 'The production view is invalid.']);
        api_response(200, ['success' => true, 'data' => [
            'today' => production_today()->format('Y-m-d'),
            'currentMonth' => production_month_summary($connection, $own, $currentMonth),
            'previousMonth' => production_month_summary($connection, $own, production_previous_month($currentMonth)),
            'recent' => production_records($connection, $own, null, null, 5),
            'years' => production_years($connection, $own),
        ]]);
    } catch (Throwable $exception) {
        error_log('Production records could not be loaded: ' . $exception->getMessage());
        api_response(500, ['success' => false, 'message' => 'Production records are temporarily unavailable. Please try again.']);
    }
}

if ($method !== 'POST') {
    header('Allow: GET, POST');
    api_response(405, ['success' => false, 'message' => 'Method not allowed.']);
}

$body = api_json_body();
try {
    [$errors, $clean] = production_validate_submission($connection, $farmerId, $body);
} catch (Throwable $exception) {
    error_log('Production record validation failed: ' . $exception->getMessage());
    api_response(500, ['success' => false, 'message' => 'The production record could not be checked. Please try again.']);
}
if ($errors) api_response(422, ['success' => false, 'message' => 'Please correct the highlighted production fields.', 'errors' => $errors]);

try {
    // A retried submission of the same form (lost response, double tap) returns the record already saved.
    $saved = production_find_by_submission($connection, $farmerId, $clean['clientSubmissionId']);
    if ($saved) api_response(200, ['success' => true, 'message' => 'This production record was already saved.', 'data' => ['record' => $saved, 'alreadySaved' => true]]);

    // Identical farm, commodity, date, quantity and unit is most likely the same harvest entered twice: ask first.
    if (($body['confirmDuplicate'] ?? false) !== true) {
        $same = production_find_same_record($connection, $farmerId, $clean);
        if ($same) {
            api_response(409, ['success' => false, 'message' => 'A matching production record already exists.', 'errors' => [
                'duplicate' => 'You already recorded ' . production_format_quantity($same['quantity'], $same['unitSymbol']) . ' of ' . $same['commodityName']
                    . ' from ' . ($same['farmName'] ?? 'this farm') . ' harvested on ' . $same['harvestDate'] . '. Submit again only if this is a separate harvest.',
            ]]);
        }
    }

    $record = production_insert_record($connection, $farmerId, $clean);
    api_response(201, ['success' => true, 'message' => 'Production record saved.', 'data' => ['record' => $record, 'alreadySaved' => false]]);
} catch (Throwable $exception) {
    if (db_is_duplicate_key($exception)) {
        $saved = production_find_by_submission($connection, $farmerId, $clean['clientSubmissionId']);
        if ($saved) api_response(200, ['success' => true, 'message' => 'This production record was already saved.', 'data' => ['record' => $saved, 'alreadySaved' => true]]);
    }
    error_log('Production record could not be saved: ' . $exception->getMessage());
    api_response(500, ['success' => false, 'message' => 'The production record could not be saved. Please try again.']);
}
