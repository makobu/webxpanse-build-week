<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\AITaskAutomationService;

class AITaskWorkspaceAutoSeedWorkerService
{
    private AITaskAutomationService $taskAutomation;

    public function __construct(?AITaskAutomationService $taskAutomation = null)
    {
        $this->taskAutomation = $taskAutomation ?? new AITaskAutomationService();
    }

    public function run(int $limit = 100): array
    {
        $summary = [
            'checked_users' => 0,
            'checked_memberships' => 0,
            'seeded_users' => 0,
            'seeded_memberships' => 0,
            'created_tasks' => 0,
            'retired_tasks' => 0,
            'blocked_by_gate' => 0,
            'blocked_by_plan' => 0,
            'gate_redirected' => 0,
            'workspace_context_failures' => 0,
        ];
        $checkedUserIds = [];
        $seededUserIds = [];
        $runtimeSnapshot = WorkspaceContext::runtimeSnapshot();

        try {
            foreach ($this->activeMembershipRows($limit) as $row) {
                $workspaceId = (int) ($row['workspace_id'] ?? 0);
                $userId = (int) ($row['user_id'] ?? 0);
                if ($workspaceId <= 0 || $userId <= 0) {
                    continue;
                }

                $summary['checked_memberships']++;
                $checkedUserIds[$userId] = true;

                $activatedWorkspace = WorkspaceContext::activateRuntimeWorkspace(
                    $workspaceId,
                    $userId,
                    (string) ($row['role_slug'] ?? 'viewer')
                );
                if ($activatedWorkspace === null) {
                    $summary['workspace_context_failures']++;
                    WorkspaceContext::restoreRuntimeWorkspace($runtimeSnapshot);
                    continue;
                }

                try {
                    if (!$this->taskAutomation->shouldAutoSeedToday($userId)) {
                        continue;
                    }

                    $seedResult = $this->taskAutomation->autoSeedDailyTasks($userId);
                    $createdCount = (int) ($seedResult['created_count'] ?? 0);
                    $retiredCount = (int) ($seedResult['retired_count'] ?? 0);

                    if ($createdCount > 0) {
                        $summary['seeded_memberships']++;
                        $summary['created_tasks'] += $createdCount;
                        $seededUserIds[$userId] = true;
                    }

                    $summary['retired_tasks'] += $retiredCount;
                    $summary['blocked_by_gate'] += (int) ($seedResult['blocked_by_gate'] ?? 0);
                    $summary['blocked_by_plan'] += (int) ($seedResult['blocked_by_plan'] ?? 0);
                    $summary['gate_redirected'] += (int) ($seedResult['gate_redirected'] ?? 0);
                } finally {
                    WorkspaceContext::restoreRuntimeWorkspace($runtimeSnapshot);
                }
            }
        } finally {
            WorkspaceContext::restoreRuntimeWorkspace($runtimeSnapshot);
        }

        $summary['checked_users'] = count($checkedUserIds);
        $summary['seeded_users'] = count($seededUserIds);

        return $summary;
    }

    private function activeMembershipRows(int $limit): array
    {
        $limit = max(1, min(500, $limit));

        return Database::query(
            "SELECT wm.workspace_id, wm.user_id, wm.role_slug
             FROM workspace_memberships wm
             JOIN workspaces w ON w.id = wm.workspace_id
             JOIN users u ON u.id = wm.user_id
             WHERE wm.membership_status = 'active'
             ORDER BY wm.user_id ASC, wm.workspace_id ASC, wm.id ASC
             LIMIT {$limit}"
        );
    }
}
