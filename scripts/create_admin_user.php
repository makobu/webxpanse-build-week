<?php
/**
 * Create Admin User Script
 *
 * Run this script to create the first admin user
 * Usage: php scripts/create_admin_user.php
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

echo "CRM Admin User Creation\n";
echo "======================\n\n";

// Check if users already exist
$existingUsers = Database::query("SELECT COUNT(*) as count FROM users");
$userCount = $existingUsers[0]['count'] ?? 0;

if ($userCount > 0) {
    echo "Users already exist in the database.\n";
    echo "Do you want to create another admin user? (y/n): ";
    $handle = fopen("php://stdin", "r");
    $line = fgets($handle);
    if (trim($line) !== 'y') {
        echo "Cancelled.\n";
        exit;
    }
}

// Get user details
echo "Enter admin email: ";
$handle = fopen("php://stdin", "r");
$email = trim(fgets($handle));

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo "Invalid email address.\n";
    exit(1);
}

// Check if email already exists
$existing = Database::queryOne("SELECT id FROM users WHERE email = ?", [$email]);
if ($existing) {
    echo "User with this email already exists.\n";
    exit(1);
}

echo "Enter password (min 12 characters): ";
$password = trim(fgets($handle));

if (strlen($password) < 12) {
    echo "Password must be at least 12 characters.\n";
    exit(1);
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

    echo "\nAdmin user created successfully.\n";
    echo "Email: $email\n";
    echo "Role: admin (RBAC: " . ($rbacAssigned ? 'superadmin' : 'not assigned') . ")\n";
    echo "\nYou can now login at: http://localhost/crm/public/login.php\n";
} catch (\Exception $e) {
    echo "\nError creating user: " . $e->getMessage() . "\n";
    exit(1);
}
