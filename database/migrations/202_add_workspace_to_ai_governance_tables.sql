ALTER TABLE ai_prompt_registry
    ADD COLUMN IF NOT EXISTS workspace_id INT NULL AFTER id;

ALTER TABLE ai_guidance_runs
    ADD COLUMN IF NOT EXISTS workspace_id INT NULL AFTER id;

ALTER TABLE ai_advice_feedback
    ADD COLUMN IF NOT EXISTS workspace_id INT NULL AFTER id;

ALTER TABLE ai_decision_outcomes
    ADD COLUMN IF NOT EXISTS workspace_id INT NULL AFTER id;

ALTER TABLE ai_threshold_tuning_log
    ADD COLUMN IF NOT EXISTS workspace_id INT NULL AFTER id;

ALTER TABLE ai_capability_state_log
    ADD COLUMN IF NOT EXISTS workspace_id INT NULL AFTER id;

ALTER TABLE ai_task_evidence
    ADD COLUMN IF NOT EXISTS workspace_id INT NULL AFTER id;

ALTER TABLE ai_runtime_controls
    ADD COLUMN IF NOT EXISTS workspace_id INT NULL AFTER id;

ALTER TABLE ai_runtime_control_log
    ADD COLUMN IF NOT EXISTS workspace_id INT NULL AFTER id;

ALTER TABLE ai_autonomy_domain_controls
    ADD COLUMN IF NOT EXISTS workspace_id INT NULL AFTER id;

ALTER TABLE ai_autonomy_eval_runs
    ADD COLUMN IF NOT EXISTS workspace_id INT NULL AFTER id;

ALTER TABLE ai_autonomy_incidents
    ADD COLUMN IF NOT EXISTS workspace_id INT NULL AFTER id;

ALTER TABLE ai_autonomy_recovery_queue
    ADD COLUMN IF NOT EXISTS workspace_id INT NULL AFTER id;

ALTER TABLE ai_autonomy_operator_actions
    ADD COLUMN IF NOT EXISTS workspace_id INT NULL AFTER id;

ALTER TABLE ai_operator_demonstrations
    ADD COLUMN IF NOT EXISTS workspace_id INT NULL AFTER id;

ALTER TABLE ai_tenant_policy_memory
    ADD COLUMN IF NOT EXISTS workspace_id INT NULL AFTER id;

ALTER TABLE ai_action_similarity_index
    ADD COLUMN IF NOT EXISTS workspace_id INT NULL AFTER id;

SET @default_workspace_id := COALESCE((SELECT MIN(id) FROM workspaces), 1);

UPDATE ai_guidance_runs gr
LEFT JOIN workspace_memberships wm
    ON wm.user_id = gr.user_id
   AND wm.membership_status = 'active'
SET gr.workspace_id = COALESCE(gr.workspace_id, wm.workspace_id, @default_workspace_id)
WHERE gr.workspace_id IS NULL;

UPDATE ai_advice_feedback af
LEFT JOIN ai_guidance_runs gr ON gr.id = af.guidance_run_id
LEFT JOIN workspace_memberships wm
    ON wm.user_id = af.user_id
   AND wm.membership_status = 'active'
SET af.workspace_id = COALESCE(af.workspace_id, gr.workspace_id, wm.workspace_id, @default_workspace_id)
WHERE af.workspace_id IS NULL;

UPDATE ai_capability_state_log csl
LEFT JOIN workspace_memberships wm
    ON wm.user_id = csl.user_id
   AND wm.membership_status = 'active'
SET csl.workspace_id = COALESCE(csl.workspace_id, wm.workspace_id, @default_workspace_id)
WHERE csl.workspace_id IS NULL;

UPDATE ai_task_evidence te
INNER JOIN tasks t ON t.id = te.task_id
SET te.workspace_id = COALESCE(te.workspace_id, t.workspace_id, @default_workspace_id)
WHERE te.workspace_id IS NULL;

UPDATE ai_decision_outcomes ado
LEFT JOIN ai_guidance_runs gr ON gr.id = ado.guidance_run_id
LEFT JOIN tasks t ON t.id = ado.task_id
SET ado.workspace_id = COALESCE(
    ado.workspace_id,
    gr.workspace_id,
    t.workspace_id,
    @default_workspace_id
)
WHERE ado.workspace_id IS NULL;

UPDATE ai_threshold_tuning_log
SET workspace_id = @default_workspace_id
WHERE workspace_id IS NULL;

UPDATE ai_runtime_controls
SET workspace_id = @default_workspace_id
WHERE workspace_id IS NULL;

