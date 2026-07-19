<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\Notifications;

class WhatsAppAssistantSessionService
{
    private WhatsAppAssistantConfig $config;
    private WhatsAppService $whatsApp;
    private Notifications $notifications;
    private SMTPClient $smtp;

    public function __construct()
    {
        $this->config = new WhatsAppAssistantConfig();
        $this->whatsApp = new WhatsAppService('assistant');
        $this->notifications = new Notifications();
        $this->smtp = new SMTPClient('assistant');
    }

    public function recordInboundMessage(array $authorizedNumber, ?\DateTimeImmutable $now = null): array
    {
        $now = $now ?? new \DateTimeImmutable('now');
        $snapshot = $this->ensureSessionRecord($authorizedNumber);
        $expiresAt = $now->modify('+' . $this->config->getSessionWindowHours() . ' hours');

        $setWorkspaceSql = '';
        $params = [
            (int) ($authorizedNumber['user_id'] ?? 0),
            (string) ($authorizedNumber['phone_number'] ?? ''),
            WhatsAppAssistantConfig::STATE_SESSION_OPEN,
            $now->format('Y-m-d H:i:s'),
            $expiresAt->format('Y-m-d H:i:s'),
        ];
        if ($this->hasWorkspaceColumn('whatsapp_assistant_sessions')) {
            $setWorkspaceSql = "workspace_id = ?,";
            $params[] = $this->workspaceIdForAuthorized($authorizedNumber) ?: null;
        }
        $params[] = (int) ($authorizedNumber['id'] ?? 0);

        Database::execute(
            "UPDATE whatsapp_assistant_sessions
             SET user_id = ?,
                 phone_number = ?,
                 session_state = ?,
                 last_inbound_at = ?,
                 session_expires_at = ?,
                 {$setWorkspaceSql}
                 reopen_required_since = NULL,
                 last_error_message = NULL
             WHERE authorized_number_id = ?",
            $params
        );

        $this->logEvent($authorizedNumber, 'reopen_acknowledged', 'success', 'Inbound message reopened the assistant session.');

        return $this->getSessionSnapshot((int) ($authorizedNumber['id'] ?? 0), $now);
    }

    public function recordOutboundMessage(array $authorizedNumber, ?\DateTimeImmutable $now = null): void
    {
        $now = $now ?? new \DateTimeImmutable('now');
        $this->ensureSessionRecord($authorizedNumber);
        $setWorkspaceSql = '';
        $params = [$now->format('Y-m-d H:i:s')];
        if ($this->hasWorkspaceColumn('whatsapp_assistant_sessions')) {
            $setWorkspaceSql = ", workspace_id = ?";
            $params[] = $this->workspaceIdForAuthorized($authorizedNumber) ?: null;
        }
        $params[] = (int) ($authorizedNumber['id'] ?? 0);
        Database::execute(
            "UPDATE whatsapp_assistant_sessions
             SET last_outbound_at = ?{$setWorkspaceSql}
             WHERE authorized_number_id = ?",
            $params
        );
    }

    public function getSessionSnapshot(int $authorizedNumberId, ?\DateTimeImmutable $now = null): array
    {
        $now = $now ?? new \DateTimeImmutable('now');
        $row = Database::queryOne(
            "SELECT wan.*, was.session_state, was.last_inbound_at, was.last_outbound_at, was.session_expires_at,
                    was.last_keepalive_reminder_at, was.last_reopen_template_sent_at, was.reopen_required_since,
                    was.last_error_message
             FROM whatsapp_assistant_authorized_numbers wan
             LEFT JOIN whatsapp_assistant_sessions was ON was.authorized_number_id = wan.id
             WHERE wan.id = ?
             LIMIT 1",
            [$authorizedNumberId]
        );

        if (!$row) {
            return [];
        }

        return $this->decorateSnapshot($row, $now);
    }

