<?php

namespace CRM\Services;

use CRM\Modules\Currencies;

class InvoiceRenderer
{
    public function renderHtml(array $invoice, ?array $settingsOverride = null): string
    {
        $vm = (new InvoiceTemplateService())->buildViewModel($invoice, $settingsOverride);
        $theme = $vm['template_key'] ?? $vm['theme'];

        return match ($theme) {
            'minimal' => $this->renderMinimal($vm),
            'bold' => $this->renderBold($vm),
            default => $this->renderClassic($vm),
        };
    }

    public function renderPdfHtml(array $invoice, ?array $settingsOverride = null): string
    {
        $vm = (new InvoiceTemplateService())->buildViewModel($invoice, $settingsOverride);
        $doc = $vm['invoice'];
        $settings = $vm['settings'];
        $logoSrc = $vm['logo_src'] ?? null;
        $label = (string) ($vm['title_label'] ?? 'Invoice');
        $accent = match ((string) ($vm['template_key'] ?? 'classic')) {
            'bold' => '#b45309',
            'minimal' => '#334155',
            default => '#8c6f46',
        };
        $heading = '#14213d';
        $muted = '#64748b';
        $line = '#e7decf';
        $esc = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $nl = static fn($v) => nl2br(htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'));

        $rows = '';
        foreach (($doc['line_items'] ?? []) as $item) {
            $rows .= '<tr>'
                . '<td width="38%" style="border-bottom:1px solid ' . $line . ';padding:7px 6px;">' . $esc($item['description'] ?? $item['product_name'] ?? '-') . '</td>'
                . '<td width="9%" align="right" style="border-bottom:1px solid ' . $line . ';padding:7px 6px;">' . number_format((float) ($item['quantity'] ?? 0), 2) . '</td>'
                . '<td width="15%" align="right" style="border-bottom:1px solid ' . $line . ';padding:7px 6px;">' . $esc($this->formatMoney((float) ($item['unit_price'] ?? 0), (string) ($doc['currency'] ?? 'USD'))) . '</td>'
                . '<td width="12%" align="right" style="border-bottom:1px solid ' . $line . ';padding:7px 6px;">' . number_format((float) ($item['discount_amount'] ?? 0), 2) . '</td>'
                . '<td width="11%" align="right" style="border-bottom:1px solid ' . $line . ';padding:7px 6px;">' . number_format((float) ($item['tax_amount'] ?? 0), 2) . '</td>'
                . '<td width="15%" align="right" style="border-bottom:1px solid ' . $line . ';padding:7px 6px;"><strong>' . $esc($this->formatMoney((float) ($item['line_total'] ?? 0), (string) ($doc['currency'] ?? 'USD'))) . '</strong></td>'
                . '</tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="6" style="border-bottom:1px solid ' . $line . ';padding:8px;color:' . $muted . ';">No line items</td></tr>';
        }

        $intro = trim((string) ($doc['intro_text'] ?? ''));
        $notes = trim((string) ($doc['notes'] ?? ''));
        $terms = trim((string) ($doc['terms'] ?? ''));
        $paymentInstructions = trim((string) ($settings['bank_instructions'] ?? ''));
        $footerText = trim((string) ($settings['footer_text'] ?? ($settings['acceptance_instructions'] ?? '')));
        $dateValue = (string) (($doc['due_date'] ?: ($doc['valid_until'] ?? '')) ?? '');
        $logoHtml = $logoSrc
            ? '<img src="' . $esc($logoSrc) . '" alt="Company logo" width="150" height="55" style="width:150px;height:55px;" />'
            : '<span style="font-size:18px;font-weight:bold;color:' . $heading . ';">' . $esc($settings['company_legal_name'] ?? brandProductName()) . '</span>';

        return '<!doctype html><html><head><meta charset="utf-8"><style>'
            . 'body{font-family:DejaVu Sans,Helvetica,Arial,sans-serif;color:' . $heading . ';font-size:10.5pt;margin:0;padding:0;background:#ffffff;}'
            . 'table{border-collapse:collapse;width:100%;}'
            . 'th{font-size:8.5pt;text-transform:uppercase;color:' . $accent . ';font-weight:bold;background:#fbf7ef;border-bottom:1px solid ' . $line . ';padding:7px 6px;}'
            . 'td{font-size:9.5pt;line-height:1.35;vertical-align:top;}'
            . '.muted{color:' . $muted . ';}.label{font-size:8pt;color:' . $accent . ';font-weight:bold;text-transform:uppercase;}.box{border:1px solid ' . $line . ';background:#fffdf8;padding:10px;}'
            . '</style></head><body>'
            . '<table cellpadding="0" cellspacing="0" style="margin-bottom:16px;"><tr>'
            . '<td width="55%" style="padding:0 12px 0 0;">' . $logoHtml . '<br><br><span class="muted">' . $nl($settings['company_address'] ?? '') . '</span></td>'
            . '<td width="45%" align="right" style="padding:0 0 0 12px;">'
            . '<div style="font-size:22pt;font-weight:bold;color:' . $heading . ';">' . $esc($label) . '</div>'
            . '<div style="font-size:12pt;color:' . $accent . ';font-weight:bold;">' . $esc($doc['invoice_number'] ?? '') . '</div><br>'
            . '<table cellpadding="3" cellspacing="0">'
            . '<tr><td width="50%" align="right" class="label">Status</td><td width="50%" align="right">' . $esc(ucwords(str_replace('_', ' ', (string) ($doc['status'] ?? 'draft')))) . '</td></tr>'
            . '<tr><td align="right" class="label">Issue Date</td><td align="right">' . $esc($doc['issue_date'] ?? '') . '</td></tr>'
            . '<tr><td align="right" class="label">Due / Valid Until</td><td align="right">' . $esc($dateValue) . '</td></tr>'
            . '</table></td></tr></table>'
            . '<div style="font-size:16pt;font-weight:bold;color:' . $heading . ';margin-bottom:6px;">' . $esc($doc['title'] ?? $label) . '</div>'
            . '<div style="color:' . $muted . ';margin-bottom:14px;">' . ($intro !== '' ? $nl($intro) : $nl($settings['proposal_intro_text'] ?? '')) . '</div>'
            . '<table cellpadding="9" cellspacing="0" style="margin-bottom:16px;"><tr>'
            . '<td width="49%" class="box"><div class="label">From</div><br><strong>' . $esc($settings['company_legal_name'] ?? '') . '</strong><br>'
            . $nl($settings['company_address'] ?? '') . '<br>' . $esc($settings['company_email'] ?? '') . '<br>' . $esc($settings['company_phone'] ?? '')
            . (($settings['company_tax_id'] ?? '') !== '' ? '<br>Tax ID: ' . $esc($settings['company_tax_id']) : '')
            . '</td><td width="2%"></td>'
            . '<td width="49%" class="box"><div class="label">Bill To</div><br><strong>' . $esc($doc['billing_name'] ?? '') . '</strong><br>'
            . $esc($doc['billing_email'] ?? '') . '<br>' . $esc($doc['billing_phone'] ?? '') . '<br>' . $nl($doc['billing_address'] ?? '')
            . '</td></tr></table>'
            . '<table cellpadding="0" cellspacing="0" style="margin-bottom:16px;">'
            . '<thead><tr>'
            . '<th width="38%" align="left">Description</th><th width="9%" align="right">Qty</th><th width="15%" align="right">Unit</th><th width="12%" align="right">Discount</th><th width="11%" align="right">Tax</th><th width="15%" align="right">Line Total</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table>'
            . '<table cellpadding="0" cellspacing="0" style="margin-bottom:16px;"><tr><td width="58%"></td><td width="42%">'
            . '<table cellpadding="7" cellspacing="0" style="border:1px solid ' . $line . ';">'
            . '<tr><td width="50%" style="border-bottom:1px solid ' . $line . ';">Subtotal</td><td width="50%" align="right" style="border-bottom:1px solid ' . $line . ';">' . $esc($this->formatMoney((float) ($doc['subtotal'] ?? 0), (string) ($doc['currency'] ?? 'USD'))) . '</td></tr>'
            . '<tr><td style="border-bottom:1px solid ' . $line . ';">Discount</td><td align="right" style="border-bottom:1px solid ' . $line . ';">' . $esc($this->formatMoney((float) ($doc['discount_total'] ?? 0), (string) ($doc['currency'] ?? 'USD'))) . '</td></tr>'
            . '<tr><td style="border-bottom:1px solid ' . $line . ';">Tax</td><td align="right" style="border-bottom:1px solid ' . $line . ';">' . $esc($this->formatMoney((float) ($doc['tax_total'] ?? 0), (string) ($doc['currency'] ?? 'USD'))) . '</td></tr>'
            . '<tr><td style="font-weight:bold;border-bottom:1px solid ' . $line . ';">Total</td><td align="right" style="font-weight:bold;border-bottom:1px solid ' . $line . ';">' . $esc($this->formatMoney((float) ($doc['grand_total'] ?? 0), (string) ($doc['currency'] ?? 'USD'))) . '</td></tr>'
            . '<tr><td style="font-weight:bold;">Balance Due</td><td align="right" style="font-weight:bold;">' . $esc($this->formatMoney((float) ($doc['balance_due'] ?? 0), (string) ($doc['currency'] ?? 'USD'))) . '</td></tr>'
            . '</table></td></tr></table>'
            . $this->renderPdfSection('Notes', $notes, $muted)
            . $this->renderPdfSection('Terms', $terms, $muted)
            . $this->renderPdfSection('Payment Instructions', $paymentInstructions, $muted)
            . '<div style="border-top:1px solid ' . $line . ';padding-top:8px;color:' . $muted . ';font-size:8.5pt;">' . ($footerText !== '' ? $nl($footerText) : 'No footer configured.') . '</div>'
            . '</body></html>';
    }

    private function renderClassic(array $vm): string
    {
        return $this->renderDocument($vm, [
            'page_bg' => '#f6f3ee',
            'sheet_bg' => '#ffffff',
            'sheet_border' => '#d9d1c3',
            'accent' => '#8c6f46',
            'heading' => '#14213d',
            'muted' => '#6b7280',
            'card_bg' => '#fcfaf7',
            'card_border' => '#eadfcd',
            'table_border' => '#ece5db',
            'hero_badge_bg' => 'transparent',
            'hero_badge_color' => '#8c6f46',
            'headline_size' => '38px',
            'meta_radius' => '16px',
            'sheet_radius' => '18px',
            'footer_border' => '#ece5db',
        ]);
    }

    private function renderMinimal(array $vm): string
    {
        return $this->renderDocument($vm, [
            'page_bg' => '#ffffff',
            'sheet_bg' => '#ffffff',
            'sheet_border' => '#e5e7eb',
            'accent' => '#334155',
            'heading' => '#111827',
            'muted' => '#6b7280',
            'card_bg' => '#ffffff',
            'card_border' => '#e5e7eb',
            'table_border' => '#e5e7eb',
            'hero_badge_bg' => '#f8fafc',
            'hero_badge_color' => '#334155',
            'headline_size' => '34px',
            'meta_radius' => '10px',
            'sheet_radius' => '8px',
            'footer_border' => '#e5e7eb',
        ]);
    }

    private function renderBold(array $vm): string
    {
        return $this->renderDocument($vm, [
            'page_bg' => '#f3f4f6',
            'sheet_bg' => '#ffffff',
            'sheet_border' => '#cbd5e1',
            'accent' => '#b45309',
            'heading' => '#0f172a',
            'muted' => '#475569',
            'card_bg' => '#fff7ed',
            'card_border' => '#fdba74',
            'table_border' => '#fed7aa',
            'hero_badge_bg' => '#b45309',
            'hero_badge_color' => '#ffffff',
            'headline_size' => '42px',
            'meta_radius' => '20px',
            'sheet_radius' => '22px',
            'footer_border' => '#fed7aa',
        ]);
    }

    private function renderDocument(array $vm, array $theme): string
    {
        $doc = $vm['invoice'];
        $settings = $vm['settings'];
        $logoSrc = $vm['logo_src'] ?? null;
        $label = $vm['title_label'];
        $esc = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        $rows = '';
        foreach (($doc['line_items'] ?? []) as $item) {
            $rows .= '<tr>'
                . '<td>' . $esc($item['description'] ?? $item['product_name'] ?? '-') . '</td>'
                . '<td class="num">' . number_format((float) ($item['quantity'] ?? 0), 2) . '</td>'
                . '<td class="num">' . $esc($this->formatMoney((float) ($item['unit_price'] ?? 0), (string) ($doc['currency'] ?? 'USD'))) . '</td>'
                . '<td class="num">' . number_format((float) ($item['discount_amount'] ?? 0), 2) . '</td>'
                . '<td class="num">' . number_format((float) ($item['tax_amount'] ?? 0), 2) . '</td>'
                . '<td class="num strong">' . $esc($this->formatMoney((float) ($item['line_total'] ?? 0), (string) ($doc['currency'] ?? 'USD'))) . '</td>'
                . '</tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="6" class="muted">No line items</td></tr>';
        }

        $intro = trim((string) ($doc['intro_text'] ?? ''));
        $notes = trim((string) ($doc['notes'] ?? ''));
        $terms = trim((string) ($doc['terms'] ?? ''));
        $paymentInstructions = trim((string) ($settings['bank_instructions'] ?? ''));
        $footerText = trim((string) ($settings['footer_text'] ?? ($settings['acceptance_instructions'] ?? '')));

        return '<!doctype html><html><head><meta charset="utf-8"><style>'
            . 'body{font-family:DejaVu Sans,Helvetica,Arial,sans-serif;color:' . $theme['heading'] . ';margin:0;padding:24px;background:' . $theme['page_bg'] . ';}'
            . '.sheet{background:' . $theme['sheet_bg'] . ';border:1px solid ' . $theme['sheet_border'] . ';border-radius:' . $theme['sheet_radius'] . ';padding:32px;max-width:980px;margin:0 auto;}'
            . '.hero{display:flex;justify-content:space-between;gap:24px;align-items:flex-start;margin-bottom:28px;}'
            . '.hero-brand{margin-bottom:14px;}'
            . '.hero-brand img{max-height:64px;max-width:220px;display:block;object-fit:contain;}'
            . '.eyebrow{display:inline-block;font-size:12px;letter-spacing:.16em;text-transform:uppercase;color:' . $theme['hero_badge_color'] . ';font-weight:700;margin-bottom:10px;padding:6px 10px;border-radius:999px;background:' . $theme['hero_badge_bg'] . ';}'
            . '.headline{font-size:' . $theme['headline_size'] . ';line-height:1.05;font-weight:800;margin:0 0 10px;color:' . $theme['heading'] . ';}'
            . '.meta-card,.info-card,.totals-card{border:1px solid ' . $theme['card_border'] . ';border-radius:' . $theme['meta_radius'] . ';padding:18px;background:' . $theme['card_bg'] . ';}'
            . '.meta-card{min-width:280px;}.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:24px;}'
            . '.label{font-size:11px;text-transform:uppercase;letter-spacing:.12em;color:' . $theme['accent'] . ';margin-bottom:4px;font-weight:700;}'
            . '.value{font-size:15px;line-height:1.5;color:' . $theme['heading'] . ';} table{width:100%;border-collapse:collapse;margin-bottom:24px;}'
            . 'th,td{padding:14px 12px;border-bottom:1px solid ' . $theme['table_border'] . ';font-size:14px;}'
            . 'th{font-size:12px;text-transform:uppercase;letter-spacing:.1em;color:' . $theme['accent'] . ';text-align:left;}'
            . '.num{text-align:right;}.strong{font-weight:700;}.totals-wrap{display:grid;grid-template-columns:1.6fr .8fr;gap:20px;align-items:start;}'
            . '.totals-card div{display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid ' . $theme['table_border'] . ';}.totals-card div:last-child{border-bottom:none;padding-top:14px;font-size:18px;font-weight:800;}'
            . '.stack > div{margin-bottom:18px;}.muted{color:' . $theme['muted'] . ';}.footer{margin-top:28px;padding-top:18px;border-top:1px solid ' . $theme['footer_border'] . ';color:' . $theme['muted'] . ';font-size:12px;}'
            . '</style></head><body><div class="sheet">'
            . '<div class="hero"><div>'
            . ($logoSrc ? '<div class="hero-brand"><img src="' . $esc($logoSrc) . '" alt="Company logo"></div>' : '')
            . '<div class="eyebrow">' . $esc($label) . '</div><h1 class="headline">' . $esc($doc['title'] ?? $label) . '</h1><div class="value muted">'
            . ($intro !== '' ? nl2br($esc($intro)) : nl2br($esc($settings['proposal_intro_text'] ?? '')))
            . '</div></div><div class="meta-card">'
            . '<div class="label">Document No.</div><div class="value strong">' . $esc($doc['invoice_number'] ?? '') . '</div>'
            . '<div class="label" style="margin-top:10px;">Status</div><div class="value">' . $esc(ucwords(str_replace('_', ' ', (string) ($doc['status'] ?? 'draft')))) . '</div>'
            . '<div class="label" style="margin-top:10px;">Issue Date</div><div class="value">' . $esc($doc['issue_date'] ?? '') . '</div>'
            . '<div class="label" style="margin-top:10px;">Due / Valid Until</div><div class="value">' . $esc((string) (($doc['due_date'] ?: ($doc['valid_until'] ?? '')) ?? '')) . '</div>'
            . '</div></div>'
            . '<div class="info-grid">'
            . '<div class="info-card"><div class="label">From</div><div class="value strong">' . $esc($settings['company_legal_name'] ?? '') . '</div><div class="value">'
            . nl2br($esc($settings['company_address'] ?? '')) . '<br>' . $esc($settings['company_email'] ?? '') . '<br>' . $esc($settings['company_phone'] ?? '')
            . (($settings['company_tax_id'] ?? '') !== '' ? '<br>Tax ID: ' . $esc($settings['company_tax_id']) : '')
            . '</div></div>'
            . '<div class="info-card"><div class="label">Bill To</div><div class="value strong">' . $esc($doc['billing_name'] ?? '') . '</div><div class="value">'
            . $esc($doc['billing_email'] ?? '') . '<br>' . $esc($doc['billing_phone'] ?? '') . '<br>' . nl2br($esc($doc['billing_address'] ?? ''))
            . '</div></div></div>'
            . '<table><thead><tr><th>Description</th><th class="num">Qty</th><th class="num">Unit</th><th class="num">Discount</th><th class="num">Tax</th><th class="num">Line Total</th></tr></thead><tbody>'
            . $rows . '</tbody></table>'
            . '<div class="totals-wrap"><div class="stack">'
            . '<div class="info-card"><div class="label">Notes</div><div class="value">' . ($notes !== '' ? nl2br($esc($notes)) : '<span class="muted">No notes</span>') . '</div></div>'
            . '<div class="info-card"><div class="label">Terms</div><div class="value">' . ($terms !== '' ? nl2br($esc($terms)) : '<span class="muted">No terms</span>') . '</div></div>'
            . '<div class="info-card"><div class="label">Payment Instructions</div><div class="value">' . ($paymentInstructions !== '' ? nl2br($esc($paymentInstructions)) : '<span class="muted">No payment instructions</span>') . '</div></div>'
            . '</div><div class="totals-card">'
            . '<div><span>Subtotal</span><span>' . $esc($this->formatMoney((float) ($doc['subtotal'] ?? 0), (string) ($doc['currency'] ?? 'USD'))) . '</span></div>'
            . '<div><span>Discount</span><span>' . $esc($this->formatMoney((float) ($doc['discount_total'] ?? 0), (string) ($doc['currency'] ?? 'USD'))) . '</span></div>'
            . '<div><span>Tax</span><span>' . $esc($this->formatMoney((float) ($doc['tax_total'] ?? 0), (string) ($doc['currency'] ?? 'USD'))) . '</span></div>'
            . '<div><span>Total</span><span>' . $esc($this->formatMoney((float) ($doc['grand_total'] ?? 0), (string) ($doc['currency'] ?? 'USD'))) . '</span></div>'
            . '<div><span>Balance Due</span><span>' . $esc($this->formatMoney((float) ($doc['balance_due'] ?? 0), (string) ($doc['currency'] ?? 'USD'))) . '</span></div>'
            . '</div></div>'
            . '<div class="footer">' . ($footerText !== '' ? nl2br($esc($footerText)) : '<span class="muted">No footer configured.</span>') . '</div>'
            . '</div></body></html>';
    }

    private function formatMoney(float $amount, string $currencyCode): string
    {
        try {
            return (new Currencies())->formatAmount($amount, $currencyCode);
        } catch (\Throwable $e) {
            return strtoupper(trim($currencyCode)) . ' ' . number_format($amount, 2);
        }
    }

    private function renderPdfSection(string $label, string $content, string $muted): string
    {
        $esc = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $nl = static fn($v) => nl2br(htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'));
        $body = trim($content) !== '' ? $nl($content) : '<span style="color:' . $muted . ';">No ' . strtolower($esc($label)) . '</span>';

        return '<table cellpadding="8" cellspacing="0" style="border:1px solid #e7decf;margin-bottom:10px;"><tr><td>'
            . '<div style="font-size:8pt;color:#8c6f46;font-weight:bold;text-transform:uppercase;">' . $esc($label) . '</div><br>'
            . $body
            . '</td></tr></table>';
    }
}
