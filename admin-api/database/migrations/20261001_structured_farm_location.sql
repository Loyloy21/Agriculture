USE agrireach;

ALTER TABLE farm_profiles
    ADD COLUMN IF NOT EXISTS purok_sitio VARCHAR(100) NULL AFTER barangay_name,
    ADD COLUMN IF NOT EXISTS landmark VARCHAR(255) NULL AFTER purok_sitio,
    ADD COLUMN IF NOT EXISTS location_method ENUM('Legacy', 'Manual', 'Map') NOT NULL DEFAULT 'Legacy' AFTER landmark,
    ADD COLUMN IF NOT EXISTS location_boundary_status ENUM('Not Checked', 'Inside Mapped Boundary', 'Outside Mapped Boundary', 'Check Unavailable') NOT NULL DEFAULT 'Not Checked' AFTER location_method,
    ADD COLUMN IF NOT EXISTS location_boundary_source VARCHAR(255) NULL AFTER location_boundary_status,
    ADD COLUMN IF NOT EXISTS location_boundary_checked_at DATETIME NULL AFTER location_boundary_source;

-- Existing farms deliberately remain Legacy / Not Checked. Their address,
-- barangay classification, and coordinates are not guessed or overwritten.
