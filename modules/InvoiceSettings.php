<?php

namespace CRM\Modules;

use CRM\Database;

class InvoiceSettings
{
    private const DEFAULTS = [
        'id' => 1,
        'enabled' => true,
        'invoice_prefix' => 'INV-',
        'invoice_next_number' => 1,
        'proforma_prefix' => 'PF-',
        'proforma_next_number' => 1,
        'quote_prefix' => 'QT-',
        'quote_next_number' => 1,
        'default_currency' => 'USD',
        'default_tax_mode' => 'exclusive',
        'default_tax_rate' => 0.0,
        'default_payment_terms_days' => 14,
        'default_validity_days' => 14,
        'default_notes' => 'Thank you for your business.',
        'default_terms' => 'Payment due within the stated terms.',
        'company_legal_name' => '',
        'company_tax_id' => '',
        'company_address' => '',
        'company_email' => '',
        'company_phone' => '',
        'bank_name' => '',
        'bank_account_name' => '',
        'bank_account_number' => '',
        'bank_branch' => '',
        'bank_swift' => '',
        'bank_instructions' => '',
        'logo_asset_path' => '',
        'footer_text' => '',
        'visual_theme' => 'classic',
        'default_template_key' => 'classic',
        'preview_document_type' => 'invoice',
        'proposal_intro_text' => 'Prepared for your review. Please see the pricing and terms below.',
        'acceptance_instructions' => 'Reply to this message or contact us to confirm acceptance.',
        'ai_create_quotes' => true,
        'ai_revise_documents' => true,
        'ai_send_documents' => true,
        'ai_finalize_invoices' => true,
        'ai_mark_paid' => false,
        'ai_require_approval_send' => false,
        'ai_require_approval_finalize' => false,
        'ai_allowed_channels' => ['email' => true, 'whatsapp' => true],
        'ai_max_discount_percent' => 20.0,
        'ai_max_total_change_percent' => 25.0,
        'ai_allowed_document_types_by_stage' => [
            'proposal' => ['quote', 'proforma'],
            'negotiation' => ['quote', 'proforma', 'invoice'],
            'closed_won' => ['invoice'],
        ],
    ];

    public function get(): array
    {
        return $this->withCompanyProfileIdentity($this->loadStoredSettings());
    }

    private function loadStoredSettings(): array
    {
        $row = Database::queryOne("SELECT * FROM invoice_settings WHERE id = 1");
        if (!$row) {
            return self::DEFAULTS;
        }

        $settings = self::DEFAULTS;
        foreach ($row as $key => $value) {
            if (!array_key_exists($key, $settings)) {
                continue;
            }
            $settings[$key] = $value;
        }

        foreach ([
            'enabled', 'ai_create_quotes', 'ai_revise_documents', 'ai_send_documents',
            'ai_finalize_invoices', 'ai_mark_paid', 'ai_require_approval_send',
            'ai_require_approval_finalize',
        ] as $boolKey) {
            $settings[$boolKey] = !empty($settings[$boolKey]);
        }

        foreach ([
            'default_tax_rate', 'ai_max_discount_percent', 'ai_max_total_change_percent',
        ] as $floatKey) {
            $settings[$floatKey] = (float) $settings[$floatKey];
        }

        foreach ([
            'invoice_next_number', 'proforma_next_number', 'quote_next_number',
            'default_payment_terms_days', 'default_validity_days',
        ] as $intKey) {
            $settings[$intKey] = (int) $settings[$intKey];
        }

        $settings['default_template_key'] = $this->normalizeTemplateKey(
            (string) ($settings['default_template_key'] ?? ($settings['visual_theme'] ?? 'classic'))
        );
        $settings['visual_theme'] = $settings['default_template_key'];
        $settings['preview_document_type'] = in_array((string) ($settings['preview_document_type'] ?? 'invoice'), ['quote', 'proforma', 'invoice', 'credit_note'], true)
            ? (string) $settings['preview_document_type']
            : 'invoice';

        $settings['ai_allowed_channels'] = $this->decodeJson(
            $settings['ai_allowed_channels'] ?? null,
            self::DEFAULTS['ai_allowed_channels']
        );
        $settings['ai_allowed_document_types_by_stage'] = $this->decodeJson(
            $settings['ai_allowed_document_types_by_stage'] ?? null,
            self::DEFAULTS['ai_allowed_document_types_by_stage']
        );

        return $settings;
    }

