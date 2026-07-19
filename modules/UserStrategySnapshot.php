<?php
/**
 * Versioned per-user strategy snapshots.
 *
 * Snapshots preserve each team member's ICP, strategy lens, market view, and
 * competition assumptions so later analytics can compare strategies over time.
 */

namespace CRM\Modules;

use CRM\Database;

class UserStrategySnapshot
{
    private const MAX_FIELD_LENGTH = 2000;

    /**
     * Sync the active snapshot for a workspace user. Creates a new version only
     * when the current strategy/idea-validation source differs from the active
     * snapshot.
     */
    public function syncForUser(int $workspaceId, int $userId): ?array
    {
        $workspaceId = max(0, $workspaceId);
        $userId = max(0, $userId);
        if ($workspaceId <= 0 || $userId <= 0 || !$this->tableReady()) {
            return null;
        }

        $source = $this->buildSource($workspaceId, $userId);
        if (!$this->hasSnapshotSignal($source['data'])) {
            return null;
        }

        $hash = $this->hashSource($source['data']);
        $active = $this->getActiveSnapshot($workspaceId, $userId);
        if ($active && hash_equals((string) ($active['source_hash'] ?? ''), $hash)) {
            return $active;
        }
        if ($active && $this->snapshotDataMatches($active, $source['data'])) {
            Database::execute(
                "UPDATE user_strategy_snapshots
                 SET source_hash = ?,
                     source_json = ?,
                     updated_at = NOW()
                 WHERE id = ?",
                [$hash, json_encode($source['data'], JSON_UNESCAPED_SLASHES), (int) ($active['id'] ?? 0)]
            );
            return $this->getActiveSnapshot($workspaceId, $userId);
        }

        $pdo = Database::getInstance();
        $startedTransaction = !$pdo->inTransaction();
        if ($startedTransaction) {
            Database::beginTransaction();
        }

        try {
            Database::execute(
                "UPDATE user_strategy_snapshots
                 SET status = 'superseded',
                     ended_at = COALESCE(ended_at, NOW()),
                     updated_at = NOW()
                 WHERE workspace_id = ?
                   AND user_id = ?
                   AND status = 'active'",
                [$workspaceId, $userId]
            );

            $versionRow = Database::queryOne(
                "SELECT COALESCE(MAX(version), 0) + 1 AS next_version
                 FROM user_strategy_snapshots
                 WHERE workspace_id = ?
                   AND user_id = ?",
                [$workspaceId, $userId]
            );
            $version = max(1, (int) ($versionRow['next_version'] ?? 1));
            $data = $source['data'];

            Database::execute(
                "INSERT INTO user_strategy_snapshots (
                    workspace_id, user_id, strategy_profile_id, idea_validation_id, version, source_hash, status,
                    target_market_focus, ideal_customer_profile, offer_angle, segment_focus, sales_motion,
                    deal_movement_strategy, outreach_posture, positioning_notes, market_view, strategy_hypothesis,
                    value_proposition, idea_target_market, pain_points, assumptions_to_test, competitors, differentiator,
                    source_json, started_at
                 ) VALUES (?, ?, ?, ?, ?, ?, 'active', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
                [
                    $workspaceId,
                    $userId,
                    $source['strategy_profile_id'],
                    $source['idea_validation_id'],
                    $version,
                    $hash,
                    $data['target_market_focus'],
                    $data['ideal_customer_profile'],
                    $data['offer_angle'],
                    $data['segment_focus'],
                    $data['sales_motion'],
                    $data['deal_movement_strategy'],
                    $data['outreach_posture'],
                    $data['positioning_notes'],
                    $data['market_view'],
                    $data['strategy_hypothesis'],
                    $data['value_proposition'],
                    $data['target_market'],
                    $data['pain_points'],
                    $data['assumptions_to_test'],
                    $data['competitors'],
                    $data['differentiator'],
                    json_encode($data, JSON_UNESCAPED_SLASHES),
                ]
            );

            if ($startedTransaction) {
                Database::commit();
            }
        } catch (\Throwable $e) {
            if ($startedTransaction && $pdo->inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }

        return $this->getActiveSnapshot($workspaceId, $userId);
    }

    public function getActiveSnapshot(int $workspaceId, int $userId): ?array
    {
        if ($workspaceId <= 0 || $userId <= 0 || !$this->tableReady()) {
            return null;
        }

        return Database::queryOne(
            "SELECT *
             FROM user_strategy_snapshots
             WHERE workspace_id = ?
               AND user_id = ?
               AND status = 'active'
             ORDER BY version DESC, id DESC
             LIMIT 1",
            [$workspaceId, $userId]
        );
    }

    public function getCurrentBrief(int $workspaceId, int $userId, bool $syncSnapshot = false): array
    {
        $source = $this->buildSource($workspaceId, $userId);
        $missing = $this->missingPersonalBriefRequirementsFromData($source['data']);
        $snapshot = $syncSnapshot && $missing === []
            ? $this->syncForUser($workspaceId, $userId)
            : $this->getActiveSnapshot($workspaceId, $userId);

        return [
            'strategy' => $source['strategy'],
            'idea_validation' => $source['idea_validation'],
            'source' => $source['data'],
            'personal_brief_ready' => $missing === [],
            'missing_requirements' => $missing,
            'active_strategy_snapshot' => $this->serializeSnapshot($snapshot),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listSnapshotsForWindow(int $workspaceId, string $start, string $end): array
    {
        if ($workspaceId <= 0 || !$this->tableReady()) {
            return [];
        }

        return Database::query(
            "SELECT s.*, u.first_name, u.last_name, u.email, u.role,
                    COALESCE(wd.name, legacy_d.name) AS department_name,
                    COALESCE(wr.slug, r.slug, wm.role_slug, u.role, 'general') AS access_role_slug,
                    COALESCE(wr.name, r.name, wm.role_slug, u.role, 'General') AS access_role_name
             FROM user_strategy_snapshots s
             INNER JOIN users u ON u.id = s.user_id
             INNER JOIN workspace_memberships wm
                ON wm.user_id = s.user_id
               AND wm.workspace_id = s.workspace_id
               AND wm.membership_status = 'active'
             LEFT JOIN departments wd ON wd.id = wm.department_id AND wd.workspace_id = wm.workspace_id
             LEFT JOIN departments legacy_d ON legacy_d.id = u.department_id
             LEFT JOIN workspace_user_roles wur ON wur.user_id = u.id AND wur.workspace_id = s.workspace_id
             LEFT JOIN roles wr ON wr.id = wur.role_id
             LEFT JOIN user_roles ur ON ur.user_id = u.id
             LEFT JOIN roles r ON r.id = ur.role_id
             WHERE s.workspace_id = ?
               AND s.started_at <= ?
               AND (s.ended_at IS NULL OR s.ended_at >= ?)
             ORDER BY s.started_at DESC, s.id DESC",
            [$workspaceId, $end, $start]
        );
    }

    public function serializeSnapshot(?array $snapshot): ?array
    {
        if (!$snapshot) {
            return null;
        }

        return [
            'id' => (int) ($snapshot['id'] ?? 0),
            'workspace_id' => (int) ($snapshot['workspace_id'] ?? 0),
            'user_id' => (int) ($snapshot['user_id'] ?? 0),
            'version' => (int) ($snapshot['version'] ?? 0),
            'status' => (string) ($snapshot['status'] ?? ''),
            'source_hash' => (string) ($snapshot['source_hash'] ?? ''),
            'summary' => $this->summarizeSnapshot($snapshot),
            'icp' => (string) ($snapshot['ideal_customer_profile'] ?? ''),
            'target_market_focus' => (string) ($snapshot['target_market_focus'] ?? ''),
            'market_view' => (string) ($snapshot['market_view'] ?? ''),
            'strategy_hypothesis' => (string) ($snapshot['strategy_hypothesis'] ?? ''),
            'competitors' => (string) ($snapshot['competitors'] ?? ''),
            'differentiator' => (string) ($snapshot['differentiator'] ?? ''),
            'started_at' => (string) ($snapshot['started_at'] ?? ''),
            'ended_at' => $snapshot['ended_at'] ?? null,
        ];
    }

    /**
     * @param array<string,mixed> $data
     * @return list<array<string,string>>
     */
    public function missingPersonalBriefRequirementsFromData(array $data): array
    {
        $missing = [];
        $hasIcp = $this->anyFilled($data, ['ideal_customer_profile', 'target_market_focus', 'target_market']);
        $hasMarketView = $this->anyFilled($data, ['market_view', 'target_market_focus', 'segment_focus']);
        $hasStrategy = $this->anyFilled($data, ['strategy_hypothesis', 'sales_motion', 'deal_movement_strategy', 'offer_angle']);
        $hasCompetition = $this->anyFilled($data, ['competitors', 'differentiator']);

        if (!$hasIcp) {
            $missing[] = $this->missingRequirement('ideal_customer_profile', 'Add your personal ICP or target market.');
        }
        if (!$hasMarketView) {
            $missing[] = $this->missingRequirement('market_view', 'Add your view of the market or segment.');
        }
        if (!$hasStrategy) {
            $missing[] = $this->missingRequirement('strategy_hypothesis', 'Add the strategy you want AI Coach to test.');
        }
        if (!$hasCompetition) {
            $missing[] = $this->missingRequirement('competitors', 'Add competitors or alternatives you are positioning against.');
        }

        return $missing;
    }

    public function summarizeSnapshot(array $snapshot): string
    {
        $parts = array_values(array_filter([
            trim((string) ($snapshot['target_market_focus'] ?? '')),
            trim((string) ($snapshot['ideal_customer_profile'] ?? '')),
            trim((string) ($snapshot['offer_angle'] ?? '')),
            trim((string) ($snapshot['strategy_hypothesis'] ?? '')),
        ]));

        return $parts === [] ? 'Personal strategy snapshot' : implode(' | ', array_slice($parts, 0, 3));
    }

    private function buildSource(int $workspaceId, int $userId): array
    {
        $strategy = $this->loadStrategy($workspaceId, $userId) ?? [];
        $idea = $this->loadIdeaValidation($workspaceId, $userId) ?? [];
        $data = [
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
            'value_proposition' => $this->clean($idea['value_proposition'] ?? ''),
            'target_market' => $this->clean($idea['target_market'] ?? ''),
            'pain_points' => $this->clean($idea['pain_points'] ?? ''),
            'assumptions_to_test' => $this->clean($idea['assumptions_to_test'] ?? ''),
            'competitors' => $this->clean($idea['competitors'] ?? ''),
            'differentiator' => $this->clean($idea['differentiator'] ?? ''),
        ];

        return [
            'strategy_profile_id' => isset($strategy['id']) ? (int) $strategy['id'] : null,
            'idea_validation_id' => isset($idea['id']) ? (int) $idea['id'] : null,
            'strategy' => $strategy,
            'idea_validation' => $idea,
            'data' => $data,
        ];
    }

    private function loadStrategy(int $workspaceId, int $userId): ?array
    {
        if (!Database::tableExists('user_strategy_profiles')) {
            return null;
        }

        $marketViewSelect = Database::columnExists('user_strategy_profiles', 'market_view')
            ? 'market_view'
            : "'' AS market_view";
        $strategyHypothesisSelect = Database::columnExists('user_strategy_profiles', 'strategy_hypothesis')
            ? 'strategy_hypothesis'
            : "'' AS strategy_hypothesis";

        if (!Database::columnExists('user_strategy_profiles', 'workspace_id')) {
            return Database::queryOne(
                "SELECT id, user_id, target_market_focus, ideal_customer_profile, offer_angle,
                        segment_focus, sales_motion, deal_movement_strategy, outreach_posture,
                        positioning_notes, {$marketViewSelect}, {$strategyHypothesisSelect}, created_at, updated_at
                 FROM user_strategy_profiles
                 WHERE user_id = ?
                 LIMIT 1",
                [$userId]
            );
        }

        return Database::queryOne(
            "SELECT id, workspace_id, user_id, target_market_focus, ideal_customer_profile, offer_angle,
                    segment_focus, sales_motion, deal_movement_strategy, outreach_posture,
                    positioning_notes, {$marketViewSelect}, {$strategyHypothesisSelect}, created_at, updated_at
             FROM user_strategy_profiles
             WHERE user_id = ?
               AND workspace_id IN (?, 0)
             ORDER BY CASE WHEN workspace_id = ? THEN 0 ELSE 1 END, id DESC
             LIMIT 1",
            [$userId, $workspaceId, $workspaceId]
        );
    }

    private function loadIdeaValidation(int $workspaceId, int $userId): ?array
    {
        if (!Database::tableExists('idea_validation_context')) {
            return null;
        }

        if (!Database::columnExists('idea_validation_context', 'workspace_id')) {
            return Database::queryOne(
                "SELECT id, user_id, value_proposition, target_market, pain_points,
                        assumptions_to_test, competitors, differentiator, created_at, updated_at
                 FROM idea_validation_context
                 WHERE user_id = ?
                 LIMIT 1",
                [$userId]
            );
        }

        return Database::queryOne(
            "SELECT id, workspace_id, user_id, value_proposition, target_market, pain_points,
                    assumptions_to_test, competitors, differentiator, created_at, updated_at
             FROM idea_validation_context
             WHERE user_id = ?
               AND workspace_id IN (?, 0)
             ORDER BY CASE WHEN workspace_id = ? THEN 0 ELSE 1 END, id DESC
             LIMIT 1",
            [$userId, $workspaceId, $workspaceId]
        );
    }

    private function hashSource(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_UNESCAPED_SLASHES) ?: '');
    }

    private function snapshotDataMatches(array $snapshot, array $data): bool
    {
        $sourceJson = (string) ($snapshot['source_json'] ?? '');
        if ($sourceJson === '') {
            return false;
        }

        $decoded = json_decode($sourceJson, true);
        if (!is_array($decoded)) {
            return false;
        }

        foreach ($data as $key => $value) {
            if ($this->clean($decoded[$key] ?? '') !== $this->clean($value)) {
                return false;
            }
        }

        return true;
    }

    private function hasSnapshotSignal(array $data): bool
    {
        foreach ($data as $value) {
            if (trim((string) $value) !== '') {
                return true;
            }
        }
        return false;
    }

    private function anyFilled(array $data, array $fields): bool
    {
        foreach ($fields as $field) {
            if (trim((string) ($data[$field] ?? '')) !== '') {
                return true;
            }
        }
        return false;
    }

    private function missingRequirement(string $field, string $message): array
    {
        return [
            'section' => 'personal_brief',
            'field' => $field,
            'label' => ucwords(str_replace('_', ' ', $field)),
            'message' => $message,
            'action' => 'personal_onboarding',
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

    private function tableReady(): bool
    {
        return Database::tableExists('user_strategy_snapshots');
    }
}
