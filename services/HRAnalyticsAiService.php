<?php

namespace CRM\Services;

use CRM\Modules\HRAnalyticsSettings;

class HRAnalyticsAiService
{
    private PluginRuntimeEventService $runtimeEvents;
    private WorkspaceLanguageLevelService $languageLevels;

    public function __construct(
        private ?AIService $aiService = null,
        private ?HRAnalyticsSettings $settingsModule = null,
        ?PluginRuntimeEventService $runtimeEvents = null,
        ?WorkspaceLanguageLevelService $languageLevels = null,
    ) {
        $this->aiService = $this->aiService ?? new AIService();
        $this->settingsModule = $this->settingsModule ?? new HRAnalyticsSettings();
        $this->runtimeEvents = $runtimeEvents ?? new PluginRuntimeEventService();
        $this->languageLevels = $languageLevels ?? new WorkspaceLanguageLevelService();
    }

    public function buildSwot(string $scopeLabel, array $summary, array $bestStrategies, array $gaps, bool $allowAi = true): array
    {
        $fallback = $this->fallbackSwot($scopeLabel, $summary, $bestStrategies, $gaps);
        $settings = $this->settingsModule->get();
        if (!$allowAi || !$settings['ai_enabled']) {
            return $fallback;
        }

        $swotFocus = trim((string) ($settings['prompt_config']['swot_focus'] ?? ''));
        $languageInstruction = $this->languageLevels->promptInstruction($this->languageLevels->currentContext()['level'] ?? WorkspaceLanguageLevelService::DEFAULT_LEVEL);
        $evidenceSources = $this->buildEvidenceSources($summary, $bestStrategies, $gaps);
        $prompt = implode("\n", [
            'You are a senior operating partner, HR business partner, people analytics lead, and founder advisor preparing an organization-level SWOT.',
            'Use careful people-risk language: do not infer attitude, laziness, misconduct, or disengagement from CRM data alone.',
            'Treat low activity as a possible data visibility or adoption signal unless stronger evidence exists.',
            'Use assigned Work Ownership and organization stage when present; missing Work Ownership is a coverage gap, not department failure.',
            'Use assignment types precisely: owner and temporary_owner mean accountable coverage; oversight is leadership visibility; contributor is support, not ownership.',
            'Use function relevance precisely: deferred and not_applicable functions are maturity choices, not failures; outsourced functions need governance, not internal performance scoring.',
            'Do not judge weakly measurable functions such as Finance, Product, or People/HR without relevant evidence.',
            'Distinguish coverage, maturity, performance, and confidence in every SWOT item.',
            'Return ONLY valid JSON with keys strengths, weaknesses, opportunities, threats.',
            'Each key must map to an array of 2 to 4 objects with keys title, evidence, impact, action, severity, confidence, source_metrics.',
            'source_metrics must be an array of keys from ALLOWED_EVIDENCE_SOURCES. Do not invent evidence sources.',
            'severity must be low, medium, or high. confidence must be low, moderate, or high.',
            $swotFocus !== '' ? 'WORKSPACE SWOT FOCUS: ' . $swotFocus : 'WORKSPACE SWOT FOCUS: Evaluate people execution, coordination, role fit, workload balance, strategy alignment, and momentum.',
            $languageInstruction,
            '',
            'SCOPE: ' . $scopeLabel,
            'SUMMARY: ' . json_encode($summary, JSON_UNESCAPED_SLASHES),
            'BEST_STRATEGIES: ' . json_encode($bestStrategies, JSON_UNESCAPED_SLASHES),
            'GAPS: ' . json_encode(array_slice($gaps, 0, 5), JSON_UNESCAPED_SLASHES),
            'ALLOWED_EVIDENCE_SOURCES: ' . json_encode($evidenceSources, JSON_UNESCAPED_SLASHES),
        ]);

        $startedAt = microtime(true);
        try {
            $parsed = $this->parseJsonResponse(
                $this->aiService->process('ai_coach_recommendations', ['prompt' => $prompt, 'text' => $prompt])
            );
        } catch (\Throwable $e) {
            $this->recordAiEvent('swot', 'capability_failed', 'failed', $startedAt, [
                'scope' => $scopeLabel,
            ], 'ai_process_failed', $e->getMessage());
            return $fallback;
        }

        if (!is_array($parsed)) {
            $this->recordAiEvent('swot', 'capability_failed', 'failed', $startedAt, [
                'scope' => $scopeLabel,
            ], 'invalid_ai_json', 'AI SWOT response was not valid JSON.');
            return $fallback;
        }

        $out = [];
        foreach (['strengths', 'weaknesses', 'opportunities', 'threats'] as $key) {
            $values = [];
            foreach ((array) ($parsed[$key] ?? []) as $index => $item) {
                $fallbackItem = (array) ($fallback[$key][$index] ?? $fallback[$key][0] ?? []);
                $normalized = $this->normalizeSwotItem($item, $fallbackItem, $evidenceSources);
                if ($normalized !== []) {
                    $values[] = $normalized;
                }
            }
            $out[$key] = $values !== [] ? array_slice($values, 0, 4) : $fallback[$key];
        }

        $this->recordAiEvent('swot', 'capability_succeeded', 'success', $startedAt, [
            'scope' => $scopeLabel,
            'source_count' => count($evidenceSources),
        ]);

        return $out;
    }

