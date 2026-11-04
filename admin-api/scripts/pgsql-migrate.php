<?php
declare(strict_types=1);

// Applies database/pgsql/*.sql schema migrations in filename order to a PostgreSQL connection.
// Usage: php scripts/pgsql-migrate.php --connection=supabase
require_once __DIR__ . '/pgsql-common.php';

$options = migration_options($argv);
$name = (string) ($options['connection'] ?? 'supabase');
$target = migration_connection($name, 'pgsql');

$applied = [];
$tracking = $target->query("SELECT to_regclass('public.agrireach_schema_migrations') IS NOT NULL")->fetchColumn();
if ($tracking) {
    $applied = $target->query('SELECT version FROM agrireach_schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
} else {
    $existing = (int) $target->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'public' AND table_name IN ('farmers', 'admins', 'damage_reports')")->fetchColumn();
    if ($existing > 0) {
        fwrite(STDERR, "Refusing to run: AgriReach tables already exist in \"{$name}\" without migration tracking.\n");
        exit(1);
    }
}

$files = glob(dirname(__DIR__) . '/database/pgsql/*.sql') ?: [];
sort($files, SORT_STRING);
foreach ($files as $file) {
    $version = basename($file, '.sql');
    if (in_array($version, $applied, true)) {
        echo "skip   {$version} (already applied)\n";
        continue;
    }
    // Each migration file manages its own BEGIN/COMMIT, so a failure leaves nothing half-applied.
    $target->exec((string) file_get_contents($file));
    echo "apply  {$version}\n";
}
echo "Schema is up to date on connection \"{$name}\".\n";
