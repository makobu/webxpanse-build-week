<?php

namespace CRM\Services;

use CRM\Database;

class ConversationIntelligenceService
{
    private const META_MANUAL_OWNER_MODE = 'manual_owner_override_mode';
    private const META_MANUAL_OWNER_USER_ID = 'manual_owner_override_user_id';
    private const META_MANUAL_OWNER_UPDATED_AT = 'manual_owner_override_updated_at';

    /** @var array<string,bool> */
    private static array $columnCache = [];

    public function syncForCommunication(int $communicationId): ?array
    {
        $communication = $this->getCommunication($communicationId);
        if (!$communication) {
            return null;
        }

        return $this->syncForCommunicationRow($communication);
    }

    public function syncForCommunicationRow(array $communication): ?array
    {
        $contactId = (int) ($communication['contact_id'] ?? 0);
        $channel = strtolower((string) ($communication['channel'] ?? ''));
        if ($contactId <= 0 || $channel === '') {
            return null;
        }

        $threadKey = $this->deriveThreadKey($communication);
        if ($threadKey === '') {
            $threadKey = $this->legacyThreadKey($contactId, $channel);
        }

        $this->assignThreadKey((int) ($communication['id'] ?? 0), $threadKey);
        if ($channel === 'email') {
            $this->backfillEmailThreadKeys($communication, $threadKey);
        }

        $threadId = $this->getOrCreateThreadId($contactId, $channel, $threadKey);
        $existingThread = Database::queryOne(
            "SELECT *
             FROM conversation_threads
             WHERE id = ?
             LIMIT 1",
            [$threadId]
        ) ?: null;
        $threadCommunications = $this->getThreadCommunications($threadKey, $communication);
        $state = $this->buildThreadState($threadCommunications, $communication, $threadKey, $existingThread);
        $this->persistThreadState($threadId, $contactId, $channel, $threadKey, $state);

        if ($this->shouldEmitOrchestrationSignals()) {
            try {
                $intake = new AICrossDomainEventIntakeService();
                $resume = new AICrossDomainResumeService();
                $signal = [
                    'tenant_key' => 'contact:' . $contactId,
                    'source_domain' => 'customer_thread',
                    'trigger_key' => $state['status'] === 'waiting_on_us'
                        ? (($state['response_due_at'] ?? null) && strtotime((string) $state['response_due_at']) < time() ? 'thread_overdue' : 'reply_needed')
                        : 'thread_waiting_on_contact',
                    'trigger_entity_type' => 'communication',
                    'trigger_entity_id' => (int) ($communication['id'] ?? 0),
                    'related_entity_ids' => [
                        'contact_id' => $contactId,
                        'communication_id' => (int) ($communication['id'] ?? 0),
                    ],
                    'metadata' => [
                        'thread_status' => (string) ($state['status'] ?? 'open'),
                        'thread_summary' => (string) (($state['metadata_json']['thread_summary'] ?? '')),
                    ],
                ];
                if (in_array((string) ($state['status'] ?? ''), ['waiting_on_us', 'waiting_on_contact'], true)) {
                    $intake->processEvent($signal);
                    $resume->handleSignal($signal);
                }
            } catch (\Throwable $e) {
                error_log('ConversationIntelligenceService orchestration signal failed: ' . $e->getMessage());
            }
        }

        return $this->getThreadById($threadId);
    }

    public function getThreadForCommunication(int $communicationId): ?array
    {
        $thread = $this->syncForCommunication($communicationId);
        if ($thread) {
            return $thread;
        }

        $communication = $this->getCommunication($communicationId);
        if (!$communication) {
            return null;
        }

        $threadKey = trim((string) ($communication['thread_key'] ?? ''));
        if ($threadKey !== '') {
            return Database::queryOne(
                "SELECT * FROM conversation_threads WHERE thread_key = ? LIMIT 1",
                [$threadKey]
            ) ?: null;
        }

        return Database::queryOne(
            "SELECT * FROM conversation_threads WHERE contact_id = ? AND channel = ? LIMIT 1",
            [(int) ($communication['contact_id'] ?? 0), (string) ($communication['channel'] ?? '')]
        ) ?: null;
    }

