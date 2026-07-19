<?php

namespace CRM\Services;

use CRM\Modules\Currencies;

class WorkspacePackageBillingInvoiceRenderer
{
    /**
     * @param array<string,mixed> $invoice
     */
    public function renderHtml(array $invoice, bool $includeActions = true): string
    {
        $doc = $this->buildDocument($invoice);
        $esc = [$this, 'e'];

        $rows = '';
        foreach ($doc['line_items'] as $item) {
            $rows .= '<tr>'
                . '<td>' . $esc($item['description']) . '</td>'
                . '<td class="num">' . $esc($this->quantity($item['quantity'])) . '</td>'
                . '<td class="num">' . $esc($this->money($item['unit_price'], $doc['currency'])) . '</td>'
                . '<td class="num">' . $esc($this->money($item['tax_amount'], $doc['currency'])) . '</td>'
                . '<td class="num strong">' . $esc($this->money($item['line_total'], $doc['currency'])) . '</td>'
                . '</tr>';
        }

        $pdfUrl = 'billing_invoice_pdf.php?id=' . (int) ($doc['id'] ?? 0);
        $title = 'Package Receipt ' . (string) $doc['document_number'];

        return '<!doctype html><html><head><meta charset="utf-8"><title>' . $esc($title) . '</title><style>'
            . '*{box-sizing:border-box;}'
            . 'body{font-family:DejaVu Sans,Helvetica,Arial,sans-serif;color:#14213d;background:#f6f3ee;margin:0;padding:24px;}'
            . '.sheet{background:#fff;border:1px solid #d9d1c3;border-radius:18px;padding:32px;max-width:980px;margin:0 auto;}'
            . '.hero{display:flex;justify-content:space-between;gap:24px;align-items:flex-start;margin-bottom:28px;}'
            . '.hero-copy{min-width:0;flex:1;}'
            . '.eyebrow{display:inline-block;font-size:12px;letter-spacing:.16em;text-transform:uppercase;color:#8c6f46;font-weight:700;margin-bottom:10px;}'
            . '.headline{font-size:38px;line-height:1.05;font-weight:800;margin:0 0 10px;color:#14213d;letter-spacing:0;}'
            . '.intro{font-size:15px;line-height:1.5;color:#6b7280;margin:0;}'
            . '.meta-card,.info-card,.totals-card{border:1px solid #eadfcd;border-radius:16px;padding:18px;background:#fcfaf7;}'
            . '.meta-card{width:320px;max-width:100%;flex:0 0 320px;}'
            . '.info-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;margin-bottom:24px;}'
            . '.info-grid--three{grid-template-columns:repeat(3,minmax(0,1fr));}'
            . '.label{font-size:11px;text-transform:uppercase;letter-spacing:.12em;color:#8c6f46;margin-bottom:4px;font-weight:700;}'
            . '.value{font-size:15px;line-height:1.5;color:#14213d;overflow-wrap:anywhere;}'
            . '.muted{color:#6b7280;}.strong{font-weight:700;}'
            . '.meta-item{margin-top:10px;}.meta-item:first-child{margin-top:0;}'
            . '.table-wrap{overflow-x:auto;margin-bottom:24px;}'
            . 'table{width:100%;min-width:680px;border-collapse:collapse;}'
            . 'th,td{padding:14px 12px;border-bottom:1px solid #ece5db;font-size:14px;text-align:left;vertical-align:top;}'
            . 'th{font-size:12px;text-transform:uppercase;letter-spacing:.1em;color:#8c6f46;font-weight:700;}'
            . '.num{text-align:right;white-space:nowrap;}'
            . '.totals-wrap{display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:20px;align-items:start;}'
            . '.reference-card{border:1px solid #eadfcd;border-radius:16px;padding:18px;background:#fff;}'
            . '.totals-card div{display:flex;justify-content:space-between;gap:18px;padding:8px 0;border-bottom:1px solid #ece5db;}'
            . '.totals-card div:last-child{border-bottom:none;padding-top:14px;font-size:18px;font-weight:800;}'
            . '.footer{margin-top:28px;padding-top:18px;border-top:1px solid #ece5db;color:#6b7280;font-size:12px;line-height:1.5;}'
            . '.actions{max-width:980px;margin:16px auto 0;display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap;}'
            . '.actions a,.actions button{border:1px solid #d9d1c3;border-radius:8px;background:#fff;color:#14213d;text-decoration:none;padding:10px 14px;font-weight:700;cursor:pointer;font:inherit;}'
            . '@media print{body{background:#fff;padding:0}.sheet{border:none;border-radius:0}.actions{display:none}}'
            . '@media (max-width:800px){body{padding:14px}.sheet{padding:20px;border-radius:12px}.hero{display:block}.meta-card{width:100%;margin-top:18px}.headline{font-size:32px}.info-grid,.info-grid--three,.totals-wrap{display:block}.info-card,.reference-card,.totals-card{margin-bottom:14px}}'
            . '</style></head><body>'
            . '<main class="sheet">'
            . '<section class="hero"><div class="hero-copy"><div class="eyebrow">Package Receipt</div><h1 class="headline">Package Receipt</h1><p class="intro">Workspace subscription package billing document.</p></div>'
            . '<aside class="meta-card">'
            . $this->htmlMetaItem('Document No.', $doc['document_number'])
            . $this->htmlMetaItem('Status', $doc['status'])
            . $this->htmlMetaItem('Issue Date', $doc['issue_date'])
            . $this->htmlMetaItem('Service Period', $doc['service_period'])
            . '</aside></section>'
            . '<section class="info-grid">'
            . '<div class="info-card"><div class="label">From</div><div class="value strong">' . $esc($doc['seller_name']) . '</div><div class="value muted">Platform billing</div></div>'
            . '<div class="info-card"><div class="label">Billed To</div><div class="value strong">' . $esc($doc['buyer_name']) . '</div><div class="value">' . $this->htmlLines($doc['buyer_lines']) . '</div></div>'
            . '</section>'
            . '<section class="info-grid info-grid--three">'
            . '<div class="info-card"><div class="label">Package</div><div class="value strong">' . $esc($doc['package_name']) . '</div><div class="value muted">' . $esc($doc['package_code']) . '</div></div>'
            . '<div class="info-card"><div class="label">Provider</div><div class="value strong">' . $esc($doc['provider_label']) . '</div><div class="value muted">' . $esc($doc['provider_reference']) . '</div></div>'
            . '<div class="info-card"><div class="label">Issued</div><div class="value strong">' . $esc($doc['issued_at']) . '</div><div class="value muted">Snapshot preserved at issue time</div></div>'
            . '</section>'
            . '<div class="table-wrap"><table><thead><tr><th>Description</th><th class="num">Qty</th><th class="num">Unit</th><th class="num">Tax</th><th class="num">Line Total</th></tr></thead><tbody>' . $rows . '</tbody></table></div>'
            . '<section class="totals-wrap"><div class="reference-card"><div class="label">Payment Reference</div><div class="value strong">' . $esc($doc['reference']) . '</div><div class="value muted">' . $esc($doc['reference_detail']) . '</div></div>'
            . '<div class="totals-card">'
            . '<div><span>Subtotal</span><span>' . $esc($this->money($doc['amount'], $doc['currency'])) . '</span></div>'
            . '<div><span>Tax</span><span>' . $esc($this->money($doc['tax_total'], $doc['currency'])) . '</span></div>'
            . '<div><span>Total Paid</span><span>' . $esc($this->money($doc['grand_total'], $doc['currency'])) . '</span></div>'
            . '</div></section>'
            . '<div class="footer">This platform package receipt was generated from billing snapshots captured at issue time. Package names, prices, and workspace details shown here do not change if current settings are edited later.</div>'
            . '</main>'
            . ($includeActions ? '<div class="actions"><button type="button" onclick="window.print()">Print</button><a href="' . $esc($pdfUrl) . '">PDF</a></div>' : '')
            . '</body></html>';
    }