    public function save(array $data, ?int $updatedBy = null): void
    {
        foreach ([
            'company_legal_name',
            'company_tax_id',
            'company_address',
            'company_email',
            'company_phone',
            'logo_asset_path',
        ] as $profileOwnedKey) {
            unset($data[$profileOwnedKey]);
        }

        $current = $this->loadStoredSettings();
        $merged = array_merge($current, $data);

        foreach ([
            'enabled', 'ai_create_quotes', 'ai_revise_documents', 'ai_send_documents',
            'ai_finalize_invoices', 'ai_mark_paid', 'ai_require_approval_send',
            'ai_require_approval_finalize',
        ] as $boolKey) {
            $merged[$boolKey] = !empty($merged[$boolKey]);
        }

        foreach ([
            'invoice_next_number', 'proforma_next_number', 'quote_next_number',
            'default_payment_terms_days', 'default_validity_days',
        ] as $intKey) {
            $merged[$intKey] = max(1, (int) ($merged[$intKey] ?? 1));
        }

        foreach ([
            'default_tax_rate', 'ai_max_discount_percent', 'ai_max_total_change_percent',
        ] as $floatKey) {
            $merged[$floatKey] = max(0.0, (float) ($merged[$floatKey] ?? 0));
        }

        $defaultTemplateKey = $this->normalizeTemplateKey(
            (string) ($merged['default_template_key'] ?? ($merged['visual_theme'] ?? 'classic'))
        );

        $params = [
            $merged['enabled'] ? 1 : 0,
            (string) $merged['invoice_prefix'],
            $merged['invoice_next_number'],
            (string) $merged['proforma_prefix'],
            $merged['proforma_next_number'],
            (string) $merged['quote_prefix'],
            $merged['quote_next_number'],
            (string) $merged['default_currency'],
            in_array($merged['default_tax_mode'], ['exclusive', 'inclusive', 'none'], true) ? $merged['default_tax_mode'] : 'exclusive',
            $merged['default_tax_rate'],
            $merged['default_payment_terms_days'],
            $merged['default_validity_days'],
            (string) ($merged['default_notes'] ?? ''),
            (string) ($merged['default_terms'] ?? ''),
            (string) ($merged['company_legal_name'] ?? ''),
            (string) ($merged['company_tax_id'] ?? ''),
            (string) ($merged['company_address'] ?? ''),
            (string) ($merged['company_email'] ?? ''),
            (string) ($merged['company_phone'] ?? ''),
            (string) ($merged['bank_name'] ?? ''),
            (string) ($merged['bank_account_name'] ?? ''),
            (string) ($merged['bank_account_number'] ?? ''),
            (string) ($merged['bank_branch'] ?? ''),
            (string) ($merged['bank_swift'] ?? ''),
            (string) ($merged['bank_instructions'] ?? ''),
            (string) ($merged['logo_asset_path'] ?? ''),
            (string) ($merged['footer_text'] ?? ''),
            $defaultTemplateKey,
            $defaultTemplateKey,
            in_array((string) ($merged['preview_document_type'] ?? 'invoice'), ['quote', 'proforma', 'invoice', 'credit_note'], true) ? (string) $merged['preview_document_type'] : 'invoice',
            (string) ($merged['proposal_intro_text'] ?? ''),
            (string) ($merged['acceptance_instructions'] ?? ''),
            $merged['ai_create_quotes'] ? 1 : 0,
            $merged['ai_revise_documents'] ? 1 : 0,
            $merged['ai_send_documents'] ? 1 : 0,
            $merged['ai_finalize_invoices'] ? 1 : 0,
            $merged['ai_mark_paid'] ? 1 : 0,
            $merged['ai_require_approval_send'] ? 1 : 0,
            $merged['ai_require_approval_finalize'] ? 1 : 0,
            json_encode($merged['ai_allowed_channels'] ?? self::DEFAULTS['ai_allowed_channels']),
            $merged['ai_max_discount_percent'],
            $merged['ai_max_total_change_percent'],
            json_encode($merged['ai_allowed_document_types_by_stage'] ?? self::DEFAULTS['ai_allowed_document_types_by_stage']),
            $updatedBy,
        ];

        $exists = Database::queryOne("SELECT id FROM invoice_settings WHERE id = 1");
        if ($exists) {
            $params[] = 1;
            Database::execute(
                "UPDATE invoice_settings SET
                    enabled = ?, invoice_prefix = ?, invoice_next_number = ?, proforma_prefix = ?, proforma_next_number = ?,
                    quote_prefix = ?, quote_next_number = ?, default_currency = ?, default_tax_mode = ?, default_tax_rate = ?,
                    default_payment_terms_days = ?, default_validity_days = ?, default_notes = ?, default_terms = ?,
                    company_legal_name = ?, company_tax_id = ?, company_address = ?, company_email = ?, company_phone = ?,
                    bank_name = ?, bank_account_name = ?, bank_account_number = ?, bank_branch = ?, bank_swift = ?,
                    bank_instructions = ?, logo_asset_path = ?, footer_text = ?, visual_theme = ?, default_template_key = ?, preview_document_type = ?, proposal_intro_text = ?,
                    acceptance_instructions = ?, ai_create_quotes = ?, ai_revise_documents = ?, ai_send_documents = ?,
                    ai_finalize_invoices = ?, ai_mark_paid = ?, ai_require_approval_send = ?, ai_require_approval_finalize = ?,
                    ai_allowed_channels = ?, ai_max_discount_percent = ?, ai_max_total_change_percent = ?,
                    ai_allowed_document_types_by_stage = ?, updated_by = ?
                 WHERE id = ?",
                $params
            );
            return;
        }

        array_unshift($params, 1);
        Database::execute(
            "INSERT INTO invoice_settings (
                id, enabled, invoice_prefix, invoice_next_number, proforma_prefix, proforma_next_number,
                quote_prefix, quote_next_number, default_currency, default_tax_mode, default_tax_rate,
                default_payment_terms_days, default_validity_days, default_notes, default_terms,
                company_legal_name, company_tax_id, company_address, company_email, company_phone,
                bank_name, bank_account_name, bank_account_number, bank_branch, bank_swift,
                bank_instructions, logo_asset_path, footer_text, visual_theme, default_template_key, preview_document_type, proposal_intro_text,
                acceptance_instructions, ai_create_quotes, ai_revise_documents, ai_send_documents,
                ai_finalize_invoices, ai_mark_paid, ai_require_approval_send, ai_require_approval_finalize,
                ai_allowed_channels, ai_max_discount_percent, ai_max_total_change_percent,
                ai_allowed_document_types_by_stage, updated_by
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
            )",
            $params
        );
    }

    private function withCompanyProfileIdentity(array $settings): array
    {
        try {
            // Use the profile module so invoice setup follows the same workspace
            // resolution rules as the Company Profile settings page.
            $profile = (new CompanyProfile())->get();
            if ($profile) {
                $profileLegalName = trim((string) ($profile['company_legal_name'] ?? ''));
                $profileName = trim((string) ($profile['company_name'] ?? ''));
                if (strcasecmp($profileLegalName, 'Your Company Name') === 0) {
                    $profileLegalName = '';
                }
                if (strcasecmp($profileName, 'Your Company Name') === 0) {
                    $profileName = '';
                }

                if ($profileLegalName !== '') {
                    $settings['company_legal_name'] = $profileLegalName;
                } elseif ($profileName !== '') {
                    $settings['company_legal_name'] = $profileName;
                }

                foreach ([
                    'company_tax_id' => 'company_tax_id',
                    'company_address' => 'company_address',
                    'company_email' => 'company_email',
                    'company_phone' => 'company_phone',
                    'logo_asset_path' => 'company_logo_url',
                ] as $settingKey => $profileKey) {
                    $profileValue = trim((string) ($profile[$profileKey] ?? ''));
                    if ($profileValue !== '') {
                        $settings[$settingKey] = (string) ($profile[$profileKey] ?? '');
                    }
                }
            }
        } catch (\Throwable $e) {
        }

        return $settings;
    }

    private function decodeJson(mixed $value, array $fallback): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return $fallback;
    }

    private function normalizeTemplateKey(string $value): string
    {
        return in_array($value, ['classic', 'minimal', 'bold'], true) ? $value : 'classic';
    }
}
