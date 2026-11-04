USE agrireach;

ALTER TABLE damage_reports
    ADD COLUMN IF NOT EXISTS submitted_address VARCHAR(255) NULL AFTER submitted_at,
    ADD COLUMN IF NOT EXISTS client_submission_id VARCHAR(64) NULL AFTER submitted_longitude,
    ADD COLUMN IF NOT EXISTS is_fixture TINYINT(1) NOT NULL DEFAULT 0 AFTER client_submission_id;

SET @add_report_submission_index = IF(
    (SELECT COUNT(*) FROM information_schema.statistics
     WHERE table_schema = DATABASE()
       AND table_name = 'damage_reports'
       AND index_name = 'uq_damage_reports_farmer_submission') = 0,
    'ALTER TABLE damage_reports ADD UNIQUE KEY uq_damage_reports_farmer_submission (farmer_id, client_submission_id)',
    'SELECT 1'
);
PREPARE report_submission_index_statement FROM @add_report_submission_index;
EXECUTE report_submission_index_statement;
DEALLOCATE PREPARE report_submission_index_statement;

-- Keep positively identified development fixtures out of live lists without deleting them.
UPDATE damage_reports
SET is_fixture = 1
WHERE report_code LIKE 'AR-SAMPLE-%';
