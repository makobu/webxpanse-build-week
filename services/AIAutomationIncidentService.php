<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\AlertingSystem;
use CRM\Modules\UserPreferences;

class AIAutomationIncidentService
{
    private const ALERT_TYPE = 'ai_automation_incident';
    private const DEFAULT_WINDOW_DAYS = 1;
    private const HEALTHY_RUNS_TO_RESOLVE = 2;
    private const INCIDENT_RULES = [
        'assistant_blocked_spike' => ['category' => 'ai_decision_quality', 'severity' => 'high', 'threshold' => 10, 'window_hours' => 24],
        'coach_clarity_blocked_spike' => ['category' => 'ai_decision_quality', 'severity' => 'medium', 'threshold' => 15, 'window_hours' => 24],
        'approval_required_spike' => ['category' => 'commercial_policy_pressure', 'severity' => 'medium', 'threshold' => 12, 'window_hours' => 24],
        'workflow_failure_spike' => ['category' => 'workflow_reliability', 'severity' => 'high', 'threshold' => 15, 'window_hours' => 24],
        'workflow_retry_medium' => ['category' => 'workflow_reliability', 'severity' => 'medium', 'threshold' => 25],
        'workflow_retry_high' => ['category' => 'workflow_reliability', 'severity' => 'high', 'threshold' => 50],
    ];

    private AIAutomationDiagnosticsService $diagnostics;
    private AIPromptQualityService $promptQuality;
    private AutomationJobHealthService $jobHealth;
    private AIRuntimeControlService $runtimeControls;
    private AIConfidenceCalibrationService $calibration;
    private AlertingSystem $alerting;
    private UserPreferences $preferences;
    private AIWorkspaceScopeService $workspaceScope;

    public function __construct(
        ?AIAutomationDiagnosticsService $diagnostics = null,
        ?AIPromptQualityService $promptQuality = null,
        ?AutomationJobHealthService $jobHealth = null,
        ?AIRuntimeControlService $runtimeControls = null,
        ?AIConfidenceCalibrationService $calibration = null,
        ?AlertingSystem $alerting = null,
        ?UserPreferences $preferences = null,
        ?AIWorkspaceScopeService $workspaceScope = null
    ) {
        $this->diagnostics = $diagnostics ?? new AIAutomationDiagnosticsService();
        $this->promptQuality = $promptQuality ?? new AIPromptQualityService();
        $this->jobHealth = $jobHealth ?? new AutomationJobHealthService();
        $this->runtimeControls = $runtimeControls ?? new AIRuntimeControlService();
        $this->calibration = $calibration ?? new AIConfidenceCalibrationService();
        $this->alerting = $alerting ?? new AlertingSystem();
        $this->preferences = $preferences ?? new UserPreferences();
        $this->workspaceScope = $workspaceScope ?? new AIWorkspaceScopeService();
    }

    public function evaluateAndAlert(array $options = []): array
    {
        $this->ensureIncidentTableExists();

        if (!$this->preferences->isAIIncidentCheckEnabled($this->configUserId())) {
            return ['signals' => [], 'incidents' => [], 'alerts_created' => [], 'skipped' => ['incident_checks_disabled']];
        }

        $signals = $this->collectSignals($options);
        $incidents = $this->detectIncidents($signals);
        $alertsCreated = [];

        foreach ($incidents as $incident) {
            $alertId = $this->emitIncidentAlert($incident);
            if ($alertId !== null) {
                $alertsCreated[] = $alertId;
            }
        }

        $this->resolveRecoveredIncidents($incidents);

        return [
            'signals' => $signals,
            'incidents' => $incidents,
            'alerts_created' => $alertsCreated,
            'skipped' => [],
        ];
    }

