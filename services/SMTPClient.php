<?php
/**
 * SMTP Client
 * 
 * Direct SMTP implementation using PHP sockets
 */

namespace CRM\Services;

class SMTPClient
{
    private string $host;
    private int $port;
    private string $username;
    private string $password;
    private string $encryption;
    private bool $isSmtpConfigured;
    private ?string $profile = null;
    private ?string $lastMethodUsed = null;
    private ?string $lastProviderKey = null;
    private ?string $lastProviderLabel = null;
    private ?EmailIntegrationService $emailIntegrationService = null;
    private ?GmailMailService $gmailMailService = null;
    private ?GoogleWorkspaceMailService $googleWorkspaceMailService = null;
    private $socket = null;
    
    /**
     * @param string|null $profile 'assistant', 'outreach', or 'nurture'; null/default for System Mail SMTP
     */
    public function __construct(?string $profile = null)
    {
        $this->profile = $this->normalizeProfile($profile);
        $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        $this->emailIntegrationService = new EmailIntegrationService();

        if ($this->profile === 'assistant') {
            $assistantService = new WorkspaceAssistantConfigService();
            $workspaceAssistantConfig = $workspaceId > 0 ? $assistantService->get($workspaceId, 'email', true) : [];
            $workspaceAssistantSmtp = $workspaceAssistantConfig !== [] ? $assistantService->emailSmtpConfig($workspaceId) : [];
            $resolvedSmtp = $workspaceAssistantSmtp;
            $this->host = trim((string) ($resolvedSmtp['host'] ?? ''));
            $this->port = (int) (($resolvedSmtp['port'] ?? 0) ?: 587);
            $this->username = trim((string) ($resolvedSmtp['username'] ?? ''));
            $this->password = (string) ($resolvedSmtp['password'] ?? '');
            $envEncryption = strtolower(trim((string) ($resolvedSmtp['encryption'] ?? '')));
            if ($envEncryption === 'ssl' || $this->port === 465) {
                $this->encryption = 'ssl';
            } elseif ($envEncryption === 'tls' || $this->port === 587) {
                $this->encryption = 'tls';
            } else {
                $this->encryption = $envEncryption ?: 'tls';
            }
        } else {
            if ($this->profile === 'nurture') {
                $workspaceSmtp = $this->emailIntegrationService->getStrictManualSmtpConfigForRole('nurture', $workspaceId > 0 ? $workspaceId : null);
            } else {
                $workspaceSmtp = $this->profile !== null
                    ? $this->emailIntegrationService->getManualSmtpConfigForRole($this->profile, $workspaceId > 0 ? $workspaceId : null)
                    : $this->emailIntegrationService->getManualSmtpConfig($workspaceId > 0 ? $workspaceId : null);
            }
            $this->host = trim((string) ($workspaceSmtp['host'] ?? ''));
            $this->port = (int) (($workspaceSmtp['port'] ?? 0) ?: 587);
            $this->username = trim((string) ($workspaceSmtp['username'] ?? ''));
            $this->password = (string) ($workspaceSmtp['password'] ?? '');
            $envEncryption = strtolower((string) ($workspaceSmtp['encryption'] ?? ''));
            if ($envEncryption === 'ssl' || $this->port === 465) {
                $this->encryption = 'ssl';
            } elseif ($envEncryption === 'tls' || $this->port === 587) {
                $this->encryption = 'tls';
            } else {
                $this->encryption = $envEncryption ?: 'tls';
            }
        }

        // Check if SMTP is configured (has host and username)
        $this->isSmtpConfigured = !empty($this->host) && !empty($this->username) && !empty($this->password);
        $this->gmailMailService = new GmailMailService();
        $this->googleWorkspaceMailService = new GoogleWorkspaceMailService();
        if ($this->shouldLogConfiguration()) {
            $prefix = $this->profile === 'assistant' ? 'SMTP Assistant' : 'SMTP';
            error_log("{$prefix} Config - Host: " . ($this->host ?: 'not set') . ", Port: {$this->port}, User: " . ($this->redactForLog($this->username) ?: 'not set') . ", Pass set: " . (!empty($this->password) ? 'yes' : 'no'));
        }
    }

    /**
     * Get configured From email for assistant profile (or null if not assistant)
     */
    public function getFromEmail(): ?string
    {
        if ($this->profile !== 'assistant') {
            return null;
        }
        $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        if ($workspaceId > 0) {
            $workspaceSmtp = (new WorkspaceAssistantConfigService())->emailSmtpConfig($workspaceId);
            if (!empty($workspaceSmtp['from_email'])) {
                return (string) $workspaceSmtp['from_email'];
            }
        }
        return 'noreply@example.com';
    }

    /**
     * Get configured From name for assistant profile (or null if not assistant)
     */
    public function getFromName(): ?string
    {
        if ($this->profile !== 'assistant') {
            return null;
        }
        $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        if ($workspaceId > 0) {
            $workspaceSmtp = (new WorkspaceAssistantConfigService())->emailSmtpConfig($workspaceId);
            if (!empty($workspaceSmtp['from_name'])) {
                return (string) $workspaceSmtp['from_name'];
            }
        }
        return 'Personal Assistant';
    }

    public function getProfileKey(): string
    {
        return $this->profile ?: 'default';
    }

