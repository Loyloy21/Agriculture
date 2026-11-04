<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/backend/config/database.php';
require_once dirname(__DIR__, 2) . '/backend/lib/damage_report_assessment.php';
require_once dirname(__DIR__, 2) . '/frontend/admin/includes/damage_report_pdf.php';

function assessment_test_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$connection = db();
$admin = $connection->query("SELECT id FROM admins WHERE role = 'admin' AND status = 'active' AND account_status = 'Active' ORDER BY id LIMIT 1")->fetch();
if (!$admin) throw new RuntimeException('A local active admin is required for this controlled test.');
$adminId = (int) $admin['id'];
$suffix = bin2hex(random_bytes(6));
$email = 'codex-assessment-' . $suffix . '@example.test';
$contact = '+639' . str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
$farmerId = null;
$reportId = null;

try {
    $farmer = $connection->prepare("INSERT INTO farmers (full_name, contact_number, email, address, account_status, verification_status) VALUES ('Controlled Assessment Test', ?, ?, 'Controlled test address', 'Active', 'Approved')");
    $farmer->execute([$contact, $email]);
    $farmerId = (int) $connection->lastInsertId();

    $reportCode = 'AR-CODEX-ASSESS-' . strtoupper($suffix);
    $report = $connection->prepare("INSERT INTO damage_reports (report_code, farmer_id, agricultural_category, damage_type, damage_description, estimated_damage_cost, status, is_fixture) VALUES (?, ?, 'Crops', 'Controlled test event', 'Controlled test description', 12500.50, 'Under Review', 0)");
    $report->execute([$reportCode, $farmerId]);
    $reportId = (int) $connection->lastInsertId();

    assessment_test_assert(damage_report_assessment_cost('0') === '0.00', 'Zero cost normalization failed.');
    foreach (['-1', '1e4', '12.345', '99999999999999'] as $invalid) {
        try {
            damage_report_assessment_cost($invalid);
            throw new RuntimeException('Invalid assessed cost was accepted.');
        } catch (DamageReportAssessmentException $exception) {
            assessment_test_assert(isset($exception->fieldErrors['final_assessed_cost']), 'Invalid cost did not identify its field.');
        }
    }

    $saved = damage_report_apply_assessment($connection, $reportId, $adminId, 'save_assessment', '12500.50', 'Controlled draft findings.');
    assessment_test_assert($saved['report']['status'] === 'Under Review', 'Saving assessment changed status.');
    assessment_test_assert($saved['report']['estimated_damage_cost'] === '12500.50', 'Farmer estimate changed.');
    assessment_test_assert($saved['report']['final_assessed_cost'] === '12500.50', 'Assessed cost was not saved.');
    assessment_test_assert(!damage_report_pdf_is_final($saved['report']), 'Under Review PDF was marked final.');
    assessment_test_assert(str_contains(damage_report_pdf_assessment_html($saved['report'], false), '(draft, not final)'), 'Draft cost was not identified as provisional.');
    assessment_test_assert(str_starts_with(damage_report_single_pdf($saved['report']), '%PDF-'), 'Draft PDF did not render.');
    $unchanged = damage_report_apply_assessment($connection, $reportId, $adminId, 'save_assessment', '12500.50', 'Controlled draft findings.');
    assessment_test_assert($unchanged['changed'] === false, 'Duplicate save was not detected.');

    try {
        damage_report_apply_assessment($connection, $reportId, $adminId, 'resolve_report', '', '');
        throw new RuntimeException('Resolution without cost and remarks was accepted.');
    } catch (DamageReportAssessmentException $exception) {
        assessment_test_assert(isset($exception->fieldErrors['final_assessed_cost'], $exception->fieldErrors['assessment_remarks']), 'Resolution did not identify both required fields.');
    }
    try {
        damage_report_apply_assessment($connection, $reportId, $adminId, 'resolve_report', '12.345', '');
        throw new RuntimeException('Resolution with invalid cost and missing remarks was accepted.');
    } catch (DamageReportAssessmentException $exception) {
        assessment_test_assert(isset($exception->fieldErrors['final_assessed_cost'], $exception->fieldErrors['assessment_remarks']), 'Resolution did not report both invalid fields.');
    }

    $resolved = damage_report_apply_assessment($connection, $reportId, $adminId, 'resolve_report', '10800.00', 'Controlled final findings.');
    assessment_test_assert($resolved['report']['status'] === 'Resolved', 'Report was not resolved.');
    assessment_test_assert($resolved['report']['estimated_damage_cost'] === '12500.50', 'Farmer estimate was overwritten.');
    assessment_test_assert($resolved['report']['final_assessed_cost'] === '10800.00', 'Final cost was not saved.');
    assessment_test_assert($resolved['report']['assessed_by_admin_id'] == $adminId && $resolved['report']['resolved_at'] !== null, 'Assessor or resolution time was not recorded.');
    assessment_test_assert(damage_report_pdf_is_final($resolved['report']), 'Resolved PDF was not classified final.');
    assessment_test_assert(str_contains(damage_report_pdf_assessment_html($resolved['report'], true), 'Final assessed cost (admin)') && str_contains(damage_report_pdf_assessment_html($resolved['report'], true), 'PHP 10,800.00'), 'Final cost was not clearly identified.');
    assessment_test_assert(str_starts_with(damage_report_single_pdf($resolved['report']), '%PDF-'), 'Final PDF did not render.');
    try {
        damage_report_apply_assessment($connection, $reportId, $adminId, 'resolve_report', '10000.00', 'Attempted duplicate.');
        throw new RuntimeException('Repeat resolution was accepted.');
    } catch (DamageReportAssessmentException $exception) {
        assessment_test_assert($exception->httpStatus === 409, 'Repeat resolution did not return a conflict.');
    }

    $count = $connection->prepare('SELECT (SELECT COUNT(*) FROM damage_report_assessment_audit WHERE report_id = ?) AS audits, (SELECT COUNT(*) FROM admin_responses WHERE report_id = ?) AS responses, (SELECT COUNT(*) FROM notifications WHERE related_report_id = ?) AS notifications');
    $count->execute([$reportId, $reportId, $reportId]);
    $totals = $count->fetch();
    assessment_test_assert((int) $totals['audits'] === 2, 'Expected one save and one resolution audit entry.');
    assessment_test_assert((int) $totals['responses'] === 1, 'Expected one final admin response.');
    assessment_test_assert((int) $totals['notifications'] === 1, 'Expected one farmer notification.');
    assessment_test_assert($resolved['report']['admin_response_message'] === 'Controlled final findings.', 'Mobile admin response was not synchronized.');
    echo "Controlled assessment checks passed: draft, validation, resolution, audit, single notification, repeat prevention, and PDF classification.\n";
} finally {
    if ($farmerId !== null) {
        $cleanup = $connection->prepare('DELETE FROM farmers WHERE id = ? AND email = ?');
        $cleanup->execute([$farmerId, $email]);
        echo "Controlled test farmer and its dependent records removed.\n";
    }
}
