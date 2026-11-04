<?php
declare(strict_types=1);

/**
 * What the running Apache serves from the AgriReach folder (read-only GET/HEAD requests; changes nothing).
 * Only the public routes may answer; data, uploads, tooling, database files and the old project archive must not.
 * Usage: php tests/security/apache_public_paths_probe.php [base URL, default http://localhost/CAPSTONE%20AGRICULTURE]
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$base = rtrim($argv[1] ?? 'http://localhost/CAPSTONE%20AGRICULTURE', '/');
$host = (string) parse_url($base, PHP_URL_SCHEME) . '://' . (string) parse_url($base, PHP_URL_HOST) . (parse_url($base, PHP_URL_PORT) ? ':' . parse_url($base, PHP_URL_PORT) : '');
function probe(string $url): array
{
    $handle = curl_init($url);
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_NOBODY => false, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => false, CURLOPT_RANGE => '0-2047']);
    $body = (string) curl_exec($handle);
    $result = [(int) curl_getinfo($handle, CURLINFO_HTTP_CODE), (string) curl_getinfo($handle, CURLINFO_REDIRECT_URL), $body];
    curl_close($handle);
    return $result;
}
$public = ['' => [302], 'index.php' => [302], 'admin/login.php' => [200, 206], 'admin/assets/css/admin.css' => [200, 206], 'admin/admin-invitation.php' => [200, 206], 'api/auth/me.php' => [401], 'config/logout.php' => [302]];
$private = ['storage/', 'storage/report-images/', 'uploads/', 'uploads/ids/', 'tmp/', 'tmp/pdfs/', 'tools/', 'tools/cloudflared/cloudflared.exe', 'config/', '.expo/', '.expo/settings.json', 'var/', 'var/cloudflare-tunnel/',
    'database/', 'database/agrireach.sql', 'database/pgsql/', 'scripts/', 'scripts/admin-recovery.php', 'tests/', 'tests/security/access_control_integration.php', 'vendor/autoload.php', 'docs/',
    'backend/', 'backend/config/database.php', 'backend/lib/admin_replacement.php', 'frontend/', 'frontend/admin/login.php', 'frontend/mobile/.env.local', 'frontend/mobile/app.config.js', 'assets/', 'composer.json', 'composer.lock', '.gitignore', '.htaccess'];
$failures = 0; $count = 0;
foreach ($public as $path => $allowed) {
    [$status] = probe($base . '/' . $path);
    $count++;
    if (!in_array($status, $allowed, true)) { $failures++; echo "FAIL public  /{$path} -> {$status} (expected " . implode('/', $allowed) . ")\n"; }
}
foreach ($private as $path) {
    [$status, , $body] = probe($base . '/' . $path);
    $count++;
    // A blocked path answers 403/404 (or a redirect to the admin login) and never lists a directory.
    if (!in_array($status, [403, 404], true) || stripos($body, 'Index of') !== false) { $failures++; echo "FAIL private /{$path} -> {$status}\n"; }
}
[$zip] = probe($host . '/CAPSTONE%20AGRICULTURE.zip');
$count++;
if (!in_array($zip, [403, 404], true)) { $failures++; echo "FAIL old project archive /CAPSTONE AGRICULTURE.zip -> {$zip}\n"; }
if ($failures) { echo "FAIL: {$failures} of {$count} path checks\n"; exit(1); }
echo "PASS: {$count} path checks on {$base} (public routes answer; private data, tooling and the old archive are blocked)\n";
