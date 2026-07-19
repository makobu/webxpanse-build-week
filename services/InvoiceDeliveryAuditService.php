<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\Invoices;

class InvoiceDeliveryAuditService
{
    private Invoices $invoices;

    public function __construct(?Invoices $invoices = null)
    {
        $this->invoices = $invoices ?? new Invoices();
    }

    public function recordEmailDelivery(
        int $invoiceId,
        string $recipient,
        string $subject,
        string $deliveryStatus,
        array $details = [],
        ?int $actorId = null,
        string $actorType = 'user'
    ): void {
        $invoice = $this->invoices->getById($invoiceId);
        if (!$invoice) {
            throw new \RuntimeException('Invoice not found.');
        }

        Database::execute(
            "INSERT INTO invoice_delivery_log (
                workspace_id, invoice_id, channel, recipient, subject, delivery_status, provider_message_id,
                sent_by, sent_by_type, email_id, email_uuid, provider_key, provider_method, failure_reason, details_json
            ) VALUES (?, ?, 'email', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                (int) ($invoice['workspace_id'] ?? 0),
                $invoiceId,
                $recipient,
                $subject,
                $deliveryStatus,
                $details['provider_message_id'] ?? null,
                $actorId,
                $actorType,
                !empty($details['email_id']) ? (int) $details['email_id'] : null,
                (($details['email_uuid'] ?? '') !== '') ? (string) $details['email_uuid'] : null,
                (($details['provider_key'] ?? '') !== '') ? (string) $details['provider_key'] : null,
                (($details['provider_method'] ?? '') !== '') ? (string) $details['provider_method'] : null,
                (($details['failure_reason'] ?? '') !== '') ? (string) $details['failure_reason'] : null,
                !empty($details) ? json_encode($details) : null,
            ]
        );
    }
}
