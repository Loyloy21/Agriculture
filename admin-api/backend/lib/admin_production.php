<?php
declare(strict_types=1);

// Admin Production Report: reads the same production_records the farmer mobile app writes (through
// backend/api/production/index.php), with the same commodity catalog, units and counting rules
// (backend/lib/production_records.php). Nothing is copied or pre-aggregated; every total is a database
// aggregate over the filtered records, so it covers all matching records, not only the visible page.
// Reporting periods use the harvest/production date in the Asia/Manila calendar.
// Kilogram totals only add kg and MT records (quantity_kg); head, pieces, trays and liters are totalled
// separately per unit and never added to kilograms or to each other.

require_once __DIR__ . '/production_records.php';

const ADMIN_PRODUCTION_PERIODS = [
    'current_month' => 'Current month',
    'previous_month' => 'Previous month',
    'last_3_months' => 'Last 3 completed months',
    'last_6_months' => 'Last 6 completed months',
    'current_year' => 'Current year',
    'previous_year' => 'Previous year',
    'custom' => 'Custom date range',
];
/** "counted" (default) = the statuses that count toward totals; Rejected records are never added to totals. */
const ADMIN_PRODUCTION_STATUSES = ['counted' => 'Counted (Submitted and Verified)', 'all' => 'All statuses', 'Submitted' => 'Submitted', 'Verified' => 'Verified', 'Rejected' => 'Rejected'];
const ADMIN_PRODUCTION_SORTS = ['harvest_date' => 'pr.harvest_date', 'submitted' => 'pr.created_at', 'farmer' => 'f.full_name', 'commodity' => 'c.name', 'category' => 'c.agricultural_category', 'quantity' => 'pr.quantity', 'record' => 'pr.id'];
const ADMIN_PRODUCTION_PAGE_SIZE = 15;
const ADMIN_PRODUCTION_EXPORT_LIMIT = 10000;
const ADMIN_PRODUCTION_FROM_SQL = ' FROM production_records pr
    JOIN farmers f ON f.id = pr.farmer_id
    JOIN commodities c ON c.id = pr.commodity_id
    JOIN measurement_units mu ON mu.code = pr.unit_code
    LEFT JOIN farm_profiles fp ON fp.id = pr.farm_profile_id';

/** Normalised filter values (strings), defaulting to the current month (the month new harvests are reported in). */
function admin_production_filters_from_input(array $input): array
{
    $string = static fn(string $key, int $max = 120): string => is_scalar($input[$key] ?? null) ? mb_substr(trim((string) $input[$key]), 0, $max) : '';
    $id = static fn(string $key): string => production_positive_int($string($key, 12)) === null ? '' : (string) production_positive_int($string($key, 12));
    $period = $string('period', 20);
    $category = $string('category', 20);
    $status = $string('status', 12);
    $sort = $string('sort', 20);
    return [
        'period' => array_key_exists($period, ADMIN_PRODUCTION_PERIODS) ? $period : 'current_month',
        'date_from' => $period === 'custom' ? $string('date_from', 10) : '',
        'date_to' => $period === 'custom' ? $string('date_to', 10) : '',
        'category' => array_key_exists($category, PRODUCTION_CATEGORIES) ? $category : '',
        'commodity_id' => $id('commodity_id'),
        'farm_id' => $id('farm_id'),
        'farmer_id' => $id('farmer_id'),
        // One exact record (links from the dashboard); it still has to fall inside the period.
        'record' => $id('record'),
        'status' => array_key_exists($status, ADMIN_PRODUCTION_STATUSES) ? $status : 'counted',
        'search' => preg_replace('/\s+/u', ' ', $string('search', 100)) ?? '',
        'sort' => array_key_exists($sort, ADMIN_PRODUCTION_SORTS) ? $sort : 'harvest_date',
        'dir' => $string('dir', 4) === 'asc' ? 'asc' : 'desc',
    ];
}

