<?php
/**
 * Campaign Queue Processor
 *
 * Dispatches pending campaign jobs and advances enrollments.
 */

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\Tags;

class CampaignQueueProcessor
{
    private CampaignEnrollmentService $enrollments;
    private EmailService $email;
    private SMSService $sms;
    private WhatsAppService $whatsapp;
    private TouchpointIngestionService $touchpoints;
    private ColdOutreachGovernanceService $coldOutreachGovernance;

    public function __construct()
    {
        $this->enrollments = new CampaignEnrollmentService();
        $this->email = new EmailService();
        $this->sms = new SMSService();
        $this->whatsapp = new WhatsAppService();
        $this->touchpoints = new TouchpointIngestionService();
        $this->coldOutreachGovernance = new ColdOutreachGovernanceService();
    }

    public function processQueue(int $batchSize = 50): int
    {
        $batchSize = min(max((int) $batchSize, 1), 200);
        $jobs = Database::query(
            "SELECT cq.*, cs.*, ce.current_step_order, c.email, c.phone, c.first_name, c.last_name, c.company,
                    camp.objective AS campaign_objective
             FROM campaign_queue cq
             JOIN campaign_steps cs ON cs.id = cq.step_id AND cs.campaign_id = cq.campaign_id
             JOIN campaigns camp ON camp.id = cq.campaign_id AND camp.workspace_id = cq.workspace_id
             JOIN campaign_enrollments ce ON ce.id = cq.enrollment_id AND ce.workspace_id = cq.workspace_id
             JOIN contacts c ON c.id = cq.contact_id AND c.workspace_id = cq.workspace_id
             WHERE cq.status = 'pending'
               AND cq.execute_at <= NOW()
             ORDER BY cq.execute_at ASC, cq.id ASC
             LIMIT " . $batchSize
        );

        $processed = 0;
        foreach ($jobs as $job) {
            $queueId = (int) $job['id'];
            $workspaceId = 0;
            try {
                $workspaceId = $this->resolveWorkspaceId($job, false);
                if ($workspaceId > 0 && (int) ($job['workspace_id'] ?? 0) <= 0) {
                    Database::execute(
                        "UPDATE campaign_queue
                         SET workspace_id = ?
                         WHERE id = ?
                           AND workspace_id IS NULL",
                        [$workspaceId, $queueId]
                    );
                    $job['workspace_id'] = $workspaceId;
                }

                $claimed = $workspaceId > 0
                    ? Database::execute(
                        "UPDATE campaign_queue
                         SET status = 'processing', attempts = attempts + 1, locked_at = NOW()
                         WHERE id = ?
                           AND workspace_id = ?
                           AND status = 'pending'",
                        [$queueId, $workspaceId]
                    )
                    : 0;
                if ($claimed === 0) {
                    continue;
                }

                AsyncWorkspaceRunner::runWithWorkspace(
                    $workspaceId,
                    function () use ($job, $queueId, $workspaceId): void {
                        $providerMessageId = $this->executeAction($job);

                        Database::execute(
                            "INSERT INTO campaign_step_executions
                             (workspace_id, campaign_id, enrollment_id, contact_id, step_id, status, attempts, provider_message_id, started_at, completed_at, metadata)
                             VALUES (?, ?, ?, ?, ?, 'completed', 1, ?, NOW(), NOW(), ?)",
                            [
                                $workspaceId,
                                $job['campaign_id'],
                                $job['enrollment_id'],
                                $job['contact_id'],
                                $job['step_id'],
                                $providerMessageId,
                                json_encode(['queue_id' => $queueId])
                            ]
                        );

                        $this->updateQueueStatus(
                            $queueId,
                            $workspaceId,
                            'completed',
                            null,
                            true
                        );

                        $this->advanceEnrollment($job);
                    },
                    null,
                    'Campaign queue item is missing a valid workspace.'
                );
                $processed++;
            } catch (\Throwable $e) {
                Database::execute(
                    "INSERT INTO campaign_step_executions
                     (workspace_id, campaign_id, enrollment_id, contact_id, step_id, status, attempts, error_message, started_at, completed_at, metadata)
                     VALUES (?, ?, ?, ?, ?, 'failed', 1, ?, NOW(), NOW(), ?)",
                    [
                        $this->resolveWorkspaceId($job),
                        $job['campaign_id'],
                        $job['enrollment_id'],
                        $job['contact_id'],
                        $job['step_id'],
                        substr($e->getMessage(), 0, 500),
                        json_encode(['queue_id' => $queueId])
                    ]
                );

                $queue = Database::queryOne(
                    "SELECT attempts, max_attempts
                     FROM campaign_queue
                     WHERE id = ?
                       AND workspace_id = ?",
                    [$queueId, $workspaceId]
                );
                $attempts = (int) ($queue['attempts'] ?? 0);
                $maxAttempts = (int) ($queue['max_attempts'] ?? 3);

                if ($attempts >= $maxAttempts) {
                    $this->updateQueueStatus(
                        $queueId,
                        $workspaceId,
                        'failed',
                        substr($e->getMessage(), 0, 500),
                        true
                    );
                    $this->enrollments->exitEnrollment((int) $job['enrollment_id'], 'step_failed');
                } else {
                    $this->updateQueueStatus(
                        $queueId,
                        $workspaceId,
                        'pending',
                        substr($e->getMessage(), 0, 500),
                        false,
                        $this->retryDelaySeconds($attempts)
                    );
                }
            }
        }

        return $processed;
    }

