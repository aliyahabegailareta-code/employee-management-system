-- SQL Script to Add Email Column to Employees Table
-- Run this script if your employees table doesn't have an email column

-- Check if email column exists, if not, add it
-- Note: This will fail if column already exists, which is fine

ALTER TABLE `employees` 
ADD COLUMN IF NOT EXISTS `email` VARCHAR(255) NULL COMMENT 'Employee email address for OTP and notifications' AFTER `name`;

-- Add index for faster email lookups (optional but recommended)
CREATE INDEX IF NOT EXISTS `idx_employee_email` ON `employees`(`email`);

-- Verify the column was added
SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = 'employee_managements' 
AND TABLE_NAME = 'employees' 
AND COLUMN_NAME = 'email';

