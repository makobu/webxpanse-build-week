<?php
/**
 * Test Email Verification API
 * Tests the email verification functionality
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Load environment
$envFile = __DIR__ . '/../.env';
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

require_once __DIR__ . '/../config/constants.php';

use CRM\Database;
use CRM\Services\ThirdPartyEnrichmentService;

try {
    $dbConfig = require __DIR__ . '/../config/database.php';
    Database::init($dbConfig);
    
    echo "=== Email Verification Test ===\n\n";
    
    // Check database columns
    echo "1. Checking database columns...\n";
    $columns = Database::query("DESCRIBE contacts");
    $columnNames = array_column($columns, 'Field');
    
    $hasEmailVerified = in_array('email_verified', $columnNames);
    $hasEmailStatus = in_array('email_verification_status', $columnNames);
    
    echo "   - email_verified: " . ($hasEmailVerified ? "✓ EXISTS" : "✗ MISSING") . "\n";
    echo "   - email_verification_status: " . ($hasEmailStatus ? "✓ EXISTS" : "✗ MISSING") . "\n\n";
    
    if (!$hasEmailVerified || !$hasEmailStatus) {
        echo "⚠ WARNING: Required columns are missing. Run migration 048_create_enrichment_tables.sql\n\n";
    }
    
    // Check API key
    echo "2. Checking Hunter.io API key...\n";
    $apiKey = $_ENV['HUNTER_API_KEY'] ?? null;
    if ($apiKey) {
        echo "   - HUNTER_API_KEY: ✓ CONFIGURED (length: " . strlen($apiKey) . ")\n";
    } else {
        echo "   - HUNTER_API_KEY: ✗ NOT CONFIGURED\n";
        echo "   ⚠ WARNING: Add HUNTER_API_KEY to your .env file\n\n";
        exit(1);
    }
    
    // Test email verification service
    echo "\n3. Testing email verification service...\n";
    $testEmail = $argv[1] ?? 'test@example.com';
    echo "   - Testing with email: $testEmail\n";
    
    $service = new ThirdPartyEnrichmentService();
    $result = $service->verifyEmail($testEmail);
    
    if ($result['status'] === 'success') {
        echo "   - Status: ✓ SUCCESS\n";
        echo "   - Email Verified: " . ($result['email_verified'] ? 'YES' : 'NO') . "\n";
        echo "   - Verification Status: " . ($result['email_verification_status'] ?? 'N/A') . "\n";
        echo "   - Confidence Score: " . ($result['confidence_score'] ?? 'N/A') . "\n";
        echo "   - SMTP Check: " . ($result['smtp_check'] ?? 'N/A') . "\n";
        echo "   - Disposable: " . ($result['disposable'] ? 'YES' : 'NO') . "\n";
        echo "   - Webmail: " . ($result['webmail'] ? 'YES' : 'NO') . "\n";
    } else {
        echo "   - Status: ✗ ERROR\n";
        echo "   - Error: " . ($result['message'] ?? 'Unknown error') . "\n";
    }
    
    echo "\n=== Test Complete ===\n";
    
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    exit(1);
}
