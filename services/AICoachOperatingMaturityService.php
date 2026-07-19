<?php

namespace CRM\Services;

use CRM\Database;

class AICoachOperatingMaturityService
{
    public const PRE_CLARITY_JOURNEY = 'pre_clarity_journey';
    public const JOURNEY_COMPLETE_FOUNDER_LOOP_NEXT = 'journey_complete_founder_loop_next';
    public const FOUNDER_LOOP_ACTIVE = 'founder_loop_active';
    public const OPERATING_SYSTEM_ACTIVE = 'operating_system_active';

    /**
     * @param array<string,mixed> $operatingContext
     * @param array<string,mixed> $journeyReadiness
     * @return array<string,mixed>
     */
    public function determine(int $workspaceId, int $userId, array $operatingContext = [], array $journeyReadiness = []): array
    {
        $workspaceId = max(0, $workspaceId);
        $userId = max(0, $userId);
        $journeyComplete = $this->journeyComplete($workspaceId, $userId, $operatingContext, $journeyReadiness);
        $founderLoop = $this->founderLoopContext($workspaceId, $userId, $operatingContext);
        $founderLoopActive = $this->founderLoopActive($founderLoop);
        $activity = $this->laterStageActivity($workspaceId, $userId, $operatingContext, $founderLoop);
        $operatingSystemActive = $founderLoopActive && !empty($activity['has_later_stage_activity']);

        if (!$journeyComplete) {
            $stage = self::PRE_CLARITY_JOURNEY;
        } elseif ($operatingSystemActive) {
            $stage = self::OPERATING_SYSTEM_ACTIVE;
        } elseif ($founderLoopActive) {
            $stage = self::FOUNDER_LOOP_ACTIVE;
        } else {
            $stage = self::JOURNEY_COMPLETE_FOUNDER_LOOP_NEXT;
        }

        return [
            'stage' => $stage,
            'label' => $this->label($stage),
            'journey_complete' => $journeyComplete,
            'founder_loop_active' => $founderLoopActive,
            'operating_system_active' => $operatingSystemActive,
            'later_stage_activity' => $activity,
            'next_action' => $this->nextAction($stage, $operatingContext, $founderLoop),
        ];
    }

