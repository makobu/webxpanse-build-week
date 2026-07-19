-- Workflow template catalog cleanup.
-- Archive noisy public templates without deleting historical rows.

UPDATE workflow_templates wt
JOIN (
    SELECT name, MIN(id) AS canonical_id
    FROM workflow_templates
    WHERE name IN (
        'Welcome New Contacts',
        'Lead Nurturing Sequence',
        'Re-engagement Campaign',
        'Deal Follow-up',
        'Birthday Automation'
    )
    GROUP BY name
) canonical ON canonical.name = wt.name
SET wt.is_active = CASE WHEN wt.id = canonical.canonical_id THEN 1 ELSE 0 END,
    wt.is_public = CASE WHEN wt.id = canonical.canonical_id THEN 1 ELSE wt.is_public END
WHERE wt.name IN (
    'Welcome New Contacts',
    'Lead Nurturing Sequence',
    'Re-engagement Campaign',
    'Deal Follow-up',
    'Birthday Automation'
);

UPDATE workflow_templates
SET is_active = 0
WHERE is_public = 1
  AND name = 'Demo Escalation Template';

UPDATE workflow_templates
SET is_active = 0
WHERE is_public = 1
  AND name IN (
      'Invoice Payment Reminder',
      'Support Follow-up and Escalation',
      'Channel Setup Reminder'
  );

UPDATE workflow_templates
SET is_public = 0
WHERE category = 'platform_ops'
   OR name LIKE 'Platform Ops - %';
