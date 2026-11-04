USE agrireach;

-- Existing free-text addresses remain unchanged. New nullable fields identify
-- structured residential locations for future registrations without guessing
-- data for legacy farmer accounts.
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