    public function buildManagerTips(array $summary, array $gaps, array $strategies, string $departmentLabel = 'All Teams', bool $allowAi = true): array
    {
        $fallback = $this->fallbackManagerTips($summary, $gaps, $strategies, $departmentLabel);
        $settings = $this->settingsModule->get();
        if (!$allowAi || !$settings['ai_enabled']) {
            return $fallback;
        }

        $managerFocus = trim((string) ($settings['prompt_config']['manager_focus'] ?? ''));
        $languageInstruction = $this->languageLevels->promptInstruction($this->languageLevels->currentContext()['level'] ?? WorkspaceLanguageLevelService::DEFAULT_LEVEL);
        $evidenceSources = $this->buildEvidenceSources($summary, $strategies, $gaps);
        $prompt = implode("\n", [
            'You are a senior HR business partner and founder advisor coaching leadership inside a CRM workspace.',
            'Write like an experienced HR specialist: specific, evidence-grounded, psychologically safe, and action-oriented.',
            'Do not infer attitude, laziness, misconduct, or disengagement from CRM data alone.',
            'Use Work Ownership when present: coach against the responsibilities a person actually carries.',
            'If a function is unstaffed or founder-owned, frame advice as coverage, load, or maturity rather than performance failure.',
            'Return ONLY valid JSON: {"tips":["..."]}.',
            'Provide 4 to 6 concise, specific management tips with a clear leadership action or manager conversation angle.',
            $managerFocus !== '' ? 'WORKSPACE MANAGER GUIDANCE FOCUS: ' . $managerFocus : 'WORKSPACE MANAGER GUIDANCE FOCUS: Keep recommendations concrete, explainable, and tied to observable team behaviour.',
            $languageInstruction,
            '',
            'DEPARTMENT: ' . $departmentLabel,
            'SUMMARY: ' . json_encode($summary, JSON_UNESCAPED_SLASHES),
            'GAPS: ' . json_encode(array_slice($gaps, 0, 6), JSON_UNESCAPED_SLASHES),
            'TOP_STRATEGIES: ' . json_encode(array_slice($strategies, 0, 4), JSON_UNESCAPED_SLASHES),
            'EVIDENCE_SOURCES: ' . json_encode($evidenceSources, JSON_UNESCAPED_SLASHES),
        ]);

        $startedAt = microtime(true);
        try {
            $parsed = $this->parseJsonResponse(
                $this->aiService->process('ai_coach_recommendations', ['prompt' => $prompt, 'text' => $prompt])
            );
        } catch (\Throwable $e) {
            $this->recordAiEvent('manager_tips', 'capability_failed', 'failed', $startedAt, [
                'department' => $departmentLabel,
            ], 'ai_process_failed', $e->getMessage());
            return $fallback;
        }
        if (!is_array($parsed) || !isset($parsed['tips']) || !is_array($parsed['tips'])) {
            $this->recordAiEvent('manager_tips', 'capability_failed', 'failed', $startedAt, [
                'department' => $departmentLabel,
            ], 'invalid_ai_json', 'AI manager tips response was not valid JSON.');
            return $fallback;
        }

        $tips = array_values(array_filter(array_map(static fn($item): string => trim((string) $item), $parsed['tips'])));
        $this->recordAiEvent('manager_tips', 'capability_succeeded', 'success', $startedAt, [
            'department' => $departmentLabel,
            'tip_count' => count($tips),
            'source_count' => count($evidenceSources),
        ]);
        return $tips !== [] ? array_slice($tips, 0, 6) : $fallback;
    }

