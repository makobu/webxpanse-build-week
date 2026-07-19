<?php
/**
 * Automation Engine
 * Workflow execution engine
 */

namespace CRM\Modules;

use CRM\Database;
use CRM\EventBus;
use CRM\Modules\ConditionEvaluator;
use CRM\Services\PluginRuntimeEventService;
use CRM\Services\PluginRuntimeRegistryService;
use CRM\Services\EmailTemplateMatcherService;
use CRM\Services\WorkflowCapabilityRegistryService;
use CRM\Services\WorkflowPluginCapabilityInterface;
use CRM\Services\WorkspaceScopeService;

class AutomationEngine
{
    private ConditionEvaluator $conditionEvaluator;
    private \CRM\Services\ColdOutreachGovernanceService $coldOutreachGovernance;
    private WorkspaceScopeService $workspaceScope;
    private WorkflowCapabilityRegistryService $workflowCapabilities;
    
    private array $triggers = [
        // Contact triggers
        'contact_created',
        'contact_updated',
        'contact_field_changed',
        'contact_tag_added',
        'contact_tag_removed',
        'contact_score_changed',
        'contact_enriched',
        
        // Email triggers
        'email_opened',
        'email_received',
        'email_clicked',
        'email_replied',
        'email_bounced',
        'email_unsubscribed',
        'no_email_opened',
        'no_email_replied',
        
        // Deal triggers
        'deal_created',
        'deal_updated',
        'deal_stage_changed',
        'deal_won',
        'deal_lost',
        'deal_amount_changed',
        'deal_closing_soon',
        
        // Task triggers
        'task_created',
        'task_completed',
        'task_overdue',
        
        // Form triggers
        'form_submitted',
        'form_field_filled',
        'form_abandoned',
        
        // Stage triggers
        'stage_changed',
        
        // Activity triggers
        'no_activity_for_days',
        'activity_created',
        
        // Scheduled triggers
        'daily_at_time',
        'weekly_on_day',
        'monthly_on_date',
        'on_date',
        'contact_birthday',
        'contact_anniversary',
        
        // Webhook triggers
        'webhook_received',
        'api_call',

        // WhatsApp triggers
        'whatsapp_message_received',
        
        // ML Scoring triggers
        'ml_score_threshold',
        'ml_conversion_probability',
        'ml_churn_risk',
        'ml_score_increased',
        'ml_score_decreased'
    ];
    
    private array $actions = [
        'send_email',
        'send_whatsapp',
        'send_sms',
        'add_tag',
        'remove_tag',
        'change_stage',
        'create_task',
        'assign_to_user',
        'wait_for_days',
        'update_contact_field',
        'create_deal',
        'update_deal_stage',
        'add_to_deal',
        'add_note',
        'create_activity',
        'update_lead_score',
        'call_webhook',
        'apply_smart_tags',
        'send_in_app_notification',
        'remove_from_workflow'
    ];
    
    public function __construct()
    {
        $this->conditionEvaluator = new ConditionEvaluator();
        $this->coldOutreachGovernance = new \CRM\Services\ColdOutreachGovernanceService();
        $this->workspaceScope = new WorkspaceScopeService();
        $this->workflowCapabilities = new WorkflowCapabilityRegistryService();
    }
    
    /**
     * Create workflow
     * Note: Subscription is handled by WorkflowTriggerService, not here
     */
    public function createWorkflow(string $name, array $trigger, array $conditions, array $actions): int
    {
        if (!in_array($trigger['type'], $this->triggers)) {
            throw new \Exception("Invalid trigger type");
        }
        
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId();
        foreach ($actions as $action) {
            if (!$this->workflowCapabilities->isActionAvailable((string) ($action['type'] ?? ''), $workspaceId, (int) ($_SESSION['user_id'] ?? 0))) {
                throw new \Exception("Invalid action type: " . $action['type']);
            }
        }

        Database::execute(
            "INSERT INTO workflows (workspace_id, name, trigger_config, conditions, actions, is_active) 
             VALUES (?, ?, ?, ?, ?, 1)",
            [
                $workspaceId,
                $name,
                json_encode($trigger),
                json_encode($conditions),
                json_encode($actions)
            ]
        );
        
        $workflowId = (int) Database::lastInsertId();
        
        // Subscribe workflow via WorkflowTriggerService
        $triggerService = new \CRM\Services\WorkflowTriggerService();
        $triggerService->subscribeWorkflow($workflowId);
        
        return $workflowId;
    }
    
    /**
     * Evaluate conditions using ConditionEvaluator
     */
    public function evaluateConditions(array $conditions, array $eventData): bool
    {
        return $this->conditionEvaluator->evaluateConditions($conditions, $eventData);
    }
    