/** The filters that differ from the defaults, for short links (pagination, sorting, export). */
function admin_production_query(array $filters, array $overrides = []): array
{
    $defaults = admin_production_filters_from_input([]);
    $query = [];
    foreach (array_merge($filters, $overrides) as $key => $value) if ($value !== '' && ($defaults[$key] ?? null) !== $value) $query[$key] = $value;
    return $query;
}

/**
 * Resolves the reporting period to [from, until) dates by harvest/production date.
 * @return array{from:DateTimeImmutable,until:DateTimeImmutable,label:string,range:string}
 */
function admin_production_period(array $filters, ?DateTimeImmutable $today = null): array
{
    $today = $today ?? production_today();
    $month = production_month_start($today);
    [$from, $until] = match ($filters['period']) {
        'previous_month' => [production_previous_month($month), $month],
        'last_3_months' => [$month->modify('-3 months'), $month],
        'last_6_months' => [$month->modify('-6 months'), $month],
        'current_year' => [$month->setDate((int) $month->format('Y'), 1, 1), $month->setDate((int) $month->format('Y') + 1, 1, 1)],
        'previous_year' => [$month->setDate((int) $month->format('Y') - 1, 1, 1), $month->setDate((int) $month->format('Y'), 1, 1)],
        'custom' => admin_production_custom_range($filters),
        default => [$month, $month->modify('first day of next month')],
    };
    $last = $until->modify('-1 day');
    $range = $from->format('Y-m') === $last->format('Y-m') && $from->format('d') === '01' && $until->format('d') === '01'
        ? $from->format('F Y')
        : $from->format('M j, Y') . ' – ' . $last->format('M j, Y');
    return ['from' => $from, 'until' => $until, 'label' => ADMIN_PRODUCTION_PERIODS[$filters['period']], 'range' => $range];
}

/** @return array{0:DateTimeImmutable,1:DateTimeImmutable} */
function admin_production_custom_range(array $filters): array
{
    $parse = static function (string $value, string $label): DateTimeImmutable {
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value, production_timezone()) : false;
        if (!$date || $date->format('Y-m-d') !== $value) throw new InvalidArgumentException("Enter a valid {$label} date for the custom range.");
        return $date;
    };
    $from = $parse($filters['date_from'], 'start');
    $to = $parse($filters['date_to'], 'end');
    if ($to < $from) throw new InvalidArgumentException('The custom range ends before it starts.');
    if ($from->format('Y-m-d') < PRODUCTION_EARLIEST_HARVEST_DATE || $from->diff($to)->days > 3660) throw new InvalidArgumentException('Choose a custom range from the year 2000 onward, up to ten years long.');
    return [$from, $to->modify('+1 day')];
}

/** Escapes LIKE wildcards; '!' is the escape character on MySQL and PostgreSQL alike. */
function admin_production_like(string $value): string
{
    return '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($value)) . '%';
}

/**
 * WHERE clause over ADMIN_PRODUCTION_FROM_SQL for the filters and the harvest-date range [from, until).
 * Every join is many-to-one, so a record appears at most once in any result.
 * @return array{0:string,1:list<int|string>}
 */
