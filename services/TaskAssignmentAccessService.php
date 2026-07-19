<?php

namespace CRM\Services;

use CRM\Authorization;
use CRM\Database;

class TaskAssignmentAccessService
{
    private AITaskAssignmentPolicyService $policyService;

    public function __construct()
    {
        $this->policyService = new AITaskAssignmentPolicyService();
    }

    public function canReadTasks(?array $user): bool
    {
        return $this->actorHasPermission((int) ($user['id'] ?? 0), 'tasks.read', (string) ($user['role'] ?? ''));
    }

    public function canWriteTasks(?array $user): bool
    {
        return $this->actorHasPermission((int) ($user['id'] ?? 0), 'tasks.write', (string) ($user['role'] ?? ''));
    }

    public function canReassignTasks(?array $user): bool
    {
        return $this->actorHasPermission((int) ($user['id'] ?? 0), 'tasks.reassign', (string) ($user['role'] ?? ''));
    }

    public function canReceiveAiTasks(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        return $this->userHasPermission($userId, 'tasks.read')
            && $this->userHasPermission($userId, 'tasks.ai_assignable');
    }

    public function getManualAssignableUsers(array $taskContext = []): array
    {
        $baseUsers = $this->getUsersByPermissions(['tasks.write']);
        if (empty($taskContext)) {
            return $baseUsers;
        }

        $policy = $this->policyService->resolve($taskContext);
        if (empty($policy['is_known_domain'])) {
            return $baseUsers;
        }

        $requiredPermissions = array_values(array_filter((array) ($policy['required_permissions'] ?? []), 'is_string'));
        if ($requiredPermissions === []) {
            return $baseUsers;
        }

        return array_values(array_filter($baseUsers, function (array $user) use ($requiredPermissions): bool {
            foreach ($requiredPermissions as $perm) {
                if ($this->userHasPermission((int) $user['id'], $perm)) {
                    return true;
                }
            }
            return false;
        }));
    }

    public function getAiAssignableUsers(): array
    {
        return $this->getUsersByPermissions(['tasks.read', 'tasks.ai_assignable']);
    }

    public function isManualAssigneeValid(?int $userId, array $taskContext = []): bool
    {
        if (empty($userId)) {
            return true;
        }

        if (!$this->userHasPermission((int) $userId, 'tasks.write')) {
            return false;
        }

        if (empty($taskContext)) {
            return true;
        }

        $policy = $this->policyService->resolve($taskContext);
        if (empty($policy['is_known_domain'])) {
            return true;
        }

        $requiredPermissions = array_values(array_filter((array) ($policy['required_permissions'] ?? []), 'is_string'));
        if ($requiredPermissions === []) {
            return true;
        }

        foreach ($requiredPermissions as $perm) {
            if ($this->userHasPermission((int) $userId, $perm)) {
                return true;
            }
        }

        return false;
    }

    public function isAiAssigneeValid(?int $userId, array $taskContext = []): bool
    {
        if (empty($userId)) {
            return true;
        }

        return $this->canReceiveAiTaskForContext((int) $userId, $taskContext);
    }

    public function canReceiveAiTaskForContext(int $userId, array $taskContext = []): bool
    {
        if (!$this->canReceiveAiTasks($userId)) {
            return false;
        }

        $policy = $this->policyService->resolve($taskContext);
        return $this->userMatchesTaskPolicy($userId, $policy);
    }

    public function resolveAiAssignee(array $candidateUserIds, array $taskContext = []): array
    {
        $candidateUserIds = array_values(array_unique(array_filter(array_map('intval', $candidateUserIds))));
        $policy = $this->policyService->resolve($taskContext);
        $baseBlocked = false;
        $domainBlocked = false;

        foreach ($candidateUserIds as $userId) {
            if (!$this->canReceiveAiTasks($userId)) {
                $baseBlocked = true;
                continue;
            }

            if (!$this->userMatchesTaskPolicy($userId, $policy)) {
                $domainBlocked = true;
                continue;
            }

            if ($this->canReceiveAiTasks($userId)) {
                return [
                    'assigned_to' => $userId,
                    'resolution' => 'matched_candidate',
                    'reason_code' => 'ai_assignee_allowed',
                    'permission_domain' => $policy['domain_key'],
                    'task_intent' => $policy['task_intent'],
                    'required_permissions' => $policy['required_permissions'],
                ];
            }
        }

        $reasonCode = 'no_ai_assignee_eligible';
        if ($candidateUserIds === []) {
            $reasonCode = 'no_ai_assignee_candidates';
        } elseif (empty($policy['is_known_domain'])) {
            $reasonCode = 'ai_assignee_unknown_intent';
        } elseif ($domainBlocked) {
            $reasonCode = 'ai_assignee_missing_domain_permission';
        } elseif ($baseBlocked) {
            $reasonCode = 'ai_assignee_missing_base_permission';
        }

        return [
            'assigned_to' => null,
            'resolution' => 'unassigned',
            'reason_code' => $reasonCode,
            'permission_domain' => $policy['domain_key'],
            'task_intent' => $policy['task_intent'],
            'required_permissions' => $policy['required_permissions'],
        ];
    }

