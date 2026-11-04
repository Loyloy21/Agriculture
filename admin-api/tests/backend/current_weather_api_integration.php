<?php
declare(strict_types=1);

require_once __DIR__ . '/../support/test_base_url.php';

require_once __DIR__ . '/../../backend/config/database.php';

function weather_api_expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$connection = db();
$farmerId = null;
try {
    $suffix = bin2hex(random_bytes(5));
    $name = 'Controlled Weather Test';
    $phone = '0996' . str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT);
    $email = "current-weather-{$suffix}@example.test";
    $address = 'Controlled API test address';
    $insertFarmer = $connection->prepare("INSERT INTO farmers (full_name, contact_number, email, address, account_status, verification_status) VALUES (?, ?, ?, ?, 'Active', 'Approved')");
    $insertFarmer->execute([$name, $phone, $email, $address]);
    $farmerId = (int) $connection->lastInsertId();

    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $tokenHash = hash('sha256', $token);
    $insertSession = $connection->prepare('INSERT INTO farmer_sessions (farmer_id, token_hash, expires_at) VALUES (?, ?, ' . db_sql_utc_now($connection) . " + INTERVAL '1' HOUR)");
    $insertSession->execute([$farmerId, $tokenHash]);

    $context = stream_context_create(['http' => [
        'method' => 'GET',
        'ignore_errors' => true,
        'timeout' => 10,
        'header' => "Accept: application/json\r\nAuthorization: Bearer {$token}\r\n",
    ]]);
    $body = file_get_contents(agrireach_test_base_url() . '/CAPSTONE%20AGRICULTURE/api/weather/current.php', false, $context);
    weather_api_expect(is_string($body), 'Local current weather API could not be reached.');
    $payload = json_decode($body, true);
    weather_api_expect(is_array($payload) && ($payload['success'] ?? false) === true, 'Current weather API did not return a successful JSON response.');
    $data = $payload['data'] ?? [];
    weather_api_expect(($data['location'] ?? '') === 'Cabadbaran City, Agusan del Norte', 'Current weather location is incorrect.');
    weather_api_expect(($data['provider'] ?? '') === 'Open-Meteo', 'Current weather provider is missing.');
    weather_api_expect(!empty($data['observedAt']) && array_key_exists('isStale', $data), 'Current weather freshness metadata is missing.');
    echo "PASS: authenticated current weather API returns Cabadbaran data and freshness metadata\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . PHP_EOL);
    $exitCode = 1;
} finally {
    if ($farmerId) {
        $delete = $connection->prepare('DELETE FROM farmers WHERE id = ?');
        $delete->execute([$farmerId]);
    }
}

exit($exitCode ?? 0);
