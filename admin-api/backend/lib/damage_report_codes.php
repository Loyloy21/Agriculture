<?php
declare(strict_types=1);

/*
 * Farmer-facing report IDs for NEW Damage and Production reports: the farmer's canonical RSBSA reference followed
 * by ONE per-farmer sequence shared by both report types, e.g. "99-88-33-333-052323-1" for the farmer's first report
 * of either kind, then "-2" for the next. The ID is stored with the report (damage_reports.report_code,
 * production_records.report_code) and never recomputed for display. Older reports keep the ID they were given
 * ("AR-YYYYMMDD-XXXXXXXX" damage, "PR-000123" production), and the internal ids never change.
 *
 * The sequence comes from farmer_report_sequences and is raised inside the report's own transaction while
 * holding that farmer's counter row lock, so concurrent submissions (of either type) get distinct numbers. It
 * only ever increases: correcting, archiving or deleting a report, or correcting the RSBSA number, never frees
 * or reuses one. A farmer without an RSBSA reference still uses the sequence, with the previous ID format.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/farmer_identity.php';

/** The farmer's canonical RSBSA reference ("00-00-00-000-000000"), or null when none is on file. */
function damage_report_rsbsa_reference(PDO $connection, int $farmerId): ?string
{
    $statement = $connection->prepare('SELECT id_number FROM farmer_ids WHERE farmer_id = ? AND id_type = ? LIMIT 1');
    $statement->execute([$farmerId, RSBSA_ID_TYPE]);
    $number = $statement->fetchColumn();
    return is_string($number) ? rsbsa_reference_normalize($number) : null;
}

/** Previous code format, used when the farmer has no RSBSA reference. */
function damage_report_legacy_code(): string
{
    return 'AR-' . gmdate('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
}

/**
 * True once migration 20261103_0010_production_report_codes has added production_records.report_code. Checked
 * before use (and cached per request) so damage submissions keep working on a database that has not been updated
 * yet: in PostgreSQL a failed statement would abort the report's whole transaction.
 */
function production_report_codes_ready(PDO $connection): bool
{
    static $ready = [];
    $key = spl_object_id($connection);
    if (!isset($ready[$key])) {
        $statement = $connection->prepare(db_is_pgsql($connection)
            ? "SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'production_records' AND column_name = 'report_code'"
            : "SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'production_records' AND column_name = 'report_code'");
        $statement->execute();
        $ready[$key] = (bool) $statement->fetchColumn();
    }
    return $ready[$key];
}

/** Next number in the farmer's shared Damage + Production sequence. Must run inside the transaction that inserts the report. */
function farmer_report_next_sequence(PDO $connection, int $farmerId): int
{
    if (!$connection->inTransaction()) throw new LogicException('Report sequences are allocated inside the report transaction.');
    // One atomic upsert takes the counter row's exclusive lock immediately (held until the report commits).
    // "Insert if missing, then SELECT ... FOR UPDATE" deadlocks on MySQL/MariaDB under concurrent first reports.
    if (db_is_pgsql($connection)) {
        $raise = $connection->prepare('INSERT INTO farmer_report_sequences (farmer_id, last_sequence) VALUES (?, 1)
            ON CONFLICT (farmer_id) DO UPDATE SET last_sequence = farmer_report_sequences.last_sequence + 1 RETURNING last_sequence');
        $raise->execute([$farmerId]);
        $next = (int) $raise->fetchColumn();
    } else {
        $connection->prepare('INSERT INTO farmer_report_sequences (farmer_id, last_sequence) VALUES (?, LAST_INSERT_ID(1))
            ON DUPLICATE KEY UPDATE last_sequence = LAST_INSERT_ID(last_sequence + 1)')->execute([$farmerId]);
        $next = (int) $connection->query('SELECT LAST_INSERT_ID()')->fetchColumn();
    }
    // Never behind a number already stored in either report table (for example after restoring the counter from a backup).
    $both = production_report_codes_ready($connection);
    $stored = $connection->prepare($both
        ? 'SELECT GREATEST((SELECT COALESCE(MAX(report_sequence), 0) FROM damage_reports WHERE farmer_id = ?),
            (SELECT COALESCE(MAX(report_sequence), 0) FROM production_records WHERE farmer_id = ?))'
        : 'SELECT COALESCE(MAX(report_sequence), 0) FROM damage_reports WHERE farmer_id = ?');
    $stored->execute($both ? [$farmerId, $farmerId] : [$farmerId]);
    $highest = (int) $stored->fetchColumn();
    if ($next <= $highest) {
        $next = $highest + 1;
        $connection->prepare('UPDATE farmer_report_sequences SET last_sequence = ? WHERE farmer_id = ?')->execute([$next, $farmerId]);
    }
    return $next;
}

/**
 * Allocates the next shared sequence number and, when the farmer has an RSBSA reference, the RSBSA report ID for a
 * new Damage or Production report, inside that report's transaction. The code is null when the caller must use
 * its own previous ID format (no reference on file, or a reference shared with another farmer).
 *
 * @return array{code:?string,sequence:int}
 */
function farmer_report_allocate_code(PDO $connection, int $farmerId): array
{
    $sequence = farmer_report_next_sequence($connection, $farmerId);
    $reference = damage_report_rsbsa_reference($connection, $farmerId);
    if ($reference === null) return ['code' => null, 'sequence' => $sequence];
    $code = $reference . '-' . $sequence;
    // An RSBSA number recorded for two farmers must not reveal or collide with the other farmer's report.
    $both = production_report_codes_ready($connection);
    $taken = $connection->prepare($both
        ? 'SELECT farmer_id FROM damage_reports WHERE report_code = ? UNION ALL SELECT farmer_id FROM production_records WHERE report_code = ?'
        : 'SELECT farmer_id FROM damage_reports WHERE report_code = ?');
    $taken->execute($both ? [$code, $code] : [$code]);
    foreach ($taken->fetchAll(PDO::FETCH_COLUMN) as $owner) {
        if ((int) $owner !== $farmerId) {
            error_log('Report code collision for an RSBSA reference shared by two farmers; the previous ID format was used.');
            return ['code' => null, 'sequence' => $sequence];
        }
    }
    return ['code' => $code, 'sequence' => $sequence];
}

/**
 * Code and sequence for a new damage report (previous "AR-" format when there is no usable RSBSA reference).
 *
 * @return array{code:string,sequence:int}
 */
function damage_report_allocate_code(PDO $connection, int $farmerId): array
{
    $allocated = farmer_report_allocate_code($connection, $farmerId);
    return ['code' => $allocated['code'] ?? damage_report_legacy_code(), 'sequence' => $allocated['sequence']];
}

/** True for a code in the RSBSA-reference format. */
function damage_report_code_is_rsbsa(string $code): bool
{
    return preg_match('/^\d{2}-\d{2}-\d{2}-\d{3}-\d{6}-[1-9]\d*$/D', $code) === 1;
}
