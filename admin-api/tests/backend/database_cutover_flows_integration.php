<?php
declare(strict_types=1);

// End-to-end HTTP checks for a database cutover: the admin website and the mobile API are exercised
// against ONE database (whichever connection the target server uses). Only controlled fixtures are
// created (one ".test" admin, ".test" farmers, a Draft advisory) and they are removed by exact ID.
// Nothing here publishes an advisory or messages real farmers, so it is safe for a pre-cutover
// rehearsal on the migrated Supabase data.
//
// The target server MUST run with email disabled, e.g. APP_ENV=development MAIL_TRANSPORT=log and
// AGRIREACH_EMAIL_ENCRYPTION_KEY_FILE pointing to a missing file. Confirm with AGRIREACH_TEST_EMAIL_DISABLED=1.
//
// Usage: AGRIREACH_TEST_BASE_URL=http://127.0.0.1:8091 AGRIREACH_TEST_EMAIL_DISABLED=1 php tests/database_cutover_flows_integration.php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

// This process may write delivery-log rows itself; keep its own mail path offline too.
putenv('APP_ENV=development');
putenv('MAIL_TRANSPORT=log');
putenv('MAIL_TEST_RECIPIENT=');
putenv('AGRIREACH_EMAIL_ENCRYPTION_KEY_FILE=' . sys_get_temp_dir() . '/agrireach-missing-email.key');

require_once __DIR__ . '/../support/test_base_url.php';
require_once dirname(__DIR__, 2) . '/backend/config/database.php';
require_once dirname(__DIR__) . '/support/admin_test_account.php';
require_once dirname(__DIR__, 2) . '/backend/lib/farmer_verification.php';
require_once dirname(__DIR__, 2) . '/backend/lib/private_uploads.php';

if (getenv('AGRIREACH_TEST_EMAIL_DISABLED') !== '1') {
    fwrite(STDERR, "Refusing to run: start the target server with email disabled and set AGRIREACH_TEST_EMAIL_DISABLED=1.\n");
    exit(1);
}

$checks = 0;
function cutover_check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}

/** @return array{status:int,location:string,type:string,body:string,json:?array} */
function cutover_http(string $method, string $path, array $options = []): array
{
    $handle = curl_init(agrireach_test_base_url() . '/CAPSTONE%20AGRICULTURE' . $path);
    $headers = ['Accept: ' . ($options['accept'] ?? 'application/json, text/html')];
    if (isset($options['token'])) $headers[] = 'Authorization: Bearer ' . $options['token'];
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 30, CURLOPT_FOLLOWLOCATION => false]);
    if (isset($options['jar'])) curl_setopt_array($handle, [CURLOPT_COOKIEJAR => $options['jar'], CURLOPT_COOKIEFILE => $options['jar']]);
    if (array_key_exists('json', $options)) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($options['json'], JSON_THROW_ON_ERROR));
    } elseif (isset($options['multipart'])) {
        curl_setopt($handle, CURLOPT_POSTFIELDS, $options['multipart']);
    } elseif (isset($options['form'])) {
        curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($options['form']));
    }
    curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
    $raw = curl_exec($handle);
    if (!is_string($raw)) throw new RuntimeException('HTTP request failed: ' . curl_error($handle));
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    $type = (string) curl_getinfo($handle, CURLINFO_CONTENT_TYPE);
    curl_close($handle);
    $head = substr($raw, 0, $headerSize);
    $body = substr($raw, $headerSize);
    preg_match('/^Location:\s*(.+)$/mi', $head, $location);
    $json = str_contains($type, 'json') ? json_decode($body, true) : null;
    return ['status' => $status, 'location' => trim($location[1] ?? ''), 'type' => $type, 'body' => $body, 'json' => is_array($json) ? $json : null];
}

function cutover_csrf(string $jar, string $page): string
{
    $response = cutover_http('GET', '/admin/' . $page, ['jar' => $jar]);
    cutover_check($response['status'] === 200, "Admin page {$page} did not load (HTTP {$response['status']}).");
    cutover_check(preg_match('/name="csrf_token" value="([a-f0-9]{64})"/', $response['body'], $match) === 1, "No CSRF token on {$page}.");
    return $match[1];
}

/** Follows an admin post-redirect-get and returns the rendered page. */
function cutover_admin_post(string $jar, string $page, array $form): string
{
    $post = cutover_http('POST', '/admin/' . $page, ['jar' => $jar, 'form' => $form]);
    cutover_check($post['status'] === 302, "Admin POST to {$page} ({$form['action']}) did not redirect (HTTP {$post['status']}).");
    $target = $post['location'] !== '' && !str_contains($post['location'], '://') ? '/admin/' . ltrim($post['location'], '/') : '/admin/' . $page;
    return cutover_http('GET', $target, ['jar' => $jar])['body'];
}

