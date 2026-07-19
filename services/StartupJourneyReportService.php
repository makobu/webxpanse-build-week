<?php

namespace CRM\Services;

use CRM\Database;

class StartupJourneyReportService
{
    private const ARTIFACT_TYPE = 'journey_report';
    private const REPORT_VERSION = '1.0';

    private StartupJourneyService $journeyService;
    private AIService $aiService;
    private AICoachAssumptionConflictService $conflictService;
    private FounderOperatingLoopService $founderLoopService;
    private AICoachReadinessService $coachReadinessService;

    public function __construct(
        ?StartupJourneyService $journeyService = null,
        ?AIService $aiService = null,
        ?AICoachAssumptionConflictService $conflictService = null,
        ?FounderOperatingLoopService $founderLoopService = null,
        ?AICoachReadinessService $coachReadinessService = null
    ) {
        $this->journeyService = $journeyService ?? new StartupJourneyService();
        $this->aiService = $aiService ?? new AIService();
        $this->conflictService = $conflictService ?? new AICoachAssumptionConflictService();
        $this->founderLoopService = $founderLoopService ?? new FounderOperatingLoopService();
        $this->coachReadinessService = $coachReadinessService ?? new AICoachReadinessService();
    }

    /**
     * @return array<string,mixed>
     */
    public function build(int $workspaceId, int $userId, bool $allowAi = true): array
    {
        $workspaceId = max(0, $workspaceId);
        $userId = max(0, $userId);
        if ($workspaceId <= 0 || $userId <= 0) {
            throw new \InvalidArgumentException('Workspace and user are required for a Clarity Journey report.');
        }

        $journey = $this->safeArray(fn(): array => $this->journeyService->getJourney($workspaceId, $userId));
        $journeyContext = $this->safeArray(fn(): array => $this->journeyService->getContextForAI($workspaceId, $userId));
        $journeyReadiness = $this->safeArray(fn(): array => $this->journeyService->readiness($workspaceId, $userId));
        $founderLoop = $this->safeArray(fn(): array => $this->founderLoopService->summary($workspaceId, $userId));
        $coachReadiness = $this->safeArray(fn(): array => $this->coachReadinessService->getReadiness($workspaceId, $userId));
        $financeContext = array_merge(
            (array) ($founderLoop['finance_snapshot'] ?? []),
            (array) ($founderLoop['pricing'] ?? [])
        );
        $assumptionConflicts = $this->safeArray(fn(): array => $this->conflictService->detect($workspaceId, $userId, [
            'startup_journey_context' => $journeyContext,
            'founder_operating_loop_context' => $founderLoop,
            'finance_context' => $financeContext,
        ]));

        $stageHealth = $this->stageHealth($journey, $assumptionConflicts);
        $journeyHealth = $this->journeyHealth($journey, $journeyReadiness, $stageHealth, $assumptionConflicts);
        $founderLoopBridge = $this->founderLoopBridge($founderLoop, $journeyHealth);
        $nextActions = $this->nextActions($journey, $journeyReadiness, $assumptionConflicts, $founderLoop, $coachReadiness);
        $caveats = $this->caveats($journey, $coachReadiness, $allowAi);

        $report = [
            'executive_summary' => $this->deterministicSummary($journeyHealth, $stageHealth, $assumptionConflicts, $founderLoopBridge),
            'journey_health' => $journeyHealth,
            'stage_health' => $stageHealth,
            'swot' => $this->swotUnavailable($allowAi ? 'AI narrative SWOT was unavailable.' : 'AI narrative SWOT was not requested.'),
            'assumption_conflicts' => $this->normalizeConflicts($assumptionConflicts),
            'founder_loop_bridge' => $founderLoopBridge,
            'next_actions' => $nextActions,
            'caveats' => $caveats,
            'report_meta' => [
                'artifact_type' => self::ARTIFACT_TYPE,
                'version' => self::REPORT_VERSION,
                'generated_at' => date('Y-m-d H:i:s'),
                'workspace_id' => $workspaceId,
                'user_id' => $userId,
                'ai_requested' => $allowAi,
                'ai_status' => $allowAi ? 'pending' : 'not_requested',
            ],
        ];

        if ($allowAi) {
            $aiNarrative = $this->aiNarrative($report, [
                'journey' => $this->compactJourneyForAI($journey),
                'journey_readiness' => $journeyReadiness,
                'founder_loop' => $this->compactFounderLoopForAI($founderLoop),
                'coach_readiness' => $this->compactCoachReadinessForAI($coachReadiness),
                'assumption_conflicts' => $report['assumption_conflicts'],
            ]);
            if (trim((string) ($aiNarrative['executive_summary'] ?? '')) !== '') {
                $report['executive_summary'] = (string) $aiNarrative['executive_summary'];
            }
            $report['swot'] = (array) ($aiNarrative['swot'] ?? $report['swot']);
            $report['report_meta']['ai_status'] = (string) ($aiNarrative['ai_status'] ?? 'unavailable');
            $report['report_meta']['ai_message'] = (string) ($aiNarrative['ai_message'] ?? '');
        }

        return $report;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function latest(int $workspaceId, int $userId): ?array
    {
        if (!Database::tableExists('startup_journey_artifacts')) {
            return null;
        }

        $row = Database::queryOne(
            "SELECT *
             FROM startup_journey_artifacts
             WHERE workspace_id = ?
               AND user_id = ?
               AND artifact_type = ?
             ORDER BY id DESC
             LIMIT 1",
            [max(0, $workspaceId), max(0, $userId), self::ARTIFACT_TYPE]
        );
        if (!$row) {
            return null;
        }

        $content = json_decode((string) ($row['content_json'] ?? '{}'), true);
        if (!is_array($content)) {
            return null;
        }

        $content['report_meta'] = array_merge((array) ($content['report_meta'] ?? []), [
            'artifact_id' => (int) ($row['id'] ?? 0),
            'artifact_created_at' => (string) ($row['created_at'] ?? ''),
            'artifact_updated_at' => (string) ($row['updated_at'] ?? ''),
        ]);

        return $content;
    }

    public function saveArtifact(int $workspaceId, int $userId, array $report): int
    {
        if (!Database::tableExists('startup_journey_artifacts')) {
            throw new \RuntimeException('Clarity Journey artifact storage is not available.');
        }

        $journey = $this->journeyService->getJourney($workspaceId, $userId);
        $journeyId = (int) ($journey['journey_id'] ?? 0);
        if ($journeyId <= 0) {
            throw new \RuntimeException('Clarity Journey is not available for this workspace.');
        }

        Database::execute(
            "INSERT INTO startup_journey_artifacts (
                journey_id, workspace_id, user_id, artifact_type, title, content_json, created_by
             ) VALUES (?, ?, ?, ?, ?, ?, ?)",
            [
                $journeyId,
                $workspaceId,
                $userId,
                self::ARTIFACT_TYPE,
                'Clarity Journey Report',
                json_encode($report, JSON_UNESCAPED_SLASHES),
                $userId,
            ]
        );

        return (int) Database::lastInsertId();
    }

