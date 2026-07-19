<?php

declare(strict_types=1);

namespace CRM\Services;

use CRM\Database;

class PresentationDeliveryService
{
    private const SESSION_EMAIL_CAP = 3;
    private const SESSION_WHATSAPP_CAP = 3;
    private const SESSION_DIGEST_CAP = 1;
    private const DAILY_CHANNEL_CAP = 20;

    private PresentationSessionService $sessions;
    private PresentationSeedPackService $seedPacks;
    private PresentationWorkspaceGuardService $guard;
    private DemoCryptoService $crypto;

    public function __construct(
        ?PresentationSessionService $sessions = null,
        ?PresentationSeedPackService $seedPacks = null,
        ?PresentationWorkspaceGuardService $guard = null,
        ?DemoCryptoService $crypto = null
    ) {
        $this->sessions = $sessions ?? new PresentationSessionService();
        $this->seedPacks = $seedPacks ?? new PresentationSeedPackService();
        $this->guard = $guard ?? new PresentationWorkspaceGuardService();
        $this->crypto = $crypto ?? new DemoCryptoService();
    }

    /**
     * @return array<string,array<string,string>>
     */
    public function scenarioCatalog(?string $packKey = null): array
    {
        return $this->seedPacks->scenarioCatalog($packKey ?: 'sales_pipeline');
    }

