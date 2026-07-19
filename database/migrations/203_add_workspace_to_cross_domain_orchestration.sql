ALTER TABLE ai_cross_domain_runs
    ADD COLUMN IF NOT EXISTS workspace_id INT NULL AFTER id;

ALTER TABLE ai_cross_domain_steps
    ADD COLUMN IF NOT EXISTS workspace_id INT NULL AFTER id;

ALTER TABLE ai_cross_domain_intake_events
    ADD COLUMN IF NOT EXISTS workspace_id INT NULL AFTER id;

SET @default_workspace_id := COALESCE((SELECT MIN(id) FROM workspaces), 1);

UPDATE ai_cross_domain_runs r
LEFT JOIN contacts tenant_contact
    ON r.tenant_key REGEXP '^contact:[0-9]+$'
   AND tenant_contact.id = CAST(SUBSTRING_INDEX(r.tenant_key, ':', -1) AS UNSIGNED)
LEFT JOIN companies tenant_company
    ON r.tenant_key REGEXP '^company:[0-9]+$'
   AND tenant_company.id = CAST(SUBSTRING_INDEX(r.tenant_key, ':', -1) AS UNSIGNED)
LEFT JOIN deals tenant_deal
    ON r.tenant_key REGEXP '^deal:[0-9]+$'
   AND tenant_deal.id = CAST(SUBSTRING_INDEX(r.tenant_key, ':', -1) AS UNSIGNED)
LEFT JOIN tasks tenant_task
    ON r.tenant_key REGEXP '^task:[0-9]+$'
   AND tenant_task.id = CAST(SUBSTRING_INDEX(r.tenant_key, ':', -1) AS UNSIGNED)
LEFT JOIN invoices tenant_invoice
    ON r.tenant_key REGEXP '^invoice:[0-9]+$'
   AND tenant_invoice.id = CAST(SUBSTRING_INDEX(r.tenant_key, ':', -1) AS UNSIGNED)
LEFT JOIN workflows tenant_workflow
    ON r.tenant_key REGEXP '^workflow:[0-9]+$'
   AND tenant_workflow.id = CAST(SUBSTRING_INDEX(r.tenant_key, ':', -1) AS UNSIGNED)
LEFT JOIN workspace_memberships tenant_user_membership
    ON r.tenant_key REGEXP '^user:[0-9]+$'
   AND tenant_user_membership.user_id = CAST(SUBSTRING_INDEX(r.tenant_key, ':', -1) AS UNSIGNED)
   AND tenant_user_membership.membership_status = 'active'
LEFT JOIN contacts primary_contact
    ON r.primary_entity_type = 'contact'
   AND primary_contact.id = r.primary_entity_id
LEFT JOIN deals primary_deal
    ON r.primary_entity_type = 'deal'
   AND primary_deal.id = r.primary_entity_id
LEFT JOIN tasks primary_task
    ON r.primary_entity_type = 'task'
   AND primary_task.id = r.primary_entity_id
LEFT JOIN invoices primary_invoice
    ON r.primary_entity_type = 'invoice'
   AND primary_invoice.id = r.primary_entity_id
LEFT JOIN workflows primary_workflow
    ON r.primary_entity_type = 'workflow'
   AND primary_workflow.id = r.primary_entity_id
SET r.workspace_id = COALESCE(
    r.workspace_id,
    CASE
        WHEN r.tenant_key REGEXP '^workspace:[0-9]+$' THEN CAST(SUBSTRING_INDEX(r.tenant_key, ':', -1) AS UNSIGNED)
        ELSE NULL
    END,
    tenant_contact.workspace_id,
    tenant_company.workspace_id,
    tenant_deal.workspace_id,
    tenant_task.workspace_id,
    tenant_invoice.workspace_id,
    tenant_workflow.workspace_id,
    tenant_user_membership.workspace_id,
    primary_contact.workspace_id,
    primary_deal.workspace_id,
    primary_task.workspace_id,
    primary_invoice.workspace_id,
    primary_workflow.workspace_id,
    @default_workspace_id
)
WHERE r.workspace_id IS NULL;

UPDATE ai_cross_domain_runs
SET workspace_id = @default_workspace_id
WHERE workspace_id IS NULL;

UPDATE ai_cross_domain_runs
SET tenant_key = CONCAT('workspace:', workspace_id)
WHERE workspace_id IS NOT NULL
  AND (tenant_key IS NULL OR tenant_key NOT REGEXP '^workspace:[0-9]+$');

UPDATE ai_cross_domain_steps s
INNER JOIN ai_cross_domain_runs r
    ON r.id = s.run_id
SET s.workspace_id = COALESCE(s.workspace_id, r.workspace_id)
WHERE s.workspace_id IS NULL;

UPDATE ai_cross_domain_steps
SET workspace_id = @default_workspace_id
WHERE workspace_id IS NULL;

UPDATE ai_cross_domain_intake_events e
LEFT JOIN ai_cross_domain_runs linked_run
    ON linked_run.id = e.linked_run_id
