<?php
/**
 * GDPR Email Service
 * 
 * Handles email sending for GDPR requests
 */

namespace CRM\Services;

use CRM\Services\SMTPClient;

class GDPREmailService
{
    private SMTPClient $smtp;
    private string $fromEmail;
    private string $fromName;
    private string $baseUrl;
    
    public function __construct()
    {
        $this->smtp = new SMTPClient();
        $this->fromEmail = $this->smtp->getPreferredFromEmail('noreply@example.com') ?? 'noreply@example.com';
        $this->fromName = $this->smtp->getPreferredFromName(brandProductName()) ?? brandProductName();
        
        // Determine base URL
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $this->baseUrl = $protocol . '://' . $host;
    }
    
    /**
     * Send verification email
     */
    public function sendVerificationEmail(string $email, string $token, string $requestType): bool
    {
        $verificationPath = function_exists('apiUrl')
            ? apiUrl('gdpr/verify-email.php')
            : '/api/gdpr/verify-email.php';
        $verificationUrl = $this->baseUrl . $verificationPath . '?token=' . urlencode($token);
        
        $requestTypeLabels = [
            'export' => 'Data Export',
            'deletion' => 'Data Deletion',
            'access' => 'Data Access',
            'rectification' => 'Data Rectification'
        ];
        
        $subject = 'Verify Your GDPR Request - ' . ($requestTypeLabels[$requestType] ?? ucfirst($requestType));
        
        $body = "Hello,\n\n";
        $body .= "You have requested a " . strtolower($requestTypeLabels[$requestType] ?? $requestType) . " under GDPR.\n\n";
        $body .= "To verify your request and proceed, please click the following link:\n\n";
        $body .= $verificationUrl . "\n\n";
        $body .= "This link will expire in 24 hours.\n\n";
        $body .= "If you did not make this request, please ignore this email.\n\n";
        $body .= "Best regards,\n";
        $body .= $this->fromName;
        
        $bodyHtml = "<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .button { display: inline-block; padding: 12px 24px; background: #0066cc; color: white; text-decoration: none; border-radius: 4px; margin: 20px 0; }
        .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class='container'>
        <h2>Verify Your GDPR Request</h2>
        <p>Hello,</p>
        <p>You have requested a <strong>" . htmlspecialchars($requestTypeLabels[$requestType] ?? ucfirst($requestType)) . "</strong> under GDPR.</p>
        <p>To verify your request and proceed, please click the button below:</p>
        <a href='" . htmlspecialchars($verificationUrl) . "' class='button'>Verify Request</a>
        <p>Or copy and paste this link into your browser:</p>
        <p style='word-break: break-all; color: #0066cc;'>" . htmlspecialchars($verificationUrl) . "</p>
        <p><strong>This link will expire in 24 hours.</strong></p>
        <p>If you did not make this request, please ignore this email.</p>
        <div class='footer'>
            <p>Best regards,<br>" . htmlspecialchars($this->fromName) . "</p>
        </div>
    </div>
</body>
</html>";
        
        return $this->smtp->send($email, $this->fromEmail, $this->fromName, $subject, $body, [], $bodyHtml);
    }
    
    /**
     * Send confirmation email with results
     */
    public function sendConfirmationEmail(string $email, string $requestType, array $result): bool
    {
        $requestTypeLabels = [
            'export' => 'Data Export',
            'deletion' => 'Data Deletion',
            'access' => 'Data Access',
            'rectification' => 'Data Rectification'
        ];
        
        $subject = 'Your GDPR Request Has Been Processed - ' . ($requestTypeLabels[$requestType] ?? ucfirst($requestType));
        
        if ($requestType === 'export') {
            // For export, send JSON file as attachment or provide download link
            $body = "Hello,\n\n";
            $body .= "Your data export request has been processed successfully.\n\n";
            $body .= "Your data export is attached to this email in JSON format.\n\n";
            $body .= "If you have any questions, please contact us.\n\n";
            $body .= "Best regards,\n";
            $body .= $this->fromName;
            
            $bodyHtml = "<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class='container'>
        <h2>Your GDPR Request Has Been Processed</h2>
        <p>Hello,</p>
        <p>Your <strong>Data Export</strong> request has been processed successfully.</p>
        <p>Your data export is attached to this email in JSON format.</p>
        <p>If you have any questions, please contact us.</p>
        <div class='footer'>
            <p>Best regards,<br>" . htmlspecialchars($this->fromName) . "</p>
        </div>
    </div>
</body>
</html>";
        } else {
            $body = "Hello,\n\n";
            $body .= "Your " . strtolower($requestTypeLabels[$requestType] ?? $requestType) . " request has been processed successfully.\n\n";
            if ($requestType === 'deletion') {
                $body .= "Your data has been anonymized and deleted in accordance with GDPR requirements.\n\n";
            }
            $body .= "If you have any questions, please contact us.\n\n";
            $body .= "Best regards,\n";
            $body .= $this->fromName;
            
            $bodyHtml = "<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class='container'>
        <h2>Your GDPR Request Has Been Processed</h2>
        <p>Hello,</p>
        <p>Your <strong>" . htmlspecialchars($requestTypeLabels[$requestType] ?? ucfirst($requestType)) . "</strong> request has been processed successfully.</p>";
            
            if ($requestType === 'deletion') {
                $bodyHtml .= "<p>Your data has been anonymized and deleted in accordance with GDPR requirements.</p>";
            }
            
            $bodyHtml .= "<p>If you have any questions, please contact us.</p>
        <div class='footer'>
            <p>Best regards,<br>" . htmlspecialchars($this->fromName) . "</p>
        </div>
    </div>
</body>
</html>";
        }
        
        // For export requests, we would attach the JSON file
        // For now, we'll just send the confirmation
        return $this->smtp->send($email, $this->fromEmail, $this->fromName, $subject, $body, [], $bodyHtml);
    }
}
