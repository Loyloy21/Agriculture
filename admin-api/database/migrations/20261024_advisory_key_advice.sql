USE agrireach;

-- Additive: short "Key advice" for admin weather advisories in the MySQL rollback copy. Mirrors
-- database/pgsql/20261024_0009_advisory_key_advice.sql. advisory_message keeps the full original text;
-- existing advisories keep key_advice NULL.
ALTER TABLE weather_advisories
    ADD COLUMN key_advice VARCHAR(200) NULL AFTER advisory_message,
    ADD CONSTRAINT chk_weather_advisories_key_advice CHECK (key_advice IS NULL OR TRIM(key_advice) <> '');
