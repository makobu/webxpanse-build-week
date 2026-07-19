<?php

namespace CRM\Services;

use CRM\Database;

class WorkflowScheduledTriggerService
{
    private WorkflowQueueService $queueService;

    public function __construct()
    {
        $this->queueService = new WorkflowQueueService();
    }

    public function processScheduledTriggers(?\DateTimeInterface $now = null): int
    {
        $now = $now ?? new \DateTimeImmutable();
        $currentTime = $now->format('H:i');
        $currentDay = strtolower($now->format('l'));
        $currentDate = (int) $now->format('j');
        $currentDateString = $now->format('Y-m-d');

        $workflows = Database::query(
            "SELECT id, workspace_id, trigger_config
             FROM workflows
             WHERE is_active = 1
               AND trigger_config IS NOT NULL",
            []
        );

        $fired = 0;
        foreach ($workflows as $workflow) {
            $workspaceId = (int) ($workflow['workspace_id'] ?? 0);
            if ($workspaceId <= 0) {
                error_log('Workflow scheduled trigger skipped: workflow ' . (int) ($workflow['id'] ?? 0) . ' is missing workspace_id');
                continue;
            }

            $fired += (int) AsyncWorkspaceRunner::runWithWorkspace(
                $workspaceId,
                function () use ($workflow, $workspaceId, $currentTime, $currentDay, $currentDate, $currentDateString): int {
                    $config = json_decode((string) ($workflow['trigger_config'] ?? ''), true);
                    if (!$config || empty($config['type'])) {
                        return 0;
                    }

                    return $this->dispatchTrigger(
                        (int) $workflow['id'],
                        $workspaceId,
                        (string) $config['type'],
                        $config,
                        $currentTime,
                        $currentDay,
                        $currentDate,
                        $currentDateString
                    );
                },
                null,
                'Workflow scheduled trigger is missing a valid workspace.'
            );
        }

        return $fired;
    }

    private function dispatchTrigger(
        int $workflowId,
        int $workspaceId,
        string $triggerType,
        array $config,
        string $currentTime,
        string $currentDay,
        int $currentDate,
        string $currentDateString
    ): int {
        return match ($triggerType) {
            'no_activity_for_days' => $this->fireNoActivityTrigger($workflowId, $workspaceId, $config),
            'task_overdue' => $this->fireTaskOverdueTrigger($workflowId, $workspaceId),
            'deal_closing_soon' => $this->fireDealClosingSoonTrigger($workflowId, $workspaceId, $config),
            'daily_at_time' => $this->fireDailyTrigger($workflowId, $workspaceId, $config, $currentTime),
            'weekly_on_day' => $this->fireWeeklyTrigger($workflowId, $workspaceId, $config, $currentTime, $currentDay),
            'monthly_on_date' => $this->fireMonthlyTrigger($workflowId, $workspaceId, $config, $currentTime, $currentDate),
            'contact_anniversary' => $this->fireContactAnniversaryTrigger($workflowId, $workspaceId, $currentDateString),
            'contact_birthday' => $this->fireContactBirthdayTrigger($workflowId, $workspaceId, $currentDateString),
            default => 0,
        };
    }

    private function fireNoActivityTrigger(int $workflowId, int $workspaceId, array $config): int
    {
        $days = (int) ($config['days'] ?? 7);
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        $contacts = Database::query(
            "SELECT c.id
             FROM contacts c
             WHERE c.workspace_id = ?
               AND NOT EXISTS (
                   SELECT 1
                   FROM activities a
                   WHERE a.workspace_id = c.workspace_id
                     AND a.contact_id = c.id
                     AND a.created_at > ?
               )
               AND (c.updated_at < ? OR c.updated_at IS NULL)",
            [$workspaceId, $cutoff, $cutoff]
        );

        return $this->enqueueContacts($workflowId, $workspaceId, $contacts, static fn(array $contact): array => [
            'contact_id' => (int) $contact['id'],
            'days_since_activity' => $days,
        ]);
    }

    private function fireTaskOverdueTrigger(int $workflowId, int $workspaceId): int
    {
        $tasks = Database::query(
            "SELECT t.id, t.contact_id
             FROM tasks t
             WHERE t.workspace_id = ?
               AND t.status NOT IN ('completed', 'cancelled')
               AND t.due_date IS NOT NULL
               AND t.due_date < NOW()
               AND t.contact_id IS NOT NULL",
            [$workspaceId]
        );

        $count = 0;
        foreach ($tasks as $task) {
            $contactId = (int) ($task['contact_id'] ?? 0);
            if ($contactId <= 0) {
                continue;
            }

            $this->queueService->addToQueue($workflowId, $contactId, [
                'contact_id' => $contactId,
                'task_id' => (int) ($task['id'] ?? 0),
            ], $workspaceId);
            $count++;
        }

        return $count;
    }

