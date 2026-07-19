-- Add reopen lost deal on reengagement config option
ALTER TABLE deal_automation_config 
ADD COLUMN reopen_lost_on_reengagement TINYINT(1) DEFAULT 0;
