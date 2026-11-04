<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/farmer_auth.php';
require_once dirname(__DIR__, 3) . '/backend/lib/private_uploads.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    api_response(405, ['success' => false, 'message' => 'Method not allowed.']);
}

$farmer = authenticated_farmer();
$side = strtolower(trim((string) ($_GET['side'] ?? 'front')));
if (!in_array($side, ['front', 'back'], true)) {
    api_response(404, ['success' => false, 'message' => 'Image not found.']);
}

try {
    $absolutePath = farmer_id_image_file(db(), (int) $farmer['id'], $side);
    if ($absolutePath === null) api_response(404, ['success' => false, 'message' => 'Image not found.']);
    stream_protected_image($absolutePath);
} catch (Throwable $exception) {
    error_log('Farmer ID owner image failed: ' . $exception->getMessage());
    api_response(500, ['success' => false, 'message' => 'The verification image is temporarily unavailable.']);
}