    public function processQueueItem(int $queueId, ?int $workspaceId = null): bool
    {
        $job = $this->loadQueueJob($queueId, $workspaceId);
        if ($job === null) {
            throw new \RuntimeException('Campaign queue row not found for this workspace.');
        }

        $resolvedWorkspaceId = $this->resolveWorkspaceId($job, false);
        if ($resolvedWorkspaceId <= 0) {
            throw new \RuntimeException('Campaign queue item is missing a valid workspace.');
        }

        $claimed = Database::execute(
            "UPDATE campaign_queue
             SET status = 'processing', attempts = attempts + 1, locked_at = NOW(), processed_at = NULL
             WHERE id = ?
               AND workspace_id = ?
               AND status IN ('failed', 'pending')",
            [$queueId, $resolvedWorkspaceId]
        );

        if ($claimed === 0) {
            throw new \RuntimeException('Campaign queue row is not replayable in its current state.');
        }

        $job['workspace_id'] = $resolvedWorkspaceId;
        $this->processJob($job, $queueId, $resolvedWorkspaceId);
        return true;
    }

    private function executeAction(array $job): ?string
    {
        $actionType = $job['action_type'];
        $campaignId = (int) $job['campaign_id'];
        $contactId = (int) $job['contact_id'];
        $messageId = null;

        if ($actionType === 'send_email') {
            if (empty($job['email'])) {
                throw new \RuntimeException('Contact has no email');
            }
            if ($this->isSuppressed('email', (string) $job['email'], (int) $job['campaign_id'])) {
                throw new \RuntimeException('Email is suppressed');
            }
            if (!$this->canSendByRateLimit('email', (int) $job['campaign_id'], (string) $job['email'])) {
                throw new \RuntimeException('Email rate limit reached');
            }
            $dispatch = $this->coldOutreachGovernance->planDispatch(
                'email',
                $contactId,
                null,
                'campaign_email',
                ['campaign_id' => $campaignId, 'queue_id' => (int) $job['id']]
            );
            $uuid = $this->email->send(
                $contactId,
                $job['email'],
                (string) ($job['subject'] ?? 'Campaign message'),
                (string) ($job['content'] ?? ''),
                array_filter([
                    'body_html' => (string) ($job['content'] ?? ''),
                    'scheduled_at' => $dispatch['scheduled_at'] ?? null,
                    'sender_profile' => $this->campaignEmailSenderProfile($job),
                ], static fn ($value): bool => $value !== null && $value !== '')
            );
            $email = Database::queryOne(
                "SELECT id
                 FROM emails
                 WHERE workspace_id = ?
                   AND uuid = ?",
                [$this->requireWorkspaceId(), $uuid]
            );
            if ($email) {
                $messageId = (string) $email['id'];
                Database::execute(
                    "UPDATE emails
                     SET campaign_id = ?
                     WHERE workspace_id = ?
                       AND id = ?",
                    [$campaignId, $this->requireWorkspaceId(), $email['id']]
                );
                $this->coldOutreachGovernance->attachReservation((int) ($dispatch['reservation_id'] ?? 0), (int) $email['id'], $uuid);
            }
            $this->touchpoints->ingestTouchpoint([
                'contact_id' => $contactId,
                'campaign_id' => $campaignId,
                'channel' => 'email',
                'touch_type' => 'email_sent',
                'source_table' => 'campaign_steps',
                'source_id' => (int) $job['step_id'],
                'metadata' => ['subject' => $job['subject']]
            ]);
            return $messageId;
        }

        if ($actionType === 'send_sms') {
            if (empty($job['phone'])) {
                throw new \RuntimeException('Contact has no phone');
            }
            if ($this->isSuppressed('sms', (string) $job['phone'], (int) $job['campaign_id'])) {
                throw new \RuntimeException('Phone is suppressed');
            }
            if (!$this->canSendByRateLimit('sms', (int) $job['campaign_id'], (string) $job['phone'])) {
                throw new \RuntimeException('SMS rate limit reached');
            }
            $uuid = $this->sms->storeMessage(
                $contactId,
                $job['phone'],
                (string) ($job['content'] ?? ''),
                ['scheduled_at' => null, 'workspace_id' => $this->requireWorkspaceId()]
            );
            $sms = Database::queryOne(
                "SELECT id
                 FROM sms_messages
                 WHERE workspace_id = ?
                   AND uuid = ?",
                [$this->requireWorkspaceId(), $uuid]
            );
            if ($sms) {
                $messageId = (string) $sms['id'];
                Database::execute(
                    "UPDATE sms_messages
                     SET campaign_id = ?
                     WHERE workspace_id = ?
                       AND id = ?",
                    [$campaignId, $this->requireWorkspaceId(), $sms['id']]
                );
            }
            $this->touchpoints->ingestTouchpoint([
                'contact_id' => $contactId,
                'campaign_id' => $campaignId,
                'channel' => 'sms',
                'touch_type' => 'sms_sent',
                'source_table' => 'campaign_steps',
                'source_id' => (int) $job['step_id']
            ]);
            return $messageId;
        }

        if ($actionType === 'send_whatsapp') {
            if (empty($job['phone'])) {
                throw new \RuntimeException('Contact has no phone');
            }
            if ($this->isSuppressed('whatsapp', (string) $job['phone'], (int) $job['campaign_id'])) {
                throw new \RuntimeException('Phone is suppressed');
            }
            if (!$this->canSendByRateLimit('whatsapp', (int) $job['campaign_id'], (string) $job['phone'])) {
                throw new \RuntimeException('WhatsApp rate limit reached');
            }
            $dispatch = $this->coldOutreachGovernance->planDispatch(
                'whatsapp',
                $contactId,
                null,
                'campaign_whatsapp',
                ['campaign_id' => $campaignId, 'queue_id' => (int) $job['id']]
            );
            $uuid = $this->whatsapp->storeMessage(
                $contactId,
                $job['phone'],
                'text',
                (string) ($job['content'] ?? ''),
                ['scheduled_at' => $dispatch['scheduled_at'] ?? null, 'workspace_id' => $this->requireWorkspaceId()]
            );
            $wa = Database::queryOne(
                "SELECT id
                 FROM whatsapp_messages
                 WHERE workspace_id = ?
                   AND uuid = ?",
                [$this->requireWorkspaceId(), $uuid]
            );
            if ($wa) {
                $messageId = (string) $wa['id'];
                Database::execute(
                    "UPDATE whatsapp_messages
                     SET campaign_id = ?
                     WHERE workspace_id = ?
                       AND id = ?",
                    [$campaignId, $this->requireWorkspaceId(), $wa['id']]
                );
                $this->coldOutreachGovernance->attachReservation((int) ($dispatch['reservation_id'] ?? 0), (int) $wa['id'], $uuid);
            }
            $this->touchpoints->ingestTouchpoint([
                'contact_id' => $contactId,
                'campaign_id' => $campaignId,
                'channel' => 'whatsapp',
                'touch_type' => 'whatsapp_sent',
                'source_table' => 'campaign_steps',
                'source_id' => (int) $job['step_id']
            ]);
            return $messageId;
        }

        if ($actionType === 'add_tag') {
            $settings = json_decode($job['settings'] ?? '[]', true) ?? [];
            $tagId = (int) ($settings['tag_id'] ?? 0);
            if ($tagId <= 0) {
                throw new \RuntimeException('Tag action requires settings.tag_id');
            }
            $tags = new Tags();
            $tags->assign($tagId, 'contact', $contactId);
            return null;
        }

        if ($actionType === 'wait') {
            return null;
        }

        return null;
    }

