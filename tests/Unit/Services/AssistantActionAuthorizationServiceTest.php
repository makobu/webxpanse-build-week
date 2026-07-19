<?php

namespace CRM\Tests\Unit\Services;

use CRM\Authorization;
use CRM\Database;
use CRM\Services\AssistantActionAuthorizationService;
use CRM\Services\WorkspaceContext;
use CRM\Tests\DatabaseTestCase;

class AssistantActionAuthorizationServiceTest extends DatabaseTestCase
{
    public function testViewerCannotRunDestructiveContactAction(): void
    {
        $userId = $this->createWorkspaceUser('assistant-viewer@example.test', 'viewer');
        WorkspaceContext::activateRuntimeWorkspace(1, $userId, 'viewer');

        $result = (new AssistantActionAuthorizationService())->authorize('delete_contact', $userId, 1);

        $this->assertFalse($result['allowed']);
        $this->assertSame('privileged_action', $result['reason']);
    }

    public function testSalesUserKeepsPermittedTaskWriteButNotInvoicePaymentAuthority(): void
    {
        $userId = $this->createWorkspaceUser('assistant-sales@example.test', 'sales');
        WorkspaceContext::activateRuntimeWorkspace(1, $userId, 'sales');

        $service = new AssistantActionAuthorizationService();
        $this->assertTrue($service->authorize('create_task', $userId, 1)['allowed']);
        $this->assertFalse($service->authorize('mark_invoice_paid', $userId, 1)['allowed']);
    }

    public function testWorkspaceOwnerRetainsFullAssistantActions(): void
    {
        $userId = $this->createWorkspaceUser('assistant-owner-authz@example.test', 'owner', true);
        WorkspaceContext::activateRuntimeWorkspace(1, $userId, 'owner');

        $this->assertTrue((new AssistantActionAuthorizationService())->authorize('delete_contact', $userId, 1)['allowed']);
    }

    private function createWorkspaceUser(string $email, string $roleSlug, bool $isOwner = false): int
    {
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, first_name, last_name, created_at)
             VALUES (?, ?, ?, ?, 'Assistant', 'Authorization', NOW())",
            [uuid_v4(), $email, password_hash('password', PASSWORD_DEFAULT), $roleSlug === 'owner' ? 'admin' : $roleSlug]
        );
        $userId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at)
             VALUES (1, ?, ?, 'active', ?, NOW())",
            [$userId, $roleSlug, $isOwner ? 1 : 0]
        );
        Authorization::assignUserRoleBySlug($userId, $roleSlug);
        Authorization::resetCaches();
        return $userId;
    }
}
