<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/farmer_auth.php';
require_once dirname(__DIR__, 3) . '/backend/lib/weather_advisories.php';

function mobile_notification_type(string $type, string $title): string
{
    $value = strtolower($type . ' ' . $title);
    if (str_contains($value, 'reject') || str_contains($value, 'failed')) return 'error';
    if (str_contains($value, 'approve') || str_contains($value, 'resolved') || str_contains($value, 'verified')) return 'success';
    if (str_contains($value, 'warning') || str_contains($value, 'required')) return 'warning';
    return 'info';
}

$farmer = authenticated_farmer();
$connection = db();
$farmerId = (int) $farmer['id'];
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    try {
        sync_weather_advisory_notifications_for_farmer($connection, $farmer);
    } catch (Throwable $exception) {
        error_log('Farmer notification advisory sync failed: ' . $exception->getMessage());
    }
    // adv: the linked advisory, so its notification shows the current Key advice instead of a cut-off message.
    $rows = weather_advisory_fetch_all($connection,
        "SELECT n.id, n.notification_type, n.title, n.message, n.related_report_id,
                n.related_advisory_id, n.is_read, n.created_at, dr.report_code,
                adv.agricultural_category, adv.weather_condition, adv.coverage_scope, adv.expiration_date,
                adv.advisory_message, adv.key_advice AS key_advice
         FROM notifications n
         LEFT JOIN damage_reports dr ON dr.id = n.related_report_id AND dr.farmer_id = n.farmer_id
         LEFT JOIN weather_advisories adv ON adv.id = n.related_advisory_id
         WHERE n.farmer_id = ?
           AND ((n.related_advisory_id IS NULL AND n.notification_type <> 'Weather Advisory') OR EXISTS (
                SELECT 1 FROM weather_advisories wa
                WHERE wa.id = n.related_advisory_id
                  AND wa.status = 'Active'
                  AND wa.effective_date <= CURRENT_DATE
                  AND (wa.expiration_date IS NULL OR wa.expiration_date >= CURRENT_DATE)
                  AND EXISTS (
                      SELECT 1 FROM farm_profiles fp
                      WHERE fp.farmer_id = n.farmer_id AND fp.status = 'Active'
                        AND fp.location_review_status = 'Verified'
                        AND fp.city_psgc_code = wa.coverage_city_psgc_code
                        AND fp.agricultural_category = wa.agricultural_category
                        AND (wa.coverage_scope = 'City' OR EXISTS (
                            SELECT 1 FROM weather_advisory_barangays wab
                            WHERE wab.advisory_id = wa.id AND wab.barangay_code = fp.barangay_psgc_code
                        ))
                  )
           ))
         ORDER BY n.created_at DESC, n.id DESC",
        [$farmerId], 'adv.key_advice');
    $notifications = [];
    foreach ($rows as $row) {
        $message = trim((string) $row['message']);
        $isAdvisory = $row['related_advisory_id'] !== null && $row['advisory_message'] !== null;
        $shortMessage = $isAdvisory ? weather_advisory_preview($row)['text'] : (mb_strlen($message) > 100 ? rtrim(mb_substr($message, 0, 97)) . '...' : $message);
        $notifications[] = [
            'id' => (string) $row['id'],
            'type' => mobile_notification_type((string) $row['notification_type'], (string) $row['title']),
            'title' => (string) $row['title'],
            'shortMessage' => $shortMessage,
            'fullMessage' => $message,
            'context' => $isAdvisory ? weather_advisory_context($row) : null,
            'createdAt' => str_replace(' ', 'T', (string) $row['created_at']) . '+08:00',
            'isRead' => (bool) $row['is_read'],
            'relatedReportId' => $row['report_code'] === null ? null : (string) $row['report_code'],
            'relatedAdvisoryId' => $row['related_advisory_id'] === null ? null : (string) $row['related_advisory_id'],
        ];
    }
    api_response(200, ['success' => true, 'data' => $notifications]);
}

if ($method !== 'POST') {
    header('Allow: GET, POST');
    api_response(405, ['success' => false, 'message' => 'Method not allowed.']);
}

$body = api_json_body();
$notificationId = filter_var($body['notificationId'] ?? null, FILTER_VALIDATE_INT);
if (($body['action'] ?? '') !== 'mark_read' || !$notificationId) {
    api_response(422, ['success' => false, 'message' => 'The notification action is invalid.']);
}

$statement = $connection->prepare('UPDATE notifications SET is_read = 1 WHERE id = ? AND farmer_id = ?');
$statement->execute([$notificationId, $farmerId]);
if ($statement->rowCount() === 0) {
    $exists = $connection->prepare('SELECT id FROM notifications WHERE id = ? AND farmer_id = ? LIMIT 1');
    $exists->execute([$notificationId, $farmerId]);
    if (!$exists->fetch()) api_response(404, ['success' => false, 'message' => 'Notification not found.']);
}
api_response(200, ['success' => true, 'data' => ['id' => (string) $notificationId, 'isRead' => true]]);
