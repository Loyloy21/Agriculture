<?php
declare(strict_types=1);

require_once __DIR__ . '/farmer_identity.php';

// Data access behind the admin "Manage Users" page: approved farmers and administrators.

/** Maps the page's user type to its table; anything else is rejected before SQL is built. */
function admin_user_table(string $userType): string
{
    return match ($userType) {
        'farmer' => 'farmers',
        'admin' => 'admins',
        default => throw new InvalidArgumentException('Invalid user type.'),
    };
}

/**
 * True when the email or contact number already belongs to any farmer or administrator.
 * $exceptType/$exceptId exclude the account being edited.
 */
function admin_user_contact_taken(PDO $connection, string $email, string $contactNumber, ?string $exceptType = null, ?int $exceptId = null): bool
{
    if ($exceptType === 'farmer') {
        $statement = $connection->prepare('SELECT EXISTS(SELECT 1 FROM farmers WHERE (email = ? OR contact_number = ?) AND id <> ?) OR EXISTS(SELECT 1 FROM admins WHERE email = ? OR contact_number = ?) AS already_used');
        $statement->execute([$email, $contactNumber, $exceptId, $email, $contactNumber]);
    } elseif ($exceptType === 'admin') {
        $statement = $connection->prepare('SELECT EXISTS(SELECT 1 FROM farmers WHERE email = ? OR contact_number = ?) OR EXISTS(SELECT 1 FROM admins WHERE (email = ? OR contact_number = ?) AND id <> ?) AS already_used');
        $statement->execute([$email, $contactNumber, $email, $contactNumber, $exceptId]);
    } else {
        $statement = $connection->prepare('SELECT EXISTS(SELECT 1 FROM farmers WHERE email = ? OR contact_number = ?) OR EXISTS(SELECT 1 FROM admins WHERE email = ? OR contact_number = ?) AS already_used');
        $statement->execute([$email, $contactNumber, $email, $contactNumber]);
    }
    return (int) $statement->fetch()['already_used'] === 1;
}

/**
 * Creates a Pending farmer with its primary farm and ID record in one transaction.
 * $farmer: full_name, first_name, middle_name, last_name, extension_name, gender, date_of_birth, contact_number, email, address, password_hash
 *          (middle_name / extension_name are optional; blank is stored as NULL)
 * $farm: farm_name, farm_type, agricultural_category, land_area, farm_address, latitude, longitude, main_product
 * $id: id_type, id_number, image_path (stored filename in private ID storage)
 */
function admin_create_pending_farmer(PDO $connection, array $farmer, array $farm, array $id): int
{
    $connection->beginTransaction();
    try {
        $statement = $connection->prepare("INSERT INTO farmers (full_name, first_name, middle_name, last_name, extension_name, gender, date_of_birth, contact_number, email, address, password_hash, account_status, verification_status) VALUES (?, ?, NULLIF(?, ''), ?, NULLIF(?, ''), ?, ?, ?, ?, ?, ?, 'Active', 'Pending')");
        $statement->execute([$farmer['full_name'], $farmer['first_name'], (string) ($farmer['middle_name'] ?? ''), $farmer['last_name'], (string) ($farmer['extension_name'] ?? ''), $farmer['gender'], $farmer['date_of_birth'], $farmer['contact_number'], $farmer['email'], $farmer['address'], $farmer['password_hash']]);
        $farmerId = db_insert_id($connection, 'farmers');
        // Coordinates are validated by the caller; blank inputs arrive as null (PostgreSQL rejects '' for numeric columns).
        $statement = $connection->prepare("INSERT INTO farm_profiles (farmer_id, farm_name, farm_type, agricultural_category, land_area, farm_address, latitude, longitude, main_product, status, is_primary) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Active', 1)");
        $statement->execute([$farmerId, $farm['farm_name'], $farm['farm_type'], $farm['agricultural_category'], $farm['land_area'], $farm['farm_address'], $farm['latitude'], $farm['longitude'], $farm['main_product']]);
        $statement = $connection->prepare('INSERT INTO farmer_ids (farmer_id, id_type, id_number, image_path) VALUES (?, ?, ?, ?)');
        $statement->execute([$farmerId, $id['id_type'], $id['id_number'], $id['image_path']]);
        $connection->commit();
        return $farmerId;
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) $connection->rollBack();
        throw $exception;
    }
}

function admin_farmer_verification_status(PDO $connection, int $farmerId): ?string
{
    $statement = $connection->prepare('SELECT verification_status FROM farmers WHERE id = ? LIMIT 1');
    $statement->execute([$farmerId]);
    $row = $statement->fetch();
    return $row ? (string) $row['verification_status'] : null;
}

/**
 * Updates name/contact/email/address and reports whether any stored value actually changed.
 * (MySQL's affected_rows counted only changed rows, PostgreSQL counts matched rows, so the values
 * are compared explicitly to keep the "no changes" message identical on both databases.)
 */
function admin_user_update_profile(PDO $connection, string $userType, int $userId, string $fullName, string $contactNumber, string $email, string $address): bool
{
    $table = admin_user_table($userType);
    $before = $connection->prepare("SELECT full_name, contact_number, email, address FROM {$table} WHERE id = ? LIMIT 1");
    $before->execute([$userId]);
    $stored = $before->fetch();
    $statement = $connection->prepare("UPDATE {$table} SET full_name = ?, contact_number = ?, email = ?, address = ? WHERE id = ?");
    $statement->execute([$fullName, $contactNumber, $email, $address, $userId]);
    return $stored && [$stored['full_name'], $stored['contact_number'], $stored['email'], $stored['address']] !== [$fullName, $contactNumber, $email, $address];
}

