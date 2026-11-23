ALTER TABLE conversations ADD user_low BIGINT UNSIGNED NULL, ADD user_high BIGINT UNSIGNED NULL,
 ADD UNIQUE KEY conversation_pair(user_low,user_high),
 ADD FOREIGN KEY(user_low) REFERENCES users(id) ON DELETE CASCADE,
 ADD FOREIGN KEY(user_high) REFERENCES users(id) ON DELETE CASCADE;
ALTER TABLE messages ADD client_id CHAR(32) NULL, ADD revision INT UNSIGNED NOT NULL DEFAULT 1, ADD UNIQUE KEY message_client(conversation_id,client_id);
CREATE TABLE conversation_reads (
 conversation_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NOT NULL,
 last_message_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
 PRIMARY KEY(conversation_id,user_id),
 FOREIGN KEY(conversation_id,user_id) REFERENCES conversation_members(conversation_id,user_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
UPDATE site_settings SET setting_value='0.2.0' WHERE setting_key='version';
