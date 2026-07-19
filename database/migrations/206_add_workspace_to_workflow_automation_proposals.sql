ALTER TABLE workflow_automation_proposals
    ADD COLUMN workspace_id INT NOT NULL DEFAULT 1 AFTER id,
    ADD KEY idx_workflow_automation_proposals_workspace_status (workspace_id, status, created_at),
    ADD KEY idx_workflow_automation_proposals_workspace_requested (workspace_id, requested_by_id, created_at),
    ADD KEY idx_workflow_automation_proposals_workspace_workflow (workspace_id, workflow_id, proposal_type);

UPDATE workflow_automation_proposals p
LEFT JOIN workflows w ON w.id = p.workflow_id
LEFT JOIN workflows aw ON aw.id = p.applied_workflow_id
LEFT JOIN (
    SELECT user_id, MIN(workspace_id) AS workspace_id
    FROM workspace_memberships
    WHERE membership_status = 'active'
    GROUP BY user_id
) wm ON wm.user_id = p.requested_by_id
SET p.workspace_id = COALESCE(w.workspace_id, aw.workspace_id, wm.workspace_id, p.workspace_id, 1)
WHERE p.workspace_id IS NULL
   OR p.workspace_id <> COALESCE(w.workspace_id, aw.workspace_id, wm.workspace_id, p.workspace_id, 1);
