<?php
/**
 * Email Service
 *
 * Handles email sending with queue management
 */

namespace CRM\Services;

use CRM\Database;
use CRM\Services\TargetIntelligenceService;
use CRM\Services\WorkspaceContext;

class EmailService
{
    private const NURTURE_SETUP_REQUIRED_MESSAGE = 'Configure Nurture Email before sending nurture messages.';
    private const OUTREACH_SETUP_REQUIRED_MESSAGE = 'Configure Outreach Email before sending workspace customer messages.';

    private EmailQueue $queue;
    private SMTPClient $smtp;
    private EmailTemplates $templates;
    private ColdOutreachGovernanceService $coldOutreachGovernance;
    private EmailLinkService $links;
    private static array $communicationsColumnCache = [];
    private static array $emailsColumnCache = [];

    public function __construct(
        ?SMTPClient $smtp = null,
        ?EmailQueue $queue = null,
        ?EmailTemplates $templates = null,
        ?ColdOutreachGovernanceService $coldOutreachGovernance = null,
        ?EmailLinkService $links = null
    )
    {
        $this->queue = $queue ?? new EmailQueue();
        $this->smtp = $smtp ?? new SMTPClient();
        $this->templates = $templates ?? new EmailTemplates();
        $this->coldOutreachGovernance = $coldOutreachGovernance ?? new ColdOutreachGovernanceService();
        $this->links = $links ?? new EmailLinkService();
    }

    /**
     * Send email (adds to queue)
     */
    public function send(int $contactId, string $to, string $subject, string $body, array $options = []): string
    {
        $workspaceId = $this->resolveWorkspaceId($contactId, isset($options['workspace_id']) ? (int) $options['workspace_id'] : null);
        $activeDemoSession = null;
        if ((new DemoWorkspaceService())->isDemoWorkspace($workspaceId)) {
            $activeDemoSession = (new DemoSessionScopeService())->activeSession($workspaceId);
        }
        $uuid = $this->generateUuid();
        $senderProfile = $this->normalizeSenderProfile($options['sender_profile'] ?? $options['smtp_profile'] ?? null);
        $smtp = $this->smtpForSenderProfile($senderProfile);
        if (in_array($senderProfile, ['outreach', 'nurture'], true)) {
            $this->assertWorkspaceSenderReady($senderProfile, $workspaceId);
            $fromEmail = $this->workspaceSenderFromEmail($senderProfile, $workspaceId);
            $fromName = $this->workspaceSenderFromName($senderProfile, $workspaceId);
        } else {
            $fromEmail = $options['from_email'] ?? $smtp->getPreferredFromEmail('noreply@example.com') ?? 'noreply@example.com';
            $fromName = $options['from_name'] ?? $smtp->getPreferredFromName(brandProductName()) ?? brandProductName();
        }
        $body = $this->links->normalizePlainTextLinks($body);
        $bodyHtml = (string) ($options['body_html'] ?? $body);
        if ($bodyHtml !== '') {
            $bodyHtml = $this->links->normalizeHtmlLinks($bodyHtml);
        }
        $userId = $options['user_id'] ?? ($_SESSION['user_id'] ?? null);
        $messageId = trim((string) ($options['message_id'] ?? ''));
        $inReplyTo = trim((string) ($options['in_reply_to'] ?? ''));
        $referencesHeader = trim((string) ($options['references_header'] ?? ''));
        $sourceTemplateId = (int) ($options['source_template_id'] ?? $options['template_id'] ?? 0);
        $sourceTemplateSlug = trim((string) ($options['source_template_slug'] ?? ''));
        $draftSource = trim((string) ($options['draft_source'] ?? ''));
        $draftIntention = trim((string) ($options['draft_intention'] ?? ''));
        $aiAssistantRunId = (int) ($options['ai_assistant_run_id'] ?? 0);
        $draftLearningSampleId = (int) ($options['draft_learning_sample_id'] ?? 0);

        // Inject tracking if HTML
        if (!empty($bodyHtml) && $bodyHtml !== strip_tags($bodyHtml)) {
            $bodyHtml = $this->injectTracking($bodyHtml, $uuid);
        }

        $columns = ['workspace_id', 'uuid', 'contact_id', 'user_id', 'to_email', 'from_email', 'from_name', 'subject', 'body', 'body_html', 'status'];
        $values = ['?', '?', '?', '?', '?', '?', '?', '?', '?', '?', "'pending'"];
        $params = [$workspaceId, $uuid, $contactId, $userId, $to, $fromEmail, $fromName, $subject, $body, $bodyHtml];

        if ($this->emailsColumnExists('message_id')) {
            $columns[] = 'message_id';
            $values[] = '?';
            $params[] = $messageId !== '' ? $messageId : null;
        }
        if ($this->emailsColumnExists('in_reply_to')) {
            $columns[] = 'in_reply_to';
            $values[] = '?';
            $params[] = $inReplyTo !== '' ? $inReplyTo : null;
        }
        if ($this->emailsColumnExists('references_header')) {
            $columns[] = 'references_header';
            $values[] = '?';
            $params[] = $referencesHeader !== '' ? $referencesHeader : null;
        }
        if ($this->emailsColumnExists('sender_profile')) {
            $columns[] = 'sender_profile';
            $values[] = '?';
            $params[] = $senderProfile;
        }
        if ($this->emailsColumnExists('source_template_id')) {
            $columns[] = 'source_template_id';
            $values[] = '?';
            $params[] = $sourceTemplateId > 0 ? $sourceTemplateId : null;
        }
        if ($this->emailsColumnExists('source_template_slug')) {
            $columns[] = 'source_template_slug';
            $values[] = '?';
            $params[] = $sourceTemplateSlug !== '' ? $sourceTemplateSlug : null;
        }
        if ($this->emailsColumnExists('draft_source')) {
            $columns[] = 'draft_source';
            $values[] = '?';
            $params[] = $draftSource !== '' ? $draftSource : null;
        }
        if ($this->emailsColumnExists('draft_intention')) {
            $columns[] = 'draft_intention';
            $values[] = '?';
            $params[] = $draftIntention !== '' ? $draftIntention : null;
        }
        if ($this->emailsColumnExists('ai_assistant_run_id')) {
            $columns[] = 'ai_assistant_run_id';
            $values[] = '?';
            $params[] = $aiAssistantRunId > 0 ? $aiAssistantRunId : null;
        }
        if ($activeDemoSession !== null && $this->emailsColumnExists('demo_visibility') && $this->emailsColumnExists('demo_session_id')) {
            $columns[] = 'demo_visibility';
            $values[] = '?';
            $params[] = 'session_private';
            $columns[] = 'demo_session_id';
            $values[] = '?';
            $params[] = (int) ($activeDemoSession['id'] ?? 0);
        }

        Database::execute(
            "INSERT INTO emails (" . implode(', ', $columns) . ")
             VALUES (" . implode(', ', $values) . ")",
            $params
        );

        $emailId = (int) Database::lastInsertId();
        if ($activeDemoSession !== null) {
            (new DemoSessionScopeService())->registerEntity((int) ($activeDemoSession['id'] ?? 0), $workspaceId, 'emails', $emailId);
        }
        $learningSamples = new EmailTemplateLearningSampleService();
        $learningSamples->recordOutboundEmail(
            $workspaceId,
            $userId !== null ? (int) $userId : null,
            $contactId,
            $emailId,
            $subject,
            $body,
            (string) $bodyHtml,
            $options
        );
        if ($draftLearningSampleId > 0) {
            $learningSamples->markDraftOutcomeForSend(
                $workspaceId,
                $userId !== null ? (int) $userId : null,
                $draftLearningSampleId,
                $emailId,
                null,
                $subject,
                $body,
                (string) $bodyHtml
            );
        }

        // Add to queue unless explicitly disabled (used by sendImmediate)
        $shouldQueue = !array_key_exists('queue', $options) || (bool) $options['queue'] === true;
        if ($shouldQueue) {
            $scheduledAt = $options['scheduled_at'] ?? null;
            if ($emailId > 0) {
                $this->queue->push($emailId, $options['priority'] ?? 0, $scheduledAt, $workspaceId);
            }
        }

        return $uuid;
    }

