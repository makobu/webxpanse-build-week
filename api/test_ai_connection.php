<?php
/**
 * Test AI API Connection
 */

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING);
ob_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../vendor/autoload.php';

use CRM\Auth;
use CRM\Database;
use CRM\Security;
use CRM\Services\AIProviderProbeService;

try {
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
    ob_clean();

    Database::init(require __DIR__ . '/../config/database.php');

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!Auth::check()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Authentication required']);
        exit;
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Method not allowed']);
        exit;
    }

    $csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
    if (!Security::validateCSRF($csrfToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
        exit;
    }

    $probe = (new AIProviderProbeService())->probe(true);
    $statusCode = $probe['success'] ? 200 : 400;
    http_response_code($statusCode);

    echo json_encode([
        'success' => $probe['success'],
        'readiness' => (new AIProviderProbeService())->getReadiness(),
        'probe' => $probe,
        'message' => $probe['message'],
        'model' => $probe['provider']['model'] ?? '',
        'api_url' => $probe['provider']['normalized_api_url'] ?? '',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error: ' . $e->getMessage(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine(),
    ]);
}
