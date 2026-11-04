<?php
declare(strict_types=1);

const CABADBARAN_WEATHER_LOCATION = 'Cabadbaran City, Agusan del Norte';
// Fixed town-centre point for the citywide card (9°7'N, 125°32'E).
const CABADBARAN_WEATHER_LATITUDE = 9.1167;
const CABADBARAN_WEATHER_LONGITUDE = 125.5333;
const CURRENT_WEATHER_CACHE_TTL_SECONDS = 600;
const CURRENT_WEATHER_FORCE_REFRESH_SECONDS = 60;
const CURRENT_WEATHER_STALE_MAX_SECONDS = 7200;

const CURRENT_WEATHER_PROVIDER_BASE = 'https://api.open-meteo.com/v1/forecast';

function current_weather_cache_path(): string
{
    // AGRIREACH_WEATHER_CACHE_DIR is used by controlled tests so mock readings never reach the real cache.
    $configured = trim((string) getenv('AGRIREACH_WEATHER_CACHE_DIR'));
    $directory = $configured !== '' ? rtrim($configured, '/\\') :rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR);
    return $directory . DIRECTORY_SEPARATOR . 'agrireach-cabadbaran-current-weather.json';
}

/**
 * Provider endpoint. AGRIREACH_WEATHER_BASE_URL exists only so controlled tests can point the app
 * at a local mock provider; it is ignored unless its host is loopback, so it cannot redirect
 * production traffic or farmer coordinates to another machine.
 */
function current_weather_provider_base(): string
{
    $override = trim((string) getenv('AGRIREACH_WEATHER_BASE_URL'));
    if ($override !== '') {
        $host = parse_url($override, PHP_URL_HOST);
        if (in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) return $override;
    }
    return CURRENT_WEATHER_PROVIDER_BASE;
}

/** @return array{0:string|false,1:int,2:string} body, HTTP status, transport error */
function current_weather_http_get(string $url, int $connectTimeoutSeconds, int $timeoutSeconds): array
{
    $curl = curl_init($url);
    if ($curl === false) return [false, 0, 'Weather provider connection could not be initialized.'];
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => $connectTimeoutSeconds,
        CURLOPT_TIMEOUT => $timeoutSeconds,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_USERAGENT => 'AgriReach/1.0 current-weather',
    ]);
    $raw = curl_exec($curl);
    $error = curl_error($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    curl_close($curl);
    return [$raw, $status, $error];
}

function current_weather_condition(int $code): string
{
    return match ($code) {
        0 => 'Clear sky',
        1 => 'Mainly clear',
        2 => 'Partly cloudy',
        3 => 'Overcast',
        45, 48 => 'Foggy',
        51, 53, 55 => 'Drizzle',
        56, 57 => 'Freezing drizzle',
        61, 63, 65 => 'Rain',
        66, 67 => 'Freezing rain',
        71, 73, 75 => 'Snowfall',
        77 => 'Snow grains',
        80, 81, 82 => 'Rain showers',
        85, 86 => 'Snow showers',
        95 => 'Thunderstorm',
        96, 99 => 'Thunderstorm with hail',
        default => 'Current conditions',
    };
}

function current_weather_number(array $values, string $key): ?float
{
    return array_key_exists($key, $values) && is_numeric($values[$key]) ? (float) $values[$key] : null;
}

function current_weather_read_cache(): ?array
{
    $path = current_weather_cache_path();
    if (!is_file($path)) return null;
    $raw = file_get_contents($path);
    if (!is_string($raw) || $raw === '') return null;
    try {
        $cache = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return null;
    }
    if (!is_array($cache) || !isset($cache['cachedAt'], $cache['data']) || !is_int($cache['cachedAt']) || !is_array($cache['data'])) return null;
    return $cache;
}

