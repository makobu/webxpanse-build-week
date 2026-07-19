<?php

namespace CRM\Services;

use CRM\Database;

class DefaultWorkspaceOpsAutomationService
{
    private const WAITING_ON_OWNER_SUMMARY = 'Automated owner follow-up sent from Platform Ops.';
    private const CLEARED_SUMMARY = 'Signal cleared after automated owner follow-up.';

    private const AUTOMATION_RULES = [
        'low_token_balance' => [
            'template_slug' => 'platform-ops-low_token_warning',
            'action_key' => 'email_owner_low_tokens',
        ],
        'stuck_onboarding' => [
            'template_slug' => 'platform-ops-onboarding_recovery',
            'action_key' => 'email_owner_onboarding_recovery',
            'minimum_age_hours' => 24,
            'source_time_key' => 'onboarding_updated_at',
        ],
        'channel_setup_gap' => [
            'template_slug' => 'platform-ops-channel_setup_reminder',
            'action_key' => 'email_owner_channel_setup',
            'minimum_age_hours' => 24,
            'source_time_key' => 'workspace_created_at',
        ],
    ];

    private const HUMAN_ONLY_SIGNALS = [
        'failed_billing_provider_event',
        'owner_contact_sync_failure',
        'missing_platform_ops_asset',
        'missing_default_workspace_ai_prompt',
    ];

    private DefaultWorkspaceService $defaultWorkspace;
    private EmailService $emailService;
    private EmailTemplates $templates;
    private OperatorAuditService $audit;

    public function __construct(
        ?DefaultWorkspaceService $defaultWorkspace = null,
        ?EmailService $emailService = null,
        ?EmailTemplates $templates = null,
        ?OperatorAuditService $audit = null
    ) {
        $this->defaultWorkspace = $defaultWorkspace ?: new DefaultWorkspaceService();
        $this->emailService = $emailService ?: new EmailService();
        $this->templates = $templates ?: new EmailTemplates();
        $this->audit = $audit ?: new OperatorAuditService();
    }

    /**
     * @return array<string,mixed>
     */
    public function run(?int $actorUserId = null, int $limit = 50, bool $refreshFirst = true, bool $force = false): array
    {
        $limit = max(1, min(200, $limit));
        $summary = [
            'success' => true,
            'refresh' => null,
            'scanned' => 0,
            'sent' => 0,
            'skipped' => 0,
            'failed' => 0,
            'human_only' => 0,
            'results' => [],
            'warnings' => [],
        ];

        if (!$this->schemaReady()) {
            $summary['success'] = false;
            $summary['warnings'][] = 'Default workspace ops automation schema is missing. Run migrations first.';
            return $summary;
        }

        if ($refreshFirst) {
            $summary['refresh'] = (new DefaultWorkspaceOpsEventService($this->defaultWorkspace, $this->audit))->refreshAll($actorUserId);
        }

        $defaultWorkspaceId = $this->defaultWorkspace->id();
        $rows = Database::query(
            "SELECT id
             FROM default_workspace_ops_events
             WHERE default_workspace_id = ?
               AND status IN ('open', 'in_progress')
               AND (? = 1 OR automation_status <> 'human_only')
             ORDER BY FIELD(priority, 'urgent', 'high', 'medium', 'low'),
                      FIELD(severity, 'critical', 'warning', 'info'),
                      last_seen_at DESC,
                      id DESC
             LIMIT {$limit}",
            [$defaultWorkspaceId, $force ? 1 : 0]
        );

        foreach ($rows as $row) {
            $summary['scanned']++;
            try {
                $result = $this->automateEvent((int) ($row['id'] ?? 0), $actorUserId, $force);
                $status = (string) ($result['status'] ?? 'skipped');
                if ($status === 'sent') {
                    $summary['sent']++;
                } elseif ($status === 'failed') {
                    $summary['failed']++;
                } elseif ($status === 'human_only') {
                    $summary['human_only']++;
                } else {
                    $summary['skipped']++;
                }
                $summary['results'][] = $result;
            } catch (\Throwable $e) {
                $summary['failed']++;
                $summary['results'][] = [
                    'event_id' => (int) ($row['id'] ?? 0),
                    'status' => 'failed',
                    'reason_code' => 'automation_exception',
                    'message' => $e->getMessage(),
                ];
            }
        }

        if ($summary['scanned'] > 0) {
            $this->audit->log('default_workspace_ops_automation_run', $actorUserId, $defaultWorkspaceId, 'Platform Ops automation run completed.', [
                'sent' => $summary['sent'],
                'skipped' => $summary['skipped'],
                'failed' => $summary['failed'],
                'human_only' => $summary['human_only'],
                'force' => $force,
            ]);
        }

        return $summary;
    }

