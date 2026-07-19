<?php
/**
 * AI Task Automation Service
 *
 * Creates actionable tasks from AI Coach recommendations while respecting
 * the selected AI guidance mode.
 */

namespace CRM\Modules;

use CRM\Database;
use CRM\Services\AIRuntimeControlService;
use CRM\Services\AIDecisionOutcomeService;
use CRM\Services\AIOutcomeClassifier;
use CRM\Services\SkillTaskTemplateService;
use CRM\Services\AITaskCreationEligibilityService;
use CRM\Services\TaskAssignmentAccessService;
use CRM\Services\WorkspaceContext;

class AITaskAutomationService
{
    private const RECOMMENDATION_SECTIONS = ['foundation_gaps', 'priorities', 'quick_wins', 'missing_features'];
    private const PREF_LAST_SYNC_DATE = 'ai_task_auto_sync_date';
    private const DESC_MARKER = '[AI-COACH][AUTO]';

    private UserPreferences $preferences;
    private AICoach $coach;
    private Tasks $tasks;
    private AIDecisionOutcomeService $outcomes;
    private AIOutcomeClassifier $classifier;
    private AIRuntimeControlService $runtimeControls;
    private TaskAssignmentAccessService $assignmentAccess;
    private SkillTaskTemplateService $skillTaskTemplates;
    private AITaskCreationEligibilityService $taskCreationEligibility;

    private const DEFAULT_GUIDANCE_BY_INTENT = [
        'follow_up' => [
            'summary' => 'Done when outreach is sent and the reply or next step is logged.',
            'completion_checks' => [
                'Outreach sent.',
                'Reply or outcome logged.',
                'Next action captured.',
            ],
        ],
        'billing' => [
            'summary' => 'Done when billing is saved and an invoice is ready or sent.',
            'completion_checks' => [
                'Billing details saved.',
                'Invoice fields complete.',
                'Invoice no longer draft-only.',
            ],
        ],
        'ops' => [
            'summary' => 'Done when the setup is saved and can run.',
            'completion_checks' => [
                'Configuration saved.',
                'Workflow or process can run.',
                'Dependencies finished.',
            ],
        ],
        'goal_progress' => [
            'summary' => 'Done when the target change is saved and the next milestone is clear.',
            'completion_checks' => [
                'Change applied.',
                'Target can move forward.',
                'Next measurement defined.',
            ],
        ],
        'pricing' => [
            'summary' => 'Done when pricing is saved and visible in the CRM.',
            'completion_checks' => [
                'Pricing entered.',
                'Product or offer saved.',
                'Pricing visible on the record.',
            ],
        ],
        'company_profile' => [
            'summary' => 'Done when company details are saved and visible.',
            'completion_checks' => [
                'Profile fields updated.',
                'Changes saved.',
                'Details appear correctly.',
            ],
        ],
        'sales_process' => [
            'summary' => 'Done when the pipeline change is saved and usable.',
            'completion_checks' => [
                'Stages or rules updated.',
                'Process saved.',
                'Deal can use the change.',
            ],
        ],
        'segmentation' => [
            'summary' => 'Done when segment rules are saved and return the right records.',
            'completion_checks' => [
                'Criteria defined.',
                'Segment saved.',
                'Expected records appear.',
            ],
        ],
        'review' => [
            'summary' => 'Done when the recommended change is saved and verified.',
            'completion_checks' => [
                'Setup updated.',
                'Record or configuration exists.',
                'Checklist is complete.',
            ],
        ],
    ];

    public function __construct()
    {
        $this->preferences = new UserPreferences();
        $this->coach = new AICoach();
        $this->tasks = new Tasks();
        $this->outcomes = new AIDecisionOutcomeService();
        $this->classifier = new AIOutcomeClassifier();
        $this->runtimeControls = new AIRuntimeControlService();
        $this->assignmentAccess = new TaskAssignmentAccessService();
        $this->skillTaskTemplates = new SkillTaskTemplateService();
        $this->taskCreationEligibility = new AITaskCreationEligibilityService();
    }

    /**
     * Auto-create tasks from recommendations (daily) for Foundation mode.
     *
     * @return array{mode:string,created_count:int,skipped:int,created_titles:array<int,string>,retired_count?:int,blocked_by_gate?:int,blocked_by_plan?:int,gate_redirected?:int}
     */
    public function autoSeedDailyTasks(int $userId): array
    {
        return $this->createTasksFromRecommendations($userId, false);
    }

    public function shouldAutoSeedToday(int $userId): bool
    {
        $control = $this->runtimeControls->getEffectiveControl('task_automation');
        if (in_array((string) ($control['control_mode'] ?? 'normal'), ['paused', 'diagnostics_only', 'suggest_only'], true)) {
            return false;
        }

        $mode = $this->preferences->getEffectiveAIGuidanceMode($userId);
        if ($mode === '3') {
            return false;
        }

        $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        if ($workspaceId > 0 && $this->taskCreationEligibility->countIneligibleOpenStarterTasks($workspaceId, $userId) > 0) {
            return true;
        }

        if ($mode !== '1') {
            return false;
        }

        $lastSyncDate = $this->preferences->getPreference($userId, $this->lastSyncPreferenceKey()) ?? '';
        return $lastSyncDate !== date('Y-m-d');
    }

    /**
     * Manually trigger task creation from recommendations.
     * Works in Foundation and Operations mode; disabled in Guardian mode.
     *
     * @return array{mode:string,created_count:int,skipped:int,created_titles:array<int,string>,retired_count?:int,blocked_by_gate?:int,blocked_by_plan?:int,gate_redirected?:int}
     */
    public function manualSyncTasks(int $userId): array
    {
        return $this->createTasksFromRecommendations($userId, true);
    }

