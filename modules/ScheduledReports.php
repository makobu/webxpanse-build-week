<?php
/**
 * Scheduled Reports Module
 * 
 * Handles scheduled report generation and delivery
 */

namespace CRM\Modules;

use CRM\Concurrency;
use CRM\Database;
use CRM\Security;
use CRM\Services\WorkspaceContext;

class ScheduledReports
{
    /**
     * Create a scheduled report
     */
    public function create(array $data): int
    {
        // Validate required fields
        if (empty($data['report_id']) || empty($data['schedule_name']) || empty($data['schedule_type'])) {
            throw new \Exception("Report ID, schedule name, and schedule type are required");
        }
        
        // Sanitize inputs
        $reportId = (int) $data['report_id'];
        $scheduleName = Security::sanitizeInput($data['schedule_name'], 'string');
        $scheduleType = Security::sanitizeInput($data['schedule_type'], 'string');
        $scheduleConfigValue = $data['schedule_config'] ?? [];
        if (is_string($scheduleConfigValue)) {
            $decodedScheduleConfig = json_decode($scheduleConfigValue, true);
            $scheduleConfigArray = is_array($decodedScheduleConfig) ? $decodedScheduleConfig : [];
        } else {
            $scheduleConfigArray = is_array($scheduleConfigValue) ? $scheduleConfigValue : [];
        }
        $scheduleConfig = json_encode($scheduleConfigArray);

        $recipientsValue = $data['recipients'] ?? [];
        if (is_string($recipientsValue)) {
            $decodedRecipients = json_decode($recipientsValue, true);
            $recipientsArray = is_array($decodedRecipients) ? $decodedRecipients : array_values(array_filter(array_map('trim', explode(',', $recipientsValue))));
        } else {
            $recipientsArray = is_array($recipientsValue) ? $recipientsValue : [];
        }
        $recipients = json_encode($recipientsArray);
        $format = Security::sanitizeInput($data['format'] ?? 'csv', 'string');
        $isActive = isset($data['is_active']) ? (int) $data['is_active'] : 1;
        $createdBy = (int) ($data['created_by'] ?? $_SESSION['user_id'] ?? 0);
        $workspaceId = $this->requireWorkspaceId();
        
        // Validate schedule type
        $allowedTypes = ['daily', 'weekly', 'monthly', 'custom'];
        if (!in_array($scheduleType, $allowedTypes)) {
            throw new \Exception("Invalid schedule type");
        }
        
        // Validate format
        $allowedFormats = ['csv', 'pdf', 'excel'];
        if (!in_array($format, $allowedFormats, true)) {
            $format = 'csv';
        }
        if (!(new Reports())->isExportFormatAvailable($format)) {
            throw new \Exception((new Reports())->getExportUnavailableMessage($format));
        }
        
        // Calculate next run time
        $nextRunAt = $this->calculateNextRunTime($scheduleType, $scheduleConfigArray);
        
        Database::execute(
            "INSERT INTO scheduled_reports (workspace_id, report_id, schedule_name, schedule_type, schedule_config, recipients, format, is_active, next_run_at, created_by) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$workspaceId, $reportId, $scheduleName, $scheduleType, $scheduleConfig, $recipients, $format, $isActive, $nextRunAt, $createdBy]
        );
        
        return (int) Database::lastInsertId();
    }
    
    /**
     * Get scheduled report by ID
     */
    public function getById(int $id): ?array
    {
        $schedule = Database::queryOne(
            "SELECT sr.*, r.name as report_name, r.description as report_description, u.email as created_by_email
             FROM scheduled_reports sr
             LEFT JOIN reports r ON sr.report_id = r.id AND r.workspace_id = sr.workspace_id
             LEFT JOIN users u ON sr.created_by = u.id
             WHERE sr.workspace_id = ?
               AND sr.id = ?",
            [$this->requireWorkspaceId(), $id]
        );
        
        if ($schedule) {
            $schedule['schedule_config'] = json_decode($schedule['schedule_config'], true) ?? [];
            $schedule['recipients'] = json_decode($schedule['recipients'], true) ?? [];
        }
        
        return $schedule;
    }

    public function getOwnedById(int $id, int $userId): ?array
    {
        $schedule = $this->getById($id);
        if (!$schedule || !$this->canUserManage($schedule, $userId)) {
            return null;
        }

        return $schedule;
    }

    public function canUserManage(array $schedule, int $userId): bool
    {
        return (int) ($schedule['created_by'] ?? 0) === $userId;
    }
    