    public function assertCanCreateOrEdit(?int $actorUserId): void
    {
        if ($actorUserId <= 0) {
            return;
        }

        if (!$this->actorHasPermission($actorUserId, 'tasks.write')) {
            throw new \RuntimeException('You do not have permission to modify tasks.');
        }
    }

    public function assertCanReassign(?int $actorUserId): void
    {
        if ($actorUserId <= 0) {
            return;
        }

        if (!$this->actorHasPermission($actorUserId, 'tasks.reassign')) {
            throw new \RuntimeException('You do not have permission to reassign tasks.');
        }
    }

    private function getUsersByPermissions(array $permissionKeys): array
    {
        if ($permissionKeys === []) {
            return [];
        }

        $workspaceId = $this->currentWorkspaceId();
        $membershipScope = $this->activeWorkspaceMembershipSql('u', $workspaceId);

        if (!Authorization::isRbacAvailable()) {
            $users = Database::query(
                "SELECT u.id, u.email, u.role
                 FROM users u
                 {$membershipScope['join']}
                 {$membershipScope['where']}
                 ORDER BY u.email ASC",
                $membershipScope['params']
            );

            return array_values(array_map(
                static fn(array $user): array => ['id' => (int) $user['id'], 'email' => (string) $user['email']],
                array_filter($users, function (array $user) use ($permissionKeys): bool {
                    foreach ($permissionKeys as $permissionKey) {
                        if (!$this->legacyRoleHasPermission((string) ($user['role'] ?? ''), (string) $permissionKey)) {
                            return false;
                        }
                    }
                    return true;
                })
            ));
        }

        $clauses = [];
        $params = [];
        foreach ($permissionKeys as $permissionKey) {
            $clauses[] = "SUM(CASE WHEN p.permission_key = ? THEN 1 ELSE 0 END) > 0";
            $params[] = $permissionKey;
        }

        $hasWorkspaceRoles = Database::tableExists('workspace_user_roles') && $workspaceId > 0;
        $roleExpression = $hasWorkspaceRoles
            ? "CASE WHEN global_r.slug = 'superadmin' THEN global_r.id ELSE COALESCE(workspace_r.id, global_r.id) END"
            : "global_r.id";

        $workspaceRoleJoins = $hasWorkspaceRoles
            ? "LEFT JOIN workspace_user_roles wur ON wur.user_id = u.id AND wur.workspace_id = ?
               LEFT JOIN roles workspace_r ON workspace_r.id = wur.role_id AND workspace_r.is_active = 1"
            : "";
        $workspaceRoleParams = $hasWorkspaceRoles ? [$workspaceId] : [];

        $sql = "SELECT u.id, u.email
                FROM users u
                {$membershipScope['join']}
                LEFT JOIN user_roles global_ur ON global_ur.user_id = u.id
                LEFT JOIN roles global_r ON global_r.id = global_ur.role_id AND global_r.is_active = 1
                {$workspaceRoleJoins}
                JOIN roles r ON r.id = {$roleExpression}
                JOIN role_permissions rp ON rp.role_id = r.id AND rp.can_access = 1
                JOIN permissions p ON p.id = rp.permission_id
                {$membershipScope['where']}
                GROUP BY u.id, u.email
                HAVING " . implode(' AND ', $clauses) . "
                ORDER BY u.email ASC";

        return Database::query($sql, array_merge($workspaceRoleParams, $membershipScope['params'], $params));
    }

    private function actorHasPermission(int $userId, string $permissionKey, string $legacyRole = ''): bool
    {
        if ($userId <= 0) {
            return false;
        }

        if (!Authorization::isRbacAvailable()) {
            if ($legacyRole === '') {
                $row = Database::queryOne("SELECT role FROM users WHERE id = ?", [$userId]);
                $legacyRole = (string) ($row['role'] ?? '');
            }
            return $this->legacyRoleHasPermission($legacyRole, $permissionKey);
        }

        return $this->userHasPermission($userId, $permissionKey);
    }