    /**
     * Send email using template
     */
    public function sendWithTemplate(int $contactId, string $templateSlug, array $variables = [], array $options = []): string
    {
        $contact = Database::queryOne(
            "SELECT * FROM contacts WHERE workspace_id = ? AND id = ?",
            [$this->resolveWorkspaceId($contactId), $contactId]
        );

        if (!$contact) {
            throw new \Exception("Contact not found: $contactId");
        }

        $allVariables = array_merge([
            'first_name' => trim((string) ($contact['first_name'] ?? '')) !== '' ? $contact['first_name'] : 'there',
            'last_name' => $contact['last_name'] ?? '',
            'email' => $contact['email'] ?? '',
            'phone' => $contact['phone'] ?? '',
            'company' => $contact['company'] ?? '',
            'contact_id' => $contactId
        ], $variables);

        $templateId = (int) ($options['template_id'] ?? 0);
        if ($templateId > 0) {
            $template = $this->templates->getById($templateId);
            if (!$template) {
                throw new \Exception("Template not found: #$templateId");
            }
            $rendered = $this->templates->renderTemplateRecord($template, $allVariables, '#' . $templateId);
        } else {
            $rendered = $this->templates->render($templateSlug, $allVariables);
            $template = $this->templates->getBySlug($templateSlug);
        }
        if ($template && isset($template['id'])) {
            $this->templates->incrementUsageCount($template['id']);
        }
        $templateOptions = [
            'source_template_id' => (int) ($template['id'] ?? 0),
            'source_template_slug' => (string) ($template['slug'] ?? $templateSlug),
            'draft_source' => 'template',
            'learning_source' => (string) ($options['learning_source'] ?? (!empty($options['workflow_id']) || !empty($options['workflow_run_id']) ? 'workflow_send' : 'template_send')),
            'learning_intent_key' => (string) ($template['template_key'] ?? $template['purpose'] ?? $template['category'] ?? ''),
            'template_key' => (string) ($template['template_key'] ?? ''),
        ];

        return $this->send(
            $contactId,
            $allVariables['email'] ?: $options['to'] ?? '',
            $rendered['subject'],
            $rendered['body_text'],
            array_merge($options, $templateOptions, ['body_html' => $rendered['body_html']])
        );
    }

    /**
     * Send email immediately (bypass queue).
     */
    public function sendImmediate(int $contactId, string $to, string $subject, string $body, array $options = []): bool
    {
        $result = $this->sendImmediateDetailed($contactId, $to, $subject, $body, $options);
        if (!empty($result['success'])) {
            return true;
        }

        $msg = trim((string) ($result['error'] ?? ''));
        if ($msg === '') {
            $msg = 'Failed to send email. Check Settings -> Email for SMTP configuration and try again.';
        }
        throw new \Exception($msg);
    }

    /**
     * Send email immediately and return delivery details.
     *
     * @return array<string,mixed>
     */
    public function sendImmediateDetailed(int $contactId, string $to, string $subject, string $body, array $options = []): array
    {
        $workspaceId = $this->resolveWorkspaceId($contactId, isset($options['workspace_id']) ? (int) $options['workspace_id'] : null);
        $immediateOptions = $options;
        $immediateOptions['queue'] = false;
        $immediateOptions['workspace_id'] = $workspaceId;
        $senderProfile = $this->normalizeSenderProfile($options['sender_profile'] ?? $options['smtp_profile'] ?? null);
        $smtp = $this->smtpForSenderProfile($senderProfile);
        $uuid = $this->send($contactId, $to, $subject, $body, $immediateOptions);

        $email = Database::queryOne(
            "SELECT * FROM emails WHERE uuid = ? AND workspace_id = ?",
            [$uuid, $workspaceId]
        );

        if (!$email) {
            return [
                'success' => false,
                'error' => 'Email record could not be created.',
                'email_uuid' => $uuid,
                'provider_key' => $smtp->getLastProviderKey() ?? $smtp->getActiveProviderKey(),
                'smtp_profile' => $smtp->getProfileKey(),
                'smtp_method' => $smtp->getLastMethodUsed(),
            ];
        }

        $lastError = null;
        $emailId = (int) ($email['id'] ?? 0);
        $result = $emailId > 0
            ? $this->processEmailDetailed($emailId, $lastError, $immediateOptions, $workspaceId)
            : $this->processEmailByUuidDetailed($uuid, $lastError, $immediateOptions, $workspaceId);
        if (!empty($result['success'])) {
            return $result;
        }

        $state = $emailId > 0
            ? Database::queryOne("SELECT status FROM emails WHERE id = ? AND workspace_id = ?", [$emailId, $workspaceId])
            : Database::queryOne("SELECT status FROM emails WHERE uuid = ? AND workspace_id = ?", [$uuid, $workspaceId]);
        if (($state['status'] ?? null) === 'sent') {
            $result['success'] = true;
            $result['status'] = 'sent';
            return $result;
        }

        $row = $emailId > 0
            ? Database::queryOne("SELECT error_message FROM emails WHERE id = ? AND workspace_id = ?", [$emailId, $workspaceId])
            : Database::queryOne("SELECT error_message FROM emails WHERE uuid = ? AND workspace_id = ?", [$uuid, $workspaceId]);
        $dbMsg = isset($row['error_message']) && trim((string) $row['error_message']) !== ''
            ? trim($row['error_message'])
            : null;
        $result['success'] = false;
        $result['error'] = $lastError ?: $dbMsg ?: 'Failed to send email. Check Settings -> Email for SMTP configuration and try again.';
        return $result;
    }

