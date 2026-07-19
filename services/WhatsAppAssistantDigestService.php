<?php

namespace CRM\Services;

use CRM\Database;

class WhatsAppAssistantDigestService
{
    private const STATUS_SUCCESS = 'success';
    private const STATUS_FAILED = 'failed';
    private const STATUS_PARTIAL_FAILED = 'partial_failed';
    private const STATUS_BLOCKED = 'blocked';
    private const STATUS_REOPEN_SENT = 'reopen_sent';
    private const STATUS_REOPEN_PENDING = 'reopen_pending';

    private WhatsAppAssistantConfig $config;
    private WhatsAppAssistantFormatter $formatter;
    private WhatsAppService $whatsApp;
    private EmailAssistantDigestService $emailDigest;
    private WhatsAppAssistantSessionService $sessionService;

    public function __construct(
        ?WhatsAppAssistantConfig $config = null,
        ?WhatsAppAssistantFormatter $formatter = null,
        ?WhatsAppService $whatsApp = null,
        ?EmailAssistantDigestService $emailDigest = null,
        ?WhatsAppAssistantSessionService $sessionService = null
    ) {
        $this->config = $config ?? new WhatsAppAssistantConfig();
        $this->formatter = $formatter ?? new WhatsAppAssistantFormatter();
        $this->whatsApp = $whatsApp ?? new WhatsAppService('assistant');
        $this->emailDigest = $emailDigest ?? new EmailAssistantDigestService();
        $this->sessionService = $sessionService ?? new WhatsAppAssistantSessionService();
    }

