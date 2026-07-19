<?php
/**
 * Deal Automation Configuration
 * Loads and saves deal automation settings from deal_automation_config table
 */

namespace CRM\Modules;

use CRM\Database;
use CRM\Services\WorkspaceContext;

class DealAutomationConfig
{
    private const DEFAULT_CONFIG = [
        'enabled' => false,
        'mode' => 'suggest_only',
        'min_confidence' => 0.85,
        'lookback_days' => 14,
        'cooldown_hours' => 24,
        'require_approval_terminal' => true,
        'min_terminal_confidence' => 0.92,
        'inactivity_days_for_loss' => 14,
        'allow_multi_stage_jump' => false,
        'dry_run' => false,
        'reopen_lost_on_reengagement' => false,
        'auto_create_from_inbound' => false,
        'auto_create_require_non_negative' => true,
        'auto_create_require_meaningful_reply' => true,
        'auto_create_min_message_chars' => 20,
        'auto_create_dedupe_hours' => 24,
        'auto_create_channels' => [
            'email' => true,
            'whatsapp' => true,
            'sms' => false,
        ],
        'inbox_triage_enabled' => false,
        'inbox_triage_auto_apply' => true,
        'inbox_triage_channels' => [
            'email' => true,
            'whatsapp' => true,
        ],
        'inbox_triage_min_confidence' => 0.80,
        'inbox_triage_task_due_hours' => 24,
        'inbox_triage_dedupe_hours' => 24,
        'inbox_triage_owner_fallback' => 'round_robin',
        'inbox_triage_negative_phrase_blocklist' => "not interested\nunsubscribe\nstop\nleave me alone\nno thanks",
        'inbox_triage_opt_out_phrase_blocklist' => "unsubscribe\nstop\nopt out\ndo not contact",
        'outcome_layer_enabled' => true,
        'outcome_layer_checklist_enabled' => true,
        'outcome_layer_daily_focus_enabled' => true,
        'outcome_layer_metrics_enabled' => true,
        'outcome_layer_rollout_percent' => 100,
        'transitions' => [],
        'schema_version' => 1,
    ];

