<?php
/**
 * Outcome Metrics Module
 */

namespace CRM\Modules;

use CRM\Database;

class OutcomeMetrics
{
    public function getTTFVSummary(int $days = 14): array
    {
        $days = max(1, min(90, $days));
        if (!Database::tableExists('activation_progress')) {
            return [
                'name' => 'ttfv_followup_hours',
                'median_hours' => 0.0,
                'p75_hours' => 0.0,
                'sample_size' => 0,
                'trend_vs_prev_pct' => 0.0,
            ];
        }

        $rows = Database::query(
            "SELECT first_login_at, first_followup_task_completed_at
             FROM activation_progress
             WHERE first_login_at IS NOT NULL
               AND first_followup_task_completed_at IS NOT NULL
               AND first_login_at >= DATE_SUB(NOW(), INTERVAL ? DAY)",
            [$days]
        );

        $hours = [];
        foreach ($rows as $row) {
            $start = strtotime((string) ($row['first_login_at'] ?? ''));
            $end = strtotime((string) ($row['first_followup_task_completed_at'] ?? ''));
            if ($start && $end && $end >= $start) {
                $hours[] = ($end - $start) / 3600;
            }
        }

        sort($hours);
        $median = $this->percentile($hours, 50);
        $p75 = $this->percentile($hours, 75);

        $currentMedian = $this->medianForWindow($days, 0);
        $previousMedian = $this->medianForWindow($days, $days);
        $trend = 0.0;
        if ($previousMedian > 0) {
            $trend = (($currentMedian - $previousMedian) / $previousMedian) * 100;
        }

        return [
            'name' => 'ttfv_followup_hours',
            'median_hours' => round($median, 2),
            'p75_hours' => round($p75, 2),
            'sample_size' => count($hours),
            'trend_vs_prev_pct' => round($trend, 1),
        ];
    }

    public function getActivationRateSummary(int $days = 7): array
    {
        $days = max(1, min(60, $days));
        if (!Database::tableExists('activation_progress')) {
            return ['window_days' => $days, 'rate' => 0.0, 'activated' => 0, 'eligible' => 0];
        }

        $rows = Database::query(
            "SELECT *
             FROM activation_progress
             WHERE first_login_at IS NOT NULL
               AND first_login_at >= DATE_SUB(NOW(), INTERVAL ? DAY)",
            [$days]
        );

        $eligible = count($rows);
        $activated = 0;
        $milestones = [
            'connected_channel_at',
            'first_contact_at',
            'first_inbound_at',
            'first_followup_task_completed_at',
            'first_deal_created_at',
            'first_deal_advanced_at',
        ];
        foreach ($rows as $row) {
            $firstLogin = strtotime((string) ($row['first_login_at'] ?? ''));
            if (!$firstLogin) {
                continue;
            }
            $windowEnd = strtotime('+7 days', $firstLogin);
            $count = 0;
            foreach ($milestones as $column) {
                $value = (string) ($row[$column] ?? '');
                if ($value === '') {
                    continue;
                }
                $ts = strtotime($value);
                if ($ts && $ts <= $windowEnd) {
                    $count++;
                }
            }
            if ($count >= 4) {
                $activated++;
            }
        }

        $rate = $eligible > 0 ? ($activated / $eligible) * 100 : 0.0;
        return [
            'window_days' => $days,
            'rate' => round($rate, 1),
            'activated' => $activated,
            'eligible' => $eligible,
        ];
    }

    public function getRevenueActionRateSummary(int $days = 7): array
    {
        $days = max(1, min(60, $days));
        if (!Database::tableExists('outcome_events')) {
            return ['window_days' => $days, 'rate' => 0.0, 'active_users' => 0, 'revenue_users' => 0];
        }

        $activeUsers = (int) ((Database::queryOne(
            "SELECT COUNT(DISTINCT user_id) AS c
             FROM outcome_events
             WHERE user_id IS NOT NULL
               AND event_at >= DATE_SUB(NOW(), INTERVAL ? DAY)",
            [$days]
        )['c'] ?? 0));

        $revenueUsers = (int) ((Database::queryOne(
            "SELECT COUNT(DISTINCT user_id) AS c
             FROM outcome_events
             WHERE user_id IS NOT NULL
               AND event_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
               AND event_key IN ('task.followup.completed','deal.created','deal.advanced')",
            [$days]
        )['c'] ?? 0));

        $rate = $activeUsers > 0 ? ($revenueUsers / $activeUsers) * 100 : 0.0;
        return [
            'window_days' => $days,
            'rate' => round($rate, 1),
            'active_users' => $activeUsers,
            'revenue_users' => $revenueUsers,
        ];
    }

