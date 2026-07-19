ALTER TABLE emails
    ADD COLUMN sender_profile ENUM('default','outreach','nurture','assistant') NULL DEFAULT NULL AFTER from_name;

ALTER TABLE emails
    ADD KEY idx_emails_sender_profile (workspace_id, sender_profile, status, created_at);
