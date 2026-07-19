<?php
/**
 * Signature Asset Upload - Logo and images for email signatures
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
use CRM\Session;
use CRM\Auth;
use CRM\Security;
use CRM\Modules\EmailSignatures;

\CRM\Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid security token']);
    exit;
}

$signatureId = (int) ($_POST['signature_id'] ?? 0);
$type = $_POST['type'] ?? '';

if (!$signatureId || !in_array($type, ['logo', 'image'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid signature_id or type']);
    exit;
}

$signaturesModule = new EmailSignatures();
$signature = $signaturesModule->getById($signatureId);
if (!$signature) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Signature not found']);
    exit;
}

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $err = $_FILES['file']['error'] ?? 'unknown';
    $msg = $err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE ? 'File too large' : 'File upload failed';
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

$file = $_FILES['file'];
$allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!in_array($mimeType, $allowedTypes)) {
    echo json_encode(['success' => false, 'error' => 'Only images (JPEG, PNG, GIF, WebP) are allowed']);
    exit;
}

$maxSize = 2 * 1024 * 1024; // 2MB
if ($file['size'] > $maxSize) {
    echo json_encode(['success' => false, 'error' => 'File must be under 2MB']);
    exit;
}

$uploadDir = __DIR__ . '/../uploads/signatures/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$ext = match ($mimeType) {
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/gif' => 'gif',
    'image/webp' => 'webp',
    default => pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'jpg'
};
$fileName = $signatureId . '_' . $type . '_' . uniqid() . '.' . $ext;
$filePath = $uploadDir . $fileName;

if (!move_uploaded_file($file['tmp_name'], $filePath)) {
    echo json_encode(['success' => false, 'error' => 'Failed to save file']);
    exit;
}

$relativePath = 'signatures/' . $fileName;

if ($type === 'logo') {
    $settings = is_string($signature['settings'] ?? null) ? json_decode($signature['settings'], true) : ($signature['settings'] ?? []);
    $settings = is_array($settings) ? $settings : [];
    $settings['logo_path'] = $relativePath;
    $signaturesModule->update($signatureId, ['settings' => $settings]);
}

$basePath = getBasePath();
$assetUrl = $basePath . '/signature_asset.php?signature_id=' . $signatureId . '&type=' . $type . '&f=' . urlencode($fileName);

echo json_encode([
    'success' => true,
    'url' => $assetUrl,
    'path' => $relativePath,
    'fileName' => $fileName
]);
