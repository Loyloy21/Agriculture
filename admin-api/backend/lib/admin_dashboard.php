<?php
declare(strict_types=1);
require_once __DIR__ . '/cabadbaran.php';
require_once __DIR__ . '/report_photo_location.php';
require_once __DIR__ . '/production_records.php';

function admin_dashboard_data(PDO $connection, string $period): array
{
    $data = ['approved' => 0, 'pending' => 0, 'received' => 0, 'advisories' => [], 'recent' => [], 'applications' => [], 'work_reports' => [], 'trend' => [], 'map' => [], 'excluded' => ['fixtures' => 0, 'missing' => 0, 'outside' => 0, 'unchecked' => 0]];
    $counts = $connection->query("SELECT SUM(CASE WHEN verification_status = 'Approved' THEN 1 ELSE 0 END) approved, SUM(CASE WHEN verification_status = 'Pending' THEN 1 ELSE 0 END) pending FROM farmers")->fetch();
    $data['approved'] = (int) $counts['approved']; $data['pending'] = (int) $counts['pending'];
    $data['received'] = (int) $connection->query("SELECT COUNT(*) c FROM damage_reports WHERE is_fixture = 0 AND status = 'Received'")->fetch()['c'];
    $data['excluded']['fixtures'] = (int) $connection->query('SELECT COUNT(*) c FROM damage_reports WHERE is_fixture = 1')->fetch()['c'];
    $data['advisories'] = $connection->query("SELECT id, title, agricultural_category, weather_condition, location_coverage, effective_date, expiration_date FROM weather_advisories WHERE status = 'Active' AND effective_date <= CURRENT_DATE AND (expiration_date IS NULL OR expiration_date >= CURRENT_DATE) AND coverage_city_psgc_code = '1600203000' ORDER BY effective_date DESC, id DESC")->fetchAll();
    $data['applications'] = $connection->query("SELECT id, full_name, address, date_registered FROM farmers WHERE verification_status = 'Pending' ORDER BY date_registered ASC, id ASC LIMIT 3")->fetchAll();
    $data['work_reports'] = $connection->query("SELECT report_code, damage_type, submitted_at FROM damage_reports WHERE is_fixture = 0 AND status = 'Received' ORDER BY submitted_at ASC, id ASC LIMIT 3")->fetchAll();
    $data['recent'] = $connection->query("SELECT dr.report_code, f.full_name, dr.agricultural_category, dr.status, dr.submitted_at, dr.submitted_address FROM damage_reports dr INNER JOIN farmers f ON f.id = dr.farmer_id WHERE dr.is_fixture = 0 ORDER BY dr.submitted_at DESC, dr.id DESC LIMIT 5")->fetchAll();
    $days = $period === 'days';
    $start = new DateTimeImmutable($days ? 'today -29 days' : 'first day of this month -5 months');
    $start = $start->setTime(0, 0);
    $end = new DateTimeImmutable('tomorrow');
    $periodSql = db_is_pgsql($connection)
        ? ($days ? "to_char(submitted_at, 'YYYY-MM-DD')" : "to_char(submitted_at, 'YYYY-MM')")
        : ($days ? "DATE_FORMAT(submitted_at, '%Y-%m-%d')" : "DATE_FORMAT(submitted_at, '%Y-%m')");
    $statement = $connection->prepare("SELECT {$periodSql} period, COUNT(*) total, SUM(CASE WHEN status = 'Resolved' THEN 1 ELSE 0 END) resolved FROM damage_reports WHERE is_fixture = 0 AND submitted_at >= ? AND submitted_at < ? GROUP BY period ORDER BY period");
    $from = $start->format('Y-m-d H:i:s'); $to = $end->format('Y-m-d H:i:s');
    $statement->execute([$from, $to]);
    $lookup = [];
    foreach ($statement->fetchAll() as $row) $lookup[$row['period']] = $row;
    for ($i = 0; $i < ($days ? 30 : 6); $i++) {
        $date = $start->modify('+' . $i . ($days ? ' days' : ' months'));
        $key = $date->format($days ? 'Y-m-d' : 'Y-m');
        $data['trend'][] = ['label' => $date->format($days ? 'M j' : 'M Y'), 'total' => (int) ($lookup[$key]['total'] ?? 0), 'resolved' => (int) ($lookup[$key]['resolved'] ?? 0)];
    }
    // Map only saved report points inside the existing approximate city boundary.
    // Neither address guesses nor registered farm points substitute for missing GPS.
    $points = $connection->query('SELECT id, report_code, status, submitted_latitude, submitted_longitude, location_source FROM damage_reports WHERE is_fixture = 0 ORDER BY submitted_at DESC, id DESC');
    while ($row = $points->fetch()) {
        [$state] = admin_dashboard_point_state($row['submitted_latitude'], $row['submitted_longitude']);
        if ($state !== 'inside') { $data['excluded'][$state]++; continue; }
        $data['map'][] = ['id' => (int) $row['id'], 'code' => $row['report_code'], 'status' => $row['status'], 'latitude' => (float) $row['submitted_latitude'], 'longitude' => (float) $row['submitted_longitude'], 'source' => $row['location_source'] ?: 'Not recorded'];
    }
    return $data;
}

