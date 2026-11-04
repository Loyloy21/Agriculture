<?php
declare(strict_types=1);

/**
 * Farmer/admin access control through the real HTTP endpoints (local test server + DISPOSABLE database only).
 *
 * Data path: Expo app -> PHP API -> database (no direct Supabase client; PHP's database role bypasses RLS, so every
 * ownership rule must hold in PHP). Two farmers submit Damage and Production reports through the API; then each
 * protected operation is tried as: the owner, the other farmer (guessed IDs/codes, foreign farm IDs, foreign
 * farmer_id in the body), a farmer token on admin pages, no credentials, the active admin, an admin replaced by
 * another, a disabled admin, and a session cookie from another application on the same host.
 * Success cases are checked too, so legitimate access keeps working. Never sends email.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/support/test_base_url.php';
require_once dirname(__DIR__) . '/support/admin_test_account.php';

$checks = 0;
$matrix = [];
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
    echo "  ok  {$message}\n";
}
function base(string $area): string
{
    $root = agrireach_test_base_url() . '/CAPSTONE%20AGRICULTURE';
    if (!in_array(parse_url($root, PHP_URL_HOST), ['127.0.0.1', 'localhost'], true)) throw new RuntimeException('Integration tests require a loopback URL.');
    return $root . '/' . $area;
}
/** @return array{status:int,body:string,type:string,location:string} */
function http(string $method, string $url, array $options = []): array
{
    $handle = curl_init($url);
    $headers = ['Accept: application/json, text/html'];
    if (isset($options['token'])) $headers[] = 'Authorization: Bearer ' . $options['token'];
    if (isset($options['cookie'])) $headers[] = 'Cookie: ' . $options['cookie'];
    $body = $options['json'] ?? $options['form'] ?? $options['multipart'] ?? null;
    if (isset($options['json'])) { $headers[] = 'Content-Type: application/json'; $body = json_encode($body); }
    elseif (isset($options['form'])) $body = http_build_query($body);
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 90, CURLOPT_HEADER => true, CURLOPT_FOLLOWLOCATION => false]);
    if ($body !== null) curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
    if (isset($options['jar'])) curl_setopt_array($handle, [CURLOPT_COOKIEFILE => $options['jar'], CURLOPT_COOKIEJAR => $options['jar']]);
    $raw = curl_exec($handle);
    if ($raw === false) throw new RuntimeException('Local test HTTP connection failed (no credentials logged).');
    $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    $result = ['status' => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE), 'body' => substr($raw, $headerSize), 'type' => (string) curl_getinfo($handle, CURLINFO_CONTENT_TYPE), 'location' => (string) curl_getinfo($handle, CURLINFO_REDIRECT_URL)];
    curl_close($handle);
    return $result;
}
function json_of(array $response): array { return json_decode($response['body'], true) ?? []; }
/** An admin endpoint refused this request: a redirect to the login page (pages) or 401 (data endpoints), with no report data. */
function admin_refused(array $response, array $mustNotContain): bool
{
    $redirected = $response['status'] === 302 && str_contains($response['location'], 'login.php');
    foreach ($mustNotContain as $secret) if ($secret !== '' && str_contains($response['body'], $secret)) return false;
    return $redirected || $response['status'] === 401;
}
function record_matrix(string $actor, string $target, string $expected, bool $ok): void
{
    global $matrix;
    $matrix[] = [$actor, $target, $expected, $ok ? 'PASS' : 'FAIL'];
    check($ok, "{$actor} -> {$target}: {$expected}");
}

