<?php
declare(strict_types=1);

/**
 * Regression guard: every PHP endpoint the mobile app calls must be in the tunnel gateway's allow-list.
 * A missing entry makes the gateway answer 404 {"message":"Not found."}, which the app used to show as a
 * generic "unavailable" (this is exactly how Weather-based advice broke on a physical phone).
 * Also checks that every allow-listed endpoint file exists. Read-only, no network.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__, 2);

$gateway = (string) file_get_contents($root . '/scripts/agrireach-api-gateway.mjs');
preg_match('/publicEndpoints = new Set\(\[(.*?)\]\)/s', $gateway, $block);
preg_match_all("/'([a-z]+\/[a-z-]+\.php)'/", $block[1] ?? '', $listed);
$allowed = array_unique($listed[1]);

$called = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/frontend/mobile/src'));
foreach ($iterator as $file) {
    if (!in_array($file->getExtension(), ['ts', 'tsx'], true)) continue;
    preg_match_all('#[\'`"]/([a-z]+/[a-z-]+\.php)#', (string) file_get_contents($file->getPathname()), $matches);
    foreach ($matches[1] as $route) $called[$route] = basename($file->getPathname());
}

$failures = [];
foreach ($called as $route => $source) {
    if (!in_array($route, $allowed, true)) $failures[] = "Mobile calls /{$route} ({$source}) but the gateway allow-list does not include it.";
}
foreach ($allowed as $route) {
    if (!is_file($root . '/backend/api/' . $route)) $failures[] = "Gateway allow-lists {$route} but backend/api/{$route} does not exist.";
}
if (!in_array('weather/advice.php', $allowed, true)) $failures[] = 'weather/advice.php must be allow-listed.';

if ($failures) {
    foreach ($failures as $failure) fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
    exit(1);
}
echo 'PASS: all ' . count($called) . ' mobile API routes are allow-listed by the gateway (' . implode(', ', array_keys($called)) . ")\n";
echo 'PASS: all ' . count($allowed) . " allow-listed endpoints exist\n";
