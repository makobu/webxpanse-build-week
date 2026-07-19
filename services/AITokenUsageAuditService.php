<?php

namespace CRM\Services;

use CRM\Database;

class AITokenUsageAuditService
{
    private const DEFAULT_WINDOW_DAYS = 30;
    private const DEFAULT_TOP_LIMIT = 25;

    private const SETUP_FEATURE_KEYS = [
        'ai_coach_recommendations',
        'marketplace_catalog_copy',
        'onboarding_clarity_draft',
        'onboarding_lifecycle_nudge',
        'startup_journey_field_draft',
    ];

    private const SETUP_FEATURE_PREFIXES = [
        'onboarding_',
        'startup_journey_',
        'marketplace_catalog_',
    ];

    private const LOW_RISK_FEATURE_KEYS = [
        'email_assistant_intent',
        'intent_detection',
        'search_query_parse',
        'sentiment',
        'smart_tagging',
        'whatsapp_quick_replies',
    ];

    private const LOW_RISK_FEATURE_PREFIXES = [
        'email_assistant_parse_',
    ];

    private const HIGH_RISK_FEATURE_KEYS = [
        'ai_coach_recommendations',
        'data_extraction',
        'data_inference',
        'document_extraction',
        'email_assistant_change_explanation',
        'email_assistant_commercial_reply',
        'email_assistant_customer_reply_goal',
        'email_assistant_question',
        'email_draft',
        'meeting_note_analysis',
        'meeting_prep_summary',
        'nl_report',
        'onboarding_clarity_draft',
        'onboarding_lifecycle_nudge',
        'startup_journey_field_draft',
        'thread_summary',
        'workflow_generation',
        'workflow_recommendations',
    ];

    private const HIGH_RISK_FEATURE_PREFIXES = [
        'deal_',
        'marketing_',
        'marketplace_catalog_',
    ];

    public function audit(array $options = []): array
    {
        $workspaceId = $this->positiveIntOrNull($options['workspace_id'] ?? null);
        $windowDays = max(1, (int) ($options['window_days'] ?? self::DEFAULT_WINDOW_DAYS));
        $topLimit = max(1, min(100, (int) ($options['top_limit'] ?? self::DEFAULT_TOP_LIMIT)));
        $customFrom = date('Y-m-d H:i:s', strtotime('-' . $windowDays . ' days'));

        $tables = [
            'workspace_ai_usage' => Database::tableExists('workspace_ai_usage'),
            'workspace_wallets' => Database::tableExists('workspace_wallets'),
            'workspace_wallet_ledger' => Database::tableExists('workspace_wallet_ledger'),
            'workspace_ai_provider_configs' => Database::tableExists('workspace_ai_provider_configs'),
            'workspaces' => Database::tableExists('workspaces'),
        ];

        if (!$tables['workspace_ai_usage']) {
            return [
                'generated_at' => date('c'),
                'filters' => [
                    'workspace_id' => $workspaceId,
                    'window_days' => $windowDays,
                    'top_limit' => $topLimit,
                ],
                'tables' => $tables,
                'windows' => $this->emptyWindows($windowDays, $customFrom),
                'usage_buckets' => $this->emptyUsageBuckets(),
                'by_day' => [],
                'by_feature' => [],
                'by_workspace' => [],
                'by_provider_model' => [],
                'by_risk_tier' => [],
                'high_usage_modules' => [],
                'wallets' => [],
                'provider_configs' => [],
                'classification_catalog' => $this->classificationCatalog(),
                'notes' => $this->notes(true),
            ];
        }

        $allFeatures = $this->queryFeatureRollup($workspaceId, $customFrom, 0);
        $byFeature = array_slice($allFeatures, 0, $topLimit);

        return [
            'generated_at' => date('c'),
            'filters' => [
                'workspace_id' => $workspaceId,
                'window_days' => $windowDays,
                'top_limit' => $topLimit,
            ],
            'tables' => $tables,
            'windows' => [
                'all_time' => $this->queryTotals($workspaceId),
                'today' => $this->queryTotals($workspaceId, date('Y-m-d 00:00:00')),
                'last_7_days' => $this->queryTotals($workspaceId, date('Y-m-d H:i:s', strtotime('-7 days'))),
                'last_30_days' => $this->queryTotals($workspaceId, date('Y-m-d H:i:s', strtotime('-30 days'))),
                'custom_window' => $this->queryTotals($workspaceId, $customFrom),
            ],
            'usage_buckets' => $this->bucketTotals($allFeatures),
            'by_day' => $this->queryDayRollup($workspaceId, $customFrom, $topLimit + 10),
            'by_feature' => $byFeature,
            'by_workspace' => $this->queryWorkspaceRollup($customFrom, $topLimit, $workspaceId),
            'by_provider_model' => $this->queryProviderModelRollup($workspaceId, $customFrom, $topLimit),
            'by_risk_tier' => $this->riskTotals($allFeatures),
            'high_usage_modules' => array_values(array_filter($byFeature, static function (array $row): bool {
                return in_array((string) ($row['risk_tier'] ?? ''), ['high', 'medium'], true);
            })),
            'wallets' => $this->queryWallets($workspaceId, $topLimit),
            'provider_configs' => $this->queryProviderConfigs($workspaceId, $topLimit),
            'classification_catalog' => $this->classificationCatalog(),
            'notes' => $this->notes(false),
        ];
    }

