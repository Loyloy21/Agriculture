USE agrireach;

-- Additive: farmer-facing report codes (RSBSA reference + per-farmer sequence) and the verified-figure
-- audit trail for the MySQL rollback copy. Mirrors database/pgsql/20261023_0005_report_codes_and_review_audit.sql.
-- Existing reports keep their report_code and get no sequence.
CREATE TABLE IF NOT EXISTS farmer_report_sequences (
    farmer_id INT UNSIGNED NOT NULL PRIMARY KEY,
    last_sequence INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_farmer_report_sequences_farmer FOREIGN KEY (farmer_id) REFERENCES farmers(id) ON DELETE CASCADE ON UPDATE CASCADE
);

ALTER TABLE damage_reports
    ADD COLUMN IF NOT EXISTS report_sequence INT UNSIGNED NULL AFTER report_code,
    ADD UNIQUE KEY IF NOT EXISTS uq_damage_reports_farmer_sequence (farmer_id, report_sequence);

-- Re-apply safety: codes already issued in the new format keep their number; counters continue after them.
UPDATE damage_reports SET report_sequence = CAST(SUBSTRING_INDEX(report_code, '-', -1) AS UNSIGNED)
 WHERE report_sequence IS NULL AND report_code REGEXP '^[0-9]{2}-[0-9]{2}-[0-9]{2}-[0-9]{3}-[0-9]{6}-[0-9]+$';
INSERT INTO farmer_report_sequences (farmer_id, last_sequence)
SELECT farmer_id, MAX(report_sequence) FROM damage_reports WHERE report_sequence IS NOT NULL GROUP BY farmer_id
ON DUPLICATE KEY UPDATE last_sequence = GREATEST(farmer_report_sequences.last_sequence, VALUES(last_sequence));

CREATE TABLE IF NOT EXISTS damage_report_verification_audit (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    report_id INT UNSIGNED NOT NULL,
    admin_id INT UNSIGNED NOT NULL,
    verified_cause VARCHAR(300) NULL,
    verified_summary VARCHAR(255) NULL,
    review_notes TEXT NULL,
    report_status VARCHAR(50) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_damage_verification_audit_report (report_id),
    INDEX fk_damage_verification_audit_admin (admin_id),
    CONSTRAINT fk_damage_verification_audit_report FOREIGN KEY (report_id) REFERENCES damage_reports(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_damage_verification_audit_admin FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE RESTRICT ON UPDATE CASCADE
);
