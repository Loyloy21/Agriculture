USE agrireach;

-- Additive: single-admin replacement for the MySQL rollback copy. Mirrors
-- database/pgsql/20261024_0008_admin_replacement.sql. MariaDB has no partial unique indexes, so generated
-- columns hold 1 for the rows that must be unique (the active admin; the open replacement) and NULL otherwise.
-- New tables use the existing tables' charset/collation, which their foreign keys require.
ALTER TABLE admins
    ADD COLUMN IF NOT EXISTS active_admin_slot TINYINT AS (IF(status = 'active', 1, NULL)) STORED,
    ADD UNIQUE KEY IF NOT EXISTS uq_admins_single_active (active_admin_slot);

CREATE TABLE IF NOT EXISTS admin_replacement_invitations (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    status VARCHAR(9) NOT NULL DEFAULT 'Pending',
    full_name VARCHAR(120) NOT NULL,
    email VARCHAR(60) NOT NULL,
    invited_by_admin_id INT UNSIGNED NULL,
    token_hash CHAR(64) NULL,
    token_expires_at DATETIME NULL,
    email_status VARCHAR(9) NOT NULL DEFAULT 'Pending',
    email_failure_code VARCHAR(80) NULL,
    send_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    last_sent_at DATETIME NULL,
    password_hash VARCHAR(255) NULL,
    setup_completed_at DATETIME NULL,
    ready_expires_at DATETIME NULL,
    replacement_admin_id INT UNSIGNED NULL,
    closed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    open_slot TINYINT AS (IF(status IN ('Pending', 'Ready'), 1, NULL)) STORED,
    UNIQUE KEY uq_admin_replacement_token (token_hash),
    UNIQUE KEY uq_admin_replacement_open (open_slot),
    INDEX fk_admin_replacement_inviter (invited_by_admin_id),
    INDEX fk_admin_replacement_admin (replacement_admin_id),
    CONSTRAINT fk_admin_replacement_inviter FOREIGN KEY (invited_by_admin_id) REFERENCES admins(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_admin_replacement_admin FOREIGN KEY (replacement_admin_id) REFERENCES admins(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT chk_admin_replacement_status CHECK (status IN ('Pending', 'Ready', 'Completed', 'Cancelled', 'Expired')),
    CONSTRAINT chk_admin_replacement_email_status CHECK (email_status IN ('Pending', 'Sent', 'Simulated', 'Failed'))
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS admin_account_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    event_type VARCHAR(32) NOT NULL,
    actor_admin_id INT UNSIGNED NULL,
    subject_admin_id INT UNSIGNED NULL,
    invitation_id INT UNSIGNED NULL,
    detail VARCHAR(255) NULL,
    ip_hash CHAR(64) NULL,
    created_at_utc DATETIME NOT NULL,
    INDEX idx_admin_account_events_type_time (event_type, created_at_utc),
    INDEX idx_admin_account_events_actor (actor_admin_id, created_at_utc),
    INDEX idx_admin_account_events_ip (ip_hash, created_at_utc),
    INDEX fk_admin_account_events_subject (subject_admin_id),
    INDEX fk_admin_account_events_invitation (invitation_id),
    CONSTRAINT fk_admin_account_events_actor FOREIGN KEY (actor_admin_id) REFERENCES admins(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_admin_account_events_subject FOREIGN KEY (subject_admin_id) REFERENCES admins(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_admin_account_events_invitation FOREIGN KEY (invitation_id) REFERENCES admin_replacement_invitations(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT chk_admin_account_events_type CHECK (event_type IN (
        'invitation_created', 'invitation_resent', 'invitation_send_failed', 'invitation_cancelled', 'invitation_expired',
        'invitation_viewed', 'invitation_rejected', 'setup_completed', 'transfer_completed', 'transfer_failed',
        'transfer_reauth_failed', 'notification_sent', 'notification_failed', 'sender_changed', 'sender_change_rejected',
        'recovery_password_reset', 'recovery_admin_activated'))
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