    public function classifyFeature(string $featureKey): array
    {
        $featureKey = trim($featureKey) !== '' ? trim($featureKey) : 'general';
        $bucket = $this->isSetupFeature($featureKey) ? 'setup' : 'daily';
        $riskTier = $this->riskTier($featureKey);

        return [
            'feature_key' => $featureKey,
            'usage_bucket' => $bucket,
            'risk_tier' => $riskTier,
            'module_label' => $this->moduleLabel($featureKey),
        ];
    }

    private function queryTotals(?int $workspaceId, ?string $from = null): array
    {
        [$where, $params] = $this->usageWhere($workspaceId, $from);
        $row = Database::queryOne(
            "SELECT COUNT(*) AS request_count,
                    MIN(created_at) AS first_recorded_at,
                    MAX(created_at) AS last_recorded_at,
                    COALESCE(SUM(input_tokens), 0) AS input_tokens,
                    COALESCE(SUM(output_tokens), 0) AS output_tokens,
                    COALESCE(SUM(billable_tokens), 0) AS billable_tokens,
                    COALESCE(SUM(provider_cost), 0) AS provider_cost,
                    COALESCE(ROUND(AVG(NULLIF(billable_tokens, 0)), 2), 0) AS avg_billable_tokens
             FROM workspace_ai_usage
             {$where}",
            $params
        ) ?? [];

        return $this->normalizeTotals($row);
    }

    private function queryFeatureRollup(?int $workspaceId, string $from, int $limit): array
    {
        [$where, $params] = $this->usageWhere($workspaceId, $from);
        $limitSql = $limit > 0 ? ' LIMIT ' . (int) $limit : '';
        $rows = Database::query(
            "SELECT feature_key,
                    COUNT(*) AS request_count,
                    COALESCE(SUM(input_tokens), 0) AS input_tokens,
                    COALESCE(SUM(output_tokens), 0) AS output_tokens,
                    COALESCE(SUM(billable_tokens), 0) AS billable_tokens,
                    COALESCE(SUM(provider_cost), 0) AS provider_cost,
                    COALESCE(ROUND(AVG(NULLIF(billable_tokens, 0)), 2), 0) AS avg_billable_tokens
             FROM workspace_ai_usage
             {$where}
             GROUP BY feature_key
             ORDER BY billable_tokens DESC, request_count DESC, feature_key ASC{$limitSql}",
            $params
        );

        return array_map(function (array $row): array {
            $featureKey = (string) ($row['feature_key'] ?? 'general');
            $classification = $this->classifyFeature($featureKey);

            return $classification + [
                'request_count' => (int) ($row['request_count'] ?? 0),
                'input_tokens' => (int) ($row['input_tokens'] ?? 0),
                'output_tokens' => (int) ($row['output_tokens'] ?? 0),
                'billable_tokens' => (int) ($row['billable_tokens'] ?? 0),
                'provider_cost' => (float) ($row['provider_cost'] ?? 0),
                'avg_billable_tokens' => (float) ($row['avg_billable_tokens'] ?? 0),
            ];
        }, $rows);
    }

