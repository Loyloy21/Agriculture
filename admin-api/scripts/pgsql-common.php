<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../backend/config/database.php';

/** Tables in foreign-key dependency order (parents first), with their primary key columns. */
const PGSQL_MIGRATION_TABLES = [
    'admins' => ['id'],
    'farmers' => ['id'],
    'cabadbaran_barangays' => ['psgc_code'],
    'farm_profiles' => ['id'],
    'farmer_ids' => ['id'],
    'farmer_sessions' => ['id'],
    'damage_reports' => ['id'],
    'admin_responses' => ['id'],
    'admin_settings' => ['id'],
    'damage_report_assessment_audit' => ['id'],
    'report_images' => ['id'],
    'weather_advisories' => ['id'],
    'notifications' => ['id'],
    'admin_notification_state' => ['admin_id', 'notification_id'],
    'registration_email_settings' => ['id'],
    'registration_email_templates' => ['decision'],
    'verification_email_deliveries' => ['id'],
    'weather_advisory_barangays' => ['advisory_id', 'barangay_code'],
    'weather_advisory_recipients' => ['id'],
    'weather_data' => ['id'],
];

/** Self-referencing foreign keys, filled in a second pass after all rows exist. */
const PGSQL_MIGRATION_SELF_REFERENCES = ['verification_email_deliveries' => 'retry_of_delivery_id'];

/** Opens a named connection from the settings file, regardless of which one the app uses by default. */
function migration_connection(string $name, string $expectedDriver): PDO
{
    $previous = getenv('AGRIREACH_DB_CONNECTION');
    putenv('AGRIREACH_DB_CONNECTION=' . $name);
    try {
        $settings = db_connection_settings();
    } finally {
        putenv($previous === false ? 'AGRIREACH_DB_CONNECTION' : 'AGRIREACH_DB_CONNECTION=' . $previous);
    }
    if ($settings['driver'] !== $expectedDriver) {
        throw new RuntimeException('Connection "' . $name . '" must use the ' . $expectedDriver . ' driver.');
    }
    return db_open($settings);
}

/** --name=value options from argv. */
function migration_options(array $argv): array
{
    $options = [];
    foreach (array_slice($argv, 1) as $argument) {
        if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $argument, $match)) $options[$match[1]] = $match[2] ?? true;
    }
    return $options;
}

function migration_columns(PDO $connection, string $table): array
{
    if (db_is_pgsql($connection)) {
        $statement = $connection->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema = 'public' AND table_name = ? ORDER BY ordinal_position");
    } else {
        $statement = $connection->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? ORDER BY ordinal_position');
    }
    $statement->execute([$table]);
    return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
}

/** Canonical text form of a value so MySQL and PostgreSQL rows can be compared exactly. */
function migration_canonical(mixed $value): string
{
    if ($value === null) return "\0NULL";
    if (is_bool($value)) return $value ? '1' : '0';
    return (string) $value;
}

/** SHA-256 over every row of a table (rows sorted in PHP, so collation differences cannot matter). */
function migration_table_digest(PDO $connection, string $table, array $columns): array
{
    $rows = [];
    foreach ($connection->query('SELECT ' . implode(', ', $columns) . ' FROM ' . $table)->fetchAll(PDO::FETCH_NUM) as $row) {
        $rows[] = implode("\x1F", array_map('migration_canonical', $row));
    }
    sort($rows, SORT_STRING);
    return ['rows' => count($rows), 'sha256' => hash('sha256', implode("\x1E", $rows))];
}