    /**
     * Execute action
     */
    public function executeAction(array $action, array $context): void
    {
        if (empty($action['type'])) {
            throw new \Exception("Action type is required");
        }
        
        if (!isset($context['contact_id'])) {
            throw new \Exception("contact_id is required in context");
        }

        $workspaceId = $this->resolveWorkspaceIdFromContext($context);
        $actionType = (string) ($action['type'] ?? '');
        if (!$this->workflowCapabilities->isActionAvailable($actionType, $workspaceId, (int) ($context['user_id'] ?? $_SESSION['user_id'] ?? 0))) {
            throw new \Exception("Unknown action type: " . $actionType);
        }

        $actionExecutionMetadata = [];

        switch ($action['type']) {
            case 'send_email':
                $actionExecutionMetadata = $this->executeSendEmail($action, $context);
                break;
                
            case 'send_whatsapp':
                $this->executeSendWhatsApp($action, $context);
                break;

            case 'send_sms':
                $this->executeSendSms($action, $context);
                break;

            case 'add_tag':
                $this->executeAddTag($action, $context);
                break;

            case 'remove_tag':
                $this->executeRemoveTag($action, $context);
                break;

            case 'change_stage':
                $this->executeChangeStage($action, $context);
                break;
                
            case 'create_task':
                $this->executeCreateTask($action, $context);
                break;
                
            case 'assign_to_user':
                $this->executeAssignToUser($action, $context);
                break;
                
            case 'wait_for_days':
                $this->executeWaitForDays($action, $context);
                break;
                
            case 'update_contact_field':
                $this->executeUpdateContactField($action, $context);
                break;
                
            case 'create_deal':
                $this->executeCreateDeal($action, $context);
                break;

            case 'update_deal_stage':
                $this->executeUpdateDealStage($action, $context);
                break;

            case 'add_to_deal':
                $this->executeAddToDeal($action, $context);
                break;

            case 'add_note':
                $this->executeAddNote($action, $context);
                break;

            case 'create_activity':
                $this->executeCreateActivity($action, $context);
                break;

            case 'update_lead_score':
                $this->executeUpdateLeadScore($action, $context);
                break;
                
            case 'call_webhook':
                $this->executeCallWebhook($action, $context);
                break;
                
            case 'apply_smart_tags':
                $this->executeApplySmartTags($action, $context);
                break;

            case 'send_in_app_notification':
                $this->executeSendInAppNotification($action, $context);
                break;

            case 'remove_from_workflow':
                $this->executeRemoveFromWorkflow($action, $context);
                break;

            default:
                $this->executePluginWorkflowAction($action, $context, $workspaceId);
                break;
        }

        (new PluginRuntimeEventService())->record([
            'workspace_id' => $workspaceId,
            'user_id' => (int) ($context['user_id'] ?? $_SESSION['user_id'] ?? 0),
            'skill_key' => !empty($action['source_plugin_key']) ? (string) $action['source_plugin_key'] : 'core',
            'capability_key' => $actionType,
            'entity_type' => 'workflow',
            'entity_id' => (int) ($context['workflow_id'] ?? 0),
            'event_type' => 'workflow_action_executed',
            'status' => 'success',
            'metadata' => array_filter(
                array_merge(['action_type' => $actionType], $actionExecutionMetadata),
                static fn($value): bool => $value !== null && $value !== []
            ),
        ]);
    }
    
    /**
     * Execute send_email action
     */
    private function executeSendEmail(array $action, array $context): array
    {
        $emailService = new \CRM\Services\EmailService();
        $contactId = $context['contact_id'];
        $toEmail = $context['contact_email'] ?? $context['email'] ?? null;
        
        if (!$toEmail) {
            // Get email from contact
            $contact = Database::queryOne(
                "SELECT email FROM contacts WHERE id = ?",
                [$contactId]
            );
            $toEmail = $contact['email'] ?? null;
        }
        
        if (!$toEmail) {
            throw new \Exception("No email address found for contact");
        }
        
        $subject = $this->replaceTokens($action['subject'] ?? '', $context);
        $body = $this->replaceTokens($action['body'] ?? '', $context);
        $senderProfile = strtolower(trim((string) ($action['sender_profile'] ?? $action['smtp_profile'] ?? 'outreach')));
        $senderProfile = in_array($senderProfile, ['nurture', 'nurture_email'], true) ? 'nurture' : 'outreach';
        
        $templateMatch = [];
        if (empty($action['template_query']) && (string) ($action['template_strategy'] ?? '') === 'auto') {
            $action['template_query'] = array_filter([
                'intent_key' => $action['template_intent_key'] ?? null,
                'purpose' => $action['template_purpose'] ?? null,
                'tone' => $action['template_tone'] ?? null,
                'lifecycle_stage' => $action['template_lifecycle_stage'] ?? null,
                'audience' => $action['template_audience'] ?? null,
            ], static fn($value): bool => $value !== null && $value !== '');
        }

        if (empty($action['template_id']) && !empty($action['template_query']) && is_array($action['template_query'])) {
            $templateMatch = (new EmailTemplateMatcherService())->matchForWorkflowAction($action, $context);
            if ((int) ($templateMatch['template_id'] ?? 0) > 0
                && in_array((string) ($templateMatch['confidence'] ?? ''), ['high', 'medium'], true)
            ) {
                $action['template_id'] = (int) $templateMatch['template_id'];
            }
        }

        // Use template if provided
        if (!empty($action['template_id'])) {
            $emailService->sendWithTemplate($contactId, '', [], [
                'template_id' => $action['template_id'],
                'sender_profile' => $senderProfile,
            ]);
        } else {
            $dispatch = $this->coldOutreachGovernance->planDispatch(
                'email',
                (int) $contactId,
                null,
                'workflow_email',
                ['workflow_id' => $context['workflow_id'] ?? null]
            );
            $uuid = $emailService->send($contactId, $toEmail, $subject, $body, [
                'scheduled_at' => $dispatch['scheduled_at'] ?? null,
                'sender_profile' => $senderProfile,
            ]);
            if (!empty($dispatch['reservation_id'])) {
                $email = Database::queryOne("SELECT id FROM emails WHERE uuid = ? LIMIT 1", [$uuid]);
                $this->coldOutreachGovernance->attachReservation(
                    (int) ($dispatch['reservation_id'] ?? 0),
                    !empty($email['id']) ? (int) $email['id'] : null,
                    $uuid
                );
            }
        }

        return $templateMatch !== []
            ? ['template_match' => [
                'template_id' => (int) ($templateMatch['template_id'] ?? 0),
                'template_name' => (string) ($templateMatch['template_name'] ?? ''),
                'confidence' => (string) ($templateMatch['confidence'] ?? 'none'),
                'score' => (int) ($templateMatch['score'] ?? 0),
                'fallback_used' => !empty($templateMatch['fallback_used']),
            ]]
            : [];
    }
    
