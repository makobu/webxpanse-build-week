<?php
/**
 * Workflow Trigger Service
 * Manages workflow subscriptions to events
 */

namespace CRM\Services;

use CRM\Database;
use CRM\EventBus;
use CRM\Modules\AutomationEngine;
use CRM\Services\WorkflowQueueService;
use CRM\Services\WorkspaceContext;

class WorkflowTriggerService
{
    private AutomationEngine $automationEngine;
    private array $subscribedWorkflows = [];
    
    public function __construct()
    {
        $this->automationEngine = new AutomationEngine();
    }
    
    /**
     * Initialize all active workflows and subscribe them to events
     * Should be called on system startup
     */
    public function initializeWorkflows(): void
    {
        // Clear existing subscriptions
        $this->subscribedWorkflows = [];
        
        // Load all active workflows
        $workflows = Database::query(
            "SELECT id, trigger_config FROM workflows WHERE is_active = 1",
            []
        );
        
        foreach ($workflows as $workflow) {
            $this->subscribeWorkflowData($workflow);
        }
    }
    
    /**
     * Subscribe a workflow to its trigger event
     */
    public function subscribeWorkflow(int $workflowId): void
    {
        $workspaceId = WorkspaceContext::currentWorkspaceId();
        $workflow = $workspaceId > 0
            ? Database::queryOne(
                "SELECT id, workspace_id, trigger_config, is_active FROM workflows WHERE workspace_id = ? AND id = ?",
                [$workspaceId, $workflowId]
            )
            : Database::queryOne(
                "SELECT id, workspace_id, trigger_config, is_active FROM workflows WHERE id = ?",
                [$workflowId]
            );
        
        if (!$workflow || !$workflow['is_active']) {
            return;
        }

        $this->subscribeWorkflowData($workflow);
    }

    /**
     * Subscribe using preloaded workflow row data.
     * Avoids extra DB lookups during bulk initialization.
     */
    private function subscribeWorkflowData(array $workflow): void
    {
        $workflowId = (int) ($workflow['id'] ?? 0);
        if ($workflowId <= 0) {
            return;
        }
        
        $triggerConfig = json_decode($workflow['trigger_config'], true);
        if (!$triggerConfig || !isset($triggerConfig['type'])) {
            return;
        }
        
        $triggerType = $triggerConfig['type'];
        
        // Map workflow trigger name to EventBus event name
        $eventName = $this->mapTriggerToEventName($triggerType);
        
        // Subscribe to the trigger event
        EventBus::subscribe($eventName, function($data) use ($workflowId, $triggerConfig) {
            $this->handleWorkflowTrigger($workflowId, $triggerConfig, $data);
        });
        
        $this->subscribedWorkflows[$workflowId] = $triggerType;
    }
    
    /**
     * Unsubscribe a workflow from events
     */
    public function unsubscribeWorkflow(int $workflowId): void
    {
        // Note: EventBus doesn't support unsubscribe, but we can track
        // and check in handleWorkflowTrigger if workflow is still active
        unset($this->subscribedWorkflows[$workflowId]);
    }
    
    /**
     * Handle workflow trigger event
     */
    private function handleWorkflowTrigger(int $workflowId, array $triggerConfig, array $eventData): void
    {
        // Verify workflow is still active
        $workflow = Database::queryOne(
            "SELECT id, workspace_id, is_active FROM workflows WHERE id = ?",
            [$workflowId]
        );
        
        if (!$workflow || !$workflow['is_active']) {
            return;
        }
        
        // Check if trigger matches (for triggers with additional conditions)
        if (!$this->matchesTrigger($triggerConfig, $eventData)) {
            return;
        }
        
        // Normalize event data - ensure contact_id exists
        if (!isset($eventData['contact_id']) && isset($eventData['contact']['id'])) {
            $eventData['contact_id'] = $eventData['contact']['id'];
        }
        if (!isset($eventData['contact_id']) && isset($eventData['contact_id'])) {
            // Already set
        } elseif (!isset($eventData['contact_id'])) {
            // Try to get from various sources
            if (isset($eventData['deal']['contact_id'])) {
                $eventData['contact_id'] = $eventData['deal']['contact_id'];
            } elseif (isset($eventData['task']['contact_id'])) {
                $eventData['contact_id'] = $eventData['task']['contact_id'];
            }
        }

        $contactId = $eventData['contact_id'] ?? null;
        if (!$contactId) {
            error_log("Workflow trigger skipped: No contact_id in event data for workflow #$workflowId");
            return;
        }

        // Queue workflow for async execution (non-blocking)
        $queueService = new WorkflowQueueService();
        $queueService->addToQueue($workflowId, (int) $contactId, $eventData, (int) ($workflow['workspace_id'] ?? 0));
    }
    