    /**
     * Process email by numeric ID.
     */
    public function processEmail(int $emailId, ?string &$lastError = null, array $runtimeOptions = [], ?int $workspaceId = null): bool
    {
        $result = $this->processEmailDetailed($emailId, $lastError, $runtimeOptions, $workspaceId);
        return !empty($result['success']);
    }

    /**
     * Process email by numeric ID and return delivery details.
     *
     * @return array<string,mixed>
     */
    public function processEmailDetailed(int $emailId, ?string &$lastError = null, array $runtimeOptions = [], ?int $workspaceId = null): array
    {
        $lastError = null;
        if ($emailId <= 0) {
            $lastError = 'Invalid email ID.';
            return ['success' => false, 'error' => $lastError];
        }

        $emailSql = "SELECT * FROM emails WHERE id = ?";
        $emailParams = [$emailId];
        $filterWorkspaceId = $this->activeWorkspaceFilter($workspaceId);
        if ($filterWorkspaceId !== null && $filterWorkspaceId > 0) {
            $emailSql .= " AND workspace_id = ?";
            $emailParams[] = $filterWorkspaceId;
        }
        $email = Database::queryOne($emailSql, $emailParams);

        if (!$email || ($email['status'] ?? null) !== 'pending') {
            $lastError = 'Email not found or already processed.';
            return ['success' => false, 'error' => $lastError];
        }

        return $this->processLoadedEmailDetailed($email, $lastError, $runtimeOptions, $filterWorkspaceId);
    }

    /**
     * Process email by UUID (fallback for broken imported IDs).
     */
    public function processEmailByUuid(string $uuid, ?string &$lastError = null, array $runtimeOptions = [], ?int $workspaceId = null): bool
    {
        $result = $this->processEmailByUuidDetailed($uuid, $lastError, $runtimeOptions, $workspaceId);
        return !empty($result['success']);
    }

    /**
     * Process email by UUID (fallback for broken imported IDs) and return delivery details.
     *
     * @return array<string,mixed>
     */
    public function processEmailByUuidDetailed(string $uuid, ?string &$lastError = null, array $runtimeOptions = [], ?int $workspaceId = null): array
    {
        $lastError = null;
        $uuid = trim($uuid);
        if ($uuid === '') {
            $lastError = 'Invalid email UUID.';
            return ['success' => false, 'error' => $lastError];
        }

        $emailSql = "SELECT * FROM emails WHERE uuid = ?";
        $emailParams = [$uuid];
        $filterWorkspaceId = $this->activeWorkspaceFilter($workspaceId);
        if ($filterWorkspaceId !== null && $filterWorkspaceId > 0) {
            $emailSql .= " AND workspace_id = ?";
            $emailParams[] = $filterWorkspaceId;
        }
        $emailSql .= " LIMIT 1";
        $email = Database::queryOne($emailSql, $emailParams);

        if (!$email || ($email['status'] ?? null) !== 'pending') {
            $lastError = 'Email not found or already processed.';
            return ['success' => false, 'error' => $lastError];
        }

        return $this->processLoadedEmailDetailed($email, $lastError, $runtimeOptions, $filterWorkspaceId);
    }

    /**
     * @return array<string,mixed>
     */
    private function processLoadedEmailDetailed(array $email, ?string &$lastError = null, array $runtimeOptions = [], ?int $workspaceId = null): array
    {
        $queueClaimManaged = !empty($runtimeOptions['queue_claim_managed']);
        $lastError = null;
        $emailId = (int) ($email['id'] ?? 0);
        $emailUuid = (string) ($email['uuid'] ?? '');
        $workspaceId = $workspaceId !== null && $workspaceId > 0
            ? $workspaceId
            : (int) ($email['workspace_id'] ?? 0);
        $senderProfile = $this->normalizeSenderProfile($runtimeOptions['sender_profile'] ?? $email['sender_profile'] ?? null);
        $smtp = $this->smtpForSenderProfile($senderProfile);
        $attachments = [];
        if (!empty($runtimeOptions['attachments']) && is_array($runtimeOptions['attachments'])) {
            $attachments = array_values(array_filter(
                $runtimeOptions['attachments'],
                static fn ($path): bool => is_string($path) && $path !== '' && file_exists($path)
            ));
        }

        $toEmail = trim((string) ($email['to_email'] ?? ''));
        if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            $err = 'Recipient email is missing or invalid.';
            $lastError = $err;
            if ($emailId > 0) {
                Database::execute("UPDATE emails SET status = 'failed', error_message = ? WHERE workspace_id = ? AND id = ?", [$err, $workspaceId, $emailId]);
                $this->coldOutreachGovernance->markByEntity('email', $emailId, 'failed');
            } elseif ($emailUuid !== '') {
                Database::execute("UPDATE emails SET status = 'failed', error_message = ? WHERE workspace_id = ? AND uuid = ?", [$err, $workspaceId, $emailUuid]);
            }
            return [
                'success' => false,
                'error' => $err,
                'email_id' => $emailId,
                'email_uuid' => $emailUuid,
                'status' => 'failed',
                'provider_key' => $smtp->getLastProviderKey() ?? $smtp->getActiveProviderKey(),
                'smtp_profile' => $smtp->getProfileKey(),
                'smtp_method' => $smtp->getLastMethodUsed(),
            ];
        }

        $marketingSuppressionBlock = $this->blockSuppressedMarketingLiveEmailHandoffIfNeeded($email, $workspaceId, $toEmail);
        if ($marketingSuppressionBlock !== null) {
            $lastError = (string) ($marketingSuppressionBlock['error'] ?? 'Marketing live email handoff blocked by suppression.');
            return $marketingSuppressionBlock;
        }