    private function queryDayRollup(?int $workspaceId, string $from, int $limit): array
    {
        [$where, $params] = $this->usageWhere($workspaceId, $from);
        $rows = Database::query(
            "SELECT DATE(created_at) AS usage_date,
                    COUNT(*) AS request_count,
                    COALESCE(SUM(input_tokens), 0) AS input_tokens,
                    COALESCE(SUM(output_tokens), 0) AS output_tokens,
                    COALESCE(SUM(billable_tokens), 0) AS billable_tokens,
                    COALESCE(SUM(provider_cost), 0) AS provider_cost,
                    COALESCE(ROUND(AVG(NULLIF(billable_tokens, 0)), 2), 0) AS avg_billable_tokens
             FROM workspace_ai_usage
             {$where}
             GROUP BY DATE(created_at)
             ORDER BY usage_date DESC
             LIMIT " . (int) $limit,
            $params
        );

        return array_map(static function (array $row): array {
            return [
                'usage_date' => (string) ($row['usage_date'] ?? ''),
                'request_count' => (int) ($row['request_count'] ?? 0),
                'input_tokens' => (int) ($row['input_tokens'] ?? 0),
                'output_tokens' => (int) ($row['output_tokens'] ?? 0),
                'billable_tokens' => (int) ($row['billable_tokens'] ?? 0),
                'provider_cost' => (float) ($row['provider_cost'] ?? 0),
                'avg_billable_tokens' => (float) ($row['avg_billable_tokens'] ?? 0),
            ];
        }, $rows);
    }

    private function queryWorkspaceRollup(string $from, int $limit, ?int $workspaceId = null): array
    {
        [$where, $params] = $this->usageWhere($workspaceId, $from, 'wau');
        $join = Database::tableExists('workspaces')
            ? 'LEFT JOIN workspaces w ON w.id = wau.workspace_id'
            : '';
        $nameExpr = Database::tableExists('workspaces')
            ? "COALESCE(w.name, CONCAT('Workspace ', wau.workspace_id))"
            : "CONCAT('Workspace ', wau.workspace_id)";
        $group = Database::tableExists('workspaces')
            ? 'wau.workspace_id, w.name'
            : 'wau.workspace_id';

        $rows = Database::query(
            "SELECT wau.workspace_id,
                    {$nameExpr} AS workspace_name,
                    COUNT(*) AS request_count,
                    COALESCE(SUM(wau.input_tokens), 0) AS input_tokens,
                    COALESCE(SUM(wau.output_tokens), 0) AS output_tokens,
                    COALESCE(SUM(wau.billable_tokens), 0) AS billable_tokens,
                    COALESCE(SUM(wau.provider_cost), 0) AS provider_cost,
                    COALESCE(ROUND(AVG(NULLIF(wau.billable_tokens, 0)), 2), 0) AS avg_billable_tokens
             FROM workspace_ai_usage wau
             {$join}
             {$where}
             GROUP BY {$group}
             ORDER BY billable_tokens DESC, request_count DESC, wau.workspace_id ASC
             LIMIT " . (int) $limit,
            $params
        );

        return array_map(static function (array $row): array {
            return [
                'workspace_id' => (int) ($row['workspace_id'] ?? 0),
                'workspace_name' => (string) ($row['workspace_name'] ?? ''),
                'request_count' => (int) ($row['request_count'] ?? 0),
                'input_tokens' => (int) ($row['input_tokens'] ?? 0),
                'output_tokens' => (int) ($row['output_tokens'] ?? 0),
                'billable_tokens' => (int) ($row['billable_tokens'] ?? 0),
                'provider_cost' => (float) ($row['provider_cost'] ?? 0),
                'avg_billable_tokens' => (float) ($row['avg_billable_tokens'] ?? 0),
            ];
        }, $rows);
    }

    private function queryProviderModelRollup(?int $workspaceId, string $from, int $limit): array
    {
        [$where, $params] = $this->usageWhere($workspaceId, $from);
        $hasProviderSource = Database::columnExists('workspace_ai_usage', 'provider_source');
        $sourceSelect = $hasProviderSource ? 'provider_source,' : "'unknown' AS provider_source,";
        $sourceGroup = $hasProviderSource ? 'provider_source,' : '';

        $rows = Database::query(
            "SELECT provider,
                    {$sourceSelect}
                    COALESCE(model, '') AS model,
                    COUNT(*) AS request_count,
                    COALESCE(SUM(input_tokens), 0) AS input_tokens,
                    COALESCE(SUM(output_tokens), 0) AS output_tokens,
                    COALESCE(SUM(billable_tokens), 0) AS billable_tokens,
                    COALESCE(SUM(provider_cost), 0) AS provider_cost
             FROM workspace_ai_usage
             {$where}
             GROUP BY provider, {$sourceGroup} model
             ORDER BY billable_tokens DESC, request_count DESC, provider ASC, model ASC
             LIMIT " . (int) $limit,
            $params
        );

        return array_map(static function (array $row): array {
            return [
                'provider' => (string) ($row['provider'] ?? ''),
                'provider_source' => (string) ($row['provider_source'] ?? 'unknown'),
                'model' => (string) ($row['model'] ?? ''),
                'request_count' => (int) ($row['request_count'] ?? 0),
                'input_tokens' => (int) ($row['input_tokens'] ?? 0),
                'output_tokens' => (int) ($row['output_tokens'] ?? 0),
                'billable_tokens' => (int) ($row['billable_tokens'] ?? 0),
                'provider_cost' => (float) ($row['provider_cost'] ?? 0),
            ];
        }, $rows);
    }

