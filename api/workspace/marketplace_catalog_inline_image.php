<?php

require_once __DIR__ . '/../../vendor/autoload.php';

$envFile = __DIR__ . '/../../.env';
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

require_once __DIR__ . '/../../config/constants.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Security;
use CRM\Session;
use CRM\Services\WorkspaceSkillCatalogService;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json');

$json = static function (int $status, string $error): void {
    http_response_code($status);
    echo json_encode(['success' => false, 'error' => $error], JSON_UNESCAPED_SLASHES);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $json(405, 'Method not allowed');
}

if (!Auth::check()) {
    $json(401, 'Unauthorized');
}

$user = Auth::user() ?: [];
if (!Authorization::isSuperAdmin($user)) {
    $json(403, 'Only superadmins can upload Marketplace catalog images.');
}

if (!Security::validateCSRF((string) ($_POST['csrf_token'] ?? ''))) {
    $json(403, 'Invalid security token');
}

$skillKey = trim((string) ($_POST['skill_key'] ?? ''));
if ($skillKey === '') {
    $json(422, 'Missing Marketplace item');
}

$catalog = new WorkspaceSkillCatalogService();
if ($catalog->find($skillKey, true) === null) {
    $json(422, 'Marketplace item not found');
}

$file = $_FILES['image_file'] ?? null;
if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    $json(422, 'Choose an image to upload.');
}

$uploadError = (int) ($file['error'] ?? UPLOAD_ERR_OK);
if ($uploadError !== UPLOAD_ERR_OK) {
    $message = match ($uploadError) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Marketplace images must be 4 MB or smaller.',
        UPLOAD_ERR_PARTIAL => 'The image was only partially uploaded. Please try again.',
        UPLOAD_ERR_NO_TMP_DIR => 'The server is missing a temporary upload folder.',
        UPLOAD_ERR_CANT_WRITE => 'The server could not write the uploaded image.',
        UPLOAD_ERR_EXTENSION => 'The image was blocked by a server upload extension.',
        default => 'Marketplace image upload failed.',
    };
    $json(422, $message);
}

if ((int) ($file['size'] ?? 0) > 4 * 1024 * 1024) {
    $json(422, 'Marketplace images must be 4 MB or smaller.');
}

$tmpName = (string) ($file['tmp_name'] ?? '');
$isEndpointTest = PHP_SAPI === 'cli' && (string) (getenv('CRM_ENDPOINT_TEST') ?: '') === '1';
if ($tmpName === '' || (!is_uploaded_file($tmpName) && !$isEndpointTest)) {
    $json(422, 'Marketplace image upload was not valid.');
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = $finfo ? (string) finfo_file($finfo, $tmpName) : (string) ($file['type'] ?? '');
if ($finfo) {
    finfo_close($finfo);
}

$extensions = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    'image/gif' => 'gif',
];
if (!isset($extensions[$mime])) {
    $json(422, 'Marketplace images must be JPG, PNG, WebP, or GIF files.');
}

$safeKey = preg_replace('/[^a-z0-9_]+/', '_', strtolower($skillKey)) ?: 'module';
$projectRoot = realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2);
$uploadRoot = $projectRoot;
if ($isEndpointTest) {
    $testRoot = trim((string) (getenv('MARKETPLACE_INLINE_UPLOAD_ROOT') ?: ($_ENV['MARKETPLACE_INLINE_UPLOAD_ROOT'] ?? '')));
    if ($testRoot !== '') {
        $uploadRoot = rtrim($testRoot, "\\/");
    }
}

$uploadDir = $uploadRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'marketplace' . DIRECTORY_SEPARATOR . $safeKey;
if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
    $json(500, 'Could not create marketplace upload directory.');
}

$fileName = 'inline-image-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $extensions[$mime];
$destination = $uploadDir . DIRECTORY_SEPARATOR . $fileName;
$saved = $isEndpointTest ? copy($tmpName, $destination) : move_uploaded_file($tmpName, $destination);
if (!$saved) {
    $json(500, 'Could not save marketplace image.');
}
@chmod($destination, 0644);

$storedPath = 'uploads/marketplace/' . $safeKey . '/' . $fileName;
$alt = trim(strip_tags(str_replace("\0", '', (string) ($_POST['alt'] ?? ''))));
$caption = trim(strip_tags(str_replace("\0", '', (string) ($_POST['caption'] ?? ''))));
$alt = mb_substr($alt, 0, 180);
$caption = mb_substr($caption, 0, 240);

echo json_encode([
    'success' => true,
    'stored_path' => $storedPath,
    'html_src' => '../' . $storedPath,
    'alt' => $alt,
    'caption' => $caption,
], JSON_UNESCAPED_SLASHES);
