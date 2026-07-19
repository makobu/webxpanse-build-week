<?php

namespace CRM\Modules;

use CRM\Database;
use CRM\Services\WorkspaceScopeService;

class MeetingBotConfig
{
    private const DEFAULTS = [
        'id' => 0,
        'workspace_id' => 1,
        'enabled' => false,
        'provider' => 'zoom',
        'bot_display_name' => '',
        'join_policy' => 'manual_invite_only',
        'recording_mode' => 'provider_native',
        'transcript_required' => true,
        'auto_apply_mode' => 'auto_safe',
        'consent_notice' => 'This meeting may be joined and transcribed by your workspace meeting assistant.',
        'zoom_account_id' => '',
        'zoom_client_id' => '',
        'zoom_client_secret' => '',
        'google_workspace_client_id' => '',
        'google_workspace_client_secret' => '',
        'google_workspace_project_id' => '',
        'google_calendar_integration_id' => '',
        'google_transcript_mode' => 'manual_ingest',
        'webhook_secret' => '',
        'scheduling_secret' => '',
        'schema_version' => 1,
    ];

    private const SECRET_FIELDS = [
        'zoom_client_secret',
        'google_workspace_client_secret',
        'webhook_secret',
        'scheduling_secret',
    ];

    public function get(?int $workspaceId = null, bool $withSecrets = true): array
    {
        $resolvedWorkspaceId = $this->resolveWorkspaceId($workspaceId);
        $row = $this->isWorkspaceScoped()
            ? Database::queryOne("SELECT * FROM meeting_bot_config WHERE workspace_id = ? LIMIT 1", [$resolvedWorkspaceId])
            : Database::queryOne("SELECT * FROM meeting_bot_config WHERE id = 1");

        if (!$row) {
            return $this->maskSecrets(array_merge(self::DEFAULTS, ['workspace_id' => $resolvedWorkspaceId]), $withSecrets);
        }

        $config = array_merge(self::DEFAULTS, [
            'id' => (int) ($row['id'] ?? 0),
            'workspace_id' => $this->isWorkspaceScoped() ? (int) ($row['workspace_id'] ?? $resolvedWorkspaceId) : $resolvedWorkspaceId,
            'enabled' => !empty($row['enabled']),
            'provider' => in_array((string) ($row['provider'] ?? 'zoom'), ['zoom', 'google_meet'], true) ? (string) $row['provider'] : 'zoom',
            'bot_display_name' => trim((string) ($row['bot_display_name'] ?? '')),
            'join_policy' => in_array((string) ($row['join_policy'] ?? 'manual_invite_only'), ['manual_invite_only', 'calendar_suggested', 'auto_join_eligible'], true)
                ? (string) $row['join_policy']
                : 'manual_invite_only',
            'recording_mode' => in_array((string) ($row['recording_mode'] ?? 'provider_native'), ['provider_native', 'bot_requested'], true)
                ? (string) $row['recording_mode']
                : 'provider_native',
            'transcript_required' => !empty($row['transcript_required']),
            'auto_apply_mode' => in_array((string) ($row['auto_apply_mode'] ?? 'auto_safe'), ['suggest_only', 'auto_safe', 'full_auto'], true)
                ? (string) $row['auto_apply_mode']
                : 'auto_safe',
            'consent_notice' => trim((string) ($row['consent_notice'] ?? self::DEFAULTS['consent_notice'])),
            'zoom_account_id' => trim((string) ($row['zoom_account_id'] ?? '')),
            'zoom_client_id' => trim((string) ($row['zoom_client_id'] ?? '')),
            'zoom_client_secret' => trim((string) ($row['zoom_client_secret'] ?? '')),
            'webhook_secret' => trim((string) ($row['webhook_secret'] ?? '')),
            'scheduling_secret' => trim((string) ($row['scheduling_secret'] ?? '')),
        ]);

        $configJson = $row['config_json'] ?? null;
        if (is_string($configJson) && trim($configJson) !== '') {
            $decoded = json_decode($configJson, true);
            if (is_array($decoded)) {
                if (isset($decoded['schema_version'])) {
                    $config['schema_version'] = max(1, (int) $decoded['schema_version']);
                }
                $config['google_workspace_client_id'] = trim((string) ($decoded['google_workspace_client_id'] ?? ''));
                $config['google_workspace_client_secret'] = trim((string) ($decoded['google_workspace_client_secret'] ?? ''));
                $config['google_workspace_project_id'] = trim((string) ($decoded['google_workspace_project_id'] ?? ''));
                $config['google_calendar_integration_id'] = trim((string) ($decoded['google_calendar_integration_id'] ?? ''));
                $decodedTranscriptMode = (string) ($decoded['google_transcript_mode'] ?? 'manual_ingest');
                $config['google_transcript_mode'] = in_array($decodedTranscriptMode, ['manual_ingest', 'workspace_export'], true)
                    ? $decodedTranscriptMode
                    : 'manual_ingest';
            }
        }

        return $this->maskSecrets($config, $withSecrets);
    }

