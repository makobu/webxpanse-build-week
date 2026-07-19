-- Add execution_time column to report_executions if missing
-- Skip if you get "Duplicate column name" (column already exists)
ALTER TABLE report_executions ADD COLUMN execution_time DECIMAL(10,3) DEFAULT 0 AFTER result_count;
