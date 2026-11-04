<?php
declare(strict_types=1);

// Copies every AgriReach row from MySQL into the migrated PostgreSQL schema, preserving IDs,
// relationships, timestamps, password hashes and image references, then resets identity sequences.
// The MySQL source is only read, never modified. Everything is written in ONE PostgreSQL
// transaction, committed only after per-table row counts and content digests match.
//
// Usage: php scripts/pgsql-transfer.php --source=mysql --target=supabase [--replace]
//   --replace  empties the target tables first (only for re-running a rehearsal before cutover).
require_once __DIR__ . '/pgsql-common.php';

$options = migration_options($argv);
$sourceName = (string) ($options['source'] ?? 'mysql');
$targetName = (string) ($options['target'] ?? 'supabase');
$source = migration_connection($sourceName, 'mysql');
$target = migration_connection($targetName, 'pgsql');

// Read inside one consistent snapshot of the source (InnoDB REPEATABLE READ).
$source->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$source->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');

$autoIncrement = [];
foreach ($source->query('SELECT table_name, auto_increment FROM information_schema.tables WHERE table_schema = DATABASE()') as $row) {
    $autoIncrement[(string) $row['table_name']] = $row['auto_increment'] === null ? null : (int) $row['auto_increment'];
}
$sourceTables = array_keys($autoIncrement);
$missing = array_diff($sourceTables, array_keys(PGSQL_MIGRATION_TABLES));
if ($missing) {
    fwrite(STDERR, 'Unmapped MySQL tables (refusing to drop data): ' . implode(', ', $missing) . "\n");
    exit(1);
}

$target->beginTransaction();
try {
    if (isset($options['replace'])) {
        $target->exec('TRUNCATE ' . implode(', ', array_keys(PGSQL_MIGRATION_TABLES)) . ' RESTART IDENTITY');
    }
    foreach (array_keys(PGSQL_MIGRATION_TABLES) as $table) {
        if ((int) $target->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn() !== 0) {
            throw new RuntimeException("Target table {$table} is not empty. Use --replace only for a rehearsal before cutover.");
        }
    }

    $report = [];
    foreach (PGSQL_MIGRATION_TABLES as $table => $primaryKey) {
        $sourceColumns = migration_columns($source, $table);
        $targetColumns = migration_columns($target, $table);
        $onlySource = array_diff($sourceColumns, $targetColumns);
        $onlyTarget = array_diff($targetColumns, $sourceColumns);
        if ($onlySource || $onlyTarget) {
            throw new RuntimeException("Column mismatch in {$table}: MySQL-only [" . implode(', ', $onlySource) . '], PostgreSQL-only [' . implode(', ', $onlyTarget) . ']');
        }

        $selfReference = PGSQL_MIGRATION_SELF_REFERENCES[$table] ?? null;
        $rows = $source->query('SELECT ' . implode(', ', $sourceColumns) . ' FROM ' . $table . ' ORDER BY ' . implode(', ', $primaryKey))->fetchAll();
        $deferred = [];
        foreach (array_chunk($rows, 200) as $chunk) {
            $values = [];
            $placeholders = [];
            foreach ($chunk as $row) {
                if ($selfReference !== null && $row[$selfReference] !== null) {
                    $deferred[(int) $row['id']] = (int) $row[$selfReference];
                    $row[$selfReference] = null;
                }
                foreach ($sourceColumns as $column) $values[] = $row[$column];
                $placeholders[] = '(' . implode(', ', array_fill(0, count($sourceColumns), '?')) . ')';
            }
            // OVERRIDING SYSTEM VALUE is harmless for BY DEFAULT identities and keeps the original IDs explicit.
            $target->prepare('INSERT INTO ' . $table . ' (' . implode(', ', $sourceColumns) . ') OVERRIDING SYSTEM VALUE VALUES ' . implode(', ', $placeholders))->execute($values);
        }
        if ($deferred) {
            $update = $target->prepare("UPDATE {$table} SET {$selfReference} = ? WHERE id = ?");
            foreach ($deferred as $id => $reference) $update->execute([$reference, $id]);
        }

        // Continue numbering exactly where MySQL would have (its AUTO_INCREMENT counter), never below max(id).
        $sequence = $primaryKey === ['id'] ? $target->query("SELECT pg_get_serial_sequence('public.{$table}', 'id')")->fetchColumn() : null;
        if (is_string($sequence) && $sequence !== '') {
            $maxId = (int) $target->query("SELECT COALESCE(MAX(id), 0) FROM {$table}")->fetchColumn();
            $next = max((int) ($autoIncrement[$table] ?? 1), $maxId + 1);
            $target->prepare('SELECT setval(CAST(? AS regclass), ?, false)')->execute([$sequence, $next]);
            $report[$table]['next_id'] = $next;
        }

        $sourceDigest = migration_table_digest($source, $table, $sourceColumns);
        $targetDigest = migration_table_digest($target, $table, $sourceColumns);
        if ($sourceDigest !== $targetDigest) {
            throw new RuntimeException("Verification failed for {$table}: MySQL " . json_encode($sourceDigest) . ' vs PostgreSQL ' . json_encode($targetDigest));
        }
        $report[$table]['rows'] = $sourceDigest['rows'];
        printf("%-32s %5d rows copied, content digest matches%s\n", $table, $sourceDigest['rows'], isset($report[$table]['next_id']) ? ', next id ' . $report[$table]['next_id'] : '');
    }

    $target->commit();
    $source->exec('COMMIT');
    echo "Transfer committed to \"{$targetName}\". The MySQL source was not modified.\n";
} catch (Throwable $exception) {
    if ($target->inTransaction()) $target->rollBack();
    fwrite(STDERR, 'Transfer rolled back: ' . $exception->getMessage() . "\n");
    exit(1);
}