/**
 * Updates a farmer's name parts (full_name recomposed by the caller's rules), contact, email and address,
 * and reports whether any stored value actually changed. Blank optional parts are stored as NULL.
 *
 * @param array{first:string,middle:string,last:string,extension:string} $parts
 */
function admin_farmer_update_profile(PDO $connection, int $farmerId, array $parts, string $contactNumber, string $email, string $address): bool
{
    $fullName = farmer_compose_full_name($parts['first'], $parts['middle'], $parts['last'], $parts['extension']);
    $before = $connection->prepare('SELECT full_name, first_name, middle_name, last_name, extension_name, contact_number, email, address FROM farmers WHERE id = ? LIMIT 1');
    $before->execute([$farmerId]);
    $stored = $before->fetch();
    $statement = $connection->prepare("UPDATE farmers SET full_name = ?, first_name = ?, middle_name = NULLIF(?, ''), last_name = ?, extension_name = NULLIF(?, ''), contact_number = ?, email = ?, address = ? WHERE id = ?");
    $statement->execute([$fullName, $parts['first'], $parts['middle'], $parts['last'], $parts['extension'], $contactNumber, $email, $address, $farmerId]);
    $after = [$fullName, $parts['first'], $parts['middle'], $parts['last'], $parts['extension'], $contactNumber, $email, $address];
    return $stored && [$stored['full_name'], $stored['first_name'], (string) $stored['middle_name'], $stored['last_name'], (string) $stored['extension_name'], $stored['contact_number'], $stored['email'], $stored['address']] !== $after;
}

function admin_user_account_status(PDO $connection, string $userType, int $userId): ?string
{
    $table = admin_user_table($userType);
    $statement = $connection->prepare("SELECT account_status FROM {$table} WHERE id = ? LIMIT 1");
    $statement->execute([$userId]);
    $row = $statement->fetch();
    return $row ? (string) $row['account_status'] : null;
}

function admin_user_set_account_status(PDO $connection, string $userType, int $userId, string $accountStatus): void
{
    $table = admin_user_table($userType);
    $statement = $connection->prepare("UPDATE {$table} SET account_status = ? WHERE id = ?");
    $statement->execute([$accountStatus, $userId]);
}

/** @return array{0:string,1:array} FROM/WHERE clause and parameters for the combined user list. */
function admin_user_list_query(string $search, string $role, string $accountStatus): array
{
    $where = [];
    $params = [];
    if ($search !== '') {
        $where[] = '(LOWER(user_name) LIKE LOWER(?) OR LOWER(email) LIKE LOWER(?) OR LOWER(contact_number) LIKE LOWER(?))';
        $searchValue = '%' . $search . '%';
        $params = [$searchValue, $searchValue, $searchValue];
    }
    if ($role !== '') { $where[] = 'user_role = ?'; $params[] = $role; }
    if ($accountStatus !== '') { $where[] = 'account_status = ?'; $params[] = $accountStatus; }
    $fromSql = "FROM (
                SELECT CONCAT('farmer:', f.id) AS user_key, f.id AS user_id, 'farmer' AS user_type, f.full_name AS user_name, f.first_name, f.middle_name, f.last_name, f.extension_name, f.contact_number, f.email, f.address, 'Farmer' AS user_role, f.account_status, f.verification_status, f.date_registered, fp.farm_name, fp.farm_type, fp.agricultural_category, fp.farm_address
                FROM farmers f LEFT JOIN farm_profiles fp ON fp.id = (
                    SELECT fp2.id FROM farm_profiles fp2 WHERE fp2.farmer_id = f.id AND fp2.status = 'Active'
                    ORDER BY fp2.is_primary DESC, fp2.id ASC LIMIT 1
                )
                WHERE f.verification_status = 'Approved'
                UNION ALL
                SELECT CONCAT('admin:', a.id), a.id, 'admin', a.full_name, NULL, NULL, NULL, NULL, a.contact_number, a.email, a.address, 'Administrator', a.account_status, NULL, a.created_at, NULL, NULL, NULL, NULL
                FROM admins a
            ) AS all_users" . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
    return [$fromSql, $params];
}

function admin_user_count(PDO $connection, string $search, string $role, string $accountStatus): int
{
    [$fromSql, $params] = admin_user_list_query($search, $role, $accountStatus);
    $statement = $connection->prepare('SELECT COUNT(*) AS total ' . $fromSql);
    $statement->execute($params);
    return (int) $statement->fetch()['total'];
}

function admin_user_page(PDO $connection, string $search, string $role, string $accountStatus, int $limit, int $offset): array
{
    [$fromSql, $params] = admin_user_list_query($search, $role, $accountStatus);
    $statement = $connection->prepare('SELECT * ' . $fromSql . ' ORDER BY date_registered DESC, user_type ASC, user_id DESC LIMIT ? OFFSET ?');
    $statement->execute([...$params, $limit, $offset]);
    return $statement->fetchAll();
}
