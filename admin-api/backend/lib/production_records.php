<?php
declare(strict_types=1);

// Farmer production (harvest) records: commodity catalog, submission validation, calendar-month arithmetic and totals.
// Totals are always calculated from production_records.harvest_date at read time (nothing is pre-aggregated),
// so a record counts once, in the month it was harvested. The filters (farmer, commodity, farm) are the same
// ones a future admin production report needs; the farmer API always passes its own farmer_id.
//
// Units: each record keeps its quantity in the unit the farmer chose. Only weights (kg, MT) convert to kilograms
// (quantity_kg); head, pieces, trays and liters have no kilogram value and are totalled separately per unit,
// never added to kilogram totals or to each other.

require_once __DIR__ . '/damage_report_codes.php';

const PRODUCTION_MAX_QUANTITY = 1000000;
const PRODUCTION_MAX_QUANTITY_KG = 1000000;
const PRODUCTION_REMARKS_MAX_LENGTH = 1000;
const PRODUCTION_EARLIEST_HARVEST_DATE = '2000-01-01';
/** Statuses that count toward totals. Rejected records stay visible to the farmer but are never added. */
const PRODUCTION_COUNTED_STATUSES = ['Submitted', 'Verified'];
/** Production categories in form order (codes are the farm/commodity agricultural_category values). */
const PRODUCTION_CATEGORIES = ['Crops' => 'Crops', 'Livestock' => 'Livestock', 'Poultry' => 'Poultry', 'Fishpond' => 'Aquaculture / Fishpond'];

function production_timezone(): DateTimeZone
{
    return new DateTimeZone('Asia/Manila');
}

function production_today(): DateTimeImmutable
{
    return new DateTimeImmutable('today', production_timezone());
}

/** First day of the month for "YYYY-MM", or null when the value is not a supported month. */
function production_parse_month(string $value): ?DateTimeImmutable
{
    if (!preg_match('/^(\d{4})-(\d{2})$/', $value, $matches)) return null;
    $year = (int) $matches[1];
    $month = (int) $matches[2];
    if ($year < 2000 || $month < 1 || $month > 12) return null;
    return new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month), production_timezone());
}

function production_month_start(DateTimeImmutable $date): DateTimeImmutable
{
    return $date->setTimezone(production_timezone())->modify('first day of this month')->setTime(0, 0);
}

/** The month before $monthStart; January 2027 gives December 2026. */
function production_previous_month(DateTimeImmutable $monthStart): DateTimeImmutable
{
    return $monthStart->modify('first day of previous month');
}

function production_positive_int(mixed $value): ?int
{
    if (is_int($value)) return $value >= 1 && $value <= 2147483647 ? $value : null;
    if (is_string($value) && preg_match('/^[1-9]\d{0,9}$/', $value) && (int) $value <= 2147483647) return (int) $value;
    return null;
}

function production_category_label(string $category): string
{
    return PRODUCTION_CATEGORIES[$category] ?? $category;
}

/** An exact decimal string ("125.5") with at most $decimals decimals and 0 < value <= the maximum, or null. */
function production_quantity(mixed $value, int $decimals = 2): ?string
{
    if (is_int($value) || is_float($value)) $value = (string) $value;
    if (!is_string($value)) return null;
    $value = trim($value);
    $pattern = $decimals > 0 ? '/^\d{1,7}(?:\.\d{1,' . $decimals . '})?$/' : '/^\d{1,7}$/';
    if (!preg_match($pattern, $value)) return null;
    $quantity = (float) $value;
    return $quantity > 0 && $quantity <= PRODUCTION_MAX_QUANTITY ? $value : null;
}

/** Hundredths of a kilogram, so kilogram sums and percentages never accumulate float error. */
function production_centikilograms(mixed $value): int
{
    return (int) round(((float) $value) * 100);
}

/** Thousandths of a unit, for exact sums of quantities in any unit. */
function production_milliunits(mixed $value): int
{
    return (int) round(((float) $value) * 1000);
}

/** @return array{0:string,1:list<int>} SQL condition on alias "pr" and its parameters. */
function production_filter_sql(array $filters): array
{
    $conditions = ['pr.is_fixture = 0'];
    $params = [];
    foreach (['farmer_id', 'commodity_id', 'farm_profile_id'] as $column) {
        if (!array_key_exists($column, $filters)) continue;
        $conditions[] = 'pr.' . $column . ' = ?';
        $params[] = (int) $filters[$column];
    }
    return [implode(' AND ', $conditions), $params];
}

