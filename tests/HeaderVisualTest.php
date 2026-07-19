<?php
/**
 * Header Visual Test
 * Uses Playwright to test header aesthetics and functionality
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/constants.php';

use CRM\Database;
use CRM\Auth;

// Initialize database
Database::init(require __DIR__ . '/../config/database.php');

// Start session
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Create a test user if needed
$testEmail = 'test@crm.local';
$testPassword = 'test123456';

try {
    // Check if test user exists
    $user = Database::query("SELECT * FROM users WHERE email = ?", [$testEmail]);
    
    if (empty($user)) {
        // Create test user
        $hashedPassword = password_hash($testPassword, PASSWORD_DEFAULT);
        Database::execute(
            "INSERT INTO users (email, password_hash, role, created_at) VALUES (?, ?, 'admin', NOW())",
            [$testEmail, $hashedPassword]
        );
    }
    
    echo "Test user ready: {$testEmail} / {$testPassword}\n";
    echo "Base URL: http://localhost" . publicUrl('dashboard.php') . "\n";
    echo "\nTo run Playwright tests:\n";
    echo "1. Install Playwright: npm install -g playwright\n";
    echo "2. Install browsers: playwright install\n";
    echo "3. Run: playwright test tests/header-visual.spec.js\n";
    
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
