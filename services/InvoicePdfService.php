<?php

namespace CRM\Services;

class InvoicePdfService
{
    public function buildFilename(array $invoice, string $extension = 'pdf'): string
    {
        $base = preg_replace('/[^a-zA-Z0-9._-]+/', '_', (string) ($invoice['invoice_number'] ?? 'document'));
        $base = trim((string) $base, '._-');
        if ($base === '') {
            $base = 'document';
        }

        return $base . '.' . ltrim($extension, '.');
    }

    public function renderToTemporaryFile(array $invoice, string $prefix = 'invoice_pdf_'): array
    {
        $tmpBase = tempnam(sys_get_temp_dir(), $prefix);
        if ($tmpBase === false) {
            throw new \RuntimeException('Could not create temporary file for invoice PDF.');
        }

        $filename = uniqid($prefix, true) . '-' . $this->buildFilename($invoice);
        $pdfFile = dirname($tmpBase) . DIRECTORY_SEPARATOR . $filename;
        @unlink($tmpBase);

        $this->renderToFile($invoice, $pdfFile);

        return [
            'path' => $pdfFile,
            'filename' => $this->buildFilename($invoice),
        ];
    }

    public function renderToFile(array $invoice, string $filePath): void
    {
        if (!class_exists('TCPDF')) {
            throw new \RuntimeException('TCPDF library is not available.');
        }

        $html = (new InvoiceRenderer())->renderPdfHtml($invoice);
        $pdf = new \TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->SetCreator(brandProductName());
        $pdf->SetAuthor($_ENV['COMPANY_NAME'] ?? brandProductName());
        $pdf->SetTitle(($invoice['invoice_number'] ?? 'Document') . ' - ' . ($invoice['title'] ?? ''));
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(10, 10, 10);
        $pdf->AddPage();
        $pdf->writeHTML($html, true, false, true, false, '');
        $pdf->Output($filePath, 'F');

        if (!is_file($filePath) || filesize($filePath) === 0) {
            throw new \RuntimeException('Invoice PDF could not be generated.');
        }
    }
}
