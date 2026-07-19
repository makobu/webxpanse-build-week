-- Clean install: Empty contacts, targets, and activities
-- Run this to reset for a fresh start (keeps users, settings, etc.)

SET FOREIGN_KEY_CHECKS = 0;

-- 1. Targets (and related)
TRUNCATE TABLE target_advice;
TRUNCATE TABLE target_reminders;
TRUNCATE TABLE targets;

-- 2. Activities
TRUNCATE TABLE activities;

-- 3. Contacts (and common contact-dependent tables to avoid orphaned rows)
TRUNCATE TABLE contact_custom_data;
TRUNCATE TABLE enrichment_history;
TRUNCATE TABLE enrichment_sources;
TRUNCATE TABLE communications;
TRUNCATE TABLE whatsapp_messages;
TRUNCATE TABLE whatsapp_queue;
TRUNCATE TABLE emails;
TRUNCATE TABLE deals;
TRUNCATE TABLE tasks;
TRUNCATE TABLE notes;
TRUNCATE TABLE events;
TRUNCATE TABLE form_submissions;
TRUNCATE TABLE page_views;
TRUNCATE TABLE conversation_threads;
TRUNCATE TABLE contacts;

SET FOREIGN_KEY_CHECKS = 1;
