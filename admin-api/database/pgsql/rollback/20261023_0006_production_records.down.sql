-- ROLLBACK for 20261023_0006_production_records. NOT applied by scripts/pgsql-migrate.php (it only
-- reads database/pgsql/*.sql, not this rollback/ folder). Run it by hand only after restoring the previous
-- PHP and mobile code, and only after exporting any rows you need from production_records:
--   psql "<connection>" -v ON_ERROR_STOP=1 -f database/pgsql/rollback/20261023_0006_production_records.down.sql
--
-- It permanently removes every submitted production record and the commodity list. No other table is touched.

BEGIN;

DROP TABLE IF EXISTS public.production_records;
DROP TABLE IF EXISTS public.commodities;
DELETE FROM public.agrireach_schema_migrations WHERE version = '20261023_0006_production_records';

COMMIT;
