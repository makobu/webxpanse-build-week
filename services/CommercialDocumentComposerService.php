<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\CommercialAutomationConfig;
use CRM\Modules\InvoiceSettings;
use CRM\Modules\Invoices;

class CommercialDocumentComposerService
{
    private Invoices $invoices;
    private InvoiceSettings $invoiceSettings;
    private InvoiceTemplateService $templateService;
    private InvoiceDeliveryReadinessService $readinessService;
    private CommercialAutomationConfig $commercialConfig;

    public function __construct(
        ?Invoices $invoices = null,
        ?InvoiceSettings $invoiceSettings = null,
        ?InvoiceTemplateService $templateService = null,
        ?InvoiceDeliveryReadinessService $readinessService = null,
        ?CommercialAutomationConfig $commercialConfig = null
    ) {
        $this->invoices = $invoices ?? new Invoices();
        $this->invoiceSettings = $invoiceSettings ?? new InvoiceSettings();
        $this->templateService = $templateService ?? new InvoiceTemplateService();
        $this->readinessService = $readinessService ?? new InvoiceDeliveryReadinessService($this->invoiceSettings);
        $this->commercialConfig = $commercialConfig ?? new CommercialAutomationConfig();
    }

    public function compose(array $payload): array
    {
        $settings = $this->loadSettings();
        $commercial = $this->loadCommercialConfig();

        $linkedDeal = $this->loadRow('deals', $this->normalizeId($payload['deal_id'] ?? null));
        $linkedContact = $this->loadRow('contacts', $this->normalizeId($payload['contact_id'] ?? ($linkedDeal['contact_id'] ?? null)));
        $linkedCompany = $this->loadRow('companies', $this->normalizeId($payload['company_id'] ?? ($linkedDeal['company_id'] ?? null)));

        $stage = strtolower(trim((string) ($linkedDeal['stage'] ?? '')));
        $documentType = $this->resolveDocumentType((string) ($payload['document_type'] ?? 'invoice'));
        $recommendedDocumentType = $this->resolveRecommendedDocumentType($stage, $documentType, $settings, $commercial);
        $templateKey = $this->templateService->resolveTemplateKey((string) ($payload['template_key'] ?? ($settings['default_template_key'] ?? 'classic')));
        $issueDate = $this->normalizeDate((string) ($payload['issue_date'] ?? ''), date('Y-m-d'));
        $paymentTermsDays = max(1, (int) ($payload['payment_terms_days'] ?? ($settings['default_payment_terms_days'] ?? 14)));
        $taxMode = $this->resolveTaxMode((string) ($payload['tax_mode'] ?? ($settings['default_tax_mode'] ?? 'exclusive')));
        $taxRate = max(0.0, (float) ($payload['tax_rate'] ?? ($settings['default_tax_rate'] ?? 0)));
        $currency = strtoupper(trim((string) ($payload['currency'] ?? ($linkedDeal['currency'] ?? ($settings['default_currency'] ?? 'USD')))));
        if ($currency === '') {
            $currency = 'USD';
        }

        $defaultDueDate = date('Y-m-d', strtotime($issueDate . ' +' . $paymentTermsDays . ' days'));
        $defaultValidUntil = date('Y-m-d', strtotime($issueDate . ' +' . max(1, (int) ($settings['default_validity_days'] ?? 14)) . ' days'));

        $autofill = $this->buildAutofill($linkedDeal, $linkedContact, $linkedCompany, $documentType, $settings, $commercial, [
            'issue_date' => $issueDate,
            'payment_terms_days' => $paymentTermsDays,
            'default_due_date' => $defaultDueDate,
            'default_valid_until' => $defaultValidUntil,
            'currency' => $currency,
            'tax_mode' => $taxMode,
            'tax_rate' => $taxRate,
            'recommended_document_type' => $recommendedDocumentType,
        ]);

        $lineItems = $this->prepareLineItems(
            $this->normalizeLineItems($payload['line_items'] ?? []),
            $taxRate,
            $taxMode
        );
        $totals = $this->calculateTotals($lineItems, $taxMode);

        $invoice = [
            'id' => 0,
            'document_type' => $documentType,
            'status' => 'draft',
            'invoice_number' => trim((string) ($payload['invoice_number'] ?? '')) ?: $this->buildPreviewNumber($documentType, $settings),
            'revision_number' => 1,
            'deal_id' => !empty($linkedDeal['id']) ? (int) $linkedDeal['id'] : null,
            'contact_id' => !empty($linkedContact['id']) ? (int) $linkedContact['id'] : null,
            'company_id' => !empty($linkedCompany['id']) ? (int) $linkedCompany['id'] : null,
            'assigned_to' => $this->normalizeId($payload['assigned_to'] ?? ($autofill['assigned_to'] ?? null)),
            'currency' => $currency,
            'issue_date' => $issueDate,
            'due_date' => $this->normalizeDate((string) ($payload['due_date'] ?? ''), (string) ($autofill['due_date'] ?? $defaultDueDate)),
            'valid_until' => $this->normalizeDate((string) ($payload['valid_until'] ?? ''), (string) ($autofill['valid_until'] ?? $defaultValidUntil)),
            'payment_terms_days' => $paymentTermsDays,
            'tax_mode' => $taxMode,
            'tax_rate' => $taxRate,
            'title' => trim((string) ($payload['title'] ?? '')) ?: (string) ($autofill['title'] ?? $this->defaultTitle($documentType, $linkedDeal)),
            'intro_text' => trim((string) ($payload['intro_text'] ?? '')) ?: (string) ($autofill['intro_text'] ?? $this->defaultIntroText($documentType, $settings)),
            'notes' => (string) ($payload['notes'] ?? ((string) ($settings['default_notes'] ?? ''))),
            'terms' => (string) ($payload['terms'] ?? ((string) ($settings['default_terms'] ?? ''))),
            'billing_name' => (string) ($payload['billing_name'] ?? ($autofill['billing_name'] ?? '')),
            'billing_email' => (string) ($payload['billing_email'] ?? ($autofill['billing_email'] ?? '')),
            'billing_phone' => (string) ($payload['billing_phone'] ?? ($autofill['billing_phone'] ?? '')),
            'billing_address' => (string) ($payload['billing_address'] ?? ($autofill['billing_address'] ?? '')),
            'shipping_address' => (string) ($payload['shipping_address'] ?? ($autofill['shipping_address'] ?? '')),
            'template_key' => $templateKey,
            'line_items' => $lineItems,
            'subtotal' => $totals['subtotal'],
            'discount_total' => $totals['discount_total'],
            'tax_total' => $totals['tax_total'],
            'grand_total' => $totals['grand_total'],
            'amount_paid' => 0.0,
            'balance_due' => $totals['grand_total'],
            'deal_stage' => $linkedDeal['stage'] ?? '',
            'deal_title' => $linkedDeal['title'] ?? '',
            'contact_email' => $linkedContact['email'] ?? '',
            'contact_phone' => $linkedContact['phone'] ?? '',
        ];

        $readiness = $this->readinessService->compile($invoice);
        $suggestedProducts = $this->resolveSuggestedProducts($invoice);
        $guidance = $this->buildGuidance($invoice, $recommendedDocumentType, $stage, $readiness, $suggestedProducts);

        return [
            'invoice' => $invoice,
            'settings' => $settings,
            'autofill' => $autofill,
            'readiness' => $readiness,
            'guidance' => $guidance,
            'suggested_products' => $suggestedProducts,
            'deal_line_items' => $this->loadDealLineItems(!empty($linkedDeal['id']) ? (int) $linkedDeal['id'] : null, $taxRate, $taxMode),
            'linked_entities' => [
                'deal' => $this->reduceDeal($linkedDeal),
                'contact' => $this->reduceContact($linkedContact),
                'company' => $this->reduceCompany($linkedCompany),
            ],
        ];
    }

