<?php

namespace CRM\Services;

use CRM\Modules\Reports;
use CRM\Modules\ScheduledReports;

class ScheduledReportRuntimeService
{
    private ScheduledReports $scheduledReports;
    private Reports $reports;

    public function __construct(?ScheduledReports $scheduledReports = null, ?Reports $reports = null)
    {
        $this->scheduledReports = $scheduledReports ?? new ScheduledReports();
        $this->reports = $reports ?? new Reports();
    }

    public function processScheduleById(int $scheduleId, ?int $workspaceId = null): array
    {
        $schedule = $this->loadSchedule($scheduleId, $workspaceId);
        if ($schedule === null) {
            throw new \RuntimeException('Scheduled report not found for this workspace.');
        }

        return $this->processSchedule($schedule);
    }

    public function replayFailedRun(int $runId, ?int $workspaceId = null): array
    {
        $run = $this->loadRun($runId, $workspaceId);
        if ($run === null) {
            throw new \RuntimeException('Scheduled report run not found for this workspace.');
        }

        return $this->processScheduleById((int) $run['scheduled_report_id'], (int) ($run['workspace_id'] ?? 0));
    }

    /**
     * @return array<string,mixed>
     */
    public function processSchedule(array $schedule): array
    {
        $workspaceId = (int) ($schedule['workspace_id'] ?? 0);
        if ($workspaceId <= 0) {
            throw new \RuntimeException('Scheduled report is missing a valid workspace.');
        }

        return AsyncWorkspaceRunner::runWithWorkspace(
            $workspaceId,
            function () use ($schedule, $workspaceId): array {
                $startTime = microtime(true);
                $status = 'success';
                $errorMessage = null;
                $resultCount = 0;
                $filePath = null;
                $sentTo = [];

                try {
                    $reportData = $this->reports->execute((int) $schedule['report_id'], []);
                    $filePath = $this->generateReportFile(
                        (string) ($schedule['report_name'] ?? $schedule['schedule_name'] ?? 'report'),
                        $reportData,
                        (string) ($schedule['format'] ?? 'csv'),
                        (int) $schedule['id']
                    );
                    $resultCount = count($reportData);

                    $recipients = json_decode((string) ($schedule['recipients'] ?? '[]'), true);
                    $recipients = is_array($recipients) ? $recipients : [];
                    foreach ($recipients as $recipient) {
                        $email = is_array($recipient) ? ($recipient['email'] ?? $recipient) : $recipient;
                        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                            continue;
                        }

                        $this->sendReportEmail((string) $email, $schedule, (string) $filePath, $resultCount);
                        $sentTo[] = (string) $email;
                    }
                } catch (\Throwable $e) {
                    $status = 'failed';
                    $errorMessage = $e->getMessage();
                }

                $runId = $this->scheduledReports->logExecution((int) $schedule['id'], $status, [
                    'result_count' => $resultCount,
                    'error_message' => $errorMessage,
                    'file_path' => $filePath,
                    'sent_to' => $sentTo,
                    'execution_time' => microtime(true) - $startTime,
                ]);

                if ($status === 'success') {
                    $this->scheduledReports->updateNextRunTime((int) $schedule['id']);
                    $this->cleanupOldExport((string) $filePath);
                }

                return [
                    'success' => $status === 'success',
                    'status' => $status,
                    'run_id' => $runId,
                    'schedule_id' => (int) $schedule['id'],
                    'workspace_id' => $workspaceId,
                    'file_path' => $filePath,
                    'result_count' => $resultCount,
                    'sent_to' => $sentTo,
                    'error_message' => $errorMessage,
                ];
            },
            null,
            'Scheduled report is missing a valid workspace.'
        );
    }

    private function loadSchedule(int $scheduleId, ?int $workspaceId = null): ?array
    {
        if ($scheduleId <= 0) {
            return null;
        }

        $sql = "SELECT sr.*, r.name AS report_name
                FROM scheduled_reports sr
                INNER JOIN reports r ON r.id = sr.report_id AND r.workspace_id = sr.workspace_id
                WHERE sr.id = ?";
        $params = [$scheduleId];

        if ($workspaceId !== null && $workspaceId > 0) {
            $sql .= " AND sr.workspace_id = ?";
            $params[] = $workspaceId;
        }

        $sql .= " LIMIT 1";
        return \CRM\Database::queryOne($sql, $params);
    }

    private function loadRun(int $runId, ?int $workspaceId = null): ?array
    {
        if ($runId <= 0) {
            return null;
        }

        $sql = "SELECT srr.*, sr.report_id, sr.schedule_name, sr.recipients, sr.format, r.name AS report_name
                FROM scheduled_report_runs srr
                INNER JOIN scheduled_reports sr
                    ON sr.id = srr.scheduled_report_id
                   AND sr.workspace_id = srr.workspace_id
                INNER JOIN reports r
                    ON r.id = sr.report_id
                   AND r.workspace_id = sr.workspace_id
                WHERE srr.id = ?";
        $params = [$runId];

        if ($workspaceId !== null && $workspaceId > 0) {
            $sql .= " AND srr.workspace_id = ?";
            $params[] = $workspaceId;
        }

        $sql .= " LIMIT 1";
        return \CRM\Database::queryOne($sql, $params);
    }

    private function generateReportFile(string $reportName, array $reportData, string $format, int $scheduleId): string
    {
        $exportDir = __DIR__ . '/../exports/reports/';
        if (!is_dir($exportDir)) {
            mkdir($exportDir, 0755, true);
        }

        $filename = preg_replace('/[^a-z0-9]/i', '_', strtolower($reportName)) . '_' . date('Y-m-d_His') . '_' . $scheduleId;

        return match ($format) {
            'pdf' => $this->generatePdf($reportData, $exportDir . $filename . '.pdf', $reportName),
            'excel' => $this->generateExcel($reportData, $exportDir . $filename . '.xlsx', $reportName),
            default => $this->generateCsv($reportData, $exportDir . $filename . '.csv'),
        };
    }

    private function generateCsv(array $reportData, string $filePath): string
    {
        $file = fopen($filePath, 'w');
        fprintf($file, chr(0xEF) . chr(0xBB) . chr(0xBF));

        if ($reportData !== []) {
            $firstRow = reset($reportData);
            $headers = array_keys(is_array($firstRow) ? $firstRow : []);
            fputcsv($file, array_map(static fn(string $header): string => ucwords(str_replace('_', ' ', $header)), $headers));
            foreach ($reportData as $row) {
                fputcsv($file, array_values(is_array($row) ? $row : []));
            }
        } else {
            fputcsv($file, ['No data available']);
        }

        fclose($file);
        return $filePath;
    }

    private function generatePdf(array $reportData, string $filePath, string $reportName): string
    {
        if (!class_exists('TCPDF')) {
            return $this->generateCsv($reportData, str_replace('.pdf', '.csv', $filePath));
        }

        try {
            $pdf = new \TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
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

            if ($reportData !== []) {
                $firstRow = reset($reportData);
                $headers = array_keys(is_array($firstRow) ? $firstRow : []);
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
            return $this->generateCsv($reportData, str_replace('.pdf', '.csv', $filePath));
        }
    }

    private function generateExcel(array $reportData, string $filePath, string $reportName): string
    {
        if (!class_exists('PhpOffice\\PhpSpreadsheet\\Spreadsheet')) {
            return $this->generateCsv($reportData, str_replace('.xlsx', '.csv', $filePath));
        }

        try {
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $spreadsheet->getProperties()->setCreator('CRM System')->setTitle($reportName);
            $sheet->setCellValue('A1', $reportName);
            $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

            $rowNumber = 3;
            if ($reportData !== []) {
                $firstRow = reset($reportData);
                $headers = array_keys(is_array($firstRow) ? $firstRow : []);

                $colNumber = 1;
                foreach ($headers as $header) {
                    $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colNumber) . $rowNumber;
                    $sheet->setCellValue($cell, ucwords(str_replace('_', ' ', $header)));
                    $sheet->getStyle($cell)->getFont()->setBold(true);
                    $colNumber++;
                }
                $rowNumber++;

                foreach ($reportData as $dataRow) {
                    $colNumber = 1;
                    foreach ($headers as $header) {
                        $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colNumber) . $rowNumber;
                        $sheet->setCellValue($cell, $dataRow[$header] ?? '');
                        $colNumber++;
                    }
                    $rowNumber++;
                }

                foreach (range(1, count($headers)) as $colNumber) {
                    $sheet->getColumnDimension(\PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($colNumber))->setAutoSize(true);
                }
            }

            $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
            $writer->save($filePath);
            return $filePath;
        } catch (\Throwable $e) {
            return $this->generateCsv($reportData, str_replace('.xlsx', '.csv', $filePath));
        }
    }

    private function sendReportEmail(string $email, array $schedule, string $filePath, int $resultCount): void
    {
        $subject = 'Scheduled Report: ' . ($schedule['schedule_name'] ?? $schedule['report_name'] ?? 'Report');
        $body = "Your scheduled report is ready.\n\nReport: " . ($schedule['report_name'] ?? 'Report')
            . "\nRows: {$resultCount}\nGenerated: " . date('Y-m-d H:i:s');

        $smtp = new SMTPClient();
        $attachments = ($filePath !== '' && file_exists($filePath)) ? [$filePath] : [];
        $fromEmail = $smtp->getPreferredFromEmail('noreply@example.com') ?? 'noreply@example.com';
        $fromName = $smtp->getPreferredFromName('CRM System') ?? 'CRM System';
        $smtp->send($email, $fromEmail, $fromName, $subject, $body, $attachments);
    }

    private function cleanupOldExport(string $filePath): void
    {
        if ($filePath === '' || !file_exists($filePath)) {
            return;
        }

        $fileAge = time() - filemtime($filePath);
        if ($fileAge > (7 * 24 * 60 * 60)) {
            @unlink($filePath);
        }
    }
}
