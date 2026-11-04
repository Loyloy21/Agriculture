<?php
declare(strict_types=1);

// CLI-only. Creates controlled .test fixtures and removes them by exact IDs in finally.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once dirname(__DIR__, 2) . '/backend/config/database.php';
require_once dirname(__DIR__, 2) . '/frontend/admin/includes/pagination.php';
require_once dirname(__DIR__, 2) . '/backend/lib/damage_reports.php';

if (($argv[1] ?? '') === 'render') {
    session_start();
    $_SESSION['admin_id'] = 1;
    $_SESSION['admin_role'] = 'admin';
    $_SESSION['admin_name'] = 'Test Administrator';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = json_decode((string) ($argv[3] ?? '{}'), true, 8) ?: [];
    $pages = [
        'users' => 'manage-users.php',
        'verification' => 'farmer-verification.php',
        'reports' => 'damage-reports.php',
    ];
    $file = $pages[$argv[2] ?? ''] ?? null;
    if ($file === null) exit(2);
    require dirname(__DIR__, 2) . '/frontend/admin/' . $file;
    exit;
}

function render_page(string $page, array $filters): string
{
    $process = proc_open([PHP_BINARY, __FILE__, 'render', $page, json_encode($filters, JSON_THROW_ON_ERROR)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not start PHP render test.');
    $html = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0 || !is_string($html)) throw new RuntimeException('PHP render failed: ' . trim((string) $error));
    return $html;
}

function check(bool $condition, string $label): void
{
    if (!$condition) throw new RuntimeException($label . ' failed.');
    echo 'PASS ' . $label . PHP_EOL;
}

function row_count(string $html, string $page): int
{
    $pattern = match ($page) {
        'users' => '/class="btn btn-outline-success btn-sm view-user-btn"/',
        'verification' => '/class="btn btn-outline-success btn-sm view-farmer-btn"/',
        'reports' => '/class="btn btn-outline-success btn-sm view-report-btn"/',
    };
    return preg_match_all($pattern, $html);
}

function row_keys(string $html, string $page): array
{
    $class = match ($page) {
        'users' => 'view-user-btn',
        'verification' => 'view-farmer-btn',
        'reports' => 'view-report-btn',
    };
    $attribute = match ($page) {
        'users' => 'data-user-id',
        'verification' => 'data-farmer-id',
        'reports' => 'data-report-key',
    };
    preg_match_all('/class="[^"]*' . $class . '[^"]*"[^>]*' . $attribute . '="([^"]+)"/', $html, $matches);
    return $matches[1];
}

$db = db();
$marker = 'qa-page-' . bin2hex(random_bytes(5));
$farmerIds = [];
$reportIds = [];
try {
    foreach (range(1, 14) as $index) {
        $status = $index === 13 ? 'Pending' : ($index === 14 ? 'Rejected' : 'Approved');
        $account = $index === 12 ? 'Blocked' : 'Active';
        $name = sprintf('%s farmer %02d', $marker, $index);
        $email = sprintf('%s-%02d@agrireach.test', $marker, $index);
        $contact = '+639' . random_int(100000000, 999999999);
        $address = 'Controlled Cabadbaran test address';
        $insertFarmer = $db->prepare('INSERT INTO farmers (full_name, contact_number, email, address, account_status, verification_status) VALUES (?, ?, ?, ?, ?, ?)');
        $insertFarmer->execute([$name, $contact, $email, $address, $account, $status]);
        $farmerId = (int) $db->lastInsertId();
        $farmerIds[] = [$farmerId, $email];

        $farmName = sprintf('%s farm %02d', $marker, $index);
        $category = $index % 2 === 0 ? 'Livestock' : 'Crops';
        $insertFarm = $db->prepare("INSERT INTO farm_profiles (farmer_id, farm_name, farm_type, agricultural_category, farm_address, status, is_primary) VALUES (?, ?, ?, ?, ?, 'Active', 1)");
        $insertFarm->execute([$farmerId, $farmName, $category, $category, $address]);
        $farmId = (int) $db->lastInsertId();

        if ($index <= 12) {
            $reportCode = sprintf('%s-%02d', $marker, $index);
            $damageType = 'Controlled storm';
            $description = 'Temporary pagination test report.';
            $cost = 100.0;
            $insertReport = $db->prepare('INSERT INTO damage_reports (report_code, farmer_id, farm_profile_id, agricultural_category, damage_type, damage_description, estimated_damage_cost, is_fixture) VALUES (?, ?, ?, ?, ?, ?, ?, 0)');
            $insertReport->execute([$reportCode, $farmerId, $farmId, $category, $damageType, $description, $cost]);
            $reportIds[] = (int) $db->lastInsertId();
        }
    }

    $cases = [
        'users' => ['search' => $marker],
        'verification' => ['search' => $marker],
        'reports' => ['search' => $marker],
    ];
    foreach ($cases as $page => $filters) {
        $first = render_page($page, $filters);
        $second = render_page($page, [...$filters, 'page' => '2']);
        $total = $page === 'verification' ? 14 : 12;
        check(row_count($first, $page) === 10 && row_count($second, $page) === $total - 10, "$page server-side page sizes");
        $firstKeys = row_keys($first, $page);
        $secondKeys = row_keys($second, $page);
        check(count($firstKeys) === 10 && count($secondKeys) === $total - 10 && !array_intersect($firstKeys, $secondKeys), "$page stable ordering without repeated rows");
        check(str_contains($second, 'id="' . ($page === 'users' ? 'user-data-' : ($page === 'verification' ? 'farmer-data-' : 'report-data-')) . $secondKeys[0] . '"'), "$page details data available on page 2");
        check(str_contains($first, 'Showing 1–10 of ' . $total) && str_contains($second, 'Showing 11–' . $total . ' of ' . $total), "$page matching total and ranges");
        check(str_contains($first, 'page=2') && str_contains($second, 'page=1') && str_contains($second, 'aria-current="page"'), "$page links and current page");
        check(str_contains($first, 'aria-disabled="true">Previous</span>') && str_contains($second, 'aria-disabled="true">Next</span>'), "$page edge navigation disabled");
        check(str_contains($second, 'search=' . $marker), "$page filter persists in URL");
        if ($page === 'users') {
            check(!str_contains($first . $second, 'data-user-id="farmer:' . $farmerIds[12][0] . '"')
                && !str_contains($first . $second, 'data-user-id="farmer:' . $farmerIds[13][0] . '"'), 'pending and rejected farmers hidden from Manage Users');
        }
        $refreshed = render_page($page, $filters);
        check(row_count($refreshed, $page) === 10 && str_contains($refreshed, 'Showing 1–10 of ' . $total), "$page refresh keeps stable page");
    }

    $users = render_page('users', ['search' => $marker, 'account_status' => 'Blocked']);
    check(row_count($users, 'users') === 1 && str_contains($users, 'Showing 1–1 of 1'), 'Manage Users status filter before pagination');
    $verification = render_page('verification', ['search' => $marker, 'status' => 'Pending']);
    check(row_count($verification, 'verification') === 1 && str_contains($verification, 'Showing 1–1 of 1'), 'Verification status filter before pagination');
    $livestock = render_page('verification', ['search' => $marker, 'farm_type' => 'Livestock']);
    check(row_count($livestock, 'verification') === 7 && str_contains($livestock, 'Showing 1–7 of 7'), 'Verification farm filter before pagination');
    $reports = render_page('reports', ['search' => $marker, 'category' => 'Crops']);
    check(row_count($reports, 'reports') === 6 && str_contains($reports, 'Showing 1–6 of 6'), 'Damage category filter before pagination');
    $futureReports = render_page('reports', ['search' => $marker, 'date_from' => date('Y-m-d', strtotime('+1 day'))]);
    check(row_count($futureReports, 'reports') === 0 && str_contains($futureReports, 'Showing 0–0 of 0'), 'Damage date filter before pagination');
    foreach (array_keys($cases) as $page) {
        $empty = render_page($page, ['search' => $marker . '-missing']);
        check(row_count($empty, $page) === 0 && str_contains($empty, 'Showing 0–0 of 0'), "$page empty filtered result");
    }
    check(count(damage_report_fetch($db, damage_report_filters_from_input(['search' => $marker]))) === 12, 'PDF export data fetch remains unbounded');
    check(admin_page_state(12, 999)['page'] === 2 && admin_page_state(0, 999)['from'] === 0 && admin_requested_page('-1') === 1, 'invalid and out-of-range page handling');
} finally {
    foreach ($reportIds as $id) {
        $delete = $db->prepare('DELETE FROM damage_reports WHERE id = ? AND report_code LIKE ?');
        $pattern = $marker . '-%';
        $delete->execute([$id, $pattern]);
    }
    foreach ($farmerIds as [$id, $email]) {
        $delete = $db->prepare('DELETE FROM farmers WHERE id = ? AND email = ?');
        $delete->execute([$id, $email]);
    }
    $verify = $db->prepare('SELECT COUNT(*) AS total FROM farmers WHERE email LIKE ?');
    $pattern = $marker . '-%@agrireach.test';
    $verify->execute([$pattern]);
    check((int) $verify->fetch()['total'] === 0, 'controlled fixtures cleaned up');
}