    public function buildStrategicPointers(array $roleSummaries, array $strategies, array $gaps, bool $allowAi = true): array
    {
        $fallback = $this->fallbackStrategicPointers($roleSummaries, $strategies, $gaps);
        $settings = $this->settingsModule->get();
        if (!$allowAi || !$settings['ai_enabled']) {
            return $fallback;
        }

        $managerFocus = trim((string) ($settings['prompt_config']['manager_focus'] ?? ''));
        $languageInstruction = $this->languageLevels->promptInstruction($this->languageLevels->currentContext()['level'] ?? WorkspaceLanguageLevelService::DEFAULT_LEVEL);
        $evidenceSources = $this->buildEvidenceSources([], $strategies, $gaps);
        $prompt = implode("\n", [
            'You are generating founder-level strategy-to-execution pointers for an Organization Intelligence Center.',
            'Act as a senior operating partner and HRBP: connect role performance to operating rhythm, manager support, and business outcomes.',
            'Do not treat activity volume as performance without outcome evidence.',
            'When Work Ownership is available, explain whether the issue is ownership coverage, department maturity, or measurable execution.',
            'Return ONLY valid JSON with keys marketing, sales, general.',
            'Each key must be an array of 2 to 4 short strategic pointers.',
            $managerFocus !== '' ? 'WORKSPACE GUIDANCE FOCUS: ' . $managerFocus : 'WORKSPACE GUIDANCE FOCUS: Keep recommendations concrete, explainable, and tied to observable team behaviour.',
            $languageInstruction,
            '',
            'ROLE_SUMMARIES: ' . json_encode($roleSummaries, JSON_UNESCAPED_SLASHES),
            'STRATEGIES: ' . json_encode(array_slice($strategies, 0, 5), JSON_UNESCAPED_SLASHES),
            'GAPS: ' . json_encode(array_slice($gaps, 0, 6), JSON_UNESCAPED_SLASHES),
            'EVIDENCE_SOURCES: ' . json_encode($evidenceSources, JSON_UNESCAPED_SLASHES),
        ]);

        $startedAt = microtime(true);
        try {
            $parsed = $this->parseJsonResponse(
                $this->aiService->process('ai_coach_recommendations', ['prompt' => $prompt, 'text' => $prompt])
            );
        } catch (\Throwable $e) {
            $this->recordAiEvent('strategic_pointers', 'capability_failed', 'failed', $startedAt, [], 'ai_process_failed', $e->getMessage());
            return $fallback;
        }
        if (!is_array($parsed)) {
            $this->recordAiEvent('strategic_pointers', 'capability_failed', 'failed', $startedAt, [], 'invalid_ai_json', 'AI strategic pointers response was not valid JSON.');
            return $fallback;
        }

        $out = [];
        foreach (['marketing', 'sales', 'general'] as $key) {
            $values = array_values(array_filter(array_map(
                static fn($item): string => trim((string) $item),
                (array) ($parsed[$key] ?? [])
            )));
            $out[$key] = $values !== [] ? array_slice($values, 0, 4) : $fallback[$key];
        }

        $this->recordAiEvent('strategic_pointers', 'capability_succeeded', 'success', $startedAt, [
            'source_count' => count($evidenceSources),
        ]);

        return $out;
    }

    public function buildActionPlanDraft(string $scopeLabel, array $subjectSummary, array $gaps, bool $allowAi = true): array
    {
        $fallback = $this->fallbackActionPlan($scopeLabel, $subjectSummary, $gaps);
        $settings = $this->settingsModule->get();
        if (!$allowAi || !$settings['ai_enabled']) {
            return $fallback;
        }

        $managerFocus = trim((string) ($settings['prompt_config']['manager_focus'] ?? ''));
        $languageInstruction = $this->languageLevels->promptInstruction($this->languageLevels->currentContext()['level'] ?? WorkspaceLanguageLevelService::DEFAULT_LEVEL);
        $evidenceSources = $this->buildEvidenceSources($subjectSummary, [], $gaps);
        $prompt = implode("\n", [
            'You are drafting a specialist-grade organization improvement plan for a founder, CEO, HRBP, and manager.',
            'Use careful people-risk language: CRM signals are evidence to validate, not character judgments.',
            'Use Work Ownership, organization stage, founder load, and measurement confidence when present.',
            'Use assignment types and relevance states: oversight-only functions remain ownership gaps, deferred functions are maturity choices, outsourced functions need governance review.',
            'Do not treat unstaffed functions as failed departments or founder overload as poor performance.',
            'Return ONLY valid JSON with keys title, summary, diagnosis, likely_causes, manager_questions, seven_day_actions, thirty_day_actions, success_metrics, escalation_triggers, what_not_to_do, confidence, actions.',
            'likely_causes, manager_questions, seven_day_actions, thirty_day_actions, success_metrics, escalation_triggers, what_not_to_do, and actions must be arrays of short strings.',
            'confidence must be low, moderate, or high.',
            $managerFocus !== '' ? 'WORKSPACE MANAGER GUIDANCE FOCUS: ' . $managerFocus : 'WORKSPACE MANAGER GUIDANCE FOCUS: Keep recommendations concrete, explainable, and tied to observable team behaviour.',
            $languageInstruction,
            '',
            'SUBJECT: ' . $scopeLabel,
            'SUMMARY: ' . json_encode($subjectSummary, JSON_UNESCAPED_SLASHES),
            'GAPS: ' . json_encode(array_slice($gaps, 0, 5), JSON_UNESCAPED_SLASHES),
            'EVIDENCE_SOURCES: ' . json_encode($evidenceSources, JSON_UNESCAPED_SLASHES),
        ]);

        $startedAt = microtime(true);
        try {
            $parsed = $this->parseJsonResponse(
                $this->aiService->process('ai_coach_recommendations', ['prompt' => $prompt, 'text' => $prompt])
            );
        } catch (\Throwable $e) {
            $this->recordAiEvent('action_plan', 'capability_failed', 'failed', $startedAt, [
                'scope' => $scopeLabel,
            ], 'ai_process_failed', $e->getMessage());
            return $fallback;
        }
        if (!is_array($parsed)) {
            $this->recordAiEvent('action_plan', 'capability_failed', 'failed', $startedAt, [
                'scope' => $scopeLabel,
            ], 'invalid_ai_json', 'AI action plan response was not valid JSON.');
            return $fallback;
        }

        $actions = $this->coerceStringList($parsed['actions'] ?? []);
        if ($actions === []) {
            $actions = array_slice(array_merge(
                $this->coerceStringList($parsed['seven_day_actions'] ?? []),
                $this->coerceStringList($parsed['thirty_day_actions'] ?? [])
            ), 0, 5);
        }
        if ($actions === []) {
            $this->recordAiEvent('action_plan', 'capability_failed', 'failed', $startedAt, [
                'scope' => $scopeLabel,
            ], 'empty_ai_actions', 'AI action plan response did not include actions.');
            return $fallback;
        }

        $confidence = strtolower($this->coerceTextValue($parsed['confidence'] ?? null, $fallback['confidence'] ?? 'moderate'));
        if (!in_array($confidence, ['low', 'moderate', 'high'], true)) {
            $confidence = (string) ($fallback['confidence'] ?? 'moderate');
        }

        $plan = array_merge($fallback, [
            'title' => $this->coerceTextValue($parsed['title'] ?? null, $fallback['title']),
            'summary' => $this->coerceTextValue($parsed['summary'] ?? null, $fallback['summary']),
            'diagnosis' => $this->coerceTextValue($parsed['diagnosis'] ?? null, $fallback['diagnosis'] ?? ''),
            'likely_causes' => $this->coerceStringList($parsed['likely_causes'] ?? ($fallback['likely_causes'] ?? [])),
            'manager_questions' => $this->coerceStringList($parsed['manager_questions'] ?? ($fallback['manager_questions'] ?? [])),
            'seven_day_actions' => $this->coerceStringList($parsed['seven_day_actions'] ?? ($fallback['seven_day_actions'] ?? [])),
            'thirty_day_actions' => $this->coerceStringList($parsed['thirty_day_actions'] ?? ($fallback['thirty_day_actions'] ?? [])),
            'success_metrics' => $this->coerceStringList($parsed['success_metrics'] ?? ($fallback['success_metrics'] ?? [])),
            'escalation_triggers' => $this->coerceStringList($parsed['escalation_triggers'] ?? ($fallback['escalation_triggers'] ?? [])),
            'what_not_to_do' => $this->coerceStringList($parsed['what_not_to_do'] ?? ($fallback['what_not_to_do'] ?? [])),
            'confidence' => $confidence,
            'actions' => array_slice($actions, 0, 5),
        ]);
        $this->recordAiEvent('action_plan', 'capability_succeeded', 'success', $startedAt, [
            'scope' => $scopeLabel,
            'source_count' => count($evidenceSources),
            'action_count' => count((array) ($plan['actions'] ?? [])),
        ]);

        return $plan;
    }