        $fromEmail = trim((string) ($email['from_email'] ?? ''));
        $fromName = trim((string) ($email['from_name'] ?? ''));
        if (in_array($senderProfile, ['outreach', 'nurture'], true)) {
            try {
                $this->assertWorkspaceSenderReady($senderProfile, $workspaceId);
                $fromEmail = $this->workspaceSenderFromEmail($senderProfile, $workspaceId);
                $fromName = $this->workspaceSenderFromName($senderProfile, $workspaceId);
            } catch (\RuntimeException $e) {
                $err = $e->getMessage();
                $lastError = $err;
                if ($emailId > 0) {
                    Database::execute("UPDATE emails SET status = 'failed', error_message = ? WHERE workspace_id = ? AND id = ?", [$err, $workspaceId, $emailId]);
                    $this->coldOutreachGovernance->markByEntity('email', $emailId, 'failed');
                    if (!$queueClaimManaged) {
                        try {
                            Database::execute("UPDATE email_queue SET status = 'failed', error_message = ? WHERE workspace_id = ? AND email_id = ?", [$err, $workspaceId, $emailId]);
                        } catch (\Throwable $queueEx) {
                            error_log("EmailService::processEmail queue failure update skipped for email_id={$emailId}: " . $queueEx->getMessage());
                        }
                    }
                } elseif ($emailUuid !== '') {
                    Database::execute("UPDATE emails SET status = 'failed', error_message = ? WHERE workspace_id = ? AND uuid = ?", [$err, $workspaceId, $emailUuid]);
                }
                return [
                    'success' => false,
                    'error' => $err,
                    'email_id' => $emailId,
                    'email_uuid' => $emailUuid,
                    'status' => 'failed',
                    'provider_key' => $smtp->getLastProviderKey() ?? $smtp->getActiveProviderKey(),
                    'smtp_profile' => $smtp->getProfileKey(),
                    'smtp_method' => $smtp->getLastMethodUsed(),
                    'from_email' => $fromEmail,
                    'to_email' => $toEmail,
                ];
            }
        } else {
            if ($fromEmail === '') {
                $fromEmail = $smtp->getPreferredFromEmail('noreply@example.com') ?? 'noreply@example.com';
            }
            if ($fromName === '') {
                $fromName = $smtp->getPreferredFromName(brandProductName()) ?? brandProductName();
            }
        }