    public function save(array $data, ?int $updatedBy = null, ?int $workspaceId = null): void
    {
        $resolvedWorkspaceId = $this->resolveWorkspaceId($workspaceId);
        $current = $this->get($resolvedWorkspaceId, true);
        $merged = array_merge($current, $data);

        $merged['enabled'] = !empty($merged['enabled']);
        $merged['provider'] = in_array((string) ($merged['provider'] ?? 'zoom'), ['zoom', 'google_meet'], true)
            ? (string) $merged['provider']
            : 'zoom';
        $merged['bot_display_name'] = substr(trim((string) ($merged['bot_display_name'] ?? '')), 0, 255);
        $merged['join_policy'] = in_array((string) ($merged['join_policy'] ?? 'manual_invite_only'), ['manual_invite_only', 'calendar_suggested', 'auto_join_eligible'], true)
            ? (string) $merged['join_policy']
            : 'manual_invite_only';
        $merged['recording_mode'] = in_array((string) ($merged['recording_mode'] ?? 'provider_native'), ['provider_native', 'bot_requested'], true)
            ? (string) $merged['recording_mode']
            : 'provider_native';
        $merged['transcript_required'] = !empty($merged['transcript_required']);
        $merged['auto_apply_mode'] = in_array((string) ($merged['auto_apply_mode'] ?? 'auto_safe'), ['suggest_only', 'auto_safe', 'full_auto'], true)
            ? (string) $merged['auto_apply_mode']
            : 'auto_safe';
        $merged['consent_notice'] = trim((string) ($merged['consent_notice'] ?? self::DEFAULTS['consent_notice']));
        $merged['zoom_account_id'] = trim((string) ($merged['zoom_account_id'] ?? ''));
        $merged['zoom_client_id'] = trim((string) ($merged['zoom_client_id'] ?? ''));
        $merged['zoom_client_secret'] = $this->preserveSecret($data, $current, 'zoom_client_secret');
        $merged['google_workspace_client_id'] = trim((string) ($merged['google_workspace_client_id'] ?? ''));
        $merged['google_workspace_client_secret'] = $this->preserveSecret($data, $current, 'google_workspace_client_secret');
        $merged['google_workspace_project_id'] = trim((string) ($merged['google_workspace_project_id'] ?? ''));
        $merged['google_calendar_integration_id'] = trim((string) ($merged['google_calendar_integration_id'] ?? ''));
        $merged['google_transcript_mode'] = in_array((string) ($merged['google_transcript_mode'] ?? 'manual_ingest'), ['manual_ingest', 'workspace_export'], true)
            ? (string) $merged['google_transcript_mode']
            : 'manual_ingest';
        $merged['webhook_secret'] = $this->preserveOrRegenerateSecret($data, $current, 'webhook_secret');
        $merged['scheduling_secret'] = $this->preserveOrRegenerateSecret($data, $current, 'scheduling_secret');
        if ($merged['webhook_secret'] === '') {
            $merged['webhook_secret'] = (string) bin2hex(random_bytes(24));
        }
        if ($merged['scheduling_secret'] === '') {
            $merged['scheduling_secret'] = (string) bin2hex(random_bytes(24));
        }

        $configJson = json_encode([
            'google_workspace_client_id' => $merged['google_workspace_client_id'],
            'google_workspace_client_secret' => $merged['google_workspace_client_secret'],
            'google_workspace_project_id' => $merged['google_workspace_project_id'],
            'google_calendar_integration_id' => $merged['google_calendar_integration_id'],
            'google_transcript_mode' => $merged['google_transcript_mode'],
            'schema_version' => 1,
        ]);

        $params = [
            $merged['enabled'] ? 1 : 0,
            $merged['provider'],
            $merged['bot_display_name'] !== '' ? $merged['bot_display_name'] : null,
            $merged['join_policy'],
            $merged['recording_mode'],
            $merged['transcript_required'] ? 1 : 0,
            $merged['auto_apply_mode'],
            $merged['consent_notice'] !== '' ? $merged['consent_notice'] : null,
            $merged['zoom_account_id'] !== '' ? $merged['zoom_account_id'] : null,
            $merged['zoom_client_id'] !== '' ? $merged['zoom_client_id'] : null,
            $merged['zoom_client_secret'] !== '' ? $merged['zoom_client_secret'] : null,
            $merged['webhook_secret'],
            $merged['scheduling_secret'],
            $configJson,
            $updatedBy,
        ];

        if ($this->isWorkspaceScoped()) {
            Database::execute(
                "INSERT INTO meeting_bot_config (
                    workspace_id, enabled, provider, bot_display_name, join_policy, recording_mode, transcript_required,
                    auto_apply_mode, consent_notice, zoom_account_id, zoom_client_id, zoom_client_secret,
                    webhook_secret, scheduling_secret, config_json, updated_by
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                )
                ON DUPLICATE KEY UPDATE
                    enabled = VALUES(enabled),
                    provider = VALUES(provider),
                    bot_display_name = VALUES(bot_display_name),
                    join_policy = VALUES(join_policy),
                    recording_mode = VALUES(recording_mode),
                    transcript_required = VALUES(transcript_required),
                    auto_apply_mode = VALUES(auto_apply_mode),
                    consent_notice = VALUES(consent_notice),
                    zoom_account_id = VALUES(zoom_account_id),
                    zoom_client_id = VALUES(zoom_client_id),
                    zoom_client_secret = VALUES(zoom_client_secret),
                    webhook_secret = VALUES(webhook_secret),
                    scheduling_secret = VALUES(scheduling_secret),
                    config_json = VALUES(config_json),
                    updated_by = VALUES(updated_by)",
                array_merge([$resolvedWorkspaceId], $params)
            );
            return;
        }

