<?php
declare(strict_types=1);

/**
 * The "you are now the administrator" notice must never link to a route the public address does not serve.
 * Real invite -> setup -> transfer on a DISPOSABLE database (never Supabase), non-sending 'log' mail transport with
 * capture, in two settings: (1) the temporary test tunnel overlay is active (gateway serves only the API and the
 * invitation page) -> no login link, plain sign-in guidance; (2) a permanent HTTPS address -> login button present.
 * Each scenario runs in a child process with its own throw-away settings folder (settings are cached per process).
 */
if (($argv[1] ?? '') === '--scenario') {
    [$mode, $dir] = [$argv[2], $argv[3]];
    putenv('AGRIREACH_EMAIL_CONFIG_FILE=' . $dir . DIRECTORY_SEPARATOR . 'email.local.php');
    putenv('APP_URL'); putenv('APP_ENV'); putenv('MAIL_TRANSPORT'); putenv('MAIL_TEST_RECIPIENT');
    putenv('AGRIREACH_TEST_MAIL_CAPTURE_DIR=' . $dir);
    require_once dirname(__DIR__) . '/support/admin_test_account.php';
    require_once dirname(__DIR__, 2) . '/backend/lib/admin_replacement.php';
    $connection = db();
    test_admin_refuse_live();
    $stamp = bin2hex(random_bytes(4));
    $old = ['email' => "notice-old-{$stamp}@example.test", 'password' => 'NoticeOld' . $stamp . 'A1'];
    $new = ['email' => "notice-new-{$stamp}@example.test", 'password' => 'NoticeNew' . $stamp . 'A1'];
    $takeover = test_admin_takeover($connection, 'Notice Old Admin', $old['email'], password_hash($old['password'], PASSWORD_DEFAULT), $old['email']);
    $result = ['mode' => $mode];
    try {
        $result['temporary'] = agrireach_public_url_is_temporary();
        $result['loginAvailable'] = agrireach_public_link_available('admin/login.php');
        $invite = admin_replacement_invite($connection, $takeover['id'], 'Notice New Admin', $new['email']);
        $result['invited'] = $invite['sent'] ? $invite['status'] : 'failed';
        $captured = static fn(string $email): array => json_decode((string) file_get_contents($dir . DIRECTORY_SEPARATOR . 'admin-account-' . hash('sha256', strtolower($email)) . '.json'), true);
        preg_match('~(https?://\S+/admin/admin-invitation\.php)#token=([A-Za-z0-9_-]{43})~', $captured($new['email'])['text'], $m);
        $result['invitationLinkBase'] = $m[1] ?? null;
        admin_replacement_complete_setup($connection, $m[2], $new['password'], $new['password']);
        $transfer = admin_replacement_transfer($connection, $takeover['id'], $old['password'], true);
        $notice = $captured($new['email']);
        $result['noticeSubject'] = $notice['subject'];
        preg_match_all('~https?://\S+~', $notice['text'], $links);
        $result['noticeLinks'] = $links[0];
        $result['noticeText'] = $notice['text'];
        $result['activeAdmins'] = (int) $connection->query("SELECT COUNT(*) FROM admins WHERE status = 'active'")->fetchColumn();
        $result['newAdminId'] = $transfer['newAdminId'];
    } catch (Throwable $exception) {
        $result['error'] = get_class($exception) . ': ' . $exception->getMessage();
    } finally {
        // Remove this run's admins, invitation and events; the previously active admin becomes active again.
        $ids = $connection->prepare('SELECT id FROM admins WHERE username IN (?, ?)');
        $ids->execute([$old['email'], $new['email']]);
        $adminIds = array_map('intval', $ids->fetchAll(PDO::FETCH_COLUMN));
        foreach ($adminIds as $id) $connection->prepare('DELETE FROM admin_account_events WHERE actor_admin_id = ? OR subject_admin_id = ?')->execute([$id, $id]);
        $connection->prepare('DELETE FROM admin_account_events WHERE invitation_id IN (SELECT id FROM admin_replacement_invitations WHERE email = ?)')->execute([$new['email']]);
        $connection->prepare('DELETE FROM admin_replacement_invitations WHERE email = ?')->execute([$new['email']]);
        foreach ($adminIds as $id) $connection->prepare('DELETE FROM admins WHERE id = ?')->execute([$id]);
        test_admin_restore($connection, ['previous' => $takeover['previous']]);
    }
    echo "\n@@RESULT@@" . json_encode($result);
    exit;
}

