-- Fix: Add two_factor_enabled if missing (064 may have been marked executed without applying)
ALTER TABLE users ADD COLUMN two_factor_enabled BOOLEAN DEFAULT FALSE;
