<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/../../backend/lib/admin_dashboard.php';
require_admin();
$period = ($_GET['period'] ?? '') === 'days' ? 'days' : 'months';
$data = null; $dashboard_error = '';
try { $data = admin_dashboard_data(db(), $period); }
catch (Throwable $exception) { error_log('Dashboard query failed: ' . get_class($exception)); $dashboard_error = 'Live dashboard data is unavailable. Check the database connection; no replacement values are shown.'; }
// Production loads on its own: a failure shows an error in the production cards only, never a misleading 0 kg.
$production_month = admin_dashboard_production_month($_GET['production_month'] ?? null);
$production = null; $production_error = '';
if ($data !== null) {
    try { $production = admin_dashboard_production(db(), $production_month); }
    catch (Throwable $exception) { error_log('Dashboard production query failed: ' . get_class($exception)); $production_error = 'Production data could not be loaded, so no production figures are shown. Use Refresh data to try again.'; }
}
$production_label = $production_month->format('F Y');
$production_all_url = 'production-report.php?' . http_build_query(['period' => 'custom', 'date_from' => $production_month->format('Y-m-d'), 'date_to' => $production_month->modify('last day of this month')->format('Y-m-d')], '', '&', PHP_QUERY_RFC3986);
$page_title = 'Dashboard'; $active_page = 'dashboard';
require_once __DIR__ . '/includes/header.php';
?>
<div class="container-fluid py-4">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div><p class="eyebrow mb-1">Municipal agriculture · Live records</p><h1 class="page-title mb-1">Cabadbaran City Overview</h1><p class="text-muted mb-0">Applications, reported damage and published agricultural advice.</p></div>
    <button class="btn btn-outline-success" type="button" id="refreshDashboard"><i class="bi bi-arrow-clockwise" aria-hidden="true"></i> Refresh data</button>
  </div>
  <?php if ($dashboard_error): ?><div class="alert alert-warning" role="alert"><?= e($dashboard_error) ?></div><?php endif; ?>
  <?php if ($data !== null): ?>
  <div class="row g-3 mb-4">
    <?php foreach ([['Approved Farmers',$data['approved'],'All approved accounts','farmers','bi-people'],['Pending Verification',$data['pending'],'Awaiting a decision','pending','bi-person-check'],['New Damage Reports',$data['received'],'Received · fixtures excluded','reports','bi-clipboard2-pulse'],['Active Advisories',count($data['advisories']),'Currently effective in Cabadbaran','advisories','bi-megaphone']] as $card): ?>
    <div class="col-6 col-xl-3"><div class="stat-card"><div class="stat-icon <?= e($card[3]) ?>"><i class="bi <?= e($card[4]) ?>" aria-hidden="true"></i></div><div><p class="stat-label"><?= e($card[0]) ?></p><h2><?= number_format($card[1]) ?></h2><p class="stat-note"><?= e($card[2]) ?></p></div></div></div>
    <?php endforeach; ?>
  </div>
  <div class="dashboard-grid">
    <section class="content-card">
      <div class="card-heading"><div><h3>Damage report activity</h3><p>Non-fixture submissions · resolved is the current status of each submission.</p></div><form method="get"><label class="visually-hidden" for="trendPeriod">Trend period</label><select class="form-select form-select-sm" name="period" id="trendPeriod" onchange="this.form.submit()"><option value="months"<?= $period === 'months' ? ' selected' : '' ?>>Last 6 months</option><option value="days"<?= $period === 'days' ? ' selected' : '' ?>>Last 30 days</option></select></form></div>
      <?php $maximum = max(1, ...array_column($data['trend'], 'total')); $axisMax = max(4, (int) ceil($maximum / 4) * 4); $points = []; $resolvedPoints = []; $n = count($data['trend']); ?>
      <svg class="trend-svg" viewBox="0 0 680 280" role="img" aria-labelledby="trendTitle trendDescription"><title id="trendTitle">Actual damage report counts</title><desc id="trendDescription"><?php foreach ($data['trend'] as $m): ?><?= e($m['label']) ?>: <?= $m['total'] ?> reports, <?= $m['resolved'] ?> resolved. <?php endforeach; ?></desc>
      <?php for ($i=0; $i<=4; $i++): $y = 230 - $i*48; ?><line class="trend-grid" x1="42" y1="<?= $y ?>" x2="655" y2="<?= $y ?>"/><text x="30" y="<?= $y+4 ?>" text-anchor="end"><?= (int) ($axisMax*$i/4) ?></text><?php endfor; ?>
      <?php foreach ($data['trend'] as $i => $m): $x = 50 + $i * 600 / max(1,$n-1); $y = 230-$m['total']/$axisMax*192; $points[] = $x . ',' . $y; $resolvedPoints[] = $x . ',' . (230-$m['resolved']/$axisMax*192); ?>
      <circle cx="<?= $x ?>" cy="<?= $y ?>" r="4" fill="#174d36"><title><?= e($m['label']) ?>: <?= $m['total'] ?> reports</title></circle>
      <?php if ($period !== 'days' || $i % 6 === 0 || $i === $n-1): ?><text x="<?= $x ?>" y="256" text-anchor="middle"><?= e($m['label']) ?></text><?php endif; ?>
      <?php endforeach; ?><polyline class="trend-line" points="<?= e(implode(' ', $points)) ?>"/><polyline points="<?= e(implode(' ', $resolvedPoints)) ?>" fill="none" stroke="#739575" stroke-width="2" stroke-dasharray="5 5"/>
      </svg><div class="chart-legend"><span><i class="legend-dot green"></i>Total submissions</span><span><i class="legend-dot light"></i>Currently resolved</span></div>
    </section>
    <section class="content-card"><div class="card-heading"><h3>Needs attention</h3></div>
      <div class="work-item pending"><header><a href="farmer-verification.php?status=Pending">Pending farmer applications</a><span class="badge-soft warning"><?= $data['pending'] ?></span></header>
      <?php if (!$data['applications']): ?><p>No pending applications.</p><?php endif; ?><?php foreach ($data['applications'] as $a): ?><p><strong><?= e($a['full_name']) ?></strong> · <?= e(date('M j, Y',strtotime($a['date_registered']))) ?><br><?= e($a['address']) ?></p><?php endforeach; ?></div>
      <div class="work-item"><header><a href="damage-reports.php?status=Received">Unreviewed damage reports</a><span class="badge-soft info"><?= $data['received'] ?></span></header>
      <?php if (!$data['work_reports']): ?><p>No received reports awaiting review.</p><?php endif; ?><?php foreach ($data['work_reports'] as $r): ?><p><a href="damage-reports.php?search=<?= urlencode($r['report_code']) ?>"><?= e($r['report_code']) ?></a> · <?= e($r['damage_type']) ?></p><?php endforeach; ?></div>
      <p class="dashboard-data-note">Counts follow stored account/report status. The map below is separately limited to valid points inside the mapped city boundary.</p>
    </section>
  </div>
  <div class="dashboard-grid dashboard-production">
    <section class="content-card" aria-labelledby="productionCategoryTitle">
      <div class="card-heading"><div><h3 id="productionCategoryTitle">Production by Category for <?= e($production_label) ?></h3><p>Kilograms reported for the month harvested or produced · Submitted and verified reports.</p></div></div>
      <?php if ($production_error !== ''): ?><div class="alert alert-warning mb-0" role="alert"><?= e($production_error) ?></div>
      <?php elseif ($production['reports'] === 0): ?><p class="dashboard-empty" role="status">No production reports for <?= e($production_label) ?>.</p>
      <?php else: $axis = admin_dashboard_axis_max(max(array_column($production['categories'], 'centikilograms')) / 100); $rows = count($production['categories']); $base = 14 + $rows * 38; ?>
      <div class="production-bars-scroll"><svg class="production-bars" viewBox="0 0 680 <?= $base + 34 ?>" role="img" aria-labelledby="productionBarsTitle productionBarsDescription"><title id="productionBarsTitle">Kilograms produced by category, <?= e($production_label) ?></title><desc id="productionBarsDescription"><?php foreach ($production['categories'] as $category): ?><?= e($category['code']) ?>: <?= e(admin_dashboard_kg($category['centikilograms'])) ?>. <?php endforeach; ?></desc>
        <?php for ($i = 0; $i <= 4; $i++): $x = 96 + $i * 125; ?><line class="trend-grid" x1="<?= $x ?>" y1="10" x2="<?= $x ?>" y2="<?= $base ?>"/><text x="<?= $x ?>" y="<?= $base + 22 ?>" text-anchor="middle"><?= e(admin_dashboard_kg((int) round($axis * $i / 4 * 100))) ?></text><?php endfor; ?>
        <line class="production-axis" x1="96" y1="<?= $base ?>" x2="596" y2="<?= $base ?>"/>
        <?php foreach ($production['categories'] as $i => $category): $y = 16 + $i * 38; $width = $category['centikilograms'] / 100 / $axis * 500; ?>
        <text class="production-bar-label" x="86" y="<?= $y + 16 ?>" text-anchor="end"><?= e($category['code']) ?></text>
        <rect class="production-bar" x="96" y="<?= $y ?>" width="<?= round($width, 2) ?>" height="22" rx="2"><title><?= e($category['code']) ?>: <?= e(admin_dashboard_kg($category['centikilograms'])) ?> from <?= $category['reports'] ?> report<?= $category['reports'] === 1 ? '' : 's' ?></title></rect>
        <text class="production-bar-value" x="<?= round(104 + $width, 2) ?>" y="<?= $y + 16 ?>"><?= e(admin_dashboard_kg($category['centikilograms'])) ?></text>
        <?php endforeach; ?>
      </svg></div>
      <?php if ($production['otherUnitReports'] > 0): ?><p class="dashboard-data-note"><?= $production['otherUnitReports'] ?> <?= $production['otherUnitReports'] === 1 ? 'report is' : 'reports are' ?> in head, pieces, trays or liters: counted in Reports, not added to kilograms.</p><?php endif; ?>
      <?php endif; ?>
    </section>
    <section class="content-card" aria-labelledby="productionSummaryTitle">
      <div class="card-heading"><h3 id="productionSummaryTitle"><?= e($production_label) ?> production</h3><a href="<?= e($production_all_url) ?>">View all</a></div>
      <?php if ($production_error !== ''): ?><div class="alert alert-warning mb-0" role="alert"><?= e($production_error) ?></div>
      <?php else: ?>
      <dl class="production-summary">
        <div class="total"><dt><i class="bi bi-flower1" aria-hidden="true"></i> Total produced</dt><dd><?= e(admin_dashboard_kg($production['centikilograms'])) ?></dd></div>
        <div><dt><i class="bi bi-clipboard-data" aria-hidden="true"></i> Reports</dt><dd><?= number_format($production['reports']) ?></dd></div>
        <div><dt><i class="bi bi-people" aria-hidden="true"></i> Farmers</dt><dd><?= number_format($production['farmers']) ?></dd></div>
      </dl>
      <?php if ($production['reports'] === 0): ?><p class="dashboard-data-note mb-0">Nothing was reported for <?= e($production_label) ?> yet.</p><?php endif; ?>
      <?php endif; ?>
    </section>
  </div>
  <div class="dashboard-lower has-production">
    <section class="content-card"><div class="card-heading"><h3>Latest damage reports</h3><a href="damage-reports.php">View all</a></div><div class="table-responsive"><table class="table mb-0"><thead><tr><th>Farmer / Report</th><th>Category</th><th>Status</th><th>Submitted</th></tr></thead><tbody>
    <?php if (!$data['recent']): ?><tr><td colspan="4">No non-fixture damage reports.</td></tr><?php endif; ?>
    <?php foreach ($data['recent'] as $r): ?><tr><td><?= e($r['full_name']) ?><br><a href="damage-reports.php?search=<?= urlencode($r['report_code']) ?>"><?= e($r['report_code']) ?></a></td><td><?= e($r['agricultural_category']) ?></td><td><span class="report-status <?= e(strtolower(str_replace(' ','-',$r['status']))) ?>"><?= e($r['status']) ?></span></td><td><?= e(date('M j, Y',strtotime($r['submitted_at']))) ?></td></tr><?php endforeach; ?></tbody></table></div></section>
    <section class="content-card"><div class="card-heading"><h3>Latest production reports</h3><a href="production-report.php">View all</a></div>
    <?php if ($production_error !== ''): ?><div class="alert alert-warning mb-0" role="alert"><?= e($production_error) ?></div>
    <?php else: ?><div class="table-responsive"><table class="table mb-0"><thead><tr><th>Farmer / Report</th><th>Category</th><th>Commodity</th><th>Produced</th><th>Month</th></tr></thead><tbody>
    <?php if (!$production['latest']): ?><tr><td colspan="5">No production reports yet.</td></tr><?php endif; ?>
    <?php foreach ($production['latest'] as $r): ?><tr><td><?= e($r['farmer']) ?><br><a href="production-report.php?<?= e(http_build_query(['period' => 'custom', 'date_from' => $r['from'], 'date_to' => $r['to'], 'record' => $r['id']], '', '&', PHP_QUERY_RFC3986)) ?>"><?= e($r['code']) ?></a></td><td><?= e($r['category']) ?></td><td><?= e($r['commodity']) ?></td><td class="text-nowrap"><?= e($r['produced']) ?><?= $r['inKilograms'] ? '' : '<small class="d-block text-muted">not in kg</small>' ?></td><td class="text-nowrap"><?= e($r['monthLabel']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
    </section>
    <section class="content-card"><div class="card-heading"><h3>Active advice</h3><a href="weather-advisory.php">View all</a></div>
    <?php if (!$data['advisories']): ?><p class="dashboard-data-note">No currently effective Cabadbaran advisories.</p><?php endif; ?>
    <?php foreach (array_slice($data['advisories'],0,3) as $a): ?><article class="dashboard-advisory"><h4><a href="weather-advisory.php?search=<?= urlencode($a['title']) ?>"><?= e($a['title']) ?></a></h4><p><?= e($a['agricultural_category']) ?> · <?= e($a['weather_condition']) ?></p><p><?= e($a['location_coverage']) ?></p><p><?= e($a['effective_date']) ?> — <?= e($a['expiration_date'] ?: 'Until further notice') ?></p></article><?php endforeach; ?>
    </section>
    <section class="content-card"><div class="card-heading"><h3>City activity map</h3></div><div id="dashboardMap" class="dashboard-map" aria-label="Saved report locations within Cabadbaran"></div><p id="dashboardMapStatus" class="dashboard-data-note" role="status"></p><p class="dashboard-data-note"><?= count($data['map']) ?> saved report points inside the approximate city boundary. Not proof of photo authenticity or a farm boundary.<br>Excluded: <?= $data['excluded']['fixtures'] ?> fixtures; <?= $data['excluded']['missing'] ?> missing/invalid points; <?= $data['excluded']['outside'] ?> outside city; <?= $data['excluded']['unchecked'] ?> boundary check unavailable. Records are retained.</p></section>
  </div>
  <script type="application/json" id="dashboardMapData"><?= json_encode($data['map'],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
  <?php endif; ?>
</div>
<script src="assets/js/admin-dashboard.js"></script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