    public function collectSignals(array $options = []): array
    {
        $dateFrom = (string) ($options['date_from'] ?? date('Y-m-d', strtotime('-' . self::DEFAULT_WINDOW_DAYS . ' day')));
        $dateTo = (string) ($options['date_to'] ?? date('Y-m-d'));
        $filters = ['date_from' => $dateFrom, 'date_to' => $dateTo, 'limit' => 200];

        return [
            'filters' => $filters,
            'events' => $this->diagnostics->getRecentEvents($filters),
            'summary' => $this->diagnostics->getSummary($filters),
            'top_blockers' => $this->diagnostics->getTopBlockers($filters),
            'context_health' => $this->diagnostics->getContextHealth($filters),
            'job_health_summary' => $this->jobHealth->getSummary(),
            'job_health' => $this->jobHealth->getJobs(),
            'prompt_summary' => $this->promptQuality->getActivePromptSummary(),
            'prompt_comparisons' => $this->buildPromptComparisons($dateFrom, $dateTo),
            'runtime_controls' => $this->runtimeControls->getAllEffectiveControls(),
            'calibration_summary' => $this->calibration->getCalibrationSummary(['date_from' => $dateFrom, 'date_to' => $dateTo]),
            'calibration_job' => $this->jobHealth->getJob('ai_confidence_calibration'),
            'incident_job' => $this->jobHealth->getJob('ai_incident_check'),
        ];
    }

    public function getIncidents(array $filters = []): array
    {
        if (!$this->tableExists('ai_incident_state')) {
            return [];
        }

        $sql = "SELECT * FROM ai_incident_state";
        $where = [];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 'status = ?';
            $params[] = (string) $filters['status'];
        }
        if (!empty($filters['incident_key'])) {
            $where[] = 'incident_key = ?';
            $params[] = (string) $filters['incident_key'];
        }
        if (!empty($filters['severity'])) {
            $where[] = 'severity = ?';
            $params[] = (string) $filters['severity'];
        }

        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY last_detected_at DESC, id DESC';
        if (!empty($filters['limit'])) {
            $sql .= ' LIMIT ' . max(1, min(200, (int) $filters['limit']));
        }

