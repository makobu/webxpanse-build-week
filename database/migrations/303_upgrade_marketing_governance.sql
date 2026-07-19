-- Marketing Phase 8: workflow governance and approval workbench fields.

ALTER TABLE marketing_content_items
    ADD COLUMN IF NOT EXISTS reviewer_user_id INT NULL AFTER owner_user_id,
    ADD COLUMN IF NOT EXISTS review_due_at DATETIME NULL AFTER reviewer_user_id,
    ADD COLUMN IF NOT EXISTS blocked_reason TEXT NULL AFTER review_due_at,
    ADD COLUMN IF NOT EXISTS readiness_score INT NULL AFTER blocked_reason,
    ADD COLUMN IF NOT EXISTS approval_checklist_json JSON NULL AFTER readiness_score,
    ADD COLUMN IF NOT EXISTS required_context_warnings_json JSON NULL AFTER approval_checklist_json,
    ADD INDEX IF NOT EXISTS idx_marketing_content_workspace_reviewer (workspace_id, reviewer_user_id),
    ADD INDEX IF NOT EXISTS idx_marketing_content_workspace_review_due (workspace_id, review_due_at),
    ADD INDEX IF NOT EXISTS idx_marketing_content_workspace_readiness (workspace_id, readiness_score),
    ADD CONSTRAINT fk_marketing_content_reviewer FOREIGN KEY IF NOT EXISTS (reviewer_user_id) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE marketing_content_approvals
    ADD COLUMN IF NOT EXISTS reviewer_user_id INT NULL AFTER requested_by,
    ADD COLUMN IF NOT EXISTS review_due_at DATETIME NULL AFTER reviewer_user_id,
    ADD INDEX IF NOT EXISTS idx_marketing_approvals_workspace_reviewer (workspace_id, reviewer_user_id, status),
    ADD INDEX IF NOT EXISTS idx_marketing_approvals_workspace_due (workspace_id, review_due_at, status),
    ADD CONSTRAINT fk_marketing_approvals_reviewer FOREIGN KEY IF NOT EXISTS (reviewer_user_id) REFERENCES users(id) ON DELETE SET NULL;
