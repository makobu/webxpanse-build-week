<?php
/**
 * WhatsApp Webhook Handler
 */

namespace CRM\Services;

use CRM\Database;
use CRM\EventBus;
use CRM\Modules\Contacts;
use CRM\Modules\Activities;
use CRM\Modules\ConversationThreads;
use CRM\Modules\Notifications;
use CRM\Modules\NotificationPreferences;
use CRM\Modules\SentimentAnalysis;
use CRM\Modules\IntentDetection;
use CRM\Services\WorkspaceContext;

class WhatsAppWebhook
{
    private Contacts $contacts;
    private Activities $activities;
    private ?int $workspaceIdHint;
    
    public function __construct(?int $workspaceIdHint = null)
    {
        $this->contacts = new Contacts();
        $this->activities = new Activities();
        $this->workspaceIdHint = $workspaceIdHint !== null && $workspaceIdHint > 0 ? $workspaceIdHint : null;
    }

    private function isVerboseDebugEnabled(): bool
    {
        return strtolower((string) ($_ENV['APP_DEBUG'] ?? 'false')) === 'true'
            && strtolower((string) ($_ENV['WHATSAPP_VERBOSE_DEBUG'] ?? 'false')) === 'true';
    }

    private function appendJsonLog(?string $path, array $entry): void
    {
        if (!$this->isVerboseDebugEnabled() || $path === null || $path === '') {
            return;
        }

        $directory = dirname($path);
        if (!is_dir($directory)) {
            @mkdir($directory, 0755, true);
        }

        @file_put_contents($path, json_encode($entry) . "\n", FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Handle webhook payload
     * Meta may send multiple entries - iterate all
     */
    public function handle(array $payload): void
    {
        $entries = $payload['entry'] ?? [];
        $debugLog = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.cursor' . DIRECTORY_SEPARATOR . 'debug.log';
        $changesCount = 0;
        foreach ($entries as $e) {
            $changesCount += count($e['changes'] ?? []);
        }
        $this->appendJsonLog($debugLog, ['id' => 'log_' . uniqid(), 'timestamp' => (int) (microtime(true) * 1000), 'location' => 'WhatsAppWebhook:handle_entry', 'message' => 'Handler started', 'data' => ['entry_count' => count($entries), 'changes_count' => $changesCount]]);
        if (!is_array($entries) || empty($entries)) {
            return;
        }

        $dl = (dirname(__DIR__) . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'whatsapp_delivery_audit.log');

        foreach ($entries as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];
                $metadata = (array) ($value['metadata'] ?? []);
                if (!isset($metadata['whatsapp_business_account_id']) && !empty($entry['id'])) {
                    $metadata['whatsapp_business_account_id'] = (string) $entry['id'];
                }

                // Handle incoming messages
                if (!empty($value['messages'])) {
                    $this->appendJsonLog($debugLog, ['id' => 'log_' . uniqid(), 'timestamp' => (int) (microtime(true) * 1000), 'location' => 'WhatsAppWebhook:processing_messages', 'message' => 'Processing messages batch', 'data' => ['messages_count' => count($value['messages'])]]);
                    $contacts = $value['contacts'] ?? [];
                    foreach ($value['messages'] as $message) {
                        $from = $message['from'] ?? '';
                        $contactInfo = [];
                        foreach ($contacts as $c) {
                            if (($c['wa_id'] ?? '') === $from) {
                                $contactInfo = $c;
                                break;
                            }
                        }
                        if (empty($contactInfo) && !empty($contacts)) {
                            $contactInfo = $contacts[0];
                        }
                        try {
                            $assistantWorkspaceId = $this->resolveIncomingWorkspaceId($message, $contactInfo, $metadata);
                            $previousRuntimeWorkspace = WorkspaceContext::runtimeSnapshot();
                            if ($assistantWorkspaceId > 0) {
                                WorkspaceContext::activateRuntimeWorkspace($assistantWorkspaceId);
                            }
                            try {
                                $assistant = new WhatsAppAssistantService();
                                if ($assistant->handleWebhookMessage($message, $metadata, $contactInfo)) {
                                    continue;
                                }
                            } finally {
                                if ($previousRuntimeWorkspace !== null) {
                                    WorkspaceContext::restoreRuntimeWorkspace($previousRuntimeWorkspace);
                                } else {
                                    WorkspaceContext::clearRuntimeWorkspace();
                                }
                            }
                        } catch (\Throwable $e) {
                            error_log('WhatsApp assistant webhook routing failed: ' . $e->getMessage());
                        }
                        $this->processIncomingMessage($message, $contactInfo, $dl, $metadata);
                    }
                }

                // Handle status updates
                if (!empty($value['statuses'])) {
                    foreach ($value['statuses'] as $status) {
                        $this->updateMessageStatus($status, $metadata);
                    }
                }
            }
        }
    }
    
    /**
     * Find contact by phone - handles international vs local format (254 vs 0)
     */
    private function findContactByPhone(string $normalizedPhone, ?int $workspaceId = null): ?array
    {
        $contact = Database::queryOne(
            "SELECT id, workspace_id FROM contacts 
             WHERE workspace_id = ?
               AND REPLACE(REPLACE(REPLACE(phone, '+', ''), ' ', ''), '-', '') = ?
             LIMIT 1",
            [$workspaceId, $normalizedPhone]
        );
        if ($contact) {
            return $contact;
        }
        // Try alternate format: 254737002242 <-> 0737002242 (Kenya and similar)
        if (strlen($normalizedPhone) === 12 && substr($normalizedPhone, 0, 3) === '254') {
            $localFormat = '0' . substr($normalizedPhone, 3);
            $contact = Database::queryOne(
                "SELECT id, workspace_id FROM contacts 
                 WHERE workspace_id = ?
                   AND REPLACE(REPLACE(REPLACE(phone, '+', ''), ' ', ''), '-', '') = ?
                 LIMIT 1",
                [$workspaceId, $localFormat]
            );
        } elseif (strlen($normalizedPhone) === 10 && $normalizedPhone[0] === '0') {
            $intlFormat = '254' . substr($normalizedPhone, 1);
            $contact = Database::queryOne(
                "SELECT id, workspace_id FROM contacts 
                 WHERE workspace_id = ?
                   AND REPLACE(REPLACE(REPLACE(phone, '+', ''), ' ', ''), '-', '') = ?
                 LIMIT 1",
                [$workspaceId, $intlFormat]
            );
        }
        return $contact ?: null;
    }
    
