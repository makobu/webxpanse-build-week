<?php
/**
 * System Mail test CLI script.
 *
 * Tests the platform_email_defaults.main_email SMTP provider.
 * Usage: php cli/test_smtp.php [test_email@example.com]
 */

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        if (strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/constants.php';

use CRM\Database;
use CRM\Services\EmailIntegrationService;
use CRM\Services\PlatformEmailDefaultService;
use CRM\Services\SMTPClient;

Database::init(require __DIR__ . '/../config/database.php');

echo "========================================\n";
echo "System Mail Configuration Test\n";
echo "========================================\n\n";

$systemSmtp = (new PlatformEmailDefaultService())->smtpConfig(EmailIntegrationService::SCOPE_MAIN_EMAIL);

echo "System Mail Settings (platform_email_defaults.main_email):\n";
echo "  Host: " . (($systemSmtp['host'] ?? '') ?: 'not set') . "\n";
echo "  Port: " . (($systemSmtp['port'] ?? '') ?: 'not set') . "\n";
echo "  Username: " . (($systemSmtp['username'] ?? '') ?: 'not set') . "\n";
echo "  Password: " . (!empty($systemSmtp['password']) ? '***' : 'not set') . "\n";
echo "  Encryption: " . (($systemSmtp['encryption'] ?? '') ?: 'not set') . "\n";
echo "  From Email: " . (($systemSmtp['from_email'] ?? '') ?: 'not set') . "\n";
echo "  From Name: " . (($systemSmtp['from_name'] ?? '') ?: 'not set') . "\n\n";

echo "Legacy .env SMTP values are ignored by System Mail readiness:\n";
echo "  Host: " . ($_ENV['SMTP_HOST'] ?? 'not set') . "\n";
echo "  Port: " . ($_ENV['SMTP_PORT'] ?? 'not set') . "\n";
echo "  Username: " . (!empty($_ENV['SMTP_USER']) ? 'configured' : 'not set') . "\n";
echo "  From Email: " . ($_ENV['SMTP_FROM_EMAIL'] ?? 'not set') . "\n\n";

$missing = [];
if (trim((string) ($systemSmtp['host'] ?? '')) === '') {
    $missing[] = 'System Mail SMTP host';
}
if (trim((string) ($systemSmtp['username'] ?? '')) === '') {
    $missing[] = 'System Mail SMTP username';
}
if ((string) ($systemSmtp['password'] ?? '') === '') {
    $missing[] = 'System Mail SMTP password';
}
if ($missing !== []) {
    echo "ERROR: Missing required settings: " . implode(', ', $missing) . "\n";
    echo "Please configure Settings > Email > System Mail.\n";
    exit(1);
}

echo "1. Testing SMTP connection...\n";
$host = (string) ($systemSmtp['host'] ?? '');
$port = (int) (($systemSmtp['port'] ?? 0) ?: 587);
$socket = @fsockopen($host, $port, $errno, $errstr, 10);
if (!$socket) {
    echo "Connection failed: {$errstr} ({$errno})\n";
    exit(1);
}
echo "Connection successful\n";
fclose($socket);

$testEmail = $argv[1] ?? null;
if (!$testEmail) {
    echo "\n2. Enter test email address: ";
    $testEmail = trim((string) fgets(STDIN));
}
if ($testEmail === '' || !filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
    echo "Invalid email address\n";
    exit(1);
}

echo "\n2. Sending System Mail test email to: {$testEmail}\n";

try {
    $smtpClient = new SMTPClient();
    $fromEmail = $smtpClient->getPreferredFromEmail('test@example.com') ?? 'test@example.com';
    $fromName = $smtpClient->getPreferredFromName('CRM System') ?? 'CRM System';
    $subject = 'CRM System Mail Test Email';
    $body = '<html><body>
        <h2>System Mail Test Email</h2>
        <p>This is a test email from your CRM System Mail provider.</p>
        <p>If you received this email, System Mail is configured correctly.</p>
        <hr>
        <p style="color:#666;font-size:12px;">
            Sent at: ' . date('Y-m-d H:i:s') . '<br>
            From: ' . htmlspecialchars($fromEmail) . '<br>
            SMTP Host: ' . htmlspecialchars($host) . '<br>
            SMTP Port: ' . $port . '
        </p>
    </body></html>';

    $smtpClient->send($testEmail, $fromEmail, $fromName, $subject, $body);
    echo "\nSUCCESS: System Mail test email sent.\n";
} catch (\Throwable $e) {
    echo "\nERROR: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n";
