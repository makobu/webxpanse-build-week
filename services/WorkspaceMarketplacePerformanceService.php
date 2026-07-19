<?php

namespace CRM\Services;

use CRM\Database;

class WorkspaceMarketplacePerformanceService
{
    public function buildModulePerformance(int $workspaceId, string $skillKey, int $windowDays = 30): array
    {
        $skillKey = $this->normalizeKey($skillKey);
        if ($workspaceId <= 0 || $skillKey === '') {
            return $this->blankModule($skillKey, $windowDays);
        }

        $since = (new \DateTimeImmutable('now'))->modify('-' . max(1, $windowDays) . ' days')->format('Y-m-d H:i:s');
        $recommendationRows = $this->recommendationRows($workspaceId, $skillKey, $since);
        $setupRows = $this->setupRows($workspaceId, $skillKey, $since);
        $skillRows = $this->skillRows($workspaceId, $skillKey, $since);
        $allRows = array_merge($recommendationRows, $setupRows, $skillRows);

        usort($allRows, static function (array $left, array $right): int {
            return strcmp((string) ($right['created_at'] ?? ''), (string) ($left['created_at'] ?? ''));
        });

        $recommendationCounts = $this->countEvents($recommendationRows);
        $setupCounts = $this->countEvents($setupRows);
        $skillCounts = $this->countEvents($skillRows);
        $impressions = (int) ($recommendationCounts['impression'] ?? 0) + (int) ($setupCounts['journey_impression'] ?? 0);
        $moduleViews = (int) ($recommendationCounts['module_page_view'] ?? 0);
        $catalogClicks = (int) ($recommendationCounts['catalog_click'] ?? 0);
        $clicks = (int) ($recommendationCounts['cta_clicked'] ?? 0)
            + $catalogClicks
            + (int) ($setupCounts['setup_opened'] ?? 0);
        $installEvents = (int) ($skillCounts['installed'] ?? 0);
        if ($installEvents <= 0) {
            $installEvents = max(
                (int) ($recommendationCounts['installed'] ?? 0),
                (int) ($setupCounts['install_completed'] ?? 0)
            );
        }

        return [
            'skill_key' => $skillKey,
            'window_days' => max(1, $windowDays),
            'metrics' => [
                'impressions' => $impressions,
                'module_page_views' => $moduleViews,
                'catalog_clicks' => $catalogClicks,
                'clicks' => $clicks,
                'installs' => $installEvents,
                'removals' => (int) ($skillCounts['uninstalled'] ?? $recommendationCounts['uninstalled'] ?? 0),
                'active_users' => $this->activeUsers($allRows),
                'click_through_rate' => $impressions > 0 ? round($clicks / $impressions, 4) : 0.0,
                'setup_opens' => (int) ($setupCounts['setup_opened'] ?? 0),
                'setup_saves' => (int) ($setupCounts['setup_saved'] ?? 0),
                'test_attempts' => (int) ($recommendationCounts['test_attempted'] ?? 0),
                'test_passes' => (int) ($recommendationCounts['test_passed'] ?? 0),
                'test_failures' => (int) ($recommendationCounts['test_failed'] ?? 0),
                'current_installed' => $this->currentInstalled($workspaceId, $skillKey),
            ],
            'recommendation_events' => $recommendationCounts,
            'setup_events' => $setupCounts,
            'skill_events' => $skillCounts,
            'runtime' => $this->buildModuleRuntimeUsage($workspaceId, $skillKey, $windowDays),
            'recent_events' => array_slice(array_map([$this, 'publicEvent'], $allRows), 0, 6),
            'generated_at' => gmdate('c'),
        ];
    }

