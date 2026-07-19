<?php

declare(strict_types=1);

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\DemoModeManager;

class GuidedDemoSessionService
{
    public const DEFAULT_PROFILE = 'solo_founder_launch';

    public function __construct(
        private ?GuidedDemoStepCatalog $steps = null,
        private ?GuidedDemoPluginInstallService $plugins = null
    ) {
        $this->steps = $steps ?? new GuidedDemoStepCatalog();
        $this->plugins = $plugins ?? new GuidedDemoPluginInstallService();
    }

    public function tableReady(): bool
    {
        return Database::tableExists('guided_demo_sessions')
            && Database::tableExists('guided_demo_events')
            && Database::tableExists('guided_demo_action_runs')
            && Database::tableExists('demo_runs')
            && Database::tableExists('demo_seed_registry');
    }

    public function activeSession(int $workspaceId, int $userId): ?array
    {
        if (!$this->tableReady() || $workspaceId <= 0 || $userId <= 0) {
            return null;
        }

        return Database::queryOne(
            "SELECT *
             FROM guided_demo_sessions
             WHERE workspace_id = ?
               AND user_id = ?
               AND status = 'active'
             ORDER BY id DESC
             LIMIT 1",
            [$workspaceId, $userId]
        );
    }

    public function latestSession(int $workspaceId, int $userId): ?array
    {
        if (!$this->tableReady() || $workspaceId <= 0 || $userId <= 0) {
            return null;
        }

        return Database::queryOne(
            "SELECT *
             FROM guided_demo_sessions
             WHERE workspace_id = ? AND user_id = ?
             ORDER BY id DESC
             LIMIT 1",
            [$workspaceId, $userId]
        );
    }

    public function start(int $workspaceId, int $userId): array
    {
        if (!$this->tableReady()) {
            throw new \RuntimeException('Guided demo tables are not installed.');
        }
        if ($workspaceId <= 0 || $userId <= 0) {
            throw new \RuntimeException('A workspace and user are required to start the demo.');
        }

        $active = $this->activeSession($workspaceId, $userId);
        if ($active !== null) {
            return $this->statePayload($active);
        }

        $latest = $this->latestSession($workspaceId, $userId);
        if (($latest['status'] ?? null) === 'cleanup_failed') {
            throw new \RuntimeException('Previous demo cleanup must be retried before a new demo can start.');
        }

        $sessionUuid = $this->uuid();
        Database::execute(
            "INSERT INTO guided_demo_sessions
                (session_uuid, workspace_id, user_id, seed_profile, status, current_step_key, metadata_json)
             VALUES (?, ?, ?, ?, 'pending', ?, ?)",
            [
                $sessionUuid,
                $workspaceId,
                $userId,
                self::DEFAULT_PROFILE,
                $this->steps->firstKey(),
                json_encode(['source' => 'onboarding_guided_demo'], JSON_UNESCAPED_SLASHES),
            ]
        );
        $sessionId = (int) Database::lastInsertId();
        $session = Database::queryOne('SELECT * FROM guided_demo_sessions WHERE id = ? LIMIT 1', [$sessionId]) ?? [];
        $this->recordEvent($sessionId, $workspaceId, $userId, null, 'created', 'guided_demo.php');

        try {
            $seed = (new DemoModeManager())->seedScenarioRun(
                $userId,
                self::DEFAULT_PROFILE,
                'Guided founder demo seed',
                [
                    'workspace_id' => $workspaceId,
                    'owner_user_id' => $userId,
                    'guided_demo_session_id' => $sessionId,
                    'defer_contacts_until_action' => true,
                    'defer_tasks' => true,
                ]
            );
            if (empty($seed['success'])) {
                throw new \RuntimeException((string) ($seed['error'] ?? 'Demo seeding failed.'));
            }

            $runId = (int) ($seed['run_id'] ?? 0);
            if ($runId <= 0) {
                throw new \RuntimeException('Demo seeding did not return a run id.');
            }

            $seedSummary = (array) ($seed['seed_summary'] ?? []);
            $anchors = $this->resolveAnchorIds($workspaceId, $runId, $seedSummary);
            $pluginSummary = $this->plugins->installSimulatedForSession($sessionId, $workspaceId, $userId);
            $metadata = array_merge($this->decodeAssoc($session['metadata_json'] ?? null), $anchors, [
                'seed_summary' => $seedSummary,
                'plugin_summary' => $pluginSummary,
            ]);

            Database::execute(
                "UPDATE guided_demo_sessions
                 SET demo_run_id = ?,
                     status = 'active',
                     current_step_key = ?,
                     started_at = NOW(),
                     metadata_json = ?,
                     updated_at = NOW()
                 WHERE id = ?",
                [
                    $runId,
                    $this->steps->firstKey(),
                    json_encode($metadata, JSON_UNESCAPED_SLASHES),
                    $sessionId,
                ]
            );
            $this->recordEvent($sessionId, $workspaceId, $userId, $this->steps->firstKey(), 'started', 'dashboard.php', [
                'run_id' => $runId,
                'plugins' => $pluginSummary,
            ]);
        } catch (\Throwable $e) {
            Database::execute(
                "UPDATE guided_demo_sessions
                 SET status = 'cleanup_failed',
                     cleanup_status = 'failed',
                     cleanup_summary_json = ?,
                     updated_at = NOW()
                 WHERE id = ?",
                [json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_SLASHES), $sessionId]
            );
            $this->recordEvent($sessionId, $workspaceId, $userId, null, 'cleanup_failed', 'guided_demo.php', ['error' => $e->getMessage()]);
            throw $e;
        }