UPDATE ai_runtime_control_log
SET workspace_id = @default_workspace_id
WHERE workspace_id IS NULL;

UPDATE ai_autonomy_domain_controls adc
LEFT JOIN contacts c
    ON adc.tenant_key REGEXP '^contact:[0-9]+$'
   AND c.id = CAST(SUBSTRING_INDEX(adc.tenant_key, ':', -1) AS UNSIGNED)
LEFT JOIN companies co
    ON adc.tenant_key REGEXP '^company:[0-9]+$'
   AND co.id = CAST(SUBSTRING_INDEX(adc.tenant_key, ':', -1) AS UNSIGNED)
LEFT JOIN deals d
    ON adc.tenant_key REGEXP '^deal:[0-9]+$'
   AND d.id = CAST(SUBSTRING_INDEX(adc.tenant_key, ':', -1) AS UNSIGNED)
LEFT JOIN tasks tt
    ON adc.tenant_key REGEXP '^task:[0-9]+$'
   AND tt.id = CAST(SUBSTRING_INDEX(adc.tenant_key, ':', -1) AS UNSIGNED)
LEFT JOIN workspace_memberships wm
    ON adc.tenant_key REGEXP '^user:[0-9]+$'
   AND wm.user_id = CAST(SUBSTRING_INDEX(adc.tenant_key, ':', -1) AS UNSIGNED)
   AND wm.membership_status = 'active'
SET adc.workspace_id = COALESCE(
    adc.workspace_id,
    CASE
        WHEN adc.tenant_key REGEXP '^workspace:[0-9]+$' THEN CAST(SUBSTRING_INDEX(adc.tenant_key, ':', -1) AS UNSIGNED)
        ELSE NULL
    END,
    c.workspace_id,
    co.workspace_id,
    d.workspace_id,
    tt.workspace_id,
    wm.workspace_id,
    @default_workspace_id
)
WHERE adc.workspace_id IS NULL;

UPDATE ai_autonomy_eval_runs aer
LEFT JOIN contacts c
    ON aer.tenant_key REGEXP '^contact:[0-9]+$'
   AND c.id = CAST(SUBSTRING_INDEX(aer.tenant_key, ':', -1) AS UNSIGNED)
LEFT JOIN companies co
    ON aer.tenant_key REGEXP '^company:[0-9]+$'
   AND co.id = CAST(SUBSTRING_INDEX(aer.tenant_key, ':', -1) AS UNSIGNED)
LEFT JOIN deals d
    ON aer.tenant_key REGEXP '^deal:[0-9]+$'
   AND d.id = CAST(SUBSTRING_INDEX(aer.tenant_key, ':', -1) AS UNSIGNED)
LEFT JOIN tasks tt
    ON aer.tenant_key REGEXP '^task:[0-9]+$'
   AND tt.id = CAST(SUBSTRING_INDEX(aer.tenant_key, ':', -1) AS UNSIGNED)
LEFT JOIN workspace_memberships wm
    ON aer.tenant_key REGEXP '^user:[0-9]+$'
   AND wm.user_id = CAST(SUBSTRING_INDEX(aer.tenant_key, ':', -1) AS UNSIGNED)
   AND wm.membership_status = 'active'
SET aer.workspace_id = COALESCE(
    aer.workspace_id,
    CASE
        WHEN aer.tenant_key REGEXP '^workspace:[0-9]+$' THEN CAST(SUBSTRING_INDEX(aer.tenant_key, ':', -1) AS UNSIGNED)
        ELSE NULL
    END,
    c.workspace_id,
    co.workspace_id,
    d.workspace_id,
    tt.workspace_id,
    wm.workspace_id,
    @default_workspace_id
)
WHERE aer.workspace_id IS NULL;

UPDATE ai_autonomy_incidents aii
LEFT JOIN contacts c
    ON aii.tenant_key REGEXP '^contact:[0-9]+$'
   AND c.id = CAST(SUBSTRING_INDEX(aii.tenant_key, ':', -1) AS UNSIGNED)
LEFT JOIN companies co
    ON aii.tenant_key REGEXP '^company:[0-9]+$'
   AND co.id = CAST(SUBSTRING_INDEX(aii.tenant_key, ':', -1) AS UNSIGNED)
LEFT JOIN deals d
    ON aii.tenant_key REGEXP '^deal:[0-9]+$'
   AND d.id = CAST(SUBSTRING_INDEX(aii.tenant_key, ':', -1) AS UNSIGNED)
LEFT JOIN tasks tt
    ON aii.tenant_key REGEXP '^task:[0-9]+$'
   AND tt.id = CAST(SUBSTRING_INDEX(aii.tenant_key, ':', -1) AS UNSIGNED)
