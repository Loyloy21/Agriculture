<?php
declare(strict_types=1);

require_once __DIR__ . '/../support/test_base_url.php';

// Single-admin replacement end to end over the local test server (non-sending 'log' mail transport): invitation
// validation and conflicts, one open process, hashed single-use tokens in the URL fragment, resend/cancel/expiry,
// setup without access, re-authenticated atomic transfer, revoked old sessions, concurrent transfers, the database
// single-active-admin guarantee, Manage Users and the recovery script. Explicitly local-only (refuses Supabase).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__, 2) . '/backend/config/database.php';
require_once dirname(__DIR__, 2) . '/backend/lib/admin_replacement.php';
require_once dirname(__DIR__) . '/support/admin_test_account.php';

function replace_test(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
function replace_admin_url(string $path): string
{
    $base = agrireach_test_base_url() . '/CAPSTONE%20AGRICULTURE/admin/';
    if (!in_array(parse_url($base, PHP_URL_HOST), ['127.0.0.1', 'localhost'], true)) throw new RuntimeException('Integration tests require a loopback URL.');
    return $base . $path;
}
/** @return array{status:int,location:string,body:string,json:mixed} */
function replace_http(string $method, string $path, ?string $jar, array|string|null $body = null, array $headers = []): array
{
    $handle = curl_init(replace_admin_url($path));
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 90, CURLOPT_HTTPHEADER => $headers]);
    if ($jar !== null) curl_setopt_array($handle, [CURLOPT_COOKIEFILE => $jar, CURLOPT_COOKIEJAR => $jar]);
    if ($body !== null) curl_setopt($handle, CURLOPT_POSTFIELDS, is_array($body) ? http_build_query($body) : $body);
    $raw = curl_exec($handle);
    if ($raw === false) throw new RuntimeException('Local test HTTP connection failed.');
    $size = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);
    $head = substr((string) $raw, 0, $size);
    $responseBody = substr((string) $raw, $size);
    return ['status' => $status, 'location' => preg_match('/^Location:\s*(.+)$/mi', $head, $m) ? trim($m[1]) : '', 'body' => $responseBody, 'json' => json_decode($responseBody, true)];
}
function replace_login(string $username, string $password): string
{
    $jar = tempnam(sys_get_temp_dir(), 'agrireach-replace-');
    replace_http('GET', 'login.php', $jar);
    $result = replace_http('POST', 'login.php', $jar, ['username' => $username, 'password' => $password]);
    replace_test($result['status'] === 302 && str_ends_with($result['location'], 'dashboard.php'), "Login as {$username} must succeed (got {$result['status']}).");
    return $jar;
}
function replace_login_fails(string $username, string $password): bool
{
    $jar = tempnam(sys_get_temp_dir(), 'agrireach-replace-');
    $result = replace_http('POST', 'login.php', $jar, ['username' => $username, 'password' => $password]);
    @unlink($jar);
    return $result['status'] === 200 && str_contains($result['body'], 'Invalid login credentials');
}
function replace_csrf(string $jar): string
{
    $page = replace_http('GET', 'settings.php', $jar);
    replace_test($page['status'] === 200 && preg_match('/name="csrf_token" value="([^"]+)"/', $page['body'], $m) === 1, 'Settings page with CSRF token.');
    return html_entity_decode($m[1]);
}
/** POSTs a Settings action and returns the next Settings page (the flash message is shown there). */
function replace_settings(string $jar, array $fields): array
{
    $post = replace_http('POST', 'settings.php', $jar, ['csrf_token' => replace_csrf($jar)] + $fields);
    if ($post['status'] === 302 && str_contains($post['location'], 'login.php')) return $post;
    $page = replace_http('GET', 'settings.php', $jar);
    return $page + ['post' => $post];
}
function replace_captured_token(string $email, array $forbidden = []): string
{
    $directory = (string) getenv('AGRIREACH_TEST_MAIL_CAPTURE_DIR');
    $file = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . 'admin-account-' . hash('sha256', strtolower($email)) . '.json';
    replace_test(is_file($file), 'The simulated invitation was captured for ' . $email);
    $mail = json_decode((string) file_get_contents($file), true);
    replace_test(preg_match('~(https?://\S+/admin/admin-invitation\.php)#token=([A-Za-z0-9_-]{43})~', $mail['text'], $m) === 1, 'Invitation link with the token in the URL fragment.');
    replace_test(str_starts_with($m[1], (string) admin_replacement_app_url()), 'Link built from the trusted APP_URL.');
    foreach ($forbidden as $secret) replace_test(!str_contains($mail['text'], $secret), 'No password in the email.');
    return $m[2];
}
function replace_invite_api(string $action, string $token, array $extra = []): array
{
    return replace_http('POST', 'admin-invitation.php', null, json_encode(['action' => $action, 'token' => $token] + $extra), ['Content-Type: application/json', 'Accept: application/json']);
}
function replace_fingerprint(PDO $connection, string $table, string $order = 'id'): string
{
    return hash('sha256', json_encode($connection->query("SELECT * FROM {$table} ORDER BY {$order}")->fetchAll()));
}