    /**
     * @return array<string,mixed>
     */
    public function automateEvent(int $eventId, ?int $actorUserId = null, bool $force = false): array
    {
        if ($eventId <= 0) {
            throw new \RuntimeException('A valid Platform Ops event is required.');
        }
        if (!$this->schemaReady()) {
            throw new \RuntimeException('Default workspace ops automation schema is missing. Run migrations first.');
        }

        $event = $this->loadEvent($eventId);
        if (!$event) {
            throw new \RuntimeException('Platform Ops event was not found.');
        }

        $decision = $this->automationDecision($event, $force);
        if (($decision['status'] ?? '') !== 'eligible') {
            return $this->recordNonSendResult($event, $actorUserId, $decision);
        }

        $event = $this->ensureOwnerContact($event, $actorUserId);
        $ownerEmail = $this->ownerEmail($event);
        $contactId = (int) ($event['contact_id'] ?? 0);
        if ($contactId <= 0) {
            return $this->recordNonSendResult($event, $actorUserId, [
                'status' => 'failed',
                'reason_code' => 'missing_owner_contact',
                'message' => 'No Platform Ops owner contact is available for automated follow-up.',
            ]);
        }
        if (!$this->isSendableOwnerEmail($ownerEmail)) {
            return $this->recordNonSendResult($event, $actorUserId, [
                'status' => 'skipped',
                'reason_code' => 'invalid_owner_email',
                'message' => 'Owner email is missing, invalid, or reserved for tests.',
            ]);
        }

        $rule = self::AUTOMATION_RULES[(string) $event['signal_type']];
        $templateSlug = (string) $rule['template_slug'];
        $variables = $this->templateVariables($event);
        try {
            $rendered = $this->renderTemplate($templateSlug, $variables);
        } catch (\Throwable $e) {
            return $this->recordNonSendResult($event, $actorUserId, [
                'status' => 'failed',
                'reason_code' => 'template_render_failed',
                'message' => $e->getMessage(),
                'template_slug' => $templateSlug,
                'action_key' => (string) $rule['action_key'],
            ]);
        }

        try {
            $delivery = $this->sendRenderedEmail($event, $contactId, $ownerEmail, $rendered, $actorUserId);
        } catch (\Throwable $e) {
            return $this->recordNonSendResult($event, $actorUserId, [
                'status' => 'failed',
                'reason_code' => 'email_delivery_failed',
                'message' => $e->getMessage(),
                'template_slug' => $templateSlug,
                'action_key' => (string) $rule['action_key'],
            ]);
        }

        if (empty($delivery['success'])) {
            return $this->recordNonSendResult($event, $actorUserId, [
                'status' => 'failed',
                'reason_code' => 'email_delivery_failed',
                'message' => (string) ($delivery['error'] ?? 'Email delivery failed.'),
                'template_slug' => $templateSlug,
                'action_key' => (string) $rule['action_key'],
                'delivery' => $delivery,
            ]);
        }

        $emailUuid = (string) ($delivery['email_uuid'] ?? '');
        Database::execute(
            "UPDATE default_workspace_ops_events
             SET status = 'waiting_on_owner',
                 automation_status = 'sent',
                 automation_action = ?,
                 automation_template_slug = ?,
                 automation_last_attempted_at = NOW(),
                 automation_last_sent_at = NOW(),
                 automation_attempt_count = automation_attempt_count + 1,
                 automation_last_result = ?,
                 automation_last_error = NULL,
                 automation_email_uuid = ?,
                 automation_delivery_json = ?,
                 resolution_summary = ?,
                 updated_at = NOW()
             WHERE id = ?",
            [
                (string) $rule['action_key'],
                $templateSlug,
                'owner_email_sent',
                $emailUuid !== '' ? $emailUuid : null,
                json_encode($delivery, JSON_UNESCAPED_SLASHES),
                self::WAITING_ON_OWNER_SUMMARY,
                $eventId,
            ]
        );

        $runId = $this->recordRun($event, $actorUserId, 'sent', 'owner_email_sent', 'Owner email sent and event moved to waiting_on_owner.', [
            'template_slug' => $templateSlug,
            'action_key' => (string) $rule['action_key'],
            'email_uuid' => $emailUuid,
            'delivery' => $delivery,
        ]);

        $this->audit->log('default_workspace_ops_event_automated', $actorUserId, $this->defaultWorkspace->id(), 'Automated owner follow-up sent from Platform Ops.', [
            'event_id' => $eventId,
            'signal_type' => (string) $event['signal_type'],
            'owner_workspace_id' => (int) ($event['owner_workspace_id'] ?? 0),
            'template_slug' => $templateSlug,
            'email_uuid' => $emailUuid,
            'automation_run_id' => $runId,
        ], !empty($event['owner_user_id']) ? (int) $event['owner_user_id'] : null);

        return [
            'event_id' => $eventId,
            'status' => 'sent',
            'reason_code' => 'owner_email_sent',
            'message' => 'Owner email sent and event moved to waiting_on_owner.',
            'template_slug' => $templateSlug,
            'email_uuid' => $emailUuid,
            'automation_run_id' => $runId,
        ];
    }

