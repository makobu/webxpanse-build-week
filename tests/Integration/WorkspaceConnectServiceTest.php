<?php

namespace CRM\Tests\Integration;

use CRM\Database;
use CRM\Services\WorkspaceConnectService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceMembershipService;
use CRM\Tests\DatabaseTestCase;

class WorkspaceConnectServiceTest extends DatabaseTestCase
{
    private int $adminId = 0;
    private int $memberId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureWorkspace(2, 'connect-two', 'Connect Workspace Two');
        $this->adminId = $this->createUser('connect-admin@example.test', 'admin');
        $this->memberId = $this->createUser('connect-member@example.test', 'user');

        $memberships = new WorkspaceMembershipService();
        $memberships->addOrUpdateMembership(1, $this->adminId, 'admin', false, $this->adminId);
        $memberships->addOrUpdateMembership(1, $this->memberId, 'member', false, $this->adminId);
        $memberships->addOrUpdateMembership(2, $this->adminId, 'admin', false, $this->adminId);

        WorkspaceContext::activateRuntimeWorkspace(1, $this->adminId, 'admin');
    }

    public function testWorkspaceAdminCanManageConnectionsButMemberCannot(): void
    {
        $service = new WorkspaceConnectService();

        $this->assertTrue($service->canManageConnections(['id' => $this->adminId, 'role' => 'admin'], 1));
        $this->assertFalse($service->canManageConnections(['id' => $this->memberId, 'role' => 'user'], 1));
        $this->assertFalse($service->canManageConnections(['id' => $this->memberId, 'role' => 'user'], 2));
    }

    public function testWhatsAppConnectionStateIsWorkspaceScoped(): void
    {
        $service = new WorkspaceConnectService();

        $stateOne = $service->storeWhatsAppEmbeddedSignup(1, $this->adminId, [
            'phone_number_id' => '111111111111111',
            'display_phone_number' => '+254 700 111 111',
            'access_token' => 'workspace-one-token',
        ]);
        $service->storeWhatsAppEmbeddedSignup(2, $this->adminId, [
            'phone_number_id' => '222222222222222',
            'display_phone_number' => '+254 700 222 222',
            'access_token' => 'workspace-two-token',
        ]);

        $this->assertSame('connected', $stateOne['status']);

        WorkspaceContext::activateRuntimeWorkspace(1, $this->adminId, 'admin');
        $activeOne = $service->getActiveWhatsAppIntegration();
        $this->assertSame('111111111111111', (string) ($activeOne['phone_number_id'] ?? ''));

        WorkspaceContext::activateRuntimeWorkspace(2, $this->adminId, 'admin');
        $activeTwo = $service->getActiveWhatsAppIntegration();
        $this->assertSame('222222222222222', (string) ($activeTwo['phone_number_id'] ?? ''));
    }

    public function testManualWhatsAppSettingsEncryptTokenAndReadBackPlainRuntimeToken(): void
    {
        $service = new WorkspaceConnectService();

        $state = $service->storeManualWhatsAppIntegration(1, $this->adminId, [
            'phone_number_id' => '333333333333333',
            'display_phone_number' => '+254 700 333 333',
            'access_token' => 'manual-secret-token',
            'verified_name' => 'Manual Demo',
        ]);

        $raw = Database::queryOne(
            "SELECT access_token, webhook_token, webhook_verify_token
             FROM workspace_whatsapp_integrations
             WHERE workspace_id = 1
             LIMIT 1"
        );
        $active = $service->getActiveWhatsAppIntegration(1);

        $this->assertSame('connected', (string) ($state['status'] ?? ''));
        $this->assertNotEmpty((string) ($state['webhook_token'] ?? ''));
        $this->assertNotEmpty((string) ($state['webhook_verify_token'] ?? ''));
        $this->assertNotSame('manual-secret-token', (string) ($raw['access_token'] ?? ''));
        $this->assertNotSame((string) ($state['webhook_verify_token'] ?? ''), (string) ($raw['webhook_verify_token'] ?? ''));
        $this->assertSame((string) ($state['webhook_token'] ?? ''), (string) ($raw['webhook_token'] ?? ''));
        $this->assertSame('manual-secret-token', (string) ($active['access_token'] ?? ''));
        $this->assertSame('333333333333333', (string) ($active['phone_number_id'] ?? ''));
        $this->assertSame((string) ($state['webhook_token'] ?? ''), (string) ($active['webhook_token'] ?? ''));
        $this->assertSame((string) ($state['webhook_verify_token'] ?? ''), (string) ($active['webhook_verify_token'] ?? ''));

        $service->storeManualWhatsAppIntegration(1, $this->adminId, [
            'phone_number_id' => '333333333333334',
            'display_phone_number' => '+254 700 333 334',
            'access_token' => '',
            'verified_name' => 'Manual Demo Updated',
        ]);
        $updated = $service->getActiveWhatsAppIntegration(1);

        $this->assertSame('manual-secret-token', (string) ($updated['access_token'] ?? ''));
        $this->assertSame('333333333333334', (string) ($updated['phone_number_id'] ?? ''));
        $this->assertSame((string) ($state['webhook_token'] ?? ''), (string) ($updated['webhook_token'] ?? ''));
        $this->assertSame((string) ($state['webhook_verify_token'] ?? ''), (string) ($updated['webhook_verify_token'] ?? ''));
    }

    public function testDuplicateActiveWhatsAppPhoneNumberIsRejectedAcrossWorkspaces(): void
    {
        $service = new WorkspaceConnectService();
        $service->storeManualWhatsAppIntegration(1, $this->adminId, [
            'phone_number_id' => '444444444444444',
            'display_phone_number' => '+254 700 444 444',
            'access_token' => 'workspace-one-whatsapp-token',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already connected to another active workspace');

        $service->storeManualWhatsAppIntegration(2, $this->adminId, [
            'phone_number_id' => '444444444444444',
            'display_phone_number' => '+254 700 444 444',
            'access_token' => 'workspace-two-whatsapp-token',
        ]);
    }

    public function testMeetingReadinessUsesCalendarLinksWithinWorkspace(): void
    {
        Database::execute(
            "INSERT INTO calendar_integrations (workspace_id, user_id, provider, access_token, refresh_token, calendar_id, calendar_name, sync_enabled)
             VALUES (1, ?, 'google', 'token', 'refresh', 'primary', 'Primary', 1)",
            [$this->adminId]
        );
        Database::execute(
            "INSERT INTO calendar_integrations (workspace_id, user_id, provider, access_token, refresh_token, calendar_id, calendar_name, sync_enabled)
             VALUES (2, ?, 'google', 'token', 'refresh', 'primary', 'Primary', 1)",
            [$this->adminId]
        );
        Database::execute(
            "INSERT INTO events (workspace_id, title, start_time, end_time, location, created_by, created_at)
             VALUES (1, 'Meet link', NOW(), DATE_ADD(NOW(), INTERVAL 30 MINUTE), 'https://meet.google.com/abc-defg-hij', ?, NOW())",
            [$this->adminId]
        );
        Database::execute(
            "INSERT INTO events (workspace_id, title, start_time, end_time, location, created_by, created_at)
             VALUES (2, 'Zoom link', NOW(), DATE_ADD(NOW(), INTERVAL 30 MINUTE), 'https://example.zoom.us/j/123456789', ?, NOW())",
            [$this->adminId]
        );

        $service = new WorkspaceConnectService();

        $workspaceOne = $service->buildHubState(['id' => $this->adminId, 'role' => 'admin'], 1);
        $workspaceTwo = $service->buildHubState(['id' => $this->adminId, 'role' => 'admin'], 2);

        $this->assertTrue((bool) ($workspaceOne['meetings']['google_meet_ready'] ?? false));
        $this->assertFalse((bool) ($workspaceOne['meetings']['zoom_ready'] ?? false));
        $this->assertFalse((bool) ($workspaceTwo['meetings']['google_meet_ready'] ?? false));
        $this->assertTrue((bool) ($workspaceTwo['meetings']['zoom_ready'] ?? false));
    }

    private function createUser(string $email, string $role): int
    {
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at)
             VALUES (?, ?, ?, ?, NOW())",
            [uniqid('connect-user-', true), $email, password_hash('secret', PASSWORD_DEFAULT), $role]
        );

        return (int) Database::lastInsertId();
    }

    private function ensureWorkspace(int $id, string $slug, string $name): void
    {
        Database::execute(
            "INSERT INTO workspaces (id, uuid, name, slug, status, plan_status, created_at, updated_at)
             VALUES (?, ?, ?, ?, 'active', 'trialing', NOW(), NOW())
             ON DUPLICATE KEY UPDATE name = VALUES(name), slug = VALUES(slug), updated_at = NOW()",
            [$id, sprintf('00000000-0000-4000-8000-%012d', $id), $name, $slug]
        );
    }
}