    /**
     * @param array<string,mixed> $invoice
     */
    public function renderPdfHtml(array $invoice): string
    {
        $doc = $this->buildDocument($invoice);
        $esc = [$this, 'e'];
        $nl = [$this, 'nl'];

        $rows = '';
        foreach ($doc['line_items'] as $item) {
            $rows .= '<tr>'
                . '<td width="46%" style="border-bottom:1px solid #e7decf;padding:7px 6px;">' . $esc($item['description']) . '</td>'
                . '<td width="10%" align="right" style="border-bottom:1px solid #e7decf;padding:7px 6px;">' . $esc($this->quantity($item['quantity'])) . '</td>'
                . '<td width="17%" align="right" style="border-bottom:1px solid #e7decf;padding:7px 6px;">' . $esc($this->money($item['unit_price'], $doc['currency'])) . '</td>'
                . '<td width="12%" align="right" style="border-bottom:1px solid #e7decf;padding:7px 6px;">' . $esc($this->money($item['tax_amount'], $doc['currency'])) . '</td>'
                . '<td width="15%" align="right" style="border-bottom:1px solid #e7decf;padding:7px 6px;"><strong>' . $esc($this->money($item['line_total'], $doc['currency'])) . '</strong></td>'
                . '</tr>';
        }

        return '<!doctype html><html><head><meta charset="utf-8"><style>'
            . 'body{font-family:DejaVu Sans,Helvetica,Arial,sans-serif;color:#14213d;font-size:10.5pt;margin:0;padding:0;background:#ffffff;}'
            . 'table{border-collapse:collapse;width:100%;}'
            . 'th{font-size:8.5pt;text-transform:uppercase;color:#8c6f46;font-weight:bold;background:#fbf7ef;border-bottom:1px solid #e7decf;padding:7px 6px;}'
            . 'td{font-size:9.5pt;line-height:1.35;vertical-align:top;}'
            . '.muted{color:#64748b;}.label{font-size:8pt;color:#8c6f46;font-weight:bold;text-transform:uppercase;}.box{border:1px solid #e7decf;background:#fffdf8;padding:10px;}'
            . '</style></head><body>'
            . '<table cellpadding="0" cellspacing="0" style="margin-bottom:16px;"><tr>'
            . '<td width="55%" style="padding:0 12px 0 0;"><span style="font-size:16px;font-weight:bold;color:#14213d;">' . $esc($doc['seller_name']) . '</span><br><br><span class="muted">Platform billing</span></td>'
            . '<td width="45%" align="right" style="padding:0 0 0 12px;">'
            . '<div style="font-size:22pt;font-weight:bold;color:#14213d;">Package Receipt</div>'
            . '<div style="font-size:12pt;color:#8c6f46;font-weight:bold;">' . $esc($doc['document_number']) . '</div><br>'
            . '<table cellpadding="3" cellspacing="0">'
            . '<tr><td width="50%" align="right" class="label">Status</td><td width="50%" align="right">' . $esc($doc['status']) . '</td></tr>'
            . '<tr><td align="right" class="label">Issue Date</td><td align="right">' . $esc($doc['issue_date']) . '</td></tr>'
            . '<tr><td align="right" class="label">Service Period</td><td align="right">' . $esc($doc['service_period']) . '</td></tr>'
            . '</table></td></tr></table>'
            . '<div style="font-size:16pt;font-weight:bold;color:#14213d;margin-bottom:6px;">Package Receipt</div>'
            . '<div style="color:#64748b;margin-bottom:14px;">Workspace subscription package billing document.</div>'
            . '<table cellpadding="9" cellspacing="0" style="margin-bottom:16px;"><tr>'
            . '<td width="49%" class="box"><div class="label">From</div><br><strong>' . $esc($doc['seller_name']) . '</strong><br><span class="muted">Platform billing</span></td>'
            . '<td width="2%"></td>'
            . '<td width="49%" class="box"><div class="label">Billed To</div><br><strong>' . $esc($doc['buyer_name']) . '</strong><br>' . $nl(implode("\n", $doc['buyer_lines'])) . '</td>'
            . '</tr></table>'
            . '<table cellpadding="9" cellspacing="0" style="margin-bottom:16px;"><tr>'
            . '<td width="32%" class="box"><div class="label">Package</div><br><strong>' . $esc($doc['package_name']) . '</strong><br><span class="muted">' . $esc($doc['package_code']) . '</span></td>'
            . '<td width="2%"></td>'
            . '<td width="32%" class="box"><div class="label">Provider</div><br><strong>' . $esc($doc['provider_label']) . '</strong><br><span class="muted">' . $esc($doc['provider_reference']) . '</span></td>'
            . '<td width="2%"></td>'
            . '<td width="32%" class="box"><div class="label">Issued</div><br><strong>' . $esc($doc['issued_at']) . '</strong><br><span class="muted">Snapshot preserved</span></td>'
            . '</tr></table>'
            . '<table cellpadding="0" cellspacing="0"><tr><td style="height:8px;font-size:1px;line-height:1px;">&nbsp;</td></tr></table>'
            . '<table cellpadding="0" cellspacing="0" style="margin-bottom:16px;">'
            . '<thead><tr><th width="46%" align="left">Description</th><th width="10%" align="right">Qty</th><th width="17%" align="right">Unit</th><th width="12%" align="right">Tax</th><th width="15%" align="right">Line Total</th></tr></thead><tbody>'
            . $rows . '</tbody></table>'
            . '<table cellpadding="0" cellspacing="0" style="margin-bottom:16px;"><tr>'
            . '<td width="58%" class="box"><div class="label">Payment Reference</div><br><strong>' . $esc($doc['reference']) . '</strong><br><span class="muted">' . $esc($doc['reference_detail']) . '</span></td>'
            . '<td width="2%"></td><td width="40%">'
            . '<table cellpadding="7" cellspacing="0" style="border:1px solid #e7decf;">'
            . '<tr><td width="50%" style="border-bottom:1px solid #e7decf;">Subtotal</td><td width="50%" align="right" style="border-bottom:1px solid #e7decf;">' . $esc($this->money($doc['amount'], $doc['currency'])) . '</td></tr>'
            . '<tr><td style="border-bottom:1px solid #e7decf;">Tax</td><td align="right" style="border-bottom:1px solid #e7decf;">' . $esc($this->money($doc['tax_total'], $doc['currency'])) . '</td></tr>'
            . '<tr><td style="font-weight:bold;">Total Paid</td><td align="right" style="font-weight:bold;">' . $esc($this->money($doc['grand_total'], $doc['currency'])) . '</td></tr>'
            . '</table></td></tr></table>'
            . '<div style="border-top:1px solid #e7decf;padding-top:8px;color:#64748b;font-size:8.5pt;">This platform package receipt was generated from billing snapshots captured at issue time.</div>'
            . '</body></html>';
    }