    public function getTodayRevenueFocus(int $userId): array
    {
        $focus = [];
        $userScopeSql = '';
        $userScopeParams = [];
        if ($userId > 0) {
            $userScopeSql = ' AND (assigned_to = ? OR created_by = ?)';
            $userScopeParams = [$userId, $userId];
        }

        $openDeals = Database::queryOne(
            "SELECT COUNT(*) AS c, COALESCE(SUM(value), 0) AS v
             FROM deals
             WHERE stage NOT IN ('closed_won','closed_lost')" . $userScopeSql,
            $userScopeParams
        );
        $staleDeals = Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM deals
             WHERE stage NOT IN ('closed_won','closed_lost')
               AND updated_at < DATE_SUB(NOW(), INTERVAL 7 DAY)" . $userScopeSql,
            $userScopeParams
        );
        $dueTodayTasks = Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM tasks
             WHERE status NOT IN ('completed','cancelled')
               AND DATE(due_date) = CURDATE()
               AND (assigned_to = ? OR created_by = ?)",
            [$userId, $userId]
        );

        $openCount = (int) ($openDeals['c'] ?? 0);
        $openValue = (float) ($openDeals['v'] ?? 0.0);
        $staleCount = (int) ($staleDeals['c'] ?? 0);
        $dueCount = (int) ($dueTodayTasks['c'] ?? 0);

        if ($staleCount > 0) {
            $focus[] = 'Follow up ' . $staleCount . ' customer' . ($staleCount === 1 ? '' : 's') . ' whose deal has gone quiet.';
        }
        if ($dueCount > 0) {
            $focus[] = 'Finish ' . $dueCount . ' task' . ($dueCount === 1 ? '' : 's') . ' due today.';
        }
        if ($openCount > 0) {
            $focus[] = 'Check ' . $openCount . ' open deal' . ($openCount === 1 ? '' : 's') . ' worth about ' . $this->formatApproximateMoney($openValue) . '.';
        }
        if (empty($focus)) {
            $focus[] = 'Add or import 5 customers or leads today.';
        }

        return array_slice($focus, 0, 3);
    }

    private function formatApproximateMoney(float $amount): string
    {
        $currency = (new Currencies())->getDefault();
        if (!$currency) {
            return '$' . number_format($amount, 0);
        }

        $formatted = number_format(
            $amount,
            0,
            (string) ($currency['decimal_separator'] ?? '.'),
            (string) ($currency['thousands_separator'] ?? ',')
        );
        $symbol = (string) ($currency['symbol'] ?? '');

        if (($currency['symbol_position'] ?? 'before') === 'before') {
            return $symbol . $formatted;
        }

        return $symbol === '' ? $formatted : $formatted . ' ' . $symbol;
    }

    public function getRevenueMomentumChecklist(int $userId): array
    {
        $userId = max(0, $userId);

        $taskScopeSql = '';
        $taskScopeParams = [];
        $dealScopeSql = '';
        $dealScopeParams = [];
        $contactScopeSql = '';
        $contactScopeParams = [];
        if ($userId > 0) {
            $taskScopeSql = ' AND (assigned_to = ? OR created_by = ?)';
            $taskScopeParams = [$userId, $userId];
            $dealScopeSql = ' AND (assigned_to = ? OR created_by = ?)';
            $dealScopeParams = [$userId, $userId];
            $contactScopeSql = ' AND assigned_to = ?';
            $contactScopeParams = [$userId];
        }

        $dueFollowups = 0;
        if (Database::tableExists('tasks')) {
            $dueFollowups = (int) ((Database::queryOne(
                "SELECT COUNT(*) AS c
                 FROM tasks
                 WHERE status NOT IN ('completed','cancelled')
                   AND due_date IS NOT NULL
                   AND DATE(due_date) <= CURDATE()" . $taskScopeSql,
                $taskScopeParams
            )['c'] ?? 0));
        }

        $staleDeals = 0;
        $openDeals = 0;
        $recentDealMovement = 0;
        if (Database::tableExists('deals')) {
            $staleDeals = (int) ((Database::queryOne(
                "SELECT COUNT(*) AS c
                 FROM deals
                 WHERE stage NOT IN ('closed_won','closed_lost')
                   AND updated_at < DATE_SUB(NOW(), INTERVAL 7 DAY)" . $dealScopeSql,
                $dealScopeParams
            )['c'] ?? 0));
            $openDeals = (int) ((Database::queryOne(
                "SELECT COUNT(*) AS c
                 FROM deals
                 WHERE stage NOT IN ('closed_won','closed_lost')" . $dealScopeSql,
                $dealScopeParams
            )['c'] ?? 0));
            $recentDealMovement = (int) ((Database::queryOne(
                "SELECT COUNT(*) AS c
                 FROM deals
                 WHERE stage NOT IN ('closed_won','closed_lost')
                   AND updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)" . $dealScopeSql,
                $dealScopeParams
            )['c'] ?? 0));
        }

        $leadsToday = 0;
        if (Database::tableExists('contacts')) {
            $leadsToday = (int) ((Database::queryOne(
                "SELECT COUNT(*) AS c
                 FROM contacts
                 WHERE created_at >= CURDATE()" . $contactScopeSql,
                $contactScopeParams
            )['c'] ?? 0));
        }

        $revenueActions = 0;
        if (Database::tableExists('outcome_events')) {
            $eventScopeSql = $userId > 0 ? ' AND user_id = ?' : '';
            $eventScopeParams = $userId > 0 ? [$userId] : [];
            $revenueActions = (int) ((Database::queryOne(
                "SELECT COUNT(*) AS c
                 FROM outcome_events
                 WHERE event_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                   AND event_key IN ('task.followup.completed','deal.created','deal.advanced')" . $eventScopeSql,
                $eventScopeParams
            )['c'] ?? 0));
        }

        return [
            $this->momentumStep($dueFollowups === 0, 'followups_clear', 'due_followups', $dueFollowups),
            $this->momentumStep($staleDeals === 0, 'no_stale_deals', 'stale_deals', $staleDeals),
            $this->momentumStep($openDeals > 0, 'pipeline_active', 'refill_pipeline', $openDeals),
            $this->momentumStep($leadsToday >= 5, 'lead_refill', 'add_leads', $leadsToday),
            $this->momentumStep($recentDealMovement > 0, 'deal_movement', 'move_deal', $recentDealMovement),
            $this->momentumStep($revenueActions > 0, 'revenue_action', 'action_gap', $revenueActions),
        ];
    }

    public function getChecklist(int $userId): array
    {
        $row = null;
        if ($userId > 0 && Database::tableExists('activation_progress')) {
            $row = Database::queryOne("SELECT * FROM activation_progress WHERE user_id = ?", [$userId]);
        }
        $row = is_array($row) ? $row : [];

        $steps = [
            ['step_key' => 'first_login', 'column' => 'first_login_at'],
            ['step_key' => 'connect_channel', 'column' => 'connected_channel_at'],
            ['step_key' => 'add_contact', 'column' => 'first_contact_at'],
            ['step_key' => 'first_inbound', 'column' => 'first_inbound_at'],
            ['step_key' => 'first_followup', 'column' => 'first_followup_task_completed_at'],
            ['step_key' => 'first_deal', 'column' => 'first_deal_created_at'],
        ];

        $out = [];
        foreach ($steps as $step) {
            $value = $row[$step['column']] ?? null;
            $out[] = [
                'step_key' => $step['step_key'],
                'complete' => !empty($value),
                'completed_at' => $value ?: null,
            ];
        }
        return $out;
    }

    private function momentumStep(bool $complete, string $completeKey, string $actionKey, int $count): array
    {
        return [
            'step_key' => $complete ? $completeKey : $actionKey,
            'complete' => $complete,
            'count' => $count,
        ];
    }

    private function percentile(array $sortedValues, int $percent): float
    {
        $count = count($sortedValues);
        if ($count === 0) {
            return 0.0;
        }
        if ($count === 1) {
            return (float) $sortedValues[0];
        }

        $rank = ($percent / 100) * ($count - 1);
        $low = (int) floor($rank);
        $high = (int) ceil($rank);
        if ($low === $high) {
            return (float) $sortedValues[$low];
        }
        $weight = $rank - $low;
        return ((1 - $weight) * (float) $sortedValues[$low]) + ($weight * (float) $sortedValues[$high]);
    }

    private function medianForWindow(int $days, int $offsetDays): float
    {
        if (!Database::tableExists('activation_progress')) {
            return 0.0;
        }
        $rows = Database::query(
            "SELECT first_login_at, first_followup_task_completed_at
             FROM activation_progress
             WHERE first_login_at IS NOT NULL
               AND first_followup_task_completed_at IS NOT NULL
               AND first_login_at >= DATE_SUB(DATE_SUB(NOW(), INTERVAL ? DAY), INTERVAL ? DAY)
               AND first_login_at < DATE_SUB(NOW(), INTERVAL ? DAY)",
            [$days, $offsetDays, $offsetDays]
        );
        $hours = [];
        foreach ($rows as $row) {
            $start = strtotime((string) ($row['first_login_at'] ?? ''));
            $end = strtotime((string) ($row['first_followup_task_completed_at'] ?? ''));
            if ($start && $end && $end >= $start) {
                $hours[] = ($end - $start) / 3600;
            }
        }
        sort($hours);
        return $this->percentile($hours, 50);
    }

}
