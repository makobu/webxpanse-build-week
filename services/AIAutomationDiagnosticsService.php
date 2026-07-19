<?php

namespace CRM\Services;

use CRM\Database;

class AIAutomationDiagnosticsService
{
    private AIDiagnosticsReasonMapper $reasonMapper;
    private CommercialAutomationApprovalService $approvalService;
    private AutomationJobHealthService $jobHealthService;
    private AIWorkspaceScopeService $workspaceScope;

    public function __construct(
        ?AIDiagnosticsReasonMapper $reasonMapper = null,
        ?CommercialAutomationApprovalService $approvalService = null,
        ?AutomationJobHealthService $jobHealthService = null
    ) {
        $this->reasonMapper = $reasonMapper ?? new AIDiagnosticsReasonMapper();
        $this->approvalService = $approvalService ?? new CommercialAutomationApprovalService();
        $this->jobHealthService = $jobHealthService ?? new AutomationJobHealthService();
        $this->workspaceScope = new AIWorkspaceScopeService();
    }

    public function getSummary(array $filters = []): array
    {
        $events = $this->collectEvents($filters, 250, 0);
        $summary = [
            'blocked_ai_actions' => 0,
            'approval_required' => 0,
            'workflow_failures' => 0,
            'workflow_retries_pending' => 0,
            'degraded_capabilities' => 0,
            'tasks_auto_completed' => 0,
        ];

        foreach ($events as $event) {
            if (in_array($event['source'], ['assistant', 'coach', 'clarity_chat', 'task_automation'], true)
                && $event['decision'] === 'blocked') {
                $summary['blocked_ai_actions']++;
            }
            if ($event['decision'] === 'approval_required') {
                $summary['approval_required']++;
            }
            if ($event['source'] === 'workflow' && $event['decision'] === 'failed') {
                $summary['workflow_failures']++;
            }
            if ($event['source'] === 'workflow' && $event['decision'] === 'retry_pending') {
                $summary['workflow_retries_pending']++;
            }
            if ($event['source'] === 'capability' && in_array($event['decision'], ['allow_with_warning', 'blocked'], true)) {
                $summary['degraded_capabilities']++;
            }
            if ($event['event_type'] === 'task_auto_completed') {
                $summary['tasks_auto_completed']++;
            }
        }

        return $summary;
    }

    public function getRecentEvents(array $filters = []): array
    {
        $limit = max(1, min(200, (int) ($filters['limit'] ?? 100)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));
        return $this->collectEvents($filters, $limit, $offset);
    }