function production_counted_sql(): string
{
    return "pr.status IN ('" . implode("', '", PRODUCTION_COUNTED_STATUSES) . "')";
}

/** The persisted report ID column, or NULL until migration 0010 has added it (rows then fall back to "PR-" + id). */
function production_report_code_sql(PDO $connection): string
{
    return production_report_codes_ready($connection) ? 'pr.report_code' : 'NULL';
}

/** "PR-000123": the ID of a production record that has no RSBSA report ID (older records, farmers without a reference). */
function production_legacy_code(int $id): string
{
    return 'PR-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT);
}

function production_record_select_sql(PDO $connection): string
{
    return 'SELECT pr.id, ' . production_report_code_sql($connection) . ' AS report_code, pr.farmer_id, pr.farm_profile_id, fp.farm_name, fp.barangay_name, pr.commodity_id,
                   c.name AS commodity_name, c.agricultural_category, c.commodity_group, pr.harvest_date, pr.quantity, pr.unit_code,
                   mu.name AS unit_name, mu.symbol AS unit_symbol, pr.quantity_kg, pr.remarks, pr.status, pr.created_at, pr.updated_at
            FROM production_records pr
            JOIN commodities c ON c.id = pr.commodity_id
            JOIN measurement_units mu ON mu.code = pr.unit_code
            LEFT JOIN farm_profiles fp ON fp.id = pr.farm_profile_id AND fp.farmer_id = pr.farmer_id';
}

function production_record_row(array $row): array
{
    $remarks = trim((string) ($row['remarks'] ?? ''));
    return [
        'id' => (string) $row['id'],
        // The persisted report ID (RSBSA reference + shared sequence, or "PR-" + id for older records).
        'reportCode' => ($row['report_code'] ?? null) ?: production_legacy_code((int) $row['id']),
        'commodityId' => (int) $row['commodity_id'],
        'commodityName' => (string) $row['commodity_name'],
        'category' => (string) $row['agricultural_category'],
        'categoryLabel' => production_category_label((string) $row['agricultural_category']),
        'commodityGroup' => (string) $row['commodity_group'],
        'farmId' => $row['farm_profile_id'] === null ? null : (int) $row['farm_profile_id'],
        'farmName' => $row['farm_name'] === null ? null : (string) $row['farm_name'],
        'farmBarangay' => ($row['barangay_name'] ?? null) ?: null,
        'harvestDate' => substr((string) $row['harvest_date'], 0, 10),
        'quantity' => production_milliunits($row['quantity']) / 1000,
        'unitCode' => (string) $row['unit_code'],
        'unitName' => (string) $row['unit_name'],
        'unitSymbol' => (string) $row['unit_symbol'],
        // Kilogram equivalent for kg and MT; null for head, pieces, trays and liters.
        'quantityKg' => $row['quantity_kg'] === null ? null : production_centikilograms($row['quantity_kg']) / 100,
        'remarks' => $remarks === '' ? null : $remarks,
        'status' => (string) $row['status'],
        'counted' => in_array((string) $row['status'], PRODUCTION_COUNTED_STATUSES, true),
        'submittedAt' => (string) $row['created_at'],
        'updatedAt' => (string) $row['updated_at'],
    ];
}

/** @return list<array> Records harvested in [$from, $until), newest harvest first. */
function production_records(PDO $connection, array $filters, ?DateTimeImmutable $from = null, ?DateTimeImmutable $until = null, ?int $limit = null): array
{
    [$where, $params] = production_filter_sql($filters);
    if ($from) { $where .= ' AND pr.harvest_date >= ?'; $params[] = $from->format('Y-m-d'); }
    if ($until) { $where .= ' AND pr.harvest_date < ?'; $params[] = $until->format('Y-m-d'); }
    $sql = production_record_select_sql($connection) . ' WHERE ' . $where . ' ORDER BY pr.harvest_date DESC, pr.id DESC';
    if ($limit !== null) $sql .= ' LIMIT ' . max(1, $limit);
    $statement = $connection->prepare($sql);
    $statement->execute($params);
    return array_map('production_record_row', $statement->fetchAll());
}

