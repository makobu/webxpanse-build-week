<?php

namespace CRM\Services;

use CRM\Modules\Invoices;

class InvoiceSendService
{
    private Invoices $invoices;
    private InvoicePdfService $pdfService;
    private EmailService $emailService;
    private InvoiceDeliveryReadinessService $readinessService;
    private InvoiceDeliveryAuditService $auditService;

    public function __construct(
        ?Invoices $invoices = null,
        ?InvoicePdfService $pdfService = null,
        ?EmailService $emailService = null,
        ?InvoiceDeliveryReadinessService $readinessService = null,
        ?InvoiceDeliveryAuditService $auditService = null
    ) {
        $this->invoices = $invoices ?? new Invoices();
        $this->pdfService = $pdfService ?? new InvoicePdfService();
        $this->emailService = $emailService ?? new EmailService();
        $this->readinessService = $readinessService ?? new InvoiceDeliveryReadinessService();
        $this->auditService = $auditService ?? new InvoiceDeliveryAuditService();
    }

    public function send(int $invoiceId, string $recipient, ?int $actorId = null, string $actorType = 'user'): array
    {
        $invoice = $this->invoices->getById($invoiceId);
        if (!$invoice) {
            throw new \RuntimeException('Invoice not found.');
        }

        $recipient = trim($recipient);
        $readiness = $this->readinessService->compile($invoice, $recipient);

        $label = match ($invoice['document_type']) {
            'quote' => 'Quote',
            'proforma' => 'Proforma Invoice',
            'credit_note' => 'Credit Note',
            default => 'Invoice',
        };
        $companyName = $_ENV['COMPANY_NAME'] ?? brandProductName();
        $subject = match ($invoice['document_type']) {
            'quote' => "{$label} {$invoice['invoice_number']} from {$companyName}",
            'proforma' => "{$label} {$invoice['invoice_number']}",
            default => "{$label} {$invoice['invoice_number']} from {$companyName}",
        };

        if (!$readiness['is_ready']) {
            $reason = $this->summarizeIssues($readiness['blocking_issues']);
            $this->auditService->recordEmailDelivery(
                $invoiceId,
                $recipient,
                $subject,
                'failed',
                [
                    'failure_reason' => $reason,
                    'readiness' => $readiness,
                ],
                $actorId,
                $actorType
            );
            $this->invoices->logActivity($invoiceId, 'invoice_send_failed', $actorType, $actorId, 'Invoice email delivery blocked', [
                'recipient' => $recipient,
                'reason' => $reason,
                'readiness' => $readiness,
            ]);
            throw new \RuntimeException($reason);
        }

        $emailBody = $this->buildEmailBody($invoice);
        $tempPdf = $this->pdfService->renderToTemporaryFile($invoice, 'invoice_email_');

        try {
            $result = $this->emailService->sendImmediateDetailed(
                (int) ($invoice['contact_id'] ?? 0),
                $recipient,
                $subject,
                $emailBody['plain'],
                [
                    'body_html' => $emailBody['html'],
                    'attachments' => [$tempPdf['path']],
                    'user_id' => $actorId,
                    'sender_profile' => 'outreach',
                ]
            );
        } finally {
            @unlink($tempPdf['path']);
        }

        if (empty($result['success'])) {
            $failureReason = trim((string) ($result['error'] ?? 'Failed to send invoice email.'));
            $auditDetails = [
                'email_id' => $result['email_id'] ?? null,
                'email_uuid' => $result['email_uuid'] ?? null,
                'provider_key' => $result['provider_key'] ?? null,
                'provider_method' => $result['smtp_method'] ?? null,
                'failure_reason' => $failureReason,
                'readiness' => $readiness,
                'delivery_result' => $result,
            ];
            $this->auditService->recordEmailDelivery($invoiceId, $recipient, $subject, 'failed', $auditDetails, $actorId, $actorType);
            $this->invoices->logActivity($invoiceId, 'invoice_send_failed', $actorType, $actorId, 'Invoice email delivery failed', [
                'recipient' => $recipient,
                'reason' => $failureReason,
                'provider_key' => $result['provider_key'] ?? null,
                'provider_method' => $result['smtp_method'] ?? null,
            ]);
            throw new \RuntimeException($failureReason);
        }

        $this->auditService->recordEmailDelivery(
            $invoiceId,
            $recipient,
            $subject,
            'sent',
            [
                'email_id' => $result['email_id'] ?? null,
                'email_uuid' => $result['email_uuid'] ?? null,
                'provider_key' => $result['provider_key'] ?? null,
                'provider_method' => $result['smtp_method'] ?? null,
                'communication_id' => $result['communication_id'] ?? null,
                'sync_error' => $result['sync_error'] ?? null,
            ],
            $actorId,
            $actorType
        );

        $this->invoices->transitionStatus($invoiceId, 'sent', $actorId, $actorType, 'Document emailed to customer');
        $this->invoices->logActivity($invoiceId, 'invoice_sent', $actorType, $actorId, 'Invoice sent via email', [
            'recipient' => $recipient,
            'provider_key' => $result['provider_key'] ?? null,
            'provider_method' => $result['smtp_method'] ?? null,
            'email_id' => $result['email_id'] ?? null,
        ]);

        return [
            'success' => true,
            'recipient' => $recipient,
            'subject' => $subject,
            'delivery_status' => 'sent',
            'email_id' => $result['email_id'] ?? null,
            'email_uuid' => $result['email_uuid'] ?? null,
            'provider_key' => $result['provider_key'] ?? null,
            'smtp_method' => $result['smtp_method'] ?? null,
            'communication_id' => $result['communication_id'] ?? null,
        ];
    }

