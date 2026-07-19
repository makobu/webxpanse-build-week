<?php

namespace CRM\Services;

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;

class AITokenRateLimiterService
{
    public function getConfig(): array
    {
        $enabled = strtolower(trim((string) ($_ENV['AI_TOKEN_RATE_LIMIT_ENABLED'] ?? 'false'))) === 'true';
        $dailyLimit = max(0, (int) ($_ENV['AI_DAILY_TOKEN_LIMIT'] ?? 0));

        return [
            'enabled' => $enabled && $dailyLimit > 0,
            'daily_limit' => $dailyLimit,
        ];
    }

    public function getUsageSummary(?array $actorUser = null, ?int $workspaceId = null, ?array $workspace = null): array
    {
        $config = $this->getConfig();
        $resolvedWorkspace = $this->resolveWorkspaceForBilling($workspaceId, $workspace);
        $workspace = $resolvedWorkspace['workspace'];
        $workspaceId = $resolvedWorkspace['workspace_id'];
        $billingExempt = $this->isBillingExempt($actorUser, $workspaceId, $workspace);
        $usedToday = $this->getUsedToday($workspaceId);
        $dailyLimit = (int) ($config['daily_limit'] ?? 0);
        $remaining = $dailyLimit > 0 ? max(0, $dailyLimit - $usedToday) : 0;
        $resetsAt = date('Y-m-d 00:00:00', strtotime('+1 day'));
        $walletSummary = $workspaceId !== null && $this->tableExists('workspace_wallets')
            ? (new WorkspaceWalletService())->getSummary($workspaceId)
            : null;

        return [
            'enabled' => !empty($config['enabled']),
            'daily_limit' => $dailyLimit,
            'used_today' => $usedToday,
            'remaining_today' => $dailyLimit > 0 ? $remaining : 0,
            'is_limited' => !empty($config['enabled']) && $dailyLimit > 0 && $usedToday >= $dailyLimit,
            'resets_at' => $resetsAt,
            'workspace_id' => $workspaceId,
            'token_balance' => (int) ($walletSummary['token_balance'] ?? 0),
            'reserved_tokens' => (int) ($walletSummary['reserved_tokens'] ?? 0),
            'available_tokens' => (int) ($walletSummary['available_tokens'] ?? 0),
            'is_billing_exempt' => $billingExempt,
            'ai_blocked_reason' => (!$billingExempt && $walletSummary !== null && !empty($walletSummary['is_depleted'])) ? 'wallet_depleted' : null,
        ];
    }

