<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/farmer_auth.php';
require_once dirname(__DIR__, 3) . '/backend/lib/private_uploads.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    api_response(405, ['success' => false, 'message' => 'Method not allowed.']);
}

$farmer = authenticated_farmer();
$imageId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$imageId) api_response(404, ['success' => false, 'message' => 'Image not found.']);

try {
    // Farmer isolation: only photos attached to this farmer's own reports are returned.
    $absolutePath = report_image_file(db(), (int) $imageId, (int) $farmer['id']);
    if ($absolutePath === null) api_response(404, ['success' => false, 'message' => 'Image not found.']);
    stream_protected_image($absolutePath, null);
} catch (Throwable $exception) {
    error_log('Mobile report image failed: ' . $exception->getMessage());
    api_response(500, ['success' => false, 'message' => 'The report image is temporarily unavailable.']);
}
