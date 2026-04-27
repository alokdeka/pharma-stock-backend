-- 013_update_users_reset.sql
ALTER TABLE users ADD COLUMN reset_token VARCHAR(255) NULL;
ALTER TABLE users ADD COLUMN reset_expires DATETIME NULL;
