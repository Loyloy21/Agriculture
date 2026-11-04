<?php
declare(strict_types=1);

/**
 * RSBSA ID registration, end to end through the real PHP API, protected storage and admin routes.
 *
 * DISPOSABLE DATA ONLY. Refuses to run unless the database is not Supabase and AGRIREACH_PRIVATE_ID_DIR
 * points at a throwaway folder (the API server must use the same folder and database). Uses synthetic
 * PNG cards and @example.test farmers, sends no email, and deletes every fixture it creates.
 *   AGRIREACH_TEST_BASE_URL=http://127.0.0.1:8194 php tests/backend/rsbsa_registration_integration.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../support/test_base_url.php';
require_once dirname(__DIR__, 2) . '/backend/config/database.php';
require_once dirname(__DIR__) . '/support/admin_test_account.php';
require_once dirname(__DIR__, 2) . '/backend/lib/private_uploads.php';

$settings = db_connection_settings();
if ($settings['name'] === 'supabase' || str_contains(strtolower($settings['host']), 'supabase')) {
    fwrite(STDERR, "Refusing to run: use a disposable database, not Supabase.\n");
    exit(1);
}
$privateDir = (string) getenv('AGRIREACH_PRIVATE_ID_DIR');
$projectRoot = (string) realpath(dirname(__DIR__, 2));
if ($privateDir === '' || !is_dir($privateDir) || str_starts_with(strtolower((string) realpath($privateDir)), strtolower($projectRoot))) {
    fwrite(STDERR, "Refusing to run: set AGRIREACH_PRIVATE_ID_DIR to a throwaway folder outside the project (the API server must use it too).\n");
    exit(1);
}

$base = agrireach_test_base_url() . '/CAPSTONE%20AGRICULTURE/';
$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
    $checks++;
}

/** A small synthetic PNG card (zlib only; no GD), so front and back have different, known bytes. */
function synthetic_png(int $width, int $height, array $rgb): string
{
    $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    $row = str_repeat(chr($rgb[0]) . chr($rgb[1]) . chr($rgb[2]), $width);
    $raw = '';
    for ($y = 0; $y < $height; $y++) $raw .= "\0" . $row;
    return "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0)) . $chunk('IDAT', (string) gzcompress($raw)) . $chunk('IEND', '');
}

function http(string $url, array $options = []): array
{
    $curl = curl_init($url);
    curl_setopt_array($curl, $options + [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 30]);
    $raw = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    curl_close($curl);
    if (!is_string($raw)) throw new RuntimeException('HTTP request failed: ' . $url);
    return ['status' => $status, 'headers' => substr($raw, 0, $headerSize), 'body' => substr($raw, $headerSize)];
}

function register(array $fields, ?string $front, ?string $back): array
{
    global $base;
    if ($front !== null) $fields['valid_id_front'] = new CURLFile($front, 'image/png', 'rsbsa-id-front.png');
    if ($back !== null) $fields['valid_id_back'] = new CURLFile($back, 'image/png', 'rsbsa-id-back.png');
    $response = http($base . 'api/farmers/register.php', [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $fields, CURLOPT_HTTPHEADER => ['Accept: application/json']]);
    $response['payload'] = json_decode($response['body'], true) ?: [];
    return $response;
}

function stored_files(string $directory): array
{
    return array_values(array_filter(scandir($directory) ?: [], static fn (string $name): bool => is_file($directory . DIRECTORY_SEPARATOR . $name)));
}

$db = db();
$run = (string) random_int(1000000, 9999999);
$work = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'agrireach-rsbsa-' . $run;
mkdir($work, 0700, true);
$frontPng = $work . DIRECTORY_SEPARATOR . 'front.png';
$backPng = $work . DIRECTORY_SEPARATOR . 'back.png';
file_put_contents($frontPng, synthetic_png(64, 40, [23, 77, 54]));
file_put_contents($backPng, synthetic_png(64, 40, [189, 146, 41]));
$frontHash = hash_file('sha256', $frontPng);
$backHash = hash_file('sha256', $backPng);

$fields = static function (string $label, string $number) use ($run): array {
    return [
        'first_name' => 'Rsbsa', 'last_name' => 'Fixture', 'gender' => 'Prefer not to say', 'custom_gender' => '',
        'email' => "rsbsa-{$run}-{$label}@example.test", 'contact_number' => '0917' . substr($run, 0, 3) . str_pad((string) (crc32($label) % 10000), 4, '0', STR_PAD_LEFT),
        'residential_city_psgc_code' => '1600203000', 'residential_barangay_psgc_code' => '1600203019', 'residential_purok_sitio' => 'Purok 1',
        'residential_landmark' => '', 'residential_location_method' => 'Manual', 'residential_latitude' => '', 'residential_longitude' => '',
        'date_of_birth' => '1990-01-02', 'password' => 'Controlled123', 'confirm_password' => 'Controlled123',
        'farm_name' => 'Rsbsa Fixture Farm', 'farm_type' => 'Livestock', 'agricultural_category' => 'Livestock',
        'farm_city_psgc_code' => '1600203000', 'farm_barangay_psgc_code' => '1600203025', 'farm_purok_sitio' => 'Purok 3', 'farm_landmark' => '',
        'farm_location_method' => 'Manual', 'latitude' => '', 'longitude' => '', 'land_area' => '2.5', 'main_product' => 'Goat',
        'id_type' => 'RSBSA ID', 'id_number' => $number,
    ];
};