    public function renderPreviewHtml(array $payload): string
    {
        $draft = $this->compose($payload);
        return (new InvoiceRenderer())->renderHtml($draft['invoice'], $draft['settings']);
    }

    private function loadSettings(): array
    {
        try {
            return $this->invoiceSettings->get();
        } catch (\Throwable $e) {
            return [
                'default_currency' => 'USD',
                'default_tax_mode' => 'exclusive',
                'default_tax_rate' => 0.0,
                'default_payment_terms_days' => 14,
                'default_validity_days' => 14,
                'default_notes' => 'Thank you for your business.',
                'default_terms' => 'Payment due within the stated terms.',
                'default_template_key' => 'classic',
                'proposal_intro_text' => 'Prepared for your review. Please see the pricing and terms below.',
                'company_legal_name' => '',
                'company_email' => '',
                'company_address' => '',
                'logo_asset_path' => '',
                'bank_instructions' => '',
                'ai_allowed_document_types_by_stage' => [
                    'proposal' => ['quote', 'proforma'],
                    'negotiation' => ['quote', 'proforma', 'invoice'],
                    'closed_won' => ['invoice'],
                ],
            ];
        }
    }

    private function loadCommercialConfig(): array
    {
        try {
            return $this->commercialConfig->get();
        } catch (\Throwable $e) {
            return [
                'default_document_by_stage' => [
                    'proposal' => 'quote',
                    'negotiation' => 'quote',
                    'closed_won' => 'invoice',
                ],
            ];
        }
    }

