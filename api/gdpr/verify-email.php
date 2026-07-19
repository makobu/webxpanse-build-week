<?php
/**
 * GDPR Email Verification Endpoint
 * Verifies email and processes GDPR request
 */

require_once __DIR__ . '/../../vendor/autoload.php';

// Load environment
$envFile = __DIR__ . '/../../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../../config/constants.php';

use CRM\Database;
use CRM\Modules\GDPR;

Database::init(require __DIR__ . '/../../config/database.php');

header('Content-Type: text/html; charset=utf-8');

$token = $_GET['token'] ?? '';

if (empty($token)) {
    http_response_code(400);
    echo "<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <title>Invalid Request</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 600px; margin: 50px auto; padding: 20px; }
        .error { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 15px; border-radius: 4px; }
    </style>
</head>
<body>
    <div class='error'>
        <h2>Invalid Request</h2>
        <p>No verification token provided.</p>
    </div>
</body>
</html>";
    exit;
}

try {
    $gdpr = new GDPR();
    $result = $gdpr->verifyAndProcess($token);
    
    $requestType = $result['request_type'];
    $requestTypeLabels = [
        'export' => 'Data Export',
        'deletion' => 'Data Deletion',
        'access' => 'Data Access',
        'rectification' => 'Data Rectification'
    ];
    
    $title = $requestTypeLabels[$requestType] ?? ucfirst($requestType);
    
    echo "<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <title>Request Verified</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 600px; margin: 50px auto; padding: 20px; line-height: 1.6; }
        .success { background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 20px; border-radius: 4px; margin-bottom: 20px; }
        .info { background: #d1ecf1; border: 1px solid #bee5eb; color: #0c5460; padding: 15px; border-radius: 4px; margin-top: 20px; }
    </style>
</head>
<body>
    <div class='success'>
        <h2>Request Verified Successfully</h2>
        <p>Your <strong>{$title}</strong> request has been verified and is being processed.</p>";
    
    if ($requestType === 'export') {
        echo "<p>You will receive an email shortly with your data export attached in JSON format.</p>";
    } elseif ($requestType === 'deletion') {
        echo "<p>Your data has been anonymized and deleted in accordance with GDPR requirements. You will receive a confirmation email shortly.</p>";
    } else {
        echo "<p>You will receive an email shortly with the requested information.</p>";
    }
    
    echo "</div>
    <div class='info'>
        <p><strong>What happens next?</strong></p>
        <ul>
            <li>Your request has been logged and is being processed</li>
            <li>You will receive a confirmation email with the results</li>
            <li>For export requests, your data will be sent as a JSON file attachment</li>
        </ul>
        <p>If you have any questions, please contact us at <a href='mailto:" . htmlspecialchars($_ENV['PRIVACY_CONTACT_EMAIL'] ?? 'privacy@example.com') . "'>" . htmlspecialchars($_ENV['PRIVACY_CONTACT_EMAIL'] ?? 'privacy@example.com') . "</a></p>
    </div>
</body>
</html>";
    
} catch (\Exception $e) {
    http_response_code(400);
    echo "<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <title>Verification Failed</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 600px; margin: 50px auto; padding: 20px; }
        .error { background: #f8d7da; border: 1px solid #f5c6cb; color: #721c24; padding: 20px; border-radius: 4px; }
    </style>
</head>
<body>
    <div class='error'>
        <h2>Verification Failed</h2>
        <p>" . htmlspecialchars($e->getMessage()) . "</p>
        <p><a href='" . (function_exists('publicUrl') ? publicUrl('gdpr-request.php') : '/gdpr-request.php') . "'>Submit a new request</a></p>
    </div>
</body>
</html>";
}
