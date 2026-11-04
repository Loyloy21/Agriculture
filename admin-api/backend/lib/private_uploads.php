<?php
declare(strict_types=1);

// Every upload location and lookup lives here. Stored database references are never rewritten:
// report_images.image_path keeps '../storage/report-images/<file>' and farmer_ids keeps its legacy or bare
// filenames; files are always found by basename inside the fixed roots below.
require_once __DIR__ . '/../config/paths.php';

const FARMER_ID_IMAGE_MAX_BYTES = 5 * 1024 * 1024;
const DAMAGE_IMAGE_MAX_BYTES = 5 * 1024 * 1024;
const DAMAGE_IMAGE_MAX_COUNT = 10;
const DAMAGE_IMAGE_TOTAL_MAX_BYTES = 32 * 1024 * 1024;

function farmer_id_private_directory(): string
{
    $configured = trim((string) getenv('AGRIREACH_PRIVATE_ID_DIR'));
    if ($configured !== '') return rtrim($configured, '/\\');

    // XAMPP default: C:\xampp\agrireach-private\farmer-ids, outside htdocs.
    return agrireach_private_dir() . DIRECTORY_SEPARATOR . 'farmer-ids';
}

/** Older farmer ID files inside the project; direct web access is denied by .htaccess. Read-only fallback. */
function legacy_farmer_id_directory(): string
{
    return agrireach_root() . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'ids';
}

/** New damage report photos are written here (web access denied by .htaccess). */
function report_image_storage_directory(): string
{
    return agrireach_root() . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'report-images';
}

/** The reference format stored in report_images.image_path (unchanged since the first release). */
function report_image_stored_path(string $filename): string
{
    return '../storage/report-images/' . $filename;
}

/** @return list<string> Report photo roots, newest first; uploads/reports holds legacy files. */
function report_image_roots(): array
{
    return [report_image_storage_directory(), agrireach_root() . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'reports'];
}

function ensure_private_farmer_id_directory(): string
{
    $directory = farmer_id_private_directory();
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Private farmer ID storage is unavailable.');
    }
    if (!is_writable($directory)) throw new RuntimeException('Private farmer ID storage is not writable.');
    return $directory;
}

function generated_image_filename(string $extension): string
{
    return bin2hex(random_bytes(24)) . '.' . $extension;
}

