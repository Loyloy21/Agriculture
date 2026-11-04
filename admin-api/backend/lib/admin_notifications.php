<?php
declare(strict_types=1);

function admin_notification_state_change(PDO $connection, int $adminId, int $notificationId, string $action): void
{
    if ($adminId < 1 || $notificationId < 1 || !in_array($action, ['mark_read', 'mark_unread', 'delete'], true)) {
        throw new InvalidArgumentException('Invalid admin notification action.');
    }
    // Archive only the administrator's view, never delete a farmer's notice.
    $column = $action === 'delete' ? 'is_archived' : 'is_read';
    $value = $action === 'mark_unread' ? 0 : 1;
    $sql = db_sql_upsert($connection, "INSERT INTO admin_notification_state (admin_id, notification_id, {$column})
            SELECT ?, id, ? FROM notifications WHERE id = ?", ['admin_id', 'notification_id'], [$column]);
    $statement = $connection->prepare($sql);
    $statement->execute([$adminId, $value, $notificationId]);
}

/** Approved, active farmers, for the "send to individual farmer" picker. */
function admin_notification_recipient_options(PDO $connection): array
{
    return $connection->query("SELECT id, full_name FROM farmers WHERE verification_status = 'Approved' AND account_status = 'Active' ORDER BY full_name")->fetchAll();
}

/**
 * Sends an admin-written notice to one approved, active farmer or to all of them, in one transaction.
 * @return int number of farmers notified
 */
function admin_send_farmer_notification(PDO $connection, int $adminId, string $recipientType, ?int $farmerId, string $storedType, string $title, string $message): int
{
    $connection->beginTransaction();
    try {
        $insert = $connection->prepare('INSERT INTO notifications (farmer_id, notification_type, title, message, is_read, created_by) VALUES (?, ?, ?, ?, 0, ?)');
        if ($recipientType === 'individual') {
            $recipient = $connection->prepare("SELECT id FROM farmers WHERE id = ? AND verification_status = 'Approved' AND account_status = 'Active' LIMIT 1");
            $recipient->execute([$farmerId]);
            if (!$recipient->fetch()) throw new RuntimeException('The selected farmer is not an active verified farmer.');
            $insert->execute([$farmerId, $storedType, $title, $message, $adminId]);
            $count = 1;
        } else {
            $recipients = $connection->query("SELECT id FROM farmers WHERE verification_status = 'Approved' AND account_status = 'Active'");
            $count = 0;
            while ($recipient = $recipients->fetch()) {
                $insert->execute([(int) $recipient['id'], $storedType, $title, $message, $adminId]);
                $count++;
            }
        }
        $connection->commit();
        return $count;
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) $connection->rollBack();
        throw $exception;
    }
}

/**
 * Notifications as seen by one administrator (their own read/archive state), newest first.
 * $filters: search, type ('' or one of $allowedTypes), read ('', 'Read', 'Unread'), date ('' or Y-m-d)
 */
function admin_notification_feed(PDO $connection, int $adminId, array $filters, array $allowedTypes): array
{
    $where = ['COALESCE(ans.is_archived, 0) = 0'];
    $params = [$adminId];
    $search = (string) ($filters['search'] ?? '');
    if ($search !== '') { $where[] = '(LOWER(n.title) LIKE LOWER(?) OR LOWER(n.message) LIKE LOWER(?) OR LOWER(f.full_name) LIKE LOWER(?))'; $term = '%' . $search . '%'; array_push($params, $term, $term, $term); }
    if (in_array($filters['type'] ?? '', $allowedTypes, true)) { $where[] = 'n.notification_type = ?'; $params[] = $filters['type']; }
    if (in_array($filters['read'] ?? '', ['Unread', 'Read'], true)) { $where[] = 'COALESCE(ans.is_read, 0) = ?'; $params[] = $filters['read'] === 'Read' ? 1 : 0; }
    $date = (string) ($filters['date'] ?? '');
    if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { $where[] = 'CAST(n.created_at AS DATE) = ?'; $params[] = $date; }
    $sql = 'SELECT n.*, n.is_read AS farmer_is_read, COALESCE(ans.is_read, 0) AS admin_is_read, f.full_name AS farmer_name, dr.report_code, wa.title AS advisory_title, a.full_name AS creator_name FROM notifications n LEFT JOIN farmers f ON f.id = n.farmer_id LEFT JOIN damage_reports dr ON dr.id = n.related_report_id LEFT JOIN weather_advisories wa ON wa.id = n.related_advisory_id LEFT JOIN admins a ON a.id = n.created_by LEFT JOIN admin_notification_state ans ON ans.notification_id = n.id AND ans.admin_id = ? WHERE ' . implode(' AND ', $where) . ' ORDER BY n.created_at DESC, n.id DESC';
    $statement = $connection->prepare($sql);
    $statement->execute($params);
    $notifications = $statement->fetchAll();
    foreach ($notifications as &$notice) $notice['is_read'] = (int) $notice['admin_is_read'];
    unset($notice);
    return $notifications;
}

/** @return array{unread:int,registrations:int,reports:int,advisories:int} counts for one administrator's view */
function admin_notification_summary(PDO $connection, int $adminId): array
{
    $statement = $connection->prepare("SELECT SUM(CASE WHEN COALESCE(ans.is_read, 0) = 0 THEN 1 ELSE 0 END) unread, SUM(CASE WHEN notification_type = 'Farmer Registration' THEN 1 ELSE 0 END) registrations, SUM(CASE WHEN notification_type IN ('Damage Report Submitted','Damage Report Status Update','Admin Response') THEN 1 ELSE 0 END) reports, SUM(CASE WHEN notification_type = 'Weather Advisory' THEN 1 ELSE 0 END) advisories FROM notifications n LEFT JOIN admin_notification_state ans ON ans.notification_id = n.id AND ans.admin_id = ? WHERE COALESCE(ans.is_archived, 0) = 0");
    $statement->execute([$adminId]);
    return array_map('intval', $statement->fetch() ?: ['unread' => 0, 'registrations' => 0, 'reports' => 0, 'advisories' => 0]);
}