    /**
     * Execute send_whatsapp action
     */
    private function executeSendWhatsApp(array $action, array $context): void
    {
        $whatsappService = new \CRM\Services\WhatsAppService();
        $contactId = $context['contact_id'];
        
        // Get phone number from contact
        $contact = Database::queryOne(
            "SELECT workspace_id, phone FROM contacts WHERE id = ?",
            [$contactId]
        );
        
        if (!$contact || empty($contact['phone'])) {
            throw new \Exception("No phone number found for contact");
        }
        
        $message = $this->replaceTokens($action['message'] ?? '', $context);
        
        if (empty($message)) {
            throw new \Exception("WhatsApp message is required");
        }
        
        $dispatch = $this->coldOutreachGovernance->planDispatch(
            'whatsapp',
            (int) $contactId,
            null,
            'workflow_whatsapp',
            ['workflow_id' => $context['workflow_id'] ?? null]
        );

        if (!empty($dispatch['was_deferred'])) {
            $uuid = $whatsappService->storeMessage($contactId, $contact['phone'], 'text', $message, [
                'scheduled_at' => $dispatch['scheduled_at'] ?? null,
            ]);
            $messageRow = Database::queryOne("SELECT id FROM whatsapp_messages WHERE uuid = ? LIMIT 1", [$uuid]);
            $this->coldOutreachGovernance->attachReservation(
                (int) ($dispatch['reservation_id'] ?? 0),
                !empty($messageRow['id']) ? (int) $messageRow['id'] : null,
                $uuid
            );
            return;
        }

        $result = $whatsappService->sendTextMessage($contact['phone'], $message);
        $whatsappMessageId = $result['messages'][0]['id'] ?? null;
        $uuid = $whatsappService->storeMessage($contactId, $contact['phone'], 'text', $message, [
            'whatsapp_message_id' => $whatsappMessageId
        ]);
        $messageRow = Database::queryOne("SELECT id FROM whatsapp_messages WHERE uuid = ? LIMIT 1", [$uuid]);
        $this->coldOutreachGovernance->attachReservation(
            (int) ($dispatch['reservation_id'] ?? 0),
            !empty($messageRow['id']) ? (int) $messageRow['id'] : null,
            $uuid
        );
        if (!empty($messageRow['id'])) {
            $this->coldOutreachGovernance->markByEntity('whatsapp', (int) $messageRow['id'], 'sent');
        }
    }

    /**
     * Execute send_sms action
     */
    private function executeSendSms(array $action, array $context): void
    {
        $smsService = new \CRM\Services\SMSService();
        $contactId = $context['contact_id'];

        $contact = Database::queryOne(
            "SELECT phone FROM contacts WHERE id = ?",
            [$contactId]
        );

        if (!$contact || empty($contact['phone'])) {
            throw new \Exception("No phone number found for contact");
        }

        $message = $this->replaceTokens($action['message'] ?? '', $context);
        if (empty($message)) {
            throw new \Exception("SMS message is required");
        }

        $workspaceId = (int) ($contact['workspace_id'] ?? 0);
        $smsService->storeMessage((int) $contactId, (string) $contact['phone'], $message, [
            'workspace_id' => $workspaceId,
            'user_id' => (int) ($context['user_id'] ?? 0) ?: null,
            'idempotency_key' => 'automation:' . hash('sha256', json_encode([
                'workspace_id' => $workspaceId,
                'contact_id' => (int) $contactId,
                'action' => $action,
                'context_execution_id' => $context['execution_id'] ?? $context['workflow_execution_id'] ?? null,
            ], JSON_UNESCAPED_SLASHES) ?: ''),
        ]);
    }

