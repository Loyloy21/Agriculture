<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/../../backend/lib/private_uploads.php';
require_admin();

$imageId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$imageId) {
    http_response_code(404);
    exit;
}

try {
    $absolutePath = report_image_file(db(), (int) $imageId, null);
    if ($absolutePath === null) {
        http_response_code(404);
        exit;
    }
    stream_protected_image($absolutePath, null);
} catch (Throwable $exception) {
    error_log('Admin report image failed: ' . $exception->getMessage());
    http_response_code(500);
}
