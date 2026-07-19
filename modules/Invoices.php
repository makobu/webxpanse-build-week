<?php

namespace CRM\Modules;

use CRM\Concurrency;
use CRM\Database;
use CRM\Security;
use CRM\Services\InvoiceAIAuthorizationService;
use CRM\Services\InvoiceNumberGenerator;
use CRM\Services\InvoicePricingInferenceService;
use CRM\Services\InvoiceProductRecommendationService;
use CRM\Services\InvoiceStatusService;
use CRM\Services\InvoiceTemplateService;
use CRM\Services\AITaskCompletionService;
use CRM\Services\ContactIntelligenceService;
use CRM\Services\TargetIntelligenceService;
use CRM\Services\WorkspaceScopeService;

class Invoices
{
    public const TYPES = ['quote', 'proforma', 'invoice', 'credit_note'];
    public const STATUSES = ['draft', 'sent', 'viewed', 'accepted', 'revised', 'finalized', 'partially_paid', 'paid', 'cancelled', 'overdue'];

    private InvoiceSettings $settings;
    private InvoiceNumberGenerator $numberGenerator;
    private InvoiceProductRecommendationService $productRecommendationService;
    private InvoicePricingInferenceService $pricingInferenceService;
    private InvoiceStatusService $statusService;
    private InvoiceAIAuthorizationService $aiAuth;
    private InvoiceTemplateService $templateService;
    private WorkspaceScopeService $workspaceScope;

    public function __construct()
    {
        $this->settings = new InvoiceSettings();
        $this->numberGenerator = new InvoiceNumberGenerator();
        $this->productRecommendationService = new InvoiceProductRecommendationService();
        $this->pricingInferenceService = new InvoicePricingInferenceService();
        $this->statusService = new InvoiceStatusService();
        $this->aiAuth = new InvoiceAIAuthorizationService();
        $this->templateService = new InvoiceTemplateService();
        $this->workspaceScope = new WorkspaceScopeService();
    }

