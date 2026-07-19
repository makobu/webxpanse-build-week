<?php

namespace CRM\Services;

use CRM\Database;

class WhatsAppAssistantConfig
{
    public const STATE_SESSION_OPEN = 'session_open';
    public const STATE_EXPIRING_SOON = 'expiring_soon';
    public const STATE_EXPIRED_REQUIRES_REOPEN = 'expired_requires_reopen';

    public function validate(): array
    {
        $enabled = $this->isEnabled();
        $runtime = $this->workspaceRuntimeConfig();
        $phoneNumberId = trim((string) ($runtime['assistant_phone_number_id'] ?? ($_ENV['WHATSAPP_ASSISTANT_PHONE_NUMBER_ID'] ?? '')));
        $assistantPhone = $this->normalizePhoneNumber((string) ($runtime['assistant_phone_number'] ?? ($_ENV['WHATSAPP_ASSISTANT_PHONE_NUMBER'] ?? '')));
        $accessToken = trim((string) ($runtime['access_token'] ?? ($_ENV['WHATSAPP_ASSISTANT_ACCESS_TOKEN'] ?? $_ENV['WHATSAPP_ACCESS_TOKEN'] ?? '')));
        $templateConfig = $this->getReopenTemplateConfig();
        $senderMode = (string) ($runtime['sender_mode'] ?? 'custom');
        $usesWorkspaceSender = !empty($runtime['uses_workspace_sender']);
        $workspacePhoneNumberId = trim((string) ($runtime['workspace_phone_number_id'] ?? ''));
        $workspaceSenderReady = !$usesWorkspaceSender || ($workspacePhoneNumberId !== '' && !empty($runtime['workspace_access_token_available']));
        $customWebhookReady = $usesWorkspaceSender
            || ($phoneNumberId !== '' && $workspacePhoneNumberId !== '' && hash_equals($workspacePhoneNumberId, $phoneNumberId));
        $authorizedCount = $this->countActiveAuthorizedNumbers();

        $outboundReady = $phoneNumberId !== '' && $accessToken !== '' && $workspaceSenderReady;
        $inboundReady = $enabled && ($phoneNumberId !== '' || $assistantPhone !== '') && $customWebhookReady;
        if ($usesWorkspaceSender && !$workspaceSenderReady) {
            $message = 'Complete WhatsApp setup so the assistant can inherit the workspace sender.';
        } elseif (!$customWebhookReady) {
            $message = 'The custom assistant number must be the number connected to this workspace webhook.';
        } elseif ($outboundReady && $inboundReady && $authorizedCount > 0) {
            $message = 'WhatsApp assistant is ready.';
        } elseif ($authorizedCount <= 0) {
            $message = 'Add at least one authorized team number for WhatsApp Assistant.';
        } else {
            $message = 'Configure the assistant phone number id and WhatsApp access token.';
        }

        return [
            'enabled' => $enabled,
            'sender_mode' => $senderMode,
            'uses_workspace_sender' => $usesWorkspaceSender,
            'assistant_phone_number' => $assistantPhone,
            'assistant_phone_number_id' => $phoneNumberId,
            'workspace_phone_number' => $this->normalizePhoneNumber((string) ($runtime['workspace_phone_number'] ?? '')),
            'workspace_phone_number_id' => $workspacePhoneNumberId,
            'workspace_sender_ready' => $workspaceSenderReady,
            'custom_webhook_ready' => $customWebhookReady,
            'authorized_number_count' => $authorizedCount,
            'outbound_ready' => $outboundReady,
            'inbound_ready' => $inboundReady,
            'message' => $message,
            'digest_enabled' => $this->isDigestEnabled(),
            'digest_time' => trim((string) ($runtime['digest_time'] ?? ($_ENV['WHATSAPP_ASSISTANT_DIGEST_TIME'] ?? '07:00'))),
            'max_chars' => (int) (($runtime['max_message_chars'] ?? '') !== '' ? $runtime['max_message_chars'] : ($_ENV['WHATSAPP_ASSISTANT_MAX_MESSAGE_CHARS'] ?? 550)),
            'max_chunks' => (int) (($runtime['max_message_chunks'] ?? '') !== '' ? $runtime['max_message_chunks'] : ($_ENV['WHATSAPP_ASSISTANT_MAX_MESSAGE_CHUNKS'] ?? 6)),
            'keepalive_warning_hours' => $this->getKeepaliveWarningHours(),
            'auto_reopen_enabled' => $this->isAutoReopenEnabled(),
            'reopen_template_ready' => !empty($templateConfig['is_ready']),
            'reopen_template_name' => (string) ($templateConfig['template_name'] ?? ''),
        ];
    }

