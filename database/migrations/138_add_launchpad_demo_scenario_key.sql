ALTER TABLE workspace_launch_settings
    ADD COLUMN IF NOT EXISTS demo_scenario_key VARCHAR(120) NULL AFTER success_milestone;
