<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\AIAutonomyDomainControlService;
use CRM\Tests\DatabaseTestCase;

class AIAutonomyDomainControlServiceTest extends DatabaseTestCase
{
    public function testSaveAndLoadDomainControl(): void
    {
        $service = new AIAutonomyDomainControlService();
        $service->save('contact:10', 'customer_thread', [
            'autonomy_mode' => 'auto_safe',
            'promotion_status' => 'auto_safe',
            'demonstration_capture_enabled' => true,
            'policy_learning_enabled' => true,
            'review_ui_enabled' => true,
            'fast_promotion_enabled' => false,
            'auto_downgrade_on_drift' => false,
            'metadata' => [
                'allowed_actions' => ['send_customer_reply'],
                'max_daily_auto_actions' => 5,
                'manual_freeze' => true,
                'temporary_daily_auto_action_cap' => 2,
            ],
        ], null);

        $control = $service->get('contact:10', 'customer_thread');

        $this->assertSame('auto_safe', $control['autonomy_mode']);
        $this->assertSame('auto_safe', $control['promotion_status']);
        $this->assertFalse($control['fast_promotion_enabled']);
        $this->assertFalse($control['auto_downgrade_on_drift']);
        $this->assertSame(['send_customer_reply'], $control['metadata']['allowed_actions']);
        $this->assertSame(5, $control['metadata']['max_daily_auto_actions']);
        $this->assertTrue($control['metadata']['manual_freeze']);
        $this->assertSame(2, $control['metadata']['temporary_daily_auto_action_cap']);
    }

    public function testWorkflowExecutionUsesDomainSpecificDefaults(): void
    {
        $service = new AIAutonomyDomainControlService();
        $control = $service->get('contact:999', 'workflow_execution');

        $this->assertSame('auto_safe', $control['autonomy_mode']);
        $this->assertSame('auto_safe', $control['promotion_status']);
    }

    public function testCustomerCareUsesSafeDomainSpecificDefaults(): void
    {
        $service = new AIAutonomyDomainControlService();
        $control = $service->get('contact:999', 'customer_care');

        $this->assertSame('suggest_only', $control['autonomy_mode']);
        $this->assertSame('suggest_only', $control['promotion_status']);
        $this->assertSame([
            'suggest_check_in',
            'create_check_in_task',
            'schedule_check_in',
            'enroll_follow_up_plan',
            'record_check_in',
            'draft_customer_reply',
        ], $control['metadata']['allowed_actions']);
        $this->assertSame(['draft_customer_reply'], $control['metadata']['require_human_checkpoint_actions']);
        $this->assertTrue($control['metadata']['block_customer_facing_full_auto']);
        $this->assertSame(25, $control['metadata']['max_daily_auto_actions']);
        $this->assertSame(20, $control['metadata']['min_sample_size_to_promote']);
    }

    public function testWorkflowExecutionFallsBackToGlobalWorkspaceControl(): void
    {
        $service = new AIAutonomyDomainControlService();
        $service->save('global:default', 'workflow_execution', [
            'autonomy_mode' => 'full_auto',
            'promotion_status' => 'full_auto',
            'metadata' => [
                'allowed_actions' => ['create_task'],
                'max_daily_auto_actions' => 12,
            ],
        ], null);

        $control = $service->get('contact:42', 'workflow_execution');

        $this->assertSame('workspace:1', $control['tenant_key']);
        $this->assertSame(1, (int) $control['workspace_id']);
        $this->assertSame('full_auto', $control['autonomy_mode']);
        $this->assertSame(['create_task'], $control['metadata']['allowed_actions']);
        $this->assertSame(12, $control['metadata']['max_daily_auto_actions']);
    }

    public function testWorkflowExecutionTenantOverrideStaysWorkspaceBoundWithoutGlobalMerge(): void
    {
        $service = new AIAutonomyDomainControlService();
        $service->save('contact:77', 'workflow_execution', [
            'autonomy_mode' => 'auto_safe',
            'promotion_status' => 'auto_safe',
            'metadata' => [
                'allowed_actions' => ['send_email', 'call_webhook'],
                'require_human_checkpoint_actions' => ['call_webhook'],
                'max_daily_auto_actions' => 8,
                'max_customer_facing_risk' => 0.65,
            ],
        ], null);

        $control = $service->get('contact:77', 'workflow_execution');

        $this->assertSame('auto_safe', $control['autonomy_mode']);
        $this->assertSame('auto_safe', $control['promotion_status']);
        $this->assertSame(['send_email', 'call_webhook'], $control['metadata']['allowed_actions']);
        $this->assertSame(['call_webhook'], $control['metadata']['require_human_checkpoint_actions']);
        $this->assertSame(8, $control['metadata']['max_daily_auto_actions']);
        $this->assertSame(0.65, $control['metadata']['max_customer_facing_risk']);
        $this->assertFalse($control['metadata']['block_customer_facing_full_auto']);
        $this->assertSame('workspace:1', $control['tenant_key']);
    }
}
