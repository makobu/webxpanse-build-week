<?php

namespace CRM\Services;

use CRM\Auth;
use CRM\Database;

class WorkspaceGovernanceService
{
    private const OWNER_ROLE = 'owner';
    private const DEFAULT_INVITE_TTL_DAYS = 7;
    private const DEFAULT_HISTORY_LIMIT = 20;
    private const ALLOWED_MEMBER_ROLES = ['owner', 'admin', 'accountant', 'expert', 'viewer'];
    private const ALLOWED_MEMBER_STATUSES = ['active', 'suspended', 'left'];
    private const HISTORY_FILTERS = ['all', 'invites', 'members', 'ownership', 'slug'];

    private WorkspaceMembershipService $memberships;
    private WorkspaceService $workspaces;
    private OperatorAuditService $operatorAudit;
    private WorkspaceGovernanceEventLogService $eventLog;
    private WorkspaceInviteMailer $inviteMailer;
    private WorkspaceLaunchReadinessService $readiness;

    public function __construct(
        ?WorkspaceMembershipService $memberships = null,
        ?WorkspaceService $workspaces = null,
        ?OperatorAuditService $operatorAudit = null,
        ?WorkspaceGovernanceEventLogService $eventLog = null,
        ?WorkspaceInviteMailer $inviteMailer = null,
        ?WorkspaceLaunchReadinessService $readiness = null
    ) {
        $this->memberships = $memberships ?? new WorkspaceMembershipService();
        $this->workspaces = $workspaces ?? new WorkspaceService();
        $this->operatorAudit = $operatorAudit ?? new OperatorAuditService();
        $this->eventLog = $eventLog ?? new WorkspaceGovernanceEventLogService();
        $this->inviteMailer = $inviteMailer ?? new WorkspaceInviteMailer();
        $this->readiness = $readiness ?? new WorkspaceLaunchReadinessService();
    }

    public function getWorkspaceGovernanceData(int $workspaceId, int $actorUserId, ?string $historyFilter = null): array
    {
        $this->assertGovernanceReady();
        $actorMembership = $this->requireManagerMembership($workspaceId, $actorUserId);
        return $this->buildGovernanceData($workspaceId, $actorMembership, $historyFilter);
    }

    public function getWorkspaceGovernanceDataForPlatformAdmin(int $workspaceId, ?string $historyFilter = null): array
    {
        $this->assertGovernanceReady();
        return $this->buildGovernanceData($workspaceId, null, $historyFilter);
    }

