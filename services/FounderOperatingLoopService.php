<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\BeginnerBudget;
use CRM\Modules\CompanyProfile;
use CRM\Modules\Tasks;

class FounderOperatingLoopService
{
    private WorkspaceScopeService $workspaceScope;
    private FounderFinanceService $finance;
    private StartupJourneyService $journey;

    public function __construct(
        ?WorkspaceScopeService $workspaceScope = null,
        ?FounderFinanceService $finance = null,
        ?StartupJourneyService $journey = null
    ) {
        $this->workspaceScope = $workspaceScope ?? new WorkspaceScopeService();
        $this->finance = $finance ?? new FounderFinanceService(null, null, $this->workspaceScope);
        $this->journey = $journey ?? new StartupJourneyService($this->workspaceScope);
    }

    public function summary(int $workspaceId, int $userId, ?string $weekStart = null): array
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId($workspaceId > 0 ? $workspaceId : null);
        [$weekStart, $weekEnd] = $this->weekRange($weekStart);

        $company = $this->safeCompanyProfile();
        $journey = $this->safeJourney($workspaceId, $userId);
        $startupJourneyCompletedAt = $this->startupJourneyCompletedAt($workspaceId, $userId);
        $financeDashboard = $this->finance->dashboard($workspaceId, $userId, $weekStart, $weekEnd);
        $financeSummary = (array) ($financeDashboard['summary'] ?? []);
        $budget = (array) ($financeDashboard['budget'] ?? []);
        $legacyBudget = (array) ($budget['legacy_beginner_budget'] ?? []);
        $sprint = $this->getSprint($workspaceId, $userId);
        $review = $this->getWeeklyReview($workspaceId, $userId, $weekStart);
        $commitments = !empty($review['id']) ? $this->reviewCommitments((int) $review['id'], $workspaceId) : [];
        $metrics = $this->executionMetrics($workspaceId, $userId, $weekStart, $weekEnd);
        $pricing = $this->pricingReadiness($financeSummary, $metrics, $legacyBudget);
        $weeklyPlan = $this->buildWeeklyPlan($journey, $metrics, $pricing, $sprint, $weekStart, $weekEnd);
        $firstCustomerSignal = $this->buildFirstCustomerSignal($metrics, $pricing);
        $recommendedCommitments = $this->recommendedCommitments($weeklyPlan, $firstCustomerSignal, $pricing, $weekStart, $weekEnd);

        $steps = $this->buildSteps($startupJourneyCompletedAt, $weeklyPlan, $firstCustomerSignal, $review, $metrics);
        $currentStepKey = $this->currentStepKey($steps);
        $blockedSteps = array_values(array_map(
            static fn(array $step): string => (string) $step['label'],
            array_filter($steps, static fn(array $step): bool => in_array($step['status'], ['blocked', 'needs_attention'], true))
        ));

