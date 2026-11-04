<?php
declare(strict_types=1);

/*
 * Category-specific damage details (table damage_report_details, one row per new report).
 *
 * The farmer reports what was affected, a SUSPECTED cause (never presented as proven) and the loss in
 * the category's own measure: a crop-damage percentage estimate, a head/bird count, or a fish count or
 * weight. Admin-verified values are stored beside the farmer's values, in the same measure and unit,
 * and never replace them. Nothing here converts a percentage, count or weight into money.
 *
 * The option lists must match frontend/mobile/src/utils/damageDetails.ts (a test compares them).
 */

const DAMAGE_DETAILS_VERSION = 1;
const DAMAGE_DETAILS_MAX_COUNT = 10000000;
const DAMAGE_DETAILS_MAX_KG = 100000000;
const DAMAGE_DETAILS_MAX_HECTARES = 9999999;

/** @return array<string,array{measure:string,subjectLabel:string,subjects:list<string>,causes:list<string>,unit:string}> */
function damage_details_config(): array
{
    return [
        'Crops' => [
            'measure' => 'crop_percent', 'subjectLabel' => 'Crop', 'unit' => '%',
            'subjects' => ['Rice', 'Corn', 'Coconut', 'Banana', 'Vegetables', 'Root crops', 'Fruit trees', 'Sugarcane', 'Other'],
            'causes' => ['Flooding', 'Typhoon / strong wind', 'Drought / dry spell', 'Pest infestation', 'Plant disease', 'Fire', 'Landslide', 'Other', 'Unsure'],
        ],
        'Livestock' => [
            'measure' => 'head_count', 'subjectLabel' => 'Animal type', 'unit' => 'head',
            'subjects' => ['Pigs', 'Cattle', 'Carabao', 'Goats', 'Sheep', 'Horses', 'Other'],
            'causes' => ['Disease', 'Heat stress', 'Flooding', 'Typhoon / strong wind', 'Lack of feed or water', 'Injury / predator attack', 'Poisoning', 'Other', 'Unsure'],
        ],
        'Poultry' => [
            'measure' => 'bird_count', 'subjectLabel' => 'Bird type', 'unit' => 'birds',
            'subjects' => ['Chickens', 'Ducks', 'Turkeys', 'Quail', 'Geese', 'Other'],
            'causes' => ['Disease', 'Heat stress', 'Flooding', 'Typhoon / strong wind', 'Lack of feed or water', 'Predator attack', 'Other', 'Unsure'],
        ],
        'Fishpond' => [
            'measure' => 'fish_count', 'subjectLabel' => 'Fish type', 'unit' => 'fish',
            'subjects' => ['Tilapia', 'Bangus (milkfish)', 'Hito (catfish)', 'Carp', 'Shrimp / prawn', 'Other'],
            'causes' => ['Poor water quality', 'Low oxygen / fish kill', 'Flooding / pond overflow', 'Drought / low water level', 'Disease', 'Pollution', 'Typhoon / strong wind', 'Other', 'Unsure'],
        ],
    ];
}

/** Unit word shown after a number for each measure. */
function damage_details_unit(string $measure): string
{
    return match ($measure) {
        'crop_percent' => '%',
        'head_count' => 'head',
        'bird_count' => 'birds',
        'fish_count' => 'fish',
        'fish_weight_kg' => 'kg',
        default => '',
    };
}

/** "30", "33.3", "1,250.5": trailing zeroes trimmed, never scientific notation. */
function damage_details_format(float|string|null $value, int $decimals = 2): string
{
    if ($value === null || $value === '') return '';
    $text = number_format((float) $value, $decimals, '.', ',');
    return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
}

/** Percentage of stock lost for count/weight measures (one decimal), or null when the total is unknown/zero. */
function damage_details_loss_percent(string $measure, float|string|null $total, float|string|null $lost): ?float
{
    if ($measure === 'crop_percent' || $total === null || $total === '' || $lost === null || $lost === '' || (float) $total <= 0) return null;
    return round(((float) $lost / (float) $total) * 100, 1);
}