    public function updateThreadStatusByCommunication(int $communicationId, string $status, ?string $reason = null): bool
    {
        $thread = $this->getThreadForCommunication($communicationId);
        if (!$thread) {
            return false;
        }

        $status = in_array($status, ['open', 'waiting_on_us', 'waiting_on_contact', 'resolved'], true)
            ? $status
            : 'open';
        $threadId = (int) ($thread['id'] ?? 0);
        if ($threadId <= 0) {
            return false;
        }

        $metadata = $this->decodeJson($thread['metadata_json'] ?? null);
        $metadata['manual_status_override'] = $status;
        $metadata['manual_status_updated_at'] = gmdate('Y-m-d H:i:s');

        Database::execute(
            "UPDATE conversation_threads
             SET status = ?,
                 is_resolved = ?,
                 resolved_at = ?,
                 resolution_reason = ?,
                 metadata_json = ?
             WHERE id = ?",
            [
                $status,
                $status === 'resolved' ? 1 : 0,
                $status === 'resolved' ? gmdate('Y-m-d H:i:s') : null,
                $reason,
                json_encode($metadata),
                $threadId,
            ]
        );

        return true;
    }

    public function clearManualOwnerOverrideForCommunication(int $communicationId): void
    {
        $thread = $this->getThreadForCommunication($communicationId);
        if (!$thread || empty($thread['id'])) {
            return;
        }

        $metadata = self::clearManualOwnerOverrideMetadata($this->decodeJson($thread['metadata_json'] ?? null));
        Database::execute(
            "UPDATE conversation_threads
             SET metadata_json = ?
             WHERE id = ?",
            [json_encode($metadata), (int) $thread['id']]
        );
    }

    /**
     * @param array<string,mixed> $metadata
     * @return array<string,mixed>
     */
    public static function setManualOwnerOverrideMetadata(array $metadata, ?int $ownerId): array
    {
        $metadata = self::clearManualOwnerOverrideMetadata($metadata);
        $metadata[self::META_MANUAL_OWNER_MODE] = $ownerId !== null && $ownerId > 0 ? 'assigned' : 'unassigned';
        if ($ownerId !== null && $ownerId > 0) {
            $metadata[self::META_MANUAL_OWNER_USER_ID] = $ownerId;
        }
        $metadata[self::META_MANUAL_OWNER_UPDATED_AT] = gmdate('Y-m-d H:i:s');
        return $metadata;
    }

    /**
     * @param array<string,mixed> $metadata
     * @return array<string,mixed>
     */
    public static function clearManualOwnerOverrideMetadata(array $metadata): array
    {
        unset(
            $metadata[self::META_MANUAL_OWNER_MODE],
            $metadata[self::META_MANUAL_OWNER_USER_ID],
            $metadata[self::META_MANUAL_OWNER_UPDATED_AT]
        );

        return $metadata;
    }

    public function buildStateForThread(array $thread): array
    {
        $metadata = $this->decodeJson($thread['metadata_json'] ?? null);
        $now = time();
        $responseDueAt = trim((string) ($thread['response_due_at'] ?? ''));
        $isOverdue = $responseDueAt !== '' && strtotime($responseDueAt) !== false && strtotime($responseDueAt) < $now
            && in_array((string) ($thread['status'] ?? ''), ['open', 'waiting_on_us'], true);

        return [
            'thread_id' => (int) ($thread['id'] ?? 0),
            'thread_key' => (string) ($thread['thread_key'] ?? ''),
            'status' => (string) ($thread['status'] ?? 'open'),
            'priority' => (string) ($thread['priority'] ?? 'medium'),
            'current_owner_id' => isset($thread['current_owner_id']) ? (int) $thread['current_owner_id'] : null,
            'response_due_at' => $responseDueAt !== '' ? $responseDueAt : null,
            'is_overdue' => $isOverdue,
            'unresolved_item_count' => (int) ($thread['unresolved_item_count'] ?? 0),
            'escalation_status' => (string) ($thread['escalation_status'] ?? ''),
            'queue_destination' => (string) ($metadata['queue_destination'] ?? ''),
            'urgency_band' => (string) ($metadata['urgency_band'] ?? ''),
            'next_best_action' => (string) ($metadata['next_best_action'] ?? ''),
            'thread_summary' => (string) ($metadata['thread_summary'] ?? ''),
            'preferred_channel' => (string) ($metadata['preferred_channel'] ?? ''),
            'sentiment_trend' => (string) ($metadata['sentiment_trend'] ?? 'unknown'),
            'recurring_concerns' => array_values((array) ($metadata['recurring_concerns'] ?? [])),
            'unresolved_commitments' => array_values((array) ($metadata['unresolved_commitments'] ?? [])),
            'last_requested_action' => $metadata['last_requested_action'] ?? null,
            'reply_controls' => (array) ($metadata['reply_controls'] ?? []),
            'related_records' => (array) ($metadata['related_records'] ?? []),
            'sla' => [
                'label' => $this->formatSlaLabel($responseDueAt, $isOverdue),
                'due_at' => $responseDueAt !== '' ? $responseDueAt : null,
            ],
        ];
    }

