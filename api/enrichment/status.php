<?php
/**
 * Enrichment Status API Endpoint
 */

require_once __DIR__ . '/../../vendor/autoload.php';

// Load environment
$envFile = __DIR__ . '/../../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ((substr($value, 0, 1) === '"' && substr($value, -1) === '"') || 
            (substr($value, 0, 1) === "'" && substr($value, -1) === "'")) {
            $value = substr($value, 1, -1);
        }
        $_ENV[$key] = $value;
    }
}

require_once __DIR__ . '/../../config/constants.php';

use CRM\Auth;
use CRM\Database;
use CRM\Services\WorkspaceScopeService;

header('Content-Type: application/json');
Auth::requireAuth();

try {
    $dbConfig = require __DIR__ . '/../../config/database.php';
    Database::init($dbConfig);
    $workspaceId = (new WorkspaceScopeService())->requireActiveWorkspaceId();
    
    $contactId = (int) ($_GET['contact_id'] ?? 0);
    
    if (!$contactId) {
        http_response_code(400);
        echo json_encode(['error' => 'contact_id is required']);
        exit;
    }
    
    // Get contact enrichment info
    $contact = Database::queryOne(
        "SELECT enrichment_score, enrichment_confidence, last_enriched_at FROM contacts WHERE workspace_id = ? AND id = ?",
        [$workspaceId, $contactId]
    );
    
    if (!$contact) {
        http_response_code(404);
        echo json_encode(['error' => 'Contact not found']);
        exit;
    }
    
    // Get enrichment history
    $history = Database::query(
        "SELECT enrichment_type, fields_updated, status, created_at, cost 
         FROM enrichment_history 
         WHERE contact_id = ? 
         ORDER BY created_at DESC 
         LIMIT 10",
        [$contactId]
    );
    
    // Get enrichment sources
    $sources = Database::query(
        "SELECT source_type, source_url, confidence_score, created_at 
         FROM enrichment_sources 
         WHERE contact_id = ? 
         ORDER BY created_at DESC 
         LIMIT 10",
        [$contactId]
    );
    
    echo json_encode([
        'contact_id' => $contactId,
        'enrichment_score' => (int) ($contact['enrichment_score'] ?? 0),
        'enrichment_confidence' => (float) ($contact['enrichment_confidence'] ?? 0.0),
        'last_enriched_at' => $contact['last_enriched_at'],
        'history' => $history,
        'sources' => $sources
    ], JSON_PRETTY_PRINT);
    
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}