    public function list(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        $workspace = $this->workspaceClause('i.');
        $where = [$workspace['sql']];
        $params = $workspace['params'];
        if (!empty($filters['document_type'])) {
            $where[] = 'i.document_type = ?';
            $params[] = $filters['document_type'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'i.status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['deal_id'])) {
            $where[] = 'i.deal_id = ?';
            $params[] = (int) $filters['deal_id'];
        }
        if (!empty($filters['contact_id'])) {
            $where[] = 'i.contact_id = ?';
            $params[] = (int) $filters['contact_id'];
        }
        if (!empty($filters['company_id'])) {
            $where[] = 'i.company_id = ?';
            $params[] = (int) $filters['company_id'];
        }
        if (!empty($filters['assigned_to'])) {
            $where[] = 'i.assigned_to = ?';
            $params[] = (int) $filters['assigned_to'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(i.invoice_number LIKE ? OR i.title LIKE ? OR i.billing_name LIKE ?)';
            $like = '%' . trim((string) $filters['search']) . '%';
            array_push($params, $like, $like, $like);
        }

        $sql = "SELECT i.*, d.title AS deal_title,
                       c.first_name AS contact_first_name, c.last_name AS contact_last_name, c.email AS contact_email,
                       co.name AS company_name,
                       u.email AS assigned_to_email
                FROM invoices i
                LEFT JOIN deals d ON d.id = i.deal_id AND d.workspace_id = i.workspace_id
                LEFT JOIN contacts c ON c.id = i.contact_id AND c.workspace_id = i.workspace_id
                LEFT JOIN companies co ON co.id = i.company_id AND co.workspace_id = i.workspace_id
                LEFT JOIN users u ON u.id = i.assigned_to";
        $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY i.created_at DESC LIMIT ' . max(1, (int) $limit) . ' OFFSET ' . max(0, (int) $offset);
        return Database::query($sql, $params);
    }

    public function count(array $filters = []): int
    {
        $workspace = $this->workspaceClause();
        $where = [$workspace['sql']];
        $params = $workspace['params'];
        if (!empty($filters['document_type'])) {
            $where[] = 'document_type = ?';
            $params[] = $filters['document_type'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'status = ?';
            $params[] = $filters['status'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(invoice_number LIKE ? OR title LIKE ? OR billing_name LIKE ?)';
            $like = '%' . trim((string) $filters['search']) . '%';
            array_push($params, $like, $like, $like);
        }
        $sql = 'SELECT COUNT(*) AS c FROM invoices';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        return (int) (Database::queryOne($sql, $params)['c'] ?? 0);
    }

    public function getById(int $id): ?array
    {
        $workspace = $this->workspaceClause('i.');
        $invoice = Database::queryOne(
            "SELECT i.*, d.title AS deal_title,
                    c.first_name AS contact_first_name, c.last_name AS contact_last_name, c.email AS contact_email, c.phone AS contact_phone,
                    co.name AS company_name,
                    u.email AS assigned_to_email
             FROM invoices i
             LEFT JOIN deals d ON d.id = i.deal_id AND d.workspace_id = i.workspace_id
             LEFT JOIN contacts c ON c.id = i.contact_id AND c.workspace_id = i.workspace_id
             LEFT JOIN companies co ON co.id = i.company_id AND co.workspace_id = i.workspace_id
             LEFT JOIN users u ON u.id = i.assigned_to
             WHERE {$workspace['sql']}
               AND i.id = ?",
            array_merge($workspace['params'], [$id])
        );
        if (!$invoice) {
            return null;
        }
        $invoice['line_items'] = $this->getLineItems($id);
        $workspaceId = (int) ($invoice['workspace_id'] ?? $this->workspaceId());
        $invoice['activity_log'] = Database::query("SELECT * FROM invoice_activity_log WHERE workspace_id = ? AND invoice_id = ? ORDER BY created_at DESC", [$workspaceId, $id]);
        $invoice['status_history'] = Database::query("SELECT * FROM invoice_status_history WHERE workspace_id = ? AND invoice_id = ? ORDER BY created_at DESC", [$workspaceId, $id]);
        $invoice['delivery_log'] = Database::query("SELECT * FROM invoice_delivery_log WHERE workspace_id = ? AND invoice_id = ? ORDER BY created_at DESC", [$workspaceId, $id]);
        return $invoice;
    }

    public function getByDealId(int $dealId): array
    {
        return $this->list(['deal_id' => $dealId], 100, 0);
    }

    public function findLatestForDeal(int $dealId, ?string $type = null): ?array
    {
        $this->assertWorkspaceEntity('deals', $dealId);
        $params = [$this->workspaceId(), $dealId];
        $sql = "SELECT id
                FROM invoices
                WHERE workspace_id = ? AND deal_id = ? AND status <> 'cancelled'";
        if ($type !== null && in_array($type, self::TYPES, true)) {
            $sql .= " AND document_type = ?";
            $params[] = $type;
        }
        $sql .= " ORDER BY revision_number DESC, id DESC LIMIT 1";
        $row = Database::queryOne($sql, $params);
        return $row ? $this->getById((int) $row['id']) : null;
    }

    public function create(array $data, string $actorType = 'user', ?int $actorId = null): int
    {
        $workspaceId = $this->workspaceId();
        $settings = $this->settings->get();
        $documentTypeCandidate = (string) ($data['document_type'] ?? 'invoice');
        $documentType = in_array($documentTypeCandidate, self::TYPES, true) ? $documentTypeCandidate : 'invoice';
        $statusCandidate = (string) ($data['status'] ?? 'draft');
        $status = in_array($statusCandidate, self::STATUSES, true) ? $statusCandidate : 'draft';
        $createdBy = (int) ($data['created_by'] ?? $actorId ?? ($_SESSION['user_id'] ?? 0));
        $issueDate = !empty($data['issue_date']) ? $data['issue_date'] : date('Y-m-d');
        $paymentTerms = max(1, (int) ($data['payment_terms_days'] ?? $settings['default_payment_terms_days']));
        $dueDate = !empty($data['due_date']) ? $data['due_date'] : date('Y-m-d', strtotime($issueDate . " +{$paymentTerms} days"));
        $validUntil = !empty($data['valid_until']) ? $data['valid_until'] : date('Y-m-d', strtotime($issueDate . " +" . max(1, (int) $settings['default_validity_days']) . " days"));
        $invoiceNumber = trim((string) ($data['invoice_number'] ?? ''));
        if ($invoiceNumber === '') {
            $invoiceNumber = $this->numberGenerator->generate($documentType, $actorId);
        }

        $contactId = !empty($data['contact_id']) ? (int) $data['contact_id'] : null;
        $companyId = !empty($data['company_id']) ? (int) $data['company_id'] : null;
        $dealId = !empty($data['deal_id']) ? (int) $data['deal_id'] : null;
        $assignedTo = !empty($data['assigned_to']) ? (int) $data['assigned_to'] : null;
        $currency = (string) ($data['currency'] ?? $settings['default_currency'] ?? 'USD');
        $templateKey = $this->templateService->resolveTemplateKey((string) ($data['template_key'] ?? ($settings['default_template_key'] ?? 'classic')));
        $taxMode = in_array(($data['tax_mode'] ?? $settings['default_tax_mode']), ['exclusive', 'inclusive', 'none'], true)
            ? ($data['tax_mode'] ?? $settings['default_tax_mode']) : 'exclusive';
        $taxRate = max(0, (float) ($data['tax_rate'] ?? $settings['default_tax_rate']));
        $billing = $this->resolveBillingDetails($contactId, $companyId, $data);
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '') {
            $title = ucfirst($documentType) . ($dealId ? ' for deal #' . $dealId : '');
        }
        if ($contactId) {
            $this->assertWorkspaceEntity('contacts', $contactId, $workspaceId);
        }
        if ($companyId) {
            $this->assertWorkspaceEntity('companies', $companyId, $workspaceId);
        }
        if ($dealId) {
            $this->assertWorkspaceEntity('deals', $dealId, $workspaceId);
        }
        if ($assignedTo) {
            $this->assertWorkspaceUser($assignedTo, $workspaceId);
        }

        Database::beginTransaction();
        try {
            Database::execute(
                "INSERT INTO invoices (
                    workspace_id, document_type, status, invoice_number, revision_number, deal_id, contact_id, company_id, assigned_to, created_by,
                    currency, issue_date, due_date, valid_until, payment_terms_days, tax_mode, tax_rate, title, intro_text, notes, terms,
                    billing_name, billing_email, billing_phone, billing_address, shipping_address, source_snapshot_json
                    , template_key
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $workspaceId, $documentType, $status, $invoiceNumber, max(1, (int) ($data['revision_number'] ?? 1)),
                    $dealId, $contactId, $companyId, $assignedTo, $createdBy,
                    $currency, $issueDate, $dueDate, $validUntil, $paymentTerms, $taxMode, $taxRate,
                    $title,
                    (string) ($data['intro_text'] ?? ($documentType === 'quote' ? $settings['proposal_intro_text'] : '')),
                    (string) ($data['notes'] ?? $settings['default_notes']),
                    (string) ($data['terms'] ?? $settings['default_terms']),
                    $billing['billing_name'], $billing['billing_email'], $billing['billing_phone'], $billing['billing_address'],
                    (string) ($data['shipping_address'] ?? ''),
                    json_encode($this->buildSourceSnapshot($dealId, $contactId, $companyId, $workspaceId)),
                    $templateKey,
                ]
            );
            $invoiceId = (int) Database::lastInsertId();

            $lineItems = is_array($data['line_items'] ?? null) ? $this->prepareLineItems($data['line_items']) : [];
            if (!$lineItems && $dealId) {
                $this->cloneDealLineItems($dealId, $invoiceId, $taxRate, $taxMode);
            } else {
                $this->replaceLineItems($invoiceId, $lineItems, $taxRate, $taxMode);
            }

            $this->recalculateTotals($invoiceId);
            $this->logActivity($invoiceId, 'invoice_created', $actorType, $actorId, 'Commercial document created', ['document_type' => $documentType]);
            $this->appendStatusHistory($invoiceId, null, $status, $actorId, $actorType, 'Initial status');
            Database::commit();
            if ($contactId) {
                try {
                    (new ContactIntelligenceService())->computeAndPersist((int) $contactId);
                } catch (\Throwable $e) {
                    error_log('Invoices::create intelligence refresh failed: ' . $e->getMessage());
                }
            }
            try {
                (new TargetIntelligenceService())->refreshAfterEntityChange('invoices', $invoiceId, [
                    'contact_id' => $contactId,
                    'company_id' => $companyId,
                    'deal_id' => $dealId,
                ]);
            } catch (\Throwable $e) {
                error_log('Invoices::create target intelligence refresh failed: ' . $e->getMessage());
            }
            return $invoiceId;
        } catch (\Throwable $e) {
            try {
                if (Database::getInstance()->inTransaction()) {
                    Database::rollBack();
                }
            } catch (\Throwable $ignored) {
            }
            throw $e;
        }
    }

    public function update(int $id, array $data, string $actorType = 'user', ?int $actorId = null): bool
    {
        $invoice = $this->getById($id);
        if (!$invoice) {
            throw new \RuntimeException('Invoice not found.');
        }
        if (($invoice['status'] ?? '') === 'finalized' && empty($data['allow_finalized_edit'])) {
            throw new \RuntimeException('Finalized invoices cannot be edited.');
        }

        $allowed = [
            'document_type', 'currency', 'issue_date', 'due_date', 'valid_until', 'payment_terms_days', 'tax_mode', 'tax_rate',
            'title', 'intro_text', 'notes', 'terms', 'billing_name', 'billing_email', 'billing_phone', 'billing_address',
            'template_key',
            'shipping_address', 'assigned_to', 'deal_id', 'contact_id', 'company_id'
        ];
        $updates = [];
        $params = [];
        foreach ($allowed as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $value = $data[$field];
            if (is_string($value)) {
                $value = Security::sanitizeInput($value, 'string');
            }
            if ($field === 'document_type') {
                if ($value === null || $value === '') {
                    continue;
                }
                if (!in_array((string) $value, self::TYPES, true)) {
                    throw new \InvalidArgumentException('Invalid commercial document type');
                }
                $value = (string) $value;
            } elseif ($field === 'tax_mode') {
                if ($value === null || $value === '') {
                    continue;
                }
                if (!in_array((string) $value, ['exclusive', 'inclusive', 'none'], true)) {
                    throw new \InvalidArgumentException('Invalid tax mode');
                }
                $value = (string) $value;
            } elseif ($field === 'tax_rate') {
                $value = max(0, (float) $value);
            } elseif ($field === 'payment_terms_days') {
                if ($value === null || $value === '') {
                    continue;
                }
                $value = max(1, (int) $value);
            }
            if ($field === 'template_key') {
                $value = $this->templateService->resolveTemplateKey((string) $value);
            }
            $updates[] = "{$field} = ?";
            $params[] = $value === '' ? null : $value;
        }

        $workspaceId = (int) ($invoice['workspace_id'] ?? $this->workspaceId());
        foreach (['contact_id' => 'contacts', 'company_id' => 'companies', 'deal_id' => 'deals'] as $field => $table) {
            if (array_key_exists($field, $data) && !empty($data[$field])) {
                $this->assertWorkspaceEntity($table, (int) $data[$field], $workspaceId);
            }
        }
        if (array_key_exists('assigned_to', $data) && !empty($data['assigned_to'])) {
            $this->assertWorkspaceUser((int) $data['assigned_to'], $workspaceId);
        }

        Database::beginTransaction();
        try {
            $submittedFields = array_intersect_key($data, array_flip($allowed));
            Concurrency::executeWorkspaceUpdate(
                'invoices',
                $workspaceId,
                $id,
                $updates ?: ['id = id'],
                $params,
                Concurrency::expectedVersionFromData($data),
                fn(): ?array => $this->getById($id),
                $submittedFields,
                'invoice'
            );

            if (isset($data['line_items']) && is_array($data['line_items'])) {
                $this->replaceLineItems($id, $this->prepareLineItems($data['line_items']), (float) ($data['tax_rate'] ?? $invoice['tax_rate']), (string) ($data['tax_mode'] ?? $invoice['tax_mode']));
            }

            if (!empty($data['increment_revision'])) {
                Database::execute("UPDATE invoices SET revision_number = revision_number + 1, status = 'revised' WHERE workspace_id = ? AND id = ?", [$workspaceId, $id]);
                $this->appendStatusHistory($id, (string) $invoice['status'], 'revised', $actorId, $actorType, 'Revision created');
            }

            $this->recalculateTotals($id);
            $this->logActivity($id, 'invoice_updated', $actorType, $actorId, 'Commercial document updated', ['fields' => array_keys($data)]);
            Database::commit();
            try {
                $updatedInvoice = $this->getById($id);
                (new TargetIntelligenceService())->refreshAfterEntityChange('invoices', $id, [
                    'contact_id' => (int) ($updatedInvoice['contact_id'] ?? 0),
                    'company_id' => (int) ($updatedInvoice['company_id'] ?? 0),
                    'deal_id' => (int) ($updatedInvoice['deal_id'] ?? 0),
                ]);
            } catch (\Throwable $e) {
                error_log('Invoices::update target intelligence refresh failed: ' . $e->getMessage());
            }
            return true;
        } catch (\Throwable $e) {
            try {
                if (Database::getInstance()->inTransaction()) {
                    Database::rollBack();
                }
            } catch (\Throwable $ignored) {
            }
            throw $e;
        }
    }

    public function replaceLineItems(int $invoiceId, array $lineItems, float $headerTaxRate = 0, string $taxMode = 'exclusive'): void
    {
        $workspaceId = $this->requireInvoiceWorkspaceId($invoiceId);
        $invoiceCurrency = strtoupper((string) (Database::queryOne(
            "SELECT currency FROM invoices WHERE workspace_id = ? AND id = ? LIMIT 1",
            [$workspaceId, $invoiceId]
        )['currency'] ?? ''));
        foreach ($lineItems as $candidate) {
            if ((string) ($candidate['catalog_source_type'] ?? '') !== 'workspace_package') {
                continue;
            }
            $candidatePrice = $this->loadActivePackagePrice((int) ($candidate['billing_plan_price_id'] ?? 0), $workspaceId);
            if (!$candidatePrice) {
                throw new \RuntimeException('The selected workspace package price is not available.');
            }
            if ($invoiceCurrency !== '' && strtoupper((string) $candidatePrice['currency']) !== $invoiceCurrency) {
                throw new \RuntimeException('The package price currency must match the document currency.');
            }
        }
        Database::execute("DELETE FROM invoice_line_items WHERE workspace_id = ? AND invoice_id = ?", [$workspaceId, $invoiceId]);
        $sort = 1;
        foreach ($lineItems as $item) {
            $productId = !empty($item['product_id']) ? (int) $item['product_id'] : null;
            $billingPlanPriceId = !empty($item['billing_plan_price_id']) ? (int) $item['billing_plan_price_id'] : null;
            $catalogSourceType = (string) ($item['catalog_source_type'] ?? ($productId ? 'workspace_offer' : 'manual'));
            if (!in_array($catalogSourceType, ['manual', 'workspace_offer', 'workspace_package'], true)) {
                $catalogSourceType = 'manual';
            }
            $description = trim((string) ($item['description'] ?? $item['product_name'] ?? ''));
            if ($description === '') {
                continue;
            }
            $quantity = max(0.01, (float) ($item['quantity'] ?? 1));
            $unitPrice = max(0.0, (float) ($item['unit_price'] ?? 0));
            $pricingContext = (string) ($item['pricing_context'] ?? '');
            if ($catalogSourceType === 'workspace_package') {
                $packagePrice = $this->loadActivePackagePrice((int) $billingPlanPriceId, $workspaceId);
                if (!$packagePrice) {
                    throw new \RuntimeException('The selected workspace package price is not available.');
                }
                $productId = null;
                $billingPlanPriceId = (int) $packagePrice['id'];
                if ($invoiceCurrency !== '' && strtoupper((string) $packagePrice['currency']) !== $invoiceCurrency) {
                    throw new \RuntimeException('The package price currency must match the document currency.');
                }
                $description = (string) $packagePrice['plan_name'] . ' - ' . ucfirst((string) $packagePrice['interval_unit']);
                $quantity = 1.0;
                $unitPrice = max(0.0, (float) $packagePrice['amount']);
                $pricingContext = 'System-managed recurring workspace package (' . (string) $packagePrice['price_code'] . '). Subscription access activates only through checkout or an audited operator activation.';
            } else {
                $billingPlanPriceId = null;
                $catalogSourceType = $productId ? 'workspace_offer' : 'manual';
                if ($productId !== null && !$this->findProductById($productId)) {
                    throw new \InvalidArgumentException('The selected product is not available in this workspace.');
                }
            }
            $discountPercent = $catalogSourceType === 'workspace_package'
                ? 0.0
                : min(100.0, max(0.0, (float) ($item['discount_percent'] ?? 0)));
            $discountAmount = round(($quantity * $unitPrice) * ($discountPercent / 100), 2);
            $taxPercent = $catalogSourceType === 'workspace_package'
                ? 0.0
                : max(0.0, (float) ($item['tax_percent'] ?? $headerTaxRate));
            $baseAmount = ($quantity * $unitPrice) - $discountAmount;
            $taxAmount = $taxMode === 'none' ? 0.0 : round($baseAmount * ($taxPercent / 100), 2);
            $lineTotal = $taxMode === 'inclusive' ? round($baseAmount, 2) : round($baseAmount + $taxAmount, 2);

            Database::execute(
                "INSERT INTO invoice_line_items (workspace_id, invoice_id, product_id, catalog_source_type, billing_plan_price_id, description, pricing_context, quantity, unit_price, discount_percent, discount_amount, tax_percent, tax_amount, line_total, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $workspaceId,
                    $invoiceId,
                    $productId,
                    $catalogSourceType,
                    $billingPlanPriceId,
                    $description,
                    $pricingContext,
                    $quantity,
                    $unitPrice,
                    $discountPercent,
                    $discountAmount,
                    $taxPercent,
                    $taxAmount,
                    $lineTotal,
                    $sort++,
                ]
            );
        }
    }

    private function loadActivePackagePrice(int $billingPlanPriceId, int $workspaceId): ?array
    {
        if ($billingPlanPriceId <= 0) {
            return null;
        }
        return Database::queryOne(
            "SELECT bpp.id, bpp.price_code, bpp.amount, bpp.currency, bpp.interval_unit, bpp.interval_count,
                    bp.name AS plan_name
             FROM billing_plan_prices bpp
             JOIN billing_plans bp ON bp.id = bpp.plan_id
             WHERE bpp.id = ?
               AND (bpp.workspace_id IS NULL OR bpp.workspace_id = ?)
               AND bpp.is_active = 1
               AND bp.is_active = 1
               AND bp.billing_type = 'subscription'
             LIMIT 1",
            [$billingPlanPriceId, $workspaceId]
        ) ?: null;
    }

    public function getLineItems(int $invoiceId): array
    {
        $workspaceId = $this->requireInvoiceWorkspaceId($invoiceId);
        return Database::query(
            "SELECT li.*, p.name AS product_name
             FROM invoice_line_items li
             LEFT JOIN products p ON p.id = li.product_id AND p.workspace_id = li.workspace_id
             WHERE li.workspace_id = ?
               AND li.invoice_id = ?
             ORDER BY li.sort_order ASC, li.id ASC",
            [$workspaceId, $invoiceId]
        );
    }

    public function recalculateTotals(int $invoiceId): void
    {
        $workspaceId = $this->requireInvoiceWorkspaceId($invoiceId);
        $invoice = Database::queryOne("SELECT tax_mode, amount_paid FROM invoices WHERE workspace_id = ? AND id = ?", [$workspaceId, $invoiceId]);
        if (!$invoice) {
            return;
        }
        $row = Database::queryOne(
            "SELECT
                COALESCE(SUM(quantity * unit_price), 0) AS gross_subtotal,
                COALESCE(SUM(discount_amount), 0) AS discount_total,
                COALESCE(SUM(tax_amount), 0) AS tax_total,
                COALESCE(SUM(line_total), 0) AS grand_total
             FROM invoice_line_items WHERE workspace_id = ? AND invoice_id = ?",
            [$workspaceId, $invoiceId]
        ) ?: [];

        $subtotal = (float) ($row['gross_subtotal'] ?? 0) - (float) ($row['discount_total'] ?? 0);
        $discountTotal = (float) ($row['discount_total'] ?? 0);
        $taxTotal = (float) ($row['tax_total'] ?? 0);
        $grandTotal = (float) ($row['grand_total'] ?? 0);
        if (($invoice['tax_mode'] ?? 'exclusive') === 'none') {
            $taxTotal = 0.0;
            $grandTotal = $subtotal;
        }

        $paid = (float) ($invoice['amount_paid'] ?? 0);
        $balance = max(0.0, $grandTotal - $paid);

        Database::execute(
            "UPDATE invoices
             SET subtotal = ?, discount_total = ?, tax_total = ?, grand_total = ?, balance_due = ?
             WHERE workspace_id = ? AND id = ?",
            [round($subtotal, 2), round($discountTotal, 2), round($taxTotal, 2), round($grandTotal, 2), round($balance, 2), $workspaceId, $invoiceId]
        );
    }

    public function transitionStatus(int $id, string $toStatus, ?int $actorId = null, string $actorType = 'user', ?string $reason = null): bool
    {
        $workspaceId = $this->workspaceId();
        $invoice = Database::queryOne("SELECT status, contact_id FROM invoices WHERE workspace_id = ? AND id = ?", [$workspaceId, $id]);
        if (!$invoice) {
            throw new \RuntimeException('Invoice not found.');
        }
        $fromStatus = (string) ($invoice['status'] ?? 'draft');
        if (!in_array($toStatus, self::STATUSES, true)) {
            throw new \RuntimeException('Invalid invoice status.');
        }
        if (!$this->statusService->canTransition($fromStatus, $toStatus)) {
            throw new \RuntimeException("Cannot change invoice from {$fromStatus} to {$toStatus}.");
        }
        if ($reason !== null) {
            if (!mb_check_encoding($reason, 'UTF-8')) {
                throw new \InvalidArgumentException('Status reason must be valid text.');
            }
            if (mb_strlen($reason) > 255) {
                throw new \InvalidArgumentException('Status reason must be 255 characters or fewer.');
            }
        }

        $fields = ['status = ?'];
        $params = [$toStatus];
        if ($toStatus === 'sent') {
            $fields[] = 'last_sent_at = NOW()';
        } elseif ($toStatus === 'accepted') {
            $fields[] = 'accepted_at = NOW()';
        } elseif ($toStatus === 'finalized') {
            $fields[] = 'finalized_at = NOW()';
        } elseif ($toStatus === 'paid') {
            $fields[] = 'paid_at = NOW()';
            $fields[] = 'amount_paid = grand_total';
            $fields[] = 'balance_due = 0';
        } elseif ($toStatus === 'cancelled') {
            $fields[] = 'cancelled_at = NOW()';
        }
        $params[] = $workspaceId;
        $params[] = $id;
        Database::beginTransaction();
        try {
            Database::execute("UPDATE invoices SET " . implode(', ', $fields) . " WHERE workspace_id = ? AND id = ?", $params);
            $this->appendStatusHistory($id, $fromStatus, $toStatus, $actorId, $actorType, $reason);
            $this->logActivity($id, 'invoice_status_changed', $actorType, $actorId, "Status changed from {$fromStatus} to {$toStatus}", ['reason' => $reason]);
            Database::commit();
        } catch (\Throwable $e) {
            if (Database::getInstance()->inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }
        try {
            (new \CRM\Services\CommercialAutomationOrchestrator())->runForInvoiceStatus($id, $fromStatus, $toStatus);
        } catch (\Throwable $e) {
            error_log('Invoices::transitionStatus commercial automation failed: ' . $e->getMessage());
        }
        try {
            $invoiceRow = Database::queryOne("SELECT assigned_to, created_by FROM invoices WHERE workspace_id = ? AND id = ?", [$workspaceId, $id]);
            $scanUserId = (int) (($invoiceRow['assigned_to'] ?? 0) ?: ($invoiceRow['created_by'] ?? 0));
            if ($scanUserId > 0) {
                (new AITaskCompletionService())->scanForCompletionEvidence($scanUserId);
            }
        } catch (\Throwable $e) {
            error_log('Invoices::transitionStatus AI task completion scan failed: ' . $e->getMessage());
        }
        if (!empty($invoice['contact_id'])) {
            try {
                (new ContactIntelligenceService())->computeAndPersist((int) $invoice['contact_id']);
            } catch (\Throwable $e) {
                error_log('Invoices::transitionStatus intelligence refresh failed: ' . $e->getMessage());
            }
        }
        try {
            $updatedInvoice = $this->getById($id);
            (new TargetIntelligenceService())->refreshAfterEntityChange('invoices', $id, [
                'contact_id' => (int) ($updatedInvoice['contact_id'] ?? 0),
                'company_id' => (int) ($updatedInvoice['company_id'] ?? 0),
                'deal_id' => (int) ($updatedInvoice['deal_id'] ?? 0),
                'status' => $toStatus,
            ]);
        } catch (\Throwable $e) {
            error_log('Invoices::transitionStatus target intelligence refresh failed: ' . $e->getMessage());
        }

        return true;
    }

    public function markPaid(int $id, float $amount, ?string $paymentDate = null, ?int $actorId = null, string $actorType = 'user', ?string $reason = null): bool
    {
        $workspaceId = $this->workspaceId();
        $invoice = Database::queryOne("SELECT grand_total, amount_paid, contact_id FROM invoices WHERE workspace_id = ? AND id = ?", [$workspaceId, $id]);
        if (!$invoice) {
            throw new \RuntimeException('Invoice not found.');
        }
        $amount = max(0.0, $amount);
        $newPaid = min((float) $invoice['grand_total'], (float) $invoice['amount_paid'] + $amount);
        $status = $newPaid >= (float) $invoice['grand_total'] ? 'paid' : 'partially_paid';
        Database::execute(
            "UPDATE invoices
             SET amount_paid = ?, balance_due = GREATEST(grand_total - ?, 0), paid_at = COALESCE(?, paid_at), status = ?
             WHERE workspace_id = ? AND id = ?",
            [$newPaid, $newPaid, $paymentDate, $status, $workspaceId, $id]
        );
        $this->appendStatusHistory($id, null, $status, $actorId, $actorType, $reason ?: 'Manual payment recorded');
        $this->logActivity($id, 'invoice_mark_paid', $actorType, $actorId, 'Payment recorded', ['amount' => $amount, 'status' => $status]);
        if (!empty($invoice['contact_id'])) {
            try {
                (new ContactIntelligenceService())->computeAndPersist((int) $invoice['contact_id']);
            } catch (\Throwable $e) {
                error_log('Invoices::markPaid intelligence refresh failed: ' . $e->getMessage());
            }
        }
        try {
            $updatedInvoice = $this->getById($id);
            (new TargetIntelligenceService())->refreshAfterEntityChange('invoices', $id, [
                'contact_id' => (int) ($updatedInvoice['contact_id'] ?? 0),
                'company_id' => (int) ($updatedInvoice['company_id'] ?? 0),
                'deal_id' => (int) ($updatedInvoice['deal_id'] ?? 0),
                'status' => $status,
            ]);
        } catch (\Throwable $e) {
            error_log('Invoices::markPaid target intelligence refresh failed: ' . $e->getMessage());
        }

        return true;
    }

    public function convertToInvoice(int $id, ?int $actorId = null, string $actorType = 'user'): int
    {
        $invoice = $this->getById($id);
        if (!$invoice) {
            throw new \RuntimeException('Document not found.');
        }
        $newId = $this->create([
            'document_type' => 'invoice',
            'status' => 'draft',
            'deal_id' => $invoice['deal_id'],
            'contact_id' => $invoice['contact_id'],
            'company_id' => $invoice['company_id'],
            'assigned_to' => $invoice['assigned_to'],
            'created_by' => $actorId ?: (int) ($invoice['created_by'] ?? 0),
            'currency' => $invoice['currency'],
            'issue_date' => date('Y-m-d'),
            'payment_terms_days' => (int) ($invoice['payment_terms_days'] ?? 14),
            'tax_mode' => $invoice['tax_mode'],
            'tax_rate' => $invoice['tax_rate'],
            'title' => $invoice['title'],
            'intro_text' => $invoice['intro_text'],
            'notes' => $invoice['notes'],
            'terms' => $invoice['terms'],
            'billing_name' => $invoice['billing_name'],
            'billing_email' => $invoice['billing_email'],
            'billing_phone' => $invoice['billing_phone'],
            'billing_address' => $invoice['billing_address'],
            'shipping_address' => $invoice['shipping_address'],
            'line_items' => $invoice['line_items'],
        ], $actorType, $actorId);
        $this->logActivity($newId, 'invoice_converted', $actorType, $actorId, 'Created from quote/proforma', ['source_invoice_id' => $id]);
        return $newId;
    }

    public function createFromDeal(int $dealId, string $documentType = 'quote', ?int $actorId = null, string $actorType = 'user'): int
    {
        $deal = Database::queryOne("SELECT * FROM deals WHERE workspace_id = ? AND id = ?", [$this->workspaceId(), $dealId]);
        if (!$deal) {
            throw new \RuntimeException('Deal not found.');
        }
        return $this->create([
            'document_type' => $documentType,
            'deal_id' => $dealId,
            'contact_id' => $deal['contact_id'] ?? null,
            'company_id' => $deal['company_id'] ?? null,
            'assigned_to' => $deal['assigned_to'] ?? null,
            'created_by' => $actorId ?: (int) ($deal['created_by'] ?? 0),
            'currency' => $deal['currency'] ?? null,
            'title' => $deal['title'] ?? ucfirst($documentType),
            'notes' => $deal['description'] ?? '',
            'tax_rate' => 0,
            'tax_mode' => 'exclusive',
        ], $actorType, $actorId);
    }

    public function createDraftForStage(int $dealId, string $stage, ?string $documentType = null, string $actorType = 'user', ?int $actorId = null): int
    {
        $settings = $this->settings->get();
        $stageDefaults = $settings['ai_allowed_document_types_by_stage'][$stage] ?? [];
        $resolvedType = $documentType ?: (($stageDefaults[0] ?? null) ?: ($stage === 'closed_won' ? 'invoice' : 'quote'));
        if (!in_array($resolvedType, self::TYPES, true)) {
            $resolvedType = 'quote';
        }
        return $this->createFromDeal($dealId, $resolvedType, $actorId, $actorType);
    }

    public function getSuggestedProducts(?int $dealId, ?int $contactId, ?int $companyId, ?string $documentType = null, ?int $invoiceId = null): array
    {
        return $this->productRecommendationService->recommend([
            'deal_id' => $dealId,
            'contact_id' => $contactId,
            'company_id' => $companyId,
            'document_type' => $documentType,
            'invoice_id' => $invoiceId,
        ]);
    }

    public function buildLineItemFromProduct(int $productId, float $quantity = 1): array
    {
        $product = $this->findProductById($productId);
        if (!$product) {
            throw new \RuntimeException('Product not found.');
        }

        $lineItem = [
            'product_id' => (int) $product['id'],
            'description' => (string) ($product['description'] ?: $product['name']),
            'product_name' => (string) $product['name'],
            'quantity' => max(0.01, $quantity),
            'unit_price' => isset($product['unit_price']) ? (float) $product['unit_price'] : 0.0,
            'discount_percent' => 0,
            'tax_percent' => 0,
            'pricing_context' => (string) ($product['pricing_info'] ?? ''),
            'pricing_info' => (string) ($product['pricing_info'] ?? ''),
        ];
        if ((float) $lineItem['unit_price'] <= 0 && $lineItem['pricing_context'] !== '') {
            $inference = $this->inferLineItemPrice($lineItem);
            if ((float) ($inference['unit_price'] ?? 0) > 0) {
                $lineItem['unit_price'] = (float) $inference['unit_price'];
            }
        }
        return $lineItem;
    }

    public function reviseFromRecommendations(int $invoiceId, array $changes, string $actorType = 'user', ?int $actorId = null): int
    {
        $invoice = $this->getById($invoiceId);
        if (!$invoice) {
            throw new \RuntimeException('Invoice not found.');
        }

        $lineItems = $invoice['line_items'] ?? [];
        $existingProductIds = [];
        foreach ($lineItems as $lineItem) {
            if (!empty($lineItem['product_id'])) {
                $existingProductIds[(int) $lineItem['product_id']] = true;
            }
        }

        foreach (($changes['suggested_products'] ?? []) as $suggested) {
            $productId = (int) ($suggested['product_id'] ?? 0);
            if ($productId > 0 && !empty($existingProductIds[$productId])) {
                continue;
            }
            if ($productId > 0) {
                $candidate = $this->buildLineItemFromProduct($productId, (float) ($suggested['recommended_quantity'] ?? 1));
                if ((float) ($candidate['unit_price'] ?? 0) <= 0 && !empty($suggested['pricing_info'])) {
                    $candidate['pricing_context'] = (string) $suggested['pricing_info'];
                    $inference = $this->inferLineItemPrice($candidate);
                    if ((float) ($inference['unit_price'] ?? 0) > 0) {
                        $candidate['unit_price'] = (float) $inference['unit_price'];
                    }
                }
                if ((float) ($candidate['unit_price'] ?? 0) > 0) {
                    $lineItems[] = $candidate;
                    $existingProductIds[$productId] = true;
                }
            }
        }

        $this->update($invoiceId, [
            'line_items' => $lineItems,
            'increment_revision' => true,
        ], $actorType, $actorId);
        $this->logActivity($invoiceId, 'document_revision_created', $actorType, $actorId, 'Document revised from automation recommendations', [
            'suggested_count' => count((array) ($changes['suggested_products'] ?? [])),
        ]);
        return $invoiceId;
    }

    public function hasBlockingCommercialGaps(int $invoiceId): array
    {
        $invoice = $this->getById($invoiceId);
        if (!$invoice) {
            return ['missing_pricing' => true, 'missing_recipient' => true, 'missing_billing_identity' => true];
        }

        $missingPricing = false;
        foreach (($invoice['line_items'] ?? []) as $lineItem) {
            if ((float) ($lineItem['unit_price'] ?? 0) <= 0) {
                $missingPricing = true;
                break;
            }
        }

        return [
            'missing_pricing' => $missingPricing || empty($invoice['line_items']),
            'missing_recipient' => trim((string) ($invoice['billing_email'] ?? $invoice['contact_email'] ?? '')) === '',
            'missing_billing_identity' => trim((string) ($invoice['billing_name'] ?? '')) === '' || trim((string) ($invoice['billing_email'] ?? '')) === '',
        ];
    }

    public function markOverdueIfEligible(int $invoiceId, string $actorType = 'user', ?int $actorId = null): bool
    {
        $invoice = $this->getById($invoiceId);
        if (!$invoice || ($invoice['document_type'] ?? '') !== 'invoice') {
            return false;
        }
        if (in_array((string) ($invoice['status'] ?? ''), ['paid', 'cancelled', 'overdue'], true)) {
            return false;
        }
        $dueDate = $invoice['due_date'] ?? null;
        if (!$dueDate || strtotime((string) $dueDate) >= strtotime(date('Y-m-d'))) {
            return false;
        }
        if ((float) ($invoice['balance_due'] ?? 0) <= 0) {
            return false;
        }
        $this->transitionStatus($invoiceId, 'overdue', $actorId, $actorType, 'Marked overdue by commercial automation');
        $this->logActivity($invoiceId, 'invoice_marked_overdue', $actorType, $actorId, 'Invoice marked overdue', []);
        return true;
    }

    public function cloneDealLineItems(int $dealId, int $invoiceId, float $taxRate = 0, string $taxMode = 'exclusive'): void
    {
        $this->assertWorkspaceEntity('deals', $dealId);
        $items = Database::query("SELECT * FROM deal_line_items WHERE deal_id = ? ORDER BY sort_order ASC, id ASC", [$dealId]);
        $mapped = [];
        foreach ($items as $item) {
            $mapped[] = [
                'product_id' => $item['product_id'] ?? null,
                'description' => $item['description'] ?? '',
                'pricing_context' => $item['pricing_context'] ?? '',
                'quantity' => $item['quantity'] ?? 1,
                'unit_price' => $item['unit_price'] ?? 0,
                'discount_percent' => $item['discount_percent'] ?? 0,
                'tax_percent' => $taxRate,
            ];
        }
        $this->replaceLineItems($invoiceId, $mapped, $taxRate, $taxMode);
    }

    public function logActivity(int $invoiceId, string $actionKey, string $actorType, ?int $actorId, string $summary, array $metadata = []): void
    {
        $workspaceId = $this->requireInvoiceWorkspaceId($invoiceId);
        Database::execute(
            "INSERT INTO invoice_activity_log (workspace_id, invoice_id, action_key, actor_type, actor_id, summary, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$workspaceId, $invoiceId, $actionKey, $actorType, $actorId, $summary, json_encode($metadata)]
        );
    }

    public function canAiPerform(string $action): bool
    {
        return $this->aiAuth->canPerform($action);
    }

    public function inferLineItemPrice(array $lineItem): array
    {
        $product = null;
        if (!empty($lineItem['product_id'])) {
            $product = $this->findProductById((int) $lineItem['product_id']);
        }
        return $this->pricingInferenceService->infer($lineItem, $product);
    }

    private function prepareLineItems(array $lineItems): array
    {
        $prepared = [];
        foreach ($lineItems as $lineItem) {
            if (!is_array($lineItem)) {
                continue;
            }
            $lineItem['pricing_context'] = (string) ($lineItem['pricing_context'] ?? '');
            if ((float) ($lineItem['unit_price'] ?? 0) <= 0) {
                $inference = $this->inferLineItemPrice($lineItem);
                if ((float) ($inference['unit_price'] ?? 0) > 0) {
                    $lineItem['unit_price'] = (float) $inference['unit_price'];
                }
            }
            $prepared[] = $lineItem;
        }
        return $prepared;
    }

    private function appendStatusHistory(int $invoiceId, ?string $fromStatus, string $toStatus, ?int $actorId, string $actorType, ?string $reason): void
    {
        $workspaceId = $this->requireInvoiceWorkspaceId($invoiceId);
        Database::execute(
            "INSERT INTO invoice_status_history (workspace_id, invoice_id, from_status, to_status, changed_by, changed_by_type, reason)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$workspaceId, $invoiceId, $fromStatus, $toStatus, $actorId, $actorType, $reason]
        );
    }

    private function resolveBillingDetails(?int $contactId, ?int $companyId, array $data): array
    {
        $workspaceId = $this->workspaceId();
        $billing = [
            'billing_name' => (string) ($data['billing_name'] ?? ''),
            'billing_email' => (string) ($data['billing_email'] ?? ''),
            'billing_phone' => (string) ($data['billing_phone'] ?? ''),
            'billing_address' => (string) ($data['billing_address'] ?? ''),
        ];
        if ($contactId) {
            $contact = Database::queryOne("SELECT * FROM contacts WHERE workspace_id = ? AND id = ?", [$workspaceId, $contactId]);
            if ($contact) {
                if ($billing['billing_name'] === '') {
                    $billing['billing_name'] = trim((string) (($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? '')));
                }
                if ($billing['billing_email'] === '') {
                    $billing['billing_email'] = (string) ($contact['email'] ?? '');
                }
                if ($billing['billing_phone'] === '') {
                    $billing['billing_phone'] = (string) ($contact['phone'] ?? '');
                }
                if ($billing['billing_address'] === '') {
                    $billing['billing_address'] = (string) ($contact['address'] ?? '');
                }
            }
        }
        if ($companyId && $billing['billing_address'] === '') {
            $company = Database::queryOne("SELECT * FROM companies WHERE workspace_id = ? AND id = ?", [$workspaceId, $companyId]);
            if ($company) {
                $billing['billing_address'] = (string) ($company['address'] ?? '');
                if ($billing['billing_name'] === '') {
                    $billing['billing_name'] = (string) ($company['name'] ?? '');
                }
            }
        }
        return $billing;
    }

    private function buildSourceSnapshot(?int $dealId, ?int $contactId, ?int $companyId, ?int $workspaceId = null): array
    {
        $resolvedWorkspaceId = $this->workspaceId($workspaceId);
        $snapshot = [];
        if ($dealId) {
            $snapshot['deal'] = Database::queryOne("SELECT id, title, stage, value, currency FROM deals WHERE workspace_id = ? AND id = ?", [$resolvedWorkspaceId, $dealId]);
        }
        if ($contactId) {
            $snapshot['contact'] = Database::queryOne("SELECT id, first_name, last_name, email, phone, company, stage FROM contacts WHERE workspace_id = ? AND id = ?", [$resolvedWorkspaceId, $contactId]);
        }
        if ($companyId) {
            $snapshot['company'] = Database::queryOne("SELECT id, name FROM companies WHERE workspace_id = ? AND id = ?", [$resolvedWorkspaceId, $companyId]);
        }
        return $snapshot;
    }

    private function workspaceId(?int $workspaceId = null): int
    {
        return $this->workspaceScope->requireActiveWorkspaceId($workspaceId);
    }

    /**
     * @return array{sql:string, params:array<int,int>}
     */
    private function workspaceClause(string $alias = '', ?int $workspaceId = null): array
    {
        return $this->workspaceScope->workspaceClause($alias, 'workspace_id', $workspaceId);
    }

    private function assertWorkspaceEntity(string $table, int $entityId, ?int $workspaceId = null): void
    {
        $this->workspaceScope->assertSameWorkspace($table, $entityId, $this->workspaceId($workspaceId));
    }

    private function assertWorkspaceUser(int $userId, ?int $workspaceId = null): void
    {
        $workspaceId = $this->workspaceId($workspaceId);
        $row = Database::queryOne(
            "SELECT id
             FROM workspace_memberships
             WHERE workspace_id = ?
               AND user_id = ?
               AND membership_status = 'active'
             LIMIT 1",
            [$workspaceId, $userId]
        );

        if (!$row) {
            throw new \RuntimeException('The requested assignee does not belong to the active workspace.');
        }
    }

    private function findProductById(int $productId): ?array
    {
        if ($productId <= 0) {
            return null;
        }

        if ($this->tableHasColumn('products', 'workspace_id')) {
            return Database::queryOne(
                "SELECT *
                 FROM products
                 WHERE workspace_id = ?
                   AND id = ?
                   AND is_active = 1
                 LIMIT 1",
                [$this->workspaceId(), $productId]
            );
        }

        return Database::queryOne(
            "SELECT *
             FROM products
             WHERE id = ?
               AND is_active = 1
             LIMIT 1",
            [$productId]
        );
    }

    private function tableHasColumn(string $table, string $column): bool
    {
        try {
            return (bool) Database::queryOne(
                "SELECT 1
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?
                   AND COLUMN_NAME = ?",
                [$table, $column]
            );
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function requireInvoiceWorkspaceId(int $invoiceId): int
    {
        $row = Database::queryOne(
            "SELECT workspace_id
             FROM invoices
             WHERE workspace_id = ?
               AND id = ?
             LIMIT 1",
            [$this->workspaceId(), $invoiceId]
        );

        if (!$row) {
            throw new \RuntimeException('Invoice not found.');
        }

        return (int) ($row['workspace_id'] ?? 0);
    }
}
