<?php
declare(strict_types=1);

// CSV export of the filtered Production Report: every matching record (not just one page), original units kept.
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/../../backend/lib/admin_production.php';
require_admin();
session_write_close();
set_time_limit(120);

$fail = static function (int $status, string $message): never {
    http_response_code($status);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: private, no-store');
    echo $message;
    exit;
};

try {
    $connection = db();
    [$filters] = admin_production_normalize_filters($connection, admin_production_filters_from_input($_GET));
    $period = admin_production_period($filters);
    $summary = admin_production_summary($connection, $filters, $period);
    if ($summary['records'] > ADMIN_PRODUCTION_EXPORT_LIMIT) $fail(422, 'More than ' . number_format(ADMIN_PRODUCTION_EXPORT_LIMIT) . ' records match. Narrow the filters, then export again.');
    $records = admin_production_records($connection, $filters, $period, ADMIN_PRODUCTION_EXPORT_LIMIT);
    $labels = admin_production_selected_labels($connection, $filters);
} catch (InvalidArgumentException $exception) {
    $fail(422, $exception->getMessage());
} catch (Throwable $exception) {
    error_log('Production report export failed: ' . $exception->getMessage());
    $fail(500, 'The production report could not be exported. Please try again.');
}

$filename = 'AgriReach-Production-Report-' . $period['from']->format('Y-m-d') . '-to-' . $period['until']->modify('-1 day')->format('Y-m-d') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
$output = fopen('php://output', 'wb');
admin_production_write_csv($output, $connection, $filters, $period, $labels, $summary, $records);
fclose($output);
