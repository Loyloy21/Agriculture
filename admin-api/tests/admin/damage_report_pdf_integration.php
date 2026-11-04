<?php
declare(strict_types=1);

require_once __DIR__ . '/../../backend/config/database.php';
require_once __DIR__ . '/../../backend/lib/damage_reports.php';
require_once __DIR__ . '/../../frontend/admin/includes/damage_report_pdf.php';

$outputDirectory = __DIR__ . '/../../tmp/pdfs';
if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0755, true) && !is_dir($outputDirectory)) {
    throw new RuntimeException('Unable to create the PDF test output directory.');
}

$filters = damage_report_filters_from_input([]);
$reports = damage_report_fetch(db(), $filters);
if (!$reports) {
    throw new RuntimeException('No non-fixture damage report is available for PDF testing.');
}

$countResult = db()->query(
    'SELECT dr.id, COUNT(ri.id) AS image_count
     FROM damage_reports dr
     LEFT JOIN report_images ri ON ri.report_id = dr.id
     WHERE dr.is_fixture = 0
     GROUP BY dr.id
     ORDER BY image_count DESC, dr.id DESC
     LIMIT 1'
)->fetch();
if (!$countResult) {
    throw new RuntimeException('No report was available for the single-report PDF test.');
}

$singleRows = damage_report_fetch(db(), $filters, (int) $countResult['id'], true, true);
$single = $singleRows[0];
$single['damage_description'] = trim((string) $single['damage_description']) . ' ' . str_repeat(
    'This controlled layout test adds a long description so wrapped text, section pagination, and image placement can be checked without changing the database. ',
    9
);
$single['weather'] = null;
$single['admin_response_message'] = null;
$single['admin_rejection_reason'] = null;
$single['admin_response_at'] = null;

$singlePath = $outputDirectory . '/single-report-layout-test.pdf';
file_put_contents($singlePath, damage_report_single_pdf($single));

$manyReports = [];
while (count($manyReports) < 55) {
    foreach ($reports as $report) {
        $copy = $report;
        $copy['report_code'] .= '-T' . (count($manyReports) + 1);
        $copy['damage_description'] = trim((string) $copy['damage_description']) . ' - controlled multi-page wrapping check with additional descriptive text.';
        $manyReports[] = $copy;
        if (count($manyReports) >= 55) {
            break;
        }
    }
}
$listPath = $outputDirectory . '/filtered-list-layout-test.pdf';
file_put_contents($listPath, damage_report_list_pdf($manyReports, damage_report_filters_from_input([
    'category' => 'Crops',
    'status' => 'Received',
    'date_from' => '2026-01-01',
    'date_to' => '2026-12-31',
])));

echo 'SINGLE_REPORT_ID=' . (int) $single['id'] . PHP_EOL;
echo 'SINGLE_IMAGE_COUNT=' . count($single['images']) . PHP_EOL;
echo 'SINGLE_PDF=' . $singlePath . PHP_EOL;
echo 'LIST_ROWS=' . count($manyReports) . PHP_EOL;
echo 'LIST_PDF=' . $listPath . PHP_EOL;