    public function getWorkspaceUsageRollup(int $workspaceId, int $windowDays = 30): array
    {
        $windowDays = max(1, $windowDays);
        $from = date('Y-m-d H:i:s', strtotime('-' . $windowDays . ' days'));

        if ($workspaceId <= 0 || !$this->tableExists('workspace_ai_usage')) {
            return [
                'window_days' => $windowDays,
                'from' => $from,
                'overview' => [
                    'request_count' => 0,
                    'total_input_tokens' => 0,
                    'total_output_tokens' => 0,
                    'total_billable_tokens' => 0,
                    'total_provider_cost' => 0.0,
                ],
                'by_feature' => [],
                'by_user' => [],
                'recent' => [],
            ];
        }

        $overview = Database::queryOne(
            "SELECT COUNT(*) AS request_count,
                    COALESCE(SUM(input_tokens), 0) AS total_input_tokens,
                    COALESCE(SUM(output_tokens), 0) AS total_output_tokens,
                    COALESCE(SUM(billable_tokens), 0) AS total_billable_tokens,
                    COALESCE(SUM(provider_cost), 0) AS total_provider_cost
             FROM workspace_ai_usage
             WHERE workspace_id = ?
               AND created_at >= ?",
            [$workspaceId, $from]
        ) ?? [];

        $byFeature = Database::query(
            "SELECT feature_key,
                    COUNT(*) AS request_count,
                    COALESCE(SUM(billable_tokens), 0) AS total_billable_tokens,
                    COALESCE(SUM(provider_cost), 0) AS total_provider_cost
             FROM workspace_ai_usage
             WHERE workspace_id = ?
               AND created_at >= ?
             GROUP BY feature_key
             ORDER BY total_billable_tokens DESC, feature_key ASC
             LIMIT 25",
            [$workspaceId, $from]
        );

        $byUser = Database::query(
            "SELECT wau.user_id,
                    u.email,
                    CONCAT_WS(' ', u.first_name, u.last_name) AS user_name,
                    COUNT(*) AS request_count,
                    COALESCE(SUM(wau.billable_tokens), 0) AS total_billable_tokens,
                    COALESCE(SUM(wau.provider_cost), 0) AS total_provider_cost
             FROM workspace_ai_usage wau
             LEFT JOIN users u ON u.id = wau.user_id
             WHERE wau.workspace_id = ?
               AND wau.created_at >= ?
             GROUP BY wau.user_id, u.email, u.first_name, u.last_name
             ORDER BY total_billable_tokens DESC, wau.user_id ASC
             LIMIT 25",
            [$workspaceId, $from]
        );

        $recent = Database::query(
            "SELECT id, user_id, provider, model, feature_key, request_id, input_tokens, output_tokens, billable_tokens, provider_cost, ledger_entry_id, created_at
             FROM workspace_ai_usage
             WHERE workspace_id = ?
               AND created_at >= ?
             ORDER BY id DESC
             LIMIT 20",
            [$workspaceId, $from]
        );

        return [
            'window_days' => $windowDays,
            'from' => $from,
            'overview' => [
                'request_count' => (int) ($overview['request_count'] ?? 0),
                'total_input_tokens' => (int) ($overview['total_input_tokens'] ?? 0),
                'total_output_tokens' => (int) ($overview['total_output_tokens'] ?? 0),
                'total_billable_tokens' => (int) ($overview['total_billable_tokens'] ?? 0),
                'total_provider_cost' => (float) ($overview['total_provider_cost'] ?? 0),
            ],
            'by_feature' => array_map(static function (array $row): array {
                return [
                    'feature_key' => (string) ($row['feature_key'] ?? 'general'),
                    'request_count' => (int) ($row['request_count'] ?? 0),
                    'total_billable_tokens' => (int) ($row['total_billable_tokens'] ?? 0),
                    'total_provider_cost' => (float) ($row['total_provider_cost'] ?? 0),
                ];
            }, $byFeature),
            'by_user' => array_map(static function (array $row): array {
                return [
                    'user_id' => !empty($row['user_id']) ? (int) $row['user_id'] : null,
                    'email' => (string) ($row['email'] ?? ''),
                    'user_name' => trim((string) ($row['user_name'] ?? '')),
                    'request_count' => (int) ($row['request_count'] ?? 0),
                    'total_billable_tokens' => (int) ($row['total_billable_tokens'] ?? 0),
                    'total_provider_cost' => (float) ($row['total_provider_cost'] ?? 0),
                ];
            }, $byUser),
            'recent' => array_map(static function (array $row): array {
                return [
                    'id' => (int) ($row['id'] ?? 0),
                    'user_id' => !empty($row['user_id']) ? (int) $row['user_id'] : null,
                    'provider' => (string) ($row['provider'] ?? ''),
                    'model' => (string) ($row['model'] ?? ''),
                    'feature_key' => (string) ($row['feature_key'] ?? 'general'),
                    'request_id' => (string) ($row['request_id'] ?? ''),
                    'input_tokens' => (int) ($row['input_tokens'] ?? 0),
                    'output_tokens' => (int) ($row['output_tokens'] ?? 0),
                    'billable_tokens' => (int) ($row['billable_tokens'] ?? 0),
                    'provider_cost' => (float) ($row['provider_cost'] ?? 0),
                    'ledger_entry_id' => !empty($row['ledger_entry_id']) ? (int) $row['ledger_entry_id'] : null,
                    'created_at' => (string) ($row['created_at'] ?? ''),
                ];
            }, $recent),
        ];
    }

    public function enforce(?array $actorUser = null, ?int $workspaceId = null, ?array $workspace = null): void
    {
        $summary = $this->getUsageSummary($actorUser, $workspaceId, $workspace);
        if (empty($summary['is_billing_exempt']) && ($summary['workspace_id'] ?? null) !== null && ($summary['available_tokens'] ?? 0) <= 0) {
            throw new \RuntimeException('AI Credit balance exhausted for this workspace. Please top up and try again.');
        }

        if (!$summary['is_limited']) {
            return;
        }

        throw new \RuntimeException('Daily AI token limit reached for this workspace. Please try again after ' . $summary['resets_at'] . '.');
    }

