<?php
/**
 * Chat Welcome Service
 * Builds personalized greeting and pending items summary for the assistant chatbot.
 */

namespace CRM\Modules;

use CRM\Database;
use CRM\Auth;
use CRM\Modules\OutcomeMetrics;
use CRM\Services\OutcomeRolloutService;

class ChatWelcomeService
{
    /**
     * Get welcome data for the current user
     *
     * @param int $userId
     * @return array
     */
    public function getWelcomeData(int $userId): array
    {
        $user = Auth::user();
        $email = $user['email'] ?? '';
        $firstName = $user['first_name'] ?? '';
        $lastName = $user['last_name'] ?? '';

        $data = [
            'display_name' => $this->getDisplayName($firstName, $lastName, $email),
            'overdue_tasks' => [],
            'today_tasks' => [],
            'ai_starter_tasks' => [],
            'unread_notifications' => 0,
            'today_events' => [],
            'revenue_focus' => [],
            'ai_tasks_created' => 0,
            'ai_mode' => '2',
        ];

        try {
            $automation = new AITaskAutomationService();
            $sync = $automation->autoSeedDailyTasks($userId);
            $data['ai_tasks_created'] = (int) ($sync['created_count'] ?? 0);
            $data['ai_mode'] = (string) ($sync['mode'] ?? '2');

            $tasks = new Tasks();
            $overdueTasks = $tasks->getAll(
                ['overdue' => true, 'assigned_to' => $userId],
                5,
                0
            );
            $data['overdue_tasks'] = array_map(function ($t) {
                return ['title' => $t['title'] ?? '', 'id' => $t['id'] ?? null];
            }, $overdueTasks);

            $todayTasks = $tasks->getAll(
                ['due_today' => true, 'assigned_to' => $userId],
                5,
                0
            );
            $data['today_tasks'] = array_map(function ($t) {
                return ['title' => $t['title'] ?? '', 'id' => $t['id'] ?? null];
            }, $todayTasks);

            $aiStarterTasks = Database::query(
                "SELECT id, title
                 FROM tasks
                 WHERE assigned_to = ?
                   AND status IN ('pending', 'in_progress')
                   AND JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.source_surface')) = 'ai_coach'
                 ORDER BY created_at DESC
                 LIMIT 5",
                [$userId]
            );
            $data['ai_starter_tasks'] = array_map(function ($t) {
                return ['title' => $t['title'] ?? '', 'id' => $t['id'] ?? null];
            }, $aiStarterTasks);

            $notifications = new Notifications();
            $data['unread_notifications'] = $notifications->getUnreadCount($userId);

            $today = date('Y-m-d');
            $events = new Events();
            $todayEvents = $events->getByDateRange($today, $today, ['assigned_to' => $userId]);
            $data['today_events'] = array_slice(
                array_map(function ($e) {
                    return [
                        'title' => $e['title'] ?? '',
                        'time' => isset($e['start_time']) ? date('g:i A', strtotime($e['start_time'])) : '',
                        'id' => $e['id'] ?? null,
                    ];
                }, $todayEvents),
                0,
                5
            );

            $data['revenue_focus'] = $this->buildRevenueFocus($userId, (int) count($overdueTasks));

            $outcomeRollout = new OutcomeRolloutService();
            if ($outcomeRollout->isFeatureEnabled($userId, 'daily_focus')) {
                $outcomeMetrics = new OutcomeMetrics();
                $outcomeFocus = $outcomeMetrics->getTodayRevenueFocus($userId);
                if (!empty($outcomeFocus)) {
                    $topFocus = (string) $outcomeFocus[0];
                    if (!in_array($topFocus, $data['revenue_focus'], true)) {
                        array_unshift($data['revenue_focus'], $topFocus);
                    }
                    $data['revenue_focus'] = array_slice($data['revenue_focus'], 0, 3);
                }
            }
        } catch (\Throwable $e) {
            error_log('ChatWelcomeService: ' . $e->getMessage());
        }

        return $data;
    }

    /**
     * Build greeting text from welcome data
     *
     * @param array $data
     * @return string HTML-safe greeting
     */
    public function buildGreeting(array $data): string
    {
        $name = $this->escapeDisplayText($data['display_name'] ?? 'there');
        $assistant = $this->escapeDisplayText(brandAssistantName());
        $lines = [
            'Hi ' . $name . ', I\'m ' . $assistant . ', your AI Co-Founder for Structured Growth.',
            '',
            'Let\'s focus on the next move that makes the workspace stronger.',
        ];

        return implode('<br>', $lines);
    }

    private function escapeDisplayText(string $value): string
    {
        $normalized = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return htmlspecialchars($normalized, ENT_COMPAT | ENT_HTML5, 'UTF-8');
    }