/** The dashboard's production month: ?production_month=YYYY-MM when valid, else the previous completed month in Asia/Manila. */
function admin_dashboard_production_month(mixed $requested = null, ?DateTimeImmutable $today = null): DateTimeImmutable
{
    $current = production_month_start($today ?? production_today());
    if (is_string($requested) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $requested)) {
        $month = DateTimeImmutable::createFromFormat('!Y-m-d', $requested . '-01', production_timezone());
        if ($month && $month->format('Y-m-d') >= PRODUCTION_EARLIEST_HARVEST_DATE && $month <= $current) return $month;
    }
    return production_previous_month($current);
}

/**
 * Production for one reporting month (the harvest/production date's month, not the submission date), from the
 * same production_records the farmer app writes: counted statuses only (Submitted, Verified), fixtures excluded.
 * Each saved record is one report (a retried submission is never a second row; a correction updates the row), so
 * Reports counts records and Farmers counts distinct farmers. Kilograms add only kg/MT records (quantity_kg);
 * Total produced is the sum of the category bars. Every value is read in one snapshot.
 */
function admin_dashboard_production(PDO $connection, DateTimeImmutable $month): array
{
    $until = $month->modify('first day of next month');
    $scope = ' FROM production_records pr JOIN commodities c ON c.id = pr.commodity_id WHERE pr.is_fixture = 0 AND ' . production_counted_sql() . ' AND pr.harvest_date >= ? AND pr.harvest_date < ?';
    $range = [$month->format('Y-m-d'), $until->format('Y-m-d')];
    $connection->beginTransaction();
    try {
        if (db_is_pgsql($connection)) $connection->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
        $statement = $connection->prepare('SELECT COUNT(*) AS reports, COUNT(DISTINCT pr.farmer_id) AS farmers, COUNT(pr.quantity_kg) AS kg_reports' . $scope);
        $statement->execute($range);
        $totals = $statement->fetch();
        $statement = $connection->prepare('SELECT c.agricultural_category AS category, SUM(pr.quantity_kg) AS total_kg, COUNT(*) AS reports' . $scope . ' GROUP BY c.agricultural_category');
        $statement->execute($range);
        $byCategory = [];
        foreach ($statement->fetchAll() as $row) $byCategory[(string) $row['category']] = $row;
        $statement = $connection->prepare('SELECT pr.id, ' . production_report_code_sql($connection) . ' AS report_code, f.full_name, c.agricultural_category, c.name AS commodity_name,
                pr.quantity, pr.quantity_kg, mu.symbol AS unit_symbol, pr.harvest_date
             FROM production_records pr JOIN farmers f ON f.id = pr.farmer_id JOIN commodities c ON c.id = pr.commodity_id JOIN measurement_units mu ON mu.code = pr.unit_code
             WHERE pr.is_fixture = 0 AND ' . production_counted_sql() . ' ORDER BY pr.created_at DESC, pr.id DESC LIMIT 5');
        $statement->execute();
        $latest = $statement->fetchAll();
        $connection->commit();
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) $connection->rollBack();
        throw $exception;
    }

    // Every standard category is shown (0 when nothing was reported); any other stored category is added, never dropped.
    $categories = [];
    foreach (array_unique(array_merge(array_keys(PRODUCTION_CATEGORIES), array_keys($byCategory))) as $code) {
        $row = $byCategory[$code] ?? null;
        $categories[] = ['code' => (string) $code, 'centikilograms' => $row ? production_centikilograms($row['total_kg'] ?? 0) : 0, 'reports' => $row ? (int) $row['reports'] : 0];
    }
    // Largest first, as the bars are read; equal values keep the standard category order.
    $order = array_flip(array_column($categories, 'code'));
    usort($categories, static fn(array $a, array $b): int => [$b['centikilograms'], $order[$a['code']]] <=> [$a['centikilograms'], $order[$b['code']]]);
    $totalCentikilograms = array_sum(array_column($categories, 'centikilograms'));
    return [
        'month' => $month, 'label' => $month->format('F Y'),
        'from' => $month->format('Y-m-d'), 'to' => $until->modify('-1 day')->format('Y-m-d'),
        'categories' => $categories, 'centikilograms' => $totalCentikilograms,
        'reports' => (int) $totals['reports'], 'farmers' => (int) $totals['farmers'], 'otherUnitReports' => (int) $totals['reports'] - (int) $totals['kg_reports'],
        'latest' => array_map(static function (array $row): array {
            $harvest = new DateTimeImmutable(substr((string) $row['harvest_date'], 0, 10), production_timezone());
            return [
                'id' => (int) $row['id'], 'code' => ($row['report_code'] ?? null) ?: production_legacy_code((int) $row['id']), 'farmer' => (string) $row['full_name'],
                'category' => (string) $row['agricultural_category'], 'commodity' => (string) $row['commodity_name'],
                'produced' => $row['quantity_kg'] !== null ? admin_dashboard_kg(production_centikilograms($row['quantity_kg'])) : production_format_quantity(production_milliunits($row['quantity']) / 1000, (string) $row['unit_symbol']),
                'inKilograms' => $row['quantity_kg'] !== null,
                'monthLabel' => $harvest->format('M Y'), 'from' => $harvest->modify('first day of this month')->format('Y-m-d'), 'to' => $harvest->modify('last day of this month')->format('Y-m-d'),
            ];
        }, $latest),
    ];
}

