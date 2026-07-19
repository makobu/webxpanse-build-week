<?php
declare(strict_types=1);

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

use CRM\Database;
use CRM\Modules\ReadinessCaptureSettings;
use CRM\Services\MarketingAssessmentIntakeService;

$startedAt = microtime(true);
$responseStatus = 200;

header('Content-Type: application/json; charset=utf-8');

$dbConfig = require __DIR__ . '/../../config/database.php';
Database::init($dbConfig);

$connector = new ReadinessCaptureSettings();
$connectorAuth = null;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

[$incomingSecret, $secretHeaderName] = extractReadinessCaptureSecret();
if ($incomingSecret === '') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Capture secret required.']);
    exit;
}

$connectorAuth = $connector->authenticate($incomingSecret);
if ($connectorAuth === null) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Readiness capture is disabled or the secret is invalid.']);
    exit;
}

try {
    $payload = json_decode(file_get_contents('php://input') ?: '{}', true);
    if (!is_array($payload)) {
        $payload = [];
    }

    if (empty($payload['source'])) {
        $payload['source'] = (string) ($connectorAuth['source_label'] ?? ReadinessCaptureSettings::DEFAULT_SOURCE);
    }

    $service = new MarketingAssessmentIntakeService();
    $result = $service->ingest(
        $payload,
        (int) ($connectorAuth['updated_by_user_id'] ?? $connectorAuth['created_by_user_id'] ?? 0)
    );

    $responseStatus = 200;
    http_response_code($responseStatus);
    echo json_encode($result);
} catch (InvalidArgumentException $e) {
    $responseStatus = 422;
    http_response_code($responseStatus);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    $responseStatus = 500;
    error_log('assessment_capture failed: ' . $e->getMessage());
    http_response_code($responseStatus);
    echo json_encode(['success' => false, 'message' => 'Unable to capture assessment right now.']);
} finally {
    if ($connectorAuth !== null) {
        error_log(sprintf(
            'assessment_capture request connector=%s header=%s status=%d duration_ms=%d ip=%s',
            (string) ($connectorAuth['connector_key'] ?? ReadinessCaptureSettings::CONNECTOR_KEY),
            $secretHeaderName,
            $responseStatus,
            (int) round((microtime(true) - $startedAt) * 1000),
            $_SERVER['REMOTE_ADDR'] ?? 'unknown'
        ));
    }
}

function extractReadinessCaptureSecret(): array
{
    $headers = [];
    if (function_exists('getallheaders')) {
        $headers = getallheaders() ?: [];
    }

    $candidates = [
        ['name' => 'X-Readiness-Capture-Secret', 'value' => $headers['X-Readiness-Capture-Secret'] ?? null],
        ['name' => 'x-readiness-capture-secret', 'value' => $headers['x-readiness-capture-secret'] ?? null],
        ['name' => 'HTTP_X_READINESS_CAPTURE_SECRET', 'value' => $_SERVER['HTTP_X_READINESS_CAPTURE_SECRET'] ?? null],
    ];

    $authorization = $headers['Authorization'] ?? $headers['authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? null);
    if (is_string($authorization) && preg_match('/Bearer\s+(.+)/i', $authorization, $matches)) {
        $candidates[] = ['name' => 'Authorization', 'value' => trim($matches[1])];
    }

    foreach ($candidates as $candidate) {
        if (is_string($candidate['value'] ?? null) && trim((string) $candidate['value']) !== '') {
            return [trim((string) $candidate['value']), (string) ($candidate['name'] ?? 'unknown')];
        }
    }

    return ['', 'missing'];
}
