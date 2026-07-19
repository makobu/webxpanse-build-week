<?php

namespace CRM\Services;

use CRM\Database;

class OwnerHelpQuoteService
{
    private const STATUSES = ['draft', 'sent', 'accepted', 'changes_requested', 'withdrawn', 'expired'];

    private DefaultWorkspaceOwnerSupportService $support;
    private OperatorAuditService $audit;

    public function __construct(?DefaultWorkspaceOwnerSupportService $support = null, ?OperatorAuditService $audit = null)
    {
        $this->support = $support ?: new DefaultWorkspaceOwnerSupportService();
        $this->audit = $audit ?: new OperatorAuditService();
    }

    /**
     * @return array<string,mixed>|null
     */
    public function activeQuoteForOwnerCase(int $workspaceId, int $userId, int $eventId): ?array
    {
        if (!$this->schemaReady()) {
            return null;
        }

        $case = $this->support->ownerCase($workspaceId, $userId, $eventId);
        $requestId = (int) (($case['help_request']['id'] ?? 0));
        if ($requestId <= 0) {
            return null;
        }

        $row = Database::queryOne(
            "SELECT *
             FROM owner_help_quotes
             WHERE service_request_id = ?
               AND status IN ('sent','accepted','changes_requested')
             ORDER BY FIELD(status, 'accepted', 'sent', 'changes_requested'), id DESC
             LIMIT 1",
            [$requestId]
        );

        return $row ? $this->normalizeQuote($row) : null;
    }

    /**
     * @param array<string,mixed>|null $actor
     * @return array<int,array<string,mixed>>
     */
    public function quotesForAdminCase(?array $actor, int $eventId): array
    {
        if (!$this->schemaReady()) {
            return [];
        }

        $case = $this->support->adminCase($actor, $eventId);
        $requestId = (int) (($case['help_request']['id'] ?? 0));
        if ($requestId <= 0) {
            return [];
        }

        return array_map(fn(array $row): array => $this->normalizeQuote($row), Database::query(
            "SELECT *
             FROM owner_help_quotes
             WHERE service_request_id = ?
             ORDER BY id DESC",
            [$requestId]
        ));
    }

