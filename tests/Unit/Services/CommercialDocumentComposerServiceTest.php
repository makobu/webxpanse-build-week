<?php

namespace CRM\Tests\Unit\Services;

use CRM\Modules\CommercialAutomationConfig;
use CRM\Modules\InvoiceSettings;
use CRM\Modules\Invoices;
use CRM\Services\CommercialDocumentComposerService;
use CRM\Services\InvoiceDeliveryReadinessService;
use CRM\Services\InvoiceTemplateService;
use PHPUnit\Framework\TestCase;

class CommercialDocumentComposerServiceTest extends TestCase
{
    public function testComposeBuildsCreditNoteDraftAndGuidance(): void
    {
        $service = new CommercialDocumentComposerService(
            new ComposerInvoicesStub(),
            new ComposerInvoiceSettingsStub(),
            new InvoiceTemplateService(),
            new InvoiceDeliveryReadinessService(new ComposerInvoiceSettingsStub()),
            new ComposerCommercialConfigStub()
        );

        $draft = $service->compose([
            'document_type' => 'credit_note',
            'billing_name' => 'Northwind Traders',
            'billing_email' => 'procurement@northwind.example',
            'billing_address' => '145 Harbor Avenue',
            'line_items' => [[
                'description' => 'Approved service adjustment',
                'quantity' => 2,
                'unit_price' => 100,
                'discount_percent' => 10,
                'tax_percent' => 16,
            ]],
        ]);

        $this->assertSame('credit_note', $draft['invoice']['document_type']);
        $this->assertSame('Credit Note', $draft['guidance']['recommended_document_label']);
        $this->assertSame(180.0, $draft['invoice']['subtotal']);
        $this->assertSame(28.8, $draft['invoice']['tax_total']);
        $this->assertSame(208.8, $draft['invoice']['grand_total']);
        $this->assertTrue($draft['readiness']['is_ready']);
        $this->assertSame('save_and_review', $draft['guidance']['recommended_action']);
    }

    public function testPreviewHtmlReflectsCurrentDraftValues(): void
    {
        $service = new CommercialDocumentComposerService(
            new ComposerInvoicesStub(),
            new ComposerInvoiceSettingsStub(),
            new InvoiceTemplateService(),
            new InvoiceDeliveryReadinessService(new ComposerInvoiceSettingsStub()),
            new ComposerCommercialConfigStub()
        );

        $html = $service->renderPreviewHtml([
            'document_type' => 'quote',
            'title' => 'Quote - ACME rollout',
            'billing_name' => 'ACME Corp',
            'billing_email' => 'ops@acme.example',
            'billing_address' => 'Main Street',
            'line_items' => [[
                'description' => 'Implementation package',
                'quantity' => 1,
                'unit_price' => 450,
                'tax_percent' => 16,
            ]],
        ]);

        $this->assertStringContainsString('Quote - ACME rollout', $html);
        $this->assertStringContainsString('ACME Corp', $html);
        $this->assertStringContainsString('Implementation package', $html);
    }
}

class ComposerInvoicesStub extends Invoices
{
    public function __construct()
    {
    }

    public function getSuggestedProducts(?int $dealId, ?int $contactId, ?int $companyId, ?string $documentType = null, ?int $invoiceId = null): array
    {
        return [[
            'product_id' => 7,
            'name' => 'Priority support',
            'description' => 'Priority support',
            'recommended_quantity' => 1,
            'unit_price' => 120.0,
            'confidence' => 0.94,
            'reason' => 'Matched commercial context',
        ]];
    }

    public function inferLineItemPrice(array $lineItem): array
    {
        return ['unit_price' => (float) ($lineItem['unit_price'] ?? 0), 'reasoning' => 'Stub'];
    }
}

class ComposerInvoiceSettingsStub extends InvoiceSettings
{
    public function get(): array
    {
        return [
            'default_currency' => 'USD',
            'default_tax_mode' => 'exclusive',
            'default_tax_rate' => 16.0,
            'default_payment_terms_days' => 14,
            'default_validity_days' => 14,
            'default_notes' => 'Thank you for your business.',
            'default_terms' => 'Payment due within the stated terms.',
            'default_template_key' => 'classic',
            'visual_theme' => 'classic',
            'proposal_intro_text' => 'Prepared for your review. Please see the pricing and terms below.',
            'company_legal_name' => 'Example Company',
            'company_email' => 'billing@example.test',
            'company_address' => '123 Market Street',
            'company_phone' => '+1 555 0101',
            'company_tax_id' => '',
            'logo_asset_path' => '',
            'bank_instructions' => 'Pay via bank transfer.',
            'footer_text' => 'Thank you.',
            'ai_allowed_document_types_by_stage' => [
                'proposal' => ['quote', 'proforma'],
                'negotiation' => ['quote', 'proforma', 'invoice'],
                'closed_won' => ['invoice'],
            ],
        ];
    }
}

class ComposerCommercialConfigStub extends CommercialAutomationConfig
{
    public function get(?int $workspaceId = null): array
    {
        return [
            'default_document_by_stage' => [
                'proposal' => 'quote',
                'negotiation' => 'quote',
                'closed_won' => 'invoice',
            ],
        ];
    }
}
