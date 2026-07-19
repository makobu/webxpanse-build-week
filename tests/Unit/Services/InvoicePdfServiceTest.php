<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\InvoicePdfService;
use CRM\Services\InvoiceRenderer;
use CRM\Services\InvoiceTemplateService;
use PHPUnit\Framework\TestCase;

class InvoicePdfServiceTest extends TestCase
{
    public function testRenderToTemporaryFileCreatesNonEmptyPdf(): void
    {
        if (!class_exists('TCPDF')) {
            $this->markTestSkipped('TCPDF is not installed in this environment.');
        }

        $invoice = (new InvoiceTemplateService())->buildSampleInvoice('invoice');
        $service = new InvoicePdfService();
        $tempPdf = $service->renderToTemporaryFile($invoice, 'invoice-pdf-test_');

        try {
            $this->assertFileExists($tempPdf['path']);
            $this->assertGreaterThan(0, filesize($tempPdf['path']));
            $this->assertSame($service->buildFilename($invoice), $tempPdf['filename']);
        } finally {
            @unlink($tempPdf['path']);
        }
    }

    public function testPdfHtmlUsesTcpdfSafeLayout(): void
    {
        $invoice = (new InvoiceTemplateService())->buildSampleInvoice('invoice');
        $invoice['line_items'][0]['description'] = str_repeat('Long service description with implementation details ', 8);

        $html = (new InvoiceRenderer())->renderPdfHtml($invoice, [
            'company_legal_name' => 'Example Company',
            'company_address' => '123 Market Street',
            'company_email' => 'billing@example.test',
            'company_phone' => '+1 555 0101',
            'bank_instructions' => 'Pay via bank transfer.',
        ]);

        $this->assertStringNotContainsString('display:flex', $html);
        $this->assertStringNotContainsString('display:grid', $html);
        $this->assertStringContainsString('From', $html);
        $this->assertStringContainsString('Bill To', $html);
        $this->assertStringContainsString('Line Total', $html);
        $this->assertStringContainsString('Payment Instructions', $html);
        $this->assertStringContainsString('Long service description with implementation details', $html);
        $this->assertStringContainsString('width="38%"', $html);
    }

    public function testPdfHtmlConstrainsLogoAndSupportsCreditNoteLabel(): void
    {
        $invoice = (new InvoiceTemplateService())->buildSampleInvoice('credit_note');

        $html = (new InvoiceRenderer())->renderPdfHtml($invoice, [
            'logo_asset_path' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9s6fP5wAAAAASUVORK5CYII=',
            'company_legal_name' => 'Example Company',
            'company_address' => '123 Market Street',
            'company_email' => 'billing@example.test',
            'bank_instructions' => 'Pay via bank transfer.',
        ]);

        $this->assertStringContainsString('Credit Note', $html);
        $this->assertStringContainsString('width="150"', $html);
        $this->assertStringContainsString('height="55"', $html);
        $this->assertStringContainsString('Company logo', $html);
    }
}
