<?php
declare(strict_types=1);

// Server-side recovery for the single AgriReach administrator (command line only; requires access to this machine).
// The admin website has no password reset, and only one admin can be active, so this is the documented way back in
// if that admin's password is lost or no admin is active. Every action is recorded in admin_account_events.
//
//   php scripts/admin-recovery.php status
//   php scripts/admin-recovery.php reset-password --username=<active admin username>   (new password read from standard input, twice)
//   php scripts/admin-recovery.php activate --username=<admin username>                 (only when NO admin is active)
//
// Former admins replaced through Settings → Admin Account cannot be given a password here while another admin is active.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../backend/config/database.php';
require_once __DIR__ . '/../backend/lib/admin_replacement.php';

$command = $argv[1] ?? '';
$username = '';
foreach (array_slice($argv, 2) as $argument) if (str_starts_with($argument, '--username=')) $username = trim(substr($argument, 11));
$fail = static function (string $message): never { fwrite(STDERR, $message . PHP_EOL); exit(1); };
$connection = db();

if ($command === 'status') {
    foreach ($connection->query('SELECT id, username, status, account_status FROM admins ORDER BY id')->fetchAll() as $row) {
        printf("admin #%d  %-40s %s / %s\n", $row['id'], $row['username'], $row['status'], $row['account_status']);
    }
    $open = admin_replacement_open($connection);
    echo $open ? "Open replacement process: {$open['status']} for {$open['email']}\n" : "No open replacement process.\n";
    exit(0);
}

$statement = $connection->prepare('SELECT id, username, status, account_status FROM admins WHERE username = ? LIMIT 1');
$statement->execute([$username]);
$admin = $statement->fetch() ?: $fail('No administrator account uses that username.');

if ($command === 'reset-password') {
    if ($admin['status'] !== 'active' || $admin['account_status'] !== 'Active') $fail('Only the active administrator\'s password can be reset. Former administrators do not regain access.');
    fwrite(STDOUT, "New password: ");
    $password = rtrim((string) fgets(STDIN), "\r\n");
    fwrite(STDOUT, "Repeat password: ");
    $repeat = rtrim((string) fgets(STDIN), "\r\n");
    $policy = farmer_password_policy_error($password);
    if ($policy !== null) $fail($policy);
    if (strlen($password) > 72 || !hash_equals($password, $repeat)) $fail('The passwords do not match (or are longer than 72 characters).');
    $connection->prepare('UPDATE admins SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), (int) $admin['id']]);
    admin_account_event($connection, 'recovery_password_reset', null, (int) $admin['id'], null, 'Password of ' . $admin['username'] . ' reset from the server command line');
    echo "Password updated for {$admin['username']}.\n";
    exit(0);
}

if ($command === 'activate') {
    $connection->beginTransaction();
    try {
        // Locks every admin row: the decision "nobody is active" cannot change before this commit.
        $active = $connection->query("SELECT id FROM admins WHERE status = 'active' FOR UPDATE")->fetchAll();
        if ($active) throw new RuntimeException('An administrator is already active. Use Settings → Admin Account to replace them.');
        $connection->prepare("UPDATE admins SET status = 'active', account_status = 'Active' WHERE id = ?")->execute([(int) $admin['id']]);
        admin_account_event($connection, 'recovery_admin_activated', null, (int) $admin['id'], null, $admin['username'] . ' made the active administrator from the server command line (no admin was active)');
        $connection->commit();
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) $connection->rollBack();
        $fail($exception instanceof RuntimeException && !$exception instanceof PDOException ? $exception->getMessage() : 'Activation failed: ' . get_class($exception));
    }
    echo "{$admin['username']} is now the active administrator.\n";
    exit(0);
}

$fail('Usage: php scripts/admin-recovery.php status | reset-password --username=<name> | activate --username=<name>');
