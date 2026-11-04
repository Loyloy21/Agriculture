<?php
declare(strict_types=1);

session_save_path(__DIR__);
session_id('weather-admin-smoke');
session_start();
$_SESSION['admin_id'] = 1;
$_SESSION['admin_role'] = 'admin';
$_SESSION['admin_name'] = 'Controlled Test Administrator';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = [];

ob_start();
require __DIR__ . '/../../frontend/admin/weather-advisory.php';
$html = (string) ob_get_clean();
foreach (['Entire Cabadbaran City', 'Selected barangays', 'Eligible', 'Registration location review', 'name="key_advice"', 'Key advice (farmer preview)'] as $expected) {
    if (!str_contains($html, $expected)) {
        fwrite(STDERR, "FAIL: missing admin weather page content: {$expected}\n");
        exit(1);
    }
}
echo "PASS: admin weather advisory page renders structured coverage and recipient controls\n";
session_destroy();
