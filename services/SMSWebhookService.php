<?php

namespace CRM\Services;

use CRM\Database;
use CRM\EventBus;

class SMSWebhookService
{
    private static ?bool $hasContactMobileColumn = null;

    public function __construct(
        private readonly ?TwilioWebhookSignatureValidator $signatureValidator = null,
        private readonly ?WorkspaceSmsChannelConfigService $configService = null,
        private readonly ?SmsComplianceService $compliance = null
    ) {
    }

    public function handle(array $payload, string $requestUrl, string $signature): array
    {
        $messageSid = trim((string) ($payload['MessageSid'] ?? ''));
        $status = trim((string) ($payload['MessageStatus'] ?? ''));

        $isStatusCallback = $messageSid !== ''
            && $status !== ''
            && !in_array(strtolower($status), ['received', 'inbound'], true);

        if ($isStatusCallback) {
            [$workspaceId, $runtimeConfig] = $this->resolveStatusCallbackConfig($messageSid);
            $this->assertValidSignature($requestUrl, $payload, $signature, $runtimeConfig);
            (new SMSService())->updateMessageStatus($messageSid, $this->mapTwilioStatus($status), [
                'error_message' => $payload['ErrorMessage'] ?? null,
                'workspace_id' => $workspaceId,
            ]);

            return [
                'accepted' => true,
                'handled' => true,
                'type' => 'status_callback',
                'workspace_id' => $workspaceId,
            ];
        }

        $from = trim((string) ($payload['From'] ?? ''));
        $to = trim((string) ($payload['To'] ?? ''));
        $body = trim((string) ($payload['Body'] ?? ''));

        if ($messageSid === '' || $from === '' || $body === '') {
            return [
                'accepted' => true,
                'handled' => false,
                'ignored_reason' => 'missing_required_fields',
            ];
        }

        [$workspaceId, $runtimeConfig] = $this->resolveInboundConfig($to);
        $this->assertValidSignature($requestUrl, $payload, $signature, $runtimeConfig);
        if ($workspaceId <= 0) {
            error_log('SMS webhook: unable to resolve workspace for inbound message.');
            return [
                'accepted' => true,
                'handled' => false,
                'ignored_reason' => 'workspace_unresolved',
            ];
        }

        $handled = AsyncWorkspaceRunner::runWithWorkspace(
            $workspaceId,
            function () use ($body, $from, $messageSid, $to, $workspaceId): bool {
                $existingSms = Database::queryOne(
                    "SELECT id
                     FROM sms_messages
                     WHERE workspace_id = ?
                       AND provider_message_id = ?
                     LIMIT 1",
                    [$workspaceId, $messageSid]
                );
                if ($existingSms) {
                    return true;
                }

                $contact = $this->findContactForWorkspace($from, $workspaceId);
                $contactId = (int) ($contact['id'] ?? 0) ?: null;
                if ($contactId === null || $contactId <= 0) {
                    error_log('SMS webhook: inbound message could not be matched to a contact inside the resolved workspace.');
                    return false;
                }

                $isOptOut = ($this->compliance ?? new SmsComplianceService())->isOptOutMessage($body);
                if ($isOptOut) {
                    ($this->compliance ?? new SmsComplianceService())->recordInboundOptOut($workspaceId, $from, $messageSid);
                }

                $uuid = $this->generateUuid();
                Database::execute(
                    "INSERT INTO sms_messages
                     (workspace_id, uuid, contact_id, to_number, from_number, message_body, status, direction, provider, provider_message_id)
                     VALUES (?, ?, ?, ?, ?, ?, 'delivered', 'inbound', 'twilio', ?)",
                    [$workspaceId, $uuid, $contactId, $to, $from, $body, $messageSid]
                );

                Database::execute(
                    "INSERT INTO communications
                     (workspace_id, uuid, contact_id, channel, direction, subject, body, status, metadata, created_at)
                     VALUES (?, ?, ?, 'sms', 'inbound', ?, ?, 'delivered', ?, NOW())",
                    [
                        $workspaceId,
                        $this->generateUuid(),
                        $contactId,
                        'SMS from ' . $from,
                        $body,
                        json_encode([
                            'message_sid' => $messageSid,
                            'provider_message_id' => $messageSid,
                            'from' => $from,
                            'to' => $to,
                            'sms_opt_out' => $isOptOut,
                        ], JSON_UNESCAPED_SLASHES),
                    ]
                );

                $communicationId = (int) Database::lastInsertId();

                try {
                    (new ConversationIntelligenceService())->syncForCommunication($communicationId);
                } catch (\Throwable $e) {
                    error_log('SMS webhook: Conversation intelligence sync failed: ' . $e->getMessage());
                }

                EventBus::publish('sms.message_received', [
                    'contact_id' => (int) $contactId,
                    'communication_id' => $communicationId,
                    'channel' => 'sms',
                    'direction' => 'inbound',
                    'message_text' => $body,
                    'from_number' => $from,
                    'to_number' => $to,
                    'message_sid' => $messageSid,
                ]);

                if (!$isOptOut) {
                    try {
                        (new AIAutoResponderQueueService())->enqueueInboundCommunication(
                            $communicationId,
                            (int) $contactId,
                            'sms',
                            $body,
                            [
                                'message_sid' => $messageSid,
                                'from' => $from,
                                'to' => $to,
                            ]
                        );
                    } catch (\Throwable $e) {
                        error_log('SMS webhook: Failed to enqueue AI auto-responder item: ' . $e->getMessage());
                    }
                }

                try {
                    (new InboxTriageService())->processCommunication($communicationId);
                } catch (\Throwable $e) {
                    error_log('SMS webhook: Inbox triage failed: ' . $e->getMessage());
                }

                if ($contactId !== null && $contactId > 0) {
                    try {
                        (new ContactIntelligenceService())->computeAndPersist((int) $contactId);
                    } catch (\Throwable $e) {
                        error_log('SMS webhook: Contact intelligence refresh failed: ' . $e->getMessage());
                    }

                    try {
                        (new TargetIntelligenceService())->refreshAfterEntityChange('communications', $communicationId, [
                            'contact_id' => (int) $contactId,
                            'channel' => 'sms',
                        ]);
                    } catch (\Throwable $e) {
                        error_log('SMS webhook: Target intelligence refresh failed: ' . $e->getMessage());
                    }
                }

                return true;
            },
            null,
            'SMS inbound message is missing a valid workspace.'
        );

        if (!$handled) {
            return [
                'accepted' => true,
                'handled' => false,
                'ignored_reason' => 'contact_unresolved',
                'workspace_id' => $workspaceId,
            ];
        }

        return [
            'accepted' => true,
            'handled' => true,
            'type' => 'inbound_message',
            'workspace_id' => $workspaceId,
        ];
    }

