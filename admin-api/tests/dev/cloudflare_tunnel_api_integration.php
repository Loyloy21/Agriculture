<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../../backend/config/database.php';

function tunnel_expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

function tunnel_request(string $url, string $method = 'GET', ?string $token = null, array|string|null $body = null, array $extraHeaders = []): array
{
    $headers = array_merge(['Accept: application/json'], $extraHeaders);
    if ($token !== null) $headers[] = 'Authorization: Bearer ' . $token;
    $curl = curl_init($url);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 40, CURLOPT_HTTPHEADER => $headers, CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]);
    // Fresh DNS answers recorded by the launcher, never a hardcoded service IP.
    // Keep the public hostname, SNI and certificate verification even on broken router DNS.
    $host = (string) parse_url($url, PHP_URL_HOST);
    if (!empty($GLOBALS['tunnelAddresses'])) {
        curl_setopt($curl, CURLOPT_RESOLVE, [$host . ':443:' . implode(',', $GLOBALS['tunnelAddresses'])]);
    }
    if ($body !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
    $responseBody = curl_exec($curl); $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE); $contentType = (string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE); $error = curl_error($curl); curl_close($curl);
    if (!is_string($responseBody)) throw new RuntimeException('Public tunnel request failed for ' . (parse_url($url, PHP_URL_PATH) ?: 'unknown path') . ': ' . $error);
    return ['status' => $status, 'body' => $responseBody, 'contentType' => $contentType];
}

$session = json_decode((string) @file_get_contents(__DIR__ . '/../../var/cloudflare-tunnel/processes.json'), true);
$apiBase = rtrim((string) (getenv('AGRIREACH_PUBLIC_API_URL') ?: ($session['publicApiUrl'] ?? '')), '/');
if (!$apiBase || $apiBase !== ($session['publicApiUrl'] ?? null) || empty($session['verifiedAt'])) {
    fwrite(STDERR, "FAIL: Start and verify the current managed tunnel before testing.\n"); exit(1);
}
$tunnelAddresses = array_values(array_filter($session['publicAddresses'] ?? [], static fn($ip) => is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)));
$authOnly = in_array('--auth-only', $argv, true);
if (!preg_match('#^https://[a-z0-9-]+\.trycloudflare\.com/CAPSTONE%20AGRICULTURE/api$#', $apiBase)) {
    fwrite(STDERR, "FAIL: AGRIREACH_PUBLIC_API_URL must be the active HTTPS Quick Tunnel API URL.\n"); exit(1);
}

