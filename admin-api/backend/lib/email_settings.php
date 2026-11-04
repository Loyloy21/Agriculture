<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/email.php';

const REGISTRATION_EMAIL_CIPHER = 'aes-256-gcm';
const REGISTRATION_EMAIL_AAD = 'agrireach-registration-email-v1';

final class EmailEncryptionConfigurationException extends RuntimeException
{
}

final class EmailSenderValidationException extends InvalidArgumentException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }
}

function registration_email_default_key_file(): string
{
    return agrireach_secrets_dir() . DIRECTORY_SEPARATOR . 'email.key';
}

function registration_email_encryption_key(): ?string
{
    $encoded = getenv('AGRIREACH_EMAIL_ENCRYPTION_KEY');
    if ($encoded !== false && trim((string) $encoded) !== '') {
        $key = base64_decode(trim((string) $encoded), true);
        return is_string($key) && strlen($key) === 32 ? $key : null;
    }

    $configuredKeyFile = getenv('AGRIREACH_EMAIL_ENCRYPTION_KEY_FILE');
    $keyFile = $configuredKeyFile !== false && trim((string) $configuredKeyFile) !== ''
        ? trim((string) $configuredKeyFile)
        : registration_email_default_key_file();

    $resolvedKeyFile = realpath($keyFile);
    if ($resolvedKeyFile === false || !is_file($resolvedKeyFile) || !is_readable($resolvedKeyFile)) {
        return null;
    }

    $documentRoot = realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
    if ($documentRoot === false) {
        $documentRoot = realpath(dirname(agrireach_root()));
    }
    if ($documentRoot !== false) {
        $normalizedRoot = strtolower(str_replace('\\', '/', rtrim($documentRoot, '\\/')));
        $normalizedFile = strtolower(str_replace('\\', '/', $resolvedKeyFile));
        if ($normalizedFile === $normalizedRoot || str_starts_with($normalizedFile, $normalizedRoot . '/')) {
            return null;
        }
    }

    $key = base64_decode(trim((string) file_get_contents($resolvedKeyFile)), true);
    return is_string($key) && strlen($key) === 32 ? $key : null;
}

function encrypt_registration_email_secret(string $secret): array
{
    $key = registration_email_encryption_key();
    if ($key === null) {
        throw new EmailEncryptionConfigurationException('The server encryption key is missing or invalid. Configure it outside the web directory, restart Apache, and try again.');
    }

    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt(
        $secret,
        REGISTRATION_EMAIL_CIPHER,
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        REGISTRATION_EMAIL_AAD,
        16
    );
    if (!is_string($ciphertext) || strlen($tag) !== 16) {
        throw new RuntimeException('The email credential could not be encrypted.');
    }

    return [
        'ciphertext' => base64_encode($ciphertext),
        'iv' => base64_encode($iv),
        'tag' => base64_encode($tag),
    ];
}

function decrypt_registration_email_secret(array $settings): ?string
{
    $key = registration_email_encryption_key();
    if ($key === null) return null;

    $ciphertext = base64_decode((string) ($settings['app_password_ciphertext'] ?? ''), true);
    $iv = base64_decode((string) ($settings['app_password_iv'] ?? ''), true);
    $tag = base64_decode((string) ($settings['app_password_tag'] ?? ''), true);
    if (!is_string($ciphertext) || !is_string($iv) || !is_string($tag) || strlen($iv) !== 12 || strlen($tag) !== 16) {
        return null;
    }

    $secret = openssl_decrypt(
        $ciphertext,
        REGISTRATION_EMAIL_CIPHER,
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        REGISTRATION_EMAIL_AAD
    );
    return is_string($secret) && $secret !== '' ? $secret : null;
}

function registration_email_sender_record(?PDO $connection = null): ?array
{
    try {
        $connection ??= db();
        $result = $connection->query('SELECT id, sender_email, app_password_ciphertext, app_password_iv, app_password_tag, last_test_status, last_tested_at, last_test_error_code, updated_at FROM registration_email_settings WHERE id = 1 LIMIT 1');
        return $result->fetch() ?: null;
    } catch (Throwable $exception) {
        return null;
    }
}