    /**
     * Update scheduled report
     */
    public function update(int $id, array $data): bool
    {
        $schedule = $this->getById($id);
        if (!$schedule) {
            throw new \Exception("Scheduled report not found");
        }
        
        $updates = [];
        $params = [];
        $submittedFields = [];
        
        $allowedFields = ['schedule_name', 'schedule_type', 'schedule_config', 'recipients', 'format', 'is_active'];
        
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                if ($field === 'schedule_name') {
                    $value = Security::sanitizeInput($data[$field], 'string');
                } elseif ($field === 'schedule_config' || $field === 'recipients') {
                    $value = is_array($data[$field]) ? json_encode($data[$field]) : $data[$field];
                } elseif ($field === 'is_active') {
                    $value = (int) $data[$field];
                } else {
                    $value = Security::sanitizeInput($data[$field], 'string');
                }
                
                $updates[] = "$field = ?";
                $params[] = $value;
                $submittedFields[$field] = $value;
            }
        }

        if (isset($data['format']) && !(new Reports())->isExportFormatAvailable((string) $data['format'])) {
            throw new \Exception((new Reports())->getExportUnavailableMessage((string) $data['format']));
        }
        
        // Recalculate next run time if schedule changed
        if (isset($data['schedule_type']) || isset($data['schedule_config'])) {
            $scheduleType = $data['schedule_type'] ?? $schedule['schedule_type'];
            $scheduleConfig = $data['schedule_config'] ?? $schedule['schedule_config'];
            if (is_string($scheduleConfig)) {
                $scheduleConfig = json_decode($scheduleConfig, true);
            }
            if (!is_array($scheduleConfig)) {
                $scheduleConfig = [];
            }
            $nextRunAt = $this->calculateNextRunTime($scheduleType, $scheduleConfig);
            $updates[] = "next_run_at = ?";
            $params[] = $nextRunAt;
            $submittedFields['next_run_at'] = $nextRunAt;
        }
        
        if (empty($updates)) {
            return false;
        }
        
        $workspaceId = $this->requireWorkspaceId();
        Concurrency::executeWorkspaceUpdate(
            'scheduled_reports',
            $workspaceId,
            $id,
            $updates,
            $params,
            Concurrency::expectedVersionFromData($data),
            fn(): ?array => $this->getById($id),
            $submittedFields,
            'scheduled report'
        );
        
        return true;
    }

    public function updateForUser(int $id, int $userId, array $data): bool
    {
        if (!$this->getOwnedById($id, $userId)) {
            throw new \Exception("You do not have permission to edit this scheduled report");
        }

        return $this->update($id, $data);
    }
    
    /**
     * Delete scheduled report
     */
    public function delete(int $id): bool
    {
        $schedule = $this->getById($id);
        if (!$schedule) {
            return false;
        }
        
        Database::execute(
            "DELETE FROM scheduled_reports WHERE id = ? AND workspace_id = ?",
            [$id, $this->requireWorkspaceId()]
        );
        
        return true;
    }

    public function deleteForUser(int $id, int $userId): bool
    {
        if (!$this->getOwnedById($id, $userId)) {
            throw new \Exception("You do not have permission to delete this scheduled report");
        }

        return $this->delete($id);
    }
    
    /**
     * Get all scheduled reports
     */
    public function getAll(int $userId = null, bool $activeOnly = false): array
    {
        $where = [];
        $params = [];
        
        if ($userId) {
            $where[] = "sr.created_by = ?";
            $params[] = $userId;
        }

        $where[] = "sr.workspace_id = ?";
        $params[] = $this->requireWorkspaceId();
        
        if ($activeOnly) {
            $where[] = "sr.is_active = 1";
        }
        
        $sql = "SELECT sr.*, r.name as report_name, u.email as created_by_email
                FROM scheduled_reports sr
                LEFT JOIN reports r ON sr.report_id = r.id
                LEFT JOIN users u ON sr.created_by = u.id";
        
        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }
        
        $sql .= " ORDER BY sr.next_run_at ASC";
        
        $schedules = Database::query($sql, $params);
        
        foreach ($schedules as &$schedule) {
            $schedule['schedule_config'] = json_decode($schedule['schedule_config'], true) ?? [];
            $schedule['recipients'] = json_decode($schedule['recipients'], true) ?? [];
        }
        
        return $schedules;
    }
    
    /**
     * Get scheduled reports ready to run
     */
    public function getReadyToRun(): array
    {
        $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        $sql = "SELECT sr.*, r.name as report_name
             FROM scheduled_reports sr
             INNER JOIN reports r ON sr.report_id = r.id AND r.workspace_id = sr.workspace_id
             WHERE sr.is_active = 1 
               AND sr.next_run_at <= NOW()";
        $params = [];
        if ($workspaceId > 0) {
            $sql .= " AND sr.workspace_id = ?";
            $params[] = $workspaceId;
        }
        $sql .= " ORDER BY sr.next_run_at ASC";

        return Database::query($sql, $params);
    }

    public function getDueSchedules(): array
    {
        return $this->getReadyToRun();
    }
    
    /**
     * Calculate next run time based on schedule
     */
    public function calculateNextRunTime(string $scheduleType, array $config): string
    {
        $now = new \DateTime();
        
        switch ($scheduleType) {
            case 'daily':
                $time = $config['time'] ?? '09:00';
                $next = clone $now;
                $next->setTime((int) substr($time, 0, 2), (int) substr($time, 3, 2));
                if ($next <= $now) {
                    $next->modify('+1 day');
                }
                return $next->format('Y-m-d H:i:s');
            
            case 'weekly':
                $dayOfWeek = $config['day_of_week'] ?? 1; // Monday = 1
                $time = $config['time'] ?? '09:00';
                $next = clone $now;
                $next->setTime((int) substr($time, 0, 2), (int) substr($time, 3, 2));
                $currentDay = (int) $now->format('N'); // 1 = Monday, 7 = Sunday
                $daysToAdd = ($dayOfWeek - $currentDay + 7) % 7;
                if ($daysToAdd === 0 && $next <= $now) {
                    $daysToAdd = 7;
                }
                $next->modify("+{$daysToAdd} days");
                return $next->format('Y-m-d H:i:s');
            
            case 'monthly':
                $dayOfMonth = $config['day_of_month'] ?? 1;
                $time = $config['time'] ?? '09:00';
                $next = clone $now;
                $next->setTime((int) substr($time, 0, 2), (int) substr($time, 3, 2));
                $next->setDate((int) $now->format('Y'), (int) $now->format('m'), $dayOfMonth);
                if ($next <= $now) {
                    $next->modify('+1 month');
                }
                return $next->format('Y-m-d H:i:s');
            
            case 'custom':
                // Custom cron-like expression or specific datetime
                if (isset($config['next_run'])) {
                    return $config['next_run'];
                }
                // Default to tomorrow
                $next = clone $now;
                $next->modify('+1 day');
                return $next->format('Y-m-d H:i:s');
            
            default:
                $next = clone $now;
                $next->modify('+1 day');
                return $next->format('Y-m-d H:i:s');
        }
    }
    
    /**
     * Update next run time after execution
     */
    public function updateNextRunTime(int $id): void
    {
        $schedule = $this->getById($id);
        if (!$schedule) {
            return;
        }
        
        $nextRunAt = $this->calculateNextRunTime($schedule['schedule_type'], $schedule['schedule_config']);
        
        Database::execute(
            "UPDATE scheduled_reports SET last_run_at = NOW(), next_run_at = ? WHERE id = ? AND workspace_id = ?",
            [$nextRunAt, $id, $this->requireWorkspaceId()]
        );
    }
    
    /**
     * Log scheduled report execution
     */
    public function logExecution(int $scheduledReportId, string $status, array $data = []): int
    {
        $resultCount = (int) ($data['result_count'] ?? 0);
        $errorMessage = !empty($data['error_message']) ? Security::sanitizeInput($data['error_message'], 'string') : null;
        $filePath = !empty($data['file_path']) ? Security::sanitizeInput($data['file_path'], 'string') : null;
        $sentTo = !empty($data['sent_to']) ? (is_array($data['sent_to']) ? json_encode($data['sent_to']) : $data['sent_to']) : null;
        $executionTime = (float) ($data['execution_time'] ?? 0);
        
        Database::execute(
            "INSERT INTO scheduled_report_runs (workspace_id, scheduled_report_id, executed_at, status, result_count, error_message, file_path, sent_to, execution_time) 
             VALUES (?, ?, NOW(), ?, ?, ?, ?, ?, ?)",
            [$this->requireWorkspaceId(), $scheduledReportId, $status, $resultCount, $errorMessage, $filePath, $sentTo, $executionTime]
        );
        
        return (int) Database::lastInsertId();
    }
    
    /**
     * Get execution history for a scheduled report
     */
    public function getExecutionHistory(int $scheduledReportId, int $limit = 20): array
    {
        return Database::query(
            "SELECT * FROM scheduled_report_runs 
             WHERE workspace_id = ?
               AND scheduled_report_id = ? 
             ORDER BY executed_at DESC 
             LIMIT ?",
            [$this->requireWorkspaceId(), $scheduledReportId, $limit]
        );
    }
    
    /**
     * Toggle active status
     */
    public function toggleActive(int $id): bool
    {
        $schedule = $this->getById($id);
        if (!$schedule) {
            return false;
        }
        
        $newStatus = $schedule['is_active'] ? 0 : 1;
        
        Database::execute(
            "UPDATE scheduled_reports SET is_active = ? WHERE id = ? AND workspace_id = ?",
            [$newStatus, $id, $this->requireWorkspaceId()]
        );
        
        return true;
    }

    public function toggleActiveForUser(int $id, int $userId): bool
    {
        if (!$this->getOwnedById($id, $userId)) {
            throw new \Exception("You do not have permission to change this scheduled report");
        }

        return $this->toggleActive($id);
    }

    private function requireWorkspaceId(): int
    {
        $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        if ($workspaceId <= 0) {
            throw new \RuntimeException('An active workspace is required for scheduled reports.');
        }

        return $workspaceId;
    }
}
