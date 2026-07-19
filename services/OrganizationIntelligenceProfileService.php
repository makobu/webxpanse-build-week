<?php

namespace CRM\Services;

use CRM\Database;

class OrganizationIntelligenceProfileService
{
    public const MODELS = [
        'solo_founder',
        'multi_founder',
        'founder_led_team',
        'functional_team',
        'departmental_organization',
        'scaling_multi_team',
    ];

    public const ROLLOUT_STATES = ['shadow', 'v2_enabled', 'rolled_back', 'v1_retired'];

    public function get(int $workspaceId): array
    {
        $row = Database::queryOne(
            'SELECT * FROM organization_intelligence_profiles WHERE workspace_id = ? LIMIT 1',
            [$workspaceId]
        );
        if (!$row) {
            $inference = $this->infer($workspaceId);
            $this->persistInference($workspaceId, $inference);
            $row = Database::queryOne(
                'SELECT * FROM organization_intelligence_profiles WHERE workspace_id = ? LIMIT 1',
                [$workspaceId]
            ) ?: [];
        }
        return $this->normalize($row);
    }

    public function refreshInference(int $workspaceId): array
    {
        $inference = $this->infer($workspaceId);
        $this->persistInference($workspaceId, $inference);
        return $this->get($workspaceId);
    }

    public function confirm(int $workspaceId, string $model, array $founderUserIds, int $actorUserId): array
    {
        $model = strtolower(trim($model));
        if (!in_array($model, self::MODELS, true)) {
            throw new \InvalidArgumentException('Choose a valid organization operating model.');
        }
        $founderUserIds = $this->scopedUserIds($workspaceId, $founderUserIds);
        Database::execute(
            "INSERT INTO organization_intelligence_profiles
                (workspace_id, engine_version, confirmed_model, inferred_model, inference_version,
                 inference_confidence, inference_evidence_json, founder_user_ids_json, confirmed_by, confirmed_at)
             VALUES (?, 'v2', ?, ?, 'oi-model-v2', 'low', JSON_OBJECT(), ?, ?, NOW())
             ON DUPLICATE KEY UPDATE confirmed_model=VALUES(confirmed_model), founder_user_ids_json=VALUES(founder_user_ids_json),
                confirmed_by=VALUES(confirmed_by), confirmed_at=VALUES(confirmed_at)",
            [$workspaceId, $model, $model, json_encode($founderUserIds), $actorUserId]
        );
        return $this->refreshInference($workspaceId);
    }

    public function setRolloutState(int $workspaceId, string $state, int $actorUserId, int $rollbackDays = 14): array
    {
        $this->get($workspaceId);
        $state = strtolower(trim($state));
        if (!in_array($state, self::ROLLOUT_STATES, true)) {
            throw new \InvalidArgumentException('Choose a valid Organization Intelligence rollout state.');
        }
        if ($actorUserId > 0 && $this->scopedUserIds($workspaceId, [$actorUserId]) === []) {
            throw new \InvalidArgumentException('The rollout actor is outside the workspace scope.');
        }
        $engineVersion = in_array($state, ['v2_enabled', 'v1_retired'], true) ? 'v2' : 'v1';
        $rollbackDays = max(1, min(30, $rollbackDays));
        $rollbackUntil = $state === 'v2_enabled'
            ? date('Y-m-d H:i:s', strtotime('+' . $rollbackDays . ' days'))
            : null;
        Database::execute(
            "UPDATE organization_intelligence_profiles
             SET engine_version = ?, rollout_state = ?, promoted_at = CASE WHEN ? = 'v2_enabled' THEN NOW() ELSE promoted_at END,
                 promoted_by = CASE WHEN ? = 'v2_enabled' THEN ? ELSE promoted_by END,
                 rollback_until = ?
             WHERE workspace_id = ?",
            [$engineVersion, $state, $state, $state, $actorUserId > 0 ? $actorUserId : null, $rollbackUntil, $workspaceId]
        );
        return $this->get($workspaceId);
    }

    public function recordShadowComparison(int $workspaceId, array $comparison): void
    {
        $safe = [];
        foreach (['v1_health', 'v2_health', 'health_delta', 'v1_eligible_people', 'v2_eligible_people',
                     'evidence_coverage', 'operating_model', 'duration_ms', 'status', 'error_code'] as $key) {
            if (array_key_exists($key, $comparison) && (is_scalar($comparison[$key]) || $comparison[$key] === null)) {
                $safe[$key] = $comparison[$key];
            }
        }
        Database::execute(
            'UPDATE organization_intelligence_profiles SET last_shadow_comparison_at = NOW(), shadow_comparison_json = ? WHERE workspace_id = ?',
            [json_encode($safe, JSON_UNESCAPED_SLASHES), $workspaceId]
        );
    }

