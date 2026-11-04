<?php
declare(strict_types=1);

use Dompdf\Dompdf;
use Dompdf\FontMetrics;
use Dompdf\Options;

require_once __DIR__ . '/../../../backend/config/paths.php';
require_once agrireach_root() . '/vendor/autoload.php';
require_once __DIR__ . '/../../../backend/lib/damage_reports.php';
require_once __DIR__ . '/../../../backend/lib/report_photo_location.php';
require_once __DIR__ . '/../../../backend/lib/private_uploads.php';

/*
 * Printable damage reports (Dompdf, A4).
 *
 * Single report: the report code and status; farmer and farm; the incident with the farmer's original
 * figures; the admin assessment, labelled separately; evidence photos; the damage location and its source;
 * the weather snapshot recorded at submission; and a reviewer/signature/date block. Only saved data is printed. An unresolved report is marked
 * "Pending review - draft" and its unverified fields are left blank. Long text and many photos continue
 * on further pages.
 */

function damage_report_pdf_escape(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function damage_report_pdf_value(mixed $value): string
{
    if ($value === null) {
        return 'Not available';
    }
    $text = trim((string) $value);
    return $text === '' ? 'Not available' : $text;
}

function damage_report_pdf_date(mixed $value, string $fallback = 'Not available', string $format = 'M d, Y h:i A'): string
{
    $text = trim((string) ($value ?? ''));
    if ($text === '') {
        return $fallback;
    }
    $timestamp = strtotime($text);
    return $timestamp === false ? $fallback : date($format, $timestamp);
}

/** A blank line for the reviewer to fill in by hand (unverified or unsigned fields). */
function damage_report_pdf_blank(string $hint = ''): string
{
    return '<span class="blank-line">&nbsp;</span>' . ($hint !== '' ? '<small class="blank-hint">' . damage_report_pdf_escape($hint) . '</small>' : '');
}

/**
 * Label/value table. A value is escaped text, or ['html' => ...] for prepared markup.
 *
 * @param array<string,mixed> $rows
 */
function damage_report_pdf_rows(array $rows): string
{
    $html = '<table class="detail-table"><tbody>';
    foreach ($rows as $label => $value) {
        $cell = is_array($value) && isset($value['html']) ? $value['html'] : nl2br(damage_report_pdf_escape(damage_report_pdf_value($value)), false);
        $html .= '<tr><th>' . damage_report_pdf_escape($label) . '</th><td>' . $cell . '</td></tr>';
    }
    return $html . '</tbody></table>';
}

function damage_report_pdf_section(string $number, string $title, string $content, string $class = ''): string
{
    return '<section class="section ' . damage_report_pdf_escape($class) . '"><h2><span class="section-number">' . damage_report_pdf_escape($number)
        . '</span>' . damage_report_pdf_escape($title) . '</h2>' . $content . '</section>';
}

/**
 * Final means the required admin assessment was actually completed and the report Resolved:
 * category-specific reports need the admin-verified cause and figures and remarks (no peso amount);
 * older reports keep their rule of a final assessed cost and remarks.
 */
function damage_report_pdf_is_final(array $report): bool
{
    if (($report['status'] ?? '') !== 'Resolved' || trim((string) ($report['assessment_remarks'] ?? '')) === '') return false;
    if (is_array($report['details'] ?? null)) return is_array($report['details']['verified'] ?? null);
    return ($report['final_assessed_cost'] ?? null) !== null;
}

function damage_report_pdf_peso(mixed $value): ?string
{
    return $value === null || $value === '' ? null : 'PHP ' . number_format((float) $value, 2);
}

/** Loss figures with explicit units for one side (farmer-reported or admin-verified). */
function damage_report_pdf_figure_rows(array $details, array $figures, string $prefix): array
{
    $measure = (string) $details['measureType'];
    if ($measure === 'crop_percent') {
        return [
            $prefix . ' crop damage' => $figures['cropDamagePercent'] === null ? null : damage_details_format($figures['cropDamagePercent']) . '% of this crop or plot',
            $prefix . ' planted area' => $figures['plantedAreaHa'] === null ? 'Not provided' : damage_details_format($figures['plantedAreaHa'], 4) . ' hectares',
            $prefix . ' affected area' => $figures['affectedAreaHa'] === null ? 'Not provided' : damage_details_format($figures['affectedAreaHa'], 4) . ' hectares',
        ];
    }
    $unit = (string) $details['unit'];
    $decimals = $measure === 'fish_weight_kg' ? 2 : 0;
    $died = in_array($measure, ['head_count', 'bird_count'], true);
    return [
        $prefix . ' total before the incident' => $figures['totalBefore'] === null ? 'Not provided' : damage_details_format($figures['totalBefore'], $decimals) . ' ' . $unit,
        $prefix . ($died ? ' number that died' : ' amount lost') => $figures['amountLost'] === null ? null : damage_details_format($figures['amountLost'], $decimals) . ' ' . $unit,
        $prefix . ' loss percentage' => $figures['lossPercent'] === null ? 'Not calculated (total not provided)' : damage_details_format($figures['lossPercent'], 1) . '% (calculated from the figures above)',
    ];
}

function damage_report_pdf_commodity(array $report): ?string
{
    $details = is_array($report['details'] ?? null) ? $report['details'] : null;
    return $details !== null ? (string) $details['subjectType'] : (($report['main_product'] ?? null) ?: null);
}

function damage_report_pdf_header_html(array $report, bool $isFinal): string
{
    $reviewed = $isFinal ? ($report['resolved_at'] ?? $report['assessed_at'] ?? null) : null;
    $statusClass = $isFinal ? 'final' : 'pending';
    return '<table class="doc-header"><tr><td class="doc-brand"><div class="brand-mark">AR</div><div class="brand-copy"><strong>AgriReach</strong><span>Department of Agriculture - Cabadbaran City</span></div></td>'
        . '<td class="doc-title"><h1>Damage Report</h1>'
        . '<p>Report reference: <strong>' . damage_report_pdf_escape(damage_report_pdf_value($report['report_code'] ?? null)) . '</strong></p>'
        . '<p>Status: <strong class="status ' . $statusClass . '">' . damage_report_pdf_escape(damage_report_pdf_value($report['status'] ?? null)) . '</strong></p>'
        . '<p>Submitted: ' . damage_report_pdf_escape(damage_report_pdf_date($report['submitted_at'] ?? null, 'Not available', 'F j, Y')) . '</p>'
        . '<p>Reviewed: ' . ($reviewed !== null ? damage_report_pdf_escape(damage_report_pdf_date($reviewed, 'Not available', 'F j, Y')) : 'Not yet') . '</p></td></tr></table>'
        . ($isFinal ? '' : '<div class="draft-banner"><strong>PENDING REVIEW - DRAFT, NOT FINAL.</strong> The admin assessment is not complete; blank fields have not been verified.</div>');
}

function damage_report_pdf_farmer_html(array $report): string
{
    $location = implode(', ', array_filter([$report['farm_barangay'] ?? null, $report['farm_city'] ?? null, $report['farm_province'] ?? null], static fn ($part) => trim((string) $part) !== ''));
    return damage_report_pdf_section('1', 'Farmer & Farm', damage_report_pdf_rows([
        'Farmer name' => $report['full_name'] ?? null,
        'RSBSA reference no.' => ($report['rsbsa_reference'] ?? null) ?: 'Not on file',
        'Contact number' => $report['contact_number'] ?? null,
        'Farm name' => $report['farm_name'] ?? null,
        'Farm type / commodity' => trim(($report['farm_type'] ?? '') . ((damage_report_pdf_commodity($report) ?? '') !== '' ? ' / ' . damage_report_pdf_commodity($report) : ''), ' /') ?: null,
        'Farm location' => $location !== '' ? $location : ($report['farm_address'] ?? null),
    ]), 'keep-together');
}

function damage_report_pdf_incident_html(array $report): string
{
    $details = is_array($report['details'] ?? null) ? $report['details'] : null;
    $narrative = trim((string) ($report['damage_description'] ?? ''));
    $narrativeHtml = '<div class="narrative"><strong>Farmer\'s incident narrative</strong><p>' . ($narrative !== '' ? nl2br(damage_report_pdf_escape($narrative), false) : 'Not provided') . '</p></div>';
    if ($details === null) {
        // Older report: its original calamity type and peso estimate, unchanged.
        return damage_report_pdf_section('2', 'Incident & Farmer Report', damage_report_pdf_rows([
            'Category' => $report['agricultural_category'] ?? null,
            'Damage type' => $report['damage_type'] ?? null,
            'Farmer peso estimate (original submission)' => damage_report_pdf_peso($report['estimated_damage_cost'] ?? null) ?? 'Not provided',
        ]) . $narrativeHtml, 'flow');
    }
    return damage_report_pdf_section('2', 'Incident & Farmer Report', damage_report_pdf_rows([
        'Category / ' . strtolower((string) $details['subjectLabel']) => ($report['agricultural_category'] ?? '') . ' / ' . $details['subjectType'],
        'Farmer-reported loss' => $details['summary'],
    ] + damage_report_pdf_figure_rows($details, $details, 'Farmer-reported') + [
        'Suspected cause (farmer, not verified)' => $details['suspectedCauseLabel'],
    ]) . $narrativeHtml, 'flow');
}

function damage_report_pdf_assessment_html(array $report, bool $isFinal): string
{
    $details = is_array($report['details'] ?? null) ? $report['details'] : null;
    $verified = $details !== null && is_array($details['verified'] ?? null) ? $details['verified'] : null;
    $label = $isFinal ? 'Admin-verified' : 'Admin-verified (draft)';
    $remarks = trim((string) ($report['assessment_remarks'] ?? ''));
    $rows = [];
    if ($details !== null) {
        if ($verified !== null) {
            $rows[$label . ' loss'] = $verified['summary'];
            $rows += damage_report_pdf_figure_rows($details, $verified, $label);
            $rows['Final cause' . ($isFinal ? '' : ' (draft)')] = $verified['causeLabel'];
            if (trim((string) ($verified['reviewNotes'] ?? '')) !== '') $rows['Verification notes'] = $verified['reviewNotes'];
        } else {
            $rows['Admin-verified loss'] = ['html' => damage_report_pdf_blank('Not verified yet')];
            $rows['Final cause'] = ['html' => damage_report_pdf_blank('Not verified yet')];
        }
        // A peso amount is not part of a category-specific assessment; one recorded by an admin is shown as recorded.
        if (($report['final_assessed_cost'] ?? null) !== null) $rows[$isFinal ? 'Final assessed cost (admin)' : 'Assessed cost (draft)'] = damage_report_pdf_peso($report['final_assessed_cost']);
    } else {
        $rows[$isFinal ? 'Final assessed cost (admin)' : 'Assessed cost'] = $isFinal ? damage_report_pdf_peso($report['final_assessed_cost'] ?? null)
            : (($report['final_assessed_cost'] ?? null) !== null ? ['html' => damage_report_pdf_escape(damage_report_pdf_peso($report['final_assessed_cost']) . ' (draft, not final)')] : ['html' => damage_report_pdf_blank('Not assessed yet')]);
    }
    $rows['Reviewer\'s note' . ($isFinal ? '' : ' (draft)')] = $remarks !== '' ? $remarks : ['html' => damage_report_pdf_blank('Not recorded yet')];
    $rows['Reviewed by'] = $isFinal ? (($report['assessed_by_name'] ?? null) ?: ($verified['verifiedBy'] ?? null)) : ['html' => damage_report_pdf_blank()];
    $rows['Final status'] = $isFinal ? ['html' => '<strong class="status final">Resolved</strong>'] : ['html' => '<strong class="status pending">' . damage_report_pdf_escape(damage_report_pdf_value($report['status'] ?? null)) . ' - pending</strong>'];
    if (!empty($report['admin_rejection_reason'])) $rows['Rejection reason'] = $report['admin_rejection_reason'];
    return damage_report_pdf_section('3', $isFinal ? 'Admin Final Assessment' : 'Admin Assessment (pending - not final)', damage_report_pdf_rows($rows), 'keep-together');
}

function damage_report_pdf_point_state(mixed $latitude, mixed $longitude): array
{
    if ($latitude === null || $longitude === null || $latitude === '' || $longitude === '') {
        return ['Could not check', 'Coordinates were not provided.'];
    }
    if (!is_numeric($latitude) || !is_numeric($longitude)) {
        return ['Check failed', 'Stored coordinate values are not numeric.'];
    }
    $lat = (float) $latitude;
    $lon = (float) $longitude;
    if ($lat < -90 || $lat > 90 || $lon < -180 || $lon > 180 || ($lat == 0.0 && $lon == 0.0)) {
        return ['Check failed', 'Stored coordinates are outside valid latitude or longitude ranges.'];
    }
    return ['Check passed', number_format($lat, 7) . ', ' . number_format($lon, 7) . ' is within valid coordinate ranges.'];
}

function damage_report_pdf_coordinates(mixed $latitude, mixed $longitude): ?string
{
    [$state] = damage_report_pdf_point_state($latitude, $longitude);
    return $state === 'Check passed' ? number_format((float) $latitude, 5) . ', ' . number_format((float) $longitude, 5) : null;
}

function damage_report_pdf_distance(array $report): string
{
    [$damageState] = damage_report_pdf_point_state($report['submitted_latitude'] ?? null, $report['submitted_longitude'] ?? null);
    [$farmState] = damage_report_pdf_point_state($report['registered_latitude'] ?? null, $report['registered_longitude'] ?? null);
    if ($damageState !== 'Check passed' || $farmState !== 'Check passed') {
        return 'Not available';
    }
    $latitudeA = deg2rad((float) $report['submitted_latitude']);
    $latitudeB = deg2rad((float) $report['registered_latitude']);
    $latitudeDelta = $latitudeB - $latitudeA;
    $longitudeDelta = deg2rad((float) $report['registered_longitude'] - (float) $report['submitted_longitude']);
    $a = sin($latitudeDelta / 2) ** 2 + cos($latitudeA) * cos($latitudeB) * sin($longitudeDelta / 2) ** 2;
    $meters = 6371000 * 2 * atan2(sqrt($a), sqrt(max(0, 1 - $a)));
    $formatted = $meters < 1 ? number_format($meters, 2) . ' m' : ($meters < 10 ? number_format($meters, 1) . ' m' : ($meters < 1000 ? number_format($meters, 0) . ' m' : number_format($meters / 1000, 2) . ' km'));
    return $formatted . ' between saved damage and current registered farm points; not a farm-boundary check';
}

/** Recorded evidence checks; "Not checked"/"Unavailable" where no check or data exists. */
function damage_report_pdf_validations(array $report): array
{
    [$submittedState, $submittedReason] = damage_report_pdf_point_state($report['submitted_latitude'] ?? null, $report['submitted_longitude'] ?? null);
    [$registeredState, $registeredReason] = damage_report_pdf_point_state($report['registered_latitude'] ?? null, $report['registered_longitude'] ?? null);
    $images = is_array($report['images'] ?? null) ? $report['images'] : [];
    $validImagePoints = 0;
    $invalidImagePoints = 0;
    $captureTimestamps = 0;
    foreach ($images as $image) {
        if (!empty($image['captured_at'])) {
            $captureTimestamps++;
        }
        if (($image['gps_latitude'] ?? null) === null && ($image['gps_longitude'] ?? null) === null) {
            continue;
        }
        [$state] = damage_report_pdf_point_state($image['gps_latitude'] ?? null, $image['gps_longitude'] ?? null);
        if ($state === 'Check passed') {
            $validImagePoints++;
        } else {
            $invalidImagePoints++;
        }
    }

    $photoGpsState = 'Unavailable';
    $photoGpsReason = $images ? 'No photo GPS metadata was stored.' : 'No damage images are stored.';
    if ($invalidImagePoints > 0) {
        $photoGpsState = 'Check failed';
        $photoGpsReason = $invalidImagePoints . ' image coordinate record(s) are incomplete or outside valid ranges.';
    } elseif ($validImagePoints > 0) {
        $photoGpsState = 'Available';
        $photoGpsReason = $validImagePoints . ' image(s) have stored coordinates. The source and accuracy are listed per photo; authenticity has not been verified.';
    }

    return [
        ['Submitted coordinate range', $submittedState, $submittedReason],
        ['Registered farm point', $registeredState, $registeredReason],
        ['Farm-boundary validation', 'Not checked', 'No farm boundary geometry is stored. Point distance is not proof that the report is inside a farm boundary.'],
        ['Photo GPS metadata', $photoGpsState, $photoGpsReason],
        ['Server submission timestamp', !empty($report['submitted_at']) ? 'Check passed' : 'Could not check', !empty($report['submitted_at']) ? 'Recorded by the AgriReach server at ' . damage_report_pdf_date($report['submitted_at']) . '.' : 'No server submission timestamp is available.'],
        ['Photo capture timestamp', 'Unavailable', $captureTimestamps > 0 ? $captureTimestamps . ' app-supplied timestamp(s) exist. Their sources are listed per photo; camera-return time is not an exact shutter time. Capture time is not independently verified.' : 'No photo capture timestamp metadata is available.'],
    ];
}

function damage_report_pdf_file_uri(string $path): string
{
    return 'file://' . str_replace('\\', '/', $path);
}

/** Evidence photos two per row, each with its own saved GPS only when present. Rows continue on new pages. */
function damage_report_pdf_images_html(array $report): string
{
    $images = is_array($report['images'] ?? null) ? $report['images'] : [];
    if (!$images) {
        return damage_report_pdf_section('4', 'Evidence', '<p class="empty-value">No damage photos were saved with this report.</p>', 'keep-together');
    }
    $cells = [];
    foreach ($images as $index => $image) {
        $number = $index + 1;
        $path = resolve_report_image((string) ($image['image_path'] ?? ''));
        if ($path === null) {
            $picture = '<div class="photo-missing">Photo ' . $number . ' file is not available.</div>';
        } else {
            $size = @getimagesize($path);
            $width = is_array($size) && $size[0] > 0 ? (float) $size[0] : 1.0;
            $height = is_array($size) && $size[1] > 0 ? (float) $size[1] : 1.0;
            // Fit inside the 236 x 168 pt frame (both in points), centred.
            $scale = min(232 / $width, 164 / $height);
            $shownHeight = round($height * $scale, 2);
            $picture = '<div class="photo-box"><img src="' . damage_report_pdf_escape(damage_report_pdf_file_uri($path)) . '" alt="Damage photo ' . $number
                . '" style="width:' . round($width * $scale, 2) . 'pt;height:' . $shownHeight . 'pt;margin-top:' . round((168 - $shownHeight) / 2, 2) . 'pt"></div>';
        }
        $point = damage_report_pdf_coordinates($image['gps_latitude'] ?? null, $image['gps_longitude'] ?? null);
        $caption = '<strong>Photo ' . $number . '</strong> - farmer uploaded<br>'
            . ($point !== null ? 'Photo GPS: ' . damage_report_pdf_escape($point) . ' (' . damage_report_pdf_escape(report_photo_location_label($image['gps_source'] ?? null, true)) . ')' : 'No photo GPS saved')
            // location_photo_order is the 1-based position of the photo in the saved (ordered) list.
            . ((int) ($report['location_photo_order'] ?? 0) === $number && ($report['location_source'] ?? '') === 'photo' ? '<br>Damage point confirmed from this photo' : '');
        $cells[] = '<td class="photo-cell">' . $picture . '<p class="photo-caption">' . $caption . '</p></td>';
    }
    $rows = [];
    foreach (array_chunk($cells, 2) as $pair) {
        $rows[] = '<table class="photo-grid"><tr>' . implode('', $pair) . (count($pair) === 1 ? '<td class="photo-cell"></td>' : '') . '</tr></table>';
    }
    // The heading stays on the same page as the first row of photos; each row is kept whole.
    $heading = '<h2><span class="section-number">4</span>Evidence (' . count($images) . ' photo' . (count($images) === 1 ? '' : 's') . ')</h2>';
    return '<section class="section"><div class="keep-together">' . $heading . array_shift($rows) . '</div>'
        . implode('', array_map(static fn (string $row): string => '<div class="keep-together">' . $row . '</div>', $rows)) . '</section>';
}

function damage_report_pdf_location_source(array $report): string
{
    return match ($report['location_source'] ?? null) {
        'photo' => 'Farmer-confirmed from photo ' . (int) ($report['location_photo_order'] ?? 0) . ' GPS',
        'device' => 'Farmer-confirmed device GPS',
        'manual' => 'Farmer-confirmed manual map pin',
        default => 'Not recorded (older report)',
    };
}

function damage_report_pdf_location_html(array $report): string
{
    $checks = [];
    foreach (damage_report_pdf_validations($report) as [$label, $state]) {
        if (in_array($label, ['Submitted coordinate range', 'Farm-boundary validation', 'Photo GPS metadata'], true)) $checks[] = $label . ': ' . $state;
    }
    $farmLocation = implode(', ', array_filter([$report['farm_barangay'] ?? null, $report['farm_city'] ?? null, $report['farm_province'] ?? null], static fn ($part) => trim((string) $part) !== ''));
    return damage_report_pdf_section('5', 'Location', damage_report_pdf_rows([
        'Damage point' => damage_report_pdf_coordinates($report['submitted_latitude'] ?? null, $report['submitted_longitude'] ?? null) ?? 'Not recorded',
        'Damage point source' => damage_report_pdf_location_source($report),
        'Address / landmark (farmer)' => $report['submitted_address'] ?? null,
        'Registered farm point' => (damage_report_pdf_coordinates($report['registered_latitude'] ?? null, $report['registered_longitude'] ?? null) ?? 'Not recorded') . ($farmLocation !== '' ? ' - ' . $farmLocation : ''),
        'Distance between points' => damage_report_pdf_distance($report),
        'Recorded checks' => implode("\n", $checks),
    ]), 'keep-together');
}

function damage_report_pdf_weather_utc(mixed $value): string
{
    if ($value === null || $value === '') return 'Not available';
    $timestamp = strtotime((string) $value . ' UTC');
    return $timestamp === false ? 'Not available' : (new DateTimeImmutable('@' . $timestamp))->setTimezone(new DateTimeZone('Asia/Manila'))->format('M j, Y g:i A') . ' (Philippine time)';
}

/** Weather evidence recorded at submission and, separately, the automatic rule result (section 6). */
function damage_report_pdf_weather_html(?array $weather): string
{
    $title = 'Weather at Submission (recorded snapshot)';
    if ($weather === null) {
        return damage_report_pdf_section('6', $title, damage_report_pdf_rows(['Weather snapshot' => 'Not recorded for this report. No weather is generated retroactively.']), 'keep-together');
    }
    if (($weather['snapshot_status'] ?? 'Available') === 'Unavailable') {
        return damage_report_pdf_section('6', $title, damage_report_pdf_rows([
            'Status' => 'Weather unavailable (the report was saved normally; no weather values were recorded)',
            'Reason' => $weather['unavailable_reason'] ?? 'No reason recorded',
            'Provider' => $weather['provider'] ?? null,
            'Coordinates requested' => ($weather['requested_latitude'] ?? null) !== null ? $weather['requested_latitude'] . ', ' . $weather['requested_longitude'] : null,
            'Report submitted (server)' => damage_report_pdf_weather_utc($weather['report_submitted_at_utc'] ?? null),
            'Retrieval attempted' => damage_report_pdf_weather_utc($weather['retrieved_at_utc'] ?? null),
        ]), 'keep-together');
    }
    $legacy = empty($weather['provider']);
    $units = is_array($weather['units'] ?? null) ? $weather['units'] : [];
    $withUnit = static fn(mixed $value, string $unit): ?string => ($value ?? null) === null || $value === '' ? null : $value . ' ' . $unit;
    $rows = array_filter([
        'Condition' => $weather['weather_condition'] ?? null,
        'Temperature' => $withUnit($weather['temperature'] ?? null, $units['temperature'] ?? 'C'),
        'Humidity' => $withUnit($weather['humidity'] ?? null, $units['humidity'] ?? '%'),
        'Precipitation (current interval)' => $legacy ? ($weather['rainfall'] ?? null) : $withUnit($weather['rainfall'] ?? null, $units['precipitation'] ?? 'mm'),
        'Precipitation (last 6 hours)' => $withUnit($weather['precipitation_last_6h_mm'] ?? null, $units['precipitation'] ?? 'mm'),
        'Wind' => $weather['wind_information'] ?? null,
    ], static fn ($value) => $value !== null && $value !== '');
    if ($legacy) {
        $rows = ['Record type' => 'Legacy record without provider, coordinates or retrieval time (not verifiable)'] + $rows;
        $rows['Observation time'] = damage_report_pdf_date($weather['recorded_at'] ?? null);
    } else {
        $rows['Provider'] = ($weather['provider'] ?? '') . ' - ' . ($weather['observation_type'] ?? '');
        $rows['Coordinates requested (confirmed damage point)'] = ($weather['requested_latitude'] ?? null) !== null ? $weather['requested_latitude'] . ', ' . $weather['requested_longitude'] : null;
        $rows['Observation time'] = damage_report_pdf_weather_utc($weather['observed_at_utc'] ?? null);
        $rows['Report submitted (server)'] = damage_report_pdf_weather_utc($weather['report_submitted_at_utc'] ?? null);
    }
    $html = damage_report_pdf_rows($rows);

    $rule = is_array($weather['rule_result'] ?? null) ? $weather['rule_result'] : null;
    if ($rule !== null) {
        // Snapshots saved before rule set 2026.10-2 carry no "assessment" and are shown as recorded, not re-evaluated.
        $legacyRule = empty($rule['assessment']);
        $ruleRows = [
            'Source' => ($rule['source'] ?? 'Weather-based advice') . ' (automatic, rule-based; not an admin advisory and not a PAGASA warning)',
            'Rule set / category' => ($rule['ruleSetVersion'] ?? 'unknown') . ' / ' . ($rule['category'] ?? 'not recorded'),
            'Result' => $legacyRule
                ? 'Recorded under earlier rule set ' . ($rule['ruleSetVersion'] ?? 'unknown') . ' and not re-evaluated' . (!empty($rule['normal']) ? ' (no threshold was triggered; some checks may have been incomplete)' : ' (a threshold was matched)')
                : ($rule['headline'] ?? null),
            'Meaning' => $legacyRule ? null : ($rule['statement'] ?? null),
            'Model grid distance' => ($weather['provider_distance_km'] ?? null) !== null ? $weather['provider_distance_km'] . ' km from the damage point (model grid cell value)' : null,
        ];
        foreach (($rule['matched'] ?? []) as $index => $match) {
            $ruleRows['Matched condition ' . ($index + 1)] = ($match['label'] ?? '') . ' - ' . ($match['measured'] ?? '') . ' (threshold ' . ($match['threshold'] ?? '') . ')';
        }
        foreach (($rule['limitations'] ?? []) as $index => $limitation) {
            $ruleRows['Limitation ' . ($index + 1)] = ($limitation['label'] ?? '') . ': ' . ($limitation['reason'] ?? '');
        }
        $ruleRows['Note'] = $rule['disclaimer'] ?? 'AgriReach rules are project-defined and are not official PAGASA warnings.';
        $html .= '<h3 class="subsection">Weather-Based Advice Result</h3>' . damage_report_pdf_rows(array_filter($ruleRows, static fn ($value) => $value !== null && $value !== ''));
    }
    return damage_report_pdf_section('6', $title, $html, 'flow');
}

function damage_report_pdf_signature_html(array $report, bool $isFinal): string
{
    $name = $isFinal ? (($report['assessed_by_name'] ?? null) ?: null) : null;
    return '<table class="signature"><tr><td><p>Prepared / reviewed by:</p><div class="sign-line">' . ($name !== null ? damage_report_pdf_escape($name) : '&nbsp;') . '</div><small>Signature over printed name and position</small></td>'
        . '<td class="sign-date"><p>Date:</p><div class="sign-line">&nbsp;</div><small>Date signed</small></td></tr></table>';
}

function damage_report_pdf_base_styles(): string
{
    return <<<'CSS'
@page { margin: 44px 40px 52px; }
* { box-sizing: border-box; }
body { margin: 0; color: #1c2b23; font-family: "DejaVu Sans", sans-serif; font-size: 9.5px; line-height: 1.45; }
.pdf-footer { position: fixed; bottom: -34px; left: 0; right: 0; border-top: 1px solid #d9e6dd; padding-top: 6px; color: #6b7a71; font-size: 7.5px; }
.doc-header { width: 100%; border-collapse: collapse; border-bottom: 2.5px solid #174d36; margin-bottom: 12px; }
.doc-header td { vertical-align: top; padding: 0 0 10px; }
.brand-mark { display: inline-block; width: 34px; height: 34px; margin-right: 8px; background: #2f8f5b; color: #fff; font-size: 13px; font-weight: bold; line-height: 34px; text-align: center; vertical-align: middle; border-radius: 6px; }
.brand-copy { display: inline-block; vertical-align: middle; }
.brand-copy strong { display: block; color: #174d36; font-size: 18px; letter-spacing: .3px; }
.brand-copy span { color: #5e6f65; font-size: 7.5px; letter-spacing: .5px; text-transform: uppercase; }
.doc-title { text-align: right; }
.doc-title h1 { margin: 0 0 3px; color: #174d36; font-size: 20px; letter-spacing: .5px; text-transform: uppercase; }
.doc-title p { margin: 1px 0; color: #2c3a32; font-size: 9px; }
.status.final { color: #1f7a45; }
.status.pending { color: #9a6413; }
.draft-banner { margin: 0 0 12px; padding: 7px 10px; border: 1px solid #e2c48d; background: #fff6e4; color: #6d4a0f; font-size: 8.5px; }
.section { margin: 0 0 12px; }
.section h2 { margin: 0 0 4px; padding: 0 0 4px; border-bottom: 1px solid #b9cbbf; color: #174d36; font-size: 11.5px; text-transform: uppercase; letter-spacing: .4px; }
.section-number { display: inline-block; width: 17px; height: 17px; margin-right: 7px; background: #174d36; color: #fff; font-size: 10px; line-height: 17px; text-align: center; }
.keep-together { page-break-inside: avoid; }
.subsection { margin: 8px 0 3px; color: #2c4a3a; font-size: 9.5px; text-transform: uppercase; letter-spacing: .3px; }
.detail-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
.detail-table th, .detail-table td { padding: 4px 6px; border-bottom: 1px solid #e3ebe6; vertical-align: top; text-align: left; word-wrap: break-word; }
.detail-table th { width: 34%; color: #4f6357; font-weight: normal; }
.detail-table td { color: #1c2b23; }
.narrative { margin-top: 6px; padding: 6px 7px; background: #f6f9f7; border-left: 3px solid #2f8f5b; }
.narrative strong { display: block; color: #4f6357; font-weight: normal; font-size: 8.5px; }
.narrative p { margin: 3px 0 0; line-height: 1.55; word-wrap: break-word; }
.blank-line { display: inline-block; width: 62%; border-bottom: 1px solid #6c7a71; }
.blank-hint { display: block; color: #8a958e; font-size: 7.5px; }
.photo-grid { width: 100%; border-collapse: collapse; margin: 0 0 8pt; }
.photo-cell { width: 50%; vertical-align: top; padding: 0 4pt; }
.photo-box { height: 168pt; text-align: center; background: #f3f6f4; border: 1px solid #d6e1da; overflow: hidden; }
.photo-caption { margin: 3pt 0 0; color: #3e5046; font-size: 8px; line-height: 1.4; }
.photo-missing { height: 168pt; padding-top: 74pt; text-align: center; background: #f5f5f4; color: #7b817d; border: 1px dashed #c6ccc8; }
.empty-value { margin: 4px 0; color: #66756c; }
.signature { width: 100%; margin-top: 16px; border-top: 2px solid #174d36; page-break-inside: avoid; }
.signature td { width: 60%; padding-top: 10px; vertical-align: top; }
.signature td.sign-date { width: 40%; padding-left: 30px; }
.signature p { margin: 0 0 18px; }
.sign-line { border-bottom: 1px solid #333; min-height: 14px; font-weight: bold; }
.signature small { color: #66756c; font-size: 7.5px; }
.summary-table { width: 100%; border-collapse: collapse; table-layout: fixed; font-size: 7.2px; }
.summary-table thead { display: table-header-group; }
.summary-table tr { page-break-inside: avoid; }
.summary-table th { padding: 6px 5px; border: 1px solid #bfd5c5; background: #e9f4ec; color: #215c3a; text-align: left; text-transform: uppercase; }
.summary-table td { padding: 6px 5px; border: 1px solid #dce7df; vertical-align: top; word-wrap: break-word; overflow-wrap: anywhere; }
.summary-table tbody tr:nth-child(even) td { background: #f8fbf9; }
.summary-table .number { text-align: right; white-space: nowrap; }
.summary-table .status { font-weight: bold; color: #206a42; }
.list-header { margin-bottom: 10px; border-bottom: 2px solid #174d36; padding-bottom: 6px; }
.list-header h1 { margin: 0; color: #174d36; font-size: 16px; }
.filters { margin: 0 0 12px; padding: 8px 10px; border: 1px solid #d8e5dc; background: #f7faf8; color: #4f6357; }
.filters span { margin-right: 12px; }
.no-results { padding: 28px; border: 1px solid #d9e6dd; color: #6c7b72; text-align: center; }
CSS;
}

function damage_report_pdf_shell(string $body, string $footer): string
{
    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><style>' . damage_report_pdf_base_styles()
        . '</style></head><body><footer class="pdf-footer">' . damage_report_pdf_escape($footer) . '</footer><main>' . $body . '</main></body></html>';
}

function damage_report_pdf_render(string $html, string $orientation, string $title): string
{
    $options = new Options();
    $options->set('defaultFont', 'DejaVu Sans');
    $options->set('isRemoteEnabled', false);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('chroot', agrireach_root());
    $options->set('tempDir', sys_get_temp_dir());

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', $orientation);
    $dompdf->render();
    $dompdf->addInfo('Title', $title);
    $dompdf->addInfo('Author', 'AgriReach Admin');
    $canvas = $dompdf->getCanvas();
    $canvas->page_script(static function (int $pageNumber, int $pageCount, $canvas, FontMetrics $fontMetrics): void {
        $font = $fontMetrics->getFont('DejaVu Sans', 'normal');
        $label = 'Page ' . $pageNumber . ' of ' . $pageCount;
        $size = 7.5;
        $width = $fontMetrics->getTextWidth($label, $font, $size);
        $canvas->text($canvas->get_width() - $width - 40, $canvas->get_height() - 30, $label, $font, $size, [0.38, 0.46, 0.41]);
    });
    return $dompdf->output();
}

function damage_report_single_pdf(array $report): string
{
    $isFinal = damage_report_pdf_is_final($report);
    $body = damage_report_pdf_header_html($report, $isFinal)
        . damage_report_pdf_farmer_html($report)
        . damage_report_pdf_incident_html($report)
        . damage_report_pdf_assessment_html($report, $isFinal)
        . damage_report_pdf_images_html($report)
        . damage_report_pdf_location_html($report)
        . damage_report_pdf_weather_html(is_array($report['weather'] ?? null) ? $report['weather'] : null)
        . damage_report_pdf_signature_html($report, $isFinal);
    $code = damage_report_pdf_value($report['report_code'] ?? null);
    $footer = 'AgriReach damage report ' . $code . ($isFinal ? ' - final' : ' - pending review, draft') . ' - generated ' . date('M d, Y h:i A') . ' Philippine time - confidential administrative record';
    return damage_report_pdf_render(damage_report_pdf_shell($body, $footer), 'portrait', ($isFinal ? 'Damage Report ' : 'Draft Damage Report ') . $code);
}

/** "Reported loss" cell of the list PDF. Each report keeps its own measure and unit; nothing is summed across reports. */
function damage_report_pdf_list_loss(array $report): string
{
    $details = is_array($report['details'] ?? null) ? $report['details'] : null;
    if ($details === null) return 'Not recorded (older report)';
    // Crop summaries already begin with the crop ("Corn: 40% of this crop damaged").
    $subject = $details['measureType'] === 'crop_percent' ? '' : $details['subjectType'] . ' - ';
    return 'Farmer-reported: ' . $subject . $details['summary']
        . "\nAdmin-verified: " . (is_array($details['verified'] ?? null) ? $details['verified']['summary'] : 'not yet');
}

function damage_report_list_pdf(array $reports, array $filters): string
{
    $filterLabels = [];
    foreach ([
        'Search' => $filters['search'],
        'Category' => $filters['category'],
        'Status' => $filters['status'],
        'From' => $filters['date_from'],
        'To' => $filters['date_to'],
        'Damage type' => $filters['damage_type'],
    ] as $label => $value) {
        if ($value !== '') {
            $filterLabels[] = '<span><strong>' . damage_report_pdf_escape($label) . ':</strong> ' . damage_report_pdf_escape($value) . '</span>';
        }
    }
    $filterHtml = '<div class="filters"><strong>Matching reports:</strong> ' . count($reports)
        . ($filterLabels ? '<br>' . implode(' ', $filterLabels) : '<br>No filters applied')
        . '<br>Losses are listed per report in their own units and are not added together. A missing peso estimate is not counted as zero.</div>';
    $body = '<div class="list-header"><h1>Damage Reports Summary</h1></div>';

    if (!$reports) {
        $body .= $filterHtml . '<div class="no-results">No reports match the selected filters.</div>';
    } else {
        $rows = '';
        foreach ($reports as $report) {
            $loss = damage_report_pdf_list_loss($report);
            $cost = ($report['estimated_damage_cost'] ?? null) === null ? 'Not requested' : 'PHP ' . number_format((float) $report['estimated_damage_cost'], 2);
            $rows .= '<tr><td>' . damage_report_pdf_escape($report['report_code']) . '</td><td>'
                . damage_report_pdf_escape(damage_report_pdf_date($report['submitted_at'])) . '</td><td><strong>'
                . damage_report_pdf_escape(damage_report_pdf_value($report['full_name'])) . '</strong><br>'
                . damage_report_pdf_escape(damage_report_pdf_value($report['farm_name'])) . '</td><td>'
                . damage_report_pdf_escape(damage_report_pdf_value($report['agricultural_category'])) . '</td><td>'
                . damage_report_pdf_escape(damage_report_pdf_value($report['damage_type'])) . '</td><td>'
                . nl2br(damage_report_pdf_escape($loss), false) . '</td><td>'
                . damage_report_pdf_escape(trim((string) ($report['damage_description'] ?? '')) !== '' ? $report['damage_description'] : 'Not provided') . '</td><td class="number">'
                . damage_report_pdf_escape($cost) . '</td><td class="status">'
                . damage_report_pdf_escape(damage_report_pdf_value($report['status'])) . '</td></tr>';
        }
        $body .= $filterHtml . '<table class="summary-table"><colgroup><col style="width:12%"><col style="width:10%"><col style="width:13%"><col style="width:8%"><col style="width:10%"><col style="width:19%"><col style="width:14%"><col style="width:7%"><col style="width:7%"></colgroup><thead><tr><th>Report ID</th><th>Submitted</th><th>Farmer / Farm</th><th>Category</th><th>Suspected cause / damage</th><th>Reported loss (units as submitted)</th><th>Description</th><th>Farmer peso estimate</th><th>Status</th></tr></thead><tbody>' . $rows . '</tbody></table>';
    }
    return damage_report_pdf_render(damage_report_pdf_shell($body, 'AgriReach damage reports summary - generated ' . date('M d, Y h:i A') . ' Philippine time - confidential administrative record'), 'landscape', 'Damage Reports Summary');
}
