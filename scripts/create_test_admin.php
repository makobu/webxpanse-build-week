<?php
/**
 * Create Test Admin User (Non-interactive)
 *
 * Creates a default admin user for testing
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

$email = 'admin@crm.local';
$password = 'Admin123!@#$';

// Check if user already exists
$existing = Database::queryOne("SELECT id FROM users WHERE email = ?", [$email]);
if ($existing) {
    echo "Admin user already exists: $email\n";
    echo "Password: $password\n";
    exit(0);
}

// Generate UUID
$uuid = sprintf(
    '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
    mt_rand(0, 0xffff), mt_rand(0, 0xffff),
    mt_rand(0, 0xffff),
    mt_rand(0, 0x0fff) | 0x4000,
    mt_rand(0, 0x3fff) | 0x8000,
    mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
);

// Hash password
$passwordHash = password_hash($password, PASSWORD_DEFAULT);

// Create user
try {
    Database::execute(
        "INSERT INTO users (uuid, email, password_hash, role) VALUES (?, ?, ?, 'admin')",
        [$uuid, $email, $passwordHash]
    );
    $userId = (int) Database::lastInsertId();
    $rbacAssigned = Authorization::assignUserRoleBySlug($userId, 'superadmin', $userId);

    echo "Test admin user created successfully.\n";
    echo "Email: $email\n";
    echo "Password: $password\n";
    echo "Role: admin (RBAC: " . ($rbacAssigned ? 'superadmin' : 'not assigned') . ")\n";
    echo "\nYou can now login at: http://localhost/crm/public/login.php\n";
} catch (\Exception $e) {
    echo "Error creating user: " . $e->getMessage() . "\n";
    exit(1);
}