        return [
            'week' => ['start' => $weekStart, 'end' => $weekEnd],
            'steps' => $steps,
            'current_step_key' => $currentStepKey,
            'current_step' => $steps[$currentStepKey] ?? reset($steps),
            'blocked_steps' => $blockedSteps,
            'startup_journey_completed_at' => $startupJourneyCompletedAt,
            'source_data' => [
                'company_profile' => $company,
                'startup_journey_progress' => $journey['progress'] ?? [],
                'finance_period' => $financeSummary['period'] ?? [],
            ],
            'finance_snapshot' => $this->financeSnapshot($financeSummary),
            'pricing' => $pricing,
            'sprint' => $sprint,
            'review' => $review,
            'commitments' => $commitments,
            'first_customer_signal' => $firstCustomerSignal,
            'weekly_plan' => $weeklyPlan,
            'recommended_commitments' => $recommendedCommitments,
            'metrics' => $metrics,
            'next_action' => (string) (($steps[$currentStepKey]['next_action'] ?? '') ?: 'Complete the next blocked founder step.'),
        ];
    }

    public function saveSprint(int $workspaceId, int $userId, array $data): int
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId($workspaceId > 0 ? $workspaceId : null);
        $segment = $this->shortText($data['target_customer_segment'] ?? '', 255);
        $pitch = $this->longText($data['offer_pitch'] ?? '', 4000);
        $channel = $this->shortText($data['outreach_channel'] ?? '', 120);
        $weeklyOutreachTarget = $this->nonNegativeInt($data['weekly_outreach_target'] ?? 0);
        $demoBookingTarget = $this->nonNegativeInt($data['demo_booking_target'] ?? 0);
        $paidCustomerTarget = $this->nonNegativeInt($data['paid_customer_target'] ?? 0);

        Database::execute(
            "INSERT INTO founder_first_customer_sprints (
                workspace_id, user_id, target_customer_segment, offer_pitch, outreach_channel,
                weekly_outreach_target, demo_booking_target, paid_customer_target, status, created_by, updated_by
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active', ?, ?)
             ON DUPLICATE KEY UPDATE
                target_customer_segment = VALUES(target_customer_segment),
                offer_pitch = VALUES(offer_pitch),
                outreach_channel = VALUES(outreach_channel),
                weekly_outreach_target = VALUES(weekly_outreach_target),
                demo_booking_target = VALUES(demo_booking_target),
                paid_customer_target = VALUES(paid_customer_target),
                status = 'active',
                updated_by = VALUES(updated_by),
                updated_at = NOW()",
            [
                $workspaceId,
                $userId > 0 ? $userId : null,
                $segment,
                $pitch,
                $channel,
                $weeklyOutreachTarget,
                $demoBookingTarget,
                $paidCustomerTarget,
                $userId > 0 ? $userId : null,
                $userId > 0 ? $userId : null,
            ]
        );

        $row = Database::queryOne(
            "SELECT id FROM founder_first_customer_sprints WHERE workspace_id = ? AND user_id <=> ? LIMIT 1",
            [$workspaceId, $userId > 0 ? $userId : null]
        );

        return (int) ($row['id'] ?? 0);
    }

    public function saveWeeklyReview(int $workspaceId, int $userId, array $data, bool $complete = false): int
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId($workspaceId > 0 ? $workspaceId : null);
        [$weekStart, $weekEnd] = $this->weekRange((string) ($data['week_start'] ?? ''));
        $financeSummary = (array) ($this->finance->dashboard($workspaceId, $userId, $weekStart, $weekEnd)['summary'] ?? []);
        $metrics = $this->executionMetrics($workspaceId, $userId, $weekStart, $weekEnd);
        $snapshot = $this->financeSnapshot($financeSummary);
        $status = $complete ? 'completed' : 'draft';

        $leadsCreated = array_key_exists('leads_created', $data)
            ? $this->nonNegativeInt($data['leads_created'])
            : (int) ($metrics['leads_created'] ?? 0);
        $dealsOpened = array_key_exists('deals_opened', $data)
            ? $this->nonNegativeInt($data['deals_opened'])
            : (int) ($metrics['deals_opened'] ?? 0);
        $dealsWon = array_key_exists('deals_won', $data)
            ? $this->nonNegativeInt($data['deals_won'])
            : (int) ($metrics['deals_won'] ?? 0);

        Database::execute(
            "INSERT INTO founder_weekly_reviews (
                workspace_id, user_id, week_start, week_end, wins, blockers, customer_conversations,
                leads_created, deals_opened, deals_won, paid_revenue, expenses, runway_months,
                burn_rate, cac, break_even_deals, pricing_concern, next_week_focus,
                finance_snapshot_json, review_status, completed_at, created_by, updated_by
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                week_end = VALUES(week_end),
                wins = VALUES(wins),
                blockers = VALUES(blockers),
                customer_conversations = VALUES(customer_conversations),
                leads_created = VALUES(leads_created),
                deals_opened = VALUES(deals_opened),
                deals_won = VALUES(deals_won),
                paid_revenue = VALUES(paid_revenue),
                expenses = VALUES(expenses),
                runway_months = VALUES(runway_months),
                burn_rate = VALUES(burn_rate),
                cac = VALUES(cac),
                break_even_deals = VALUES(break_even_deals),
                pricing_concern = VALUES(pricing_concern),
                next_week_focus = VALUES(next_week_focus),
                finance_snapshot_json = VALUES(finance_snapshot_json),
                review_status = VALUES(review_status),
                completed_at = VALUES(completed_at),
                updated_by = VALUES(updated_by),
                updated_at = NOW()",
            [
                $workspaceId,
                $userId > 0 ? $userId : null,
                $weekStart,
                $weekEnd,
                $this->longText($data['wins'] ?? '', 4000),
                $this->longText($data['blockers'] ?? '', 4000),
                $this->nonNegativeInt($data['customer_conversations'] ?? 0),
                $leadsCreated,
                $dealsOpened,
                $dealsWon,
                (float) ($snapshot['money_in'] ?? 0),
                (float) ($snapshot['money_out'] ?? 0),
                $snapshot['runway_months'],
                (float) ($snapshot['burn_rate'] ?? 0),
                $snapshot['cac'],
                $snapshot['break_even_deals'],
                $this->longText($data['pricing_concern'] ?? '', 4000),
                $this->longText($data['next_week_focus'] ?? '', 4000),
                json_encode($snapshot, JSON_UNESCAPED_SLASHES),
                $status,
                $complete ? date('Y-m-d H:i:s') : null,
                $userId > 0 ? $userId : null,
                $userId > 0 ? $userId : null,
            ]
        );

        $review = $this->getWeeklyReview($workspaceId, $userId, $weekStart);
        $reviewId = (int) ($review['id'] ?? 0);
        if ($reviewId > 0 && array_key_exists('commitment_title', $data)) {
            $this->replaceCommitments($reviewId, $workspaceId, $userId, $data);
        }
        if ($reviewId > 0 && $complete) {
            $this->createTasksForReviewCommitments($reviewId, $workspaceId, $userId);
        }

        return $reviewId;
    }

    public function createFirstCustomerTasks(int $workspaceId, int $userId): array
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId($workspaceId > 0 ? $workspaceId : null);
        $summary = $this->summary($workspaceId, $userId);
        $taskModule = new Tasks();
        $tasks = array_map(
            static fn(array $commitment): array => [
                'title' => (string) ($commitment['title'] ?? 'Founder Loop commitment'),
                'description' => (string) ($commitment['description'] ?? ''),
                'due_date' => (string) ($commitment['due_date'] ?? ''),
                'priority' => (string) ($commitment['priority'] ?? 'high'),
                'subtasks' => (array) ($commitment['subtasks'] ?? []),
            ],
            (array) ($summary['recommended_commitments'] ?? [])
        );

        $created = [];
        $skipped = [];
        foreach ($tasks as $task) {
            if ($this->founderLoopTaskExists($workspaceId, (string) $task['title'])) {
                $skipped[] = (string) $task['title'];
                continue;
            }
            $id = $taskModule->createWithSubtasks([
                'title' => (string) $task['title'],
                'description' => (string) $task['description'],
                'assigned_to' => $userId > 0 ? $userId : null,
                'created_by' => $userId > 0 ? $userId : null,
                'actor_user_id' => $userId > 0 ? $userId : null,
                'status' => 'pending',
                'priority' => (string) $task['priority'],
                'due_date' => $this->normalizeDate($task['due_date'] ?? ''),
                'metadata_json' => [
                    'source_surface' => 'founder_operating_loop',
                    'founder_loop_task' => true,
                    'founder_loop_step' => 'weekly_commitments',
                    'generated_from' => 'recommended_commitment',
                ],
            ], (array) $task['subtasks']);
            $created[] = ['id' => $id, 'title' => (string) $task['title']];
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    public function contextForAI(int $workspaceId, int $userId): array
    {
        $summary = $this->summary($workspaceId, $userId);
        $commitments = array_values((array) ($summary['commitments'] ?? []));
        $recommendedCommitments = array_values((array) ($summary['recommended_commitments'] ?? []));
        $currentCommitment = $this->currentCommitmentForAI($commitments, $recommendedCommitments);
        $blockedCommitment = $this->blockedCommitmentForAI($commitments);
        $review = (array) ($summary['review'] ?? []);

        return [
            'active_week' => [
                'start' => (string) ($summary['week']['start'] ?? ''),
                'end' => (string) ($summary['week']['end'] ?? ''),
                'has_saved_review' => !empty($review['id']),
            ],
            'current_loop_step' => $summary['current_step_key'] ?? '',
            'current_step_label' => (string) ($summary['current_step']['label'] ?? ''),
            'next_recommended_loop_step' => [
                'key' => (string) ($summary['current_step_key'] ?? ''),
                'label' => (string) ($summary['current_step']['label'] ?? ''),
                'next_action' => (string) (($summary['current_step']['next_action'] ?? '') ?: ($summary['next_action'] ?? '')),
            ],
            'blocked_steps' => $summary['blocked_steps'] ?? [],
            'weekly_review_status' => (string) ($summary['review']['review_status'] ?? 'not_started'),
            'last_review_outcome' => [
                'status' => (string) ($review['review_status'] ?? 'not_started'),
                'wins' => (string) ($review['wins'] ?? ''),
                'blockers' => (string) ($review['blockers'] ?? ''),
                'customer_conversations' => (int) ($review['customer_conversations'] ?? 0),
                'next_week_focus' => (string) ($review['next_week_focus'] ?? ''),
            ],
            'current_commitment' => $currentCommitment,
            'blocked_commitment' => $blockedCommitment,
            'startup_journey_completed_at' => (string) ($summary['startup_journey_completed_at'] ?? ''),
            'weekly_plan' => $summary['weekly_plan'] ?? [],
            'first_customer_signal' => $summary['first_customer_signal'] ?? [],
            'recommended_commitments' => array_slice($recommendedCommitments, 0, 3),
            'latest_commitments' => array_slice($commitments, 0, 5),
            'finance_snapshot' => $summary['finance_snapshot'] ?? [],
            'pricing_evidence' => $summary['pricing'] ?? [],
            'pricing_warnings' => $summary['pricing']['warnings'] ?? [],
            'financial_next_actions' => $this->financialNextActionsForAI((array) ($summary['finance_snapshot'] ?? []), (array) ($summary['pricing'] ?? [])),
            'next_action' => (string) ($summary['next_action'] ?? ''),
        ];
    }

    /**
     * @param list<array<string,mixed>> $commitments
     * @param list<array<string,mixed>> $recommendedCommitments
     * @return array<string,mixed>
     */
    private function currentCommitmentForAI(array $commitments, array $recommendedCommitments): array
    {
        foreach ($commitments as $commitment) {
            if (!in_array((string) ($commitment['status'] ?? 'pending'), ['completed', 'cancelled'], true)) {
                return $this->commitmentSummaryForAI($commitment, 'saved_commitment');
            }
        }
        foreach ($recommendedCommitments as $commitment) {
            return $this->commitmentSummaryForAI($commitment, 'recommended_commitment');
        }
        return [];
    }

    /**
     * @param list<array<string,mixed>> $commitments
     * @return array<string,mixed>
     */
    private function blockedCommitmentForAI(array $commitments): array
    {
        $today = date('Y-m-d');
        foreach ($commitments as $commitment) {
            $status = (string) ($commitment['status'] ?? 'pending');
            $dueDate = trim((string) ($commitment['due_date'] ?? ''));
            if (!in_array($status, ['completed', 'cancelled'], true) && $dueDate !== '' && $dueDate < $today) {
                return $this->commitmentSummaryForAI($commitment, 'overdue_commitment');
            }
        }
        return [];
    }

    /**
     * @param array<string,mixed> $commitment
     * @return array<string,mixed>
     */
    private function commitmentSummaryForAI(array $commitment, string $source): array
    {
        return [
            'id' => (int) ($commitment['id'] ?? 0),
            'task_id' => (int) ($commitment['task_id'] ?? 0),
            'title' => (string) ($commitment['title'] ?? ''),
            'description' => (string) ($commitment['description'] ?? ''),
            'due_date' => (string) ($commitment['due_date'] ?? ''),
            'status' => (string) ($commitment['status'] ?? 'pending'),
            'source' => $source,
        ];
    }

    private function startupJourneyCompletedAt(int $workspaceId, int $userId): ?string
    {
        try {
            return $this->journey->setupCompletedAt($workspaceId, $userId);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function buildWeeklyPlan(array $journey, array $metrics, array $pricing, array $legacySprint, string $weekStart, string $weekEnd): array
    {
        $customerDiscovery = $this->stageResponses($journey, 'customer_discovery');
        $valueProposition = $this->stageResponses($journey, 'value_proposition');
        $leanCanvas = $this->stageResponses($journey, 'lean_canvas');
        $mvp = $this->stageResponses($journey, 'mvp');
        $goToMarket = $this->stageResponses($journey, 'go_to_market');
        $okrs = $this->stageResponses($journey, 'okrs');

        $targetCustomer = $this->firstText([
            $customerDiscovery['target_customer'] ?? '',
            $leanCanvas['customer_segments'] ?? '',
            $goToMarket['beachhead_segment'] ?? '',
            $legacySprint['target_customer_segment'] ?? '',
        ], 'target customers');
        $offerPitch = $this->firstText([
            $leanCanvas['unique_value_proposition'] ?? '',
            $goToMarket['message'] ?? '',
            $valueProposition['pain_relievers'] ?? '',
            $mvp['mvp_hypothesis'] ?? '',
            $legacySprint['offer_pitch'] ?? '',
        ], 'the saved Clarity Journey offer');
        $channel = $this->firstText([
            $goToMarket['channels'] ?? '',
            $leanCanvas['channels'] ?? '',
            $legacySprint['outreach_channel'] ?? '',
        ], 'direct outreach');
        $objective = $this->firstText([
            $okrs['objective'] ?? '',
            $goToMarket['launch_plan'] ?? '',
            $mvp['smallest_test'] ?? '',
        ], 'turn Clarity Journey assumptions into first-customer execution');

        $paidCustomers = (int) ($metrics['paid_customer_count'] ?? 0);
        $openDeals = (int) ($metrics['open_deals'] ?? 0);
        $dealsOpened = (int) ($metrics['deals_opened'] ?? 0);
        $leadsCreated = (int) ($metrics['leads_created'] ?? 0);
        $pricingWarnings = (array) ($pricing['warnings'] ?? []);
        if ($paidCustomers > 0) {
            $focus = 'Expand repeatable first-customer motion';
        } elseif ($openDeals > 0 || $dealsOpened > 0) {
            $focus = 'Convert active pipeline into paid proof';
        } elseif ($leadsCreated > 0) {
            $focus = 'Qualify and follow up new leads';
        } elseif ($pricingWarnings !== []) {
            $focus = 'Validate pricing while starting outreach';
        } else {
            $focus = 'Create first customer conversations';
        }

        return [
            'week_start' => $weekStart,
            'week_end' => $weekEnd,
            'target_customer_segment' => $targetCustomer,
            'offer_pitch' => $offerPitch,
            'outreach_channel' => $channel,
            'objective' => $objective,
            'focus' => $focus,
            'weekly_outreach_target' => max(5, min(50, $leadsCreated + 10)),
            'demo_booking_target' => max(2, min(8, $openDeals + 2)),
            'paid_customer_target' => max(1, min(3, $paidCustomers + 1)),
            'source' => 'Clarity Journey and CRM signals',
        ];
    }

    private function buildFirstCustomerSignal(array $metrics, array $pricing): array
    {
        $paidCustomers = (int) ($metrics['paid_customer_count'] ?? 0);
        $dealsWon = (int) ($metrics['deals_won'] ?? 0);
        $openDeals = (int) ($metrics['open_deals'] ?? 0);
        $dealsOpened = (int) ($metrics['deals_opened'] ?? 0);
        $leadsCreated = (int) ($metrics['leads_created'] ?? 0);
        $openTasks = (int) ($metrics['founder_loop_open_tasks'] ?? 0);
        $completedTasks = (int) ($metrics['founder_loop_completed_tasks'] ?? 0);

        if ($paidCustomers > 0) {
            $gap = '';
            $headline = $paidCustomers . ' paid customer' . ($paidCustomers === 1 ? '' : 's') . ' this week';
        } elseif ($dealsWon > 0) {
            $gap = 'Won deals need paid invoice proof.';
            $headline = $dealsWon . ' won deal' . ($dealsWon === 1 ? '' : 's') . ' awaiting paid proof';
        } elseif ($openDeals > 0 || $dealsOpened > 0) {
            $gap = 'Active deals need follow-up, demo, pricing, or close actions.';
            $headline = max($openDeals, $dealsOpened) . ' active pipeline signal' . (max($openDeals, $dealsOpened) === 1 ? '' : 's');
        } elseif ($leadsCreated > 0) {
            $gap = 'New leads need qualification and a next CRM step.';
            $headline = $leadsCreated . ' lead' . ($leadsCreated === 1 ? '' : 's') . ' created this week';
        } else {
            $gap = 'No lead, deal, or paid-customer movement has been recorded this week.';
            $headline = 'No first-customer movement yet';
        }

        return [
            'headline' => $headline,
            'metric' => $paidCustomers . ' paid / ' . $dealsWon . ' won / ' . $openDeals . ' open deals',
            'strongest_gap' => $gap,
            'leads_created' => $leadsCreated,
            'open_deals' => $openDeals,
            'deals_opened' => $dealsOpened,
            'deals_won' => $dealsWon,
            'paid_customer_count' => $paidCustomers,
            'open_founder_tasks' => $openTasks,
            'completed_founder_tasks' => $completedTasks,
            'pricing_warning_count' => count((array) ($pricing['warnings'] ?? [])),
        ];
    }

    private function recommendedCommitments(array $weeklyPlan, array $firstCustomerSignal, array $pricing, string $weekStart, string $weekEnd): array
    {
        $targetCustomer = (string) ($weeklyPlan['target_customer_segment'] ?? 'target customers');
        $channel = (string) ($weeklyPlan['outreach_channel'] ?? 'direct outreach');
        $pitch = (string) ($weeklyPlan['offer_pitch'] ?? 'the current offer');
        $outreachTarget = (int) ($weeklyPlan['weekly_outreach_target'] ?? 10);
        $demoTarget = (int) ($weeklyPlan['demo_booking_target'] ?? 2);
        $paidTarget = (int) ($weeklyPlan['paid_customer_target'] ?? 1);
        $pricingWarnings = (array) ($pricing['warnings'] ?? []);
        $learningTitle = $pricingWarnings !== []
            ? 'Validate pricing with customer evidence'
            : 'Capture learning from first-customer conversations';
        $learningDescription = $pricingWarnings !== []
            ? 'Use customer conversations to test whether price, CAC, margin, or payback assumptions need adjustment.'
            : 'Record objections, buying triggers, and next-step evidence so next week is based on observed CRM signals.';

        return [
            [
                'title' => 'Create ' . $outreachTarget . ' first-customer outreach touches',
                'description' => 'Use ' . $channel . ' to reach ' . $targetCustomer . ' with this Clarity Journey-backed offer: ' . $pitch,
                'due_date' => $this->dateWithinWeek($weekStart, $weekEnd, 1),
                'status' => 'pending',
                'priority' => 'high',
                'subtasks' => ['Build or refresh the CRM list', 'Send the outreach batch', 'Log replies or non-responses in CRM'],
            ],
            [
                'title' => 'Move pipeline toward ' . $demoTarget . ' booked conversations',
                'description' => 'Follow up active leads and deals until there are booked conversations, demos, or clear disqualifications.',
                'due_date' => $this->dateWithinWeek($weekStart, $weekEnd, 3),
                'status' => 'pending',
                'priority' => 'high',
                'subtasks' => ['Review open deals and warm leads', 'Create follow-up tasks for every active opportunity', 'Update deal stages after each response'],
            ],
            [
                'title' => $paidTarget > 1 ? 'Push toward ' . $paidTarget . ' paid customers' : $learningTitle,
                'description' => $paidTarget > 1
                    ? 'Use the current pipeline to close paid proof, then record the paid invoice or won deal in CRM.'
                    : $learningDescription,
                'due_date' => $this->dateWithinWeek($weekStart, $weekEnd, 5),
                'status' => 'pending',
                'priority' => 'medium',
                'subtasks' => ['Review what moved and what stalled', 'Write the strongest customer signal', 'Choose the next adjustment'],
            ],
        ];
    }

    private function buildSteps(?string $startupJourneyCompletedAt, array $weeklyPlan, array $firstCustomerSignal, array $review, array $metrics): array
    {
        $hasJourney = $startupJourneyCompletedAt !== null;
        $hasWeeklyFocus = trim((string) ($weeklyPlan['focus'] ?? '')) !== '';
        $hasCommitments = (int) ($metrics['founder_loop_open_tasks'] ?? 0) > 0
            || (int) ($metrics['founder_loop_completed_tasks'] ?? 0) > 0;
        $hasPipelineMovement = (int) ($firstCustomerSignal['leads_created'] ?? 0) > 0
            || (int) ($firstCustomerSignal['deals_opened'] ?? 0) > 0
            || (int) ($firstCustomerSignal['deals_won'] ?? 0) > 0
            || (int) ($firstCustomerSignal['paid_customer_count'] ?? 0) > 0;
        $reviewComplete = (string) ($review['review_status'] ?? '') === 'completed';

        return [
            'review_signals' => [
                'order' => 1,
                'label' => 'Review Signals',
                'status' => $hasJourney ? 'ready' : 'blocked',
                'source' => 'Completed Clarity Journey, finance, and CRM signals',
                'gap' => $hasJourney ? '' : 'Clarity Journey must be completed before Founder Loop can run.',
                'next_action' => 'Review this week\'s CRM, pricing, runway, and first-customer signals.',
                'metric' => (string) ($firstCustomerSignal['headline'] ?? 'Signals ready'),
            ],
            'choose_weekly_focus' => [
                'order' => 2,
                'label' => 'Choose Weekly Focus',
                'status' => $hasWeeklyFocus ? 'ready' : 'needs_attention',
                'source' => 'Clarity Journey and strongest CRM gap',
                'gap' => $hasWeeklyFocus ? '' : 'No weekly focus could be derived yet.',
                'next_action' => 'Use the generated focus to decide what the founder should push this week.',
                'metric' => (string) ($weeklyPlan['focus'] ?? 'Needs focus'),
            ],
            'approve_commitments' => [
                'order' => 3,
                'label' => 'Approve Commitments',
                'status' => $hasCommitments ? 'ready' : 'needs_attention',
                'source' => 'Generated weekly commitments and CRM tasks',
                'gap' => $hasCommitments ? '' : 'No Founder Loop CRM tasks have been created yet.',
                'next_action' => 'Approve the generated commitments and create the CRM tasks for the week.',
                'metric' => (int) ($metrics['founder_loop_open_tasks'] ?? 0) . ' open tasks',
            ],
            'work_crm_pipeline' => [
                'order' => 4,
                'label' => 'Work CRM Pipeline',
                'status' => $hasPipelineMovement ? 'ready' : 'needs_attention',
                'source' => 'Contacts, deals, invoices, and founder-loop tasks',
                'gap' => $hasPipelineMovement ? '' : (string) ($firstCustomerSignal['strongest_gap'] ?? 'No first-customer movement has been recorded this week.'),
                'next_action' => 'Use CRM activity to move from outreach to conversations, deals, and paid customers.',
                'metric' => (string) ($firstCustomerSignal['metric'] ?? 'No movement'),
            ],
            'weekly_execution_review' => [
                'order' => 5,
                'label' => 'Complete Weekly Review',
                'status' => $reviewComplete ? 'ready' : (!empty($review['id']) ? 'needs_attention' : 'blocked'),
                'source' => 'Founder weekly review',
                'gap' => $reviewComplete ? '' : 'This week has not been completed yet.',
                'next_action' => 'Complete the weekly review after checking generated commitments against CRM outcomes.',
                'metric' => (string) ($review['review_status'] ?? 'not started'),
            ],
        ];
    }

    private function pricingReadiness(array $financeSummary, array $metrics, array $legacyBudget): array
    {
        $targetDealValue = (float) ($financeSummary['target_deal_value'] ?? $legacyBudget['target_deal_value'] ?? 0);
        $avgPaidInvoice = $financeSummary['avg_paid_invoice'] ?? null;
        $cac = $financeSummary['cac'] ?? null;
        $targetCac = $financeSummary['target_cac'] ?? $legacyBudget['target_cac'] ?? null;
        $margin = $financeSummary['gross_margin_percent'] ?? null;
        $breakEvenDeals = $financeSummary['break_even_deals'] ?? null;
        $warnings = [];

        if ($targetDealValue <= 0) {
            $warnings[] = 'Target deal value is missing.';
        }
        if ($avgPaidInvoice === null) {
            $warnings[] = 'No paid invoice data yet, so pricing is still unproven.';
        }
        if ($cac !== null && $targetCac !== null && (float) $targetCac > 0 && (float) $cac > (float) $targetCac) {
            $warnings[] = 'CAC is above the target CAC.';
        }
        if ($margin !== null && (float) $margin < 20) {
            $warnings[] = 'Gross or contribution margin is weak.';
        }
        if ($targetDealValue > 0 && $cac !== null && (float) $cac >= $targetDealValue) {
            $warnings[] = 'Price does not appear to cover CAC plus delivery pressure.';
        }
        if ($breakEvenDeals !== null && (int) $breakEvenDeals > max(3, (int) ($metrics['leads_created'] ?? 0) + (int) ($metrics['deals_opened'] ?? 0))) {
            $warnings[] = 'Break-even requires more deals than current first-customer activity supports.';
        }

        return [
            'headline' => $targetDealValue > 0 ? 'Target deal ' . number_format($targetDealValue, 2) : 'Target deal missing',
            'target_deal_value' => $targetDealValue,
            'average_paid_invoice' => $avgPaidInvoice,
            'cac' => $cac,
            'target_cac' => $targetCac,
            'payback_months' => $financeSummary['payback_months'] ?? null,
            'gross_margin_percent' => $margin,
            'contribution_margin' => $financeSummary['contribution_margin'] ?? null,
            'break_even_deals' => $breakEvenDeals,
            'warnings' => $warnings,
        ];
    }

    private function financeSnapshot(array $financeSummary): array
    {
        return [
            'period' => $financeSummary['period'] ?? [],
            'currency' => (string) ($financeSummary['currency'] ?? 'USD'),
            'money_in' => (float) ($financeSummary['money_in'] ?? 0),
            'money_out' => (float) ($financeSummary['money_out'] ?? 0),
            'net_cash_movement' => (float) ($financeSummary['net_cash_movement'] ?? 0),
            'burn_rate' => (float) ($financeSummary['burn_rate'] ?? 0),
            'runway_months' => array_key_exists('runway_months', $financeSummary) && $financeSummary['runway_months'] !== null ? (float) $financeSummary['runway_months'] : null,
            'cash_reserve' => (float) ($financeSummary['cash_reserve'] ?? 0),
            'cac' => array_key_exists('cac', $financeSummary) && $financeSummary['cac'] !== null ? (float) $financeSummary['cac'] : null,
            'target_cac' => array_key_exists('target_cac', $financeSummary) && $financeSummary['target_cac'] !== null ? (float) $financeSummary['target_cac'] : null,
            'gross_margin_percent' => array_key_exists('gross_margin_percent', $financeSummary) && $financeSummary['gross_margin_percent'] !== null ? (float) $financeSummary['gross_margin_percent'] : null,
            'break_even_deals' => array_key_exists('break_even_deals', $financeSummary) && $financeSummary['break_even_deals'] !== null ? (int) $financeSummary['break_even_deals'] : null,
            'avg_paid_invoice' => array_key_exists('avg_paid_invoice', $financeSummary) && $financeSummary['avg_paid_invoice'] !== null ? (float) $financeSummary['avg_paid_invoice'] : null,
            'paid_customer_count' => (int) ($financeSummary['paid_customer_count'] ?? 0),
        ];
    }

    /**
     * @param array<string,mixed> $financeSnapshot
     * @param array<string,mixed> $pricing
     * @return list<array<string,string>>
     */
    private function financialNextActionsForAI(array $financeSnapshot, array $pricing): array
    {
        $actions = [];
        $targetDealValue = (float) ($pricing['target_deal_value'] ?? 0);
        $avgPaidInvoice = array_key_exists('average_paid_invoice', $pricing) && $pricing['average_paid_invoice'] !== null
            ? (float) $pricing['average_paid_invoice']
            : null;
        $breakEvenDeals = array_key_exists('break_even_deals', $pricing) && $pricing['break_even_deals'] !== null ? (int) $pricing['break_even_deals'] : null;
        $runway = array_key_exists('runway_months', $financeSnapshot) && $financeSnapshot['runway_months'] !== null ? (float) $financeSnapshot['runway_months'] : null;
        $cac = array_key_exists('cac', $pricing) && $pricing['cac'] !== null ? (float) $pricing['cac'] : null;
        $targetCac = array_key_exists('target_cac', $pricing) && $pricing['target_cac'] !== null ? (float) $pricing['target_cac'] : null;
        $paidRevenue = (float) ($financeSnapshot['money_in'] ?? 0);
        $paidCustomers = (int) ($financeSnapshot['paid_customer_count'] ?? 0);

        if ($targetDealValue > 0 && $avgPaidInvoice !== null && abs($avgPaidInvoice - $targetDealValue) / max($targetDealValue, 1) >= 0.35) {
            $actions[] = [
                'type' => 'pricing_evidence',
                'label' => 'Review pricing evidence',
                'reason' => 'Average paid invoice differs from the target deal value.',
                'next_action' => 'Compare the latest paid invoice to the target deal value before approving this week\'s offer or follow-up.',
            ];
        } elseif ($avgPaidInvoice === null) {
            $actions[] = [
                'type' => 'pricing_evidence',
                'label' => 'Capture willingness-to-pay evidence',
                'reason' => 'No paid invoice data exists yet.',
                'next_action' => 'Run a customer interview or proposal follow-up that asks for a concrete paid next step.',
            ];
        }

        if ($breakEvenDeals !== null && $breakEvenDeals > 0) {
            $actions[] = [
                'type' => 'break_even',
                'label' => 'Check break-even deal count',
                'reason' => 'Break-even requires ' . $breakEvenDeals . ' deal' . ($breakEvenDeals === 1 ? '' : 's') . ' at the current target deal value.',
                'next_action' => 'Choose the named deal/contact most likely to move toward break-even this week.',
            ];
        }

        if ($runway !== null && $runway < 3) {
            $actions[] = [
                'type' => 'runway_warning',
                'label' => 'Protect runway',
                'reason' => 'Runway is below three months.',
                'next_action' => 'Make this week\'s commitment either revenue collection, paid proposal follow-up, or spend reduction.',
            ];
        }

        if ($cac !== null && $targetCac !== null && $targetCac > 0 && $cac > $targetCac) {
            $actions[] = [
                'type' => 'cac_payback_warning',
                'label' => 'Reduce CAC pressure',
                'reason' => 'CAC is above the target CAC.',
                'next_action' => 'Prioritize warm referrals, existing pipeline, or higher-value follow-up before spending more on acquisition.',
            ];
        }

        if ($paidRevenue > 0 || $paidCustomers > 0) {
            $actions[] = [
                'type' => 'paid_revenue_evidence',
                'label' => 'Turn paid evidence into learning',
                'reason' => 'Paid revenue or customers are already visible in finance.',
                'next_action' => 'Record what the paid customer bought and convert that learning into a Journey assumption update if needed.',
            ];
        }

        return array_slice($actions, 0, 5);
    }

    private function executionMetrics(int $workspaceId, int $userId, string $weekStart, string $weekEnd): array
    {
        $metrics = [
            'leads_created' => 0,
            'open_deals' => 0,
            'deals_opened' => 0,
            'deals_won' => 0,
            'paid_customer_count' => 0,
            'founder_loop_open_tasks' => 0,
            'founder_loop_completed_tasks' => 0,
        ];

        if (Database::tableExists('contacts')) {
            $metrics['leads_created'] = (int) (Database::queryOne(
                "SELECT COUNT(*) AS c FROM contacts WHERE workspace_id = ? AND created_at >= ? AND created_at < DATE_ADD(?, INTERVAL 1 DAY)",
                [$workspaceId, $weekStart, $weekEnd]
            )['c'] ?? 0);
        }
        if (Database::tableExists('deals')) {
            $metrics['open_deals'] = (int) (Database::queryOne(
                "SELECT COUNT(*) AS c FROM deals WHERE workspace_id = ? AND stage NOT IN ('closed_won', 'closed_lost')",
                [$workspaceId]
            )['c'] ?? 0);
            $metrics['deals_opened'] = (int) (Database::queryOne(
                "SELECT COUNT(*) AS c FROM deals WHERE workspace_id = ? AND created_at >= ? AND created_at < DATE_ADD(?, INTERVAL 1 DAY)",
                [$workspaceId, $weekStart, $weekEnd]
            )['c'] ?? 0);
            $metrics['deals_won'] = (int) (Database::queryOne(
                "SELECT COUNT(*) AS c FROM deals WHERE workspace_id = ? AND stage = 'closed_won' AND COALESCE(actual_close_date, updated_at, created_at) >= ? AND COALESCE(actual_close_date, updated_at, created_at) < DATE_ADD(?, INTERVAL 1 DAY)",
                [$workspaceId, $weekStart, $weekEnd]
            )['c'] ?? 0);
        }
        if (Database::tableExists('invoices')) {
            $metrics['paid_customer_count'] = (int) (Database::queryOne(
                "SELECT COUNT(DISTINCT contact_id) AS c
                 FROM invoices
                 WHERE workspace_id = ?
                   AND status IN ('paid','partially_paid')
                   AND document_type = 'invoice'
                   AND contact_id IS NOT NULL
                   AND COALESCE(paid_at, updated_at, issue_date, created_at) >= ?
                   AND COALESCE(paid_at, updated_at, issue_date, created_at) < DATE_ADD(?, INTERVAL 1 DAY)",
                [$workspaceId, $weekStart, $weekEnd]
            )['c'] ?? 0);
        }
        if (Database::tableExists('tasks') && Database::columnExists('tasks', 'metadata_json')) {
            $taskRow = Database::queryOne(
                "SELECT
                    SUM(CASE WHEN status IN ('completed') THEN 1 ELSE 0 END) AS completed_count,
                    SUM(CASE WHEN status NOT IN ('completed','cancelled') THEN 1 ELSE 0 END) AS open_count
                 FROM tasks
                 WHERE workspace_id = ?
                   AND JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.source_surface')) = 'founder_operating_loop'",
                [$workspaceId]
            ) ?: [];
            $metrics['founder_loop_open_tasks'] = (int) ($taskRow['open_count'] ?? 0);
            $metrics['founder_loop_completed_tasks'] = (int) ($taskRow['completed_count'] ?? 0);
        }

        return $metrics;
    }

    private function getSprint(int $workspaceId, int $userId): array
    {
        if (!Database::tableExists('founder_first_customer_sprints')) {
            return [];
        }
        return Database::queryOne(
            "SELECT * FROM founder_first_customer_sprints WHERE workspace_id = ? AND user_id <=> ? ORDER BY updated_at DESC LIMIT 1",
            [$workspaceId, $userId > 0 ? $userId : null]
        ) ?: [];
    }

    private function getWeeklyReview(int $workspaceId, int $userId, string $weekStart): array
    {
        if (!Database::tableExists('founder_weekly_reviews')) {
            return [];
        }
        $review = Database::queryOne(
            "SELECT * FROM founder_weekly_reviews WHERE workspace_id = ? AND user_id <=> ? AND week_start = ? LIMIT 1",
            [$workspaceId, $userId > 0 ? $userId : null, $weekStart]
        ) ?: [];
        $review['finance_snapshot'] = $this->decodeAssoc($review['finance_snapshot_json'] ?? null);
        return $review;
    }

    private function reviewCommitments(int $reviewId, int $workspaceId): array
    {
        if (!Database::tableExists('founder_weekly_review_commitments') || $reviewId <= 0) {
            return [];
        }
        return Database::query(
            "SELECT * FROM founder_weekly_review_commitments WHERE workspace_id = ? AND review_id = ? ORDER BY due_date IS NULL, due_date ASC, id ASC",
            [$workspaceId, $reviewId]
        );
    }

    private function replaceCommitments(int $reviewId, int $workspaceId, int $userId, array $data): void
    {
        Database::execute("DELETE FROM founder_weekly_review_commitments WHERE review_id = ? AND workspace_id = ?", [$reviewId, $workspaceId]);
        $titles = (array) ($data['commitment_title'] ?? []);
        $descriptions = (array) ($data['commitment_description'] ?? []);
        $dueDates = (array) ($data['commitment_due_date'] ?? []);
        $statuses = (array) ($data['commitment_status'] ?? []);
        foreach ($titles as $index => $title) {
            $cleanTitle = $this->shortText($title, 255);
            if ($cleanTitle === '') {
                continue;
            }
            Database::execute(
                "INSERT INTO founder_weekly_review_commitments (review_id, workspace_id, user_id, title, description, due_date, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                [
                    $reviewId,
                    $workspaceId,
                    $userId > 0 ? $userId : null,
                    $cleanTitle,
                    $this->longText($descriptions[$index] ?? '', 2000),
                    $this->normalizeDate($dueDates[$index] ?? ''),
                    $this->normalizeCommitmentStatus($statuses[$index] ?? 'pending'),
                ]
            );
        }
    }

    private function createTasksForReviewCommitments(int $reviewId, int $workspaceId, int $userId): void
    {
        if (!Database::tableExists('founder_weekly_review_commitments')) {
            return;
        }
        $commitments = $this->reviewCommitments($reviewId, $workspaceId);
        $taskModule = new Tasks();
        foreach ($commitments as $commitment) {
            $title = $this->shortText($commitment['title'] ?? '', 255);
            if ($title === '' || in_array((string) ($commitment['status'] ?? 'pending'), ['completed', 'cancelled'], true)) {
                continue;
            }
            if (!empty($commitment['task_id'])) {
                continue;
            }

            $taskId = $this->founderLoopTaskIdByTitle($workspaceId, $title);
            if ($taskId <= 0) {
                $taskId = $taskModule->createWithSubtasks([
                    'title' => $title,
                    'description' => $this->longText($commitment['description'] ?? '', 2000),
                    'assigned_to' => $userId > 0 ? $userId : null,
                    'created_by' => $userId > 0 ? $userId : null,
                    'actor_user_id' => $userId > 0 ? $userId : null,
                    'status' => 'pending',
                    'priority' => 'high',
                    'due_date' => $this->normalizeDate($commitment['due_date'] ?? ''),
                    'metadata_json' => [
                        'source_surface' => 'founder_operating_loop',
                        'founder_loop_task' => true,
                        'founder_loop_step' => 'weekly_commitments',
                        'generated_from' => 'weekly_review_commitment',
                        'founder_weekly_review_id' => $reviewId,
                    ],
                ], []);
            }

            if ($taskId > 0) {
                Database::execute(
                    "UPDATE founder_weekly_review_commitments SET task_id = ?, updated_at = NOW() WHERE id = ? AND workspace_id = ?",
                    [$taskId, (int) ($commitment['id'] ?? 0), $workspaceId]
                );
            }
        }
    }

    private function founderLoopTaskIdByTitle(int $workspaceId, string $title): int
    {
        if (!Database::tableExists('tasks') || !Database::columnExists('tasks', 'metadata_json')) {
            return 0;
        }
        $row = Database::queryOne(
            "SELECT id
             FROM tasks
             WHERE workspace_id = ?
               AND title = ?
               AND status NOT IN ('completed', 'cancelled')
               AND JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.source_surface')) = 'founder_operating_loop'
             ORDER BY id DESC
             LIMIT 1",
            [$workspaceId, $title]
        );
        return (int) ($row['id'] ?? 0);
    }

    private function founderLoopTaskExists(int $workspaceId, string $title): bool
    {
        return $this->founderLoopTaskIdByTitle($workspaceId, $title) > 0;
    }

    private function currentStepKey(array $steps): string
    {
        foreach ($steps as $key => $step) {
            if (($step['status'] ?? '') !== 'ready') {
                return (string) $key;
            }
        }
        return 'weekly_execution_review';
    }

    private function safeCompanyProfile(): array
    {
        try {
            return (new CompanyProfile())->get() ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function safeJourney(int $workspaceId, int $userId): array
    {
        try {
            return $this->journey->getJourney($workspaceId, $userId);
        } catch (\Throwable $e) {
            return ['progress' => ['completed_stages' => 0, 'total_stages' => 8]];
        }
    }

    private function companyReady(array $company): bool
    {
        return trim((string) ($company['company_name'] ?? '')) !== ''
            && (
                trim((string) ($company['company_description'] ?? '')) !== ''
                || trim((string) ($company['business_model'] ?? '')) !== ''
                || trim((string) ($company['target_market'] ?? '')) !== ''
            );
    }

    private function weekRange(?string $weekStart): array
    {
        $base = trim((string) $weekStart) !== '' ? new \DateTimeImmutable((string) $weekStart) : new \DateTimeImmutable('today');
        $start = $base->modify('monday this week');
        $end = $start->modify('+6 days');
        return [$start->format('Y-m-d'), $end->format('Y-m-d')];
    }

    private function stageResponses(array $journey, string $stageKey): array
    {
        return (array) ($journey['stages'][$stageKey]['responses'] ?? []);
    }

    private function firstText(array $values, string $fallback): string
    {
        foreach ($values as $value) {
            $text = trim(preg_replace('/\s+/', ' ', (string) $value) ?? (string) $value);
            if ($text !== '') {
                return mb_substr($text, 0, 255);
            }
        }
        return $fallback;
    }

    private function dateWithinWeek(string $weekStart, string $weekEnd, int $offsetDays): string
    {
        $start = new \DateTimeImmutable($weekStart);
        $end = new \DateTimeImmutable($weekEnd);
        $candidate = $start->modify('+' . max(0, $offsetDays) . ' days');
        if ($candidate > $end) {
            return $end->format('Y-m-d');
        }
        return $candidate->format('Y-m-d');
    }

    private function shortText(mixed $value, int $max): string
    {
        return mb_substr(trim((string) $value), 0, $max);
    }

    private function longText(mixed $value, int $max): string
    {
        return mb_substr(trim((string) $value), 0, $max);
    }

    private function nonNegativeInt(mixed $value): int
    {
        return max(0, min((int) $value, 1000000));
    }

    private function normalizeDate(mixed $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }
        $timestamp = strtotime($raw);
        return $timestamp ? date('Y-m-d', $timestamp) : null;
    }

    private function normalizeCommitmentStatus(mixed $value): string
    {
        $status = trim((string) $value);
        return in_array($status, ['pending', 'in_progress', 'completed', 'cancelled'], true) ? $status : 'pending';
    }

    private function decodeAssoc(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }
}
