<?php
declare(strict_types=1);

/**
 * TEMPORARY cross-network test overlay (app.temporary-test.php beside the private mail settings): it may replace only
 * APP_URL, only with an HTTPS *.trycloudflare.com address, only until EXPIRES_AT; without it the normal production
 * rules apply (localhost refused). Uses a throw-away settings folder; never the real secrets folder.
 */
if (($argv[1] ?? '') === '--case') {
    putenv('AGRIREACH_EMAIL_CONFIG_FILE=' . $argv[2] . DIRECTORY_SEPARATOR . 'email.local.php');
    putenv('APP_ENV'); putenv('APP_URL'); putenv('TRUSTED_PROXY_IPS');
    if (($argv[3] ?? '') !== '') putenv('APP_URL=' . $argv[3]);
    require_once dirname(__DIR__, 2) . '/backend/config/app.php';
    echo json_encode(['url' => agrireach_public_base_url(), 'problem' => agrireach_public_url_problem(), 'temporary' => agrireach_public_url_is_temporary(),
        'link' => agrireach_public_url_problem() === null ? agrireach_public_url('admin/admin-invitation.php') : null, 'mail_env' => email_setting('APP_ENV'),
        'login_link' => agrireach_public_link_available('admin/login.php'), 'dashboard_link' => agrireach_public_link_available('admin/dashboard.php'),
        'api_route' => agrireach_public_route_available('api/auth/me.php'), 'invitation_link' => agrireach_public_link_available('admin/admin-invitation.php')]);
    exit;
}

$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'agrireach-overlay-' . bin2hex(random_bytes(4));
mkdir($dir);
file_put_contents($dir . '/email.local.php', "<?php return ['APP_ENV' => 'production', 'APP_URL' => 'http://localhost/CAPSTONE%20AGRICULTURE'];\n");
$run = static function (?array $overlay, string $envUrl = '') use ($dir): array {
    $file = $dir . '/app.temporary-test.php';
    if ($overlay === null) @unlink($file); else file_put_contents($file, '<?php return ' . var_export($overlay, true) . ";\n");
    return json_decode((string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --case ' . escapeshellarg($dir) . ' ' . escapeshellarg($envUrl)), true);
};
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void { if (!$ok) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } $checks++; };
$tunnel = 'https://lucky-test-words-here.trycloudflare.com/CAPSTONE%20AGRICULTURE';
try {
    $r = $run(null);
    $check($r['url'] === 'http://localhost/CAPSTONE%20AGRICULTURE' && str_contains((string) $r['problem'], 'this computer') && !$r['temporary'], 'without the overlay the normal configuration applies (localhost refused on production)');
    $r = $run(['APP_URL' => $tunnel, 'EXPIRES_AT' => time() + 3600]);
    $check($r['url'] === $tunnel && $r['problem'] === null && $r['temporary'] === true && $r['link'] === $tunnel . '/admin/admin-invitation.php', 'a valid overlay makes invitation links use the temporary HTTPS tunnel');
    $check($r['login_link'] === false && $r['dashboard_link'] === false && $r['api_route'] === true && $r['invitation_link'] === true, 'on the temporary tunnel only the API and the invitation page may be linked (no admin login or dashboard link)');
    $check($r['mail_env'] === 'production', 'the overlay changes nothing but APP_URL (APP_ENV stays production)');
    $r = $run(['APP_URL' => $tunnel, 'EXPIRES_AT' => time() - 1]);
    $check($r['url'] === 'http://localhost/CAPSTONE%20AGRICULTURE' && !$r['temporary'], 'an expired overlay is ignored');
    $r = $run(['APP_URL' => 'https://evil.example.com/x', 'EXPIRES_AT' => time() + 3600]);
    $check($r['url'] === 'http://localhost/CAPSTONE%20AGRICULTURE' && !$r['temporary'], 'an overlay with a non-tunnel address is ignored');
    $r = $run(['APP_URL' => 'http://lucky-test-words-here.trycloudflare.com/x', 'EXPIRES_AT' => time() + 3600]);
    $check(!$r['temporary'] && str_contains((string) $r['problem'], 'this computer'), 'an overlay with plain HTTP is ignored');
    $r = $run(['APP_URL' => $tunnel, 'EXPIRES_AT' => time() + 3600], 'https://other-host.trycloudflare.com/x');
    $check(!$r['temporary'] && str_contains((string) $r['problem'], 'tunnel'), 'an APP_URL environment variable still wins, and a tunnel not written by the launcher is refused');
    $r = $run(null, $tunnel);
    $check(!$r['temporary'] && $r['problem'] !== null, 'the same tunnel address without the launcher overlay is refused');
} finally {
    @unlink($dir . '/app.temporary-test.php'); @unlink($dir . '/email.local.php'); @rmdir($dir);
}
echo "ALL {$checks} CHECKS PASSED\n";