    private function queryWallets(?int $workspaceId, int $limit): array
    {
        if (!Database::tableExists('workspace_wallets')) {
            return [];
        }

        $params = [];
        $where = '';
        if ($workspaceId !== null) {
            $where = 'WHERE wallet.workspace_id = ?';
            $params[] = $workspaceId;
        }

        $join = Database::tableExists('workspaces')
            ? 'LEFT JOIN workspaces w ON w.id = wallet.workspace_id'
            : '';
        $nameExpr = Database::tableExists('workspaces')
            ? "COALESCE(w.name, CONCAT('Workspace ', wallet.workspace_id))"
            : "CONCAT('Workspace ', wallet.workspace_id)";

        $rows = Database::query(
            "SELECT wallet.workspace_id,
                    {$nameExpr} AS workspace_name,
                    wallet.currency,
                    wallet.token_balance,
                    wallet.reserved_tokens,
                    GREATEST(wallet.token_balance - wallet.reserved_tokens, 0) AS available_tokens,
                    wallet.lifetime_credited_tokens,
                    wallet.lifetime_debited_tokens,
                    wallet.last_activity_at
             FROM workspace_wallets wallet
             {$join}
             {$where}
             ORDER BY wallet.lifetime_debited_tokens DESC, wallet.token_balance DESC, wallet.workspace_id ASC
             LIMIT " . (int) $limit,
            $params
        );

        return array_map(static function (array $row): array {
            return [
                'workspace_id' => (int) ($row['workspace_id'] ?? 0),
                'workspace_name' => (string) ($row['workspace_name'] ?? ''),
                'currency' => (string) ($row['currency'] ?? ''),
                'token_balance' => (int) ($row['token_balance'] ?? 0),
                'reserved_tokens' => (int) ($row['reserved_tokens'] ?? 0),
                'available_tokens' => (int) ($row['available_tokens'] ?? 0),
                'lifetime_credited_tokens' => (int) ($row['lifetime_credited_tokens'] ?? 0),
                'lifetime_debited_tokens' => (int) ($row['lifetime_debited_tokens'] ?? 0),
                'last_activity_at' => (string) ($row['last_activity_at'] ?? ''),
            ];
        }, $rows);
    }

    private function queryProviderConfigs(?int $workspaceId, int $limit): array
    {
        if (!Database::tableExists('workspace_ai_provider_configs')) {
            return [];
        }

        $params = [];
        $where = '';
        if ($workspaceId !== null) {
            $where = 'WHERE c.workspace_id = ?';
            $params[] = $workspaceId;
        }

        $join = Database::tableExists('workspaces')
            ? 'LEFT JOIN workspaces w ON w.id = c.workspace_id'
            : '';
        $nameExpr = Database::tableExists('workspaces')
            ? "COALESCE(w.name, CONCAT('Workspace ', c.workspace_id))"
            : "CONCAT('Workspace ', c.workspace_id)";

        $rows = Database::query(
            "SELECT c.workspace_id,
                    {$nameExpr} AS workspace_name,
                    c.mode,
                    c.provider_key,
                    c.model,
                    c.shared_daily_token_cap,
                    CASE WHEN c.api_key_fingerprint IS NULL OR c.api_key_fingerprint = '' THEN 0 ELSE 1 END AS has_key_fingerprint,
                    c.last_verified_at,
                    LEFT(COALESCE(c.last_error, ''), 160) AS last_error_preview,
                    c.updated_at
             FROM workspace_ai_provider_configs c
             {$join}
             {$where}
             ORDER BY c.workspace_id ASC
             LIMIT " . (int) $limit,
            $params
        );

        return array_map(static function (array $row): array {
            return [
                'workspace_id' => (int) ($row['workspace_id'] ?? 0),
                'workspace_name' => (string) ($row['workspace_name'] ?? ''),
                'mode' => (string) ($row['mode'] ?? ''),
                'provider_key' => (string) ($row['provider_key'] ?? ''),
                'model' => (string) ($row['model'] ?? ''),
                'shared_daily_token_cap' => (int) ($row['shared_daily_token_cap'] ?? 0),
                'has_key_fingerprint' => (int) ($row['has_key_fingerprint'] ?? 0) === 1,
                'last_verified_at' => (string) ($row['last_verified_at'] ?? ''),
                'last_error_preview' => (string) ($row['last_error_preview'] ?? ''),
                'updated_at' => (string) ($row['updated_at'] ?? ''),
            ];
        }, $rows);
    }