/**
 * Parses a non-negative number: whole numbers only when $whole, otherwise up to $decimals places.
 * Returns the canonical string, '' for a blank optional value, or null when invalid.
 */
function damage_details_number(mixed $value, bool $whole, int $decimals, float $maximum): ?string
{
    $text = trim(is_scalar($value) ? (string) $value : '');
    if ($text === '') return '';
    $pattern = $whole ? '/^\d+$/' : '/^\d+(?:\.\d{1,' . $decimals . '})?$/';
    if (!preg_match($pattern, $text) || (float) $text > $maximum) return null;
    return $whole ? (string) (int) $text : $text;
}

/**
 * Validates the loss figures for one measure. $raw keys: percent, planted, affected, total, lost.
 * Error keys match the mobile fields (cropDamagePercent, plantedArea, affectedArea, totalBefore, amountLost).
 *
 * @return array{0:array<string,string>,1:array{crop_damage_percent:?string,planted_area_ha:?string,affected_area_ha:?string,total_before:?string,amount_lost:?string}}
 */
function damage_details_validate_figures(string $measure, array $raw): array
{
    $errors = [];
    $figures = ['crop_damage_percent' => null, 'planted_area_ha' => null, 'affected_area_ha' => null, 'total_before' => null, 'amount_lost' => null];
    $unit = damage_details_unit($measure);
    if ($measure === 'crop_percent') {
        $percent = damage_details_number($raw['percent'] ?? '', false, 2, 100);
        if ($percent === null) $errors['cropDamagePercent'] = 'Enter a percentage from 0 to 100.';
        elseif ($percent === '') $errors['cropDamagePercent'] = 'Enter your estimate of how much of this crop was damaged (0 to 100%).';
        else $figures['crop_damage_percent'] = $percent;
        $planted = damage_details_number($raw['planted'] ?? '', false, 4, DAMAGE_DETAILS_MAX_HECTARES);
        $affected = damage_details_number($raw['affected'] ?? '', false, 4, DAMAGE_DETAILS_MAX_HECTARES);
        if ($planted === null) $errors['plantedArea'] = 'Enter the planted area in hectares, or leave it blank.';
        elseif ($planted !== '') $figures['planted_area_ha'] = $planted;
        if ($affected === null) $errors['affectedArea'] = 'Enter the affected area in hectares, or leave it blank.';
        elseif ($affected !== '') $figures['affected_area_ha'] = $affected;
        if ($figures['planted_area_ha'] !== null && $figures['affected_area_ha'] !== null && (float) $figures['affected_area_ha'] > (float) $figures['planted_area_ha']) {
            $errors['affectedArea'] = 'The affected area cannot be larger than the planted area.';
        }
        return [$errors, $figures];
    }

    $whole = $measure !== 'fish_weight_kg';
    $maximum = $whole ? DAMAGE_DETAILS_MAX_COUNT : DAMAGE_DETAILS_MAX_KG;
    $kind = $whole ? 'a whole number' : 'a number with up to 2 decimal places';
    $total = damage_details_number($raw['total'] ?? '', $whole, 2, $maximum);
    $lost = damage_details_number($raw['lost'] ?? '', $whole, 2, $maximum);
    $totalRequired = in_array($measure, ['head_count', 'bird_count'], true);
    if ($total === null) $errors['totalBefore'] = "Enter the total before the incident as {$kind} ({$unit}).";
    elseif ($total === '' && $totalRequired) $errors['totalBefore'] = "Enter how many {$unit} there were before the incident.";
    elseif ($total !== '' && (float) $total <= 0) $errors['totalBefore'] = 'The total before the incident must be more than 0' . ($totalRequired ? '.' : ', or leave it blank if unknown.');
    elseif ($total !== '') $figures['total_before'] = $total;
    if ($lost === null) $errors['amountLost'] = "Enter the amount " . ($measure === 'head_count' || $measure === 'bird_count' ? 'that died' : 'lost') . " as {$kind} ({$unit}).";
    elseif ($lost === '') $errors['amountLost'] = $measure === 'head_count' || $measure === 'bird_count' ? "Enter how many {$unit} died (0 if none)." : "Enter the amount lost ({$unit}).";
    else $figures['amount_lost'] = $lost;
    if ($figures['total_before'] !== null && $figures['amount_lost'] !== null && (float) $figures['amount_lost'] > (float) $figures['total_before']) {
        $errors['amountLost'] = $measure === 'head_count' || $measure === 'bird_count'
            ? 'The number that died cannot be more than the total before the incident.'
            : 'The amount lost cannot be more than the total stock before the incident.';
    }
    return [$errors, $figures];
}