    private function coerceTextValue(mixed $value, string $fallback): string
    {
        if (is_array($value) || is_object($value)) {
            return $fallback;
        }

        $text = trim((string) ($value ?? ''));
        return $text !== '' ? $text : $fallback;
    }

    private function coerceStringList(mixed $value): array
    {
        $items = is_array($value) ? $value : [$value];
        return array_values(array_filter(array_map(
            static fn($item): string => is_array($item) || is_object($item) ? '' : trim((string) $item),
            $items
        )));
    }

    private function normalizeSwotItem(mixed $item, array $fallback, array $evidenceSources = []): array
    {
        if (is_string($item) || is_numeric($item)) {
            $text = trim((string) $item);
            if ($text === '') {
                return $fallback;
            }
            return [
                'title' => $text,
                'evidence' => $fallback['evidence'] ?? 'Current workspace analytics signal.',
                'impact' => $fallback['impact'] ?? $text,
                'action' => $fallback['action'] ?? 'Validate the signal with managers and choose one measurable next action.',
                'severity' => $fallback['severity'] ?? 'medium',
                'confidence' => $fallback['confidence'] ?? 'moderate',
                'source_metrics' => $fallback['source_metrics'] ?? ['dashboard_summary'],
            ];
        }
        if (!is_array($item)) {
            return $fallback;
        }

        $severity = strtolower($this->coerceTextValue($item['severity'] ?? null, $fallback['severity'] ?? 'medium'));
        if (!in_array($severity, ['low', 'medium', 'high'], true)) {
            $severity = 'medium';
        }
        $confidence = strtolower($this->coerceTextValue($item['confidence'] ?? null, $fallback['confidence'] ?? 'moderate'));
        if (!in_array($confidence, ['low', 'moderate', 'high'], true)) {
            $confidence = 'moderate';
        }

        $normalized = [
            'title' => $this->coerceTextValue($item['title'] ?? null, $fallback['title'] ?? 'Organization signal'),
            'evidence' => $this->coerceTextValue($item['evidence'] ?? null, $fallback['evidence'] ?? 'Current workspace analytics signal.'),
            'impact' => $this->coerceTextValue($item['impact'] ?? null, $fallback['impact'] ?? 'Leadership should review the signal before acting.'),
            'action' => $this->coerceTextValue($item['action'] ?? null, $fallback['action'] ?? 'Validate the signal with managers and choose one measurable next action.'),
            'severity' => $severity,
            'confidence' => $confidence,
        ];
        $sourceMetrics = $this->validSourceMetrics($item['source_metrics'] ?? [], $normalized, $evidenceSources);
        if ($sourceMetrics === []) {
            $fallback['confidence'] = 'low';
            $fallback['source_metrics'] = $fallback['source_metrics'] ?? ['dashboard_summary'];
            return $fallback;
        }

        $normalized['source_metrics'] = $sourceMetrics;
        return $normalized;
    }

