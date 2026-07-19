-- Partition activities table by month (example - adjust as needed)
-- Note: Partitioning requires careful planning and may need to be done manually
-- This is a placeholder migration

-- ALTER TABLE activities 
-- PARTITION BY RANGE (YEAR(created_at) * 100 + MONTH(created_at)) (
--     PARTITION p202401 VALUES LESS THAN (202402),
--     PARTITION p202402 VALUES LESS THAN (202403),
--     PARTITION p_future VALUES LESS THAN MAXVALUE
-- );