    /**
     * Add Coach task metadata used by completion scans without making the
     * visible task body noisy.
     *
     * @param array<string,mixed> $taskData
     * @param array<int,mixed> $subtasks
     * @return array{task_data:array<string,mixed>,subtasks:array<int,array<string,string>>}
     */
    public function prepareCoachTaskCreationPayload(array $taskData, array $subtasks = []): array
    {
        $metadata = $this->decodeMetadata($taskData['metadata_json'] ?? null);
        $sourceSurface = (string) ($metadata['source_surface'] ?? $taskData['source_surface'] ?? '');
        if ($sourceSurface !== 'ai_coach') {
            return [
                'task_data' => $taskData,
                'subtasks' => array_values(array_filter($subtasks, static fn($item): bool => is_array($item))),
            ];
        }

        $title = $this->makeConciseTaskTitle((string) ($taskData['title'] ?? ''));
        $reason = $this->makeConciseTaskText(
            $this->extractReasonFromDescription((string) ($taskData['description'] ?? '')),
            180
        );
        $taskIntent = trim((string) ($metadata['task_intent'] ?? ''));
        if ($taskIntent === '') {
            $taskIntent = $this->inferTaskIntent($title, $reason);
        }

        $evidenceTypes = $this->normalizeEvidenceTypes((array) ($metadata['completion_evidence_types'] ?? []));
        if ($evidenceTypes === []) {
            $evidenceTypes = $this->inferCompletionEvidenceTypes($title, $reason);
        }

        $normalizedSubtasks = $this->normalizeSuggestedSubtasks($subtasks, $title, $reason, $taskIntent, $evidenceTypes);
        $completionGuidance = $this->buildCompletionGuidance($title, $reason, $taskIntent, $evidenceTypes, $normalizedSubtasks);
        $autoCompleteSupported = $this->isAutoCompletionSupported($evidenceTypes, $taskIntent, $metadata, $taskData);

        $metadata['source_surface'] = 'ai_coach';
        $metadata['task_intent'] = $taskIntent;
        $metadata['completion_evidence_types'] = $evidenceTypes;
        $metadata['completion_guidance'] = $completionGuidance;
        $metadata['auto_complete_allowed'] = $autoCompleteSupported;
        $metadata['protected_from_auto_complete'] = $autoCompleteSupported
            ? (bool) ($metadata['protected_from_auto_complete'] ?? false)
            : true;
        $metadata['completion_detector_state'] = $autoCompleteSupported ? 'supported' : 'review_only';

        $taskData['title'] = $title;
        $taskData['description'] = $reason !== '' ? 'Reason: ' . $reason : '';
        $taskData['metadata_json'] = $metadata;

        return [
            'task_data' => $taskData,
            'subtasks' => $normalizedSubtasks,
        ];
    }

