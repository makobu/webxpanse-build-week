-- Organization Intelligence V2: confirmed operating model, truthful snapshots,
-- durable contact-stage history, and private Clarity conversations.

CREATE TABLE IF NOT EXISTS organization_intelligence_profiles (
    id INT NOT NULL AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    engine_version VARCHAR(20) NOT NULL DEFAULT 'v2',
    confirmed_model VARCHAR(40) NULL,
    inferred_model VARCHAR(40) NOT NULL DEFAULT 'solo_founder',
    inference_version VARCHAR(30) NOT NULL DEFAULT 'oi-model-v2',
    inference_confidence VARCHAR(20) NOT NULL DEFAULT 'low',
    inference_evidence_json JSON NULL,
    founder_user_ids_json JSON NULL,
    confirmed_by INT NULL,
    confirmed_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_oi_profile_workspace (workspace_id),
    KEY idx_oi_profile_model (confirmed_model, inferred_model),
    CONSTRAINT fk_oi_profile_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_oi_profile_confirmed_by FOREIGN KEY (confirmed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS organization_intelligence_snapshots (
    id BIGINT NOT NULL AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    snapshot_date DATE NOT NULL,
    schema_version INT NOT NULL DEFAULT 2,
    calculation_version VARCHAR(30) NOT NULL DEFAULT 'oi-score-v2',
    confirmed_model VARCHAR(40) NULL,
    inferred_model VARCHAR(40) NOT NULL,
    evidence_level VARCHAR(20) NOT NULL DEFAULT 'low',
    eligible_people_count INT NOT NULL DEFAULT 0,
    total_people_count INT NOT NULL DEFAULT 0,
    organization_health_score DECIMAL(6,2) NULL,
    structural_readiness_score DECIMAL(6,2) NULL,
    measurement_window_start DATETIME NULL,
    measurement_window_end DATETIME NULL,
    workspace_metrics_json JSON NULL,
    people_metrics_json JSON NULL,
    function_metrics_json JSON NULL,
    department_metrics_json JSON NULL,
    component_metrics_json JSON NULL,
    source_checksum CHAR(64) NOT NULL,
    generation_source VARCHAR(30) NOT NULL DEFAULT 'cli',
    generated_at DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_oi_snapshot_workspace_date (workspace_id, snapshot_date),
    KEY idx_oi_snapshot_workspace_generated (workspace_id, generated_at),
    CONSTRAINT fk_oi_snapshot_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contact_stage_transitions (
    id BIGINT NOT NULL AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    contact_id INT NOT NULL,
    from_stage VARCHAR(50) NULL,
    to_stage VARCHAR(50) NOT NULL,
    changed_by INT NULL,
    source VARCHAR(40) NOT NULL DEFAULT 'contact_update',
    is_baseline TINYINT(1) NOT NULL DEFAULT 0,
    occurred_at DATETIME NOT NULL,
    metadata_json JSON NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_contact_stage_transition_workspace_time (workspace_id, occurred_at),
    KEY idx_contact_stage_transition_contact_time (contact_id, occurred_at),
    CONSTRAINT fk_contact_stage_transition_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_contact_stage_transition_contact FOREIGN KEY (contact_id) REFERENCES contacts(id) ON DELETE CASCADE,
    CONSTRAINT fk_contact_stage_transition_user FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO contact_stage_transitions (
    workspace_id, contact_id, from_stage, to_stage, changed_by, source, is_baseline, occurred_at, metadata_json
)
SELECT c.workspace_id, c.id, NULL, COALESCE(NULLIF(c.stage, ''), 'new'), NULL,
       'migration_baseline', 1, COALESCE(c.updated_at, c.created_at, NOW()),
       JSON_OBJECT('basis', 'current_stage_only', 'conversion_eligible', FALSE)
FROM contacts c
LEFT JOIN contact_stage_transitions existing
  ON existing.workspace_id = c.workspace_id
 AND existing.contact_id = c.id
WHERE existing.id IS NULL;

CREATE TABLE IF NOT EXISTS organization_intelligence_conversations (
    id BIGINT NOT NULL AUTO_INCREMENT,
    workspace_id INT NOT NULL,
    user_id INT NOT NULL,
    surface VARCHAR(50) NOT NULL DEFAULT 'organization_intelligence',
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    rolling_summary TEXT NULL,
    last_room VARCHAR(40) NULL,
    last_sub_room VARCHAR(40) NULL,
    last_message_at DATETIME NULL,
    expires_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_oi_conversation_owner (workspace_id, user_id, surface, status),
    KEY idx_oi_conversation_expiry (expires_at),
    CONSTRAINT fk_oi_conversation_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_oi_conversation_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS organization_intelligence_messages (
    id BIGINT NOT NULL AUTO_INCREMENT,
    conversation_id BIGINT NOT NULL,
    workspace_id INT NOT NULL,
    user_id INT NOT NULL,
    message_role VARCHAR(20) NOT NULL,
    message_text MEDIUMTEXT NOT NULL,
    room VARCHAR(40) NOT NULL DEFAULT 'brief',
    sub_room VARCHAR(40) NULL,
    scope_json JSON NULL,
    context_snapshot_id BIGINT NULL,
    guidance_run_id BIGINT NULL,
    message_hash CHAR(64) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_oi_message_conversation_created (conversation_id, created_at),
    KEY idx_oi_message_workspace_user (workspace_id, user_id, created_at),
    CONSTRAINT fk_oi_message_conversation FOREIGN KEY (conversation_id) REFERENCES organization_intelligence_conversations(id) ON DELETE CASCADE,
    CONSTRAINT fk_oi_message_workspace FOREIGN KEY (workspace_id) REFERENCES workspaces(id) ON DELETE CASCADE,
    CONSTRAINT fk_oi_message_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_oi_message_snapshot FOREIGN KEY (context_snapshot_id) REFERENCES organization_intelligence_snapshots(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO organization_intelligence_profiles (
    workspace_id, engine_version, confirmed_model, inferred_model, inference_version,
    inference_confidence, inference_evidence_json, founder_user_ids_json
)
SELECT w.id, 'v2', NULL,
       CASE
           WHEN SUM(CASE WHEN wm.membership_status = 'active' THEN 1 ELSE 0 END) <= 1 THEN 'solo_founder'
           WHEN SUM(CASE WHEN wm.membership_status = 'active' AND (wm.is_owner = 1 OR LOWER(wm.role_slug) = 'owner') THEN 1 ELSE 0 END) >= 2
                AND SUM(CASE WHEN wm.membership_status = 'active' AND NOT (wm.is_owner = 1 OR LOWER(wm.role_slug) = 'owner') THEN 1 ELSE 0 END) = 0
                THEN 'multi_founder'
           ELSE 'founder_led_team'
       END,
       'oi-model-v2', 'low', JSON_OBJECT('source', 'migration_seed'), JSON_ARRAY()
FROM workspaces w
LEFT JOIN workspace_memberships wm ON wm.workspace_id = w.id
GROUP BY w.id
ON DUPLICATE KEY UPDATE workspace_id = VALUES(workspace_id);
