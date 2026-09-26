ALTER TABLE conversations ADD assigned_to CHAR(32) NULL, ADD FOREIGN KEY(assigned_to) REFERENCES users(id), ADD INDEX idx_status_updated(status,updated_at);
ALTER TABLE conversation_messages ADD sender_user_id CHAR(32) NULL, ADD FOREIGN KEY(sender_user_id) REFERENCES users(id);
