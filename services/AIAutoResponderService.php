<?php
/**
 * AI Auto-responder Service
 * End-to-end processing for queued inbound communications.
 */

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\AIAutoResponderConfig;
use CRM\Modules\CompanyProfile;
use CRM\Services\AsyncWorkspaceRunner;
use CRM\Services\WorkspaceContext;

class AIAutoResponderService
{
    private AIAutoResponderConfig $configModule;
    private AIAutoResponderPolicy $policy;
    private AIAutoResponderDispatcher $dispatcher;
    private AIService $aiService;
    private CustomerReplyAssistantService $replyAssistantService;
    private CompanyProfile $companyProfile;

    public function __construct()
    {
        $this->configModule = new AIAutoResponderConfig();
        $this->policy = new AIAutoResponderPolicy();
        $this->dispatcher = new AIAutoResponderDispatcher();
        $this->aiService = new AIService();
        $this->replyAssistantService = new CustomerReplyAssistantService();
        $this->companyProfile = new CompanyProfile();
    }

    public function processQueueItem(array $queueItem): array
    {
        $started = microtime(true);
        $queueId = (int) ($queueItem['id'] ?? 0);
        $workspaceId = $this->resolveWorkspaceId($queueItem, false);

        return AsyncWorkspaceRunner::runWithWorkspace(
            $workspaceId,
            function () use ($queueId, $workspaceId, $queueItem, $started): array {
                $contactId = (int) ($queueItem['contact_id'] ?? 0);
                $communicationId = (int) ($queueItem['communication_id'] ?? 0);
                $channel = strtolower((string) ($queueItem['channel'] ?? 'email'));
                $messageText = trim((string) ($queueItem['message_text'] ?? ''));
                $payload = json_decode((string) ($queueItem['normalized_payload'] ?? '{}'), true);
                if (!is_array($payload)) {
                    $payload = [];
                }

                $config = $this->configModule->get($workspaceId);
                if (empty($config['enabled']) || ($config['mode'] ?? 'off') === 'off') {
                    $logId = $this->logDecision($workspaceId, $queueId, $communicationId, $contactId, $channel, [
                        'decision' => 'skipped',
                        'status' => 'skipped',
                        'reason_code' => 'feature_disabled',
                        'reason_notes' => 'AI auto-responder is disabled.',
                        'request_payload' => $payload,
                        'latency_ms' => $this->latencyMs($started),
                    ]);
                    return ['action' => 'skipped', 'log_id' => $logId, 'reason' => 'feature_disabled'];
                }

                $policyCheck = $this->policy->evaluateInbound(array_merge($payload, [
                    'message_text' => $messageText,
                    'channel' => $channel,
                ]), $config);
                if (($policyCheck['decision'] ?? 'continue') !== 'continue') {
                    $decision = ($policyCheck['decision'] === 'blocked') ? 'blocked' : 'draft';
                    $status = ($policyCheck['decision'] === 'blocked') ? 'blocked' : 'pending_review';
                    $logId = $this->logDecision($workspaceId, $queueId, $communicationId, $contactId, $channel, [
                        'decision' => $decision,
                        'status' => $status,
                        'reason_code' => (string) ($policyCheck['reason_code'] ?? 'policy'),
                        'reason_notes' => (string) ($policyCheck['notes'] ?? 'Policy decision'),
                        'reply_text' => null,
                        'request_payload' => $payload,
                        'latency_ms' => $this->latencyMs($started),
                    ]);
                    return ['action' => $policyCheck['decision'], 'log_id' => $logId, 'reason' => $policyCheck['reason_code'] ?? 'policy'];
                }

                if (!$this->policy->enforceDailyRateLimit($contactId, $config)) {
                    $logId = $this->logDecision($workspaceId, $queueId, $communicationId, $contactId, $channel, [
                        'decision' => 'blocked',
                        'status' => 'blocked',
                        'reason_code' => 'rate_limited',
                        'reason_notes' => 'Max auto-replies per contact/day exceeded.',
                        'request_payload' => $payload,
                        'latency_ms' => $this->latencyMs($started),
                    ]);
                    return ['action' => 'blocked', 'log_id' => $logId, 'reason' => 'rate_limited'];
                }

                $context = $this->buildContext($workspaceId, $contactId, $communicationId, $channel, $messageText);
                $threadState = (array) ($context['conversation_thread'] ?? []);
                $threadControls = (array) ($threadState['reply_controls'] ?? []);
                $threadSuppressionReason = $this->getThreadSuppressionReason($threadState, $threadControls);
                if ($threadSuppressionReason !== null) {
                    $logId = $this->logDecision($workspaceId, $queueId, $communicationId, $contactId, $channel, [
                        'decision' => 'draft',
                        'status' => 'pending_review',
                        'reason_code' => $threadSuppressionReason,
                        'reason_notes' => 'Conversation thread policy requires supervised handling.',
                        'request_payload' => $payload,
                        'response_payload' => ['thread_state' => $threadState],
                        'latency_ms' => $this->latencyMs($started),
                    ]);
                    return ['action' => 'draft', 'log_id' => $logId, 'reason' => $threadSuppressionReason];
                }
                $aiTask = match ($channel) {
                    'whatsapp' => 'whatsapp_auto_reply',
                    'sms' => 'sms_auto_reply',
                    default => 'email_auto_reply',
                };

                $aiRaw = $this->aiService->process($aiTask, [
                    'context' => $context,
                    'incoming_message' => $messageText,
                    'channel' => $channel,
                    'options' => [
                        'max_chars' => (int) ($config['channels'][$channel]['max_chars'] ?? 700),
                        'forbid_hallucinations' => (bool) ($config['safety']['forbid_hallucinations'] ?? true),
                        'draft_style_instructions' => (string) (($context['draft_style']['instruction_text'] ?? '')),
                    ],
                ]);
                $aiData = $this->parseAiResponse($aiRaw, $channel);
                if ($channel === 'email') {
                    $formattedDraft = $this->replyAssistantService->formatDraftForChannel([
                        'subject' => (string) ($aiData['subject'] ?? ''),
                        'plain_body' => (string) ($aiData['reply_text'] ?? ''),
                        'html_body' => (string) ($aiData['body_html'] ?? ''),
                    ], (array) ($context['draft_style'] ?? []), 'email', [
                        'channel' => 'email',
                        'contact' => (array) ($context['contact'] ?? []),
                        'communication' => (array) (Database::queryOne(
                            "SELECT *
                             FROM communications
                             WHERE workspace_id = ?
                               AND id = ?",
                            [$workspaceId, $communicationId]
                        ) ?: []),
                    ]);

                    $aiData['subject'] = (string) ($formattedDraft['subject'] ?? $aiData['subject'] ?? '');
                    $aiData['reply_text'] = (string) ($formattedDraft['plain_body'] ?? $aiData['reply_text'] ?? '');
                    $aiData['body_html'] = (string) ($formattedDraft['html_body'] ?? $aiData['body_html'] ?? '');
                    $aiData['signature_id'] = $formattedDraft['signature_id'] ?? null;
                    $aiData['signature_html'] = $formattedDraft['signature_html'] ?? '';
                    $aiData['signature_text'] = $formattedDraft['signature_text'] ?? '';
                }
                $confidence = (float) ($aiData['confidence'] ?? 0.0);
                $threshold = (float) ($config['channels'][$channel]['confidence_threshold'] ?? $config['default_confidence_threshold'] ?? 0.85);
                $requiresHuman = (bool) ($aiData['requires_human'] ?? false);
                $mode = (string) ($config['mode'] ?? 'draft_only');

                $sourceCommunication = Database::queryOne(
                    "SELECT *
                     FROM communications
                     WHERE workspace_id = ?
                       AND id = ?",
                    [$workspaceId, $communicationId]
                ) ?: [];
                $replyRecord = [
                    'subject' => $aiData['subject'] ?? '',
                    'reply_text' => $aiData['reply_text'] ?? '',
                    'body_text' => $aiData['reply_text'] ?? '',
                    'body_html' => $aiData['body_html'] ?? '',
                    'signature_id' => $aiData['signature_id'] ?? null,
                    'signature_html' => $aiData['signature_html'] ?? '',
                    'signature_text' => $aiData['signature_text'] ?? '',
                    'sender_user_id' => (int) (($context['draft_style']['subject_user_id'] ?? 0)),
                    'sender_name' => (string) (($context['draft_style']['default_signature_name'] ?? '')),
                ];

                $shouldAutoSend = !$requiresHuman && $confidence >= $threshold && in_array($mode, ['hybrid', 'full_auto'], true);
                if ($mode === 'draft_only') {
                    $shouldAutoSend = false;
                }

                if (!$shouldAutoSend) {
                    $logId = $this->logDecision($workspaceId, $queueId, $communicationId, $contactId, $channel, [
                        'decision' => 'draft',
                        'status' => 'pending_review',
                        'reason_code' => $requiresHuman ? 'requires_human' : 'below_threshold',
                        'reason_notes' => "mode={$mode}, confidence={$confidence}, threshold={$threshold}",
                        'confidence' => $confidence,
                        'reply_subject' => $replyRecord['subject'],
                        'reply_text' => $replyRecord['reply_text'],
                        'request_payload' => $payload,
                        'response_payload' => $aiData,
                        'prompt_hash' => hash('sha256', json_encode($context)),
                        'latency_ms' => $this->latencyMs($started),
                    ]);
                    return ['action' => 'draft', 'log_id' => $logId];
                }

                $dispatch = $this->dispatcher->dispatch($channel, $contactId, $sourceCommunication, $replyRecord);
                $success = !empty($dispatch['success']);
                $logId = $this->logDecision($workspaceId, $queueId, $communicationId, $contactId, $channel, [
                    'decision' => $success ? 'auto_sent' : 'failed',
                    'status' => $success ? 'sent' : 'failed',
                    'reason_code' => $success ? 'auto_sent' : 'dispatch_failed',
                    'reason_notes' => $success ? 'Auto reply sent successfully' : (string) ($dispatch['error'] ?? 'Dispatch failed'),
                    'confidence' => $confidence,
                    'reply_subject' => (string) ($dispatch['subject'] ?? $replyRecord['subject']),
                    'reply_text' => (string) ($dispatch['body_text'] ?? $dispatch['message'] ?? $replyRecord['reply_text']),
                    'request_payload' => $payload,
                    'response_payload' => $aiData,
                    'dispatch_payload' => $dispatch,
                    'prompt_hash' => hash('sha256', json_encode($context)),
                    'latency_ms' => $this->latencyMs($started),
                ]);

                return [
                    'action' => $success ? 'auto_sent' : 'failed',
                    'log_id' => $logId,
                    'error' => $success ? null : (string) ($dispatch['error'] ?? 'Dispatch failed'),
                ];
            },
            null,
            'AI auto-responder queue item is missing a valid workspace.'
        );
    }

