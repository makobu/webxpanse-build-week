-- Targets V2: typed provenance and measurement, guarded automation,
-- immutable evidence/transitions, cursor scans, and referential integrity.

ALTER TABLE targets
    ADD COLUMN IF NOT EXISTS origin_type ENUM('manual','ai','automation','import') NOT NULL DEFAULT 'manual' AFTER source_capability_key,
    ADD COLUMN IF NOT EXISTS automation_mode ENUM('manual','review','auto') NOT NULL DEFAULT 'manual' AFTER origin_type,
    ADD COLUMN IF NOT EXISTS automation_dedupe_key VARCHAR(191) NULL AFTER automation_mode,
    ADD COLUMN IF NOT EXISTS source_surface VARCHAR(80) NULL AFTER automation_dedupe_key,
    ADD COLUMN IF NOT EXISTS source_run_id VARCHAR(191) NULL AFTER source_surface,
    ADD COLUMN IF NOT EXISTS rollup_source VARCHAR(80) NULL AFTER source_run_id,
    ADD COLUMN IF NOT EXISTS rollup_metric VARCHAR(80) NULL AFTER rollup_source,
    ADD COLUMN IF NOT EXISTS rollup_window ENUM('target_period','since_creation','lifetime') NOT NULL DEFAULT 'target_period' AFTER rollup_metric,
    ADD COLUMN IF NOT EXISTS rollup_filters_json JSON NULL AFTER rollup_window,
    ADD COLUMN IF NOT EXISTS currency_code CHAR(3) NULL AFTER rollup_filters_json,
    ADD COLUMN IF NOT EXISTS state_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER currency_code,
    ADD COLUMN IF NOT EXISTS completion_source ENUM('manual','rollup','ai_review','import') NULL AFTER state_version,
    ADD COLUMN IF NOT EXISTS completed_by_user_id INT NULL AFTER completion_source,
    ADD COLUMN IF NOT EXISTS reopened_at DATETIME NULL AFTER completed_by_user_id,
    ADD COLUMN IF NOT EXISTS reopened_by_user_id INT NULL AFTER reopened_at;

ALTER TABLE targets
    ADD UNIQUE KEY IF NOT EXISTS uniq_targets_workspace_automation_dedupe (workspace_id, automation_dedupe_key),
    ADD INDEX IF NOT EXISTS idx_targets_workspace_automation_scan (workspace_id, status, progress_mode, automation_mode, id),
    ADD INDEX IF NOT EXISTS idx_targets_source_run (workspace_id, source_surface, source_run_id),
    ADD INDEX IF NOT EXISTS idx_targets_rollup_lookup (workspace_id, rollup_source, rollup_metric, status, id);

ALTER TABLE target_reminders
    ADD COLUMN IF NOT EXISTS delivery_key CHAR(64) NULL AFTER notification_id,
    ADD COLUMN IF NOT EXISTS delivery_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER delivery_key,
    ADD COLUMN IF NOT EXISTS last_error TEXT NULL AFTER delivery_attempts,
    ADD COLUMN IF NOT EXISTS provider_response_json JSON NULL AFTER last_error,
    ADD UNIQUE KEY IF NOT EXISTS uniq_target_reminder_delivery (workspace_id, delivery_key);