$farmerIds = [];
$adminId = null;
$sessionId = 'rsbsatest' . bin2hex(random_bytes(12));
$sessionFile = rtrim((string) ini_get('session.save_path'), '/\\') . DIRECTORY_SEPARATOR . 'sess_' . $sessionId;
try {
    $filesBefore = stored_files($privateDir);

    // 1. Step-1 requirements are enforced by the API too, and failures store nothing.
    $noNumber = register($fields('nonumber', ''), $frontPng, $backPng);
    check($noNumber['status'] === 422 && ($noNumber['payload']['errors']['idNumber'] ?? '') === 'Enter the 15-digit RSBSA reference number printed on your card.', 'the RSBSA reference number is required');
    // Only the 15 digits alone or grouped 00-00-00-000-000000 are accepted for a new RSBSA ID registration.
    foreach (['short' => '16020300500012', 'extra' => '1602030050001234', 'letters' => 'AB-02-03-005-000123', 'grouping' => '160-20-30-050-00123', 'spaces' => '16 02 03 005 000123', 'partial' => '16-02-03-005-', 'long' => str_repeat('7', 101), 'control' => "16-02-03-005-00012"] as $label => $bad) {
        $refused = register($fields($label, $bad), $frontPng, $backPng);
        check($refused['status'] === 422 && ($refused['payload']['errors']['idNumber'] ?? '') === 'Enter all 15 digits of the RSBSA reference number (00-00-00-000-000000).', "malformed RSBSA number '{$label}' is refused by the API");
    }
    $noBack = register($fields('noback', '16-02-03-005-000001'), $frontPng, null);
    check($noBack['status'] === 422 && ($noBack['payload']['errors']['idBackImageUri'] ?? '') === 'Back of RSBSA ID is required.', 'the back of the RSBSA ID is required');
    $noFront = register($fields('nofront', '16-02-03-005-000002'), null, $backPng);
    check($noFront['status'] === 422 && str_contains((string) ($noFront['payload']['errors']['idFrontImageUri'] ?? ''), 'Front of RSBSA ID'), 'the front of the RSBSA ID is required');
    check(stored_files($privateDir) === $filesBefore, 'refused registrations leave no files in protected storage');
    check((int) $db->query("SELECT COUNT(*) FROM farmers WHERE email LIKE 'rsbsa-{$run}-%'")->fetchColumn() === 0, 'refused registrations leave no farmer rows');

    // 2. Grouped, bare 15-digit and leading-zero numbers are accepted and stored as 00-00-00-000-000000.
    $accepted = [];
    $canonical = ['dashed' => '16-02-03-005-000123', 'mixed' => '16-02-03-005-000124', 'reference' => '00-00-00-000-000045'];
    foreach (['dashed' => '16-02-03-005-000123', 'mixed' => '160203005000124', 'reference' => '00-00-00-000-000045'] as $label => $number) {
        $result = register($fields($label, $number), $frontPng, $backPng);
        check($result['status'] === 201 && ($result['payload']['application']['verificationStatus'] ?? '') === 'Pending', "RSBSA number format '{$label}' is accepted");
        $farmerIds[] = $accepted[$label] = (int) $result['payload']['application']['id'];
        $row = $db->prepare('SELECT fi.id_type, fi.id_number, fi.image_path, fi.front_image_path, fi.back_image_path, f.verification_status, f.account_status FROM farmer_ids fi JOIN farmers f ON f.id = fi.farmer_id WHERE fi.farmer_id = ?');
        $row->execute([$accepted[$label]]);
        $stored = $row->fetch();
        check($stored['id_type'] === 'RSBSA ID' && $stored['id_number'] === $canonical[$label], "'{$label}' is stored as RSBSA ID in the canonical grouped form, leading zeroes kept");
        check($stored['verification_status'] === 'Pending' && $stored['account_status'] === 'Active', "'{$label}' waits for manual admin review");
        check($stored['image_path'] === null && $stored['front_image_path'] !== $stored['back_image_path'], "'{$label}' keeps separate front and back files");
        check(hash_file('sha256', $privateDir . DIRECTORY_SEPARATOR . $stored['front_image_path']) === $frontHash, "'{$label}' front_image_path holds the front photo");
        check(hash_file('sha256', $privateDir . DIRECTORY_SEPARATOR . $stored['back_image_path']) === $backHash, "'{$label}' back_image_path holds the back photo");
        check(!is_file($projectRoot . '/uploads/ids/' . $stored['front_image_path']) && !is_file($projectRoot . '/storage/' . $stored['front_image_path']), "'{$label}' photos are not stored inside the web project");
    }
    $farmerId = $accepted['mixed'];

    // 3. Retrying a submission that was already saved does not create a second applicant or orphan files.
    $filesAfter = stored_files($privateDir);
    $retry = register($fields('mixed', '160203005000124'), $frontPng, $backPng);
    check($retry['status'] === 409 && isset($retry['payload']['errors']['email']), 'a retried, already saved registration answers 409 for the email');
    check((int) $db->query("SELECT COUNT(*) FROM farmers WHERE email = 'rsbsa-{$run}-mixed@example.test'")->fetchColumn() === 1, 'the retry did not create a second applicant');
    check(stored_files($privateDir) === $filesAfter, 'the retry stored no extra files');
    $login = http($base . 'api/auth/login.php', [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode(['email' => "rsbsa-{$run}-mixed@example.test", 'password' => 'Controlled123']), CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
    check($login['status'] === 403 && (json_decode($login['body'], true)['status'] ?? '') === 'pending', 'login reports pending review (no automatic approval)');

    // 4. Identity images are never public; the admin sees the right side for the right applicant.
    check(http($base . 'admin/farmer-id-image.php?farmer_id=' . $farmerId . '&side=front')['status'] === 302, 'admin image route without a session redirects to login');
    check(http($base . 'api/farmers/id-image.php?side=front')['status'] === 401, 'API image route without a token is 401');
    $storedName = (string) $db->query('SELECT front_image_path FROM farmer_ids WHERE farmer_id = ' . $farmerId)->fetchColumn();
    foreach (['uploads/ids/', 'storage/', 'backend/', 'frontend/'] as $prefix) {
        $direct = http($base . $prefix . $storedName);
        check($direct['status'] !== 200 || $direct['body'] !== file_get_contents($frontPng), "no direct URL under {$prefix} serves the photo");
    }

    $hash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    $takeover = test_admin_takeover($db, 'RSBSA test admin', 'rsbsa_admin_' . $run, $hash);
    $adminId = $takeover['id'];
    session_id($sessionId);
    session_start();
    $_SESSION['admin_id'] = $adminId;
    $_SESSION['admin_role'] = 'admin';
    $_SESSION['admin_name'] = 'RSBSA test admin';
    session_write_close();
    $cookie = [CURLOPT_COOKIE => session_name() . '=' . $sessionId];

    $front = http($base . 'admin/farmer-id-image.php?farmer_id=' . $farmerId . '&side=front', $cookie);
    $back = http($base . 'admin/farmer-id-image.php?farmer_id=' . $farmerId . '&side=back', $cookie);
    check($front['status'] === 200 && hash('sha256', $front['body']) === $frontHash, 'admin front view shows the Front of RSBSA ID');
    check($back['status'] === 200 && hash('sha256', $back['body']) === $backHash, 'admin back view shows the Back of RSBSA ID');
    check(stripos($front['headers'], 'Cache-Control: private, no-store') !== false && stripos($front['headers'], 'X-Content-Type-Options: nosniff') !== false, 'identity images are sent private, no-store, nosniff');
    check(http($base . 'admin/farmer-id-image.php?farmer_id=' . $accepted['dashed'] . '&side=front', $cookie)['body'] === $front['body'], 'each applicant id resolves to its own stored file');

    // The page searches by full name; the disposable database holds only these fixtures.
    $page = http($base . 'admin/farmer-verification.php?status=Pending&search=' . rawurlencode('Rsbsa Fixture'), $cookie);
    check($page['status'] === 200, 'Admin Farmer Verification opens');
    check(preg_match('/<script type="application\/json" id="farmer-data-' . $farmerId . '">(.*?)<\/script>/s', $page['body'], $match) === 1, 'the applicant is listed for review');
    $detail = json_decode(html_entity_decode($match[1]), true);
    check(($detail['id_type'] ?? '') === 'RSBSA ID' && ($detail['id_number'] ?? '') === '16-02-03-005-000124', 'the review data carries the RSBSA type and the formatted number');
    check(($detail['has_id_front'] ?? false) === true && ($detail['has_id_back'] ?? false) === true, 'the review data shows both sides are available');
    check(!str_contains($match[1], (string) $storedName), 'stored file names are not exposed to the page');
    foreach (['Front of RSBSA ID', 'Back of RSBSA ID', 'RSBSA Reference Number', 'does not verify RSBSA registration automatically'] as $label) {
        check(str_contains($page['body'], $label), "the review dialog labels '{$label}'");
    }

    // 5. Existing registrations and their stored images stay readable.
    $legacy = $fields('legacy', 'LEGACY-' . $run);
    $legacy['id_type'] = 'Philippine National ID';
    $legacyResult = register($legacy, $frontPng, $backPng);
    check($legacyResult['status'] === 201, 'older app builds that send a legacy ID type still register');
    $farmerIds[] = (int) $legacyResult['payload']['application']['id'];
    $db->prepare("INSERT INTO farmers (full_name, contact_number, email, address, verification_status, account_status, password_hash) VALUES ('Legacy Single Image', ?, ?, 'Controlled legacy address', 'Approved', 'Active', ?)")
        ->execute(['+63918' . substr($run, 0, 7), "rsbsa-{$run}-single@example.test", $hash]);
    $singleId = db_insert_id($db, 'farmers');
    $farmerIds[] = $singleId;
    $singleName = generated_image_filename('png');
    copy($frontPng, $privateDir . DIRECTORY_SEPARATOR . $singleName);
    $db->prepare("INSERT INTO farmer_ids (farmer_id, id_type, id_number, image_path) VALUES (?, 'National ID', 'OLD-1', ?)")->execute([$singleId, $singleName]);
    $oldFront = http($base . 'admin/farmer-id-image.php?farmer_id=' . $singleId . '&side=front', $cookie);
    check($oldFront['status'] === 200 && hash('sha256', $oldFront['body']) === $frontHash, 'a legacy single-image record still shows its image as the front');
    check(http($base . 'admin/farmer-id-image.php?farmer_id=' . $singleId . '&side=back', $cookie)['status'] === 404, 'a legacy single-image record has no back image');

    // A stored RSBSA record from before the 15-digit rule keeps its historical number and stays readable.
    $db->prepare("INSERT INTO farmers (full_name, first_name, last_name, contact_number, email, address, verification_status, account_status, password_hash) VALUES ('Rsbsa Fixture', 'Rsbsa', 'Fixture', ?, ?, 'Controlled historical address', 'Pending', 'Active', ?)")
        ->execute(['+63919' . substr($run, 0, 7), "rsbsa-{$run}-historical@example.test", $hash]);
    $historicalId = db_insert_id($db, 'farmers');
    $farmerIds[] = $historicalId;
    $db->prepare("INSERT INTO farmer_ids (farmer_id, id_type, id_number, front_image_path, back_image_path) VALUES (?, 'RSBSA ID', 'RSBSA 1602 0300 5/ab-12', NULL, NULL)")->execute([$historicalId]);
    $page = http($base . 'admin/farmer-verification.php?status=Pending&search=' . rawurlencode('Rsbsa Fixture'), $cookie);
    check(preg_match('/<script type="application\/json" id="farmer-data-' . $historicalId . '">(.*?)<\/script>/s', $page['body'], $match) === 1, 'the historical RSBSA record is listed');
    check((json_decode($match[1], true)['id_number'] ?? '') === 'RSBSA 1602 0300 5/ab-12', 'a historical RSBSA number is shown exactly as stored');
} finally {
    $placeholders = implode(', ', array_fill(0, max(1, count($farmerIds)), '?'));
    $names = [];
    if ($farmerIds) {
        $files = $db->prepare("SELECT image_path, front_image_path, back_image_path FROM farmer_ids WHERE farmer_id IN ({$placeholders})");
        $files->execute($farmerIds);
        foreach ($files->fetchAll() as $row) foreach ($row as $name) if ($name) $names[] = basename((string) $name);
        $db->prepare("DELETE FROM farmers WHERE id IN ({$placeholders}) AND email LIKE 'rsbsa-%@example.test'")->execute($farmerIds);
    }
    foreach ($names as $name) @unlink($privateDir . DIRECTORY_SEPARATOR . $name);
    if ($adminId !== null) $db->prepare("DELETE FROM admins WHERE id = ? AND username LIKE 'rsbsa\\_admin\\_%'")->execute([$adminId]);
    if (isset($takeover)) test_admin_restore($db, $takeover);
    @unlink($sessionFile);
    @unlink($frontPng); @unlink($backPng); @rmdir($work);
}

check((int) $db->query("SELECT COUNT(*) FROM farmers WHERE email LIKE 'rsbsa-{$run}-%'")->fetchColumn() === 0, 'fixture farmers were removed');
check(stored_files($privateDir) === $filesBefore, 'fixture files were removed from protected storage');
echo "PASS: RSBSA registration, {$checks} checks: required front/back/number, 15-digit reference stored as 00-00-00-000-000000 (bare or grouped, leading zeroes), malformed numbers refused, historical numbers readable, exact front/back storage, no orphan files on refusal or retry, pending review only, private image routes, admin review labels and data, legacy records readable; fixtures cleaned up\n";
