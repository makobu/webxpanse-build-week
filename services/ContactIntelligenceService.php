<?php

namespace CRM\Services;

use CRM\Database;

class ContactIntelligenceService
{
    private const DUPLICATE_MATCH_VERSION = 2;

    private static ?bool $hasMetadataJsonColumn = null;
    private static array $columnExistsCache = [];
    private ?EmailDomainClassifier $emailDomainClassifier = null;

    public function computeAndPersist(int $contactId): ?array
    {
        $contact = Database::queryOne("SELECT * FROM contacts WHERE id = ?", [$contactId]);
        if (!$contact) {
            return null;
        }

        $intelligence = $this->buildIntelligence($contact);
        $aiContext = $this->decodeJson($contact['ai_context'] ?? null);
        $aiContext['operational_context'] = [
            'relationship_health' => $intelligence['relationship_health'],
            'risk_flags' => $intelligence['risk_flags'],
            'next_best_actions' => $intelligence['next_best_actions'],
            'buying_role' => $intelligence['buying_role'],
            'communication_intelligence' => $intelligence['communication_intelligence'],
            'account_context' => $intelligence['account_context'],
            'data_quality' => $intelligence['data_quality'],
            'relationship_summary' => $intelligence['relationship_summary'],
            'generated_at' => date('c'),
        ];

        if ($this->hasMetadataJsonColumn()) {
            $metadata = $this->decodeJson($contact['metadata_json'] ?? null);
            $metadata['contact_intelligence'] = $intelligence;
            $metadata['contact_intelligence_updated_at'] = date('c');

            Database::execute(
                "UPDATE contacts SET metadata_json = ?, ai_context = ? WHERE id = ?",
                [
                    json_encode($metadata, JSON_UNESCAPED_SLASHES),
                    json_encode($aiContext, JSON_UNESCAPED_SLASHES),
                    $contactId,
                ]
            );
        } else {
            Database::execute(
                "UPDATE contacts SET ai_context = ? WHERE id = ?",
                [
                    json_encode($aiContext, JSON_UNESCAPED_SLASHES),
                    $contactId,
                ]
            );
        }

        return $intelligence;
    }

    public function getStoredOrCompute(int $contactId): ?array
    {
        if (!$this->hasMetadataJsonColumn()) {
            return $this->computeAndPersist($contactId);
        }

        $contact = Database::queryOne("SELECT id, workspace_id, metadata_json FROM contacts WHERE id = ?", [$contactId]);
        if (!$contact) {
            return null;
        }

        $metadata = $this->decodeJson($contact['metadata_json'] ?? null);
        $stored = $metadata['contact_intelligence'] ?? null;
        if (is_array($stored)) {
            return $this->sanitizeStoredIntelligence($stored, (int) ($contact['workspace_id'] ?? 0));
        }

        return $this->computeAndPersist($contactId);
    }

