<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/backend/config/database.php';
require_once dirname(__DIR__, 2) . '/frontend/admin/includes/damage_report_pdf.php';

function review_check(bool $condition, string $description): void
{
    if (!$condition) {
        throw new RuntimeException($description);
    }
}

function review_pdf_pages(string $pdf): int
{
    review_check(str_starts_with($pdf, '%PDF-'), 'PDF header is missing.');
    preg_match_all('/\/Type\s*\/Page\b/', $pdf, $matches);
    return count($matches[0]);
}

$filters = damage_report_filters_from_input([]);
$connection = db();
$total = damage_report_count($connection, $filters);
if ($total < 1) {
    echo "No real damage reports available; read-only integration checks skipped.\n";
    exit(0);
}

$first = damage_report_fetch($connection, $filters, null, true, true, 1, 0)[0];
review_check(isset($first['id'], $first['report_code']), 'First report was not loaded.');
review_check(damage_report_pdf_distance($first) !== '', 'Distance label is empty.');
review_check(count(damage_report_pdf_validations($first)) === 6, 'Expected six evidence checks.');
review_check(damage_report_pdf_point_state(0, 0)[0] === 'Check failed', 'Historical 0,0 was treated as usable GPS in the PDF.');

$pointCase = [
    'submitted_latitude' => 9.1172515,
    'submitted_longitude' => 125.5296613,
    'registered_latitude' => 9.1172494,
    'registered_longitude' => 125.5296635,
];
review_check(str_contains(damage_report_pdf_distance($pointCase), '0.34 m'), 'Overlapping-point distance is inaccurate.');
$pointCase['submitted_latitude'] = 9.1128462;
$pointCase['submitted_longitude'] = 125.5351975;
review_check(str_contains(damage_report_pdf_distance($pointCase), '780 m'), 'Distant-point distance is inaccurate.');

if ($total > 1) {
    $second = damage_report_fetch($connection, $filters, null, true, true, 1, 1)[0];
    review_check((int) $first['id'] !== (int) $second['id'], 'Adjacent positions returned the same report.');
}

$shortPdf = damage_report_single_pdf($first);
$shortPages = review_pdf_pages($shortPdf);
review_check($shortPages >= 1, 'Short report produced no page.');
preg_match_all('/\/Subtype\s*\/Image\b/', $shortPdf, $embeddedImages);
if (count($first['images'] ?? []) > 0) {
    review_check(count($embeddedImages[0]) >= count($first['images']), 'Not all saved damage photos were embedded in the PDF.');
}

$longReport = $first;
$longReport['damage_description'] = str_repeat("Extensive damage was observed and described by the farmer.\n", 95);
$longPdf = damage_report_single_pdf($longReport);
$longPages = review_pdf_pages($longPdf);
review_check($longPages > 1, 'Long report did not continue onto multiple A4 pages.');

$missingReport = $first;
$missingReport['images'] = [];
$missingReport['weather'] = null;
$missingReport['submitted_latitude'] = null;
$missingReport['submitted_longitude'] = null;
review_check(damage_report_pdf_distance($missingReport) === 'Not available', 'Missing location did not produce Not available.');
review_check(review_pdf_pages(damage_report_single_pdf($missingReport)) >= 1, 'Missing-data report PDF failed.');

echo 'Read-only report/PDF checks passed. Real reports: ' . $total
    . '; first-report photos: ' . count($first['images'] ?? [])
    . '; embedded images: ' . count($embeddedImages[0])
    . '; short PDF pages: ' . $shortPages
    . '; long PDF pages: ' . $longPages . ".\n";
