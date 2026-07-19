<?php
/**
 * Target Reminders Module
 * 
 * Handles scheduling and managing reminders for targets
 */

namespace CRM\Modules;

use CRM\Database;
use CRM\Security;
use CRM\Modules\Targets;
use CRM\Services\WorkspaceScopeService;

class TargetReminders
{
    private WorkspaceScopeService $workspaceScope;

    public function __construct()
    {
        $this->workspaceScope = new WorkspaceScopeService();
    }

    /**
     * Schedule initial reminders for a new target
     */
    public function scheduleInitialReminders(int $targetId, string $frequency, string $targetDate, ?int $customDays = null, ?int $workspaceId = null): void
    {
        $workspaceId = $workspaceId ?: $this->workspaceScope->requireActiveWorkspaceId();
        // Cancel any existing reminders first
        $this->cancelPendingReminders($targetId, $workspaceId);
        
        $targetDateTimestamp = strtotime($targetDate);
        $now = time();
        
        switch ($frequency) {
            case 'daily':
                // Schedule daily reminders starting tomorrow
                $nextReminder = strtotime('+1 day', $now);
                while ($nextReminder <= $targetDateTimestamp) {
                    $this->createReminder($targetId, 'daily', date('Y-m-d H:i:s', $nextReminder), $workspaceId);
                    $nextReminder = strtotime('+1 day', $nextReminder);
                }
                break;
                
            case 'weekly':
                // Schedule weekly reminders (every Monday)
                $nextReminder = $this->getNextMonday($now);
                while ($nextReminder <= $targetDateTimestamp) {
                    $this->createReminder($targetId, 'weekly', date('Y-m-d 09:00:00', $nextReminder), $workspaceId);
                    $nextReminder = strtotime('+1 week', $nextReminder);
                }
                break;
                
            case 'deadline':
                // Schedule reminders at 7, 3, and 1 days before deadline
                $deadline7 = strtotime('-7 days', $targetDateTimestamp);
                $deadline3 = strtotime('-3 days', $targetDateTimestamp);
                $deadline1 = strtotime('-1 day', $targetDateTimestamp);
                
                if ($deadline7 > $now) {
                    $this->createReminder($targetId, 'deadline_7', date('Y-m-d 09:00:00', $deadline7), $workspaceId);
                }
                if ($deadline3 > $now) {
                    $this->createReminder($targetId, 'deadline_3', date('Y-m-d 09:00:00', $deadline3), $workspaceId);
                }
                if ($deadline1 > $now) {
                    $this->createReminder($targetId, 'deadline_1', date('Y-m-d 09:00:00', $deadline1), $workspaceId);
                }
                break;
                
            case 'custom':
                if ($customDays && $customDays > 0) {
                    $nextReminder = strtotime("+{$customDays} days", $now);
                    while ($nextReminder <= $targetDateTimestamp) {
                        $this->createReminder($targetId, 'custom', date('Y-m-d 09:00:00', $nextReminder), $workspaceId);
                        $nextReminder = strtotime("+{$customDays} days", $nextReminder);
                    }
                }
                break;
        }
    }
    
    /**
     * Reschedule reminders (when target is updated)
     */
    public function rescheduleReminders(int $targetId, string $frequency, string $targetDate, ?int $customDays = null, ?int $workspaceId = null): void
    {
        $this->scheduleInitialReminders($targetId, $frequency, $targetDate, $customDays, $workspaceId);
    }
    
    /**
     * Create a reminder record
     */
    public function createReminder(int $targetId, string $reminderType, string $scheduledAt, ?int $workspaceId = null): int
    {
        $workspaceId = $workspaceId ?: $this->workspaceScope->requireActiveWorkspaceId();
        $target = Database::queryOne('SELECT state_version FROM targets WHERE workspace_id=? AND id=?', [$workspaceId, $targetId]);
        $deliveryKey = hash('sha256', implode('|', [$workspaceId, $targetId, $reminderType, $scheduledAt, (int) ($target['state_version'] ?? 1)]));
        Database::execute(
            "INSERT IGNORE INTO target_reminders (workspace_id, target_id, reminder_type, scheduled_at, status, delivery_key)
             VALUES (?, ?, ?, ?, 'pending', ?)",
            [$workspaceId, $targetId, $reminderType, $scheduledAt, $deliveryKey]
        );
        
        $row = Database::queryOne('SELECT id FROM target_reminders WHERE workspace_id=? AND delivery_key=? LIMIT 1', [$workspaceId, $deliveryKey]);
        return (int) ($row['id'] ?? 0);
    }
    
    /**
     * Get pending reminders that are due
     */
    public function getDueReminders(int $limit = 100, ?int $workspaceId = null): array
    {
        $workspaceId = $workspaceId ?: $this->workspaceScope->requireActiveWorkspaceId();
        return Database::query(
            "SELECT tr.*, t.user_id, t.title, t.target_value, t.current_value, t.target_date, 
                    t.reminder_frequency, t.unit, u.email as user_email
             FROM target_reminders tr
             INNER JOIN targets t ON tr.target_id = t.id
             LEFT JOIN users u ON t.user_id = u.id
             WHERE tr.workspace_id = ?
             AND tr.status = 'pending'
             AND tr.scheduled_at <= NOW()
             AND t.status = 'active'
             ORDER BY tr.scheduled_at ASC
             LIMIT ?",
            [$workspaceId, $limit]
        );
    }
    
