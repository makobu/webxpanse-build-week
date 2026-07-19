<?php
/**
 * SMTP Test API Endpoint
 * Tests SMTP connection and sends a test email
 */

// Start output buffering to catch any unwanted output
ob_start();

// Suppress any error output that might be HTML
ini_set('display_errors', 0);
error_reporting(E_ALL);

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

use CRM\Auth;
use CRM\Session;
use CRM\Database;
use CRM\Services\EmailProviderDiagnosticService;
use CRM\Services\EmailIntegrationService;
use CRM\Services\PlatformEmailDefaultService;
use CRM\Services\SMTPClient;

// Clear any output that might have been generated
ob_clean();

// Set JSON header
header('Content-Type: application/json');

try {
    // Initialize database and session
    $dbConfig = require __DIR__ . '/../config/database.php';
    Database::init($dbConfig);
    Session::start();
    
    // Require authentication - return JSON instead of redirecting
    if (!Auth::check()) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized', 'message' => 'Please log in to test SMTP settings']);
        exit;
    }
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Initialization error', 'message' => $e->getMessage()]);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method === 'POST') {
        $testEmail = $_POST['test_email'] ?? $_POST['email'] ?? '';
        $action = $_POST['action'] ?? 'send';
        
        if (empty($testEmail) || !filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            echo json_encode(['error' => 'Valid test email address required']);
            exit;
        }
        
        $smtpClient = new SMTPClient();
        $integrationService = new EmailIntegrationService();
        $diagnosticService = new EmailProviderDiagnosticService();
        $providerSummary = $integrationService->getMainProviderSummary();
        $systemSmtp = (new PlatformEmailDefaultService())->smtpConfig(EmailIntegrationService::SCOPE_MAIN_EMAIL);
        $isSmtpConfigured = !empty($providerSummary['smtp_fallback_configured']);
        $hasPHPMailer = class_exists('PHPMailer\PHPMailer\PHPMailer');
        $preferredProviderLabel = (string) ($providerSummary['provider_label'] ?? 'Manual SMTP / IMAP');
        $legacyEnvSettings = [
            'host' => $_ENV['SMTP_HOST'] ?? '',
            'port' => $_ENV['SMTP_PORT'] ?? '',
            'username_configured' => !empty($_ENV['SMTP_USER']),
            'from_email' => $_ENV['SMTP_FROM_EMAIL'] ?? '',
            'from_name' => $_ENV['SMTP_FROM_NAME'] ?? '',
            'imap_host' => $_ENV['IMAP_HOST'] ?? '',
            'imap_username_configured' => !empty($_ENV['IMAP_USER']),
            'ignored' => true,
            'reason' => 'Legacy .env SMTP keys are not used for System Mail readiness. Configure Settings > Email > System Mail.',
        ];
        $legacyAssistantEnvSettings = [
            'system_email' => $_ENV['EMAIL_ASSISTANT_SYSTEM_EMAIL'] ?? '',
            'smtp_host' => $_ENV['EMAIL_ASSISTANT_SMTP_HOST'] ?? '',
            'smtp_username_configured' => !empty($_ENV['EMAIL_ASSISTANT_SMTP_USER']),
            'from_email' => $_ENV['EMAIL_ASSISTANT_FROM_EMAIL'] ?? '',
            'imap_host' => $_ENV['EMAIL_ASSISTANT_IMAP_HOST'] ?? '',
            'imap_username_configured' => !empty($_ENV['EMAIL_ASSISTANT_IMAP_USER']),
            'ignored' => true,
            'reason' => 'Legacy .env Email Assistant mailbox keys are not used for System Mail or workspace Email plugin readiness. Configure Marketplace > Email Assistant.',
        ];

        $results = [
            'method_used' => null,
            'active_provider_key' => $providerSummary['provider_key'] ?? 'smtp',
            'active_provider_label' => $preferredProviderLabel,
            'connected_email' => $providerSummary['connected_email'] ?? '',
            'smtp_configured' => $isSmtpConfigured,
            'provider_summary' => $providerSummary,
            'phpmailer_available' => $hasPHPMailer,
            'connection' => false,
            'authentication' => false,
            'send' => false,
            'errors' => [],
            'settings' => [
                'source' => 'platform_email_defaults.main_email',
                'legacy_env_ignored' => true,
            ],
            'legacy_env_settings' => $legacyEnvSettings,
            'legacy_assistant_env_settings' => $legacyAssistantEnvSettings,
        ];
        
        if ($isSmtpConfigured && !$hasPHPMailer && $action === 'send') {
            try {
                $socket = @fsockopen(
                    trim((string) ($systemSmtp['host'] ?? '')) ?: 'localhost',
                    (int) (($systemSmtp['port'] ?? 0) ?: 587),
                    $errno,
                    $errstr,
                    10
                );
                
                if ($socket) {
                    $results['connection'] = true;
                    fclose($socket);
                } else {
                    $results['errors'][] = "Connection failed: $errstr ($errno)";
                }
            } catch (\Exception $e) {
                $results['errors'][] = "Connection error: " . $e->getMessage();
            }
        } elseif ($hasPHPMailer) {
            $results['connection'] = true; // PHPMailer handles connection
        }
        
        // Test full email send
        if ($action === 'send') {
            try {
                $fromEmail = $smtpClient->getPreferredFromEmail('test@example.com') ?? 'test@example.com';
                $fromName = $smtpClient->getPreferredFromName('CRM Test') ?? 'CRM Test';
                $subject = 'CRM System Mail Test';
                $body = '<html><body>
                    <h2>System Mail Test</h2>
                    <p>This is a test email from your CRM system.</p>
                    <p>If you received this email, System Mail is configured correctly for workspace invites and system notifications.</p>
                    <hr>
                    <p style="color: #666; font-size: 12px;">
                        Sent at: ' . date('Y-m-d H:i:s') . '<br>
                        From: ' . htmlspecialchars($fromEmail) . '<br>
                        Preferred Provider: ' . htmlspecialchars($preferredProviderLabel) . '
                    </p>
                </body></html>';
                
                $success = $smtpClient->send(
                    $testEmail,
                    $fromEmail,
                    $fromName,
                    $subject,
                    $body
                );
                
                if ($success) {
                    $actualMethod = (string) ($smtpClient->getLastMethodUsed() ?? '');
                    $results['method_used'] = $actualMethod !== '' ? $actualMethod : ($providerSummary['provider_key'] ?? 'unknown');
                    $results['provider_key'] = $smtpClient->getLastProviderKey() ?? ($providerSummary['provider_key'] ?? 'unknown');
                    $results['provider_label'] = $smtpClient->getLastProviderLabel() ?? $preferredProviderLabel;
                    $results['send'] = true;
                    if (!$results['authentication']) {
                        $results['authentication'] = true;
                    }
                    $response = json_encode([
                        'success' => true,
                        'message' => 'System Mail test email sent successfully using ' . ($results['method_used'] ?: $preferredProviderLabel) . '!',
                        'results' => $results
                    ], JSON_PRETTY_PRINT);
                } else {
                    $technicalError = 'Failed to send email (unknown error)';
                    $results['errors'][] = $technicalError;
                    $diagnostic = $diagnosticService->diagnose($technicalError, $providerSummary);
                    $response = json_encode([
                        'success' => false,
                        'message' => $diagnostic['diagnostic_title'],
                        'error' => $technicalError,
                        'results' => $results,
                    ] + $diagnostic, JSON_PRETTY_PRINT);
                }
            } catch (\Exception $e) {
                $results['errors'][] = 'Send error: ' . $e->getMessage();
                
                // Check if it's an authentication error
                if (strpos($e->getMessage(), 'authentication') !== false || 
                    strpos($e->getMessage(), 'AUTH') !== false ||
                    strpos($e->getMessage(), '235') !== false ||
                    strpos($e->getMessage(), '535') !== false) {
                    $results['authentication'] = false;
                }
                $results['method_used'] = (string) ($smtpClient->getLastMethodUsed() ?? '');
                $diagnostic = $diagnosticService->diagnose($e->getMessage(), $providerSummary);
                
                $response = json_encode([
                    'success' => false,
                    'message' => $diagnostic['diagnostic_title'],
                    'error' => $e->getMessage(),
                    'results' => $results
                ] + $diagnostic, JSON_PRETTY_PRINT);
            }
        } else {
            // Just return configuration info
            $response = json_encode([
                'success' => true,
                'message' => "Active System Mail provider: {$preferredProviderLabel}",
                'results' => $results,
                'provider_summary' => $providerSummary
            ], JSON_PRETTY_PRINT);
        }
    } else {
        // GET request - return current SMTP settings (without sensitive data)
        $settings = [
            'source' => 'platform_email_defaults.main_email',
            'legacy_env_ignored' => true,
        ];
        
        $response = json_encode([
            'settings' => $settings,
            'provider_summary' => (new EmailIntegrationService())->getMainProviderSummary(),
            'legacy_env_settings' => [
                'host' => $_ENV['SMTP_HOST'] ?? '',
                'port' => $_ENV['SMTP_PORT'] ?? '',
                'username_configured' => !empty($_ENV['SMTP_USER']),
                'from_email' => $_ENV['SMTP_FROM_EMAIL'] ?? '',
                'from_name' => $_ENV['SMTP_FROM_NAME'] ?? '',
                'imap_host' => $_ENV['IMAP_HOST'] ?? '',
                'imap_username_configured' => !empty($_ENV['IMAP_USER']),
                'ignored' => true,
                'reason' => 'Legacy .env SMTP keys are not used for System Mail readiness. Configure Settings > Email > System Mail.',
            ],
            'legacy_assistant_env_settings' => [
                'system_email' => $_ENV['EMAIL_ASSISTANT_SYSTEM_EMAIL'] ?? '',
                'smtp_host' => $_ENV['EMAIL_ASSISTANT_SMTP_HOST'] ?? '',
                'smtp_username_configured' => !empty($_ENV['EMAIL_ASSISTANT_SMTP_USER']),
                'from_email' => $_ENV['EMAIL_ASSISTANT_FROM_EMAIL'] ?? '',
                'imap_host' => $_ENV['EMAIL_ASSISTANT_IMAP_HOST'] ?? '',
                'imap_username_configured' => !empty($_ENV['EMAIL_ASSISTANT_IMAP_USER']),
                'ignored' => true,
                'reason' => 'Legacy .env Email Assistant mailbox keys are not used for System Mail or workspace Email plugin readiness. Configure Marketplace > Email Assistant.',
            ],
            'instructions' => 'Send a POST request with "test_email" parameter to test SMTP connection'
        ], JSON_PRETTY_PRINT);
    }
    
    // Clear output buffer and send JSON response
    ob_end_clean();
    echo $response;
    exit;
} catch (\Exception $e) {
    ob_end_clean();
    http_response_code(500);
    echo json_encode([
        'error' => 'Server error',
        'message' => $e->getMessage(),
        'trace' => function_exists('crmPublicDebugEnabled') && crmPublicDebugEnabled() ? $e->getTraceAsString() : null
    ]);
    exit;
}
