CREATE TABLE IF NOT EXISTS purchase_orders (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    medicine_id INT NOT NULL,
    quantity    INT NOT NULL,
    status      ENUM('pending','approved','received') DEFAULT 'pending',
    created_by  INT,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (medicine_id) REFERENCES medicines(id),
    FOREIGN KEY (created_by)  REFERENCES users(id)
);
