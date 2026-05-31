-- Migration: 017_create_locations
-- Constructs structured warehouse locations catalog and seeds default zones

CREATE TABLE IF NOT EXISTS locations (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) UNIQUE NOT NULL,
    zone        ENUM('aisle-a', 'aisle-b', 'cold-storage', 'secured-vault') NOT NULL DEFAULT 'aisle-a',
    capacity    INT NOT NULL DEFAULT 1000,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Seed default visual locations matching seed layout
INSERT IGNORE INTO locations (name, zone, capacity) VALUES 
('Aisle A - Shelf 1', 'aisle-a', 1000),
('Aisle A - Shelf 2', 'aisle-a', 1000),
('Aisle A - Shelf 3', 'aisle-a', 1000),
('Aisle B - Shelf 1', 'aisle-b', 1000),
('Aisle B - Shelf 2', 'aisle-b', 1000),
('Aisle B - Shelf 3', 'aisle-b', 1000),
('Cold Storage Zone A', 'cold-storage', 500),
('Cold Storage Zone B', 'cold-storage', 500),
('Secured Vault Zone A', 'secured-vault', 200),
('Secured Vault Zone B', 'secured-vault', 200);