    public function buildModuleRuntimeUsage(int $workspaceId, string $skillKey, int $windowDays = 30): array
    {
        $skillKey = $this->normalizeKey($skillKey);
        $windowDays = max(1, $windowDays);

        return match ($skillKey) {
            WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT => [
                'runs_30d' => $this->countRowsForWorkspace('email_assistant_runs', $workspaceId, 't.created_at >= DATE_SUB(NOW(), INTERVAL ' . $windowDays . ' DAY)' . $this->assistantTypeSql('email_assistant_runs', 'email')),
                'queued_actions' => $this->countRowsForWorkspace('email_assistant_action_queue', $workspaceId, "t.status = 'pending'" . $this->assistantTypeSql('email_assistant_action_queue', 'email')),
                'latest_run_status' => (string) ($this->latestAssistantRun($workspaceId, 'email')['execution_status'] ?? ''),
            ],
            WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT => [
                'messages_30d' => $this->countRowsForWorkspace('whatsapp_assistant_messages', $workspaceId, 't.created_at >= DATE_SUB(NOW(), INTERVAL ' . $windowDays . ' DAY)'),
                'digests_30d' => $this->countRowsForWorkspace('whatsapp_assistant_digest_log', $workspaceId, 't.sent_at >= DATE_SUB(NOW(), INTERVAL ' . $windowDays . ' DAY)'),
                'assistant_runs_30d' => $this->countRowsForWorkspace('email_assistant_runs', $workspaceId, 't.created_at >= DATE_SUB(NOW(), INTERVAL ' . $windowDays . ' DAY)' . $this->assistantTypeSql('email_assistant_runs', 'whatsapp')),
                'queued_actions' => $this->countRowsForWorkspace('email_assistant_action_queue', $workspaceId, "t.status = 'pending'" . $this->assistantTypeSql('email_assistant_action_queue', 'whatsapp')),
                'active_sessions' => $this->columnExists('whatsapp_assistant_sessions', 'session_state')
                    ? $this->countRowsForWorkspace('whatsapp_assistant_sessions', $workspaceId, "t.session_state = 'session_open'")
                    : $this->countRowsForWorkspace('whatsapp_assistant_sessions', $workspaceId),
                'latest_message_status' => $this->columnExists('whatsapp_assistant_messages', 'status')
                    ? (string) ($this->latestRowForWorkspace('whatsapp_assistant_messages', $workspaceId, 'ORDER BY t.created_at DESC, t.id DESC LIMIT 1')['status'] ?? '')
                    : '',
                'latest_run_status' => (string) ($this->latestAssistantRun($workspaceId, 'whatsapp')['execution_status'] ?? ''),
            ],
            WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL => [
                'sent_30d' => $this->countRowsForWorkspace('sms_messages', $workspaceId, "t.direction = 'outbound' AND t.created_at >= DATE_SUB(NOW(), INTERVAL " . $windowDays . " DAY)"),
                'failed_30d' => $this->countRowsForWorkspace('sms_messages', $workspaceId, "t.status = 'failed' AND t.created_at >= DATE_SUB(NOW(), INTERVAL " . $windowDays . " DAY)"),
                'queued_messages' => $this->columnExists('sms_queue', 'status')
                    ? $this->countRowsForWorkspace('sms_queue', $workspaceId, "t.status = 'pending'")
                    : $this->countRowsForWorkspace('sms_queue', $workspaceId),
                'latest_sms_status' => $this->columnExists('sms_messages', 'status')
                    ? (string) ($this->latestRow('sms_messages', 'WHERE workspace_id = ? ORDER BY created_at DESC, id DESC LIMIT 1', [$workspaceId])['status'] ?? '')
                    : '',
            ],
            WorkspaceSkillCatalogService::PLUGIN_CALENDAR_MEETINGS => [
                'connected_calendars' => $this->countRows('calendar_integrations', 'WHERE workspace_id = ? AND sync_enabled = 1', [$workspaceId]),
                'meeting_bot_runs_30d' => $this->countRowsForWorkspace('meeting_bot_runs', $workspaceId, 't.created_at >= DATE_SUB(NOW(), INTERVAL ' . $windowDays . ' DAY)', [], 'created_by'),
                'notes_ingested_30d' => $this->countRowsForWorkspace('meeting_note_taker_runs', $workspaceId, 't.created_at >= DATE_SUB(NOW(), INTERVAL ' . $windowDays . ' DAY)', [], 'created_by'),
                'latest_bot_status' => $this->columnExists('meeting_bot_runs', 'status')
                    ? (string) ($this->latestRowForWorkspace('meeting_bot_runs', $workspaceId, 'ORDER BY t.created_at DESC, t.id DESC LIMIT 1', [], 'created_by')['status'] ?? '')
                    : '',
            ],
            WorkspaceSkillCatalogService::SKILL_AI_COACH => [
                'guidance_runs_30d' => $this->countRowsForWorkspace('ai_guidance_runs', $workspaceId, 't.created_at >= DATE_SUB(NOW(), INTERVAL ' . $windowDays . ' DAY)'),
                'feedback_events_30d' => $this->countRowsForWorkspace('ai_advice_feedback', $workspaceId, 't.created_at >= DATE_SUB(NOW(), INTERVAL ' . $windowDays . ' DAY)'),
            ],
            WorkspaceSkillCatalogService::PLUGIN_HR_ANALYTICS_SETUP => $this->buildHrAnalyticsRuntimeUsage($workspaceId, $windowDays),
            default => [],
        };
    }

