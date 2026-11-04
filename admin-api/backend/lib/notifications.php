<?php
declare(strict_types=1);

/** Reusable notification writer for future registration, verification, report, and advisory events. */
function create_farmer_notification(PDO $connection, int $farmer_id, string $notification_type, string $title, string $message, ?int $report_id = null, ?int $advisory_id = null, ?int $created_by = null): int
{
    $statement = $connection->prepare('INSERT INTO notifications (farmer_id, notification_type, title, message, related_report_id, related_advisory_id, is_read, created_by) VALUES (?, ?, ?, ?, ?, ?, 0, ?)');
    $statement->execute([$farmer_id, $notification_type, $title, $message, $report_id, $advisory_id, $created_by]);
    return db_insert_id($connection, 'notifications');
}

function notify_verified_farmers(PDO $connection, string $notification_type, string $title, string $message, ?int $report_id = null, ?int $advisory_id = null, ?int $created_by = null): int
{
    $recipients = $connection->query("SELECT id FROM farmers WHERE verification_status = 'Approved' AND account_status = 'Active'");
    $count = 0;
    while ($farmer = $recipients->fetch()) {
        create_farmer_notification($connection, (int) $farmer['id'], $notification_type, $title, $message, $report_id, $advisory_id, $created_by);
        $count++;
    }
    return $count;
}
