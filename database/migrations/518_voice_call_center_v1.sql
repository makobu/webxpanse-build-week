-- AI-first Voice & Call Center production v1 foundation.
-- All runtime records are workspace scoped. Provider media remains outside CRM.

CREATE TABLE IF NOT EXISTS workspace_voice_configs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT NOT NULL,
    provider VARCHAR(40) NOT NULL DEFAULT 'africastalking',
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    inbound_enabled TINYINT(1) NOT NULL DEFAULT 0,
    outbound_enabled TINYINT(1) NOT NULL DEFAULT 0,
    recording_enabled TINYINT(1) NOT NULL DEFAULT 0,
    transcription_enabled TINYINT(1) NOT NULL DEFAULT 0,
    ai_application_enabled TINYINT(1) NOT NULL DEFAULT 0,
    customer_voice_enabled TINYINT(1) NOT NULL DEFAULT 0,
    account_username VARCHAR(191) NULL,
    encrypted_api_key TEXT NULL,
    api_key_fingerprint VARCHAR(32) NULL,
    virtual_number VARCHAR(40) NULL,
    encrypted_callback_token TEXT NULL,
    callback_token_hash CHAR(64) NULL,
    consent_mode ENUM('explicit_keypress','notice_only','disabled') NOT NULL DEFAULT 'explicit_keypress',
    consent_notice TEXT NULL,
    compliance_acknowledged_at DATETIME NULL,
    compliance_acknowledged_by_user_id INT NULL,
    allowed_country_codes_json JSON NULL,
    blocked_prefixes_json JSON NULL,
    max_call_duration_seconds INT UNSIGNED NOT NULL DEFAULT 3600,
    hourly_call_limit INT UNSIGNED NOT NULL DEFAULT 60,
    daily_minute_limit INT UNSIGNED NOT NULL DEFAULT 1000,
    recording_retention_days INT UNSIGNED NOT NULL DEFAULT 30,
    transcript_retention_days INT UNSIGNED NOT NULL DEFAULT 180,
    transcription_model VARCHAR(120) NOT NULL DEFAULT 'gpt-4o-mini-transcribe',
    status ENUM('not_configured','needs_setup','ready','degraded','disabled') NOT NULL DEFAULT 'not_configured',
    last_verified_at DATETIME NULL,
    last_callback_at DATETIME NULL,
    last_event_at DATETIME NULL,
    last_error VARCHAR(500) NULL,
    settings_json JSON NULL,
    created_by_user_id INT NULL,
    updated_by_user_id INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_workspace_voice_config (workspace_id),
    UNIQUE KEY uniq_workspace_voice_virtual_number (provider, virtual_number),
    KEY idx_workspace_voice_status (workspace_id, enabled, status),
    KEY idx_workspace_voice_callback_token (callback_token_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS voice_agents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT NOT NULL,
    user_id INT NOT NULL,
    display_name VARCHAR(191) NOT NULL,
    endpoint_type ENUM('phone','sip') NOT NULL DEFAULT 'phone',
    encrypted_endpoint TEXT NOT NULL,
    endpoint_hash CHAR(64) NOT NULL,
    endpoint_masked VARCHAR(191) NOT NULL,
    presence_status ENUM('available','away','offline','busy') NOT NULL DEFAULT 'offline',
    pre_call_presence_status ENUM('available','away','offline') NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    last_assigned_at DATETIME NULL,
    last_successful_call_at DATETIME NULL,
    created_by_user_id INT NULL,
    updated_by_user_id INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_voice_agent_workspace_user (workspace_id, user_id),
    UNIQUE KEY uniq_voice_agent_workspace_endpoint (workspace_id, endpoint_hash),
    KEY idx_voice_agent_presence (workspace_id, enabled, presence_status, last_assigned_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS voice_queues (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT NOT NULL,
    name VARCHAR(191) NOT NULL,
    slug VARCHAR(120) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    is_default TINYINT(1) NOT NULL DEFAULT 0,
    routing_strategy ENUM('priority_least_recent','priority') NOT NULL DEFAULT 'priority_least_recent',
    max_wait_seconds INT UNSIGNED NOT NULL DEFAULT 120,
    business_hours_json JSON NULL,
    fallback_action ENUM('voicemail','verified_number','alternate_queue','reject') NOT NULL DEFAULT 'voicemail',
    fallback_queue_id INT UNSIGNED NULL,
    encrypted_fallback_target TEXT NULL,
    fallback_target_masked VARCHAR(191) NULL,
    greeting_text TEXT NULL,
    voicemail_prompt TEXT NULL,
    created_by_user_id INT NULL,
    updated_by_user_id INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_voice_queue_workspace_slug (workspace_id, slug),
    KEY idx_voice_queue_workspace_default (workspace_id, enabled, is_default),
    CONSTRAINT fk_voice_queue_fallback_queue
        FOREIGN KEY (fallback_queue_id) REFERENCES voice_queues(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS voice_queue_members (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT NOT NULL,
    queue_id INT UNSIGNED NOT NULL,
    agent_id INT UNSIGNED NOT NULL,
    priority INT NOT NULL DEFAULT 100,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_voice_queue_member (workspace_id, queue_id, agent_id),
    KEY idx_voice_queue_member_route (workspace_id, queue_id, enabled, priority),
    CONSTRAINT fk_voice_queue_member_queue
        FOREIGN KEY (queue_id) REFERENCES voice_queues(id) ON DELETE CASCADE,
    CONSTRAINT fk_voice_queue_member_agent
        FOREIGN KEY (agent_id) REFERENCES voice_agents(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS voice_calls (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT NOT NULL,
    uuid CHAR(36) NOT NULL,
    provider VARCHAR(40) NOT NULL DEFAULT 'africastalking',
    provider_session_id VARCHAR(191) NULL,
    provider_parent_session_id VARCHAR(191) NULL,
    direction ENUM('inbound','outbound') NOT NULL,
    state ENUM(
        'requested','queued','received','consent_pending','dialing_agent','agent_answered',
        'dialing_customer','ringing','in_progress','completed','cancelled','busy','no_answer',
        'rejected','expired','provider_failed','policy_blocked'
    ) NOT NULL DEFAULT 'requested',
    contact_id INT NULL,
    queue_id INT UNSIGNED NULL,
    agent_id INT UNSIGNED NULL,
    agent_user_id INT NULL,
    encrypted_from_number TEXT NULL,
    from_number_hash CHAR(64) NULL,
    from_number_masked VARCHAR(40) NULL,
    encrypted_to_number TEXT NULL,
    to_number_hash CHAR(64) NULL,
    to_number_masked VARCHAR(40) NULL,
    consent_status ENUM('not_required','pending','granted','declined','timed_out') NOT NULL DEFAULT 'pending',
    recording_status ENUM('disabled','pending','recording','ready','failed','deleted') NOT NULL DEFAULT 'disabled',
    transcription_status ENUM('disabled','pending','processing','ready','failed','deleted') NOT NULL DEFAULT 'disabled',
    disposition VARCHAR(120) NULL,
    disposition_notes TEXT NULL,
    failure_category VARCHAR(120) NULL,
    failure_message VARCHAR(500) NULL,
    requested_at DATETIME NULL,
    queued_at DATETIME NULL,
    ringing_at DATETIME NULL,
    answered_at DATETIME NULL,
    completed_at DATETIME NULL,
    duration_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    provider_metadata_json JSON NULL,
    created_by_user_id INT NULL,
    updated_by_user_id INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_voice_call_workspace_uuid (workspace_id, uuid),
    UNIQUE KEY uniq_voice_call_provider_session (workspace_id, provider, provider_session_id),
    KEY idx_voice_call_active (workspace_id, state, created_at),
    KEY idx_voice_call_contact (workspace_id, contact_id, created_at),
    KEY idx_voice_call_agent (workspace_id, agent_user_id, created_at),
    KEY idx_voice_call_from_hash (workspace_id, from_number_hash),
    KEY idx_voice_call_to_hash (workspace_id, to_number_hash),
    CONSTRAINT fk_voice_call_queue FOREIGN KEY (queue_id) REFERENCES voice_queues(id) ON DELETE SET NULL,
    CONSTRAINT fk_voice_call_agent FOREIGN KEY (agent_id) REFERENCES voice_agents(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS voice_call_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT NOT NULL,
    call_id BIGINT UNSIGNED NULL,
    provider VARCHAR(40) NOT NULL,
    provider_event_key VARCHAR(191) NOT NULL,
    provider_session_id VARCHAR(191) NULL,
    event_type VARCHAR(80) NOT NULL,
    normalized_state VARCHAR(40) NULL,
    payload_hash CHAR(64) NOT NULL,
    metadata_json JSON NULL,
    source_ip_hash CHAR(64) NULL,
    accepted TINYINT(1) NOT NULL DEFAULT 1,
    rejection_reason VARCHAR(191) NULL,
    occurred_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_voice_provider_event (workspace_id, provider, provider_event_key),
    KEY idx_voice_event_call (workspace_id, call_id, id),
    KEY idx_voice_event_session (workspace_id, provider_session_id),
    CONSTRAINT fk_voice_event_call FOREIGN KEY (call_id) REFERENCES voice_calls(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS voice_recordings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT NOT NULL,
    call_id BIGINT UNSIGNED NOT NULL,
    provider VARCHAR(40) NOT NULL,
    provider_recording_id VARCHAR(191) NULL,
    encrypted_provider_url TEXT NULL,
    provider_host VARCHAR(191) NULL,
    status ENUM('pending','ready','failed','deleted') NOT NULL DEFAULT 'pending',
    duration_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    mime_type VARCHAR(100) NULL,
    size_bytes BIGINT UNSIGNED NULL,
    retained_until DATETIME NULL,
    deleted_at DATETIME NULL,
    last_error VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_voice_recording_call (workspace_id, call_id),
    UNIQUE KEY uniq_voice_recording_provider (workspace_id, provider, provider_recording_id),
    KEY idx_voice_recording_retention (workspace_id, status, retained_until),
    CONSTRAINT fk_voice_recording_call FOREIGN KEY (call_id) REFERENCES voice_calls(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS voice_transcription_jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT NOT NULL,
    call_id BIGINT UNSIGNED NOT NULL,
    recording_id BIGINT UNSIGNED NOT NULL,
    status ENUM('pending','processing','completed','failed','dead_letter') NOT NULL DEFAULT 'pending',
    attempt_count INT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts INT UNSIGNED NOT NULL DEFAULT 5,
    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    lease_token VARCHAR(120) NULL,
    leased_at DATETIME NULL,
    completed_at DATETIME NULL,
    last_error VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_voice_transcription_call (workspace_id, call_id),
    KEY idx_voice_transcription_queue (status, available_at, leased_at),
    CONSTRAINT fk_voice_transcription_call FOREIGN KEY (call_id) REFERENCES voice_calls(id) ON DELETE CASCADE,
    CONSTRAINT fk_voice_transcription_recording FOREIGN KEY (recording_id) REFERENCES voice_recordings(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS voice_call_transcripts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT NOT NULL,
    call_id BIGINT UNSIGNED NOT NULL,
    encrypted_transcript LONGTEXT NOT NULL,
    transcript_fingerprint CHAR(64) NOT NULL,
    redacted_preview TEXT NULL,
    language_code VARCHAR(20) NULL,
    speaker_segments_json JSON NULL,
    provider VARCHAR(40) NOT NULL DEFAULT 'openai',
    model VARCHAR(120) NOT NULL,
    confidence DECIMAL(6,5) NULL,
    retained_until DATETIME NULL,
    deleted_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_voice_transcript_call (workspace_id, call_id),
    KEY idx_voice_transcript_retention (workspace_id, retained_until, deleted_at),
    CONSTRAINT fk_voice_transcript_call FOREIGN KEY (call_id) REFERENCES voice_calls(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS voice_call_insights (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT NOT NULL,
    call_id BIGINT UNSIGNED NOT NULL,
    contact_id INT NULL,
    summary TEXT NULL,
    relationship_context TEXT NULL,
    sentiment VARCHAR(40) NULL,
    intent VARCHAR(120) NULL,
    pains_json JSON NULL,
    goals_json JSON NULL,
    objections_json JSON NULL,
    commitments_json JSON NULL,
    requested_actions_json JSON NULL,
    next_step TEXT NULL,
    contact_updates_json JSON NULL,
    task_suggestions_json JSON NULL,
    deal_stage_suggestion_json JSON NULL,
    confidence DECIMAL(6,5) NULL,
    evidence_json JSON NULL,
    extracted_actions_json JSON NULL,
    applied_actions_json JSON NULL,
    skipped_actions_json JSON NULL,
    review_status ENUM('pending','reviewed','applied','dismissed') NOT NULL DEFAULT 'pending',
    reviewed_by_user_id INT NULL,
    reviewed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_voice_call_insight (workspace_id, call_id),
    KEY idx_voice_insight_contact (workspace_id, contact_id, created_at),
    KEY idx_voice_insight_review (workspace_id, review_status, created_at),
    CONSTRAINT fk_voice_insight_call FOREIGN KEY (call_id) REFERENCES voice_calls(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS voice_usage_ledger (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT NOT NULL,
    call_id BIGINT UNSIGNED NOT NULL,
    direction ENUM('inbound','outbound') NOT NULL,
    duration_seconds INT UNSIGNED NOT NULL DEFAULT 0,
    estimated_billable_minutes INT UNSIGNED NOT NULL DEFAULT 0,
    estimated_provider_cost DECIMAL(14,4) NOT NULL DEFAULT 0,
    provider_currency CHAR(3) NOT NULL DEFAULT 'KES',
    transcription_model VARCHAR(120) NULL,
    estimated_ai_cost DECIMAL(14,6) NOT NULL DEFAULT 0,
    estimate_only TINYINT(1) NOT NULL DEFAULT 1,
    metadata_json JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_voice_usage_call (workspace_id, call_id),
    KEY idx_voice_usage_period (workspace_id, created_at),
    CONSTRAINT fk_voice_usage_call FOREIGN KEY (call_id) REFERENCES voice_calls(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS marketing_customer_voice_insights (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT NOT NULL,
    category ENUM('pain','goal','objection','requested_feature','buying_language','recurring_question') NOT NULL,
    topic_hash CHAR(64) NOT NULL,
    topic_label VARCHAR(255) NOT NULL,
    summary TEXT NULL,
    evidence_count INT UNSIGNED NOT NULL DEFAULT 0,
    distinct_contact_count INT UNSIGNED NOT NULL DEFAULT 0,
    confidence DECIMAL(6,5) NULL,
    review_status ENUM('pending','accepted','dismissed') NOT NULL DEFAULT 'pending',
    accepted_target_type VARCHAR(80) NULL,
    accepted_target_id BIGINT NULL,
    reviewed_by_user_id INT NULL,
    reviewed_at DATETIME NULL,
    first_seen_at DATETIME NULL,
    last_seen_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_customer_voice_topic (workspace_id, category, topic_hash),
    KEY idx_customer_voice_review (workspace_id, review_status, confidence)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS marketing_customer_voice_evidence (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT NOT NULL,
    customer_voice_insight_id BIGINT UNSIGNED NOT NULL,
    voice_call_insight_id BIGINT UNSIGNED NOT NULL,
    contact_id INT NULL,
    evidence_hash CHAR(64) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_customer_voice_evidence (workspace_id, customer_voice_insight_id, voice_call_insight_id, evidence_hash),
    KEY idx_customer_voice_evidence_contact (workspace_id, contact_id),
    CONSTRAINT fk_customer_voice_evidence_topic
        FOREIGN KEY (customer_voice_insight_id) REFERENCES marketing_customer_voice_insights(id) ON DELETE CASCADE,
    CONSTRAINT fk_customer_voice_evidence_call
        FOREIGN KEY (voice_call_insight_id) REFERENCES voice_call_insights(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS voice_worker_heartbeats (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT NOT NULL,
    worker_key VARCHAR(80) NOT NULL,
    status ENUM('healthy','degraded','failed') NOT NULL DEFAULT 'healthy',
    pending_count INT UNSIGNED NOT NULL DEFAULT 0,
    failed_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_error VARCHAR(500) NULL,
    heartbeat_at DATETIME NOT NULL,
    metadata_json JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_voice_worker_heartbeat (workspace_id, worker_key),
    KEY idx_voice_worker_health (worker_key, heartbeat_at, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS voice_provider_rate_limits (
    workspace_id INT PRIMARY KEY,
    next_release_at DATETIME(6) NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE communications
    MODIFY COLUMN channel ENUM('email','whatsapp','sms','web_chat','voice') NOT NULL;

ALTER TABLE conversation_threads
    MODIFY COLUMN channel ENUM('email','whatsapp','sms','web_chat','voice') NOT NULL;

INSERT INTO permissions (permission_key, label, description, is_sensitive)
VALUES
    ('voice.settings.manage', 'Manage Voice Settings', 'Configure provider credentials, routing, consent, retention, and voice kill switches', TRUE),
    ('voice.agents.manage', 'Manage Voice Agents', 'Configure agent phone or SIP endpoints and queue memberships', TRUE),
    ('voice.calls.use', 'Use Voice Calling', 'Place and receive calls through the Voice & Call Center plugin', TRUE),
    ('voice.calls.view_all', 'View All Voice Calls', 'View voice calls across the active workspace', TRUE),
    ('voice.recordings.listen', 'Listen to Voice Recordings', 'Listen to consented provider-hosted call recordings', TRUE),
    ('voice.transcripts.view', 'View Voice Transcripts', 'View decrypted call transcripts', TRUE),
    ('voice.insights.review', 'Review Voice Insights', 'Review and apply suggested CRM actions from calls', TRUE),
    ('voice.customer_voice.review', 'Review Customer Voice', 'Review anonymized multi-customer marketing insights', TRUE)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    description = VALUES(description),
    is_sensitive = VALUES(is_sensitive);

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p ON p.permission_key IN (
    'voice.settings.manage','voice.agents.manage','voice.calls.use','voice.calls.view_all',
    'voice.recordings.listen','voice.transcripts.view','voice.insights.review','voice.customer_voice.review'
)
WHERE r.slug IN ('owner','admin')
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p ON p.permission_key = 'voice.calls.use'
WHERE r.slug = 'sales'
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);

INSERT INTO role_permissions (role_id, permission_id, can_access)
SELECT r.id, p.id, 1
FROM roles r
JOIN permissions p ON p.permission_key = 'voice.customer_voice.review'
WHERE r.slug = 'marketing'
ON DUPLICATE KEY UPDATE can_access = VALUES(can_access);

INSERT INTO billing_package_features
    (feature_key, label, description, category, value_type, default_value_json, is_core, is_active, display_order)
VALUES
    ('voice_call_center', 'Voice & Call Center', 'Unlocks the AI-first Voice & Call Center Marketplace plugin.', 'plugins', 'boolean', JSON_OBJECT('value', false), 0, 1, 210),
    ('voice_concurrent_calls', 'Voice concurrent calls', 'Maximum simultaneous voice calls. Zero disables voice runtime.', 'limits', 'integer', JSON_OBJECT('value', 0), 0, 1, 211),
    ('voice_agent_limit', 'Voice agent limit', 'Maximum enabled voice agents. Zero disables voice runtime.', 'limits', 'integer', JSON_OBJECT('value', 0), 0, 1, 212),
    ('voice_recording_enabled', 'Voice recording', 'Allows consent-gated provider call recording.', 'plugins', 'boolean', JSON_OBJECT('value', false), 0, 1, 213),
    ('voice_transcription_enabled', 'Voice transcription', 'Allows post-call transcription using the workspace AI provider key.', 'plugins', 'boolean', JSON_OBJECT('value', false), 0, 1, 214),
    ('voice_customer_voice_enabled', 'Customer Voice intelligence', 'Allows anonymized multi-contact marketing insights from consented calls.', 'plugins', 'boolean', JSON_OBJECT('value', false), 0, 1, 215)
ON DUPLICATE KEY UPDATE
    label = VALUES(label),
    description = VALUES(description),
    category = VALUES(category),
    value_type = VALUES(value_type),
    default_value_json = VALUES(default_value_json),
    is_core = VALUES(is_core),
    is_active = VALUES(is_active),
    display_order = VALUES(display_order),
    updated_at = NOW();
