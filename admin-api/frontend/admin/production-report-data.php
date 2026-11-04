<?php
declare(strict_types=1);

// Searchable farmer / farm area options for the Production Report filters (admin session only, read-only).
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/../../backend/lib/admin_production.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

if (!is_admin_logged_in()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Your admin session expired. Log in again.']);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}
// The session is only read: release it so parallel lookups do not wait on each other.
session_write_close();

$lookup = is_string($_GET['lookup'] ?? null) ? $_GET['lookup'] : '';
$query = is_string($_GET['q'] ?? null) ? mb_substr(trim($_GET['q']), 0, 100) : '';
$farmerId = production_positive_int(is_string($_GET['farmer_id'] ?? null) ? $_GET['farmer_id'] : null);
if (!in_array($lookup, ['farmers', 'farms'], true)) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Choose a valid lookup.']);
    exit;
}

try {
    $connection = db();
    $options = $lookup === 'farmers' ? admin_production_farmer_options($connection, $query) : admin_production_farm_options($connection, $query, $farmerId);
    echo json_encode(['success' => true, 'data' => $options], JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $exception) {
    error_log('Production report lookup failed: ' . $exception->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'The list could not be loaded. Please try again.']);
}
