-- AgriReach: optional Middle Name and Extension Name (suffix such as Jr., Sr., II, III) for farmers.
-- ADDITIVE ONLY: two NULLable columns on public.farmers. No existing row, column, constraint, index,
-- grant or row-level-security setting is changed; existing farmers simply have NULL in both.
--
-- Apply with:
--   php scripts/pgsql-migrate.php --connection=supabase
--
-- full_name keeps its existing meaning (the displayed name). New registrations compose it as
-- "First Middle Last Extension" with empty parts omitted.

BEGIN;

ALTER TABLE public.farmers ADD COLUMN IF NOT EXISTS middle_name varchar(75);
ALTER TABLE public.farmers ADD COLUMN IF NOT EXISTS extension_name varchar(20);

INSERT INTO public.agrireach_schema_migrations (version) VALUES ('20261022_0003_farmer_name_parts');

COMMIT;
