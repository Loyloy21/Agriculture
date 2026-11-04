<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/../../backend/lib/admin_accounts.php';
require_once __DIR__ . '/../../backend/lib/admin_replacement.php';
require_admin();

$csrf_token = admin_csrf_token();
$admin_id = (int) $_SESSION['admin_id'];
$message = '';
$message_type = 'success';

$preference_defaults = ADMIN_PREFERENCE_DEFAULTS;

function settings_fail(string $text): void
{
    $_SESSION['settings_error'] = $text;
    header('Location: settings.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf_token, (string) ($_POST['csrf_token'] ?? ''))) {
        settings_fail('Your session token is invalid. Please try again.');
    }

    $action = (string) ($_POST['action'] ?? '');
    try {
        $connection = db();

        if ($action === 'save_profile') {
            $full_name = trim((string) ($_POST['full_name'] ?? ''));
            $username = trim((string) ($_POST['username'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $contact_number = trim((string) ($_POST['contact_number'] ?? ''));
            if ($full_name === '' || mb_strlen($full_name) > 120 || !preg_match('/^[A-Za-z0-9._-]{3,60}$/', $username) || ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) || mb_strlen($contact_number) > 40) {
                settings_fail('Enter valid profile information.');
            }
            if (admin_username_taken($connection, $username, $admin_id)) {
                settings_fail('That username is already in use.');
            }
            admin_update_profile($connection, $admin_id, $full_name, $username, $email, $contact_number);
            $_SESSION['admin_name'] = $full_name;
            $_SESSION['admin_username'] = $username;
            $_SESSION['settings_flash'] = 'Admin profile updated successfully.';
        } elseif ($action === 'change_password') {
            $current_password = (string) ($_POST['current_password'] ?? '');
            $new_password = (string) ($_POST['new_password'] ?? '');
            $confirm_password = (string) ($_POST['confirm_password'] ?? '');
            $stored_hash = admin_password_hash($connection, $admin_id);
            if ($stored_hash === null || !password_verify($current_password, $stored_hash)) {
                settings_fail('The current password is incorrect.');
            }
            if (strlen($new_password) < 8 || $new_password !== $confirm_password) {
                settings_fail('The new password must be at least 8 characters and match its confirmation.');
            }
            admin_update_password($connection, $admin_id, password_hash($new_password, PASSWORD_DEFAULT));
            $_SESSION['settings_flash'] = 'Password changed successfully.';
        } elseif ($action === 'save_preferences') {
            $values = [];
            foreach ($preference_defaults as $key => $default) {
                $values[$key] = isset($_POST[$key]) ? 1 : 0;
            }
            admin_save_preferences($connection, $admin_id, $values);
            $_SESSION['settings_flash'] = 'Notification preferences saved.';
        } elseif (in_array($action, ['invite_replacement', 'resend_invitation', 'cancel_invitation', 'transfer_admin'], true)) {
            // Admin replacement (Settings → Admin Account). Every step re-checks on the server that this is the active admin.
            try {
                if ($action === 'transfer_admin') {
                    $result = admin_replacement_transfer($connection, $admin_id, (string) ($_POST['current_password'] ?? ''), ($_POST['confirm_transfer'] ?? '') === '1');
                    $notified = !array_intersect(['failed', 'no_email'], $result['notifications']);
                    admin_logout();
                    header('Location: login.php?transferred=1' . ($notified ? '' : '&notifications=incomplete'));
                    exit;
                }
                if ($action === 'cancel_invitation') {
                    admin_replacement_cancel($connection, $admin_id);
                    $_SESSION['settings_flash'] = 'The replacement process was cancelled. Its invitation link no longer works. Your administrator access is unchanged.';
                } else {
                    $result = $action === 'invite_replacement'
                        ? admin_replacement_invite($connection, $admin_id, (string) ($_POST['replacement_name'] ?? ''), (string) ($_POST['replacement_email'] ?? ''))
                        : admin_replacement_resend($connection, $admin_id);
                    if (!$result['sent']) {
                        $_SESSION['settings_error'] = 'The invitation email was NOT sent: ' . admin_replacement_failure_message($result['failureCode']) . ' Your administrator access is unchanged. Use Resend Invitation to try again.';
                    } elseif ($result['status'] === 'Simulated') {
                        $_SESSION['settings_flash'] = 'The invitation was created, but this server only simulates email (log transport), so no email was actually sent.';
                    } else {
                        // 'Sent' means the mail server accepted the message. Outside production it goes to the test recipient.
                        $routed = registration_email_configuration($connection)['app_environment'] !== 'production';
                        $_SESSION['settings_flash'] = ($action === 'resend_invitation' ? 'A new invitation link was sent; the previous link no longer works.' : 'Invitation sent.')
                            . ($routed ? ' Development server: it was delivered to the test recipient, not the invited person.' : '') . ' Nothing changes for your account until you transfer access.';
                    }
                }
            } catch (AdminReplacementException $exception) {
                $_SESSION['settings_error'] = $exception->getMessage();
                if ($action === 'invite_replacement') {
                    $_SESSION['settings_replacement_form'] = ['name' => mb_substr((string) ($_POST['replacement_name'] ?? ''), 0, 120), 'email' => mb_substr((string) ($_POST['replacement_email'] ?? ''), 0, 160), 'field' => $exception->field];
                }
                if ($action === 'transfer_admin') $_SESSION['settings_reopen_transfer'] = true;
            }
        } else {
            settings_fail('Unsupported settings action.');
        }
        header('Location: settings.php');
        exit;
    } catch (Throwable $exception) {
        error_log('Settings action failed: ' . get_class($exception));
        if (admin_replacement_schema_missing($exception)) settings_fail('The admin replacement database update (migration 20261024_0008_admin_replacement) has not been applied to this database yet. Nothing was changed.');
        settings_fail('The settings update could not be completed.');
    }
}

if (!empty($_SESSION['settings_flash'])) { $message = (string) $_SESSION['settings_flash']; unset($_SESSION['settings_flash']); }
if (!empty($_SESSION['settings_error'])) { $message = (string) $_SESSION['settings_error']; $message_type = 'danger'; unset($_SESSION['settings_error']); }

$replacement_form = $_SESSION['settings_replacement_form'] ?? null;
$reopen_transfer = !empty($_SESSION['settings_reopen_transfer']);
unset($_SESSION['settings_replacement_form'], $_SESSION['settings_reopen_transfer']);

$admin = [];
$preferences = $preference_defaults;
$replacement = null;
$replacement_activity = [];
$sender_state = null;
$prerequisites = null;
$replacement_load_failed = false;
// Each part loads on its own, so one missing feature table cannot hide the sender or mark a working database
// as unavailable. Database Status comes only from a read-only health query on the same connection.
$database_status = 'Unavailable';
$database_label = '';
$connection = null;
try {
    $connection = db();
    $connection->query('SELECT 1')->fetchColumn();
    $database_status = 'Connected';
    $settings = db_connection_settings();
    $database_label = ($settings['driver'] === 'pgsql' ? 'PostgreSQL' : 'MySQL') . ' · "' . $settings['name'] . '" connection';
} catch (Throwable $exception) {
    error_log('Settings database health check failed: ' . get_class($exception));
    $connection = null;
}
if ($connection) {
    try {
        $admin = admin_profile($connection, $admin_id);
        $preferences = admin_preferences($connection, $admin_id);
    } catch (Throwable $exception) {
        error_log('Settings profile load failed: ' . get_class($exception));
    }
    try {
        $sender_state = registration_email_sender_state($connection);
    } catch (Throwable $exception) {
        error_log('Settings sender status load failed: ' . get_class($exception));
    }
    try {
        $prerequisites = admin_replacement_prerequisites($connection);
        if ($prerequisites['database']['ok']) {
            $replacement = admin_replacement_open($connection);
            $replacement_activity = admin_account_recent_activity($connection, 8);
        }
    } catch (Throwable $exception) {
        error_log('Settings admin replacement load failed: ' . get_class($exception));
        $replacement_load_failed = true;
    }
}
$blocking = $prerequisites ? array_values(array_filter($prerequisites, static fn(array $item): bool => $item['blocking'])) : [];
$invite_ready = $prerequisites !== null && !$blocking && !$replacement_load_failed;

$page_title = 'Settings';
$active_page = 'settings';
$page_css = 'assets/css/settings.css?v=20261026';
require_once __DIR__ . '/includes/header.php';
?>
<div class="container-fluid py-4 settings-page">
    <div class="mb-4"><p class="eyebrow mb-1">Administration</p><h1 class="page-title mb-1">Settings</h1><p class="text-muted mb-0">Manage your administrator account and system preferences.</p></div>
    <?php if ($message !== ''): ?><div class="alert alert-<?= e($message_type) ?> alert-dismissible fade show" role="alert"><?= e($message) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div><?php endif; ?>
    <section class="content-card settings-card admin-account-card mb-4" id="admin-account" aria-labelledby="adminAccountTitle">
        <div class="settings-card-heading"><div><span class="settings-icon"><i class="bi bi-person-gear" aria-hidden="true"></i></span><div><h2 id="adminAccountTitle">Admin Account</h2><p>AgriReach has exactly one active administrator. Replacing the admin hands this account's access to another person; it never adds a second admin.</p></div></div></div>
        <div class="admin-account-grid">
            <dl class="system-details admin-account-facts">
                <div><dt>Active administrator</dt><dd><?= e((string) ($admin['full_name'] ?? '')) ?></dd></div>
                <div><dt>Login username</dt><dd><?= e((string) ($admin['username'] ?? '')) ?></dd></div>
                <div><dt>Account email</dt><dd><?= ($admin['email'] ?? '') !== '' && ($admin['email'] ?? null) !== null ? e((string) $admin['email']) : '<span class="text-muted">Not set · add it in Admin Profile to receive the transfer notice</span>' ?></dd></div>
                <div><dt>System email sender</dt><dd><?php if ($sender_state === null): ?><span class="text-muted">Could not be checked</span><?php elseif ($sender_state['sender_email'] === ''): ?><span class="text-muted">Not configured</span><?php else: ?><?= e($sender_state['sender_email']) ?><?php if (!$sender_state['credential_available']): ?><small class="d-block text-danger">Saved, but its App Password cannot be read on this server.</small><?php endif; ?><?php endif; ?><small class="d-block text-muted">Sends approval, rejection and invitation emails. It stays the same when the admin is replaced.</small></dd></div>
            </dl>
            <div class="admin-replacement-panel">
                <h3>Replace Admin Account</h3>
                <?php if ($replacement_load_failed || $prerequisites === null): ?>
                    <div class="alert alert-danger py-2 small mb-2" role="alert">The replacement status could not be checked<?= $connection ? '' : ' because the database is unavailable' ?>. Invitations are turned off until it can be checked. Reload the page to try again.</div>
                <?php else: ?>
                    <ul class="replacement-checks" aria-label="What invitations need">
                        <?php foreach (['database' => 'Database update', 'app_url' => 'Invitation link address (APP_URL)', 'sender' => 'Email sender'] as $key => $label): $item = $prerequisites[$key]; $state = $item['ok'] ? 'ok' : ($item['blocking'] ? 'blocked' : 'note'); ?>
                            <li class="<?= $state ?>"><i class="bi <?= ['ok' => 'bi-check-circle-fill', 'blocked' => 'bi-x-circle-fill', 'note' => 'bi-exclamation-circle-fill'][$state] ?>" aria-hidden="true"></i><span><strong><?= e($label) ?>:</strong> <?= e($item['message']) ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if ($blocking): ?><p class="small text-danger mb-2">Invitations are turned off until the items marked <i class="bi bi-x-circle-fill" aria-hidden="true"></i> are fixed. Your administrator access is not affected.</p><?php endif; ?>
                <?php endif; ?>
                <?php if ($replacement === null): ?>
                    <p class="small text-muted">Invite the person who will take over. They set their own password through an emailed link (valid 24 hours). You keep full access until you confirm the transfer; then this account is disabled and its sessions end.</p>
                    <button class="btn btn-success" type="button" data-bs-toggle="modal" data-bs-target="#inviteReplacementModal"<?= $invite_ready ? '' : ' disabled' ?>><i class="bi bi-person-plus" aria-hidden="true"></i> Create Replacement Admin Account</button>
                <?php else: $ready = $replacement['status'] === 'Ready'; ?>
                    <div class="replacement-status <?= $ready ? 'ready' : 'pending' ?>">
                        <div class="d-flex flex-wrap justify-content-between gap-2"><strong><?= e((string) $replacement['full_name']) ?></strong><span class="badge-soft <?= $ready ? 'success' : ($replacement['email_status'] === 'Failed' ? 'warning' : 'info') ?>"><?= $ready ? 'Ready for Transfer' : ($replacement['email_status'] === 'Failed' ? 'Invitation not delivered' : 'Invitation pending') ?></span></div>
                        <p class="mb-1 small"><?= e((string) $replacement['email']) ?></p>
                        <?php if ($ready): ?>
                            <p class="mb-0 small text-muted">Password set <?= e(admin_utc_to_manila($replacement['setup_completed_at'])) ?>. Transfer before <?= e(admin_utc_to_manila($replacement['ready_expires_at'])) ?>.</p>
                        <?php else: ?>
                            <p class="mb-0 small text-muted">Email: <?= e(match ($replacement['email_status']) { 'Sent' => 'sent', 'Simulated' => 'simulated on this server (not delivered)', 'Failed' => 'failed — ' . admin_replacement_failure_message((string) $replacement['email_failure_code']), default => 'not sent yet' }) ?><?= $replacement['last_sent_at'] ? ' · last sent ' . e(admin_utc_to_manila($replacement['last_sent_at'])) : '' ?> · link expires <?= e(admin_utc_to_manila($replacement['token_expires_at'])) ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="d-flex flex-wrap gap-2 mt-3">
                        <?php if ($ready): ?><button class="btn btn-danger" type="button" data-bs-toggle="modal" data-bs-target="#transferAdminModal"><i class="bi bi-arrow-left-right" aria-hidden="true"></i> Transfer Admin Access</button><?php endif; ?>
                        <?php if (!$ready): ?><form method="post"><input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>"><input type="hidden" name="action" value="resend_invitation"><button class="btn btn-outline-success" type="submit"<?= $invite_ready ? '' : ' disabled' ?>><i class="bi bi-send" aria-hidden="true"></i> Resend Invitation</button></form><?php endif; ?>
                        <form method="post" data-confirm="Cancel this replacement process? The invitation link will stop working. Your access does not change."><input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>"><input type="hidden" name="action" value="cancel_invitation"><button class="btn btn-outline-danger" type="submit"><i class="bi bi-x-circle" aria-hidden="true"></i> Cancel Invitation</button></form>
                    </div>
                <?php endif; ?>
                <p class="small text-muted mt-3 mb-0">To change your own name, login or password, use Admin Profile and Change Password below.</p>
            </div>
        </div>
        <?php if ($replacement_activity): ?>
        <details class="admin-account-activity mt-3"><summary>Admin account activity</summary><div class="table-responsive mt-2"><table class="table table-sm mb-0"><thead><tr><th scope="col">When</th><th scope="col">Event</th><th scope="col">By</th></tr></thead><tbody>
            <?php foreach ($replacement_activity as $event): ?><tr><td><?= e(admin_utc_to_manila((string) $event['created_at_utc'])) ?></td><td><?= e((string) ($event['detail'] ?: str_replace('_', ' ', (string) $event['event_type']))) ?></td><td><?= e((string) ($event['actor_name'] ?? '—')) ?></td></tr><?php endforeach; ?>
        </tbody></table></div></details>
        <?php endif; ?>
    </section>
    <div class="row g-4">
        <div class="col-12 col-xl-7">
            <section class="content-card settings-card mb-4"><div class="settings-card-heading"><div><span class="settings-icon"><i class="bi bi-person"></i></span><div><h2>Admin Profile</h2><p>Update the information shown for your administrator account.</p></div></div></div><form method="post" class="row g-3"><input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>"><input type="hidden" name="action" value="save_profile"><div class="col-md-6"><label class="form-label" for="full_name">Full Name</label><input class="form-control" id="full_name" name="full_name" value="<?= e((string) ($admin['full_name'] ?? '')) ?>" maxlength="120" required></div><div class="col-md-6"><label class="form-label" for="username">Username</label><input class="form-control" id="username" name="username" value="<?= e((string) ($admin['username'] ?? '')) ?>" maxlength="60" required></div><div class="col-md-6"><label class="form-label" for="email">Email</label><input class="form-control" type="email" id="email" name="email" value="<?= e((string) ($admin['email'] ?? '')) ?>" maxlength="160"></div><div class="col-md-6"><label class="form-label" for="contact_number">Contact Number</label><input class="form-control" id="contact_number" name="contact_number" value="<?= e((string) ($admin['contact_number'] ?? '')) ?>" maxlength="40"></div><div class="col-12"><label class="form-label">Position / Role</label><input class="form-control" value="<?= e(ucfirst((string) ($admin['role'] ?? 'admin'))) ?>" readonly></div><div class="col-12"><button class="btn btn-success" type="submit"><i class="bi bi-check2"></i> Save Changes</button></div></form></section>
            <section class="content-card settings-card"><div class="settings-card-heading"><div><span class="settings-icon warning"><i class="bi bi-shield-lock"></i></span><div><h2>Change Password</h2><p>Use a strong password to protect the admin account.</p></div></div></div><form method="post" class="row g-3"><input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>"><input type="hidden" name="action" value="change_password"><div class="col-12"><label class="form-label" for="current_password">Current Password <span class="required-mark">*</span></label><input class="form-control" type="password" id="current_password" name="current_password" required></div><div class="col-md-6"><label class="form-label" for="new_password">New Password <span class="required-mark">*</span></label><input class="form-control" type="password" id="new_password" name="new_password" minlength="8" required></div><div class="col-md-6"><label class="form-label" for="confirm_password">Confirm New Password <span class="required-mark">*</span></label><input class="form-control" type="password" id="confirm_password" name="confirm_password" minlength="8" required></div><div class="col-12"><button class="btn btn-success" type="submit">Update Password</button></div></form></section>
        </div>
        <div class="col-12 col-xl-5">
            <section class="content-card settings-card mb-4"><div class="settings-card-heading"><div><span class="settings-icon"><i class="bi bi-bell"></i></span><div><h2>Notification Preferences</h2><p>Choose which admin alerts are enabled.</p></div></div></div><form method="post"><input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>"><input type="hidden" name="action" value="save_preferences"><?php foreach ([['new_farmer_registration','New Farmer Registration'],['farmer_verification_updates','Farmer Verification Updates'],['new_damage_reports','New Damage Reports'],['damage_report_status_changes','Damage Report Status Changes'],['weather_advisory_updates','Weather Advisory Updates']] as $preference): ?><div class="preference-row"><label for="<?= e($preference[0]) ?>"><?= e($preference[1]) ?></label><div class="form-check form-switch"><input class="form-check-input" type="checkbox" role="switch" id="<?= e($preference[0]) ?>" name="<?= e($preference[0]) ?>"<?= $preferences[$preference[0]] ? ' checked' : '' ?>></div></div><?php endforeach; ?><button class="btn btn-success mt-3" type="submit">Save Preferences</button></form></section>
            <section class="content-card settings-card mb-4"><div class="settings-card-heading"><div><span class="settings-icon info"><i class="bi bi-info-circle"></i></span><div><h2>System Information</h2><p>Read-only application details.</p></div></div></div><dl class="system-details"><div><dt>System Name</dt><dd>AgriReach</dd></div><div><dt>System Type</dt><dd>Mobile-Web Integrated Agricultural Damage Reporting and Weather-Driven Decision Support System</dd></div><div><dt>Administrator Role</dt><dd><?= e(ucfirst((string) ($admin['role'] ?? 'admin'))) ?></dd></div><div><dt>Database Status</dt><dd><span class="status-pill <?= $database_status === 'Connected' ? 'connected' : 'offline' ?>"><i class="bi bi-circle-fill"></i> <?= e($database_status) ?></span><?php if ($database_label !== ''): ?><small class="d-block text-muted mt-1"><?= e($database_label) ?></small><?php endif; ?></dd></div><div><dt>Application Version</dt><dd>1.0.0</dd></div></dl></section>
            <section class="content-card settings-card security-card"><div class="settings-card-heading"><div><span class="settings-icon danger"><i class="bi bi-box-arrow-right"></i></span><div><h2>Session Security</h2><p>End access to the current administrator session.</p></div></div></div><a class="btn btn-outline-danger" href="logout.php"><i class="bi bi-box-arrow-right"></i> Logout Current Session</a></section>
        </div>
    </div>
</div>

<div class="modal fade" id="inviteReplacementModal" tabindex="-1" aria-labelledby="inviteReplacementTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form method="post">
    <div class="modal-header"><div><p class="eyebrow mb-1">Admin Account</p><h2 class="modal-title" id="inviteReplacementTitle">Create Replacement Admin Account</h2></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body">
      <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>"><input type="hidden" name="action" value="invite_replacement">
      <p class="small">This starts a <strong>replacement</strong>, not a second administrator. The person below receives an email to set their own password. Nothing changes for you until you separately confirm <em>Transfer Admin Access</em>.</p>
      <div class="mb-3"><label class="form-label" for="replacement_name">Full name <span class="required-mark">*</span></label><input class="form-control<?= ($replacement_form['field'] ?? '') === 'full_name' ? ' is-invalid' : '' ?>" id="replacement_name" name="replacement_name" maxlength="120" required value="<?= e((string) ($replacement_form['name'] ?? '')) ?>" autocomplete="off"></div>
      <div class="mb-2"><label class="form-label" for="replacement_email">Email address <span class="required-mark">*</span></label><input class="form-control<?= ($replacement_form['field'] ?? '') === 'email' ? ' is-invalid' : '' ?>" type="email" id="replacement_email" name="replacement_email" maxlength="60" required value="<?= e((string) ($replacement_form['email'] ?? '')) ?>" autocomplete="off"><div class="form-text">Becomes the new admin's login username. It must not belong to you, a former admin or a farmer account.</div></div>
      <?php if ($prerequisites): ?><p class="small text-muted mb-1"><?= e($prerequisites['sender']['message']) ?></p><p class="small text-muted mb-0"><?= e($prerequisites['app_url']['message']) ?></p><?php endif; ?>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-success" type="submit"><i class="bi bi-send" aria-hidden="true"></i> Send Invitation</button></div>
  </form></div></div>
</div>
<?php if ($replacement !== null && $replacement['status'] === 'Ready'): ?>
<div class="modal fade" id="transferAdminModal" tabindex="-1" aria-labelledby="transferAdminTitle" aria-hidden="true" data-open-on-load="<?= $reopen_transfer ? '1' : '0' ?>">
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content"><form method="post">
    <div class="modal-header"><div><p class="eyebrow mb-1">Admin Account</p><h2 class="modal-title" id="transferAdminTitle">Transfer Admin Access</h2></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body">
      <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>"><input type="hidden" name="action" value="transfer_admin">
      <dl class="system-details mb-3"><div><dt>New administrator</dt><dd><?= e((string) $replacement['full_name']) ?></dd></div><div><dt>Login email</dt><dd><?= e((string) $replacement['email']) ?></dd></div></dl>
      <div class="alert alert-warning small" role="alert"><strong>You will lose administrator access.</strong> Your account (<?= e((string) ($admin['username'] ?? '')) ?>) is disabled immediately and every session you have open ends. The new administrator signs in with their own email and password. Reports, decisions, farmers, production records and the email sender are kept unchanged.</div>
      <div class="mb-3"><label class="form-label" for="transfer_current_password">Your current password <span class="required-mark">*</span></label><input class="form-control" type="password" id="transfer_current_password" name="current_password" autocomplete="current-password" required></div>
      <div class="form-check"><input class="form-check-input" type="checkbox" id="confirm_transfer" name="confirm_transfer" value="1" required><label class="form-check-label small" for="confirm_transfer">I understand that I will lose administrator access.</label></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Keep my access</button><button class="btn btn-danger" type="submit"><i class="bi bi-arrow-left-right" aria-hidden="true"></i> Transfer Admin Access</button></div>
  </form></div></div>
</div>
<?php endif; ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('form[data-confirm]').forEach((form) => form.addEventListener('submit', (event) => { if (!window.confirm(form.dataset.confirm)) event.preventDefault(); }));
  // Forms submit once: a second click while the request is in flight does nothing.
  document.querySelectorAll('.admin-account-card form, #inviteReplacementModal form, #transferAdminModal form').forEach((form) => form.addEventListener('submit', (event) => {
    if (event.defaultPrevented) return;
    if (form.dataset.submitting) { event.preventDefault(); return; }
    form.dataset.submitting = '1';
    form.querySelectorAll('button[type="submit"]').forEach((button) => { button.disabled = true; });
  }));
  <?php if ($replacement_form !== null): ?>bootstrap.Modal.getOrCreateInstance(document.getElementById('inviteReplacementModal')).show();<?php endif; ?>
  const transfer = document.getElementById('transferAdminModal');
  if (transfer?.dataset.openOnLoad === '1') bootstrap.Modal.getOrCreateInstance(transfer).show();
});
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
