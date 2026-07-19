<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Authorization;
use CRM\Session;

class PlatformImpersonationService
{
    private const CONTEXT_KEY = 'platform_impersonation_context';

    private OperatorAuditService $operatorAudit;

    public function __construct(?OperatorAuditService $operatorAudit = null)
    {
        $this->operatorAudit = $operatorAudit ?? new OperatorAuditService();
    }

    public function isActive(): bool
    {
        return is_array(Session::get(self::CONTEXT_KEY)) && !empty(Session::get(self::CONTEXT_KEY)['actor_user_id']);
    }

    public function context(): ?array
    {
        $context = Session::get(self::CONTEXT_KEY);
        return is_array($context) ? $context : null;
    }

    public function begin(array $actorUser, int $workspaceId, ?int $targetUserId = null, ?string $reason = null): array
    {
        if (!PlatformWorkspaceOperationsService::isPlatformAdmin($actorUser)) {
            throw new \RuntimeException('Only platform admins can impersonate a workspace.');
        }

        if ($this->isActive()) {
            throw new \RuntimeException('Exit the current impersonation session before starting another one.');
        }

        if ((new DefaultWorkspaceService())->isDefaultWorkspace($workspaceId) && !Authorization::isSuperAdmin($actorUser)) {
            throw new \RuntimeException('Only Super Admin can impersonate the default workspace.');
        }

        $membership = $this->resolveMembership($workspaceId, $targetUserId);
        $targetUser = Database::queryOne(
            "SELECT id, uuid, email, role
             FROM users
             WHERE id = ?
             LIMIT 1",
            [(int) ($membership['user_id'] ?? 0)]
        );
        if ($targetUser === null) {
            throw new \RuntimeException('The selected impersonation user was not found.');
        }

        $context = [
            'actor_user_id' => (int) ($actorUser['id'] ?? 0),
            'actor_user_uuid' => (string) ($actorUser['uuid'] ?? ''),
            'actor_user_email' => (string) ($actorUser['email'] ?? ''),
            'actor_user_role' => (string) ($actorUser['role'] ?? ''),
            'actor_workspace_id' => (int) (Session::get('active_workspace_id') ?? 0),
            'actor_workspace_uuid' => (string) (Session::get('active_workspace_uuid') ?? ''),
            'actor_workspace_slug' => (string) (Session::get('active_workspace_slug') ?? ''),
            'actor_workspace_name' => (string) (Session::get('active_workspace_name') ?? ''),
            'actor_workspace_role' => (string) (Session::get('active_workspace_role') ?? ''),
            'actor_workspace_membership_id' => (int) (Session::get('active_workspace_membership_id') ?? 0),
            'impersonated_user_id' => (int) ($targetUser['id'] ?? 0),
            'impersonated_user_email' => (string) ($targetUser['email'] ?? ''),
            'impersonated_workspace_id' => (int) ($membership['workspace_id'] ?? 0),
            'impersonated_workspace_name' => (string) ($membership['workspace_name'] ?? ''),
            'impersonated_workspace_slug' => (string) ($membership['workspace_slug'] ?? ''),
            'impersonated_role_slug' => (string) ($membership['role_slug'] ?? 'viewer'),
            'started_at' => date('c'),
            'reason' => $reason,
        ];

        Session::set(self::CONTEXT_KEY, $context);
        Session::set('user_id', (int) ($targetUser['id'] ?? 0));
        Session::set('user_uuid', (string) ($targetUser['uuid'] ?? ''));
        Session::set('user_email', (string) ($targetUser['email'] ?? ''));
        Session::set('user_role', (string) ($targetUser['role'] ?? ''));
        WorkspaceContext::setWorkspaceSession($membership);

        $this->operatorAudit->log(
            'impersonation_start',
            (int) ($actorUser['id'] ?? 0),
            (int) ($membership['workspace_id'] ?? 0),
            $reason,
            [
                'impersonated_user_id' => (int) ($targetUser['id'] ?? 0),
                'impersonated_email' => (string) ($targetUser['email'] ?? ''),
                'impersonated_role_slug' => (string) ($membership['role_slug'] ?? 'viewer'),
                'protected_default_workspace' => (new DefaultWorkspaceService())->isDefaultWorkspace((int) ($membership['workspace_id'] ?? 0)),
            ],
            (int) ($targetUser['id'] ?? 0)
        );

        return [
            'membership' => $membership,
            'user' => $targetUser,
            'context' => $context,
        ];
    }