    /**
     * Execute remove_tag action
     */
    private function executeRemoveTag(array $action, array $context): void
    {
        $contactId = $context['contact_id'];
        $tagId = $action['tag_id'] ?? null;
        $tagName = $action['tag_name'] ?? null;

        if (!$tagId && $tagName) {
            $tag = Database::queryOne("SELECT id FROM tags WHERE name = ?", [$tagName]);
            $tagId = $tag ? (int) $tag['id'] : null;
        }

        if (!$tagId) {
            throw new \Exception("Tag ID or name is required");
        }

        $tags = new Tags();
        $tags->unassign($tagId, 'contact', $contactId);
    }

    /**
     * Execute add_tag action
     */
    private function executeAddTag(array $action, array $context): void
    {
        $contactId = $context['contact_id'];
        $tagId = $action['tag_id'] ?? null;
        $tagName = $action['tag_name'] ?? $action['tag'] ?? null;
        
        if (!$tagId && $tagName) {
            // Find or create tag
            $tag = Database::queryOne(
                "SELECT id FROM tags WHERE name = ?",
                [$tagName]
            );
            
            if (!$tag) {
                // Create tag
                $createdBy = $context['user_id'] ?? $_SESSION['user_id'] ?? 1;
                Database::execute(
                    "INSERT INTO tags (name, created_by) VALUES (?, ?)",
                    [$tagName, $createdBy]
                );
                $tagId = (int) Database::lastInsertId();
            } else {
                $tagId = (int) $tag['id'];
            }
        }
        
        if (!$tagId) {
            throw new \Exception("Tag ID or name is required");
        }
        
        // Check if tag already assigned
        $exists = Database::queryOne(
            "SELECT id FROM tag_assignments WHERE tag_id = ? AND entity_type = 'contact' AND entity_id = ?",
            [$tagId, $contactId]
        );
        
        if (!$exists) {
            Database::execute(
                "INSERT INTO tag_assignments (tag_id, entity_type, entity_id) VALUES (?, 'contact', ?)",
                [$tagId, $contactId]
            );
        }
    }
    
    /**
     * Execute change_stage action
     */
    private function executeChangeStage(array $action, array $context): void
    {
        $stage = $action['stage'] ?? null;
        if (!$stage) {
            throw new \Exception("Stage is required");
        }
        
        $contact = Database::queryOne('SELECT workspace_id, stage FROM contacts WHERE id = ? LIMIT 1', [(int) $context['contact_id']]);
        Database::execute("UPDATE contacts SET stage = ? WHERE id = ?", [$stage, $context['contact_id']]);
        if ($contact) {
            (new \CRM\Services\ContactStageHistoryService())->record(
                (int) $contact['workspace_id'],
                (int) $context['contact_id'],
                (string) ($contact['stage'] ?? ''),
                (string) $stage,
                null,
                'automation_engine'
            );
        }
    }
    
    /**
     * Execute create_task action
     */
    private function executeCreateTask(array $action, array $context): void
    {
        $title = $this->replaceTokens($action['title'] ?? 'Task', $context);
        $description = $this->replaceTokens($action['description'] ?? '', $context);
        $dueDate = $action['due_date'] ?? null;
        if ($dueDate && (str_starts_with((string)$dueDate, '+') || str_starts_with((string)$dueDate, '-'))) {
            $dueDate = date('Y-m-d H:i:s', strtotime($dueDate));
        }
        $assignedTo = $action['assigned_to'] ?? $action['user_id'] ?? $context['user_id'] ?? null;
        $priority = $action['priority'] ?? 'medium';
        $contactId = $context['contact_id'];
        $createdBy = $context['user_id'] ?? $_SESSION['user_id'] ?? 1;
        $executionId = trim((string) ($context['workflow_execution_id'] ?? ''));
        $stepId = trim((string) ($action['step_id'] ?? $action['id'] ?? ''));
        
        $tasks = new \CRM\Modules\Tasks();
        $tasks->create([
            'title' => $title,
            'description' => $description,
            'contact_id' => $contactId,
            'assigned_to' => $assignedTo,
            'created_by' => $createdBy,
            'priority' => $priority,
            'due_date' => $dueDate,
            'status' => 'pending',
            'source_surface' => 'workflow',
            'source_capability_key' => (string) ($action['source_capability_key'] ?? 'workflow.create_task'),
            'source_plugin_key' => !empty($action['source_plugin_key']) ? (string) $action['source_plugin_key'] : null,
            'source_run_id' => !empty($context['workflow_execution_id']) ? (string) $context['workflow_execution_id'] : null,
            'origin_type' => 'automation',
            'completion_mode' => 'review',
            'automation_dedupe_key' => $executionId !== '' && $stepId !== ''
                ? 'workflow:' . substr($executionId . ':' . $stepId, 0, 170)
                : null,
            'metadata_json' => [
                'source_surface' => 'workflow',
                'workflow_execution_id' => $executionId !== '' ? $executionId : null,
                'workflow_step_id' => $stepId !== '' ? $stepId : null,
            ],
        ]);
    }
    
