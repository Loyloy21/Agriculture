-- AgriReach: short "Key advice" for admin weather advisories.
-- BACKWARD COMPATIBLE:
--   * New NULLable column public.weather_advisories.key_advice (at most 200 characters): the one- or
--     two-sentence recommendation farmers see on advisory cards and notifications.
--   * advisory_message keeps the complete original text and is never rewritten; existing advisories keep
--     key_advice NULL until an administrator writes one, and the app falls back to the full message.
-- Nothing is dropped and no existing row is rewritten.
--
-- Apply with:   php scripts/pgsql-migrate.php --connection=supabase
-- Roll back:    database/pgsql/rollback/20261024_0009_advisory_key_advice.down.sql (see its notes)

BEGIN;

ALTER TABLE public.weather_advisories ADD COLUMN key_advice varchar(200);
ALTER TABLE public.weather_advisories ADD CONSTRAINT chk_weather_advisories_key_advice
    CHECK (key_advice IS NULL OR btrim(key_advice) <> '');

INSERT INTO public.agrireach_schema_migrations (version) VALUES ('20261024_0009_advisory_key_advice');

COMMIT;