    private function buildAutofill(array $deal, array $contact, array $company, string $documentType, array $settings, array $commercial, array $resolved): array
    {
        $billingName = trim((string) (($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? '')));
        if ($billingName === '') {
            $billingName = trim((string) ($company['name'] ?? ''));
        }

        $billingEmail = trim((string) ($contact['email'] ?? ($company['email'] ?? '')));
        $billingPhone = trim((string) ($contact['phone'] ?? ($company['phone'] ?? '')));
        $billingAddress = trim((string) ($contact['address'] ?? ($company['address'] ?? '')));

        return [
            'deal_id' => !empty($deal['id']) ? (int) $deal['id'] : null,
            'contact_id' => !empty($contact['id']) ? (int) $contact['id'] : (!empty($deal['contact_id']) ? (int) $deal['contact_id'] : null),
            'company_id' => !empty($company['id']) ? (int) $company['id'] : (!empty($deal['company_id']) ? (int) $deal['company_id'] : null),
            'assigned_to' => !empty($deal['assigned_to']) ? (int) $deal['assigned_to'] : null,
            'currency' => (string) $resolved['currency'],
            'tax_mode' => (string) $resolved['tax_mode'],
            'tax_rate' => (float) $resolved['tax_rate'],
            'payment_terms_days' => (int) $resolved['payment_terms_days'],
            'issue_date' => (string) $resolved['issue_date'],
            'due_date' => $documentType === 'credit_note' ? (string) $resolved['issue_date'] : (string) $resolved['default_due_date'],
            'valid_until' => (string) $resolved['default_valid_until'],
            'title' => $this->defaultTitle($documentType, $deal),
            'intro_text' => $this->defaultIntroText($documentType, $settings),
            'billing_name' => $billingName,
            'billing_email' => $billingEmail,
            'billing_phone' => $billingPhone,
            'billing_address' => $billingAddress,
            'shipping_address' => trim((string) ($company['address'] ?? $contact['address'] ?? '')),
            'recommended_document_type' => (string) $resolved['recommended_document_type'],
            'document_type_options' => $settings['ai_allowed_document_types_by_stage'][strtolower((string) ($deal['stage'] ?? ''))] ?? [],
            'default_document_by_stage' => $commercial['default_document_by_stage'] ?? [],
        ];
    }

