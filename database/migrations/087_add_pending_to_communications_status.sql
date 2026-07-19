-- Add 'pending' to communications status enum for WhatsApp outbound messages
-- that are queued but not yet sent
ALTER TABLE communications MODIFY COLUMN status ENUM('pending','sent','delivered','read','failed') DEFAULT 'sent';