    private function buildEvidenceSources(array $summary, array $bestStrategies, array $gaps): array
    {
        $sources = [
            'dashboard_summary' => ['label' => 'Dashboard summary', 'value' => 'Current Organization Intelligence dashboard data.'],
        ];

        foreach (['staff_count', 'avg_score', 'high_performers', 'at_risk_count', 'needs_coaching_count', 'overloaded_count', 'system_active_minutes', 'avg_system_active_minutes'] as $key) {
            if (array_key_exists($key, $summary)) {
                $label = ucwords(str_replace('_', ' ', $key));
                $value = $summary[$key];
                if (in_array($key, ['system_active_minutes', 'avg_system_active_minutes'], true)) {
                    $label = $key === 'avg_system_active_minutes' ? 'Average active time' : 'System active time';
                    $value = number_format(max(0.0, ((float) $summary[$key]) / 60), 1) . ' h';
                }
                $sources[$key] = [
                    'label' => $label,
                    'value' => $value,
                ];
            }
        }

        if (!empty($summary['evidence_confidence']) && is_array($summary['evidence_confidence'])) {
            $sources['evidence_confidence'] = [
                'label' => 'Evidence confidence',
                'value' => (string) ($summary['evidence_confidence']['label'] ?? $summary['evidence_confidence']['level'] ?? 'Unknown'),
            ];
        }
        if (!empty($summary['small_sample_caveat'])) {
            $sources['small_sample_caveat'] = [
                'label' => 'Small sample caveat',
                'value' => (string) $summary['small_sample_caveat'],
            ];
        }
        if (!empty($summary['organization_stage']) && is_array($summary['organization_stage'])) {
            $sources['organization_stage'] = [
                'label' => 'Organization stage',
                'value' => (string) ($summary['organization_stage']['label'] ?? $summary['organization_stage']['stage'] ?? ''),
            ];
        }

        foreach (array_slice($bestStrategies, 0, 4) as $index => $strategy) {
            $sources['strategy_' . ($index + 1)] = [
                'label' => 'Strategy signal ' . ($index + 1),
                'value' => (string) ($strategy['name'] ?? $strategy['strategy_name'] ?? 'Strategy signal'),
            ];
        }

        foreach (array_slice($gaps, 0, 6) as $index => $gap) {
            $sources['gap_' . ($index + 1)] = [
                'label' => (string) ($gap['title'] ?? 'Gap signal ' . ($index + 1)),
                'value' => (string) ($gap['evidence'] ?? $gap['summary'] ?? ''),
            ];
        }

        return $sources;
    }

    private function validSourceMetrics(mixed $sourceMetrics, array $item, array $evidenceSources): array
    {
        $allowed = array_keys($evidenceSources);
        $provided = is_array($sourceMetrics) ? $sourceMetrics : [$sourceMetrics];
        $valid = [];
        foreach ($provided as $sourceMetric) {
            $sourceMetric = trim((string) $sourceMetric);
            if ($sourceMetric !== '' && in_array($sourceMetric, $allowed, true)) {
                $valid[] = $sourceMetric;
            }
        }

        if ($valid !== []) {
            return array_values(array_unique($valid));
        }

        $haystack = strtolower(implode(' ', [
            (string) ($item['title'] ?? ''),
            (string) ($item['evidence'] ?? ''),
            (string) ($item['impact'] ?? ''),
            (string) ($item['action'] ?? ''),
        ]));
        foreach ($evidenceSources as $key => $source) {
            $label = strtolower((string) ($source['label'] ?? ''));
            $value = strtolower((string) ($source['value'] ?? ''));
            $keyText = strtolower((string) $key);
            if (
                ($label !== '' && str_contains($haystack, $label))
                || ($keyText !== '' && str_contains($haystack, str_replace('_', ' ', $keyText)))
                || ($value !== '' && strlen($value) >= 4 && str_contains($haystack, $value))
            ) {
                $valid[] = (string) $key;
            }
        }

        return array_values(array_unique($valid));
    }