    /**
     * Extract message body from message based on type
     */
    private function extractMessageBody(array $message): string
    {
        $type = $message['type'] ?? 'text';
        switch ($type) {
            case 'text':
                return $message['text']['body'] ?? '';
            case 'contacts':
                return '[Shared Contact]';
            case 'image':
                return $message['image']['caption'] ?? '[Image]';
            case 'video':
                return $message['video']['caption'] ?? '[Video]';
            case 'audio':
                return '[Audio]';
            case 'document':
                return $message['document']['filename'] ?? $message['document']['caption'] ?? '[Document]';
            case 'interactive':
                $interactive = $message['interactive'] ?? [];
                if (isset($interactive['button_reply']['title'])) {
                    return $interactive['button_reply']['title'];
                }
                if (isset($interactive['list_reply']['title'])) {
                    return $interactive['list_reply']['title'];
                }
                return '[Interactive]';
            case 'reaction':
                return $this->buildReactionBody($message);
            case 'sticker':
                return '[Sticker]';
            case 'location':
                return '[Location]';
            default:
                return '[Unsupported message type]';
        }
    }

    /**
     * Extract media ID from message for image/video/audio/document/sticker (for later URL fetch)
     */
    private function extractMediaId(array $message): ?string
    {
        $type = $message['type'] ?? 'text';
        $mediaId = null;
        if (isset($message['image']['id'])) {
            $mediaId = $message['image']['id'];
        } elseif (isset($message['video']['id'])) {
            $mediaId = $message['video']['id'];
        } elseif (isset($message['audio']['id'])) {
            $mediaId = $message['audio']['id'];
        } elseif (isset($message['document']['id'])) {
            $mediaId = $message['document']['id'];
        } elseif (isset($message['sticker']['id'])) {
            $mediaId = $message['sticker']['id'];
        }
        return $mediaId ? (string) $mediaId : null;
    }

    private function buildReactionBody(array $message): string
    {
        $reaction = is_array($message['reaction'] ?? null) ? $message['reaction'] : [];
        $emoji = trim((string) ($reaction['emoji'] ?? ''));
        $targetMessageId = trim((string) ($reaction['message_id'] ?? ''));
        $prefix = $emoji !== '' ? ('Reaction: ' . $emoji) : '[Reaction]';

        if ($targetMessageId === '') {
            return $prefix;
        }

        $targetPreview = $this->findReactionTargetPreview($targetMessageId);
        if ($targetPreview === null || $targetPreview === '') {
            return $prefix;
        }

        return $prefix . ' to "' . $targetPreview . '"';
    }

    private function findReactionTargetPreview(string $targetMessageId, ?int $workspaceId = null): ?string
    {
        if ($targetMessageId === '') {
            return null;
        }

        $communicationParams = [$targetMessageId];
        $communicationSql = "SELECT body
             FROM communications
             WHERE channel = 'whatsapp'
               AND JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.whatsapp_message_id')) = ?";
        if ($workspaceId !== null && $workspaceId > 0) {
            $communicationSql .= " AND workspace_id = ?";
            $communicationParams[] = $workspaceId;
        }
        $communicationSql .= " ORDER BY id DESC LIMIT 1";
        $communication = Database::queryOne($communicationSql, $communicationParams);
        if ($communication) {
            return $this->normalizeReactionPreview((string) ($communication['body'] ?? ''));
        }

        $messageParams = [$targetMessageId];
        $messageSql = "SELECT message_body
             FROM whatsapp_messages
             WHERE whatsapp_message_id = ?";
        if ($workspaceId !== null && $workspaceId > 0) {
            $messageSql .= " AND workspace_id = ?";
            $messageParams[] = $workspaceId;
        }
        $messageSql .= " ORDER BY id DESC LIMIT 1";
        $whatsAppMessage = Database::queryOne($messageSql, $messageParams);
        if ($whatsAppMessage) {
            return $this->normalizeReactionPreview((string) ($whatsAppMessage['message_body'] ?? ''));
        }

        return null;
    }

    private function normalizeReactionPreview(string $body): string
    {
        $preview = trim(preg_replace('/\s+/', ' ', strip_tags($body)));
        if ($preview === '') {
            return '';
        }

        if (function_exists('mb_substr') && function_exists('mb_strlen')) {
            return mb_strlen($preview) > 72 ? (mb_substr($preview, 0, 72) . '...') : $preview;
        }

        return strlen($preview) > 72 ? (substr($preview, 0, 72) . '...') : $preview;
    }
    
