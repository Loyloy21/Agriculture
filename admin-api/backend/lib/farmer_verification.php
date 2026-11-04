<?php
declare(strict_types=1);

require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/mailer.php';

function change_farmer_verification(PDO $connection, int $farmerId, int $adminId, string $action, ?string $rejectionReason = null): array
{
    if (!in_array($action, ['approve', 'reject'], true)) {
        throw new InvalidArgumentException('Invalid verification action.');
    }

    $decision = $action === 'approve' ? 'Approved' : 'Rejected';
    $reason = $action === 'reject' ? trim((string) $rejectionReason) : null;

    $connection->beginTransaction();
    try {
        $statement = $connection->prepare('SELECT id, full_name, email, verification_status, rejection_reason FROM farmers WHERE id = ? FOR UPDATE');
        $statement->execute([$farmerId]);
        $farmer = $statement->fetch();
        if (!$farmer) throw new RuntimeException('Farmer was not found.');

        $currentStatus = (string) $farmer['verification_status'];
        if ($currentStatus !== 'Pending') {
            $connection->commit();
            return [
                'changed' => false,
                'stale' => true,
                'current_status' => $currentStatus,
                'farmer' => $farmer,
                'decision' => $currentStatus,
                'reason' => $farmer['rejection_reason'] !== null ? (string) $farmer['rejection_reason'] : null,
            ];
        }

        // Validate the rejection reason only after locking and checking the latest
        // status. An old/stale form must never be able to alter a decided record.
        if ($action === 'reject' && mb_strlen((string) $reason) < 5) {
            throw new InvalidArgumentException('Please provide a rejection reason of at least 5 characters.');
        }
        if ($reason !== null && mb_strlen($reason) > 1000) {
            throw new InvalidArgumentException('The rejection reason must be 1,000 characters or fewer.');
        }

        if ($decision === 'Approved') {
            $update = $connection->prepare("UPDATE farmers SET verification_status = 'Approved', account_status = 'Active', rejection_reason = NULL, verified_at = NOW() WHERE id = ? AND verification_status = 'Pending'");
            $update->execute([$farmerId]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('The verification status changed before this decision could be saved.');
            }
            create_farmer_notification($connection, $farmerId, 'Farmer Verification', 'Farmer account approved', 'Your AgriReach farmer account has been approved and is now verified.', null, null, $adminId);
        } else {
            $update = $connection->prepare("UPDATE farmers SET verification_status = 'Rejected', account_status = 'Inactive', rejection_reason = ?, verified_at = NULL WHERE id = ? AND verification_status = 'Pending'");
            $update->execute([$reason, $farmerId]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('The verification status changed before this decision could be saved.');
            }
            create_farmer_notification($connection, $farmerId, 'Farmer Verification', 'Farmer account requires changes', 'Your farmer registration was rejected. Reason: ' . $reason, null, null, $adminId);
        }

        $connection->commit();
        $farmer['verification_status'] = $decision;
        $farmer['rejection_reason'] = $reason;
        return [
            'changed' => true,
            'stale' => false,
            'current_status' => $decision,
            'farmer' => $farmer,
            'decision' => $decision,
            'reason' => $reason,
        ];
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) $connection->rollBack();
        throw $exception;
    }
}

function verification_email_failure_message(?string $failureCode): string
{
    return match ($failureCode) {
        'test_recipient_required' => 'Development safety routing is active, but no test recipient is configured. Enter an explicit test recipient when retrying, or configure MAIL_TEST_RECIPIENT locally. The real farmer was not emailed.',
        'invalid_test_recipient' => 'Enter a valid development test recipient email address. The real farmer was not emailed.',
        'invalid_recipient' => 'The registered farmer email address is invalid. Correct the farmer email before live delivery.',
        'smtp_configuration_missing' => 'The Gmail SMTP sender configuration is incomplete. Review Email Settings and save the sender again.',
        'smtp_credentials_missing' => 'The Gmail SMTP credential is unavailable. Review the sender configuration and encryption key.',
        'smtp_encryption_invalid' => 'The SMTP encryption setting is invalid. Use TLS for Gmail SMTP.',
        'mailer_not_installed' => 'The server email library is unavailable. Install the configured PHPMailer dependency.',
        'smtp_not_accepted' => 'Gmail SMTP did not accept the message. Verify the sender, App Password, and SMTP test status.',
        'duplicate_retry_prevented' => 'This failed delivery already has a retry attempt. Refresh the page to view its latest status.',
        'delivery_log_unavailable' => 'The delivery attempt could not be recorded. Check the email delivery table and database connection.',
        'message_build_failed', 'delivery_error' => 'The email could not be prepared or delivered. Review Email Settings and the safe server log.',
        default => 'Email delivery failed. Review Email Settings before retrying.',
    };
}