LEFT JOIN workspace_memberships wm
    ON aii.tenant_key REGEXP '^user:[0-9]+$'
   AND wm.user_id = CAST(SUBSTRING_INDEX(aii.tenant_key, ':', -1) AS UNSIGNED)
   AND wm.membership_status = 'active'
SET aii.workspace_id = COALESCE(
    aii.workspace_id,
    CASE
        WHEN aii.tenant_key REGEXP '^workspace:[0-9]+$' THEN CAST(SUBSTRING_INDEX(aii.tenant_key, ':', -1) AS UNSIGNED)
        ELSE NULL
    END,
    c.workspace_id,
    co.workspace_id,
    d.workspace_id,
    tt.workspace_id,
    wm.workspace_id,
    @default_workspace_id
)
WHERE aii.workspace_id IS NULL;

UPDATE ai_autonomy_recovery_queue arq
LEFT JOIN ai_autonomy_incidents aii ON aii.id = arq.incident_id
SET arq.workspace_id = COALESCE(arq.workspace_id, aii.workspace_id, @default_workspace_id)
WHERE arq.workspace_id IS NULL;

UPDATE ai_autonomy_operator_actions aoa
LEFT JOIN ai_autonomy_incidents aii ON aii.id = aoa.incident_id
LEFT JOIN ai_autonomy_recovery_queue arq ON arq.id = aoa.recovery_queue_id
SET aoa.workspace_id = COALESCE(aoa.workspace_id, aii.workspace_id, arq.workspace_id, @default_workspace_id)
WHERE aoa.workspace_id IS NULL;

UPDATE ai_operator_demonstrations aod
LEFT JOIN tasks tt ON tt.id = aod.linked_task_id
LEFT JOIN contacts c
    ON aod.tenant_key REGEXP '^contact:[0-9]+$'
   AND c.id = CAST(SUBSTRING_INDEX(aod.tenant_key, ':', -1) AS UNSIGNED)
LEFT JOIN companies co
    ON aod.tenant_key REGEXP '^company:[0-9]+$'
   AND co.id = CAST(SUBSTRING_INDEX(aod.tenant_key, ':', -1) AS UNSIGNED)
LEFT JOIN deals d
    ON aod.tenant_key REGEXP '^deal:[0-9]+$'
   AND d.id = CAST(SUBSTRING_INDEX(aod.tenant_key, ':', -1) AS UNSIGNED)
LEFT JOIN workspace_memberships wm
    ON aod.tenant_key REGEXP '^user:[0-9]+$'
   AND wm.user_id = CAST(SUBSTRING_INDEX(aod.tenant_key, ':', -1) AS UNSIGNED)
   AND wm.membership_status = 'active'
SET aod.workspace_id = COALESCE(
    aod.workspace_id,
    CASE
        WHEN aod.tenant_key REGEXP '^workspace:[0-9]+$' THEN CAST(SUBSTRING_INDEX(aod.tenant_key, ':', -1) AS UNSIGNED)
        ELSE NULL
    END,
    tt.workspace_id,
    c.workspace_id,
    co.workspace_id,
    d.workspace_id,
    wm.workspace_id,
    @default_workspace_id
)
WHERE aod.workspace_id IS NULL;

UPDATE ai_tenant_policy_memory atpm
LEFT JOIN contacts c
    ON atpm.tenant_key REGEXP '^contact:[0-9]+$'
   AND c.id = CAST(SUBSTRING_INDEX(atpm.tenant_key, ':', -1) AS UNSIGNED)
LEFT JOIN companies co
    ON atpm.tenant_key REGEXP '^company:[0-9]+$'
   AND co.id = CAST(SUBSTRING_INDEX(atpm.tenant_key, ':', -1) AS UNSIGNED)
LEFT JOIN deals d
    ON atpm.tenant_key REGEXP '^deal:[0-9]+$'
   AND d.id = CAST(SUBSTRING_INDEX(atpm.tenant_key, ':', -1) AS UNSIGNED)
LEFT JOIN tasks tt
    ON atpm.tenant_key REGEXP '^task:[0-9]+$'
   AND tt.id = CAST(SUBSTRING_INDEX(atpm.tenant_key, ':', -1) AS UNSIGNED)
LEFT JOIN workspace_memberships wm
    ON atpm.tenant_key REGEXP '^user:[0-9]+$'
   AND wm.user_id = CAST(SUBSTRING_INDEX(atpm.tenant_key, ':', -1) AS UNSIGNED)
   AND wm.membership_status = 'active'