    private function buildContext(int $workspaceId, int $contactId, int $communicationId, string $channel, string $incomingMessage): array
    {
        $contact = Database::queryOne(
            "SELECT id, first_name, last_name, email, phone, company, job_title, stage, lead_score, assigned_to
             FROM contacts
             WHERE workspace_id = ?
               AND id = ?",
            [$workspaceId, $contactId]
        ) ?: [];
        $recentCommunications = Database::query(
            "SELECT channel, direction, subject, body, created_at
             FROM communications
             WHERE workspace_id = ?
               AND contact_id = ?
             ORDER BY created_at DESC
             LIMIT 8",
            [$workspaceId, $contactId]
        );
        $deals = Database::query(
            "SELECT title, value, stage, created_at
             FROM deals
             WHERE workspace_id = ?
               AND contact_id = ?
             ORDER BY created_at DESC
             LIMIT 3",
            [$workspaceId, $contactId]
        );
        $notes = Database::query(
            "SELECT title, content, created_at
             FROM notes
             WHERE workspace_id = ?
               AND entity_type = 'contact'
               AND entity_id = ?
             ORDER BY created_at DESC
             LIMIT 3",
            [$workspaceId, $contactId]
        );
        $threadState = [];
        try {
            $thread = (new ConversationIntelligenceService())->getThreadForCommunication($communicationId);
            if ($thread) {
                $threadState = (new ConversationIntelligenceService())->buildStateForThread($thread);
            }
        } catch (\Throwable $e) {
            $threadState = [];
        }
        $draftStyleUserId = $this->resolveDraftStyleUserId($contact, $threadState);
        $companyProfile = $this->companyProfile->get() ?: [];
        $draftStyle = $this->replyAssistantService->resolveDraftStyleContext($draftStyleUserId, $channel, [], [
            'recent_communications' => $recentCommunications,
            'company_profile' => $companyProfile,
            'contact' => $contact,
        ]);

        return [
            'channel' => $channel,
            'communication_id' => $communicationId,
            'incoming_message' => $incomingMessage,
            'contact' => $contact,
            'conversation_thread' => $threadState,
            'recent_communications' => $recentCommunications,
            'deals' => $deals,
            'company_profile' => $companyProfile,
            'draft_style' => $draftStyle,
            'notes' => array_map(static function (array $note): array {
                $note['content'] = substr(strip_tags((string) ($note['content'] ?? '')), 0, 250);
                return $note;
            }, $notes),
        ];
    }