function deliver_and_log_verification_email(
    PDO $connection,
    array $farmer,
    int $adminId,
    string $decision,
    ?string $reason,
    ?string $developmentTestRecipient = null,
    ?int $retryOfDeliveryId = null
): array
{
    $configuration = registration_verification_email_configuration($developmentTestRecipient);
    $transport = (string) $configuration['transport'];
    $status = 'Pending';
    $recipient = strtolower(trim((string) $farmer['email']));
    $developmentRouted = $configuration['app_environment'] !== 'production';
    $routingMode = $developmentRouted ? 'Development Test' : 'Live';
    $deliveryRecipient = $developmentRouted && filter_var($configuration['test_recipient'], FILTER_VALIDATE_EMAIL)
        ? strtolower(trim((string) $configuration['test_recipient']))
        : ($developmentRouted ? null : $recipient);
    $routeFailure = null;
    try {
        $route = registration_email_delivery_route($configuration, $recipient, true);
        $routingMode = (string) $route['routing_mode'];
        $deliveryRecipient = (string) $route['delivery_recipient'];
    } catch (VerificationEmailException $exception) {
        $routeFailure = $exception;
    }

    try {
        $insert = $connection->prepare('INSERT INTO verification_email_deliveries (farmer_id, admin_id, decision, recipient_email, delivery_recipient_email, routing_mode, transport, status, retry_of_delivery_id, attempted_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ' . db_sql_utc_now($connection) . ')');
        $farmerId = (int) $farmer['id'];
        $insert->execute([$farmerId, $adminId, $decision, $recipient, $deliveryRecipient, $routingMode, $transport, $status, $retryOfDeliveryId]);
        $deliveryId = db_insert_id($connection, 'verification_email_deliveries');
    } catch (Throwable $exception) {
        if ($retryOfDeliveryId !== null && db_is_duplicate_key($exception)) {
            return ['status' => 'Failed', 'failure_code' => 'duplicate_retry_prevented', 'development_routed' => $developmentRouted, 'routing_mode' => $routingMode, 'delivery_recipient' => $deliveryRecipient];
        }
        error_log('Verification email delivery log could not be created.');
        return ['status' => 'Failed', 'failure_code' => 'delivery_log_unavailable', 'development_routed' => $developmentRouted, 'routing_mode' => $routingMode, 'delivery_recipient' => $deliveryRecipient];
    }

    try {
        if ($routeFailure instanceof VerificationEmailException) {
            throw $routeFailure;
        }
        $status = send_prepared_registration_email(
            $configuration,
            $recipient,
            (string) $farmer['full_name'],
            build_verification_email((string) $farmer['full_name'], $decision, $reason),
            true
        );
        try {
            $update = $connection->prepare("UPDATE verification_email_deliveries SET status = ?, failure_code = NULL, delivered_at = CASE WHEN ? = 'Sent' THEN " . db_sql_utc_now($connection) . " ELSE NULL END WHERE id = ?");
            $update->execute([$status, $status, $deliveryId]);
        } catch (Throwable $logException) {
            error_log('Verification email success status could not be recorded.');
        }
        return ['status' => $status, 'failure_code' => null, 'development_routed' => $developmentRouted, 'routing_mode' => $routingMode, 'delivery_recipient' => $deliveryRecipient];
    } catch (VerificationEmailException $exception) {
        $status = 'Failed';
        $failureCode = $exception->failureCode;
        try {
            $update = $connection->prepare('UPDATE verification_email_deliveries SET status = ?, failure_code = ? WHERE id = ?');
            $update->execute([$status, $failureCode, $deliveryId]);
        } catch (Throwable $logException) {
            error_log('Verification email failure status could not be recorded.');
        }
        return ['status' => $status, 'failure_code' => $failureCode, 'development_routed' => $developmentRouted, 'routing_mode' => $routingMode, 'delivery_recipient' => $deliveryRecipient];
    }
}

