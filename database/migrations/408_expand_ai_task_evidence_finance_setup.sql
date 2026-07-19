ALTER TABLE ai_task_evidence
    MODIFY COLUMN evidence_type ENUM(
        'email_reply_received',
        'deal_stage_reached',
        'invoice_paid',
        'workflow_step_completed',
        'contact_updated',
        'task_dependency_completed',
        'manual_confirmation',
        'finance_setup_ready'
    ) NOT NULL,
    MODIFY COLUMN entity_type ENUM(
        'communication',
        'deal',
        'invoice',
        'workflow_execution',
        'task',
        'contact',
        'workspace_skill'
    ) NOT NULL;
