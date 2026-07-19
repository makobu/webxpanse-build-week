<?php
/**
 * WhatsApp Media Proxy
 * Fetches media from Meta's CDN server-side and streams it to the browser.
 * Fixes CORS/auth issues when embedding Meta URLs directly in img/video tags.
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

if (!Auth::check()) {
    http_response_code(401);
    header('Content-Type: text/plain');
    echo 'Authentication required';
    exit;
}

$workspaceId = (new WorkspaceScopeService())->requireActiveWorkspaceId();

$communicationId = isset($_GET['communication_id']) ? (int) $_GET['communication_id'] : 0;

if ($communicationId <= 0) {
    http_response_code(400);
    header('Content-Type: text/plain');
    echo 'communication_id is required';
    exit;
}

$communication = Database::queryOne(
    "SELECT id, channel, metadata FROM communications WHERE workspace_id = ? AND id = ?",
    [$workspaceId, $communicationId]
);

if (!$communication || ($communication['channel'] ?? '') !== 'whatsapp') {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo 'Communication not found or not WhatsApp';
    exit;
}

$metadata = json_decode($communication['metadata'] ?? '{}', true);
$mediaId = $metadata['media_id'] ?? null;
$messageType = $metadata['message_type'] ?? 'image';
$mimeType = $metadata['mime_type'] ?? null;

if (empty($mediaId)) {
    http_response_code(400);
    header('Content-Type: text/plain');
    echo 'This communication has no media';
    exit;
}

try {
    $whatsappService = new WhatsAppService();
    $mediaUrl = $whatsappService->getMediaUrl($mediaId);

    if ($mediaUrl === null) {
        http_response_code(502);
        header('Content-Type: text/plain');
        echo 'Could not resolve media URL';
        exit;
    }

    // Fetch media from Meta (server-side) - Meta URLs may require Bearer token
    $ch = curl_init($mediaUrl);
    $accessToken = $_ENV['WHATSAPP_ACCESS_TOKEN'] ?? '';
    $headers = [];
    if (!empty($accessToken)) {
        $headers[] = 'Authorization: Bearer ' . $accessToken;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADER => true,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    if ($response === false || $httpCode >= 400) {
        http_response_code(502);
        header('Content-Type: text/plain');
        echo 'Failed to fetch media';
        exit;
    }

    $headerBlock = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);

    // Determine Content-Type from response or metadata
    $contentType = $mimeType;
    if (empty($contentType) && preg_match('/Content-Type:\s*([^\s;]+)/i', $headerBlock, $m)) {
        $contentType = trim($m[1]);
    }
    if (empty($contentType)) {
        $contentType = match ($messageType) {
            'image' => 'image/jpeg',
            'sticker' => 'image/webp',
            'video' => 'video/mp4',
            'audio' => 'audio/mpeg',
            'document' => 'application/octet-stream',
            default => 'application/octet-stream',
        };
    }

    // Stream to browser
    header('Content-Type: ' . $contentType);
    header('Content-Length: ' . strlen($body));
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');

    if ($messageType === 'document') {
        header('Content-Disposition: attachment');
    }

    echo $body;
} catch (\Throwable $e) {
    error_log('WhatsApp media proxy error: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain');
    echo 'Server error';
}