    /**
     * @param callable():array $callback
     * @return array<string,mixed>
     */
    private function safeArray(callable $callback): array
    {
        try {
            $value = $callback();
            return is_array($value) ? $value : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * @param array<string,mixed> $journey
     * @param list<array<string,mixed>> $conflicts
     * @return list<array<string,mixed>>
     */
    private function stageHealth(array $journey, array $conflicts): array
    {
        $items = [];
        $conflictsByStage = $this->conflictsByStage($conflicts);
        foreach ((array) ($journey['stages'] ?? []) as $stageKey => $stage) {
            $stageKey = (string) ($stage['stage_key'] ?? $stageKey);
            $readiness = (array) ($stage['readiness'] ?? []);
            $stageConflicts = (array) ($conflictsByStage[$stageKey] ?? []);
            $labels = $this->stageLabels((array) $stage, $readiness, $stageConflicts);
            $reviewUrl = 'startup_journey.php?stage=' . rawurlencode($stageKey);
            $items[] = [
                'stage_key' => $stageKey,
                'label' => (string) ($stage['label'] ?? $stageKey),
                'status' => (string) ($stage['status'] ?? 'not_started'),
                'filled_fields' => (int) ($stage['filled_fields'] ?? 0),
                'total_fields' => (int) ($stage['total_fields'] ?? 0),
                'readiness_label' => (string) ($readiness['label'] ?? 'Not started'),
                'readiness_status' => (string) ($readiness['status'] ?? 'not_started'),
                'completion_risk' => (string) ($readiness['completion_risk'] ?? 'medium'),
                'strong_fields' => (int) ($readiness['strong_fields'] ?? 0),
                'thin_fields' => (int) ($readiness['thin_fields'] ?? 0),
                'empty_fields' => (int) ($readiness['empty_fields'] ?? 0),
                'summary' => (string) ($readiness['summary'] ?? 'No saved answers yet.'),
                'labels' => $labels,
                'warnings' => $this->stageWarnings((array) $stage, $readiness, $stageConflicts),
                'conflict_count' => count($stageConflicts),
                'review_url' => $reviewUrl,
                'updated_at' => (string) ($stage['updated_at'] ?? ''),
                'completed_at' => (string) ($stage['completed_at'] ?? ''),
            ];
        }

        return $items;
    }

    /**
     * @param array<string,mixed> $journey
     * @param array<string,mixed> $journeyReadiness
     * @param list<array<string,mixed>> $stageHealth
     * @param list<array<string,mixed>> $conflicts
     * @return array<string,mixed>
     */
    private function journeyHealth(array $journey, array $journeyReadiness, array $stageHealth, array $conflicts): array
    {
        $progress = (array) ($journey['progress'] ?? []);
        $labels = [];
        $staleCount = 0;
        $thinCount = 0;
        $evidenceBackedCount = 0;
        foreach ($stageHealth as $stage) {
            $stageLabels = (array) ($stage['labels'] ?? []);
            if (in_array('stale', $stageLabels, true)) {
                $staleCount++;
            }
            if (in_array('thin', $stageLabels, true)) {
                $thinCount++;
            }
            if (in_array('evidence-backed', $stageLabels, true)) {
                $evidenceBackedCount++;
            }
        }

        if (!empty($journeyReadiness['ready'])) {
            $labels[] = 'completed';
        }
        if ($evidenceBackedCount > 0) {
            $labels[] = 'evidence-backed';
        }
        if ($thinCount > 0) {
            $labels[] = 'thin';
        }
        if ($conflicts !== []) {
            $labels[] = 'contradicted';
        }
        if ($staleCount > 0) {
            $labels[] = 'stale';
        }

        return [
            'status' => (string) ($journeyReadiness['status'] ?? 'needs_setup'),
            'message' => (string) ($journeyReadiness['message'] ?? 'Clarity Journey needs more context.'),
            'labels' => array_values(array_unique($labels)),
            'progress' => [
                'completed' => (int) ($progress['completed'] ?? 0),
                'total' => (int) ($progress['total'] ?? count($stageHealth)),
                'draft' => (int) ($progress['draft'] ?? 0),
                'pending' => (int) ($progress['pending'] ?? 0),
                'percent' => (int) ($progress['percent'] ?? 0),
            ],
            'current_stage_key' => (string) ($journey['current_stage_key'] ?? $journeyReadiness['current_stage_key'] ?? ''),
            'conflict_count' => count($conflicts),
            'stale_stage_count' => $staleCount,
            'thin_stage_count' => $thinCount,
            'evidence_backed_stage_count' => $evidenceBackedCount,
            'next_action' => (string) ($journeyReadiness['next_action'] ?? ''),
        ];
    }

    /**
     * @param array<string,mixed> $founderLoop
     * @param array<string,mixed> $journeyHealth
     * @return array<string,mixed>
     */
    private function founderLoopBridge(array $founderLoop, array $journeyHealth): array
    {
        $currentStep = (array) ($founderLoop['current_step'] ?? []);
        $firstCustomerSignal = (array) ($founderLoop['first_customer_signal'] ?? []);
        return [
            'available' => $founderLoop !== [],
            'current_step_key' => (string) ($founderLoop['current_step_key'] ?? ''),
            'current_step_label' => (string) ($currentStep['label'] ?? ''),
            'next_action' => (string) ($founderLoop['next_action'] ?? ($journeyHealth['next_action'] ?? '')),
            'first_customer_signal' => [
                'leads_created' => (int) ($firstCustomerSignal['leads_created'] ?? 0),
                'open_deals' => (int) ($firstCustomerSignal['open_deals'] ?? 0),
                'deals_won' => (int) ($firstCustomerSignal['deals_won'] ?? 0),
                'paid_customer_count' => (int) ($firstCustomerSignal['paid_customer_count'] ?? 0),
                'gap' => (string) ($firstCustomerSignal['gap'] ?? ''),
            ],
            'pricing_warnings' => array_values((array) ($founderLoop['pricing']['warnings'] ?? [])),
            'commitment_count' => count((array) ($founderLoop['commitments'] ?? [])),
            'weekly_plan_focus' => (string) ($founderLoop['weekly_plan']['focus'] ?? ''),
        ];
    }

    /**
     * @param array<string,mixed> $journey
     * @param array<string,mixed> $journeyReadiness
     * @param list<array<string,mixed>> $conflicts
     * @param array<string,mixed> $founderLoop
     * @param array<string,mixed> $coachReadiness
     * @return list<array<string,mixed>>
     */
    private function nextActions(array $journey, array $journeyReadiness, array $conflicts, array $founderLoop, array $coachReadiness): array
    {
        $actions = [];
        if (empty($journeyReadiness['ready'])) {
            $stageKey = (string) ($journeyReadiness['current_stage_key'] ?? $journey['current_stage_key'] ?? '');
            $stage = (array) (($journey['stages'][$stageKey] ?? []) ?: []);
            $actions[] = [
                'title' => 'Complete the next Clarity Journey stage',
                'description' => (string) ($journeyReadiness['next_action'] ?? 'Complete the next Clarity Journey stage in the hub.'),
                'source' => 'journey_readiness',
                'priority' => 'high',
                'cta_label' => 'Review stage',
                'cta_url' => $stageKey !== '' ? 'startup_journey.php?stage=' . rawurlencode($stageKey) : 'startup_journey.php',
                'stage_key' => $stageKey,
                'stage_label' => (string) ($stage['label'] ?? ''),
            ];
        }

        foreach (array_slice($conflicts, 0, 3) as $conflict) {
            $stageKey = $this->primaryStageForConflict((string) ($conflict['type'] ?? ''));
            $actions[] = [
                'title' => 'Resolve ' . $this->titleFromKey((string) ($conflict['type'] ?? 'assumption')) . ' evidence',
                'description' => (string) ($conflict['recommended_action'] ?? 'Review the conflicting assumption and update the Journey.'),
                'source' => 'assumption_conflict',
                'priority' => (string) ($conflict['severity'] ?? 'medium'),
                'cta_label' => 'Review stage',
                'cta_url' => 'startup_journey.php?stage=' . rawurlencode($stageKey),
                'stage_key' => $stageKey,
            ];
        }

        $loopAction = trim((string) ($founderLoop['next_action'] ?? ''));
        if ($loopAction !== '') {
            $actions[] = [
                'title' => 'Move the Founder Loop forward',
                'description' => $loopAction,
                'source' => 'founder_loop',
                'priority' => 'medium',
                'cta_label' => 'Open Founder Loop',
                'cta_url' => 'founder_operating_loop.php',
            ];
        }

        if (empty($coachReadiness['recommendations_ready'])) {
            $missing = (array) ($coachReadiness['missing_requirements'] ?? []);
            $firstMissing = (array) ($missing[0] ?? []);
            if ($firstMissing !== []) {
                $actions[] = [
                    'title' => 'Unlock stronger AI Coach guidance',
                    'description' => (string) ($firstMissing['message'] ?? 'Complete the missing AI Coach readiness step.'),
                    'source' => 'ai_coach_readiness',
                    'priority' => 'medium',
                    'cta_label' => 'Open setup',
                    'cta_url' => (string) ($coachReadiness['marketplace_url'] ?? 'workspace_skills.php'),
                ];
            }
        }

        return array_slice($actions, 0, 6);
    }

    /**
     * @param array<string,mixed> $journey
     * @param array<string,mixed> $coachReadiness
     * @return list<string>
     */
    private function caveats(array $journey, array $coachReadiness, bool $allowAi): array
    {
        $caveats = [];
        $progress = (array) ($journey['progress'] ?? []);
        if ((int) ($progress['completed'] ?? 0) < (int) ($progress['total'] ?? 0)) {
            $caveats[] = 'The report is based on an incomplete Clarity Journey, so recommendations should be treated as directional.';
        }
        if (empty($coachReadiness['recommendations_ready'])) {
            $caveats[] = 'AI Coach readiness is incomplete, so some guidance may stay gated until setup is finished.';
        }
        if (!$allowAi) {
            $caveats[] = 'AI narrative SWOT was not requested for this report build.';
        }
        return $caveats;
    }

    /**
     * @param array<string,mixed> $journeyHealth
     * @param list<array<string,mixed>> $stageHealth
     * @param list<array<string,mixed>> $conflicts
     * @param array<string,mixed> $founderLoopBridge
     */
    private function deterministicSummary(array $journeyHealth, array $stageHealth, array $conflicts, array $founderLoopBridge): string
    {
        $progress = (array) ($journeyHealth['progress'] ?? []);
        $completed = (int) ($progress['completed'] ?? 0);
        $total = (int) ($progress['total'] ?? count($stageHealth));
        $parts = [];
        $parts[] = 'Clarity Journey is ' . $completed . ' of ' . $total . ' stages complete.';
        if ($conflicts !== []) {
            $parts[] = count($conflicts) . ' assumption conflict' . (count($conflicts) === 1 ? ' needs' : 's need') . ' review before scaling advice.';
        }
        if (!empty($founderLoopBridge['available']) && (string) ($founderLoopBridge['next_action'] ?? '') !== '') {
            $parts[] = 'Founder Loop next action: ' . (string) $founderLoopBridge['next_action'];
        }
        return implode(' ', $parts);
    }

    /**
     * @param array<string,mixed> $report
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function aiNarrative(array $report, array $context): array
    {
        try {
            $prompt = $this->buildReportPrompt($report, $context);
            $resolvedPrompt = $this->aiService->buildPromptFromRegistry('startup_journey', 'journey_report', [
                'surface' => 'startup_journey',
                'prompt_key' => 'journey_report',
                'blocks' => [
                    ['key' => 'report_context', 'content' => $context],
                    ['key' => 'deterministic_report', 'content' => $this->compactReportForAI($report)],
                ],
            ], [
                'legacy_prompt' => $prompt,
            ]);
            $raw = $this->aiService->processWithPrompt('startup_journey_report', $resolvedPrompt, [
                'fast_fallback' => true,
            ]);
            $parsed = $this->decodeAiJson($raw);
            if ($parsed === []) {
                return [
                    'swot' => $this->swotUnavailable('AI narrative returned no valid JSON.'),
                    'ai_status' => 'invalid_json',
                    'ai_message' => 'AI narrative returned no valid JSON.',
                ];
            }
            $swot = $this->normalizeSwot((array) ($parsed['swot'] ?? []));
            if (($swot['status'] ?? '') !== 'available') {
                return [
                    'swot' => $this->swotUnavailable('AI narrative did not include a usable SWOT.'),
                    'ai_status' => 'invalid_swot',
                    'ai_message' => 'AI narrative did not include a usable SWOT.',
                ];
            }
            return [
                'executive_summary' => $this->shortText($parsed['executive_summary'] ?? '', 900),
                'swot' => $swot,
                'ai_status' => 'available',
                'ai_message' => 'AI narrative generated successfully.',
            ];
        } catch (\Throwable $e) {
            return [
                'swot' => $this->swotUnavailable('AI narrative SWOT was unavailable.'),
                'ai_status' => 'unavailable',
                'ai_message' => $e->getMessage(),
            ];
        }
    }

    /**
     * @param array<string,mixed> $report
     * @param array<string,mixed> $context
     */
    private function buildReportPrompt(array $report, array $context): string
    {
        return "You are Clarity, writing a concise internal Clarity Journey report for a founder.\n"
            . "Return ONLY valid JSON. Do not wrap it in markdown.\n"
            . "Use only the supplied evidence. Do not invent metrics, customers, revenue, or market facts.\n"
            . "JSON shape: {\"executive_summary\":\"...\",\"swot\":{\"strengths\":[],\"weaknesses\":[],\"opportunities\":[],\"threats\":[]}}\n"
            . "Each SWOT item must be an object with title, evidence, confidence, source_refs, and next_action.\n"
            . "Use confidence low, medium, or high. Put no more than 3 items in each SWOT quadrant.\n\n"
            . "DETERMINISTIC REPORT:\n" . json_encode($this->compactReportForAI($report), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n\n"
            . "CONTEXT:\n" . json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeAiJson(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return [];
        }
        $raw = preg_replace('/^```(?:json)?\s*/i', '', $raw) ?? $raw;
        $raw = preg_replace('/\s*```$/', '', $raw) ?? $raw;
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        if (preg_match('/\{.*\}/s', $raw, $match) === 1) {
            $decoded = json_decode($match[0], true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    /**
     * @param array<string,mixed> $swot
     * @return array<string,mixed>
     */
    private function normalizeSwot(array $swot): array
    {
        $out = ['status' => 'available'];
        foreach (['strengths', 'weaknesses', 'opportunities', 'threats'] as $quadrant) {
            $items = [];
            foreach (array_slice((array) ($swot[$quadrant] ?? []), 0, 3) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $title = $this->shortText($item['title'] ?? '', 120);
                $evidence = $this->shortText($item['evidence'] ?? '', 320);
                if ($title === '' || $evidence === '') {
                    continue;
                }
                $confidence = strtolower($this->shortText($item['confidence'] ?? 'medium', 20));
                if (!in_array($confidence, ['low', 'medium', 'high'], true)) {
                    $confidence = 'medium';
                }
                $items[] = [
                    'title' => $title,
                    'evidence' => $evidence,
                    'confidence' => $confidence,
                    'source_refs' => array_values(array_filter(array_map(
                        fn($ref): string => $this->shortText($ref, 80),
                        (array) ($item['source_refs'] ?? [])
                    ))),
                    'next_action' => $this->shortText($item['next_action'] ?? '', 220),
                ];
            }
            $out[$quadrant] = $items;
        }

        $hasAny = false;
        foreach (['strengths', 'weaknesses', 'opportunities', 'threats'] as $quadrant) {
            if (!empty($out[$quadrant])) {
                $hasAny = true;
                break;
            }
        }

        return $hasAny ? $out : $this->swotUnavailable('AI narrative did not include usable SWOT items.');
    }

    /**
     * @return array<string,mixed>
     */
    private function swotUnavailable(string $reason): array
    {
        return [
            'status' => 'unavailable',
            'reason' => $reason,
            'strengths' => [],
            'weaknesses' => [],
            'opportunities' => [],
            'threats' => [],
        ];
    }

    /**
     * @param list<array<string,mixed>> $conflicts
     * @return list<array<string,mixed>>
     */
    private function normalizeConflicts(array $conflicts): array
    {
        $out = [];
        foreach ($conflicts as $conflict) {
            if (!is_array($conflict)) {
                continue;
            }
            $type = (string) ($conflict['type'] ?? 'assumption');
            $out[] = [
                'type' => $type,
                'severity' => (string) ($conflict['severity'] ?? 'medium'),
                'journey_assumption' => $this->shortText($conflict['journey_assumption'] ?? '', 500),
                'operating_evidence' => $this->shortText($conflict['operating_evidence'] ?? '', 500),
                'recommended_action' => $this->shortText($conflict['recommended_action'] ?? '', 400),
                'stage_key' => $this->primaryStageForConflict($type),
            ];
        }
        return $out;
    }

    /**
     * @param list<array<string,mixed>> $conflicts
     * @return array<string,list<array<string,mixed>>>
     */
    private function conflictsByStage(array $conflicts): array
    {
        $out = [];
        foreach ($conflicts as $conflict) {
            if (!is_array($conflict)) {
                continue;
            }
            $stageKey = $this->primaryStageForConflict((string) ($conflict['type'] ?? ''));
            $out[$stageKey] ??= [];
            $out[$stageKey][] = $conflict;
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $stage
     * @param array<string,mixed> $readiness
     * @param list<array<string,mixed>> $conflicts
     * @return list<string>
     */
    private function stageLabels(array $stage, array $readiness, array $conflicts): array
    {
        $labels = [];
        $status = (string) ($stage['status'] ?? 'not_started');
        if ($status === 'completed') {
            $labels[] = 'completed';
        }
        if ((int) ($readiness['strong_fields'] ?? 0) > 0 && $status === 'completed' && $conflicts === []) {
            $labels[] = 'evidence-backed';
        }
        if ((int) ($readiness['thin_fields'] ?? 0) > 0 || (int) ($readiness['empty_fields'] ?? 0) > 0) {
            $labels[] = 'thin';
        }
        if ($conflicts !== []) {
            $labels[] = 'contradicted';
        }
        if ($this->isStale((string) ($stage['updated_at'] ?? $stage['completed_at'] ?? ''))) {
            $labels[] = 'stale';
        }
        return array_values(array_unique($labels));
    }

    /**
     * @param array<string,mixed> $stage
     * @param array<string,mixed> $readiness
     * @param list<array<string,mixed>> $conflicts
     * @return list<string>
     */
    private function stageWarnings(array $stage, array $readiness, array $conflicts): array
    {
        $warnings = [];
        if ((int) ($readiness['empty_fields'] ?? 0) > 0 || (int) ($readiness['thin_fields'] ?? 0) > 0) {
            $warnings[] = (string) ($readiness['next_missing_item'] ?? 'Add more specific answers before relying on this stage.');
        }
        if ($conflicts !== []) {
            $warnings[] = count($conflicts) . ' operating evidence conflict' . (count($conflicts) === 1 ? '' : 's') . ' found.';
        }
        if ($this->isStale((string) ($stage['updated_at'] ?? $stage['completed_at'] ?? ''))) {
            $warnings[] = 'This completed stage has not been reviewed recently.';
        }
        return $warnings;
    }

    private function isStale(string $date): bool
    {
        if (trim($date) === '') {
            return false;
        }
        $timestamp = strtotime($date);
        return $timestamp !== false && $timestamp < (time() - 60 * 60 * 24 * 60);
    }

    private function primaryStageForConflict(string $type): string
    {
        return match ($type) {
            'gtm_channel', 'cac' => 'go_to_market',
            'target_customer' => 'customer_discovery',
            'pricing', 'revenue_model', 'cost_structure' => 'lean_canvas',
            'experiment_budget' => 'mvp',
            'runway', 'okr_execution' => 'okrs',
            default => 'lean_canvas',
        };
    }

    private function titleFromKey(string $key): string
    {
        $key = trim(str_replace('_', ' ', $key));
        return $key === '' ? 'assumption' : ucwords($key);
    }

    private function shortText(mixed $value, int $max): string
    {
        $text = trim(preg_replace('/\s+/', ' ', (string) $value) ?? (string) $value);
        if ($max > 0 && mb_strlen($text) > $max) {
            return rtrim(mb_substr($text, 0, max(0, $max - 3))) . '...';
        }
        return $text;
    }

    /**
     * @param array<string,mixed> $journey
     * @return array<string,mixed>
     */
    private function compactJourneyForAI(array $journey): array
    {
        $stages = [];
        foreach ((array) ($journey['stages'] ?? []) as $stage) {
            $responses = [];
            foreach ((array) ($stage['responses'] ?? []) as $field => $value) {
                $clean = $this->shortText($value, 500);
                if ($clean !== '') {
                    $responses[(string) $field] = $clean;
                }
            }
            $stages[] = [
                'stage_key' => (string) ($stage['stage_key'] ?? ''),
                'label' => (string) ($stage['label'] ?? ''),
                'status' => (string) ($stage['status'] ?? ''),
                'readiness' => (array) ($stage['readiness'] ?? []),
                'responses' => $responses,
            ];
        }
        return [
            'progress' => (array) ($journey['progress'] ?? []),
            'current_stage_key' => (string) ($journey['current_stage_key'] ?? ''),
            'stages' => $stages,
        ];
    }

    /**
     * @param array<string,mixed> $founderLoop
     * @return array<string,mixed>
     */
    private function compactFounderLoopForAI(array $founderLoop): array
    {
        return [
            'current_step_key' => (string) ($founderLoop['current_step_key'] ?? ''),
            'current_step' => (array) ($founderLoop['current_step'] ?? []),
            'next_action' => (string) ($founderLoop['next_action'] ?? ''),
            'first_customer_signal' => (array) ($founderLoop['first_customer_signal'] ?? []),
            'pricing' => (array) ($founderLoop['pricing'] ?? []),
            'commitment_count' => count((array) ($founderLoop['commitments'] ?? [])),
        ];
    }

    /**
     * @param array<string,mixed> $coachReadiness
     * @return array<string,mixed>
     */
    private function compactCoachReadinessForAI(array $coachReadiness): array
    {
        return [
            'recommendations_ready' => !empty($coachReadiness['recommendations_ready']),
            'clarity_journey_ready' => !empty($coachReadiness['clarity_journey_ready']),
            'operating_maturity' => (string) ($coachReadiness['operating_maturity'] ?? ''),
            'missing_requirements' => array_slice((array) ($coachReadiness['missing_requirements'] ?? []), 0, 5),
        ];
    }

    /**
     * @param array<string,mixed> $report
     * @return array<string,mixed>
     */
    private function compactReportForAI(array $report): array
    {
        return [
            'executive_summary' => (string) ($report['executive_summary'] ?? ''),
            'journey_health' => (array) ($report['journey_health'] ?? []),
            'stage_health' => array_slice((array) ($report['stage_health'] ?? []), 0, 8),
            'assumption_conflicts' => array_slice((array) ($report['assumption_conflicts'] ?? []), 0, 8),
            'founder_loop_bridge' => (array) ($report['founder_loop_bridge'] ?? []),
            'next_actions' => array_slice((array) ($report['next_actions'] ?? []), 0, 6),
            'caveats' => (array) ($report['caveats'] ?? []),
        ];
    }
}