    /**
     * Process incoming message
     */
    private function processIncomingMessage(array $message, array $contactInfo, ?string $auditLog = null, array $metadata = []): void
    {
        $lp = $auditLog ?? (dirname(__DIR__) . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'whatsapp_delivery_audit.log');
        $from = $message['from'] ?? '';
        $messageId = $message['id'] ?? '';
        $messageType = $message['type'] ?? 'text';
        $contactShareParser = new WhatsAppContactShareParser($this->contacts);
        $contactShare = $contactShareParser->parse($message);
        if ($contactShare) {
            $messageType = (string) ($contactShare['message_type'] ?? $messageType);
            $text = trim((string) ($contactShare['body'] ?? ''));
        } else {
            $text = $this->extractMessageBody($message);
        }
        $timestamp = $message['timestamp'] ?? time();

        $debugLog = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.cursor' . DIRECTORY_SEPARATOR . 'debug.log';
        $this->appendJsonLog($debugLog, ['id' => 'log_' . uniqid(), 'timestamp' => (int) (microtime(true) * 1000), 'location' => 'WhatsAppWebhook:processIncoming', 'message' => 'Processing message', 'data' => ['from' => $from, 'messageId_preview' => substr($messageId, 0, 24), 'type' => $messageType]]);
        $this->appendJsonLog($lp, ['location' => 'WhatsAppWebhook:processIncoming', 'message' => 'Processing message', 'data' => ['from' => $from, 'messageId' => substr($messageId, 0, 20), 'type' => $messageType], 'timestamp' => (int) (microtime(true) * 1000)]);

        $workspaceId = $this->resolveIncomingWorkspaceId($message, $contactInfo, $metadata);
        if ($workspaceId <= 0) {
            $this->appendJsonLog($lp, ['location' => 'WhatsAppWebhook:workspace_missing', 'message' => 'Skipping message with unresolved workspace', 'data' => ['messageId' => substr($messageId, 0, 20)], 'timestamp' => (int) (microtime(true) * 1000)]);
            error_log('WhatsApp webhook: unable to resolve workspace for inbound message.');
            return;
        }

        AsyncWorkspaceRunner::runWithWorkspace(
            $workspaceId,
            function () use ($contactInfo, $contactShare, $contactShareParser, $debugLog, $from, $lp, $message, $messageId, $messageType, $metadata, $text, $timestamp, $workspaceId): void {
            // Deduplicate complete deliveries. If an older partial write exists,
            // continue so the missing unified-inbox record can be repaired.
            $existingStoredMessage = null;
            if ($messageId !== '') {
                $existingStoredMessage = Database::queryOne(
                    'SELECT id, uuid, contact_id FROM whatsapp_messages WHERE workspace_id = ? AND direction = \'inbound\' AND whatsapp_message_id = ? LIMIT 1',
                    [$workspaceId, $messageId]
                );
                if ($existingStoredMessage) {
                    $existingCommunication = Database::queryOne(
                        "SELECT id FROM communications
                         WHERE workspace_id = ?
                           AND channel = 'whatsapp'
                           AND direction = 'inbound'
                           AND JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.whatsapp_uuid')) = ?
                         LIMIT 1",
                        [$workspaceId, (string) ($existingStoredMessage['uuid'] ?? '')]
                    );
                    if ($existingCommunication) {
                        $this->appendJsonLog($debugLog, ['id' => 'log_' . uniqid(), 'timestamp' => (int) (microtime(true) * 1000), 'location' => 'WhatsAppWebhook:dedupe_skipped', 'message' => 'Message already stored', 'data' => ['messageId_preview' => substr($messageId, 0, 24)]]);
                        return;
                    }
                }
            }

            // Normalize phone number for matching (remove all non-digits)
            $normalizedFrom = preg_replace('/[^\d]/', '', $from);
            
            // Find contact - try direct match, then international vs local format (e.g. 254737002242 vs 0737002242)
            $contact = $this->findContactByPhone($normalizedFrom, $workspaceId);
            
            if (!$contact) {
                $contactName = $contactInfo['profile']['name'] ?? 'WhatsApp User';
                $placeholderEmail = 'whatsapp_' . $normalizedFrom . '@whatsapp.local';
                
                $result = $this->contacts->create([
                    'first_name' => $contactName,
                    'email' => $placeholderEmail,
                    'phone' => $from,
                    'lead_source' => 'whatsapp'
                ]);
                
                if ($result['status'] === 'success') {
                    $contactId = $result['id'];
                } elseif ($result['status'] === 'duplicate' && !empty($result['matches'])) {
                    // Use existing contact when duplicate (e.g. same placeholder email from previous message)
                    $matches = $result['matches'];
                    $existing = $matches['email'][0] ?? $matches['phone'][0] ?? null;
                    $contactId = $existing ? (int) $existing['id'] : null;
                    if (!$contactId) {
                        error_log("WhatsApp webhook: Duplicate contact but no match ID");
                        return;
                    }
                } else {
                    $this->appendJsonLog($debugLog, ['id' => 'log_' . uniqid(), 'timestamp' => (int) (microtime(true) * 1000), 'location' => 'WhatsAppWebhook:contact_create_failed', 'message' => 'Contact create failed', 'data' => ['error' => $result['error'] ?? 'Unknown']]);
                    $this->appendJsonLog($lp, ['location' => 'WhatsAppWebhook:contact_create_failed', 'message' => 'Contact create failed', 'data' => ['error' => $result['error'] ?? 'Unknown'], 'timestamp' => (int) (microtime(true) * 1000)]);
                    error_log("WhatsApp webhook: Failed to create contact: " . ($result['error'] ?? 'Unknown error'));
                    return;
                }
            } else {
                $contactId = $contact['id'];
            }

        $sharedContactResult = null;
        if ($contactShare) {
            try {
                $sharedContactResult = $contactShareParser->resolveOrCreateContact($contactShare, (int) $contactId);
                $sharedContactId = (int) ($sharedContactResult['contact_id'] ?? 0);
                if ($sharedContactId > 0) {
                    $this->activities->log(
                        $sharedContactId,
                        'note',
                        $contactShareParser->buildSharedContactActivityDescription($contactShare, (string) $from),
                        [
                            'source' => 'whatsapp_contact_share',
                            'sender_contact_id' => (int) $contactId,
                            'shared_contact_created' => !empty($sharedContactResult['created']),
                            'detection_source' => (string) ($contactShare['detection_source'] ?? ''),
                        ]
                    );
                }
            } catch (\Throwable $e) {
                error_log('WhatsApp webhook: shared contact resolution failed: ' . $e->getMessage());
            }
        }

        $this->appendJsonLog($debugLog, ['id' => 'log_' . uniqid(), 'timestamp' => (int) (microtime(true) * 1000), 'location' => 'WhatsAppWebhook:contact_resolved', 'message' => 'Contact ready', 'data' => ['contactId' => $contactId]]);
        $this->appendJsonLog($lp, ['location' => 'WhatsAppWebhook:contact_resolved', 'message' => 'Contact ready', 'data' => ['contactId' => $contactId], 'timestamp' => (int) (microtime(true) * 1000)]);

        try {
            (new WhatsAppComplianceService())->detectAndApplyInboundOptOut($workspaceId, (int) $contactId, (string) $text);
        } catch (\Throwable $e) {
            error_log('WhatsApp webhook: opt-out detection failed: ' . $e->getMessage());
        }

        // Store message in whatsapp_messages table
        $uuid = trim((string) ($existingStoredMessage['uuid'] ?? '')) ?: $this->generateUuid();
        $businessPhoneNumberId = trim((string) ($metadata['phone_number_id'] ?? ''));
        $businessDisplayNumber = trim((string) ($metadata['display_phone_number'] ?? ''));
        // Also add to unified inbox (communications table)
        $commUuid = $this->generateUuid();
        $commId = 0;
        $bodyForInbox = (is_string($text) && trim($text) !== '') ? $text : '[Media]';
        $timestampForDb = (is_numeric($timestamp) && (int) $timestamp >= 0 && (int) $timestamp <= 2147483647)
            ? (int) $timestamp
            : time();
        $mediaId = $this->extractMediaId($message);
        $commMetadata = [
            'whatsapp_message_id' => $messageId,
            'whatsapp_uuid' => $uuid,
            'message_type' => $messageType,
            'from_number' => $from,
            'business_phone_number_id' => $businessPhoneNumberId,
            'business_display_phone_number' => $businessDisplayNumber,
        ];
        if ($contactShare) {
            $contactPayload = $contactShare['contact'] ?? [];
            if (!is_array($contactPayload)) {
                $contactPayload = [];
            }
            $commMetadata['contact_share'] = [
                'detected' => true,
                'detection_source' => (string) ($contactShare['detection_source'] ?? ''),
                'confidence' => (string) ($contactShare['confidence'] ?? ''),
                'linked_contact_id' => (int) ($sharedContactResult['contact_id'] ?? 0),
                'was_created' => !empty($sharedContactResult['created']),
                'summary' => (string) ($contactShare['body'] ?? ''),
                'contact' => [
                    'display_name' => (string) ($contactPayload['display_name'] ?? $contactPayload['full_name'] ?? ''),
                    'first_name' => (string) ($contactPayload['first_name'] ?? ''),
                    'last_name' => (string) ($contactPayload['last_name'] ?? ''),
                    'phone' => (string) ($contactPayload['phone'] ?? ''),
                    'email' => (string) ($contactPayload['email'] ?? ''),
                    'phones' => array_values(array_slice((array) ($contactPayload['phones'] ?? []), 0, 5)),
                    'emails' => array_values(array_slice((array) ($contactPayload['emails'] ?? []), 0, 5)),
                    'company' => (string) ($contactPayload['company'] ?? ''),
                    'job_title' => (string) ($contactPayload['job_title'] ?? ''),
                    'raw_payload' => $contactPayload['raw_payload'] ?? null,
                ],
            ];
        }
        if ($messageType === 'reaction') {
            $reaction = is_array($message['reaction'] ?? null) ? $message['reaction'] : [];
            $reactionEmoji = trim((string) ($reaction['emoji'] ?? ''));
            $reactionTargetId = trim((string) ($reaction['message_id'] ?? ''));
            if ($reactionEmoji !== '') {
                $commMetadata['reaction_emoji'] = $reactionEmoji;
            }
            if ($reactionTargetId !== '') {
                $commMetadata['reaction_target_message_id'] = $reactionTargetId;
                $reactionTargetPreview = $this->findReactionTargetPreview($reactionTargetId, $workspaceId);
                if ($reactionTargetPreview !== null && $reactionTargetPreview !== '') {
                    $commMetadata['reaction_target_preview'] = $reactionTargetPreview;
                }
            }
        }
        if ($mediaId !== null) {
            $commMetadata['media_id'] = $mediaId;
            if ($messageType === 'image' && !empty($message['image']['mime_type'])) {
                $commMetadata['mime_type'] = $message['image']['mime_type'];
            } elseif ($messageType === 'video' && !empty($message['video']['mime_type'])) {
                $commMetadata['mime_type'] = $message['video']['mime_type'];
            } elseif ($messageType === 'document' && !empty($message['document']['mime_type'])) {
                $commMetadata['mime_type'] = $message['document']['mime_type'];
            }
        }
        if (
            $messageType !== 'reaction'
            && strlen($text) > 10
            && !in_array($bodyForInbox, ['[Media]', '[Image]', '[Video]', '[Audio]', '[Document]', '[Sticker]', '[Interactive]', '[Reaction]', '[Location]'], true)
        ) {
            try {
                $sentimentAnalysis = new SentimentAnalysis();
                $commMetadata['sentiment'] = $sentimentAnalysis->analyze($text, [
                    'fast_fallback' => true,
                ]);
            } catch (\Throwable $e) {
                error_log("WhatsApp webhook: Sentiment analysis failed: " . $e->getMessage());
                $commMetadata['sentiment'] = [
                    'sentiment' => 'neutral',
                    'confidence' => 0.0,
                    'provider' => 'fallback',
                ];
            }
            try {
                $intentDetection = new IntentDetection();
                $commMetadata['intent'] = $intentDetection->detect($text, [
                    'fast_fallback' => true,
                ]);
            } catch (\Throwable $e) {
                error_log("WhatsApp webhook: Intent detection failed: " . $e->getMessage());
                $commMetadata['intent'] = [
                    'intent' => 'unknown',
                    'confidence' => 0.0,
                    'provider' => 'fallback',
                ];
            }
        }
        $metadataJson = json_encode($commMetadata);
        if ($metadataJson === false || strlen($metadataJson) > 65000) {
            $commMetadata = array_intersect_key($commMetadata, array_flip([
                'whatsapp_message_id',
                'whatsapp_uuid',
                'message_type',
                'from_number',
                'media_id',
                'reaction_emoji',
                'reaction_target_message_id',
                'reaction_target_preview',
            ]));
            $metadataJson = json_encode($commMetadata);
        }
        if ($metadataJson === false) {
            $metadataJson = '{}';
        }
        Database::beginTransaction();
        try {
            if ($existingStoredMessage) {
                Database::queryOne(
                    "SELECT id FROM whatsapp_messages WHERE workspace_id = ? AND id = ? FOR UPDATE",
                    [$workspaceId, (int) ($existingStoredMessage['id'] ?? 0)]
                );
                $alreadyRepaired = Database::queryOne(
                    "SELECT id FROM communications
                     WHERE workspace_id = ?
                       AND channel = 'whatsapp'
                       AND direction = 'inbound'
                       AND JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.whatsapp_uuid')) = ?
                     LIMIT 1",
                    [$workspaceId, $uuid]
                );
                if ($alreadyRepaired) {
                    Database::commit();
                    return;
                }
            } else {
                Database::execute(
                    "INSERT INTO whatsapp_messages (workspace_id, uuid, contact_id, to_number, from_number, message_type, message_body, whatsapp_message_id, status, direction, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'delivered', 'inbound', FROM_UNIXTIME(?))",
                    [$workspaceId, $uuid, $contactId, $businessDisplayNumber, $from, $messageType, $text, $messageId, $timestamp]
                );
            }

            Database::execute(
                "INSERT INTO communications (workspace_id, uuid, contact_id, channel, direction, subject, body, status, metadata, created_at) 
                 VALUES (?, ?, ?, 'whatsapp', 'inbound', 'WhatsApp Message', ?, 'delivered', ?, FROM_UNIXTIME(?))",
                [
                    $workspaceId,
                    $commUuid,
                    $contactId,
                    $bodyForInbox,
                    $metadataJson,
                    $timestampForDb
                ]
            );
            $commId = (int) Database::lastInsertId();
            Database::commit();
        } catch (\Throwable $e) {
            if (Database::getInstance()->inTransaction()) {
                Database::rollBack();
            }
            $this->appendJsonLog($lp, ['location' => 'WhatsAppWebhook:insert_comm_failed', 'message' => 'communications insert failed', 'data' => ['error' => $e->getMessage()], 'timestamp' => (int) (microtime(true) * 1000)]);
            error_log("Error adding WhatsApp message to communications: " . $e->getMessage());
            $driverCode = $e instanceof \PDOException ? (int) ($e->errorInfo[1] ?? 0) : 0;
            if ($driverCode === 1062 && $messageId !== '') {
                $winner = Database::queryOne(
                    "SELECT uuid FROM whatsapp_messages
                     WHERE workspace_id = ?
                       AND direction = 'inbound'
                       AND whatsapp_message_id = ?
                     LIMIT 1",
                    [$workspaceId, $messageId]
                );
                if ($winner) {
                    $winnerCommunication = Database::queryOne(
                        "SELECT id FROM communications
                         WHERE workspace_id = ?
                           AND channel = 'whatsapp'
                           AND direction = 'inbound'
                           AND JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.whatsapp_uuid')) = ?
                         LIMIT 1",
                        [$workspaceId, (string) ($winner['uuid'] ?? '')]
                    );
                    if ($winnerCommunication) {
                        return;
                    }
                }
            }
            throw $e;
        }

        $this->appendJsonLog($debugLog, ['id' => 'log_' . uniqid(), 'timestamp' => (int) (microtime(true) * 1000), 'location' => 'WhatsAppWebhook:insert_whatsapp_ok', 'message' => 'whatsapp message and communication stored', 'data' => ['messageId_preview' => substr($messageId, 0, 24)]]);
        $this->appendJsonLog($lp, ['location' => 'WhatsAppWebhook:insert_whatsapp_ok', 'message' => 'whatsapp message and communication stored', 'data' => ['messageId' => substr($messageId, 0, 20)], 'timestamp' => (int) (microtime(true) * 1000)]);

        $this->appendJsonLog($lp, ['location' => 'WhatsAppWebhook:flow_complete', 'message' => 'Message fully processed', 'data' => ['contactId' => $contactId], 'timestamp' => (int) (microtime(true) * 1000)]);

        // Create/update conversation thread for inbox
        try {
            $threads = new ConversationThreads();
            $threads->updateThread($contactId, 'whatsapp', $commId > 0 ? $commId : null);
        } catch (\Exception $e) {
            error_log("Error updating conversation thread: " . $e->getMessage());
        }
        
        // Log activity
        $this->activities->log($contactId, 'note', "WhatsApp message received: $text");

        // Create in-app notifications only when message is in inbox (so link opens the actual message)
        if ($commId > 0) {
            try {
                $contact = Database::queryOne("SELECT first_name, last_name FROM contacts WHERE workspace_id = ? AND id = ?", [$workspaceId, $contactId]);
                $contactName = trim(($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? '')) ?: ($contactInfo['profile']['name'] ?? 'WhatsApp User');
                $messagePreview = strlen($text) > 80 ? substr($text, 0, 77) . '...' : $text;
                $notificationMessage = $contactName . ': ' . $messagePreview;

                $prefs = new NotificationPreferences();
                $notifications = new Notifications();
                foreach ($notifications->resolveContactAudienceUserIds((int) $contactId) as $userId) {
                    if ($prefs->isInAppEnabled($userId, 'whatsapp_message_received')) {
                        $notifications->create(
                            $userId,
                            'whatsapp_message_received',
                            'New WhatsApp Message',
                            $notificationMessage,
                            [
                                'entity_type' => 'contact',
                                'entity_id' => $contactId,
                                'link' => publicUrl('conversation.php?id=' . $commId)
                            ]
                        );
                    }
                }
            } catch (\Exception $e) {
                error_log("WhatsApp webhook: Failed to create notifications: " . $e->getMessage());
            }
        }

        // Publish event for workflow triggers (include sentiment/intent for workflow conditions)
        $eventData = [
            'contact_id' => $contactId,
            'communication_id' => $commId,
            'channel' => 'whatsapp',
            'direction' => 'inbound',
            'message_text' => $text,
            'message' => $text,
            'message_type' => $messageType,
            'from_number' => $from,
        ];
        if (!empty($commMetadata['sentiment'])) {
            $eventData['sentiment'] = $commMetadata['sentiment'];
        }
        if (!empty($commMetadata['intent'])) {
            $eventData['intent'] = $commMetadata['intent'];
        }
        EventBus::publish('whatsapp.message_received', $eventData);

        if ($commId > 0) {
            try {
                $queueService = new AIAutoResponderQueueService();
                $queueService->enqueueInboundCommunication(
                    $commId,
                    (int) $contactId,
                    'whatsapp',
                    (string) $text,
                    [
                        'whatsapp_message_id' => (string) $messageId,
                        'from_number' => (string) $from,
                        'message_type' => (string) $messageType,
                    ]
                );
            } catch (\Throwable $e) {
                error_log("WhatsApp webhook: Failed to enqueue AI auto-responder item: " . $e->getMessage());
            }
        }

        if ($commId > 0) {
            try {
                $triage = new InboxTriageService();
                $triage->processCommunication($commId);
            } catch (\Throwable $e) {
                error_log("WhatsApp webhook: Inbox triage failed: " . $e->getMessage());
            }
        }

        try {
            (new ContactIntelligenceService())->computeAndPersist((int) $contactId);
        } catch (\Throwable $e) {
            error_log("WhatsApp webhook: Contact intelligence refresh failed: " . $e->getMessage());
        }
        try {
            (new TargetIntelligenceService())->refreshAfterEntityChange('communications', $commId, [
                'contact_id' => (int) $contactId,
                'channel' => 'whatsapp',
            ]);
        } catch (\Throwable $e) {
            error_log("WhatsApp webhook: Target intelligence refresh failed: " . $e->getMessage());
        }

        if ($commId > 0) {
            try {
                $outcomes = new OutcomeEventService();
                $outcomes->track('channel.connected', [
                    'user_id' => (int) ($_SESSION['user_id'] ?? 0),
                    'contact_id' => (int) $contactId,
                    'event_source' => 'whatsapp_webhook',
                    'metadata' => ['channel' => 'whatsapp'],
                ]);
                $outcomes->track('inbound.processed', [
                    'user_id' => (int) ($_SESSION['user_id'] ?? 0),
                    'contact_id' => (int) $contactId,
                    'event_source' => 'whatsapp_webhook',
                    'metadata' => ['channel' => 'whatsapp', 'communication_id' => $commId],
                ]);
            } catch (\Throwable $e) {
                error_log("WhatsApp webhook: Outcome tracking failed: " . $e->getMessage());
            }
        }

        // Deal automation: run on communication ingested
        if ($commId > 0) {
            try {
                $orchestrator = new \CRM\Services\DealAutomationOrchestrator();
                $orchestrator->runForCommunication($contactId, $commId, 'communication');
            } catch (\Throwable $e) {
                error_log("Deal automation (whatsapp): " . $e->getMessage());
            }
            }
            },
            null,
            'WhatsApp inbound message is missing a valid workspace.'
        );
    }
    