    /**
     * @return array{0:string,1:array<int,mixed>}
     */
    private function usageWhere(?int $workspaceId, ?string $from = null, string $alias = ''): array
    {
        $prefix = $alias !== '' ? $alias . '.' : '';
        $where = ['1 = 1'];
        $params = [];

        if ($workspaceId !== null) {
            $where[] = $prefix . 'workspace_id = ?';
            $params[] = $workspaceId;
        }

        if ($from !== null && trim($from) !== '') {
            $where[] = $prefix . 'created_at >= ?';
            $params[] = $from;
        }

        return ['WHERE ' . implode(' AND ', $where), $params];
    }

    private function bucketTotals(array $features): array
    {
        $buckets = $this->emptyUsageBuckets();
        foreach ($features as $feature) {
            $bucket = (string) ($feature['usage_bucket'] ?? 'daily');
            if (!isset($buckets[$bucket])) {
                $bucket = 'daily';
            }

            $buckets[$bucket]['request_count'] += (int) ($feature['request_count'] ?? 0);
            $buckets[$bucket]['input_tokens'] += (int) ($feature['input_tokens'] ?? 0);
            $buckets[$bucket]['output_tokens'] += (int) ($feature['output_tokens'] ?? 0);
            $buckets[$bucket]['billable_tokens'] += (int) ($feature['billable_tokens'] ?? 0);
            $buckets[$bucket]['provider_cost'] += (float) ($feature['provider_cost'] ?? 0);
        }

        foreach ($buckets as $bucket => $totals) {
            $buckets[$bucket]['avg_billable_tokens'] = $totals['request_count'] > 0
                ? round($totals['billable_tokens'] / $totals['request_count'], 2)
                : 0.0;
        }

        return $buckets;
    }

    private function riskTotals(array $features): array
    {
        $totals = [
            'high' => $this->zeroTotals(),
            'medium' => $this->zeroTotals(),
            'low' => $this->zeroTotals(),
        ];

        foreach ($features as $feature) {
            $tier = (string) ($feature['risk_tier'] ?? 'medium');
            if (!isset($totals[$tier])) {
                $tier = 'medium';
            }

            $totals[$tier]['request_count'] += (int) ($feature['request_count'] ?? 0);
            $totals[$tier]['input_tokens'] += (int) ($feature['input_tokens'] ?? 0);
            $totals[$tier]['output_tokens'] += (int) ($feature['output_tokens'] ?? 0);
            $totals[$tier]['billable_tokens'] += (int) ($feature['billable_tokens'] ?? 0);
            $totals[$tier]['provider_cost'] += (float) ($feature['provider_cost'] ?? 0);
        }

        foreach ($totals as $tier => $row) {
            $totals[$tier]['avg_billable_tokens'] = $row['request_count'] > 0
                ? round($row['billable_tokens'] / $row['request_count'], 2)
                : 0.0;
        }

        return $totals;
    }

    private function normalizeTotals(array $row): array
    {
        return [
            'request_count' => (int) ($row['request_count'] ?? 0),
            'first_recorded_at' => (string) ($row['first_recorded_at'] ?? ''),
            'last_recorded_at' => (string) ($row['last_recorded_at'] ?? ''),
            'input_tokens' => (int) ($row['input_tokens'] ?? 0),
            'output_tokens' => (int) ($row['output_tokens'] ?? 0),
            'billable_tokens' => (int) ($row['billable_tokens'] ?? 0),
            'provider_cost' => (float) ($row['provider_cost'] ?? 0),
            'avg_billable_tokens' => (float) ($row['avg_billable_tokens'] ?? 0),
        ];
    }

    private function emptyWindows(int $windowDays, string $customFrom): array
    {
        return [
            'all_time' => $this->zeroTotals(),
            'today' => $this->zeroTotals(),
            'last_7_days' => $this->zeroTotals(),
            'last_30_days' => $this->zeroTotals(),
            'custom_window' => $this->zeroTotals() + [
                'window_days' => $windowDays,
                'from' => $customFrom,
            ],
        ];
    }

