<?php
/**
 * AI Auto-responder Configuration
 * Loads and saves singleton multi-channel policy/settings.
 */

namespace CRM\Modules;

use CRM\Database;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceAIAutoResponderQuietHoursService;

class AIAutoResponderConfig
{
    private const LENGTH_BANDS = ['short', 'balanced', 'detailed'];
    private const FULLNESS_LEVELS = ['concise', 'balanced', 'fuller'];

    private const DEFAULT_CONFIG = [
        'enabled' => false,
        'mode' => 'draft_only',
        'default_confidence_threshold' => 0.85,
        'channels' => [
            'email' => [
                'enabled' => true,
                'confidence_threshold' => 0.88,
                'max_chars' => 4000,
                'draft_length_band' => 'balanced',
                'draft_fullness' => 'balanced',
                'draft_include_clear_cta' => true,
            ],
            'whatsapp' => [
                'enabled' => true,
                'confidence_threshold' => 0.90,
                'max_chars' => 700,
                'draft_length_band' => 'short',
                'draft_fullness' => 'concise',
                'draft_include_clear_cta' => true,
            ],
            'sms' => [
                'enabled' => true,
                'confidence_threshold' => 0.92,
                'max_chars' => 320,
                'draft_length_band' => 'short',
                'draft_fullness' => 'concise',
                'draft_include_clear_cta' => true,
            ],
        ],
        'quiet_hours' => [
            'enabled' => false,
            'start' => '20:00',
            'end' => '08:00',
            'timezone' => 'UTC',
        ],
        'safety' => [
            'escalation_keywords' => ['refund', 'lawyer', 'legal', 'complaint', 'angry'],
            'opt_out_keywords' => ['stop', 'unsubscribe', 'cancel'],
            'max_auto_replies_per_contact_per_day' => 5,
            'forbid_hallucinations' => true,
            'require_human_for_sensitive_intents' => true,
        ],
    ];

    public function get(?int $workspaceId = null): array
    {
        $workspaceId = $this->resolveWorkspaceId($workspaceId);
        $row = null;
        if ($workspaceId > 0 && Database::tableExists('workspace_ai_autoresponder_config')) {
            $row = Database::queryOne(
                "SELECT * FROM workspace_ai_autoresponder_config WHERE workspace_id = ? LIMIT 1",
                [$workspaceId]
            );
        }
        if (!$row) {
            $row = Database::queryOne("SELECT * FROM ai_autoresponder_config WHERE id = 1");
        }
        if (!$row) {
            return $this->withWorkspaceQuietHours(self::DEFAULT_CONFIG, $workspaceId > 0 ? $workspaceId : null);
        }

        $json = json_decode((string) ($row['config_json'] ?? '{}'), true);
        if (!is_array($json)) {
            $json = [];
        }

        $config = array_replace_recursive(self::DEFAULT_CONFIG, $json);
        $config['enabled'] = (bool) ($row['enabled'] ?? false);
        $config['mode'] = $this->normalizeMode((string) ($row['mode'] ?? 'draft_only'));
        $config['default_confidence_threshold'] = $this->clampConfidence((float) ($row['default_confidence_threshold'] ?? 0.85));

        return $this->withWorkspaceQuietHours($config, $workspaceId > 0 ? $workspaceId : null);
    }