        $exists = Database::queryOne("SELECT id FROM meeting_bot_config WHERE id = 1");
        if ($exists) {
            $params[] = 1;
            Database::execute(
                "UPDATE meeting_bot_config SET
                    enabled = ?, provider = ?, bot_display_name = ?, join_policy = ?, recording_mode = ?,
                    transcript_required = ?, auto_apply_mode = ?, consent_notice = ?, zoom_account_id = ?,
                    zoom_client_id = ?, zoom_client_secret = ?, webhook_secret = ?, scheduling_secret = ?,
                    config_json = ?, updated_by = ?
                 WHERE id = ?",
                $params
            );
            return;
        }

        array_unshift($params, 1);
        Database::execute(
            "INSERT INTO meeting_bot_config (
                id, enabled, provider, bot_display_name, join_policy, recording_mode, transcript_required,
                auto_apply_mode, consent_notice, zoom_account_id, zoom_client_id, zoom_client_secret,
                webhook_secret, scheduling_secret, config_json, updated_by
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
            )",
            $params
        );
    }

    public function findByWebhookSecret(string $secret): ?array
    {
        return $this->findBySecret('webhook_secret', $secret);
    }

    public function findBySchedulingSecret(string $secret): ?array
    {
        return $this->findBySecret('scheduling_secret', $secret);
    }

    private function findBySecret(string $field, string $secret): ?array
    {
        $secret = trim($secret);
        if ($secret === '' || !in_array($field, ['webhook_secret', 'scheduling_secret'], true)) {
            return null;
        }

        if (!$this->isWorkspaceScoped()) {
            $config = $this->get(null, true);
            return !empty($config[$field]) && hash_equals((string) $config[$field], $secret) ? $config : null;
        }

        $rows = Database::query(
            "SELECT workspace_id, {$field} AS secret_value
             FROM meeting_bot_config
             WHERE {$field} IS NOT NULL
               AND {$field} <> ''"
        );
        foreach ($rows as $row) {
            if (hash_equals((string) ($row['secret_value'] ?? ''), $secret)) {
                return $this->get((int) ($row['workspace_id'] ?? 0), true);
            }
        }

        return null;
    }

    private function preserveSecret(array $data, array $current, string $field): string
    {
        if (!array_key_exists($field, $data)) {
            return trim((string) ($current[$field] ?? ''));
        }

        $value = trim((string) ($data[$field] ?? ''));
        if ($value === '' || $value === 'saved') {
            return trim((string) ($current[$field] ?? ''));
        }

        return $value;
    }

    private function preserveOrRegenerateSecret(array $data, array $current, string $field): string
    {
        if (!array_key_exists($field, $data)) {
            return trim((string) ($current[$field] ?? ''));
        }

        $value = trim((string) ($data[$field] ?? ''));
        if ($value === 'saved') {
            return trim((string) ($current[$field] ?? ''));
        }
        if ($value === '' || $value === '__regenerate__') {
            return '';
        }

        return $value;
    }

    private function maskSecrets(array $config, bool $withSecrets): array
    {
        if ($withSecrets) {
            return $config;
        }

        foreach (self::SECRET_FIELDS as $field) {
            if (array_key_exists($field, $config) && trim((string) $config[$field]) !== '') {
                $config[$field] = 'saved';
            }
        }

        return $config;
    }

    private function resolveWorkspaceId(?int $workspaceId): int
    {
        if ($workspaceId !== null && $workspaceId > 0) {
            return (new WorkspaceScopeService())->requireActiveWorkspaceId($workspaceId);
        }

        try {
            return (new WorkspaceScopeService())->requireActiveWorkspaceId();
        } catch (\Throwable $e) {
            return 1;
        }
    }

    private function isWorkspaceScoped(): bool
    {
        return Database::columnExists('meeting_bot_config', 'workspace_id');
    }
}
