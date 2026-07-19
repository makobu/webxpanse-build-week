<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\AICoach;
use CRM\Modules\Tasks;
use CRM\Modules\UserPreferences;

class EmailAssistantDigestService
{
    private AICoach $coach;
    private Tasks $tasks;
    private UserPreferences $preferences;
    private AssistantEmailRenderer $renderer;
    private SMTPClient $smtp;

    public function __construct()
    {
        $this->coach = new AICoach();
        $this->tasks = new Tasks();
        $this->preferences = new UserPreferences();
        $this->renderer = new AssistantEmailRenderer();
        $this->smtp = new SMTPClient('assistant');
    }

    public function validateDigestConfig(): array
    {
        $workspaceDigest = $this->workspaceDigestConfig();
        $digestEnabled = !empty($workspaceDigest['digest_enabled']);
        $outboundReady = !empty($workspaceDigest['outbound_ready']);
        $message = (string) ($workspaceDigest['message'] ?? 'Install and configure Email Assistant in Marketplace.');
        $latestAttempt = $this->recentAttempts(1)[0] ?? null;
        $lastStatus = strtolower(trim((string) ($latestAttempt['status'] ?? '')));
        $deliveryVerified = $latestAttempt === null ? null : in_array($lastStatus, ['sent', 'success'], true);
        if ($outboundReady && $deliveryVerified === false) {
            $message = 'Workspace Email Assistant is configured, but its most recent delivery failed. Reconnect or verify the saved provider before relying on scheduled sends.';
        } elseif ($outboundReady && $deliveryVerified === null) {
            $message = 'Workspace Email Assistant is configured but has not yet completed a verified delivery.';
        }

        return [
            'digest_enabled' => $digestEnabled,
            'outbound_ready' => $outboundReady,
            'message' => $message,
            'send_time' => trim((string) ($workspaceDigest['send_time'] ?? '07:00')),
            'delivery_verified' => $deliveryVerified,
            'last_delivery_status' => $lastStatus !== '' ? $lastStatus : null,
            'last_delivery_at' => $latestAttempt['sent_at'] ?? null,
        ];
    }