    /**
     * Execute assign_to_user action
     */
    private function executeAssignToUser(array $action, array $context): void
    {
        $userId = $action['user_id'] ?? null;
        if (!$userId) {
            throw new \Exception("User ID is required");
        }
        
        Database::execute(
            "UPDATE contacts SET assigned_to = ? WHERE id = ?",
            [$userId, $context['contact_id']]
        );
    }
    
    /**
     * Execute wait_for_days action
     */
    private function executeWaitForDays(array $action, array $context): void
    {
        $days = (int)($action['days'] ?? 1);
        $hours = (int)($action['hours'] ?? 0);
        $minutes = (int)($action['minutes'] ?? 0);
        $executionId = $context['execution_id'] ?? null;
        $workflowId = $context['workflow_id'] ?? null;
        $actionIndex = $context['action_index'] ?? 0;
        $businessHoursOnly = !empty($action['business_hours_only']);
        $timezone = $action['timezone'] ?? 'UTC';
        
        if (!$executionId || !$workflowId) {
            // Can't schedule without execution context
            throw new \Exception("Execution context required for wait_for_days action");
        }
        
        // Calculate delay
        $delayString = '';
        if ($days > 0) {
            $delayString .= "+{$days} days";
        }
        if ($hours > 0) {
            $delayString .= " +{$hours} hours";
        }
        if ($minutes > 0) {
            $delayString .= " +{$minutes} minutes";
        }
        
        if (empty($delayString)) {
            $delayString = "+1 day"; // Default
        }
        
        // Calculate scheduled time
        $scheduledFor = new \DateTime($delayString);
        
        // Adjust for business hours if needed
        if ($businessHoursOnly) {
            $this->adjustForBusinessHours($scheduledFor);
        }
        
        // Use WorkflowScheduler to schedule
        try {
            $scheduler = new \CRM\Services\WorkflowScheduler();
            $scheduler->scheduleAction($workflowId, $executionId, $actionIndex + 1, $scheduledFor, $timezone);
        } catch (\Exception $e) {
            // Fallback to direct database insert if scheduler not available
            try {
                Database::execute(
                    "INSERT INTO scheduled_workflow_actions 
                     (workflow_id, execution_id, action_index, scheduled_for, timezone, status) 
                     VALUES (?, ?, ?, ?, ?, 'pending')",
                    [
                        $workflowId,
                        $executionId,
                        $actionIndex + 1,
                        $scheduledFor->format('Y-m-d H:i:s'),
                        $timezone
                    ]
                );
            } catch (\Exception $dbError) {
                error_log("Could not schedule workflow action: " . $dbError->getMessage());
                throw new \Exception("Failed to schedule workflow action: " . $e->getMessage());
            }
        }
    }
    
    /**
     * Adjust scheduled time for business hours (9 AM - 5 PM, Mon-Fri)
     */
    private function adjustForBusinessHours(\DateTime $scheduledFor): void
    {
        $hour = (int)$scheduledFor->format('H');
        $dayOfWeek = (int)$scheduledFor->format('w'); // 0 = Sunday, 6 = Saturday
        
        // If it's weekend, move to next Monday
        if ($dayOfWeek === 0) {
            $scheduledFor->modify('+1 day');
        } elseif ($dayOfWeek === 6) {
            $scheduledFor->modify('+2 days');
        }
        
        // If before 9 AM, move to 9 AM
        if ($hour < 9) {
            $scheduledFor->setTime(9, 0);
        }
        // If after 5 PM, move to next day 9 AM
        elseif ($hour >= 17) {
            $scheduledFor->modify('+1 day');
            $scheduledFor->setTime(9, 0);
            
            // Check if next day is weekend
            $dayOfWeek = (int)$scheduledFor->format('w');
            if ($dayOfWeek === 0) {
                $scheduledFor->modify('+1 day');
            } elseif ($dayOfWeek === 6) {
                $scheduledFor->modify('+2 days');
            }
        }
    }
    
    /**
     * Execute update_contact_field action
     */
    private function executeUpdateContactField(array $action, array $context): void
    {
        $field = $action['field'] ?? null;
        $value = $this->replaceTokens($action['value'] ?? '', $context);
        
        if (!$field) {
            throw new \Exception("Field name is required");
        }
        
        // Whitelist allowed fields
        $allowedFields = ['first_name', 'last_name', 'email', 'phone', 'company', 'job_title', 'stage', 'assigned_to'];
        if (!in_array($field, $allowedFields)) {
            throw new \Exception("Field '$field' is not allowed to be updated via workflow");
        }
        
        Database::execute(
            "UPDATE contacts SET {$field} = ? WHERE id = ?",
            [$value, $context['contact_id']]
        );
    }
    
    /**
     * Execute create_deal action
     */
    private function executeCreateDeal(array $action, array $context): void
    {
        $title = $this->replaceTokens($action['name'] ?? $action['title'] ?? 'Deal', $context);
        $amount = (float)($action['amount'] ?? 0);
        $stage = $action['stage'] ?? 'prospecting';
        $contactId = $context['contact_id'];
        $assignedTo = $action['assigned_to'] ?? $context['user_id'] ?? null;
        $createdBy = $context['user_id'] ?? $_SESSION['user_id'] ?? 1;
        
        Database::execute(
            "INSERT INTO deals (title, contact_id, value, stage, assigned_to, created_by) 
             VALUES (?, ?, ?, ?, ?, ?)",
            [$title, $contactId, $amount, $stage, $assignedTo, $createdBy]
        );
    }

