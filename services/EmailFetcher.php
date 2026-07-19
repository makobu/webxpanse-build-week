<?php
/**
 * Email Fetcher Service
 * 
 * Fetches incoming emails via IMAP/POP3
 */

namespace CRM\Services;

use CRM\Database;
use CRM\EventBus;
use CRM\Services\AITaskCompletionService;
use CRM\Services\ConversationIntelligenceService;
use CRM\Services\TargetIntelligenceService;

class EmailFetcher
{
    private string $host;
    private int $port;
    private string $protocol;
    private string $encryption;
    private string $username;
    private string $password;
    private string $folder;
    private $connection = null;
    private ?string $profile;
    private ?EmailIntegrationService $emailIntegrationService = null;
    private ?GmailMailService $gmailMailService = null;
    private ?GoogleWorkspaceMailService $googleWorkspaceMailService = null;
    private static array $communicationsColumnCache = [];
    private static array $tableCache = [];
    private int $lastFetchedUid = 0;
    private int $lastFetchedCount = 0;
    private ?int $pendingFetchedUid = null;
    /** @var array<string,mixed>|null */
    private ?array $pendingGoogleCheckpoint = null;

    public static function getAssistantEnabledWorkspaceIds(): array
    {
        if (!Database::tableExists('workspaces')) {
            return [];
        }

        $workspaceIds = [];
        if (Database::tableExists('workspace_assistant_configs')) {
            $rows = Database::query(
                "SELECT DISTINCT c.workspace_id
                 FROM workspace_assistant_configs c
                 JOIN workspaces w ON w.id = c.workspace_id
                 WHERE c.assistant_type = 'email'
                   AND c.enabled = 1
                   AND w.status IN ('active', 'trialing')
                   AND LOWER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(c.settings_json, '$.imap_enabled')), '')) IN ('1', 'true', 'yes', 'on')"
            );
            foreach ($rows as $row) {
                $workspaceIds[(int) ($row['workspace_id'] ?? 0)] = true;
            }
        }

        if (Database::tableExists('email_integrations')) {
            $rows = Database::query(
                "SELECT DISTINCT i.workspace_id
                 FROM email_integrations i
                 JOIN workspaces w ON w.id = i.workspace_id
                 WHERE i.scope = ?
                   AND i.is_active = 1
                   AND w.status IN ('active', 'trialing')",
                [EmailIntegrationService::SCOPE_ASSISTANT_EMAIL]
            );
            foreach ($rows as $row) {
                $workspaceIds[(int) ($row['workspace_id'] ?? 0)] = true;
            }
        }

        unset($workspaceIds[0]);
        $ids = array_keys($workspaceIds);
        sort($ids, SORT_NUMERIC);
        return array_values(array_map('intval', $ids));
    }

    /**
     * @param string|null $profile 'assistant', 'outreach', 'nurture', or null for System Mail IMAP
     */
    public function __construct(?string $profile = null)
    {
        $this->profile = $this->normalizeProfile($profile);
        $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        $this->emailIntegrationService = new EmailIntegrationService();

        if ($this->profile === 'assistant') {
            $assistantService = new WorkspaceAssistantConfigService();
            $assistantConfig = $workspaceId > 0
                ? $assistantService->get($workspaceId, 'email', true)
                : [];
            $assistantSettings = (array) ($assistantConfig['settings'] ?? []);
            $this->host = trim((string) ($assistantSettings['imap_host'] ?? $assistantSettings['host'] ?? ''));
            $this->port = (int) (($assistantSettings['imap_port'] ?? $assistantSettings['port'] ?? 0) ?: 993);
            $this->protocol = strtolower((string) (($assistantSettings['imap_protocol'] ?? $assistantSettings['protocol'] ?? '') ?: 'imap'));
            $this->encryption = strtolower((string) (($assistantSettings['imap_encryption'] ?? $assistantSettings['encryption'] ?? '') ?: 'ssl'));
            $this->username = trim((string) ($assistantSettings['imap_username'] ?? $assistantSettings['username'] ?? ''));
            $this->password = (string) ($assistantSettings['imap_password'] ?? $assistantSettings['password'] ?? '');
            $this->folder = trim((string) ($assistantSettings['imap_folder'] ?? $assistantSettings['folder'] ?? 'INBOX')) ?: 'INBOX';
        } elseif (in_array($this->profile, ['outreach', 'nurture'], true)) {
            $workspaceImap = $this->emailIntegrationService->getManualImapConfigForRole($this->profile, $workspaceId > 0 ? $workspaceId : null);
            $this->host = trim((string) ($workspaceImap['host'] ?? ''));
            $this->port = (int) (($workspaceImap['port'] ?? 0) ?: 993);
            $this->protocol = strtolower((string) (($workspaceImap['protocol'] ?? '') ?: 'imap'));
            $this->encryption = strtolower((string) (($workspaceImap['encryption'] ?? '') ?: 'ssl'));
            $this->username = trim((string) ($workspaceImap['username'] ?? ''));
            $this->password = (string) ($workspaceImap['password'] ?? '');
            $this->folder = trim((string) ($workspaceImap['folder'] ?? '')) ?: 'INBOX';
        } else {
            $workspaceImap = $this->emailIntegrationService->getManualImapConfig($workspaceId > 0 ? $workspaceId : null);
            $this->host = trim((string) ($workspaceImap['host'] ?? ''));
            $this->port = (int) (($workspaceImap['port'] ?? 0) ?: 993);
            $this->protocol = strtolower((string) (($workspaceImap['protocol'] ?? '') ?: 'imap'));
            $this->encryption = strtolower((string) (($workspaceImap['encryption'] ?? '') ?: 'ssl'));
            $this->username = trim((string) ($workspaceImap['username'] ?? ''));
            $this->password = (string) ($workspaceImap['password'] ?? '');
            $this->folder = trim((string) ($workspaceImap['folder'] ?? '')) ?: 'INBOX';
        }
        $this->gmailMailService = new GmailMailService();
        $this->googleWorkspaceMailService = new GoogleWorkspaceMailService();
    }

    /**
     * Check if IMAP is enabled and configured
     */
    public function isEnabled(): bool
    {
        if ($this->profile === 'assistant') {
            if ($this->getAuthorizedOAuthIntegration()) {
                return true;
            }
            $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
            $workspaceAssistantConfig = $workspaceId > 0
                ? (new WorkspaceAssistantConfigService())->get($workspaceId, 'email')
                : [];
            return !empty($workspaceAssistantConfig['enabled'])
                && !empty($this->host)
                && !empty($this->username)
                && !empty($this->password);
        }

        if (in_array($this->profile, ['outreach', 'nurture'], true)) {
            if ($this->getAuthorizedOAuthIntegration()) {
                return true;
            }

            $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
            $workspaceImap = $this->emailIntegrationService
                ? $this->emailIntegrationService->getManualImapConfigForRole($this->profile, $workspaceId > 0 ? $workspaceId : null)
                : [];
            return !empty($workspaceImap['enabled'])
                && !empty($this->host)
                && !empty($this->username)
                && !empty($this->password);
        }

        if ($this->getAuthorizedOAuthIntegration()) {
            return true;
        }

        $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        $workspaceImap = $this->emailIntegrationService ? $this->emailIntegrationService->getManualImapConfig($workspaceId > 0 ? $workspaceId : null) : [];
        return !empty($workspaceImap['enabled'])
            && !empty($this->host)
            && !empty($this->username)
            && !empty($this->password);
    }
    
    /**
     * Connect to IMAP/POP3 server
     */
    public function connect(): bool
    {
        if ($this->getAuthorizedOAuthIntegration()) {
            return true;
        }

        if (!function_exists('imap_open')) {
            throw new \RuntimeException('PHP IMAP extension is not installed. Please install php-imap extension.');
        }
        
        if (!$this->isEnabled()) {
            throw new \RuntimeException('IMAP is not enabled or not properly configured.');
        }
        
        try {
            // Build connection string
            $connectionString = $this->buildConnectionString();
            
            // Suppress warnings for connection attempts
            $this->connection = @imap_open($connectionString, $this->username, $this->password);
            
            if (!$this->connection) {
                $errors = imap_errors();
                $errorMsg = $errors ? implode(', ', $errors) : 'Unknown connection error';
                if ($this->isDebugEnabled()) {
                    error_log("EmailFetcher IMAP connection failed for host {$this->host}:{$this->port}");
                }
                throw new \RuntimeException("Failed to connect to {$this->protocol} server: $errorMsg");
            }
            return true;
        } catch (\Exception $e) {
            error_log("EmailFetcher connection error: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Build IMAP/POP3 connection string
     * Format: {host:port/protocol/encryption}mailbox
     * Example: {imap.gmail.com:993/imap/ssl}INBOX
     */
    private function buildConnectionString(): string
    {
        // Start with host and port
        $server = "{{$this->host}:{$this->port}";
        
        // Add protocol first (imap or pop3)
        if ($this->protocol === 'pop3') {
            $server .= '/pop3';
        } else {
            $server .= '/imap';
        }
        
        // Add encryption after protocol
        if ($this->encryption === 'ssl') {
            $server .= '/ssl';
            if ($this->allowInsecureMailTls()) {
                $server .= '/novalidate-cert';
            }
        } elseif ($this->encryption === 'tls') {
            $server .= '/tls';
        } elseif ($this->encryption === 'none') {
            $server .= '/notls';
        }
        
        // Close the braces
        $server .= '}';
        
        // Add folder OUTSIDE the braces (only for IMAP)
        // Note: Some servers require lowercase folder names
        if ($this->protocol === 'imap' && !empty($this->folder)) {
            $server .= strtolower($this->folder);
        }
        
        return $server;
    }

    private function isDebugEnabled(): bool
    {
        return strtolower((string) ($_ENV['APP_DEBUG'] ?? 'false')) === 'true';
    }
    
    /**
     * Fetch new emails since last check
     */
    public function fetchNewEmails(): array
    {
        $this->discardFetchCheckpoint();
        if ($this->getAuthorizedOAuthIntegration()) {
            return $this->fetchNewGoogleWorkspaceEmails();
        }

        if (!$this->connection) {
            $this->connect();
        }
        
        $emails = [];
        
        try {
            // Get last fetch info
            $lastFetch = $this->getLastFetchInfo();
            $lastUid = $lastFetch['last_uid'] ?? 0;
            $this->lastFetchedUid = (int) $lastUid;
            $this->lastFetchedCount = 0;
            
            // Get all message numbers
            $messageNumbers = imap_search($this->connection, 'UNSEEN', SE_UID);
            
            if ($messageNumbers === false) {
                $imapError = imap_last_error();
                if (!empty($imapError)) {
                    $errorMsg = "IMAP search failed: {$imapError}";
                    error_log($errorMsg);
                    $this->logFetchError($errorMsg);
                }
                return $emails;
            }

            if (empty($messageNumbers)) {
                return $emails; // No new emails
            }
            
            // Filter messages newer than last UID
            $newMessages = array_filter($messageNumbers, function($uid) use ($lastUid) {
                return $uid > $lastUid;
            });
            
            $maxUid = $lastUid;
            
            foreach ($newMessages as $messageNumber) {
                try {
                    $emailData = $this->parseEmail($messageNumber);
                    if ($emailData) {
                        $emails[] = $emailData;
                        $maxUid = max($maxUid, $messageNumber);
                    }
                } catch (\Exception $e) {
                    error_log("Error parsing email {$messageNumber}: " . $e->getMessage());
                    continue;
                }
            }
            
            $this->pendingFetchedUid = (int) $maxUid;
            $this->lastFetchedCount = count($emails);
            
        } catch (\Exception $e) {
            error_log("Error fetching emails: " . $e->getMessage());
            throw $e;
        }
        
        return $emails;
    }

    public function getProviderKey(): string
    {
        if ($this->profile === 'assistant') {
            $integration = $this->getAuthorizedOAuthIntegration();
            return $integration
                ? (string) ($integration['provider'] ?? EmailIntegrationService::PROVIDER_GMAIL_OAUTH)
                : 'assistant_imap';
        }
        if (in_array($this->profile, ['outreach', 'nurture'], true)) {
            $integration = $this->getAuthorizedOAuthIntegration();
            return $integration
                ? (string) ($integration['provider'] ?? EmailIntegrationService::PROVIDER_GMAIL_OAUTH)
                : $this->profile . '_imap';
        }

        $integration = $this->getAuthorizedOAuthIntegration();
        return $integration
            ? (string) ($integration['provider'] ?? EmailIntegrationService::PROVIDER_GOOGLE_WORKSPACE)
            : EmailIntegrationService::PROVIDER_MANUAL_IMAP;
    }

    private function fetchNewGoogleWorkspaceEmails(): array
    {
        $integration = $this->getAuthorizedOAuthIntegration();
        if (!$integration) {
            throw new \RuntimeException('No direct-connect Google mailbox is available for this inbox.');
        }
        $mailService = $this->mailServiceForProvider((string) ($integration['provider'] ?? ''));

        $accessToken = (string) ($integration['access_token'] ?? '');
        if ($accessToken === '') {
            throw new \RuntimeException($this->providerLabel((string) ($integration['provider'] ?? '')) . ' access token is unavailable.');
        }

        $settings = is_array($integration['settings'] ?? null) ? $integration['settings'] : [];
        $historyId = trim((string) ($settings['gmail_history_id'] ?? ''));
        $lastInternalTs = (int) ($settings['last_message_internal_ts'] ?? 0);
        $latestInternalTs = $lastInternalTs;
        $latestHistoryId = $historyId;
        $messageIds = [];
        $profile = null;

        if ($historyId !== '') {
            try {
                $pageToken = null;
                do {
                    $historyResponse = $mailService->listHistory($accessToken, $historyId, $pageToken);
                    $latestHistoryId = trim((string) ($historyResponse['historyId'] ?? $latestHistoryId));
                    foreach ((array) ($historyResponse['history'] ?? []) as $historyItem) {
                        foreach ((array) ($historyItem['messagesAdded'] ?? []) as $messageAdded) {
                            $gmailMessageId = trim((string) ($messageAdded['message']['id'] ?? ''));
                            if ($gmailMessageId !== '') {
                                $messageIds[$gmailMessageId] = true;
                            }
                        }
                    }
                    $pageToken = trim((string) ($historyResponse['nextPageToken'] ?? ''));
                } while ($pageToken !== '');
            } catch (\Throwable $e) {
                error_log('EmailFetcher Google mail history fallback: ' . $e->getMessage());
                $historyId = '';
            }
        }

        if ($historyId === '') {
            $profile = $mailService->getAuthenticatedProfile($accessToken);
            $latestHistoryId = trim((string) ($profile['historyId'] ?? $latestHistoryId));
            $pageToken = null;
            do {
                $messagesResponse = $mailService->listInboxMessages($accessToken, $pageToken, 50);
                foreach ((array) ($messagesResponse['messages'] ?? []) as $messageRef) {
                    $gmailMessageId = trim((string) ($messageRef['id'] ?? ''));
                    if ($gmailMessageId !== '') {
                        $messageIds[$gmailMessageId] = true;
                    }
                }
                $pageToken = trim((string) ($messagesResponse['nextPageToken'] ?? ''));
            } while ($pageToken !== '' && count($messageIds) < 100);
        }

        $emails = [];
        foreach (array_keys($messageIds) as $gmailMessageId) {
            $message = $mailService->getMessage($accessToken, $gmailMessageId, 'full');
            $labelIds = array_map('strtoupper', array_map('strval', (array) ($message['labelIds'] ?? [])));
            if (in_array('SENT', $labelIds, true) || in_array('DRAFT', $labelIds, true)) {
                continue;
            }

            $emailData = $mailService->parseMessageToEmailData($message);
            if (!$emailData) {
                continue;
            }

            $messageId = trim((string) ($emailData['message_id'] ?? ''));
            if ($messageId !== '' && $this->messageAlreadyImported($messageId)) {
                continue;
            }

            $internalTs = (int) ($emailData['internal_ts'] ?? 0);
            if ($historyId === '' && $lastInternalTs > 0 && $internalTs > 0 && $internalTs <= $lastInternalTs) {
                continue;
            }

            $latestInternalTs = max($latestInternalTs, $internalTs);
            $emails[] = $emailData;
        }

        if ($profile === null) {
            try {
                $profile = $mailService->getAuthenticatedProfile($accessToken);
            } catch (\Throwable $e) {
                $profile = [];
            }
        }
        $latestHistoryId = trim((string) (($profile['historyId'] ?? '') ?: $latestHistoryId));

        $this->lastFetchedUid = 0;
        $this->lastFetchedCount = count($emails);

        if ($this->emailIntegrationService && !empty($integration['id'])) {
            $this->pendingGoogleCheckpoint = [
                'integration_id' => (int) $integration['id'],
                'settings' => [
                    'gmail_history_id' => $latestHistoryId !== '' ? $latestHistoryId : ($settings['gmail_history_id'] ?? ''),
                    'last_message_internal_ts' => $latestInternalTs,
                    'messages_total' => (int) ($profile['messagesTotal'] ?? ($settings['messages_total'] ?? 0)),
                    'threads_total' => (int) ($profile['threadsTotal'] ?? ($settings['threads_total'] ?? 0)),
                ],
            ];
        }

        return $emails;
    }

    public function commitFetchCheckpoint(): void
    {
        $committedImapUid = $this->pendingFetchedUid;
        if ($this->pendingGoogleCheckpoint !== null && $this->emailIntegrationService !== null) {
            $integrationId = (int) ($this->pendingGoogleCheckpoint['integration_id'] ?? 0);
            $settings = (array) ($this->pendingGoogleCheckpoint['settings'] ?? []);
            if ($integrationId > 0 && $settings !== []) {
                $this->emailIntegrationService->mergeSettings($integrationId, $settings);
            }
        }

        if ($committedImapUid !== null && $this->profile === 'assistant') {
            $this->persistAssistantFetchCheckpoint($committedImapUid);
        }

        if ($this->pendingFetchedUid !== null) {
            $this->lastFetchedUid = $this->pendingFetchedUid;
        }

        $this->pendingFetchedUid = null;
        $this->pendingGoogleCheckpoint = null;
    }

    private function persistAssistantFetchCheckpoint(int $lastUid): void
    {
        $workspaceId = $this->assistantWorkspaceId();
        if ($workspaceId <= 0 || !$this->tableExists('email_assistant_fetch_log')) {
            throw new \RuntimeException('Assistant inbox checkpoint storage is unavailable for this workspace.');
        }

        Database::execute(
            "INSERT INTO email_assistant_fetch_log
                (workspace_id, last_uid, last_fetch_at, emails_fetched, emails_total_found, processed_count, skipped_count, auto_created_count, error_count, status, source)
             VALUES (?, ?, NOW(), ?, ?, ?, 0, 0, 0, 'success', 'assistant')",
            [$workspaceId, $lastUid, $this->lastFetchedCount, $this->lastFetchedCount, $this->lastFetchedCount]
        );
    }

    public function discardFetchCheckpoint(): void
    {
        $this->pendingFetchedUid = null;
        $this->pendingGoogleCheckpoint = null;
    }
    
    /**
     * Parse email message
     */
    private function parseEmail(int $messageNumber): ?array
    {
        // Get headers
        $headers = imap_headerinfo($this->connection, $messageNumber, FT_UID);
        
        if (!$headers) {
            error_log("Skipping message {$messageNumber}: missing IMAP headers");
            return null;
        }
        
        // Check for duplicate (Message-ID)
        $messageId = $headers->message_id ?? null;
        if ($messageId) {
            if ($this->profile === 'assistant') {
                $workspaceId = $this->assistantWorkspaceId();
                $existing = $workspaceId > 0 && Database::columnExists('email_assistant_messages', 'workspace_id')
                    ? Database::queryOne(
                        "SELECT id
                         FROM email_assistant_messages
                         WHERE workspace_id = ?
                           AND message_id = ?
                           AND direction = 'inbound'",
                        [$workspaceId, $messageId]
                    )
                    : null;
            } else {
                if (!$this->communicationsColumnExists('message_id')) {
                    $existing = null;
                } else {
                $workspaceId = $this->requireWorkspaceIdForMainInbox();
                $existing = Database::queryOne(
                    "SELECT id
                     FROM communications
                     WHERE workspace_id = ?
                       AND message_id = ?",
                    [$workspaceId, $messageId]
                );
                }
            }
            if ($existing) {
                return null; // Already processed
            }
        }
        
        // Get body
        $body = imap_body($this->connection, $messageNumber, FT_UID);
        if ($body === false) {
            error_log("Message {$messageNumber}: imap_body returned false, continuing with empty body");
            $body = '';
        }
        
        // Parse structure for multipart messages
        $structure = imap_fetchstructure($this->connection, $messageNumber, FT_UID);
        if ($structure === false) {
            error_log("Message {$messageNumber}: imap_fetchstructure returned false, using raw body");
            $bodyText = $body;
        } else {
            $bodyText = $this->extractBody($structure, $messageNumber, $body);
        }
        
        // Extract plain text from HTML if needed
        $plainText = strip_tags($bodyText);
        if (strlen($plainText) < strlen($bodyText) * 0.5) {
            // Likely HTML, extract better plain text
            $plainText = html_entity_decode($plainText);
            $plainText = preg_replace('/\s+/', ' ', $plainText);
            $plainText = trim($plainText);
        }
        
        // Get sender info
        $fromHeader = $headers->from[0] ?? null;
        $fromMailbox = isset($fromHeader->mailbox) ? trim((string) $fromHeader->mailbox) : '';
        $fromHost = isset($fromHeader->host) ? trim((string) $fromHeader->host) : '';
        if ($fromMailbox === '' || $fromHost === '') {
            error_log("Skipping message {$messageNumber}: malformed or missing From header");
            return null;
        }
        $fromEmail = $fromMailbox . '@' . $fromHost;
        $fromName = $fromHeader->personal ?? $fromEmail;

        // Get To address (primary recipient)
        $toEmail = '';
        if (isset($headers->to) && is_array($headers->to) && count($headers->to) > 0) {
            $toHeader = $headers->to[0] ?? null;
            $toMailbox = isset($toHeader->mailbox) ? trim((string) $toHeader->mailbox) : '';
            $toHost = isset($toHeader->host) ? trim((string) $toHeader->host) : '';
            if ($toMailbox !== '' && $toHost !== '') {
                $toEmail = strtolower($toMailbox . '@' . $toHost);
            } else {
                error_log("Message {$messageNumber}: malformed To header, leaving to_email empty");
            }
        }
        
        // Get subject
        $subject = isset($headers->subject) ? $this->decodeMimeHeader($headers->subject) : '';
        
        // Get In-Reply-To header for threading
        $inReplyTo = isset($headers->in_reply_to) ? trim($headers->in_reply_to, '<>') : null;
        
        // Store inbound email event time in app timezone (defaults to Africa/Nairobi).
        $date = date('Y-m-d H:i:s');
        if (isset($headers->date) && trim((string) $headers->date) !== '') {
            try {
                $headerDate = new \DateTimeImmutable((string) $headers->date);
                $date = $headerDate->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
            } catch (\Throwable $e) {
                $parsed = strtotime((string) $headers->date);
                if ($parsed !== false) {
                    $date = date('Y-m-d H:i:s', $parsed);
                }
            }
        }
        
        return [
            'message_id' => $messageId,
            'from_email' => $fromEmail,
            'to_email' => $toEmail,
            'from_name' => $fromName,
            'subject' => $subject,
            'body' => $plainText,
            'body_html' => $bodyText,
            'in_reply_to' => $inReplyTo,
            'date' => $date,
            'uid' => $messageNumber,
            'workspace_id' => $this->profile === 'assistant' ? $this->assistantWorkspaceId() : $this->requireWorkspaceIdForMainInbox(),
        ];
    }
    
    /**
     * Extract body from multipart message
     */
    private function extractBody($structure, int $messageNumber, string $body): string
    {
        if (!is_object($structure)) {
            return $body;
        }

        if (isset($structure->type) && $structure->type == TYPEMULTIPART && isset($structure->parts) && is_array($structure->parts)) {
            $parts = [];
            foreach ($structure->parts as $partNum => $part) {
                if (!is_object($part)) {
                    continue;
                }
                $partBody = imap_fetchbody($this->connection, $messageNumber, $partNum + 1, FT_UID);
                if ($partBody === false) {
                    error_log("Message {$messageNumber} part {$partNum}: imap_fetchbody returned false");
                    $partBody = '';
                }
                
                // Decode if needed
                if (isset($part->encoding) && $part->encoding == ENCBASE64) {
                    $partBody = base64_decode($partBody);
                } elseif (isset($part->encoding) && $part->encoding == ENCQUOTEDPRINTABLE) {
                    $partBody = quoted_printable_decode($partBody);
                }
                
                // Check content type
                $type = $this->getContentType($part);
                if (strpos($type, 'text/html') !== false) {
                    $parts['html'] = $partBody;
                } elseif (strpos($type, 'text/plain') !== false) {
                    $parts['text'] = $partBody;
                }
            }
            
            // Prefer HTML, fallback to plain text
            return $parts['html'] ?? $parts['text'] ?? $body;
        } else {
            // Single part message
            if (isset($structure->encoding) && $structure->encoding == ENCBASE64) {
                $body = base64_decode($body);
            } elseif (isset($structure->encoding) && $structure->encoding == ENCQUOTEDPRINTABLE) {
                $body = quoted_printable_decode($body);
            }
            
            return $body;
        }
    }
    
    /**
     * Get content type from part structure
     */
    private function getContentType($part): string
    {
        if (isset($part->dparameters)) {
            foreach ($part->dparameters as $param) {
                if (isset($param->attribute, $param->value) && strtolower($param->attribute) === 'content-type') {
                    return strtolower($param->value);
                }
            }
        }
        
        if (isset($part->parameters)) {
            foreach ($part->parameters as $param) {
                if (isset($param->attribute, $param->value) && strtolower($param->attribute) === 'content-type') {
                    return strtolower($param->value);
                }
            }
        }
        
        return 'text/plain';
    }
    
    /**
     * Decode MIME header
     */
    private function decodeMimeHeader(string $header): string
    {
        $decoded = imap_mime_header_decode($header);
        $result = '';
        foreach ($decoded as $part) {
            $result .= $part->text;
        }
        return $result;
    }
    
    /**
     * Match sender email to contact
     */
    public function matchToContact(string $fromEmail): ?int
    {
        $fromEmail = $this->normalizeEmailAddress($fromEmail);
        if ($fromEmail === '') {
            return null;
        }

        $workspaceId = $this->requireWorkspaceIdForMainInbox();
        $contact = Database::queryOne(
            "SELECT id
             FROM contacts
             WHERE workspace_id = ?
               AND LOWER(TRIM(email)) = ?
             LIMIT 1",
            [$workspaceId, $fromEmail]
        );
        
        return $contact ? (int) $contact['id'] : null;
    }
    
    /**
     * Create contact if not exists
     */
    public function createContactIfNotExists(string $email, string $name): int
    {
        $email = $this->normalizeEmailAddress($email);
        if ($email === '') {
            throw new \RuntimeException('Cannot create contact without a valid sender email.');
        }

        $workspaceId = $this->requireWorkspaceIdForMainInbox();
        // Check if contact exists
        $contactId = $this->matchToContact($email);
        if ($contactId) {
            return $contactId;
        }
        
        [$firstName, $lastName] = $this->splitDisplayName($name, $email);
        
        // Create contact (contacts.uuid is CHAR(36))
        $uuid = uuid_v4();
        try {
            Database::execute(
                "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, stage, created_at) 
                 VALUES (?, ?, ?, ?, ?, 'new', NOW())",
                [$workspaceId, $uuid, $firstName, $lastName, $email]
            );
            
            return (int) Database::lastInsertId();
        } catch (\PDOException $e) {
            $contactId = $this->matchToContact($email);
            if ($contactId) {
                return $contactId;
            }
            throw $e;
        }
    }
    
    /**
     * Add fetched email to communications table
     */
    public function addToCommunications(array $emailData, ?int $contactId = null): int
    {
        $workspaceId = $this->requireWorkspaceIdForMainInbox();
        // Match or create contact
        $contactId = $contactId ?: $this->matchToContact($emailData['from_email']);
        
        // If still no contact, use a default or skip
        if (!$contactId) {
            throw new \RuntimeException("No contact found for email: {$emailData['from_email']}");
        }
        
        // Extract data from email if auto-enrichment enabled
        if (($contactId && ($_ENV['AUTO_ENRICH_ON_EMAIL'] ?? 'false') === 'true')) {
            try {
                $enrichmentService = new \CRM\Services\AIEnrichmentService();
                $enrichmentService->extractFromEmail($emailData['body'] ?? '', [
                    'contact_id' => (int) $contactId,
                    'from_email' => $emailData['from_email'],
                    'from_name' => $emailData['from_name'],
                    'message_id' => $emailData['message_id'] ?? null,
                    'uid' => $emailData['uid'] ?? null,
                ]);
            } catch (\Exception $e) {
                error_log("Email extraction error: " . $e->getMessage());
            }
        }
        
        // Create communication record (communications.uuid is CHAR(36))
        $uuid = uuid_v4();
        $metadata = [
            'body_html' => $emailData['body_html'] ?? null,
            'from_name' => $emailData['from_name'],
            'uid' => $emailData['uid']
        ];
        $bodyText = strip_tags($emailData['body'] ?? '');
        if (strlen($bodyText) > 10) {
            try {
                $sentimentAnalysis = new \CRM\Modules\SentimentAnalysis();
                $metadata['sentiment'] = $sentimentAnalysis->analyze($bodyText);
            } catch (\Throwable $e) {
                error_log("EmailFetcher: Sentiment analysis failed: " . $e->getMessage());
            }
            try {
                $intentDetection = new \CRM\Modules\IntentDetection();
                $metadata['intent'] = $intentDetection->detect($bodyText);
            } catch (\Throwable $e) {
                error_log("EmailFetcher: Intent detection failed: " . $e->getMessage());
            }
        }
        $columns = ['workspace_id', 'uuid', 'contact_id', 'channel', 'direction', 'subject', 'body', 'status', 'metadata', 'created_at'];
        $values = ['?', '?', '?', "'email'", "'inbound'", '?', '?', "'sent'", '?', '?'];
        $params = [
            $workspaceId,
            $uuid,
            $contactId,
            $emailData['subject'] ?? 'No Subject',
            $emailData['body'],
            json_encode($metadata),
            $emailData['date']
        ];
        if ($this->communicationsColumnExists('message_id')) {
            $columns[] = 'message_id';
            $values[] = '?';
            $params[] = $emailData['message_id'] ?? null;
        }
        if ($this->communicationsColumnExists('in_reply_to')) {
            $columns[] = 'in_reply_to';
            $values[] = '?';
            $params[] = $emailData['in_reply_to'] ?? null;
        }
        if ($this->communicationsColumnExists('from_email')) {
            $columns[] = 'from_email';
            $values[] = '?';
            $params[] = $emailData['from_email'] ?? '';
        }
        if ($this->communicationsColumnExists('to_email')) {
            $columns[] = 'to_email';
            $values[] = '?';
            $params[] = $emailData['to_email']
                ?? ($this->emailIntegrationService
                    ? $this->emailIntegrationService->getPreferredMainFromEmail('')
                    : '');
        }
        $sql = "INSERT INTO communications (" . implode(', ', $columns) . ")
                VALUES (" . implode(', ', $values) . ")";
        Database::execute($sql, $params);
        
        $communicationId = (int) Database::lastInsertId();
        try {
            (new ConversationIntelligenceService())->syncForCommunication($communicationId);
        } catch (\Throwable $e) {
            error_log("EmailFetcher: Conversation intelligence sync failed: " . $e->getMessage());
        }

        // Publish standardized inbound event and enqueue AI auto-responder work.
        $eventData = [
            'contact_id' => $contactId,
            'communication_id' => $communicationId,
            'channel' => 'email',
            'direction' => 'inbound',
            'message_text' => (string) ($emailData['body'] ?? ''),
            'subject' => (string) ($emailData['subject'] ?? ''),
            'message_id' => (string) ($emailData['message_id'] ?? ''),
            'from_email' => (string) ($emailData['from_email'] ?? ''),
            'metadata' => $metadata,
        ];
        EventBus::publish('email.received', $eventData);

        try {
            $queueService = new AIAutoResponderQueueService();
            $queueService->enqueueInboundCommunication(
                $communicationId,
                $contactId,
                'email',
                (string) ($emailData['body'] ?? ''),
                [
                    'message_id' => (string) ($emailData['message_id'] ?? ''),
                    'from_email' => (string) ($emailData['from_email'] ?? ''),
                    'subject' => (string) ($emailData['subject'] ?? ''),
                ]
            );
        } catch (\Throwable $e) {
            error_log("EmailFetcher: Failed to enqueue AI auto-responder item: " . $e->getMessage());
        }

        try {
            $triage = new InboxTriageService();
            $triage->processCommunication($communicationId);
        } catch (\Throwable $e) {
            error_log("EmailFetcher: Inbox triage failed: " . $e->getMessage());
        }
        try {
            $scanUserId = (int) ($_SESSION['user_id'] ?? 0);
            if ($scanUserId > 0) {
                (new AITaskCompletionService())->scanForCompletionEvidence($scanUserId);
            }
        } catch (\Throwable $e) {
            error_log("EmailFetcher: AI task completion scan failed: " . $e->getMessage());
        }
        try {
            (new ContactIntelligenceService())->computeAndPersist((int) $contactId);
        } catch (\Throwable $e) {
            error_log("EmailFetcher: Contact intelligence refresh failed: " . $e->getMessage());
        }
        try {
            (new TargetIntelligenceService())->refreshAfterEntityChange('communications', $communicationId, [
                'contact_id' => (int) $contactId,
                'channel' => 'email',
            ]);
        } catch (\Throwable $e) {
            error_log("EmailFetcher: Target intelligence refresh failed: " . $e->getMessage());
        }

        try {
            $outcomes = new OutcomeEventService();
            $outcomes->track('channel.connected', [
                'user_id' => (int) ($_SESSION['user_id'] ?? 0),
                'contact_id' => $contactId,
                'event_source' => 'email_fetcher',
                'metadata' => ['channel' => 'email'],
            ]);
            $outcomes->track('inbound.processed', [
                'user_id' => (int) ($_SESSION['user_id'] ?? 0),
                'contact_id' => $contactId,
                'event_source' => 'email_fetcher',
                'metadata' => ['channel' => 'email', 'communication_id' => $communicationId],
            ]);
        } catch (\Throwable $e) {
            error_log("EmailFetcher: Outcome tracking failed: " . $e->getMessage());
        }

        return $communicationId;
    }

    public function startImportRun(?int $initiatedByUserId = null, string $source = 'manual'): int
    {
        if ($this->profile !== null || !$this->tableExists('email_fetch_log')) {
            return 0;
        }

        Database::execute(
            "INSERT INTO email_fetch_log
             (workspace_id, last_uid, last_fetch_at, emails_fetched, emails_total_found, processed_count, skipped_count, auto_created_count, error_count, status, error_message, initiated_by_user_id, source)
             VALUES (?, ?, NOW(), 0, 0, 0, 0, 0, 0, 'running', NULL, ?, ?)",
            [
                $this->requireWorkspaceIdForMainInbox(),
                $this->lastFetchedUid,
                $initiatedByUserId ?: null,
                substr(trim($source), 0, 32) ?: 'manual',
            ]
        );

        return (int) Database::lastInsertId();
    }

    public function finalizeImportRun(int $fetchLogId, array $summary, ?string $errorMessage = null): void
    {
        if ($fetchLogId <= 0 || $this->profile !== null || !$this->tableExists('email_fetch_log')) {
            return;
        }

        $processed = (int) ($summary['processed'] ?? 0);
        $skipped = (int) ($summary['skipped'] ?? 0);
        $autoCreated = (int) ($summary['auto_created'] ?? 0);
        $errors = (int) ($summary['errors'] ?? 0);
        $totalFound = (int) ($summary['emails_found'] ?? $this->lastFetchedCount);

        $status = 'success';
        if ($errors > 0 && ($processed + $autoCreated + $skipped) === 0) {
            $status = 'failed';
        } elseif ($errors > 0 || $skipped > 0) {
            $status = 'partial';
        }

        Database::execute(
            "UPDATE email_fetch_log
             SET last_uid = ?,
                 last_fetch_at = NOW(),
                 emails_fetched = ?,
                 emails_total_found = ?,
                 processed_count = ?,
                 skipped_count = ?,
                 auto_created_count = ?,
                 error_count = ?,
                 status = ?,
                 error_message = ?
             WHERE id = ?
               AND workspace_id = ?",
            [
                $this->lastFetchedUid,
                $processed + $autoCreated,
                $totalFound,
                $processed,
                $skipped,
                $autoCreated,
                $errors,
                $status,
                $errorMessage !== null && trim($errorMessage) !== '' ? $errorMessage : null,
                $fetchLogId,
                $this->requireWorkspaceIdForMainInbox(),
            ]
        );
    }

    public function recordRunFailure(int $fetchLogId, string $errorMessage, array $summary = []): void
    {
        if ($fetchLogId <= 0 || $this->profile !== null || !$this->tableExists('email_fetch_log')) {
            return;
        }

        Database::execute(
            "UPDATE email_fetch_log
             SET last_uid = ?,
                 last_fetch_at = NOW(),
                 emails_fetched = ?,
                 emails_total_found = ?,
                 processed_count = ?,
                 skipped_count = ?,
                 auto_created_count = ?,
                 error_count = ?,
                 status = 'failed',
                 error_message = ?
             WHERE id = ?
               AND workspace_id = ?",
            [
                $this->lastFetchedUid,
                (int) (($summary['processed'] ?? 0) + ($summary['auto_created'] ?? 0)),
                (int) ($summary['emails_found'] ?? $this->lastFetchedCount),
                (int) ($summary['processed'] ?? 0),
                (int) ($summary['skipped'] ?? 0),
                (int) ($summary['auto_created'] ?? 0),
                (int) ($summary['errors'] ?? 0),
                trim($errorMessage),
                $fetchLogId,
                $this->requireWorkspaceIdForMainInbox(),
            ]
        );
    }

    public function importFetchedEmail(array $emailData, ?int $fetchLogId = null, bool $forceAutoCreate = true): array
    {
        $senderEmail = $this->normalizeEmailAddress((string) ($emailData['from_email'] ?? ''));
        $senderName = trim((string) ($emailData['from_name'] ?? ''));
        $subject = trim((string) ($emailData['subject'] ?? ''));
        $messageUid = isset($emailData['uid']) ? (int) $emailData['uid'] : null;
        $messageId = trim((string) ($emailData['message_id'] ?? ''));

        if ($senderEmail === '') {
            $result = [
                'outcome' => 'skipped',
                'reason_code' => 'invalid_sender_email',
                'reason_message' => 'Sender email is missing or invalid.',
                'contact_id' => null,
                'communication_id' => null,
                'sender_email' => null,
                'sender_name' => $senderName,
                'subject_preview' => $this->truncateSubjectPreview($subject),
                'message_uid' => $messageUid,
                'message_id' => $messageId !== '' ? $messageId : null,
            ];
            $this->recordImportDetail($fetchLogId, $result);
            return $result;
        }

        try {
            $contactId = $this->matchToContact($senderEmail);
            $autoCreated = false;
            $reasonCode = 'matched_contact';
            $reasonMessage = 'Matched an existing contact by sender email.';

            if (!$contactId && $forceAutoCreate && $this->profile === null) {
                $contactId = $this->createContactIfNotExists($senderEmail, $senderName);
                $autoCreated = true;
                $reasonCode = 'contact_auto_created';
                $reasonMessage = 'Created a new contact from the sender email during import.';
            }

            if (!$contactId) {
                $result = [
                    'outcome' => 'skipped',
                    'reason_code' => 'contact_unresolved',
                    'reason_message' => 'No contact could be resolved for the sender email.',
                    'contact_id' => null,
                    'communication_id' => null,
                    'sender_email' => $senderEmail,
                    'sender_name' => $senderName,
                    'subject_preview' => $this->truncateSubjectPreview($subject),
                    'message_uid' => $messageUid,
                    'message_id' => $messageId !== '' ? $messageId : null,
                ];
                $this->recordImportDetail($fetchLogId, $result);
                return $result;
            }

            $emailData['from_email'] = $senderEmail;
            $emailData['from_name'] = $senderName !== '' ? $senderName : $senderEmail;
            $communicationId = $this->addToCommunications($emailData, $contactId);

            $result = [
                'outcome' => $autoCreated ? 'auto_created' : 'processed',
                'reason_code' => $reasonCode,
                'reason_message' => $reasonMessage,
                'contact_id' => $contactId,
                'communication_id' => $communicationId,
                'sender_email' => $senderEmail,
                'sender_name' => $emailData['from_name'],
                'subject_preview' => $this->truncateSubjectPreview($subject),
                'message_uid' => $messageUid,
                'message_id' => $messageId !== '' ? $messageId : null,
            ];
            $this->recordImportDetail($fetchLogId, $result);

            return $result;
        } catch (\Throwable $e) {
            $result = [
                'outcome' => 'error',
                'reason_code' => 'import_failed',
                'reason_message' => $e->getMessage(),
                'contact_id' => null,
                'communication_id' => null,
                'sender_email' => $senderEmail,
                'sender_name' => $senderName,
                'subject_preview' => $this->truncateSubjectPreview($subject),
                'message_uid' => $messageUid,
                'message_id' => $messageId !== '' ? $messageId : null,
            ];
            $this->recordImportDetail($fetchLogId, $result);

            throw $e;
        }
    }

    public function getLastFetchedUid(): int
    {
        return $this->lastFetchedUid;
    }

    public function getLastFetchedCount(): int
    {
        return $this->lastFetchedCount;
    }

    private function communicationsColumnExists(string $column): bool
    {
        if (array_key_exists($column, self::$communicationsColumnCache)) {
            return self::$communicationsColumnCache[$column];
        }

        try {
            $row = Database::queryOne(
                "SELECT COUNT(*) AS count
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'communications'
                   AND COLUMN_NAME = ?",
                [$column]
            );
            $exists = ((int) ($row['count'] ?? 0)) > 0;
            self::$communicationsColumnCache[$column] = $exists;
            return $exists;
        } catch (\Throwable $e) {
            self::$communicationsColumnCache[$column] = false;
            return false;
        }
    }
    
    private function getFetchLogTable(): string
    {
        return $this->profile === 'assistant' ? 'email_assistant_fetch_log' : 'email_fetch_log';
    }

    /**
     * Get last fetch info from database
     */
    private function getLastFetchInfo(): array
    {
        $table = $this->getFetchLogTable();
        if (!$this->tableExists($table)) {
            return ['last_uid' => 0, 'last_fetch_at' => null];
        }

        if ($this->profile === 'assistant') {
            $workspaceId = $this->assistantWorkspaceId();
            if ($workspaceId > 0 && Database::columnExists($table, 'workspace_id')) {
                $log = Database::queryOne(
                    "SELECT last_uid, last_fetch_at FROM {$table}
                     WHERE workspace_id = ?
                       AND status IN ('success', 'partial')
                     ORDER BY last_fetch_at DESC
                     LIMIT 1",
                    [$workspaceId]
                );
            } else {
                $log = Database::queryOne(
                    "SELECT last_uid, last_fetch_at FROM {$table}
                     WHERE status IN ('success', 'partial')
                     ORDER BY last_fetch_at DESC
                     LIMIT 1"
                );
            }
        } else {
            $log = Database::queryOne(
                "SELECT last_uid, last_fetch_at FROM {$table} 
                 WHERE workspace_id = ?
                   AND status IN ('success', 'partial') 
                 ORDER BY last_fetch_at DESC 
                 LIMIT 1",
                [$this->requireWorkspaceIdForMainInbox()]
            );
        }
        
        return $log ?: ['last_uid' => 0, 'last_fetch_at' => null];
    }
    
    /**
     * Disconnect from server
     */
    public function disconnect(): void
    {
        if ($this->connection) {
            imap_close($this->connection);
            $this->connection = null;
        }
    }
    
    /**
     * Destructor - ensure connection is closed
     */
    public function __destruct()
    {
        $this->disconnect();
    }

    private function getAuthorizedOAuthIntegration(): ?array
    {
        if (!$this->emailIntegrationService) {
            return null;
        }

        try {
            if ($this->profile === 'assistant') {
                return $this->emailIntegrationService->getAuthorizedMailInboxIntegration(EmailIntegrationService::SCOPE_ASSISTANT_EMAIL);
            }

            if (in_array($this->profile, ['outreach', 'nurture'], true)) {
                $scope = $this->profile === 'outreach'
                    ? EmailIntegrationService::SCOPE_OUTREACH_EMAIL
                    : EmailIntegrationService::SCOPE_NURTURE_EMAIL;
                return $this->emailIntegrationService->getAuthorizedMailInboxIntegration($scope);
            }

            return null;
        } catch (\Throwable $e) {
            error_log('EmailFetcher Google mailbox auth resolution failed: ' . $e->getMessage());
            return null;
        }
    }

    private function mailServiceForProvider(string $provider): GoogleOAuthMailService
    {
        if ($this->profile === 'assistant' && $provider === EmailIntegrationService::PROVIDER_GMAIL_OAUTH) {
            return new AssistantGmailMailService();
        }

        return match ($provider) {
            EmailIntegrationService::PROVIDER_GMAIL_OAUTH => $this->gmailMailService ?? new GmailMailService(),
            EmailIntegrationService::PROVIDER_GOOGLE_WORKSPACE => $this->googleWorkspaceMailService ?? new GoogleWorkspaceMailService(),
            default => throw new \InvalidArgumentException('Unsupported Google mail provider: ' . $provider),
        };
    }

    private function providerLabel(string $provider): string
    {
        return $this->emailIntegrationService
            ? $this->emailIntegrationService->providerLabel($provider)
            : 'Google mail';
    }

    private function normalizeProfile(?string $profile): ?string
    {
        return match (strtolower(trim((string) $profile))) {
            'assistant', 'email_assistant', 'assistant_email' => 'assistant',
            'outreach', 'outreach_email', 'outbound', 'sales' => 'outreach',
            'nurture', 'nurture_email', 'followup', 'follow_up' => 'nurture',
            default => null,
        };
    }

    private function messageAlreadyImported(string $messageId): bool
    {
        if ($messageId === '') {
            return false;
        }

        if ($this->profile === 'assistant') {
            if (!$this->tableExists('email_assistant_messages')) {
                return false;
            }
            $workspaceId = $this->assistantWorkspaceId();
            if ($workspaceId <= 0 || !Database::columnExists('email_assistant_messages', 'workspace_id')) {
                return false;
            }
            $existing = Database::queryOne(
                "SELECT id
                 FROM email_assistant_messages
                 WHERE workspace_id = ?
                   AND message_id = ?
                   AND direction = 'inbound'
                 LIMIT 1",
                [$workspaceId, $messageId]
            );
        } else {
            if (!$this->communicationsColumnExists('message_id')) {
                return false;
            }
            $workspaceId = $this->requireWorkspaceIdForMainInbox();
            $existing = Database::queryOne(
                "SELECT id
                 FROM communications
                 WHERE workspace_id = ?
                   AND message_id = ?
                 LIMIT 1",
                [$workspaceId, $messageId]
            );
        }

        return !empty($existing);
    }

    private function normalizeEmailAddress(string $email): string
    {
        $email = strtolower(trim($email));
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    private function splitDisplayName(string $name, string $fallbackEmail): array
    {
        $name = trim((string) preg_replace('/\s+/', ' ', str_replace(['"', "'"], '', $name)));
        if ($name === '' || strpos($name, '@') !== false) {
            $localPart = preg_replace('/[^a-z0-9]+/i', ' ', strstr($fallbackEmail, '@', true) ?: $fallbackEmail);
            $name = trim((string) preg_replace('/\s+/', ' ', (string) $localPart));
        }

        if ($name === '') {
            return ['Unknown', ''];
        }

        $parts = preg_split('/\s+/', $name, 2) ?: [];
        $firstName = trim((string) ($parts[0] ?? 'Unknown'));
        $lastName = trim((string) ($parts[1] ?? ''));

        return [$firstName !== '' ? $firstName : 'Unknown', $lastName];
    }

    private function truncateSubjectPreview(string $subject): ?string
    {
        $subject = trim($subject);
        if ($subject === '') {
            return null;
        }

        return mb_strlen($subject) > 255 ? mb_substr($subject, 0, 252) . '...' : $subject;
    }

    private function recordImportDetail(?int $fetchLogId, array $result): void
    {
        if (($fetchLogId ?? 0) <= 0 || $this->profile === 'assistant' || !$this->tableExists('email_fetch_log_details')) {
            return;
        }

        Database::execute(
            "INSERT INTO email_fetch_log_details
             (workspace_id, fetch_log_id, message_uid, message_id, sender_email, sender_name, subject_preview, outcome, reason_code, reason_message, contact_id, communication_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $this->requireWorkspaceIdForMainInbox(),
                $fetchLogId,
                $result['message_uid'] ?? null,
                $result['message_id'] ?? null,
                $result['sender_email'] ?? null,
                $result['sender_name'] ?? null,
                $result['subject_preview'] ?? null,
                $result['outcome'] ?? 'error',
                $result['reason_code'] ?? null,
                $result['reason_message'] ?? null,
                $result['contact_id'] ?? null,
                $result['communication_id'] ?? null,
            ]
        );
    }

    private function requireWorkspaceIdForMainInbox(): int
    {
        if ($this->profile === 'assistant') {
            throw new \RuntimeException('Assistant inbox does not use workspace-scoped main inbox helpers.');
        }

        $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        if ($workspaceId <= 0) {
            throw new \RuntimeException('Email fetch requires an active runtime workspace.');
        }

        return $workspaceId;
    }

    private function assistantWorkspaceId(): int
    {
        return (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
    }

    private function allowInsecureMailTls(): bool
    {
        return filter_var($_ENV['ALLOW_INSECURE_MAIL_TLS'] ?? false, FILTER_VALIDATE_BOOL)
            || filter_var($_ENV['MAIL_ALLOW_INSECURE_TLS'] ?? false, FILTER_VALIDATE_BOOL);
    }

    private function tableExists(string $table): bool
    {
        if (array_key_exists($table, self::$tableCache)) {
            return self::$tableCache[$table];
        }

        try {
            $row = Database::queryOne(
                "SELECT COUNT(*) AS c
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?",
                [$table]
            );
            self::$tableCache[$table] = ((int) ($row['c'] ?? 0)) > 0;
        } catch (\Throwable $e) {
            self::$tableCache[$table] = false;
        }

        return self::$tableCache[$table];
    }
}
