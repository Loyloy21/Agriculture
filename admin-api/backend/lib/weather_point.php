<?php
declare(strict_types=1);

require_once __DIR__ . '/cabadbaran.php';
require_once __DIR__ . '/current_weather.php';

/**
 * Point-based weather observations (a farm pin or a confirmed damage point) from Open-Meteo.
 *
 * Honesty rules enforced here:
 *  - Open-Meteo "current" values are MODEL conditions for the provider grid cell, not a station
 *    measurement. Every observation records the coordinates requested AND the provider grid point.
 *  - A reading is "current" only if it was retrieved within WEATHER_POINT_CACHE_TTL_SECONDS and the
 *    provider's own observation time is no older than WEATHER_POINT_OBSERVATION_MAX_AGE_SECONDS.
 *    Anything older that is still shown is labelled "stale" with its age; beyond
 *    WEATHER_POINT_STALE_MAX_SECONDS it is never shown.
 *  - No values are ever invented. Failures return status "unavailable" with a reason.
 */
const WEATHER_POINT_CACHE_TTL_SECONDS = 600;
const WEATHER_POINT_FORCE_REFRESH_SECONDS = 60;
const WEATHER_POINT_OBSERVATION_MAX_AGE_SECONDS = 3600;
const WEATHER_POINT_STALE_MAX_SECONDS = 7200;
const WEATHER_POINT_HOURLY_WINDOW = 6;

final class WeatherProviderException extends RuntimeException
{
    public function __construct(public readonly string $reasonCode, public readonly string $userMessage, string $detail = '')
    {
        parent::__construct($detail !== '' ? $detail : $userMessage);
    }
}

/** Public, farmer-safe wording for each failure code. */
function weather_point_failure_message(string $code, int $httpStatus = 0): string
{
    return match ($code) {
        'provider_timeout' => 'The weather service did not respond in time.',
        'provider_unreachable' => 'The weather service could not be reached.',
        'provider_rate_limited' => 'The weather service is temporarily limiting requests.',
        'provider_http_error' => 'The weather service returned an error' . ($httpStatus > 0 ? ' (HTTP ' . $httpStatus . ')' : '') . '.',
        'provider_invalid_response' => 'The weather service returned data that could not be used.',
        'location_outside_city' => 'The selected point is outside Cabadbaran City, so no weather was requested.',
        'location_invalid' => 'The selected coordinates are invalid, so no weather was requested.',
        'boundary_unavailable' => 'The Cabadbaran boundary check is unavailable, so no weather was requested.',
        default => 'Weather data is unavailable.',
    };
}

function weather_point_cache_directory(): string
{
    $configured = trim((string) getenv('AGRIREACH_WEATHER_CACHE_DIR'));
    $base = $configured !== '' ? rtrim($configured, '/\\') : rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'agrireach-weather-points';
    return $base;
}

/** Cache key rounds to 0.01 degree (~1 km), finer than the provider's own grid. */
function weather_point_cache_path(float $latitude, float $longitude): string
{
    return weather_point_cache_directory() . DIRECTORY_SEPARATOR . sha1(sprintf('%.2f,%.2f|%s', $latitude, $longitude, current_weather_provider_base())) . '.json';
}

function weather_point_read_cache(float $latitude, float $longitude): ?array
{
    $path = weather_point_cache_path($latitude, $longitude);
    if (!is_file($path)) return null;
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') return null;
    try {
        $cache = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return null;
    }
    if (!is_array($cache) || !is_int($cache['cachedAt'] ?? null) || !is_array($cache['observation'] ?? null)) return null;
    return $cache;
}

function weather_point_write_cache(float $latitude, float $longitude, array $observation): void
{
    $directory = weather_point_cache_directory();
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) return;
    $payload = json_encode(['cachedAt' => time(), 'observation' => $observation], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (is_string($payload)) @file_put_contents(weather_point_cache_path($latitude, $longitude), $payload, LOCK_EX);
    // Opportunistic housekeeping: drop cache files older than a day.
    if (random_int(1, 50) === 1) {
        foreach (glob($directory . DIRECTORY_SEPARATOR . '*.json') ?: [] as $file) {
            if (@filemtime($file) < time() - 86400) @unlink($file);
        }
    }
}

