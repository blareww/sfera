CREATE TABLE user_sessions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 session_hash CHAR(64) NOT NULL UNIQUE,
 session_version INT UNSIGNED NOT NULL,
 device_name VARCHAR(120) NOT NULL,
 ip_hint VARCHAR(64) NOT NULL,
 created_at DATETIME NOT NULL,
 last_activity DATETIME NOT NULL,
 expires_at DATETIME NOT NULL,
 revoked_at DATETIME NULL,
 INDEX session_owner (user_id, revoked_at, last_activity),
 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
ALTER TABLE conversations ADD key_salt CHAR(32) NULL;
ALTER TABLE conversation_members ADD cleared_through BIGINT UNSIGNED NOT NULL DEFAULT 0, ADD hidden_at DATETIME NULL;
CREATE INDEX notification_unread ON notifications(user_id,read_at,id);
UPDATE site_settings SET setting_value='0.3.0' WHERE setting_key='version';