    private function buildGuidance(array $invoice, string $recommendedDocumentType, string $stage, array $readiness, array $suggestedProducts): array
    {
        $rationale = [];
        $selectedLabel = $this->documentLabel((string) ($invoice['document_type'] ?? 'invoice'));
        $recommendedLabel = $this->documentLabel($recommendedDocumentType);
        $stageLabel = $stage !== '' ? ucwords(str_replace('_', ' ', $stage)) : 'Unlinked';

        if ($stage !== '') {
            $rationale[] = $stageLabel . ' stage usually leads with ' . strtolower($recommendedLabel) . ' for this flow.';
        } else {
            $rationale[] = 'No deal is linked yet, so the current document type is being treated as a manual draft.';
        }

        if (($invoice['document_type'] ?? '') !== $recommendedDocumentType && $stage !== '') {
            $rationale[] = 'You can keep the current ' . strtolower($selectedLabel) . ', but a ' . strtolower($recommendedLabel) . ' is the default commercialization fit here.';
        }

        if ($suggestedProducts !== []) {
            $rationale[] = count($suggestedProducts) . ' catalog recommendation' . (count($suggestedProducts) === 1 ? ' is' : 's are') . ' available for this draft.';
        }

        $recommendedAction = 'save_and_review';
        $summary = 'This draft is ready to save and continue in the commercial workflow.';

        if (!empty($readiness['blocking_issues'])) {
            $recommendedAction = 'complete_required_details';
            $summary = 'Finish the blocking details below before this document is ready for delivery.';
        } elseif (($invoice['document_type'] ?? '') === 'credit_note') {
            $recommendedAction = 'save_and_review';
            $summary = 'Credit note is ready to save and review before sharing with the customer.';
        } elseif (($invoice['document_type'] ?? '') === 'invoice') {
            $summary = 'Invoice draft is ready to save and review/send once it is created.';
        } else {
            $summary = $selectedLabel . ' draft is ready to save and review/send once it is created.';
        }

        return [
            'stage' => $stage,
            'stage_label' => $stageLabel,
            'selected_document_type' => $invoice['document_type'] ?? 'invoice',
            'recommended_document_type' => $recommendedDocumentType,
            'recommended_document_label' => $recommendedLabel,
            'recommended_action' => $recommendedAction,
            'summary' => $summary,
            'headline' => ($invoice['document_type'] ?? '') === $recommendedDocumentType
                ? $selectedLabel . ' fits the current commercial context.'
                : 'Consider a ' . strtolower($recommendedLabel) . ' for this stage.',
            'rationale' => $rationale,
        ];
    }

    private function resolveSuggestedProducts(array $invoice): array
    {
        try {
            return $this->invoices->getSuggestedProducts(
                !empty($invoice['deal_id']) ? (int) $invoice['deal_id'] : null,
                !empty($invoice['contact_id']) ? (int) $invoice['contact_id'] : null,
                !empty($invoice['company_id']) ? (int) $invoice['company_id'] : null,
                (string) ($invoice['document_type'] ?? 'invoice'),
                null
            );
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function loadDealLineItems(?int $dealId, float $taxRate, string $taxMode): array
    {
        if (!$dealId) {
            return [];
        }

        try {
            $rows = Database::query("SELECT * FROM deal_line_items WHERE deal_id = ? ORDER BY sort_order ASC, id ASC", [$dealId]);
        } catch (\Throwable $e) {
            return [];
        }

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'product_id' => !empty($row['product_id']) ? (int) $row['product_id'] : null,
                'description' => (string) ($row['description'] ?? ''),
                'pricing_context' => (string) ($row['pricing_context'] ?? ''),
                'quantity' => (float) ($row['quantity'] ?? 1),
                'unit_price' => (float) ($row['unit_price'] ?? 0),
                'discount_percent' => (float) ($row['discount_percent'] ?? 0),
                'tax_percent' => (float) ($row['tax_percent'] ?? $taxRate),
            ];
        }

        return $this->prepareLineItems($items, $taxRate, $taxMode);
    }

