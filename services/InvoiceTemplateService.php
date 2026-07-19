<?php

namespace CRM\Services;

use CRM\Modules\InvoiceSettings;
use CRM\Modules\Products;

class InvoiceTemplateService
{
    private const DEFAULT_SETTINGS = [
        'company_legal_name' => '',
        'company_tax_id' => '',
        'company_address' => '',
        'company_email' => '',
        'company_phone' => '',
        'logo_asset_path' => '',
        'bank_instructions' => '',
        'footer_text' => '',
        'visual_theme' => 'classic',
        'default_template_key' => 'classic',
        'preview_document_type' => 'invoice',
        'proposal_intro_text' => 'Prepared for your review. Please see the pricing and terms below.',
        'acceptance_instructions' => 'Reply to this message or contact us to confirm acceptance.',
    ];

    public function getAvailableThemes(): array
    {
        return array_column($this->getAvailableTemplates(), 'label', 'key');
    }

    public function getAvailableTemplates(): array
    {
        return [
            [
                'key' => 'classic',
                'label' => 'Classic',
                'description' => 'Balanced commercial layout with warm accents and a traditional invoice structure.',
                'supported_document_types' => ['quote', 'proforma', 'invoice', 'credit_note'],
            ],
            [
                'key' => 'minimal',
                'label' => 'Minimal',
                'description' => 'Clean modern layout with understated styling for simple quote and invoice delivery.',
                'supported_document_types' => ['quote', 'proforma', 'invoice', 'credit_note'],
            ],
            [
                'key' => 'bold',
                'label' => 'Bold',
                'description' => 'High-contrast layout with stronger visual emphasis for proposals and branded billing.',
                'supported_document_types' => ['quote', 'proforma', 'invoice', 'credit_note'],
            ],
        ];
    }

    public function resolveTheme(?string $theme): string
    {
        return $this->resolveTemplateKey($theme);
    }

    public function resolveTemplateKey(?string $templateKey): string
    {
        $templateKey = strtolower(trim((string) $templateKey));
        return array_key_exists($templateKey, $this->getAvailableThemes()) ? $templateKey : 'classic';
    }

    public function buildViewModel(array $invoice, ?array $settingsOverride = null): array
    {
        $settings = $this->loadSettings($settingsOverride);
        $templateKey = $this->resolveTemplateKey((string) ($invoice['template_key'] ?? $settingsOverride['default_template_key'] ?? $settingsOverride['visual_theme'] ?? $settings['default_template_key'] ?? $settings['visual_theme'] ?? 'classic'));
        $invoice['document_type'] = $invoice['document_type'] ?? 'invoice';
        $invoice['status'] = $invoice['status'] ?? 'draft';
        $invoice['currency'] = $invoice['currency'] ?? 'USD';
        $invoice['line_items'] = array_values(is_array($invoice['line_items'] ?? null) ? $invoice['line_items'] : []);
        $invoice['template_key'] = $templateKey;

        return [
            'invoice' => $invoice,
            'settings' => $settings,
            'theme' => $templateKey,
            'template_key' => $templateKey,
            'logo_src' => $this->resolveLogoSource((string) ($settings['logo_asset_path'] ?? '')),
            'title_label' => match ($invoice['document_type']) {
                'quote' => 'Quote',
                'proforma' => 'Proforma Invoice',
                'credit_note' => 'Credit Note',
                default => 'Invoice',
            },
            'theme_label' => $this->getAvailableThemes()[$templateKey],
            'template_label' => $this->getAvailableThemes()[$templateKey],
        ];
    }

