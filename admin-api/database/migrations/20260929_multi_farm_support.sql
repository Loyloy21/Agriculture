USE agrireach;

ALTER TABLE farm_profiles
    ADD COLUMN IF NOT EXISTS status ENUM('Active', 'Archived') NOT NULL DEFAULT 'Active' AFTER longitude,
    ADD COLUMN IF NOT EXISTS is_primary TINYINT(1) NOT NULL DEFAULT 0 AFTER status,
    ADD COLUMN IF NOT EXISTS created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER is_primary,
    ADD COLUMN IF NOT EXISTS updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at;

UPDATE farm_profiles fp
INNER JOIN (
    SELECT farmer_id, MIN(id) AS primary_farm_id
    FROM farm_profiles
    WHERE status = 'Active'
    GROUP BY farmer_id
) selected ON selected.primary_farm_id = fp.id
LEFT JOIN (
    SELECT DISTINCT farmer_id FROM farm_profiles WHERE status = 'Active' AND is_primary = 1
) existing_primary ON existing_primary.farmer_id = fp.farmer_id
SET fp.is_primary = 1
WHERE existing_primary.farmer_id IS NULL;

SET @add_farm_farmer_index = IF(
    (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'farm_profiles' AND index_name = 'idx_farm_profiles_farmer_status') = 0,
    'ALTER TABLE farm_profiles ADD INDEX idx_farm_profiles_farmer_status (farmer_id, status, is_primary)',
    'SELECT 1'
);
PREPARE add_farm_farmer_index_statement FROM @add_farm_farmer_index;
EXECUTE add_farm_farmer_index_statement;
DEALLOCATE PREPARE add_farm_farmer_index_statement;

SET @farm_farmer_unique_index = (
    SELECT index_name FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'farm_profiles'
      AND column_name = 'farmer_id' AND non_unique = 0 AND index_name <> 'PRIMARY'
    LIMIT 1
);
SET @drop_farm_farmer_unique = IF(
    @farm_farmer_unique_index IS NULL,
    'SELECT 1',
    CONCAT('ALTER TABLE farm_profiles DROP INDEX `', REPLACE(@farm_farmer_unique_index, '`', '``'), '`')
);
PREPARE drop_farm_farmer_unique_statement FROM @drop_farm_farmer_unique;
EXECUTE drop_farm_farmer_unique_statement;
DEALLOCATE PREPARE drop_farm_farmer_unique_statement;
