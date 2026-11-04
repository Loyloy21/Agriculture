<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/../../backend/lib/damage_reports.php';
require_once __DIR__ . '/includes/damage_report_pdf.php';
require_admin();

set_time_limit(120);

try {
    $mode = (string) ($_GET['mode'] ?? 'list');
    $filters = damage_report_filters_from_input($_GET);
    $filterError = damage_report_filter_error($filters);
    if ($filterError !== '') {
        http_response_code(422);
        header('Content-Type: text/plain; charset=UTF-8');
        echo $filterError;
        exit;
    }

    if ($mode === 'single') {
        $reportId = filter_var($_GET['report_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($reportId === false || $reportId === null) {
            http_response_code(400);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'A valid report is required.';
            exit;
        }
        $reports = damage_report_fetch(db(), $filters, (int) $reportId, true, true);
        if (!$reports) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'The report was not found.';
            exit;
        }
        $pdf = damage_report_single_pdf($reports[0]);
        $safeCode = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $reports[0]['report_code']);
        $filename = 'AgriReach-Damage-Report-' . trim((string) $safeCode, '-') . '.pdf';
    } elseif ($mode === 'list') {
        $reports = damage_report_fetch(db(), $filters);
        $pdf = damage_report_list_pdf($reports, $filters);
        $filename = 'AgriReach-Damage-Reports-' . date('Y-m-d-His') . '.pdf';
    } else {
        http_response_code(400);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Invalid PDF export request.';
        exit;
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: private, no-store, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    echo $pdf;
} catch (Throwable $exception) {
    error_log('Damage report PDF export failed: ' . $exception->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=UTF-8');
        header('Cache-Control: no-store');
    }
    echo str_contains($exception->getMessage(), 'GD extension is required')
        ? 'PDF image rendering requires the PHP GD extension. Enable extension=gd in the Apache PHP configuration and restart Apache, then retry. Saved photos and assessment data are unchanged.'
        : 'The PDF could not be generated. Please try again.';
}