    public function getLastMethodUsed(): ?string
    {
        return $this->lastMethodUsed;
    }

    public function getLastProviderKey(): ?string
    {
        return $this->lastProviderKey;
    }

    public function getLastProviderLabel(): ?string
    {
        return $this->lastProviderLabel;
    }

    public function getPreferredFromEmail(?string $fallback = null): ?string
    {
        if ($this->profile === 'assistant') {
            return $this->getFromEmail();
        }

        if ($this->emailIntegrationService) {
            if ($this->profile !== null) {
                if ($this->profile === 'nurture') {
                    return $this->emailIntegrationService->getStrictPreferredFromEmailForRole('nurture');
                }
                return $this->emailIntegrationService->getPreferredFromEmailForRole($this->profile, $fallback);
            }
            return $this->emailIntegrationService->getPreferredMainFromEmail($fallback);
        }

        return $fallback;
    }

    public function getPreferredFromName(?string $fallback = null): ?string
    {
        if ($this->profile === 'assistant') {
            return $this->getFromName();
        }

        if ($this->emailIntegrationService) {
            if ($this->profile !== null) {
                if ($this->profile === 'nurture') {
                    return $this->emailIntegrationService->getStrictPreferredFromNameForRole('nurture', $fallback);
                }
                return $this->emailIntegrationService->getPreferredFromNameForRole($this->profile, $fallback);
            }
            return $this->emailIntegrationService->getPreferredMainFromName($fallback);
        }

        return $fallback;
    }

    public function getActiveProviderKey(): string
    {
        if ($this->profile === 'assistant') {
            $integration = $this->emailIntegrationService->getActiveAssistantIntegration();
            if ($integration) {
                return (string) ($integration['provider'] ?? EmailIntegrationService::PROVIDER_GMAIL_OAUTH);
            }
            return 'assistant_smtp';
        }

        if ($this->emailIntegrationService) {
            $integration = $this->profile !== null
                ? ($this->profile === 'nurture'
                    ? $this->emailIntegrationService->getActiveStrictRoleIntegration($this->profile)
                    : $this->emailIntegrationService->getActiveRoleIntegration($this->profile))
                : null;
            if ($integration) {
                return (string) ($integration['provider'] ?? EmailIntegrationService::PROVIDER_MANUAL_SMTP);
            }
        }

        return EmailIntegrationService::PROVIDER_MANUAL_SMTP;
    }

    public function getActiveProviderLabel(): string
    {
        if ($this->profile === 'assistant') {
            $summary = $this->emailIntegrationService->getAssistantProviderSummary();
            if (!empty($summary['is_active'])) {
                return (string) ($summary['provider_label'] ?? 'Assistant Gmail');
            }
            return 'Assistant SMTP';
        }

        if ($this->emailIntegrationService) {
            if ($this->profile === 'nurture') {
                $integration = $this->emailIntegrationService->getActiveStrictRoleIntegration('nurture');
                if ($integration) {
                    return $this->emailIntegrationService->providerLabel((string) ($integration['provider'] ?? EmailIntegrationService::PROVIDER_MANUAL_SMTP));
                }

                return 'Manual SMTP / IMAP';
            }
            $summary = $this->profile !== null
                ? $this->emailIntegrationService->getProviderSummaryForRole($this->profile)
                : $this->emailIntegrationService->getMainProviderSummary();
            return (string) ($summary['provider_label'] ?? 'Manual SMTP / IMAP');
        }

        return 'Manual SMTP / IMAP';
    }
    
    /**
     * Send email via PHPMailer, SMTP, or PHP mail()
     * 
     * Priority order:
     * 1. PHPMailer (if available and SMTP configured)
     * 2. Custom SMTP (if SMTP configured)
     * 3. PHP mail() (only when explicitly enabled)
     * 4. Throw error (if SMTP configured but all methods failed)
     * 
     * @param string $to Recipient email
     * @param string $from Sender email
     * @param string $fromName Sender name
     * @param string $subject Email subject
     * @param string $body Email body (plain text)
     * @param array $attachments Optional array of attachment file paths
     * @param string|null $bodyHtml Optional HTML version of email body
     * @return bool
     */
    public function send(string $to, string $from, string $fromName, string $subject, string $body, array $attachments = [], ?string $bodyHtml = null): bool
    {
        return $this->sendInternal($to, $from, $fromName, $subject, $body, $attachments, $bodyHtml, []);
    }

    /**
     * Send email with custom headers (e.g. In-Reply-To, References for threading)
     *
     * @param array $customHeaders Associative array of header name => value
     */
    public function sendWithHeaders(string $to, string $from, string $fromName, string $subject, string $body, array $attachments = [], ?string $bodyHtml = null, array $customHeaders = []): bool
    {
        return $this->sendInternal($to, $from, $fromName, $subject, $body, $attachments, $bodyHtml, $customHeaders);
    }