/** "7,200 kg", "12.5 kg": kilograms from integer centikilograms, with only the decimals the value has. */
function admin_dashboard_kg(int $centikilograms): string
{
    return rtrim(rtrim(number_format($centikilograms / 100, 2, '.', ','), '0'), '.') . ' kg';
}

/** A round axis maximum (1, 2, 2.5 or 5 times a power of ten) at or above $value, in kilograms. */
function admin_dashboard_axis_max(float $value): float
{
    if ($value <= 0) return 1.0;
    $power = 10 ** floor(log10($value));
    foreach ([1, 2, 2.5, 5, 10] as $step) if ($step * $power >= $value) return $step * $power;
    return 10 * $power;
}

function admin_dashboard_point_state(mixed $latitude, mixed $longitude): array
{
    if ($latitude === null || $longitude === null || !is_numeric($latitude) || !is_numeric($longitude)) return ['missing'];
    $lat = (float) $latitude; $lon = (float) $longitude;
    if (!is_finite($lat) || !is_finite($lon) || abs($lat) > 90 || abs($lon) > 180 || ($lat == 0 && $lon == 0)) return ['missing'];
    $check = cabadbaran_boundary_check($lat, $lon);
    return [$check['inside'] === true ? 'inside' : ($check['inside'] === false ? 'outside' : 'unchecked')];
}
