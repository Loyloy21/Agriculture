<?php
declare(strict_types=1);

/**
 * Farmer-facing advisory summaries (migration 0009 key_advice), through the real APIs and the admin form:
 *   - short advisory without Key advice: the whole message is the preview (nothing cut);
 *   - long advisory with Key advice: the Key advice is the preview, the full message is returned unchanged;
 *   - long legacy advisory without Key advice: a plain pointer, never the first N characters;
 *   - urgent admin warning: its Key advice reaches cards and notifications word for word;
 *   - admin form: Key advice is required and limited to 200 characters; saving never rewrites the message.
 * Disposable records only. Run against a local test database (tests refuse Supabase via the admin helper rules).
 */

if (($argv[1] ?? '') === '--admin-post') {
    // Child process: one admin form POST, in-process like the admin smoke test. Prints the flash/error.
    session_save_path(sys_get_temp_dir());
    session_id('advisory-key-advice-' . bin2hex(random_bytes(4)));
    session_start();
    $_SESSION['admin_id'] = (int) $argv[2];
    $_SESSION['admin_role'] = 'admin';
    $_SESSION['admin_name'] = 'Controlled Test Administrator';
    $_SESSION['csrf_token'] = 'controlled-csrf-token';
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = json_decode((string) file_get_contents($argv[3]), true) + ['csrf_token' => 'controlled-csrf-token'];
    register_shutdown_function(static function (): void {
        echo json_encode(['error' => $_SESSION['advisory_error'] ?? null, 'flash' => $_SESSION['advisory_flash'] ?? null]);
        session_destroy();
    });
    require __DIR__ . '/../../frontend/admin/weather-advisory.php';
    exit;
}

require_once __DIR__ . '/../support/test_base_url.php';
require_once __DIR__ . '/../../backend/config/database.php';
require_once __DIR__ . '/../../backend/lib/weather_advisories.php';

$checks = 0;
function check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
    echo "  ok  {$message}\n";
}

function api_get(string $path, string $token): array
{
    $context = stream_context_create(['http' => [
        'method' => 'GET', 'ignore_errors' => true, 'timeout' => 15,
        'header' => "Accept: application/json\r\nAuthorization: Bearer {$token}\r\n",
    ]]);
    $body = file_get_contents(agrireach_test_base_url() . '/CAPSTONE%20AGRICULTURE/api' . $path, false, $context);
    if ($body === false) throw new RuntimeException('Local API could not be reached.');
    $payload = json_decode($body, true);
    if (!is_array($payload)) throw new RuntimeException('Local API returned invalid JSON: ' . substr($body, 0, 200));
    return $payload;
}

function admin_post(int $adminId, array $post): array
{
    $file = tempnam(sys_get_temp_dir(), 'adv');
    file_put_contents($file, json_encode($post));
    $output = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --admin-post ' . $adminId . ' ' . escapeshellarg($file) . ' 2>&1');
    unlink($file);
    $json = preg_match('/\{"error":.*\}$/s', (string) $output, $match) ? json_decode($match[0], true) : null;
    if (!is_array($json)) throw new RuntimeException('Admin form run failed: ' . substr((string) $output, 0, 400));
    return $json;
}

