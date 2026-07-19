<?php
/**
 * Export Report Page
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Load environment
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
use CRM\Modules\Reports;
use CRM\Services\PresentationWorkspaceGuardService;
use CRM\Services\WorkspaceContext;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$reportsModule = new Reports();
$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);
$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
$presentationGuard = new PresentationWorkspaceGuardService();
if ($presentationGuard->isBlocked('exports', $workspaceId)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo $presentationGuard->message('exports');
    exit;
}
$reportId = (int) ($_GET['id'] ?? 0);
$requestedFormat = strtolower(trim((string) ($_GET['format'] ?? 'csv')));
$format = $reportsModule->normalizeExportFormat($requestedFormat);

if ($reportId <= 0) {
    header('Location: reports.php');
    exit;
}

$report = $reportsModule->getViewableById($reportId, $userId);
if (!$report) {
    header('Location: reports.php?error=access_denied');
    exit;
}

if ($requestedFormat !== $format) {
    header('Location: report_view.php?id=' . $reportId . '&error=invalid_format');
    exit;
}

if (!$reportsModule->isExportFormatAvailable($format)) {
    header('Location: report_view.php?id=' . $reportId . '&error=' . urlencode($reportsModule->getExportUnavailableMessage($format)));
    exit;
}

$definitions = $reportsModule->getReportTypeDefinitions();
$definition = $definitions[$report['report_type']] ?? $definitions['custom'];
$parameters = [];
foreach ((array) ($definition['filters'] ?? []) as $filterKey) {
    if (isset($_GET[$filterKey]) && $_GET[$filterKey] !== '') {
        $parameters[$filterKey] = (string) $_GET[$filterKey];
    }
}

try {
    $reportData = $reportsModule->executeForUser($reportId, $userId, $parameters);
} catch (\Exception $e) {
    header('Location: report_view.php?id=' . $reportId . '&error=' . urlencode($e->getMessage()));
    exit;
}

$filename = preg_replace('/[^a-z0-9]/i', '_', strtolower((string) $report['name'])) . '_' . date('Y-m-d');

switch ($format) {
    case 'pdf':
        exportPdfReport($report, $reportData, $filename, $reportsModule);
        break;
    case 'excel':
        exportExcelReport($report, $reportData, $filename, $reportsModule);
        break;
    case 'csv':
    default:
        exportCsvReport($reportData, $filename);
        break;
}

function exportCsvReport(array $reportData, string $filename): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    header('Pragma: no-cache');
    header('Expires: 0');

    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    if ($reportData !== []) {
        $firstRow = reset($reportData);
        $headers = array_keys((array) $firstRow);
        fputcsv($output, array_map(static function (string $header): string {
            return ucwords(str_replace('_', ' ', str_replace(['a.', 'c.'], '', $header)));
        }, $headers));

        foreach ($reportData as $row) {
            fputcsv($output, array_values((array) $row));
        }
    } else {
        fputcsv($output, ['No data available']);
    }

    fclose($output);
    exit;
}

function exportPdfReport(array $report, array $reportData, string $filename, Reports $reportsModule): void
{
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    $pdf->SetCreator(brandProductName());
    $pdf->SetAuthor(brandProductName());
    $pdf->SetTitle((string) $report['name']);
    $pdf->SetSubject('Report Export');
    $pdf->setPrintHeader(false);
    $pdf->setPrintFooter(false);
    $pdf->AddPage();

    $pdf->SetFont('helvetica', 'B', 16);
    $pdf->Cell(0, 10, (string) $report['name'], 0, 1, 'L');

    if (!empty($report['description'])) {
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 5, (string) $report['description'], 0, 1, 'L');
        $pdf->Ln(5);
    }

    $pdf->SetFont('helvetica', '', 9);
    $pdf->Cell(0, 5, 'Generated: ' . date('Y-m-d H:i:s'), 0, 1, 'L');
    $pdf->Cell(0, 5, 'Total Records: ' . count($reportData), 0, 1, 'L');
    $pdf->Ln(5);

    if ($reportData !== []) {
        $headers = array_keys((array) reset($reportData));
        $pageWidth = $pdf->getPageWidth() - 20;
        $columnWidth = $pageWidth / max(count($headers), 1);

        $pdf->SetFont('helvetica', 'B', 9);
        $pdf->SetFillColor(240, 240, 240);
        foreach ($headers as $header) {
            $pdf->Cell($columnWidth, 7, ucwords(str_replace('_', ' ', str_replace(['a.', 'c.'], '', (string) $header))), 1, 0, 'L', true);
        }
        $pdf->Ln();

        $pdf->SetFont('helvetica', '', 8);
        foreach ($reportData as $index => $row) {
            if ($index > 0 && $index % 25 === 0) {
                $pdf->AddPage();
            }
            foreach ((array) $row as $column => $value) {
                $displayValue = $reportsModule->formatDisplayValue((string) $column, $value);
                if (strlen($displayValue) > 30) {
                    $displayValue = substr($displayValue, 0, 27) . '...';
                }
                $pdf->Cell($columnWidth, 6, $displayValue, 1, 0, 'L', false);
            }
            $pdf->Ln();
        }
    } else {
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 10, 'No data available', 0, 1, 'C');
    }

    $pdf->Output($filename . '.pdf', 'D');
    exit;
}

function exportExcelReport(array $report, array $reportData, string $filename, Reports $reportsModule): void
{
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();

    $spreadsheet->getProperties()
        ->setCreator(brandProductName())
        ->setTitle((string) $report['name'])
        ->setSubject('Report Export')
        ->setDescription((string) ($report['description'] ?? ''));

    $sheet->setCellValue('A1', (string) $report['name']);
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

    $headerCount = count((array) ($reportData !== [] ? reset($reportData) : ['No data available']));
    $sheet->mergeCells('A1:' . \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(max($headerCount, 1)) . '1');

    $rowIndex = 2;
    if (!empty($report['description'])) {
        $sheet->setCellValue('A' . $rowIndex, (string) $report['description']);
        $rowIndex++;
    }

    $sheet->setCellValue('A' . $rowIndex, 'Generated: ' . date('Y-m-d H:i:s'));
    $rowIndex++;
    $sheet->setCellValue('A' . $rowIndex, 'Total Records: ' . count($reportData));
    $rowIndex += 2;

    if ($reportData !== []) {
        $headers = array_keys((array) reset($reportData));
        $columnIndex = 1;
        foreach ($headers as $header) {
            $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($columnIndex) . $rowIndex;
            $sheet->setCellValue($cell, ucwords(str_replace('_', ' ', str_replace(['a.', 'c.'], '', (string) $header))));
            $sheet->getStyle($cell)->getFont()->setBold(true);
            $sheet->getStyle($cell)->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()->setARGB('FFE0E0E0');
            $columnIndex++;
        }
        $rowIndex++;

        foreach ($reportData as $row) {
            $columnIndex = 1;
            foreach ((array) $row as $column => $value) {
                $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($columnIndex) . $rowIndex;
                $sheet->setCellValue($cell, $reportsModule->formatDisplayValue((string) $column, $value));
                $columnIndex++;
            }
            $rowIndex++;
        }

        foreach (range(1, count($headers)) as $columnIndex) {
            $sheet->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($columnIndex))->setAutoSize(true);
        }
    } else {
        $sheet->setCellValue('A' . $rowIndex, 'No data available');
    }

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '.xlsx"');
    header('Cache-Control: max-age=0');
    $writer->save('php://output');
    exit;
}
