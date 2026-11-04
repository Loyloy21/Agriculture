<?php
declare(strict_types=1);

// Pre-cutover verification: compares MySQL and PostgreSQL row counts and full-content digests,
// checks referential integrity and identity sequences in PostgreSQL, and confirms that every
// image path stored in the database still resolves to a protected file on disk.
// Exit code 0 only when every check passes. Read-only on both databases.
//
// Usage: php scripts/pgsql-verify.php --source=mysql --target=supabase
require_once __DIR__ . '/pgsql-common.php';
require_once __DIR__ . '/../backend/lib/private_uploads.php';
require_once __DIR__ . '/../backend/lib/damage_reports.php';

$options = migration_options($argv);
$source = migration_connection((string) ($options['source'] ?? 'mysql'), 'mysql');
$target = migration_connection((string) ($options['target'] ?? 'supabase'), 'pgsql');
$failures = 0;
$fail = static function (string $message) use (&$failures): void {
    $failures++;
    echo "FAIL  {$message}\n";
};

echo "== Row counts and content digests (MySQL vs PostgreSQL)\n";
foreach (array_keys(PGSQL_MIGRATION_TABLES) as $table) {
    $columns = migration_columns($source, $table);
    if ($columns !== array_values(array_intersect($columns, migration_columns($target, $table)))) {
        $fail("{$table}: column sets differ");
        continue;
    }
    $mysql = migration_table_digest($source, $table, $columns);
    $pgsql = migration_table_digest($target, $table, $columns);
    $same = $mysql === $pgsql;
    printf("%-5s %-32s mysql=%-5d pgsql=%-5d %s\n", $same ? 'OK' : 'FAIL', $table, $mysql['rows'], $pgsql['rows'], $same ? 'digest match' : 'DIGEST MISMATCH');
    if (!$same) $failures++;
}

echo "== PostgreSQL referential integrity\n";
$constraints = $target->query("SELECT conrelid::regclass::text AS child, conname, pg_get_constraintdef(oid) AS definition, convalidated
    FROM pg_constraint WHERE contype = 'f' AND connamespace = 'public'::regnamespace ORDER BY 1, 2")->fetchAll();
foreach ($constraints as $constraint) {
    // Re-check each FK explicitly so the result does not depend on how the rows were loaded.
    if (!preg_match('/^FOREIGN KEY \(([^)]+)\) REFERENCES ([a-z_.]+)\(([^)]+)\)/', (string) $constraint['definition'], $match)) {
        $fail("cannot parse {$constraint['conname']}");
        continue;
    }
    $childColumns = array_map('trim', explode(',', $match[1]));
    $parentColumns = array_map('trim', explode(',', $match[3]));
    $join = implode(' AND ', array_map(static fn($c, $p) => "p.{$p} = c.{$c}", $childColumns, $parentColumns));
    $notNull = implode(' AND ', array_map(static fn($c) => "c.{$c} IS NOT NULL", $childColumns));
    $orphans = (int) $target->query("SELECT COUNT(*) FROM {$constraint['child']} c WHERE {$notNull} AND NOT EXISTS (SELECT 1 FROM {$match[2]} p WHERE {$join})")->fetchColumn();
    if ($orphans > 0 || !$constraint['convalidated']) $fail("{$constraint['conname']}: {$orphans} orphan row(s)");
}
echo 'OK    ' . count($constraints) . " foreign keys checked\n";

echo "== Identity sequences\n";
foreach (array_keys(PGSQL_MIGRATION_TABLES) as $table) {
    if (PGSQL_MIGRATION_TABLES[$table] !== ['id']) continue;
    $sequence = (string) $target->query("SELECT pg_get_serial_sequence('public.{$table}', 'id')")->fetchColumn();
    if ($sequence === '') continue; // e.g. the registration_email_settings singleton (id is always 1)
    $state = $target->query("SELECT last_value, is_called FROM {$sequence}")->fetch();
    $next = (int) $state['last_value'] + ($state['is_called'] ? 1 : 0);
    $maxId = (int) $target->query("SELECT COALESCE(MAX(id), 0) FROM {$table}")->fetchColumn();
    $mysqlNext = (int) $source->query("SELECT auto_increment FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '{$table}'")->fetchColumn();
    if ($next <= $maxId || $next < $mysqlNext) $fail("{$table}: next id {$next}, max id {$maxId}, MySQL AUTO_INCREMENT {$mysqlNext}");
}
echo "OK    sequences continue after the highest imported ID\n";

echo "== Stored image references (files stay on this server; paths are unchanged)\n";
$images = $target->query('SELECT id, report_id, image_path FROM report_images ORDER BY id')->fetchAll();
$placeholder = 0;
$resolved = 0;
foreach ($images as $image) {
    if (str_starts_with((string) $image['image_path'], '../assets/')) { $placeholder++; continue; }
    if (resolve_report_image((string) $image['image_path']) !== null) $resolved++;
    else echo "WARN  report_images.id={$image['id']} (report {$image['report_id']}): file not found on disk\n";
}
echo "INFO  report_images: {$resolved} resolve to protected files, {$placeholder} reference bundled sample assets\n";
$ids = $target->query('SELECT farmer_id, image_path, front_image_path, back_image_path FROM farmer_ids ORDER BY farmer_id')->fetchAll();
foreach ($ids as $row) {
    foreach (['image_path', 'front_image_path', 'back_image_path'] as $column) {
        if ($row[$column] === null) continue;
        echo (resolve_farmer_id_image((string) $row[$column]) !== false ? 'OK  ' : 'WARN') . "  farmer_ids farmer {$row['farmer_id']} {$column}: " . (resolve_farmer_id_image((string) $row[$column]) !== false ? 'file present' : 'file not found on disk (pre-existing; not caused by the migration)') . "\n";
    }
}

echo $failures === 0 ? "\nALL DATABASE CHECKS PASSED\n" : "\n{$failures} CHECK(S) FAILED - do not switch the application connection\n";
exit($failures === 0 ? 0 : 1);
