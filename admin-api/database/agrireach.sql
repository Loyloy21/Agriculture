CREATE DATABASE IF NOT EXISTS agrireach CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE agrireach;

CREATE TABLE IF NOT EXISTS admins (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(120) NOT NULL,
    username VARCHAR(60) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('admin') NOT NULL DEFAULT 'admin',
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- No default password is shipped. '!' can never match, so this account cannot sign in until a password is set:
--   php scripts/admin-recovery.php reset-password --username=admin
INSERT INTO admins (full_name, username, password_hash, role, status)
VALUES ('AgriReach Administrator', 'admin', '!', 'admin', 'active')
ON DUPLICATE KEY UPDATE username = username;

ALTER TABLE admins
    ADD COLUMN IF NOT EXISTS contact_number VARCHAR(40) NULL,
    ADD COLUMN IF NOT EXISTS email VARCHAR(160) NULL,
    ADD COLUMN IF NOT EXISTS address VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS account_status ENUM('Active', 'Inactive', 'Blocked') NOT NULL DEFAULT 'Active';

CREATE TABLE IF NOT EXISTS farmers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(150) NOT NULL,
    first_name VARCHAR(75) NULL,
    last_name VARCHAR(75) NULL,
    gender VARCHAR(30) NULL,
    date_of_birth DATE NULL,
    contact_number VARCHAR(40) NOT NULL,
    email VARCHAR(160) NOT NULL,
    address VARCHAR(255) NOT NULL,
    residential_city_psgc_code CHAR(10) CHARACTER SET ascii COLLATE ascii_bin NULL,
    residential_city_name VARCHAR(100) NULL,
    residential_province_name VARCHAR(100) NULL,
    residential_barangay_psgc_code CHAR(10) CHARACTER SET ascii COLLATE ascii_bin NULL,
    residential_barangay_name VARCHAR(100) NULL,
    residential_purok_sitio VARCHAR(100) NULL,
    residential_landmark VARCHAR(255) NULL,
    residential_location_method ENUM('Legacy', 'Manual', 'Map') NOT NULL DEFAULT 'Legacy',
    residential_boundary_status ENUM('Not Checked', 'Inside Mapped Boundary', 'Outside Mapped Boundary', 'Check Unavailable') NOT NULL DEFAULT 'Not Checked',
    residential_boundary_source VARCHAR(255) NULL,
    residential_boundary_checked_at DATETIME NULL,
    residential_latitude DECIMAL(10,7) NULL,
    residential_longitude DECIMAL(10,7) NULL,
    password_hash VARCHAR(255) NULL,
    account_status ENUM('Active', 'Inactive', 'Blocked') NOT NULL DEFAULT 'Active',
    verification_status ENUM('Pending', 'Approved', 'Rejected') NOT NULL DEFAULT 'Pending',
    rejection_reason VARCHAR(1000) NULL,
    date_registered TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    verified_at TIMESTAMP NULL DEFAULT NULL,
    INDEX idx_farmers_verification_status (verification_status),
    INDEX idx_farmers_email (email)
);

ALTER TABLE farmers
    ADD COLUMN IF NOT EXISTS password_hash VARCHAR(255) NULL,
    ADD COLUMN IF NOT EXISTS account_status ENUM('Active', 'Inactive', 'Blocked') NOT NULL DEFAULT 'Active',
    ADD COLUMN IF NOT EXISTS first_name VARCHAR(75) NULL,
    ADD COLUMN IF NOT EXISTS last_name VARCHAR(75) NULL,
    ADD COLUMN IF NOT EXISTS gender VARCHAR(30) NULL,
    ADD COLUMN IF NOT EXISTS date_of_birth DATE NULL;