CREATE TABLE IF NOT EXISTS workspace_target_automation_settings (
    workspace_id INT PRIMARY KEY,
    mode ENUM('off','review','full_auto') NOT NULL DEFAULT 'review',
    allow_user_target_opt_in TINYINT(1) NOT NULL DEFAULT 1,
    confidence_threshold DECIMAL(5,4) NOT NULL DEFAULT 0.9600,
    low_risk_only TINYINT(1) NOT NULL DEFAULT 1,
    allow_ai_creation TINYINT(1) NOT NULL DEFAULT 1,
    allow_ai_revision TINYINT(1) NOT NULL DEFAULT 1,
    allow_ai_completion TINYINT(1) NOT NULL DEFAULT 1,
    allow_automated_reminders TINYINT(1) NOT NULL DEFAULT 1,
    allow_ai_advice TINYINT(1) NOT NULL DEFAULT 1,
    updated_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_workspace_target_automation_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS target_evidence (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    target_id INT NOT NULL,
    collector_key VARCHAR(120) NOT NULL,
    source_entity_type VARCHAR(80) NOT NULL,
    source_entity_id BIGINT NULL,
    observed_at DATETIME NOT NULL,
    contribution_value DECIMAL(18,4) NOT NULL DEFAULT 0,
    currency_code CHAR(3) NULL,
    evidence_payload_json JSON NULL,
    evidence_fingerprint CHAR(64) NOT NULL,
    decision_state ENUM('observed','accepted','rejected','superseded') NOT NULL DEFAULT 'observed',
    decided_at DATETIME NULL,
    decided_by_user_id INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_target_evidence_fingerprint (workspace_id, target_id, evidence_fingerprint),
    INDEX idx_target_evidence_decision (workspace_id, target_id, decision_state, observed_at),
    CONSTRAINT fk_target_evidence_target FOREIGN KEY (target_id) REFERENCES targets(id) ON DELETE CASCADE,
    CONSTRAINT fk_target_evidence_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS target_automation_proposals (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    target_id INT NULL,
    proposal_type ENUM('create','revise','complete','reopen','milestone') NOT NULL,
    proposed_changes_json JSON NULL,
    confidence_score DECIMAL(5,4) NULL,
    risk_level ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
    explanation TEXT NULL,
    evidence_fingerprints_json JSON NULL,
    missing_evidence_json JSON NULL,
    conflicts_json JSON NULL,
    decision_state ENUM('pending','approved','rejected','applied','failed','superseded') NOT NULL DEFAULT 'pending',
    provider_key VARCHAR(80) NULL,
    source_run_id VARCHAR(191) NULL,
    reviewed_by_user_id INT NULL,
    reviewed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_target_proposals_review (workspace_id, decision_state, proposal_type, id),
    INDEX idx_target_proposals_target (workspace_id, target_id, created_at),
    CONSTRAINT fk_target_proposal_target FOREIGN KEY (target_id) REFERENCES targets(id) ON DELETE CASCADE,
    CONSTRAINT fk_target_proposal_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS target_state_transitions (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    target_id INT NOT NULL,
    from_status VARCHAR(32) NOT NULL,
    to_status VARCHAR(32) NOT NULL,
    previous_value DECIMAL(18,4) NOT NULL DEFAULT 0,
    new_value DECIMAL(18,4) NOT NULL DEFAULT 0,
    actor_type ENUM('user','clarity','automation','import','system') NOT NULL,
    actor_user_id INT NULL,
    decision_source ENUM('manual','rollup','ai_review','reopen','missed','cancel','compatibility','import') NOT NULL,
    confidence_score DECIMAL(5,4) NULL,
    evidence_fingerprints_json JSON NULL,
    proposal_id BIGINT NULL,
    explanation TEXT NULL,
    transition_key CHAR(64) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_target_transition_key (workspace_id, transition_key),
    INDEX idx_target_transitions_target (workspace_id, target_id, created_at),
    CONSTRAINT fk_target_transition_target FOREIGN KEY (target_id) REFERENCES targets(id) ON DELETE CASCADE,
    CONSTRAINT fk_target_transition_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_target_transition_proposal FOREIGN KEY (proposal_id) REFERENCES target_automation_proposals(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS target_intelligence_scan_queue (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    source_entity_type VARCHAR(80) NULL,
    source_entity_id BIGINT NULL,
    cursor_target_id INT NOT NULL DEFAULT 0,
    page_size SMALLINT UNSIGNED NOT NULL DEFAULT 100,
    continuation_count INT UNSIGNED NOT NULL DEFAULT 0,
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    state ENUM('pending','processing','completed','failed','dead_letter') NOT NULL DEFAULT 'pending',
    dedupe_key VARCHAR(191) NOT NULL,
    last_error TEXT NULL,
    lease_expires_at DATETIME NULL,
    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_target_scan_dedupe (workspace_id, dedupe_key),
    INDEX idx_target_scan_claim (state, available_at, lease_expires_at, id),
    CONSTRAINT fk_target_scan_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS target_migration_orphans_511 LIKE target_milestones;
ALTER TABLE target_migration_orphans_511 ADD COLUMN IF NOT EXISTS archived_at DATETIME NULL;
INSERT INTO target_migration_orphans_511
SELECT tm.*, NOW()
FROM target_milestones tm
LEFT JOIN targets t ON t.id = tm.target_id
WHERE t.id IS NULL;
DELETE tm FROM target_milestones tm LEFT JOIN targets t ON t.id = tm.target_id WHERE t.id IS NULL;

UPDATE target_reminders tr JOIN targets t ON t.id = tr.target_id SET tr.workspace_id = t.workspace_id WHERE tr.workspace_id IS NULL OR tr.workspace_id <> t.workspace_id;
UPDATE target_advice ta JOIN targets t ON t.id = ta.target_id SET ta.workspace_id = t.workspace_id WHERE ta.workspace_id IS NULL OR ta.workspace_id <> t.workspace_id;
UPDATE target_milestones tm JOIN targets t ON t.id = tm.target_id SET tm.workspace_id = t.workspace_id WHERE tm.workspace_id IS NULL OR tm.workspace_id <> t.workspace_id;

ALTER TABLE target_reminders MODIFY workspace_id INT NOT NULL;
ALTER TABLE target_advice MODIFY workspace_id INT NOT NULL;
ALTER TABLE target_milestones MODIFY workspace_id INT NOT NULL;

SET @has_milestone_fk := (SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'fk_target_milestone_target');
SET @sql := IF(@has_milestone_fk = 0, 'ALTER TABLE target_milestones ADD CONSTRAINT fk_target_milestone_target FOREIGN KEY (target_id) REFERENCES targets(id) ON DELETE CASCADE', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO workspace_target_automation_settings (workspace_id)
SELECT id FROM workspaces
ON DUPLICATE KEY UPDATE workspace_id = VALUES(workspace_id);

UPDATE targets
SET source_surface = COALESCE(
        NULLIF(source_surface, ''),
        NULLIF(JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.source_surface')), ''),
        NULLIF(JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.source')), '')
    ),
    source_run_id = COALESCE(
        NULLIF(source_run_id, ''),
        NULLIF(JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.source_run_id')), ''),
        NULLIF(JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.run_id')), '')
    )
WHERE JSON_VALID(COALESCE(metadata_json, '{}'));

UPDATE targets
SET origin_type = CASE
        WHEN source_surface IN ('ai_coach','assistant','clarity_chat','mobile_ai','ai_mobile') THEN 'ai'
        WHEN source_surface IN ('import','csv_import') THEN 'import'
        WHEN source_surface IS NOT NULL OR source_skill_key IS NOT NULL OR source_plugin_key IS NOT NULL THEN 'automation'
        ELSE 'manual'
    END,
    automation_mode = CASE WHEN progress_mode IN ('auto_rollup','hybrid') THEN 'review' ELSE 'manual' END;

UPDATE targets
SET rollup_source = NULLIF(JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.rollup_definition.source')), ''),
    rollup_metric = NULLIF(JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.rollup_definition.metric')), ''),
    rollup_filters_json = JSON_EXTRACT(metadata_json, '$.rollup_definition.filters')
WHERE JSON_VALID(COALESCE(metadata_json, '{}'))
  AND JSON_EXTRACT(metadata_json, '$.rollup_definition') IS NOT NULL;
