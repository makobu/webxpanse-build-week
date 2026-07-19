<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\UserStrategyProfile;
use CRM\Modules\UserStrategySnapshot;

class StartupJourneyService
{
    private WorkspaceScopeService $workspaceScope;

    public function __construct(?WorkspaceScopeService $workspaceScope = null)
    {
        $this->workspaceScope = $workspaceScope ?? new WorkspaceScopeService();
    }

    public function stageDefinitions(): array
    {
        return [
            'customer_discovery' => [
                'order' => 1,
                'label' => 'Customer Discovery',
                'prompt' => 'Confirm the problem with real people before scaling the idea.',
                'fields' => [
                    'target_customer' => 'Who exactly are you learning from?',
                    'interview_count' => 'How many customer conversations have you completed?',
                    'observed_problem' => 'What problem did customers describe in their own words?',
                    'evidence' => 'What evidence proves this problem is real?',
                    'riskiest_assumption' => 'What assumption could still break the idea?',
                ],
            ],
            'jobs_to_be_done' => [
                'order' => 2,
                'label' => 'Jobs To Be Done',
                'prompt' => 'Name what the customer is hiring the product to accomplish.',
                'fields' => [
                    'job_statement' => 'When customers use this, what job are they trying to get done?',
                    'triggers' => 'What situation makes the job urgent?',
                    'current_alternatives' => 'What do they use or do today instead?',
                    'desired_outcomes' => 'What outcome would make them feel progress?',
                    'success_criteria' => 'How will they judge whether the job was done well?',
                ],
            ],
            'value_proposition' => [
                'order' => 3,
                'label' => 'Value Proposition Canvas',
                'prompt' => 'Connect the offer to jobs, pains, and gains.',
                'fields' => [
                    'customer_jobs' => 'Customer jobs',
                    'pains' => 'Customer pains',
                    'gains' => 'Customer gains',
                    'products_services' => 'Products or services',
                    'pain_relievers' => 'Pain relievers',
                    'gain_creators' => 'Gain creators',
                ],
            ],
            'lean_canvas' => [
                'order' => 4,
                'label' => 'Lean Canvas',
                'prompt' => 'Summarize the business-model assumptions the CRM and AI should use.',
                'fields' => [
                    'problem' => 'Problem',
                    'customer_segments' => 'Customer segments',
                    'unique_value_proposition' => 'Unique value proposition',
                    'solution' => 'Solution',
                    'channels' => 'Channels',
                    'revenue_streams' => 'Revenue streams',
                    'cost_structure' => 'Cost structure',
                    'key_metrics' => 'Key metrics',
                    'unfair_advantage' => 'Unfair advantage',
                ],
            ],
            'mvp' => [
                'order' => 5,
                'label' => 'MVP',
                'prompt' => 'Define the smallest test that can prove demand without overbuilding.',
                'fields' => [
                    'mvp_hypothesis' => 'What must the MVP prove?',
                    'smallest_test' => 'What is the smallest version you can test?',
                    'required_features' => 'Which features are truly required for the test?',
                    'success_metric' => 'What metric proves the test worked?',
                    'experiment_budget' => 'What budget or time limit will you put on this test?',
                ],
            ],
            'go_to_market' => [
                'order' => 6,
                'label' => 'Go-To-Market Strategy',
                'prompt' => 'Decide who to target first, what to say, and how to sell.',
                'fields' => [
                    'beachhead_segment' => 'Initial target segment',
                    'message' => 'Core launch message',
                    'channels' => 'Primary acquisition channels',
                    'sales_motion' => 'Sales process',
                    'launch_plan' => 'Launch plan',
                    'conversion_goal' => 'Conversion goal',
                ],
            ],
            'aarrr' => [
                'order' => 7,
                'label' => 'AARRR',
                'prompt' => 'Find where the growth engine is working or breaking.',
                'fields' => [
                    'acquisition' => 'How will customers find you?',
                    'activation' => 'What first value moment proves activation?',
                    'retention' => 'Why will they come back or continue paying?',
                    'referral' => 'What makes them share or refer?',
                    'revenue' => 'How does usage become revenue?',
                ],
            ],
            'okrs' => [
                'order' => 8,
                'label' => 'OKRs',
                'prompt' => 'Turn the journey into a quarter of focused execution.',
                'fields' => [
                    'objective' => 'Objective',
                    'key_result_1' => 'Key result 1',
                    'key_result_2' => 'Key result 2',
                    'key_result_3' => 'Key result 3',
                    'review_cadence' => 'Review cadence',
                ],
            ],
        ];
    }