    private function prepareLineItems(array $lineItems, float $headerTaxRate, string $taxMode): array
    {
        $prepared = [];
        foreach ($lineItems as $lineItem) {
            if (!is_array($lineItem)) {
                continue;
            }

            $product = [];
            if (!empty($lineItem['product_id'])) {
                $product = $this->loadRow('products', (int) $lineItem['product_id']);
            }

            $description = trim((string) ($lineItem['description'] ?? $lineItem['product_name'] ?? ''));
            if ($description === '') {
                $description = trim((string) ($product['description'] ?? $product['name'] ?? ''));
            }
            if ($description === '') {
                continue;
            }

            $candidate = [
                'product_id' => !empty($lineItem['product_id']) ? (int) $lineItem['product_id'] : null,
                'description' => $description,
                'pricing_context' => (string) ($lineItem['pricing_context'] ?? ($product['pricing_info'] ?? '')),
                'quantity' => max(0.01, (float) ($lineItem['quantity'] ?? 1)),
                'unit_price' => max(0.0, (float) ($lineItem['unit_price'] ?? ($product['unit_price'] ?? 0))),
                'discount_percent' => max(0.0, (float) ($lineItem['discount_percent'] ?? 0)),
                'tax_percent' => max(0.0, (float) ($lineItem['tax_percent'] ?? $headerTaxRate)),
                'product_name' => (string) ($product['name'] ?? ($lineItem['product_name'] ?? '')),
            ];

            if ($candidate['unit_price'] <= 0) {
                try {
                    $inference = $this->invoices->inferLineItemPrice($candidate);
                    if ((float) ($inference['unit_price'] ?? 0) > 0) {
                        $candidate['unit_price'] = (float) $inference['unit_price'];
                    }
                    if ($candidate['pricing_context'] === '' && !empty($inference['reasoning'])) {
                        $candidate['pricing_context'] = (string) $inference['reasoning'];
                    }
                } catch (\Throwable $e) {
                }
            }

            $gross = $candidate['quantity'] * $candidate['unit_price'];
            $discountAmount = round($gross * ($candidate['discount_percent'] / 100), 2);
            $baseAmount = $gross - $discountAmount;
            $taxAmount = $taxMode === 'none' ? 0.0 : round($baseAmount * ($candidate['tax_percent'] / 100), 2);
            $lineTotal = $taxMode === 'inclusive' ? round($baseAmount, 2) : round($baseAmount + $taxAmount, 2);

            $candidate['discount_amount'] = $discountAmount;
            $candidate['tax_amount'] = $taxAmount;
            $candidate['line_total'] = $lineTotal;

            $prepared[] = $candidate;
        }

        return $prepared;
    }

    private function calculateTotals(array $lineItems, string $taxMode): array
    {
        $grossSubtotal = 0.0;
        $discountTotal = 0.0;
        $taxTotal = 0.0;
        $grandTotal = 0.0;

        foreach ($lineItems as $lineItem) {
            $grossSubtotal += (float) (($lineItem['quantity'] ?? 0) * ($lineItem['unit_price'] ?? 0));
            $discountTotal += (float) ($lineItem['discount_amount'] ?? 0);
            $taxTotal += (float) ($lineItem['tax_amount'] ?? 0);
            $grandTotal += (float) ($lineItem['line_total'] ?? 0);
        }

        $subtotal = $grossSubtotal - $discountTotal;
        if ($taxMode === 'none') {
            $taxTotal = 0.0;
            $grandTotal = $subtotal;
        }

        return [
            'subtotal' => round($subtotal, 2),
            'discount_total' => round($discountTotal, 2),
            'tax_total' => round($taxTotal, 2),
            'grand_total' => round($grandTotal, 2),
        ];
    }

    private function normalizeLineItems(mixed $lineItems): array
    {
        if (is_string($lineItems)) {
            $decoded = json_decode($lineItems, true);
            $lineItems = is_array($decoded) ? $decoded : [];
        }

        return is_array($lineItems) ? array_values($lineItems) : [];
    }

