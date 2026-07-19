<?php

namespace CRM\Services;

use CRM\Database;

class ContactStageHistoryService
{
    private const STAGES = ['new', 'contacted', 'qualified', 'proposal', 'negotiation', 'won', 'lost'];

    public function record(int $workspaceId, int $contactId, ?string $fromStage, string $toStage, ?int $actorUserId = null, string $source = 'contact_update'): int
    {
        $toStage = strtolower(trim($toStage));
        $fromStage = $fromStage !== null ? strtolower(trim($fromStage)) : null;
        if ($workspaceId <= 0 || $contactId <= 0 || $toStage === '' || $fromStage === $toStage) {
            return 0;
        }
        $existing = Database::queryOne(
            "SELECT id FROM contact_stage_transitions
             WHERE workspace_id = ? AND contact_id = ?
               AND COALESCE(from_stage, '') = COALESCE(?, '') AND to_stage = ?
               AND is_baseline = 0 AND occurred_at >= DATE_SUB(NOW(), INTERVAL 5 SECOND)
             ORDER BY id DESC LIMIT 1",
            [$workspaceId, $contactId, $fromStage, $toStage]
        );
        if ($existing) {
            return (int) $existing['id'];
        }
        Database::execute(
            "INSERT INTO contact_stage_transitions
                (workspace_id, contact_id, from_stage, to_stage, changed_by, source, is_baseline, occurred_at, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?, 0, NOW(), JSON_OBJECT('conversion_eligible', TRUE))",
            [$workspaceId, $contactId, $fromStage, $toStage, $actorUserId, $source]
        );
        return (int) Database::lastInsertId();
    }

    public function distribution(int $workspaceId, string $start, string $end, ?int $userId = null, string $roleFamily = '', string $department = ''): array
    {
        // A distribution is a point-in-time inventory. The measurement window is
        // deliberately not applied; doing so would omit older contacts that are
        // still in the current pipeline.
        $where = ['c.workspace_id = ?'];
        $params = [$workspaceId];
        if ($userId !== null && $userId > 0) {
            $where[] = 'c.assigned_to = ?';
            $params[] = $userId;
        }
        $roleExpression = "LOWER(COALESCE(wm.role_slug, u.role, ''))";
        if ($roleFamily === 'marketing') {
            $where[] = "{$roleExpression} LIKE '%marketing%'";
        } elseif ($roleFamily === 'sales') {
            $where[] = "{$roleExpression} LIKE '%sales%'";
        } elseif ($roleFamily === 'general') {
            $where[] = "{$roleExpression} NOT LIKE '%marketing%' AND {$roleExpression} NOT LIKE '%sales%'";
        }
        if (trim($department) !== '') {
            $where[] = '(LOWER(d.slug) = ? OR LOWER(d.name) = ?)';
            $params[] = strtolower(trim($department));
            $params[] = strtolower(trim($department));
        }
        $rows = Database::query(
            "SELECT c.stage, COUNT(*) AS count
             FROM contacts c
             LEFT JOIN workspace_memberships wm ON wm.workspace_id = c.workspace_id AND wm.user_id = c.assigned_to AND wm.membership_status = 'active'
             LEFT JOIN users u ON u.id = c.assigned_to
             LEFT JOIN departments d ON d.id = wm.department_id AND d.workspace_id = c.workspace_id
             WHERE " . implode(' AND ', $where) . ' GROUP BY c.stage',
            $params
        );
        $counts = [];
        foreach ($rows as $row) {
            $counts[strtolower((string) $row['stage'])] = (int) $row['count'];
        }
        $total = array_sum($counts);
        $stages = [];
        foreach (self::STAGES as $stage) {
            $count = (int) ($counts[$stage] ?? 0);
            $stages[$stage] = [
                'count' => $count,
                'share' => $total > 0 ? round(($count / $total) * 100, 1) : 0.0,
                'conversion_rate' => null,
                'drop_off_rate' => null,
                'avg_dwell_days' => null,
                'basis' => 'current_stage_distribution',
            ];
        }
        return [
            'total_count' => $total,
            'cohort_size' => $total,
            'basis' => 'current_stage_distribution',
            'measurement_type' => 'point_in_time',
            'stages' => $stages,
        ];
    }

    public function journey(int $workspaceId, string $start, string $end, ?int $userId = null, string $roleFamily = '', string $department = ''): array
    {
        $where = ['t.workspace_id = ?', 't.is_baseline = 0', 't.occurred_at BETWEEN ? AND ?'];
        $params = [$workspaceId, $start, $end];
        if ($userId !== null && $userId > 0) {
            $where[] = 'c.assigned_to = ?';
            $params[] = $userId;
        }
        $roleExpression = "LOWER(COALESCE(wm.role_slug, u.role, ''))";
        if ($roleFamily === 'marketing') {
            $where[] = "{$roleExpression} LIKE '%marketing%'";
        } elseif ($roleFamily === 'sales') {
            $where[] = "{$roleExpression} LIKE '%sales%'";
        } elseif ($roleFamily === 'general') {
            $where[] = "{$roleExpression} NOT LIKE '%marketing%' AND {$roleExpression} NOT LIKE '%sales%'";
        }
        if (trim($department) !== '') {
            $where[] = '(LOWER(d.slug) = ? OR LOWER(d.name) = ?)';
            $params[] = strtolower(trim($department));
            $params[] = strtolower(trim($department));
        }
        $rows = Database::query(
            "SELECT t.from_stage, t.to_stage, COUNT(*) AS transition_count
             FROM contact_stage_transitions t
             JOIN contacts c ON c.id = t.contact_id AND c.workspace_id = t.workspace_id
             LEFT JOIN workspace_memberships wm ON wm.workspace_id = t.workspace_id AND wm.user_id = c.assigned_to AND wm.membership_status = 'active'
             LEFT JOIN users u ON u.id = c.assigned_to
             LEFT JOIN departments d ON d.id = wm.department_id AND d.workspace_id = t.workspace_id
             WHERE " . implode(' AND ', $where) . "
             GROUP BY t.from_stage, t.to_stage",
            $params
        );
        $eligible = array_sum(array_map(static fn(array $row): int => (int) $row['transition_count'], $rows));
        $fromTotals = [];
        foreach ($rows as $row) {
            $from = (string) ($row['from_stage'] ?? '');
            $fromTotals[$from] = ($fromTotals[$from] ?? 0) + (int) ($row['transition_count'] ?? 0);
        }
        $journeyRows = array_map(static function (array $row) use ($fromTotals): array {
            $from = (string) ($row['from_stage'] ?? '');
            $count = (int) ($row['transition_count'] ?? 0);
            return [
                'from_stage' => $from,
                'to_stage' => (string) ($row['to_stage'] ?? ''),
                'transition_count' => $count,
                'transition_share' => ($fromTotals[$from] ?? 0) > 0 ? round(($count / $fromTotals[$from]) * 100, 1) : null,
                'avg_dwell_days' => null,
            ];
        }, $rows);
        return [
            'available' => $eligible >= 20,
            'sample_size' => $eligible,
            'minimum_sample_size' => 20,
            'basis' => 'durable_stage_transitions',
            'transitions' => $eligible >= 20 ? $journeyRows : [],
        ];
    }
}
