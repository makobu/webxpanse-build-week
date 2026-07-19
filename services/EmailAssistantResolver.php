<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\Invoices;
use CRM\Services\WorkspaceContext;

class EmailAssistantResolver
{
    private Invoices $invoices;
    private EmailAssistantThreadContextService $threadContext;

    public function __construct()
    {
        $this->invoices = new Invoices();
        $this->threadContext = new EmailAssistantThreadContextService();
    }

    public function resolveFromAdminEmail(string $body, int $userId): array
    {
        $body = trim($body);
        $dealId = $this->extractIntegerAfterKeyword($body, 'deal');
        $approvalId = $this->extractIntegerAfterKeyword($body, 'approval');

        $deal = $dealId > 0 ? $this->queryOneInWorkspace('deals', 'id = ?', [$dealId]) : null;
        $invoiceResolution = $this->resolveInvoice($body, ['deal' => $deal ?: []]);
        $invoice = $invoiceResolution['primary_entities']['invoice'] ?? null;

        if (!$deal && !empty($invoice['deal_id'])) {
            $deal = $this->queryOneInWorkspace('deals', 'id = ?', [(int) $invoice['deal_id']]) ?: null;
        }

        $contact = null;
        if ($deal && !empty($deal['contact_id'])) {
            $contact = $this->queryOneInWorkspace('contacts', 'id = ?', [(int) $deal['contact_id']]) ?: null;
        } elseif (!empty($invoice['contact_id'])) {
            $contact = $this->queryOneInWorkspace('contacts', 'id = ?', [(int) $invoice['contact_id']]) ?: null;
        } else {
            $contact = $this->resolveContactFromBody($body);
        }

        $approval = $approvalId > 0
            ? $this->queryOneInWorkspace('commercial_automation_approvals', 'id = ?', [$approvalId])
            : null;

        $ambiguities = $invoiceResolution['ambiguities'] ?? [];
        $confidence = (float) ($invoiceResolution['confidence'] ?? 0.6);
        if (!$deal && !$invoice && !$contact && !$approval) {
            $ambiguities[] = 'No specific deal, contact, invoice, or approval could be resolved.';
            $confidence = 0.35;
        }

        return [
            'resolved' => empty($ambiguities),
            'confidence' => $confidence,
            'primary_entities' => [
                'contact' => $contact,
                'deal' => $deal,
                'invoice' => $invoice,
                'approval' => $approval,
            ],
            'candidates' => [
                'invoice' => $invoiceResolution['candidates']['invoice'] ?? [],
            ],
            'ambiguities' => $ambiguities,
            'reasons' => $ambiguities ?: ['resolved'],
        ];
    }

    public function resolveFromCustomerThread(int $communicationId, array $threadMessages = []): array
    {
        $context = $this->threadContext->buildForCommunication($communicationId);
        if (empty($context)) {
            return [
                'resolved' => false,
                'confidence' => 0.0,
                'primary_entities' => [],
                'candidates' => [],
                'ambiguities' => ['Communication thread not found.'],
                'reasons' => ['missing_thread'],
                'thread_context' => [],
            ];
        }

        return [
            'resolved' => !empty($context['contact']),
            'confidence' => !empty($context['contact']) ? 0.92 : 0.4,
            'primary_entities' => [
                'contact' => $context['contact'] ?? null,
                'deal' => $context['deal'] ?? null,
                'invoice' => $context['invoice'] ?? null,
                'thread' => [
                    'thread_id' => $context['thread_id'] ?? null,
                    'communication_id' => $communicationId,
                ],
            ],
            'candidates' => [],
            'ambiguities' => empty($context['contact']) ? ['Contact could not be linked to the conversation thread.'] : [],
            'reasons' => ['thread_context'],
            'thread_context' => $context,
        ];
    }

    public function resolveInvoice(string $query, array $context = []): array
    {
        $query = trim($query);
        $candidates = [];
        $invoice = null;
        $ambiguities = [];

        if (preg_match('/\b([A-Z]{1,6}-?\d{2,})\b/', strtoupper($query), $m)) {
            $row = $this->queryOneInWorkspace('invoices', 'UPPER(invoice_number) = ?', [strtoupper($m[1])], 'id');
            if ($row) {
                $invoice = $this->invoices->getById((int) $row['id']);
            }
        }

        if (!$invoice && !empty($context['deal']['id'])) {
            $invoice = $this->invoices->findLatestForDeal((int) $context['deal']['id']);
        }

        if (!$invoice) {
            $type = null;
            if (preg_match('/\bquote\b/i', $query)) {
                $type = 'quote';
            } elseif (preg_match('/\bproforma\b/i', $query)) {
                $type = 'proforma';
            } elseif (preg_match('/\binvoice\b/i', $query)) {
                $type = 'invoice';
            }

            $params = [];
            $sql = "SELECT id, invoice_number, document_type, status, created_at FROM invoices";
            $workspace = $this->workspaceCondition();
            $where = [];
            if ($workspace !== null) {
                $where[] = 'workspace_id = ?';
                $params[] = $workspace;
            }
            if ($type) {
                $where[] = 'document_type = ?';
                $params[] = $type;
            }
            if ($where !== []) {
                $sql .= ' WHERE ' . implode(' AND ', $where);
            }
            $sql .= " ORDER BY created_at DESC, id DESC LIMIT 3";
            $candidates = Database::query($sql, $params);
            if (count($candidates) === 1) {
                $invoice = $this->invoices->getById((int) $candidates[0]['id']);
            } elseif (count($candidates) > 1) {
                $ambiguities[] = 'Multiple matching commercial documents were found.';
            }
        }

        return [
            'resolved' => $invoice !== null,
            'confidence' => $invoice ? 0.9 : (empty($ambiguities) ? 0.55 : 0.3),
            'primary_entities' => ['invoice' => $invoice],
            'candidates' => ['invoice' => $candidates],
            'ambiguities' => $ambiguities,
            'reasons' => [$invoice ? 'resolved' : 'not_found'],
        ];
    }

