<?php
/**
 * Campaign Orchestrator
 *
 * Creates, updates, launches, and controls campaign execution.
 */

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\Contacts;
use CRM\Security;

class CampaignOrchestrator
{
    private CampaignEnrollmentService $enrollmentService;
    private Contacts $contacts;
    private WorkspaceScopeService $workspaceScope;

    public function __construct()
    {
        $this->enrollmentService = new CampaignEnrollmentService();
        $this->contacts = new Contacts();
        $this->workspaceScope = new WorkspaceScopeService();
    }

    public function createCampaign(array $data): int
    {
        $name = Security::sanitizeInput($data['name'] ?? '', 'string');
        if ($name === '') {
            throw new \InvalidArgumentException('Campaign name is required');
        }

        $description = Security::sanitizeInput($data['description'] ?? '', 'string');
        $objective = Security::sanitizeInput($data['objective'] ?? 'nurture', 'string');
        $requestedScheduleType = (string) ($data['schedule_type'] ?? 'immediate');
        $scheduleType = in_array($requestedScheduleType, ['immediate', 'scheduled'], true)
            ? $requestedScheduleType
            : 'immediate';

        Database::execute(
            "INSERT INTO campaigns
             (workspace_id, uuid, name, description, objective, channel_mix, status, schedule_type, scheduled_start_at, scheduled_end_at, timezone, attribution_model_default, created_by)
             VALUES (?, ?, ?, ?, ?, ?, 'draft', ?, ?, ?, ?, ?, ?)",
            [
                $this->workspaceId(),
                $this->generateUuid(),
                $name,
                $description ?: null,
                $objective,
                json_encode($data['channel_mix'] ?? ['email']),
                $scheduleType,
                !empty($data['scheduled_start_at']) ? $data['scheduled_start_at'] : null,
                !empty($data['scheduled_end_at']) ? $data['scheduled_end_at'] : null,
                Security::sanitizeInput($data['timezone'] ?? 'UTC', 'string'),
                Security::sanitizeInput($data['attribution_model_default'] ?? 'last_touch', 'string'),
                (int) ($data['created_by'] ?? ($_SESSION['user_id'] ?? 0)) ?: null
            ]
        );

        $campaignId = (int) Database::lastInsertId();
        if (!empty($data['steps']) && is_array($data['steps'])) {
            $this->replaceSteps($campaignId, $data['steps']);
        }

        return $campaignId;
    }

    public function updateCampaign(int $campaignId, array $data): bool
    {
        $campaign = $this->getCampaignById($campaignId);
        if (!$campaign) {
            throw new \RuntimeException('Campaign not found');
        }

        $updates = [];
        $params = [];
        $allowed = ['name', 'description', 'objective', 'schedule_type', 'scheduled_start_at', 'scheduled_end_at', 'timezone', 'attribution_model_default', 'status', 'is_active'];

        foreach ($allowed as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }

            $updates[] = $field . ' = ?';
            if (in_array($field, ['name', 'description', 'objective', 'timezone', 'attribution_model_default', 'status', 'schedule_type'], true)) {
                $params[] = Security::sanitizeInput((string) ($data[$field] ?? ''), 'string');
            } else {
                $params[] = $data[$field];
            }
        }

        if (isset($data['channel_mix'])) {
            $updates[] = 'channel_mix = ?';
            $params[] = json_encode($data['channel_mix']);
        }

        if ($updates) {
            $params[] = $this->workspaceId();
            $params[] = $campaignId;
            Database::execute("UPDATE campaigns SET " . implode(', ', $updates) . " WHERE workspace_id = ? AND id = ?", $params);
        }

        if (isset($data['steps']) && is_array($data['steps'])) {
            $this->replaceSteps($campaignId, $data['steps']);
        }

