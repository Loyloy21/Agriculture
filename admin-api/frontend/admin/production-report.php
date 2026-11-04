<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/../../backend/lib/admin_production.php';
require_once __DIR__ . '/includes/pagination.php';
require_admin();

// Read-only report over the production records farmers submit from the mobile app (same table, same rules).
$filters = admin_production_filters_from_input($_GET);
$notice = '';
$load_error = '';
$summary = null;
$period = null;
$records = [];
$commodity_options = [];
$labels = ['farmer' => '', 'farm' => '', 'commodity' => ''];
$page_state = admin_page_state(0, 1, ADMIN_PRODUCTION_PAGE_SIZE);
$newer_notice = null;
try {
    $connection = db();
    [$filters, $cleared] = admin_production_normalize_filters($connection, $filters);
    if ($cleared) $notice = 'The selected commodity does not belong to the chosen category, so the commodity filter was cleared.';
    $commodity_options = admin_production_commodity_options($connection);
    $labels = admin_production_selected_labels($connection, $filters);
    $period = admin_production_period($filters);
    $summary = admin_production_summary($connection, $filters, $period);
    $page_state = admin_page_state($summary['records'], admin_requested_page($_GET['page'] ?? null), ADMIN_PRODUCTION_PAGE_SIZE);
    admin_redirect_clamped_page('production-report.php', admin_production_query($filters), $page_state);
    $records = admin_production_records($connection, $filters, $period, $page_state['per_page'], $page_state['offset']);
    // A dashboard link to one exact record: say so, and open its details (production-report.js) when it is listed.
    if ($filters['record'] !== '') $notice = $records
        ? 'Showing one production report: ' . $records[0]['code'] . '.'
        : 'That production report is not in the selected period or status.';
    // When an earlier period is selected, newer harvests (this month) are outside it: say so.
    $current_filters = array_merge($filters, ['period' => 'current_month', 'date_from' => '', 'date_to' => '']);
    $current_period = admin_production_period($current_filters);
    if ($period['until'] <= $current_period['from']) {
        $current_count = admin_production_count($connection, $current_filters, $current_period);
        if ($current_count > 0) $newer_notice = ['count' => $current_count, 'range' => $current_period['range'], 'url' => 'production-report.php?' . http_build_query(admin_production_query($current_filters), '', '&', PHP_QUERY_RFC3986)];
    }
} catch (InvalidArgumentException $exception) {
    $load_error = $exception->getMessage();
} catch (Throwable $exception) {
    error_log('Production report query failed: ' . $exception->getMessage());
    $load_error = 'Production records are unavailable. Check the database connection; no replacement values are shown.';
}

$query = admin_production_query($filters);
$export_url = 'production-report-export.php' . ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
$kg = static fn(float $value): string => production_format_quantity(round($value, 2), 'kg');
$other_line = static fn(array $totals): string => implode(' · ', array_map(static fn(array $total): string => production_format_quantity($total['total'], $total['unitSymbol']), $totals));
$category_icons = ['Crops' => 'bi-tree', 'Livestock' => 'bi-piggy-bank', 'Poultry' => 'bi-egg', 'Fishpond' => 'bi-water'];
$sort_header = static function (string $key, string $label) use ($filters): string {
    $active = $filters['sort'] === $key;
    $direction = $active && $filters['dir'] === 'desc' ? 'asc' : 'desc';
    $url = 'production-report.php?' . http_build_query(admin_production_query($filters, ['sort' => $key, 'dir' => $direction]), '', '&', PHP_QUERY_RFC3986);
    $icon = $active ? ($filters['dir'] === 'asc' ? 'bi-sort-up' : 'bi-sort-down') : 'bi-arrow-down-up';
    return '<th scope="col" aria-sort="' . ($active ? ($filters['dir'] === 'asc' ? 'ascending' : 'descending') : 'none') . '"><a class="production-sort' . ($active ? ' active' : '') . '" href="' . e($url) . '">'
        . e($label) . ' <i class="bi ' . $icon . '" aria-hidden="true"></i></a></th>';
};

