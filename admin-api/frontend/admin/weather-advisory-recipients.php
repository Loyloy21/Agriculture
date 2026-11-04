<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/../../backend/lib/weather_advisories.php';
require_admin();

header('Content-Type: application/json; charset=utf-8');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$csrfToken = (string) ($_POST['csrf_token'] ?? '');
if (empty($_SESSION['csrf_token']) || !hash_equals((string) $_SESSION['csrf_token'], $csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Your session token is invalid.']);
    exit;
}

$advisoryId = filter_var($_POST['advisory_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$farmerId = filter_var($_POST['farmer_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($advisoryId !== false || $farmerId !== false) {
    if ($advisoryId === false || $farmerId === false) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Enter a valid controlled farmer ID.']);
        exit;
    }
    try {
        echo json_encode([
            'success' => true,
            'eligibility' => weather_advisory_farmer_eligibility(db(), (int) $advisoryId, (int) $farmerId),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $exception) {
        error_log('Weather advisory farmer eligibility check failed: ' . $exception->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Eligibility could not be checked right now.']);
    }
    exit;
}

$category = (string) ($_POST['agricultural_category'] ?? '');
$scope = (string) ($_POST['coverage_scope'] ?? '');
$codes = array_values(array_unique(array_map('strval', (array) ($_POST['barangay_codes'] ?? []))));
if (!in_array($category, ['Crops', 'Livestock', 'Poultry', 'Fishpond'], true) || !in_array($scope, ['City', 'Barangays'], true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Select a valid category and coverage.']);
    exit;
}

$names = [];
foreach ($codes as $code) {
    $name = cabadbaran_barangay_name($code);
    if ($name === null) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'One or more selected barangays are invalid.']);
        exit;
    }
    $names[] = $name;
}
if ($scope === 'Barangays' && !$codes) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Select at least one barangay.']);
    exit;
}
if ($scope === 'City') { $codes = []; $names = []; }

try {
    echo json_encode(['success' => true, 'count' => weather_advisory_recipient_count(db(), $category, $scope, $codes), 'coverage' => cabadbaran_coverage_label($scope, $names)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $exception) {
    error_log('Weather advisory recipient preview failed: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Recipient count is temporarily unavailable.']);
}
