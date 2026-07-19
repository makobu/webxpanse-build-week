<?php
/**
 * Workflow Analytics Module
 * Calculates workflow performance metrics
 */

namespace CRM\Modules;

use CRM\Database;
use CRM\Services\AnalyticsWorkspaceService;

class WorkflowAnalytics
{
    private AnalyticsWorkspaceService $analyticsWorkspace;

    public function __construct()
    {
        $this->analyticsWorkspace = new AnalyticsWorkspaceService();
    }

    /**
     * Get workflow analytics
     */
    public function getWorkflowAnalytics(int $workflowId, ?string $startDate = null, ?string $endDate = null): array
    {
        $workspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId();
        $startDate = $startDate ?? date('Y-m-d', strtotime('-30 days'));
        $endDate = $endDate ?? date('Y-m-d');
        $workflow = Database::queryOne(
            "SELECT id FROM workflows WHERE workspace_id = ? AND id = ? LIMIT 1",
            [$workspaceId, $workflowId]
        );
        if (!$workflow) {
            throw new \RuntimeException('Workflow not found');
        }
        
        // Get execution stats
        $stats = Database::queryOne(
            "SELECT 
                COUNT(*) as total_executions,
                COUNT(CASE WHEN status = 'completed' THEN 1 END) as successful_executions,
                COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed_executions,
                AVG(execution_time_ms) as avg_execution_time,
                SUM(actions_completed) as total_actions_completed,
                SUM(actions_failed) as total_actions_failed
             FROM workflow_executions
             WHERE workspace_id = ?
             AND workflow_id = ? 
             AND DATE(executed_at) BETWEEN ? AND ?",
            [$workspaceId, $workflowId, $startDate, $endDate]
        );
        
        // Calculate success rate
        $successRate = $stats['total_executions'] > 0 
            ? ($stats['successful_executions'] / $stats['total_executions']) * 100 
            : 0;
        
        // Get execution trend (daily)
        $trend = Database::query(
            "SELECT 
                DATE(executed_at) as date,
                COUNT(*) as executions,
                COUNT(CASE WHEN status = 'completed' THEN 1 END) as successful,
                COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed
             FROM workflow_executions
             WHERE workspace_id = ?
             AND workflow_id = ?
             AND DATE(executed_at) BETWEEN ? AND ?
             GROUP BY DATE(executed_at)
             ORDER BY date ASC",
            [$workspaceId, $workflowId, $startDate, $endDate]
        );
        
        // Get most common errors
        $errors = Database::query(
            "SELECT 
                error_message,
                COUNT(*) as count
             FROM workflow_executions
             WHERE workspace_id = ?
             AND workflow_id = ?
             AND status = 'failed'
             AND error_message IS NOT NULL
             AND DATE(executed_at) BETWEEN ? AND ?
             GROUP BY error_message
             ORDER BY count DESC
             LIMIT 10",
            [$workspaceId, $workflowId, $startDate, $endDate]
        );

        $nodeStats = Database::query(
            "SELECT 
                node_id,
                node_type,
                node_label,
                COUNT(*) as run_count,
                COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed_count,
                AVG(duration_ms) as avg_duration_ms
             FROM workflow_node_runs
             WHERE workflow_id = ?
             AND EXISTS (
                 SELECT 1 FROM workflows w
                 WHERE w.id = workflow_node_runs.workflow_id
                   AND w.workspace_id = ?
             )
             AND DATE(created_at) BETWEEN ? AND ?
             GROUP BY node_id, node_type, node_label
             ORDER BY failed_count DESC, run_count DESC
             LIMIT 10",
            [$workflowId, $workspaceId, $startDate, $endDate]
        );

        $queueStats = ['avg_queue_latency_ms' => 0, 'p95_queue_latency_ms' => 0];
        if (Database::tableExists('workflow_queue')) {
            $queueStats = Database::queryOne(
                "SELECT 
                    AVG(queue_latency_ms) as avg_queue_latency_ms,
                    MAX(queue_latency_ms) as p95_queue_latency_ms
                 FROM workflow_queue
                 WHERE workspace_id = ?
                 AND workflow_id = ?
                 AND DATE(created_at) BETWEEN ? AND ?",
                [$workspaceId, $workflowId, $startDate, $endDate]
            ) ?? $queueStats;
        }

        $retryStats = ['retry_count' => 0];
        if (Database::tableExists('workflow_retry_queue')) {
            $retryStats = Database::queryOne(
                "SELECT COUNT(*) as retry_count
                 FROM workflow_retry_queue
                 WHERE workspace_id = ?
                 AND workflow_id = ?
                 AND DATE(created_at) BETWEEN ? AND ?",
                [$workspaceId, $workflowId, $startDate, $endDate]
            ) ?? $retryStats;
        }
        
        return [
            'workflow_id' => $workflowId,
            'period' => [
                'start' => $startDate,
                'end' => $endDate
            ],
            'stats' => [
                'total_executions' => (int)$stats['total_executions'],
                'successful_executions' => (int)$stats['successful_executions'],
                'failed_executions' => (int)$stats['failed_executions'],
                'success_rate' => round($successRate, 2),
                'avg_execution_time_ms' => round((float)$stats['avg_execution_time'], 2),
                'total_actions_completed' => (int)$stats['total_actions_completed'],
                'total_actions_failed' => (int)$stats['total_actions_failed'],
                'retry_count' => (int) ($retryStats['retry_count'] ?? 0),
                'avg_queue_latency_ms' => round((float) ($queueStats['avg_queue_latency_ms'] ?? 0), 2),
                'p95_queue_latency_ms' => round((float) ($queueStats['p95_queue_latency_ms'] ?? 0), 2),
            ],
            'trend' => $trend,
            'top_errors' => $errors,
            'node_hotspots' => $nodeStats
        ];
    }
    
    /**
     * Get all workflows analytics summary
     */
    public function getAllWorkflowsAnalytics(?string $startDate = null, ?string $endDate = null): array
    {
        $workspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId();
        $startDate = $startDate ?? date('Y-m-d', strtotime('-30 days'));
        $endDate = $endDate ?? date('Y-m-d');
        
        $workflows = Database::query(
            "SELECT 
                w.id,
                w.name,
                w.execution_count,
                w.success_count,
                w.failure_count,
                w.avg_execution_time,
                w.last_executed_at
             FROM workflows w
             WHERE w.workspace_id = ?
             ORDER BY w.execution_count DESC",
            [$workspaceId]
        );
        
        $analytics = [];
        foreach ($workflows as $workflow) {
            $analytics[] = $this->getWorkflowAnalytics($workflow['id'], $startDate, $endDate);
        }
        
        return $analytics;
    }
}