    private function getCommunication(int $communicationId): ?array
    {
        $columns = [
            'id',
            'contact_id',
            'channel',
            'direction',
            'subject',
            'body',
            'metadata',
            'created_at',
            'read_at',
        ];

        foreach (['thread_key', 'message_id', 'in_reply_to', 'from_email', 'to_email', 'triage_priority', 'triage_status', 'triage_owner_id'] as $column) {
            if ($this->columnExists('communications', $column)) {
                $columns[] = $column;
            }
        }

        return Database::queryOne(
            "SELECT " . implode(', ', $columns) . " FROM communications WHERE id = ? LIMIT 1",
            [$communicationId]
        ) ?: null;
    }

    private function deriveThreadKey(array $communication): string
    {
        $contactId = (int) ($communication['contact_id'] ?? 0);
        $channel = strtolower((string) ($communication['channel'] ?? ''));
        if ($contactId <= 0 || $channel === '') {
            return '';
        }

        if ($channel !== 'email') {
            return $this->legacyThreadKey($contactId, $channel);
        }

        $messageId = trim((string) ($communication['message_id'] ?? ''));
        $replyTo = trim((string) ($communication['in_reply_to'] ?? ''));
        if ($replyTo !== '') {
            $existing = $this->findThreadKeyByEmailHeader($replyTo);
            if ($existing !== '') {
                return $existing;
            }
        }
        if ($messageId !== '') {
            $existing = $this->findThreadKeyByEmailHeader($messageId);
            if ($existing !== '') {
                return $existing;
            }
        }

        $subject = $this->normalizeSubject((string) ($communication['subject'] ?? ''));
        if ($subject !== '') {
            return sprintf('email:contact:%d:subject:%s', $contactId, substr(sha1($subject), 0, 24));
        }

        return $this->legacyThreadKey($contactId, $channel);
    }

    private function findThreadKeyByEmailHeader(string $headerValue): string
    {
        if ($headerValue === '') {
            return '';
        }

        $clauses = [];
        $params = [];
        if ($this->columnExists('communications', 'message_id')) {
            $clauses[] = 'message_id = ?';
            $params[] = $headerValue;
        }
        if ($this->columnExists('communications', 'in_reply_to')) {
            $clauses[] = 'in_reply_to = ?';
            $params[] = $headerValue;
        }
        if (empty($clauses)) {
            return '';
        }

        $sql = "SELECT thread_key
                FROM communications
                WHERE (" . implode(' OR ', $clauses) . ")
                  AND thread_key IS NOT NULL
                  AND thread_key <> ''
                ORDER BY created_at DESC
                LIMIT 1";
        $row = Database::queryOne($sql, $params);
        return trim((string) ($row['thread_key'] ?? ''));
    }

    private function backfillEmailThreadKeys(array $communication, string $threadKey): void
    {
        $contactId = (int) ($communication['contact_id'] ?? 0);
        if ($contactId <= 0 || !$this->columnExists('communications', 'thread_key')) {
            return;
        }

        $subject = $this->normalizeSubject((string) ($communication['subject'] ?? ''));
        if ($subject === '') {
            return;
        }

        $rows = Database::query(
            "SELECT id, subject, thread_key
             FROM communications
             WHERE contact_id = ?
               AND channel = 'email'
             ORDER BY created_at ASC
             LIMIT 250",
            [$contactId]
        );

        foreach ($rows as $row) {
            if ($this->normalizeSubject((string) ($row['subject'] ?? '')) !== $subject) {
                continue;
            }
            $existingKey = trim((string) ($row['thread_key'] ?? ''));
            if ($existingKey === $threadKey) {
                continue;
            }
            Database::execute(
                "UPDATE communications SET thread_key = ? WHERE id = ?",
                [$threadKey, (int) $row['id']]
            );
        }
    }

    private function assignThreadKey(int $communicationId, string $threadKey): void
    {
        if ($communicationId <= 0 || $threadKey === '' || !$this->columnExists('communications', 'thread_key')) {
            return;
        }

        Database::execute(
            "UPDATE communications SET thread_key = ? WHERE id = ?",
            [$threadKey, $communicationId]
        );
    }

