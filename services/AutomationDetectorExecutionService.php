<?php

namespace CRM\Services;

use CRM\Database;

class AutomationDetectorExecutionService
{
    /** @var array<int,string> */
    private const PREFLIGHT_DETECTOR_KEYS = [
        'missing_production_settings_detector',
        'broken_template_detector',
        'demo_test_data_detector',
        'failed_migration_detector',
        'failed_email_delivery_monitor',
        'failed_whatsapp_delivery_monitor',
        'integration_credential_missing_detector',
        'expiring_integration_credential_warning',
        'queue_backlog_monitor',
        'failed_background_job_monitor',
        'billing_package_mismatch_detector',
        'permission_drift_detector',
        'superadmin_access_repair_suggestion',
        'workspace_owner_missing_detector',
        'duplicate_default_templates_detector',
        'empty_onboarding_context_detector',
        'ai_runtime_overuse_detector',
        'disabled_critical_module_detector',
        'broken_marketplace_setup_detector',
        'daily_superadmin_health_digest',
    ];

    private const EXPIRING_CREDENTIAL_DAYS = 14;
    private const QUEUE_STALE_HOURS = 2;
    private const JOB_STALE_HOURS = 25;
    private const AI_RUNTIME_DAILY_TOKEN_LIMIT = 1000000;

    private AutomationCatalogService $catalog;
    private SystemReadinessService $readiness;

    public function __construct(?AutomationCatalogService $catalog = null, ?SystemReadinessService $readiness = null)
    {
        $this->catalog = $catalog ?? new AutomationCatalogService();
        $this->readiness = $readiness ?? new SystemReadinessService();
    }

