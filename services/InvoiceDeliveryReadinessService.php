<?php

namespace CRM\Services;

use CRM\Modules\InvoiceSettings;

class InvoiceDeliveryReadinessService
{
    private InvoiceSettings $settings;

    public function __construct(?InvoiceSettings $settings = null)
    {
        $this->settings = $settings ?? new InvoiceSettings();
    }

    public function compile(array $invoice, ?string $recipient = null): array
    {
        $settings = $this->settings->get();
        $resolvedRecipient = trim((string) ($recipient ?? ($invoice['billing_email'] ?? $invoice['contact_email'] ?? '')));

        $blocking = [];
        $warnings = [];

        if ($resolvedRecipient === '' || !filter_var($resolvedRecipient, FILTER_VALIDATE_EMAIL)) {
            $blocking[] = [
                'code' => 'missing_recipient',
                'message' => 'A valid recipient email is required before this document can be sent.',
            ];
        }

        $billingName = trim((string) ($invoice['billing_name'] ?? ''));
        $billingEmail = trim((string) ($invoice['billing_email'] ?? ''));
        $billingPhone = trim((string) ($invoice['billing_phone'] ?? ''));
        $billingAddress = trim((string) ($invoice['billing_address'] ?? ''));
        if ($billingName === '' || ($billingEmail === '' && $billingPhone === '' && $billingAddress === '')) {
            $blocking[] = [
                'code' => 'missing_billing_identity',
                'message' => 'Billing identity is incomplete. Add a billing name and at least one billing contact detail.',
            ];
        }

        $lineItems = is_array($invoice['line_items'] ?? null) ? $invoice['line_items'] : [];
        if ($lineItems === []) {
            $blocking[] = [
                'code' => 'missing_line_items',
                'message' => 'At least one line item is required before sending.',
            ];
        } else {
            foreach ($lineItems as $lineItem) {
                if ((float) ($lineItem['unit_price'] ?? 0) <= 0) {
                    $blocking[] = [
                        'code' => 'missing_pricing',
                        'message' => 'One or more line items still have unresolved pricing.',
                    ];
                    break;
                }
            }
        }

        $companyName = trim((string) ($settings['company_legal_name'] ?? ''));
        $companyEmail = trim((string) ($settings['company_email'] ?? ''));
        $companyAddress = trim((string) ($settings['company_address'] ?? ''));
        if ($companyName === '' || $companyEmail === '' || $companyAddress === '') {
            $blocking[] = [
                'code' => 'missing_company_identity',
                'message' => 'Company Profile must include the legal name, company email, and company address used on this document.',
            ];
        }

        if (trim((string) ($settings['logo_asset_path'] ?? '')) === '') {
            $warnings[] = [
                'code' => 'missing_logo',
                'message' => 'No company logo is configured. The PDF will send, but branding will be limited.',
            ];
        }
        if (trim((string) ($settings['bank_instructions'] ?? '')) === '') {
            $warnings[] = [
                'code' => 'missing_payment_instructions',
                'message' => 'Payment instructions are empty. Customers may not know how to pay from the PDF alone.',
            ];
        }

        return [
            'is_ready' => $blocking === [],
            'recipient' => $resolvedRecipient,
            'blocking_issues' => $blocking,
            'warnings' => $warnings,
            'summary' => [
                'recipient_ready' => $resolvedRecipient !== '' && filter_var($resolvedRecipient, FILTER_VALIDATE_EMAIL),
                'billing_identity_ready' => $billingName !== '' && ($billingEmail !== '' || $billingPhone !== '' || $billingAddress !== ''),
                'line_items_ready' => $lineItems !== [],
                'pricing_ready' => !$this->hasZeroPricedLineItem($lineItems),
                'company_identity_ready' => $companyName !== '' && $companyEmail !== '' && $companyAddress !== '',
                'branding_ready' => trim((string) ($settings['logo_asset_path'] ?? '')) !== '',
            ],
        ];
    }

    private function hasZeroPricedLineItem(array $lineItems): bool
    {
        foreach ($lineItems as $lineItem) {
            if ((float) ($lineItem['unit_price'] ?? 0) <= 0) {
                return true;
            }
        }

        return false;
    }
}
