<?php

namespace CRM\Services;

use CRM\Authorization;
use CRM\CacheManager;
use CRM\Database;
use CRM\Modules\AIAutoResponderConfig;
use CRM\Modules\CommercialAutomationConfig;
use CRM\Modules\DealAutomationConfig;

class AutomationBatteryService
{
    private const CACHE_TTL_SECONDS = 300;
    private const CACHE_KEY_PREFIX = 'automation_battery_status:v4';
    private const SNAPSHOT_TTL_SECONDS = 600;
    private const SNAPSHOT_TABLE = 'automation_battery_snapshots';

    private AIOperatingContextService $operatingContext;
    private DealAutomationReadinessService $dealReadiness;
    private AutoAdminService $autoAdmin;
    private AutomationJobHealthService $jobHealth;
    private AIAutoResponderConfig $autoResponderConfig;
    private CommercialAutomationConfig $commercialConfig;
    private DealAutomationConfig $dealConfig;
    private AIAutonomyRolloutOperationsService $rolloutOps;
    private AIConfidenceCalibrationService $calibration;
    private AIAutomationIncidentService $incidentService;
    private SmartTemplateContextService $smartTemplateContext;
    private AIWorkspaceScopeService $workspaceScope;
    private CacheManager $cache;
    private string $fileCacheDirectory;
    private WorkspaceAutomationReadinessSettingsService $refreshSettings;
    /** @var array<int,array<string,mixed>> */
    private array $viewerUserCache = [];

    public function __construct(
        ?AIOperatingContextService $operatingContext = null,
        ?DealAutomationReadinessService $dealReadiness = null,
        ?AutoAdminService $autoAdmin = null,
        ?AutomationJobHealthService $jobHealth = null,
        ?AIAutoResponderConfig $autoResponderConfig = null,
        ?CommercialAutomationConfig $commercialConfig = null,
        ?DealAutomationConfig $dealConfig = null,
        ?AIAutonomyRolloutOperationsService $rolloutOps = null,
        ?AIConfidenceCalibrationService $calibration = null,
        ?AIAutomationIncidentService $incidentService = null,
        ?SmartTemplateContextService $smartTemplateContext = null,
        ?CacheManager $cache = null,
        ?string $fileCacheDirectory = null,
        ?WorkspaceAutomationReadinessSettingsService $refreshSettings = null
    ) {
        $this->operatingContext = $operatingContext ?? new AIOperatingContextService();
        $this->dealReadiness = $dealReadiness ?? new DealAutomationReadinessService();
        $this->autoAdmin = $autoAdmin ?? new AutoAdminService();
        $this->jobHealth = $jobHealth ?? new AutomationJobHealthService();
        $this->autoResponderConfig = $autoResponderConfig ?? new AIAutoResponderConfig();
        $this->commercialConfig = $commercialConfig ?? new CommercialAutomationConfig();
        $this->dealConfig = $dealConfig ?? new DealAutomationConfig();
        $this->rolloutOps = $rolloutOps ?? new AIAutonomyRolloutOperationsService();
        $this->calibration = $calibration ?? new AIConfidenceCalibrationService();
        $this->incidentService = $incidentService ?? new AIAutomationIncidentService();
        $this->smartTemplateContext = $smartTemplateContext ?? new SmartTemplateContextService();
        $this->workspaceScope = new AIWorkspaceScopeService();
        $this->cache = $cache ?? new CacheManager();
        $this->fileCacheDirectory = $fileCacheDirectory
            ?? ((defined('CACHE_PATH') ? CACHE_PATH : (__DIR__ . '/../cache')) . DIRECTORY_SEPARATOR . 'automation_battery');
        $this->refreshSettings = $refreshSettings ?? new WorkspaceAutomationReadinessSettingsService();
    }

    public function getStatus(int $viewerUserId, ?int $subjectUserId = null): array
    {
        $subjectUserId = $subjectUserId ?? $viewerUserId;
        $workspaceId = $this->workspaceScope->requireWorkspaceId();
        $cacheKey = $this->buildStatusCacheKey($workspaceId, $subjectUserId, $viewerUserId);
        $cachedPayload = $this->readCachedPayload($cacheKey);
        $cached = $this->readFreshStatusFromPayload($cachedPayload);

        if ($cached !== null) {
            return $this->finalizeStatusForViewer($cached, $viewerUserId);
        }

        $fingerprint = $this->buildStatusCacheFingerprint($workspaceId, $subjectUserId, $viewerUserId);
        if (is_array($cachedPayload) && (string) ($cachedPayload['fingerprint'] ?? '') === $fingerprint) {
            $cached = $this->readStatusFromPayload($cachedPayload);
            if ($cached !== null) {
                $cached = $this->finalizeStatusForViewer($cached, $viewerUserId);
                $this->writeCachedStatus($cacheKey, $fingerprint, $cached);
                $this->writeStoredStatus($workspaceId, $subjectUserId, $fingerprint, $cached, 'service');
                return $cached;
            }
        }

        $status = $this->buildFreshStatus($viewerUserId, $subjectUserId);
        $this->writeCachedStatus($cacheKey, $fingerprint, $status);
        $this->writeStoredStatus($workspaceId, $subjectUserId, $fingerprint, $status, 'service');

        return $status;
    }

    public function getStoredStatus(int $viewerUserId, ?int $subjectUserId = null): ?array
    {
        $subjectUserId = $subjectUserId ?? $viewerUserId;
        $workspaceId = $this->workspaceScope->requireWorkspaceId();
        $stored = $this->readStoredStatus($workspaceId, $subjectUserId);

        return $stored === null
            ? null
            : $this->withRefreshPolicyMetadata($this->finalizeStatusForViewer($stored, $viewerUserId), $this->refreshSettings->getPolicy($workspaceId));
    }

    public function getRefreshPolicy(int $viewerUserId, ?int $subjectUserId = null): array
    {
        return $this->refreshSettings->getPolicy($this->workspaceScope->requireWorkspaceId());
    }

    public function refreshStatus(int $viewerUserId, ?int $subjectUserId = null, string $source = 'manual'): array
    {
        $subjectUserId = $subjectUserId ?? $viewerUserId;
        $workspaceId = $this->workspaceScope->requireWorkspaceId();
        $source = $this->normalizeCalculationSource($source);
        $policy = $this->refreshSettings->getPolicy($workspaceId);
        $stored = $this->readStoredStatus($workspaceId, $subjectUserId);
        $viewerStored = $stored === null ? null : $this->finalizeStatusForViewer($stored, $viewerUserId);

        if ($source === 'idle') {
            if (!$this->refreshSettings->isAutomaticEnabled($policy)) {
                return $this->withRefreshPolicyMetadata(
                    $viewerStored ?? $this->buildPendingStatus($subjectUserId),
                    $policy,
                    true,
                    'manual_only'
                );
            }

            if ($stored !== null && !$this->isIdleRefreshDue($stored, $policy)) {
                return $this->withRefreshPolicyMetadata($viewerStored ?? $stored, $policy, true, 'not_due');
            }
        }

        if ($source === 'manual' && $stored !== null && $this->isManualRefreshThrottled($stored, $policy)) {
            return $this->withRefreshPolicyMetadata($viewerStored ?? $stored, $policy, true, 'manual_throttle');
        }

        $fingerprint = $this->buildStatusCacheFingerprint($workspaceId, $subjectUserId, $viewerUserId);
        $status = $this->buildFreshStatus($viewerUserId, $subjectUserId);
        $cacheKey = $this->buildStatusCacheKey($workspaceId, $subjectUserId, $viewerUserId);

        $this->writeCachedStatus($cacheKey, $fingerprint, $status);
        $this->writeStoredStatus($workspaceId, $subjectUserId, $fingerprint, $status, $source);

        $fresh = $this->readStoredStatus($workspaceId, $subjectUserId) ?? $this->withSnapshotMetadata($status, [
            'calculated_at' => date('Y-m-d H:i:s'),
            'expires_at' => date('Y-m-d H:i:s', time() + self::SNAPSHOT_TTL_SECONDS),
            'calculation_source' => $source,
        ]);

        return $this->withRefreshPolicyMetadata($this->finalizeStatusForViewer($fresh, $viewerUserId), $policy);
    }

    private function buildFreshStatus(int $viewerUserId, int $subjectUserId): array
    {
        $workspaceId = $this->workspaceScope->requireWorkspaceId();
        $context = $this->operatingContext->buildForUser($subjectUserId, ['surface' => 'dashboard']);
        $featureState = (array) ($context['feature_state'] ?? []);
        $dealState = (array) ($context['deal_automation_state'] ?? $this->dealReadiness->getState($subjectUserId, $workspaceId));
        $jobSummary = $this->jobHealth->getSummary();
        $autoAdminEnabled = $this->autoAdmin->isEnabledForWorkspace($workspaceId);
        $activeLayers = $this->getScoredLayerActivity();
        $userSetupReadiness = $this->getUserSetupReadiness($subjectUserId);

        $setupLayer = $this->buildSetupLayer($featureState, $userSetupReadiness, $activeLayers);
        $autoresponderLayer = ($activeLayers['autoresponder'] ?? false)
            ? $this->buildAutoresponderLayer($jobSummary)
            : $this->buildInactiveLayer('AI Auto Responder');
        $customerCareLayer = $this->buildCustomerCareLayer($subjectUserId);
        $commercialLayer = ($activeLayers['commercial'] ?? false)
            ? $this->buildCommercialLayer($jobSummary, $subjectUserId)
            : $this->buildInactiveLayer('Commercial Layer');
        $workflowAutomationLayer = $this->buildWorkflowAutomationLayer($subjectUserId);
        $dealLayer = ($activeLayers['deal'] ?? false)
            ? $this->buildDealLayer($dealState, $autoAdminEnabled)
            : $this->buildInactiveLayer('Deal Automation');
        $learningLayer = $this->buildLearningLayer($jobSummary, $subjectUserId);

        $layers = [
            'setup' => $setupLayer,
            'autoresponder' => $autoresponderLayer,
            'customer_care' => $customerCareLayer,
            'commercial' => $commercialLayer,
            'workflow_automation' => $workflowAutomationLayer,
            'deal' => $dealLayer,
            'learning' => $learningLayer,
        ];
        $layers = $this->withLayerDisplayMetadata($layers, $viewerUserId, $autoAdminEnabled);

        $rawScore = $this->computeWeightedScore([
            ['weight' => 0.28, 'layer' => $setupLayer],
            ['weight' => 0.12, 'layer' => $autoresponderLayer],
            ['weight' => 0.12, 'layer' => $customerCareLayer],
            ['weight' => 0.12, 'layer' => $commercialLayer],
            ['weight' => 0.12, 'layer' => $workflowAutomationLayer],
            ['weight' => 0.12, 'layer' => $dealLayer],
            ['weight' => 0.12, 'layer' => $learningLayer],
        ]);

        $score = $rawScore;
        if (!$setupLayer['is_complete']) {
            $score = min($score, 69);
        }
        if ($learningLayer['score'] < 55) {
            $score = min($score, 84);
        }
        if (($jobSummary['failed'] ?? 0) > 0 || ($jobSummary['stale'] ?? 0) >= 2) {
            $score = min($score, 89);
        }
        $score = max(5, min(100, $score));

        $bucket = $this->scoreBucket($score);
        $statusLabel = match ($bucket) {
            'full' => 'Full automation',
            'high' => 'Near full automation',
            'medium' => 'Building automation',
            default => 'Early automation',
        };

        $headlineLabel = $this->buildHeadlineLabel($setupLayer, [
            $autoresponderLayer,
            $customerCareLayer,
            $commercialLayer,
            $workflowAutomationLayer,
            $dealLayer,
            $learningLayer,
        ]);
        $summary = $this->buildSummary($setupLayer, [
            'Auto responder' => $autoresponderLayer,
            'Customer Care' => $customerCareLayer,
            'commercial' => $commercialLayer,
            'workflow automation' => $workflowAutomationLayer,
            'deal automation' => $dealLayer,
            'learning' => $learningLayer,
        ], $activeLayers);

        $legacyBlockers = [];
        foreach ($layers as $layer) {
            if (!$this->isLayerCounted($layer)) {
                continue;
            }
            foreach ((array) ($layer['blockers'] ?? []) as $blocker) {
                $legacyBlockers[] = (string) $blocker;
            }
        }
        if (($jobSummary['failed'] ?? 0) > 0) {
            $legacyBlockers[] = 'Resolve failed automation jobs';
        }
        if (($jobSummary['stale'] ?? 0) > 0) {
            $legacyBlockers[] = 'Restart stale scheduled workers';
        }
        $legacyBlockers = array_slice(array_values(array_unique(array_filter($legacyBlockers))), 0, 5);
        $jobActions = $this->buildJobActions($jobSummary, $viewerUserId);
        $jobProgressSignals = $this->buildJobProgressSignals($jobSummary);
        $topActions = $this->buildTopActions($layers, $jobActions);
        $topProgressSignals = $this->buildTopProgressSignals($layers, $jobProgressSignals);
        $contextEnrichments = $this->buildContextEnrichments($workspaceId, $userSetupReadiness);

        $boosters = [];
        foreach ($layers as $layer) {
            if (!$this->isLayerCounted($layer)) {
                continue;
            }
            foreach ((array) ($layer['signals'] ?? []) as $signal) {
                $boosters[] = (string) $signal;
            }
        }
        if ($autoAdminEnabled) {
            $boosters[] = 'Auto Admin enabled';
        }
        if (($jobSummary['failed'] ?? 0) === 0 && ($jobSummary['stale'] ?? 0) === 0) {
            $boosters[] = 'Automation jobs healthy';
        }
        $boosters = array_slice(array_values(array_unique(array_filter($boosters))), 0, 5);
        $statusVisible = $this->hasVisibleAutomationReadinessContent($layers, $topActions, $topProgressSignals);

        return $this->withOperationalPolishMetadata([
            'score' => $score,
            'bucket' => $bucket,
            'status_label' => $statusLabel,
            'headline_label' => $headlineLabel,
            'summary' => $summary,
            'top_blockers' => array_values(array_map(
                static fn(array $action): string => (string) ($action['source_blocker'] ?? $action['label'] ?? ''),
                array_slice($topActions, 0, 4)
            )),
            'top_boosters' => array_slice($boosters, 0, 4),
            'top_actions' => $topActions,
            'top_progress_signals' => $topProgressSignals,
            'context_enrichments' => $contextEnrichments,
            'is_visible' => $statusVisible,
            'legacy_blockers' => $legacyBlockers,
            'mode_label' => $headlineLabel,
            'subject_user_id' => $subjectUserId,
            'setup_progress_label' => sprintf('%d of %d foundations ready', (int) ($setupLayer['completed_count'] ?? 0), (int) ($setupLayer['total_count'] ?? 0)),
            'layers' => $layers,
            'job_health' => $jobSummary,
        ]);
    }

    /**
     * @param array<string,array<string,mixed>> $layers
     * @return array<string,array<string,mixed>>
     */
    private function withLayerDisplayMetadata(array $layers, int $viewerUserId, bool $autoAdminEnabled): array
    {
        foreach ($layers as $layerKey => $layer) {
            $actions = [];
            $progressSignals = [];
            foreach ((array) ($layer['blockers'] ?? []) as $blocker) {
                $blocker = (string) $blocker;
                $action = $this->actionForBlocker($blocker, (string) $layerKey, $viewerUserId, $layer, $autoAdminEnabled);
                if ($action !== null) {
                    $actions[] = $action;
                }

                $progressSignal = $this->progressSignalForBlocker($blocker, (string) $layerKey, $layer, $viewerUserId, $autoAdminEnabled);
                if ($progressSignal !== null) {
                    $progressSignals[] = $progressSignal;
                }
            }

            $layer['actions'] = array_slice($actions, 0, 3);
            $layer['progress_signals'] = array_slice($progressSignals, 0, 3);
            $layer = $this->withLayerVisibility($layer);
            $layers[$layerKey] = $layer;
        }

        return $layers;
    }