$connection = db();
$farmerIds = []; $advisoryIds = [];
$exitCode = 0;
try {
    $adminId = (int) $connection->query("SELECT id FROM admins WHERE status = 'active' ORDER BY id LIMIT 1")->fetchColumn();
    check($adminId > 0, 'an active test admin exists');
    $suffix = bin2hex(random_bytes(4));

    // ---------- Pure helpers ----------
    $long = 'DELIKADO RON ANG MGA CROPS KUSOG BAG YO UP COMING MAONG KUNG MAKAYA AYAW MO OG TANOM SA OR PAG HARVEST NA DAYON SA MGA HINOG NA. '
        . 'Siguraduha nga limpyo ang mga kanal aron dili mabahaan ang umahan. Ayaw sa pag-abono o pag-spray samtang nag-uwan kay maanod lang. '
        . 'Bantayi ang mga ubos nga dapit ug ang suba. Kung kinahanglan, ibalhin ang mga himan ug binhi sa taas nga lugar.';
    $base = ['agricultural_category' => 'Crops', 'weather_condition' => 'Heavy Rain', 'coverage_scope' => 'City', 'expiration_date' => '2026-10-30'];
    check(weather_advisory_preview($base + ['advisory_message' => 'Check drainage canals today.', 'key_advice' => null]) === ['text' => 'Check drainage canals today.', 'source' => 'message'], 'short message without Key advice: the whole message is the preview');
    $legacy = weather_advisory_preview($base + ['advisory_message' => $long, 'key_advice' => null]);
    check($legacy['source'] === 'none' && !str_starts_with($long, rtrim($legacy['text'], '.')) && !str_contains($long, mb_substr($legacy['text'], 0, 25)) && str_contains($legacy['text'], 'Detailed heavy rain advice') && str_contains($legacy['text'], 'full message'), 'long message without Key advice: a pointer to the full advice, not a cut-off of the message');
    check(weather_advisory_preview($base + ['advisory_message' => $long, 'key_advice' => '  Harvest mature crops now; clear canals.  '])['text'] === 'Harvest mature crops now; clear canals.', 'Key advice wins over the message and is trimmed');
    check(weather_advisory_context($base) === 'Heavy Rain · Crops · Until Oct 30, 2026', 'context names condition, category and end date');
    check(weather_advisory_context(['weather_condition' => 'Other', 'agricultural_category' => 'Livestock', 'coverage_scope' => 'Barangays', 'expiration_date' => null]) === 'Livestock · Selected barangays · Until further notice', 'context for "Other": no invented condition, barangay coverage and open end');

    // ---------- Fixtures: one verified Crops farmer ----------
    $connection->prepare("INSERT INTO farmers (full_name, contact_number, email, address, account_status, verification_status) VALUES ('Key Advice Test Farmer', ?, ?, 'Controlled test address', 'Active', 'Approved')")
        ->execute(['0996' . str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT), "key-advice-{$suffix}@example.test"]);
    $farmerId = db_insert_id($connection, 'farmers'); $farmerIds[] = $farmerId;
    $connection->prepare("INSERT INTO farm_profiles (farmer_id, farm_name, farm_type, agricultural_category, farm_address, city_psgc_code, city_name, province_name, barangay_psgc_code, barangay_name, location_review_status) VALUES (?, 'Key Advice Farm', 'Crops', 'Crops', 'Tolosa, Cabadbaran City', ?, 'Cabadbaran City', 'Agusan del Norte', '1600203040', 'Tolosa', 'Verified')")
        ->execute([$farmerId, CABADBARAN_CITY_CODE]);
    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $connection->prepare("INSERT INTO farmer_sessions (farmer_id, token_hash, expires_at) VALUES (?, ?, " . db_sql_utc_now($connection) . " + INTERVAL '1' HOUR)")->execute([$farmerId, hash('sha256', $token)]);

    $today = date('Y-m-d'); $until = date('Y-m-d', strtotime('+5 days'));
    $save = static function (array $fields) use ($connection, $adminId, $today, &$advisoryIds): int {
        $saved = weather_advisory_save($connection, null, $fields + [
            'category' => 'Crops', 'coverage_label' => cabadbaran_coverage_label('City'), 'scope' => 'City', 'barangay_codes' => [],
            'effective_date' => $today, 'status' => 'Active',
        ], $adminId);
        $advisoryIds[] = $saved['id'];
        return $saved['id'];
    };
    $shortId = $save(['title' => "Short advisory {$suffix}", 'weather_condition' => 'Normal Weather', 'message' => 'Continue regular farm activities and keep monitoring weather updates.', 'key_advice' => null, 'expiration_date' => null]);
    $longId = $save(['title' => "Long advisory {$suffix}", 'weather_condition' => 'Heavy Rain', 'message' => $long, 'key_advice' => 'Harvest mature crops now and clear drainage canals. Do not apply fertilizer or spray while it rains.', 'expiration_date' => $until]);
    $urgentKey = 'URGENT: Strong winds expected tonight. Secure greenhouse covers and stake tall crops before 5 PM.';
    $urgentMessage = "Strong Wind warning for all crop farms.\n- Secure greenhouse covers, nets and light structures before 5 PM.\n- Stake or support tall crops such as corn and banana.\n- Do not spray: wind carries chemicals away.\n- Stay away from power lines and tall trees during the strongest gusts.";
    $urgentId = $save(['title' => "Urgent wind {$suffix}", 'weather_condition' => 'Strong Wind', 'message' => $urgentMessage, 'key_advice' => $urgentKey, 'expiration_date' => $until]);
    // A legacy long advisory written before 0009 (no Key advice).
    $legacyId = $save(['title' => "Legacy long advisory {$suffix}", 'weather_condition' => 'Heavy Rain', 'message' => $long, 'key_advice' => null, 'expiration_date' => $until]);

    $stored = $connection->query('SELECT id, advisory_message, key_advice FROM weather_advisories WHERE id IN (' . implode(',', $advisoryIds) . ')')->fetchAll(PDO::FETCH_UNIQUE);
    check($stored[$longId]['advisory_message'] === $long && $stored[$urgentId]['advisory_message'] === $urgentMessage && $stored[$longId]['key_advice'] !== null && $stored[$legacyId]['key_advice'] === null, 'stored: full messages saved byte for byte, Key advice in its own column');

    // ---------- Farmer advisory list ----------
    $list = api_get('/weather/advisories.php', $token);
    check(($list['success'] ?? false) === true, 'advisory list API answers');
    $items = array_column($list['data']['items'], null, 'id');
    $short = $items[(string) $shortId]; $longItem = $items[(string) $longId]; $urgent = $items[(string) $urgentId]; $legacyItem = $items[(string) $legacyId];
    check($short['preview'] === ['text' => 'Continue regular farm activities and keep monitoring weather updates.', 'source' => 'message'] && $short['keyAdvice'] === null, 'short advisory: whole message is the card preview');
    check($longItem['message'] === $long && $longItem['keyAdvice'] === $stored[$longId]['key_advice'] && $longItem['preview']['source'] === 'key_advice' && $longItem['preview']['text'] === $longItem['keyAdvice'], 'long advisory: Key advice is the preview, the full message is returned unchanged');
    check(str_starts_with($longItem['context'], 'Heavy Rain · Crops · Until ') && $longItem['coverageScope'] === 'City', 'long advisory: context gives condition, category and end date');
    check($urgent['preview']['text'] === $urgentKey && $urgent['message'] === $urgentMessage && str_contains($urgent['context'], 'Strong Wind'), 'urgent admin warning: its Key advice reaches the card word for word, full message kept');
    check($legacyItem['preview']['source'] === 'none' && $legacyItem['message'] === $long && !str_contains($long, mb_substr($legacyItem['preview']['text'], 0, 25)), 'legacy long advisory: pointer to the full advice, never a cut-off');

    // ---------- Detail (View full advice) ----------
    $detail = api_get('/weather/advisories.php?id=' . $urgentId, $token);
    check(($detail['data']['message'] ?? null) === $urgentMessage && $detail['data']['keyAdvice'] === $urgentKey, 'detail returns the complete original message and the Key advice');

    // ---------- Notifications ----------
    $notifications = api_get('/notifications/index.php', $token);
    $byAdvisory = [];
    foreach ($notifications['data'] as $item) if ($item['relatedAdvisoryId'] !== null) $byAdvisory[$item['relatedAdvisoryId']] = $item;
    check(count(array_intersect_key($byAdvisory, array_flip(array_map('strval', $advisoryIds)))) === 4, 'a notification exists for each of the four advisories');
    check($byAdvisory[(string) $urgentId]['shortMessage'] === $urgentKey && str_contains($byAdvisory[(string) $urgentId]['fullMessage'], $urgentMessage) && str_contains((string) $byAdvisory[(string) $urgentId]['context'], 'Strong Wind · Crops'), 'urgent notification preview is the Key advice; the stored notification keeps the full message');
    check($byAdvisory[(string) $longId]['shortMessage'] === $longItem['keyAdvice'] && !str_starts_with($byAdvisory[(string) $longId]['shortMessage'], 'Admin-created'), 'long notification preview is the Key advice, not the "Admin-created rule-based advisory. Coverage…" prefix');
    check($byAdvisory[(string) $shortId]['shortMessage'] === $short['preview']['text'] && $byAdvisory[(string) $legacyId]['shortMessage'] === $legacyItem['preview']['text'] && !str_ends_with($byAdvisory[(string) $legacyId]['shortMessage'], '...'), 'short and legacy notification previews match the cards and are not cut off');
    $stillStored = $connection->prepare('SELECT message FROM notifications WHERE related_advisory_id = ? AND farmer_id = ?');
    $stillStored->execute([$longId, $farmerId]);
    check(str_contains((string) $stillStored->fetchColumn(), $long), 'the notification row in storage still holds the full advisory message');

    // ---------- Admin form: validation and edit ----------
    $form = ['action' => 'create', 'title' => "Admin form advisory {$suffix}", 'agricultural_category' => 'Crops', 'weather_condition' => 'High Temperature',
        'advisory_message' => "Line one of the full message.\nLine two keeps its line break.", 'effective_date' => $today, 'expiration_date' => '', 'status' => 'Draft', 'coverage_scope' => 'City'];
    $result = admin_post($adminId, $form + ['key_advice' => '   ']);
    check(is_string($result['error']) && str_contains($result['error'], 'Key advice'), 'admin form rejects a blank Key advice');
    $result = admin_post($adminId, $form + ['key_advice' => str_repeat('a', WEATHER_ADVISORY_KEY_ADVICE_MAX + 1)]);
    check(is_string($result['error']) && str_contains($result['error'], (string) WEATHER_ADVISORY_KEY_ADVICE_MAX), 'admin form rejects Key advice over 200 characters');
    $result = admin_post($adminId, $form + ['key_advice' => "Water crops early\n morning;   avoid midday field work."]);
    check($result['error'] === null && str_contains((string) $result['flash'], 'created'), 'admin form saves a valid Key advice');
    $row = $connection->prepare('SELECT id, key_advice, advisory_message FROM weather_advisories WHERE title = ?');
    $row->execute([$form['title']]);
    $created = $row->fetch();
    $advisoryIds[] = (int) $created['id'];
    check($created['key_advice'] === 'Water crops early morning; avoid midday field work.' && $created['advisory_message'] === $form['advisory_message'], 'Key advice whitespace is tidied; the full message keeps its line breaks');
    $result = admin_post($adminId, ['action' => 'update', 'advisory_id' => (string) $created['id'], 'key_advice' => 'Water crops early morning.'] + $form);
    $row->execute([$form['title']]);
    $updated = $row->fetch();
    check($result['error'] === null && $updated['key_advice'] === 'Water crops early morning.' && $updated['advisory_message'] === $form['advisory_message'], 'editing the Key advice leaves the full message untouched');

    echo "PASS: advisory Key advice previews, full advice and admin validation ({$checks} checks)\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . PHP_EOL);
    $exitCode = 1;
} finally {
    if ($advisoryIds) $connection->query('DELETE FROM weather_advisories WHERE id IN (' . implode(',', array_map('intval', $advisoryIds)) . ')');
    if ($farmerIds) $connection->query('DELETE FROM farmers WHERE id IN (' . implode(',', array_map('intval', $farmerIds)) . ')');
}
exit($exitCode);