    /**
     * Map workflow trigger names to EventBus event names
     */
    private function mapTriggerToEventName(string $triggerType): string
    {
        $mapping = [
            'contact_created' => 'contact.created',
            'contact_updated' => 'contact.updated',
            'contact_field_changed' => 'contact.updated',
            'contact_tag_added' => 'contact.updated',
            'contact_tag_removed' => 'contact.updated',
            'contact_score_changed' => 'contact.score_changed',
            'contact_enriched' => 'contact.updated',
            'deal_created' => 'deal.created',
            'deal_updated' => 'deal.updated',
            'deal_stage_changed' => 'deal.stage_changed',
            'deal_won' => 'deal.won',
            'deal_lost' => 'deal.lost',
            'deal_amount_changed' => 'deal.amount_changed',
            'task_created' => 'task.created',
            'task_completed' => 'task.completed',
            'task_overdue' => 'task.overdue',
            'webhook_received' => 'webhook.received',
            'api_call' => 'api.call',
            'email_opened' => 'email.opened',
            'email_clicked' => 'email.clicked',
            'email_received' => 'email.received',
            'whatsapp_message_received' => 'whatsapp.message_received',
            'ml_score_threshold' => 'ml.score_threshold',
            'ml_conversion_probability' => 'ml.conversion_probability',
            'ml_churn_risk' => 'ml.churn_risk',
            'ml_score_increased' => 'ml.score_increased',
            'ml_score_decreased' => 'ml.score_decreased',
            'email_replied' => 'email.replied',
            'email_bounced' => 'email.bounced',
            'email_unsubscribed' => 'email.unsubscribed',
            'form_submitted' => 'form.submitted',
            'stage_changed' => 'stage.changed',
            'activity_created' => 'activity.created',
        ];
        
        return $mapping[$triggerType] ?? $triggerType;
    }
    
    /**
     * Check if event data matches trigger configuration
     */
    private function matchesTrigger(array $triggerConfig, array $eventData): bool
    {
        $triggerType = $triggerConfig['type'];
        
        // Handle specific trigger types with additional conditions
        switch ($triggerType) {
            case 'stage_changed':
                // Check if stage change matches trigger config
                if (isset($triggerConfig['from_stage']) && !empty($triggerConfig['from_stage'])) {
                    if (($eventData['from_stage'] ?? null) !== $triggerConfig['from_stage']) {
                        return false;
                    }
                }
                if (isset($triggerConfig['to_stage']) && !empty($triggerConfig['to_stage'])) {
                    if (($eventData['to_stage'] ?? null) !== $triggerConfig['to_stage']) {
                        return false;
                    }
                }
                break;
                
            case 'no_activity_for_days':
                // This trigger is handled by a scheduled job, not event bus
                // But we can check if days match
                if (isset($triggerConfig['days'])) {
                    $daysSinceActivity = $eventData['days_since_activity'] ?? 0;
                    if ($daysSinceActivity < $triggerConfig['days']) {
                        return false;
                    }
                }
                break;
                
            case 'ml_score_threshold':
                // Check if ML score crosses threshold
                if (isset($triggerConfig['threshold'])) {
                    $mlScore = $eventData['ml_score'] ?? $eventData['score'] ?? null;
                    $operator = $triggerConfig['operator'] ?? '>=';
                    
                    if ($mlScore === null) return false;
                    
                    $threshold = (float) $triggerConfig['threshold'];
                    switch ($operator) {
                        case '>=':
                            if ($mlScore < $threshold) return false;
                            break;
                        case '<=':
                            if ($mlScore > $threshold) return false;
                            break;
                        case '>':
                            if ($mlScore <= $threshold) return false;
                            break;
                        case '<':
                            if ($mlScore >= $threshold) return false;
                            break;
                    }
                }
                break;
                
            case 'ml_conversion_probability':
                // Check if conversion probability meets criteria
                if (isset($triggerConfig['probability'])) {
                    $probability = $eventData['ml_conversion_probability'] ?? $eventData['probability'] ?? null;
                    $operator = $triggerConfig['operator'] ?? '>=';
                    
                    if ($probability === null) return false;
                    
                    $threshold = (float) $triggerConfig['probability'];
                    switch ($operator) {
                        case '>=':
                            if ($probability < $threshold) return false;
                            break;
                        case '<=':
                            if ($probability > $threshold) return false;
                            break;
                        case '>':
                            if ($probability <= $threshold) return false;
                            break;
                        case '<':
                            if ($probability >= $threshold) return false;
                            break;
                    }
                }
                break;
                
            case 'ml_churn_risk':
                // Check if churn risk meets criteria
                if (isset($triggerConfig['risk_level'])) {
                    $churnProbability = $eventData['ml_churn_probability'] ?? $eventData['churn_probability'] ?? null;
                    
                    if ($churnProbability === null) return false;
                    
                    $riskLevel = $triggerConfig['risk_level']; // 'high', 'medium', 'low'
                    $thresholds = [
                        'high' => 0.7,
                        'medium' => 0.5,
                        'low' => 0.3
                    ];
                    
                    $threshold = $thresholds[$riskLevel] ?? 0.5;
                    
                    if ($churnProbability < $threshold) return false;
                }
                break;
        }
        
        return true;
    }
    
    /**
     * Get list of subscribed workflows
     */
    public function getSubscribedWorkflows(): array
    {
        return $this->subscribedWorkflows;
    }
}