    public static function hasWorkspaceDigestConfigRows(): bool
    {
        try {
            if (!Database::tableExists('workspace_assistant_configs')) {
                return false;
            }

            $row = Database::queryOne(
                "SELECT 1
                 FROM workspace_assistant_configs
                 WHERE assistant_type = 'email'
                 LIMIT 1"
            );

            return !empty($row);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function isLegacyDigestConfigEnabled(): bool
    {
        return false;
    }

    public static function workspaceHasEmailAssistantConfig(int $workspaceId): bool
    {
        try {
            if ($workspaceId <= 0 || !Database::tableExists('workspace_assistant_configs')) {
                return false;
            }

            $row = Database::queryOne(
                "SELECT 1
                 FROM workspace_assistant_configs
                 WHERE workspace_id = ?
                   AND assistant_type = 'email'
                 LIMIT 1",
                [$workspaceId]
            );

            return !empty($row);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @return array<int,int>
     */
    public static function getDigestEnabledWorkspaceIds(): array
    {
        try {
            if (!Database::tableExists('workspace_assistant_configs') || !Database::tableExists('workspaces')) {
                return [];
            }

            $rows = Database::query(
                "SELECT DISTINCT c.workspace_id
                 FROM workspace_assistant_configs c
                 JOIN workspaces w ON w.id = c.workspace_id
                 WHERE c.assistant_type = 'email'
                   AND c.enabled = 1
                   AND w.status IN ('active', 'trialing')
                   AND LOWER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(c.settings_json, '$.digest_enabled')), '')) IN ('1', 'true', 'yes', 'on')
                 ORDER BY c.workspace_id ASC"
            );

            return array_values(array_filter(array_map(
                static fn(array $row): int => (int) ($row['workspace_id'] ?? 0),
                $rows
            ), static fn(int $workspaceId): bool => $workspaceId > 0));
        } catch (\Throwable $e) {
            error_log('EmailAssistantDigestService::getDigestEnabledWorkspaceIds failed: ' . $e->getMessage());
            return [];
        }
    }

    public function getConfiguredRecipients(): array
    {
        $workspaceDigest = $this->workspaceDigestConfig();
        $recipientConfig = trim((string) ($workspaceDigest['recipients'] ?? 'admins'));
        if ($recipientConfig === '' || strtolower($recipientConfig) === 'admins') {
            return $this->getAdminRecipients();
        }

        $recipients = [];
        $seen = [];
        $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        foreach (array_map('trim', explode(',', $recipientConfig)) as $email) {
            if ($email === '') {
                continue;
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $recipients[] = [
                    'user_id' => 0,
                    'email' => $email,
                    'reason' => 'invalid_recipient_email',
                ];
                continue;
            }

            $lowerEmail = strtolower($email);
            if (isset($seen[$lowerEmail])) {
                continue;
            }
            $seen[$lowerEmail] = true;

            $user = $this->lookupDigestRecipientUser($lowerEmail, $workspaceId);
            if (!$user) {
                $recipients[] = [
                    'user_id' => 0,
                    'email' => $email,
                    'reason' => $workspaceId > 0 ? 'email_not_linked_to_workspace_user' : 'email_not_linked_to_user',
                ];
                continue;
            }

            $recipients[] = [
                'user_id' => (int) ($user['id'] ?? 0),
                'email' => (string) ($user['email'] ?? $email),
            ];
        }

        return $recipients;
    }

    public function shouldRunNow(?\DateTimeImmutable $now = null, bool $force = false): array
    {
        $now = $now ?? new \DateTimeImmutable('now');
        $config = $this->validateDigestConfig();

        if (!$config['digest_enabled'] && !$force) {
            return ['due' => false, 'reason' => 'digest_disabled'];
        }

        if (!$config['outbound_ready']) {
            return ['due' => false, 'reason' => 'outbound_not_configured', 'message' => $config['message']];
        }

        $configuredTime = $config['send_time'] !== '' ? $config['send_time'] : '07:00';
        [$hours, $minutes] = array_pad(explode(':', $configuredTime, 2), 2, '00');
        $target = $now->setTime((int) $hours, (int) $minutes, 0);

        if (!$force && $now < $target) {
            return ['due' => false, 'reason' => 'before_send_time', 'send_time' => $configuredTime];
        }

        return ['due' => true, 'reason' => 'ok', 'send_time' => $configuredTime];
    }

    public function sendDigestToUser(int $userId, string $email, bool $isTest = false, ?\DateTimeImmutable $runAt = null): array
    {
        if ($userId <= 0 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Invalid digest recipient.'];
        }

        $runAt = $runAt ?? new \DateTimeImmutable('now');
        $payload = $this->buildDigestPayload($userId, $runAt);
        $recommendations = (array) ($payload['recommendations'] ?? []);
        $displayTasks = (array) ($payload['tasks'] ?? []);
        $workspace = (array) ($payload['workspace'] ?? []);
        $rendered = $this->renderer->renderDigest($displayTasks, $recommendations, $isTest, $runAt, [
            'workspace_name' => (string) ($workspace['name'] ?? ''),
            'tasks_url' => $this->buildPublicUrl('tasks.php'),
        ]);

        $fromEmail = trim((string) ($this->smtp->getPreferredFromEmail('noreply@example.com') ?? '')) ?: 'noreply@example.com';
        $fromName = trim((string) ($this->smtp->getPreferredFromName('Email Assistant') ?? '')) ?: 'Email Assistant';
        $subjectPrefix = $isTest ? 'Your CRM Daily Digest (Test)' : 'Your CRM Daily Digest';
        $subject = $subjectPrefix . ' - ' . $runAt->format('l, F j, Y');

        try {
            $this->smtp->send($email, $fromEmail, $fromName, $subject, $rendered['plain'], [], $rendered['html']);
            $this->logDigestAttempt($userId, $email, $isTest, 'success', null, $runAt);
        } catch (\Throwable $e) {
            $this->logDigestAttempt($userId, $email, $isTest, 'failed', $e->getMessage(), $runAt);
            throw $e;
        }

        return [
            'success' => true,
            'user_id' => $userId,
            'email' => $email,
            'task_count' => count($displayTasks),
            'provider' => $this->smtp->getLastProviderKey() ?: $this->smtp->getActiveProviderKey(),
            'delivery_method' => $this->smtp->getLastMethodUsed() ?: 'unknown',
        ];
    }

    public function buildDigestPayload(int $userId, ?\DateTimeImmutable $runAt = null): array
    {
        $runAt = $runAt ?? new \DateTimeImmutable('now');
        $mode = $this->preferences->getEffectiveAIGuidanceMode($userId);
        $recommendations = $this->coach->generateStarterTaskRecommendations($userId, $mode);
        $displayTasks = $this->getDigestTasks($userId, $runAt);
        $workspace = WorkspaceContext::currentWorkspace() ?? [];

        return [
            'tasks' => $displayTasks,
            'recommendations' => $recommendations,
            'mode' => $mode,
            'workspace' => [
                'id' => (int) ($workspace['id'] ?? $workspace['workspace_id'] ?? 0),
                'name' => (string) ($workspace['name'] ?? $workspace['workspace_name'] ?? ''),
            ],
        ];
    }

    public function hasSuccessfulDigestForToday(int $userId, ?string $date = null): bool
    {
        $date = $date ?: (new \DateTimeImmutable('now'))->format('Y-m-d');
        $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        $workspaceSql = '';
        $params = [$userId, $date];
        if ($workspaceId > 0 && Database::columnExists('email_digest_log', 'workspace_id')) {
            $workspaceSql = " AND workspace_id = ?";
            $params[] = $workspaceId;
        }
        $testSql = Database::columnExists('email_digest_log', 'is_test') ? ' AND is_test = 0' : '';
        $row = Database::queryOne(
            "SELECT 1
             FROM email_digest_log
             WHERE user_id = ?
               AND status = 'success'
               AND DATE(sent_at) = ?
               {$testSql}
               {$workspaceSql}
             LIMIT 1",
            $params
        );

        return !empty($row);
    }

    public function runScheduled(bool $force = false, ?\DateTimeImmutable $now = null): array
    {
        $runAt = $now ?? new \DateTimeImmutable('now');
        $due = $this->shouldRunNow($runAt, $force);
        if (empty($due['due'])) {
            return [
                'ran' => false,
                'reason' => (string) ($due['reason'] ?? 'not_due'),
                'sent' => 0,
                'failed' => 0,
                'skipped' => 0,
                'results' => [],
            ];
        }

        $today = $runAt->format('Y-m-d');
        $sent = 0;
        $failed = 0;
        $skipped = 0;
        $results = [];

        foreach ($this->getConfiguredRecipients() as $recipient) {
            $userId = (int) ($recipient['user_id'] ?? 0);
            $email = trim((string) ($recipient['email'] ?? ''));
            $invalidReason = (string) ($recipient['reason'] ?? 'invalid_recipient');

            if ($userId <= 0) {
                $skipped++;
                $results[] = ['status' => 'skipped', 'reason' => $invalidReason, 'email' => $email];
                continue;
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $skipped++;
                $results[] = ['status' => 'skipped', 'reason' => 'invalid_recipient_email', 'email' => $email, 'user_id' => $userId];
                continue;
            }

            if (!$force && $this->hasSuccessfulDigestForToday($userId, $today)) {
                $skipped++;
                $results[] = ['status' => 'skipped', 'reason' => 'already_sent_today', 'email' => $email, 'user_id' => $userId];
                continue;
            }

            try {
                $result = $this->sendDigestToUser($userId, $email, false, $runAt);
                if (empty($result['success'])) {
                    $failed++;
                    $error = (string) ($result['error'] ?? 'Digest send failed.');
                    $results[] = ['status' => 'failed', 'user_id' => $userId, 'email' => $email, 'error' => $error];
                    continue;
                }
                $sent++;
                $results[] = ['status' => 'sent'] + $result;
            } catch (\Throwable $e) {
                $failed++;
                $results[] = ['status' => 'failed', 'user_id' => $userId, 'email' => $email, 'error' => $e->getMessage()];
            }
        }

        return [
            'ran' => true,
            'reason' => 'ok',
            'sent' => $sent,
            'failed' => $failed,
            'skipped' => $skipped,
            'results' => $results,
        ];
    }

    private function getDigestTasks(int $userId, \DateTimeImmutable $runAt): array
    {
        $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        if ($workspaceId <= 0) {
            return [];
        }

        $today = $runAt->format('Y-m-d');
        $rows = Database::query(
            "SELECT t.*,
                    c.first_name AS contact_first_name,
                    c.last_name AS contact_last_name,
                    c.email AS contact_email,
                    c.company AS contact_company,
                    u1.email AS assigned_to_email,
                    u2.email AS created_by_email
             FROM tasks t
             LEFT JOIN contacts c ON t.contact_id = c.id AND c.workspace_id = t.workspace_id
             LEFT JOIN users u1 ON t.assigned_to = u1.id
             LEFT JOIN users u2 ON t.created_by = u2.id
             WHERE t.workspace_id = ?
               AND (t.assigned_to = ? OR t.created_by = ?)
               AND t.status NOT IN ('completed', 'cancelled')
               AND (t.due_date IS NULL OR DATE(t.due_date) <= ?)
             ORDER BY
               CASE
                 WHEN t.due_date IS NOT NULL AND DATE(t.due_date) < ? THEN 1
                 WHEN t.due_date IS NOT NULL AND DATE(t.due_date) = ? THEN 2
                 ELSE 3
               END,
               CASE t.priority
                 WHEN 'urgent' THEN 1
                 WHEN 'high' THEN 2
                 WHEN 'medium' THEN 3
                 WHEN 'low' THEN 4
                 ELSE 5
               END,
               COALESCE(t.due_date, '9999-12-31') ASC,
               t.created_at DESC
             LIMIT 15",
            [$workspaceId, $userId, $userId, $today, $today, $today]
        );

        return array_map(
            fn(array $task): array => $this->enrichDigestTask($task, $runAt),
            $rows
        );
    }

    private function lookupDigestRecipientUser(string $lowerEmail, int $workspaceId): ?array
    {
        if ($workspaceId > 0 && Database::tableExists('workspace_memberships')) {
            return Database::queryOne(
                "SELECT u.id, u.email
                 FROM users u
                 JOIN workspace_memberships wm ON wm.user_id = u.id
                 WHERE wm.workspace_id = ?
                   AND wm.membership_status = 'active'
                   AND LOWER(u.email) = ?
                 LIMIT 1",
                [$workspaceId, $lowerEmail]
            ) ?: null;
        }

        return Database::queryOne("SELECT id, email FROM users WHERE LOWER(email) = ? LIMIT 1", [$lowerEmail]) ?: null;
    }

    private function enrichDigestTask(array $task, \DateTimeImmutable $runAt): array
    {
        $taskId = (int) ($task['id'] ?? 0);
        $contactId = (int) ($task['contact_id'] ?? 0);
        $contactName = trim(implode(' ', array_filter([
            trim((string) ($task['contact_first_name'] ?? '')),
            trim((string) ($task['contact_last_name'] ?? '')),
        ])));
        if ($contactName === '') {
            $contactName = trim((string) ($task['contact_email'] ?? ''));
        }

        $task['digest_bucket'] = $this->digestTaskBucket((string) ($task['due_date'] ?? ''), $runAt);
        $task['task_url'] = $taskId > 0 ? $this->buildPublicUrl('task_view.php?id=' . $taskId) : '';
        $task['contact_name'] = $contactName;
        $task['contact_company'] = trim((string) ($task['contact_company'] ?? ''));
        $task['contact_url'] = $contactId > 0 ? $this->buildPublicUrl('contact_view.php?id=' . $contactId) : '';
        $task['owner_email'] = trim((string) ($task['assigned_to_email'] ?? ''));
        $task['creator_email'] = trim((string) ($task['created_by_email'] ?? ''));

        return $task;
    }

    private function digestTaskBucket(string $dueDate, \DateTimeImmutable $runAt): string
    {
        $dueAt = $this->parseDigestTaskDate($dueDate, $runAt);
        if ($dueAt === null) {
            return 'unscheduled';
        }

        $today = $runAt->format('Y-m-d');
        $dueDay = $dueAt->format('Y-m-d');

        if ($dueDay < $today) {
            return 'overdue';
        }

        if ($dueDay === $today) {
            return 'due_today';
        }

        return 'future';
    }

    private function buildPublicUrl(string $path): string
    {
        $origin = $this->safeAppUrlOrigin();
        if ($origin === '') {
            return '';
        }

        if (function_exists('\\publicUrl')) {
            $relative = \publicUrl($path);
        } else {
            $appPath = (string) (parse_url((string) ($_ENV['APP_URL'] ?? getenv('APP_URL') ?: ''), PHP_URL_PATH) ?: '');
            $relative = rtrim('/' . trim($appPath, '/'), '/') . '/' . ltrim($path, '/');
        }

        if (preg_match('#^https?://#i', (string) $relative)) {
            return (string) $relative;
        }

        $relative = '/' . ltrim((string) $relative, '/');
        $relative = preg_replace('#/+#', '/', $relative) ?? $relative;

        return rtrim($origin, '/') . $relative;
    }

    private function safeAppUrlOrigin(): string
    {
        $appUrl = trim((string) ($_ENV['APP_URL'] ?? getenv('APP_URL') ?: ''));
        if ($appUrl === '') {
            return '';
        }

        $parts = parse_url($appUrl);
        if (!is_array($parts)) {
            return '';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = trim((string) ($parts['host'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return '';
        }

        $origin = $scheme . '://' . $host;
        if (isset($parts['port'])) {
            $origin .= ':' . (int) $parts['port'];
        }

        return $origin;
    }

    private function parseDigestTaskDate(string $value, \DateTimeImmutable $runAt): ?\DateTimeImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value, $runAt->getTimezone()))->setTimezone($runAt->getTimezone());
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function getAdminRecipients(): array
    {
        $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        if ($workspaceId > 0 && Database::tableExists('workspace_memberships')) {
            return Database::query(
                "SELECT DISTINCT u.id AS user_id, u.email
                 FROM workspace_memberships wm
                 JOIN users u ON u.id = wm.user_id
                 WHERE wm.workspace_id = ?
                   AND wm.membership_status = 'active'
                   AND wm.role_slug IN ('owner', 'admin', 'superadmin')
                   AND u.email IS NOT NULL
                   AND u.email != ''
                 ORDER BY u.email ASC",
                [$workspaceId]
            );
        }

        if (\CRM\Authorization::isRbacAvailable()) {
            return Database::query(
                "SELECT DISTINCT u.id AS user_id, u.email
                 FROM users u
                 JOIN user_roles ur ON ur.user_id = u.id
                 JOIN roles r ON r.id = ur.role_id
                 WHERE r.slug = 'admin'
                   AND r.is_active = 1
                   AND u.email IS NOT NULL
                   AND u.email != ''
                 ORDER BY u.email ASC"
            );
        }

        return Database::query(
            "SELECT id AS user_id, email
             FROM users
             WHERE role = 'admin'
               AND email IS NOT NULL
               AND email != ''
             ORDER BY email ASC"
        );
    }

    public function recentAttempts(int $limit = 10): array
    {
        if (!Database::tableExists('email_digest_log')) {
            return [];
        }
        $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        if ($workspaceId <= 0) {
            return [];
        }
        $limit = max(1, min(50, $limit));
        $columns = Database::columnExists('email_digest_log', 'is_test')
            ? 'is_test, recipient_email, provider_key, delivery_method,'
            : '0 AS is_test, NULL AS recipient_email, NULL AS provider_key, NULL AS delivery_method,';

        return Database::query(
            "SELECT {$columns} status, error_message, sent_at
             FROM email_digest_log
             WHERE workspace_id = ?
             ORDER BY sent_at DESC
             LIMIT {$limit}",
            [$workspaceId]
        );
    }

    private function logDigestAttempt(int $userId, string $email, bool $isTest, string $status, ?string $errorMessage, ?\DateTimeImmutable $sentAt = null): void
    {
        try {
            $sentAtValue = ($sentAt ?? new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
            $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
            $safeError = $errorMessage !== null ? mb_substr(trim($errorMessage), 0, 2000) : null;
            if (Database::columnExists('email_digest_log', 'is_test')) {
                Database::execute(
                    "INSERT INTO email_digest_log
                        (workspace_id, user_id, sent_at, status, is_test, recipient_email, provider_key, delivery_method, error_message)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [
                        $workspaceId > 0 ? $workspaceId : null,
                        $userId,
                        $sentAtValue,
                        $status,
                        $isTest ? 1 : 0,
                        $email,
                        $this->smtp->getLastProviderKey() ?: $this->smtp->getActiveProviderKey(),
                        $this->smtp->getLastMethodUsed()
                            ?: ($this->smtp->getActiveProviderKey() === 'assistant_smtp' ? 'smtp' : 'oauth'),
                        $safeError,
                    ]
                );
            } elseif ($workspaceId > 0 && Database::columnExists('email_digest_log', 'workspace_id')) {
                Database::execute(
                    "INSERT INTO email_digest_log (workspace_id, user_id, sent_at, status, error_message) VALUES (?, ?, ?, ?, ?)",
                    [$workspaceId, $userId, $sentAtValue, $status, $safeError]
                );
            } else {
                Database::execute(
                    "INSERT INTO email_digest_log (user_id, sent_at, status, error_message) VALUES (?, ?, ?, ?)",
                    [$userId, $sentAtValue, $status, $safeError]
                );
            }
        } catch (\Throwable $e) {
            error_log('EmailAssistantDigestService::logDigestAttempt failed: ' . $e->getMessage());
        }
    }

    private function workspaceDigestConfig(): array
    {
        $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        if ($workspaceId <= 0) {
            return [];
        }

        try {
            $assistantConfig = (new WorkspaceAssistantConfigService())->get($workspaceId, 'email', true);
        } catch (\Throwable $e) {
            return [];
        }
        if ($assistantConfig === []) {
            return [];
        }

        $settings = (array) ($assistantConfig['settings'] ?? []);
        $enabled = !empty($assistantConfig['enabled']);
        $smtpReady = trim((string) ($settings['smtp_host'] ?? '')) !== ''
            && trim((string) ($settings['smtp_username'] ?? '')) !== ''
            && trim((string) ($settings['smtp_password'] ?? '')) !== ''
            && trim((string) ($settings['from_email'] ?? $settings['system_email'] ?? '')) !== '';

        $oauthReady = false;
        try {
            $summary = (new EmailIntegrationService())->getAssistantProviderSummary($workspaceId);
            $oauthReady = !empty($summary['is_active']) && in_array((string) ($summary['readiness'] ?? ''), ['ready', 'warning'], true);
        } catch (\Throwable $e) {
            $oauthReady = false;
        }

        $outboundReady = $enabled && ($smtpReady || $oauthReady);
        return [
            'digest_enabled' => !empty($settings['digest_enabled']),
            'send_time' => trim((string) ($settings['digest_time'] ?? '07:00')) ?: '07:00',
            'recipients' => trim((string) ($settings['digest_recipients'] ?? 'admins')) ?: 'admins',
            'outbound_ready' => $outboundReady,
            'message' => $outboundReady
                ? 'Workspace Email Assistant digest sending is configured.'
                : 'Workspace Email Assistant needs enabled outbound setup before digest sending.',
        ];
    }
}