        return true;
    }

    public function replaceSteps(int $campaignId, array $steps): void
    {
        $workspaceId = $this->workspaceIdForCampaign($campaignId);
        Database::execute(
            "DELETE FROM campaign_steps WHERE workspace_id = ? AND campaign_id = ?",
            [$workspaceId, $campaignId]
        );

        $order = 1;
        foreach ($steps as $step) {
            $action = Security::sanitizeInput($step['action_type'] ?? 'wait', 'string');
            $channel = Security::sanitizeInput($step['channel'] ?? 'email', 'string');
            $stepName = Security::sanitizeInput($step['step_name'] ?? ('Step ' . $order), 'string');

            Database::execute(
                "INSERT INTO campaign_steps
                 (workspace_id, campaign_id, step_order, step_name, action_type, channel, template_ref, subject, content, wait_minutes, branch_condition, settings, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $workspaceId,
                    $campaignId,
                    $order,
                    $stepName,
                    $action,
                    $channel,
                    !empty($step['template_ref']) ? Security::sanitizeInput($step['template_ref'], 'string') : null,
                    !empty($step['subject']) ? Security::sanitizeInput($step['subject'], 'string') : null,
                    !empty($step['content']) ? (string) $step['content'] : null,
                    (int) ($step['wait_minutes'] ?? 0),
                    isset($step['branch_condition']) ? json_encode($step['branch_condition']) : null,
                    isset($step['settings']) ? json_encode($step['settings']) : null,
                    isset($step['is_active']) ? (int) (bool) $step['is_active'] : 1
                ]
            );
            $order++;
        }
    }

    public function launchCampaign(int $campaignId, array $filters = [], bool $snapshotAudience = true): array
    {
        $campaign = $this->getCampaignById($campaignId);
        if (!$campaign) {
            throw new \RuntimeException('Campaign not found');
        }

        $steps = $this->getCampaignSteps($campaignId);
        if (empty($steps)) {
            throw new \RuntimeException('Campaign requires at least one active step');
        }

        $contacts = $this->contacts->getByFilters($filters, 5000, 0);
        $contactIds = array_map(static fn(array $c): int => (int) $c['id'], $contacts);

        if ($snapshotAudience) {
            Database::execute(
                "INSERT INTO campaign_audience_snapshots (workspace_id, campaign_id, snapshot_name, filters, contact_ids, snapshot_count, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                [
                    $this->workspaceId(),
                    $campaignId,
                    'Launch ' . date('Y-m-d H:i:s'),
                    json_encode($filters),
                    json_encode($contactIds),
                    count($contactIds),
                    (int) ($_SESSION['user_id'] ?? 0) ?: null
                ]
            );
        }

        $enrollment = $this->enrollmentService->enrollContacts($campaignId, $contactIds, [
            'next_run_at' => date('Y-m-d H:i:s'),
            'metadata' => ['filters' => $filters]
        ]);

        Database::execute(
            "UPDATE campaigns SET status = 'active', is_active = 1 WHERE workspace_id = ? AND id = ?",
            [$this->workspaceId(), $campaignId]
        );

        return [
            'campaign_id' => $campaignId,
            'audience_count' => count($contactIds),
            'enrollment' => $enrollment
        ];
    }

    public function pauseCampaign(int $campaignId): void
    {
        if (!$this->getCampaignById($campaignId)) {
            throw new \RuntimeException('Campaign not found');
        }
        Database::execute("UPDATE campaigns SET status = 'paused', is_active = 0 WHERE workspace_id = ? AND id = ?", [$this->workspaceId(), $campaignId]);
    }

    public function resumeCampaign(int $campaignId): void
    {
        if (!$this->getCampaignById($campaignId)) {
            throw new \RuntimeException('Campaign not found');
        }
        Database::execute("UPDATE campaigns SET status = 'active', is_active = 1 WHERE workspace_id = ? AND id = ?", [$this->workspaceId(), $campaignId]);
    }

    public function deleteCampaign(int $campaignId): void
    {
        $campaign = $this->getCampaignById($campaignId);
        if (!$campaign) {
            throw new \RuntimeException('Campaign not found');
        }

        Database::execute("DELETE FROM campaigns WHERE workspace_id = ? AND id = ?", [$this->workspaceId(), $campaignId]);
    }

    public function getCampaignById(int $campaignId): ?array
    {
        $workspace = $this->workspaceClause('c.');
        $campaign = Database::queryOne(
            "SELECT c.*, u.email as created_by_email
             FROM campaigns c
             LEFT JOIN users u ON c.created_by = u.id
             WHERE {$workspace['sql']} AND c.id = ?",
            array_merge($workspace['params'], [$campaignId])
        );
        if (!$campaign) {
            return null;
        }
        $campaign['channel_mix'] = json_decode($campaign['channel_mix'] ?? '[]', true) ?? [];
        $campaign['steps'] = $this->getCampaignSteps($campaignId);
        return $campaign;
    }

    public function listCampaigns(array $filters = []): array
    {
        $where = [];
        $workspace = $this->workspaceClause('c.');
        $where[] = $workspace['sql'];
        $params = $workspace['params'];

        if (!empty($filters['status'])) {
            $where[] = "c.status = ?";
            $params[] = Security::sanitizeInput((string) $filters['status'], 'string');
        }

        $sql = "SELECT c.*,
                       (SELECT COUNT(*) FROM campaign_enrollments ce WHERE ce.campaign_id = c.id) as enrollment_count,
                       (SELECT COUNT(*) FROM campaign_step_executions cse WHERE cse.campaign_id = c.id AND cse.status = 'completed') as completed_steps
                FROM campaigns c";

        if ($where) {
            $sql .= " WHERE " . implode(' AND ', $where);
        }
        $sql .= " ORDER BY c.updated_at DESC";

        return Database::query($sql, $params);
    }

    public function getCampaignSteps(int $campaignId): array
    {
        $workspace = $this->workspaceClause();
        $steps = Database::query(
            "SELECT * FROM campaign_steps WHERE {$workspace['sql']} AND campaign_id = ? AND is_active = 1 ORDER BY step_order ASC",
            array_merge($workspace['params'], [$campaignId])
        );

        foreach ($steps as &$step) {
            $step['branch_condition'] = json_decode($step['branch_condition'] ?? 'null', true);
            $step['settings'] = json_decode($step['settings'] ?? 'null', true);
        }
        return $steps;
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * @return array{sql:string, params:array<int, int>}
     */
    private function workspaceClause(string $alias = '', string $column = 'workspace_id'): array
    {
        return $this->workspaceScope->workspaceClause($alias, $column);
    }

    private function workspaceId(): int
    {
        return $this->workspaceScope->requireActiveWorkspaceId();
    }

    private function workspaceIdForCampaign(int $campaignId): int
    {
        $campaign = $this->getCampaignById($campaignId);
        if (!$campaign) {
            throw new \RuntimeException('Campaign not found');
        }

        return (int) $campaign['workspace_id'];
    }
}