    public function getJourney(int $workspaceId, int $userId): array
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId($workspaceId > 0 ? $workspaceId : null);
        $journeyId = $this->ensureJourney($workspaceId, $userId);
        $journey = Database::queryOne("SELECT * FROM startup_journeys WHERE id = ?", [$journeyId]) ?: [];
        $responses = $this->responsesByStage($journeyId);
        $definitions = $this->stageDefinitions();
        $stages = [];

        foreach ($definitions as $stageKey => $definition) {
            $response = (array) ($responses[$stageKey] ?? []);
            $values = $this->decodeAssoc($response['responses_json'] ?? null);
            $fieldValues = [];
            foreach ((array) ($definition['fields'] ?? []) as $fieldKey => $label) {
                $fieldValues[$fieldKey] = (string) ($values[$fieldKey] ?? '');
            }
            $filled = count(array_filter($fieldValues, static fn(string $value): bool => trim($value) !== ''));
            $total = count($fieldValues);
            $status = (string) ($response['status'] ?? ($filled > 0 ? 'draft' : 'not_started'));
            if ($status !== 'completed' && $total > 0 && $filled >= $total) {
                $status = 'draft';
            }
            $readiness = $this->stageReadiness(
                $stageKey,
                (array) ($definition['fields'] ?? []),
                $fieldValues,
                $status
            );
            $stages[$stageKey] = [
                'stage_key' => $stageKey,
                'order' => (int) ($definition['order'] ?? 0),
                'label' => (string) ($definition['label'] ?? $stageKey),
                'prompt' => (string) ($definition['prompt'] ?? ''),
                'fields' => (array) ($definition['fields'] ?? []),
                'responses' => $fieldValues,
                'notes' => (string) ($response['notes'] ?? ''),
                'status' => $status,
                'filled_fields' => $filled,
                'total_fields' => $total,
                'completed_at' => (string) ($response['completed_at'] ?? ''),
                'updated_at' => (string) ($response['updated_at'] ?? ''),
                'readiness' => $readiness,
            ];
        }

