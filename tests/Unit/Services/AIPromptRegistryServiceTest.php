<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Session;
use CRM\Services\AIPromptRegistryService;
use CRM\Services\WorkspaceContext;
use CRM\Tests\DatabaseTestCase;

class AIPromptRegistryServiceTest extends DatabaseTestCase
{
    public function testResolvesActivePromptAndActivatesPriorVersion(): void
    {
        $service = new AIPromptRegistryService();

        $history = $service->getPromptHistory('coach', 'coach_recommendations');
        $this->assertNotEmpty($history);
        $this->assertSame('coach_recommendations', $history[0]['prompt_key']);

        $id = $service->registerPrompt([
            'surface' => 'coach',
            'prompt_key' => 'coach_recommendations',
            'status' => 'draft',
            'system_prompt_text' => 'Test system prompt',
            'instruction_text' => 'Test instructions',
            'output_contract_json' => ['type' => 'json'],
            'created_by' => 1,
        ]);
        $this->assertGreaterThan(0, $id);

        $updatedHistory = $service->getPromptHistory('coach', 'coach_recommendations');
        $draftVersion = (int) ($updatedHistory[0]['version'] ?? 0);
        $this->assertGreaterThan(0, $draftVersion);
        $this->assertSame(1, (int) ($updatedHistory[0]['workspace_id'] ?? 0));

        $this->assertTrue($service->activatePromptVersion('coach', 'coach_recommendations', $draftVersion));
        $active = $service->getActivePrompt('coach', 'coach_recommendations');
        $this->assertSame($draftVersion, (int) ($active['version'] ?? 0));
        $this->assertSame('active', (string) ($active['status'] ?? ''));
        $this->assertSame(1, (int) ($active['workspace_id'] ?? 0));
    }

    public function testAssistantPromptSeedsAreAvailable(): void
    {
        $service = new AIPromptRegistryService();

        $question = $service->getActivePrompt('assistant', 'assistant_question');
        $this->assertNotEmpty($question);
        $this->assertSame('assistant_question', $question['prompt_key']);

        $ambiguity = $service->getActivePrompt('assistant', 'assistant_ambiguity_summary');
        $this->assertNotEmpty($ambiguity);
        $this->assertSame('assistant_ambiguity_summary', $ambiguity['prompt_key']);

        $emailPack = $service->getActivePrompt('smart_templates', 'email_pack_generation');
        $this->assertNotEmpty($emailPack);
        $this->assertSame('email_pack_generation', $emailPack['prompt_key']);
        $this->assertSame(5, (int) ($emailPack['version'] ?? 0));
        $this->assertStringContainsString('body-fragment only', (string) ($emailPack['instruction_text'] ?? ''));
        $this->assertContains('body_fragment_only', (array) ($emailPack['output_contract_json']['quality_rules'] ?? []));
        $this->assertContains('no_layout_shell', (array) ($emailPack['output_contract_json']['quality_rules'] ?? []));

        $workflowPack = $service->getActivePrompt('smart_templates', 'workflow_pack_generation');
        $this->assertNotEmpty($workflowPack);
        $this->assertSame('workflow_pack_generation', $workflowPack['prompt_key']);
    }

    public function testCanResolveSpecificPromptVersion(): void
    {
        $service = new AIPromptRegistryService();

        $version = $service->getPromptVersion('coach', 'coach_recommendations', 1);
        $this->assertNotEmpty($version);
        $this->assertSame(1, (int) ($version['version'] ?? 0));
        $this->assertSame('coach_recommendations', $version['prompt_key']);
    }

    public function testDefaultWorkspacePromptOverrideWinsBeforeGlobalPrompt(): void
    {
        $service = new AIPromptRegistryService();

        $prompt = $service->getActivePrompt('coach', 'coach_recommendations');

        $this->assertSame(1, (int) ($prompt['workspace_id'] ?? 0));
        $this->assertStringContainsString('Platform Ops HQ', (string) ($prompt['system_prompt_text'] ?? ''));
        $this->assertSame('default_workspace_platform_ops_ai', (string) ($prompt['metadata_json']['seed_source'] ?? ''));
    }

    public function testTenantWorkspaceFallsBackToGlobalPrompt(): void
    {
        $workspaceId = $this->createTenantWorkspace();
        WorkspaceContext::activateRuntimeWorkspace($workspaceId, 1, 'owner');
        Session::set('active_workspace_id', $workspaceId);

        $prompt = (new AIPromptRegistryService())->getActivePrompt('coach', 'coach_recommendations');

        $this->assertNull($prompt['workspace_id'] ?? null);
        $this->assertStringNotContainsString('Platform Ops HQ', (string) ($prompt['system_prompt_text'] ?? ''));
    }

    private function createTenantWorkspace(): int
    {
        Database::execute(
            "INSERT INTO workspaces (uuid, name, slug, status, plan_status, created_at, updated_at)
             VALUES (?, 'Tenant Prompt Workspace', 'tenant-prompt-workspace', 'active', 'active', NOW(), NOW())",
            ['00000000-0000-4000-8000-0000000000c2']
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