    public function buildWorkspaceSummary(int $workspaceId, int $windowDays = 30): array
    {
        if ($workspaceId <= 0) {
            return [
                'window_days' => max(1, $windowDays),
                'metrics' => [],
                'top_modules' => [],
                'generated_at' => gmdate('c'),
            ];
        }

        $since = (new \DateTimeImmutable('now'))->modify('-' . max(1, $windowDays) . ' days')->format('Y-m-d H:i:s');
        $modules = (new WorkspaceSkillCatalogService())->available();
        $moduleSummaries = [];
        foreach ($modules as $module) {
            $skillKey = (string) ($module['key'] ?? '');
            if ($skillKey === '') {
                continue;
            }
            $summary = $this->buildModulePerformance($workspaceId, $skillKey, $windowDays);
            $summary['label'] = (string) ($module['label'] ?? $skillKey);
            $summary['module_type'] = (string) ($module['module_type'] ?? 'skill');
            $moduleSummaries[] = $summary;
        }

        usort($moduleSummaries, static function (array $left, array $right): int {
            $leftScore = (int) ($left['metrics']['clicks'] ?? 0) + (int) ($left['metrics']['installs'] ?? 0) + (int) ($left['metrics']['impressions'] ?? 0);
            $rightScore = (int) ($right['metrics']['clicks'] ?? 0) + (int) ($right['metrics']['installs'] ?? 0) + (int) ($right['metrics']['impressions'] ?? 0);
            if ($rightScore !== $leftScore) {
                return $rightScore <=> $leftScore;
            }
            return strcmp((string) ($left['skill_key'] ?? ''), (string) ($right['skill_key'] ?? ''));
        });

        $totals = [
            'impressions' => 0,
            'module_page_views' => 0,
            'catalog_clicks' => 0,
            'clicks' => 0,
            'installs' => 0,
            'removals' => 0,
            'active_users' => 0,
            'setup_opens' => 0,
            'setup_saves' => 0,
            'test_attempts' => 0,
            'test_passes' => 0,
            'test_failures' => 0,
        ];
        foreach ($moduleSummaries as $summary) {
            foreach (['impressions', 'module_page_views', 'catalog_clicks', 'clicks', 'installs', 'removals', 'setup_opens', 'setup_saves', 'test_attempts', 'test_passes', 'test_failures'] as $key) {
                $totals[$key] += (int) ($summary['metrics'][$key] ?? 0);
            }
        }
        $totals['active_users'] = $this->workspaceActiveUsers($workspaceId, $since);
        $totals['click_through_rate'] = $totals['impressions'] > 0 ? round($totals['clicks'] / $totals['impressions'], 4) : 0.0;

        return [
            'window_days' => max(1, $windowDays),
            'metrics' => $totals,
            'top_modules' => array_slice($moduleSummaries, 0, 5),
            'generated_at' => gmdate('c'),
        ];
    }

