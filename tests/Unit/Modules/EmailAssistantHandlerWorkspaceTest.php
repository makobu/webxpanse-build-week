<?php

namespace CRM\Tests\Unit\Modules;

use CRM\Database;
use CRM\Modules\EmailAssistantHandler;
use CRM\Services\WorkspaceAssistantConfigService;
use CRM\Services\WorkspaceContext;
use CRM\Tests\DatabaseTestCase;

class EmailAssistantHandlerWorkspaceTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        WorkspaceContext::clear();
    }

    protected function tearDown(): void
    {
        WorkspaceContext::clear();
        parent::tearDown();
    }

    public function testInboundWorkspaceResolutionRejectsAmbiguousSenderMemberships(): void
    {
        $userId = $this->createUser('multi-workspace-assistant@example.test');
        $this->createWorkspace(2, 'Assistant Workspace Two', 'assistant-workspace-two');
        $this->addMembership(1, $userId, 'admin');
        $this->addMembership(2, $userId, 'admin');

        $result = $this->invokeResolveInboundWorkspace('multi-workspace-assistant@example.test', []);

        $this->assertSame('ambiguous', $result['status']);
        $this->assertSame(0, (int) $result['workspace_id']);
    }

    public function testInboundWorkspaceResolutionHonorsExplicitWorkspace(): void
    {
        $userId = $this->createUser('explicit-workspace-assistant@example.test');
        $this->createWorkspace(2, 'Assistant Workspace Explicit', 'assistant-workspace-explicit');
        $this->addMembership(1, $userId, 'admin');
        $this->addMembership(2, $userId, 'admin');

        $result = $this->invokeResolveInboundWorkspace('explicit-workspace-assistant@example.test', [
            'workspace_id' => 2,
        ]);

        $this->assertSame('resolved', $result['status']);
        $this->assertSame(2, (int) $result['workspace_id']);
    }

    public function testWorkspaceConfigDisablesStructuredMutations(): void
    {
        WorkspaceContext::activateRuntimeWorkspace(1);
        (new WorkspaceAssistantConfigService())->save(1, 'email', [
            'qa_enabled' => true,
            'instructions_enabled' => false,
        ], true, 0);

        $response = $this->invokeExecuteSingleInstruction('Create invoice for deal 1', 'create_invoice');

        $this->assertStringContainsString('Q&A and instructions are disabled', $response);
    }

    public function testWorkspaceConfigAllowsReadOnlyStructuredAdviceWithQaEnabled(): void
    {
        WorkspaceContext::activateRuntimeWorkspace(1);
        (new WorkspaceAssistantConfigService())->save(1, 'email', [
            'qa_enabled' => true,
            'instructions_enabled' => false,
        ], true, 0);

        $response = $this->invokeExecuteSingleInstruction('What did the assistant last send?', 'show_last_assistant_action');

        $this->assertNotSame('', trim($response));
        $this->assertStringNotContainsString('Q&A and instructions are disabled', $response);
    }

    public function testWhatsappActionDisabledMessageUsesWhatsappSettingsOnly(): void
    {
        WorkspaceContext::activateRuntimeWorkspace(1);
        $configs = new WorkspaceAssistantConfigService();
        $configs->save(1, 'email', [
            'instructions_enabled' => true,
            'skill_create_contact' => true,
        ], true, 0);
        $configs->save(1, 'whatsapp', [
            'instructions_enabled' => true,
            'skill_create_contact' => false,
        ], true, 0);

        $response = $this->invokeExecuteSingleInstruction('Create contact Jane Example, jane@example.test', 'create_contact', 'whatsapp');

        $this->assertStringContainsString('WhatsApp Assistant action disabled', $response);
        $this->assertStringContainsString('Create contact', $response);
    }

    /**
     * @param array<string,mixed> $emailData
     * @return array{status:string,workspace_id:int}
     */
    private function invokeResolveInboundWorkspace(string $fromEmail, array $emailData): array
    {
        $handler = new EmailAssistantHandler();
        $method = new \ReflectionMethod($handler, 'resolveInboundWorkspace');
        $method->setAccessible(true);

        return $method->invoke($handler, $fromEmail, $emailData);
    }

    private function invokeExecuteSingleInstruction(string $body, string $intent, string $assistantType = 'email'): string
    {
        $userId = $this->createUser('assistant-config-owner-' . bin2hex(random_bytes(4)) . '@example.test');
        $this->addMembership(1, $userId, 'owner');
        WorkspaceContext::activateRuntimeWorkspace(1, $userId, 'owner');

        $handler = new EmailAssistantHandler($assistantType);
        $method = new \ReflectionMethod($handler, 'executeSingleInstruction');
        $method->setAccessible(true);

        return (string) $method->invoke($handler, $body, $intent, $userId);
    }

    private function createUser(string $email): int
    {
        Database::execute(
            "INSERT INTO users (email, password_hash, role, created_at) VALUES (?, ?, 'admin', NOW())",
            [$email, password_hash('secret', PASSWORD_DEFAULT)]
        );

        return (int) Database::lastInsertId();
    }

    private function createWorkspace(int $id, string $name, string $slug): void
    {
        Database::execute(
            "INSERT INTO workspaces (id, uuid, name, slug, status, plan_status, created_at, updated_at)
             VALUES (?, ?, ?, ?, 'active', 'trialing', NOW(), NOW())
             ON DUPLICATE KEY UPDATE name = VALUES(name), slug = VALUES(slug), status = 'active', plan_status = 'trialing'",
            [$id, sprintf('00000000-0000-4000-8000-%012d', $id), $name, $slug]
        );
    }

    private function addMembership(int $workspaceId, int $userId, string $role): void
    {
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at)
             VALUES (?, ?, ?, 'active', ?, NOW())
             ON DUPLICATE KEY UPDATE role_slug = VALUES(role_slug), membership_status = 'active'",
            [$workspaceId, $userId, $role, $role === 'owner' ? 1 : 0]
        );
    }
}
