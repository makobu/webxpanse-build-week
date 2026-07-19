<?php
/**
 * Setup Test Environment
 * Creates test user and test contact for Playwright tests
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Load environment
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
use CRM\Authorization;

Database::init(require __DIR__ . '/../config/database.php');

$testEmail = 'test@crm.local';
$testPassword = 'test123456';

echo "Setting up test environment...\n\n";

// 1. Create test user
echo "1. Creating test user...\n";
$existing = Database::queryOne("SELECT id FROM users WHERE email = ?", [$testEmail]);
if ($existing) {
    echo "   Test user already exists: $testEmail\n";
} else {
    $uuid = sprintf(
        '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );

    $passwordHash = password_hash($testPassword, PASSWORD_DEFAULT);

    try {
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role) VALUES (?, ?, ?, 'admin')",
            [$uuid, $testEmail, $passwordHash]
        );
        $userId = (int) Database::lastInsertId();
        $rbacAssigned = Authorization::assignUserRoleBySlug($userId, 'superadmin', $userId);
        echo "   Test user created: $testEmail / $testPassword\n";
        echo "   Access profile: " . ($rbacAssigned ? 'superadmin' : 'legacy admin only') . "\n";
    } catch (\Exception $e) {
        echo "   Error creating user: " . $e->getMessage() . "\n";
    }
}

// 2. Create test contact
echo "\n2. Creating test contact...\n";
$contactEmail = 'testcontact@example.com';
$existingContact = Database::queryOne("SELECT id FROM contacts WHERE email = ?", [$contactEmail]);
if ($existingContact) {
    echo "   Test contact already exists: $contactEmail\n";
} else {
    try {
        Database::execute(
            "INSERT INTO contacts (first_name, last_name, email, phone, created_at) VALUES (?, ?, ?, ?, NOW())",
            ['Test', 'Contact', $contactEmail, '1234567890']
        );
        echo "   Test contact created: Test Contact ($contactEmail)\n";
    } catch (\Exception $e) {
        echo "   Error creating contact: " . $e->getMessage() . "\n";
    }
}

// 3. Check for email templates
echo "\n3. Checking email templates...\n";
$templates = Database::query("SELECT COUNT(*) as count FROM email_templates WHERE is_active = 1");
$templateCount = $templates[0]['count'] ?? 0;
if ($templateCount > 0) {
    echo "   Found $templateCount active email template(s)\n";
} else {
    echo "   No email templates found. Tests will still work but template tests may skip.\n";
}

// 4. Summary
echo "\n" . str_repeat("=", 50) . "\n";
echo "Test Environment Setup Complete!\n";
echo str_repeat("=", 50) . "\n";
echo "\nTest Credentials:\n";
echo "  Email: $testEmail\n";
echo "  Password: $testPassword\n";
echo "\nTo run Playwright tests:\n";
echo "  npm run test:email\n";
echo "  OR\n";
echo "  npx playwright test tests/email-ai-template.spec.js --headed\n";
echo "\n";