    public function isEnabled(): bool
    {
        $runtime = $this->workspaceRuntimeConfig();
        if (array_key_exists('enabled', $runtime)) {
            return !empty($runtime['enabled']);
        }
        return strtolower((string) ($_ENV['WHATSAPP_ASSISTANT_ENABLED'] ?? 'false')) === 'true';
    }

    public function isDigestEnabled(): bool
    {
        $runtime = $this->workspaceRuntimeConfig();
        if (array_key_exists('digest_enabled', $runtime)) {
            return !empty($runtime['digest_enabled']);
        }
        return strtolower((string) ($_ENV['WHATSAPP_ASSISTANT_DIGEST_ENABLED'] ?? 'false')) === 'true';
    }

    public function isAutoReopenEnabled(): bool
    {
        $runtime = $this->workspaceRuntimeConfig();
        if (array_key_exists('auto_reopen_enabled', $runtime) && $runtime['auto_reopen_enabled'] !== null) {
            return !empty($runtime['auto_reopen_enabled']);
        }
        return strtolower((string) ($_ENV['WHATSAPP_ASSISTANT_AUTO_REOPEN_ENABLED'] ?? 'true')) === 'true';
    }

    public function getKeepaliveWarningHours(): int
    {
        $runtime = $this->workspaceRuntimeConfig();
        if (($runtime['keepalive_warning_hours'] ?? '') !== '') {
            return max(1, (int) $runtime['keepalive_warning_hours']);
        }
        return max(1, (int) ($_ENV['WHATSAPP_ASSISTANT_KEEPALIVE_WARNING_HOURS'] ?? 4));
    }

    public function getSessionWindowHours(): int
    {
        return max(1, (int) ($_ENV['WHATSAPP_ASSISTANT_SESSION_WINDOW_HOURS'] ?? 24));
    }

    public function getReopenTemplateConfig(): array
    {
        $runtime = $this->workspaceRuntimeConfig();
        $templateName = trim((string) (($runtime['reopen_template_name'] ?? '') !== '' ? $runtime['reopen_template_name'] : ($_ENV['WHATSAPP_ASSISTANT_REOPEN_TEMPLATE_NAME'] ?? '')));
        $language = trim((string) (($runtime['reopen_template_language'] ?? '') !== '' ? $runtime['reopen_template_language'] : ($_ENV['WHATSAPP_ASSISTANT_REOPEN_TEMPLATE_LANGUAGE'] ?? 'en_US'))) ?: 'en_US';
        $headerValues = $this->parseTemplateValues((string) (($runtime['reopen_template_header_values'] ?? '') !== '' ? $runtime['reopen_template_header_values'] : ($_ENV['WHATSAPP_ASSISTANT_REOPEN_TEMPLATE_HEADER_VALUES'] ?? '')));
        $bodyValues = $this->parseTemplateValues((string) (($runtime['reopen_template_body_values'] ?? '') !== '' ? $runtime['reopen_template_body_values'] : ($_ENV['WHATSAPP_ASSISTANT_REOPEN_TEMPLATE_BODY_VALUES'] ?? '')));
        $buttonValues = $this->parseTemplateValues((string) (($runtime['reopen_template_button_values'] ?? '') !== '' ? $runtime['reopen_template_button_values'] : ($_ENV['WHATSAPP_ASSISTANT_REOPEN_TEMPLATE_BUTTON_VALUES'] ?? '')));

        return [
            'template_name' => $templateName,
            'language' => $language,
            'header_values' => $headerValues,
            'body_values' => $bodyValues,
            'button_values' => $buttonValues,
            'template_params' => [
                'header' => $headerValues,
                'body' => $bodyValues,
                'buttons' => $buttonValues,
            ],
            'is_ready' => $templateName !== '',
        ];
    }

    public function normalizePhoneNumber(string $phoneNumber): string
    {
        $normalized = preg_replace('/[^\d+]/', '', trim($phoneNumber));
        $normalized = ltrim((string) $normalized, '+');
        $normalized = ltrim((string) $normalized, '0');
        return (string) $normalized;
    }

    public function isAssistantWebhookTarget(array $metadata): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        $runtime = $this->workspaceRuntimeConfig();
        $assistantPhoneNumberId = trim((string) ($runtime['assistant_phone_number_id'] ?? ($_ENV['WHATSAPP_ASSISTANT_PHONE_NUMBER_ID'] ?? '')));
        $assistantPhone = $this->normalizePhoneNumber((string) ($runtime['assistant_phone_number'] ?? ($_ENV['WHATSAPP_ASSISTANT_PHONE_NUMBER'] ?? '')));
        $targetPhoneNumberId = trim((string) ($metadata['phone_number_id'] ?? ''));
        $targetDisplayPhone = $this->normalizePhoneNumber((string) ($metadata['display_phone_number'] ?? ''));

