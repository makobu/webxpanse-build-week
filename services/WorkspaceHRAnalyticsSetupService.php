<?php

namespace CRM\Services;

use CRM\Database;

class WorkspaceHRAnalyticsSetupService
{
    public function status(int $workspaceId): array
    {
        $workspaceId = max(0, $workspaceId);
        if ($workspaceId <= 0) {
            return $this->emptyStatus('Workspace context is required before Organization Intelligence setup can be checked.');
        }

        $settingsReady = $this->settingsAvailable();
        $functionCount = $this->activeFunctionCount($workspaceId);
        $functionAssignmentCount = $this->functionAssignmentCount($workspaceId);
        $departmentCount = $this->activeDepartmentCount($workspaceId);
        $activeMemberCount = $this->activeMemberCount($workspaceId);
        $assignedMemberCount = $this->assignedMemberCount($workspaceId);
        $functionsReady = $functionCount > 0;
        $departmentsReady = $departmentCount > 0;
        $assignmentsReady = $assignedMemberCount > 0;
        $ready = $settingsReady && $functionsReady;

        $checks = [
            [
                'label' => 'Organization Intelligence settings',
                'ok' => $settingsReady,
                'required' => true,
                'detail' => $settingsReady
                    ? 'Organization Intelligence scoring and guidance defaults are available.'
                    : 'Organization Intelligence settings storage is unavailable.',
            ],
            [
                'label' => 'Active Work Ownership areas',
                'ok' => $functionsReady,
                'required' => true,
                'detail' => $functionsReady
                    ? $functionCount . ' active business area(s) are available in this workspace.'
                    : 'Create or mark at least one business area as active now.',
            ],
            [
                'label' => 'Active departments',
                'ok' => $departmentsReady,
                'required' => false,
                'detail' => $departmentsReady
                    ? $departmentCount . ' active department(s) are available as optional formal structure.'
                    : 'Departments are optional for founder-led workspaces; add them when structure forms.',
            ],
            [
                'label' => 'Department assignment',
                'ok' => $assignmentsReady,
                'required' => false,
                'detail' => $assignmentsReady
                    ? $assignedMemberCount . ' of ' . $activeMemberCount . ' active member(s) have a department.'
                    : 'Department assignment is optional until departments become meaningful performance units.',
            ],
        ];

        $blockers = array_values(array_map(
            static fn(array $check): string => (string) $check['label'],
            array_filter($checks, static fn(array $check): bool => !empty($check['required']) && empty($check['ok']))
        ));

        return [
            'enabled' => true,
            'ready' => $ready,
            'locked' => !$ready,
            'status' => $ready ? 'ready' : 'needs_setup',
            'message' => $ready
                ? 'Organization Intelligence setup is ready.'
                : 'Complete the required business areas before opening the dashboard.',
            'owner_message' => 'Ask the workspace owner to complete Organization Intelligence setup before using the dashboard.',
            'checks' => $checks,
            'blockers' => $blockers,
            'next_action' => $ready ? 'Open Organization Intelligence.' : 'Finish the guided Organization Intelligence setup.',
            'counts' => [
                'settings_ready' => $settingsReady ? 1 : 0,
                'active_departments' => $departmentCount,
                'active_members' => $activeMemberCount,
                'assigned_members' => $assignedMemberCount,
                'active_functions' => $functionCount,
                'function_assignments' => $functionAssignmentCount,
            ],
            'actions' => [
                ['label' => 'Continue guided setup', 'url' => WorkspaceHRAnalyticsGateService::SETUP_URL],
                ['label' => 'Review business areas', 'url' => WorkspaceHRAnalyticsGateService::SETUP_URL],
            ],
            'setup_url' => WorkspaceHRAnalyticsGateService::SETUP_URL,
            'runtime_url' => WorkspaceHRAnalyticsGateService::RUNTIME_URL,
        ];
    }

    public function isReady(int $workspaceId): bool
    {
        return !empty($this->status($workspaceId)['ready']);
    }

    private function settingsAvailable(): bool
    {
        return Database::tableExists('hr_analytics_settings');
    }

    private function activeDepartmentCount(int $workspaceId): int
    {
        if (!Database::tableExists('departments')) {
            return 0;
        }

        $row = Database::queryOne(
            "SELECT COUNT(*) AS c FROM departments WHERE workspace_id = ? AND is_active = 1",
            [$workspaceId]
        );

        return (int) ($row['c'] ?? 0);
    }

    private function activeFunctionCount(int $workspaceId): int
    {
        if (!Database::tableExists('organization_functions')) {
            return 0;
        }

        (new OrganizationFunctionService())->ensureDefaults($workspaceId);
        $row = Database::queryOne(
            "SELECT COUNT(*) AS c FROM organization_functions WHERE workspace_id = ? AND is_active = 1 AND relevance_status = 'active'",
            [$workspaceId]
        );

        return (int) ($row['c'] ?? 0);
    }

    private function functionAssignmentCount(int $workspaceId): int
    {
        if (!Database::tableExists('user_function_assignments') || !Database::tableExists('organization_functions')) {
            return 0;
        }

        $row = Database::queryOne(
            "SELECT COUNT(DISTINCT ufa.user_id) AS c
             FROM user_function_assignments ufa
             JOIN organization_functions f
              ON f.id = ufa.function_id
             AND f.workspace_id = ufa.workspace_id
             AND f.is_active = 1
             AND f.relevance_status = 'active'
             JOIN workspace_memberships wm
               ON wm.workspace_id = ufa.workspace_id
              AND wm.user_id = ufa.user_id
              AND wm.membership_status = 'active'
             WHERE ufa.workspace_id = ?",
            [$workspaceId]
        );

        return (int) ($row['c'] ?? 0);
    }

    private function activeMemberCount(int $workspaceId): int
    {
        if (!Database::tableExists('workspace_memberships')) {
            return 0;
        }

        $row = Database::queryOne(
            "SELECT COUNT(*) AS c FROM workspace_memberships WHERE workspace_id = ? AND membership_status = 'active'",
            [$workspaceId]
        );

        return (int) ($row['c'] ?? 0);
    }

    private function assignedMemberCount(int $workspaceId): int
    {
        if (!Database::tableExists('workspace_memberships') || !Database::tableExists('departments')) {
            return 0;
        }

        $row = Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM workspace_memberships wm
             INNER JOIN departments d
                ON d.id = wm.department_id
               AND d.workspace_id = wm.workspace_id
               AND d.is_active = 1
             WHERE wm.workspace_id = ?
               AND wm.membership_status = 'active'
               AND wm.department_id IS NOT NULL",
            [$workspaceId]
        );

        return (int) ($row['c'] ?? 0);
    }

    private function emptyStatus(string $message): array
    {
        return [
            'enabled' => true,
            'ready' => false,
            'locked' => true,
            'status' => 'needs_setup',
            'message' => $message,
            'owner_message' => 'Ask the workspace owner to complete Organization Intelligence setup before using the dashboard.',
            'checks' => [],
            'blockers' => ['Workspace context'],
            'next_action' => 'Open the workspace before reviewing Organization Intelligence setup.',
            'counts' => ['settings_ready' => 0, 'active_departments' => 0, 'active_members' => 0, 'assigned_members' => 0, 'active_functions' => 0, 'function_assignments' => 0],
            'actions' => [],
            'setup_url' => WorkspaceHRAnalyticsGateService::SETUP_URL,
            'runtime_url' => WorkspaceHRAnalyticsGateService::RUNTIME_URL,
        ];
    }
}