$connection = db();
test_admin_refuse_live();
$stamp = bin2hex(random_bytes(4));
$image = tempnam(sys_get_temp_dir(), 'acl') . '.png';
$jars = [];
$created = ['farmers' => [], 'admins' => []];
$takeovers = [];
$exitCode = 0;
try {
    file_put_contents($image, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a3ioAAAAASUVORK5CYII='));
    $commodity = (int) $connection->query("SELECT c.id FROM commodities c JOIN commodity_units cu ON cu.commodity_id = c.id WHERE c.agricultural_category = 'Crops' AND cu.unit_code = 'kg' AND c.status = 'Active' ORDER BY c.id LIMIT 1")->fetchColumn();
    $farmers = [];
    foreach (['a' => '31-41-59-265-' . substr((string) hexdec($stamp), -6), 'b' => '27-18-28-182-' . substr((string) (hexdec($stamp) + 7), -6)] as $name => $reference) {
        $connection->prepare("INSERT INTO farmers (full_name, contact_number, email, address, account_status, verification_status) VALUES (?, ?, ?, 'Controlled test address', 'Active', 'Approved')")
            ->execute(['Controlled ACL Farmer ' . strtoupper($name) . ' ' . $stamp, '+639' . str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT), "acl-{$stamp}-{$name}@example.test"]);
        $id = db_insert_id($connection, 'farmers');
        $created['farmers'][] = $id;
        $connection->prepare("INSERT INTO farmer_ids (farmer_id, id_type, id_number) VALUES (?, 'RSBSA ID', ?)")->execute([$id, $reference]);
        $connection->prepare("INSERT INTO farm_profiles (farmer_id, farm_name, farm_type, agricultural_category, farm_address, city_psgc_code, city_name, province_name, barangay_psgc_code, barangay_name, location_review_status, latitude, longitude, status) VALUES (?, 'Controlled ACL farm', 'Crops', 'Crops', 'Tolosa, Cabadbaran City', '1600203000', 'Cabadbaran City', 'Agusan del Norte', '1600203040', 'Tolosa', 'Verified', 9.12, 125.53, 'Active')")->execute([$id]);
        $farm = db_insert_id($connection, 'farm_profiles');
        $token = bin2hex(random_bytes(32));
        $connection->prepare('INSERT INTO farmer_sessions (farmer_id, token_hash, expires_at) VALUES (?, ?, ' . db_sql_utc_now($connection) . " + INTERVAL '1' HOUR)")->execute([$id, hash('sha256', $token)]);
        $farmers[$name] = ['id' => $id, 'farm' => $farm, 'token' => $token];
    }
    $submitDamage = static fn(string $name) => http('POST', base('api/reports/index.php'), ['token' => $farmers[$name]['token'], 'multipart' => [
        'farm_profile_id' => (string) $farmers[$name]['farm'], 'farm_type' => 'Crops', 'damage_description' => 'Controlled ACL report.', 'submitted_address' => 'Tolosa, Cabadbaran City',
        'submitted_latitude' => '9.1172482', 'submitted_longitude' => '125.5296631', 'client_submission_id' => 'acl_' . bin2hex(random_bytes(10)), 'location_confirmed' => '1', 'location_source' => 'photo',
        'location_photo_order' => '1', 'photos[0]' => new CURLFile($image, 'image/png', 'acl.png'), 'photo_latitude[0]' => '9.1172482', 'photo_longitude[0]' => '125.5296631', 'photo_gps_source[0]' => 'photo_exif',
        'photo_gps_accuracy[0]' => '', 'photo_captured_at[0]' => '', 'photo_capture_time_source[0]' => 'none', 'photo_location_timestamp[0]' => '', 'photo_location_status[0]' => 'available',
        'damage_details_version' => '1', 'subject_type' => 'Rice', 'suspected_cause' => 'Flooding', 'crop_damage_percent' => '20', 'planted_area_ha' => '1', 'affected_area_ha' => '0.2']]);
    $production = static fn(string $name, int $farmId, array $extra = []) => http('POST', base('api/production/index.php'), ['token' => $farmers[$name]['token'], 'json' => array_merge([
        'farmId' => $farmId, 'category' => 'Crops', 'commodityId' => $commodity, 'harvestDate' => date('Y-m-d'), 'quantity' => '120', 'unitCode' => 'kg', 'remarks' => '',
        'clientSubmissionId' => 'acl_' . bin2hex(random_bytes(10)), 'confirmDuplicate' => true], $extra)]);
    $reports = [];
    foreach (['a', 'b'] as $name) {
        $damage = $submitDamage($name);
        $made = $production($name, $farmers[$name]['farm']);
        check($damage['status'] === 201 && $made['status'] === 201, "setup: farmer {$name} submitted a damage and a production report through the API");
        $code = (string) json_of($damage)['data']['id'];
        $statement = $connection->prepare('SELECT ri.id FROM report_images ri JOIN damage_reports dr ON dr.id = ri.report_id WHERE dr.report_code = ? LIMIT 1');
        $statement->execute([$code]);
        $reports[$name] = ['code' => $code, 'image' => (int) $statement->fetchColumn(), 'production' => (int) json_of($made)['data']['record']['id'], 'productionCode' => (string) json_of($made)['data']['record']['reportCode']];
    }
    $statement = $connection->prepare('SELECT id FROM notifications WHERE farmer_id = ? ORDER BY id DESC LIMIT 1');
    $statement->execute([$farmers['b']['id']]);
    $bNotification = (int) $statement->fetchColumn();
    [$a, $b] = [$farmers['a'], $farmers['b']];
    [$ra, $rb] = [$reports['a'], $reports['b']];
    $bSecrets = [$rb['code'], $rb['productionCode']];

    // ---------- Farmer A: own records work ----------
    $list = http('GET', base('api/reports/index.php'), ['token' => $a['token']]);
    $codes = array_column(json_of($list)['data'] ?? [], 'id');
    record_matrix('Farmer A', 'own damage list', '200, only A', $list['status'] === 200 && in_array($ra['code'], $codes, true) && !in_array($rb['code'], $codes, true));
    record_matrix('Farmer A', 'own damage report by code', '200', http('GET', base('api/reports/index.php?id=' . rawurlencode($ra['code'])), ['token' => $a['token']])['status'] === 200);
    $photo = http('GET', base('api/reports/image.php?id=' . $ra['image']), ['token' => $a['token']]);
    record_matrix('Farmer A', 'own report photo', '200 image', $photo['status'] === 200 && str_starts_with($photo['type'], 'image/'));
    $own = http('GET', base('api/production/index.php?id=' . $ra['production']), ['token' => $a['token']]);
    record_matrix('Farmer A', 'own production report', '200', $own['status'] === 200 && json_of($own)['data']['reportCode'] === $ra['productionCode']);

    // ---------- Farmer A: Farmer B's records are refused ----------
    record_matrix('Farmer A', "B's damage report by code (guessed)", '404', http('GET', base('api/reports/index.php?id=' . rawurlencode($rb['code'])), ['token' => $a['token']])['status'] === 404);
    record_matrix('Farmer A', "B's damage report via show.php", '404', http('GET', base('api/reports/show.php?id=' . rawurlencode($rb['code'])), ['token' => $a['token']])['status'] === 404);
    record_matrix('Farmer A', "B's report photo (guessed image id)", '404', http('GET', base('api/reports/image.php?id=' . $rb['image']), ['token' => $a['token']])['status'] === 404);
    record_matrix('Farmer A', "B's production report (guessed id)", '404', http('GET', base('api/production/index.php?id=' . $rb['production']), ['token' => $a['token']])['status'] === 404);
    foreach (['summary' => '', 'month' => '&month=' . date('Y-m'), 'year' => '&year=' . date('Y')] as $view => $param) {
        $response = http('GET', base('api/production/index.php?view=' . $view . $param . '&farmer_id=' . $b['id']), ['token' => $a['token']]);
        record_matrix('Farmer A', "production {$view} with farmer_id=B in the URL", "only A's data", $response['status'] === 200 && !str_contains($response['body'], $rb['productionCode']));
    }
    $count = static function (string $table, int $farmerId) use ($connection): int { $s = $connection->prepare("SELECT COUNT(*) FROM {$table} WHERE farmer_id = ?"); $s->execute([$farmerId]); return (int) $s->fetchColumn(); };
    [$bDamage, $bProduction] = [$count('damage_reports', $b['id']), $count('production_records', $b['id'])];
    $foreign = $production('a', $b['farm']);
    record_matrix('Farmer A', "create production on B's farm", '422 farmId, nothing saved for B', $foreign['status'] === 422 && isset(json_of($foreign)['errors']['farmId']) && $count('production_records', $b['id']) === $bProduction);
    $smuggled = $production('a', $a['farm'], ['farmerId' => $b['id'], 'farmer_id' => $b['id']]);
    record_matrix('Farmer A', 'create production with farmer_id=B in the body', "saved as A's own", $smuggled['status'] === 201 && $count('production_records', $b['id']) === $bProduction);
    $foreignDamage = http('POST', base('api/reports/index.php'), ['token' => $a['token'], 'multipart' => ['farm_profile_id' => (string) $b['farm'], 'farm_type' => 'Crops', 'farmer_id' => (string) $b['id'], 'client_submission_id' => 'acl_' . bin2hex(random_bytes(10))]]);
    record_matrix('Farmer A', "create damage report on B's farm", '4xx, nothing saved for B', $foreignDamage['status'] >= 400 && $foreignDamage['status'] < 500 && $count('damage_reports', $b['id']) === $bDamage);
    $archive = http('POST', base('api/farms/index.php'), ['token' => $a['token'], 'json' => ['action' => 'archive', 'farmId' => $b['farm']]]);
    $statement = $connection->prepare('SELECT status FROM farm_profiles WHERE id = ?');
    $statement->execute([$b['farm']]);
    record_matrix('Farmer A', "archive B's farm", '4xx, B farm unchanged', $archive['status'] >= 400 && $statement->fetchColumn() === 'Active');
    $update = http('POST', base('api/farms/index.php'), ['token' => $a['token'], 'json' => ['action' => 'update', 'farmId' => $b['farm'], 'name' => 'Hijacked farm']]);
    $statement = $connection->prepare('SELECT farm_name FROM farm_profiles WHERE id = ?');
    $statement->execute([$b['farm']]);
    record_matrix('Farmer A', "edit B's farm", '4xx, B farm unchanged', $update['status'] >= 400 && $statement->fetchColumn() === 'Controlled ACL farm');
    if ($bNotification > 0) {
        $read = http('POST', base('api/notifications/index.php'), ['token' => $a['token'], 'json' => ['action' => 'mark_read', 'notificationId' => $bNotification]]);
        $statement = $connection->prepare('SELECT is_read FROM notifications WHERE id = ?');
        $statement->execute([$bNotification]);
        record_matrix('Farmer A', "mark B's notification read", '404, unchanged', $read['status'] === 404 && (int) $statement->fetchColumn() === 0);
    }
    $notes = http('GET', base('api/notifications/index.php'), ['token' => $a['token']]);
    record_matrix('Farmer A', 'notification list', "no B report codes", $notes['status'] === 200 && !str_contains($notes['body'], $rb['code']));

    // ---------- A farmer token on admin endpoints ----------
    foreach (['dashboard.php', 'damage-reports.php', 'production-report.php', 'production-report-export.php', 'report-image.php?id=' . $rb['image'], 'farmer-id-image.php?farmer_id=' . $b['id'] . '&side=front',
              'damage-reports-pdf.php?mode=list', 'damage-report-detail-data.php?index=0', 'production-report-data.php?lookup=farmers&q=a', 'manage-users.php', 'settings.php'] as $page) {
        record_matrix('Farmer token', "admin/{$page}", 'refused (login/401), no data', admin_refused(http('GET', base('admin/' . $page), ['token' => $a['token']]), $bSecrets));
    }

    // ---------- No credentials ----------
    foreach (['reports/index.php', 'reports/index.php?id=' . rawurlencode($rb['code']), 'reports/image.php?id=' . $rb['image'], 'production/index.php', 'production/index.php?id=' . $rb['production'],
              'farms/index.php', 'notifications/index.php', 'farmers/id-image.php?side=front', 'auth/me.php', 'weather/advisories.php'] as $route) {
        $response = http('GET', base('api/' . $route));
        record_matrix('Unauthenticated', "api/{$route}", '401, no data', $response['status'] === 401 && !str_contains($response['body'], $rb['code']) && !str_contains($response['body'], 'Controlled ACL'));
    }
    foreach (['farmers/profile.php', 'production/index.php', 'farms/index.php', 'notifications/index.php'] as $route) {
        $response = http('POST', base('api/' . $route), ['json' => ['action' => 'archive', 'farmId' => $b['farm']]]);
        record_matrix('Unauthenticated', "POST api/{$route}", '401, nothing changed', $response['status'] === 401);
    }
    foreach (['dashboard.php', 'production-report-export.php', 'report-image.php?id=' . $rb['image'], 'damage-reports-pdf.php?mode=list', 'production-report-data.php?lookup=farmers&q=a'] as $page) {
        record_matrix('Unauthenticated', "admin/{$page}", 'refused (login/401)', admin_refused(http('GET', base('admin/' . $page)), $bSecrets));
    }

    // ---------- A session cookie belonging to another application on this host ----------
    session_save_path(ini_get('session.save_path') ?: sys_get_temp_dir());
    $foreignId = 'acl' . bin2hex(random_bytes(12));
    session_id($foreignId);
    session_start();
    $_SESSION = ['admin_id' => (int) $connection->query("SELECT id FROM admins WHERE status = 'active' ORDER BY id LIMIT 1")->fetchColumn(), 'admin_role' => 'admin'];
    session_write_close();
    record_matrix('Other app session', 'admin/dashboard.php with PHPSESSID holding admin_id', 'refused (own cookie name)', admin_refused(http('GET', base('admin/dashboard.php'), ['cookie' => 'PHPSESSID=' . $foreignId]), $bSecrets));

    // ---------- Active admin ----------
    $login = static function (string $username, string $password) use (&$jars): string {
        $jar = tempnam(sys_get_temp_dir(), 'acljar');
        $jars[] = $jar;
        $response = http('POST', base('admin/login.php'), ['form' => ['username' => $username, 'password' => $password], 'jar' => $jar]);
        if ($response['status'] !== 302 || !str_contains($response['location'], 'dashboard.php')) throw new RuntimeException('Controlled admin login failed (HTTP ' . $response['status'] . ').');
        return $jar;
    };
    $passwordX = 'Acl-' . bin2hex(random_bytes(8));
    $takeovers[] = $x = test_admin_takeover($connection, 'Controlled ACL Admin X', 'aclx' . $stamp, password_hash($passwordX, PASSWORD_DEFAULT));
    $created['admins'][] = $x['id'];
    $jarX = $login('aclx' . $stamp, $passwordX);
    check(str_contains((string) file_get_contents($jarX), "AGRIREACH_ADMIN") && str_contains((string) file_get_contents($jarX), '#HttpOnly_'), 'admin session cookie is AGRIREACH_ADMIN and HttpOnly');
    $dashboard = http('GET', base('admin/dashboard.php'), ['jar' => $jarX]);
    record_matrix('Active admin', 'admin/dashboard.php', '200 with both farmers\' reports', $dashboard['status'] === 200 && str_contains($dashboard['body'], $ra['code']) && str_contains($dashboard['body'], $rb['code']));
    $bPhoto = http('GET', base('admin/report-image.php?id=' . $rb['image']), ['jar' => $jarX]);
    record_matrix('Active admin', "any farmer's report photo", '200 image', $bPhoto['status'] === 200 && str_starts_with($bPhoto['type'], 'image/'));
    $export = http('GET', base('admin/production-report-export.php?period=current_year&status=all'), ['jar' => $jarX]);
    record_matrix('Active admin', 'production CSV export', '200 CSV with both farmers', $export['status'] === 200 && str_contains($export['type'], 'csv') && str_contains($export['body'], $ra['productionCode']) && str_contains($export['body'], $rb['productionCode']));
    $lookup = http('GET', base('admin/production-report-data.php?lookup=farmers&q=Controlled%20ACL'), ['jar' => $jarX]);
    record_matrix('Active admin', 'admin data endpoint (farmer lookup)', '200 JSON', $lookup['status'] === 200 && (json_of($lookup)['success'] ?? false) === true);
    $statement = $connection->prepare('SELECT id FROM damage_reports WHERE report_code = ?');
    $statement->execute([$rb['code']]);
    $pdf = http('GET', base('admin/damage-reports-pdf.php?mode=single&report_id=' . (int) $statement->fetchColumn()), ['jar' => $jarX]);
    record_matrix('Active admin', 'single damage report PDF', '200 PDF', $pdf['status'] === 200 && str_contains($pdf['type'], 'pdf'));

    // ---------- Replaced admin: X's open session ends on the next request ----------
    $passwordY = 'Acl-' . bin2hex(random_bytes(8));
    $takeovers[] = $y = test_admin_takeover($connection, 'Controlled ACL Admin Y', 'acly' . $stamp, password_hash($passwordY, PASSWORD_DEFAULT));
    $created['admins'][] = $y['id'];
    $after = http('GET', base('admin/dashboard.php'), ['jar' => $jarX]);
    record_matrix('Replaced admin', 'admin/dashboard.php with the old session', 'refused (login?ended=1)', admin_refused($after, $bSecrets) && str_contains($after['location'], 'ended=1'));
    foreach (['production-report-export.php', 'report-image.php?id=' . $rb['image'], 'production-report-data.php?lookup=farmers&q=a', 'settings.php'] as $page) {
        record_matrix('Replaced admin', "admin/{$page}", 'refused', admin_refused(http('GET', base('admin/' . $page), ['jar' => $jarX]), $bSecrets));
    }
    $connection->prepare("UPDATE admins SET status = 'inactive' WHERE id = ?")->execute([$y['id']]);
    $connection->prepare("UPDATE admins SET status = 'active' WHERE id = ?")->execute([$x['id']]);
    record_matrix('Replaced admin', 'old session after being made active again', 'still refused (session was ended)', admin_refused(http('GET', base('admin/dashboard.php'), ['jar' => $jarX]), $bSecrets));
    $connection->prepare("UPDATE admins SET status = 'inactive' WHERE id = ?")->execute([$x['id']]);
    $connection->prepare("UPDATE admins SET status = 'active' WHERE id = ?")->execute([$y['id']]);
    record_matrix('Replaced admin', 'login with the old password', 'refused', http('POST', base('admin/login.php'), ['form' => ['username' => 'aclx' . $stamp, 'password' => $passwordX]])['status'] === 200);

    // ---------- Disabled admin ----------
    $jarY = $login('acly' . $stamp, $passwordY);
    check(http('GET', base('admin/dashboard.php'), ['jar' => $jarY])['status'] === 200, 'setup: admin Y signed in and sees the dashboard');
    $connection->prepare("UPDATE admins SET account_status = 'Inactive' WHERE id = ?")->execute([$y['id']]);
    record_matrix('Disabled admin', 'admin/dashboard.php with an open session', 'refused', admin_refused(http('GET', base('admin/dashboard.php'), ['jar' => $jarY]), $bSecrets));
    $connection->prepare("UPDATE admins SET account_status = 'Active' WHERE id = ?")->execute([$y['id']]);

    // ---------- A blocked farmer's token stops working ----------
    $connection->prepare("UPDATE farmers SET account_status = 'Blocked' WHERE id = ?")->execute([$b['id']]);
    record_matrix('Blocked farmer', 'own report list with an old token', '401', http('GET', base('api/reports/index.php'), ['token' => $b['token']])['status'] === 401);

    echo "\nACCESS MATRIX\n";
    foreach ($matrix as $row) printf("  %-18s %-58s %-36s %s\n", ...$row);
    echo "PASS: farmer/admin access control ({$checks} checks)\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . PHP_EOL);
    $exitCode = 1;
} finally {
    @unlink($image);
    foreach ($jars as $jar) @unlink($jar);
    foreach ($created['farmers'] as $farmerId) {
        $statement = $connection->prepare('SELECT ri.image_path FROM report_images ri JOIN damage_reports dr ON dr.id = ri.report_id WHERE dr.farmer_id = ?');
        $statement->execute([$farmerId]);
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $path) {
            $root = realpath(dirname(__DIR__, 2) . '/storage/report-images');
            $file = realpath(dirname(__DIR__, 2) . '/storage/report-images/' . basename((string) $path));
            if ($root && $file && str_starts_with($file, $root . DIRECTORY_SEPARATOR) && preg_match('/^[a-f0-9]{40}\.(?:png|jpg)$/D', basename($file))) unlink($file);
        }
        $connection->prepare('DELETE FROM farmers WHERE id = ?')->execute([$farmerId]);
    }
    foreach ($created['admins'] as $adminId) {
        $connection->prepare('DELETE FROM admin_account_events WHERE actor_admin_id = ? OR subject_admin_id = ?')->execute([$adminId, $adminId]);
        $connection->prepare('DELETE FROM admins WHERE id = ?')->execute([$adminId]);
    }
    if ($takeovers) test_admin_restore($connection, ['previous' => $takeovers[0]['previous']]);
}
exit($exitCode);