    /**
     * Shared send strategy used by send() and sendWithHeaders()
     */
    private function sendInternal(string $to, string $from, string $fromName, string $subject, string $body, array $attachments = [], ?string $bodyHtml = null, array $customHeaders = []): bool
    {
        $this->lastMethodUsed = null;
        $this->lastProviderKey = null;
        $this->lastProviderLabel = null;
        $mailSent = false;
        $errors = [];
        $validated = $this->validateEnvelopeAndHeaders($to, $from, $fromName, $subject, $customHeaders);
        $to = $validated['to'];
        $from = $validated['from'];
        $fromName = $validated['from_name'];
        $subject = $validated['subject'];
        $customHeaders = $validated['headers'];

        if ($this->emailIntegrationService) {
            $oauthIntegration = null;
            try {
                $oauthIntegration = match ($this->profile) {
                    'assistant' => $this->emailIntegrationService->getAuthorizedAssistantIntegration(),
                    'nurture' => $this->emailIntegrationService->getAuthorizedStrictRoleIntegration($this->profile),
                    'outreach' => $this->emailIntegrationService->getAuthorizedRoleIntegration($this->profile),
                    default => null,
                };
                if ($oauthIntegration) {
                    $providerKey = (string) ($oauthIntegration['provider'] ?? '');
                    $mailService = $this->mailServiceForProvider($providerKey);
                    $mailService->sendMessage(
                        (string) ($oauthIntegration['access_token'] ?? ''),
                        [
                            'to' => $to,
                            'from_email' => $from,
                            'from_name' => $fromName,
                            'subject' => $subject,
                            'body_text' => $body,
                            'body_html' => $bodyHtml,
                            'attachments' => $attachments,
                            'headers' => $customHeaders,
                            'authenticated_email' => (string) ($oauthIntegration['email_address'] ?? ''),
                        ]
                    );
                    $this->lastMethodUsed = 'gmail_api';
                    $this->lastProviderKey = $providerKey;
                    $this->lastProviderLabel = $this->emailIntegrationService->providerLabel($providerKey);
                    $this->emailIntegrationService->recordProviderSuccess(
                        (int) ($oauthIntegration['id'] ?? 0),
                        $providerKey,
                        'gmail_api',
                        (int) ($oauthIntegration['workspace_id'] ?? 0)
                    );
                    return true;
                }
            } catch (\Throwable $e) {
                if (!empty($oauthIntegration['id'])) {
                    $this->emailIntegrationService->recordProviderFailure(
                        (int) $oauthIntegration['id'],
                        $e->getMessage(),
                        (int) ($oauthIntegration['workspace_id'] ?? 0)
                    );
                }
                $providerLabel = !empty($oauthIntegration['provider'])
                    ? $this->emailIntegrationService->providerLabel((string) $oauthIntegration['provider'])
                    : 'OAuth mailbox';
                $errors[] = $providerLabel . ' failed: ' . $e->getMessage();
                error_log($providerLabel . ' Mail Error: ' . $e->getMessage());
            }
        }
        
        // If SMTP is configured, try SMTP methods first
        if ($this->isSmtpConfigured) {
            // Try PHPMailer first if available (recommended for SMTP)
            if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
                try {
                    error_log("Attempting to send email via PHPMailer");
                    $result = $this->sendViaPHPMailer($to, $from, $fromName, $subject, $body, $attachments, $bodyHtml, $customHeaders);
                    $this->lastMethodUsed = 'phpmailer';
                    $this->lastProviderKey = EmailIntegrationService::PROVIDER_MANUAL_SMTP;
                    $this->lastProviderLabel = 'Manual SMTP';
                    return $result;
                } catch (\Exception $e) {
                    $errors[] = "PHPMailer failed: " . $e->getMessage();
                    error_log("PHPMailer Error: " . $e->getMessage());
                }
            } else {
                error_log("PHPMailer not available, using custom SMTP");
            }
            
            // Try custom SMTP implementation
            try {
                error_log("Attempting to send email via custom SMTP");
                error_log("SMTP Details - Host: {$this->host}, Port: {$this->port}, Encryption: {$this->encryption}, User: " . $this->redactForLog($this->username));
                $this->connect();
                error_log("SMTP connection established");
                $this->authenticate();
                error_log("SMTP authentication successful");
                $this->sendMail($from, $to, $fromName, $subject, $body, $attachments, $bodyHtml, $customHeaders);
                error_log("Email sent successfully via custom SMTP");
                $this->disconnect();
                $mailSent = true;
                $this->lastMethodUsed = 'custom_smtp';
                $this->lastProviderKey = EmailIntegrationService::PROVIDER_MANUAL_SMTP;
                $this->lastProviderLabel = 'Manual SMTP';
            } catch (\Exception $e) {
                if ($this->socket) {
                    $this->disconnect();
                }
                $errors[] = "Custom SMTP failed: " . $e->getMessage();
                error_log("SMTP Error: " . $e->getMessage());
            }
        }

        // Workspace-scoped profiles enforce explicit configuration and do not use PHP mail() fallback.
        if ($this->profile !== null) {
            if ($mailSent) {
                return true;
            }

            if (!$this->isSmtpConfigured) {
                $profileLabel = match ($this->profile) {
                    'assistant' => 'Assistant',
                    'nurture' => 'Nurture Email',
                    'outreach' => 'Outreach Email',
                    default => 'Workspace email',
                };
                throw new \RuntimeException(
                    $profileLabel . " SMTP is not fully configured. Save this mailbox in its Marketplace setup."
                );
            }

            $errorMsg = ucfirst($this->profile) . " email send failed via SMTP methods. ";
            if (!empty($errors)) {
                $errorMsg .= "Errors: " . implode("; ", $errors) . ". ";
            }
            $errorMsg .= "Verify the saved SMTP credentials and encryption settings.";
            throw new \RuntimeException($errorMsg);
        }

