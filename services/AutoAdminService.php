<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\ColdOutreachWarmupConfig;
use CRM\Modules\AIAutoResponderConfig;
use CRM\Modules\CommercialAutomationConfig;
use CRM\Modules\DealAutomationConfig;
use CRM\Modules\UserPreferences;

class AutoAdminService
{
    public const GLOBAL_USER_ID = 1;

    /** @var array<int, string> */
    private const MANAGED_TABS = [
        'ai',
        'ai_autoresponder',
        'commercial_automation',
        'deal_automation',
        'workflow_automation',
    ];

    private UserPreferences $userPreferences;
    private PlatformAutoAdminSettingsService $platformSettings;
    private WorkspaceAutoAdminSettingsService $workspaceSettings;
    private AutomationProgressionPolicyService $progressionPolicy;

    public function __construct(
        ?UserPreferences $userPreferences = null,
        ?PlatformAutoAdminSettingsService $platformSettings = null,
        ?WorkspaceAutoAdminSettingsService $workspaceSettings = null,
        ?AutomationProgressionPolicyService $progressionPolicy = null
    )
    {
        $this->userPreferences = $userPreferences ?? new UserPreferences();
        $this->platformSettings = $platformSettings ?? new PlatformAutoAdminSettingsService($this->userPreferences);
        $this->workspaceSettings = $workspaceSettings ?? new WorkspaceAutoAdminSettingsService();
        $this->progressionPolicy = $progressionPolicy ?? new AutomationProgressionPolicyService();
    }

    public function isEnabled(): bool
    {
        return $this->platformSettings->isEnabled();
    }

    public function isAutoAdminEnabled(): bool
    {
        return $this->isEnabled();
    }

    public function isEnabledForWorkspace(?int $workspaceId): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        $workspaceId = (int) ($workspaceId ?? 0);
        if ($workspaceId <= 0) {
            return false;
        }