    private function loadQueueJob(int $queueId, ?int $workspaceId = null): ?array
    {
        $sql = "SELECT cq.*, cs.*, ce.current_step_order, c.email, c.phone, c.first_name, c.last_name, c.company,
                       camp.objective AS campaign_objective
                FROM campaign_queue cq
                JOIN campaign_steps cs ON cs.id = cq.step_id AND cs.campaign_id = cq.campaign_id
                JOIN campaigns camp ON camp.id = cq.campaign_id AND camp.workspace_id = cq.workspace_id
                JOIN campaign_enrollments ce ON ce.id = cq.enrollment_id AND ce.workspace_id = cq.workspace_id
                JOIN contacts c ON c.id = cq.contact_id AND c.workspace_id = cq.workspace_id
                WHERE cq.id = ?";
        $params = [$queueId];
        if ($workspaceId !== null && $workspaceId > 0) {
            $sql .= " AND cq.workspace_id = ?";
            $params[] = $workspaceId;
        }
        $sql .= " LIMIT 1";

        return Database::queryOne($sql, $params);
    }

    private function campaignEmailSenderProfile(array $job): string
    {
        $settings = $this->decodeSettings($job['settings'] ?? null);
        $explicit = strtolower(trim((string) ($settings['sender_profile'] ?? $settings['email_sender_profile'] ?? '')));
        if (in_array($explicit, ['nurture', 'nurture_email'], true)) {
            return 'nurture';
        }

        $signals = [
            (string) ($job['campaign_objective'] ?? $job['objective'] ?? ''),
            (string) ($job['step_name'] ?? ''),
            (string) ($job['template_ref'] ?? ''),
            (string) ($settings['intent_key'] ?? ''),
            (string) ($settings['template_key'] ?? ''),
            (string) ($settings['workflow_intent'] ?? ''),
        ];
        if (isset($settings['workflow_intents']) && is_array($settings['workflow_intents'])) {
            foreach ($settings['workflow_intents'] as $intent) {
                $signals[] = (string) $intent;
            }
        }

        foreach ($signals as $signal) {
            if (str_contains(strtolower($signal), 'nurture')) {
                return 'nurture';
            }
        }

        return '';
    }