ALTER TABLE farmers
    ADD COLUMN IF NOT EXISTS residential_city_psgc_code CHAR(10) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER address,
    ADD COLUMN IF NOT EXISTS residential_city_name VARCHAR(100) NULL AFTER residential_city_psgc_code,
    ADD COLUMN IF NOT EXISTS residential_province_name VARCHAR(100) NULL AFTER residential_city_name,
    ADD COLUMN IF NOT EXISTS residential_barangay_psgc_code CHAR(10) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER residential_province_name,
    ADD COLUMN IF NOT EXISTS residential_barangay_name VARCHAR(100) NULL AFTER residential_barangay_psgc_code,
    ADD COLUMN IF NOT EXISTS residential_purok_sitio VARCHAR(100) NULL AFTER residential_barangay_name,
    ADD COLUMN IF NOT EXISTS residential_landmark VARCHAR(255) NULL AFTER residential_purok_sitio,
    ADD COLUMN IF NOT EXISTS residential_location_method ENUM('Legacy', 'Manual', 'Map') NOT NULL DEFAULT 'Legacy' AFTER residential_landmark,
    ADD COLUMN IF NOT EXISTS residential_boundary_status ENUM('Not Checked', 'Inside Mapped Boundary', 'Outside Mapped Boundary', 'Check Unavailable') NOT NULL DEFAULT 'Not Checked' AFTER residential_location_method,
    ADD COLUMN IF NOT EXISTS residential_boundary_source VARCHAR(255) NULL AFTER residential_boundary_status,
    ADD COLUMN IF NOT EXISTS residential_boundary_checked_at DATETIME NULL AFTER residential_boundary_source,
    ADD COLUMN IF NOT EXISTS residential_latitude DECIMAL(10,7) NULL AFTER residential_boundary_checked_at,
    ADD COLUMN IF NOT EXISTS residential_longitude DECIMAL(10,7) NULL AFTER residential_latitude,
    ADD INDEX IF NOT EXISTS idx_farmers_residential_location (residential_city_psgc_code, residential_barangay_psgc_code);

ALTER TABLE farmers
    ADD UNIQUE INDEX IF NOT EXISTS uq_farmers_email (email),
    ADD UNIQUE INDEX IF NOT EXISTS uq_farmers_contact_number (contact_number);

CREATE TABLE IF NOT EXISTS farmer_sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    farmer_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    revoked_at DATETIME NULL,
    last_used_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_farmer_sessions_farmer (farmer_id),
    INDEX idx_farmer_sessions_expiry (expires_at, revoked_at),
    CONSTRAINT fk_farmer_sessions_farmer FOREIGN KEY (farmer_id) REFERENCES farmers(id) ON DELETE CASCADE ON UPDATE CASCADE
);

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

CREATE TABLE IF NOT EXISTS farm_profiles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    farmer_id INT UNSIGNED NOT NULL,
    farm_name VARCHAR(150) NOT NULL,
    farm_type VARCHAR(100) NOT NULL,
    agricultural_category VARCHAR(100) NOT NULL,
    land_area DECIMAL(12,2) NULL,
    farm_address VARCHAR(255) NOT NULL,
    city_psgc_code CHAR(10) CHARACTER SET ascii COLLATE ascii_bin NULL,
    city_name VARCHAR(100) NULL,
    province_name VARCHAR(100) NULL,
    barangay_psgc_code CHAR(10) CHARACTER SET ascii COLLATE ascii_bin NULL,
    barangay_name VARCHAR(100) NULL,
    purok_sitio VARCHAR(100) NULL,
    landmark VARCHAR(255) NULL,
    location_method ENUM('Legacy', 'Manual', 'Map') NOT NULL DEFAULT 'Legacy',
    location_boundary_status ENUM('Not Checked', 'Inside Mapped Boundary', 'Outside Mapped Boundary', 'Check Unavailable') NOT NULL DEFAULT 'Not Checked',
    location_boundary_source VARCHAR(255) NULL,
    location_boundary_checked_at DATETIME NULL,
    location_review_status ENUM('Needs Review', 'Verified', 'Outside Coverage') NOT NULL DEFAULT 'Needs Review',
    main_product VARCHAR(150) NULL,
    latitude DECIMAL(10,7) NULL,
    longitude DECIMAL(10,7) NULL,
    status ENUM('Active', 'Archived') NOT NULL DEFAULT 'Active',
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_farm_profiles_farmer_status (farmer_id, status, is_primary),
    INDEX idx_farm_profiles_advisory_location (location_review_status, city_psgc_code, barangay_psgc_code, agricultural_category),
    CONSTRAINT fk_farm_profiles_farmer FOREIGN KEY (farmer_id) REFERENCES farmers(id) ON DELETE CASCADE ON UPDATE CASCADE
);

