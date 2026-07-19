<?php

namespace CRM\Services;

use CRM\Modules\Currencies;
use CRM\Modules\InvoiceSettings;

class FinanceReportDocumentService
{
    private Currencies $currencies;

    public function __construct(?Currencies $currencies = null)
    {
        $this->currencies = $currencies ?? new Currencies();
    }

    /**
     * @return array<string,array{title:string,short:string,description:string}>
     */
    public function reportTypes(): array
    {
        return [
            'cashflow' => [
                'title' => 'Statement of Cash Flows',
                'short' => 'Cashflow',
                'description' => 'Cash received and paid by activity.',
            ],
            'profit' => [
                'title' => 'Income Statement',
                'short' => 'Profit',
                'description' => 'Revenue, costs, expenses, and profit share.',
            ],
            'bank' => [
                'title' => 'Bank Statement',
                'short' => 'Bank',
                'description' => 'Account movement with running balance.',
            ],
            'balance' => [
                'title' => 'Balance Sheet',
                'short' => 'Balance',
                'description' => 'Things owned balanced against amounts owed and owner value.',
            ],
        ];
    }

    public function normalizeType(string $type): string
    {
        $type = strtolower(trim($type));
        $aliases = [
            'cash_flow' => 'cashflow',
            'cash-flow' => 'cashflow',
            'pnl' => 'profit',
            'profit_and_loss' => 'profit',
            'profit-loss' => 'profit',
            'bank_statement' => 'bank',
            'bank-statement' => 'bank',
            'balance_sheet' => 'balance',
            'balance-sheet' => 'balance',
        ];
        $type = $aliases[$type] ?? $type;

        return array_key_exists($type, $this->reportTypes()) ? $type : 'profit';
    }

    /**
     * @param array<string,mixed> $workspace
     * @return array<string,mixed>
     */
    public function brandingContext(int $workspaceId, array $workspace, string $dateFrom, string $dateTo, string $currency): array
    {
        $settings = [];
        try {
            $settings = (new InvoiceSettings())->get();
        } catch (\Throwable $e) {
            $settings = [];
        }

        $workspaceName = trim((string) ($workspace['name'] ?? ''));
        $businessName = trim((string) ($settings['company_legal_name'] ?? ''));
        if ($businessName === '') {
            $businessName = $workspaceName !== '' ? $workspaceName : \brandProductName();
        }

        $logoSource = $this->resolveLogoSource((string) ($settings['logo_asset_path'] ?? ''));

        return [
            'workspace_id' => $workspaceId,
            'business_name' => $businessName,
            'workspace_name' => $workspaceName,
            'company_address' => trim((string) ($settings['company_address'] ?? '')),
            'company_email' => trim((string) ($settings['company_email'] ?? '')),
            'company_phone' => trim((string) ($settings['company_phone'] ?? '')),
            'company_tax_id' => trim((string) ($settings['company_tax_id'] ?? '')),
            'footer_text' => trim((string) ($settings['footer_text'] ?? '')),
            'logo_url' => $logoSource ?? '',
            'logo_path' => $logoSource ?? '',
            'logo_src' => $logoSource ?? '',
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'currency' => strtoupper(trim($currency)) ?: 'USD',
            'prepared_at' => date('Y-m-d'),
        ];
    }

    /**
     * @param array<string,mixed> $statements
     * @param array<string,mixed> $bankStatement
     * @param array<string,mixed> $opening
     * @param array<string,mixed> $context
     */
    public function renderHtml(string $type, array $statements, array $bankStatement, array $opening, array $context, bool $forPdf = false): string
    {
        if ($forPdf) {
            return $this->renderPdfDocumentHtml($type, $statements, $bankStatement, $opening, $context);
        }

        $type = $this->normalizeType($type);
        $meta = $this->reportTypes()[$type];
        $currency = (string) ($context['currency'] ?? $statements['currency'] ?? 'USD');
        $body = match ($type) {
            'cashflow' => $this->cashflowBody((array) ($statements['cash_flow'] ?? []), $currency),
            'bank' => $this->bankBody($bankStatement, $currency),
            'balance' => $this->balanceBody($opening, $currency),
            default => $this->profitBody((array) ($statements['profit_and_loss'] ?? []), $currency),
        };

        return '<article class="finance-report-document finance-report-document-' . $type . '">'
            . $this->headerHtml($type, $meta['title'], $context)
            . $body
            . $this->footerHtml($context)
            . '</article>';
    }

