USE agrireach;

-- Additive: farmer Forgot Password tables for the MySQL rollback copy, so the same PHP code keeps
-- working if 'default' is ever switched back to 'mysql'. Mirrors
-- database/pgsql/20261019_0002_farmer_password_resets.sql. All *_utc columns are UTC.
CREATE TABLE IF NOT EXISTS farmer_password_resets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    farmer_id INT UNSIGNED NOT NULL,
    code_verifier VARCHAR(255) NOT NULL,
    failed_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    expires_at_utc DATETIME NOT NULL,
    verified_at_utc DATETIME NULL,
    reset_token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    reset_token_expires_at_utc DATETIME NULL,
    used_at_utc DATETIME NULL,
    revoked_at_utc DATETIME NULL,
    revoke_reason ENUM('superseded', 'attempts', 'completed') NULL,
    delivery_status ENUM('Pending', 'Sent', 'Failed', 'Simulated') NOT NULL DEFAULT 'Pending',
    delivery_failure_code VARCHAR(80) NULL,
    created_at_utc DATETIME NOT NULL,
    UNIQUE KEY uq_farmer_password_resets_token (reset_token_hash),
    INDEX idx_farmer_password_resets_farmer (farmer_id, created_at_utc),
    CONSTRAINT fk_farmer_password_resets_farmer FOREIGN KEY (farmer_id) REFERENCES farmers(id) ON DELETE CASCADE ON UPDATE CASCADE
);

CREATE TABLE IF NOT EXISTS farmer_password_reset_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    action ENUM('request', 'verify_failed', 'reset') NOT NULL,
    email_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    ip_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    created_at_utc DATETIME NOT NULL,
    INDEX idx_farmer_password_reset_events_email (action, email_hash, created_at_utc),
    INDEX idx_farmer_password_reset_events_ip (action, ip_hash, created_at_utc),
    INDEX idx_farmer_password_reset_events_created (created_at_utc)
);