    public function beginRequest(string $provider, string $model, string $featureKey, string $input, ?int $userId = null, array $metadata = []): array
    {
        $userId = $userId ?? Auth::userId();
        $actorUser = $this->resolveActorUser($userId);
        $requestedWorkspaceId = !empty($metadata['workspace_id']) ? (int) $metadata['workspace_id'] : null;
        $resolvedWorkspace = $this->resolveWorkspaceForBilling($requestedWorkspaceId);
        $workspace = $resolvedWorkspace['workspace'];
        $workspaceId = $resolvedWorkspace['workspace_id'];
        $billingExempt = $this->isBillingExempt($actorUser, $workspaceId, $workspace);

        $this->enforce($actorUser, $workspaceId, $workspace);

        $inputTokens = $this->estimateTokens($input);
        $reservedTokens = max(1, (int) ceil($inputTokens * 1.5));
        $requestId = bin2hex(random_bytes(12));
        $ledgerEntryId = null;

        if (!$billingExempt && $workspaceId !== null && $this->tableExists('workspace_wallets')) {
            $reservation = (new WorkspaceWalletService())->reserveTokens(
                $workspaceId,
                $reservedTokens,
                'ai_request',
                $requestId,
                $userId,
                [
                    'provider' => $provider,
                    'model' => $model,
                    'feature_key' => $featureKey,
                    'credential_scope' => $this->normalizeCredentialScope((string) ($metadata['credential_scope'] ?? 'general')),
                    'input_tokens' => $inputTokens,
                ]
            );

            if (empty($reservation['ok'])) {
                throw new \RuntimeException('AI Credit balance exhausted for this workspace. Available AI Credits: ' . (int) ($reservation['available_tokens'] ?? 0) . '.');
            }

            $ledgerEntryId = $reservation['ledger_entry_id'] ?? null;
        }

        return [
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'provider' => $provider,
            'provider_source' => $this->normalizeProviderSource((string) ($metadata['provider_source'] ?? $this->defaultProviderSource($provider))),
            'provider_config_workspace_id' => !empty($metadata['provider_config_workspace_id']) ? (int) $metadata['provider_config_workspace_id'] : null,
            'credential_scope' => $this->normalizeCredentialScope((string) ($metadata['credential_scope'] ?? 'general')),
            'model' => $model,
            'feature_key' => $featureKey,
            'request_id' => $requestId,
            'input_tokens' => $inputTokens,
            'reserved_tokens' => $billingExempt ? 0 : $reservedTokens,
            'ledger_entry_id' => $ledgerEntryId,
            'billing_exempt' => $billingExempt,
        ];
    }