    /**
     * @param array<string,mixed> $invoice
     * @return array<string,mixed>
     */
    private function buildDocument(array $invoice): array
    {
        $invoice = $this->hydrateJson($invoice);
        $currency = strtoupper(trim((string) ($invoice['currency'] ?? 'KES'))) ?: 'KES';
        $amount = round((float) ($invoice['amount'] ?? 0), 2);
        $taxTotal = round((float) ($invoice['tax_total'] ?? 0), 2);
        $grandTotal = round((float) ($invoice['grand_total'] ?? ($amount + $taxTotal)), 2);
        $packageName = trim((string) ($invoice['package_name'] ?? 'Workspace package')) ?: 'Workspace package';
        $packageCode = trim((string) ($invoice['package_code'] ?? 'package')) ?: 'package';
        $provider = trim((string) ($invoice['provider'] ?? ''));
        $providerReference = trim((string) ($invoice['provider_reference'] ?? ''));
        $reference = $this->firstPresent([
            $providerReference,
            (string) ($invoice['provider_subscription_code'] ?? ''),
            (string) ($invoice['provider_customer_code'] ?? ''),
            (string) ($invoice['document_key'] ?? ''),
        ]);

        $buyerName = trim((string) ($invoice['buyer_workspace_name'] ?? '')) ?: 'Workspace';
        $buyerLines = array_values(array_filter([
            trim((string) ($invoice['buyer_email'] ?? '')),
            trim((string) ($invoice['buyer_workspace_slug'] ?? '')),
        ], static fn(string $value): bool => $value !== ''));

        return [
            'id' => (int) ($invoice['id'] ?? 0),
            'document_number' => trim((string) ($invoice['document_number'] ?? '')),
            'status' => $this->statusLabel($invoice),
            'issue_date' => $this->dateOnly($invoice['issued_at'] ?? ''),
            'issued_at' => $this->dateTime($invoice['issued_at'] ?? ''),
            'service_period' => $this->servicePeriod($invoice['period_start'] ?? '', $invoice['period_end'] ?? ''),
            'seller_name' => trim((string) ($invoice['seller_legal_name'] ?? '')) ?: $this->defaultSellerName(),
            'buyer_name' => $buyerName,
            'buyer_lines' => $buyerLines !== [] ? $buyerLines : ['Workspace billing contact'],
            'package_name' => $packageName,
            'package_code' => $packageCode,
            'provider_label' => $this->providerLabel($provider),
            'provider_reference' => $providerReference !== '' ? $providerReference : 'No provider reference',
            'reference' => $reference !== '' ? $reference : (trim((string) ($invoice['document_number'] ?? '')) ?: 'Package receipt'),
            'reference_detail' => $this->referenceDetail($invoice),
            'currency' => $currency,
            'amount' => $amount,
            'tax_total' => $taxTotal,
            'grand_total' => $grandTotal,
            'line_items' => $this->lineItems($invoice, $packageName, $amount, $taxTotal, $grandTotal),
        ];
    }

