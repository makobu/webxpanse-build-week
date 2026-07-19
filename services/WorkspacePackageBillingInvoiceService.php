<?php

namespace CRM\Services;

use CRM\Database;

class WorkspacePackageBillingInvoiceService
{
    /**
     * @return array<string,mixed>|null
     */
    public function issueForSubscriptionTransaction(int $billingTransactionId): ?array
    {
        if ($billingTransactionId <= 0 || !Database::tableExists('billing_invoices')) {
            return null;
        }

        $context = $this->loadSubscriptionTransactionContext($billingTransactionId);
        if (!$context) {
            return null;
        }

        if ((string) ($context['transaction_type'] ?? '') !== 'subscription_charge') {
            return null;
        }

        if ((string) ($context['transaction_status'] ?? '') !== 'succeeded') {
            return null;
        }

        $workspaceId = (int) ($context['workspace_id'] ?? 0);
        if ($workspaceId <= 0 || $this->isPackageExempt($workspaceId, $context)) {
            return null;
        }

        $subscriptionId = (int) ($context['subscription_id'] ?? 0);
        $priceId = (int) ($context['billing_plan_price_id'] ?? 0);
        if ($subscriptionId <= 0 || $priceId <= 0) {
            return null;
        }

        $documentKey = 'transaction:' . $billingTransactionId;
        $existing = $this->findByDocumentKey($documentKey);
        if ($existing) {
            return $existing;
        }

        return $this->createInvoice($documentKey, $this->normalizeInvoicePayload([
            'workspace_id' => $workspaceId,
            'subscription_id' => $subscriptionId,
            'billing_plan_price_id' => $priceId,
            'billing_transaction_id' => $billingTransactionId,
            'checkout_session_id' => !empty($context['checkout_session_id']) ? (int) $context['checkout_session_id'] : null,
            'negotiated_offer_id' => $this->resolveNegotiatedOfferId($workspaceId, $priceId),
            'provider' => (string) ($context['provider'] ?? 'paystack'),
            'provider_reference' => (string) ($context['provider_reference'] ?? ''),
            'provider_subscription_code' => (string) ($context['provider_subscription_code'] ?? ''),
            'provider_customer_code' => (string) ($context['provider_customer_code'] ?? ''),
            'package_code' => (string) (($context['plan_code'] ?? '') ?: ($context['price_code'] ?? 'package')),
            'package_name' => (string) (($context['plan_name'] ?? '') ?: 'Workspace package'),
            'buyer' => $this->resolveBuyerSnapshot($workspaceId, (int) ($context['checkout_user_id'] ?? 0), (int) ($context['subscription_created_by'] ?? 0)),
            'workspace' => [
                'name' => (string) ($context['workspace_name'] ?? ''),
                'slug' => (string) ($context['workspace_slug'] ?? ''),
            ],
            'currency' => (string) (($context['currency'] ?? '') ?: ($context['price_currency'] ?? 'KES')),
            'amount' => (float) ($context['amount'] ?? 0),
            'period_start' => $this->nullableDateTime($context['current_period_start'] ?? null),
            'period_end' => $this->nullableDateTime($context['current_period_end'] ?? null),
            'metadata' => [
                'source' => 'subscription_transaction',
                'transaction_type' => (string) ($context['transaction_type'] ?? ''),
                'payment_mode' => (string) ($context['payment_mode'] ?? ''),
                'flow_type' => (string) ($context['flow_type'] ?? ''),
                'price_code' => (string) ($context['price_code'] ?? ''),
                'interval_unit' => (string) ($context['interval_unit'] ?? ''),
                'interval_count' => (int) ($context['interval_count'] ?? 1),
                'included_credits' => (int) ($context['included_tokens'] ?? 0),
            ],
        ]));
    }

