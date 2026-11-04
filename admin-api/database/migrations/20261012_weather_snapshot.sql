USE agrireach;

-- Additive: richer, honest per-report weather snapshots. Existing rows (legacy test/demo
-- snapshots) keep their values; provider IS NULL marks them as legacy with no provenance.
-- Times ending in _utc are UTC. recorded_at keeps its original meaning (observation time,
-- Asia/Manila local time) so existing admin/PDF code continues to work.
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