    /**
     * @param array<string,mixed> $invoice
     * @return array<string,mixed>
     */
    private function hydrateJson(array $invoice): array
    {
        if (isset($invoice['line_items_json']) && !isset($invoice['line_items'])) {
            $decoded = json_decode((string) $invoice['line_items_json'], true);
            $invoice['line_items'] = is_array($decoded) ? $decoded : [];
        }

        if (isset($invoice['metadata_json']) && !isset($invoice['metadata'])) {
            $decoded = json_decode((string) $invoice['metadata_json'], true);
            $invoice['metadata'] = is_array($decoded) ? $decoded : [];
        }

        return $invoice;
    }

    /**
     * @param array<string,mixed> $invoice
     * @return list<array{description:string,quantity:float,unit_price:float,tax_amount:float,line_total:float}>
     */
    private function lineItems(array $invoice, string $packageName, float $amount, float $taxTotal, float $grandTotal): array
    {
        $items = [];
        foreach ((array) ($invoice['line_items'] ?? []) as $rawItem) {
            if (!is_array($rawItem)) {
                continue;
            }
            $quantity = max(0.0, (float) ($rawItem['quantity'] ?? 1));
            $unitPrice = round((float) ($rawItem['unit_price'] ?? $amount), 2);
            $taxAmount = round((float) ($rawItem['tax_amount'] ?? 0), 2);
            $lineTotal = round((float) ($rawItem['line_total'] ?? ($rawItem['amount'] ?? (($unitPrice * max(1.0, $quantity)) + $taxAmount))), 2);

            $items[] = [
                'description' => trim((string) ($rawItem['description'] ?? $packageName)) ?: $packageName,
                'quantity' => $quantity > 0 ? $quantity : 1.0,
                'unit_price' => $unitPrice,
                'tax_amount' => $taxAmount,
                'line_total' => $lineTotal,
            ];
        }

        if ($items === []) {
            $items[] = [
                'description' => $packageName,
                'quantity' => 1.0,
                'unit_price' => $amount,
                'tax_amount' => $taxTotal,
                'line_total' => $grandTotal,
            ];
        }

        return $items;
    }