/**
 * Decide which coordinates may be sent to the provider.
 * @return array{ok:bool,latitude:?float,longitude:?float,basis:string,reasonCode:?string,reason:?string}
 */
function weather_point_resolve_location(?float $latitude, ?float $longitude, string $pinBasis = 'farm_pin'): array
{
    if ($latitude === null || $longitude === null) {
        // No pin: the barangay is known but not its coordinates. Use the city-centre point and say so.
        return ['ok' => true, 'latitude' => CABADBARAN_WEATHER_LATITUDE, 'longitude' => CABADBARAN_WEATHER_LONGITUDE, 'basis' => 'city_centre', 'reasonCode' => null, 'reason' => null];
    }
    if (!is_finite($latitude) || !is_finite($longitude) || abs($latitude) > 90 || abs($longitude) > 180 || ($latitude == 0.0 && $longitude == 0.0)) {
        return ['ok' => false, 'latitude' => null, 'longitude' => null, 'basis' => $pinBasis, 'reasonCode' => 'location_invalid', 'reason' => weather_point_failure_message('location_invalid')];
    }
    $boundary = cabadbaran_boundary_check($latitude, $longitude);
    if ($boundary['status'] === 'unavailable') {
        return ['ok' => false, 'latitude' => null, 'longitude' => null, 'basis' => $pinBasis, 'reasonCode' => 'boundary_unavailable', 'reason' => weather_point_failure_message('boundary_unavailable')];
    }
    if ($boundary['inside'] !== true) {
        return ['ok' => false, 'latitude' => null, 'longitude' => null, 'basis' => $pinBasis, 'reasonCode' => 'location_outside_city', 'reason' => weather_point_failure_message('location_outside_city')];
    }
    return ['ok' => true, 'latitude' => $latitude, 'longitude' => $longitude, 'basis' => $pinBasis, 'reasonCode' => null, 'reason' => null];
}

/** Great-circle distance in kilometres. */
function weather_point_distance_km(float $latitudeA, float $longitudeA, float $latitudeB, float $longitudeB): float
{
    $dLat = deg2rad($latitudeB - $latitudeA);
    $dLon = deg2rad($longitudeB - $longitudeA);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($latitudeA)) * cos(deg2rad($latitudeB)) * sin($dLon / 2) ** 2;
    return 6371.0 * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

/**
 * What the reading actually represents: a model grid cell, how far it is from the requested point, and its elevation.
 * Computed from stored coordinates so older cached readings work too. Never invents a distance.
 *
 * @return array{representsModelGridCell:bool,distanceKm:?float,elevationM:?int,notes:array}
 */
function weather_point_grid_summary(array $observation): array
{
    $distance = null;
    foreach (['requestedLatitude', 'requestedLongitude', 'providerLatitude', 'providerLongitude'] as $key) {
        if (!isset($observation[$key]) || !is_numeric($observation[$key])) { $distance = false; break; }
    }
    if ($distance === null) {
        $distance = round(weather_point_distance_km((float) $observation['requestedLatitude'], (float) $observation['requestedLongitude'], (float) $observation['providerLatitude'], (float) $observation['providerLongitude']), 1);
    }
    $distance = $distance === false ? null : $distance;
    $elevation = isset($observation['providerElevation']) && is_numeric($observation['providerElevation']) ? (int) round((float) $observation['providerElevation']) : null;
    $notes = [];
    if ($distance !== null && $distance >= 3.0) $notes[] = 'At this distance (' . weather_format_distance($distance) . ') local conditions at the farm can differ from the grid cell.';
    if ($elevation !== null && $elevation >= 200) $notes[] = 'The grid cell is at about ' . $elevation . ' m elevation, so temperatures can differ from lowland farms.';
    return ['representsModelGridCell' => true, 'distanceKm' => $distance, 'elevationM' => $elevation, 'notes' => $notes];
}

