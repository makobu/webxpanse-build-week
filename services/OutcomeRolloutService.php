<?php
/**
 * Outcome Rollout Service
 *
 * Feature-flag and percentage rollout gate for the Outcome Layer.
 */

namespace CRM\Services;

use CRM\Modules\DealAutomationConfig;

class OutcomeRolloutService
{
    private DealAutomationConfig $configModule;

    public function __construct()
    {
        $this->configModule = new DealAutomationConfig();
    }

    public function getConfig(): array
    {
        try {
            return $this->configModule->get();
        } catch (\Throwable $e) {
            return [
                'outcome_layer_enabled' => true,
                'outcome_layer_checklist_enabled' => true,
                'outcome_layer_daily_focus_enabled' => true,
                'outcome_layer_metrics_enabled' => true,
                'outcome_layer_rollout_percent' => 100,
            ];
        }
    }

    public function isEnabledForUser(int $userId): bool
    {
        $cfg = $this->getConfig();
        if (empty($cfg['outcome_layer_enabled'])) {
            return false;
        }

        $rolloutPercent = max(0, min(100, (int) ($cfg['outcome_layer_rollout_percent'] ?? 0)));
        if ($rolloutPercent >= 100) {
            return true;
        }
        if ($rolloutPercent <= 0 || $userId <= 0) {
            return false;
        }

        return ($userId % 100) < $rolloutPercent;
    }

    public function isFeatureEnabled(int $userId, string $feature): bool
    {
        if (!$this->isEnabledForUser($userId)) {
            return false;
        }

        $cfg = $this->getConfig();
        $map = [
            'checklist' => 'outcome_layer_checklist_enabled',
            'daily_focus' => 'outcome_layer_daily_focus_enabled',
            'metrics' => 'outcome_layer_metrics_enabled',
        ];
        $key = $map[$feature] ?? '';
        if ($key === '') {
            return false;
        }

        return !empty($cfg[$key]);
    }
}
