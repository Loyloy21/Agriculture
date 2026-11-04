-- ROLLBACK for 20261023_0004_damage_report_details. NOT applied by scripts/pgsql-migrate.php (it only
-- reads database/pgsql/*.sql, not this rollback/ folder). Run it by hand only after restoring the previous
-- PHP code, and only after exporting any rows you need from damage_report_details:
--   psql "<connection>" -v ON_ERROR_STOP=1 -f database/pgsql/rollback/20261023_0004_damage_report_details.down.sql
--
-- It removes the category-specific details of reports submitted after the migration (those reports keep
-- their damage_reports row, photos and location). damage_reports.estimated_damage_cost is deliberately
-- left NULLable: restoring NOT NULL would require inventing a peso value for newer reports.

BEGIN;

DROP TABLE IF EXISTS public.damage_report_details;
DELETE FROM public.agrireach_schema_migrations WHERE version = '20261023_0004_damage_report_details';

COMMIT;
