-- Add status column to support temporary or permanent account suspensions
ALTER TABLE users 
ADD COLUMN status ENUM('active', 'suspended') NOT NULL DEFAULT 'active';