    /**
     * Update message status
     */
    private function updateMessageStatus(array $status, array $metadata = []): void
    {
        $messageId = $status['id'] ?? '';
        $statusValue = $status['status'] ?? '';
        $timestamp = $status['timestamp'] ?? time();
        $messageSql =
            "SELECT workspace_id, uuid, contact_id
             FROM whatsapp_messages
             WHERE whatsapp_message_id = ?";
        $messageParams = [$messageId];
        if ($this->workspaceIdHint !== null && $this->workspaceIdHint > 0) {
            $messageSql .= " AND workspace_id = ?";
            $messageParams[] = $this->workspaceIdHint;
        }
        $messageSql .= " ORDER BY id DESC LIMIT 1";
        $whatsappMsg = Database::queryOne($messageSql, $messageParams);
        $workspaceId = is_array($whatsappMsg)
            ? (int) ($whatsappMsg['workspace_id'] ?? 0)
            : (int) ($this->workspaceIdHint ?? 0);
        if ($workspaceId <= 0) {
            return;
        }
        
        $dbStatus = match($statusValue) {
            'sent' => 'sent',
            'delivered' => 'delivered',
            'read' => 'read',
            'failed' => 'failed',
            default => 'pending'
        };

        AsyncWorkspaceRunner::runWithWorkspace(
            $workspaceId,
            function () use ($dbStatus, $messageId, $metadata, $status, $statusValue, $timestamp, $whatsappMsg, $workspaceId): void {
                // Update whatsapp_messages table
                $updateFields = ['status = ?'];
                $params = [$dbStatus, $messageId];
                
                if ($statusValue === 'sent') {
                    $updateFields[] = 'sent_at = FROM_UNIXTIME(?)';
                    $params[] = $timestamp;
                } elseif ($statusValue === 'delivered') {
                    $updateFields[] = 'delivered_at = FROM_UNIXTIME(?)';
                    $params[] = $timestamp;
                } elseif ($statusValue === 'read') {
                    $updateFields[] = 'read_at = FROM_UNIXTIME(?)';
                    $params[] = $timestamp;
                }
                
                if ($statusValue === 'failed') {
                    $updateFields[] = 'error_message = ?';
                    $params[] = substr((string) ($status['errors'][0]['message'] ?? 'Unknown error'), 0, 500);
                }
                
                $sql = "UPDATE whatsapp_messages SET " . implode(', ', $updateFields) . " WHERE whatsapp_message_id = ? AND workspace_id = ?";
                $params[] = $workspaceId;
                Database::execute($sql, $params);

                try {
                    (new WorkspaceWhatsAppCreditService())->settleDeliveredWebhook($status, $workspaceId);
                } catch (\Throwable $e) {
                    error_log('WhatsApp webhook: managed credit reconciliation failed: ' . $e->getMessage());
                }

                try {
                    $assistantStatusSql = "UPDATE whatsapp_assistant_messages
                         SET status = ?
                         WHERE whatsapp_message_id = ?";
                    $assistantStatusParams = [$dbStatus, $messageId];
                    if (Database::columnExists('whatsapp_assistant_messages', 'workspace_id')) {
                        $assistantStatusSql .= " AND workspace_id = ?";
                        $assistantStatusParams[] = $workspaceId;
                    }
                    Database::execute($assistantStatusSql, $assistantStatusParams);
                } catch (\Throwable $e) {
                    error_log("Error updating assistant WhatsApp status: " . $e->getMessage());
                }
                
                // Also update communications table if message exists there; log activity when customer reads
                try {
                    if ($whatsappMsg) {
                        // Primary: match by whatsapp_uuid in metadata
                        $comm = Database::queryOne(
                            "SELECT id, contact_id, metadata FROM communications 
                             WHERE channel = 'whatsapp' AND direction = 'outbound' 
                             AND workspace_id = ?
                             AND JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.whatsapp_uuid')) = ?",
                            [$workspaceId, $whatsappMsg['uuid']]
                        );
                        // Fallback: match by whatsapp_message_id in metadata (for rows missing whatsapp_uuid)
                        if (!$comm) {
                            $comm = Database::queryOne(
                                "SELECT id, contact_id, metadata FROM communications 
                                 WHERE channel = 'whatsapp' AND direction = 'outbound' 
                                 AND workspace_id = ?
                                 AND JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.whatsapp_message_id')) = ?",
                                [$workspaceId, $messageId]
                            );
                            if ($comm) {
                                $commMeta = is_string($comm['metadata'] ?? '') ? json_decode($comm['metadata'], true) : ($comm['metadata'] ?? []);
                                $commMeta = is_array($commMeta) ? $commMeta : [];
                                if (empty($commMeta['whatsapp_uuid'])) {
                                    $commMeta['whatsapp_uuid'] = $whatsappMsg['uuid'];
                                    $comm['metadata'] = json_encode($commMeta);
                                }
                            }
                        }
                        
                        if ($comm) {
                            $commUpdateFields = ['status = ?'];
                            $commParams = [$dbStatus];
                            
                            $tsFormatted = date('Y-m-d H:i:s', is_numeric($timestamp) ? (int) $timestamp : strtotime($timestamp));
                            if ($statusValue === 'read') {
                                $commUpdateFields[] = 'read_at = FROM_UNIXTIME(?)';
                                $commParams[] = $timestamp;
                            }
                            
                            // Merge status timestamps into metadata for display (sent, delivered)
                            $meta = is_string($comm['metadata'] ?? '') ? json_decode($comm['metadata'], true) : ($comm['metadata'] ?? []);
                            $meta = is_array($meta) ? $meta : [];
                            if ($statusValue === 'sent') {
                                $meta['whatsapp_sent_at'] = $tsFormatted;
                            } elseif ($statusValue === 'delivered') {
                                $meta['whatsapp_delivered_at'] = $tsFormatted;
                            } elseif ($statusValue === 'failed') {
                                $meta['whatsapp_error'] = substr((string) ($status['errors'][0]['message'] ?? 'Unknown error'), 0, 500);
                            }
                            $metaJson = json_encode($meta);
                            if ($metaJson !== false && strlen($metaJson) <= 65000) {
                                $commUpdateFields[] = 'metadata = ?';
                                $commParams[] = $metaJson;
                            }
                            
                            $commParams[] = $comm['id'];
                            $commSql = "UPDATE communications SET " . implode(', ', $commUpdateFields) . " WHERE id = ? AND workspace_id = ?";
                            $commParams[] = $workspaceId;
                            Database::execute($commSql, $commParams);

                            // Track customer read as CRM event
                            if ($statusValue === 'read') {
                                $contactId = (int) ($comm['contact_id'] ?? $whatsappMsg['contact_id'] ?? 0);
                                if ($contactId > 0) {
                                    $this->activities->log($contactId, 'whatsapp_message_read', 'WhatsApp message read by contact', [
                                        'whatsapp_message_id' => $messageId,
                                        'communication_id' => (int) $comm['id'],
                                        'read_at' => $tsFormatted
                                    ]);
                                }
                            }
                        }
                    }
                } catch (\Exception $e) {
                    error_log("Error updating communications status: " . $e->getMessage());
                }
            },
            null,
            'WhatsApp status update is missing a valid workspace.'
        );
    }