function admin_production_where(array $filters, DateTimeImmutable $from, DateTimeImmutable $until, bool $countedOnly = false, bool $searchReportCodes = false): array
{
    $where = ['pr.is_fixture = 0', 'pr.harvest_date >= ?', 'pr.harvest_date < ?'];
    $params = [$from->format('Y-m-d'), $until->format('Y-m-d')];
    if ($filters['category'] !== '') { $where[] = 'c.agricultural_category = ?'; $params[] = $filters['category']; }
    foreach (['commodity_id' => 'pr.commodity_id', 'farm_id' => 'pr.farm_profile_id', 'farmer_id' => 'pr.farmer_id', 'record' => 'pr.id'] as $key => $column) {
        if ($filters[$key] !== '') { $where[] = $column . ' = ?'; $params[] = (int) $filters[$key]; }
    }
    if ($filters['status'] === 'counted') $where[] = production_counted_sql();
    elseif ($filters['status'] !== 'all') { $where[] = 'pr.status = ?'; $params[] = $filters['status']; }
    // Quantities only ever add counted records, within whatever status the filter selects (Rejected -> nothing).
    if ($countedOnly && $filters['status'] !== 'counted') $where[] = production_counted_sql();
    // Every word must match the farmer, farm area, commodity (English/Filipino names) or the report ID.
    foreach (array_filter(explode(' ', $filters['search'])) as $word) {
        $like = admin_production_like($word);
        $match = "LOWER(f.full_name) LIKE ? ESCAPE '!' OR LOWER(COALESCE(fp.farm_name, '')) LIKE ? ESCAPE '!' OR LOWER(c.name) LIKE ? ESCAPE '!' OR LOWER(c.search_names) LIKE ? ESCAPE '!'";
        array_push($params, $like, $like, $like, $like);
        if ($searchReportCodes) { $match .= " OR LOWER(COALESCE(pr.report_code, '')) LIKE ? ESCAPE '!'"; $params[] = $like; }
        if (preg_match('/^(?:PR-?)?0*(\d{1,9})$/i', $word, $code)) { $match .= ' OR pr.id = ?'; $params[] = (int) $code[1]; }
        $where[] = '(' . $match . ')';
    }
    return [implode(' AND ', $where), $params];
}

/** "PR-000123": the ID of a record saved without a report ID (accepted by the search as well). */
function admin_production_code(int $id): string
{
    return production_legacy_code($id);
}

/** Clears a commodity that does not belong to the selected category (and reports whether it did). */
function admin_production_normalize_filters(PDO $connection, array $filters): array
{
    if ($filters['commodity_id'] === '' || $filters['category'] === '') return [$filters, false];
    $statement = $connection->prepare('SELECT agricultural_category FROM commodities WHERE id = ?');
    $statement->execute([(int) $filters['commodity_id']]);
    if ($statement->fetchColumn() === $filters['category']) return [$filters, false];
    $filters['commodity_id'] = '';
    return [$filters, true];
}

function admin_production_unit_totals(array $rows): array
{
    return array_map(static fn(array $row): array => [
        'unitCode' => (string) $row['unit_code'], 'unitName' => (string) $row['unit_name'], 'unitSymbol' => (string) $row['unit_symbol'],
        'total' => production_milliunits($row['total_quantity']) / 1000, 'recordCount' => (int) $row['record_count'],
    ], $rows);
}

/**
 * Summary cards, category breakdown, commodity totals and the monthly trend for every record matching the filters.
 * Records/farmers/categories count the records the status filter selects; quantities only add counted statuses.
 */
