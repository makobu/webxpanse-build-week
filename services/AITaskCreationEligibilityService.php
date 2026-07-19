<?php

namespace CRM\Services;

use CRM\Database;

class AITaskCreationEligibilityService
{
    private WorkspaceMarketplaceAccessService $access;
    private WorkspaceSkillCatalogService $catalog;

    public function __construct(
        ?WorkspaceMarketplaceAccessService $access = null,
        ?WorkspaceSkillCatalogService $catalog = null
    ) {
        $this->catalog = $catalog ?? new WorkspaceSkillCatalogService();
        $this->access = $access ?? new WorkspaceMarketplaceAccessService($this->catalog);
    }

    /**
     * @param list<array<string,mixed>> $candidates
     * @return array{candidates:list<array<string,mixed>>,skipped:int,blocked_by_gate:int,blocked_by_plan:int,gate_redirected:int}
     */
    public function filterCandidates(int $workspaceId, int $userId, array $candidates): array
    {
        if ($workspaceId <= 0 || $userId <= 0 || $candidates === []) {
            return [
                'candidates' => array_values(array_filter($candidates, 'is_array')),
                'skipped' => 0,
                'blocked_by_gate' => 0,
                'blocked_by_plan' => 0,
                'gate_redirected' => 0,
            ];
        }

        $out = [];
        $seen = [];
        $counts = [
            'skipped' => 0,
            'blocked_by_gate' => 0,
            'blocked_by_plan' => 0,
            'gate_redirected' => 0,
        ];

        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $resolved = $this->resolveCandidate($workspaceId, $userId, $candidate, [], $counts);
            if ($resolved === null) {
                $counts['skipped']++;
                continue;
            }

            $dedupeKey = $this->candidateDedupeKey($resolved);
            if ($dedupeKey !== '' && isset($seen[$dedupeKey])) {
                $counts['skipped']++;
                continue;
            }
            if ($dedupeKey !== '') {
                $seen[$dedupeKey] = true;
            }

            $out[] = $resolved;
        }