    private function resolveIncomingWorkspaceId(array $message, array $contactInfo = [], array $metadata = []): int
    {
        $metadataWorkspaceId = $this->resolveWorkspaceIdFromWebhookMetadata($metadata);
        if ($this->workspaceIdHint !== null && $this->workspaceIdHint > 0) {
            if ($metadataWorkspaceId > 0 && $metadataWorkspaceId !== $this->workspaceIdHint) {
                error_log('WhatsApp webhook: workspace URL token does not match payload phone number metadata.');
                return 0;
            }

            if (!$this->webhookMetadataMatchesWorkspace($this->workspaceIdHint, $metadata)) {
                error_log('WhatsApp webhook: payload metadata is not linked to the workspace callback URL.');
                return 0;
            }

            return $this->workspaceIdHint;
        }

        if ($metadataWorkspaceId > 0) {
            return $metadataWorkspaceId;
        }

        $reactionTargetId = trim((string) ($message['reaction']['message_id'] ?? ''));
        if ($reactionTargetId !== '') {
            $workspaceId = $this->findWorkspaceIdForExternalMessage($reactionTargetId);
            if ($workspaceId > 0) {
                return $workspaceId;
            }
        }

        $from = preg_replace('/[^\d]/', '', (string) ($message['from'] ?? ''));
        if ($from !== '') {
            $matches = Database::query(
                "SELECT DISTINCT workspace_id
                 FROM contacts
                 WHERE REPLACE(REPLACE(REPLACE(phone, '+', ''), ' ', ''), '-', '') IN (?, ?, ?)
                 ORDER BY workspace_id ASC
                 LIMIT 2",
                [
                    $from,
                    strlen($from) === 12 && substr($from, 0, 3) === '254' ? ('0' . substr($from, 3)) : $from,
                    strlen($from) === 10 && $from[0] === '0' ? ('254' . substr($from, 1)) : $from,
                ]
            );
            if (count($matches) === 1) {
                return (int) ($matches[0]['workspace_id'] ?? 0);
            }
        }

        $workspaces = Database::query(
            "SELECT id
             FROM workspaces
             WHERE status = 'active'
             ORDER BY id ASC
             LIMIT 2"
        );

        return count($workspaces) === 1 ? (int) ($workspaces[0]['id'] ?? 0) : 0;
    }