    private function assertValidSignature(string $requestUrl, array $payload, string $signature, array $runtimeConfig): void
    {
        $valid = ($this->signatureValidator ?? new TwilioWebhookSignatureValidator())->isValid(
            $requestUrl,
            $payload,
            $signature,
            (string) ($runtimeConfig['auth_token'] ?? '')
        );
        if (!$valid) {
            throw new SMSWebhookAuthenticationException('Invalid Twilio webhook signature.');
        }
    }

    /** @return array{0:int,1:array<string,mixed>} */
    private function resolveStatusCallbackConfig(string $messageSid): array
    {
        $message = Database::queryOne(
            "SELECT workspace_id FROM sms_messages WHERE provider_message_id = ? LIMIT 1",
            [$messageSid]
        );
        $workspaceId = (int) ($message['workspace_id'] ?? 0);
        $config = ($this->configService ?? new WorkspaceSmsChannelConfigService())->runtimeConfig($workspaceId, false);
        if ($workspaceId <= 0 || empty($config['ready']) || empty($config['status_callbacks_enabled'])) {
            throw new SMSWebhookAuthenticationException('SMS status callback is not enabled for this message.');
        }
        return [$workspaceId, $config];
    }

    /** @return array{0:int,1:array<string,mixed>} */
    private function resolveInboundConfig(string $to): array
    {
        $businessPhones = $this->phoneVariants($to);
        if ($businessPhones === []) {
            throw new SMSWebhookAuthenticationException('SMS inbound webhook workspace could not be resolved.');
        }
        $placeholders = implode(',', array_fill(0, count($businessPhones), '?'));
        $rows = Database::query(
            "SELECT workspace_id
             FROM workspace_sms_channel_configs
             WHERE enabled = 1
               AND webhook_enabled = 1
               AND REPLACE(REPLACE(REPLACE(COALESCE(from_number, ''), '+', ''), ' ', ''), '-', '') IN ({$placeholders})
             ORDER BY workspace_id ASC
             LIMIT 2",
            $businessPhones
        );
        if (count($rows) !== 1) {
            throw new SMSWebhookAuthenticationException('SMS inbound webhook workspace could not be resolved uniquely.');
        }

        $workspaceId = (int) ($rows[0]['workspace_id'] ?? 0);
        $catalog = new WorkspaceSkillCatalogService();
        $installer = new WorkspaceSkillInstallService($catalog);
        if ($workspaceId <= 0
            || $catalog->isGloballyDeactivated(WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL)
            || !$installer->isInstalled($workspaceId, WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL)) {
            throw new SMSWebhookAuthenticationException('SMS inbound webhook is disabled for this workspace.');
        }

        $config = ($this->configService ?? new WorkspaceSmsChannelConfigService())->runtimeConfig($workspaceId, false);
        if (empty($config['ready']) || empty($config['webhook_enabled'])) {
            throw new SMSWebhookAuthenticationException('SMS inbound webhook is not configured for this workspace.');
        }
        return [$workspaceId, $config];
    }