        return $this->workspaceSettings->isEnabled($workspaceId);
    }

    /**
     * @return array<string,mixed>
     */
    public function getWorkspaceState(int $workspaceId): array
    {
        return $this->workspaceSettings->getSettings($workspaceId);
    }

    /**
     * @return array<string,mixed>
     */
    public function setWorkspaceEnabled(int $workspaceId, bool $enabled, ?int $actingUserId = null): array
    {
        return $this->workspaceSettings->setEnabled(
            $workspaceId,
            $enabled,
            $actingUserId !== null && $actingUserId > 0 ? $actingUserId : 0
        );
    }

    public function setPlatformEnabled(bool $enabled, ?int $actingUserId = null): void
    {
        $actorUserId = $actingUserId !== null && $actingUserId > 0 ? $actingUserId : 0;
        $this->platformSettings->setEnabled($enabled, $actorUserId);

        // Keep the legacy preference as compatibility data while the platform table is authoritative.
        $this->userPreferences->setAutoAdminEnabled(self::GLOBAL_USER_ID, $enabled);
    }

    /**
     * @return array<string,mixed>
     */
    public function setWorkspaceFreeze(int $workspaceId, bool $manualFreeze, string $freezeReason = '', ?int $actingUserId = null): array
    {
        return $this->workspaceSettings->setFreeze(
            $workspaceId,
            $manualFreeze,
            $freezeReason,
            $actingUserId !== null && $actingUserId > 0 ? $actingUserId : 0
        );
    }

    /**
     * @param array<string,mixed> $targetModes
     * @return array<string,mixed>
     */
    public function setWorkspaceTargetModes(int $workspaceId, array $targetModes, ?int $actingUserId = null): array
    {
        return $this->workspaceSettings->setTargetModes(
            $workspaceId,
            $targetModes,
            $actingUserId !== null && $actingUserId > 0 ? $actingUserId : 0
        );
    }

    public function setEnabled(bool $enabled, ?int $actingUserId = null, ?int $workspaceId = null): void
    {
        $this->setPlatformEnabled($enabled, $actingUserId);
    }

    public function isManagedTab(string $tabKey): bool
    {
        return in_array($tabKey, self::MANAGED_TABS, true);
    }

    public function isAutoAdminManagedTab(string $tabKey): bool
    {
        return $this->isManagedTab($tabKey);
    }

    /**
     * @return array<int, string>
     */
    public function getManagedTabs(): array
    {
        return self::MANAGED_TABS;
    }

    /**
     * @return array<string, mixed>
     */
    public function getManagedDefaults(): array
    {
        return [
            'ai_coach_enabled' => true,
            'ai_auto_task_completion_enabled' => true,
            'ai_auto_task_completion_min_confidence' => 0.95,
            'ai_incident_alerts_enabled' => true,
            'ai_incident_check_enabled' => true,
            'ai_autonomous_threshold_tuning_enabled' => true,
            'ai_calibration_min_sample_size' => 30,
            'ai_calibration_daily_change_cap' => 0.02,
            'ai_calibration_rolling_change_cap' => 0.05,
            'ai_autoresponder' => [
                'mode' => 'draft_only',
                'default_confidence_threshold' => 0.85,
            ],
            'commercial_automation' => [
                'enabled' => true,
                'mode' => 'auto_safe',
            ],
            'cold_outreach_warmup' => [
                'email' => [
                    'enabled' => true,
                    'initial_daily_cold_limit' => 10,
                    'current_daily_cold_limit' => 10,
                    'auto_admin_warmup_enabled' => true,
                    'weekly_increment' => 5,
                    'max_limit' => 50,
                ],
                'whatsapp' => [
                    'enabled' => true,
                    'initial_daily_cold_limit' => 10,
                    'current_daily_cold_limit' => 10,
                    'auto_admin_warmup_enabled' => true,
                    'weekly_increment' => 5,
                    'max_limit' => 50,
                ],
            ],
            'deal_automation' => [
                'enabled' => true,
                'mode' => 'suggest_only',
                'min_confidence' => 0.85,
                'require_approval_terminal' => true,
                'dry_run' => false,
            ],
            'workflow_automation' => [
                'autonomy_mode' => 'auto_safe',
                'promotion_status' => 'auto_safe',
                'block_customer_facing_full_auto' => true,
                'max_daily_auto_actions' => 50,
                'max_customer_facing_risk' => 0.95,
            ],
        ];
    }

    public function getAutoAdminManagedDefaults(): array
    {
        return $this->getManagedDefaults();
    }

    /**
     * @param array<string, bool> $canTab
     */
    public function getFirstAllowedUnlockedTab(array $canTab, string $fallback = 'general'): string
    {
        foreach ($canTab as $tabKey => $allowed) {
            if ($allowed) {
                return $tabKey;
            }
        }

        return $fallback;
    }

    /**
     * @param array<string, bool> $canTab
     * @return array{tab:string, redirected:bool}
     */
    public function normalizeRequestedSettingsTab(string $requestedTab, array $canTab): array
    {
        return ['tab' => $requestedTab, 'redirected' => false];
    }

    public function applyManagedDefaults(?int $actingUserId = null, ?int $workspaceId = null): void
    {
        if ($workspaceId !== null && $workspaceId > 0) {
            $this->applyManagedDefaultsForWorkspace($workspaceId, $actingUserId);
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function applyManagedDefaultsForWorkspace(int $workspaceId, ?int $actingUserId = null, bool $dryRun = false): array
    {
        $workspaceId = max(0, $workspaceId);
        $actorUserId = $actingUserId !== null && $actingUserId > 0 ? $actingUserId : self::GLOBAL_USER_ID;
        $state = $this->workspaceSettings->getSettings($workspaceId);

        if ($workspaceId <= 0 || !$this->isEnabledForWorkspace($workspaceId)) {
            if (!$dryRun) {
                $this->workspaceSettings->recordEvent(
                    $workspaceId,
                    'blocked',
                    $state,
                    $state,
                    'Workspace Auto Admin is not enabled.',
                    $actorUserId
                );
            }
            return ['applied' => false, 'reason' => 'workspace_auto_admin_disabled', 'state' => $state];
        }

        if (!empty($state['manual_freeze'])) {
            if (!$dryRun) {
                $this->workspaceSettings->recordSkippedFrozen($workspaceId, $actorUserId);
            }
            return ['applied' => false, 'reason' => 'workspace_auto_admin_frozen', 'state' => $state];
        }

        $defaults = $this->getManagedDefaults();
        $evaluation = $this->progressionPolicy->evaluateWorkspace($workspaceId, $defaults, $state);
        $effectiveModes = (array) ($evaluation['effective_modes'] ?? []);
        $readinessSnapshot = (array) ($evaluation['readiness_snapshot'] ?? []);
        $stateAfterEvaluation = array_merge($state, [
            'effective_modes' => $effectiveModes,
            'readiness_snapshot' => $readinessSnapshot,
        ]);
        $runtimeBlockedDomains = (array) ($evaluation['runtime_blocked_domains'] ?? []);
        $downgradedModes = $this->downgradedModes((array) ($state['target_modes'] ?? []), $effectiveModes);
        $promotedModes = $this->promotedModes((array) ($state['effective_modes'] ?? []), $effectiveModes);

        if ($dryRun) {
            return [
                'applied' => false,
                'dry_run' => true,
                'effective_modes' => $effectiveModes,
                'readiness_snapshot' => $readinessSnapshot,
                'runtime_blocked_domains' => array_values(array_unique(array_map('strval', $runtimeBlockedDomains))),
                'downgraded_modes' => $downgradedModes,
                'promoted_modes' => $promotedModes,
                'state' => $stateAfterEvaluation,
            ];
        }

        $beforeState = $state;
        AsyncWorkspaceRunner::runWithWorkspace($workspaceId, function () use ($defaults, $workspaceId, $actorUserId, $effectiveModes, $evaluation): void {
            $this->applyWorkspaceDefaults($workspaceId, $actorUserId, $defaults, $effectiveModes, $evaluation);
        }, $actorUserId, 'Auto Admin workspace is missing or inactive.');

        $state = $this->workspaceSettings->recordEvaluation(
            $workspaceId,
            $effectiveModes,
            $readinessSnapshot,
            $actorUserId,
            true,
            'Workspace Auto Admin restored managed defaults.'
        );

        if (!empty($runtimeBlockedDomains)) {
            $this->workspaceSettings->recordRuntimeBlocked(
                $workspaceId,
                $beforeState,
                $state,
                'Runtime controls are capping Auto Admin domains: ' . implode(', ', array_map('strval', $runtimeBlockedDomains)) . '.',
                $actorUserId
            );
        }

        if (!empty($downgradedModes)) {
            $this->workspaceSettings->recordDowngraded(
                $workspaceId,
                $beforeState,
                $state,
                'Workspace Auto Admin capped modes by readiness: ' . implode(', ', $downgradedModes) . '.',
                $actorUserId
            );
        }

        if (!empty($promotedModes)) {
            $this->workspaceSettings->recordPromoted(
                $workspaceId,
                $beforeState,
                $state,
                'Workspace Auto Admin effective modes advanced: ' . implode(', ', $promotedModes) . '.',
                $actorUserId
            );
        }

        return [
            'applied' => true,
            'effective_modes' => $effectiveModes,
            'readiness_snapshot' => $readinessSnapshot,
            'state' => $state,
        ];
    }

    /**
     * @param array<string,mixed> $defaults
     * @param array<string,string> $effectiveModes
     * @param array<string,mixed> $evaluation
     */
    private function applyWorkspaceDefaults(int $workspaceId, int $actorUserId, array $defaults, array $effectiveModes, array $evaluation): void
    {
        if (!empty($defaults['ai_coach_enabled'])) {
            (new AICoachWorkspaceSetupService())->setWorkspaceEnabled(
                $workspaceId,
                $actorUserId,
                true
            );
        }

        $autoResponderConfig = new AIAutoResponderConfig();
        $currentAutoResponderConfig = $autoResponderConfig->get($workspaceId);
        $autoResponderDefaults = $defaults['ai_autoresponder'];
        $autoResponderEnabled = !empty($currentAutoResponderConfig['enabled']);
        $autoResponderConfig->save([
            'enabled' => $autoResponderEnabled,
            'mode' => (string) ($effectiveModes['ai_autoresponder'] ?? $autoResponderDefaults['mode']),
            'default_confidence_threshold' => $autoResponderDefaults['default_confidence_threshold'],
        ], $workspaceId);

        $commercialDefaults = $defaults['commercial_automation'];
        (new CommercialAutomationConfig())->save([
            'enabled' => (bool) $commercialDefaults['enabled'],
            'mode' => (string) ($effectiveModes['commercial_automation'] ?? $commercialDefaults['mode']),
            'auto_send_enabled' => !empty($evaluation['commercial_auto_send_ready']),
        ], $workspaceId);

        $warmupConfig = new ColdOutreachWarmupConfig();
        foreach ((array) ($defaults['cold_outreach_warmup'] ?? []) as $channel => $channelDefaults) {
            $existing = $warmupConfig->get((string) $channel, $workspaceId);
            $warmupConfig->save((string) $channel, [
                'enabled' => !empty($channelDefaults['enabled']),
                'initial_daily_cold_limit' => (int) ($existing['initial_daily_cold_limit'] ?? $channelDefaults['initial_daily_cold_limit'] ?? 10),
                'current_daily_cold_limit' => (int) ($existing['current_daily_cold_limit'] ?? $channelDefaults['current_daily_cold_limit'] ?? 10),
                'auto_admin_warmup_enabled' => !empty($channelDefaults['auto_admin_warmup_enabled']),
                'weekly_increment' => (int) ($existing['weekly_increment'] ?? $channelDefaults['weekly_increment'] ?? 5),
                'max_limit' => (int) ($existing['max_limit'] ?? $channelDefaults['max_limit'] ?? 50),
                'last_auto_adjusted_at' => $existing['last_auto_adjusted_at'] ?? null,
            ], $actorUserId, $workspaceId);
        }

        $dealDefaults = $defaults['deal_automation'];
        (new DealAutomationConfig())->save([
            'enabled' => (bool) $dealDefaults['enabled'],
            'mode' => (string) ($effectiveModes['deal_automation'] ?? $dealDefaults['mode']),
            'min_confidence' => (float) $dealDefaults['min_confidence'],
            'require_approval_terminal' => (bool) $dealDefaults['require_approval_terminal'],
            'dry_run' => (bool) $dealDefaults['dry_run'],
        ], $workspaceId);

        $workflowDefaults = (array) ($defaults['workflow_automation'] ?? []);
        (new WorkflowAutomationControlService())->saveWorkspaceControl([
            'autonomy_mode' => (string) ($effectiveModes['workflow_automation'] ?? $workflowDefaults['autonomy_mode'] ?? 'auto_safe'),
            'promotion_status' => (string) ($effectiveModes['workflow_automation'] ?? $workflowDefaults['promotion_status'] ?? 'auto_safe'),
            'metadata' => [
                'block_customer_facing_full_auto' => !empty($workflowDefaults['block_customer_facing_full_auto']),
                'max_daily_auto_actions' => (int) ($workflowDefaults['max_daily_auto_actions'] ?? 50),
                'max_customer_facing_risk' => (float) ($workflowDefaults['max_customer_facing_risk'] ?? 0.95),
            ],
        ], $actorUserId);
    }

    /**
     * @return array<int,int>
     */
    private function workspaceMemberUserIds(int $workspaceId): array
    {
        if ($workspaceId <= 0 || !Database::tableExists('workspace_memberships')) {
            return [];
        }

        $ids = [];
        try {
            foreach (Database::query(
                "SELECT user_id
                 FROM workspace_memberships
                 WHERE workspace_id = ?
                   AND membership_status = 'active'",
                [$workspaceId]
            ) as $row) {
                $candidateUserId = (int) ($row['user_id'] ?? 0);
                if ($candidateUserId > 0) {
                    $ids[] = $candidateUserId;
                }
            }
        } catch (\Throwable $e) {
            return [];
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param array<string,mixed> $targetModes
     * @param array<string,mixed> $effectiveModes
     * @return array<int,string>
     */
    private function downgradedModes(array $targetModes, array $effectiveModes): array
    {
        $downgraded = [];
        foreach ($effectiveModes as $domain => $effectiveMode) {
            $targetMode = (string) ($targetModes[$domain] ?? '');
            if ($targetMode !== '' && $targetMode !== (string) $effectiveMode) {
                $downgraded[] = (string) $domain . ' ' . $targetMode . '->' . (string) $effectiveMode;
            }
        }

        return $downgraded;
    }

    /**
     * @param array<string,mixed> $beforeModes
     * @param array<string,mixed> $afterModes
     * @return array<int,string>
     */
    private function promotedModes(array $beforeModes, array $afterModes): array
    {
        $order = [
            'manual' => 0,
            'off' => 0,
            'draft_only' => 1,
            'suggest_only' => 1,
            'hybrid' => 2,
            'auto_safe' => 2,
            'full_auto' => 3,
        ];
        $promoted = [];
        foreach ($afterModes as $domain => $afterMode) {
            $beforeMode = (string) ($beforeModes[$domain] ?? 'off');
            $afterMode = (string) $afterMode;
            if (($order[$afterMode] ?? 0) > ($order[$beforeMode] ?? 0)) {
                $promoted[] = (string) $domain . ' ' . $beforeMode . '->' . $afterMode;
            }
        }

        return $promoted;
    }
}