        if ($assistantPhoneNumberId !== '' && $targetPhoneNumberId !== '') {
            return hash_equals($assistantPhoneNumberId, $targetPhoneNumberId);
        }

        return $assistantPhone !== '' && $targetDisplayPhone !== '' && hash_equals($assistantPhone, $targetDisplayPhone);
    }

    public function findAuthorizedNumber(string $phoneNumber): ?array
    {
        $normalized = $this->normalizePhoneNumber($phoneNumber);
        if ($normalized === '') {
            return null;
        }

        $params = [$normalized];
        $workspaceSql = $this->workspacePredicate('wan', 'whatsapp_assistant_authorized_numbers', $params);
        $row = Database::queryOne(
            "SELECT wan.*, u.first_name, u.last_name, u.email
             FROM whatsapp_assistant_authorized_numbers wan
             JOIN users u ON u.id = wan.user_id
             WHERE wan.phone_number = ?
               AND wan.is_active = 1
               {$workspaceSql}
             LIMIT 1",
            $params
        );

        return $row ?: null;
    }

    public function getAuthorizedNumbers(bool $activeOnly = false, ?int $workspaceId = null): array
    {
        $sql = "SELECT wan.*, u.first_name, u.last_name, u.email
                FROM whatsapp_assistant_authorized_numbers wan
                JOIN users u ON u.id = wan.user_id";
        $params = [];
        $where = [];
        if ($activeOnly) {
            $where[] = "wan.is_active = 1";
        }
        $workspaceSql = $this->workspacePredicate('wan', 'whatsapp_assistant_authorized_numbers', $params, $workspaceId, false);
        if ($workspaceSql !== '') {
            $where[] = ltrim(preg_replace('/^\s*AND\s+/i', '', $workspaceSql) ?? $workspaceSql);
        }
        if ($where !== []) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }
        $sql .= " ORDER BY wan.is_active DESC, wan.label ASC, wan.phone_number ASC";
        return Database::query($sql, $params);
    }

    public function getDigestRecipients(?int $workspaceId = null): array
    {
        $params = [];
        $workspaceSql = $this->workspacePredicate('wan', 'whatsapp_assistant_authorized_numbers', $params, $workspaceId);
        return Database::query(
            "SELECT wan.*, u.first_name, u.last_name, u.email
             FROM whatsapp_assistant_authorized_numbers wan
             JOIN users u ON u.id = wan.user_id
             WHERE wan.is_active = 1
               AND wan.digest_enabled = 1
               {$workspaceSql}
             ORDER BY wan.phone_number ASC",
            $params
        );
    }

    public function countActiveAuthorizedNumbers(?int $workspaceId = null): int
    {
        $params = [];
        $workspaceSql = $this->workspacePredicate('wan', 'whatsapp_assistant_authorized_numbers', $params, $workspaceId);
        try {
            $row = Database::queryOne(
                "SELECT COUNT(*) AS c
                 FROM whatsapp_assistant_authorized_numbers wan
                 WHERE wan.is_active = 1
                   {$workspaceSql}",
                $params
            );
            return (int) ($row['c'] ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    public function touchAuthorizedNumber(int $id): void
    {
        if ($id <= 0) {
            return;
        }
        $params = [$id];
        $workspaceSql = $this->workspacePredicate('', 'whatsapp_assistant_authorized_numbers', $params);
        $workspaceSql = $workspaceSql !== '' ? str_replace('AND .workspace_id', 'AND workspace_id', $workspaceSql) : '';
        Database::execute(
            "UPDATE whatsapp_assistant_authorized_numbers
             SET last_used_at = NOW()
             WHERE id = ?
             {$workspaceSql}",
            $params
        );
    }

    private function parseTemplateValues(string $raw): array
    {
        $items = preg_split('/\r\n|\r|\n/', trim($raw));
        if (!is_array($items)) {
            return [];
        }

        $items = array_map(static fn ($value) => trim((string) $value), $items);
        return array_values(array_filter($items, static fn ($value) => $value !== ''));
    }

    private function workspaceRuntimeConfig(): array
    {
        $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        if ($workspaceId <= 0) {
            return [];
        }

        try {
            return (new WorkspaceAssistantConfigService())->whatsappRuntimeConfig($workspaceId);
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function workspacePredicate(
        string $alias,
        string $table,
        array &$params,
        ?int $workspaceId = null,
        bool $prefixAnd = true
    ): string {
        $workspaceId = $workspaceId !== null ? $workspaceId : (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        if ($workspaceId <= 0 || !Database::tableExists($table) || !Database::columnExists($table, 'workspace_id')) {
            return '';
        }

        $params[] = $workspaceId;
        $column = $alias !== '' ? $alias . '.workspace_id' : 'workspace_id';
        return ($prefixAnd ? ' AND ' : '') . $column . ' = ?';
    }
}
