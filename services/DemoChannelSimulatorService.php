<?php

declare(strict_types=1);

namespace CRM\Services;

use CRM\Auth;
use CRM\Database;
use CRM\Security;
use CRM\Modules\Notifications;

class DemoChannelSimulatorService
{
    private const MAX_MESSAGES_PER_SESSION = 30;
    private const MAX_BODY_LENGTH = 2000;

    private DemoSessionScopeService $scope;
    private DemoRealtimeEventService $events;

    public function __construct(?DemoSessionScopeService $scope = null, ?DemoRealtimeEventService $events = null)
    {
        $this->scope = $scope ?? new DemoSessionScopeService();
        $this->events = $events ?? new DemoRealtimeEventService();
    }

    public function simulate(array $input): array
    {
        $session = $this->scope->requireActiveSession();
        $workspaceId = (int) ($session['workspace_id'] ?? 0);
        $sessionId = (int) ($session['id'] ?? 0);
        $userId = (int) (Auth::userId() ?? $session['user_id'] ?? $session['guest_user_id'] ?? 0);

        if ((int) ($session['message_count'] ?? 0) >= self::MAX_MESSAGES_PER_SESSION) {
            throw new \RuntimeException('This demo session has reached its simulated message limit.');
        }

        $channel = strtolower(trim((string) ($input['channel'] ?? 'email')));
        if (!in_array($channel, ['email', 'whatsapp'], true)) {
            throw new \InvalidArgumentException('Demo supports simulated email and WhatsApp only.');
        }

        $direction = strtolower(trim((string) ($input['direction'] ?? 'inbound')));
        if (!in_array($direction, ['inbound', 'outbound'], true)) {
            throw new \InvalidArgumentException('Invalid demo message direction.');
        }

        $subject = Security::sanitizeInput((string) ($input['subject'] ?? ($channel === 'email' ? 'Demo inquiry' : 'WhatsApp demo message')), 'string');
        $body = trim((string) ($input['body'] ?? $input['message'] ?? ''));
        if ($body === '') {
            throw new \InvalidArgumentException('Message body is required.');
        }
        if (mb_strlen($body) > self::MAX_BODY_LENGTH) {
            throw new \InvalidArgumentException('Demo messages are limited to ' . self::MAX_BODY_LENGTH . ' characters.');
        }
        $body = Security::sanitizeInput($body, 'string');

        Database::beginTransaction();
        try {
            $contactId = $this->findOrCreateContact($workspaceId, $sessionId, $userId, $input, $channel);
            $threadKey = 'demo-session-' . $sessionId . '-' . $channel . '-' . $contactId;
            $threadId = $this->findOrCreateThread($workspaceId, $sessionId, $contactId, $channel, $threadKey, $userId);
            $communicationId = $this->createCommunication($workspaceId, $sessionId, $contactId, $threadKey, $channel, $direction, $subject, $body, $input);

            $providerId = null;
            if ($channel === 'email') {
                $providerId = $this->createEmailArtifact($workspaceId, $sessionId, $contactId, $userId, $direction, $subject, $body, $input);
            } else {
                $providerId = $this->createWhatsAppArtifact($workspaceId, $sessionId, $contactId, $userId, $direction, $body, $input);
            }

            $this->updateThread($workspaceId, $threadKey, $direction);
            Database::execute(
                "UPDATE demo_visitor_sessions SET message_count = message_count + 1, last_seen_at = NOW() WHERE id = ?",
                [$sessionId]
            );

            $notifications = new Notifications();
            $notificationType = $direction === 'inbound' ? 'demo_message_received' : 'demo_delivery_completed';
            $notificationTitle = trim((string) ($input['notification_title'] ?? ''));
            if ($notificationTitle === '') {
                $notificationTitle = $direction === 'inbound' ? 'Demo message received' : 'Simulated delivery completed';
            }
            $notificationMessage = trim((string) ($input['notification_message'] ?? ''));
            if ($notificationMessage === '') {
                $notificationMessage = $direction === 'inbound'
                    ? 'Your private demo thread received a simulated ' . $channel . ' message.'
                    : 'Your simulated ' . $channel . ' message was marked delivered.';
            }
            $inboxUrl = function_exists('publicUrl') ? publicUrl('inbox.php') : '/inbox.php';
            $demoEventKey = trim((string) ($input['demo_event_key'] ?? ''));
            if ($demoEventKey === '') {
                $demoEventKey = $direction === 'inbound'
                    ? ($channel === 'whatsapp' ? 'whatsapp_lead_received' : 'email_inquiry_received')
                    : 'demo_delivery_completed';
            }
            $toastLabel = trim((string) ($input['toast_label'] ?? ''));
            if ($toastLabel === '') {
                $toastLabel = $channel === 'whatsapp' ? 'WhatsApp' : 'Email';
            }
            $toastActionLabel = trim((string) ($input['toast_action_label'] ?? ''));
            if ($toastActionLabel === '') {
                $toastActionLabel = 'Open inbox';
            }
            $toastContextUrl = trim((string) ($input['toast_context_url'] ?? ''));
            if ($toastContextUrl === '') {
                $toastContextUrl = $inboxUrl;
            } elseif (function_exists('publicUrl') && !preg_match('/^https?:\/\//i', $toastContextUrl) && $toastContextUrl[0] !== '/') {
                $toastContextUrl = publicUrl($toastContextUrl);
            }
            $toastEnabled = array_key_exists('toast', $input) ? (bool) $input['toast'] : false;
            $cuePayload = [
                'cue_key' => (string) ($input['cue_key'] ?? ''),
                'moment_state' => (string) ($input['moment_state'] ?? ''),
                'next_cue' => (string) ($input['next_cue'] ?? ''),
                'highlight_selector' => (string) ($input['highlight_selector'] ?? ''),
                'auto_action' => (string) ($input['auto_action'] ?? ''),
                'auto_action_url' => (string) ($input['auto_action_url'] ?? ''),
                'scene_key' => (string) ($input['scene_key'] ?? $demoEventKey),
                'scene_step' => (string) ($input['scene_step'] ?? ''),
                'animation_payload' => (array) ($input['animation_payload'] ?? []),
                'persisted_entity_refs' => (array) ($input['persisted_entity_refs'] ?? []),
                'story_version' => (string) ($input['story_version'] ?? ''),
                'toast' => $toastEnabled,
                'sound' => false,
            ];
            $notificationId = $notifications->create(
                $userId,
                $notificationType,
                $notificationTitle,
                $notificationMessage,
                [
                    'entity_type' => 'communication',
                    'entity_id' => $communicationId,
                    'link' => $inboxUrl,
                    'severity' => 'info',
                    'ai_insight' => (string) ($input['ai_insight'] ?? 'Demo triage kept this message inside your private session.'),
                    'ai_action' => (string) ($input['ai_action'] ?? 'Open inbox'),
                ]
            );
            $this->markDemoRecord('notifications', $notificationId, $workspaceId, $sessionId);
            $notificationPayload = $this->notificationPayload($notificationId, $workspaceId, $sessionId, $notifications);

            $this->events->publish($workspaceId, $sessionId, 'communication_created', 'communication', $communicationId, [
                'channel' => $channel,
                'direction' => $direction,
                'thread_id' => $threadId,
                'demo_event_key' => $demoEventKey,
                'phase' => (string) ($input['phase'] ?? ''),
                'moment_index' => (int) ($input['moment_index'] ?? 0),
                'moment_total' => (int) ($input['moment_total'] ?? 0),
                'progress_label' => (string) ($input['progress_label'] ?? ''),
            ] + $cuePayload);
            $this->events->publish($workspaceId, $sessionId, 'notification_created', 'notification', $notificationId, [
                'title' => $notificationTitle,
                'notification' => $notificationPayload,
                'toast' => $toastEnabled,
                'sound' => false,
                'sound_type' => $channel === 'whatsapp' ? 'whatsapp' : 'email',
                'demo_event_key' => $demoEventKey,
                'toast_label' => $toastLabel,
                'toast_action_label' => $toastActionLabel,
                'toast_context_url' => $toastContextUrl,
                'phase' => (string) ($input['phase'] ?? ''),
                'moment_index' => (int) ($input['moment_index'] ?? 0),
                'moment_total' => (int) ($input['moment_total'] ?? 0),
                'progress_label' => (string) ($input['progress_label'] ?? ''),
            ] + $cuePayload);
            $this->events->publish($workspaceId, $sessionId, 'triage_completed', 'communication', $communicationId, [
                'priority' => $direction === 'inbound' ? 'high' : 'medium',
                'reasons' => [$channel === 'email' ? 'demo_inbound_email' : 'demo_whatsapp_lead', 'visitor_private_thread'],
                'demo_event_key' => $demoEventKey,
            ] + $cuePayload);

            Database::commit();

            return [
                'success' => true,
                'communication_id' => $communicationId,
                'thread_id' => $threadId,
                'contact_id' => $contactId,
                'provider_artifact_id' => $providerId,
                'notification_id' => $notificationId,
                'simulated' => true,
            ];
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    private function findOrCreateContact(int $workspaceId, int $sessionId, int $userId, array $input, string $channel): int
    {
        $email = strtolower(trim((string) ($input['email'] ?? 'visitor-' . $sessionId . '@demo.local.invalid')));
        $phone = trim((string) ($input['phone'] ?? $input['whatsapp_phone'] ?? '+1000000' . $sessionId));
        $name = trim((string) ($input['name'] ?? 'Demo Visitor'));
        $company = trim((string) ($input['company'] ?? 'Riverside Residence'));
        $parts = preg_split('/\s+/', $name, 2) ?: [];

        $existing = Database::queryOne(
            "SELECT id FROM contacts WHERE workspace_id = ? AND demo_session_id = ? AND (email = ? OR phone = ?) LIMIT 1",
            [$workspaceId, $sessionId, $email, $phone]
        );
        if ($existing) {
            return (int) $existing['id'];
        }

        Database::execute(
            "INSERT INTO contacts
                (workspace_id, demo_visibility, demo_session_id, uuid, first_name, last_name, email, phone, company, lead_source, stage, assigned_to, created_by, metadata_json)
             VALUES (?, 'session_private', ?, ?, ?, ?, ?, ?, ?, ?, 'new', ?, ?, ?)",
            [
                $workspaceId,
                $sessionId,
                $this->uuid(),
                Security::sanitizeInput($parts[0] ?? 'Demo', 'string') ?: 'Demo',
                Security::sanitizeInput($parts[1] ?? 'Visitor', 'string') ?: 'Visitor',
                Security::sanitizeInput($email, 'email') ?: ('visitor-' . $sessionId . '@demo.local.invalid'),
                Security::sanitizeInput($phone, 'string'),
                Security::sanitizeInput($company, 'string') ?: 'Riverside Residence',
                $channel === 'whatsapp' ? 'whatsapp' : 'form',
                $userId > 0 ? $userId : null,
                $userId > 0 ? $userId : null,
                json_encode(['source' => 'protected_demo_session'], JSON_UNESCAPED_SLASHES),
            ]
        );
        $contactId = (int) Database::lastInsertId();
        $this->scope->registerEntity($sessionId, $workspaceId, 'contacts', $contactId);
        return $contactId;
    }

    private function findOrCreateThread(int $workspaceId, int $sessionId, int $contactId, string $channel, string $threadKey, int $userId): int
    {
        $existing = Database::queryOne(
            "SELECT id FROM conversation_threads WHERE workspace_id = ? AND demo_session_id = ? AND thread_key = ? LIMIT 1",
            [$workspaceId, $sessionId, $threadKey]
        );
        if ($existing) {
            return (int) $existing['id'];
        }

        Database::execute(
            "INSERT INTO conversation_threads
                (workspace_id, demo_visibility, demo_session_id, contact_id, channel, thread_key, last_message_at, last_channel, status, current_owner_id, priority, unresolved_item_count, metadata_json)
             VALUES (?, 'session_private', ?, ?, ?, ?, NOW(), ?, 'open', ?, 'high', 1, ?)",
            [
                $workspaceId,
                $sessionId,
                $contactId,
                $channel,
                $threadKey,
                $channel,
                $userId > 0 ? $userId : null,
                json_encode(['source' => 'protected_demo_session'], JSON_UNESCAPED_SLASHES),
            ]
        );
        $threadId = (int) Database::lastInsertId();
        $this->scope->registerEntity($sessionId, $workspaceId, 'conversation_threads', $threadId);
        return $threadId;
    }

    private function createCommunication(int $workspaceId, int $sessionId, int $contactId, string $threadKey, string $channel, string $direction, string $subject, string $body, array $input): int
    {
        $reasonCodes = json_encode([
            $channel === 'email' ? 'demo_inbound_email' : 'demo_whatsapp_lead',
            'visitor_private_thread',
        ], JSON_UNESCAPED_SLASHES);

        Database::execute(
            "INSERT INTO communications
                (workspace_id, demo_visibility, demo_session_id, uuid, contact_id, thread_key, channel, direction, subject, body, metadata, status, read_at,
                 triage_priority, triage_score, triage_confidence, triage_status, triage_reason_codes, triage_decided_at, from_email, to_email, message_id)
             VALUES (?, 'session_private', ?, ?, ?, ?, ?, ?, ?, ?, ?, 'delivered', NULL, ?, 87.50, 92.00, 'suggested', ?, NOW(), ?, ?, ?)",
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
                json_encode([
                    'source' => 'protected_demo_session',
                    'delivery_mode' => 'simulated_first',
                    'provider_call' => false,
                    'demo_event_key' => (string) ($input['demo_event_key'] ?? ''),
                    'cue_key' => (string) ($input['cue_key'] ?? ''),
                    'moment_state' => (string) ($input['moment_state'] ?? ''),
                    'scene_key' => (string) ($input['scene_key'] ?? ''),
                    'scene_step' => (string) ($input['scene_step'] ?? ''),
                    'story_version' => (string) ($input['story_version'] ?? ''),
                ], JSON_UNESCAPED_SLASHES),
                $direction === 'inbound' ? 'high' : 'medium',
                $reasonCodes,
                strtolower(trim((string) ($input['email'] ?? 'visitor-' . $sessionId . '@demo.local.invalid'))),
                'workspace-demo@demo.local.invalid',
                'demo-' . $sessionId . '-' . bin2hex(random_bytes(8)) . '@demo.local',
            ]
        );

        $communicationId = (int) Database::lastInsertId();
        $this->scope->registerEntity($sessionId, $workspaceId, 'communications', $communicationId);
        return $communicationId;
    }

    private function createEmailArtifact(int $workspaceId, int $sessionId, int $contactId, int $userId, string $direction, string $subject, string $body, array $input): int
    {
        $to = $direction === 'inbound' ? 'workspace-demo@demo.local.invalid' : strtolower(trim((string) ($input['email'] ?? 'visitor-' . $sessionId . '@demo.local.invalid')));
        $from = $direction === 'inbound' ? strtolower(trim((string) ($input['email'] ?? 'visitor-' . $sessionId . '@demo.local.invalid'))) : 'workspace-demo@demo.local.invalid';
        Database::execute(
            "INSERT INTO emails
                (workspace_id, demo_visibility, demo_session_id, uuid, contact_id, user_id, to_email, from_email, from_name, subject, body, status, sent_at, delivered_at, message_id)
             VALUES (?, 'session_private', ?, ?, ?, ?, ?, ?, 'Protected Demo', ?, ?, 'delivered', NOW(), NOW(), ?)",
            [$workspaceId, $sessionId, $this->uuid(), $contactId, $userId ?: null, $to, $from, $subject, $body, 'demo-email-' . $sessionId . '-' . bin2hex(random_bytes(6))]
        );
        $id = (int) Database::lastInsertId();
        $this->scope->registerEntity($sessionId, $workspaceId, 'emails', $id);
        return $id;
    }

    private function createWhatsAppArtifact(int $workspaceId, int $sessionId, int $contactId, int $userId, string $direction, string $body, array $input): int
    {
        $phone = trim((string) ($input['phone'] ?? $input['whatsapp_phone'] ?? '+1000000' . $sessionId));
        Database::execute(
            "INSERT INTO whatsapp_messages
                (workspace_id, demo_visibility, demo_session_id, uuid, contact_id, user_id, to_number, from_number, message_type, message_body, whatsapp_message_id, status, sent_at, delivered_at, direction)
             VALUES (?, 'session_private', ?, ?, ?, ?, ?, ?, 'text', ?, ?, 'delivered', NOW(), NOW(), ?)",
            [
                $workspaceId,
                $sessionId,
                $this->uuid(),
                $contactId,
                $userId ?: null,
                $direction === 'inbound' ? 'workspace-demo' : $phone,
                $direction === 'inbound' ? $phone : 'workspace-demo',
                $body,
                'demo-wa-' . $sessionId . '-' . bin2hex(random_bytes(6)),
                $direction,
            ]
        );
        $id = (int) Database::lastInsertId();
        $this->scope->registerEntity($sessionId, $workspaceId, 'whatsapp_messages', $id);
        return $id;
    }

    private function updateThread(int $workspaceId, string $threadKey, string $direction): void
    {
        $field = $direction === 'inbound' ? 'last_inbound_at' : 'last_outbound_at';
        Database::execute(
            "UPDATE conversation_threads
             SET last_message_at = NOW(),
                 {$field} = NOW(),
                 message_count = COALESCE(message_count, 0) + 1,
                 unresolved_item_count = CASE WHEN ? = 'inbound' THEN COALESCE(unresolved_item_count, 0) + 1 ELSE unresolved_item_count END,
                 updated_at = NOW()
             WHERE workspace_id = ? AND thread_key = ?",
            [$direction, $workspaceId, $threadKey]
        );
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

    private function notificationPayload(int $notificationId, int $workspaceId, int $sessionId, Notifications $notifications): array
    {
        $notification = Database::queryOne(
            "SELECT *
             FROM notifications
             WHERE workspace_id = ?
               AND demo_session_id = ?
               AND id = ?
             LIMIT 1",
            [$workspaceId, $sessionId, $notificationId]
        ) ?: [];

        if ($notification === []) {
            return [];
        }

        $type = (string) ($notification['type'] ?? '');
        foreach (['title', 'message', 'ai_insight', 'ai_action'] as $field) {
            if (isset($notification[$field]) && is_scalar($notification[$field])) {
                $notification[$field] = html_entity_decode((string) $notification[$field], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }
        $notification['icon'] = $type === 'demo_delivery_completed' ? 'Sent' : 'Msg';
        $notification['color'] = $notifications->getColor($type);

        return $notification;
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