    /**
     * @return array{mode:string,created_count:int,skipped:int,created_titles:array<int,string>,retired_count?:int,blocked_by_gate?:int,blocked_by_plan?:int,gate_redirected?:int}
     */
    private function createTasksFromRecommendations(int $userId, bool $force): array
    {
        $control = $this->runtimeControls->getEffectiveControl('task_automation');
        if (in_array((string) ($control['control_mode'] ?? 'normal'), ['paused', 'diagnostics_only', 'suggest_only'], true)) {
            return [
                'mode' => $this->preferences->getEffectiveAIGuidanceMode($userId),
                'created_count' => 0,
                'skipped' => 0,
                'created_titles' => [],
                'control_mode' => (string) ($control['control_mode'] ?? 'normal'),
                'control_reason' => (string) ($control['reason'] ?? ''),
            ];
        }

        $mode = $this->preferences->getEffectiveAIGuidanceMode($userId);
        $today = date('Y-m-d');
        $createdTitles = [];
        $createdCount = 0;
        $skipped = 0;
        $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        $retirement = [
            'retired_count' => 0,
            'blocked_by_gate' => 0,
            'blocked_by_plan' => 0,
        ];
        $eligibility = [
            'skipped' => 0,
            'blocked_by_gate' => 0,
            'blocked_by_plan' => 0,
            'gate_redirected' => 0,
        ];

        // Guardian mode: never automate task creation.
        if ($mode === '3') {
            return [
                'mode' => $mode,
                'created_count' => 0,
                'skipped' => 0,
                'created_titles' => [],
                'retired_count' => 0,
                'blocked_by_gate' => 0,
                'blocked_by_plan' => 0,
                'gate_redirected' => 0,
            ];
        }

        if ($workspaceId > 0) {
            $retirement = $this->taskCreationEligibility->retireIneligibleOpenStarterTasks($workspaceId, $userId);
        }

        $lastSyncKey = $this->lastSyncPreferenceKey();
        $lastSyncDate = $this->preferences->getPreference($userId, $lastSyncKey) ?? '';
        if (!$force && $mode === '1' && $lastSyncDate === $today) {
            return [
                'mode' => $mode,
                'created_count' => 0,
                'skipped' => 0,
                'created_titles' => [],
                'retired_count' => (int) ($retirement['retired_count'] ?? 0),
                'blocked_by_gate' => (int) ($retirement['blocked_by_gate'] ?? 0),
                'blocked_by_plan' => (int) ($retirement['blocked_by_plan'] ?? 0),
                'gate_redirected' => 0,
            ];
        }
        if (!$force && $mode !== '1') {
            return [
                'mode' => $mode,
                'created_count' => 0,
                'skipped' => 0,
                'created_titles' => [],
                'retired_count' => (int) ($retirement['retired_count'] ?? 0),
                'blocked_by_gate' => (int) ($retirement['blocked_by_gate'] ?? 0),
                'blocked_by_plan' => (int) ($retirement['blocked_by_plan'] ?? 0),
                'gate_redirected' => 0,
            ];
        }

        $recommendations = (!$force && $mode === '1')
            ? $this->coach->generateStarterTaskRecommendations($userId, $mode)
            : $this->coach->generateRecommendations($userId, $mode);
        $candidates = $this->buildTaskCandidates($recommendations, $mode, $force);
        if ($workspaceId > 0) {
            $eligibility = $this->taskCreationEligibility->filterCandidates($workspaceId, $userId, $candidates);
            $candidates = $eligibility['candidates'];
            $skipped += (int) ($eligibility['skipped'] ?? 0);
        }

        foreach ($candidates as $item) {
            $title = $this->makeConciseTaskTitle((string) ($item['title'] ?? ''));
            if ($title === '') {
                $skipped++;
                continue;
            }

            if ($this->hasOpenTaskWithTitle($userId, $title)) {
                $skipped++;
                continue;
            }

            $reason = $this->makeConciseTaskText((string) ($item['reason'] ?? ''), 180);
            $taskIntent = $this->inferTaskIntent($title, $reason);
            $templateEvidenceTypes = $this->normalizeEvidenceTypes((array) ($item['completion_evidence_types'] ?? []));
            $evidenceTypes = array_values(array_unique(array_merge($templateEvidenceTypes, $this->inferCompletionEvidenceTypes($title, $reason))));
            $subtasks = $this->normalizeSuggestedSubtasks((array) ($item['suggested_subtasks'] ?? []), $title, $reason, $taskIntent, $evidenceTypes);
            $completionGuidance = $this->buildCompletionGuidance($title, $reason, $taskIntent, $evidenceTypes, $subtasks);
            $autoCompleteSupported = $this->isAutoCompletionSupported($evidenceTypes, $taskIntent, (array) $item, [
                'contact_id' => $item['contact_id'] ?? null,
            ]);

            $description = $reason !== '' ? 'Reason: ' . $reason : '';

            $assignmentContext = [
                'title' => $title,
                'description' => $description,
                'metadata_json' => [
                    'source_surface' => 'ai_coach',
                    'source_recommendation_type' => (string) ($item['source_recommendation_type'] ?? $item['category'] ?? 'coach'),
                    'marketplace_skill_key' => !empty($item['marketplace_skill_key']) ? (string) $item['marketplace_skill_key'] : null,
                    'skill_task_template_source' => !empty($item['skill_task_template_source']) && is_array($item['skill_task_template_source']) ? $item['skill_task_template_source'] : [],
                    'skill_task_template_target_metric_hints' => !empty($item['skill_task_template_target_metric_hints']) && is_array($item['skill_task_template_target_metric_hints']) ? $item['skill_task_template_target_metric_hints'] : [],
                    'skill_task_template_evidence_types' => $templateEvidenceTypes,
                    'marketplace_next_setup_step' => !empty($item['marketplace_next_setup_step']) && is_array($item['marketplace_next_setup_step']) ? $item['marketplace_next_setup_step'] : null,
                    'marketplace_activation_bundle' => !empty($item['marketplace_activation_bundle']) && is_array($item['marketplace_activation_bundle']) ? $item['marketplace_activation_bundle'] : null,
                    'blocked_marketplace_skill_key' => !empty($item['blocked_marketplace_skill_key']) ? (string) $item['blocked_marketplace_skill_key'] : null,
                    'gate_redirected_from_skill_key' => !empty($item['gate_redirected_from_skill_key']) ? (string) $item['gate_redirected_from_skill_key'] : null,
                    'marketplace_access_state' => !empty($item['marketplace_access_state']) ? (string) $item['marketplace_access_state'] : null,
                    'source_context' => !empty($item['source_context']) && is_array($item['source_context']) ? $item['source_context'] : null,
                    'evidence' => !empty($item['evidence']) && is_array($item['evidence']) ? $item['evidence'] : [],
                    'task_intent' => $taskIntent,
                ],
            ];
            $assignment = $this->assignmentAccess->resolveAiAssignee([$userId], $assignmentContext);

            $taskData = [
                'title' => $title,
                'description' => $description,
                'created_by' => $userId,
                'assigned_to' => $assignment['assigned_to'],
                'assignment_mode' => 'ai',
                'status' => 'pending',
                'priority' => $this->mapPriority((string) ($item['impact'] ?? 'Medium')),
                'due_date' => $this->recommendedDueDate((string) ($item['effort'] ?? 'Medium')),
                'metadata_json' => [
                    'source_surface' => 'ai_coach',
                    'source_goal_id' => !empty($item['target_id']) ? (int) $item['target_id'] : null,
                    'source_recommendation_type' => (string) ($item['source_recommendation_type'] ?? $item['category'] ?? 'coach'),
                    'guidance_run_id' => (int) ($recommendations['diagnostics']['guidance_run_id'] ?? 0) ?: null,
                    'recommendation_key' => !empty($item['recommendation_key']) ? (string) $item['recommendation_key'] : null,
                    'marketplace_skill_key' => !empty($item['marketplace_skill_key']) ? (string) $item['marketplace_skill_key'] : null,
                    'skill_task_template_source' => !empty($item['skill_task_template_source']) && is_array($item['skill_task_template_source']) ? $item['skill_task_template_source'] : [],
                    'skill_task_template_target_metric_hints' => !empty($item['skill_task_template_target_metric_hints']) && is_array($item['skill_task_template_target_metric_hints']) ? $item['skill_task_template_target_metric_hints'] : [],
                    'skill_task_template_evidence_types' => $templateEvidenceTypes,
                    'marketplace_setup_url' => !empty($item['marketplace_setup_url']) ? (string) $item['marketplace_setup_url'] : null,
                    'marketplace_next_setup_step' => !empty($item['marketplace_next_setup_step']) && is_array($item['marketplace_next_setup_step']) ? $item['marketplace_next_setup_step'] : null,
                    'marketplace_setup_progress' => !empty($item['marketplace_setup_progress']) && is_array($item['marketplace_setup_progress']) ? $item['marketplace_setup_progress'] : null,
                    'marketplace_activation_bundle' => !empty($item['marketplace_activation_bundle']) && is_array($item['marketplace_activation_bundle']) ? $item['marketplace_activation_bundle'] : null,
                    'blocked_marketplace_skill_key' => !empty($item['blocked_marketplace_skill_key']) ? (string) $item['blocked_marketplace_skill_key'] : null,
                    'gate_redirected_from_skill_key' => !empty($item['gate_redirected_from_skill_key']) ? (string) $item['gate_redirected_from_skill_key'] : null,
                    'marketplace_access_state' => !empty($item['marketplace_access_state']) ? (string) $item['marketplace_access_state'] : null,
                    'source_context' => !empty($item['source_context']) && is_array($item['source_context']) ? $item['source_context'] : null,
                    'evidence' => !empty($item['evidence']) && is_array($item['evidence']) ? $item['evidence'] : [],
                    'predicted_confidence' => (float) ($recommendations['diagnostics']['confidence_score'] ?? 1.0),
                    'context_quality_score' => (float) ($recommendations['diagnostics']['context_quality_score'] ?? 0.0),
                    'goal_relevance_score' => (float) ($recommendations['diagnostics']['goal_relevance_score'] ?? 0.0),
                    'policy_decision' => (string) ($recommendations['diagnostics']['decision'] ?? 'allow'),
                    'completion_evidence_types' => $evidenceTypes,
                    'auto_complete_allowed' => $autoCompleteSupported,
                    'task_intent' => $taskIntent,
                    'protected_from_auto_complete' => !$autoCompleteSupported,
                    'completion_detector_state' => $autoCompleteSupported ? 'supported' : 'review_only',
                    'completion_guidance' => $completionGuidance,
                    'assignment_resolution' => $assignment,
                ],
            ];

            if (!empty($subtasks)) {
                $this->tasks->createWithSubtasks($taskData, $subtasks);
            } else {
                $this->tasks->create($taskData);
            }

            $createdCount++;
            $createdTitles[] = $title;
        }

        if ($mode === '1' && (!empty($candidates) || $createdCount > 0 || $skipped > 0)) {
            $this->preferences->setPreference($userId, $lastSyncKey, $today);
        }

        $guidanceRunId = (int) ($recommendations['diagnostics']['guidance_run_id'] ?? 0);
        if ($guidanceRunId > 0 && $createdCount > 0) {
            $run = Database::queryOne("SELECT * FROM ai_guidance_runs WHERE id = ?", [$guidanceRunId]) ?: [];
            if ($run) {
                $this->outcomes->recordGuidanceOutcome(
                    $run,
                    $this->classifier->classifyCoachOutcome([
                        'action_taken' => true,
                        'created_count' => $createdCount,
                        'created_titles' => $createdTitles,
                        'sync_mode' => $force ? 'manual' : 'auto',
                    ])
                );
            }
        }

        return [
            'mode' => $mode,
            'created_count' => $createdCount,
            'skipped' => $skipped,
            'created_titles' => $createdTitles,
            'retired_count' => (int) ($retirement['retired_count'] ?? 0),
            'blocked_by_gate' => (int) ($retirement['blocked_by_gate'] ?? 0) + (int) ($eligibility['blocked_by_gate'] ?? 0),
            'blocked_by_plan' => (int) ($retirement['blocked_by_plan'] ?? 0) + (int) ($eligibility['blocked_by_plan'] ?? 0),
            'gate_redirected' => (int) ($eligibility['gate_redirected'] ?? 0),
        ];
    }

