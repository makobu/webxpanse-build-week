<?php

namespace CRM\Modules;

use CRM\Database;
use CRM\Services\WorkspaceScopeService;

class MeetingNoteTakerConfig
{
    private const DEFAULTS = [
        'id' => 0,
        'workspace_id' => 1,
        'enabled' => false,
        'auto_apply_mode' => 'full_auto',
        'contact_updates_additive_only' => true,
        'task_auto_create_enabled' => true,
        'deal_stage_auto_move_enabled' => true,
        'deal_stage_min_confidence' => 0.90,
        'contact_update_min_confidence' => 0.75,
        'ingest_secret' => '',
        'allowed_contact_fields' => ['job_title', 'location', 'company_website', 'linkedin_url', 'twitter_url', 'timezone'],
        'max_context_entries' => 10,
        'schema_version' => 1,
    ];

    private const SECRET_FIELDS = ['ingest_secret'];

    public function get(?int $workspaceId = null, bool $withSecrets = true): array
    {
        $resolvedWorkspaceId = $this->resolveWorkspaceId($workspaceId);
        $row = $this->isWorkspaceScoped()
            ? Database::queryOne("SELECT * FROM meeting_note_taker_config WHERE workspace_id = ? LIMIT 1", [$resolvedWorkspaceId])
            : Database::queryOne("SELECT * FROM meeting_note_taker_config WHERE id = 1");

        if (!$row) {
            return $this->maskSecrets(array_merge(self::DEFAULTS, ['workspace_id' => $resolvedWorkspaceId]), $withSecrets);
        }

        $config = array_merge(self::DEFAULTS, [
            'id' => (int) ($row['id'] ?? 0),
            'workspace_id' => $this->isWorkspaceScoped() ? (int) ($row['workspace_id'] ?? $resolvedWorkspaceId) : $resolvedWorkspaceId,
            'enabled' => !empty($row['enabled']),
            'auto_apply_mode' => in_array((string) ($row['auto_apply_mode'] ?? 'full_auto'), ['suggest_only', 'auto_safe', 'full_auto'], true)
                ? (string) $row['auto_apply_mode']
                : 'full_auto',
            'contact_updates_additive_only' => !empty($row['contact_updates_additive_only']),
            'task_auto_create_enabled' => !empty($row['task_auto_create_enabled']),
            'deal_stage_auto_move_enabled' => !empty($row['deal_stage_auto_move_enabled']),
            'deal_stage_min_confidence' => max(0.0, min(1.0, (float) ($row['deal_stage_min_confidence'] ?? 0.90))),
            'contact_update_min_confidence' => max(0.0, min(1.0, (float) ($row['contact_update_min_confidence'] ?? 0.75))),
            'ingest_secret' => (string) ($row['ingest_secret'] ?? ''),
        ]);

        $configJson = $row['config_json'] ?? null;
        if (is_string($configJson) && trim($configJson) !== '') {
            $decoded = json_decode($configJson, true);
            if (is_array($decoded)) {
                if (!empty($decoded['allowed_contact_fields']) && is_array($decoded['allowed_contact_fields'])) {
                    $config['allowed_contact_fields'] = array_values(array_filter(array_map('strval', $decoded['allowed_contact_fields'])));
                }
                if (isset($decoded['max_context_entries'])) {
                    $config['max_context_entries'] = max(1, min(50, (int) $decoded['max_context_entries']));
                }
                if (isset($decoded['schema_version'])) {
                    $config['schema_version'] = max(1, (int) $decoded['schema_version']);
                }
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
        $merged['contact_updates_additive_only'] = !empty($merged['contact_updates_additive_only']);
        $merged['task_auto_create_enabled'] = !empty($merged['task_auto_create_enabled']);
        $merged['deal_stage_auto_move_enabled'] = !empty($merged['deal_stage_auto_move_enabled']);
        $merged['auto_apply_mode'] = in_array((string) ($merged['auto_apply_mode'] ?? 'full_auto'), ['suggest_only', 'auto_safe', 'full_auto'], true)
            ? (string) $merged['auto_apply_mode']
            : 'full_auto';
        $merged['deal_stage_min_confidence'] = max(0.0, min(1.0, (float) ($merged['deal_stage_min_confidence'] ?? 0.90)));
        $merged['contact_update_min_confidence'] = max(0.0, min(1.0, (float) ($merged['contact_update_min_confidence'] ?? 0.75)));

        $allowedContactFields = array_values(array_filter(array_map('trim', (array) ($merged['allowed_contact_fields'] ?? self::DEFAULTS['allowed_contact_fields']))));
        if ($allowedContactFields === []) {
            $allowedContactFields = self::DEFAULTS['allowed_contact_fields'];
        }
        $maxContextEntries = max(1, min(50, (int) ($merged['max_context_entries'] ?? 10)));
        $ingestSecret = $this->preserveOrRegenerateSecret($data, $current, 'ingest_secret');
        if ($ingestSecret === '') {
            $ingestSecret = (string) bin2hex(random_bytes(24));
        }

        $configJson = json_encode([
            'allowed_contact_fields' => $allowedContactFields,
            'max_context_entries' => $maxContextEntries,
            'schema_version' => 1,
        ]);

        $params = [
            $merged['enabled'] ? 1 : 0,
            $merged['auto_apply_mode'],
            $merged['contact_updates_additive_only'] ? 1 : 0,
            $merged['task_auto_create_enabled'] ? 1 : 0,
            $merged['deal_stage_auto_move_enabled'] ? 1 : 0,
            $merged['deal_stage_min_confidence'],
            $merged['contact_update_min_confidence'],
            $ingestSecret,
            $configJson,
            $updatedBy,
        ];

        if ($this->isWorkspaceScoped()) {
            Database::execute(
                "INSERT INTO meeting_note_taker_config (
                    workspace_id, enabled, auto_apply_mode, contact_updates_additive_only, task_auto_create_enabled,
                    deal_stage_auto_move_enabled, deal_stage_min_confidence, contact_update_min_confidence,
                    ingest_secret, config_json, updated_by
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                )
                ON DUPLICATE KEY UPDATE
                    enabled = VALUES(enabled),
                    auto_apply_mode = VALUES(auto_apply_mode),
                    contact_updates_additive_only = VALUES(contact_updates_additive_only),
                    task_auto_create_enabled = VALUES(task_auto_create_enabled),
                    deal_stage_auto_move_enabled = VALUES(deal_stage_auto_move_enabled),
                    deal_stage_min_confidence = VALUES(deal_stage_min_confidence),
                    contact_update_min_confidence = VALUES(contact_update_min_confidence),
                    ingest_secret = VALUES(ingest_secret),
                    config_json = VALUES(config_json),
                    updated_by = VALUES(updated_by)",
                array_merge([$resolvedWorkspaceId], $params)
            );
            return;
        }

        $exists = Database::queryOne("SELECT id FROM meeting_note_taker_config WHERE id = 1");
        if ($exists) {
            $params[] = 1;
            Database::execute(
                "UPDATE meeting_note_taker_config SET
                    enabled = ?, auto_apply_mode = ?, contact_updates_additive_only = ?, task_auto_create_enabled = ?,
                    deal_stage_auto_move_enabled = ?, deal_stage_min_confidence = ?, contact_update_min_confidence = ?,
                    ingest_secret = ?, config_json = ?, updated_by = ?
                 WHERE id = ?",
                $params
            );
            return;
        }

        array_unshift($params, 1);
        Database::execute(
            "INSERT INTO meeting_note_taker_config (
                id, enabled, auto_apply_mode, contact_updates_additive_only, task_auto_create_enabled,
                deal_stage_auto_move_enabled, deal_stage_min_confidence, contact_update_min_confidence,
                ingest_secret, config_json, updated_by
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
            )",
            $params
        );
    }

    public function findByIngestSecret(string $secret): ?array
    {
        $secret = trim($secret);
        if ($secret === '') {
            return null;
        }

        if (!$this->isWorkspaceScoped()) {
            $config = $this->get(null, true);
            return !empty($config['ingest_secret']) && hash_equals((string) $config['ingest_secret'], $secret) ? $config : null;
        }

        $rows = Database::query(
            "SELECT workspace_id, ingest_secret
             FROM meeting_note_taker_config
             WHERE ingest_secret IS NOT NULL
               AND ingest_secret <> ''"
        );
        foreach ($rows as $row) {
            if (hash_equals((string) ($row['ingest_secret'] ?? ''), $secret)) {
                return $this->get((int) ($row['workspace_id'] ?? 0), true);
            }
        }

        return null;
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
        return Database::columnExists('meeting_note_taker_config', 'workspace_id');
    }
}