    public function getSessionSnapshotByPhone(string $phoneNumber, ?\DateTimeImmutable $now = null): array
    {
        $normalized = $this->config->normalizePhoneNumber($phoneNumber);
        if ($normalized === '') {
            return [];
        }

        $params = [$normalized];
        $workspaceSql = $this->workspacePredicate('wan', 'whatsapp_assistant_authorized_numbers', $params);
        $row = Database::queryOne(
            "SELECT wan.*, was.session_state, was.last_inbound_at, was.last_outbound_at, was.session_expires_at,
                    was.last_keepalive_reminder_at, was.last_reopen_template_sent_at, was.reopen_required_since,
                    was.last_error_message
             FROM whatsapp_assistant_authorized_numbers wan
             LEFT JOIN whatsapp_assistant_sessions was ON was.authorized_number_id = wan.id
             WHERE wan.phone_number = ?
             {$workspaceSql}
             LIMIT 1",
            $params
        );

        if (!$row) {
            return [];
        }

        return $this->decorateSnapshot($row, $now ?? new \DateTimeImmutable('now'));
    }

    public function getSessionDiagnostics(?\DateTimeImmutable $now = null): array
    {
        $now = $now ?? new \DateTimeImmutable('now');
        if (!$this->tableExists('whatsapp_assistant_sessions')) {
            return [];
        }

        $params = [];
        $workspaceSql = $this->workspacePredicate('wan', 'whatsapp_assistant_authorized_numbers', $params, true);
        $rows = Database::query(
            "SELECT wan.*, was.session_state, was.last_inbound_at, was.last_outbound_at, was.session_expires_at,
                    was.last_keepalive_reminder_at, was.last_reopen_template_sent_at, was.reopen_required_since,
                    was.last_error_message, u.first_name, u.last_name, u.email
             FROM whatsapp_assistant_authorized_numbers wan
             LEFT JOIN whatsapp_assistant_sessions was ON was.authorized_number_id = wan.id
             LEFT JOIN users u ON u.id = wan.user_id
             {$workspaceSql}
             ORDER BY wan.is_active DESC, wan.label ASC, wan.phone_number ASC",
            $params
        );

        return array_map(fn ($row) => $this->decorateSnapshot($row, $now), $rows);
    }

    public function ensureSessionReadyForOutbound(array $authorizedNumber, string $reason = 'assistant_reply', bool $allowReopen = true): array
    {
        $snapshot = $this->ensureSessionRecord($authorizedNumber);
        $state = (string) ($snapshot['computed_session_state'] ?? WhatsAppAssistantConfig::STATE_EXPIRED_REQUIRES_REOPEN);
        if (in_array($state, [WhatsAppAssistantConfig::STATE_SESSION_OPEN, WhatsAppAssistantConfig::STATE_EXPIRING_SOON], true)) {
            return [
                'ready' => true,
                'state' => $state,
                'action' => 'send_session_message',
            ];
        }

        if ($allowReopen && $this->config->isAutoReopenEnabled()) {
            $reopen = $this->sendReopenTemplate($authorizedNumber, $reason);
            if (!empty($reopen['sent'])) {
                return [
                    'ready' => false,
                    'state' => WhatsAppAssistantConfig::STATE_EXPIRED_REQUIRES_REOPEN,
                    'action' => 'reopen_template_sent',
                    'message' => 'Session expired. A reopen template was sent and the assistant is waiting for the user to reply.',
                ];
            }
            if (!empty($reopen['skipped'])) {
                return [
                    'ready' => false,
                    'state' => WhatsAppAssistantConfig::STATE_EXPIRED_REQUIRES_REOPEN,
                    'action' => 'reopen_already_pending',
                    'message' => 'Session expired and a reopen template is already pending. Wait for the user to reply in WhatsApp.',
                ];
            }
        }

        $this->logEvent($authorizedNumber, 'session_blocked', 'blocked', 'Assistant session is expired and needs an inbound WhatsApp reply.');

        return [
            'ready' => false,
            'state' => WhatsAppAssistantConfig::STATE_EXPIRED_REQUIRES_REOPEN,
            'action' => 'blocked',
            'message' => 'Assistant session expired. The mapped user must reply on WhatsApp before free-form assistant messages can resume.',
        ];
    }