    /**
     * @param array<string,mixed>|null $actor
     * @param array<string,mixed> $data
     * @param array<int,array<string,mixed>> $items
     * @return array<string,mixed>
     */
    public function saveDraft(?array $actor, int $eventId, array $data, array $items): array
    {
        $this->assertSchemaReady();
        $case = $this->support->adminCase($actor, $eventId);
        $help = (array) ($case['help_request'] ?? []);
        $requestId = (int) ($help['id'] ?? 0);
        if ($requestId <= 0) {
            throw new \RuntimeException('Help request metadata was not found.');
        }

        $quoteId = max(0, (int) ($data['quote_id'] ?? 0));
        $existing = $quoteId > 0 ? $this->quoteForAdminCase($actor, $eventId, $quoteId) : null;
        if ($quoteId > 0 && !$existing) {
            throw new \RuntimeException('Quote was not found.');
        }

        $currency = strtoupper($this->cleanSingleLine((string) ($data['currency'] ?? 'KES'), 8));
        if ($currency === '') {
            $currency = 'KES';
        }
        $title = $this->cleanSingleLine((string) ($data['title'] ?? 'Support service quote'), 180);
        if ($title === '') {
            throw new \RuntimeException('Quote title is required.');
        }

        $normalizedItems = $this->normalizeItems($items);
        if ($normalizedItems === []) {
            throw new \RuntimeException('Add at least one quote line item.');
        }
        $total = array_sum(array_map(static fn(array $item): float => (float) $item['line_total'], $normalizedItems));

        $validUntil = $this->normalizeDate((string) ($data['valid_until'] ?? ''));
        $payload = [
            'title' => $title,
            'scope_summary' => $this->cleanMultiline((string) ($data['scope_summary'] ?? '')),
            'owner_visible_notes' => $this->cleanMultiline((string) ($data['owner_visible_notes'] ?? '')),
            'internal_notes' => $this->cleanMultiline((string) ($data['internal_notes'] ?? '')),
            'terms' => $this->cleanMultiline((string) ($data['terms'] ?? '')),
            'currency' => $currency,
            'subtotal_amount' => $total,
            'total_amount' => $total,
            'valid_until' => $validUntil,
        ];

        $actorId = (int) ($actor['id'] ?? 0);

        Database::beginTransaction();
        try {
            if ($quoteId > 0) {
                Database::execute(
                    "UPDATE owner_help_quotes
                     SET title = ?, scope_summary = ?, owner_visible_notes = ?, internal_notes = ?, terms = ?,
                         currency = ?, subtotal_amount = ?, total_amount = ?, valid_until = ?, updated_by = ?,
                         updated_at = NOW()
                     WHERE id = ? AND service_request_id = ?",
                    [
                        $payload['title'],
                        $payload['scope_summary'],
                        $payload['owner_visible_notes'],
                        $payload['internal_notes'],
                        $payload['terms'],
                        $payload['currency'],
                        $payload['subtotal_amount'],
                        $payload['total_amount'],
                        $payload['valid_until'],
                        $actorId ?: null,
                        $quoteId,
                        $requestId,
                    ]
                );
                Database::execute("DELETE FROM owner_help_quote_items WHERE quote_id = ?", [$quoteId]);
            } else {
                Database::execute(
                    "INSERT INTO owner_help_quotes
                        (service_request_id, ops_event_id, quote_number, title, scope_summary, owner_visible_notes,
                         internal_notes, terms, currency, subtotal_amount, total_amount, status, valid_until,
                         created_by, updated_by, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, ?, ?, NOW(), NOW())",
                    [
                        $requestId,
                        $eventId,
                        $this->generateQuoteNumber($eventId),
                        $payload['title'],
                        $payload['scope_summary'],
                        $payload['owner_visible_notes'],
                        $payload['internal_notes'],
                        $payload['terms'],
                        $payload['currency'],
                        $payload['subtotal_amount'],
                        $payload['total_amount'],
                        $payload['valid_until'],
                        $actorId ?: null,
                        $actorId ?: null,
                    ]
                );
                $quoteId = (int) Database::lastInsertId();
            }

            foreach ($normalizedItems as $item) {
                Database::execute(
                    "INSERT INTO owner_help_quote_items
                        (quote_id, item_label, item_description, quantity, unit_price, line_total, sort_order, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
                    [
                        $quoteId,
                        $item['item_label'],
                        $item['item_description'],
                        $item['quantity'],
                        $item['unit_price'],
                        $item['line_total'],
                        $item['sort_order'],
                    ]
                );
            }

            Database::commit();
        } catch (\Throwable $e) {
            if (Database::getInstance()->inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }

        $this->audit->log('owner_help_quote_saved', $actorId ?: null, (int) ($case['owner_workspace_id'] ?? 0), 'Owner help quote saved.', [
            'ops_event_id' => $eventId,
            'quote_id' => $quoteId,
            'quote_total' => $total,
            'currency' => $currency,
        ]);

        return $this->quoteForAdminCase($actor, $eventId, $quoteId) ?? [];
    }

    /**
     * @param array<string,mixed>|null $actor
     * @return array<string,mixed>
     */
    public function sendQuote(?array $actor, int $eventId, int $quoteId): array
    {
        $this->assertSchemaReady();
        $case = $this->support->adminCase($actor, $eventId);
        $quote = $this->quoteForAdminCase($actor, $eventId, $quoteId);
        if (!$quote) {
            throw new \RuntimeException('Quote was not found.');
        }
        if (!in_array((string) ($quote['status'] ?? ''), ['draft', 'changes_requested', 'sent'], true)) {
            throw new \RuntimeException('Only draft or change-requested quotes can be sent.');
        }

        Database::execute(
            "UPDATE owner_help_quotes
             SET status = 'sent', sent_at = COALESCE(sent_at, NOW()), updated_by = ?, updated_at = NOW()
             WHERE id = ?",
            [(int) ($actor['id'] ?? 0) ?: null, $quoteId]
        );

        $this->support->addOperatorReply(
            $actor,
            $eventId,
            "A quote has been sent for review.\n\n" .
            'Quote: ' . (string) ($quote['title'] ?? 'Support service quote') . "\n" .
            'Amount: ' . $this->formatAmount((string) ($quote['currency'] ?? 'KES'), (float) ($quote['total_amount'] ?? 0)) . "\n" .
            "Open this request to accept it or request changes."
        );
        $this->applyRequestState($eventId, 'quoted', 'quoted', 'in_progress');

        $this->audit->log('owner_help_quote_sent', (int) ($actor['id'] ?? 0) ?: null, (int) ($case['owner_workspace_id'] ?? 0), 'Owner help quote sent.', [
            'ops_event_id' => $eventId,
            'quote_id' => $quoteId,
        ]);

        return $this->quoteForAdminCase($actor, $eventId, $quoteId) ?? [];
    }

    /**
     * @return array<string,mixed>
     */
    public function acceptQuote(int $workspaceId, int $userId, int $eventId, int $quoteId): array
    {
        $this->assertSchemaReady();
        $case = $this->support->ownerCase($workspaceId, $userId, $eventId);
        $quote = $this->quoteForOwnerCase($workspaceId, $userId, $eventId, $quoteId);
        if (!$quote) {
            throw new \RuntimeException('Quote was not found.');
        }
        if (!in_array((string) ($quote['status'] ?? ''), ['sent', 'changes_requested'], true)) {
            throw new \RuntimeException('This quote is not ready for acceptance.');
        }

        Database::execute(
            "UPDATE owner_help_quotes
             SET status = 'accepted', accepted_at = NOW(), updated_at = NOW()
             WHERE id = ?",
            [$quoteId]
        );

        $this->support->addOwnerReply(
            $workspaceId,
            $userId,
            $eventId,
            'Quote accepted: ' . (string) ($quote['title'] ?? 'Support service quote')
        );
        $this->applyRequestState($eventId, 'accepted', 'assigned', 'in_progress');

        $this->audit->log('owner_help_quote_accepted', $userId ?: null, $workspaceId, 'Owner accepted support quote.', [
            'ops_event_id' => $eventId,
            'quote_id' => $quoteId,
            'quote_total' => (float) ($quote['total_amount'] ?? 0),
            'currency' => (string) ($quote['currency'] ?? ''),
        ]);

        return $this->quoteForOwnerCase($workspaceId, $userId, $eventId, $quoteId) ?? [];
    }

    /**
     * @return array<string,mixed>
     */
    public function requestChanges(int $workspaceId, int $userId, int $eventId, int $quoteId, string $note): array
    {
        $this->assertSchemaReady();
        $quote = $this->quoteForOwnerCase($workspaceId, $userId, $eventId, $quoteId);
        if (!$quote) {
            throw new \RuntimeException('Quote was not found.');
        }
        if (!in_array((string) ($quote['status'] ?? ''), ['sent', 'changes_requested'], true)) {
            throw new \RuntimeException('Changes can only be requested on a sent quote.');
        }

        $note = $this->cleanMultiline($note);
        if ($note === '') {
            throw new \RuntimeException('Please describe the requested quote change.');
        }

        Database::execute(
            "UPDATE owner_help_quotes
             SET status = 'changes_requested', changes_requested_at = NOW(), updated_at = NOW()
             WHERE id = ?",
            [$quoteId]
        );

        $this->support->addOwnerReply(
            $workspaceId,
            $userId,
            $eventId,
            "Quote changes requested:\n\n" . $note
        );
        $this->applyRequestState($eventId, 'quoted', 'waiting_on_owner', 'waiting_on_owner');

        $this->audit->log('owner_help_quote_changes_requested', $userId ?: null, $workspaceId, 'Owner requested quote changes.', [
            'ops_event_id' => $eventId,
            'quote_id' => $quoteId,
        ]);

        return $this->quoteForOwnerCase($workspaceId, $userId, $eventId, $quoteId) ?? [];
    }

    /**
     * @param array<string,mixed>|null $actor
     * @return array<string,mixed>|null
     */
    public function quoteForAdminCase(?array $actor, int $eventId, int $quoteId): ?array
    {
        if (!$this->schemaReady()) {
            return null;
        }

        $case = $this->support->adminCase($actor, $eventId);
        $requestId = (int) (($case['help_request']['id'] ?? 0));
        if ($requestId <= 0 || $quoteId <= 0) {
            return null;
        }

        $row = Database::queryOne(
            "SELECT * FROM owner_help_quotes WHERE id = ? AND service_request_id = ? LIMIT 1",
            [$quoteId, $requestId]
        );

        return $row ? $this->normalizeQuote($row) : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function quoteForOwnerCase(int $workspaceId, int $userId, int $eventId, int $quoteId): ?array
    {
        $case = $this->support->ownerCase($workspaceId, $userId, $eventId);
        $requestId = (int) (($case['help_request']['id'] ?? 0));
        if ($requestId <= 0 || $quoteId <= 0) {
            return null;
        }

        $row = Database::queryOne(
            "SELECT * FROM owner_help_quotes WHERE id = ? AND service_request_id = ? LIMIT 1",
            [$quoteId, $requestId]
        );

        return $row ? $this->normalizeQuote($row) : null;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function normalizeQuote(array $row): array
    {
        $quoteId = (int) ($row['id'] ?? 0);
        return [
            'id' => $quoteId,
            'service_request_id' => (int) ($row['service_request_id'] ?? 0),
            'ops_event_id' => (int) ($row['ops_event_id'] ?? 0),
            'quote_number' => (string) ($row['quote_number'] ?? ''),
            'title' => (string) ($row['title'] ?? 'Support service quote'),
            'scope_summary' => (string) ($row['scope_summary'] ?? ''),
            'owner_visible_notes' => (string) ($row['owner_visible_notes'] ?? ''),
            'internal_notes' => (string) ($row['internal_notes'] ?? ''),
            'terms' => (string) ($row['terms'] ?? ''),
            'currency' => (string) ($row['currency'] ?? 'KES'),
            'subtotal_amount' => (float) ($row['subtotal_amount'] ?? 0),
            'total_amount' => (float) ($row['total_amount'] ?? 0),
            'status' => $this->normalizeStatus((string) ($row['status'] ?? 'draft')),
            'valid_until' => (string) ($row['valid_until'] ?? ''),
            'sent_at' => (string) ($row['sent_at'] ?? ''),
            'accepted_at' => (string) ($row['accepted_at'] ?? ''),
            'changes_requested_at' => (string) ($row['changes_requested_at'] ?? ''),
            'created_by' => isset($row['created_by']) ? (int) $row['created_by'] : null,
            'updated_by' => isset($row['updated_by']) ? (int) $row['updated_by'] : null,
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
            'items' => $this->itemsForQuote($quoteId),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function itemsForQuote(int $quoteId): array
    {
        if ($quoteId <= 0 || !Database::tableExists('owner_help_quote_items')) {
            return [];
        }

        return array_map(static fn(array $row): array => [
            'id' => (int) ($row['id'] ?? 0),
            'item_label' => (string) ($row['item_label'] ?? ''),
            'item_description' => (string) ($row['item_description'] ?? ''),
            'quantity' => (float) ($row['quantity'] ?? 1),
            'unit_price' => (float) ($row['unit_price'] ?? 0),
            'line_total' => (float) ($row['line_total'] ?? 0),
            'sort_order' => (int) ($row['sort_order'] ?? 100),
        ], Database::query(
            "SELECT * FROM owner_help_quote_items WHERE quote_id = ? ORDER BY sort_order ASC, id ASC",
            [$quoteId]
        ));
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @return array<int,array<string,mixed>>
     */
    private function normalizeItems(array $items): array
    {
        $normalized = [];
        foreach ($items as $index => $item) {
            $label = $this->cleanSingleLine((string) ($item['item_label'] ?? ''), 180);
            $quantity = max(0.01, round((float) ($item['quantity'] ?? 1), 2));
            $unitPrice = max(0, round((float) ($item['unit_price'] ?? 0), 2));
            if ($label === '' && $unitPrice <= 0) {
                continue;
            }
            if ($label === '') {
                throw new \RuntimeException('Each priced quote item needs a label.');
            }
            $lineTotal = round($quantity * $unitPrice, 2);
            $normalized[] = [
                'item_label' => $label,
                'item_description' => $this->cleanMultiline((string) ($item['item_description'] ?? '')),
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
                'sort_order' => (int) ($item['sort_order'] ?? (($index + 1) * 10)),
            ];
        }

        return $normalized;
    }

    private function applyRequestState(int $eventId, string $pricingState, string $lifecycleStatus, string $caseStatus): void
    {
        Database::execute(
            "UPDATE owner_help_service_requests
             SET pricing_state = ?, lifecycle_status = ?, updated_at = NOW()
             WHERE ops_event_id = ?",
            [$pricingState, $lifecycleStatus, $eventId]
        );
        Database::execute(
            "UPDATE default_workspace_ops_events
             SET status = ?, last_seen_at = NOW(), updated_at = NOW()
             WHERE id = ?",
            [$caseStatus, $eventId]
        );
    }

    private function generateQuoteNumber(int $eventId): string
    {
        return 'OHQ-' . date('Ymd') . '-' . $eventId . '-' . strtoupper(bin2hex(random_bytes(2)));
    }

    private function formatAmount(string $currency, float $amount): string
    {
        return trim($currency) . ' ' . number_format($amount, 2);
    }

    private function normalizeDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        $ts = strtotime($value);
        if ($ts === false) {
            throw new \RuntimeException('Quote expiry date is not valid.');
        }
        return date('Y-m-d', $ts);
    }

    private function normalizeStatus(string $status): string
    {
        return in_array($status, self::STATUSES, true) ? $status : 'draft';
    }

    private function cleanSingleLine(string $value, int $maxLength): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $maxLength);
        }
        return substr($value, 0, $maxLength);
    }

    private function cleanMultiline(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", trim($value));
        return preg_replace("/\n{3,}/", "\n\n", $value) ?? '';
    }

    private function schemaReady(): bool
    {
        return Database::tableExists('owner_help_quotes')
            && Database::tableExists('owner_help_quote_items')
            && Database::tableExists('owner_help_service_requests');
    }

    private function assertSchemaReady(): void
    {
        if (!$this->schemaReady()) {
            throw new \RuntimeException('Owner help quote storage is not available.');
        }
    }
}
