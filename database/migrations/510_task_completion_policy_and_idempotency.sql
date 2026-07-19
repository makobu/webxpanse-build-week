-- Tasks V2: canonical provenance, guarded completion policy, evidence decisions,
-- idempotent transitions, paged scan state, and workspace rollout controls.

ALTER TABLE tasks
    ADD COLUMN IF NOT EXISTS origin_type ENUM('manual','ai','automation','import') NOT NULL DEFAULT 'manual' AFTER source_run_id,
    ADD COLUMN IF NOT EXISTS completion_mode ENUM('manual','review','auto') NOT NULL DEFAULT 'manual' AFTER origin_type,
    ADD COLUMN IF NOT EXISTS completion_policy_version SMALLINT UNSIGNED NOT NULL DEFAULT 2 AFTER completion_mode,
    ADD COLUMN IF NOT EXISTS automation_dedupe_key VARCHAR(191) NULL AFTER completion_policy_version;

ALTER TABLE tasks
    ADD UNIQUE KEY IF NOT EXISTS uniq_tasks_workspace_automation_dedupe (workspace_id, automation_dedupe_key),
    ADD INDEX IF NOT EXISTS idx_tasks_workspace_completion_scan (workspace_id, completion_mode, status, id);

ALTER TABLE ai_task_evidence
    ADD COLUMN IF NOT EXISTS evidence_fingerprint CHAR(64) NULL AFTER evidence_json,
    ADD COLUMN IF NOT EXISTS decision_status ENUM('observed','accepted','rejected','superseded') NOT NULL DEFAULT 'observed' AFTER evidence_fingerprint,
    ADD COLUMN IF NOT EXISTS decided_at DATETIME NULL AFTER decision_status,
    ADD COLUMN IF NOT EXISTS decided_by INT NULL AFTER decided_at;

ALTER TABLE ai_task_evidence
    ADD UNIQUE KEY IF NOT EXISTS uniq_ai_task_evidence_fingerprint (workspace_id, task_id, evidence_fingerprint),
    ADD INDEX IF NOT EXISTS idx_ai_task_evidence_decision (workspace_id, task_id, decision_status, created_at);

ALTER TABLE ai_task_evidence
    MODIFY COLUMN evidence_type ENUM(
        'email_reply_received','deal_stage_reached','invoice_paid','workflow_step_completed',
        'contact_updated','task_dependency_completed','manual_confirmation','finance_setup_ready',
        'completed_event','note_created','document_uploaded','checklist_completed'
    ) NOT NULL,
    MODIFY COLUMN entity_type ENUM(
        'communication','deal','invoice','workflow_execution','task','contact','workspace_skill',
        'event','note','document'
    ) NOT NULL;

CREATE TABLE IF NOT EXISTS task_completion_transitions (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    task_id INT NOT NULL,
    from_status VARCHAR(32) NOT NULL,
    to_status VARCHAR(32) NOT NULL,
    decision_source ENUM('manual','review','clarity','checklist','reopen','compatibility') NOT NULL,
    confidence_score DECIMAL(5,4) NULL,
    evidence_fingerprints_json JSON NULL,
    explanation TEXT NULL,
    actor_user_id INT NULL,
    transition_key CHAR(64) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_task_completion_transition_key (workspace_id, transition_key),
    INDEX idx_task_completion_transitions_task (workspace_id, task_id, created_at),
    CONSTRAINT fk_task_completion_transition_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE task_completion_scan_queue
    ADD COLUMN IF NOT EXISTS cursor_task_id INT NULL AFTER active_dedupe_key,
    ADD COLUMN IF NOT EXISTS continuation_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER cursor_task_id,
    ADD COLUMN IF NOT EXISTS remaining_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER continuation_count;

CREATE TABLE IF NOT EXISTS workspace_task_automation_settings (
    workspace_id INT PRIMARY KEY,
    rollout_mode ENUM('review','full_auto') NOT NULL DEFAULT 'review',
    allow_user_task_opt_in TINYINT(1) NOT NULL DEFAULT 1,
    min_confidence DECIMAL(5,4) NOT NULL DEFAULT 0.9600,
    low_risk_only TINYINT(1) NOT NULL DEFAULT 1,
    updated_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_workspace_task_automation_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO workspace_task_automation_settings (workspace_id, rollout_mode, allow_user_task_opt_in, min_confidence, low_risk_only)
SELECT id, 'review', 1, 0.9600, 1
FROM workspaces
ON DUPLICATE KEY UPDATE workspace_id = VALUES(workspace_id);

UPDATE tasks
SET source_surface = NULLIF(JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.source_surface')), '')
WHERE source_surface IS NULL
  AND JSON_VALID(COALESCE(metadata_json, '{}'))
  AND JSON_EXTRACT(metadata_json, '$.source_surface') IS NOT NULL;

UPDATE tasks
SET source_run_id = COALESCE(
        NULLIF(JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.source_run_id')), ''),
        NULLIF(JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.guidance_run_id')), ''),
        NULLIF(JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.assistant_run_id')), ''),
        NULLIF(JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.commercial_run_id')), '')
    )
WHERE source_run_id IS NULL
  AND JSON_VALID(COALESCE(metadata_json, '{}'));

UPDATE tasks
SET origin_type = CASE
    WHEN source_surface IN ('ai_coach','clarity_chat','assistant','email_assistant','whatsapp_assistant','ai_mobile') THEN 'ai'
    WHEN source_surface IS NOT NULL AND source_surface <> '' THEN 'automation'
    WHEN JSON_VALID(COALESCE(metadata_json, '{}'))
         AND (
             JSON_EXTRACT(metadata_json, '$.ai_assisted') = true
             OR JSON_EXTRACT(metadata_json, '$.meeting_note_taker') = true
         ) THEN 'ai'
    ELSE 'manual'
END;

UPDATE tasks
SET completion_mode = CASE
    WHEN JSON_VALID(COALESCE(metadata_json, '{}'))
         AND JSON_EXTRACT(metadata_json, '$.auto_complete_allowed') = true
         AND COALESCE(JSON_EXTRACT(metadata_json, '$.protected_from_auto_complete'), false) <> true THEN 'auto'
    WHEN origin_type IN ('ai','automation')
         OR (
             JSON_VALID(COALESCE(metadata_json, '{}'))
             AND JSON_EXTRACT(metadata_json, '$.completion_evidence_types') IS NOT NULL
         ) THEN 'review'
    ELSE 'manual'
END,
completion_policy_version = 2;
