CREATE TABLE IF NOT EXISTS notifications (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT NULL,
    title       VARCHAR(255) NOT NULL,
    message     TEXT NOT NULL,
    type        ENUM('info', 'success', 'warning', 'error') DEFAULT 'info',
    is_read     BOOLEAN DEFAULT FALSE,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