    private function recommendationRows(int $workspaceId, string $skillKey, string $since): array
    {
        if (!Database::tableExists('workspace_marketplace_recommendation_events')) {
            return [];
        }

        return Database::query(
            "SELECT 'recommendation' AS source, event_type, user_id, created_at, metadata_json
             FROM workspace_marketplace_recommendation_events
             WHERE workspace_id = ? AND skill_key = ? AND created_at >= ?",
            [$workspaceId, $skillKey, $since]
        );
    }

    private function setupRows(int $workspaceId, string $skillKey, string $since): array
    {
        if (!Database::tableExists('workspace_marketplace_setup_journey_events')) {
            return [];
        }

        return Database::query(
            "SELECT 'setup_journey' AS source, event_type, user_id, created_at, metadata_json
             FROM workspace_marketplace_setup_journey_events
             WHERE workspace_id = ? AND skill_key = ? AND created_at >= ?",
            [$workspaceId, $skillKey, $since]
        );
    }

    private function skillRows(int $workspaceId, string $skillKey, string $since): array
    {
        if (!Database::tableExists('workspace_skill_events')) {
            return [];
        }

        return Database::query(
            "SELECT 'install' AS source, event_type, actor_user_id AS user_id, created_at, metadata_json
             FROM workspace_skill_events
             WHERE workspace_id = ? AND skill_key = ? AND created_at >= ?",
            [$workspaceId, $skillKey, $since]
        );
    }

    private function countEvents(array $rows): array
    {
        $counts = [];
        foreach ($rows as $row) {
            $eventType = (string) ($row['event_type'] ?? '');
            if ($eventType === '') {
                continue;
            }
            $counts[$eventType] = (int) (($counts[$eventType] ?? 0) + 1);
        }

        return $counts;
    }

    private function activeUsers(array $rows): int
    {
        $users = [];
        foreach ($rows as $row) {
            $userId = (int) ($row['user_id'] ?? 0);
            if ($userId > 0) {
                $users[$userId] = true;
            }
        }

        return count($users);
    }

    private function workspaceActiveUsers(int $workspaceId, string $since): int
    {
        $rows = [];
        if (Database::tableExists('workspace_marketplace_recommendation_events')) {
            $rows = array_merge($rows, Database::query(
                "SELECT user_id FROM workspace_marketplace_recommendation_events WHERE workspace_id = ? AND created_at >= ?",
                [$workspaceId, $since]
            ));
        }
        if (Database::tableExists('workspace_marketplace_setup_journey_events')) {
            $rows = array_merge($rows, Database::query(
                "SELECT user_id FROM workspace_marketplace_setup_journey_events WHERE workspace_id = ? AND created_at >= ?",
                [$workspaceId, $since]
            ));
        }
        if (Database::tableExists('workspace_skill_events')) {
            $rows = array_merge($rows, Database::query(
                "SELECT actor_user_id AS user_id FROM workspace_skill_events WHERE workspace_id = ? AND created_at >= ?",
                [$workspaceId, $since]
            ));
        }

        return $this->activeUsers($rows);
    }

