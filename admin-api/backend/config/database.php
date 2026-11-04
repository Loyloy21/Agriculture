<?php
declare(strict_types=1);

require_once __DIR__ . '/paths.php';

date_default_timezone_set('Asia/Manila');

/**
 * Database connection settings are read from a PHP file OUTSIDE the web root so credentials are never
 * served by Apache or committed to Git. Default location (beside the existing email key directory):
 *   C:\xampp\agrireach-secrets\database.php
 * Override the path with AGRIREACH_DB_CONFIG_FILE, and the named connection with AGRIREACH_DB_CONNECTION.
 * See backend/config/database.example.php for the format. Without that file the original local XAMPP
 * MySQL settings are used, so the application keeps working on MySQL until the cutover is configured.
 */
function db_settings_file(): string
{
    $configured = trim((string) getenv('AGRIREACH_DB_CONFIG_FILE'));
    if ($configured !== '') return $configured;
    return agrireach_secrets_dir() . DIRECTORY_SEPARATOR . 'database.php';
}

/** @return array{name:string,driver:string,host:string,port:int,database:string,username:string,password:string,sslmode?:string,sslrootcert?:string} */
function db_connection_settings(): array
{
    $legacyMysql = ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 3306, 'database' => 'agrireach', 'username' => 'root', 'password' => ''];
    $file = db_settings_file();
    $settings = is_file($file) ? require $file : ['default' => 'mysql', 'connections' => ['mysql' => $legacyMysql]];
    if (!is_array($settings) || !isset($settings['connections']) || !is_array($settings['connections'])) {
        throw new RuntimeException('The AgriReach database settings file is invalid.');
    }
    $name = trim((string) getenv('AGRIREACH_DB_CONNECTION'));
    if ($name === '') $name = (string) ($settings['default'] ?? '');
    $connection = $settings['connections'][$name] ?? null;
    if (!is_array($connection) || !in_array($connection['driver'] ?? '', ['mysql', 'pgsql'], true)) {
        throw new RuntimeException('The AgriReach database connection "' . $name . '" is not configured.');
    }
    return ['name' => $name] + $connection + ['port' => $connection['driver'] === 'pgsql' ? 5432 : 3306, 'password' => ''];
}

/** Binds PHP ints/bools as integers and nulls as NULL, the way mysqli bind_param('i') did. */
final class DbStatement extends PDOStatement
{
    protected function __construct()
    {
    }

    public function execute(?array $params = null): bool
    {
        if ($params === null) return parent::execute();
        $position = 1;
        foreach ($params as $value) {
            match (true) {
                $value === null => $this->bindValue($position, null, PDO::PARAM_NULL),
                is_int($value), is_bool($value) => $this->bindValue($position, (int) $value, PDO::PARAM_INT),
                default => $this->bindValue($position, (string) $value, PDO::PARAM_STR),
            };
            $position++;
        }
        return parent::execute();
    }
}

function db_open(array $settings): PDO
{
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false,
        PDO::ATTR_STATEMENT_CLASS => [DbStatement::class],
    ];

    if ($settings['driver'] === 'pgsql') {
        if (!extension_loaded('pdo_pgsql')) throw new RuntimeException('The pdo_pgsql PHP extension is not enabled.');
        $dsn = 'pgsql:host=' . $settings['host'] . ';port=' . (int) $settings['port'] . ';dbname=' . $settings['database']
            . ';sslmode=' . ($settings['sslmode'] ?? 'verify-full')
            . (!empty($settings['sslrootcert']) ? ';sslrootcert=' . $settings['sslrootcert'] : '')
            . ';connect_timeout=10;application_name=agrireach-php';
        // Parameters are still sent separately from the SQL (PQexecParams), but without named
        // server-side prepared statements: one round trip per query, and safe behind Supabase's pooler.
        $options[PDO::PGSQL_ATTR_DISABLE_PREPARES] = true;
        $connection = new PDO($dsn, $settings['username'], $settings['password'], $options);
        // Same wall clock MySQL used (time_zone +08:00). PostgreSQL reads "+08:00" as POSIX (inverted), so use the zone name.
        // The citext operators live in the "extensions" schema; without it on the path, citext columns
        // silently compare as case-sensitive text instead of matching MySQL's case-insensitive collation.
        $connection->exec("SET TIME ZONE 'Asia/Manila'; SET search_path = public, extensions");
        return $connection;
    }

    $dsn = 'mysql:host=' . $settings['host'] . ';port=' . (int) $settings['port'] . ';dbname=' . $settings['database'] . ';charset=utf8mb4';
    $connection = new PDO($dsn, $settings['username'], $settings['password'], $options);
    $connection->exec("SET time_zone = '+08:00'");
    return $connection;
}

function db(): PDO
{
    static $connection;

    if ($connection instanceof PDO) {
        return $connection;
    }

    $connection = db_open(db_connection_settings());

    return $connection;
}

function db_is_pgsql(PDO $connection): bool
{
    return $connection->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
}

/** ID generated by the last INSERT into $table (identity sequence on PostgreSQL, AUTO_INCREMENT on MySQL). */
function db_insert_id(PDO $connection, string $table): int
{
    return (int) $connection->lastInsertId(db_is_pgsql($connection) ? $table . '_id_seq' : null);
}

/** True for duplicate-key errors: MySQL 1062 / PostgreSQL SQLSTATE 23505. */
function db_is_duplicate_key(Throwable $exception): bool
{
    if (!$exception instanceof PDOException) return false;
    $info = $exception->errorInfo ?? [];
    return ($info[0] ?? null) === '23505' || (($info[0] ?? null) === '23000' && (int) ($info[1] ?? 0) === 1062);
}

/** Current UTC wall-clock time as SQL, for the UTC-valued DATETIME columns (sessions, email deliveries). */
function db_sql_utc_now(PDO $connection): string
{
    return db_is_pgsql($connection) ? "(CURRENT_TIMESTAMP(0) AT TIME ZONE 'UTC')" : 'UTC_TIMESTAMP()';
}

/**
 * INSERT ... that updates the listed columns from the proposed row on a key conflict.
 * MySQL: ON DUPLICATE KEY UPDATE col = VALUES(col). PostgreSQL: ON CONFLICT (keys) DO UPDATE SET col = EXCLUDED.col.
 * $extraAssignments are literal SQL assignments applied on conflict (no user input).
 */
function db_sql_upsert(PDO $connection, string $insertSql, array $conflictColumns, array $updateColumns, array $extraAssignments = []): string
{
    if (db_is_pgsql($connection)) {
        $set = array_map(static fn(string $column): string => $column . ' = EXCLUDED.' . $column, $updateColumns);
        return $insertSql . ' ON CONFLICT (' . implode(', ', $conflictColumns) . ') DO UPDATE SET ' . implode(', ', [...$set, ...$extraAssignments]);
    }
    $set = array_map(static fn(string $column): string => $column . ' = VALUES(' . $column . ')', $updateColumns);
    return $insertSql . ' ON DUPLICATE KEY UPDATE ' . implode(', ', [...$set, ...$extraAssignments]);
}

/** INSERT that silently skips rows violating the given unique key (MySQL INSERT IGNORE). */
function db_sql_insert_ignore(PDO $connection, string $table, string $columnsAndValues, array $conflictColumns): string
{
    return db_is_pgsql($connection)
        ? 'INSERT INTO ' . $table . ' ' . $columnsAndValues . ' ON CONFLICT (' . implode(', ', $conflictColumns) . ') DO NOTHING'
        : 'INSERT IGNORE INTO ' . $table . ' ' . $columnsAndValues;
}
