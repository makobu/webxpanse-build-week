<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\DefaultWorkspaceHealthService;
use CRM\Services\DefaultWorkspaceOpsEventService;
use CRM\Services\DefaultWorkspaceOwnerContactReconciliationService;
use CRM\Services\DefaultWorkspaceRecoveryService;
use CRM\Services\WorkspaceMembershipService;
use CRM\Services\WorkspaceProvisioningService;
use CRM\Tests\DatabaseTestCase;

class DefaultWorkspaceOpsCompletionServiceTest extends DatabaseTestCase
{
    private int $superAdminId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superAdminId = $this->createSuperAdmin('ops.completion.superadmin@example.test');
        (new WorkspaceMembershipService())->addOrUpdateMembership(1, $this->superAdminId, 'superadmin', true, $this->superAdminId);
    }

    public function testReconciliationIsIdempotentAndDoesNotDuplicateOwnerContacts(): void
    {
        $seed = $this->provisionOwner('ops.reconcile.owner@example.test', 'Ops Reconcile');
        $service = new DefaultWorkspaceOwnerContactReconciliationService();

        $first = $service->run($this->superAdminId, 'test reconciliation first pass');
        $second = $service->run($this->superAdminId, 'test reconciliation second pass');

        $this->assertGreaterThanOrEqual(1, (int) ($first['scanned'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($second['scanned'] ?? 0));
        $this->assertSame(1, (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM default_workspace_owner_contacts WHERE owner_workspace_id = ? AND owner_user_id = ?",
            [(int) $seed['workspace_id'], (int) $seed['user_id']]
        )['c'] ?? 0));
        $this->assertSame(1, (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM contacts WHERE workspace_id = 1 AND email = ?",
            ['ops.reconcile.owner@example.test']
        )['c'] ?? 0));
    }

    public function testReconciliationMarksSuspendedOwnerMappingInactive(): void
    {
        $seed = $this->provisionOwner('ops.inactive.owner@example.test', 'Ops Inactive');
        Database::execute("UPDATE workspaces SET status = 'suspended', plan_status = 'past_due' WHERE id = ?", [(int) $seed['workspace_id']]);

        $result = (new DefaultWorkspaceOwnerContactReconciliationService())->run($this->superAdminId, 'test suspension reconciliation');

        $mapping = Database::queryOne(
            "SELECT relationship_status, customer_state, sync_status, inactive_reason
             FROM default_workspace_owner_contacts
             WHERE owner_workspace_id = ? AND owner_user_id = ?",
            [(int) $seed['workspace_id'], (int) $seed['user_id']]
        ) ?: [];

        $this->assertGreaterThanOrEqual(1, (int) ($result['marked_inactive'] ?? 0));
        $this->assertSame('inactive', (string) ($mapping['relationship_status'] ?? ''));
        $this->assertSame('ineligible', (string) ($mapping['customer_state'] ?? ''));
        $this->assertSame('inactive', (string) ($mapping['sync_status'] ?? ''));
        $this->assertSame('workspace_suspended', (string) ($mapping['inactive_reason'] ?? ''));
    }

    public function testOpsEventsDeduplicateAndTransitionWithAudit(): void
    {
        $seed = $this->provisionOwner('ops.event.owner@example.test', 'Ops Event');
        $service = new DefaultWorkspaceOpsEventService();
        $signal = [
            'owner_workspace_id' => (int) $seed['workspace_id'],
            'owner_user_id' => (int) $seed['user_id'],
            'signal_type' => 'owner_support_followup',
            'signal_fingerprint' => 'owner_support_followup:' . (int) $seed['workspace_id'],
            'severity' => 'warning',
            'priority' => 'high',
            'metadata' => ['test' => true],
        ];

        $this->assertSame('created', $service->upsertEvent($signal));
        $this->assertSame('updated', $service->upsertEvent($signal));
        $this->assertSame(1, (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM default_workspace_ops_events WHERE owner_workspace_id = ? AND signal_type = 'owner_support_followup'",
            [(int) $seed['workspace_id']]
        )['c'] ?? 0));

        $event = Database::queryOne("SELECT id FROM default_workspace_ops_events WHERE owner_workspace_id = ? LIMIT 1", [(int) $seed['workspace_id']]);
        $service->transitionEvent((int) ($event['id'] ?? 0), 'resolved', $this->superAdminId, 'resolved in test', 'Done');

        $updated = Database::queryOne("SELECT status, active_signal_key FROM default_workspace_ops_events WHERE id = ?", [(int) ($event['id'] ?? 0)]) ?: [];
        $this->assertSame('resolved', (string) ($updated['status'] ?? ''));
        $this->assertSame('', (string) ($updated['active_signal_key'] ?? ''));
        $this->assertSame(1, (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM operator_audit_log WHERE action_type = 'default_workspace_ops_event_status_changed'"
        )['c'] ?? 0));
    }

    public function testHealthReportsOpsEventAndSyncWarnings(): void
    {
        $seed = $this->provisionOwner('ops.health.owner@example.test', 'Ops Health');
        (new DefaultWorkspaceOpsEventService())->upsertEvent([
            'owner_workspace_id' => (int) $seed['workspace_id'],
            'owner_user_id' => (int) $seed['user_id'],
            'signal_type' => 'low_token_balance',
            'signal_fingerprint' => 'low_token_balance:' . (int) $seed['workspace_id'],
            'severity' => 'warning',
            'priority' => 'high',
        ]);
        Database::execute(
            "INSERT INTO default_workspace_owner_contact_sync_errors
                (default_workspace_id, owner_workspace_id, owner_user_id, error_type, error_message)
             VALUES (1, ?, ?, 'test_error', 'sync failed')",
            [(int) $seed['workspace_id'], (int) $seed['user_id']]
        );

        $health = (new DefaultWorkspaceHealthService())->health($this->superAdminId);

        $this->assertSame('warning', (string) ($health['status'] ?? ''));
        $this->assertGreaterThanOrEqual(1, (int) ($health['metrics']['ops_events_open_high'] ?? 0));
        $this->assertGreaterThanOrEqual(1, (int) ($health['metrics']['sync_errors_open'] ?? 0));
    }

    public function testRecoveryIsIdempotentAndReturnsHealthReport(): void
    {
        $actor = Database::queryOne("SELECT * FROM users WHERE id = ? LIMIT 1", [$this->superAdminId]) ?: [];
        $service = new DefaultWorkspaceRecoveryService();

        $first = $service->recover($actor, 'test recovery first pass');
        $second = $service->recover($actor, 'test recovery second pass');

        $this->assertSame(1, (int) ($first['workspace_id'] ?? 0));
        $this->assertSame(1, (int) ($second['workspace_id'] ?? 0));
        $this->assertContains((string) ($second['after_health_status'] ?? ''), ['ok', 'warning', 'critical']);
        $this->assertGreaterThanOrEqual(1, (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM operator_audit_log WHERE action_type = 'default_workspace_recovery_completed'"
        )['c'] ?? 0));
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