    public function getCoachFeedbackInsights(array $filters = []): array
    {
        $blank = $this->blankCoachFeedbackInsights();
        if (!$this->tableExists('ai_advice_feedback')) {
            return $blank;
        }
        if (!empty($filters['source']) && (string) $filters['source'] !== 'coach') {
            return $blank;
        }

        $sql = "SELECT f.*
                FROM ai_advice_feedback f";
        $where = ["f.surface = 'coach'"];
        $params = [];
        if ($this->columnExists('ai_advice_feedback', 'workspace_id')) {
            $where[] = 'f.workspace_id = ?';
            $params[] = $this->workspaceScope->requireWorkspaceId();
        }
        if (!empty($filters['user_id'])) {
            $where[] = 'f.user_id = ?';
            $params[] = (int) $filters['user_id'];
        }
        if (!empty($filters['deal_id'])) {
            $where[] = 'f.linked_deal_id = ?';
            $params[] = (int) $filters['deal_id'];
        }
        if (!empty($filters['contact_id'])) {
            $where[] = 'f.linked_contact_id = ?';
            $params[] = (int) $filters['contact_id'];
        }
        if (!empty($filters['task_id'])) {
            $where[] = 'f.linked_task_id = ?';
            $params[] = (int) $filters['task_id'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'DATE(f.created_at) >= ?';
            $params[] = (string) $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'DATE(f.created_at) <= ?';
            $params[] = (string) $filters['date_to'];
        }
        $sql .= ' WHERE ' . implode(' AND ', $where) . ' ORDER BY f.created_at DESC LIMIT 500';

        $rows = Database::query($sql, $params);
        $signatureStats = [];
        $sourceTypeCounts = [];
        $sourceSectionCounts = [];
        $whySignalCounts = [];
        $trustSignalCounts = [];

        foreach ($rows as $row) {
            $type = (string) ($row['feedback_type'] ?? '');
            if (isset($blank['counts'][$type])) {
                $blank['counts'][$type]++;
            }
            $blank['total_feedback']++;

            $metadata = $this->decodeJson($row['metadata_json'] ?? null);
            $signature = trim((string) ($metadata['feedback_signature'] ?? ''));
            if ($signature === '') {
                $signature = trim((string) ($row['recommendation_key'] ?? ''));
            }
            $sourceType = trim((string) ($metadata['source_recommendation_type'] ?? 'coach_recommendation'));
            $sourceSection = trim((string) ($metadata['source_section'] ?? ''));
            $sourceType = $sourceType !== '' ? $sourceType : 'coach_recommendation';
            $sourceSection = $sourceSection !== '' ? $sourceSection : 'unknown';

            $sourceTypeCounts[$sourceType] = ($sourceTypeCounts[$sourceType] ?? 0) + 1;
            $sourceSectionCounts[$sourceSection] = ($sourceSectionCounts[$sourceSection] ?? 0) + 1;
            foreach ((array) ($metadata['why_signals'] ?? []) as $signal) {
                $label = trim((string) $signal);
                if ($label !== '') {
                    $whySignalCounts[$label] = ($whySignalCounts[$label] ?? 0) + 1;
                }
            }
            foreach ((array) ($metadata['trust_signals'] ?? []) as $signal) {
                if (!is_array($signal)) {
                    continue;
                }
                $label = trim((string) ($signal['label'] ?? ''));
                $value = trim((string) ($signal['value'] ?? ''));
                $key = trim($label . ($value !== '' ? ': ' . $value : ''));
                if ($key !== '') {
                    $trustSignalCounts[$key] = ($trustSignalCounts[$key] ?? 0) + 1;
                }
            }

            if ($signature === '') {
                continue;
            }
            if (!isset($signatureStats[$signature])) {
                $signatureStats[$signature] = [
                    'feedback_signature' => $signature,
                    'positive_count' => 0,
                    'negative_count' => 0,
                    'dismissed_count' => 0,
                    'acted_on_count' => 0,
                    'total_count' => 0,
                    'source_type' => $sourceType,
                    'source_section' => $sourceSection,
                    'latest_feedback_type' => $type,
                    'latest_event_id' => 'feedback:' . (int) ($row['id'] ?? 0),
                    'latest_at' => (string) ($row['created_at'] ?? ''),
                ];
            }
            $signatureStats[$signature]['total_count']++;
            if (in_array($type, ['useful', 'acted_on', 'already_done'], true)) {
                $signatureStats[$signature]['positive_count']++;
            }
            if (in_array($type, ['not_useful', 'dismissed'], true)) {
                $signatureStats[$signature]['negative_count']++;
            }
            if ($type === 'dismissed') {
                $signatureStats[$signature]['dismissed_count']++;
            }
            if ($type === 'acted_on') {
                $signatureStats[$signature]['acted_on_count']++;
            }
        }

        $blank['top_positive_signatures'] = $this->topCoachFeedbackSignatures($signatureStats, 'positive_count');
        $blank['top_negative_signatures'] = $this->topCoachFeedbackSignatures($signatureStats, 'negative_count');
        $blank['top_dismissed_signatures'] = $this->topCoachFeedbackSignatures($signatureStats, 'dismissed_count');
        $blank['top_acted_on_signatures'] = $this->topCoachFeedbackSignatures($signatureStats, 'acted_on_count');
        $blank['recent_adjusted_patterns'] = array_slice(array_values(array_filter(
            $signatureStats,
            static fn(array $row): bool => (int) ($row['positive_count'] ?? 0) > 0 || (int) ($row['negative_count'] ?? 0) > 0
        )), 0, 8);
        $blank['source_type_breakdown'] = $this->topCoachFeedbackCounts($sourceTypeCounts);
        $blank['source_section_breakdown'] = $this->topCoachFeedbackCounts($sourceSectionCounts);
        $blank['why_signal_breakdown'] = $this->topCoachFeedbackCounts($whySignalCounts);
        $blank['trust_signal_breakdown'] = $this->topCoachFeedbackCounts($trustSignalCounts);

        return $blank;
    }

    public function getCoachRecommendationControlSummary(array $filters = []): array
    {
        if (!empty($filters['source']) && (string) $filters['source'] !== 'coach') {
            return [
                'counts' => ['boosted' => 0, 'muted' => 0, 'reset_learning' => 0],
                'by_scope' => ['feedback_signature' => 0, 'source_type' => 0, 'source_section' => 0],
                'active_controls' => [],
                'total_controls' => 0,
            ];
        }

        return (new AICoachRecommendationControlService())->summary([
            'workspace_id' => $this->workspaceScope->requireWorkspaceId(),
        ]);
    }

    public function getTopBlockers(array $filters = []): array
    {
        $events = $this->collectEvents($filters, 300, 0);
        $counts = [];

        foreach ($events as $event) {
            $bucket = (string) ($event['reason_bucket'] ?? '');
            if ($bucket === '') {
                continue;
            }
            $counts[$bucket] = ($counts[$bucket] ?? 0) + 1;
        }

        arsort($counts);
        $output = [];
        foreach (array_slice($counts, 0, 5, true) as $bucket => $count) {
            $output[] = ['bucket' => $bucket, 'count' => $count];
        }

        return $output;
    }

    public function getRecordTimeline(array $filters = []): array
    {
        return $this->collectEvents($filters, 250, 0);
    }

    public function getContextHealth(array $filters = []): array
    {
        $summary = ['ready' => 0, 'degraded' => 0, 'missing' => 0];
        if (!$this->tableExists('ai_capability_state_log')) {
            return $summary;
        }

        foreach ($this->latestCapabilityLogs($filters) as $row) {
            $status = (string) ($row['status'] ?? '');
            if (isset($summary[$status])) {
                $summary[$status]++;
            }
        }

        return $summary;
    }

    public function getJobHealthSummary(): array
    {
        return $this->jobHealthService->getSummary();
    }

    public function getJobHealth(): array
    {
        return $this->jobHealthService->getJobs();
    }

    public function getMarketplaceRecommendationSummary(array $filters = []): array
    {
        $eventFilters = $this->marketplaceRecommendationFilters($filters);
        if ($eventFilters === null) {
            return $this->blankMarketplaceRecommendationSummary();
        }

        return (new WorkspaceMarketplaceRecommendationEventService())->getSummary($eventFilters);
    }

    public function getMarketplaceRecommendationEvents(array $filters = []): array
    {
        $eventFilters = $this->marketplaceRecommendationFilters($filters);
        if ($eventFilters === null) {
            return [];
        }

        return (new WorkspaceMarketplaceRecommendationEventService())->getEvents($eventFilters, 100);
    }

    public function getMarketplaceRecommendationInsights(array $filters = []): array
    {
        $eventFilters = $this->marketplaceRecommendationFilters($filters);
        if ($eventFilters === null) {
            return [];
        }

        return (new WorkspaceMarketplaceRecommendationInsightService())->getInsights($eventFilters);
    }

    public function getMarketplaceSetupJourneySummary(array $filters = []): array
    {
        $eventFilters = $this->marketplaceSetupJourneyFilters($filters);
        if ($eventFilters === null) {
            return $this->blankMarketplaceSetupJourneySummary();
        }

        return (new WorkspaceMarketplaceSetupJourneyEventService())->getSummary($eventFilters);
    }

    public function getMarketplaceSetupJourneyEvents(array $filters = []): array
    {
        $eventFilters = $this->marketplaceSetupJourneyFilters($filters);
        if ($eventFilters === null) {
            return [];
        }

        return (new WorkspaceMarketplaceSetupJourneyEventService())->getEvents($eventFilters, 100);
    }

    public function getMarketplaceAdaptiveSignalSummary(array $filters = []): array
    {
        $source = trim((string) ($filters['source'] ?? ''));
        if ($source !== '' && !in_array($source, ['marketplace_recommendation', 'marketplace_setup_journey'], true)) {
            return [
                'positive_boosts' => 0,
                'negative_dampening' => 0,
                'top_skills' => [],
                'reason_counts' => [],
                'total_signals' => 0,
            ];
        }

        $signalFilters = ['workspace_id' => $this->workspaceScope->requireWorkspaceId()];
        foreach (['user_id', 'skill_key', 'date_from', 'date_to'] as $key) {
            if (!empty($filters[$key])) {
                $signalFilters[$key] = $filters[$key];
            }
        }

        return (new WorkspaceMarketplaceRecommendationAdaptiveSignalService())->getSummary($signalFilters);
    }

    public function getMarketplaceRecommendationControlSummary(array $filters = []): array
    {
        $source = trim((string) ($filters['source'] ?? ''));
        if ($source !== '' && $source !== 'marketplace_recommendation') {
            return [
                'counts' => ['pinned' => 0, 'muted' => 0, 'surface_disabled' => 0],
                'by_surface' => [],
                'active_controls' => [],
                'total_controls' => 0,
            ];
        }

        return (new WorkspaceMarketplaceRecommendationControlService())->summary([
            'workspace_id' => $this->workspaceScope->requireWorkspaceId(),
        ]);
    }

    public function getMarketplaceActivationBundleSummary(array $filters = []): array
    {
        $source = trim((string) ($filters['source'] ?? ''));
        if ($source !== '' && !in_array($source, ['marketplace_recommendation', 'marketplace_activation_bundle'], true)) {
            return [
                'counts' => ['selected' => 0, 'dismissed' => 0, 'completed' => 0],
                'active_bundles' => [],
                'total_state_rows' => 0,
            ];
        }

        return (new WorkspaceMarketplaceActivationBundleService())->summary([
            'workspace_id' => $this->workspaceScope->requireWorkspaceId(),
            'user_id' => (int) ($filters['user_id'] ?? 0),
            'surface' => 'marketplace',
        ]);
    }

    public function getMarketplaceActivationBundleEventSummary(array $filters = []): array
    {
        $eventFilters = $this->marketplaceActivationBundleFilters($filters);
        if ($eventFilters === null) {
            return $this->blankMarketplaceActivationBundleEventSummary();
        }

        return (new WorkspaceMarketplaceActivationBundleEventService())->getSummary($eventFilters);
    }

    public function getMarketplaceActivationBundleEvents(array $filters = []): array
    {
        $eventFilters = $this->marketplaceActivationBundleFilters($filters);
        if ($eventFilters === null) {
            return [];
        }

        return (new WorkspaceMarketplaceActivationBundleEventService())->getEvents($eventFilters, 100);
    }

    public function getMarketplaceActivationBundleInsights(array $filters = []): array
    {
        $eventFilters = $this->marketplaceActivationBundleFilters($filters);
        if ($eventFilters === null) {
            return [];
        }

        return (new WorkspaceMarketplaceActivationBundleInsightService())->getInsights($eventFilters);
    }

    public function getMarketplaceActivationBundleAdaptiveSignalSummary(array $filters = []): array
    {
        $eventFilters = $this->marketplaceActivationBundleFilters($filters);
        if ($eventFilters === null) {
            return [
                'positive_boosts' => 0,
                'negative_dampening' => 0,
                'top_bundles' => [],
                'reason_counts' => [],
                'total_signals' => 0,
            ];
        }

        return (new WorkspaceMarketplaceActivationBundleAdaptiveSignalService())->getSummary($eventFilters);
    }

    public function getLinkedData(array $filters = []): array
    {
        $timeline = $this->getRecordTimeline($filters);
        $linked = [
            'approvals' => [],
            'assistant_runs' => [],
            'workflow_runs' => [],
            'task_evidence' => [],
            'orchestration_runs' => [],
        ];

        foreach ($timeline as $event) {
            if ($event['source'] === 'commercial' && isset($event['payload']['approval'])) {
                $linked['approvals'][] = $event['payload']['approval'];
            }
            if ($event['source'] === 'assistant' && isset($event['payload']['run'])) {
                $linked['assistant_runs'][] = $event['payload']['run'];
            }
            if (in_array($event['source'], ['coach', 'clarity_chat'], true) && isset($event['payload']['feedback'])) {
                $linked['assistant_runs'][] = $event['payload']['feedback'];
            }
            if ($event['source'] === 'workflow' && isset($event['payload']['workflow'])) {
                $linked['workflow_runs'][] = $event['payload']['workflow'];
            }
            if ($event['source'] === 'task_automation' && isset($event['payload']['evidence'])) {
                $linked['task_evidence'][] = $event['payload']['evidence'];
            }
            if ($event['source'] === 'orchestrator' && isset($event['payload']['run'])) {
                $linked['orchestration_runs'][] = $event['payload']['run'];
            }
        }

        return $linked;
    }

    private function collectEvents(array $filters, int $limit, int $offset): array
    {
        $events = array_merge(
            $this->normalizeAssistantRuns($filters),
            $this->normalizeGuidanceRuns($filters),
            $this->normalizeAdviceFeedback($filters),
            $this->normalizeDecisionOutcomes($filters),
            $this->normalizeIncidentState($filters),
            $this->normalizeCommercialRuns($filters),
            $this->normalizeApprovals($filters),
            $this->normalizeWorkflowNodeRuns($filters),
            $this->normalizeWorkflowRetries($filters),
            $this->normalizeCrossDomainRuns($filters),
            $this->normalizeCrossDomainIntakeEvents($filters),
            $this->normalizeCapabilityLogs($filters),
            $this->normalizeTaskEvidence($filters),
            $this->normalizePromptRegistryEvents($filters),
            $this->normalizeRuntimeControlLog($filters),
            $this->normalizeJobHealth($filters),
            $this->normalizeMarketplaceRecommendationEvents($filters),
            $this->normalizeMarketplaceSetupJourneyEvents($filters),
            $this->normalizeMarketplaceActivationBundleEvents($filters)
        );

        usort($events, static function (array $left, array $right): int {
            return strcmp((string) ($right['created_at'] ?? ''), (string) ($left['created_at'] ?? ''));
        });

        $filtered = array_values(array_filter($events, fn(array $event): bool => $this->matchesHighLevelFilters($event, $filters)));
        $filtered = $this->collapseCapabilityNoise($filtered);
        return array_slice($filtered, $offset, $limit);
    }

    private function normalizeIncidentState(array $filters): array
    {
        if (!$this->tableExists('ai_incident_state')) {
            return [];
        }

        $sql = "SELECT * FROM ai_incident_state";
        $where = [];
        $params = [];
        if (!empty($filters['date_from'])) {
            $where[] = 'DATE(last_detected_at) >= ?';
            $params[] = (string) $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'DATE(last_detected_at) <= ?';
            $params[] = (string) $filters['date_to'];
        }
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $rows = Database::query($sql, $params);
        $events = [];
        foreach ($rows as $row) {
            $metadata = $this->decodeJson($row['metadata_json'] ?? null);
            $event = [
                'id' => 'incident:' . (int) ($row['id'] ?? 0),
                'source' => 'incident',
                'event_type' => 'incident_' . (string) ($row['status'] ?? 'active'),
                'decision' => (string) ($row['status'] ?? 'active') === 'active' ? 'blocked' : 'allow_with_warning',
                'reason_codes' => array_values(array_filter((array) ($metadata['reason_codes'] ?? []))),
                'human_summary' => (string) (($metadata['incident_key'] ?? $row['incident_key'] ?? 'incident') . ' incident is ' . ($row['status'] ?? 'active') . '.'),
                'confidence_score' => null,
                'context_quality_score' => null,
                'goal_relevance_score' => null,
                'linked_records' => [
                    'deal_id' => $this->nullableInt($metadata['linked_filters']['deal_id'] ?? null),
                    'invoice_id' => $this->nullableInt($metadata['linked_filters']['invoice_id'] ?? null),
                    'contact_id' => $this->nullableInt($metadata['linked_filters']['contact_id'] ?? null),
                    'task_id' => $this->nullableInt($metadata['linked_filters']['task_id'] ?? null),
                ],
                'created_at' => (string) ($row['last_detected_at'] ?? ''),
                'payload' => [
                    'incident' => $row,
                    'metadata' => $metadata,
                ],
                'mode' => null,
            ];
            $event += $this->reasonMapper->map($event);
            $events[] = $event;
        }

        return $events;
    }

    private function normalizeAssistantRuns(array $filters): array
    {
        if (!$this->tableExists('email_assistant_runs')) {
            return [];
        }

        $rows = Database::query($this->buildBaseQuery('email_assistant_runs', $filters), $this->buildBaseParams('email_assistant_runs', $filters));
        $events = [];

        foreach ($rows as $row) {
            $plan = $this->decodeJson($row['plan_json'] ?? null);
            $result = $this->decodeJson($row['result_json'] ?? null);
            $policy = [];
            if (isset($result['policy']) && is_array($result['policy'])) {
                $policy = $result['policy'];
            } elseif (isset($plan['qualification_snapshot']) && is_array($plan['qualification_snapshot'])) {
                $policy = $plan['qualification_snapshot'];
            }

            $decision = (string) ($policy['decision'] ?? $result['policy_decision'] ?? $plan['policy_decision'] ?? 'allow');
            $event = [
                'id' => 'assistant:' . (int) $row['id'],
                'source' => 'assistant',
                'event_type' => 'assistant_' . ($decision !== '' ? $decision : 'event'),
                'decision' => $decision,
                'reason_codes' => array_values(array_filter(array_merge(
                    (array) ($policy['reasons'] ?? []),
                    (array) ($result['context_bundle_quality']['warnings'] ?? []),
                    (array) ($result['draft']['context_bundle_quality']['warnings'] ?? [])
                ))),
                'human_summary' => (string) ($result['summary_text'] ?? $row['intent'] ?? 'Assistant activity recorded.'),
                'confidence_score' => $this->nullableFloat($policy['confidence_score'] ?? null),
                'context_quality_score' => $this->nullableFloat($policy['context_quality_score'] ?? null),
                'goal_relevance_score' => $this->nullableFloat($policy['goal_relevance_score'] ?? null),
                'linked_records' => [
                    'deal_id' => $this->nullableInt($row['deal_id'] ?? null),
                    'invoice_id' => $this->nullableInt($row['invoice_id'] ?? null),
                    'contact_id' => $this->nullableInt($row['contact_id'] ?? null),
                    'task_id' => null,
                ],
                'created_at' => (string) ($row['created_at'] ?? ''),
                'payload' => [
                    'run' => $row,
                    'plan' => $plan,
                    'result' => $result,
                    'warnings' => array_values(array_filter((array) ($policy['warnings'] ?? []))),
                    'prompt_key' => $result['draft']['prompt_key'] ?? $result['prompt_key'] ?? null,
                    'prompt_version' => $result['draft']['prompt_version'] ?? $result['prompt_version'] ?? null,
                    'context_bundle_quality' => $result['draft']['context_bundle_quality'] ?? $result['context_bundle_quality'] ?? null,
                    'role_profile' => $result['role_profile'] ?? $plan['role_profile'] ?? null,
                    'role_summary' => $result['role_summary'] ?? $plan['role_summary'] ?? null,
                    'user_work_context_summary' => $result['user_work_context_summary'] ?? $plan['user_work_context_summary'] ?? null,
                    'role_threshold_recommendations' => $result['role_threshold_recommendations'] ?? $plan['role_threshold_recommendations'] ?? null,
                ],
                'mode' => $this->normalizeMode($policy['mode'] ?? null),
            ];
            $event += $this->reasonMapper->map($event);
            $events[] = $event;
        }

        return $events;
    }

    private function normalizeDecisionOutcomes(array $filters): array
    {
        if (!$this->tableExists('ai_decision_outcomes')) {
            return [];
        }

        $sql = "SELECT * FROM ai_decision_outcomes";
        $where = ['workspace_id = ?'];
        $params = [$this->workspaceScope->requireWorkspaceId()];
        foreach (['surface', 'decision_type', 'action_type', 'outcome_label'] as $field) {
            if (!empty($filters[$field]) && $this->columnExists('ai_decision_outcomes', $field)) {
                $where[] = $field . ' = ?';
                $params[] = (string) $filters[$field];
            }
        }
        if (!empty($filters['task_id'])) {
            $where[] = 'task_id = ?';
            $params[] = (int) $filters['task_id'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'DATE(measured_at) >= ?';
            $params[] = (string) $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'DATE(measured_at) <= ?';
            $params[] = (string) $filters['date_to'];
        }
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY measured_at DESC LIMIT 250';

        $rows = Database::query($sql, $params);
        $events = [];

        foreach ($rows as $row) {
            $metadata = $this->decodeJson($row['outcome_metadata_json'] ?? null);
            $hasSourceLink = !empty($row['guidance_run_id']) || !empty($row['assistant_run_id']) || !empty($row['commercial_run_id']) || !empty($row['approval_id']);
            $isLinkedTaskOutcome = (string) ($row['decision_type'] ?? '') === 'task' && $hasSourceLink;
            $isExplicitFeedback = !empty($metadata['feedback_id']);
            $isManualFollowThrough = !empty($metadata['manual_followthrough']);
            $isFilteredRecord = !empty($filters['deal_id']) || !empty($filters['invoice_id']) || !empty($filters['contact_id']) || !empty($filters['task_id']);

            if (!$isExplicitFeedback && !$isManualFollowThrough && !$isLinkedTaskOutcome && !$isFilteredRecord) {
                continue;
            }

            if (!$this->matchesOutcomeRecordFilters($row, $metadata, $filters)) {
                continue;
            }

            $eventType = $isManualFollowThrough ? 'manual_followthrough_recorded' : 'outcome_' . (string) ($row['outcome_label'] ?? 'recorded');
            $decision = match ((string) ($row['outcome_label'] ?? 'ignored')) {
                'accepted', 'approved', 'completed' => 'allow',
                'edited' => 'allow_with_warning',
                'failed' => 'failed',
                'ignored' => 'suggest_only',
                default => 'blocked',
            };
            $surface = (string) ($row['surface'] ?? 'assistant');
            $summary = $isManualFollowThrough
                ? 'Manual follow-through linked to prior AI guidance.'
                : 'AI outcome recorded as ' . (string) ($row['outcome_label'] ?? 'recorded') . '.';

            $event = [
                'id' => 'outcome:' . (int) $row['id'],
                'source' => $surface,
                'event_type' => $eventType,
                'decision' => $decision,
                'reason_codes' => array_values(array_filter(array_merge(
                    ['explicit_followthrough'],
                    !empty($metadata['feedback_id']) ? ['explicit_feedback'] : [],
                    !empty($metadata['manual_followthrough']) ? ['manual_followthrough'] : []
                ))),
                'human_summary' => $summary,
                'confidence_score' => $this->nullableFloat($row['predicted_confidence'] ?? null),
                'context_quality_score' => $this->nullableFloat($row['context_quality_score'] ?? null),
                'goal_relevance_score' => $this->nullableFloat($row['goal_relevance_score'] ?? null),
                'linked_records' => [
                    'deal_id' => $this->nullableInt($metadata['linked_deal_id'] ?? null),
                    'invoice_id' => $this->nullableInt($metadata['linked_invoice_id'] ?? null),
                    'contact_id' => $this->nullableInt($metadata['linked_contact_id'] ?? null),
                    'task_id' => $this->nullableInt($row['task_id'] ?? null),
                ],
                'created_at' => (string) ($row['measured_at'] ?? $row['created_at'] ?? ''),
                'payload' => [
                    'outcome' => $row,
                    'metadata' => $metadata,
                ],
                'mode' => null,
            ];
            $event += $this->reasonMapper->map($event);
            $events[] = $event;
        }

        return $events;
    }

    private function normalizeGuidanceRuns(array $filters): array
    {
        if (!$this->tableExists('ai_guidance_runs')) {
            return [];
        }

        $rows = Database::query($this->buildBaseQuery('ai_guidance_runs', $filters), $this->buildBaseParams('ai_guidance_runs', $filters));
        $events = [];

        foreach ($rows as $row) {
            $policy = $this->decodeJson($row['policy_snapshot_json'] ?? null);
            $output = $this->decodeJson($row['output_snapshot_json'] ?? null);
            $surface = (string) ($row['surface'] ?? 'coach');
            $policyRoleProfile = is_array($policy['role_profile'] ?? null) ? $policy['role_profile'] : [];
            $event = [
                'id' => 'guidance:' . (int) $row['id'],
                'source' => in_array($surface, ['coach', 'clarity_chat', 'task_automation', 'assistant'], true) ? $surface : 'coach',
                'event_type' => $surface . '_' . (string) ($row['decision'] ?? 'allow'),
                'decision' => (string) ($row['decision'] ?? 'allow'),
                'reason_codes' => array_values(array_filter(array_merge(
                    (array) ($policy['reasons'] ?? $output['reasons'] ?? []),
                    (array) ($output['context_bundle_quality']['warnings'] ?? []),
                    (array) ($policy['context_bundle_quality']['warnings'] ?? [])
                ))),
                'human_summary' => (string) ($output['summary'] ?? 'AI guidance decision recorded.'),
                'confidence_score' => $this->nullableFloat($row['confidence_score'] ?? null),
                'context_quality_score' => $this->nullableFloat($row['context_quality_score'] ?? null),
                'goal_relevance_score' => $this->nullableFloat($row['goal_relevance_score'] ?? null),
                'linked_records' => [
                    'deal_id' => $this->nullableInt($output['deal_id'] ?? null),
                    'invoice_id' => $this->nullableInt($output['invoice_id'] ?? null),
                    'contact_id' => $this->nullableInt($output['contact_id'] ?? null),
                    'task_id' => $this->nullableInt($output['task_id'] ?? null),
                ],
                'created_at' => (string) ($row['created_at'] ?? ''),
                'payload' => [
                    'guidance' => $row,
                    'policy' => $policy,
                    'output' => $output,
                    'prompt_key' => $output['prompt_key'] ?? $policy['prompt_key'] ?? null,
                    'prompt_version' => $output['prompt_version'] ?? $policy['prompt_version'] ?? null,
                    'context_bundle_quality' => $output['context_bundle_quality'] ?? $policy['context_bundle_quality'] ?? null,
                    'role_profile' => $output['role_profile'] ?? ($policy['role_profile'] ?? ($policyRoleProfile['role_profile'] ?? null)),
                    'role_summary' => $output['role_summary'] ?? ($policyRoleProfile['summary'] ?? null),
                    'user_work_context_summary' => $output['user_work_context_summary'] ?? ($policy['user_work_context_summary'] ?? null),
                    'role_threshold_recommendations' => $output['role_threshold_recommendations'] ?? ($policy['role_threshold_recommendations'] ?? null),
                ],
                'mode' => $this->normalizeMode($row['mode'] ?? null),
            ];
            $event += $this->reasonMapper->map($event);
            $events[] = $event;
        }

        return $events;
    }

    private function normalizePromptRegistryEvents(array $filters): array
    {
        if (!$this->tableExists('ai_prompt_registry')) {
            return [];
        }

        $sql = "SELECT * FROM ai_prompt_registry";
        $where = ['(workspace_id = ? OR workspace_id IS NULL)'];
        $params = [$this->workspaceScope->requireWorkspaceId()];
        if (!empty($filters['date_from'])) {
            $where[] = 'DATE(created_at) >= ?';
            $params[] = (string) $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'DATE(created_at) <= ?';
            $params[] = (string) $filters['date_to'];
        }
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY created_at DESC LIMIT 100';

        $rows = Database::query($sql, $params);
        $events = [];
        foreach ($rows as $row) {
            $metadata = $this->decodeJson($row['metadata_json'] ?? null) ?: [];
            $event = [
                'id' => 'prompt:' . (int) $row['id'],
                'source' => 'assistant',
                'event_type' => 'prompt_version_changed',
                'decision' => (string) ($row['status'] ?? 'active') === 'active' ? 'allow_with_warning' : 'allow',
                'reason_codes' => [
                    'prompt_version_changed',
                    !empty($metadata['legacy_inline']) ? 'legacy_inline' : 'registry_prompt',
                ],
                'human_summary' => sprintf(
                    'Prompt %s/%s v%d is %s.',
                    (string) ($row['surface'] ?? 'surface'),
                    (string) ($row['prompt_key'] ?? 'prompt'),
                    (int) ($row['version'] ?? 0),
                    (string) ($row['status'] ?? 'draft')
                ),
                'confidence_score' => null,
                'context_quality_score' => null,
                'goal_relevance_score' => null,
                'linked_records' => [
                    'deal_id' => null,
                    'invoice_id' => null,
                    'contact_id' => null,
                    'task_id' => null,
                ],
                'created_at' => (string) ($row['created_at'] ?? ''),
                'payload' => [
                    'prompt' => [
                        'surface' => $row['surface'] ?? null,
                        'prompt_key' => $row['prompt_key'] ?? null,
                        'version' => (int) ($row['version'] ?? 0),
                        'status' => $row['status'] ?? null,
                        'output_contract' => $this->decodeJson($row['output_contract_json'] ?? null),
                        'metadata' => $metadata,
                    ],
                ],
                'mode' => null,
            ];
            $event += $this->reasonMapper->map($event);
            $events[] = $event;
        }

        return $events;
    }

    private function normalizeRuntimeControlLog(array $filters): array
    {
        if (!$this->tableExists('ai_runtime_control_log')) {
            return [];
        }

        $sql = "SELECT * FROM ai_runtime_control_log";
        $where = ['workspace_id = ?'];
        $params = [$this->workspaceScope->requireWorkspaceId()];
        if (!empty($filters['date_from'])) {
            $where[] = 'DATE(set_at) >= ?';
            $params[] = (string) $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'DATE(set_at) <= ?';
            $params[] = (string) $filters['date_to'];
        }
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY set_at DESC LIMIT 100';

        $rows = Database::query($sql, $params);
        $events = [];
        foreach ($rows as $row) {
            $newMode = (string) ($row['new_mode'] ?? 'normal');
            $decision = match ($newMode) {
                'paused' => 'blocked',
                'suggest_only' => 'suggest_only',
                'diagnostics_only' => 'allow_with_warning',
                default => 'allow',
            };
            $event = [
                'id' => 'control:' . (int) $row['id'],
                'source' => 'control',
                'event_type' => 'control_changed',
                'decision' => $decision,
                'reason_codes' => ['operator_' . ($newMode === 'normal' ? 'restore' : $newMode)],
                'human_summary' => sprintf(
                    'Runtime control for %s changed from %s to %s.',
                    (string) ($row['surface'] ?? 'global'),
                    (string) ($row['previous_mode'] ?? 'normal'),
                    $newMode
                ),
                'confidence_score' => null,
                'context_quality_score' => null,
                'goal_relevance_score' => null,
                'linked_records' => [
                    'deal_id' => null,
                    'invoice_id' => null,
                    'contact_id' => null,
                    'task_id' => null,
                ],
                'created_at' => (string) ($row['set_at'] ?? ''),
                'payload' => [
                    'control' => $row,
                    'metadata' => $this->decodeJson($row['metadata_json'] ?? null),
                ],
                'mode' => null,
            ];
            $event += $this->reasonMapper->map($event);
            $events[] = $event;
        }

        return $events;
    }

    private function normalizeJobHealth(array $filters): array
    {
        $events = [];
        foreach ($this->jobHealthService->getJobs() as $job) {
            $createdAt = (string) ($job['updated_at'] ?? $job['last_run_at'] ?? '');
            if (!empty($filters['date_from']) && $createdAt !== '' && strtotime(substr($createdAt, 0, 10)) < strtotime((string) $filters['date_from'])) {
                continue;
            }
            if (!empty($filters['date_to']) && $createdAt !== '' && strtotime(substr($createdAt, 0, 10)) > strtotime((string) $filters['date_to'])) {
                continue;
            }
            $decision = match ((string) ($job['derived_status'] ?? $job['status'] ?? 'ok')) {
                'failed' => 'failed',
                'stale' => 'allow_with_warning',
                'running' => 'allow_with_warning',
                default => 'allow',
            };

            $reasonCodes = [];
            if ((string) ($job['derived_status'] ?? '') === 'stale') {
                $reasonCodes[] = 'stale_job';
            }
            if ((string) ($job['status'] ?? '') === 'failed') {
                $reasonCodes[] = 'job_failed';
            }
            if ((string) ($job['status'] ?? '') === 'running') {
                $reasonCodes[] = 'job_running';
            }

            $event = [
                'id' => 'job:' . (int) ($job['id'] ?? 0),
                'source' => 'job',
                'event_type' => 'job_health_' . (string) ($job['derived_status'] ?? $job['status'] ?? 'ok'),
                'decision' => $decision,
                'reason_codes' => $reasonCodes,
                'human_summary' => sprintf(
                    '%s is %s%s',
                    (string) ($job['label'] ?? $job['job_key'] ?? 'Automation job'),
                    (string) ($job['derived_status'] ?? $job['status'] ?? 'ok'),
                    !empty($job['last_message']) ? ': ' . (string) $job['last_message'] : '.'
                ),
                'confidence_score' => null,
                'context_quality_score' => null,
                'goal_relevance_score' => null,
                'linked_records' => [
                    'deal_id' => null,
                    'invoice_id' => null,
                    'contact_id' => null,
                    'task_id' => null,
                ],
                'created_at' => $createdAt,
                'payload' => [
                    'job' => $job,
                ],
                'mode' => null,
            ];
            $event += $this->reasonMapper->map($event);
            $events[] = $event;
        }

        return $events;
    }

    private function normalizeAdviceFeedback(array $filters): array
    {
        if (!$this->tableExists('ai_advice_feedback')) {
            return [];
        }

        $sql = "SELECT f.*, r.mode, r.confidence_score, r.context_quality_score, r.goal_relevance_score, r.policy_snapshot_json, r.output_snapshot_json
                FROM ai_advice_feedback f
                LEFT JOIN ai_guidance_runs r ON r.id = f.guidance_run_id";
        $where = ['f.workspace_id = ?'];
        $params = [$this->workspaceScope->requireWorkspaceId()];

        if (!empty($filters['user_id'])) {
            $where[] = 'f.user_id = ?';
            $params[] = (int) $filters['user_id'];
        }
        if (!empty($filters['task_id'])) {
            $where[] = 'f.linked_task_id = ?';
            $params[] = (int) $filters['task_id'];
        }
        if (!empty($filters['deal_id'])) {
            $where[] = 'f.linked_deal_id = ?';
            $params[] = (int) $filters['deal_id'];
        }
        if (!empty($filters['contact_id'])) {
            $where[] = 'f.linked_contact_id = ?';
            $params[] = (int) $filters['contact_id'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'DATE(f.created_at) >= ?';
            $params[] = (string) $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'DATE(f.created_at) <= ?';
            $params[] = (string) $filters['date_to'];
        }
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY f.created_at DESC LIMIT 200';

        $rows = Database::query($sql, $params);
        $events = [];
        foreach ($rows as $row) {
            $feedbackType = (string) ($row['feedback_type'] ?? '');
            $policy = $this->decodeJson($row['policy_snapshot_json'] ?? null);
            $output = $this->decodeJson($row['output_snapshot_json'] ?? null);
            $decision = match ($feedbackType) {
                'useful', 'acted_on', 'already_done' => 'allow',
                'not_useful' => 'blocked',
                'dismissed' => 'suggest_only',
                default => 'allow_with_warning',
            };
            $surface = (string) ($row['surface'] ?? 'coach');
            $event = [
                'id' => 'feedback:' . (int) $row['id'],
                'source' => in_array($surface, ['coach', 'clarity_chat'], true) ? $surface : 'coach',
                'event_type' => 'feedback_recorded',
                'decision' => $decision,
                'reason_codes' => ['explicit_feedback', $feedbackType],
                'human_summary' => $this->buildFeedbackSummary($surface, $feedbackType),
                'confidence_score' => $this->nullableFloat($row['confidence_score'] ?? null),
                'context_quality_score' => $this->nullableFloat($row['context_quality_score'] ?? null),
                'goal_relevance_score' => $this->nullableFloat($row['goal_relevance_score'] ?? null),
                'linked_records' => [
                    'deal_id' => $this->nullableInt($row['linked_deal_id'] ?? null),
                    'invoice_id' => null,
                    'contact_id' => $this->nullableInt($row['linked_contact_id'] ?? null),
                    'task_id' => $this->nullableInt($row['linked_task_id'] ?? null),
                ],
                'created_at' => (string) ($row['created_at'] ?? ''),
                'payload' => [
                    'feedback' => $row,
                    'metadata' => $this->decodeJson($row['metadata_json'] ?? null),
                    'role_profile' => $output['role_profile'] ?? ($policy['role_profile'] ?? null),
                    'role_threshold_recommendations' => $output['role_threshold_recommendations'] ?? ($policy['role_threshold_recommendations'] ?? null),
                ],
                'mode' => $this->normalizeMode($row['mode'] ?? null),
            ];
            $event += $this->reasonMapper->map($event);
            $events[] = $event;
        }

        return $events;
    }

    private function normalizeCommercialRuns(array $filters): array
    {
        if (!$this->tableExists('commercial_automation_runs')) {
            return [];
        }

        $rows = Database::query($this->buildBaseQuery('commercial_automation_runs', $filters), $this->buildBaseParams('commercial_automation_runs', $filters));
        $events = [];

        foreach ($rows as $row) {
            $actionPlan = $this->decodeJson($row['action_plan_json'] ?? null);
            $policy = $this->decodeJson($row['policy_snapshot_json'] ?? null);
            $decision = $this->normalizeCommercialDecision((string) ($row['decision'] ?? ''));
            $event = [
                'id' => 'commercial_run:' . (int) $row['id'],
                'source' => 'commercial',
                'event_type' => 'commercial_' . ($decision !== '' ? $decision : 'event'),
                'decision' => $decision,
                'reason_codes' => array_values(array_filter((array) ($policy['reasons'] ?? $actionPlan['reasons'] ?? []))),
                'human_summary' => 'Commercial automation ' . ($decision !== '' ? str_replace('_', ' ', $decision) : 'event') . ' triggered by ' . (string) ($row['trigger_type'] ?? 'unknown') . '.',
                'confidence_score' => $this->nullableFloat($policy['assistant_confidence'] ?? null),
                'context_quality_score' => $this->nullableFloat($policy['context_quality_score'] ?? null),
                'goal_relevance_score' => $this->nullableFloat($policy['goal_relevance_score'] ?? null),
                'linked_records' => [
                    'deal_id' => $this->nullableInt($row['deal_id'] ?? null),
                    'invoice_id' => $this->nullableInt($row['invoice_id'] ?? null),
                    'contact_id' => $this->nullableInt($row['contact_id'] ?? null),
                    'task_id' => null,
                ],
                'created_at' => (string) ($row['created_at'] ?? ''),
                'payload' => [
                    'run' => $row,
                    'action_plan' => $actionPlan,
                    'policy' => $policy,
                    'evidence' => $this->decodeJson($row['evidence_json'] ?? null),
                ],
                'mode' => $this->normalizeMode($policy['mode'] ?? null),
            ];
            $event += $this->reasonMapper->map($event);
            $events[] = $event;
        }

        return $events;
    }

    private function normalizeApprovals(array $filters): array
    {
        if (!$this->tableExists('commercial_automation_approvals')) {
            return [];
        }

        $approvals = $this->approvalService->listAll([
            'deal_id' => (int) ($filters['deal_id'] ?? 0),
            'invoice_id' => (int) ($filters['invoice_id'] ?? 0),
            'date_from' => trim((string) ($filters['date_from'] ?? '')),
            'date_to' => trim((string) ($filters['date_to'] ?? '')),
            'limit' => 200,
        ]);

        $events = [];
        foreach ($approvals as $approval) {
            $decision = match ((string) ($approval['status'] ?? 'pending')) {
                'pending' => 'approval_required',
                'approved' => 'allow',
                'rejected', 'expired' => 'blocked',
                default => 'approval_required',
            };
            $event = [
                'id' => 'approval:' . (int) $approval['id'],
                'source' => 'commercial',
                'event_type' => 'approval_' . (string) ($approval['status'] ?? 'pending'),
                'decision' => $decision,
                'reason_codes' => (array) ($approval['diagnostics']['reason_codes'] ?? []),
                'human_summary' => (string) ($approval['reason'] ?? 'Commercial approval recorded.'),
                'confidence_score' => $this->nullableFloat($approval['diagnostics']['assistant_confidence'] ?? null),
                'context_quality_score' => null,
                'goal_relevance_score' => null,
                'linked_records' => [
                    'deal_id' => $this->nullableInt($approval['deal_id'] ?? null),
                    'invoice_id' => $this->nullableInt($approval['invoice_id'] ?? null),
                    'contact_id' => null,
                    'task_id' => null,
                ],
                'created_at' => (string) ($approval['created_at'] ?? ''),
                'payload' => [
                    'approval' => $approval,
                    'preview' => $approval['preview'] ?? [],
                    'diagnostics' => $approval['diagnostics'] ?? [],
                ],
                'mode' => $this->normalizeMode($approval['diagnostics']['auto_mode'] ?? null),
            ];
            $event += $this->reasonMapper->map($event);
            $events[] = $event;
        }

        return $events;
    }

    private function normalizeWorkflowNodeRuns(array $filters): array
    {
        if (!$this->tableExists('workflow_node_runs')) {
            return [];
        }

        $rows = Database::query($this->buildWorkflowQuery('workflow_node_runs', $filters), $this->buildWorkflowParams($filters));
        $retryIndex = [];
        foreach ($this->fetchWorkflowRetryRows($filters) as $retryRow) {
            $retryIndex[(string) ($retryRow['workflow_execution_id'] ?? '') . ':' . (string) ($retryRow['node_id'] ?? '')] = true;
        }

        $events = [];
        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? '');
            $retryKey = (string) ($row['workflow_execution_id'] ?? '') . ':' . (string) ($row['node_id'] ?? '');
            $input = $this->decodeJson($row['input_snapshot_json'] ?? null);
            $output = $this->decodeJson($row['output_snapshot_json'] ?? null);
            $autonomy = (array) ($output['autonomy'] ?? []);
            $autonomyDecision = (string) ($autonomy['decision'] ?? '');
            $decision = $autonomyDecision !== ''
                ? $this->normalizeCommercialDecision($autonomyDecision)
                : match ($status) {
                    'failed' => 'failed',
                    'waiting' => isset($retryIndex[$retryKey]) ? 'retry_pending' : 'allow_with_warning',
                    'completed' => 'allow',
                    default => 'allow_with_warning',
                };
            $linkedRecords = [
                'deal_id' => $this->nullableInt($input['deal_id'] ?? null),
                'invoice_id' => null,
                'contact_id' => $this->nullableInt($input['contact_id'] ?? null),
                'task_id' => $this->nullableInt($input['task_id'] ?? null),
            ];
            $event = [
                'id' => 'workflow_node:' . (int) $row['id'],
                'source' => 'workflow',
                'event_type' => 'workflow_node_' . ($status !== '' ? $status : 'event'),
                'decision' => $decision,
                'reason_codes' => $autonomy['reasons'] ?? ($row['error_message'] ? ['workflow_error'] : []),
                'human_summary' => 'Workflow node ' . (string) ($row['node_label'] ?? $row['node_type'] ?? 'node') . ' ' . ($autonomyDecision !== '' ? str_replace('_', ' ', $autonomyDecision) : ($status !== '' ? $status : 'recorded')) . '.',
                'confidence_score' => $this->nullableFloat($autonomy['assistant_confidence'] ?? null),
                'context_quality_score' => null,
                'goal_relevance_score' => null,
                'linked_records' => $linkedRecords,
                'created_at' => (string) ($row['created_at'] ?? $row['started_at'] ?? ''),
                'payload' => [
                    'workflow' => $row,
                    'input' => $input,
                    'output' => $output,
                    'autonomy' => $autonomy,
                ],
                'mode' => null,
            ];
            $event += $this->reasonMapper->map($event);
            $events[] = $event;
        }

        return $events;
    }

    private function normalizeWorkflowRetries(array $filters): array
    {
        $rows = $this->fetchWorkflowRetryRows($filters);
        $events = [];

        foreach ($rows as $row) {
            $payload = $this->decodeJson($row['payload_json'] ?? null);
            $event = [
                'id' => 'workflow_retry:' . (int) $row['id'],
                'source' => 'workflow',
                'event_type' => 'workflow_retry_pending',
                'decision' => 'retry_pending',
                'reason_codes' => ['retry_exhausted'],
                'human_summary' => 'Workflow retry queued for node ' . (string) ($row['node_id'] ?? 'unknown') . '.',
                'confidence_score' => null,
                'context_quality_score' => null,
                'goal_relevance_score' => null,
                'linked_records' => [
                    'deal_id' => $this->nullableInt($payload['deal_id'] ?? null),
                    'invoice_id' => null,
                    'contact_id' => $this->nullableInt($payload['contact_id'] ?? null),
                    'task_id' => $this->nullableInt($payload['task_id'] ?? null),
                ],
                'created_at' => (string) ($row['created_at'] ?? ''),
                'payload' => [
                    'workflow' => $row,
                    'retry' => $payload,
                ],
                'mode' => null,
            ];
            $event += $this->reasonMapper->map($event);
            $events[] = $event;
        }

        return $events;
    }

    private function normalizeCrossDomainRuns(array $filters): array
    {
        if (!$this->tableExists('ai_cross_domain_runs')) {
            return [];
        }

        $sql = "SELECT * FROM ai_cross_domain_runs";
        $where = [];
        $params = [];
        if (!empty($filters['date_from'])) {
            $where[] = 'DATE(created_at) >= ?';
            $params[] = (string) $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'DATE(created_at) <= ?';
            $params[] = (string) $filters['date_to'];
        }
        if (!empty($filters['deal_id'])) {
            $where[] = '(primary_entity_type = ? AND primary_entity_id = ?)';
            $params[] = 'deal';
            $params[] = (int) $filters['deal_id'];
        }
        if (!empty($filters['task_id'])) {
            $where[] = '(primary_entity_type = ? AND primary_entity_id = ?)';
            $params[] = 'task';
            $params[] = (int) $filters['task_id'];
        }
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY created_at DESC LIMIT 100';
        $rows = Database::query($sql, $params);

        $events = [];
        foreach ($rows as $row) {
            $related = $this->decodeJson($row['related_entities_json'] ?? null);
            $plan = $this->decodeJson($row['plan_json'] ?? null);
            $summary = $this->decodeJson($row['summary_json'] ?? null);
            $steps = [];
            if ($this->tableExists('ai_cross_domain_steps')) {
                $steps = Database::query(
                    "SELECT * FROM ai_cross_domain_steps WHERE run_id = ? ORDER BY step_order ASC, id ASC",
                    [(int) ($row['id'] ?? 0)]
                );
            }
            $decision = match ((string) ($row['run_status'] ?? 'planned')) {
                'completed' => 'allow',
                'approval_required' => 'approval_required',
                'blocked' => 'blocked',
                'failed' => 'failed',
                'canceled' => 'suggest_only',
                default => 'suggest_only',
            };
            $confidence = null;
            if ($steps !== []) {
                $confidence = round(array_sum(array_map(fn(array $step): float => (float) ($step['assistant_confidence'] ?? 0.0), $steps)) / count($steps), 4);
            }

            $event = [
                'id' => 'cross_domain:' . (int) ($row['id'] ?? 0),
                'source' => 'orchestrator',
                'event_type' => 'cross_domain_' . (string) ($row['run_status'] ?? 'planned'),
                'decision' => $decision,
                'reason_codes' => array_values(array_filter((array) ($summary['reasons'] ?? []))),
                'human_summary' => 'Cross-domain objective ' . (string) ($row['objective_key'] ?? 'objective') . ' is ' . (string) ($row['run_status'] ?? 'planned') . '.',
                'confidence_score' => $confidence,
                'context_quality_score' => null,
                'goal_relevance_score' => null,
                'linked_records' => [
                    'deal_id' => $this->nullableInt(($row['primary_entity_type'] ?? '') === 'deal' ? ($row['primary_entity_id'] ?? null) : ($related['deal_id'] ?? null)),
                    'invoice_id' => $this->nullableInt($related['invoice_id'] ?? null),
                    'contact_id' => $this->nullableInt($related['contact_id'] ?? null),
                    'task_id' => $this->nullableInt(($row['primary_entity_type'] ?? '') === 'task' ? ($row['primary_entity_id'] ?? null) : ($related['task_id'] ?? null)),
                ],
                'created_at' => (string) ($row['created_at'] ?? ''),
                'payload' => [
                    'run' => $row,
                    'related_entities' => $related,
                    'plan' => $plan,
                    'summary' => $summary,
                    'steps' => $steps,
                ],
                'mode' => null,
            ];
            $event += $this->reasonMapper->map($event);
            $events[] = $event;
        }

        return $events;
    }

    private function normalizeCrossDomainIntakeEvents(array $filters): array
    {
        if (!$this->tableExists('ai_cross_domain_intake_events')) {
            return [];
        }

        $sql = "SELECT * FROM ai_cross_domain_intake_events";
        $where = [];
        $params = [];
        if (!empty($filters['date_from'])) {
            $where[] = 'DATE(created_at) >= ?';
            $params[] = (string) $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'DATE(created_at) <= ?';
            $params[] = (string) $filters['date_to'];
        }
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY created_at DESC LIMIT 100';

        $rows = Database::query($sql, $params);
        $events = [];
        foreach ($rows as $row) {
            $metadata = $this->decodeJson($row['metadata_json'] ?? null);
            $decision = match ((string) ($row['intake_decision'] ?? 'ignored')) {
                'run_created', 'run_waiting' => 'allow',
                'suppressed' => 'allow_with_warning',
                'duplicate_rejected' => 'blocked',
                default => 'suggest_only',
            };
            $event = [
                'id' => 'cross_domain_intake:' . (int) ($row['id'] ?? 0),
                'source' => 'orchestrator',
                'event_type' => 'cross_domain_intake_' . (string) ($row['intake_decision'] ?? 'ignored'),
                'decision' => $decision,
                'reason_codes' => array_values(array_filter([(string) ($row['reason_text'] ?? '')])),
                'human_summary' => 'Cross-domain intake ' . (string) ($row['intake_decision'] ?? 'ignored') . ' for ' . (string) ($row['trigger_key'] ?? 'trigger') . '.',
                'confidence_score' => null,
                'context_quality_score' => null,
                'goal_relevance_score' => null,
                'linked_records' => [
                    'deal_id' => $this->nullableInt($metadata['event']['related_entity_ids']['deal_id'] ?? null),
                    'invoice_id' => $this->nullableInt($metadata['event']['related_entity_ids']['invoice_id'] ?? null),
                    'contact_id' => $this->nullableInt($metadata['event']['related_entity_ids']['contact_id'] ?? null),
                    'task_id' => $this->nullableInt($metadata['event']['related_entity_ids']['task_id'] ?? null),
                ],
                'created_at' => (string) ($row['created_at'] ?? ''),
                'payload' => [
                    'intake' => $row,
                    'metadata' => $metadata,
                ],
                'mode' => null,
            ];
            $event += $this->reasonMapper->map($event);
            $events[] = $event;
        }

        return $events;
    }

    private function normalizeCapabilityLogs(array $filters): array
    {
        $rows = $this->latestCapabilityLogs($filters);
        $events = [];

        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? 'ready');
            if ($status === 'ready' && empty($filters['source']) && empty($filters['decision']) && empty($filters['reason_bucket'])) {
                continue;
            }

            $decision = match ($status) {
                'missing' => 'blocked',
                'degraded' => 'allow_with_warning',
                default => 'allow',
            };
            $event = [
                'id' => 'capability:' . (int) $row['id'],
                'source' => 'capability',
                'event_type' => 'capability_' . $status,
                'decision' => $decision,
                'reason_codes' => [(string) ($row['capability_key'] ?? 'feature_unready')],
                'human_summary' => (string) ($row['reason'] ?? 'Capability state recorded.'),
                'confidence_score' => null,
                'context_quality_score' => null,
                'goal_relevance_score' => null,
                'linked_records' => ['deal_id' => null, 'invoice_id' => null, 'contact_id' => null, 'task_id' => null],
                'created_at' => (string) ($row['created_at'] ?? ''),
                'payload' => [
                    'capability' => $row,
                    'metadata' => $this->decodeJson($row['metadata_json'] ?? null),
                ],
                'mode' => null,
            ];
            $event += $this->reasonMapper->map($event);
            $events[] = $event;
        }