    public function buildSampleInvoice(string $documentType = 'invoice', array $settingsOverride = []): array
    {
        $documentType = in_array($documentType, ['quote', 'proforma', 'invoice', 'credit_note'], true) ? $documentType : 'invoice';
        $settings = $this->loadSettings($settingsOverride);
        $today = date('Y-m-d');
        $valid = date('Y-m-d', strtotime($today . ' +14 days'));
        $lineItems = $this->buildPreviewLineItems($settings);
        $totals = $this->calculatePreviewTotals(
            $lineItems,
            (string) ($settings['default_tax_mode'] ?? 'exclusive'),
            (float) ($settings['default_tax_rate'] ?? 0)
        );

        return [
            'id' => 0,
            'document_type' => $documentType,
            'status' => 'draft',
            'invoice_number' => match ($documentType) {
                'quote' => 'QT-2026-0012',
                'proforma' => 'PF-2026-0012',
                'credit_note' => 'CN-2026-0012',
                default => 'INV-2026-0012',
            },
            'revision_number' => 1,
            'currency' => (string) ($settings['default_currency'] ?? 'USD'),
            'issue_date' => $today,
            'due_date' => in_array($documentType, ['invoice', 'credit_note'], true) ? $valid : null,
            'valid_until' => $valid,
            'payment_terms_days' => 14,
            'subtotal' => $totals['subtotal'],
            'discount_total' => $totals['discount_total'],
            'tax_total' => $totals['tax_total'],
            'grand_total' => $totals['grand_total'],
            'amount_paid' => 0.00,
            'balance_due' => $totals['grand_total'],
            'tax_mode' => (string) ($settings['default_tax_mode'] ?? 'exclusive'),
            'tax_rate' => (float) ($settings['default_tax_rate'] ?? 0),
            'title' => match ($documentType) {
                'quote' => 'Quote Preview',
                'proforma' => 'Proforma Preview',
                'credit_note' => 'Credit Note Preview',
                default => 'Invoice Preview',
            },
            'intro_text' => '',
            'notes' => $this->buildPreviewNotes($lineItems),
            'terms' => (string) ($settings['default_terms'] ?? 'Payment due within the stated terms.'),
            'billing_name' => 'Northwind Traders',
            'billing_email' => 'procurement@northwind.example',
            'billing_phone' => '+1 555 0148',
            'billing_address' => "145 Harbor Avenue\nSeattle, WA",
            'shipping_address' => "145 Harbor Avenue\nSeattle, WA",
            'line_items' => $lineItems,
        ];
    }

    private function loadSettings(?array $settingsOverride): array
    {
        $settings = self::DEFAULT_SETTINGS;
        try {
            $settings = array_merge($settings, (new InvoiceSettings())->get());
        } catch (\Throwable $e) {
        }

        if ($settingsOverride) {
            $settings = array_merge($settings, $settingsOverride);
        }

        $settings['default_template_key'] = $this->resolveTemplateKey((string) ($settings['default_template_key'] ?? ($settings['visual_theme'] ?? 'classic')));
        $settings['visual_theme'] = $settings['default_template_key'];
        $settings['preview_document_type'] = in_array((string) ($settings['preview_document_type'] ?? 'invoice'), ['quote', 'proforma', 'invoice', 'credit_note'], true)
            ? (string) $settings['preview_document_type']
            : 'invoice';

        return $settings;
    }