    /**
     * @param array<string,mixed> $recommendations
     * @return array<int,array<string,mixed>>
     */
    private function buildTaskCandidates(array $recommendations, string $mode, bool $force): array
    {
        $items = [];
        $priorities = array_slice((array) ($recommendations['priorities'] ?? []), 0, 3);
        $quickWins = array_slice((array) ($recommendations['quick_wins'] ?? []), 0, 2);
        $foundationGaps = array_slice((array) ($recommendations['foundation_gaps'] ?? []), 0, 2);

        if (!$force) {
            // Automatic daily seeding in Foundation mode should prioritize setup blockers first.
            if ($mode === '1') {
                $items = array_merge($foundationGaps, $priorities);
            }
        } elseif ($mode === '1') {
            $items = array_merge($priorities, $quickWins, $foundationGaps);
        } else {
            // Operations mode: only priorities on explicit manual sync.
            $items = $priorities;
        }

        return array_slice($this->skillTaskTemplates->filterTaskCreationCandidates($items), 0, 5);
    }

    /**
     * Annotate recommendation payload items with their task creation state for UI surfaces.
     *
     * @param array<string,mixed> $recommendations
     * @return array<string,mixed>
     */
    public function annotateRecommendationsWithTaskState(int $userId, array $recommendations): array
    {
        foreach (self::RECOMMENDATION_SECTIONS as $section) {
            $recommendations[$section] = array_map(function (array $item) use ($userId): array {
                $title = trim((string) ($item['title'] ?? ''));
                if ($title === '') {
                    $item['task_state'] = 'available';
                    $item['linked_task_id'] = null;
                    $item['linked_task_title'] = null;
                    $item['linked_task_link'] = null;
                    return $item;
                }

                $task = $this->findOpenTaskForRecommendation($userId, $item);
                if ($task) {
                    $taskId = (int) ($task['id'] ?? 0);
                    $item['task_state'] = 'created';
                    $item['linked_task_id'] = $taskId > 0 ? $taskId : null;
                    $item['linked_task_title'] = (string) ($task['title'] ?? $title);
                    $item['linked_task_link'] = $taskId > 0 ? publicUrl('task_view.php?id=' . $taskId) : null;
                } else {
                    $item['task_state'] = 'available';
                    $item['linked_task_id'] = null;
                    $item['linked_task_title'] = null;
                    $item['linked_task_link'] = null;
                }

                return $item;
            }, array_values((array) ($recommendations[$section] ?? [])));
        }

        return $recommendations;
    }

    private function hasOpenTaskWithTitle(int $userId, string $title): bool
    {
        return !empty($this->findOpenTaskForRecommendation($userId, ['title' => $title]));
    }

    public function findOpenTaskForRecommendation(int $userId, array $item): ?array
    {
        $title = trim((string) ($item['title'] ?? ''));
        $recommendationKey = trim((string) ($item['recommendation_key'] ?? ''));
        if ($userId <= 0 || $title === '') {
            return null;
        }
        $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        $workspaceSql = $workspaceId > 0 ? ' AND workspace_id = ?' : '';

        if ($recommendationKey !== '') {
            $params = [$userId, $userId];
            if ($workspaceId > 0) {
                $params[] = $workspaceId;
            }
            $rows = Database::query(
                "SELECT id, title, metadata_json
                 FROM tasks
                 WHERE (assigned_to = ? OR created_by = ?)
                   AND status NOT IN ('completed', 'cancelled')" . $workspaceSql,
                $params
            );
            foreach ($rows as $row) {
                $metadata = $this->decodeMetadata($row['metadata_json'] ?? null);
                if (($metadata['recommendation_key'] ?? '') === $recommendationKey) {
                    return $row;
                }
            }
        }

        $params = [$userId, $userId];
        if ($workspaceId > 0) {
            $params[] = $workspaceId;
        }
        $existing = Database::query(
            "SELECT id, title, metadata_json
             FROM tasks
             WHERE (assigned_to = ? OR created_by = ?)
               AND status NOT IN ('completed', 'cancelled')" . $workspaceSql,
            $params
        );

        $targetId = (int) ($item['target_id'] ?? 0);
        $canonicalTitle = $this->canonicalizeTaskTitle($title);
        foreach ($existing as $row) {
            $rowTitle = (string) ($row['title'] ?? '');
            if ($rowTitle !== '' && strcasecmp($rowTitle, $title) === 0) {
                return $row;
            }

            $metadata = $this->decodeMetadata($row['metadata_json'] ?? null);
            if (
                $targetId > 0
                && !empty($metadata['source_goal_id'])
                && (int) $metadata['source_goal_id'] === $targetId
                && $this->titlesAreEquivalent($title, $rowTitle)
            ) {
                return $row;
            }

            if ($this->titlesAreEquivalent($title, $rowTitle, $canonicalTitle)) {
                return $row;
            }
        }

        return null;
    }

    private function lastSyncPreferenceKey(): string
    {
        $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        return $workspaceId > 0 ? self::PREF_LAST_SYNC_DATE . ':' . $workspaceId : self::PREF_LAST_SYNC_DATE;
    }

    private function titlesAreEquivalent(string $left, string $right, ?string $leftCanonical = null): bool
    {
        $leftCanonical = $leftCanonical ?? $this->canonicalizeTaskTitle($left);
        $rightCanonical = $this->canonicalizeTaskTitle($right);
        if ($leftCanonical === '' || $rightCanonical === '') {
            return false;
        }
        if ($leftCanonical === $rightCanonical) {
            return true;
        }

        $leftTokens = array_values(array_filter(explode(' ', $leftCanonical)));
        $rightTokens = array_values(array_filter(explode(' ', $rightCanonical)));
        if ($leftTokens === [] || $rightTokens === []) {
            return false;
        }

        $shared = array_values(array_intersect($leftTokens, $rightTokens));
        $union = array_values(array_unique(array_merge($leftTokens, $rightTokens)));
        $overlap = count($union) > 0 ? count($shared) / count($union) : 0;
        similar_text($leftCanonical, $rightCanonical, $similarity);

        return count($shared) >= 2 || $overlap >= 0.6 || $similarity >= 72.0;
    }

    private function canonicalizeTaskTitle(string $title): string
    {
        $title = strtolower(trim($title));
        if ($title === '') {
            return '';
        }

        $title = str_replace(['-', '_', '/', ':'], ' ', $title);
        $replacements = [
            'configuration' => '',
            'configure' => '',
            'config' => '',
            'setup' => '',
            'set up' => '',
            'define' => '',
            'complete' => '',
            'save' => '',
            'verify' => '',
            'create' => '',
            'your' => '',
            'first' => '',
            'system' => '',
        ];
        $title = strtr($title, $replacements);
        $title = preg_replace('/\s+/', ' ', $title ?? '');
        $tokens = array_values(array_filter(explode(' ', trim((string) $title)), static function (string $token): bool {
            return strlen($token) >= 3;
        }));
        sort($tokens);
        return implode(' ', array_unique($tokens));
    }

