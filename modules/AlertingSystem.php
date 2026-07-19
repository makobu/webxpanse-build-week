<?php
/**
 * Alerting System Module
 * 
 * Manages system alerts for errors, performance issues, and resource usage
 */

namespace CRM\Modules;

use CRM\Database;
use CRM\Modules\Notifications;
use CRM\Services\SMTPClient;

class AlertingSystem
{
    private Notifications $notifications;
    
    public function __construct()
    {
        $this->notifications = new Notifications();
    }
    
    /**
     * Create an alert
     */
    public function createAlert(string $alertType, string $severity, string $title, string $message, array $metadata = []): int
    {
        Database::execute(
            "INSERT INTO alerts (alert_type, severity, title, message, metadata, status) 
             VALUES (?, ?, ?, ?, ?, 'active')",
            [
                $alertType,
                $severity,
                $title,
                $message,
                json_encode($metadata)
            ]
        );
        
        $alertId = (int) Database::lastInsertId();
        
        // Send notifications for high/critical severity alerts
        if (in_array($severity, ['high', 'critical'])) {
            $this->notifyAdmins($alertId, $title, $message, $severity);
        }
        
        return $alertId;
    }
    
    /**
     * Check and create alerts based on system status
     */
    public function checkSystemAlerts(): void
    {
        $systemMonitor = new SystemMonitor();
        $errorTracker = new ErrorTracker();
        $performanceMonitor = new PerformanceMonitor();
        
        $healthStatus = $systemMonitor->getHealthStatus();
        $errorStats = $errorTracker->getErrorStats(1);
        $queryStats = $performanceMonitor->getQueryStats(1);
        
        // Check database status
        if ($healthStatus['database']['status'] === 'error') {
            $this->createAlert(
                'performance',
                'critical',
                'Database Connection Error',
                'Database is experiencing connection issues. Response time: ' . ($healthStatus['database']['response_time_ms'] ?? 0) . 'ms',
                ['component' => 'database', 'response_time' => $healthStatus['database']['response_time_ms'] ?? 0]
            );
        } elseif ($healthStatus['database']['response_time_ms'] > 2000) {
            $this->createAlert(
                'performance',
                'high',
                'Database Performance Degradation',
                'Database response time is high: ' . ($healthStatus['database']['response_time_ms'] ?? 0) . 'ms',
                ['component' => 'database', 'response_time' => $healthStatus['database']['response_time_ms'] ?? 0]
            );
        }
        
        // Check queue status
        if ($healthStatus['queue']['status'] === 'error') {
            $this->createAlert(
                'resource',
                'critical',
                'Email Queue Backlog',
                'Email queue has ' . ($healthStatus['queue']['pending'] ?? 0) . ' pending items and ' . ($healthStatus['queue']['failed'] ?? 0) . ' failed items',
                ['component' => 'queue', 'pending' => $healthStatus['queue']['pending'] ?? 0, 'failed' => $healthStatus['queue']['failed'] ?? 0]
            );
        } elseif ($healthStatus['queue']['pending'] > 100) {
            $this->createAlert(
                'resource',
                'medium',
                'Email Queue Growing',
                'Email queue has ' . ($healthStatus['queue']['pending'] ?? 0) . ' pending items',
                ['component' => 'queue', 'pending' => $healthStatus['queue']['pending'] ?? 0]
            );
        }
        
        // Check error rate
        if ($errorStats['errors_today'] > 50) {
            $this->createAlert(
                'error',
                'high',
                'High Error Rate',
                'System has generated ' . $errorStats['errors_today'] . ' errors today',
                ['component' => 'errors', 'count' => $errorStats['errors_today']]
            );
        }
        
        // Check disk usage
        if ($healthStatus['disk']['status'] === 'error') {
            $this->createAlert(
                'resource',
                'critical',
                'Disk Space Critical',
                'Disk usage is at ' . number_format($healthStatus['disk']['percent'], 1) . '%',
                ['component' => 'disk', 'percent' => $healthStatus['disk']['percent']]
            );
        } elseif ($healthStatus['disk']['status'] === 'warning') {
            $this->createAlert(
                'resource',
                'medium',
                'Disk Space Warning',
                'Disk usage is at ' . number_format($healthStatus['disk']['percent'], 1) . '%',
                ['component' => 'disk', 'percent' => $healthStatus['disk']['percent']]
            );
        }
        
        // Check memory usage
        if ($healthStatus['memory']['status'] === 'error') {
            $this->createAlert(
                'resource',
                'high',
                'Memory Usage Critical',
                'Memory usage is at ' . number_format($healthStatus['memory']['percent'], 1) . '%',
                ['component' => 'memory', 'percent' => $healthStatus['memory']['percent']]
            );
        }
        
        // Check slow queries
        if ($queryStats['slow_queries'] > 10) {
            $this->createAlert(
                'performance',
                'medium',
                'Multiple Slow Queries',
                'System has ' . $queryStats['slow_queries'] . ' slow queries (>1s) in the last hour',
                ['component' => 'database', 'slow_queries' => $queryStats['slow_queries']]
            );
        }
    }
    
    /**
     * Get active alerts
     */
    public function getActiveAlerts(int $limit = 50): array
    {
        return Database::query(
            "SELECT a.*, u.email as acknowledged_by_email
             FROM alerts a
             LEFT JOIN users u ON a.acknowledged_by = u.id
             WHERE a.status = 'active'
             ORDER BY 
                 CASE a.severity
                     WHEN 'critical' THEN 1
                     WHEN 'high' THEN 2
                     WHEN 'medium' THEN 3
                     WHEN 'low' THEN 4
                 END,
                 a.created_at DESC
             LIMIT ?",
            [$limit]
        );
    }
    
