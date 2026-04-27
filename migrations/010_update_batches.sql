ALTER TABLE batches 
ADD COLUMN location VARCHAR(100) DEFAULT 'Main Warehouse' AFTER expiry_date,
ADD COLUMN unit_cost DECIMAL(10,2) DEFAULT 0.00 AFTER location;