    private function userHasPermission(int $userId, string $permissionKey): bool
    {
        if ($userId <= 0 || trim($permissionKey) === '') {
            return false;
        }

        if (!$this->isActiveWorkspaceMember($userId)) {
            return false;
        }

        if (!Authorization::isRbacAvailable()) {
            $row = Database::queryOne("SELECT role FROM users WHERE id = ?", [$userId]);
            return $this->legacyRoleHasPermission((string) ($row['role'] ?? ''), $permissionKey);
        }

        $workspaceId = $this->currentWorkspaceId();
        $hasWorkspaceRoles = Database::tableExists('workspace_user_roles') && $workspaceId > 0;
        $roleExpression = $hasWorkspaceRoles
            ? "CASE WHEN global_r.slug = 'superadmin' THEN global_r.id ELSE COALESCE(workspace_r.id, global_r.id) END"
            : "global_r.id";
        $workspaceRoleJoin = $hasWorkspaceRoles
            ? "LEFT JOIN workspace_user_roles wur ON wur.user_id = u.id AND wur.workspace_id = ?
               LEFT JOIN roles workspace_r ON workspace_r.id = wur.role_id AND workspace_r.is_active = 1"
            : "";
        $params = $hasWorkspaceRoles ? [$workspaceId] : [];
        $params = array_merge($params, [$userId, $permissionKey]);

        $row = Database::queryOne(
            "SELECT 1
             FROM users u
             LEFT JOIN user_roles global_ur ON global_ur.user_id = u.id
             LEFT JOIN roles global_r ON global_r.id = global_ur.role_id AND global_r.is_active = 1
             {$workspaceRoleJoin}
             JOIN roles r ON r.id = {$roleExpression}
             JOIN role_permissions rp ON rp.role_id = r.id AND rp.can_access = 1
             JOIN permissions p ON p.id = rp.permission_id
             WHERE u.id = ? AND p.permission_key = ?
             LIMIT 1",
            $params
        );

        return !empty($row);
    }

    /**
     * @return array{join:string, where:string, params:array<int,int>}
     */
    private function activeWorkspaceMembershipSql(string $userAlias, int $workspaceId): array
    {
        if ($workspaceId <= 0 || !Database::tableExists('workspace_memberships')) {
            return ['join' => '', 'where' => '', 'params' => []];
        }

        return [
            'join' => "JOIN workspace_memberships wm_scope ON wm_scope.user_id = {$userAlias}.id",
            'where' => "WHERE wm_scope.workspace_id = ? AND wm_scope.membership_status = 'active'",
            'params' => [$workspaceId],
        ];
    }

    private function isActiveWorkspaceMember(int $userId): bool
    {
        $workspaceId = $this->currentWorkspaceId();
        if ($workspaceId <= 0 || !Database::tableExists('workspace_memberships')) {
            return true;
        }

        return (bool) Database::queryOne(
            "SELECT 1
             FROM workspace_memberships
             WHERE workspace_id = ?
               AND user_id = ?
               AND membership_status = 'active'
             LIMIT 1",
            [$workspaceId, $userId]
        );
    }

    private function currentWorkspaceId(): int
    {
        return (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
    }

    private function legacyRoleHasPermission(string $legacyRole, string $permissionKey): bool
    {
        $legacyRole = strtolower(trim($legacyRole));
        if ($legacyRole === '') {
            return false;
        }

        $matrix = [
            'tasks.read' => ['admin', 'sales', 'marketing', 'viewer'],
            'tasks.write' => ['admin', 'sales', 'marketing'],
            'tasks.reassign' => ['admin', 'sales', 'marketing'],
            'tasks.ai_assignable' => ['admin', 'sales'],
            'ai.tasks.follow_up' => ['admin', 'sales'],
            'ai.tasks.pricing' => ['admin', 'sales'],
            'ai.tasks.segmentation' => ['admin', 'marketing'],
            'ai.tasks.review' => ['admin', 'sales', 'marketing'],
            'settings.company' => ['admin'],
            'settings.deal_automation' => ['admin'],
            'workflows.manage' => ['admin'],
            'targets.manage_all' => ['admin'],
            'invoices.create' => ['admin', 'sales'],
            'invoices.edit' => ['admin', 'sales'],
            'invoices.finalize' => ['admin'],
            'invoices.mark_paid' => ['admin'],
            'invoices.settings' => ['admin'],
            'settings.invoicing' => ['admin'],
            'ai.invoices.execute' => ['admin'],
            'settings.ai' => ['admin'],
            'settings.general' => ['admin'],
        ];

        return in_array($legacyRole, $matrix[$permissionKey] ?? [], true);
    }

    /**
     * @param array<string,mixed> $policy
     */
    private function userMatchesTaskPolicy(int $userId, array $policy): bool
    {
        if ($userId <= 0) {
            return false;
        }

        if (empty($policy['is_known_domain'])) {
            return false;
        }

        $requiredPermissions = array_values(array_filter((array) ($policy['required_permissions'] ?? []), 'is_string'));
        if ($requiredPermissions === []) {
            return false;
        }

        foreach ($requiredPermissions as $permissionKey) {
            if ($this->userHasPermission($userId, $permissionKey)) {
                return true;
            }
        }

        return false;
    }
}
