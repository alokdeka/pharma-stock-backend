-- In MySQL, modifying an ENUM to append a new value is safe
ALTER TABLE transactions 
MODIFY COLUMN type ENUM('in', 'out', 'spoilage') NOT NULL;