        return array_map(function (array $row): array {
            $row['metadata'] = $this->decodeJson($row['metadata_json'] ?? null);
            return $row;
        }, Database::query($sql, $params));
    }

    public function getActiveIncidents(): array
    {
        return $this->getIncidents(['status' => 'active']);
    }

    public function getRules(): array
    {
        return [
            'enabled' => $this->preferences->isAIIncidentAlertsEnabled($this->configUserId()),
            'check_enabled' => $this->preferences->isAIIncidentCheckEnabled($this->configUserId()),
            'cooldowns' => [
                'medium' => $this->preferences->getAIIncidentMediumCooldownMinutes($this->configUserId()),
                'high' => $this->preferences->getAIIncidentHighCooldownMinutes($this->configUserId()),
                'critical' => $this->preferences->getAIIncidentCriticalCooldownMinutes($this->configUserId()),
            ],
            'thresholds' => self::INCIDENT_RULES,
        ];
    }

    public function detectIncidents(array $signals): array
    {
        $incidents = [];
        $events = (array) ($signals['events'] ?? []);
        $summary = (array) ($signals['summary'] ?? []);
        $runtimeControls = (array) ($signals['runtime_controls'] ?? []);
        $promptComparisons = (array) ($signals['prompt_comparisons'] ?? []);
        $promptSummary = (array) ($signals['prompt_summary'] ?? []);
        $jobHealth = (array) ($signals['job_health'] ?? []);
        $contextHealth = (array) ($signals['context_health'] ?? []);
        $calibrationJob = (array) ($signals['calibration_job'] ?? []);

        $assistantBlocked = $this->countEvents($events, 'assistant', 'blocked');
        if ($assistantBlocked >= self::INCIDENT_RULES['assistant_blocked_spike']['threshold']) {
            $incidents[] = $this->buildIncident([
                'incident_key' => 'assistant_blocked_spike',
                'category' => self::INCIDENT_RULES['assistant_blocked_spike']['category'],
                'severity' => self::INCIDENT_RULES['assistant_blocked_spike']['severity'],
                'title' => 'Assistant blocked-action spike detected',
                'message' => 'Blocked assistant actions exceeded threshold in the last 24 hours.',
                'reason_codes' => $this->topReasonCodesFor($events, 'assistant', 'blocked'),
                'metrics' => ['blocked_count' => $assistantBlocked, 'threshold' => self::INCIDENT_RULES['assistant_blocked_spike']['threshold'], 'window_hours' => 24],
                'linked_filters' => ['source' => 'assistant', 'decision' => 'blocked'],
                'surface' => 'assistant',
            ]);
        }

        foreach (['coach', 'clarity_chat'] as $surface) {
            $blockedCount = $this->countEvents($events, $surface, null, ['blocked', 'suggest_only']);
            $sampleCount = $this->countEvents($events, $surface);
            $rate = $sampleCount > 0 ? $blockedCount / $sampleCount : 0.0;
            if ($blockedCount >= self::INCIDENT_RULES['coach_clarity_blocked_spike']['threshold'] || ($sampleCount >= 20 && $rate > 0.40)) {
                $incidents[] = $this->buildIncident([
                    'incident_key' => $surface . '_blocked_spike',
                    'category' => self::INCIDENT_RULES['coach_clarity_blocked_spike']['category'],
                    'severity' => $sampleCount >= 20 && $rate > 0.40 ? 'high' : self::INCIDENT_RULES['coach_clarity_blocked_spike']['severity'],
                    'title' => ucfirst(str_replace('_', ' ', $surface)) . ' downgrade spike detected',
                    'message' => ucfirst(str_replace('_', ' ', $surface)) . ' blocked or suggest-only responses exceeded threshold.',
                    'reason_codes' => $this->topReasonCodesFor($events, $surface, null, ['blocked', 'suggest_only']),
                    'metrics' => ['downgraded_count' => $blockedCount, 'sample_size' => $sampleCount, 'downgraded_rate' => round($rate, 4), 'threshold' => self::INCIDENT_RULES['coach_clarity_blocked_spike']['threshold']],
                    'linked_filters' => ['source' => $surface],
                    'surface' => $surface,
                ]);
            }
        }

        $approvalCount = (int) ($summary['approval_required'] ?? 0);
        $commercialEvents = array_values(array_filter($events, static fn(array $event): bool => ($event['source'] ?? '') === 'commercial'));
        $approvalRate = count($commercialEvents) > 0 ? ($approvalCount / count($commercialEvents)) : 0.0;
        if ($approvalCount >= self::INCIDENT_RULES['approval_required_spike']['threshold'] || (count($commercialEvents) >= 20 && $approvalRate > 0.50)) {
            $incidents[] = $this->buildIncident([
                'incident_key' => 'approval_required_spike',
                'category' => self::INCIDENT_RULES['approval_required_spike']['category'],
                'severity' => count($commercialEvents) >= 20 && $approvalRate > 0.50 ? 'high' : self::INCIDENT_RULES['approval_required_spike']['severity'],
                'title' => 'Commercial approval spike detected',
                'message' => 'Approval-required commercial decisions exceeded threshold in the last 24 hours.',
                'reason_codes' => $this->topReasonCodesFor($events, 'commercial', 'approval_required'),
                'metrics' => ['approval_required_count' => $approvalCount, 'approval_required_rate' => round($approvalRate, 4), 'threshold' => self::INCIDENT_RULES['approval_required_spike']['threshold'], 'sample_size' => count($commercialEvents)],
                'linked_filters' => ['source' => 'commercial', 'decision' => 'approval_required'],
                'surface' => 'commercial',
            ]);
        }

        foreach ($promptComparisons as $comparison) {
            $regressionRisk = (string) ($comparison['regression_risk'] ?? 'low');
            $qualityDelta = (float) ($comparison['deltas']['quality_delta'] ?? 0.0);
            if ($regressionRisk === 'high' || $qualityDelta <= -0.15) {
                $incidents[] = $this->buildIncident([
                    'incident_key' => 'prompt_regression',
                    'category' => 'prompt_quality',
                    'severity' => 'high',
                    'title' => 'Prompt regression detected',
                    'message' => 'An active prompt version is showing regression risk against the previous version.',
                    'reason_codes' => array_values(array_unique(array_merge(['prompt_regression'], (array) ($comparison['regression_signals'] ?? [])))),
                    'metrics' => ['regression_risk' => $regressionRisk, 'quality_delta' => $qualityDelta, 'primary_version' => $comparison['primary_version'] ?? null, 'compare_version' => $comparison['compare_version'] ?? null],
                    'linked_filters' => ['source' => (string) ($comparison['surface'] ?? ''), 'prompt_key' => (string) ($comparison['prompt_key'] ?? '')],
                    'surface' => (string) ($comparison['surface'] ?? ''),
                    'prompt_key' => (string) ($comparison['prompt_key'] ?? ''),
                ]);
            }
        }

        foreach ($promptSummary as $prompt) {
            $staleRate = (float) ($prompt['stale_context_rate'] ?? 0.0);
            $overloadRate = (float) ($prompt['overload_rate'] ?? 0.0);
            if ($staleRate > 0.20) {
                $incidents[] = $this->buildIncident([
                    'incident_key' => 'stale_context_spike',
                    'category' => 'context_quality',
                    'severity' => 'medium',
                    'title' => 'Stale context spike detected',
                    'message' => 'Stale context warnings exceeded threshold for an active prompt.',
                    'reason_codes' => ['stale_context'],
                    'metrics' => ['stale_context_rate' => $staleRate, 'recent_run_count' => (int) ($prompt['recent_run_count'] ?? 0)],
                    'linked_filters' => ['source' => (string) $prompt['surface'], 'prompt_key' => (string) $prompt['prompt_key']],
                    'surface' => (string) $prompt['surface'],
                    'prompt_key' => (string) $prompt['prompt_key'],
                ]);
            }
            if ($overloadRate > 0.20) {
                $incidents[] = $this->buildIncident([
                    'incident_key' => 'prompt_overload_spike',
                    'category' => 'context_quality',
                    'severity' => 'medium',
                    'title' => 'Prompt overload spike detected',
                    'message' => 'Prompt overload or bundle trimming warnings exceeded threshold for an active prompt.',
                    'reason_codes' => ['prompt_overload'],
                    'metrics' => ['overload_rate' => $overloadRate, 'recent_run_count' => (int) ($prompt['recent_run_count'] ?? 0)],
                    'linked_filters' => ['source' => (string) $prompt['surface'], 'prompt_key' => (string) $prompt['prompt_key']],
                    'surface' => (string) $prompt['surface'],
                    'prompt_key' => (string) $prompt['prompt_key'],
                ]);
            }
        }

        $this->appendJobIncidents($incidents, $jobHealth);
        $this->appendWorkflowIncidents($incidents, $summary);
        $this->appendCapabilityIncidents($incidents, $events, $contextHealth);
        $this->appendTuningIncidents($incidents, $signals, $runtimeControls, $calibrationJob);

        return array_values($this->deduplicateIncidents($incidents));
    }

    public function emitIncidentAlert(array $incident): ?int
    {
        if (!$this->preferences->isAIIncidentAlertsEnabled($this->configUserId())) {
            $this->upsertIncidentState($incident, null, 'suppressed');
            return null;
        }

        $existing = $this->getActiveIncidentState((string) $incident['incident_key'], (string) $incident['fingerprint']);
        $severity = (string) ($incident['severity'] ?? 'medium');
        $cooldownUntil = $this->buildCooldown($severity);

        if ($existing) {
            $cooldownExpired = empty($existing['cooldown_until']) || strtotime((string) $existing['cooldown_until']) <= time();
            if (!$cooldownExpired && !$this->isEscalation((string) ($existing['severity'] ?? 'medium'), $severity)) {
                Database::execute(
                    "UPDATE ai_incident_state SET last_detected_at = NOW(), metadata_json = ? WHERE id = ?",
                    [json_encode(array_merge($this->decodeJson($existing['metadata_json'] ?? null), ['healthy_passes' => 0, 'last_payload' => $incident])), (int) $existing['id']]
                );
                return null;
            }
        }

        $alertId = $this->alerting->createAlert(
            self::ALERT_TYPE,
            $severity,
            (string) $incident['title'],
            (string) $incident['message'],
            $this->buildIncidentMetadata($incident)
        );

        $this->upsertIncidentState($incident, $alertId, 'active', $cooldownUntil);
        return $alertId;
    }

    public function buildIncidentMetadata(array $incident): array
    {
        $query = http_build_query(array_filter((array) ($incident['linked_filters'] ?? []), static fn($value): bool => $value !== null && $value !== ''));
        return [
            'source' => 'ai_automation',
            'incident_key' => $incident['incident_key'] ?? '',
            'fingerprint' => $incident['fingerprint'] ?? '',
            'severity' => $incident['severity'] ?? 'medium',
            'reason_codes' => array_values(array_unique((array) ($incident['reason_codes'] ?? []))),
            'linked_filters' => (array) ($incident['linked_filters'] ?? []),
            'diagnostics_url' => publicUrl('ai_automation_diagnostics.php' . ($query !== '' ? '?' . $query : '')),
            'incident_url' => publicUrl('ai_incidents.php'),
            'metrics' => (array) ($incident['metrics'] ?? []),
            'runtime_control_mode' => $this->runtimeControls->getEffectiveControl((string) ($incident['surface'] ?? 'global'))['control_mode'] ?? 'normal',
        ];
    }

    public function buildIncidentFingerprint(array $incident): string
    {
        $parts = [(string) ($incident['incident_key'] ?? 'unknown')];
        foreach (['surface', 'prompt_key', 'job_key'] as $field) {
            if (!empty($incident[$field])) {
                $parts[] = (string) $incident[$field];
            }
        }
        return implode(':', $parts);
    }

    private function appendJobIncidents(array &$incidents, array $jobHealth): void
    {
        foreach (array_values(array_filter($jobHealth, static fn(array $job): bool => ($job['status'] ?? '') === 'failed')) as $job) {
            $incidents[] = $this->buildIncident([
                'incident_key' => 'job_failure',
                'category' => 'job_health',
                'severity' => 'high',
                'title' => 'Automation job failure detected',
                'message' => 'A core AI or automation scheduled job reported failure.',
                'reason_codes' => ['job_failure', (string) ($job['job_key'] ?? 'unknown_job')],
                'metrics' => ['job_key' => $job['job_key'] ?? null, 'derived_status' => $job['derived_status'] ?? null],
                'linked_filters' => ['source' => 'job'],
                'job_key' => (string) ($job['job_key'] ?? ''),
            ]);
        }

        $staleJobs = array_values(array_filter($jobHealth, static fn(array $job): bool => ($job['derived_status'] ?? '') === 'stale'));
        foreach ($staleJobs as $job) {
            $incidents[] = $this->buildIncident([
                'incident_key' => count($staleJobs) >= 2 ? 'multiple_jobs_stale' : 'job_stale',
                'category' => 'job_health',
                'severity' => count($staleJobs) >= 2 ? 'critical' : 'high',
                'title' => count($staleJobs) >= 2 ? 'Multiple automation jobs are stale' : 'Automation job is stale',
                'message' => count($staleJobs) >= 2 ? 'Multiple core AI or automation scheduled jobs are stale.' : 'A core AI or automation scheduled job is stale.',
                'reason_codes' => ['job_stale', (string) ($job['job_key'] ?? 'unknown_job')],
                'metrics' => ['job_key' => $job['job_key'] ?? null, 'stale_job_count' => count($staleJobs)],
                'linked_filters' => ['source' => 'job'],
                'job_key' => (string) ($job['job_key'] ?? ''),
            ]);
        }
    }

    private function appendWorkflowIncidents(array &$incidents, array $summary): void
    {
        $workflowFailures = (int) ($summary['workflow_failures'] ?? 0);
        $workflowRetries = (int) ($summary['workflow_retries_pending'] ?? 0);

        if ($workflowFailures >= self::INCIDENT_RULES['workflow_failure_spike']['threshold']) {
            $incidents[] = $this->buildIncident([
                'incident_key' => 'workflow_failure_spike',
                'category' => 'workflow_reliability',
                'severity' => self::INCIDENT_RULES['workflow_failure_spike']['severity'],
                'title' => 'Workflow failure spike detected',
                'message' => 'Workflow failures exceeded threshold in the last 24 hours.',
                'reason_codes' => ['workflow_error'],
                'metrics' => ['workflow_failures' => $workflowFailures, 'threshold' => self::INCIDENT_RULES['workflow_failure_spike']['threshold']],
                'linked_filters' => ['source' => 'workflow', 'decision' => 'failed'],
                'surface' => 'workflow',
            ]);
        }

        if ($workflowRetries >= self::INCIDENT_RULES['workflow_retry_high']['threshold'] || $workflowRetries >= self::INCIDENT_RULES['workflow_retry_medium']['threshold']) {
            $high = $workflowRetries >= self::INCIDENT_RULES['workflow_retry_high']['threshold'];
            $incidents[] = $this->buildIncident([
                'incident_key' => 'workflow_retry_spike',
                'category' => 'workflow_reliability',
                'severity' => $high ? 'high' : 'medium',
                'title' => 'Workflow retry backlog detected',
                'message' => $high ? 'Workflow retries pending exceeded the high threshold.' : 'Workflow retries pending exceeded the threshold.',
                'reason_codes' => ['retry_exhausted'],
                'metrics' => ['workflow_retries_pending' => $workflowRetries, 'threshold' => $high ? self::INCIDENT_RULES['workflow_retry_high']['threshold'] : self::INCIDENT_RULES['workflow_retry_medium']['threshold']],
                'linked_filters' => ['source' => 'workflow', 'decision' => 'retry_pending'],
                'surface' => 'workflow',
            ]);
        }
    }

    private function appendCapabilityIncidents(array &$incidents, array $events, array $contextHealth): void
    {
        $capabilityMissingEvents = array_values(array_filter($events, static fn(array $event): bool => ($event['source'] ?? '') === 'capability' && ($event['decision'] ?? '') === 'blocked'));
        if (count($capabilityMissingEvents) < 3 && ((int) ($contextHealth['missing'] ?? 0)) < 3) {
            return;
        }

        $reasonCodes = ['feature_unready'];
        foreach ($capabilityMissingEvents as $event) {
            $reasonCodes = array_merge($reasonCodes, (array) ($event['reason_codes'] ?? []));
        }

        $incidents[] = $this->buildIncident([
            'incident_key' => 'capability_degradation',
            'category' => 'capability_health',
            'severity' => 'medium',
            'title' => 'Capability degradation spike detected',
            'message' => 'Missing or degraded AI capabilities exceeded threshold.',
            'reason_codes' => array_values(array_unique($reasonCodes)),
            'metrics' => ['missing_capability_count' => count($capabilityMissingEvents), 'context_health_missing' => (int) ($contextHealth['missing'] ?? 0)],
            'linked_filters' => ['source' => 'capability'],
            'surface' => 'capability',
        ]);
    }

    private function appendTuningIncidents(array &$incidents, array $signals, array $runtimeControls, array $calibrationJob): void
    {
        $globalControlMode = (string) (($runtimeControls['global']['control_mode'] ?? 'normal'));
        $autonomousTuningControlMode = (string) (($runtimeControls['autonomous_tuning']['control_mode'] ?? 'normal'));

        if (!$this->preferences->isAIAutonomousThresholdTuningEnabled($this->configUserId()) && $globalControlMode === 'normal' && $autonomousTuningControlMode === 'normal') {
            $incidents[] = $this->buildIncident([
                'incident_key' => 'autonomous_tuning_disabled',
                'category' => 'calibration_health',
                'severity' => 'medium',
                'title' => 'Autonomous tuning disabled unexpectedly',
                'message' => 'Autonomous threshold tuning is disabled while runtime controls are normal.',
                'reason_codes' => ['autonomous_tuning_disabled'],
                'metrics' => [],
                'linked_filters' => ['source' => 'job'],
                'surface' => 'autonomous_tuning',
            ]);
        }

        if ($autonomousTuningControlMode === 'normal' && (($calibrationJob['derived_status'] ?? '') === 'stale' || ($calibrationJob['status'] ?? '') === 'failed')) {
            $incidents[] = $this->buildIncident([
                'incident_key' => 'calibration_stale',
                'category' => 'calibration_health',
                'severity' => 'high',
                'title' => 'Calibration job stale or failed',
                'message' => 'Confidence calibration is stale or failed while autonomous tuning is expected to run.',
                'reason_codes' => ['calibration_stale'],
                'metrics' => ['derived_status' => $calibrationJob['derived_status'] ?? null, 'status' => $calibrationJob['status'] ?? null],
                'linked_filters' => ['source' => 'job'],
                'surface' => 'autonomous_tuning',
            ]);
        }

        $recentChanges = (array) ($signals['calibration_summary']['recent_changes'] ?? []);
        $changeCount24h = count(array_filter($recentChanges, static fn(array $row): bool => strtotime((string) ($row['created_at'] ?? '')) >= strtotime('-24 hours')));
        if ($changeCount24h > 5) {
            $incidents[] = $this->buildIncident([
                'incident_key' => 'threshold_change_spike',
                'category' => 'calibration_health',
                'severity' => 'medium',
                'title' => 'Threshold change spike detected',
                'message' => 'Autonomous threshold tuning changed values more frequently than expected.',
                'reason_codes' => ['threshold_change_spike'],
                'metrics' => ['changes_in_24h' => $changeCount24h, 'threshold' => 5],
                'linked_filters' => ['source' => 'job'],
                'surface' => 'autonomous_tuning',
            ]);
        }
    }

    private function deduplicateIncidents(array $incidents): array
    {
        $deduped = [];
        foreach ($incidents as $incident) {
            $fingerprint = (string) ($incident['fingerprint'] ?? '');
            if ($fingerprint === '') {
                continue;
            }
            if (!isset($deduped[$fingerprint]) || $this->isEscalation((string) ($deduped[$fingerprint]['severity'] ?? 'medium'), (string) ($incident['severity'] ?? 'medium'))) {
                $deduped[$fingerprint] = $incident;
            }
        }
        return $deduped;
    }

    private function isEscalation(string $previous, string $current): bool
    {
        $weights = ['medium' => 1, 'high' => 2, 'critical' => 3];
        return ($weights[$current] ?? 0) > ($weights[$previous] ?? 0);
    }

    private function countEvents(array $events, ?string $source = null, ?string $decision = null, ?array $decisionSet = null): int
    {
        return count(array_filter($events, static function (array $event) use ($source, $decision, $decisionSet): bool {
            if ($source !== null && ($event['source'] ?? '') !== $source) {
                return false;
            }
            if ($decision !== null) {
                return ($event['decision'] ?? '') === $decision;
            }
            if ($decisionSet !== null) {
                return in_array((string) ($event['decision'] ?? ''), $decisionSet, true);
            }
            return true;
        }));
    }

    private function topReasonCodesFor(array $events, string $source, ?string $decision = null, ?array $decisionSet = null): array
    {
        $counts = [];
        foreach ($events as $event) {
            if (($event['source'] ?? '') !== $source) {
                continue;
            }
            $eventDecision = (string) ($event['decision'] ?? '');
            if ($decision !== null && $eventDecision !== $decision) {
                continue;
            }
            if ($decisionSet !== null && !in_array($eventDecision, $decisionSet, true)) {
                continue;
            }
            foreach ((array) ($event['reason_codes'] ?? []) as $code) {
                $counts[(string) $code] = ($counts[(string) $code] ?? 0) + 1;
            }
        }
        arsort($counts);
        return array_slice(array_keys($counts), 0, 3);
    }

    private function upsertIncidentState(array $incident, ?int $alertId, string $status = 'active', ?string $cooldownUntil = null): void
    {
        $existing = $this->getIncidentState((string) $incident['incident_key'], (string) $incident['fingerprint'], null);
        $metadata = array_merge((array) ($incident['metadata'] ?? []), $this->buildIncidentMetadata($incident), ['healthy_passes' => 0, 'last_payload' => $incident]);

        if ($existing) {
            Database::execute(
                "UPDATE ai_incident_state
                 SET status = ?, severity = ?, last_detected_at = NOW(), last_alert_id = COALESCE(?, last_alert_id), cooldown_until = COALESCE(?, cooldown_until), metadata_json = ?
                 WHERE id = ?",
                [$status, (string) ($incident['severity'] ?? 'medium'), $alertId, $cooldownUntil, json_encode($metadata), (int) $existing['id']]
            );
            return;
        }

        Database::execute(
            "INSERT INTO ai_incident_state
                (incident_key, fingerprint, status, severity, first_detected_at, last_detected_at, last_alert_id, cooldown_until, metadata_json)
             VALUES (?, ?, ?, ?, NOW(), NOW(), ?, ?, ?)",
            [(string) $incident['incident_key'], (string) $incident['fingerprint'], $status, (string) ($incident['severity'] ?? 'medium'), $alertId, $cooldownUntil, json_encode($metadata)]
        );
    }

    private function resolveRecoveredIncidents(array $activeIncidents): void
    {
        $activeFingerprints = array_fill_keys(array_map(static fn(array $incident): string => (string) ($incident['fingerprint'] ?? ''), $activeIncidents), true);
        foreach ($this->getIncidents(['status' => 'active']) as $state) {
            $fingerprint = (string) ($state['fingerprint'] ?? '');
            if (isset($activeFingerprints[$fingerprint])) {
                continue;
            }

            $metadata = (array) ($state['metadata'] ?? []);
            $healthyPasses = ((int) ($metadata['healthy_passes'] ?? 0)) + 1;
            $metadata['healthy_passes'] = $healthyPasses;

            Database::execute(
                "UPDATE ai_incident_state SET status = ?, metadata_json = ?, last_detected_at = NOW() WHERE id = ?",
                [$healthyPasses >= self::HEALTHY_RUNS_TO_RESOLVE ? 'resolved' : 'active', json_encode($metadata), (int) $state['id']]
            );
        }
    }

    private function getActiveIncidentState(string $incidentKey, string $fingerprint): ?array
    {
        return $this->getIncidentState($incidentKey, $fingerprint, 'active');
    }

    private function getIncidentState(string $incidentKey, string $fingerprint, ?string $status = null): ?array
    {
        if (!$this->tableExists('ai_incident_state')) {
            return null;
        }

        $sql = "SELECT * FROM ai_incident_state WHERE incident_key = ? AND fingerprint = ?";
        $params = [$incidentKey, $fingerprint];
        if ($status !== null) {
            $sql .= " AND status = ?";
            $params[] = $status;
        }
        $sql .= " ORDER BY id DESC LIMIT 1";

        return Database::queryOne($sql, $params) ?: null;
    }

    private function buildCooldown(string $severity): string
    {
        $minutes = match ($severity) {
            'critical' => $this->preferences->getAIIncidentCriticalCooldownMinutes($this->configUserId()),
            'high' => $this->preferences->getAIIncidentHighCooldownMinutes($this->configUserId()),
            default => $this->preferences->getAIIncidentMediumCooldownMinutes($this->configUserId()),
        };
        return date('Y-m-d H:i:s', strtotime('+' . max(1, $minutes) . ' minutes'));
    }

    private function configUserId(): int
    {
        return $this->workspaceScope->resolvePreferenceUserId();
    }

    private function buildPromptComparisons(string $dateFrom, string $dateTo): array
    {
        $comparisons = [];
        foreach ($this->promptQuality->getActivePromptSummary() as $prompt) {
            $surface = (string) ($prompt['surface'] ?? '');
            $promptKey = (string) ($prompt['prompt_key'] ?? '');
            $activeVersion = (int) ($prompt['version'] ?? 0);
            if ($surface === '' || $promptKey === '' || $activeVersion <= 1) {
                continue;
            }
            $comparisons[] = $this->promptQuality->getPromptVersionComparison($surface, $promptKey, $activeVersion, $activeVersion - 1, ['date_from' => $dateFrom, 'date_to' => $dateTo, 'limit' => 300]);
        }
        return $comparisons;
    }

    private function buildIncident(array $incident): array
    {
        $incident['fingerprint'] = $this->buildIncidentFingerprint($incident);
        return $incident;
    }

    private function tableExists(string $tableName): bool
    {
        $row = Database::queryOne(
            "SELECT COUNT(*) AS cnt FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?",
            [$tableName]
        );
        return ((int) ($row['cnt'] ?? 0)) > 0;
    }

    private function ensureIncidentTableExists(): void
    {
        if (!$this->tableExists('ai_incident_state')) {
            throw new \RuntimeException('ai_incident_state table is missing. Apply migration 119_create_ai_incident_state.sql.');
        }
    }

    private function decodeJson(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
