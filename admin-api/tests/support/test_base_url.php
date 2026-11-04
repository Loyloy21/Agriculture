<?php
declare(strict_types=1);

/**
 * Loopback origin the integration tests call. Defaults to the XAMPP Apache server; set
 * AGRIREACH_TEST_BASE_URL (for example http://127.0.0.1:8091) to target a separate PHP server
 * that uses another database connection, such as the PostgreSQL rehearsal before cutover.
 */
function agrireach_test_base_url(): string
{
    $base = rtrim((string) (getenv('AGRIREACH_TEST_BASE_URL') ?: 'http://127.0.0.1'), '/');
    $host = (string) parse_url($base, PHP_URL_HOST);
    if (!in_array($host, ['127.0.0.1', 'localhost'], true)) throw new RuntimeException('Integration tests require a loopback base URL.');
    return $base;
}