    private function getOrCreateThreadId(int $contactId, string $channel, string $threadKey): int
    {
        $existing = Database::queryOne(
            "SELECT id FROM conversation_threads WHERE thread_key = ? LIMIT 1",
            [$threadKey]
        );
        if ($existing) {
            return (int) $existing['id'];
        }

        try {
            Database::execute(
                "INSERT INTO conversation_threads (contact_id, channel, thread_key, last_message_at, last_channel, status)
                 VALUES (?, ?, ?, NOW(), ?, 'open')",
                [$contactId, $channel, $threadKey, $channel]
            );
        } catch (\Throwable $e) {
            $existing = Database::queryOne(
                "SELECT id FROM conversation_threads WHERE thread_key = ? LIMIT 1",
                [$threadKey]
            );
            if ($existing) {
                return (int) $existing['id'];
            }
            throw $e;
        }

        return (int) Database::lastInsertId();
    }

    private function getThreadCommunications(string $threadKey, array $communication): array
    {
        if ($this->columnExists('communications', 'thread_key')) {
            $rows = Database::query(
                "SELECT *
                 FROM communications
                 WHERE thread_key = ?
                 ORDER BY created_at ASC",
                [$threadKey]
            );
            if (!empty($rows)) {
                return $rows;
            }
        }

        return Database::query(
            "SELECT *
             FROM communications
             WHERE contact_id = ?
               AND channel = ?
             ORDER BY created_at ASC",
            [(int) ($communication['contact_id'] ?? 0), (string) ($communication['channel'] ?? '')]
        );
    }

