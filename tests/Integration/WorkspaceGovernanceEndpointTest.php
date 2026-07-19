<?php

namespace CRM\Tests\Integration;

use CRM\Auth;
use CRM\Database;
use CRM\Services\OrganizationFunctionService;
use CRM\Services\WorkspaceGovernanceService;
use CRM\Services\WorkspacePlanEntitlementService;
use CRM\Services\WorkspaceProvisioningService;
use CRM\Tests\DatabaseTestCase;
use CRM\Tests\Support\EndpointHarness;

class WorkspaceGovernanceEndpointTest extends DatabaseTestCase
{
    use EndpointHarness;

    public function testSettingsWorkspaceGovernanceInviteAndTransferOwnershipStayScopedToActiveWorkspace(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Endpoint Governance Workspace',
            'workspace_slug' => 'endpoint-governance-workspace',
            'first_name' => 'Governance',
            'last_name' => 'Owner',
            'email' => 'endpoint.owner@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $ownerUserId = (int) ($provisioned['user_id'] ?? 0);
        $this->setWorkspacePlan($workspaceId, 'founder-plus-monthly');
        $targetUserId = (int) (Auth::createUser('endpoint.target@example.com', 'P@ssword123!', 'viewer', 'Target', 'Member') ?? 0);

        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at)
             VALUES (?, ?, 'viewer', 'active', 0, NOW())",
            [$workspaceId, $targetUserId]
        );
        $targetMembershipId = (int) Database::lastInsertId();

        $inviteResponse = $this->runWebEndpoint('public/settings.php', $this->workspaceSession($ownerUserId, $workspaceId, 'owner'), [
            'method' => 'POST',
            'query' => ['tab' => 'workspace_governance'],
            'post' => [
                'csrf_token' => 'test-csrf-token',
                'tab' => 'workspace_governance',
                'workspace_governance_action' => 'invite_member',
                'invite_email' => 'endpoint.invitee@example.com',
                'invite_role_slug' => 'expert',
            ] + $this->inviteFunctionPostFields($workspaceId, 'expert'),
        ]);

        $this->assertSame(200, (int) ($inviteResponse['status'] ?? 0), (string) ($inviteResponse['stderr'] ?? ''));
        $inviteRow = Database::queryOne(
            "SELECT email, workspace_id, role_slug, invite_status
             FROM workspace_invites
             WHERE workspace_id = ?
               AND email = ?",
            [$workspaceId, 'endpoint.invitee@example.com']
        );
        $this->assertSame('pending', (string) ($inviteRow['invite_status'] ?? ''));
        $this->assertSame('expert', (string) ($inviteRow['role_slug'] ?? ''));
        $this->assertSame($workspaceId, (int) ($inviteRow['workspace_id'] ?? 0));

        $transferResponse = $this->runWebEndpoint('public/settings.php', $this->workspaceSession($ownerUserId, $workspaceId, 'owner'), [
            'method' => 'POST',
            'query' => ['tab' => 'workspace_governance'],
            'post' => [
                'csrf_token' => 'test-csrf-token',
                'tab' => 'workspace_governance',
                'workspace_governance_action' => 'transfer_ownership',
                'target_membership_id' => $targetMembershipId,
                'demoted_actor_role' => 'admin',
            ],
        ]);

        $this->assertSame(200, (int) ($transferResponse['status'] ?? 0), (string) ($transferResponse['stderr'] ?? ''));

        $targetMembership = Database::queryOne(
            "SELECT role_slug, is_owner
             FROM workspace_memberships
             WHERE id = ?",
            [$targetMembershipId]
        );
        $ownerMembership = Database::queryOne(
            "SELECT role_slug, is_owner
             FROM workspace_memberships wm
             WHERE workspace_id = ?
               AND user_id = ?",
            [$workspaceId, $ownerUserId]
        );
        $historyCount = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM workspace_governance_events
             WHERE workspace_id = ?
               AND event_type IN ('invite_created', 'ownership_transferred')",
            [$workspaceId]
        )['c'] ?? 0);

        $this->assertSame('owner', (string) ($targetMembership['role_slug'] ?? ''));
        $this->assertSame(1, (int) ($targetMembership['is_owner'] ?? 0));
        $this->assertSame('admin', (string) ($ownerMembership['role_slug'] ?? ''));
        $this->assertSame(0, (int) ($ownerMembership['is_owner'] ?? 1));
        $this->assertSame(2, $historyCount);
    }

    public function testPublicWorkspaceInviteAcceptanceIsIdempotentForExistingUser(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Invite Acceptance Workspace',
            'workspace_slug' => 'invite-acceptance-workspace',
            'first_name' => 'Invite',
            'last_name' => 'Owner',
            'email' => 'invite.owner@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $ownerUserId = (int) ($provisioned['user_id'] ?? 0);
        $this->setWorkspacePlan($workspaceId, 'founder-plus-monthly');
        $memberUserId = (int) (Auth::createUser('existing.invited@example.com', 'P@ssword123!', 'viewer', 'Existing', 'Invited') ?? 0);

        $service = new WorkspaceGovernanceService();
        $invite = $service->createInvite($workspaceId, $ownerUserId, 'existing.invited@example.com', 'expert');
        $token = (string) ($invite['token'] ?? '');

        $firstResponse = $this->runWebEndpoint('public/workspace_invite.php', $this->workspaceSession($memberUserId, $workspaceId, 'viewer'), [
            'method' => 'POST',
            'query' => ['token' => $token],
            'post' => [
                'csrf_token' => 'test-csrf-token',
                'token' => $token,
                'invite_action' => 'accept_existing',
            ],
        ]);
        $secondResponse = $this->runWebEndpoint('public/workspace_invite.php', $this->workspaceSession($memberUserId, $workspaceId, 'viewer'), [
            'method' => 'POST',
            'query' => ['token' => $token],
            'post' => [
                'csrf_token' => 'test-csrf-token',
                'token' => $token,
                'invite_action' => 'accept_existing',
            ],
        ]);

        $membership = Database::queryOne(
            "SELECT wm.role_slug, r.slug AS workspace_role_slug
             FROM workspace_memberships wm
             LEFT JOIN workspace_user_roles wur
               ON wur.workspace_id = wm.workspace_id
              AND wur.user_id = wm.user_id
             LEFT JOIN roles r ON r.id = wur.role_id
              WHERE wm.workspace_id = ?
               AND wm.user_id = ?
             LIMIT 1",
            [$workspaceId, $memberUserId]
        ) ?? [];
        $historyCount = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM workspace_governance_events
             WHERE workspace_id = ?
               AND event_type = 'invite_accepted'
               AND target_user_id = ?",
            [$workspaceId, $memberUserId]
        )['c'] ?? 0);

        $this->assertSame(302, (int) ($firstResponse['status'] ?? 0));
        $this->assertSame(302, (int) ($secondResponse['status'] ?? 0));
        $this->assertSame('expert', (string) ($membership['role_slug'] ?? ''));
        $this->assertSame('expert', (string) ($membership['workspace_role_slug'] ?? ''));
        $this->assertSame(1, $historyCount);
    }

    public function testPublicWorkspaceInviteAcceptanceReactivatesInactiveMembership(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Invite Reactivation Workspace',
            'workspace_slug' => 'invite-reactivation-workspace',
            'first_name' => 'Invite',
            'last_name' => 'Owner',
            'email' => 'invite.reactivate.owner@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $ownerUserId = (int) ($provisioned['user_id'] ?? 0);
        $this->setWorkspacePlan($workspaceId, 'founder-plus-monthly');
        $memberUserId = (int) (Auth::createUser('invite.reactivate.member@example.com', 'P@ssword123!', 'viewer', 'Inactive', 'Member') ?? 0);

        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at)
             VALUES (?, ?, 'viewer', 'left', 0, NOW())",
            [$workspaceId, $memberUserId]
        );

        $service = new WorkspaceGovernanceService();
        $invite = $service->createInvite($workspaceId, $ownerUserId, 'invite.reactivate.member@example.com', 'admin');
        $token = (string) ($invite['token'] ?? '');

        $response = $this->runWebEndpoint('public/workspace_invite.php', $this->workspaceSession($memberUserId, $workspaceId, 'viewer'), [
            'method' => 'POST',
            'query' => ['token' => $token],
            'post' => [
                'csrf_token' => 'test-csrf-token',
                'token' => $token,
                'invite_action' => 'accept_existing',
            ],
        ]);

        $membership = Database::queryOne(
            "SELECT role_slug, membership_status
             FROM workspace_memberships
             WHERE workspace_id = ?
               AND user_id = ?",
            [$workspaceId, $memberUserId]
        );

        $this->assertSame(302, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertSame('admin', (string) ($membership['role_slug'] ?? ''));
        $this->assertSame('active', (string) ($membership['membership_status'] ?? ''));
    }

    public function testWorkspaceGovernanceApiSummaryAndRetryExposeDeliveryState(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Governance API Workspace',
            'workspace_slug' => 'governance-api-workspace',
            'first_name' => 'Api',
            'last_name' => 'Owner',
            'email' => 'governance.api.owner@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $ownerUserId = (int) ($provisioned['user_id'] ?? 0);

        Database::execute(
            "INSERT INTO workspace_invites
             (workspace_id, email, role_slug, token_hash, invite_status, invited_by, expires_at, delivery_status, delivery_error, last_delivery_attempt_at, delivery_attempt_count)
             VALUES (?, ?, 'viewer', ?, 'pending', ?, DATE_ADD(NOW(), INTERVAL 7 DAY), 'failed', 'SMTP down', NOW(), 1)",
            [$workspaceId, 'api.retry@example.com', hash('sha256', 'old-token'), $ownerUserId]
        );
        $inviteId = (int) Database::lastInsertId();

        $summaryResponse = $this->runWebEndpoint('api/workspace_governance/summary.php', $this->workspaceSession($ownerUserId, $workspaceId, 'owner'), [
            'method' => 'GET',
            'query' => ['history_filter' => 'invites'],
            'env' => ['APP_ENV' => 'test'],
        ]);

        $summaryPayload = json_decode((string) ($summaryResponse['body'] ?? ''), true);
        if (!is_array($summaryPayload)) {
            $summaryPayload = json_decode((string) ($summaryResponse['stdout'] ?? ''), true);
        }

        $this->assertSame(200, (int) ($summaryResponse['status'] ?? 0), (string) ($summaryResponse['stderr'] ?? ''));
        $this->assertTrue((bool) ($summaryPayload['success'] ?? false));
        $this->assertArrayHasKey('invites', $summaryPayload['data'] ?? []);
        $this->assertArrayHasKey('slug_history', $summaryPayload['data'] ?? []);
        $this->assertSame('invites', (string) (($summaryPayload['data']['history_filter'] ?? 'all')));

        $retryResponse = $this->runWebEndpoint('api/workspace_governance/actions.php', $this->workspaceSession($ownerUserId, $workspaceId, 'owner'), [
            'method' => 'POST',
            'env' => ['APP_ENV' => 'test'],
            'post' => [
                'csrf_token' => 'test-csrf-token',
                'action' => 'retry_invite_delivery',
                'invite_id' => $inviteId,
            ],
        ]);

        $retryPayload = json_decode((string) ($retryResponse['body'] ?? ''), true);
        if (!is_array($retryPayload)) {
            $retryPayload = json_decode((string) ($retryResponse['stdout'] ?? ''), true);
        }

        $updatedInvite = Database::queryOne(
            "SELECT delivery_status, delivery_attempt_count
             FROM workspace_invites
             WHERE id = ?",
            [$inviteId]
        );

        $this->assertSame(200, (int) ($retryResponse['status'] ?? 0), (string) ($retryResponse['stderr'] ?? ''));
        $this->assertTrue((bool) ($retryPayload['success'] ?? false));
        $this->assertSame('sent', (string) ($updatedInvite['delivery_status'] ?? ''));
        $this->assertSame(2, (int) ($updatedInvite['delivery_attempt_count'] ?? 0));
    }

    public function testWorkspaceGovernanceApiRateLimitsRepeatedOwnerActions(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Governance Rate Limit Workspace',
            'workspace_slug' => 'governance-rate-limit-workspace',
            'first_name' => 'Governance',
            'last_name' => 'RateLimit',
            'email' => 'governance.rate.limit.owner@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $ownerUserId = (int) ($provisioned['user_id'] ?? 0);
        $lastResponse = null;

        for ($attempt = 0; $attempt < 11; $attempt++) {
            $lastResponse = $this->runWebEndpoint('api/workspace_governance/actions.php', $this->workspaceSession($ownerUserId, $workspaceId, 'owner'), [
                'method' => 'POST',
                'server' => ['REMOTE_ADDR' => '10.40.0.88'],
                'post' => [
                    'csrf_token' => 'test-csrf-token',
                    'action' => 'invite_member',
                    'invite_email' => 'governance.limit@example.com',
                    'invite_role_slug' => 'viewer',
                ] + $this->inviteFunctionPostFields($workspaceId, 'viewer'),
                'env' => ['APP_ENV' => 'test'],
            ]);
        }

        $payload = json_decode((string) ($lastResponse['body'] ?? ''), true);
        if (!is_array($payload)) {
            $payload = json_decode((string) ($lastResponse['stdout'] ?? ''), true);
        }

        $this->assertSame(429, (int) ($lastResponse['status'] ?? 0));
        $this->assertSame(
            'Too many workspace team changes were attempted. Please wait a moment before trying again.',
            (string) ($payload['error'] ?? '')
        );
        $this->assertGreaterThan(0, (int) ($payload['retry_after'] ?? 0));
    }

    public function testPublicWorkspaceInviteAcceptanceRateLimitsRepeatedAttempts(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Invite Limit Workspace',
            'workspace_slug' => 'invite-limit-workspace',
            'first_name' => 'Invite',
            'last_name' => 'Limiter',
            'email' => 'invite.limit.owner@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $ownerUserId = (int) ($provisioned['user_id'] ?? 0);
        $this->setWorkspacePlan($workspaceId, 'founder-plus-monthly');

        $service = new WorkspaceGovernanceService();
        $invite = $service->createInvite($workspaceId, $ownerUserId, 'invite.limit.member@example.com', 'viewer');
        $token = (string) ($invite['token'] ?? '');
        $lastResponse = null;

        for ($attempt = 0; $attempt < 11; $attempt++) {
            $lastResponse = $this->runWebEndpoint('public/workspace_invite.php', ['csrf_token' => 'test-csrf-token'], [
                'method' => 'POST',
                'query' => ['token' => $token],
                'server' => ['REMOTE_ADDR' => '10.40.0.89'],
                'post' => [
                    'csrf_token' => 'test-csrf-token',
                    'token' => $token,
                    'invite_action' => 'create_account',
                    'first_name' => 'Invite',
                    'last_name' => 'Member',
                    'password' => 'short',
                    'password_confirm' => 'different',
                ],
            ]);
        }

        $body = (string) ($lastResponse['body'] ?? '');
        $membershipCount = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM workspace_memberships
             WHERE workspace_id = ?
               AND user_id IN (
                   SELECT id
                   FROM users
                   WHERE email = 'invite.limit.member@example.com'
               )",
            [$workspaceId]
        )['c'] ?? 0);

        $this->assertSame(429, (int) ($lastResponse['status'] ?? 0));
        $this->assertStringContainsString('Too many invite acceptance attempts were made. Please wait a moment before trying again.', $body);
        $this->assertSame(0, $membershipCount);
    }

    public function testWorkspaceGovernanceSummaryReturnsSafeReadinessErrorWhenSchemaDrifts(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Governance Drift Workspace',
            'workspace_slug' => 'governance-drift-workspace',
            'first_name' => 'Drift',
            'last_name' => 'Owner',
            'email' => 'governance.drift.owner@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $ownerUserId = (int) ($provisioned['user_id'] ?? 0);

        Database::execute("DROP TABLE workspace_governance_events");

        $response = $this->runWebEndpoint('api/workspace_governance/summary.php', $this->workspaceSession($ownerUserId, $workspaceId, 'owner'), [
            'method' => 'GET',
            'env' => ['APP_ENV' => 'test'],
        ]);

        $payload = json_decode((string) ($response['body'] ?? ''), true);
        if (!is_array($payload)) {
            $payload = json_decode((string) ($response['stdout'] ?? ''), true);
        }

        $this->assertSame(503, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertSame(
            'Workspace team settings are temporarily unavailable until the latest governance migrations are applied.',
            (string) ($payload['error'] ?? '')
        );
        $this->assertSame('workspace_governance_events', (string) (($payload['readiness']['issues'][0]['table'] ?? '')));
    }

    public function testPublicWorkspaceInvitePageShowsSafeReadinessErrorWhenSchemaDrifts(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Invite Drift Workspace',
            'workspace_slug' => 'invite-drift-workspace',
            'first_name' => 'Invite',
            'last_name' => 'Drift',
            'email' => 'invite.drift.owner@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $ownerUserId = (int) ($provisioned['user_id'] ?? 0);
        $this->setWorkspacePlan($workspaceId, 'founder-plus-monthly');

        $service = new WorkspaceGovernanceService();
        $invite = $service->createInvite($workspaceId, $ownerUserId, 'invite.drift.member@example.com', 'viewer');
        $token = (string) ($invite['token'] ?? '');

        Database::execute("DROP TABLE workspace_governance_events");

        $response = $this->runWebEndpoint('public/workspace_invite.php', [], [
            'method' => 'GET',
            'query' => ['token' => $token],
        ]);

        $body = (string) ($response['body'] ?? '');

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertStringContainsString('Workspace team settings are temporarily unavailable until the latest governance migrations are applied.', $body);
    }

    public function testPlatformWorkspaceAdminOverrideCreatesOperatorAuditAndGovernanceHistory(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Platform Override Workspace',
            'workspace_slug' => 'platform-override-workspace',
            'first_name' => 'Platform',
            'last_name' => 'Owner',
            'email' => 'platform.owner2@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $memberUserId = (int) (Auth::createUser('override.member@example.com', 'P@ssword123!', 'viewer', 'Override', 'Member') ?? 0);
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at)
             VALUES (?, ?, 'viewer', 'active', 0, NOW())",
            [$workspaceId, $memberUserId]
        );
        $membershipId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, first_name, last_name, created_at)
             VALUES (?, ?, ?, 'admin', 'Support', 'Operator', NOW())",
            [uniqid('platform-governance-', true), 'platform.ops@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $platformAdminUserId = (int) Database::lastInsertId();
        $this->assignGlobalRole($platformAdminUserId, 'superadmin');

        $response = $this->runWebEndpoint('public/workspace_admin.php', $this->platformSession($platformAdminUserId), [
            'method' => 'POST',
            'query' => ['workspace_id' => $workspaceId],
            'post' => [
                'csrf_token' => 'test-csrf-token',
                'workspace_id' => $workspaceId,
                'admin_action' => 'governance_override',
                'governance_action' => 'update_member_role',
                'membership_id' => $membershipId,
                'role_slug' => 'admin',
                'reason' => 'Support override',
            ],
        ]);

        $audit = Database::queryOne(
            "SELECT action_type, reason
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

        $this->assertSame(302, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertSame('workspace_member_role_override', (string) ($audit['action_type'] ?? ''));
        $this->assertSame('Support override', (string) ($audit['reason'] ?? ''));
        $this->assertSame('member_role_changed', (string) ($history['event_type'] ?? ''));
        $this->assertSame($memberUserId, (int) ($history['target_user_id'] ?? 0));
        $this->assertStringContainsString('platform_override', (string) ($history['metadata_json'] ?? ''));
    }

    public function testNonPlatformUserCannotAccessWorkspaceAdminGovernanceSurface(): void
    {
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Non Platform Workspace',
            'workspace_slug' => 'non-platform-workspace',
            'first_name' => 'Normal',
            'last_name' => 'Owner',
            'email' => 'non.platform.owner@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $viewerUserId = (int) (Auth::createUser('non.platform.viewer@example.com', 'P@ssword123!', 'viewer', 'Normal', 'Viewer') ?? 0);
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at)
             VALUES (?, ?, 'viewer', 'active', 0, NOW())",
            [$workspaceId, $viewerUserId]
        );

        $response = $this->runWebEndpoint('public/workspace_admin.php', $this->workspaceSession($viewerUserId, $workspaceId, 'viewer'), [
            'method' => 'GET',
            'query' => ['workspace_id' => $workspaceId],
        ]);

        $this->assertSame(302, (int) ($response['status'] ?? 0));
    }

    /**
     * @return array<string,mixed>
     */
    private function workspaceSession(int $userId, int $workspaceId, string $workspaceRole): array
    {
        $workspace = Database::queryOne(
            "SELECT id, uuid, name, slug
             FROM workspaces
             WHERE id = ?
             LIMIT 1",
            [$workspaceId]
        ) ?? [];
        $membership = Database::queryOne(
            "SELECT id
             FROM workspace_memberships
             WHERE workspace_id = ?
               AND user_id = ?
             LIMIT 1",
            [$workspaceId, $userId]
        ) ?? [];
        $user = Database::queryOne(
            "SELECT uuid, email, role
             FROM users
             WHERE id = ?
             LIMIT 1",
            [$userId]
        ) ?? [];

        return [
            'user_id' => $userId,
            'user_uuid' => (string) ($user['uuid'] ?? ''),
            'user_email' => (string) ($user['email'] ?? ''),
            'user_role' => (string) ($user['role'] ?? 'viewer'),
            'active_workspace_id' => $workspaceId,
            'active_workspace_uuid' => (string) ($workspace['uuid'] ?? ''),
            'active_workspace_slug' => (string) ($workspace['slug'] ?? ''),
            'active_workspace_name' => (string) ($workspace['name'] ?? ''),
            'active_workspace_role' => $workspaceRole,
            'active_workspace_membership_id' => (int) ($membership['id'] ?? 0),
            'csrf_token' => 'test-csrf-token',
            '__remember_restore_attempted' => true,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function platformSession(int $userId): array
    {
        $user = Database::queryOne(
            "SELECT uuid, email, role
             FROM users
             WHERE id = ?
             LIMIT 1",
            [$userId]
        ) ?? [];

        return [
            'user_id' => $userId,
            'user_uuid' => (string) ($user['uuid'] ?? ''),
            'user_email' => (string) ($user['email'] ?? ''),
            'user_role' => (string) ($user['role'] ?? 'admin'),
            'csrf_token' => 'test-csrf-token',
            '__remember_restore_attempted' => true,
        ];
    }

    private function assignGlobalRole(int $userId, string $roleSlug): void
    {
        $role = Database::queryOne("SELECT id FROM roles WHERE slug = ? LIMIT 1", [$roleSlug]);
        $roleId = (int) ($role['id'] ?? 0);
        $this->assertGreaterThan(0, $roleId, 'Missing role: ' . $roleSlug);
        Database::execute(
            "INSERT INTO user_roles (user_id, role_id, assigned_by)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE role_id = VALUES(role_id), assigned_by = VALUES(assigned_by)",
            [$userId, $roleId, $userId]
        );
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

    private function inviteFunctionPostFields(int $workspaceId, string $roleSlug): array
    {
        $functionService = new OrganizationFunctionService();
        $functionService->ensureDefaults($workspaceId);
        $isOwner = $roleSlug === 'owner';
        $functionIds = $functionService->defaultFunctionIdsForRole($workspaceId, $roleSlug, $isOwner);

        return [
            'invite_function_ids' => $functionIds,
            'invite_primary_function_id' => $functionService->primaryFunctionIdForDefaults($workspaceId, $functionIds),
            'invite_function_assignment_types' => $functionService->defaultAssignmentTypesForRole($functionIds, $roleSlug, $isOwner),
        ];
    }
}
