<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__, 2) . '/backend/lib/admin_dashboard.php';
require_once dirname(__DIR__, 2) . '/backend/lib/email_settings.php';
function check_redesign(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); echo 'PASS: ' . $message . PHP_EOL; }
check_redesign(admin_dashboard_point_state(0, 0)[0] === 'missing', 'Dashboard rejects 0,0');
check_redesign(admin_dashboard_point_state(null, 125.5)[0] === 'missing', 'Dashboard rejects incomplete coordinates');
check_redesign(admin_dashboard_point_state(91, 181)[0] === 'missing', 'Dashboard rejects invalid coordinates');
check_redesign(admin_dashboard_point_state(14.6, 121)[0] === 'outside', 'Dashboard excludes out-of-city saved points without deleting records');
check_redesign(admin_dashboard_point_state(9.1172, 125.5296)[0] === 'inside', 'Dashboard checks saved city points with the existing boundary data');
foreach (default_registration_email_templates() as $decision => $template) validate_registration_email_template($decision, $template['subject'], $template['message_body']);
check_redesign(true, 'Safe default approval/rejection templates validate');
foreach (['Hello {farmer_name}, Password: example-not-a-secret', 'Hello {farmer_name}, use the password you registered example-not-a-secret.'] as $body) {
    try { validate_registration_email_template('Approved', 'Controlled template', $body); throw new RuntimeException('Unsafe text accepted'); }
    catch (InvalidArgumentException $expected) {}
}
check_redesign(true, 'Password-like pasted text is rejected');
$connection = db(); $before = load_registration_email_templates($connection);
try {
    $connection->beginTransaction();
    $adminId = (int) $connection->query('SELECT id FROM admins ORDER BY id LIMIT 1')->fetch()['id'];
    save_registration_email_template($connection, 'Approved', 'Controlled template subject', 'Hello {farmer_name}, your registration was approved. You may log in with your registered credentials.', $adminId);
    check_redesign(load_registration_email_template('Approved', $connection)['subject'] === 'Controlled template subject', 'Template save/read works inside a rolled-back transaction; no email sent');
} finally { $connection->rollBack(); }
check_redesign(load_registration_email_templates($connection) === $before, 'Original production templates retained after test rollback');