    /**
     * Get full config (singleton row merged with defaults)
     */
    public function get(?int $workspaceId = null): array
    {
        $workspaceId = $this->resolveWorkspaceId($workspaceId);
        $row = null;
        if ($workspaceId > 0 && Database::tableExists('workspace_deal_automation_config')) {
            $row = Database::queryOne(
                "SELECT * FROM workspace_deal_automation_config WHERE workspace_id = ? LIMIT 1",
                [$workspaceId]
            );
        }
        if (!$row) {
            $row = Database::queryOne("SELECT * FROM deal_automation_config WHERE id = 1");
        }
        if (!$row) {
            return self::DEFAULT_CONFIG;
        }

        $config = array_merge(self::DEFAULT_CONFIG, [
            'enabled' => (bool) ($row['enabled'] ?? false),
            'mode' => $row['mode'] ?? 'suggest_only',
            'min_confidence' => (float) ($row['min_confidence'] ?? 0.85),
            'lookback_days' => (int) ($row['lookback_days'] ?? 14),
            'cooldown_hours' => (int) ($row['cooldown_hours'] ?? 24),
            'require_approval_terminal' => (bool) ($row['require_approval_terminal'] ?? true),
            'min_terminal_confidence' => (float) ($row['min_terminal_confidence'] ?? 0.92),
            'inactivity_days_for_loss' => (int) ($row['inactivity_days_for_loss'] ?? 14),
            'allow_multi_stage_jump' => (bool) ($row['allow_multi_stage_jump'] ?? false),
            'dry_run' => (bool) ($row['dry_run'] ?? false),
            'reopen_lost_on_reengagement' => (bool) ($row['reopen_lost_on_reengagement'] ?? false),
            'schema_version' => (int) ($row['schema_version'] ?? 1),
        ]);

        $configJson = $row['config_json'] ?? null;
        if ($configJson) {
            $decoded = is_string($configJson) ? json_decode($configJson, true) : $configJson;
            if (is_array($decoded)) {
                if (!empty($decoded['transitions'])) {
                    $config['transitions'] = $decoded['transitions'];
                }
                if (isset($decoded['auto_create_from_inbound'])) {
                    $config['auto_create_from_inbound'] = (bool) $decoded['auto_create_from_inbound'];
                }
                if (isset($decoded['auto_create_require_non_negative'])) {
                    $config['auto_create_require_non_negative'] = (bool) $decoded['auto_create_require_non_negative'];
                }
                if (isset($decoded['auto_create_require_meaningful_reply'])) {
                    $config['auto_create_require_meaningful_reply'] = (bool) $decoded['auto_create_require_meaningful_reply'];
                }
                if (isset($decoded['auto_create_min_message_chars'])) {
                    $config['auto_create_min_message_chars'] = max(1, min(1000, (int) $decoded['auto_create_min_message_chars']));
                }
                if (isset($decoded['auto_create_dedupe_hours'])) {
                    $config['auto_create_dedupe_hours'] = max(1, min(168, (int) $decoded['auto_create_dedupe_hours']));
                }
                if (isset($decoded['auto_create_channels']) && is_array($decoded['auto_create_channels'])) {
                    $config['auto_create_channels'] = array_merge(
                        self::DEFAULT_CONFIG['auto_create_channels'],
                        array_intersect_key($decoded['auto_create_channels'], self::DEFAULT_CONFIG['auto_create_channels'])
                    );
                    $config['auto_create_channels'] = [
                        'email' => !empty($config['auto_create_channels']['email']),
                        'whatsapp' => !empty($config['auto_create_channels']['whatsapp']),
                        'sms' => !empty($config['auto_create_channels']['sms']),
                    ];
                }
                if (isset($decoded['inbox_triage_enabled'])) {
                    $config['inbox_triage_enabled'] = (bool) $decoded['inbox_triage_enabled'];
                }
                if (isset($decoded['inbox_triage_auto_apply'])) {
                    $config['inbox_triage_auto_apply'] = (bool) $decoded['inbox_triage_auto_apply'];
                }
                if (isset($decoded['inbox_triage_channels']) && is_array($decoded['inbox_triage_channels'])) {
                    $triageChannels = array_merge(
                        self::DEFAULT_CONFIG['inbox_triage_channels'],
                        array_intersect_key($decoded['inbox_triage_channels'], self::DEFAULT_CONFIG['inbox_triage_channels'])
                    );
                    $config['inbox_triage_channels'] = [
                        'email' => !empty($triageChannels['email']),
                        'whatsapp' => !empty($triageChannels['whatsapp']),
                    ];
                }
                if (isset($decoded['inbox_triage_min_confidence'])) {
                    $config['inbox_triage_min_confidence'] = max(0.0, min(1.0, (float) $decoded['inbox_triage_min_confidence']));
                }
                if (isset($decoded['inbox_triage_task_due_hours'])) {
                    $config['inbox_triage_task_due_hours'] = max(1, min(168, (int) $decoded['inbox_triage_task_due_hours']));
                }
                if (isset($decoded['inbox_triage_dedupe_hours'])) {
                    $config['inbox_triage_dedupe_hours'] = max(1, min(168, (int) $decoded['inbox_triage_dedupe_hours']));
                }
                if (isset($decoded['inbox_triage_owner_fallback'])) {
                    $config['inbox_triage_owner_fallback'] = (string) $decoded['inbox_triage_owner_fallback'];
                }
                if (isset($decoded['inbox_triage_negative_phrase_blocklist'])) {
                    $config['inbox_triage_negative_phrase_blocklist'] = (string) $decoded['inbox_triage_negative_phrase_blocklist'];
                }
                if (isset($decoded['inbox_triage_opt_out_phrase_blocklist'])) {
                    $config['inbox_triage_opt_out_phrase_blocklist'] = (string) $decoded['inbox_triage_opt_out_phrase_blocklist'];
                }
                if (isset($decoded['outcome_layer_enabled'])) {
                    $config['outcome_layer_enabled'] = (bool) $decoded['outcome_layer_enabled'];
                }
                if (isset($decoded['outcome_layer_checklist_enabled'])) {
                    $config['outcome_layer_checklist_enabled'] = (bool) $decoded['outcome_layer_checklist_enabled'];
                }
                if (isset($decoded['outcome_layer_daily_focus_enabled'])) {
                    $config['outcome_layer_daily_focus_enabled'] = (bool) $decoded['outcome_layer_daily_focus_enabled'];
                }
                if (isset($decoded['outcome_layer_metrics_enabled'])) {
                    $config['outcome_layer_metrics_enabled'] = (bool) $decoded['outcome_layer_metrics_enabled'];
                }
                if (isset($decoded['outcome_layer_rollout_percent'])) {
                    $config['outcome_layer_rollout_percent'] = max(0, min(100, (int) $decoded['outcome_layer_rollout_percent']));
                }
            }
        }

        return $config;
    }