SET atpm.workspace_id = COALESCE(
    atpm.workspace_id,
    CASE
        WHEN atpm.tenant_key REGEXP '^workspace:[0-9]+$' THEN CAST(SUBSTRING_INDEX(atpm.tenant_key, ':', -1) AS UNSIGNED)
        ELSE NULL
    END,
    c.workspace_id,
    co.workspace_id,
    d.workspace_id,
    tt.workspace_id,
    wm.workspace_id,
    @default_workspace_id
)
WHERE atpm.workspace_id IS NULL;

UPDATE ai_action_similarity_index aasi
LEFT JOIN ai_operator_demonstrations aod ON aod.id = aasi.demonstration_id
SET aasi.workspace_id = COALESCE(aasi.workspace_id, aod.workspace_id, @default_workspace_id)
WHERE aasi.workspace_id IS NULL;

UPDATE ai_guidance_runs
SET workspace_id = @default_workspace_id
WHERE workspace_id IS NULL;

UPDATE ai_advice_feedback
SET workspace_id = @default_workspace_id
WHERE workspace_id IS NULL;

UPDATE ai_decision_outcomes
SET workspace_id = @default_workspace_id
WHERE workspace_id IS NULL;

UPDATE ai_threshold_tuning_log
SET workspace_id = @default_workspace_id
WHERE workspace_id IS NULL;

UPDATE ai_capability_state_log
SET workspace_id = @default_workspace_id
WHERE workspace_id IS NULL;

UPDATE ai_task_evidence
SET workspace_id = @default_workspace_id
WHERE workspace_id IS NULL;

UPDATE ai_runtime_controls
SET workspace_id = @default_workspace_id
WHERE workspace_id IS NULL;

UPDATE ai_runtime_control_log
SET workspace_id = @default_workspace_id
WHERE workspace_id IS NULL;

UPDATE ai_autonomy_domain_controls
SET workspace_id = @default_workspace_id
WHERE workspace_id IS NULL;

UPDATE ai_autonomy_eval_runs
SET workspace_id = @default_workspace_id
WHERE workspace_id IS NULL;

UPDATE ai_autonomy_incidents
SET workspace_id = @default_workspace_id
WHERE workspace_id IS NULL;

UPDATE ai_autonomy_recovery_queue
SET workspace_id = @default_workspace_id
WHERE workspace_id IS NULL;

UPDATE ai_autonomy_operator_actions
SET workspace_id = @default_workspace_id
WHERE workspace_id IS NULL;

UPDATE ai_operator_demonstrations
SET workspace_id = @default_workspace_id
WHERE workspace_id IS NULL;

UPDATE ai_tenant_policy_memory
SET workspace_id = @default_workspace_id
WHERE workspace_id IS NULL;

UPDATE ai_action_similarity_index
SET workspace_id = @default_workspace_id
WHERE workspace_id IS NULL;

ALTER TABLE ai_guidance_runs
    MODIFY COLUMN workspace_id INT NOT NULL;

ALTER TABLE ai_advice_feedback
    MODIFY COLUMN workspace_id INT NOT NULL;

ALTER TABLE ai_decision_outcomes
    MODIFY COLUMN workspace_id INT NOT NULL;

ALTER TABLE ai_threshold_tuning_log
    MODIFY COLUMN workspace_id INT NOT NULL;

ALTER TABLE ai_capability_state_log
    MODIFY COLUMN workspace_id INT NOT NULL;

ALTER TABLE ai_task_evidence
    MODIFY COLUMN workspace_id INT NOT NULL;

ALTER TABLE ai_runtime_controls
    MODIFY COLUMN workspace_id INT NOT NULL;

ALTER TABLE ai_runtime_control_log
    MODIFY COLUMN workspace_id INT NOT NULL;

ALTER TABLE ai_autonomy_domain_controls
    MODIFY COLUMN workspace_id INT NOT NULL;

ALTER TABLE ai_autonomy_eval_runs
    MODIFY COLUMN workspace_id INT NOT NULL;

ALTER TABLE ai_autonomy_incidents
    MODIFY COLUMN workspace_id INT NOT NULL;

ALTER TABLE ai_autonomy_recovery_queue
    MODIFY COLUMN workspace_id INT NOT NULL;

ALTER TABLE ai_autonomy_operator_actions
    MODIFY COLUMN workspace_id INT NOT NULL;

ALTER TABLE ai_operator_demonstrations
    MODIFY COLUMN workspace_id INT NOT NULL;

ALTER TABLE ai_tenant_policy_memory
    MODIFY COLUMN workspace_id INT NOT NULL;