    /**
     * @param array<string,mixed> $operatingContext
     * @param array<string,mixed> $journeyReadiness
     */
    private function journeyComplete(int $workspaceId, int $userId, array $operatingContext, array $journeyReadiness): bool
    {
        if (array_key_exists('ready', $journeyReadiness)) {
            return !empty($journeyReadiness['ready']);
        }

        $journey = (array) ($operatingContext['startup_journey_context'] ?? []);
        $progress = (array) ($journey['progress'] ?? []);
        $total = (int) ($progress['total'] ?? 0);
        $completed = (int) ($progress['completed'] ?? 0);
        if ($total > 0) {
            return $completed >= $total;
        }

        if ($workspaceId > 0 && $userId > 0 && Database::tableExists('startup_journeys')) {
            try {
                return !empty((new StartupJourneyService())->readiness($workspaceId, $userId)['ready']);
            } catch (\Throwable $e) {
                return false;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed> $operatingContext
     * @return array<string,mixed>
     */
    private function founderLoopContext(int $workspaceId, int $userId, array $operatingContext): array
    {
        $founderLoop = (array) ($operatingContext['founder_operating_loop_context'] ?? []);
        if ($founderLoop !== []) {
            return $founderLoop;
        }

        if ($workspaceId <= 0 || $userId <= 0 || !Database::tableExists('founder_weekly_reviews')) {
            return [];
        }

        try {
            return (new FounderOperatingLoopService())->contextForAI($workspaceId, $userId);
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * @param array<string,mixed> $founderLoop
     */
    private function founderLoopActive(array $founderLoop): bool
    {
        if (!empty($founderLoop['latest_commitments'])) {
            return true;
        }
        if (!empty($founderLoop['active_week']['has_saved_review'])) {
            return true;
        }
        if (!in_array((string) ($founderLoop['weekly_review_status'] ?? 'not_started'), ['', 'not_started'], true)) {
            return true;
        }

        $signal = (array) ($founderLoop['first_customer_signal'] ?? []);
        return (int) ($signal['open_founder_tasks'] ?? 0) > 0
            || (int) ($signal['completed_founder_tasks'] ?? 0) > 0;
    }

    /**
     * @param array<string,mixed> $operatingContext
     * @param array<string,mixed> $founderLoop
     * @return array<string,mixed>
     */
    private function laterStageActivity(int $workspaceId, int $userId, array $operatingContext, array $founderLoop): array
    {
        $signals = [];
        $counts = [];

        $pipeline = (array) ($operatingContext['pipeline_state'] ?? []);
        $this->addSignal($signals, $counts, 'open_deals', (int) ($pipeline['open_deals'] ?? $this->countOpenDeals($workspaceId)));
        $this->addSignal($signals, $counts, 'proposal_or_negotiation_deals', (int) ($pipeline['proposal_stage_deals'] ?? 0) + (int) ($pipeline['negotiation_stage_deals'] ?? 0));

        $tasks = (array) ($operatingContext['task_state'] ?? []);
        $this->addSignal($signals, $counts, 'open_tasks', (int) ($tasks['open_tasks'] ?? $this->countOpenTasks($workspaceId, $userId)));

        $inbox = (array) ($operatingContext['inbox_state'] ?? []);
        $this->addSignal($signals, $counts, 'inbox_threads', (int) ($inbox['open_threads'] ?? 0) + (int) ($inbox['unread_priority_items'] ?? 0));

        $finance = (array) ($operatingContext['finance_context'] ?? []);
        $moneyIn = (float) ($finance['money_in'] ?? 0);
        $moneyOut = (float) ($finance['money_out'] ?? 0);
        if ($moneyIn > 0 || $moneyOut > 0 || $this->countFinanceTransactions($workspaceId) > 0) {
            $signals[] = 'finance_movement';
            $counts['finance_movement'] = round($moneyIn + $moneyOut, 2);
        }

        $firstCustomer = (array) ($founderLoop['first_customer_signal'] ?? []);
        $this->addSignal($signals, $counts, 'founder_loop_tasks', (int) ($firstCustomer['open_founder_tasks'] ?? 0) + (int) ($firstCustomer['completed_founder_tasks'] ?? 0));
        $this->addSignal($signals, $counts, 'first_customer_pipeline', (int) ($firstCustomer['leads_created'] ?? 0) + (int) ($firstCustomer['open_deals'] ?? 0) + (int) ($firstCustomer['deals_won'] ?? 0));

        $contactCount = $this->countContacts($workspaceId);
        $this->addSignal($signals, $counts, 'contacts', $contactCount);

        return [
            'has_later_stage_activity' => count(array_unique($signals)) >= 2 || in_array('finance_movement', $signals, true),
            'signals' => array_values(array_unique($signals)),
            'counts' => $counts,
        ];
    }

    /**
     * @param list<string> $signals
     * @param array<string,int|float> $counts
     */
    private function addSignal(array &$signals, array &$counts, string $key, int $count): void
    {
        $counts[$key] = $count;
        if ($count > 0) {
            $signals[] = $key;
        }
    }

    private function countContacts(int $workspaceId): int
    {
        if ($workspaceId <= 0 || !Database::tableExists('contacts')) {
            return 0;
        }
        return (int) (Database::queryOne("SELECT COUNT(*) AS c FROM contacts WHERE workspace_id = ?", [$workspaceId])['c'] ?? 0);
    }

    private function countOpenDeals(int $workspaceId): int
    {
        if ($workspaceId <= 0 || !Database::tableExists('deals')) {
            return 0;
        }
        return (int) (Database::queryOne("SELECT COUNT(*) AS c FROM deals WHERE workspace_id = ? AND stage NOT IN ('closed_won', 'closed_lost')", [$workspaceId])['c'] ?? 0);
    }

    private function countOpenTasks(int $workspaceId, int $userId): int
    {
        if ($workspaceId <= 0 || !Database::tableExists('tasks')) {
            return 0;
        }
        return (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM tasks
             WHERE workspace_id = ?
               AND (? <= 0 OR assigned_to = ? OR created_by = ?)
               AND status NOT IN ('completed', 'cancelled')",
            [$workspaceId, $userId, $userId, $userId]
        )['c'] ?? 0);
    }

    private function countFinanceTransactions(int $workspaceId): int
    {
        if ($workspaceId <= 0 || !Database::tableExists('finance_transactions')) {
            return 0;
        }
        return (int) (Database::queryOne("SELECT COUNT(*) AS c FROM finance_transactions WHERE workspace_id = ?", [$workspaceId])['c'] ?? 0);
    }

    /**
     * @param array<string,mixed> $operatingContext
     * @param array<string,mixed> $founderLoop
     */
    private function nextAction(string $stage, array $operatingContext, array $founderLoop): string
    {
        if ($stage === self::PRE_CLARITY_JOURNEY) {
            $journey = (array) ($operatingContext['startup_journey_context'] ?? []);
            $current = (string) ($journey['current_stage_key'] ?? '');
            return $current !== ''
                ? 'Complete the ' . str_replace('_', ' ', $current) . ' stage in Clarity Journey.'
                : 'Complete the next Clarity Journey stage.';
        }
        if ($stage === self::JOURNEY_COMPLETE_FOUNDER_LOOP_NEXT) {
            return 'Start Founder Loop and create first-deal commitments.';
        }
        if ($stage === self::FOUNDER_LOOP_ACTIVE) {
            return (string) (($founderLoop['next_action'] ?? '') ?: 'Work the current Founder Loop commitment.');
        }
        return 'Compare operating evidence against Journey assumptions and resolve the sharpest conflict.';
    }

    private function label(string $stage): string
    {
        return match ($stage) {
            self::PRE_CLARITY_JOURNEY => 'Before Clarity Journey',
            self::JOURNEY_COMPLETE_FOUNDER_LOOP_NEXT => 'Journey complete, Founder Loop next',
            self::FOUNDER_LOOP_ACTIVE => 'Founder Loop active',
            self::OPERATING_SYSTEM_ACTIVE => 'Operating system active',
            default => 'Unknown',
        };
    }
}
