<?php

namespace CRM\Services;

use CRM\Database;

class ConversationEmailReplyService
{
    private EmailService $emailService;

    public function __construct(?EmailService $emailService = null)
    {
        $this->emailService = $emailService ?? new EmailService();
    }

    public static function normalizeEmailSubject(string $subject): string
    {
        $normalized = trim(html_entity_decode($subject, ENT_QUOTES));
        while (preg_match('/^(re|fw|fwd)\s*:\s*/i', $normalized)) {
            $normalized = (string) preg_replace('/^(re|fw|fwd)\s*:\s*/i', '', $normalized);
        }
        $normalized = (string) preg_replace('/\s+/', ' ', trim($normalized));
        return strtolower($normalized);
    }

    public static function buildReplySubject(string $subject): string
    {
        $subject = trim($subject);
        if ($subject === '') {
            return 'Re: Message';
        }
        if (preg_match('/^\s*re\s*:/i', $subject)) {
            return $subject;
        }
        return 'Re: ' . $subject;
    }

    public static function resolveEmailThreadCommunications(array $communication): array
    {
        $contactId = (int) ($communication['contact_id'] ?? 0);
        $baseSubject = self::normalizeEmailSubject((string) ($communication['subject'] ?? ''));
        $participants = [];

        foreach (['from_email', 'to_email', 'contact_email'] as $key) {
            $value = strtolower(trim((string) ($communication[$key] ?? '')));
            if ($value !== '') {
                $participants[$value] = true;
            }
        }

        $participantList = array_keys($participants);
        $where = ["channel = 'email'"];
        $params = [];
        $scopeParts = [];

        if ($contactId > 0) {
            $scopeParts[] = 'contact_id = ?';
            $params[] = $contactId;
        }

        if ($participantList !== []) {
            $placeholders = implode(',', array_fill(0, count($participantList), '?'));
            $scopeParts[] = "LOWER(COALESCE(from_email, '')) IN ($placeholders)";
            $params = array_merge($params, $participantList);
            $scopeParts[] = "LOWER(COALESCE(to_email, '')) IN ($placeholders)";
            $params = array_merge($params, $participantList);
        }

        if ($scopeParts !== []) {
            $where[] = '(' . implode(' OR ', $scopeParts) . ')';
        }

        $candidates = Database::query(
            "SELECT *
             FROM communications
             WHERE " . implode(' AND ', $where) . "
             ORDER BY created_at ASC
             LIMIT 500",
            $params
        );

        if ($candidates === []) {
            return [];
        }

        $seedIds = [];
        $seedMessageId = trim((string) ($communication['message_id'] ?? ''));
        $seedReplyTo = trim((string) ($communication['in_reply_to'] ?? ''));
        if ($seedMessageId !== '') {
            $seedIds[$seedMessageId] = true;
        }
        if ($seedReplyTo !== '') {
            $seedIds[$seedReplyTo] = true;
        }

        $threadKeys = $seedIds;
        $threadRowsById = [(int) ($communication['id'] ?? 0) => true];
        $changed = true;

        while ($changed) {
            $changed = false;
            foreach ($candidates as $row) {
                $messageId = trim((string) ($row['message_id'] ?? ''));
                $replyTo = trim((string) ($row['in_reply_to'] ?? ''));
                $subject = self::normalizeEmailSubject((string) ($row['subject'] ?? ''));

                $linkedByHeader = ($messageId !== '' && isset($threadKeys[$messageId]))
                    || ($replyTo !== '' && isset($threadKeys[$replyTo]));
                $linkedBySubject = ($baseSubject !== '' && $subject === $baseSubject);

                if (!$linkedByHeader && !$linkedBySubject) {
                    continue;
                }

                $rowId = (int) ($row['id'] ?? 0);
                if ($rowId > 0 && !isset($threadRowsById[$rowId])) {
                    $threadRowsById[$rowId] = true;
                    $changed = true;
                }
                if ($messageId !== '' && !isset($threadKeys[$messageId])) {
                    $threadKeys[$messageId] = true;
                    $changed = true;
                }
                if ($replyTo !== '' && !isset($threadKeys[$replyTo])) {
                    $threadKeys[$replyTo] = true;
                    $changed = true;
                }
            }
        }

        $result = [];
        foreach ($candidates as $row) {
            $rowId = (int) ($row['id'] ?? 0);
            if ($rowId > 0 && isset($threadRowsById[$rowId])) {
                $result[] = $row;
            }
        }

        return $result !== [] ? $result : $candidates;
    }