    public function end(?string $reason = null): ?array
    {
        $context = $this->context();
        if ($context === null) {
            return null;
        }

        Session::set('user_id', (int) ($context['actor_user_id'] ?? 0));
        Session::set('user_uuid', (string) ($context['actor_user_uuid'] ?? ''));
        Session::set('user_email', (string) ($context['actor_user_email'] ?? ''));
        Session::set('user_role', (string) ($context['actor_user_role'] ?? ''));

        $workspaceId = (int) ($context['actor_workspace_id'] ?? 0);
        if ($workspaceId > 0) {
            WorkspaceContext::setWorkspaceSession([
                'id' => (int) ($context['actor_workspace_membership_id'] ?? 0),
                'workspace_id' => $workspaceId,
                'workspace_uuid' => (string) ($context['actor_workspace_uuid'] ?? ''),
                'workspace_slug' => (string) ($context['actor_workspace_slug'] ?? ''),
                'workspace_name' => (string) ($context['actor_workspace_name'] ?? ''),
                'role_slug' => (string) ($context['actor_workspace_role'] ?? 'viewer'),
            ]);
        } else {
            WorkspaceContext::clear();
        }

        Session::remove(self::CONTEXT_KEY);

        $this->operatorAudit->log(
            'impersonation_stop',
            (int) ($context['actor_user_id'] ?? 0),
            (int) ($context['impersonated_workspace_id'] ?? 0),
            $reason,
            [
                'impersonated_user_id' => (int) ($context['impersonated_user_id'] ?? 0),
                'impersonated_email' => (string) ($context['impersonated_user_email'] ?? ''),
                'started_at' => (string) ($context['started_at'] ?? ''),
                'stopped_at' => date('c'),
            ],
            (int) ($context['impersonated_user_id'] ?? 0)
        );

        return $context;
    }

    private function resolveMembership(int $workspaceId, ?int $targetUserId = null): array
    {
        if ($workspaceId <= 0) {
            throw new \RuntimeException('Workspace is required for impersonation.');
        }

        if ($targetUserId !== null && $targetUserId > 0) {
            $membership = Database::queryOne(
                "SELECT wm.*, w.uuid AS workspace_uuid, w.name AS workspace_name, w.slug AS workspace_slug, w.status AS workspace_status, w.plan_status
                 FROM workspace_memberships wm
                 JOIN workspaces w ON w.id = wm.workspace_id
                 WHERE wm.workspace_id = ?
                   AND wm.user_id = ?
                   AND wm.membership_status = 'active'
                 LIMIT 1",
                [$workspaceId, $targetUserId]
            );
            if ($membership !== null) {
                return $membership;
            }
        }

        $membership = Database::queryOne(
            "SELECT wm.*, w.uuid AS workspace_uuid, w.name AS workspace_name, w.slug AS workspace_slug, w.status AS workspace_status, w.plan_status
             FROM workspace_memberships wm
             JOIN workspaces w ON w.id = wm.workspace_id
             WHERE wm.workspace_id = ?
               AND wm.membership_status = 'active'
             ORDER BY wm.is_owner DESC, wm.id ASC
             LIMIT 1",
            [$workspaceId]
        );

        if ($membership === null) {
            throw new \RuntimeException('No active workspace member is available for impersonation.');
        }

        return $membership;
    }
}