    private function buildThreadState(
        array $threadCommunications,
        array $seedCommunication,
        string $threadKey,
        ?array $existingThread = null
    ): array
    {
        $contactId = (int) ($seedCommunication['contact_id'] ?? 0);
        $channel = (string) ($seedCommunication['channel'] ?? '');
        $latest = !empty($threadCommunications) ? $threadCommunications[array_key_last($threadCommunications)] : $seedCommunication;
        $lastInboundAt = null;
        $lastOutboundAt = null;
        $messageCount = count($threadCommunications);
        $unreadInbound = 0;
        $latestPriority = '';
        $latestOwnerId = 0;
        $sentimentScores = [];
        $concernCounts = [];
        $unresolvedCommitments = [];
        $lastRequestedAction = null;

        foreach ($threadCommunications as $row) {
            $createdAt = (string) ($row['created_at'] ?? '');
            $direction = strtolower((string) ($row['direction'] ?? ''));
            if ($direction === 'inbound') {
                $lastInboundAt = $this->maxDate($lastInboundAt, $createdAt);
                if (empty($row['read_at'])) {
                    $unreadInbound++;
                }
                $action = $this->extractRequestedAction($row);
                if ($action !== null) {
                    $lastRequestedAction = $action;
                }
                $bodyText = $this->messagePlainText($row);
                foreach ($this->extractConcernTopics($bodyText) as $topic) {
                    $concernCounts[$topic] = ($concernCounts[$topic] ?? 0) + 1;
                }
                $questionSnippet = $this->extractQuestionSnippet($bodyText);
                if ($questionSnippet !== null) {
                    $unresolvedCommitments[] = [
                        'source_communication_id' => (int) ($row['id'] ?? 0),
                        'summary' => $questionSnippet,
                        'last_seen_at' => $createdAt,
                        'state' => 'open',
                    ];
                }
            } elseif ($direction === 'outbound') {
                $lastOutboundAt = $this->maxDate($lastOutboundAt, $createdAt);
            }

            $priority = strtolower((string) ($row['triage_priority'] ?? ''));
            if ($priority !== '') {
                $latestPriority = $priority;
            }
            $ownerId = (int) ($row['triage_owner_id'] ?? 0);
            if ($ownerId > 0) {
                $latestOwnerId = $ownerId;
            }

            $metadata = $this->decodeJson($row['metadata'] ?? null);
            $sentiment = $metadata['sentiment'] ?? null;
            if (is_array($sentiment)) {
                if (isset($sentiment['score']) && is_numeric($sentiment['score'])) {
                    $sentimentScores[] = (float) $sentiment['score'];
                } else {
                    $sentimentScores[] = $this->sentimentLabelToScore((string) ($sentiment['sentiment'] ?? 'neutral'));
                }
            }
        }

        $contact = Database::queryOne(
            "SELECT assigned_to, company, stage, lead_score, email
             FROM contacts
             WHERE id = ?
             LIMIT 1",
            [$contactId]
        ) ?: [];
        $existingMetadata = $this->decodeJson($existingThread['metadata_json'] ?? null);
        $manualMode = strtolower(trim((string) ($existingMetadata[self::META_MANUAL_OWNER_MODE] ?? '')));
        $manualOwnerId = (int) ($existingMetadata[self::META_MANUAL_OWNER_USER_ID] ?? 0);
        $contactOwnerId = (int) ($contact['assigned_to'] ?? 0);
        if ($manualMode === 'unassigned') {
            $ownerId = null;
        } elseif ($manualMode === 'assigned' && $manualOwnerId > 0) {
            $ownerId = $manualOwnerId;
        } else {
            $ownerId = $latestOwnerId > 0 ? $latestOwnerId : $contactOwnerId;
        }
        $resolvedOwnerId = $ownerId !== null && $ownerId > 0 ? $ownerId : 0;
        $priority = $latestPriority !== '' ? $latestPriority : $this->inferPriority($contactId, $concernCounts);
        $status = $this->inferStatus($latest, $lastInboundAt, $lastOutboundAt);
        $responseDueAt = $this->buildResponseDueAt($status, $priority, $lastInboundAt);
        $isOverdue = $responseDueAt !== null && strtotime($responseDueAt) < time() && in_array($status, ['open', 'waiting_on_us'], true);
        $sentimentTrend = $this->summarizeSentimentTrend($sentimentScores);
        $recurringConcerns = $this->topConcernTopics($concernCounts);
        $relatedRecords = $this->getRelatedRecordSummary($contactId);
        $queueDestination = $this->buildQueueDestination($status, $isOverdue, $resolvedOwnerId);
        $urgencyBand = $this->buildUrgencyBand($priority, $isOverdue, $recurringConcerns);
        $escalationStatus = $this->buildEscalationStatus($priority, $isOverdue, $recurringConcerns, $relatedRecords);
        $nextBestAction = $this->buildNextBestAction($status, $queueDestination, $relatedRecords, $resolvedOwnerId, $lastRequestedAction);
        $threadSummary = $this->buildThreadSummary($threadCommunications, $contactId, $channel, $status, $nextBestAction);
        $preferredChannel = $this->getPreferredChannel($contactId);
        $replyControls = [
            'auto_response_state' => in_array($queueDestination, ['escalated', 'manager_review'], true) ? 'human_only' : 'auto_allowed',
            'human_only' => in_array($escalationStatus, ['manager_review', 'finance_review', 'complaint_review'], true),
            'escalation_required' => $escalationStatus !== 'none',
            'customer_opted_out' => in_array('opt_out', $recurringConcerns, true),
        ];

        return [
            'last_message_at' => (string) ($latest['created_at'] ?? gmdate('Y-m-d H:i:s')),
            'message_count' => $messageCount,
            'status' => $status,
            'current_owner_id' => $ownerId !== null && $ownerId > 0 ? $ownerId : null,
            'priority' => $priority,
            'response_due_at' => $responseDueAt,
            'last_inbound_at' => $lastInboundAt,
            'last_outbound_at' => $lastOutboundAt,
            'last_channel' => (string) ($latest['channel'] ?? $channel),
            'unresolved_item_count' => max($unreadInbound, count($unresolvedCommitments)),
            'escalation_status' => $escalationStatus,
            'is_resolved' => $status === 'resolved' ? 1 : 0,
            'resolved_at' => $status === 'resolved' ? gmdate('Y-m-d H:i:s') : null,
            'resolution_reason' => $status === 'resolved' ? 'resolved_by_state' : null,
            'metadata_json' => array_merge($existingMetadata, [
                'thread_key' => $threadKey,
                'preferred_channel' => $preferredChannel,
                'sentiment_trend' => $sentimentTrend,
                'recurring_concerns' => $recurringConcerns,
                'unresolved_commitments' => array_slice($unresolvedCommitments, -3),
                'last_requested_action' => $lastRequestedAction,
                'next_best_action' => $nextBestAction,
                'thread_summary' => $threadSummary,
                'queue_destination' => $queueDestination,
                'urgency_band' => $urgencyBand,
                'reply_controls' => $replyControls,
                'related_records' => $relatedRecords,
            ]),
        ];
    }

