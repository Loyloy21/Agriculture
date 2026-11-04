<?php
declare(strict_types=1);

/**
 * Farmer Middle Name / Extension Name, end to end: registration API, database values, composed
 * full_name, login payload, farmer profile updates, Admin Farmer Verification and Manage Users
 * (display, edit, add), including older records without name parts.
 *
 * DISPOSABLE DATA ONLY. Refuses to run against Supabase; needs AGRIREACH_PRIVATE_ID_DIR pointing at a
 * throwaway folder outside the project (the API server must use the same database and folder). Uses
 * synthetic images and @example.test farmers, sends no email, and deletes every fixture it creates.
 *   AGRIREACH_TEST_BASE_URL=http://127.0.0.1:8195 php tests/backend/farmer_name_parts_integration.php
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../support/test_base_url.php';
require_once dirname(__DIR__, 2) . '/backend/config/database.php';
require_once dirname(__DIR__) . '/support/admin_test_account.php';

$settings = db_connection_settings();
if ($settings['name'] === 'supabase' || str_contains(strtolower($settings['host']), 'supabase')) {
    fwrite(STDERR, "Refusing to run: use a disposable database, not Supabase.\n");
    exit(1);
}
$privateDir = (string) getenv('AGRIREACH_PRIVATE_ID_DIR');
$projectRoot = (string) realpath(dirname(__DIR__, 2));
if ($privateDir === '' || !is_dir($privateDir) || str_starts_with(strtolower((string) realpath($privateDir)), strtolower($projectRoot))) {
    fwrite(STDERR, "Refusing to run: set AGRIREACH_PRIVATE_ID_DIR to a throwaway folder outside the project.\n");
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

function synthetic_png(array $rgb): string
{
    $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    $raw = str_repeat("\0" . str_repeat(chr($rgb[0]) . chr($rgb[1]) . chr($rgb[2]), 32), 20);
    return "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', 32, 20, 8, 2, 0, 0, 0)) . $chunk('IDAT', (string) gzcompress($raw)) . $chunk('IEND', '');
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
    $body = substr($raw, $headerSize);
    return ['status' => $status, 'body' => $body, 'json' => json_decode($body, true) ?: []];
}

function farmer(PDO $db, int $id): array
{
    $statement = $db->prepare('SELECT * FROM farmers WHERE id = ?');
    $statement->execute([$id]);
    return $statement->fetch() ?: [];
}

$db = db();
$run = (string) random_int(1000000, 9999999);
$work = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'agrireach-names-' . $run;
mkdir($work, 0700, true);
$front = $work . '/front.png'; $back = $work . '/back.png';
file_put_contents($front, synthetic_png([23, 77, 54])); file_put_contents($back, synthetic_png([189, 146, 41]));

$sequence = 0;
$register = static function (string $label, array $names) use ($base, $run, $front, $back, &$sequence): array {
    $sequence++;
    $fields = $names + [
        'gender' => 'Prefer not to say', 'custom_gender' => '', 'email' => "names-{$run}-{$label}@example.test", 'contact_number' => '0917' . substr($run, 0, 4) . str_pad((string) $sequence, 3, '0', STR_PAD_LEFT),
        'residential_city_psgc_code' => '1600203000', 'residential_barangay_psgc_code' => '1600203019', 'residential_purok_sitio' => 'Purok 1', 'residential_landmark' => '',
        'residential_location_method' => 'Manual', 'residential_latitude' => '', 'residential_longitude' => '', 'date_of_birth' => '1991-03-04',
        'password' => 'Controlled123', 'confirm_password' => 'Controlled123', 'farm_name' => 'Names Fixture Farm', 'farm_type' => 'Crops', 'agricultural_category' => 'Crops',
        'farm_city_psgc_code' => '1600203000', 'farm_barangay_psgc_code' => '1600203025', 'farm_purok_sitio' => 'Purok 3', 'farm_landmark' => '', 'farm_location_method' => 'Manual',
        'latitude' => '', 'longitude' => '', 'land_area' => '1.5', 'main_product' => 'Rice', 'id_type' => 'RSBSA ID', 'id_number' => '160203005' . str_pad((string) $sequence, 6, '0', STR_PAD_LEFT),
        'valid_id_front' => new CURLFile($front, 'image/png', 'front.png'), 'valid_id_back' => new CURLFile($back, 'image/png', 'back.png'),
    ];
    return http($base . 'api/farmers/register.php', [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $fields, CURLOPT_HTTPHEADER => ['Accept: application/json']]);
};

$farmerIds = [];
$adminId = null;
$sessionId = 'namestest' . bin2hex(random_bytes(12));
$sessionFile = rtrim((string) ini_get('session.save_path'), '/\\') . DIRECTORY_SEPARATOR . 'sess_' . $sessionId;
$csrf = bin2hex(random_bytes(32));
try {
    // 1. Registration: both optional parts, only Middle, only Extension, neither, placeholders, older client.
    $cases = [
        'both' => [['first_name' => 'Maria Clara', 'middle_name' => 'Dela  Cruz', 'last_name' => 'Santos', 'extension_name' => 'Jr.'], ['Maria Clara', 'Dela Cruz', 'Santos', 'Jr.'], 'Maria Clara Dela Cruz Santos Jr.'],
        'middle' => [['first_name' => 'Jose', 'middle_name' => 'Ma. Luisa', 'last_name' => "O'Neil", 'extension_name' => ''], ['Jose', 'Ma. Luisa', "O'Neil", null], "Jose Ma. Luisa O'Neil"],
        'extension' => [['first_name' => 'Pedro', 'middle_name' => '', 'last_name' => 'Reyes-Garcia', 'extension_name' => 'III'], ['Pedro', null, 'Reyes-Garcia', 'III'], 'Pedro Reyes-Garcia III'],
        'neither' => [['first_name' => 'Ana', 'middle_name' => '', 'last_name' => 'Lim', 'extension_name' => ''], ['Ana', null, 'Lim', null], 'Ana Lim'],
        'placeholders' => [['first_name' => 'Rosa', 'middle_name' => 'N/A', 'last_name' => 'Tan', 'extension_name' => 'none'], ['Rosa', null, 'Tan', null], 'Rosa Tan'],
        'olderclient' => [['first_name' => 'Ben', 'last_name' => 'Uy'], ['Ben', null, 'Uy', null], 'Ben Uy'],
    ];
    $ids = [];
    foreach ($cases as $label => [$names, $expected, $full]) {
        $result = $register($label, $names);
        check($result['status'] === 201, "registration '{$label}' is accepted");
        $farmerIds[] = $ids[$label] = (int) $result['json']['application']['id'];
        $row = farmer($db, $ids[$label]);
        check([$row['first_name'], $row['middle_name'], $row['last_name'], $row['extension_name']] === $expected, "'{$label}': name parts are stored separately (blank optional parts as NULL)");
        check($row['full_name'] === $full, "'{$label}': full_name is '{$full}' (First Middle Last Extension, no double spaces)");
        check(!preg_match('/\b(N\/A|null|undefined|none)\b/i', (string) $row['full_name']), "'{$label}': no placeholder text is saved");
        check($row['verification_status'] === 'Pending' && $row['account_status'] === 'Active', "'{$label}': registration status is unchanged (Pending, Active)");
    }
    $invalidMiddle = $register('badmiddle', ['first_name' => 'Lito', 'middle_name' => 'Ma..ria', 'last_name' => 'Cruz', 'extension_name' => '']);
    check($invalidMiddle['status'] === 422 && isset($invalidMiddle['json']['errors']['middleName']), 'an invalid Middle Name is refused with a field error');
    $invalidExtension = $register('badextension', ['first_name' => 'Lito', 'middle_name' => '', 'last_name' => 'Cruz', 'extension_name' => 'Jr!!']);
    check($invalidExtension['status'] === 422 && isset($invalidExtension['json']['errors']['extensionName']), 'an invalid Extension Name is refused with a field error');
    check((int) $db->query("SELECT COUNT(*) FROM farmers WHERE email IN ('names-{$run}-badmiddle@example.test', 'names-{$run}-badextension@example.test')")->fetchColumn() === 0, 'refused registrations create no farmer');

    // 2. Login payload carries the parts; approval here is test setup on the disposable database.
    $db->prepare("UPDATE farmers SET verification_status = 'Approved', verified_at = " . db_sql_utc_now($db) . " WHERE id IN (?, ?, ?)")->execute([$ids['both'], $ids['neither'], $ids['middle']]);
    $login = http($base . 'api/auth/login.php', [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode(['email' => "names-{$run}-both@example.test", 'password' => 'Controlled123']), CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
    check($login['status'] === 200, 'email login still works');
    $payload = $login['json']['farmer'] ?? [];
    check(($payload['firstName'] ?? '') === 'Maria Clara' && ($payload['middleName'] ?? '') === 'Dela Cruz' && ($payload['lastName'] ?? '') === 'Santos' && ($payload['extensionName'] ?? '') === 'Jr.' && ($payload['fullName'] ?? '') === 'Maria Clara Dela Cruz Santos Jr.', 'the app receives every name part and the composed full name');
    $token = (string) $login['json']['token'];
    $neitherLogin = http($base . 'api/auth/login.php', [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode(['email' => "names-{$run}-neither@example.test", 'password' => 'Controlled123']), CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
    check(($neitherLogin['json']['farmer']['middleName'] ?? null) === '' && ($neitherLogin['json']['farmer']['extensionName'] ?? null) === '', 'blank parts reach the app as empty strings, never null or undefined');

    // 3. Farmer profile updates: an older app (no middle/extension keys) keeps the stored parts.
    $profile = static fn (array $body) => http($base . 'api/farmers/profile.php', [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode(['action' => 'update_profile'] + $body), CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $token]]);
    $common = ['gender' => 'Female', 'dateOfBirth' => '1991-03-04', 'email' => "names-{$run}-both@example.test", 'contactNumber' => (string) farmer($db, $ids['both'])['contact_number'], 'address' => 'Purok 1, Controlled address'];
    check($profile(['firstName' => 'Maria Clara', 'lastName' => 'Santos'] + $common)['status'] === 200, 'an older app profile update without the new keys is accepted');
    $row = farmer($db, $ids['both']);
    check($row['middle_name'] === 'Dela Cruz' && $row['extension_name'] === 'Jr.' && $row['full_name'] === 'Maria Clara Dela Cruz Santos Jr.', 'that update keeps Middle/Extension and a consistent full_name');
    check($profile(['firstName' => 'Maria Clara', 'middleName' => 'Reyes', 'lastName' => 'Santos', 'extensionName' => ''] + $common)['status'] === 200, 'the new app can change and clear optional parts');
    $row = farmer($db, $ids['both']);
    check($row['middle_name'] === 'Reyes' && $row['extension_name'] === null && $row['full_name'] === 'Maria Clara Reyes Santos', 'changed/cleared parts are stored and full_name follows');
    check($profile(['firstName' => 'Maria Clara', 'middleName' => '9', 'lastName' => 'Santos', 'extensionName' => ''] + $common)['status'] === 422, 'an invalid Middle Name is refused on profile update');
    $statusAfter = farmer($db, $ids['both']);
    check($statusAfter['verification_status'] === 'Approved' && $statusAfter['account_status'] === 'Active' && password_verify('Controlled123', (string) $statusAfter['password_hash']), 'profile updates do not touch status or password');

    // 4. Admin pages: a throwaway admin session with a known CSRF token.
    $takeover = test_admin_takeover($db, 'Names test admin', 'names_admin_' . $run, password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT));
    $adminId = $takeover['id'];
    session_id($sessionId);
    session_start();
    $_SESSION = ['admin_id' => $adminId, 'admin_role' => 'admin', 'admin_name' => 'Names test admin', 'csrf_token' => $csrf];
    session_write_close();
    $cookie = [CURLOPT_COOKIE => session_name() . '=' . $sessionId];
    $adminPost = static fn (string $page, array $fields) => http($base . 'admin/' . $page, $cookie + [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $fields + ['csrf_token' => $csrf]]);

    // Farmer Verification shows the parts and the formatted RSBSA number for the pending applicant.
    $page = http($base . 'admin/farmer-verification.php?status=Pending&search=' . rawurlencode('Pedro Reyes-Garcia III'), $cookie);
    check(preg_match('/<script type="application\/json" id="farmer-data-' . $ids['extension'] . '">(.*?)<\/script>/s', $page['body'], $match) === 1, 'the applicant is listed in Farmer Verification');
    $detail = json_decode($match[1], true);
    check($detail['first_name'] === 'Pedro' && $detail['middle_name'] === null && $detail['last_name'] === 'Reyes-Garcia' && $detail['extension_name'] === 'III' && $detail['full_name'] === 'Pedro Reyes-Garcia III', 'verification data carries each name part and the composed full name');
    check(($detail['id_number'] ?? '') === '16-02-03-005-000003', 'verification data carries the canonical RSBSA reference number');
    foreach (["row('First Name', data.first_name)", "row('Middle Name', data.middle_name)", "row('Last Name', data.last_name)", "row('Extension Name', data.extension_name)", 'RSBSA Reference Number'] as $label) check(str_contains($page['body'], $label), "verification details show {$label}");

    // Manage Users lists approved farmers with their parts.
    $users = http($base . 'admin/manage-users.php?search=' . rawurlencode("names-{$run}-middle"), $cookie);
    check(preg_match('/<script type="application\/json" id="user-data-farmer:' . $ids['middle'] . '">(.*?)<\/script>/s', $users['body'], $match) === 1, 'the approved farmer is listed in Manage Users');
    $user = json_decode($match[1], true);
    check($user['first_name'] === 'Jose' && $user['middle_name'] === 'Ma. Luisa' && $user['last_name'] === "O'Neil" && $user['extension_name'] === null && $user['user_name'] === "Jose Ma. Luisa O'Neil", 'Manage Users data carries the name parts');
    foreach (['id="editMiddleName"', 'id="editExtensionName"', 'id="addMiddleName"', 'id="addExtensionName"', "row('Middle Name', data.middle_name)"] as $marker) check(str_contains($users['body'], $marker), "Manage Users offers {$marker}");

    // Editing contact details only keeps every name part; editing parts recomposes full_name.
    $middle = farmer($db, $ids['middle']);
    $adminPost('manage-users.php', ['action' => 'edit', 'user_type' => 'farmer', 'user_id' => $ids['middle'], 'first_name' => 'Jose', 'middle_name' => 'Ma. Luisa', 'last_name' => "O'Neil", 'extension_name' => '', 'contact_number' => '+63917' . substr($run, 0, 7), 'email' => (string) $middle['email'], 'address' => (string) $middle['address']]);
    $row = farmer($db, $ids['middle']);
    check($row['middle_name'] === 'Ma. Luisa' && $row['full_name'] === "Jose Ma. Luisa O'Neil" && $row['contact_number'] === '+63917' . substr($run, 0, 7), 'an admin contact edit keeps the optional name parts and full_name');
    $adminPost('manage-users.php', ['action' => 'edit', 'user_type' => 'farmer', 'user_id' => $ids['middle'], 'first_name' => 'Jose', 'middle_name' => '', 'last_name' => "O'Neil", 'extension_name' => 'Sr.', 'contact_number' => (string) $row['contact_number'], 'email' => (string) $row['email'], 'address' => (string) $row['address']]);
    $row = farmer($db, $ids['middle']);
    check($row['middle_name'] === null && $row['extension_name'] === 'Sr.' && $row['full_name'] === "Jose O'Neil Sr." && $row['first_name'] === 'Jose', 'an admin name edit stores the parts and recomposes full_name');
    check($row['verification_status'] === 'Approved' && $row['account_status'] === 'Active', 'admin edits leave approval and account status unchanged');
    $adminPost('manage-users.php', ['action' => 'edit', 'user_type' => 'farmer', 'user_id' => $ids['middle'], 'first_name' => '', 'middle_name' => '', 'last_name' => "O'Neil", 'extension_name' => '', 'contact_number' => (string) $row['contact_number'], 'email' => (string) $row['email'], 'address' => (string) $row['address']]);
    check(farmer($db, $ids['middle'])['full_name'] === "Jose O'Neil Sr.", 'an edit with a blank First Name is refused and changes nothing');

    // An older farmer record without name parts keeps the single Full Name edit.
    $db->prepare("INSERT INTO farmers (full_name, contact_number, email, address, verification_status, account_status, password_hash) VALUES ('Legacy Whole Name', ?, ?, 'Controlled legacy address', 'Approved', 'Active', ?)")
        ->execute(['+63918' . substr($run, 0, 7), "names-{$run}-legacy@example.test", password_hash('Controlled123', PASSWORD_DEFAULT)]);
    $legacyId = db_insert_id($db, 'farmers');
    $farmerIds[] = $legacyId;
    $adminPost('manage-users.php', ['action' => 'edit', 'user_type' => 'farmer', 'user_id' => $legacyId, 'full_name' => 'Legacy Whole Name Edited', 'contact_number' => '+63918' . substr($run, 0, 7), 'email' => "names-{$run}-legacy@example.test", 'address' => 'Controlled legacy address']);
    $legacy = farmer($db, $legacyId);
    check($legacy['full_name'] === 'Legacy Whole Name Edited' && $legacy['first_name'] === null && $legacy['middle_name'] === null, 'a legacy record is still edited through Full Name and gets no invented parts');

    // Admin add: optional parts are stored and composed; an RSBSA ID number must be complete.
    $addFields = static fn (string $label, array $extra) => $extra + [
        'action' => 'add', 'first_name' => 'Lorna', 'last_name' => 'Bautista', 'gender' => 'Female', 'contact_number' => '+63916' . substr($run, 0, 6) . ($label === 'ok' ? '1' : '2'),
        'email' => "names-{$run}-add-{$label}@example.test", 'address' => 'Controlled admin-added address', 'date_of_birth' => '1980-05-06', 'password' => 'Controlled123', 'confirm_password' => 'Controlled123',
        'farm_name' => 'Admin Added Farm', 'farm_type' => 'Crops', 'agricultural_category' => 'Crops', 'farm_location' => 'Purok 2, Cabadbaran City', 'farm_size' => '1.2', 'main_product' => 'Rice',
        'latitude' => '', 'longitude' => '', 'valid_id' => new CURLFile($front, 'image/png', 'id.png'),
    ];
    $adminPost('manage-users.php', $addFields('ok', ['middle_name' => 'Gomez', 'extension_name' => 'II', 'id_type' => 'National ID', 'id_number' => 'N-' . $run]));
    $added = $db->query("SELECT * FROM farmers WHERE email = 'names-{$run}-add-ok@example.test'")->fetch();
    check(is_array($added) && $added['middle_name'] === 'Gomez' && $added['extension_name'] === 'II' && $added['full_name'] === 'Lorna Gomez Bautista II' && $added['verification_status'] === 'Pending', 'admin-added farmer stores the parts, composes full_name and stays Pending');
    if (is_array($added)) $farmerIds[] = (int) $added['id'];
    $adminPost('manage-users.php', $addFields('badrsbsa', ['middle_name' => '', 'extension_name' => '', 'id_type' => 'RSBSA ID', 'id_number' => '16-02-03']));
    check($db->query("SELECT COUNT(*) FROM farmers WHERE email = 'names-{$run}-add-badrsbsa@example.test'")->fetchColumn() == 0, 'an admin add with an incomplete RSBSA number is refused');
} finally {
    if ($farmerIds) {
        $placeholders = implode(', ', array_fill(0, count($farmerIds), '?'));
        $files = $db->prepare("SELECT image_path, front_image_path, back_image_path FROM farmer_ids WHERE farmer_id IN ({$placeholders})");
        $files->execute($farmerIds);
        foreach ($files->fetchAll() as $row) foreach ($row as $name) if ($name) @unlink($privateDir . DIRECTORY_SEPARATOR . basename((string) $name));
        $db->prepare("DELETE FROM farmers WHERE id IN ({$placeholders}) AND email LIKE 'names-%@example.test'")->execute($farmerIds);
    }
    if ($adminId !== null) $db->prepare("DELETE FROM admins WHERE id = ? AND username LIKE 'names\\_admin\\_%'")->execute([$adminId]);
    if (isset($takeover)) test_admin_restore($db, $takeover);
    @unlink($sessionFile); @unlink($front); @unlink($back); @rmdir($work);
}
check((int) $db->query("SELECT COUNT(*) FROM farmers WHERE email LIKE 'names-{$run}-%'")->fetchColumn() === 0, 'fixture farmers were removed');
echo "PASS: farmer name parts, {$checks} checks: registration with both/middle only/extension only/neither/placeholders/older client, separate NULLable columns, composed full_name, login payload, profile updates keep parts, Farmer Verification and Manage Users display/edit/add, legacy full-name records; fixtures cleaned up\n";
