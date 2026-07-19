<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\WorkspaceBusinessIntelligenceGateService;
use CRM\Services\WorkspacePlanEntitlementService;
use CRM\Services\WorkspaceProvisioningService;
use CRM\Tests\DatabaseTestCase;

class WorkspaceBusinessIntelligenceGateServiceTest extends DatabaseTestCase
{
    public function testTenantCompassFreeWorkspaceIsLocked(): void
    {
        $workspaceId = $this->provisionWorkspace('bi-gate-compass-free');
        $this->forceCompassFreeEntitlements($workspaceId);

        $gate = new WorkspaceBusinessIntelligenceGateService();
        $payload = $gate->jsonBlockPayload($workspaceId, null, 'Business Intelligence');

        $this->assertFalse($gate->canAccess($workspaceId));
        $this->assertSame('business_intelligence_required', (string) ($payload['error_code'] ?? ''));
        $this->assertTrue((bool) ($payload['billing_required'] ?? false));
        $this->assertSame('Business Intelligence', (string) ($payload['feature'] ?? ''));
        $this->assertSame('billing_payment_required.php?tab=packages#workspace-packages', (string) ($payload['action_url'] ?? ''));
    }

    public function testFounderPlusWorkspaceIsAllowed(): void
    {
        $workspaceId = $this->provisionWorkspace('bi-gate-founder-plus');
        $this->setWorkspacePlan($workspaceId, 'founder-plus-monthly');

        $this->assertTrue((new WorkspaceBusinessIntelligenceGateService())->canAccess($workspaceId));
    }

    public function testDefaultWorkspacePackageExemptionIsAllowed(): void
    {
        $this->assertTrue((new WorkspaceBusinessIntelligenceGateService())->canAccess(1));
    }

    public function testUnknownWorkspaceIsLocked(): void
    {
        $gate = new WorkspaceBusinessIntelligenceGateService();

        $this->assertFalse($gate->canAccess(0));
        $this->assertFalse((bool) ($gate->status(0)['allowed'] ?? true));
    }

    private function provisionWorkspace(string $slugPrefix): int
    {
        $suffix = strtolower(bin2hex(random_bytes(3)));
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'BI Gate ' . $suffix,
            'workspace_slug' => $slugPrefix . '-' . $suffix,
            'first_name' => 'BI',
            'last_name' => 'Owner',
            'email' => $slugPrefix . '.' . $suffix . '@example.test',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        return (int) ($provisioned['workspace_id'] ?? 0);
    }

    private function forceCompassFreeEntitlements(int $workspaceId): void
    {
        Database::execute("DELETE FROM workspace_subscriptions WHERE workspace_id = ?", [$workspaceId]);
    }

    private function setWorkspacePlan(int $workspaceId, string $priceCode): void
    {
        $price = (new WorkspacePlanEntitlementService())->priceByCode($priceCode);
        $this->assertIsArray($price);
        $this->assertGreaterThan(0, (int) ($price['id'] ?? 0));

        Database::execute(
            "UPDATE workspace_subscriptions
             SET billing_plan_price_id = ?,
                 subscription_status = 'active'
             WHERE workspace_id = ?",
            [(int) $price['id'], $workspaceId]
        );
    }
}