$connection = db(); $farmerId = null; $storedImage = null; $temporaryImage = tempnam(sys_get_temp_dir(), 'agrireach-tunnel-test-');
try {
    file_put_contents($temporaryImage, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true));
    $suffix = bin2hex(random_bytes(5)); $email = "tunnel-test-{$suffix}@example.test"; $phone = '0994' . str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT); $password = bin2hex(random_bytes(12)) . 'Aa1'; $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $insertFarmer = $connection->prepare("INSERT INTO farmers (full_name, first_name, last_name, contact_number, email, address, password_hash, account_status, verification_status) VALUES ('Controlled Tunnel Farmer', 'Controlled', 'Farmer', ?, ?, 'Controlled tunnel address', ?, 'Active', 'Approved')");
    $insertFarmer->execute([$phone, $email, $passwordHash]); $farmerId = (int) $connection->lastInsertId();
    $insertFarm = $connection->prepare("INSERT INTO farm_profiles (farmer_id, farm_name, farm_type, agricultural_category, land_area, farm_address, city_psgc_code, city_name, province_name, barangay_psgc_code, barangay_name, location_review_status, status, is_primary) VALUES (?, 'Controlled Tunnel Farm', 'Crops', 'Crops', 1.5, 'Tolosa controlled landmark', '1600203000', 'Cabadbaran City', 'Agusan del Norte', '1600203040', 'Tolosa', 'Verified', 'Active', 1)");
    $insertFarm->execute([$farmerId]); $farmId = (int) $connection->lastInsertId();

    $unauthenticated = tunnel_request($apiBase . '/auth/me.php');
    tunnel_expect($unauthenticated['status'] === 401, 'Unauthenticated auth/me did not return HTTP 401.');
    $login = tunnel_request($apiBase . '/auth/login.php', 'POST', null, json_encode(['email' => $email, 'password' => $password], JSON_THROW_ON_ERROR), ['Content-Type: application/json']);
    $loginPayload = json_decode($login['body'], true);
    tunnel_expect($login['status'] === 200 && is_array($loginPayload) && ($loginPayload['status'] ?? '') === 'authenticated' && !empty($loginPayload['token']), 'Public login failed.');
    $token = (string) $loginPayload['token'];

    $me = tunnel_request($apiBase . '/auth/me.php', 'GET', $token); $mePayload = json_decode($me['body'], true);
    tunnel_expect($me['status'] === 200 && (int) ($mePayload['farmer']['id'] ?? 0) === $farmerId, 'Bearer token did not reach auth/me through the tunnel.');
    $farms = tunnel_request($apiBase . '/farms/index.php', 'GET', $token); $farmPayload = json_decode($farms['body'], true);
    tunnel_expect($farms['status'] === 200 && count($farmPayload['data']['farms'] ?? []) === 1, 'Authenticated farm list failed through the tunnel.');

    if (!$authOnly) {
    $submissionId = 'tunnel_' . bin2hex(random_bytes(8));
    $report = tunnel_request($apiBase . '/reports/index.php', 'POST', $token, [
        'farm_profile_id' => (string) $farmId, 'farm_type' => 'Crops', 'damage_type' => 'Flood', 'custom_damage_type' => '',
        'damage_description' => 'Controlled public tunnel multipart test.', 'estimated_damage_cost' => '100',
        'submitted_address' => 'Tolosa controlled damage point', 'submitted_latitude' => '9.1200', 'submitted_longitude' => '125.5300',
        'location_source' => 'manual', 'location_confirmed' => '1',
        'client_submission_id' => $submissionId, 'photos[]' => new CURLFile($temporaryImage, 'image/png', 'controlled.png'),
        'photo_captured_at[0]' => '', 'photo_latitude[0]' => '', 'photo_longitude[0]' => '',
    ]);
    $reportPayload = json_decode($report['body'], true);
    tunnel_expect($report['status'] === 201 && !empty($reportPayload['data']['id']), 'Multipart damage report failed through the tunnel: ' . ($reportPayload['message'] ?? 'unknown response'));
    $reportCode = (string) $reportPayload['data']['id'];
    $imageStatement = $connection->prepare('SELECT ri.id, ri.image_path FROM report_images ri JOIN damage_reports dr ON dr.id = ri.report_id WHERE dr.report_code = ? AND dr.farmer_id = ? LIMIT 1');
    $imageStatement->execute([$reportCode, $farmerId]); $imageRow = $imageStatement->fetch();
    tunnel_expect((int) ($imageRow['id'] ?? 0) > 0, 'Uploaded report image record was not saved.');
    $storedImage = dirname(__DIR__, 2) . '/storage/report-images/' . basename((string) $imageRow['image_path']);
    $image = tunnel_request($apiBase . '/reports/image.php?id=' . (int) $imageRow['id'], 'GET', $token);
    tunnel_expect($image['status'] === 200 && str_starts_with($image['contentType'], 'image/png') && strlen($image['body']) > 0, 'Authenticated image retrieval failed through the tunnel.');
    }
    $logout = tunnel_request($apiBase . '/auth/logout.php', 'POST', $token);
    tunnel_expect($logout['status'] === 200, 'Public logout failed.');
    echo $authOnly
        ? "PASS: PUBLIC HTTPS auth/me 401, login 200, bearer auth/me 200 (correct farmer), farm list 200, logout 200. No report was created.\n"
        : "PASS: Quick Tunnel login, bearer auth/me, farm list, multipart report, image retrieval, and logout\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . PHP_EOL); $exitCode = 1;
} finally {
    if ($farmerId) { $delete = $connection->prepare('DELETE FROM farmers WHERE id = ?'); $delete->execute([$farmerId]); }
    if (is_string($storedImage) && is_file($storedImage)) @unlink($storedImage);
    if (is_string($temporaryImage) && is_file($temporaryImage)) @unlink($temporaryImage);
}
exit($exitCode ?? 0);