    private function buildHrAnalyticsRuntimeUsage(int $workspaceId, int $windowDays): array
    {
        if (!Database::tableExists('workspace_plugin_runtime_events')) {
            return [];
        }

        $windowDays = max(1, $windowDays);
        $baseWhere = "WHERE workspace_id = ?
            AND skill_key = ?
            AND created_at >= DATE_SUB(NOW(), INTERVAL {$windowDays} DAY)";
        $params = [$workspaceId, WorkspaceSkillCatalogService::PLUGIN_HR_ANALYTICS_SETUP];
        $summary = Database::queryOne(
            "SELECT COUNT(*) AS events,
                    SUM(CASE WHEN event_type = 'capability_succeeded' AND status = 'success' THEN 1 ELSE 0 END) AS successful_runs,
                    SUM(CASE WHEN status = 'blocked' THEN 1 ELSE 0 END) AS blocked_runs,
                    SUM(CASE WHEN event_type = 'capability_failed' OR status = 'failed' THEN 1 ELSE 0 END) AS failed_runs,
                    AVG(CASE WHEN duration_ms IS NOT NULL THEN duration_ms ELSE NULL END) AS avg_duration_ms
             FROM workspace_plugin_runtime_events
             {$baseWhere}",
            $params
        ) ?: [];
        $latest = Database::queryOne(
            "SELECT capability_key, status, duration_ms, created_at
             FROM workspace_plugin_runtime_events
             {$baseWhere}
             ORDER BY created_at DESC, id DESC
             LIMIT 1",
            $params
        ) ?: [];

        return [
            'events_30d' => (int) ($summary['events'] ?? 0),
            'successful_runs_30d' => (int) ($summary['successful_runs'] ?? 0),
            'blocked_runs_30d' => (int) ($summary['blocked_runs'] ?? 0),
            'failed_runs_30d' => (int) ($summary['failed_runs'] ?? 0),
            'avg_duration_ms' => round((float) ($summary['avg_duration_ms'] ?? 0), 1),
            'active_minutes_30d' => $this->workspaceSystemActiveMinutes($workspaceId, $windowDays),
            'latest_capability' => (string) ($latest['capability_key'] ?? ''),
            'latest_status' => (string) ($latest['status'] ?? ''),
        ];
    }

    private function workspaceSystemActiveMinutes(int $workspaceId, int $windowDays): float
    {
        if (!Database::tableExists('user_system_sessions')) {
            return 0.0;
        }

        $row = Database::queryOne(
            "SELECT SUM(COALESCE(active_seconds, 0)) AS active_seconds
             FROM user_system_sessions
             WHERE workspace_id = ?
               AND started_at >= DATE_SUB(NOW(), INTERVAL " . max(1, $windowDays) . " DAY)",
            [$workspaceId]
        ) ?: [];

        return round(((int) ($row['active_seconds'] ?? 0)) / 60, 1);
    }

