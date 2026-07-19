<?php

namespace CRM\Tests\Unit\Api;

use PHPUnit\Framework\TestCase;

class MobileDecisionCenterApiContractTest extends TestCase
{
    public function testApprovalQueuesUseExistingGovernedServices(): void
    {
        $source = $this->readDecisionSource('approvals.php');

        $this->assertStringContainsString('CommercialAutomationApprovalService', $source);
        $this->assertStringContainsString('executeApprovedAction', $source);
        $this->assertStringContainsString('WorkflowAutomationProposalService', $source);
        $this->assertStringContainsString('applyProposal', $source);
        $this->assertStringContainsString("mobileDecisionCan('commercial_automation.approvals')", $source);
        $this->assertStringContainsString("mobileDecisionCan('workflow_automation.approvals')", $source);
        $this->assertStringNotContainsString('payload_json', $source);
        $this->assertStringNotContainsString('graph_json', $source);
    }

    public function testCampaignMonitoringKeepsAuthoringOnWebAndControlsStateSafely(): void
    {
        $endpoint = $this->readDecisionSource('campaigns.php');
        $service = (string) file_get_contents(__DIR__ . '/../../../services/MobileCampaignMonitoringService.php');

        $this->assertStringContainsString("mobileDecisionRequire('campaigns.manage')", $endpoint);
        $this->assertStringContainsString('MobileCampaignMonitoringService', $endpoint);
        $this->assertStringContainsString('workspace_id = ?', $service);
        $this->assertStringContainsString("Only an active campaign can be paused", $service);
        $this->assertStringContainsString("Only a paused campaign can be resumed", $service);
        $this->assertStringContainsString('mobile_decision_acknowledgements', $service);
        $this->assertStringNotContainsString('launchCampaign(', $endpoint);
        $this->assertStringNotContainsString('createCampaign(', $endpoint);
    }

    public function testDecisionCenterBootstrapUsesBearerWorkspaceAndPermissionGates(): void
    {
        $source = $this->readDecisionSource('_bootstrap.php');

        $this->assertStringContainsString('mobileRequireAuth()', $source);
        $this->assertStringContainsString('mobileWorkspaceId', $source);
        $this->assertStringContainsString('Authorization::can', $source);
        $this->assertStringNotContainsString('Auth::check', $source);
        $this->assertStringNotContainsString('requireCsrf', $source);
    }

    private function readDecisionSource(string $file): string
    {
        $path = __DIR__ . '/../../../api/mobile/decision_center/' . $file;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
