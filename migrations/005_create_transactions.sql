CREATE TABLE IF NOT EXISTS transactions (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    batch_id    INT NOT NULL,
    type        ENUM('in','out') NOT NULL,
    quantity    INT NOT NULL,
    reference   VARCHAR(200),
    created_by  INT,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (batch_id)   REFERENCES batches(id),
    FOREIGN KEY (created_by) REFERENCES users(id)
);