    /**
     * @param array<string,array<string,mixed>> $layers
     * @param array<int,array<string,mixed>> $jobActions
     * @return array<int,array<string,mixed>>
     */
    private function buildTopActions(array $layers, array $jobActions = []): array
    {
        $actions = [];
        foreach ($layers as $layer) {
            foreach ((array) ($layer['actions'] ?? []) as $action) {
                if (!is_array($action)) {
                    continue;
                }
                $key = (string) ($action['key'] ?? '');
                if ($key !== '' && !isset($actions[$key])) {
                    $actions[$key] = $action;
                }
            }
        }

        foreach ($jobActions as $action) {
            if (!is_array($action)) {
                continue;
            }
            $key = (string) ($action['key'] ?? '');
            if ($key !== '' && !isset($actions[$key])) {
                $actions[$key] = $action;
            }
        }

        return array_slice(array_values($actions), 0, 3);
    }

    /**
     * @param array<string,array<string,mixed>> $layers
     * @param array<int,array<string,mixed>> $jobProgressSignals
     * @return array<int,array<string,mixed>>
     */
    private function buildTopProgressSignals(array $layers, array $jobProgressSignals = []): array
    {
        $signals = [];
        foreach ($layers as $layer) {
            foreach ((array) ($layer['progress_signals'] ?? []) as $signal) {
                if (!is_array($signal)) {
                    continue;
                }
                $key = (string) ($signal['key'] ?? '');
                if ($key !== '' && !isset($signals[$key])) {
                    $signals[$key] = $signal;
                }
            }
        }
        foreach ($jobProgressSignals as $signal) {
            if (!is_array($signal)) {
                continue;
            }
            $key = (string) ($signal['key'] ?? '');
            if ($key !== '' && !isset($signals[$key])) {
                $signals[$key] = $signal;
            }
        }

        return array_slice(array_values($signals), 0, 4);
    }

    /**
     * @param array<string,mixed> $layer
     */
    private function actionForBlocker(string $blocker, string $layerKey, int $viewerUserId, array $layer = [], bool $autoAdminEnabled = false): ?array
    {
        $catalog = $this->automationActionCatalog();
        if (!isset($catalog[$blocker])) {
            return null;
        }

        $action = $catalog[$blocker];
        if (!$this->viewerCanUseAction($action, $viewerUserId)) {
            return null;
        }
        if ($autoAdminEnabled && !empty($action['suppress_when_auto_admin']) && $this->autoAdmin->isManagedTab((string) ($action['managed_tab'] ?? ''))) {
            return null;
        }

        if ((string) ($action['key'] ?? '') === 'setup.product_pricing') {
            $productsTotal = max(0, (int) ($layer['metadata']['products_total'] ?? 0));
            $pricedProducts = max(0, (int) ($layer['metadata']['priced_products'] ?? 0));
            $target = max(1, (int) ceil($productsTotal / 2));
            $action['label'] = sprintf(
                'Add unit prices to %d of %d active products',
                min($target, $pricedProducts),
                $productsTotal
            );
            $action['completion_signal'] = sprintf('priced_products >= %d of %d active products', $target, $productsTotal);
        }

        $action['layer_key'] = (string) ($action['layer_key'] ?? $layerKey);
        $action['source_blocker'] = $blocker;

        return $this->normalizeAction($action);
    }

