<?php

namespace CRM\Services;

use CRM\Auth;
use CRM\Database;
use CRM\Modules\Notifications;

class OnboardingLifecycleNudgeService
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';
    public const COOLDOWN_HOURS = 24;

    private WorkspaceOnboardingService $onboarding;
    private WorkspaceChannelHealthService $channelHealth;
    private OnboardingNudgeDraftService $drafts;
    private OperatorAuditService $audit;

    public function __construct(
        ?WorkspaceOnboardingService $onboarding = null,
        ?WorkspaceChannelHealthService $channelHealth = null,
        ?OnboardingNudgeDraftService $drafts = null,
        ?OperatorAuditService $audit = null
    ) {
        $this->onboarding = $onboarding ?: new WorkspaceOnboardingService();
        $this->channelHealth = $channelHealth ?: new WorkspaceChannelHealthService();
        $this->drafts = $drafts ?: new OnboardingNudgeDraftService();
        $this->audit = $audit ?: new OperatorAuditService();
    }

    public function tableReady(): bool
    {
        try {
            return (bool) Database::queryOne(
                "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'workspace_onboarding_nudges'"
            );
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function summarizeWorkspace(int $workspaceId, int $userId = 0): array
    {
        $workspace = $this->workspaceRow($workspaceId);
        $owner = $this->ownerRow($workspaceId);
        $ownerUserId = (int) ($owner['id'] ?? 0);
        $contextUserId = $userId > 0 ? $userId : $ownerUserId;
        $snapshot = WorkspaceContext::runtimeSnapshot();
        WorkspaceContext::activateRuntimeWorkspace($workspaceId, $contextUserId > 0 ? $contextUserId : 0, 'owner');
        try {
            $state = $this->onboarding->getState($workspaceId, $contextUserId);
            $impact = $this->onboarding->getDashboardSetupImpact($workspaceId, $contextUserId);
        } finally {
            WorkspaceContext::restoreRuntimeWorkspace($snapshot);
        }
        $row = (array) ($state['row'] ?? []);
        $skipped = $this->decodeList($row['skipped_optional_json'] ?? null);
        $missingSteps = array_values(array_diff((array) ($state['required_steps'] ?? []), (array) ($state['completed_steps'] ?? [])));
        $actions = (array) ($impact['actions'] ?? []);
        $nextAction = (array) ($actions[0] ?? $this->actionForMissingStep((string) ($missingSteps[0] ?? ''), (int) ($state['current_step'] ?? 1)));
        $isOperational = (string) ($state['status'] ?? '') === 'completed' && (int) ($impact['score'] ?? 0) >= 80;
        $channelHealth = [];
        $channelSnapshot = null;
        try {
            $channelSnapshot = WorkspaceContext::runtimeSnapshot();
            WorkspaceContext::activateRuntimeWorkspace($workspaceId, $contextUserId > 0 ? $contextUserId : 0, 'owner');
            $channelHealth = $this->channelHealth->summarize($workspaceId, Auth::user() ?: null);
        } catch (\Throwable $e) {
            $channelHealth = [];
        } finally {
            if ($channelSnapshot !== null) {
                WorkspaceContext::restoreRuntimeWorkspace($channelSnapshot);
            }
        }

        return [
            'workspace' => $workspace,
            'owner' => $owner,
            'owner_user_id' => $ownerUserId,
            'status' => (string) ($state['status'] ?? 'not_started'),
            'current_step' => (int) ($state['current_step'] ?? 1),
            'completed_steps' => (array) ($state['completed_steps'] ?? []),
            'missing_steps' => $missingSteps,
            'selected_channel' => (string) ($row['communication_channel'] ?? ''),
            'quick_start' => in_array('quick_start', $skipped, true),
            'readiness_score' => (int) ($state['readiness']['readiness_score'] ?? 0),
            'operational_score' => (int) ($impact['score'] ?? 0),
            'is_operational' => $isOperational,
            'next_action' => $nextAction,
            'setup_actions' => $actions,
            'channel_health' => $channelHealth,
            'last_nudge' => $this->latestNudge($workspaceId),
            'is_stuck' => $this->isStuck($workspace, $state, $impact),
        ];
    }

    public function decorateDirectoryRows(array $rows): array
    {
        foreach ($rows as &$row) {
            $workspaceId = (int) ($row['id'] ?? 0);
            if ($workspaceId <= 0) {
                continue;
            }
            try {
                $summary = $this->summarizeWorkspace($workspaceId, (int) ($row['owner_user_id'] ?? 0));
            } catch (\Throwable $e) {
                $summary = [
                    'status' => 'unknown',
                    'current_step' => 1,
                    'operational_score' => 0,
                    'next_action' => ['title' => 'Review onboarding'],
                    'last_nudge' => null,
                    'is_stuck' => false,
                ];
            }
            $row['onboarding_summary'] = $summary;
        }
        unset($row);
        return $rows;
    }

    public function createDraft(int $workspaceId, int $actorUserId, string $channel = 'email', ?string $reason = null): array
    {
        $channel = $this->normalizeChannel($channel);
        $summary = $this->summarizeWorkspace($workspaceId, $actorUserId);
        $ownerUserId = (int) ($summary['owner_user_id'] ?? 0);
        if ($ownerUserId <= 0) {
            throw new \RuntimeException('No active owner is available for this workspace.');
        }

        $context = $this->drafts->buildContext($workspaceId, $ownerUserId, $summary);
        $draft = $this->drafts->draft($context, $channel);
        $triggerKey = $this->triggerKey($summary);
        $actionUrl = $this->relativeActionUrl((string) ($summary['next_action']['url'] ?? 'onboarding.php'));

        Database::execute(
            "INSERT INTO workspace_onboarding_nudges
             (workspace_id, owner_user_id, created_by_user_id, trigger_key, channel, status, subject, body, action_url, metadata_json)
             VALUES (?, ?, ?, ?, ?, 'draft', ?, ?, ?, ?)",
            [
                $workspaceId,
                $ownerUserId,
                $actorUserId,
                $triggerKey,
                $channel,
                (string) ($draft['subject'] ?? 'Finish workspace setup'),
                (string) ($draft['body'] ?? ''),
                $actionUrl,
                json_encode([
                    'reason' => $reason,
                    'draft_source' => (string) ($draft['source'] ?? 'fallback'),
                    'confidence' => (string) ($draft['confidence'] ?? 'low'),
                    'summary' => $this->compactSummary($summary),
                ], JSON_UNESCAPED_SLASHES),
            ]
        );
        $nudgeId = (int) Database::lastInsertId();
        $this->audit->log('onboarding_nudge_drafted', $actorUserId, $workspaceId, $reason, [
            'nudge_id' => $nudgeId,
            'channel' => $channel,
            'trigger_key' => $triggerKey,
        ], $ownerUserId);

        return $this->getNudge($nudgeId);
    }

    public function sendNudge(int $nudgeId, int $actorUserId, array $overrides = [], ?string $reason = null): array
    {
        $nudge = $this->getNudge($nudgeId);
        $workspaceId = (int) ($nudge['workspace_id'] ?? 0);
        $ownerUserId = (int) ($nudge['owner_user_id'] ?? 0);
        $subject = trim((string) ($overrides['subject'] ?? $nudge['subject'] ?? 'Finish workspace setup'));
        $body = trim((string) ($overrides['body'] ?? $nudge['body'] ?? ''));
        $channel = (string) ($overrides['channel'] ?? $nudge['channel'] ?? 'email');
        $channel = $this->normalizeChannel($channel);
        if ($body === '') {
            throw new \RuntimeException('Nudge body is required.');
        }

        $status = self::STATUS_SENT;
        $error = null;
        $delivery = [];
        try {
            if ($channel === 'in_app' || $channel === 'push') {
                $delivery = $this->sendInApp($workspaceId, $ownerUserId, $subject, $body, (string) ($nudge['action_url'] ?? 'onboarding.php'));
            } elseif ($channel === 'email') {
                $delivery = $this->sendEmail($ownerUserId, $subject, $body, (string) ($nudge['action_url'] ?? 'onboarding.php'));
            } elseif ($channel === 'whatsapp') {
                $delivery = $this->sendWhatsAppDraftOnly($workspaceId, $ownerUserId);
                $status = self::STATUS_SKIPPED;
                $error = (string) ($delivery['message'] ?? 'WhatsApp draft kept for manual delivery.');
            } else {
                throw new \RuntimeException('Unsupported nudge channel.');
            }
        } catch (\Throwable $e) {
            $status = self::STATUS_FAILED;
            $error = $e->getMessage();
            $delivery = ['success' => false, 'error' => $error];
        }

        $metadata = json_decode((string) ($nudge['metadata_json'] ?? '{}'), true);
        if (!is_array($metadata)) {
            $metadata = [];
        }
        $metadata['delivery'] = $delivery;
        $metadata['send_reason'] = $reason;

        Database::execute(
            "UPDATE workspace_onboarding_nudges
             SET channel = ?,
                 subject = ?,
                 body = ?,
                 status = ?,
                 sent_at = IF(? = 'sent', NOW(), sent_at),
                 delivery_error = ?,
                 metadata_json = ?,
                 updated_at = NOW()
             WHERE id = ?",
            [
                $channel,
                $subject,
                $body,
                $status,
                $status,
                $error,
                json_encode($metadata, JSON_UNESCAPED_SLASHES),
                $nudgeId,
            ]
        );

        $this->audit->log($status === self::STATUS_SENT ? 'onboarding_nudge_sent' : 'onboarding_nudge_delivery_' . $status, $actorUserId, $workspaceId, $reason, [
            'nudge_id' => $nudgeId,
            'channel' => $channel,
            'status' => $status,
            'delivery_error' => $error,
        ], $ownerUserId);

        return $this->getNudge($nudgeId);
    }

    public function createQueuedDraft(
        int $workspaceId,
        int $actorUserId = 0,
        string $channel = 'in_app',
        ?string $scheduledAt = null,
        ?string $reason = null,
        bool $enforceCooldown = true
    ): array {
        $channel = $this->normalizeChannel($channel);
        $summary = $this->summarizeWorkspace($workspaceId, $actorUserId);
        if (!empty($summary['is_operational'])) {
            return ['status' => self::STATUS_SKIPPED, 'reason' => 'workspace_operational'];
        }

        $triggerKey = $this->triggerKey($summary);
        if ($enforceCooldown && $this->recentNudgeForTrigger($workspaceId, $triggerKey, $channel, self::COOLDOWN_HOURS)) {
            return ['status' => self::STATUS_SKIPPED, 'reason' => 'cooldown'];
        }

        $draft = $this->createDraft($workspaceId, $actorUserId, $channel, $reason ?? 'Queued lifecycle nudge');
        $metadata = json_decode((string) ($draft['metadata_json'] ?? '{}'), true);
        if (!is_array($metadata)) {
            $metadata = [];
        }
        $metadata['queued_by'] = $actorUserId > 0 ? 'operator' : 'automation';
        $metadata['queued_reason'] = $reason;

        Database::execute(
            "UPDATE workspace_onboarding_nudges
             SET status = 'queued',
                 scheduled_at = ?,
                 metadata_json = ?,
                 updated_at = NOW()
             WHERE id = ?",
            [
                $scheduledAt ?: date('Y-m-d H:i:s'),
                json_encode($metadata, JSON_UNESCAPED_SLASHES),
                (int) $draft['id'],
            ]
        );

        $this->audit->log('onboarding_nudge_queued', $actorUserId, $workspaceId, $reason, [
            'nudge_id' => (int) $draft['id'],
            'channel' => $channel,
            'trigger_key' => $triggerKey,
            'scheduled_at' => $scheduledAt,
        ], (int) ($summary['owner_user_id'] ?? 0) ?: null);

        return $this->getNudge((int) $draft['id']);
    }

    public function processDueQueuedNudges(int $limit = 50, int $actorUserId = 0, bool $force = false): array
    {
        if (!$force && !$this->automationEnabled()) {
            return ['success' => true, 'automation_enabled' => false, 'processed' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'results' => []];
        }
        if (!$this->tableReady()) {
            return ['success' => true, 'automation_enabled' => $this->automationEnabled(), 'processed' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'results' => []];
        }

        $limit = max(1, min(200, $limit));
        $rows = Database::query(
            "SELECT id
             FROM workspace_onboarding_nudges
             WHERE status = 'queued'
               AND (scheduled_at IS NULL OR scheduled_at <= NOW())
             ORDER BY COALESCE(scheduled_at, created_at), id
             LIMIT {$limit}"
        );

        $summary = ['success' => true, 'automation_enabled' => $force || $this->automationEnabled(), 'processed' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0, 'results' => []];
        foreach ($rows as $row) {
            $summary['processed']++;
            $queuedNudge = $this->getNudge((int) $row['id']);
            $workspaceSummary = $this->summarizeWorkspace((int) ($queuedNudge['workspace_id'] ?? 0), $actorUserId);
            if (!empty($workspaceSummary['is_operational'])) {
                $metadata = json_decode((string) ($queuedNudge['metadata_json'] ?? '{}'), true);
                if (!is_array($metadata)) {
                    $metadata = [];
                }
                $metadata['skip_reason'] = 'workspace_operational';
                Database::execute(
                    "UPDATE workspace_onboarding_nudges
                     SET status = 'skipped',
                         delivery_error = 'Workspace is already operational.',
                         metadata_json = ?,
                         updated_at = NOW()
                     WHERE id = ?",
                    [json_encode($metadata, JSON_UNESCAPED_SLASHES), (int) $row['id']]
                );
                $summary['skipped']++;
                $summary['results'][] = ['id' => (int) $row['id'], 'status' => self::STATUS_SKIPPED, 'reason' => 'workspace_operational'];
                continue;
            }
            $result = $this->sendNudge((int) $row['id'], $actorUserId, [], 'Automated onboarding lifecycle nudge');
            $status = (string) ($result['status'] ?? self::STATUS_FAILED);
            if ($status === self::STATUS_SENT) {
                $summary['sent']++;
            } elseif ($status === self::STATUS_SKIPPED) {
                $summary['skipped']++;
            } else {
                $summary['failed']++;
            }
            $summary['results'][] = ['id' => (int) $row['id'], 'status' => $status];
        }
        return $summary;
    }

    public function queueLifecycleNudgesForStuckWorkspaces(
        int $limit = 100,
        string $channel = 'in_app',
        int $actorUserId = 0,
        bool $force = false
    ): array {
        if (!$force && !$this->automationEnabled()) {
            return ['success' => true, 'automation_enabled' => false, 'scanned' => 0, 'queued' => 0, 'skipped' => 0, 'results' => []];
        }
        if (!$this->tableReady() || !$this->onboarding->tableReady()) {
            return ['success' => true, 'automation_enabled' => $force || $this->automationEnabled(), 'scanned' => 0, 'queued' => 0, 'skipped' => 0, 'results' => [], 'reason' => 'schema_not_ready'];
        }

        $channel = $this->normalizeChannel($channel);
        $limit = max(1, min(250, $limit));
        $rows = Database::query(
            "SELECT w.id
             FROM workspaces w
             LEFT JOIN workspace_onboarding_state s ON s.workspace_id = w.id
             WHERE COALESCE(w.status, 'active') = 'active'
               AND (
                   COALESCE(s.status, 'in_progress') <> 'completed'
                   OR (s.status = 'completed' AND s.completed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY))
               )
             ORDER BY COALESCE(s.updated_at, w.created_at), w.id
             LIMIT {$limit}"
        );

        $summary = ['success' => true, 'automation_enabled' => $force || $this->automationEnabled(), 'scanned' => 0, 'queued' => 0, 'skipped' => 0, 'results' => []];
        foreach ($rows as $row) {
            $workspaceId = (int) ($row['id'] ?? 0);
            if ($workspaceId <= 0) {
                continue;
            }
            $summary['scanned']++;
            try {
                $workspaceSummary = $this->summarizeWorkspace($workspaceId, $actorUserId);
                if (empty($workspaceSummary['is_stuck'])) {
                    $summary['skipped']++;
                    $summary['results'][] = ['workspace_id' => $workspaceId, 'status' => self::STATUS_SKIPPED, 'reason' => 'not_stuck'];
                    continue;
                }
                $queued = $this->createQueuedDraft($workspaceId, $actorUserId, $channel, null, 'Automated lifecycle scan');
                if ((string) ($queued['status'] ?? '') === self::STATUS_QUEUED) {
                    $summary['queued']++;
                } else {
                    $summary['skipped']++;
                }
                $summary['results'][] = ['workspace_id' => $workspaceId, 'status' => (string) ($queued['status'] ?? self::STATUS_SKIPPED), 'reason' => (string) ($queued['reason'] ?? '')];
            } catch (\Throwable $e) {
                $summary['skipped']++;
                $summary['results'][] = ['workspace_id' => $workspaceId, 'status' => self::STATUS_FAILED, 'reason' => $e->getMessage()];
            }
        }
        return $summary;
    }

    public function resendOwnerSetupLink(int $workspaceId, int $actorUserId, string $reason): array
    {
        $summary = $this->summarizeWorkspace($workspaceId, $actorUserId);
        $owner = (array) ($summary['owner'] ?? []);
        $email = trim((string) ($owner['email'] ?? ''));
        $ownerUserId = (int) ($owner['id'] ?? 0);
        if ($email === '' || $ownerUserId <= 0) {
            throw new \RuntimeException('Workspace owner email is required.');
        }

        $token = Auth::generatePasswordResetToken($email);
        $setupUrl = $this->absoluteUrl($this->relativeActionUrl((string) ($summary['next_action']['url'] ?? 'onboarding.php')));
        $resetUrl = $token ? $this->absoluteUrl('reset_password.php?token=' . rawurlencode($token)) : null;
        $subject = 'Continue setup for ' . (string) ($summary['workspace']['name'] ?? 'your workspace');
        $body = implode("\n\n", array_filter([
            'Hi,',
            'Use this link after signing in to continue setup: ' . $setupUrl,
            $resetUrl ? 'If you need a fresh password, reset it here: ' . $resetUrl : null,
            'No one has been logged in automatically. This link only helps the owner return to setup safely.',
        ]));

        $delivery = $this->sendEmail($ownerUserId, $subject, $body, $setupUrl);
        Database::execute(
            "INSERT INTO workspace_onboarding_nudges
             (workspace_id, owner_user_id, created_by_user_id, trigger_key, channel, status, subject, body, action_url, metadata_json, sent_at)
             VALUES (?, ?, ?, 'owner_setup_link', 'email', 'sent', ?, ?, ?, ?, NOW())",
            [
                $workspaceId,
                $ownerUserId,
                $actorUserId,
                $subject,
                $body,
                $setupUrl,
                json_encode(['reason' => $reason, 'delivery' => $delivery], JSON_UNESCAPED_SLASHES),
            ]
        );

        $this->audit->log('onboarding_owner_setup_link_resent', $actorUserId, $workspaceId, $reason, [
            'setup_url' => $setupUrl,
            'reset_token_created' => $token !== null,
            'delivery' => $delivery,
        ], $ownerUserId);

        return ['setup_url' => $setupUrl, 'reset_url' => $resetUrl, 'delivery' => $delivery];
    }

    public function resetOnboarding(int $workspaceId, int $actorUserId, int $step, string $reason): array
    {
        $this->onboarding->createInProgress($workspaceId);
        $step = max(1, min(WorkspaceOnboardingService::STEP_COUNT, $step));
        $before = $this->summarizeWorkspace($workspaceId, $actorUserId);
        $completed = array_slice($this->requiredSteps(), 0, max(0, $step - 1));
        Database::execute(
            "UPDATE workspace_onboarding_state
             SET status = 'in_progress',
                 current_step = ?,
                 completed_steps_json = ?,
                 skipped_optional_json = JSON_ARRAY(),
                 completed_at = NULL,
                 updated_at = NOW()
             WHERE workspace_id = ?",
            [$step, json_encode($completed), $workspaceId]
        );
        $this->audit->log('onboarding_state_reset', $actorUserId, $workspaceId, $reason, [
            'previous' => $this->compactSummary($before),
            'current_step' => $step,
        ], (int) ($before['owner_user_id'] ?? 0) ?: null);
        return $this->summarizeWorkspace($workspaceId, $actorUserId);
    }

    public function markOnboardingComplete(int $workspaceId, int $actorUserId, string $reason): array
    {
        $this->onboarding->createInProgress($workspaceId);
        $before = $this->summarizeWorkspace($workspaceId, $actorUserId);
        Database::execute(
            "UPDATE workspace_onboarding_state
             SET status = 'completed',
                 current_step = ?,
                 completed_steps_json = ?,
                 completed_at = COALESCE(completed_at, NOW()),
                 updated_at = NOW()
             WHERE workspace_id = ?",
            [WorkspaceOnboardingService::STEP_COUNT, json_encode($this->requiredSteps()), $workspaceId]
        );
        $this->audit->log('onboarding_state_marked_complete', $actorUserId, $workspaceId, $reason, [
            'previous' => $this->compactSummary($before),
        ], (int) ($before['owner_user_id'] ?? 0) ?: null);
        return $this->summarizeWorkspace($workspaceId, $actorUserId);
    }

    public function listRecentNudges(int $workspaceId, int $limit = 10): array
    {
        if (!$this->tableReady()) {
            return [];
        }

        return Database::query(
            "SELECT n.*, u.email AS owner_email, creator.email AS creator_email
             FROM workspace_onboarding_nudges n
             LEFT JOIN users u ON u.id = n.owner_user_id
             LEFT JOIN users creator ON creator.id = n.created_by_user_id
             WHERE n.workspace_id = ?
             ORDER BY n.id DESC
             LIMIT " . max(1, min(50, $limit)),
            [$workspaceId]
        );
    }

    public function latestOwnerPrompt(int $workspaceId, int $userId): ?array
    {
        $summary = $this->summarizeWorkspace($workspaceId, $userId);
        if (!empty($summary['is_operational'])) {
            return null;
        }

        $next = (array) ($summary['next_action'] ?? []);
        $title = trim((string) ($next['title'] ?? 'Finish workspace setup'));
        return [
            'title' => $title,
            'message' => trim((string) ($next['description'] ?? 'Finish your setup so the workspace can start capturing conversations and guiding daily work.')),
            'url' => $this->relativeActionUrl((string) ($next['url'] ?? 'onboarding.php')),
            'score' => (int) ($summary['operational_score'] ?? 0),
            'quick_start' => !empty($summary['quick_start']),
        ];
    }

    private function sendInApp(int $workspaceId, int $ownerUserId, string $subject, string $body, string $actionUrl): array
    {
        $snapshot = WorkspaceContext::runtimeSnapshot();
        WorkspaceContext::activateRuntimeWorkspace($workspaceId, $ownerUserId, 'owner');
        try {
            $notificationId = (new Notifications())->create($ownerUserId, 'onboarding_nudge', $subject, $body, [
                'entity_type' => 'workspace_onboarding',
                'entity_id' => $workspaceId,
                'link' => $this->relativeActionUrl($actionUrl),
                'severity' => 'info',
                'ai_action' => 'finish_onboarding',
            ]);
        } finally {
            WorkspaceContext::restoreRuntimeWorkspace($snapshot);
        }
        return ['success' => true, 'method' => 'notification', 'notification_id' => $notificationId];
    }

    private function sendEmail(int $ownerUserId, string $subject, string $body, string $actionUrl): array
    {
        $owner = Database::queryOne("SELECT email FROM users WHERE id = ? LIMIT 1", [$ownerUserId]);
        $email = trim((string) ($owner['email'] ?? ''));
        if ($email === '') {
            throw new \RuntimeException('Owner email is missing.');
        }
        $smtp = new SMTPClient();
        $fromEmail = $smtp->getPreferredFromEmail('noreply@example.com') ?? 'noreply@example.com';
        $fromName = $smtp->getPreferredFromName(brandProductName()) ?? brandProductName();
        $html = nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'));
        $link = htmlspecialchars($this->absoluteUrl($actionUrl), ENT_QUOTES, 'UTF-8');
        $html .= '<p><a href="' . $link . '" style="display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;padding:10px 14px;border-radius:8px;">Open setup</a></p>';
        $smtp->send($email, $fromEmail, $fromName, $subject, $body, [], $html);
        return ['success' => true, 'method' => $smtp->getLastMethodUsed() ?? 'smtp', 'provider' => $smtp->getLastProviderKey() ?? $smtp->getActiveProviderKey(), 'to' => $email];
    }

    private function sendWhatsAppDraftOnly(int $workspaceId, int $ownerUserId): array
    {
        $row = Database::queryOne(
            "SELECT phone_number
             FROM whatsapp_assistant_authorized_numbers
             WHERE user_id = ?
               AND is_active = 1
             ORDER BY id DESC
             LIMIT 1",
            [$ownerUserId]
        );
        if (!$row) {
            return ['success' => false, 'method' => 'draft_only', 'message' => 'No active WhatsApp owner number is configured. Draft kept for manual copy.'];
        }
        return ['success' => false, 'method' => 'draft_only', 'to' => (string) ($row['phone_number'] ?? ''), 'message' => 'WhatsApp owner number is available, but lifecycle nudges are kept as drafts unless an approved template send path is configured.'];
    }

    private function isStuck(array $workspace, array $state, array $impact): bool
    {
        if ((string) ($state['status'] ?? '') !== 'completed') {
            $updated = strtotime((string) ($state['row']['updated_at'] ?? $workspace['created_at'] ?? ''));
            return $updated !== false && $updated <= strtotime('-1 day');
        }
        return (int) ($impact['score'] ?? 0) < 80;
    }

    private function triggerKey(array $summary): string
    {
        $next = (array) ($summary['next_action'] ?? []);
        $key = trim((string) ($next['key'] ?? 'finish_onboarding'));
        return preg_replace('/[^a-z0-9_]+/i', '_', strtolower($key)) ?: 'finish_onboarding';
    }

    private function latestNudge(int $workspaceId): ?array
    {
        if (!$this->tableReady()) {
            return null;
        }
        $row = Database::queryOne(
            "SELECT * FROM workspace_onboarding_nudges WHERE workspace_id = ? ORDER BY id DESC LIMIT 1",
            [$workspaceId]
        );
        return $row ?: null;
    }

    private function getNudge(int $nudgeId): array
    {
        $row = Database::queryOne("SELECT * FROM workspace_onboarding_nudges WHERE id = ? LIMIT 1", [$nudgeId]);
        if (!$row) {
            throw new \RuntimeException('Onboarding nudge not found.');
        }
        return $row;
    }

    private function ownerRow(int $workspaceId): array
    {
        return Database::queryOne(
            "SELECT u.*
             FROM workspace_memberships wm
             JOIN users u ON u.id = wm.user_id
             WHERE wm.workspace_id = ?
               AND wm.membership_status = 'active'
             ORDER BY wm.is_owner DESC, FIELD(wm.role_slug, 'owner', 'admin', 'accountant', 'expert', 'sales', 'marketing', 'viewer'), wm.id ASC
             LIMIT 1",
            [$workspaceId]
        ) ?: [];
    }

    private function workspaceRow(int $workspaceId): array
    {
        $row = Database::queryOne("SELECT * FROM workspaces WHERE id = ? LIMIT 1", [$workspaceId]);
        if (!$row) {
            throw new \RuntimeException('Workspace not found.');
        }
        return $row;
    }

    private function actionForMissingStep(string $step, int $currentStep): array
    {
        $map = [
            'company' => ['key' => 'finish_company_profile', 'title' => 'Finish company context', 'description' => 'Add the company basics so Clarity understands the business.', 'url' => 'onboarding.php?step=1'],
            'products' => ['key' => 'finish_products', 'title' => 'Add your products and offers', 'description' => 'Add the offer and customer context Clarity should use.', 'url' => 'settings.php?tab=products'],
            'voice' => ['key' => 'finish_voice', 'title' => 'Finish voice setup', 'description' => 'Save the tone Clarity should use in replies.', 'url' => 'settings.php?tab=voice'],
            'automation' => ['key' => 'save_automation_preferences', 'title' => 'Save automation preferences', 'description' => 'Choose how much initiative Clarity should take later.', 'url' => 'settings.php?tab=voice'],
            'review' => ['key' => 'review_ai_brief', 'title' => 'Review business setup', 'description' => 'Review the operating brief and enter the workspace.', 'url' => 'onboarding.php?step=5'],
        ];
        return $map[$step] ?? ['key' => 'finish_onboarding', 'title' => 'Finish onboarding', 'description' => 'Complete the next onboarding step.', 'url' => 'onboarding.php?step=' . max(1, min(5, $currentStep))];
    }

    private function relativeActionUrl(string $url): string
    {
        $url = trim($url) !== '' ? trim($url) : 'onboarding.php';
        if (preg_match('/^https?:\/\//i', $url)) {
            $parts = parse_url($url);
            return ltrim((string) (($parts['path'] ?? '') . (!empty($parts['query']) ? '?' . $parts['query'] : '')), '/');
        }
        return ltrim($url, '/');
    }

    private function absoluteUrl(string $path): string
    {
        if (preg_match('/^https?:\/\//i', $path)) {
            return $path;
        }
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $basePath = function_exists('getBasePath') ? rtrim((string) getBasePath(), '/') : '';
        return $scheme . '://' . $host . $basePath . '/' . ltrim($path, '/');
    }

    private function compactSummary(array $summary): array
    {
        return [
            'status' => (string) ($summary['status'] ?? ''),
            'current_step' => (int) ($summary['current_step'] ?? 0),
            'operational_score' => (int) ($summary['operational_score'] ?? 0),
            'quick_start' => !empty($summary['quick_start']),
            'next_action' => (array) ($summary['next_action'] ?? []),
            'missing_steps' => (array) ($summary['missing_steps'] ?? []),
        ];
    }

    private function recentNudgeForTrigger(int $workspaceId, string $triggerKey, string $channel, int $hours): ?array
    {
        if (!$this->tableReady()) {
            return null;
        }

        $row = Database::queryOne(
            "SELECT *
             FROM workspace_onboarding_nudges
             WHERE workspace_id = ?
               AND trigger_key = ?
               AND channel = ?
               AND status IN ('draft', 'queued', 'sent', 'skipped')
               AND created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
             ORDER BY id DESC
             LIMIT 1",
            [$workspaceId, $triggerKey, $channel, max(1, $hours)]
        );
        return $row ?: null;
    }

    private function automationEnabled(): bool
    {
        $value = strtolower(trim((string) ($_ENV['ONBOARDING_NUDGES_AUTOMATION_ENABLED'] ?? getenv('ONBOARDING_NUDGES_AUTOMATION_ENABLED') ?: '')));
        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    private function normalizeChannel(string $channel): string
    {
        $channel = strtolower(trim($channel));
        if ($channel === 'setup_link') {
            return 'email';
        }
        return in_array($channel, ['in_app', 'email', 'whatsapp', 'push'], true) ? $channel : 'email';
    }

    private function requiredSteps(): array
    {
        return ['company', 'review'];
    }

    private function decodeList($json): array
    {
        $decoded = is_string($json) ? json_decode($json, true) : $json;
        return is_array($decoded) ? array_values(array_filter(array_map('strval', $decoded))) : [];
    }
}
