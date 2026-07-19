<?php
/**
 * Form Asset Upload - Logo and header image for forms
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
use CRM\Authorization;
use CRM\Security;
use CRM\Modules\Forms;
use CRM\Services\MarketingMarketplaceGateService;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    Auth::jsonAuthError('', 401, 'Unauthorized');
    exit;
}

header('Content-Type: application/json');

$user = Auth::user() ?: [];
$designGate = new MarketingMarketplaceGateService();
if (!$designGate->canRun($user, MarketingMarketplaceGateService::FEATURE_DESIGN)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Design is not available for this workspace.']);
    exit;
}
if (!Authorization::can('marketing.write', $user)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'You do not have permission to update form assets.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
    Auth::jsonAuthError('csrf_invalid', 403, 'Invalid security token');
    exit;
}

$formId = (int) ($_POST['form_id'] ?? 0);
$formUuid = trim((string) ($_POST['form_uuid'] ?? ''));
$type = $_POST['type'] ?? '';

if (!in_array($type, ['logo', 'header_image'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid type']);
    exit;
}

$formsModule = new Forms();
$form = null;
if ($formUuid !== '') {
    $form = $formsModule->getByUuid($formUuid);
    $formId = (int) ($form['id'] ?? 0);
} elseif ($formId > 0) {
    $form = $formsModule->getById($formId);
    $formUuid = (string) ($form['uuid'] ?? '');
}

if (!$form) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Form not found']);
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

$uploadDir = __DIR__ . '/../uploads/forms/';
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
$fileKey = $formUuid !== '' ? preg_replace('/[^a-zA-Z0-9_-]/', '', $formUuid) : (string) $formId;
$fileName = $fileKey . '_' . $type . '_' . uniqid() . '.' . $ext;
$filePath = $uploadDir . $fileName;

if (!move_uploaded_file($file['tmp_name'], $filePath)) {
    echo json_encode(['success' => false, 'error' => 'Failed to save file']);
    exit;
}

$relativePath = 'forms/' . $fileName;
$settings = is_string($form['settings'] ?? null) ? json_decode($form['settings'], true) : ($form['settings'] ?? []);
$settings = is_array($settings) ? $settings : [];
$settings[$type . '_path'] = $relativePath;

if ($formUuid !== '') {
    $formsModule->updateByUuid($formUuid, ['settings' => $settings]);
} else {
    $formsModule->update($formId, ['settings' => $settings]);
}

$basePath = getBasePath();
$assetVersion = rawurlencode($fileName);
$assetUrl = $basePath . '/form_asset.php?form_uuid=' . urlencode($form['uuid']) . '&type=' . $type . '&v=' . $assetVersion;

echo json_encode([
    'success' => true,
    'url' => $assetUrl,
    'path' => $relativePath
]);
