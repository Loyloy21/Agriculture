<?php
declare(strict_types=1);

require_once __DIR__ . '/cabadbaran.php';

/** Client metadata is evidence supplied by the app, never proof of authenticity. */
function report_photo_metadata(array $post, int $index): array
{
    $value = static function (string $key) use ($post, $index): string {
        $values = $post[$key] ?? [];
        if (!is_array($values) || !is_scalar($values[$index] ?? '')) throw new InvalidArgumentException('Invalid photo metadata fields.');
        return trim((string) ($values[$index] ?? ''));
    };
    $latText = $value('photo_latitude');
    $lonText = $value('photo_longitude');
    $lat = $latText === '' ? null : filter_var($latText, FILTER_VALIDATE_FLOAT);
    $lon = $lonText === '' ? null : filter_var($lonText, FILTER_VALIDATE_FLOAT);
    if (($latText === '') !== ($lonText === '') || ($lat !== null && ($lat === false || !is_finite($lat) || $lat < -90 || $lat > 90))
        || ($lon !== null && ($lon === false || !is_finite($lon) || $lon < -180 || $lon > 180))
        || ($lat !== null && $lon !== null && $lat == 0.0 && $lon == 0.0)) {
        throw new InvalidArgumentException('A photo contains invalid GPS coordinates.');
    }
    $source = $value('photo_gps_source') ?: ($lat === null ? 'none' : 'legacy_unknown');
    if (!in_array($source, ['photo_exif', 'asset_location', 'device_capture', 'none', 'legacy_unknown'], true)
        || ($source === 'none' && $lat !== null) || (in_array($source, ['photo_exif', 'asset_location', 'device_capture'], true) && $lat === null)) {
        throw new InvalidArgumentException('A photo has inconsistent GPS source metadata.');
    }
    $accuracyText = $value('photo_gps_accuracy');
    $accuracy = $accuracyText === '' ? null : filter_var($accuracyText, FILTER_VALIDATE_FLOAT);
    if ($accuracy !== null && ($accuracy === false || !is_finite($accuracy) || $accuracy < 0 || $accuracy > 1000000)) throw new InvalidArgumentException('A photo has invalid GPS accuracy.');
    $captureSource = $value('photo_capture_time_source') ?: 'none';
    if (!in_array($captureSource, ['exif', 'asset', 'camera_return', 'none', 'legacy_unknown'], true)) throw new InvalidArgumentException('A photo has invalid capture-time source.');
    $capturedAt = report_metadata_timestamp($value('photo_captured_at'));
    $locationTime = report_metadata_timestamp($value('photo_location_timestamp'));
    if (($capturedAt === null) !== ($captureSource === 'none')) throw new InvalidArgumentException('A photo has inconsistent capture-time metadata.');
    $status = $value('photo_location_status') ?: ($lat === null ? 'no_gps' : 'available');
    if (!in_array($status, ['available', 'no_gps', 'permission_denied', 'services_disabled', 'timeout', 'poor_accuracy', 'stale_fix', 'unavailable'], true)) throw new InvalidArgumentException('A photo has invalid location availability.');
    if ($lat === null && in_array($status, ['available', 'poor_accuracy'], true)) throw new InvalidArgumentException('Photo GPS availability does not match its coordinates.');
    if ($lat !== null && !in_array($status, ['available', 'poor_accuracy'], true)) throw new InvalidArgumentException('Photo GPS availability does not match its coordinates.');
    if ($source === 'device_capture') {
        if ($capturedAt === null || $locationTime === null || abs(strtotime($capturedAt . ' UTC') - strtotime($locationTime . ' UTC')) > 30) {
            throw new InvalidArgumentException('The device GPS reading must be within 30 seconds of the camera timestamp. Take a new photo to retry.');
        }
        if ($status === 'available' && ($accuracy === null || $accuracy > 100)) throw new InvalidArgumentException('The device GPS fix needs accuracy within 100 meters.');
    }
    return ['capturedAt' => $capturedAt, 'latitude' => $lat, 'longitude' => $lon, 'gpsSource' => $source,
        'accuracy' => $accuracy, 'locationTimestamp' => $locationTime, 'captureTimeSource' => $captureSource, 'locationStatus' => $status];
}

function report_metadata_timestamp(string $value): ?string
{
    if ($value === '') return null;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D', $value)) throw new InvalidArgumentException('A photo timestamp must include its timezone.');
    try { $date = new DateTimeImmutable($value); } catch (Throwable) { throw new InvalidArgumentException('A photo timestamp is invalid.'); }
    $errors = DateTimeImmutable::getLastErrors();
    if (is_array($errors) && ($errors['warning_count'] || $errors['error_count'])) throw new InvalidArgumentException('A photo timestamp is invalid.');
    if ($date->getTimestamp() > time() + 300 || (int) $date->format('Y') < 1900) throw new InvalidArgumentException('A photo timestamp is outside the supported range.');
    return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

function report_confirmed_location(?float $latitude, ?float $longitude, string $confirmed, string $source, ?int $photoOrder, array $photos): array
{
    if ($confirmed !== '1' || $latitude === null || $longitude === null) throw new InvalidArgumentException('Select and confirm “This is the damaged location” before submitting.');
    if (!is_finite($latitude) || !is_finite($longitude) || abs($latitude) > 90 || abs($longitude) > 180 || ($latitude == 0.0 && $longitude == 0.0)) throw new InvalidArgumentException('The damage coordinates are outside the valid latitude/longitude range.');
    if (!in_array($source, ['photo', 'device', 'manual'], true)) throw new InvalidArgumentException('Choose a valid damage-location source.');
    if ($source === 'photo') {
        $photo = $photoOrder === null ? null : ($photos[$photoOrder - 1] ?? null);
        if (!$photo || $photo['latitude'] === null || $photo['longitude'] === null || !in_array($photo['gpsSource'], ['photo_exif', 'asset_location', 'device_capture'], true)
            || $photo['locationStatus'] !== 'available'
            || abs($photo['latitude'] - $latitude) > 0.00000011 || abs($photo['longitude'] - $longitude) > 0.00000011) {
            throw new InvalidArgumentException('The confirmed photo location must match an attached photo with available GPS. Select that photo and confirm again.');
        }
    } elseif ($photoOrder !== null) throw new InvalidArgumentException('A manual location must not be linked to photo GPS.');
    return cabadbaran_boundary_check($latitude, $longitude);
}

function report_photo_location_label(?string $source, bool $hasCoordinates): string
{
    return match ($source) {
        'photo_exif', 'asset_location' => $hasCoordinates ? 'Photo GPS available' : 'No accessible GPS',
        'device_capture' => $hasCoordinates ? 'Captured with device location' : 'No accessible GPS',
        default => $hasCoordinates ? 'Location source not recorded' : 'No accessible GPS',
    };
}
