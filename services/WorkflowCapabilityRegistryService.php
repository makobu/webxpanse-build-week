<?php

namespace CRM\Services;

class WorkflowCapabilityRegistryService
{
    private const BUILT_IN_ACTIONS = [
        'send_email' => ['customer_facing' => true, 'requires_approval' => true, 'default_confidence_threshold' => 0.91, 'risk_level' => 'high'],
        'send_whatsapp' => ['customer_facing' => true, 'requires_approval' => true, 'default_confidence_threshold' => 0.89, 'risk_level' => 'high'],
        'send_sms' => ['customer_facing' => true, 'requires_approval' => true, 'default_confidence_threshold' => 0.88, 'risk_level' => 'high'],
        'add_tag' => ['customer_facing' => false, 'requires_approval' => false, 'default_confidence_threshold' => 0.8, 'risk_level' => 'low'],
        'remove_tag' => ['customer_facing' => false, 'requires_approval' => false, 'default_confidence_threshold' => 0.8, 'risk_level' => 'low'],
        'change_stage' => ['customer_facing' => false, 'requires_approval' => false, 'default_confidence_threshold' => 0.83, 'risk_level' => 'medium'],
        'create_task' => ['customer_facing' => false, 'requires_approval' => false, 'default_confidence_threshold' => 0.84, 'risk_level' => 'low'],
        'assign_to_user' => ['customer_facing' => false, 'requires_approval' => false, 'default_confidence_threshold' => 0.82, 'risk_level' => 'medium'],
        'wait_for_days' => ['customer_facing' => false, 'requires_approval' => false, 'default_confidence_threshold' => 0.99, 'risk_level' => 'low'],
        'update_contact_field' => ['customer_facing' => false, 'requires_approval' => false, 'default_confidence_threshold' => 0.86, 'risk_level' => 'medium'],
        'create_deal' => ['customer_facing' => false, 'requires_approval' => false, 'default_confidence_threshold' => 0.84, 'risk_level' => 'medium'],
        'update_deal_stage' => ['customer_facing' => false, 'requires_approval' => false, 'default_confidence_threshold' => 0.85, 'risk_level' => 'medium'],
        'add_to_deal' => ['customer_facing' => false, 'requires_approval' => false, 'default_confidence_threshold' => 0.84, 'risk_level' => 'medium'],
        'add_note' => ['customer_facing' => false, 'requires_approval' => false, 'default_confidence_threshold' => 0.78, 'risk_level' => 'low'],
        'create_activity' => ['customer_facing' => false, 'requires_approval' => false, 'default_confidence_threshold' => 0.78, 'risk_level' => 'low'],
        'update_lead_score' => ['customer_facing' => false, 'requires_approval' => false, 'default_confidence_threshold' => 0.82, 'risk_level' => 'medium'],
        'call_webhook' => ['customer_facing' => false, 'requires_approval' => true, 'default_confidence_threshold' => 0.8, 'risk_level' => 'high'],
        'apply_smart_tags' => ['customer_facing' => false, 'requires_approval' => false, 'default_confidence_threshold' => 0.76, 'risk_level' => 'low'],
        'send_in_app_notification' => ['customer_facing' => false, 'requires_approval' => false, 'default_confidence_threshold' => 0.76, 'risk_level' => 'low'],
        'remove_from_workflow' => ['customer_facing' => false, 'requires_approval' => false, 'default_confidence_threshold' => 0.7, 'risk_level' => 'medium'],
    ];

    private PluginRuntimeRegistryService $runtimeRegistry;

    public function __construct(?PluginRuntimeRegistryService $runtimeRegistry = null)
    {
        $this->runtimeRegistry = $runtimeRegistry ?? new PluginRuntimeRegistryService();
    }

    public function actionsForWorkspace(int $workspaceId = 0, int $userId = 0): array
    {
        $actions = array_keys(self::BUILT_IN_ACTIONS);
        if ($workspaceId > 0) {
            foreach ($this->runtimeRegistry->capabilitiesByType($workspaceId, PluginRuntimeRegistryService::TYPE_WORKFLOW_ACTION, $userId) as $capability) {
                $actions[] = (string) ($capability['capability_key'] ?? '');
            }
        }

        return array_values(array_unique(array_filter($actions)));
    }

    public function isActionAvailable(string $actionType, int $workspaceId = 0, int $userId = 0): bool
    {
        return $this->getActionCapability($actionType, $workspaceId, $userId) !== null;
    }

    public function getActionCapability(string $actionType, int $workspaceId = 0, int $userId = 0): ?array
    {
        $actionType = strtolower(trim($actionType));
        if (isset(self::BUILT_IN_ACTIONS[$actionType])) {
            return array_merge([
                'capability_key' => $actionType,
                'capability_type' => PluginRuntimeRegistryService::TYPE_WORKFLOW_ACTION,
                'skill_key' => 'core',
                'native' => true,
            ], self::BUILT_IN_ACTIONS[$actionType]);
        }

        if ($workspaceId <= 0) {
            return null;
        }

        return $this->runtimeRegistry->findCapability($workspaceId, $actionType, false, $userId);
    }

    public function governanceForAction(string $actionType, int $workspaceId = 0, int $userId = 0): array
    {
        $capability = $this->getActionCapability($actionType, $workspaceId, $userId);
        if (!$capability) {
            return ['customer_facing' => false, 'requires_approval' => true, 'default_confidence_threshold' => 0.84, 'risk_level' => 'unknown'];
        }

        $schema = (array) ($capability['schema'] ?? []);
        return [
            'customer_facing' => (bool) ($schema['customer_facing'] ?? $capability['customer_facing'] ?? false),
            'requires_approval' => (bool) ($schema['requires_approval'] ?? $capability['requires_approval'] ?? false),
            'default_confidence_threshold' => (float) ($schema['default_confidence_threshold'] ?? $capability['default_confidence_threshold'] ?? 0.84),
            'risk_level' => (string) ($schema['risk_level'] ?? $capability['risk_level'] ?? 'medium'),
        ];
    }
}
