<?php
declare(strict_types=1);

// Administrator account data used by the admin website (login and Settings).

const ADMIN_PREFERENCE_DEFAULTS = [
    'new_farmer_registration' => 1,
    'farmer_verification_updates' => 1,
    'new_damage_reports' => 1,
    'damage_report_status_changes' => 1,
    'weather_advisory_updates' => 1,
];

/** Login lookup; usernames match case-insensitively on both databases (citext / utf8mb4_general_ci). */
function admin_find_by_username(PDO $connection, string $username): ?array
{
    $statement = $connection->prepare('SELECT id, full_name, username, password_hash, role, status, account_status FROM admins WHERE username = ? LIMIT 1');
    $statement->execute([$username]);
    return $statement->fetch() ?: null;
}

function admin_username_taken(PDO $connection, string $username, int $exceptAdminId): bool
{
    $statement = $connection->prepare('SELECT id FROM admins WHERE username = ? AND id <> ? LIMIT 1');
    $statement->execute([$username, $exceptAdminId]);
    return (bool) $statement->fetch();
}

/** Blank email/contact values are stored as NULL. */
function admin_update_profile(PDO $connection, int $adminId, string $fullName, string $username, string $email, string $contactNumber): void
{
    $statement = $connection->prepare('UPDATE admins SET full_name = ?, username = ?, email = NULLIF(?, \'\'), contact_number = NULLIF(?, \'\') WHERE id = ?');
    $statement->execute([$fullName, $username, $email, $contactNumber, $adminId]);
}

function admin_password_hash(PDO $connection, int $adminId): ?string
{
    $statement = $connection->prepare('SELECT password_hash FROM admins WHERE id = ? LIMIT 1');
    $statement->execute([$adminId]);
    $admin = $statement->fetch();
    return $admin ? (string) $admin['password_hash'] : null;
}

function admin_update_password(PDO $connection, int $adminId, string $passwordHash): void
{
    $statement = $connection->prepare('UPDATE admins SET password_hash = ? WHERE id = ?');
    $statement->execute([$passwordHash, $adminId]);
}

/** @param array<string,int> $values 0/1 per key of ADMIN_PREFERENCE_DEFAULTS */
function admin_save_preferences(PDO $connection, int $adminId, array $values): void
{
    $columns = array_keys(ADMIN_PREFERENCE_DEFAULTS);
    $statement = $connection->prepare(db_sql_upsert(
        $connection,
        'INSERT INTO admin_settings (admin_id, ' . implode(', ', $columns) . ') VALUES (?, ?, ?, ?, ?, ?)',
        ['admin_id'],
        $columns
    ));
    $statement->execute([$adminId, ...array_map(static fn(string $column): int => (int) $values[$column], $columns)]);
}

function admin_profile(PDO $connection, int $adminId): array
{
    $statement = $connection->prepare('SELECT full_name, username, email, contact_number, role FROM admins WHERE id = ? LIMIT 1');
    $statement->execute([$adminId]);
    return $statement->fetch() ?: [];
}

/** Saved notification preferences, falling back to ADMIN_PREFERENCE_DEFAULTS. */
function admin_preferences(PDO $connection, int $adminId): array
{
    $preferences = ADMIN_PREFERENCE_DEFAULTS;
    $statement = $connection->prepare('SELECT ' . implode(', ', array_keys(ADMIN_PREFERENCE_DEFAULTS)) . ' FROM admin_settings WHERE admin_id = ? LIMIT 1');
    $statement->execute([$adminId]);
    if ($saved = $statement->fetch()) {
        foreach ($preferences as $key => $default) $preferences[$key] = (int) $saved[$key];
    }
    return $preferences;
}
