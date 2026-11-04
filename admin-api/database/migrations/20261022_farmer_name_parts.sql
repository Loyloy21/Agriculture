USE agrireach;

-- Additive: optional Middle Name and Extension Name for farmers on the MySQL rollback copy, so the same
-- PHP code keeps working if 'default' is switched back to 'mysql'. Mirrors
-- database/pgsql/20261022_0003_farmer_name_parts.sql. Existing rows get NULL in both columns.
ALTER TABLE farmers
    ADD COLUMN IF NOT EXISTS middle_name VARCHAR(75) NULL AFTER first_name,
    ADD COLUMN IF NOT EXISTS extension_name VARCHAR(20) NULL AFTER last_name;