/** @return array{0:array<string,string>,1:array{cause:string,other:?string}} */
function damage_details_validate_cause(string $category, mixed $cause, mixed $other, string $causeKey, string $otherKey): array
{
    $config = damage_details_config()[$category] ?? null;
    $cause = trim(is_string($cause) ? $cause : '');
    $other = trim((string) preg_replace('/\s+/u', ' ', is_string($other) ? $other : ''));
    $errors = [];
    if ($config === null || !in_array($cause, $config['causes'], true)) $errors[$causeKey] = 'Select the suspected cause, or choose Unsure.';
    if ($cause === 'Other' && (mb_strlen($other) < 3 || mb_strlen($other) > 255)) $errors[$otherKey] = 'Explain the other cause (3 to 255 characters).';
    return [$errors, ['cause' => $cause, 'other' => $cause === 'Other' ? $other : null]];
}

/**
 * Validates a farmer's category-specific submission.
 *
 * @return array{0:array<string,string>,1:?array<string,?string>}
 */
function damage_details_validate_submission(string $category, array $input): array
{
    $config = damage_details_config()[$category] ?? null;
    if ($config === null) return [['farmType' => 'Select a valid farm type.'], null];
    $errors = [];
    $subject = trim(is_string($input['subject_type'] ?? null) ? $input['subject_type'] : '');
    $subjectOther = trim((string) preg_replace('/\s+/u', ' ', is_string($input['subject_type_other'] ?? null) ? $input['subject_type_other'] : ''));
    if (!in_array($subject, $config['subjects'], true)) $errors['subjectType'] = 'Select the ' . strtolower($config['subjectLabel']) . '.';
    elseif ($subject === 'Other') {
        if (mb_strlen($subjectOther) < 2 || mb_strlen($subjectOther) > 100 || !preg_match("/^[\p{L}\p{N}][\p{L}\p{N} .,'()\/-]*$/u", $subjectOther)) {
            $errors['subjectTypeOther'] = 'Type the ' . strtolower($config['subjectLabel']) . ' (2 to 100 characters).';
        } else {
            $subject = $subjectOther;
        }
    }
    [$causeErrors, $cause] = damage_details_validate_cause($category, $input['suspected_cause'] ?? '', $input['suspected_cause_other'] ?? '', 'suspectedCause', 'suspectedCauseOther');
    $measure = $config['measure'];
    if ($category === 'Fishpond') {
        $unit = is_string($input['fish_unit'] ?? null) ? $input['fish_unit'] : '';
        if (!in_array($unit, ['count', 'kg'], true)) $errors['fishUnit'] = 'Choose whether you are recording a number of fish or kilograms.';
        $measure = $unit === 'kg' ? 'fish_weight_kg' : 'fish_count';
    }
    [$figureErrors, $figures] = damage_details_validate_figures($measure, [
        'percent' => $input['crop_damage_percent'] ?? '', 'planted' => $input['planted_area_ha'] ?? '', 'affected' => $input['affected_area_ha'] ?? '',
        'total' => $input['total_before'] ?? '', 'lost' => $input['amount_lost'] ?? '',
    ]);
    $errors += $causeErrors + (isset($errors['fishUnit']) ? [] : $figureErrors);
    if ($errors) return [$errors, null];
    return [[], ['measure_type' => $measure, 'subject_type' => $subject, 'suspected_cause' => $cause['cause'], 'suspected_cause_other' => $cause['other']] + $figures];
}