        try {
            // Demo mode: simulate send and skip external SMTP delivery.
            $demoSimulation = false;
            try {
                $state = Database::queryOne('SELECT is_enabled, simulation_only FROM demo_mode_state WHERE id = 1 LIMIT 1');
                $demoSimulation = (new DemoWorkspaceService())->isDemoWorkspace($workspaceId)
                    || (
                        ((int) ($state['is_enabled'] ?? 0)) === 1
                        && ((int) ($state['simulation_only'] ?? 1)) === 1
                    );
            } catch (\Throwable $demoError) {
                $demoSimulation = (new DemoWorkspaceService())->isDemoWorkspace($workspaceId);
            }

            if ($demoSimulation) {
                $simulatedMessage = 'Simulated send in demo mode';
                $sentAtNow = date('Y-m-d H:i:s');
                if ($emailId > 0) {
                    Database::execute(
                        "UPDATE emails SET status = 'sent', sent_at = ?, error_message = ? WHERE workspace_id = ? AND id = ?",
                        [$sentAtNow, $simulatedMessage, $workspaceId, $emailId]
                    );
                    $this->coldOutreachGovernance->markByEntity('email', $emailId, 'sent');
                } elseif ($emailUuid !== '') {
                    Database::execute(
                        "UPDATE emails SET status = 'sent', sent_at = ?, error_message = ? WHERE workspace_id = ? AND uuid = ?",
                        [$sentAtNow, $simulatedMessage, $workspaceId, $emailUuid]
                    );
                }

                if ($emailId > 0 && !$queueClaimManaged) {
                    try {
                        Database::execute(
                            "UPDATE email_queue SET status = 'completed', processed_at = ?, error_message = ? WHERE workspace_id = ? AND email_id = ?",
                            [$sentAtNow, $simulatedMessage, $workspaceId, $emailId]
                        );
                    } catch (\Throwable $queueEx) {
                        error_log("EmailService::processEmail demo queue completion update skipped for email_id={$emailId}: " . $queueEx->getMessage());
                    }
                }
                if ($emailId > 0) {
                    $email['sent_at'] = $sentAtNow;
                    $this->syncMarketingLiveEmailHandoffDelivery($workspaceId, $emailId, 'sent', 'completed', 'sent', 'completed', 'Marketing live email handoff sent in demo simulation.');
                }
                $communicationId = 0;
                $syncError = null;
                if ($emailId > 0) {
                    try {
                        $communicationId = $this->syncToCommunicationsDetailed($email);
                    } catch (\Throwable $syncEx) {
                        $syncError = trim((string) $syncEx->getMessage());
                        error_log("EmailService::processEmail demo communications sync failed for email_id={$emailId}: " . $syncError);
                    }
                    (new EmailTemplateLearningSampleService())->markEmailSent($emailId, $communicationId > 0 ? $communicationId : null);
                }

                return [
                    'success' => true,
                    'email_id' => $emailId,
                    'email_uuid' => $emailUuid,
                    'status' => 'sent',
                    'provider_key' => $smtp->getLastProviderKey() ?? $smtp->getActiveProviderKey(),
                    'smtp_profile' => $smtp->getProfileKey(),
                    'smtp_method' => 'demo_simulation',
                    'communication_id' => $communicationId,
                    'sync_error' => $syncError,
                    'message_id' => (string) ($email['message_id'] ?? ''),
                    'in_reply_to' => (string) ($email['in_reply_to'] ?? ''),
                    'references_header' => (string) ($email['references_header'] ?? ''),
                    'from_email' => $fromEmail,
                    'to_email' => $toEmail,
                ];
            }

            $customHeaders = [];
            if (!empty($email['message_id'])) {
                $customHeaders['Message-ID'] = (string) $email['message_id'];
            }
            if (!empty($email['in_reply_to'])) {
                $customHeaders['In-Reply-To'] = (string) $email['in_reply_to'];
            }
            if (!empty($email['references_header'])) {
                $customHeaders['References'] = (string) $email['references_header'];
            }

            if ($customHeaders !== []) {
                $smtp->sendWithHeaders(
                    $toEmail,
                    $fromEmail,
                    $fromName,
                    $email['subject'],
                    $email['body'] ?? '',
                    $attachments,
                    $email['body_html'] ?? null,
                    $customHeaders
                );
            } else {
                $smtp->send(
                    $toEmail,
                    $fromEmail,
                    $fromName,
                    $email['subject'],
                    $email['body'] ?? '',
                    $attachments,
                    $email['body_html'] ?? null
                );
            }

            if ($emailId > 0) {
                // Persist outbound email event time in app timezone (defaults to Africa/Nairobi).
                $sentAtNow = date('Y-m-d H:i:s');
                Database::execute(
                    "UPDATE emails SET status = 'sent', sent_at = ? WHERE workspace_id = ? AND id = ?",
                    [$sentAtNow, $workspaceId, $emailId]
                );
                $this->coldOutreachGovernance->markByEntity('email', $emailId, 'sent');
                // Keep a DB-authoritative sent_at for downstream communications sync.
                $sentRow = Database::queryOne(
                    "SELECT sent_at FROM emails WHERE workspace_id = ? AND id = ? LIMIT 1",
                    [$workspaceId, $emailId]
                );
                if (!empty($sentRow['sent_at'])) {
                    $email['sent_at'] = $sentRow['sent_at'];
                } else {
                    $email['sent_at'] = $sentAtNow;
                }
            } elseif ($emailUuid !== '') {
                $sentAtNow = date('Y-m-d H:i:s');
                Database::execute(
                    "UPDATE emails SET status = 'sent', sent_at = ? WHERE workspace_id = ? AND uuid = ?",
                    [$sentAtNow, $workspaceId, $emailUuid]
                );
                $sentRow = Database::queryOne(
                    "SELECT sent_at FROM emails WHERE workspace_id = ? AND uuid = ? LIMIT 1",
                    [$workspaceId, $emailUuid]
                );
                if (!empty($sentRow['sent_at'])) {
                    $email['sent_at'] = $sentRow['sent_at'];
                } else {
                    $email['sent_at'] = $sentAtNow;
                }
            }

            if ($emailId > 0 && !$queueClaimManaged) {
                try {
                    Database::execute(
                        "UPDATE email_queue SET status = 'completed', processed_at = ? WHERE workspace_id = ? AND email_id = ?",
                        [date('Y-m-d H:i:s'), $workspaceId, $emailId]
                    );
                } catch (\Throwable $queueEx) {
                    error_log("EmailService::processEmail queue completion update skipped for email_id={$emailId}: " . $queueEx->getMessage());
                }
            }
            if ($emailId > 0) {
                $this->syncMarketingLiveEmailHandoffDelivery($workspaceId, $emailId, 'sent', 'completed', 'sent', 'completed', 'Marketing live email handoff sent by email worker.');
            }

            $communicationId = 0;
            $syncError = null;
            if ($emailId > 0) {
                try {
                    $communicationId = $this->syncToCommunicationsDetailed($email);
                } catch (\Throwable $syncEx) {
                    $syncError = trim((string) $syncEx->getMessage());
                    error_log("EmailService::processLoadedEmail communications sync failed for email_id={$emailId}: " . $syncError);
                }
                (new EmailTemplateLearningSampleService())->markEmailSent($emailId, $communicationId > 0 ? $communicationId : null);
            } else {
                error_log("EmailService::processLoadedEmail skipped communications sync for uuid={$emailUuid} due to non-positive id");
            }

            return [
                'success' => true,
                'email_id' => $emailId,
                'email_uuid' => $emailUuid,
                'status' => 'sent',
                'provider_key' => $smtp->getLastProviderKey() ?? $smtp->getActiveProviderKey(),
                'smtp_profile' => $smtp->getProfileKey(),
                'smtp_method' => $smtp->getLastMethodUsed(),
                'communication_id' => $communicationId,
                'sync_error' => $syncError,
                'message_id' => (string) ($email['message_id'] ?? ''),
                'in_reply_to' => (string) ($email['in_reply_to'] ?? ''),
                'references_header' => (string) ($email['references_header'] ?? ''),
                'from_email' => $fromEmail,
                'to_email' => $toEmail,
            ];
        } catch (\Throwable $e) {
            $errMsg = trim((string) $e->getMessage());
            if ($errMsg === '') {
                $errMsg = get_class($e) . ' (no message)';
            }
            $lastError = $errMsg;
            error_log("EmailService::processEmail failed for email_id={$emailId}: " . $errMsg);

            if ($emailId > 0) {
                Database::execute(
                    "UPDATE emails SET status = 'failed', error_message = ? WHERE workspace_id = ? AND id = ?",
                    [$errMsg, $workspaceId, $emailId]
                );
                $this->coldOutreachGovernance->markByEntity('email', $emailId, 'failed');
            } elseif ($emailUuid !== '') {
                Database::execute(
                    "UPDATE emails SET status = 'failed', error_message = ? WHERE workspace_id = ? AND uuid = ?",
                    [$errMsg, $workspaceId, $emailUuid]
                );
            }
            if ($emailId > 0 && !$queueClaimManaged) {
                try {
                    Database::execute(
                        "UPDATE email_queue SET status = 'failed', error_message = ? WHERE workspace_id = ? AND email_id = ?",
                        [$errMsg, $workspaceId, $emailId]
                    );
                } catch (\Throwable $queueEx) {
                    error_log("EmailService::processEmail queue failure update skipped for email_id={$emailId}: " . $queueEx->getMessage());
                }
            }
            if ($emailId > 0) {
                $this->syncMarketingLiveEmailHandoffDelivery($workspaceId, $emailId, 'failed', 'blocked', 'failed', 'failed', $errMsg);
            }
            return [
                'success' => false,
                'error' => $errMsg,
                'email_id' => $emailId,
                'email_uuid' => $emailUuid,
                'status' => 'failed',
                'provider_key' => $smtp->getLastProviderKey() ?? $smtp->getActiveProviderKey(),
                'smtp_profile' => $smtp->getProfileKey(),
                'smtp_method' => $smtp->getLastMethodUsed(),
                'message_id' => (string) ($email['message_id'] ?? ''),
                'in_reply_to' => (string) ($email['in_reply_to'] ?? ''),
                'references_header' => (string) ($email['references_header'] ?? ''),
                'from_email' => $fromEmail,
                'to_email' => $toEmail,
            ];
        }
    }

    /**
     * Inject tracking pixel and rewrite links
     */
    private function injectTracking(string $html, string $emailUuid): string
    {
        $openUrl = $this->links->absoluteApiUrl('track/email/open/' . rawurlencode($emailUuid));
        $trackingPixel = '<img src="' . htmlspecialchars($openUrl, ENT_QUOTES, 'UTF-8') . '" width="1" height="1" style="display:none;" alt="" />';

        $html = preg_replace_callback(
            '/href=["\']([^"\']+)["\']/i',
            function ($matches) use ($emailUuid) {
                $url = $this->links->normalizeHref((string) ($matches[1] ?? ''));
                if ($url === null || preg_match('#^(mailto|tel):#i', $url) === 1) {
                    return $matches[0];
                }
                if (str_contains($url, '/api/track/email/click/')) {
                    return 'href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"';
                }
                $trackedUrl = $this->links->absoluteApiUrl('track/email/click/' . rawurlencode($emailUuid))
                    . '?url=' . rawurlencode($url);
                return 'href="' . htmlspecialchars($trackedUrl, ENT_QUOTES, 'UTF-8') . '"';
            },
            $html
        );

        if (strpos($html, '</body>') !== false) {
            $html = str_replace('</body>', $trackingPixel . '</body>', $html);
        } else {
            $html .= $trackingPixel;
        }

        return $html;
    }

    /**
     * Sync sent email to communications table
     */
    private function syncToCommunications(array $email): void
    {
        try {
            $this->syncToCommunicationsDetailed($email);
        } catch (\Exception $e) {
            error_log("Error syncing email to communications: " . $e->getMessage());
        }
    }

    /**
     * Sync sent email to communications and return the outbound communication ID.
     */
    private function syncToCommunicationsDetailed(array $email): int
    {
        if ((int) ($email['contact_id'] ?? 0) <= 0) {
            return 0;
        }

        $hasEmailIdColumn = $this->communicationsColumnExists('email_id');
        if ($hasEmailIdColumn) {
            $existing = Database::queryOne(
                "SELECT id FROM communications WHERE workspace_id = ? AND email_id = ?",
                [$this->resolveEmailWorkspaceId($email), $email['id']]
            );
            if ($existing) {
                return (int) ($existing['id'] ?? 0);
            }
        } else {
            $existing = Database::queryOne(
                "SELECT id
                 FROM communications
                 WHERE workspace_id = ?
                   AND channel = 'email'
                   AND direction = 'outbound'
                   AND contact_id = ?
                   AND subject = ?
                   AND ABS(TIMESTAMPDIFF(SECOND, created_at, ?)) <= 300
                 ORDER BY id DESC
                 LIMIT 1",
                [
                    $this->resolveEmailWorkspaceId($email),
                    $email['contact_id'],
                    $email['subject'] ?? '',
                    $email['sent_at'] ?? ($email['created_at'] ?? gmdate('Y-m-d H:i:s'))
                ]
            );
            if ($existing) {
                return (int) ($existing['id'] ?? 0);
            }
        }

        $bodyText = (string) ($email['body'] ?? '');
        if (!empty($email['body_html'])) {
            $bodyText = strip_tags((string) $email['body_html']);
            $bodyText = html_entity_decode($bodyText);
            $bodyText = (string) preg_replace('/\s+/', ' ', $bodyText);
            $bodyText = trim($bodyText);
        }

        $commUuid = $this->generateUuid();
        $metadata = [
            'body_html' => $email['body_html'] ?? null,
            'from_name' => $email['from_name'] ?? null,
            'email_uuid' => $email['uuid'] ?? null,
        ];
        if (!empty($email['references_header'])) {
            $metadata['references_header'] = $email['references_header'];
        }
        $textForProposal = strtolower((string) (($email['subject'] ?? '') . ' ' . $bodyText));
        if (preg_match('/\b(proposal|quote|quotation|estimate)\b/', $textForProposal)) {
            $metadata['purpose'] = 'proposal';
        }

        $columns = ['workspace_id', 'uuid', 'contact_id', 'channel', 'direction', 'subject', 'body', 'status', 'metadata', 'created_at'];
        $values = ['?', '?', '?', "'email'", "'outbound'", '?', '?', "'sent'", '?', '?'];
        $params = [
            $this->resolveEmailWorkspaceId($email),
            $commUuid,
            $email['contact_id'],
            $email['subject'],
            $bodyText,
            json_encode($metadata),
            $email['sent_at'] ?? ($email['created_at'] ?? gmdate('Y-m-d H:i:s'))
        ];
        $emailDemoSessionId = (int) ($email['demo_session_id'] ?? 0);
        if ($emailDemoSessionId > 0 && $this->communicationsColumnExists('demo_visibility') && $this->communicationsColumnExists('demo_session_id')) {
            $columns[] = 'demo_visibility';
            $values[] = '?';
            $params[] = 'session_private';
            $columns[] = 'demo_session_id';
            $values[] = '?';
            $params[] = $emailDemoSessionId;
        }

        if ($hasEmailIdColumn) {
            $columns[] = 'email_id';
            $values[] = '?';
            $params[] = $email['id'];
        }
        if ($this->communicationsColumnExists('from_email')) {
            $columns[] = 'from_email';
            $values[] = '?';
            $params[] = $email['from_email'] ?? '';
        }
        if ($this->communicationsColumnExists('to_email')) {
            $columns[] = 'to_email';
            $values[] = '?';
            $params[] = $email['to_email'] ?? '';
        }
        if ($this->communicationsColumnExists('message_id')) {
            $columns[] = 'message_id';
            $values[] = '?';
            $params[] = ($email['message_id'] ?? '') !== '' ? $email['message_id'] : null;
        }
        if ($this->communicationsColumnExists('in_reply_to')) {
            $columns[] = 'in_reply_to';
            $values[] = '?';
            $params[] = ($email['in_reply_to'] ?? '') !== '' ? $email['in_reply_to'] : null;
        }

        $sql = "INSERT INTO communications (" . implode(', ', $columns) . ")
                VALUES (" . implode(', ', $values) . ")";
        Database::execute($sql, $params);
        $commId = (int) Database::lastInsertId();
        if ($emailDemoSessionId > 0) {
            (new DemoSessionScopeService())->registerEntity($emailDemoSessionId, $this->resolveEmailWorkspaceId($email), 'communications', $commId);
        }

        try {
            (new ConversationIntelligenceService())->syncForCommunication($commId);
        } catch (\Throwable $e) {
            error_log("EmailService: Conversation intelligence sync failed: " . $e->getMessage());
        }
        try {
            (new ContactIntelligenceService())->computeAndPersist((int) $email['contact_id']);
        } catch (\Throwable $e) {
            error_log("EmailService: Contact intelligence refresh failed: " . $e->getMessage());
        }
        try {
            (new TargetIntelligenceService())->refreshAfterEntityChange('communications', $commId, [
                'contact_id' => (int) ($email['contact_id'] ?? 0),
                'channel' => 'email',
            ]);
        } catch (\Throwable $e) {
            error_log("EmailService: Target intelligence refresh failed: " . $e->getMessage());
        }
        if (!empty($metadata['purpose']) && $metadata['purpose'] === 'proposal' && $commId > 0) {
            try {
                $orchestrator = new DealAutomationOrchestrator();
                $orchestrator->runForProposalSent((int) $email['contact_id'], $commId);
            } catch (\Throwable $e) {
                error_log("Deal automation (proposal): " . $e->getMessage());
            }
        }

        return $commId;
    }

    /**
     * Ensure one sent email is present in communications immediately.
     */
    public function syncSentEmailRecord(int $emailId, ?int $workspaceId = null): bool
    {
        if ($emailId <= 0) {
            return false;
        }

        $filterWorkspaceId = $this->activeWorkspaceFilter($workspaceId);
        $sql = "SELECT *
             FROM emails
             WHERE id = ?";
        $params = [$emailId];
        if ($filterWorkspaceId !== null && $filterWorkspaceId > 0) {
            $sql .= " AND workspace_id = ?";
            $params[] = $filterWorkspaceId;
        }
        $sql .= " LIMIT 1";

        $email = Database::queryOne(
            $sql,
            $params
        );
        if (!$email || ($email['status'] ?? null) !== 'sent') {
            return false;
        }

        $this->syncToCommunications($email);
        return true;
    }

    /**
     * Backfill missing sent email records into communications.
     */
    public function syncMissingSentEmails(int $limit = 200, ?int $workspaceId = null): int
    {
        $limit = max(1, min(1000, $limit));
        $filterWorkspaceId = $this->activeWorkspaceFilter($workspaceId);
        $workspaceSql = '';
        $workspaceParams = [];
        if ($filterWorkspaceId !== null && $filterWorkspaceId > 0) {
            $workspaceSql = " AND e.workspace_id = ?";
            $workspaceParams[] = $filterWorkspaceId;
        }

        $hasEmailIdColumn = $this->communicationsColumnExists('email_id');
        if ($hasEmailIdColumn) {
            $rows = Database::query(
                "SELECT e.*
                 FROM emails e
                 LEFT JOIN communications c
                   ON c.email_id = e.id
                  AND c.workspace_id = e.workspace_id
                 WHERE e.status = 'sent'
                   {$workspaceSql}
                   AND c.id IS NULL
                 ORDER BY e.sent_at DESC, e.created_at DESC
                 LIMIT ?",
                array_merge($workspaceParams, [$limit])
            );
        } else {
            // Legacy schema fallback: no direct FK column available.
            $rows = Database::query(
                "SELECT e.*
                 FROM emails e
                 WHERE e.status = 'sent'
                   {$workspaceSql}
                 ORDER BY e.sent_at DESC, e.created_at DESC
                 LIMIT ?",
                array_merge($workspaceParams, [$limit])
            );
        }

        $synced = 0;
        foreach ($rows as $email) {
            try {
                $this->syncToCommunications($email);
                $synced++;
            } catch (\Throwable $e) {
                error_log("EmailService::syncMissingSentEmails failed for email_id=" . ($email['id'] ?? 'unknown') . ": " . $e->getMessage());
            }
        }

        return $synced;
    }

    private function normalizeSenderProfile($profile): string
    {
        return match (strtolower(trim((string) $profile))) {
            'assistant', 'email_assistant', 'assistant_email' => 'assistant',
            'outreach', 'outbound', 'sales', 'outreach_email' => 'outreach',
            'nurture', 'followup', 'follow_up', 'nurture_email' => 'nurture',
            default => 'default',
        };
    }

    private function activeWorkspaceFilter(?int $workspaceId = null): ?int
    {
        if ($workspaceId !== null && $workspaceId > 0) {
            return $workspaceId;
        }

        $contextWorkspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        return $contextWorkspaceId > 0 ? $contextWorkspaceId : null;
    }

    private function smtpForSenderProfile(string $profile): SMTPClient
    {
        if ($profile === 'default' && $this->smtp->getProfileKey() === 'default') {
            return $this->smtp;
        }

        if ($profile !== 'default' && $this->smtp->getProfileKey() === $profile) {
            return $this->smtp;
        }

        return $profile === 'default' ? new SMTPClient() : new SMTPClient($profile);
    }

    private function assertWorkspaceSenderReady(string $profile, int $workspaceId): void
    {
        if ($workspaceId <= 0 || !(new EmailIntegrationService())->isStrictRoleOutboundReady($profile, $workspaceId)) {
            throw new \RuntimeException($profile === 'nurture' ? self::NURTURE_SETUP_REQUIRED_MESSAGE : self::OUTREACH_SETUP_REQUIRED_MESSAGE);
        }
    }

    private function workspaceSenderFromEmail(string $profile, int $workspaceId): string
    {
        $email = (new EmailIntegrationService())->getStrictPreferredFromEmailForRole($profile, $workspaceId);
        if ($email === '') {
            throw new \RuntimeException($profile === 'nurture' ? self::NURTURE_SETUP_REQUIRED_MESSAGE : self::OUTREACH_SETUP_REQUIRED_MESSAGE);
        }

        return $email;
    }

    private function workspaceSenderFromName(string $profile, int $workspaceId): string
    {
        return (new EmailIntegrationService())->getStrictPreferredFromNameForRole($profile, brandProductName(), $workspaceId);
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

    private function emailsColumnExists(string $column): bool
    {
        if (array_key_exists($column, self::$emailsColumnCache)) {
            return self::$emailsColumnCache[$column];
        }

        try {
            $row = Database::queryOne(
                "SELECT COUNT(*) AS count
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'emails'
                   AND COLUMN_NAME = ?",
                [$column]
            );
            $exists = ((int) ($row['count'] ?? 0)) > 0;
            self::$emailsColumnCache[$column] = $exists;
            return $exists;
        } catch (\Throwable $e) {
            self::$emailsColumnCache[$column] = false;
            return false;
        }
    }

    private function resolveWorkspaceId(int $contactId, ?int $workspaceId = null): int
    {
        if ($workspaceId !== null && $workspaceId > 0) {
            return $workspaceId;
        }

        $contextWorkspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        if ($contextWorkspaceId > 0) {
            return $contextWorkspaceId;
        }

        $contact = Database::queryOne(
            "SELECT workspace_id
             FROM contacts
             WHERE id = ?
             LIMIT 1",
            [$contactId]
        );

        $resolvedWorkspaceId = (int) ($contact['workspace_id'] ?? 0);
        if ($resolvedWorkspaceId <= 0) {
            throw new \RuntimeException('Unable to resolve workspace for email.');
        }

        return $resolvedWorkspaceId;
    }

    /**
     * Prevents a queued Marketing live email from being sent if the recipient became suppressed after handoff.
     *
     * @return array<string,mixed>|null
     */
    private function blockSuppressedMarketingLiveEmailHandoffIfNeeded(array $email, int $workspaceId, string $toEmail): ?array
    {
        $emailId = (int) ($email['id'] ?? 0);
        if ($emailId <= 0 || !Database::tableExists('marketing_live_email_handoffs') || !Database::tableExists('marketing_suppression_entries')) {
            return null;
        }

        $handoff = Database::queryOne(
            "SELECT id, queue_id, email_run_id, email_queue_id
             FROM marketing_live_email_handoffs
             WHERE workspace_id = ?
               AND email_id = ?
               AND status IN ('queued', 'processing')
             ORDER BY id DESC
             LIMIT 1",
            [$workspaceId, $emailId]
        );
        if (!$handoff) {
            return null;
        }

        $emailAddress = strtolower(trim($toEmail));
        $suppressed = Database::queryOne(
            "SELECT id
             FROM marketing_suppression_entries
             WHERE workspace_id = ?
               AND status = 'active'
               AND channel IN ('email', 'all')
               AND identifier_hash IN (?, ?)
             LIMIT 1",
            [
                $workspaceId,
                hash('sha256', 'email:' . $emailAddress),
                hash('sha256', 'all:' . $emailAddress),
            ]
        );
        if (!$suppressed) {
            $this->syncMarketingLiveEmailHandoffDelivery($workspaceId, $emailId, 'processing', 'reviewed', 'pending', 'processing', 'Marketing live email suppression recheck passed.');
            return null;
        }

        $error = 'Marketing live email handoff blocked by active suppression entry.';
        Database::execute(
            "UPDATE emails
             SET status = 'failed', error_message = ?
             WHERE workspace_id = ?
               AND id = ?",
            [$error, $workspaceId, $emailId]
        );
        Database::execute(
            "UPDATE email_queue
             SET status = 'failed', error_message = ?, processed_at = NOW()
             WHERE workspace_id = ?
               AND email_id = ?",
            [$error, $workspaceId, $emailId]
        );
        $this->syncMarketingLiveEmailHandoffDelivery($workspaceId, $emailId, 'blocked', 'blocked', 'failed', 'failed', $error);

        return [
            'success' => false,
            'error' => $error,
            'email_id' => $emailId,
            'email_uuid' => (string) ($email['uuid'] ?? ''),
            'status' => 'failed',
            'provider_key' => $this->smtp->getLastProviderKey() ?? $this->smtp->getActiveProviderKey(),
            'smtp_profile' => $this->smtp->getProfileKey(),
            'smtp_method' => 'marketing_suppression_block',
            'from_email' => (string) ($email['from_email'] ?? ''),
            'to_email' => $toEmail,
        ];
    }

    private function syncMarketingLiveEmailHandoffDelivery(
        int $workspaceId,
        int $emailId,
        string $handoffStatus,
        string $operatorStatus,
        string $emailStatus,
        string $queueStatus,
        string $message
    ): void {
        if ($workspaceId <= 0 || $emailId <= 0 || !Database::tableExists('marketing_live_email_handoffs')) {
            return;
        }

        Database::execute(
            "UPDATE marketing_live_email_handoffs
             SET status = ?,
                 operator_status = ?,
                 last_email_status = ?,
                 last_queue_status = ?,
                 last_checked_at = NOW(),
                 suppression_rechecked_at = COALESCE(suppression_rechecked_at, NOW()),
                 error_message = CASE WHEN ? IN ('failed', 'blocked') THEN ? ELSE error_message END,
                 controlled_at = CASE WHEN ? IN ('sent', 'failed', 'blocked') THEN NOW() ELSE controlled_at END
             WHERE workspace_id = ?
               AND email_id = ?",
            [
                $handoffStatus,
                $operatorStatus,
                $emailStatus,
                $queueStatus,
                $handoffStatus,
                $message,
                $handoffStatus,
                $workspaceId,
                $emailId,
            ]
        );

        if (!Database::tableExists('marketing_live_email_handoff_events')) {
            return;
        }

        $handoffs = Database::query(
            "SELECT id, queue_id, email_run_id
             FROM marketing_live_email_handoffs
             WHERE workspace_id = ?
               AND email_id = ?",
            [$workspaceId, $emailId]
        );
        $eventType = match ($handoffStatus) {
            'sent' => 'sent',
            'failed' => 'failed',
            'blocked' => 'blocked',
            default => 'status_synced',
        };
        $eventStatus = match ($handoffStatus) {
            'sent' => 'success',
            'failed' => 'failed',
            'blocked' => 'blocked',
            default => 'info',
        };
        foreach ($handoffs as $handoff) {
            Database::execute(
                "INSERT INTO marketing_live_email_handoff_events
                 (workspace_id, uuid, handoff_id, queue_id, email_run_id, event_type, status, message, metadata_json)
                 VALUES (?, UUID(), ?, ?, ?, ?, ?, ?, ?)",
                [
                    $workspaceId,
                    (int) ($handoff['id'] ?? 0),
                    (int) ($handoff['queue_id'] ?? 0) ?: null,
                    (int) ($handoff['email_run_id'] ?? 0) ?: null,
                    $eventType,
                    $eventStatus,
                    substr($message, 0, 500),
                    json_encode([
                        'email_id' => $emailId,
                        'email_status' => $emailStatus,
                        'queue_status' => $queueStatus,
                        'source' => 'email_worker',
                    ], JSON_UNESCAPED_SLASHES),
                ]
            );
        }
    }

    private function resolveEmailWorkspaceId(array $email): int
    {
        $workspaceId = (int) ($email['workspace_id'] ?? 0);
        if ($workspaceId > 0) {
            return $workspaceId;
        }

        return $this->resolveWorkspaceId((int) ($email['contact_id'] ?? 0));
    }

    /**
     * Generate UUID v4
     */
    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