    private function decodeSettings($settings): array
    {
        if (is_array($settings)) {
            return $settings;
        }
        if (!is_string($settings) || trim($settings) === '') {
            return [];
        }
        $decoded = json_decode($settings, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function processJob(array $job, int $queueId, int $workspaceId): void
    {
        try {
            AsyncWorkspaceRunner::runWithWorkspace(
                $workspaceId,
                function () use ($job, $queueId, $workspaceId): void {
                    $providerMessageId = $this->executeAction($job);

                    Database::execute(
                        "INSERT INTO campaign_step_executions
                         (workspace_id, campaign_id, enrollment_id, contact_id, step_id, status, attempts, provider_message_id, started_at, completed_at, metadata)
                         VALUES (?, ?, ?, ?, ?, 'completed', 1, ?, NOW(), NOW(), ?)",
                        [
                            $workspaceId,
                            $job['campaign_id'],
                            $job['enrollment_id'],
                            $job['contact_id'],
                            $job['step_id'],
                            $providerMessageId,
                            json_encode(['queue_id' => $queueId]),
                        ]
                    );

                    $this->updateQueueStatus(
                        $queueId,
                        $workspaceId,
                        'completed',
                        null,
                        true
                    );

                    $this->advanceEnrollment($job);
                },
                null,
                'Campaign queue item is missing a valid workspace.'
            );
        } catch (\Throwable $e) {
            Database::execute(
                "INSERT INTO campaign_step_executions
                 (workspace_id, campaign_id, enrollment_id, contact_id, step_id, status, attempts, error_message, started_at, completed_at, metadata)
                 VALUES (?, ?, ?, ?, ?, 'failed', 1, ?, NOW(), NOW(), ?)",
                [
                    $workspaceId,
                    $job['campaign_id'],
                    $job['enrollment_id'],
                    $job['contact_id'],
                    $job['step_id'],
                    substr($e->getMessage(), 0, 500),
                    json_encode(['queue_id' => $queueId]),
                ]
            );

            $queue = Database::queryOne(
                "SELECT attempts, max_attempts
                 FROM campaign_queue
                 WHERE id = ?
                   AND workspace_id = ?",
                [$queueId, $workspaceId]
            );
            $attempts = (int) ($queue['attempts'] ?? 0);
            $maxAttempts = (int) ($queue['max_attempts'] ?? 3);

            if ($attempts >= $maxAttempts) {
                $this->updateQueueStatus(
                    $queueId,
                    $workspaceId,
                    'failed',
                    substr($e->getMessage(), 0, 500),
                    true
                );
                $this->enrollments->exitEnrollment((int) $job['enrollment_id'], 'step_failed');
            } else {
                $this->updateQueueStatus(
                    $queueId,
                    $workspaceId,
                    'pending',
                    substr($e->getMessage(), 0, 500),
                    false,
                    $this->retryDelaySeconds($attempts)
                );
            }

            throw $e;
        }
    }

    private function advanceEnrollment(array $job): void
    {
        $settings = json_decode($job['settings'] ?? '[]', true) ?? [];
        $waitMinutes = (int) ($job['wait_minutes'] ?? 0);
        if (isset($settings['wait_minutes'])) {
            $waitMinutes = (int) $settings['wait_minutes'];
        }

        $nextStepOrder = (int) $job['step_order'] + 1;
        $sql = "SELECT id, step_order
             FROM campaign_steps
             WHERE campaign_id = ?
               AND step_order = ?
               AND is_active = 1";
        $params = [$job['campaign_id'], $nextStepOrder];
        $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        if ($workspaceId > 0) {
            $sql .= " AND workspace_id = ?";
            $params[] = $workspaceId;
        }
        $sql .= " LIMIT 1";
        $nextStep = Database::queryOne($sql, $params);

        if (!$nextStep) {
            $this->enrollments->markCompleted((int) $job['enrollment_id']);
            return;
        }

        $nextRunAt = date('Y-m-d H:i:s', strtotime('+' . max(0, $waitMinutes) . ' minutes'));
        $this->enrollments->updateProgress(
            (int) $job['enrollment_id'],
            (int) $nextStep['id'],
            (int) $nextStep['step_order'],
            $nextRunAt
        );
    }

    private function retryDelaySeconds(int $attempt): int
    {
        if ($attempt <= 1) {
            return 60;
        }
        if ($attempt === 2) {
            return 300;
        }
        return 900;
    }

    private function isSuppressed(string $channel, string $value, int $campaignId): bool
    {
        $row = Database::queryOne(
            "SELECT id
             FROM suppression_list
             WHERE channel = ?
               AND value = ?
               AND campaign_id = ?
             LIMIT 1",
            [$channel, $value, $campaignId]
        );
        return (bool) $row;
    }

    private function canSendByRateLimit(string $channel, int $campaignId, string $identity): bool
    {
        $rate = Database::queryOne(
            "SELECT * FROM campaign_rate_limits
             WHERE channel = ?
               AND campaign_id = ?
             LIMIT 1",
            [$channel, $campaignId]
        );
        if (!$rate) {
            return true;
        }

        $startHour = (int) ($rate['start_hour'] ?? 0);
        $endHour = (int) ($rate['end_hour'] ?? 23);
        $hour = (int) date('G');
        if ($hour < $startHour || $hour > $endHour) {
            return false;
        }

        $domainPattern = (string) ($rate['domain_pattern'] ?? '');
        if ($channel === 'email' && $domainPattern !== '') {
            $domain = '';
            if (strpos($identity, '@') !== false) {
                $domain = substr(strrchr($identity, '@'), 1) ?: '';
            }
            if ($domain !== '' && stripos($domain, $domainPattern) === false) {
                return true;
            }
        }

        $minuteRow = Database::queryOne(
            "SELECT COUNT(*) as count
             FROM campaign_step_executions cse
             JOIN campaign_steps cs ON cs.id = cse.step_id AND cs.campaign_id = cse.campaign_id
             WHERE cse.campaign_id = ?
               AND cse.workspace_id = ?
               AND cse.status = 'completed'
               AND cs.channel = ?
               AND cse.completed_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)",
            [$campaignId, $this->requireWorkspaceId(), $channel]
        );
        $minuteCount = (int) ($minuteRow['count'] ?? 0);
        if ($minuteCount >= (int) ($rate['per_minute'] ?? 60)) {
            return false;
        }

        $hourRow = Database::queryOne(
            "SELECT COUNT(*) as count
             FROM campaign_step_executions cse
             JOIN campaign_steps cs ON cs.id = cse.step_id AND cs.campaign_id = cse.campaign_id
             WHERE cse.campaign_id = ?
               AND cse.workspace_id = ?
               AND cse.status = 'completed'
               AND cs.channel = ?
               AND cse.completed_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)",
            [$campaignId, $this->requireWorkspaceId(), $channel]
        );
        $hourCount = (int) ($hourRow['count'] ?? 0);
        if ($hourCount >= (int) ($rate['per_hour'] ?? 1000)) {
            return false;
        }

        $dayRow = Database::queryOne(
            "SELECT COUNT(*) as count
             FROM campaign_step_executions cse
             JOIN campaign_steps cs ON cs.id = cse.step_id AND cs.campaign_id = cse.campaign_id
             WHERE cse.campaign_id = ?
               AND cse.workspace_id = ?
               AND cse.status = 'completed'
               AND cs.channel = ?
               AND cse.completed_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)",
            [$campaignId, $this->requireWorkspaceId(), $channel]
        );
        $dayCount = (int) ($dayRow['count'] ?? 0);
        if ($dayCount >= (int) ($rate['per_day'] ?? 10000)) {
            return false;
        }