    /**
     * @param array<int,string> $activeSignalKeys
     * @return array<string,mixed>
     */
    public function resolveClearedAutomatedEvents(array $activeSignalKeys, ?int $actorUserId = null): array
    {
        if (!$this->schemaReady()) {
            return ['resolved' => 0, 'event_ids' => []];
        }

        $defaultWorkspaceId = $this->defaultWorkspace->id();
        $params = [$defaultWorkspaceId];
        $notIn = '';
        $keys = array_values(array_unique(array_filter(array_map('strval', $activeSignalKeys))));
        if ($keys !== []) {
            $notIn = 'AND active_signal_key NOT IN (' . implode(', ', array_fill(0, count($keys), '?')) . ')';
            $params = array_merge($params, $keys);
        }

        $rows = Database::query(
            "SELECT *
             FROM default_workspace_ops_events
             WHERE default_workspace_id = ?
               AND status = 'waiting_on_owner'
               AND automation_status = 'sent'
               AND automation_last_sent_at IS NOT NULL
               AND active_signal_key IS NOT NULL
               {$notIn}",
            $params
        );

        $resolved = [];
        foreach ($rows as $row) {
            $eventId = (int) ($row['id'] ?? 0);
            if ($eventId <= 0) {
                continue;
            }
            Database::execute(
                "UPDATE default_workspace_ops_events
                 SET status = 'resolved',
                     active_signal_key = NULL,
                     resolved_at = NOW(),
                     resolution_summary = ?,
                     automation_last_result = 'signal_cleared',
                     updated_at = NOW()
                 WHERE id = ?",
                [self::CLEARED_SUMMARY, $eventId]
            );
            $this->recordRun($row, $actorUserId, 'resolved', 'signal_cleared', self::CLEARED_SUMMARY);
            $this->audit->log('default_workspace_ops_event_auto_resolved', $actorUserId, $defaultWorkspaceId, self::CLEARED_SUMMARY, [
                'event_id' => $eventId,
                'signal_type' => (string) ($row['signal_type'] ?? ''),
                'owner_workspace_id' => (int) ($row['owner_workspace_id'] ?? 0),
            ], !empty($row['owner_user_id']) ? (int) $row['owner_user_id'] : null);
            $resolved[] = $eventId;
        }

        return ['resolved' => count($resolved), 'event_ids' => $resolved];
    }