    /**
     * Return stored intelligence for list views without recomputing missing rows
     * during a latency-sensitive request.
     *
     * @param array<int, int> $contactIds
     * @return array<int, array<string, mixed>>
     */
    public function getStoredForContactIds(array $contactIds): array
    {
        if (!$this->hasMetadataJsonColumn()) {
            return [];
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $contactIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = Database::query(
            "SELECT id, workspace_id, metadata_json FROM contacts WHERE id IN ($placeholders)",
            $ids
        );

        $storedById = [];
        $workspaceById = [];
        $duplicateIdsByWorkspace = [];
        foreach ($rows as $row) {
            $metadata = $this->decodeJson($row['metadata_json'] ?? null);
            $stored = $metadata['contact_intelligence'] ?? null;
            if (is_array($stored)) {
                $contactId = (int) $row['id'];
                $workspaceId = (int) ($row['workspace_id'] ?? 0);
                $storedById[$contactId] = $stored;
                $workspaceById[$contactId] = $workspaceId;

                foreach ($this->duplicateIdsFromIntelligence($stored) as $duplicateId) {
                    if ($workspaceId > 0) {
                        $duplicateIdsByWorkspace[$workspaceId][] = $duplicateId;
                    }
                }
            }
        }

        $validDuplicateIdsByWorkspace = [];
        foreach ($duplicateIdsByWorkspace as $workspaceId => $duplicateIds) {
            $duplicateIds = array_values(array_unique(array_filter(array_map('intval', $duplicateIds), static fn (int $id): bool => $id > 0)));
            if ($duplicateIds === []) {
                continue;
            }

            $duplicatePlaceholders = implode(',', array_fill(0, count($duplicateIds), '?'));
            $validRows = Database::query(
                "SELECT id FROM contacts WHERE workspace_id = ? AND id IN ($duplicatePlaceholders)",
                array_merge([(int) $workspaceId], $duplicateIds)
            );
            $validDuplicateIdsByWorkspace[(int) $workspaceId] = array_fill_keys(
                array_map(static fn (array $row): int => (int) $row['id'], $validRows),
                true
            );
        }

        foreach ($storedById as $contactId => $stored) {
            $workspaceId = (int) ($workspaceById[$contactId] ?? 0);
            $storedById[$contactId] = $this->sanitizeStoredIntelligence(
                $stored,
                $workspaceId,
                $validDuplicateIdsByWorkspace[$workspaceId] ?? []
            );
        }

        return $storedById;
    }

    private function hasMetadataJsonColumn(): bool
    {
        if (self::$hasMetadataJsonColumn === null) {
            self::$hasMetadataJsonColumn = $this->columnExists('contacts', 'metadata_json');
        }

        return self::$hasMetadataJsonColumn;
    }

    private function columnExists(string $table, string $column): bool
    {
        $key = $table . '.' . $column;
        if (array_key_exists($key, self::$columnExistsCache)) {
            return self::$columnExistsCache[$key];
        }

        try {
            $row = Database::queryOne(
                "SELECT COUNT(*) AS count
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?
                   AND COLUMN_NAME = ?",
                [$table, $column]
            );
            self::$columnExistsCache[$key] = ((int) ($row['count'] ?? 0)) > 0;
        } catch (\Throwable $e) {
            self::$columnExistsCache[$key] = false;
        }

        return self::$columnExistsCache[$key];
    }

    public function buildIntelligence(array $contact): array
    {
        $contactId = (int) $contact['id'];
        $summary = $this->buildRelationshipSummary($contactId);
        $communication = $this->buildCommunicationIntelligence($contactId);
        $account = $this->buildAccountContext($contact);
        $duplicates = $this->findLikelyDuplicates($contact);
        $relationshipEvidence = $this->buildRelationshipEvidence($summary);
        $dataQuality = $this->buildDataQuality($contact, $duplicates, $relationshipEvidence);
        $buyingRole = $this->detectBuyingRole($contact, $communication);
        $health = $this->buildRelationshipHealth($contact, $summary, $communication, $account, $dataQuality, $relationshipEvidence);
        $nextActions = $this->buildNextActions($summary, $communication, $health, $dataQuality, $relationshipEvidence);

        return [
            'relationship_summary' => $summary,
            'relationship_health' => $health,
            'risk_flags' => $health['risk_flags'],
            'next_best_actions' => $nextActions,
            'buying_role' => $buyingRole,
            'communication_intelligence' => $communication,
            'account_context' => $account,
            'data_quality' => $dataQuality,
            'duplicates' => $duplicates,
            'duplicate_match_version' => self::DUPLICATE_MATCH_VERSION,
            'generated_at' => date('c'),
        ];
    }

    public function buildUnifiedTimeline(int $contactId, int $limit = 40): array
    {
        $events = [];
        $eventTimeField = $this->columnExists('events', 'event_date') ? 'event_date' : 'created_at';

        foreach (Database::query("SELECT id, channel, direction, subject, body, created_at FROM communications WHERE contact_id = ? ORDER BY created_at DESC LIMIT 20", [$contactId]) as $row) {
            $events[] = [
                'type' => (string) ($row['channel'] ?? 'communication'),
                'filter' => (string) ($row['channel'] ?? 'communication'),
                'title' => (string) (($row['direction'] ?? 'outbound') === 'inbound' ? 'Inbound ' : 'Outbound ') . ucfirst((string) ($row['channel'] ?? 'message')),
                'detail' => (string) (($row['subject'] ?? '') !== '' ? $row['subject'] : $this->truncate(strip_tags((string) ($row['body'] ?? '')), 120)),
                'created_at' => (string) $row['created_at'],
            ];
        }

        foreach (Database::query("SELECT id, title, content, created_at FROM notes WHERE entity_type = 'contact' AND entity_id = ? AND is_private = 0 ORDER BY created_at DESC LIMIT 10", [$contactId]) as $row) {
            $events[] = [
                'type' => 'note',
                'filter' => 'notes',
                'title' => (string) (($row['title'] ?? '') !== '' ? $row['title'] : 'Note added'),
                'detail' => $this->truncate(strip_tags((string) ($row['content'] ?? '')), 120),
                'created_at' => (string) $row['created_at'],
            ];
        }

        foreach (Database::query("SELECT id, title, stage, value, updated_at, created_at FROM deals WHERE contact_id = ? ORDER BY COALESCE(updated_at, created_at) DESC LIMIT 10", [$contactId]) as $row) {
            $events[] = [
                'type' => 'deal',
                'filter' => 'deals',
                'title' => 'Deal: ' . (string) ($row['title'] ?? ('#' . $row['id'])),
                'detail' => 'Stage ' . (string) ($row['stage'] ?? 'unknown') . (($row['value'] ?? 0) > 0 ? ' · ' . number_format((float) $row['value'], 2) : ''),
                'created_at' => (string) (($row['updated_at'] ?? '') !== '' ? $row['updated_at'] : $row['created_at']),
            ];
        }

        foreach (Database::query("SELECT id, invoice_number, document_type, status, created_at FROM invoices WHERE contact_id = ? ORDER BY created_at DESC LIMIT 10", [$contactId]) as $row) {
            $events[] = [
                'type' => 'invoice',
                'filter' => 'invoices',
                'title' => ucfirst((string) ($row['document_type'] ?? 'invoice')) . ': ' . (string) ($row['invoice_number'] ?? ('#' . $row['id'])),
                'detail' => 'Status ' . (string) ($row['status'] ?? 'unknown'),
                'created_at' => (string) $row['created_at'],
            ];
        }

        foreach (Database::query("SELECT id, title, status, due_date, created_at FROM tasks WHERE contact_id = ? ORDER BY created_at DESC LIMIT 10", [$contactId]) as $row) {
            $events[] = [
                'type' => 'task',
                'filter' => 'tasks',
                'title' => 'Task: ' . (string) ($row['title'] ?? ('#' . $row['id'])),
                'detail' => 'Status ' . (string) ($row['status'] ?? 'unknown') . (($row['due_date'] ?? '') !== '' ? ' · due ' . $row['due_date'] : ''),
                'created_at' => (string) $row['created_at'],
            ];
        }

        foreach (Database::query("SELECT id, title, {$eventTimeField} AS event_time, created_at FROM events WHERE contact_id = ? ORDER BY {$eventTimeField} DESC LIMIT 10", [$contactId]) as $row) {
            $events[] = [
                'type' => 'event',
                'filter' => 'events',
                'title' => 'Event: ' . (string) ($row['title'] ?? ('#' . $row['id'])),
                'detail' => 'Meeting/event',
                'created_at' => (string) (($row['event_time'] ?? '') !== '' ? $row['event_time'] : $row['created_at']),
            ];
        }

        usort($events, static fn (array $a, array $b): int => strcmp((string) $b['created_at'], (string) $a['created_at']));
        return array_slice($events, 0, $limit);
    }

    private function buildRelationshipSummary(int $contactId): array
    {
        $eventTimeField = $this->columnExists('events', 'event_date') ? 'event_date' : 'created_at';
        $lastInbound = Database::queryOne("SELECT id, channel, subject, body, created_at FROM communications WHERE contact_id = ? AND direction = 'inbound' ORDER BY created_at DESC LIMIT 1", [$contactId]);
        $lastOutbound = Database::queryOne("SELECT id, channel, subject, body, created_at FROM communications WHERE contact_id = ? AND direction = 'outbound' ORDER BY created_at DESC LIMIT 1", [$contactId]);
        $lastMeeting = Database::queryOne("SELECT id, title, {$eventTimeField} AS event_time, created_at FROM events WHERE contact_id = ? ORDER BY {$eventTimeField} DESC LIMIT 1", [$contactId]);
        $lastInvoice = Database::queryOne("SELECT id, invoice_number, document_type, status, created_at FROM invoices WHERE contact_id = ? ORDER BY created_at DESC LIMIT 1", [$contactId]);
        $lastNote = Database::queryOne("SELECT id, title, content, created_at FROM notes WHERE entity_type = 'contact' AND entity_id = ? AND is_private = 0 ORDER BY created_at DESC LIMIT 1", [$contactId]);
        $lastOpenTask = Database::queryOne("SELECT id, title, status, due_date, created_at FROM tasks WHERE contact_id = ? AND status <> 'completed' ORDER BY COALESCE(due_date, created_at) ASC LIMIT 1", [$contactId]);
        $lastDeal = Database::queryOne("SELECT id, title, stage, value, updated_at, created_at FROM deals WHERE contact_id = ? ORDER BY COALESCE(updated_at, created_at) DESC LIMIT 1", [$contactId]);

        return [
            'last_inbound_reply' => $this->formatSummaryItem($lastInbound, 'communication'),
            'last_outbound_reply' => $this->formatSummaryItem($lastOutbound, 'communication'),
            'last_meeting' => $this->formatSummaryItem($lastMeeting, 'meeting'),
            'last_quote_or_invoice' => $this->formatSummaryItem($lastInvoice, 'invoice'),
            'last_note' => $this->formatSummaryItem($lastNote, 'note'),
            'last_open_task' => $this->formatSummaryItem($lastOpenTask, 'task'),
            'last_deal' => $this->formatSummaryItem($lastDeal, 'deal'),
        ];
    }

    private function buildCommunicationIntelligence(int $contactId): array
    {
        $communications = Database::query(
            "SELECT id, channel, direction, subject, body, metadata, created_at
             FROM communications
             WHERE contact_id = ?
             ORDER BY created_at DESC
             LIMIT 40",
            [$contactId]
        );
        $notes = Database::query(
            "SELECT id, title, content, created_at
             FROM notes
             WHERE entity_type = 'contact' AND entity_id = ? AND is_private = 0
             ORDER BY created_at DESC
             LIMIT 10",
            [$contactId]
        );

        $channelCounts = [];
        $sentimentScores = [];
        $haystacks = [];
        foreach ($communications as $comm) {
            $channel = strtolower((string) ($comm['channel'] ?? 'email'));
            $channelCounts[$channel] = ($channelCounts[$channel] ?? 0) + 1;
            $metadata = $this->decodeJson($comm['metadata'] ?? null);
            $sentiment = $metadata['sentiment']['score'] ?? null;
            if ($sentiment !== null) {
                $sentimentScores[] = (float) $sentiment;
            }
            $haystacks[] = [
                'source_type' => 'communication',
                'source_id' => (int) $comm['id'],
                'created_at' => (string) $comm['created_at'],
                'text' => trim((string) (($comm['subject'] ?? '') . ' ' . strip_tags((string) ($comm['body'] ?? '')))),
            ];
        }
        foreach ($notes as $note) {
            $haystacks[] = [
                'source_type' => 'note',
                'source_id' => (int) $note['id'],
                'created_at' => (string) $note['created_at'],
                'text' => trim((string) (($note['title'] ?? '') . ' ' . strip_tags((string) ($note['content'] ?? '')))),
            ];
        }

        arsort($channelCounts);
        $preferredChannel = array_key_first($channelCounts) ?: 'email';
        $avgSentiment = !empty($sentimentScores) ? array_sum($sentimentScores) / count($sentimentScores) : 0.0;
        $trend = $avgSentiment > 0.15 ? 'positive' : ($avgSentiment < -0.15 ? 'negative' : 'neutral');

        $objections = $this->extractStructuredSignals($haystacks, [
            'pricing' => ['price', 'pricing', 'expensive', 'budget', 'cost'],
            'timing' => ['later', 'next quarter', 'not now', 'timing', 'delay'],
            'authority' => ['need approval', 'decision maker', 'boss', 'management'],
            'legal' => ['contract', 'legal', 'compliance', 'terms'],
        ]);

        $commitments = $this->extractStructuredSignals($haystacks, [
            'follow_up' => ['follow up', 'get back to', 'circle back', 'reach out'],
            'proposal' => ['send proposal', 'quote', 'proforma', 'invoice'],
            'demo' => ['demo', 'meeting', 'schedule', 'call'],
        ], true);

        $whatMattersNow = $this->buildWhatMattersNow($communications, $notes, $objections, $commitments);

        return [
            'preferred_channel' => $preferredChannel,
            'channel_counts' => $channelCounts,
            'response_cadence' => $this->buildResponseCadence($communications),
            'sentiment_trend' => [
                'label' => $trend,
                'average_score' => round($avgSentiment, 3),
                'sample_count' => count($sentimentScores),
            ],
            'objections' => $objections,
            'unresolved_commitments' => $commitments,
            'what_matters_now' => $whatMattersNow,
        ];
    }

    private function buildAccountContext(array $contact): array
    {
        $company = trim((string) ($contact['company'] ?? ''));
        $domain = $this->emailDomainClassifier()->extractBusinessDomainFromEmail((string) ($contact['email'] ?? '')) ?? '';
        $contactId = (int) $contact['id'];
        $workspaceId = $this->resolveContactWorkspaceId($contact);
        $matchClauses = [];
        $params = [$workspaceId, $contactId];

        if ($company !== '') {
            $matchClauses[] = "LOWER(company) = LOWER(?)";
            $params[] = $company;
        }
        if ($domain !== '') {
            $matchClauses[] = "LOWER(SUBSTRING_INDEX(email, '@', -1)) = LOWER(?)";
            $params[] = $domain;
        }

        $relatedContacts = [];
        if ($matchClauses !== []) {
            $relatedContacts = Database::query(
                "SELECT id, first_name, last_name, email, company, job_title, created_at
                 FROM contacts
                 WHERE workspace_id = ?
                   AND id <> ?
                   AND (" . implode(' OR ', $matchClauses) . ")
                 ORDER BY created_at DESC
                 LIMIT 8",
                $params
            );
        }

        $relatedIds = array_map(static fn (array $row): int => (int) $row['id'], $relatedContacts);
        $sharedDeals = !empty($relatedIds) ? Database::query(
            "SELECT id, title, stage, value, contact_id, created_at
             FROM deals
             WHERE (workspace_id = ? OR workspace_id IS NULL OR workspace_id = 0)
               AND contact_id IN (" . implode(',', array_fill(0, count($relatedIds), '?')) . ")
             ORDER BY created_at DESC
             LIMIT 8",
            array_merge([$workspaceId], $relatedIds)
        ) : [];
        $sharedInvoices = !empty($relatedIds) ? Database::query(
            "SELECT id, invoice_number, document_type, status, contact_id, created_at
             FROM invoices
             WHERE (workspace_id = ? OR workspace_id IS NULL OR workspace_id = 0)
               AND contact_id IN (" . implode(',', array_fill(0, count($relatedIds), '?')) . ")
             ORDER BY created_at DESC
             LIMIT 8",
            array_merge([$workspaceId], $relatedIds)
        ) : [];
        $sharedTasks = !empty($relatedIds) ? Database::query(
            "SELECT id, title, status, contact_id, created_at
             FROM tasks
             WHERE (workspace_id = ? OR workspace_id IS NULL OR workspace_id = 0)
               AND contact_id IN (" . implode(',', array_fill(0, count($relatedIds), '?')) . ")
             ORDER BY created_at DESC
             LIMIT 8",
            array_merge([$workspaceId], $relatedIds)
        ) : [];

        return [
            'company_name' => $company,
            'email_domain' => $domain,
            'related_contacts' => $relatedContacts,
            'shared_deals' => $sharedDeals,
            'shared_invoices' => $sharedInvoices,
            'shared_tasks' => $sharedTasks,
        ];
    }

    private function findLikelyDuplicates(array $contact): array
    {
        $contactId = (int) $contact['id'];
        $workspaceId = $this->resolveContactWorkspaceId($contact);
        if ($workspaceId <= 0) {
            return [];
        }

        $first = strtolower(trim((string) ($contact['first_name'] ?? '')));
        $last = strtolower(trim((string) ($contact['last_name'] ?? '')));
        $company = strtolower(trim((string) ($contact['company'] ?? '')));
        $domain = $this->extractDomain((string) ($contact['email'] ?? ''));
        $phoneDigits = preg_replace('/\D+/', '', (string) ($contact['phone'] ?? ''));

        $rows = Database::query(
            "SELECT id, first_name, last_name, email, phone, company
             FROM contacts
             WHERE workspace_id = ?
               AND id <> ?
             ORDER BY created_at DESC
             LIMIT 200",
            [$workspaceId, $contactId]
        );

        $duplicates = [];
        foreach ($rows as $row) {
            $score = 0;
            $otherFirst = strtolower(trim((string) ($row['first_name'] ?? '')));
            $otherLast = strtolower(trim((string) ($row['last_name'] ?? '')));
            $otherCompany = strtolower(trim((string) ($row['company'] ?? '')));
            $otherDomain = $this->extractDomain((string) ($row['email'] ?? ''));
            $otherPhone = preg_replace('/\D+/', '', (string) ($row['phone'] ?? ''));

            if ($first !== '' && $otherFirst === $first) {
                $score += 0.25;
            }
            if ($last !== '' && $otherLast === $last) {
                $score += 0.25;
            }
            if ($company !== '' && $otherCompany === $company) {
                $score += 0.2;
            }
            if ($domain !== '' && $otherDomain === $domain) {
                $score += 0.2;
            }
            if ($phoneDigits !== '' && $otherPhone !== '' && substr($phoneDigits, -7) === substr($otherPhone, -7)) {
                $score += 0.2;
            }

            similar_text($first . ' ' . $last, $otherFirst . ' ' . $otherLast, $namePct);
            if ($namePct >= 85) {
                $score += 0.15;
            }

            if ($score >= 0.5) {
                $row['duplicate_score'] = round(min(1, $score), 2);
                $duplicates[] = $row;
            }
        }

        usort($duplicates, static fn (array $a, array $b): int => ($b['duplicate_score'] <=> $a['duplicate_score']));
        return array_slice($duplicates, 0, 5);
    }

    private function resolveContactWorkspaceId(array $contact): int
    {
        $workspaceId = (int) ($contact['workspace_id'] ?? 0);
        if ($workspaceId > 0) {
            return $workspaceId;
        }

        try {
            return (new WorkspaceScopeService())->requireActiveWorkspaceId();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * @return array<int, int>
     */
    private function duplicateIdsFromIntelligence(array $intelligence): array
    {
        $duplicates = $intelligence['data_quality']['likely_duplicates'] ?? $intelligence['duplicates'] ?? [];
        if (!is_array($duplicates)) {
            return [];
        }

        $ids = [];
        foreach ($duplicates as $duplicate) {
            if (is_array($duplicate) && !empty($duplicate['id'])) {
                $ids[] = (int) $duplicate['id'];
            }
        }

        return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
    }

    /**
     * @param array<int, bool>|null $validDuplicateIds
     */
    private function sanitizeStoredIntelligence(array $intelligence, int $workspaceId, ?array $validDuplicateIds = null): array
    {
        $duplicates = $intelligence['data_quality']['likely_duplicates'] ?? [];
        if (!is_array($duplicates) || $duplicates === []) {
            return $intelligence;
        }

        if ($validDuplicateIds === null) {
            $duplicateIds = $this->duplicateIdsFromIntelligence($intelligence);
            if ($workspaceId <= 0 || $duplicateIds === []) {
                $validDuplicateIds = [];
            } else {
                $placeholders = implode(',', array_fill(0, count($duplicateIds), '?'));
                $rows = Database::query(
                    "SELECT id FROM contacts WHERE workspace_id = ? AND id IN ($placeholders)",
                    array_merge([$workspaceId], $duplicateIds)
                );
                $validDuplicateIds = array_fill_keys(
                    array_map(static fn (array $row): int => (int) $row['id'], $rows),
                    true
                );
            }
        }

        $filterDuplicate = static function (mixed $duplicate) use ($validDuplicateIds): bool {
            return is_array($duplicate)
                && !empty($duplicate['id'])
                && isset($validDuplicateIds[(int) $duplicate['id']]);
        };

        $cleanDuplicates = array_values(array_filter($duplicates, $filterDuplicate));
        $intelligence['data_quality']['likely_duplicates'] = $cleanDuplicates;

        if (isset($intelligence['duplicates']) && is_array($intelligence['duplicates'])) {
            $intelligence['duplicates'] = array_values(array_filter($intelligence['duplicates'], $filterDuplicate));
        }

        return $intelligence;
    }

    private function buildRelationshipEvidence(array $summary): array
    {
        $touches = [];
        $addTouch = static function (string $source, ?array $item) use (&$touches): void {
            if (!$item || empty($item['created_at'])) {
                return;
            }

            $timestamp = strtotime((string) $item['created_at']);
            if ($timestamp === false) {
                return;
            }

            $touches[] = [
                'source' => $source,
                'created_at' => (string) $item['created_at'],
                'timestamp' => $timestamp,
            ];
        };

        $addTouch('inbound_reply', $summary['last_inbound_reply'] ?? null);
        $addTouch('outbound_reply', $summary['last_outbound_reply'] ?? null);
        $addTouch('note', $summary['last_note'] ?? null);
        $addTouch('meeting', $summary['last_meeting'] ?? null);
        $addTouch('invoice', $summary['last_quote_or_invoice'] ?? null);
        $addTouch('task', $summary['last_open_task'] ?? null);
        $addTouch('deal', $summary['last_deal'] ?? null);

        usort($touches, static fn (array $a, array $b): int => $b['timestamp'] <=> $a['timestamp']);

        $lastInboundAt = !empty($summary['last_inbound_reply']['created_at'])
            ? strtotime((string) $summary['last_inbound_reply']['created_at'])
            : null;
        $lastOutboundAt = !empty($summary['last_outbound_reply']['created_at'])
            ? strtotime((string) $summary['last_outbound_reply']['created_at'])
            : null;
        $awaitingResponseSince = null;
        if ($lastOutboundAt !== null && ($lastInboundAt === null || $lastInboundAt < $lastOutboundAt)) {
            $awaitingResponseSince = (string) ($summary['last_outbound_reply']['created_at'] ?? '');
        }

        $latest = $touches[0] ?? null;
        $staleDays = $latest !== null ? (int) floor((time() - (int) $latest['timestamp']) / 86400) : null;

        return [
            'has_engagement_evidence' => $touches !== [],
            'last_touch_at' => $latest['created_at'] ?? null,
            'last_touch_source' => $latest['source'] ?? null,
            'awaiting_response_since' => $awaitingResponseSince !== '' ? $awaitingResponseSince : null,
            'stale_days' => $staleDays,
        ];
    }

    private function buildDataQuality(array $contact, array $duplicates, array $relationshipEvidence): array
    {
        $missing = [];
        foreach (['phone', 'company', 'job_title', 'location'] as $field) {
            if (empty($contact[$field])) {
                $missing[] = $field;
            }
        }

        $hasEngagementEvidence = !empty($relationshipEvidence['has_engagement_evidence']);
        $staleDays = isset($relationshipEvidence['stale_days']) ? (int) $relationshipEvidence['stale_days'] : null;

        return [
            'missing_critical_fields' => $missing,
            'is_incomplete' => count($missing) >= 2,
            'is_stale' => $hasEngagementEvidence && $staleDays !== null && $staleDays >= 21,
            'stale_days' => $staleDays,
            'has_engagement_evidence' => $hasEngagementEvidence,
            'last_touch_at' => $relationshipEvidence['last_touch_at'] ?? null,
            'last_touch_source' => $relationshipEvidence['last_touch_source'] ?? null,
            'awaiting_response_since' => $relationshipEvidence['awaiting_response_since'] ?? null,
            'likely_duplicates' => $duplicates,
        ];
    }

    private function detectBuyingRole(array $contact, array $communication): array
    {
        $metadata = $this->decodeJson($contact['metadata_json'] ?? null);
        $manualRole = $metadata['contact_intelligence']['buying_role']['manual_role'] ?? null;
        if (is_string($manualRole) && $manualRole !== '') {
            return [
                'suggested_role' => $manualRole,
                'manual_role' => $manualRole,
                'source' => 'manual_override',
                'editable' => true,
            ];
        }

        $text = strtolower(trim((string) (($contact['job_title'] ?? '') . ' ' . $communication['what_matters_now'])));
        $role = 'unknown';

        if (preg_match('/\b(finance|accountant|accounts|procurement|billing|payables|cfo)\b/i', $text)) {
            $role = 'finance';
        } elseif (preg_match('/\b(ceo|founder|owner|president|director|head|chief|vp)\b/i', $text)) {
            $role = 'decision_maker';
        } elseif (preg_match('/\b(champion|internal sponsor|supports us|advocating)\b/i', $text)) {
            $role = 'champion';
        } elseif (preg_match('/\b(blocker|resisting|opposed|not convinced|concerned)\b/i', $text)) {
            $role = 'blocker';
        } elseif (preg_match('/\b(manager|lead|specialist|coordinator)\b/i', $text)) {
            $role = 'influencer';
        }

        return [
            'suggested_role' => $role,
            'source' => 'rules',
            'editable' => true,
        ];
    }

    private function buildRelationshipHealth(array $contact, array $summary, array $communication, array $account, array $dataQuality, array $relationshipEvidence): array
    {
        $score = 100;
        $riskFlags = [];
        $now = time();

        $lastInboundAt = !empty($summary['last_inbound_reply']['created_at']) ? strtotime((string) $summary['last_inbound_reply']['created_at']) : null;
        $lastOutboundAt = !empty($summary['last_outbound_reply']['created_at']) ? strtotime((string) $summary['last_outbound_reply']['created_at']) : null;
        $awaitingResponseAt = !empty($relationshipEvidence['awaiting_response_since']) ? strtotime((string) $relationshipEvidence['awaiting_response_since']) : null;

        if ($awaitingResponseAt !== null && ($now - $awaitingResponseAt) > 21 * 86400) {
            $score -= 25;
            $riskFlags[] = 'No response in 21 days';
        }

        if (!empty($summary['last_open_task']['due_date']) && strtotime((string) $summary['last_open_task']['due_date']) < $now) {
            $score -= 15;
            $riskFlags[] = 'Overdue follow-up task';
        }

        foreach ($account['shared_deals'] as $deal) {
            if (in_array((string) ($deal['stage'] ?? ''), ['proposal', 'negotiation'], true) && ((float) ($deal['value'] ?? 0) >= 1000)) {
                if ($lastOutboundAt !== null && ($now - $lastOutboundAt) > 7 * 86400) {
                    $score -= 15;
                    $riskFlags[] = 'High-value deal with no recent follow-up';
                    break;
                }
            }
        }

        foreach ($account['shared_invoices'] as $invoice) {
            if (in_array((string) ($invoice['status'] ?? ''), ['sent', 'viewed', 'accepted'], true) && $lastInboundAt !== null && strtotime((string) $invoice['created_at']) > $lastInboundAt) {
                $score -= 10;
                $riskFlags[] = 'Invoice sent without newer customer response';
                break;
            }
        }

        if ($dataQuality['is_stale']) {
            $score -= 15;
            $riskFlags[] = 'Contact is stale';
        }

        if (($communication['sentiment_trend']['label'] ?? 'neutral') === 'negative') {
            $score -= 10;
            $riskFlags[] = 'Negative sentiment trend';
        }

        if (!empty($communication['unresolved_commitments'])) {
            $score -= 10;
            $riskFlags[] = 'Unresolved commitments';
        }

        $score = max(0, min(100, $score));
        $band = $score >= 75 ? 'healthy' : ($score >= 55 ? 'at_risk' : ($score >= 35 ? 'stale' : 'urgent'));

        return [
            'score' => $score,
            'band' => $band,
            'risk_flags' => array_values(array_unique($riskFlags)),
        ];
    }

    private function buildNextActions(array $summary, array $communication, array $health, array $dataQuality, array $relationshipEvidence): array
    {
        $actions = [];
        if (empty($relationshipEvidence['has_engagement_evidence'])) {
            $actions[] = 'Log the first outreach or create the first next step for this contact.';
        }
        if (in_array('No response in 21 days', $health['risk_flags'], true)) {
            $actions[] = 'Send a re-engagement email or WhatsApp follow-up.';
        }
        if (!empty($communication['unresolved_commitments'])) {
            $actions[] = 'Close or update the latest outstanding commitment.';
        }
        if ($dataQuality['is_incomplete']) {
            $actions[] = 'Complete missing critical fields for better qualification.';
        }
        if (!empty($summary['last_open_task']['title'])) {
            $actions[] = 'Complete or reschedule the open follow-up task: ' . $summary['last_open_task']['title'] . '.';
        }
        if (empty($actions)) {
            $actions[] = 'Review the latest thread summary and decide the next outreach step.';
        }
        return array_values(array_unique($actions));
    }

    private function formatSummaryItem(?array $row, string $type): ?array
    {
        if (!$row) {
            return null;
        }

        $title = match ($type) {
            'communication' => (string) (($row['subject'] ?? '') !== '' ? $row['subject'] : $this->truncate(strip_tags((string) ($row['body'] ?? '')), 80)),
            'meeting' => (string) ($row['title'] ?? 'Meeting'),
            'invoice' => ucfirst((string) ($row['document_type'] ?? 'invoice')) . ' ' . (string) ($row['invoice_number'] ?? ('#' . ($row['id'] ?? ''))),
            'note' => (string) (($row['title'] ?? '') !== '' ? $row['title'] : $this->truncate(strip_tags((string) ($row['content'] ?? '')), 80)),
            'task' => (string) ($row['title'] ?? 'Open task'),
            'deal' => 'Deal: ' . (string) ($row['title'] ?? ('#' . ($row['id'] ?? ''))),
            default => 'Item',
        };

        $createdAt = (string) ($row['created_at'] ?? '');
        if ($type === 'meeting') {
            $createdAt = (string) ($row['event_time'] ?? ($row['event_date'] ?? $createdAt));
        } elseif ($type === 'deal') {
            $createdAt = (string) ($row['updated_at'] ?? $createdAt);
        }

        return [
            'id' => (int) ($row['id'] ?? 0),
            'title' => $title,
            'created_at' => $createdAt,
            'status' => $row['status'] ?? null,
            'stage' => $row['stage'] ?? null,
            'value' => $row['value'] ?? null,
            'channel' => $row['channel'] ?? null,
            'due_date' => $row['due_date'] ?? null,
        ];
    }

    private function buildResponseCadence(array $communications): array
    {
        $inbound = array_values(array_filter($communications, static fn (array $row): bool => ($row['direction'] ?? '') === 'inbound'));
        if (count($inbound) < 2) {
            return ['label' => 'insufficient_data'];
        }

        $days = [];
        for ($i = 0; $i < count($inbound) - 1; $i++) {
            $a = strtotime((string) $inbound[$i]['created_at']);
            $b = strtotime((string) $inbound[$i + 1]['created_at']);
            if ($a && $b) {
                $days[] = abs($a - $b) / 86400;
            }
        }

        if (empty($days)) {
            return ['label' => 'insufficient_data'];
        }

        $avg = array_sum($days) / count($days);
        return [
            'label' => $avg <= 3 ? 'fast' : ($avg <= 10 ? 'steady' : 'slow'),
            'average_days_between_replies' => round($avg, 1),
        ];
    }

    private function extractStructuredSignals(array $items, array $patterns, bool $commitments = false): array
    {
        $signals = [];
        foreach ($items as $item) {
            $text = strtolower((string) ($item['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            foreach ($patterns as $topic => $keywords) {
                foreach ($keywords as $keyword) {
                    if (str_contains($text, strtolower($keyword))) {
                        $signals[] = [
                            'topic' => $topic,
                            'evidence_source' => $item['source_type'] . ':' . $item['source_id'],
                            'last_seen_at' => $item['created_at'],
                            'status' => $commitments ? 'open' : 'observed',
                            'excerpt' => $this->truncate((string) $item['text'], 160),
                        ];
                        break;
                    }
                }
            }
        }

        $unique = [];
        foreach ($signals as $signal) {
            $key = $signal['topic'] . '|' . $signal['evidence_source'];
            $unique[$key] = $signal;
        }

        return array_values($unique);
    }

    private function buildWhatMattersNow(array $communications, array $notes, array $objections, array $commitments): string
    {
        $parts = [];
        if (!empty($objections)) {
            $parts[] = 'Current objections center on ' . implode(', ', array_unique(array_column($objections, 'topic'))) . '.';
        }
        if (!empty($commitments)) {
            $parts[] = 'Outstanding commitments include ' . implode(', ', array_unique(array_column($commitments, 'topic'))) . '.';
        }
        if (!empty($communications[0]['subject'])) {
            $parts[] = 'Latest thread topic: ' . $communications[0]['subject'] . '.';
        }
        if (!empty($notes[0]['title'])) {
            $parts[] = 'Latest note: ' . $notes[0]['title'] . '.';
        }

        return $parts ? implode(' ', $parts) : 'No strong active thread signals detected yet.';
    }

    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function extractDomain(string $email): string
    {
        if ($email === '' || strpos($email, '@') === false) {
            return '';
        }
        return strtolower(substr(strrchr($email, '@'), 1));
    }

    private function emailDomainClassifier(): EmailDomainClassifier
    {
        return $this->emailDomainClassifier ??= new EmailDomainClassifier();
    }

    private function truncate(string $text, int $length): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text));
        if ($text === '') {
            return '';
        }
        return mb_strlen($text) > $length ? mb_substr($text, 0, $length - 1) . '…' : $text;
    }
}