    private function persistThreadState(int $threadId, int $contactId, string $channel, string $threadKey, array $state): void
    {
        Database::execute(
            "UPDATE conversation_threads
             SET contact_id = ?,
                 channel = ?,
                 thread_key = ?,
                 last_message_at = ?,
                 message_count = ?,
                 is_resolved = ?,
                 resolved_at = ?,
                 status = ?,
                 current_owner_id = ?,
                 priority = ?,
                 response_due_at = ?,
                 last_inbound_at = ?,
                 last_outbound_at = ?,
                 last_channel = ?,
                 unresolved_item_count = ?,
                 escalation_status = ?,
                 resolution_reason = ?,
                 metadata_json = ?
             WHERE id = ?",
            [
                $contactId,
                $channel,
                $threadKey,
                $state['last_message_at'],
                $state['message_count'],
                $state['is_resolved'],
                $state['resolved_at'],
                $state['status'],
                $state['current_owner_id'],
                $state['priority'],
                $state['response_due_at'],
                $state['last_inbound_at'],
                $state['last_outbound_at'],
                $state['last_channel'],
                $state['unresolved_item_count'],
                $state['escalation_status'],
                $state['resolution_reason'],
                json_encode($state['metadata_json']),
                $threadId,
            ]
        );
    }

    private function getThreadById(int $threadId): ?array
    {
        $thread = Database::queryOne(
            "SELECT * FROM conversation_threads WHERE id = ? LIMIT 1",
            [$threadId]
        );
        if (!$thread) {
            return null;
        }

        $thread['_state'] = $this->buildStateForThread($thread);
        return $thread;
    }

    private function inferStatus(array $latest, ?string $lastInboundAt, ?string $lastOutboundAt): string
    {
        $direction = strtolower((string) ($latest['direction'] ?? ''));
        if ($direction === 'inbound') {
            return 'waiting_on_us';
        }
        if ($direction === 'outbound' && $lastInboundAt !== null) {
            return 'waiting_on_contact';
        }
        if ($lastInboundAt === null && $lastOutboundAt !== null) {
            return 'waiting_on_contact';
        }
        return 'open';
    }

