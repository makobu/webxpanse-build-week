<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

$source = __DIR__ . '/../docs/clarity-onboarding-outreach-playbook.md';
$output = __DIR__ . '/../docs/clarity-onboarding-outreach-playbook.pdf';

if (!is_file($source)) {
    fwrite(STDERR, "Source file not found: {$source}\n");
    exit(1);
}

$markdown = file_get_contents($source);
if ($markdown === false) {
    fwrite(STDERR, "Failed to read source file.\n");
    exit(1);
}

function renderPlaybookMarkdownToHtml(string $markdown): string
{
    $lines = preg_split("/\r\n|\n|\r/", $markdown) ?: [];
    $html = [];
    $inList = false;

    $flushList = static function () use (&$html, &$inList): void {
        if ($inList) {
            $html[] = '</ul>';
            $inList = false;
        }
    };

    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') {
            $flushList();
            continue;
        }

        if (str_starts_with($trimmed, '# ')) {
            $flushList();
            $html[] = '<h1>' . htmlspecialchars(substr($trimmed, 2), ENT_QUOTES, 'UTF-8') . '</h1>';
            continue;
        }

        if (str_starts_with($trimmed, '## ')) {
            $flushList();
            $html[] = '<h2>' . htmlspecialchars(substr($trimmed, 3), ENT_QUOTES, 'UTF-8') . '</h2>';
            continue;
        }

        if (str_starts_with($trimmed, '### ')) {
            $flushList();
            $html[] = '<h3>' . htmlspecialchars(substr($trimmed, 4), ENT_QUOTES, 'UTF-8') . '</h3>';
            continue;
        }

        if (str_starts_with($trimmed, '- ') || str_starts_with($trimmed, '- [')) {
            if (!$inList) {
                $html[] = '<ul>';
                $inList = true;
            }
            $item = htmlspecialchars(substr($trimmed, 2), ENT_QUOTES, 'UTF-8');
            $item = preg_replace('/`([^`]+)`/', '<code>$1</code>', $item);
            $html[] = '<li>' . $item . '</li>';
            continue;
        }

        if (preg_match('/^\d+\.\s+/', $trimmed)) {
            $flushList();
            $paragraph = htmlspecialchars($trimmed, ENT_QUOTES, 'UTF-8');
            $paragraph = preg_replace('/`([^`]+)`/', '<code>$1</code>', $paragraph);
            $html[] = '<p>' . $paragraph . '</p>';
            continue;
        }

        $flushList();
        $paragraph = htmlspecialchars($trimmed, ENT_QUOTES, 'UTF-8');
        $paragraph = preg_replace('/`([^`]+)`/', '<code>$1</code>', $paragraph);
        $html[] = '<p>' . $paragraph . '</p>';
    }

    $flushList();

    return implode("\n", $html);
}

$html = renderPlaybookMarkdownToHtml($markdown);

$pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
$pdf->SetCreator('CRM');
$pdf->SetAuthor('Codex');
$pdf->SetTitle('Clarity Onboarding and Outreach Playbook');
$pdf->SetSubject('Go-to-market, pricing, outreach, and demo materials');
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(true);
$pdf->SetMargins(14, 14, 14);
$pdf->SetAutoPageBreak(true, 12);
$pdf->SetFont('dejavusans', '', 10);
$pdf->AddPage();

$stylesheet = <<<HTML
<style>
body { font-family: dejavusans; color: #0f172a; }
h1 { font-size: 20pt; color: #0f172a; margin-bottom: 8px; }
h2 { font-size: 14pt; color: #0f172a; margin-top: 14px; margin-bottom: 6px; }
h3 { font-size: 11.5pt; color: #0f172a; margin-top: 10px; margin-bottom: 4px; }
p { font-size: 10pt; line-height: 1.45; margin: 0 0 6px 0; }
ul { margin: 0 0 8px 14px; padding: 0; }
li { font-size: 10pt; line-height: 1.45; margin-bottom: 3px; }
code { background-color: #e2e8f0; color: #0f172a; font-size: 9pt; }
</style>
HTML;

$pdf->writeHTML($stylesheet . $html, true, false, true, false, '');
$pdf->Output($output, 'F');

fwrite(STDOUT, "Generated: {$output}\n");
