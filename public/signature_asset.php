<?php
/**
 * Signature Asset Serve - Public endpoint for signature logo/images (no auth required for email clients)
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

Database::init(require __DIR__ . '/../config/database.php');

$signatureId = (int) ($_GET['signature_id'] ?? 0);
$type = $_GET['type'] ?? 'logo';
$file = $_GET['f'] ?? '';

$uploadDir = realpath(__DIR__ . '/../uploads/signatures/');
if (!$uploadDir || !is_dir($uploadDir)) {
    http_response_code(404);
    exit;
}

// For logo: verify signature exists and user owns it, then use settings path
// For image: file param is required (direct file serve)
if ($type === 'logo' && $signatureId) {
    $path = '';
    try {
        $signature = Database::queryOne("SELECT * FROM email_signatures WHERE id = ?", [$signatureId]);
        if (!$signature) {
            http_response_code(404);
            exit;
        }
        $settings = is_string($signature['settings'] ?? null) ? json_decode($signature['settings'], true) : ($signature['settings'] ?? []);
        $settings = is_array($settings) ? $settings : [];
        $path = (string) ($settings['logo_path'] ?? '');
    } catch (\Throwable $e) {
        $path = '';
    }

    if ($path === '') {
        $matches = glob($uploadDir . DIRECTORY_SEPARATOR . $signatureId . '_logo_*');
        if (!empty($matches)) {
            usort($matches, static function ($a, $b) {
                return filemtime($b) <=> filemtime($a);
            });
            $fullPath = realpath($matches[0]);
        } else {
            http_response_code(404);
            exit;
        }
    } else {
        $path = str_replace(['../', '..\\'], '', $path);
        if (strpos($path, 'signatures/') !== 0) {
            $path = 'signatures/' . basename($path);
        }
        $fullPath = realpath(__DIR__ . '/../uploads/' . $path);
    }
} elseif ($file && in_array($type, ['logo', 'image'])) {
    $file = basename(str_replace(['../', '..\\'], '', $file));
    if (!preg_match('/^\d+_' . preg_quote($type, '/') . '_[a-zA-Z0-9.]+\.(jpg|jpeg|png|gif|webp)$/i', $file)) {
        http_response_code(400);
        exit;
    }
    $fullPath = realpath($uploadDir . '/' . $file);
} else {
    http_response_code(400);
    exit;
}

if (!$fullPath || strpos($fullPath, $uploadDir) !== 0 || !is_file($fullPath)) {
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