/** @return array{temporary_path:string,extension:string,mime_type:string,size:int} */
function validate_uploaded_image(?array $upload, int $maximumBytes, string $fieldLabel): array
{
    if (!is_array($upload)) throw new InvalidArgumentException($fieldLabel . ' is required.');
    $error = (int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) throw new InvalidArgumentException($fieldLabel . ' is required.');
    if (in_array($error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
        throw new LengthException($fieldLabel . ' exceeds the server upload limit.');
    }
    if ($error === UPLOAD_ERR_PARTIAL) throw new RuntimeException($fieldLabel . ' was only partially uploaded. Choose it again.');
    if ($error !== UPLOAD_ERR_OK) {
        error_log($fieldLabel . ' upload failed with PHP code ' . $error . '.');
        throw new RuntimeException($fieldLabel . ' could not be stored by the server.');
    }

    $temporaryPath = (string) ($upload['tmp_name'] ?? '');
    $size = (int) ($upload['size'] ?? 0);
    if ($size < 1 || $size > $maximumBytes || !is_uploaded_file($temporaryPath)) {
        throw new LengthException($fieldLabel . ' must be no larger than ' . (int) ($maximumBytes / 1024 / 1024) . 'MB.');
    }
    $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($temporaryPath);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
    if (!is_string($mimeType) || !isset($extensions[$mimeType]) || @getimagesize($temporaryPath) === false) {
        throw new InvalidArgumentException($fieldLabel . ' must be a valid JPG or PNG image.');
    }
    return ['temporary_path' => $temporaryPath, 'extension' => $extensions[$mimeType], 'mime_type' => $mimeType, 'size' => $size];
}

function resolve_farmer_id_image(string $storedReference): string|false
{
    $filename = basename($storedReference);
    if (!preg_match('/^[a-f0-9]{40,64}\.(?:jpg|png)$/', $filename)) return false;
    $roots = [
        farmer_id_private_directory(),
        legacy_farmer_id_directory(),
    ];
    foreach ($roots as $root) {
        $resolvedRoot = realpath($root);
        if ($resolvedRoot === false) continue;
        $candidate = realpath($resolvedRoot . DIRECTORY_SEPARATOR . $filename);
        if ($candidate !== false && str_starts_with($candidate, $resolvedRoot . DIRECTORY_SEPARATOR) && is_file($candidate)) return $candidate;
    }
    return false;
}

/** A farmer's stored ID photo on disk, or null. 'front' falls back to the legacy single-image column. */
function farmer_id_image_file(PDO $connection, int $farmerId, string $side): ?string
{
    $statement = $connection->prepare(
        'SELECT image_path, front_image_path, back_image_path
         FROM farmer_ids WHERE farmer_id = ? LIMIT 1'
    );
    $statement->execute([$farmerId]);
    $row = $statement->fetch();
    if (!$row) return null;
    $storedReference = $side === 'back'
        ? (string) ($row['back_image_path'] ?? '')
        : (string) (($row['front_image_path'] ?? '') ?: ($row['image_path'] ?? ''));
    $absolutePath = $storedReference === '' ? false : resolve_farmer_id_image($storedReference);
    return $absolutePath === false ? null : $absolutePath;
}

/**
 * A damage report photo on disk, or null. With $ownerFarmerId the photo must belong to one of that
 * farmer's reports (mobile API isolation); the admin website passes null.
 */
function report_image_file(PDO $connection, int $imageId, ?int $ownerFarmerId): ?string
{
    if ($ownerFarmerId === null) {
        $statement = $connection->prepare('SELECT image_path FROM report_images WHERE id = ? LIMIT 1');
        $statement->execute([$imageId]);
    } else {
        $statement = $connection->prepare(
            'SELECT ri.image_path FROM report_images ri
             JOIN damage_reports dr ON dr.id = ri.report_id
             WHERE ri.id = ? AND dr.farmer_id = ? LIMIT 1'
        );
        $statement->execute([$imageId, $ownerFarmerId]);
    }
    $row = $statement->fetch();
    return $row ? resolve_report_image((string) $row['image_path']) : null;
}

/** Absolute path of a stored report photo (JPEG/PNG only), or null. Only the basename of the reference is used. */
function resolve_report_image(string $storedPath): ?string
{
    $filename = basename($storedPath);
    if ($filename === '' || $filename === '.' || $filename === '..') {
        return null;
    }
    foreach (report_image_roots() as $root) {
        $resolvedRoot = realpath($root);
        $candidate = $resolvedRoot === false ? false : realpath($resolvedRoot . DIRECTORY_SEPARATOR . $filename);
        if ($resolvedRoot === false || $candidate === false || !str_starts_with($candidate, $resolvedRoot . DIRECTORY_SEPARATOR) || !is_file($candidate)) {
            continue;
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($candidate);
        if (in_array($mime, ['image/jpeg', 'image/png'], true)) {
            return $candidate;
        }
    }
    return null;
}

/** Streams a private JPEG/PNG. $inlineName adds Content-Disposition (ID images send it; report photos never did). */
function stream_protected_image(string $absolutePath, ?string $inlineName = 'verification-image'): never
{
    $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($absolutePath);
    if (!in_array($mimeType, ['image/jpeg', 'image/png'], true)) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . (string) filesize($absolutePath));
    header('Cache-Control: private, no-store');
    header('X-Content-Type-Options: nosniff');
    if ($inlineName !== null) header('Content-Disposition: inline; filename="' . $inlineName . '"');
    readfile($absolutePath);
    exit;
}
