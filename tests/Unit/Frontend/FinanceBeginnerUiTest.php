<?php

namespace CRM\Tests\Unit\Frontend;

use CRM\Tests\TestCase;

class FinanceBeginnerUiTest extends TestCase
{
    public function testFinancePageKeepsBeginnerRuntimeLabels(): void
    {
        $financeSource = file_get_contents(__DIR__ . '/../../../public/finance.php');
        $reportDocumentSource = file_get_contents(__DIR__ . '/../../../services/FinanceReportDocumentService.php');

        $this->assertNotFalse($financeSource);
        $this->assertNotFalse($reportDocumentSource);
        $source = (string) $financeSource . "\n" . (string) $reportDocumentSource;

        $this->assertStringContainsString('data-finance-open="finance-modal-in"', $source);
        $this->assertStringContainsString('data-finance-open="finance-modal-out"', $source);
        $this->assertStringContainsString('data-finance-open="finance-modal-funding"', $source);
        $this->assertStringContainsString('Latest In', $source);
        $this->assertStringContainsString('Latest Out', $source);
        $this->assertStringContainsString('Add money in', $source);
        $this->assertStringContainsString('Add money out', $source);
        $this->assertStringContainsString('Funding', $source);
        $this->assertStringContainsString('Add funding', $source);
        $this->assertStringContainsString('Owner money in', $source);
        $this->assertStringContainsString('Other income', $source);
        $this->assertStringContainsString('Net after profit share', $source);
        $this->assertStringContainsString('Things owned', $source);
        $this->assertStringContainsString('Liabilities and equity', $source);
        $this->assertStringContainsString('Export PDF', $source);
        $this->assertStringContainsString('Statement of Cash Flows', $source);
        $this->assertStringContainsString('Income Statement', $source);
        $this->assertStringContainsString('Bank Statement', $source);
        $this->assertStringContainsString('Balance Sheet', $source);
        $this->assertStringContainsString('finance_report_pdf.php?', $source);
        $this->assertStringContainsString('finance-kpi-strip', $source);
        $this->assertStringContainsString('finance-row-card', $source);
        $this->assertStringContainsString('finance-report-tabbed', $source);
        $this->assertStringContainsString('role="tablist" aria-label="Finance reports"', $source);
        $this->assertStringContainsString('data-finance-report-tab', $source);
        $this->assertStringContainsString('data-finance-report-panel', $source);
        $this->assertStringContainsString('aria-selected', $source);
        $this->assertStringContainsString('finance-report-document', $source);
        $this->assertStringContainsString('finance-report-t-account', $source);
        $this->assertStringContainsString('finance-btn-loud', $source);
        $this->assertStringContainsString('finance-btn-in', $source);
        $this->assertStringContainsString('finance-btn-out', $source);
        $this->assertStringContainsString('finance-btn-funding', $source);
        $this->assertStringContainsString('finance-card-in', $source);
        $this->assertStringContainsString('finance-card-out', $source);
        $this->assertStringContainsString('finance-card-bank', $source);
        $this->assertStringContainsString('finance-modal-card-in', $source);
        $this->assertStringContainsString('finance-modal-card-out', $source);
        $this->assertStringContainsString('finance-modal-card-funding', $source);
        $this->assertStringContainsString('finance-t-account', $source);
        $this->assertStringNotContainsString('Owner draw/dividend', $source);
    }

    public function testFinancePageKeepsAdvancedFieldsBehindMoreSections(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../../public/finance.php');

        $this->assertStringContainsString('<summary>More</summary>', $source);
        $this->assertStringContainsString('name="income_category_id" required', $source);
        $this->assertStringContainsString('name="category_id" required', $source);
        $this->assertStringContainsString('financeExpenseCategoryOptions($categories', $source);
        $this->assertStringContainsString('financeLedgerTypeLabel', $source);
    }
}
