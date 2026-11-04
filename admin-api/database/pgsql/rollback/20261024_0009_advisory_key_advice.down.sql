-- ROLLBACK for 20261024_0009_advisory_key_advice. NOT applied by scripts/pgsql-migrate.php (it only
-- reads database/pgsql/*.sql, not this rollback/ folder). Run it by hand only after restoring the previous
-- PHP code:
--   psql "<connection>" -v ON_ERROR_STOP=1 -f database/pgsql/rollback/20261024_0009_advisory_key_advice.down.sql
--
-- It removes the administrators' Key advice text. The full advisory messages are not affected.

BEGIN;

ALTER TABLE public.weather_advisories DROP CONSTRAINT IF EXISTS chk_weather_advisories_key_advice;
ALTER TABLE public.weather_advisories DROP COLUMN IF EXISTS key_advice;
DELETE FROM public.agrireach_schema_migrations WHERE version = '20261024_0009_advisory_key_advice';

COMMIT;