    /**
     * @param array<string,mixed> $invoice
     */
    private function statusLabel(array $invoice): string
    {
        if ((int) ($invoice['billing_transaction_id'] ?? 0) > 0) {
            return 'Paid';
        }

        $metadata = is_array($invoice['metadata'] ?? null) ? $invoice['metadata'] : [];
        if ((string) ($metadata['source'] ?? '') === 'operator_activation' || (string) ($invoice['provider'] ?? '') === 'operator') {
            return 'Activated';
        }

        return 'Paid';
    }

    /**
     * @param array<string,mixed> $invoice
     */
    private function referenceDetail(array $invoice): string
    {
        $parts = array_values(array_filter([
            trim((string) ($invoice['provider_subscription_code'] ?? '')) !== '' ? 'Subscription ' . trim((string) $invoice['provider_subscription_code']) : '',
            trim((string) ($invoice['provider_customer_code'] ?? '')) !== '' ? 'Customer ' . trim((string) $invoice['provider_customer_code']) : '',
            (int) ($invoice['checkout_session_id'] ?? 0) > 0 ? 'Checkout #' . (int) $invoice['checkout_session_id'] : '',
            (int) ($invoice['billing_transaction_id'] ?? 0) > 0 ? 'Transaction #' . (int) $invoice['billing_transaction_id'] : '',
            (int) ($invoice['negotiated_offer_id'] ?? 0) > 0 ? 'Offer #' . (int) $invoice['negotiated_offer_id'] : '',
        ], static fn(string $value): bool => $value !== ''));

        return $parts !== [] ? implode(' | ', $parts) : 'Platform package activation';
    }