function admin_production_summary(PDO $connection, array $filters, array $period): array
{
    [$where, $params] = admin_production_where($filters, $period['from'], $period['until'], false, production_report_codes_ready($connection));
    [$counted, $countedParams] = admin_production_where($filters, $period['from'], $period['until'], true, production_report_codes_ready($connection));
    $statement = $connection->prepare('SELECT COUNT(*) AS records, COUNT(DISTINCT pr.farmer_id) AS farmers, COUNT(DISTINCT c.agricultural_category) AS categories,
            COUNT(DISTINCT pr.commodity_id) AS commodities, COUNT(DISTINCT pr.farm_profile_id) AS farms' . ADMIN_PRODUCTION_FROM_SQL . ' WHERE ' . $where);
    $statement->execute($params);
    $totals = $statement->fetch();

    $statement = $connection->prepare('SELECT SUM(pr.quantity_kg) AS total_kg, COUNT(pr.quantity_kg) AS kg_records, COUNT(*) AS counted_records' . ADMIN_PRODUCTION_FROM_SQL . ' WHERE ' . $counted);
    $statement->execute($countedParams);
    $weights = $statement->fetch();

    $statement = $connection->prepare('SELECT pr.unit_code, mu.name AS unit_name, mu.symbol AS unit_symbol, SUM(pr.quantity) AS total_quantity, COUNT(*) AS record_count'
        . ADMIN_PRODUCTION_FROM_SQL . ' WHERE ' . $counted . ' AND pr.quantity_kg IS NULL GROUP BY pr.unit_code, mu.name, mu.symbol, mu.sort_order ORDER BY mu.sort_order');
    $statement->execute($countedParams);
    $otherTotals = admin_production_unit_totals($statement->fetchAll());

    // Per category: the selected records and farmers, the counted kilograms.
    $statement = $connection->prepare('SELECT c.agricultural_category, COUNT(*) AS records, COUNT(DISTINCT pr.farmer_id) AS farmers,
            SUM(CASE WHEN ' . production_counted_sql() . ' THEN pr.quantity_kg END) AS total_kg' . ADMIN_PRODUCTION_FROM_SQL . ' WHERE ' . $where . ' GROUP BY c.agricultural_category');
    $statement->execute($params);
    $categoryRows = [];
    foreach ($statement->fetchAll() as $row) $categoryRows[(string) $row['agricultural_category']] = $row;
    $statement = $connection->prepare('SELECT c.agricultural_category, pr.unit_code, mu.name AS unit_name, mu.symbol AS unit_symbol, SUM(pr.quantity) AS total_quantity, COUNT(*) AS record_count'
        . ADMIN_PRODUCTION_FROM_SQL . ' WHERE ' . $counted . ' AND pr.quantity_kg IS NULL GROUP BY c.agricultural_category, pr.unit_code, mu.name, mu.symbol, mu.sort_order ORDER BY mu.sort_order');
    $statement->execute($countedParams);
    $categoryOther = [];
    foreach ($statement->fetchAll() as $row) $categoryOther[(string) $row['agricultural_category']][] = $row;

    // Per commodity: kilograms (kg and MT records) and, separately, the other units.
    $statement = $connection->prepare('SELECT c.id, c.name, c.agricultural_category, SUM(pr.quantity_kg) AS total_kg, COUNT(*) AS record_count, COUNT(DISTINCT pr.farmer_id) AS farmers'
        . ADMIN_PRODUCTION_FROM_SQL . ' WHERE ' . $counted . ' AND pr.quantity_kg IS NOT NULL GROUP BY c.id, c.name, c.agricultural_category ORDER BY total_kg DESC, c.name ASC');
    $statement->execute($countedParams);
    $totalCentikilograms = production_centikilograms($weights['total_kg'] ?? 0);
    $commodities = array_map(static function (array $row) use ($totalCentikilograms): array {
        $kilograms = production_centikilograms($row['total_kg']);
        return ['commodityId' => (int) $row['id'], 'name' => (string) $row['name'], 'category' => (string) $row['agricultural_category'], 'totalKg' => $kilograms / 100,
            'recordCount' => (int) $row['record_count'], 'farmers' => (int) $row['farmers'], 'percent' => $totalCentikilograms > 0 ? round($kilograms / $totalCentikilograms * 100, 1) : 0.0];
    }, $statement->fetchAll());
    $statement = $connection->prepare('SELECT c.id, c.name, c.agricultural_category, pr.unit_code, mu.name AS unit_name, mu.symbol AS unit_symbol, SUM(pr.quantity) AS total_quantity, COUNT(*) AS record_count'
        . ADMIN_PRODUCTION_FROM_SQL . ' WHERE ' . $counted . ' AND pr.quantity_kg IS NULL GROUP BY c.id, c.name, c.agricultural_category, pr.unit_code, mu.name, mu.symbol, mu.sort_order ORDER BY mu.sort_order, total_quantity DESC, c.name');
    $statement->execute($countedParams);
    $otherCommodities = array_map(static fn(array $row): array => ['commodityId' => (int) $row['id'], 'name' => (string) $row['name'], 'category' => (string) $row['agricultural_category']]
        + admin_production_unit_totals([$row])[0], $statement->fetchAll());

    $categories = [];
    foreach (PRODUCTION_CATEGORIES as $code => $label) {
        $row = $categoryRows[$code] ?? null;
        $kilograms = $row ? production_centikilograms($row['total_kg'] ?? 0) : 0;
        $categories[] = [
            'code' => $code, 'label' => $label, 'records' => $row ? (int) $row['records'] : 0, 'farmers' => $row ? (int) $row['farmers'] : 0,
            'totalKg' => $kilograms / 100, 'percent' => $totalCentikilograms > 0 ? round($kilograms / $totalCentikilograms * 100, 1) : 0.0,
            'otherTotals' => admin_production_unit_totals($categoryOther[$code] ?? []),
            'commodities' => array_values(array_filter($commodities, static fn(array $item): bool => $item['category'] === $code)),
            'otherCommodities' => array_values(array_filter($otherCommodities, static fn(array $item): bool => $item['category'] === $code)),
        ];
    }

    return [
        'records' => (int) $totals['records'], 'farmers' => (int) $totals['farmers'], 'categories' => (int) $totals['categories'],
        'commodities' => (int) $totals['commodities'], 'farms' => (int) $totals['farms'],
        'countedRecords' => (int) $weights['counted_records'], 'kgRecords' => (int) $weights['kg_records'],
        'totalKg' => $totalCentikilograms / 100, 'otherTotals' => $otherTotals,
        'categoryBreakdown' => $categories, 'commodityTotals' => $commodities, 'otherCommodityTotals' => $otherCommodities,
        'trend' => admin_production_trend($connection, $filters, $period),
    ];
}

/**
 * Monthly kilograms (and other units) for the selected filters. Short periods are shown in context: the chart covers
 * at least the six months ending with the period (at most 24), and marks the months inside the selected period.
 */
function admin_production_trend(PDO $connection, array $filters, array $period): array
{
    $lastMonth = production_month_start($period['until']->modify('-1 day'));
    $firstMonth = production_month_start($period['from']);
    $months = ((int) $lastMonth->format('Y') - (int) $firstMonth->format('Y')) * 12 + (int) $lastMonth->format('n') - (int) $firstMonth->format('n') + 1;
    $start = $months < 6 ? $lastMonth->modify('-5 months') : ($months > 24 ? $lastMonth->modify('-23 months') : $firstMonth);
    $end = $lastMonth->modify('first day of next month');
    [$counted, $params] = admin_production_where($filters, $start, $end, true, production_report_codes_ready($connection));
    $statement = $connection->prepare('SELECT EXTRACT(YEAR FROM pr.harvest_date) AS y, EXTRACT(MONTH FROM pr.harvest_date) AS m, pr.unit_code, mu.name AS unit_name, mu.symbol AS unit_symbol,
            SUM(pr.quantity_kg) AS total_kg, SUM(pr.quantity) AS total_quantity, COUNT(*) AS record_count' . ADMIN_PRODUCTION_FROM_SQL . ' WHERE ' . $counted . '
         GROUP BY EXTRACT(YEAR FROM pr.harvest_date), EXTRACT(MONTH FROM pr.harvest_date), pr.unit_code, mu.name, mu.symbol, mu.sort_order ORDER BY mu.sort_order');
    $statement->execute($params);
    $byMonth = [];
    foreach ($statement->fetchAll() as $row) $byMonth[sprintf('%04d-%02d', (int) $row['y'], (int) $row['m'])][] = $row;
    $trend = [];
    for ($month = $start; $month < $end; $month = $month->modify('first day of next month')) {
        $key = $month->format('Y-m');
        $kilograms = 0;
        $records = 0;
        $other = [];
        foreach ($byMonth[$key] ?? [] as $row) {
            $records += (int) $row['record_count'];
            if ($row['total_kg'] !== null) $kilograms += production_centikilograms($row['total_kg']);
            else $other[] = admin_production_unit_totals([$row])[0];
        }
        $trend[] = ['month' => $key, 'label' => $month->format('M Y'), 'shortLabel' => $month->format('M'), 'totalKg' => $kilograms / 100, 'recordCount' => $records,
            'otherTotals' => $other, 'inPeriod' => $month >= production_month_start($period['from']) && $month < $period['until']];
    }
    return $trend;
}

function admin_production_count(PDO $connection, array $filters, array $period): int
{
    [$where, $params] = admin_production_where($filters, $period['from'], $period['until'], false, production_report_codes_ready($connection));
    $statement = $connection->prepare('SELECT COUNT(*)' . ADMIN_PRODUCTION_FROM_SQL . ' WHERE ' . $where);
    $statement->execute($params);
    return (int) $statement->fetchColumn();
}

/** One page (or, for the export, all) of matching records in the chosen order. */
function admin_production_records(PDO $connection, array $filters, array $period, int $limit, int $offset = 0): array
{
    [$where, $params] = admin_production_where($filters, $period['from'], $period['until'], false, production_report_codes_ready($connection));
    $order = ADMIN_PRODUCTION_SORTS[$filters['sort']] . ' ' . ($filters['dir'] === 'asc' ? 'ASC' : 'DESC') . ', pr.id ' . ($filters['dir'] === 'asc' ? 'ASC' : 'DESC');
    $statement = $connection->prepare('SELECT pr.id, ' . production_report_code_sql($connection) . ' AS report_code, pr.harvest_date, pr.created_at, pr.updated_at, pr.quantity, pr.quantity_kg, pr.unit_code, mu.name AS unit_name, mu.symbol AS unit_symbol,
            pr.status, pr.remarks, f.id AS farmer_id, f.full_name, fp.id AS farm_id, fp.farm_name, fp.barangay_name, fp.status AS farm_status,
            c.id AS commodity_id, c.name AS commodity_name, c.agricultural_category, c.commodity_group, c.status AS commodity_status'
        . ADMIN_PRODUCTION_FROM_SQL . ' WHERE ' . $where . ' ORDER BY ' . $order . ' LIMIT ' . max(1, min($limit, ADMIN_PRODUCTION_EXPORT_LIMIT + 1)) . ' OFFSET ' . max(0, $offset));
    $statement->execute($params);
    return array_map('admin_production_row', $statement->fetchAll());
}

/** A record for the admin table, details and export (only fields the system stores; no farmer contact data). */
function admin_production_row(array $row): array
{
    $quantity = production_milliunits($row['quantity']) / 1000;
    $remarks = trim((string) ($row['remarks'] ?? ''));
    return [
        'id' => (int) $row['id'],
        // The persisted report ID; records saved before migration 0010 keep their "PR-" + id.
        'code' => ($row['report_code'] ?? null) ?: admin_production_code((int) $row['id']),
        'farmerId' => (int) $row['farmer_id'],
        'farmerName' => (string) $row['full_name'],
        'farmId' => $row['farm_id'] === null ? null : (int) $row['farm_id'],
        'farmName' => $row['farm_name'] === null ? null : (string) $row['farm_name'],
        'farmBarangay' => ($row['barangay_name'] ?? null) ?: null,
        'farmArchived' => ($row['farm_status'] ?? null) === 'Archived',
        'category' => (string) $row['agricultural_category'],
        'categoryLabel' => production_category_label((string) $row['agricultural_category']),
        'commodityId' => (int) $row['commodity_id'],
        'commodityName' => (string) $row['commodity_name'],
        'commodityGroup' => (string) $row['commodity_group'],
        'commodityInactive' => ($row['commodity_status'] ?? 'Active') !== 'Active',
        'quantity' => $quantity,
        'quantityText' => production_format_quantity($quantity, (string) $row['unit_symbol']),
        'unitCode' => (string) $row['unit_code'],
        'unitName' => (string) $row['unit_name'],
        'unitSymbol' => (string) $row['unit_symbol'],
        'quantityKg' => $row['quantity_kg'] === null ? null : production_centikilograms($row['quantity_kg']) / 100,
        'harvestDate' => substr((string) $row['harvest_date'], 0, 10),
        'submittedAt' => (string) $row['created_at'],
        'updatedAt' => (string) $row['updated_at'],
        'status' => (string) $row['status'],
        'counted' => in_array((string) $row['status'], PRODUCTION_COUNTED_STATUSES, true),
        'remarks' => $remarks === '' ? null : $remarks,
    ];
}

/** All commodities (inactive ones too: older records may use them) for the dependent commodity filter. */
function admin_production_commodity_options(PDO $connection): array
{
    return array_map(static fn(array $row): array => [
        'id' => (int) $row['id'], 'name' => (string) $row['name'], 'category' => (string) $row['agricultural_category'],
        'group' => (string) $row['commodity_group'], 'searchNames' => (string) $row['search_names'], 'active' => $row['status'] === 'Active',
    ], $connection->query('SELECT id, name, agricultural_category, commodity_group, search_names, status FROM commodities ORDER BY agricultural_category, commodity_group, name')->fetchAll());
}

/** Farmer search (name or RSBSA reference digits); farmers with production records first. No contact details. */
function admin_production_farmer_options(PDO $connection, string $query, int $limit = 20): array
{
    $params = [];
    $where = [];
    // Every typed word must match the name; a word of 4+ digits may instead match the RSBSA reference.
    foreach (array_filter(explode(' ', preg_replace('/\s+/u', ' ', trim($query)) ?? '')) as $word) {
        $match = "LOWER(f.full_name) LIKE ? ESCAPE '!'";
        $params[] = admin_production_like($word);
        $digits = (string) preg_replace('/\D/', '', $word);
        if (strlen($digits) >= 4) { $match .= " OR REPLACE(COALESCE(fi.id_number, ''), '-', '') LIKE ? ESCAPE '!'"; $params[] = admin_production_like($digits); }
        $where[] = '(' . $match . ')';
    }
    $statement = $connection->prepare('SELECT f.id, f.full_name, f.residential_barangay_name,
            (SELECT COUNT(*) FROM production_records pr WHERE pr.farmer_id = f.id AND pr.is_fixture = 0) AS records
        FROM farmers f LEFT JOIN farmer_ids fi ON fi.farmer_id = f.id' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY records DESC, f.full_name ASC, f.id ASC LIMIT ' . max(1, min($limit, 50)));
    $statement->execute($params);
    return array_map(static fn(array $row): array => ['id' => (int) $row['id'], 'label' => (string) $row['full_name'],
        'detail' => trim(($row['residential_barangay_name'] ? 'Brgy. ' . $row['residential_barangay_name'] . ' · ' : '') . (int) $row['records'] . ' production record' . ((int) $row['records'] === 1 ? '' : 's'))], $statement->fetchAll());
}

/** Farm area search by farm name, barangay or owner (optionally one farmer's farms); archived farms keep their records. */
function admin_production_farm_options(PDO $connection, string $query, ?int $farmerId = null, int $limit = 20): array
{
    $where = [];
    $params = [];
    foreach (array_filter(explode(' ', preg_replace('/\s+/u', ' ', trim($query)) ?? '')) as $word) {
        $where[] = "(LOWER(fp.farm_name) LIKE ? ESCAPE '!' OR LOWER(COALESCE(fp.barangay_name, '')) LIKE ? ESCAPE '!' OR LOWER(f.full_name) LIKE ? ESCAPE '!')";
        array_push($params, admin_production_like($word), admin_production_like($word), admin_production_like($word));
    }
    if ($farmerId) { $where[] = 'fp.farmer_id = ?'; $params[] = $farmerId; }
    $statement = $connection->prepare('SELECT fp.id, fp.farm_name, fp.barangay_name, fp.agricultural_category, fp.status, f.full_name,
            (SELECT COUNT(*) FROM production_records pr WHERE pr.farm_profile_id = fp.id AND pr.is_fixture = 0) AS records
        FROM farm_profiles fp JOIN farmers f ON f.id = fp.farmer_id' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY records DESC, fp.farm_name ASC, fp.id ASC LIMIT ' . max(1, min($limit, 50)));
    $statement->execute($params);
    return array_map(static fn(array $row): array => ['id' => (int) $row['id'], 'label' => (string) $row['farm_name'],
        'detail' => trim($row['full_name'] . ($row['barangay_name'] ? ' · Brgy. ' . $row['barangay_name'] : '') . ' · ' . production_category_label((string) $row['agricultural_category']) . ($row['status'] === 'Archived' ? ' · archived' : ''))], $statement->fetchAll());
}

/** Display names for the selected farmer and farm area (so the filter shows what is applied). */
function admin_production_selected_labels(PDO $connection, array $filters): array
{
    $labels = ['farmer' => '', 'farm' => '', 'commodity' => ''];
    $lookups = ['farmer' => ['farmer_id', 'SELECT full_name FROM farmers WHERE id = ?'], 'farm' => ['farm_id', 'SELECT farm_name FROM farm_profiles WHERE id = ?'], 'commodity' => ['commodity_id', 'SELECT name FROM commodities WHERE id = ?']];
    foreach ($lookups as $key => [$filter, $sql]) {
        if ($filters[$filter] === '') continue;
        $statement = $connection->prepare($sql);
        $statement->execute([(int) $filters[$filter]]);
        $labels[$key] = (string) ($statement->fetchColumn() ?: '');
    }
    return $labels;
}

/** Neutralises spreadsheet formulas in exported text (CSV injection). */
function admin_production_csv_cell(mixed $value): string
{
    $text = (string) ($value ?? '');
    return preg_match('/^[=+\-@\t\r]/', $text) ? "'" . $text : $text;
}

/**
 * Writes the filtered report as CSV (UTF-8 with BOM for Excel): the applied filters, then every matching record with its
 * original quantity and unit, then the totals per unit (kilograms never include head, pieces, trays or liters).
 * @param resource $output
 */
function admin_production_write_csv($output, PDO $connection, array $filters, array $period, array $labels, array $summary, array $records): void
{
    $row = static fn(array $values) => fputcsv($output, array_map('admin_production_csv_cell', $values), ',', '"', '');
    fwrite($output, "\xEF\xBB\xBF");
    $row(['AgriReach Production Report']);
    $row(['Reporting period (by harvest/production date)', $period['label'] . ': ' . $period['range']]);
    $row(['Category', $filters['category'] === '' ? 'All categories' : production_category_label($filters['category'])]);
    $row(['Commodity', $labels['commodity'] ?: 'All commodities']);
    $row(['Farm area', $labels['farm'] ?: 'All farm areas']);
    $row(['Farmer', $labels['farmer'] ?: 'All farmers']);
    $row(['Status', ADMIN_PRODUCTION_STATUSES[$filters['status']]]);
    $row(['Search', $filters['search'] === '' ? 'None' : $filters['search']]);
    $row(['Generated (Asia/Manila)', (new DateTimeImmutable('now', production_timezone()))->format('Y-m-d H:i')]);
    $row([]);
    $row(['Record ID', 'Farmer', 'Farm area', 'Barangay', 'Production category', 'Commodity', 'Commodity group', 'Quantity', 'Unit', 'Kilogram equivalent', 'Date harvested / produced', 'Date submitted', 'Status', 'Counted in totals', 'Remarks']);
    foreach ($records as $record) {
        $row([$record['code'], $record['farmerName'], $record['farmName'] ?? 'Not recorded', $record['farmBarangay'] ?? '', $record['categoryLabel'], $record['commodityName'], $record['commodityGroup'],
            rtrim(rtrim(number_format($record['quantity'], 3, '.', ''), '0'), '.'), $record['unitName'], $record['quantityKg'] === null ? '' : number_format($record['quantityKg'], 2, '.', ''),
            $record['harvestDate'], substr($record['submittedAt'], 0, 16), $record['status'], $record['counted'] ? 'Yes' : 'No', $record['remarks'] ?? '']);
    }
    $row([]);
    $row(['Totals (counted records only)']);
    $row(['Kilograms (kg and MT records)', number_format($summary['totalKg'], 2, '.', ''), 'kg']);
    foreach ($summary['otherTotals'] as $total) $row([$total['unitName'] . ' (not added to kilograms)', rtrim(rtrim(number_format($total['total'], 3, '.', ''), '0'), '.'), $total['unitSymbol']]);
    $row(['Matching records', (string) $summary['records']]);
    $row(['Reporting farmers', (string) $summary['farmers']]);
}