LEFT JOIN contacts tenant_contact
    ON e.tenant_key REGEXP '^contact:[0-9]+$'
   AND tenant_contact.id = CAST(SUBSTRING_INDEX(e.tenant_key, ':', -1) AS UNSIGNED)
LEFT JOIN companies tenant_company
    ON e.tenant_key REGEXP '^company:[0-9]+$'
   AND tenant_company.id = CAST(SUBSTRING_INDEX(e.tenant_key, ':', -1) AS UNSIGNED)
LEFT JOIN deals tenant_deal
    ON e.tenant_key REGEXP '^deal:[0-9]+$'
   AND tenant_deal.id = CAST(SUBSTRING_INDEX(e.tenant_key, ':', -1) AS UNSIGNED)
LEFT JOIN tasks tenant_task
    ON e.tenant_key REGEXP '^task:[0-9]+$'
   AND tenant_task.id = CAST(SUBSTRING_INDEX(e.tenant_key, ':', -1) AS UNSIGNED)
LEFT JOIN invoices tenant_invoice
    ON e.tenant_key REGEXP '^invoice:[0-9]+$'
   AND tenant_invoice.id = CAST(SUBSTRING_INDEX(e.tenant_key, ':', -1) AS UNSIGNED)
LEFT JOIN workflows tenant_workflow
    ON e.tenant_key REGEXP '^workflow:[0-9]+$'
   AND tenant_workflow.id = CAST(SUBSTRING_INDEX(e.tenant_key, ':', -1) AS UNSIGNED)
LEFT JOIN contacts trigger_contact
    ON e.trigger_entity_type = 'contact'
   AND trigger_contact.id = e.trigger_entity_id
LEFT JOIN deals trigger_deal
    ON e.trigger_entity_type = 'deal'
   AND trigger_deal.id = e.trigger_entity_id
LEFT JOIN tasks trigger_task
    ON e.trigger_entity_type = 'task'
   AND trigger_task.id = e.trigger_entity_id
LEFT JOIN invoices trigger_invoice
    ON e.trigger_entity_type = 'invoice'
   AND trigger_invoice.id = e.trigger_entity_id
LEFT JOIN workflows trigger_workflow
    ON e.trigger_entity_type = 'workflow'
   AND trigger_workflow.id = e.trigger_entity_id
SET e.workspace_id = COALESCE(
    e.workspace_id,
    linked_run.workspace_id,
    CASE
        WHEN e.tenant_key REGEXP '^workspace:[0-9]+$' THEN CAST(SUBSTRING_INDEX(e.tenant_key, ':', -1) AS UNSIGNED)
        ELSE NULL
    END,
    tenant_contact.workspace_id,
    tenant_company.workspace_id,
    tenant_deal.workspace_id,
    tenant_task.workspace_id,
    tenant_invoice.workspace_id,
    tenant_workflow.workspace_id,
    trigger_contact.workspace_id,
    trigger_deal.workspace_id,
    trigger_task.workspace_id,
    trigger_invoice.workspace_id,
    trigger_workflow.workspace_id,
    @default_workspace_id
)
WHERE e.workspace_id IS NULL;

UPDATE ai_cross_domain_intake_events
SET workspace_id = @default_workspace_id
WHERE workspace_id IS NULL;

UPDATE ai_cross_domain_intake_events
SET tenant_key = CONCAT('workspace:', workspace_id)
WHERE workspace_id IS NOT NULL
  AND (tenant_key IS NULL OR tenant_key NOT REGEXP '^workspace:[0-9]+$');

ALTER TABLE ai_cross_domain_runs
    MODIFY COLUMN workspace_id INT NOT NULL;

ALTER TABLE ai_cross_domain_steps
    MODIFY COLUMN workspace_id INT NOT NULL;

ALTER TABLE ai_cross_domain_intake_events
    MODIFY COLUMN workspace_id INT NOT NULL;

ALTER TABLE ai_cross_domain_runs
    ADD KEY IF NOT EXISTS idx_ai_cross_domain_runs_workspace_objective_status (workspace_id, objective_key, run_status),
    ADD KEY IF NOT EXISTS idx_ai_cross_domain_runs_workspace_created (workspace_id, created_at);

ALTER TABLE ai_cross_domain_steps
    ADD KEY IF NOT EXISTS idx_ai_cross_domain_steps_workspace_run (workspace_id, run_id, step_order),
    ADD KEY IF NOT EXISTS idx_ai_cross_domain_steps_workspace_status (workspace_id, step_status, domain_key);

ALTER TABLE ai_cross_domain_intake_events
    ADD KEY IF NOT EXISTS idx_ai_cross_domain_intake_workspace_source_trigger (workspace_id, source_domain, trigger_key, created_at),
    ADD KEY IF NOT EXISTS idx_ai_cross_domain_intake_workspace_decision (workspace_id, intake_decision, created_at);