function verification_email_retry_data(PDO $connection, int $farmerId): ?array
{
    $statement = $connection->prepare("SELECT f.id, f.full_name, f.email, f.verification_status, f.rejection_reason, failed_delivery.id AS failed_delivery_id
        FROM farmers f
        INNER JOIN verification_email_deliveries failed_delivery
                ON failed_delivery.id = (SELECT latest.id FROM verification_email_deliveries latest WHERE latest.farmer_id = f.id AND latest.decision = f.verification_status ORDER BY latest.id DESC LIMIT 1)
        WHERE f.id = ? AND f.verification_status IN ('Approved', 'Rejected')
          AND failed_delivery.status = 'Failed'
          AND NOT EXISTS (SELECT 1 FROM verification_email_deliveries retry WHERE retry.retry_of_delivery_id = failed_delivery.id)
        LIMIT 1");
    $statement->execute([$farmerId]);
    return $statement->fetch() ?: null;
}

/** Distinct farm types for the verification list filter. */
function farmer_verification_farm_types(PDO $connection): array
{
    return array_map('strval', $connection->query("SELECT DISTINCT farm_type FROM farm_profiles WHERE farm_type IS NOT NULL AND farm_type <> '' ORDER BY farm_type")->fetchAll(PDO::FETCH_COLUMN));
}

/** @return array{0:string,1:array} FROM/WHERE clause and parameters; $status and $farmType are '' or already validated. */
function farmer_verification_list_query(string $search, string $status, string $farmType): array
{
    $where = [];
    $params = [];
    if ($search !== '') { $where[] = 'LOWER(f.full_name) LIKE LOWER(?)'; $params[] = '%' . $search . '%'; }
    if ($status !== '') { $where[] = 'f.verification_status = ?'; $params[] = $status; }
    if ($farmType !== '') { $where[] = 'fp.farm_type = ?'; $params[] = $farmType; }
    $fromSql = "FROM farmers f
            LEFT JOIN farm_profiles fp ON fp.id = (
                SELECT fp2.id FROM farm_profiles fp2 WHERE fp2.farmer_id = f.id AND fp2.status = 'Active'
                ORDER BY fp2.is_primary DESC, fp2.id ASC LIMIT 1
            )
            LEFT JOIN farmer_ids fi ON fi.farmer_id = f.id
            " . ($where ? 'WHERE ' . implode(' AND ', $where) : '');
    return [$fromSql, $params];
}

function farmer_verification_count(PDO $connection, string $search, string $status, string $farmType): int
{
    [$fromSql, $params] = farmer_verification_list_query($search, $status, $farmType);
    $statement = $connection->prepare('SELECT COUNT(*) AS total ' . $fromSql);
    $statement->execute($params);
    return (int) $statement->fetch()['total'];
}

/** One page of registrations with their primary farm, ID record and latest decision-email delivery. */
function farmer_verification_page(PDO $connection, string $search, string $status, string $farmType, int $limit, int $offset): array
{
    [$fromSql, $params] = farmer_verification_list_query($search, $status, $farmType);
    $latestDelivery = static fn(string $column, string $alias): string => "(SELECT ved.{$column}
                      FROM verification_email_deliveries ved
                     WHERE ved.farmer_id = f.id AND ved.decision = f.verification_status
                     ORDER BY ved.id DESC LIMIT 1) AS {$alias}";
    $sql = 'SELECT f.id, f.full_name, f.first_name, f.middle_name, f.last_name, f.extension_name, f.contact_number, f.email, f.address,
                   f.residential_city_name, f.residential_province_name, f.residential_barangay_name,
                   f.residential_purok_sitio, f.residential_landmark, f.residential_location_method,
                   f.residential_boundary_status, f.residential_boundary_source,
                   f.residential_boundary_checked_at, f.residential_latitude, f.residential_longitude,
                   f.verification_status, f.date_registered, f.rejection_reason, f.verified_at,
                   fp.farm_name, fp.farm_type, fp.agricultural_category, fp.land_area, fp.farm_address,
                   fp.city_name, fp.province_name, fp.barangay_name, fp.purok_sitio, fp.landmark,
                   fp.location_method, fp.location_boundary_status, fp.location_boundary_source,
                   fp.location_boundary_checked_at, fp.location_review_status, fp.latitude, fp.longitude,
                   fi.id_type, fi.id_number, fi.image_path, fi.front_image_path, fi.back_image_path,
                   ' . $latestDelivery('status', 'email_delivery_status') . ',
                   ' . $latestDelivery('failure_code', 'email_delivery_failure_code') . ',
                   ' . $latestDelivery('delivery_recipient_email', 'email_delivery_recipient') . ',
                   ' . $latestDelivery('routing_mode', 'email_delivery_routing_mode') . '
            ' . $fromSql . '
            ORDER BY f.date_registered DESC, f.id DESC LIMIT ? OFFSET ?';
    $statement = $connection->prepare($sql);
    $statement->execute([...$params, $limit, $offset]);
    return $statement->fetchAll();
}

/** Most recent decision-email attempts for the Email Settings history table, with retry eligibility. */
function verification_email_delivery_history(PDO $connection, int $limit = 50): array
{
    $statement = $connection->prepare("SELECT ved.id, ved.farmer_id, f.full_name, f.verification_status, ved.decision, ved.recipient_email, ved.delivery_recipient_email, ved.routing_mode, ved.transport, ved.status, ved.failure_code, COALESCE(ved.attempted_at, ved.created_at) AS attempted_at,
        CASE WHEN ved.status = 'Failed'
                  AND f.verification_status = ved.decision
                  AND ved.id = (SELECT MAX(latest.id) FROM verification_email_deliveries latest WHERE latest.farmer_id = ved.farmer_id AND latest.decision = ved.decision)
             THEN 1 ELSE 0 END AS can_retry
        FROM verification_email_deliveries ved
        INNER JOIN farmers f ON f.id = ved.farmer_id
        ORDER BY ved.id DESC
        LIMIT ?");
    $statement->execute([$limit]);
    return $statement->fetchAll();
}
