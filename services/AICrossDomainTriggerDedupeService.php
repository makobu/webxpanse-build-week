<?php

namespace CRM\Services;

use CRM\Database;

class AICrossDomainTriggerDedupeService
{
    private AIWorkspaceScopeService $workspaceScope;

    public function __construct(?AIWorkspaceScopeService $workspaceScope = null)
    {
        $this->workspaceScope = $workspaceScope ?? new AIWorkspaceScopeService();
    }

    public function findActiveMatchingRun(string $tenantKey, string $objectiveKey, string $primaryEntityType, int $primaryEntityId): ?array
    {
        if ($primaryEntityId <= 0) {
            return null;
        }

        return Database::queryOne(
            "SELECT *
             FROM ai_cross_domain_runs
             WHERE workspace_id = ?
               AND objective_key = ?
               AND primary_entity_type = ?
               AND primary_entity_id = ?
               AND run_status IN ('planned', 'running', 'waiting', 'ready_to_resume', 'approval_required', 'blocked')
             ORDER BY created_at DESC, id DESC
             LIMIT 1",
            [
                $this->workspaceScope->requireWorkspaceId(),
                $objectiveKey,
                $primaryEntityType,
                $primaryEntityId,
            ]
        ) ?: null;
    }

    public function isCoveredByMoreAdvancedState(?array $existingRun): bool
    {
        return $existingRun !== null;
    }
}
