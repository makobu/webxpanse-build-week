-- Email Assistant V2 tables and metadata columns

CREATE TABLE IF NOT EXISTS email_assistant_runs (
    id INT PRIMARY KEY AUTO_INCREMENT,
    mode ENUM('admin_command', 'customer_thread') NOT NULL DEFAULT 'admin_command',
    source ENUM('email_assistant_inbound', 'conversation_ui', 'commercial_automation', 'manual') NOT NULL DEFAULT 'manual',
    user_id INT DEFAULT NULL,
    contact_id INT DEFAULT NULL,
    deal_id INT DEFAULT NULL,
    invoice_id INT DEFAULT NULL,
    thread_type ENUM('email_assistant', 'customer_email') DEFAULT NULL,
    source_message_id INT DEFAULT NULL,
    intent VARCHAR(100) DEFAULT NULL,
    resolution_status ENUM('resolved', 'ambiguous', 'blocked', 'approval_required') NOT NULL DEFAULT 'resolved',
    execution_status ENUM('planned', 'executed', 'rejected', 'failed') NOT NULL DEFAULT 'planned',
    confidence_score DECIMAL(5,4) DEFAULT 0.0000,
    context_snapshot_json JSON DEFAULT NULL,
    plan_json JSON DEFAULT NULL,
    result_json JSON DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email_assistant_runs_mode (mode),
    INDEX idx_email_assistant_runs_user (user_id),
    INDEX idx_email_assistant_runs_contact (contact_id),
    INDEX idx_email_assistant_runs_deal (deal_id),
    INDEX idx_email_assistant_runs_invoice (invoice_id),
    INDEX idx_email_assistant_runs_created (created_at),
    CONSTRAINT fk_email_assistant_runs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_email_assistant_runs_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE SET NULL,
    CONSTRAINT fk_email_assistant_runs_deal FOREIGN KEY (deal_id) REFERENCES deals(id) ON DELETE SET NULL,
    CONSTRAINT fk_email_assistant_runs_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_assistant_resolutions (
    id INT PRIMARY KEY AUTO_INCREMENT,
    run_id INT NOT NULL,
    entity_type ENUM('contact', 'deal', 'invoice', 'approval', 'thread') NOT NULL,
    query_text VARCHAR(500) DEFAULT NULL,
    resolved_id INT DEFAULT NULL,
    resolution_method ENUM('exact_id', 'invoice_number', 'thread_context', 'deal_context', 'contact_email', 'ai_ranked') NOT NULL DEFAULT 'exact_id',
    candidate_json JSON DEFAULT NULL,
    confidence_score DECIMAL(5,4) DEFAULT 0.0000,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email_assistant_resolutions_run (run_id),
    INDEX idx_email_assistant_resolutions_entity (entity_type),
    CONSTRAINT fk_email_assistant_resolutions_run FOREIGN KEY (run_id) REFERENCES email_assistant_runs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS email_assistant_action_queue (
    id INT PRIMARY KEY AUTO_INCREMENT,
    run_id INT NOT NULL,
    action_key VARCHAR(100) NOT NULL,
    status ENUM('pending', 'approved', 'executing', 'sent', 'failed', 'cancelled') NOT NULL DEFAULT 'pending',
    channel ENUM('email') NOT NULL DEFAULT 'email',
    payload_json JSON DEFAULT NULL,
    error_message TEXT DEFAULT NULL,
    scheduled_at DATETIME DEFAULT NULL,
    completed_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email_assistant_action_queue_run (run_id),
    INDEX idx_email_assistant_action_queue_status (status),
    CONSTRAINT fk_email_assistant_action_queue_run FOREIGN KEY (run_id) REFERENCES email_assistant_runs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE email_assistant_messages
    ADD COLUMN mode ENUM('admin_command', 'customer_thread') NOT NULL DEFAULT 'admin_command' AFTER direction,
    ADD COLUMN run_id INT DEFAULT NULL AFTER mode,
    ADD COLUMN thread_contact_id INT DEFAULT NULL AFTER run_id,
    ADD COLUMN thread_deal_id INT DEFAULT NULL AFTER thread_contact_id,
    ADD COLUMN thread_invoice_id INT DEFAULT NULL AFTER thread_deal_id,
    ADD COLUMN parsed_intent VARCHAR(100) DEFAULT NULL AFTER intent,
    ADD COLUMN parsed_entities_json JSON DEFAULT NULL AFTER parsed_intent,
    ADD COLUMN resolution_status ENUM('resolved', 'ambiguous', 'blocked', 'approval_required') DEFAULT NULL AFTER parsed_entities_json;

ALTER TABLE email_assistant_messages
    ADD INDEX idx_email_assistant_messages_run (run_id),
    ADD INDEX idx_email_assistant_messages_thread_contact (thread_contact_id),
    ADD INDEX idx_email_assistant_messages_thread_deal (thread_deal_id),
    ADD INDEX idx_email_assistant_messages_thread_invoice (thread_invoice_id);

ALTER TABLE email_assistant_messages
    ADD CONSTRAINT fk_email_assistant_messages_run FOREIGN KEY (run_id) REFERENCES email_assistant_runs(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_email_assistant_messages_thread_contact FOREIGN KEY (thread_contact_id) REFERENCES contacts(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_email_assistant_messages_thread_deal FOREIGN KEY (thread_deal_id) REFERENCES deals(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_email_assistant_messages_thread_invoice FOREIGN KEY (thread_invoice_id) REFERENCES invoices(id) ON DELETE SET NULL;