function cutover_register(array $fields, string $image): array
{
    $fields['valid_id_front'] = new CURLFile($image, 'image/png', 'controlled-id-front.png');
    $fields['valid_id_back'] = new CURLFile($image, 'image/png', 'controlled-id-back.png');
    return cutover_http('POST', '/api/farmers/register.php', ['multipart' => $fields, 'accept' => 'application/json']);
}

$connection = db();
$suffix = (string) random_int(1000000, 9999999);
$jar = tempnam(sys_get_temp_dir(), 'agrireach-cutover-cookies-');
$image = dirname(__DIR__, 2) . '/frontend/mobile/assets/images/icon.png';
$adminId = null;
$farmerIds = [];
$advisoryIds = [];
$files = [];
$started = microtime(true);

try {
    cutover_check(is_file($image) && getimagesize($image) !== false, 'The PNG fixture image is unavailable.');
    $driver = db_is_pgsql($connection) ? 'PostgreSQL' : 'MySQL';

    // --- Admin account, login (case-insensitive username, as with MySQL utf8mb4_general_ci) and roles.
    $adminPassword = 'Cutover-' . bin2hex(random_bytes(6));
    $username = 'cutover_admin_' . $suffix;
    $takeover = test_admin_takeover($connection, 'Cutover Test Admin', $username, password_hash($adminPassword, PASSWORD_DEFAULT), $username . '@example.test');
    $adminId = $takeover['id'];

    $anonymous = cutover_http('GET', '/admin/dashboard.php', ['jar' => $jar]);
    cutover_check($anonymous['status'] === 302 && str_contains($anonymous['location'], 'login.php'), 'Admin pages must require login.');
    $bad = cutover_http('POST', '/admin/login.php', ['jar' => $jar, 'form' => ['username' => $username, 'password' => 'wrong-password']]);
    cutover_check($bad['status'] === 200 && str_contains($bad['body'], 'Invalid login credentials'), 'A wrong admin password was not rejected.');
    $login = cutover_http('POST', '/admin/login.php', ['jar' => $jar, 'form' => ['username' => strtoupper($username), 'password' => $adminPassword]]);
    cutover_check($login['status'] === 302 && str_contains($login['location'], 'dashboard.php'), 'Admin login (upper-case username) failed.');
    $dashboard = cutover_http('GET', '/admin/dashboard.php', ['jar' => $jar]);
    cutover_check($dashboard['status'] === 200 && !str_contains($dashboard['body'], 'could not be loaded'), 'The admin dashboard did not render.');

    // --- Registration (mobile API) for an approve path and a reject path.
    $base = [
        'first_name' => 'Cutover', 'last_name' => 'Tester', 'gender' => 'Prefer not to say', 'custom_gender' => '',
        'residential_city_psgc_code' => '1600203000', 'residential_barangay_psgc_code' => '1600203019',
        'residential_purok_sitio' => 'Purok 1', 'residential_landmark' => 'Controlled cutover landmark',
        'residential_location_method' => 'Manual', 'residential_latitude' => '', 'residential_longitude' => '',
        'date_of_birth' => '1990-01-02', 'password' => 'Cutover123', 'confirm_password' => 'Cutover123',
        'farm_name' => 'Cutover Test Farm', 'farm_type' => 'Crops', 'agricultural_category' => 'Crops',
        'farm_city_psgc_code' => '1600203000', 'farm_barangay_psgc_code' => '1600203025', 'farm_purok_sitio' => 'Purok 3',
        'farm_landmark' => 'Near controlled cutover marker', 'land_area' => '1.25', 'main_product' => 'Rice',
        'id_type' => 'Philippine National ID', 'farm_location_method' => 'Manual', 'latitude' => '', 'longitude' => '',
    ];
    $emails = ['approve' => "cutover-approve-{$suffix}@example.test", 'reject' => "cutover-reject-{$suffix}@example.test"];
    $ids = [];
    foreach (['approve' => '0917', 'reject' => '0918'] as $key => $prefix) {
        $registered = cutover_register($base + ['email' => $emails[$key], 'contact_number' => $prefix . substr($suffix, 0, 7), 'id_number' => "CUTOVER-{$key}-{$suffix}"], $image);
        cutover_check($registered['status'] === 201 && ($registered['json']['application']['verificationStatus'] ?? '') === 'Pending', "Registration ({$key}) did not return 201 Pending.");
        $ids[$key] = (int) $registered['json']['application']['id'];
        $farmerIds[] = $ids[$key];
    }
    $duplicate = cutover_register($base + ['email' => strtoupper($emails['approve']), 'contact_number' => '0919' . substr($suffix, 0, 7), 'id_number' => 'CUTOVER-dup'], $image);
    cutover_check($duplicate['status'] === 409, 'A duplicate email (different case) was not rejected with 409.');
    $pending = cutover_http('POST', '/api/auth/login.php', ['json' => ['email' => $emails['approve'], 'password' => 'Cutover123']]);
    cutover_check($pending['status'] === 403 && ($pending['json']['status'] ?? '') === 'pending', 'A pending farmer could log in.');

    // --- Admin approval / rejection through the real form (CSRF, transaction, notification, email log).
    $token = cutover_csrf($jar, 'farmer-verification.php');
    $page = cutover_admin_post($jar, 'farmer-verification.php', ['csrf_token' => $token, 'action' => 'approve', 'farmer_id' => $ids['approve']]);
    cutover_check(str_contains($page, 'Farmer approved successfully.'), 'Approval flash message missing.');
    $page = cutover_admin_post($jar, 'farmer-verification.php', ['csrf_token' => $token, 'action' => 'approve', 'farmer_id' => $ids['approve']]);
    cutover_check(str_contains($page, 'already decided as Approved'), 'A repeated approval was not treated as stale.');
    $page = cutover_admin_post($jar, 'farmer-verification.php', ['csrf_token' => $token, 'action' => 'reject', 'farmer_id' => $ids['reject'], 'rejection_reason' => 'Controlled cutover rejection reason']);
    cutover_check(str_contains($page, 'Farmer rejected and the reason was saved.'), 'Rejection flash message missing.');
    $states = $connection->prepare('SELECT id, verification_status, account_status, verified_at, rejection_reason FROM farmers WHERE id IN (?, ?) ORDER BY id');
    $states->execute([$ids['approve'], $ids['reject']]);
    $byId = array_column($states->fetchAll(), null, 'id');
    cutover_check($byId[$ids['approve']]['verification_status'] === 'Approved' && $byId[$ids['approve']]['verified_at'] !== null, 'Approved farmer state was not stored.');
    cutover_check($byId[$ids['reject']]['verification_status'] === 'Rejected' && $byId[$ids['reject']]['account_status'] === 'Inactive', 'Rejected farmer state was not stored.');
    $deliveries = $connection->prepare('SELECT id, status, routing_mode, transport FROM verification_email_deliveries WHERE farmer_id IN (?, ?) ORDER BY id');
    $deliveries->execute([$ids['approve'], $ids['reject']]);
    $deliveryRows = $deliveries->fetchAll();
    cutover_check(count($deliveryRows) === 2, 'Each decision must log exactly one email delivery attempt.');
    foreach ($deliveryRows as $row) cutover_check(!($row['status'] === 'Sent' && $row['routing_mode'] === 'Live'), 'A LIVE email was sent: the target server is not running with email disabled.');
    $rejected = cutover_http('POST', '/api/auth/login.php', ['json' => ['email' => $emails['reject'], 'password' => 'Cutover123']]);
    cutover_check($rejected['status'] === 403 && ($rejected['json']['rejectionReason'] ?? '') === 'Controlled cutover rejection reason', 'Rejected login did not return the stored reason.');

    // Email-delivery history page and duplicate-retry prevention (unique retry_of_delivery_id).
    $history = cutover_http('GET', '/admin/email-settings.php', ['jar' => $jar]);
    cutover_check($history['status'] === 200 && str_contains($history['body'], $emails['approve']), 'Email delivery history does not list the decision email.');
    $farmerRow = ['id' => $ids['approve'], 'full_name' => 'Cutover Tester', 'email' => $emails['approve']];
    $firstRetry = deliver_and_log_verification_email($connection, $farmerRow, (int) $adminId, 'Approved', null, null, (int) $deliveryRows[0]['id']);
    $secondRetry = deliver_and_log_verification_email($connection, $farmerRow, (int) $adminId, 'Approved', null, null, (int) $deliveryRows[0]['id']);
    cutover_check($firstRetry['failure_code'] !== 'duplicate_retry_prevented' && $secondRetry['failure_code'] === 'duplicate_retry_prevented', 'Duplicate email retry was not prevented.');

    // --- Farmer session lifecycle and farms (mobile API).
    $farmerLogin = cutover_http('POST', '/api/auth/login.php', ['json' => ['email' => strtoupper($emails['approve']), 'password' => 'Cutover123']]);
    cutover_check($farmerLogin['status'] === 200 && is_string($farmerLogin['json']['token'] ?? null), 'Approved farmer login failed.');
    $farmerToken = $farmerLogin['json']['token'];
    $me = cutover_http('GET', '/api/auth/me.php', ['token' => $farmerToken]);
    cutover_check($me['status'] === 200 && ($me['json']['farmer']['id'] ?? null) === $ids['approve'] && is_int($me['json']['farmer']['farm']['id'] ?? null), 'auth/me did not return the farmer and primary farm with integer IDs.');
    $added = cutover_http('POST', '/api/farms/index.php', ['token' => $farmerToken, 'json' => ['action' => 'create', 'name' => 'Cutover Second Farm', 'type' => 'Crops', 'agriculturalCategory' => 'Crops', 'barangayCode' => '1600203040', 'address' => 'Tolosa cutover landmark', 'landArea' => 2.5, 'landAreaUnit' => 'hectares', 'mainProduct' => 'Corn', 'latitude' => null, 'longitude' => null]]);
    cutover_check($added['status'] === 201 && ($added['json']['data']['isPrimary'] ?? null) === false, 'Adding a second farm failed.');
    $farms = cutover_http('GET', '/api/farms/index.php', ['token' => $farmerToken]);
    cutover_check(count($farms['json']['data']['farms'] ?? []) === 2, 'The farm list does not contain both farms.');
    $primaryFarmId = (int) $me['json']['farmer']['farm']['id'];

    // --- Damage report upload, duplicate-submit retry, protected images, farmer isolation.
    $clientId = 'cutover_' . bin2hex(random_bytes(8));
    $report = [
        'farm_profile_id' => (string) $primaryFarmId, 'farm_type' => 'Crops', 'damage_type' => 'Flood',
        'damage_description' => 'Controlled cutover test report, not a real damage report.', 'estimated_damage_cost' => '1500.50',
        'submitted_address' => 'Controlled Cabadbaran cutover location', 'submitted_latitude' => '9.1172482', 'submitted_longitude' => '125.5296631',
        'client_submission_id' => $clientId, 'location_confirmed' => '1', 'location_source' => 'manual', 'location_photo_order' => '',
        'photos[0]' => new CURLFile($image, 'image/png', 'cutover-0.png'), 'photo_latitude[0]' => '', 'photo_longitude[0]' => '',
        'photo_gps_source[0]' => 'none', 'photo_gps_accuracy[0]' => '', 'photo_captured_at[0]' => '', 'photo_capture_time_source[0]' => 'none',
    ];
    $submitted = cutover_http('POST', '/api/reports/index.php', ['token' => $farmerToken, 'multipart' => $report]);
    cutover_check($submitted['status'] === 201, 'Damage report submission failed (HTTP ' . $submitted['status'] . ': ' . ($submitted['json']['message'] ?? '') . ').');
    $reportCode = (string) $submitted['json']['data']['id'];
    $retry = cutover_http('POST', '/api/reports/index.php', ['token' => $farmerToken, 'multipart' => $report]);
    cutover_check($retry['status'] === 200 && ($retry['json']['data']['id'] ?? '') === $reportCode, 'A retried submission did not return the already-saved report.');
    $count = $connection->prepare('SELECT COUNT(*) FROM damage_reports WHERE farmer_id = ? AND client_submission_id = ?');
    $count->execute([$ids['approve'], $clientId]);
    cutover_check((int) $count->fetchColumn() === 1, 'Duplicate submission created a second report.');
    $detail = cutover_http('GET', '/api/reports/index.php?id=' . rawurlencode($reportCode), ['token' => $farmerToken]);
    $photoId = (string) ($detail['json']['data']['photos'][0]['id'] ?? '');
    cutover_check($detail['status'] === 200 && $photoId !== '', 'Report detail did not include its photo.');
    $reportRow = $connection->prepare('SELECT id, estimated_damage_cost, submitted_at FROM damage_reports WHERE report_code = ?');
    $reportRow->execute([$reportCode]);
    $reportRow = $reportRow->fetch();
    $reportId = (int) $reportRow['id'];
    cutover_check($reportRow['estimated_damage_cost'] === '1500.50' && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', (string) $reportRow['submitted_at']) === 1, 'Decimal or timestamp format changed.');
    cutover_check(abs(strtotime((string) $reportRow['submitted_at']) - time()) < 300, 'submitted_at is not Philippine wall-clock time.');
    foreach ($connection->query("SELECT image_path FROM report_images WHERE report_id = {$reportId}")->fetchAll(PDO::FETCH_COLUMN) as $path) $files[] = dirname(__DIR__, 2) . '/storage/report-images/' . basename((string) $path);
    $ownImage = cutover_http('GET', '/api/reports/image.php?id=' . $photoId, ['token' => $farmerToken, 'accept' => 'image/*']);
    cutover_check($ownImage['status'] === 200 && $ownImage['type'] === 'image/png', 'The owner could not fetch the protected report photo.');
    cutover_check(cutover_http('GET', '/api/reports/image.php?id=' . $photoId, ['accept' => 'image/*'])['status'] === 401, 'Report photos must require authentication.');

    $otherEmail = "cutover-other-{$suffix}@example.test";
    $connection->prepare("INSERT INTO farmers (full_name, contact_number, email, address, account_status, verification_status, password_hash) VALUES ('Cutover Other Farmer', ?, ?, 'Controlled address', 'Active', 'Approved', ?)")
        ->execute(['0920' . substr($suffix, 0, 7), $otherEmail, password_hash('Cutover123', PASSWORD_DEFAULT)]);
    $otherId = db_insert_id($connection, 'farmers');
    $farmerIds[] = $otherId;
    $otherToken = cutover_http('POST', '/api/auth/login.php', ['json' => ['email' => $otherEmail, 'password' => 'Cutover123']])['json']['token'] ?? '';
    cutover_check($otherToken !== '', 'The second farmer could not log in.');
    cutover_check(cutover_http('GET', '/api/reports/image.php?id=' . $photoId, ['token' => $otherToken, 'accept' => 'image/*'])['status'] === 404, 'Another farmer could fetch a foreign report photo.');
    cutover_check(cutover_http('GET', '/api/reports/index.php?id=' . rawurlencode($reportCode), ['token' => $otherToken])['status'] === 404, 'Another farmer could read a foreign report.');
    cutover_check(cutover_http('GET', '/api/farms/index.php?id=' . $primaryFarmId, ['token' => $otherToken])['status'] === 404, 'Another farmer could read a foreign farm.');
    cutover_check(cutover_http('GET', '/api/farmers/id-image.php?side=front', ['token' => $farmerToken, 'accept' => 'image/*'])['type'] === 'image/png', 'The farmer could not fetch their own ID image.');
    $adminIdImage = cutover_http('GET', '/admin/farmer-id-image.php?farmer_id=' . $ids['approve'] . '&side=back', ['jar' => $jar, 'accept' => 'image/*']);
    cutover_check($adminIdImage['status'] === 200 && $adminIdImage['type'] === 'image/png', 'The admin could not fetch the protected ID image.');
    $ids_paths = $connection->prepare('SELECT front_image_path, back_image_path FROM farmer_ids WHERE farmer_id IN (?, ?)');
    $ids_paths->execute([$ids['approve'], $ids['reject']]);
    foreach ($ids_paths->fetchAll() as $row) foreach ($row as $path) if ($path) $files[] = farmer_id_private_directory() . DIRECTORY_SEPARATOR . basename((string) $path);
    $adminPhoto = cutover_http('GET', '/admin/report-image.php?id=' . $photoId, ['jar' => $jar, 'accept' => 'image/*']);
    cutover_check($adminPhoto['status'] === 200 && $adminPhoto['type'] === 'image/png', 'The admin could not fetch the report photo.');

    // --- Admin report status + message, assessment and resolution, then the farmer's view.
    $reportsPage = cutover_http('GET', '/admin/damage-reports.php?search=' . rawurlencode(strtolower($reportCode)), ['jar' => $jar]);
    cutover_check(str_contains($reportsPage['body'], $reportCode), 'Case-insensitive admin report search did not find the report.');
    $token = cutover_csrf($jar, 'damage-reports.php');
    cutover_admin_post($jar, 'damage-reports.php', ['csrf_token' => $token, 'action' => 'update_response', 'report_id' => $reportId, 'new_status' => 'Under Review', 'response_message' => 'Controlled cutover admin message', 'rejection_reason' => '']);
    $farmerView = cutover_http('GET', '/api/reports/index.php?id=' . rawurlencode($reportCode), ['token' => $farmerToken]);
    cutover_check(($farmerView['json']['data']['status'] ?? '') === 'Under Review' && str_contains(json_encode($farmerView['json']['data']), 'Controlled cutover admin message'), 'The farmer does not see the new status and admin message.');
    foreach (['save_assessment' => ['1200.00', 'Controlled assessment remarks'], 'resolve_report' => ['1250.75', 'Controlled final remarks']] as $action => [$cost, $remarks]) {
        $assessed = cutover_http('POST', '/admin/damage-reports.php', ['jar' => $jar, 'form' => ['csrf_token' => $token, 'action' => $action, 'report_id' => $reportId, 'final_assessed_cost' => $cost, 'assessment_remarks' => $remarks]]);
        cutover_check($assessed['status'] === 200 && ($assessed['json']['success'] ?? false) === true, "Assessment action {$action} failed.");
    }
    $audit = $connection->prepare('SELECT action, new_assessed_cost FROM damage_report_assessment_audit WHERE report_id = ? ORDER BY id');
    $audit->execute([$reportId]);
    cutover_check($audit->fetchAll(PDO::FETCH_NUM) === [['Saved', '1200.00'], ['Resolved', '1250.75']], 'The assessment audit trail is incomplete.');
    $resolved = cutover_http('GET', '/api/reports/index.php?id=' . rawurlencode($reportCode), ['token' => $farmerToken]);
    cutover_check(($resolved['json']['data']['status'] ?? '') === 'Resolved', 'The farmer does not see the resolved status.');

    // --- Notifications: farmer list + mark read, admin read state + archive (upsert insert and update paths).
    $notices = cutover_http('GET', '/api/notifications/index.php', ['token' => $farmerToken]);
    $titles = array_column($notices['json']['data'] ?? [], 'title');
    foreach (['Farmer account approved', 'Damage report received', 'Damage report status updated', 'Admin response to your damage report', 'Damage report resolved'] as $title) cutover_check(in_array($title, $titles, true), "Farmer notification missing: {$title}");
    $noticeId = (int) $notices['json']['data'][0]['id'];
    $read = cutover_http('POST', '/api/notifications/index.php', ['token' => $farmerToken, 'json' => ['action' => 'mark_read', 'notificationId' => $noticeId]]);
    $readAgain = cutover_http('POST', '/api/notifications/index.php', ['token' => $farmerToken, 'json' => ['action' => 'mark_read', 'notificationId' => $noticeId]]);
    cutover_check($read['status'] === 200 && $readAgain['status'] === 200, 'Marking a farmer notification read (twice) failed.');
    cutover_check(cutover_http('POST', '/api/notifications/index.php', ['token' => $otherToken, 'json' => ['action' => 'mark_read', 'notificationId' => $noticeId]])['status'] === 404, 'Another farmer could mark a foreign notification.');
    $token = cutover_csrf($jar, 'notifications.php');
    foreach (['mark_read', 'mark_unread', 'delete'] as $action) cutover_admin_post($jar, 'notifications.php', ['csrf_token' => $token, 'action' => $action, 'notification_id' => $noticeId]);
    $state = $connection->prepare('SELECT is_read, is_archived FROM admin_notification_state WHERE admin_id = ? AND notification_id = ?');
    $state->execute([$adminId, $noticeId]);
    cutover_check($state->fetch(PDO::FETCH_NUM) === [0, 1], 'Admin notification read/archive state is wrong.');
    $farmerStill = $connection->prepare('SELECT is_read FROM notifications WHERE id = ?');
    $farmerStill->execute([$noticeId]);
    cutover_check((int) $farmerStill->fetchColumn() === 1, 'Admin archive changed the farmer notification.');

    // --- Admin settings upsert (insert then update) and manage-users change detection.
    $token = cutover_csrf($jar, 'settings.php');
    cutover_admin_post($jar, 'settings.php', ['csrf_token' => $token, 'action' => 'save_preferences', 'new_farmer_registration' => '1']);
    cutover_admin_post($jar, 'settings.php', ['csrf_token' => $token, 'action' => 'save_preferences', 'new_damage_reports' => '1']);
    $prefs = $connection->prepare('SELECT new_farmer_registration, new_damage_reports, COUNT(*) OVER () FROM admin_settings WHERE admin_id = ?');
    $prefs->execute([$adminId]);
    cutover_check($prefs->fetch(PDO::FETCH_NUM) === [0, 1, 1], 'Admin preference upsert did not update the single row.');
    $token = cutover_csrf($jar, 'manage-users.php');
    $profile = ['csrf_token' => $token, 'action' => 'edit', 'user_type' => 'farmer', 'user_id' => $otherId, 'full_name' => 'Cutover Other Farmer', 'contact_number' => '0920' . substr($suffix, 0, 7), 'email' => $otherEmail, 'address' => 'Controlled address'];
    cutover_check(str_contains(cutover_admin_post($jar, 'manage-users.php', $profile), 'No profile changes were made.'), 'An unchanged profile save was reported as an update.');
    cutover_check(str_contains(cutover_admin_post($jar, 'manage-users.php', ['address' => 'Controlled address, updated'] + $profile), 'User profile updated successfully.'), 'A changed profile was not reported as updated.');
    cutover_admin_post($jar, 'manage-users.php', ['csrf_token' => $token, 'action' => 'block', 'user_type' => 'farmer', 'user_id' => $otherId]);

    // Admin "Add farmer": the ID photo goes to private storage (outside the web root) and is served only through the admin route.
    $addedEmail = "cutover-added-{$suffix}@example.test";
    $add = cutover_http('POST', '/admin/manage-users.php', ['jar' => $jar, 'multipart' => [
        'csrf_token' => $token, 'action' => 'add', 'first_name' => 'Cutover', 'last_name' => 'Added', 'gender' => 'Prefer not to say',
        'contact_number' => '0921' . substr($suffix, 0, 7), 'email' => $addedEmail, 'address' => 'Controlled added address', 'date_of_birth' => '1991-03-04',
        'password' => 'Cutover123', 'confirm_password' => 'Cutover123', 'farm_name' => 'Cutover Added Farm', 'farm_type' => 'Crops', 'agricultural_category' => 'Crops',
        'farm_location' => 'Controlled added farm location', 'farm_size' => '1.5', 'main_product' => 'Rice', 'latitude' => '', 'longitude' => '',
        'id_type' => 'National ID', 'id_number' => 'CUTOVER-ADDED', 'valid_id' => new CURLFile($image, 'image/png', 'controlled-added-id.png'),
    ]]);
    cutover_check($add['status'] === 302 && str_contains(cutover_http('GET', '/admin/manage-users.php', ['jar' => $jar])['body'], 'Farmer added with Pending verification status.'), 'Admin "Add farmer" failed.');
    $added = $connection->prepare('SELECT f.id, fi.image_path FROM farmers f JOIN farmer_ids fi ON fi.farmer_id = f.id WHERE f.email = ?');
    $added->execute([$addedEmail]);
    $added = $added->fetch();
    cutover_check(is_array($added), 'The admin-added farmer was not stored.');
    $farmerIds[] = (int) $added['id'];
    $addedFile = farmer_id_private_directory() . DIRECTORY_SEPARATOR . basename((string) $added['image_path']);
    $files[] = $addedFile;
    cutover_check(is_file($addedFile) && !is_file(dirname(__DIR__, 2) . '/uploads/ids/' . basename((string) $added['image_path'])), 'The admin-added ID photo is not in private storage.');
    $addedImage = cutover_http('GET', '/admin/farmer-id-image.php?farmer_id=' . $added['id'] . '&side=front', ['jar' => $jar, 'accept' => 'image/*']);
    cutover_check($addedImage['status'] === 200 && $addedImage['type'] === 'image/png', 'The admin could not view the admin-added ID photo.');
    cutover_check(cutover_http('GET', '/api/auth/me.php', ['token' => $otherToken])['status'] === 401, 'A blocked farmer kept API access.');

    // --- Exports and weather (advice reads the live provider; advisory stays Draft so no one is notified).
    // Existing migrated reports (JPEG photos) must export. The PNG fixture needs the PHP GD extension,
    // which XAMPP leaves disabled: that is reported as an environment warning, not a database failure.
    $existing = (int) $connection->query("SELECT dr.id FROM damage_reports dr WHERE dr.is_fixture = 0 AND dr.farmer_id NOT IN (" . implode(',', array_map('intval', $farmerIds)) . ") AND EXISTS (SELECT 1 FROM report_images ri WHERE ri.report_id = dr.id AND LOWER(ri.image_path) LIKE '%.jpg') ORDER BY dr.id LIMIT 1")->fetchColumn();
    if ($existing > 0) {
        $pdf = cutover_http('GET', '/admin/damage-reports-pdf.php?mode=single&report_id=' . $existing, ['jar' => $jar, 'accept' => 'application/pdf']);
        cutover_check($pdf['status'] === 200 && str_starts_with($pdf['body'], '%PDF'), 'Single-report PDF export of a migrated report failed.');
    }
    $pdf = cutover_http('GET', '/admin/damage-reports-pdf.php?mode=single&report_id=' . $reportId, ['jar' => $jar, 'accept' => 'application/pdf']);
    if ($pdf['status'] === 500 && str_contains($pdf['body'], 'PHP GD extension')) echo "WARN: PNG-photo PDF export needs extension=gd (pre-existing XAMPP setting, unrelated to the database).
";
    else cutover_check($pdf['status'] === 200 && str_starts_with($pdf['body'], '%PDF'), 'Single-report PDF export failed.');
    $listPdf = cutover_http('GET', '/admin/damage-reports-pdf.php?search=' . rawurlencode($reportCode), ['jar' => $jar, 'accept' => 'application/pdf']);
    cutover_check($listPdf['status'] === 200 && str_starts_with($listPdf['body'], '%PDF'), 'Filtered list PDF export failed.');
    $token = cutover_csrf($jar, 'weather-advisory.php');
    $advisory = ['csrf_token' => $token, 'action' => 'create', 'title' => "Cutover draft advisory {$suffix}", 'agricultural_category' => 'Crops', 'weather_condition' => 'Heavy Rain', 'advisory_message' => 'Controlled draft, never published.', 'key_advice' => 'Controlled draft key advice.', 'effective_date' => date('Y-m-d'), 'expiration_date' => '', 'status' => 'Draft', 'coverage_scope' => 'City'];
    cutover_check(str_contains(cutover_admin_post($jar, 'weather-advisory.php', $advisory), 'Weather advisory created.'), 'Creating a draft advisory failed.');
    $find = $connection->prepare('SELECT id, expiration_date, published_at FROM weather_advisories WHERE title = ? AND created_by = ?');
    $find->execute([$advisory['title'], $adminId]);
    $created = $find->fetch();
    $advisoryIds[] = (int) $created['id'];
    cutover_check($created['expiration_date'] === null && $created['published_at'] === null, 'A blank expiration date was not stored as NULL.');
    cutover_check(str_contains(cutover_admin_post($jar, 'weather-advisory.php', ['action' => 'update', 'advisory_id' => $created['id'], 'expiration_date' => date('Y-m-d', strtotime('+3 days'))] + $advisory), 'Weather advisory updated.'), 'Updating the draft advisory failed.');
    cutover_check(str_contains(cutover_admin_post($jar, 'weather-advisory.php', ['csrf_token' => $token, 'action' => 'archive', 'advisory_id' => $created['id']]), 'Advisory status updated to Archived.'), 'Archiving the advisory failed.');
    $recipients = $connection->prepare('SELECT COUNT(*) FROM weather_advisory_recipients WHERE advisory_id = ?');
    $recipients->execute([$created['id']]);
    cutover_check((int) $recipients->fetchColumn() === 0, 'A draft/archived advisory notified farmers.');
    $advice = cutover_http('GET', '/api/weather/advice.php?farmId=' . $primaryFarmId, ['token' => $farmerToken]);
    cutover_check($advice['status'] === 200 && ($advice['json']['success'] ?? false) === true, 'Weather advice endpoint failed.');
    $advisories = cutover_http('GET', '/api/weather/advisories.php', ['token' => $farmerToken]);
    cutover_check($advisories['status'] === 200 && ($advisories['json']['data']['eligibility']['eligible'] ?? null) === true, 'Weather advisories endpoint failed.');

    // --- Archive a farm (delete workflow) and log out (session revocation).
    $archive = cutover_http('POST', '/api/farms/index.php', ['token' => $farmerToken, 'json' => ['action' => 'archive', 'farmId' => $primaryFarmId]]);
    $remaining = cutover_http('GET', '/api/farms/index.php', ['token' => $farmerToken])['json']['data']['farms'] ?? [];
    cutover_check($archive['status'] === 200 && count(array_filter($remaining, static fn($farm) => $farm['status'] === 'Active' && $farm['isPrimary'] === true)) === 1, 'Archiving the primary farm did not promote the remaining farm.');
    cutover_check(cutover_http('POST', '/api/auth/logout.php', ['token' => $farmerToken])['status'] === 200, 'Farmer logout failed.');
    cutover_check(cutover_http('GET', '/api/auth/me.php', ['token' => $farmerToken])['status'] === 401, 'A logged-out token still works.');
    cutover_http('GET', '/config/logout.php', ['jar' => $jar]);
    cutover_check(cutover_http('GET', '/admin/dashboard.php', ['jar' => $jar])['status'] === 302, 'Admin logout did not end the session.');

    printf("PASS: %d cutover checks on %s via %s in %.1fs\n", $checks, $driver, agrireach_test_base_url(), microtime(true) - $started);
} catch (Throwable $exception) {
    echo 'FAIL after ' . $checks . ' checks: ' . $exception->getMessage() . PHP_EOL;
    $exitCode = 1;
} finally {
    if ($advisoryIds) $connection->prepare('DELETE FROM weather_advisories WHERE id IN (' . implode(',', array_fill(0, count($advisoryIds), '?')) . ')')->execute($advisoryIds);
    if ($farmerIds) $connection->prepare('DELETE FROM farmers WHERE id IN (' . implode(',', array_fill(0, count($farmerIds), '?')) . ") AND email LIKE '%@example.test'")->execute($farmerIds);
    if ($adminId !== null) $connection->prepare("DELETE FROM admins WHERE id = ? AND username LIKE 'cutover\\_admin\\_%'")->execute([$adminId]);
    if (isset($takeover)) test_admin_restore($connection, $takeover);
    foreach (array_unique($files) as $file) if (is_file($file)) @unlink($file);
    if (is_file($jar)) @unlink($jar);
    echo "Controlled cutover fixtures removed.\n";
}
exit($exitCode ?? 0);