    /**
     * @return array<string,mixed>
     */
    public function preview(int $sessionId, string $scenarioKey, array $options = []): array
    {
        $session = $this->sessions->requireSession($sessionId);
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
            'requires_preflight_confirmation' => !empty($options['live']),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function runScenario(int $sessionId, string $scenarioKey, array $options, int $actorUserId): array
    {
        $session = $this->sessions->requireSession($sessionId);
        $this->assertActive($session);
        $message = $this->scenarioMessage($session, $scenarioKey);
        $channel = (string) ($message['channel'] ?? 'email');
        $recipient = $this->resolveRecipient($sessionId, $channel, (int) ($options['recipient_id'] ?? 0));
        $liveRequested = !empty($options['live']);

        if ($scenarioKey === 'new_lead') {
            $contactId = $this->recordInbound($session, $recipient, 'whatsapp', (string) $message['subject'], (string) $message['body'], $actorUserId, $scenarioKey);
            $taskId = $this->recordTask($session, $contactId, $actorUserId, 'Qualify presentation lead', 'AI captured the inquiry, created a follow-up task, and made the record visible in the workspace.', 'high', $scenarioKey);
            $dealId = $this->recordDeal($session, $contactId, $actorUserId, 'New presentation lead opportunity', 35000.0, 'qualification', $scenarioKey);
            $auditId = $this->recordAudit($session, $actorUserId, null, 'whatsapp', 'scenario', $scenarioKey, false, false, 'simulated', null, (string) $message['subject'], (string) $message['body'], null, ['contact_id' => $contactId, 'task_id' => $taskId, 'deal_id' => $dealId]);
            return ['success' => true, 'scenario_key' => $scenarioKey, 'simulated' => true, 'contact_id' => $contactId, 'task_id' => $taskId, 'deal_id' => $dealId, 'audit_id' => $auditId];
        }

        if ($scenarioKey === 'human_escalation') {
            $contactId = $this->recordInbound($session, $recipient, 'whatsapp', (string) $message['subject'], (string) $message['body'], $actorUserId, $scenarioKey);
            $taskId = $this->recordTask($session, $contactId, $actorUserId, 'Human review: sensitive presentation issue', 'AI confidence is high that this should be escalated rather than auto-resolved.', 'urgent', $scenarioKey);
            $notificationId = $this->recordNotification($session, $actorUserId, 'Human escalation queued', 'A sensitive issue was routed to a human review task.');
            $auditId = $this->recordAudit($session, $actorUserId, null, 'whatsapp', 'scenario', $scenarioKey, false, false, 'simulated', null, (string) $message['subject'], (string) $message['body'], null, ['contact_id' => $contactId, 'task_id' => $taskId, 'notification_id' => $notificationId]);
            return ['success' => true, 'scenario_key' => $scenarioKey, 'simulated' => true, 'contact_id' => $contactId, 'task_id' => $taskId, 'notification_id' => $notificationId, 'escalated' => true, 'audit_id' => $auditId];
        }

        if ($scenarioKey === 'report_snapshot') {
            $notificationId = $this->recordNotification($session, $actorUserId, (string) $message['subject'], (string) $message['body']);
            $auditId = $this->recordAudit($session, $actorUserId, null, 'email', 'report_snapshot', $scenarioKey, false, false, 'simulated', null, (string) $message['subject'], (string) $message['body'], null, ['notification_id' => $notificationId]);
            return ['success' => true, 'scenario_key' => $scenarioKey, 'simulated' => true, 'notification_id' => $notificationId, 'audit_id' => $auditId];
        }

        $deliveryKind = in_array($scenarioKey, ['email_digest', 'whatsapp_digest'], true) ? $scenarioKey : 'scenario';
        return $this->deliver(
            $session,
            $recipient,
            $channel,
            $deliveryKind,
            $scenarioKey,
            (string) ($message['subject'] ?? ''),
            (string) ($message['body'] ?? ''),
            $liveRequested,
            !empty($options['preflight_confirmed']),
            $actorUserId
        );
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
        bool $preflightConfirmed,
        int $actorUserId
    ): array {
        $recipientId = (int) ($recipient['id'] ?? 0);

        if ($liveRequested) {
            if (!$preflightConfirmed) {
                $auditId = $this->recordAudit($session, $actorUserId, $recipientId, $channel, $deliveryKind, $scenarioKey, true, false, 'blocked', null, $subject, $body, 'preflight_confirmation_required', []);
                return ['success' => false, 'blocked' => true, 'reason' => 'preflight_confirmation_required', 'audit_id' => $auditId, 'scenario_key' => $scenarioKey, 'channel' => $channel];
            }

            $gate = $this->liveGate($session, $recipient, $channel, $deliveryKind);
            if (empty($gate['allowed'])) {
                $auditId = $this->recordAudit($session, $actorUserId, $recipientId, $channel, $deliveryKind, $scenarioKey, true, false, 'blocked', null, $subject, $body, (string) ($gate['reason'] ?? 'blocked'), $gate);
                return ['success' => false, 'blocked' => true, 'reason' => (string) ($gate['reason'] ?? 'blocked'), 'audit_id' => $auditId, 'scenario_key' => $scenarioKey, 'channel' => $channel];
            }

            $providerId = null;
            $workspaceId = (int) ($session['workspace_id'] ?? 0);
            $previousWorkspace = WorkspaceContext::runtimeSnapshot();
            WorkspaceContext::activateRuntimeWorkspace($workspaceId, (int) ($session['temporary_user_id'] ?? 0), 'presentation_owner');
            try {
                if ($channel === 'email') {
                    $smtp = new SMTPClient('assistant');
                    $fromEmail = trim((string) ($smtp->getPreferredFromEmail($this->fromEmail($session)) ?? '')) ?: $this->fromEmail($session);
                    $fromName = trim((string) ($smtp->getPreferredFromName($this->fromName($session)) ?? '')) ?: $this->fromName($session);
                    $smtp->send((string) ($recipient['email'] ?? ''), $fromEmail, $fromName, $subject, $body, [], nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8')));
                    $providerId = 'smtp-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));
                } else {
                    $result = (new WhatsAppService('assistant'))->sendTextMessage((string) ($recipient['phone'] ?? ''), $body);
                    $providerId = (string) ($result['messages'][0]['id'] ?? ('wa-' . date('YmdHis') . '-' . bin2hex(random_bytes(4))));
                }

                $artifact = $this->recordOutbound($session, $recipient, $channel, $subject, $body, $actorUserId, $scenarioKey, $providerId, true);
                $auditId = $this->recordAudit($session, $actorUserId, $recipientId, $channel, $deliveryKind, $scenarioKey, true, true, 'success', $providerId, $subject, $body, null, ['artifact' => $artifact]);
                return ['success' => true, 'live_sent' => true, 'provider_message_id' => $providerId, 'audit_id' => $auditId, 'artifact' => $artifact, 'scenario_key' => $scenarioKey];
            } catch (\Throwable $e) {
                $auditId = $this->recordAudit($session, $actorUserId, $recipientId, $channel, $deliveryKind, $scenarioKey, true, false, 'failed', $providerId, $subject, $body, $e->getMessage(), []);
                return ['success' => false, 'live_sent' => false, 'status' => 'failed', 'error' => $e->getMessage(), 'audit_id' => $auditId, 'scenario_key' => $scenarioKey];
            } finally {
                WorkspaceContext::restoreRuntimeWorkspace($previousWorkspace);
            }
        }

        $artifact = $this->recordOutbound($session, $recipient, $channel, $subject, $body, $actorUserId, $scenarioKey, null, false);
        $auditId = $this->recordAudit($session, $actorUserId, $recipientId, $channel, $deliveryKind, $scenarioKey, false, false, 'simulated', null, $subject, $body, null, ['artifact' => $artifact]);
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
        if (!$this->guard->isPresentationWorkspace((int) ($session['workspace_id'] ?? 0))) {
            return ['allowed' => false, 'reason' => 'workspace_not_presentation_enabled'];
        }
        if (empty($session['live_armed']) || empty($session['live_armed_at'])) {
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
        $where = ['presentation_session_id = ?', 'status = ?', 'live_sent = 1'];
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
             FROM presentation_delivery_audit
             WHERE " . implode(' AND ', $where),
            $params
        )['c'] ?? 0);
    }

    private function countDailyDeliveries(int $workspaceId, string $channel): int
    {
        return (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM presentation_delivery_audit
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
        return $this->seedPacks->scenarioMessage(
            (string) ($session['seed_pack_key'] ?? 'sales_pipeline'),
            $scenarioKey,
            [
                'name' => (string) ($session['prospect_name'] ?? 'there'),
                'company' => (string) ($session['prospect_company'] ?? ''),
            ]
        );
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
        $contactId = $this->ensureScenarioContact($session, $recipient, $actorUserId);
        $threadKey = 'presentation-session-' . (int) $session['id'] . '-' . $channel . '-' . $contactId;
        $threadId = $this->ensureThread($session, $contactId, $channel, $threadKey, $actorUserId, $scenarioKey);
        $communicationId = $this->insertCommunication($session, $contactId, $threadKey, $channel, 'outbound', $subject, $body, $scenarioKey === 'human_escalation' ? 'urgent' : 'medium', $scenarioKey, $recipient, $providerId);
        $providerArtifactId = $channel === 'email'
            ? $this->insertEmailArtifact($session, $contactId, $actorUserId, $recipient, $subject, $body, $providerId, $liveSent)
            : $this->insertWhatsAppArtifact($session, $contactId, $actorUserId, $recipient, $body, $providerId, $liveSent, 'outbound');

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
        $contactId = $this->ensureScenarioContact($session, $recipient, $actorUserId);
        $threadKey = 'presentation-session-' . (int) $session['id'] . '-' . $channel . '-' . $contactId;
        $this->ensureThread($session, $contactId, $channel, $threadKey, $actorUserId, $scenarioKey);
        $this->insertCommunication($session, $contactId, $threadKey, $channel, 'inbound', $subject, $body, $scenarioKey === 'human_escalation' ? 'urgent' : 'high', $scenarioKey, $recipient, null);
        if ($channel === 'whatsapp') {
            $this->insertWhatsAppArtifact($session, $contactId, $actorUserId, $recipient, $body, null, false, 'inbound');
        }
        return $contactId;
    }

    /**
     * @param array<string,mixed> $session
     * @param array<string,mixed> $recipient
     */
    private function ensureScenarioContact(array $session, array $recipient, int $actorUserId): int
    {
        $workspaceId = (int) ($session['workspace_id'] ?? 0);
        $email = $this->crypto->normalizeEmail((string) ($recipient['email'] ?? ''));
        $phone = $this->crypto->normalizePhone((string) ($recipient['phone'] ?? ''));
        $existing = Database::queryOne(
            "SELECT id
             FROM contacts
             WHERE workspace_id = ?
               AND ((? <> '' AND email = ?) OR (? <> '' AND phone = ?))
             ORDER BY id DESC
             LIMIT 1",
            [$workspaceId, $email, $email, $phone, $phone]
        );
        if ($existing) {
            return (int) $existing['id'];
        }

        $label = trim((string) ($recipient['label'] ?? 'Presentation Guest'));
        $parts = preg_split('/\s+/', $label, 2) ?: [];
        Database::execute(
            "INSERT INTO contacts
                (workspace_id, uuid, first_name, last_name, email, phone, whatsapp_opt_in_status,
                 whatsapp_opt_in_source, whatsapp_opt_in_at, company, lead_source, stage, assigned_to, created_by, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?, 'opted_in', 'presentation_recipient_allowlist', NOW(),
                     'Presentation Recipient', 'whatsapp', 'qualified', ?, ?, ?)",
            [
                $workspaceId,
                $this->uuid(),
                $parts[0] ?? 'Presentation',
                $parts[1] ?? 'Guest',
                $email !== '' ? $email : ('presentation-' . (int) $session['id'] . '@demo.local.invalid'),
                $phone,
                $actorUserId,
                $actorUserId,
                json_encode(['source' => 'presentation_session', 'presentation_session_id' => (int) ($session['id'] ?? 0), 'recipient_id' => (int) ($recipient['id'] ?? 0)], JSON_UNESCAPED_SLASHES),
            ]
        );
        return (int) Database::lastInsertId();
    }

    private function ensureThread(array $session, int $contactId, string $channel, string $threadKey, int $actorUserId, string $scenarioKey): int
    {
        $workspaceId = (int) ($session['workspace_id'] ?? 0);
        $existing = Database::queryOne(
            "SELECT id
             FROM conversation_threads
             WHERE workspace_id = ?
               AND thread_key = ?
             LIMIT 1",
            [$workspaceId, $threadKey]
        );
        if ($existing) {
            return (int) $existing['id'];
        }

        Database::execute(
            "INSERT INTO conversation_threads
                (workspace_id, contact_id, channel, thread_key, last_message_at, status, current_owner_id,
                 priority, last_channel, unresolved_item_count, escalation_status, metadata_json, message_count)
             VALUES (?, ?, ?, ?, NOW(), 'open', ?, ?, ?, 1, ?, ?, 1)",
            [
                $workspaceId,
                $contactId,
                $channel,
                $threadKey,
                $actorUserId,
                $scenarioKey === 'human_escalation' ? 'urgent' : 'high',
                $channel,
                $scenarioKey === 'human_escalation' ? 'needs_human_review' : null,
                json_encode(['source' => 'presentation_session', 'scenario' => $scenarioKey], JSON_UNESCAPED_SLASHES),
            ]
        );
        return (int) Database::lastInsertId();
    }

    private function insertCommunication(array $session, int $contactId, string $threadKey, string $channel, string $direction, string $subject, string $body, string $priority, string $scenarioKey, array $recipient, ?string $providerId): int
    {
        $workspaceId = (int) ($session['workspace_id'] ?? 0);
        $fromEmail = $this->fromEmail($session);
        $email = $this->crypto->normalizeEmail((string) ($recipient['email'] ?? 'presentation@demo.local.invalid'));
        Database::execute(
            "INSERT INTO communications
                (workspace_id, uuid, contact_id, thread_key, channel, direction, subject, body, metadata,
                 status, triage_priority, triage_score, triage_confidence, triage_status, triage_reason_codes,
                 triage_decided_at, from_email, to_email, message_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'delivered', ?, ?, ?, 'suggested', ?, NOW(), ?, ?, ?)",
            [
                $workspaceId,
                $this->uuid(),
                $contactId,
                $threadKey,
                $channel,
                $direction,
                $subject,
                $body,
                json_encode(['source' => 'presentation_session', 'scenario' => $scenarioKey, 'provider_message_id' => $providerId], JSON_UNESCAPED_SLASHES),
                $priority,
                $priority === 'urgent' ? 97.0 : 88.0,
                $priority === 'urgent' ? 94.0 : 91.0,
                json_encode([$scenarioKey, 'presentation_session'], JSON_UNESCAPED_SLASHES),
                $direction === 'inbound' ? $email : $fromEmail,
                $direction === 'inbound' ? $fromEmail : $email,
                $providerId ?: 'presentation-session-' . (int) $session['id'] . '-' . bin2hex(random_bytes(8)),
            ]
        );

        return (int) Database::lastInsertId();
    }

    private function insertEmailArtifact(array $session, int $contactId, int $actorUserId, array $recipient, string $subject, string $body, ?string $providerId, bool $liveSent): int
    {
        $workspaceId = (int) ($session['workspace_id'] ?? 0);
        $manualId = $this->manualPrimaryKeyIfNeeded('emails');
        $columns = "workspace_id, uuid, contact_id, user_id, to_email, from_email, from_name, sender_profile, subject, body, status, sent_at, delivered_at, message_id";
        $placeholders = "?, ?, ?, ?, ?, ?, ?, 'assistant', ?, ?, ?, NOW(), NOW(), ?";
        $params = [
            $workspaceId,
            $this->uuid(),
            $contactId,
            $actorUserId,
            (string) ($recipient['email'] ?? 'presentation@demo.local.invalid'),
            $this->fromEmail($session),
            $this->fromName($session),
            $subject,
            $body,
            $liveSent ? 'sent' : 'delivered',
            $providerId ?: 'simulated-email-' . (int) $session['id'] . '-' . bin2hex(random_bytes(6)),
        ];
        if ($manualId !== null) {
            $columns = 'id, ' . $columns;
            $placeholders = '?, ' . $placeholders;
            array_unshift($params, $manualId);
        }

        Database::execute("INSERT INTO emails ({$columns}) VALUES ({$placeholders})", $params);
        return $manualId ?? (int) Database::lastInsertId();
    }

    private function insertWhatsAppArtifact(array $session, int $contactId, int $actorUserId, array $recipient, string $body, ?string $providerId, bool $liveSent, string $direction): int
    {
        $workspaceId = (int) ($session['workspace_id'] ?? 0);
        $fromPhone = preg_replace('/\D+/', '', $this->fromPhone($session)) ?: '254711020300';
        $phone = preg_replace('/\D+/', '', $this->crypto->normalizePhone((string) ($recipient['phone'] ?? '+254711020300')));
        Database::execute(
            "INSERT INTO whatsapp_messages
                (workspace_id, uuid, contact_id, user_id, to_number, from_number, message_type, message_body,
                 whatsapp_message_id, status, sent_at, delivered_at, direction)
             VALUES (?, ?, ?, ?, ?, ?, 'text', ?, ?, ?, NOW(), NOW(), ?)",
            [
                $workspaceId,
                $this->uuid(),
                $contactId,
                $actorUserId,
                $direction === 'inbound' ? $fromPhone : $phone,
                $direction === 'inbound' ? $phone : $fromPhone,
                $body,
                $providerId ?: 'simulated-wa-' . (int) $session['id'] . '-' . bin2hex(random_bytes(6)),
                $liveSent ? 'sent' : 'delivered',
                $direction,
            ]
        );

        return (int) Database::lastInsertId();
    }

    private function recordTask(array $session, int $contactId, int $actorUserId, string $title, string $description, string $priority, string $scenarioKey): int
    {
        Database::execute(
            "INSERT INTO tasks
                (workspace_id, title, description, metadata_json, source_surface, contact_id, assigned_to, created_by, status, priority, due_date)
             VALUES (?, ?, ?, ?, 'presentation_scenario', ?, ?, ?, 'pending', ?, DATE_ADD(NOW(), INTERVAL 4 HOUR))",
            [
                (int) ($session['workspace_id'] ?? 0),
                $title,
                $description,
                json_encode(['source' => 'presentation_session', 'scenario' => $scenarioKey], JSON_UNESCAPED_SLASHES),
                $contactId,
                $actorUserId,
                $actorUserId,
                $priority,
            ]
        );

        return (int) Database::lastInsertId();
    }

    private function recordDeal(array $session, int $contactId, int $actorUserId, string $title, float $value, string $stage, string $scenarioKey): int
    {
        Database::execute(
            "INSERT INTO deals
                (workspace_id, title, description, contact_id, assigned_to, created_by, stage, value, probability,
                 expected_close_date, currency, lead_source, tags, custom_fields)
             VALUES (?, ?, 'Presentation-created opportunity.', ?, ?, ?, ?, ?, 70, DATE_ADD(CURDATE(), INTERVAL 7 DAY), ?, 'whatsapp', ?, ?)",
            [
                (int) ($session['workspace_id'] ?? 0),
                $title,
                $contactId,
                $actorUserId,
                $actorUserId,
                $stage,
                $value,
                $this->packCurrency($session),
                json_encode(['presentation', (string) ($session['seed_pack_key'] ?? '')], JSON_UNESCAPED_SLASHES),
                json_encode(['source' => 'presentation_session', 'scenario' => $scenarioKey], JSON_UNESCAPED_SLASHES),
            ]
        );

        return (int) Database::lastInsertId();
    }

    private function recordNotification(array $session, int $actorUserId, string $title, string $message): int
    {
        Database::execute(
            "INSERT INTO notifications
                (workspace_id, user_id, type, title, message, entity_type, entity_id, link, severity, ai_insight, ai_action)
             VALUES (?, ?, 'presentation', ?, ?, 'presentation_session', ?, ?, 'info', ?, ?)",
            [
                (int) ($session['workspace_id'] ?? 0),
                (int) ($session['temporary_user_id'] ?? $actorUserId),
                $title,
                $message,
                (int) ($session['id'] ?? 0),
                function_exists('publicUrl') ? publicUrl('reports.php') : 'reports.php',
                'Presentation scenario created real workspace records.',
                'Open the related workspace view.',
            ]
        );

        return (int) Database::lastInsertId();
    }

    private function recordAudit(array $session, int $actorUserId, ?int $recipientId, string $channel, string $deliveryKind, string $scenarioKey, bool $liveRequested, bool $liveSent, string $status, ?string $providerId, string $subject, string $body, ?string $error, array $metadata): int
    {
        $recipientHash = null;
        if ($recipientId !== null && $recipientId > 0) {
            $recipient = Database::queryOne("SELECT email_hash, phone_hash FROM presentation_recipients WHERE id = ? LIMIT 1", [$recipientId]) ?: [];
            $recipientHash = $channel === 'email' ? ($recipient['email_hash'] ?? null) : ($recipient['phone_hash'] ?? null);
        }

        Database::execute(
            "INSERT INTO presentation_delivery_audit
                (presentation_session_id, workspace_id, actor_user_id, recipient_id, channel, delivery_kind, scenario_key,
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

    /**
     * @param array<string,mixed> $session
     */
    private function assertActive(array $session): void
    {
        if ((string) ($session['status'] ?? '') !== 'active' || strtotime((string) ($session['expires_at'] ?? '')) <= time()) {
            throw new \RuntimeException('Presentation session is not active.');
        }
    }

    /**
     * @param array<string,mixed> $session
     */
    private function fromEmail(array $session): string
    {
        $pack = $this->seedPacks->pack((string) ($session['seed_pack_key'] ?? 'sales_pipeline'));
        return (string) ($pack['from_email'] ?? 'hello@presentation.example');
    }

    /**
     * @param array<string,mixed> $session
     */
    private function fromPhone(array $session): string
    {
        $pack = $this->seedPacks->pack((string) ($session['seed_pack_key'] ?? 'sales_pipeline'));
        return (string) ($pack['from_phone'] ?? '254711020300');
    }

    /**
     * @param array<string,mixed> $session
     */
    private function fromName(array $session): string
    {
        $pack = $this->seedPacks->pack((string) ($session['seed_pack_key'] ?? 'sales_pipeline'));
        return (string) ($pack['company_name'] ?? 'Presentation Workspace');
    }

    /**
     * @param array<string,mixed> $session
     */
    private function packCurrency(array $session): string
    {
        $pack = $this->seedPacks->pack((string) ($session['seed_pack_key'] ?? 'sales_pipeline'));
        return (string) ($pack['currency'] ?? 'KES');
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