        return true;
    }

    private function resolveWorkspaceId(array $job, bool $allowContextFallback = true): int
    {
        $workspaceId = (int) ($job['workspace_id'] ?? 0);
        if ($workspaceId > 0) {
            return $workspaceId;
        }

        $contextWorkspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        if ($allowContextFallback && $contextWorkspaceId > 0) {
            return $contextWorkspaceId;
        }

        $contactId = (int) ($job['contact_id'] ?? 0);
        if ($contactId > 0) {
            $contact = Database::queryOne(
                "SELECT workspace_id
                 FROM contacts
                 WHERE id = ?
                 LIMIT 1",
                [$contactId]
            );
            $workspaceId = (int) ($contact['workspace_id'] ?? 0);
            if ($workspaceId > 0) {
                return $workspaceId;
            }
        }

        $campaignId = (int) ($job['campaign_id'] ?? 0);
        if ($campaignId > 0) {
            $campaign = Database::queryOne(
                "SELECT workspace_id
                 FROM campaigns
                 WHERE id = ?
                 LIMIT 1",
                [$campaignId]
            );
            return (int) ($campaign['workspace_id'] ?? 0);
        }

        return 0;
    }

    private function requireWorkspaceId(): int
    {
        $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        if ($workspaceId <= 0) {
            throw new \RuntimeException('An active workspace is required for campaign queue processing.');
        }

        return $workspaceId;
    }

    private function updateQueueStatus(
        int $queueId,
        int $workspaceId,
        string $status,
        ?string $errorMessage,
        bool $markProcessedAt,
        ?int $retryDelaySeconds = null
    ): void {
        $updates = ['status = ?'];
        $params = [$status];

        if ($errorMessage !== null || $status !== 'completed') {
            $updates[] = 'error_message = ?';
            $params[] = $errorMessage !== null ? substr($errorMessage, 0, 500) : null;
        }

        if ($markProcessedAt) {
            $updates[] = 'processed_at = NOW()';
        }

        if ($retryDelaySeconds !== null) {
            $updates[] = 'execute_at = DATE_ADD(NOW(), INTERVAL ? SECOND)';
            $params[] = $retryDelaySeconds;
        }

        $params[] = $queueId;
        $params[] = $workspaceId;

        Database::execute(
            "UPDATE campaign_queue
             SET " . implode(', ', $updates) . "
             WHERE id = ?
               AND workspace_id = ?",
            $params
        );
    }
}