    public function completeRequest(array $reservation, string $output, float $cost = 0, ?int $providerTotalTokens = null): array
    {
        $inputTokens = (int) ($reservation['input_tokens'] ?? 0);
        $totalTokens = $providerTotalTokens !== null && $providerTotalTokens > 0
            ? $providerTotalTokens
            : $inputTokens + $this->estimateTokens($output);
        $totalTokens = max(0, $totalTokens);
        $outputTokens = max(0, $totalTokens - $inputTokens);

        $workspaceId = !empty($reservation['workspace_id']) ? (int) $reservation['workspace_id'] : null;
        $ledgerEntryId = null;
        $billingExempt = !empty($reservation['billing_exempt']);
        if (!$billingExempt && $workspaceId !== null && $this->tableExists('workspace_wallets')) {
            $settlement = (new WorkspaceWalletService())->settleReservation(
                $workspaceId,
                'ai_request',
                (string) ($reservation['request_id'] ?? ''),
                $totalTokens,
                !empty($reservation['user_id']) ? (int) $reservation['user_id'] : null,
                [
                    'provider' => (string) ($reservation['provider'] ?? ''),
                    'model' => (string) ($reservation['model'] ?? ''),
                    'feature_key' => (string) ($reservation['feature_key'] ?? ''),
                    'credential_scope' => $this->normalizeCredentialScope((string) ($reservation['credential_scope'] ?? 'general')),
                    'provider_cost' => $cost,
                    'input_tokens' => $inputTokens,
                    'output_tokens' => $outputTokens,
                ]
            );
            $ledgerEntryId = (int) ($settlement['ledger_entry_id'] ?? 0);
        }

        if ($workspaceId !== null && $this->tableExists('workspace_ai_usage')) {
            if (Database::columnExists('workspace_ai_usage', 'provider_source')) {
                $hasCredentialScope = Database::columnExists('workspace_ai_usage', 'credential_scope');
                $columns = 'workspace_id, user_id, provider, provider_source, provider_config_workspace_id, '
                    . ($hasCredentialScope ? 'credential_scope, ' : '')
                    . 'model, feature_key, request_id, input_tokens, output_tokens, billable_tokens, provider_cost, ledger_entry_id';
                $values = [
                    $workspaceId,
                    !empty($reservation['user_id']) ? (int) $reservation['user_id'] : null,
                    (string) ($reservation['provider'] ?? ''),
                    $this->normalizeProviderSource((string) ($reservation['provider_source'] ?? $this->defaultProviderSource((string) ($reservation['provider'] ?? '')))),
                    !empty($reservation['provider_config_workspace_id']) ? (int) $reservation['provider_config_workspace_id'] : null,
                ];
                if ($hasCredentialScope) {
                    $values[] = $this->normalizeCredentialScope((string) ($reservation['credential_scope'] ?? 'general'));
                }
                array_push(
                    $values,
                    (string) ($reservation['model'] ?? ''),
                    (string) ($reservation['feature_key'] ?? 'general'),
                    (string) ($reservation['request_id'] ?? ''),
                    $inputTokens,
                    $outputTokens,
                    $totalTokens,
                    max(0, $cost),
                    $ledgerEntryId > 0 ? $ledgerEntryId : null
                );
                Database::execute(
                    "INSERT INTO workspace_ai_usage ({$columns}) VALUES (" . implode(', ', array_fill(0, count($values), '?')) . ')',
                    $values
                );
            } else {
                Database::execute(
                    "INSERT INTO workspace_ai_usage
                     (workspace_id, user_id, provider, model, feature_key, request_id, input_tokens, output_tokens, billable_tokens, provider_cost, ledger_entry_id)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [
                        $workspaceId,
                        !empty($reservation['user_id']) ? (int) $reservation['user_id'] : null,
                        (string) ($reservation['provider'] ?? ''),
                        (string) ($reservation['model'] ?? ''),
                        (string) ($reservation['feature_key'] ?? 'general'),
                        (string) ($reservation['request_id'] ?? ''),
                        $inputTokens,
                        $outputTokens,
                        $totalTokens,
                        max(0, $cost),
                        $ledgerEntryId > 0 ? $ledgerEntryId : null,
                    ]
                );
            }
        }

        return [
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
            'billable_tokens' => $totalTokens,
            'ledger_entry_id' => $ledgerEntryId,
        ];
    }

    public function failRequest(array $reservation): void
    {
        if (!empty($reservation['billing_exempt'])) {
            return;
        }

        $workspaceId = !empty($reservation['workspace_id']) ? (int) $reservation['workspace_id'] : null;
        if ($workspaceId === null || !$this->tableExists('workspace_wallets')) {
            return;
        }

        (new WorkspaceWalletService())->releaseReservation(
            $workspaceId,
            'ai_request',
            (string) ($reservation['request_id'] ?? ''),
            !empty($reservation['user_id']) ? (int) $reservation['user_id'] : null,
            'Released reservation after AI request failure',
            [
                'provider' => (string) ($reservation['provider'] ?? ''),
                'model' => (string) ($reservation['model'] ?? ''),
                'feature_key' => (string) ($reservation['feature_key'] ?? ''),
            ]
        );
    }

    public function recordUsage(string $provider, int $tokens, float $cost = 0): void
    {
        $tokens = max(0, $tokens);
        if ($tokens <= 0 || !$this->shouldMirrorLegacyUsage()) {
            return;
        }

        Database::execute(
            "INSERT INTO ai_usage (provider, token_count, cost, date)
             VALUES (?, ?, ?, CURDATE())
             ON DUPLICATE KEY UPDATE token_count = token_count + VALUES(token_count), cost = cost + VALUES(cost)",
            [$provider, $tokens, max(0, $cost)]
        );
    }

    public function estimateTokens(string $input, string $output = ''): int
    {
        $combined = trim($input . "\n" . $output);
        if ($combined === '') {
            return 0;
        }

        return max(1, (int) ceil(strlen($combined) / 4));
    }

    private function getUsedToday(?int $workspaceId = null): int
    {
        if ($this->tableExists('workspace_ai_usage')) {
            if ($workspaceId !== null && $workspaceId > 0) {
                $row = Database::queryOne(
                    "SELECT COALESCE(SUM(billable_tokens), 0) AS used_today
                     FROM workspace_ai_usage
                     WHERE workspace_id = ?
                       AND created_at >= ?",
                    [$workspaceId, date('Y-m-d 00:00:00')]
                );

                return (int) ($row['used_today'] ?? 0);
            }

            $row = Database::queryOne(
                "SELECT COALESCE(SUM(billable_tokens), 0) AS used_today
                 FROM workspace_ai_usage
                 WHERE created_at >= ?",
                [date('Y-m-d 00:00:00')]
            );

            return (int) ($row['used_today'] ?? 0);
        }

        if (!$this->tableExists('ai_usage')) {
            return 0;
        }

        $row = Database::queryOne(
            "SELECT COALESCE(SUM(token_count), 0) AS used_today
             FROM ai_usage
             WHERE date = ?",
            [date('Y-m-d')]
        );

        return (int) ($row['used_today'] ?? 0);
    }