/** Legacy damage_reports.damage_type text for a new report (lists, filters and older screens keep working). */
function damage_details_damage_type(array $details): string
{
    return match ($details['suspected_cause']) {
        'Other' => mb_substr('Other: ' . $details['suspected_cause_other'], 0, 100),
        'Unsure' => 'Unsure (cause not known)',
        default => mb_substr((string) $details['suspected_cause'], 0, 100),
    };
}

function damage_details_cause_label(?string $cause, ?string $other): string
{
    if ($cause === null || $cause === '') return '';
    if ($cause === 'Other') return 'Other: ' . (string) $other;
    if ($cause === 'Unsure') return 'Unsure (cause not known)';
    return $cause;
}

/** One-line loss summary such as "30 of 100 head died · 30%" or "Corn: 40% of this crop damaged". */
function damage_details_summary(string $measure, ?string $subject, array $figures): string
{
    $percent = $figures['crop_damage_percent'] ?? null;
    $total = $figures['total_before'] ?? null;
    $lost = $figures['amount_lost'] ?? null;
    if ($measure === 'crop_percent') {
        if ($percent === null || $percent === '') return '';
        $text = trim((string) $subject) . ': ' . damage_details_format($percent) . '% of this crop damaged';
        $planted = $figures['planted_area_ha'] ?? null;
        $affected = $figures['affected_area_ha'] ?? null;
        if ($affected !== null && $affected !== '' && $planted !== null && $planted !== '') $text .= ' · ' . damage_details_format($affected, 4) . ' of ' . damage_details_format($planted, 4) . ' ha affected';
        elseif ($affected !== null && $affected !== '') $text .= ' · ' . damage_details_format($affected, 4) . ' ha affected';
        elseif ($planted !== null && $planted !== '') $text .= ' · ' . damage_details_format($planted, 4) . ' ha planted';
        return $text;
    }
    if ($lost === null || $lost === '') return '';
    $unit = damage_details_unit($measure);
    $decimals = $measure === 'fish_weight_kg' ? 2 : 0;
    $verb = in_array($measure, ['head_count', 'bird_count'], true) ? 'died' : 'lost';
    $loss = damage_details_loss_percent($measure, $total, $lost);
    if ($total === null || $total === '') return damage_details_format($lost, $decimals) . ' ' . $unit . ' ' . $verb . ' · total stock not provided';
    return damage_details_format($lost, $decimals) . ' of ' . damage_details_format($total, $decimals) . ' ' . $unit . ' ' . $verb . ($loss === null ? '' : ' · ' . damage_details_format($loss, 1) . '%');
}

function damage_details_insert(PDO $connection, int $reportId, array $details): void
{
    $statement = $connection->prepare('INSERT INTO damage_report_details (report_id, details_version, measure_type, subject_type, suspected_cause, suspected_cause_other,
        crop_damage_percent, planted_area_ha, affected_area_ha, total_before, amount_lost) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $statement->execute([$reportId, DAMAGE_DETAILS_VERSION, $details['measure_type'], $details['subject_type'], $details['suspected_cause'], $details['suspected_cause_other'],
        $details['crop_damage_percent'], $details['planted_area_ha'], $details['affected_area_ha'], $details['total_before'], $details['amount_lost']]);
}

/** @param list<int> $reportIds @return array<int,array> rows keyed by report id */
function damage_details_for_reports(PDO $connection, array $reportIds): array
{
    $reportIds = array_values(array_unique(array_map('intval', $reportIds)));
    if (!$reportIds) return [];
    $statement = $connection->prepare('SELECT d.*, a.full_name AS verified_by_name FROM damage_report_details d LEFT JOIN admins a ON a.id = d.verified_by_admin_id WHERE d.report_id IN (' . implode(', ', array_fill(0, count($reportIds), '?')) . ')');
    $statement->execute($reportIds);
    $rows = [];
    foreach ($statement->fetchAll() as $row) $rows[(int) $row['report_id']] = $row;
    return $rows;
}

/**
 * Presentation of a stored details row (camelCase, as the app and admin page use it).
 * $includeVerified false hides admin values (the farmer sees them only once the report is resolved).
 */