    public function sendReopenTemplate(array $authorizedNumber, string $reason = 'expired_session'): array
    {
        $templateConfig = $this->config->getReopenTemplateConfig();
        if (empty($templateConfig['is_ready'])) {
            $this->updateSessionError((int) ($authorizedNumber['id'] ?? 0), 'Reopen template is not configured.');
            return ['sent' => false, 'skipped' => false, 'error' => 'reopen_template_not_configured'];
        }

        $snapshot = $this->ensureSessionRecord($authorizedNumber);
        $lastInboundAt = (string) ($snapshot['last_inbound_at'] ?? '');
        $lastTemplateSentAt = (string) ($snapshot['last_reopen_template_sent_at'] ?? '');
        if ($lastTemplateSentAt !== '' && ($lastInboundAt === '' || strtotime($lastTemplateSentAt) >= strtotime($lastInboundAt))) {
            $this->logEvent($authorizedNumber, 'reopen_template_skipped', 'skipped', 'Reopen template already sent for current expired session.');
            return ['sent' => false, 'skipped' => true, 'error' => null];
        }

        $providerMessageId = null;
        try {
            $templateStructure = $this->whatsApp->getTemplateByName(
                (string) $templateConfig['template_name'],
                (string) $templateConfig['language']
            );
            $components = $this->whatsApp->buildTemplateComponents(
                (array) ($templateConfig['template_params'] ?? []),
                $templateStructure
            );
            $result = $this->whatsApp->sendTemplateMessage(
                (string) ($authorizedNumber['phone_number'] ?? ''),
                (string) $templateConfig['template_name'],
                (string) $templateConfig['language'],
                $components
            );
            $providerMessageId = (string) ($result['messages'][0]['id'] ?? '');
        } catch (\Throwable $e) {
            $this->updateSessionError((int) ($authorizedNumber['id'] ?? 0), $e->getMessage());
            $this->logEvent($authorizedNumber, 'reopen_template_failed', 'failed', 'Failed to send reopen template.', $e->getMessage());
            return ['sent' => false, 'skipped' => false, 'error' => $e->getMessage()];
        }

        Database::execute(
            "UPDATE whatsapp_assistant_sessions
             SET session_state = ?,
                 last_reopen_template_sent_at = NOW(),
                 reopen_required_since = COALESCE(reopen_required_since, NOW()),
                 last_error_message = NULL
             WHERE authorized_number_id = ?",
            [
                WhatsAppAssistantConfig::STATE_EXPIRED_REQUIRES_REOPEN,
                (int) ($authorizedNumber['id'] ?? 0),
            ]
        );

        $this->logAssistantMessage([
            'user_id' => (int) ($authorizedNumber['user_id'] ?? 0),
            'authorized_number_id' => (int) ($authorizedNumber['id'] ?? 0),
            'phone_number' => (string) ($authorizedNumber['phone_number'] ?? ''),
            'direction' => 'outbound',
            'message_type' => 'template',
            'message_body' => 'WhatsApp reopen template sent: ' . (string) $templateConfig['template_name'],
            'intent' => 'reopen_session',
            'command_result_json' => json_encode([
                'reason' => $reason,
                'template_name' => $templateConfig['template_name'],
                'language' => $templateConfig['language'],
            ]),
            'whatsapp_message_id' => $providerMessageId,
            'status' => 'reopen_sent',
        ]);
        $this->logEvent($authorizedNumber, 'reopen_template_sent', 'success', 'Reopen template sent to restore the 24-hour assistant session.');

        return ['sent' => true, 'skipped' => false, 'provider_message_id' => $providerMessageId];
    }