    private function inferPriority(int $contactId, array $concernCounts): string
    {
        $hasOpenDeals = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM deals
             WHERE contact_id = ?
               AND stage IN ('prospecting','qualification','proposal','negotiation')",
            [$contactId]
        )['c'] ?? 0);
        $hasPendingInvoices = $this->tableExists('invoices')
            ? (int) (Database::queryOne(
                "SELECT COUNT(*) AS c
                 FROM invoices
                 WHERE contact_id = ?
                   AND status IN ('sent','overdue','draft','viewed','accepted')",
                [$contactId]
            )['c'] ?? 0)
            : 0;

        if ($hasPendingInvoices > 0 || isset($concernCounts['payment'])) {
            return 'urgent';
        }
        if ($hasOpenDeals > 0 || isset($concernCounts['pricing']) || isset($concernCounts['timeline'])) {
            return 'high';
        }
        return 'medium';
    }

    private function buildResponseDueAt(string $status, string $priority, ?string $lastInboundAt): ?string
    {
        if (!in_array($status, ['open', 'waiting_on_us'], true) || $lastInboundAt === null) {
            return null;
        }

        $hours = match ($priority) {
            'urgent' => 2,
            'high' => 8,
            'medium' => 24,
            default => 48,
        };

        return gmdate('Y-m-d H:i:s', strtotime($lastInboundAt . ' +' . $hours . ' hours'));
    }

    private function summarizeSentimentTrend(array $scores): string
    {
        if (empty($scores)) {
            return 'unknown';
        }

        $average = array_sum($scores) / max(1, count($scores));
        if ($average > 0.2) {
            return 'positive';
        }
        if ($average < -0.2) {
            return 'negative';
        }
        return 'neutral';
    }

    /**
     * @return array<int,string>
     */
    private function topConcernTopics(array $concernCounts): array
    {
        if (empty($concernCounts)) {
            return [];
        }
        arsort($concernCounts);
        return array_slice(array_keys($concernCounts), 0, 3);
    }

    private function getRelatedRecordSummary(int $contactId): array
    {
        $summary = [
            'open_deals' => 0,
            'open_tasks' => 0,
            'pending_invoices' => 0,
        ];

        $summary['open_deals'] = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM deals
             WHERE contact_id = ?
               AND stage IN ('prospecting','qualification','proposal','negotiation')",
            [$contactId]
        )['c'] ?? 0);
        $summary['open_tasks'] = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM tasks
             WHERE contact_id = ?
               AND status NOT IN ('completed','cancelled')",
            [$contactId]
        )['c'] ?? 0);
        if ($this->tableExists('invoices')) {
            $summary['pending_invoices'] = (int) (Database::queryOne(
                "SELECT COUNT(*) AS c
                 FROM invoices
                 WHERE contact_id = ?
                   AND status IN ('draft','sent','viewed','accepted','overdue')",
                [$contactId]
            )['c'] ?? 0);
        }

        return $summary;
    }

    private function buildQueueDestination(string $status, bool $isOverdue, int $ownerId): string
    {
        if ($status === 'resolved') {
            return 'resolved';
        }
        if ($ownerId <= 0) {
            return 'unassigned';
        }
        if ($isOverdue) {
            return 'overdue_replies';
        }
        if ($status === 'waiting_on_contact') {
            return 'waiting_on_contact';
        }
        if ($status === 'waiting_on_us') {
            return 'needs_reply';
        }
        return 'open';
    }

    private function buildUrgencyBand(string $priority, bool $isOverdue, array $concerns): string
    {
        if ($isOverdue || $priority === 'urgent') {
            return 'urgent';
        }
        if ($priority === 'high' || in_array('payment', $concerns, true)) {
            return 'high';
        }
        if ($priority === 'medium') {
            return 'medium';
        }
        return 'low';
    }

    private function buildEscalationStatus(string $priority, bool $isOverdue, array $concerns, array $relatedRecords): string
    {
        if ($isOverdue && $priority === 'urgent') {
            return 'manager_review';
        }
        if (in_array('payment', $concerns, true) || (int) ($relatedRecords['pending_invoices'] ?? 0) > 0) {
            return 'finance_review';
        }
        if (in_array('complaint', $concerns, true)) {
            return 'complaint_review';
        }
        return 'none';
    }

    private function buildNextBestAction(string $status, string $queueDestination, array $relatedRecords, int $ownerId, $lastRequestedAction): string
    {
        if ($queueDestination === 'unassigned') {
            return 'Assign an owner';
        }
        if ($queueDestination === 'overdue_replies') {
            return 'Reply now and clear the overdue thread';
        }
        if ($status === 'waiting_on_us' && !empty($lastRequestedAction)) {
            return 'Reply about: ' . substr((string) ($lastRequestedAction['summary'] ?? $lastRequestedAction), 0, 80);
        }
        if ((int) ($relatedRecords['open_tasks'] ?? 0) > 0) {
            return 'Review linked open tasks before replying';
        }
        if ((int) ($relatedRecords['open_deals'] ?? 0) > 0) {
            return 'Advance the related deal after reply';
        }
        if ((int) ($relatedRecords['pending_invoices'] ?? 0) > 0) {
            return 'Follow up on the pending invoice';
        }
        if ($status === 'waiting_on_contact') {
            return 'Wait for contact response';
        }
        return $ownerId > 0 ? 'Review and respond if needed' : 'Assign owner and review';
    }

    private function buildThreadSummary(array $threadCommunications, int $contactId, string $channel, string $status, string $nextBestAction): string
    {
        $count = count($threadCommunications);
        $latest = !empty($threadCommunications) ? $threadCommunications[array_key_last($threadCommunications)] : [];
        $latestPreview = substr($this->messagePlainText($latest), 0, 120);
        $parts = [
            sprintf('%d message%s on %s', $count, $count === 1 ? '' : 's', strtoupper($channel)),
            'status: ' . str_replace('_', ' ', $status),
        ];
        if ($latestPreview !== '') {
            $parts[] = 'latest: ' . $latestPreview;
        }
        if ($contactId > 0) {
            $parts[] = 'next: ' . $nextBestAction;
        }
        return implode(' | ', $parts);
    }

    private function getPreferredChannel(int $contactId): string
    {
        $rows = Database::query(
            "SELECT channel, COUNT(*) AS c
             FROM communications
             WHERE contact_id = ?
             GROUP BY channel
             ORDER BY c DESC, MAX(created_at) DESC",
            [$contactId]
        );
        return strtolower((string) ($rows[0]['channel'] ?? 'email'));
    }

    private function extractRequestedAction(array $row): ?array
    {
        $text = $this->messagePlainText($row);
        $snippet = $this->extractQuestionSnippet($text);
        if ($snippet === null) {
            return null;
        }

        return [
            'source_communication_id' => (int) ($row['id'] ?? 0),
            'summary' => $snippet,
            'channel' => (string) ($row['channel'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }

    private function extractQuestionSnippet(string $text): ?string
    {
        $plain = trim((string) preg_replace('/\s+/', ' ', strip_tags($text)));
        if ($plain === '') {
            return null;
        }
        if (strpos($plain, '?') !== false) {
            $parts = preg_split('/\?+/', $plain);
            $candidate = trim((string) ($parts[0] ?? ''));
            return $candidate !== '' ? substr($candidate . '?', 0, 140) : null;
        }

        if (preg_match('/\b(can you|could you|please|need|want|quote|pricing|invoice|call|meeting|when)\b/i', $plain)) {
            return substr($plain, 0, 140);
        }

        return null;
    }

    /**
     * @return array<int,string>
     */
    private function extractConcernTopics(string $text): array
    {
        $plain = strtolower($text);
        $topics = [];
        $map = [
            'pricing' => ['price', 'pricing', 'quote', 'cost', 'budget'],
            'timeline' => ['when', 'timeline', 'deadline', 'delivery', 'schedule'],
            'payment' => ['invoice', 'payment', 'paid', 'billing', 'proforma'],
            'complaint' => ['issue', 'problem', 'complaint', 'angry', 'frustrated', 'unhappy'],
            'support' => ['help', 'support', 'assist', 'fix'],
            'opt_out' => ['stop', 'unsubscribe', 'do not contact', 'opt out'],
        ];
        foreach ($map as $topic => $terms) {
            foreach ($terms as $term) {
                if (strpos($plain, $term) !== false) {
                    $topics[] = $topic;
                    break;
                }
            }
        }
        return $topics;
    }

    private function messagePlainText(array $row): string
    {
        $text = trim((string) ($row['body'] ?? ''));
        if ($text !== '') {
            return trim((string) preg_replace('/\s+/', ' ', strip_tags($text)));
        }
        return trim((string) ($row['subject'] ?? ''));
    }

    private function normalizeSubject(string $subject): string
    {
        $value = trim(html_entity_decode($subject, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        while (preg_match('/^(re|fw|fwd)\s*:\s*/i', $value)) {
            $value = preg_replace('/^(re|fw|fwd)\s*:\s*/i', '', $value);
        }
        $value = preg_replace('/\s+/', ' ', trim($value));
        return strtolower($value);
    }

    private function sentimentLabelToScore(string $label): float
    {
        return match (strtolower($label)) {
            'positive' => 0.6,
            'negative' => -0.6,
            default => 0.0,
        };
    }

    private function legacyThreadKey(int $contactId, string $channel): string
    {
        return sprintf('%s:contact:%d', strtolower($channel), $contactId);
    }

    private function maxDate(?string $current, ?string $candidate): ?string
    {
        if ($candidate === null || $candidate === '') {
            return $current;
        }
        if ($current === null || $current === '') {
            return $candidate;
        }
        return strtotime($candidate) > strtotime($current) ? $candidate : $current;
    }

    private function decodeJson($value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function formatSlaLabel(?string $responseDueAt, bool $isOverdue): ?string
    {
        if ($responseDueAt === null || $responseDueAt === '') {
            return null;
        }
        $ts = strtotime($responseDueAt);
        if ($ts === false) {
            return null;
        }
        if ($isOverdue) {
            return 'Overdue since ' . gmdate('M j g:i A', $ts);
        }
        return 'Reply by ' . gmdate('M j g:i A', $ts);
    }

    private function tableExists(string $table): bool
    {
        static $cache = [];
        if (array_key_exists($table, $cache)) {
            return $cache[$table];
        }
        try {
            $row = Database::queryOne(
                "SELECT 1
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?",
                [$table]
            );
            $cache[$table] = !empty($row);
        } catch (\Throwable $e) {
            $cache[$table] = false;
        }
        return $cache[$table];
    }

    private function columnExists(string $table, string $column): bool
    {
        $cacheKey = $table . '.' . $column;
        if (array_key_exists($cacheKey, self::$columnCache)) {
            return self::$columnCache[$cacheKey];
        }
        try {
            $row = Database::queryOne(
                "SELECT 1
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?
                   AND COLUMN_NAME = ?",
                [$table, $column]
            );
            self::$columnCache[$cacheKey] = !empty($row);
        } catch (\Throwable $e) {
            self::$columnCache[$cacheKey] = false;
        }
        return self::$columnCache[$cacheKey];
    }

    private function shouldEmitOrchestrationSignals(): bool
    {
        return !in_array(strtolower((string) ($_ENV['DISABLE_CROSS_DOMAIN_ORCHESTRATION'] ?? 'false')), ['1', 'true', 'yes', 'on'], true);
    }
}