test_admin_refuse_live();
replace_test(admin_replacement_app_url() !== null, 'APP_URL must be configured for this test.');
replace_test(str_contains((string) getenv('MAIL_TRANSPORT'), 'log'), 'This test only runs with the non-sending log transport.');
$connection = db();
$watched = ['farmers' => 'id', 'damage_reports' => 'id', 'production_records' => 'id', 'registration_email_settings' => 'id', 'registration_email_templates' => 'decision', 'admin_responses' => 'id'];
$before = [];
foreach ($watched as $table => $order) $before[$table] = replace_fingerprint($connection, $table, $order);
$firstEvent = (int) $connection->query('SELECT COALESCE(MAX(id), 0) FROM admin_account_events')->fetchColumn();
$firstInvitation = (int) $connection->query('SELECT COALESCE(MAX(id), 0) FROM admin_replacement_invitations')->fetchColumn();
$stamp = bin2hex(random_bytes(5));
$people = [];
foreach (['a', 'b', 'c', 'd', 'e', 'f'] as $key) $people[$key] = ['name' => 'Replace ' . strtoupper($key) . ' ' . $stamp, 'email' => "replace-{$key}-{$stamp}@example.test", 'password' => 'Replace' . $key . bin2hex(random_bytes(4)) . 'A1'];
$takeover = null;
$jars = [];
$createdAdminUsernames = [];
$formerId = null;
$farmerId = null;
try {
    // The database itself refuses a second active admin.
    $takeover = test_admin_takeover($connection, $people['a']['name'], $people['a']['email'], password_hash($people['a']['password'], PASSWORD_DEFAULT), $people['a']['email']);
    $createdAdminUsernames[] = $people['a']['email'];
    $connection->beginTransaction();
    try {
        $connection->prepare("INSERT INTO admins (full_name, username, password_hash, role, status, account_status) VALUES ('Second admin', ?, 'x', 'admin', 'active', 'Active')")->execute(['second-' . $stamp]);
        $connection->rollBack();
        throw new RuntimeException('A second active admin was accepted.');
    } catch (PDOException $exception) {
        if ($connection->inTransaction()) $connection->rollBack();
        replace_test(db_is_duplicate_key($exception), 'Second active admin refused by the unique index.');
    }
    echo "PASS: the database refuses a second active administrator\n";

    // Fixtures for conflicts: a former admin and a farmer.
    $connection->prepare("INSERT INTO admins (full_name, username, password_hash, role, status, email, account_status) VALUES ('Former admin', ?, 'x', 'admin', 'inactive', ?, 'Inactive')")->execute(['former-' . $stamp, "former-{$stamp}@example.test"]);
    $formerId = db_insert_id($connection, 'admins');
    $connection->prepare("INSERT INTO farmers (full_name, contact_number, email, address, account_status, verification_status) VALUES ('Replace farmer', ?, ?, 'x', 'Active', 'Approved')")->execute(['+639' . random_int(100000000, 999999999), "farmer-{$stamp}@example.test"]);
    $farmerId = (int) $connection->lastInsertId();

    // Access control.
    replace_test(replace_http('GET', 'settings.php', null)['status'] === 302, 'Settings requires an admin session.');
    replace_test(replace_http('POST', 'settings.php', null, ['action' => 'transfer_admin'])['status'] === 302, 'Transfer requires an admin session.');
    $jarA = replace_login($people['a']['email'], $people['a']['password']);
    $jars[] = $jarA;
    $page = replace_http('GET', 'settings.php', $jarA);
    replace_test(str_contains($page['body'], 'Create Replacement Admin Account') && str_contains($page['body'], 'Admin Account') && str_contains($page['body'], 'It stays the same when the admin is replaced'), 'Admin Account section with the replacement action.');
    $noCsrf = replace_http('POST', 'settings.php', $jarA, ['action' => 'invite_replacement', 'replacement_name' => 'X', 'replacement_email' => $people['b']['email']]);
    replace_test(str_contains(replace_http('GET', 'settings.php', $jarA)['body'], 'session token is invalid'), 'CSRF token required.');

    // Invitation validation and conflicts (nothing is created).
    foreach ([
        [['replacement_name' => 'Someone', 'replacement_email' => $people['a']['email']], 'This is your own administrator account'],
        [['replacement_name' => 'Someone', 'replacement_email' => strtoupper("former-{$stamp}@example.test")], 'former administrator account'],
        [['replacement_name' => 'Someone', 'replacement_email' => "farmer-{$stamp}@example.test"], 'registered to a farmer account'],
        [['replacement_name' => 'Someone', 'replacement_email' => 'not-an-email'], 'valid email address'],
        [['replacement_name' => 'S', 'replacement_email' => $people['b']['email']], 'full name'],
        [['replacement_name' => 'Someone', 'replacement_email' => str_repeat('x', 50) . "@example.test"], 'at most 60 characters'],
    ] as [$fields, $expected]) {
        $result = replace_settings($jarA, ['action' => 'invite_replacement'] + $fields);
        replace_test(str_contains($result['body'], $expected), 'Invite rejected: ' . $expected);
    }
    replace_test((int) $connection->query('SELECT COUNT(*) FROM admin_replacement_invitations WHERE id > ' . $firstInvitation)->fetchColumn() === 0, 'Rejected invitations create nothing.');
    echo "PASS: access control, CSRF, invitation validation (own account, former admin, farmer email, format) without side effects\n";

    // Invite B: simulated email, hashed token only, one open process at a time.
    $result = replace_settings($jarA, ['action' => 'invite_replacement', 'replacement_name' => $people['b']['name'], 'replacement_email' => $people['b']['email']]);
    replace_test(str_contains($result['body'], 'only simulates email') && str_contains($result['body'], 'Invitation pending') && str_contains($result['body'], 'Resend Invitation'), 'Invitation created; the page says honestly that email was simulated.');
    $tokenB1 = replace_captured_token($people['b']['email'], [$people['a']['password']]);
    $row = $connection->query('SELECT * FROM admin_replacement_invitations WHERE id > ' . $firstInvitation . ' ORDER BY id DESC LIMIT 1')->fetch();
    replace_test($row['status'] === 'Pending' && $row['token_hash'] === hash('sha256', $tokenB1) && !str_contains(json_encode($row), $tokenB1) && $row['password_hash'] === null && $row['email_status'] === 'Simulated', 'Only the token hash is stored.');
    $result = replace_settings($jarA, ['action' => 'invite_replacement', 'replacement_name' => $people['c']['name'], 'replacement_email' => $people['c']['email']]);
    replace_test(str_contains($result['body'], 'already open'), 'Only one replacement process at a time.');
    // Resend: a new link; the previous one stops working.
    $result = replace_settings($jarA, ['action' => 'resend_invitation']);
    replace_test(str_contains($result['body'], 'only simulates email'), 'Resent.');
    $tokenB2 = replace_captured_token($people['b']['email']);
    replace_test($tokenB2 !== $tokenB1, 'Resend issues a new token.');
    $old = replace_invite_api('check', $tokenB1);
    replace_test($old['status'] === 410 && $old['json']['state'] === 'invalid', 'The previous link no longer works.');
    echo "PASS: invitation sent through the system mailer (simulated), token stored only as a hash, one open process, resend invalidates the old link\n";

    // The invitation page: token in the fragment only, setup grants nothing.
    $pageHtml = replace_http('GET', 'admin-invitation.php', null);
    replace_test($pageHtml['status'] === 200 && str_contains($pageHtml['body'], 'location.hash') && !str_contains($pageHtml['body'], 'name="csrf_token"'), 'Invitation page reads the token from the fragment.');
    $check = replace_invite_api('check', $tokenB2);
    replace_test($check['status'] === 200 && $check['json']['email'] === $people['b']['email'] && $check['json']['fullName'] === $people['b']['name'], 'Valid invitation shows the invited name and email.');
    $weak = replace_invite_api('complete', $tokenB2, ['password' => 'short', 'passwordConfirmation' => 'short']);
    replace_test($weak['status'] === 422 && $weak['json']['field'] === 'password', 'Password policy enforced.');
    $mismatch = replace_invite_api('complete', $tokenB2, ['password' => $people['b']['password'], 'passwordConfirmation' => $people['b']['password'] . 'x']);
    replace_test($mismatch['status'] === 422 && $mismatch['json']['field'] === 'password_confirmation', 'Confirmation must match.');
    $otherEmail = replace_invite_api('complete', $tokenB2, ['password' => $people['b']['password'], 'passwordConfirmation' => $people['b']['password'], 'email' => 'attacker@example.test']);
    replace_test($otherEmail['status'] === 200 && $otherEmail['json']['email'] === $people['b']['email'], 'The email cannot be changed during setup (extra fields ignored).');
    $again = replace_invite_api('complete', $tokenB2, ['password' => $people['b']['password'], 'passwordConfirmation' => $people['b']['password']]);
    replace_test($again['status'] === 410 && str_contains($again['json']['message'], 'already used'), 'The invitation is single-use.');
    replace_test(replace_login_fails($people['b']['email'], $people['b']['password']), 'A ready replacement cannot sign in before the transfer.');
    replace_test(admin_active_account($connection, $takeover['id']) !== null, 'The current admin is still active.');
    $page = replace_http('GET', 'settings.php', $jarA);
    replace_test(str_contains($page['body'], 'Ready for Transfer') && str_contains($page['body'], 'Transfer Admin Access'), 'Settings shows Ready for Transfer.');
    echo "PASS: setup page (fragment token, password policy, fixed email, single use); setup grants no access; current admin unchanged\n";

    // Transfer: re-authentication and explicit confirmation required; failures change nothing.
    $jarA2 = replace_login($people['a']['email'], $people['a']['password']);
    $jars[] = $jarA2;
    $result = replace_settings($jarA, ['action' => 'transfer_admin', 'current_password' => 'wrong-password', 'confirm_transfer' => '1']);
    replace_test(str_contains($result['body'], 'current password is incorrect') && admin_active_account($connection, $takeover['id']) !== null, 'Wrong password: nothing changes.');
    $result = replace_settings($jarA, ['action' => 'transfer_admin', 'current_password' => $people['a']['password']]);
    replace_test(str_contains($result['body'], 'Confirm that you understand') && admin_active_account($connection, $takeover['id']) !== null, 'Confirmation required.');
    $result = replace_settings($jarA, ['action' => 'transfer_admin', 'current_password' => $people['a']['password'], 'confirm_transfer' => '1']);
    replace_test($result['status'] === 302 && str_contains($result['location'], 'login.php?transferred=1') && !str_contains($result['location'], 'notifications=incomplete'), 'Transfer completes and signs the old admin out.');
    $login = replace_http('GET', 'login.php?transferred=1', null);
    replace_test(str_contains($login['body'], 'Administrator access was transferred'), 'Transfer-completed message on the login page.');
    $state = $connection->query("SELECT id, username, status, account_status FROM admins WHERE status = 'active'")->fetchAll();
    replace_test(count($state) === 1 && $state[0]['username'] === $people['b']['email'] && $state[0]['account_status'] === 'Active', 'Exactly one active admin: the replacement.');
    $createdAdminUsernames[] = $people['b']['email'];
    $oldRow = $connection->prepare('SELECT status, account_status FROM admins WHERE id = ?');
    $oldRow->execute([$takeover['id']]);
    replace_test($oldRow->fetch() == ['status' => 'inactive', 'account_status' => 'Inactive'], 'The old admin row is kept but disabled.');
    $closed = $connection->query('SELECT status, password_hash, replacement_admin_id FROM admin_replacement_invitations WHERE id > ' . $firstInvitation . ' ORDER BY id DESC LIMIT 1')->fetch();
    replace_test($closed['status'] === 'Completed' && $closed['password_hash'] === null && (int) $closed['replacement_admin_id'] === (int) $state[0]['id'], 'Process closed; stored setup hash cleared.');
    // Old sessions stop working; the old account cannot sign in; the new admin uses their own credentials.
    $stale = replace_http('GET', 'dashboard.php', $jarA2);
    replace_test($stale['status'] === 302 && str_contains($stale['location'], 'login.php?ended=1'), 'An already-open session of the old admin is ended.');
    replace_test(replace_http('GET', 'production-report-data.php?lookup=farmers', $jarA2)['status'] === 401, 'Old session cannot reach admin data endpoints.');
    replace_test(replace_login_fails($people['a']['email'], $people['a']['password']), 'The old admin cannot sign in again.');
    $jarB = replace_login($people['b']['email'], $people['b']['password']);
    $jars[] = $jarB;
    $page = replace_http('GET', 'settings.php', $jarB);
    replace_test(str_contains($page['body'], htmlspecialchars($people['b']['name'])), 'The new admin sees their own account.');
    echo "PASS: re-authenticated transfer: one active admin, old row kept and disabled, old sessions ended, old login refused, new admin signs in with own password\n";

    // Notifications to both people through the existing sender (simulated here), and the audit trail.
    $events = $connection->query('SELECT event_type, detail FROM admin_account_events WHERE id > ' . $firstEvent . ' ORDER BY id')->fetchAll();
    $types = array_column($events, 'event_type');
    foreach (['invitation_created', 'invitation_resent', 'setup_completed', 'transfer_reauth_failed', 'transfer_completed'] as $type) replace_test(in_array($type, $types, true), "Audit event {$type}.");
    replace_test(count(array_filter($events, static fn($e) => $e['event_type'] === 'notification_sent')) === 2, 'Both people were notified.');
    $allText = json_encode($events);
    foreach ([$tokenB1, $tokenB2, $people['a']['password'], $people['b']['password']] as $secret) replace_test(!str_contains($allText, $secret), 'No token or password in the audit trail.');
    $newMail = json_decode((string) file_get_contents(rtrim((string) getenv('AGRIREACH_TEST_MAIL_CAPTURE_DIR'), '/\\') . DIRECTORY_SEPARATOR . 'admin-account-' . hash('sha256', $people['b']['email']) . '.json'), true);
    replace_test(str_contains($newMail['subject'], 'You are now the AgriReach administrator') && !str_contains($newMail['text'], $people['b']['password']), 'Transfer notice to the new admin, without a password.');
    echo "PASS: transfer notices to both people via the system sender; audit trail without tokens or passwords\n";

    // Concurrency: two processes (separate database connections) transfer at the same moment -> one active admin.
    // (The PHP test web server handles one request at a time, so true concurrency is exercised with CLI workers.)
    replace_settings($jarB, ['action' => 'invite_replacement', 'replacement_name' => $people['c']['name'], 'replacement_email' => $people['c']['email']]);
    $tokenC = replace_captured_token($people['c']['email']);
    replace_test(replace_invite_api('complete', $tokenC, ['password' => $people['c']['password'], 'passwordConfirmation' => $people['c']['password']])['status'] === 200, 'C set a password.');
    $bId = (int) $connection->query("SELECT id FROM admins WHERE status = 'active'")->fetchColumn();
    $worker = 'require ' . var_export(dirname(__DIR__, 2) . '/backend/lib/admin_replacement.php', true) . '; usleep((int) getenv("T_START_US") - (int) (microtime(true) * 1000000) > 0 ? (int) getenv("T_START_US") - (int) (microtime(true) * 1000000) : 0);'
        . ' try { admin_replacement_transfer(db(), (int) getenv("T_ADMIN"), (string) getenv("T_PASS"), true); echo "ok"; } catch (Throwable $e) { echo "fail:" . $e->getMessage(); }';
    $start = (int) (microtime(true) * 1000000) + 1500000;
    $processes = [];
    for ($i = 0; $i < 2; $i++) {
        $environment = getenv() + ['T_ADMIN' => (string) $bId, 'T_PASS' => $people['b']['password'], 'T_START_US' => (string) $start];
        $processes[] = proc_open([PHP_BINARY, '-r', $worker], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment) ?: throw new RuntimeException('Worker did not start.');
        $outputs[$i] = $pipes;
    }
    $results = [];
    foreach ($processes as $i => $process) { $results[] = trim(stream_get_contents($outputs[$i][1]) . stream_get_contents($outputs[$i][2])); fclose($outputs[$i][1]); fclose($outputs[$i][2]); proc_close($process); }
    replace_test(count(array_filter($results, static fn($r) => $r === 'ok')) === 1 && count(array_filter($results, static fn($r) => str_starts_with($r, 'fail:'))) === 1, 'Exactly one of two concurrent transfers succeeds: ' . json_encode($results));
    $active = $connection->query("SELECT username FROM admins WHERE status = 'active'")->fetchAll(PDO::FETCH_COLUMN);
    replace_test($active === [$people['c']['email']], 'Still exactly one active admin after concurrent transfers.');
    $createdAdminUsernames[] = $people['c']['email'];
    $stale = replace_http('GET', 'settings.php', $jarB);
    replace_test($stale['status'] === 302 && str_contains($stale['location'], 'ended=1'), 'The replaced admin\'s session ends.');
    echo "PASS: concurrent transfers (two processes): one succeeds, the other is refused, one active admin\n";

    // Cancel (pending and ready) and expiry: links stop working, the current admin is unaffected.
    $jarC = replace_login($people['c']['email'], $people['c']['password']);
    $jars[] = $jarC;
    replace_settings($jarC, ['action' => 'invite_replacement', 'replacement_name' => $people['d']['name'], 'replacement_email' => $people['d']['email']]);
    $tokenD = replace_captured_token($people['d']['email']);
    $result = replace_settings($jarC, ['action' => 'cancel_invitation']);
    replace_test(str_contains($result['body'], 'was cancelled') && str_contains($result['body'], 'Create Replacement Admin Account'), 'Cancelled.');
    $cancelled = replace_invite_api('check', $tokenD);
    replace_test($cancelled['status'] === 410 && $cancelled['json']['state'] === 'cancelled', 'A cancelled link says so.');
    replace_settings($jarC, ['action' => 'invite_replacement', 'replacement_name' => $people['e']['name'], 'replacement_email' => $people['e']['email']]);
    $tokenE = replace_captured_token($people['e']['email']);
    replace_test(replace_invite_api('complete', $tokenE, ['password' => $people['e']['password'], 'passwordConfirmation' => $people['e']['password']])['status'] === 200, 'E set a password.');
    replace_settings($jarC, ['action' => 'cancel_invitation']);
    $result = replace_settings($jarC, ['action' => 'transfer_admin', 'current_password' => $people['c']['password'], 'confirm_transfer' => '1']);
    replace_test(!isset($result['location']) || !str_contains((string) $result['location'], 'transferred'), 'No transfer after a cancelled ready setup.');
    replace_test(replace_login_fails($people['e']['email'], $people['e']['password']), 'A cancelled replacement cannot sign in.');
    replace_settings($jarC, ['action' => 'invite_replacement', 'replacement_name' => $people['f']['name'], 'replacement_email' => $people['f']['email']]);
    $tokenF = replace_captured_token($people['f']['email']);
    $connection->prepare("UPDATE admin_replacement_invitations SET token_expires_at = " . db_sql_utc_now($connection) . " - INTERVAL '60' SECOND WHERE token_hash = ?")->execute([hash('sha256', $tokenF)]);
    $expired = replace_invite_api('check', $tokenF);
    replace_test($expired['status'] === 410 && $expired['json']['state'] === 'expired', 'An expired link says so.');
    replace_test(str_contains(replace_http('GET', 'settings.php', $jarC)['body'], 'Create Replacement Admin Account'), 'An expired process no longer blocks a new invitation.');
    replace_test(admin_active_account($connection, (int) $connection->query("SELECT id FROM admins WHERE status = 'active'")->fetchColumn())['username'] === $people['c']['email'], 'C is still the active admin throughout.');
    echo "PASS: cancel (pending and ready) and expiry invalidate links without affecting the current admin\n";

    // Manage Users cannot change administrator access; the recovery script refuses unsafe actions.
    $usersPage = replace_http('GET', 'manage-users.php?role=Administrator', $jarC);
    preg_match('/name="csrf_token" value="([^"]+)"/', $usersPage['body'], $m);
    replace_http('POST', 'manage-users.php', $jarC, ['csrf_token' => html_entity_decode($m[1]), 'action' => 'toggle', 'user_type' => 'admin', 'user_id' => (string) $takeover['id']]);
    $after = $connection->prepare('SELECT status, account_status FROM admins WHERE id = ?');
    $after->execute([$takeover['id']]);
    replace_test($after->fetch() == ['status' => 'inactive', 'account_status' => 'Inactive'], 'Manage Users cannot reactivate a former admin.');
    $php = PHP_BINARY;
    $recovery = dirname(__DIR__, 2) . '/scripts/admin-recovery.php';
    $run = static function (array $args, string $input = '') use ($php, $recovery): array {
        $process = proc_open([$php, $recovery, ...$args], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fwrite($pipes[0], $input); fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        return [proc_close($process), $out];
    };
    [$code, $out] = $run(['reset-password', '--username=' . $people['a']['email']], "NewPassword1\nNewPassword1\n");
    replace_test($code === 1 && str_contains($out, 'Former administrators do not regain access'), 'Recovery refuses to reset a former admin.');
    [$code, $out] = $run(['activate', '--username=' . $people['a']['email']]);
    replace_test($code === 1 && str_contains($out, 'already active'), 'Recovery refuses to activate a second admin.');
    [$code, $out] = $run(['status']);
    replace_test($code === 0 && str_contains($out, $people['c']['email']), 'Recovery status works.');
    echo "PASS: Manage Users cannot change admin access; recovery script refuses unsafe actions\n";

    // Rate limit on token checks from one client address.
    $limited = false;
    for ($i = 0; $i < ADMIN_TOKEN_FAILURE_LIMIT + 2 && !$limited; $i++) $limited = replace_invite_api('check', str_repeat('x', 43))['status'] === 429;
    replace_test($limited, 'Repeated invalid token checks are rate-limited.');
    echo "PASS: token verification rate limit\n";
} finally {
    foreach ($jars as $jar) @unlink($jar);
    // Remove this run's invitations/events and admins (history rows reference admins with ON DELETE SET NULL).
    $connection->prepare('DELETE FROM admin_account_events WHERE id > ?')->execute([$firstEvent]);
    $connection->prepare('DELETE FROM admin_replacement_invitations WHERE id > ?')->execute([$firstInvitation]);
    if ($takeover !== null) {
        $connection->exec("UPDATE admins SET status = 'inactive' WHERE status = 'active' AND username LIKE 'replace-%{$stamp}@example.test'");
        foreach ($createdAdminUsernames as $username) $connection->prepare('DELETE FROM admins WHERE username = ?')->execute([$username]);
        test_admin_restore($connection, $takeover);
    }
    if ($formerId !== null) $connection->prepare("DELETE FROM admins WHERE id = ? AND username = ?")->execute([$formerId, 'former-' . $stamp]);
    if ($farmerId !== null) $connection->prepare('DELETE FROM farmers WHERE id = ? AND email = ?')->execute([$farmerId, "farmer-{$stamp}@example.test"]);
    foreach (glob(rtrim((string) getenv('AGRIREACH_TEST_MAIL_CAPTURE_DIR'), '/\\') . DIRECTORY_SEPARATOR . 'admin-account-*.json') ?: [] as $file) @unlink($file);
    foreach ($before as $table => $fingerprint) replace_test(replace_fingerprint($connection, $table, $watched[$table]) === $fingerprint, "Pre-existing {$table} rows changed.");
    echo "Removed only this run's controlled admins, farmer, invitations, events and captured mail; the original admin is active again.\n";
}
