<?php
require_once __DIR__ . '/../vendor/autoload.php';
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) continue;
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}
require_once __DIR__ . '/../config/constants.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Modules\Invoices;
use CRM\Services\InvoicePdfService;
use CRM\Session;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}
Authorization::requirePermission('invoices.view');

$invoice = (new Invoices())->getById((int) ($_GET['id'] ?? 0));
if (!$invoice) {
    header('Location: invoices.php');
    exit;
}

$pdfService = new InvoicePdfService();
$tempPdf = $pdfService->renderToTemporaryFile($invoice);
$pdfFile = $tempPdf['path'];
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $pdfService->buildFilename($invoice) . '"');
readfile($pdfFile);
@unlink($pdfFile);
exit;