        return $this->statePayload(Database::queryOne('SELECT * FROM guided_demo_sessions WHERE id = ? LIMIT 1', [$sessionId]) ?? []);
    }

    public function state(int $workspaceId, int $userId): ?array
    {
        $session = $this->activeSession($workspaceId, $userId);
        return $session !== null ? $this->statePayload($session) : null;
    }

    public function advance(int $workspaceId, int $userId, string $direction = 'next'): array
    {
        $session = $this->activeSession($workspaceId, $userId);
        if ($session === null) {
            throw new \RuntimeException('No active guided demo session was found.');
        }

        $currentKey = (string) ($session['current_step_key'] ?? $this->steps->firstKey());
        if ($direction !== 'back' && $this->steps->requiresAction($currentKey)) {
            $action = $this->steps->actionFor($currentKey) ?? [];
            $actionKey = (string) ($action['key'] ?? '');
            if ($actionKey !== '' && !in_array($actionKey, $this->completedActionKeys((int) $session['id']), true)) {
                throw new \RuntimeException('Complete the demo action before moving to the next step.');
            }
        }

        $nextKey = $direction === 'back'
            ? $this->steps->previousKey($currentKey)
            : $this->steps->nextKey($currentKey);

        Database::execute(
            "UPDATE guided_demo_sessions
             SET current_step_key = ?, updated_at = NOW()
             WHERE id = ?",
            [$nextKey, (int) $session['id']]
        );

        $this->recordEvent(
            (int) $session['id'],
            $workspaceId,
            $userId,
            $nextKey,
            $direction === 'back' ? 'back' : 'advanced',
            $this->steps->pageFor($nextKey)
        );

        return $this->statePayload(Database::queryOne('SELECT * FROM guided_demo_sessions WHERE id = ? LIMIT 1', [(int) $session['id']]) ?? []);
    }

    public function finish(int $workspaceId, int $userId, string $reason = 'completed'): array
    {
        $reason = $reason === 'exited' ? 'exited' : 'completed';
        $session = $this->activeSession($workspaceId, $userId);
        if ($session === null) {
            $latest = $this->latestSession($workspaceId, $userId);
            if (($latest['status'] ?? null) !== 'cleanup_failed') {
                $this->ensureCompassFree($workspaceId, $userId);
                return [
                    'success' => true,
                    'redirect_url' => $this->dashboardUrl($reason === 'exited' ? 'exited' : 'complete'),
                    'cleanup' => ['already_finished' => true],
                ];
            }
            $session = $latest;
        }

        $sessionId = (int) ($session['id'] ?? 0);
        if ((string) ($session['cleanup_status'] ?? '') === 'succeeded') {
            $this->ensureCompassFree($workspaceId, $userId);
            return [
                'success' => true,
                'redirect_url' => $this->dashboardUrl($reason === 'exited' ? 'exited' : 'complete'),
                'cleanup' => $this->decodeAssoc($session['cleanup_summary_json'] ?? null),
            ];
        }

        Database::execute(
            "UPDATE guided_demo_sessions
             SET cleanup_status = 'pending', updated_at = NOW()
             WHERE id = ?",
            [$sessionId]
        );
        $this->recordEvent($sessionId, $workspaceId, $userId, (string) ($session['current_step_key'] ?? ''), 'cleanup_started', null, ['reason' => $reason]);

        try {
            $runId = (int) ($session['demo_run_id'] ?? 0);
            $purge = $runId > 0
                ? (new DemoModeManager())->purge($runId, $userId)
                : ['success' => true, 'deleted' => 0, 'skipped' => 0];
            if (empty($purge['success'])) {
                throw new \RuntimeException((string) ($purge['error'] ?? 'Demo data cleanup failed.'));
            }

            $pluginRestore = $this->plugins->restoreForSession($sessionId, $workspaceId, $userId);
            if (!empty($pluginRestore['errors'])) {
                throw new \RuntimeException('Plugin cleanup failed: ' . implode('; ', $pluginRestore['errors']));
            }

            $summary = [
                'reason' => $reason,
                'purge' => $purge,
                'plugins' => $pluginRestore,
                'finished_at' => gmdate('c'),
            ];
            Database::execute(
                "UPDATE guided_demo_sessions
                 SET status = ?,
                     cleanup_status = 'succeeded',
                     cleanup_summary_json = ?,
                     completed_at = IF(? = 'completed', COALESCE(completed_at, NOW()), completed_at),
                     exited_at = IF(? = 'exited', COALESCE(exited_at, NOW()), exited_at),
                     updated_at = NOW()
                 WHERE id = ?",
                [
                    $reason,
                    json_encode($summary, JSON_UNESCAPED_SLASHES),
                    $reason,
                    $reason,
                    $sessionId,
                ]
            );
            $this->recordEvent($sessionId, $workspaceId, $userId, null, $reason === 'exited' ? 'exited' : 'completed', null, $summary);
            $this->recordEvent($sessionId, $workspaceId, $userId, null, 'cleanup_succeeded', null, $summary);
            $this->ensureCompassFree($workspaceId, $userId);

            return [
                'success' => true,
                'redirect_url' => $this->dashboardUrl($reason === 'exited' ? 'exited' : 'complete'),
                'cleanup' => $summary,
            ];
        } catch (\Throwable $e) {
            $summary = ['reason' => $reason, 'error' => $e->getMessage(), 'failed_at' => gmdate('c')];
            Database::execute(
                "UPDATE guided_demo_sessions
                 SET status = 'cleanup_failed',
                     cleanup_status = 'failed',
                     cleanup_summary_json = ?,
                     updated_at = NOW()
                 WHERE id = ?",
                [json_encode($summary, JSON_UNESCAPED_SLASHES), $sessionId]
            );
            $this->recordEvent($sessionId, $workspaceId, $userId, null, 'cleanup_failed', null, $summary);
            throw $e;
        }
    }

    public function recordEvent(int $sessionId, int $workspaceId, int $userId, ?string $stepKey, string $eventType, ?string $page = null, array $metadata = []): void
    {
        if (!Database::tableExists('guided_demo_events') || $sessionId <= 0 || $workspaceId <= 0) {
            return;
        }

        Database::execute(
            "INSERT INTO guided_demo_events
                (session_id, workspace_id, user_id, step_key, event_type, page, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [
                $sessionId,
                $workspaceId,
                $userId > 0 ? $userId : null,
                $stepKey !== '' ? $stepKey : null,
                $eventType,
                $page,
                $metadata !== [] ? json_encode($metadata, JSON_UNESCAPED_SLASHES) : null,
            ]
        );
    }

    public function statePayload(array $session): array
    {
        $stepKey = (string) ($session['current_step_key'] ?? $this->steps->firstKey());
        $sessionId = (int) ($session['id'] ?? 0);
        $completedActions = $this->completedActionKeys($sessionId);
        $actionRows = $this->actionRows($sessionId);
        $clientStep = $this->steps->clientStep($stepKey, $session, $completedActions, $actionRows);
        $recommendation = (new GuidedDemoPackageIntentService())->recommendationForSession($session, $completedActions);

        return [
            'session' => [
                'id' => (int) ($session['id'] ?? 0),
                'uuid' => (string) ($session['session_uuid'] ?? ''),
                'workspace_id' => (int) ($session['workspace_id'] ?? 0),
                'user_id' => (int) ($session['user_id'] ?? 0),
                'status' => (string) ($session['status'] ?? ''),
                'cleanup_status' => (string) ($session['cleanup_status'] ?? ''),
                'current_step_key' => $stepKey,
                'metadata' => $this->decodeAssoc($session['metadata_json'] ?? null),
            ],
            'step' => $clientStep,
            'phases' => $this->steps->phases(),
            'checklist' => $this->steps->checklist($stepKey, $completedActions),
            'completed_actions' => $completedActions,
            'action_runs' => $actionRows,
            'package_recommendation' => $recommendation,
            'readiness' => [
                'cleanup_required' => in_array((string) ($session['status'] ?? ''), ['active', 'cleanup_failed'], true),
                'package_gate_ready' => in_array((string) ($session['status'] ?? ''), ['completed', 'exited'], true)
                    && (string) ($session['cleanup_status'] ?? '') === 'succeeded',
            ],
            'redirect_url' => $this->publicUrl((string) ($clientStep['route'] ?? 'dashboard.php?guided_demo=1')),
        ];
    }

    /**
     * @return list<string>
     */
    private function completedActionKeys(int $sessionId): array
    {
        if ($sessionId <= 0 || !Database::tableExists('guided_demo_action_runs')) {
            return [];
        }

        $rows = Database::query(
            "SELECT action_key
             FROM guided_demo_action_runs
             WHERE session_id = ?
               AND status = 'completed'
             ORDER BY id ASC",
            [$sessionId]
        );

        return array_values(array_filter(array_map(
            static fn(array $row): string => (string) ($row['action_key'] ?? ''),
            $rows
        )));
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function actionRows(int $sessionId): array
    {
        if ($sessionId <= 0 || !Database::tableExists('guided_demo_action_runs')) {
            return [];
        }

        $rows = Database::query(
            "SELECT step_key, action_key, status, result_metadata_json, created_records_json, completed_at
             FROM guided_demo_action_runs
             WHERE session_id = ?
             ORDER BY id ASC",
            [$sessionId]
        );

        return array_map(function (array $row): array {
            return [
                'step_key' => (string) ($row['step_key'] ?? ''),
                'action_key' => (string) ($row['action_key'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
                'result' => $this->decodeAssoc($row['result_metadata_json'] ?? null),
                'created_records' => $this->decodeAssoc($row['created_records_json'] ?? null),
                'completed_at' => (string) ($row['completed_at'] ?? ''),
            ];
        }, $rows);
    }

    private function resolveAnchorIds(int $workspaceId, int $runId, array $seedSummary): array
    {
        $seeded = (array) ($seedSummary['seeded_ids'] ?? []);
        $contacts = array_values(array_map('intval', (array) ($seeded['contacts'] ?? [])));
        $products = array_values(array_map('intval', (array) ($seeded['products'] ?? [])));
        $weeklyReviews = array_values(array_map('intval', (array) ($seeded['founder_weekly_reviews'] ?? [])));
        $metadata = [
            'primary_contact_id' => $contacts[0] ?? 0,
            'launch_contact_id' => $contacts[3] ?? ($contacts[0] ?? 0),
            'launch_product_id' => $products[0] ?? 0,
            'weekly_review_id' => $weeklyReviews[0] ?? 0,
        ];

        foreach ([
            'first_paid_deal_id' => ['deals', 'title', 'First Paid Sprint - Warm Prospect'],
            'launch_offer_deal_id' => ['deals', 'title', 'Launch Offer Follow-Up'],
            'first_offer_task_id' => ['tasks', 'title', 'Finalize first offer and pricing'],
            'first_outreach_task_id' => ['tasks', 'title', 'Send first 20 warm outreach messages'],
            'weekly_review_task_id' => ['tasks', 'title', 'Complete first weekly review'],
        ] as $key => $lookup) {
            [$table, $column, $value] = $lookup;
            if (!Database::tableExists($table)) {
                $metadata[$key] = 0;
                continue;
            }
            $workspaceClause = Database::columnExists($table, 'workspace_id') ? ' AND t.workspace_id = ?' : '';
            $params = [$runId, $value];
            if ($workspaceClause !== '') {
                $params[] = $workspaceId;
            }
            $row = Database::queryOne(
                "SELECT t.id
                 FROM `{$table}` t
                 INNER JOIN demo_seed_registry r ON r.record_id = t.id AND r.table_name = ?
                 WHERE r.run_id = ?
                   AND t.`{$column}` = ?" . $workspaceClause . "
                 ORDER BY t.id ASC
                 LIMIT 1",
                array_merge([$table], $params)
            );
            $metadata[$key] = (int) ($row['id'] ?? 0);
        }

        return $metadata;
    }

    private function publicUrl(string $path): string
    {
        return function_exists('publicUrl') ? publicUrl($path) : '/' . ltrim($path, '/');
    }

    private function dashboardUrl(string $demoStatus = ''): string
    {
        $query = $demoStatus !== '' ? '?demo=' . rawurlencode($demoStatus) : '';
        return $this->publicUrl('dashboard.php' . $query);
    }

    private function ensureCompassFree(int $workspaceId, int $userId): void
    {
        try {
            (new SaaSBillingService())->activateCompassFreeSubscription($workspaceId, $userId);
        } catch (\Throwable $e) {
            error_log('Compass Free activation after guided demo failed: ' . $e->getMessage());
        }
    }

    private function decodeAssoc(mixed $json): array
    {
        if (is_array($json)) {
            return $json;
        }
        $decoded = json_decode((string) $json, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