    private function recordAiEvent(
        string $capability,
        string $eventType,
        string $status,
        float $startedAt,
        array $metadata = [],
        ?string $errorCode = null,
        ?string $errorMessage = null
    ): void {
        $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        if ($workspaceId <= 0) {
            return;
        }

        $this->runtimeEvents->record([
            'workspace_id' => $workspaceId,
            'user_id' => null,
            'skill_key' => WorkspaceSkillCatalogService::PLUGIN_HR_ANALYTICS_SETUP,
            'capability_key' => 'hr_analytics.ai.' . trim($capability),
            'event_type' => $eventType,
            'status' => $status,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
            'metadata' => $metadata,
        ]);
    }

    private function parseJsonResponse(string $response): ?array
    {
        $response = trim($response);
        if ($response === '') {
            return null;
        }

        $decoded = json_decode($response, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{.*\}/s', $response, $matches) !== 1) {
            return null;
        }

        $decoded = json_decode($matches[0], true);
        return is_array($decoded) ? $decoded : null;
    }

    private function fallbackSwot(string $scopeLabel, array $summary, array $bestStrategies, array $gaps): array
    {
        $topStrategy = $bestStrategies[0]['name'] ?? 'Execution discipline';
        $highPerformers = (int) ($summary['high_performers'] ?? 0);
        $atRisk = (int) ($summary['at_risk_count'] ?? 0);
        $overloaded = (int) ($summary['overloaded_count'] ?? 0);
        $stage = (string) ($summary['organization_stage']['label'] ?? $summary['organization_stage']['stage'] ?? '');
        $nextFunction = (string) ($summary['next_function_to_formalize']['function'] ?? '');
        $functionCoverage = (array) ($summary['function_coverage'] ?? []);
        $coverageGap = null;
        $deferredFunction = null;
        foreach ($functionCoverage as $function) {
            $state = (string) ($function['state'] ?? '');
            if ($coverageGap === null && $state === 'unassigned' && (string) ($function['relevance_status'] ?? 'active') === 'active') {
                $coverageGap = (array) $function;
            }
            if ($deferredFunction === null && in_array((string) ($function['relevance_status'] ?? ''), ['deferred', 'outsourced'], true)) {
                $deferredFunction = (array) $function;
            }
        }

        return [
            'strengths' => [
                [
                    'title' => $highPerformers > 0 ? 'Repeatable high-performance signal' : 'Measurable operating baseline',
                    'evidence' => $highPerformers > 0 ? "{$highPerformers} team member(s) are above the high-performer threshold." : "{$scopeLabel} has enough score data to establish a baseline.",
                    'impact' => $highPerformers > 0 ? 'Leadership can study what is working and turn it into manager coaching routines.' : 'A baseline lets leadership start tracking whether interventions improve execution.',
                    'action' => 'Identify the behaviours behind the strongest signal and codify them into a lightweight playbook.',
                    'severity' => 'low',
                    'confidence' => 'moderate',
                    'source_metrics' => ['high_performers', 'dashboard_summary'],
                ],
                [
                    'title' => $stage !== '' ? 'Stage-aware operating view' : 'Strategy pattern to reuse',
                    'evidence' => $stage !== '' ? "Current organization stage: {$stage}." : "Top strategy signal: {$topStrategy}.",
                    'impact' => $stage !== '' ? 'Leadership can judge departments, functions, and founder load according to business maturity.' : 'A visible strategy pattern gives managers something concrete to reinforce.',
                    'action' => $stage !== '' ? 'Use stage language in the next operating review before comparing departments.' : 'Translate this pattern into a weekly expectation for relevant teams.',
                    'severity' => 'low',
                    'confidence' => 'moderate',
                    'source_metrics' => [$stage !== '' ? 'organization_stage' : 'strategy_1'],
                ],
            ],
            'weaknesses' => [
                [
                    'title' => $atRisk > 0 ? 'At-risk performance cluster' : 'At-risk monitoring needed',
                    'evidence' => $atRisk > 0 ? "{$atRisk} staff member(s) are currently flagged as at risk." : 'Few at-risk staff are visible in this filtered window.',
                    'impact' => $atRisk > 0 ? 'If this is not reviewed, execution gaps may become normalized.' : 'The organization still needs active monitoring to catch early changes.',
                    'action' => $atRisk > 0 ? 'Validate whether this is capability, clarity, workload, role-fit, or tracking hygiene before choosing an intervention.' : 'Keep reviewing weekly trend movement before acting.',
                    'severity' => $atRisk > 0 ? 'high' : 'low',
                    'confidence' => 'moderate',
                    'source_metrics' => ['at_risk_count', 'dashboard_summary'],
                ],
                [
                    'title' => $coverageGap ? (string) ($coverageGap['name'] ?? 'Function') . ' ownership gap' : ($overloaded > 0 ? 'Visible workload pressure' : 'Workload balance watchpoint'),
                    'evidence' => $coverageGap ? (string) ($coverageGap['evidence'] ?? 'An active function has no accountable owner.') : ($overloaded > 0 ? "{$overloaded} staff member(s) show overload or uneven task distribution." : 'No major overload signal is visible, but workload balance still needs monitoring.'),
                    'impact' => $coverageGap ? 'Unowned active functions create accountability ambiguity before they create department performance problems.' : 'Hidden workload pressure can reduce quality, slow follow-through, and increase burnout risk.',
                    'action' => $coverageGap ? (string) ($coverageGap['recommended_next_move'] ?? 'Assign an accountable owner or mark the function deferred.') : 'Review overdue work allocation before assigning new priority work.',
                    'severity' => ($coverageGap || $overloaded > 0) ? 'medium' : 'low',
                    'confidence' => 'moderate',
                    'source_metrics' => [$coverageGap ? 'dashboard_summary' : 'overloaded_count'],
                ],
            ],
            'opportunities' => [
                [
                    'title' => 'Turn strong cohorts into coaching templates',
                    'evidence' => 'Top-performer and strategy signals are already visible in the dashboard.',
                    'impact' => 'Managers can scale repeatable behaviours instead of relying on individual heroics.',
                    'action' => 'Build a one-page playbook from top performers and use it in the next manager check-in.',
                    'severity' => 'low',
                    'confidence' => 'moderate',
                    'source_metrics' => ['dashboard_summary', 'strategy_1'],
                ],
                [
                    'title' => $nextFunction !== '' ? "Formalize {$nextFunction}" : 'Tune the operating model',
                    'evidence' => $nextFunction !== '' ? "Next function to formalize: {$nextFunction}." : 'Role-specific weights already distinguish marketing, sales, and operations.',
                    'impact' => $nextFunction !== '' ? 'The next structural improvement becomes concrete instead of generic process advice.' : 'Better weighting can make the center more credible for each team lane.',
                    'action' => $nextFunction !== '' ? 'Give this function a named owner, review rhythm, and one success metric.' : 'Review whether the scoring weights match how each department creates value.',
                    'severity' => 'low',
                    'confidence' => 'moderate',
                    'source_metrics' => [$nextFunction !== '' ? 'dashboard_summary' : 'dashboard_summary'],
                ],
            ],
            'threats' => [
                [
                    'title' => 'Top-performer dependency',
                    'evidence' => 'Strong and weak performance signals are visible in the same operating window.',
                    'impact' => 'Delivery may lean too heavily on a small group if weaker execution habits are not coached.',
                    'action' => 'Rebalance work and convert top-performer routines into manager-owned standards.',
                    'severity' => 'medium',
                    'confidence' => 'moderate',
                    'source_metrics' => ['dashboard_summary'],
                ],
                [
                    'title' => $deferredFunction ? (string) ($deferredFunction['name'] ?? 'Function') . ' governance trigger' : 'Busy work can hide weak follow-through',
                    'evidence' => $deferredFunction ? (string) ($deferredFunction['evidence'] ?? 'A function is deferred or outsourced.') : 'Activity, workload, and outcome metrics can diverge.',
                    'impact' => $deferredFunction ? 'Deferred or outsourced functions need a review trigger so they do not become invisible risks.' : 'Teams may look active while strategic outcomes remain weak.',
                    'action' => $deferredFunction ? (string) ($deferredFunction['recommended_next_move'] ?? 'Define when leadership will review this function again.') : 'Review activity with outcome-linked metrics before scaling work volume.',
                    'severity' => 'medium',
                    'confidence' => 'moderate',
                    'source_metrics' => ['dashboard_summary'],
                ],
            ],
        ];
    }