function production_find_record(PDO $connection, array $filters, int $id): ?array
{
    [$where, $params] = production_filter_sql($filters);
    $statement = $connection->prepare(production_record_select_sql($connection) . ' WHERE ' . $where . ' AND pr.id = ? LIMIT 1');
    $statement->execute([...$params, $id]);
    $row = $statement->fetch();
    return $row ? production_record_row($row) : null;
}

/**
 * Totals in units without a kilogram value (head, pieces, trays, liters): one row per commodity and unit, plus one
 * total per unit. Never converted or added to kilograms.
 * @param list<array> $rows rows with id, name, agricultural_category, unit_code, unit_symbol, unit_name, total_quantity, record_count
 */
function production_other_measures(array $rows): array
{
    $measures = [];
    $totals = [];
    foreach ($rows as $row) {
        $quantity = production_milliunits($row['total_quantity']);
        $measures[] = [
            'commodityId' => (int) $row['id'], 'name' => (string) $row['name'], 'category' => (string) $row['agricultural_category'],
            'unitCode' => (string) $row['unit_code'], 'unitSymbol' => (string) $row['unit_symbol'],
            'total' => $quantity / 1000, 'recordCount' => (int) $row['record_count'],
        ];
        $unit = (string) $row['unit_code'];
        $totals[$unit] ??= ['unitCode' => $unit, 'unitName' => (string) $row['unit_name'], 'unitSymbol' => (string) $row['unit_symbol'], 'total' => 0, 'recordCount' => 0, 'sort' => (int) $row['sort_order']];
        $totals[$unit]['total'] += $quantity;
        $totals[$unit]['recordCount'] += (int) $row['record_count'];
    }
    usort($totals, static fn(array $a, array $b): int => $a['sort'] <=> $b['sort']);
    return [$measures, array_map(static function (array $total): array {
        $total['total'] /= 1000;
        unset($total['sort']);
        return $total;
    }, $totals)];
}

/**
 * Monthly Total Production (kg) = sum of the kilogram equivalents (kg and MT records) harvested in that calendar month.
 * Crop Percentage = crop kg / monthly total kg x 100, one decimal. An empty month has total 0 and no crops.
 * Records in head, pieces, trays or liters are listed separately in otherMeasures/otherTotals.
 */
function production_month_summary(PDO $connection, array $filters, DateTimeImmutable $monthStart): array
{
    [$where, $params] = production_filter_sql($filters);
    $next = $monthStart->modify('first day of next month');
    $range = ' AND ' . production_counted_sql() . ' AND pr.harvest_date >= ? AND pr.harvest_date < ?';
    $rangeParams = [...$params, $monthStart->format('Y-m-d'), $next->format('Y-m-d')];
    $statement = $connection->prepare(
        'SELECT c.id, c.name, c.agricultural_category, SUM(pr.quantity_kg) AS total_kg, COUNT(*) AS record_count
         FROM production_records pr
         JOIN commodities c ON c.id = pr.commodity_id
         WHERE ' . $where . $range . ' AND pr.quantity_kg IS NOT NULL
         GROUP BY c.id, c.name, c.agricultural_category
         ORDER BY total_kg DESC, c.name ASC'
    );
    $statement->execute($rangeParams);
    $rows = $statement->fetchAll();

    $total = 0;
    $count = 0;
    foreach ($rows as $row) {
        $total += production_centikilograms($row['total_kg']);
        $count += (int) $row['record_count'];
    }
    $crops = [];
    foreach ($rows as $row) {
        $kilograms = production_centikilograms($row['total_kg']);
        $crops[] = [
            'commodityId' => (int) $row['id'],
            'name' => (string) $row['name'],
            'category' => (string) $row['agricultural_category'],
            'totalKg' => $kilograms / 100,
            'recordCount' => (int) $row['record_count'],
            'percent' => $total > 0 ? round($kilograms / $total * 100, 1) : 0.0,
        ];
    }

    $statement = $connection->prepare(
        'SELECT c.id, c.name, c.agricultural_category, pr.unit_code, mu.symbol AS unit_symbol, mu.name AS unit_name, mu.sort_order,
                SUM(pr.quantity) AS total_quantity, COUNT(*) AS record_count
         FROM production_records pr
         JOIN commodities c ON c.id = pr.commodity_id
         JOIN measurement_units mu ON mu.code = pr.unit_code
         WHERE ' . $where . $range . ' AND pr.quantity_kg IS NULL
         GROUP BY c.id, c.name, c.agricultural_category, pr.unit_code, mu.symbol, mu.name, mu.sort_order
         ORDER BY mu.sort_order ASC, total_quantity DESC, c.name ASC'
    );
    $statement->execute($rangeParams);
    $otherRows = $statement->fetchAll();
    [$otherMeasures, $otherTotals] = production_other_measures($otherRows);
    foreach ($otherMeasures as $measure) $count += $measure['recordCount'];

    return [
        'month' => $monthStart->format('Y-m'),
        'label' => $monthStart->format('F Y'),
        'totalKg' => $total / 100,
        'recordCount' => $count,
        'crops' => $crops,
        'otherMeasures' => $otherMeasures,
        'otherTotals' => $otherTotals,
    ];
}