function damage_details_present(?array $row, string $category, bool $includeVerified = true): ?array
{
    if ($row === null) return null;
    $config = damage_details_config()[$category] ?? null;
    $measure = (string) $row['measure_type'];
    $farmerFigures = ['crop_damage_percent' => $row['crop_damage_percent'], 'planted_area_ha' => $row['planted_area_ha'], 'affected_area_ha' => $row['affected_area_ha'], 'total_before' => $row['total_before'], 'amount_lost' => $row['amount_lost']];
    $number = static fn ($value) => $value === null ? null : (float) $value;
    // A just-submitted report (built from the validated input, not a stored row) has no verified columns.
    $hasVerified = ($row['verified_at'] ?? null) !== null;
    $verifiedFigures = $hasVerified ? ['crop_damage_percent' => $row['verified_crop_damage_percent'], 'planted_area_ha' => $row['verified_planted_area_ha'], 'affected_area_ha' => $row['verified_affected_area_ha'], 'total_before' => $row['verified_total_before'], 'amount_lost' => $row['verified_amount_lost']] : [];
    return [
        'version' => (int) $row['details_version'],
        'measureType' => $measure,
        'unit' => damage_details_unit($measure),
        'subjectLabel' => $config['subjectLabel'] ?? 'Affected',
        'subjectType' => (string) $row['subject_type'],
        'suspectedCause' => (string) $row['suspected_cause'],
        'suspectedCauseOther' => $row['suspected_cause_other'] === null ? null : (string) $row['suspected_cause_other'],
        'suspectedCauseLabel' => damage_details_cause_label((string) $row['suspected_cause'], $row['suspected_cause_other']),
        'cropDamagePercent' => $number($row['crop_damage_percent']),
        'plantedAreaHa' => $number($row['planted_area_ha']),
        'affectedAreaHa' => $number($row['affected_area_ha']),
        'totalBefore' => $number($row['total_before']),
        'amountLost' => $number($row['amount_lost']),
        'lossPercent' => damage_details_loss_percent($measure, $row['total_before'], $row['amount_lost']),
        'summary' => damage_details_summary($measure, (string) $row['subject_type'], $farmerFigures),
        'verified' => $includeVerified && $hasVerified ? [
            'cause' => (string) $row['verified_cause'],
            'causeOther' => $row['verified_cause_other'] === null ? null : (string) $row['verified_cause_other'],
            'causeLabel' => damage_details_cause_label($row['verified_cause'], $row['verified_cause_other']),
            'cropDamagePercent' => $number($row['verified_crop_damage_percent']),
            'plantedAreaHa' => $number($row['verified_planted_area_ha']),
            'affectedAreaHa' => $number($row['verified_affected_area_ha']),
            'totalBefore' => $number($row['verified_total_before']),
            'amountLost' => $number($row['verified_amount_lost']),
            'lossPercent' => damage_details_loss_percent($measure, $row['verified_total_before'], $row['verified_amount_lost']),
            'summary' => damage_details_summary($measure, (string) $row['subject_type'], $verifiedFigures),
            'reviewNotes' => $row['review_notes'] === null ? null : (string) $row['review_notes'],
            'verifiedBy' => $row['verified_by_name'] ?? null,
            'verifiedAt' => $row['verified_at'],
        ] : null,
    ];
}

final class DamageDetailsVerificationException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus, public readonly array $fieldErrors = [])
    {
        parent::__construct($message);
    }
}

/**
 * Saves admin-verified cause and figures for a report that is Under Review, in the report's own measure
 * and unit. The farmer's submitted values are never changed. Returns true when a stored value changed.
 */