    /**
     * @return array<string,mixed>|null
     */
    public function issueForOperatorActivation(
        int $workspaceId,
        int $subscriptionId,
        int $billingPlanPriceId,
        ?int $actorUserId = null,
        ?int $negotiatedOfferId = null,
        ?string $reason = null
    ): ?array {
        if ($workspaceId <= 0 || $subscriptionId <= 0 || $billingPlanPriceId <= 0 || !Database::tableExists('billing_invoices')) {
            return null;
        }

        $context = $this->loadOperatorActivationContext($workspaceId, $subscriptionId, $billingPlanPriceId);
        if (!$context || $this->isPackageExempt($workspaceId, $context)) {
            return null;
        }

        $periodStart = $this->nullableDateTime($context['current_period_start'] ?? null) ?: date('Y-m-d H:i:s');
        $documentKey = sprintf('operator_activation:%d:%d:%s', $subscriptionId, $billingPlanPriceId, $periodStart);
        $existing = $this->findByDocumentKey($documentKey);
        if ($existing) {
            if ($negotiatedOfferId !== null && $negotiatedOfferId > 0 && empty($existing['negotiated_offer_id'])) {
                Database::execute(
                    "UPDATE billing_invoices
                     SET negotiated_offer_id = ?,
                         updated_at = NOW()
                     WHERE id = ?",
                    [$negotiatedOfferId, (int) $existing['id']]
                );
                $existing = $this->findById((int) $existing['id']);
            }

            return $existing;
        }

        $amount = (float) ($context['price_amount'] ?? 0);

        return $this->createInvoice($documentKey, $this->normalizeInvoicePayload([
            'workspace_id' => $workspaceId,
            'subscription_id' => $subscriptionId,
            'billing_plan_price_id' => $billingPlanPriceId,
            'billing_transaction_id' => null,
            'checkout_session_id' => null,
            'negotiated_offer_id' => $negotiatedOfferId !== null && $negotiatedOfferId > 0
                ? $negotiatedOfferId
                : $this->resolveNegotiatedOfferId($workspaceId, $billingPlanPriceId),
            'provider' => 'operator',
            'provider_reference' => '',
            'provider_subscription_code' => (string) ($context['provider_subscription_code'] ?? ''),
            'provider_customer_code' => (string) ($context['provider_customer_code'] ?? ''),
            'package_code' => (string) (($context['plan_code'] ?? '') ?: ($context['price_code'] ?? 'package')),
            'package_name' => (string) (($context['plan_name'] ?? '') ?: 'Workspace package'),
            'buyer' => $this->resolveBuyerSnapshot($workspaceId, 0, (int) ($context['subscription_created_by'] ?? 0)),
            'workspace' => [
                'name' => (string) ($context['workspace_name'] ?? ''),
                'slug' => (string) ($context['workspace_slug'] ?? ''),
            ],
            'currency' => (string) (($context['price_currency'] ?? '') ?: 'KES'),
            'amount' => $amount,
            'period_start' => $periodStart,
            'period_end' => $this->nullableDateTime($context['current_period_end'] ?? null),
            'metadata' => [
                'source' => 'operator_activation',
                'operator_user_id' => $actorUserId,
                'reason' => $reason,
                'price_code' => (string) ($context['price_code'] ?? ''),
                'interval_unit' => (string) ($context['interval_unit'] ?? ''),
                'interval_count' => (int) ($context['interval_count'] ?? 1),
                'included_credits' => (int) ($context['included_tokens'] ?? 0),
            ],
        ]));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listForWorkspace(int $workspaceId, int $limit = 20): array
    {
        if ($workspaceId <= 0 || !Database::tableExists('billing_invoices')) {
            return [];
        }

        return array_map([$this, 'hydrateInvoice'], Database::query(
            "SELECT *
             FROM billing_invoices
             WHERE workspace_id = ?
               AND document_key IS NOT NULL
               AND document_key <> ''
             ORDER BY issued_at DESC, id DESC
             LIMIT " . max(1, min(200, $limit)),
            [$workspaceId]
        ));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function summariesForTransactionIds(array $transactionIds): array
    {
        $transactionIds = array_values(array_unique(array_filter(array_map('intval', $transactionIds), static fn(int $id): bool => $id > 0)));
        if ($transactionIds === [] || !Database::tableExists('billing_invoices')) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($transactionIds), '?'));
        $rows = Database::query(
            "SELECT id, document_number, document_key, workspace_id, billing_transaction_id, amount, currency, issued_at
             FROM billing_invoices
             WHERE billing_transaction_id IN ({$placeholders})",
            $transactionIds
        );

        $byTransaction = [];
        foreach ($rows as $row) {
            $byTransaction[(int) ($row['billing_transaction_id'] ?? 0)] = $this->summary($row);
        }

        return $byTransaction;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findById(int $invoiceId): ?array
    {
        if ($invoiceId <= 0 || !Database::tableExists('billing_invoices')) {
            return null;
        }

        $row = Database::queryOne(
            "SELECT *
             FROM billing_invoices
             WHERE id = ?
               AND document_key IS NOT NULL
               AND document_key <> ''
             LIMIT 1",
            [$invoiceId]
        );
        return $row ? $this->hydrateInvoice($row) : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findForWorkspace(int $invoiceId, int $workspaceId): ?array
    {
        if ($invoiceId <= 0 || $workspaceId <= 0 || !Database::tableExists('billing_invoices')) {
            return null;
        }

        $row = Database::queryOne(
            "SELECT *
             FROM billing_invoices
             WHERE id = ?
               AND workspace_id = ?
               AND document_key IS NOT NULL
               AND document_key <> ''
             LIMIT 1",
            [$invoiceId, $workspaceId]
        );

        return $row ? $this->hydrateInvoice($row) : null;
    }

    /**
     * @param array<string,mixed> $invoice
     * @return array<string,mixed>
     */
    public function summary(array $invoice): array
    {
        $id = (int) ($invoice['id'] ?? 0);
        $url = $id > 0 ? 'billing_invoice.php?id=' . $id : '';
        $pdfUrl = $id > 0 ? 'billing_invoice_pdf.php?id=' . $id : '';

        if (function_exists('publicUrl')) {
            $url = $id > 0 ? publicUrl('billing_invoice.php?id=' . $id) : '';
            $pdfUrl = $id > 0 ? publicUrl('billing_invoice_pdf.php?id=' . $id) : '';
        }

        return [
            'id' => $id,
            'document_number' => (string) ($invoice['document_number'] ?? ''),
            'document_key' => (string) ($invoice['document_key'] ?? ''),
            'amount' => (float) ($invoice['amount'] ?? 0),
            'currency' => (string) ($invoice['currency'] ?? 'KES'),
            'issued_at' => (string) ($invoice['issued_at'] ?? ''),
            'url' => $url,
            'pdf_url' => $pdfUrl,
        ];
    }

    /**
     * @param array<string,mixed> $invoice
     */
    public function buildFilename(array $invoice, string $extension = 'pdf'): string
    {
        $base = preg_replace('/[^a-zA-Z0-9._-]+/', '_', (string) ($invoice['document_number'] ?? 'package_receipt'));
        $base = trim((string) $base, '._-');
        if ($base === '') {
            $base = 'package_receipt';
        }

        return $base . '.' . ltrim($extension, '.');
    }

    /**
     * @param array<string,mixed> $invoice
     */
    public function renderHtml(array $invoice, bool $forPdf = false): string
    {
        $invoice = $this->hydrateInvoice($invoice);
        $renderer = new WorkspacePackageBillingInvoiceRenderer();
        return $forPdf ? $renderer->renderPdfHtml($invoice) : $renderer->renderHtml($invoice);
    }

    /**
     * @param array<string,mixed> $invoice
     */
    public function renderPdfToFile(array $invoice, string $filePath): void
    {
        if (!class_exists('TCPDF')) {
            throw new \RuntimeException('TCPDF library is not available.');
        }

        $invoice = $this->hydrateInvoice($invoice);
        $renderer = new WorkspacePackageBillingInvoiceRenderer();
        $pdf = new \TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->SetCreator(function_exists('brandProductName') ? brandProductName() : 'CRM');
        $pdf->SetAuthor($this->sellerLegalName());
        $pdf->SetTitle((string) ($invoice['document_number'] ?? 'Package Receipt'));
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(10, 10, 10);
        $pdf->AddPage();
        $pdf->writeHTML($renderer->renderPdfHtml($invoice), true, false, true, false, '');
        $pdf->Output($filePath, 'F');

        if (!is_file($filePath) || filesize($filePath) === 0) {
            throw new \RuntimeException('Package receipt PDF could not be generated.');
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private function loadSubscriptionTransactionContext(int $billingTransactionId): ?array
    {
        $row = Database::queryOne(
            "SELECT bt.id AS billing_transaction_id, bt.workspace_id, bt.checkout_session_id, bt.subscription_id,
                    bt.provider, bt.provider_reference, bt.provider_subscription_code, bt.provider_customer_code,
                    bt.transaction_type, bt.transaction_status, bt.payment_mode, bt.flow_type, bt.amount, bt.currency,
                    bt.metadata_json AS transaction_metadata_json, bt.created_at AS transaction_created_at,
                    bcs.user_id AS checkout_user_id, bcs.checkout_type, bcs.billing_plan_price_id AS checkout_billing_plan_price_id,
                    ws.billing_plan_price_id AS subscription_billing_plan_price_id, ws.created_by AS subscription_created_by,
                    ws.current_period_start, ws.current_period_end, ws.provider_subscription_code AS subscription_provider_subscription_code,
                    ws.provider_customer_code AS subscription_provider_customer_code,
                    bpp.id AS billing_plan_price_id, bpp.price_code, bpp.currency AS price_currency, bpp.amount AS price_amount,
                    bpp.interval_unit, bpp.interval_count, bpp.included_tokens, bpp.workspace_id AS price_workspace_id,
                    bp.code AS plan_code, bp.name AS plan_name,
                    w.name AS workspace_name, w.slug AS workspace_slug
             FROM billing_transactions bt
             LEFT JOIN billing_checkout_sessions bcs ON bcs.id = bt.checkout_session_id
             LEFT JOIN workspace_subscriptions ws ON ws.id = bt.subscription_id
             LEFT JOIN billing_plan_prices bpp ON bpp.id = COALESCE(bcs.billing_plan_price_id, ws.billing_plan_price_id)
             LEFT JOIN billing_plans bp ON bp.id = bpp.plan_id
             LEFT JOIN workspaces w ON w.id = bt.workspace_id
             WHERE bt.id = ?
             LIMIT 1",
            [$billingTransactionId]
        );

        return $row ?: null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function loadOperatorActivationContext(int $workspaceId, int $subscriptionId, int $billingPlanPriceId): ?array
    {
        $row = Database::queryOne(
            "SELECT ws.id AS subscription_id, ws.workspace_id, ws.billing_plan_price_id AS subscription_billing_plan_price_id,
                    ws.created_by AS subscription_created_by, ws.current_period_start, ws.current_period_end,
                    ws.provider_subscription_code, ws.provider_customer_code,
                    bpp.id AS billing_plan_price_id, bpp.price_code, bpp.currency AS price_currency, bpp.amount AS price_amount,
                    bpp.interval_unit, bpp.interval_count, bpp.included_tokens, bpp.workspace_id AS price_workspace_id,
                    bp.code AS plan_code, bp.name AS plan_name,
                    w.name AS workspace_name, w.slug AS workspace_slug
             FROM workspace_subscriptions ws
             JOIN billing_plan_prices bpp ON bpp.id = ?
             JOIN billing_plans bp ON bp.id = bpp.plan_id
             JOIN workspaces w ON w.id = ws.workspace_id
             WHERE ws.id = ?
               AND ws.workspace_id = ?
             LIMIT 1",
            [$billingPlanPriceId, $subscriptionId, $workspaceId]
        );

        return $row ?: null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findByDocumentKey(string $documentKey): ?array
    {
        $row = Database::queryOne("SELECT * FROM billing_invoices WHERE document_key = ? LIMIT 1", [$documentKey]);
        return $row ? $this->hydrateInvoice($row) : null;
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function normalizeInvoicePayload(array $payload): array
    {
        $amount = round(max(0.0, (float) ($payload['amount'] ?? 0)), 2);
        $currency = strtoupper(trim((string) ($payload['currency'] ?? 'KES'))) ?: 'KES';
        $periodStart = $this->nullableDateTime($payload['period_start'] ?? null);
        $periodEnd = $this->nullableDateTime($payload['period_end'] ?? null);
        $packageName = trim((string) ($payload['package_name'] ?? 'Workspace package')) ?: 'Workspace package';
        $packageCode = trim((string) ($payload['package_code'] ?? 'package')) ?: 'package';
        $buyer = (array) ($payload['buyer'] ?? []);
        $workspace = (array) ($payload['workspace'] ?? []);

        return array_merge($payload, [
            'package_name' => $packageName,
            'package_code' => $packageCode,
            'currency' => $currency,
            'amount' => $amount,
            'tax_total' => 0.0,
            'grand_total' => $amount,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'buyer_user_id' => !empty($buyer['user_id']) ? (int) $buyer['user_id'] : null,
            'buyer_email' => (string) ($buyer['email'] ?? ''),
            'buyer_workspace_name' => (string) (($workspace['name'] ?? '') ?: ($buyer['workspace_name'] ?? '')),
            'buyer_workspace_slug' => (string) (($workspace['slug'] ?? '') ?: ($buyer['workspace_slug'] ?? '')),
            'line_items' => [[
                'description' => $packageName . ' - ' . $this->cadenceLabel((string) ($payload['metadata']['interval_unit'] ?? ''), (int) ($payload['metadata']['interval_count'] ?? 1)),
                'quantity' => 1,
                'unit_price' => $amount,
                'amount' => $amount,
                'tax_amount' => 0.0,
            ]],
        ]);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>|null
     */
    private function createInvoice(string $documentKey, array $payload): ?array
    {
        $issuedAt = date('Y-m-d H:i:s');
        $documentNumber = $this->documentNumber($documentKey, $issuedAt);
        $metadata = array_filter((array) ($payload['metadata'] ?? []), static fn($value): bool => $value !== null && $value !== '');

        Database::execute(
            "INSERT INTO billing_invoices
                (document_key, document_number, workspace_id, subscription_id, billing_plan_price_id,
                 billing_transaction_id, checkout_session_id, negotiated_offer_id, provider, provider_reference,
                 provider_subscription_code, provider_customer_code, package_code, package_name,
                 buyer_workspace_name, buyer_workspace_slug, buyer_user_id, buyer_email, seller_legal_name,
                 currency, amount, tax_total, grand_total, period_start, period_end, line_items_json, metadata_json, issued_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 id = LAST_INSERT_ID(id),
                 negotiated_offer_id = COALESCE(VALUES(negotiated_offer_id), negotiated_offer_id),
                 updated_at = NOW()",
            [
                $documentKey,
                $documentNumber,
                (int) $payload['workspace_id'],
                (int) $payload['subscription_id'],
                (int) $payload['billing_plan_price_id'],
                $payload['billing_transaction_id'],
                $payload['checkout_session_id'],
                $payload['negotiated_offer_id'],
                (string) ($payload['provider'] ?? ''),
                (string) ($payload['provider_reference'] ?? ''),
                (string) ($payload['provider_subscription_code'] ?? ''),
                (string) ($payload['provider_customer_code'] ?? ''),
                (string) $payload['package_code'],
                (string) $payload['package_name'],
                (string) ($payload['buyer_workspace_name'] ?? ''),
                (string) ($payload['buyer_workspace_slug'] ?? ''),
                $payload['buyer_user_id'],
                (string) ($payload['buyer_email'] ?? ''),
                $this->sellerLegalName(),
                (string) $payload['currency'],
                (float) $payload['amount'],
                (float) $payload['tax_total'],
                (float) $payload['grand_total'],
                $payload['period_start'],
                $payload['period_end'],
                json_encode((array) ($payload['line_items'] ?? []), JSON_UNESCAPED_SLASHES),
                json_encode($metadata, JSON_UNESCAPED_SLASHES),
                $issuedAt,
            ]
        );

        return $this->findById((int) Database::lastInsertId());
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function hydrateInvoice(array $row): array
    {
        if (isset($row['line_items_json']) && !isset($row['line_items'])) {
            $decoded = json_decode((string) $row['line_items_json'], true);
            $row['line_items'] = is_array($decoded) ? $decoded : [];
        }

        if (isset($row['metadata_json']) && !isset($row['metadata'])) {
            $decoded = json_decode((string) $row['metadata_json'], true);
            $row['metadata'] = is_array($decoded) ? $decoded : [];
        }

        $row['summary'] = $this->summary($row);
        return $row;
    }

    private function documentNumber(string $documentKey, string $issuedAt): string
    {
        $date = date('Ymd', strtotime($issuedAt) ?: time());
        return 'PKG-' . $date . '-' . strtoupper(substr(hash('sha256', $documentKey), 0, 10));
    }

    /**
     * @param array<string,mixed> $workspace
     */
    private function isPackageExempt(int $workspaceId, array $workspace = []): bool
    {
        return (new DefaultWorkspacePackageExemptionService())->isExempt($workspaceId, $workspace);
    }

    /**
     * @return array{user_id:?int,email:string}
     */
    private function resolveBuyerSnapshot(int $workspaceId, int $preferredUserId = 0, int $subscriptionCreatedBy = 0): array
    {
        $userId = $preferredUserId > 0 ? $preferredUserId : 0;
        if ($userId <= 0) {
            $owner = Database::queryOne(
                "SELECT user_id
                 FROM workspace_memberships
                 WHERE workspace_id = ?
                   AND membership_status = 'active'
                 ORDER BY is_owner DESC, FIELD(role_slug, 'owner', 'admin', 'accountant', 'expert', 'sales', 'marketing', 'viewer'), id ASC
                 LIMIT 1",
                [$workspaceId]
            );
            $userId = (int) ($owner['user_id'] ?? 0);
        }
        if ($userId <= 0 && $subscriptionCreatedBy > 0) {
            $userId = $subscriptionCreatedBy;
        }

        $email = '';
        if ($userId > 0) {
            $user = Database::queryOne("SELECT email FROM users WHERE id = ? LIMIT 1", [$userId]);
            $email = (string) ($user['email'] ?? '');
        }

        return [
            'user_id' => $userId > 0 ? $userId : null,
            'email' => $email,
        ];
    }

    private function resolveNegotiatedOfferId(int $workspaceId, int $billingPlanPriceId): ?int
    {
        if (!Database::tableExists('workspace_negotiated_package_offers') || $workspaceId <= 0 || $billingPlanPriceId <= 0) {
            return null;
        }

        $offer = Database::queryOne(
            "SELECT id
             FROM workspace_negotiated_package_offers
             WHERE workspace_id = ?
               AND negotiated_billing_plan_price_id = ?
             ORDER BY id DESC
             LIMIT 1",
            [$workspaceId, $billingPlanPriceId]
        );

        $offerId = (int) ($offer['id'] ?? 0);
        return $offerId > 0 ? $offerId : null;
    }

    private function sellerLegalName(): string
    {
        $legalName = (new PlatformLegalIdentityService())->systemLegalName();
        if ($legalName !== '') {
            return $legalName;
        }

        if (!empty($_ENV['COMPANY_NAME'])) {
            return (string) $_ENV['COMPANY_NAME'];
        }

        return function_exists('brandProductName') ? brandProductName() : 'CRM';
    }

    private function cadenceLabel(string $intervalUnit, int $intervalCount): string
    {
        $intervalUnit = trim($intervalUnit) !== '' ? trim($intervalUnit) : 'monthly';
        $intervalCount = max(1, $intervalCount);
        $unit = str_replace('_', ' ', $intervalUnit);
        if ($intervalCount === 1) {
            return ucfirst($unit) . ' package subscription';
        }

        return $intervalCount . ' ' . $unit . ' package subscription';
    }

    private function nullableDateTime(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

}
