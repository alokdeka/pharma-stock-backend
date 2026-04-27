CREATE TABLE IF NOT EXISTS medicines (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(200) NOT NULL,
    manufacturer  VARCHAR(200),
    category      VARCHAR(100),
    price         DECIMAL(10,2),
    reorder_point INT DEFAULT 50,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