    private function schemaReady(): bool
    {
        return Database::tableExists('default_workspace_ops_events')
            && Database::tableExists('default_workspace_ops_automation_runs')
            && Database::columnExists('default_workspace_ops_events', 'automation_status');
    }

    /**
     * @return array<string,mixed>|null
     */
    private function loadEvent(int $eventId): ?array
    {
        $row = Database::queryOne(
            "SELECT e.*, w.name AS owner_workspace_name, w.slug AS owner_workspace_slug,
                    u.email AS owner_email, u.first_name AS owner_first_name, u.last_name AS owner_last_name,
                    c.first_name AS contact_first_name, c.last_name AS contact_last_name, c.email AS contact_email
             FROM default_workspace_ops_events e
             LEFT JOIN workspaces w ON w.id = e.owner_workspace_id
             LEFT JOIN users u ON u.id = e.owner_user_id
             LEFT JOIN contacts c ON c.id = e.contact_id
             WHERE e.id = ? AND e.default_workspace_id = ?
             LIMIT 1",
            [$eventId, $this->defaultWorkspace->id()]
        );
        if (!$row) {
            return null;
        }
        $row['metadata'] = $this->decodeJson($row['metadata_json'] ?? null);
        return $row;
    }

    /**
     * @param array<string,mixed> $event
     * @return array<string,mixed>
     */
    private function automationDecision(array $event, bool $force): array
    {
        $signalType = (string) ($event['signal_type'] ?? '');
        $severity = (string) ($event['severity'] ?? '');
        if ($severity === 'critical') {
            return ['status' => 'human_only', 'reason_code' => 'critical_signal', 'message' => 'Critical Platform Ops signals require human review.'];
        }
        if (in_array($signalType, self::HUMAN_ONLY_SIGNALS, true)) {
            return ['status' => 'human_only', 'reason_code' => 'human_only_signal', 'message' => 'This Platform Ops signal requires human review.'];
        }
        if (preg_match('/complaint|system[_-]?error/i', $signalType) === 1) {
            return ['status' => 'human_only', 'reason_code' => 'complaint_or_system_error', 'message' => 'Complaints and system errors require human review.'];
        }
        if (!isset(self::AUTOMATION_RULES[$signalType])) {
            return ['status' => 'human_only', 'reason_code' => 'unsupported_signal', 'message' => 'No automation rule is configured for this signal.'];
        }
        if (!$force && !empty($event['automation_last_sent_at'])) {
            return ['status' => 'skipped', 'reason_code' => 'already_sent', 'message' => 'Owner follow-up was already sent for this active signal.'];
        }

        $rule = self::AUTOMATION_RULES[$signalType];
        $minimumAgeHours = (int) ($rule['minimum_age_hours'] ?? 0);
        if ($minimumAgeHours > 0 && !$this->isOldEnoughForAutomation($event, $rule, $minimumAgeHours)) {
            return [
                'status' => 'skipped',
                'reason_code' => 'waiting_for_signal_age',
                'message' => "Automation waits {$minimumAgeHours} hours before contacting this owner.",
            ];
        }

        return ['status' => 'eligible'];
    }

    /**
     * @param array<string,mixed> $event
     * @param array<string,mixed> $rule
     */
    private function isOldEnoughForAutomation(array $event, array $rule, int $minimumAgeHours): bool
    {
        $metadata = (array) ($event['metadata'] ?? []);
        $sourceKey = (string) ($rule['source_time_key'] ?? '');
        $sourceTime = $sourceKey !== '' ? (string) ($metadata[$sourceKey] ?? '') : '';
        if ($sourceTime === '') {
            $sourceTime = (string) ($event['detected_at'] ?? '');
        }
        $timestamp = strtotime($sourceTime);
        if ($timestamp === false) {
            return false;
        }
        return $timestamp <= strtotime('-' . $minimumAgeHours . ' hours');
    }

