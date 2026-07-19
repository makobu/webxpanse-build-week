<?php

namespace CRM\Services;

use CRM\Modules\InvoiceSettings;

class InvoiceAIAuthorizationService
{
    public function canPerform(string $action): bool
    {
        $settings = (new InvoiceSettings())->get();
        return match ($action) {
            'create_quote' => !empty($settings['ai_create_quotes']),
            'create_invoice', 'update_invoice', 'revise_quote' => !empty($settings['ai_revise_documents']),
            'send_invoice' => !empty($settings['ai_send_documents']),
            'finalize_invoice', 'convert_quote_to_invoice' => !empty($settings['ai_finalize_invoices']),
            'mark_invoice_paid' => !empty($settings['ai_mark_paid']),
            default => false,
        };
    }
}