    /**
     * Execute update_deal_stage action
     */
    private function executeUpdateDealStage(array $action, array $context): void
    {
        $stage = $action['stage'] ?? null;
        $dealId = $action['deal_id'] ?? $context['deal_id'] ?? null;

        if (!$stage) {
            throw new \Exception("Stage is required");
        }

        $allowedStages = ['prospecting', 'qualification', 'proposal', 'negotiation', 'closed_won', 'closed_lost'];
        if (!in_array($stage, $allowedStages)) {
            throw new \Exception("Invalid stage: $stage");
        }

        if ($dealId) {
            $deals = new Deals();
            $deals->update((int) $dealId, ['stage' => $stage]);
        } else {
            $contactId = $context['contact_id'];
            $deal = Database::queryOne(
                "SELECT id FROM deals WHERE contact_id = ? ORDER BY created_at DESC LIMIT 1",
                [$contactId]
            );
            if ($deal) {
                $deals = new Deals();
                $deals->update((int) $deal['id'], ['stage' => $stage]);
            } else {
                throw new \Exception("No deal found for contact");
            }
        }
    }

    /**
     * Execute add_to_deal action - link contact to deal or create deal
     */
    private function executeAddToDeal(array $action, array $context): void
    {
        $contactId = $context['contact_id'];
        $dealId = $action['deal_id'] ?? null;

        if ($dealId) {
            $deals = new Deals();
            $deals->update((int) $dealId, ['contact_id' => $contactId]);
        } else {
            $title = $this->replaceTokens($action['title'] ?? 'Deal for contact', $context);
            $stage = $action['stage'] ?? 'prospecting';
            $deals = new Deals();
            $deals->create([
                'title' => $title,
                'contact_id' => $contactId,
                'stage' => $stage,
                'value' => (float)($action['value'] ?? 0)
            ]);
        }
    }

    /**
     * Execute add_note action
     */
    private function executeAddNote(array $action, array $context): void
    {
        $note = $this->replaceTokens($action['note'] ?? $action['content'] ?? '', $context);
        $title = $this->replaceTokens($action['title'] ?? '', $context);
        $contactId = $context['contact_id'];
        $createdBy = $context['user_id'] ?? $_SESSION['user_id'] ?? 1;
        
        if (empty($note)) {
            throw new \Exception("Note content is required");
        }
        
        Database::execute(
            "INSERT INTO notes (entity_type, entity_id, title, content, created_by) 
             VALUES ('contact', ?, ?, ?, ?)",
            [$contactId, $title, $note, $createdBy]
        );
    }

    /**
     * Execute create_activity action
     */
    private function executeCreateActivity(array $action, array $context): void
    {
        $contactId = $context['contact_id'];
        $activityType = $action['activity_type'] ?? 'workflow_activity';
        $description = $this->replaceTokens($action['description'] ?? '', $context);

        $activities = new Activities();
        $activities->log($contactId, $activityType, $description);
    }

    /**
     * Execute update_lead_score action
     */
    private function executeUpdateLeadScore(array $action, array $context): void
    {
        $score = (int)($action['score'] ?? 0);
        $operation = $action['operation'] ?? 'set'; // set, add, subtract
        $contactId = (int) ($context['contact_id'] ?? 0);
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId((int) ($context['workspace_id'] ?? 0) ?: null);
        
        $contact = Database::queryOne(
            "SELECT lead_score, engagement_score, ml_score, ai_score
             FROM contacts
             WHERE workspace_id = ? AND id = ?",
            [$workspaceId, $contactId]
        );

        if (!$contact) {
            throw new \RuntimeException('Contact not found in the active workspace.');
        }
        
        $currentScore = (int)($contact['lead_score'] ?? 0);
        
        switch ($operation) {
            case 'add':
                $newScore = $currentScore + $score;
                break;
            case 'subtract':
                $newScore = max(0, $currentScore - $score);
                break;
            default:
                $newScore = $score;
        }

        $newScore = max(0, min(100, (int) $newScore));

        $metadata = [
            'source' => 'workflow_update_lead_score',
            'workflow_action' => $action,
            'last_recalculated_at' => date('c'),
            'component_availability' => [
                'engagement' => true,
                'ml' => $contact['ml_score'] !== null,
                'ai' => (int) ($contact['ai_score'] ?? 0) > 0,
            ],
            'fallback_policy' => 'workflow score adjustment; component weights unchanged',
        ];
        
        Database::execute(
            "UPDATE contacts
             SET lead_score = ?,
                 score_recalculated_at = CURRENT_TIMESTAMP,
                 score_metadata_json = ?
             WHERE workspace_id = ? AND id = ?",
            [$newScore, json_encode($metadata), $workspaceId, $contactId]
        );

        if ($currentScore !== $newScore) {
            $payload = [
                'contact_id' => $contactId,
                'workspace_id' => $workspaceId,
                'previous_score' => $currentScore,
                'current_score' => $newScore,
                'lead_score' => $newScore,
                'component_scores' => [
                    'engagement' => (int) ($contact['engagement_score'] ?? 0),
                    'ml' => $contact['ml_score'] === null ? null : (int) $contact['ml_score'],
                    'ai' => (int) ($contact['ai_score'] ?? 0),
                ],
                'source' => 'workflow_update_lead_score',
                'score_metadata' => $metadata,
            ];

            EventBus::publish('contact.score_changed', $payload);
            EventBus::publish('contact.updated', [
                'contact_id' => $contactId,
                'workspace_id' => $workspaceId,
                'changes' => ['lead_score' => ['old' => $currentScore, 'new' => $newScore]],
                'source' => 'workflow_update_lead_score',
            ]);
        }
    }
    