    /**
     * @param array<string,mixed> $event
     * @return array<string,mixed>
     */
    private function ensureOwnerContact(array $event, ?int $actorUserId): array
    {
        if ((int) ($event['contact_id'] ?? 0) > 0) {
            return $event;
        }

        $ownerWorkspaceId = (int) ($event['owner_workspace_id'] ?? 0);
        if ($ownerWorkspaceId <= 0) {
            return $event;
        }

        $context = $this->ownerContactContext($ownerWorkspaceId);
        if ($context === null) {
            try {
                (new DefaultWorkspaceOwnerContactReconciliationService($this->defaultWorkspace))->run($actorUserId, 'Resolve owner contact before Platform Ops automation');
            } catch (\Throwable $ignored) {
            }
            $context = $this->ownerContactContext($ownerWorkspaceId);
        }

        if ($context === null) {
            return $event;
        }

        Database::execute(
            "UPDATE default_workspace_ops_events
             SET owner_user_id = COALESCE(owner_user_id, ?),
                 contact_id = COALESCE(contact_id, ?),
                 updated_at = NOW()
             WHERE id = ?",
            [
                !empty($context['owner_user_id']) ? (int) $context['owner_user_id'] : null,
                !empty($context['contact_id']) ? (int) $context['contact_id'] : null,
                (int) $event['id'],
            ]
        );

        return $this->loadEvent((int) $event['id']) ?: $event;
    }

    /**
     * @return array<string,int>|null
     */
    private function ownerContactContext(int $ownerWorkspaceId): ?array
    {
        if (!Database::tableExists('default_workspace_owner_contacts')) {
            return null;
        }

        $row = Database::queryOne(
            "SELECT owner_user_id, contact_id
             FROM default_workspace_owner_contacts
             WHERE default_workspace_id = ?
               AND owner_workspace_id = ?
               AND relationship_status = 'active'
             ORDER BY id ASC
             LIMIT 1",
            [$this->defaultWorkspace->id(), $ownerWorkspaceId]
        );

        if (!$row) {
            return null;
        }

        return [
            'owner_user_id' => (int) ($row['owner_user_id'] ?? 0),
            'contact_id' => (int) ($row['contact_id'] ?? 0),
        ];
    }

    /**
     * @param array<string,mixed> $event
     */
    private function ownerEmail(array $event): string
    {
        return trim((string) (($event['contact_email'] ?? '') ?: ($event['owner_email'] ?? '')));
    }

    private function isSendableOwnerEmail(string $email): bool
    {
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $domain = strtolower((string) substr(strrchr($email, '@') ?: '', 1));
        return !in_array($domain, ['example.test', 'example.invalid', 'test', 'invalid'], true);
    }

    /**
     * @param array<string,mixed> $event
     * @return array<string,string>
     */
    private function templateVariables(array $event): array
    {
        $metadata = (array) ($event['metadata'] ?? []);
        $workspaceName = trim((string) (($event['owner_workspace_name'] ?? '') ?: ($metadata['name'] ?? 'your workspace')));
        $ownerName = $this->ownerName($event);
        $currentStep = max(1, min(5, (int) ($metadata['current_step'] ?? 1)));
        $nextAction = $this->onboardingAction($currentStep);
        $billingStatus = (string) (($metadata['plan_status'] ?? '') ?: 'Billing or subscription needs review');

        return [
            'owner_name' => $ownerName,
            'workspace_name' => $workspaceName,
            'available_tokens' => (string) (int) ($metadata['available_tokens'] ?? 0),
            'top_up_url' => $this->absolutePublicUrl('billing_payment_required.php?tab=tokens#ai-token-refill'),
            'next_action' => $nextAction['title'],
            'next_action_url' => $this->absolutePublicUrl($nextAction['url']),
            'billing_status' => $billingStatus,
            'action_url' => $this->absolutePublicUrl('billing_payment_required.php?tab=packages#workspace-packages'),
            'setup_url' => $this->absolutePublicUrl('workspace_skills.php?module=email'),
            'support_url' => $this->absolutePublicUrl('dashboard.php'),
        ];
    }