    private function fallbackManagerTips(array $summary, array $gaps, array $strategies, string $departmentLabel): array
    {
        $tips = [
            "Use {$departmentLabel} leaderboards to coach from evidence, not anecdotes.",
            'Pair every underperformance conversation with one concrete weekly action and one measurable outcome.',
            'Protect top performers from hidden overload by redistributing work before burnout shows up in missed follow-through.',
        ];

        if (($summary['avg_score'] ?? 0) < 60) {
            $tips[] = 'Tighten weekly operating rhythm: shorter check-ins, fewer priorities, and clearer ownership per person.';
        }
        if (!empty($gaps)) {
            $tips[] = 'Review the gap panel first when planning interventions; it usually highlights faster wins than broad retraining.';
        }
        if (!empty($strategies)) {
            $tips[] = 'Convert the best-performing strategy signals into reusable playbooks for managers and team leads.';
        }

        return array_slice($tips, 0, 6);
    }

    private function fallbackStrategicPointers(array $roleSummaries, array $strategies, array $gaps): array
    {
        $marketingAvg = (float) ($roleSummaries['marketing']['avg_score'] ?? 0.0);
        $salesAvg = (float) ($roleSummaries['sales']['avg_score'] ?? 0.0);
        $generalAvg = (float) ($roleSummaries['general']['avg_score'] ?? 0.0);

        return [
            'marketing' => [
                $marketingAvg >= 65 ? 'Scale the campaign habits used by high-performing marketers before adding new channels.' : 'Reduce channel sprawl and concentrate marketers on the few campaigns that are converting.',
                'Review activity consistency and outcome impact together so busy marketers are not confused with effective marketers.',
                'Turn strong campaign ownership patterns into coaching templates for weaker cohorts.',
            ],
            'sales' => [
                $salesAvg >= 65 ? 'Reinforce follow-up discipline and deal hygiene with visible weekly scorecards.' : 'Focus sales coaching on overdue follow-ups, stage movement, and clean pipeline maintenance.',
                'Promote behaviours that move deals forward, not just raw activity volume.',
                'Use top-performer evidence to standardize qualification and next-step routines.',
            ],
            'general' => [
                $generalAvg >= 65 ? 'Protect reliable operators from becoming silent bottlenecks by balancing assignment load.' : 'Clarify task ownership and due-date discipline for operational staff before adding more process.',
                'Coach for timeliness and consistency first; throughput improves when queue discipline improves.',
                !empty($gaps) ? 'Tie team interventions to the most repeated gap themes rather than one-off incidents.' : 'Keep weekly reviews focused on repeatable operating habits.',
            ],
        ];
    }

