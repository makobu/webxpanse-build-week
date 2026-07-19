<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\InvoiceRenderer;
use CRM\Services\InvoiceTemplateService;
use CRM\Tests\TestCase;

class InvoiceTemplateServiceTest extends TestCase
{
    public function testAvailableThemesAreFixed(): void
    {
        $service = new InvoiceTemplateService();
        $this->assertSame([
            'classic' => 'Classic',
            'minimal' => 'Minimal',
            'bold' => 'Bold',
        ], $service->getAvailableThemes());
    }

    public function testAvailableTemplatesExposeMetadata(): void
    {
        $service = new InvoiceTemplateService();
        $templates = $service->getAvailableTemplates();

        $this->assertCount(3, $templates);
        $this->assertSame('classic', $templates[0]['key']);
        $this->assertNotEmpty($templates[0]['description']);
        $this->assertContains('quote', $templates[0]['supported_document_types']);
    }

    public function testResolveThemeFallsBackToClassic(): void
    {
        $service = new InvoiceTemplateService();
        $this->assertSame('classic', $service->resolveTheme(''));
        $this->assertSame('classic', $service->resolveTheme('weird'));
        $this->assertSame('bold', $service->resolveTheme('bold'));
        $this->assertSame('minimal', $service->resolveTemplateKey('minimal'));
    }

    public function testBuildSampleInvoiceSupportsDocumentTypes(): void
    {
        $service = new InvoiceTemplateService();
        $quote = $service->buildSampleInvoice('quote');
        $proforma = $service->buildSampleInvoice('proforma');
        $invoice = $service->buildSampleInvoice('invoice');
        $creditNote = $service->buildSampleInvoice('credit_note');

        $this->assertSame('quote', $quote['document_type']);
        $this->assertSame('proforma', $proforma['document_type']);
        $this->assertSame('invoice', $invoice['document_type']);
        $this->assertSame('credit_note', $creditNote['document_type']);
        $this->assertSame('Quote Preview', $quote['title']);
        $this->assertSame('Proforma Preview', $proforma['title']);
        $this->assertSame('Invoice Preview', $invoice['title']);
        $this->assertSame('Credit Note Preview', $creditNote['title']);
        $this->assertNotEmpty($invoice['line_items']);
    }

    public function testRendererChangesByTheme(): void
    {
        $service = new InvoiceTemplateService();
        $sample = $service->buildSampleInvoice('invoice');
        $renderer = new InvoiceRenderer();

        $classic = $renderer->renderHtml($sample, ['visual_theme' => 'classic']);
        $minimal = $renderer->renderHtml($sample, ['visual_theme' => 'minimal']);
        $bold = $renderer->renderHtml($sample, ['visual_theme' => 'bold']);

        $this->assertNotSame($classic, $minimal);
        $this->assertNotSame($minimal, $bold);
        $this->assertStringContainsString('#f6f3ee', $classic);
        $this->assertStringContainsString('#ffffff', $minimal);
        $this->assertStringContainsString('#fff7ed', $bold);
    }

    public function testRendererIncludesLogoWhenConfigured(): void
    {
        $baseDir = dirname(__DIR__, 3) . '/uploads/test-assets';
        if (!is_dir($baseDir)) {
            mkdir($baseDir, 0777, true);
        }
        $tmpFile = $baseDir . '/invoice-logo-test.png';
        $pngData = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9s6fP5wAAAAASUVORK5CYII=');
        file_put_contents($tmpFile, $pngData);

        $relativePath = 'uploads/test-assets/invoice-logo-test.png';
        $service = new InvoiceTemplateService();
        $sample = $service->buildSampleInvoice('invoice');
        $html = (new InvoiceRenderer())->renderHtml($sample, [
            'visual_theme' => 'classic',
            'logo_asset_path' => $relativePath,
        ]);

        $this->assertStringContainsString('Company logo', $html);
        $this->assertStringContainsString('data:image/', $html);
    }

    public function testDocumentTemplateKeyOverridesWorkspaceDefault(): void
    {
        $service = new InvoiceTemplateService();
        $sample = $service->buildSampleInvoice('invoice');
        $sample['template_key'] = 'bold';

        $vm = $service->buildViewModel($sample, ['default_template_key' => 'minimal']);

        $this->assertSame('bold', $vm['template_key']);
        $this->assertSame('Bold', $vm['template_label']);
    }
}