        return $events;
    }

    private function latestCapabilityLogs(array $filters): array
    {
        $rows = $this->fetchCapabilityLogs($filters);
        if ($rows === []) {
            return [];
        }

        $latest = [];
        foreach ($rows as $row) {
            $key = trim((string) ($row['capability_key'] ?? ''));
            if ($key === '') {
                $key = 'capability:' . (string) ($row['id'] ?? uniqid('', true));
            }
            if (!isset($latest[$key])) {
                $latest[$key] = $row;
            }
        }

        return array_values($latest);
    }

    private function collapseCapabilityNoise(array $events): array
    {
        $collapsed = [];
        $seen = [];

        foreach ($events as $event) {
            if ((string) ($event['source'] ?? '') !== 'capability') {
                $collapsed[] = $event;
                continue;
            }

            $payload = (array) ($event['payload']['capability'] ?? []);
            $signature = implode('|', [
                (string) ($payload['capability_key'] ?? $event['event_type'] ?? 'capability'),
                (string) ($payload['status'] ?? $event['decision'] ?? ''),
                trim((string) ($payload['reason'] ?? $event['human_summary'] ?? '')),
            ]);

            if (isset($seen[$signature])) {
                continue;
            }

            $seen[$signature] = true;
            $collapsed[] = $event;
        }

        return $collapsed;
    }

    private function normalizeTaskEvidence(array $filters): array
    {
        if (!$this->tableExists('ai_task_evidence')) {
            return [];
        }

        $sql = "SELECT e.*, t.status AS task_status, t.completed_at, t.title AS task_title, t.contact_id
                FROM ai_task_evidence e
                LEFT JOIN tasks t ON t.id = e.task_id";
        $where = [];
        $params = [];

        if (!empty($filters['task_id'])) {
            $where[] = 'e.task_id = ?';
            $params[] = (int) $filters['task_id'];
        }
        if (!empty($filters['contact_id'])) {
            $where[] = 't.contact_id = ?';
            $params[] = (int) $filters['contact_id'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'DATE(e.created_at) >= ?';
            $params[] = (string) $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'DATE(e.created_at) <= ?';
            $params[] = (string) $filters['date_to'];
        }
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY e.created_at DESC LIMIT 200';
        $rows = Database::query($sql, $params);

        $events = [];
        foreach ($rows as $row) {
            $details = $this->decodeJson($row['evidence_json'] ?? null);
            $baseEvent = [
                'id' => 'task_evidence:' . (int) $row['id'],
                'source' => 'task_automation',
                'event_type' => 'task_evidence_recorded',
                'decision' => 'allow',
                'reason_codes' => [(string) ($row['evidence_type'] ?? 'task_evidence')],
                'human_summary' => 'Task evidence recorded for ' . (string) ($row['task_title'] ?? 'task') . '.',
                'confidence_score' => $this->nullableFloat($row['confidence_score'] ?? null),
                'context_quality_score' => null,
                'goal_relevance_score' => null,
                'linked_records' => [
                    'deal_id' => $this->nullableInt($details['deal_id'] ?? null),
                    'invoice_id' => $this->nullableInt($details['invoice_id'] ?? null),
                    'contact_id' => $this->nullableInt($row['contact_id'] ?? $details['contact_id'] ?? null),
                    'task_id' => $this->nullableInt($row['task_id'] ?? null),
                ],
                'created_at' => (string) ($row['created_at'] ?? ''),
                'payload' => [
                    'evidence' => $row,
                    'details' => $details,
                ],
                'mode' => null,
            ];
            $baseEvent += $this->reasonMapper->map($baseEvent);
            $events[] = $baseEvent;

            if ((string) ($row['task_status'] ?? '') === 'completed') {
                $autoEvent = $baseEvent;
                $autoEvent['id'] = 'task_auto:' . (int) $row['id'];
                $autoEvent['event_type'] = 'task_auto_completed';
                $autoEvent['human_summary'] = 'Task ' . (string) ($row['task_title'] ?? 'task') . ' was completed using recorded evidence.';
                $events[] = $autoEvent;
            }
        }

        return $events;
    }

    private function normalizeMarketplaceRecommendationEvents(array $filters): array
    {
        if (!$this->tableExists('workspace_marketplace_recommendation_events')) {
            return [];
        }

        $rows = $this->getMarketplaceRecommendationEvents($filters);
        $events = [];

        foreach ($rows as $row) {
            $eventType = (string) ($row['event_type'] ?? 'impression');
            $surface = (string) ($row['surface'] ?? 'marketplace');
            $reasonCodes = $this->decodeJson($row['reason_codes_json'] ?? null);
            $metadata = $this->decodeJson($row['metadata_json'] ?? null);
            $skillKey = (string) ($row['skill_key'] ?? 'marketplace_skill');
            $label = trim((string) ($metadata['label'] ?? str_replace('_', ' ', $skillKey)));

            $event = [
                'id' => 'marketplace_recommendation:' . (int) ($row['id'] ?? 0),
                'source' => 'marketplace_recommendation',
                'event_type' => 'marketplace_recommendation_' . $eventType,
                'decision' => in_array($eventType, ['dismissed', 'snoozed'], true) ? 'suggest_only' : 'allow',
                'reason_codes' => array_values(array_filter(array_merge(
                    ['marketplace_recommendation', $eventType, $surface],
                    array_map('strval', $reasonCodes)
                ))),
                'human_summary' => 'Marketplace recommendation ' . $label . ' ' . str_replace('_', ' ', $eventType) . ' from ' . $this->marketplaceSurfaceLabel($surface) . '.',
                'confidence_score' => null,
                'context_quality_score' => null,
                'goal_relevance_score' => null,
                'linked_records' => [
                    'deal_id' => null,
                    'invoice_id' => null,
                    'contact_id' => null,
                    'task_id' => $this->nullableInt($metadata['task_id'] ?? null),
                ],
                'created_at' => (string) ($row['created_at'] ?? ''),
                'payload' => [
                    'marketplace_recommendation_event' => $row,
                    'reason_codes' => $reasonCodes,
                    'metadata' => $metadata,
                ],
                'mode' => null,
            ];
            $event += $this->reasonMapper->map($event);
            $events[] = $event;
        }

        return $events;
    }

    private function normalizeMarketplaceSetupJourneyEvents(array $filters): array
    {
        if (!$this->tableExists('workspace_marketplace_setup_journey_events')) {
            return [];
        }

        $rows = $this->getMarketplaceSetupJourneyEvents($filters);
        $events = [];

        foreach ($rows as $row) {
            $eventType = (string) ($row['event_type'] ?? 'journey_impression');
            $metadata = $this->decodeJson($row['metadata_json'] ?? null);
            $skillKey = (string) ($row['skill_key'] ?? 'marketplace_skill');
            $label = trim((string) ($row['label'] ?? $metadata['label'] ?? str_replace('_', ' ', $skillKey)));

            $event = [
                'id' => 'marketplace_setup_journey:' . (int) ($row['id'] ?? 0),
                'source' => 'marketplace_setup_journey',
                'event_type' => 'marketplace_setup_journey_' . $eventType,
                'decision' => in_array($eventType, ['step_skipped', 'step_reset'], true) ? 'suggest_only' : 'allow',
                'reason_codes' => array_values(array_filter(['marketplace_setup_journey', $eventType, (string) ($row['step_status'] ?? '')])),
                'human_summary' => 'Marketplace setup journey ' . $label . ' ' . str_replace('_', ' ', $eventType) . '.',
                'confidence_score' => null,
                'context_quality_score' => null,
                'goal_relevance_score' => null,
                'linked_records' => [
                    'deal_id' => null,
                    'invoice_id' => null,
                    'contact_id' => null,
                    'task_id' => null,
                ],
                'created_at' => (string) ($row['created_at'] ?? ''),
                'payload' => [
                    'marketplace_setup_journey_event' => $row,
                    'metadata' => $metadata,
                ],
                'mode' => null,
            ];
            $event += $this->reasonMapper->map($event);
            $events[] = $event;
        }

        return $events;
    }

    private function normalizeMarketplaceActivationBundleEvents(array $filters): array
    {
        if (!$this->tableExists('workspace_marketplace_activation_bundle_events')) {
            return [];
        }

        $rows = $this->getMarketplaceActivationBundleEvents($filters);
        $events = [];

        foreach ($rows as $row) {
            $eventType = (string) ($row['event_type'] ?? 'bundle_impression');
            $metadata = $this->decodeJson($row['metadata_json'] ?? null);
            $bundleKey = (string) ($row['bundle_key'] ?? 'activation_bundle');
            $label = trim((string) ($metadata['label'] ?? str_replace('_', ' ', $bundleKey)));

            $event = [
                'id' => 'marketplace_activation_bundle:' . (int) ($row['id'] ?? 0),
                'source' => 'marketplace_activation_bundle',
                'event_type' => 'marketplace_activation_bundle_' . $eventType,
                'decision' => in_array($eventType, ['dismissed'], true) ? 'suggest_only' : 'allow',
                'reason_codes' => array_values(array_filter(['marketplace_activation_bundle', $eventType, (string) ($row['bundle_status'] ?? '')])),
                'human_summary' => 'Marketplace activation bundle ' . $label . ' ' . str_replace('_', ' ', $eventType) . '.',
                'confidence_score' => null,
                'context_quality_score' => null,
                'goal_relevance_score' => null,
                'linked_records' => [
                    'deal_id' => null,
                    'invoice_id' => null,
                    'contact_id' => null,
                    'task_id' => null,
                ],
                'created_at' => (string) ($row['created_at'] ?? ''),
                'payload' => [
                    'marketplace_activation_bundle_event' => $row,
                    'included_skill_keys' => $this->decodeJson($row['included_skill_keys_json'] ?? null),
                    'recommended_skill_keys' => $this->decodeJson($row['recommended_skill_keys_json'] ?? null),
                    'progress' => $this->decodeJson($row['progress_json'] ?? null),
                    'metadata' => $metadata,
                ],
                'mode' => null,
            ];
            $event += $this->reasonMapper->map($event);
            $events[] = $event;
        }

        return $events;
    }

    private function buildFeedbackSummary(string $surface, string $feedbackType): string
    {
        $prefix = $surface === 'clarity_chat' ? 'Clarity answer' : 'Coach recommendation';
        return match ($feedbackType) {
            'useful' => $prefix . ' marked useful.',
            'not_useful' => $prefix . ' marked not useful.',
            'acted_on' => $prefix . ' linked to a follow-through action.',
            'already_done' => $prefix . ' marked already done.',
            'dismissed' => $prefix . ' dismissed.',
            default => $prefix . ' feedback recorded.',
        };
    }

    private function matchesOutcomeRecordFilters(array $row, array $metadata, array $filters): bool
    {
        if (!empty($filters['task_id']) && (int) ($row['task_id'] ?? 0) !== (int) $filters['task_id']) {
            return false;
        }
        foreach (['deal_id' => 'linked_deal_id', 'invoice_id' => 'linked_invoice_id', 'contact_id' => 'linked_contact_id'] as $filterKey => $metadataKey) {
            if (!empty($filters[$filterKey]) && (int) ($metadata[$metadataKey] ?? 0) !== (int) $filters[$filterKey]) {
                return false;
            }
        }
        return true;
    }

    private function fetchCapabilityLogs(array $filters): array
    {
        if (!$this->tableExists('ai_capability_state_log')) {
            return [];
        }

        $sql = "SELECT * FROM ai_capability_state_log";
        $where = ['workspace_id = ?'];
        $params = [$this->workspaceScope->requireWorkspaceId()];

        if (!empty($filters['user_id'])) {
            $where[] = 'user_id = ?';
            $params[] = (int) $filters['user_id'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'DATE(created_at) >= ?';
            $params[] = (string) $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'DATE(created_at) <= ?';
            $params[] = (string) $filters['date_to'];
        }
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY created_at DESC LIMIT 200';

        return Database::query($sql, $params);
    }

    private function fetchWorkflowRetryRows(array $filters): array
    {
        if (!$this->tableExists('workflow_retry_queue')) {
            return [];
        }

        $sql = "SELECT * FROM workflow_retry_queue";
        $where = [];
        $params = [];

        if (!empty($filters['date_from'])) {
            $where[] = 'DATE(created_at) >= ?';
            $params[] = (string) $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'DATE(created_at) <= ?';
            $params[] = (string) $filters['date_to'];
        }
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY created_at DESC LIMIT 200';

        return Database::query($sql, $params);
    }

    private function buildBaseQuery(string $table, array $filters): string
    {
        $sql = "SELECT * FROM {$table}";
        $where = [];

        if ($this->columnExists($table, 'workspace_id')) {
            $where[] = 'workspace_id = ?';
        }

        if (!empty($filters['user_id']) && $this->columnExists($table, 'user_id')) {
            $where[] = 'user_id = ?';
        }
        if (!empty($filters['deal_id']) && $this->columnExists($table, 'deal_id')) {
            $where[] = 'deal_id = ?';
        }
        if (!empty($filters['invoice_id']) && $this->columnExists($table, 'invoice_id')) {
            $where[] = 'invoice_id = ?';
        }
        if (!empty($filters['contact_id']) && $this->columnExists($table, 'contact_id')) {
            $where[] = 'contact_id = ?';
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'DATE(created_at) >= ?';
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'DATE(created_at) <= ?';
        }
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY created_at DESC LIMIT 250';

        return $sql;
    }

    private function buildBaseParams(string $table, array $filters): array
    {
        $params = [];
        if ($this->columnExists($table, 'workspace_id')) {
            $params[] = $this->workspaceScope->requireWorkspaceId();
        }
        if (!empty($filters['user_id']) && $this->columnExists($table, 'user_id')) {
            $params[] = (int) $filters['user_id'];
        }
        if (!empty($filters['deal_id']) && $this->columnExists($table, 'deal_id')) {
            $params[] = (int) $filters['deal_id'];
        }
        if (!empty($filters['invoice_id']) && $this->columnExists($table, 'invoice_id')) {
            $params[] = (int) $filters['invoice_id'];
        }
        if (!empty($filters['contact_id']) && $this->columnExists($table, 'contact_id')) {
            $params[] = (int) $filters['contact_id'];
        }
        if (!empty($filters['date_from'])) {
            $params[] = (string) $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $params[] = (string) $filters['date_to'];
        }

        return $params;
    }

    private function buildWorkflowQuery(string $table, array $filters): string
    {
        $sql = "SELECT * FROM {$table}";
        $where = [];
        if ($this->columnExists($table, 'workspace_id')) {
            $where[] = 'workspace_id = ?';
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'DATE(created_at) >= ?';
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'DATE(created_at) <= ?';
        }
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY created_at DESC LIMIT 250';
        return $sql;
    }

    private function buildWorkflowParams(array $filters): array
    {
        $params = [];
        if ($this->columnExists('workflow_node_runs', 'workspace_id')) {
            $params[] = $this->workspaceScope->requireWorkspaceId();
        }
        if (!empty($filters['date_from'])) {
            $params[] = (string) $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $params[] = (string) $filters['date_to'];
        }
        return $params;
    }

    private function matchesHighLevelFilters(array $event, array $filters): bool
    {
        $source = trim((string) ($filters['source'] ?? ''));
        if ($source !== '' && (string) ($event['source'] ?? '') !== $source) {
            return false;
        }

        $decision = trim((string) ($filters['decision'] ?? ''));
        if ($decision !== '' && (string) ($event['decision'] ?? '') !== $decision) {
            return false;
        }

        $reasonBucket = trim((string) ($filters['reason_bucket'] ?? ''));
        if ($reasonBucket !== '' && (string) ($event['reason_bucket'] ?? '') !== $reasonBucket) {
            return false;
        }

        $mode = trim((string) ($filters['mode'] ?? ''));
        if ($mode !== '') {
            $normalizedMode = $this->normalizeMode($mode);
            if ($normalizedMode !== null && (string) ($event['mode'] ?? '') !== $normalizedMode) {
                return false;
            }
        }

        foreach (['deal_id', 'invoice_id', 'contact_id', 'task_id'] as $key) {
            $filterValue = (int) ($filters[$key] ?? 0);
            if ($filterValue > 0 && (int) ($event['linked_records'][$key] ?? 0) !== $filterValue) {
                return false;
            }
        }

        return true;
    }

    private function normalizeCommercialDecision(string $decision): string
    {
        return match ($decision) {
            'auto_apply' => 'allow',
            'reject' => 'blocked',
            default => $decision !== '' ? $decision : 'allow',
        };
    }

    private function normalizeMode($mode): ?string
    {
        $value = trim((string) $mode);
        return match ($value) {
            '1', 'foundation' => 'foundation',
            '2', 'operations' => 'operations',
            '3', 'guardian' => 'guardian',
            default => $value !== '' ? $value : null,
        };
    }

    private function marketplaceRecommendationFilters(array $filters): ?array
    {
        $source = trim((string) ($filters['source'] ?? ''));
        if ($source !== '' && $source !== 'marketplace_recommendation') {
            return null;
        }

        $eventFilters = ['workspace_id' => $this->workspaceScope->requireWorkspaceId()];
        foreach (['user_id', 'surface', 'skill_key', 'event_type', 'date_from', 'date_to'] as $key) {
            if (!empty($filters[$key])) {
                $eventFilters[$key] = $filters[$key];
            }
        }

        return $eventFilters;
    }

    private function marketplaceSetupJourneyFilters(array $filters): ?array
    {
        $source = trim((string) ($filters['source'] ?? ''));
        if ($source !== '' && $source !== 'marketplace_setup_journey') {
            return null;
        }

        $eventFilters = ['workspace_id' => $this->workspaceScope->requireWorkspaceId()];
        foreach (['user_id', 'skill_key', 'step_key', 'event_type', 'date_from', 'date_to'] as $key) {
            if (!empty($filters[$key])) {
                $eventFilters[$key] = $filters[$key];
            }
        }

        return $eventFilters;
    }

    private function marketplaceActivationBundleFilters(array $filters): ?array
    {
        $source = trim((string) ($filters['source'] ?? ''));
        if ($source !== '' && $source !== 'marketplace_activation_bundle') {
            return null;
        }

        $eventFilters = ['workspace_id' => $this->workspaceScope->requireWorkspaceId()];
        foreach (['user_id', 'bundle_key', 'surface', 'event_type', 'date_from', 'date_to'] as $key) {
            if (!empty($filters[$key])) {
                $eventFilters[$key] = $filters[$key];
            }
        }

        return $eventFilters;
    }

    private function blankMarketplaceRecommendationSummary(): array
    {
        return [
            'counts' => [
                'impression' => 0,
                'cta_clicked' => 0,
                'dismissed' => 0,
                'snoozed' => 0,
                'task_created' => 0,
                'installed' => 0,
            ],
            'by_surface' => [],
            'top_skills' => [],
            'total_events' => 0,
            'click_through_rate' => 0.0,
        ];
    }

    private function blankCoachFeedbackInsights(): array
    {
        return [
            'counts' => [
                'useful' => 0,
                'acted_on' => 0,
                'already_done' => 0,
                'not_useful' => 0,
                'dismissed' => 0,
            ],
            'total_feedback' => 0,
            'top_positive_signatures' => [],
            'top_negative_signatures' => [],
            'top_dismissed_signatures' => [],
            'top_acted_on_signatures' => [],
            'source_type_breakdown' => [],
            'source_section_breakdown' => [],
            'why_signal_breakdown' => [],
            'trust_signal_breakdown' => [],
            'recent_adjusted_patterns' => [],
        ];
    }

    private function topCoachFeedbackSignatures(array $stats, string $metric): array
    {
        $items = array_values(array_filter(
            $stats,
            static fn(array $row): bool => (int) ($row[$metric] ?? 0) > 0
        ));
        usort($items, static function (array $left, array $right) use ($metric): int {
            $metricCompare = (int) ($right[$metric] ?? 0) <=> (int) ($left[$metric] ?? 0);
            if ($metricCompare !== 0) {
                return $metricCompare;
            }
            return strcmp((string) ($right['latest_at'] ?? ''), (string) ($left['latest_at'] ?? ''));
        });

        return array_slice($items, 0, 5);
    }

    private function topCoachFeedbackCounts(array $counts): array
    {
        arsort($counts);
        $rows = [];
        foreach (array_slice($counts, 0, 6, true) as $label => $count) {
            $rows[] = [
                'label' => (string) $label,
                'count' => (int) $count,
            ];
        }

        return $rows;
    }

    private function blankMarketplaceSetupJourneySummary(): array
    {
        return [
            'counts' => [
                'journey_impression' => 0,
                'setup_opened' => 0,
                'install_completed' => 0,
                'step_completed' => 0,
                'step_skipped' => 0,
                'step_reset' => 0,
            ],
            'top_skills' => [],
            'top_steps' => [],
            'total_events' => 0,
            'setup_open_rate' => 0.0,
            'manual_step_completion_rate' => 0.0,
        ];
    }

    private function blankMarketplaceActivationBundleEventSummary(): array
    {
        return [
            'counts' => [
                'bundle_impression' => 0,
                'cta_clicked' => 0,
                'selected' => 0,
                'dismissed' => 0,
                'completed' => 0,
                'module_installed' => 0,
            ],
            'top_bundles' => [],
            'total_events' => 0,
            'click_through_rate' => 0.0,
            'completion_rate' => 0.0,
        ];
    }

    private function marketplaceSurfaceLabel(string $surface): string
    {
        return match ($surface) {
            'clarity_chat' => 'Clarity',
            'coach' => 'Coach',
            default => 'Marketplace',
        };
    }

    private function decodeJson($value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function tableExists(string $table): bool
    {
        $row = Database::queryOne(
            "SELECT COUNT(*) AS cnt
             FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?",
            [$table]
        );

        return ((int) ($row['cnt'] ?? 0)) > 0;
    }

    private function columnExists(string $table, string $column): bool
    {
        $row = Database::queryOne(
            "SELECT COUNT(*) AS cnt
             FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?",
            [$table, $column]
        );

        return ((int) ($row['cnt'] ?? 0)) > 0;
    }

    private function nullableInt($value): ?int
    {
        if ($value === null || $value === '' || (int) $value <= 0) {
            return null;
        }

        return (int) $value;
    }

    private function nullableFloat($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) $value;
    }
}