    private function emptyUsageBuckets(): array
    {
        return [
            'setup' => $this->zeroTotals(),
            'daily' => $this->zeroTotals(),
        ];
    }

    private function zeroTotals(): array
    {
        return [
            'request_count' => 0,
            'first_recorded_at' => '',
            'last_recorded_at' => '',
            'input_tokens' => 0,
            'output_tokens' => 0,
            'billable_tokens' => 0,
            'provider_cost' => 0.0,
            'avg_billable_tokens' => 0.0,
        ];
    }

    private function isSetupFeature(string $featureKey): bool
    {
        if (in_array($featureKey, self::SETUP_FEATURE_KEYS, true)) {
            return true;
        }

        foreach (self::SETUP_FEATURE_PREFIXES as $prefix) {
            if (str_starts_with($featureKey, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function riskTier(string $featureKey): string
    {
        if (
            in_array($featureKey, self::LOW_RISK_FEATURE_KEYS, true) ||
            $this->startsWithAny($featureKey, self::LOW_RISK_FEATURE_PREFIXES)
        ) {
            return 'low';
        }

        if (
            in_array($featureKey, self::HIGH_RISK_FEATURE_KEYS, true) ||
            $this->startsWithAny($featureKey, self::HIGH_RISK_FEATURE_PREFIXES)
        ) {
            return 'high';
        }

        return 'medium';
    }

    private function startsWithAny(string $value, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($value, (string) $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function moduleLabel(string $featureKey): string
    {
        $labels = [
            'ai_coach_recommendations' => 'AI Coach / setup guidance',
            'data_extraction' => 'Data extraction',
            'data_inference' => 'Data inference',
            'document_extraction' => 'Document extraction',
            'email_assistant_commercial_reply' => 'Email/commercial assistant',
            'email_assistant_question' => 'Email assistant Q&A',
            'email_draft' => 'Email drafting',
            'meeting_note_analysis' => 'Meeting notes',
            'meeting_prep_summary' => 'Meeting prep',
            'marketplace_catalog_copy' => 'Marketplace setup copy',
            'onboarding_clarity_draft' => 'Founder setup drafting',
            'onboarding_lifecycle_nudge' => 'Onboarding lifecycle nudges',
            'startup_journey_field_draft' => 'Startup journey setup drafting',
            'thread_summary' => 'Conversation summaries',
        ];

        if (isset($labels[$featureKey])) {
            return $labels[$featureKey];
        }

        if (str_starts_with($featureKey, 'marketing_')) {
            return 'Marketing AI';
        }

        if (str_starts_with($featureKey, 'deal_')) {
            return 'Deal intelligence';
        }

        if (str_starts_with($featureKey, 'email_assistant_parse_')) {
            return 'Email assistant parsers';
        }

        return ucwords(str_replace('_', ' ', $featureKey));
    }

    private function classificationCatalog(): array
    {
        return [
            'setup_features' => self::SETUP_FEATURE_KEYS,
            'setup_prefixes' => self::SETUP_FEATURE_PREFIXES,
            'high_risk_features' => self::HIGH_RISK_FEATURE_KEYS,
            'high_risk_prefixes' => self::HIGH_RISK_FEATURE_PREFIXES,
            'low_risk_features' => self::LOW_RISK_FEATURE_KEYS,
            'low_risk_prefixes' => self::LOW_RISK_FEATURE_PREFIXES,
        ];
    }

    private function notes(bool $missingUsageTable): array
    {
        $notes = [
            'Tokens used means settled workspace_ai_usage.billable_tokens.',
            'Provider total_tokens is used when returned; otherwise usage is estimated at roughly one token per four characters of prompt plus output.',
            'Cache hits, cache-only flows, deterministic fallbacks before beginRequest, blocked wallet requests, and failed provider calls with released reservations may not create workspace_ai_usage rows.',
            'Setup vs daily usage is classified by feature_key; update the catalog when new setup feature keys are introduced.',
        ];

        if ($missingUsageTable) {
            array_unshift($notes, 'workspace_ai_usage is missing, so no per-request token audit can be calculated until migrations are run.');
        }

        return $notes;
    }

    private function positiveIntOrNull(mixed $value): ?int
    {
        $int = (int) $value;
        return $int > 0 ? $int : null;
    }
}