    public static function resolveReplyEmail(array $communication, array $threadCommunications, array $contact = []): string
    {
        $contactEmail = trim((string) ($contact['email'] ?? ''));
        if ($contactEmail !== '') {
            return $contactEmail;
        }

        $workspaceId = (int) ($communication['workspace_id'] ?? $contact['workspace_id'] ?? 0);
        $internalCandidates = [];
        try {
            $emailIntegration = new EmailIntegrationService();
            $internalCandidates[] = $emailIntegration->getPreferredMainFromEmail('', $workspaceId > 0 ? $workspaceId : null);
            $internalCandidates[] = $emailIntegration->getStrictPreferredFromEmailForRole('outreach', $workspaceId > 0 ? $workspaceId : null);
            $internalCandidates[] = $emailIntegration->getStrictPreferredFromEmailForRole('nurture', $workspaceId > 0 ? $workspaceId : null);
            $internalCandidates[] = $emailIntegration->getStrictPreferredFromEmailForRole('assistant', $workspaceId > 0 ? $workspaceId : null);
        } catch (\Throwable $e) {
        }

        $internalEmails = array_filter(array_unique(array_map(
            static fn (string $value): string => strtolower(trim($value)),
            $internalCandidates
        )));

        $messagePool = $threadCommunications !== [] ? $threadCommunications : [$communication];

        foreach (array_reverse($messagePool) as $message) {
            if (!is_array($message)) {
                continue;
            }

            $direction = strtolower((string) ($message['direction'] ?? ''));
            $fromEmail = trim((string) ($message['from_email'] ?? ''));
            $toEmail = trim((string) ($message['to_email'] ?? ''));

            if ($direction === 'inbound' && $fromEmail !== '') {
                return $fromEmail;
            }

            foreach ([$fromEmail, $toEmail] as $candidate) {
                if ($candidate === '') {
                    continue;
                }
                if (in_array(strtolower($candidate), $internalEmails, true)) {
                    continue;
                }
                return $candidate;
            }
        }

        return '';
    }

    public static function buildReferencesHeader(array $communication, array $threadCommunications): string
    {
        $ordered = [];
        $sourceMessageId = trim((string) ($communication['message_id'] ?? ''));
        if ($sourceMessageId !== '') {
            $ordered[$sourceMessageId] = true;
        }

        foreach ($threadCommunications as $row) {
            $messageId = trim((string) ($row['message_id'] ?? ''));
            if ($messageId !== '') {
                $ordered[$messageId] = true;
            }
        }

        return implode(' ', array_keys($ordered));
    }

    public function sendReply(int $communicationId, int $actorUserId, string $body, ?string $subjectOverride = null, string $replySurface = 'unknown'): array
    {
        $body = trim($body);
        if ($communicationId <= 0) {
            throw new \InvalidArgumentException('Conversation ID is required.');
        }
        if ($body === '') {
            throw new \InvalidArgumentException('Reply body is required.');
        }

        $communication = Database::queryOne(
            "SELECT c.*, ct.email AS contact_email
             FROM communications c
             LEFT JOIN contacts ct ON ct.id = c.contact_id
             WHERE c.id = ?
             LIMIT 1",
            [$communicationId]
        );

        if (!$communication) {
            throw new \RuntimeException('Conversation not found.');
        }
        if (strtolower((string) ($communication['channel'] ?? '')) !== 'email') {
            throw new \RuntimeException('Only email conversations are supported.');
        }

        $threadCommunications = self::resolveEmailThreadCommunications($communication);
        $contact = [];
        $contactId = (int) ($communication['contact_id'] ?? 0);
        if ($contactId > 0) {
            $contact = Database::queryOne(
                'SELECT id, email FROM contacts WHERE id = ? LIMIT 1',
                [$contactId]
            ) ?? [];
        }

        $toEmail = self::resolveReplyEmail($communication, $threadCommunications, $contact);
        if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('A valid contact email is required to send this reply.');
        }

        $subject = trim((string) ($subjectOverride ?? ''));
        if ($subject === '') {
            $subject = self::buildReplySubject((string) ($communication['subject'] ?? 'Message'));
        }

        $inReplyTo = trim((string) ($communication['message_id'] ?? ''));
        $referencesHeader = self::buildReferencesHeader($communication, $threadCommunications);
        $generatedMessageId = self::generateMessageId();
        try {
            $fromEmail = trim((string) (new EmailIntegrationService())->getStrictPreferredFromEmailForRole('outreach'));
        } catch (\Throwable $e) {
            $fromEmail = '';
        }
        $auditId = $this->createAuditAttempt([
            'source_communication_id' => $communicationId,
            'contact_id' => $contactId > 0 ? $contactId : null,
            'user_id' => $actorUserId > 0 ? $actorUserId : null,
            'reply_surface' => $replySurface,
            'smtp_profile' => 'outreach',
            'to_email' => $toEmail,
            'from_email' => $fromEmail,
            'subject' => $subject,
            'generated_message_id' => $generatedMessageId,
            'in_reply_to' => $inReplyTo !== '' ? $inReplyTo : null,
            'references_header' => $referencesHeader !== '' ? $referencesHeader : null,
            'details_json' => [
                'thread_message_count' => count($threadCommunications),
                'source_channel' => (string) ($communication['channel'] ?? ''),
            ],
        ]);