    /**
     * @param array<string,mixed> $event
     */
    private function ownerName(array $event): string
    {
        $name = trim(implode(' ', array_filter([
            (string) ($event['contact_first_name'] ?? ''),
            (string) ($event['contact_last_name'] ?? ''),
        ])));
        if ($name !== '') {
            return $name;
        }

        $name = trim(implode(' ', array_filter([
            (string) ($event['owner_first_name'] ?? ''),
            (string) ($event['owner_last_name'] ?? ''),
        ])));
        return $name !== '' ? $name : 'there';
    }

    /**
     * @return array{title:string,url:string}
     */
    private function onboardingAction(int $currentStep): array
    {
        return match ($currentStep) {
            1 => ['title' => 'company context', 'url' => 'onboarding.php?step=1'],
            2 => ['title' => 'products and offers', 'url' => 'settings.php?tab=products'],
            3 => ['title' => 'voice setup', 'url' => 'settings.php?tab=voice'],
            4 => ['title' => 'automation preferences', 'url' => 'settings.php?tab=voice'],
            5 => ['title' => 'Business setup review', 'url' => 'onboarding.php?step=5'],
            default => ['title' => 'workspace setup', 'url' => 'onboarding.php'],
        };
    }

    /**
     * @param array<string,string> $variables
     * @return array<string,string>
     */
    private function renderTemplate(string $templateSlug, array $variables): array
    {
        $snapshot = WorkspaceContext::runtimeSnapshot();
        WorkspaceContext::activateRuntimeWorkspace($this->defaultWorkspace->id());
        try {
            return $this->templates->render($templateSlug, $variables);
        } finally {
            WorkspaceContext::restoreRuntimeWorkspace($snapshot);
        }
    }

    /**
     * @param array<string,mixed> $event
     * @param array<string,string> $rendered
     * @return array<string,mixed>
     */
    private function sendRenderedEmail(array $event, int $contactId, string $ownerEmail, array $rendered, ?int $actorUserId): array
    {
        $snapshot = WorkspaceContext::runtimeSnapshot();
        WorkspaceContext::activateRuntimeWorkspace($this->defaultWorkspace->id(), $actorUserId, 'superadmin');
        try {
            return $this->emailService->sendImmediateDetailed(
                $contactId,
                $ownerEmail,
                (string) ($rendered['subject'] ?? 'Platform Ops update'),
                (string) ($rendered['body_text'] ?? ''),
                [
                    'body_html' => (string) ($rendered['body_html'] ?? ''),
                    'workspace_id' => $this->defaultWorkspace->id(),
                    'user_id' => $actorUserId,
                    'priority' => 5,
                    'metadata' => [
                        'source' => 'default_workspace_ops_automation',
                        'ops_event_id' => (int) ($event['id'] ?? 0),
                    ],
                ]
            );
        } finally {
            WorkspaceContext::restoreRuntimeWorkspace($snapshot);
        }
    }