    public function runMaintenance(bool $force = false, ?\DateTimeImmutable $now = null): array
    {
        $now = $now ?? new \DateTimeImmutable('now');
        if (!$this->config->isEnabled()) {
            return [
                'reminders' => 0,
                'reopens' => 0,
                'skipped' => 0,
                'failures' => 0,
                'results' => [],
                'reason' => 'assistant_disabled',
            ];
        }
        $results = [];
        $reminders = 0;
        $reopens = 0;
        $skipped = 0;
        $failures = 0;

        foreach ($this->config->getAuthorizedNumbers(true) as $authorizedNumber) {
            $snapshot = $this->ensureSessionRecord($authorizedNumber, $now);
            $state = (string) ($snapshot['computed_session_state'] ?? '');

            if ($state === WhatsAppAssistantConfig::STATE_EXPIRING_SOON) {
                if ($force || $this->shouldSendReminder($snapshot, $now)) {
                    $result = $this->sendKeepaliveReminder($authorizedNumber, $snapshot, $now);
                    $results[] = $result;
                    if (($result['status'] ?? '') === 'success') {
                        $reminders++;
                    } else {
                        $failures++;
                    }
                } else {
                    $skipped++;
                }
                continue;
            }

            if ($state === WhatsAppAssistantConfig::STATE_EXPIRED_REQUIRES_REOPEN && $this->config->isAutoReopenEnabled()) {
                $reopen = $this->sendReopenTemplate($authorizedNumber, 'maintenance_expired_session');
                if (!empty($reopen['sent'])) {
                    $results[] = ['status' => 'success', 'type' => 'reopen_template', 'phone_number' => $authorizedNumber['phone_number']];
                    $reopens++;
                } elseif (!empty($reopen['skipped'])) {
                    $results[] = ['status' => 'skipped', 'type' => 'reopen_template', 'phone_number' => $authorizedNumber['phone_number']];
                    $skipped++;
                } else {
                    $results[] = ['status' => 'failed', 'type' => 'reopen_template', 'phone_number' => $authorizedNumber['phone_number'], 'error' => $reopen['error'] ?? 'Unknown error'];
                    $failures++;
                }
                continue;
            }

            $skipped++;
        }

        return [
            'reminders' => $reminders,
            'reopens' => $reopens,
            'skipped' => $skipped,
            'failures' => $failures,
            'results' => $results,
        ];
    }

    public function runMaintenanceForWorkspaces(bool $force = false, ?\DateTimeImmutable $now = null): array
    {
        $workspaceIds = WhatsAppAssistantDigestService::getDigestEnabledWorkspaceIds(true);
        if (
            $workspaceIds === []
            && !WhatsAppAssistantDigestService::hasWorkspaceDigestConfigRows()
            && strtolower((string) ($_ENV['WHATSAPP_ASSISTANT_ENABLED'] ?? 'false')) === 'true'
        ) {
            $rows = Database::query(
                "SELECT DISTINCT wan.workspace_id
                 FROM whatsapp_assistant_authorized_numbers wan
                 JOIN workspaces w ON w.id = wan.workspace_id AND w.status IN ('active', 'trialing')
                 JOIN users u ON u.id = wan.user_id
                 JOIN workspace_memberships wm
                   ON wm.workspace_id = wan.workspace_id
                  AND wm.user_id = wan.user_id
                  AND wm.membership_status = 'active'
                 WHERE wan.is_active = 1
                 ORDER BY wan.workspace_id"
            );
            $workspaceIds = array_values(array_filter(array_map(
                static fn(array $row): int => (int) ($row['workspace_id'] ?? 0),
                $rows
            ), static fn(int $workspaceId): bool => $workspaceId > 0));
        }

        $aggregate = [
            'reminders' => 0,
            'reopens' => 0,
            'skipped' => 0,
            'failures' => 0,
            'results' => [],
            'workspace_results' => [],
        ];
        $previousRuntime = WorkspaceContext::runtimeSnapshot();

        foreach (array_values(array_unique($workspaceIds)) as $workspaceId) {
            try {
                if (WorkspaceContext::activateRuntimeWorkspace($workspaceId) === null) {
                    $aggregate['skipped']++;
                    $aggregate['results'][] = ['workspace_id' => $workspaceId, 'status' => 'skipped', 'reason' => 'workspace_unavailable'];
                    continue;
                }

                // Sender credentials and assistant config must be resolved after activation.
                $result = (new self())->runMaintenance($force, $now);
                $result['workspace_id'] = $workspaceId;
                $aggregate['workspace_results'][] = $result;
                foreach (['reminders', 'reopens', 'skipped', 'failures'] as $key) {
                    $aggregate[$key] += (int) ($result[$key] ?? 0);
                }
                foreach ((array) ($result['results'] ?? []) as $row) {
                    $aggregate['results'][] = ['workspace_id' => $workspaceId] + (array) $row;
                }
            } catch (\Throwable $e) {
                $aggregate['failures']++;
                $aggregate['results'][] = ['workspace_id' => $workspaceId, 'status' => 'failed', 'error' => $e->getMessage()];
            } finally {
                if ($previousRuntime !== null) {
                    WorkspaceContext::restoreRuntimeWorkspace($previousRuntime);
                } else {
                    WorkspaceContext::clearRuntimeWorkspace();
                }
            }
        }

        return $aggregate;
    }