        // Default profile: fallback to PHP mail() only when explicitly enabled.
        if (!$mailSent) {
            if (!$this->isPhpMailFallbackAllowed()) {
                $errorMsg = $this->isSmtpConfigured
                    ? "Failed to send email. SMTP methods failed. "
                    : "Failed to send email. No configured mail provider is available. ";
                if (!empty($errors)) {
                    $errorMsg .= "Errors: " . implode("; ", $errors) . ". ";
                }
                $errorMsg .= "Configure Settings > Email > System Mail or enable ALLOW_PHP_MAIL_FALLBACK explicitly.";
                throw new \RuntimeException($errorMsg);
            }

            try {
                error_log("Attempting to send email via PHP mail() fallback");
                $result = $this->sendViaPhpMail($to, $from, $fromName, $subject, $body, $attachments, $bodyHtml, $customHeaders);
                $this->lastMethodUsed = 'php_mail';
                $this->lastProviderKey = EmailIntegrationService::PROVIDER_MANUAL_SMTP;
                $this->lastProviderLabel = 'Manual SMTP';
                return $result;
            } catch (\Exception $e) {
                error_log("PHP mail() Error: " . $e->getMessage());
                // If SMTP was configured but failed, provide helpful error message
                if ($this->isSmtpConfigured) {
                    $errorMsg = "Failed to send email. SMTP methods failed and PHP mail() also failed. ";
                    $errorMsg .= "SMTP Errors: " . implode("; ", $errors) . ". ";
                    $errorMsg .= "Please verify the workspace or platform SMTP credentials.";
                    throw new \RuntimeException($errorMsg);
                } else {
                    throw new \RuntimeException("Failed to send email via PHP mail(): " . $e->getMessage() . ". Please configure Settings > Email > System Mail.");
                }
            }
        }
        