    public function compileReadiness(int $invoiceId, ?string $recipient = null): array
    {
        $invoice = $this->invoices->getById($invoiceId);
        if (!$invoice) {
            throw new \RuntimeException('Invoice not found.');
        }

        return $this->readinessService->compile($invoice, $recipient);
    }

    private function summarizeIssues(array $issues): string
    {
        if ($issues === []) {
            return 'Invoice send readiness checks failed.';
        }

        return implode(' ', array_map(
            static fn (array $issue): string => trim((string) ($issue['message'] ?? '')),
            $issues
        ));
    }

    private function buildEmailBody(array $invoice): array
    {
        $label = match ($invoice['document_type'] ?? 'invoice') {
            'quote' => 'Quote',
            'proforma' => 'Proforma Invoice',
            'credit_note' => 'Credit Note',
            default => 'Invoice',
        };
        $invoiceNumber = (string) ($invoice['invoice_number'] ?? 'document');
        $companyName = trim((string) ($_ENV['COMPANY_NAME'] ?? brandProductName()));
        $total = number_format((float) ($invoice['grand_total'] ?? 0), 2);
        $currency = strtoupper(trim((string) ($invoice['currency'] ?? 'USD')));
        $dueLabel = trim((string) (($invoice['due_date'] ?? '') ?: ($invoice['valid_until'] ?? '')));

        $plain = trim(
            $label . ' ' . $invoiceNumber . "\n"
            . ($companyName !== '' ? ('From: ' . $companyName . "\n") : '')
            . 'Customer: ' . trim((string) ($invoice['billing_name'] ?? 'Customer')) . "\n"
            . 'Total: ' . $currency . ' ' . $total . "\n"
            . ($dueLabel !== '' ? ('Due/Valid Until: ' . $dueLabel . "\n") : '')
            . "\nThe PDF version of this document is attached."
        );

        $html = '<!doctype html><html><body style="font-family:Arial,sans-serif;color:#111827;line-height:1.6;">'
            . '<h2 style="margin-bottom:12px;">' . htmlspecialchars($label . ' ' . $invoiceNumber, ENT_QUOTES, 'UTF-8') . '</h2>'
            . '<p style="margin:0 0 8px 0;"><strong>From:</strong> ' . htmlspecialchars($companyName !== '' ? $companyName : brandProductName(), ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p style="margin:0 0 8px 0;"><strong>Customer:</strong> ' . htmlspecialchars((string) ($invoice['billing_name'] ?? 'Customer'), ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p style="margin:0 0 8px 0;"><strong>Total:</strong> ' . htmlspecialchars($currency . ' ' . $total, ENT_QUOTES, 'UTF-8') . '</p>'
            . ($dueLabel !== '' ? '<p style="margin:0 0 8px 0;"><strong>Due/Valid Until:</strong> ' . htmlspecialchars($dueLabel, ENT_QUOTES, 'UTF-8') . '</p>' : '')
            . '<p style="margin-top:16px;">The PDF version of this document is attached for your records.</p>'
            . '</body></html>';

        return ['plain' => $plain, 'html' => $html];
    }
}