    public function infer(int $workspaceId): array
    {
        $members = Database::query(
            "SELECT wm.user_id, wm.is_owner, wm.role_slug, wm.department_id,
                    COALESCE(d.name, '') AS department_name
             FROM workspace_memberships wm
             LEFT JOIN departments d ON d.id = wm.department_id AND d.workspace_id = wm.workspace_id
             WHERE wm.workspace_id = ? AND wm.membership_status = 'active'",
            [$workspaceId]
        );
        $staffCount = count($members);
        $founders = [];
        $nonFounders = 0;
        $departmentMembers = [];
        foreach ($members as $member) {
            $isFounder = !empty($member['is_owner']) || strtolower((string) ($member['role_slug'] ?? '')) === 'owner';
            if ($isFounder) {
                $founders[] = (int) $member['user_id'];
            } else {
                $nonFounders++;
            }
            $departmentId = (int) ($member['department_id'] ?? 0);
            if ($departmentId > 0) {
                $departmentMembers[$departmentId] = ($departmentMembers[$departmentId] ?? 0) + 1;
            }
        }
        $qualifyingDepartmentIds = array_keys(array_filter($departmentMembers, static fn(int $count): bool => $count >= 2));
        $explicitDepartments = count($qualifyingDepartmentIds);
        $assignments = Database::query(
            "SELECT ufa.user_id, ufa.function_id, ufa.assignment_type, wm.department_id
             FROM user_function_assignments ufa
             JOIN workspace_memberships wm ON wm.workspace_id = ufa.workspace_id AND wm.user_id = ufa.user_id
             JOIN organization_functions f ON f.id = ufa.function_id AND f.workspace_id = ufa.workspace_id
             WHERE ufa.workspace_id = ? AND wm.membership_status = 'active'
               AND f.is_active = 1 AND COALESCE(f.relevance_status, 'active') = 'active'
               AND ufa.assignment_type IN ('owner','temporary_owner')",
            [$workspaceId]
        );
        $founderFunctionIds = [];
        $ownedFunctionIds = [];
        $functionOwnerUserIds = [];
        $functionDepartments = [];
        $departmentOwnerUserIds = [];
        foreach ($assignments as $assignment) {
            $functionId = (int) $assignment['function_id'];
            $userId = (int) $assignment['user_id'];
            $ownedFunctionIds[$functionId] = true;
            $functionOwnerUserIds[$userId] = true;
            if (in_array($userId, $founders, true)) {
                $founderFunctionIds[$functionId] = true;
            }
            $departmentId = (int) ($assignment['department_id'] ?? 0);
            if ($departmentId > 0 && in_array($departmentId, $qualifyingDepartmentIds, true)) {
                $functionDepartments[$functionId][$departmentId] = true;
                $departmentOwnerUserIds[$userId] = true;
            }
        }
        $departmentBackedFunctions = count(array_filter(
            $functionDepartments,
            static fn(array $departments): bool => count($departments) >= 1
        ));
        $distinctOwnerDepartments = [];
        foreach ($functionDepartments as $departments) {
            foreach (array_keys($departments) as $departmentId) {
                $distinctOwnerDepartments[$departmentId] = true;
            }
        }
        $activeCoreFunctionCount = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c FROM organization_functions
             WHERE workspace_id = ? AND is_active = 1 AND category = 'core'
               AND COALESCE(relevance_status, 'active') = 'active'",
            [$workspaceId]
        )['c'] ?? 0);
        $foundersCarryCore = count($founderFunctionIds) >= 3
            || ($activeCoreFunctionCount > 0 && count($founderFunctionIds) > ($activeCoreFunctionCount / 2));

        $model = 'founder_led_team';
        $confidence = 'moderate';
        if ($staffCount <= 1) {
            $model = 'solo_founder';
            $confidence = 'high';
        } elseif (count($founders) >= 2 && $nonFounders === 0) {
            $model = 'multi_founder';
            $confidence = 'high';
        } elseif ($staffCount >= 10 && $explicitDepartments >= 3 && count($distinctOwnerDepartments) >= 3
            && $departmentBackedFunctions >= 3 && count($departmentOwnerUserIds) >= 3) {
            $model = 'scaling_multi_team';
            $confidence = 'moderate';
        } elseif ($explicitDepartments >= 2 && count($distinctOwnerDepartments) >= 2
            && $departmentBackedFunctions >= 2 && count($departmentOwnerUserIds) >= 2) {
            $model = 'departmental_organization';
            $confidence = 'moderate';
        } elseif ($foundersCarryCore && $nonFounders > 0) {
            $model = 'founder_led_team';
            $confidence = 'moderate';
        } elseif (count($functionOwnerUserIds) >= 2 && $explicitDepartments < 2) {
            $model = 'functional_team';
            $confidence = 'moderate';
        }

        return [
            'model' => $model,
            'confidence' => $confidence,
            'founder_user_ids' => array_values(array_unique($founders)),
            'evidence' => [
                'staff_count' => $staffCount,
                'founder_count' => count($founders),
                'non_founder_count' => $nonFounders,
                'explicit_department_count' => $explicitDepartments,
                'owned_function_count' => count($ownedFunctionIds),
                'founder_function_count' => count($founderFunctionIds),
                'active_core_function_count' => $activeCoreFunctionCount,
                'function_owner_count' => count($functionOwnerUserIds),
                'department_backed_function_count' => $departmentBackedFunctions,
                'department_owner_count' => count($departmentOwnerUserIds),
                'owner_department_count' => count($distinctOwnerDepartments),
            ],
        ];
    }

    private function persistInference(int $workspaceId, array $inference): void
    {
        Database::execute(
            "INSERT INTO organization_intelligence_profiles
                (workspace_id, engine_version, inferred_model, inference_version, inference_confidence,
                 inference_evidence_json, founder_user_ids_json)
             VALUES (?, 'v2', ?, 'oi-model-v2', ?, ?, ?)
             ON DUPLICATE KEY UPDATE inferred_model=VALUES(inferred_model), inference_version=VALUES(inference_version),
                inference_confidence=VALUES(inference_confidence), inference_evidence_json=VALUES(inference_evidence_json),
                founder_user_ids_json=IF(confirmed_at IS NULL, VALUES(founder_user_ids_json), founder_user_ids_json)",
            [
                $workspaceId,
                (string) $inference['model'],
                (string) $inference['confidence'],
                json_encode($inference['evidence'], JSON_UNESCAPED_SLASHES),
                json_encode($inference['founder_user_ids']),
            ]
        );
    }

    private function normalize(array $row): array
    {
        $confirmed = trim((string) ($row['confirmed_model'] ?? ''));
        $inferred = trim((string) ($row['inferred_model'] ?? 'solo_founder'));
        $founders = json_decode((string) ($row['founder_user_ids_json'] ?? '[]'), true);
        $evidence = json_decode((string) ($row['inference_evidence_json'] ?? '{}'), true);
        $shadowComparison = json_decode((string) ($row['shadow_comparison_json'] ?? '{}'), true);
        return [
            'engine_version' => (string) ($row['engine_version'] ?? 'v2'),
            'rollout_state' => (string) ($row['rollout_state'] ?? 'v2_enabled'),
            'confirmed_model' => $confirmed !== '' ? $confirmed : null,
            'inferred_model' => $inferred,
            'effective_model' => $confirmed !== '' ? $confirmed : $inferred,
            'is_confirmed' => $confirmed !== '',
            'review_recommended' => $confirmed !== '' && $confirmed !== $inferred,
            'inference_version' => (string) ($row['inference_version'] ?? 'oi-model-v2'),
            'inference_confidence' => (string) ($row['inference_confidence'] ?? 'low'),
            'inference_evidence' => is_array($evidence) ? $evidence : [],
            'founder_user_ids' => array_values(array_filter(array_map('intval', is_array($founders) ? $founders : []))),
            'confirmed_by' => !empty($row['confirmed_by']) ? (int) $row['confirmed_by'] : null,
            'confirmed_at' => $row['confirmed_at'] ?? null,
            'promoted_at' => $row['promoted_at'] ?? null,
            'promoted_by' => !empty($row['promoted_by']) ? (int) $row['promoted_by'] : null,
            'rollback_until' => $row['rollback_until'] ?? null,
            'last_shadow_comparison_at' => $row['last_shadow_comparison_at'] ?? null,
            'shadow_comparison' => is_array($shadowComparison) ? $shadowComparison : [],
        ];
    }

    private function scopedUserIds(int $workspaceId, array $userIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn(int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = Database::query(
            "SELECT user_id FROM workspace_memberships WHERE workspace_id = ? AND membership_status = 'active' AND user_id IN ({$placeholders})",
            array_merge([$workspaceId], $ids)
        );
        return array_map('intval', array_column($rows, 'user_id'));
    }
}