function weather_format_distance(float $km): string
{
    return rtrim(rtrim(number_format($km, 1, '.', ''), '0'), '.') . ' km';
}

function weather_point_number(array $values, string $key, float $minimum, float $maximum): ?float
{
    if (!array_key_exists($key, $values) || $values[$key] === null) return null;
    if (!is_numeric($values[$key])) throw new WeatherProviderException('provider_invalid_response', weather_point_failure_message('provider_invalid_response'), "Non-numeric {$key}");
    $number = (float) $values[$key];
    if (!is_finite($number) || $number < $minimum || $number > $maximum) {
        throw new WeatherProviderException('provider_invalid_response', weather_point_failure_message('provider_invalid_response'), "Implausible {$key}");
    }
    return $number;
}

/** Fetch and normalise one observation. Throws WeatherProviderException. */
function weather_point_fetch(float $latitude, float $longitude, int $connectTimeout, int $timeout): array
{
    $query = http_build_query([
        'latitude' => round($latitude, 5),
        'longitude' => round($longitude, 5),
        'current' => 'temperature_2m,relative_humidity_2m,apparent_temperature,precipitation,rain,weather_code,wind_speed_10m,wind_direction_10m,wind_gusts_10m,is_day',
        'hourly' => 'precipitation',
        'past_hours' => WEATHER_POINT_HOURLY_WINDOW,
        'forecast_hours' => 1,
        'temperature_unit' => 'celsius',
        'wind_speed_unit' => 'kmh',
        'precipitation_unit' => 'mm',
        'timezone' => 'Asia/Manila',
    ], '', '&', PHP_QUERY_RFC3986);
    $base = current_weather_provider_base();
    [$raw, $status, $transportError] = current_weather_http_get($base . '?' . $query, $connectTimeout, $timeout);

    if (!is_string($raw) || $raw === '') {
        $timedOut = stripos($transportError, 'timed out') !== false || stripos($transportError, 'timeout') !== false;
        $code = $timedOut ? 'provider_timeout' : 'provider_unreachable';
        throw new WeatherProviderException($code, weather_point_failure_message($code), 'Transport: ' . ($transportError !== '' ? $transportError : 'empty response'));
    }
    if ($status === 429) throw new WeatherProviderException('provider_rate_limited', weather_point_failure_message('provider_rate_limited'));
    if ($status < 200 || $status >= 300) throw new WeatherProviderException('provider_http_error', weather_point_failure_message('provider_http_error', $status), 'HTTP ' . $status);

    try {
        $provider = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new WeatherProviderException('provider_invalid_response', weather_point_failure_message('provider_invalid_response'), 'Invalid JSON');
    }
    if (!is_array($provider) || !is_array($provider['current'] ?? null)) {
        throw new WeatherProviderException('provider_invalid_response', weather_point_failure_message('provider_invalid_response'), 'Missing current block');
    }
    $current = $provider['current'];
    $units = is_array($provider['current_units'] ?? null) ? $provider['current_units'] : [];
    if (!is_string($current['time'] ?? null) || !is_numeric($current['weather_code'] ?? null)) {
        throw new WeatherProviderException('provider_invalid_response', weather_point_failure_message('provider_invalid_response'), 'Missing time or weather code');
    }
    try {
        $observedAt = new DateTimeImmutable($current['time'], new DateTimeZone('Asia/Manila'));
    } catch (Throwable) {
        throw new WeatherProviderException('provider_invalid_response', weather_point_failure_message('provider_invalid_response'), 'Bad observation time');
    }

    $temperature = weather_point_number($current, 'temperature_2m', -20.0, 60.0);
    $apparent = weather_point_number($current, 'apparent_temperature', -40.0, 80.0);
    $humidity = weather_point_number($current, 'relative_humidity_2m', 0.0, 100.0);
    $precipitation = weather_point_number($current, 'precipitation', 0.0, 500.0);
    $rain = weather_point_number($current, 'rain', 0.0, 500.0);
    $windSpeed = weather_point_number($current, 'wind_speed_10m', 0.0, 400.0);
    $windGusts = weather_point_number($current, 'wind_gusts_10m', 0.0, 500.0);
    $windDirection = weather_point_number($current, 'wind_direction_10m', 0.0, 360.0);
    if ($temperature === null && $humidity === null && $precipitation === null && $windSpeed === null) {
        throw new WeatherProviderException('provider_invalid_response', weather_point_failure_message('provider_invalid_response'), 'No usable values');
    }

    // Hourly precipitation: keep only hours up to the observation time (the provider may append a forecast hour).
    $hourly = [];
    $hourlyTimes = $provider['hourly']['time'] ?? null;
    $hourlyValues = $provider['hourly']['precipitation'] ?? null;
    if (is_array($hourlyTimes) && is_array($hourlyValues)) {
        foreach ($hourlyTimes as $index => $time) {
            $value = $hourlyValues[$index] ?? null;
            if (!is_string($time) || strcmp($time, $current['time']) > 0 || !is_numeric($value) || (float) $value < 0 || (float) $value > 500) continue;
            $hourly[] = ['time' => (new DateTimeImmutable($time, new DateTimeZone('Asia/Manila')))->format(DATE_ATOM), 'precipitationMm' => round((float) $value, 2)];
        }
        $hourly = array_slice($hourly, -WEATHER_POINT_HOURLY_WINDOW);
    }
    $sixHourTotal = count($hourly) >= 3 ? round(array_sum(array_column($hourly, 'precipitationMm')), 2) : null;
    $weatherCode = (int) $current['weather_code'];

    return [
        'provider' => 'Open-Meteo',
        'observationType' => 'Current model conditions',
        'endpoint' => (string) parse_url($base, PHP_URL_HOST) . (string) parse_url($base, PHP_URL_PATH),
        'requestedLatitude' => round($latitude, 6),
        'requestedLongitude' => round($longitude, 6),
        'providerLatitude' => is_numeric($provider['latitude'] ?? null) ? round((float) $provider['latitude'], 6) : null,
        'providerLongitude' => is_numeric($provider['longitude'] ?? null) ? round((float) $provider['longitude'], 6) : null,
        'providerElevation' => is_numeric($provider['elevation'] ?? null) ? round((float) $provider['elevation'], 0) : null,
        'observedAt' => $observedAt->format(DATE_ATOM),
        'fetchedAt' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format(DATE_ATOM),
        'condition' => current_weather_condition($weatherCode),
        'weatherCode' => $weatherCode,
        'isDay' => is_numeric($current['is_day'] ?? null) ? (int) $current['is_day'] === 1 : null,
        'temperature' => $temperature,
        'apparentTemperature' => $apparent,
        'humidity' => $humidity,
        'precipitation' => $precipitation,
        'precipitationIntervalSeconds' => is_numeric($current['interval'] ?? null) && (int) $current['interval'] > 0 ? (int) $current['interval'] : 900,
        'rain' => $rain,
        'windSpeed' => $windSpeed,
        'windGusts' => $windGusts,
        'windDirection' => $windDirection,
        'hourlyPrecipitation' => $hourly,
        'precipitationLast6h' => $sixHourTotal,
        'units' => [
            'temperature' => (string) ($units['temperature_2m'] ?? '°C'),
            'humidity' => (string) ($units['relative_humidity_2m'] ?? '%'),
            'precipitation' => (string) ($units['precipitation'] ?? 'mm'),
            'windSpeed' => (string) ($units['wind_speed_10m'] ?? 'km/h'),
            'windDirection' => (string) ($units['wind_direction_10m'] ?? '°'),
        ],
    ];
}