    /**
     * Execute call_webhook action
     */
    private function executeCallWebhook(array $action, array $context): void
    {
        $url = $action['url'] ?? null;
        if (!$url) {
            throw new \Exception("Webhook URL is required");
        }
        
        $method = strtoupper($action['method'] ?? 'POST');
        $headers = $action['headers'] ?? [];
        $body = $action['body'] ?? $context;
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        if ($method === 'POST' || $method === 'PUT') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            if (empty($headers['Content-Type'])) {
                $headers['Content-Type'] = 'application/json';
            }
        }
        
        if (!empty($headers)) {
            $headerArray = [];
            foreach ($headers as $key => $value) {
                $headerArray[] = "$key: $value";
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headerArray);
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode >= 400) {
            throw new \Exception("Webhook call failed with HTTP $httpCode");
        }
    }
    
    /**
     * Execute apply_smart_tags action - AI suggests and applies tags to contact
     */
    private function executeApplySmartTags(array $action, array $context): void
    {
        $contactId = $context['contact_id'] ?? null;
        if (!$contactId) {
            return;
        }
        try {
            $smartTagging = new \CRM\Modules\SmartTagging();
            $suggestions = $smartTagging->suggestTagsForContact((int) $contactId);
            if (empty($suggestions)) {
                return;
            }
            $tags = new \CRM\Modules\Tags();
            foreach ($suggestions as $name) {
                $tag = $tags->getByName($name);
                if (!$tag) {
                    $tagId = $tags->create(['name' => $name]);
                } else {
                    $tagId = $tag['id'];
                }
                $tags->assign($tagId, 'contact', (int) $contactId);
            }
        } catch (\Throwable $e) {
            error_log("Apply smart tags failed: " . $e->getMessage());
        }
    }

    /**
     * Execute send_in_app_notification action
     */
    private function executeSendInAppNotification(array $action, array $context): void
    {
        $userId = $action['user_id'] ?? $context['assigned_to'] ?? null;
        if (!$userId) {
            $contact = Database::queryOne("SELECT assigned_to FROM contacts WHERE id = ?", [$context['contact_id']]);
            $userId = $contact['assigned_to'] ?? null;
        }
        if (!$userId) {
            throw new \Exception("User ID or assigned_to is required for notification");
        }

        $title = $this->replaceTokens($action['title'] ?? 'Notification', $context);
        $message = $this->replaceTokens($action['message'] ?? '', $context);
        $type = $action['notification_type'] ?? 'workflow_notification';

        $notifications = new Notifications();
        $notifications->create(
            (int) $userId,
            $type,
            $title,
            $message,
            [
                'entity_type' => 'contact',
                'entity_id' => $context['contact_id'],
                'link' => publicUrl('contact_view.php?id=' . $context['contact_id'])
            ]
        );
    }

    /**
     * Execute remove_from_workflow action - exclude contact from future workflow runs
     */
    private function executeRemoveFromWorkflow(array $action, array $context): void
    {
        $workflowId = $action['workflow_id'] ?? $context['workflow_id'] ?? null;
        $contactId = $context['contact_id'];

        if (!$workflowId) {
            throw new \Exception("Workflow ID is required for remove_from_workflow");
        }

        try {
            Database::execute(
                "INSERT IGNORE INTO workflow_exclusions (workflow_id, contact_id) VALUES (?, ?)",
                [$workflowId, $contactId]
            );
        } catch (\Exception $e) {
            if (strpos($e->getMessage(), "doesn't exist") !== false) {
                error_log("workflow_exclusions table may not exist: " . $e->getMessage());
            } else {
                throw $e;
            }
        }
    }

    /**
     * Get workflow by ID
     */
    public function getWorkflow(int $id): ?array
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId();
        return Database::queryOne(
            "SELECT * FROM workflows WHERE workspace_id = ? AND id = ?",
            [$workspaceId, $id]
        );
    }
    
    /**
     * Update workflow
     */
    public function updateWorkflow(int $id, string $name, array $trigger, array $conditions, array $actions, bool $isActive = true): bool
    {
        $workflow = $this->getWorkflow($id);
        if (!$workflow) {
            throw new \Exception("Workflow not found");
        }
        
        if (!in_array($trigger['type'], $this->triggers)) {
            throw new \Exception("Invalid trigger type");
        }
        
        foreach ($actions as $action) {
            if (!in_array($action['type'], $this->actions)) {
                throw new \Exception("Invalid action type: " . $action['type']);
            }
        }
        
        Database::execute(
            "UPDATE workflows SET name = ?, trigger_config = ?, conditions = ?, actions = ?, is_active = ? WHERE workspace_id = ? AND id = ?",
            [
                $name,
                json_encode($trigger),
                json_encode($conditions),
                json_encode($actions),
                $isActive ? 1 : 0,
                (int) ($workflow['workspace_id'] ?? $this->workspaceScope->requireActiveWorkspaceId()),
                $id
            ]
        );

        \CRM\Services\WorkflowExecutionService::invalidateWorkflowCache($id);

        return true;
    }
    
    /**
     * Delete workflow
     */
    public function deleteWorkflow(int $id): bool
    {
        $workflow = $this->getWorkflow($id);
        if (!$workflow) {
            return false;
        }
        
        Database::execute("DELETE FROM workflows WHERE workspace_id = ? AND id = ?", [(int) ($workflow['workspace_id'] ?? $this->workspaceScope->requireActiveWorkspaceId()), $id]);
        \CRM\Services\WorkflowExecutionService::invalidateWorkflowCache($id);
        return true;
    }
    
    /**
     * Replace tokens in string
     */
    private function replaceTokens(string $text, array $context): string
    {
        // Replace context variables
        foreach ($context as $key => $value) {
            if (is_scalar($value)) {
                $text = str_replace('{' . $key . '}', (string)$value, $text);
            }
        }
        
        // Replace contact fields if contact_id exists
        if (isset($context['contact_id'])) {
            $contact = Database::queryOne(
                "SELECT * FROM contacts WHERE id = ?",
                [$context['contact_id']]
            );
            
            if ($contact) {
                foreach ($contact as $key => $value) {
                    if (is_scalar($value)) {
                        $text = str_replace('{contact.' . $key . '}', (string)$value, $text);
                        $text = str_replace('{' . $key . '}', (string)$value, $text);
                    }
                }
            }
        }

        $deal = $this->resolveDealForTokenReplacement($context);
        if ($deal) {
            foreach ($deal as $key => $value) {
                if (is_scalar($value)) {
                    $text = str_replace('{deal.' . $key . '}', (string) $value, $text);
                }
            }
        }
        
        return $text;
    }

    private function resolveDealForTokenReplacement(array $context): ?array
    {
        try {
            $workspaceId = $this->resolveWorkspaceIdFromContext($context);
            $hasWorkspaceColumn = Database::columnExists('deals', 'workspace_id');

            if (!empty($context['deal_id'])) {
                $sql = "SELECT * FROM deals WHERE id = ?";
                $params = [(int) $context['deal_id']];
                if ($hasWorkspaceColumn && $workspaceId > 0) {
                    $sql .= " AND workspace_id = ?";
                    $params[] = $workspaceId;
                }
                $sql .= " LIMIT 1";

                $deal = Database::queryOne($sql, $params);
                return $deal ?: null;
            }

            if (!empty($context['contact_id'])) {
                $sql = "SELECT * FROM deals WHERE contact_id = ?";
                $params = [(int) $context['contact_id']];
                if ($hasWorkspaceColumn && $workspaceId > 0) {
                    $sql .= " AND workspace_id = ?";
                    $params[] = $workspaceId;
                }
                $sql .= " ORDER BY created_at DESC, id DESC LIMIT 1";

                $deal = Database::queryOne($sql, $params);
                return $deal ?: null;
            }
        } catch (\Throwable $e) {
            return null;
        }

        return null;
    }
    
    /**
     * Get available triggers
     */
    public function getTriggers(): array
    {
        return $this->triggers;
    }
    
    /**
     * Get available actions
     */
    public function getActions(): array
    {
        try {
            return $this->workflowCapabilities->actionsForWorkspace(
                $this->workspaceScope->requireActiveWorkspaceId(),
                (int) ($_SESSION['user_id'] ?? 0)
            );
        } catch (\Throwable $e) {
            return $this->actions;
        }
    }

    private function executePluginWorkflowAction(array $action, array $context, int $workspaceId): void
    {
        $capability = $this->workflowCapabilities->getActionCapability(
            (string) ($action['type'] ?? ''),
            $workspaceId,
            (int) ($context['user_id'] ?? $_SESSION['user_id'] ?? 0)
        );
        if (!$capability || !empty($capability['native'])) {
            throw new \Exception("Unknown action type: " . ($action['type'] ?? ''));
        }

        $handler = (new PluginRuntimeRegistryService())->resolveHandler($capability);
        if (!$handler instanceof WorkflowPluginCapabilityInterface) {
            throw new \Exception("Workflow action handler is unavailable: " . ($action['type'] ?? ''));
        }

        $handler->executeWorkflowAction($capability, $action, $context);
    }

    private function resolveWorkspaceIdFromContext(array $context): int
    {
        if (!empty($context['workspace_id'])) {
            return (int) $context['workspace_id'];
        }
        try {
            return $this->workspaceScope->requireActiveWorkspaceId();
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
