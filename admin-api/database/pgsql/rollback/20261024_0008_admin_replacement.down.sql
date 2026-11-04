-- ROLLBACK for 20261024_0008_admin_replacement. NOT applied by scripts/pgsql-migrate.php (it only reads
-- database/pgsql/*.sql, not this rollback/ folder). Run it by hand only after restoring the previous PHP code:
--   psql "<connection>" -v ON_ERROR_STOP=1 -f database/pgsql/rollback/20261024_0008_admin_replacement.down.sql
--
-- It removes the replacement invitations and the admin account audit trail, and the single-active-admin index.
-- Admin rows (including any former admin made inactive by a transfer) are kept as they are.

BEGIN;

DROP TABLE IF EXISTS public.admin_account_events;
DROP TABLE IF EXISTS public.admin_replacement_invitations;
DROP INDEX IF EXISTS public.uq_admins_single_active;
DELETE FROM public.agrireach_schema_migrations WHERE version = '20261024_0008_admin_replacement';

COMMIT;
