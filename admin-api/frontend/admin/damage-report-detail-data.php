<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/../../backend/lib/damage_reports.php';
require_admin();

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$index = filter_var($_GET['index'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
if ($index === false || $index === null || $index > 1000000) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid report position.']);
    exit;
}

try {
    $filters = damage_report_filters_from_input($_GET);
    if (damage_report_filter_error($filters) !== '') {
        throw new InvalidArgumentException('Invalid report filters.');
    }
    $connection = db();
    $total = damage_report_count($connection, $filters);
    if ($index >= $total) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'That report is no longer in the filtered list. Refresh the list.']);
        exit;
    }
    $reports = damage_report_fetch($connection, $filters, null, true, false, 1, $index);
    if (!$reports) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'That report is no longer available. Refresh the list.']);
        exit;
    }
    echo json_encode(['success' => true, 'index' => $index, 'total' => $total, 'report' => $reports[0]], JSON_INVALID_UTF8_SUBSTITUTE);
} catch (InvalidArgumentException $exception) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'The report filters are invalid.']);
} catch (Throwable $exception) {
    error_log('Damage report details could not be loaded: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'The report could not be loaded. Please try again.']);
}