    public function mapTwilioStatus(string $twilioStatus): string
    {
        $statusMap = [
            'queued' => 'queued',
            'sending' => 'pending',
            'sent' => 'sent',
            'delivered' => 'delivered',
            'undelivered' => 'undelivered',
            'failed' => 'failed',
        ];

        return $statusMap[strtolower(trim($twilioStatus))] ?? 'pending';
    }

    private function findContactForWorkspace(string $phone, int $workspaceId): ?array
    {
        $variants = $this->phoneVariants($phone);
        if ($workspaceId <= 0 || $variants === []) {
            return null;
        }

        [$phoneClause, $params] = $this->contactPhoneClause($variants);

        return Database::queryOne(
            "SELECT id, workspace_id
             FROM contacts
             WHERE workspace_id = ?
               AND ({$phoneClause})
             LIMIT 1",
            array_merge([$workspaceId], $params)
        );
    }

    /**
     * @return string[]
     */
    private function phoneVariants(string $phone): array
    {
        $normalized = preg_replace('/[^\d]/', '', $phone);
        if (!is_string($normalized) || $normalized === '') {
            return [];
        }

        return array_values(array_unique(array_filter([
            $normalized,
            (strlen($normalized) === 12 && str_starts_with($normalized, '254')) ? ('0' . substr($normalized, 3)) : null,
            (strlen($normalized) === 10 && str_starts_with($normalized, '0')) ? ('254' . substr($normalized, 1)) : null,
        ])));
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * @param string[] $phones
     * @return array{0:string,1:array<int,mixed>}
     */
    private function buildContactPhoneLookupSql(string $template, array $phones): array
    {
        [$phoneClause, $params] = $this->contactPhoneClause($phones);
        return [sprintf($template, $phoneClause), $params];
    }

    /**
     * @param string[] $phones
     * @return array{0:string,1:array<int,mixed>}
     */
    private function contactPhoneClause(array $phones): array
    {
        $placeholders = implode(',', array_fill(0, count($phones), '?'));
        $clauses = [
            "REPLACE(REPLACE(REPLACE(COALESCE(phone, ''), '+', ''), ' ', ''), '-', '') IN ({$placeholders})",
        ];
        $params = $phones;

        if ($this->contactMobileColumnExists()) {
            $clauses[] = "REPLACE(REPLACE(REPLACE(COALESCE(mobile, ''), '+', ''), ' ', ''), '-', '') IN ({$placeholders})";
            $params = array_merge($params, $phones);
        }

        return [implode(' OR ', $clauses), $params];
    }

    private function contactMobileColumnExists(): bool
    {
        if (self::$hasContactMobileColumn !== null) {
            return self::$hasContactMobileColumn;
        }

        try {
            $row = Database::queryOne(
                "SELECT COUNT(*) AS c
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'contacts'
                   AND COLUMN_NAME = 'mobile'"
            );
            self::$hasContactMobileColumn = ((int) ($row['c'] ?? 0)) > 0;
        } catch (\Throwable $e) {
            self::$hasContactMobileColumn = false;
        }

        return self::$hasContactMobileColumn;
    }
}
