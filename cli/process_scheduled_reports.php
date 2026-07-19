<?php
/**
 * Scheduled Report Processor (one-shot)
 *
 * SiteGround-safe cron entrypoint for due scheduled reports.
 * Usage: php cli/process_scheduled_reports.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/constants.php';

use CRM\Database;
use CRM\Modules\Reports;
use CRM\Modules\ScheduledReports;
use CRM\Services\AsyncWorkspaceRunner;
use CRM\Services\SMTPClient;

Database::init(require __DIR__ . '/../config/database.php');

$scheduledReportsModule = new ScheduledReports();
$reportsModule = new Reports();
$readySchedules = $scheduledReportsModule->getReadyToRun();
$processed = 0;

foreach ($readySchedules as $schedule) {
    $startTime = microtime(true);
    $status = 'success';
    $errorMessage = null;
    $resultCount = 0;
    $filePath = null;
    $sentTo = [];

    try {
        $workspaceId = (int) ($schedule['workspace_id'] ?? 0);
        [$reportData, $filePath] = AsyncWorkspaceRunner::runWithWorkspace(
            $workspaceId,
            function () use ($reportsModule, $schedule): array {
                $reportData = $reportsModule->execute($schedule['report_id'], []);
                $filePath = generateReportFile(
                    $schedule['report_name'],
                    $reportData,
                    $schedule['format'],
                    (int) $schedule['id']
                );

                return [$reportData, $filePath];
            },
            null,
            'Scheduled report is missing a valid workspace.'
        );
        $resultCount = count($reportData);

        $recipients = json_decode($schedule['recipients'], true) ?? [];
        if (!empty($recipients)) {
            foreach ($recipients as $recipient) {
                $email = is_array($recipient) ? ($recipient['email'] ?? $recipient) : $recipient;
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    sendReportEmail($email, $schedule, $filePath, $resultCount);
                    $sentTo[] = $email;
                }
            }
        }
    } catch (\Throwable $e) {
        $status = 'failed';
        $errorMessage = $e->getMessage();
        error_log('process_scheduled_reports failed for schedule ' . (int) $schedule['id'] . ': ' . $e->getMessage());
    }

    $executionTime = microtime(true) - $startTime;
    $scheduledReportsModule->logExecution((int) $schedule['id'], $status, [
        'result_count' => $resultCount,
        'error_message' => $errorMessage,
        'file_path' => $filePath,
        'sent_to' => $sentTo,
        'execution_time' => $executionTime
    ]);
    $scheduledReportsModule->updateNextRunTime((int) $schedule['id']);

    if ($filePath && file_exists($filePath)) {
        $fileAge = time() - filemtime($filePath);
        if ($fileAge > (7 * 24 * 60 * 60)) {
            @unlink($filePath);
        }
    }

    $processed++;
}

echo sprintf("[%s] Scheduled report run complete: processed=%d\n", date('Y-m-d H:i:s'), $processed);

function generateReportFile(string $reportName, array $reportData, string $format, int $scheduleId): string
{
    $exportDir = __DIR__ . '/../exports/reports/';
    if (!is_dir($exportDir)) {
        mkdir($exportDir, 0755, true);
    }

    $filename = preg_replace('/[^a-z0-9]/i', '_', strtolower($reportName)) . '_' . date('Y-m-d_His') . '_' . $scheduleId;

    return match ($format) {
        'pdf' => generatePDF($reportData, $exportDir . $filename . '.pdf', $reportName),
        'excel' => generateExcel($reportData, $exportDir . $filename . '.xlsx', $reportName),
        default => generateCSV($reportData, $exportDir . $filename . '.csv', $reportName),
    };
}

function generateCSV(array $reportData, string $filePath, string $reportName): string
{
    $file = fopen($filePath, 'w');
    fprintf($file, chr(0xEF) . chr(0xBB) . chr(0xBF));

    if (!empty($reportData)) {
        $firstRow = reset($reportData);
        $headers = array_keys($firstRow);
        fputcsv($file, array_map(static fn($h) => ucwords(str_replace('_', ' ', $h)), $headers));
        foreach ($reportData as $row) {
            fputcsv($file, array_values($row));
        }
    } else {
        fputcsv($file, ['No data available']);
    }

    fclose($file);
    return $filePath;
}

function generatePDF(array $reportData, string $filePath, string $reportName): string
{
    if (!class_exists('TCPDF')) {
        return generateCSV($reportData, str_replace('.pdf', '.csv', $filePath), $reportName);
    }

    try {
        $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
        $pdf->SetCreator('CRM System');
        $pdf->SetAuthor('CRM System');
        $pdf->SetTitle($reportName);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->AddPage();
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->Cell(0, 10, $reportName, 0, 1, 'L');
        $pdf->SetFont('helvetica', '', 9);
        $pdf->Cell(0, 5, 'Generated: ' . date('Y-m-d H:i:s'), 0, 1, 'L');
        $pdf->Cell(0, 5, 'Total Records: ' . count($reportData), 0, 1, 'L');
        $pdf->Ln(5);

        if (!empty($reportData)) {
            $firstRow = reset($reportData);
            $headers = array_keys($firstRow);
            $pageWidth = $pdf->getPageWidth() - 20;
            $colWidth = $pageWidth / max(1, count($headers));

            $pdf->SetFont('helvetica', 'B', 9);
            $pdf->SetFillColor(240, 240, 240);
            foreach ($headers as $header) {
                $pdf->Cell($colWidth, 7, ucwords(str_replace('_', ' ', $header)), 1, 0, 'L', true);
            }
            $pdf->Ln();

            $pdf->SetFont('helvetica', '', 8);
            foreach ($reportData as $row) {
                foreach ($headers as $header) {
                    $value = isset($row[$header]) ? (string) $row[$header] : '';
                    if (strlen($value) > 30) {
                        $value = substr($value, 0, 27) . '...';
                    }
                    $pdf->Cell($colWidth, 6, $value, 1, 0, 'L', false);
                }
                $pdf->Ln();
            }
        }

        $pdf->Output($filePath, 'F');
        return $filePath;
    } catch (\Throwable $e) {
        return generateCSV($reportData, str_replace('.pdf', '.csv', $filePath), $reportName);
    }
}

function generateExcel(array $reportData, string $filePath, string $reportName): string
{
    if (!class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet')) {
        return generateCSV($reportData, str_replace('.xlsx', '.csv', $filePath), $reportName);
    }

    try {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $spreadsheet->getProperties()->setCreator('CRM System')->setTitle($reportName);
        $sheet->setCellValue('A1', $reportName);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $row = 3;
        if (!empty($reportData)) {
            $firstRow = reset($reportData);
            $headers = array_keys($firstRow);

            $col = 1;
            foreach ($headers as $header) {
                $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . $row;
                $sheet->setCellValue($cell, ucwords(str_replace('_', ' ', $header)));
                $sheet->getStyle($cell)->getFont()->setBold(true);
                $col++;
            }
            $row++;

            foreach ($reportData as $dataRow) {
                $col = 1;
                foreach ($headers as $header) {
                    $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . $row;
                    $sheet->setCellValue($cell, $dataRow[$header] ?? '');
                    $col++;
                }
                $row++;
            }

            foreach (range(1, count($headers)) as $col) {
                $sheet->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col))->setAutoSize(true);
            }
        }

        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
        $writer->save($filePath);
        return $filePath;
    } catch (\Throwable $e) {
        return generateCSV($reportData, str_replace('.xlsx', '.csv', $filePath), $reportName);
    }
}

function sendReportEmail(string $email, array $schedule, string $filePath, int $resultCount): void
{
    $subject = 'Scheduled Report: ' . ($schedule['schedule_name'] ?? $schedule['report_name'] ?? 'Report');
    $body = "Your scheduled report is ready.\n\nReport: " . ($schedule['report_name'] ?? 'Report')
        . "\nRows: {$resultCount}\nGenerated: " . date('Y-m-d H:i:s');

    $smtp = new SMTPClient();
    $attachments = ($filePath !== '' && file_exists($filePath)) ? [$filePath] : [];
    $fromEmail = $smtp->getPreferredFromEmail('noreply@example.com') ?? 'noreply@example.com';
    $fromName = $smtp->getPreferredFromName(brandProductName()) ?? brandProductName();
    $smtp->send($email, $fromEmail, $fromName, $subject, $body, $attachments);
}
