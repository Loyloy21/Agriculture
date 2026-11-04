<?php
declare(strict_types=1);

/**
 * Admin Settings status checks (disposable database only; never Supabase, never sends email):
 *   - Database Status comes from a real health query and names the connection;
 *   - a missing admin-replacement update (0008) does not hide the system sender or mark the database unavailable;
 *   - the invitation checklist reflects the real prerequisites (0008, APP_URL and who can open it, a sender that
 *     can deliver), the button is off while one is blocking, and the server refuses an invite with that reason
 *     without creating anything;
 *   - a saved Gmail sender is the same sender approval/rejection emails use.
 * Each page render runs in a child process (the page can be loaded once per process) with its own environment.
 */

if (in_array($argv[1] ?? '', ['--render', '--post'], true)) {
    require_once dirname(__DIR__, 2) . '/backend/config/database.php';
    $options = json_decode((string) file_get_contents($argv[2]), true);
    $connection = db();
    $adminId = (int) $connection->query("SELECT id FROM admins WHERE status = 'active' ORDER BY id LIMIT 1")->fetchColumn();
    // Hide the 0008 tables for this child only: renamed inside a transaction that is always rolled back (PostgreSQL DDL is transactional).
    if (!empty($options['hide0008'])) {
        $connection->beginTransaction();
        $connection->exec('ALTER TABLE admin_replacement_invitations RENAME TO hidden_admin_replacement_invitations');
        $connection->exec('ALTER TABLE admin_account_events RENAME TO hidden_admin_account_events');
    }
    session_save_path(sys_get_temp_dir());
    session_id('settings-status-' . bin2hex(random_bytes(4)));
    session_start();
    $_SESSION['admin_id'] = $adminId;
    $_SESSION['admin_role'] = 'admin';
    $_SESSION['admin_name'] = 'Controlled Settings Check';
    $_SESSION['csrf_token'] = 'controlled-csrf-token';
    $_SERVER['REQUEST_METHOD'] = $argv[1] === '--post' ? 'POST' : 'GET';
    $_POST = ($options['post'] ?? []) + ['csrf_token' => 'controlled-csrf-token'];
    register_shutdown_function(static function () use ($connection, $argv): void {
        $html = (string) ob_get_clean();
        if ($connection->inTransaction()) $connection->rollBack();
        // MySQL/MariaDB commit a RENAME immediately, so the names are put back explicitly there.
        if (!db_is_pgsql($connection) && $connection->query("SHOW TABLES LIKE 'hidden_admin_replacement_invitations'")->fetchColumn()) {
            $connection->exec('RENAME TABLE hidden_admin_replacement_invitations TO admin_replacement_invitations, hidden_admin_account_events TO admin_account_events');
        }
        $text = static fn(string $fragment): string => trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($fragment), ENT_QUOTES)));
        $result = ['error' => $_SESSION['settings_error'] ?? null, 'flash' => $_SESSION['settings_flash'] ?? null];
        if ($argv[1] === '--render') {
            preg_match('#<dt>Database Status</dt><dd>(.*?)</dd>#s', $html, $m); $result['database'] = $text($m[1] ?? '');
            preg_match('#<dt>System email sender</dt><dd>(.*?)</dd>#s', $html, $m); $result['sender'] = $text($m[1] ?? '');
            preg_match_all('#<li class="(ok|blocked|note)">(.*?)</li>#s', $html, $m, PREG_SET_ORDER);
            $result['checks'] = array_map(static fn(array $item): array => ['state' => $item[1], 'text' => $text($item[2])], $m);
            preg_match('#data-bs-target="\#inviteReplacementModal"([^>]*)>#', $html, $m); $result['button'] = str_contains($m[1] ?? '', 'disabled') ? 'disabled' : 'enabled';
            $result['alert'] = preg_match('#<div class="alert alert-danger py-2 small mb-2" role="alert">(.*?)</div>#s', $html, $m) ? $text($m[1]) : null;
        }
        echo "\n@@RESULT@@" . json_encode($result);
        session_destroy();
    });
    ob_start();
    require dirname(__DIR__, 2) . '/frontend/admin/settings.php';
    exit;
}

require_once dirname(__DIR__) . '/support/admin_test_account.php';
require_once dirname(__DIR__, 2) . '/backend/lib/admin_replacement.php';

$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
    echo "  ok  {$message}\n";
}

