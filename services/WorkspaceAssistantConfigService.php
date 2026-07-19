<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Security;

class WorkspaceAssistantConfigService
{
    private const SECRET_KEYS = [
        'smtp_password',
        'imap_password',
        'access_token',
    ];

    public function isAvailable(): bool
    {
        try {
            $row = Database::queryOne(
                "SELECT COUNT(*) AS c
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'workspace_assistant_configs'"
            );
            return ((int) ($row['c'] ?? 0)) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function save(int $workspaceId, string $assistantType, array $settings, bool $enabled, int $userId = 0): void
    {
        if (!$this->isAvailable() || $workspaceId <= 0) {
            return;
        }

        $assistantType = $this->normalizeType($assistantType);
        $safeSettings = $this->prepareForStorage($settings);

        Database::execute(
            "INSERT INTO workspace_assistant_configs
                (workspace_id, assistant_type, enabled, settings_json, created_by_user_id, updated_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                enabled = VALUES(enabled),
                settings_json = VALUES(settings_json),
                updated_by_user_id = VALUES(updated_by_user_id),
                updated_at = NOW()",
            [
                $workspaceId,
                $assistantType,
                $enabled ? 1 : 0,
                json_encode($safeSettings, JSON_UNESCAPED_SLASHES),
                $userId > 0 ? $userId : null,
                $userId > 0 ? $userId : null,
            ]
        );
    }

    public function get(int $workspaceId, string $assistantType, bool $withSecrets = false): array
    {
        if (!$this->isAvailable() || $workspaceId <= 0) {
            return [];
        }

        $row = Database::queryOne(
            "SELECT *
             FROM workspace_assistant_configs
             WHERE workspace_id = ?
               AND assistant_type = ?
             LIMIT 1",
            [$workspaceId, $this->normalizeType($assistantType)]
        );
        if (!$row) {
            return [];
        }

        $settings = [];
        $decoded = json_decode((string) ($row['settings_json'] ?? '{}'), true);
        if (is_array($decoded)) {
            $settings = $withSecrets ? $this->restoreFromStorage($decoded) : $this->maskSecrets($decoded);
        }

        return [
            'workspace_id' => (int) ($row['workspace_id'] ?? 0),
            'assistant_type' => (string) ($row['assistant_type'] ?? ''),
            'enabled' => !empty($row['enabled']),
            'settings' => $settings,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    public function emailSmtpConfig(int $workspaceId): array
    {
        $config = $this->get($workspaceId, 'email', true);
        $settings = (array) ($config['settings'] ?? []);
        return [
            'enabled' => !empty($config['enabled']),
            'host' => trim((string) ($settings['smtp_host'] ?? '')),
            'port' => (int) ($settings['smtp_port'] ?? 587),
            'username' => trim((string) ($settings['smtp_username'] ?? '')),
            'password' => (string) ($settings['smtp_password'] ?? ''),
            'encryption' => strtolower(trim((string) ($settings['smtp_encryption'] ?? 'tls'))),
            'from_email' => trim((string) ($settings['from_email'] ?? '')),
            'from_name' => trim((string) ($settings['from_name'] ?? '')),
        ];
    }

    public function whatsappRuntimeConfig(int $workspaceId): array
    {
        $config = $this->get($workspaceId, 'whatsapp', true);
        $settings = (array) ($config['settings'] ?? []);
        $connection = $this->activeWhatsAppConnection($workspaceId);
        $connectionAvailable = !empty($connection);
        $workspacePhoneNumber = trim((string) ($connection['display_phone_number'] ?? ''));
        $workspacePhoneNumberId = trim((string) ($connection['phone_number_id'] ?? ''));
        $workspaceAccessToken = trim((string) ($connection['access_token'] ?? ''));
        $customPhoneNumber = trim((string) ($settings['assistant_phone_number'] ?? ''));
        $customPhoneNumberId = trim((string) ($settings['assistant_phone_number_id'] ?? ''));
        $customAccessToken = trim((string) ($settings['access_token'] ?? ''));
        $requestedSenderMode = trim((string) ($settings['sender_mode'] ?? ''));
        $legacyCustomConfig = $requestedSenderMode === ''
            && ($customPhoneNumber !== '' || $customPhoneNumberId !== '' || $customAccessToken !== '');
        $senderMode = $requestedSenderMode === 'custom' || $legacyCustomConfig ? 'custom' : 'workspace';
        $usesWorkspaceSender = $senderMode !== 'custom';

        return [
            'enabled' => !empty($config['enabled']),
            'sender_mode' => $senderMode,
            'uses_workspace_sender' => $usesWorkspaceSender,
            'assistant_phone_number' => $usesWorkspaceSender ? $workspacePhoneNumber : $customPhoneNumber,
            'assistant_phone_number_id' => $usesWorkspaceSender ? $workspacePhoneNumberId : $customPhoneNumberId,
            'access_token' => $usesWorkspaceSender ? $workspaceAccessToken : $customAccessToken,
            'workspace_sender_available' => $connectionAvailable,
            'workspace_phone_number' => $workspacePhoneNumber,
            'workspace_phone_number_id' => $workspacePhoneNumberId,
            'workspace_access_token_available' => $workspaceAccessToken !== '',
            'workspace_connection_status' => (string) ($connection['connection_status'] ?? ''),
            'custom_assistant_phone_number' => $customPhoneNumber,
            'custom_assistant_phone_number_id' => $customPhoneNumberId,
            'custom_access_token_available' => $customAccessToken !== '',
            'digest_enabled' => !empty($settings['digest_enabled']),
            'digest_time' => trim((string) ($settings['digest_time'] ?? '07:00')),
            'max_message_chars' => trim((string) ($settings['max_message_chars'] ?? '')),
            'max_message_chunks' => trim((string) ($settings['max_message_chunks'] ?? '')),
            'keepalive_warning_hours' => trim((string) ($settings['keepalive_warning_hours'] ?? '')),
            'auto_reopen_enabled' => array_key_exists('auto_reopen_enabled', $settings) ? !empty($settings['auto_reopen_enabled']) : null,
            'reopen_template_name' => trim((string) ($settings['reopen_template_name'] ?? '')),
            'reopen_template_language' => trim((string) ($settings['reopen_template_language'] ?? '')),
            'reopen_template_header_values' => (string) ($settings['reopen_template_header_values'] ?? ''),
            'reopen_template_body_values' => (string) ($settings['reopen_template_body_values'] ?? ''),
            'reopen_template_button_values' => (string) ($settings['reopen_template_button_values'] ?? ''),
        ];
    }

    private function activeWhatsAppConnection(int $workspaceId): array
    {
        if ($workspaceId <= 0) {
            return [];
        }

        try {
            return (new WorkspaceConnectService())->getActiveWhatsAppIntegration($workspaceId) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function prepareForStorage(array $settings): array
    {
        $out = [];
        foreach ($settings as $key => $value) {
            $key = trim((string) $key);
            if ($key === '') {
                continue;
            }
            $value = is_bool($value) ? $value : trim((string) $value);
            if (in_array($key, self::SECRET_KEYS, true)) {
                if ($value === '') {
                    continue;
                }
                $out[$key] = [
                    'encrypted' => true,
                    'value' => Security::encryptSensitiveData((string) $value, $this->encryptionKey()),
                ];
                continue;
            }
            $out[$key] = $value;
        }
        return $out;
    }

    private function restoreFromStorage(array $settings): array
    {
        foreach (self::SECRET_KEYS as $key) {
            $value = $settings[$key] ?? null;
            if (is_array($value) && !empty($value['encrypted']) && isset($value['value'])) {
                try {
                    $settings[$key] = Security::decryptSensitiveData((string) $value['value'], $this->encryptionKey());
                } catch (\Throwable $e) {
                    $settings[$key] = '';
                }
            }
        }
        return $settings;
    }

    private function maskSecrets(array $settings): array
    {
        foreach (self::SECRET_KEYS as $key) {
            if (array_key_exists($key, $settings)) {
                $settings[$key] = 'saved';
            }
        }
        return $settings;
    }

    private function normalizeType(string $assistantType): string
    {
        return $assistantType === 'whatsapp' ? 'whatsapp' : 'email';
    }

    private function encryptionKey(): string
    {
        $seed = (string) ($_ENV['APP_KEY'] ?? $_ENV['APP_SECRET'] ?? $_ENV['DB_PASS'] ?? 'crm-local-secret');
        return hash('sha256', $seed, true);
    }
}
