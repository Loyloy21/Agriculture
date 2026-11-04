USE agrireach;

-- Additive: NULL means provenance was not recorded for an older report/photo.
ALTER TABLE damage_reports
    ADD COLUMN IF NOT EXISTS location_source VARCHAR(24) NULL,
    ADD COLUMN IF NOT EXISTS location_photo_order SMALLINT UNSIGNED NULL,
    ADD COLUMN IF NOT EXISTS location_confirmed_at DATETIME NULL;

ALTER TABLE report_images
    ADD COLUMN IF NOT EXISTS gps_source VARCHAR(24) NULL,
    ADD COLUMN IF NOT EXISTS gps_accuracy_m DECIMAL(10,2) NULL,
    ADD COLUMN IF NOT EXISTS gps_recorded_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS capture_time_source VARCHAR(24) NULL,
    ADD COLUMN IF NOT EXISTS location_status VARCHAR(32) NULL;
