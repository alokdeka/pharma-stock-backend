-- Create activity_logs table for auditing system-wide user actions
CREATE TABLE IF NOT EXISTS activity_logs (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NULL,
    action      VARCHAR(255) NOT NULL,
    details     TEXT NULL,
    ip_address  VARCHAR(45) NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);