    public function resolveDeal(string $query, array $context = []): array
    {
        $dealId = $this->extractIntegerAfterKeyword($query, 'deal');
        $deal = $dealId > 0 ? $this->queryOneInWorkspace('deals', 'id = ?', [$dealId]) : null;
        if (!$deal && !empty($context['contact']['id'])) {
            $deal = $this->queryOneInWorkspace(
                'deals',
                'contact_id = ?',
                [(int) $context['contact']['id']],
                '*',
                'ORDER BY updated_at DESC, id DESC LIMIT 1'
            ) ?: null;
        }

        return [
            'resolved' => $deal !== null,
            'confidence' => $deal ? 0.9 : 0.4,
            'primary_entities' => ['deal' => $deal],
            'candidates' => ['deal' => $deal ? [$deal] : []],
            'ambiguities' => $deal ? [] : ['Deal not found.'],
            'reasons' => [$dealId > 0 ? 'exact_id' : 'deal_context'],
        ];
    }

    public function resolveApproval(string $query, array $context = []): array
    {
        $approvalId = $this->extractIntegerAfterKeyword($query, 'approval');
        $approval = $approvalId > 0
            ? $this->queryOneInWorkspace('commercial_automation_approvals', 'id = ?', [$approvalId])
            : null;

        return [
            'resolved' => $approval !== null,
            'confidence' => $approval ? 0.95 : 0.3,
            'primary_entities' => ['approval' => $approval],
            'candidates' => ['approval' => $approval ? [$approval] : []],
            'ambiguities' => $approval ? [] : ['Approval not found.'],
            'reasons' => ['exact_id'],
        ];
    }

    private function resolveContactFromBody(string $body): ?array
    {
        if (preg_match('/([a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-z]{2,})/i', $body, $m)) {
            return $this->queryOneInWorkspace('contacts', 'LOWER(email) = ?', [strtolower($m[1])]) ?: null;
        }

        if (preg_match('/\bto\s+([A-Z][a-z]+(?:\s+[A-Z][a-z]+)?)\b/', $body, $m)) {
            $parts = preg_split('/\s+/', trim($m[1]));
            $first = strtolower((string) ($parts[0] ?? ''));
            $last = strtolower((string) ($parts[1] ?? ''));
            if ($last !== '') {
                return $this->queryOneInWorkspace(
                    'contacts',
                    'LOWER(first_name) = ? AND LOWER(last_name) = ?',
                    [$first, $last],
                    '*',
                    'LIMIT 1'
                ) ?: null;
            }
            return $this->queryOneInWorkspace(
                'contacts',
                '(LOWER(first_name) = ? OR LOWER(last_name) = ?)',
                [$first, $first],
                '*',
                'LIMIT 1'
            ) ?: null;
        }

        return null;
    }

    private function queryOneInWorkspace(string $table, string $condition, array $params = [], string $select = '*', string $suffix = ''): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            throw new \InvalidArgumentException('Invalid table name.');
        }

        $workspaceId = $this->workspaceCondition();
        $where = [$condition];
        if ($workspaceId !== null) {
            $where[] = 'workspace_id = ?';
            $params[] = $workspaceId;
        }

        $sql = "SELECT {$select} FROM {$table} WHERE " . implode(' AND ', $where);
        if ($suffix !== '') {
            $sql .= ' ' . $suffix;
        }

        return Database::queryOne($sql, $params) ?: null;
    }

    private function workspaceCondition(): ?int
    {
        $workspaceId = WorkspaceContext::currentWorkspaceId();
        return $workspaceId !== null && $workspaceId > 0 ? (int) $workspaceId : null;
    }

    private function extractIntegerAfterKeyword(string $body, string $keyword): int
    {
        if (preg_match('/\b' . preg_quote($keyword, '/') . '\s*#?\s*(\d+)\b/i', $body, $m)) {
            return (int) $m[1];
        }

        return 0;
    }
}