    public function save(array $data, ?int $workspaceId = null): void
    {
        $workspaceId = $this->resolveWorkspaceId($workspaceId);
        $current = $this->get($workspaceId > 0 ? $workspaceId : null);
        $enabled = isset($data['enabled']) ? (bool) $data['enabled'] : (bool) $current['enabled'];
        $mode = $this->normalizeMode((string) ($data['mode'] ?? $current['mode']));
        $defaultThreshold = $this->clampConfidence((float) ($data['default_confidence_threshold'] ?? $current['default_confidence_threshold']));

        $channels = $current['channels'];
        foreach (['email', 'whatsapp', 'sms'] as $channel) {
            if (!isset($channels[$channel])) {
                $channels[$channel] = self::DEFAULT_CONFIG['channels'][$channel];
            }
            $input = $data['channels'][$channel] ?? [];
            if (!is_array($input)) {
                continue;
            }
            $channels[$channel]['enabled'] = isset($input['enabled']) ? (bool) $input['enabled'] : (bool) ($channels[$channel]['enabled'] ?? true);
            $channels[$channel]['confidence_threshold'] = $this->clampConfidence((float) ($input['confidence_threshold'] ?? $channels[$channel]['confidence_threshold']));
            $channels[$channel]['max_chars'] = max(60, min(10000, (int) ($input['max_chars'] ?? $channels[$channel]['max_chars'])));
            $channels[$channel]['draft_length_band'] = $this->normalizeChoice(
                (string) ($input['draft_length_band'] ?? $channels[$channel]['draft_length_band'] ?? 'balanced'),
                self::LENGTH_BANDS,
                (string) (self::DEFAULT_CONFIG['channels'][$channel]['draft_length_band'] ?? 'balanced')
            );
            $channels[$channel]['draft_fullness'] = $this->normalizeChoice(
                (string) ($input['draft_fullness'] ?? $channels[$channel]['draft_fullness'] ?? 'balanced'),
                self::FULLNESS_LEVELS,
                (string) (self::DEFAULT_CONFIG['channels'][$channel]['draft_fullness'] ?? 'balanced')
            );
            $channels[$channel]['draft_include_clear_cta'] = isset($input['draft_include_clear_cta'])
                ? (bool) $input['draft_include_clear_cta']
                : (bool) ($channels[$channel]['draft_include_clear_cta'] ?? (self::DEFAULT_CONFIG['channels'][$channel]['draft_include_clear_cta'] ?? true));
        }

        $quietHours = $current['quiet_hours'];
        $quietInput = $data['quiet_hours'] ?? [];
        if (is_array($quietInput)) {
            $quietHours['enabled'] = isset($quietInput['enabled']) ? (bool) $quietInput['enabled'] : (bool) ($quietHours['enabled'] ?? false);
            $quietHours['start'] = $this->normalizeTime((string) ($quietInput['start'] ?? $quietHours['start']));
            $quietHours['end'] = $this->normalizeTime((string) ($quietInput['end'] ?? $quietHours['end']));
            $quietHours['timezone'] = trim((string) ($quietInput['timezone'] ?? $quietHours['timezone'] ?? 'UTC')) ?: 'UTC';
        }

        $safety = $current['safety'];
        $safetyInput = $data['safety'] ?? [];
        if (is_array($safetyInput)) {
            $safety['escalation_keywords'] = $this->normalizeKeywordList($safetyInput['escalation_keywords'] ?? $safety['escalation_keywords']);
            $safety['opt_out_keywords'] = $this->normalizeKeywordList($safetyInput['opt_out_keywords'] ?? $safety['opt_out_keywords']);
            $safety['max_auto_replies_per_contact_per_day'] = max(1, min(100, (int) ($safetyInput['max_auto_replies_per_contact_per_day'] ?? $safety['max_auto_replies_per_contact_per_day'])));
            $safety['forbid_hallucinations'] = isset($safetyInput['forbid_hallucinations']) ? (bool) $safetyInput['forbid_hallucinations'] : (bool) ($safety['forbid_hallucinations'] ?? true);
            $safety['require_human_for_sensitive_intents'] = isset($safetyInput['require_human_for_sensitive_intents'])
                ? (bool) $safetyInput['require_human_for_sensitive_intents']
                : (bool) ($safety['require_human_for_sensitive_intents'] ?? true);
        }

        $jsonConfig = [
            'channels' => $channels,
            'quiet_hours' => $quietHours,
            'safety' => $safety,
            'updated_by' => 'settings_tab',
        ];

        if ($workspaceId > 0 && Database::tableExists('workspace_ai_autoresponder_config')) {
            Database::execute(
                "INSERT INTO workspace_ai_autoresponder_config
                    (workspace_id, enabled, mode, default_confidence_threshold, config_json)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    enabled = VALUES(enabled),
                    mode = VALUES(mode),
                    default_confidence_threshold = VALUES(default_confidence_threshold),
                    config_json = VALUES(config_json),
                    updated_at = CURRENT_TIMESTAMP",
                [$workspaceId, $enabled ? 1 : 0, $mode, $defaultThreshold, json_encode($jsonConfig)]
            );
            return;
        }

        $exists = Database::queryOne("SELECT 1 FROM ai_autoresponder_config WHERE id = 1");
        if ($exists) {
            Database::execute(
                "UPDATE ai_autoresponder_config
                 SET enabled = ?, mode = ?, default_confidence_threshold = ?, config_json = ?
                 WHERE id = 1",
                [$enabled ? 1 : 0, $mode, $defaultThreshold, json_encode($jsonConfig)]
            );
            return;
        }

        Database::execute(
            "INSERT INTO ai_autoresponder_config (id, enabled, mode, default_confidence_threshold, config_json)
             VALUES (1, ?, ?, ?, ?)",
            [$enabled ? 1 : 0, $mode, $defaultThreshold, json_encode($jsonConfig)]
        );
    }

    private function normalizeMode(string $mode): string
    {
        return in_array($mode, ['off', 'draft_only', 'hybrid', 'full_auto'], true) ? $mode : 'draft_only';
    }

    private function clampConfidence(float $value): float
    {
        return max(0.0, min(1.0, round($value, 3)));
    }

    private function normalizeTime(string $value): string
    {
        if (preg_match('/^\d{2}:\d{2}$/', $value)) {
            return $value;
        }
        return '00:00';
    }

    private function withWorkspaceQuietHours(array $config, ?int $workspaceId): array
    {
        if ($workspaceId === null || $workspaceId <= 0) {
            return $config;
        }

        try {
            $config['quiet_hours'] = (new WorkspaceAIAutoResponderQuietHoursService())->get($workspaceId);
        } catch (\Throwable $e) {
            // Keep the global quiet-hours fallback if the workspace table is not ready.
        }

        return $config;
    }

    private function normalizeChoice(string $value, array $allowed, string $default): string
    {
        $normalized = strtolower(trim($value));
        return in_array($normalized, $allowed, true) ? $normalized : $default;
    }

    /**
     * @param mixed $raw
     * @return array<int, string>
     */
    private function normalizeKeywordList(mixed $raw): array
    {
        if (is_string($raw)) {
            $parts = preg_split('/[\r\n,]+/', strtolower($raw), -1, PREG_SPLIT_NO_EMPTY);
            return array_values(array_unique(array_map('trim', $parts ?: [])));
        }
        if (is_array($raw)) {
            $normalized = [];
            foreach ($raw as $item) {
                $text = trim(strtolower((string) $item));
                if ($text !== '') {
                    $normalized[] = $text;
                }
            }
            return array_values(array_unique($normalized));
        }
        return [];
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