    public function countPendingInvites(int $workspaceId): int
    {
        if ($workspaceId <= 0) {
            return 0;
        }

        if (!$this->readiness->checkWorkspaceSaasReadiness('workspace_governance')['ready']) {
            return 0;
        }

        return (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM workspace_invites
             WHERE workspace_id = ?
               AND invite_status = 'pending'
               AND expires_at > NOW()",
            [$workspaceId]
        )['c'] ?? 0);
    }

    public function createInvite(
        int $workspaceId,
        int $actorUserId,
        string $email,
        string $roleSlug = 'viewer',
        array $functionIds = [],
        int $primaryFunctionId = 0,
        array $functionAssignmentTypes = []
    ): array
    {
        $this->assertGovernanceReady();
        (new PresentationWorkspaceGuardService())->assertAllowed('invites', $workspaceId);
        $actorMembership = $this->requireManagerMembership($workspaceId, $actorUserId);
        $roleSlug = $this->normalizeRoleSlug($roleSlug);
        $this->assertRoleCanBeManagedByActor($actorMembership, $roleSlug, false);

        $email = $this->normalizeEmail($email);
        if ($email === '') {
            throw new \RuntimeException('Invite email is required.');
        }

        $existingUser = $this->findUserByEmail($email);
        if ($existingUser !== null) {
            $existingMembership = $this->memberships->getMembership($workspaceId, (int) ($existingUser['id'] ?? 0));
            if ($existingMembership !== null && (string) ($existingMembership['membership_status'] ?? '') === 'active') {
                throw new \RuntimeException('That user already belongs to this workspace.');
            }
        }
        $this->assertSeatCapacityForInvite($workspaceId, $email);
        $functionAssignments = $this->resolveFunctionAssignmentRequest(
            $workspaceId,
            $roleSlug,
            $roleSlug === self::OWNER_ROLE,
            $functionIds,
            $primaryFunctionId,
            $functionAssignmentTypes,
            true
        );

        $token = bin2hex(random_bytes(24));
        $tokenHash = hash('sha256', $token);
        $expiresAt = date('Y-m-d H:i:s', strtotime('+' . self::DEFAULT_INVITE_TTL_DAYS . ' days'));
        $inviteId = 0;

        Database::beginTransaction();
        try {
            $existingInvite = Database::queryOne(
                "SELECT id
                 FROM workspace_invites
                 WHERE workspace_id = ?
                   AND LOWER(TRIM(email)) = ?
                   AND invite_status IN ('pending', 'revoked', 'expired')
                 ORDER BY id DESC
                 LIMIT 1",
                [$workspaceId, $email]
            );

            if ($existingInvite !== null) {
                $inviteId = (int) $existingInvite['id'];
                Database::execute(
                    "UPDATE workspace_invites
                    SET role_slug = ?,
                        token_hash = ?,
                        invite_status = 'pending',
                        delivery_status = 'pending',
                        delivery_error = NULL,
                        last_delivery_attempt_at = NULL,
                        invited_by = ?,
                        expires_at = ?,
                        accepted_at = NULL,
                         updated_at = NOW()
                     WHERE id = ?",
                    [$roleSlug, $tokenHash, $actorUserId, $expiresAt, $inviteId]
                );
            } else {
                Database::execute(
                    "INSERT INTO workspace_invites (workspace_id, email, role_slug, token_hash, invite_status, invited_by, expires_at)
                     VALUES (?, ?, ?, ?, 'pending', ?, ?)",
                    [$workspaceId, $email, $roleSlug, $tokenHash, $actorUserId, $expiresAt]
                );
                $inviteId = (int) Database::lastInsertId();
            }

            $this->saveInviteFunctionAssignments($workspaceId, $inviteId, $functionAssignments);

            $this->eventLog->log(
                $workspaceId,
                'invite_created',
                $actorUserId,
                (int) ($existingUser['id'] ?? 0) ?: null,
                $inviteId,
                [
                    'email' => $email,
                    'role_slug' => $roleSlug,
                    'expires_at' => $expiresAt,
                    'function_ids' => array_values(array_map(static fn(array $assignment): int => (int) $assignment['function_id'], $functionAssignments)),
                ]
            );

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        $invite = $this->requireInviteRow($workspaceId, $inviteId);
        $inviteUrl = $this->buildInviteUrl($token);
        $delivery = $this->attemptInviteDelivery($invite, $inviteUrl, $actorUserId, 'create');
        $invite = $this->requireInviteRow($workspaceId, $inviteId);

        return [
            'invite' => $invite,
            'token' => $token,
            'invite_url' => $inviteUrl,
            'delivery' => $delivery,
        ];
    }

    public function resendInvite(int $workspaceId, int $inviteId, int $actorUserId, string $deliveryAction = 'resend'): array
    {
        $this->assertGovernanceReady();
        (new PresentationWorkspaceGuardService())->assertAllowed('invites', $workspaceId);
        $actorMembership = $this->requireManagerMembership($workspaceId, $actorUserId);
        $invite = $this->requireInviteRow($workspaceId, $inviteId);
        $this->assertInviteCanBeManagedByActor($actorMembership, $invite);

        if ((string) ($invite['invite_status'] ?? '') === 'accepted') {
            throw new \RuntimeException('Accepted invites cannot be resent.');
        }

        $token = bin2hex(random_bytes(24));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+' . self::DEFAULT_INVITE_TTL_DAYS . ' days'));

        Database::beginTransaction();
        try {
            Database::execute(
                "UPDATE workspace_invites
                 SET token_hash = ?,
                     invite_status = 'pending',
                     delivery_status = 'pending',
                     delivery_error = NULL,
                     last_delivery_attempt_at = NULL,
                     invited_by = ?,
                     expires_at = ?,
                     accepted_at = NULL,
                     updated_at = NOW()
                 WHERE id = ?
                   AND workspace_id = ?",
                [
                    hash('sha256', $token),
                    $actorUserId,
                    $expiresAt,
                    $inviteId,
                    $workspaceId,
                ]
            );

            $this->eventLog->log(
                $workspaceId,
                'invite_resent',
                $actorUserId,
                null,
                $inviteId,
                [
                    'email' => (string) ($invite['email'] ?? ''),
                    'role_slug' => (string) ($invite['role_slug'] ?? 'viewer'),
                    'expires_at' => $expiresAt,
                    'delivery_action' => $deliveryAction,
                ]
            );

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        $updatedInvite = $this->requireInviteRow($workspaceId, $inviteId);
        $inviteUrl = $this->buildInviteUrl($token);
        $delivery = $this->attemptInviteDelivery($updatedInvite, $inviteUrl, $actorUserId, $deliveryAction);
        $updatedInvite = $this->requireInviteRow($workspaceId, $inviteId);

        return [
            'invite' => $updatedInvite,
            'token' => $token,
            'invite_url' => $inviteUrl,
            'delivery' => $delivery,
        ];
    }

    public function retryInviteDelivery(int $workspaceId, int $inviteId, int $actorUserId): array
    {
        $this->assertGovernanceReady();
        $invite = $this->requireInviteRow($workspaceId, $inviteId);
        if ((string) ($invite['invite_status'] ?? '') !== 'pending') {
            throw new \RuntimeException('Only pending workspace invites can retry delivery.');
        }
        if ((string) ($invite['delivery_status'] ?? 'pending') !== 'failed') {
            throw new \RuntimeException('Only failed workspace invite deliveries can be retried.');
        }

        return $this->resendInvite($workspaceId, $inviteId, $actorUserId, 'retry_delivery');
    }

    public function revokeInvite(int $workspaceId, int $inviteId, int $actorUserId): array
    {
        $this->assertGovernanceReady();
        $actorMembership = $this->requireManagerMembership($workspaceId, $actorUserId);
        $invite = $this->requireInviteRow($workspaceId, $inviteId);
        $this->assertInviteCanBeManagedByActor($actorMembership, $invite);

        if ((string) ($invite['invite_status'] ?? '') === 'accepted') {
            throw new \RuntimeException('Accepted invites cannot be revoked.');
        }

        Database::beginTransaction();
        try {
            Database::execute(
                "UPDATE workspace_invites
                 SET invite_status = 'revoked',
                     updated_at = NOW()
                 WHERE id = ?
                   AND workspace_id = ?",
                [$inviteId, $workspaceId]
            );

            $this->eventLog->log(
                $workspaceId,
                'invite_revoked',
                $actorUserId,
                null,
                $inviteId,
                [
                    'email' => (string) ($invite['email'] ?? ''),
                    'role_slug' => (string) ($invite['role_slug'] ?? 'viewer'),
                ]
            );

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        return $this->requireInviteRow($workspaceId, $inviteId);
    }

    public function getInvitePreview(string $token): ?array
    {
        $this->assertGovernanceReady();
        $invite = $this->findInviteByToken($token);
        if ($invite === null) {
            return null;
        }

        $invite = $this->normalizeInviteRow($this->expireInviteIfNeeded($invite));
        $invite['function_assignments'] = $this->inviteFunctionAssignments((int) ($invite['workspace_id'] ?? 0), (int) ($invite['id'] ?? 0));
        $invite['account_exists'] = $this->findUserByEmail((string) ($invite['email'] ?? '')) !== null;
        $invite['is_valid'] = (string) ($invite['invite_status'] ?? '') === 'pending';

        return $invite;
    }

    public function acceptInviteForUser(string $token, int $userId): array
    {
        $this->assertGovernanceReady();
        if ($userId <= 0) {
            throw new \RuntimeException('A signed-in user is required to accept this invite.');
        }

        $user = Database::queryOne(
            "SELECT id, email
             FROM users
             WHERE id = ?
             LIMIT 1",
            [$userId]
        );
        if ($user === null) {
            throw new \RuntimeException('The selected user account was not found.');
        }

        return $this->acceptInvite($token, (string) ($user['email'] ?? ''), $userId);
    }

    public function acceptInviteForNewUser(
        string $token,
        string $password,
        ?string $firstName = null,
        ?string $lastName = null
    ): array {
        $this->assertGovernanceReady();
        $invite = $this->getInvitePreview($token);
        if ($invite === null) {
            throw new \RuntimeException('This invite could not be found.');
        }
        if (!empty($invite['account_exists'])) {
            throw new \RuntimeException('An account already exists for this invite email. Please sign in first.');
        }
        if ((string) ($invite['invite_status'] ?? '') !== 'pending') {
            throw new \RuntimeException($this->inviteStatusMessage((string) ($invite['invite_status'] ?? 'invalid')));
        }

        $email = (string) ($invite['email'] ?? '');
        $userId = (int) (Auth::createUser($email, $password, 'viewer', $firstName, $lastName) ?? 0);
        if ($userId <= 0) {
            throw new \RuntimeException('We could not create the invited account.');
        }

        $accepted = $this->acceptInvite($token, $email, $userId);
        $accepted['created_user_id'] = $userId;
        $accepted['email'] = $email;

        return $accepted;
    }

    public function updateMemberRole(int $workspaceId, int $membershipId, int $actorUserId, string $newRoleSlug): array
    {
        $this->assertGovernanceReady();
        (new PresentationWorkspaceGuardService())->assertAllowed('user_management', $workspaceId);
        $actorMembership = $this->requireManagerMembership($workspaceId, $actorUserId);
        $membership = $this->requireMembershipRow($workspaceId, $membershipId);
        $newRoleSlug = $this->normalizeRoleSlug($newRoleSlug);

        $this->assertMembershipCanBeManagedByActor($actorMembership, $membership, $newRoleSlug);
        $this->assertNotLastOwnerChange($workspaceId, $membership, $newRoleSlug, (string) ($membership['membership_status'] ?? 'active'));

        Database::beginTransaction();
        try {
            Database::execute(
                "UPDATE workspace_memberships
                 SET role_slug = ?,
                     is_owner = ?,
                     updated_at = NOW()
                 WHERE id = ?
                   AND workspace_id = ?",
                [$newRoleSlug, $newRoleSlug === self::OWNER_ROLE ? 1 : 0, $membershipId, $workspaceId]
            );
            if ($newRoleSlug === self::OWNER_ROLE) {
                (new OrganizationFunctionService())->ensureOwnerFunctionCoverage($workspaceId, (int) ($membership['user_id'] ?? 0));
            }

            $updated = $this->requireMembershipRow($workspaceId, $membershipId);
            $this->eventLog->log(
                $workspaceId,
                'member_role_changed',
                $actorUserId,
                (int) ($membership['user_id'] ?? 0),
                null,
                [
                    'membership_id' => $membershipId,
                    'previous_role' => (string) ($membership['role_slug'] ?? ''),
                    'new_role' => (string) ($updated['role_slug'] ?? ''),
                ]
            );

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        $this->refreshWorkspaceSessionForUserIfCurrent($workspaceId, (int) ($membership['user_id'] ?? 0));
        $this->notifyDefaultWorkspaceLifecycle($workspaceId, $actorUserId, 'member_role_changed');
        return $updated;
    }

    public function assignMemberWorkOwnership(
        int $workspaceId,
        int $membershipId,
        int $actorUserId,
        int $functionId,
        string $assignmentType = 'contributor',
        bool $makePrimary = false
    ): array {
        $this->assertGovernanceReady();
        (new PresentationWorkspaceGuardService())->assertAllowed('user_management', $workspaceId);
        $actorMembership = $this->requireManagerMembership($workspaceId, $actorUserId);
        $membership = $this->requireMembershipRow($workspaceId, $membershipId);
        $this->assertMembershipCanBeManagedByActor($actorMembership, $membership, (string) ($membership['role_slug'] ?? 'viewer'));
        if ((string) ($membership['membership_status'] ?? '') !== 'active') {
            throw new \RuntimeException('Work Ownership can only be assigned to active members.');
        }

        $functionService = new OrganizationFunctionService();
        $functionService->upsertUserAssignment(
            $workspaceId,
            (int) ($membership['user_id'] ?? 0),
            $functionId,
            $assignmentType,
            $makePrimary
        );

        $function = Database::queryOne(
            "SELECT name, slug
             FROM organization_functions
             WHERE workspace_id = ?
               AND id = ?
             LIMIT 1",
            [$workspaceId, $functionId]
        ) ?? [];

        $this->eventLog->log(
            $workspaceId,
            'member_work_ownership_assigned',
            $actorUserId,
            (int) ($membership['user_id'] ?? 0),
            null,
            [
                'membership_id' => $membershipId,
                'function_id' => $functionId,
                'function_name' => (string) ($function['name'] ?? 'Work Ownership'),
                'function_slug' => (string) ($function['slug'] ?? ''),
                'assignment_type' => $assignmentType,
                'is_primary' => $makePrimary,
            ]
        );

        $this->notifyDefaultWorkspaceLifecycle($workspaceId, $actorUserId, 'member_work_ownership_assigned');
        $updated = $this->requireMembershipRow($workspaceId, $membershipId);
        $updated['function_assignments'] = $functionService->assignmentsForUser($workspaceId, (int) ($membership['user_id'] ?? 0), true);
        return $updated;
    }

    public function setMemberStatus(int $workspaceId, int $membershipId, int $actorUserId, string $newStatus): array
    {
        $this->assertGovernanceReady();
        (new PresentationWorkspaceGuardService())->assertAllowed('user_management', $workspaceId);
        $actorMembership = $this->requireManagerMembership($workspaceId, $actorUserId);
        $updated = $this->setMemberStatusInternal($workspaceId, $membershipId, $actorMembership, $newStatus, $actorUserId, false, null);
        $this->notifyDefaultWorkspaceLifecycle($workspaceId, $actorUserId, 'member_status_changed');
        return $updated;
    }

    public function removeMember(int $workspaceId, int $membershipId, int $actorUserId): array
    {
        $this->assertGovernanceReady();
        (new PresentationWorkspaceGuardService())->assertAllowed('user_management', $workspaceId);
        return $this->setMemberStatus($workspaceId, $membershipId, $actorUserId, 'left');
    }

    public function setPrimaryWorkspaceSlug(int $workspaceId, int $actorUserId, string $slug): array
    {
        $this->assertGovernanceReady();
        $actorMembership = $this->requireManagerMembership($workspaceId, $actorUserId);
        $result = $this->setPrimaryWorkspaceSlugInternal($workspaceId, $slug, $actorMembership);

        $this->eventLog->log(
            $workspaceId,
            'primary_slug_changed',
            $actorUserId,
            null,
            null,
            [
                'previous_slug' => (string) ($result['previous_slug'] ?? ''),
                'new_slug' => (string) ($result['workspace']['slug'] ?? ''),
            ]
        );

        $this->refreshWorkspaceContextForActiveSession($workspaceId);
        return $result;
    }

    public function transferOwnership(
        int $workspaceId,
        int $actorUserId,
        int $targetMembershipId,
        bool $retainActorOwnership = false,
        string $demotedActorRole = 'admin'
    ): array {
        $this->assertGovernanceReady();
        $actorMembership = $this->requireManagerMembership($workspaceId, $actorUserId);
        if (!$this->isOwnerMembership($actorMembership)) {
            throw new \RuntimeException('Only workspace owners can transfer ownership.');
        }

        $targetMembership = $this->requireMembershipRow($workspaceId, $targetMembershipId);
        if ((string) ($targetMembership['membership_status'] ?? '') !== 'active') {
            throw new \RuntimeException('Ownership can only be transferred to an active workspace member.');
        }
        if ((int) ($targetMembership['user_id'] ?? 0) === $actorUserId && !$retainActorOwnership) {
            throw new \RuntimeException('Choose a different member to transfer ownership to.');
        }

        $demotedActorRole = $retainActorOwnership ? self::OWNER_ROLE : $this->normalizeNonOwnerRole($demotedActorRole);
        $actorMembershipId = (int) ($actorMembership['id'] ?? 0);

        Database::beginTransaction();
        try {
            Database::execute(
                "UPDATE workspace_memberships
                 SET role_slug = 'owner',
                     is_owner = 1,
                     membership_status = 'active',
                     joined_at = COALESCE(joined_at, NOW()),
                     updated_at = NOW()
                 WHERE id = ?
                   AND workspace_id = ?",
                [$targetMembershipId, $workspaceId]
            );
            (new OrganizationFunctionService())->ensureOwnerFunctionCoverage($workspaceId, (int) ($targetMembership['user_id'] ?? 0));

            if (!$retainActorOwnership) {
                Database::execute(
                    "UPDATE workspace_memberships
                     SET role_slug = ?,
                         is_owner = 0,
                         updated_at = NOW()
                     WHERE id = ?
                       AND workspace_id = ?",
                    [$demotedActorRole, $actorMembershipId, $workspaceId]
                );
            }

            $updatedActorMembership = $this->requireMembershipRow($workspaceId, $actorMembershipId);
            $updatedTargetMembership = $this->requireMembershipRow($workspaceId, $targetMembershipId);

            $this->eventLog->log(
                $workspaceId,
                'ownership_transferred',
                $actorUserId,
                (int) ($targetMembership['user_id'] ?? 0),
                null,
                [
                    'source_membership_id' => $actorMembershipId,
                    'target_membership_id' => $targetMembershipId,
                    'source_previous_role' => (string) ($actorMembership['role_slug'] ?? ''),
                    'source_new_role' => (string) ($updatedActorMembership['role_slug'] ?? ''),
                    'target_previous_role' => (string) ($targetMembership['role_slug'] ?? ''),
                    'target_new_role' => (string) ($updatedTargetMembership['role_slug'] ?? ''),
                    'retain_actor_ownership' => $retainActorOwnership,
                ]
            );

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        $this->refreshWorkspaceSessionForUserIfCurrent($workspaceId, $actorUserId);
        $this->refreshWorkspaceSessionForUserIfCurrent($workspaceId, (int) ($targetMembership['user_id'] ?? 0));
        $this->notifyDefaultWorkspaceLifecycle($workspaceId, $actorUserId, 'ownership_transferred');

        return [
            'source_membership' => $updatedActorMembership,
            'target_membership' => $updatedTargetMembership,
            'retain_actor_ownership' => $retainActorOwnership,
        ];
    }

    public function platformUpdateMemberRole(
        int $workspaceId,
        int $membershipId,
        string $newRoleSlug,
        int $actorUserId,
        string $reason
    ): array {
        $this->assertGovernanceReady();
        (new PresentationWorkspaceGuardService())->assertAllowed('user_management', $workspaceId);
        $membership = $this->requireMembershipRow($workspaceId, $membershipId);
        $newRoleSlug = $this->normalizeRoleSlug($newRoleSlug);
        $this->assertNotLastOwnerChange($workspaceId, $membership, $newRoleSlug, (string) ($membership['membership_status'] ?? 'active'));

        Database::beginTransaction();
        try {
            Database::execute(
                "UPDATE workspace_memberships
                 SET role_slug = ?,
                     is_owner = ?,
                     updated_at = NOW()
                 WHERE id = ?
                   AND workspace_id = ?",
                [$newRoleSlug, $newRoleSlug === self::OWNER_ROLE ? 1 : 0, $membershipId, $workspaceId]
            );
            if ($newRoleSlug === self::OWNER_ROLE) {
                (new OrganizationFunctionService())->ensureOwnerFunctionCoverage($workspaceId, (int) ($membership['user_id'] ?? 0));
            }

            $updated = $this->requireMembershipRow($workspaceId, $membershipId);
            $this->eventLog->log(
                $workspaceId,
                'member_role_changed',
                $actorUserId,
                (int) ($membership['user_id'] ?? 0),
                null,
                [
                    'membership_id' => $membershipId,
                    'previous_role' => (string) ($membership['role_slug'] ?? ''),
                    'new_role' => $newRoleSlug,
                    'source' => 'platform_override',
                    'reason' => $reason,
                ]
            );

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        $this->operatorAudit->log(
            'workspace_member_role_override',
            $actorUserId,
            $workspaceId,
            $reason,
            [
                'membership_id' => $membershipId,
                'previous_role' => (string) ($membership['role_slug'] ?? ''),
                'new_role' => $newRoleSlug,
            ],
            (int) ($membership['user_id'] ?? 0)
        );

        $this->refreshWorkspaceSessionForUserIfCurrent($workspaceId, (int) ($membership['user_id'] ?? 0));
        $this->notifyDefaultWorkspaceLifecycle($workspaceId, $actorUserId, 'platform_member_role_changed');
        return $updated;
    }

    public function platformSetMemberStatus(
        int $workspaceId,
        int $membershipId,
        string $newStatus,
        int $actorUserId,
        string $reason
    ): array {
        $this->assertGovernanceReady();
        (new PresentationWorkspaceGuardService())->assertAllowed('user_management', $workspaceId);
        $membership = $this->requireMembershipRow($workspaceId, $membershipId);
        $updated = $this->setMemberStatusInternal($workspaceId, $membershipId, null, $newStatus, $actorUserId, true, $reason);

        $this->operatorAudit->log(
            'workspace_member_status_override',
            $actorUserId,
            $workspaceId,
            $reason,
            [
                'membership_id' => $membershipId,
                'previous_status' => (string) ($membership['membership_status'] ?? ''),
                'new_status' => (string) ($updated['membership_status'] ?? ''),
            ],
            (int) ($membership['user_id'] ?? 0)
        );

        $this->notifyDefaultWorkspaceLifecycle($workspaceId, $actorUserId, 'platform_member_status_changed');
        return $updated;
    }

    public function platformRevokeInvite(int $workspaceId, int $inviteId, int $actorUserId, string $reason): array
    {
        $this->assertGovernanceReady();
        $invite = $this->requireInviteRow($workspaceId, $inviteId);
        if ((string) ($invite['invite_status'] ?? '') === 'accepted') {
            throw new \RuntimeException('Accepted invites cannot be revoked.');
        }

        Database::beginTransaction();
        try {
            Database::execute(
                "UPDATE workspace_invites
                 SET invite_status = 'revoked',
                     updated_at = NOW()
                 WHERE id = ?
                   AND workspace_id = ?",
                [$inviteId, $workspaceId]
            );

            $this->eventLog->log(
                $workspaceId,
                'invite_revoked',
                $actorUserId,
                null,
                $inviteId,
                [
                    'email' => (string) ($invite['email'] ?? ''),
                    'role_slug' => (string) ($invite['role_slug'] ?? 'viewer'),
                    'source' => 'platform_override',
                    'reason' => $reason,
                ]
            );

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        $updated = $this->requireInviteRow($workspaceId, $inviteId);
        $this->operatorAudit->log(
            'workspace_invite_revoke_override',
            $actorUserId,
            $workspaceId,
            $reason,
            [
                'invite_id' => $inviteId,
                'invite_email' => (string) ($invite['email'] ?? ''),
            ]
        );

        return $updated;
    }

    public function platformSetPrimaryWorkspaceSlug(int $workspaceId, string $slug, int $actorUserId, string $reason): array
    {
        $this->assertGovernanceReady();
        $previous = $this->requireWorkspace($workspaceId);
        $result = $this->setPrimaryWorkspaceSlugInternal($workspaceId, $slug, null);

        $this->eventLog->log(
            $workspaceId,
            'primary_slug_changed',
            $actorUserId,
            null,
            null,
            [
                'previous_slug' => (string) ($previous['slug'] ?? ''),
                'new_slug' => (string) ($result['workspace']['slug'] ?? ''),
                'source' => 'platform_override',
                'reason' => $reason,
            ]
        );

        $this->operatorAudit->log(
            'workspace_slug_override',
            $actorUserId,
            $workspaceId,
            $reason,
            [
                'previous_slug' => (string) ($previous['slug'] ?? ''),
                'new_slug' => (string) ($result['workspace']['slug'] ?? ''),
            ]
        );

        $this->refreshWorkspaceContextForActiveSession($workspaceId);
        return $result;
    }

    public function platformTransferOwnership(
        int $workspaceId,
        int $targetMembershipId,
        int $actorUserId,
        string $reason,
        ?int $sourceMembershipId = null,
        bool $retainExistingOwners = false,
        string $demotedSourceRole = 'admin'
    ): array {
        $this->assertGovernanceReady();
        (new PresentationWorkspaceGuardService())->assertAllowed('user_management', $workspaceId);
        $targetMembership = $this->requireMembershipRow($workspaceId, $targetMembershipId);
        if ((string) ($targetMembership['membership_status'] ?? '') !== 'active') {
            throw new \RuntimeException('Ownership can only be transferred to an active workspace member.');
        }

        $sourceMembership = null;
        if (!$retainExistingOwners) {
            if (($sourceMembershipId ?? 0) <= 0) {
                throw new \RuntimeException('Choose the current owner membership to transfer from, or keep existing owners.');
            }

            $sourceMembership = $this->requireMembershipRow($workspaceId, (int) $sourceMembershipId);
            if (!$this->isOwnerMembership($sourceMembership)) {
                throw new \RuntimeException('The selected source membership is not an active workspace owner.');
            }
            if ((int) ($sourceMembership['id'] ?? 0) === $targetMembershipId) {
                throw new \RuntimeException('Choose a different owner membership to transfer from.');
            }
            $demotedSourceRole = $this->normalizeNonOwnerRole($demotedSourceRole);
        } else {
            $demotedSourceRole = self::OWNER_ROLE;
        }

        Database::beginTransaction();
        try {
            Database::execute(
                "UPDATE workspace_memberships
                 SET role_slug = 'owner',
                     is_owner = 1,
                     membership_status = 'active',
                     joined_at = COALESCE(joined_at, NOW()),
                     updated_at = NOW()
                 WHERE id = ?
                   AND workspace_id = ?",
                [$targetMembershipId, $workspaceId]
            );
            (new OrganizationFunctionService())->ensureOwnerFunctionCoverage($workspaceId, (int) ($targetMembership['user_id'] ?? 0));

            if ($sourceMembership !== null) {
                Database::execute(
                    "UPDATE workspace_memberships
                     SET role_slug = ?,
                         is_owner = 0,
                         updated_at = NOW()
                     WHERE id = ?
                       AND workspace_id = ?",
                    [$demotedSourceRole, (int) ($sourceMembership['id'] ?? 0), $workspaceId]
                );
            }

            $updatedTarget = $this->requireMembershipRow($workspaceId, $targetMembershipId);
            $updatedSource = $sourceMembership !== null
                ? $this->requireMembershipRow($workspaceId, (int) ($sourceMembership['id'] ?? 0))
                : null;

            $this->eventLog->log(
                $workspaceId,
                'ownership_transferred',
                $actorUserId,
                (int) ($targetMembership['user_id'] ?? 0),
                null,
                [
                    'source_membership_id' => (int) ($sourceMembership['id'] ?? 0) ?: null,
                    'target_membership_id' => $targetMembershipId,
                    'source_previous_role' => (string) ($sourceMembership['role_slug'] ?? ''),
                    'source_new_role' => (string) ($updatedSource['role_slug'] ?? ''),
                    'target_previous_role' => (string) ($targetMembership['role_slug'] ?? ''),
                    'target_new_role' => (string) ($updatedTarget['role_slug'] ?? ''),
                    'retain_actor_ownership' => $retainExistingOwners,
                    'source' => 'platform_override',
                    'reason' => $reason,
                ]
            );

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        $this->operatorAudit->log(
            'workspace_ownership_transfer_override',
            $actorUserId,
            $workspaceId,
            $reason,
            [
                'source_membership_id' => (int) ($sourceMembership['id'] ?? 0) ?: null,
                'target_membership_id' => $targetMembershipId,
                'retain_existing_owners' => $retainExistingOwners,
            ],
            (int) ($targetMembership['user_id'] ?? 0)
        );

        $this->refreshWorkspaceSessionForUserIfCurrent($workspaceId, (int) ($targetMembership['user_id'] ?? 0));
        if ($sourceMembership !== null) {
            $this->refreshWorkspaceSessionForUserIfCurrent($workspaceId, (int) ($sourceMembership['user_id'] ?? 0));
        }

        return [
            'source_membership' => $updatedSource,
            'target_membership' => $updatedTarget,
            'retain_existing_owners' => $retainExistingOwners,
        ];
    }

    private function acceptInvite(string $token, string $expectedEmail, int $userId): array
    {
        $invite = $this->findInviteByToken($token);
        if ($invite === null) {
            throw new \RuntimeException('This invite could not be found.');
        }

        $invite = $this->expireInviteIfNeeded($invite);
        $expectedEmail = $this->normalizeEmail($expectedEmail);
        if ($expectedEmail === '' || $expectedEmail !== $this->normalizeEmail((string) ($invite['email'] ?? ''))) {
            throw new \RuntimeException('This invite was sent to a different email address.');
        }

        $workspaceId = 0;
        Database::beginTransaction();
        try {
            $currentInvite = Database::queryOne(
                "SELECT *
                 FROM workspace_invites
                 WHERE id = ?
                 LIMIT 1
                 FOR UPDATE",
                [(int) ($invite['id'] ?? 0)]
            );
            if ($currentInvite === null) {
                throw new \RuntimeException('This invite could not be found.');
            }

            $currentInvite = $this->expireInviteIfNeeded($currentInvite);
            $status = (string) ($currentInvite['invite_status'] ?? 'pending');
            $workspaceId = (int) ($currentInvite['workspace_id'] ?? 0);

            if ($status === 'accepted') {
                $membership = $this->memberships->getMembership($workspaceId, $userId);
                if ($membership !== null) {
                    Database::commit();
                    WorkspaceContext::activateWorkspaceById($userId, $workspaceId);

                    return [
                        'workspace_id' => $workspaceId,
                        'membership' => $membership,
                        'invite' => $currentInvite,
                        'already_accepted' => true,
                    ];
                }

                throw new \RuntimeException('This invite has already been accepted.');
            }

            if ($status !== 'pending') {
                throw new \RuntimeException($this->inviteStatusMessage($status));
            }

            $membershipId = $this->memberships->addOrUpdateMembership(
                $workspaceId,
                $userId,
                (string) ($currentInvite['role_slug'] ?? 'viewer'),
                (string) ($currentInvite['role_slug'] ?? 'viewer') === self::OWNER_ROLE,
                !empty($currentInvite['invited_by']) ? (int) $currentInvite['invited_by'] : null
            );
            $this->applyInviteFunctionAssignments(
                $workspaceId,
                (int) ($currentInvite['id'] ?? 0),
                $userId,
                (string) ($currentInvite['role_slug'] ?? 'viewer'),
                (string) ($currentInvite['role_slug'] ?? 'viewer') === self::OWNER_ROLE
            );

            Database::execute(
                "UPDATE workspace_invites
                 SET invite_status = 'accepted',
                     accepted_at = NOW(),
                     updated_at = NOW()
                 WHERE id = ?",
                [(int) ($currentInvite['id'] ?? 0)]
            );

            $membership = $this->requireMembershipRow($workspaceId, $membershipId);
            $acceptedInvite = $this->requireInviteById((int) ($currentInvite['id'] ?? 0));
            $this->eventLog->log(
                $workspaceId,
                'invite_accepted',
                $userId,
                $userId,
                (int) ($currentInvite['id'] ?? 0),
                [
                    'email' => (string) ($acceptedInvite['email'] ?? ''),
                    'role_slug' => (string) ($acceptedInvite['role_slug'] ?? 'viewer'),
                    'membership_id' => $membershipId,
                ]
            );

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        WorkspaceContext::activateWorkspaceById($userId, $workspaceId);

        return [
            'workspace_id' => $workspaceId,
            'membership' => $membership,
            'invite' => $acceptedInvite,
            'already_accepted' => false,
        ];
    }

    private function buildGovernanceData(int $workspaceId, ?array $actorMembership, ?string $historyFilter = null): array
    {
        $workspace = $this->requireWorkspace($workspaceId);
        $members = Database::query(
            "SELECT wm.*, u.uuid AS user_uuid, u.first_name, u.last_name, u.email,
                    d.name AS department_name,
                    d.slug AS department_slug,
                    COALESCE(wr.name, gr.name) AS access_role_name,
                    COALESCE(wr.slug, gr.slug) AS access_role_slug
             FROM workspace_memberships wm
             JOIN users u ON u.id = wm.user_id
             LEFT JOIN departments d
               ON d.id = wm.department_id
              AND d.workspace_id = wm.workspace_id
             LEFT JOIN workspace_user_roles wur
               ON wur.workspace_id = wm.workspace_id
              AND wur.user_id = wm.user_id
             LEFT JOIN roles wr ON wr.id = wur.role_id
             LEFT JOIN user_roles gur ON gur.user_id = wm.user_id
             LEFT JOIN roles gr ON gr.id = gur.role_id
             WHERE wm.workspace_id = ?
             ORDER BY wm.is_owner DESC, wm.membership_status ASC, u.email ASC",
            [$workspaceId]
        );
        $invites = Database::query(
            "SELECT wi.*
             FROM workspace_invites wi
             WHERE wi.workspace_id = ?
             ORDER BY CASE WHEN wi.invite_status = 'pending' THEN 0 ELSE 1 END, wi.id DESC",
            [$workspaceId]
        );
        $slugs = Database::query(
            "SELECT id, workspace_id, slug, is_primary, created_at
             FROM workspace_slugs
             WHERE workspace_id = ?
             ORDER BY is_primary DESC, id DESC",
            [$workspaceId]
        );

        $normalizedHistoryFilter = $this->normalizeHistoryFilter($historyFilter);
        $inviteAssignments = $this->inviteFunctionAssignmentsForInvites(
            $workspaceId,
            array_values(array_map(static fn(array $invite): int => (int) ($invite['id'] ?? 0), $invites))
        );
        $normalizedInvites = array_map(function (array $invite) use ($inviteAssignments): array {
            $invite = $this->normalizeInviteRow($invite);
            $invite['function_assignments'] = $inviteAssignments[(int) ($invite['id'] ?? 0)] ?? [];
            return $invite;
        }, $invites);
        $functionService = new OrganizationFunctionService();
        $availableFunctions = [];
        $functionDefaultsByRole = [];
        $memberFunctionAssignments = [];
        $workOwnershipCoverage = [
            'functions' => [],
            'active_function_count' => 0,
            'missing_primary_count' => 0,
            'assigned_member_count' => 0,
            'unassigned_active_members' => [],
        ];
        if ($functionService->tablesReady()) {
            $functionService->ensureDefaults($workspaceId);
            $availableFunctions = $functionService->listAssignableFunctions($workspaceId);
            $memberFunctionAssignments = $functionService->assignmentsForWorkspace($workspaceId, true);
            $workOwnershipCoverage = $functionService->coverageForWorkspace($workspaceId);
            foreach (self::ALLOWED_MEMBER_ROLES as $roleSlug) {
                $defaultFunctionIds = $functionService->defaultFunctionIdsForRole($workspaceId, $roleSlug, $roleSlug === self::OWNER_ROLE);
                $functionDefaultsByRole[$roleSlug] = [
                    'function_ids' => $defaultFunctionIds,
                    'primary_function_id' => $functionService->primaryFunctionIdForDefaults($workspaceId, $defaultFunctionIds),
                    'assignment_types' => $functionService->defaultAssignmentTypesForRole($defaultFunctionIds, $roleSlug, $roleSlug === self::OWNER_ROLE),
                ];
            }
        }

        return [
            'workspace' => $workspace,
            'members' => $members,
            'pending_invites' => array_values(array_filter($normalizedInvites, static function (array $invite): bool {
                return (string) ($invite['invite_status'] ?? '') === 'pending';
            })),
            'invites' => $normalizedInvites,
            'slugs' => $slugs,
            'slug_history' => $slugs,
            'history' => $this->eventLog->listForWorkspace($workspaceId, self::DEFAULT_HISTORY_LIMIT, $normalizedHistoryFilter),
            'history_filter' => $normalizedHistoryFilter,
            'history_filters' => self::HISTORY_FILTERS,
            'assignable_functions' => $availableFunctions,
            'function_defaults_by_role' => $functionDefaultsByRole,
            'member_function_assignments' => $memberFunctionAssignments,
            'work_ownership_coverage' => $workOwnershipCoverage,
            'capabilities' => [
                'can_manage_workspace' => $actorMembership === null ? true : $this->canManageMembership($actorMembership),
                'can_manage_invites' => $actorMembership === null ? true : $this->canManageMembership($actorMembership),
                'can_manage_member_roles' => $actorMembership === null ? true : $this->canManageMembership($actorMembership),
                'can_manage_member_statuses' => $actorMembership === null ? true : $this->canManageMembership($actorMembership),
                'can_manage_owners' => $actorMembership === null ? true : $this->isOwnerMembership($actorMembership),
                'can_transfer_ownership' => $actorMembership === null ? true : $this->isOwnerMembership($actorMembership),
                'current_membership_role' => (string) ($actorMembership['role_slug'] ?? ''),
                'current_membership_status' => (string) ($actorMembership['membership_status'] ?? ''),
            ],
            'actor_membership' => $actorMembership,
            'pending_invite_count' => $this->countPendingInvites($workspaceId),
        ];
    }

    private function requireManagerMembership(int $workspaceId, int $actorUserId): array
    {
        $membership = $this->memberships->getMembership($workspaceId, $actorUserId);
        if (!$this->canManageMembership($membership)) {
            throw new \RuntimeException('Only workspace owners can manage this workspace.');
        }

        return $membership;
    }

    private function canManageMembership(?array $membership): bool
    {
        if ($membership === null) {
            return false;
        }

        return $this->isOwnerMembership($membership);
    }

    private function isOwnerMembership(?array $membership): bool
    {
        return $membership !== null
            && (string) ($membership['membership_status'] ?? '') === 'active'
            && (!empty($membership['is_owner']) || (string) ($membership['role_slug'] ?? 'viewer') === self::OWNER_ROLE);
    }

    private function assertRoleCanBeManagedByActor(array $actorMembership, string $roleSlug, bool $targetIsOwner): void
    {
        if (($roleSlug === self::OWNER_ROLE || $targetIsOwner) && !$this->isOwnerMembership($actorMembership)) {
            throw new \RuntimeException('Only workspace owners can manage owner access.');
        }
    }

    private function assertInviteCanBeManagedByActor(array $actorMembership, array $invite): void
    {
        $this->assertRoleCanBeManagedByActor(
            $actorMembership,
            (string) ($invite['role_slug'] ?? 'viewer'),
            (string) ($invite['role_slug'] ?? 'viewer') === self::OWNER_ROLE
        );
    }

    private function assertMembershipCanBeManagedByActor(array $actorMembership, array $membership, string $newRoleSlug): void
    {
        $targetIsOwner = $this->isOwnerMembership($membership);
        $this->assertRoleCanBeManagedByActor($actorMembership, $newRoleSlug, $targetIsOwner);
    }

    private function assertNotLastOwnerChange(
        int $workspaceId,
        array $membership,
        string $newRoleSlug,
        string $newStatus
    ): void {
        $currentlyOwner = $this->isOwnerMembership($membership);
        $willRemainOwner = $newRoleSlug === self::OWNER_ROLE && $newStatus === 'active';
        if (!$currentlyOwner || $willRemainOwner) {
            return;
        }

        $ownerCount = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM workspace_memberships
             WHERE workspace_id = ?
               AND membership_status = 'active'
               AND (is_owner = 1 OR role_slug = 'owner')",
            [$workspaceId]
        )['c'] ?? 0);

        if ($ownerCount <= 1) {
            throw new \RuntimeException('The last active workspace owner cannot be removed or demoted.');
        }
    }

    private function setMemberStatusInternal(
        int $workspaceId,
        int $membershipId,
        ?array $actorMembership,
        string $newStatus,
        ?int $actorUserId = null,
        bool $platformOverride = false,
        ?string $reason = null
    ): array {
        $newStatus = trim($newStatus);
        if (!in_array($newStatus, self::ALLOWED_MEMBER_STATUSES, true)) {
            throw new \RuntimeException('That membership status is not supported.');
        }

        $membership = $this->requireMembershipRow($workspaceId, $membershipId);
        if ($actorMembership !== null) {
            $this->assertMembershipCanBeManagedByActor($actorMembership, $membership, (string) ($membership['role_slug'] ?? 'viewer'));
        }
        $this->assertNotLastOwnerChange($workspaceId, $membership, (string) ($membership['role_slug'] ?? 'viewer'), $newStatus);

        Database::beginTransaction();
        try {
            Database::execute(
                "UPDATE workspace_memberships
                 SET membership_status = ?,
                     joined_at = CASE WHEN ? = 'active' THEN COALESCE(joined_at, NOW()) ELSE joined_at END,
                     updated_at = NOW()
                 WHERE id = ?
                   AND workspace_id = ?",
                [$newStatus, $newStatus, $membershipId, $workspaceId]
            );

            $updated = $this->requireMembershipRow($workspaceId, $membershipId);
            $metadata = [
                'membership_id' => $membershipId,
                'previous_status' => (string) ($membership['membership_status'] ?? ''),
                'new_status' => (string) ($updated['membership_status'] ?? ''),
            ];
            if ($platformOverride) {
                $metadata['source'] = 'platform_override';
                $metadata['reason'] = $reason;
            }
            if (
                (string) ($updated['membership_status'] ?? '') === 'active'
                && (!empty($updated['is_owner']) || (string) ($updated['role_slug'] ?? '') === self::OWNER_ROLE)
            ) {
                (new OrganizationFunctionService())->ensureOwnerFunctionCoverage($workspaceId, (int) ($membership['user_id'] ?? 0));
            }

            $this->eventLog->log(
                $workspaceId,
                $this->memberStatusEventType((string) ($updated['membership_status'] ?? 'active')),
                $actorUserId,
                (int) ($membership['user_id'] ?? 0),
                null,
                $metadata
            );

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        $this->refreshWorkspaceSessionForUserIfCurrent($workspaceId, (int) ($membership['user_id'] ?? 0));
        return $updated;
    }

    private function setPrimaryWorkspaceSlugInternal(int $workspaceId, string $slug, ?array $actorMembership): array
    {
        if ($actorMembership !== null && !$this->canManageMembership($actorMembership)) {
            throw new \RuntimeException('Only workspace owners can update the workspace slug.');
        }

        $workspace = $this->requireWorkspace($workspaceId);
        $slug = $this->workspaces->normalizeSlug($slug);
        if ($slug === '') {
            throw new \RuntimeException('Workspace slug is required.');
        }

        $conflict = Database::queryOne(
            "SELECT workspace_id
             FROM workspace_slugs
             WHERE slug = ?
             LIMIT 1",
            [$slug]
        );
        if ($conflict !== null && (int) ($conflict['workspace_id'] ?? 0) !== $workspaceId) {
            throw new \RuntimeException('That workspace slug is already taken.');
        }

        Database::beginTransaction();
        try {
            Database::execute(
                "UPDATE workspace_slugs
                 SET is_primary = 0
                 WHERE workspace_id = ?",
                [$workspaceId]
            );

            $existingSlug = Database::queryOne(
                "SELECT id
                 FROM workspace_slugs
                 WHERE workspace_id = ?
                   AND slug = ?
                 LIMIT 1",
                [$workspaceId, $slug]
            );

            if ($existingSlug !== null) {
                Database::execute(
                    "UPDATE workspace_slugs
                     SET is_primary = 1
                     WHERE id = ?",
                    [(int) $existingSlug['id']]
                );
            } else {
                Database::execute(
                    "INSERT INTO workspace_slugs (workspace_id, slug, is_primary)
                     VALUES (?, ?, 1)",
                    [$workspaceId, $slug]
                );
            }

            Database::execute(
                "UPDATE workspaces
                 SET slug = ?,
                     updated_at = NOW()
                 WHERE id = ?",
                [$slug, $workspaceId]
            );

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        return [
            'workspace' => $this->requireWorkspace($workspaceId),
            'slugs' => Database::query(
                "SELECT id, workspace_id, slug, is_primary, created_at
                 FROM workspace_slugs
                 WHERE workspace_id = ?
                 ORDER BY is_primary DESC, id DESC",
                [$workspaceId]
            ),
            'previous_slug' => (string) ($workspace['slug'] ?? ''),
        ];
    }

    private function requireWorkspace(int $workspaceId): array
    {
        $workspace = $this->workspaces->getById($workspaceId);
        if ($workspace === null) {
            throw new \RuntimeException('Workspace not found.');
        }

        return $workspace;
    }

    private function requireInviteRow(int $workspaceId, int $inviteId): array
    {
        $invite = Database::queryOne(
            "SELECT wi.*, w.name AS workspace_name, w.slug AS workspace_slug
             FROM workspace_invites wi
             JOIN workspaces w ON w.id = wi.workspace_id
             WHERE wi.id = ?
               AND wi.workspace_id = ?
             LIMIT 1",
            [$inviteId, $workspaceId]
        );
        if ($invite === null) {
            throw new \RuntimeException('Workspace invite not found.');
        }

        $invite = $this->normalizeInviteRow($this->expireInviteIfNeeded($invite));
        $invite['function_assignments'] = $this->inviteFunctionAssignments($workspaceId, $inviteId);

        return $invite;
    }

    private function requireInviteById(int $inviteId): array
    {
        $invite = Database::queryOne(
            "SELECT wi.*, w.name AS workspace_name, w.slug AS workspace_slug
             FROM workspace_invites wi
             JOIN workspaces w ON w.id = wi.workspace_id
             WHERE wi.id = ?
             LIMIT 1",
            [$inviteId]
        );
        if ($invite === null) {
            throw new \RuntimeException('Workspace invite not found.');
        }

        $invite = $this->normalizeInviteRow($this->expireInviteIfNeeded($invite));
        $invite['function_assignments'] = $this->inviteFunctionAssignments((int) ($invite['workspace_id'] ?? 0), $inviteId);

        return $invite;
    }

    private function saveInviteFunctionAssignments(int $workspaceId, int $inviteId, array $assignments): void
    {
        if ($workspaceId <= 0 || $inviteId <= 0 || !Database::tableExists('workspace_invite_function_assignments')) {
            return;
        }

        Database::execute(
            "DELETE FROM workspace_invite_function_assignments
             WHERE workspace_id = ?
               AND invite_id = ?",
            [$workspaceId, $inviteId]
        );

        foreach ($assignments as $assignment) {
            Database::execute(
                "INSERT INTO workspace_invite_function_assignments
                    (workspace_id, invite_id, function_id, assignment_type, importance, is_primary)
                 VALUES (?, ?, ?, ?, ?, ?)",
                [
                    $workspaceId,
                    $inviteId,
                    (int) ($assignment['function_id'] ?? 0),
                    (string) ($assignment['assignment_type'] ?? 'contributor'),
                    (string) ($assignment['importance'] ?? 'secondary'),
                    !empty($assignment['is_primary']) ? 1 : 0,
                ]
            );
        }
    }

    private function applyInviteFunctionAssignments(int $workspaceId, int $inviteId, int $userId, string $roleSlug, bool $isOwner): void
    {
        if ($workspaceId <= 0 || $inviteId <= 0 || $userId <= 0) {
            return;
        }

        $functionService = new OrganizationFunctionService();
        if (!$functionService->tablesReady()) {
            return;
        }

        $assignments = $this->inviteFunctionAssignments($workspaceId, $inviteId);
        if ($assignments === []) {
            if ($functionService->assignmentsForUser($workspaceId, $userId, true) === []) {
                $functionService->assignDefaultFunctionsForRole($workspaceId, $userId, $roleSlug, $isOwner);
            }
            if ($isOwner || $roleSlug === self::OWNER_ROLE) {
                $functionService->ensureOwnerFunctionCoverage($workspaceId, $userId);
            }
            return;
        }

        $functionIds = [];
        $assignmentTypes = [];
        $primaryFunctionId = 0;
        foreach ($assignments as $assignment) {
            $functionId = (int) ($assignment['function_id'] ?? 0);
            if ($functionId <= 0) {
                continue;
            }
            $functionIds[] = $functionId;
            $assignmentTypes[$functionId] = (string) ($assignment['assignment_type'] ?? 'contributor');
            if (!empty($assignment['is_primary'])) {
                $primaryFunctionId = $functionId;
            }
        }

        $functionService->saveUserAssignments($workspaceId, $userId, $functionIds, $primaryFunctionId, $assignmentTypes);
        if ($isOwner || $roleSlug === self::OWNER_ROLE) {
            $functionService->ensureOwnerFunctionCoverage($workspaceId, $userId);
        }
    }

    private function resolveFunctionAssignmentRequest(
        int $workspaceId,
        string $roleSlug,
        bool $isOwner,
        array $functionIds,
        int $primaryFunctionId,
        array $assignmentTypes,
        bool $useDefaultsWhenEmpty
    ): array {
        $functionService = new OrganizationFunctionService();
        if ($workspaceId <= 0 || !$functionService->tablesReady()) {
            return [];
        }

        $functionService->ensureDefaults($workspaceId);
        $availableFunctions = $functionService->listAssignableFunctions($workspaceId);
        if ($availableFunctions === []) {
            return [];
        }

        $allowedFunctionIds = [];
        foreach ($availableFunctions as $function) {
            $allowedFunctionIds[(int) ($function['id'] ?? 0)] = true;
        }

        $functionIds = array_values(array_unique(array_filter(array_map('intval', $functionIds), static fn(int $id): bool => $id > 0)));
        $functionIds = array_values(array_filter($functionIds, static fn(int $id): bool => isset($allowedFunctionIds[$id])));
        if ($functionIds === [] && $useDefaultsWhenEmpty) {
            $functionIds = $functionService->defaultFunctionIdsForRole($workspaceId, $roleSlug, $isOwner);
            $assignmentTypes = $functionService->defaultAssignmentTypesForRole($functionIds, $roleSlug, $isOwner);
            $primaryFunctionId = $functionService->primaryFunctionIdForDefaults($workspaceId, $functionIds);
        }
        if ($functionIds === []) {
            return [];
        }

        if ($primaryFunctionId <= 0 || !in_array($primaryFunctionId, $functionIds, true)) {
            $primaryFunctionId = $functionService->primaryFunctionIdForDefaults($workspaceId, $functionIds);
        }

        $assignments = [];
        foreach ($functionIds as $functionId) {
            $isPrimary = $functionId === $primaryFunctionId;
            $assignmentType = $this->normalizeAssignmentType((string) ($assignmentTypes[$functionId] ?? ($isPrimary ? 'owner' : 'contributor')));
            if ($isPrimary && $assignmentType === 'contributor') {
                $assignmentType = 'owner';
            }
            $assignments[] = [
                'function_id' => $functionId,
                'assignment_type' => $assignmentType,
                'importance' => $isPrimary ? 'primary' : 'secondary',
                'is_primary' => $isPrimary,
            ];
        }

        return $assignments;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function inviteFunctionAssignments(int $workspaceId, int $inviteId): array
    {
        if ($workspaceId <= 0 || $inviteId <= 0) {
            return [];
        }

        $assignments = $this->inviteFunctionAssignmentsForInvites($workspaceId, [$inviteId]);
        return $assignments[$inviteId] ?? [];
    }

    /**
     * @param list<int> $inviteIds
     * @return array<int,array<int,array<string,mixed>>>
     */
    private function inviteFunctionAssignmentsForInvites(int $workspaceId, array $inviteIds): array
    {
        $inviteIds = array_values(array_unique(array_filter(array_map('intval', $inviteIds), static fn(int $id): bool => $id > 0)));
        if ($workspaceId <= 0 || $inviteIds === [] || !Database::tableExists('workspace_invite_function_assignments')) {
            return [];
        }

        $rows = Database::query(
            "SELECT wifa.*, f.name, f.slug, f.description, f.category, f.measurement_strength
             FROM workspace_invite_function_assignments wifa
             JOIN organization_functions f
               ON f.id = wifa.function_id
              AND f.workspace_id = wifa.workspace_id
             WHERE wifa.workspace_id = ?
               AND wifa.invite_id IN (" . implode(',', array_fill(0, count($inviteIds), '?')) . ")
             ORDER BY wifa.is_primary DESC, f.name ASC",
            array_merge([$workspaceId], $inviteIds)
        );

        $out = [];
        foreach ($rows as $row) {
            $inviteId = (int) ($row['invite_id'] ?? 0);
            if ($inviteId <= 0) {
                continue;
            }
            $out[$inviteId][] = [
                'function_id' => (int) ($row['function_id'] ?? 0),
                'name' => (string) ($row['name'] ?? ''),
                'slug' => (string) ($row['slug'] ?? ''),
                'description' => (string) ($row['description'] ?? ''),
                'category' => (string) ($row['category'] ?? 'core'),
                'measurement_strength' => (string) ($row['measurement_strength'] ?? 'partial'),
                'assignment_type' => $this->normalizeAssignmentType((string) ($row['assignment_type'] ?? 'contributor')),
                'importance' => (string) ($row['importance'] ?? 'secondary'),
                'is_primary' => !empty($row['is_primary']),
            ];
        }

        return $out;
    }

    private function normalizeAssignmentType(string $type): string
    {
        $type = strtolower(trim($type));
        return in_array($type, OrganizationFunctionService::ASSIGNMENT_TYPES, true) ? $type : 'contributor';
    }

    private function requireMembershipRow(int $workspaceId, int $membershipId): array
    {
        $membership = Database::queryOne(
            "SELECT wm.*, u.first_name, u.last_name, u.email
             FROM workspace_memberships wm
             JOIN users u ON u.id = wm.user_id
             WHERE wm.id = ?
               AND wm.workspace_id = ?
             LIMIT 1",
            [$membershipId, $workspaceId]
        );
        if ($membership === null) {
            throw new \RuntimeException('Workspace membership not found.');
        }

        return $membership;
    }

    private function findInviteByToken(string $token): ?array
    {
        $token = trim($token);
        if ($token === '') {
            return null;
        }

        $invite = Database::queryOne(
            "SELECT wi.*, w.name AS workspace_name, w.slug AS workspace_slug
             FROM workspace_invites wi
             JOIN workspaces w ON w.id = wi.workspace_id
             WHERE wi.token_hash = ?
             LIMIT 1",
            [hash('sha256', $token)]
        );

        return $invite !== null ? $this->expireInviteIfNeeded($invite) : null;
    }

    private function expireInviteIfNeeded(array $invite): array
    {
        if ((string) ($invite['invite_status'] ?? '') !== 'pending') {
            return $invite;
        }

        $expiresAt = trim((string) ($invite['expires_at'] ?? ''));
        if ($expiresAt !== '' && strtotime($expiresAt) !== false && strtotime($expiresAt) < time()) {
            Database::execute(
                "UPDATE workspace_invites
                 SET invite_status = 'expired',
                     updated_at = NOW()
                 WHERE id = ?
                   AND invite_status = 'pending'",
                [(int) ($invite['id'] ?? 0)]
            );
            $invite['invite_status'] = 'expired';
        }

        return $invite;
    }

    private function normalizeRoleSlug(string $roleSlug): string
    {
        $roleSlug = strtolower(trim($roleSlug));
        if (!in_array($roleSlug, self::ALLOWED_MEMBER_ROLES, true)) {
            throw new \RuntimeException('That workspace role is not supported.');
        }

        return $roleSlug;
    }

    private function normalizeNonOwnerRole(string $roleSlug): string
    {
        $roleSlug = $this->normalizeRoleSlug($roleSlug);
        if ($roleSlug === self::OWNER_ROLE) {
            throw new \RuntimeException('Choose a non-owner role for the previous owner.');
        }

        return $roleSlug;
    }

    private function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    private function assertSeatCapacityForInvite(int $workspaceId, string $email): void
    {
        $limit = (new WorkspacePlanEntitlementService())->seatLimit($workspaceId);
        if ($limit <= 0) {
            return;
        }

        $email = $this->normalizeEmail($email);
        $activeMembers = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM workspace_memberships
             WHERE workspace_id = ?
               AND membership_status = 'active'",
            [$workspaceId]
        )['c'] ?? 0);
        $pendingInvites = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM workspace_invites
             WHERE workspace_id = ?
               AND invite_status = 'pending'
               AND expires_at > NOW()
               AND LOWER(TRIM(email)) <> ?",
            [$workspaceId, $email]
        )['c'] ?? 0);

        if (($activeMembers + $pendingInvites) >= $limit) {
            throw new \RuntimeException('This workspace has reached the seat limit for its current package. Upgrade before inviting another member.');
        }
    }

    private function findUserByEmail(string $email): ?array
    {
        $email = $this->normalizeEmail($email);
        if ($email === '') {
            return null;
        }

        return Database::queryOne(
            "SELECT id, email
             FROM users
             WHERE LOWER(TRIM(email)) = ?
             LIMIT 1",
            [$email]
        );
    }

    private function inviteStatusMessage(string $status): string
    {
        return match ($status) {
            'accepted' => 'This invite has already been accepted.',
            'revoked' => 'This invite has been revoked.',
            'expired' => 'This invite has expired.',
            default => 'This invite is not available anymore.',
        };
    }

    private function buildInviteUrl(string $token): string
    {
        $path = 'workspace_invite.php?token=' . rawurlencode($token);
        if (function_exists('publicUrl')) {
            $relative = publicUrl($path);
            $appUrl = rtrim((string) ($_ENV['APP_URL'] ?? ''), '/');
            if ($appUrl !== '') {
                return $appUrl . $relative;
            }

            if (!empty($_SERVER['HTTP_HOST'])) {
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                return $scheme . '://' . $_SERVER['HTTP_HOST'] . $relative;
            }

            return $relative;
        }

        return '/public/' . $path;
    }

    private function attemptInviteDelivery(array $invite, string $inviteUrl, ?int $actorUserId = null, string $deliveryAction = 'send'): array
    {
        try {
            $result = $this->inviteMailer->sendInvite($invite, $inviteUrl);
            $persisted = $this->persistInviteDeliveryOutcome($invite, true, null, $actorUserId, $deliveryAction);
            return array_merge([
                'success' => true,
                'error' => null,
            ], $persisted, $result);
        } catch (\Throwable $e) {
            return array_merge([
                'success' => false,
                'error' => $this->truncateDeliveryError($e->getMessage()),
            ], $this->persistInviteDeliveryOutcome(
                $invite,
                false,
                $e->getMessage(),
                $actorUserId,
                $deliveryAction
            ));
        }
    }

    private function memberStatusEventType(string $status): string
    {
        return match ($status) {
            'suspended' => 'member_suspended',
            'left' => 'member_removed',
            default => 'member_restored',
        };
    }

    private function refreshWorkspaceSessionForUserIfCurrent(int $workspaceId, int $userId): void
    {
        if ($workspaceId <= 0 || $userId <= 0 || !Auth::check()) {
            return;
        }

        $currentUser = Auth::user() ?? [];
        if ((int) ($currentUser['id'] ?? 0) !== $userId) {
            return;
        }

        if ((int) (WorkspaceContext::currentWorkspaceId() ?? 0) !== $workspaceId) {
            return;
        }

        $membership = $this->memberships->getActiveMembership($workspaceId, $userId);
        if ($membership !== null) {
            WorkspaceContext::setWorkspaceSession($membership);
            return;
        }

        $fallback = WorkspaceContext::activateForUser($userId);
        if ($fallback === null) {
            WorkspaceContext::clear();
        }
    }

    private function refreshWorkspaceContextForActiveSession(int $workspaceId): void
    {
        if (!Auth::check()) {
            return;
        }

        $currentUser = Auth::user() ?? [];
        $currentUserId = (int) ($currentUser['id'] ?? 0);
        if ($currentUserId <= 0) {
            return;
        }

        $this->refreshWorkspaceSessionForUserIfCurrent($workspaceId, $currentUserId);
    }

    private function normalizeHistoryFilter(?string $historyFilter): string
    {
        $normalized = strtolower(trim((string) $historyFilter));
        return in_array($normalized, self::HISTORY_FILTERS, true) ? $normalized : 'all';
    }

    /**
     * @param array<string,mixed> $invite
     * @return array<string,mixed>
     */
    private function normalizeInviteRow(array $invite): array
    {
        $invite['delivery_status'] = (string) ($invite['delivery_status'] ?? 'pending');
        $invite['delivery_error'] = trim((string) ($invite['delivery_error'] ?? '')) ?: null;
        $invite['invite_delivery_status'] = $invite['delivery_status'];
        $invite['invite_delivery_error'] = $invite['delivery_error'];
        $invite['invite_last_delivery_attempt_at'] = $invite['last_delivery_attempt_at'] ?? null;
        $invite['delivery_attempt_count'] = (int) ($invite['delivery_attempt_count'] ?? 0);

        return $invite;
    }

    /**
     * @param array<string,mixed> $invite
     * @return array<string,mixed>
     */
    private function persistInviteDeliveryOutcome(
        array $invite,
        bool $success,
        ?string $error,
        ?int $actorUserId,
        string $deliveryAction
    ): array {
        $workspaceId = (int) ($invite['workspace_id'] ?? 0);
        $inviteId = (int) ($invite['id'] ?? 0);
        if ($workspaceId <= 0 || $inviteId <= 0) {
            return [
                'invite_delivery_status' => $success ? 'sent' : 'failed',
                'invite_delivery_error' => $success ? null : $this->truncateDeliveryError((string) $error),
                'invite_last_delivery_attempt_at' => date('Y-m-d H:i:s'),
            ];
        }

        $boundedError = $success ? null : $this->truncateDeliveryError((string) $error);
        Database::execute(
            "UPDATE workspace_invites
             SET delivery_status = ?,
                 delivery_error = ?,
                 last_delivery_attempt_at = NOW(),
                 delivery_attempt_count = delivery_attempt_count + 1,
                 updated_at = NOW()
             WHERE id = ?
               AND workspace_id = ?",
            [$success ? 'sent' : 'failed', $boundedError, $inviteId, $workspaceId]
        );

        $updatedInvite = $this->normalizeInviteRow($this->requireInviteRow($workspaceId, $inviteId));
        $this->eventLog->log(
            $workspaceId,
            $success ? 'invite_delivery_sent' : 'invite_delivery_failed',
            $actorUserId,
            null,
            $inviteId,
            [
                'email' => (string) ($updatedInvite['email'] ?? ''),
                'delivery_status' => (string) ($updatedInvite['delivery_status'] ?? ($success ? 'sent' : 'failed')),
                'delivery_action' => $deliveryAction,
                'error' => $boundedError,
            ]
        );

        return [
            'invite' => $updatedInvite,
            'invite_delivery_status' => (string) ($updatedInvite['delivery_status'] ?? ''),
            'invite_delivery_error' => $updatedInvite['delivery_error'] ?? null,
            'invite_last_delivery_attempt_at' => $updatedInvite['last_delivery_attempt_at'] ?? null,
            'delivery_attempt_count' => (int) ($updatedInvite['delivery_attempt_count'] ?? 0),
        ];
    }

    private function truncateDeliveryError(string $error): string
    {
        $error = trim($error);
        if ($error === '') {
            return 'Invite delivery failed.';
        }

        return function_exists('mb_substr')
            ? mb_substr($error, 0, 255)
            : substr($error, 0, 255);
    }

    private function assertGovernanceReady(): void
    {
        $this->readiness->assertWorkspaceSaasReadiness('workspace_governance');
    }

    private function notifyDefaultWorkspaceLifecycle(int $workspaceId, int $actorUserId, string $reason): void
    {
        try {
            (new DefaultWorkspaceLifecycleObserverService())->workspaceChanged($workspaceId, $actorUserId, $reason);
        } catch (\Throwable $e) {
            error_log('Default workspace lifecycle observer failed: ' . $e->getMessage());
        }
    }
}
