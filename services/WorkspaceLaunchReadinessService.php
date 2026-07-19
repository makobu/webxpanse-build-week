<?php

namespace CRM\Services;

use CRM\Database;
use PDO;

class WorkspaceLaunchReadinessService
{
    /**
     * @var array<string,array{label:string,customer_message:string,operator_message:string,tables:array<string,list<string>>}>
     */
    private const SURFACE_REQUIREMENTS = [
        'billing_snapshot' => [
            'label' => 'Workspace billing snapshot',
            'customer_message' => 'Workspace billing is temporarily unavailable until the latest SaaS migrations are applied.',
            'operator_message' => 'Workspace billing snapshot checks failed. Apply the latest SaaS billing migrations for this environment.',
            'tables' => [
                'workspaces' => ['id', 'uuid', 'name', 'slug', 'status', 'plan_status', 'trial_starts_at', 'trial_ends_at'],
                'workspace_subscriptions' => ['id', 'workspace_id', 'billing_plan_price_id', 'provider', 'provider_reference', 'provider_subscription_code', 'provider_customer_code', 'provider_email_token', 'provider_subscription_status', 'renewal_status', 'provider_metadata_json', 'subscription_status', 'current_period_start', 'current_period_end', 'trial_starts_at', 'trial_ends_at', 'next_billing_at', 'created_by'],
                'billing_plan_prices' => ['id', 'plan_id', 'price_code', 'currency', 'amount', 'included_tokens', 'interval_unit', 'interval_count', 'is_default', 'is_active', 'metadata_json', 'provider', 'provider_plan_code', 'provider_plan_id', 'provider_plan_status', 'provider_plan_synced_at'],
                'billing_plans' => ['id', 'code', 'name', 'description', 'billing_type', 'is_active'],
                'workspace_wallets' => ['id', 'workspace_id', 'currency', 'token_balance', 'reserved_tokens'],
            ],
        ],
        'billing_checkout' => [
            'label' => 'Workspace billing checkout',
            'customer_message' => 'Workspace checkout is temporarily unavailable until the latest SaaS migrations are applied.',
            'operator_message' => 'Workspace billing checkout checks failed. Apply the latest SaaS billing migrations before launching checkout.',
            'tables' => [
                'billing_checkout_sessions' => ['id', 'workspace_id', 'user_id', 'checkout_type', 'provider', 'provider_reference', 'provider_plan_code', 'provider_subscription_code', 'provider_customer_code', 'status', 'currency', 'amount', 'billing_plan_price_id', 'token_pack_price_id', 'subscription_id', 'payment_mode', 'flow_type', 'authorization_url', 'callback_url', 'customer_phone', 'display_text', 'instructions_json', 'metadata_json', 'paid_at', 'expires_at', 'created_at', 'updated_at'],
                'billing_plan_prices' => ['id', 'plan_id', 'price_code', 'currency', 'amount', 'included_tokens', 'interval_unit', 'interval_count', 'is_default', 'is_active', 'metadata_json', 'provider', 'provider_plan_code', 'provider_plan_id', 'provider_plan_status', 'provider_plan_synced_at'],
                'billing_plans' => ['id', 'code', 'name', 'description', 'billing_type', 'is_active'],
                'token_pack_prices' => ['id', 'billing_plan_price_id', 'token_quantity', 'sort_order', 'is_active'],
            ],
        ],
        'billing_portal' => [
            'label' => 'Workspace billing portal',
            'customer_message' => 'Workspace billing details are temporarily unavailable until the latest SaaS migrations are applied.',
            'operator_message' => 'Workspace billing portal checks failed. Apply the latest SaaS billing migrations before relying on owner or operator billing views.',
            'tables' => [
                'workspaces' => ['id', 'uuid', 'name', 'slug', 'status', 'plan_status', 'trial_starts_at', 'trial_ends_at'],
                'workspace_subscriptions' => ['id', 'workspace_id', 'billing_plan_price_id', 'provider', 'provider_reference', 'provider_subscription_code', 'provider_customer_code', 'provider_email_token', 'provider_subscription_status', 'renewal_status', 'provider_metadata_json', 'subscription_status', 'current_period_start', 'current_period_end', 'trial_starts_at', 'trial_ends_at', 'next_billing_at', 'created_by'],
                'billing_plan_prices' => ['id', 'plan_id', 'price_code', 'currency', 'amount', 'included_tokens', 'interval_unit', 'interval_count', 'is_default', 'is_active', 'metadata_json', 'provider', 'provider_plan_code', 'provider_plan_id', 'provider_plan_status', 'provider_plan_synced_at'],
                'billing_plans' => ['id', 'code', 'name', 'description', 'billing_type', 'is_active'],
                'token_pack_prices' => ['id', 'billing_plan_price_id', 'token_quantity', 'sort_order', 'is_active'],
                'workspace_wallets' => ['id', 'workspace_id', 'currency', 'token_balance', 'reserved_tokens'],
                'workspace_wallet_ledger' => ['id', 'workspace_id', 'entry_type', 'reference_type', 'reference_id', 'external_reference', 'token_delta', 'balance_after', 'reserved_after', 'status', 'description', 'metadata_json', 'created_at'],
                'billing_transactions' => ['id', 'workspace_id', 'checkout_session_id', 'subscription_id', 'wallet_ledger_id', 'provider', 'provider_reference', 'provider_subscription_code', 'provider_customer_code', 'transaction_type', 'transaction_status', 'payment_mode', 'flow_type', 'amount', 'currency', 'metadata_json', 'created_at'],
                'billing_invoices' => ['id', 'document_key', 'document_number', 'workspace_id', 'subscription_id', 'billing_plan_price_id', 'billing_transaction_id', 'checkout_session_id', 'negotiated_offer_id', 'provider', 'provider_reference', 'provider_subscription_code', 'provider_customer_code', 'package_code', 'package_name', 'buyer_workspace_name', 'buyer_workspace_slug', 'buyer_user_id', 'buyer_email', 'seller_legal_name', 'currency', 'amount', 'tax_total', 'grand_total', 'period_start', 'period_end', 'line_items_json', 'metadata_json', 'issued_at', 'created_at'],
                'billing_checkout_sessions' => ['id', 'workspace_id', 'user_id', 'checkout_type', 'provider_reference', 'provider_plan_code', 'provider_subscription_code', 'provider_customer_code', 'status', 'payment_mode', 'flow_type', 'currency', 'amount', 'billing_plan_price_id', 'token_pack_price_id', 'authorization_url', 'callback_url', 'customer_phone', 'display_text', 'instructions_json', 'metadata_json', 'paid_at', 'expires_at', 'created_at', 'updated_at'],
                'workspace_ai_usage' => ['id', 'workspace_id', 'user_id', 'provider', 'model', 'feature_key', 'request_id', 'input_tokens', 'output_tokens', 'billable_tokens', 'provider_cost', 'created_at'],
            ],
        ],
        'billing_finalize' => [
            'label' => 'Workspace billing finalization',
            'customer_message' => 'Payment confirmation is temporarily unavailable until the latest SaaS migrations are applied.',
            'operator_message' => 'Workspace billing finalization checks failed. Apply the latest SaaS billing migrations before processing callbacks or provider webhooks.',
            'tables' => [
                'billing_checkout_sessions' => ['id', 'workspace_id', 'user_id', 'checkout_type', 'provider', 'provider_reference', 'provider_plan_code', 'provider_subscription_code', 'provider_customer_code', 'status', 'currency', 'amount', 'billing_plan_price_id', 'token_pack_price_id', 'subscription_id', 'payment_mode', 'flow_type', 'authorization_url', 'callback_url', 'customer_phone', 'display_text', 'instructions_json', 'metadata_json', 'paid_at', 'expires_at', 'created_at', 'updated_at'],
                'billing_transactions' => ['id', 'workspace_id', 'checkout_session_id', 'subscription_id', 'wallet_ledger_id', 'provider', 'provider_reference', 'provider_subscription_code', 'provider_customer_code', 'transaction_type', 'transaction_status', 'payment_mode', 'flow_type', 'amount', 'currency', 'metadata_json', 'created_at'],
                'billing_invoices' => ['id', 'document_key', 'document_number', 'workspace_id', 'subscription_id', 'billing_plan_price_id', 'billing_transaction_id', 'checkout_session_id', 'negotiated_offer_id', 'provider', 'provider_reference', 'provider_subscription_code', 'provider_customer_code', 'package_code', 'package_name', 'buyer_workspace_name', 'buyer_workspace_slug', 'buyer_user_id', 'buyer_email', 'seller_legal_name', 'currency', 'amount', 'tax_total', 'grand_total', 'period_start', 'period_end', 'line_items_json', 'metadata_json', 'issued_at', 'created_at'],
                'billing_provider_events' => ['id', 'provider', 'workspace_id', 'event_name', 'event_reference', 'signature', 'payload_json', 'processing_status', 'processing_message', 'processed_at', 'created_at'],
                'workspace_subscriptions' => ['id', 'workspace_id', 'billing_plan_price_id', 'provider', 'provider_reference', 'provider_subscription_code', 'provider_customer_code', 'provider_email_token', 'provider_subscription_status', 'renewal_status', 'provider_metadata_json', 'subscription_status', 'current_period_start', 'current_period_end', 'trial_starts_at', 'trial_ends_at', 'next_billing_at', 'created_by'],
                'workspace_wallets' => ['id', 'workspace_id', 'currency', 'token_balance', 'reserved_tokens'],
                'workspace_wallet_ledger' => ['id', 'workspace_id', 'wallet_id', 'user_id', 'entry_type', 'reference_type', 'reference_id', 'token_delta', 'balance_after', 'reserved_after', 'status', 'description', 'metadata_json', 'created_at'],
            ],
        ],
        'workspace_governance' => [
            'label' => 'Workspace governance',
            'customer_message' => 'Workspace team settings are temporarily unavailable until the latest governance migrations are applied.',
            'operator_message' => 'Workspace governance checks failed. Apply the latest governance migrations before relying on invite, member, or slug management.',
            'tables' => [
                'workspace_memberships' => ['id', 'workspace_id', 'user_id', 'role_slug', 'membership_status', 'is_owner', 'joined_at', 'invited_by', 'updated_at'],
                'workspace_invites' => ['id', 'workspace_id', 'email', 'role_slug', 'token_hash', 'invite_status', 'invited_by', 'expires_at', 'accepted_at', 'delivery_status', 'delivery_error', 'last_delivery_attempt_at', 'delivery_attempt_count', 'updated_at'],
                'workspace_invite_function_assignments' => ['id', 'workspace_id', 'invite_id', 'function_id', 'assignment_type', 'importance', 'is_primary', 'updated_at'],
                'workspace_slugs' => ['id', 'workspace_id', 'slug', 'is_primary', 'created_at'],
                'workspace_governance_events' => ['id', 'workspace_id', 'actor_user_id', 'target_user_id', 'invite_id', 'event_type', 'metadata_json', 'created_at'],
            ],
        ],
        'invite_acceptance' => [
            'label' => 'Workspace invite acceptance',
            'customer_message' => 'Workspace invite acceptance is temporarily unavailable until the latest governance migrations are applied.',
            'operator_message' => 'Workspace invite acceptance checks failed. Apply the latest governance migrations before relying on invite links.',
            'tables' => [
                'workspaces' => ['id', 'uuid', 'name', 'slug', 'status'],
                'workspace_memberships' => ['id', 'workspace_id', 'user_id', 'role_slug', 'membership_status', 'is_owner', 'joined_at', 'invited_by', 'updated_at'],
                'workspace_invites' => ['id', 'workspace_id', 'email', 'role_slug', 'token_hash', 'invite_status', 'invited_by', 'expires_at', 'accepted_at', 'delivery_status', 'delivery_error', 'last_delivery_attempt_at', 'delivery_attempt_count', 'updated_at'],
                'workspace_invite_function_assignments' => ['id', 'workspace_id', 'invite_id', 'function_id', 'assignment_type', 'importance', 'is_primary', 'updated_at'],
                'workspace_slugs' => ['id', 'workspace_id', 'slug', 'is_primary', 'created_at'],
            ],
        ],
        'mobile_workspace_payload' => [
            'label' => 'Mobile workspace payloads',
            'customer_message' => 'Workspace mobile payloads are temporarily unavailable until the latest SaaS migrations are applied.',
            'operator_message' => 'Mobile workspace payload checks failed. Apply the latest SaaS and governance migrations before relying on mobile workspace state.',
            'tables' => [
                'workspaces' => ['id', 'uuid', 'name', 'slug', 'status', 'plan_status', 'trial_starts_at', 'trial_ends_at'],
                'workspace_memberships' => ['id', 'workspace_id', 'user_id', 'role_slug', 'membership_status', 'is_owner', 'joined_at', 'invited_by', 'updated_at'],
                'workspace_invites' => ['id', 'workspace_id', 'email', 'invite_status', 'delivery_status', 'delivery_error', 'last_delivery_attempt_at', 'delivery_attempt_count', 'updated_at'],
                'workspace_subscriptions' => ['id', 'workspace_id', 'billing_plan_price_id', 'provider', 'provider_reference', 'provider_subscription_code', 'provider_customer_code', 'provider_email_token', 'provider_subscription_status', 'renewal_status', 'provider_metadata_json', 'subscription_status', 'current_period_start', 'current_period_end', 'trial_starts_at', 'trial_ends_at', 'next_billing_at', 'created_by'],
                'workspace_wallets' => ['id', 'workspace_id', 'currency', 'token_balance', 'reserved_tokens'],
            ],
        ],
    ];