        return [
            'candidates' => $out,
            'skipped' => $counts['skipped'],
            'blocked_by_gate' => $counts['blocked_by_gate'],
            'blocked_by_plan' => $counts['blocked_by_plan'],
            'gate_redirected' => $counts['gate_redirected'],
        ];
    }

    public function countIneligibleOpenStarterTasks(int $workspaceId, int $userId): int
    {
        $count = 0;
        foreach ($this->openMarketplaceStarterTasks($workspaceId, $userId) as $task) {
            $metadata = $this->decodeMetadata($task['metadata_json'] ?? null);
            $skillKey = $this->normalizeKey((string) ($metadata['marketplace_skill_key'] ?? ''));
            if ($skillKey !== '' && $this->retirementReasonForSkill($workspaceId, $userId, $skillKey) !== null) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return array{retired_count:int,blocked_by_gate:int,blocked_by_plan:int}
     */
    public function retireIneligibleOpenStarterTasks(int $workspaceId, int $userId): array
    {
        $counts = [
            'retired_count' => 0,
            'blocked_by_gate' => 0,
            'blocked_by_plan' => 0,
        ];

        foreach ($this->openMarketplaceStarterTasks($workspaceId, $userId) as $task) {
            $metadata = $this->decodeMetadata($task['metadata_json'] ?? null);
            $skillKey = $this->normalizeKey((string) ($metadata['marketplace_skill_key'] ?? ''));
            if ($skillKey === '') {
                continue;
            }

            $reason = $this->retirementReasonForSkill($workspaceId, $userId, $skillKey);
            if ($reason === null) {
                continue;
            }

            $metadata['auto_retired_reason'] = $reason['reason'];
            $metadata['blocked_marketplace_skill_key'] = $skillKey;
            $metadata['retired_at'] = gmdate('c');
            $metadata['marketplace_access_state_at_retirement'] = $reason['access_state'];
            if ($reason['root_blocker_skill_key'] !== '') {
                $metadata['root_blocker_skill_key'] = $reason['root_blocker_skill_key'];
            }

            Database::execute(
                "UPDATE tasks
                 SET status = 'cancelled',
                     metadata_json = ?,
                     updated_at = NOW()
                 WHERE workspace_id = ?
                   AND id = ?
                   AND status NOT IN ('completed', 'cancelled')",
                [
                    json_encode($metadata, JSON_UNESCAPED_SLASHES),
                    $workspaceId,
                    (int) ($task['id'] ?? 0),
                ]
            );

            $counts['retired_count']++;
            if ($reason['reason'] === 'plan_locked') {
                $counts['blocked_by_plan']++;
            } elseif ($reason['reason'] === 'gate_locked') {
                $counts['blocked_by_gate']++;
            }
        }

        return $counts;
    }

    /**
     * @param array<string,mixed> $candidate
     * @param array<string,true> $visited
     * @param array{skipped:int,blocked_by_gate:int,blocked_by_plan:int,gate_redirected:int} $counts
     * @return array<string,mixed>|null
     */
    private function resolveCandidate(int $workspaceId, int $userId, array $candidate, array $visited, array &$counts): ?array
    {
        $skillKey = $this->candidateSkillKey($candidate);
        if ($skillKey === '') {
            return $candidate;
        }

        if (isset($visited[$skillKey])) {
            $counts['blocked_by_gate']++;
            return null;
        }
        $visited[$skillKey] = true;

        $access = $this->access->accessForModule($workspaceId, $userId, $skillKey);
        $state = (string) ($access['state'] ?? $access['access_state'] ?? '');

        if ($state === WorkspaceMarketplaceAccessService::STATE_LOCKED_BY_PLAN) {
            $counts['blocked_by_plan']++;
            return null;
        }

        if (in_array($state, [WorkspaceMarketplaceAccessService::STATE_LOCKED_BY_PREREQUISITES, WorkspaceMarketplaceAccessService::STATE_INSTALLED_LOCKED], true)) {
            $counts['blocked_by_gate']++;
            $rootKey = $this->normalizeKey((string) ($access['root_blocker_skill_key'] ?? ''));
            if ($rootKey === '' || $rootKey === $skillKey) {
                return null;
            }

            $rootAccess = $this->access->accessForModule($workspaceId, $userId, $rootKey);
            if ((string) ($rootAccess['state'] ?? '') === WorkspaceMarketplaceAccessService::STATE_LOCKED_BY_PLAN) {
                $counts['blocked_by_plan']++;
                return null;
            }

            $counts['gate_redirected']++;
            return $this->resolveCandidate(
                $workspaceId,
                $userId,
                $this->redirectCandidate($workspaceId, $candidate, $skillKey, $rootKey, $rootAccess),
                $visited,
                $counts
            );
        }

        if ($state === WorkspaceMarketplaceAccessService::STATE_READY) {
            return null;
        }

        if (in_array($state, [WorkspaceMarketplaceAccessService::STATE_AVAILABLE_TO_INSTALL, WorkspaceMarketplaceAccessService::STATE_INSTALLED_NEEDS_SETUP], true)) {
            return $candidate;
        }

        if (!empty($access['is_locked'])) {
            $counts['blocked_by_gate']++;
            return null;
        }

        return $candidate;
    }

    /**
     * @return array<string,mixed>
     */
    private function redirectCandidate(int $workspaceId, array $candidate, string $fromSkillKey, string $toSkillKey, array $access): array
    {
        $definition = $this->catalog->findForWorkspace($toSkillKey, $workspaceId, true) ?? [];
        $label = (string) ($access['label'] ?? $definition['label'] ?? ucwords(str_replace('_', ' ', $toSkillKey)));
        $isInstalled = !empty($access['is_installed']);
        $reason = trim((string) ($access['message'] ?? '') . ' ' . (string) ($access['why'] ?? ''));

        $candidate['title'] = $isInstalled ? 'Finish ' . $label . ' setup' : 'Add ' . $label . ' from the Marketplace';
        $candidate['reason'] = $reason !== '' ? $reason : 'Complete this prerequisite before opening downstream Marketplace modules.';
        $candidate['marketplace_skill_key'] = $toSkillKey;
        $candidate['marketplace_setup_url'] = (string) ($access['next_action_url'] ?? ('workspace_skills.php?module=' . rawurlencode($toSkillKey)));
        $candidate['marketplace_cta_label'] = (string) ($access['next_action_label'] ?? ($isInstalled ? 'Open setup' : 'Open Marketplace'));
        $candidate['source_recommendation_type'] = 'marketplace_module';
        $candidate['blocked_marketplace_skill_key'] = $fromSkillKey;
        $candidate['gate_redirected_from_skill_key'] = $fromSkillKey;
        $candidate['marketplace_access_state'] = (string) ($access['state'] ?? $access['access_state'] ?? '');
        $candidate['suggested_subtasks'] = $this->subtasksFromAccess($access, $label);

        return $candidate;
    }

    /**
     * @return list<string>
     */
    private function subtasksFromAccess(array $access, string $label): array
    {
        $subtasks = [];
        foreach ((array) ($access['pending_requirements'] ?? []) as $requirement) {
            if (!is_array($requirement)) {
                continue;
            }
            $text = trim((string) ($requirement['label'] ?? $requirement['module_label'] ?? ''));
            if ($text !== '') {
                $subtasks[] = $text;
            }
        }

        if ($subtasks === []) {
            $subtasks[] = !empty($access['is_installed']) ? 'Finish ' . $label . ' setup' : 'Install ' . $label;
        }
        $subtasks[] = 'Open Workspace Marketplace';

        return array_values(array_unique($subtasks));
    }

    /**
     * @return array{reason:string,access_state:string,root_blocker_skill_key:string}|null
     */
    private function retirementReasonForSkill(int $workspaceId, int $userId, string $skillKey): ?array
    {
        $access = $this->access->accessForModule($workspaceId, $userId, $skillKey);
        $state = (string) ($access['state'] ?? $access['access_state'] ?? '');

        if ($state === WorkspaceMarketplaceAccessService::STATE_LOCKED_BY_PLAN) {
            return [
                'reason' => 'plan_locked',
                'access_state' => $state,
                'root_blocker_skill_key' => '',
            ];
        }

        if (in_array($state, [WorkspaceMarketplaceAccessService::STATE_LOCKED_BY_PREREQUISITES, WorkspaceMarketplaceAccessService::STATE_INSTALLED_LOCKED], true)) {
            return [
                'reason' => 'gate_locked',
                'access_state' => $state,
                'root_blocker_skill_key' => (string) ($access['root_blocker_skill_key'] ?? ''),
            ];
        }

        if ($state === WorkspaceMarketplaceAccessService::STATE_READY) {
            return [
                'reason' => 'module_ready',
                'access_state' => $state,
                'root_blocker_skill_key' => '',
            ];
        }

        return null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function openMarketplaceStarterTasks(int $workspaceId, int $userId): array
    {
        if ($workspaceId <= 0 || $userId <= 0 || !Database::tableExists('tasks') || !Database::columnExists('tasks', 'metadata_json')) {
            return [];
        }

        return Database::query(
            "SELECT id, title, status, metadata_json
             FROM tasks
             WHERE workspace_id = ?
               AND assigned_to = ?
               AND status NOT IN ('completed', 'cancelled')
               AND JSON_UNQUOTE(JSON_EXTRACT(COALESCE(metadata_json, '{}'), '$.source_surface')) = 'ai_coach'
               AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(COALESCE(metadata_json, '{}'), '$.marketplace_skill_key')), '') <> ''",
            [$workspaceId, $userId]
        );
    }

    private function candidateSkillKey(array $candidate): string
    {
        return $this->normalizeKey((string) ($candidate['marketplace_skill_key'] ?? ''));
    }

    private function candidateDedupeKey(array $candidate): string
    {
        $skillKey = $this->candidateSkillKey($candidate);
        if ($skillKey !== '') {
            return 'skill:' . $skillKey;
        }

        $title = strtolower(trim((string) ($candidate['title'] ?? '')));
        return $title !== '' ? 'title:' . $title : '';
    }

    private function normalizeKey(string $key): string
    {
        return strtolower(trim($key));
    }

    /**
     * @param mixed $json
     * @return array<string,mixed>
     */
    private function decodeMetadata($json): array
    {
        if (is_array($json)) {
            return $json;
        }

        $json = trim((string) $json);
        if ($json === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }
}
