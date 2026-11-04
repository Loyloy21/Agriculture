<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/../../backend/lib/notifications.php';
require_once __DIR__ . '/../../backend/lib/damage_reports.php';
require_once __DIR__ . '/../../backend/lib/damage_report_assessment.php';
require_once __DIR__ . '/../../backend/lib/damage_report_codes.php';
require_once __DIR__ . '/includes/pagination.php';
require_admin();

$filters = damage_report_filters_from_input($_GET);
$return_url = admin_page_url('damage-reports.php', $filters, admin_requested_page($_GET['page'] ?? null));

$csrf_token = admin_csrf_token();
$message = '';
$message_type = 'success';
$allowed_statuses = damage_report_allowed_statuses();
$allowed_categories = damage_report_allowed_categories();

$assessmentAction = $_POST['action'] ?? null;
// Admin-verified cause and figures (same measure and unit as the farmer's report). The farmer's submitted
// values are kept unchanged beside them; the report status workflow is not touched here.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $assessmentAction === 'save_verified_figures') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    $verifyToken = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '';
    $verifyReportId = filter_var($_POST['report_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!hash_equals($csrf_token, $verifyToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Your admin session expired. Reload the page and try again.']);
        exit;
    }
    if (!$verifyReportId) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Choose a valid damage report.']);
        exit;
    }
    try {
        $connection = db();
        $changed = damage_details_save_verified($connection, (int) $verifyReportId, (int) $_SESSION['admin_id'], $_POST);
        $report = damage_report_fetch($connection, damage_report_filters_from_input([]), (int) $verifyReportId, true)[0] ?? null;
        echo json_encode(['success' => true, 'changed' => $changed, 'message' => $changed ? 'Verified figures saved. The farmer-submitted values are unchanged.' : 'No changes to the verified figures.', 'report' => $report], JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (DamageDetailsVerificationException $exception) {
        http_response_code($exception->httpStatus);
        echo json_encode(['success' => false, 'message' => $exception->getMessage(), 'errors' => $exception->fieldErrors]);
    } catch (Throwable $exception) {
        error_log('Verified damage figures could not be saved: ' . $exception->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'The verified figures could not be saved. Please try again.']);
    }
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && is_string($assessmentAction) && in_array($assessmentAction, ['save_assessment', 'resolve_report'], true)) {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    $assessmentToken = is_string($_POST['csrf_token'] ?? null) ? $_POST['csrf_token'] : '';
    $assessmentReportId = filter_var($_POST['report_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!hash_equals($csrf_token, $assessmentToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Your admin session expired. Reload the page and try again.']);
        exit;
    }
    if (!$assessmentReportId) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Choose a valid damage report.']);
        exit;
    }
    try {
        $result = damage_report_apply_assessment(
            db(),
            (int) $assessmentReportId,
            (int) $_SESSION['admin_id'],
            $assessmentAction,
            is_string($_POST['final_assessed_cost'] ?? null) ? $_POST['final_assessed_cost'] : '',
            is_string($_POST['assessment_remarks'] ?? null) ? $_POST['assessment_remarks'] : ''
        );
        echo json_encode(['success' => true] + $result, JSON_INVALID_UTF8_SUBSTITUTE);
    } catch (DamageReportAssessmentException $exception) {
        http_response_code($exception->httpStatus);
        echo json_encode(['success' => false, 'message' => $exception->getMessage(), 'errors' => $exception->fieldErrors]);
    } catch (Throwable $exception) {
        error_log('Damage report assessment could not be saved: ' . $exception->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'The assessment could not be saved. Please try again.']);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted_token = (string) ($_POST['csrf_token'] ?? '');
    $report_id = filter_var($_POST['report_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $new_status = (string) ($_POST['new_status'] ?? '');
    $response_message = trim((string) ($_POST['response_message'] ?? ''));
    $rejection_reason = trim((string) ($_POST['rejection_reason'] ?? ''));

    if (!hash_equals($csrf_token, $posted_token)) {
        $message = 'Your session token is invalid. Please try again.';
        $message_type = 'danger';
    } elseif ($report_id === false || $report_id === null || !in_array($new_status, $allowed_statuses, true) || $new_status === 'Resolved' || !in_array((string) ($_POST['action'] ?? ''), ['', 'update_response'], true)) {
        $message = 'Invalid report response request.';
        $message_type = 'danger';
    } elseif ($new_status === 'Rejected' && mb_strlen($rejection_reason) < 5) {
        $message = 'A rejection reason of at least 5 characters is required.';
        $message_type = 'danger';
    } elseif (mb_strlen($response_message) > 2000 || mb_strlen($rejection_reason) > 1000) {
        $message = 'The response or rejection reason is too long.';
        $message_type = 'danger';
    } else {
        try {
            $connection = db();
            damage_report_record_admin_response($connection, (int) $report_id, (int) $_SESSION['admin_id'], $new_status, $response_message, $rejection_reason);
            $_SESSION['damage_reports_flash'] = 'Report status updated to ' . $new_status . '.';
            header('Location: ' . $return_url);
            exit;
        } catch (Throwable $exception) {
            $message = in_array($exception->getMessage(), ['Report not found.', 'Resolved reports are read-only.'], true) ? $exception->getMessage() : 'The report response could not be saved.';
            $message_type = 'danger';
        }
    }
}

if (!empty($_SESSION['damage_reports_flash'])) {
    $message = (string) $_SESSION['damage_reports_flash'];
    unset($_SESSION['damage_reports_flash']);
}

$search = $filters['search'];
$category = $filters['category'];
$status = $filters['status'];
$date_from = $filters['date_from'];
$date_to = $filters['date_to'];
$damage_type = $filters['damage_type'];
$reports = [];
$load_error = '';
$total_reports = 0;
$page_state = admin_page_state(0, 1);

try {
    $total_reports = damage_report_count(db(), $filters);
    $page_state = admin_page_state($total_reports, admin_requested_page($_GET['page'] ?? null));
    admin_redirect_clamped_page('damage-reports.php', $filters, $page_state);
    $reports = damage_report_fetch(db(), $filters, null, true, false, $page_state['per_page'], $page_state['offset']);
} catch (InvalidArgumentException $exception) {
    $load_error = $exception->getMessage();
} catch (Throwable $exception) {
    $load_error = 'Damage report data is not available yet. Import the updated database setup first.';
}

$export_url = 'damage-reports-pdf.php?' . http_build_query(array_merge(['mode' => 'list'], $filters));

$page_title = 'Damage Reports';
$active_page = 'damage-reports';
$page_css = 'assets/css/damage-reports.css';
$page_css_extra = 'assets/css/damage-reports-modal.css';
require_once __DIR__ . '/includes/header.php';
?>
<div class="container-fluid py-4">
    <div class="mb-4"><p class="eyebrow mb-1">Agricultural monitoring</p><h1 class="page-title mb-1">Damage Reports</h1><p class="text-muted mb-0">View and manage damage reports submitted by verified farmers.</p></div>
    <?php if ($message !== ''): ?><div class="alert alert-<?= e($message_type) ?> alert-dismissible fade show" role="alert"><?= e($message) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div><?php endif; ?>
    <?php if ($load_error !== ''): ?><div class="alert alert-warning" role="alert"><?= e($load_error) ?></div><?php endif; ?>
    <section class="content-card">
        <form class="report-filters" method="get" aria-label="Filter damage reports">
            <div class="report-filter-search"><label class="form-label small fw-semibold" for="search">Search</label><div class="input-group"><span class="input-group-text" aria-hidden="true"><i class="bi bi-search"></i></span><input class="form-control" id="search" name="search" value="<?= e($search) ?>" placeholder="Farmer, farm, or report ID"></div></div>
            <div><label class="form-label small fw-semibold" for="status">Status</label><select class="form-select" id="status" name="status"><option value="">All statuses</option><?php foreach ($allowed_statuses as $option): ?><option value="<?= e($option) ?>"<?= $status === $option ? ' selected' : '' ?>><?= e($option) ?></option><?php endforeach; ?></select></div>
            <div><label class="form-label small fw-semibold" for="category">Category</label><select class="form-select" id="category" name="category"><option value="">All categories</option><?php foreach ($allowed_categories as $option): ?><option value="<?= e($option) ?>"<?= $category === $option ? ' selected' : '' ?>><?= e($option) ?></option><?php endforeach; ?></select></div>
            <div><label class="form-label small fw-semibold" for="damage_type">Cause / damage type</label><input class="form-control" id="damage_type" name="damage_type" value="<?= e($damage_type) ?>" placeholder="All"></div>
            <div><label class="form-label small fw-semibold" for="date_from">From</label><input class="form-control" type="date" id="date_from" name="date_from" value="<?= e($date_from) ?>"></div>
            <div><label class="form-label small fw-semibold" for="date_to">To</label><input class="form-control" type="date" id="date_to" name="date_to" value="<?= e($date_to) ?>"></div>
            <div class="report-filter-actions"><button class="btn btn-success" type="submit">Filter</button><?php if (array_filter($filters, static fn ($value) => $value !== '')): ?><a class="btn btn-light" href="damage-reports.php"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Reset</a><?php endif; ?></div>
        </form>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3"><div><h2 class="section-title mb-1">Submitted reports</h2><p class="text-muted small mb-0" id="reportResultCount"><?= $total_reports === 0 ? 'No reports found' : 'Showing ' . $page_state['from'] . '–' . $page_state['to'] . ' of ' . $total_reports . ' report' . ($total_reports === 1 ? '' : 's') ?></p></div><a class="btn btn-outline-success btn-sm" href="<?= e($export_url) ?>"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> Export list PDF</a></div>
        <div class="table-responsive"><table class="table align-middle damage-reports-table mb-0"><thead><tr><th scope="col">Report ID</th><th scope="col">Submitted</th><th scope="col">Farmer / Farm</th><th scope="col">Category</th><th scope="col">Reported loss</th><th scope="col">Status</th><th scope="col" class="text-end">View</th></tr></thead><tbody>
        <?php if (!$reports): ?><tr><td colspan="7" class="text-center py-5 text-muted">No damage reports match the selected filters.</td></tr><?php endif; ?>
        <?php foreach ($reports as $report): $detail_json = json_encode($report, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE); $details = $report['details']; ?>
            <tr>
                <td><strong class="report-code"><?= e((string) $report['report_code']) ?></strong><small class="d-block text-muted"><?= damage_report_code_is_rsbsa((string) $report['report_code']) ? 'RSBSA reference + report sequence' : 'Earlier report reference' ?></small></td>
                <td><?= e(date('M d, Y', strtotime((string) $report['submitted_at']))) ?><small class="d-block text-muted"><?= e(date('h:i A', strtotime((string) $report['submitted_at']))) ?></small></td>
                <td><?= e((string) $report['full_name']) ?><small class="d-block text-muted"><?= e((string) ($report['farm_name'] ?: 'Farm not recorded')) ?></small></td>
                <td><?= e((string) $report['agricultural_category']) ?><?php if ($details !== null): ?><small class="d-block text-muted"><?= e((string) $details['subjectType']) ?></small><?php endif; ?></td>
                <td class="report-loss-cell"><?php if ($details !== null): ?><?= e((string) $details['summary']) ?><?php else: ?><span class="text-muted">Older report · <?= e((string) $report['damage_type']) ?></span><?php if ($report['estimated_damage_cost'] !== null): ?><small class="d-block text-muted">Farmer estimate ₱<?= e(number_format((float) $report['estimated_damage_cost'], 2)) ?></small><?php endif; ?><?php endif; ?></td>
                <td><span class="report-status <?= strtolower(str_replace(' ', '-', (string) $report['status'])) ?>"><?= e((string) $report['status']) ?></span></td>
                <td class="text-end"><button class="btn btn-outline-success btn-sm view-report-btn" type="button" data-report-key="<?= e((string) $report['id']) ?>" aria-label="View report <?= e((string) $report['report_code']) ?>"><i class="bi bi-eye" aria-hidden="true"></i> View</button><script type="application/json" id="report-data-<?= (int) $report['id'] ?>"><?= $detail_json ?></script></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div><?= admin_pagination_html($page_state, 'damage-reports.php', $filters) ?>
    </section>
</div>

<div class="modal fade" id="reportDetailsModal" tabindex="-1" aria-labelledby="reportDetailsTitle" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable report-review-dialog"><div class="modal-content report-modal">
    <div class="modal-header report-review-header">
      <div class="report-heading"><div class="report-heading-line"><h2 class="modal-title" id="reportDetailsTitle">Damage Report</h2><span id="reportDetailsStatus" class="report-status"></span></div><strong id="reportDetailsNumber" class="report-heading-code">Not available</strong><small id="reportDetailsCodeNote" class="report-heading-submitted"></small><small id="reportDetailsSubmitted" class="report-heading-submitted">Submitted: Not available</small></div>
      <nav class="report-step-nav" aria-label="Move between reports in the filtered list"><button id="previousReport" class="btn btn-outline-success btn-sm" type="button"><i class="bi bi-chevron-left" aria-hidden="true"></i> Previous</button><span id="reportPosition" aria-live="polite"></span><button id="nextReport" class="btn btn-outline-success btn-sm" type="button">Next <i class="bi bi-chevron-right" aria-hidden="true"></i></button></nav>
      <a class="btn btn-success btn-sm report-header-export" id="downloadReportPdf" href="#" target="_blank" rel="noopener"><i class="bi bi-printer" aria-hidden="true"></i> Print / PDF</a>
      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close report and return to the list"></button>
    </div>
    <div class="report-tabs" role="tablist" aria-label="Report sections">
      <button type="button" role="tab" id="tab-overview" aria-controls="panel-overview" aria-selected="true" data-report-tab="overview">Overview</button>
      <button type="button" role="tab" id="tab-photos" aria-controls="panel-photos" aria-selected="false" tabindex="-1" data-report-tab="photos">Photos &amp; Map</button>
      <button type="button" role="tab" id="tab-assessment" aria-controls="panel-assessment" aria-selected="false" tabindex="-1" data-report-tab="assessment">Admin Assessment</button>
      <button type="button" role="tab" id="tab-technical" aria-controls="panel-technical" aria-selected="false" tabindex="-1" data-report-tab="technical">Technical Details</button>
      <button type="button" role="tab" id="tab-history" aria-controls="panel-history" aria-selected="false" tabindex="-1" data-report-tab="history">History</button>
    </div>
    <div class="modal-body report-review-body" tabindex="0">
      <div id="reportLoadMessage" class="alert alert-warning" role="alert" hidden></div>

      <section class="report-tab-panel" role="tabpanel" id="panel-overview" aria-labelledby="tab-overview">
        <section class="report-review-card"><h3><i class="bi bi-file-earmark-text" aria-hidden="true"></i> Report information</h3><div class="report-overview-grid"><dl id="reportFarmerDetails" class="report-info-list"></dl><dl id="reportFarmDetails" class="report-info-list"></dl></div></section>
        <section class="report-review-card"><h3><i class="bi bi-chat-left-text" aria-hidden="true"></i> What happened <small class="text-muted fw-normal">farmer's description</small></h3><p id="reportOverviewDescription" class="report-description-text"></p></section>
        <section class="report-review-card"><div class="d-flex justify-content-between align-items-center gap-2 mb-2"><h3 class="mb-0"><i class="bi bi-images" aria-hidden="true"></i> Photos <span id="reportOverviewPhotoCount"></span></h3><button type="button" class="btn btn-link btn-sm p-0" data-go-tab="photos">View all photos &amp; map <i class="bi bi-arrow-right" aria-hidden="true"></i></button></div><div id="reportOverviewPhotos" class="report-overview-photos"></div></section>
        <section class="report-review-card report-evidence-summary"><div><h3 class="mb-1"><i class="bi bi-geo-alt" aria-hidden="true"></i> Evidence &amp; location</h3><p id="reportEvidenceSummary" class="mb-0 small"></p></div><button type="button" class="btn btn-light btn-sm" data-go-tab="technical">Technical details</button></section>
      </section>

      <section class="report-tab-panel" role="tabpanel" id="panel-photos" aria-labelledby="tab-photos" hidden>
        <div class="report-evidence-grid">
          <section class="report-review-card report-photos"><h3><i class="bi bi-images" aria-hidden="true"></i> Submitted Photos <span id="reportPhotoCount"></span><small class="report-photo-helper">Click a photo to view it full size</small></h3><div id="reportImages" class="report-images"></div></section>
          <section class="report-review-card report-where"><h3><i class="bi bi-geo-alt-fill" aria-hidden="true"></i> Map</h3><p class="photo-selection-caption"><strong id="selectedPhotoMapLabel"></strong><span id="selectedPhotoMapPoint"></span></p><div class="d-flex flex-wrap gap-2 mb-2"><button type="button" id="showMainReportPoint" class="btn btn-light btn-sm">Farmer-confirmed damage point</button><button type="button" id="showRegisteredFarmPoint" class="btn btn-light btn-sm">Registered farm point</button></div><p class="report-address"><span>Address / landmark</span><strong id="reportAddress"></strong></p><p class="report-address"><span>Damage point</span><strong id="reportDamagePoint"></strong></p><p class="report-address"><span>Registered farm point</span><strong id="reportFarmPoint"></strong></p><div class="map-placeholder report-map-wrapper"><div class="map-toolbar" role="group" aria-label="Map view"><button id="roadMapButton" type="button" class="map-mode-button active" aria-pressed="true">Road Map</button><button id="satelliteMapButton" type="button" class="map-mode-button" aria-pressed="false">Satellite</button></div><div id="reportMap" class="report-map" aria-label="Damage report location map"></div><div id="mapFallback" class="map-fallback" role="status">Map preview will load when the report opens.</div><div class="map-legend" aria-label="Map marker legend"><span><i class="map-dot registered"></i>Registered farm point</span><span><i class="map-dot submitted"></i>Damage location</span></div><div class="report-map-links"><a id="mapOpenFullLink" class="map-external-link" href="#" target="_blank" rel="noopener noreferrer" hidden><i class="bi bi-arrows-fullscreen" aria-hidden="true"></i> Open full map</a><a id="mapExternalLink" class="map-external-link" href="#" target="_blank" rel="noopener noreferrer" hidden><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i> Open in Google Maps</a></div></div><p id="reportMapNote" class="report-map-note"></p><p class="small text-muted mb-0">The map shows saved points only. Farm boundaries are not verified.</p></section>
        </div>
      </section>

      <section class="report-tab-panel" role="tabpanel" id="panel-assessment" aria-labelledby="tab-assessment" hidden>
        <div class="report-assessment-grid">
          <section class="report-review-card report-assessment"><h3><i class="bi bi-person-lines-fill" aria-hidden="true"></i> <span id="reportSubmissionTitle">Submitted by farmer</span> <small class="text-muted fw-normal">read-only</small></h3><div id="reportWhatHappened"></div></section>
          <section class="report-review-card report-response-card"><h3><i class="bi bi-clipboard-check" aria-hidden="true"></i> Admin assessment</h3>
            <div id="verifiedFiguresSection" class="verified-figures" hidden></div>
            <div id="assessmentFeedback" class="alert py-2" role="status" hidden></div>
            <form method="post" id="reportAssessmentForm" class="assessment-edit" hidden>
              <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>"><input type="hidden" name="report_id" id="assessmentReportId">
              <h4 class="verified-title">Final review <small id="assessmentRuleNote"></small></h4>
              <div class="assessment-fields">
                <div id="assessmentCostWrap"><label class="form-label fw-semibold" for="assessmentCost">Final Assessed Cost <small>(required to resolve this older report)</small></label><div class="assessment-peso-input"><span aria-hidden="true">₱</span><input id="assessmentCost" class="form-control" name="final_assessed_cost" type="text" inputmode="decimal" maxlength="16" autocomplete="off" aria-describedby="assessmentCostHint assessmentCostError" placeholder="0.00"></div><small id="assessmentCostHint" class="form-text">Admin assessment in Philippine pesos. The farmer estimate remains unchanged.</small><div id="assessmentCostError" class="invalid-feedback" role="alert"></div></div>
                <div><label class="form-label fw-semibold" for="assessmentRemarks">Reviewer's notes / final remarks <small>(required to resolve)</small></label><textarea id="assessmentRemarks" class="form-control" name="assessment_remarks" rows="3" maxlength="4000" aria-describedby="assessmentRemarksError" placeholder="Record only what was actually reviewed or checked."></textarea><div id="assessmentRemarksError" class="invalid-feedback" role="alert"></div></div>
              </div>
              <div class="assessment-actions"><small id="assessmentActionNote">Saving a draft does not change the report status.</small><button class="btn btn-outline-success" type="submit" name="action" value="save_assessment"><i class="bi bi-floppy" aria-hidden="true"></i> Save draft</button><button class="btn btn-success" type="submit" name="action" value="resolve_report" id="resolveReportButton"><i class="bi bi-check2-circle" aria-hidden="true"></i> Mark as Resolved</button></div>
            </form>
            <div id="assessmentReadOnly" class="assessment-readonly" hidden><div id="assessmentSavedCostWrap"><small>Final Assessed Cost</small><strong id="assessmentSavedCost">Not available</strong></div><div class="assessment-remarks-block"><small>Reviewer's notes / final remarks</small><p id="assessmentSavedRemarks"></p></div><div><small>Reviewed by</small><strong id="assessmentSavedBy">Not available</strong></div><div><small>Review date</small><strong id="assessmentSavedAt">Not available</strong></div><p id="assessmentReadOnlyNote" class="assessment-readonly-note"></p></div>
            <details id="reportOtherActions" class="report-other-actions"><summary>Change status or message the farmer</summary><form method="post" id="reportResponseForm"><input type="hidden" name="action" value="update_response"><input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>"><input type="hidden" name="report_id" id="responseReportId"><div class="report-response-grid"><div><label class="form-label small fw-semibold" for="responseStatus">Report status</label><select class="form-select" id="responseStatus" name="new_status" required><?php foreach ($allowed_statuses as $option): if ($option === 'Resolved') continue; ?><option value="<?= e($option) ?>"><?= e($option) ?></option><?php endforeach; ?></select><span id="responseStatusIndicator" class="report-status report-response-indicator">Not available</span></div><div><label class="form-label small fw-semibold" for="responseMessage">Message to farmer (optional)</label><textarea class="form-control" id="responseMessage" name="response_message" rows="2" maxlength="2000" placeholder="Add an optional message for the farmer."></textarea></div><div class="rejection-reason-wrap" id="rejectionReasonWrap" hidden><label class="form-label small fw-semibold" for="rejectionReason">Rejection reason <span class="required-mark">*</span></label><textarea class="form-control" id="rejectionReason" name="rejection_reason" rows="2" maxlength="1000" placeholder="Explain why this report is being rejected."></textarea></div><div class="report-response-actions"><button class="btn btn-outline-success" type="submit"><i class="bi bi-send" aria-hidden="true"></i> Save status / message</button></div></div></form></details>
          </section>
        </div>
      </section>

      <section class="report-tab-panel" role="tabpanel" id="panel-technical" aria-labelledby="tab-technical" hidden>
        <div class="report-extra report-extra-grid">
          <section class="report-review-card report-extra-full"><h3>Validation checks <small class="text-muted fw-normal">only checks actually performed are shown as passed</small></h3><div id="reportValidationSummary" class="report-check-summary"></div><details id="reportCheckDisclosure" class="report-disclosure"><summary>View all check details</summary><div id="reportValidationDetails" class="validation-list"></div></details></section>
          <section class="report-review-card report-extra-full"><h3>Photo metadata</h3><div id="reportPhotoMetadata"></div></section>
          <section class="report-review-card report-extra-full"><h3>Weather snapshot <small class="text-muted fw-normal">recorded at submission</small></h3><div id="reportWeatherDetails"></div></section>
          <section class="report-review-card report-extra-full"><h3>Weather-based advice result <small class="text-muted fw-normal">automatic rule result, not an admin advisory</small></h3><div id="reportWeatherRule"></div></section>
          <section class="report-review-card"><h3>Coordinate provenance</h3><dl id="reportLocationDetails" class="report-info-list"></dl></section>
          <section class="report-review-card"><h3>Saved report fields</h3><dl id="reportInfoDetails" class="report-info-list"></dl><dl id="reportAdminHistory" class="report-info-list"></dl></section>
        </div>
      </section>

      <section class="report-tab-panel" role="tabpanel" id="panel-history" aria-labelledby="tab-history" hidden>
        <section class="report-review-card"><h3><i class="bi bi-clock-history" aria-hidden="true"></i> Review history <small class="text-muted fw-normal">recorded events only</small></h3><div class="table-responsive"><table class="table table-sm report-history-table mb-0"><thead><tr><th scope="col">Date &amp; time</th><th scope="col">Event</th><th scope="col">Status</th><th scope="col">Notes</th><th scope="col">Updated by</th></tr></thead><tbody id="reportHistory"></tbody></table></div></section>
      </section>
    </div>
    <div class="modal-footer report-review-footer"><small id="reportFooterNote" class="me-auto text-muted"></small><button type="button" class="btn btn-light" data-bs-dismiss="modal"><i class="bi bi-arrow-left" aria-hidden="true"></i> Back to list</button><a class="btn btn-success" id="footerReportPdf" href="#" target="_blank" rel="noopener"><i class="bi bi-printer" aria-hidden="true"></i> Print / PDF</a></div>
  </div></div>
</div>
<div class="report-image-viewer" id="reportImageViewer" role="dialog" aria-modal="true" aria-labelledby="reportImageViewerTitle" hidden>
    <div class="report-image-viewer-shell">
        <header class="report-image-viewer-header">
            <div><p class="eyebrow mb-1">Damage evidence</p><h2 id="reportImageViewerTitle">Damage image</h2></div>
            <button class="report-image-viewer-close" id="closeReportImageViewer" type="button" aria-label="Close image viewer" title="Close"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </header>
        <div class="report-image-viewer-body">
            <button class="report-image-viewer-nav previous" id="previousReportImage" type="button" aria-label="Previous damage image"><i class="bi bi-chevron-left" aria-hidden="true"></i></button>
            <div class="report-image-viewer-stage" id="reportImageViewerStage" tabindex="0">
                <div class="report-image-viewer-canvas" id="reportImageViewerCanvas">
                    <img id="reportImageViewerImage" alt="" draggable="false" hidden>
                    <div class="report-image-viewer-message" id="reportImageViewerMessage" role="status">Loading image...</div>
                </div>
            </div>
            <button class="report-image-viewer-nav next" id="nextReportImage" type="button" aria-label="Next damage image"><i class="bi bi-chevron-right" aria-hidden="true"></i></button>
        </div>
        <section class="report-image-location" id="reportImageLocation" aria-label="Selected photo location">
            <div class="report-image-location-summary"><span id="reportImageLocationText" role="status"></span><a id="reportImageLocationLink" target="_blank" rel="noopener noreferrer" hidden>Open exact photo point in Google Maps</a></div>
            <div id="reportImageLocationMap" class="report-image-location-map" hidden></div>
        </section>
        <footer class="report-image-viewer-footer">
            <span id="reportImageCounter" aria-live="polite"></span>
            <div class="report-image-zoom-controls" aria-label="Image zoom controls">
                <button id="reportImageZoomOut" type="button" aria-label="Zoom out"><i class="bi bi-dash-lg" aria-hidden="true"></i></button>
                <button id="reportImageZoomReset" type="button" aria-label="Reset zoom"><span id="reportImageZoomValue">100%</span></button>
                <button id="reportImageZoomIn" type="button" aria-label="Zoom in"><i class="bi bi-plus-lg" aria-hidden="true"></i></button>
            </div>
        </footer>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const escapeHtml = (value) => String(value ?? '—').replace(/[&<>"']/g, (character) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[character]));
  const text = (value) => escapeHtml(value === null || value === undefined || value === '' ? '—' : value);
  const row = (label, value) => `<div><dt>${escapeHtml(label)}</dt><dd>${text(value)}</dd></div>`;
  const modalElement = document.getElementById('reportDetailsModal');
  const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
  const firstReportIndex = <?= (int) $page_state['offset'] ?>;
  const reportFilters = <?= json_encode($filters, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE) ?>;
  let filteredReportCount = <?= (int) $total_reports ?>;
  let activeReportIndex = -1;
  let reportLoading = false;
  let listNeedsRefresh = false;
  let responseBaseline = null;
  const responseMessageField = document.getElementById('responseMessage');
  const reportLoadMessage = document.getElementById('reportLoadMessage');
  const previousReportButton = document.getElementById('previousReport');
  const nextReportButton = document.getElementById('nextReport');
  const status = document.getElementById('responseStatus');
  const downloadReportPdf = document.getElementById('downloadReportPdf');
  const assessmentForm = document.getElementById('reportAssessmentForm');
  const assessmentCost = document.getElementById('assessmentCost');
  const assessmentRemarks = document.getElementById('assessmentRemarks');
  const assessmentFeedback = document.getElementById('assessmentFeedback');
  let assessmentSaving = false;
  const rejectionWrap = document.getElementById('rejectionReasonWrap');
  const rejectionReason = document.getElementById('rejectionReason');
  let mapMode = 'road';
  let activeReport = null;
  const reportModalDialog = modalElement.querySelector('.modal-dialog');
  const reportModalBody = modalElement.querySelector('.modal-body');
  // Report sections (ARIA tabs). The header, tabs and footer stay in place; only the body scrolls.
  const tabButtons = Array.from(modalElement.querySelectorAll('[data-report-tab]'));
  let activeTab = 'overview';
  const showTab = (name, focusTab = false) => {
    activeTab = name;
    tabButtons.forEach((button) => {
      const selected = button.dataset.reportTab === name;
      button.setAttribute('aria-selected', String(selected));
      button.tabIndex = selected ? 0 : -1;
      button.classList.toggle('active', selected);
      document.getElementById(button.getAttribute('aria-controls')).hidden = !selected;
      if (selected && focusTab) button.focus();
    });
    reportModalBody.scrollTop = 0;
    if (name === 'photos' && activeReport && modalElement.classList.contains('show')) window.requestAnimationFrame(() => renderReportMap(activeReport));
  };
  tabButtons.forEach((button, index) => {
    button.addEventListener('click', () => showTab(button.dataset.reportTab));
    button.addEventListener('keydown', (event) => {
      const target = event.key === 'ArrowRight' ? index + 1 : event.key === 'ArrowLeft' ? index - 1 : event.key === 'Home' ? 0 : event.key === 'End' ? tabButtons.length - 1 : null;
      if (target === null) return;
      event.preventDefault();
      showTab(tabButtons[(target + tabButtons.length) % tabButtons.length].dataset.reportTab, true);
    });
  });
  modalElement.querySelectorAll('[data-go-tab]').forEach((button) => button.addEventListener('click', () => showTab(button.dataset.goTab)));
  let reportTrigger = null;
  const imageViewer = document.getElementById('reportImageViewer');
  const viewerImage = document.getElementById('reportImageViewerImage');
  const viewerStage = document.getElementById('reportImageViewerStage');
  const viewerCanvas = document.getElementById('reportImageViewerCanvas');
  const viewerMessage = document.getElementById('reportImageViewerMessage');
  const viewerCounter = document.getElementById('reportImageCounter');
  const viewerPrevious = document.getElementById('previousReportImage');
  const viewerNext = document.getElementById('nextReportImage');
  const viewerZoomOut = document.getElementById('reportImageZoomOut');
  const viewerZoomReset = document.getElementById('reportImageZoomReset');
  const viewerZoomIn = document.getElementById('reportImageZoomIn');
  const viewerZoomValue = document.getElementById('reportImageZoomValue');
  const viewerLocation = document.getElementById('reportImageLocation');
  const viewerLocationText = document.getElementById('reportImageLocationText');
  const viewerLocationLink = document.getElementById('reportImageLocationLink');
  const viewerLocationMap = document.getElementById('reportImageLocationMap');
  modalElement.appendChild(imageViewer);
  let viewerImages = [];
  let viewerIndex = 0;
  let viewerZoom = 1;
  let viewerImageLoaded = false;
  let viewerReturnFocus = null;
  let reportBodyScrollTop = 0;
  const coordinateValue = (value, minimum, maximum) => {
    if (value === null || value === undefined || value === '') return null;
    const parsed = Number(value);
    return Number.isFinite(parsed) && parsed >= minimum && parsed <= maximum ? parsed : false;
  };
  const pointEvidence = (latitude, longitude) => {
    const hasLatitude = latitude !== null && latitude !== undefined && latitude !== '';
    const hasLongitude = longitude !== null && longitude !== undefined && longitude !== '';
    if (!hasLatitude && !hasLongitude) return { state: 'unavailable', point: null, reason: 'No coordinate pair was stored.' };
    if (!hasLatitude || !hasLongitude) return { state: 'failed', point: null, reason: 'The stored coordinate pair is incomplete.' };
    const parsedLatitude = coordinateValue(latitude, -90, 90);
    const parsedLongitude = coordinateValue(longitude, -180, 180);
    if (parsedLatitude === false || parsedLongitude === false || (parsedLatitude === 0 && parsedLongitude === 0)) return { state: 'failed', point: null, reason: 'The stored latitude or longitude is invalid; 0,0 is not usable GPS evidence.' };
    return { state: 'passed', point: [parsedLatitude, parsedLongitude], reason: 'Latitude and longitude are present and within valid coordinate ranges.' };
  };
  const pointDistanceMeters = (first, second) => {
    if (!first || !second) return null;
    const radians = (degrees) => degrees * Math.PI / 180;
    const latitudeDelta = radians(second[0] - first[0]);
    const longitudeDelta = radians(second[1] - first[1]);
    const value = Math.sin(latitudeDelta / 2) ** 2 + Math.cos(radians(first[0])) * Math.cos(radians(second[0])) * Math.sin(longitudeDelta / 2) ** 2;
    return 6371000 * 2 * Math.atan2(Math.sqrt(value), Math.sqrt(1 - value));
  };
  const formatPoint = (point) => point ? `${point[0].toFixed(7)}, ${point[1].toFixed(7)}` : 'Not available';
  // Category-specific damage details. Farmer-submitted values and admin-verified values are shown and
  // stored separately; older reports without details keep the original layout.
  const detailConfig = <?= json_encode(damage_details_config(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  const measureUnits = { crop_percent: '%', head_count: 'head', bird_count: 'birds', fish_count: 'fish', fish_weight_kg: 'kg' };
  const formatAmount = (value, decimals = 2) => value === null || value === undefined || value === '' ? null : Number(value).toLocaleString('en-PH', { maximumFractionDigits: decimals });
  const figureRows = (measure, figures, prefix) => {
    if (measure === 'crop_percent') return [
      row(`${prefix} crop damage estimate`, figures.cropDamagePercent === null ? null : `${formatAmount(figures.cropDamagePercent)}%`),
      row(`${prefix} planted area`, figures.plantedAreaHa === null ? 'Not provided' : `${formatAmount(figures.plantedAreaHa, 4)} hectares`),
      row(`${prefix} affected area`, figures.affectedAreaHa === null ? 'Not provided' : `${formatAmount(figures.affectedAreaHa, 4)} hectares`),
    ];
    const unit = measureUnits[measure] || '';
    const decimals = measure === 'fish_weight_kg' ? 2 : 0;
    const died = measure === 'head_count' || measure === 'bird_count';
    return [
      row(`${prefix} total before the incident`, figures.totalBefore === null ? 'Not provided' : `${formatAmount(figures.totalBefore, decimals)} ${unit}`),
      row(`${prefix} ${died ? 'number that died' : 'amount lost'}`, figures.amountLost === null ? null : `${formatAmount(figures.amountLost, decimals)} ${unit}`),
      row(`${prefix} loss percentage`, figures.lossPercent === null ? 'Not calculated (total stock not provided)' : `${formatAmount(figures.lossPercent, 1)}% (calculated from the counts)`),
    ];
  };
  const submissionHtml = (data, cost) => {
    const details = data.details;
    if (!details) return null;
    const lossNote = details.measureType === 'crop_percent' ? "The farmer's estimate for this crop or plot, not a measurement of the entire farm."
      : details.lossPercent === null ? 'Total stock was not provided, so no percentage is calculated.' : "Calculated from the farmer's own figures.";
    return `<p class="report-origin-note">Original farmer submission. Admin verification never changes these values.</p>`
      + `<div class="report-assessment-top report-details-top"><div class="report-fact"><small>Category</small><strong>${text(data.agricultural_category)}</strong></div>`
      + `<div class="report-fact"><small>${escapeHtml(details.subjectLabel)}</small><strong>${text(details.subjectType)}</strong></div>`
      + `<div class="report-fact"><small>Suspected cause</small><strong>${text(details.suspectedCauseLabel)}</strong><span>Farmer's selection; not a verified cause.</span></div></div>`
      + `<div class="report-fact report-loss"><small>Farmer-reported loss</small><strong>${text(details.summary)}</strong><span>${escapeHtml(lossNote)}</span></div>`
      + `<dl class="report-info-list">${figureRows(details.measureType, details, 'Farmer-reported').join('')}</dl>`
      + `<div class="report-fact report-description"><small>What happened? (from farmer)</small><p>${text(data.damage_description || 'Not provided')}</p></div>`;
  };
  const verifiedSection = document.getElementById('verifiedFiguresSection');
  let verifySaving = false;
  const verifyInput = (name, label, value, unit) => `<div><label class="form-label fw-semibold" for="verify_${name}">${escapeHtml(label)}</label><div class="input-group"><input class="form-control" id="verify_${name}" name="${name}" type="text" inputmode="decimal" autocomplete="off" value="${value === null || value === undefined ? '' : escapeHtml(value)}">${unit ? `<span class="input-group-text">${escapeHtml(unit)}</span>` : ''}</div><div class="invalid-feedback d-block" data-error-for="${name}"></div></div>`;
  const renderVerified = (data) => {
    const details = data.details;
    verifiedSection.hidden = !details;
    if (!details) { verifiedSection.innerHTML = ''; return; }
    const verified = details.verified;
    const measure = details.measureType;
    const unit = measureUnits[measure] || '';
    const summary = verified
      ? `<div class="report-fact report-loss verified"><small>Admin-verified loss</small><strong>${text(verified.summary)}</strong><span>Verified cause: ${text(verified.causeLabel)}${verified.verifiedBy ? ` · ${escapeHtml(verified.verifiedBy)}` : ''}${verified.verifiedAt ? ` · ${escapeHtml(verified.verifiedAt)}` : ''}</span></div>`
        + `<dl class="report-info-list">${figureRows(measure, verified, 'Admin-verified').join('')}${row('Review notes', verified.reviewNotes)}</dl>`
      : '<p class="small text-muted mb-2">No admin-verified figures yet.</p>';
    if (data.status !== 'Under Review') {
      verifiedSection.innerHTML = `<h4 class="verified-title">Verified figures <small>(admin)</small></h4>${summary}<p class="small text-muted mb-0">${data.status === 'Resolved' ? 'Final: these verified figures are read-only.' : 'Verified figures can be entered or changed while the report is Under Review.'}</p>`;
      return;
    }
    const value = verified || {};
    const died = measure === 'head_count' || measure === 'bird_count';
    const figureFields = measure === 'crop_percent'
      ? verifyInput('verified_crop_damage_percent', 'Verified crop damage', value.cropDamagePercent, '%') + verifyInput('verified_planted_area_ha', 'Verified planted area (optional)', value.plantedAreaHa, 'hectares') + verifyInput('verified_affected_area_ha', 'Verified affected area (optional)', value.affectedAreaHa, 'hectares')
      : verifyInput('verified_total_before', `Verified total before the incident${died ? '' : ' (optional)'}`, value.totalBefore, unit) + verifyInput('verified_amount_lost', died ? 'Verified number that died' : 'Verified amount lost', value.amountLost, unit);
    const causes = (detailConfig[data.agricultural_category] || { causes: [] }).causes;
    verifiedSection.innerHTML = `<h4 class="verified-title">Verified figures <small>(filled by admin · same unit as the farmer: ${escapeHtml(unit === '%' ? 'percent of this crop' : unit)})</small></h4>${summary}`
      + `<form id="verifiedFiguresForm" class="verified-form" novalidate><input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>"><input type="hidden" name="report_id" value="${escapeHtml(data.id)}"><input type="hidden" name="action" value="save_verified_figures">`
      + `<div class="verified-grid"><div><label class="form-label fw-semibold" for="verify_verified_cause">Verified cause</label><select class="form-select" id="verify_verified_cause" name="verified_cause"><option value="">Select cause</option>${causes.map(cause => `<option value="${escapeHtml(cause)}"${value.cause === cause ? ' selected' : ''}>${escapeHtml(cause === 'Unsure' ? 'Undetermined (Unsure)' : cause)}</option>`).join('')}</select><div class="invalid-feedback d-block" data-error-for="verified_cause"></div></div>`
      + `<div id="verifiedCauseOtherWrap"${value.cause === 'Other' ? '' : ' hidden'}><label class="form-label fw-semibold" for="verify_verified_cause_other">Other cause (explain)</label><input class="form-control" id="verify_verified_cause_other" name="verified_cause_other" maxlength="255" value="${value.causeOther ? escapeHtml(value.causeOther) : ''}"><div class="invalid-feedback d-block" data-error-for="verified_cause_other"></div></div>`
      + `${figureFields}<div class="verified-notes"><label class="form-label fw-semibold" for="verify_review_notes">Review notes <small>(optional)</small></label><textarea class="form-control" id="verify_review_notes" name="review_notes" rows="2" maxlength="4000" placeholder="Add notes from field validation">${value.reviewNotes ? escapeHtml(value.reviewNotes) : ''}</textarea><div class="invalid-feedback d-block" data-error-for="review_notes"></div></div></div>`
      + `<div class="assessment-actions"><small>Saving verified figures does not change the report status or the farmer's submission.</small><button type="button" class="btn btn-light" id="copyFarmerFigures">Start from farmer's figures</button><button class="btn btn-outline-success" type="submit"><i class="bi bi-floppy" aria-hidden="true"></i> Save verified figures</button></div>`
      + `<div id="verifiedFeedback" class="alert py-2 mt-2" role="status" hidden></div></form>`;
    const form = document.getElementById('verifiedFiguresForm');
    const cause = form.querySelector('[name="verified_cause"]');
    cause.addEventListener('change', () => { document.getElementById('verifiedCauseOtherWrap').hidden = cause.value !== 'Other'; });
    document.getElementById('copyFarmerFigures').addEventListener('click', () => {
      const farmer = { verified_crop_damage_percent: details.cropDamagePercent, verified_planted_area_ha: details.plantedAreaHa, verified_affected_area_ha: details.affectedAreaHa, verified_total_before: details.totalBefore, verified_amount_lost: details.amountLost };
      for (const [name, figure] of Object.entries(farmer)) { const input = form.querySelector(`[name="${name}"]`); if (input) input.value = figure ?? ''; }
    });
    form.addEventListener('submit', saveVerified);
  };
  const saveVerified = async (event) => {
    event.preventDefault();
    if (verifySaving || activeReport?.status !== 'Under Review') return;
    const form = event.currentTarget;
    const feedback = document.getElementById('verifiedFeedback');
    verifySaving = true;
    form.querySelectorAll('button').forEach((button) => { button.disabled = true; });
    form.querySelectorAll('[data-error-for]').forEach((holder) => { holder.textContent = ''; });
    form.querySelectorAll('.is-invalid').forEach((input) => input.classList.remove('is-invalid'));
    try {
      const response = await fetch(window.location.href, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json' }, body: new FormData(form), cache: 'no-store' });
      if (!(response.headers.get('content-type') || '').includes('application/json')) throw new Error('Your admin session may have expired. Reload the page and sign in again.');
      const result = await response.json();
      if (!response.ok || !result.success) {
        for (const [name, message] of Object.entries(result.errors || {})) {
          const holder = form.querySelector(`[data-error-for="${name}"]`);
          const input = form.querySelector(`[name="${name}"]`);
          if (holder) holder.textContent = String(message);
          if (input) input.classList.add('is-invalid');
        }
        throw new Error(result.message || 'The verified figures could not be saved.');
      }
      const scrollTop = reportModalBody.scrollTop;
      if (result.changed) listNeedsRefresh = true;
      renderReport(result.report, activeReportIndex);
      reportModalBody.scrollTop = scrollTop;
      const saved = document.getElementById('verifiedFeedback');
      if (saved) { saved.className = 'alert alert-success py-2 mt-2'; saved.textContent = result.message; saved.hidden = false; }
    } catch (error) {
      feedback.className = 'alert alert-danger py-2 mt-2';
      feedback.textContent = error instanceof Error ? error.message : 'The verified figures could not be saved.';
      feedback.hidden = false;
    } finally {
      verifySaving = false;
      document.getElementById('verifiedFiguresForm')?.querySelectorAll('button').forEach((button) => { button.disabled = false; });
    }
  };
  const formatDistance = (meters) => {
    if (meters === null) return 'Could not calculate';
    if (meters < 1000) return `${meters < 1 ? meters.toFixed(2) : meters < 10 ? meters.toFixed(1) : Math.round(meters)} m`;
    return `${(meters / 1000).toFixed(meters < 10000 ? 2 : 1)} km`;
  };
  const validationLabels = { passed: 'Check passed', failed: 'Check failed', available: 'Available', 'not-checked': 'Not checked', unavailable: 'Unavailable', 'could-not-check': 'Could not check' };
  const validationItem = (label, state, reason) => {
    const stateLabel = validationLabels[state] || 'Could not check';
    return `<div class="validation-item ${state}"><div><strong>${escapeHtml(label)}</strong><span class="validation-state">${stateLabel}</span></div><p>${escapeHtml(reason)}</p></div>`;
  };
  const googleMapsUrl = (point) => `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(`${point[0].toFixed(7)},${point[1].toFixed(7)}`)}`;
  const destroyReportMap = () => {
    document.getElementById('reportMap').replaceChildren();
  };
  const roadButton = document.getElementById('roadMapButton');
  const satelliteButton = document.getElementById('satelliteMapButton');
  const setMapMode = (mode) => {
    mapMode = mode;
    roadButton.classList.toggle('active', mode === 'road');
    satelliteButton.classList.toggle('active', mode === 'satellite');
    roadButton.setAttribute('aria-pressed', String(mode === 'road'));
    satelliteButton.setAttribute('aria-pressed', String(mode === 'satellite'));
    if (activeReport && modalElement.classList.contains('show')) renderReportMap(activeReport);
  };
  roadButton.addEventListener('click', () => setMapMode('road'));
  satelliteButton.addEventListener('click', () => setMapMode('satellite'));
  let selectedPhotoId = null;
  let reviewPointKind = 'photo';
  const selectedReviewImage = (data) => (data.images || []).find(image => String(image.id) === String(selectedPhotoId)) || null;
  const renderReportMap = (data) => {
    destroyReportMap();
    const mapElement = document.getElementById('reportMap');
    const fallback = document.getElementById('mapFallback');
    const externalLink = document.getElementById('mapExternalLink');
    const fullMapLink = document.getElementById('mapOpenFullLink');
    mapElement.replaceChildren(); externalLink.hidden = true; externalLink.removeAttribute('href'); fullMapLink.hidden = true; fullMapLink.removeAttribute('href');
    const legend = mapElement.parentElement.querySelector('.map-legend');
    if (legend) legend.hidden = true;
    const image = selectedReviewImage(data);
    const preview = document.getElementById('reportSelectedPhotoPreview');
    if (preview) {
      preview.hidden = !image;
      if (image) {
        preview.querySelector('img').src = reportImageUrl(image.id);
        preview.querySelector('span').textContent = 'Photo ' + ((data.images || []).indexOf(image) + 1) + ' · ' + photoSourceLabel(image) + ' · Click to enlarge';
        preview.onclick = () => openImageViewer((data.images || []).indexOf(image), preview);
      } else { preview.querySelector('img').removeAttribute('src'); preview.onclick = null; }
    }
    const pointData = reviewPointKind === 'farm' ? [data.registered_latitude, data.registered_longitude]
      : reviewPointKind === 'report' ? [data.submitted_latitude, data.submitted_longitude]
      : [image?.gps_latitude, image?.gps_longitude];
    const evidence = pointEvidence(...pointData);
    const label = reviewPointKind === 'farm' ? 'Current registered farm point (not photo GPS)'
      : reviewPointKind === 'report' ? 'Main report point — ' + (data.location_source || 'source not recorded')
      : image ? 'Photo ' + ((data.images || []).indexOf(image) + 1) + ' · saved image ID ' + image.id + ' · ' + photoSourceLabel(image) : 'Selected photo';
    document.getElementById('selectedPhotoMapLabel').textContent = label;
    document.getElementById('selectedPhotoMapPoint').textContent = evidence.point ? formatPoint(evidence.point) : 'No usable saved photo GPS';
    document.getElementById('reportMapNote').textContent = reviewPointKind === 'photo'
      ? 'This map belongs to the selected saved photo. Main report and farm points are separate views; neither replaces missing photo GPS.'
      : 'Separate reference point. This is not the selected photo location or a verified farm boundary.';
    document.querySelectorAll('[data-select-photo-id]').forEach(button => button.setAttribute('aria-pressed', String(reviewPointKind === 'photo' && String(button.dataset.selectPhotoId) === String(selectedPhotoId))));
    if (!evidence.point) {
      mapElement.hidden = true; fallback.textContent = label + ': ' + evidence.reason + ' No previous marker is retained.'; return;
    }
    mapElement.hidden = false;
    const frame = document.createElement('iframe');
    frame.title = label; frame.width = '100%'; frame.height = '290'; frame.style.border = '0'; frame.referrerPolicy = 'no-referrer';
    frame.src = 'https://maps.google.com/maps?q=' + encodeURIComponent(evidence.point.join(',')) + '&z=17&t=' + (mapMode === 'satellite' ? 'k' : 'm') + '&output=embed';
    mapElement.appendChild(frame);
    externalLink.href = googleMapsUrl(evidence.point); externalLink.hidden = false;
    fallback.textContent = 'Google Maps preview of this saved point. If the frame is unavailable, open its exact coordinates below.';
  };
  document.getElementById('showMainReportPoint').addEventListener('click', () => { reviewPointKind = 'report'; if (activeReport) renderReportMap(activeReport); });
  document.getElementById('showRegisteredFarmPoint').addEventListener('click', () => { reviewPointKind = 'farm'; if (activeReport) renderReportMap(activeReport); });
  const selectReviewPhoto = (id) => {
    selectedPhotoId = id; reviewPointKind = 'photo';
    if (activeReport && modalElement.classList.contains('show')) renderReportMap(activeReport);
  };
  const renderResponseFields = () => {
    const rejected = status.value === 'Rejected';
    rejectionWrap.hidden = !rejected;
    rejectionReason.required = rejected;
    const indicator = document.getElementById('responseStatusIndicator');
    indicator.className = `report-status report-response-indicator ${String(status.value).toLowerCase().replace(/[^a-z]+/g, '-')}`;
    indicator.textContent = `Selected: ${status.value}`;
  };
  status.addEventListener('change', renderResponseFields);
  const reportImageUrl = (imageId) => `report-image.php?id=${encodeURIComponent(imageId)}`;
  const updateViewerZoomControls = () => {
    viewerZoomValue.textContent = `${Math.round(viewerZoom * 100)}%`;
    viewerZoomOut.disabled = !viewerImageLoaded || viewerZoom <= 1;
    viewerZoomReset.disabled = !viewerImageLoaded || viewerZoom === 1;
    viewerZoomIn.disabled = !viewerImageLoaded || viewerZoom >= 4;
  };
  const sizeViewerImage = (centerImage = false) => {
    if (!viewerImageLoaded || !viewerImage.naturalWidth || !viewerImage.naturalHeight) return;
    const canvasStyle = window.getComputedStyle(viewerCanvas);
    const horizontalPadding = parseFloat(canvasStyle.paddingLeft) + parseFloat(canvasStyle.paddingRight);
    const verticalPadding = parseFloat(canvasStyle.paddingTop) + parseFloat(canvasStyle.paddingBottom);
    const availableWidth = Math.max(1, viewerStage.clientWidth - horizontalPadding);
    const availableHeight = Math.max(1, viewerStage.clientHeight - verticalPadding);
    const fitScale = Math.min(availableWidth / viewerImage.naturalWidth, availableHeight / viewerImage.naturalHeight, 1);
    const width = Math.max(1, viewerImage.naturalWidth * fitScale * viewerZoom);
    const height = Math.max(1, viewerImage.naturalHeight * fitScale * viewerZoom);
    viewerImage.style.width = `${width}px`;
    viewerImage.style.height = `${height}px`;
    viewerCanvas.style.width = `${Math.max(viewerStage.clientWidth, width + horizontalPadding)}px`;
    viewerCanvas.style.height = `${Math.max(viewerStage.clientHeight, height + verticalPadding)}px`;
    if (centerImage) {
      window.requestAnimationFrame(() => viewerStage.scrollTo({
        left: Math.max(0, (viewerStage.scrollWidth - viewerStage.clientWidth) / 2),
        top: Math.max(0, (viewerStage.scrollHeight - viewerStage.clientHeight) / 2),
      }));
    }
    updateViewerZoomControls();
  };
  const setViewerZoom = (nextZoom) => {
    viewerZoom = Math.min(4, Math.max(1, Math.round(nextZoom * 4) / 4));
    sizeViewerImage(true);
  };
  const clearViewerLocationMap = () => {
    viewerLocationMap.replaceChildren();
    viewerLocationMap.hidden = true;
    viewerLocationLink.hidden = true;
    viewerLocationLink.removeAttribute('href');
  };
  const showViewerLocation = (image) => {
    clearViewerLocationMap();
    const evidence = pointEvidence(image.gps_latitude, image.gps_longitude);
    if (evidence.state !== 'passed') {
      viewerLocationText.textContent = `Photo ${viewerIndex + 1} (saved image ID ${image.id}): No usable saved photo GPS. ${evidence.reason}`;
      return;
    }
    const [latitude, longitude] = evidence.point;
    viewerLocationText.textContent = `Photo ${viewerIndex + 1} (saved image ID ${image.id}): ${photoSourceLabel(image)} — ${latitude.toFixed(7)}, ${longitude.toFixed(7)}. Photo location is not a verified farm boundary.`;
    viewerLocationLink.href = `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(`${latitude},${longitude}`)}`;
    viewerLocationLink.hidden = false;
    viewerLocationMap.hidden = false;
    const frame = document.createElement('iframe');
    frame.title = 'Photo ' + (viewerIndex + 1) + ' · saved image ID ' + image.id + ' · ' + photoSourceLabel(image);
    frame.width = '100%'; frame.height = '170'; frame.style.border = '0'; frame.referrerPolicy = 'no-referrer';
    frame.src = 'https://maps.google.com/maps?q=' + encodeURIComponent(latitude + ',' + longitude) + '&z=17&t=k&output=embed';
    viewerLocationMap.appendChild(frame);
  };
  const showViewerImage = (index) => {
    if (!viewerImages.length) return;
    viewerIndex = (index + viewerImages.length) % viewerImages.length;
    const image = viewerImages[viewerIndex];
    selectReviewPhoto(image.id);
    viewerZoom = 1;
    viewerImageLoaded = false;
    viewerImage.hidden = true;
    viewerImage.removeAttribute('src');
    viewerImage.alt = `Damage report image ${viewerIndex + 1} of ${viewerImages.length}`;
    viewerMessage.hidden = false;
    viewerMessage.classList.remove('error');
    viewerMessage.textContent = 'Loading image...';
    viewerCounter.textContent = `${viewerIndex + 1} of ${viewerImages.length}`;
    const hasSingleImage = viewerImages.length < 2;
    imageViewer.classList.toggle('single-image', hasSingleImage);
    viewerPrevious.hidden = hasSingleImage;
    viewerNext.hidden = hasSingleImage;
    showViewerLocation(image);
    updateViewerZoomControls();
    viewerImage.src = reportImageUrl(image.id);
  };
  const openImageViewer = (index, trigger) => {
    viewerImages = Array.isArray(activeReport?.images) ? activeReport.images : [];
    if (!viewerImages.length) return;
    viewerReturnFocus = trigger;
    reportBodyScrollTop = reportModalBody.scrollTop;
    reportModalDialog.setAttribute('aria-hidden', 'true');
    reportModalDialog.inert = true;
    imageViewer.hidden = false;
    document.body.classList.add('report-image-viewer-open');
    showViewerImage(index);
    window.requestAnimationFrame(() => document.getElementById('closeReportImageViewer').focus({ preventScroll: true }));
  };
  const closeImageViewer = (restoreFocus = true) => {
    if (imageViewer.hidden) return;
    clearViewerLocationMap();
    imageViewer.hidden = true;
    viewerImage.removeAttribute('src');
    viewerImage.hidden = true;
    viewerImageLoaded = false;
    reportModalDialog.inert = false;
    reportModalDialog.removeAttribute('aria-hidden');
    document.body.classList.remove('report-image-viewer-open');
    reportModalBody.scrollTop = reportBodyScrollTop;
    const focusTarget = viewerReturnFocus;
    viewerReturnFocus = null;
    if (restoreFocus) window.requestAnimationFrame(() => focusTarget?.focus({ preventScroll: true }));
  };
  viewerImage.addEventListener('load', () => {
    viewerImageLoaded = true;
    viewerMessage.hidden = true;
    viewerImage.hidden = false;
    sizeViewerImage(false);
  });
  viewerImage.addEventListener('error', () => {
    viewerImageLoaded = false;
    viewerImage.hidden = true;
    viewerMessage.hidden = false;
    viewerMessage.classList.add('error');
    viewerMessage.textContent = 'This image could not be loaded. It may be unavailable or you may no longer have access.';
    updateViewerZoomControls();
  });
  document.getElementById('closeReportImageViewer').addEventListener('click', () => closeImageViewer(true));
  viewerPrevious.addEventListener('click', () => showViewerImage(viewerIndex - 1));
  viewerNext.addEventListener('click', () => showViewerImage(viewerIndex + 1));
  viewerZoomOut.addEventListener('click', () => setViewerZoom(viewerZoom - .25));
  viewerZoomReset.addEventListener('click', () => setViewerZoom(1));
  viewerZoomIn.addEventListener('click', () => setViewerZoom(viewerZoom + .25));
  viewerStage.addEventListener('wheel', (event) => {
    if (!event.ctrlKey || !viewerImageLoaded) return;
    event.preventDefault();
    setViewerZoom(viewerZoom + (event.deltaY < 0 ? .25 : -.25));
  }, { passive: false });
  document.addEventListener('keydown', (event) => {
    if (imageViewer.hidden) return;
    if (event.key === 'Escape') {
      event.preventDefault();
      event.stopImmediatePropagation();
      closeImageViewer(true);
    } else if (event.key === 'ArrowLeft' && viewerImages.length > 1) {
      event.preventDefault();
      showViewerImage(viewerIndex - 1);
    } else if (event.key === 'ArrowRight' && viewerImages.length > 1) {
      event.preventDefault();
      showViewerImage(viewerIndex + 1);
    } else if (event.key === 'Tab') {
      const focusable = Array.from(imageViewer.querySelectorAll('button:not([hidden]):not(:disabled)'));
      if (!focusable.length) return;
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    }
  }, true);
  const photoSourceLabel = (image) => {
    const usable = pointEvidence(image.gps_latitude, image.gps_longitude).state === 'passed';
    if (!usable) return 'No usable photo GPS';
    return image.gps_source === 'device_capture' ? 'Fresh device GPS at capture'
      : image.gps_source === 'photo_exif' ? 'Original photo EXIF GPS'
      : image.gps_source === 'asset_location' ? 'Original library asset location'
      : 'Location source not recorded';
  };
  const renderImages = (data) => {
    const container = document.getElementById('reportImages');
    const metadata = document.getElementById('reportPhotoMetadata');
    if (!data.images?.length) {
      container.innerHTML = '<p class="evidence-empty mb-0">No damage image available.</p>';
      metadata.innerHTML = '<p class="weather-unavailable">No photo metadata is available because this report has no saved images.</p>';
      return;
    }
    metadata.innerHTML = data.images.map((image, index) => {
      const imagePoint = pointEvidence(image.gps_latitude, image.gps_longitude);
      const coordinateNote = imagePoint.state === 'passed'
        ? `Photo GPS metadata: ${formatPoint(imagePoint.point)} (range check passed; authenticity not verified)`
        : imagePoint.state === 'failed' ? `Photo GPS metadata: ${imagePoint.reason}` : 'Photo GPS metadata: Unavailable';
      const timestampNote = image.captured_at
        ? `${image.capture_time_source === 'camera_return' ? 'Camera returned (not exact shutter time)' : 'Photo timestamp'}: ${image.captured_at} UTC; source: ${image.capture_time_source || 'not recorded'}`
        : 'Photo timestamp: Unavailable';
      const accuracy = image.gps_accuracy_m != null ? `GPS accuracy: about ${Number(image.gps_accuracy_m)} m` : 'GPS accuracy: Unavailable';
      const availability = imagePoint.state === 'passed' ? (image.location_status || 'Not recorded') : 'No usable GPS';
      return `<div class="report-photo-metadata-item"><strong>Photo ${index + 1} — ${escapeHtml(photoSourceLabel(image))}</strong><span>GPS source: ${text(image.gps_source || 'Not recorded')}</span><span>${escapeHtml(timestampNote)}</span><span>${escapeHtml(coordinateNote)}</span><span>${escapeHtml(accuracy)}</span><span>GPS reading time: ${image.gps_recorded_at ? `${text(image.gps_recorded_at)} UTC` : 'Unavailable'}</span><span>Location availability: ${escapeHtml(availability)}</span><span>Photo authenticity: Not independently verified</span></div>`;
    }).join('');
    let selectedPreview = document.getElementById('reportSelectedPhotoPreview');
    if (!selectedPreview) {
      selectedPreview = document.createElement('button'); selectedPreview.id = 'reportSelectedPhotoPreview';
      selectedPreview.type = 'button'; selectedPreview.className = 'report-selected-photo';
      selectedPreview.innerHTML = '<img alt="Selected saved damage photo"><span></span>';
      container.before(selectedPreview);
    }
    container.innerHTML = data.images.map((image, index) => {
      return `<figure><button class="report-image-thumbnail" type="button" data-image-index="${index}" aria-label="Open damage report image ${index + 1} of ${data.images.length} in full-size viewer"><img src="${reportImageUrl(image.id)}" alt="Damage report image ${index + 1}"><span class="report-image-enlarge" aria-hidden="true"><i class="bi bi-arrows-fullscreen"></i></span></button><figcaption>Photo ${index + 1}<br>${escapeHtml(photoSourceLabel(image))}${image.location_status === 'poor_accuracy' ? '<br>Low GPS accuracy' : ''}</figcaption><button type="button" class="btn btn-light btn-sm mt-1 w-100" data-select-photo-id="${image.id}" aria-pressed="false">Show photo pin</button></figure>`;
    }).join('');
    container.querySelectorAll('.report-image-thumbnail').forEach((button) => button.addEventListener('click', () => openImageViewer(Number(button.dataset.imageIndex), button)));
    container.querySelectorAll('[data-select-photo-id]').forEach(button => button.addEventListener('click', () => selectReviewPhoto(button.dataset.selectPhotoId)));
    document.querySelectorAll('#reportImages img').forEach((image) => image.addEventListener('error', () => {
      const figure = image.closest('figure');
      if (figure) figure.innerHTML = '<div class="image-load-error">Image could not be loaded.</div><figcaption>Stored image reference is unavailable.</figcaption>';
    }, { once: true }));
  };
  const manilaTime = (value, utc = true) => {
    if (!value) return null;
    const date = new Date(utc ? String(value).replace(' ', 'T') + 'Z' : value);
    return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString('en-PH', { timeZone: 'Asia/Manila', year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }) + ' (Philippine time)';
  };
  const hasValue = (value) => value !== null && value !== undefined && value !== '';
  const renderWeather = (weather) => {
    const container = document.getElementById('reportWeatherDetails');
    const ruleContainer = document.getElementById('reportWeatherRule');
    if (!weather) {
      container.innerHTML = '<p class="weather-unavailable">No weather snapshot was recorded for this report (reports submitted before this feature have none; none is generated retroactively).</p>';
      ruleContainer.innerHTML = '<p class="weather-unavailable">No rule result is available.</p>';
      return;
    }
    if (weather.snapshot_status === 'Unavailable') {
      const unavailable = [row('Status', 'Weather unavailable'), row('Reason', weather.unavailable_reason || 'No reason was recorded.'), row('Reason code', weather.unavailable_code), row('Coordinates requested', hasValue(weather.requested_latitude) ? `${Number(weather.requested_latitude).toFixed(6)}, ${Number(weather.requested_longitude).toFixed(6)}` : null), row('Report submitted', manilaTime(weather.report_submitted_at_utc)), row('Retrieval attempted', manilaTime(weather.retrieved_at_utc)), row('Provider', weather.provider)];
      container.innerHTML = `<p class="weather-unavailable">Weather unavailable. The report was saved normally; no weather values were recorded or estimated.</p><dl class="mb-0">${unavailable.join('')}</dl>`;
      ruleContainer.innerHTML = '<p class="weather-unavailable">No rule result because no weather data was available.</p>';
      return;
    }
    const legacy = !weather.provider;
    const units = weather.units || {};
    const details = [];
    if (legacy) details.push(row('Record type', 'Legacy record without provider, coordinates or retrieval time. Not verifiable.'));
    if (weather.weather_condition) details.push(row('Weather condition', weather.weather_condition));
    if (hasValue(weather.temperature)) details.push(row('Temperature', `${weather.temperature} ${units.temperature || '°C'}`));
    if (hasValue(weather.apparent_temperature)) details.push(row('Apparent temperature', `${weather.apparent_temperature} ${units.temperature || '°C'}`));
    if (hasValue(weather.humidity)) details.push(row('Humidity', `${weather.humidity} ${units.humidity || '%'}`));
    if (hasValue(weather.rainfall)) details.push(row('Precipitation (current interval)', legacy ? weather.rainfall : `${weather.rainfall} ${units.precipitation || 'mm'}`));
    if (hasValue(weather.precipitation_mm)) details.push(row('Precipitation (latest hour)', `${weather.precipitation_mm} ${units.precipitation || 'mm'}`));
    if (hasValue(weather.precipitation_last_6h_mm)) details.push(row('Precipitation (last 6 hours)', `${weather.precipitation_last_6h_mm} ${units.precipitation || 'mm'}`));
    if (weather.wind_information) details.push(row('Wind', weather.wind_information));
    if (!legacy) {
      details.push(row('Provider', `${weather.provider} · ${weather.observation_type || 'observation type not recorded'}`));
      details.push(row('Coordinates requested', hasValue(weather.requested_latitude) ? `${Number(weather.requested_latitude).toFixed(6)}, ${Number(weather.requested_longitude).toFixed(6)} (${weather.location_basis === 'damage_point' ? 'confirmed damage point' : weather.location_basis || 'basis not recorded'})` : null));
      details.push(row('Provider grid point', hasValue(weather.provider_latitude) ? `${weather.provider_latitude}, ${weather.provider_longitude}${hasValue(weather.provider_distance_km) ? ` (${weather.provider_distance_km} km from the damage point)` : ''}. A model grid cell value, not a measurement at the damage point.` : null));
      details.push(row('Observation time', manilaTime(weather.observed_at_utc)));
      details.push(row('Retrieved at', manilaTime(weather.retrieved_at_utc)));
      details.push(row('Report submitted (server)', manilaTime(weather.report_submitted_at_utc)));
    } else if (weather.recorded_at) {
      details.push(row('Observation time', weather.recorded_at));
    }
    container.innerHTML = `<dl class="mb-0">${details.join('')}</dl>`;

    const rule = weather.rule_result;
    if (!rule) {
      ruleContainer.innerHTML = '<p class="weather-unavailable">No rule result was recorded for this snapshot.</p>';
      return;
    }
    const matched = (rule.matched || []).map((item) => `<li><strong>${escapeHtml(item.label)}</strong> · measured: ${escapeHtml(item.measured)} · threshold: ${escapeHtml(item.threshold)}</li>`).join('');
    const actions = (rule.recommendations || []).map((item) => `<div class="mb-2"><strong>${escapeHtml(item.conditionLabel)}</strong> for ${escapeHtml(item.category)}<ul class="mb-0">${(item.actions || []).map((action) => `<li>${escapeHtml(action)}</li>`).join('')}</ul></div>`).join('');
    const skipped = (rule.notEvaluated || []).map((item) => `<li>${escapeHtml(item.label)}: ${escapeHtml(item.reason)}</li>`).join('');
    // Snapshots saved before rule set 2026.10-2 have no "assessment": they are shown as recorded and are NOT re-evaluated.
    const legacyRule = !rule.assessment;
    const resultText = legacyRule
      ? `Recorded under earlier rule set ${rule.ruleSetVersion || 'unknown'} and not re-evaluated. ${rule.normal ? 'That version printed "Normal conditions" even when some checks could not be made, so treat it as "no threshold triggered" only.' : 'A threshold was matched.'}`
      : rule.headline;
    const calc = rule.calculations || {};
    const heatRow = hasValue(calc.heatIndexC) ? row('Estimated heat index', `${calc.heatIndexC} °C (${calc.heatIndexCategoryLabel}). AgriReach estimate from air temperature and humidity, not PAGASA's value.`) : null;
    const limits = (rule.limitations || []).map((item) => `<li>${escapeHtml(item.label)}: ${escapeHtml(item.reason)}</li>`).join('');
    ruleContainer.innerHTML = `<dl class="mb-2">${[row('Source', `${rule.source} (${rule.method})`), row('Rule set version', rule.ruleSetVersion), row('Category applied', rule.category), row('Result', resultText), legacyRule ? null : row('Meaning', rule.statement), heatRow].filter(Boolean).join('')}</dl>${matched ? `<ul class="mb-2">${matched}</ul>` : ''}${actions}${limits ? `<p class="small text-muted mb-1">Checks that could not be made or data that disagreed:</p><ul class="small text-muted mb-0">${limits}</ul>` : (skipped ? `<p class="small text-muted mb-1">Checks that could not be made:</p><ul class="small text-muted mb-0">${skipped}</ul>` : '')}<p class="small text-muted mt-2 mb-0">${escapeHtml(rule.disclaimer || 'AgriReach rules are project-defined and not official PAGASA warnings.')}</p>`;
  };
  const renderValidation = (data) => {
    const submittedEvidence = pointEvidence(data.submitted_latitude, data.submitted_longitude);
    const registeredEvidence = pointEvidence(data.registered_latitude, data.registered_longitude);
    const images = Array.isArray(data.images) ? data.images : [];
    const imagePoints = images.map((image) => pointEvidence(image.gps_latitude, image.gps_longitude));
    const invalidImagePoints = imagePoints.filter((item) => item.state === 'failed').length;
    const availableImagePoints = imagePoints.filter((item) => item.state === 'passed').length;
    const serverTimestampAvailable = typeof data.submitted_at === 'string' && data.submitted_at.trim() !== '' && !Number.isNaN(Date.parse(data.submitted_at.replace(' ', 'T')));
    const suppliedPhotoTimestamps = images.filter((image) => image.captured_at).length;
    const checks = [
      ['Coordinates', submittedEvidence.state === 'unavailable' ? 'could-not-check' : submittedEvidence.state, submittedEvidence.reason],
      ['Farm point', registeredEvidence.state === 'unavailable' ? 'could-not-check' : registeredEvidence.state, registeredEvidence.reason],
      ['Boundary', 'not-checked', 'No farm boundary geometry is stored. Point distance does not prove that damage occurred inside the farm boundary.'],
      ['Photo GPS', invalidImagePoints > 0 ? 'failed' : availableImagePoints > 0 ? 'available' : 'unavailable', invalidImagePoints > 0
        ? `${invalidImagePoints} image coordinate record(s) are incomplete or outside valid ranges.`
        : availableImagePoints > 0 ? `${availableImagePoints} image(s) have stored coordinates. Review each photo's source, accuracy and availability below. These readings do not prove authenticity.`
          : images.length ? 'No photo GPS metadata was stored.' : 'No damage images are stored.'],
      ['Server time', serverTimestampAvailable ? 'passed' : 'could-not-check', serverTimestampAvailable
        ? `Recorded by the AgriReach server at ${data.submitted_at}.` : 'No server submission timestamp is available.'],
      ['Photo time', 'unavailable', suppliedPhotoTimestamps > 0
        ? `${suppliedPhotoTimestamps} app-supplied timestamp(s) exist. The source is listed per photo; camera-return time is not an exact shutter time. Actual capture time is not independently verified.`
        : 'No photo capture timestamp metadata is available.'],
    ];
    document.getElementById('reportValidationSummary').innerHTML = checks.map(([label, state]) => `<div class="report-check"><span>${escapeHtml(label)}</span><strong class="check-state ${state}">${escapeHtml(validationLabels[state])}</strong></div>`).join('');
    document.getElementById('reportValidationDetails').innerHTML = checks.map(([label, state, reason]) => validationItem(label, state, reason)).join('');
  };
  modalElement.addEventListener('shown.bs.modal', () => {
    if (!activeReport || activeTab !== 'photos') return;
    window.requestAnimationFrame(() => {
      renderReportMap(activeReport);
    });
  });
  window.addEventListener('resize', () => {
    if (!imageViewer.hidden && viewerImageLoaded) sizeViewerImage(false);
  });
  const responseDraft = () => ({ status: status.value, message: responseMessageField.value, reason: rejectionReason.value, assessedCost: assessmentCost.value, assessmentRemarks: assessmentRemarks.value });
  const hasUnsavedResponse = () => responseBaseline && JSON.stringify(responseDraft()) !== JSON.stringify(responseBaseline);
  const canDiscardResponse = () => {
    if (assessmentSaving) {
      window.alert('The assessment is still saving. Please wait.');
      return false;
    }
    return !hasUnsavedResponse() || window.confirm('You have unsaved assessment or response changes. Discard them?');
  };
  const updateReportNavigation = () => {
    previousReportButton.disabled = reportLoading || assessmentSaving || activeReportIndex <= 0;
    nextReportButton.disabled = reportLoading || assessmentSaving || activeReportIndex < 0 || activeReportIndex >= filteredReportCount - 1;
    document.getElementById('reportPosition').textContent = activeReportIndex < 0 ? '' : `${activeReportIndex + 1} of ${filteredReportCount}`;
  };
  const rsbsaCodePattern = /^\d{2}-\d{2}-\d{2}-\d{3}-\d{6}-[1-9]\d*$/;
  const peso = (value) => value !== null && value !== undefined && value !== ''
    ? `₱${Number(value).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}` : null;
  // Category-specific reports are final once Resolved with remarks and admin-verified figures (no peso
  // amount); older reports keep their rule of a final assessed cost and remarks.
  const reportIsFinal = (data) => data.status === 'Resolved' && Boolean(String(data.assessment_remarks || '').trim())
    && (data.details ? Boolean(data.details.verified) : peso(data.final_assessed_cost) !== null);
  const locationSourceLabel = (data) => data.location_source === 'photo' ? `Farmer-confirmed from photo ${data.location_photo_order || '?'} GPS`
    : data.location_source === 'device' ? 'Farmer-confirmed device GPS'
    : data.location_source === 'manual' ? 'Farmer-confirmed manual map pin' : 'Not recorded (older report)';
  const renderOverview = (data, cost) => {
    const details = data.details;
    const farmLocation = [data.farm_barangay, data.farm_city, data.farm_province].filter(Boolean).join(', ');
    document.getElementById('reportFarmerDetails').innerHTML = [row('Farmer name', data.full_name), row('RSBSA reference', data.rsbsa_reference || 'Not on file'), row('Contact number', data.contact_number), row('Email', data.email), row('Farm name', data.farm_name), row('Farm location', farmLocation || data.farm_address)].join('');
    document.getElementById('reportFarmDetails').innerHTML = (details
      ? [row('Category', `${data.agricultural_category} · ${details.subjectType}`), row('Farmer suspected cause', details.suspectedCauseLabel), row('Reported damage (farmer)', details.summary)]
      : [row('Category', data.agricultural_category), row('Damage type (older report)', data.damage_type), row('Farmer peso estimate', cost)]
    ).concat([row('Status', data.status), row('Submitted', data.submitted_at ? `${data.submitted_at} (Philippine time)` : null)]).join('');
    document.getElementById('reportOverviewDescription').textContent = String(data.damage_description || '').trim() || 'Not provided';
    const images = Array.isArray(data.images) ? data.images : [];
    document.getElementById('reportOverviewPhotoCount').textContent = `(${images.length})`;
    const preview = document.getElementById('reportOverviewPhotos');
    preview.innerHTML = images.length ? images.slice(0, 3).map((image, index) => `<button class="report-image-thumbnail" type="button" data-overview-image="${index}" aria-label="Open photo ${index + 1} of ${images.length} full size"><img src="${reportImageUrl(image.id)}" alt="Damage photo ${index + 1}"><span class="report-image-enlarge" aria-hidden="true"><i class="bi bi-arrows-fullscreen"></i></span></button>`).join('')
      + (images.length > 3 ? `<button type="button" class="report-more-photos" data-go-tab-inline="photos">+${images.length - 3} more</button>` : '')
      : '<p class="evidence-empty mb-0">No damage photos were saved with this report.</p>';
    preview.querySelectorAll('[data-overview-image]').forEach((button) => button.addEventListener('click', () => openImageViewer(Number(button.dataset.overviewImage), button)));
    preview.querySelector('[data-go-tab-inline]')?.addEventListener('click', () => showTab('photos'));
    preview.querySelectorAll('img').forEach((image) => image.addEventListener('error', () => { image.closest('button')?.replaceWith(Object.assign(document.createElement('div'), { className: 'image-load-error', textContent: 'Image could not be loaded.' })); }, { once: true }));
    const withGps = images.filter((image) => pointEvidence(image.gps_latitude, image.gps_longitude).state === 'passed').length;
    document.getElementById('reportEvidenceSummary').textContent = `Damage point: ${locationSourceLabel(data)}. Photo GPS saved on ${withGps} of ${images.length} photo${images.length === 1 ? '' : 's'}. Farm boundary: Not checked. Weather snapshot: ${data.weather ? (data.weather.snapshot_status === 'Unavailable' ? 'Unavailable' : 'Recorded') : 'Not recorded'}.`;
  };
  const renderHistory = (data) => {
    const events = Array.isArray(data.history) ? data.history : [];
    document.getElementById('reportHistory').innerHTML = events.length
      ? events.map((event) => `<tr><td>${text(event.at ? `${event.at}` : null)}</td><td>${escapeHtml(event.event)}</td><td>${event.status ? `<span class="report-status ${String(event.status).toLowerCase().replace(/[^a-z]+/g, '-')}">${escapeHtml(event.status)}</span>` : '—'}</td><td class="report-history-notes">${text(event.notes)}</td><td>${text(event.by)}</td></tr>`).join('')
      : '<tr><td colspan="5" class="text-muted">No review events have been recorded.</td></tr>';
  };
  const renderReport = (data, index) => {
    destroyReportMap();
    activeReport = data;
    const previousPhoto = (data.images || []).find(image => String(image.id) === String(selectedPhotoId));
    // location_photo_order is the 1-based position of the photo in the saved (ordered) list.
    const mainPhoto = data.location_photo_order ? (data.images || [])[Number(data.location_photo_order) - 1] : null;
    selectedPhotoId = (previousPhoto || mainPhoto || data.images?.[0])?.id ?? null;
    reviewPointKind = 'photo';
    activeReportIndex = index;
    reportLoadMessage.hidden = true;
    const submittedEvidence = pointEvidence(data.submitted_latitude, data.submitted_longitude);
    const registeredEvidence = pointEvidence(data.registered_latitude, data.registered_longitude);
    const pointDistance = pointDistanceMeters(submittedEvidence.point, registeredEvidence.point);
    const cost = peso(data.estimated_damage_cost) || (data.details ? 'Not requested (category-specific report)' : 'Not available');
    const assessedCost = peso(data.final_assessed_cost) || 'Not available';
    const isFinal = reportIsFinal(data);
    document.getElementById('reportDetailsNumber').textContent = data.report_code || 'Not available';
    document.getElementById('reportDetailsCodeNote').textContent = rsbsaCodePattern.test(String(data.report_code || '')) ? 'RSBSA reference + report sequence' : 'Earlier report reference (kept unchanged)';
    document.getElementById('reportDetailsSubmitted').textContent = data.submitted_at ? `Submitted: ${data.submitted_at} (Philippine time)` : 'Submitted: Not available';
    const statusBadge = document.getElementById('reportDetailsStatus');
    statusBadge.className = `report-status ${String(data.status || '').toLowerCase().replace(/[^a-z]+/g, '-')}`;
    statusBadge.textContent = data.status || 'Not available';
    renderOverview(data, cost);
    const submission = submissionHtml(data, cost);
    renderVerified(data);
    document.getElementById('reportWhatHappened').innerHTML = submission || `<div class="report-assessment-top"><div class="report-fact"><small>Damage Type</small><strong>${text(data.damage_type)}</strong></div><div class="report-fact report-cost"><small>Farmer Estimate</small><strong>${escapeHtml(cost)}</strong><span>Original submission; never changed by the admin.</span></div></div><div class="report-fact report-description"><small>Damage Description (from farmer)</small><p>${text(data.damage_description)}</p></div>`;
    document.getElementById('reportAddress').textContent = data.submitted_address || 'Not available';
    document.getElementById('reportDamagePoint').textContent = `${formatPoint(submittedEvidence.point)} · ${locationSourceLabel(data)}`;
    document.getElementById('reportFarmPoint').textContent = registeredEvidence.point ? `${formatPoint(registeredEvidence.point)} · current registered farm point (not photo GPS)` : 'Not recorded';
    document.getElementById('reportMapNote').textContent = pointDistance === null
      ? 'Point-to-point distance could not be calculated; a saved coordinate pair is missing.'
      : pointDistance < 2
        ? `Saved damage and current farm points nearly overlap (${formatDistance(pointDistance)} apart). No farm boundary was checked.`
        : `Damage and current registered farm points are ${formatDistance(pointDistance)} apart. This is not a farm-boundary check.`;
    document.getElementById('reportPhotoCount').textContent = `(${Array.isArray(data.images) ? data.images.length : 0})`;
    document.getElementById('reportLocationDetails').innerHTML = [row('Submitted address / landmark', data.submitted_address), row('Saved damage latitude, longitude', formatPoint(submittedEvidence.point)), row('Damage point source', data.location_source || 'Not recorded for this older report'), row('Main location photo', data.location_photo_order ? `Photo ${data.location_photo_order}` : 'Not linked to a photo'), row('Farmer confirmation recorded', data.location_confirmed_at), row('Current registered farm latitude, longitude', formatPoint(registeredEvidence.point)), row('Distance between points', formatDistance(pointDistance))].join('');
    document.getElementById('reportInfoDetails').innerHTML = [row('Report ID', data.report_code), row('Report sequence (per farmer)', data.report_sequence ? String(data.report_sequence) : 'None (earlier report)'), row('Server submission timestamp', data.submitted_at), row('Agricultural category', data.agricultural_category), row('Damage type / suspected cause', data.damage_type), row('Farmer-estimated cost', cost), row('Current status', data.status), row('Stored distance at submission (km, rounded)', data.distance_from_registered_farm), row('GPS validation status', data.gps_validation_status || 'Not checked'), row('GPS validation result', data.gps_validation_result || 'Not available'), row('Image metadata validation status', data.image_metadata_validation_status || 'Not checked'), row('Timestamp validation status', data.timestamp_validation_status || 'Not checked'), row('Validation warning', data.validation_warning || 'None recorded')].join('');
    document.getElementById('reportAdminHistory').innerHTML = [...(data.details ? [row('Admin-verified loss', data.details.verified?.summary || 'Not verified yet'), row('Verified cause', data.details.verified?.causeLabel), row('Verified by', data.details.verified?.verifiedBy), row('Verification date', data.details.verified?.verifiedAt)] : []), row('Final assessed cost', assessedCost), row('Assessment remarks', data.assessment_remarks), row('Assessed by', data.assessed_by_name), row('Assessment date', data.assessed_at), row('Resolution date', data.resolved_at), row('Latest admin response', data.admin_response_message), row('Latest rejection reason', data.admin_rejection_reason), row('Latest notification message', data.admin_notification_message), row('Latest response date', data.admin_response_at)].join('');
    renderImages(data);
    renderWeather(data.weather || null);
    renderValidation(data);
    renderHistory(data);
    document.getElementById('responseReportId').value = data.id;
    document.getElementById('assessmentReportId').value = data.id;
    const pdfHref = `damage-reports-pdf.php?mode=single&report_id=${encodeURIComponent(data.id)}`;
    downloadReportPdf.href = pdfHref;
    downloadReportPdf.innerHTML = `<i class="bi bi-printer" aria-hidden="true"></i> Print / ${isFinal ? 'Final' : 'Draft'} PDF`;
    const footerPdf = document.getElementById('footerReportPdf');
    footerPdf.href = pdfHref;
    footerPdf.innerHTML = `<i class="bi bi-printer" aria-hidden="true"></i> Print / ${isFinal ? 'Final' : 'Draft'} PDF`;
    document.getElementById('reportFooterNote').textContent = isFinal ? 'Final: the admin assessment is complete.' : 'Not final: a PDF prints as a pending draft with unverified fields left blank.';
    const categoryReport = Boolean(data.details);
    const verifiedSaved = Boolean(data.details?.verified);
    document.getElementById('assessmentCostWrap').hidden = categoryReport;
    document.getElementById('assessmentRuleNote').textContent = categoryReport
      ? '(category-specific report: resolved on the admin-verified figures above; no peso amount is required or calculated)'
      : '(older report: a final assessed cost and remarks are required to resolve)';
    const resolveButton = document.getElementById('resolveReportButton');
    resolveButton.disabled = categoryReport && !verifiedSaved;
    document.getElementById('assessmentActionNote').textContent = categoryReport && !verifiedSaved
      ? 'Save the admin-verified cause and figures first. Saving a draft does not change the report status.'
      : 'Saving a draft does not change the report status.';
    assessmentForm.hidden = data.status !== 'Under Review';
    const assessmentReadOnly = document.getElementById('assessmentReadOnly');
    assessmentReadOnly.hidden = data.status === 'Under Review';
    document.getElementById('assessmentSavedCostWrap').hidden = categoryReport && data.final_assessed_cost === null;
    document.getElementById('assessmentSavedCost').textContent = assessedCost;
    document.getElementById('assessmentSavedRemarks').textContent = data.assessment_remarks || 'Not available';
    document.getElementById('assessmentSavedBy').textContent = data.assessed_by_name || 'Not available';
    document.getElementById('assessmentSavedAt').textContent = data.assessed_at || 'Not available';
    document.getElementById('assessmentReadOnlyNote').textContent = isFinal ? 'This assessment is final and read-only.' : 'The assessment can be edited after the report is moved to Under Review. Nothing is final yet.';
    assessmentCost.value = data.final_assessed_cost ?? '';
    assessmentRemarks.value = data.assessment_remarks ?? '';
    assessmentFeedback.hidden = true;
    assessmentFeedback.textContent = '';
    for (const field of [assessmentCost, assessmentRemarks]) {
      field.classList.remove('is-invalid');
      field.removeAttribute('aria-invalid');
    }
    document.getElementById('assessmentCostError').textContent = '';
    document.getElementById('assessmentRemarksError').textContent = '';
    document.getElementById('reportOtherActions').hidden = data.status === 'Resolved';
    document.getElementById('reportOtherActions').open = false;
    status.value = data.status === 'Resolved' ? 'Under Review' : data.status;
    responseMessageField.value = '';
    rejectionReason.value = '';
    responseBaseline = responseDraft();
    renderResponseFields();
    document.getElementById('reportCheckDisclosure').open = false;
    reportModalBody.scrollTop = 0;
    updateReportNavigation();
    if (modalElement.classList.contains('show') && activeTab === 'photos') window.requestAnimationFrame(() => renderReportMap(data));
  };
  assessmentForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    if (assessmentSaving || activeReport?.status !== 'Under Review') return;
    if (reportLoading) {
      assessmentFeedback.className = 'alert alert-warning py-2';
      assessmentFeedback.textContent = 'Wait for the next report to finish loading before saving an assessment.';
      assessmentFeedback.hidden = false;
      return;
    }
    const action = event.submitter?.value === 'resolve_report' ? 'resolve_report' : 'save_assessment';
    const resolveQuestion = activeReport?.details
      ? 'Mark this report as Resolved with the saved admin-verified figures and these remarks?'
      : 'Mark this report as Resolved with the entered final cost and remarks?';
    if (action === 'resolve_report' && !window.confirm(resolveQuestion)) return;
    assessmentSaving = true;
    updateReportNavigation();
    const buttons = Array.from(assessmentForm.querySelectorAll('button[type="submit"]'));
    buttons.forEach((button) => { button.disabled = true; });
    assessmentFeedback.hidden = true;
    for (const field of [assessmentCost, assessmentRemarks]) { field.classList.remove('is-invalid'); field.removeAttribute('aria-invalid'); }
    document.getElementById('assessmentCostError').textContent = '';
    document.getElementById('assessmentRemarksError').textContent = '';
    try {
      const body = new FormData(assessmentForm);
      body.set('action', action);
      const response = await fetch(window.location.href, { method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json' }, body, cache: 'no-store' });
      if (!(response.headers.get('content-type') || '').includes('application/json')) throw new Error('Your admin session may have expired. Reload the page and sign in again.');
      const result = await response.json();
      if (!response.ok || !result.success) {
        for (const [name, message] of Object.entries(result.errors || {})) {
          const field = name === 'final_assessed_cost' ? assessmentCost : name === 'assessment_remarks' ? assessmentRemarks : null;
          const error = name === 'final_assessed_cost' ? document.getElementById('assessmentCostError') : name === 'assessment_remarks' ? document.getElementById('assessmentRemarksError') : null;
          if (field && error) { field.classList.add('is-invalid'); field.setAttribute('aria-invalid', 'true'); error.textContent = String(message); }
        }
        throw new Error(result.errors?.verified_figures || result.message || 'The assessment could not be saved.');
      }
      const scrollTop = reportModalBody.scrollTop;
      if (result.changed) listNeedsRefresh = true;
      const nextIndex = reportFilters.status && reportFilters.status !== result.report.status ? -1 : activeReportIndex;
      renderReport(result.report, nextIndex);
      reportModalBody.scrollTop = scrollTop;
      assessmentFeedback.className = 'alert alert-success py-2';
      assessmentFeedback.textContent = result.message;
      assessmentFeedback.hidden = false;
    } catch (error) {
      assessmentFeedback.className = 'alert alert-danger py-2';
      assessmentFeedback.textContent = error instanceof Error ? error.message : 'The assessment could not be saved. Please try again.';
      assessmentFeedback.hidden = false;
    } finally {
      assessmentSaving = false;
      buttons.forEach((button) => { button.disabled = false; });
      updateReportNavigation();
    }
  });
  const guardPdfExport = (event) => {
    if (assessmentSaving) {
      event.preventDefault();
      window.alert('The assessment is still saving. Please wait before exporting.');
    } else if (hasUnsavedResponse() && !window.confirm('This PDF uses the last saved report. Unsaved assessment or response changes will not appear. Continue?')) {
      event.preventDefault();
    }
  };
  downloadReportPdf.addEventListener('click', guardPdfExport);
  document.getElementById('footerReportPdf').addEventListener('click', guardPdfExport);
  const navigateReport = async (direction) => {
    const nextIndex = activeReportIndex + direction;
    if (reportLoading || nextIndex < 0 || nextIndex >= filteredReportCount || !canDiscardResponse()) return;
    const fromReportId = activeReport?.id;
    const startingDraft = JSON.stringify(responseDraft());
    reportLoading = true;
    updateReportNavigation();
    reportLoadMessage.hidden = true;
    try {
      const query = new URLSearchParams({ ...reportFilters, index: String(nextIndex) });
      const response = await fetch(`damage-report-detail-data.php?${query}`, { credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store' });
      if (!(response.headers.get('content-type') || '').includes('application/json')) throw new Error('Your admin session may have expired. Reload the page and sign in again.');
      const result = await response.json();
      if (!response.ok || !result.success || !result.report) throw new Error(result.message || 'The report could not be loaded.');
      if (!modalElement.classList.contains('show') || activeReport?.id !== fromReportId) return;
      if (JSON.stringify(responseDraft()) !== startingDraft && !canDiscardResponse()) return;
      filteredReportCount = result.total;
      renderReport(result.report, result.index);
    } catch (error) {
      reportLoadMessage.textContent = error instanceof Error ? error.message : 'The report could not be loaded. Please try again.';
      reportLoadMessage.hidden = false;
    } finally {
      reportLoading = false;
      updateReportNavigation();
    }
  };
  previousReportButton.addEventListener('click', () => void navigateReport(-1));
  nextReportButton.addEventListener('click', () => void navigateReport(1));
  modalElement.addEventListener('hide.bs.modal', (event) => { if (!canDiscardResponse()) event.preventDefault(); });
  // Closing returns to the same list page; the row that opened the report gets focus back. A saved change
  // reloads this page (same filters and page number) so the list shows the current status.
  modalElement.addEventListener('hidden.bs.modal', () => {
    closeImageViewer(false); destroyReportMap(); activeReport = null; activeReportIndex = -1; responseBaseline = null;
    if (listNeedsRefresh) { window.location.reload(); return; }
    if (reportTrigger?.isConnected) reportTrigger.focus({ preventScroll: false });
  });
  document.querySelectorAll('.view-report-btn').forEach((button, pageIndex) => button.addEventListener('click', () => {
    const data = JSON.parse(document.getElementById(`report-data-${button.dataset.reportKey}`).textContent);
    reportTrigger = button;
    showTab('overview');
    renderReport(data, firstReportIndex + pageIndex);
    modal.show();
  }));
});
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