        return array_merge($journey, [
            'journey_id' => $journeyId,
            'stages' => $stages,
            'progress' => $this->progressFromStages($stages),
            'current_stage_key' => $this->currentStageKey($stages),
        ]);
    }

    public function saveStage(int $workspaceId, int $userId, string $stageKey, array $responses, string $notes = '', bool $complete = false, array $eventMetadata = []): array
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId($workspaceId > 0 ? $workspaceId : null);
        $definitions = $this->stageDefinitions();
        if (!isset($definitions[$stageKey])) {
            throw new \InvalidArgumentException('Unknown Clarity Journey stage.');
        }

        $journeyId = $this->ensureJourney($workspaceId, $userId);
        $previous = Database::queryOne(
            "SELECT status FROM startup_journey_stage_responses WHERE journey_id = ? AND stage_key = ? LIMIT 1",
            [$journeyId, $stageKey]
        ) ?: [];
        $definition = $definitions[$stageKey];
        $clean = $this->cleanStageResponses($definition, $responses);

        $status = $complete ? 'completed' : ($this->hasAnyValue($clean) ? 'draft' : 'not_started');
        $completedAt = $complete ? date('Y-m-d H:i:s') : null;
        Database::execute(
            "INSERT INTO startup_journey_stage_responses (
                journey_id, workspace_id, user_id, stage_key, stage_order, status, responses_json, notes, completed_at
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                responses_json = VALUES(responses_json),
                notes = VALUES(notes),
                completed_at = VALUES(completed_at),
                updated_at = NOW()",
            [
                $journeyId,
                $workspaceId,
                $userId,
                $stageKey,
                (int) ($definition['order'] ?? 0),
                $status,
                json_encode($clean, JSON_UNESCAPED_SLASHES),
                mb_substr(trim($notes), 0, 2000),
                $completedAt,
            ]
        );

        if ($stageKey === 'lean_canvas') {
            $this->syncLeanCanvasProfile($workspaceId, $userId, $clean);
        }
        try {
            (new StartupJourneyCoachContextService($this))->syncForJourney($workspaceId, $userId);
        } catch (\Throwable $e) {
            error_log('Clarity Journey Coach context sync failed: ' . $e->getMessage());
        }

        $eventType = $complete ? 'stage_completed' : 'stage_saved';
        if (!$complete && (string) ($previous['status'] ?? '') === 'completed') {
            $eventType = 'stage_reopened';
        }
        $this->recordEvent($journeyId, $workspaceId, $userId, $stageKey, $eventType, array_merge([
            'filled_fields' => count(array_filter($clean, static fn(string $value): bool => trim($value) !== '')),
            'completion_intent' => $complete ? 'complete' : 'draft',
        ], $this->cleanEventMetadata($eventMetadata)));

        $journey = $this->getJourney($workspaceId, $userId);
        Database::execute(
            "UPDATE startup_journeys SET current_stage_key = ?, status = ? WHERE id = ?",
            [
                (string) ($journey['current_stage_key'] ?? $stageKey),
                ((int) ($journey['progress']['completed'] ?? 0) >= count($definitions)) ? 'completed' : 'active',
                $journeyId,
            ]
        );

        return $this->getJourney($workspaceId, $userId);
    }

    public function saveStageWithAutoCompletion(int $workspaceId, int $userId, string $stageKey, array $responses, string $notes = ''): array
    {
        return $this->saveStage($workspaceId, $userId, $stageKey, $responses, $notes, false, [
            'save_source' => 'autosave',
            'legacy_auto_completion_disabled' => true,
        ]);
    }

    public function getContextForAI(int $workspaceId, int $userId): array
    {
        if (!Database::tableExists('startup_journeys')) {
            return [];
        }
        $journey = $this->getJourney($workspaceId, $userId);
        $stages = [];
        foreach ((array) ($journey['stages'] ?? []) as $stage) {
            $stages[] = [
                'stage_key' => (string) ($stage['stage_key'] ?? ''),
                'label' => (string) ($stage['label'] ?? ''),
                'status' => (string) ($stage['status'] ?? 'not_started'),
                'filled_fields' => (int) ($stage['filled_fields'] ?? 0),
                'total_fields' => (int) ($stage['total_fields'] ?? 0),
                'readiness' => (array) ($stage['readiness'] ?? []),
                'responses' => array_filter((array) ($stage['responses'] ?? []), static fn($value): bool => trim((string) $value) !== ''),
            ];
        }
        $financialAssumptions = $this->financialAssumptionsForAI($stages);

        return [
            'label' => 'Clarity Journey',
            'current_stage_key' => (string) ($journey['current_stage_key'] ?? 'customer_discovery'),
            'progress' => (array) ($journey['progress'] ?? []),
            'stages' => $stages,
            'financial_assumptions' => $financialAssumptions,
            'next_best_action' => $this->nextBestAction($journey),
            'current_stage_readiness' => (array) ($journey['stages'][$journey['current_stage_key'] ?? '']['readiness'] ?? []),
        ];
    }

    public function readiness(int $workspaceId, int $userId): array
    {
        if (!Database::tableExists('startup_journeys')) {
            return ['ready' => false, 'status' => 'needs_setup', 'message' => 'Clarity Journey tables are not installed.'];
        }
        $journey = $this->getJourney($workspaceId, $userId);
        $progress = (array) ($journey['progress'] ?? []);
        $completed = (int) ($progress['completed'] ?? 0);
        $total = (int) ($progress['total'] ?? count($this->stageDefinitions()));
        $ready = $completed >= $total;
        $missing = [];
        foreach ((array) ($journey['stages'] ?? []) as $stage) {
            if ((string) ($stage['status'] ?? '') !== 'completed') {
                $missing[] = (string) ($stage['label'] ?? $stage['stage_key'] ?? 'Stage');
            }
        }

        return [
            'enabled' => true,
            'ready' => $ready,
            'status' => $ready ? 'ready' : 'needs_setup',
            'message' => $ready ? 'Clarity Journey is complete.' : 'Clarity Journey needs more context.',
            'checks' => [
                ['label' => 'Clarity Journey stages', 'ok' => $ready, 'required' => true, 'detail' => $completed . ' of ' . $total . ' stages complete' . ($missing !== [] ? '; next: ' . $missing[0] : '') . '.'],
            ],
            'blockers' => $ready ? [] : $missing,
            'next_action' => $ready ? 'Review Clarity Journey-backed recommendations in AI Coach or Clarity.' : 'Complete the next Clarity Journey stage in the hub.',
            'progress' => $progress,
            'current_stage_key' => (string) ($journey['current_stage_key'] ?? ''),
            'current_stage_readiness' => (array) ($journey['stages'][$journey['current_stage_key'] ?? '']['readiness'] ?? []),
        ];
    }

    public function assessFieldQuality(string $stageKey, string $fieldKey, string $value): array
    {
        $value = trim($value);
        $length = mb_strlen($value);
        if ($value === '') {
            return [
                'status' => 'empty',
                'label' => 'Empty',
                'tone' => 'muted',
                'message' => 'Add a specific answer.',
            ];
        }

        if ($this->isStrongAnswer($fieldKey, $value)) {
            return [
                'status' => 'strong',
                'label' => 'Strong',
                'tone' => 'good',
                'message' => 'Specific enough to trust for the next decision.',
            ];
        }

        if ($this->isUsefulAnswer($stageKey, $fieldKey, $value)) {
            return [
                'status' => 'useful',
                'label' => 'Useful',
                'tone' => 'active',
                'message' => 'Specific enough to guide the next decision.',
            ];
        }

        return [
            'status' => 'thin',
            'label' => 'Thin',
            'tone' => 'warning',
            'message' => 'Make this more specific before completing the stage.',
        ];
    }

    public function stageReadinessForResponses(string $stageKey, array $responses, string $status = 'draft'): array
    {
        $definitions = $this->stageDefinitions();
        if (!isset($definitions[$stageKey])) {
            throw new \InvalidArgumentException('Unknown Clarity Journey stage.');
        }

        $definition = (array) $definitions[$stageKey];
        $clean = $this->cleanStageResponses($definition, $responses);
        $normalizedStatus = in_array($status, ['not_started', 'draft', 'completed'], true) ? $status : 'draft';

        return $this->stageReadiness(
            $stageKey,
            (array) ($definition['fields'] ?? []),
            $clean,
            $normalizedStatus
        );
    }

    public function setupCompletedAt(int $workspaceId, int $userId): ?string
    {
        if ($workspaceId <= 0 || $userId <= 0 || !Database::tableExists('startup_journey_stage_responses')) {
            return null;
        }

        $stageKeys = array_keys($this->stageDefinitions());
        if ($stageKeys === []) {
            return null;
        }

        $placeholders = implode(',', array_fill(0, count($stageKeys), '?'));
        $row = Database::queryOne(
            "SELECT COUNT(DISTINCT stage_key) AS completed_count, MAX(completed_at) AS completed_at
             FROM startup_journey_stage_responses
             WHERE workspace_id = ?
               AND user_id = ?
               AND stage_key IN ({$placeholders})
               AND status = 'completed'
               AND completed_at IS NOT NULL",
            array_merge([$workspaceId, $userId], $stageKeys)
        );

        if ((int) ($row['completed_count'] ?? 0) < count($stageKeys)) {
            return null;
        }

        $completedAt = trim((string) ($row['completed_at'] ?? ''));
        return $completedAt !== '' ? $completedAt : null;
    }

    private function ensureJourney(int $workspaceId, int $userId): int
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId($workspaceId);
        if (!Database::tableExists('startup_journeys')) {
            throw new \RuntimeException('Clarity Journey tables are not installed.');
        }
        $existing = Database::queryOne(
            "SELECT id FROM startup_journeys WHERE workspace_id = ? AND user_id = ? LIMIT 1",
            [$workspaceId, $userId]
        );
        if ($existing) {
            $journeyId = (int) $existing['id'];
            $this->syncLegacyLeanCanvas($journeyId, $workspaceId, $userId);
            return $journeyId;
        }

        Database::execute(
            "INSERT INTO startup_journeys (workspace_id, user_id, status, current_stage_key)
             VALUES (?, ?, 'active', 'customer_discovery')",
            [$workspaceId, $userId]
        );
        $journeyId = (int) Database::lastInsertId();
        $this->syncLegacyLeanCanvas($journeyId, $workspaceId, $userId);
        return $journeyId;
    }

    private function syncLegacyLeanCanvas(int $journeyId, int $workspaceId, int $userId): void
    {
        $existing = Database::queryOne(
            "SELECT id FROM startup_journey_stage_responses WHERE journey_id = ? AND stage_key = 'lean_canvas' LIMIT 1",
            [$journeyId]
        );
        if ($existing) {
            return;
        }

        $canvas = (new UserStrategyProfile())->getLeanCanvas($userId);
        if (!$this->hasAnyValue($canvas)) {
            return;
        }

        Database::execute(
            "INSERT INTO startup_journey_stage_responses
                (journey_id, workspace_id, user_id, stage_key, stage_order, status, responses_json, completed_at)
             VALUES (?, ?, ?, 'lean_canvas', 4, 'completed', ?, NOW())",
            [$journeyId, $workspaceId, $userId, json_encode($canvas, JSON_UNESCAPED_SLASHES)]
        );
        $this->recordEvent($journeyId, $workspaceId, $userId, 'lean_canvas', 'legacy_lean_canvas_imported', []);
    }

    private function syncLeanCanvasProfile(int $workspaceId, int $userId, array $responses): void
    {
        $profile = new UserStrategyProfile();
        $profile->save($userId, array_merge($profile->get($userId) ?: [], [
            'lean_problem' => $responses['problem'] ?? '',
            'lean_customer_segments' => $responses['customer_segments'] ?? '',
            'lean_unique_value_proposition' => $responses['unique_value_proposition'] ?? '',
            'lean_solution' => $responses['solution'] ?? '',
            'lean_channels' => $responses['channels'] ?? '',
            'lean_revenue_streams' => $responses['revenue_streams'] ?? '',
            'lean_cost_structure' => $responses['cost_structure'] ?? '',
            'lean_key_metrics' => $responses['key_metrics'] ?? '',
            'lean_unfair_advantage' => $responses['unfair_advantage'] ?? '',
        ]));
        (new UserStrategySnapshot())->syncForUser($workspaceId, $userId);
    }

    private function responsesByStage(int $journeyId): array
    {
        $rows = Database::query(
            "SELECT * FROM startup_journey_stage_responses WHERE journey_id = ?",
            [$journeyId]
        );
        $out = [];
        foreach ($rows as $row) {
            $out[(string) ($row['stage_key'] ?? '')] = $row;
        }
        return $out;
    }

    private function stageReadiness(string $stageKey, array $fields, array $responses, string $status): array
    {
        $fieldQuality = [];
        $useful = 0;
        $thin = 0;
        $empty = 0;
        $strong = 0;
        $nextMissing = '';
        foreach ($fields as $fieldKey => $label) {
            $quality = $this->assessFieldQuality(
                $stageKey,
                (string) $fieldKey,
                (string) ($responses[$fieldKey] ?? '')
            );
            $fieldQuality[$fieldKey] = $quality;
            $qualityStatus = (string) ($quality['status'] ?? 'empty');
            if ($qualityStatus === 'empty') {
                $empty++;
                $nextMissing = $nextMissing === '' ? (string) $label : $nextMissing;
                continue;
            }
            if ($qualityStatus === 'thin') {
                $thin++;
                $nextMissing = $nextMissing === '' ? (string) $label : $nextMissing;
                continue;
            }
            if ($qualityStatus === 'strong') {
                $strong++;
            }
            $useful++;
        }

        $total = count($fields);
        $readinessStatus = 'not_started';
        if ($status === 'completed' || ($total > 0 && $empty === 0 && $thin === 0)) {
            $readinessStatus = 'ready_to_complete';
        } elseif ($useful >= max(1, (int) ceil($total * 0.55))) {
            $readinessStatus = 'useful_draft';
        } elseif ($empty < $total) {
            $readinessStatus = 'needs_detail';
        }

        $labels = [
            'not_started' => 'Not started',
            'needs_detail' => 'Needs detail',
            'useful_draft' => 'Useful draft',
            'ready_to_complete' => 'Ready',
        ];

        return [
            'status' => $readinessStatus,
            'label' => $labels[$readinessStatus] ?? 'Needs review',
            'field_quality' => $fieldQuality,
            'useful_fields' => $useful,
            'thin_fields' => $thin,
            'empty_fields' => $empty,
            'strong_fields' => $strong,
            'next_missing_item' => $nextMissing !== '' ? $nextMissing : 'Review and mark this stage complete.',
            'checklist' => $this->stageChecklist($total, $empty, $thin, $strong, $status),
            'summary' => $this->stageSummary($fields, $responses),
            'completion_risk' => $this->completionRisk($readinessStatus, $thin, $empty, $strong),
        ];
    }

    private function stageChecklist(int $total, int $empty, int $thin, int $strong, string $status): array
    {
        return [
            ['label' => 'Core answers', 'ok' => $total > 0 && $empty === 0, 'detail' => $empty === 0 ? 'All fields have answers.' : $empty . ' empty field' . ($empty === 1 ? '' : 's') . '.'],
            ['label' => 'Useful detail', 'ok' => $thin === 0 && $empty === 0, 'detail' => $thin === 0 ? 'Answers are specific enough.' : $thin . ' thin answer' . ($thin === 1 ? '' : 's') . '.'],
            ['label' => 'Real signals', 'ok' => $strong > 0, 'detail' => $strong > 0 ? $strong . ' strong answer' . ($strong === 1 ? '' : 's') . '.' : 'Add a real example, number, quote, or customer signal.'],
            ['label' => 'Stage complete', 'ok' => $status === 'completed', 'detail' => $status === 'completed' ? 'Marked complete.' : 'Mark complete when the answers can guide AI.'],
        ];
    }

    private function stageSummary(array $fields, array $responses): string
    {
        $parts = [];
        foreach ($fields as $fieldKey => $label) {
            $value = trim((string) ($responses[$fieldKey] ?? ''));
            if ($value === '') {
                continue;
            }
            $value = preg_replace('/\s+/', ' ', $value) ?? $value;
            $parts[] = (string) $label . ': ' . mb_substr($value, 0, 110);
            if (count($parts) >= 2) {
                break;
            }
        }
        return $parts === [] ? 'No saved answers yet. Start with the first field.' : implode(' ', $parts);
    }

    private function completionRisk(string $readinessStatus, int $thin, int $empty, int $strong): string
    {
        if ($readinessStatus === 'ready_to_complete' && $strong > 0) {
            return 'low';
        }
        if ($empty > 0 || $thin > 1) {
            return 'high';
        }
        return 'medium';
    }

    private function isStrongAnswer(string $fieldKey, string $value): bool
    {
        $length = mb_strlen($value);
        $hasSignal = (bool) preg_match('/\d+|customer|interview|paid|demo|quote|test|usage|invoice|founder|lead|deal/i', $value);
        if ($fieldKey === 'interview_count') {
            return (bool) preg_match('/[1-9]\d*/', $value);
        }
        return $length >= 65 && $hasSignal;
    }

    private function isUsefulAnswer(string $stageKey, string $fieldKey, string $value): bool
    {
        $length = mb_strlen($value);
        if ($fieldKey === 'interview_count') {
            return (bool) preg_match('/[1-9]\d*/', $value);
        }
        if (in_array($fieldKey, ['experiment_budget', 'conversion_goal', 'key_result_1', 'key_result_2', 'key_result_3'], true)) {
            return $length >= 18 && (bool) preg_match('/\d+|week|month|day|percent|%|\$|usd|kes/i', $value);
        }
        if (in_array($fieldKey, ['channels', 'revenue', 'revenue_streams', 'review_cadence'], true)) {
            return $length >= 24;
        }
        return $length >= 45 || ($length >= 28 && (bool) preg_match('/because|when|who|with|for|from|customer|founder|lead|deal|pay|use/i', $value));
    }

    /**
     * @param list<array<string,mixed>> $stages
     * @return array<string,mixed>
     */
    private function financialAssumptionsForAI(array $stages): array
    {
        $responsesByStage = [];
        foreach ($stages as $stage) {
            $stageKey = (string) ($stage['stage_key'] ?? '');
            if ($stageKey === '') {
                continue;
            }
            $responsesByStage[$stageKey] = (array) ($stage['responses'] ?? []);
        }

        $assumptions = [];
        $map = [
            'revenue_streams' => ['lean_canvas', 'revenue_streams'],
            'cost_structure' => ['lean_canvas', 'cost_structure'],
            'experiment_budget' => ['mvp', 'experiment_budget'],
            'conversion_goal' => ['go_to_market', 'conversion_goal'],
            'okr_objective' => ['okrs', 'objective'],
            'aarrr_revenue' => ['aarrr', 'revenue'],
        ];
        foreach ($map as $outKey => [$stageKey, $fieldKey]) {
            $value = $this->financialAssumptionText($responsesByStage[$stageKey][$fieldKey] ?? '');
            if ($value !== '') {
                $assumptions[$outKey] = $value;
            }
        }

        $keyResults = [];
        foreach (['key_result_1', 'key_result_2', 'key_result_3'] as $fieldKey) {
            $value = $this->financialAssumptionText($responsesByStage['okrs'][$fieldKey] ?? '');
            if ($value !== '') {
                $keyResults[] = $value;
            }
        }
        if ($keyResults !== []) {
            $assumptions['okr_key_results'] = $keyResults;
        }
        if ($assumptions !== []) {
            $assumptions['source'] = 'clarity_journey';
        }

        return $assumptions;
    }

    private function financialAssumptionText(mixed $value): string
    {
        $value = trim(preg_replace('/\s+/', ' ', (string) $value) ?? (string) $value);
        return mb_substr($value, 0, 900);
    }

    private function progressFromStages(array $stages): array
    {
        $total = count($stages);
        $completed = count(array_filter($stages, static fn(array $stage): bool => (string) ($stage['status'] ?? '') === 'completed'));
        $draft = count(array_filter($stages, static fn(array $stage): bool => (string) ($stage['status'] ?? '') === 'draft'));

        return [
            'total' => $total,
            'completed' => $completed,
            'draft' => $draft,
            'pending' => max(0, $total - $completed - $draft),
            'percent' => $total > 0 ? (int) round(($completed / $total) * 100) : 0,
        ];
    }

    private function currentStageKey(array $stages): string
    {
        foreach ($stages as $stageKey => $stage) {
            if ((string) ($stage['status'] ?? '') !== 'completed') {
                return (string) $stageKey;
            }
        }
        return 'okrs';
    }

    private function nextBestAction(array $journey): string
    {
        $stageKey = (string) ($journey['current_stage_key'] ?? 'customer_discovery');
        $stage = (array) (($journey['stages'][$stageKey] ?? []) ?: []);
        $label = (string) ($stage['label'] ?? 'the next stage');
        return 'Complete ' . $label . ' before moving higher in the clarity journey.';
    }

    private function recordEvent(int $journeyId, int $workspaceId, int $userId, string $stageKey, string $eventType, array $metadata): void
    {
        Database::execute(
            "INSERT INTO startup_journey_stage_events (journey_id, workspace_id, user_id, stage_key, event_type, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?)",
            [$journeyId, $workspaceId, $userId, $stageKey, $eventType, json_encode($metadata, JSON_UNESCAPED_SLASHES)]
        );
    }

    private function cleanEventMetadata(array $metadata): array
    {
        $clean = [];
        foreach ($metadata as $key => $value) {
            $key = preg_replace('/[^a-z0-9_]+/i', '_', (string) $key) ?: '';
            if ($key === '') {
                continue;
            }
            if (is_array($value)) {
                $clean[$key] = $this->cleanEventMetadata($value);
                continue;
            }
            if (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
                $clean[$key] = $value;
                continue;
            }
            $clean[$key] = mb_substr(trim((string) $value), 0, 700);
        }
        return $clean;
    }

    private function cleanStageResponses(array $definition, array $responses): array
    {
        $clean = [];
        foreach ((array) ($definition['fields'] ?? []) as $fieldKey => $label) {
            $clean[$fieldKey] = mb_substr(trim((string) ($responses[$fieldKey] ?? '')), 0, 2000);
        }

        return $clean;
    }

    private function hasAnyValue(array $values): bool
    {
        foreach ($values as $value) {
            if (trim((string) $value) !== '') {
                return true;
            }
        }
        return false;
    }

    private function decodeAssoc(mixed $json): array
    {
        $decoded = is_string($json) && trim($json) !== '' ? json_decode($json, true) : [];
        return is_array($decoded) ? $decoded : [];
    }
}
