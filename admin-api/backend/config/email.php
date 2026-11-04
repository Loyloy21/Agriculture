<?php
declare(strict_types=1);

require_once __DIR__ . '/paths.php';

/**
 * Local mail settings (APP_ENV, SMTP values) live OUTSIDE the web root:
 *   C:\xampp\agrireach-secrets\email.local.php   (format: backend/config/email.example.php)
 * Override the path with AGRIREACH_EMAIL_CONFIG_FILE. Server environment variables still win.
 */
function email_local_settings_file(): string
{
    $configured = trim((string) getenv('AGRIREACH_EMAIL_CONFIG_FILE'));
    return $configured !== '' ? $configured : agrireach_secrets_dir() . DIRECTORY_SEPARATOR . 'email.local.php';
}

function email_local_settings(): array
{
    static $settings;
    if (is_array($settings)) {
        return $settings;
    }

    $localFile = email_local_settings_file();
    $settings = is_file($localFile) ? require $localFile : [];
    $settings = is_array($settings) ? $settings : [];
    // TEMPORARY cross-network test (see temporary_test_settings()): only APP_URL is replaced, only while it is valid.
    $temporary = temporary_test_settings();
    if ($temporary !== null) $settings['APP_URL'] = $temporary['APP_URL'];
    return $settings;
}

/**
 * The temporary test overlay written by scripts/start-cloudflare-quick-tunnel.ps1 -WithInvitationPage and removed by
 * the stop script: app.temporary-test.php beside the private mail settings (outside the web root). It may only set
 * APP_URL to an HTTPS *.trycloudflare.com address, and is ignored once EXPIRES_AT (Unix time) has passed.
 * @return array{APP_URL:string,EXPIRES_AT:int}|null
 */
function temporary_test_settings(): ?array
{
    static $cached = false;
    if ($cached !== false) return $cached;
    $file = dirname(email_local_settings_file()) . DIRECTORY_SEPARATOR . 'app.temporary-test.php';
    $overlay = is_file($file) ? require $file : null;
    $url = is_array($overlay) ? rtrim(trim((string) ($overlay['APP_URL'] ?? '')), '/') : '';
    $valid = $url !== '' && preg_match('#^https://[a-z0-9-]+\.trycloudflare\.com(/[A-Za-z0-9%._~/-]*)?$#D', $url) === 1
        && (int) ($overlay['EXPIRES_AT'] ?? 0) > time();
    return $cached = $valid ? ['APP_URL' => $url, 'EXPIRES_AT' => (int) $overlay['EXPIRES_AT']] : null;
}

function email_setting(string $name, ?string $default = null): ?string
{
    $environmentValue = getenv($name);
    if ($environmentValue !== false && trim((string) $environmentValue) !== '') {
        return trim((string) $environmentValue);
    }

    $localSettings = email_local_settings();
    if (array_key_exists($name, $localSettings) && trim((string) $localSettings[$name]) !== '') {
        return trim((string) $localSettings[$name]);
    }

    return $default;
}

function email_boolean_setting(string $name, bool $default): bool
{
    $value = email_setting($name);
    if ($value === null) return $default;
    return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
}

function email_configuration(): array
{
    return [
        'app_environment' => strtolower((string) email_setting('APP_ENV', 'development')),
        'transport' => strtolower((string) email_setting('MAIL_TRANSPORT', 'log')),
        'host' => (string) email_setting('SMTP_HOST', ''),
        'port' => (int) email_setting('SMTP_PORT', '587'),
        'encryption' => strtolower((string) email_setting('SMTP_ENCRYPTION', 'tls')),
        'authentication' => email_boolean_setting('SMTP_AUTH', true),
        'username' => (string) email_setting('SMTP_USERNAME', ''),
        'password' => (string) email_setting('SMTP_PASSWORD', ''),
        'from_address' => (string) email_setting('SMTP_FROM_ADDRESS', ''),
        'from_name' => (string) email_setting('SMTP_FROM_NAME', 'AgriReach'),
        'test_recipient' => (string) email_setting('MAIL_TEST_RECIPIENT', ''),
        'timeout' => max(1, min(30, (int) email_setting('SMTP_TIMEOUT', '10'))),
    ];
}
