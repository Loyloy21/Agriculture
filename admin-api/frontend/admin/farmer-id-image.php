<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/../../backend/lib/private_uploads.php';
require_admin();

$farmerId = filter_input(INPUT_GET, 'farmer_id', FILTER_VALIDATE_INT);
$side = strtolower(trim((string) ($_GET['side'] ?? 'front')));
if (!$farmerId || !in_array($side, ['front', 'back'], true)) {
    http_response_code(404);
    exit;
}

try {
    $absolutePath = farmer_id_image_file(db(), (int) $farmerId, $side);
    if ($absolutePath === null) {
        http_response_code(404);
        exit;
    }
    stream_protected_image($absolutePath);
} catch (Throwable $exception) {
    error_log('Admin farmer ID image failed: ' . $exception->getMessage());
    http_response_code(500);
}