    private function buildPreviewNumber(string $documentType, array $settings): string
    {
        $year = date('Y');
        $prefix = match ($documentType) {
            'quote' => (string) ($settings['quote_prefix'] ?? 'QT-'),
            'proforma' => (string) ($settings['proforma_prefix'] ?? 'PF-'),
            'credit_note' => 'CN-',
            default => (string) ($settings['invoice_prefix'] ?? 'INV-'),
        };

        return $prefix . $year . '-DRAFT';
    }

    private function defaultTitle(string $documentType, array $deal): string
    {
        $label = $this->documentLabel($documentType);
        $dealTitle = trim((string) ($deal['title'] ?? ''));
        return $dealTitle !== '' ? $label . ' - ' . $dealTitle : $label;
    }

    private function defaultIntroText(string $documentType, array $settings): string
    {
        return match ($documentType) {
            'quote' => (string) ($settings['proposal_intro_text'] ?? 'Prepared for your review. Please see the pricing and terms below.'),
            'proforma' => 'This proforma invoice outlines the expected charges for approval before final invoicing.',
            'credit_note' => 'This credit note reflects an approved adjustment for the customer account.',
            default => 'Please review the invoice details and payment instructions below.',
        };
    }

    private function resolveRecommendedDocumentType(string $stage, string $fallbackType, array $settings, array $commercial): string
    {
        $candidate = '';
        if ($stage !== '') {
            $candidate = (string) (($commercial['default_document_by_stage'][$stage] ?? '') ?: '');
            $allowed = (array) ($settings['ai_allowed_document_types_by_stage'][$stage] ?? []);
            if ($candidate === '' && $allowed !== []) {
                $candidate = (string) ($allowed[0] ?? '');
            }
        }

        return $this->resolveDocumentType($candidate !== '' ? $candidate : $fallbackType);
    }

    private function resolveDocumentType(string $documentType): string
    {
        return in_array($documentType, Invoices::TYPES, true) ? $documentType : 'invoice';
    }

    private function resolveTaxMode(string $taxMode): string
    {
        return in_array($taxMode, ['exclusive', 'inclusive', 'none'], true) ? $taxMode : 'exclusive';
    }

    private function normalizeDate(string $value, string $fallback): string
    {
        $value = trim($value);
        if ($value !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value;
        }

        return $fallback;
    }

    private function normalizeId(mixed $value): ?int
    {
        $intValue = (int) $value;
        return $intValue > 0 ? $intValue : null;
    }

    private function loadRow(string $table, ?int $id): array
    {
        if (!$id) {
            return [];
        }

        try {
            return Database::queryOne("SELECT * FROM {$table} WHERE id = ?", [$id]) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function reduceDeal(array $deal): array
    {
        if ($deal === []) {
            return [];
        }

        return [
            'id' => (int) ($deal['id'] ?? 0),
            'title' => (string) ($deal['title'] ?? ''),
            'stage' => (string) ($deal['stage'] ?? ''),
            'contact_id' => !empty($deal['contact_id']) ? (int) $deal['contact_id'] : null,
            'company_id' => !empty($deal['company_id']) ? (int) $deal['company_id'] : null,
            'assigned_to' => !empty($deal['assigned_to']) ? (int) $deal['assigned_to'] : null,
        ];
    }

    private function reduceContact(array $contact): array
    {
        if ($contact === []) {
            return [];
        }

        return [
            'id' => (int) ($contact['id'] ?? 0),
            'name' => trim((string) (($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? ''))),
            'email' => (string) ($contact['email'] ?? ''),
            'phone' => (string) ($contact['phone'] ?? ''),
        ];
    }

    private function reduceCompany(array $company): array
    {
        if ($company === []) {
            return [];
        }

        return [
            'id' => (int) ($company['id'] ?? 0),
            'name' => (string) ($company['name'] ?? ''),
        ];
    }

    private function documentLabel(string $documentType): string
    {
        return match ($documentType) {
            'quote' => 'Quote',
            'proforma' => 'Proforma Invoice',
            'credit_note' => 'Credit Note',
            default => 'Invoice',
        };
    }
}
