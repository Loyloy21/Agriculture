<?php
declare(strict_types=1);

/**
 * Trusted public address (backend/config/app.php): validation, link building, HTTPS detection and the HTTPS
 * upgrade decision. Pure unit checks: environment variables only, no database, no network, no private config file.
 */
putenv('AGRIREACH_EMAIL_CONFIG_FILE=C:/nonexistent/agrireach-email.local.php');
putenv('TRUSTED_PROXY_IPS');
require_once dirname(__DIR__, 2) . '/backend/config/app.php';

$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
    $checks++;
}
function with_env(string $appEnv, ?string $appUrl): void
{
    putenv('APP_ENV=' . $appEnv);
    $appUrl === null ? putenv('APP_URL') : putenv('APP_URL=' . $appUrl);
}

// ---------- Production: only a public HTTPS address may be used in links ----------
$refused = [
    '' => 'not set', 'ftp://agrireach.example.org' => 'not a valid', 'https://agrireach.example.org/app?x=1' => 'not a valid',
    'https://user:pass@agrireach.example.org' => 'not a valid', 'http://agrireach.example.org' => 'must use HTTPS', 'https://localhost/CAPSTONE%20AGRICULTURE' => 'this computer',
    'https://127.0.0.1' => 'this computer', 'https://192.168.1.55/CAPSTONE%20AGRICULTURE' => 'private network', 'https://10.1.2.3' => 'private network',
    'https://agrireach-pc.local' => 'private network', 'https://agrireach-pc' => 'private network', 'https://reflect-sunrise.trycloudflare.com/CAPSTONE%20AGRICULTURE' => 'temporary mobile API tunnel',
];
foreach ($refused as $url => $reason) {
    with_env('production', $url === '' ? null : (string) $url);  // '': APP_URL not set at all
    $problem = agrireach_public_url_problem();
    check($problem !== null && str_contains($problem, $reason), "production refuses APP_URL '{$url}' ({$reason}): " . var_export($problem, true));
    $threw = false;
    try { agrireach_public_url('admin/admin-invitation.php'); } catch (RuntimeException) { $threw = true; }
    check($threw, "no link is built from '{$url}' on a production server (no localhost fallback)");
}
with_env('production', 'https://agrireach.example.gov.ph/agri/');
check(agrireach_public_url_problem() === null, 'production accepts a public HTTPS address with a deployment path');
check(agrireach_public_url('admin/admin-invitation.php') === 'https://agrireach.example.gov.ph/agri/admin/admin-invitation.php', 'links keep the deployment path and have no double slash');
check(agrireach_public_url('/admin/login.php') === 'https://agrireach.example.gov.ph/agri/admin/login.php', 'a leading slash in the path is handled');

// ---------- Development: local addresses allowed (flow can be tried on this computer) ----------
with_env('development', 'http://localhost/CAPSTONE%20AGRICULTURE');
check(agrireach_public_url_problem() === null && agrireach_public_url('admin/login.php') === 'http://localhost/CAPSTONE%20AGRICULTURE/admin/login.php', 'development allows localhost');
with_env('development', 'https://reflect-sunrise.trycloudflare.com/x');
check(str_contains((string) agrireach_public_url_problem(), 'tunnel'), 'even development refuses the API-only tunnel for admin links');

// ---------- HTTPS detection and the trusted proxy header ----------
$_SERVER = ['HTTPS' => 'on'];
check(agrireach_request_is_https(), 'HTTPS=on is HTTPS');
$_SERVER = ['HTTPS' => 'off'];
check(!agrireach_request_is_https(), 'HTTPS=off is not HTTPS');
$_SERVER = ['REMOTE_ADDR' => '203.0.113.7', 'HTTP_X_FORWARDED_PROTO' => 'https'];
check(!agrireach_request_is_https(), 'X-Forwarded-Proto from an untrusted address is ignored');
putenv('TRUSTED_PROXY_IPS=10.0.0.2, 10.0.0.3');
$_SERVER = ['REMOTE_ADDR' => '10.0.0.3', 'HTTP_X_FORWARDED_PROTO' => 'https'];
check(agrireach_request_is_https(), 'X-Forwarded-Proto from a configured trusted proxy is believed');
$_SERVER = ['REMOTE_ADDR' => '10.0.0.3', 'HTTP_X_FORWARDED_PROTO' => 'http'];
check(!agrireach_request_is_https(), 'a trusted proxy reporting http is not HTTPS');
putenv('TRUSTED_PROXY_IPS');

// ---------- HTTPS upgrade: only the public host over plain HTTP on production ----------
with_env('production', 'https://agrireach.example.gov.ph/agri');
$_SERVER = ['HTTP_HOST' => 'agrireach.example.gov.ph', 'REQUEST_URI' => '/agri/admin/login.php?ended=1'];
check(agrireach_https_upgrade_url() === 'https://agrireach.example.gov.ph/agri/admin/login.php?ended=1', 'public host over HTTP is sent to the same path over HTTPS');
$_SERVER = ['HTTP_HOST' => 'evil.example', 'REQUEST_URI' => '/agri/admin/login.php'];
check(agrireach_https_upgrade_url() === null, 'a foreign Host header never produces a redirect to it');
$_SERVER = ['HTTP_HOST' => 'localhost', 'REQUEST_URI' => '/CAPSTONE%20AGRICULTURE/admin/login.php'];
check(agrireach_https_upgrade_url() === null, 'local access (localhost) is left alone');
$_SERVER = ['HTTPS' => 'on', 'HTTP_HOST' => 'agrireach.example.gov.ph', 'REQUEST_URI' => '/agri/admin/login.php'];
check(agrireach_https_upgrade_url() === null, 'an HTTPS request is not redirected');
with_env('development', 'https://agrireach.example.gov.ph/agri');
$_SERVER = ['HTTP_HOST' => 'agrireach.example.gov.ph', 'REQUEST_URI' => '/agri/admin/login.php'];
check(agrireach_https_upgrade_url() === null, 'development never redirects');

echo "ALL {$checks} CHECKS PASSED\n";