    private function resolveWorkspaceIdFromWebhookMetadata(array $metadata): int
    {
        try {
            $integration = (new WorkspaceConnectService())->getWhatsAppIntegrationByWebhookMetadata($metadata);
        } catch (\Throwable $e) {
            $integration = null;
        }

        return (int) ($integration['workspace_id'] ?? 0);
    }

    private function webhookMetadataMatchesWorkspace(int $workspaceId, array $metadata): bool
    {
        $phoneNumberId = preg_replace('/\s+/', '', trim((string) ($metadata['phone_number_id'] ?? ''))) ?? '';
        $wabaId = preg_replace('/\s+/', '', trim((string) (
            $metadata['whatsapp_business_account_id']
            ?? $metadata['business_account_id']
            ?? $metadata['waba_id']
            ?? ''
        ))) ?? '';

        if ($phoneNumberId === '' && $wabaId === '') {
            return true;
        }

        try {
            $integration = (new WorkspaceConnectService())->getActiveWhatsAppIntegration($workspaceId);
        } catch (\Throwable $e) {
            $integration = null;
        }

        if (!$integration) {
            return false;
        }

        $savedPhoneNumberId = preg_replace('/\s+/', '', trim((string) ($integration['phone_number_id'] ?? ''))) ?? '';
        $savedWabaId = preg_replace('/\s+/', '', trim((string) ($integration['whatsapp_business_account_id'] ?? ''))) ?? '';

        if ($phoneNumberId !== '') {
            return $savedPhoneNumberId !== '' && $phoneNumberId === $savedPhoneNumberId;
        }

        if ($wabaId !== '') {
            return $savedWabaId !== '' && $wabaId === $savedWabaId;
        }

        return false;
    }

    private function findWorkspaceIdForExternalMessage(string $messageId): int
    {
        $communication = Database::queryOne(
            "SELECT workspace_id
             FROM communications
             WHERE channel = 'whatsapp'
               AND JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.whatsapp_message_id')) = ?
             ORDER BY id DESC
             LIMIT 1",
            [$messageId]
        );
        $workspaceId = (int) ($communication['workspace_id'] ?? 0);
        if ($workspaceId > 0) {
            return $workspaceId;
        }

        $whatsappMessage = Database::queryOne(
            "SELECT workspace_id
             FROM whatsapp_messages
             WHERE whatsapp_message_id = ?
             ORDER BY id DESC
             LIMIT 1",
            [$messageId]
        );

        return (int) ($whatsappMessage['workspace_id'] ?? 0);
    }
    
    /**
     * Generate UUID
     */
    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
