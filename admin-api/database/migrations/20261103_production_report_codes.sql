USE agrireach;

-- Additive: persisted production report IDs sharing the farmer's report sequence (farmer_report_sequences) with
-- damage reports, for the MySQL rollback copy. Mirrors database/pgsql/20261103_0010_production_report_codes.sql.
-- Existing records get the ID administrators already saw ("PR-" + zero-padded id); nothing is renumbered.
ALTER TABLE production_records
    ADD COLUMN report_code VARCHAR(40) NULL AFTER id,
    ADD COLUMN report_sequence INT UNSIGNED NULL AFTER report_code;
UPDATE production_records
   -- updated_at is assigned its own value so ON UPDATE CURRENT_TIMESTAMP does not make the backfill look like an edit.
   SET report_code = CONCAT('PR-', CASE WHEN CHAR_LENGTH(id) >= 6 THEN CAST(id AS CHAR) ELSE LPAD(id, 6, '0') END), updated_at = updated_at
 WHERE report_code IS NULL;
ALTER TABLE production_records
    ADD CONSTRAINT uq_production_records_report_code UNIQUE (report_code),
    ADD CONSTRAINT uq_production_records_farmer_sequence UNIQUE (farmer_id, report_sequence),
    ADD CONSTRAINT chk_production_records_report_sequence CHECK (report_sequence IS NULL OR report_sequence >= 1);
