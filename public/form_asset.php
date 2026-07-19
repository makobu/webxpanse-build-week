<?php
/**
 * Form Asset Serve - Public endpoint for form logo/header images (no auth)
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
use CRM\Modules\Forms;

Database::init(require __DIR__ . '/../config/database.php');

$uuid = $_GET['form_uuid'] ?? '';
$type = $_GET['type'] ?? '';

if (!$uuid || !in_array($type, ['logo', 'header_image'])) {
    http_response_code(400);
    exit;
}

$formsModule = new Forms();
$form = $formsModule->getByUuid($uuid);
if (!$form) {
    http_response_code(404);
    exit;
}

$settings = is_string($form['settings'] ?? null) ? json_decode($form['settings'], true) : ($form['settings'] ?? []);
$settings = is_array($settings) ? $settings : [];
$path = $settings[$type . '_path'] ?? '';

if (empty($path)) {
    http_response_code(404);
    exit;
}

// Security: path must be under uploads/forms/ and no directory traversal
$path = str_replace(['../', '..\\'], '', $path);
if (strpos($path, 'forms/') !== 0 && $path !== 'forms') {
    $path = 'forms/' . basename($path);
}
$fullPath = realpath(__DIR__ . '/../uploads/' . $path);
$uploadBase = realpath(__DIR__ . '/../uploads/forms/');

if (!$fullPath || !$uploadBase || strpos($fullPath, $uploadBase) !== 0 || !is_file($fullPath)) {
    http_response_code(404);
    exit;
}

$mime = mime_content_type($fullPath);
if (strpos($mime, 'image/') !== 0) {
    http_response_code(403);
    exit;
}

header('Content-Type: ' . $mime);
header('Content-Disposition: inline');
header('Content-Length: ' . filesize($fullPath));
header('Cache-Control: public, max-age=86400');
header('X-Content-Type-Options: nosniff');
readfile($fullPath);
exit;