function registration_email_configuration(?PDO $connection = null): array
{
    $configuration = email_configuration();
    $settings = registration_email_sender_record($connection);
    if (!$settings) return $configuration;

    $password = decrypt_registration_email_secret($settings);
    if ($password === null || !filter_var($settings['sender_email'], FILTER_VALIDATE_EMAIL)) {
        return $configuration;
    }

    return array_merge($configuration, [
        'transport' => 'smtp',
        'host' => 'smtp.gmail.com',
        'port' => 587,
        'encryption' => 'tls',
        'authentication' => true,
        'username' => (string) $settings['sender_email'],
        'password' => $password,
        'from_address' => (string) $settings['sender_email'],
    ]);
}

function registration_email_sender_state(?PDO $connection = null): array
{
    $base = email_configuration();
    $settings = registration_email_sender_record($connection);
    $keyConfigured = registration_email_encryption_key() !== null;
    if ($settings) {
        return [
            'sender_email' => (string) $settings['sender_email'],
            'credential_configured' => (string) $settings['app_password_ciphertext'] !== '',
            'credential_available' => $keyConfigured && decrypt_registration_email_secret($settings) !== null,
            'key_configured' => $keyConfigured,
            'source' => 'Admin settings',
            'last_test_status' => (string) $settings['last_test_status'],
            'last_tested_at' => $settings['last_tested_at'],
        ];
    }

    $baseCredential = $base['username'] !== '' && $base['password'] !== '';
    return [
        'sender_email' => (string) ($base['from_address'] ?: $base['username']),
        'credential_configured' => $baseCredential,
        'credential_available' => $baseCredential,
        'key_configured' => $keyConfigured,
        'source' => $baseCredential ? 'Server environment/local configuration' : 'Not configured',
        'last_test_status' => 'Not Tested',
        'last_tested_at' => null,
    ];
}