    public function validateDigestConfig(): array
    {
        $assistant = $this->config->validate();

        return [
            'digest_enabled' => !empty($assistant['digest_enabled']),
            'outbound_ready' => !empty($assistant['outbound_ready']),
            'message' => (string) ($assistant['message'] ?? ''),
            'send_time' => (string) ($assistant['digest_time'] ?? '07:00'),
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
                 WHERE assistant_type = 'whatsapp'
                 LIMIT 1"
            );

            return !empty($row);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * @return array<int,int>
     */
    public static function getDigestEnabledWorkspaceIds(bool $force = false): array
    {
        try {
            if (!Database::tableExists('workspace_assistant_configs') || !Database::tableExists('workspaces')) {
                return [];
            }

            $digestPredicate = $force
                ? ''
                : "AND LOWER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(c.settings_json, '$.digest_enabled')), '')) IN ('1', 'true', 'yes', 'on')";
            $rows = Database::query(
                "SELECT DISTINCT c.workspace_id
                 FROM workspace_assistant_configs c
                 JOIN workspaces w ON w.id = c.workspace_id
                 WHERE c.assistant_type = 'whatsapp'
                   AND c.enabled = 1
                   AND w.status IN ('active', 'trialing')
                   {$digestPredicate}
                 ORDER BY c.workspace_id ASC"
            );

            return array_values(array_filter(array_map(
                static fn(array $row): int => (int) ($row['workspace_id'] ?? 0),
                $rows
            ), static fn(int $workspaceId): bool => $workspaceId > 0));
        } catch (\Throwable $e) {
            error_log('WhatsAppAssistantDigestService::getDigestEnabledWorkspaceIds failed: ' . $e->getMessage());
            return [];
        }
    }

    public static function isLegacyDigestConfigEnabled(): bool
    {
        return strtolower((string) ($_ENV['WHATSAPP_ASSISTANT_DIGEST_ENABLED'] ?? 'false')) === 'true';
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

    public function sendDigestToNumber(
        int $userId,
        string $phoneNumber,
        bool $isTest = false,
        ?\DateTimeImmutable $runAt = null
    ): array {
        $runAt = $runAt ?? new \DateTimeImmutable('now');
        $normalizedPhone = $this->config->normalizePhoneNumber($phoneNumber);
        if ($userId <= 0 || $normalizedPhone === '') {
            return ['success' => false, 'status' => self::STATUS_FAILED, 'error' => 'Invalid WhatsApp digest recipient.'];
        }

        $authorized = $this->config->findAuthorizedNumber($normalizedPhone);
        if (!$authorized) {
            return ['success' => false, 'status' => self::STATUS_FAILED, 'error' => 'No active WhatsApp assistant mapping found for this number.'];
        }

        $authorizedUserId = (int) ($authorized['user_id'] ?? 0);
        if ($authorizedUserId > 0 && $authorizedUserId !== $userId) {
            return [
                'success' => false,
                'status' => 'recipient_user_mismatch',
                'error' => 'WhatsApp digest recipient is mapped to a different CRM user.',
                'user_id' => $userId,
                'mapped_user_id' => $authorizedUserId,
                'phone_number' => $normalizedPhone,
            ];
        }

        $sessionGate = $this->sessionService->ensureSessionReadyForOutbound($authorized, 'daily_digest', true);
        if (empty($sessionGate['ready'])) {
            $status = $this->statusForSessionGate((string) ($sessionGate['action'] ?? 'blocked'));
            $message = (string) ($sessionGate['message'] ?? 'Assistant session is not ready.');
            if (!$isTest) {
                $this->logDigestAttempt($userId, $normalizedPhone, $status, $message, [], 0, 0, $runAt);
            }

            return [
                'success' => false,
                'user_id' => $userId,
                'phone_number' => $normalizedPhone,
                'task_count' => 0,
                'message_count' => 0,
                'sent_message_count' => 0,
                'status' => $status,
                'message' => $message,
            ];
        }

        $payload = $this->emailDigest->buildDigestPayload($userId, $runAt);
        $tasks = (array) ($payload['tasks'] ?? []);
        $recommendations = (array) ($payload['recommendations'] ?? []);
        $workspace = (array) ($payload['workspace'] ?? []);
        $messages = $this->formatter->formatDigest($tasks, $recommendations, $isTest, [
            'workspace_name' => (string) ($workspace['name'] ?? ''),
            'recipient_name' => $this->recipientDisplayName($authorized),
            'tasks_url' => $this->buildPublicUrl('tasks.php'),
            'run_at' => $runAt,
        ]);

        if ($messages === []) {
            return ['success' => false, 'status' => self::STATUS_FAILED, 'error' => 'No WhatsApp digest content was generated.'];
        }

        $providerIds = [];
        $sentCount = 0;
        $messageCount = count($messages);
        foreach ($messages as $message) {
            try {
                $result = $this->whatsApp->sendTextMessage($normalizedPhone, $message);
                $providerIds[] = (string) ($result['messages'][0]['id'] ?? '');
                $sentCount++;
                $this->sessionService->recordOutboundMessage($authorized);
            } catch (\Throwable $e) {
                $status = $sentCount > 0 ? self::STATUS_PARTIAL_FAILED : self::STATUS_FAILED;
                if (!$isTest) {
                    $this->logDigestAttempt($userId, $normalizedPhone, $status, $e->getMessage(), $providerIds, $messageCount, $sentCount, $runAt);
                }

                return [
                    'success' => false,
                    'user_id' => $userId,
                    'phone_number' => $normalizedPhone,
                    'task_count' => count($tasks),
                    'message_count' => $messageCount,
                    'sent_message_count' => $sentCount,
                    'provider_ids' => $providerIds,
                    'status' => $status,
                    'error' => $e->getMessage(),
                ];
            }
        }

        if (!$isTest) {
            $this->logDigestAttempt($userId, $normalizedPhone, self::STATUS_SUCCESS, null, $providerIds, $messageCount, $sentCount, $runAt);
        }

        return [
            'success' => true,
            'status' => self::STATUS_SUCCESS,
            'user_id' => $userId,
            'phone_number' => $normalizedPhone,
            'task_count' => count($tasks),
            'message_count' => $messageCount,
            'sent_message_count' => $sentCount,
            'provider_ids' => $providerIds,
        ];
    }

    public function hasSuccessfulDigestForToday(int $userId, string $phoneNumber, ?string $date = null): bool
    {
        return $this->hasDigestForToday($userId, $phoneNumber, [self::STATUS_SUCCESS], $date);
    }

    public function hasTerminalDigestForToday(int $userId, string $phoneNumber, ?string $date = null): bool
    {
        return $this->hasDigestForToday($userId, $phoneNumber, [self::STATUS_SUCCESS, self::STATUS_PARTIAL_FAILED], $date);
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

        foreach ($this->config->getDigestRecipients() as $recipient) {
            $userId = (int) ($recipient['user_id'] ?? 0);
            $phoneNumber = (string) ($recipient['phone_number'] ?? '');
            if ($userId <= 0 || $phoneNumber === '') {
                $skipped++;
                $results[] = ['status' => 'skipped', 'reason' => 'invalid_recipient', 'phone_number' => $phoneNumber];
                continue;
            }

            if (!$force && $this->hasTerminalDigestForToday($userId, $phoneNumber, $today)) {
                $skipped++;
                $results[] = ['status' => 'skipped', 'reason' => 'already_attempted_today', 'phone_number' => $phoneNumber, 'user_id' => $userId];
                continue;
            }

            try {
                $result = $this->sendDigestToNumber($userId, $phoneNumber, false, $runAt);
                $status = (string) ($result['status'] ?? (empty($result['success']) ? self::STATUS_FAILED : self::STATUS_SUCCESS));
                if ($status === self::STATUS_SUCCESS && !empty($result['success'])) {
                    $sent++;
                    $results[] = ['status' => 'sent'] + $result;
                } elseif (in_array($status, [self::STATUS_BLOCKED, self::STATUS_REOPEN_SENT, self::STATUS_REOPEN_PENDING], true)) {
                    $skipped++;
                    $results[] = ['status' => 'skipped', 'reason' => $status] + $result;
                } else {
                    $failed++;
                    $results[] = ['status' => 'failed'] + $result;
                }
            } catch (\Throwable $e) {
                $failed++;
                $this->logDigestAttempt($userId, $phoneNumber, self::STATUS_FAILED, $e->getMessage(), [], 0, 0, $runAt);
                $results[] = ['status' => 'failed', 'user_id' => $userId, 'phone_number' => $phoneNumber, 'error' => $e->getMessage()];
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

    public function runScheduledForWorkspaces(bool $force = false, ?\DateTimeImmutable $now = null): array
    {
        $runAt = $now ?? new \DateTimeImmutable('now');
        $workspaceIds = self::getDigestEnabledWorkspaceIds($force);
        $hasWorkspaceRows = self::hasWorkspaceDigestConfigRows();
        if ($workspaceIds === [] && (!$hasWorkspaceRows || self::isLegacyDigestConfigEnabled() || $force)) {
            $legacyWorkspaceId = $this->legacyDigestWorkspaceId();
            if ($legacyWorkspaceId > 0) {
                $workspaceIds = [$legacyWorkspaceId];
            }
        }

        if ($workspaceIds === []) {
            return [
                'ran' => false,
                'reason' => $hasWorkspaceRows ? 'no_workspace_digest_enabled' : 'no_legacy_digest_workspace',
                'sent' => 0,
                'failed' => 0,
                'skipped' => 0,
                'results' => [],
                'workspace_results' => [],
            ];
        }

        $aggregate = [
            'ran' => false,
            'reason' => 'workspace_digests_not_due',
            'sent' => 0,
            'failed' => 0,
            'skipped' => 0,
            'results' => [],
            'workspace_results' => [],
        ];
        $previousRuntimeWorkspace = WorkspaceContext::runtimeSnapshot();

        foreach (array_values(array_unique($workspaceIds)) as $workspaceId) {
            try {
                if (WorkspaceContext::activateRuntimeWorkspace((int) $workspaceId) === null) {
                    $aggregate['skipped']++;
                    $aggregate['results'][] = ['status' => 'skipped', 'reason' => 'workspace_unavailable', 'workspace_id' => (int) $workspaceId];
                    continue;
                }

                $workspaceResult = (new self())->runScheduled($force, $runAt);
                $workspaceResult['workspace_id'] = (int) $workspaceId;
                $aggregate['workspace_results'][] = $workspaceResult;
                if (!empty($workspaceResult['ran'])) {
                    $aggregate['ran'] = true;
                    $aggregate['reason'] = 'ok';
                }
                foreach (['sent', 'failed', 'skipped'] as $key) {
                    $aggregate[$key] += (int) ($workspaceResult[$key] ?? 0);
                }
                foreach ((array) ($workspaceResult['results'] ?? []) as $row) {
                    $aggregate['results'][] = ['workspace_id' => (int) $workspaceId] + (array) $row;
                }
            } catch (\Throwable $e) {
                $aggregate['failed']++;
                $aggregate['results'][] = [
                    'status' => 'failed',
                    'workspace_id' => (int) $workspaceId,
                    'error' => $e->getMessage(),
                ];
            } finally {
                if ($previousRuntimeWorkspace !== null) {
                    WorkspaceContext::restoreRuntimeWorkspace($previousRuntimeWorkspace);
                } else {
                    WorkspaceContext::clearRuntimeWorkspace();
                }
            }
        }

        return $aggregate;
    }

    private function hasDigestForToday(int $userId, string $phoneNumber, array $statuses, ?string $date = null): bool
    {
        $date = $date ?: (new \DateTimeImmutable('now'))->format('Y-m-d');
        $normalizedPhone = $this->config->normalizePhoneNumber($phoneNumber);
        if ($userId <= 0 || $normalizedPhone === '' || $statuses === []) {
            return false;
        }

        $statusPlaceholders = implode(', ', array_fill(0, count($statuses), '?'));
        $params = array_merge([$userId, $normalizedPhone], array_values($statuses), [$date]);
        $workspaceSql = '';
        $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        if ($workspaceId > 0 && $this->hasWorkspaceColumn('whatsapp_assistant_digest_log')) {
            $workspaceSql = ' AND workspace_id = ?';
            $params[] = $workspaceId;
        }

        $row = Database::queryOne(
            "SELECT 1
             FROM whatsapp_assistant_digest_log
             WHERE user_id = ?
               AND phone_number = ?
               AND status IN ({$statusPlaceholders})
               AND DATE(sent_at) = ?
               {$workspaceSql}
             LIMIT 1",
            $params
        );

        return !empty($row);
    }

    private function statusForSessionGate(string $action): string
    {
        return match ($action) {
            'reopen_template_sent' => self::STATUS_REOPEN_SENT,
            'reopen_already_pending' => self::STATUS_REOPEN_PENDING,
            default => self::STATUS_BLOCKED,
        };
    }

    private function recipientDisplayName(array $authorized): string
    {
        $label = trim((string) ($authorized['label'] ?? ''));
        if ($label !== '') {
            return $label;
        }

        $name = trim(implode(' ', array_filter([
            trim((string) ($authorized['first_name'] ?? '')),
            trim((string) ($authorized['last_name'] ?? '')),
        ])));
        if ($name !== '') {
            return $name;
        }

        return trim((string) ($authorized['email'] ?? ''));
    }

    private function logDigestAttempt(
        int $userId,
        string $phoneNumber,
        string $status,
        ?string $errorMessage,
        array $providerIds = [],
        int $messageCount = 0,
        int $sentMessageCount = 0,
        ?\DateTimeImmutable $sentAt = null
    ): void {
        $sentAtValue = ($sentAt ?? new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $columns = ['user_id', 'phone_number', 'sent_at', 'status', 'error_message'];
        $placeholders = ['?', '?', '?', '?', '?'];
        $params = [
            $userId,
            $this->config->normalizePhoneNumber($phoneNumber),
            $sentAtValue,
            $status,
            $errorMessage,
        ];

        if ($this->hasWorkspaceColumn('whatsapp_assistant_digest_log')) {
            array_unshift($columns, 'workspace_id');
            array_unshift($placeholders, '?');
            array_unshift($params, (int) (WorkspaceContext::currentWorkspaceId() ?? 0) ?: null);
        }
        if (Database::columnExists('whatsapp_assistant_digest_log', 'provider_message_ids_json')) {
            $columns[] = 'provider_message_ids_json';
            $placeholders[] = '?';
            $params[] = json_encode(array_values($providerIds), JSON_UNESCAPED_SLASHES);
        }
        if (Database::columnExists('whatsapp_assistant_digest_log', 'message_count')) {
            $columns[] = 'message_count';
            $placeholders[] = '?';
            $params[] = max(0, $messageCount);
        }
        if (Database::columnExists('whatsapp_assistant_digest_log', 'sent_message_count')) {
            $columns[] = 'sent_message_count';
            $placeholders[] = '?';
            $params[] = max(0, $sentMessageCount);
        }

        Database::execute(
            "INSERT INTO whatsapp_assistant_digest_log (" . implode(', ', $columns) . ")
             VALUES (" . implode(', ', $placeholders) . ")",
            $params
        );
    }

    private function legacyDigestWorkspaceId(): int
    {
        $currentWorkspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        if ($currentWorkspaceId > 0) {
            return $currentWorkspaceId;
        }
        if (!Database::tableExists('workspaces')) {
            return 0;
        }

        $row = Database::queryOne(
            "SELECT id
             FROM workspaces
             WHERE slug = 'default'
             ORDER BY id ASC
             LIMIT 1"
        );
        if (!empty($row['id'])) {
            return (int) $row['id'];
        }

        $rows = Database::query(
            "SELECT id
             FROM workspaces
             WHERE status IN ('active', 'trialing')
             ORDER BY id ASC
             LIMIT 2"
        );

        return count($rows) === 1 ? (int) ($rows[0]['id'] ?? 0) : 0;
    }

    private function hasWorkspaceColumn(string $table): bool
    {
        return Database::tableExists($table) && Database::columnExists($table, 'workspace_id');
    }

    private function buildPublicUrl(string $path): string
    {
        if (function_exists('\\publicUrl')) {
            return \publicUrl($path);
        }

        return $path;
    }
}