$page_title = 'Production Report';
$active_page = 'production-report';
$page_css = 'assets/css/production-report.css';
require_once __DIR__ . '/includes/header.php';
?>
<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div><p class="eyebrow mb-1">Agricultural monitoring</p><h1 class="page-title mb-1">Production Report</h1><p class="text-muted mb-0">Production records submitted by farmers through the AgriReach mobile app.</p></div>
        <div class="d-flex flex-wrap gap-2">
            <button class="btn btn-outline-success" type="button" id="refreshProductionReport"><i class="bi bi-arrow-clockwise" aria-hidden="true"></i> Refresh data</button>
            <a class="btn btn-success<?= $summary === null || $summary['records'] === 0 ? ' disabled' : '' ?>" href="<?= e($export_url) ?>"<?= $summary === null || $summary['records'] === 0 ? ' aria-disabled="true" tabindex="-1"' : '' ?>><i class="bi bi-filetype-csv" aria-hidden="true"></i> Export CSV</a>
        </div>
    </div>
    <?php if ($notice !== ''): ?><div class="alert alert-info" role="status"><?= e($notice) ?><?php if ($filters['record'] !== ''): ?> <a class="alert-link" href="production-report.php?<?= e(http_build_query(admin_production_query($filters, ['record' => '']), '', '&', PHP_QUERY_RFC3986)) ?>">Show all reports in this period</a><?php endif; ?></div><?php endif; ?>
    <?php if ($newer_notice !== null): ?><div class="alert alert-success d-flex flex-wrap align-items-center justify-content-between gap-2" role="status"><span><i class="bi bi-info-circle" aria-hidden="true"></i> <strong><?= number_format($newer_notice['count']) ?> production record<?= $newer_notice['count'] === 1 ? '' : 's' ?></strong> harvested in <?= e($newer_notice['range']) ?> (current month) <?= $newer_notice['count'] === 1 ? 'is' : 'are' ?> not in the selected reporting period.</span><a class="btn btn-success btn-sm" href="<?= e($newer_notice['url']) ?>"><i class="bi bi-calendar-check" aria-hidden="true"></i> View current month</a></div><?php endif; ?>
    <?php if ($load_error !== ''): ?><div class="alert alert-warning" role="alert"><?= e($load_error) ?> <a class="alert-link" href="production-report.php">Reset filters</a></div><?php endif; ?>

    <section class="content-card mb-4" aria-labelledby="productionFiltersTitle">
        <h2 class="visually-hidden" id="productionFiltersTitle">Filters</h2>
        <form class="production-filters" method="get" id="productionFilters" aria-label="Filter production records">
            <div class="production-filter-search"><label class="form-label small fw-semibold" for="search">Search</label><div class="input-group"><span class="input-group-text" aria-hidden="true"><i class="bi bi-search"></i></span><input class="form-control" id="search" name="search" value="<?= e($filters['search']) ?>" maxlength="100" placeholder="Farmer, farm area, commodity (English or Filipino) or record ID"></div></div>
            <div><label class="form-label small fw-semibold" for="period">Reporting period</label><select class="form-select" id="period" name="period"><?php foreach (ADMIN_PRODUCTION_PERIODS as $value => $label): ?><option value="<?= e($value) ?>"<?= $filters['period'] === $value ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
            <div class="production-custom-range" id="customRange"<?= $filters['period'] === 'custom' ? '' : ' hidden' ?>>
                <div><label class="form-label small fw-semibold" for="date_from">From</label><input class="form-control" type="date" id="date_from" name="date_from" value="<?= e($filters['date_from']) ?>"<?= $filters['period'] === 'custom' ? '' : ' disabled' ?>></div>
                <div><label class="form-label small fw-semibold" for="date_to">To</label><input class="form-control" type="date" id="date_to" name="date_to" value="<?= e($filters['date_to']) ?>"<?= $filters['period'] === 'custom' ? '' : ' disabled' ?>></div>
            </div>
            <div><label class="form-label small fw-semibold" for="category">Production category</label><select class="form-select" id="category" name="category"><option value="">All categories</option><?php foreach (PRODUCTION_CATEGORIES as $value => $label): ?><option value="<?= e($value) ?>"<?= $filters['category'] === $value ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
            <div class="production-combobox" data-combobox="commodity">
                <label class="form-label small fw-semibold" for="commodityFilter">Commodity</label>
                <div class="production-combobox-field"><input class="form-control" type="text" id="commodityFilter" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="commodityFilterList" autocomplete="off" value="<?= e($labels['commodity']) ?>" placeholder="All commodities" data-selected-label="<?= e($labels['commodity']) ?>"><button class="production-combobox-clear" type="button" aria-label="Clear commodity filter"<?= $filters['commodity_id'] === '' ? ' hidden' : '' ?>><i class="bi bi-x-lg" aria-hidden="true"></i></button></div>
                <input type="hidden" name="commodity_id" value="<?= e($filters['commodity_id']) ?>">
                <ul class="production-combobox-list" id="commodityFilterList" role="listbox" aria-label="Commodities" hidden></ul>
            </div>
            <div class="production-combobox" data-combobox="farm">
                <label class="form-label small fw-semibold" for="farmFilter">Farm area</label>
                <div class="production-combobox-field"><input class="form-control" type="text" id="farmFilter" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="farmFilterList" autocomplete="off" value="<?= e($labels['farm']) ?>" placeholder="All farm areas" data-selected-label="<?= e($labels['farm']) ?>"><button class="production-combobox-clear" type="button" aria-label="Clear farm area filter"<?= $filters['farm_id'] === '' ? ' hidden' : '' ?>><i class="bi bi-x-lg" aria-hidden="true"></i></button></div>
                <input type="hidden" name="farm_id" value="<?= e($filters['farm_id']) ?>">
                <ul class="production-combobox-list" id="farmFilterList" role="listbox" aria-label="Farm areas" hidden></ul>
            </div>
            <div class="production-combobox" data-combobox="farmer">
                <label class="form-label small fw-semibold" for="farmerFilter">Farmer</label>
                <div class="production-combobox-field"><input class="form-control" type="text" id="farmerFilter" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="farmerFilterList" autocomplete="off" value="<?= e($labels['farmer']) ?>" placeholder="All farmers" data-selected-label="<?= e($labels['farmer']) ?>"><button class="production-combobox-clear" type="button" aria-label="Clear farmer filter"<?= $filters['farmer_id'] === '' ? ' hidden' : '' ?>><i class="bi bi-x-lg" aria-hidden="true"></i></button></div>
                <input type="hidden" name="farmer_id" value="<?= e($filters['farmer_id']) ?>">
                <ul class="production-combobox-list" id="farmerFilterList" role="listbox" aria-label="Farmers" hidden></ul>
            </div>
            <div><label class="form-label small fw-semibold" for="status">Record status</label><select class="form-select" id="status" name="status"><?php foreach (ADMIN_PRODUCTION_STATUSES as $value => $label): ?><option value="<?= e($value) ?>"<?= $filters['status'] === $value ? ' selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></div>
            <input type="hidden" name="sort" value="<?= e($filters['sort']) ?>"><input type="hidden" name="dir" value="<?= e($filters['dir']) ?>">
            <div class="production-filter-actions"><button class="btn btn-success" type="submit"><i class="bi bi-funnel" aria-hidden="true"></i> Apply filters</button><a class="btn btn-light" href="production-report.php"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Reset filters</a></div>
        </form>
        <?php if ($period !== null): ?><p class="production-period-note mb-0"><i class="bi bi-calendar3" aria-hidden="true"></i> <strong><?= e($period['label']) ?>:</strong> <?= e($period['range']) ?> · by harvest/production date (Asia/Manila)<?php if ($filters['status'] !== 'counted'): ?> · status: <?= e(ADMIN_PRODUCTION_STATUSES[$filters['status']]) ?><?php endif; ?></p><?php endif; ?>
    </section>

    <?php if ($summary !== null): ?>
    <div class="row g-3 mb-4" aria-label="Production summary">
        <div class="col-6 col-xl-3"><div class="stat-card"><div class="stat-icon"><i class="bi bi-bar-chart-line" aria-hidden="true"></i></div><div><p class="stat-label">Total Production</p><h2><?= e($kg($summary['totalKg'])) ?></h2><p class="stat-note"><?= number_format($summary['kgRecords']) ?> kg/MT record<?= $summary['kgRecords'] === 1 ? '' : 's' ?> · <?= e($period['range']) ?></p><?php if ($summary['otherTotals']): ?><p class="stat-note production-other-note">Also <?= e($other_line($summary['otherTotals'])) ?> (not in kg)</p><?php endif; ?></div></div></div>
        <div class="col-6 col-xl-3"><div class="stat-card"><div class="stat-icon"><i class="bi bi-people" aria-hidden="true"></i></div><div><p class="stat-label">Reporting Farmers</p><h2><?= number_format($summary['farmers']) ?></h2><p class="stat-note">Unique farmers with matching records · <?= number_format($summary['farms']) ?> farm area<?= $summary['farms'] === 1 ? '' : 's' ?></p></div></div></div>
        <div class="col-6 col-xl-3"><div class="stat-card"><div class="stat-icon pending"><i class="bi bi-journal-text" aria-hidden="true"></i></div><div><p class="stat-label">Production Records</p><h2><?= number_format($summary['records']) ?></h2><p class="stat-note"><?= $filters['status'] === 'counted' ? 'Submitted and verified records' : number_format($summary['countedRecords']) . ' counted in totals' ?> · <?= number_format($summary['commodities']) ?> commodit<?= $summary['commodities'] === 1 ? 'y' : 'ies' ?></p></div></div></div>
        <div class="col-6 col-xl-3"><div class="stat-card"><div class="stat-icon"><i class="bi bi-grid-3x2-gap" aria-hidden="true"></i></div><div><p class="stat-label">Production Categories</p><h2><?= number_format($summary['categories']) ?> <small class="production-stat-of">of 4</small></h2><p class="stat-note"><?= e(implode(' · ', array_map(static fn(array $c): string => $c['label'], array_filter($summary['categoryBreakdown'], static fn(array $c): bool => $c['records'] > 0))) ?: 'No category has records') ?></p></div></div></div>
    </div>

    <section class="content-card mb-4" aria-labelledby="categoryBreakdownTitle">
        <div class="card-heading"><h2 id="categoryBreakdownTitle">Production by category</h2><p class="mb-0">Kilograms add kg and MT records only. Head, pieces, trays and liters are shown separately and never added to kilograms.</p></div>
        <div class="production-category-grid">
            <?php foreach ($summary['categoryBreakdown'] as $category): ?>
            <article class="production-category-card<?= $category['records'] === 0 ? ' empty' : '' ?>" aria-label="<?= e($category['label']) ?>">
                <header><span class="production-category-icon"><i class="bi <?= e($category_icons[$category['code']]) ?>" aria-hidden="true"></i></span><h3><?= e($category['label']) ?></h3><?php if ($filters['category'] === '' && $category['records'] > 0): ?><a class="production-category-link" href="production-report.php?<?= e(http_build_query(admin_production_query($filters, ['category' => $category['code'], 'commodity_id' => '']), '', '&', PHP_QUERY_RFC3986)) ?>">Filter</a><?php endif; ?></header>
                <p class="production-category-kg"><?= e($kg($category['totalKg'])) ?><?php if ($summary['totalKg'] > 0): ?> <small><?= e((string) $category['percent']) ?>% of kg</small><?php endif; ?></p>
                <?php if ($category['otherTotals']): ?><p class="production-other-note">+ <?= e($other_line($category['otherTotals'])) ?></p><?php endif; ?>
                <p class="production-category-meta"><?= number_format($category['records']) ?> record<?= $category['records'] === 1 ? '' : 's' ?> · <?= number_format($category['farmers']) ?> farmer<?= $category['farmers'] === 1 ? '' : 's' ?></p>
                <?php if ($category['commodities'] || $category['otherCommodities']): ?><ul class="production-commodity-list">
                    <?php foreach (array_slice($category['commodities'], 0, 5) as $item): ?><li><span><?= e($item['name']) ?></span><strong><?= e($kg($item['totalKg'])) ?></strong></li><?php endforeach; ?>
                    <?php foreach (array_slice($category['otherCommodities'], 0, 4) as $item): ?><li><span><?= e($item['name']) ?></span><strong><?= e(production_format_quantity($item['total'], $item['unitSymbol'])) ?></strong></li><?php endforeach; ?>
                </ul><?php else: ?><p class="production-empty-note">No production records in this period.</p><?php endif; ?>
            </article>
            <?php endforeach; ?>
        </div>
    </section>

    <div class="production-chart-grid mb-4">
        <section class="content-card" aria-labelledby="trendTitle">
            <?php
            $trend = $summary['trend']; $maxKg = max(0, ...array_column($trend, 'totalKg')); $count = count($trend); $slot = 600 / max(1, $count);
            // Round axis steps (1, 2, 2.5 or 5 x 10^n) so gridlines read 0, 500, 1,000, ...
            $rawStep = max($maxKg, 0.01) / 4; $magnitude = 10 ** floor(log10($rawStep)); $step = $magnitude * 10;
            foreach ([1, 2, 2.5, 5, 10] as $factor) { if ($factor * $magnitude >= $rawStep) { $step = $factor * $magnitude; break; } }
            $divisions = max(1, (int) ceil($maxKg / $step)); $axisMax = $step * $divisions;
            ?>
            <div class="card-heading"><h2 id="trendTitle">Monthly production trend (kg)</h2><p class="mb-0"><?= e($trend[0]['label']) ?> – <?= e($trend[$count - 1]['label']) ?> · darker bars are inside the selected period</p></div>
            <?php if ($maxKg <= 0): ?><p class="production-empty-note">No kilogram production for these filters in this window.</p><?php else: ?>
            <svg class="production-trend-svg" viewBox="0 0 680 260" role="img" aria-labelledby="trendSvgTitle trendSvgDesc"><title id="trendSvgTitle">Monthly production in kilograms</title><desc id="trendSvgDesc"><?php foreach ($trend as $month): ?><?= e($month['label']) ?>: <?= e($kg($month['totalKg'])) ?>. <?php endforeach; ?></desc>
                <?php for ($i = 0; $i <= $divisions; $i++): $y = round(210 - $i * 180 / $divisions, 1); ?><line class="trend-grid" x1="62" y1="<?= $y ?>" x2="670" y2="<?= $y ?>"/><text x="56" y="<?= $y + 4 ?>" text-anchor="end"><?= e(rtrim(rtrim(number_format($step * $i, 2), '0'), '.')) ?></text><?php endfor; ?>
                <?php foreach ($trend as $i => $month): $height = $month['totalKg'] / $axisMax * 180; $x = 66 + $i * $slot + $slot * 0.18; $width = $slot * 0.64; ?>
                <rect class="production-bar<?= $month['inPeriod'] ? ' in-period' : '' ?>" x="<?= round($x, 1) ?>" y="<?= round(210 - $height, 1) ?>" width="<?= round($width, 1) ?>" height="<?= round(max($height, $month['totalKg'] > 0 ? 2 : 0), 1) ?>" rx="3"><title><?= e($month['label']) ?>: <?= e($kg($month['totalKg'])) ?><?= $month['otherTotals'] ? ' + ' . e($other_line($month['otherTotals'])) : '' ?></title></rect>
                <?php if ($count <= 12 || $i % 2 === 0 || $i === $count - 1): ?><text x="<?= round($x + $width / 2, 1) ?>" y="232" text-anchor="middle"><?= e($month['shortLabel']) ?></text><?php endif; ?>
                <?php endforeach; ?>
            </svg>
            <?php endif; ?>
            <?php $otherMonths = array_filter($trend, static fn(array $m): bool => (bool) $m['otherTotals']); if ($otherMonths): ?>
            <details class="production-other-months"><summary>Other units by month (not in the kg chart)</summary><ul><?php foreach ($otherMonths as $month): ?><li><strong><?= e($month['label']) ?>:</strong> <?= e($other_line($month['otherTotals'])) ?></li><?php endforeach; ?></ul></details>
            <?php endif; ?>
        </section>
        <section class="content-card" aria-labelledby="categoryChartTitle">
            <div class="card-heading"><h2 id="categoryChartTitle">Kilograms by category</h2><p class="mb-0"><?= e($period['range']) ?></p></div>
            <?php if ($summary['totalKg'] <= 0): ?><p class="production-empty-note">No kilogram production for these filters.</p><?php else: ?>
            <div class="production-hbars"><?php foreach ($summary['categoryBreakdown'] as $category): ?><div class="production-hbar" role="img" aria-label="<?= e($category['label'] . ': ' . $kg($category['totalKg']) . ', ' . $category['percent'] . '%') ?>"><span class="production-hbar-label"><?= e($category['label']) ?></span><span class="production-hbar-track"><span style="width: <?= e((string) max($category['percent'], $category['totalKg'] > 0 ? 1 : 0)) ?>%"></span></span><span class="production-hbar-value"><?= e($kg($category['totalKg'])) ?></span></div><?php endforeach; ?></div>
            <?php endif; ?>
        </section>
        <section class="content-card" aria-labelledby="commodityChartTitle">
            <div class="card-heading"><h2 id="commodityChartTitle">Top commodities (kg)</h2><p class="mb-0">Share of the kilogram total</p></div>
            <?php if (!$summary['commodityTotals']): ?><p class="production-empty-note">No kilogram production for these filters.</p><?php else: ?>
            <div class="production-hbars"><?php foreach (array_slice($summary['commodityTotals'], 0, 10) as $item): ?><div class="production-hbar" role="img" aria-label="<?= e($item['name'] . ': ' . $kg($item['totalKg']) . ', ' . $item['percent'] . '%') ?>"><span class="production-hbar-label" title="<?= e($item['name']) ?>"><?= e($item['name']) ?></span><span class="production-hbar-track"><span style="width: <?= e((string) max($item['percent'], 1)) ?>%"></span></span><span class="production-hbar-value"><?= e($kg($item['totalKg'])) ?> <small><?= e((string) $item['percent']) ?>%</small></span></div><?php endforeach; ?></div>
            <?php endif; ?>
        </section>
        <section class="content-card" aria-labelledby="otherUnitsTitle">
            <div class="card-heading"><h2 id="otherUnitsTitle">Other units</h2><p class="mb-0">Head, pieces, trays and liters, totalled per unit</p></div>
            <?php if (!$summary['otherCommodityTotals']): ?><p class="production-empty-note">No production recorded in other units for these filters.</p><?php else: ?>
            <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th scope="col">Commodity</th><th scope="col">Category</th><th scope="col" class="text-end">Total</th><th scope="col" class="text-end">Records</th></tr></thead><tbody>
                <?php foreach ($summary['otherCommodityTotals'] as $item): ?><tr><td><?= e($item['name']) ?></td><td><?= e(production_category_label($item['category'])) ?></td><td class="text-end"><?= e(production_format_quantity($item['total'], $item['unitSymbol'])) ?></td><td class="text-end"><?= number_format($item['recordCount']) ?></td></tr><?php endforeach; ?>
            </tbody></table></div>
            <?php endif; ?>
        </section>
    </div>
    <?php endif; ?>

    <section class="content-card" aria-labelledby="productionRecordsTitle">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><div><h2 class="section-title mb-1" id="productionRecordsTitle">Production records</h2><p class="text-muted small mb-0"><?= $page_state['total'] === 0 ? 'No records found' : 'Showing ' . $page_state['from'] . '–' . $page_state['to'] . ' of ' . number_format($page_state['total']) . ' record' . ($page_state['total'] === 1 ? '' : 's') ?></p></div></div>
        <div class="table-responsive"><table class="table align-middle production-table mb-0"><thead><tr>
            <?= $sort_header('record', 'Record ID') ?><?= $sort_header('farmer', 'Farmer') ?><th scope="col">Farm area</th><?= $sort_header('category', 'Category') ?><?= $sort_header('commodity', 'Commodity') ?><?= $sort_header('quantity', 'Quantity') ?><th scope="col">Unit</th><?= $sort_header('harvest_date', 'Date harvested / produced') ?><?= $sort_header('submitted', 'Date submitted') ?><th scope="col">Status</th><th scope="col" class="text-end">Actions</th>
        </tr></thead><tbody>
        <?php if ($load_error !== ''): ?><tr><td colspan="11" class="text-center py-5 text-muted">Records could not be loaded. <a href="production-report.php">Reset filters</a> or try again.</td></tr>
        <?php elseif (!$records): ?><tr><td colspan="11" class="text-center py-5 text-muted">No production records match the selected filters and reporting period.<?php if ($filters['period'] === 'previous_month'): ?> Farmers' records for the current month appear under <a href="production-report.php?period=current_month">Current month</a>.<?php endif; ?></td></tr><?php endif; ?>
        <?php foreach ($records as $record): ?>
            <tr>
                <td><strong class="production-code"><?= e($record['code']) ?></strong></td>
                <td><?= e($record['farmerName']) ?></td>
                <td><?= e($record['farmName'] ?? 'Not recorded') ?><?php if ($record['farmBarangay']): ?><small class="d-block text-muted">Brgy. <?= e($record['farmBarangay']) ?></small><?php endif; ?><?php if ($record['farmArchived']): ?><small class="d-block text-muted">Archived farm</small><?php endif; ?></td>
                <td><?= e($record['categoryLabel']) ?></td>
                <td><?= e($record['commodityName']) ?><small class="d-block text-muted"><?= e($record['commodityGroup']) ?></small></td>
                <td class="production-quantity"><?= e($record['quantityText']) ?><?php if ($record['unitCode'] === 'mt'): ?><small class="d-block text-muted"><?= e($kg((float) $record['quantityKg'])) ?></small><?php endif; ?></td>
                <td class="production-unit"><?= e($record['unitName']) ?></td>
                <td><?= e(date('M j, Y', strtotime($record['harvestDate']))) ?></td>
                <td><?= e(date('M j, Y', strtotime($record['submittedAt']))) ?><small class="d-block text-muted"><?= e(date('g:i A', strtotime($record['submittedAt']))) ?></small></td>
                <td><span class="production-status <?= e(strtolower($record['status'])) ?>"><?= e($record['status']) ?></span><?php if (!$record['counted']): ?><small class="d-block text-muted">Not counted</small><?php endif; ?></td>
                <td class="text-end"><button class="btn btn-outline-success btn-sm view-production-btn" type="button" data-record-id="<?= (int) $record['id'] ?>" aria-label="View details of <?= e($record['code']) ?>"><i class="bi bi-eye" aria-hidden="true"></i> View</button><script type="application/json" id="production-record-<?= (int) $record['id'] ?>"><?= json_encode($record, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?></script></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div><?= admin_pagination_html($page_state, 'production-report.php', $query) ?>
    </section>
</div>

<div class="modal fade" id="productionDetailsModal" tabindex="-1" aria-labelledby="productionDetailsTitle" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
    <div class="modal-header"><div><h2 class="modal-title" id="productionDetailsTitle">Production record</h2><small class="text-muted" id="productionDetailsCode"></small></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body">
      <p class="text-muted small"><i class="bi bi-lock" aria-hidden="true"></i> Read-only: submitted by the farmer through the mobile app.</p>
      <section class="report-review-card"><h3><i class="bi bi-journal-text" aria-hidden="true"></i> Record</h3><dl class="production-detail-list" id="productionDetailsList"></dl></section>
      <section class="report-review-card mt-3"><h3><i class="bi bi-chat-left-text" aria-hidden="true"></i> Remarks / notes</h3><p class="report-description-text mb-0" id="productionDetailsRemarks"></p></section>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to list</button></div>
  </div></div>
</div>
<script type="application/json" id="productionCommodities"><?= json_encode($commodity_options, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
<?php if ($filters['record'] !== '' && $records): ?><script type="application/json" id="productionOpenRecord"><?= (int) $records[0]['id'] ?></script><?php endif; ?>
<script src="assets/js/production-report.js?v=20261103"></script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
