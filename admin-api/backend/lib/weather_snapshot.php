<?php
declare(strict_types=1);

require_once __DIR__ . '/weather_point.php';
require_once __DIR__ . '/weather_decision.php';

/** Column names and placeholders are built from the same array so long INSERTs cannot get out of step. */
function weather_snapshot_insert(PDO $connection, int $reportId, array $columns): void
{
    $columns = ['report_id' => $reportId] + $columns;
    $sql = 'INSERT INTO weather_data (' . implode(', ', array_keys($columns)) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '?')) . ')';
    $statement = $connection->prepare($sql);
    $statement->execute(array_values($columns));
}

function weather_snapshot_utc(string $isoTime): ?string
{
    $timestamp = strtotime($isoTime);
    return $timestamp === false ? null : gmdate('Y-m-d H:i:s', $timestamp);
}

/**
 * Record (never fabricate) the weather at a newly submitted report's confirmed damage point.
 *
 * Always returns a result and never throws: a failed provider call is stored as an "Unavailable"
 * snapshot with its reason, and a failed database write is only logged, so the report itself is
 * never lost. Stale cache is NOT used: a snapshot must describe conditions at submission time.
 *
 * @return array{status:string,reason:?string}
 */
function weather_snapshot_record(PDO $connection, int $reportId, float $latitude, float $longitude, string $category, DateTimeImmutable $submittedAt): array
{
    $submittedUtc = $submittedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $attemptUtc = gmdate('Y-m-d H:i:s');
    try {
        $location = weather_point_resolve_location($latitude, $longitude, 'damage_point');
        if (!$location['ok']) {
            $reasonCode = (string) $location['reasonCode'];
            $reason = (string) $location['reason'];
            $observation = null;
        } else {
            $result = weather_point_observation((float) $location['latitude'], (float) $location['longitude'], ['allowStale' => false, 'connectTimeout' => 3, 'timeout' => 6]);
            $observation = $result['status'] === 'current' ? $result['observation'] : null;
            $reasonCode = (string) ($result['reasonCode'] ?? 'provider_invalid_response');
            $reason = (string) ($result['reason'] ?? ($result['message'] !== '' ? $result['message'] : weather_point_failure_message('provider_invalid_response')));
        }

        if ($observation === null) {
            weather_snapshot_insert($connection, $reportId, [
                'snapshot_status' => 'Unavailable',
                'unavailable_reason' => mb_substr($reason, 0, 255),
                'unavailable_code' => mb_substr($reasonCode, 0, 40),
                'provider' => 'Open-Meteo',
                'requested_latitude' => $latitude,
                'requested_longitude' => $longitude,
                'location_basis' => 'damage_point',
                'retrieved_at_utc' => $attemptUtc,
                'report_submitted_at_utc' => $submittedUtc,
            ]);
            return ['status' => 'Unavailable', 'reason' => $reason];
        }

        $advice = weather_decision_advice($observation, $category);
        $hourly = $observation['hourlyPrecipitation'];
        $latestHourly = $hourly ? (float) end($hourly)['precipitationMm'] : null;
        $wind = null;
        if ($observation['windSpeed'] !== null) {
            $wind = weather_format_number((float) $observation['windSpeed']) . ' km/h'
                . ($observation['windDirection'] !== null ? ' from ' . (int) round((float) $observation['windDirection']) . '°' : '')
                . ($observation['windGusts'] !== null ? ' (gusts ' . weather_format_number((float) $observation['windGusts']) . ' km/h)' : '');
        }
        $observedUtc = weather_snapshot_utc((string) $observation['observedAt']);
        $recordedLocal = (new DateTimeImmutable((string) $observation['observedAt']))->setTimezone(new DateTimeZone('Asia/Manila'))->format('Y-m-d H:i:s');
        $columns = [
            'snapshot_status' => 'Available',
            'provider' => (string) $observation['provider'],
            'observation_type' => (string) $observation['observationType'],
            'requested_latitude' => $latitude,
            'requested_longitude' => $longitude,
            'location_basis' => 'damage_point',
            'observed_at_utc' => $observedUtc,
            'retrieved_at_utc' => weather_snapshot_utc((string) $observation['fetchedAt']),
            'report_submitted_at_utc' => $submittedUtc,
            'recorded_at' => $recordedLocal,
            'weather_condition' => (string) $observation['condition'],
            'weather_code' => (int) $observation['weatherCode'],
            'units_json' => json_encode($observation['units'] + ['precipitationIntervalSeconds' => $observation['precipitationIntervalSeconds'], 'endpoint' => $observation['endpoint']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'rule_set_version' => WEATHER_RULE_SET_VERSION,
            'rule_category' => $category,
            'rule_result_json' => json_encode($advice, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
        // Nullable numerics are only bound when the provider actually supplied them (no placeholder values).
        $optional = [
            'provider_latitude' => $observation['providerLatitude'], 'provider_longitude' => $observation['providerLongitude'],
            'temperature' => $observation['temperature'], 'apparent_temperature' => $observation['apparentTemperature'],
            'humidity' => $observation['humidity'], 'rainfall' => $observation['precipitation'],
            'precipitation_mm' => $latestHourly, 'precipitation_last_6h_mm' => $observation['precipitationLast6h'],
            'wind_speed_kmh' => $observation['windSpeed'], 'wind_gust_kmh' => $observation['windGusts'],
            'wind_direction_deg' => $observation['windDirection'] === null ? null : (int) round((float) $observation['windDirection']),
            'wind_information' => $wind,
        ];
        foreach ($optional as $name => $value) {
            if ($value !== null) $columns[$name] = is_int($value) ? $value : (is_numeric($value) ? (float) $value : (string) $value);
        }
        weather_snapshot_insert($connection, $reportId, $columns);
        return ['status' => 'Available', 'reason' => null];
    } catch (Throwable $exception) {
        // The report is already committed; never let snapshot bookkeeping break the submission.
        error_log('Weather snapshot could not be recorded for a report: ' . get_class($exception) . ' ' . $exception->getMessage());
        return ['status' => 'Failed', 'reason' => 'The weather snapshot could not be saved.'];
    }
}