/**
 * Obtain a weather observation for validated coordinates, labelling freshness honestly.
 *
 * Options: allowStale (default true; report snapshots pass false), forceRefresh, connectTimeout, timeout.
 *
 * @return array{status:string,observation:?array,observationAgeMinutes:?int,cacheAgeMinutes:?int,reasonCode:?string,reason:?string,message:string}
 */
function weather_point_observation(float $latitude, float $longitude, array $options = []): array
{
    $allowStale = ($options['allowStale'] ?? true) === true;
    $forceRefresh = ($options['forceRefresh'] ?? false) === true;
    $connectTimeout = max(1, min(10, (int) ($options['connectTimeout'] ?? 3)));
    $timeout = max(2, min(20, (int) ($options['timeout'] ?? 8)));
    $now = time();

    $result = static function (string $status, ?array $observation, ?int $cacheAge, ?string $code, ?string $reason, string $message): array {
        $observationAge = null;
        if ($observation !== null) {
            $observedTimestamp = strtotime((string) $observation['observedAt']);
            $observationAge = $observedTimestamp === false ? null : max(0, (int) floor((time() - $observedTimestamp) / 60));
        }
        return ['status' => $status, 'observation' => $observation, 'observationAgeMinutes' => $observationAge, 'cacheAgeMinutes' => $cacheAge, 'reasonCode' => $code, 'reason' => $reason, 'message' => $message];
    };
    $observationIsRecent = static function (array $observation): bool {
        $observedTimestamp = strtotime((string) ($observation['observedAt'] ?? ''));
        return $observedTimestamp !== false && (time() - $observedTimestamp) <= WEATHER_POINT_OBSERVATION_MAX_AGE_SECONDS;
    };

    $cache = weather_point_read_cache($latitude, $longitude);
    $cacheAge = $cache === null ? null : max(0, $now - (int) $cache['cachedAt']);
    $reuseWindow = $forceRefresh ? WEATHER_POINT_FORCE_REFRESH_SECONDS : WEATHER_POINT_CACHE_TTL_SECONDS;
    if ($cache !== null && $cacheAge < $reuseWindow && $observationIsRecent($cache['observation'])) {
        return $result('current', $cache['observation'], (int) floor($cacheAge / 60), null, null, '');
    }

    try {
        $observation = weather_point_fetch($latitude, $longitude, $connectTimeout, $timeout);
        weather_point_write_cache($latitude, $longitude, $observation);
        if (!$observationIsRecent($observation)) {
            // The provider answered, but its own observation time is old: never call that "current".
            $stale = $result('stale', $observation, 0, null, null, '');
            $stale['message'] = 'The weather service returned a reading that is ' . (int) $stale['observationAgeMinutes'] . ' minutes old. It is not current.';
            return $stale;
        }
        return $result('current', $observation, 0, null, null, '');
    } catch (WeatherProviderException $exception) {
        error_log('Point weather fetch failed (' . $exception->reasonCode . '): ' . $exception->getMessage());
        if ($allowStale && $cache !== null && $cacheAge <= WEATHER_POINT_STALE_MAX_SECONDS) {
            $stale = $result('stale', $cache['observation'], (int) floor($cacheAge / 60), $exception->reasonCode, $exception->userMessage, '');
            $stale['message'] = $exception->userMessage . ' Showing the last reading retrieved ' . max(1, (int) ceil($cacheAge / 60)) . ' minute(s) ago. It is not current.';
            return $stale;
        }
        return $result('unavailable', null, $cacheAge === null ? null : (int) floor($cacheAge / 60), $exception->reasonCode, $exception->userMessage, $exception->userMessage);
    } catch (Throwable $exception) {
        error_log('Point weather unexpected failure: ' . get_class($exception));
        $message = weather_point_failure_message('provider_invalid_response');
        return $result('unavailable', null, null, 'provider_invalid_response', $message, $message);
    }
}
