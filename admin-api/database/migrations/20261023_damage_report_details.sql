USE agrireach;

-- Additive: category-specific damage details for the MySQL rollback copy. Mirrors
-- database/pgsql/20261023_0004_damage_report_details.sql. Existing reports keep every value; the farmer
-- peso estimate becomes optional (NULLable, same default) for new reports.
CREATE TABLE IF NOT EXISTS damage_report_details (
    report_id INT UNSIGNED NOT NULL PRIMARY KEY,
    details_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    measure_type ENUM('crop_percent', 'head_count', 'bird_count', 'fish_count', 'fish_weight_kg') NOT NULL,
    subject_type VARCHAR(100) NOT NULL,
    suspected_cause VARCHAR(60) NOT NULL,
    suspected_cause_other VARCHAR(255) NULL,
    crop_damage_percent DECIMAL(5,2) NULL,
    planted_area_ha DECIMAL(12,4) NULL,
    affected_area_ha DECIMAL(12,4) NULL,
    total_before DECIMAL(14,2) NULL,
    amount_lost DECIMAL(14,2) NULL,
    verified_cause VARCHAR(60) NULL,
    verified_cause_other VARCHAR(255) NULL,
    verified_crop_damage_percent DECIMAL(5,2) NULL,
    verified_planted_area_ha DECIMAL(12,4) NULL,
    verified_affected_area_ha DECIMAL(12,4) NULL,
    verified_total_before DECIMAL(14,2) NULL,
    verified_amount_lost DECIMAL(14,2) NULL,
    review_notes TEXT NULL,
    verified_by_admin_id INT UNSIGNED NULL,
    verified_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX fk_damage_report_details_verifier (verified_by_admin_id),
    CONSTRAINT fk_damage_report_details_report FOREIGN KEY (report_id) REFERENCES damage_reports(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_damage_report_details_verifier FOREIGN KEY (verified_by_admin_id) REFERENCES admins(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT chk_damage_report_details_percent CHECK (crop_damage_percent BETWEEN 0 AND 100 AND verified_crop_damage_percent BETWEEN 0 AND 100),
    CONSTRAINT chk_damage_report_details_nonnegative CHECK (planted_area_ha >= 0 AND affected_area_ha >= 0 AND total_before >= 0 AND amount_lost >= 0
        AND verified_planted_area_ha >= 0 AND verified_affected_area_ha >= 0 AND verified_total_before >= 0 AND verified_amount_lost >= 0),
    CONSTRAINT chk_damage_report_details_lost_within_total CHECK (amount_lost <= total_before AND verified_amount_lost <= verified_total_before)
);

ALTER TABLE damage_reports MODIFY estimated_damage_cost DECIMAL(15,2) NULL DEFAULT 0.00;