        return $mailSent;
    }

    private function mailServiceForProvider(string $provider): GoogleOAuthMailService
    {
        if ($this->profile === 'assistant' && $provider === EmailIntegrationService::PROVIDER_GMAIL_OAUTH) {
            return new AssistantGmailMailService();
        }

        return match ($provider) {
            EmailIntegrationService::PROVIDER_GMAIL_OAUTH => $this->gmailMailService ?? new GmailMailService(),
            EmailIntegrationService::PROVIDER_GOOGLE_WORKSPACE => $this->googleWorkspaceMailService ?? new GoogleWorkspaceMailService(),
            default => throw new \InvalidArgumentException('Unsupported email provider: ' . $provider),
        };
    }

    private function normalizeProfile(?string $profile): ?string
    {
        return match (strtolower(trim((string) $profile))) {
            'assistant', 'email_assistant', 'assistant_email' => 'assistant',
            'outreach', 'outbound', 'sales', 'outreach_email' => 'outreach',
            'nurture', 'followup', 'follow_up', 'nurture_email' => 'nurture',
            default => null,
        };
    }

    private function shouldLogConfiguration(): bool
    {
        if (in_array(strtolower((string) ($_ENV['DISABLE_SMTP_CONFIG_LOGS'] ?? 'false')), ['1', 'true', 'yes', 'on'], true)) {
            return false;
        }

        return filter_var($_ENV['SMTP_DEBUG_LOG_CONFIG'] ?? false, FILTER_VALIDATE_BOOL)
            || filter_var($_ENV['MAIL_DEBUG_LOG_CONFIG'] ?? false, FILTER_VALIDATE_BOOL)
            || filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL)
            || filter_var($_ENV['DEBUG'] ?? false, FILTER_VALIDATE_BOOL)
            || strtolower((string) ($_ENV['APP_ENV'] ?? 'production')) === 'development';
    }

    private function isPhpMailFallbackAllowed(): bool
    {
        return filter_var($_ENV['ALLOW_PHP_MAIL_FALLBACK'] ?? false, FILTER_VALIDATE_BOOL)
            || filter_var($_ENV['MAIL_ALLOW_PHP_MAIL_FALLBACK'] ?? false, FILTER_VALIDATE_BOOL);
    }

    private function allowInsecureMailTls(): bool
    {
        return filter_var($_ENV['ALLOW_INSECURE_MAIL_TLS'] ?? false, FILTER_VALIDATE_BOOL)
            || filter_var($_ENV['MAIL_ALLOW_INSECURE_TLS'] ?? false, FILTER_VALIDATE_BOOL);
    }

    /**
     * @return array{to:string,from:string,from_name:string,subject:string,headers:array<string,string>}
     */
    private function validateEnvelopeAndHeaders(string $to, string $from, string $fromName, string $subject, array $customHeaders): array
    {
        $to = $this->sanitizeEmailAddress($to, 'recipient');
        $from = $this->sanitizeEmailAddress($from, 'sender');
        $fromName = $this->sanitizeHeaderValue($fromName, 'from name');
        $subject = $this->sanitizeHeaderValue($subject, 'subject');

        $headers = [];
        foreach ($customHeaders as $name => $value) {
            $name = trim((string) $name);
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9-]{0,127}$/', $name)) {
                throw new \InvalidArgumentException('Invalid email header name.');
            }
            $headers[$name] = $this->sanitizeHeaderValue((string) $value, 'header ' . $name);
        }

        return [
            'to' => $to,
            'from' => $from,
            'from_name' => $fromName,
            'subject' => $subject,
            'headers' => $headers,
        ];
    }

    private function sanitizeEmailAddress(string $email, string $label): string
    {
        $email = trim($email);
        $this->rejectHeaderInjection($email, $label);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException("Invalid {$label} email address.");
        }

        return $email;
    }

    private function sanitizeHeaderValue(string $value, string $label): string
    {
        $value = trim($value);
        $this->rejectHeaderInjection($value, $label);
        return $value;
    }

    private function rejectHeaderInjection(string $value, string $label): void
    {
        if (str_contains($value, "\r") || str_contains($value, "\n") || str_contains($value, "\0")) {
            throw new \InvalidArgumentException("Invalid {$label}: header injection characters are not allowed.");
        }
    }

    private function redactForLog(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (strpos($value, '@') !== false) {
            [$local, $domain] = array_pad(explode('@', $value, 2), 2, '');
            return substr($local, 0, 1) . '***@' . $domain;
        }

        return substr($value, 0, 1) . '***';
    }
    
    /**
     * Send email using PHPMailer (if available)
     */
    private function sendViaPHPMailer(string $to, string $from, string $fromName, string $subject, string $body, array $attachments = [], ?string $bodyHtml = null, array $customHeaders = []): bool
    {
        if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
            throw new \RuntimeException("PHPMailer class not found. Please run 'composer install'.");
        }
        
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        
        try {
            // Server settings - matching working code pattern exactly
            $mail->isSMTP();
            $mail->Host = $this->host;
            $mail->SMTPAuth = true;
            $mail->Username = $this->username;
            $mail->Password = $this->password;
            $allowInsecureTls = $this->allowInsecureMailTls();
            $mail->SMTPOptions = [
                'ssl' => [
                    'verify_peer' => !$allowInsecureTls,
                    'verify_peer_name' => !$allowInsecureTls,
                    'allow_self_signed' => $allowInsecureTls,
                ],
            ];
            $mail->SMTPAutoTLS = $this->encryption !== 'none';
            
            // For port 465, use ENCRYPTION_SMTPS (matching working code)
            if ($this->port == 465) {
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($this->port == 587) {
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                // Use encryption setting from env
                if ($this->encryption === 'ssl') {
                    $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
                } elseif ($this->encryption === 'tls') {
                    $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                }
            }
            
            $mail->Port = $this->port;
            $mail->CharSet = 'UTF-8';
            
            // Recipients - matching working code
            $mail->setFrom($from, $fromName);
            $mail->addAddress($to);
            
            // Content - multipart/alternative support
            $mail->isHTML(true);
            $mail->Subject = $subject;
            
            // If HTML version provided, use it; otherwise convert plain text to HTML
            if ($bodyHtml) {
                $mail->Body = $bodyHtml;
                $mail->AltBody = $body;
            } else {
                // Convert plain text to HTML if it contains HTML tags
                if ($body !== strip_tags($body)) {
                    // Already HTML
            $mail->Body = $body;
            $mail->AltBody = strip_tags($body);
                } else {
                    // Plain text - convert to HTML and use as both
                    $mail->Body = $this->convertPlainTextToHtml($body);
                    $mail->AltBody = $body;
                }
            }
            
            // Add attachments
            foreach ($attachments as $filePath) {
                if (file_exists($filePath)) {
                    $mail->addAttachment($filePath);
                }
            }

            // Add custom headers (e.g. In-Reply-To, References)
            foreach ($customHeaders as $name => $value) {
                if (strcasecmp((string) $name, 'Message-ID') === 0) {
                    $mail->MessageID = (string) $value;
                    continue;
                }
                $mail->addCustomHeader($name, $value);
            }
            
            $mail->send();
            error_log("PHPMailer: Email sent successfully (multipart/alternative)");
            return true;
        } catch (\Exception $e) {
            $errorInfo = $mail->ErrorInfo ?? $e->getMessage();
            error_log("PHPMailer Error: $errorInfo");
            throw new \RuntimeException("PHPMailer Error: $errorInfo");
        }
    }
    
    /**
     * Send email using PHP mail() function (fallback)
     */
    private function sendViaPhpMail(string $to, string $from, string $fromName, string $subject, string $body, array $attachments = [], ?string $bodyHtml = null, array $customHeaders = []): bool
    {
        // Build headers for PHP mail()
        $headers = [];
        $headers[] = "MIME-Version: 1.0";
        $headers[] = "From: $fromName <$from>";
        $headers[] = "Reply-To: $from";
        $headers[] = "X-Mailer: PHP/" . phpversion();
        foreach ($customHeaders as $name => $value) {
            $headers[] = "$name: $value";
        }
        
        // Determine if we need multipart/alternative (both plain text and HTML)
        $hasHtml = false;
        $htmlContent = $bodyHtml;
        
        if ($htmlContent) {
            $hasHtml = true;
        } elseif ($body !== strip_tags($body)) {
            // Body contains HTML tags
            $htmlContent = $body;
            $hasHtml = true;
        } else {
            // Plain text only - convert to HTML for tracking
            $htmlContent = $this->convertPlainTextToHtml($body);
            $hasHtml = true;
        }
        
        // Build multipart message
        $boundary = uniqid('boundary_');
        $messageBody = '';
        
        if (!empty($attachments)) {
            // Multipart/mixed (attachments + multipart/alternative)
            $mixedBoundary = uniqid('mixed_');
            $headers[] = "Content-Type: multipart/mixed; boundary=\"$mixedBoundary\"";
            
            // Add multipart/alternative part
            $messageBody .= "--$mixedBoundary\r\n";
            $messageBody .= "Content-Type: multipart/alternative; boundary=\"$boundary\"\r\n\r\n";
            
            // Add plain text part
            $messageBody .= "--$boundary\r\n";
            $messageBody .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $messageBody .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
            $messageBody .= $body . "\r\n\r\n";
            
            // Add HTML part
            $messageBody .= "--$boundary\r\n";
            $messageBody .= "Content-Type: text/html; charset=UTF-8\r\n";
            $messageBody .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
            $messageBody .= $htmlContent . "\r\n\r\n";
            $messageBody .= "--$boundary--\r\n\r\n";
            
            // Add attachments
            foreach ($attachments as $filePath) {
                if (!file_exists($filePath)) {
                    continue;
                }
                
                $fileName = basename($filePath);
                $fileContent = file_get_contents($filePath);
                $fileContentEncoded = chunk_split(base64_encode($fileContent));
                $mimeType = $this->getMimeType($filePath);
                
                $messageBody .= "--$mixedBoundary\r\n";
                $messageBody .= "Content-Type: $mimeType; name=\"$fileName\"\r\n";
                $messageBody .= "Content-Disposition: attachment; filename=\"$fileName\"\r\n";
                $messageBody .= "Content-Transfer-Encoding: base64\r\n\r\n";
                $messageBody .= $fileContentEncoded . "\r\n";
            }
            
            $messageBody .= "--$mixedBoundary--\r\n";
        } else {
            // Multipart/alternative only (no attachments)
            $headers[] = "Content-Type: multipart/alternative; boundary=\"$boundary\"";
            
            // Add plain text part
            $messageBody .= "--$boundary\r\n";
            $messageBody .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $messageBody .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
            $messageBody .= $body . "\r\n\r\n";
            
            // Add HTML part
            $messageBody .= "--$boundary\r\n";
            $messageBody .= "Content-Type: text/html; charset=UTF-8\r\n";
            $messageBody .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
            $messageBody .= $htmlContent . "\r\n\r\n";
            $messageBody .= "--$boundary--\r\n";
        }
        
        $headersString = implode("\r\n", $headers);
        
        // Send using PHP mail()
        $result = @mail($to, $subject, $messageBody, $headersString);
        
        if (!$result) {
            $error = error_get_last();
            $errorMsg = $error ? $error['message'] : 'Unknown error';
            throw new \RuntimeException("PHP mail() function failed: $errorMsg");
        }
        
        return true;
    }
    
    /**
     * Connect to SMTP server
     */
    private function connect(): void
    {
        // For SSL connections (port 465), use ssl:// wrapper
        if ($this->encryption === 'ssl' || $this->port === 465) {
            $allowInsecureTls = $this->allowInsecureMailTls();
            $context = stream_context_create([
                'ssl' => [
                    'verify_peer' => !$allowInsecureTls,
                    'verify_peer_name' => !$allowInsecureTls,
                    'allow_self_signed' => $allowInsecureTls,
                    'SNI_enabled' => true,
                    'peer_name' => $this->host,
                ]
            ]);
            
            $this->socket = @stream_socket_client(
                "ssl://{$this->host}:{$this->port}",
                $errno,
                $errstr,
                30,
                STREAM_CLIENT_CONNECT,
                $context
            );
            
            if (!$this->socket) {
                throw new \RuntimeException("SMTP SSL Connection failed: $errstr ($errno)");
            }
            
            // Note: ssl:// wrapper already encrypts, no need to enable crypto again
        } else {
            // For TLS or no encryption, use regular socket
            $allowInsecureTls = $this->allowInsecureMailTls();
            $context = stream_context_create([
                'ssl' => [
                    'verify_peer' => !$allowInsecureTls,
                    'verify_peer_name' => !$allowInsecureTls,
                    'allow_self_signed' => $allowInsecureTls,
                    'SNI_enabled' => true,
                    'peer_name' => $this->host,
                ],
            ]);
            $this->socket = @stream_socket_client(
                "tcp://{$this->host}:{$this->port}",
                $errno,
                $errstr,
                30,
                STREAM_CLIENT_CONNECT,
                $context
            );
            
            if (!$this->socket) {
                throw new \RuntimeException("SMTP Connection failed: $errstr ($errno)");
            }
        }
        
        $response = $this->readResponse();
        if (substr($response, 0, 3) !== '220') {
            throw new \RuntimeException("SMTP Connection error: $response");
        }
    }
    
    /**
     * Authenticate with SMTP server
     */
    private function authenticate(): void
    {
        $isSSL = ($this->encryption === 'ssl' || $this->port === 465);
        
        // EHLO - use SMTP host or server name, not localhost
        $ehloHost = $_SERVER['SERVER_NAME'] ?? $_SERVER['HTTP_HOST'] ?? $this->host ?? 'localhost';
        // Remove port if present
        $ehloHost = preg_replace('/:\d+$/', '', $ehloHost);
        // Use domain from SMTP host if localhost
        if ($ehloHost === 'localhost' || $ehloHost === '127.0.0.1') {
            // Extract domain from SMTP host (e.g., mail.webxpanse.com -> webxpanse.com)
            $parts = explode('.', $this->host);
            if (count($parts) >= 2) {
                $ehloHost = implode('.', array_slice($parts, -2)); // Get last 2 parts
            } else {
                $ehloHost = $this->host;
            }
        }
        $this->sendCommand("EHLO " . $ehloHost);
        $response = $this->readResponse();
        
        $startTlsAdvertised = stripos($response, 'STARTTLS') !== false;
        if (!$isSSL && $this->encryption === 'tls' && !$startTlsAdvertised) {
            throw new \RuntimeException('SMTP server did not advertise STARTTLS while TLS encryption is configured.');
        }

        // For SSL connections (port 465), connection is already encrypted, skip STARTTLS.
        if (!$isSSL && $startTlsAdvertised) {
            $this->sendCommand("STARTTLS");
            $response = $this->readResponse();
            
            if (substr($response, 0, 3) !== '220') {
                throw new \RuntimeException("STARTTLS failed: $response");
            }
            
            $cryptoEnabled = stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($cryptoEnabled !== true) {
                throw new \RuntimeException('STARTTLS crypto negotiation failed.');
            }
            
            // EHLO again after TLS
            $ehloHost = $_SERVER['SERVER_NAME'] ?? $_SERVER['HTTP_HOST'] ?? $this->host ?? 'localhost';
            $ehloHost = preg_replace('/:\d+$/', '', $ehloHost);
            if ($ehloHost === 'localhost' || $ehloHost === '127.0.0.1') {
                $parts = explode('.', $this->host);
                if (count($parts) >= 2) {
                    $ehloHost = implode('.', array_slice($parts, -2));
                } else {
                    $ehloHost = $this->host;
                }
            }
            $this->sendCommand("EHLO " . $ehloHost);
            $this->readResponse();
        }
        
        // AUTH LOGIN or AUTH PLAIN
        if (!empty($this->username)) {
            error_log("Starting SMTP authentication - User: " . $this->redactForLog($this->username));
            
            // Try AUTH PLAIN first (some servers prefer this, especially for SSL connections)
            try {
                error_log("Trying AUTH PLAIN first");
                $authString = base64_encode("\0" . $this->username . "\0" . $this->password);
                $this->sendCommand("AUTH PLAIN " . $authString);
                $response = $this->readResponse();
                error_log("AUTH PLAIN response: $response");
                
                if (substr($response, 0, 3) === '235') {
                    error_log("AUTH PLAIN successful");
                    return; // Success with PLAIN
                }
                error_log("AUTH PLAIN failed, trying AUTH LOGIN");
            } catch (\Exception $e) {
                error_log("AUTH PLAIN exception: " . $e->getMessage() . ", trying AUTH LOGIN");
            }
            
            // Try AUTH LOGIN
            $this->sendCommand("AUTH LOGIN");
            $response = $this->readResponse();
            error_log("AUTH LOGIN response: $response");
            
            if (substr($response, 0, 3) !== '334') {
                throw new \RuntimeException("AUTH LOGIN not supported and AUTH PLAIN failed. Server response: $response");
            }
            
            // Continue with LOGIN
            error_log("Sending SMTP username");
            $this->sendCommand(base64_encode($this->username));
            $response = $this->readResponse();
            error_log("Username response: $response");
            
            if (substr($response, 0, 3) !== '334') {
                throw new \RuntimeException("Username authentication failed: $response");
            }
            
            error_log("Sending SMTP password");
            $this->sendCommand(base64_encode($this->password));
            $response = $this->readResponse();
            error_log("Password response: $response");
            
            if (substr($response, 0, 3) !== '235') {
                throw new \RuntimeException("Password authentication failed: $response. Please verify the saved SMTP password is correct.");
            }
            error_log("SMTP authentication successful");
        }
    }
    
    /**
     * Send email
     */
    private function sendMail(string $from, string $to, string $fromName, string $subject, string $body, array $attachments = [], ?string $bodyHtml = null, array $customHeaders = []): void
    {
        // MAIL FROM
        $this->sendCommand("MAIL FROM:<$from>");
        $response = $this->readResponse();
        if (substr($response, 0, 3) !== '250') {
            throw new \RuntimeException("MAIL FROM failed: $response");
        }
        
        // RCPT TO
        $this->sendCommand("RCPT TO:<$to>");
        $response = $this->readResponse();
        if (substr($response, 0, 3) !== '250') {
            throw new \RuntimeException("RCPT TO failed: $response");
        }
        
        // DATA
        $this->sendCommand("DATA");
        $response = $this->readResponse();
        if (substr($response, 0, 3) !== '354') {
            throw new \RuntimeException("DATA command failed: $response");
        }
        
        // Build email
        $message = $this->buildEmail($from, $to, $fromName, $subject, $body, $attachments, $bodyHtml, $customHeaders);
        fwrite($this->socket, $message . "\r\n.\r\n");
        
        $response = $this->readResponse();
        if (substr($response, 0, 3) !== '250') {
            throw new \RuntimeException("Email send failed: $response");
        }
    }
    
    /**
     * Build email message with multipart/alternative support and optional attachments
     */
    private function buildEmail(string $from, string $to, string $fromName, string $subject, string $body, array $attachments = [], ?string $bodyHtml = null, array $customHeaders = []): string
    {
        $altBoundary = uniqid('alt_');
        $hasAttachments = !empty($attachments);
        
        // Determine HTML content
        $htmlContent = $bodyHtml;
        if (!$htmlContent) {
            if ($body !== strip_tags($body)) {
                // Body contains HTML tags
                $htmlContent = $body;
            } else {
                // Plain text - convert to HTML for tracking
                $htmlContent = $this->convertPlainTextToHtml($body);
            }
        }
        
        $headers = [
            "From: $fromName <$from>",
            "To: <$to>",
            "Subject: $subject",
            "MIME-Version: 1.0",
            "Date: " . date('r')
        ];
        foreach ($customHeaders as $name => $value) {
            $headers[] = "$name: $value";
        }
        
        $message = implode("\r\n", $headers) . "\r\n\r\n";
        
        if ($hasAttachments) {
            // Multipart/mixed (attachments + multipart/alternative)
            $mixedBoundary = uniqid('mixed_');
            $headers[] = "Content-Type: multipart/mixed; boundary=\"$mixedBoundary\"";
            
            // Rebuild headers with multipart/mixed
            $message = implode("\r\n", $headers) . "\r\n\r\n";
            
            // Add multipart/alternative part
            $message .= "--$mixedBoundary\r\n";
            $message .= "Content-Type: multipart/alternative; boundary=\"$altBoundary\"\r\n\r\n";
            
            // Add plain text part
            $message .= "--$altBoundary\r\n";
            $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $message .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
            $message .= $body . "\r\n\r\n";
            
            // Add HTML part
            $message .= "--$altBoundary\r\n";
            $message .= "Content-Type: text/html; charset=UTF-8\r\n";
            $message .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
            $message .= $htmlContent . "\r\n\r\n";
            $message .= "--$altBoundary--\r\n\r\n";
            
            // Add attachments
            foreach ($attachments as $filePath) {
                if (!file_exists($filePath)) {
                    continue; // Skip missing files
                }
                
                $fileName = basename($filePath);
                $fileContent = file_get_contents($filePath);
                $fileContentEncoded = chunk_split(base64_encode($fileContent));
                
                // Detect MIME type
                $mimeType = $this->getMimeType($filePath);
                
                $message .= "--$mixedBoundary\r\n";
                $message .= "Content-Type: $mimeType; name=\"$fileName\"\r\n";
                $message .= "Content-Disposition: attachment; filename=\"$fileName\"\r\n";
                $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
                $message .= $fileContentEncoded . "\r\n";
            }
            
            $message .= "--$mixedBoundary--\r\n";
        } else {
            // Multipart/alternative only (no attachments)
            $headers[] = "Content-Type: multipart/alternative; boundary=\"$altBoundary\"";
            
            // Rebuild headers with multipart/alternative
            $message = implode("\r\n", $headers) . "\r\n\r\n";
            
            // Add plain text part
            $message .= "--$altBoundary\r\n";
            $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $message .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
            $message .= $body . "\r\n\r\n";
            
            // Add HTML part
            $message .= "--$altBoundary\r\n";
            $message .= "Content-Type: text/html; charset=UTF-8\r\n";
            $message .= "Content-Transfer-Encoding: 7bit\r\n\r\n";
            $message .= $htmlContent . "\r\n\r\n";
            $message .= "--$altBoundary--\r\n";
        }
        
        return $message;
    }
    
    /**
     * Convert plain text to HTML
     */
    private function convertPlainTextToHtml(string $text): string
    {
        // Escape HTML special characters
        $html = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        
        // Convert line breaks to <br>
        $html = nl2br($html);
        
        // Wrap in basic HTML structure
        return "<!DOCTYPE html>\n<html>\n<head><meta charset=\"UTF-8\"></head>\n<body style=\"font-family: Arial, sans-serif; line-height: 1.6; color: #333;\">\n" . $html . "\n</body>\n</html>";
    }
    
    /**
     * Get MIME type for file
     */
    private function getMimeType(string $filePath): string
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        
        $mimeTypes = [
            'pdf' => 'application/pdf',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'xls' => 'application/vnd.ms-excel',
            'csv' => 'text/csv',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'txt' => 'text/plain',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'zip' => 'application/zip'
        ];
        
        return $mimeTypes[$extension] ?? 'application/octet-stream';
    }
    
    /**
     * Send SMTP command
     */
    private function sendCommand(string $command): void
    {
        fwrite($this->socket, $command . "\r\n");
    }
    
    /**
     * Read SMTP response
     */
    private function readResponse(): string
    {
        $response = '';
        while ($line = fgets($this->socket, 515)) {
            $response .= $line;
            if (substr($line, 3, 1) === ' ') {
                break;
            }
        }
        return trim($response);
    }
    
    /**
     * Disconnect from SMTP server
     */
    private function disconnect(): void
    {
        if ($this->socket) {
            $this->sendCommand("QUIT");
            $this->readResponse();
            fclose($this->socket);
            $this->socket = null;
        }
    }
}