    /**
     * @return array<string,array<string,string>>
     */
    private function automationActionCatalog(): array
    {
        return [
            'Complete Company profile' => $this->catalogAction('setup.company_profile', 'Complete company profile', 'required', 'settings.php?tab=company', 'Complete profile', 'Company profile has a business name and useful description.', 'setup', ['permission' => 'settings.company']),
            'Complete Product pricing' => $this->catalogAction('setup.product_pricing', 'Complete product pricing', 'required', 'settings.php?tab=products', 'Add pricing', 'At least half of active products have unit prices.', 'setup', ['permission' => 'settings.company']),
            'Complete Invoicing' => $this->catalogAction('setup.invoicing', 'Complete invoicing setup', 'required', 'settings.php?tab=invoicing', 'Set up invoices', 'Invoice settings are enabled with legal identity.', 'setup', ['permission' => 'settings.invoicing']),
            'Complete Workflow graph' => $this->catalogAction('setup.workflow_graph', 'Create a workflow graph', 'required', 'workflows.php', 'Open workflows', 'Workflow graph support is configured.', 'setup', ['permission' => 'workflows.manage']),
            'Complete Commercial automation' => $this->catalogAction('setup.commercial_automation', 'Review commercial automation setup', 'required', 'settings.php?tab=commercial_automation', 'Open settings', 'Commercial automation tables and controls are available.', 'setup', ['superadmin_only' => true, 'managed_tab' => 'commercial_automation', 'suppress_when_auto_admin' => true]),
            'Enable AI auto responder' => $this->catalogAction('autoresponder.enable', 'Enable AI auto responder', 'required', 'settings.php?tab=ai_autoresponder', 'Open responder settings', 'AI auto responder is enabled.', 'autoresponder', ['superadmin_only' => true, 'managed_tab' => 'ai_autoresponder', 'suppress_when_auto_admin' => true]),
            'Move auto responder beyond draft-only mode' => $this->catalogAction('autoresponder.mode', 'Move auto responder beyond draft-only', 'required', 'settings.php?tab=ai_autoresponder', 'Adjust mode', 'Auto responder mode is hybrid or full auto.', 'autoresponder', ['superadmin_only' => true, 'managed_tab' => 'ai_autoresponder', 'suppress_when_auto_admin' => true]),
            'Autoresponder confidence is still below threshold' => $this->catalogAction('autoresponder.confidence', 'Responder confidence is below threshold', 'recommended', '', 'Monitor confidence', 'Responder confidence meets the configured threshold.', 'autoresponder', ['actionable' => false]),
            'Reduce blocked auto-responder decisions' => $this->catalogAction('autoresponder.blocked_decisions', 'Blocked responder decisions need more evidence', 'recommended', '', 'Monitor decisions', 'Blocked responder decisions are reduced.', 'autoresponder', ['actionable' => false]),
            'Stabilize failed auto-responder sends' => $this->catalogAction('autoresponder.failed_sends', 'Stabilize responder sends', 'required', 'ai_automation_diagnostics.php?source=assistant', 'Open diagnostics', 'Recent responder send failures are cleared.', 'autoresponder', ['superadmin_only' => true]),
            'Add paying customers to Customer Care' => $this->catalogAction('customer_care.add_customers', 'No paying customer care evidence yet', 'recommended', '', 'Monitor Customer Care', 'Paying customer care profiles appear through normal customer activity.', 'customer_care', ['actionable' => false]),
            'Create at least one follow-up plan' => $this->catalogAction('customer_care.follow_up_plan', 'No active Customer Care plan yet', 'recommended', '', 'Monitor plans', 'At least one active Customer Care plan exists.', 'customer_care', ['actionable' => false]),
            'Complete more check-ins so the system can learn' => $this->catalogAction('customer_care.check_ins', 'Complete more Customer Care check-ins', 'recommended', 'nurture.php', 'Open Customer Care', 'Recent completed Customer Care check-ins are available.', 'customer_care', ['permission' => 'nurture.read']),
            'Resolve Customer Care automation incidents' => $this->catalogAction('customer_care.incidents', 'Resolve Customer Care incidents', 'required', 'ai_automation_diagnostics.php?source=customer_care', 'Open diagnostics', 'Open Customer Care automation incidents are resolved.', 'customer_care', ['superadmin_only' => true]),
            'Customer Care automation is paused' => $this->catalogAction('customer_care.resume', 'Customer Care automation is paused', 'required', 'ai_learning_review.php?domain=customer_care', 'Review learning', 'Customer Care automation is no longer paused.', 'customer_care', ['superadmin_only' => true]),
            'Commercial automation has not built enough recent run history' => $this->catalogAction('commercial.run_history', 'Commercial automation is still gathering run history', 'recommended', '', 'Monitor runs', 'Recent commercial automation runs exist.', 'commercial', ['actionable' => false]),
            'Reduce commercial approvals backlog' => $this->catalogAction('commercial.approvals_backlog', 'Commercial approvals backlog is still present', 'recommended', '', 'Monitor approvals', 'Pending commercial approvals are reduced.', 'commercial', ['actionable' => false]),
            'Commercial automation rollout is not fully ready' => $this->catalogAction('commercial.rollout', 'Review commercial automation rollout', 'required', 'settings.php?tab=commercial_automation', 'Open settings', 'Commercial automation rollout is ready.', 'commercial', ['superadmin_only' => true, 'managed_tab' => 'commercial_automation', 'suppress_when_auto_admin' => true]),
            'Commercial confidence is not strong enough for full auto' => $this->catalogAction('commercial.confidence', 'Commercial confidence is still building', 'recommended', '', 'Monitor confidence', 'Commercial precision meets the promotion threshold.', 'commercial', ['actionable' => false]),
            'Workflow automation has not built enough recent proposal history' => $this->catalogAction('workflow.proposal_history', 'Workflow automation is still gathering proposal history', 'recommended', '', 'Monitor proposals', 'Recent workflow automation proposals exist.', 'workflow_automation', ['actionable' => false]),
            'Reduce workflow automation approval backlog' => $this->catalogAction('workflow.approvals_backlog', 'Workflow approvals backlog is still present', 'recommended', '', 'Monitor approvals', 'Pending workflow approvals are reduced.', 'workflow_automation', ['actionable' => false]),
            'Workflow automation rollout is not fully ready' => $this->catalogAction('workflow.rollout', 'Review workflow automation rollout', 'required', 'settings.php?tab=workflow_automation', 'Open settings', 'Workflow automation rollout is ready.', 'workflow_automation', ['superadmin_only' => true, 'managed_tab' => 'workflow_automation', 'suppress_when_auto_admin' => true]),
            'Move workflow automation beyond suggest-only mode' => $this->catalogAction('workflow.mode', 'Move workflow automation beyond suggest-only', 'required', 'settings.php?tab=workflow_automation', 'Adjust mode', 'Workflow automation mode is auto-safe or full auto.', 'workflow_automation', ['superadmin_only' => true, 'managed_tab' => 'workflow_automation', 'suppress_when_auto_admin' => true]),
            'Deal automation is disabled.' => $this->catalogAction('deal.enable', 'Enable deal automation', 'required', 'settings.php?tab=deal_automation', 'Open deal settings', 'Deal automation is enabled.', 'deal', ['superadmin_only' => true, 'managed_tab' => 'deal_automation', 'suppress_when_auto_admin' => true]),
            'No transition rules are configured.' => $this->catalogAction('deal.transition_rules', 'Configure deal transition rules', 'required', 'settings.php?tab=deal_automation', 'Configure rules', 'Deal transition rules are configured.', 'deal', ['superadmin_only' => true, 'managed_tab' => 'deal_automation', 'suppress_when_auto_admin' => true]),
            'Dry run is still enabled.' => $this->catalogAction('deal.disable_dry_run', 'Disable deal automation dry run', 'required', 'settings.php?tab=deal_automation', 'Open deal settings', 'Deal automation dry run is disabled.', 'deal', ['superadmin_only' => true, 'managed_tab' => 'deal_automation', 'suppress_when_auto_admin' => true]),
            'Not enough recent automation audit history yet.' => $this->catalogAction('deal.audit_history', 'Deal automation is still gathering audit history', 'recommended', '', 'Monitor audits', 'At least five recent deal automation audits exist.', 'deal', ['actionable' => false]),
            'Recent audit history shows unstable automation outcomes.' => $this->catalogAction('deal.audit_stability', 'Deal automation outcomes are still stabilizing', 'recommended', '', 'Monitor audits', 'Recent deal automation outcomes are stable.', 'deal', ['actionable' => false]),
            'Terminal-stage safety settings are not strict enough.' => $this->catalogAction('deal.terminal_safety', 'Tighten terminal-stage safety', 'required', 'settings.php?tab=deal_automation', 'Open deal settings', 'Terminal-stage safety settings are strict enough.', 'deal', ['superadmin_only' => true, 'managed_tab' => 'deal_automation', 'suppress_when_auto_admin' => true]),
            'Learning history is still too thin' => $this->catalogAction('learning.history', 'Learning history is still thin', 'recommended', '', 'Monitor learning', 'Recent AI decision outcome history is available.', 'learning', ['actionable' => false]),
            'More accepted demonstrations and outcomes are needed' => $this->catalogAction('learning.demonstrations', 'Demonstrations are still building', 'recommended', '', 'Monitor demonstrations', 'Enough demonstrations are feeding the learning loop.', 'learning', ['actionable' => false]),
            'Confidence calibration job is not healthy' => $this->catalogAction('learning.calibration_job', 'Repair confidence calibration', 'required', 'ai_automation_diagnostics.php#calibration-section', 'Open diagnostics', 'Confidence calibration job is healthy.', 'learning', ['superadmin_only' => true]),
            'Active AI automation incidents are reducing trust' => $this->catalogAction('learning.incidents', 'Resolve active AI automation incidents', 'required', 'ai_automation_diagnostics.php', 'Open diagnostics', 'Active AI automation incidents are resolved.', 'learning', ['superadmin_only' => true]),
            'Confidence precision is not strong enough yet' => $this->catalogAction('learning.precision', 'Confidence precision is still building', 'recommended', '', 'Monitor precision', 'Confidence precision is strong enough for the current threshold.', 'learning', ['actionable' => false]),
            'Resolve failed automation jobs' => $this->catalogAction('jobs.failed', 'Resolve failed automation jobs', 'required', 'ai_automation_diagnostics.php?source=job', 'Open diagnostics', 'Failed automation jobs are cleared.', 'job_health', ['superadmin_only' => true]),
            'Restart stale scheduled workers' => $this->catalogAction('jobs.stale', 'Restart stale scheduled workers', 'required', 'ai_automation_diagnostics.php?source=job', 'Open diagnostics', 'Stale scheduled workers are running again.', 'job_health', ['superadmin_only' => true]),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function catalogAction(
        string $key,
        string $label,
        string $kind,
        string $href,
        string $ctaLabel,
        string $completionSignal,
        string $layerKey,
        array $options = []
    ): array {
        return array_merge([
            'key' => $key,
            'label' => $label,
            'kind' => $kind,
            'href' => $href,
            'cta_label' => $ctaLabel,
            'completion_signal' => $completionSignal,
            'layer_key' => $layerKey,
        ], $options);
    }

    /**
     * @param array<string,mixed> $action
     * @return array<string,mixed>
     */
    private function normalizeAction(array $action): array
    {
        $kind = (string) ($action['kind'] ?? 'recommended');
        if (!in_array($kind, ['required', 'recommended', 'optional_enrichment'], true)) {
            $kind = 'recommended';
        }

        return [
            'key' => (string) ($action['key'] ?? ''),
            'label' => (string) ($action['label'] ?? ''),
            'kind' => $kind,
            'layer_key' => (string) ($action['layer_key'] ?? ''),
            'href' => (string) ($action['href'] ?? ''),
            'cta_label' => (string) ($action['cta_label'] ?? 'Open'),
            'source_blocker' => (string) ($action['source_blocker'] ?? ''),
            'completion_signal' => (string) ($action['completion_signal'] ?? ''),
            'admin_only' => !empty($action['superadmin_only']),
        ];
    }

    /**
     * @param array<string,mixed> $action
     */
    private function viewerCanUseAction(array $action, int $viewerUserId): bool
    {
        if (array_key_exists('actionable', $action) && empty($action['actionable'])) {
            return false;
        }

        $viewer = $this->viewerUser($viewerUserId);
        if (!empty($action['superadmin_only'])) {
            return Authorization::isSuperAdmin($viewer);
        }

        $permission = trim((string) ($action['permission'] ?? ''));
        return $permission === '' || Authorization::can($permission, $viewer);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function viewerUser(int $viewerUserId): ?array
    {
        if ($viewerUserId <= 0) {
            return null;
        }
        if (array_key_exists($viewerUserId, $this->viewerUserCache)) {
            return $this->viewerUserCache[$viewerUserId];
        }

        $this->viewerUserCache[$viewerUserId] = Database::queryOne(
            'SELECT * FROM users WHERE id = ? LIMIT 1',
            [$viewerUserId]
        ) ?: [];

        return $this->viewerUserCache[$viewerUserId];
    }

    /**
     * @param array<string,mixed> $layer
     * @return array<string,mixed>|null
     */
    private function progressSignalForBlocker(string $blocker, string $layerKey, array $layer, int $viewerUserId, bool $autoAdminEnabled): ?array
    {
        $catalog = $this->automationActionCatalog();
        $catalogItem = $catalog[$blocker] ?? [];
        if ($autoAdminEnabled && !empty($catalogItem['suppress_when_auto_admin']) && $this->autoAdmin->isManagedTab((string) ($catalogItem['managed_tab'] ?? ''))) {
            return $this->normalizeProgressSignal([
                'key' => $layerKey . '.auto_admin_managed',
                'label' => 'Managed by Auto Admin',
                'layer_key' => $layerKey,
                'source_blocker' => $blocker,
                'completion_signal' => 'Auto Admin manages this automation setting.',
                'current_value' => 1,
                'target_value' => 1,
                'severity' => 'info',
            ]);
        }

        $metadata = (array) ($layer['metadata'] ?? []);
        $signal = match ($blocker) {
            'Add paying customers to Customer Care' => [
                'key' => 'customer_care.paying_customer_evidence',
                'label' => 'No paying customer care evidence yet. This will improve once paying customers/check-ins exist.',
                'current_value' => (int) ($metadata['profile_count'] ?? 0),
                'target_value' => 1,
                'severity' => 'info',
            ],
            'Create at least one follow-up plan' => [
                'key' => 'customer_care.follow_up_plan_progress',
                'label' => 'No active Customer Care plan yet.',
                'current_value' => (int) ($metadata['program_count'] ?? 0),
                'target_value' => 1,
                'severity' => 'info',
            ],
            'Commercial automation has not built enough recent run history' => [
                'key' => 'commercial.run_history_progress',
                'label' => 'Commercial automation has 0 recent runs; history will build as supervised work runs.',
                'current_value' => (int) ($metadata['recent_runs'] ?? 0),
                'target_value' => 1,
                'severity' => 'info',
            ],
            'Reduce commercial approvals backlog' => [
                'key' => 'commercial.approvals_backlog_progress',
                'label' => 'Commercial approvals are still waiting for review.',
                'current_value' => (int) ($metadata['pending_approvals'] ?? 0),
                'target_value' => 0,
                'severity' => 'warning',
            ],
            'Commercial confidence is not strong enough for full auto' => [
                'key' => 'commercial.confidence_progress',
                'label' => 'Commercial confidence is still building from transactional outcomes.',
                'current_value' => (int) round(((float) ($metadata['precision'] ?? 0.0)) * 100),
                'target_value' => 85,
                'severity' => 'info',
            ],
            'Workflow automation has not built enough recent proposal history' => [
                'key' => 'workflow.proposal_history_progress',
                'label' => 'Workflow automation has 0 recent proposals; history will build as governed workflow changes run.',
                'current_value' => (int) ($metadata['recent_total'] ?? 0),
                'target_value' => 1,
                'severity' => 'info',
            ],
            'Reduce workflow automation approval backlog' => [
                'key' => 'workflow.approvals_backlog_progress',
                'label' => 'Workflow approvals are still waiting for review.',
                'current_value' => (int) ($metadata['pending_count'] ?? 0),
                'target_value' => 0,
                'severity' => 'warning',
            ],
            'Not enough recent automation audit history yet.' => [
                'key' => 'deal.audit_history_progress',
                'label' => 'Deal automation audit history is still building.',
                'current_value' => (int) ($metadata['recent_total'] ?? 0),
                'target_value' => 5,
                'severity' => 'info',
            ],
            'Recent audit history shows unstable automation outcomes.' => [
                'key' => 'deal.audit_stability_progress',
                'label' => 'Deal automation outcomes are still stabilizing.',
                'current_value' => (int) ($metadata['recent_applied'] ?? 0),
                'target_value' => 5,
                'severity' => 'warning',
            ],
            'Learning history is still too thin' => [
                'key' => 'learning.history_progress',
                'label' => 'Learning history is still thin; transactional outcomes will build this signal.',
                'current_value' => (int) ($metadata['decision_outcome_count'] ?? 0),
                'target_value' => 15,
                'severity' => 'info',
            ],
            'More accepted demonstrations and outcomes are needed' => [
                'key' => 'learning.demonstration_progress',
                'label' => 'Demonstrations are still building as humans complete work.',
                'current_value' => (int) ($metadata['demonstration_count'] ?? 0),
                'target_value' => 10,
                'severity' => 'info',
            ],
            'Confidence precision is not strong enough yet' => [
                'key' => 'learning.precision_progress',
                'label' => 'Confidence precision is still building from measured outcomes.',
                'current_value' => (int) ($metadata['quality_score'] ?? 0),
                'target_value' => 80,
                'severity' => 'info',
            ],
            'Autoresponder confidence is still below threshold' => [
                'key' => 'autoresponder.confidence_progress',
                'label' => 'Responder confidence is still below the send threshold.',
                'current_value' => (int) round(((float) ($metadata['confidence'] ?? 0.0)) * 100),
                'target_value' => (int) round(((float) ($metadata['threshold'] ?? 0.85)) * 100),
                'severity' => 'info',
            ],
            'Reduce blocked auto-responder decisions' => [
                'key' => 'autoresponder.blocked_decisions_progress',
                'label' => 'Blocked responder decisions will reduce as safer patterns are learned.',
                'current_value' => (int) ($metadata['blocked_count'] ?? 0),
                'target_value' => 0,
                'severity' => 'warning',
            ],
            default => null,
        };

        if ($signal === null && $catalogItem !== [] && !$this->viewerCanUseAction($catalogItem, $viewerUserId)) {
            $signal = $this->readOnlyProgressSignalFromCatalog($blocker, $layerKey, $layer, $catalogItem);
        }

        if ($signal === null) {
            return null;
        }

        $signal['layer_key'] = $layerKey;
        $signal['source_blocker'] = $blocker;
        $signal['completion_signal'] = (string) ($catalogItem['completion_signal'] ?? '');

        return $this->normalizeProgressSignal($signal);
    }

    /**
     * @param array<string,mixed> $layer
     * @param array<string,mixed> $catalogItem
     * @return array<string,mixed>
     */
    private function readOnlyProgressSignalFromCatalog(string $blocker, string $layerKey, array $layer, array $catalogItem): array
    {
        $metadata = (array) ($layer['metadata'] ?? []);
        $key = (string) ($catalogItem['key'] ?? preg_replace('/[^a-z0-9]+/i', '.', strtolower($blocker)));
        $severity = !empty($catalogItem['superadmin_only']) ? 'warning' : 'info';
        $currentValue = 0;
        $targetValue = 1;
        $label = (string) ($catalogItem['label'] ?? $blocker);

        if ($key === 'setup.product_pricing') {
            $productsTotal = max(0, (int) ($metadata['products_total'] ?? 0));
            $pricedProducts = max(0, (int) ($metadata['priced_products'] ?? 0));
            $targetValue = max(1, (int) ceil($productsTotal / 2));
            $currentValue = min($productsTotal, $pricedProducts);
            $label = sprintf('%d of %d active products have unit prices.', $currentValue, $productsTotal);
        } elseif (!empty($catalogItem['superadmin_only'])) {
            $label = 'Admin-managed readiness: ' . lcfirst($label) . '.';
        } else {
            $label = rtrim($label, '.') . ' is still incomplete.';
        }

        return [
            'key' => $key . '.progress',
            'label' => $label,
            'layer_key' => $layerKey,
            'source_blocker' => $blocker,
            'completion_signal' => (string) ($catalogItem['completion_signal'] ?? ''),
            'current_value' => $currentValue,
            'target_value' => $targetValue,
            'severity' => $severity,
        ];
    }

    /**
     * @param array<string,mixed> $signal
     * @return array<string,mixed>
     */
    private function normalizeProgressSignal(array $signal): array
    {
        $severity = (string) ($signal['severity'] ?? 'info');
        if (!in_array($severity, ['info', 'warning', 'critical'], true)) {
            $severity = 'info';
        }

        return [
            'key' => (string) ($signal['key'] ?? ''),
            'label' => (string) ($signal['label'] ?? ''),
            'layer_key' => (string) ($signal['layer_key'] ?? ''),
            'source_blocker' => (string) ($signal['source_blocker'] ?? ''),
            'completion_signal' => (string) ($signal['completion_signal'] ?? ''),
            'current_value' => (int) ($signal['current_value'] ?? 0),
            'target_value' => (int) ($signal['target_value'] ?? 0),
            'severity' => $severity,
        ];
    }

    /**
     * @param array<string,mixed> $layer
     * @return array<string,mixed>
     */
    private function withLayerVisibility(array $layer): array
    {
        if (!$this->isLayerCounted($layer)) {
            $layer['is_visible'] = false;
            $layer['display_mode'] = 'hidden';
            $layer['visibility_reason'] = 'not_counted';
            return $layer;
        }

        $hasActions = !empty($layer['actions']);
        $hasProgress = !empty($layer['progress_signals']);
        if (!$hasActions && !$hasProgress) {
            $layer['is_visible'] = false;
            $layer['display_mode'] = 'hidden';
            $layer['visibility_reason'] = 'fulfilled';
            return $layer;
        }

        $hasAdminOnly = false;
        foreach ((array) ($layer['actions'] ?? []) as $action) {
            if (is_array($action) && !empty($action['admin_only'])) {
                $hasAdminOnly = true;
                break;
            }
        }

        $layer['is_visible'] = true;
        $layer['display_mode'] = $hasActions ? ($hasAdminOnly ? 'admin_only' : 'actionable') : 'progress_only';
        $layer['visibility_reason'] = $hasActions ? 'has_actions' : 'has_progress';

        return $layer;
    }

    /**
     * @param array<string,mixed> $jobSummary
     * @return array<int,array<string,mixed>>
     */
    private function buildJobActions(array $jobSummary, int $viewerUserId): array
    {
        $actions = [];
        if (($jobSummary['failed'] ?? 0) > 0) {
            $action = $this->actionForBlocker('Resolve failed automation jobs', 'job_health', $viewerUserId);
            if ($action !== null) {
                $actions[] = $action;
            }
        }
        if (($jobSummary['stale'] ?? 0) > 0) {
            $action = $this->actionForBlocker('Restart stale scheduled workers', 'job_health', $viewerUserId);
            if ($action !== null) {
                $actions[] = $action;
            }
        }

        return $actions;
    }

    /**
     * @param array<string,mixed> $jobSummary
     * @return array<int,array<string,mixed>>
     */
    private function buildJobProgressSignals(array $jobSummary): array
    {
        $signals = [];
        if (($jobSummary['failed'] ?? 0) > 0) {
            $signals[] = $this->normalizeProgressSignal([
                'key' => 'jobs.failed_progress',
                'label' => 'Automation jobs need superadmin repair.',
                'layer_key' => 'job_health',
                'source_blocker' => 'Resolve failed automation jobs',
                'completion_signal' => 'Failed automation jobs are cleared.',
                'current_value' => (int) ($jobSummary['failed'] ?? 0),
                'target_value' => 0,
                'severity' => 'critical',
            ]);
        }
        if (($jobSummary['stale'] ?? 0) > 0) {
            $signals[] = $this->normalizeProgressSignal([
                'key' => 'jobs.stale_progress',
                'label' => 'Scheduled workers need superadmin attention.',
                'layer_key' => 'job_health',
                'source_blocker' => 'Restart stale scheduled workers',
                'completion_signal' => 'Stale scheduled workers are running again.',
                'current_value' => (int) ($jobSummary['stale'] ?? 0),
                'target_value' => 0,
                'severity' => 'critical',
            ]);
        }

        return $signals;
    }

    /**
     * @param array<string,array<string,mixed>> $layers
     * @param array<int,array<string,mixed>> $topActions
     * @param array<int,array<string,mixed>> $topProgressSignals
     */
    private function hasVisibleAutomationReadinessContent(array $layers, array $topActions, array $topProgressSignals): bool
    {
        if ($topActions !== [] || $topProgressSignals !== []) {
            return true;
        }
        foreach ($layers as $layer) {
            if (!empty($layer['is_visible'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $userSetupReadiness
     * @return array<int,array<string,mixed>>
     */
    private function buildContextEnrichments(int $workspaceId, array $userSetupReadiness): array
    {
        $needsContext = empty($userSetupReadiness['strategy_profile_ready'])
            || empty($userSetupReadiness['idea_validation_ready']);
        if (!$needsContext || $workspaceId <= 0) {
            return [];
        }

        $actions = [];
        try {
            $aiCoach = new AICoachWorkspaceSetupService();
            if ($aiCoach->isWorkspaceEnabled($workspaceId)) {
                $actions[] = $this->normalizeAction([
                    'key' => 'ai_context.ai_coach',
                    'label' => 'Improve AI Coach context',
                    'kind' => 'optional_enrichment',
                    'layer_key' => 'setup',
                    'href' => 'dashboard.php#ai-coach',
                    'cta_label' => 'Open AI Coach',
                    'source_blocker' => 'Optional AI Coach context enrichment',
                    'completion_signal' => 'AI Coach has enough personal strategy context for stronger recommendations.',
                ]);
            }
        } catch (\Throwable $e) {
            // Optional enrichment should never block automation readiness.
        }

        try {
            $installer = new WorkspaceSkillInstallService();
            if ($installer->isInstalled($workspaceId, WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS)) {
                $actions[] = $this->normalizeAction([
                    'key' => 'ai_context.clarity_journey',
                    'label' => 'Enrich strategy context in Clarity Journey',
                    'kind' => 'optional_enrichment',
                    'layer_key' => 'setup',
                    'href' => 'startup_journey.php',
                    'cta_label' => 'Open Journey',
                    'source_blocker' => 'Optional Clarity Journey context enrichment',
                    'completion_signal' => 'Clarity Journey has current strategy context for AI guidance.',
                ]);
            }
        } catch (\Throwable $e) {
            // Optional enrichment should never block automation readiness.
        }

        return array_slice($actions, 0, 2);
    }

    private function buildStatusCacheKey(int $workspaceId, int $subjectUserId, int $viewerUserId): string
    {
        return sprintf('%s:workspace:%d:subject:%d:viewer:%d', self::CACHE_KEY_PREFIX, $workspaceId, $subjectUserId, $viewerUserId);
    }

    private function buildStatusCacheFingerprint(int $workspaceId, int $subjectUserId, int $viewerUserId): string
    {
        $parts = [
            'version' => self::CACHE_KEY_PREFIX,
            'workspace_id' => $workspaceId,
            'subject_user_id' => $subjectUserId,
            'viewer' => $this->viewerAutomationReadinessFingerprint($viewerUserId),
            'time_bucket' => (int) floor(time() / self::CACHE_TTL_SECONDS),
            'schema' => [
                'workflows' => [
                    'exists' => $this->tableExists('workflows'),
                    'graph_json' => $this->columnExists('workflows', 'graph_json'),
                ],
                'commercial_automation_config' => $this->tableExists('commercial_automation_config'),
                'workspace_auto_admin_settings' => $this->tableExists('workspace_auto_admin_settings'),
                'workspace_automation_config' => [
                    'ai_autoresponder' => $this->tableExists('workspace_ai_autoresponder_config'),
                    'commercial' => $this->tableExists('workspace_commercial_automation_config'),
                    'deal' => $this->tableExists('workspace_deal_automation_config'),
                ],
            ],
            'setup' => [
                'company_profile' => $this->tableSignature('company_profile', ['updated_at', 'created_at'], '', [], [
                    'company_name',
                    'company_legal_name',
                    'company_tax_id',
                    'company_description',
                    'company_mission',
                    'owner_company_context',
                    'company_email',
                    'company_phone',
                    'company_address',
                    'company_logo_url',
                    'company_industry',
                    'icp_industries',
                    'icp_pain_points',
                    'is_active',
                ]),
                'invoice_settings' => $this->tableSignature('invoice_settings', ['updated_at', 'created_at'], '', [], [
                    'enabled',
                    'company_legal_name',
                    'config_json',
                ]),
                'products' => $this->scopedTableSignature('products', $workspaceId, ['updated_at', 'created_at'], [
                    'name',
                    'description',
                    'category',
                    'unit_price',
                    'is_active',
                ]),
                'strategy_profile' => $this->tableSignature('user_strategy_profiles', ['updated_at', 'created_at'], 'user_id = ?', [$subjectUserId], [
                    'target_market_focus',
                    'ideal_customer_profile',
                    'offer_angle',
                    'sales_motion',
                    'segment_focus',
                    'deal_movement_strategy',
                    'outreach_posture',
                    'positioning_notes',
                ]),
                'idea_validation' => $this->tableSignature('idea_validation_context', ['updated_at', 'created_at'], 'user_id = ?', [$subjectUserId], [
                    'value_proposition',
                    'target_market',
                    'pain_points',
                    'assumptions_to_test',
                    'competitors',
                    'differentiator',
                ]),
            ],
            'config' => [
                'ai_autoresponder_config' => $this->tableSignature('ai_autoresponder_config', ['updated_at', 'created_at'], '', [], [
                    'enabled',
                    'mode',
                    'default_confidence_threshold',
                    'config_json',
                ]),
                'commercial_automation_config' => $this->tableSignature('commercial_automation_config', ['updated_at', 'created_at'], '', [], [
                    'enabled',
                    'mode',
                    'config_json',
                ]),
                'deal_automation_config' => $this->tableSignature('deal_automation_config', ['updated_at', 'created_at'], '', [], [
                    'enabled',
                    'mode',
                    'min_confidence',
                    'config_json',
                    'schema_version',
                ]),
                'workspace_auto_admin_settings' => $this->strictScopedTableSignature('workspace_auto_admin_settings', $workspaceId, ['updated_at', 'last_evaluated_at', 'last_applied_at'], [
                    'enabled',
                    'manual_freeze',
                    'managed_defaults_version',
                    'effective_modes_json',
                    'readiness_snapshot_json',
                ]),
                'workspace_ai_autoresponder_config' => $this->strictScopedTableSignature('workspace_ai_autoresponder_config', $workspaceId, ['updated_at', 'created_at'], [
                    'enabled',
                    'mode',
                    'default_confidence_threshold',
                    'config_json',
                ]),
                'workspace_commercial_automation_config' => $this->strictScopedTableSignature('workspace_commercial_automation_config', $workspaceId, ['updated_at', 'created_at'], [
                    'enabled',
                    'mode',
                    'auto_send_enabled',
                    'config_json',
                ]),
                'workspace_deal_automation_config' => $this->strictScopedTableSignature('workspace_deal_automation_config', $workspaceId, ['updated_at', 'created_at'], [
                    'enabled',
                    'mode',
                    'min_confidence',
                    'config_json',
                    'schema_version',
                ]),
                'auto_admin_preferences' => $this->tableSignature('user_preferences', ['updated_at', 'created_at'], 'user_id = ?', [AutoAdminService::GLOBAL_USER_ID], [
                    'preference_key',
                    'preference_value',
                ]),
                'autonomy_controls' => $this->scopedTableSignature('ai_autonomy_domain_controls', $workspaceId, ['updated_at', 'created_at'], [
                    'tenant_key',
                    'domain_key',
                    'autonomy_mode',
                    'promotion_status',
                    'metadata_json',
                ]),
            ],
            'evidence' => [
                'ai_autoresponder_logs' => $this->strictScopedTableSignature('ai_autoresponder_logs', $workspaceId, ['updated_at', 'created_at'], [
                    'decision',
                    'status',
                    'confidence',
                ]),
                'commercial_automation_runs' => $this->scopedTableSignature('commercial_automation_runs', $workspaceId, ['updated_at', 'created_at'], [
                    'decision',
                    'deal_id',
                    'contact_id',
                ]),
                'commercial_automation_approvals' => $this->scopedTableSignature('commercial_automation_approvals', $workspaceId, ['updated_at', 'created_at'], [
                    'status',
                    'deal_id',
                    'invoice_id',
                ]),
                'workflow_automation_proposals' => $this->scopedSubjectTableSignature('workflow_automation_proposals', $workspaceId, $subjectUserId, 'requested_by_id', ['updated_at', 'created_at'], [
                    'status',
                    'decision_mode',
                    'governance_decision',
                    'confidence_score',
                ]),
                'deal_automation_audit' => $this->subjectOwnershipEvidenceSignature('deal_automation_audit', $workspaceId, $subjectUserId, ['updated_at', 'created_at'], [
                    'decision',
                    'applied',
                    'reason',
                    'deal_id',
                    'contact_id',
                ]),
                'ai_decision_outcomes' => $this->scopedSubjectTableSignature('ai_decision_outcomes', $workspaceId, $subjectUserId, 'user_id', ['updated_at', 'measured_at', 'created_at'], [
                    'surface',
                    'decision_type',
                    'action_type',
                    'outcome_label',
                    'outcome_score',
                    'predicted_confidence',
                ]),
                'ai_operator_demonstrations' => $this->scopedSubjectTableSignature('ai_operator_demonstrations', $workspaceId, $subjectUserId, 'actor_user_id', ['updated_at', 'created_at', 'observed_at'], [
                    'domain_key',
                    'source_surface',
                    'entity_type',
                    'entity_id',
                    'action_key',
                    'demonstration_hash',
                ]),
                'nurture_profiles' => $this->scopedSubjectTableSignature('nurture_profiles', $workspaceId, $subjectUserId, 'owner_user_id', ['updated_at', 'created_at', 'last_touch_at', 'next_touch_at'], [
                    'contact_id',
                    'lifecycle_lane',
                    'nurture_status',
                    'cadence',
                    'health_score',
                ]),
                'nurture_touchpoints' => $this->scopedSubjectTableSignature('nurture_touchpoints', $workspaceId, $subjectUserId, 'created_by', ['updated_at', 'created_at', 'completed_at', 'scheduled_at'], [
                    'contact_id',
                    'touch_type',
                    'status',
                    'channel',
                ]),
                'nurture_programs' => $this->scopedTableSignature('nurture_programs', $workspaceId, ['updated_at', 'created_at'], [
                    'name',
                    'program_type',
                    'status',
                    'cadence',
                ]),
                'nurture_enrollments' => $this->scopedTableSignature('nurture_enrollments', $workspaceId, ['updated_at', 'created_at', 'completed_at', 'next_touch_at'], [
                    'program_id',
                    'contact_id',
                    'status',
                ]),
                'contacts' => $this->scopedSubjectTableSignature('contacts', $workspaceId, $subjectUserId, 'assigned_to', ['updated_at', 'created_at'], [
                    'assigned_to',
                    'first_name',
                    'last_name',
                    'email',
                ]),
                'deals' => $this->scopedSubjectTableSignature('deals', $workspaceId, $subjectUserId, 'assigned_to', ['updated_at', 'created_at'], [
                    'assigned_to',
                    'stage',
                    'value',
                    'contact_id',
                ]),
            ],
            'health' => [
                'automation_job_health' => $this->tableSignature('automation_job_health', ['updated_at', 'last_run_at', 'last_success_at', 'last_failure_at'], '', [], [
                    'job_key',
                    'status',
                    'last_message',
                    'metadata_json',
                ]),
                'ai_incident_state' => $this->scopedTableSignature('ai_incident_state', $workspaceId, ['updated_at', 'last_detected_at', 'last_resolved_at', 'created_at'], [
                    'incident_key',
                    'status',
                    'severity',
                    'metadata_json',
                ]),
                'ai_autonomy_incidents' => $this->scopedTableSignature('ai_autonomy_incidents', $workspaceId, ['updated_at', 'created_at', 'resolved_at'], [
                    'domain_key',
                    'action_key',
                    'incident_key',
                    'severity',
                    'status',
                ]),
            ],
        ];

        return hash('sha256', json_encode($parts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array<string,mixed>
     */
    private function viewerAutomationReadinessFingerprint(int $viewerUserId): array
    {
        $viewer = $this->viewerUser($viewerUserId);
        return [
            'user_id' => $viewerUserId,
            'is_superadmin' => Authorization::isSuperAdmin($viewer),
            'settings_company' => Authorization::can('settings.company', $viewer),
            'settings_invoicing' => Authorization::can('settings.invoicing', $viewer),
            'workflows_manage' => Authorization::can('workflows.manage', $viewer),
            'nurture_read' => Authorization::can('nurture.read', $viewer),
        ];
    }

    private function readCachedPayload(string $cacheKey): ?array
    {
        $cached = $this->cache->get($cacheKey);
        if (!is_array($cached)) {
            $cached = $this->readFileCache($cacheKey);
        }

        return is_array($cached) ? $cached : null;
    }

    private function readFreshStatusFromPayload(?array $cached): ?array
    {
        if (!is_array($cached)) {
            return null;
        }

        $expiresAt = strtotime((string) ($cached['expires_at'] ?? ''));
        if ($expiresAt === false || $expiresAt <= time()) {
            return null;
        }

        return $this->readStatusFromPayload($cached);
    }

    private function readStatusFromPayload(array $cached): ?array
    {
        $status = $cached['status'] ?? null;
        return is_array($status) ? $status : null;
    }

    private function writeCachedStatus(string $cacheKey, string $fingerprint, array $status): void
    {
        $payload = [
            'fingerprint' => $fingerprint,
            'status' => $status,
            'cached_at' => gmdate('c'),
            'expires_at' => gmdate('c', time() + self::CACHE_TTL_SECONDS),
        ];

        $this->cache->set($cacheKey, $payload, self::CACHE_TTL_SECONDS);
        $this->writeFileCache($cacheKey, $payload);
    }

    private function readStoredStatus(int $workspaceId, int $subjectUserId): ?array
    {
        if (!$this->tableExists(self::SNAPSHOT_TABLE)) {
            return null;
        }

        $row = Database::queryOne(
            "SELECT score, bucket, status_label, headline_label, summary, payload_json,
                    fingerprint, calculation_source, calculated_at, expires_at
             FROM automation_battery_snapshots
             WHERE workspace_id = ?
               AND subject_user_id = ?
             LIMIT 1",
            [$workspaceId, $subjectUserId]
        );
        if ($row === null) {
            return null;
        }

        $payload = json_decode((string) ($row['payload_json'] ?? ''), true);
        $status = is_array($payload) ? $payload : [];
        $status['score'] = (int) ($status['score'] ?? $row['score'] ?? 0);
        $status['bucket'] = (string) ($status['bucket'] ?? $row['bucket'] ?? 'low');
        $status['status_label'] = (string) ($status['status_label'] ?? $row['status_label'] ?? 'Checked');
        $status['headline_label'] = (string) ($status['headline_label'] ?? $row['headline_label'] ?? '');
        $status['mode_label'] = (string) ($status['mode_label'] ?? $status['headline_label']);
        $status['summary'] = (string) ($status['summary'] ?? $row['summary'] ?? '');

        return $this->withSnapshotMetadata($status, $row);
    }

    private function writeStoredStatus(int $workspaceId, int $subjectUserId, string $fingerprint, array $status, string $source): void
    {
        if (!$this->tableExists(self::SNAPSHOT_TABLE)) {
            return;
        }

        $encoded = json_encode($status, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded) || $encoded === '') {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $expiresAt = date('Y-m-d H:i:s', time() + self::SNAPSHOT_TTL_SECONDS);

        Database::execute(
            "INSERT INTO automation_battery_snapshots
                (workspace_id, subject_user_id, score, bucket, status_label, headline_label, summary,
                 payload_json, fingerprint, calculation_source, calculated_at, expires_at, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                score = VALUES(score),
                bucket = VALUES(bucket),
                status_label = VALUES(status_label),
                headline_label = VALUES(headline_label),
                summary = VALUES(summary),
                payload_json = VALUES(payload_json),
                fingerprint = VALUES(fingerprint),
                calculation_source = VALUES(calculation_source),
                calculated_at = VALUES(calculated_at),
                expires_at = VALUES(expires_at),
                updated_at = NOW()",
            [
                $workspaceId,
                $subjectUserId,
                max(0, min(100, (int) ($status['score'] ?? 0))),
                (string) ($status['bucket'] ?? 'low'),
                (string) ($status['status_label'] ?? 'Checked'),
                (string) ($status['headline_label'] ?? $status['mode_label'] ?? ''),
                (string) ($status['summary'] ?? ''),
                $encoded,
                $fingerprint,
                $source,
                $now,
                $expiresAt,
            ]
        );
    }

    private function withSnapshotMetadata(array $status, array $row): array
    {
        $calculatedAt = (string) ($row['calculated_at'] ?? '');
        $expiresAt = (string) ($row['expires_at'] ?? '');
        $expiresTimestamp = strtotime($expiresAt);

        $status['snapshot_calculated_at'] = $calculatedAt;
        $status['snapshot_expires_at'] = $expiresAt;
        $status['snapshot_source'] = (string) ($row['calculation_source'] ?? '');
        $status['snapshot_fingerprint'] = (string) ($row['fingerprint'] ?? '');
        $status['is_stale'] = $expiresTimestamp === false || $expiresTimestamp <= time();
        $status['updated_label'] = $this->formatSnapshotUpdatedLabel($calculatedAt);

        return $status;
    }

    private function withRefreshPolicyMetadata(array $status, array $policy, bool $refreshSkipped = false, string $skipReason = ''): array
    {
        $calculatedAt = (string) ($status['snapshot_calculated_at'] ?? '');
        $calculatedTimestamp = $this->snapshotCalculatedTimestamp($status);
        $ageSeconds = $calculatedTimestamp === null ? null : max(0, time() - $calculatedTimestamp);

        $status['last_calculated_at'] = $calculatedAt;
        $status['age_seconds'] = $ageSeconds;
        $status['refresh_policy'] = $policy;
        $status['refresh_skipped'] = $refreshSkipped;
        if ($skipReason !== '') {
            $status['refresh_skip_reason'] = $skipReason;
        } else {
            unset($status['refresh_skip_reason']);
        }
        $status['refresh_due_at'] = $this->refreshDueAt($status, $policy);
        $status['idle_refresh_due'] = $this->isIdleRefreshDue($calculatedTimestamp === null ? null : $status, $policy);

        return $this->withOperationalPolishMetadata($status);
    }

    private function finalizeStatusForViewer(array $status, int $viewerUserId): array
    {
        $legacyTopBlockers = array_values(array_filter(array_map('strval', (array) ($status['top_blockers'] ?? []))));
        $layers = [];
        foreach ((array) ($status['layers'] ?? []) as $layerKey => $layer) {
            if (is_array($layer)) {
                $layers[(string) $layerKey] = $layer;
            }
        }

        $autoAdminEnabled = false;
        try {
            $autoAdminEnabled = $this->autoAdmin->isEnabledForWorkspace($this->workspaceScope->requireWorkspaceId());
        } catch (\Throwable $e) {
            $autoAdminEnabled = false;
        }

        $layers = $this->withLayerDisplayMetadata($layers, $viewerUserId, $autoAdminEnabled);
        $jobHealth = is_array($status['job_health'] ?? null) ? (array) $status['job_health'] : [];
        $topActions = $this->buildTopActions($layers, $this->buildJobActions($jobHealth, $viewerUserId));
        $topProgressSignals = $this->buildTopProgressSignals($layers, $this->buildJobProgressSignals($jobHealth));
        if ($topProgressSignals === [] && $legacyTopBlockers !== []) {
            foreach (array_slice($legacyTopBlockers, 0, 4) as $index => $blocker) {
                $topProgressSignals[] = $this->normalizeProgressSignal([
                    'key' => 'legacy.top_blocker.' . $index,
                    'label' => $blocker,
                    'layer_key' => '',
                    'source_blocker' => $blocker,
                    'completion_signal' => '',
                    'current_value' => 0,
                    'target_value' => 1,
                    'severity' => 'info',
                ]);
            }
        }

        $status['layers'] = $layers;
        $status['top_actions'] = $topActions;
        $status['top_progress_signals'] = $topProgressSignals;
        $status['context_enrichments'] = array_values(array_filter(
            (array) ($status['context_enrichments'] ?? []),
            'is_array'
        ));
        $status['top_blockers'] = array_values(array_map(
            static fn(array $action): string => (string) ($action['source_blocker'] ?? $action['label'] ?? ''),
            array_slice($topActions, 0, 4)
        ));
        $status['top_boosters'] = array_values(array_map('strval', (array) ($status['top_boosters'] ?? [])));
        if ((string) ($status['bucket'] ?? '') === 'pending') {
            $status['is_visible'] = true;
        } else {
            $status['is_visible'] = $this->hasVisibleAutomationReadinessContent($layers, $topActions, $topProgressSignals);
        }

        return $this->withOperationalPolishMetadata($status);
    }

    /**
     * @param array<string,mixed> $status
     * @return array<string,mixed>
     */
    private function withOperationalPolishMetadata(array $status): array
    {
        $topActions = array_values(array_filter((array) ($status['top_actions'] ?? []), 'is_array'));
        $topProgressSignals = array_values(array_filter((array) ($status['top_progress_signals'] ?? []), 'is_array'));
        $jobHealth = is_array($status['job_health'] ?? null) ? (array) $status['job_health'] : [];

        $requiredActions = 0;
        $recommendedActions = 0;
        $optionalEnrichments = 0;
        foreach ($topActions as $action) {
            $kind = (string) ($action['kind'] ?? 'recommended');
            if ($kind === 'required') {
                $requiredActions++;
            } elseif ($kind === 'optional_enrichment') {
                $optionalEnrichments++;
            } else {
                $recommendedActions++;
            }
        }

        $attentionCounts = [
            'required_actions' => $requiredActions,
            'recommended_actions' => $recommendedActions,
            'optional_enrichments' => $optionalEnrichments,
            'progress_signals' => count($topProgressSignals),
            'failed_jobs' => max(0, (int) ($jobHealth['failed'] ?? 0)),
            'stale_jobs' => max(0, (int) ($jobHealth['stale'] ?? 0)),
            'running_jobs' => max(0, (int) ($jobHealth['running'] ?? 0)),
            'healthy_jobs' => max(0, (int) ($jobHealth['healthy'] ?? 0)),
            'total_jobs' => max(0, (int) ($jobHealth['total'] ?? 0)),
        ];

        $blockingCount = $attentionCounts['required_actions']
            + $attentionCounts['failed_jobs']
            + $attentionCounts['stale_jobs'];

        if ($attentionCounts['failed_jobs'] > 0) {
            $healthStatus = 'critical';
            $healthLabel = 'Needs attention';
            $healthMessage = 'Failed automation jobs need review before the workspace can trust higher automation.';
        } elseif ($attentionCounts['stale_jobs'] > 0 || $attentionCounts['required_actions'] > 0) {
            $healthStatus = 'warning';
            $healthLabel = 'Checks to review';
            $parts = [];
            if ($attentionCounts['required_actions'] > 0) {
                $parts[] = $attentionCounts['required_actions'] . ' required action' . ($attentionCounts['required_actions'] === 1 ? '' : 's');
            }
            if ($attentionCounts['stale_jobs'] > 0) {
                $parts[] = $attentionCounts['stale_jobs'] . ' stale worker' . ($attentionCounts['stale_jobs'] === 1 ? '' : 's');
            }
            $healthMessage = implode(' and ', $parts) . ' need attention.';
        } elseif ($attentionCounts['progress_signals'] > 0 || $attentionCounts['recommended_actions'] > 0) {
            $healthStatus = 'building';
            $healthLabel = 'Building evidence';
            $healthMessage = 'No blocking setup checks are waiting. Normal work and learning signals can keep improving the score.';
        } else {
            $healthStatus = 'healthy';
            $healthLabel = 'Healthy';
            $healthMessage = 'No setup checks waiting.';
        }

        $status['attention_counts'] = $attentionCounts;
        $status['refresh_state_label'] = $this->buildRefreshStateLabel($status);
        $status['health_summary'] = [
            'status' => $healthStatus,
            'tone' => $healthStatus,
            'label' => $healthLabel,
            'message' => $healthMessage,
            'last_checked_label' => (string) (($status['updated_label'] ?? '') ?: 'Not checked yet'),
            'refresh_state_label' => (string) $status['refresh_state_label'],
            'next_refresh_at' => $status['refresh_due_at'] ?? null,
            'no_setup_checks_waiting' => $blockingCount === 0,
        ];

        return $status;
    }

    /**
     * @param array<string,mixed> $status
     */
    private function buildRefreshStateLabel(array $status): string
    {
        if (!empty($status['refresh_skipped'])) {
            return match ((string) ($status['refresh_skip_reason'] ?? '')) {
                'manual_throttle' => 'Already fresh. Manual refresh will be available again shortly.',
                'not_due' => 'Already fresh. The next automatic check is scheduled.',
                'manual_only' => 'Automatic checks are off. Manual refresh is available from the dashboard.',
                default => 'Refresh skipped. Showing the latest saved score.',
            };
        }

        if (!empty($status['is_stale'])) {
            return 'Saved score is stale. Refresh for the latest automation health.';
        }

        $updatedLabel = trim((string) ($status['updated_label'] ?? ''));
        if ($updatedLabel !== '') {
            return $updatedLabel;
        }

        return 'Not checked yet.';
    }

    private function isIdleRefreshDue(?array $stored, array $policy): bool
    {
        if (!$this->refreshSettings->isAutomaticEnabled($policy)) {
            return false;
        }

        $seconds = $policy['seconds'] ?? null;
        if ($seconds === null || (int) $seconds <= 0) {
            return false;
        }

        if ($stored === null) {
            return true;
        }

        $calculatedTimestamp = $this->snapshotCalculatedTimestamp($stored);
        if ($calculatedTimestamp === null) {
            return true;
        }

        return time() >= ($calculatedTimestamp + (int) $seconds);
    }

    private function refreshDueAt(array $status, array $policy): ?string
    {
        if (!$this->refreshSettings->isAutomaticEnabled($policy)) {
            return null;
        }

        $seconds = $policy['seconds'] ?? null;
        if ($seconds === null || (int) $seconds <= 0) {
            return null;
        }

        $calculatedTimestamp = $this->snapshotCalculatedTimestamp($status);
        if ($calculatedTimestamp === null) {
            return date('c');
        }

        return date('c', $calculatedTimestamp + (int) $seconds);
    }

    private function isManualRefreshThrottled(array $stored, array $policy): bool
    {
        if ((string) ($stored['snapshot_source'] ?? '') !== 'manual') {
            return false;
        }

        $calculatedTimestamp = $this->snapshotCalculatedTimestamp($stored);
        if ($calculatedTimestamp === null) {
            return false;
        }

        $throttleSeconds = max(1, (int) ($policy['manual_throttle_seconds'] ?? WorkspaceAutomationReadinessSettingsService::MANUAL_REFRESH_THROTTLE_SECONDS));
        return time() < ($calculatedTimestamp + $throttleSeconds);
    }

    private function snapshotCalculatedTimestamp(array $status): ?int
    {
        $calculatedAt = (string) ($status['snapshot_calculated_at'] ?? $status['calculated_at'] ?? '');
        if ($calculatedAt === '') {
            return null;
        }

        $timestamp = strtotime($calculatedAt);
        return $timestamp === false ? null : $timestamp;
    }

    private function buildPendingStatus(int $subjectUserId): array
    {
        return [
            'score' => 0,
            'bucket' => 'pending',
            'status_label' => 'Ready to check',
            'headline_label' => 'Not checked yet',
            'mode_label' => 'Not checked yet',
            'summary' => 'No background calculation is running. Check this when you want a fresh automation score and next setup moves.',
            'setup_progress_label' => '',
            'signal_title' => 'Ready when you are',
            'signal_copy' => 'Check once to see the current score, strongest signals, required actions, and optional enrichments.',
            'top_blockers' => [],
            'top_boosters' => ['Fresh score', 'Actions to review', 'Strong signals'],
            'top_actions' => [],
            'top_progress_signals' => [],
            'context_enrichments' => [],
            'is_visible' => true,
            'layers' => [],
            'job_health' => [],
            'subject_user_id' => $subjectUserId,
            'snapshot_calculated_at' => '',
            'snapshot_expires_at' => '',
            'snapshot_source' => '',
            'snapshot_fingerprint' => '',
            'is_stale' => false,
            'updated_label' => '',
        ];
    }

    private function formatSnapshotUpdatedLabel(string $calculatedAt): string
    {
        $timestamp = strtotime($calculatedAt);
        if ($timestamp === false) {
            return '';
        }

        $ageSeconds = max(0, time() - $timestamp);
        if ($ageSeconds < 60) {
            return 'Updated just now';
        }

        $minutes = (int) floor($ageSeconds / 60);
        if ($minutes < 60) {
            return 'Updated ' . $minutes . ' min ago';
        }

        $hours = (int) floor($minutes / 60);
        if ($hours < 24) {
            return 'Updated ' . $hours . ' hr' . ($hours === 1 ? '' : 's') . ' ago';
        }

        $days = (int) floor($hours / 24);
        return 'Updated ' . $days . ' day' . ($days === 1 ? '' : 's') . ' ago';
    }

    private function normalizeCalculationSource(string $source): string
    {
        $source = strtolower(trim($source));
        return in_array($source, ['manual', 'idle', 'cron', 'service'], true) ? $source : 'manual';
    }

    public function getMobileStatus(int $viewerUserId, ?int $subjectUserId = null): array
    {
        $status = $this->getStatus($viewerUserId, $subjectUserId);
        try {
            $status = $this->withRefreshPolicyMetadata(
                $status,
                $this->refreshSettings->getPolicy($this->workspaceScope->requireWorkspaceId())
            );
        } catch (\Throwable $e) {
            $status = $this->withOperationalPolishMetadata($status);
        }

        return [
            'score' => (int) ($status['score'] ?? 0),
            'bucket' => (string) ($status['bucket'] ?? 'low'),
            'status_label' => (string) ($status['status_label'] ?? 'Checked'),
            'headline_label' => (string) ($status['headline_label'] ?? ''),
            'summary' => (string) ($status['summary'] ?? ''),
            'setup_progress_label' => (string) ($status['setup_progress_label'] ?? ''),
            'top_blockers' => array_values(array_map('strval', array_slice((array) ($status['top_blockers'] ?? []), 0, 4))),
            'top_boosters' => array_values(array_map('strval', array_slice((array) ($status['top_boosters'] ?? []), 0, 4))),
            'top_actions' => $this->compactAutomationActions((array) ($status['top_actions'] ?? []), 3),
            'top_progress_signals' => $this->compactAutomationProgressSignals((array) ($status['top_progress_signals'] ?? []), 4),
            'attention_counts' => (array) ($status['attention_counts'] ?? []),
            'health_summary' => (array) ($status['health_summary'] ?? []),
            'refresh_state_label' => (string) ($status['refresh_state_label'] ?? ''),
            'refresh_policy' => [
                'cadence' => (string) ($status['refresh_policy']['cadence'] ?? ''),
                'label' => (string) ($status['refresh_policy']['label'] ?? ''),
                'seconds' => $status['refresh_policy']['seconds'] ?? null,
                'automatic_enabled' => !empty($status['refresh_policy']['automatic_enabled']),
            ],
            'refresh_due_at' => $status['refresh_due_at'] ?? null,
            'last_calculated_at' => (string) ($status['last_calculated_at'] ?? $status['snapshot_calculated_at'] ?? ''),
            'age_seconds' => $status['age_seconds'] ?? null,
        ];
    }

    /**
     * @param array<int,mixed> $actions
     * @return array<int,array<string,mixed>>
     */
    private function compactAutomationActions(array $actions, int $limit): array
    {
        $compact = [];
        foreach ($actions as $action) {
            if (!is_array($action)) {
                continue;
            }
            $compact[] = [
                'key' => (string) ($action['key'] ?? ''),
                'label' => (string) ($action['label'] ?? ''),
                'kind' => (string) ($action['kind'] ?? 'recommended'),
                'href' => (string) ($action['href'] ?? ''),
                'cta_label' => (string) ($action['cta_label'] ?? ''),
                'layer_key' => (string) ($action['layer_key'] ?? ''),
                'completion_signal' => (string) ($action['completion_signal'] ?? ''),
            ];
            if (count($compact) >= $limit) {
                break;
            }
        }

        return $compact;
    }

    /**
     * @param array<int,mixed> $signals
     * @return array<int,array<string,mixed>>
     */
    private function compactAutomationProgressSignals(array $signals, int $limit): array
    {
        $compact = [];
        foreach ($signals as $signal) {
            if (!is_array($signal)) {
                continue;
            }
            $compact[] = [
                'key' => (string) ($signal['key'] ?? ''),
                'label' => (string) ($signal['label'] ?? $signal['source_blocker'] ?? ''),
                'severity' => (string) ($signal['severity'] ?? 'info'),
                'layer_key' => (string) ($signal['layer_key'] ?? ''),
                'current_value' => $signal['current_value'] ?? null,
                'target_value' => $signal['target_value'] ?? null,
                'completion_signal' => (string) ($signal['completion_signal'] ?? ''),
            ];
            if (count($compact) >= $limit) {
                break;
            }
        }

        return $compact;
    }

    private function buildSetupLayer(array $featureState, array $userSetupReadiness, array $activeLayers): array
    {
        $checks = [
            'Company profile ready' => !empty($featureState['company_profile_ready']),
            'Product pricing ready' => !empty($featureState['products_priced']),
            'Invoicing ready' => !empty($featureState['invoicing_ready']),
            'Workflow graph ready' => !empty($featureState['workflow_graph_ready']),
        ];
        if (!empty($activeLayers['commercial'])) {
            $checks['Commercial automation available'] = !empty($featureState['commercial_automation_ready']);
        }
        $completedCount = count(array_filter($checks));
        $blockers = [];
        foreach ((array) ($featureState['readiness_gaps'] ?? []) as $gap) {
            if ((string) $gap === 'commercial automation' && empty($activeLayers['commercial'])) {
                continue;
            }
            $blockers[] = 'Complete ' . ucfirst((string) $gap);
        }
        $signals = [];
        foreach ($checks as $label => $passed) {
            if ($passed) {
                $signals[] = $label;
            }
        }

        $score = $this->scoreBooleans($checks);
        return [
            'label' => 'Setup',
            'score' => $score,
            'bucket' => $this->scoreBucket($score),
            'status_label' => $completedCount === count($checks) ? 'Ready' : 'Incomplete',
            'detail_value' => $completedCount . '/' . count($checks),
            'summary' => $completedCount === count($checks)
                ? 'Core setup foundations are complete, so the system can safely progress toward higher automation states.'
                : 'Core setup is still incomplete, which hard-caps the system before near-full automation.',
            'blockers' => array_slice($blockers, 0, 3),
            'signals' => array_slice($signals, 0, 3),
            'is_complete' => $completedCount === count($checks),
            'completed_count' => $completedCount,
            'total_count' => count($checks),
            'counted_in_score' => true,
            'metadata' => [
                'products_total' => (int) ($featureState['products_total'] ?? 0),
                'priced_products' => (int) ($featureState['priced_products'] ?? 0),
            ],
        ];
    }

    private function buildAutoresponderLayer(array $jobSummary): array
    {
        $config = $this->autoResponderConfig->get();
        $mode = (string) ($config['mode'] ?? 'draft_only');
        $logs = $this->getAutoResponderLogSummary();
        $modeScoreMap = [
            'off' => 0,
            'draft_only' => 22,
            'hybrid' => 68,
            'full_auto' => 100,
        ];

        $channelReadiness = $this->scoreBooleans(array_map(
            static fn(array $channelConfig): bool => !empty($channelConfig['enabled']),
            (array) ($config['channels'] ?? [])
        ));
        $modeScore = $modeScoreMap[$mode] ?? 22;
        $autoSendRate = (float) ($logs['auto_send_rate'] ?? 0.0);
        $confidence = (float) ($logs['avg_confidence'] ?? 0.0);
        $threshold = (float) ($config['default_confidence_threshold'] ?? 0.85);
        $confidenceScore = $confidence > 0 ? (int) round(min(100, ($confidence / max(0.01, $threshold)) * 80)) : ($mode === 'full_auto' ? 35 : 20);
        $executionScore = (int) round(min(100, ($autoSendRate * 100) + min(25, (int) ($logs['auto_sent_count'] ?? 0) * 2)));
        $healthPenalty = min(35, ((int) ($logs['failed_count'] ?? 0) * 8) + ((int) ($logs['blocked_count'] ?? 0) * 4));
        if (($jobSummary['failed'] ?? 0) > 0 && !empty($this->jobHealth->getJob('ai_autoresponder_queue')['status'])) {
            $healthPenalty += 8;
        }

        $score = (int) round(($modeScore * 0.45) + ($channelReadiness * 0.15) + ($confidenceScore * 0.20) + ($executionScore * 0.20));
        $score = max(0, min(100, $score - $healthPenalty));

        $blockers = [];
        if (empty($config['enabled']) || $mode === 'off') {
            $blockers[] = 'Enable AI auto responder';
        }
        if ($mode === 'draft_only') {
            $blockers[] = 'Move auto responder beyond draft-only mode';
        }
        if ($confidence > 0 && $confidence < $threshold) {
            $blockers[] = 'Autoresponder confidence is still below threshold';
        }
        if (($logs['blocked_count'] ?? 0) > 0) {
            $blockers[] = 'Reduce blocked auto-responder decisions';
        }
        if (($logs['failed_count'] ?? 0) > 0) {
            $blockers[] = 'Stabilize failed auto-responder sends';
        }

        $signals = [];
        if (!empty($config['enabled'])) {
            $signals[] = 'Auto responder enabled';
        }
        if ($mode === 'hybrid' || $mode === 'full_auto') {
            $signals[] = 'Responder is past draft-only mode';
        }
        if (($logs['auto_sent_count'] ?? 0) > 0) {
            $signals[] = 'Recent auto-sent replies detected';
        }
        if ($confidence >= $threshold && $confidence > 0) {
            $signals[] = 'Confidence is meeting send threshold';
        }

        return [
            'label' => 'AI Auto Responder',
            'score' => $score,
            'bucket' => $this->scoreBucket($score),
            'status_label' => $this->formatModeLabel($mode, 'Draft only'),
            'detail_value' => strtoupper(str_replace('_', ' ', $mode)),
            'summary' => $this->buildLayerSummary(
                $mode === 'full_auto' ? 'Customer auto replies are running in full auto mode.' : 'Customer auto replies are still progressing toward confident full auto.',
                $blockers
            ),
            'blockers' => array_slice($blockers, 0, 3),
            'signals' => array_slice($signals, 0, 3),
            'counted_in_score' => true,
            'metadata' => [
                'confidence' => $confidence,
                'threshold' => $threshold,
                'blocked_count' => (int) ($logs['blocked_count'] ?? 0),
                'failed_count' => (int) ($logs['failed_count'] ?? 0),
            ],
        ];
    }

    private function buildCommercialLayer(array $jobSummary, int $subjectUserId): array
    {
        $tenantKey = $this->workspaceScope->currentTenantKey();
        $rollout = $this->rolloutOps->computeReadiness($tenantKey, 'commercial_mvp');
        $control = (array) ($rollout['control'] ?? []);
        $mode = (string) ($control['autonomy_mode'] ?? 'suggest_only');
        $commercialSummary = $this->getCommercialSummary($subjectUserId, $this->workspaceScope->requireWorkspaceId($tenantKey));
        $calibrationMetrics = $this->calibration->getSurfaceMetrics('commercial', [
            'user_id' => $subjectUserId,
            'date_from' => date('Y-m-d', strtotime('-30 days')),
            'date_to' => date('Y-m-d'),
        ]);
        $surfaceMetrics = $this->pickStrongestCalibrationMetrics($calibrationMetrics);

        $modeScoreMap = [
            'suggest_only' => 35,
            'auto_safe' => 72,
            'full_auto' => 100,
        ];
        $modeScore = $modeScoreMap[$mode] ?? 15;
        $readinessBoost = match ((string) ($rollout['rollout_state'] ?? 'not_ready')) {
            'ready' => 18,
            'degraded' => -15,
            'not_ready', 'paused', 'manually_frozen' => -25,
            default => 0,
        };
        $runScore = (int) round(min(100, ((int) ($commercialSummary['recent_runs'] ?? 0) * 8) + ((float) ($commercialSummary['auto_apply_rate'] ?? 0) * 40)));
        $confidenceScore = (float) ($surfaceMetrics['precision_at_current_threshold'] ?? 0) > 0
            ? (int) round(min(100, ((float) ($surfaceMetrics['precision_at_current_threshold'] ?? 0)) * 100))
            : 40;
        $score = (int) round(($modeScore * 0.45) + ($runScore * 0.20) + ($confidenceScore * 0.20) + max(0, min(100, 60 + $readinessBoost)) * 0.15);

        if (($commercialSummary['pending_approvals'] ?? 0) >= 5) {
            $score -= 10;
        }
        if (($rollout['rollout_state'] ?? '') === 'degraded') {
            $score -= 12;
        }
        $score = max(0, min(100, $score));

        $blockers = [];
        if (($commercialSummary['recent_runs'] ?? 0) === 0) {
            $blockers[] = 'Commercial automation has not built enough recent run history';
        }
        if (($commercialSummary['pending_approvals'] ?? 0) > 0) {
            $blockers[] = 'Reduce commercial approvals backlog';
        }
        if (($rollout['rollout_state'] ?? '') !== 'ready') {
            $blockers[] = 'Commercial automation rollout is not fully ready';
        }
        if ((float) ($surfaceMetrics['precision_at_current_threshold'] ?? 0) > 0 && (float) ($surfaceMetrics['precision_at_current_threshold'] ?? 0) < 0.85) {
            $blockers[] = 'Commercial confidence is not strong enough for full auto';
        }

        $signals = [];
        if (($commercialSummary['recent_runs'] ?? 0) > 0) {
            $signals[] = 'Recent commercial automation runs detected';
        }
        if (($commercialSummary['auto_apply_rate'] ?? 0) >= 0.5) {
            $signals[] = 'Commercial automation is auto-applying decisions';
        }
        if (($rollout['rollout_state'] ?? '') === 'ready') {
            $signals[] = 'Commercial rollout state is ready';
        }
        if ((float) ($surfaceMetrics['precision_at_current_threshold'] ?? 0) >= 0.90) {
            $signals[] = 'Commercial confidence precision is strong';
        }

        return [
            'label' => 'Commercial Layer',
            'score' => $score,
            'bucket' => $this->scoreBucket($score),
            'status_label' => $this->formatModeLabel($mode, 'Suggest only'),
            'detail_value' => sprintf('%d recent runs', (int) ($commercialSummary['recent_runs'] ?? 0)),
            'summary' => $this->buildLayerSummary(
                $mode === 'full_auto' ? 'Commercial automation is operating close to autonomous document flow.' : 'Commercial automation is still progressing through supervised maturity stages.',
                $blockers
            ),
            'blockers' => array_slice($blockers, 0, 3),
            'signals' => array_slice($signals, 0, 3),
            'counted_in_score' => true,
            'metadata' => [
                'recent_runs' => (int) ($commercialSummary['recent_runs'] ?? 0),
                'pending_approvals' => (int) ($commercialSummary['pending_approvals'] ?? 0),
                'precision' => (float) ($surfaceMetrics['precision_at_current_threshold'] ?? 0),
                'rollout_state' => (string) ($rollout['rollout_state'] ?? ''),
                'mode' => $mode,
            ],
        ];
    }

    private function buildCustomerCareLayer(int $subjectUserId): array
    {
        $tenantKey = $this->workspaceScope->currentTenantKey();
        $workspaceId = $this->workspaceScope->requireWorkspaceId($tenantKey);
        $rollout = $this->rolloutOps->computeReadiness($tenantKey, CustomerCareAutomationService::DOMAIN_KEY);
        $control = (array) ($rollout['control'] ?? []);
        $mode = (string) ($control['autonomy_mode'] ?? 'suggest_only');
        $rolloutState = (string) ($rollout['rollout_state'] ?? 'not_ready');
        $summary = $this->getCustomerCareSummary($subjectUserId, $workspaceId);

        $checks = [
            'profiles' => (int) ($summary['profile_count'] ?? 0) > 0,
            'care_brief' => (int) ($summary['profile_count'] ?? 0) > 0,
            'plans' => (int) ($summary['program_count'] ?? 0) > 0,
            'check_ins' => (int) ($summary['recent_completed_check_ins'] ?? 0) > 0,
            'demonstrations' => (int) ($summary['demonstration_count'] ?? 0) > 0,
            'incidents_clear' => (int) ($summary['critical_incident_count'] ?? 0) === 0,
            'rollout_ready' => !in_array($rolloutState, ['paused', 'manually_frozen', 'not_ready'], true)
                && (string) ($control['promotion_status'] ?? 'suggest_only') !== 'blocked',
        ];

        $modeScore = [
            'suggest_only' => 35,
            'auto_safe' => 72,
            'full_auto' => 86,
        ][$mode] ?? 25;
        $evidenceScore = (int) round(min(100,
            ((int) ($summary['recent_completed_check_ins'] ?? 0) * 16)
            + ((int) ($summary['demonstration_count'] ?? 0) * 5)
            + ((int) ($summary['profile_count'] ?? 0) * 4)
        ));
        $readinessScore = $this->scoreBooleans($checks);
        $score = (int) round(($modeScore * 0.30) + ($readinessScore * 0.45) + ($evidenceScore * 0.25));

        if ($rolloutState === 'degraded') {
            $score -= 12;
        } elseif (in_array($rolloutState, ['paused', 'manually_frozen'], true)) {
            $score -= 22;
        } elseif ($rolloutState === 'not_ready') {
            $score -= 16;
        }
        if ((int) ($summary['critical_incident_count'] ?? 0) > 0) {
            $score -= 24;
        }
        $score = max(0, min(100, $score));

        $blockers = [];
        if ((int) ($summary['profile_count'] ?? 0) === 0) {
            $blockers[] = 'Add paying customers to Customer Care';
        }
        if ((int) ($summary['program_count'] ?? 0) === 0) {
            $blockers[] = 'Create at least one follow-up plan';
        }
        if ((int) ($summary['recent_completed_check_ins'] ?? 0) === 0 || (int) ($summary['demonstration_count'] ?? 0) < 5) {
            $blockers[] = 'Complete more check-ins so the system can learn';
        }
        if ((int) ($summary['critical_incident_count'] ?? 0) > 0) {
            $blockers[] = 'Resolve Customer Care automation incidents';
        }
        if (in_array($rolloutState, ['paused', 'manually_frozen'], true)) {
            $blockers[] = 'Customer Care automation is paused';
        }

        $signals = [];
        if ((int) ($summary['profile_count'] ?? 0) > 0) {
            $signals[] = 'Paying customer care profiles detected';
        }
        if ((int) ($summary['program_count'] ?? 0) > 0) {
            $signals[] = 'Follow-up plans available';
        }
        if ((int) ($summary['recent_completed_check_ins'] ?? 0) > 0) {
            $signals[] = 'Completed check-ins detected';
        }
        if ((int) ($summary['demonstration_count'] ?? 0) > 0) {
            $signals[] = 'Customer Care demonstrations captured';
        }
        if ($rolloutState === 'ready') {
            $signals[] = 'Customer Care rollout state is ready';
        }

        $statusLabel = $this->formatModeLabel($mode, 'Suggest only');
        if ($rolloutState === 'degraded') {
            $statusLabel = 'Needs Attention';
        } elseif (in_array($rolloutState, ['paused', 'manually_frozen'], true)) {
            $statusLabel = 'Paused';
        } elseif ($rolloutState === 'not_ready' || (string) ($control['promotion_status'] ?? '') === 'blocked') {
            $statusLabel = 'Needs More Learning';
        }

        return [
            'label' => 'Customer Care',
            'score' => $score,
            'bucket' => $this->scoreBucket($score),
            'status_label' => $statusLabel,
            'detail_value' => sprintf('%d care profiles', (int) ($summary['profile_count'] ?? 0)),
            'summary' => $this->buildLayerSummary(
                'Can the system safely handle customer check-ins with less manual work?',
                $blockers
            ),
            'blockers' => array_slice($blockers, 0, 3),
            'signals' => array_slice($signals, 0, 3),
            'counted_in_score' => true,
            'rollout_state' => $rolloutState,
            'mode' => $mode,
            'metadata' => [
                'profile_count' => (int) ($summary['profile_count'] ?? 0),
                'program_count' => (int) ($summary['program_count'] ?? 0),
                'recent_completed_check_ins' => (int) ($summary['recent_completed_check_ins'] ?? 0),
                'demonstration_count' => (int) ($summary['demonstration_count'] ?? 0),
                'critical_incident_count' => (int) ($summary['critical_incident_count'] ?? 0),
                'rollout_state' => $rolloutState,
                'mode' => $mode,
            ],
        ];
    }

    private function buildWorkflowAutomationLayer(int $subjectUserId): array
    {
        $tenantKey = $this->workspaceScope->currentTenantKey();
        $rollout = $this->rolloutOps->computeReadiness($tenantKey, 'workflow_execution');
        $control = (array) ($rollout['control'] ?? []);
        $mode = (string) ($control['autonomy_mode'] ?? 'suggest_only');
        $summary = $this->getWorkflowAutomationSummary($subjectUserId, $this->workspaceScope->requireWorkspaceId($tenantKey));

        $modeScoreMap = [
            'suggest_only' => 30,
            'auto_safe' => 70,
            'full_auto' => 100,
        ];
        $modeScore = $modeScoreMap[$mode] ?? 20;
        $rolloutState = (string) ($rollout['rollout_state'] ?? 'not_ready');
        $readinessBoost = match ($rolloutState) {
            'ready' => 18,
            'degraded' => -12,
            'paused', 'manually_frozen' => -18,
            'not_ready' => -24,
            default => 0,
        };
        $recentActivityScore = (int) round(min(100, ((int) ($summary['recent_total'] ?? 0) * 8) + ((int) ($summary['applied_count'] ?? 0) * 10)));
        $applyRateScore = (int) round(min(100, ((float) ($summary['applied_rate'] ?? 0.0)) * 100));
        $score = (int) round(
            ($modeScore * 0.45) +
            (max(0, min(100, 60 + $readinessBoost)) * 0.20) +
            ($recentActivityScore * 0.20) +
            ($applyRateScore * 0.15)
        );

        if (($summary['pending_count'] ?? 0) >= 3) {
            $score -= min(15, ((int) ($summary['pending_count'] ?? 0)) * 3);
        }
        if (in_array($rolloutState, ['degraded', 'not_ready', 'paused', 'manually_frozen'], true)) {
            $score -= 8;
        }
        $score = max(0, min(100, $score));

        $blockers = [];
        if (($summary['recent_total'] ?? 0) === 0) {
            $blockers[] = 'Workflow automation has not built enough recent proposal history';
        }
        if (($summary['pending_count'] ?? 0) > 0) {
            $blockers[] = 'Reduce workflow automation approval backlog';
        }
        if ($rolloutState !== 'ready') {
            $blockers[] = 'Workflow automation rollout is not fully ready';
        }
        if ($mode === 'suggest_only') {
            $blockers[] = 'Move workflow automation beyond suggest-only mode';
        }

        $signals = [];
        if (($summary['recent_total'] ?? 0) > 0) {
            $signals[] = 'Recent workflow automation proposals detected';
        }
        if (($summary['applied_count'] ?? 0) > 0) {
            $signals[] = 'Workflow automation is applying approved changes';
        }
        if ($mode === 'auto_safe' || $mode === 'full_auto') {
            $signals[] = 'Workflow automation is beyond suggest-only';
        }
        if ($rolloutState === 'ready') {
            $signals[] = 'Workflow automation rollout state is ready';
        }

        $statusLabel = $this->formatModeLabel($mode, 'Suggest only');
        if ($rolloutState === 'degraded') {
            $statusLabel = 'Needs Attention';
        } elseif (in_array($rolloutState, ['paused', 'manually_frozen'], true)) {
            $statusLabel = 'Managed Hold';
        } elseif ($rolloutState === 'not_ready') {
            $statusLabel = 'Not Ready';
        }

        return [
            'label' => 'Workflow Automation',
            'score' => $score,
            'bucket' => $this->scoreBucket($score),
            'status_label' => $statusLabel,
            'detail_value' => sprintf('%d recent proposals', (int) ($summary['recent_total'] ?? 0)),
            'summary' => $this->buildLayerSummary(
                $mode === 'full_auto'
                    ? 'Workflow automation is operating near autonomous create, update, and deploy flow.'
                    : 'Workflow automation is still progressing through governed proposal and approval stages.',
                $blockers
            ),
            'blockers' => array_slice($blockers, 0, 3),
            'signals' => array_slice($signals, 0, 3),
            'counted_in_score' => true,
            'metadata' => [
                'recent_total' => (int) ($summary['recent_total'] ?? 0),
                'pending_count' => (int) ($summary['pending_count'] ?? 0),
                'applied_count' => (int) ($summary['applied_count'] ?? 0),
                'rollout_state' => $rolloutState,
                'mode' => $mode,
            ],
        ];
    }

    private function buildDealLayer(array $dealState, bool $autoAdminEnabled): array
    {
        $mode = (string) ($dealState['current_mode'] ?? 'manual');
        $modeScoreMap = [
            'manual' => 10,
            'suggest_only' => 45,
            'auto_safe' => 78,
            'full_auto' => 100,
        ];
        $auditSummary = (array) ($dealState['recent_audit_summary'] ?? []);
        $modeScore = $modeScoreMap[$mode] ?? 10;
        $readinessBoost = ((string) ($dealState['readiness_status'] ?? 'unknown')) === 'ready' ? 18 : (((string) ($dealState['readiness_status'] ?? 'unknown')) === 'needs_attention' ? -12 : -20);
        $auditScore = (int) round(min(100, ((int) ($auditSummary['recent_total'] ?? 0) * 7) + ((int) ($auditSummary['recent_applied'] ?? 0) * 5)));
        $score = (int) round(($modeScore * 0.50) + max(0, min(100, 60 + $readinessBoost)) * 0.25 + ($auditScore * 0.25));
        if ($autoAdminEnabled && !empty($dealState['is_managed_by_auto_admin'])) {
            $score = min(100, $score + 5);
        }
        if (!empty($dealState['blocking_reasons'])) {
            $score -= min(24, count((array) $dealState['blocking_reasons']) * 6);
        }
        $score = max(0, min(100, $score));

        $signals = [];
        if (($auditSummary['recent_total'] ?? 0) >= 5) {
            $signals[] = 'Deal automation has recent audit history';
        }
        if (($dealState['readiness_status'] ?? '') === 'ready') {
            $signals[] = 'Deal automation readiness checks are passing';
        }
        if ($mode === 'auto_safe' || $mode === 'full_auto') {
            $signals[] = 'Deal automation is beyond suggest-only';
        }

        return [
            'label' => 'Deal Automation',
            'score' => $score,
            'bucket' => $this->scoreBucket($score),
            'status_label' => (string) ($dealState['current_mode_label'] ?? $this->formatModeLabel($mode, 'Manual')),
            'detail_value' => sprintf('%d recent audits', (int) ($auditSummary['recent_total'] ?? 0)),
            'summary' => $this->buildLayerSummary(
                (($dealState['readiness_status'] ?? '') === 'ready')
                    ? 'Deal automation has enough controls and recent history to keep advancing.'
                    : 'Deal automation is still limited by readiness checks or sparse evidence.',
                (array) ($dealState['blocking_reasons'] ?? [])
            ),
            'blockers' => array_slice(array_values((array) ($dealState['blocking_reasons'] ?? [])), 0, 3),
            'signals' => array_slice($signals, 0, 3),
            'counted_in_score' => true,
            'metadata' => [
                'recent_total' => (int) ($auditSummary['recent_total'] ?? 0),
                'recent_applied' => (int) ($auditSummary['recent_applied'] ?? 0),
                'mode' => $mode,
                'is_managed_by_auto_admin' => !empty($dealState['is_managed_by_auto_admin']),
            ],
        ];
    }

    private function buildLearningLayer(array $jobSummary, int $subjectUserId): array
    {
        $workspaceId = $this->workspaceScope->requireWorkspaceId();
        $incidentSignals = $this->incidentService->collectSignals();
        $calibrationSummary = $this->calibration->getCalibrationSummary([
            'date_from' => date('Y-m-d', strtotime('-30 days')),
            'date_to' => date('Y-m-d'),
            'user_id' => $subjectUserId,
        ]);
        $decisionOutcomeCount = $this->countFilteredRowsSince('ai_decision_outcomes', 'measured_at', 30, 'workspace_id = ? AND user_id = ?', [$workspaceId, $subjectUserId]);
        $demonstrationCount = $this->countFilteredRowsSince('ai_operator_demonstrations', 'created_at', 30, 'workspace_id = ? AND actor_user_id = ?', [$workspaceId, $subjectUserId]);
        $recentTuningChanges = 0;
        $activeIncidents = count((array) $this->incidentService->getActiveIncidents());
        $calibrationJob = (array) ($incidentSignals['calibration_job'] ?? []);
        $incidentJob = (array) ($incidentSignals['incident_job'] ?? []);
        $calibrationMetrics = $this->flattenCalibrationMetrics((array) ($calibrationSummary['summary'] ?? []));

        $evidenceScore = min(100, (int) round(($decisionOutcomeCount * 1.2) + ($demonstrationCount * 0.7)));
        $qualityScore = 45;
        if ($calibrationMetrics !== []) {
            $qualityScore = (int) round(array_sum(array_map(
                static fn(array $metric): float => ((float) ($metric['precision_at_current_threshold'] ?? 0.0)) * 100,
                $calibrationMetrics
            )) / count($calibrationMetrics));
        }

        $jobHealthScore = 100;
        foreach ([$calibrationJob, $incidentJob] as $job) {
            $status = (string) ($job['status'] ?? '');
            if ($status === 'failed') {
                $jobHealthScore -= 30;
            } elseif ($status === 'warning' || $status === 'stale') {
                $jobHealthScore -= 15;
            }
        }
        $jobHealthScore = max(25, $jobHealthScore);

        $score = (int) round(($evidenceScore * 0.35) + ($qualityScore * 0.35) + ($jobHealthScore * 0.20) + min(100, $recentTuningChanges * 20) * 0.10);
        if ($activeIncidents > 0) {
            $score -= min(28, $activeIncidents * 7);
        }
        $score = max(0, min(100, $score));

        $blockers = [];
        if ($decisionOutcomeCount < 15) {
            $blockers[] = 'Learning history is still too thin';
        }
        if ($demonstrationCount < 10) {
            $blockers[] = 'More accepted demonstrations and outcomes are needed';
        }
        if ((string) ($calibrationJob['status'] ?? '') === 'failed' || (string) ($calibrationJob['status'] ?? '') === 'stale') {
            $blockers[] = 'Confidence calibration job is not healthy';
        }
        if ($activeIncidents > 0) {
            $blockers[] = 'Active AI automation incidents are reducing trust';
        }
        if ($qualityScore > 0 && $qualityScore < 80) {
            $blockers[] = 'Confidence precision is not strong enough yet';
        }

        $signals = [];
        if ($decisionOutcomeCount >= 15) {
            $signals[] = 'Recent AI outcome history is available';
        }
        if ($demonstrationCount >= 10) {
            $signals[] = 'Demonstrations are feeding the learning loop';
        }
        if ((string) ($calibrationJob['status'] ?? '') === 'success') {
            $signals[] = 'Confidence calibration job is healthy';
        }
        if ($recentTuningChanges > 0) {
            $signals[] = 'Recent threshold tuning changes were recorded';
        }

        return [
            'label' => 'Learning',
            'score' => $score,
            'bucket' => $this->scoreBucket($score),
            'status_label' => $score >= 75 ? 'Learning Strong' : ($score >= 45 ? 'Learning Building' : 'Learning Thin'),
            'detail_value' => sprintf('%d outcomes / %d demos', $decisionOutcomeCount, $demonstrationCount),
            'summary' => $this->buildLayerSummary(
                $score >= 75
                    ? 'Learning, calibration, and feedback loops are strong enough to support more trusted automation.'
                    : 'The system is still building the learning evidence needed to trust higher automation modes.',
                $blockers
            ),
            'blockers' => array_slice($blockers, 0, 3),
            'signals' => array_slice($signals, 0, 3),
            'counted_in_score' => true,
            'metadata' => [
                'decision_outcome_count' => $decisionOutcomeCount,
                'demonstration_count' => $demonstrationCount,
                'quality_score' => $qualityScore,
                'active_incidents' => $activeIncidents,
                'calibration_job_status' => (string) ($calibrationJob['status'] ?? ''),
            ],
        ];
    }

    private function getAutoResponderLogSummary(): array
    {
        if (!$this->tableExists('ai_autoresponder_logs') || !$this->columnExists('ai_autoresponder_logs', 'workspace_id')) {
            return [
                'recent_total' => 0,
                'auto_sent_count' => 0,
                'failed_count' => 0,
                'blocked_count' => 0,
                'avg_confidence' => 0.0,
                'auto_send_rate' => 0.0,
            ];
        }

        $row = Database::queryOne(
            "SELECT
                COUNT(*) AS recent_total,
                SUM(CASE WHEN decision = 'auto_sent' THEN 1 ELSE 0 END) AS auto_sent_count,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_count,
                SUM(CASE WHEN decision = 'blocked' THEN 1 ELSE 0 END) AS blocked_count,
                AVG(confidence) AS avg_confidence
             FROM ai_autoresponder_logs
             WHERE workspace_id = ?
               AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
            [$this->workspaceScope->requireWorkspaceId()]
        ) ?: [];

        $total = (int) ($row['recent_total'] ?? 0);
        $sent = (int) ($row['auto_sent_count'] ?? 0);

        return [
            'recent_total' => $total,
            'auto_sent_count' => $sent,
            'failed_count' => (int) ($row['failed_count'] ?? 0),
            'blocked_count' => (int) ($row['blocked_count'] ?? 0),
            'avg_confidence' => isset($row['avg_confidence']) ? (float) $row['avg_confidence'] : 0.0,
            'auto_send_rate' => $total > 0 ? round($sent / $total, 4) : 0.0,
        ];
    }

    private function getCommercialSummary(?int $subjectUserId = null, ?int $workspaceId = null): array
    {
        $resolvedWorkspaceId = $this->workspaceScope->requireWorkspaceId(null, $workspaceId);
        $recentRuns = 0;
        $autoApplyCount = 0;
        if ($this->tableExists('commercial_automation_runs')) {
            $params = [$resolvedWorkspaceId];
            $joins = '';
            $where = ['car.workspace_id = ?', 'car.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)'];
            if ($subjectUserId !== null) {
                $joins = '
                    LEFT JOIN deals d ON d.id = car.deal_id AND d.workspace_id = car.workspace_id
                    LEFT JOIN contacts c ON c.id = COALESCE(car.contact_id, d.contact_id) AND c.workspace_id = car.workspace_id';
                $where[] = '(d.assigned_to = ? OR c.assigned_to = ?)';
                $params[] = $subjectUserId;
                $params[] = $subjectUserId;
            }
            $row = Database::queryOne(
                "SELECT
                    COUNT(DISTINCT car.id) AS recent_runs,
                    SUM(CASE WHEN car.decision = 'auto_apply' THEN 1 ELSE 0 END) AS auto_apply_count
                 FROM commercial_automation_runs car
                 {$joins}
                 WHERE " . implode(' AND ', $where),
                $params
            ) ?: [];
            $recentRuns = (int) ($row['recent_runs'] ?? 0);
            $autoApplyCount = (int) ($row['auto_apply_count'] ?? 0);
        }

        $pendingApprovals = 0;
        if ($this->tableExists('commercial_automation_approvals')) {
            $approvalParams = [$resolvedWorkspaceId];
            if ($subjectUserId === null) {
                $pendingApprovals = (int) ((Database::queryOne(
                    "SELECT COUNT(*) AS c FROM commercial_automation_approvals WHERE workspace_id = ? AND status = 'pending'",
                    $approvalParams
                )['c'] ?? 0));
            } else {
                $approvalParams[] = $subjectUserId;
                $approvalParams[] = $subjectUserId;
                $pendingApprovals = (int) ((Database::queryOne(
                    "SELECT COUNT(DISTINCT caa.id) AS c
                     FROM commercial_automation_approvals caa
                     LEFT JOIN deals d ON d.id = caa.deal_id AND d.workspace_id = caa.workspace_id
                     LEFT JOIN contacts c ON c.id = d.contact_id AND c.workspace_id = caa.workspace_id
                     WHERE caa.workspace_id = ? AND caa.status = 'pending' AND (d.assigned_to = ? OR c.assigned_to = ?)",
                    $approvalParams
                )['c'] ?? 0));
            }
        }

        return [
            'recent_runs' => $recentRuns,
            'auto_apply_count' => $autoApplyCount,
            'auto_apply_rate' => $recentRuns > 0 ? round($autoApplyCount / $recentRuns, 4) : 0.0,
            'pending_approvals' => $pendingApprovals,
        ];
    }

    private function getCustomerCareSummary(?int $subjectUserId = null, ?int $workspaceId = null): array
    {
        $resolvedWorkspaceId = $this->workspaceScope->requireWorkspaceId(null, $workspaceId);
        $profileCount = 0;
        $recentCompletedCheckIns = 0;
        if ($this->tableExists('nurture_profiles')) {
            $params = [$resolvedWorkspaceId];
            $where = ['np.workspace_id = ?', "np.nurture_status <> 'exited'"];
            $joins = 'LEFT JOIN contacts c ON c.id = np.contact_id AND c.workspace_id = np.workspace_id';
            if ($subjectUserId !== null) {
                $where[] = '(np.owner_user_id = ? OR c.assigned_to = ?)';
                $params[] = $subjectUserId;
                $params[] = $subjectUserId;
            }

            $row = Database::queryOne(
                "SELECT COUNT(DISTINCT np.id) AS profile_count
                 FROM nurture_profiles np
                 {$joins}
                 WHERE " . implode(' AND ', $where),
                $params
            ) ?: [];
            $profileCount = (int) ($row['profile_count'] ?? 0);

            if ($this->tableExists('nurture_touchpoints')) {
                $touchParams = [$resolvedWorkspaceId];
                $touchWhere = [
                    'nt.workspace_id = ?',
                    "nt.touch_type = 'check_in'",
                    "nt.status = 'completed'",
                    'COALESCE(nt.completed_at, nt.created_at) >= DATE_SUB(NOW(), INTERVAL 30 DAY)',
                ];
                $touchJoins = 'LEFT JOIN nurture_profiles np ON np.id = nt.profile_id AND np.workspace_id = nt.workspace_id
                    LEFT JOIN contacts c ON c.id = nt.contact_id AND c.workspace_id = nt.workspace_id';
                if ($subjectUserId !== null) {
                    $touchWhere[] = '(nt.created_by = ? OR np.owner_user_id = ? OR c.assigned_to = ?)';
                    $touchParams[] = $subjectUserId;
                    $touchParams[] = $subjectUserId;
                    $touchParams[] = $subjectUserId;
                }
                $touchRow = Database::queryOne(
                    "SELECT COUNT(DISTINCT nt.id) AS recent_completed_check_ins
                     FROM nurture_touchpoints nt
                     {$touchJoins}
                     WHERE " . implode(' AND ', $touchWhere),
                    $touchParams
                ) ?: [];
                $recentCompletedCheckIns = (int) ($touchRow['recent_completed_check_ins'] ?? 0);
            }
        }

        $programCount = 0;
        if ($this->tableExists('nurture_programs')) {
            $programCount = (int) ((Database::queryOne(
                "SELECT COUNT(*) AS c
                 FROM nurture_programs
                 WHERE workspace_id = ?
                   AND status IN ('active', 'draft')",
                [$resolvedWorkspaceId]
            )['c'] ?? 0));
        }

        $demonstrationCount = 0;
        if ($this->tableExists('ai_operator_demonstrations')) {
            $demoParams = [$resolvedWorkspaceId, CustomerCareAutomationService::DOMAIN_KEY];
            $demoWhere = [
                'workspace_id = ?',
                'domain_key = ?',
                'observed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)',
            ];
            if ($subjectUserId !== null) {
                $demoWhere[] = 'actor_user_id = ?';
                $demoParams[] = $subjectUserId;
            }
            $demonstrationCount = (int) ((Database::queryOne(
                "SELECT COUNT(*) AS c
                 FROM ai_operator_demonstrations
                 WHERE " . implode(' AND ', $demoWhere),
                $demoParams
            )['c'] ?? 0));
        }

        $criticalIncidentCount = 0;
        if ($this->tableExists('ai_autonomy_incidents')) {
            $criticalIncidentCount = (int) ((Database::queryOne(
                "SELECT COUNT(*) AS c
                 FROM ai_autonomy_incidents
                 WHERE workspace_id = ?
                   AND domain_key = ?
                   AND status NOT IN ('resolved', 'suppressed')
                   AND severity = 'critical'",
                [$resolvedWorkspaceId, CustomerCareAutomationService::DOMAIN_KEY]
            )['c'] ?? 0));
        }

        return [
            'profile_count' => $profileCount,
            'program_count' => $programCount,
            'recent_completed_check_ins' => $recentCompletedCheckIns,
            'demonstration_count' => $demonstrationCount,
            'critical_incident_count' => $criticalIncidentCount,
        ];
    }

    private function getWorkflowAutomationSummary(?int $subjectUserId = null, ?int $workspaceId = null): array
    {
        if (!$this->tableExists('workflow_automation_proposals')) {
            return $this->emptyWorkflowAutomationSummary();
        }

        $params = [];
        $where = ['created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)'];
        if ($this->columnExists('workflow_automation_proposals', 'workspace_id')) {
            $resolvedWorkspaceId = $this->workspaceScope->requireWorkspaceId(null, $workspaceId);
            $where[] = 'workspace_id = ?';
            $params[] = $resolvedWorkspaceId;
        }
        if ($subjectUserId !== null) {
            $where[] = 'requested_by_id = ?';
            $params[] = $subjectUserId;
        }
        $row = Database::queryOne(
            "SELECT
                COUNT(*) AS recent_total,
                SUM(CASE WHEN status = 'applied' THEN 1 ELSE 0 END) AS applied_count,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_count
             FROM workflow_automation_proposals
             WHERE " . implode(' AND ', $where),
            $params
        ) ?: [];

        $recentTotal = (int) ($row['recent_total'] ?? 0);
        $appliedCount = (int) ($row['applied_count'] ?? 0);

        return [
            'recent_total' => $recentTotal,
            'applied_count' => $appliedCount,
            'pending_count' => (int) ($row['pending_count'] ?? 0),
            'applied_rate' => $recentTotal > 0 ? round($appliedCount / $recentTotal, 4) : 0.0,
        ];
    }

    private function emptyWorkflowAutomationSummary(): array
    {
        return [
            'recent_total' => 0,
            'applied_count' => 0,
            'pending_count' => 0,
            'applied_rate' => 0.0,
        ];
    }

    private function flattenCalibrationMetrics(array $summary): array
    {
        $metrics = [];
        foreach ($summary as $surface => $actions) {
            if (!is_array($actions)) {
                continue;
            }
            foreach ($actions as $actionType => $item) {
                if (is_array($item)) {
                    $metrics[] = $item + ['surface' => $surface, 'action_type' => $actionType];
                }
            }
        }
        return $metrics;
    }

    private function pickStrongestCalibrationMetrics(array $surfaceMetrics): array
    {
        $best = [];
        $bestSample = -1;
        foreach ($surfaceMetrics as $actionType => $metrics) {
            if (!is_array($metrics)) {
                continue;
            }
            $sample = (int) ($metrics['sample_size'] ?? 0);
            if ($sample > $bestSample) {
                $bestSample = $sample;
                $best = $metrics + ['action_type' => $actionType];
            }
        }
        return $best;
    }

    private function countTableRowsSince(string $table, string $dateColumn, int $days): int
    {
        if (!$this->tableExists($table)) {
            return 0;
        }

        $row = Database::queryOne(
            "SELECT COUNT(*) AS c FROM {$table} WHERE {$dateColumn} >= DATE_SUB(NOW(), INTERVAL {$days} DAY)"
        );
        return (int) ($row['c'] ?? 0);
    }

    private function countFilteredRowsSince(string $table, string $dateColumn, int $days, string $whereClause, array $params): int
    {
        if (!$this->tableExists($table)) {
            return 0;
        }

        $row = Database::queryOne(
            "SELECT COUNT(*) AS c FROM {$table} WHERE {$dateColumn} >= DATE_SUB(NOW(), INTERVAL {$days} DAY) AND {$whereClause}",
            $params
        );
        return (int) ($row['c'] ?? 0);
    }

    private function tableSignature(
        string $table,
        array $timeColumns = ['updated_at', 'created_at'],
        string $whereClause = '',
        array $params = [],
        array $checksumColumns = []
    ): array {
        if (!$this->tableExists($table)) {
            return ['exists' => false];
        }

        if (!$this->isSafeIdentifier($table)) {
            return ['exists' => false, 'error' => 'unsafe_table'];
        }

        $selects = ['COUNT(*) AS row_count'];
        foreach ($timeColumns as $column) {
            $column = (string) $column;
            if (!$this->isSafeIdentifier($column) || !$this->columnExists($table, $column)) {
                continue;
            }
            $selects[] = sprintf(
                'MAX(%s) AS %s',
                $this->quoteIdentifier($column),
                $this->quoteIdentifier('max_' . strtolower($column))
            );
        }

        $checksumParts = [];
        foreach ($checksumColumns as $column) {
            $column = (string) $column;
            if (!$this->isSafeIdentifier($column) || !$this->columnExists($table, $column)) {
                continue;
            }
            $checksumParts[] = 'COALESCE(CAST(' . $this->quoteIdentifier($column) . " AS CHAR), '')";
        }
        if ($checksumParts !== []) {
            $selects[] = "COALESCE(SUM(CRC32(CONCAT_WS('|', " . implode(', ', $checksumParts) . '))), 0) AS row_checksum';
        }

        $sql = 'SELECT ' . implode(', ', $selects) . ' FROM ' . $this->quoteIdentifier($table);
        if ($whereClause !== '') {
            $sql .= ' WHERE ' . $whereClause;
        }

        try {
            $row = Database::queryOne($sql, $params) ?: [];
        } catch (\Throwable $e) {
            return ['exists' => true, 'error' => get_class($e)];
        }

        ksort($row);
        return ['exists' => true] + $row;
    }

    private function scopedTableSignature(string $table, int $workspaceId, array $timeColumns, array $checksumColumns): array
    {
        [$whereClause, $params] = $this->workspaceScopeClause($table, $workspaceId);
        return $this->tableSignature($table, $timeColumns, $whereClause, $params, $checksumColumns);
    }

    private function strictScopedTableSignature(string $table, int $workspaceId, array $timeColumns, array $checksumColumns): array
    {
        if (!$this->tableExists($table)) {
            return ['exists' => false];
        }

        if (!$this->columnExists($table, 'workspace_id')) {
            return [
                'exists' => true,
                'scope_unavailable' => true,
                'row_count' => 0,
            ];
        }

        return $this->tableSignature($table, $timeColumns, 'workspace_id = ?', [$workspaceId], $checksumColumns);
    }

    private function scopedSubjectTableSignature(
        string $table,
        int $workspaceId,
        int $subjectUserId,
        string $subjectColumn,
        array $timeColumns,
        array $checksumColumns
    ): array {
        $where = [];
        $params = [];

        if ($this->columnExists($table, 'workspace_id')) {
            $where[] = 'workspace_id = ?';
            $params[] = $workspaceId;
        }
        if ($this->columnExists($table, $subjectColumn)) {
            $where[] = $this->quoteIdentifier($subjectColumn) . ' = ?';
            $params[] = $subjectUserId;
        }

        return $this->tableSignature($table, $timeColumns, implode(' AND ', $where), $params, $checksumColumns);
    }

    private function subjectOwnershipEvidenceSignature(
        string $table,
        int $workspaceId,
        int $subjectUserId,
        array $timeColumns,
        array $checksumColumns
    ): array {
        if ($table !== 'deal_automation_audit' || !$this->tableExists($table)) {
            return $this->tableSignature($table, $timeColumns, '', [], $checksumColumns);
        }

        $selects = [
            'COUNT(DISTINCT daa.id) AS row_count',
            'COALESCE(SUM(CRC32(CONCAT_WS(\'|\', COALESCE(CAST(daa.decision AS CHAR), \'\'), COALESCE(CAST(daa.applied AS CHAR), \'\'), COALESCE(CAST(daa.reason AS CHAR), \'\'), COALESCE(CAST(daa.deal_id AS CHAR), \'\'), COALESCE(CAST(daa.contact_id AS CHAR), \'\'), COALESCE(CAST(d.assigned_to AS CHAR), \'\'), COALESCE(CAST(c.assigned_to AS CHAR), \'\'), COALESCE(CAST(d.updated_at AS CHAR), \'\'), COALESCE(CAST(c.updated_at AS CHAR), \'\')))), 0) AS row_checksum',
        ];
        foreach ($timeColumns as $column) {
            $column = (string) $column;
            if ($this->isSafeIdentifier($column) && $this->columnExists($table, $column)) {
                $selects[] = sprintf('MAX(daa.%s) AS %s', $this->quoteIdentifier($column), $this->quoteIdentifier('max_' . strtolower($column)));
            }
        }

        $where = ['(d.assigned_to = ? OR c.assigned_to = ?)'];
        $params = [$subjectUserId, $subjectUserId];
        if ($this->columnExists('deals', 'workspace_id') && $this->columnExists('contacts', 'workspace_id')) {
            $where[] = '(d.workspace_id = ? OR c.workspace_id = ?)';
            $params[] = $workspaceId;
            $params[] = $workspaceId;
        }

        try {
            $row = Database::queryOne(
                'SELECT ' . implode(', ', $selects) . '
                 FROM deal_automation_audit daa
                 LEFT JOIN deals d ON d.id = daa.deal_id
                 LEFT JOIN contacts c ON c.id = COALESCE(daa.contact_id, d.contact_id)
                 WHERE ' . implode(' AND ', $where),
                $params
            ) ?: [];
        } catch (\Throwable $e) {
            return ['exists' => true, 'error' => get_class($e)];
        }

        ksort($row);
        return ['exists' => true] + $row;
    }

    private function workspaceScopeClause(string $table, int $workspaceId): array
    {
        if ($this->columnExists($table, 'workspace_id')) {
            return ['workspace_id = ?', [$workspaceId]];
        }

        return ['', []];
    }

    private function readFileCache(string $cacheKey): ?array
    {
        $path = $this->fileCachePath($cacheKey);
        if (!is_file($path)) {
            return null;
        }

        $contents = @file_get_contents($path);
        if (!is_string($contents) || trim($contents) === '') {
            return null;
        }

        $decoded = json_decode($contents, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function writeFileCache(string $cacheKey, array $payload): void
    {
        if (!is_dir($this->fileCacheDirectory) && !@mkdir($this->fileCacheDirectory, 0755, true) && !is_dir($this->fileCacheDirectory)) {
            return;
        }

        $path = $this->fileCachePath($cacheKey);
        $tmpPath = $path . '.' . uniqid('tmp', true);
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            return;
        }

        if (@file_put_contents($tmpPath, $encoded, LOCK_EX) === false) {
            return;
        }

        @rename($tmpPath, $path);
        if (is_file($tmpPath)) {
            @unlink($tmpPath);
        }
    }

    private function fileCachePath(string $cacheKey): string
    {
        return rtrim($this->fileCacheDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . sha1($cacheKey) . '.json';
    }

    private function quoteIdentifier(string $identifier): string
    {
        if (!$this->isSafeIdentifier($identifier)) {
            throw new \InvalidArgumentException('Unsafe SQL identifier.');
        }

        return '`' . $identifier . '`';
    }

    private function isSafeIdentifier(string $identifier): bool
    {
        return preg_match('/^[A-Za-z0-9_]+$/', $identifier) === 1;
    }

    private function scoreBooleans(array $checks): int
    {
        if ($checks === []) {
            return 0;
        }

        $passed = 0;
        foreach ($checks as $value) {
            if ($value) {
                $passed++;
            }
        }

        return (int) round(($passed / count($checks)) * 100);
    }

    private function tableExists(string $table): bool
    {
        return Database::tableExists($table);
    }

    private function columnExists(string $table, string $column): bool
    {
        return Database::columnExists($table, $column);
    }

    private function scoreBucket(int $score): string
    {
        return match (true) {
            $score >= 95 => 'full',
            $score >= 75 => 'high',
            $score >= 45 => 'medium',
            default => 'low',
        };
    }

    private function formatModeLabel(string $mode, string $fallback): string
    {
        if ($mode === '') {
            return $fallback;
        }
        return ucwords(str_replace('_', ' ', $mode));
    }

    private function buildLayerSummary(string $base, array $blockers): string
    {
        if ($blockers === []) {
            return $base;
        }

        return $base . ' Main blocker: ' . rtrim((string) $blockers[0], '.') . '.';
    }

    private function buildHeadlineLabel(array $setupLayer, array $layers): string
    {
        if (!$setupLayer['is_complete']) {
            return 'Finish Setup';
        }

        $scoredLayers = array_values(array_filter($layers, fn(array $layer): bool => $this->isLayerCounted($layer)));
        $fullishLayers = 0;
        foreach ($scoredLayers as $layer) {
            if (($layer['score'] ?? 0) >= 75) {
                $fullishLayers++;
            }
        }

        if ($scoredLayers !== [] && $fullishLayers === count($scoredLayers)) {
            return 'Full Auto In Reach';
        }
        if ($fullishLayers >= max(1, (int) ceil(count($scoredLayers) / 2))) {
            return 'Auto Mode Building';
        }
        return 'Suggest To Auto';
    }

    private function buildSummary(array $setupLayer, array $namedLayers, array $activeLayers): string
    {
        if (!$setupLayer['is_complete']) {
            return 'Complete the remaining foundations before automation can run reliably.';
        }

        $segments = [];
        foreach ($namedLayers as $label => $layer) {
            if (!$this->isLayerCounted($layer)) {
                continue;
            }
            $segments[] = sprintf('%s is %s', $label, strtolower((string) ($layer['status_label'] ?? 'warming up')));
        }

        if ($segments === []) {
            return 'Setup is unlocked. No active automation domains are enabled in Admin right now, so disabled domains are not counted in readiness.';
        }

        $summary = 'Setup is unlocked. ' . $this->joinSummarySegments($segments) . '.';
        if (empty($activeLayers['commercial'])) {
            $summary .= ' Commercial automation is disabled in Admin, so it is not counted right now.';
        }

        return $summary;
    }

    private function getScoredLayerActivity(): array
    {
        $autoresponder = $this->autoResponderConfig->get();
        $commercial = $this->commercialConfig->get();
        $deal = $this->dealConfig->get();

        return [
            'autoresponder' => !empty($autoresponder['enabled']) && (string) ($autoresponder['mode'] ?? 'draft_only') !== 'off',
            'commercial' => !empty($commercial['enabled']),
            'workflow_automation' => true,
            'deal' => !empty($deal['enabled']),
        ];
    }

    private function getUserSetupReadiness(int $subjectUserId): array
    {
        $readiness = $this->smartTemplateContext->getReadiness($subjectUserId);
        $sections = (array) ($readiness['sections'] ?? []);

        return [
            'strategy_profile_ready' => !empty($sections['strategy_profile']['is_ready']),
            'idea_validation_ready' => !empty($sections['idea_validation']['is_ready']),
        ];
    }

    private function buildInactiveLayer(string $label): array
    {
        return [
            'label' => $label,
            'score' => 50,
            'bucket' => 'medium',
            'status_label' => 'Disabled in Admin',
            'detail_value' => 'Not in scope',
            'summary' => 'This automation is turned off in Admin, so it is not counted toward automation readiness or battery progress.',
            'blockers' => [],
            'signals' => ['Disabled in Admin'],
            'counted_in_score' => false,
        ];
    }

    private function isLayerCounted(array $layer): bool
    {
        return !array_key_exists('counted_in_score', $layer) || !empty($layer['counted_in_score']);
    }

    private function computeWeightedScore(array $weightedLayers): int
    {
        $weightedTotal = 0.0;
        $weightSum = 0.0;
        foreach ($weightedLayers as $item) {
            $layer = (array) ($item['layer'] ?? []);
            if (!$this->isLayerCounted($layer)) {
                continue;
            }
            $weight = (float) ($item['weight'] ?? 0);
            $weightedTotal += ((float) ($layer['score'] ?? 0)) * $weight;
            $weightSum += $weight;
        }

        if ($weightSum <= 0.0) {
            return 0;
        }

        return (int) round($weightedTotal / $weightSum);
    }

    private function joinSummarySegments(array $segments): string
    {
        if ($segments === []) {
            return '';
        }
        if (count($segments) === 1) {
            return $segments[0];
        }
        if (count($segments) === 2) {
            return $segments[0] . ' and ' . $segments[1];
        }

        $last = array_pop($segments);
        return implode(', ', $segments) . ', and ' . $last;
    }
}