    /**
     * @return array<string,mixed>
     */
    public function evaluateProductionReadinessDetectors(): array
    {
        $readiness = $this->readiness->check();
        $checks = $this->checksByKey((array) ($readiness['checks'] ?? []));
        $preflight = (array) ($checks['production_preflight'] ?? []);
        $migrations = (array) ($checks['migrations'] ?? []);

        $evaluations = [
            $this->missingProductionSettingsEvaluation($preflight),
            $this->brokenTemplateEvaluation($preflight),
            $this->demoTestDataEvaluation($preflight),
            $this->failedMigrationEvaluation($migrations),
            $this->failedEmailDeliveryEvaluation(),
            $this->failedWhatsAppDeliveryEvaluation(),
            $this->integrationCredentialMissingEvaluation(),
            $this->expiringIntegrationCredentialEvaluation(),
            $this->queueBacklogEvaluation(),
            $this->failedBackgroundJobEvaluation(),
            $this->billingPackageMismatchEvaluation(),
            $this->permissionDriftEvaluation(),
            $this->superadminAccessRepairEvaluation(),
            $this->workspaceOwnerMissingEvaluation(),
            $this->duplicateDefaultTemplatesEvaluation(),
            $this->emptyOnboardingContextEvaluation(),
            $this->aiRuntimeOveruseEvaluation(),
            $this->disabledCriticalModuleEvaluation($preflight),
            $this->brokenMarketplaceSetupEvaluation(),
        ];
        $evaluations[] = $this->dailyDigestEvaluation($readiness, $evaluations);

        return [
            'persisted' => false,
            'checked_at' => (string) ($readiness['checked_at'] ?? date('c')),
            'supported_detectors' => self::PREFLIGHT_DETECTOR_KEYS,
            'readiness_status' => (string) ($readiness['status'] ?? 'unknown'),
            'readiness_summary' => (array) ($readiness['summary'] ?? []),
            'evaluations' => $evaluations,
            'summary' => $this->summarize($evaluations),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function runProductionReadinessDetectors(?int $actorUserId = null): array
    {
        $result = $this->evaluateProductionReadinessDetectors();
        $recorded = 0;
        $skipped = 0;
        $evaluations = [];

        foreach ((array) ($result['evaluations'] ?? []) as $evaluation) {
            if (!is_array($evaluation)) {
                continue;
            }

            $definition = $this->catalog->findDefinition((string) ($evaluation['automation_key'] ?? ''));
            if ($definition === null) {
                $evaluation['recorded'] = false;
                $evaluation['skip_reason'] = 'missing_catalog_definition';
                $evaluations[] = $evaluation;
                $skipped++;
                continue;
            }

            $skipReason = $this->definitionSkipReason($definition);
            if ($skipReason !== '') {
                $evaluation['recorded'] = false;
                $evaluation['skip_reason'] = $skipReason;
                $evaluations[] = $evaluation;
                $skipped++;
                continue;
            }

            $runStatus = $this->runStatusForMode(
                (string) ($definition['effective_mode'] ?? $definition['default_mode'] ?? 'observe'),
                !empty($evaluation['finding']) || !empty($evaluation['always_prepare'])
            );

            $runId = $this->catalog->recordRun((string) $evaluation['automation_key'], [
                'actor_user_id' => $actorUserId,
                'workspace_id' => $evaluation['workspace_id'] ?? null,
                'run_status' => $runStatus,
                'severity' => (string) ($evaluation['severity'] ?? 'info'),
                'evidence' => (array) ($evaluation['evidence'] ?? []),
                'recommendation' => (array) ($evaluation['recommendation'] ?? []),
                'action_taken' => [],
                'source' => 'automation_detector_execution',
            ]);

            $evaluation['recorded'] = true;
            $evaluation['run_id'] = $runId;
            $evaluation['run_status'] = $runStatus;
            $evaluations[] = $evaluation;
            $recorded++;
        }

        $result['persisted'] = true;
        $result['evaluations'] = $evaluations;
        $result['summary'] = $this->summarize($evaluations) + [
            'recorded' => $recorded,
            'skipped' => $skipped,
        ];

        return $result;
    }

    /**
     * @param array<int,array<string,mixed>> $checks
     * @return array<string,array<string,mixed>>
     */
    private function checksByKey(array $checks): array
    {
        $byKey = [];
        foreach ($checks as $check) {
            if (!is_array($check)) {
                continue;
            }
            $key = (string) ($check['key'] ?? '');
            if ($key !== '') {
                $byKey[$key] = $check;
            }
        }
        return $byKey;
    }

    /**
     * @param array<string,mixed> $preflight
     * @return array<string,mixed>
     */
    private function missingProductionSettingsEvaluation(array $preflight): array
    {
        $metadata = (array) ($preflight['metadata'] ?? []);
        $environment = (array) ($metadata['environment'] ?? []);
        $warnings = array_values(array_map('strval', (array) ($environment['warnings'] ?? [])));
        $finding = $warnings !== [];

        return $this->evaluation(
            'missing_production_settings_detector',
            $finding,
            $finding ? 'warning' : 'info',
            [
                'environment' => $environment,
                'warnings' => $warnings,
                'preflight_status' => (string) ($preflight['status'] ?? 'unknown'),
            ],
            $finding
                ? ['action' => 'set_live_environment_values', 'items' => $warnings, 'customer_facing' => false]
                : ['action' => 'none', 'next' => 'production settings remain within current baseline']
        );
    }

    /**
     * @param array<string,mixed> $migrations
     * @return array<string,mixed>
     */
    private function failedMigrationEvaluation(array $migrations): array
    {
        $metadata = (array) ($migrations['metadata'] ?? []);
        $pending = (int) ($metadata['pending_count'] ?? 0);
        $finding = $pending > 0 || (string) ($migrations['status'] ?? '') === 'critical';

        return $this->evaluation(
            'failed_migration_detector',
            $finding,
            $finding ? 'critical' : 'info',
            [
                'status' => (string) ($migrations['status'] ?? 'unknown'),
                'message' => (string) ($migrations['message'] ?? ''),
                'latest_migration' => (string) ($metadata['latest_migration'] ?? ''),
                'latest_applied_migration' => (string) ($metadata['latest_applied_migration'] ?? ''),
                'pending_count' => $pending,
                'pending_migrations' => array_values(array_map('strval', (array) ($metadata['pending_migrations'] ?? []))),
            ],
            $finding
                ? ['action' => 'run_database_migrations', 'customer_facing' => false]
                : ['action' => 'none', 'next' => 'migration table matches migration files']
        );
    }

    /**
     * @param array<string,mixed> $preflight
     * @return array<string,mixed>
     */
    private function demoTestDataEvaluation(array $preflight): array
    {
        $dataCounts = $this->preflightDataCounts($preflight);
        $smokeContacts = (int) ($dataCounts['smoke_contacts'] ?? 0);
        $smokeDeals = (int) ($dataCounts['smoke_deals'] ?? 0);
        $finding = $smokeContacts > 0 || $smokeDeals > 0;

        return $this->evaluation(
            'demo_test_data_detector',
            $finding,
            $finding ? 'critical' : 'info',
            [
                'workspace_id' => 1,
                'smoke_contacts' => $smokeContacts,
                'smoke_deals' => $smokeDeals,
                'preflight_blockers' => array_values(array_map('strval', (array) (($preflight['metadata'] ?? [])['blockers'] ?? []))),
            ],
            $finding
                ? ['action' => 'review_default_workspace_demo_data', 'customer_facing' => false]
                : ['action' => 'none', 'next' => 'default workspace smoke/demo indicators remain clear'],
            1
        );
    }

    /**
     * @param array<string,mixed> $preflight
     * @return array<string,mixed>
     */
    private function brokenTemplateEvaluation(array $preflight): array
    {
        $dataCounts = $this->preflightDataCounts($preflight);
        $staleTemplates = (int) ($dataCounts['stale_email_templates'] ?? 0);
        $platformEmailTemplates = (int) ($dataCounts['platform_ops_email_templates'] ?? 0);
        $platformWorkflowTemplates = (int) ($dataCounts['platform_ops_workflow_templates'] ?? 0);
        $finding = $staleTemplates > 0 || $platformEmailTemplates < 8 || $platformWorkflowTemplates < 3;

        return $this->evaluation(
            'broken_template_detector',
            $finding,
            $finding ? 'warning' : 'info',
            [
                'stale_email_templates' => $staleTemplates,
                'platform_ops_email_templates' => $platformEmailTemplates,
                'platform_ops_workflow_templates' => $platformWorkflowTemplates,
                'minimum_platform_ops_email_templates' => 8,
                'minimum_platform_ops_workflow_templates' => 3,
            ],
            $finding
                ? ['action' => 'open_template_review', 'customer_facing' => false]
                : ['action' => 'none', 'next' => 'platform template baseline remains present']
        );
    }

    /**
     * @param array<string,mixed> $preflight
     * @return array<string,mixed>
     */
    private function disabledCriticalModuleEvaluation(array $preflight): array
    {
        $metadata = (array) ($preflight['metadata'] ?? []);
        $warnings = array_values(array_map('strval', (array) ($metadata['warnings'] ?? [])));
        $blockers = array_values(array_map('strval', (array) ($metadata['blockers'] ?? [])));
        $moduleSignals = array_values(array_filter(
            array_merge($warnings, $blockers),
            static fn(string $signal): bool => str_contains($signal, 'auto_admin')
                || str_contains($signal, 'autoresponder')
                || str_contains($signal, 'automation')
                || str_contains($signal, 'workflow_autonomy')
                || str_contains($signal, 'cold_outreach')
        ));
        $finding = $moduleSignals !== [];

        return $this->evaluation(
            'disabled_critical_module_detector',
            $finding,
            array_intersect($moduleSignals, $blockers) !== [] ? 'critical' : ($finding ? 'warning' : 'info'),
            [
                'automation_safety' => (array) ($metadata['automation_safety'] ?? []),
                'module_signals' => $moduleSignals,
            ],
            $finding
                ? ['action' => 'review_critical_module_state', 'customer_facing' => false]
                : ['action' => 'none', 'next' => 'critical automation module baseline remains safe']
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function failedEmailDeliveryEvaluation(): array
    {
        $supported = false;
        $failedEmails = 0;
        $failedQueue = 0;
        $failedAudit = 0;
        $affectedWorkspaceIds = [];

        if ($this->tableHasColumns('emails', ['status', 'created_at'])) {
            $supported = true;
            $failedEmails = $this->safeCount(
                "SELECT COUNT(*) AS c
                 FROM emails
                 WHERE status IN ('failed', 'bounced')
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
            );
            $affectedWorkspaceIds = array_merge(
                $affectedWorkspaceIds,
                $this->affectedWorkspaceIds('emails', "status IN ('failed', 'bounced') AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")
            );
        }

        if ($this->tableHasColumns('email_queue', ['status', 'created_at'])) {
            $supported = true;
            $failedQueue = $this->safeCount(
                "SELECT COUNT(*) AS c
                 FROM email_queue
                 WHERE status = 'failed'
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
            );
            $affectedWorkspaceIds = array_merge(
                $affectedWorkspaceIds,
                $this->affectedWorkspaceIds('email_queue', "status = 'failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")
            );
        }

        if ($this->tableHasColumns('email_reply_delivery_audit', ['status', 'created_at'])) {
            $supported = true;
            $failedAudit = $this->safeCount(
                "SELECT COUNT(*) AS c
                 FROM email_reply_delivery_audit
                 WHERE status IN ('failed', 'sync_failed')
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
            );
        }

        $totalFailures = $failedEmails + $failedQueue + $failedAudit;
        $finding = $supported && $totalFailures > 0;

        return $this->evaluation(
            'failed_email_delivery_monitor',
            $finding,
            $totalFailures >= 10 ? 'critical' : ($finding ? 'warning' : 'info'),
            [
                'supported' => $supported,
                'failed_emails_7d' => $failedEmails,
                'failed_queue_items_7d' => $failedQueue,
                'failed_reply_audit_7d' => $failedAudit,
                'affected_workspace_ids' => $this->uniqueInts($affectedWorkspaceIds),
            ],
            $finding
                ? ['action' => 'open_delivery_review', 'customer_facing' => false]
                : ['action' => 'none', 'next' => $supported ? 'email delivery failures remain clear' : 'email delivery tables are not installed']
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function failedWhatsAppDeliveryEvaluation(): array
    {
        $supported = false;
        $failedMessages = 0;
        $failedQueue = 0;
        $attentionIntegrations = 0;
        $affectedWorkspaceIds = [];

        if ($this->tableHasColumns('whatsapp_messages', ['status', 'created_at'])) {
            $supported = true;
            $failedMessages = $this->safeCount(
                "SELECT COUNT(*) AS c
                 FROM whatsapp_messages
                 WHERE status = 'failed'
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
            );
            $affectedWorkspaceIds = array_merge(
                $affectedWorkspaceIds,
                $this->affectedWorkspaceIds('whatsapp_messages', "status = 'failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")
            );
        }

        if ($this->tableHasColumns('whatsapp_queue', ['status', 'created_at'])) {
            $supported = true;
            $failedQueue = $this->safeCount(
                "SELECT COUNT(*) AS c
                 FROM whatsapp_queue
                 WHERE status = 'failed'
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
            );
            $affectedWorkspaceIds = array_merge(
                $affectedWorkspaceIds,
                $this->affectedWorkspaceIds('whatsapp_queue', "status = 'failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")
            );
        }

        if ($this->tableHasColumns('workspace_whatsapp_integrations', ['connection_status'])) {
            $supported = true;
            $attentionIntegrations = $this->safeCount(
                "SELECT COUNT(*) AS c
                 FROM workspace_whatsapp_integrations
                 WHERE connection_status = 'needs_attention'"
            );
            $affectedWorkspaceIds = array_merge(
                $affectedWorkspaceIds,
                $this->affectedWorkspaceIds('workspace_whatsapp_integrations', "connection_status = 'needs_attention'")
            );
        }

        $totalFailures = $failedMessages + $failedQueue + $attentionIntegrations;
        $finding = $supported && $totalFailures > 0;

        return $this->evaluation(
            'failed_whatsapp_delivery_monitor',
            $finding,
            $totalFailures >= 10 ? 'critical' : ($finding ? 'warning' : 'info'),
            [
                'supported' => $supported,
                'failed_messages_7d' => $failedMessages,
                'failed_queue_items_7d' => $failedQueue,
                'needs_attention_integrations' => $attentionIntegrations,
                'affected_workspace_ids' => $this->uniqueInts($affectedWorkspaceIds),
            ],
            $finding
                ? ['action' => 'open_whatsapp_delivery_review', 'customer_facing' => false]
                : ['action' => 'none', 'next' => $supported ? 'WhatsApp delivery failures remain clear' : 'WhatsApp delivery tables are not installed']
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function integrationCredentialMissingEvaluation(): array
    {
        $checks = [
            'email_integrations' => $this->missingCredentialCount(
                'email_integrations',
                ['provider', 'scope', 'access_token'],
                "is_active = 1 AND (access_token IS NULL OR access_token = '')",
                'email'
            ),
            'platform_email_defaults' => $this->platformEmailDefaultsCredentialCount(),
            'workspace_whatsapp_integrations' => $this->missingCredentialCount(
                'workspace_whatsapp_integrations',
                ['workspace_id', 'connection_status', 'access_token', 'phone_number_id'],
                "connection_status IN ('connecting', 'connected', 'needs_attention')
                 AND (access_token IS NULL OR access_token = '' OR phone_number_id IS NULL OR phone_number_id = '')",
                'whatsapp'
            ),
            'calendar_integrations' => $this->missingCredentialCount(
                'calendar_integrations',
                ['provider', 'sync_enabled', 'access_token'],
                "sync_enabled = 1 AND (access_token IS NULL OR access_token = '')",
                'calendar'
            ),
            'social_integrations' => $this->missingCredentialCount(
                'social_integrations',
                ['provider', 'is_active', 'access_token'],
                "is_active = 1 AND (access_token IS NULL OR access_token = '')",
                'social'
            ),
        ];

        $supported = count(array_filter($checks, static fn(array $check): bool => !empty($check['supported']))) > 0;
        $missing = array_sum(array_map(static fn(array $check): int => (int) ($check['missing_count'] ?? 0), $checks));
        $affectedWorkspaceIds = [];
        foreach ($checks as $check) {
            $affectedWorkspaceIds = array_merge($affectedWorkspaceIds, (array) ($check['affected_workspace_ids'] ?? []));
        }

        return $this->evaluation(
            'integration_credential_missing_detector',
            $supported && $missing > 0,
            $missing > 0 ? 'warning' : 'info',
            [
                'supported' => $supported,
                'checks' => $checks,
                'missing_credentials' => $missing,
                'affected_workspace_ids' => $this->uniqueInts($affectedWorkspaceIds),
            ],
            $missing > 0
                ? ['action' => 'recommend_integration_setup', 'customer_facing' => false]
                : ['action' => 'none', 'next' => $supported ? 'enabled integration credentials remain present' : 'integration credential tables are not installed']
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function expiringIntegrationCredentialEvaluation(): array
    {
        $checks = [
            'email_integrations' => $this->expiringCredentialCount('email_integrations', ['token_expires_at'], 'email'),
            'workspace_whatsapp_integrations' => $this->expiringCredentialCount('workspace_whatsapp_integrations', ['token_expires_at'], 'whatsapp'),
            'calendar_integrations' => $this->expiringCredentialCount('calendar_integrations', ['token_expires_at'], 'calendar'),
            'social_integrations' => $this->expiringCredentialCount('social_integrations', ['token_expires_at'], 'social'),
        ];

        $supported = count(array_filter($checks, static fn(array $check): bool => !empty($check['supported']))) > 0;
        $expired = array_sum(array_map(static fn(array $check): int => (int) ($check['expired_count'] ?? 0), $checks));
        $expiring = array_sum(array_map(static fn(array $check): int => (int) ($check['expiring_count'] ?? 0), $checks));

        return $this->evaluation(
            'expiring_integration_credential_warning',
            $supported && ($expired + $expiring) > 0,
            $expired > 0 ? 'critical' : ($expiring > 0 ? 'warning' : 'info'),
            [
                'supported' => $supported,
                'warning_window_days' => self::EXPIRING_CREDENTIAL_DAYS,
                'expired_credentials' => $expired,
                'expiring_credentials' => $expiring,
                'checks' => $checks,
            ],
            ($expired + $expiring) > 0
                ? ['action' => 'open_reconnect_task', 'customer_facing' => false]
                : ['action' => 'none', 'next' => $supported ? 'credential expiry window remains clear' : 'credential expiry columns are not installed']
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function queueBacklogEvaluation(): array
    {
        $queues = [
            'email_queue' => $this->queueSnapshot('email_queue', 'created_at'),
            'whatsapp_queue' => $this->queueSnapshot('whatsapp_queue', 'created_at'),
            'workflow_queue' => $this->queueSnapshot('workflow_queue', 'created_at'),
            'workflow_retry_queue' => $this->queueSnapshot('workflow_retry_queue', 'retry_after'),
            'ai_autoresponder_queue' => $this->queueSnapshot('ai_autoresponder_queue', 'created_at'),
            'campaign_queue' => $this->queueSnapshot('campaign_queue', 'created_at'),
            'task_completion_scan_queue' => $this->queueSnapshot('task_completion_scan_queue', 'created_at'),
            'marketing_execution_queue' => $this->queueSnapshot('marketing_execution_queue', 'created_at'),
        ];

        $supported = count(array_filter($queues, static fn(array $queue): bool => !empty($queue['supported']))) > 0;
        $stale = array_sum(array_map(static fn(array $queue): int => (int) ($queue['stale_pending_count'] ?? 0), $queues));
        $pending = array_sum(array_map(static fn(array $queue): int => (int) ($queue['pending_count'] ?? 0), $queues));
        $failed = array_sum(array_map(static fn(array $queue): int => (int) ($queue['failed_count'] ?? 0), $queues));
        $finding = $supported && ($stale > 0 || $failed > 0);

        return $this->evaluation(
            'queue_backlog_monitor',
            $finding,
            $failed > 0 ? 'warning' : ($stale > 0 ? 'warning' : 'info'),
            [
                'supported' => $supported,
                'stale_threshold_hours' => self::QUEUE_STALE_HOURS,
                'pending_count' => $pending,
                'stale_pending_count' => $stale,
                'failed_count' => $failed,
                'queues' => $queues,
            ],
            $finding
                ? ['action' => 'open_queue_review', 'customer_facing' => false]
                : ['action' => 'none', 'next' => $supported ? 'queue backlog remains within threshold' : 'queue tables are not installed']
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function failedBackgroundJobEvaluation(): array
    {
        if (!$this->tableHasColumns('automation_job_health', ['job_key', 'status', 'last_run_at', 'last_success_at', 'last_failure_at', 'updated_at'])) {
            return $this->evaluation(
                'failed_background_job_monitor',
                false,
                'info',
                ['supported' => false, 'missing_tables' => ['automation_job_health']],
                ['action' => 'none', 'next' => 'automation job health table is not installed']
            );
        }

        $failed = $this->safeCount("SELECT COUNT(*) AS c FROM automation_job_health WHERE status = 'failed'");
        $lastSuccessFilter = Database::columnExists('automation_job_health', 'last_success_at')
            ? " OR (last_success_at IS NOT NULL AND last_success_at < DATE_SUB(NOW(), INTERVAL " . self::JOB_STALE_HOURS . " HOUR))"
            : '';
        $stale = $lastSuccessFilter !== ''
            ? $this->safeCount(
                "SELECT COUNT(*) AS c
                 FROM automation_job_health
                 WHERE status <> 'failed'
                   AND (last_run_at IS NULL{$lastSuccessFilter})"
            )
            : 0;
        $samples = $this->safeRows(
            "SELECT job_key, status, last_run_at, last_success_at, last_failure_at
             FROM automation_job_health
             WHERE status = 'failed'{$lastSuccessFilter}
             ORDER BY updated_at DESC
             LIMIT 10"
        );
        $finding = ($failed + $stale) > 0;

        return $this->evaluation(
            'failed_background_job_monitor',
            $finding,
            $failed > 0 ? 'critical' : ($stale > 0 ? 'warning' : 'info'),
            [
                'supported' => true,
                'stale_threshold_hours' => self::JOB_STALE_HOURS,
                'failed_jobs' => $failed,
                'stale_jobs' => $stale,
                'samples' => $samples,
            ],
            $finding
                ? ['action' => 'recommend_worker_repair', 'customer_facing' => false]
                : ['action' => 'none', 'next' => 'background job health remains clear']
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function billingPackageMismatchEvaluation(): array
    {
        $supported = Database::tableExists('workspaces');
        $missingSubscription = 0;
        $invalidPrice = 0;
        $walletIssues = 0;
        $affectedWorkspaceIds = [];

        if ($this->tableHasColumns('workspaces', ['id', 'status', 'plan_status']) && $this->tableHasColumns('workspace_subscriptions', ['workspace_id', 'subscription_status'])) {
            $supported = true;
            $missingSubscription = $this->safeCount(
                "SELECT COUNT(*) AS c
                 FROM workspaces w
                 WHERE w.status IN ('active', 'trialing')
                   AND w.plan_status IN ('active', 'trialing', 'past_due')
                   AND NOT EXISTS (
                       SELECT 1
                       FROM workspace_subscriptions ws
                       WHERE ws.workspace_id = w.id
                         AND ws.subscription_status IN ('active', 'trialing', 'past_due')
                   )"
            );
            foreach ($this->safeRows(
                "SELECT w.id AS workspace_id
                 FROM workspaces w
                 WHERE w.status IN ('active', 'trialing')
                   AND w.plan_status IN ('active', 'trialing', 'past_due')
                   AND NOT EXISTS (
                       SELECT 1
                       FROM workspace_subscriptions ws
                       WHERE ws.workspace_id = w.id
                         AND ws.subscription_status IN ('active', 'trialing', 'past_due')
                   )
                 LIMIT 25"
            ) as $row) {
                $affectedWorkspaceIds[] = (int) ($row['workspace_id'] ?? 0);
            }
        }

        if ($this->tableHasColumns('workspace_subscriptions', ['workspace_id', 'billing_plan_price_id', 'subscription_status'])
            && $this->tableHasColumns('billing_plan_prices', ['id', 'is_active'])) {
            $supported = true;
            $privatePriceMismatch = Database::columnExists('billing_plan_prices', 'workspace_id')
                ? " OR (bpp.workspace_id IS NOT NULL AND bpp.workspace_id <> ws.workspace_id)"
                : '';
            $invalidPrice = $this->safeCount(
                "SELECT COUNT(*) AS c
                 FROM workspace_subscriptions ws
                 LEFT JOIN billing_plan_prices bpp ON bpp.id = ws.billing_plan_price_id
                 WHERE ws.subscription_status IN ('active', 'trialing', 'past_due')
                   AND (bpp.id IS NULL OR bpp.is_active = 0{$privatePriceMismatch})"
            );
        }

        if ($this->tableHasColumns('workspaces', ['id', 'status']) && $this->tableHasColumns('workspace_wallets', ['workspace_id', 'token_balance', 'reserved_tokens'])) {
            $supported = true;
            $walletIssues = $this->safeCount(
                "SELECT COUNT(*) AS c
                 FROM workspaces w
                 LEFT JOIN workspace_wallets wallet ON wallet.workspace_id = w.id
                 WHERE w.status IN ('active', 'trialing')
                   AND (wallet.workspace_id IS NULL OR wallet.token_balance < 0 OR wallet.reserved_tokens < 0 OR wallet.reserved_tokens > wallet.token_balance)"
            );
        }

        $total = $missingSubscription + $invalidPrice + $walletIssues;

        return $this->evaluation(
            'billing_package_mismatch_detector',
            $supported && $total > 0,
            $total > 0 ? 'warning' : 'info',
            [
                'supported' => $supported,
                'active_workspaces_without_subscription' => $missingSubscription,
                'active_subscriptions_with_invalid_price' => $invalidPrice,
                'wallet_mismatch_count' => $walletIssues,
                'affected_workspace_ids' => $this->uniqueInts($affectedWorkspaceIds),
            ],
            $total > 0
                ? ['action' => 'open_billing_review', 'customer_facing' => false]
                : ['action' => 'none', 'next' => $supported ? 'billing/package consistency remains clear' : 'billing tables are not installed']
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function permissionDriftEvaluation(): array
    {
        if (!$this->tableHasColumns('roles', ['id', 'slug'])
            || !$this->tableHasColumns('permissions', ['id', 'permission_key'])
            || !$this->tableHasColumns('role_permissions', ['role_id', 'permission_id', 'can_access'])) {
            return $this->evaluation(
                'permission_drift_detector',
                false,
                'info',
                ['supported' => false, 'missing_tables' => ['roles', 'permissions', 'role_permissions']],
                ['action' => 'none', 'next' => 'RBAC tables are not installed']
            );
        }

        $adminMissingCore = $this->safeCount(
            "SELECT COUNT(*) AS c
             FROM permissions p
             LEFT JOIN roles r ON r.slug = 'admin'
             LEFT JOIN role_permissions rp ON rp.role_id = r.id AND rp.permission_id = p.id AND rp.can_access = 1
             WHERE p.permission_key IN ('settings.monitoring', 'admin.roles.manage', 'admin.users.manage')
               AND rp.permission_id IS NULL"
        );
        $ownerPlatformGrants = $this->safeCount(
            "SELECT COUNT(*) AS c
             FROM roles r
             JOIN role_permissions rp ON rp.role_id = r.id AND rp.can_access = 1
             JOIN permissions p ON p.id = rp.permission_id
             WHERE r.slug = 'owner'
               AND (p.permission_key LIKE 'platform.%' OR p.permission_key LIKE 'operator.%')"
        );
        $inactiveSystemRoles = Database::columnExists('roles', 'is_active')
            ? $this->safeCount("SELECT COUNT(*) AS c FROM roles WHERE slug IN ('admin', 'owner', 'superadmin') AND is_active = 0")
            : 0;
        $total = $adminMissingCore + $ownerPlatformGrants + $inactiveSystemRoles;

        return $this->evaluation(
            'permission_drift_detector',
            $total > 0,
            $total > 0 ? 'critical' : 'info',
            [
                'supported' => true,
                'admin_missing_core_permissions' => $adminMissingCore,
                'owner_platform_permission_grants' => $ownerPlatformGrants,
                'inactive_system_roles' => $inactiveSystemRoles,
            ],
            $total > 0
                ? ['action' => 'open_permission_review', 'customer_facing' => false]
                : ['action' => 'none', 'next' => 'role permission baseline remains aligned']
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function superadminAccessRepairEvaluation(): array
    {
        if (!$this->tableHasColumns('roles', ['id', 'slug'])
            || !$this->tableHasColumns('users', ['id'])
            || !$this->tableHasColumns('user_roles', ['user_id', 'role_id'])) {
            return $this->evaluation(
                'superadmin_access_repair_suggestion',
                false,
                'info',
                ['supported' => false, 'missing_tables' => ['roles', 'users', 'user_roles']],
                ['action' => 'none', 'next' => 'global role tables are not installed']
            );
        }

        $roleExists = $this->safeCount("SELECT COUNT(*) AS c FROM roles WHERE slug = 'superadmin'") > 0;
        $superadminUsers = $this->safeCount(
            "SELECT COUNT(DISTINCT u.id) AS c
             FROM users u
             JOIN user_roles ur ON ur.user_id = u.id
             JOIN roles r ON r.id = ur.role_id
             WHERE r.slug = 'superadmin'"
        );
        $missingPermissionGrants = 0;
        if ($roleExists && $this->tableHasColumns('permissions', ['id']) && $this->tableHasColumns('role_permissions', ['role_id', 'permission_id', 'can_access'])) {
            $missingPermissionGrants = $this->safeCount(
                "SELECT COUNT(*) AS c
                 FROM permissions p
                 JOIN roles r ON r.slug = 'superadmin'
                 LEFT JOIN role_permissions rp ON rp.role_id = r.id AND rp.permission_id = p.id AND rp.can_access = 1
                 WHERE rp.permission_id IS NULL"
            );
        }

        $defaultMembershipMissing = false;
        if ($this->tableHasColumns('workspace_memberships', ['workspace_id', 'user_id', 'role_slug', 'membership_status'])) {
            $defaultMembershipMissing = $this->safeCount(
                "SELECT COUNT(*) AS c
                 FROM workspace_memberships wm
                 LEFT JOIN user_roles ur ON ur.user_id = wm.user_id
                 LEFT JOIN roles r ON r.id = ur.role_id
                 WHERE wm.workspace_id = 1
                   AND wm.membership_status = 'active'
                   AND (wm.role_slug = 'superadmin' OR r.slug = 'superadmin')"
            ) === 0;
        }

        $finding = !$roleExists || $superadminUsers === 0 || $missingPermissionGrants > 0 || $defaultMembershipMissing;

        return $this->evaluation(
            'superadmin_access_repair_suggestion',
            $finding,
            $finding ? 'critical' : 'info',
            [
                'supported' => true,
                'superadmin_role_exists' => $roleExists,
                'superadmin_user_count' => $superadminUsers,
                'missing_permission_grants' => $missingPermissionGrants,
                'default_workspace_superadmin_membership_missing' => $defaultMembershipMissing,
            ],
            $finding
                ? ['action' => 'recommend_superadmin_access_repair', 'customer_facing' => false]
                : ['action' => 'none', 'next' => 'superadmin access baseline remains present']
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function workspaceOwnerMissingEvaluation(): array
    {
        if (!$this->tableHasColumns('workspaces', ['id', 'status'])
            || !$this->tableHasColumns('workspace_memberships', ['workspace_id', 'role_slug', 'membership_status'])) {
            return $this->evaluation(
                'workspace_owner_missing_detector',
                false,
                'info',
                ['supported' => false, 'missing_tables' => ['workspaces', 'workspace_memberships']],
                ['action' => 'none', 'next' => 'workspace membership tables are not installed']
            );
        }

        $ownerPredicate = Database::columnExists('workspace_memberships', 'is_owner')
            ? "(wm.is_owner = 1 OR wm.role_slug IN ('owner', 'superadmin', 'admin'))"
            : "wm.role_slug IN ('owner', 'superadmin', 'admin')";
        $missing = $this->safeRows(
            "SELECT w.id AS workspace_id, w.status
             FROM workspaces w
             WHERE w.status IN ('active', 'trialing', 'past_due')
               AND NOT EXISTS (
                   SELECT 1
                   FROM workspace_memberships wm
                   WHERE wm.workspace_id = w.id
                     AND wm.membership_status = 'active'
                     AND {$ownerPredicate}
               )
             ORDER BY w.id ASC
             LIMIT 25"
        );
        $missingCount = $this->safeCount(
            "SELECT COUNT(*) AS c
             FROM workspaces w
             WHERE w.status IN ('active', 'trialing', 'past_due')
               AND NOT EXISTS (
                   SELECT 1
                   FROM workspace_memberships wm
                   WHERE wm.workspace_id = w.id
                     AND wm.membership_status = 'active'
                     AND {$ownerPredicate}
               )"
        );

        return $this->evaluation(
            'workspace_owner_missing_detector',
            $missingCount > 0,
            $missingCount > 0 ? 'critical' : 'info',
            [
                'supported' => true,
                'missing_owner_workspace_count' => $missingCount,
                'sample_workspaces' => $missing,
            ],
            $missingCount > 0
                ? ['action' => 'open_owner_repair_review', 'customer_facing' => false]
                : ['action' => 'none', 'next' => 'active workspaces have active owner membership signals']
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function duplicateDefaultTemplatesEvaluation(): array
    {
        $email = $this->duplicateTemplateSnapshot('email_templates', 'email');
        $workflow = $this->duplicateTemplateSnapshot('workflow_templates', 'workflow');
        $supported = !empty($email['supported']) || !empty($workflow['supported']);
        $duplicates = (int) ($email['duplicate_groups'] ?? 0) + (int) ($workflow['duplicate_groups'] ?? 0);

        return $this->evaluation(
            'duplicate_default_templates_detector',
            $supported && $duplicates > 0,
            $duplicates > 0 ? 'warning' : 'info',
            [
                'supported' => $supported,
                'duplicate_groups' => $duplicates,
                'email_templates' => $email,
                'workflow_templates' => $workflow,
            ],
            $duplicates > 0
                ? ['action' => 'open_template_dedupe_review', 'customer_facing' => false]
                : ['action' => 'none', 'next' => $supported ? 'default template duplicate groups remain clear' : 'template tables are not installed']
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyOnboardingContextEvaluation(): array
    {
        if (!$this->tableHasColumns('workspaces', ['id', 'status'])
            || !$this->tableHasColumns('workspace_onboarding_state', ['workspace_id', 'status'])) {
            return $this->evaluation(
                'empty_onboarding_context_detector',
                false,
                'info',
                ['supported' => false, 'missing_tables' => ['workspaces', 'workspace_onboarding_state']],
                ['action' => 'none', 'next' => 'workspace onboarding state table is not installed']
            );
        }

        $missingState = $this->safeCount(
            "SELECT COUNT(*) AS c
             FROM workspaces w
             WHERE w.status IN ('active', 'trialing')
               AND NOT EXISTS (
                   SELECT 1
                   FROM workspace_onboarding_state s
                   WHERE s.workspace_id = w.id
               )"
        );
        $emptyCompletedSteps = Database::columnExists('workspace_onboarding_state', 'completed_steps_json')
            ? $this->safeCount(
                "SELECT COUNT(*) AS c
                 FROM workspace_onboarding_state s
                 JOIN workspaces w ON w.id = s.workspace_id
                 WHERE w.status IN ('active', 'trialing')
                   AND s.status = 'in_progress'
                   AND (s.completed_steps_json IS NULL OR CAST(s.completed_steps_json AS CHAR) IN ('', '[]', '{}'))"
            )
            : 0;
        $missingContext = $missingState + $emptyCompletedSteps;

        return $this->evaluation(
            'empty_onboarding_context_detector',
            $missingContext > 0,
            $missingContext > 0 ? 'warning' : 'info',
            [
                'supported' => true,
                'workspaces_missing_onboarding_state' => $missingState,
                'in_progress_with_empty_completed_steps' => $emptyCompletedSteps,
            ],
            $missingContext > 0
                ? ['action' => 'recommend_onboarding_context_repair', 'customer_facing' => false]
                : ['action' => 'none', 'next' => 'onboarding context baseline remains populated']
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function aiRuntimeOveruseEvaluation(): array
    {
        $supported = false;
        $overLimitWorkspaces = 0;
        $globalTokenUsage = 0;
        $sampleWorkspaces = [];

        if ($this->tableHasColumns('workspace_ai_usage', ['workspace_id', 'billable_tokens', 'created_at'])) {
            $supported = true;
            $sampleWorkspaces = $this->safeRows(
                "SELECT workspace_id, SUM(billable_tokens) AS billable_tokens, COUNT(*) AS run_count
                 FROM workspace_ai_usage
                 WHERE created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
                 GROUP BY workspace_id
                 HAVING SUM(billable_tokens) > " . self::AI_RUNTIME_DAILY_TOKEN_LIMIT . "
                 ORDER BY billable_tokens DESC
                 LIMIT 10"
            );
            $overLimitWorkspaces = count($sampleWorkspaces);
        }

        if ($this->tableHasColumns('ai_usage', ['token_count', 'date'])) {
            $supported = true;
            $globalTokenUsage = $this->safeCount(
                "SELECT COALESCE(SUM(token_count), 0) AS c
                 FROM ai_usage
                 WHERE date >= DATE_SUB(CURDATE(), INTERVAL 1 DAY)"
            );
        }

        $globalOverLimit = $globalTokenUsage > self::AI_RUNTIME_DAILY_TOKEN_LIMIT;
        $finding = $supported && ($overLimitWorkspaces > 0 || $globalOverLimit);

        return $this->evaluation(
            'ai_runtime_overuse_detector',
            $finding,
            $finding ? 'warning' : 'info',
            [
                'supported' => $supported,
                'daily_token_limit' => self::AI_RUNTIME_DAILY_TOKEN_LIMIT,
                'over_limit_workspace_count' => $overLimitWorkspaces,
                'global_token_usage_24h' => $globalTokenUsage,
                'sample_workspaces' => $sampleWorkspaces,
            ],
            $finding
                ? ['action' => 'recommend_ai_runtime_limit_review', 'customer_facing' => false]
                : ['action' => 'none', 'next' => $supported ? 'AI runtime usage remains within detector threshold' : 'AI usage tables are not installed']
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function brokenMarketplaceSetupEvaluation(): array
    {
        $setupSteps = $this->marketplacePendingCount('workspace_marketplace_setup_journey_steps', 'status', ['pending']);
        $bundleState = $this->marketplacePendingCount('workspace_marketplace_activation_bundle_state', 'status', ['selected']);
        $setupEvents = $this->marketplaceEventFailureCount();
        $supported = !empty($setupSteps['supported']) || !empty($bundleState['supported']) || !empty($setupEvents['supported']);
        $issues = (int) ($setupSteps['pending_count'] ?? 0)
            + (int) ($bundleState['pending_count'] ?? 0)
            + (int) ($setupEvents['failure_count'] ?? 0);

        return $this->evaluation(
            'broken_marketplace_setup_detector',
            $supported && $issues > 0,
            $issues > 0 ? 'warning' : 'info',
            [
                'supported' => $supported,
                'issue_count' => $issues,
                'setup_steps' => $setupSteps,
                'activation_bundles' => $bundleState,
                'setup_events' => $setupEvents,
            ],
            $issues > 0
                ? ['action' => 'open_marketplace_setup_review', 'customer_facing' => false]
                : ['action' => 'none', 'next' => $supported ? 'marketplace setup signals remain clear' : 'marketplace setup tables are not installed']
        );
    }

    /**
     * @param array<string,mixed> $readiness
     * @param array<int,array<string,mixed>> $evaluations
     * @return array<string,mixed>
     */
    private function dailyDigestEvaluation(array $readiness, array $evaluations): array
    {
        $critical = count(array_filter(
            $evaluations,
            static fn(array $evaluation): bool => !empty($evaluation['finding']) && (string) ($evaluation['severity'] ?? '') === 'critical'
        ));
        $warning = count(array_filter(
            $evaluations,
            static fn(array $evaluation): bool => !empty($evaluation['finding']) && (string) ($evaluation['severity'] ?? '') === 'warning'
        ));
        $findingKeys = array_values(array_map(
            static fn(array $evaluation): string => (string) ($evaluation['automation_key'] ?? ''),
            array_filter($evaluations, static fn(array $evaluation): bool => !empty($evaluation['finding']))
        ));

        $evaluation = $this->evaluation(
            'daily_superadmin_health_digest',
            false,
            $critical > 0 ? 'critical' : ($warning > 0 ? 'warning' : 'info'),
            [
                'readiness_status' => (string) ($readiness['status'] ?? 'unknown'),
                'readiness_summary' => (array) ($readiness['summary'] ?? []),
                'critical_count' => $critical,
                'warning_count' => $warning,
                'finding_keys' => $findingKeys,
            ],
            [
                'action' => 'prepare_digest_draft',
                'customer_facing' => false,
                'summary' => $critical . ' critical and ' . $warning . ' warning detector finding(s).',
            ]
        );
        $evaluation['always_prepare'] = true;
        return $evaluation;
    }

    /**
     * @param array<string,mixed> $preflight
     * @return array<string,int>
     */
    private function preflightDataCounts(array $preflight): array
    {
        $metadata = (array) ($preflight['metadata'] ?? []);
        $counts = [];
        foreach ((array) ($metadata['data_counts'] ?? []) as $key => $value) {
            $counts[(string) $key] = (int) $value;
        }
        return $counts;
    }

    /**
     * @param array<int,string> $columns
     */
    private function tableHasColumns(string $table, array $columns): bool
    {
        if (!Database::tableExists($table)) {
            return false;
        }
        foreach ($columns as $column) {
            if (!Database::columnExists($table, $column)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<int,mixed> $params
     */
    private function safeCount(string $sql, array $params = []): int
    {
        try {
            $row = Database::queryOne($sql, $params);
            return max(0, (int) ($row['c'] ?? 0));
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * @param array<int,mixed> $params
     * @return array<int,array<string,mixed>>
     */
    private function safeRows(string $sql, array $params = []): array
    {
        try {
            return Database::query($sql, $params);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * @param array<int,mixed> $params
     * @return array<int,int>
     */
    private function affectedWorkspaceIds(string $table, string $whereSql, array $params = []): array
    {
        if (!Database::tableExists($table) || !Database::columnExists($table, 'workspace_id')) {
            return [];
        }

        $rows = $this->safeRows(
            "SELECT DISTINCT workspace_id
             FROM {$table}
             WHERE workspace_id IS NOT NULL
               AND {$whereSql}
             LIMIT 25",
            $params
        );

        return array_map(static fn(array $row): int => (int) ($row['workspace_id'] ?? 0), $rows);
    }

    /**
     * @param array<int,mixed> $values
     * @return array<int,int>
     */
    private function uniqueInts(array $values): array
    {
        $ints = [];
        foreach ($values as $value) {
            $int = (int) $value;
            if ($int > 0) {
                $ints[$int] = $int;
            }
        }
        ksort($ints);
        return array_values($ints);
    }

    /**
     * @param array<int,string> $columns
     * @return array<string,mixed>
     */
    private function missingCredentialCount(string $table, array $columns, string $whereSql, string $providerType): array
    {
        if (!$this->tableHasColumns($table, $columns)) {
            return [
                'supported' => false,
                'provider_type' => $providerType,
                'missing_count' => 0,
                'unsupported_reason' => 'missing_table_or_columns',
            ];
        }

        $missing = $this->safeCount("SELECT COUNT(*) AS c FROM {$table} WHERE {$whereSql}");

        return [
            'supported' => true,
            'provider_type' => $providerType,
            'missing_count' => $missing,
            'affected_workspace_ids' => $this->affectedWorkspaceIds($table, $whereSql),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function platformEmailDefaultsCredentialCount(): array
    {
        if (!$this->tableHasColumns('platform_email_defaults', ['scope', 'settings_json'])) {
            return [
                'supported' => false,
                'provider_type' => 'platform_email_defaults',
                'missing_count' => 0,
                'unsupported_reason' => 'missing_table_or_columns',
            ];
        }

        $missing = $this->safeCount(
            "SELECT COUNT(*) AS c
             FROM platform_email_defaults
             WHERE settings_json IS NULL
                OR CAST(settings_json AS CHAR) IN ('', '{}', '[]')"
        );

        return [
            'supported' => true,
            'provider_type' => 'platform_email_defaults',
            'missing_count' => $missing,
        ];
    }

    /**
     * @param array<int,string> $columns
     * @return array<string,mixed>
     */
    private function expiringCredentialCount(string $table, array $columns, string $providerType): array
    {
        if (!$this->tableHasColumns($table, $columns)) {
            return [
                'supported' => false,
                'provider_type' => $providerType,
                'expired_count' => 0,
                'expiring_count' => 0,
                'unsupported_reason' => 'missing_table_or_columns',
            ];
        }

        $activeWhere = '1 = 1';
        if (Database::columnExists($table, 'is_active')) {
            $activeWhere = 'is_active = 1';
        } elseif (Database::columnExists($table, 'sync_enabled')) {
            $activeWhere = 'sync_enabled = 1';
        } elseif (Database::columnExists($table, 'connection_status')) {
            $activeWhere = "connection_status IN ('connecting', 'connected', 'needs_attention')";
        }

        $expired = $this->safeCount(
            "SELECT COUNT(*) AS c
             FROM {$table}
             WHERE {$activeWhere}
               AND token_expires_at IS NOT NULL
               AND token_expires_at <= NOW()"
        );
        $expiring = $this->safeCount(
            "SELECT COUNT(*) AS c
             FROM {$table}
             WHERE {$activeWhere}
               AND token_expires_at IS NOT NULL
               AND token_expires_at > NOW()
               AND token_expires_at <= DATE_ADD(NOW(), INTERVAL " . self::EXPIRING_CREDENTIAL_DAYS . " DAY)"
        );

        return [
            'supported' => true,
            'provider_type' => $providerType,
            'expired_count' => $expired,
            'expiring_count' => $expiring,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function queueSnapshot(string $table, string $preferredTimeColumn): array
    {
        if (!$this->tableHasColumns($table, ['status'])) {
            return [
                'supported' => false,
                'pending_count' => 0,
                'stale_pending_count' => 0,
                'failed_count' => 0,
                'unsupported_reason' => 'missing_table_or_status_column',
            ];
        }

        $timeColumn = Database::columnExists($table, $preferredTimeColumn)
            ? $preferredTimeColumn
            : (Database::columnExists($table, 'created_at') ? 'created_at' : null);
        if ($timeColumn === null) {
            return [
                'supported' => false,
                'pending_count' => 0,
                'stale_pending_count' => 0,
                'failed_count' => 0,
                'unsupported_reason' => 'missing_time_column',
            ];
        }

        $pendingStatusSql = "status IN ('pending', 'processing', 'queued', 'running')";
        $pending = $this->safeCount("SELECT COUNT(*) AS c FROM {$table} WHERE {$pendingStatusSql}");
        $stale = $this->safeCount(
            "SELECT COUNT(*) AS c
             FROM {$table}
             WHERE {$pendingStatusSql}
               AND {$timeColumn} <= DATE_SUB(NOW(), INTERVAL " . self::QUEUE_STALE_HOURS . " HOUR)"
        );
        $failed = $this->safeCount("SELECT COUNT(*) AS c FROM {$table} WHERE status IN ('failed', 'error')");
        $oldest = $this->safeRows(
            "SELECT MIN({$timeColumn}) AS oldest_pending_at
             FROM {$table}
             WHERE {$pendingStatusSql}"
        );

        return [
            'supported' => true,
            'pending_count' => $pending,
            'stale_pending_count' => $stale,
            'failed_count' => $failed,
            'oldest_pending_at' => (string) ($oldest[0]['oldest_pending_at'] ?? ''),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function duplicateTemplateSnapshot(string $table, string $templateType): array
    {
        if (!Database::tableExists($table)) {
            return [
                'supported' => false,
                'template_type' => $templateType,
                'duplicate_groups' => 0,
                'unsupported_reason' => 'missing_table',
            ];
        }

        $keyColumn = Database::columnExists($table, 'template_key')
            ? 'template_key'
            : (Database::columnExists($table, 'slug') ? 'slug' : null);
        if ($keyColumn === null) {
            return [
                'supported' => false,
                'template_type' => $templateType,
                'duplicate_groups' => 0,
                'unsupported_reason' => 'missing_template_key_column',
            ];
        }

        $activeWhere = Database::columnExists($table, 'is_active') ? 'AND is_active = 1' : '';
        $workspaceSelect = Database::columnExists($table, 'workspace_id') ? 'workspace_id,' : 'NULL AS workspace_id,';
        $workspaceGroup = Database::columnExists($table, 'workspace_id') ? 'workspace_id,' : '';
        $rows = $this->safeRows(
            "SELECT {$workspaceSelect} {$keyColumn} AS template_key, COUNT(*) AS duplicate_count
             FROM {$table}
             WHERE {$keyColumn} IS NOT NULL
               AND {$keyColumn} <> ''
               {$activeWhere}
             GROUP BY {$workspaceGroup} {$keyColumn}
             HAVING COUNT(*) > 1
             ORDER BY duplicate_count DESC
             LIMIT 10"
        );

        return [
            'supported' => true,
            'template_type' => $templateType,
            'duplicate_groups' => count($rows),
            'samples' => $rows,
        ];
    }

    /**
     * @param array<int,string> $pendingStatuses
     * @return array<string,mixed>
     */
    private function marketplacePendingCount(string $table, string $statusColumn, array $pendingStatuses): array
    {
        if (!$this->tableHasColumns($table, [$statusColumn])) {
            return [
                'supported' => false,
                'pending_count' => 0,
                'unsupported_reason' => 'missing_table_or_status_column',
            ];
        }

        $quotedStatuses = implode(',', array_map(static fn(string $status): string => "'" . str_replace("'", "''", $status) . "'", $pendingStatuses));
        $count = $this->safeCount("SELECT COUNT(*) AS c FROM {$table} WHERE {$statusColumn} IN ({$quotedStatuses})");

        return [
            'supported' => true,
            'pending_count' => $count,
            'pending_statuses' => $pendingStatuses,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function marketplaceEventFailureCount(): array
    {
        if (!$this->tableHasColumns('workspace_marketplace_setup_journey_events', ['event_type'])) {
            return [
                'supported' => false,
                'failure_count' => 0,
                'unsupported_reason' => 'missing_table_or_event_column',
            ];
        }

        $failures = $this->safeCount(
            "SELECT COUNT(*) AS c
             FROM workspace_marketplace_setup_journey_events
             WHERE event_type IN ('step_reset')"
        );

        return [
            'supported' => true,
            'failure_count' => $failures,
        ];
    }

    /**
     * @param array<string,mixed> $evidence
     * @param array<string,mixed> $recommendation
     * @return array<string,mixed>
     */
    private function evaluation(
        string $automationKey,
        bool $finding,
        string $severity,
        array $evidence,
        array $recommendation,
        ?int $workspaceId = null
    ): array {
        return [
            'automation_key' => $automationKey,
            'workspace_id' => $workspaceId,
            'finding' => $finding,
            'severity' => $severity,
            'evidence' => $evidence,
            'recommendation' => $recommendation,
        ];
    }

    /**
     * @param array<string,mixed> $definition
     */
    private function definitionSkipReason(array $definition): string
    {
        if (!empty($definition['kill_switch_engaged'])) {
            return 'kill_switch_engaged';
        }
        if (!empty($definition['is_paused'])) {
            return 'paused';
        }
        if ((string) ($definition['effective_mode'] ?? '') === 'disabled') {
            return 'disabled';
        }
        return '';
    }

    private function runStatusForMode(string $mode, bool $hasFindingOrPreparedOutput): string
    {
        if (!$hasFindingOrPreparedOutput) {
            return 'observed';
        }

        return match ($mode) {
            'suggest' => 'suggested',
            'prepare' => 'prepared',
            'approval_required', 'auto' => 'approval_required',
            default => 'observed',
        };
    }

    /**
     * @param array<int,array<string,mixed>> $evaluations
     * @return array<string,int>
     */
    private function summarize(array $evaluations): array
    {
        $summary = [
            'evaluated' => count($evaluations),
            'findings' => 0,
            'critical' => 0,
            'warning' => 0,
            'info' => 0,
            'clear' => 0,
            'prepared_outputs' => 0,
        ];

        foreach ($evaluations as $evaluation) {
            if (!empty($evaluation['always_prepare'])) {
                $summary['prepared_outputs']++;
            }
            if (empty($evaluation['finding'])) {
                $summary['clear']++;
                continue;
            }

            $severity = (string) ($evaluation['severity'] ?? 'info');
            if (!array_key_exists($severity, $summary)) {
                $severity = 'info';
            }
            $summary[$severity]++;
            $summary['findings']++;
        }

        return $summary;
    }
}