ALTER TABLE farm_profiles
    ADD COLUMN IF NOT EXISTS main_product VARCHAR(150) NULL,
    ADD COLUMN IF NOT EXISTS city_psgc_code CHAR(10) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER farm_address,
    ADD COLUMN IF NOT EXISTS city_name VARCHAR(100) NULL AFTER city_psgc_code,
    ADD COLUMN IF NOT EXISTS province_name VARCHAR(100) NULL AFTER city_name,
    ADD COLUMN IF NOT EXISTS barangay_psgc_code CHAR(10) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER province_name,
    ADD COLUMN IF NOT EXISTS barangay_name VARCHAR(100) NULL AFTER barangay_psgc_code,
    ADD COLUMN IF NOT EXISTS purok_sitio VARCHAR(100) NULL AFTER barangay_name,
    ADD COLUMN IF NOT EXISTS landmark VARCHAR(255) NULL AFTER purok_sitio,
    ADD COLUMN IF NOT EXISTS location_method ENUM('Legacy', 'Manual', 'Map') NOT NULL DEFAULT 'Legacy' AFTER landmark,
    ADD COLUMN IF NOT EXISTS location_boundary_status ENUM('Not Checked', 'Inside Mapped Boundary', 'Outside Mapped Boundary', 'Check Unavailable') NOT NULL DEFAULT 'Not Checked' AFTER location_method,
    ADD COLUMN IF NOT EXISTS location_boundary_source VARCHAR(255) NULL AFTER location_boundary_status,
    ADD COLUMN IF NOT EXISTS location_boundary_checked_at DATETIME NULL AFTER location_boundary_source,
    ADD COLUMN IF NOT EXISTS location_review_status ENUM('Needs Review', 'Verified', 'Outside Coverage') NOT NULL DEFAULT 'Needs Review' AFTER barangay_name,
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

UPDATE farm_profiles fp
INNER JOIN cabadbaran_barangays cb
    ON LOWER(TRIM(SUBSTRING_INDEX(fp.farm_address, ',', 1))) = LOWER(cb.barangay_name)
SET fp.city_psgc_code = '1600203000', fp.city_name = 'Cabadbaran City',
    fp.province_name = 'Agusan del Norte', fp.barangay_psgc_code = cb.psgc_code,
    fp.barangay_name = cb.barangay_name, fp.location_review_status = 'Verified'
WHERE LOWER(fp.farm_address) LIKE '%cabadbaran%' AND fp.location_review_status = 'Needs Review';

