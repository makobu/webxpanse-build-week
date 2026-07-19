-- Add parameters column to report_executions if missing (fixes "Unknown column 'parameters'" error)
-- Skip this migration if you get "Duplicate column name" (column already exists)
ALTER TABLE report_executions ADD COLUMN parameters JSON DEFAULT NULL AFTER executed_by;