    private const LAUNCH_CRITICAL_SURFACES = [
        'billing_snapshot',
        'billing_checkout',
        'billing_portal',
        'billing_finalize',
        'workspace_governance',
        'invite_acceptance',
        'mobile_workspace_payload',
        'mail_provider',
    ];

    /**
     * @return array<string,mixed>
     */
    public function checkWorkspaceSaasReadiness(string $surface = 'billing_portal'): array
    {
        $requirements = self::SURFACE_REQUIREMENTS[$surface] ?? self::SURFACE_REQUIREMENTS['billing_portal'];
        $tableRequirements = (array) ($requirements['tables'] ?? []);
        $tables = array_keys($tableRequirements);
        $issues = [];

        $existingTables = $this->loadExistingTables($tables);
        $existingColumns = $this->loadExistingColumns($tables);

        foreach ($tableRequirements as $table => $columns) {
            if (!isset($existingTables[$table])) {
                $issues[] = [
                    'type' => 'missing_table',
                    'table' => $table,
                    'column' => null,
                    'message' => sprintf('Missing required table `%s`.', $table),
                ];
                continue;
            }

            $availableColumns = $existingColumns[$table] ?? [];
            foreach ($columns as $column) {
                if (!isset($availableColumns[$column])) {
                    $issues[] = [
                        'type' => 'missing_column',
                        'table' => $table,
                        'column' => $column,
                        'message' => sprintf('Missing required column `%s.%s`.', $table, $column),
                    ];
                }
            }
        }

        return [
            'surface' => $surface,
            'label' => (string) ($requirements['label'] ?? $surface),
            'ready' => $issues === [],
            'customer_message' => (string) ($requirements['customer_message'] ?? 'This workspace surface is temporarily unavailable until the latest SaaS migrations are applied.'),
            'operator_message' => (string) ($requirements['operator_message'] ?? 'Apply the latest SaaS migrations for this environment.'),
            'issues' => $issues,
            'checked_at' => gmdate('c'),
        ];
    }

