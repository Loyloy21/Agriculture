-- ROLLBACK for 20261023_0005_report_codes_and_review_audit. NOT applied by scripts/pgsql-migrate.php (it only
-- reads database/pgsql/*.sql, not this rollback/ folder). Run it by hand only after restoring the previous
-- PHP code, and only after exporting any rows you need:
--   psql "<connection>" -v ON_ERROR_STOP=1 -f database/pgsql/rollback/20261023_0005_report_codes_and_review_audit.down.sql
--
-- Report codes already issued stay in damage_reports.report_code (they are never rewritten); only the
-- sequence numbers, the counters and the verification audit rows are removed. Re-applying 0005 rebuilds
-- the numbers and counters from those codes, so no code is issued twice.

BEGIN;

DROP TABLE IF EXISTS public.damage_report_verification_audit;
ALTER TABLE public.damage_reports DROP CONSTRAINT IF EXISTS uq_damage_reports_farmer_sequence;
ALTER TABLE public.damage_reports DROP CONSTRAINT IF EXISTS chk_damage_reports_report_sequence;
ALTER TABLE public.damage_reports DROP COLUMN IF EXISTS report_sequence;
DROP TABLE IF EXISTS public.farmer_report_sequences;
DELETE FROM public.agrireach_schema_migrations WHERE version = '20261023_0005_report_codes_and_review_audit';

COMMIT;