    private function htmlMetaItem(string $label, string $value): string
    {
        return '<div class="meta-item"><div class="label">' . $this->e($label) . '</div><div class="value strong">' . $this->e($value) . '</div></div>';
    }

    /**
     * @param list<string> $lines
     */
    private function htmlLines(array $lines): string
    {
        return implode('<br>', array_map([$this, 'e'], $lines));
    }

    /**
     * @param list<string> $values
     */
    private function firstPresent(array $values): string
    {
        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function servicePeriod(mixed $start, mixed $end): string
    {
        $startText = $this->dateOnly($start);
        $endText = $this->dateOnly($end);
        if ($startText === '-' && $endText === '-') {
            return '-';
        }
        if ($endText === '-') {
            return 'From ' . $startText;
        }
        if ($startText === '-') {
            return 'Until ' . $endText;
        }

        return $startText . ' to ' . $endText;
    }

    private function dateOnly(mixed $value): string
    {
        $timestamp = $this->timestamp($value);
        return $timestamp !== null ? date('Y-m-d', $timestamp) : '-';
    }

    private function dateTime(mixed $value): string
    {
        $timestamp = $this->timestamp($value);
        return $timestamp !== null ? date('d M Y, H:i', $timestamp) : '-';
    }

    private function timestamp(mixed $value): ?int
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $timestamp = strtotime($value);
        return $timestamp !== false ? $timestamp : null;
    }

    private function providerLabel(string $provider): string
    {
        $provider = trim($provider);
        if ($provider === '') {
            return 'Platform';
        }

        return match (strtolower($provider)) {
            'mpesa', 'm-pesa' => 'M-Pesa',
            'paystack' => 'Paystack',
            'operator' => 'Operator activation',
            default => ucwords(str_replace(['_', '-'], ' ', $provider)),
        };
    }

    private function defaultSellerName(): string
    {
        if (function_exists('brandProductName')) {
            return brandProductName();
        }

        return 'CRM';
    }

    private function quantity(float $quantity): string
    {
        return number_format($quantity, 2);
    }

    private function money(float $amount, string $currency): string
    {
        try {
            return (new Currencies())->formatAmount($amount, $currency);
        } catch (\Throwable $e) {
            $currency = strtoupper(trim($currency)) ?: 'KES';
            return $currency . ' ' . number_format($amount, 2);
        }
    }

    private function nl(mixed $value): string
    {
        return nl2br($this->e($value));
    }

    private function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