    private function decodeMetadata($raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function extractReasonFromDescription(string $description): string
    {
        $description = str_replace("\r\n", "\n", trim($description));
        if ($description === '') {
            return '';
        }

        $lines = array_values(array_filter(array_map('trim', explode("\n", $description)), static fn(string $line): bool => $line !== ''));
        foreach ($lines as $line) {
            if (preg_match('/^Reason:\s*(.+)$/i', $line, $matches) === 1) {
                return trim((string) ($matches[1] ?? ''));
            }
        }

        $visible = [];
        foreach ($lines as $line) {
            if (
                $line === self::DESC_MARKER
                || preg_match('/^Source:\s*/i', $line) === 1
                || preg_match('/^Completion summary:\s*/i', $line) === 1
            ) {
                continue;
            }
            $visible[] = $line;
        }

        return implode(' ', $visible);
    }

    private function makeConciseTaskTitle(string $title): string
    {
        $title = $this->collapseWhitespace($title);
        $title = preg_replace('/^(recommendation|priority|quick win|next action)\s*[:\-]\s*/i', '', $title) ?? $title;
        $title = $this->truncateAtWord($title, 90);
        return $title !== '' ? $title : 'Review AI Coach recommendation';
    }

    private function makeConciseTaskText(string $text, int $maxLength): string
    {
        $text = strip_tags($text);
        $text = $this->collapseWhitespace($text);
        $text = preg_replace('/^(AI Coach recommends|Recommendation|Reason)\s*[:\-]?\s*/i', '', $text) ?? $text;
        return $this->truncateAtWord($text, $maxLength);
    }

    private function collapseWhitespace(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }

    private function truncateAtWord(string $text, int $maxLength): string
    {
        $text = trim($text);
        if ($maxLength <= 0 || strlen($text) <= $maxLength) {
            return $text;
        }

        $trimmed = rtrim(substr($text, 0, $maxLength));
        $lastSpace = strrpos($trimmed, ' ');
        if ($lastSpace !== false && $lastSpace >= 40) {
            $trimmed = substr($trimmed, 0, $lastSpace);
        }

        return rtrim($trimmed, " \t\n\r\0\x0B.,;:") . '...';
    }

    private function mapPriority(string $impact): string
    {
        $impact = strtolower(trim($impact));
        if ($impact === 'high') {
            return 'high';
        }
        if ($impact === 'low') {
            return 'low';
        }
        return 'medium';
    }

    private function recommendedDueDate(string $effort): string
    {
        $effort = strtolower(trim($effort));
        $days = 1;
        if ($effort === 'high') {
            $days = 3;
        } elseif ($effort === 'low') {
            $days = 0;
        }
        return date('Y-m-d 18:00:00', strtotime('+' . $days . ' day'));
    }

    public static function isAIAutoTask(array $task): bool
    {
        $description = (string) ($task['description'] ?? '');
        if (strpos($description, self::DESC_MARKER) !== false) {
            return true;
        }

        $metadata = [];
        if (!empty($task['metadata_json'])) {
            $decoded = json_decode((string) $task['metadata_json'], true);
            if (is_array($decoded)) {
                $metadata = $decoded;
            }
        }

        return (string) ($metadata['source_surface'] ?? '') === 'ai_coach'
            || (string) ($metadata['source_recommendation_type'] ?? '') === 'coach';
    }

    public static function resolveCompletionGuidance(array $task, array $subtasks = []): array
    {
        $metadata = [];
        if (!empty($task['metadata_json'])) {
            $decoded = json_decode((string) $task['metadata_json'], true);
            if (is_array($decoded)) {
                $metadata = $decoded;
            }
        }
        if (!empty($metadata['completion_guidance']) && is_array($metadata['completion_guidance'])) {
            return $metadata['completion_guidance'];
        }

        $service = new self();
        $reason = $service->extractReasonFromDescription((string) ($task['description'] ?? ''));
        $inferredIntent = $service->inferTaskIntent((string) ($task['title'] ?? ''), $reason);
        $storedIntent = (string) ($metadata['task_intent'] ?? 'review');
        $intent = $storedIntent !== '' && $storedIntent !== 'review' ? $storedIntent : $inferredIntent;
        $inferredEvidenceTypes = $service->inferCompletionEvidenceTypes((string) ($task['title'] ?? ''), $reason);
        $storedEvidenceTypes = array_values(array_filter((array) ($metadata['completion_evidence_types'] ?? []), 'is_string'));
        $evidenceTypes = array_values(array_unique(array_merge($inferredEvidenceTypes, $storedEvidenceTypes)));
        $normalizedSubtasks = [];
        $position = 1;
        foreach ($subtasks as $subtask) {
            if (!is_array($subtask)) {
                continue;
            }
            $subtaskTitle = (string) ($subtask['title'] ?? '');
            $subtaskDescription = trim((string) ($subtask['description'] ?? ''));
            $normalizedSubtasks[] = [
                'title' => $subtaskTitle,
                'description' => $subtaskDescription !== ''
                    ? $subtaskDescription
                    : $service->buildSubtaskGuidance($subtaskTitle, (string) ($task['title'] ?? ''), $reason, $intent, $evidenceTypes, $position),
            ];
            $position++;
        }

        return $service->buildCompletionGuidance(
            (string) ($task['title'] ?? ''),
            $reason,
            $intent,
            $evidenceTypes,
            $normalizedSubtasks
        );
    }

    public static function reconcileTaskSubtasks(array $task, array $subtasks = []): array
    {
        $service = new self();
        $metadata = [];
        if (!empty($task['metadata_json'])) {
            $decoded = json_decode((string) $task['metadata_json'], true);
            if (is_array($decoded)) {
                $metadata = $decoded;
            }
        }

        $reason = $service->extractReasonFromDescription((string) ($task['description'] ?? ''));
        $inferredIntent = $service->inferTaskIntent((string) ($task['title'] ?? ''), $reason);
        $storedIntent = (string) ($metadata['task_intent'] ?? 'review');
        $taskIntent = $storedIntent !== '' && $storedIntent !== 'review' ? $storedIntent : $inferredIntent;
        $evidenceTypes = array_values(array_unique(array_merge(
            $service->inferCompletionEvidenceTypes((string) ($task['title'] ?? ''), $reason),
            array_values(array_filter((array) ($metadata['completion_evidence_types'] ?? []), 'is_string'))
        )));
        $candidateSubtasks = [];
        foreach ($subtasks as $subtask) {
            if (!is_array($subtask)) {
                continue;
            }
            $candidateSubtasks[] = [
                'title' => (string) ($subtask['title'] ?? ''),
                'description' => (string) ($subtask['description'] ?? ''),
            ];
        }

        if ($service->looksLikeGenericChecklist($candidateSubtasks)) {
            $candidateSubtasks = [];
        }

        return $service->normalizeSuggestedSubtasks(
            $candidateSubtasks,
            (string) ($task['title'] ?? ''),
            $reason,
            $taskIntent,
            $evidenceTypes
        );
    }

    private function looksLikeGenericChecklist(array $subtasks): bool
    {
        if (count($subtasks) !== 3) {
            return false;
        }

        $genericTitles = [
            'open the related crm area',
            'save the required change',
            'verify the expected result',
            'open the required setup area',
            'save the configuration',
            'verify the setup works',
        ];

        foreach ($subtasks as $subtask) {
            $title = strtolower(trim((string) ($subtask['title'] ?? '')));
            if (!in_array($title, $genericTitles, true)) {
                return false;
            }
        }

        return true;
    }

    private function inferCompletionEvidenceTypes(string $title, string $reason): array
    {
        $titleText = strtolower($title);
        $text = strtolower($title . ' ' . $reason);
        if (str_contains($titleText, 'reply') || str_contains($titleText, 'follow up')) {
            return ['email_reply_received'];
        }
        if (str_contains($titleText, 'finance') && str_contains($titleText, 'setup')) {
            return ['finance_setup_ready'];
        }
        if (str_contains($titleText, 'invoice') || str_contains($titleText, 'invoicing') || str_contains($titleText, 'billing')) {
            return ['invoice_paid', 'workflow_step_completed'];
        }
        if (
            str_contains($titleText, 'pricing')
            || str_contains($titleText, 'price ')
            || str_contains($titleText, 'product')
            || str_contains($titleText, 'offer')
            || str_contains($titleText, 'package')
            || str_contains($titleText, 'plan')
        ) {
            return ['workflow_step_completed'];
        }
        if (str_contains($titleText, 'deal') || str_contains($titleText, 'stage')) {
            return ['deal_stage_reached'];
        }
        if (str_contains($text, 'reply') || str_contains($text, 'follow up')) {
            return ['email_reply_received'];
        }
        if (str_contains($text, 'finance') && str_contains($text, 'setup')) {
            return ['finance_setup_ready'];
        }
        if (str_contains($text, 'invoice') || str_contains($text, 'invoicing') || str_contains($text, 'billing')) {
            return ['invoice_paid', 'workflow_step_completed'];
        }
        if (str_contains($text, 'deal') || str_contains($text, 'stage')) {
            return ['deal_stage_reached'];
        }
        return ['workflow_step_completed'];
    }

    private function normalizeEvidenceTypes(array $evidenceTypes): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn($type): string => trim((string) $type),
            $evidenceTypes
        ))));
    }

    private function isAutoCompletionSupported(array $evidenceTypes, string $taskIntent, array $metadata = [], array $taskData = []): bool
    {
        $evidenceTypes = $this->normalizeEvidenceTypes($evidenceTypes);
        if (in_array('finance_setup_ready', $evidenceTypes, true)) {
            return true;
        }
        if (in_array('invoice_paid', $evidenceTypes, true)) {
            return true;
        }
        if (in_array('task_dependency_completed', $evidenceTypes, true) && (int) ($metadata['dependency_task_id'] ?? 0) > 0) {
            return true;
        }
        if (
            in_array('deal_stage_reached', $evidenceTypes, true)
            && (int) ($metadata['deal_id'] ?? 0) > 0
            && trim((string) ($metadata['deal_stage'] ?? '')) !== ''
        ) {
            return true;
        }
        if (
            in_array('email_reply_received', $evidenceTypes, true)
            && (int) ($taskData['contact_id'] ?? $metadata['contact_id'] ?? 0) > 0
        ) {
            return true;
        }
        return $taskIntent === 'billing' && in_array('workflow_step_completed', $evidenceTypes, true);
    }

    private function inferTaskIntent(string $title, string $reason): string
    {
        $titleText = strtolower($title);
        $text = strtolower($title . ' ' . $reason);
        if (str_contains($titleText, 'follow') || str_contains($titleText, 'reply')) {
            return 'follow_up';
        }
        if (str_contains($titleText, 'invoice') || str_contains($titleText, 'invoicing') || str_contains($titleText, 'billing')) {
            return 'billing';
        }
        if (str_contains($titleText, 'workflow') || str_contains($titleText, 'automation')) {
            return 'ops';
        }
        if (
            str_contains($titleText, 'pricing')
            || str_contains($titleText, 'price ')
            || str_contains($titleText, 'product')
            || str_contains($titleText, 'offer')
            || str_contains($titleText, 'package')
            || str_contains($titleText, 'plan')
        ) {
            return 'pricing';
        }
        if (
            str_contains($titleText, 'profile')
            || str_contains($titleText, 'company')
            || str_contains($titleText, 'branding')
            || str_contains($titleText, 'brand')
        ) {
            return 'company_profile';
        }
        if (
            str_contains($titleText, 'pipeline')
            || str_contains($titleText, 'stage')
            || str_contains($titleText, 'sales process')
            || str_contains($titleText, 'deal flow')
            || str_contains($titleText, 'deal stage')
        ) {
            return 'sales_process';
        }
        if (
            str_contains($titleText, 'segment')
            || str_contains($titleText, 'audience')
            || str_contains($titleText, 'customer group')
            || str_contains($titleText, 'ideal customer')
            || str_contains($titleText, 'icp')
        ) {
            return 'segmentation';
        }
        if (str_contains($titleText, 'goal') || str_contains($titleText, 'target')) {
            return 'goal_progress';
        }
        if (str_contains($text, 'invoice') || str_contains($text, 'invoicing') || str_contains($text, 'billing')) {
            return 'billing';
        }
        if (str_contains($text, 'workflow') || str_contains($text, 'automation')) {
            return 'ops';
        }
        if (
            str_contains($text, 'pricing')
            || str_contains($text, 'price ')
            || str_contains($text, 'product')
            || str_contains($text, 'offer')
            || str_contains($text, 'package')
            || str_contains($text, 'plan')
        ) {
            return 'pricing';
        }
        if (
            str_contains($text, 'profile')
            || str_contains($text, 'company')
            || str_contains($text, 'branding')
            || str_contains($text, 'brand')
        ) {
            return 'company_profile';
        }
        if (
            str_contains($text, 'pipeline')
            || str_contains($text, 'stage')
            || str_contains($text, 'sales process')
            || str_contains($text, 'deal flow')
            || str_contains($text, 'deal stage')
        ) {
            return 'sales_process';
        }
        if (
            str_contains($text, 'segment')
            || str_contains($text, 'audience')
            || str_contains($text, 'customer group')
            || str_contains($text, 'ideal customer')
            || str_contains($text, 'icp')
        ) {
            return 'segmentation';
        }
        if (str_contains($text, 'goal') || str_contains($text, 'target')) {
            return 'goal_progress';
        }
        if (str_contains($text, 'follow') || str_contains($text, 'reply')) {
            return 'follow_up';
        }
        return 'review';
    }

    private function normalizeSuggestedSubtasks(array $subtasks, string $title, string $reason, string $taskIntent, array $evidenceTypes): array
    {
        $subtasks = $this->expandToMeasurableSubtasks($subtasks, $title, $taskIntent);
        $normalized = [];
        $position = 1;
        foreach ($subtasks as $subtask) {
            $subtaskTitle = '';
            if (is_string($subtask)) {
                $subtaskTitle = trim($subtask);
            } elseif (is_array($subtask)) {
                $subtaskTitle = trim((string) ($subtask['title'] ?? ''));
            }
            if ($subtaskTitle === '') {
                continue;
            }

            $normalized[] = [
                'title' => $subtaskTitle,
                'description' => $this->buildSubtaskGuidance($subtaskTitle, $title, $reason, $taskIntent, $evidenceTypes, $position),
            ];
            $position++;
        }

        if ($normalized === []) {
            $fallbacks = $this->buildFallbackSubtasks($title, $taskIntent);
            foreach ($fallbacks as $fallbackTitle) {
                $normalized[] = [
                    'title' => $fallbackTitle,
                    'description' => $this->buildSubtaskGuidance($fallbackTitle, $title, $reason, $taskIntent, $evidenceTypes, $position),
                ];
                $position++;
            }
        }

        return $normalized;
    }

    private function expandToMeasurableSubtasks(array $subtasks, string $title, string $taskIntent): array
    {
        $cleanTitles = array_values(array_filter(array_map(function ($subtask): string {
            if (is_string($subtask)) {
                return trim($subtask);
            }
            if (is_array($subtask)) {
                return trim((string) ($subtask['title'] ?? ''));
            }
            return '';
        }, $subtasks)));

        if (!$this->shouldReplaceSubtasksWithBlueprint($cleanTitles, $title)) {
            return $subtasks;
        }

        $replacementTitles = $this->buildFallbackSubtasks($title, $taskIntent);
        return array_map(static fn(string $item): array => ['title' => $item], $replacementTitles);
    }

    private function shouldReplaceSubtasksWithBlueprint(array $cleanTitles, string $title): bool
    {
        if ($cleanTitles === []) {
            return true;
        }

        if (count($cleanTitles) < 3) {
            return true;
        }

        foreach ($cleanTitles as $subtaskTitle) {
            if ($this->isBroadSubtaskTitle($subtaskTitle, $title)) {
                return true;
            }
        }

        return false;
    }

    private function isBroadSubtaskTitle(string $subtaskTitle, string $taskTitle): bool
    {
        $subtaskLower = strtolower(trim($subtaskTitle));
        $taskLower = strtolower(trim($taskTitle));
        if ($subtaskLower === '' || $subtaskLower === $taskLower) {
            return true;
        }

        $genericPhrases = [
            'configure',
            'set up',
            'setup',
            'review',
            'complete setup',
            'update system',
            'check configuration',
            'configure system',
            'open the related crm area',
            'save the required change',
            'verify the expected result',
            'open the required setup area',
            'save the configuration',
            'verify the setup works',
            'save changes',
        ];

        foreach ($genericPhrases as $phrase) {
            if (preg_match('/\b' . preg_quote($phrase, '/') . '\b/', $subtaskLower) === 1) {
                return true;
            }
        }

        $specificDomainTerms = [
            'pricing',
            'price',
            'offer',
            'product',
            'invoice',
            'billing',
            'workflow',
            'automation',
            'pipeline',
            'deal',
            'segment',
            'audience',
            'target',
            'goal',
            'profile',
            'company',
            'reply',
            'follow-up',
            'follow up',
        ];
        foreach ($specificDomainTerms as $term) {
            if (str_contains($subtaskLower, $term) && strlen($subtaskLower) >= 24) {
                return false;
            }
        }

        if (preg_match('/^(open|save|verify|review|update|complete|check|define|set up|setup)$/', $subtaskLower) === 1) {
            return true;
        }

        $subtaskWordCount = count(array_values(array_filter(preg_split('/\s+/', $subtaskLower ?: ''))));
        similar_text($subtaskLower, $taskLower, $similarity);
        return $subtaskWordCount <= 4 && $similarity >= 75.0;
    }

    private function buildCompletionGuidance(string $title, string $reason, string $taskIntent, array $evidenceTypes, array $subtasks): array
    {
        $defaults = self::DEFAULT_GUIDANCE_BY_INTENT[$taskIntent] ?? self::DEFAULT_GUIDANCE_BY_INTENT['review'];
        $summary = $defaults['summary'];

        $evidenceLabels = array_values(array_filter(array_map(
            fn(string $type): string => $this->describeEvidenceSignal($type, $taskIntent),
            $evidenceTypes
        )));

        $subtaskHints = [];
        foreach (array_slice($subtasks, 0, 5) as $subtask) {
            if (!is_array($subtask) || trim((string) ($subtask['title'] ?? '')) === '') {
                continue;
            }
            $subtaskHints[] = [
                'title' => (string) $subtask['title'],
                'detail' => (string) ($subtask['description'] ?? ''),
            ];
        }

        return [
            'summary' => $summary,
            'reason' => $this->makeConciseTaskText($reason, 180),
            'completion_checks' => $defaults['completion_checks'],
            'evidence_signals' => $evidenceLabels,
            'subtask_hints' => $subtaskHints,
        ];
    }

    private function buildSubtaskGuidance(string $subtaskTitle, string $taskTitle, string $reason, string $taskIntent, array $evidenceTypes, int $position): string
    {
        $lower = strtolower($subtaskTitle . ' ' . $taskTitle . ' ' . $reason);
        return match (true) {
            $taskIntent === 'pricing' || str_contains($lower, 'pricing') || str_contains($lower, 'price') || str_contains($lower, 'offer') || str_contains($lower, 'product')
                => $this->buildPricingGuidance($position),
            $taskIntent === 'company_profile' || str_contains($lower, 'profile') || str_contains($lower, 'company') || str_contains($lower, 'brand')
                => $this->buildCompanyProfileGuidance($position),
            $taskIntent === 'sales_process' || str_contains($lower, 'pipeline') || str_contains($lower, 'stage') || str_contains($lower, 'sales process')
                => $this->buildSalesProcessGuidance($position),
            $taskIntent === 'segmentation' || str_contains($lower, 'segment') || str_contains($lower, 'audience') || str_contains($lower, 'icp')
                => $this->buildSegmentationGuidance($position),
            (str_contains($lower, 'design') && (str_contains($lower, 'invoice') || str_contains($lower, 'invoicing') || str_contains($lower, 'billing')))
                => 'Finish and save the invoice design.',
            ((str_contains($lower, 'send') || str_contains($lower, 'deliver')) && (str_contains($lower, 'invoice') || str_contains($lower, 'invoicing') || str_contains($lower, 'billing')))
                => 'Send a real or test invoice successfully.',
            str_contains($lower, 'invoice') || str_contains($lower, 'invoicing') || str_contains($lower, 'billing') || $taskIntent === 'billing'
                => 'Complete required billing fields and save.',
            str_contains($lower, 'workflow') || str_contains($lower, 'automation') || $taskIntent === 'ops'
                => 'Save the setup and run a quick test.',
            str_contains($lower, 'reply') || str_contains($lower, 'follow') || in_array('email_reply_received', $evidenceTypes, true)
                => 'Send the outreach and log the outcome.',
            str_contains($lower, 'deal') || in_array('deal_stage_reached', $evidenceTypes, true)
                => 'Update the deal and confirm the stage.',
            default
                => $this->buildGenericReviewGuidance($taskTitle),
        };
    }

    private function buildFallbackSubtasks(string $title, string $taskIntent): array
    {
        $titleLower = strtolower($title);
        if (str_contains($titleLower, 'pricing') || str_contains($titleLower, 'price') || str_contains($titleLower, 'product') || str_contains($titleLower, 'offer') || $taskIntent === 'pricing') {
            return [
                'Define offer pricing',
                'Save pricing in CRM',
                'Confirm pricing appears',
            ];
        }

        if (str_contains($titleLower, 'invoice') || str_contains($titleLower, 'invoicing') || str_contains($titleLower, 'billing') || $taskIntent === 'billing') {
            return [
                'Complete invoice design',
                'Save billing and company details',
                'Send a test invoice successfully',
            ];
        }

        if (str_contains($titleLower, 'profile') || str_contains($titleLower, 'company')) {
            return [
                'Complete company profile',
                'Save brand and contact details',
                'Confirm profile details',
            ];
        }

        if (str_contains($titleLower, 'pipeline') || str_contains($titleLower, 'stage') || str_contains($titleLower, 'sales process') || $taskIntent === 'sales_process') {
            return [
                'Define pipeline steps',
                'Save pipeline rules',
                'Test a deal move',
            ];
        }

        if (str_contains($titleLower, 'segment') || str_contains($titleLower, 'audience') || str_contains($titleLower, 'icp') || $taskIntent === 'segmentation') {
            return [
                'Define segment criteria',
                'Save segment filters',
                'Confirm matching records',
            ];
        }

        if (str_contains($titleLower, 'workflow') || str_contains($titleLower, 'automation') || $taskIntent === 'ops') {
            return [
                'Define workflow trigger',
                'Save workflow actions',
                'Test the automation',
            ];
        }

        return match ($taskIntent) {
            'pricing' => ['Define offer pricing', 'Save pricing in CRM', 'Confirm pricing appears'],
            'billing' => ['Complete invoice design', 'Save billing and company details', 'Send a test invoice successfully'],
            'company_profile' => ['Complete company profile', 'Save brand and contact details', 'Confirm profile details'],
            'sales_process' => ['Define pipeline steps', 'Save pipeline rules', 'Test a deal move'],
            'segmentation' => ['Define segment criteria', 'Save segment filters', 'Confirm matching records'],
            'ops' => ['Define workflow trigger', 'Save workflow actions', 'Test the automation'],
            'follow_up' => ['Send follow-up', 'Record response', 'Log next action'],
            'goal_progress' => ['Set target metric', 'Save baseline data', 'Confirm target progress'],
            default => $this->buildContextualGenericFallbackSubtasks($title),
        };
    }

    private function describeEvidenceSignal(string $type, string $taskIntent): string
    {
        return match ($type) {
            'email_reply_received' => 'A recorded inbound reply',
            'finance_setup_ready' => 'Finance setup is ready',
            'invoice_paid' => 'A paid invoice',
            'workflow_step_completed' => match ($taskIntent) {
                'pricing' => 'Saved pricing or product configuration in the CRM',
                'company_profile' => 'Saved company profile or branding details',
                'sales_process' => 'Saved pipeline or sales process configuration',
                'segmentation' => 'Saved segment or filter configuration',
                default => 'A saved workflow or billing step in the CRM',
            },
            'deal_stage_reached' => 'A deal moved to the required stage',
            'task_dependency_completed' => 'A linked dependency task completed',
            default => '',
        };
    }

    private function buildPricingGuidance(int $position): string
    {
        return match ($position) {
            1 => 'Set price, frequency, and tiers.',
            2 => 'Save pricing on the product or offer.',
            default => 'Confirm pricing is visible.',
        };
    }

    private function buildCompanyProfileGuidance(int $position): string
    {
        return match ($position) {
            1 => 'Fill the required profile fields.',
            2 => 'Save brand and contact details.',
            default => 'Confirm details appear correctly.',
        };
    }

    private function buildSalesProcessGuidance(int $position): string
    {
        return match ($position) {
            1 => 'Define stages or sales steps.',
            2 => 'Save rules and ownership.',
            default => 'Confirm a deal can use it.',
        };
    }

    private function buildSegmentationGuidance(int $position): string
    {
        return match ($position) {
            1 => 'Define criteria, tags, or filters.',
            2 => 'Save the segment.',
            default => 'Confirm expected records appear.',
        };
    }

    private function buildGenericReviewGuidance(string $taskTitle): string
    {
        [$area, $subject] = $this->inferGenericAreaAndSubject($taskTitle);
        return 'Update ' . $area . ' and confirm the ' . $subject . ' is saved.';
    }

    private function buildContextualGenericFallbackSubtasks(string $title): array
    {
        [$area, $subject] = $this->inferGenericAreaAndSubject($title);

        return [
            'Open ' . $area,
            'Save the ' . $subject,
            'Confirm the ' . $subject,
        ];
    }

    private function inferGenericAreaAndSubject(string $title): array
    {
        $text = strtolower($title);

        return match (true) {
            str_contains($text, 'pricing') || str_contains($text, 'price') => ['pricing settings', 'pricing setup'],
            str_contains($text, 'product') || str_contains($text, 'offer') => ['the product or offer record', 'product or offer update'],
            str_contains($text, 'workflow') || str_contains($text, 'automation') => ['the workflow builder', 'workflow configuration'],
            str_contains($text, 'pipeline') || str_contains($text, 'deal') || str_contains($text, 'stage') => ['pipeline settings', 'pipeline change'],
            str_contains($text, 'segment') || str_contains($text, 'audience') => ['segment filters', 'saved segment rule'],
            str_contains($text, 'goal') || str_contains($text, 'target') => ['target settings', 'target update'],
            str_contains($text, 'company') || str_contains($text, 'profile') => ['company profile settings', 'company profile update'],
            default => ['the related CRM area', 'recommended change'],
        };
    }
}
