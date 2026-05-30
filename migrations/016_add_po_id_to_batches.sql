-- Migration: 016_add_po_id_to_batches
-- Adds po_id column to batches table and seeds locations for interactive map testing

ALTER TABLE batches 
ADD COLUMN po_id INT NULL AFTER id;

ALTER TABLE batches 
ADD CONSTRAINT fk_batch_po 
FOREIGN KEY (po_id) REFERENCES purchase_orders(id) 
ON DELETE SET NULL;

-- Distribute existing seeded batches across the 4 newly designed physical zones
UPDATE batches SET location = 'Aisle A - Shelf 1' WHERE id % 4 = 0;
UPDATE batches SET location = 'Aisle B - Shelf 3' WHERE id % 4 = 1;
UPDATE batches SET location = 'Cold Storage Zone A' WHERE id % 4 = 2;
UPDATE batches SET location = 'Secured Vault Zone B' WHERE id % 4 = 3;
