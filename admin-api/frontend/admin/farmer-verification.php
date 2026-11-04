<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/../../backend/lib/farmer_verification.php';
require_once __DIR__ . '/includes/pagination.php';
require_admin();
require_once __DIR__ . '/../../backend/lib/admin_farms.php';

$search = is_string($_GET['search'] ?? null) ? trim($_GET['search']) : '';
$allowed_statuses = ['Pending', 'Approved', 'Rejected'];
$status = (string) ($_GET['status'] ?? '');
$status = in_array($status, $allowed_statuses, true) ? $status : '';
$farm_type = is_string($_GET['farm_type'] ?? null) ? $_GET['farm_type'] : '';
$list_filters = ['search' => $search, 'status' => $status, 'farm_type' => $farm_type];
$return_url = admin_page_url('farmer-verification.php', $list_filters, admin_requested_page($_GET['page'] ?? null));

$csrf_token = admin_csrf_token();
$message = '';
$message_type = 'success';
$redirect_after_post = false;
$verification_email_configuration = registration_email_configuration();
$development_email_routing = $verification_email_configuration['app_environment'] !== 'production';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted_token = (string) ($_POST['csrf_token'] ?? '');
    $farmer_id = filter_var($_POST['farmer_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $action = (string) ($_POST['action'] ?? '');

    if (!hash_equals($csrf_token, $posted_token)) {
        $message = 'Your session token is invalid. Please try again.';
        $message_type = 'danger';
    } elseif ($farmer_id === false || !in_array($action, ['approve', 'reject', 'retry_email'], true)) {
        $message = 'Invalid verification request.';
        $message_type = 'danger';
    } else {
        try {
            $connection = db();
            $admin_id = (int) $_SESSION['admin_id'];

            if ($action === 'retry_email') {
                $development_test_recipient = null;
                if ($development_email_routing) {
                    $development_test_recipient = strtolower(trim((string) ($_POST['test_recipient'] ?? '')));
                    if (!filter_var($development_test_recipient, FILTER_VALIDATE_EMAIL)) {
                        throw new InvalidArgumentException('Enter a valid development test recipient. The real farmer will not be emailed while development safety routing is active.');
                    }
                }
                $farmer = verification_email_retry_data($connection, (int) $farmer_id);
                if (!$farmer) {
                    $message = 'There is no failed decision email available to retry for this farmer.';
                    $message_type = 'info';
                } else {
                    $delivery = deliver_and_log_verification_email(
                        $connection,
                        $farmer,
                        $admin_id,
                        (string) $farmer['verification_status'],
                        $farmer['verification_status'] === 'Rejected' ? (string) $farmer['rejection_reason'] : null,
                        $development_test_recipient,
                        (int) $farmer['failed_delivery_id']
                    );
                    if ($delivery['status'] === 'Sent') {
                        $message = $delivery['development_routed']
                            ? 'The registration decision email was accepted for the explicit development test recipient. The real farmer was not emailed.'
                            : 'The registration decision email was accepted by the SMTP server.';
                    } elseif ($delivery['status'] === 'Simulated') {
                        $message = 'The registration decision email was prepared using the local test transport. No real email was sent.';
                        $message_type = 'info';
                    } else {
                        $message = verification_email_failure_message($delivery['failure_code']) . ' The verification decision remains saved and the in-app notification was not repeated.';
                        $message_type = 'warning';
                    }
                }
            } else {
                $result = change_farmer_verification(
                    $connection,
                    (int) $farmer_id,
                    $admin_id,
                    $action,
                    $action === 'reject' ? (string) ($_POST['rejection_reason'] ?? '') : null
                );

                if (!$result['changed']) {
                    $current_status = in_array((string) ($result['current_status'] ?? ''), ['Approved', 'Rejected'], true)
                        ? (string) $result['current_status']
                        : 'already decided';
                    $message = 'This registration was already decided as ' . $current_status . ' by another or earlier admin action. No status, notification, or decision email was changed.';
                    $message_type = 'warning';
                } else {
                    $delivery = deliver_and_log_verification_email(
                        $connection,
                        $result['farmer'],
                        $admin_id,
                        $result['decision'],
                        $result['reason']
                    );
                    $decision_message = $result['decision'] === 'Approved'
                        ? 'Farmer approved successfully.'
                        : 'Farmer rejected and the reason was saved.';
                    if ($delivery['status'] === 'Sent') {
                        $message = $decision_message . ($delivery['development_routed']
                            ? ' The email was accepted for the configured development test recipient; the real farmer was not emailed.'
                            : ' The email was accepted by the SMTP server.');
                    } elseif ($delivery['status'] === 'Simulated') {
                        $message = $decision_message . ' The email was prepared using the local test transport; no real email was sent.';
                        $message_type = 'info';
                    } else {
                        $message = $decision_message . ' In-app notification was created, but email delivery failed. ' . verification_email_failure_message($delivery['failure_code']);
                        $message_type = 'warning';
                    }
                }
            }
            $redirect_after_post = true;
        } catch (Throwable $exception) {
            $message = $exception instanceof InvalidArgumentException ? $exception->getMessage() : 'The verification action could not be completed.';
            $message_type = 'danger';
        }
    }

    if ($redirect_after_post) {
        $_SESSION['verification_flash'] = $message;
        $_SESSION['verification_flash_type'] = $message_type;
        header('Location: ' . $return_url);
        exit;
    }
}

if (!empty($_SESSION['verification_flash'])) {
    $message = (string) $_SESSION['verification_flash'];
    $message_type = (string) ($_SESSION['verification_flash_type'] ?? 'success');
    unset($_SESSION['verification_flash'], $_SESSION['verification_flash_type']);
}

$farmers = [];
$farm_types = [];
$load_error = '';
$total_farmers = 0;
$page_state = admin_page_state(0, 1);

try {
    $connection = db();
    $farm_types = farmer_verification_farm_types($connection);
    $status_filter = in_array($status, $allowed_statuses, true) ? $status : '';
    $total_farmers = farmer_verification_count($connection, $search, $status_filter, $farm_type);
    $page_state = admin_page_state($total_farmers, admin_requested_page($_GET['page'] ?? null));
    admin_redirect_clamped_page('farmer-verification.php', $list_filters, $page_state);
    $farmers = farmer_verification_page($connection, $search, $status_filter, $farm_type, $page_state['per_page'], $page_state['offset']);
    foreach ($farmers as &$listedFarmer) $listedFarmer['linked_farms'] = admin_linked_farms(db(), (int) $listedFarmer['id']);
    unset($listedFarmer);
    foreach ($farmers as &$farmer) {
        $farmer['has_id_front'] = !empty($farmer['front_image_path']) || !empty($farmer['image_path']);
        $farmer['has_id_back'] = !empty($farmer['back_image_path']);
        unset($farmer['image_path'], $farmer['front_image_path'], $farmer['back_image_path']);
        $farmer['email_delivery_failure_message'] = $farmer['email_delivery_status'] === 'Failed'
            ? verification_email_failure_message((string) $farmer['email_delivery_failure_code'])
            : '';
        unset($farmer['email_delivery_failure_code']);
    }
    unset($farmer);
} catch (Throwable $exception) {
    $load_error = 'Verification data is not available yet. Import the updated database setup first.';
}

$page_title = 'Farmer Verification';
$active_page = 'farmer-verification';
$page_css = 'assets/css/farmer-verification.css';
require_once __DIR__ . '/includes/header.php';
?>
<div class="container-fluid py-4">
    <div class="mb-4"><p class="eyebrow mb-1">Registration review</p><h1 class="page-title mb-1">Farmer Verification</h1><p class="text-muted mb-0">Review and validate pending farmer registrations before they access AgriReach services.</p></div>

    <?php if ($message !== ''): ?><div class="alert alert-<?= e($message_type) ?> alert-dismissible fade show" role="alert"><?= e($message) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div><?php endif; ?>
    <?php if ($load_error !== ''): ?><div class="alert alert-warning" role="alert"><?= e($load_error) ?></div><?php endif; ?>
    <?php if (!$development_email_routing): ?><div class="alert alert-success py-2" role="status"><i class="bi bi-envelope-check me-2"></i>Live registration email delivery is active. New decisions and approved retries are sent to each farmer's registered email address.</div><?php endif; ?>

    <section class="content-card">
        <form class="row g-2 align-items-end mb-4" method="get">
            <div class="col-12 col-lg-5"><label class="form-label small fw-semibold" for="search">Search farmer</label><input class="form-control" id="search" name="search" value="<?= e($search) ?>" placeholder="Search by farmer name"></div>
            <div class="col-12 col-md-5 col-lg-3"><label class="form-label small fw-semibold" for="status">Verification status</label><select class="form-select" id="status" name="status"><option value="">All statuses</option><?php foreach ($allowed_statuses as $option): ?><option value="<?= e($option) ?>"<?= $status === $option ? ' selected' : '' ?>><?= e($option) ?></option><?php endforeach; ?></select></div>
            <div class="col-12 col-md-5 col-lg-3"><label class="form-label small fw-semibold" for="farm_type">Farm type</label><select class="form-select" id="farm_type" name="farm_type"><option value="">All farm types</option><?php foreach ($farm_types as $option): ?><option value="<?= e($option) ?>"<?= $farm_type === $option ? ' selected' : '' ?>><?= e($option) ?></option><?php endforeach; ?></select></div>
            <div class="col-12 col-md-2 col-lg-1"><button class="btn btn-success w-100" type="submit">Filter</button></div>
        </form>

        <div class="d-flex justify-content-between align-items-center gap-3 mb-3"><div><h2 class="section-title mb-1">Farmer registrations</h2><p class="text-muted small mb-0"><?= $total_farmers ?> registration<?= $total_farmers === 1 ? '' : 's' ?> found</p></div></div>
        <div class="table-responsive"><table class="table align-middle verification-table mb-0"><thead><tr><th>Farmer Name</th><th>Farm Name</th><th>Farm Type</th><th>Location</th><th>Date Registered</th><th>Verification Status</th><th class="text-end">Action</th></tr></thead><tbody>
        <?php if (!$farmers): ?><tr><td colspan="7" class="text-center py-5 text-muted">No farmer registrations match the selected filters.</td></tr><?php endif; ?>
        <?php foreach ($farmers as $farmer): $detail_json = json_encode($farmer, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>
            <tr><td><strong><?= e((string) $farmer['full_name']) ?></strong><small class="d-block text-muted"><?= e((string) $farmer['email']) ?></small></td><td><?= e((string) ($farmer['farm_name'] ?: '—')) ?></td><td><?= e((string) ($farmer['farm_type'] ?: '—')) ?></td><td><?= e((string) ($farmer['farm_address'] ?: $farmer['address'] ?: '—')) ?></td><td><?= e(date('M d, Y', strtotime((string) $farmer['date_registered']))) ?></td><td><span class="verification-status <?= strtolower((string) $farmer['verification_status']) ?>"><?= e((string) $farmer['verification_status']) ?></span></td><td class="text-end"><button class="btn btn-outline-success btn-sm view-farmer-btn" type="button" data-farmer-id="<?= (int) $farmer['id'] ?>">View Details</button><script type="application/json" id="farmer-data-<?= (int) $farmer['id'] ?>"><?= $detail_json ?></script></td></tr>
        <?php endforeach; ?></tbody></table></div>
        <?= admin_pagination_html($page_state, 'farmer-verification.php', $list_filters) ?>
    </section>
</div>

<div class="modal fade" id="farmerDetailsModal" tabindex="-1" aria-labelledby="farmerDetailsTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content verification-modal">
            <div class="modal-header">
                <div><p class="eyebrow mb-1">Registration review</p><h2 class="modal-title fs-5" id="farmerDetailsTitle">Farmer details</h2></div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="detail-grid">
                    <section><h3>Farmer Personal Information</h3><dl id="personalDetails"></dl><div id="residentialPinMap" class="farm-pin-map" hidden></div></section>
                    <section><h3>Farm Information</h3><dl id="farmDetails"></dl><div id="farmPinMap" class="farm-pin-map" hidden></div></section>
                    <section><h3 id="idSectionTitle">Valid ID</h3><dl id="idDetails"></dl><p id="idReviewNote" class="small text-muted mb-0" hidden></p><div id="idPreview" class="id-side-grid"></div></section>
                    <section><h3>Verification Information</h3><dl id="verificationDetails"></dl></section>
                </div>
                <div class="alert alert-warning mt-3 mb-0" id="emailDeliveryWarning" role="status" hidden></div>
            </div>
            <div class="modal-footer justify-content-between">
                <small class="text-muted" id="actionHint">Review the submitted information before deciding.</small>
                <div class="d-flex flex-wrap gap-2">
                    <div class="flex-wrap align-items-center gap-2 rounded-3 border px-3 py-2 bg-light" id="decisionReadOnly" role="status" hidden>
                        <span class="verification-status" id="decisionStatus"></span>
                        <small class="text-muted text-break" id="decisionMeta"></small>
                    </div>
                    <form method="post" id="retryEmailForm" hidden>
                        <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">
                        <input type="hidden" name="farmer_id" id="retryEmailFarmerId">
                        <input type="hidden" name="action" value="retry_email">
                        <div class="d-flex flex-column flex-md-row gap-2 align-items-md-end">
                            <?php if ($development_email_routing): ?>
                                <div>
                                    <label class="form-label small fw-semibold mb-1" for="retryTestRecipient">Development test recipient</label>
                                    <input class="form-control form-control-sm" type="email" id="retryTestRecipient" name="test_recipient" placeholder="Enter your test email" autocomplete="email" required>
                                    <small class="text-muted">Used for this retry only. The farmer will not receive it.</small>
                                </div>
                            <?php else: ?>
                                <small class="text-muted align-self-center">This retry will send to the farmer's registered email.</small>
                            <?php endif; ?>
                            <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-envelope-arrow-up"></i> <?= $development_email_routing ? 'Retry Decision Email' : 'Retry Email to Farmer' ?></button>
                        </div>
                    </form>
                    <form method="post" id="approveForm">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">
                        <input type="hidden" name="farmer_id" id="approveFarmerId">
                        <input type="hidden" name="action" value="approve">
                        <button class="btn btn-success" type="submit"><i class="bi bi-check2-circle"></i> Approve Farmer</button>
                    </form>
                    <button class="btn btn-outline-danger" type="button" id="showRejectForm">Reject Farmer</button>
                </div>
                <form method="post" class="reject-form w-100" id="rejectForm" hidden>
                    <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">
                    <input type="hidden" name="farmer_id" id="rejectFarmerId">
                    <input type="hidden" name="action" value="reject">
                    <label class="form-label small fw-semibold" for="rejectionReason">Rejection reason</label>
                    <textarea class="form-control mb-2" id="rejectionReason" name="rejection_reason" rows="3" maxlength="1000" required placeholder="Explain what the farmer needs to correct or resubmit."></textarea>
                    <div class="text-end"><button class="btn btn-danger" type="submit">Confirm Rejection</button></div>
                </form>
            </div>
        </div>
    </div>
<div id="idImageViewer" class="id-image-viewer" role="dialog" aria-modal="true" aria-label="Registration ID photo preview" hidden>
    <div class="id-image-viewer-panel">
        <button type="button" class="id-image-viewer-close" id="idImageViewerClose" aria-label="Close ID photo preview"><i class="bi bi-x-lg" aria-hidden="true"></i><span>Close</span></button>
        <div class="id-image-viewer-stage">
            <button type="button" class="id-image-viewer-arrow" id="idImageViewerPrevious" aria-label="Previous ID side"><i class="bi bi-chevron-left" aria-hidden="true"></i></button>
            <img id="idImageViewerPhoto" alt="" hidden>
            <p id="idImageViewerError" class="id-image-viewer-message" role="status">Loading ID photo…</p>
            <button type="button" class="id-image-viewer-arrow" id="idImageViewerNext" aria-label="Next ID side"><i class="bi bi-chevron-right" aria-hidden="true"></i></button>
        </div>
        <p id="idImageViewerCaption" class="id-image-viewer-caption"></p>
    </div>
</div>
</div>
<script src="assets/js/admin-farms.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const modalElement = document.getElementById('farmerDetailsModal');
  const modal = bootstrap.Modal.getOrCreateInstance(modalElement);
  const idViewer = document.getElementById('idImageViewer');
  const idViewerPhoto = document.getElementById('idImageViewerPhoto');
  const idViewerMessage = document.getElementById('idImageViewerError');
  const idViewerCaption = document.getElementById('idImageViewerCaption');
  const idViewerPrevious = document.getElementById('idImageViewerPrevious');
  const idViewerNext = document.getElementById('idImageViewerNext');
  const idViewerClose = document.getElementById('idImageViewerClose');
  let idImages = [];
  let idImageIndex = 0;
  let idViewerOpener = null;
  const showIdImage = (index) => {
    if (!idImages.length) return;
    idImageIndex = (index + idImages.length) % idImages.length;
    const image = idImages[idImageIndex];
    idViewerPhoto.hidden = true;
    idViewerPhoto.removeAttribute('src');
    idViewerPhoto.alt = image.label;
    idViewerMessage.textContent = 'Loading ID photo…';
    idViewerMessage.hidden = false;
    idViewerCaption.textContent = `${image.label} · ${idImageIndex + 1} of ${idImages.length}`;
    idViewerPrevious.hidden = idImages.length < 2;
    idViewerNext.hidden = idImages.length < 2;
    idViewerPhoto.onload = () => { idViewerMessage.hidden = true; idViewerPhoto.hidden = false; };
    idViewerPhoto.onerror = () => {
      idViewerPhoto.hidden = true;
      idViewerMessage.textContent = 'This ID photo could not be loaded. Check your connection or try again.';
      idViewerMessage.hidden = false;
    };
    idViewerPhoto.src = image.url;
  };
  const closeIdViewer = () => {
    if (idViewer.hidden) return;
    idViewer.hidden = true;
    idViewerPhoto.removeAttribute('src');
    idViewerPhoto.hidden = true;
    if (idViewerOpener?.isConnected && modalElement.classList.contains('show')) idViewerOpener.focus();
    idViewerOpener = null;
  };
  document.getElementById('idPreview').addEventListener('click', (event) => {
    const trigger = event.target.closest('.id-preview-trigger');
    if (!trigger || !idImages.length) return;
    idViewerOpener = trigger;
    idViewer.hidden = false;
    showIdImage(idImages.findIndex((image) => image.side === trigger.dataset.side));
    idViewerClose.focus();
  });
  idViewerClose.addEventListener('click', closeIdViewer);
  idViewerPrevious.addEventListener('click', () => showIdImage(idImageIndex - 1));
  idViewerNext.addEventListener('click', () => showIdImage(idImageIndex + 1));
  modalElement.addEventListener('hidden.bs.modal', closeIdViewer);
  document.addEventListener('keydown', (event) => {
    if (idViewer.hidden) return;
    if (event.key === 'Escape') { event.preventDefault(); event.stopImmediatePropagation(); closeIdViewer(); }
    else if (event.key === 'ArrowLeft' && idImages.length > 1) { event.preventDefault(); showIdImage(idImageIndex - 1); }
    else if (event.key === 'ArrowRight' && idImages.length > 1) { event.preventDefault(); showIdImage(idImageIndex + 1); }
    else if (event.key === 'Tab') {
      const controls = [idViewerClose, idViewerPrevious, idViewerNext].filter((button) => !button.hidden);
      const current = controls.indexOf(document.activeElement);
      if (event.shiftKey && current <= 0) { event.preventDefault(); controls[controls.length - 1].focus(); }
      else if (!event.shiftKey && current === controls.length - 1) { event.preventDefault(); controls[0].focus(); }
    }
  }, true);
  const escapeHtml = (value) => String(value ?? '—').replace(/[&<>"']/g, (character) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[character]));
  const text = (value) => escapeHtml(value || '—');
  const row = (label, value) => `<div><dt>${escapeHtml(label)}</dt><dd>${text(value)}</dd></div>`;
  const fill = (id, rows) => { document.getElementById(id).innerHTML = rows.join(''); };
  document.querySelectorAll('.view-farmer-btn').forEach((button) => button.addEventListener('click', () => {
    const data = JSON.parse(document.getElementById(`farmer-data-${button.dataset.farmerId}`).textContent);
    document.getElementById('farmerDetailsTitle').textContent = data.full_name || 'Farmer details';
    fill('personalDetails', [
      row('Full Name', data.full_name),
      row('First Name', data.first_name),
      row('Middle Name', data.middle_name),
      row('Last Name', data.last_name),
      row('Extension Name', data.extension_name),
      row('Contact Number', data.contact_number),
      row('Email', data.email),
      row('Residential Address', data.address),
      row('Residential City / Province', data.residential_city_name && data.residential_province_name ? `${data.residential_city_name}, ${data.residential_province_name}` : null),
      row('Residential Barangay', data.residential_barangay_name),
      row('Residential Purok / Sitio', data.residential_purok_sitio),
      row('House / Street / Landmark', data.residential_landmark),
      row('Residential Location Method', data.residential_location_method),
      row('Home City-Boundary Check', data.residential_boundary_status),
      row('Home Latitude', data.residential_latitude === null || data.residential_latitude === '' ? 'Not provided' : data.residential_latitude),
      row('Home Longitude', data.residential_longitude === null || data.residential_longitude === '' ? 'Not provided' : data.residential_longitude)
    ]);
    const residentialPinMap = document.getElementById('residentialPinMap');
    const residentialLatitude = Number(data.residential_latitude);
    const residentialLongitude = Number(data.residential_longitude);
    if (data.residential_latitude !== null && data.residential_latitude !== '' && data.residential_longitude !== null && data.residential_longitude !== '' && Number.isFinite(residentialLatitude) && Number.isFinite(residentialLongitude)) {
      const residentialDelta = 0.006;
      const residentialBbox = `${residentialLongitude - residentialDelta},${residentialLatitude - residentialDelta},${residentialLongitude + residentialDelta},${residentialLatitude + residentialDelta}`;
      const residentialEmbedUrl = `https://www.openstreetmap.org/export/embed.html?bbox=${encodeURIComponent(residentialBbox)}&layer=mapnik&marker=${encodeURIComponent(`${residentialLatitude},${residentialLongitude}`)}`;
      const residentialExternalUrl = `https://www.openstreetmap.org/?mlat=${encodeURIComponent(residentialLatitude)}&mlon=${encodeURIComponent(residentialLongitude)}#map=16/${encodeURIComponent(residentialLatitude)}/${encodeURIComponent(residentialLongitude)}`;
      residentialPinMap.innerHTML = `<iframe title="Submitted residential pin" src="${residentialEmbedUrl}" loading="lazy"></iframe><div><strong>Submitted home pin</strong><span>Point shown separately from the registered farm location.</span><a href="${residentialExternalUrl}" target="_blank" rel="noopener noreferrer">Open larger map</a></div>`;
      residentialPinMap.hidden = false;
    } else {
      residentialPinMap.innerHTML = '';
      residentialPinMap.hidden = true;
    }
    fill('farmDetails', [
      row('Farm Name', data.farm_name),
      row('Farm Type', data.farm_type),
      row('Agricultural Category', data.agricultural_category),
      row('Land Area', data.land_area),
      row('Fixed City / Province', data.city_name && data.province_name ? `${data.city_name}, ${data.province_name}` : null),
      row('Barangay', data.barangay_name),
      row('Purok / Sitio', data.purok_sitio),
      row('Landmark', data.landmark),
      row('Submitted Farm Address', data.farm_address),
      row('Location Method', data.location_method),
      row('Location Review', data.location_review_status),
      row('Mapped City-Boundary Check', data.location_boundary_status),
      ...(data.location_boundary_source ? [row('Boundary Data Source', data.location_boundary_source)] : []),
      ...(data.location_boundary_checked_at ? [row('Boundary Check Time', data.location_boundary_checked_at)] : []),
      row('Latitude', data.latitude),
      row('Longitude', data.longitude)
    ]);
    const farmPinMap = document.getElementById('farmPinMap');
    const latitude = Number(data.latitude);
    const longitude = Number(data.longitude);
    if (data.latitude !== null && data.latitude !== '' && data.longitude !== null && data.longitude !== '' && Number.isFinite(latitude) && Number.isFinite(longitude)) {
      const delta = 0.006;
      const bbox = `${longitude - delta},${latitude - delta},${longitude + delta},${latitude + delta}`;
      const embedUrl = `https://www.openstreetmap.org/export/embed.html?bbox=${encodeURIComponent(bbox)}&layer=mapnik&marker=${encodeURIComponent(`${latitude},${longitude}`)}`;
      const externalUrl = `https://www.openstreetmap.org/?mlat=${encodeURIComponent(latitude)}&mlon=${encodeURIComponent(longitude)}#map=16/${encodeURIComponent(latitude)}/${encodeURIComponent(longitude)}`;
      farmPinMap.innerHTML = `<iframe title="Submitted farm pin" src="${embedUrl}" loading="lazy"></iframe><div><strong>Submitted farm pin</strong><span>Point shown for review; it is not proof of a cadastral farm boundary.</span><a href="${externalUrl}" target="_blank" rel="noopener noreferrer">Open larger map</a></div>`;
      farmPinMap.hidden = false;
    } else {
      farmPinMap.innerHTML = '';
      farmPinMap.hidden = true;
    }
    let linked = document.getElementById('verificationLinkedFarms');
    if (!linked) {
      linked = document.createElement('section'); linked.id = 'verificationLinkedFarms'; linked.className = 'linked-farms';
      document.getElementById('farmDetails').closest('.detail-grid').appendChild(linked);
    }
    linked.innerHTML = '<h3>All linked farms</h3>' + renderAdminFarms(data.linked_farms);
    // New registrations submit the physical RSBSA ID card; older records keep their original ID type labels.
    const rsbsaId = data.id_type === 'RSBSA ID';
    const frontLabel = rsbsaId ? 'Front of RSBSA ID' : data.id_type === 'Passport' ? 'Passport Bio Page' : 'ID Front';
    const backLabel = rsbsaId ? 'Back of RSBSA ID' : 'ID Back';
    document.getElementById('idSectionTitle').textContent = rsbsaId ? 'RSBSA ID' : 'Valid ID';
    const idReviewNote = document.getElementById('idReviewNote');
    idReviewNote.textContent = rsbsaId ? 'Submitted for manual review. Compare both card photos and the number with the applicant; AgriReach does not verify RSBSA registration automatically.' : '';
    idReviewNote.hidden = !rsbsaId;
    // RSBSA numbers are stored as 00-00-00-000-000000; a bare 15-digit value is shown in the same grouping.
    const idNumber = rsbsaId && /^\d{15}$/.test(String(data.id_number || '')) ? String(data.id_number).replace(/^(\d{2})(\d{2})(\d{2})(\d{3})(\d{6})$/, '$1-$2-$3-$4-$5') : data.id_number;
    fill('idDetails', [row('ID Type', data.id_type), row(rsbsaId ? 'RSBSA Reference Number' : 'ID Number', idNumber)]);
    fill('verificationDetails', [
      row('Current Status', data.verification_status),
      row('Date Submitted', data.date_registered),
      ...(data.verified_at ? [row('Decision Date', data.verified_at)] : []),
      row('Rejection Reason', data.rejection_reason),
      row('Decision Email', data.email_delivery_status || 'Not attempted'),
      row('Intended Recipient', data.email),
      row('Delivery Route', data.email_delivery_routing_mode || 'Not recorded'),
      row('Delivered To', data.email_delivery_recipient || 'Not sent / not recorded'),
      ...(data.email_delivery_failure_message ? [row('Delivery Issue', data.email_delivery_failure_message)] : [])
    ]);
    const emailWarning = document.getElementById('emailDeliveryWarning');
    emailWarning.textContent = data.email_delivery_failure_message || '';
    emailWarning.hidden = !data.email_delivery_failure_message;
    const preview = document.getElementById('idPreview');
    const imageUrl = (side) => `farmer-id-image.php?farmer_id=${encodeURIComponent(data.id)}&side=${side}`;
    const idSide = (side, label, available) => `<div class="id-side"><strong>${escapeHtml(label)}</strong>${available ? `<button type="button" class="id-preview id-preview-trigger" data-side="${side}" aria-label="View ${escapeHtml(label)} full size"><img src="${imageUrl(side)}" alt="${escapeHtml(label)}" loading="lazy"><span class="id-preview-hint">View larger</span></button>` : '<div class="id-preview"><span>Not provided.</span></div>'}</div>`;
    closeIdViewer();
    idImages = [
      ...(data.has_id_front ? [{ side: 'front', label: frontLabel, url: imageUrl('front') }] : []),
      ...(data.has_id_back ? [{ side: 'back', label: backLabel, url: imageUrl('back') }] : [])
    ];
    preview.innerHTML = idSide('front', frontLabel, data.has_id_front)
      + idSide('back', backLabel, data.has_id_back);
    document.getElementById('approveFarmerId').value = data.id;
    document.getElementById('rejectFarmerId').value = data.id;
    document.getElementById('retryEmailFarmerId').value = data.id;
    document.getElementById('retryEmailForm').hidden = data.email_delivery_status !== 'Failed';
    document.getElementById('rejectForm').hidden = true;
    document.getElementById('rejectionReason').value = '';
    const isPending = data.verification_status === 'Pending';
    const approveForm = document.getElementById('approveForm');
    const rejectButton = document.getElementById('showRejectForm');
    const decisionReadOnly = document.getElementById('decisionReadOnly');
    const decisionStatus = document.getElementById('decisionStatus');
    const decisionMeta = document.getElementById('decisionMeta');
    approveForm.hidden = !isPending;
    rejectButton.hidden = !isPending;
    decisionReadOnly.hidden = isPending;
    decisionReadOnly.classList.toggle('d-flex', !isPending);
    document.getElementById('actionHint').textContent = isPending
      ? 'Review the submitted information before deciding.'
      : 'This verification decision is read-only.';
    if (!isPending) {
      decisionStatus.textContent = `Decision: ${data.verification_status}`;
      decisionStatus.className = `verification-status ${String(data.verification_status).toLowerCase()}`;
      const decisionDetails = [];
      if (data.verified_at) decisionDetails.push(`Date: ${data.verified_at}`);
      if (data.verification_status === 'Rejected' && data.rejection_reason) decisionDetails.push(`Reason: ${data.rejection_reason}`);
      decisionMeta.textContent = decisionDetails.join(' | ');
      decisionMeta.hidden = decisionDetails.length === 0;
    } else {
      decisionStatus.textContent = '';
      decisionStatus.className = 'verification-status';
      decisionMeta.textContent = '';
      decisionMeta.hidden = true;
    }
    modal.show();
  }));
  document.getElementById('showRejectForm').addEventListener('click', () => { document.getElementById('rejectForm').hidden = false; document.getElementById('showRejectForm').hidden = true; document.getElementById('rejectionReason').focus(); });
});
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
