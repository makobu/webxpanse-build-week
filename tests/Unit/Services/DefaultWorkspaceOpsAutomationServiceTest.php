<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\DefaultWorkspaceOpsAutomationService;
use CRM\Services\DefaultWorkspaceOpsEventService;
use CRM\Services\DefaultWorkspaceOperationalizationService;
use CRM\Services\EmailService;
use CRM\Services\WorkspaceMembershipService;
use CRM\Services\WorkspaceProvisioningService;
use CRM\Tests\DatabaseTestCase;

class DefaultWorkspaceOpsAutomationServiceTest extends DatabaseTestCase
{
    private int $superAdminId;
    private FakeDefaultWorkspaceOpsEmailService $emailService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdminId = $this->createSuperAdmin('ops.automation.superadmin@example.test');
        (new WorkspaceMembershipService())->addOrUpdateMembership(1, $this->superAdminId, 'superadmin', true, $this->superAdminId);
        (new DefaultWorkspaceOperationalizationService())->operationalize($this->superAdminId);
        $this->emailService = new FakeDefaultWorkspaceOpsEmailService();
    }

    public function testAutomatesLowTokenSignalBySendingOwnerEmailAndWaitingOnOwner(): void
    {
        $seed = $this->provisionOwner('ops.automation.owner@customer.com', 'Ops Automation Owner');
        $eventId = $this->createOpsEvent((int) $seed['workspace_id'], (int) $seed['user_id'], 'low_token_balance', [
            'name' => 'Ops Automation Owner',
            'available_tokens' => 250,
        ]);

        $result = $this->service()->automateEvent($eventId, $this->superAdminId);

        $this->assertSame('sent', (string) ($result['status'] ?? ''));
        $this->assertCount(1, $this->emailService->sends);
        $this->assertSame('ops.automation.owner@customer.com', $this->emailService->sends[0]['to']);
        $this->assertStringContainsString('AI Credit balance is low', $this->emailService->sends[0]['subject']);

        $event = Database::queryOne("SELECT status, automation_status, automation_template_slug, automation_email_uuid FROM default_workspace_ops_events WHERE id = ?", [$eventId]) ?: [];
        $this->assertSame('waiting_on_owner', (string) ($event['status'] ?? ''));
        $this->assertSame('sent', (string) ($event['automation_status'] ?? ''));
        $this->assertSame('platform-ops-low_token_warning', (string) ($event['automation_template_slug'] ?? ''));
        $this->assertSame('fake-email-1', (string) ($event['automation_email_uuid'] ?? ''));
        $this->assertSame(1, (int) (Database::queryOne("SELECT COUNT(*) AS c FROM default_workspace_ops_automation_runs WHERE ops_event_id = ? AND run_status = 'sent'", [$eventId])['c'] ?? 0));
        $this->assertSame(1, (int) (Database::queryOne("SELECT COUNT(*) AS c FROM operator_audit_log WHERE action_type = 'default_workspace_ops_event_automated'")['c'] ?? 0));
    }

    public function testAlreadySentActiveSignalDoesNotSendAgainWithoutForce(): void
    {
        $seed = $this->provisionOwner('ops.automation.cooldown@customer.com', 'Ops Automation Cooldown');
        $eventId = $this->createOpsEvent((int) $seed['workspace_id'], (int) $seed['user_id'], 'low_token_balance', [
            'name' => 'Ops Automation Cooldown',
            'available_tokens' => 100,
        ]);
        $service = $this->service();

        $service->automateEvent($eventId, $this->superAdminId);
        $second = $service->automateEvent($eventId, $this->superAdminId);

        $this->assertSame('skipped', (string) ($second['status'] ?? ''));
        $this->assertSame('already_sent', (string) ($second['reason_code'] ?? ''));
        $this->assertCount(1, $this->emailService->sends);
        $event = Database::queryOne("SELECT status, automation_status FROM default_workspace_ops_events WHERE id = ?", [$eventId]) ?: [];
        $this->assertSame('waiting_on_owner', (string) ($event['status'] ?? ''));
        $this->assertSame('sent', (string) ($event['automation_status'] ?? ''));
    }

    public function testMissingOwnerContactLeavesEventOpenWithFailure(): void
    {
        Database::execute(
            "INSERT INTO workspaces (uuid, name, slug, status, plan_status, created_at)
             VALUES (UUID(), 'No Owner Workspace', 'no-owner-workspace', 'active', 'trialing', NOW())"
        );
        $workspaceId = (int) Database::lastInsertId();
        $eventId = $this->createOpsEvent($workspaceId, null, 'low_token_balance', [
            'name' => 'No Owner Workspace',
            'available_tokens' => 50,
        ]);

        $result = $this->service()->automateEvent($eventId, $this->superAdminId);

        $this->assertSame('failed', (string) ($result['status'] ?? ''));
        $this->assertSame('missing_owner_contact', (string) ($result['reason_code'] ?? ''));
        $this->assertCount(0, $this->emailService->sends);
        $event = Database::queryOne("SELECT status, automation_status, automation_last_error FROM default_workspace_ops_events WHERE id = ?", [$eventId]) ?: [];
        $this->assertSame('open', (string) ($event['status'] ?? ''));
        $this->assertSame('failed', (string) ($event['automation_status'] ?? ''));
        $this->assertStringContainsString('owner contact', (string) ($event['automation_last_error'] ?? ''));
    }

    public function testHumanOnlySignalsNeverSendOwnerEmail(): void
    {
        $seed = $this->provisionOwner('ops.automation.human@customer.com', 'Ops Human Only');
        $eventId = $this->createOpsEvent((int) $seed['workspace_id'], (int) $seed['user_id'], 'failed_billing_provider_event', [
            'failed_events' => 1,
        ], 'critical', 'urgent');

        $result = $this->service()->automateEvent($eventId, $this->superAdminId);

        $this->assertSame('human_only', (string) ($result['status'] ?? ''));
        $this->assertCount(0, $this->emailService->sends);
        $event = Database::queryOne("SELECT status, automation_status FROM default_workspace_ops_events WHERE id = ?", [$eventId]) ?: [];
        $this->assertSame('open', (string) ($event['status'] ?? ''));
        $this->assertSame('human_only', (string) ($event['automation_status'] ?? ''));
    }

    public function testClearedAutomatedSignalAutoResolvesOnRefreshCheck(): void
    {
        $seed = $this->provisionOwner('ops.automation.clear@customer.com', 'Ops Clear Signal');
        $eventId = $this->createOpsEvent((int) $seed['workspace_id'], (int) $seed['user_id'], 'low_token_balance', [
            'name' => 'Ops Clear Signal',
            'available_tokens' => 25,
        ]);
        $this->service()->automateEvent($eventId, $this->superAdminId);

        $result = $this->service()->resolveClearedAutomatedEvents([], $this->superAdminId);

        $this->assertSame(1, (int) ($result['resolved'] ?? 0));
        $event = Database::queryOne("SELECT status, active_signal_key, resolution_summary FROM default_workspace_ops_events WHERE id = ?", [$eventId]) ?: [];
        $this->assertSame('resolved', (string) ($event['status'] ?? ''));
        $this->assertSame('', (string) ($event['active_signal_key'] ?? ''));
        $this->assertSame('Signal cleared after automated owner follow-up.', (string) ($event['resolution_summary'] ?? ''));
    }

    private function service(): DefaultWorkspaceOpsAutomationService
    {
        return new DefaultWorkspaceOpsAutomationService(null, $this->emailService);
    }

    /**
     * @return array<string,mixed>
     */
    private function provisionOwner(string $email, string $workspaceName): array
    {
        return (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => $workspaceName,
            'first_name' => 'Owner',
            'last_name' => 'User',
            'email' => $email,
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);
    }

    /**
     * @param array<string,mixed> $metadata
     */
    private function createOpsEvent(int $workspaceId, ?int $ownerUserId, string $signalType, array $metadata, string $severity = 'warning', string $priority = 'high'): int
    {
        (new DefaultWorkspaceOpsEventService())->upsertEvent([
            'owner_workspace_id' => $workspaceId,
            'owner_user_id' => $ownerUserId,
            'signal_type' => $signalType,
            'signal_fingerprint' => $signalType . ':' . $workspaceId,
            'severity' => $severity,
            'priority' => $priority,
            'metadata' => $metadata,
        ]);

        return (int) (Database::queryOne(
            "SELECT id FROM default_workspace_ops_events WHERE owner_workspace_id = ? AND signal_type = ? ORDER BY id DESC LIMIT 1",
            [$workspaceId, $signalType]
        )['id'] ?? 0);
    }

    private function createSuperAdmin(string $email): int
    {
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, first_name, last_name, created_at)
             VALUES (UUID(), ?, ?, 'admin', 'Super', 'Admin', NOW())",
            [$email, password_hash('secret', PASSWORD_DEFAULT)]
        );
        $userId = (int) Database::lastInsertId();
        $role = Database::queryOne("SELECT id FROM roles WHERE slug = 'superadmin' LIMIT 1");
        Database::execute(
            "INSERT INTO user_roles (user_id, role_id, assigned_by)
             VALUES (?, ?, ?)",
            [$userId, (int) ($role['id'] ?? 0), $userId]
        );
        return $userId;
    }
}

class FakeDefaultWorkspaceOpsEmailService extends EmailService
{
    /** @var array<int,array<string,mixed>> */
    public array $sends = [];

    public function __construct()
    {
    }

    public function sendImmediateDetailed(int $contactId, string $to, string $subject, string $body, array $options = []): array
    {
        $uuid = 'fake-email-' . (count($this->sends) + 1);
        $this->sends[] = [
            'contact_id' => $contactId,
            'to' => $to,
            'subject' => $subject,
            'body' => $body,
            'options' => $options,
            'uuid' => $uuid,
        ];

        return [
            'success' => true,
            'status' => 'sent',
            'email_uuid' => $uuid,
            'provider_key' => 'fake',
            'smtp_method' => 'fake',
            'communication_id' => count($this->sends),
        ];
    }
}
