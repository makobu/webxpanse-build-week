<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\FinanceReportDocumentService;
use CRM\Tests\TestCase;

class FinanceReportDocumentServiceTest extends TestCase
{
    public function testPdfBalanceSheetUsesTableCellsForLabelsAndAmounts(): void
    {
        $html = $this->service()->renderStandaloneHtml(
            'balance',
            $this->statements(),
            $this->bankStatement(),
            $this->openingSummary(),
            $this->context()
        );

        $this->assertStringContainsString('Balance Sheet', $html);
        $this->assertStringContainsString('MANAGEMENT REPORT', $html);
        $this->assertMatchesRegularExpression('/<td width="52%"[^>]*>Bank and cash<\/td><td width="48%" align="right"/', $html);
        $this->assertMatchesRegularExpression('/<td width="52%"[^>]*>Owner value<\/td><td width="48%" align="right"/', $html);
        $this->assertStringNotContainsString('Bank and cashKES', $html);
        $this->assertStringNotContainsString('Owner valueKES', $html);
        $this->assertStringNotContainsString('display:grid', $html);
        $this->assertStringNotContainsString('display:flex', $html);
        $this->assertStringNotContainsString('float:right', $html);
        $this->assertStringNotContainsString('finance-report-t-row', $html);
        $this->assertStringNotContainsString('logo-web.png', $html);
    }

    public function testPdfReportsRenderFormalDocumentHeadersAndSections(): void
    {
        $service = $this->service();
        $expected = [
            'cashflow' => ['Statement of Cash Flows', 'Operating activities', 'Net cash movement'],
            'profit' => ['Income Statement', 'Net income', 'Net after profit share'],
            'bank' => ['Bank Statement', 'Open', 'Balance'],
            'balance' => ['Balance Sheet', 'ASSETS', 'LIABILITIES AND EQUITY'],
        ];

        foreach ($expected as $type => $labels) {
            $html = $service->renderStandaloneHtml(
                $type,
                $this->statements(),
                $this->bankStatement(),
                $this->openingSummary(),
                $this->context()
            );

            $this->assertStringContainsString('Demo Business', $html);
            $this->assertStringContainsString('Financial report', $html);
            $this->assertStringContainsString('123 Market Street', $html);
            $this->assertStringContainsString('Nairobi', $html);
            $this->assertStringContainsString('finance@example.test', $html);
            $this->assertStringContainsString('+254 700 000000', $html);
            $this->assertStringContainsString('Tax ID: P051234567A', $html);
            $this->assertStringContainsString('CURRENCY', $html);
            $this->assertStringContainsString('PREPARED', $html);
            $this->assertStringContainsString('Finance reports are management summaries.', $html);
            $this->assertStringNotContainsString('logo-web.png', $html);
            foreach ($labels as $label) {
                $this->assertStringContainsString($label, $html);
            }
        }
    }

    public function testPdfReportsUseOwnerLogoDataUriWhenConfigured(): void
    {
        $logoSource = $this->resolveLogoSource('uploads/test-assets/invoice-logo-test.png');
        $this->assertIsString($logoSource);
        $this->assertStringStartsWith('data:image/', $logoSource);

        $html = $this->service()->renderStandaloneHtml(
            'cashflow',
            $this->statements(),
            $this->bankStatement(),
            $this->openingSummary(),
            $this->context([
                'logo_src' => $logoSource,
                'logo_url' => $logoSource,
                'logo_path' => $logoSource,
            ])
        );

        $this->assertStringContainsString('Company logo', $html);
        $this->assertStringContainsString('data:image/', $html);
        $this->assertStringContainsString('width="150"', $html);
        $this->assertStringContainsString('height="55"', $html);
        $this->assertStringNotContainsString('logo-web.png', $html);
    }

    private function service(): FinanceReportDocumentService
    {
        return new FinanceReportDocumentService();
    }

    /**
     * @return array<string,mixed>
     */
    private function statements(): array
    {
        return [
            'currency' => 'KES',
            'profit_and_loss' => [
                'summary' => [
                    'sales_revenue' => 120000.0,
                    'other_income' => 5000.0,
                    'revenue' => 125000.0,
                    'cost_of_sales' => 20000.0,
                    'gross_profit' => 105000.0,
                    'operating_expenses' => 25000.0,
                    'net_income' => 80000.0,
                    'profit_share_payouts' => 10000.0,
                    'net_after_profit_share' => 70000.0,
                ],
            ],
            'cash_flow' => [
                'sections' => [
                    'operating' => ['cash_in' => 125000.0, 'cash_out' => 25000.0, 'net' => 100000.0],
                    'investing' => ['cash_in' => 0.0, 'cash_out' => 50000.0, 'net' => -50000.0],
                    'financing' => ['cash_in' => 30000.0, 'cash_out' => 10000.0, 'net' => 20000.0],
                ],
                'summary' => [
                    'net_cash_movement' => 70000.0,
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function bankStatement(): array
    {
        return [
            'summary' => [
                'opening_balance' => 100000.0,
                'cash_in' => 125000.0,
                'cash_out' => 55000.0,
                'ending_balance' => 170000.0,
            ],
            'rows' => [
                [
                    'date' => '2026-05-27',
                    'ref' => 'IN-1',
                    'cash_account_name' => 'Main Bank',
                    'money_in' => 125000.0,
                    'money_out' => 0.0,
                    'balance' => 225000.0,
                ],
                [
                    'date' => '2026-05-27',
                    'ref' => 'OUT-1',
                    'cash_account_name' => 'Main Bank',
                    'money_in' => 0.0,
                    'money_out' => 55000.0,
                    'balance' => 170000.0,
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function openingSummary(): array
    {
        return [
            'bank_total' => 100000.0,
            'receivables_total' => 0.0,
            'assets_total' => 50000.0,
            'liabilities_total' => 0.0,
            'business_value' => 150000.0,
        ];
    }

    /**
     * @param array<string,mixed> $overrides
     * @return array<string,mixed>
     */
    private function context(array $overrides = []): array
    {
        return array_merge([
            'business_name' => 'Demo Business',
            'company_address' => "123 Market Street\nNairobi",
            'company_email' => 'finance@example.test',
            'company_phone' => '+254 700 000000',
            'company_tax_id' => 'P051234567A',
            'footer_text' => 'Finance reports are management summaries.',
            'logo_url' => '',
            'logo_path' => '',
            'logo_src' => '',
            'date_from' => '2026-05-01',
            'date_to' => '2026-05-31',
            'currency' => 'KES',
            'prepared_at' => '2026-05-27',
        ], $overrides);
    }

    private function resolveLogoSource(string $path): ?string
    {
        $method = new \ReflectionMethod(FinanceReportDocumentService::class, 'resolveLogoSource');
        $method->setAccessible(true);

        return $method->invoke($this->service(), $path);
    }
}