    /**
     * Mark reminder as sent
     */
    public function markAsSent(int $reminderId, ?int $notificationId = null, ?int $workspaceId = null, array $providerResponse = []): void
    {
        $workspaceId = $workspaceId ?: $this->workspaceScope->requireActiveWorkspaceId();
        Database::execute(
            "UPDATE target_reminders 
             SET status = 'sent', sent_at = NOW(), notification_id = ?, delivery_attempts=delivery_attempts+1,
                 last_error=NULL, provider_response_json=?
             WHERE workspace_id = ? AND id = ?",
            [$notificationId, json_encode($providerResponse, JSON_UNESCAPED_SLASHES), $workspaceId, $reminderId]
        );
        
        // Update last_reminder_at on target
        $reminder = Database::queryOne(
            "SELECT target_id FROM target_reminders WHERE workspace_id = ? AND id = ?",
            [$workspaceId, $reminderId]
        );
        
        if ($reminder) {
            Database::execute(
                "UPDATE targets SET last_reminder_at = NOW() WHERE workspace_id = ? AND id = ?",
                [$workspaceId, $reminder['target_id']]
            );
        }
    }

    public function markFailed(int $reminderId, int $workspaceId, string $error, array $providerResponse = []): void
    {
        Database::execute(
            "UPDATE target_reminders SET delivery_attempts=delivery_attempts+1,last_error=?,provider_response_json=?,
                 scheduled_at=DATE_ADD(NOW(),INTERVAL LEAST(delivery_attempts+1,30) MINUTE)
             WHERE workspace_id=? AND id=? AND status='pending'",
            [substr($error, 0, 4000), json_encode($providerResponse, JSON_UNESCAPED_SLASHES), $workspaceId, $reminderId]
        );
    }
    
    /**
     * Cancel pending reminders for a target
     */
    public function cancelPendingReminders(int $targetId, ?int $workspaceId = null): void
    {
        $workspaceId = $workspaceId ?: $this->workspaceScope->requireActiveWorkspaceId();
        Database::execute(
            "UPDATE target_reminders 
             SET status = 'cancelled' 
             WHERE workspace_id = ? AND target_id = ? AND status = 'pending'",
            [$workspaceId, $targetId]
        );
    }
    
    /**
     * Get reminders for a target
     */
    public function getTargetReminders(int $targetId, bool $pendingOnly = false): array
    {
        $where = "workspace_id = ? AND target_id = ?";
        $params = [$this->workspaceScope->requireActiveWorkspaceId(), $targetId];
        
        if ($pendingOnly) {
            $where .= " AND status = 'pending'";
        }
        
        return Database::query(
            "SELECT * FROM target_reminders 
             WHERE $where 
             ORDER BY scheduled_at ASC",
            $params
        );
    }
    
    /**
     * Get next Monday from given timestamp
     */
    private function getNextMonday(int $timestamp): int
    {
        $dayOfWeek = date('w', $timestamp); // 0 = Sunday, 1 = Monday, etc.
        $daysUntilMonday = $dayOfWeek == 0 ? 1 : (8 - $dayOfWeek);
        return strtotime("+{$daysUntilMonday} days", $timestamp);
    }
    
    /**
     * Get reminder message based on type and target data
     */
    public function getReminderMessage(array $reminder, array $target): string
    {
        $progress = $this->calculateProgress($target);
        $daysRemaining = $this->getDaysRemaining($target);
        $unit = $target['unit'] ?: '';
        
        $title = $target['title'];
        $currentValue = number_format((float)$target['current_value'], 2);
        $targetValue = number_format((float)$target['target_value'], 2);
        
        switch ($reminder['reminder_type']) {
            case 'daily':
                return "Daily check-in: Your target '$title' is at {$currentValue}{$unit} of {$targetValue}{$unit} ({$progress}% complete). {$daysRemaining} days remaining.";
                
            case 'weekly':
                return "Weekly update: Your target '$title' progress is {$currentValue}{$unit} of {$targetValue}{$unit} ({$progress}% complete). {$daysRemaining} days remaining.";
                
            case 'deadline_7':
                return "Target reminder: '$title' deadline is in 7 days. Current progress: {$currentValue}{$unit} of {$targetValue}{$unit} ({$progress}%).";
                
            case 'deadline_3':
                return "Target reminder: '$title' deadline is in 3 days. Current progress: {$currentValue}{$unit} of {$targetValue}{$unit} ({$progress}%).";
                
            case 'deadline_1':
                return "Final reminder: '$title' deadline is tomorrow! Current progress: {$currentValue}{$unit} of {$targetValue}{$unit} ({$progress}%).";
                
            case 'custom':
            default:
                return "Target reminder: '$title' - {$currentValue}{$unit} of {$targetValue}{$unit} ({$progress}% complete). {$daysRemaining} days remaining.";
        }
    }
    
    /**
     * Calculate progress percentage
     */
    private function calculateProgress(array $target): float
    {
        $targetValue = (float) $target['target_value'];
        $currentValue = (float) $target['current_value'];
        
        if ($targetValue <= 0) {
            return 0;
        }
        
        $percentage = ($currentValue / $targetValue) * 100;
        return min(100, max(0, round($percentage, 2)));
    }
    
    /**
     * Get days remaining
     */
    private function getDaysRemaining(array $target): int
    {
        if (empty($target['target_date'])) {
            return 0;
        }
        
        $targetDate = strtotime($target['target_date']);
        $today = strtotime(date('Y-m-d'));
        $diff = $targetDate - $today;
        
        return max(0, (int) ceil($diff / 86400));
    }
}