$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void { if (!$ok) throw new RuntimeException($message); $checks++; echo "  ok  {$message}\n"; };
$scenario = static function (string $mode, string $settings, ?array $overlay): array {
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'agrireach-notice-' . bin2hex(random_bytes(4));
    mkdir($dir);
    try {
        file_put_contents($dir . '/email.local.php', $settings);
        if ($overlay !== null) file_put_contents($dir . '/app.temporary-test.php', '<?php return ' . var_export($overlay, true) . ";\n");
        $output = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --scenario ' . escapeshellarg($mode) . ' ' . escapeshellarg($dir) . ' 2>&1');
        $position = strrpos($output, '@@RESULT@@');
        if ($position === false) throw new RuntimeException("Scenario {$mode} failed: " . substr($output, 0, 500));
        return json_decode(substr($output, $position + 10), true);
    } finally {
        array_map('unlink', glob($dir . DIRECTORY_SEPARATOR . '*') ?: []);
        @rmdir($dir);
    }
};
$exitCode = 0;
try {
    $tunnel = 'https://lucky-test-words-here.trycloudflare.com/CAPSTONE%20AGRICULTURE';
    $r = $scenario('temporary', "<?php return ['APP_ENV' => 'development', 'MAIL_TRANSPORT' => 'log', 'APP_URL' => 'http://localhost/CAPSTONE%20AGRICULTURE'];\n", ['APP_URL' => $tunnel, 'EXPIRES_AT' => time() + 3600]);
    $check(!isset($r['error']), 'temporary tunnel scenario ran: ' . ($r['error'] ?? 'ok'));
    $check($r['temporary'] === true && $r['loginAvailable'] === false, 'with the temporary test tunnel, admin/login.php is known to be unavailable publicly');
    $check($r['invitationLinkBase'] === $tunnel . '/admin/admin-invitation.php', 'the invitation link still uses the temporary tunnel (the invitation page is served there)');
    $check($r['noticeLinks'] === [], 'the transfer notice contains no link at all (no login link to a 404 route): ' . json_encode($r['noticeLinks']));
    $check(str_contains($r['noticeText'], 'not published on the internet') && str_contains($r['noticeText'], 'temporary test link') && str_contains($r['noticeText'], 'sign in on the computer that runs the AgriReach admin website'), 'the notice says where to sign in instead');
    $check(str_contains($r['noticeText'], 'Sign in with the username notice-new-'), 'the notice still states the username');
    $check($r['activeAdmins'] === 1, 'exactly one active admin after the transfer');

    $r = $scenario('permanent', "<?php return ['APP_ENV' => 'development', 'MAIL_TRANSPORT' => 'log', 'APP_URL' => 'https://agrireach.example.gov.ph/agri'];\n", null);
    $check(!isset($r['error']), 'permanent address scenario ran: ' . ($r['error'] ?? 'ok'));
    $check($r['temporary'] === false && $r['loginAvailable'] === true, 'with a permanent address the login route is available');
    $check($r['noticeLinks'] === ['https://agrireach.example.gov.ph/agri/admin/login.php'], 'the transfer notice links to the real login page: ' . json_encode($r['noticeLinks']));
    $check(!str_contains($r['noticeText'], 'not published on the internet'), 'no "sign in on the computer" wording when the login is public');
    $check($r['activeAdmins'] === 1, 'exactly one active admin after the transfer');
    echo "PASS: transfer notice links only to routes the public address serves ({$checks} checks)\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . PHP_EOL);
    $exitCode = 1;
}
exit($exitCode);
