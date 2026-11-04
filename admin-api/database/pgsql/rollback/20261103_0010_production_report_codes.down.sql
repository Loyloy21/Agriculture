-- ROLLBACK for 20261103_0010_production_report_codes. NOT applied by scripts/pgsql-migrate.php (it only
-- reads database/pgsql/*.sql, not this rollback/ folder). Run it by hand only after restoring the previous PHP code:
--   psql "<connection>" -v ON_ERROR_STOP=1 -f database/pgsql/rollback/20261103_0010_production_report_codes.down.sql
--
-- It removes the persisted production report IDs. Records keep their data; the previous code shows them as
-- "PR-" + id again. Sequence numbers already given to production reports stay used in farmer_report_sequences
-- (the counter never goes back), so later reports never reuse a number a farmer has seen.

BEGIN;

ALTER TABLE public.production_records DROP CONSTRAINT IF EXISTS chk_production_records_report_sequence;
ALTER TABLE public.production_records DROP CONSTRAINT IF EXISTS uq_production_records_farmer_sequence;
ALTER TABLE public.production_records DROP CONSTRAINT IF EXISTS uq_production_records_report_code;
ALTER TABLE public.production_records DROP COLUMN IF EXISTS report_sequence;
ALTER TABLE public.production_records DROP COLUMN IF EXISTS report_code;
DELETE FROM public.agrireach_schema_migrations WHERE version = '20261103_0010_production_report_codes';

COMMIT;