    private function parseAiResponse(string $aiRaw, string $channel): array
    {
        $decoded = json_decode($aiRaw, true);
        if (!is_array($decoded)) {
            return [
                'reply_text' => trim($aiRaw),
                'confidence' => 0.72,
                'requires_human' => true,
                'reasoning_tags' => ['fallback_non_json'],
            ];
        }

        $replyText = trim((string) ($decoded['reply_text'] ?? $decoded['message'] ?? $decoded['body'] ?? ''));
        $subject = trim((string) ($decoded['subject'] ?? ''));
        $confidence = (float) ($decoded['confidence'] ?? 0.0);
        $requiresHuman = (bool) ($decoded['requires_human'] ?? false);

        if ($replyText === '' && isset($decoded['body_html'])) {
            $replyText = trim(strip_tags((string) $decoded['body_html']));
        }

        return [
            'subject' => $subject,
            'reply_text' => $replyText,
            'body_html' => (string) ($decoded['body_html'] ?? ''),
            'confidence' => max(0.0, min(1.0, $confidence)),
            'requires_human' => $requiresHuman,
            'reasoning_tags' => is_array($decoded['reasoning_tags'] ?? null) ? $decoded['reasoning_tags'] : [],
            'channel' => $channel,
            'raw' => $decoded,
        ];
    }

