<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/../../backend/lib/weather_advisories.php';
require_admin();

$csrf_token = admin_csrf_token();
$message = '';
$message_type = 'success';
$categories = ['Crops', 'Livestock', 'Poultry', 'Fishpond'];
$conditions = ['Heavy Rain', 'Continuous Rainfall', 'High Temperature', 'Strong Wind', 'Normal Weather', 'Other'];
$statuses = ['Draft', 'Active', 'Archived'];
$barangays = cabadbaran_barangays();
$rule_recommendations = [
    'Heavy Rain' => 'Check drainage canals. Avoid fertilizer or pesticide application. Monitor possible flooding.',
    'High Temperature' => 'Provide enough water for crops and animals. Avoid field activities during peak heat. Monitor heat stress.',
    'Strong Wind' => 'Secure poultry housing. Secure livestock shelters. Secure farm tools and light structures.',
    'Continuous Rainfall' => 'Avoid planting in low-lying areas. Monitor crop or fishpond overflow risk.',
    'Normal Weather' => 'Continue regular farm activities. Continue monitoring weather updates.',
    'Other' => '',
];

function advisory_fail(string $text): never
{
    $_SESSION['advisory_error'] = $text;
    header('Location: weather-advisory.php');
    exit;
}

function validated_advisory_coverage(array $barangays): array
{
    $scope = (string) ($_POST['coverage_scope'] ?? '');
    if (!in_array($scope, ['City', 'Barangays'], true)) advisory_fail('Select a valid Cabadbaran coverage option.');
    $codes = array_values(array_unique(array_map('strval', (array) ($_POST['barangay_codes'] ?? []))));
    $names = [];
    foreach ($codes as $code) {
        if (!isset($barangays[$code])) advisory_fail('One or more selected barangays are outside Cabadbaran City.');
        $names[] = $barangays[$code];
    }
    if ($scope === 'Barangays' && !$codes) advisory_fail('Select at least one Cabadbaran barangay.');
    if ($scope === 'City') { $codes = []; $names = []; }
    return [$scope, $codes, $names, cabadbaran_coverage_label($scope, $names)];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf_token, (string) ($_POST['csrf_token'] ?? ''))) advisory_fail('Your session token is invalid. Please try again.');
    $action = (string) ($_POST['action'] ?? '');
    $advisory_id = filter_var($_POST['advisory_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    try {
        $connection = db();
        if ($action === 'review_location') {
            $farmProfileId = filter_var($_POST['farm_profile_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $choice = (string) ($_POST['barangay_code'] ?? '');
            if (!$farmProfileId) advisory_fail('Invalid farm profile.');
            weather_advisory_classify_farm_location($connection, (int) $farmProfileId, $choice);
            $_SESSION['advisory_flash'] = 'Farm location classification saved.';
            header('Location: weather-advisory.php#location-review'); exit;
        }

        if ($action === 'create' || $action === 'update') {
            $title = trim((string) ($_POST['title'] ?? ''));
            $category = (string) ($_POST['agricultural_category'] ?? '');
            $weatherCondition = (string) ($_POST['weather_condition'] ?? '');
            $advisoryMessage = trim((string) ($_POST['advisory_message'] ?? ''));
            $keyAdvice = trim((string) preg_replace('/\s+/u', ' ', (string) ($_POST['key_advice'] ?? '')));
            $effectiveDate = (string) ($_POST['effective_date'] ?? '');
            $expirationDate = trim((string) ($_POST['expiration_date'] ?? ''));
            $statusValue = (string) ($_POST['status'] ?? 'Draft');
            [$scope, $codes, $names, $coverageLabel] = validated_advisory_coverage($barangays);
            $effective = DateTimeImmutable::createFromFormat('!Y-m-d', $effectiveDate);
            $expiration = $expirationDate === '' ? null : DateTimeImmutable::createFromFormat('!Y-m-d', $expirationDate);
            $validDates = $effective && $effective->format('Y-m-d') === $effectiveDate && ($expirationDate === '' || ($expiration && $expiration->format('Y-m-d') === $expirationDate && $expiration >= $effective));
            if ($title === '' || mb_strlen($title) > 180 || !in_array($category, $categories, true) || !in_array($weatherCondition, $conditions, true) || $advisoryMessage === '' || mb_strlen($advisoryMessage) > 5000 || !$validDates || !in_array($statusValue, $statuses, true)) advisory_fail('Complete all advisory fields with valid dates and content.');
            if ($keyAdvice === '' || mb_strlen($keyAdvice) > WEATHER_ADVISORY_KEY_ADVICE_MAX) advisory_fail('Write the Key advice farmers see first, in one or two short sentences (up to ' . WEATHER_ADVISORY_KEY_ADVICE_MAX . ' characters).');
            if ($statusValue === 'Active' && $expirationDate !== '' && $expirationDate < date('Y-m-d')) advisory_fail('An expired advisory cannot be published. Update its expiration date or save it as archived.');
            if ($action === 'update' && $advisory_id === false) throw new RuntimeException('Invalid advisory record.');
            $saved = weather_advisory_save($connection, $action === 'create' ? null : (int) $advisory_id, [
                'title' => $title, 'category' => $category, 'weather_condition' => $weatherCondition, 'coverage_label' => $coverageLabel,
                'scope' => $scope, 'barangay_codes' => $codes, 'message' => $advisoryMessage, 'key_advice' => $keyAdvice, 'effective_date' => $effectiveDate,
                // A blank expiration is stored as NULL (PostgreSQL rejects '' for a DATE column).
                'expiration_date' => $expirationDate === '' ? null : $expirationDate, 'status' => $statusValue,
            ], (int) $_SESSION['admin_id']);
            $notified = $saved['notified'];
            $_SESSION['advisory_flash'] = ($action === 'create' ? 'Weather advisory created.' : 'Weather advisory updated.') . ($statusValue === 'Active' ? " {$notified} new eligible farmer notification(s) created." : ' Drafts do not notify farmers.');
            header('Location: weather-advisory.php'); exit;
        }

        if (in_array($action, ['publish', 'archive'], true) && $advisory_id !== false) {
            $newStatus = $action === 'publish' ? 'Active' : 'Archived';
            $notified = weather_advisory_set_status($connection, (int) $advisory_id, $newStatus, (int) $_SESSION['admin_id']);
            $_SESSION['advisory_flash'] = "Advisory status updated to {$newStatus}." . ($newStatus === 'Active' ? " {$notified} new eligible farmer notification(s) created." : '');
            header('Location: weather-advisory.php'); exit;
        }
        advisory_fail('Unsupported advisory action.');
    } catch (WeatherAdvisoryActionException $exception) {
        advisory_fail($exception->getMessage());
    } catch (Throwable $exception) {
        error_log('Weather advisory action failed: ' . $exception->getMessage());
        // Undefined column: the Key advice migration (0009) has not been applied to this database yet.
        if ($exception instanceof PDOException && in_array((string) $exception->getCode(), ['42703', '42S22'], true)) advisory_fail('Saving needs the Key advice database update (migration 20261024_0009_advisory_key_advice). Nothing was saved; ask the system administrator to apply it, then try again.');
        advisory_fail('The advisory action could not be completed. Check the database migration and try again.');
    }
}

if (!empty($_SESSION['advisory_flash'])) { $message = (string) $_SESSION['advisory_flash']; unset($_SESSION['advisory_flash']); }
if (!empty($_SESSION['advisory_error'])) { $message = (string) $_SESSION['advisory_error']; $message_type = 'danger'; unset($_SESSION['advisory_error']); }

$search = trim((string) ($_GET['search'] ?? ''));
$linked_advisory_id = filter_var($_GET['advisory_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$category_filter = (string) ($_GET['category'] ?? '');
$status_filter = (string) ($_GET['status'] ?? '');
$advisories = []; $reviewLocations = []; $load_error = '';
try {
    $connection = db();
    $advisories = weather_advisory_admin_list($connection, [
        'search' => $search, 'advisory_id' => $linked_advisory_id ?: null,
        'category' => in_array($category_filter, $categories, true) ? $category_filter : '',
        'status' => in_array($status_filter, $statuses, true) ? $status_filter : '',
    ]);
    $reviewLocations = weather_advisory_locations_needing_review($connection);
} catch (Throwable $exception) { error_log('Weather advisory page load failed: ' . $exception->getMessage()); $load_error = 'Advisory data is not available yet. Import the Cabadbaran weather advisory migration first.'; }

$page_title = 'Weather Advisory'; $active_page = 'weather-advisory'; $page_css = 'assets/css/weather-advisory.css';
require_once __DIR__ . '/includes/header.php';
?>
<div class="container-fluid py-4">
  <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4"><div><p class="eyebrow mb-1">Farmer guidance</p><h1 class="page-title mb-1">Weather Advisory</h1><p class="text-muted mb-0">Create and manage rule-based guidance for Cabadbaran City farmers.</p></div><button class="btn btn-success" type="button" data-bs-toggle="modal" data-bs-target="#advisoryModal" id="createAdvisoryButton"><i class="bi bi-plus-lg"></i> Create Advisory</button></div>
  <?php if ($message !== ''): ?><div class="alert alert-<?= e($message_type) ?> alert-dismissible fade show" role="alert"><?= e($message) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
  <?php if ($load_error !== ''): ?><div class="alert alert-warning"><?= e($load_error) ?></div><?php endif; ?>
  <div class="advisory-tabs mb-3"><a class="<?= $category_filter === '' ? 'active' : '' ?>" href="weather-advisory.php">All</a><?php foreach ($categories as $option): ?><a class="<?= $category_filter === $option ? 'active' : '' ?>" href="?category=<?= urlencode($option) ?>"><?= e($option) ?></a><?php endforeach; ?></div>
  <section class="content-card">
    <form class="row g-2 align-items-end mb-4" method="get"><div class="col-12 col-lg-6"><label class="form-label small fw-semibold" for="search">Search advisories</label><input class="form-control" id="search" name="search" value="<?= e($search) ?>" placeholder="Title, weather condition, or coverage"></div><div class="col-12 col-md-4 col-lg-3"><label class="form-label small fw-semibold" for="status">Status</label><select class="form-select" id="status" name="status"><option value="">All statuses</option><?php foreach ($statuses as $option): ?><option<?= $status_filter === $option ? ' selected' : '' ?>><?= e($option) ?></option><?php endforeach; ?></select></div><input type="hidden" name="category" value="<?= e($category_filter) ?>"><div class="col-12 col-md-2"><button class="btn btn-success w-100">Filter</button></div></form>
    <div class="d-flex justify-content-between align-items-center mb-3"><div><h2 class="section-title mb-1">Advisory list</h2><p class="text-muted small mb-0"><?= count($advisories) ?> <?= count($advisories) === 1 ? 'advisory' : 'advisories' ?> found</p></div></div>
    <div class="table-responsive"><table class="table align-middle advisory-table mb-0"><thead><tr><th>Advisory Title</th><th>Category</th><th>Weather Condition</th><th>Coverage</th><th>Eligible</th><th>Status</th><th>Date Created</th><th class="text-end">Action</th></tr></thead><tbody>
    <?php if (!$advisories): ?><tr><td colspan="8" class="text-center py-5 text-muted">No advisories match the selected filters.</td></tr><?php endif; ?>
    <?php foreach ($advisories as $advisory): $json = json_encode($advisory, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?><tr><td><strong><?= e((string) $advisory['title']) ?></strong><small class="d-block text-muted"><?= e((string) $advisory['effective_date']) ?></small></td><td><?= e((string) $advisory['agricultural_category']) ?></td><td><?= e((string) $advisory['weather_condition']) ?></td><td><?= e((string) $advisory['location_coverage']) ?></td><td><?= (int) $advisory['recipient_count'] ?></td><td><span class="advisory-status <?= strtolower((string) $advisory['status']) ?>"><?= e((string) $advisory['status']) ?></span></td><td><?= e(date('M d, Y', strtotime((string) $advisory['created_at']))) ?></td><td class="text-end advisory-actions"><button class="btn btn-outline-success btn-sm view-advisory-btn" type="button" data-id="<?= (int) $advisory['id'] ?>">View</button><button class="btn btn-outline-secondary btn-sm edit-advisory-btn" type="button" data-id="<?= (int) $advisory['id'] ?>">Edit</button><?php if ($advisory['status'] !== 'Active'): ?><form method="post" class="d-inline publish-form" data-coverage="<?= e((string) $advisory['location_coverage']) ?>" data-count="<?= (int) $advisory['recipient_count'] ?>"><input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>"><input type="hidden" name="action" value="publish"><input type="hidden" name="advisory_id" value="<?= (int) $advisory['id'] ?>"><button class="btn btn-light btn-sm">Activate</button></form><?php endif; ?><?php if ($advisory['status'] !== 'Archived'): ?><form method="post" class="d-inline"><input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>"><input type="hidden" name="action" value="archive"><input type="hidden" name="advisory_id" value="<?= (int) $advisory['id'] ?>"><button class="btn btn-outline-danger btn-sm">Archive</button></form><?php endif; ?><script type="application/json" id="advisory-data-<?= (int) $advisory['id'] ?>"><?= $json ?></script></td></tr><?php endforeach; ?>
    </tbody></table></div>
  </section>
  <section class="content-card mt-4" id="location-review"><h2 class="section-title mb-1">Registration location review</h2><p class="text-muted small">Legacy free-text farm addresses are excluded from advisory delivery until their Cabadbaran barangay is confirmed.</p><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Farmer</th><th>Farm</th><th>Registered address</th><th>Classification</th></tr></thead><tbody><?php if (!$reviewLocations): ?><tr><td colspan="4" class="text-center py-4 text-muted">No farm locations require review.</td></tr><?php endif; ?><?php foreach ($reviewLocations as $farm): ?><tr><td><?= e((string) $farm['full_name']) ?><small class="d-block text-muted"><?= e((string) $farm['verification_status']) ?></small></td><td><?= e((string) $farm['farm_name']) ?> (<?= e((string) $farm['agricultural_category']) ?>)</td><td><?= e((string) $farm['farm_address']) ?></td><td><form method="post" class="d-flex gap-2"><input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>"><input type="hidden" name="action" value="review_location"><input type="hidden" name="farm_profile_id" value="<?= (int) $farm['id'] ?>"><select name="barangay_code" class="form-select form-select-sm" required><option value="">Choose Cabadbaran barangay</option><?php foreach ($barangays as $code => $name): ?><option value="<?= e((string) $code) ?>"><?= e($name) ?></option><?php endforeach; ?><option value="OUTSIDE">Outside Cabadbaran City</option></select><button class="btn btn-outline-success btn-sm">Save</button></form></td></tr><?php endforeach; ?></tbody></table></div></section>
  <section class="content-card mt-4" id="weather-rules">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-2"><div><h2 class="section-title mb-1">Automatic weather-based advice (rule-based)</h2><p class="text-muted small mb-0">Separate from the advisories above. Farmers see this as <strong>Weather-based advice</strong>, computed from real provider readings and the thresholds below. It is not AI, not a forecast, and never published or edited here. Admin advisories stay labelled <strong>Admin advisory</strong>.</p></div><button class="btn btn-outline-success btn-sm" type="button" id="loadWeatherRules">Show rules and current result</button></div>
    <div id="weatherRulesOutput" class="mt-3" aria-live="polite"></div>
  </section>
</div>

<div class="modal fade" id="advisoryModal" tabindex="-1" aria-labelledby="advisoryModalTitle" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content advisory-modal"><div class="modal-header"><div><p class="eyebrow mb-1">Admin-created rule-based guidance</p><h2 class="modal-title fs-5" id="advisoryModalTitle">Create Advisory</h2></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><form method="post" id="advisoryForm"><div class="modal-body" id="advisoryModalBody" tabindex="0"><input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>"><input type="hidden" name="action" id="advisoryAction" value="create"><input type="hidden" name="advisory_id" id="advisoryId"><div class="row g-3">
  <div class="col-12"><label class="form-label" for="advisoryTitle">Advisory Title <span class="required-mark">*</span></label><input class="form-control" id="advisoryTitle" name="title" required maxlength="180"></div>
  <div class="col-md-6"><label class="form-label" for="advisoryCategory">Agricultural Category <span class="required-mark">*</span></label><select class="form-select" id="advisoryCategory" name="agricultural_category" required><option value="">Select category</option><?php foreach ($categories as $option): ?><option><?= e($option) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-6"><label class="form-label" for="weatherCondition">Weather Condition <span class="required-mark">*</span></label><select class="form-select" id="weatherCondition" name="weather_condition" required><option value="">Select condition</option><?php foreach ($conditions as $option): ?><option><?= e($option) ?></option><?php endforeach; ?></select></div>
  <div class="col-12"><div class="rule-hint" id="ruleHint">Select a weather condition to see the prepared rule-based recommendation.</div></div>
  <div class="col-12"><label class="form-label">Location / Coverage</label><div class="fixed-city"><strong>Cabadbaran City, Agusan del Norte</strong><small>Advisories cannot target locations outside the city.</small></div></div>
  <div class="col-12 coverage-field" id="coverageField">
    <div class="coverage-options" role="radiogroup" aria-label="Advisory coverage">
      <label class="coverage-card"><input type="radio" name="coverage_scope" value="City" checked><span><strong>Entire Cabadbaran City</strong><small>Notify eligible farmers in every verified city barangay.</small></span></label>
      <label class="coverage-card"><input type="radio" name="coverage_scope" value="Barangays"><span><strong>Selected barangays</strong><small>Notify only eligible farmers in the barangays selected below.</small></span></label>
    </div>
    <div class="barangay-panel d-none" id="barangayPanel">
      <div class="barangay-panel-header"><strong>Select barangays</strong><span id="selectedBarangayCount" aria-live="polite">0 selected</span></div>
      <div class="barangay-grid" id="barangayGrid"><?php foreach ($barangays as $code => $name): ?><label class="barangay-option"><input type="checkbox" name="barangay_codes[]" value="<?= e((string) $code) ?>"><span><?= e($name) ?></span></label><?php endforeach; ?></div>
    </div>
    <div class="coverage-error" id="coverageError" role="alert"></div>
  </div>
  <div class="col-12"><div class="recipient-preview" id="recipientPreview">Select a category to preview eligible recipients.</div></div>
  <div class="col-12"><label class="form-label" for="advisoryKeyAdvice">Key advice <span class="required-mark">*</span></label><textarea class="form-control" id="advisoryKeyAdvice" name="key_advice" rows="2" maxlength="<?= WEATHER_ADVISORY_KEY_ADVICE_MAX ?>" required aria-describedby="keyAdviceHelp"></textarea><div class="form-text d-flex justify-content-between gap-2" id="keyAdviceHelp"><span>The main warning or recommendation in one or two short sentences. Farmers see this first on advisory cards and notifications; keep any critical instruction. The full message below stays available.</span><span id="keyAdviceCount" class="text-nowrap">0/<?= WEATHER_ADVISORY_KEY_ADVICE_MAX ?></span></div></div>
  <div class="col-12"><label class="form-label" for="advisoryMessage">Advisory Message / Recommendation <span class="required-mark">*</span></label><textarea class="form-control" id="advisoryMessage" name="advisory_message" rows="6" maxlength="5000" required></textarea></div>
  <div class="col-md-4"><label class="form-label" for="effectiveDate">Effective Date <span class="required-mark">*</span></label><input class="form-control" type="date" id="effectiveDate" name="effective_date" required></div><div class="col-md-4"><label class="form-label" for="expirationDate">Expiration Date</label><input class="form-control" type="date" id="expirationDate" name="expiration_date"></div><div class="col-md-4"><label class="form-label" for="advisoryStatus">Status</label><select class="form-select" id="advisoryStatus" name="status"><?php foreach ($statuses as $option): ?><option><?= e($option) ?></option><?php endforeach; ?></select></div>
</div></div><div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-success" id="saveAdvisoryButton">Save Advisory</button></div></form></div></div></div>

<div class="modal fade" id="viewAdvisoryModal" tabindex="-1" aria-labelledby="viewAdvisoryTitle" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content advisory-modal"><div class="modal-header"><div><p class="eyebrow mb-1">Admin-created rule-based guidance</p><h2 class="modal-title fs-5" id="viewAdvisoryTitle">Advisory Details</h2></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><div class="advisory-view-card"><dl id="viewAdvisoryDetails"></dl><h3>Key advice (farmer preview)</h3><p id="viewAdvisoryKeyAdvice" class="advisory-message"></p><h3>Advisory Message / Recommendation</h3><p id="viewAdvisoryMessage" class="advisory-message"></p><section class="recipient-eligibility-check" aria-labelledby="recipientEligibilityTitle"><h3 id="recipientEligibilityTitle">Controlled farmer eligibility</h3><p>Enter a controlled test farmer ID to see why that farmer is included or excluded. No personal details are displayed.</p><form id="eligibilityCheckForm" class="input-group"><input type="hidden" id="eligibilityAdvisoryId"><input class="form-control" type="number" id="eligibilityFarmerId" min="1" inputmode="numeric" placeholder="Farmer ID" aria-label="Controlled farmer ID" required><button class="btn btn-outline-success" type="submit">Check</button></form><div class="eligibility-result" id="eligibilityResult" role="status" aria-live="polite"></div></section></div></div></div></div></div>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const rules = <?= json_encode($rule_recommendations, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const csrf = <?= json_encode($csrf_token) ?>;
  const modalElement = document.getElementById('advisoryModal');
  const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
  const viewModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('viewAdvisoryModal'));
  const keyAdvice = document.getElementById('advisoryKeyAdvice'), keyAdviceCount = document.getElementById('keyAdviceCount');
  const renderKeyAdviceCount = () => { keyAdviceCount.textContent = `${keyAdvice.value.length}/${keyAdvice.maxLength}`; };
  keyAdvice.addEventListener('input', renderKeyAdviceCount);
  const form = document.getElementById('advisoryForm'), modalBody = document.getElementById('advisoryModalBody'), category = document.getElementById('advisoryCategory'), condition = document.getElementById('weatherCondition'), advisoryMessage = document.getElementById('advisoryMessage'), panel = document.getElementById('barangayPanel'), grid = document.getElementById('barangayGrid'), selectedCount = document.getElementById('selectedBarangayCount'), coverageField = document.getElementById('coverageField'), coverageError = document.getElementById('coverageError'), preview = document.getElementById('recipientPreview');
  const escapeHtml = value => String(value || '-').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
  const getData = id => JSON.parse(document.getElementById(`advisory-data-${id}`).textContent);
  const scope = () => form.querySelector('input[name="coverage_scope"]:checked').value;
  const barangayInputs = [...form.querySelectorAll('input[name="barangay_codes[]"]')];
  const selectedCodes = () => barangayInputs.filter(input => input.checked).map(input => input.value);
  let previewTimer;
  const updateRecipientPreview = () => { clearTimeout(previewTimer); delete preview.dataset.count; delete preview.dataset.coverage; if (!category.value) { preview.textContent = 'Select a category to preview eligible recipients.'; return; } if (scope() === 'Barangays' && !selectedCodes().length) { preview.textContent = 'Select at least one barangay to preview eligible recipients.'; return; } preview.textContent = 'Checking eligible recipients...'; previewTimer = setTimeout(async () => { const body = new FormData(); body.append('csrf_token', csrf); body.append('agricultural_category', category.value); body.append('coverage_scope', scope()); selectedCodes().forEach(code => body.append('barangay_codes[]', code)); try { const response = await fetch('weather-advisory-recipients.php', { method: 'POST', body }); const data = await response.json(); preview.textContent = data.success ? `${data.count} eligible farmer(s) - ${data.coverage}` : data.message; preview.dataset.count = data.success ? String(data.count) : ''; preview.dataset.coverage = data.success ? data.coverage : ''; } catch { preview.textContent = 'Recipient count is temporarily unavailable.'; } }, 250); };
  const renderSelectedCount = () => { const count = selectedCodes().length; selectedCount.textContent = `${count} selected`; barangayInputs.forEach(input => input.closest('.barangay-option').classList.toggle('selected', input.checked)); if (count) { coverageError.textContent = ''; coverageField.classList.remove('has-error'); } };
  const renderScope = () => { const usesBarangays = scope() === 'Barangays'; panel.classList.toggle('d-none', !usesBarangays); if (!usesBarangays) { coverageError.textContent = ''; coverageField.classList.remove('has-error'); } renderSelectedCount(); updateRecipientPreview(); };
  const renderRule = () => { const suggestion = rules[condition.value] || ''; document.getElementById('ruleHint').textContent = suggestion ? `Prepared rule-based recommendation: ${suggestion}` : 'No automatic recommendation is configured for this condition.'; if (suggestion && advisoryMessage.value.trim() === '') advisoryMessage.value = suggestion; };
  form.querySelectorAll('input[name="coverage_scope"]').forEach(input => input.addEventListener('change', renderScope)); grid.addEventListener('change', () => { renderSelectedCount(); updateRecipientPreview(); }); category.addEventListener('change', updateRecipientPreview); condition.addEventListener('change', renderRule);
  document.getElementById('createAdvisoryButton').addEventListener('click', () => { form.reset(); document.getElementById('advisoryAction').value = 'create'; document.getElementById('advisoryId').value = ''; document.getElementById('advisoryModalTitle').textContent = 'Create Advisory'; document.getElementById('saveAdvisoryButton').textContent = 'Save Advisory'; renderScope(); renderRule(); renderKeyAdviceCount(); });
  document.querySelectorAll('.edit-advisory-btn').forEach(button => button.addEventListener('click', () => { const data = getData(button.dataset.id); form.reset(); document.getElementById('advisoryAction').value = 'update'; document.getElementById('advisoryId').value = data.id; document.getElementById('advisoryModalTitle').textContent = 'Edit Advisory'; document.getElementById('saveAdvisoryButton').textContent = 'Save Changes'; document.getElementById('advisoryTitle').value = data.title; category.value = data.agricultural_category; condition.value = data.weather_condition; advisoryMessage.value = data.advisory_message; keyAdvice.value = data.key_advice || ''; renderKeyAdviceCount(); document.getElementById('effectiveDate').value = data.effective_date; document.getElementById('expirationDate').value = data.expiration_date || ''; document.getElementById('advisoryStatus').value = data.status; form.querySelector(`input[name="coverage_scope"][value="${data.coverage_scope}"]`).checked = true; const codes = String(data.barangay_codes || '').split(','); form.querySelectorAll('input[name="barangay_codes[]"]').forEach(input => input.checked = codes.includes(input.value)); renderScope(); renderRule(); modal.show(); }));
  document.querySelectorAll('.view-advisory-btn').forEach(button => button.addEventListener('click', () => { const data = getData(button.dataset.id); document.getElementById('viewAdvisoryTitle').textContent = data.title; document.getElementById('viewAdvisoryDetails').innerHTML = [['Category', data.agricultural_category], ['Weather Condition (admin selected)', data.weather_condition], ['Coverage', data.location_coverage], ['Eligible recipients', data.recipient_count], ['Status', data.status], ['Effective Date', data.effective_date], ['Expiration Date', data.expiration_date], ['Published At', data.published_at], ['Created By', data.creator_name]].map(([label,value]) => `<div><dt>${escapeHtml(label)}</dt><dd>${escapeHtml(value)}</dd></div>`).join(''); document.getElementById('viewAdvisoryKeyAdvice').textContent = data.key_advice || 'Not written yet. Farmers see the full message when it is short, otherwise a prompt to open it. Edit the advisory to add Key advice.'; document.getElementById('viewAdvisoryMessage').textContent = data.advisory_message || ''; document.getElementById('eligibilityAdvisoryId').value = data.id; document.getElementById('eligibilityFarmerId').value = ''; const result = document.getElementById('eligibilityResult'); result.textContent = ''; result.className = 'eligibility-result'; viewModal.show(); }));
  document.getElementById('eligibilityCheckForm').addEventListener('submit', async event => { event.preventDefault(); const result = document.getElementById('eligibilityResult'); const body = new FormData(); body.append('csrf_token', csrf); body.append('advisory_id', document.getElementById('eligibilityAdvisoryId').value); body.append('farmer_id', document.getElementById('eligibilityFarmerId').value); result.textContent = 'Checking eligibility...'; result.className = 'eligibility-result'; try { const response = await fetch('weather-advisory-recipients.php', { method: 'POST', body }); const data = await response.json(); if (!response.ok || !data.success) throw new Error(data.message || 'Eligibility could not be checked.'); result.textContent = data.eligibility.message; result.classList.add(data.eligibility.eligible ? 'eligible' : 'excluded'); } catch (error) { result.textContent = error instanceof Error ? error.message : 'Eligibility could not be checked.'; result.classList.add('excluded'); } });
  form.addEventListener('invalid', event => { const field = event.target; window.setTimeout(() => { field.focus({ preventScroll: true }); field.scrollIntoView({ behavior: 'smooth', block: 'center' }); }, 0); }, true);
  form.addEventListener('submit', event => { if (scope() === 'Barangays' && !selectedCodes().length) { event.preventDefault(); coverageError.textContent = 'Select at least one Cabadbaran barangay.'; coverageField.classList.add('has-error'); panel.classList.remove('d-none'); const firstCheckbox = barangayInputs[0]; firstCheckbox.focus({ preventScroll: true }); coverageField.scrollIntoView({ behavior: 'smooth', block: 'center' }); return; } if (document.getElementById('advisoryStatus').value !== 'Active') return; if (preview.dataset.count === undefined) { event.preventDefault(); preview.textContent = 'Wait for the eligible recipient count before publishing.'; updateRecipientPreview(); preview.scrollIntoView({ behavior: 'smooth', block: 'center' }); return; } if (!confirm(`Publish to ${preview.dataset.count} eligible farmer(s)?\n${preview.dataset.coverage}`)) event.preventDefault(); });
  document.querySelectorAll('.publish-form').forEach(publishForm => publishForm.addEventListener('submit', event => { if (!confirm(`Publish this advisory to ${publishForm.dataset.count} eligible farmer(s)?\n${publishForm.dataset.coverage}`)) event.preventDefault(); }));
  const linkedAdvisory = new URLSearchParams(window.location.search).get('advisory_id');
  if (linkedAdvisory && /^[1-9][0-9]*$/.test(linkedAdvisory)) document.querySelector('.view-advisory-btn[data-id="' + linkedAdvisory + '"]')?.click();
  let pageScrollPosition = 0;
  modalElement.addEventListener('show.bs.modal', () => { pageScrollPosition = window.scrollY; });
  modalElement.addEventListener('shown.bs.modal', () => { modalBody.scrollTop = 0; });
  modalElement.addEventListener('hidden.bs.modal', () => { window.requestAnimationFrame(() => window.scrollTo({ top: pageScrollPosition, left: 0, behavior: 'auto' })); });
});

(() => {
  const button = document.getElementById('loadWeatherRules');
  const output = document.getElementById('weatherRulesOutput');
  if (!button || !output) return;
  const esc = (value) => String(value ?? '—').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
  const time = (value) => { const d = new Date(value); return Number.isNaN(d.getTime()) ? esc(value) : esc(d.toLocaleString('en-PH', { timeZone: 'Asia/Manila', dateStyle: 'medium', timeStyle: 'short' })) + ' (Philippine time)'; };
  button.addEventListener('click', async () => {
    button.disabled = true; output.innerHTML = '<p class="text-muted small">Loading rules and the current provider reading…</p>';
    try {
      const response = await fetch('weather-rules-data.php', { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
      const data = await response.json();
      if (!response.ok || !data.success) throw new Error(data.message || 'Rules could not be loaded.');
      const basisTag = (type) => type === 'reference' ? '<span class="badge text-bg-info">PAGASA reference value</span>' : '<span class="badge text-bg-secondary">Project-defined</span>';
      const rules = data.rules.rules.map((r) => `<tr><td><strong>${esc(r.label)}</strong></td><td>${esc(r.threshold)}</td><td class="small">${esc(r.rule)}</td><td>${basisTag(r.basisType)}</td><td class="small text-muted">${esc(r.basis)}</td></tr>`).join('');
      const live = data.live;
      let current = '';
      if (live.status === 'unavailable') current = `<div class="alert alert-warning py-2 mb-0">Weather unavailable: ${esc(live.reason || live.message)}. No values are shown or estimated.</div>`;
      else {
        const w = live.weather;
        const cats = Object.entries(live.byCategory).map(([category, advice]) => {
          const matched = advice.matched.map((m) => esc(m.label) + ' (' + esc(m.measured) + ')').join('; ');
          const limits = (advice.limitations || []).map((l) => esc(l.reason)).join('<br>');
          return `<tr><td><strong>${esc(category)}</strong></td><td><strong>${esc(advice.headline)}</strong>${matched ? '<br><span class="small">' + matched + '</span>' : ''}${limits ? '<br><span class="small text-muted">' + limits + '</span>' : ''}</td><td class="small">${advice.recommendations.map((r) => esc(r.actions.join(' '))).join('<br>')}</td></tr>`;
        }).join('');
        const grid = live.grid ? ` · model grid cell ${live.grid.distanceKm === null ? 'distance unknown' : esc(live.grid.distanceKm) + ' km from the point'}${live.grid.elevationM === null ? '' : ', elevation ' + esc(live.grid.elevationM) + ' m'}` : '';
        const calc = Object.values(live.byCategory)[0]?.calculations || {};
        const heat = calc.heatIndexC === null || calc.heatIndexC === undefined ? '' : ` · estimated heat index ${esc(calc.heatIndexC)} °C (${esc(calc.heatIndexCategoryLabel)}; AgriReach estimate, not PAGASA's value)`;
        current = `${live.status === 'stale' ? `<div class="alert alert-warning py-2">${esc(live.message)}</div>` : ''}<p class="small mb-2"><strong>${esc(data.locationLabel)}</strong> · ${esc(w.provider)} (${esc(w.observationType)}) · observed ${time(w.observedAt)} · retrieved ${time(w.fetchedAt)}${grid}${heat}${live.status === 'stale' ? ' · <strong>STALE reading</strong>' : ''}</p><div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>Category</th><th>Result</th><th>Recommended action</th></tr></thead><tbody>${cats}</tbody></table></div>`;
      }
      output.innerHTML = `<h3 class="h6">Rule table <span class="text-muted fw-normal">(version ${esc(data.rules.ruleSetVersion)})</span></h3><div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>Condition</th><th>Threshold</th><th>Rule</th><th>Type</th><th>Basis and limits</th></tr></thead><tbody>${rules}</tbody></table></div><p class="small text-muted mb-1">${esc(data.rules.disclaimer)}</p><p class="small text-muted">${esc(data.rules.guidanceReview)}</p><h3 class="h6 mt-3">Current result (live, read-only)</h3>${current}`;
    } catch (error) {
      output.innerHTML = `<div class="alert alert-danger py-2 mb-0">${esc(error.message)}</div>`;
    } finally { button.disabled = false; button.textContent = 'Refresh rules and current result'; }
  });
})();
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