    /**
     * Build compact revenue-focused priorities for today's greeting.
     *
     * @param int $userId
     * @param int $overdueTasksCount
     * @return array<int, string>
     */
    private function buildRevenueFocus(int $userId, int $overdueTasksCount): array
    {
        $focus = [];
        $dealScopeSql = '';
        $dealScopeParams = [];
        if ($userId > 0) {
            $dealScopeSql = ' AND (assigned_to = ? OR created_by = ?)';
            $dealScopeParams = [$userId, $userId];
        }

        try {
            $open = Database::queryOne(
                "SELECT COUNT(*) AS cnt, COALESCE(SUM(value), 0) AS total_value
                 FROM deals
                 WHERE stage NOT IN ('closed_won', 'closed_lost')" . $dealScopeSql,
                $dealScopeParams
            ) ?: [];

            $stale = Database::queryOne(
                "SELECT COUNT(*) AS cnt
                 FROM deals
                 WHERE stage NOT IN ('closed_won', 'closed_lost')
                   AND updated_at < DATE_SUB(NOW(), INTERVAL 7 DAY)" . $dealScopeSql,
                $dealScopeParams
            ) ?: [];

            $dueToday = Database::queryOne(
                "SELECT COUNT(*) AS cnt
                 FROM deals
                 WHERE stage NOT IN ('closed_won', 'closed_lost')
                   AND expected_close_date = CURDATE()" . $dealScopeSql,
                $dealScopeParams
            ) ?: [];

            $wonToday = Database::queryOne(
                "SELECT COUNT(*) AS cnt, COALESCE(SUM(value), 0) AS total_value
                 FROM deals
                 WHERE stage = 'closed_won'
                   AND (actual_close_date = CURDATE() OR DATE(updated_at) = CURDATE())" . $dealScopeSql,
                $dealScopeParams
            ) ?: [];

            $leadsToday = Database::queryOne(
                "SELECT COUNT(*) AS cnt
                 FROM contacts
                 WHERE DATE(created_at) = CURDATE()"
            ) ?: [];

            $openCount = (int) ($open['cnt'] ?? 0);
            $openValue = (float) ($open['total_value'] ?? 0);
            $staleCount = (int) ($stale['cnt'] ?? 0);
            $dueTodayCount = (int) ($dueToday['cnt'] ?? 0);
            $wonTodayCount = (int) ($wonToday['cnt'] ?? 0);
            $wonTodayValue = (float) ($wonToday['total_value'] ?? 0);
            $leadsTodayCount = (int) ($leadsToday['cnt'] ?? 0);

            if ($openCount > 0) {
                $focus[] = 'Protect active pipeline: ' . $openCount . ' open deals (~' . $this->formatMoney($openValue) . ').';
            }
            if ($staleCount > 0) {
                $focus[] = 'Recover momentum: follow up ' . $staleCount . ' stale deal' . ($staleCount === 1 ? '' : 's') . ' (7+ days idle).';
            } elseif ($dueTodayCount > 0) {
                $focus[] = 'Close-day push: resolve ' . $dueTodayCount . ' deal' . ($dueTodayCount === 1 ? '' : 's') . ' expected to close today.';
            }
            if ($wonTodayCount > 0) {
                $focus[] = 'Wins today: ' . $wonTodayCount . ' closed-won (~' . $this->formatMoney($wonTodayValue) . ').';
            } elseif ($overdueTasksCount > 0) {
                $focus[] = 'Execution gap: clear ' . $overdueTasksCount . ' overdue revenue task' . ($overdueTasksCount === 1 ? '' : 's') . '.';
            }
            if (empty($focus)) {
                if ($leadsTodayCount === 0) {
                    $focus[] = 'Pipeline refill: add or import at least 5 new leads today.';
                } else {
                    $focus[] = 'New lead conversion: qualify today\'s ' . $leadsTodayCount . ' new lead' . ($leadsTodayCount === 1 ? '' : 's') . ' and book next steps.';
                }
            }
        } catch (\Throwable $e) {
            error_log('ChatWelcomeService::buildRevenueFocus: ' . $e->getMessage());
        }

        return array_slice($focus, 0, 3);
    }

    private function formatMoney(float $value): string
    {
        return '$' . number_format($value, 0);
    }

    /**
     * Resolve display name from user profile first, then email fallback.
     *
     * @param string $firstName
     * @param string $lastName
     * @param string $email
     * @return string
     */
    private function getDisplayName(string $firstName, string $lastName, string $email): string
    {
        $firstName = trim($firstName);
        $lastName = trim($lastName);
        if ($firstName !== '') {
            return $firstName;
        }

        if ($lastName !== '') {
            return $lastName;
        }

        $email = trim($email);
        if (empty($email) || strpos($email, '@') === false) {
            return 'there';
        }

        $local = explode('@', $email)[0];
        $local = str_replace(['.', '_', '-'], ' ', $local);
        $local = ucwords(strtolower($local));
        $words = preg_split('/\s+/', trim($local), 2);

        return $words[0] ?: 'there';
    }
}