    private function fireDealClosingSoonTrigger(int $workflowId, int $workspaceId, array $config): int
    {
        $daysAhead = (int) ($config['days'] ?? 7);
        $futureDate = date('Y-m-d', strtotime("+{$daysAhead} days"));
        $deals = Database::query(
            "SELECT d.id, d.contact_id
             FROM deals d
             WHERE d.workspace_id = ?
               AND d.contact_id IS NOT NULL
               AND d.expected_close_date IS NOT NULL
               AND d.expected_close_date <= ?
               AND d.stage NOT IN ('closed_won', 'closed_lost')",
            [$workspaceId, $futureDate]
        );

        $count = 0;
        foreach ($deals as $deal) {
            $contactId = (int) ($deal['contact_id'] ?? 0);
            if ($contactId <= 0) {
                continue;
            }

            $this->queueService->addToQueue($workflowId, $contactId, [
                'contact_id' => $contactId,
                'deal_id' => (int) ($deal['id'] ?? 0),
            ], $workspaceId);
            $count++;
        }

        return $count;
    }

    private function fireDailyTrigger(int $workflowId, int $workspaceId, array $config, string $currentTime): int
    {
        $triggerTime = (string) ($config['time'] ?? '09:00');
        if (substr($triggerTime, 0, 5) !== substr($currentTime, 0, 5)) {
            return 0;
        }

        $contacts = Database::query(
            "SELECT id
             FROM contacts
             WHERE workspace_id = ?
               AND updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
             LIMIT 500",
            [$workspaceId]
        );

        return $this->enqueueContacts($workflowId, $workspaceId, $contacts, static fn(array $contact): array => [
            'contact_id' => (int) $contact['id'],
        ]);
    }

    private function fireWeeklyTrigger(int $workflowId, int $workspaceId, array $config, string $currentTime, string $currentDay): int
    {
        $day = strtolower((string) ($config['day'] ?? 'monday'));
        $triggerTime = (string) ($config['time'] ?? '09:00');
        if ($currentDay !== $day || substr($triggerTime, 0, 5) !== substr($currentTime, 0, 5)) {
            return 0;
        }

        $contacts = Database::query(
            "SELECT id
             FROM contacts
             WHERE workspace_id = ?
               AND updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
             LIMIT 500",
            [$workspaceId]
        );

        return $this->enqueueContacts($workflowId, $workspaceId, $contacts, static fn(array $contact): array => [
            'contact_id' => (int) $contact['id'],
        ]);
    }

    private function fireMonthlyTrigger(int $workflowId, int $workspaceId, array $config, string $currentTime, int $currentDate): int
    {
        $date = (int) ($config['date'] ?? 1);
        $triggerTime = (string) ($config['time'] ?? '09:00');
        if ($currentDate !== $date || substr($triggerTime, 0, 5) !== substr($currentTime, 0, 5)) {
            return 0;
        }

        $contacts = Database::query(
            "SELECT id
             FROM contacts
             WHERE workspace_id = ?
             LIMIT 1000",
            [$workspaceId]
        );

        return $this->enqueueContacts($workflowId, $workspaceId, $contacts, static fn(array $contact): array => [
            'contact_id' => (int) $contact['id'],
        ]);
    }

    private function fireContactAnniversaryTrigger(int $workflowId, int $workspaceId, string $currentDate): int
    {
        $anniversaryDate = (new \DateTimeImmutable($currentDate))->modify('-1 year')->format('Y-m-d');
        $contacts = Database::query(
            "SELECT id
             FROM contacts
             WHERE workspace_id = ?
               AND DATE(created_at) = ?",
            [$workspaceId, $anniversaryDate]
        );

        return $this->enqueueContacts($workflowId, $workspaceId, $contacts, static fn(array $contact): array => [
            'contact_id' => (int) $contact['id'],
        ]);
    }

    private function fireContactBirthdayTrigger(int $workflowId, int $workspaceId, string $currentDate): int
    {
        $birthdayField = Database::queryOne(
            "SELECT id
             FROM custom_fields
             WHERE module = 'contacts'
               AND (field_name = 'birthday' OR field_name = 'birth_date')
             LIMIT 1",
            []
        );
        if (!$birthdayField) {
            return 0;
        }

        $birthdayContacts = Database::query(
            "SELECT c.id
             FROM contacts c
             INNER JOIN contact_custom_data ccd
                 ON ccd.contact_id = c.id
                AND ccd.field_id = ?
             WHERE c.workspace_id = ?
               AND DATE(ccd.field_value) = ?",
            [$birthdayField['id'], $workspaceId, $currentDate]
        );

        return $this->enqueueContacts($workflowId, $workspaceId, $birthdayContacts, static fn(array $contact): array => [
            'contact_id' => (int) $contact['id'],
        ]);
    }

    /**
     * @param array<int,array<string,mixed>> $contacts
     * @param callable(array<string,mixed>):array<string,mixed> $eventBuilder
     */
    private function enqueueContacts(int $workflowId, int $workspaceId, array $contacts, callable $eventBuilder): int
    {
        $count = 0;
        foreach ($contacts as $contact) {
            $contactId = (int) ($contact['id'] ?? 0);
            if ($contactId <= 0) {
                continue;
            }

            $eventData = $eventBuilder($contact);
            $this->queueService->addToQueue($workflowId, $contactId, $eventData, $workspaceId);
            $count++;
        }

        return $count;
    }
}