    public function assertWorkspaceSaasReadiness(string $surface = 'billing_portal'): void
    {
        $readiness = $this->checkWorkspaceSaasReadiness($surface);
        if (!empty($readiness['ready'])) {
            return;
        }

        error_log('Workspace launch readiness failed for surface=' . $surface . ' issues=' . json_encode($readiness['issues'] ?? [], JSON_UNESCAPED_SLASHES));
        throw new WorkspaceLaunchReadinessException($surface, $readiness);
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function checkLaunchCriticalSurfaces(?int $workspaceId = null): array
    {
        $results = [];
        foreach (self::LAUNCH_CRITICAL_SURFACES as $surface) {
            if ($surface === 'mail_provider') {
                $results[$surface] = $this->checkMailProviderReadiness($workspaceId);
                continue;
            }
            $results[$surface] = $this->checkWorkspaceSaasReadiness($surface);
        }

        return $results;
    }

    /**
     * @return array<string,mixed>
     */
    public function getWorkspaceLaunchStatus(?int $workspaceId = null): array
    {
        $surfaces = $this->checkLaunchCriticalSurfaces($workspaceId);
        $readyCount = 0;
        $issues = [];

        foreach ($surfaces as $surfaceKey => $surface) {
            if (!empty($surface['ready'])) {
                $readyCount++;
                continue;
            }

            foreach ((array) ($surface['issues'] ?? []) as $issue) {
                $issues[] = ['surface' => $surfaceKey] + $issue;
            }
        }

        $surfaceCount = count($surfaces);
        $isReady = $readyCount === $surfaceCount;

        return [
            'workspace_id' => $workspaceId,
            'ready' => $isReady,
            'ready_surface_count' => $readyCount,
            'surface_count' => $surfaceCount,
            'recommended_action' => $isReady
                ? 'Launch-critical SaaS surfaces are ready.'
                : 'Apply the latest SaaS and governance migrations before launch.',
            'checked_at' => gmdate('c'),
            'surfaces' => $surfaces,
            'issues' => $issues,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function checkMailProviderReadiness(?int $workspaceId = null): array
    {
        $emailIntegrationService = new EmailIntegrationService();
        $summary = $emailIntegrationService->getMainProviderSummary($workspaceId);
        $activeProviderKey = (string) ($summary['provider_key'] ?? EmailIntegrationService::PROVIDER_MANUAL_SMTP);
        $activeProviderLabel = (string) ($summary['provider_label'] ?? 'Manual SMTP / IMAP');
        $providers = is_array($summary['providers'] ?? null) ? $summary['providers'] : [];
        $issues = is_array($summary['issues'] ?? null) ? $summary['issues'] : [];
        $blocking = array_values(array_filter($issues, static function (array $issue): bool {
            return !in_array((string) ($issue['type'] ?? ''), ['incoming_fallback_missing'], true);
        }));

        $outboundReady = !empty($summary['is_active']) || !empty($summary['smtp_fallback_configured']);
        $incomingReady = !empty($summary['is_active']) || !empty($summary['incoming_fallback_configured']);

        if (!$outboundReady) {
            $issues[] = [
                'type' => 'mail_provider_missing',
                'provider' => EmailIntegrationService::PROVIDER_MANUAL_SMTP,
                'message' => 'No System Mail SMTP provider is configured for outbound platform mail.',
            ];
            $blocking = $issues;
        }

        return [
            'surface' => 'mail_provider',
            'label' => 'Mail provider readiness',
            'ready' => $blocking === [],
            'customer_message' => 'System Mail delivery is temporarily unavailable until platform SMTP is configured.',
            'operator_message' => $blocking === []
                ? ($incomingReady
                    ? 'System Mail checks passed for launch-critical outbound and fallback flows.'
                    : 'System Mail outbound is ready, but incoming IMAP fallback is still incomplete.')
                : 'Mail provider checks are blocking launch-critical invite, billing, or CRM mail delivery.',
            'issues' => $issues,
            'checked_at' => gmdate('c'),
            'details' => [
                'active_provider_key' => $activeProviderKey,
                'active_provider_label' => $activeProviderLabel,
                'smtp_fallback_configured' => !empty($summary['smtp_fallback_configured']),
                'incoming_fallback_configured' => !empty($summary['incoming_fallback_configured']),
                'readiness' => (string) ($summary['readiness'] ?? 'blocked'),
                'last_failure' => $summary['last_failure'] ?? null,
                'providers' => $providers,
            ],
        ];
    }

    /**
     * @param list<string> $tables
     * @return array<string,bool>
     */
    private function loadExistingTables(array $tables): array
    {
        if ($tables === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($tables), '?'));
        $rows = Database::query(
            "SELECT table_name
             FROM information_schema.TABLES
             WHERE table_schema = DATABASE()
               AND table_name IN ($placeholders)",
            $tables
        );

        $result = [];
        foreach ($rows as $row) {
            $table = (string) ($row['table_name'] ?? $row['TABLE_NAME'] ?? '');
            if ($table !== '') {
                $result[$table] = true;
            }
        }

        foreach ($tables as $table) {
            if (isset($result[$table]) || !$this->isSafeIdentifier($table)) {
                continue;
            }

            try {
                $stmt = Database::getInstance()->query('SELECT 1 FROM `' . $table . '` LIMIT 0');
                if ($stmt !== false) {
                    $result[$table] = true;
                    $stmt->closeCursor();
                }
            } catch (\Throwable $e) {
                // Keep the readiness result explicit; the caller will report this table as missing.
            }
        }

        return $result;
    }

    /**
     * @param list<string> $tables
     * @return array<string,array<string,bool>>
     */
    private function loadExistingColumns(array $tables): array
    {
        if ($tables === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($tables), '?'));
        $rows = Database::query(
            "SELECT table_name, column_name
             FROM information_schema.COLUMNS
             WHERE table_schema = DATABASE()
               AND table_name IN ($placeholders)",
            $tables
        );

        $result = [];
        foreach ($rows as $row) {
            $table = (string) ($row['table_name'] ?? $row['TABLE_NAME'] ?? '');
            $column = (string) ($row['column_name'] ?? $row['COLUMN_NAME'] ?? '');
            if ($table === '' || $column === '') {
                continue;
            }

            if (!isset($result[$table])) {
                $result[$table] = [];
            }
            $result[$table][$column] = true;
        }

        foreach ($tables as $table) {
            if (isset($result[$table]) || !$this->isSafeIdentifier($table)) {
                continue;
            }

            try {
                $stmt = Database::getInstance()->query('SHOW COLUMNS FROM `' . $table . '`');
                if ($stmt === false) {
                    continue;
                }

                $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($columns as $row) {
                    $column = (string) ($row['Field'] ?? $row['field'] ?? '');
                    if ($column === '') {
                        continue;
                    }
                    if (!isset($result[$table])) {
                        $result[$table] = [];
                    }
                    $result[$table][$column] = true;
                }
                $stmt->closeCursor();
            } catch (\Throwable $e) {
                // Leave missing column reporting to the main readiness loop.
            }
        }

        return $result;
    }

    private function isSafeIdentifier(string $identifier): bool
    {
        return preg_match('/^[A-Za-z0-9_]+$/', $identifier) === 1;
    }
}
