<?php
declare(strict_types=1);

require_once __DIR__ . '/damage_reports.php';
require_once __DIR__ . '/notifications.php';

final class DamageReportAssessmentException extends RuntimeException
{
    public function __construct(string $message, public readonly int $httpStatus = 422, public readonly array $fieldErrors = [])
    {
        parent::__construct($message);
    }
}

/** Keep currency as a decimal string; floating-point values must not be written to DECIMAL(15,2). */
function damage_report_assessment_cost(string $input): ?string
{
    $value = trim($input);
    if ($value === '') {
        return null;
    }
    if (!preg_match('/^(?:0|[1-9][0-9]{0,12})(?:\.[0-9]{1,2})?$/D', $value)) {
        throw new DamageReportAssessmentException(
            'Check the final assessed cost.',
            422,
            ['final_assessed_cost' => 'Enter a nonnegative peso amount with no more than two decimal places.']
        );
    }
    [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
    return $whole . '.' . str_pad($fraction, 2, '0');
}

/**
 * A category-specific report counts as assessed once an admin has saved the verified cause and the verified
 * main figure in the report's own unit (crop percentage, or the number/amount lost).
 */
function damage_report_verification_complete(?array $details): bool
{
    return $details !== null && $details['verified_at'] !== null && trim((string) $details['verified_cause']) !== ''
        && ($details['verified_crop_damage_percent'] !== null || $details['verified_amount_lost'] !== null);
}

/** @return array{report:array,changed:bool,message:string} */
function damage_report_apply_assessment(PDO $connection, int $reportId, int $adminId, string $action, string $costInput, string $remarksInput): array
{
    if (!in_array($action, ['save_assessment', 'resolve_report'], true)) {
        throw new DamageReportAssessmentException('Invalid assessment action.', 400);
    }
    $fieldErrors = [];
    try {
        $cost = damage_report_assessment_cost($costInput);
    } catch (DamageReportAssessmentException $exception) {
        $cost = null;
        $fieldErrors = $exception->fieldErrors;
    }
    $remarks = trim($remarksInput);
    if (mb_strlen($remarks) > 4000) {
        $fieldErrors['assessment_remarks'] = 'Keep the assessment remarks within 4,000 characters.';
    }
    if ($action === 'resolve_report' && $remarks === '') {
        $fieldErrors['assessment_remarks'] = 'Enter admin assessment remarks before resolving this report.';
    }

    $connection->beginTransaction();
    try {
        $select = $connection->prepare('SELECT id, report_code, farmer_id, agricultural_category, status, final_assessed_cost, assessment_remarks FROM damage_reports WHERE id = ? AND is_fixture = 0 FOR UPDATE');
        $select->execute([$reportId]);
        $before = $select->fetch();
        if (!$before) {
            throw new DamageReportAssessmentException('This report was not found.', 404);
        }
        // Category-specific reports record physical loss (crop %, heads, fish count or kg). They are resolved on
        // the admin-verified cause and figures; a peso amount is optional and never calculated from the figures.
        // Older reports keep their rule: a final assessed cost and remarks are required.
        $detailsStatement = $connection->prepare('SELECT verified_at, verified_cause, verified_crop_damage_percent, verified_amount_lost FROM damage_report_details WHERE report_id = ? FOR UPDATE');
        $detailsStatement->execute([$reportId]);
        $details = $detailsStatement->fetch() ?: null;
        if ($action === 'resolve_report') {
            if ($details === null && $cost === null && !isset($fieldErrors['final_assessed_cost'])) {
                $fieldErrors['final_assessed_cost'] = 'Enter a final assessed cost before resolving this report.';
            }
            if ($details !== null && !damage_report_verification_complete($details)) {
                $fieldErrors['verified_figures'] = 'Save the admin-verified cause and figures before resolving this report.';
            }
        }
        if ($fieldErrors) {
            throw new DamageReportAssessmentException('Correct the highlighted assessment fields.', 422, $fieldErrors);
        }
        if ($before['status'] !== 'Under Review') {
            throw new DamageReportAssessmentException('This report is no longer Under Review. Reload it to see the latest decision.', 409);
        }

        $oldCost = $before['final_assessed_cost'] === null ? null : (string) $before['final_assessed_cost'];
        $oldRemarks = $before['assessment_remarks'] === null ? null : (string) $before['assessment_remarks'];
        $changed = $cost !== $oldCost || $remarks !== trim((string) $oldRemarks);
        if (!$changed && $action === 'save_assessment') {
            $report = damage_report_fetch($connection, damage_report_filters_from_input([]), $reportId, true, false)[0];
            $connection->commit();
            return ['report' => $report, 'changed' => false, 'message' => 'Assessment is already up to date.'];
        }

        $statusAfter = $action === 'resolve_report' ? 'Resolved' : 'Under Review';
        $update = $action === 'resolve_report'
            ? $connection->prepare("UPDATE damage_reports SET final_assessed_cost = ?, assessment_remarks = ?, assessed_by_admin_id = ?, assessed_at = CURRENT_TIMESTAMP, resolved_at = CURRENT_TIMESTAMP, status = 'Resolved', updated_at = CURRENT_TIMESTAMP WHERE id = ?")
            : $connection->prepare('UPDATE damage_reports SET final_assessed_cost = ?, assessment_remarks = ?, assessed_by_admin_id = ?, assessed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $update->execute([$cost, $remarks, $adminId, $reportId]);

        $auditAction = $action === 'resolve_report' ? 'Resolved' : 'Saved';
        $audit = $connection->prepare('INSERT INTO damage_report_assessment_audit (report_id, admin_id, action, old_assessed_cost, new_assessed_cost, old_remarks, new_remarks, previous_status, new_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $previousStatus = (string) $before['status'];
        $audit->execute([$reportId, $adminId, $auditAction, $oldCost, $cost, $oldRemarks, $remarks, $previousStatus, $statusAfter]);

        if ($action === 'resolve_report') {
            $resolvedFigures = '';
            if ($details !== null) {
                $presented = damage_details_present(damage_details_for_reports($connection, [$reportId])[$reportId] ?? null, (string) ($before['agricultural_category'] ?? ''));
                $resolvedFigures = ($presented['verified']['summary'] ?? '') !== '' ? ' Verified loss: ' . $presented['verified']['summary'] . '.' : '';
            }
            $message = 'Your damage report ' . $before['report_code'] . ' is now Resolved.' . $resolvedFigures
                . ($cost !== null ? ' Final assessed cost: PHP ' . number_format((float) $cost, 2) . '.' : '')
                . ' Admin assessment: ' . $remarks;
            $response = $connection->prepare('INSERT INTO admin_responses (report_id, admin_id, previous_status, new_status, response_message, rejection_reason, notification_message) VALUES (?, ?, ?, ?, ?, NULL, ?)');
            $response->execute([$reportId, $adminId, $previousStatus, $statusAfter, $remarks, $message]);
            create_farmer_notification($connection, (int) $before['farmer_id'], 'Damage Report Status Update', 'Damage report resolved', $message, $reportId, null, $adminId);
        }

        $reports = damage_report_fetch($connection, damage_report_filters_from_input([]), $reportId, true, false);
        $connection->commit();
        return [
            'report' => $reports[0],
            'changed' => true,
            'message' => $action === 'resolve_report' ? 'Report resolved. The farmer status and admin message were updated.' : 'Assessment saved without changing the report status.',
        ];
    } catch (Throwable $exception) {
        $connection->rollBack();
        throw $exception;
    }
}

/**
 * Admin status change with a response message (Damage Reports "update response" form), in one transaction:
 * updates the status, records the admin response, and notifies the farmer.
 * Throws RuntimeException('Report not found.') or RuntimeException('Resolved reports are read-only.').
 */
function damage_report_record_admin_response(PDO $connection, int $reportId, int $adminId, string $newStatus, string $responseMessage, string $rejectionReason): void
{
    $connection->beginTransaction();
    try {
        $select = $connection->prepare('SELECT status, report_code, farmer_id FROM damage_reports WHERE id = ? FOR UPDATE');
        $select->execute([$reportId]);
        $report = $select->fetch();
        if (!$report) throw new RuntimeException('Report not found.');
        if ($report['status'] === 'Resolved') throw new RuntimeException('Resolved reports are read-only.');
        $update = $connection->prepare('UPDATE damage_reports SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $update->execute([$newStatus, $reportId]);
        $notificationMessage = $responseMessage !== '' ? $responseMessage : ($newStatus === 'Rejected' ? $rejectionReason : 'Your damage report status is now ' . $newStatus . '.');
        $insert = $connection->prepare('INSERT INTO admin_responses (report_id, admin_id, previous_status, new_status, response_message, rejection_reason, notification_message) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $insert->execute([$reportId, $adminId, $report['status'], $newStatus, $responseMessage, $rejectionReason, $notificationMessage]);
        create_farmer_notification($connection, (int) $report['farmer_id'], 'Damage Report Status Update', 'Damage report status updated', 'Your damage report ' . $report['report_code'] . ' is now ' . $newStatus . '. ' . $notificationMessage, $reportId, null, $adminId);
        if ($responseMessage !== '') {
            create_farmer_notification($connection, (int) $report['farmer_id'], 'Admin Response', 'Admin response to your damage report', $responseMessage, $reportId, null, $adminId);
        }
        $connection->commit();
    } catch (Throwable $exception) {
        if ($connection->inTransaction()) $connection->rollBack();
        throw $exception;
    }
}
