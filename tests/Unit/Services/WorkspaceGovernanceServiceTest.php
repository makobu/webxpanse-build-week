<?php

namespace CRM\Tests\Unit\Services;

use CRM\Auth;
use CRM\Database;
use CRM\Services\OrganizationFunctionService;
use CRM\Services\WorkspaceGovernanceService;
use CRM\Services\WorkspaceInviteMailer;
use CRM\Services\WorkspaceMembershipService;
use CRM\Services\WorkspacePlanEntitlementService;
use CRM\Services\WorkspaceProvisioningService;
use CRM\Tests\DatabaseTestCase;

class WorkspaceGovernanceServiceTest extends DatabaseTestCase
{
    public function testDefaultWorkspaceCanExceedCompassFreeSeatLimit(): void
    {
        $ownerUserId = (int) Auth::createUser('default-seat-owner@example.com', 'P@ssword123!', 'owner', 'Default', 'Owner');
        $memberUserId = (int) Auth::createUser('default-seat-member@example.com', 'P@ssword123!', 'viewer', 'Default', 'Member');
        $memberships = new WorkspaceMembershipService();
        $memberships->addOrUpdateMembership(1, $ownerUserId, 'owner', true, $ownerUserId);
        $memberships->addOrUpdateMembership(1, $memberUserId, 'viewer', false, $ownerUserId);

        $invite = (new WorkspaceGovernanceService())->createInvite(1, $ownerUserId, 'default-seat-invite@example.com', 'viewer');
        $activeMembers = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM workspace_memberships
             WHERE workspace_id = 1
               AND membership_status = 'active'"
        )['c'] ?? 0);

        $this->assertGreaterThanOrEqual(2, $activeMembers);
        $this->assertSame('pending', (string) ($invite['invite']['invite_status'] ?? ''));
    }

    public function testTenantCompassFreeStillEnforcesSeatLimitForInvites(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Governance Seat Limit Workspace',
            'workspace_slug' => 'governance-seat-limit-workspace',
            'first_name' => 'Seat',
            'last_name' => 'Owner',
            'email' => 'seat.owner@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $this->expectExceptionMessage('seat limit');
        (new WorkspaceGovernanceService())->createInvite(
            (int) ($provisioned['workspace_id'] ?? 0),
            (int) ($provisioned['user_id'] ?? 0),
            'blocked.by.seat.limit@example.com',
            'viewer'
        );
    }

    public function testCreateResendAndRevokeInviteStayScopedToWorkspace(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Governance Invite Workspace',
            'workspace_slug' => 'governance-invite-workspace',
            'first_name' => 'Grace',
            'last_name' => 'Owner',
            'email' => 'grace.owner@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $ownerUserId = (int) ($provisioned['user_id'] ?? 0);
        $mailer = new class extends WorkspaceInviteMailer {
            /** @var array<int,array<string,mixed>> */
            public array $sent = [];

            public function sendInvite(array $invite, string $inviteUrl): array
            {
                $this->sent[] = ['invite' => $invite, 'invite_url' => $inviteUrl];
                return ['success' => true, 'method' => 'fake_mailer'];
            }
        };
        $service = new WorkspaceGovernanceService(null, null, null, null, $mailer);

        $created = $service->createInvite($workspaceId, $ownerUserId, 'teammate@example.com', 'viewer');
        $resent = $service->resendInvite($workspaceId, (int) ($created['invite']['id'] ?? 0), $ownerUserId);
        $revoked = $service->revokeInvite($workspaceId, (int) ($created['invite']['id'] ?? 0), $ownerUserId);
        $history = Database::query(
            "SELECT event_type
             FROM workspace_governance_events
             WHERE workspace_id = ?
             ORDER BY id ASC",
            [$workspaceId]
        );

        $this->assertNotSame((string) ($created['token'] ?? ''), (string) ($resent['token'] ?? ''));
        $this->assertSame('pending', (string) ($resent['invite']['invite_status'] ?? ''));
        $this->assertSame('revoked', (string) ($revoked['invite_status'] ?? ''));
        $this->assertStringContainsString('workspace_invite.php?token=', (string) ($created['invite_url'] ?? ''));
        $this->assertTrue((bool) ($created['delivery']['success'] ?? false));
        $this->assertTrue((bool) ($resent['delivery']['success'] ?? false));
        $this->assertSame('sent', (string) ($created['invite']['delivery_status'] ?? ''));
        $this->assertSame('sent', (string) ($resent['invite']['delivery_status'] ?? ''));
        $this->assertNotEmpty($resent['invite']['last_delivery_attempt_at'] ?? null);
        $this->assertCount(2, $mailer->sent);
        $this->assertSame(
            ['invite_created', 'invite_delivery_sent', 'invite_resent', 'invite_delivery_sent', 'invite_revoked'],
            array_column($history, 'event_type')
        );
    }

    public function testWorkspaceAdminCannotCreateWorkspaceInvite(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Governance Owner Only Workspace',
            'workspace_slug' => 'governance-owner-only-workspace',
            'first_name' => 'Only',
            'last_name' => 'Owner',
            'email' => 'owner.only@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $price = (new WorkspacePlanEntitlementService())->priceByCode('founder-plus-monthly');
        $this->assertIsArray($price);
        Database::execute(
            "UPDATE workspace_subscriptions
             SET billing_plan_price_id = ?,
                 subscription_status = 'active'
             WHERE workspace_id = ?",
            [(int) ($price['id'] ?? 0), $workspaceId]
        );
        $adminUserId = (int) Auth::createUser('owner.only.admin@example.com', 'P@ssword123!', 'admin', 'Workspace', 'Admin');
        (new WorkspaceMembershipService())->addOrUpdateMembership($workspaceId, $adminUserId, 'admin', false, (int) ($provisioned['user_id'] ?? 0));

        $this->expectExceptionMessage('Only workspace owners can manage this workspace.');
        (new WorkspaceGovernanceService())->createInvite($workspaceId, $adminUserId, 'blocked.admin.invite@example.com', 'viewer');
    }

    public function testInviteDeliveryFailureDoesNotRollbackPersistedInvite(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Governance Delivery Failure Workspace',
            'workspace_slug' => 'governance-delivery-failure-workspace',
            'first_name' => 'Grace',
            'last_name' => 'Owner',
            'email' => 'grace.delivery@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $ownerUserId = (int) ($provisioned['user_id'] ?? 0);
        $mailer = new class extends WorkspaceInviteMailer {
            public function sendInvite(array $invite, string $inviteUrl): array
            {
                throw new \RuntimeException('SMTP unavailable');
            }
        };
        $service = new WorkspaceGovernanceService(null, null, null, null, $mailer);

        $created = $service->createInvite($workspaceId, $ownerUserId, 'delivery.failure@example.com', 'viewer');
        $storedInvite = Database::queryOne(
            "SELECT invite_status, delivery_status, delivery_error, last_delivery_attempt_at, delivery_attempt_count
             FROM workspace_invites
             WHERE workspace_id = ?
               AND email = ?",
            [$workspaceId, 'delivery.failure@example.com']
        );

        $this->assertSame('pending', (string) ($storedInvite['invite_status'] ?? ''));
        $this->assertSame('failed', (string) ($storedInvite['delivery_status'] ?? ''));
        $this->assertSame('SMTP unavailable', (string) ($storedInvite['delivery_error'] ?? ''));
        $this->assertNotEmpty($storedInvite['last_delivery_attempt_at'] ?? null);
        $this->assertSame(1, (int) ($storedInvite['delivery_attempt_count'] ?? 0));
        $this->assertFalse((bool) ($created['delivery']['success'] ?? true));
        $this->assertSame('SMTP unavailable', (string) ($created['delivery']['error'] ?? ''));
    }

    public function testRetryInviteDeliveryReusesStoredInviteAndUpdatesDeliveryState(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Governance Retry Workspace',
            'workspace_slug' => 'governance-retry-workspace',
            'first_name' => 'Grace',
            'last_name' => 'Owner',
            'email' => 'grace.retry@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $ownerUserId = (int) ($provisioned['user_id'] ?? 0);
        $failingMailer = new class extends WorkspaceInviteMailer {
            public function sendInvite(array $invite, string $inviteUrl): array
            {
                throw new \RuntimeException('Mailbox unavailable');
            }
        };
        $service = new WorkspaceGovernanceService(null, null, null, null, $failingMailer);
        $created = $service->createInvite($workspaceId, $ownerUserId, 'retry.member@example.com', 'viewer');
        $inviteId = (int) ($created['invite']['id'] ?? 0);

        $workingMailer = new class extends WorkspaceInviteMailer {
            /** @var array<int,string> */
            public array $urls = [];

            public function sendInvite(array $invite, string $inviteUrl): array
            {
                $this->urls[] = $inviteUrl;
                return ['success' => true, 'method' => 'fake_mailer'];
            }
        };
        $retryService = new WorkspaceGovernanceService(null, null, null, null, $workingMailer);
        $retried = $retryService->retryInviteDelivery($workspaceId, $inviteId, $ownerUserId);

        $storedInvite = Database::queryOne(
            "SELECT delivery_status, delivery_error, delivery_attempt_count
             FROM workspace_invites
             WHERE id = ?",
            [$inviteId]
        );

        $this->assertTrue((bool) ($retried['delivery']['success'] ?? false));
        $this->assertSame('sent', (string) ($storedInvite['delivery_status'] ?? ''));
        $this->assertNull($storedInvite['delivery_error'] ?? null);
        $this->assertSame(2, (int) ($storedInvite['delivery_attempt_count'] ?? 0));
        $this->assertCount(1, $workingMailer->urls);
        $this->assertStringContainsString('workspace_invite.php?token=', $workingMailer->urls[0]);
    }

    public function testAcceptInviteForExistingUserIsIdempotent(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Governance Existing Workspace',
            'workspace_slug' => 'governance-existing-workspace',
            'first_name' => 'Existing',
            'last_name' => 'Owner',
            'email' => 'existing.owner@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $ownerUserId = (int) ($provisioned['user_id'] ?? 0);
        $viewerUserId = (int) (Auth::createUser('existing.member@example.com', 'P@ssword123!', 'viewer', 'Existing', 'Member') ?? 0);

        $service = new WorkspaceGovernanceService();
        $invite = $service->createInvite($workspaceId, $ownerUserId, 'existing.member@example.com', 'viewer');

        $firstAcceptance = $service->acceptInviteForUser((string) ($invite['token'] ?? ''), $viewerUserId);
        $secondAcceptance = $service->acceptInviteForUser((string) ($invite['token'] ?? ''), $viewerUserId);

        $membershipCount = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM workspace_memberships
             WHERE workspace_id = ?
               AND user_id = ?",
            [$workspaceId, $viewerUserId]
        )['c'] ?? 0);

        $this->assertSame(1, $membershipCount);
        $this->assertFalse((bool) ($firstAcceptance['already_accepted'] ?? true));
        $this->assertTrue((bool) ($secondAcceptance['already_accepted'] ?? false));
        $this->assertSame('accepted', (string) ($secondAcceptance['invite']['invite_status'] ?? ''));
    }

    public function testAcceptInviteReactivatesExistingInactiveMembership(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Governance Reactivation Workspace',
            'workspace_slug' => 'governance-reactivation-workspace',
            'first_name' => 'Grace',
            'last_name' => 'Owner',
            'email' => 'grace.reactivation@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $ownerUserId = (int) ($provisioned['user_id'] ?? 0);
        $memberUserId = (int) (Auth::createUser('reactivate.member@example.com', 'P@ssword123!', 'viewer', 'Reactivate', 'Member') ?? 0);

        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at)
             VALUES (?, ?, 'viewer', 'left', 0, NOW())",
            [$workspaceId, $memberUserId]
        );

        $service = new WorkspaceGovernanceService();
        $invite = $service->createInvite($workspaceId, $ownerUserId, 'reactivate.member@example.com', 'admin');
        $accepted = $service->acceptInviteForUser((string) ($invite['token'] ?? ''), $memberUserId);

        $membership = Database::queryOne(
            "SELECT role_slug, membership_status
             FROM workspace_memberships
             WHERE workspace_id = ?
               AND user_id = ?",
            [$workspaceId, $memberUserId]
        );

        $this->assertFalse((bool) ($accepted['already_accepted'] ?? true));
        $this->assertSame('admin', (string) ($membership['role_slug'] ?? ''));
        $this->assertSame('active', (string) ($membership['membership_status'] ?? ''));
    }

    public function testAcceptInviteForNewUserCreatesMembership(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Governance New Workspace',
            'workspace_slug' => 'governance-new-workspace',
            'first_name' => 'New',
            'last_name' => 'Owner',
            'email' => 'new.owner@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $ownerUserId = (int) ($provisioned['user_id'] ?? 0);

        $service = new WorkspaceGovernanceService();
        $invite = $service->createInvite($workspaceId, $ownerUserId, 'new.member@example.com', 'admin');
        $accepted = $service->acceptInviteForNewUser((string) ($invite['token'] ?? ''), 'P@ssword123!', 'New', 'Member');

        $membership = Database::queryOne(
            "SELECT role_slug, membership_status
             FROM workspace_memberships
             WHERE workspace_id = ?
               AND user_id = ?",
            [$workspaceId, (int) ($accepted['created_user_id'] ?? 0)]
        );

        $this->assertSame('admin', (string) ($membership['role_slug'] ?? ''));
        $this->assertSame('active', (string) ($membership['membership_status'] ?? ''));
    }

    public function testInviteFunctionAssignmentsAreStoredAndAppliedOnAcceptance(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Governance Function Invite Workspace',
            'workspace_slug' => 'governance-function-invite-workspace',
            'first_name' => 'Function',
            'last_name' => 'Owner',
            'email' => 'function.invite.owner@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $ownerUserId = (int) ($provisioned['user_id'] ?? 0);
        $price = (new WorkspacePlanEntitlementService())->priceByCode('founder-plus-monthly');
        $this->assertIsArray($price);
        Database::execute(
            "UPDATE workspace_subscriptions
             SET billing_plan_price_id = ?,
                 subscription_status = 'active'
             WHERE workspace_id = ?",
            [(int) ($price['id'] ?? 0), $workspaceId]
        );
        (new OrganizationFunctionService())->ensureDefaults($workspaceId);
        $salesId = (int) (Database::queryOne(
            "SELECT id FROM organization_functions WHERE workspace_id = ? AND slug = 'sales' LIMIT 1",
            [$workspaceId]
        )['id'] ?? 0);
        $marketingId = (int) (Database::queryOne(
            "SELECT id FROM organization_functions WHERE workspace_id = ? AND slug = 'marketing' LIMIT 1",
            [$workspaceId]
        )['id'] ?? 0);

        $service = new WorkspaceGovernanceService();
        $invite = $service->createInvite(
            $workspaceId,
            $ownerUserId,
            'function.invite.member@example.com',
            'viewer',
            [$salesId, $marketingId],
            $salesId,
            [
                $salesId => 'owner',
                $marketingId => 'contributor',
            ]
        );

        $this->assertCount(2, (array) ($invite['invite']['function_assignments'] ?? []));

        $accepted = $service->acceptInviteForNewUser((string) ($invite['token'] ?? ''), 'P@ssword123!', 'Function', 'Member');
        $assignmentRows = Database::query(
            "SELECT f.slug, ufa.assignment_type, ufa.is_primary
             FROM user_function_assignments ufa
             JOIN organization_functions f
               ON f.id = ufa.function_id
              AND f.workspace_id = ufa.workspace_id
             WHERE ufa.workspace_id = ?
               AND ufa.user_id = ?
             ORDER BY f.slug ASC",
            [$workspaceId, (int) ($accepted['created_user_id'] ?? 0)]
        );

        $this->assertSame(
            [
                ['slug' => 'marketing', 'assignment_type' => 'contributor', 'is_primary' => 0],
                ['slug' => 'sales', 'assignment_type' => 'owner', 'is_primary' => 1],
            ],
            array_map(static fn(array $row): array => [
                'slug' => (string) ($row['slug'] ?? ''),
                'assignment_type' => (string) ($row['assignment_type'] ?? ''),
                'is_primary' => (int) ($row['is_primary'] ?? 0),
            ], $assignmentRows)
        );
    }

    public function testOwnerInviteRepairsPartialFunctionAssignmentsToFullOwnerCoverage(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Governance Owner Invite Coverage Workspace',
            'workspace_slug' => 'governance-owner-invite-coverage-workspace',
            'first_name' => 'Owner',
            'last_name' => 'Invite',
            'email' => 'owner.invite.coverage@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $ownerUserId = (int) ($provisioned['user_id'] ?? 0);
        $price = (new WorkspacePlanEntitlementService())->priceByCode('founder-plus-monthly');
        $this->assertIsArray($price);
        Database::execute(
            "UPDATE workspace_subscriptions
             SET billing_plan_price_id = ?,
                 subscription_status = 'active'
             WHERE workspace_id = ?",
            [(int) ($price['id'] ?? 0), $workspaceId]
        );

        $functionService = new OrganizationFunctionService();
        $functionService->ensureDefaults($workspaceId);
        $salesId = (int) (Database::queryOne(
            "SELECT id FROM organization_functions WHERE workspace_id = ? AND slug = 'sales' LIMIT 1",
            [$workspaceId]
        )['id'] ?? 0);
        $marketingId = (int) (Database::queryOne(
            "SELECT id FROM organization_functions WHERE workspace_id = ? AND slug = 'marketing' LIMIT 1",
            [$workspaceId]
        )['id'] ?? 0);

        $service = new WorkspaceGovernanceService();
        $invite = $service->createInvite(
            $workspaceId,
            $ownerUserId,
            'partial.owner.invite@example.com',
            'owner',
            [$salesId, $marketingId],
            $salesId,
            [
                $salesId => 'owner',
                $marketingId => 'contributor',
            ]
        );

        $accepted = $service->acceptInviteForNewUser((string) ($invite['token'] ?? ''), 'P@ssword123!', 'Partial', 'Owner');
        $assignments = $functionService->assignmentsForUser($workspaceId, (int) ($accepted['created_user_id'] ?? 0), true);
        $originalOwnerAssignments = $functionService->assignmentsForUser($workspaceId, $ownerUserId, true);
        $assignmentsBySlug = [];
        foreach ($assignments as $assignment) {
            $assignmentsBySlug[(string) ($assignment['slug'] ?? '')] = $assignment;
        }

        $this->assertCount(count($functionService->listAssignableFunctions($workspaceId)), $assignments);
        $this->assertCount(count($functionService->listAssignableFunctions($workspaceId)), $originalOwnerAssignments);
        $this->assertTrue((bool) ($assignmentsBySlug['leadership']['is_primary'] ?? false));
        foreach (array_merge($assignments, $originalOwnerAssignments) as $assignment) {
            $this->assertSame('owner', (string) ($assignment['assignment_type'] ?? ''));
        }
    }

    public function testTransferOwnershipPromotesTargetAndDemotesActor(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Governance Transfer Workspace',
            'workspace_slug' => 'governance-transfer-workspace',
            'first_name' => 'Owner',
            'last_name' => 'One',
            'email' => 'transfer.owner@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $ownerUserId = (int) ($provisioned['user_id'] ?? 0);
        $targetUserId = (int) (Auth::createUser('transfer.target@example.com', 'P@ssword123!', 'viewer', 'Target', 'Member') ?? 0);

        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at)
             VALUES (?, ?, 'viewer', 'active', 0, NOW())",
            [$workspaceId, $targetUserId]
        );
        $targetMembershipId = (int) Database::lastInsertId();

        $service = new WorkspaceGovernanceService();
        $result = $service->transferOwnership($workspaceId, $ownerUserId, $targetMembershipId, false, 'admin');
        $history = Database::queryOne(
            "SELECT event_type, target_user_id
             FROM workspace_governance_events
             WHERE workspace_id = ?
               AND event_type = 'ownership_transferred'
             ORDER BY id DESC
             LIMIT 1",
            [$workspaceId]
        );

        $this->assertSame('admin', (string) ($result['source_membership']['role_slug'] ?? ''));
        $this->assertSame('owner', (string) ($result['target_membership']['role_slug'] ?? ''));
        $this->assertSame('ownership_transferred', (string) ($history['event_type'] ?? ''));
        $this->assertSame($targetUserId, (int) ($history['target_user_id'] ?? 0));

        $functionService = new OrganizationFunctionService();
        $targetAssignments = $functionService->assignmentsForUser($workspaceId, $targetUserId, true);
        $this->assertCount(count($functionService->listAssignableFunctions($workspaceId)), $targetAssignments);
        $this->assertContains('leadership', array_map(static fn(array $assignment): string => (string) ($assignment['slug'] ?? ''), $targetAssignments));
        $this->assertSame(1, count(array_filter($targetAssignments, static fn(array $assignment): bool => !empty($assignment['is_primary']))));
    }

    public function testOwnerPromotionAddsFullCoverageAndMovesPrimaryToLeadership(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Governance Owner Coverage Workspace',
            'workspace_slug' => 'governance-owner-coverage-workspace',
            'first_name' => 'Coverage',
            'last_name' => 'Owner',
            'email' => 'coverage.owner@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $ownerUserId = (int) ($provisioned['user_id'] ?? 0);
        $targetUserId = (int) (Auth::createUser('coverage.target@example.com', 'P@ssword123!', 'viewer', 'Coverage', 'Target') ?? 0);

        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at)
             VALUES (?, ?, 'viewer', 'active', 0, NOW())",
            [$workspaceId, $targetUserId]
        );
        $targetMembershipId = (int) Database::lastInsertId();

        $functionService = new OrganizationFunctionService();
        $functionService->ensureDefaults($workspaceId);
        $salesId = (int) (Database::queryOne(
            "SELECT id FROM organization_functions WHERE workspace_id = ? AND slug = 'sales' LIMIT 1",
            [$workspaceId]
        )['id'] ?? 0);
        $this->assertGreaterThan(0, $salesId);
        $functionService->saveUserAssignments($workspaceId, $targetUserId, [$salesId], $salesId, [$salesId => 'oversight']);

        (new WorkspaceGovernanceService())->updateMemberRole($workspaceId, $targetMembershipId, $ownerUserId, 'owner');

        $assignments = $functionService->assignmentsForUser($workspaceId, $targetUserId, true);
        $assignmentsBySlug = [];
        foreach ($assignments as $assignment) {
            $assignmentsBySlug[(string) ($assignment['slug'] ?? '')] = $assignment;
        }

        $this->assertCount(count($functionService->listAssignableFunctions($workspaceId)), $assignments);
        $this->assertSame('owner', (string) ($assignmentsBySlug['sales']['assignment_type'] ?? ''));
        $this->assertFalse((bool) ($assignmentsBySlug['sales']['is_primary'] ?? true));
        $this->assertSame('owner', (string) ($assignmentsBySlug['leadership']['assignment_type'] ?? ''));
        $this->assertTrue((bool) ($assignmentsBySlug['leadership']['is_primary'] ?? false));
    }

    public function testOwnerReactivationRestoresFullFunctionCoverage(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Governance Owner Reactivation Workspace',
            'workspace_slug' => 'governance-owner-reactivation-workspace',
            'first_name' => 'Reactivation',
            'last_name' => 'Owner',
            'email' => 'reactivation.owner@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $ownerUserId = (int) ($provisioned['user_id'] ?? 0);
        $targetUserId = (int) (Auth::createUser('reactivation.target@example.com', 'P@ssword123!', 'owner', 'Reactivation', 'Target') ?? 0);
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at)
             VALUES (?, ?, 'owner', 'suspended', 1, NOW())",
            [$workspaceId, $targetUserId]
        );
        $targetMembershipId = (int) Database::lastInsertId();
        Database::execute("DELETE FROM user_function_assignments WHERE workspace_id = ? AND user_id = ?", [$workspaceId, $targetUserId]);

        (new WorkspaceGovernanceService())->setMemberStatus($workspaceId, $targetMembershipId, $ownerUserId, 'active');

        $functionService = new OrganizationFunctionService();
        $assignments = $functionService->assignmentsForUser($workspaceId, $targetUserId, true);
        $assignmentsBySlug = [];
        foreach ($assignments as $assignment) {
            $assignmentsBySlug[(string) ($assignment['slug'] ?? '')] = $assignment;
        }

        $this->assertCount(count($functionService->listAssignableFunctions($workspaceId)), $assignments);
        $this->assertTrue((bool) ($assignmentsBySlug['leadership']['is_primary'] ?? false));
        foreach ($assignments as $assignment) {
            $this->assertSame('owner', (string) ($assignment['assignment_type'] ?? ''));
        }
    }

    public function testLastOwnerCannotBeDemotedOrRemoved(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Governance Owner Workspace',
            'workspace_slug' => 'governance-owner-workspace',
            'first_name' => 'Owner',
            'last_name' => 'One',
            'email' => 'owner.one@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $ownerUserId = (int) ($provisioned['user_id'] ?? 0);
        $membershipId = (int) (Database::queryOne(
            "SELECT id
             FROM workspace_memberships
             WHERE workspace_id = ?
               AND user_id = ?",
            [$workspaceId, $ownerUserId]
        )['id'] ?? 0);

        $service = new WorkspaceGovernanceService();

        $this->expectExceptionMessage('last active workspace owner');
        $service->updateMemberRole($workspaceId, $membershipId, $ownerUserId, 'admin');
    }

    public function testSetPrimarySlugUpdatesWorkspaceAndPreservesHistory(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Governance Slug Workspace',
            'workspace_slug' => 'governance-slug-workspace',
            'first_name' => 'Slug',
            'last_name' => 'Owner',
            'email' => 'slug.owner@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $ownerUserId = (int) ($provisioned['user_id'] ?? 0);
        $service = new WorkspaceGovernanceService();

        $result = $service->setPrimaryWorkspaceSlug($workspaceId, $ownerUserId, 'governance-slug-renamed');
        $slugRows = Database::query(
            "SELECT slug, is_primary
             FROM workspace_slugs
             WHERE workspace_id = ?
             ORDER BY id ASC",
            [$workspaceId]
        );

        $this->assertSame('governance-slug-renamed', (string) ($result['workspace']['slug'] ?? ''));
        $this->assertCount(2, $slugRows);
        $this->assertSame('governance-slug-workspace', (string) ($slugRows[0]['slug'] ?? ''));
        $this->assertSame(0, (int) ($slugRows[0]['is_primary'] ?? 1));
        $this->assertSame('governance-slug-renamed', (string) ($slugRows[1]['slug'] ?? ''));
        $this->assertSame(1, (int) ($slugRows[1]['is_primary'] ?? 0));
    }

    public function testGovernanceDataIncludesWorkOwnershipCoverageAndInlineAssignment(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Governance Coverage Workspace',
            'workspace_slug' => 'governance-coverage-workspace',
            'first_name' => 'Coverage',
            'last_name' => 'Owner',
            'email' => 'coverage.matrix.owner@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $ownerUserId = (int) ($provisioned['user_id'] ?? 0);
        $memberUserId = (int) (Auth::createUser('coverage.matrix.member@example.com', 'P@ssword123!', 'viewer', 'Coverage', 'Member') ?? 0);
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at)
             VALUES (?, ?, 'viewer', 'active', 0, NOW())",
            [$workspaceId, $memberUserId]
        );
        $membershipId = (int) Database::lastInsertId();

        $functionService = new OrganizationFunctionService();
        $functionService->ensureDefaults($workspaceId);
        $operationsId = (int) (Database::queryOne(
            "SELECT id FROM organization_functions WHERE workspace_id = ? AND slug = 'operations' LIMIT 1",
            [$workspaceId]
        )['id'] ?? 0);
        Database::execute(
            "DELETE ufa
             FROM user_function_assignments ufa
             JOIN organization_functions f
               ON f.id = ufa.function_id
              AND f.workspace_id = ufa.workspace_id
             WHERE ufa.workspace_id = ?
               AND f.slug = 'operations'",
            [$workspaceId]
        );

        $service = new WorkspaceGovernanceService();
        $before = $service->getWorkspaceGovernanceData($workspaceId, $ownerUserId);
        $operationsBefore = $this->coverageRowBySlug((array) ($before['work_ownership_coverage']['functions'] ?? []), 'operations');
        $this->assertTrue((bool) ($operationsBefore['missing_primary'] ?? false));

        $updated = $service->assignMemberWorkOwnership($workspaceId, $membershipId, $ownerUserId, $operationsId, 'owner', true);
        $after = $service->getWorkspaceGovernanceData($workspaceId, $ownerUserId);
        $operationsAfter = $this->coverageRowBySlug((array) ($after['work_ownership_coverage']['functions'] ?? []), 'operations');
        $history = Database::queryOne(
            "SELECT event_type, target_user_id, metadata_json
             FROM workspace_governance_events
             WHERE workspace_id = ?
               AND event_type = 'member_work_ownership_assigned'
             ORDER BY id DESC
             LIMIT 1",
            [$workspaceId]
        );

        $this->assertSame($memberUserId, (int) ($updated['user_id'] ?? 0));
        $this->assertFalse((bool) ($operationsAfter['missing_primary'] ?? true));
        $this->assertSame($memberUserId, (int) ($operationsAfter['primary_members'][0]['user_id'] ?? 0));
        $this->assertSame('member_work_ownership_assigned', (string) ($history['event_type'] ?? ''));
        $this->assertSame($memberUserId, (int) ($history['target_user_id'] ?? 0));
        $this->assertStringContainsString('operations', (string) ($history['metadata_json'] ?? ''));
    }

    public function testPlatformOverrideWritesOperatorAudit(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Governance Platform Workspace',
            'workspace_slug' => 'governance-platform-workspace',
            'first_name' => 'Platform',
            'last_name' => 'Owner',
            'email' => 'platform.owner@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $ownerUserId = (int) ($provisioned['user_id'] ?? 0);
        $memberUserId = (int) (Auth::createUser('platform.member@example.com', 'P@ssword123!', 'viewer', 'Platform', 'Member') ?? 0);
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, first_name, last_name, created_at)
             VALUES (?, ?, ?, 'admin', 'Support', 'Operator', NOW())",
            [uniqid('platform-admin-', true), 'support.operator@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $actorUserId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at)
             VALUES (?, ?, 'viewer', 'active', 0, NOW())",
            [$workspaceId, $memberUserId]
        );
        $membershipId = (int) Database::lastInsertId();

        $service = new WorkspaceGovernanceService();
        $service->platformUpdateMemberRole($workspaceId, $membershipId, 'admin', $actorUserId, 'Support escalation');

        $audit = Database::queryOne(
            "SELECT action_type, reason, target_user_id
             FROM operator_audit_log
             WHERE target_workspace_id = ?
            ORDER BY id DESC
            LIMIT 1",
            [$workspaceId]
        );
        $history = Database::queryOne(
            "SELECT event_type, target_user_id, metadata_json
             FROM workspace_governance_events
             WHERE workspace_id = ?
             ORDER BY id DESC
             LIMIT 1",
            [$workspaceId]
        );

        $this->assertSame('workspace_member_role_override', (string) ($audit['action_type'] ?? ''));
        $this->assertSame('Support escalation', (string) ($audit['reason'] ?? ''));
        $this->assertSame($memberUserId, (int) ($audit['target_user_id'] ?? 0));
        $this->assertSame('member_role_changed', (string) ($history['event_type'] ?? ''));
        $this->assertSame($memberUserId, (int) ($history['target_user_id'] ?? 0));
        $this->assertStringContainsString('platform_override', (string) ($history['metadata_json'] ?? ''));
    }

    private function coverageRowBySlug(array $rows, string $slug): array
    {
        foreach ($rows as $row) {
            if ((string) ($row['slug'] ?? '') === $slug) {
                return $row;
            }
        }

        return [];
    }
}
