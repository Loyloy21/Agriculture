-- AgriReach: one farmer-facing report ID sequence shared by Damage and Production reports.
-- BACKWARD COMPATIBLE:
--   * production_records gets report_code (the persisted, visible report ID) and report_sequence (its number in
--     the farmer's shared sequence, from public.farmer_report_sequences, the counter damage reports already use).
--   * Every existing production record is given exactly the ID administrators already saw for it, "PR-" plus the
--     zero-padded record id (for example PR-000002), with no sequence. Nothing is renumbered or relabelled.
--   * Damage report codes are not touched.
-- Nothing is dropped and no existing value is changed.
--
-- Apply with:   php scripts/pgsql-migrate.php --connection=supabase
-- Roll back:    database/pgsql/rollback/20261103_0010_production_report_codes.down.sql

BEGIN;

ALTER TABLE public.production_records
    ADD COLUMN report_code varchar(40),
    ADD COLUMN report_sequence integer;
-- The backfill must not look like an edit: the updated_at trigger is paused for it (inside this transaction).
ALTER TABLE public.production_records DISABLE TRIGGER trg_production_records_updated_at;
UPDATE public.production_records
   SET report_code = 'PR-' || CASE WHEN length(id::text) >= 6 THEN id::text ELSE lpad(id::text, 6, '0') END
 WHERE report_code IS NULL;
ALTER TABLE public.production_records ENABLE TRIGGER trg_production_records_updated_at;
ALTER TABLE public.production_records
    ADD CONSTRAINT uq_production_records_report_code UNIQUE (report_code),
    ADD CONSTRAINT uq_production_records_farmer_sequence UNIQUE (farmer_id, report_sequence),
    ADD CONSTRAINT chk_production_records_report_sequence CHECK (report_sequence >= 1);

INSERT INTO public.agrireach_schema_migrations (version) VALUES ('20261103_0010_production_report_codes');

COMMIT;
