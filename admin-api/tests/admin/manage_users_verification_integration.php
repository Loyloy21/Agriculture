<?php
declare(strict_types=1);

require_once __DIR__ . '/../support/test_base_url.php';

// CLI-only integration checks. All farmer fixtures are created with .test addresses
// and removed by their exact ID and email in the finally block.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__, 2) . '/backend/config/database.php';
require_once dirname(__DIR__, 2) . '/backend/lib/farmer_verification.php';

if (($argv[1] ?? '') === 'render') {
    session_start();
    $_SESSION['admin_id'] = 1;
    $_SESSION['admin_role'] = 'admin';
    $_SESSION['admin_name'] = 'Test Administrator';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = json_decode((string) ($argv[2] ?? '{}'), true, 8) ?: [];
    require dirname(__DIR__, 2) . '/frontend/admin/manage-users.php';
    exit;
}

function run_child(array $arguments): string
{
    $process = proc_open([PHP_BINARY, __FILE__, ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not start a test subprocess.');
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) throw new RuntimeException('Test subprocess failed: ' . trim((string) $error));
    return (string) $output;
}

function render_users(array $filters = []): string
{
    return run_child(['render', json_encode($filters, JSON_THROW_ON_ERROR)]);
}

function user_count(string $html): int
{
    if (!preg_match('/<p class="text-muted small mb-0">(\d+) users? found<\/p>/', $html, $match)) {
        throw new RuntimeException('Manage Users count could not be read.');
    }
    return (int) $match[1];
}

function api_request(string $path, ?array $payload = null, ?string $token = null): array
{
    $request = curl_init(agrireach_test_base_url() . '/CAPSTONE%20AGRICULTURE/api/' . $path);
    if ($request === false) throw new RuntimeException('HTTP test could not start.');
    $headers = ['Accept: application/json'];
    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($request, CURLOPT_POST, true);
        curl_setopt($request, CURLOPT_POSTFIELDS, json_encode($payload, JSON_THROW_ON_ERROR));
    }
    if ($token !== null) $headers[] = 'Authorization: Bearer ' . $token;
    curl_setopt_array($request, [CURLOPT_HTTPHEADER => $headers, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    $body = curl_exec($request);
    $status = curl_getinfo($request, CURLINFO_RESPONSE_CODE);
    $error = curl_error($request);
    curl_close($request);
    if (!is_string($body)) throw new RuntimeException('Local API test failed: ' . $error);
    return [$status, json_decode($body, true, 32, JSON_THROW_ON_ERROR)];
}

function id_image_request(int $farmerId, string $side, ?string $sessionId): array
{
    $url = agrireach_test_base_url() . '/CAPSTONE%20AGRICULTURE/admin/farmer-id-image.php?farmer_id=' . $farmerId . '&side=' . $side;
    $request = curl_init($url);
    if ($request === false) throw new RuntimeException('Image HTTP test could not start.');
    curl_setopt_array($request, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 10,
    ]);
    if ($sessionId !== null) curl_setopt($request, CURLOPT_COOKIE, session_name() . '=' . $sessionId);
    $bytes = curl_exec($request);
    $status = curl_getinfo($request, CURLINFO_RESPONSE_CODE);
    $mime = curl_getinfo($request, CURLINFO_CONTENT_TYPE);
    curl_close($request);
    return [$status, $mime, is_string($bytes) ? $bytes : ''];
}

function check(bool $condition, string $label): void
{
    if (!$condition) throw new RuntimeException($label . ' failed.');
    echo 'PASS ' . $label . PHP_EOL;
}

$connection = db();
ob_start();
$marker = 'qa-visibility-' . bin2hex(random_bytes(5));
$password = bin2hex(random_bytes(12)) . 'Aa1';
$created = [];
$testAdminSessionId = bin2hex(random_bytes(16));
session_id($testAdminSessionId);
session_start();
$_SESSION['admin_id'] = 1;
$_SESSION['admin_role'] = 'admin';
session_write_close();
try {
    $baseline = user_count(render_users());
    $adminBaseline = user_count(render_users(['role' => 'Administrator']));
    check($adminBaseline > 0, 'non-farmer admin remains listed');

    $cases = [
        'pending' => ['Pending', 'Active'],
        'approved' => ['Approved', 'Active'],
        'rejected' => ['Rejected', 'Active'],
        'reject_candidate' => ['Pending', 'Active'],
        'blocked' => ['Approved', 'Blocked'],
    ];
    foreach ($cases as $key => [$verification, $account]) {
        $email = $marker . '-' . $key . '@agrireach.test';
        $contact = '+639' . random_int(100000000, 999999999);
        $name = $marker . ' ' . $key;
        $address = 'Controlled test address';
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $statement = $connection->prepare('INSERT INTO farmers (full_name, contact_number, email, address, password_hash, account_status, verification_status) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $statement->execute([$name, $contact, $email, $address, $hash, $account, $verification]);
        $created[$key] = ['id' => (int) $connection->lastInsertId(), 'email' => $email];
    }

    $html = render_users(['search' => $marker]);
    check(user_count($html) === 2 && str_contains($html, $marker . ' approved') && str_contains($html, $marker . ' blocked') && !str_contains($html, $marker . ' pending') && !str_contains($html, $marker . ' rejected'), 'Manage Users search includes approved farmers only');
    check(user_count(render_users()) === $baseline + 2, 'Manage Users total count excludes undecided farmers');
    check(user_count(render_users(['search' => $marker, 'role' => 'Farmer', 'account_status' => 'Active'])) === 1, 'active farmer filter');
    check(user_count(render_users(['search' => $marker, 'role' => 'Farmer', 'account_status' => 'Blocked'])) === 1, 'blocked farmer filter');
    check(user_count(render_users(['role' => 'Administrator'])) === $adminBaseline, 'admin list unaffected');

    foreach (['pending' => 'pending', 'rejected' => 'rejected', 'blocked' => 'blocked'] as $key => $expected) {
        [$status, $body] = api_request('auth/login.php', ['email' => $created[$key]['email'], 'password' => $password]);
        check($status === 403 && ($body['status'] ?? null) === $expected && empty($body['token']), $key . ' login denied without a session');
    }
    [$approvedStatus, $approvedBody] = api_request('auth/login.php', ['email' => $created['approved']['email'], 'password' => $password]);
    $approvedToken = (string) ($approvedBody['token'] ?? '');
    check($approvedStatus === 200 && $approvedToken !== '', 'approved active login');
    [$meStatus] = api_request('auth/me.php', null, $approvedToken);
    check($meStatus === 200, 'approved active API access');

    foreach (['pending', 'rejected', 'blocked'] as $key) {
        $token = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $token);
        $farmerId = $created[$key]['id'];
        $statement = $connection->prepare('INSERT INTO farmer_sessions (farmer_id, token_hash, expires_at) VALUES (?, ?, ' . db_sql_utc_now($connection) . " + INTERVAL '1' DAY)");
        $statement->execute([$farmerId, $tokenHash]);
        [$status] = api_request('auth/me.php', null, $token);
        check($status === 401, $key . ' API token rejected');
    }

    $blockedId = $created['approved']['id'];
    $update = $connection->prepare("UPDATE farmers SET account_status = 'Blocked' WHERE id = ? AND verification_status = 'Approved'");
    $update->execute([$blockedId]);
    [$status] = api_request('auth/me.php', null, $approvedToken);
    check($status === 401, 'approved farmer blocked after login loses API access');
    check(user_count(render_users(['search' => $marker, 'account_status' => 'Blocked'])) === 2, 'approved blocked farmer remains manageable');

    $admin = $connection->query('SELECT id FROM admins ORDER BY id LIMIT 1')->fetch();
    if (!$admin) throw new RuntimeException('A local admin is required for verification tests.');
    $adminId = (int) $admin['id'];
    $approval = change_farmer_verification($connection, $created['pending']['id'], $adminId, 'approve');
    check($approval['changed'] === true && user_count(render_users(['search' => $marker])) === 3, 'approval moves pending farmer into Manage Users');
    $rejection = change_farmer_verification($connection, $created['reject_candidate']['id'], $adminId, 'reject', 'Controlled test rejection');
    check($rejection['changed'] === true && user_count(render_users(['search' => $marker])) === 3, 'rejection keeps farmer out of Manage Users');
    [$rejectedStatus, $rejectedBody] = api_request('auth/login.php', ['email' => $created['reject_candidate']['email'], 'password' => $password]);
    check($rejectedStatus === 403 && ($rejectedBody['status'] ?? null) === 'rejected', 'newly rejected farmer login denied');

    $images = $connection->query("SELECT farmer_id FROM farmer_ids WHERE (front_image_path IS NOT NULL OR image_path IS NOT NULL) AND back_image_path IS NOT NULL ORDER BY id DESC LIMIT 1")->fetch();
    if ($images) {
        [$unauthorizedStatus] = id_image_request((int) $images['farmer_id'], 'front', null);
        check($unauthorizedStatus === 302, 'ID endpoint redirects unauthenticated requests');
        foreach (['front', 'back'] as $side) {
            [$imageStatus, $mime, $bytes] = id_image_request((int) $images['farmer_id'], $side, $testAdminSessionId);
            check($imageStatus === 200 && in_array($mime, ['image/jpeg', 'image/png'], true) && (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes) === $mime, 'protected ID ' . $side . ' endpoint streams an image');
        }
    } else {
        echo 'SKIP protected ID image bytes: no existing farmer has both sides stored.' . PHP_EOL;
    }
} finally {
    session_id($testAdminSessionId);
    session_start();
    $_SESSION = [];
    session_destroy();
    check(!is_file(rtrim(session_save_path(), '/\\') . DIRECTORY_SEPARATOR . 'sess_' . $testAdminSessionId), 'temporary admin test session removed');
    foreach ($created as $fixture) {
        $delete = $connection->prepare('DELETE FROM farmers WHERE id = ? AND email = ?');
        $delete->execute([$fixture['id'], $fixture['email']]);
    }
    $like = $marker . '%';
    $remaining = $connection->prepare('SELECT COUNT(*) AS total FROM farmers WHERE email LIKE ?');
    $remaining->execute([$like]);
    check((int) $remaining->fetch()['total'] === 0, 'controlled farmer fixtures cleaned up');
}