    private function shouldMirrorLegacyUsage(): bool
    {
        if (!$this->tableExists('ai_usage')) {
            return false;
        }

        return strtolower(trim((string) ($_ENV['AI_USAGE_COMPATIBILITY_MIRROR'] ?? 'false'))) === 'true';
    }

    private function isBillingExempt(?array $actorUser = null, ?int $workspaceId = null, ?array $workspace = null): bool
    {
        if ((new DefaultWorkspacePackageExemptionService())->isExempt((int) ($workspaceId ?? 0), $workspace)) {
            return true;
        }

        try {
            return Authorization::isSuperAdmin($actorUser ?? Auth::user());
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function resolveActorUser(?int $userId): ?array
    {
        $currentUser = Auth::user();
        if ($currentUser && ($userId === null || (int) ($currentUser['id'] ?? 0) === $userId)) {
            return $currentUser;
        }

        if ($userId === null || $userId <= 0) {
            return $currentUser;
        }

        return Database::queryOne(
            "SELECT id, uuid, first_name, last_name, email, role, last_login, email_verified_at
             FROM users
             WHERE id = ?
             LIMIT 1",
            [$userId]
        );
    }

    /** @return array{workspace_id:?int,workspace:?array} */
    private function resolveWorkspaceForBilling(?int $workspaceId = null, ?array $workspace = null): array
    {
        $workspaceId = $workspaceId !== null && $workspaceId > 0 ? $workspaceId : null;
        if ($workspace !== null) {
            $rowId = (int) ($workspace['id'] ?? $workspace['workspace_id'] ?? 0);
            if ($rowId > 0 && ($workspaceId === null || $workspaceId === $rowId)) {
                return ['workspace_id' => $rowId, 'workspace' => $workspace];
            }
        }

        try {
            $current = WorkspaceContext::currentWorkspace();
            $currentId = (int) ($current['id'] ?? 0);
            if ($currentId > 0 && ($workspaceId === null || $workspaceId === $currentId)) {
                return ['workspace_id' => $currentId, 'workspace' => $current];
            }
        } catch (\Throwable $e) {
            // Explicit background workspace resolution is handled below.
        }

        if ($workspaceId === null) {
            return ['workspace_id' => null, 'workspace' => null];
        }

        $resolved = Database::queryOne(
            "SELECT id, uuid, name, slug, status, plan_status
             FROM workspaces
             WHERE id = ?
             LIMIT 1",
            [$workspaceId]
        );

        return [
            'workspace_id' => $resolved ? $workspaceId : null,
            'workspace' => $resolved ?: null,
        ];
    }

    private function tableExists(string $table): bool
    {
        try {
            return (bool) Database::queryOne(
                "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
                [$table]
            );
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function defaultProviderSource(string $provider): string
    {
        return strtolower(trim($provider)) === 'ollama'
            ? WorkspaceAIProviderResolverService::SOURCE_LOCAL_FALLBACK
            : WorkspaceAIProviderResolverService::SOURCE_ENV;
    }

    private function normalizeProviderSource(string $source): string
    {
        return in_array($source, [
            WorkspaceAIProviderResolverService::SOURCE_ENV,
            WorkspaceAIProviderResolverService::SOURCE_DEFAULT_WORKSPACE,
            WorkspaceAIProviderResolverService::SOURCE_WORKSPACE_API,
            WorkspaceAIProviderResolverService::SOURCE_LOCAL_FALLBACK,
        ], true) ? $source : WorkspaceAIProviderResolverService::SOURCE_ENV;
    }

    private function normalizeCredentialScope(string $credentialScope): string
    {
        return $credentialScope === WorkspaceAIProviderConfigService::SCOPE_CONTENT_GENERATION
            ? WorkspaceAIProviderConfigService::SCOPE_CONTENT_GENERATION
            : WorkspaceAIProviderConfigService::SCOPE_GENERAL;
    }
}
