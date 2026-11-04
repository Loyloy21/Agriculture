USE agrireach;

-- Additive only: farmer estimated_damage_cost and all existing reports remain unchanged.
ALTER TABLE damage_reports
    ADD COLUMN IF NOT EXISTS final_assessed_cost DECIMAL(15,2) NULL AFTER estimated_damage_cost,
    ADD COLUMN IF NOT EXISTS assessment_remarks TEXT NULL AFTER final_assessed_cost,
    ADD COLUMN IF NOT EXISTS assessed_by_admin_id INT UNSIGNED NULL AFTER assessment_remarks,
    ADD COLUMN IF NOT EXISTS assessed_at DATETIME NULL AFTER assessed_by_admin_id,
    ADD COLUMN IF NOT EXISTS resolved_at DATETIME NULL AFTER assessed_at;

CREATE TABLE IF NOT EXISTS damage_report_assessment_audit (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    report_id INT UNSIGNED NOT NULL,
    admin_id INT UNSIGNED NOT NULL,
    action ENUM('Saved', 'Resolved') NOT NULL,
    old_assessed_cost DECIMAL(15,2) NULL,
    new_assessed_cost DECIMAL(15,2) NULL,
    old_remarks TEXT NULL,
    new_remarks TEXT NULL,
    previous_status VARCHAR(50) NOT NULL,
    new_status VARCHAR(50) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_damage_assessment_report (report_id, id),
    CONSTRAINT fk_damage_assessment_report FOREIGN KEY (report_id) REFERENCES damage_reports(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_damage_assessment_admin FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE RESTRICT ON UPDATE CASCADE
);