    private function fallbackActionPlan(string $scopeLabel, array $subjectSummary, array $gaps): array
    {
        $primaryGap = (array) ($gaps[0] ?? []);
        $hasConcreteEvidence = $this->hasConcreteActionPlanEvidence($subjectSummary, $gaps);
        $actions = [
            'Set one weekly performance goal tied to the weakest visible operating signal.',
            'Validate whether the signal is caused by workload, clarity, role fit, capability, or tracking hygiene.',
            'Review progress with a short manager check-in and update the next action immediately.',
        ];

        if (($subjectSummary['overdue_open_tasks'] ?? 0) > 0) {
            $actions[] = 'Clear overdue open tasks before adding new priority work.';
        }
        if (!empty($gaps)) {
            $actions[] = 'Document the top recurring gap and assign one owner to resolve it this cycle.';
        }
        if (!empty($subjectSummary['next_function_to_formalize']['function'])) {
            $actions[] = 'Formalize ownership for ' . (string) $subjectSummary['next_function_to_formalize']['function'] . ' before treating it as department performance.';
        }

        return [
            'title' => "Action Plan: {$scopeLabel}",
            'summary' => $hasConcreteEvidence
                ? 'A focused improvement plan based on visible workload, follow-through, organization health, and performance signals.'
                : 'Limited evidence is visible in this scope; use this draft to validate what is happening before assigning performance conclusions.',
            'diagnosis' => (string) ($primaryGap['summary'] ?? ($hasConcreteEvidence
                ? 'The current view shows an operating signal that should be validated before leadership treats it as a people-performance conclusion.'
                : 'Not enough concrete operating evidence is visible for a confident diagnosis yet. Start by confirming workload, ownership, and tracking quality.')),
            'likely_causes' => [
                'Role clarity, workload volume, capability gaps, manager support, or work happening outside the CRM may be contributing.',
                'Current data may reflect tracking behaviour as much as true performance.',
                'Work Ownership, founder load, or low measurement confidence may explain the signal better than individual underperformance.',
            ],
            'manager_questions' => [
                'What is making follow-through harder right now: workload, clarity, confidence, tools, or competing priorities?',
                'Which commitments should be paused, reassigned, or narrowed this week?',
                'What support would make the next measurable outcome more likely?',
            ],
            'seven_day_actions' => array_slice($actions, 0, 3),
            'thirty_day_actions' => [
                'Review trend movement against the same metric after two weekly check-ins.',
                'Document one repeatable operating habit that should become the team standard.',
                'Escalate only if the pattern persists after support, clarity, and workload adjustments.',
            ],
            'success_metrics' => [
                'Fewer overdue commitments or clearer completion rhythm.',
                'Improved score on the weakest visible metric.',
                'Manager can name the next owner, action, and review date.',
            ],
            'escalation_triggers' => [
                'Risk signal worsens across two review cycles.',
                'Workload remains concentrated after rebalance.',
                'Manager validation identifies role-fit, conduct, or wellbeing concerns requiring formal HR review.',
            ],
            'what_not_to_do' => [
                'Do not label the person or team as disengaged from CRM data alone.',
                'Do not add more priority work before resolving queue pressure and role clarity.',
            ],
            'confidence' => $hasConcreteEvidence ? 'moderate' : 'low',
            'actions' => array_slice($actions, 0, 5),
            'source_metrics' => $hasConcreteEvidence ? ['visible_operating_signals'] : ['insufficient_evidence'],
        ];
    }

    private function hasConcreteActionPlanEvidence(array $subjectSummary, array $gaps): bool
    {
        if ($gaps !== []) {
            return true;
        }

        foreach ([
            'overdue_open_tasks',
            'open_tasks',
            'overloaded_count',
            'at_risk_count',
            'needs_coaching_count',
            'activity_count',
            'system_active_minutes',
            'system_active_hours',
            'outcome_events',
        ] as $key) {
            if ((float) ($subjectSummary[$key] ?? 0) > 0) {
                return true;
            }
        }

        $confidence = strtolower((string) ($subjectSummary['evidence_confidence']['level'] ?? ''));
        return in_array($confidence, ['moderate', 'high'], true);
    }
}