    /**
     * Acknowledge alert
     */
    public function acknowledgeAlert(int $alertId, int $userId): bool
    {
        Database::execute(
            "UPDATE alerts 
             SET status = 'acknowledged', acknowledged_by = ?, acknowledged_at = NOW() 
             WHERE id = ?",
            [$userId, $alertId]
        );
        
        return true;
    }
    
    /**
     * Resolve alert
     */
    public function resolveAlert(int $alertId): bool
    {
        Database::execute(
            "UPDATE alerts 
             SET status = 'resolved', resolved_at = NOW() 
             WHERE id = ?",
            [$alertId]
        );
        
        return true;
    }
    
    /**
     * Get alert statistics
     */
    public function getAlertStats(int $days = 7): array
    {
        $cutoffDate = date('Y-m-d', strtotime("-{$days} days"));
        
        $stats = Database::queryOne(
            "SELECT 
                COUNT(*) as total_alerts,
                COUNT(CASE WHEN status = 'active' THEN 1 END) as active_alerts,
                COUNT(CASE WHEN severity = 'critical' THEN 1 END) as critical_alerts,
                COUNT(CASE WHEN severity = 'high' THEN 1 END) as high_alerts
             FROM alerts
             WHERE created_at >= ?",
            [$cutoffDate]
        );
        
        $byType = Database::query(
            "SELECT alert_type, COUNT(*) as count
             FROM alerts
             WHERE created_at >= ?
             GROUP BY alert_type
             ORDER BY count DESC",
            [$cutoffDate]
        );
        
        return [
            'total_alerts' => (int) ($stats['total_alerts'] ?? 0),
            'active_alerts' => (int) ($stats['active_alerts'] ?? 0),
            'critical_alerts' => (int) ($stats['critical_alerts'] ?? 0),
            'high_alerts' => (int) ($stats['high_alerts'] ?? 0),
            'by_type' => $byType
        ];
    }
    
    /**
     * Notify administrators about critical alerts
     * - In-app notifications (always)
     * - Email to admins (when ALERT_EMAIL_ADMINS=true, default for high/critical)
     * - Slack webhook (when ALERT_SLACK_WEBHOOK_URL set, critical only)
     */
    private function notifyAdmins(int $alertId, string $title, string $message, string $severity): void
    {
        $admins = Database::query(
            "SELECT id, email FROM users WHERE role = 'admin'",
            []
        );
        
        $baseUrl = $_ENV['APP_URL'] ?? '';
        if (empty($baseUrl) && isset($_SERVER['HTTP_HOST'])) {
            $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
                . '://' . $_SERVER['HTTP_HOST'] . '/crm';
        }
        $alertPath = publicUrl("alerts.php?id={$alertId}");
        $alertLink = rtrim($baseUrl ?: 'http://localhost', '/') . $alertPath;
        
        // 1. In-app notifications (always)
        foreach ($admins as $admin) {
            $coachAction = $severity === 'critical'
                ? 'Escalate now, assign an owner, and resolve root cause.'
                : 'Review promptly, assign an owner, and schedule mitigation.';
            $this->notifications->create(
                $admin['id'],
                'system_alert',
                $title,
                $message,
                [
                    'alert_id' => $alertId,
                    'severity' => $severity,
                    'link' => $alertPath
                ]
            );
            $this->notifications->create(
                $admin['id'],
                'ai_coach_nudge',
                'AI Coach Nudge',
                $coachAction,
                [
                    'alert_id' => $alertId,
                    'severity' => $severity,
                    'ai_insight' => $title,
                    'ai_action' => $coachAction,
                    'link' => $alertPath
                ]
            );
        }
        
        // 2. Email admins (when enabled, for high/critical)
        $emailAlertsEnabled = ($_ENV['ALERT_EMAIL_ADMINS'] ?? 'true') === 'true';
        if ($emailAlertsEnabled && in_array($severity, ['high', 'critical'])) {
            $subject = '[' . strtoupper($severity) . '] ' . $title;
            $bodyText = $message . "\n\nView alert: " . $alertLink;
            $bodyHtml = '<p>' . nl2br(htmlspecialchars($message)) . '</p><p><a href="' . htmlspecialchars($alertLink) . '">View alert</a></p>';
            
            try {
                $smtp = new SMTPClient();
                $fromEmail = $smtp->getPreferredFromEmail('noreply@example.com') ?? 'noreply@example.com';
                $fromName = $smtp->getPreferredFromName(brandProductName()) ?? brandProductName();
                foreach ($admins as $admin) {
                    if (!empty($admin['email'])) {
                        $smtp->send($admin['email'], $fromEmail, $fromName, $subject, $bodyText, [], $bodyHtml);
                    }
                }
            } catch (\Exception $e) {
                error_log('Alert email failed: ' . $e->getMessage());
            }
        }
        
        // 3. Slack webhook (when configured, critical only)
        $slackWebhook = $_ENV['ALERT_SLACK_WEBHOOK_URL'] ?? '';
        if ($slackWebhook && $severity === 'critical') {
            $payload = [
                'text' => '🚨 *Critical CRM Alert*',
                'blocks' => [
                    [
                        'type' => 'section',
                        'text' => [
                            'type' => 'mrkdwn',
                            'text' => "*{$title}*\n{$message}\n<{$alertLink}|View alert>"
                        ]
                    ]
                ]
            ];
            $ch = curl_init($slackWebhook);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($httpCode !== 200) {
                error_log('Slack alert webhook failed: HTTP ' . $httpCode . ' ' . $response);
            }
        }
    }
}
