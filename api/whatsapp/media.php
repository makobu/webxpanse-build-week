<?php
/**
 * WhatsApp Media API
 * Returns temporary URL for a media item (image/video/audio/document/sticker) by communication ID.
 * Ensures user can only access media for communications they are allowed to see.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

$envFile = __DIR__ . '/../../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../../config/constants.php';

use CRM\Auth;
use CRM\Database;
use CRM\Services\WorkspaceScopeService;
use CRM\Services\WhatsAppService;

$dbConfig = require __DIR__ . '/../../config/database.php';
Database::init($dbConfig);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required']);
    exit;
}

$workspaceId = (new WorkspaceScopeService())->requireActiveWorkspaceId();

$communicationId = isset($_GET['communication_id']) ? (int) $_GET['communication_id'] : 0;

if ($communicationId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'communication_id is required']);
    exit;
}

$communication = Database::queryOne(
    "SELECT id, channel, metadata FROM communications WHERE workspace_id = ? AND id = ?",
    [$workspaceId, $communicationId]
);
if (!$communication || ($communication['channel'] ?? '') !== 'whatsapp') {
    http_response_code(404);
    echo json_encode(['error' => 'Communication not found or not WhatsApp']);
    exit;
}
$metadata = json_decode($communication['metadata'] ?? '{}', true);
$mediaId = $metadata['media_id'] ?? null;

if (empty($mediaId)) {
    http_response_code(400);
    echo json_encode(['error' => 'This communication has no media']);
    exit;
}

try {
    $whatsappService = new WhatsAppService();
    $url = $whatsappService->getMediaUrl($mediaId);
    if ($url === null) {
        http_response_code(502);
        echo json_encode(['error' => 'Could not resolve media URL']);
        exit;
    }
    echo json_encode(['url' => $url]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error', 'message' => $e->getMessage()]);
}