    private function logDecision(int $workspaceId, int $queueId, int $communicationId, int $contactId, string $channel, array $data): int
    {
        Database::execute(
            "INSERT INTO ai_autoresponder_logs
             (queue_id, workspace_id, communication_id, contact_id, channel, decision, status, confidence, reason_code, reason_notes,
              reply_subject, reply_text, prompt_hash, request_payload, response_payload, dispatch_payload, latency_ms)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $queueId ?: null,
                $workspaceId ?: null,
                $communicationId ?: null,
                $contactId,
                $channel,
                (string) ($data['decision'] ?? 'skipped'),
                (string) ($data['status'] ?? 'skipped'),
                isset($data['confidence']) ? (float) $data['confidence'] : null,
                (string) ($data['reason_code'] ?? null),
                (string) ($data['reason_notes'] ?? null),
                (string) ($data['reply_subject'] ?? null),
                (string) ($data['reply_text'] ?? null),
                (string) ($data['prompt_hash'] ?? null),
                isset($data['request_payload']) ? json_encode($data['request_payload']) : null,
                isset($data['response_payload']) ? json_encode($data['response_payload']) : null,
                isset($data['dispatch_payload']) ? json_encode($data['dispatch_payload']) : null,
                isset($data['latency_ms']) ? (int) $data['latency_ms'] : null,
            ]
        );

        return (int) Database::lastInsertId();
    }

    private function latencyMs(float $started): int
    {
        return (int) max(0, round((microtime(true) - $started) * 1000));
    }

    private function getThreadSuppressionReason(array $threadState, array $replyControls): ?string
    {
        if (!empty($replyControls['customer_opted_out'])) {
            return 'customer_opted_out';
        }
        if (!empty($replyControls['human_only'])) {
            return 'human_only_thread';
        }
        if (!empty($replyControls['escalation_required'])) {
            return 'escalation_required';
        }

        $status = (string) ($threadState['status'] ?? '');
        $priority = (string) ($threadState['priority'] ?? '');
        $overdue = !empty($threadState['is_overdue']);
        if ($status === 'waiting_on_us' && $priority === 'urgent' && $overdue) {
            return 'sensitive_overdue_thread';
        }

        return null;
    }

    private function resolveDraftStyleUserId(array $contact, array $threadState): int
    {
        $threadOwnerId = (int) ($threadState['current_owner_id'] ?? $threadState['owner_id'] ?? 0);
        if ($threadOwnerId > 0) {
            return $threadOwnerId;
        }

        $assignedTo = (int) ($contact['assigned_to'] ?? 0);
        if ($assignedTo > 0) {
            return $assignedTo;
        }

        return 0;
    }

    private function resolveWorkspaceId(array $queueItem, bool $allowContextFallback = true): int
    {
        $workspaceId = (int) ($queueItem['workspace_id'] ?? 0);
        if ($workspaceId > 0) {
            return $workspaceId;
        }

        $contextWorkspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        if ($allowContextFallback && $contextWorkspaceId > 0) {
            return $contextWorkspaceId;
        }

        $communicationId = (int) ($queueItem['communication_id'] ?? 0);
        if ($communicationId > 0) {
            $communication = Database::queryOne(
                "SELECT workspace_id
                 FROM communications
                 WHERE id = ?
                 LIMIT 1",
                [$communicationId]
            );
            $workspaceId = (int) ($communication['workspace_id'] ?? 0);
            if ($workspaceId > 0) {
                return $workspaceId;
            }
        }

        $contactId = (int) ($queueItem['contact_id'] ?? 0);
        if ($contactId > 0) {
            $contact = Database::queryOne(
                "SELECT workspace_id
                 FROM contacts
                 WHERE id = ?
                 LIMIT 1",
                [$contactId]
            );
            return (int) ($contact['workspace_id'] ?? 0);
        }

        return 0;
    }
}
