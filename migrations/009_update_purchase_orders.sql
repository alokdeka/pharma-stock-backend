ALTER TABLE purchase_orders 
ADD COLUMN supplier_id INT NULL AFTER medicine_id;

ALTER TABLE purchase_orders 
ADD CONSTRAINT fk_po_supplier 
FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL;