function current_weather_write_cache(array $data): void
{
    $payload = json_encode(['cachedAt' => time(), 'data' => $data], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $path = current_weather_cache_path();
    if (file_put_contents($path, $payload, LOCK_EX) === false) throw new RuntimeException('Weather cache could not be written.');
}

function current_weather_public_cache(array $cache, bool $stale, string $message = ''): array
{
    $ageSeconds = max(0, time() - (int) $cache['cachedAt']);
    return array_merge($cache['data'], [
        'isStale' => $stale,
        'cacheAgeMinutes' => (int) floor($ageSeconds / 60),
        'statusMessage' => $message,
    ]);
}

function fetch_cabadbaran_current_weather(): array
{
    $query = http_build_query([
        'latitude' => CABADBARAN_WEATHER_LATITUDE,
        'longitude' => CABADBARAN_WEATHER_LONGITUDE,
        'current' => 'temperature_2m,relative_humidity_2m,precipitation,rain,weather_code,wind_speed_10m,wind_direction_10m,is_day',
        'temperature_unit' => 'celsius',
        'wind_speed_unit' => 'kmh',
        'precipitation_unit' => 'mm',
        'timezone' => 'Asia/Manila',
        'forecast_days' => 1,
    ], '', '&', PHP_QUERY_RFC3986);
    [$raw, $status, $curlError] = current_weather_http_get(current_weather_provider_base() . '?' . $query, 4, 10);
    if (!is_string($raw) || $raw === '') throw new RuntimeException('Weather provider connection failed: ' . ($curlError !== '' ? $curlError : 'empty response'));
    if ($status === 429) throw new RuntimeException('Weather provider rate limit reached.');
    if ($status < 200 || $status >= 300) throw new RuntimeException('Weather provider returned HTTP ' . $status . '.');

    try {
        $provider = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new RuntimeException('Weather provider returned invalid JSON.');
    }
    if (!is_array($provider) || !isset($provider['current']) || !is_array($provider['current'])) {
        throw new RuntimeException('Weather provider response is missing current conditions.');
    }
    $current = $provider['current'];
    $units = isset($provider['current_units']) && is_array($provider['current_units']) ? $provider['current_units'] : [];
    if (!isset($current['time']) || !is_string($current['time']) || !isset($current['weather_code']) || !is_numeric($current['weather_code'])) {
        throw new RuntimeException('Weather provider response is missing observation time or condition.');
    }
    try {
        $observedAt = new DateTimeImmutable($current['time'], new DateTimeZone('Asia/Manila'));
    } catch (Throwable) {
        throw new RuntimeException('Weather provider returned an invalid observation time.');
    }

    $temperature = current_weather_number($current, 'temperature_2m');
    $humidity = current_weather_number($current, 'relative_humidity_2m');
    $precipitation = current_weather_number($current, 'precipitation');
    $rain = current_weather_number($current, 'rain');
    $windSpeed = current_weather_number($current, 'wind_speed_10m');
    $windDirection = current_weather_number($current, 'wind_direction_10m');
    if ($temperature === null && $humidity === null && $precipitation === null && $rain === null && $windSpeed === null) {
        throw new RuntimeException('Weather provider response contains no usable current values.');
    }
    $weatherCode = (int) $current['weather_code'];
    return [
        'location' => CABADBARAN_WEATHER_LOCATION,
        'provider' => 'Open-Meteo',
        'observationType' => 'Current model conditions',
        'observedAt' => $observedAt->format(DATE_ATOM),
        'fetchedAt' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format(DATE_ATOM),
        'condition' => current_weather_condition($weatherCode),
        'weatherCode' => $weatherCode,
        'isDay' => isset($current['is_day']) && is_numeric($current['is_day']) ? (int) $current['is_day'] === 1 : null,
        'temperature' => $temperature,
        'temperatureUnit' => (string) ($units['temperature_2m'] ?? '°C'),
        'humidity' => $humidity,
        'humidityUnit' => (string) ($units['relative_humidity_2m'] ?? '%'),
        'precipitation' => $precipitation,
        'precipitationUnit' => (string) ($units['precipitation'] ?? 'mm'),
        'rain' => $rain,
        'rainUnit' => (string) ($units['rain'] ?? 'mm'),
        'windSpeed' => $windSpeed,
        'windSpeedUnit' => (string) ($units['wind_speed_10m'] ?? 'km/h'),
        'windDirection' => $windDirection,
        'windDirectionUnit' => (string) ($units['wind_direction_10m'] ?? '°'),
    ];
}

function get_cabadbaran_current_weather(bool $forceRefresh = false): array
{
    $cache = current_weather_read_cache();
    $cacheAge = $cache ? max(0, time() - (int) $cache['cachedAt']) : PHP_INT_MAX;
    $freshFor = $forceRefresh ? CURRENT_WEATHER_FORCE_REFRESH_SECONDS : CURRENT_WEATHER_CACHE_TTL_SECONDS;
    if ($cache && $cacheAge < $freshFor) {
        $message = $forceRefresh ? 'The latest cached provider result is still within the refresh interval.' : '';
        return current_weather_public_cache($cache, false, $message);
    }

    try {
        $weather = fetch_cabadbaran_current_weather();
        current_weather_write_cache($weather);
        return array_merge($weather, ['isStale' => false, 'cacheAgeMinutes' => 0, 'statusMessage' => '']);
    } catch (Throwable $exception) {
        error_log('Current Cabadbaran weather fetch failed: ' . $exception->getMessage());
        if ($cache && $cacheAge <= CURRENT_WEATHER_STALE_MAX_SECONDS) {
            $minutes = max(1, (int) ceil($cacheAge / 60));
            return current_weather_public_cache($cache, true, "Weather provider unavailable. Showing cached data from {$minutes} minute(s) ago.");
        }
        throw new RuntimeException('Current weather is temporarily unavailable.');
    }
}