        $result = $this->emailService->sendImmediateDetailed(
            $contactId,
            $toEmail,
            $subject,
            $body,
            [
                'body_html' => nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8')),
                'user_id' => $actorUserId,
                'sender_profile' => 'outreach',
                'message_id' => $generatedMessageId,
                'in_reply_to' => $inReplyTo !== '' ? $inReplyTo : null,
                'references_header' => $referencesHeader !== '' ? $referencesHeader : null,
                'reply_surface' => $replySurface,
                'source_communication_id' => $communicationId,
            ]
        );

        $auditPayload = [
            'email_id' => (int) ($result['email_id'] ?? 0) > 0 ? (int) $result['email_id'] : null,
            'smtp_method' => (string) ($result['smtp_method'] ?? ''),
            'from_email' => (string) ($result['from_email'] ?? $fromEmail),
            'generated_message_id' => (string) ($result['message_id'] ?? $generatedMessageId),
            'transport_error' => (string) ($result['error'] ?? ''),
            'details_json' => [
                'communication_id' => (int) ($result['communication_id'] ?? 0),
                'email_uuid' => (string) ($result['email_uuid'] ?? ''),
                'sync_error' => (string) ($result['sync_error'] ?? ''),
                'status' => (string) ($result['status'] ?? ''),
            ],
        ];

        if (!empty($result['success'])) {
            $auditPayload['status'] = !empty($result['sync_error']) ? 'sync_failed' : 'sent';
            $this->updateAuditAttempt($auditId, $auditPayload);
            $result['audit_id'] = $auditId;
            $result['recipient_email'] = $toEmail;
            $result['source_communication_id'] = $communicationId;
            return $result;
        }

        $auditPayload['status'] = 'failed';
        $this->updateAuditAttempt($auditId, $auditPayload);
        throw new \RuntimeException((string) ($result['error'] ?? 'Failed to send email reply.'));
    }

    private function createAuditAttempt(array $payload): int
    {
        Database::execute(
            "INSERT INTO email_reply_delivery_audit
                (source_communication_id, email_id, contact_id, user_id, reply_surface, smtp_profile, smtp_method, to_email, from_email, subject, generated_message_id, in_reply_to, references_header, status, transport_error, details_json)
             VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'attempted', ?, ?)",
            [
                (int) ($payload['source_communication_id'] ?? 0),
                $payload['email_id'] ?? null,
                $payload['contact_id'] ?? null,
                $payload['user_id'] ?? null,
                (string) ($payload['reply_surface'] ?? 'unknown'),
                (string) ($payload['smtp_profile'] ?? 'default'),
                (($payload['smtp_method'] ?? '') !== '') ? $payload['smtp_method'] : null,
                (string) ($payload['to_email'] ?? ''),
                (($payload['from_email'] ?? '') !== '') ? $payload['from_email'] : null,
                (string) ($payload['subject'] ?? ''),
                (($payload['generated_message_id'] ?? '') !== '') ? $payload['generated_message_id'] : null,
                $payload['in_reply_to'] ?? null,
                $payload['references_header'] ?? null,
                $payload['transport_error'] ?? null,
                isset($payload['details_json']) ? json_encode($payload['details_json']) : null,
            ]
        );

        return (int) Database::lastInsertId();
    }

    private function updateAuditAttempt(int $auditId, array $payload): void
    {
        if ($auditId <= 0) {
            return;
        }

        $detailsJson = $payload['details_json'] ?? null;
        if (is_array($detailsJson)) {
            $detailsJson = json_encode($detailsJson);
        }

        Database::execute(
            "UPDATE email_reply_delivery_audit
             SET email_id = COALESCE(?, email_id),
                 smtp_method = COALESCE(NULLIF(?, ''), smtp_method),
                 from_email = COALESCE(NULLIF(?, ''), from_email),
                 generated_message_id = COALESCE(NULLIF(?, ''), generated_message_id),
                 status = ?,
                 transport_error = ?,
                 details_json = COALESCE(?, details_json)
             WHERE id = ?",
            [
                $payload['email_id'] ?? null,
                (string) ($payload['smtp_method'] ?? ''),
                (string) ($payload['from_email'] ?? ''),
                (string) ($payload['generated_message_id'] ?? ''),
                (string) ($payload['status'] ?? 'attempted'),
                (($payload['transport_error'] ?? '') !== '') ? $payload['transport_error'] : null,
                $detailsJson,
                $auditId,
            ]
        );
    }

    private static function generateMessageId(): string
    {
        $domain = trim((string) parse_url((string) ($_ENV['APP_URL'] ?? 'http://crm.local'), PHP_URL_HOST));
        if ($domain === '') {
            $domain = 'crm.local';
        }

        return sprintf('<reply-%s@%s>', self::generateUuid(), $domain);
    }

    private static function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