/** Twelve calendar months of counted totals for $year (months without records are 0). */
function production_year_totals(PDO $connection, array $filters, int $year): array
{
    [$where, $params] = production_filter_sql($filters);
    $start = new DateTimeImmutable(sprintf('%04d-01-01', $year), production_timezone());
    $rangeParams = [...$params, $start->format('Y-m-d'), $start->modify('+1 year')->format('Y-m-d')];
    $statement = $connection->prepare(
        'SELECT EXTRACT(MONTH FROM pr.harvest_date) AS month_number, pr.unit_code, mu.symbol AS unit_symbol, mu.name AS unit_name, mu.sort_order,
                SUM(pr.quantity_kg) AS total_kg, SUM(pr.quantity) AS total_quantity, COUNT(*) AS record_count
         FROM production_records pr
         JOIN measurement_units mu ON mu.code = pr.unit_code
         WHERE ' . $where . ' AND ' . production_counted_sql() . ' AND pr.harvest_date >= ? AND pr.harvest_date < ?
         GROUP BY EXTRACT(MONTH FROM pr.harvest_date), pr.unit_code, mu.symbol, mu.name, mu.sort_order
         ORDER BY mu.sort_order ASC'
    );
    $statement->execute($rangeParams);
    $byMonth = [];
    foreach ($statement->fetchAll() as $row) $byMonth[(int) $row['month_number']][] = $row;

    $currentMonth = production_month_start(production_today());
    $months = [];
    $total = 0;
    $count = 0;
    $yearOther = [];
    for ($month = 1; $month <= 12; $month++) {
        $monthStart = $start->setDate($year, $month, 1);
        $kilograms = 0;
        $records = 0;
        $other = [];
        foreach ($byMonth[$month] ?? [] as $row) {
            $records += (int) $row['record_count'];
            // kg and MT rows carry a kilogram equivalent; the others are kept per unit.
            if ($row['total_kg'] !== null) { $kilograms += production_centikilograms($row['total_kg']); continue; }
            $other[] = ['unitCode' => (string) $row['unit_code'], 'unitName' => (string) $row['unit_name'], 'unitSymbol' => (string) $row['unit_symbol'],
                'total' => production_milliunits($row['total_quantity']) / 1000, 'recordCount' => (int) $row['record_count']];
            $yearOther[(string) $row['unit_code']] ??= ['unitCode' => (string) $row['unit_code'], 'unitName' => (string) $row['unit_name'], 'unitSymbol' => (string) $row['unit_symbol'], 'total' => 0, 'recordCount' => 0];
            $yearOther[(string) $row['unit_code']]['total'] += production_milliunits($row['total_quantity']);
            $yearOther[(string) $row['unit_code']]['recordCount'] += (int) $row['record_count'];
        }
        $total += $kilograms;
        $count += $records;
        $months[] = [
            'month' => $monthStart->format('Y-m'),
            'label' => $monthStart->format('F Y'),
            'shortLabel' => $monthStart->format('M'),
            'totalKg' => $kilograms / 100,
            'recordCount' => $records,
            'otherTotals' => $other,
            'isFuture' => $monthStart > $currentMonth,
        ];
    }
    $otherTotals = array_values(array_map(static function (array $item): array { $item['total'] /= 1000; return $item; }, $yearOther));
    return ['year' => $year, 'totalKg' => $total / 100, 'recordCount' => $count, 'otherTotals' => $otherTotals, 'months' => $months];
}

