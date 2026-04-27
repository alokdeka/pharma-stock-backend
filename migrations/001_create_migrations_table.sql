-- Tracks which migrations have been run
CREATE TABLE IF NOT EXISTS migrations (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    filename   VARCHAR(255) NOT NULL UNIQUE,
    ran_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
