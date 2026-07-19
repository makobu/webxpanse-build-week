<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\ClarityOperatorControlsService;
use CRM\Tests\DatabaseTestCase;

class ClarityOperatorControlsServiceTest extends DatabaseTestCase
{
    public function testControlsAreEnabledOnlyForProtectedDefaultWorkspace(): void
    {
        Database::execute(
            "INSERT INTO workspaces (uuid, name, slug, status, plan_status, created_at)
             VALUES (UUID(), 'Tenant Workspace', 'tenant-workspace', 'active', 'trialing', NOW())"
        );
        $tenantWorkspaceId = (int) Database::lastInsertId();
        $service = new ClarityOperatorControlsService();

        $this->assertTrue($service->enabled(1));
        $this->assertFalse($service->enabled($tenantWorkspaceId));
        $this->assertFalse($service->enabled(0));
    }

    public function testOnlyClarityFeedbackTaskMetadataIsProtected(): void
    {
        $service = new ClarityOperatorControlsService();

        $this->assertTrue($service->isClarityFeedbackTask([], [
            'source_surface' => 'clarity_chat',
            'source_recommendation_type' => 'clarity_feedback',
        ]));
        $this->assertFalse($service->isClarityFeedbackTask([], [
            'source_surface' => 'ai_coach',
            'source_recommendation_type' => 'clarity_feedback',
        ]));
        $this->assertFalse($service->isClarityFeedbackTask([], [
            'source_surface' => 'clarity_chat',
            'source_recommendation_type' => 'manual_task',
        ]));
    }

    public function testClaritySurfaceAssertionRejectsTenantButLeavesCoachAlone(): void
    {
        Database::execute(
            "INSERT INTO workspaces (uuid, name, slug, status, plan_status, created_at)
             VALUES (UUID(), 'Tenant Workspace', 'tenant-two', 'active', 'trialing', NOW())"
        );
        $tenantWorkspaceId = (int) Database::lastInsertId();
        $service = new ClarityOperatorControlsService();

        $service->assertSurfaceAllowed('ai_coach', $tenantWorkspaceId);
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage(ClarityOperatorControlsService::BLOCKED_REASON);
        $service->assertSurfaceAllowed('clarity_chat', $tenantWorkspaceId);
    }
}
