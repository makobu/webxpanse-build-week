<?php

namespace CRM\Services;

use CRM\Database;

class WorkspaceMarketplaceSetupJourneyService
{
    private WorkspaceSkillCatalogService $catalog;

    public function __construct(?WorkspaceSkillCatalogService $catalog = null)
    {
        $this->catalog = $catalog ?? new WorkspaceSkillCatalogService();
    }

    public function journeysBySkill(
        int $workspaceId,
        int $userId,
        array $recommendations,
        array $installedByKey,
        array $readinessByKey
    ): array {
        if ($workspaceId <= 0) {
            return [];
        }

        $persisted = $this->persistedBySkill($workspaceId);
        $journeys = [];

        foreach ($recommendations as $recommendation) {
            if (!is_array($recommendation)) {
                continue;
            }

            $skillKey = $this->normalizeKey((string) ($recommendation['skill_key'] ?? ''));
            if ($skillKey === '') {
                continue;
            }

            $module = $this->catalog->find($skillKey);
            if ($module === null) {
                continue;
            }

            $installed = isset($installedByKey[$skillKey]) || !empty($recommendation['is_installed']);
            $readiness = (array) ($readinessByKey[$skillKey] ?? $recommendation['readiness'] ?? []);
            $setupUrl = (string) ($recommendation['setup_url'] ?? $module['settings_schema']['settings_url'] ?? $module['plugin_metadata']['setup_url'] ?? $module['navigation']['url'] ?? 'workspace_skills.php');
            $steps = $this->buildSteps($module, $recommendation, $installed, $readiness, (array) ($persisted[$skillKey] ?? []));

            if ($steps === []) {
                continue;
            }

            $completed = count(array_filter($steps, static fn(array $step): bool => (string) ($step['status'] ?? '') === 'completed'));
            $skipped = count(array_filter($steps, static fn(array $step): bool => (string) ($step['status'] ?? '') === 'skipped'));

            $journeys[$skillKey] = [
                'skill_key' => $skillKey,
                'label' => (string) ($module['label'] ?? $recommendation['label'] ?? $skillKey),
                'setup_url' => $setupUrl !== '' ? $setupUrl : 'workspace_skills.php',
                'is_installed' => $installed,
                'readiness' => $readiness,
                'steps' => $steps,
                'progress' => [
                    'total' => count($steps),
                    'completed' => $completed,
                    'skipped' => $skipped,
                    'pending' => max(0, count($steps) - $completed - $skipped),
                ],
            ];
        }

        ksort($journeys);
        return $journeys;
    }

    public function continuityBySkill(
        int $workspaceId,
        int $userId,
        array $recommendations,
        array $installedByKey = [],
        array $readinessByKey = [],
        int $previewLimit = 3
    ): array {
        $journeys = $this->journeysBySkill($workspaceId, $userId, $recommendations, $installedByKey, $readinessByKey);
        if ($journeys === []) {
            return [];
        }

        $out = [];
        foreach ($journeys as $skillKey => $journey) {
            $continuity = $this->shapeContinuity($journey, $previewLimit);
            if ($continuity !== []) {
                $out[$skillKey] = $continuity;
            }
        }

        ksort($out);
        return $out;
    }

