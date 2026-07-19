<?php

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

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Modules\InvoiceSettings;
use CRM\Services\InvoiceRenderer;
use CRM\Services\InvoiceTemplateService;
use CRM\Session;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    http_response_code(401);
    echo 'Unauthorized';
    exit;
}
Authorization::requirePermission('settings.invoicing');

try {
    $settings = (new InvoiceSettings())->get();
    $documentType = $_GET['document_type'] ?? ($settings['preview_document_type'] ?? 'invoice');
    $theme = $_GET['template_key'] ?? $_GET['theme'] ?? ($settings['default_template_key'] ?? $settings['visual_theme'] ?? 'classic');
    $currency = strtoupper(trim((string) ($_GET['currency'] ?? ($settings['default_currency'] ?? 'USD'))));
    $templateService = new InvoiceTemplateService();
    $settings['preview_document_type'] = in_array((string) $documentType, ['quote', 'proforma', 'invoice', 'credit_note'], true) ? (string) $documentType : 'invoice';
    $settings['default_template_key'] = $templateService->resolveTemplateKey((string) $theme);
    $settings['visual_theme'] = $settings['default_template_key'];
    $settings['default_currency'] = $currency !== '' ? $currency : 'USD';
    $sample = $templateService->buildSampleInvoice($settings['preview_document_type'], $settings);

    header('Content-Type: text/html; charset=utf-8');
    echo (new InvoiceRenderer())->renderHtml($sample, $settings);
} catch (\Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><body style="font-family:Arial,sans-serif;padding:24px;color:#991b1b;background:#fff7f7;">'
        . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')
        . '</body></html>';
}