    /**
     * Save config (updates singleton row)
     */
    public function save(array $data, ?int $workspaceId = null): void
    {
        $workspaceId = $this->resolveWorkspaceId($workspaceId);
        $enabled = isset($data['enabled']) ? (bool) $data['enabled'] : false;
        $mode = in_array($data['mode'] ?? '', ['suggest_only', 'auto_safe', 'full_auto'])
            ? $data['mode'] : 'suggest_only';
        $minConfidence = (float) ($data['min_confidence'] ?? 0.85);
        $lookbackDays = max(1, min(90, (int) ($data['lookback_days'] ?? 14)));
        $cooldownHours = max(0, min(168, (int) ($data['cooldown_hours'] ?? 24)));
        $requireApprovalTerminal = isset($data['require_approval_terminal'])
            ? (bool) $data['require_approval_terminal'] : true;
        $minTerminalConfidence = (float) ($data['min_terminal_confidence'] ?? 0.92);
        $inactivityDaysForLoss = max(1, min(90, (int) ($data['inactivity_days_for_loss'] ?? 14)));
        $allowMultiStageJump = isset($data['allow_multi_stage_jump'])
            ? (bool) $data['allow_multi_stage_jump'] : false;
        $dryRun = isset($data['dry_run']) ? (bool) $data['dry_run'] : false;
        $reopenLostOnReengagement = isset($data['reopen_lost_on_reengagement'])
            ? (bool) $data['reopen_lost_on_reengagement'] : false;
        $autoCreateFromInbound = isset($data['auto_create_from_inbound']) ? (bool) $data['auto_create_from_inbound'] : false;
        $autoCreateRequireNonNegative = isset($data['auto_create_require_non_negative']) ? (bool) $data['auto_create_require_non_negative'] : true;
        $autoCreateRequireMeaningful = isset($data['auto_create_require_meaningful_reply']) ? (bool) $data['auto_create_require_meaningful_reply'] : true;
        $autoCreateMinChars = max(1, min(1000, (int) ($data['auto_create_min_message_chars'] ?? 20)));
        $autoCreateDedupeHours = max(1, min(168, (int) ($data['auto_create_dedupe_hours'] ?? 24)));
        $autoCreateChannels = $data['auto_create_channels'] ?? self::DEFAULT_CONFIG['auto_create_channels'];
        $autoCreateChannels = is_array($autoCreateChannels) ? $autoCreateChannels : self::DEFAULT_CONFIG['auto_create_channels'];
        $autoCreateChannels = [
            'email' => !empty($autoCreateChannels['email']),
            'whatsapp' => !empty($autoCreateChannels['whatsapp']),
            'sms' => !empty($autoCreateChannels['sms']),
        ];
        $inboxTriageEnabled = isset($data['inbox_triage_enabled']) ? (bool) $data['inbox_triage_enabled'] : false;
        $inboxTriageAutoApply = isset($data['inbox_triage_auto_apply']) ? (bool) $data['inbox_triage_auto_apply'] : true;
        $inboxTriageChannels = $data['inbox_triage_channels'] ?? self::DEFAULT_CONFIG['inbox_triage_channels'];
        $inboxTriageChannels = is_array($inboxTriageChannels) ? $inboxTriageChannels : self::DEFAULT_CONFIG['inbox_triage_channels'];
        $inboxTriageChannels = [
            'email' => !empty($inboxTriageChannels['email']),
            'whatsapp' => !empty($inboxTriageChannels['whatsapp']),
        ];
        $inboxTriageMinConfidence = max(0.0, min(1.0, (float) ($data['inbox_triage_min_confidence'] ?? 0.80)));
        $inboxTriageTaskDueHours = max(1, min(168, (int) ($data['inbox_triage_task_due_hours'] ?? 24)));
        $inboxTriageDedupeHours = max(1, min(168, (int) ($data['inbox_triage_dedupe_hours'] ?? 24)));
        $inboxTriageOwnerFallback = (string) ($data['inbox_triage_owner_fallback'] ?? 'round_robin');
        $inboxTriageNegativeBlocklist = (string) ($data['inbox_triage_negative_phrase_blocklist'] ?? self::DEFAULT_CONFIG['inbox_triage_negative_phrase_blocklist']);
        $inboxTriageOptOutBlocklist = (string) ($data['inbox_triage_opt_out_phrase_blocklist'] ?? self::DEFAULT_CONFIG['inbox_triage_opt_out_phrase_blocklist']);
        $outcomeLayerEnabled = isset($data['outcome_layer_enabled']) ? (bool) $data['outcome_layer_enabled'] : false;
        $outcomeLayerChecklistEnabled = isset($data['outcome_layer_checklist_enabled']) ? (bool) $data['outcome_layer_checklist_enabled'] : true;
        $outcomeLayerDailyFocusEnabled = isset($data['outcome_layer_daily_focus_enabled']) ? (bool) $data['outcome_layer_daily_focus_enabled'] : true;
        $outcomeLayerMetricsEnabled = isset($data['outcome_layer_metrics_enabled']) ? (bool) $data['outcome_layer_metrics_enabled'] : true;
        $outcomeLayerRolloutPercent = max(0, min(100, (int) ($data['outcome_layer_rollout_percent'] ?? 0)));

        $transitions = $data['transitions'] ?? [];
        $configJson = json_encode([
            'transitions' => $transitions,
            'auto_create_from_inbound' => $autoCreateFromInbound,
            'auto_create_require_non_negative' => $autoCreateRequireNonNegative,
            'auto_create_require_meaningful_reply' => $autoCreateRequireMeaningful,
            'auto_create_min_message_chars' => $autoCreateMinChars,
            'auto_create_dedupe_hours' => $autoCreateDedupeHours,
            'auto_create_channels' => $autoCreateChannels,
            'inbox_triage_enabled' => $inboxTriageEnabled,
            'inbox_triage_auto_apply' => $inboxTriageAutoApply,
            'inbox_triage_channels' => $inboxTriageChannels,
            'inbox_triage_min_confidence' => $inboxTriageMinConfidence,
            'inbox_triage_task_due_hours' => $inboxTriageTaskDueHours,
            'inbox_triage_dedupe_hours' => $inboxTriageDedupeHours,
            'inbox_triage_owner_fallback' => $inboxTriageOwnerFallback,
            'inbox_triage_negative_phrase_blocklist' => $inboxTriageNegativeBlocklist,
            'inbox_triage_opt_out_phrase_blocklist' => $inboxTriageOptOutBlocklist,
            'outcome_layer_enabled' => $outcomeLayerEnabled,
            'outcome_layer_checklist_enabled' => $outcomeLayerChecklistEnabled,
            'outcome_layer_daily_focus_enabled' => $outcomeLayerDailyFocusEnabled,
            'outcome_layer_metrics_enabled' => $outcomeLayerMetricsEnabled,
            'outcome_layer_rollout_percent' => $outcomeLayerRolloutPercent,
            'schema_version' => 1,
        ]);

        if ($workspaceId > 0 && Database::tableExists('workspace_deal_automation_config')) {
            Database::execute(
                "INSERT INTO workspace_deal_automation_config (
                    workspace_id, enabled, mode, min_confidence, lookback_days, cooldown_hours,
                    require_approval_terminal, min_terminal_confidence, inactivity_days_for_loss,
                    allow_multi_stage_jump, dry_run, reopen_lost_on_reengagement, config_json, schema_version
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1
                )
                ON DUPLICATE KEY UPDATE
                    enabled = VALUES(enabled),
                    mode = VALUES(mode),
                    min_confidence = VALUES(min_confidence),
                    lookback_days = VALUES(lookback_days),
                    cooldown_hours = VALUES(cooldown_hours),
                    require_approval_terminal = VALUES(require_approval_terminal),
                    min_terminal_confidence = VALUES(min_terminal_confidence),
                    inactivity_days_for_loss = VALUES(inactivity_days_for_loss),
                    allow_multi_stage_jump = VALUES(allow_multi_stage_jump),
                    dry_run = VALUES(dry_run),
                    reopen_lost_on_reengagement = VALUES(reopen_lost_on_reengagement),
                    config_json = VALUES(config_json),
                    schema_version = 1,
                    updated_at = CURRENT_TIMESTAMP",
                [
                    $workspaceId,
                    $enabled ? 1 : 0,
                    $mode,
                    $minConfidence,
                    $lookbackDays,
                    $cooldownHours,
                    $requireApprovalTerminal ? 1 : 0,
                    $minTerminalConfidence,
                    $inactivityDaysForLoss,
                    $allowMultiStageJump ? 1 : 0,
                    $dryRun ? 1 : 0,
                    $reopenLostOnReengagement ? 1 : 0,
                    $configJson,
                ]
            );
            return;
        }