    public function updateStepStatus(
        int $workspaceId,
        int $userId,
        string $skillKey,
        string $stepKey,
        string $status,
        string $label,
        string $source,
        array $metadata = []
    ): void {
        if ($workspaceId <= 0 || !$this->tableReady()) {
            return;
        }

        $skillKey = $this->normalizeKey($skillKey);
        $stepKey = $this->normalizeStepKey($stepKey);
        if ($skillKey === '' || $stepKey === '') {
            throw new \InvalidArgumentException('Setup journey step is required.');
        }
        if ($this->catalog->find($skillKey) === null) {
            throw new \InvalidArgumentException('Unknown workspace skill: ' . $skillKey);
        }

        $status = $this->normalizeStatus($status);
        $label = trim($label) !== '' ? trim($label) : 'Setup step';
        $source = trim($source) !== '' ? trim($source) : 'manual';

        Database::execute(
            "INSERT INTO workspace_marketplace_setup_journey_steps
                (workspace_id, user_id, skill_key, step_key, label, status, source, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                user_id = VALUES(user_id),
                label = VALUES(label),
                status = VALUES(status),
                source = VALUES(source),
                metadata_json = VALUES(metadata_json),
                updated_at = NOW()",
            [
                $workspaceId,
                $userId > 0 ? $userId : null,
                $skillKey,
                $stepKey,
                $label,
                $status,
                substr($source, 0, 40),
                json_encode($metadata, JSON_UNESCAPED_SLASHES),
            ]
        );
    }

    public function tableReady(): bool
    {
        try {
            return (bool) Database::queryOne(
                "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
                ['workspace_marketplace_setup_journey_steps']
            );
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function buildSteps(array $module, array $recommendation, bool $installed, array $readiness, array $persisted): array
    {
        $steps = [];
        $label = (string) ($module['label'] ?? $recommendation['label'] ?? 'Marketplace item');

        if (!$installed) {
            $steps[] = [
                'step_key' => 'install_module',
                'label' => 'Install ' . $label,
                'status' => 'pending',
                'source' => 'install',
                'manual' => false,
                'metadata' => ['action' => 'install'],
            ];
        } elseif (!empty($readiness) && empty($readiness['ready'])) {
            $steps[] = [
                'step_key' => 'readiness_blocker',
                'label' => (string) ($readiness['message'] ?? 'Open setup to finish configuration.'),
                'status' => 'pending',
                'source' => 'readiness',
                'manual' => false,
                'metadata' => ['status' => (string) ($readiness['status'] ?? 'needs_setup')],
            ];
        }

        foreach ((array) ($module['setup_steps'] ?? []) as $setupStep) {
            $setupStep = trim((string) $setupStep);
            if ($setupStep === '') {
                continue;
            }
            $stepKey = 'setup_' . substr(hash('sha1', $setupStep), 0, 16);
            $steps[] = $this->manualStep($stepKey, $setupStep, 'catalog', $persisted[$stepKey] ?? null);
        }

        foreach ((array) ($module['dependencies'] ?? []) as $dependency) {
            $dependency = trim((string) $dependency);
            if ($dependency === '') {
                continue;
            }
            $stepKey = 'dependency_' . substr(hash('sha1', $dependency), 0, 16);
            $steps[] = $this->manualStep($stepKey, 'Confirm ' . $dependency, 'dependency', $persisted[$stepKey] ?? null);
        }

        $seen = [];
        $deduped = [];
        foreach ($steps as $step) {
            $stepKey = (string) ($step['step_key'] ?? '');
            if ($stepKey === '' || isset($seen[$stepKey])) {
                continue;
            }
            $seen[$stepKey] = true;
            $deduped[] = $step;
        }

        return $deduped;
    }

    private function shapeContinuity(array $journey, int $previewLimit): array
    {
        $steps = array_values(array_filter((array) ($journey['steps'] ?? []), 'is_array'));
        if ($steps === []) {
            return [];
        }

        $nextStep = null;
        foreach ($steps as $step) {
            if ((string) ($step['status'] ?? 'pending') === 'pending') {
                $nextStep = $this->shapeContinuityStep($step);
                break;
            }
        }
        if ($nextStep === null) {
            $nextStep = $this->shapeContinuityStep($steps[0]);
        }

        $preview = [];
        foreach (array_slice($steps, 0, max(1, $previewLimit)) as $step) {
            $preview[] = $this->shapeContinuityStep($step);
        }

        $readiness = (array) ($journey['readiness'] ?? []);
        $readinessStatus = (string) ($readiness['status'] ?? (!empty($readiness['ready']) ? 'ready' : ''));

        return [
            'skill_key' => (string) ($journey['skill_key'] ?? ''),
            'label' => (string) ($journey['label'] ?? 'Marketplace item'),
            'setup_url' => (string) ($journey['setup_url'] ?? 'workspace_skills.php'),
            'progress' => (array) ($journey['progress'] ?? []),
            'next_step' => $nextStep,
            'steps_preview' => $preview,
            'is_installed' => !empty($journey['is_installed']),
            'readiness_status' => $readinessStatus !== '' ? $readinessStatus : 'unknown',
            'tracking_metadata' => [
                'has_setup_journey' => true,
                'next_step_key' => (string) ($nextStep['step_key'] ?? ''),
                'total_steps' => (int) (($journey['progress']['total'] ?? 0)),
                'pending_steps' => (int) (($journey['progress']['pending'] ?? 0)),
            ],
        ];
    }

    private function shapeContinuityStep(array $step): array
    {
        return [
            'step_key' => (string) ($step['step_key'] ?? ''),
            'label' => (string) ($step['label'] ?? 'Setup step'),
            'status' => $this->normalizeStatus((string) ($step['status'] ?? 'pending')),
        ];
    }

    private function manualStep(string $stepKey, string $label, string $source, ?array $persisted): array
    {
        return [
            'step_key' => $stepKey,
            'label' => $label,
            'status' => $this->normalizeStatus((string) ($persisted['status'] ?? 'pending')),
            'source' => $source,
            'manual' => true,
            'metadata' => $persisted['metadata'] ?? [],
        ];
    }

    private function persistedBySkill(int $workspaceId): array
    {
        if (!$this->tableReady()) {
            return [];
        }

        $rows = Database::query(
            "SELECT skill_key, step_key, label, status, source, metadata_json
             FROM workspace_marketplace_setup_journey_steps
             WHERE workspace_id = ?
             ORDER BY updated_at DESC, id DESC",
            [$workspaceId]
        );

        $out = [];
        foreach ($rows as $row) {
            $skillKey = $this->normalizeKey((string) ($row['skill_key'] ?? ''));
            $stepKey = $this->normalizeStepKey((string) ($row['step_key'] ?? ''));
            if ($skillKey === '' || $stepKey === '' || isset($out[$skillKey][$stepKey])) {
                continue;
            }
            $out[$skillKey][$stepKey] = [
                'label' => (string) ($row['label'] ?? ''),
                'status' => $this->normalizeStatus((string) ($row['status'] ?? 'pending')),
                'source' => (string) ($row['source'] ?? ''),
                'metadata' => $this->decodeAssoc($row['metadata_json'] ?? null),
            ];
        }

        return $out;
    }

    private function normalizeStatus(string $status): string
    {
        $status = strtolower(trim($status));
        return in_array($status, ['pending', 'completed', 'skipped'], true) ? $status : 'pending';
    }

    private function normalizeKey(string $key): string
    {
        return strtolower(trim(preg_replace('/[^a-z0-9_]+/', '_', $key) ?? ''));
    }

    private function normalizeStepKey(string $key): string
    {
        return strtolower(trim(preg_replace('/[^a-z0-9_]+/', '_', $key) ?? ''));
    }

    private function decodeAssoc(mixed $json): array
    {
        $decoded = is_string($json) && trim($json) !== '' ? json_decode($json, true) : [];
        return is_array($decoded) ? $decoded : [];
    }
}
