<?php

declare(strict_types=1);

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\Notifications;

class DemoPresentationDeliveryService
{
    private const SESSION_EMAIL_CAP = 3;
    private const SESSION_WHATSAPP_CAP = 3;
    private const SESSION_DIGEST_CAP = 1;
    private const DAILY_CHANNEL_CAP = 20;

    private DemoPresentationSessionService $sessions;
    private DemoWorkspaceService $workspaces;
    private DemoCryptoService $crypto;
    private DemoSessionScopeService $scope;
    private DemoRealtimeEventService $events;

    public function __construct(
        ?DemoPresentationSessionService $sessions = null,
        ?DemoWorkspaceService $workspaces = null,
        ?DemoCryptoService $crypto = null,
        ?DemoSessionScopeService $scope = null,
        ?DemoRealtimeEventService $events = null
    ) {
        $this->sessions = $sessions ?? new DemoPresentationSessionService();
        $this->workspaces = $workspaces ?? new DemoWorkspaceService();
        $this->crypto = $crypto ?? new DemoCryptoService();
        $this->scope = $scope ?? new DemoSessionScopeService();
        $this->events = $events ?? new DemoRealtimeEventService();
    }

    /**
     * @return array<string,array<string,string>>
     */
    public function scenarioCatalog(): array
    {
        return [
            'lead_inquiry' => [
                'label' => 'Lead Inquiry',
                'channel' => 'whatsapp',
                'summary' => 'Inbound beginner lesson inquiry, AI qualifies and opens the learner record.',
            ],
            'booking_confirmation' => [
                'label' => 'Booking Confirmation',
                'channel' => 'email',
                'summary' => 'Outbound booking confirmation after AI selects a structured starter package.',
            ],
            'document_followup' => [
                'label' => 'Document Follow-up',
                'channel' => 'whatsapp',
                'summary' => 'Reminder for missing ID photo and eCitizen screenshot.',
            ],
            'payment_reminder' => [
                'label' => 'Payment Reminder',
                'channel' => 'email',
                'summary' => 'Polite balance reminder before lesson three.',
            ],
            'email_digest' => [
                'label' => 'Email Digest',
                'channel' => 'email',
                'summary' => 'Owner digest with bookings, tasks, escalations, and payment signals.',
            ],
            'whatsapp_digest' => [
                'label' => 'WhatsApp Digest',
                'channel' => 'whatsapp',
                'summary' => 'Concise owner WhatsApp digest for the same MetroDrive signals.',
            ],
            'escalation' => [
                'label' => 'Human Escalation',
                'channel' => 'whatsapp',
                'summary' => 'Instructor complaint is routed to human review instead of auto-resolved.',
            ],
            'report_snapshot' => [
                'label' => 'Report Snapshot',
                'channel' => 'email',
                'summary' => 'Weekly/monthly report snapshot appears with trend context.',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function preview(int $sessionId, string $scenarioKey, array $options = []): array
    {
        $session = $this->sessions->requirePresentationSession($sessionId);
        $message = $this->scenarioMessage($session, $scenarioKey);
        $channel = (string) ($message['channel'] ?? 'email');
        $recipient = $this->resolveRecipient($sessionId, $channel, (int) ($options['recipient_id'] ?? 0));

        return [
            'success' => true,
            'scenario_key' => $scenarioKey,
            'channel' => $channel,
            'recipient' => $recipient,
            'subject' => (string) ($message['subject'] ?? ''),
            'body' => (string) ($message['body'] ?? ''),
            'live_requested' => !empty($options['live']),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function runScenario(int $sessionId, string $scenarioKey, array $options, int $actorUserId): array
    {
        $session = $this->sessions->requirePresentationSession($sessionId);
        if ((string) ($session['status'] ?? '') !== 'active' || strtotime((string) ($session['expires_at'] ?? '')) <= time()) {
            throw new \RuntimeException('Presentation session is not active.');
        }

        $message = $this->scenarioMessage($session, $scenarioKey);
        $channel = (string) ($message['channel'] ?? 'email');
        $recipient = $this->resolveRecipient($sessionId, $channel, (int) ($options['recipient_id'] ?? 0));
        $liveRequested = !empty($options['live']);

        if ($scenarioKey === 'lead_inquiry') {
            $contactId = $this->recordInbound($session, $recipient, 'whatsapp', 'Beginner lesson inquiry', 'Hi MetroDrive Academy, I want to start driving lessons. Can you recommend the best beginner package and available slots?', $actorUserId, $scenarioKey);
            $taskId = $this->recordTask($session, $contactId, $actorUserId, 'Qualify beginner lesson lead', 'AI captured package interest, branch preference, and available starter slots.', 'high');
            $dealId = $this->recordDeal($session, $contactId, $actorUserId, 'Beginner 20-lesson package', 42000.0, 'qualification');
            $this->publishScenarioEvent($session, 'lead_inquiry_completed', 'contact', $contactId, ['task_id' => $taskId, 'deal_id' => $dealId]);
            return ['success' => true, 'scenario_key' => $scenarioKey, 'simulated' => true, 'contact_id' => $contactId, 'task_id' => $taskId, 'deal_id' => $dealId];
        }

        if ($scenarioKey === 'escalation') {
            $contactId = $this->recordInbound($session, $recipient, 'whatsapp', 'Instructor concern', 'I was not comfortable with today\'s instructor and want a different instructor before my next lesson.', $actorUserId, $scenarioKey);
            $taskId = $this->recordTask($session, $contactId, $actorUserId, 'Human review: instructor concern', 'AI confidence is high that this needs human judgment. Operations lead should review before any automated reply.', 'urgent');
            $this->publishScenarioEvent($session, 'human_escalation_created', 'task', $taskId, ['contact_id' => $contactId]);
            return ['success' => true, 'scenario_key' => $scenarioKey, 'simulated' => true, 'contact_id' => $contactId, 'task_id' => $taskId, 'escalated' => true];
        }

        if ($scenarioKey === 'report_snapshot') {
            $notificationId = $this->recordNotification($session, $actorUserId, 'MetroDrive report snapshot ready', 'Weekly lead movement, instructor utilization, payment risk, and escalation trends are ready for owner review.');
            $this->recordAudit($session, $actorUserId, null, 'email', 'report_snapshot', $scenarioKey, false, false, 'simulated', null, 'MetroDrive report snapshot ready', (string) ($message['body'] ?? ''), null, ['notification_id' => $notificationId]);
            $this->publishScenarioEvent($session, 'report_snapshot_ready', 'notification', $notificationId, []);
            return ['success' => true, 'scenario_key' => $scenarioKey, 'simulated' => true, 'notification_id' => $notificationId];
        }

        $deliveryKind = in_array($scenarioKey, ['email_digest', 'whatsapp_digest'], true) ? $scenarioKey : 'scenario';
        return $this->deliver($session, $recipient, $channel, $deliveryKind, $scenarioKey, (string) ($message['subject'] ?? ''), (string) ($message['body'] ?? ''), $liveRequested, $actorUserId);
    }

    /**
     * @param array<string,mixed> $session
     * @param array<string,mixed> $recipient
     * @return array<string,mixed>
     */
    private function deliver(
        array $session,
        array $recipient,
        string $channel,
        string $deliveryKind,
        string $scenarioKey,
        string $subject,
        string $body,
        bool $liveRequested,
        int $actorUserId
    ): array {
        $workspaceId = (int) ($session['workspace_id'] ?? 0);
        $sessionId = (int) ($session['id'] ?? 0);
        $recipientId = (int) ($recipient['id'] ?? 0);

        if ($liveRequested) {
            $gate = $this->liveGate($session, $recipient, $channel, $deliveryKind);
            if (empty($gate['allowed'])) {
                $this->recordAudit($session, $actorUserId, $recipientId, $channel, $deliveryKind, $scenarioKey, true, false, 'blocked', null, $subject, $body, (string) ($gate['reason'] ?? 'blocked'), $gate);
                return ['success' => false, 'blocked' => true, 'reason' => (string) ($gate['reason'] ?? 'blocked'), 'scenario_key' => $scenarioKey, 'channel' => $channel];
            }

            $previousWorkspace = WorkspaceContext::runtimeSnapshot();
            WorkspaceContext::activateRuntimeWorkspace($workspaceId, (int) ($session['user_id'] ?? 0), 'demo_owner');
            try {
                if ($channel === 'email') {
                    $smtp = new SMTPClient('assistant');
                    $fromEmail = trim((string) ($smtp->getPreferredFromEmail('hello@metrodrive.demo.local') ?? '')) ?: 'hello@metrodrive.demo.local';
                    $fromName = trim((string) ($smtp->getPreferredFromName('MetroDrive Academy') ?? '')) ?: 'MetroDrive Academy';
                    $smtp->send((string) ($recipient['email'] ?? ''), $fromEmail, $fromName, $subject, $body, [], nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8')));
                    $providerId = 'smtp-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));
                } else {
                    $result = (new WhatsAppService('assistant'))->sendTextMessage((string) ($recipient['phone'] ?? ''), $body);
                    $providerId = (string) ($result['messages'][0]['id'] ?? ('wa-' . date('YmdHis') . '-' . bin2hex(random_bytes(4))));
                }
                $artifact = $this->recordOutbound($session, $recipient, $channel, $subject, $body, $actorUserId, $scenarioKey, $providerId, true);
                $auditId = $this->recordAudit($session, $actorUserId, $recipientId, $channel, $deliveryKind, $scenarioKey, true, true, 'success', $providerId, $subject, $body, null, ['artifact' => $artifact]);
                $this->publishScenarioEvent($session, 'presentation_live_delivery_sent', $channel === 'email' ? 'email' : 'whatsapp_message', (int) ($artifact['provider_artifact_id'] ?? 0), ['audit_id' => $auditId, 'scenario_key' => $scenarioKey]);
                return ['success' => true, 'live_sent' => true, 'provider_message_id' => $providerId, 'audit_id' => $auditId, 'artifact' => $artifact, 'scenario_key' => $scenarioKey];
            } catch (\Throwable $e) {
                $auditId = $this->recordAudit($session, $actorUserId, $recipientId, $channel, $deliveryKind, $scenarioKey, true, false, 'failed', null, $subject, $body, $e->getMessage(), []);
                return ['success' => false, 'live_sent' => false, 'status' => 'failed', 'error' => $e->getMessage(), 'audit_id' => $auditId, 'scenario_key' => $scenarioKey];
            } finally {
                WorkspaceContext::restoreRuntimeWorkspace($previousWorkspace);
            }
        }

        $artifact = $this->recordOutbound($session, $recipient, $channel, $subject, $body, $actorUserId, $scenarioKey, null, false);
        $auditId = $this->recordAudit($session, $actorUserId, $recipientId, $channel, $deliveryKind, $scenarioKey, false, false, 'simulated', null, $subject, $body, null, ['artifact' => $artifact]);
        $this->publishScenarioEvent($session, 'presentation_simulated_delivery', $channel === 'email' ? 'email' : 'whatsapp_message', (int) ($artifact['provider_artifact_id'] ?? 0), ['audit_id' => $auditId, 'scenario_key' => $scenarioKey]);
        return ['success' => true, 'simulated' => true, 'audit_id' => $auditId, 'artifact' => $artifact, 'scenario_key' => $scenarioKey];
    }

    /**
     * @param array<string,mixed> $session
     * @param array<string,mixed> $recipient
     * @return array{allowed:bool,reason?:string}
     */
    private function liveGate(array $session, array $recipient, string $channel, string $deliveryKind): array
    {
        if (!$this->sessions->liveDeliveryGloballyEnabled()) {
            return ['allowed' => false, 'reason' => 'global_live_delivery_disabled'];
        }
        if (!$this->workspaces->isPresentationDemoWorkspace((int) ($session['workspace_id'] ?? 0))) {
            return ['allowed' => false, 'reason' => 'workspace_not_presentation_enabled'];
        }
        if (empty($session['live_presentation']) || empty($session['live_armed_at'])) {
            return ['allowed' => false, 'reason' => 'session_not_armed'];
        }
        if ((string) ($session['status'] ?? '') !== 'active' || strtotime((string) ($session['expires_at'] ?? '')) <= time()) {
            return ['allowed' => false, 'reason' => 'session_not_active'];
        }
        if ($channel === 'email' && (empty($recipient['can_email']) || trim((string) ($recipient['email'] ?? '')) === '')) {
            return ['allowed' => false, 'reason' => 'recipient_not_email_allowlisted'];
        }
        if ($channel === 'whatsapp' && (empty($recipient['can_whatsapp']) || trim((string) ($recipient['phone'] ?? '')) === '')) {
            return ['allowed' => false, 'reason' => 'recipient_not_whatsapp_allowlisted'];
        }
        if (in_array($deliveryKind, ['email_digest', 'whatsapp_digest'], true) && $this->countSessionDeliveries((int) $session['id'], null, $deliveryKind) >= self::SESSION_DIGEST_CAP) {
            return ['allowed' => false, 'reason' => 'session_digest_cap_reached'];
        }
        $channelCap = $channel === 'email' ? self::SESSION_EMAIL_CAP : self::SESSION_WHATSAPP_CAP;
        if ($this->countSessionDeliveries((int) $session['id'], $channel) >= $channelCap) {
            return ['allowed' => false, 'reason' => 'session_channel_cap_reached'];
        }
        if ($this->countDailyDeliveries((int) ($session['workspace_id'] ?? 0), $channel) >= self::DAILY_CHANNEL_CAP) {
            return ['allowed' => false, 'reason' => 'daily_channel_cap_reached'];
        }

        return ['allowed' => true];
    }

    private function countSessionDeliveries(int $sessionId, ?string $channel = null, ?string $deliveryKind = null): int
    {
        $where = ['demo_session_id = ?', 'status = ?', 'live_sent = 1'];
        $params = [$sessionId, 'success'];
        if ($channel !== null) {
            $where[] = 'channel = ?';
            $params[] = $channel;
        }
        if ($deliveryKind !== null) {
            $where[] = 'delivery_kind = ?';
            $params[] = $deliveryKind;
        }

        return (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM demo_presentation_delivery_audit
             WHERE " . implode(' AND ', $where),
            $params
        )['c'] ?? 0);
    }

    private function countDailyDeliveries(int $workspaceId, string $channel): int
    {
        return (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM demo_presentation_delivery_audit
             WHERE workspace_id = ?
               AND channel = ?
               AND status = 'success'
               AND live_sent = 1
               AND created_at >= CURDATE()",
            [$workspaceId, $channel]
        )['c'] ?? 0);
    }

    /**
     * @param array<string,mixed> $session
     * @return array<string,string>
     */
    private function scenarioMessage(array $session, string $scenarioKey): array
    {
        $name = $this->crypto->decrypt($session['encrypted_name'] ?? null) ?: 'there';
        $company = trim((string) ($session['presentation_company'] ?? ''));
        $companyLine = $company !== '' ? ' for ' . $company : '';

        return match ($scenarioKey) {
            'lead_inquiry' => [
                'channel' => 'whatsapp',
                'subject' => 'Beginner lesson inquiry',
                'body' => 'Hi MetroDrive Academy, I want to start driving lessons. Can you recommend the best beginner package and available slots?',
            ],
            'booking_confirmation' => [
                'channel' => 'email',
                'subject' => 'MetroDrive Academy booking confirmation',
                'body' => "Hi {$name},\n\nYour MetroDrive Academy beginner consultation{$companyLine} is ready. Clarity reviewed the inquiry and recommends the Beginner 20-lesson package with a Westlands starter slot.\n\nNext steps:\n- Confirm your preferred lesson window\n- Send ID photo and eCitizen profile screenshot\n- Pay the deposit before the first practical lesson\n\nIf anything feels unclear, reply here and the team will help.",
            ],
            'document_followup' => [
                'channel' => 'whatsapp',
                'subject' => 'Missing learner documents',
                'body' => "MetroDrive Academy reminder: your learner file is almost ready. Please send your ID photo and eCitizen profile screenshot before the first practical lesson. If you need help, reply and an operator will review it.",
            ],
            'payment_reminder' => [
                'channel' => 'email',
                'subject' => 'MetroDrive Academy payment reminder',
                'body' => "Hi {$name},\n\nThis is a friendly reminder that your remaining lesson balance is due before lesson three. Your deposit is recorded and your slot is still held.\n\nReply if you need the paybill instructions resent or if a human should review a payment plan.",
            ],
            'email_digest' => [
                'channel' => 'email',
                'subject' => 'MetroDrive Academy owner digest',
                'body' => "MetroDrive Academy daily digest\n\nHandled automatically:\n- 14 learner messages triaged\n- 6 booking confirmations prepared\n- 4 document reminders queued\n- 3 payment follow-ups ready\n\nNeeds human review:\n- 1 instructor concern\n- 1 refund request\n\nReports ready:\n- Weekly lead movement\n- Instructor utilization\n- Payments and escalations",
            ],
            'whatsapp_digest' => [
                'channel' => 'whatsapp',
                'subject' => 'MetroDrive Academy WhatsApp digest',
                'body' => "MetroDrive owner digest: 14 learner messages triaged, 6 bookings ready, 4 document reminders, 3 payment follow-ups. Human review: instructor concern + refund request. Reports are ready.",
            ],
            'escalation' => [
                'channel' => 'whatsapp',
                'subject' => 'Instructor concern',
                'body' => "I was not comfortable with today's instructor and want a different instructor before my next lesson.",
            ],
            'report_snapshot' => [
                'channel' => 'email',
                'subject' => 'MetroDrive Academy report snapshot',
                'body' => "MetroDrive report snapshot\n\nWeekly view:\n- Beginner package inquiries are up 18%\n- Westlands practical slots are 82% utilized\n- 5 learners need payment follow-up before lesson three\n- 2 human-review escalations remain open\n\nMonthly view:\n- Corporate fleet training is the highest-value pipeline\n- Defensive driving has the best completion rate\n- Instructor complaints remain below the review threshold",
            ],
            default => [
                'channel' => 'email',
                'subject' => 'MetroDrive Academy scenario',
                'body' => 'MetroDrive Academy demo scenario is ready for review.',
            ],
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function resolveRecipient(int $sessionId, string $channel, int $requestedRecipientId = 0): array
    {
        $recipients = $this->sessions->recipients($sessionId, $channel);
        if ($recipients === []) {
            throw new \RuntimeException('No approved recipient is available for ' . $channel . '.');
        }
        if ($requestedRecipientId > 0) {
            foreach ($recipients as $recipient) {
                if ((int) ($recipient['id'] ?? 0) === $requestedRecipientId) {
                    return $recipient;
                }
            }
            throw new \RuntimeException('Selected recipient is not approved for ' . $channel . '.');
        }

        return $recipients[0];
    }

    /**
     * @param array<string,mixed> $session
     * @param array<string,mixed> $recipient
     * @return array<string,mixed>
     */
    private function recordOutbound(array $session, array $recipient, string $channel, string $subject, string $body, int $actorUserId, string $scenarioKey, ?string $providerId, bool $liveSent): array
    {
        $contactId = $this->ensureSessionContact($session, $recipient, $actorUserId);
        $threadKey = 'metrodrive-session-' . (int) $session['id'] . '-' . $channel . '-' . $contactId;
        $threadId = $this->ensureThread($session, $contactId, $channel, $threadKey, $actorUserId, $scenarioKey);
        $communicationId = $this->insertCommunication($session, $contactId, $threadKey, $channel, 'outbound', $subject, $body, 'medium', $scenarioKey, $recipient, $providerId);
        $providerArtifactId = $channel === 'email'
            ? $this->insertEmailArtifact($session, $contactId, $actorUserId, $recipient, $subject, $body, $providerId, $liveSent)
            : $this->insertWhatsAppArtifact($session, $contactId, $actorUserId, $recipient, $body, $providerId, $liveSent, 'outbound');
        $this->updateSessionMessageCount((int) $session['id']);

        return [
            'contact_id' => $contactId,
            'thread_id' => $threadId,
            'communication_id' => $communicationId,
            'provider_artifact_id' => $providerArtifactId,
        ];
    }

    /**
     * @param array<string,mixed> $session
     * @param array<string,mixed> $recipient
     */
    private function recordInbound(array $session, array $recipient, string $channel, string $subject, string $body, int $actorUserId, string $scenarioKey): int
    {
        $contactId = $this->ensureSessionContact($session, $recipient, $actorUserId);
        $threadKey = 'metrodrive-session-' . (int) $session['id'] . '-' . $channel . '-' . $contactId;
        $this->ensureThread($session, $contactId, $channel, $threadKey, $actorUserId, $scenarioKey);
        $communicationId = $this->insertCommunication($session, $contactId, $threadKey, $channel, 'inbound', $subject, $body, $scenarioKey === 'escalation' ? 'urgent' : 'high', $scenarioKey, $recipient, null);
        if ($channel === 'whatsapp') {
            $this->insertWhatsAppArtifact($session, $contactId, $actorUserId, $recipient, $body, null, false, 'inbound');
        }
        $this->updateSessionMessageCount((int) $session['id']);
        $this->publishScenarioEvent($session, 'presentation_inbound_received', 'communication', $communicationId, ['scenario_key' => $scenarioKey]);
        return $contactId;
    }

    /**
     * @param array<string,mixed> $session
     * @param array<string,mixed> $recipient
     */
    private function ensureSessionContact(array $session, array $recipient, int $actorUserId): int
    {
        $workspaceId = (int) ($session['workspace_id'] ?? 0);
        $sessionId = (int) ($session['id'] ?? 0);
        $email = $this->crypto->normalizeEmail((string) ($recipient['email'] ?? ''));
        $phone = $this->crypto->normalizePhone((string) ($recipient['phone'] ?? ''));
        $existing = Database::queryOne(
            "SELECT id
             FROM contacts
             WHERE workspace_id = ?
               AND demo_session_id = ?
               AND ((? <> '' AND email = ?) OR (? <> '' AND phone = ?))
             LIMIT 1",
            [$workspaceId, $sessionId, $email, $email, $phone, $phone]
        );
        if ($existing) {
            return (int) $existing['id'];
        }

        $label = trim((string) ($recipient['label'] ?? 'Presentation Guest'));
        $parts = preg_split('/\s+/', $label, 2) ?: [];
        Database::execute(
            "INSERT INTO contacts
                (workspace_id, demo_visibility, demo_session_id, uuid, first_name, last_name, email, phone,
                 whatsapp_opt_in_status, whatsapp_opt_in_source, whatsapp_opt_in_at, company, lead_source, stage,
                 assigned_to, created_by, metadata_json)
             VALUES (?, 'session_private', ?, ?, ?, ?, ?, ?, 'opted_in', 'presentation_session_allowlist', NOW(),
                     'Presentation Guest', 'whatsapp', 'qualified', ?, ?, ?)",
            [
                $workspaceId,
                $sessionId,
                $this->uuid(),
                $parts[0] ?? 'Presentation',
                $parts[1] ?? 'Guest',
                $email !== '' ? $email : ('presentation-' . $sessionId . '@demo.local.invalid'),
                $phone,
                $actorUserId,
                $actorUserId,
                json_encode(['source' => 'metrodrive_presentation_session', 'recipient_id' => (int) ($recipient['id'] ?? 0)], JSON_UNESCAPED_SLASHES),
            ]
        );
        $contactId = (int) Database::lastInsertId();
        $this->scope->registerEntity($sessionId, $workspaceId, 'contacts', $contactId);
        return $contactId;
    }

    private function ensureThread(array $session, int $contactId, string $channel, string $threadKey, int $actorUserId, string $scenarioKey): int
    {
        $workspaceId = (int) ($session['workspace_id'] ?? 0);
        $sessionId = (int) ($session['id'] ?? 0);
        $existing = Database::queryOne(
            "SELECT id
             FROM conversation_threads
             WHERE workspace_id = ?
               AND demo_session_id = ?
               AND thread_key = ?
             LIMIT 1",
            [$workspaceId, $sessionId, $threadKey]
        );
        if ($existing) {
            return (int) $existing['id'];
        }

        Database::execute(
            "INSERT INTO conversation_threads
                (workspace_id, demo_visibility, demo_session_id, contact_id, channel, thread_key, last_message_at,
                 status, current_owner_id, priority, last_channel, unresolved_item_count, escalation_status, metadata_json)
             VALUES (?, 'session_private', ?, ?, ?, ?, NOW(), 'open', ?, ?, ?, 1, ?, ?)",
            [
                $workspaceId,
                $sessionId,
                $contactId,
                $channel,
                $threadKey,
                $actorUserId,
                $scenarioKey === 'escalation' ? 'urgent' : 'high',
                $channel,
                $scenarioKey === 'escalation' ? 'needs_human_review' : null,
                json_encode(['source' => 'metrodrive_presentation_session', 'scenario' => $scenarioKey], JSON_UNESCAPED_SLASHES),
            ]
        );
        $threadId = (int) Database::lastInsertId();
        $this->scope->registerEntity($sessionId, $workspaceId, 'conversation_threads', $threadId);
        return $threadId;
    }

    private function insertCommunication(array $session, int $contactId, string $threadKey, string $channel, string $direction, string $subject, string $body, string $priority, string $scenarioKey, array $recipient, ?string $providerId): int
    {
        $workspaceId = (int) ($session['workspace_id'] ?? 0);
        $sessionId = (int) ($session['id'] ?? 0);
        $email = $this->crypto->normalizeEmail((string) ($recipient['email'] ?? 'presentation@demo.local.invalid'));
        Database::execute(
            "INSERT INTO communications
                (workspace_id, demo_visibility, demo_session_id, uuid, contact_id, thread_key, channel, direction, subject, body,
                 metadata, status, read_at, triage_priority, triage_score, triage_confidence, triage_status, triage_reason_codes,
                 triage_decided_at, from_email, to_email, message_id)
             VALUES (?, 'session_private', ?, ?, ?, ?, ?, ?, ?, ?, ?, 'delivered', NULL, ?, ?, ?, 'suggested', ?, NOW(), ?, ?, ?)",
            [
                $workspaceId,
                $sessionId,
                $this->uuid(),
                $contactId,
                $threadKey,
                $channel,
                $direction,
                $subject,
                $body,
                json_encode(['source' => 'metrodrive_presentation_session', 'scenario' => $scenarioKey, 'provider_message_id' => $providerId], JSON_UNESCAPED_SLASHES),
                $priority,
                $priority === 'urgent' ? 97.0 : 88.0,
                $priority === 'urgent' ? 94.0 : 91.0,
                json_encode([$scenarioKey, 'presentation_session'], JSON_UNESCAPED_SLASHES),
                $direction === 'inbound' ? $email : 'hello@metrodrive.demo.local',
                $direction === 'inbound' ? 'hello@metrodrive.demo.local' : $email,
                $providerId ?: 'metrodrive-session-' . $sessionId . '-' . bin2hex(random_bytes(8)),
            ]
        );
        $communicationId = (int) Database::lastInsertId();
        $this->scope->registerEntity($sessionId, $workspaceId, 'communications', $communicationId);
        return $communicationId;
    }

    private function insertEmailArtifact(array $session, int $contactId, int $actorUserId, array $recipient, string $subject, string $body, ?string $providerId, bool $liveSent): int
    {
        $workspaceId = (int) ($session['workspace_id'] ?? 0);
        $sessionId = (int) ($session['id'] ?? 0);
        $manualId = $this->manualPrimaryKeyIfNeeded('emails');
        $columns = "workspace_id, demo_visibility, demo_session_id, uuid, contact_id, user_id, to_email, from_email, from_name,
                 sender_profile, subject, body, status, sent_at, delivered_at, message_id";
        $placeholders = "?, 'session_private', ?, ?, ?, ?, ?, 'hello@metrodrive.demo.local', 'MetroDrive Academy',
                     'assistant', ?, ?, ?, NOW(), NOW(), ?";
        $params = [
            $workspaceId,
            $sessionId,
            $this->uuid(),
            $contactId,
            $actorUserId,
            (string) ($recipient['email'] ?? 'presentation@demo.local.invalid'),
            $subject,
            $body,
            $liveSent ? 'sent' : 'delivered',
            $providerId ?: 'simulated-email-' . $sessionId . '-' . bin2hex(random_bytes(6)),
        ];
        if ($manualId !== null) {
            $columns = 'id, ' . $columns;
            $placeholders = '?, ' . $placeholders;
            array_unshift($params, $manualId);
        }

        Database::execute(
            "INSERT INTO emails ({$columns}) VALUES ({$placeholders})",
            $params
        );
        $id = $manualId ?? (int) Database::lastInsertId();
        $this->scope->registerEntity($sessionId, $workspaceId, 'emails', $id);
        return $id;
    }

    private function insertWhatsAppArtifact(array $session, int $contactId, int $actorUserId, array $recipient, string $body, ?string $providerId, bool $liveSent, string $direction): int
    {
        $workspaceId = (int) ($session['workspace_id'] ?? 0);
        $sessionId = (int) ($session['id'] ?? 0);
        $phone = $this->crypto->normalizePhone((string) ($recipient['phone'] ?? '+254700000475'));
        Database::execute(
            "INSERT INTO whatsapp_messages
                (workspace_id, demo_visibility, demo_session_id, uuid, contact_id, user_id, to_number, from_number,
                 message_type, message_body, whatsapp_message_id, status, sent_at, delivered_at, direction)
             VALUES (?, 'session_private', ?, ?, ?, ?, ?, ?, 'text', ?, ?, ?, NOW(), NOW(), ?)",
            [
                $workspaceId,
                $sessionId,
                $this->uuid(),
                $contactId,
                $actorUserId,
                $direction === 'inbound' ? '254700000475' : $phone,
                $direction === 'inbound' ? $phone : '254700000475',
                $body,
                $providerId ?: 'simulated-wa-' . $sessionId . '-' . bin2hex(random_bytes(6)),
                $liveSent ? 'sent' : 'delivered',
                $direction,
            ]
        );
        $id = (int) Database::lastInsertId();
        $this->scope->registerEntity($sessionId, $workspaceId, 'whatsapp_messages', $id);
        return $id;
    }

    private function recordTask(array $session, int $contactId, int $actorUserId, string $title, string $description, string $priority): int
    {
        $workspaceId = (int) ($session['workspace_id'] ?? 0);
        $sessionId = (int) ($session['id'] ?? 0);
        Database::execute(
            "INSERT INTO tasks
                (workspace_id, demo_visibility, demo_session_id, title, description, metadata_json, source_surface,
                 contact_id, assigned_to, created_by, status, priority, due_date)
             VALUES (?, 'session_private', ?, ?, ?, ?, 'metrodrive_presentation', ?, ?, ?, 'pending', ?, DATE_ADD(NOW(), INTERVAL 4 HOUR))",
            [
                $workspaceId,
                $sessionId,
                $title,
                $description,
                json_encode(['source' => 'metrodrive_presentation_session'], JSON_UNESCAPED_SLASHES),
                $contactId,
                $actorUserId,
                $actorUserId,
                $priority,
            ]
        );
        $id = (int) Database::lastInsertId();
        $this->scope->registerEntity($sessionId, $workspaceId, 'tasks', $id);
        return $id;
    }

    private function recordDeal(array $session, int $contactId, int $actorUserId, string $title, float $value, string $stage): int
    {
        $workspaceId = (int) ($session['workspace_id'] ?? 0);
        $sessionId = (int) ($session['id'] ?? 0);
        Database::execute(
            "INSERT INTO deals
                (workspace_id, demo_visibility, demo_session_id, title, description, contact_id, assigned_to, created_by,
                 stage, value, probability, expected_close_date, currency, lead_source, tags, custom_fields)
             VALUES (?, 'session_private', ?, ?, 'Presentation-created MetroDrive opportunity.', ?, ?, ?, ?, ?, 70,
                     DATE_ADD(CURDATE(), INTERVAL 7 DAY), 'KES', 'whatsapp', ?, ?)",
            [
                $workspaceId,
                $sessionId,
                $title,
                $contactId,
                $actorUserId,
                $actorUserId,
                $stage,
                $value,
                json_encode(['metrodrive', 'presentation_session'], JSON_UNESCAPED_SLASHES),
                json_encode(['source' => 'metrodrive_presentation_session'], JSON_UNESCAPED_SLASHES),
            ]
        );
        $id = (int) Database::lastInsertId();
        $this->scope->registerEntity($sessionId, $workspaceId, 'deals', $id);
        return $id;
    }

    private function recordNotification(array $session, int $actorUserId, string $title, string $message): int
    {
        $notificationId = (new Notifications())->create(
            (int) ($session['user_id'] ?? $actorUserId),
            'demo_experience',
            $title,
            $message,
            [
                'entity_type' => 'demo_session',
                'entity_id' => (int) ($session['id'] ?? 0),
                'link' => function_exists('publicUrl') ? publicUrl('reports.php') : 'reports.php',
                'severity' => 'info',
                'ai_insight' => 'MetroDrive reports are built from seeded and session-private activity.',
                'ai_action' => 'Open reports.',
            ]
        );
        $this->markDemoRecord('notifications', $notificationId, (int) ($session['workspace_id'] ?? 0), (int) ($session['id'] ?? 0));
        return $notificationId;
    }

    private function recordAudit(array $session, int $actorUserId, ?int $recipientId, string $channel, string $deliveryKind, string $scenarioKey, bool $liveRequested, bool $liveSent, string $status, ?string $providerId, string $subject, string $body, ?string $error, array $metadata): int
    {
        $recipientHash = null;
        if ($recipientId !== null && $recipientId > 0) {
            $recipient = Database::queryOne("SELECT email_hash, phone_hash FROM demo_presentation_recipients WHERE id = ? LIMIT 1", [$recipientId]) ?: [];
            $recipientHash = $channel === 'email' ? ($recipient['email_hash'] ?? null) : ($recipient['phone_hash'] ?? null);
        }

        Database::execute(
            "INSERT INTO demo_presentation_delivery_audit
                (demo_session_id, workspace_id, actor_user_id, recipient_id, channel, delivery_kind, scenario_key,
                 live_requested, live_sent, status, provider_message_id, recipient_hash, payload_hash, subject, error_message, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                (int) ($session['id'] ?? 0),
                (int) ($session['workspace_id'] ?? 0),
                $actorUserId > 0 ? $actorUserId : null,
                $recipientId,
                $channel,
                $deliveryKind,
                $scenarioKey,
                $liveRequested ? 1 : 0,
                $liveSent ? 1 : 0,
                $status,
                $providerId,
                $recipientHash,
                hash('sha256', $subject . "\n" . $body),
                mb_substr($subject, 0, 255),
                $error,
                json_encode($metadata, JSON_UNESCAPED_SLASHES),
            ]
        );

        return (int) Database::lastInsertId();
    }

    private function markDemoRecord(string $table, int $id, int $workspaceId, int $sessionId): void
    {
        if ($id <= 0 || !Database::columnExists($table, 'demo_visibility')) {
            return;
        }
        Database::execute(
            "UPDATE `{$table}`
             SET demo_visibility = 'session_private', demo_session_id = ?
             WHERE workspace_id = ? AND id = ?",
            [$sessionId, $workspaceId, $id]
        );
        $this->scope->registerEntity($sessionId, $workspaceId, $table, $id);
    }

    private function updateSessionMessageCount(int $sessionId): void
    {
        Database::execute(
            "UPDATE demo_visitor_sessions
             SET message_count = message_count + 1,
                 last_seen_at = NOW()
             WHERE id = ?",
            [$sessionId]
        );
    }

    private function publishScenarioEvent(array $session, string $eventType, string $entityType, int $entityId, array $payload): void
    {
        $this->events->publish(
            (int) ($session['workspace_id'] ?? 0),
            (int) ($session['id'] ?? 0),
            $eventType,
            $entityType,
            $entityId,
            ['source' => 'metrodrive_presentation'] + $payload
        );
    }

    private function manualPrimaryKeyIfNeeded(string $table): ?int
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            return null;
        }

        $column = Database::queryOne("SHOW COLUMNS FROM `{$table}` LIKE 'id'") ?: [];
        if (stripos((string) ($column['Extra'] ?? ''), 'auto_increment') !== false) {
            return null;
        }

        return (int) (Database::queryOne("SELECT COALESCE(MAX(id), 0) + 1 AS next_id FROM `{$table}`")['next_id'] ?? 1);
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