    /**
     * @param array<string,mixed> $statements
     * @param array<string,mixed> $bankStatement
     * @param array<string,mixed> $opening
     * @param array<string,mixed> $context
     */
    public function renderStandaloneHtml(string $type, array $statements, array $bankStatement, array $opening, array $context): string
    {
        return '<!doctype html><html><head><meta charset="utf-8"></head>'
            . '<body style="font-family:dejavusans,helvetica,arial,sans-serif;color:#111827;font-size:9.5pt;background:#ffffff;margin:0;padding:0;">'
            . $this->renderPdfDocumentHtml($type, $statements, $bankStatement, $opening, $context)
            . '</body></html>';
    }

    /**
     * @param array<string,mixed> $statements
     * @param array<string,mixed> $bankStatement
     * @param array<string,mixed> $opening
     * @param array<string,mixed> $context
     */
    public function outputPdf(string $type, array $statements, array $bankStatement, array $opening, array $context, string $destination = 'I'): void
    {
        if (!class_exists('\TCPDF')) {
            throw new \RuntimeException('TCPDF library is not available.');
        }

        $type = $this->normalizeType($type);
        $meta = $this->reportTypes()[$type];
        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator(\brandProductName());
        $pdf->SetAuthor((string) ($context['business_name'] ?? \brandProductName()));
        $pdf->SetTitle($meta['title']);
        $pdf->SetSubject('Finance report');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(12, 12, 12);
        $pdf->SetAutoPageBreak(true, 14);
        $pdf->AddPage();
        $pdf->SetFont('dejavusans', '', 9);
        $pdf->writeHTML($this->renderStandaloneHtml($type, $statements, $bankStatement, $opening, $context), true, false, true, false, '');
        $outputName = $this->filename($type, $context);
        if (strtoupper($destination) === 'F' && !preg_match('/^(?:[A-Za-z]:[\/\\\\]|[\/\\\\])/', $outputName)) {
            $outputName = getcwd() . DIRECTORY_SEPARATOR . $outputName;
        }
        $pdf->Output($outputName, $destination);
    }

    /**
     * @param array<string,mixed> $context
     */
    public function filename(string $type, array $context): string
    {
        $type = $this->normalizeType($type);
        $business = preg_replace('/[^a-zA-Z0-9._-]+/', '_', (string) ($context['business_name'] ?? 'finance'));
        $business = trim((string) $business, '._-') ?: 'finance';

        return strtolower($business . '_' . $type . '_' . (string) ($context['date_to'] ?? date('Y-m-d')) . '.pdf');
    }

    private function resolveLogoSource(string $assetPath): ?string
    {
        $assetPath = trim($assetPath);
        if ($assetPath === '') {
            return null;
        }
        if (preg_match('#^data:image/#i', $assetPath) || preg_match('#^(https?:)?//#i', $assetPath)) {
            return $assetPath;
        }

        $relative = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($assetPath, '/\\'));
        $absolute = dirname(__DIR__) . DIRECTORY_SEPARATOR . $relative;
        if (!is_file($absolute) || !is_readable($absolute)) {
            return null;
        }

        $mime = function_exists('mime_content_type') ? (string) mime_content_type($absolute) : '';
        if ($mime === '' || strpos($mime, 'image/') !== 0) {
            $ext = strtolower(pathinfo($absolute, PATHINFO_EXTENSION));
            $mime = match ($ext) {
                'jpg', 'jpeg' => 'image/jpeg',
                'gif' => 'image/gif',
                'webp' => 'image/webp',
                'svg' => 'image/svg+xml',
                default => 'image/png',
            };
        }

        $contents = @file_get_contents($absolute);
        if ($contents === false) {
            return null;
        }

