CREATE TABLE IF NOT EXISTS batches (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    medicine_id  INT NOT NULL,
    batch_number VARCHAR(100) UNIQUE NOT NULL,
    mfg_date     DATE NOT NULL,
    expiry_date  DATE NOT NULL,
    quantity     INT NOT NULL DEFAULT 0,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (medicine_id) REFERENCES medicines(id) ON DELETE CASCADE
);