ALTER TABLE ai_action_similarity_index
    MODIFY COLUMN workspace_id INT NOT NULL;

SET @ai_prompt_registry_unique_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'ai_prompt_registry'
      AND index_name = 'uniq_surface_prompt_version'
);
SET @ai_prompt_registry_unique_sql := IF(
    @ai_prompt_registry_unique_exists > 0,
    'ALTER TABLE ai_prompt_registry DROP INDEX uniq_surface_prompt_version',
    'SELECT 1'
);
PREPARE stmt FROM @ai_prompt_registry_unique_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @ai_runtime_controls_unique_exists := (
    SELECT COUNT(*)
    FROM information_schema.statistics
    WHERE table_schema = DATABASE()
      AND table_name = 'ai_runtime_controls'
      AND index_name = 'uniq_ai_runtime_controls_surface'
);
SET @ai_runtime_controls_unique_sql := IF(
    @ai_runtime_controls_unique_exists > 0,
    'ALTER TABLE ai_runtime_controls DROP INDEX uniq_ai_runtime_controls_surface',
    'SELECT 1'
);
PREPARE stmt FROM @ai_runtime_controls_unique_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

ALTER TABLE ai_prompt_registry
    ADD KEY IF NOT EXISTS idx_ai_prompt_registry_workspace_surface_status (workspace_id, surface, prompt_key, status, version),
    ADD UNIQUE KEY IF NOT EXISTS uniq_ai_prompt_registry_workspace_version (workspace_id, surface, prompt_key, version);

ALTER TABLE ai_guidance_runs
    ADD KEY IF NOT EXISTS idx_ai_guidance_workspace_surface_created (workspace_id, surface, created_at);

ALTER TABLE ai_advice_feedback
    ADD KEY IF NOT EXISTS idx_ai_advice_feedback_workspace_surface_created (workspace_id, surface, created_at);

ALTER TABLE ai_decision_outcomes
    ADD KEY IF NOT EXISTS idx_ai_decision_outcomes_workspace_surface_action_measured (workspace_id, surface, action_type, measured_at);

ALTER TABLE ai_threshold_tuning_log
    ADD KEY IF NOT EXISTS idx_ai_threshold_tuning_workspace_key_created (workspace_id, threshold_key, created_at);

ALTER TABLE ai_capability_state_log
    ADD KEY IF NOT EXISTS idx_ai_capability_workspace_surface_created (workspace_id, surface, created_at);

ALTER TABLE ai_task_evidence
    ADD KEY IF NOT EXISTS idx_ai_task_evidence_workspace_task (workspace_id, task_id, created_at);

ALTER TABLE ai_runtime_controls
    ADD UNIQUE KEY IF NOT EXISTS uniq_ai_runtime_controls_workspace_surface (workspace_id, surface),
    ADD KEY IF NOT EXISTS idx_ai_runtime_controls_workspace_expires (workspace_id, expires_at);

ALTER TABLE ai_runtime_control_log
    ADD KEY IF NOT EXISTS idx_ai_runtime_control_log_workspace_surface_set_at (workspace_id, surface, set_at);

ALTER TABLE ai_autonomy_domain_controls
    ADD KEY IF NOT EXISTS idx_ai_autonomy_domain_controls_workspace_domain (workspace_id, domain_key, promotion_status);

ALTER TABLE ai_autonomy_eval_runs
    ADD KEY IF NOT EXISTS idx_ai_autonomy_eval_workspace_domain_started (workspace_id, domain_key, started_at);

ALTER TABLE ai_autonomy_incidents
    ADD KEY IF NOT EXISTS idx_ai_autonomy_incidents_workspace_scope (workspace_id, domain_key, status);

ALTER TABLE ai_autonomy_recovery_queue
    ADD KEY IF NOT EXISTS idx_ai_autonomy_recovery_workspace_scope (workspace_id, domain_key, status);

ALTER TABLE ai_autonomy_operator_actions
    ADD KEY IF NOT EXISTS idx_ai_autonomy_operator_actions_workspace_scope (workspace_id, domain_key, created_at);

ALTER TABLE ai_operator_demonstrations
    ADD KEY IF NOT EXISTS idx_ai_operator_demo_workspace_domain_action (workspace_id, domain_key, action_key, observed_at);

ALTER TABLE ai_tenant_policy_memory
    ADD KEY IF NOT EXISTS idx_ai_tenant_policy_workspace_scope (workspace_id, scope_key, last_observed_at);

ALTER TABLE ai_action_similarity_index
    ADD KEY IF NOT EXISTS idx_ai_action_similarity_workspace_lookup (workspace_id, domain_key, action_key, outcome_label);