function damage_details_save_verified(PDO $connection, int $reportId, int $adminId, array $input): bool
{
    $connection->beginTransaction();
    try {
        $report = $connection->prepare('SELECT id, status, agricultural_category FROM damage_reports WHERE id = ? AND is_fixture = 0 FOR UPDATE');
        $report->execute([$reportId]);
        $row = $report->fetch();
        if (!$row) throw new DamageDetailsVerificationException('This report was not found.', 404);
        if ($row['status'] !== 'Under Review') throw new DamageDetailsVerificationException('Verified figures can be saved only while the report is Under Review. Reload it to see the latest status.', 409);
        $details = $connection->prepare('SELECT * FROM damage_report_details WHERE report_id = ? FOR UPDATE');
        $details->execute([$reportId]);
        $stored = $details->fetch();
        if (!$stored) throw new DamageDetailsVerificationException('This older report has no category-specific details to verify.', 409);

        $category = (string) $row['agricultural_category'];
        [$causeErrors, $cause] = damage_details_validate_cause($category, $input['verified_cause'] ?? '', $input['verified_cause_other'] ?? '', 'verified_cause', 'verified_cause_other');
        [$figureErrors, $figures] = damage_details_validate_figures((string) $stored['measure_type'], [
            'percent' => $input['verified_crop_damage_percent'] ?? '', 'planted' => $input['verified_planted_area_ha'] ?? '', 'affected' => $input['verified_affected_area_ha'] ?? '',
            'total' => $input['verified_total_before'] ?? '', 'lost' => $input['verified_amount_lost'] ?? '',
        ]);
        $notes = trim(is_string($input['review_notes'] ?? null) ? $input['review_notes'] : '');
        $errors = $causeErrors;
        $map = ['cropDamagePercent' => 'verified_crop_damage_percent', 'plantedArea' => 'verified_planted_area_ha', 'affectedArea' => 'verified_affected_area_ha', 'totalBefore' => 'verified_total_before', 'amountLost' => 'verified_amount_lost'];
        foreach ($figureErrors as $key => $message) $errors[$map[$key]] = $message;
        if (mb_strlen($notes) > 4000) $errors['review_notes'] = 'Review notes must be 4,000 characters or fewer.';
        if ($errors) throw new DamageDetailsVerificationException('Correct the highlighted verified figures.', 422, $errors);

        $after = [$cause['cause'], $cause['other'], $figures['crop_damage_percent'], $figures['planted_area_ha'], $figures['affected_area_ha'], $figures['total_before'], $figures['amount_lost'], $notes === '' ? null : $notes];
        $before = [$stored['verified_cause'], $stored['verified_cause_other'], $stored['verified_crop_damage_percent'], $stored['verified_planted_area_ha'], $stored['verified_affected_area_ha'], $stored['verified_total_before'], $stored['verified_amount_lost'], $stored['review_notes']];
        $same = static fn ($a, $b) => ($a === null && $b === null) || ($a !== null && $b !== null && (is_numeric($a) && is_numeric($b) ? (float) $a === (float) $b : (string) $a === (string) $b));
        $changed = false;
        foreach ($after as $i => $value) if (!$same($value, $before[$i])) $changed = true;

        // An unchanged save keeps the original verifier and time.
        if ($changed) {
            $update = $connection->prepare('UPDATE damage_report_details SET verified_cause = ?, verified_cause_other = ?, verified_crop_damage_percent = ?, verified_planted_area_ha = ?,
                verified_affected_area_ha = ?, verified_total_before = ?, verified_amount_lost = ?, review_notes = ?, verified_by_admin_id = ?, verified_at = CURRENT_TIMESTAMP WHERE report_id = ?');
            $update->execute([...$after, $adminId, $reportId]);
            // Review history: who saved which verified values, while the report had which status.
            $audit = $connection->prepare('INSERT INTO damage_report_verification_audit (report_id, admin_id, verified_cause, verified_summary, review_notes, report_status) VALUES (?, ?, ?, ?, ?, ?)');
            $audit->execute([$reportId, $adminId, mb_substr(damage_details_cause_label($cause['cause'], $cause['other']), 0, 300),
                mb_substr(damage_details_summary((string) $stored['measure_type'], (string) $stored['subject_type'], $figures), 0, 255),
                $notes === '' ? null : $notes, (string) $row['status']]);
        }
        $connection->commit();
        return $changed;
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) $connection->rollBack();
        throw $exception;
    }
}
