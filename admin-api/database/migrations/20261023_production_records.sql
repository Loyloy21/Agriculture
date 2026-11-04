USE agrireach;

-- Additive: farmer production (harvest) records and the commodity list they reference, for the MySQL
-- rollback copy. Mirrors database/pgsql/20261023_0006_production_records.sql. No existing table changes.
CREATE TABLE IF NOT EXISTS commodities (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    agricultural_category VARCHAR(100) NOT NULL,
    status VARCHAR(8) NOT NULL DEFAULT 'Active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_commodities_category_name (agricultural_category, name),
    CONSTRAINT chk_commodities_category CHECK (agricultural_category IN ('Crops', 'Livestock', 'Poultry', 'Fishpond')),
    CONSTRAINT chk_commodities_status CHECK (status IN ('Active', 'Inactive'))
);

INSERT INTO commodities (name, agricultural_category) VALUES
    ('Rice', 'Crops'), ('Corn', 'Crops'), ('Banana', 'Crops'), ('Coconut', 'Crops'), ('Cacao', 'Crops'),
    ('Coffee', 'Crops'), ('Cassava', 'Crops'), ('Sweet Potato', 'Crops'), ('Taro', 'Crops'), ('Peanut', 'Crops'),
    ('Mango', 'Crops'), ('Calamansi', 'Crops'), ('Pineapple', 'Crops'), ('Eggplant', 'Crops'), ('Tomato', 'Crops'),
    ('Bitter Gourd (Ampalaya)', 'Crops'), ('String Beans', 'Crops'), ('Squash', 'Crops'), ('Okra', 'Crops'), ('Pechay', 'Crops'),
    ('Pig', 'Livestock'), ('Cattle', 'Livestock'), ('Carabao', 'Livestock'), ('Goat', 'Livestock'),
    ('Chicken', 'Poultry'), ('Duck', 'Poultry'),
    ('Bangus (Milkfish)', 'Fishpond'), ('Tilapia', 'Fishpond'), ('Shrimp', 'Fishpond'), ('Mud Crab', 'Fishpond')
ON DUPLICATE KEY UPDATE name = VALUES(name);

CREATE TABLE IF NOT EXISTS production_records (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    farmer_id INT UNSIGNED NOT NULL,
    farm_profile_id INT UNSIGNED NULL,
    commodity_id INT UNSIGNED NOT NULL,
    harvest_date DATE NOT NULL,
    quantity_kg DECIMAL(12,2) NOT NULL,
    remarks VARCHAR(1000) NULL,
    status VARCHAR(9) NOT NULL DEFAULT 'Submitted',
    client_submission_id VARCHAR(64) NULL,
    is_fixture TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_production_records_farmer_submission (farmer_id, client_submission_id),
    INDEX idx_production_records_farmer_harvest (farmer_id, harvest_date),
    INDEX idx_production_records_harvest (harvest_date),
    INDEX idx_production_records_commodity_harvest (commodity_id, harvest_date),
    INDEX idx_production_records_farm_harvest (farm_profile_id, harvest_date),
    CONSTRAINT fk_production_records_farmer FOREIGN KEY (farmer_id) REFERENCES farmers(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_production_records_farm_profile FOREIGN KEY (farm_profile_id) REFERENCES farm_profiles(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_production_records_commodity FOREIGN KEY (commodity_id) REFERENCES commodities(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT chk_production_records_quantity CHECK (quantity_kg > 0),
    CONSTRAINT chk_production_records_status CHECK (status IN ('Submitted', 'Verified', 'Rejected')),
    CONSTRAINT chk_production_records_is_fixture CHECK (is_fixture IN (0, 1))
);
