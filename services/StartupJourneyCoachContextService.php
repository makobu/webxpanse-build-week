<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\IdeaValidationContext;
use CRM\Modules\UserStrategyProfile;
use CRM\Modules\UserStrategySnapshot;

class StartupJourneyCoachContextService
{
    private const MAX_FIELD_LENGTH = 2000;

    private StartupJourneyService $journeyService;

    public function __construct(?StartupJourneyService $journeyService = null)
    {
        $this->journeyService = $journeyService ?? new StartupJourneyService();
    }

    /**
     * @return array<string,mixed>
     */
    public function deriveForJourney(int $workspaceId, int $userId): array
    {
        $workspaceId = max(0, $workspaceId);
        $userId = max(0, $userId);
        if ($workspaceId <= 0 || $userId <= 0 || !Database::tableExists('startup_journeys')) {
            return $this->emptyDerivedContext();
        }

        $journey = $this->journeyService->getJourney($workspaceId, $userId);
        $readiness = $this->journeyService->readiness($workspaceId, $userId);
        $responses = $this->stageResponses((array) ($journey['stages'] ?? []));
        $sourceMap = [];

        $strategy = [
            'target_market_focus' => $this->firstFrom($responses, $sourceMap, 'target_market_focus', [
                ['customer_discovery', 'target_customer'],
                ['lean_canvas', 'customer_segments'],
                ['go_to_market', 'beachhead_segment'],
            ]),
            'ideal_customer_profile' => $this->combineFrom($responses, $sourceMap, 'ideal_customer_profile', [
                'Target customer' => ['customer_discovery', 'target_customer'],
                'Job to be done' => ['jobs_to_be_done', 'job_statement'],
                'Pains' => ['value_proposition', 'pains'],
                'Gains' => ['value_proposition', 'gains'],
                'Beachhead segment' => ['go_to_market', 'beachhead_segment'],
            ]),
            'offer_angle' => $this->combineFrom($responses, $sourceMap, 'offer_angle', [
                'Products or services' => ['value_proposition', 'products_services'],
                'Pain relievers' => ['value_proposition', 'pain_relievers'],
                'Gain creators' => ['value_proposition', 'gain_creators'],
                'Unique value proposition' => ['lean_canvas', 'unique_value_proposition'],
            ]),
            'segment_focus' => $this->firstFrom($responses, $sourceMap, 'segment_focus', [
                ['go_to_market', 'beachhead_segment'],
                ['lean_canvas', 'customer_segments'],
                ['customer_discovery', 'target_customer'],
            ]),
            'sales_motion' => $this->firstFrom($responses, $sourceMap, 'sales_motion', [
                ['go_to_market', 'sales_motion'],
            ]),
            'deal_movement_strategy' => $this->combineFrom($responses, $sourceMap, 'deal_movement_strategy', [
                'Launch plan' => ['go_to_market', 'launch_plan'],
                'Objective' => ['okrs', 'objective'],
                'Conversion goal' => ['go_to_market', 'conversion_goal'],
            ]),
            'outreach_posture' => $this->combineFrom($responses, $sourceMap, 'outreach_posture', [
                'Message' => ['go_to_market', 'message'],
                'Channels' => ['go_to_market', 'channels'],
                'Lean Canvas channels' => ['lean_canvas', 'channels'],
            ]),
            'positioning_notes' => $this->combineFrom($responses, $sourceMap, 'positioning_notes', [
                'Customer jobs' => ['value_proposition', 'customer_jobs'],
                'Customer pains' => ['value_proposition', 'pains'],
                'Customer gains' => ['value_proposition', 'gains'],
                'Unfair advantage' => ['lean_canvas', 'unfair_advantage'],
            ]),
            'market_view' => $this->combineFrom($responses, $sourceMap, 'market_view', [
                'Current alternatives' => ['jobs_to_be_done', 'current_alternatives'],
                'Triggers' => ['jobs_to_be_done', 'triggers'],
                'Unfair advantage' => ['lean_canvas', 'unfair_advantage'],
                'Differentiator signal' => ['value_proposition', 'gain_creators'],
            ]),
            'strategy_hypothesis' => $this->combineFrom($responses, $sourceMap, 'strategy_hypothesis', [
                'MVP hypothesis' => ['mvp', 'mvp_hypothesis'],
                'Smallest test' => ['mvp', 'smallest_test'],
                'Objective' => ['okrs', 'objective'],
                'Key result 1' => ['okrs', 'key_result_1'],
                'Key result 2' => ['okrs', 'key_result_2'],
                'Key result 3' => ['okrs', 'key_result_3'],
            ]),
            'lean_problem' => $this->firstFrom($responses, $sourceMap, 'lean_problem', [['lean_canvas', 'problem']]),
            'lean_customer_segments' => $this->firstFrom($responses, $sourceMap, 'lean_customer_segments', [['lean_canvas', 'customer_segments']]),
            'lean_unique_value_proposition' => $this->firstFrom($responses, $sourceMap, 'lean_unique_value_proposition', [['lean_canvas', 'unique_value_proposition']]),
            'lean_solution' => $this->firstFrom($responses, $sourceMap, 'lean_solution', [['lean_canvas', 'solution']]),
            'lean_channels' => $this->firstFrom($responses, $sourceMap, 'lean_channels', [['lean_canvas', 'channels']]),
            'lean_revenue_streams' => $this->firstFrom($responses, $sourceMap, 'lean_revenue_streams', [['lean_canvas', 'revenue_streams']]),
            'lean_cost_structure' => $this->firstFrom($responses, $sourceMap, 'lean_cost_structure', [['lean_canvas', 'cost_structure']]),
            'lean_key_metrics' => $this->firstFrom($responses, $sourceMap, 'lean_key_metrics', [['lean_canvas', 'key_metrics']]),
            'lean_unfair_advantage' => $this->firstFrom($responses, $sourceMap, 'lean_unfair_advantage', [['lean_canvas', 'unfair_advantage']]),
        ];

        $ideaValidation = [
            'value_proposition' => $this->combineFrom($responses, $sourceMap, 'value_proposition', [
                'Unique value proposition' => ['lean_canvas', 'unique_value_proposition'],
                'Products or services' => ['value_proposition', 'products_services'],
                'Gain creators' => ['value_proposition', 'gain_creators'],
            ]),
            'target_market' => $this->combineFrom($responses, $sourceMap, 'target_market', [
                'Target customer' => ['customer_discovery', 'target_customer'],
                'Customer segments' => ['lean_canvas', 'customer_segments'],
                'Beachhead segment' => ['go_to_market', 'beachhead_segment'],
            ]),
            'pain_points' => $this->combineFrom($responses, $sourceMap, 'pain_points', [
                'Observed problem' => ['customer_discovery', 'observed_problem'],
                'Customer pains' => ['value_proposition', 'pains'],
                'Lean Canvas problem' => ['lean_canvas', 'problem'],
            ]),
            'assumptions_to_test' => $this->combineFrom($responses, $sourceMap, 'assumptions_to_test', [
                'Riskiest assumption' => ['customer_discovery', 'riskiest_assumption'],
                'MVP hypothesis' => ['mvp', 'mvp_hypothesis'],
                'Smallest test' => ['mvp', 'smallest_test'],
            ]),
            'competitors' => $this->firstFrom($responses, $sourceMap, 'competitors', [
                ['jobs_to_be_done', 'current_alternatives'],
            ]),
            'differentiator' => $this->combineFrom($responses, $sourceMap, 'differentiator', [
                'Unfair advantage' => ['lean_canvas', 'unfair_advantage'],
                'Gain creators' => ['value_proposition', 'gain_creators'],
                'Unique value proposition' => ['lean_canvas', 'unique_value_proposition'],
            ]),
        ];

        $strategy = $this->nonEmptyOnly($strategy);
        $ideaValidation = $this->nonEmptyOnly($ideaValidation);
        $derivedData = $this->briefData($strategy, $ideaValidation);
        $derivedMissing = (new UserStrategySnapshot())->missingPersonalBriefRequirementsFromData($derivedData);
        $journeyReady = !empty($readiness['ready']);

        return [
            'clarity_journey_ready' => $journeyReady,
            'inherited_context_ready' => $journeyReady && $derivedMissing === [],
            'strategy' => $strategy,
            'idea_validation' => $ideaValidation,
            'source_map' => $sourceMap,
            'derived_missing_requirements' => $derivedMissing,
            'remaining_personal_requirements' => $derivedMissing,
            'journey_readiness' => $readiness,
            'journey_progress' => (array) ($journey['progress'] ?? []),
            'journey_values' => $this->flattenJourneyValues($responses),
            'current_stage_key' => (string) ($journey['current_stage_key'] ?? ''),
            'context_sources' => $this->contextSources($journeyReady, $strategy, $ideaValidation),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function syncForJourney(int $workspaceId, int $userId): array
    {
        return $this->withWorkspaceContext($workspaceId, $userId, function () use ($workspaceId, $userId): array {
            $derived = $this->deriveForJourney($workspaceId, $userId);
            if (empty($derived['strategy']) && empty($derived['idea_validation'])) {
                return $derived + [
                    'changed' => false,
                    'filled_strategy_fields' => [],
                    'filled_idea_validation_fields' => [],
                    'preserved_strategy_fields' => [],
                    'preserved_idea_validation_fields' => [],
                    'manual_personal_brief_ready' => false,
                    'personal_brief_source' => 'missing',
                ];
            }

            $strategyModule = new UserStrategyProfile();
            $ideaModule = new IdeaValidationContext();
            $snapshotModule = new UserStrategySnapshot();
            $currentStrategy = $strategyModule->get($userId) ?: [];
            $currentIdea = $ideaModule->get($userId) ?: [];
            $manualMissingBefore = $snapshotModule->missingPersonalBriefRequirementsFromData($this->briefData($currentStrategy, $currentIdea));
            $manualReadyBefore = $manualMissingBefore === [];

            $nextStrategy = $currentStrategy;
            $nextIdea = $currentIdea;
            $filledStrategy = [];
            $filledIdea = [];
            $refreshedStrategy = [];
            $refreshedIdea = [];
            $preservedStrategy = [];
            $preservedIdea = [];
            $journeyValues = (array) ($derived['journey_values'] ?? []);

            foreach ((array) ($derived['strategy'] ?? []) as $field => $value) {
                $value = $this->clean($value);
                if ($value === '') {
                    continue;
                }
                if ($this->clean($currentStrategy[$field] ?? '') === '') {
                    $nextStrategy[$field] = $value;
                    $filledStrategy[] = (string) $field;
                    continue;
                }
                $currentValue = $this->clean($currentStrategy[$field] ?? '');
                if ($currentValue !== $value && $this->looksLikeEarlierDerivedValue($currentValue, $value, $journeyValues)) {
                    $nextStrategy[$field] = $value;
                    $refreshedStrategy[] = (string) $field;
                    continue;
                }
                if ($currentValue !== $value) {
                    $preservedStrategy[] = (string) $field;
                }
            }

            foreach ((array) ($derived['idea_validation'] ?? []) as $field => $value) {
                $value = $this->clean($value);
                if ($value === '') {
                    continue;
                }
                if ($this->clean($currentIdea[$field] ?? '') === '') {
                    $nextIdea[$field] = $value;
                    $filledIdea[] = (string) $field;
                    continue;
                }
                $currentValue = $this->clean($currentIdea[$field] ?? '');
                if ($currentValue !== $value && $this->looksLikeEarlierDerivedValue($currentValue, $value, $journeyValues)) {
                    $nextIdea[$field] = $value;
                    $refreshedIdea[] = (string) $field;
                    continue;
                }
                if ($currentValue !== $value) {
                    $preservedIdea[] = (string) $field;
                }
            }

            $changedStrategy = $filledStrategy !== [] || $refreshedStrategy !== [];
            $changedIdea = $filledIdea !== [] || $refreshedIdea !== [];
            if ($changedStrategy) {
                $strategyModule->save($userId, $nextStrategy);
            }
            if ($changedIdea) {
                $ideaModule->save($userId, $nextIdea);
            }

            $brief = $snapshotModule->getCurrentBrief($workspaceId, $userId, true);
            $source = $this->personalBriefSource(
                (array) ($brief['strategy'] ?? $nextStrategy),
                (array) ($brief['idea_validation'] ?? $nextIdea),
                (array) ($derived['strategy'] ?? []),
                (array) ($derived['idea_validation'] ?? []),
                $manualReadyBefore,
                !empty($brief['personal_brief_ready'])
            );

            return $derived + [
                'changed' => $changedStrategy || $changedIdea,
                'filled_strategy_fields' => $filledStrategy,
                'filled_idea_validation_fields' => $filledIdea,
                'refreshed_strategy_fields' => $refreshedStrategy,
                'refreshed_idea_validation_fields' => $refreshedIdea,
                'preserved_strategy_fields' => $preservedStrategy,
                'preserved_idea_validation_fields' => $preservedIdea,
                'manual_personal_brief_ready' => $manualReadyBefore,
                'manual_missing_requirements_before_sync' => $manualMissingBefore,
                'personal_brief_source' => $source,
                'active_strategy_snapshot' => $brief['active_strategy_snapshot'] ?? null,
            ];
        });
    }

    /**
     * @return array<string,mixed>
     */
    public function readinessForCoach(int $workspaceId, int $userId): array
    {
        $sync = $this->syncForJourney($workspaceId, $userId);
        $brief = (new UserStrategySnapshot())->getCurrentBrief($workspaceId, $userId, true);
        $finalMissing = (array) ($brief['missing_requirements'] ?? []);
        $clarityReady = !empty($sync['clarity_journey_ready']);
        $inheritedReady = !empty($sync['inherited_context_ready']);
        $finalReady = $finalMissing === [];

        return $sync + [
            'clarity_journey_ready' => $clarityReady,
            'inherited_context_ready' => $inheritedReady,
            'personal_brief_ready' => $finalReady,
            'personal_brief_source' => $this->personalBriefSource(
                (array) ($brief['strategy'] ?? []),
                (array) ($brief['idea_validation'] ?? []),
                (array) ($sync['strategy'] ?? []),
                (array) ($sync['idea_validation'] ?? []),
                !empty($sync['manual_personal_brief_ready']),
                $finalReady
            ),
            'remaining_personal_requirements' => $finalReady ? [] : $finalMissing,
            'personal_missing_requirements' => $finalMissing,
            'active_strategy_snapshot' => $brief['active_strategy_snapshot'] ?? null,
            'context_sources' => $this->contextSources($clarityReady, (array) ($sync['strategy'] ?? []), (array) ($sync['idea_validation'] ?? [])),
        ];
    }

    /**
     * @param array<string,mixed> $stages
     * @return array<string,array<string,string>>
     */
    private function stageResponses(array $stages): array
    {
        $responses = [];
        foreach ($stages as $stageKey => $stage) {
            $responses[(string) $stageKey] = [];
            foreach ((array) ($stage['responses'] ?? []) as $field => $value) {
                $responses[(string) $stageKey][(string) $field] = $this->clean($value);
            }
        }
        return $responses;
    }

    /**
     * @param array<string,array<string,string>> $responses
     * @param array<string,mixed> $sourceMap
     * @param list<array{0:string,1:string}> $paths
     */
    private function firstFrom(array $responses, array &$sourceMap, string $targetField, array $paths): string
    {
        foreach ($paths as $path) {
            [$stage, $field] = $path;
            $value = $this->clean($responses[$stage][$field] ?? '');
            if ($value === '') {
                continue;
            }
            $sourceMap[$targetField] = [['stage' => $stage, 'field' => $field]];
            return $value;
        }
        return '';
    }

    /**
     * @param array<string,array<string,string>> $responses
     * @param array<string,mixed> $sourceMap
     * @param array<string,array{0:string,1:string}> $parts
     */
    private function combineFrom(array $responses, array &$sourceMap, string $targetField, array $parts): string
    {
        $lines = [];
        $sources = [];
        foreach ($parts as $label => $path) {
            [$stage, $field] = $path;
            $value = $this->clean($responses[$stage][$field] ?? '');
            if ($value === '') {
                continue;
            }
            $lines[] = $label . ': ' . $value;
            $sources[] = ['stage' => $stage, 'field' => $field, 'label' => $label];
        }
        if ($lines === []) {
            return '';
        }
        $sourceMap[$targetField] = $sources;
        return $this->clean(implode("\n", $lines));
    }

    /**
     * @param array<string,mixed> $strategy
     * @param array<string,mixed> $ideaValidation
     * @return array<string,string>
     */
    private function briefData(array $strategy, array $ideaValidation): array
    {
        return [
            'target_market_focus' => $this->clean($strategy['target_market_focus'] ?? ''),
            'ideal_customer_profile' => $this->clean($strategy['ideal_customer_profile'] ?? ''),
            'offer_angle' => $this->clean($strategy['offer_angle'] ?? ''),
            'segment_focus' => $this->clean($strategy['segment_focus'] ?? ''),
            'sales_motion' => $this->clean($strategy['sales_motion'] ?? ''),
            'deal_movement_strategy' => $this->clean($strategy['deal_movement_strategy'] ?? ''),
            'outreach_posture' => $this->clean($strategy['outreach_posture'] ?? ''),
            'positioning_notes' => $this->clean($strategy['positioning_notes'] ?? ''),
            'market_view' => $this->clean($strategy['market_view'] ?? ''),
            'strategy_hypothesis' => $this->clean($strategy['strategy_hypothesis'] ?? ''),
            'value_proposition' => $this->clean($ideaValidation['value_proposition'] ?? ''),
            'target_market' => $this->clean($ideaValidation['target_market'] ?? ''),
            'pain_points' => $this->clean($ideaValidation['pain_points'] ?? ''),
            'assumptions_to_test' => $this->clean($ideaValidation['assumptions_to_test'] ?? ''),
            'competitors' => $this->clean($ideaValidation['competitors'] ?? ''),
            'differentiator' => $this->clean($ideaValidation['differentiator'] ?? ''),
        ];
    }

    /**
     * @param array<string,mixed> $values
     * @return array<string,string>
     */
    private function nonEmptyOnly(array $values): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            $clean = $this->clean($value);
            if ($clean !== '') {
                $out[(string) $key] = $clean;
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $currentStrategy
     * @param array<string,mixed> $currentIdea
     * @param array<string,mixed> $derivedStrategy
     * @param array<string,mixed> $derivedIdea
     */
    private function personalBriefSource(
        array $currentStrategy,
        array $currentIdea,
        array $derivedStrategy,
        array $derivedIdea,
        bool $manualReadyBefore,
        bool $finalReady
    ): string {
        if (!$finalReady) {
            return 'missing';
        }
        if ($manualReadyBefore && !$this->hasDerivedMatch($currentStrategy, $currentIdea, $derivedStrategy, $derivedIdea)) {
            return 'manual';
        }
        if ($this->hasManualDifference($currentStrategy, $currentIdea, $derivedStrategy, $derivedIdea)) {
            return 'mixed';
        }
        return $this->hasDerivedMatch($currentStrategy, $currentIdea, $derivedStrategy, $derivedIdea)
            ? 'clarity_journey'
            : 'manual';
    }

    /**
     * @param array<string,mixed> $currentStrategy
     * @param array<string,mixed> $currentIdea
     * @param array<string,mixed> $derivedStrategy
     * @param array<string,mixed> $derivedIdea
     */
    private function hasDerivedMatch(array $currentStrategy, array $currentIdea, array $derivedStrategy, array $derivedIdea): bool
    {
        foreach ($derivedStrategy as $field => $value) {
            if ($this->clean($value) !== '' && $this->clean($currentStrategy[$field] ?? '') === $this->clean($value)) {
                return true;
            }
        }
        foreach ($derivedIdea as $field => $value) {
            if ($this->clean($value) !== '' && $this->clean($currentIdea[$field] ?? '') === $this->clean($value)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string,mixed> $currentStrategy
     * @param array<string,mixed> $currentIdea
     * @param array<string,mixed> $derivedStrategy
     * @param array<string,mixed> $derivedIdea
     */
    private function hasManualDifference(array $currentStrategy, array $currentIdea, array $derivedStrategy, array $derivedIdea): bool
    {
        foreach ($derivedStrategy as $field => $value) {
            $current = $this->clean($currentStrategy[$field] ?? '');
            $derived = $this->clean($value);
            if ($current !== '' && $derived !== '' && $current !== $derived) {
                return true;
            }
        }
        foreach ($derivedIdea as $field => $value) {
            $current = $this->clean($currentIdea[$field] ?? '');
            $derived = $this->clean($value);
            if ($current !== '' && $derived !== '' && $current !== $derived) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<string> $journeyValues
     */
    private function looksLikeEarlierDerivedValue(string $current, string $derived, array $journeyValues = []): bool
    {
        $current = $this->clean($current);
        $derived = $this->clean($derived);
        if ($current === '' || $derived === '' || $current === $derived) {
            return false;
        }

        $normalize = static function (string $value): string {
            return strtolower((string) preg_replace('/\s+/', ' ', trim($value)));
        };
        $currentNormalized = $normalize($current);
        $derivedNormalized = $normalize($derived);
        if ($currentNormalized !== '' && str_contains($derivedNormalized, $currentNormalized)) {
            return true;
        }
        foreach ($journeyValues as $journeyValue) {
            $journeyNormalized = $normalize($this->clean($journeyValue));
            if ($journeyNormalized === '') {
                continue;
            }
            if ($currentNormalized === $journeyNormalized || str_contains($currentNormalized, $journeyNormalized)) {
                return true;
            }
        }

        $currentLines = array_values(array_filter(array_map('trim', preg_split('/\R+/', $current) ?: [])));
        if ($currentLines === []) {
            return false;
        }
        foreach ($currentLines as $line) {
            if ($line === '' || !str_contains($derivedNormalized, $normalize($line))) {
                return false;
            }
        }
        return true;
    }

    /**
     * @param array<string,array<string,string>> $responses
     * @return list<string>
     */
    private function flattenJourneyValues(array $responses): array
    {
        $values = [];
        foreach ($responses as $stageResponses) {
            foreach ($stageResponses as $value) {
                $clean = $this->clean($value);
                if ($clean !== '') {
                    $values[] = $clean;
                }
            }
        }
        return array_values(array_unique($values));
    }

    /**
     * @param array<string,mixed> $strategy
     * @param array<string,mixed> $ideaValidation
     * @return list<string>
     */
    private function contextSources(bool $journeyReady, array $strategy, array $ideaValidation): array
    {
        $sources = [];
        if ($journeyReady || $strategy !== [] || $ideaValidation !== []) {
            $sources[] = 'clarity_journey';
        }
        $sources[] = 'crm';
        $sources[] = 'finance';
        return array_values(array_unique($sources));
    }

    /**
     * @template T
     * @param callable():T $callback
     * @return T
     */
    private function withWorkspaceContext(int $workspaceId, int $userId, callable $callback)
    {
        $snapshot = WorkspaceContext::runtimeSnapshot();
        $currentWorkspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        $changed = $workspaceId > 0 && $currentWorkspaceId !== $workspaceId;
        if ($changed) {
            WorkspaceContext::activateRuntimeWorkspace($workspaceId, $userId, WorkspaceContext::currentRoleSlug() ?? 'owner');
        }

        try {
            return $callback();
        } finally {
            if ($changed) {
                WorkspaceContext::restoreRuntimeWorkspace($snapshot);
            }
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyDerivedContext(): array
    {
        return [
            'clarity_journey_ready' => false,
            'inherited_context_ready' => false,
            'strategy' => [],
            'idea_validation' => [],
            'source_map' => [],
            'derived_missing_requirements' => [],
            'remaining_personal_requirements' => [],
            'journey_readiness' => [
                'ready' => false,
                'status' => 'needs_setup',
                'message' => 'Clarity Journey is not available yet.',
                'blockers' => ['Clarity Journey'],
            ],
            'journey_progress' => [],
            'current_stage_key' => '',
            'context_sources' => [],
        ];
    }

    private function clean(mixed $value): string
    {
        $trimmed = trim((string) $value);
        if (strlen($trimmed) > self::MAX_FIELD_LENGTH) {
            return substr($trimmed, 0, self::MAX_FIELD_LENGTH);
        }
        return $trimmed;
    }
}
