ALTER TABLE commercial_automation_runs
    ADD COLUMN IF NOT EXISTS workspace_id INT NOT NULL DEFAULT 1 AFTER id;

ALTER TABLE commercial_automation_approvals
    ADD COLUMN IF NOT EXISTS workspace_id INT NOT NULL DEFAULT 1 AFTER id;

ALTER TABLE email_assistant_runs
    ADD COLUMN IF NOT EXISTS workspace_id INT NOT NULL DEFAULT 1 AFTER id;

ALTER TABLE email_assistant_resolutions
    ADD COLUMN IF NOT EXISTS workspace_id INT NOT NULL DEFAULT 1 AFTER id;

ALTER TABLE email_assistant_action_queue
    ADD COLUMN IF NOT EXISTS workspace_id INT NOT NULL DEFAULT 1 AFTER id;

SET @default_workspace_id := COALESCE((SELECT MIN(id) FROM workspaces), 1);

UPDATE commercial_automation_runs car
LEFT JOIN deals d
    ON d.id = car.deal_id
LEFT JOIN contacts c
    ON c.id = car.contact_id
LEFT JOIN invoices i
    ON i.id = car.invoice_id
SET car.workspace_id = COALESCE(
    NULLIF(car.workspace_id, 0),
    d.workspace_id,
    c.workspace_id,
    i.workspace_id,
    @default_workspace_id
);

UPDATE commercial_automation_approvals ca
LEFT JOIN deals d
    ON d.id = ca.deal_id
LEFT JOIN invoices i
    ON i.id = ca.invoice_id
LEFT JOIN workspace_memberships wm
    ON wm.user_id = ca.requested_by_id
   AND wm.membership_status = 'active'
SET ca.workspace_id = COALESCE(
    NULLIF(ca.workspace_id, 0),
    d.workspace_id,
    i.workspace_id,
    wm.workspace_id,
    @default_workspace_id
);

UPDATE email_assistant_runs ear
LEFT JOIN contacts c
    ON c.id = ear.contact_id
LEFT JOIN deals d
    ON d.id = ear.deal_id
LEFT JOIN invoices i
    ON i.id = ear.invoice_id
LEFT JOIN workspace_memberships wm
    ON wm.user_id = ear.user_id
   AND wm.membership_status = 'active'
SET ear.workspace_id = COALESCE(
    NULLIF(ear.workspace_id, 0),
    c.workspace_id,
    d.workspace_id,
    i.workspace_id,
    wm.workspace_id,
    @default_workspace_id
);

UPDATE email_assistant_resolutions ear
INNER JOIN email_assistant_runs runs
    ON runs.id = ear.run_id
SET ear.workspace_id = COALESCE(
    NULLIF(ear.workspace_id, 0),
    runs.workspace_id,
    @default_workspace_id
);

UPDATE email_assistant_action_queue eaaq
INNER JOIN email_assistant_runs runs
    ON runs.id = eaaq.run_id
SET eaaq.workspace_id = COALESCE(
    NULLIF(eaaq.workspace_id, 0),
    runs.workspace_id,
    @default_workspace_id
);

ALTER TABLE commercial_automation_runs
    ADD KEY IF NOT EXISTS idx_commercial_automation_runs_workspace_created (workspace_id, created_at),
    ADD KEY IF NOT EXISTS idx_commercial_automation_runs_workspace_decision (workspace_id, decision, created_at),
    ADD KEY IF NOT EXISTS idx_commercial_automation_runs_workspace_deal (workspace_id, deal_id);

ALTER TABLE commercial_automation_approvals
    ADD KEY IF NOT EXISTS idx_commercial_automation_approvals_workspace_status (workspace_id, status, created_at),
    ADD KEY IF NOT EXISTS idx_commercial_automation_approvals_workspace_action (workspace_id, action_key, created_at),
    ADD KEY IF NOT EXISTS idx_commercial_automation_approvals_workspace_deal (workspace_id, deal_id),
    ADD KEY IF NOT EXISTS idx_commercial_automation_approvals_workspace_invoice (workspace_id, invoice_id);

ALTER TABLE email_assistant_runs
    ADD KEY IF NOT EXISTS idx_email_assistant_runs_workspace_created (workspace_id, created_at),
    ADD KEY IF NOT EXISTS idx_email_assistant_runs_workspace_source (workspace_id, source, created_at),
    ADD KEY IF NOT EXISTS idx_email_assistant_runs_workspace_contact (workspace_id, contact_id),
    ADD KEY IF NOT EXISTS idx_email_assistant_runs_workspace_deal (workspace_id, deal_id);

ALTER TABLE email_assistant_resolutions
    ADD KEY IF NOT EXISTS idx_email_assistant_resolutions_workspace_run (workspace_id, run_id),
    ADD KEY IF NOT EXISTS idx_email_assistant_resolutions_workspace_entity (workspace_id, entity_type, resolved_id);

ALTER TABLE email_assistant_action_queue
    ADD KEY IF NOT EXISTS idx_email_assistant_action_queue_workspace_status (workspace_id, status, scheduled_at),
    ADD KEY IF NOT EXISTS idx_email_assistant_action_queue_workspace_run (workspace_id, run_id);
