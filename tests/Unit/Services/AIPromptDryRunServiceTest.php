<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Session;
use CRM\Services\AIPromptDryRunService;
use CRM\Services\WorkspaceContext;
use CRM\Tests\DatabaseTestCase;

class AIPromptDryRunServiceTest extends DatabaseTestCase
{
    public function testDryRunBuildsRenderedPromptAndBundleSummary(): void
    {
        $service = new AIPromptDryRunService();

        $result = $service->dryRun('clarity_chat', 'clarity_question_answer', 1, [
            'user_id' => 1,
            'question' => 'What should I do next?',
            'current_page' => 'dashboard',
        ], 8, 8000);

        $this->assertSame('clarity_chat', $result['surface']);
        $this->assertSame('clarity_question_answer', $result['prompt_key']);
        $this->assertSame(1, $result['prompt_version']);
        $this->assertNotEmpty($result['rendered_prompt']);
        $this->assertArrayHasKey('context_bundle_summary', $result);
        $this->assertArrayHasKey('context_bundle_quality', $result);
        $this->assertLessThanOrEqual(8, (int) $result['block_count']);
        $this->assertStringContainsString('Platform Ops HQ', $result['rendered_prompt']);
        $this->assertStringContainsString('LANGUAGE LEVEL: Level 2', $result['rendered_prompt']);
        $this->assertSame('Level 2', $result['response_style_contract']['label'] ?? null);
        $this->assertSame('Level 2', $result['context_bundle_summary']['response_style']['label'] ?? null);
        $this->assertContains('response_style_contract', $result['context_bundle_summary']['block_types'] ?? []);
        $this->assertContains('platform_ops_context', $result['context_bundle_summary']['block_types'] ?? []);
    }

    public function testTenantDryRunDoesNotRenderPlatformOpsContext(): void
    {
        $workspaceId = $this->createTenantWorkspace();
        WorkspaceContext::activateRuntimeWorkspace($workspaceId, 1, 'owner');
        Session::set('active_workspace_id', $workspaceId);

        $result = (new AIPromptDryRunService())->dryRun('clarity_chat', 'clarity_question_answer', 0, [
            'user_id' => 1,
            'workspace_id' => $workspaceId,
            'question' => 'What should I do next?',
            'current_page' => 'dashboard',
        ], 8, 8000);

        $this->assertStringNotContainsString('Platform Ops HQ', $result['rendered_prompt']);
        $this->assertNotContains('platform_ops_context', $result['context_bundle_summary']['block_types'] ?? []);
    }

    private function createTenantWorkspace(): int
    {
        Database::execute(
            "INSERT INTO workspaces (uuid, name, slug, status, plan_status, created_at, updated_at)
             VALUES (?, 'Tenant Dry Run Workspace', 'tenant-dry-run-workspace', 'active', 'active', NOW(), NOW())",
            ['00000000-0000-4000-8000-0000000000d2']
        );
        $workspaceId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at)
             VALUES (?, 1, 'owner', 'active', 1, NOW())",
            [$workspaceId]
        );

        return $workspaceId;
    }
}