        $exists = Database::queryOne("SELECT 1 FROM deal_automation_config WHERE id = 1");
        if ($exists) {
            Database::execute(
                "UPDATE deal_automation_config SET 
                    enabled = ?, mode = ?, min_confidence = ?, lookback_days = ?,
                    cooldown_hours = ?, require_approval_terminal = ?, min_terminal_confidence = ?,
                    inactivity_days_for_loss = ?, allow_multi_stage_jump = ?, dry_run = ?,
                    reopen_lost_on_reengagement = ?, config_json = ?, schema_version = 1
                WHERE id = 1",
                [
                    $enabled, $mode, $minConfidence, $lookbackDays,
                    $cooldownHours, $requireApprovalTerminal ? 1 : 0, $minTerminalConfidence,
                    $inactivityDaysForLoss, $allowMultiStageJump ? 1 : 0, $dryRun ? 1 : 0,
                    $reopenLostOnReengagement ? 1 : 0, $configJson,
                ]
            );
        } else {
            Database::execute(
                "INSERT INTO deal_automation_config (id, enabled, mode, min_confidence, lookback_days,
                    cooldown_hours, require_approval_terminal, min_terminal_confidence,
                    inactivity_days_for_loss, allow_multi_stage_jump, dry_run, reopen_lost_on_reengagement, config_json)
                VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $enabled ? 1 : 0, $mode, $minConfidence, $lookbackDays,
                    $cooldownHours, $requireApprovalTerminal ? 1 : 0, $minTerminalConfidence,
                    $inactivityDaysForLoss, $allowMultiStageJump ? 1 : 0, $dryRun ? 1 : 0,
                    $reopenLostOnReengagement ? 1 : 0, $configJson,
                ]
            );
        }
    }

    /**
     * Check if automation is enabled and should run
     */
    public function isEnabled(): bool
    {
        $config = $this->get();
        return !empty($config['enabled']);
    }

    /**
     * Get allowed deal stages (for validation)
     */
    public static function getAllowedStages(): array
    {
        return ['prospecting', 'qualification', 'proposal', 'negotiation', 'closed_won', 'closed_lost'];
    }

    private function resolveWorkspaceId(?int $workspaceId): int
    {
        if ($workspaceId !== null && $workspaceId > 0) {
            return $workspaceId;
        }

        try {
            return (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