    private function countRows(string $table, string $where = '', array $params = []): int
    {
        if (!Database::tableExists($table)) {
            return 0;
        }
        try {
            $row = Database::queryOne("SELECT COUNT(*) AS c FROM {$table} {$where}", $params);
            return (int) ($row['c'] ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function latestRow(string $table, string $where = '', array $params = []): array
    {
        if (!Database::tableExists($table)) {
            return [];
        }
        try {
            return Database::queryOne("SELECT * FROM {$table} {$where}", $params) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function countRowsForWorkspace(
        string $table,
        int $workspaceId,
        string $condition = '',
        array $params = [],
        string $userColumn = 'user_id'
    ): int {
        $row = $this->scopedRowForWorkspace('COUNT(*) AS c', $table, $workspaceId, $condition, $params, $userColumn);
        return (int) ($row['c'] ?? 0);
    }

    private function latestRowForWorkspace(
        string $table,
        int $workspaceId,
        string $suffix = '',
        array $params = [],
        string $userColumn = 'user_id'
    ): array {
        return $this->scopedRowForWorkspace('t.*', $table, $workspaceId, '', $params, $userColumn, $suffix);
    }

    private function scopedRowForWorkspace(
        string $select,
        string $table,
        int $workspaceId,
        string $condition = '',
        array $params = [],
        string $userColumn = 'user_id',
        string $suffix = ''
    ): array {
        if ($workspaceId <= 0 || !preg_match('/^[a-z0-9_]+$/', $table) || !preg_match('/^[a-z0-9_]+$/', $userColumn)) {
            return [];
        }
        if (!Database::tableExists($table)) {
            return [];
        }

        $where = [];
        $queryParams = [$workspaceId];
        if (trim($condition) !== '') {
            $where[] = '(' . $condition . ')';
            $queryParams = array_merge($queryParams, $params);
        }

        try {
            if ($this->columnExists($table, 'workspace_id')) {
                $sql = "SELECT {$select} FROM {$table} t WHERE t.workspace_id = ?";
                if ($where !== []) {
                    $sql .= ' AND ' . implode(' AND ', $where);
                }
                $sql .= $suffix !== '' ? ' ' . $suffix : '';
                return Database::queryOne($sql, $queryParams) ?: [];
            }

            if ($this->columnExists($table, $userColumn) && Database::tableExists('workspace_memberships')) {
                $sql = "SELECT {$select}
                        FROM {$table} t
                        INNER JOIN workspace_memberships wm
                            ON wm.user_id = t.{$userColumn}
                           AND wm.workspace_id = ?
                           AND wm.membership_status = 'active'";
                if ($where !== []) {
                    $sql .= ' WHERE ' . implode(' AND ', $where);
                }
                $sql .= $suffix !== '' ? ' ' . $suffix : '';
                return Database::queryOne($sql, $queryParams) ?: [];
            }
        } catch (\Throwable $e) {
            return [];
        }

        return [];
    }

    private function assistantTypeSql(string $table, string $assistantType): string
    {
        if (!$this->columnExists($table, 'assistant_type')) {
            return '';
        }

        $assistantType = $assistantType === 'whatsapp' ? 'whatsapp' : 'email';
        return " AND t.assistant_type = '" . $assistantType . "'";
    }

    private function latestAssistantRun(int $workspaceId, string $assistantType): array
    {
        if (!Database::tableExists('email_assistant_runs')) {
            return [];
        }

        $params = [$workspaceId];
        $where = 'WHERE workspace_id = ?';
        if ($this->columnExists('email_assistant_runs', 'assistant_type')) {
            $where .= ' AND assistant_type = ?';
            $params[] = $assistantType === 'whatsapp' ? 'whatsapp' : 'email';
        }

        return $this->latestRow(
            'email_assistant_runs',
            $where . ' ORDER BY created_at DESC, id DESC LIMIT 1',
            $params
        );
    }

    private function columnExists(string $table, string $column): bool
    {
        return Database::tableExists($table) && Database::columnExists($table, $column);
    }

    private function currentInstalled(int $workspaceId, string $skillKey): int
    {
        if (!Database::tableExists('workspace_skill_installs')) {
            return 0;
        }

        $row = Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM workspace_skill_installs
             WHERE workspace_id = ? AND skill_key = ? AND status = 'installed'",
            [$workspaceId, $skillKey]
        );

        return (int) ($row['c'] ?? 0);
    }

    private function publicEvent(array $row): array
    {
        $metadata = json_decode((string) ($row['metadata_json'] ?? ''), true);
        if (!is_array($metadata)) {
            $metadata = [];
        }

        return [
            'source' => (string) ($row['source'] ?? ''),
            'event_type' => (string) ($row['event_type'] ?? ''),
            'user_id' => (int) ($row['user_id'] ?? 0),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'label' => (string) ($metadata['label'] ?? ''),
        ];
    }

    private function blankModule(string $skillKey, int $windowDays): array
    {
        return [
            'skill_key' => $skillKey,
            'window_days' => max(1, $windowDays),
            'metrics' => [
                'impressions' => 0,
                'module_page_views' => 0,
                'catalog_clicks' => 0,
                'clicks' => 0,
                'installs' => 0,
                'removals' => 0,
                'active_users' => 0,
                'click_through_rate' => 0.0,
                'setup_opens' => 0,
                'setup_saves' => 0,
                'test_attempts' => 0,
                'test_passes' => 0,
                'test_failures' => 0,
                'current_installed' => 0,
            ],
            'recommendation_events' => [],
            'setup_events' => [],
            'skill_events' => [],
            'recent_events' => [],
            'generated_at' => gmdate('c'),
        ];
    }

    private function normalizeKey(string $key): string
    {
        return strtolower(trim(preg_replace('/[^a-z0-9_]+/', '_', $key) ?? ''));
    }
}
