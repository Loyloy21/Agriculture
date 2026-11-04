USE agrireach;

CREATE TABLE IF NOT EXISTS cabadbaran_barangays (
    psgc_code CHAR(10) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
    barangay_name VARCHAR(100) NOT NULL UNIQUE,
    city_psgc_code CHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '1600203000',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO cabadbaran_barangays (psgc_code, barangay_name) VALUES
('1600203002', 'Antonio Luna'), ('1600203005', 'Bay-ang'), ('1600203006', 'Bayabas'),
('1600203007', 'Caasinan'), ('1600203009', 'Cabinet'), ('1600203010', 'Calamba'),
('1600203011', 'Calibunan'), ('1600203012', 'Comagascas'), ('1600203013', 'Concepcion'),
('1600203014', 'Del Pilar'), ('1600203016', 'Katugasan'), ('1600203017', 'Kauswagan'),
('1600203018', 'La Union'), ('1600203019', 'Mabini'), ('1600203021', 'Poblacion 1'),
('1600203022', 'Poblacion 10'), ('1600203023', 'Poblacion 11'), ('1600203024', 'Poblacion 12'),
('1600203025', 'Poblacion 2'), ('1600203026', 'Poblacion 3'), ('1600203027', 'Poblacion 4'),
('1600203028', 'Poblacion 5'), ('1600203029', 'Poblacion 6'), ('1600203030', 'Poblacion 7'),
('1600203031', 'Poblacion 8'), ('1600203032', 'Poblacion 9'), ('1600203033', 'Puting Bato'),
('1600203037', 'Sanghan'), ('1600203038', 'Soriano'), ('1600203040', 'Tolosa'),
('1600203041', 'Mahaba')
ON DUPLICATE KEY UPDATE barangay_name = VALUES(barangay_name), city_psgc_code = VALUES(city_psgc_code);

ALTER TABLE farm_profiles
    ADD COLUMN IF NOT EXISTS city_psgc_code CHAR(10) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER farm_address,
    ADD COLUMN IF NOT EXISTS city_name VARCHAR(100) NULL AFTER city_psgc_code,
    ADD COLUMN IF NOT EXISTS province_name VARCHAR(100) NULL AFTER city_name,
    ADD COLUMN IF NOT EXISTS barangay_psgc_code CHAR(10) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER province_name,
    ADD COLUMN IF NOT EXISTS barangay_name VARCHAR(100) NULL AFTER barangay_psgc_code,
    ADD COLUMN IF NOT EXISTS location_review_status ENUM('Needs Review', 'Verified', 'Outside Coverage') NOT NULL DEFAULT 'Needs Review' AFTER barangay_name;

SET @farm_location_index_sql = IF(
    (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'farm_profiles' AND index_name = 'idx_farm_profiles_advisory_location') = 0,
    'ALTER TABLE farm_profiles ADD INDEX idx_farm_profiles_advisory_location (location_review_status, city_psgc_code, barangay_psgc_code, agricultural_category)',
    'SELECT 1'
);
PREPARE farm_location_index_statement FROM @farm_location_index_sql;
EXECUTE farm_location_index_statement;
DEALLOCATE PREPARE farm_location_index_statement;

-- Normalize only an exact canonical barangay prefix in an address that explicitly names Cabadbaran.
-- Every other legacy free-text address remains Needs Review for an administrator to classify.
UPDATE farm_profiles fp
INNER JOIN cabadbaran_barangays cb
    ON LOWER(TRIM(SUBSTRING_INDEX(fp.farm_address, ',', 1))) = LOWER(cb.barangay_name)
SET fp.city_psgc_code = '1600203000',
    fp.city_name = 'Cabadbaran City',
    fp.province_name = 'Agusan del Norte',
    fp.barangay_psgc_code = cb.psgc_code,
    fp.barangay_name = cb.barangay_name,
    fp.location_review_status = 'Verified'
WHERE LOWER(fp.farm_address) LIKE '%cabadbaran%'
  AND fp.location_review_status = 'Needs Review';

ALTER TABLE weather_advisories
    ADD COLUMN IF NOT EXISTS coverage_scope ENUM('City', 'Barangays') NOT NULL DEFAULT 'City' AFTER location_coverage,
    ADD COLUMN IF NOT EXISTS coverage_city_psgc_code CHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '1600203000' AFTER coverage_scope,
    ADD COLUMN IF NOT EXISTS coverage_city_name VARCHAR(100) NOT NULL DEFAULT 'Cabadbaran City' AFTER coverage_city_psgc_code,
    ADD COLUMN IF NOT EXISTS coverage_province_name VARCHAR(100) NOT NULL DEFAULT 'Agusan del Norte' AFTER coverage_city_name,
    ADD COLUMN IF NOT EXISTS published_at DATETIME NULL AFTER status;

UPDATE weather_advisories
SET location_coverage = 'Entire Cabadbaran City, Agusan del Norte',
    coverage_scope = 'City',
    coverage_city_psgc_code = '1600203000',
    coverage_city_name = 'Cabadbaran City',
    coverage_province_name = 'Agusan del Norte'
WHERE coverage_scope = 'City';

CREATE TABLE IF NOT EXISTS weather_advisory_barangays (
    advisory_id INT UNSIGNED NOT NULL,
    barangay_code CHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    barangay_name VARCHAR(100) NOT NULL,
    PRIMARY KEY (advisory_id, barangay_code),
    CONSTRAINT fk_weather_advisory_barangays_advisory FOREIGN KEY (advisory_id) REFERENCES weather_advisories(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_weather_advisory_barangays_barangay FOREIGN KEY (barangay_code) REFERENCES cabadbaran_barangays(psgc_code) ON DELETE RESTRICT ON UPDATE CASCADE
);

CREATE TABLE IF NOT EXISTS weather_advisory_recipients (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    advisory_id INT UNSIGNED NOT NULL,
    farmer_id INT UNSIGNED NOT NULL,
    notification_id INT UNSIGNED NULL,
    notified_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_weather_advisory_farmer (advisory_id, farmer_id),
    INDEX idx_weather_advisory_recipients_farmer (farmer_id, advisory_id),
    CONSTRAINT fk_weather_advisory_recipients_advisory FOREIGN KEY (advisory_id) REFERENCES weather_advisories(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_weather_advisory_recipients_farmer FOREIGN KEY (farmer_id) REFERENCES farmers(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_weather_advisory_recipients_notification FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE SET NULL ON UPDATE CASCADE
);

-- Treat an existing advisory notification as the already-sent delivery for deduplication.
INSERT IGNORE INTO weather_advisory_recipients (advisory_id, farmer_id, notification_id, notified_at)
SELECT related_advisory_id, farmer_id, id, created_at
FROM notifications
WHERE related_advisory_id IS NOT NULL;
