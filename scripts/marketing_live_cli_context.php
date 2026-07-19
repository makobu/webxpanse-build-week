<?php
/**
 * Shared CLI context helpers for Marketing live execution workers.
 */

use CRM\Authorization;
use CRM\Database;
use CRM\Session;
use CRM\Services\WorkspaceContext;

$_ENV['DISABLE_SMTP_CONFIG_LOGS'] = 'true';
putenv('DISABLE_SMTP_CONFIG_LOGS=true');

if (!function_exists('marketingLiveCliActivateManageContext')) {
    /**
     * @return array{user_id:int,role:string,email:string}
     */
    function marketingLiveCliActivateManageContext(int $workspaceId, int $preferredUserId = 0): array
    {
        if ($workspaceId <= 0) {
            throw new RuntimeException('A valid workspace is required for Marketing live execution.');
        }

        $candidates = marketingLiveCliManagerCandidates($workspaceId, $preferredUserId);
        foreach ($candidates as $candidate) {
            $userId = (int) ($candidate['id'] ?? 0);
            if ($userId <= 0) {
                continue;
            }

            $role = !empty($candidate['is_owner'])
                ? 'owner'
                : (string) ($candidate['role_slug'] ?? 'viewer');

            Session::set('__remember_restore_attempted', true);
            Session::set('user_id', $userId);
            Session::set('user_uuid', (string) ($candidate['uuid'] ?? ''));
            Session::set('user_email', (string) ($candidate['email'] ?? ''));
            Session::set('user_role', (string) ($candidate['user_role'] ?? 'user'));
            WorkspaceContext::activateRuntimeWorkspace($workspaceId, $userId, $role);

            $user = [
                'id' => $userId,
                'uuid' => (string) ($candidate['uuid'] ?? ''),
                'email' => (string) ($candidate['email'] ?? ''),
                'role' => (string) ($candidate['user_role'] ?? 'user'),
            ];
            if (Authorization::can('marketing.manage', $user)) {
                return [
                    'user_id' => $userId,
                    'role' => $role,
                    'email' => (string) ($candidate['email'] ?? ''),
                ];
            }
        }

        if ($preferredUserId > 0) {
            throw new RuntimeException('The specified live execution user does not have marketing.manage in this workspace.');
        }

        throw new RuntimeException('No active workspace user with marketing.manage was found for Marketing live execution.');
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    function marketingLiveCliManagerCandidates(int $workspaceId, int $preferredUserId = 0): array
    {
        $params = [$workspaceId];
        $userFilter = '';
        if ($preferredUserId > 0) {
            $userFilter = ' AND u.id = ?';
            $params[] = $preferredUserId;
        }

        return Database::query(
            "SELECT u.id, u.uuid, u.email, u.role AS user_role, wm.role_slug, wm.is_owner
             FROM workspace_memberships wm
             JOIN users u ON u.id = wm.user_id
             WHERE wm.workspace_id = ?
               AND wm.membership_status = 'active'
               {$userFilter}
             ORDER BY wm.is_owner DESC,
                      FIELD(wm.role_slug, 'owner', 'admin', 'marketing', 'sales', 'viewer'),
                      wm.id ASC",
            $params
        );
    }
}