CREATE TABLE IF NOT EXISTS damage_reports (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    report_code VARCHAR(40) NOT NULL UNIQUE,
    farmer_id INT UNSIGNED NOT NULL,
    farm_profile_id INT UNSIGNED NULL,
    agricultural_category VARCHAR(100) NOT NULL,
    damage_type VARCHAR(100) NOT NULL,
    damage_description TEXT NOT NULL,
    estimated_damage_cost DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    final_assessed_cost DECIMAL(15,2) NULL,
    assessment_remarks TEXT NULL,
    assessed_by_admin_id INT UNSIGNED NULL,
    assessed_at DATETIME NULL,
    resolved_at DATETIME NULL,
    status ENUM('Received', 'Under Review', 'Approved', 'Rejected', 'Resolved') NOT NULL DEFAULT 'Received',
    submitted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    submitted_address VARCHAR(255) NULL,
    submitted_latitude DECIMAL(10,7) NULL,
    submitted_longitude DECIMAL(10,7) NULL,
    location_source VARCHAR(24) NULL,
    location_photo_order SMALLINT UNSIGNED NULL,
    location_confirmed_at DATETIME NULL,
    client_submission_id VARCHAR(64) NULL,
    is_fixture TINYINT(1) NOT NULL DEFAULT 0,
    distance_from_registered_farm DECIMAL(10,3) NULL,
    gps_validation_status VARCHAR(50) NULL,
    gps_validation_result VARCHAR(255) NULL,
    image_metadata_validation_status VARCHAR(50) NULL,
    timestamp_validation_status VARCHAR(50) NULL,
    validation_warning VARCHAR(1000) NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_damage_reports_status (status),
    INDEX idx_damage_reports_category (agricultural_category),
    INDEX idx_damage_reports_submitted_at (submitted_at),
    UNIQUE KEY uq_damage_reports_farmer_submission (farmer_id, client_submission_id),
    CONSTRAINT fk_damage_reports_farmer FOREIGN KEY (farmer_id) REFERENCES farmers(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_damage_reports_farm_profile FOREIGN KEY (farm_profile_id) REFERENCES farm_profiles(id) ON DELETE SET NULL ON UPDATE CASCADE
);

ALTER TABLE damage_reports
    ADD COLUMN IF NOT EXISTS submitted_address VARCHAR(255) NULL AFTER submitted_at,
    ADD COLUMN IF NOT EXISTS client_submission_id VARCHAR(64) NULL AFTER submitted_longitude,
    ADD COLUMN IF NOT EXISTS is_fixture TINYINT(1) NOT NULL DEFAULT 0 AFTER client_submission_id,
    ADD COLUMN IF NOT EXISTS final_assessed_cost DECIMAL(15,2) NULL AFTER estimated_damage_cost,
    ADD COLUMN IF NOT EXISTS assessment_remarks TEXT NULL AFTER final_assessed_cost,
    ADD COLUMN IF NOT EXISTS assessed_by_admin_id INT UNSIGNED NULL AFTER assessment_remarks,
    ADD COLUMN IF NOT EXISTS assessed_at DATETIME NULL AFTER assessed_by_admin_id,
    ADD COLUMN IF NOT EXISTS resolved_at DATETIME NULL AFTER assessed_at,
    ADD COLUMN IF NOT EXISTS location_source VARCHAR(24) NULL,
    ADD COLUMN IF NOT EXISTS location_photo_order SMALLINT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS location_confirmed_at DATETIME NULL;

SET @add_report_submission_index = IF(
    (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'damage_reports' AND index_name = 'uq_damage_reports_farmer_submission') = 0,
    'ALTER TABLE damage_reports ADD UNIQUE KEY uq_damage_reports_farmer_submission (farmer_id, client_submission_id)',
    'SELECT 1'
);
PREPARE report_submission_index_statement FROM @add_report_submission_index;
EXECUTE report_submission_index_statement;
DEALLOCATE PREPARE report_submission_index_statement;

CREATE TABLE IF NOT EXISTS report_images (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    report_id INT UNSIGNED NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    display_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    captured_at DATETIME NULL,
    gps_latitude DECIMAL(10,7) NULL,
    gps_longitude DECIMAL(10,7) NULL,
    gps_source VARCHAR(24) NULL,
    gps_accuracy_m DECIMAL(10,2) NULL,
    gps_recorded_at DATETIME NULL,
    capture_time_source VARCHAR(24) NULL,
    location_status VARCHAR(32) NULL,
    metadata_validation_status VARCHAR(50) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_report_images_report (report_id, display_order, id),
    CONSTRAINT fk_report_images_report FOREIGN KEY (report_id) REFERENCES damage_reports(id) ON DELETE CASCADE ON UPDATE CASCADE
);

ALTER TABLE report_images
    ADD COLUMN IF NOT EXISTS gps_source VARCHAR(24) NULL,
    ADD COLUMN IF NOT EXISTS gps_accuracy_m DECIMAL(10,2) NULL,
    ADD COLUMN IF NOT EXISTS gps_recorded_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS capture_time_source VARCHAR(24) NULL,
    ADD COLUMN IF NOT EXISTS location_status VARCHAR(32) NULL;

CREATE TABLE IF NOT EXISTS weather_data (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    report_id INT UNSIGNED NOT NULL,
    weather_condition VARCHAR(100) NULL,
    temperature DECIMAL(6,2) NULL,
    humidity DECIMAL(6,2) NULL,
    rainfall DECIMAL(10,2) NULL,
    wind_information VARCHAR(255) NULL,
    recorded_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_weather_data_report (report_id),
    CONSTRAINT fk_weather_data_report FOREIGN KEY (report_id) REFERENCES damage_reports(id) ON DELETE CASCADE ON UPDATE CASCADE
);

-- Per-report weather snapshot provenance (see migrations/20261012_weather_snapshot.sql).
ALTER TABLE weather_data
    ADD COLUMN IF NOT EXISTS snapshot_status ENUM('Available', 'Unavailable') NOT NULL DEFAULT 'Available' AFTER report_id,
    ADD COLUMN IF NOT EXISTS unavailable_reason VARCHAR(255) NULL AFTER snapshot_status,
    ADD COLUMN IF NOT EXISTS unavailable_code VARCHAR(40) NULL AFTER unavailable_reason,
    ADD COLUMN IF NOT EXISTS provider VARCHAR(60) NULL,
    ADD COLUMN IF NOT EXISTS observation_type VARCHAR(80) NULL,
    ADD COLUMN IF NOT EXISTS requested_latitude DECIMAL(10,7) NULL,
    ADD COLUMN IF NOT EXISTS requested_longitude DECIMAL(10,7) NULL,
    ADD COLUMN IF NOT EXISTS provider_latitude DECIMAL(10,6) NULL,
    ADD COLUMN IF NOT EXISTS provider_longitude DECIMAL(10,6) NULL,
    ADD COLUMN IF NOT EXISTS location_basis VARCHAR(24) NULL,
    ADD COLUMN IF NOT EXISTS observed_at_utc DATETIME NULL,
    ADD COLUMN IF NOT EXISTS retrieved_at_utc DATETIME NULL,
    ADD COLUMN IF NOT EXISTS report_submitted_at_utc DATETIME NULL,
    ADD COLUMN IF NOT EXISTS weather_code SMALLINT NULL,
    ADD COLUMN IF NOT EXISTS apparent_temperature DECIMAL(6,2) NULL,
    ADD COLUMN IF NOT EXISTS precipitation_mm DECIMAL(8,2) NULL,
    ADD COLUMN IF NOT EXISTS precipitation_last_6h_mm DECIMAL(8,2) NULL,
    ADD COLUMN IF NOT EXISTS wind_speed_kmh DECIMAL(6,2) NULL,
    ADD COLUMN IF NOT EXISTS wind_gust_kmh DECIMAL(6,2) NULL,
    ADD COLUMN IF NOT EXISTS wind_direction_deg SMALLINT NULL,
    ADD COLUMN IF NOT EXISTS units_json VARCHAR(500) NULL,
    ADD COLUMN IF NOT EXISTS rule_set_version VARCHAR(30) NULL,
    ADD COLUMN IF NOT EXISTS rule_category VARCHAR(100) NULL,
    ADD COLUMN IF NOT EXISTS rule_result_json MEDIUMTEXT NULL;

ALTER TABLE weather_data
    ADD UNIQUE INDEX IF NOT EXISTS uq_weather_data_report (report_id);

CREATE TABLE IF NOT EXISTS admin_responses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    report_id INT UNSIGNED NOT NULL,
    admin_id INT UNSIGNED NOT NULL,
    previous_status VARCHAR(50) NOT NULL,
    new_status VARCHAR(50) NOT NULL,
    response_message TEXT NULL,
    rejection_reason VARCHAR(1000) NULL,
    notification_message TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_admin_responses_report (report_id),
    CONSTRAINT fk_admin_responses_report FOREIGN KEY (report_id) REFERENCES damage_reports(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_admin_responses_admin FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE RESTRICT ON UPDATE CASCADE
);

CREATE TABLE IF NOT EXISTS damage_report_assessment_audit (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    report_id INT UNSIGNED NOT NULL,
    admin_id INT UNSIGNED NOT NULL,
    action ENUM('Saved', 'Resolved') NOT NULL,
    old_assessed_cost DECIMAL(15,2) NULL,
    new_assessed_cost DECIMAL(15,2) NULL,
    old_remarks TEXT NULL,
    new_remarks TEXT NULL,
    previous_status VARCHAR(50) NOT NULL,
    new_status VARCHAR(50) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_damage_assessment_report (report_id, id),
    CONSTRAINT fk_damage_assessment_report FOREIGN KEY (report_id) REFERENCES damage_reports(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_damage_assessment_admin FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE RESTRICT ON UPDATE CASCADE
);

CREATE TABLE IF NOT EXISTS weather_advisories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(180) NOT NULL,
    agricultural_category VARCHAR(100) NOT NULL,
    weather_condition VARCHAR(100) NOT NULL,
    location_coverage VARCHAR(255) NOT NULL,
    coverage_scope ENUM('City', 'Barangays') NOT NULL DEFAULT 'City',
    coverage_city_psgc_code CHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '1600203000',
    coverage_city_name VARCHAR(100) NOT NULL DEFAULT 'Cabadbaran City',
    coverage_province_name VARCHAR(100) NOT NULL DEFAULT 'Agusan del Norte',
    advisory_message TEXT NOT NULL,
    effective_date DATE NOT NULL,
    expiration_date DATE NULL,
    status ENUM('Draft', 'Active', 'Archived') NOT NULL DEFAULT 'Draft',
    published_at DATETIME NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_weather_advisories_category (agricultural_category),
    INDEX idx_weather_advisories_status_dates (status, effective_date, expiration_date),
    CONSTRAINT fk_weather_advisories_admin FOREIGN KEY (created_by) REFERENCES admins(id) ON DELETE RESTRICT ON UPDATE CASCADE
);

ALTER TABLE weather_advisories
    ADD COLUMN IF NOT EXISTS coverage_scope ENUM('City', 'Barangays') NOT NULL DEFAULT 'City' AFTER location_coverage,
    ADD COLUMN IF NOT EXISTS coverage_city_psgc_code CHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '1600203000' AFTER coverage_scope,
    ADD COLUMN IF NOT EXISTS coverage_city_name VARCHAR(100) NOT NULL DEFAULT 'Cabadbaran City' AFTER coverage_city_psgc_code,
    ADD COLUMN IF NOT EXISTS coverage_province_name VARCHAR(100) NOT NULL DEFAULT 'Agusan del Norte' AFTER coverage_city_name,
    ADD COLUMN IF NOT EXISTS published_at DATETIME NULL AFTER status;

UPDATE weather_advisories
SET location_coverage = 'Entire Cabadbaran City, Agusan del Norte',
    coverage_scope = 'City', coverage_city_psgc_code = '1600203000',
    coverage_city_name = 'Cabadbaran City', coverage_province_name = 'Agusan del Norte'
WHERE coverage_scope = 'City';

CREATE TABLE IF NOT EXISTS weather_advisory_barangays (
    advisory_id INT UNSIGNED NOT NULL,
    barangay_code CHAR(10) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    barangay_name VARCHAR(100) NOT NULL,
    PRIMARY KEY (advisory_id, barangay_code),
    CONSTRAINT fk_weather_advisory_barangays_advisory FOREIGN KEY (advisory_id) REFERENCES weather_advisories(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_weather_advisory_barangays_barangay FOREIGN KEY (barangay_code) REFERENCES cabadbaran_barangays(psgc_code) ON DELETE RESTRICT ON UPDATE CASCADE
);

CREATE TABLE IF NOT EXISTS notifications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    farmer_id INT UNSIGNED NOT NULL,
    notification_type VARCHAR(100) NOT NULL,
    title VARCHAR(180) NOT NULL,
    message TEXT NOT NULL,
    related_report_id INT UNSIGNED NULL,
    related_advisory_id INT UNSIGNED NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_notifications_farmer_read (farmer_id, is_read),
    INDEX idx_notifications_type_date (notification_type, created_at),
    CONSTRAINT fk_notifications_farmer FOREIGN KEY (farmer_id) REFERENCES farmers(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_notifications_report FOREIGN KEY (related_report_id) REFERENCES damage_reports(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_notifications_advisory FOREIGN KEY (related_advisory_id) REFERENCES weather_advisories(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_notifications_admin FOREIGN KEY (created_by) REFERENCES admins(id) ON DELETE SET NULL ON UPDATE CASCADE
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

INSERT IGNORE INTO weather_advisory_recipients (advisory_id, farmer_id, notification_id, notified_at)
SELECT related_advisory_id, farmer_id, id, created_at FROM notifications WHERE related_advisory_id IS NOT NULL;

CREATE TABLE IF NOT EXISTS admin_settings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_id INT UNSIGNED NOT NULL UNIQUE,
    new_farmer_registration TINYINT(1) NOT NULL DEFAULT 1,
    farmer_verification_updates TINYINT(1) NOT NULL DEFAULT 1,
    new_damage_reports TINYINT(1) NOT NULL DEFAULT 1,
    damage_report_status_changes TINYINT(1) NOT NULL DEFAULT 1,
    weather_advisory_updates TINYINT(1) NOT NULL DEFAULT 1,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_admin_settings_admin FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE ON UPDATE CASCADE
);

CREATE TABLE IF NOT EXISTS farmer_ids (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    farmer_id INT UNSIGNED NOT NULL UNIQUE,
    id_type VARCHAR(100) NOT NULL,
    id_number VARCHAR(100) NULL,
    image_path VARCHAR(255) NULL,
    front_image_path VARCHAR(255) NULL,
    back_image_path VARCHAR(255) NULL,
    uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_farmer_ids_farmer FOREIGN KEY (farmer_id) REFERENCES farmers(id) ON DELETE CASCADE ON UPDATE CASCADE
);

ALTER TABLE farmer_ids
    ADD COLUMN IF NOT EXISTS front_image_path VARCHAR(255) NULL AFTER image_path,
    ADD COLUMN IF NOT EXISTS back_image_path VARCHAR(255) NULL AFTER front_image_path;

ALTER TABLE report_images
    ADD COLUMN IF NOT EXISTS display_order SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER image_path;

CREATE TABLE IF NOT EXISTS verification_email_deliveries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    farmer_id INT UNSIGNED NOT NULL,
    admin_id INT UNSIGNED NULL,
    decision ENUM('Approved', 'Rejected') NOT NULL,
    recipient_email VARCHAR(160) NOT NULL,
    delivery_recipient_email VARCHAR(160) NULL,
    routing_mode ENUM('Live', 'Development Test', 'Unknown') NOT NULL DEFAULT 'Unknown',
    transport VARCHAR(20) NOT NULL,
    status ENUM('Pending', 'Sent', 'Failed', 'Simulated') NOT NULL DEFAULT 'Pending',
    failure_code VARCHAR(80) NULL,
    retry_of_delivery_id BIGINT UNSIGNED NULL,
    attempted_at DATETIME NULL,
    delivered_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_verification_email_farmer (farmer_id, decision, created_at),
    INDEX idx_verification_email_status (status, created_at),
    UNIQUE KEY uq_verification_email_retry (retry_of_delivery_id),
    CONSTRAINT fk_verification_email_farmer FOREIGN KEY (farmer_id) REFERENCES farmers(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_verification_email_admin FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_verification_email_retry FOREIGN KEY (retry_of_delivery_id) REFERENCES verification_email_deliveries(id) ON DELETE SET NULL ON UPDATE CASCADE
);

ALTER TABLE verification_email_deliveries
    ADD COLUMN IF NOT EXISTS delivery_recipient_email VARCHAR(160) NULL AFTER recipient_email,
    ADD COLUMN IF NOT EXISTS routing_mode ENUM('Live', 'Development Test', 'Unknown') NOT NULL DEFAULT 'Unknown' AFTER delivery_recipient_email,
    ADD COLUMN IF NOT EXISTS retry_of_delivery_id BIGINT UNSIGNED NULL AFTER failure_code;

SET @add_verification_retry_index = IF(
    (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'verification_email_deliveries' AND index_name = 'uq_verification_email_retry') = 0,
    'ALTER TABLE verification_email_deliveries ADD UNIQUE KEY uq_verification_email_retry (retry_of_delivery_id)',
    'SELECT 1'
);
PREPARE verification_retry_index_statement FROM @add_verification_retry_index;
EXECUTE verification_retry_index_statement;
DEALLOCATE PREPARE verification_retry_index_statement;

SET @add_verification_retry_fk = IF(
    (SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema = DATABASE() AND table_name = 'verification_email_deliveries' AND constraint_name = 'fk_verification_email_retry') = 0,
    'ALTER TABLE verification_email_deliveries ADD CONSTRAINT fk_verification_email_retry FOREIGN KEY (retry_of_delivery_id) REFERENCES verification_email_deliveries(id) ON DELETE SET NULL ON UPDATE CASCADE',
    'SELECT 1'
);
PREPARE verification_retry_fk_statement FROM @add_verification_retry_fk;
EXECUTE verification_retry_fk_statement;
DEALLOCATE PREPARE verification_retry_fk_statement;

CREATE TABLE IF NOT EXISTS registration_email_settings (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
    sender_email VARCHAR(160) NOT NULL,
    app_password_ciphertext TEXT NOT NULL,
    app_password_iv VARCHAR(64) NOT NULL,
    app_password_tag VARCHAR(64) NOT NULL,
    last_test_status ENUM('Not Tested', 'Accepted', 'Failed') NOT NULL DEFAULT 'Not Tested',
    last_tested_at DATETIME NULL,
    last_test_error_code VARCHAR(80) NULL,
    configured_by INT UNSIGNED NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT chk_registration_email_singleton CHECK (id = 1),
    CONSTRAINT fk_registration_email_admin FOREIGN KEY (configured_by) REFERENCES admins(id) ON DELETE SET NULL ON UPDATE CASCADE
);

CREATE TABLE IF NOT EXISTS registration_email_templates (
    decision ENUM('Approved', 'Rejected') NOT NULL PRIMARY KEY,
    subject VARCHAR(180) NOT NULL,
    message_body TEXT NOT NULL,
    updated_by INT UNSIGNED NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_registration_email_template_admin FOREIGN KEY (updated_by) REFERENCES admins(id) ON DELETE SET NULL ON UPDATE CASCADE
);

INSERT INTO registration_email_templates (decision, subject, message_body)
VALUES
    ('Approved', 'Your AgriReach registration was approved', 'Hello {farmer_name},\n\nYour farmer registration has been approved by the Department of Agriculture.\n\nYou may now log in to the AgriReach farmer mobile app using the email address and password you registered.'),
    ('Rejected', 'Update on your AgriReach registration', 'Hello {farmer_name},\n\nYour farmer registration was rejected by the Department of Agriculture.\n\nReason: {rejection_reason}\n\nPlease review the reason above before contacting the Department of Agriculture or submitting corrected information when that option becomes available.')
ON DUPLICATE KEY UPDATE decision = VALUES(decision);

-- Independent admin read/archive state. Never updates a farmer's notification.
CREATE TABLE IF NOT EXISTS admin_notification_state (
    admin_id INT UNSIGNED NOT NULL,
    notification_id INT UNSIGNED NOT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    is_archived TINYINT(1) NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (admin_id, notification_id),
    CONSTRAINT fk_admin_notice_admin FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE,
    CONSTRAINT fk_admin_notice_record FOREIGN KEY (notification_id) REFERENCES notifications(id) ON DELETE CASCADE
);