function is_gmail_address(string $email): bool
{
    // Google Workspace senders can use a custom domain while still authenticating through Gmail SMTP.
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function save_registration_email_sender(PDO $connection, string $senderEmail, string $appPassword, int $adminId): void
{
    $senderEmail = strtolower(trim($senderEmail));
    if (!is_gmail_address($senderEmail)) {
        throw new EmailSenderValidationException('sender_email', 'Enter a valid Gmail or Google Workspace sender email address.');
    }

    $existing = registration_email_sender_record($connection);
    $normalizedPassword = preg_replace('/\s+/', '', trim($appPassword)) ?? '';
    if ($normalizedPassword === '') {
        if (!$existing || strcasecmp((string) $existing['sender_email'], $senderEmail) !== 0) {
            throw new EmailSenderValidationException('app_password', 'Enter the Gmail App Password when configuring or changing the sender.');
        }
        if (registration_email_encryption_key() === null) {
            throw new EmailEncryptionConfigurationException('The server encryption key is missing or invalid. Configure it outside the web directory, restart Apache, and try again.');
        }
        return;
    }
    if (preg_match('/^[A-Za-z0-9]{16}$/', $normalizedPassword) !== 1) {
        throw new EmailSenderValidationException('app_password', 'Enter the 16-character Gmail App Password, not the ordinary Gmail password.');
    }

    $encrypted = encrypt_registration_email_secret($normalizedPassword);
    $status = 'Not Tested';
    $statement = $connection->prepare(db_sql_upsert(
        $connection,
        'INSERT INTO registration_email_settings (id, sender_email, app_password_ciphertext, app_password_iv, app_password_tag, last_test_status, last_tested_at, last_test_error_code, configured_by) VALUES (1, ?, ?, ?, ?, ?, NULL, NULL, ?)',
        ['id'],
        ['sender_email', 'app_password_ciphertext', 'app_password_iv', 'app_password_tag', 'last_test_status', 'configured_by'],
        ['last_tested_at = NULL', 'last_test_error_code = NULL']
    ));
    $statement->execute([$senderEmail, $encrypted['ciphertext'], $encrypted['iv'], $encrypted['tag'], $status, $adminId]);
}

function record_registration_email_test(PDO $connection, string $status, ?string $failureCode): void
{
    if (!in_array($status, ['Accepted', 'Failed'], true)) {
        throw new InvalidArgumentException('Invalid email test status.');
    }
    $statement = $connection->prepare('UPDATE registration_email_settings SET last_test_status = ?, last_tested_at = ' . db_sql_utc_now($connection) . ', last_test_error_code = ? WHERE id = 1');
    $statement->execute([$status, $failureCode]);
}

function default_registration_email_templates(): array
{
    return [
        'Approved' => [
            'subject' => 'Your AgriReach registration was approved',
            'message_body' => "Hello {farmer_name},\n\nYour farmer registration has been approved by the Department of Agriculture.\n\nYou may now log in to the AgriReach farmer mobile app using the email address and password you registered.",
        ],
        'Rejected' => [
            'subject' => 'Update on your AgriReach registration',
            'message_body' => "Hello {farmer_name},\n\nYour farmer registration was rejected by the Department of Agriculture.\n\nReason: {rejection_reason}\n\nPlease review the reason above before contacting the Department of Agriculture or submitting corrected information when that option becomes available.",
        ],
    ];
}

function load_registration_email_templates(?PDO $connection = null): array
{
    $templates = default_registration_email_templates();
    try {
        $connection ??= db();
        $result = $connection->query("SELECT decision, subject, message_body, updated_at FROM registration_email_templates WHERE decision IN ('Approved', 'Rejected')");
        while ($row = $result->fetch()) {
            $templates[(string) $row['decision']] = [
                'subject' => (string) $row['subject'],
                'message_body' => (string) $row['message_body'],
                'updated_at' => $row['updated_at'],
            ];
        }
    } catch (Throwable $exception) {
        // Defaults keep verification usable while the schema is being installed.
    }
    return $templates;
}

function load_registration_email_template(string $decision, ?PDO $connection = null): array
{
    if (!in_array($decision, ['Approved', 'Rejected'], true)) {
        throw new InvalidArgumentException('Unsupported verification decision.');
    }
    return load_registration_email_templates($connection)[$decision];
}

function validate_registration_email_template(string $decision, string $subject, string $messageBody): array
{
    $subject = trim($subject);
    $messageBody = trim($messageBody);
    if (!in_array($decision, ['Approved', 'Rejected'], true)) {
        throw new InvalidArgumentException('Invalid email template type.');
    }
    if (mb_strlen($subject) < 5 || mb_strlen($subject) > 180) {
        throw new InvalidArgumentException('The subject must contain 5 to 180 characters.');
    }
    if (preg_match('/[\r\n]/', $subject) === 1) {
        throw new InvalidArgumentException('The subject must be a single line.');
    }
    if (mb_strlen($messageBody) < 20 || mb_strlen($messageBody) > 5000) {
        throw new InvalidArgumentException('The message must contain 20 to 5,000 characters.');
    }
    if (preg_match('/(?:password|passwd|app[ _-]?password)\s*[:=]\s*\S+|password you registered\s+\S+/i', $messageBody)) {
        throw new InvalidArgumentException('Do not include a password or credential in a registration email template.');
    }

    preg_match_all('/\{[a-z_]+\}/', $subject . "\n" . $messageBody, $matches);
    $allowed = $decision === 'Approved' ? ['{farmer_name}'] : ['{farmer_name}', '{rejection_reason}'];
    foreach (array_unique($matches[0]) as $placeholder) {
        if (!in_array($placeholder, $allowed, true)) {
            throw new InvalidArgumentException('The template contains an unsupported placeholder: ' . $placeholder);
        }
    }
    if (!str_contains($subject . $messageBody, '{farmer_name}')) {
        throw new InvalidArgumentException('The template must include {farmer_name}.');
    }
    if ($decision === 'Approved' && (preg_match('/approved/i', $messageBody) !== 1 || preg_match('/log\s*in/i', $messageBody) !== 1)) {
        throw new InvalidArgumentException('The approval message must state that registration is approved and the farmer may log in.');
    }
    if ($decision === 'Rejected' && !str_contains($subject . $messageBody, '{rejection_reason}')) {
        throw new InvalidArgumentException('The rejection template must include {rejection_reason}.');
    }
    if ($decision === 'Rejected' && preg_match('/rejected|not\s+approved/i', $messageBody) !== 1) {
        throw new InvalidArgumentException('The rejection message must clearly state that the registration was rejected or not approved.');
    }

    return ['subject' => $subject, 'message_body' => $messageBody];
}

function save_registration_email_template(PDO $connection, string $decision, string $subject, string $messageBody, int $adminId): void
{
    $validated = validate_registration_email_template($decision, $subject, $messageBody);
    $statement = $connection->prepare(db_sql_upsert($connection, 'INSERT INTO registration_email_templates (decision, subject, message_body, updated_by) VALUES (?, ?, ?, ?)', ['decision'], ['subject', 'message_body', 'updated_by']));
    $statement->execute([$decision, $validated['subject'], $validated['message_body'], $adminId]);
}