    /**
     * @param array<string,mixed> $event
     * @param array<string,mixed> $decision
     * @return array<string,mixed>
     */
    private function recordNonSendResult(array $event, ?int $actorUserId, array $decision): array
    {
        $status = (string) ($decision['status'] ?? 'skipped');
        if (!in_array($status, ['skipped', 'failed', 'human_only'], true)) {
            $status = 'skipped';
        }
        $reasonCode = (string) ($decision['reason_code'] ?? $status);
        $message = substr((string) ($decision['message'] ?? $reasonCode), 0, 500);
        $templateSlug = (string) ($decision['template_slug'] ?? $this->ruleValue($event, 'template_slug'));
        $actionKey = (string) ($decision['action_key'] ?? $this->ruleValue($event, 'action_key'));
        $delivery = (array) ($decision['delivery'] ?? []);
        if ($reasonCode === 'already_sent' && (string) ($event['automation_status'] ?? '') === 'sent') {
            $runId = $this->recordRun($event, $actorUserId, 'skipped', $reasonCode, $message, [
                'template_slug' => $templateSlug,
                'action_key' => $actionKey,
            ]);
            return [
                'event_id' => (int) ($event['id'] ?? 0),
                'status' => 'skipped',
                'reason_code' => $reasonCode,
                'message' => $message,
                'template_slug' => $templateSlug,
                'automation_run_id' => $runId,
            ];
        }

        Database::execute(
            "UPDATE default_workspace_ops_events
             SET automation_status = ?,
                 automation_action = NULLIF(?, ''),
                 automation_template_slug = NULLIF(?, ''),
                 automation_last_attempted_at = NOW(),
                 automation_attempt_count = automation_attempt_count + 1,
                 automation_last_result = ?,
                 automation_last_error = ?,
                 automation_delivery_json = ?,
                 updated_at = NOW()
             WHERE id = ?",
            [
                $status,
                $actionKey,
                $templateSlug,
                $reasonCode,
                $status === 'failed' ? $message : null,
                $delivery !== [] ? json_encode($delivery, JSON_UNESCAPED_SLASHES) : null,
                (int) ($event['id'] ?? 0),
            ]
        );

        $runId = $this->recordRun($event, $actorUserId, $status, $reasonCode, $message, [
            'template_slug' => $templateSlug,
            'action_key' => $actionKey,
            'delivery' => $delivery,
        ]);

        return [
            'event_id' => (int) ($event['id'] ?? 0),
            'status' => $status,
            'reason_code' => $reasonCode,
            'message' => $message,
            'template_slug' => $templateSlug,
            'automation_run_id' => $runId,
        ];
    }

    /**
     * @param array<string,mixed> $event
     */
    private function ruleValue(array $event, string $key): string
    {
        $signalType = (string) ($event['signal_type'] ?? '');
        return (string) (self::AUTOMATION_RULES[$signalType][$key] ?? '');
    }

    /**
     * @param array<string,mixed> $event
     * @param array<string,mixed> $metadata
     */
    private function recordRun(array $event, ?int $actorUserId, string $status, string $reasonCode, string $message, array $metadata = []): int
    {
        $templateSlug = (string) ($metadata['template_slug'] ?? $this->ruleValue($event, 'template_slug'));
        $actionKey = (string) ($metadata['action_key'] ?? $this->ruleValue($event, 'action_key'));
        $emailUuid = (string) ($metadata['email_uuid'] ?? '');

        Database::execute(
            "INSERT INTO default_workspace_ops_automation_runs
                (default_workspace_id, ops_event_id, owner_workspace_id, owner_user_id, contact_id, actor_user_id,
                 signal_type, action_key, template_slug, run_status, reason_code, message, email_uuid, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $this->defaultWorkspace->id(),
                !empty($event['id']) ? (int) $event['id'] : null,
                !empty($event['owner_workspace_id']) ? (int) $event['owner_workspace_id'] : null,
                !empty($event['owner_user_id']) ? (int) $event['owner_user_id'] : null,
                !empty($event['contact_id']) ? (int) $event['contact_id'] : null,
                $actorUserId ?: null,
                (string) ($event['signal_type'] ?? ''),
                $actionKey !== '' ? $actionKey : null,
                $templateSlug !== '' ? $templateSlug : null,
                $status,
                $reasonCode !== '' ? substr($reasonCode, 0, 120) : null,
                $message !== '' ? substr($message, 0, 500) : null,
                $emailUuid !== '' ? $emailUuid : null,
                $metadata !== [] ? json_encode($metadata, JSON_UNESCAPED_SLASHES) : null,
            ]
        );

        return (int) Database::lastInsertId();
    }

    private function absolutePublicUrl(string $path): string
    {
        $relative = function_exists('publicUrl') ? publicUrl($path) : '/' . ltrim($path, '/');
        if (preg_match('/^https?:\/\//i', $relative) === 1) {
            return $relative;
        }
        $base = rtrim((string) ($_ENV['APP_URL'] ?? getenv('APP_URL') ?: ''), '/');
        if ($base === '') {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
            $base = $scheme . '://' . $host;
        }
        return $base . '/' . ltrim($relative, '/');
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
