<?php
declare(strict_types=1);

// Controlled tests sign in as an administrator, but AgriReach allows exactly ONE active admin (database index
// uq_admins_single_active). test_admin_takeover() makes a controlled test admin the active one and remembers who was
// active; test_admin_restore() makes them active again (call it in `finally`, after the test removed its own admin).
// Refuses to run against Supabase: the live administrator is never swapped out by a test.
require_once dirname(__DIR__, 2) . '/backend/config/database.php';

function test_admin_refuse_live(): void
{
    if (str_contains(strtolower((string) (db_connection_settings()['host'] ?? '')), 'supabase')) {
        throw new RuntimeException('Refusing: controlled admin tests swap the active administrator and must run on a disposable database, not Supabase.');
    }
}

/** @return array{id:int,username:string,previous:list<int>} */
function test_admin_takeover(PDO $connection, string $fullName, string $username, string $passwordHash, ?string $email = null): array
{
    test_admin_refuse_live();
    // Inside a caller's transaction (fixtures that create everything atomically) no nested transaction is opened.
    $own = !$connection->inTransaction();
    if ($own) $connection->beginTransaction();
    try {
        $previous = array_map('intval', $connection->query("SELECT id FROM admins WHERE status = 'active'")->fetchAll(PDO::FETCH_COLUMN));
        $connection->exec("UPDATE admins SET status = 'inactive' WHERE status = 'active'");
        $connection->prepare("INSERT INTO admins (full_name, username, password_hash, role, status, email, account_status) VALUES (?, ?, ?, 'admin', 'active', ?, 'Active')")
            ->execute([$fullName, $username, $passwordHash, $email]);
        $id = db_insert_id($connection, 'admins');
        if ($own) $connection->commit();
    } catch (Throwable $exception) {
        if ($own && $connection->inTransaction()) $connection->rollBack();
        throw $exception;
    }
    return ['id' => $id, 'username' => $username, 'previous' => $previous];
}

/** Deactivates whichever admin a test left active and reactivates the administrator(s) active before the test. */
function test_admin_restore(PDO $connection, array $takeover): void
{
    $own = !$connection->inTransaction();
    if ($own) $connection->beginTransaction();
    try {
        $keep = $takeover['previous'] ?: [0];
        $placeholders = implode(', ', array_fill(0, count($keep), '?'));
        $connection->prepare("UPDATE admins SET status = 'inactive' WHERE status = 'active' AND id NOT IN ({$placeholders})")->execute($keep);
        if ($takeover['previous']) $connection->prepare("UPDATE admins SET status = 'active' WHERE id IN ({$placeholders})")->execute($keep);
        if ($own) $connection->commit();
    } catch (Throwable $exception) {
        if ($own && $connection->inTransaction()) $connection->rollBack();
        throw $exception;
    }
}
