DELETE FROM workspace_skill_installs
WHERE skill_key = 'communication_setup';

DELETE FROM workspace_skill_definitions
WHERE skill_key = 'communication_setup';

DELETE FROM workspace_skill_catalog_overrides
WHERE skill_key = 'communication_setup';

DELETE FROM workspace_plugin_capabilities
WHERE skill_key = 'communication_setup';

DELETE FROM target_metric_providers
WHERE skill_key = 'communication_setup';

DELETE FROM workspace_skill_events
WHERE skill_key = 'communication_setup';

DELETE FROM workspace_marketplace_recommendation_feedback
WHERE skill_key = 'communication_setup';

DELETE FROM workspace_marketplace_recommendation_events
WHERE skill_key = 'communication_setup';

DELETE FROM workspace_marketplace_recommendation_controls
WHERE skill_key = 'communication_setup';

DELETE FROM workspace_marketplace_setup_journey_steps
WHERE skill_key = 'communication_setup';

DELETE FROM workspace_marketplace_setup_journey_events
WHERE skill_key = 'communication_setup';

DELETE FROM workspace_plugin_runtime_events
WHERE skill_key = 'communication_setup';
