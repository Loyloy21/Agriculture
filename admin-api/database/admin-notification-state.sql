-- Additive: farmer notification/read state remains owned by the farmer API.
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