    private function sendKeepaliveReminder(array $authorizedNumber, array $snapshot, \DateTimeImmutable $now): array
    {
        $userId = (int) ($authorizedNumber['user_id'] ?? 0);
        $phoneNumber = (string) ($authorizedNumber['phone_number'] ?? '');
        $assistantDisplay = trim((string) ($_ENV['WHATSAPP_ASSISTANT_PHONE_NUMBER'] ?? ''));
        $title = 'WhatsApp assistant session is about to expire';
        $message = 'Send any WhatsApp message to the assistant number ' . ($assistantDisplay !== '' ? $assistantDisplay : 'today') . ' to keep your 24-hour assistant window open.';

        try {
            $this->notifications->create(
                $userId,
                'reminder',
                $title,
                $message,
                [
                    'severity' => 'info',
                    'entity_type' => 'whatsapp_assistant',
                    'entity_id' => (int) ($authorizedNumber['id'] ?? 0),
                    'link' => 'workspace_skills.php?module=whatsapp_assistant&setup_tab=identity#setup',
                ]
            );
        } catch (\Throwable $e) {
            // Keep in-app notification non-fatal. Email reminder still attempts below.
        }

        $emailStatus = 'not_sent';
        $emailAddress = trim((string) ($authorizedNumber['email'] ?? ''));
        if ($emailAddress !== '' && filter_var($emailAddress, FILTER_VALIDATE_EMAIL)) {
            try {
                $fromEmail = $this->smtp->getFromEmail() ?? 'noreply@example.com';
                $fromName = $this->smtp->getFromName() ?? 'Personal Assistant';
                $subject = 'Keep your WhatsApp assistant session active';
                $body = $message . "\n\nReply from your designated WhatsApp number to reopen or keep the session running.";
                $this->smtp->send($emailAddress, $fromEmail, $fromName, $subject, $body);
                $emailStatus = 'sent';
            } catch (\Throwable $e) {
                $emailStatus = 'failed';
            }
        }

        Database::execute(
            "UPDATE whatsapp_assistant_sessions
             SET session_state = ?,
                 last_keepalive_reminder_at = ?,
                 last_error_message = NULL
             WHERE authorized_number_id = ?",
            [
                WhatsAppAssistantConfig::STATE_EXPIRING_SOON,
                $now->format('Y-m-d H:i:s'),
                (int) ($authorizedNumber['id'] ?? 0),
            ]
        );

        $logMessage = 'Keepalive reminder created' . ($emailStatus === 'sent' ? ' and emailed.' : '.');
        $this->logEvent($authorizedNumber, 'keepalive_reminder', 'success', $logMessage);

        return [
            'status' => 'success',
            'type' => 'keepalive_reminder',
            'phone_number' => $phoneNumber,
            'email_status' => $emailStatus,
        ];
    }

    private function shouldSendReminder(array $snapshot, \DateTimeImmutable $now): bool
    {
        $lastReminderAt = (string) ($snapshot['last_keepalive_reminder_at'] ?? '');
        if ($lastReminderAt === '') {
            return true;
        }

        return substr($lastReminderAt, 0, 10) !== $now->format('Y-m-d');
    }

