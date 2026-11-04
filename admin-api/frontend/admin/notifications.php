<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_admin();
require_once __DIR__ . '/../../backend/lib/admin_notifications.php';

$csrf_token = admin_csrf_token();
$message = '';
$message_type = 'success';
$notification_types = ['Farmer Registration', 'Farmer Verification', 'Damage Report Submitted', 'Damage Report Status Update', 'Admin Response', 'Weather Advisory', 'System Notification'];
$send_types = ['General', 'Report Update', 'Weather Advisory', 'Required Action'];

function notifications_fail(string $text): void
{
    $_SESSION['notifications_error'] = $text;
    header('Location: notifications.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf_token, (string) ($_POST['csrf_token'] ?? ''))) { notifications_fail('Your session token is invalid. Please try again.'); }
    $action = (string) ($_POST['action'] ?? '');
    $notification_id = filter_var($_POST['notification_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    try {
        $connection = db();
        if (in_array($action, ['mark_read', 'mark_unread', 'delete'], true) && $notification_id) {
            admin_notification_state_change($connection, (int) $_SESSION['admin_id'], (int) $notification_id, $action);
            $_SESSION['notifications_flash'] = $action === 'delete' ? 'Archived from your admin view. The farmer notification is retained.' : 'Your admin read state was saved. Farmer read status is unchanged.';
            header('Location: notifications.php'); exit;
        }
        if ($action === 'send') {
            $recipient_type = (string) ($_POST['recipient_type'] ?? '');
            $farmer_id = filter_var($_POST['farmer_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $send_type = (string) ($_POST['send_type'] ?? '');
            $title = trim((string) ($_POST['title'] ?? ''));
            $notification_message = trim((string) ($_POST['message'] ?? ''));
            if (!in_array($recipient_type, ['individual', 'all_verified'], true) || ($recipient_type === 'individual' && $farmer_id === false) || !in_array($send_type, $send_types, true) || $title === '' || mb_strlen($title) > 180 || $notification_message === '' || mb_strlen($notification_message) > 5000) {
                notifications_fail('Complete all notification fields with valid values.');
            }
            $stored_type = $send_type === 'General' ? 'System Notification' : ($send_type === 'Report Update' ? 'Damage Report Status Update' : $send_type);
            $recipient_count = admin_send_farmer_notification($connection, (int) $_SESSION['admin_id'], $recipient_type, $recipient_type === 'individual' ? (int) $farmer_id : null, $stored_type, $title, $notification_message);
            $_SESSION['notifications_flash'] = 'Notification sent to ' . $recipient_count . ' farmer' . ($recipient_count === 1 ? '' : 's') . '.';
            header('Location: notifications.php'); exit;
        }
        notifications_fail('Unsupported notification action.');
    } catch (Throwable $exception) {
        notifications_fail($exception->getMessage() === 'The selected farmer is not an active verified farmer.' ? $exception->getMessage() : 'The notification action could not be completed.');
    }
}

if (!empty($_SESSION['notifications_flash'])) { $message = (string) $_SESSION['notifications_flash']; unset($_SESSION['notifications_flash']); }
if (!empty($_SESSION['notifications_error'])) { $message = (string) $_SESSION['notifications_error']; $message_type = 'danger'; unset($_SESSION['notifications_error']); }

$search = trim((string) ($_GET['search'] ?? ''));
$type_filter = (string) ($_GET['notification_type'] ?? '');
$read_filter = (string) ($_GET['read_status'] ?? '');
$date_filter = (string) ($_GET['date'] ?? '');
$notifications = [];
$farmers = [];
$load_error = '';
try {
    $connection = db();
    $adminViewId = (int) $_SESSION['admin_id'];
    $farmers = admin_notification_recipient_options($connection);
    $notifications = admin_notification_feed($connection, $adminViewId, ['search' => $search, 'type' => $type_filter, 'read' => $read_filter, 'date' => $date_filter], $notification_types);
    $summary = admin_notification_summary($connection, $adminViewId);
} catch (Throwable $exception) { $load_error = 'Notification data is not available yet. Import the updated database setup first.'; $summary = ['unread' => 0, 'registrations' => 0, 'reports' => 0, 'advisories' => 0]; }

$page_title = 'Notifications'; $active_page = 'notifications'; $page_css = 'assets/css/notifications.css';
require_once __DIR__ . '/includes/header.php';
?>
<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-4"><div><p class="eyebrow mb-1">System communication</p><h1 class="page-title mb-1">Notifications</h1><p class="text-muted mb-0">Your admin inbox. Reading or archiving here never changes the farmer’s notification.</p></div><button class="btn btn-success" type="button" data-bs-toggle="modal" data-bs-target="#sendNotificationModal"><i class="bi bi-send"></i> Send Notification</button></div>
    <?php if ($message !== ''): ?><div class="alert alert-<?= e($message_type) ?> alert-dismissible fade show" role="alert"><?= e($message) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div><?php endif; ?><?php if ($load_error !== ''): ?><div class="alert alert-warning" role="alert"><?= e($load_error) ?></div><?php endif; ?>
    <div class="row g-3 mb-4"><?php foreach ([['Unread Notifications',$summary['unread'],'unread','bi-bell'],['Farmer Registrations',$summary['registrations'],'registrations','bi-person-plus'],['Damage Report Updates',$summary['reports'],'reports','bi-clipboard2-pulse'],['Weather Advisories',$summary['advisories'],'advisories','bi-cloud-sun']] as $card): ?><div class="col-12 col-sm-6 col-xl-3"><div class="notification-stat"><span class="notification-stat-icon <?= e($card[2]) ?>"><i class="bi <?= e($card[3]) ?>"></i></span><div><p><?= e($card[0]) ?></p><strong><?= (int) $card[1] ?></strong></div></div></div><?php endforeach; ?></div>
    <section class="content-card"><form class="row g-2 align-items-end mb-4" method="get"><div class="col-12 col-lg-5"><label class="form-label small fw-semibold" for="search">Search notifications</label><input class="form-control" id="search" name="search" value="<?= e($search) ?>" placeholder="Search title, message, or farmer"></div><div class="col-12 col-md-4 col-lg-2"><label class="form-label small fw-semibold" for="notification_type">Type</label><select class="form-select" id="notification_type" name="notification_type"><option value="">All types</option><?php foreach ($notification_types as $option): ?><option value="<?= e($option) ?>"<?= $type_filter === $option ? ' selected' : '' ?>><?= e($option) ?></option><?php endforeach; ?></select></div><div class="col-12 col-md-4 col-lg-2"><label class="form-label small fw-semibold" for="read_status">Admin read status</label><select class="form-select" id="read_status" name="read_status"><option value="">All</option><option<?= $read_filter === 'Unread' ? ' selected' : '' ?>>Unread</option><option<?= $read_filter === 'Read' ? ' selected' : '' ?>>Read</option></select></div><div class="col-12 col-md-4 col-lg-2"><label class="form-label small fw-semibold" for="date">Date</label><input class="form-control" type="date" id="date" name="date" value="<?= e($date_filter) ?>"></div><div class="col-12 col-md-4 col-lg-1"><button class="btn btn-success w-100" type="submit">Filter</button></div></form><div class="d-flex justify-content-between align-items-center mb-3"><div><h2 class="section-title mb-1">Notification list</h2><p class="text-muted small mb-0"><?= count($notifications) ?> notification<?= count($notifications) === 1 ? '' : 's' ?> found</p></div></div><div class="notification-feed">
<?php $notificationDay = ''; if (!$notifications): ?><p class="text-muted">No notifications match these filters.</p><?php endif; ?>
<?php foreach ($notifications as $notification):
    $day = date('M j, Y', strtotime($notification['created_at']));
    if ($day !== $notificationDay): $notificationDay = $day; ?><h3 class="notification-date"><?= e($day) ?></h3><?php endif; ?>
    <article class="notification-item <?= $notification['is_read'] ? '' : 'unread' ?>">
      <span class="notification-symbol"><i class="bi bi-bell" aria-hidden="true"></i></span>
      <div class="notification-copy"><strong><?= e($notification['title']) ?></strong><p><?= e(mb_strimwidth($notification['message'],0,240,'…')) ?></p><small><?= e($notification['farmer_name'] ?: 'Recipient unavailable') ?> · <?= e(date('g:i A',strtotime($notification['created_at']))) ?> · Farmer: <?= $notification['farmer_is_read'] ? 'Read' : 'Unread' ?> · Admin: <?= $notification['is_read'] ? 'Read' : 'Unread' ?></small></div>
      <div class="notification-actions">
        <button class="btn btn-outline-success btn-sm view-notification-btn" type="button" data-notification-id="<?= (int) $notification['id'] ?>">Details</button>
        <form method="post"><input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>"><input type="hidden" name="action" value="<?= $notification['is_read'] ? 'mark_unread' : 'mark_read' ?>"><input type="hidden" name="notification_id" value="<?= (int) $notification['id'] ?>"><button class="btn btn-light btn-sm" type="submit">Mark <?= $notification['is_read'] ? 'unread' : 'read' ?></button></form>
        <form method="post"><input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="notification_id" value="<?= (int) $notification['id'] ?>"><button class="btn btn-light btn-sm" type="submit" aria-label="Archive this notice from your admin view">Archive</button></form>
        <script type="application/json" id="notification-data-<?= (int) $notification['id'] ?>"><?= json_encode($notification,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?></script>
      </div>
    </article>
<?php endforeach; ?></div></section>
</div>
<div class="modal fade" id="notificationDetailsModal" tabindex="-1" aria-labelledby="notificationDetailsTitle" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content notification-modal"><div class="modal-header"><div><p class="eyebrow mb-1">Notification record</p><h2 class="modal-title fs-5" id="notificationDetailsTitle">Notification Details</h2></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><dl id="notificationDetails"></dl><div class="notification-full-message"><h3>Full Message</h3><p id="notificationFullMessage"></p></div><div id="notificationRelatedAction"></div></div></div></div></div>
<div class="modal fade" id="sendNotificationModal" tabindex="-1" aria-labelledby="sendNotificationTitle" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content notification-modal"><div class="modal-header"><div><p class="eyebrow mb-1">Farmer communication</p><h2 class="modal-title fs-5" id="sendNotificationTitle">Send Notification</h2></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><form method="post"><div class="modal-body"><input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>"><input type="hidden" name="action" value="send"><div class="row g-3"><div class="col-md-6"><label class="form-label" for="recipientType">Recipient Type <span class="required-mark">*</span></label><select class="form-select" id="recipientType" name="recipient_type" required><option value="individual">Individual Farmer</option><option value="all_verified">All Verified Farmers</option></select></div><div class="col-md-6" id="farmerRecipientWrap"><label class="form-label" for="farmerRecipient">Farmer <span class="required-mark">*</span></label><select class="form-select" id="farmerRecipient" name="farmer_id" required><option value="">Select verified farmer</option><?php foreach ($farmers as $farmer): ?><option value="<?= (int) $farmer['id'] ?>"><?= e((string) $farmer['full_name']) ?></option><?php endforeach; ?></select></div><div class="col-md-6"><label class="form-label" for="sendType">Notification Type <span class="required-mark">*</span></label><select class="form-select" id="sendType" name="send_type" required><?php foreach ($send_types as $option): ?><option><?= e($option) ?></option><?php endforeach; ?></select></div><div class="col-12"><label class="form-label" for="notificationTitle">Title <span class="required-mark">*</span></label><input class="form-control" id="notificationTitle" name="title" maxlength="180" required></div><div class="col-12"><label class="form-label" for="notificationMessage">Message <span class="required-mark">*</span></label><textarea class="form-control" id="notificationMessage" name="message" rows="5" maxlength="5000" required></textarea></div></div></div><div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-success" type="submit"><i class="bi bi-send"></i> Send Notification</button></div></form></div></div></div>
<script>
document.addEventListener('DOMContentLoaded', () => {
  const esc = (value) => String(value ?? '—').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
  const detailRow = (label, value) => `<div><dt>${esc(label)}</dt><dd>${esc(value || '—')}</dd></div>`;
  const detailsModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('notificationDetailsModal'));
  document.querySelectorAll('.view-notification-btn').forEach((button) => button.addEventListener('click', () => { const data = JSON.parse(document.getElementById(`notification-data-${button.dataset.notificationId}`).textContent); document.getElementById('notificationDetailsTitle').textContent = data.title || 'Notification Details'; document.getElementById('notificationDetails').innerHTML = [detailRow('Notification Type', data.notification_type), detailRow('Related Farmer', data.farmer_name || 'All verified farmers'), detailRow('Related Report ID', data.report_code), detailRow('Date and Time', data.created_at), detailRow('Admin Read Status', data.is_read ? 'Read' : 'Unread'), detailRow('Farmer Read Status', data.farmer_is_read ? 'Read' : 'Unread')].join(''); document.getElementById('notificationFullMessage').textContent = data.message || ''; let related = ''; if (data.report_code) related = '<a class="btn btn-outline-success btn-sm" href="damage-reports.php?search=' + encodeURIComponent(data.report_code) + '">Open this report</a>'; else if (data.advisory_title) related = '<a class="btn btn-outline-success btn-sm" href="weather-advisory.php?advisory_id=' + encodeURIComponent(data.related_advisory_id) + '">Open this advisory</a>'; else if (data.notification_type === 'Farmer Registration' || data.notification_type === 'Farmer Verification') related = '<a class="btn btn-outline-success btn-sm" href="farmer-verification.php">Open Farmer Verification</a>'; document.getElementById('notificationRelatedAction').innerHTML = related; detailsModal.show(); }));
  const recipientType = document.getElementById('recipientType'); const farmerWrap = document.getElementById('farmerRecipientWrap'); const farmerSelect = document.getElementById('farmerRecipient');
  const toggleRecipient = () => { const all = recipientType.value === 'all_verified'; farmerWrap.hidden = all; farmerSelect.required = !all; if (all) farmerSelect.value = ''; };
  recipientType.addEventListener('change', toggleRecipient); toggleRecipient();
});
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