/** Runs one page load in a child with these environment overrides (null removes a variable). */
function settings_child(string $mode, array $env = [], array $options = []): array
{
    $file = tempnam(sys_get_temp_dir(), 'set');
    file_put_contents($file, json_encode($options));
    $environment = getenv();
    foreach ($env as $name => $value) { if ($value === null) unset($environment[$name]); else $environment[$name] = $value; }
    $process = proc_open([PHP_BINARY, __FILE__, $mode, $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    proc_close($process);
    unlink($file);
    $position = strrpos($output, '@@RESULT@@');
    if ($position === false) throw new RuntimeException('Settings child failed: ' . substr($output, 0, 500));
    return json_decode(substr($output, $position + 10), true);
}
$check = static fn(array $result, string $label): ?array => array_values(array_filter($result['checks'], static fn(array $item): bool => str_starts_with($item['text'], $label)))[0] ?? null;

$connection = db();
test_admin_refuse_live();
$senderBackup = null;
$exitCode = 0;
try {
    // ---------- APP_URL scope: who can open the link ----------
    foreach ([
        'http://localhost/CAPSTONE%20AGRICULTURE' => 'this_computer', 'http://127.0.0.1:8197/x' => 'this_computer', 'http://[::1]/x' => 'this_computer',
        'http://192.168.1.55/CAPSTONE%20AGRICULTURE' => 'local_network', 'http://10.0.0.8/x' => 'local_network', 'http://agrireach-pc.local/x' => 'local_network', 'http://agrireach-pc/x' => 'local_network',
        'https://reflect-sunrise.trycloudflare.com/CAPSTONE%20AGRICULTURE' => 'api_tunnel', 'https://agrireach.example.org' => 'internet', 'http://203.0.113.9/x' => 'internet',
    ] as $url => $scope) check(admin_replacement_app_url_scope($url) === $scope, "APP_URL {$url} opens on: {$scope}");

    // ---------- 0008 applied, test APP_URL on this computer, simulated email ----------
    $result = settings_child('--render');
    $driver = db_connection_settings()['driver'] === 'pgsql' ? 'PostgreSQL' : 'MySQL';
    check(str_starts_with($result['database'], 'Connected') && str_contains($result['database'], $driver), "Database Status is Connected and names the {$driver} connection");
    check($check($result, 'Database update')['state'] === 'ok' && $check($result, 'Invitation link address')['state'] === 'ok' && str_contains($check($result, 'Invitation link address')['text'], 'only on this computer'), 'checklist: update applied; APP_URL usable but labelled "only on this computer"');
    check($check($result, 'Email sender')['state'] === 'note' && str_contains($check($result, 'Email sender')['text'], 'simulates email') && $result['button'] === 'enabled', 'simulated email on a development server is a note, not a block; invitations enabled');

    // ---------- Missing 0008: database still Connected, sender still shown, invitations off ----------
    $result = settings_child('--render', [], ['hide0008' => true]);
    check(str_starts_with($result['database'], 'Connected'), 'without the 0008 tables the database is still reported Connected (no false "Unavailable")');
    check($result['sender'] !== '' && !str_starts_with($result['sender'], 'Could not be checked'), 'without the 0008 tables the sender row is still evaluated');
    check($check($result, 'Database update')['state'] === 'blocked' && str_contains($check($result, 'Database update')['text'], '20261024_0008_admin_replacement') && $result['button'] === 'disabled', 'missing 0008 is a named blocking item and the button is off');
    $result = settings_child('--post', [], ['hide0008' => true, 'post' => ['action' => 'invite_replacement', 'replacement_name' => 'Controlled Person', 'replacement_email' => 'controlled-settings@example.test']]);
    check(is_string($result['error']) && str_contains($result['error'], '0008') && str_contains($result['error'], 'No invitation was created or sent') && $result['flash'] === null, 'server refuses an invite without 0008, says why, and claims nothing was sent');

    // ---------- APP_URL missing or pointing at the API tunnel ----------
    $before = (int) $connection->query('SELECT COUNT(*) FROM admin_replacement_invitations')->fetchColumn();
    $result = settings_child('--render', ['APP_URL' => '', 'AGRIREACH_EMAIL_CONFIG_FILE' => 'C:/nonexistent/email.local.php']);
    check($check($result, 'Invitation link address')['state'] === 'blocked' && str_contains($check($result, 'Invitation link address')['text'], 'not set') && $result['button'] === 'disabled', 'APP_URL missing: blocked, button off');
    $result = settings_child('--post', ['APP_URL' => '', 'AGRIREACH_EMAIL_CONFIG_FILE' => 'C:/nonexistent/email.local.php'], ['post' => ['action' => 'invite_replacement', 'replacement_name' => 'Controlled Person', 'replacement_email' => 'controlled-settings@example.test']]);
    check(is_string($result['error']) && str_contains($result['error'], 'APP_URL') && str_contains($result['error'], 'No invitation was created or sent'), 'APP_URL missing: invite refused with the reason');
    $result = settings_child('--render', ['APP_URL' => 'https://reflect-sunrise.trycloudflare.com/CAPSTONE%20AGRICULTURE']);
    check($check($result, 'Invitation link address')['state'] === 'blocked' && str_contains($check($result, 'Invitation link address')['text'], 'tunnel'), 'APP_URL on the API-only tunnel: blocked (its gateway never serves the invitation page)');
    $result = settings_child('--render', ['APP_URL' => 'http://192.168.1.55/CAPSTONE%20AGRICULTURE']);
    check($check($result, 'Invitation link address')['state'] === 'ok' && str_contains($check($result, 'Invitation link address')['text'], 'same network'), 'APP_URL on a LAN address: usable, labelled "same network"');
    // A production server sends invitations only to a public HTTPS address.
    foreach (['http://localhost/CAPSTONE%20AGRICULTURE' => 'this computer', 'https://192.168.1.55/CAPSTONE%20AGRICULTURE' => 'private network', 'http://agrireach.example.org' => 'must use HTTPS'] as $url => $reason) {
        $result = settings_child('--render', ['APP_ENV' => 'production', 'APP_URL' => $url]);
        check($check($result, 'Invitation link address')['state'] === 'blocked' && str_contains($check($result, 'Invitation link address')['text'], $reason) && $result['button'] === 'disabled', "production server, APP_URL {$url}: blocked ({$reason}), button off");
    }
    $result = settings_child('--post', ['APP_ENV' => 'production', 'APP_URL' => 'http://localhost/CAPSTONE%20AGRICULTURE'], ['post' => ['action' => 'invite_replacement', 'replacement_name' => 'Controlled Person', 'replacement_email' => 'controlled-settings@example.test']]);
    check(is_string($result['error']) && str_contains($result['error'], 'this computer') && str_contains($result['error'], 'No invitation was created or sent'), 'production server refuses to send an invitation to a localhost link');
    $result = settings_child('--render', ['APP_ENV' => 'production', 'APP_URL' => 'https://agrireach.example.gov.ph/agri']);
    check($check($result, 'Invitation link address')['state'] === 'ok' && str_contains($check($result, 'Invitation link address')['text'], 'https://agrireach.example.gov.ph/agri'), 'production server with a public HTTPS address: link address accepted');
    check((int) $connection->query('SELECT COUNT(*) FROM admin_replacement_invitations')->fetchColumn() === $before, 'refused invitations created no rows');

    // ---------- Production must deliver: simulated email blocks ----------
    $result = settings_child('--render', ['APP_ENV' => 'production', 'MAIL_TRANSPORT' => 'log']);
    check($check($result, 'Email sender')['state'] === 'blocked' && $result['button'] === 'disabled', 'production server with simulated email: sender blocked, button off');

    // ---------- A saved Gmail sender (disposable test key; nothing is sent) ----------
    $senderBackup = $connection->query('SELECT * FROM registration_email_settings WHERE id = 1')->fetch() ?: false;
    $testKey = base64_encode(random_bytes(32));
    putenv('AGRIREACH_EMAIL_ENCRYPTION_KEY=' . $testKey);
    save_registration_email_sender($connection, 'controlled.sender@gmail.com', 'abcdabcdabcdabcd', (int) $connection->query("SELECT id FROM admins WHERE status = 'active' ORDER BY id LIMIT 1")->fetchColumn());
    $approval = registration_email_configuration($connection);
    check($approval['from_address'] === 'controlled.sender@gmail.com' && $approval['transport'] === 'smtp' && $approval['host'] === 'smtp.gmail.com', 'approval/rejection email configuration uses the saved Gmail sender');
    $result = settings_child('--render', ['AGRIREACH_EMAIL_ENCRYPTION_KEY' => $testKey, 'MAIL_TEST_RECIPIENT' => 'controlled-inbox@example.test']);
    check(str_starts_with($result['sender'], 'controlled.sender@gmail.com') && $check($result, 'Email sender')['state'] === 'ok' && str_contains($check($result, 'Email sender')['text'], 'controlled.sender@gmail.com, the same sender as approval and rejection emails') && str_contains($check($result, 'Email sender')['text'], 'test recipient'), 'Settings shows the saved sender, says invitations use the same sender, and that a development server routes to the test recipient');
    $result = settings_child('--render', ['AGRIREACH_EMAIL_ENCRYPTION_KEY' => $testKey, 'MAIL_TEST_RECIPIENT' => '']);
    check($check($result, 'Email sender')['state'] === 'blocked' && str_contains($check($result, 'Email sender')['text'], 'MAIL_TEST_RECIPIENT'), 'development server without a test recipient: blocked before anything could be sent');
    $result = settings_child('--render', ['AGRIREACH_EMAIL_ENCRYPTION_KEY' => null, 'AGRIREACH_EMAIL_ENCRYPTION_KEY_FILE' => 'C:/nonexistent/agrireach-email.key']);
    check(str_contains($result['sender'], 'controlled.sender@gmail.com') && str_contains($result['sender'], 'cannot be read') && $check($result, 'Email sender')['state'] === 'blocked' && str_contains($check($result, 'Email sender')['text'], 'cannot be decrypted'), 'saved sender whose key is missing: still shown (not "Not configured") and reported as unreadable');

    echo "PASS: admin Settings statuses ({$checks} checks)\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . PHP_EOL);
    $exitCode = 1;
} finally {
    // Put the disposable database's sender row back exactly as it was.
    if ($senderBackup !== null) {
        $connection->exec('DELETE FROM registration_email_settings WHERE id = 1');
        if ($senderBackup !== false) {
            $columns = array_keys($senderBackup);
            $connection->prepare('INSERT INTO registration_email_settings (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')')->execute(array_values($senderBackup));
        }
    }
}
exit($exitCode);