    private function ensureSessionRecord(array $authorizedNumber, ?\DateTimeImmutable $now = null): array
    {
        $authorizedNumberId = (int) ($authorizedNumber['id'] ?? 0);
        $now = $now ?? new \DateTimeImmutable('now');
        $existing = Database::queryOne(
            "SELECT *
             FROM whatsapp_assistant_sessions
             WHERE authorized_number_id = ?
             LIMIT 1",
            [$authorizedNumberId]
        );

        if (!$existing) {
            $columns = ['authorized_number_id', 'user_id', 'phone_number', 'session_state', 'created_at', 'updated_at'];
            $placeholders = ['?', '?', '?', '?', 'NOW()', 'NOW()'];
            $params = [
                $authorizedNumberId,
                (int) ($authorizedNumber['user_id'] ?? 0),
                (string) ($authorizedNumber['phone_number'] ?? ''),
                WhatsAppAssistantConfig::STATE_EXPIRED_REQUIRES_REOPEN,
            ];
            if ($this->hasWorkspaceColumn('whatsapp_assistant_sessions')) {
                array_splice($columns, 1, 0, 'workspace_id');
                array_splice($placeholders, 1, 0, '?');
                array_splice($params, 1, 0, [$this->workspaceIdForAuthorized($authorizedNumber) ?: null]);
            }
            Database::execute(
                "INSERT INTO whatsapp_assistant_sessions (" . implode(', ', $columns) . ")
                 VALUES (" . implode(', ', $placeholders) . ")",
                $params
            );
        } else {
            $setWorkspaceSql = '';
            $params = [
                (int) ($authorizedNumber['user_id'] ?? 0),
                (string) ($authorizedNumber['phone_number'] ?? ''),
            ];
            if ($this->hasWorkspaceColumn('whatsapp_assistant_sessions')) {
                $setWorkspaceSql = ', workspace_id = ?';
                $params[] = $this->workspaceIdForAuthorized($authorizedNumber) ?: null;
            }
            $params[] = $authorizedNumberId;
            Database::execute(
                "UPDATE whatsapp_assistant_sessions
                 SET user_id = ?, phone_number = ?{$setWorkspaceSql}
                 WHERE authorized_number_id = ?",
                $params
            );
        }

        return $this->getSessionSnapshot($authorizedNumberId, $now);
    }

    private function decorateSnapshot(array $row, \DateTimeImmutable $now): array
    {
        $expiresAtRaw = (string) ($row['session_expires_at'] ?? '');
        $lastInboundRaw = (string) ($row['last_inbound_at'] ?? '');
        $windowHours = $this->config->getSessionWindowHours();
        if ($expiresAtRaw === '' && $lastInboundRaw !== '') {
            $expiresAtRaw = date('Y-m-d H:i:s', strtotime($lastInboundRaw . ' +' . $windowHours . ' hours'));
        }

        $state = WhatsAppAssistantConfig::STATE_EXPIRED_REQUIRES_REOPEN;
        $secondsRemaining = null;
        if ($expiresAtRaw !== '') {
            $expiresTs = strtotime($expiresAtRaw);
            if ($expiresTs !== false) {
                $secondsRemaining = $expiresTs - $now->getTimestamp();
                if ($secondsRemaining > ($this->config->getKeepaliveWarningHours() * 3600)) {
                    $state = WhatsAppAssistantConfig::STATE_SESSION_OPEN;
                } elseif ($secondsRemaining > 0) {
                    $state = WhatsAppAssistantConfig::STATE_EXPIRING_SOON;
                }
            }
        }

        if ($secondsRemaining === null || $secondsRemaining <= 0) {
            $state = WhatsAppAssistantConfig::STATE_EXPIRED_REQUIRES_REOPEN;
        }

        Database::execute(
            "UPDATE whatsapp_assistant_sessions
             SET session_state = ?, session_expires_at = ?, reopen_required_since = CASE WHEN ? = ? AND reopen_required_since IS NULL THEN NOW() ELSE reopen_required_since END
             WHERE authorized_number_id = ?",
            [
                $state,
                $expiresAtRaw !== '' ? $expiresAtRaw : null,
                $state,
                WhatsAppAssistantConfig::STATE_EXPIRED_REQUIRES_REOPEN,
                (int) ($row['id'] ?? $row['authorized_number_id'] ?? 0),
            ]
        );

        $row['computed_session_state'] = $state;
        $row['seconds_until_expiry'] = $secondsRemaining;
        $row['session_expires_at'] = $expiresAtRaw;

        return $row;
    }

    private function updateSessionError(int $authorizedNumberId, string $message): void
    {
        if ($authorizedNumberId <= 0) {
            return;
        }

        Database::execute(
            "UPDATE whatsapp_assistant_sessions
             SET last_error_message = ?, session_state = ?
             WHERE authorized_number_id = ?",
            [$message, WhatsAppAssistantConfig::STATE_EXPIRED_REQUIRES_REOPEN, $authorizedNumberId]
        );
    }

