<?php
/**
 * Outcome Event Service
 *
 * Tracks outcome events and updates first-time activation milestones.
 */

namespace CRM\Services;

use CRM\Database;

class OutcomeEventService
{
    private const VALID_EVENT_KEYS = [
        'user.first_login',
        'channel.connected',
        'contact.created',
        'inbound.processed',
        'task.followup.completed',
        'deal.created',
        'deal.advanced',
        'dashboard.guidance.viewed',
        'dashboard.guidance.clicked',
        'dashboard.readiness.viewed',
        'dashboard.readiness.clicked',
        'work_surface.guidance.viewed',
        'work_surface.guidance.clicked',
        'work_surface.ai_help.requested',
    ];

    private const EVENT_MILESTONE_MAP = [
        'user.first_login' => 'first_login_at',
        'channel.connected' => 'connected_channel_at',
        'contact.created' => 'first_contact_at',
        'inbound.processed' => 'first_inbound_at',
        'task.followup.completed' => 'first_followup_task_completed_at',
        'deal.created' => 'first_deal_created_at',
        'deal.advanced' => 'first_deal_advanced_at',
    ];

    private static ?bool $hasOutcomeEventsTable = null;
    private static ?bool $hasActivationProgressTable = null;

    public function track(string $eventKey, array $payload): void
    {
        if (!in_array($eventKey, self::VALID_EVENT_KEYS, true)) {
            return;
        }
        if (!$this->hasOutcomeEventsTable()) {
            return;
        }

        $userId = (int) ($payload['user_id'] ?? ($_SESSION['user_id'] ?? 0));
        $contactId = !empty($payload['contact_id']) ? (int) $payload['contact_id'] : null;
        $dealId = !empty($payload['deal_id']) ? (int) $payload['deal_id'] : null;
        $eventSource = trim((string) ($payload['event_source'] ?? 'system'));
        $eventAt = (string) ($payload['event_at'] ?? date('Y-m-d H:i:s'));
        $metadata = $payload['metadata'] ?? [];
        if (!is_array($metadata)) {
            $metadata = ['value' => (string) $metadata];
        }

        try {
            Database::execute(
                "INSERT INTO outcome_events (user_id, contact_id, deal_id, event_key, event_source, event_at, metadata)
                 VALUES (?, ?, ?, ?, ?, ?, ?)",
                [
                    $userId > 0 ? $userId : null,
                    $contactId,
                    $dealId,
                    $eventKey,
                    $eventSource !== '' ? $eventSource : 'system',
                    $eventAt,
                    json_encode($metadata),
                ]
            );
        } catch (\Throwable $e) {
            error_log('OutcomeEventService::track: ' . $e->getMessage());
        }

        $milestone = self::EVENT_MILESTONE_MAP[$eventKey] ?? null;
        if ($milestone && $userId > 0) {
            $this->markActivationMilestone($userId, $milestone, $eventAt);
        }
    }

    public function markActivationMilestone(int $userId, string $milestoneKey, ?string $at = null): void
    {
        if ($userId <= 0 || !$this->hasActivationProgressTable()) {
            return;
        }

        $allowed = array_values(self::EVENT_MILESTONE_MAP);
        if (!in_array($milestoneKey, $allowed, true)) {
            return;
        }

        $atValue = $at ?: date('Y-m-d H:i:s');
        try {
            Database::execute(
                "INSERT INTO activation_progress (user_id, {$milestoneKey})
                 VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE {$milestoneKey} = COALESCE({$milestoneKey}, VALUES({$milestoneKey}))",
                [$userId, $atValue]
            );
        } catch (\Throwable $e) {
            error_log('OutcomeEventService::markActivationMilestone: ' . $e->getMessage());
        }
    }

    public function getActivationProgress(int $userId): array
    {
        $default = [
            'user_id' => $userId,
            'first_login_at' => null,
            'connected_channel_at' => null,
            'first_contact_at' => null,
            'first_inbound_at' => null,
            'first_followup_task_completed_at' => null,
            'first_deal_created_at' => null,
            'first_deal_advanced_at' => null,
        ];
        if ($userId <= 0 || !$this->hasActivationProgressTable()) {
            return $default;
        }

        try {
            $row = Database::queryOne("SELECT * FROM activation_progress WHERE user_id = ?", [$userId]);
            if (!$row) {
                return $default;
            }
            return array_merge($default, $row);
        } catch (\Throwable $e) {
            error_log('OutcomeEventService::getActivationProgress: ' . $e->getMessage());
            return $default;
        }
    }

    public function isQualifiedFollowUpTask(array $task, array $changes = []): bool
    {
        $contactId = (int) ($task['contact_id'] ?? 0);
        $status = (string) ($changes['status'] ?? $task['status'] ?? '');
        if ($contactId <= 0 || $status !== 'completed') {
            return false;
        }

        $title = strtolower((string) ($changes['title'] ?? $task['title'] ?? ''));
        $description = strtolower((string) ($changes['description'] ?? $task['description'] ?? ''));
        $signalText = trim($title . ' ' . $description);
        if ($signalText === '') {
            return false;
        }

        if (strpos($description, '[inbox_triage]') !== false || strpos($description, '[ai-coach][auto]') !== false) {
            return true;
        }

        $keywords = [
            'follow up',
            'follow-up',
            'followup',
            'outreach',
            'reply',
            'call back',
            'callback',
            'check in',
            'next step',
        ];
        foreach ($keywords as $keyword) {
            if (strpos($signalText, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    private function hasOutcomeEventsTable(): bool
    {
        if (self::$hasOutcomeEventsTable !== null) {
            return self::$hasOutcomeEventsTable;
        }
        try {
            $row = Database::queryOne(
                "SELECT COUNT(*) AS c FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'outcome_events'"
            );
            self::$hasOutcomeEventsTable = ((int) ($row['c'] ?? 0)) > 0;
        } catch (\Throwable $e) {
            self::$hasOutcomeEventsTable = false;
        }
        return self::$hasOutcomeEventsTable;
    }

    private function hasActivationProgressTable(): bool
    {
        if (self::$hasActivationProgressTable !== null) {
            return self::$hasActivationProgressTable;
        }
        try {
            $row = Database::queryOne(
                "SELECT COUNT(*) AS c FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activation_progress'"
            );
            self::$hasActivationProgressTable = ((int) ($row['c'] ?? 0)) > 0;
        } catch (\Throwable $e) {
            self::$hasActivationProgressTable = false;
        }
        return self::$hasActivationProgressTable;
    }
}
