<?php
/**
 * Generate Quote PDF from Deal
 * Reuses TCPDF logic from report_export.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/constants.php';
use CRM\Database;
use CRM\Session;
use CRM\Auth;
use CRM\Modules\Deals;
use CRM\Modules\Currencies;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: ' . getBasePath() . '/login.php');
    exit;
}

$dealId = (int) ($_GET['id'] ?? 0);
if (!$dealId) {
    header('Location: ' . getBasePath() . '/deals.php');
    exit;
}

$dealsModule = new Deals();
$currenciesModule = new Currencies();
$deal = $dealsModule->getById($dealId);

if (!$deal) {
    header('Location: ' . getBasePath() . '/deals.php');
    exit;
}

$lineItems = $deal['line_items'] ?? [];
$currency = $deal['currency'] ?? 'USD';
$curr = $currenciesModule->getByCode($currency);
$symbol = $curr['symbol'] ?? $currency;

if (!class_exists('TCPDF')) {
    header('Location: ' . getBasePath() . '/deal_view.php?id=' . $dealId . '&error=' . urlencode('PDF library not available'));
    exit;
}

try {
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    $pdf->SetCreator(brandProductName());
    $pdf->SetAuthor(COMPANY_NAME);
    $pdf->SetTitle('Quote - ' . $deal['title']);
    $pdf->SetSubject('Quote');
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->AddPage();

    // Title
    $pdf->SetFont('helvetica', 'B', 18);
    $pdf->Cell(0, 10, 'QUOTE', 0, 1, 'L');
    $pdf->Ln(3);

    // Quote number and valid until
    $pdf->SetFont('helvetica', '', 10);
    if (!empty($deal['quote_number'])) {
        $pdf->Cell(0, 6, 'Quote #: ' . $deal['quote_number'], 0, 1, 'L');
    }
    if (!empty($deal['quote_valid_until'])) {
        $pdf->Cell(0, 6, 'Valid Until: ' . date('F j, Y', strtotime($deal['quote_valid_until'])), 0, 1, 'L');
    }
    $pdf->Cell(0, 6, 'Date: ' . date('F j, Y'), 0, 1, 'L');
    $pdf->Ln(5);

    // Deal title
    $pdf->SetFont('helvetica', 'B', 14);
    $pdf->Cell(0, 8, $deal['title'], 0, 1, 'L');
    if (!empty($deal['description'])) {
        $pdf->SetFont('helvetica', '', 9);
        $pdf->MultiCell(0, 5, $deal['description'], 0, 'L');
    }
    $pdf->Ln(5);

    // Contact/Company info
    $pdf->SetFont('helvetica', '', 9);
    $contactName = trim(($deal['contact_first_name'] ?? '') . ' ' . ($deal['contact_last_name'] ?? ''));
    if ($contactName !== '') {
        $pdf->Cell(0, 6, 'Prepared for: ' . $contactName, 0, 1, 'L');
    }
    if (!empty($deal['company_id'])) {
        $company = Database::queryOne("SELECT name FROM companies WHERE id = ?", [$deal['company_id']]);
        if ($company && !empty($company['name'])) {
            $pdf->Cell(0, 6, 'Company: ' . $company['name'], 0, 1, 'L');
        }
    }
    $pdf->Ln(8);

    // Line items table
    $pageWidth = $pdf->getPageWidth() - 20;
    $colDesc = $pageWidth * 0.40;
    $colQty = $pageWidth * 0.12;
    $colPrice = $pageWidth * 0.18;
    $colDisc = $pageWidth * 0.12;
    $colTotal = $pageWidth * 0.18;

    $pdf->SetFont('helvetica', 'B', 9);
    $pdf->SetFillColor(240, 240, 240);
    $pdf->Cell($colDesc, 7, 'Description', 1, 0, 'L', true);
    $pdf->Cell($colQty, 7, 'Qty', 1, 0, 'R', true);
    $pdf->Cell($colPrice, 7, 'Unit Price', 1, 0, 'R', true);
    $pdf->Cell($colDisc, 7, 'Discount', 1, 0, 'R', true);
    $pdf->Cell($colTotal, 7, 'Total', 1, 1, 'R', true);

    $pdf->SetFont('helvetica', '', 9);
    $pdf->SetFillColor(255, 255, 255);

    if (!empty($lineItems)) {
        foreach ($lineItems as $li) {
            $desc = $li['product_name'] ?? $li['description'] ?? '-';
            $pdf->Cell($colDesc, 7, substr($desc, 0, 40), 1, 0, 'L', false);
            $pdf->Cell($colQty, 7, number_format($li['quantity'], 2), 1, 0, 'R', false);
            $pdf->Cell($colPrice, 7, $symbol . ' ' . number_format($li['unit_price'], 2), 1, 0, 'R', false);
            $pdf->Cell($colDisc, 7, number_format($li['discount_percent'], 1) . '%', 1, 0, 'R', false);
            $pdf->Cell($colTotal, 7, $symbol . ' ' . number_format($li['total'], 2), 1, 1, 'R', false);
        }
    } else {
        $pdf->Cell($pageWidth, 10, 'No line items', 1, 1, 'C', false);
    }

    $pdf->Ln(3);
    $pdf->SetFont('helvetica', 'B', 11);
    $pdf->Cell($colDesc + $colQty + $colPrice + $colDisc, 8, 'Total:', 0, 0, 'R');
    $pdf->Cell($colTotal, 8, $symbol . ' ' . number_format($deal['value'] ?? 0, 2), 0, 1, 'R');

    $filename = 'quote_' . preg_replace('/[^a-z0-9]/i', '_', strtolower($deal['title'])) . '_' . date('Y-m-d') . '.pdf';
    $pdf->Output($filename, 'D');
    exit;
} catch (\Exception $e) {
    header('Location: ' . getBasePath() . '/deal_view.php?id=' . $dealId . '&error=' . urlencode($e->getMessage()));
    exit;
}
