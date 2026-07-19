ALTER TABLE workspace_launch_settings
    MODIFY COLUMN active_package VARCHAR(50) NOT NULL DEFAULT 'launch',
    MODIFY COLUMN target_niche VARCHAR(80) NOT NULL DEFAULT 'solo_founders',
    MODIFY COLUMN launch_model VARCHAR(80) NOT NULL DEFAULT 'service_led_saas',
    MODIFY COLUMN success_milestone VARCHAR(80) NOT NULL DEFAULT 'first_paid_signal';

UPDATE workspace_launch_settings
SET active_package = 'launch',
    target_niche = 'solo_founders',
    launch_model = 'service_led_saas',
    success_milestone = 'first_paid_signal',
    notes = COALESCE(notes, 'Default launchpad repositioned to the AI Cofounder founder-launch path.')
WHERE id = 1
  AND active_package = 'core'
  AND target_niche = 'interiors_contractors'
  AND launch_model = 'service_led_saas'
  AND success_milestone = 'first_5_customers';

INSERT INTO workspace_launch_settings (id, active_package, target_niche, launch_model, success_milestone, notes)
SELECT 1,
       'launch',
       'solo_founders',
       'service_led_saas',
       'first_paid_signal',
       'Default launchpad initialized to the AI Cofounder founder-launch path.'
WHERE NOT EXISTS (
    SELECT 1 FROM workspace_launch_settings WHERE id = 1
);