    private function resolveLogoSource(string $path): ?string
    {
        $path = trim($path);
        if ($path === '') {
            return null;
        }
        if (preg_match('#^(https?:)?//#i', $path) || str_starts_with($path, 'data:image/')) {
            return $path;
        }

        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($path, '/\\'));
        $absolutePath = dirname(__DIR__) . DIRECTORY_SEPARATOR . $normalized;
        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            return null;
        }

        $mime = function_exists('mime_content_type') ? (string) mime_content_type($absolutePath) : '';
        if ($mime === '' || strpos($mime, 'image/') !== 0) {
            $ext = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
            $mime = match ($ext) {
                'jpg', 'jpeg' => 'image/jpeg',
                'gif' => 'image/gif',
                'webp' => 'image/webp',
                'svg' => 'image/svg+xml',
                default => 'image/png',
            };
        }

        $contents = @file_get_contents($absolutePath);
        if ($contents === false) {
            return null;
        }

        return 'data:' . $mime . ';base64,' . base64_encode($contents);
    }

    private function buildPreviewLineItems(array $settings): array
    {
        try {
            $products = array_slice((new Products())->list(), 0, 2);
        } catch (\Throwable $e) {
            $products = [];
        }

        if ($products === []) {
            return $this->buildFallbackPreviewLineItems((float) ($settings['default_tax_rate'] ?? 0));
        }

        $taxRate = (float) ($settings['default_tax_rate'] ?? 0);
        $items = [];
        foreach ($products as $index => $product) {
            $quantity = 1.0;
            $unitPrice = $this->resolvePreviewUnitPrice($product);
            $discountAmount = 0.0;
            $taxAmount = round(($unitPrice * $quantity) * ($taxRate / 100), 2);
            $lineTotal = round(($unitPrice * $quantity) - $discountAmount + $taxAmount, 2);

            $items[] = [
                'product_id' => (int) ($product['id'] ?? 0),
                'description' => (string) ($product['name'] ?? ('Product ' . ($index + 1))),
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'discount_percent' => 0,
                'discount_amount' => $discountAmount,
                'tax_percent' => $taxRate,
                'tax_amount' => $taxAmount,
                'line_total' => $lineTotal,
            ];
        }

        return $items;
    }

    private function buildFallbackPreviewLineItems(float $taxRate): array
    {
        return [
            [
                'product_id' => 1,
                'description' => 'Premium installation package',
                'quantity' => 2,
                'unit_price' => 450.00,
                'discount_percent' => 5,
                'discount_amount' => 45.00,
                'tax_percent' => $taxRate > 0 ? $taxRate : 16.00,
                'tax_amount' => 136.80,
                'line_total' => 991.80,
            ],
            [
                'product_id' => 2,
                'description' => 'Annual maintenance support',
                'quantity' => 1,
                'unit_price' => 220.00,
                'discount_percent' => 0,
                'discount_amount' => 0.00,
                'tax_percent' => $taxRate > 0 ? $taxRate : 16.00,
                'tax_amount' => 35.20,
                'line_total' => 255.20,
            ],
        ];
    }

    private function resolvePreviewUnitPrice(array $product): float
    {
        $unitPrice = (float) ($product['unit_price'] ?? 0);
        if ($unitPrice > 0) {
            return round($unitPrice, 2);
        }

        $pricingInfo = trim((string) ($product['pricing_info'] ?? ''));
        if ($pricingInfo !== '' && preg_match('/(?:usd|kes|eur|gbp|zar|\$|€|£)\s*([0-9]+(?:\.[0-9]{1,2})?)|([0-9]+(?:\.[0-9]{1,2})?)\s*(?:per|\/)\s*(?:unit|seat|user|month|item|site|year)/i', $pricingInfo, $matches)) {
            $price = (float) ($matches[1] !== '' ? $matches[1] : ($matches[2] ?? 0));
            if ($price > 0) {
                return round($price, 2);
            }
        }

        return 0.0;
    }

    private function calculatePreviewTotals(array $lineItems, string $taxMode, float $defaultTaxRate): array
    {
        $subtotal = 0.0;
        $discountTotal = 0.0;
        $taxTotal = 0.0;
        $grandTotal = 0.0;

        foreach ($lineItems as $item) {
            $quantity = (float) ($item['quantity'] ?? 0);
            $unitPrice = (float) ($item['unit_price'] ?? 0);
            $lineBase = round($quantity * $unitPrice, 2);
            $discount = (float) ($item['discount_amount'] ?? 0);
            $taxAmount = (float) ($item['tax_amount'] ?? 0);
            $lineGrand = (float) ($item['line_total'] ?? ($lineBase - $discount + $taxAmount));

            $subtotal += $lineBase;
            $discountTotal += $discount;
            $taxTotal += $taxMode === 'none' ? 0.0 : $taxAmount;
            $grandTotal += $lineGrand;
        }

        return [
            'subtotal' => round($subtotal, 2),
            'discount_total' => round($discountTotal, 2),
            'tax_total' => round($taxMode === 'none' ? 0.0 : $taxTotal, 2),
            'grand_total' => round($grandTotal, 2),
        ];
    }

    private function buildPreviewNotes(array $lineItems): string
    {
        if ($lineItems === []) {
            return 'Installation includes site preparation, fitting, and handover.';
        }

        $names = array_values(array_filter(array_map(
            static fn(array $item): string => trim((string) ($item['description'] ?? '')),
            $lineItems
        )));

        if ($names === []) {
            return 'Preview uses your saved invoice settings and current product catalog.';
        }

        return 'Preview line items are drawn from your current product catalog: ' . implode(', ', $names) . '.';
    }
}