/** @return list<int> Years that have records, plus the current year, newest first. */
function production_years(PDO $connection, array $filters): array
{
    [$where, $params] = production_filter_sql($filters);
    $statement = $connection->prepare('SELECT DISTINCT EXTRACT(YEAR FROM pr.harvest_date) AS harvest_year FROM production_records pr WHERE ' . $where);
    $statement->execute($params);
    $years = array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN));
    $years[] = (int) production_today()->format('Y');
    $years = array_values(array_unique($years));
    rsort($years);
    return $years;
}

/** @return list<array{code:string,name:string,symbol:string,measure:string,kgPerUnit:?float,decimals:int}> */
function production_units(PDO $connection): array
{
    return array_map(static fn(array $row): array => [
        'code' => (string) $row['code'], 'name' => (string) $row['name'], 'symbol' => (string) $row['symbol'], 'measure' => (string) $row['measure'],
        'kgPerUnit' => $row['kg_per_unit'] === null ? null : (float) $row['kg_per_unit'], 'decimals' => (int) $row['decimals'],
    ], $connection->query('SELECT code, name, symbol, measure, kg_per_unit, decimals FROM measurement_units ORDER BY sort_order ASC')->fetchAll());
}

/** Active commodities with their group, search names and allowed units (default unit first). */
function production_active_commodities(PDO $connection): array
{
    $units = [];
    foreach ($connection->query("SELECT cu.commodity_id, cu.unit_code FROM commodity_units cu JOIN measurement_units mu ON mu.code = cu.unit_code ORDER BY mu.sort_order ASC")->fetchAll() as $row) {
        $units[(int) $row['commodity_id']][] = (string) $row['unit_code'];
    }
    $statement = $connection->query("SELECT id, name, agricultural_category, commodity_group, product_type, default_unit_code, search_names
        FROM commodities WHERE status = 'Active' ORDER BY agricultural_category ASC, commodity_group ASC, name ASC");
    $commodities = [];
    foreach ($statement->fetchAll() as $row) {
        $default = (string) $row['default_unit_code'];
        $allowed = $units[(int) $row['id']] ?? [];
        if (!in_array($default, $allowed, true)) continue; // misconfigured catalog row: never offer a unit the API would reject
        $commodities[] = [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'category' => (string) $row['agricultural_category'],
            'group' => (string) $row['commodity_group'],
            'productType' => (string) $row['product_type'],
            'searchNames' => (string) $row['search_names'],
            'defaultUnit' => $default,
            'units' => array_values(array_unique([$default, ...$allowed])),
        ];
    }
    return $commodities;
}

/** Everything the submission form needs: categories (form order), units and the active commodity catalog. */
function production_catalog(PDO $connection): array
{
    $categories = [];
    foreach (PRODUCTION_CATEGORIES as $code => $label) $categories[] = ['code' => $code, 'label' => $label];
    return ['categories' => $categories, 'units' => production_units($connection), 'commodities' => production_active_commodities($connection)];
}

/**
 * Server-side validation of a farmer's submission. The farmer comes from the session, never the body.
 * Order and rules follow the form: farm (owned, active) -> category -> commodity of that category -> date ->
 * quantity in a unit the commodity allows. Older app builds that send only quantityKg are read as kilograms.
 * @return array{0:array<string,string>,1:array<string,mixed>} field errors, cleaned values
 */
function production_validate_submission(PDO $connection, int $farmerId, array $body): array
{
    $errors = [];
    $clean = [];

    $submissionId = $body['clientSubmissionId'] ?? null;
    if (!is_string($submissionId) || !preg_match('/^[A-Za-z0-9_-]{16,64}$/', $submissionId)) {
        $errors['form'] = 'The submission identifier is invalid. Please reopen the form.';
    } else {
        $clean['clientSubmissionId'] = $submissionId;
    }

    $farmId = production_positive_int($body['farmId'] ?? null);
    if ($farmId === null) {
        $errors['farmId'] = 'Select the farm area where this was produced.';
    } else {
        // Ownership: only the signed-in farmer's own active farms are accepted.
        $statement = $connection->prepare("SELECT id, farm_name, agricultural_category FROM farm_profiles WHERE id = ? AND farmer_id = ? AND status = 'Active' LIMIT 1");
        $statement->execute([$farmId, $farmerId]);
        $farm = $statement->fetch();
        if (!$farm) $errors['farmId'] = 'Select one of your active registered farm areas.';
        else $clean['farm'] = $farm;
    }

    $legacy = !array_key_exists('quantity', $body) && array_key_exists('quantityKg', $body);
    $category = $body['category'] ?? null;
    if ($category !== null && (!is_string($category) || !array_key_exists($category, PRODUCTION_CATEGORIES))) {
        $errors['category'] = 'Select a production category from the list.';
    } elseif ($category === null && !$legacy) {
        $errors['category'] = 'Select the production category.';
    }

    $commodityId = production_positive_int($body['commodityId'] ?? null);
    if ($commodityId === null) {
        $errors['commodityId'] = 'Select the commodity you produced.';
    } else {
        $statement = $connection->prepare("SELECT id, name, agricultural_category FROM commodities WHERE id = ? AND status = 'Active' LIMIT 1");
        $statement->execute([$commodityId]);
        $commodity = $statement->fetch();
        if (!$commodity) $errors['commodityId'] = 'Select a commodity from the list.';
        elseif (is_string($category) && !isset($errors['category']) && $commodity['agricultural_category'] !== $category) {
            $errors['commodityId'] = 'The selected commodity does not belong to the ' . production_category_label($category) . ' category.';
        } else $clean['commodity'] = $commodity;
    }

    $harvestDate = $body['harvestDate'] ?? null;
    $harvestDate = is_string($harvestDate) ? trim($harvestDate) : '';
    $parsedDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $harvestDate) ? DateTimeImmutable::createFromFormat('!Y-m-d', $harvestDate, production_timezone()) : false;
    if ($harvestDate === '') $errors['harvestDate'] = 'Select the date harvested or produced.';
    elseif (!$parsedDate || $parsedDate->format('Y-m-d') !== $harvestDate) $errors['harvestDate'] = 'Select a valid date.';
    elseif ($harvestDate > production_today()->format('Y-m-d')) $errors['harvestDate'] = 'The date cannot be in the future.';
    elseif ($harvestDate < PRODUCTION_EARLIEST_HARVEST_DATE) $errors['harvestDate'] = 'Select a date from the year 2000 onward.';
    else $clean['harvestDate'] = $harvestDate;

    $unitCode = $legacy ? 'kg' : ($body['unitCode'] ?? null);
    $unit = null;
    if (!is_string($unitCode) || $unitCode === '') {
        $errors['unitCode'] = 'Select the unit of measurement.';
    } elseif (isset($clean['commodity'])) {
        $statement = $connection->prepare('SELECT mu.code, mu.name, mu.symbol, mu.kg_per_unit, mu.decimals FROM commodity_units cu JOIN measurement_units mu ON mu.code = cu.unit_code WHERE cu.commodity_id = ? AND cu.unit_code = ? LIMIT 1');
        $statement->execute([(int) $clean['commodity']['id'], $unitCode]);
        $unit = $statement->fetch() ?: null;
        if (!$unit) $errors['unitCode'] = 'This unit cannot be used for ' . $clean['commodity']['name'] . '.';
        else $clean['unit'] = $unit;
    }

    $rawQuantity = $legacy ? $body['quantityKg'] : ($body['quantity'] ?? null);
    $decimals = $unit ? (int) $unit['decimals'] : 2;
    $quantity = production_quantity($rawQuantity, $decimals);
    if ($rawQuantity === null || (is_string($rawQuantity) && trim($rawQuantity) === '')) $errors['quantity'] = 'Enter the quantity produced.';
    elseif ($quantity === null) {
        $errors['quantity'] = is_numeric($rawQuantity) && (float) $rawQuantity > PRODUCTION_MAX_QUANTITY
            ? 'A single record cannot exceed 1,000,000.'
            : ($decimals === 0 ? 'Enter a whole number greater than 0.' : 'Enter a number greater than 0 with up to ' . $decimals . ' decimal places.');
    } elseif ($unit) {
        $clean['quantity'] = $quantity;
        // Kilogram equivalent only for weights (kg, MT): exact, since MT has 3 decimals.
        $clean['quantityKg'] = $unit['kg_per_unit'] === null ? null : number_format(production_milliunits($quantity) * (float) $unit['kg_per_unit'] / 1000, 2, '.', '');
        if ($clean['quantityKg'] !== null && (float) $clean['quantityKg'] > PRODUCTION_MAX_QUANTITY_KG) $errors['quantity'] = 'A single record cannot exceed 1,000,000 kg (1,000 MT).';
    }
    if ($legacy && isset($errors['quantity'])) $errors['quantityKg'] = $errors['quantity'];

    $remarks = $body['remarks'] ?? '';
    if ($remarks === null) $remarks = '';
    if (!is_string($remarks) || !mb_check_encoding($remarks, 'UTF-8')) {
        $errors['remarks'] = 'The remarks could not be read. Please type them again.';
    } else {
        $remarks = trim(str_replace(["\r\n", "\r"], "\n", $remarks));
        if (mb_strlen($remarks) > PRODUCTION_REMARKS_MAX_LENGTH) $errors['remarks'] = 'Remarks must be 1,000 characters or fewer.';
        elseif (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $remarks)) $errors['remarks'] = 'Remarks contain unsupported characters.';
        else $clean['remarks'] = $remarks === '' ? null : $remarks;
    }

    return [$errors, $clean];
}

