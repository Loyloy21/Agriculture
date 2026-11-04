<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/../../backend/lib/farmer_verification.php';
require_once __DIR__ . '/../../backend/lib/admin_replacement.php';
require_admin();

$csrf_token = admin_csrf_token();
$admin_id = (int) $_SESSION['admin_id'];
$message = '';
$message_type = 'success';
$reopen_modal = null;
$sender_field_errors = [];
$sender_old_email = null;

function email_settings_redirect(string $message, string $type = 'success', ?string $modal = null, array $fieldErrors = [], ?string $senderEmail = null): never
{
    $_SESSION['email_settings_flash'] = $message;
    $_SESSION['email_settings_flash_type'] = $type;
    if ($modal !== null) $_SESSION['email_settings_reopen_modal'] = $modal;
    if ($fieldErrors) $_SESSION['email_settings_field_errors'] = $fieldErrors;
    if ($senderEmail !== null) $_SESSION['email_settings_sender_email'] = $senderEmail;
    header('Location: email-settings.php');
    exit;
}

function safe_email_failure_message(string $failureCode): string
{
    return match ($failureCode) {
        'smtp_configuration_missing', 'smtp_credentials_missing' => 'The Gmail sender is not fully configured.',
        'test_recipient_required' => 'Development safety routing requires a configured test recipient.',
        'invalid_recipient' => 'Enter a valid test recipient email address.',
        'mailer_not_installed' => 'The server email library is unavailable.',
        default => 'The SMTP server did not accept the email. Check the Gmail sender settings and try again.',
    };
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf_token, (string) ($_POST['csrf_token'] ?? ''))) {
        email_settings_redirect('Your session token is invalid. Please try again.', 'danger');
    }

    $action = (string) ($_POST['action'] ?? '');
    try {
        $connection = db();
        if ($action === 'save_sender') {
            $postedSenderEmail = trim((string) ($_POST['sender_email'] ?? ''));
            $postedPassword = preg_replace('/\s+/', '', trim((string) ($_POST['app_password'] ?? ''))) ?? '';
            $previousSender = registration_email_sender_record($connection);
            // The sender belongs to the system, not to an admin. In production, new credentials must sign in to Gmail
            // before they replace the working sender (the old sender stays untouched if Gmail refuses them).
            if ($postedPassword !== '' && preg_match('/^[A-Za-z0-9]{16}$/', $postedPassword) && filter_var($postedSenderEmail, FILTER_VALIDATE_EMAIL) && email_configuration()['app_environment'] === 'production') {
                try {
                    verify_registration_email_sender_login(strtolower($postedSenderEmail), $postedPassword);
                } catch (VerificationEmailException $exception) {
                    admin_account_event($connection, 'sender_change_rejected', $admin_id, null, null, 'Gmail did not accept new sender credentials for ' . strtolower($postedSenderEmail) . ' (' . $exception->failureCode . ')', admin_account_ip_hash());
                    email_settings_redirect('Gmail did not accept these sender credentials. The current sender was kept unchanged.', 'danger', 'senderModal', ['app_password' => 'Gmail did not accept this App Password for that sender.'], $postedSenderEmail);
                }
            }
            try {
                save_registration_email_sender(
                    $connection,
                    $postedSenderEmail,
                    (string) ($_POST['app_password'] ?? ''),
                    $admin_id
                );
            } catch (EmailSenderValidationException $exception) {
                email_settings_redirect($exception->getMessage(), 'danger', 'senderModal', [$exception->field => $exception->getMessage()], $postedSenderEmail);
            } catch (EmailEncryptionConfigurationException $exception) {
                email_settings_redirect($exception->getMessage(), 'danger', 'senderModal', ['configuration' => $exception->getMessage()], $postedSenderEmail);
            }
            if ($postedPassword !== '') {
                // The sender is already saved: an audit-trail failure is logged, not reported as a failed save.
                try {
                    admin_account_event($connection, 'sender_changed', $admin_id, null, null, 'System sender set to ' . strtolower($postedSenderEmail) . ($previousSender ? ' (previously ' . $previousSender['sender_email'] . ')' : ''), admin_account_ip_hash());
                } catch (Throwable $exception) {
                    error_log('Sender change saved but not audited: ' . get_class($exception));
                }
            }
            email_settings_redirect('Sender Gmail was saved securely. Send a test email before enabling production delivery.');
        }

        if ($action === 'send_test') {
            $testRecipient = strtolower(trim((string) ($_POST['test_recipient'] ?? '')));
            if (!filter_var($testRecipient, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('Enter a valid test recipient email address.');
            }
            try {
                $result = send_registration_test_email($testRecipient);
                if ($result !== 'Sent') {
                    throw new VerificationEmailException('smtp_not_accepted');
                }
                record_registration_email_test($connection, 'Accepted', null);
                email_settings_redirect('Gmail SMTP accepted the test email. Inbox delivery is not independently confirmed.');
            } catch (VerificationEmailException $exception) {
                record_registration_email_test($connection, 'Failed', $exception->failureCode);
                email_settings_redirect(safe_email_failure_message($exception->failureCode), 'danger');
            }
        }

        if ($action === 'save_template') {
            save_registration_email_template(
                $connection,
                (string) ($_POST['decision'] ?? ''),
                (string) ($_POST['subject'] ?? ''),
                (string) ($_POST['message_body'] ?? ''),
                $admin_id
            );
            email_settings_redirect('The registration email template was saved for future verification decisions.');
        }

        if ($action === 'retry_email') {
            $farmerId = filter_var($_POST['farmer_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($farmerId === false) {
                throw new InvalidArgumentException('Invalid email retry request.');
            }
            $retryConfiguration = registration_email_configuration($connection);
            $developmentTestRecipient = null;
            if ($retryConfiguration['app_environment'] !== 'production') {
                $developmentTestRecipient = strtolower(trim((string) ($_POST['test_recipient'] ?? '')));
                if (!filter_var($developmentTestRecipient, FILTER_VALIDATE_EMAIL)) {
                    throw new InvalidArgumentException('Enter a valid development test recipient. The real farmer will not be emailed while development safety routing is active.');
                }
            }
            $farmer = verification_email_retry_data($connection, (int) $farmerId);
            if (!$farmer) {
                email_settings_redirect('This delivery is no longer eligible for retry.', 'info');
            }
            $delivery = deliver_and_log_verification_email(
                $connection,
                $farmer,
                $admin_id,
                (string) $farmer['verification_status'],
                $farmer['verification_status'] === 'Rejected' ? (string) $farmer['rejection_reason'] : null,
                $developmentTestRecipient,
                (int) $farmer['failed_delivery_id']
            );
            if ($delivery['status'] === 'Sent') {
                email_settings_redirect($delivery['development_routed']
                    ? 'The SMTP server accepted the retried email for the explicit development test recipient. The real farmer was not emailed.'
                    : 'The SMTP server accepted the retried email. Inbox delivery is not independently confirmed.');
            }
            if ($delivery['status'] === 'Simulated') {
                email_settings_redirect('The retry was processed by the local test transport. No real email was sent.', 'info');
            }
            email_settings_redirect(verification_email_failure_message($delivery['failure_code']) . ' The farmer verification decision and in-app notification were not repeated.', 'warning');
        }

        throw new InvalidArgumentException('Unsupported Email Settings action.');
    } catch (InvalidArgumentException $exception) {
        email_settings_redirect($exception->getMessage(), 'danger');
    } catch (Throwable $exception) {
        error_log('Email Settings action failed with ' . get_class($exception) . '.');
        email_settings_redirect('The Email Settings action could not be completed.', 'danger');
    }
}

if (!empty($_SESSION['email_settings_flash'])) {
    $message = (string) $_SESSION['email_settings_flash'];
    $message_type = (string) ($_SESSION['email_settings_flash_type'] ?? 'success');
    unset($_SESSION['email_settings_flash'], $_SESSION['email_settings_flash_type']);
}
$reopen_modal = isset($_SESSION['email_settings_reopen_modal']) ? (string) $_SESSION['email_settings_reopen_modal'] : null;
$sender_field_errors = is_array($_SESSION['email_settings_field_errors'] ?? null) ? $_SESSION['email_settings_field_errors'] : [];
$sender_old_email = isset($_SESSION['email_settings_sender_email']) ? (string) $_SESSION['email_settings_sender_email'] : null;
unset($_SESSION['email_settings_reopen_modal'], $_SESSION['email_settings_field_errors'], $_SESSION['email_settings_sender_email']);

$sender_state = registration_email_sender_state();
$sender_email_value = $sender_old_email ?? (string) $sender_state['sender_email'];
$sender_can_keep_password = $sender_state['source'] === 'Admin settings' && $sender_state['credential_configured'];
if (!$sender_state['credential_available']) {
    $sender_setup_status = 'Not configured';
    $sender_setup_class = 'neutral';
    $sender_setup_description = 'Add a sender Gmail and Gmail App Password to begin.';
} elseif ($sender_state['last_test_status'] === 'Accepted') {
    $sender_setup_status = 'SMTP accepted';
    $sender_setup_class = 'accepted';
    $sender_setup_description = 'Gmail SMTP accepted the latest test. Inbox delivery is not independently confirmed.';
} elseif ($sender_state['last_test_status'] === 'Failed') {
    $sender_setup_status = 'Test failed';
    $sender_setup_class = 'failed';
    $sender_setup_description = 'The latest SMTP test failed. Review the sender and App Password, then test again.';
} else {
    $sender_setup_status = 'Saved but untested';
    $sender_setup_class = 'simulated';
    $sender_setup_description = 'Sender settings are saved. Send a test email before using production delivery.';
}
$templates = load_registration_email_templates();
$deliveries = [];
$load_error = '';
try {
    $deliveries = verification_email_delivery_history(db());
} catch (Throwable $exception) {
    $load_error = 'Email settings data is unavailable. Import the updated database schema first.';
}

$environment = email_configuration();
$development_routing = $environment['app_environment'] !== 'production';
$page_title = 'Email Settings';
$active_page = 'email-settings';
$page_css = 'assets/css/email-settings.css';
require_once __DIR__ . '/includes/header.php';
?>
<div class="container-fluid py-4 email-settings-page">
    <div class="mb-4">
        <p class="eyebrow mb-1">Registration email</p>
        <h1 class="page-title mb-1">Email Settings</h1>
        <p class="text-muted mb-0">Manage farmer registration approval and rejection emails.</p>
    </div>

    <?php if ($message !== ''): ?><div class="alert alert-<?= e($message_type) ?> alert-dismissible fade show" role="alert"><?= e($message) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div><?php endif; ?>
    <?php if ($load_error !== ''): ?><div class="alert alert-warning" role="alert"><?= e($load_error) ?></div><?php endif; ?>
    <?php if ($development_routing): ?><div class="alert alert-info safety-alert" role="status"><i class="bi bi-shield-check"></i><div><strong>Development safety routing is active.</strong><span>Verification emails will use the configured test recipient or fail safely; they will not be sent directly to farmers.</span></div></div><?php endif; ?>
    <?php if (!$development_routing): ?><div class="alert alert-success safety-alert live-delivery-alert" role="status"><i class="bi bi-envelope-check"></i><div><strong>Live farmer delivery is active.</strong><span>New approval and rejection emails, including reviewed retries, are sent to the farmer's registered email address.</span></div></div><?php endif; ?>

    <section class="content-card sender-card mb-4">
        <div class="card-heading mb-3">
            <div><h2>Sender Gmail</h2><p>This Gmail account is used only for farmer registration approval and rejection emails.</p></div>
        </div>
        <div class="sender-summary">
            <span class="sender-icon"><i class="bi bi-envelope-at"></i></span>
            <div class="sender-identity">
                <strong><?= e($sender_state['sender_email'] !== '' ? $sender_state['sender_email'] : 'No sender configured') ?></strong>
                <span><?= e((string) $sender_state['source']) ?></span>
            </div>
            <div class="sender-badges">
                <span class="email-status <?= e($sender_setup_class) ?>" title="<?= e($sender_setup_description) ?>"><i class="bi bi-circle-fill"></i> <?= e($sender_setup_status) ?></span>
            </div>
            <div class="sender-actions">
                <button class="btn btn-outline-success" type="button" data-bs-toggle="modal" data-bs-target="#senderModal">Configure Sender</button>
                <button class="btn btn-success" type="button" data-bs-toggle="modal" data-bs-target="#testEmailModal"<?= !$sender_state['credential_available'] ? ' disabled' : '' ?>><i class="bi bi-send"></i> Send Test Email</button>
            </div>
        </div>
        <?php if (!$sender_state['key_configured']): ?><p class="configuration-note warning mb-0"><i class="bi bi-exclamation-triangle"></i> Server encryption key required. Open Configure Sender for the exact local XAMPP setup steps.</p><?php endif; ?>
        <?php if (!empty($sender_state['last_tested_at'])): ?><p class="configuration-note mb-0">Last SMTP test: <?= e(date('M d, Y h:i A', strtotime((string) $sender_state['last_tested_at']))) ?>. “Accepted” means accepted by SMTP, not confirmed inbox delivery.</p><?php endif; ?>
    </section>

    <div class="row g-4 mb-4">
        <?php foreach (['Approved' => ['Approval Email', 'bi-envelope-check', 'approval'], 'Rejected' => ['Rejection Email', 'bi-envelope-x', 'rejection']] as $decision => $meta): ?>
            <div class="col-12 col-xl-6">
                <section class="content-card template-card h-100">
                    <div class="template-heading"><span class="template-icon <?= e($meta[2]) ?>"><i class="bi <?= e($meta[1]) ?>"></i></span><div><h2><?= e($meta[0]) ?></h2><p>Email sent for future <?= strtolower(e($decision)) ?> registration decisions.</p></div></div>
                    <form method="post" class="template-form" data-template-decision="<?= e($decision) ?>">
                        <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">
                        <input type="hidden" name="action" value="save_template">
                        <input type="hidden" name="decision" value="<?= e($decision) ?>">
                        <label class="form-label" for="<?= strtolower(e($decision)) ?>Subject">Subject</label>
                        <input class="form-control template-subject" id="<?= strtolower(e($decision)) ?>Subject" name="subject" value="<?= e((string) $templates[$decision]['subject']) ?>" maxlength="180" required>
                        <label class="form-label mt-3" for="<?= strtolower(e($decision)) ?>Message">Message</label>
                        <textarea class="form-control template-message" id="<?= strtolower(e($decision)) ?>Message" name="message_body" rows="7" maxlength="5000" required><?= e((string) $templates[$decision]['message_body']) ?></textarea>
                        <p class="placeholder-help">Allowed placeholders: <code>{farmer_name}</code><?= $decision === 'Rejected' ? ' and <code>{rejection_reason}</code>' : '' ?>.</p>
                        <div class="template-actions"><button class="btn btn-outline-success preview-template" type="button"><i class="bi bi-eye"></i> Preview</button><button class="btn btn-success" type="submit"><i class="bi bi-check2"></i> Save Template</button></div>
                    </form>
                </section>
            </div>
        <?php endforeach; ?>
    </div>

    <section class="content-card delivery-card">
        <div class="card-heading"><div><h2>Recent delivery status</h2><p>Latest registration decision email attempts. SMTP acceptance does not confirm inbox delivery.</p></div></div>
        <div class="table-responsive">
            <table class="table align-middle delivery-table mb-0">
                <thead><tr><th>Farmer</th><th>Decision</th><th>Intended Recipient</th><th>Route</th><th>Delivered To</th><th>Email Status</th><th>Date</th><th class="text-end">Action</th></tr></thead>
                <tbody>
                <?php if (!$deliveries): ?><tr><td colspan="8" class="text-center py-5 text-muted">No registration email attempts yet.</td></tr><?php endif; ?>
                <?php foreach ($deliveries as $delivery):
                    $status_label = match ($delivery['status']) { 'Sent' => 'SMTP Accepted', 'Simulated' => 'Test Only', default => (string) $delivery['status'] };
                    $status_class = match ($delivery['status']) { 'Sent' => 'accepted', 'Failed' => 'failed', 'Simulated' => 'simulated', default => 'neutral' };
                ?>
                    <tr>
                        <td><strong><?= e((string) $delivery['full_name']) ?></strong></td>
                        <td><span class="decision-badge <?= strtolower(e((string) $delivery['decision'])) ?>"><?= e((string) $delivery['decision']) ?></span></td>
                        <td><?= e((string) $delivery['recipient_email']) ?></td>
                        <td><span class="route-badge <?= $delivery['routing_mode'] === 'Live' ? 'live' : ($delivery['routing_mode'] === 'Development Test' ? 'test' : 'unknown') ?>"><?= e((string) $delivery['routing_mode']) ?></span></td>
                        <td><?= e((string) ($delivery['delivery_recipient_email'] ?: 'Not sent / not recorded')) ?></td>
                        <td><span class="email-status <?= e($status_class) ?>"><i class="bi bi-circle-fill"></i> <?= e($status_label) ?></span><?php if ($delivery['status'] === 'Failed'): ?><small class="delivery-failure-reason"><?= e(verification_email_failure_message((string) $delivery['failure_code'])) ?></small><?php endif; ?></td>
                        <td><?= e(date('M d, Y h:i A', strtotime((string) $delivery['attempted_at']))) ?></td>
                        <td class="text-end">
                            <?php if ((int) $delivery['can_retry'] === 1): ?>
                                <form method="post" class="d-flex justify-content-end align-items-center gap-2"<?= !$development_routing ? ' onsubmit="return confirm(\'Send this decision email to the farmer registered address?\');"' : '' ?>>
                                    <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">
                                    <input type="hidden" name="action" value="retry_email">
                                    <input type="hidden" name="farmer_id" value="<?= (int) $delivery['farmer_id'] ?>">
                                    <?php if ($development_routing): ?><input class="form-control form-control-sm retry-test-recipient" type="email" name="test_recipient" placeholder="Test recipient" aria-label="Development test recipient for <?= e((string) $delivery['full_name']) ?>" autocomplete="email" required><?php endif; ?>
                                    <button class="btn btn-outline-success btn-sm" type="submit"><?= $development_routing ? 'Retry Test' : 'Retry to Farmer' ?></button>
                                </form>
                            <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

<div class="modal fade" id="senderModal" tabindex="-1" aria-labelledby="senderModalTitle" aria-describedby="senderModalDescription" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content email-modal sender-setup-modal">
            <form method="post" class="sender-setup-form">
                <div class="modal-header">
                    <div><p class="eyebrow mb-1">Secure Gmail SMTP</p><h2 class="modal-title fs-5" id="senderModalTitle">Configure Sender Gmail</h2><p class="modal-description mb-0" id="senderModalDescription">Follow these steps to connect a Gmail sender for registration decisions.</p></div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close Configure Sender Gmail"></button>
                </div>
                <div class="modal-body" tabindex="0" aria-label="Gmail sender setup steps">
                    <input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>">
                    <input type="hidden" name="action" value="save_sender">

                    <?php if (!$sender_state['key_configured'] || isset($sender_field_errors['configuration'])): ?>
                        <div class="encryption-setup-alert" role="alert" id="encryptionSetupAlert">
                            <div class="encryption-setup-heading"><i class="bi bi-shield-exclamation"></i><div><strong>Server encryption key required</strong><span>AgriReach cannot safely save a Gmail App Password until this key is available to Apache.</span></div></div>
                            <ol>
                                <li>Create this folder outside the public web directory: <code>C:\xampp\agrireach-secrets</code></li>
                                <li>Create <code>email.key</code> there containing a Base64-encoded 32-byte random key.</li>
                                <li>Restart Apache from the XAMPP Control Panel, reload this page, and confirm this warning disappears.</li>
                            </ol>
                            <p class="mb-1"><strong>Run once in PowerShell:</strong></p>
                            <pre class="encryption-setup-command" tabindex="0"><code>$secretDir = 'C:\xampp\agrireach-secrets'
New-Item -ItemType Directory -Force -Path $secretDir | Out-Null
$keyBytes = New-Object byte[] 32
$rng = [Security.Cryptography.RandomNumberGenerator]::Create()
$rng.GetBytes($keyBytes)
$rng.Dispose()
[IO.File]::WriteAllText((Join-Path $secretDir 'email.key'), [Convert]::ToBase64String($keyBytes))</code></pre>
                            <p class="mb-0">Expected path: <code><?= e(registration_email_default_key_file()) ?></code>. For a different external path, set <code>AGRIREACH_EMAIL_ENCRYPTION_KEY_FILE</code> for the Apache process.</p>
                        </div>
                    <?php endif; ?>

                    <section class="setup-step" aria-labelledby="gmailStepOne">
                        <span class="setup-step-number" aria-hidden="true">1</span>
                        <div class="setup-step-content">
                            <h3 id="gmailStepOne">Prepare Gmail</h3>
                            <p>Turn on 2-Step Verification for the Gmail account you will use to send farmer approval and rejection emails.</p>
                            <a class="btn btn-outline-success btn-sm setup-link" href="https://myaccount.google.com/signinoptions/two-step-verification" target="_blank" rel="noopener noreferrer"><i class="bi bi-box-arrow-up-right"></i> Open Google 2-Step Verification settings</a>
                        </div>
                    </section>

                    <section class="setup-step" aria-labelledby="gmailStepTwo">
                        <span class="setup-step-number" aria-hidden="true">2</span>
                        <div class="setup-step-content">
                            <h3 id="gmailStepTwo">Create App Password</h3>
                            <p>Create an App Password named <strong>AgriReach</strong>. This is different from your normal Gmail password.</p>
                            <a class="btn btn-outline-success btn-sm setup-link" href="https://myaccount.google.com/apppasswords" target="_blank" rel="noopener noreferrer"><i class="bi bi-box-arrow-up-right"></i> Open Google App Passwords</a>
                            <p class="setup-help mb-0"><i class="bi bi-info-circle"></i> If App Passwords is unavailable, confirm that 2-Step Verification is enabled. Google may also hide it for security-key-only verification, managed work or school accounts, or accounts using Advanced Protection. Contact your Google Workspace administrator or use an eligible Gmail account.</p>
                        </div>
                    </section>

                    <section class="setup-step" aria-labelledby="gmailStepThree">
                        <span class="setup-step-number" aria-hidden="true">3</span>
                        <div class="setup-step-content">
                            <h3 id="gmailStepThree">Connect sender</h3>
                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="form-label" for="senderEmail">Sender Gmail</label>
                                    <input class="form-control<?= isset($sender_field_errors['sender_email']) ? ' is-invalid' : '' ?>" type="email" id="senderEmail" name="sender_email" value="<?= e($sender_email_value) ?>" placeholder="Enter the Gmail sender address" maxlength="160" autocomplete="email" inputmode="email" required aria-describedby="senderEmailHelp senderEmailError"<?= isset($sender_field_errors['sender_email']) ? ' aria-invalid="true"' : '' ?>>
                                    <p class="form-text mb-0" id="senderEmailHelp">Use the same Gmail account where you created the AgriReach App Password.</p>
                                    <div class="invalid-feedback" id="senderEmailError"><?= e((string) ($sender_field_errors['sender_email'] ?? 'Enter a valid Gmail or Google Workspace sender email address.')) ?></div>
                                </div>
                                <div class="col-12">
                                    <label class="form-label" for="appPassword">Gmail App Password</label>
                                    <input class="form-control<?= isset($sender_field_errors['app_password']) ? ' is-invalid' : '' ?>" type="password" id="appPassword" name="app_password" placeholder="Enter the 16-character App Password" maxlength="32" autocomplete="new-password" aria-describedby="appPasswordHelp appPasswordError"<?= $sender_can_keep_password ? '' : ' required' ?><?= isset($sender_field_errors['app_password']) ? ' aria-invalid="true"' : '' ?>>
                                    <p class="form-text mb-0" id="appPasswordHelp">Never enter the normal Gmail account password. Use only the 16-character App Password generated by Google. The saved App Password is encrypted and is never displayed.<?= $sender_can_keep_password ? ' Leave App Password blank to keep the current one.' : '' ?></p>
                                    <div class="invalid-feedback" id="appPasswordError"><?= e((string) ($sender_field_errors['app_password'] ?? 'Enter the 16-character Gmail App Password.')) ?></div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="setup-step setup-step-final" aria-labelledby="gmailStepFour">
                        <span class="setup-step-number" aria-hidden="true">4</span>
                        <div class="setup-step-content">
                            <h3 id="gmailStepFour">Test</h3>
                            <div class="setup-status <?= e($sender_setup_class) ?>" role="status"><i class="bi bi-circle-fill"></i><div><strong><?= e($sender_setup_status) ?></strong><span><?= e($sender_setup_description) ?></span></div></div>
                            <p class="mb-0">After saving, select <strong>Send Test Email</strong> on the Email Settings page and enter your own test recipient address.</p>
                        </div>
                    </section>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-light" type="button" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-success" type="submit"><i class="bi bi-shield-check"></i> Save Sender</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="testEmailModal" tabindex="-1" aria-labelledby="testEmailModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content email-modal"><form method="post"><div class="modal-header"><div><p class="eyebrow mb-1">SMTP test</p><h2 class="modal-title fs-5" id="testEmailModalTitle">Send Test Email</h2></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><input type="hidden" name="csrf_token" value="<?= e($csrf_token) ?>"><input type="hidden" name="action" value="send_test"><label class="form-label" for="testRecipient">Test Recipient</label><input class="form-control" type="email" id="testRecipient" name="test_recipient" placeholder="Enter your test email address" maxlength="160" autocomplete="email" required><p class="form-text mb-0">This sends only to the address entered here. SMTP acceptance does not guarantee inbox delivery.</p></div><div class="modal-footer"><button class="btn btn-light" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-success" type="submit"><i class="bi bi-send"></i> Send Test</button></div></form></div></div>
</div>

<div class="modal fade" id="templatePreviewModal" tabindex="-1" aria-labelledby="templatePreviewTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered"><div class="modal-content email-modal"><div class="modal-header"><div><p class="eyebrow mb-1">Template preview</p><h2 class="modal-title fs-5" id="templatePreviewTitle">Email Preview</h2></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><p class="preview-label">Subject</p><h3 id="previewSubject"></h3><hr><p class="preview-label">Message</p><div id="previewMessage" class="preview-message"></div></div><div class="modal-footer"><button class="btn btn-success" type="button" data-bs-dismiss="modal">Close Preview</button></div></div></div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const senderModalElement = document.getElementById('senderModal');
  const senderForm = senderModalElement?.querySelector('.sender-setup-form');
  const senderEmail = document.getElementById('senderEmail');
  const appPassword = document.getElementById('appPassword');
  const senderEmailError = document.getElementById('senderEmailError');
  const appPasswordError = document.getElementById('appPasswordError');

  const setFieldError = (field, feedback, message) => {
    field.classList.add('is-invalid');
    field.setAttribute('aria-invalid', 'true');
    feedback.textContent = message;
  };

  const clearFieldError = (field) => {
    field.classList.remove('is-invalid');
    field.removeAttribute('aria-invalid');
  };

  if (senderForm && senderEmail && appPassword && senderEmailError && appPasswordError) {
    senderEmail.addEventListener('input', () => clearFieldError(senderEmail));
    appPassword.addEventListener('input', () => clearFieldError(appPassword));

    senderForm.addEventListener('submit', (event) => {
      clearFieldError(senderEmail);
      clearFieldError(appPassword);

      const emailValue = senderEmail.value.trim().toLowerCase();
      const passwordValue = appPassword.value.replace(/\s+/g, '');
      const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
      let firstInvalidField = null;

      if (!emailPattern.test(emailValue)) {
        setFieldError(senderEmail, senderEmailError, 'Enter a valid Gmail or Google Workspace sender email address.');
        firstInvalidField = senderEmail;
      } else {
        senderEmail.value = emailValue;
      }

      if ((appPassword.required || passwordValue !== '') && !/^[A-Za-z0-9]{16}$/.test(passwordValue)) {
        setFieldError(appPassword, appPasswordError, appPassword.required && passwordValue === ''
          ? 'Enter the Gmail App Password when configuring the sender.'
          : 'Enter the 16-character Gmail App Password, not the ordinary Gmail password.');
        firstInvalidField ??= appPassword;
      } else if (passwordValue !== '') {
        appPassword.value = passwordValue;
      }

      if (firstInvalidField) {
        event.preventDefault();
        firstInvalidField.focus();
        firstInvalidField.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
    });
  }

  if (<?= $reopen_modal === 'senderModal' ? 'true' : 'false' ?> && senderModalElement) {
    bootstrap.Modal.getOrCreateInstance(senderModalElement).show();
  }

  const previewModal = bootstrap.Modal.getOrCreateInstance(document.getElementById('templatePreviewModal'));
  document.querySelectorAll('.preview-template').forEach((button) => {
    button.addEventListener('click', () => {
      const form = button.closest('.template-form');
      const decision = form.dataset.templateDecision;
      const replacements = {
        '{farmer_name}': 'Sample Farmer',
        '{rejection_reason}': 'Sample reason entered by the administrator'
      };
      const render = (value) => Object.entries(replacements).reduce((text, [placeholder, sample]) => text.split(placeholder).join(sample), value);
      document.getElementById('templatePreviewTitle').textContent = `${decision} Email Preview`;
      document.getElementById('previewSubject').textContent = render(form.querySelector('.template-subject').value);
      document.getElementById('previewMessage').textContent = render(form.querySelector('.template-message').value);
      previewModal.show();
    });
  });
});
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