    private function logEvent(array $authorizedNumber, string $eventType, string $status, string $message, ?string $error = null): void
    {
        if (!$this->tableExists('whatsapp_assistant_keepalive_log')) {
            return;
        }

        $columns = ['authorized_number_id', 'user_id', 'phone_number', 'event_type', 'status', 'message', 'error_message', 'created_at'];
        $placeholders = ['?', '?', '?', '?', '?', '?', '?', 'NOW()'];
        $params = [
            (int) ($authorizedNumber['id'] ?? 0),
            (int) ($authorizedNumber['user_id'] ?? 0),
            (string) ($authorizedNumber['phone_number'] ?? ''),
            $eventType,
            $status,
            $message,
            $error,
        ];
        if ($this->hasWorkspaceColumn('whatsapp_assistant_keepalive_log')) {
            array_splice($columns, 1, 0, 'workspace_id');
            array_splice($placeholders, 1, 0, '?');
            array_splice($params, 1, 0, [$this->workspaceIdForAuthorized($authorizedNumber) ?: null]);
        }

        Database::execute(
            "INSERT INTO whatsapp_assistant_keepalive_log (" . implode(', ', $columns) . ")
             VALUES (" . implode(', ', $placeholders) . ")",
            $params
        );
    }

    private function logAssistantMessage(array $data): void
    {
        if (!$this->tableExists('whatsapp_assistant_messages')) {
            return;
        }

        $uuid = function_exists('uuid_v4') ? uuid_v4() : $this->generateUuid();
        $columns = ['uuid', 'user_id', 'authorized_number_id', 'phone_number', 'direction', 'message_type', 'message_body', 'intent', 'command_result_json', 'whatsapp_message_id', 'status', 'created_at'];
        $placeholders = ['?', '?', '?', '?', '?', '?', '?', '?', '?', '?', '?', 'NOW()'];
        $params = [
            $uuid,
            $data['user_id'] ?: null,
            $data['authorized_number_id'] ?: null,
            $data['phone_number'],
            $data['direction'],
            $data['message_type'],
            $data['message_body'],
            $data['intent'],
            $data['command_result_json'],
            $data['whatsapp_message_id'],
            $data['status'],
        ];
        if ($this->hasWorkspaceColumn('whatsapp_assistant_messages')) {
            $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
            if ($workspaceId <= 0) {
                $workspaceId = (int) ($data['workspace_id'] ?? 0);
            }
            array_splice($columns, 1, 0, 'workspace_id');
            array_splice($placeholders, 1, 0, '?');
            array_splice($params, 1, 0, [$workspaceId > 0 ? $workspaceId : null]);
        }

        Database::execute(
            "INSERT INTO whatsapp_assistant_messages (" . implode(', ', $columns) . ")
             VALUES (" . implode(', ', $placeholders) . ")",
            $params
        );
    }

    private function tableExists(string $tableName): bool
    {
        $row = Database::queryOne(
            "SELECT COUNT(*) AS cnt
             FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?",
            [$tableName]
        );

        return ((int) ($row['cnt'] ?? 0)) > 0;
    }

    private function hasWorkspaceColumn(string $tableName): bool
    {
        return Database::tableExists($tableName) && Database::columnExists($tableName, 'workspace_id');
    }

    private function workspaceIdForAuthorized(array $authorizedNumber): int
    {
        $workspaceId = (int) ($authorizedNumber['workspace_id'] ?? 0);
        if ($workspaceId > 0) {
            return $workspaceId;
        }

        return (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
    }

    private function workspacePredicate(string $alias, string $tableName, array &$params, bool $asWhere = false): string
    {
        $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        if ($workspaceId <= 0 || !$this->hasWorkspaceColumn($tableName)) {
            return '';
        }

        $params[] = $workspaceId;
        $column = $alias !== '' ? $alias . '.workspace_id' : 'workspace_id';
        return ($asWhere ? 'WHERE ' : 'AND ') . $column . ' = ?';
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
