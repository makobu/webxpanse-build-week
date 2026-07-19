<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\Invoices;

class CommercialAutomationMessagingService
{
    private InvoiceSendService $invoiceSendService;
    private WhatsAppService $whatsAppService;

    public function __construct(?InvoiceSendService $invoiceSendService = null, ?WhatsAppService $whatsAppService = null)
    {
        $this->invoiceSendService = $invoiceSendService ?? new InvoiceSendService();
        $this->whatsAppService = $whatsAppService ?? new WhatsAppService();
    }

    public function sendInvoice(int $invoiceId, string $channel, string $recipient, ?int $actorId = null, string $actorType = 'system'): array
    {
        $invoices = new Invoices();
        $invoice = $invoices->getById($invoiceId);
        if (!$invoice) {
            throw new \RuntimeException('Invoice not found.');
        }

        if ($channel === 'email') {
            return $this->invoiceSendService->send($invoiceId, $recipient, $actorId, $actorType);
        }

        if ($channel === 'whatsapp') {
            $workspaceId = (int) ($invoice['workspace_id'] ?? 0);
            $normalizedPhone = $this->whatsAppService->normalizePhoneNumber($recipient);
            if ($normalizedPhone === '') {
                throw new \RuntimeException('Valid WhatsApp recipient is required.');
            }
            if (!$this->whatsAppService->isWithin24HourWindow((int) ($invoice['contact_id'] ?? 0))) {
                throw new \RuntimeException('WhatsApp document delivery requires an open 24-hour customer window.');
            }

            $tempPdf = (new InvoicePdfService())->renderToTemporaryFile($invoice, 'invoice_wa_');
            $pdfFile = $tempPdf['path'];
            $filename = $tempPdf['filename'];
            $caption = $this->buildWhatsAppCaption($invoice);

            try {
                $mediaId = $this->whatsAppService->uploadMedia($pdfFile, 'application/pdf');
                if (!$mediaId) {
                    throw new \RuntimeException('WhatsApp provider could not upload the commercial document.');
                }
                $result = $this->whatsAppService->sendDocumentMessage($normalizedPhone, $mediaId, $filename, $caption);
                $providerMessageId = (string) ($result['messages'][0]['id'] ?? '');
                if ($providerMessageId === '') {
                    throw new \RuntimeException('WhatsApp provider did not return a message ID for the commercial document send.');
                }

                $this->whatsAppService->storeMessage(
                    (int) ($invoice['contact_id'] ?? 0),
                    $normalizedPhone,
                    'document',
                    $caption !== '' ? $caption : ('Commercial document ' . ($invoice['invoice_number'] ?? '')),
                    [
                        'user_id' => $actorId,
                        'whatsapp_message_id' => $providerMessageId,
                        'media_id' => $mediaId,
                        'mime_type' => 'application/pdf',
                        'media_filename' => $filename,
                    ]
                );

                Database::execute(
                    "INSERT INTO invoice_delivery_log (workspace_id, invoice_id, channel, recipient, subject, delivery_status, provider_message_id, sent_by, sent_by_type)
                     VALUES (?, ?, 'whatsapp', ?, ?, 'sent', ?, ?, ?)",
                    [$workspaceId, $invoiceId, $normalizedPhone, $filename, $providerMessageId, $actorId, $actorType]
                );
                $invoices->transitionStatus($invoiceId, 'sent', $actorId, $actorType, 'Document sent via WhatsApp');
                $invoices->logActivity($invoiceId, 'invoice_sent', $actorType, $actorId, 'Invoice sent via WhatsApp', [
                    'recipient' => $normalizedPhone,
                    'provider_message_id' => $providerMessageId,
                ]);

                return [
                    'success' => true,
                    'recipient' => $normalizedPhone,
                    'provider_message_id' => $providerMessageId,
                    'channel' => 'whatsapp',
                ];
            } catch (\Throwable $e) {
                Database::execute(
                    "INSERT INTO invoice_delivery_log (workspace_id, invoice_id, channel, recipient, subject, delivery_status, provider_message_id, sent_by, sent_by_type)
                     VALUES (?, ?, 'whatsapp', ?, ?, 'failed', NULL, ?, ?)",
                    [$workspaceId, $invoiceId, $normalizedPhone, $filename, $actorId, $actorType]
                );
                $invoices->logActivity($invoiceId, 'invoice_send_failed', $actorType, $actorId, 'WhatsApp delivery failed', [
                    'recipient' => $normalizedPhone,
                    'reason' => $e->getMessage(),
                ]);
                throw $e;
            } finally {
                @unlink($pdfFile);
            }
        }

        throw new \RuntimeException('Unsupported delivery channel.');
    }

    private function buildWhatsAppCaption(array $invoice): string
    {
        $label = match ($invoice['document_type'] ?? 'invoice') {
            'quote' => 'Quote',
            'proforma' => 'Proforma Invoice',
            default => 'Invoice',
        };
        $number = (string) ($invoice['invoice_number'] ?? 'document');
        $company = (string) ($_ENV['COMPANY_NAME'] ?? brandProductName());
        return trim($label . ' ' . $number . ' from ' . $company);
    }
}