function production_find_by_submission(PDO $connection, int $farmerId, string $clientSubmissionId): ?array
{
    $statement = $connection->prepare(production_record_select_sql($connection) . ' WHERE pr.farmer_id = ? AND pr.client_submission_id = ? LIMIT 1');
    $statement->execute([$farmerId, $clientSubmissionId]);
    $row = $statement->fetch();
    return $row ? production_record_row($row) : null;
}

/** An already-counted record with the same farm, commodity, date, quantity and unit (a likely accidental repeat). */
function production_find_same_record(PDO $connection, int $farmerId, array $clean): ?array
{
    $statement = $connection->prepare(production_record_select_sql($connection) . ' WHERE pr.farmer_id = ? AND pr.farm_profile_id = ? AND pr.commodity_id = ?
        AND pr.harvest_date = ? AND pr.quantity = ? AND pr.unit_code = ? AND pr.is_fixture = 0 AND ' . production_counted_sql() . ' ORDER BY pr.id ASC LIMIT 1');
    $statement->execute([$farmerId, (int) $clean['farm']['id'], (int) $clean['commodity']['id'], $clean['harvestDate'], $clean['quantity'], (string) $clean['unit']['code']]);
    $row = $statement->fetch();
    return $row ? production_record_row($row) : null;
}

/** "1,234.5 kg", "120 pcs": a quantity with its unit symbol, without trailing zeros. */
function production_format_quantity(float $quantity, string $symbol): string
{
    $text = rtrim(rtrim(number_format($quantity, 3, '.', ','), '0'), '.');
    return $text . ' ' . $symbol;
}

/**
 * Inserts one validated record with its report ID and returns it. The ID's number comes from the farmer's sequence
 * shared with damage reports, taken in the same transaction as the insert, so a failed or duplicate insert never
 * uses up a number. A concurrent retry of the same form raises a duplicate-key error (and rolls back).
 */
function production_insert_record(PDO $connection, int $farmerId, array $clean): array
{
    $values = [$farmerId, (int) $clean['farm']['id'], (int) $clean['commodity']['id'], $clean['harvestDate'], $clean['quantity'],
        (string) $clean['unit']['code'], $clean['quantityKg'], $clean['remarks'], $clean['clientSubmissionId']];
    if (!production_report_codes_ready($connection)) {
        // Database without migration 0010: save as before; the record is shown as "PR-" + id.
        $connection->prepare('INSERT INTO production_records (farmer_id, farm_profile_id, commodity_id, harvest_date, quantity, unit_code, quantity_kg, remarks, client_submission_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute($values);
        $id = db_insert_id($connection, 'production_records');
    } else {
        $connection->beginTransaction();
        try {
            ['code' => $code, 'sequence' => $sequence] = farmer_report_allocate_code($connection, $farmerId);
            $connection->prepare('INSERT INTO production_records (report_code, report_sequence, farmer_id, farm_profile_id, commodity_id, harvest_date, quantity, unit_code, quantity_kg, remarks, client_submission_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([$code, $sequence, ...$values]);
            $id = db_insert_id($connection, 'production_records');
            // No usable RSBSA reference: the previous ID format, which needs the new record's id.
            if ($code === null) $connection->prepare('UPDATE production_records SET report_code = ? WHERE id = ?')->execute([production_legacy_code($id), $id]);
            $connection->commit();
        } catch (Throwable $exception) {
            if ($connection->inTransaction()) $connection->rollBack();
            throw $exception;
        }
    }
    $record = production_find_record($connection, ['farmer_id' => $farmerId], $id);
    if (!$record) throw new RuntimeException('The saved production record could not be read back.');
    return $record;
}