        return 'data:' . $mime . ';base64,' . base64_encode($contents);
    }

    /**
     * @param array<string,mixed> $context
     */
    private function headerHtml(string $type, string $title, array $context): string
    {
        $periodLabel = $this->periodLabel($type, $context);
        $logoSource = (string) (($context['logo_src'] ?? '') ?: ($context['logo_url'] ?? '') ?: ($context['logo_path'] ?? ''));
        $logo = trim($logoSource) !== ''
            ? '<img src="' . $this->esc($logoSource) . '" alt="Logo" />'
            : '<strong>' . $this->esc((string) ($context['business_name'] ?? 'Business')) . '</strong>';
        $details = $this->companyDetailsHtml($context, '<br>');

        return '<header class="finance-document-header">'
            . '<div class="finance-document-brand"><div class="finance-document-logo">' . $logo . '</div><div><strong>' . $this->esc((string) ($context['business_name'] ?? 'Business')) . '</strong><span>Financial report</span>' . ($details !== '' ? '<span>' . $details . '</span>' : '') . '</div></div>'
            . '<table class="finance-document-meta" cellpadding="0" cellspacing="0"><tr><td>Currency</td><td>' . $this->esc((string) ($context['currency'] ?? 'USD')) . '</td></tr><tr><td>Prepared</td><td>' . $this->dateLabel((string) ($context['prepared_at'] ?? date('Y-m-d'))) . '</td></tr></table>'
            . '</header>'
            . '<section class="finance-document-title"><span>Management report</span><h2>' . $this->esc($title) . '</h2><p>' . $this->esc($periodLabel) . '</p></section>';
    }

    /**
     * @param array<string,mixed> $context
     */
    private function footerHtml(array $context): string
    {
        $footer = trim((string) ($context['footer_text'] ?? ''));
        if ($footer === '') {
            $footer = 'Prepared from recorded Finance entries. Review source documents before statutory filing.';
        }

        return '<footer class="finance-document-footer">' . $this->esc($footer) . '</footer>';
    }

    /**
     * @param array<string,mixed> $pnl
     */
    private function profitBody(array $pnl, string $currency): string
    {
        return $this->statementTable($this->profitRows((array) ($pnl['summary'] ?? [])), $currency);
    }

    /**
     * @param array<string,mixed> $cashFlow
     */
    private function cashflowBody(array $cashFlow, string $currency): string
    {
        $sections = (array) ($cashFlow['sections'] ?? []);
        $summary = (array) ($cashFlow['summary'] ?? []);
        $rows = '';
        foreach (['operating' => 'Operating activities', 'investing' => 'Investing activities', 'financing' => 'Financing activities'] as $key => $label) {
            $section = (array) ($sections[$key] ?? []);
            $rows .= '<tr><td>' . $this->esc($label) . '</td><td class="amount">' . $this->money((float) ($section['cash_in'] ?? 0), $currency) . '</td><td class="amount deduct">' . $this->money((float) ($section['cash_out'] ?? 0), $currency) . '</td><td class="amount">' . $this->money((float) ($section['net'] ?? 0), $currency) . '</td></tr>';
        }

        return '<table class="finance-document-table" cellpadding="0" cellspacing="0"><thead><tr><th>Activity</th><th class="amount">In</th><th class="amount">Out</th><th class="amount">Net</th></tr></thead><tbody>'
            . $rows
            . '<tr class="grand"><td>Net cash movement</td><td></td><td></td><td class="amount">' . $this->money((float) ($summary['net_cash_movement'] ?? 0), $currency) . '</td></tr>'
            . '</tbody></table>';
    }

    /**
     * @param array<string,mixed> $bankStatement
     */
    private function bankBody(array $bankStatement, string $currency): string
    {
        $summary = (array) ($bankStatement['summary'] ?? []);
        $rows = '';
        foreach ((array) ($bankStatement['rows'] ?? []) as $row) {
            $rows .= '<tr><td>' . $this->esc((string) ($row['date'] ?? '')) . '</td><td>' . $this->esc((string) ($row['ref'] ?? '')) . '</td><td>' . $this->esc((string) ($row['cash_account_name'] ?? '')) . '</td><td class="amount in">' . ((float) ($row['money_in'] ?? 0) > 0 ? $this->money((float) ($row['money_in'] ?? 0), $currency) : '') . '</td><td class="amount deduct">' . ((float) ($row['money_out'] ?? 0) > 0 ? $this->money((float) ($row['money_out'] ?? 0), $currency) : '') . '</td><td class="amount">' . $this->money((float) ($row['balance'] ?? 0), $currency) . '</td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="6" class="empty">No entries</td></tr>';
        }

        return '<div class="finance-document-summary"><div><span>Open</span><strong>' . $this->money((float) ($summary['opening_balance'] ?? 0), $currency) . '</strong></div><div><span>In</span><strong>' . $this->money((float) ($summary['cash_in'] ?? 0), $currency) . '</strong></div><div><span>Out</span><strong>' . $this->money((float) ($summary['cash_out'] ?? 0), $currency) . '</strong></div><div><span>Close</span><strong>' . $this->money((float) ($summary['ending_balance'] ?? 0), $currency) . '</strong></div></div>'
            . '<table class="finance-document-table finance-document-bank" cellpadding="0" cellspacing="0"><thead><tr><th>Date</th><th>Ref</th><th>Account</th><th class="amount">In</th><th class="amount">Out</th><th class="amount">Balance</th></tr></thead><tbody>' . $rows . '</tbody></table>';
    }

    /**
     * @param array<string,mixed> $opening
     */
    private function balanceBody(array $opening, string $currency): string
    {
        $balance = $this->balanceValues($opening);

        return '<div class="finance-report-t-account">'
            . '<div class="finance-report-t-side"><h3>Assets</h3><div class="finance-report-t-row"><span>Bank and cash</span><strong>' . $this->money($balance['bank'], $currency) . '</strong></div><div class="finance-report-t-row"><span>Receivables</span><strong>' . $this->money($balance['receivables'], $currency) . '</strong></div><div class="finance-report-t-row"><span>Other assets</span><strong>' . $this->money($balance['assets'], $currency) . '</strong></div><div class="finance-report-t-total"><span>Total assets</span><strong>' . $this->money($balance['total_assets'], $currency) . '</strong></div></div>'
            . '<div class="finance-report-t-side"><h3>Liabilities and equity</h3><div class="finance-report-t-row"><span>Liabilities</span><strong>' . $this->money($balance['liabilities'], $currency) . '</strong></div><div class="finance-report-t-row"><span>Owner value</span><strong>' . $this->money($balance['business_value'], $currency) . '</strong></div><div class="finance-report-t-total"><span>Total liabilities and equity</span><strong>' . $this->money($balance['total_claims'], $currency) . '</strong></div></div>'
            . '</div><div class="finance-document-balance-check"><span>Balance check</span><strong>' . ($balance['difference'] === 0.0 ? 'Balanced' : 'Difference ' . $this->money($balance['difference'], $currency)) . '</strong></div>';
    }

    /**
     * @param array<int,array{0:string,1:string,2:?float}> $rows
     */
    private function statementTable(array $rows, string $currency): string
    {
        $html = '<table class="finance-document-table" cellpadding="0" cellspacing="0"><tbody>';
        foreach ($rows as [$kind, $label, $amount]) {
            if ($kind === 'section') {
                $html .= '<tr class="section"><td colspan="2">' . $this->esc($label) . '</td></tr>';
                continue;
            }
            $html .= '<tr class="' . $this->esc($kind) . '"><td>' . $this->esc($label) . '</td><td class="amount">' . $this->money((float) $amount, $currency) . '</td></tr>';
        }

        return $html . '</tbody></table>';
    }

    /**
     * @param array<string,mixed> $statements
     * @param array<string,mixed> $bankStatement
     * @param array<string,mixed> $opening
     * @param array<string,mixed> $context
     */
    private function renderPdfDocumentHtml(string $type, array $statements, array $bankStatement, array $opening, array $context): string
    {
        $type = $this->normalizeType($type);
        $meta = $this->reportTypes()[$type];
        $currency = (string) ($context['currency'] ?? $statements['currency'] ?? 'USD');
        $body = match ($type) {
            'cashflow' => $this->pdfCashflowBody((array) ($statements['cash_flow'] ?? []), $currency),
            'bank' => $this->pdfBankBody($bankStatement, $currency),
            'balance' => $this->pdfBalanceBody($opening, $currency),
            default => $this->pdfProfitBody((array) ($statements['profit_and_loss'] ?? []), $currency),
        };

        return '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="font-family:dejavusans,helvetica,arial,sans-serif;color:#111827;">'
            . '<tr><td>'
            . $this->pdfHeaderHtml($type, $meta['title'], $context)
            . $body
            . $this->pdfFooterHtml($context)
            . '</td></tr></table>';
    }

    /**
     * @param array<string,mixed> $context
     */
    private function pdfHeaderHtml(string $type, string $title, array $context): string
    {
        $logoSource = (string) (($context['logo_src'] ?? '') ?: ($context['logo_path'] ?? '') ?: ($context['logo_url'] ?? ''));
        $business = $this->esc((string) ($context['business_name'] ?? 'Business'));
        $details = $this->companyDetailsHtml($context, '<br />');
        $logo = trim($logoSource) !== ''
            ? '<img src="' . $this->esc($logoSource) . '" alt="Company logo" width="150" height="55" style="width:150px;height:55px;" />'
            : '<span style="font-size:18pt;font-weight:bold;color:#14213d;">' . $business . '</span>';
        $brandName = trim($logoSource) !== ''
            ? '<br /><span style="font-size:12pt;font-weight:bold;color:#14213d;">' . $business . '</span>'
            : '';
        $periodLabel = $this->esc($this->periodLabel($type, $context));

        return '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-bottom:12px;">'
            . '<tr>'
            . '<td width="58%" style="padding:0 14px 8px 0;vertical-align:top;">' . $logo . $brandName . '<br /><span style="font-size:8.5pt;color:#64748b;">Financial report</span>'
            . ($details !== '' ? '<br /><span style="font-size:8.5pt;color:#64748b;line-height:1.35;">' . $details . '</span>' : '')
            . '</td>'
            . '<td width="42%" style="padding:0 0 8px 14px;vertical-align:top;">'
            . '<table width="100%" cellpadding="5" cellspacing="0" border="0" style="border:1px solid #e7decf;background-color:#fffdf8;">'
            . '<tr><td width="45%" style="font-size:7.5pt;color:#8c6f46;font-weight:bold;">REPORT</td><td width="55%" align="right" style="font-size:8.5pt;color:#14213d;">Management</td></tr>'
            . '<tr><td width="45%" style="font-size:7.5pt;color:#8c6f46;font-weight:bold;">CURRENCY</td><td width="55%" align="right" style="font-size:8.5pt;color:#14213d;">' . $this->esc((string) ($context['currency'] ?? 'USD')) . '</td></tr>'
            . '<tr><td width="45%" style="font-size:7.5pt;color:#8c6f46;font-weight:bold;">PREPARED</td><td width="55%" align="right" style="font-size:8.5pt;color:#14213d;">' . $this->dateLabel((string) ($context['prepared_at'] ?? date('Y-m-d'))) . '</td></tr>'
            . '</table></td>'
            . '</tr></table>'
            . '<table width="100%" cellpadding="10" cellspacing="0" border="0" style="border-top:3px solid #14213d;border-bottom:1px solid #e7decf;margin-bottom:14px;">'
            . '<tr><td width="100%" style="background-color:#fbf7ef;">'
            . '<span style="font-size:7.5pt;color:#8c6f46;font-weight:bold;">MANAGEMENT REPORT</span><br />'
            . '<span style="font-size:23pt;font-weight:bold;color:#14213d;line-height:1.18;">' . $this->esc($title) . '</span><br />'
            . '<span style="font-size:9.5pt;color:#334155;">' . $periodLabel . '</span>'
            . '</td></tr>'
            . '</table>';
    }

    /**
     * @param array<string,mixed> $context
     */
    private function pdfFooterHtml(array $context): string
    {
        $footer = trim((string) ($context['footer_text'] ?? ''));
        if ($footer === '') {
            $footer = 'Prepared from recorded Finance entries. Review source documents before statutory filing.';
        }

        return '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top:18px;">'
            . '<tr><td style="border-top:1px solid #cbd5e1;line-height:7px;">&nbsp;</td></tr>'
            . '<tr><td style="font-size:8.5pt;color:#64748b;">' . $this->esc($footer) . '</td></tr>'
            . '</table>';
    }

    /**
     * @param array<string,mixed> $pnl
     */
    private function pdfProfitBody(array $pnl, string $currency): string
    {
        return $this->pdfStatementRows($this->profitRows((array) ($pnl['summary'] ?? [])), $currency);
    }

    /**
     * @param array<string,mixed> $cashFlow
     */
    private function pdfCashflowBody(array $cashFlow, string $currency): string
    {
        $sections = (array) ($cashFlow['sections'] ?? []);
        $summary = (array) ($cashFlow['summary'] ?? []);
        $rows = '';
        foreach (['operating' => 'Operating activities', 'investing' => 'Investing activities', 'financing' => 'Financing activities'] as $key => $label) {
            $section = (array) ($sections[$key] ?? []);
            $rows .= '<tr>'
                . '<td width="40%" style="border-bottom:1px solid #e7decf;color:#14213d;">' . $this->esc($label) . '</td>'
                . '<td width="20%" align="right" style="border-bottom:1px solid #e7decf;">' . $this->money((float) ($section['cash_in'] ?? 0), $currency) . '</td>'
                . '<td width="20%" align="right" style="border-bottom:1px solid #e7decf;">' . $this->money((float) ($section['cash_out'] ?? 0), $currency) . '</td>'
                . '<td width="20%" align="right" style="border-bottom:1px solid #e7decf;"><strong>' . $this->money((float) ($section['net'] ?? 0), $currency) . '</strong></td>'
                . '</tr>';
        }

        return '<table width="100%" cellpadding="7" cellspacing="0" border="0" style="border:1px solid #e7decf;margin-top:4px;">'
            . '<thead><tr>'
            . '<th width="40%" align="left" style="background-color:#14213d;color:#ffffff;font-size:8.5pt;font-weight:bold;">Activity</th>'
            . '<th width="20%" align="right" style="background-color:#14213d;color:#ffffff;font-size:8.5pt;font-weight:bold;">In</th>'
            . '<th width="20%" align="right" style="background-color:#14213d;color:#ffffff;font-size:8.5pt;font-weight:bold;">Out</th>'
            . '<th width="20%" align="right" style="background-color:#14213d;color:#ffffff;font-size:8.5pt;font-weight:bold;">Net</th>'
            . '</tr></thead><tbody>'
            . $rows
            . '<tr><td width="40%" style="border-top:2px solid #14213d;background-color:#fbf7ef;font-weight:bold;">Net cash movement</td><td width="20%" style="border-top:2px solid #14213d;background-color:#fbf7ef;"></td><td width="20%" style="border-top:2px solid #14213d;background-color:#fbf7ef;"></td><td width="20%" align="right" style="border-top:2px solid #14213d;background-color:#fbf7ef;font-weight:bold;">' . $this->money((float) ($summary['net_cash_movement'] ?? 0), $currency) . '</td></tr>'
            . '</tbody></table>';
    }

    /**
     * @param array<string,mixed> $bankStatement
     */
    private function pdfBankBody(array $bankStatement, string $currency): string
    {
        $summary = (array) ($bankStatement['summary'] ?? []);
        $rows = '';
        foreach ((array) ($bankStatement['rows'] ?? []) as $row) {
            $rows .= '<tr>'
                . '<td width="14%" style="border-bottom:1px solid #e7decf;">' . $this->esc((string) ($row['date'] ?? '')) . '</td>'
                . '<td width="19%" style="border-bottom:1px solid #e7decf;">' . $this->esc((string) ($row['ref'] ?? '')) . '</td>'
                . '<td width="19%" style="border-bottom:1px solid #e7decf;">' . $this->esc((string) ($row['cash_account_name'] ?? '')) . '</td>'
                . '<td width="16%" align="right" style="border-bottom:1px solid #e7decf;">' . ((float) ($row['money_in'] ?? 0) > 0 ? $this->money((float) ($row['money_in'] ?? 0), $currency) : '') . '</td>'
                . '<td width="16%" align="right" style="border-bottom:1px solid #e7decf;">' . ((float) ($row['money_out'] ?? 0) > 0 ? $this->money((float) ($row['money_out'] ?? 0), $currency) : '') . '</td>'
                . '<td width="16%" align="right" style="border-bottom:1px solid #e7decf;"><strong>' . $this->money((float) ($row['balance'] ?? 0), $currency) . '</strong></td>'
                . '</tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td width="100%" colspan="6" align="center" style="color:#64748b;">No entries</td></tr>';
        }

        return $this->pdfSummaryCards([
            ['Open', (float) ($summary['opening_balance'] ?? 0)],
            ['In', (float) ($summary['cash_in'] ?? 0)],
            ['Out', (float) ($summary['cash_out'] ?? 0)],
            ['Close', (float) ($summary['ending_balance'] ?? 0)],
        ], $currency)
            . '<table width="100%" cellpadding="6" cellspacing="0" border="0" style="border:1px solid #e7decf;margin-top:10px;">'
            . '<thead><tr>'
            . '<th width="14%" align="left" style="background-color:#14213d;color:#ffffff;font-size:8pt;font-weight:bold;">Date</th>'
            . '<th width="19%" align="left" style="background-color:#14213d;color:#ffffff;font-size:8pt;font-weight:bold;">Ref</th>'
            . '<th width="19%" align="left" style="background-color:#14213d;color:#ffffff;font-size:8pt;font-weight:bold;">Account</th>'
            . '<th width="16%" align="right" style="background-color:#14213d;color:#ffffff;font-size:8pt;font-weight:bold;">In</th>'
            . '<th width="16%" align="right" style="background-color:#14213d;color:#ffffff;font-size:8pt;font-weight:bold;">Out</th>'
            . '<th width="16%" align="right" style="background-color:#14213d;color:#ffffff;font-size:8pt;font-weight:bold;">Balance</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table>';
    }

    /**
     * @param array<string,mixed> $opening
     */
    private function pdfBalanceBody(array $opening, string $currency): string
    {
        $balance = $this->balanceValues($opening);

        return '<table width="100%" cellpadding="0" cellspacing="0" border="0" style="border:1px solid #e7decf;">'
            . '<tr>'
            . '<td width="50%" style="border-right:1px solid #e7decf;vertical-align:top;">'
            . $this->pdfTAccountSide('Assets', [
                ['Bank and cash', $balance['bank']],
                ['Receivables', $balance['receivables']],
                ['Other assets', $balance['assets']],
            ], 'Total assets', $balance['total_assets'], $currency)
            . '</td>'
            . '<td width="50%" style="vertical-align:top;">'
            . $this->pdfTAccountSide('Liabilities and equity', [
                ['Liabilities', $balance['liabilities']],
                ['Owner value', $balance['business_value']],
            ], 'Total liabilities and equity', $balance['total_claims'], $currency)
            . '</td>'
            . '</tr></table>'
            . '<table width="100%" cellpadding="8" cellspacing="0" border="0" style="border:1px solid #e7decf;background-color:#fbf7ef;margin-top:10px;">'
            . '<tr><td width="55%" style="font-weight:bold;color:#14213d;">Balance check</td><td width="45%" align="right" style="font-weight:bold;color:#14213d;">' . ($balance['difference'] === 0.0 ? 'Balanced' : 'Difference ' . $this->money($balance['difference'], $currency)) . '</td></tr>'
            . '</table>';
    }

    /**
     * @param array<int,array{0:string,1:string,2:?float}> $rows
     */
    private function pdfStatementRows(array $rows, string $currency): string
    {
        $html = '<table width="100%" cellpadding="7" cellspacing="0" border="0" style="border:1px solid #e7decf;">';
        foreach ($rows as [$kind, $label, $amount]) {
            if ($kind === 'section') {
                $html .= '<tr><td width="100%" colspan="2" style="background-color:#fbf7ef;color:#8c6f46;font-size:8pt;font-weight:bold;border-bottom:1px solid #e7decf;">' . $this->esc($label) . '</td></tr>';
                continue;
            }
            $border = in_array($kind, ['total', 'subtotal', 'grand'], true) ? 'border-top:1px solid #14213d;' : 'border-bottom:1px solid #e7decf;';
            if ($kind === 'grand') {
                $border = 'border-top:2px solid #14213d;border-bottom:2px solid #14213d;background-color:#fbf7ef;';
            }
            $weight = in_array($kind, ['total', 'subtotal', 'grand'], true) ? 'font-weight:bold;' : '';
            $html .= '<tr>'
                . '<td width="68%" style="' . $border . $weight . '">' . $this->esc($label) . '</td>'
                . '<td width="32%" align="right" style="' . $border . $weight . '">' . $this->money((float) $amount, $currency) . '</td>'
                . '</tr>';
        }

        return $html . '</table>';
    }

    /**
     * @param array<int,array{0:string,1:float}> $items
     */
    private function pdfSummaryCards(array $items, string $currency): string
    {
        $html = '<table width="100%" cellpadding="6" cellspacing="0" border="0" style="margin-bottom:12px;"><tr>';
        foreach ($items as [$label, $amount]) {
            $html .= '<td width="25%" style="border:1px solid #e7decf;background-color:#fffdf8;">'
                . '<span style="font-size:7.5pt;color:#8c6f46;font-weight:bold;">' . $this->esc($label) . '</span><br />'
                . '<span style="font-size:10.5pt;font-weight:bold;color:#14213d;">' . $this->money($amount, $currency) . '</span>'
                . '</td>';
        }

        return $html . '</tr></table>';
    }

    /**
     * @param array<int,array{0:string,1:float}> $rows
     */
    private function pdfTAccountSide(string $title, array $rows, string $totalLabel, float $totalAmount, string $currency): string
    {
        $html = '<table width="100%" cellpadding="8" cellspacing="0" border="0">'
            . '<tr><td width="100%" colspan="2" style="font-size:11pt;font-weight:bold;color:#14213d;background-color:#fbf7ef;border-bottom:1px solid #e7decf;">' . $this->esc(strtoupper($title)) . '</td></tr>';
        foreach ($rows as [$label, $amount]) {
            $html .= '<tr>'
                . '<td width="52%" style="border-bottom:1px solid #e7decf;">' . $this->esc($label) . '</td>'
                . '<td width="48%" align="right" style="border-bottom:1px solid #e7decf;"><strong>' . $this->money($amount, $currency) . '</strong></td>'
                . '</tr>';
        }

        return $html
            . '<tr>'
            . '<td width="52%" style="border-top:2px solid #14213d;font-weight:bold;">' . $this->esc($totalLabel) . '</td>'
            . '<td width="48%" align="right" style="border-top:2px solid #14213d;font-weight:bold;">' . $this->money($totalAmount, $currency) . '</td>'
            . '</tr></table>';
    }

    /**
     * @param array<string,mixed> $summary
     * @return array<int,array{0:string,1:string,2:?float}>
     */
    private function profitRows(array $summary): array
    {
        return [
            ['section', 'Income', null],
            ['line', 'Sales', (float) ($summary['sales_revenue'] ?? $summary['revenue'] ?? 0)],
            ['line', 'Other income', (float) ($summary['other_income'] ?? 0)],
            ['total', 'Total income', (float) ($summary['revenue'] ?? 0)],
            ['section', 'Cost and expenses', null],
            ['line', 'Cost of sales', -1 * (float) ($summary['cost_of_sales'] ?? 0)],
            ['subtotal', 'Gross profit', (float) ($summary['gross_profit'] ?? 0)],
            ['line', 'Operating expenses', -1 * (float) ($summary['operating_expenses'] ?? 0)],
            ['total', 'Net income', (float) ($summary['net_income'] ?? 0)],
            ['section', 'Owner payout after net', null],
            ['line', 'Profit share', -1 * (float) ($summary['profit_share_payouts'] ?? 0)],
            ['grand', 'Net after profit share', (float) ($summary['net_after_profit_share'] ?? $summary['net_income'] ?? 0)],
        ];
    }

    /**
     * @param array<string,mixed> $opening
     * @return array{bank:float,receivables:float,assets:float,liabilities:float,business_value:float,total_assets:float,total_claims:float,difference:float}
     */
    private function balanceValues(array $opening): array
    {
        $bank = (float) ($opening['bank_total'] ?? 0);
        $receivables = (float) ($opening['receivables_total'] ?? 0);
        $assets = (float) ($opening['assets_total'] ?? 0);
        $liabilities = (float) ($opening['liabilities_total'] ?? 0);
        $businessValue = (float) ($opening['business_value'] ?? ($bank + $receivables + $assets - $liabilities));
        $totalAssets = $bank + $receivables + $assets;
        $totalClaims = $liabilities + $businessValue;

        return [
            'bank' => $bank,
            'receivables' => $receivables,
            'assets' => $assets,
            'liabilities' => $liabilities,
            'business_value' => $businessValue,
            'total_assets' => $totalAssets,
            'total_claims' => $totalClaims,
            'difference' => round($totalAssets - $totalClaims, 2),
        ];
    }

    /**
     * @param array<string,mixed> $context
     */
    private function companyDetailsHtml(array $context, string $separator): string
    {
        $lines = [];
        $address = trim((string) ($context['company_address'] ?? ''));
        if ($address !== '') {
            foreach (preg_split('/\R/', $address) ?: [] as $line) {
                $line = trim((string) $line);
                if ($line !== '') {
                    $lines[] = $line;
                }
            }
        }

        foreach (['company_email', 'company_phone'] as $key) {
            $value = trim((string) ($context[$key] ?? ''));
            if ($value !== '') {
                $lines[] = $value;
            }
        }

        $taxId = trim((string) ($context['company_tax_id'] ?? ''));
        if ($taxId !== '') {
            $lines[] = 'Tax ID: ' . $taxId;
        }

        return implode($separator, array_map(fn (string $line): string => $this->esc($line), $lines));
    }

    /**
     * @param array<string,mixed> $context
     */
    private function periodLabel(string $type, array $context): string
    {
        return $type === 'balance'
            ? 'As at ' . $this->dateLabel((string) ($context['date_to'] ?? date('Y-m-d')))
            : 'For the period ' . $this->dateLabel((string) ($context['date_from'] ?? '')) . ' to ' . $this->dateLabel((string) ($context['date_to'] ?? ''));
    }

    private function money(float $amount, string $currency): string
    {
        $code = strtoupper(trim($currency)) ?: 'USD';
        $value = $code . ' ' . number_format(abs($amount), 2);

        return $amount < 0 ? '(' . $value . ')' : $value;
    }

    private function dateLabel(string $date): string
    {
        $timestamp = strtotime($date);
        return $timestamp ? date('M j, Y', $timestamp) : $date;
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
