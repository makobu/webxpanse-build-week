<?php
/**
 * Email Assistant Handler Module
 *
 * Processes inbound admin emails to the system address: answers questions,
 * executes instructions (e.g. create task), and sends replies.
 */

namespace CRM\Modules;

use CRM\Database;
use CRM\Security;
use CRM\Services\AIService;
use CRM\Services\AssistantActionRuntimeConfig;
use CRM\Services\AssistantEmailRenderer;
use CRM\Services\EmailAssistantApplicationService;
use CRM\Services\EmailAssistantActionPlanner;
use CRM\Services\AssistantActionAuthorizationService;
use CRM\Services\EmailAssistantExecutionService;
use CRM\Services\EmailAssistantPolicyBridge;
use CRM\Services\EmailAssistantResolver;
use CRM\Services\EmailAssistantRuntimeConfig;
use CRM\Services\EmailAssistantThreadContextService;
use CRM\Services\SMTPClient;
use CRM\Services\TaskAssignmentAccessService;
use CRM\Services\WorkspaceContext;

class EmailAssistantHandler
{
    private AIService $aiService;
    private SMTPClient $smtp;
    private EmailAssistantResolver $resolver;
    private EmailAssistantActionPlanner $planner;
    private EmailAssistantExecutionService $executionService;
    private EmailAssistantThreadContextService $threadContextService;
    private EmailAssistantPolicyBridge $policyBridge;
    private EmailAssistantApplicationService $applicationService;
    private EmailAssistantRuntimeConfig $runtimeConfig;
    private AssistantActionAuthorizationService $actionAuthorization;
    private string $assistantType;
    private string $assistantLabel;

    public function __construct(string $assistantType = AssistantActionRuntimeConfig::ASSISTANT_EMAIL)
    {
        $this->assistantType = AssistantActionRuntimeConfig::normalizeType($assistantType);
        $this->assistantLabel = AssistantActionRuntimeConfig::labelFor($this->assistantType);
        $this->aiService = new AIService();
        $this->smtp = new SMTPClient('assistant');
        $this->resolver = new EmailAssistantResolver();
        $this->planner = new EmailAssistantActionPlanner();
        $this->executionService = new EmailAssistantExecutionService($this->assistantType);
        $this->threadContextService = new EmailAssistantThreadContextService();
        $this->policyBridge = new EmailAssistantPolicyBridge();
        $this->applicationService = new EmailAssistantApplicationService($this->assistantType);
        $this->runtimeConfig = new EmailAssistantRuntimeConfig($this->assistantType);
        $this->actionAuthorization = new AssistantActionAuthorizationService();
    }

    /**
     * Process inbound admin email to system address
     */
    public function handleInbound(array $emailData): void
    {
        $messageId = $emailData['message_id'] ?? null;
        $fromEmail = strtolower(trim($emailData['from_email'] ?? ''));
        $body = trim($emailData['body'] ?? '');
        $subject = $emailData['subject'] ?? '';

        if (empty($fromEmail) || empty($body)) {
            error_log('EmailAssistantHandler: Missing from_email or body');
            return;
        }

        $resolvedWorkspace = $this->resolveInboundWorkspace($fromEmail, $emailData);
        $workspaceId = (int) ($resolvedWorkspace['workspace_id'] ?? 0);
        if (($resolvedWorkspace['status'] ?? '') === 'ambiguous') {
            error_log("EmailAssistantHandler: Ambiguous workspace for {$fromEmail}");
            $this->sendReply(
                $fromEmail,
                'Re: ' . $subject,
                "I found more than one active workspace for your email address, so I cannot safely choose where to run this instruction. Please switch to the intended workspace and try again, or use a workspace-specific assistant mailbox.",
                $messageId,
                $messageId
            );
            return;
        }

        if ($workspaceId <= 0) {
            error_log("EmailAssistantHandler: No workspace found for {$fromEmail}");
            $this->sendReply(
                $fromEmail,
                'Re: ' . $subject,
                "Sorry, I could not find an active workspace for this email address. Please use an account that belongs to the intended workspace.",
                $messageId,
                $messageId
            );
            return;
        }

        $user = $this->resolveAuthorizedUserForWorkspace($fromEmail, $workspaceId);
        if (!$user) {
            error_log("EmailAssistantHandler: Sender {$fromEmail} is not authorized for workspace {$workspaceId}");
            $previousRuntime = WorkspaceContext::runtimeSnapshot();
            try {
                WorkspaceContext::activateRuntimeWorkspace($workspaceId);
                $this->smtp = new SMTPClient('assistant');
                $this->sendReply(
                    $fromEmail,
                    'Re: ' . $subject,
                    "You are not authorized to use the Email Assistant for this workspace. Only workspace admins or whitelisted addresses can send instructions.",
                    $messageId,
                    $messageId
                );
            } finally {
                if ($previousRuntime !== null) {
                    WorkspaceContext::restoreRuntimeWorkspace($previousRuntime);
                } else {
                    WorkspaceContext::clearRuntimeWorkspace();
                }
            }
            return;
        }
        $userId = (int) $user['id'];

        $previousRuntime = WorkspaceContext::runtimeSnapshot();
        WorkspaceContext::activateRuntimeWorkspace($workspaceId, $userId, (string) ($user['role_slug'] ?? 'admin'));
        $this->smtp = new SMTPClient('assistant');

        try {
            // If an allowed sender list is configured, enforce it (defense-in-depth).
            if (!$this->runtimeConfig->isSenderAllowed($fromEmail, $workspaceId)) {
                error_log("EmailAssistantHandler: Sender {$fromEmail} not in workspace allowed senders");
                $this->sendReply(
                    $fromEmail,
                    'Re: ' . $subject,
                    "You are not authorized to use the Email Assistant. Only whitelisted addresses can send instructions.",
                    $messageId,
                    $messageId
                );
                return;
            }

            // Check for duplicate (already processed)
            if ($messageId) {
                $existing = Database::queryOne(
                    "SELECT id
                     FROM email_assistant_messages
                     WHERE workspace_id = ?
                       AND message_id = ?
                       AND direction = 'inbound'",
                    [$workspaceId, $messageId]
                );
                if ($existing) {
                    error_log('EmailAssistantHandler: Duplicate message_id, skipping');
                    return;
                }
            }

            // Insert inbound message (email_assistant_messages.uuid is CHAR(36))
            $uuid = uuid_v4();
            Database::execute(
                "INSERT INTO email_assistant_messages (workspace_id, uuid, user_id, from_email, to_email, subject, body, body_html, message_id, in_reply_to, direction)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'inbound')",
                [
                    $workspaceId,
                    $uuid,
                    $userId,
                    $fromEmail,
                    $emailData['to_email'] ?? '',
                    $subject,
                    $body,
                    $emailData['body_html'] ?? null,
                    $messageId,
                    $emailData['in_reply_to'] ?? null
                ]
            );

            $processed = $this->processAssistantMessage($body, $userId);
            $replyBody = (string) ($processed['reply_body'] ?? $this->getDisabledMessage());
            $primaryIntent = (string) ($processed['primary_intent'] ?? 'question');
            $commandResults = (array) ($processed['command_results'] ?? []);

            // Send reply
            $replySubject = (stripos($subject, 'Re:') === 0) ? $subject : 'Re: ' . $subject;
            $this->sendReply($fromEmail, $replySubject, $replyBody, $messageId, $messageId);

            $assistantFrom = $this->smtp->getFromEmail() ?? '';
            $commandResultJson = !empty($commandResults) ? json_encode(['instructions' => $commandResults, 'primary_intent' => $primaryIntent]) : null;

            // Insert outbound message (email_assistant_messages.uuid is CHAR(36))
            $outUuid = uuid_v4();
            Database::execute(
                "INSERT INTO email_assistant_messages (workspace_id, uuid, user_id, from_email, to_email, subject, body, message_id, in_reply_to, direction, intent, command_result)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'outbound', ?, ?)",
                [
                    $workspaceId,
                    $outUuid,
                    $userId,
                    $assistantFrom,
                    $fromEmail,
                    $replySubject,
                    $replyBody,
                    null,
                    $messageId,
                    $primaryIntent,
                    $commandResultJson
                ]
            );
        } finally {
            if ($previousRuntime !== null) {
                WorkspaceContext::restoreRuntimeWorkspace($previousRuntime);
            } else {
                WorkspaceContext::clearRuntimeWorkspace();
            }
        }
    }

    /**
     * @return array{status:string,workspace_id:int}
     */
    private function resolveInboundWorkspace(string $fromEmail, array $emailData): array
    {
        $explicitWorkspaceId = (int) ($emailData['workspace_id'] ?? 0);
        if ($explicitWorkspaceId > 0) {
            return ['status' => 'resolved', 'workspace_id' => $explicitWorkspaceId];
        }

        $contextWorkspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        if ($contextWorkspaceId > 0) {
            return ['status' => 'resolved', 'workspace_id' => $contextWorkspaceId];
        }

        $memberships = Database::query(
            "SELECT wm.workspace_id
             FROM users u
             JOIN workspace_memberships wm ON wm.user_id = u.id
             JOIN workspaces w ON w.id = wm.workspace_id
             WHERE LOWER(u.email) = ?
               AND wm.membership_status = 'active'
             ORDER BY wm.workspace_id ASC",
            [$fromEmail]
        );

        $workspaceIds = array_values(array_unique(array_map(
            static fn(array $row): int => (int) ($row['workspace_id'] ?? 0),
            $memberships
        )));
        $workspaceIds = array_values(array_filter($workspaceIds, static fn(int $id): bool => $id > 0));

        if (count($workspaceIds) === 1) {
            return ['status' => 'resolved', 'workspace_id' => $workspaceIds[0]];
        }

        return [
            'status' => count($workspaceIds) > 1 ? 'ambiguous' : 'missing',
            'workspace_id' => 0,
        ];
    }

    private function resolveAuthorizedUserForWorkspace(string $fromEmail, int $workspaceId): ?array
    {
        $runtimeConfig = $this->runtimeConfig->forWorkspace($workspaceId);
        $whitelistConfigured = !empty($runtimeConfig['allowed_senders']);

        $user = Database::queryOne(
            "SELECT u.id, u.role, wm.role_slug
             FROM users u
             JOIN workspace_memberships wm ON wm.user_id = u.id
             WHERE LOWER(u.email) = ?
               AND wm.workspace_id = ?
               AND wm.membership_status = 'active'
             LIMIT 1",
            [$fromEmail, $workspaceId]
        );

        if (!$user) {
            return null;
        }

        if ($whitelistConfigured) {
            return $user;
        }

        $role = strtolower((string) ($user['role'] ?? ''));
        $workspaceRole = strtolower((string) ($user['role_slug'] ?? ''));
        if ($role === 'admin' || in_array($workspaceRole, ['owner', 'admin', 'superadmin'], true)) {
            return $user;
        }

        return null;
    }

    public function processAssistantMessage(string $body, int $userId): array
    {
        $body = trim($body);
        $instructions = $this->parseInstructions($body);
        $replyBody = '';
        $primaryIntent = 'question';
        $commandResults = [];

        if (count($instructions) >= 2) {
            $parts = [];
            foreach ($instructions as $item) {
                $part = $this->executeSingleInstruction($item['instruction'], $item['intent'], $userId);
                $commandResults[] = [
                    'intent' => $item['intent'],
                    'action_type' => $item['intent'],
                    'success' => $this->isSuccessResponse($part),
                    'error' => $this->isSuccessResponse($part) ? null : $part,
                ];
                if ($part !== '') {
                    $parts[] = $part;
                    $primaryIntent = $item['intent'];
                }
            }
            $replyBody = implode("\n\n", $parts) ?: $this->getDisabledMessage();
        } elseif (count($instructions) === 1) {
            $item = $instructions[0];
            $replyBody = $this->executeSingleInstruction($item['instruction'], $item['intent'], $userId);
            $primaryIntent = $item['intent'];
            $commandResults[] = [
                'intent' => $item['intent'],
                'action_type' => $item['intent'],
                'success' => $this->isSuccessResponse($replyBody),
                'error' => $this->isSuccessResponse($replyBody) ? null : $replyBody,
            ];
        }

        if ($replyBody === '' && count($instructions) <= 1) {
            $intent = $this->classifyIntent($body);
            $primaryIntent = $intent;
            $replyBody = $this->executeSingleInstruction($body, $intent, $userId);
            if (empty($commandResults)) {
                $commandResults[] = [
                    'intent' => $intent,
                    'action_type' => $intent,
                    'success' => $this->isSuccessResponse($replyBody),
                    'error' => $this->isSuccessResponse($replyBody) ? null : $replyBody,
                ];
            }
        }

        if ($replyBody === '') {
            $replyBody = $this->getDisabledMessage();
        }

        return [
            'reply_body' => $replyBody,
            'primary_intent' => $primaryIntent,
            'command_results' => $commandResults,
        ];
    }

    /**
     * Classify intent: question, create_task, create_contact, add_note, get_pipeline, list_tasks, schedule_event, run_report, get_status, unknown
     */
    public function classifyIntent(string $body): string
    {
        $bodyLower = strtolower(trim($body));

        // Keyword fallbacks for instructions
        if (preg_match('/\b(create|add|new)\s+task\b/i', $bodyLower)) {
            return 'create_task';
        }
        if (preg_match('/\btask:\s*.+/i', $bodyLower)) {
            return 'create_task';
        }
        if (preg_match('/\b(create|add|new)\s+contact\b/i', $bodyLower)) {
            return 'create_contact';
        }
        if (preg_match('/\b(update|edit|change)\s+contact\b|\bcontact\s+(update|edit)\b/i', $bodyLower)) {
            return 'update_contact';
        }
        if (preg_match('/\b(delete|remove)\s+contact\b|\bcontact\s+(delete|remove)\b/i', $bodyLower)) {
            return 'delete_contact';
        }
        if (preg_match('/\benrich\s+(contact|john|.*@)\b|\benrich\s+contact\b/i', $bodyLower)) {
            return 'enrich_contact';
        }
        if (preg_match('/\bverify\s+(email|contact)\b|\bemail\s+verif/i', $bodyLower)) {
            return 'verify_contact_email';
        }
        if (preg_match('/\badd\s+note\b|\bnote\s+to\b/i', $bodyLower)) {
            return 'add_note';
        }
        if (preg_match('/\b(list|show)\s*(my\s+)?tasks?\b|\btasks?\s*(list|for)\b/i', $bodyLower)) {
            return 'list_tasks';
        }
        if (preg_match('/\b(schedule|book|create)\s*(event|meeting|appointment)\b/i', $bodyLower)) {
            return 'schedule_event';
        }
        if (preg_match('/\brun\s+report\b|\breport\s*:\s*.+/i', $bodyLower)) {
            return 'run_report';
        }
        if (preg_match('/\b(create|draft|make)\s+(quote|proforma|invoice)\b/i', $bodyLower)) {
            return 'create_invoice';
        }
        if (preg_match('/\b(send)\s+(quote|proforma|invoice)\b/i', $bodyLower)) {
            return 'send_invoice';
        }
        if (preg_match('/\b(finali[sz]e|approve)\s+(quote|proforma|invoice)\b/i', $bodyLower)) {
            return 'finalize_invoice';
        }
        if (preg_match('/\bapprove\s+(commercial|automation)\b|\bapprove\s+approval\b/i', $bodyLower)) {
            return 'approve_commercial_action';
        }
        if (preg_match('/\breject\s+(commercial|automation)\b|\breject\s+approval\b/i', $bodyLower)) {
            return 'reject_commercial_action';
        }
        if (preg_match('/\b(commercial automation|automation state|pending approvals)\b/i', $bodyLower)) {
            return 'summarize_commercial_automation_state';
        }
        if (preg_match('/\b(draft|preview)\s+(customer\s+)?reply\b|\bai\s+reply\b/i', $bodyLower)) {
            return 'draft_customer_reply';
        }
        if (preg_match('/\b(send)\s+(customer\s+)?reply\b/i', $bodyLower)) {
            return 'send_customer_reply';
        }
        if (preg_match('/\b(explain)\s+(quote|invoice)\s+changes\b/i', $bodyLower)) {
            return 'explain_quote_changes';
        }
        if (preg_match('/\b(thread summary|summarize thread|thread state)\b/i', $bodyLower)) {
            return 'summarize_thread_state';
        }
        if (preg_match('/\b(list|show)\s+pending\s+commercial\s+approvals\b/i', $bodyLower)) {
            return 'list_pending_commercial_approvals';
        }
        if (preg_match('/\b(last assistant action|what did the assistant last send)\b/i', $bodyLower)) {
            return 'show_last_assistant_action';
        }
        if (preg_match('/\b(mark)\s+.*\b(paid)\b/i', $bodyLower)) {
            return 'mark_invoice_paid';
        }
        if (preg_match('/\b(convert)\s+(quote|proforma)\b.*\bto\s+invoice\b/i', $bodyLower)) {
            return 'convert_quote_to_invoice';
        }
        if (preg_match('/\b(revise|update|edit)\s+(quote|proforma|invoice)\b/i', $bodyLower)) {
            return 'update_invoice';
        }
        if (preg_match('/\b(list|show)\s+(quotes|proformas|invoices)\b/i', $bodyLower)) {
            return 'list_invoices';
        }
        if (preg_match('/\b(pipeline|deal)\s*(status|summary)?\b|\bhow.*deals?\b/i', $bodyLower)) {
            return 'get_pipeline';
        }

        // Keyword fallbacks for questions
        $questionKeywords = ['how many', 'what is', 'what are', 'list', 'show me', 'tell me', 'who', 'when', 'which', '?', 'how many'];
        foreach ($questionKeywords as $kw) {
            if (strpos($bodyLower, $kw) !== false) {
                return 'question';
            }
        }

        // Use AI for classification if available
        $validIntents = ['question', 'create_task', 'create_contact', 'update_contact', 'delete_contact', 'add_note', 'get_pipeline', 'list_tasks', 'schedule_event', 'run_report', 'get_status', 'enrich_contact', 'verify_contact_email', 'verify_email_value', 'create_invoice', 'update_invoice', 'list_invoices', 'send_invoice', 'finalize_invoice', 'mark_invoice_paid', 'convert_quote_to_invoice', 'approve_commercial_action', 'reject_commercial_action', 'summarize_commercial_automation_state', 'draft_customer_reply', 'send_customer_reply', 'revise_quote_with_context', 'explain_quote_changes', 'summarize_thread_state', 'list_pending_commercial_approvals', 'resolve_assistant_ambiguity', 'show_last_assistant_action', 'rerun_assistant_action', 'unknown'];
        try {
            $result = $this->aiService->process('email_assistant_intent', ['text' => substr($body, 0, 500)]);
            $parsed = json_decode($result, true);
            if ($parsed && !empty($parsed['intent'])) {
                $intent = strtolower($parsed['intent']);
                if (in_array($intent, $validIntents)) {
                    return $intent;
                }
            }
        } catch (\Throwable $e) {
            error_log('EmailAssistantHandler::classifyIntent AI error: ' . $e->getMessage());
        }

        return 'question';
    }

    /**
     * Parse email body into multiple instructions via AI
     * @return array<array{instruction: string, intent: string}>
     */
    private function parseInstructions(string $body): array
    {
        try {
            $result = $this->aiService->process('email_assistant_parse_instructions', ['text' => substr($body, 0, 1500)]);
            $parsed = json_decode($result, true);
            if (is_array($parsed) && !empty($parsed)) {
                $items = [];
                foreach ($parsed as $item) {
                    if (!empty($item['instruction']) && !empty($item['intent'])) {
                        $items[] = [
                            'instruction' => trim($item['instruction']),
                            'intent' => strtolower($item['intent'])
                        ];
                    }
                }
                return $items;
            }
        } catch (\Throwable $e) {
            error_log('EmailAssistantHandler::parseInstructions error: ' . $e->getMessage());
        }
        return [];
    }

    /**
     * Execute a single instruction by intent; routes to appropriate handler
     */
    private function executeSingleInstruction(string $body, string $intent, int $userId): string
    {
        $authorization = $this->actionAuthorization->authorize($intent, $userId);
        if (empty($authorization['allowed'])) {
            $permission = trim((string) ($authorization['permission'] ?? ''));
            return $permission !== ''
                ? "I cannot run that action because your CRM access profile does not include {$permission}."
                : 'I cannot run that action because it requires a workspace owner or administrator.';
        }

        $runtime = $this->runtimeConfig->forWorkspace();
        $qaEnabled = $runtime['enabled'] && $runtime['qa_enabled'];
        $instructionsEnabled = $runtime['enabled'] && $runtime['instructions_enabled'];
        $structuredIntents = [
            'create_invoice',
            'update_invoice',
            'send_invoice',
            'finalize_invoice',
            'mark_invoice_paid',
            'convert_quote_to_invoice',
            'approve_commercial_action',
            'reject_commercial_action',
            'summarize_commercial_automation_state',
            'draft_customer_reply',
            'send_customer_reply',
            'revise_quote_with_context',
            'explain_quote_changes',
            'summarize_thread_state',
            'list_pending_commercial_approvals',
            'show_last_assistant_action',
        ];
        $readOnlyStructuredIntents = [
            'summarize_commercial_automation_state',
            'explain_quote_changes',
            'summarize_thread_state',
            'list_pending_commercial_approvals',
            'show_last_assistant_action',
        ];

        if (in_array($intent, $structuredIntents, true)) {
            $isReadOnlyStructured = in_array($intent, $readOnlyStructuredIntents, true);
            if (!$instructionsEnabled && (!$isReadOnlyStructured || !$qaEnabled)) {
                return $this->getDisabledMessage();
            }
            $disabledActionMessage = $this->disabledActionMessageForIntent($intent, $runtime);
            if ($disabledActionMessage !== null && !$isReadOnlyStructured) {
                return $disabledActionMessage;
            }
            $structured = $this->executeStructuredInstruction($body, $intent, $userId);
            if ($structured !== '') {
                return $structured;
            }
        }

        if ($intent === 'create_task' && $instructionsEnabled) {
            return $this->executeInstruction($body, $userId);
        }
        if (($intent === 'question' || $intent === 'get_status') && $qaEnabled) {
            return $this->answerQuestion($body, $userId);
        }
        if (!$instructionsEnabled) {
            return $qaEnabled ? $this->answerQuestion($body, $userId) : '';
        }
        $disabledActionMessage = $this->disabledActionMessageForIntent($intent, $runtime);
        if ($disabledActionMessage !== null) {
            return $disabledActionMessage;
        }
        if ($intent === 'get_pipeline' && $this->runtimeSkillEnabled($runtime, 'skill_get_pipeline', 'EMAIL_ASSISTANT_SKILL_GET_PIPELINE', false)) {
            return $this->executeGetPipeline($userId);
        }
        if ($intent === 'list_tasks' && $this->runtimeSkillEnabled($runtime, 'skill_list_tasks', 'EMAIL_ASSISTANT_SKILL_LIST_TASKS', false)) {
            return $this->executeListTasks($userId);
        }
        if ($intent === 'create_contact' && $this->runtimeSkillEnabled($runtime, 'skill_create_contact', 'EMAIL_ASSISTANT_SKILL_CREATE_CONTACT', false)) {
            return $this->executeCreateContact($body, $userId);
        }
        if ($intent === 'update_contact' && $this->runtimeSkillEnabled($runtime, 'skill_update_contact', 'EMAIL_ASSISTANT_SKILL_UPDATE_CONTACT', false)) {
            return $this->executeUpdateContact($body, $userId);
        }
        if ($intent === 'delete_contact' && $this->runtimeSkillEnabled($runtime, 'skill_delete_contact', 'EMAIL_ASSISTANT_SKILL_DELETE_CONTACT', false)) {
            return $this->executeDeleteContact($body, $userId);
        }
        if ($intent === 'enrich_contact' && $this->runtimeSkillEnabled($runtime, 'skill_enrich_contact', 'EMAIL_ASSISTANT_SKILL_ENRICH_CONTACT', false)) {
            return $this->executeEnrichContact($body, $userId);
        }
        if (($intent === 'verify_contact_email' || $intent === 'verify_email_value') && $this->runtimeSkillEnabled($runtime, 'skill_verify_email', 'EMAIL_ASSISTANT_SKILL_VERIFY_EMAIL', false)) {
            return $this->executeVerifyEmail($body, $userId);
        }
        if ($intent === 'add_note' && $this->runtimeSkillEnabled($runtime, 'skill_add_note', 'EMAIL_ASSISTANT_SKILL_ADD_NOTE', false)) {
            return $this->executeAddNote($body, $userId);
        }
        if ($intent === 'schedule_event' && $this->runtimeSkillEnabled($runtime, 'skill_schedule_event', 'EMAIL_ASSISTANT_SKILL_SCHEDULE_EVENT', false)) {
            return $this->executeScheduleEvent($body, $userId);
        }
        if ($intent === 'run_report' && $this->runtimeSkillEnabled($runtime, 'skill_run_report', 'EMAIL_ASSISTANT_SKILL_RUN_REPORT', false)) {
            return $this->executeRunReport($body, $userId);
        }
        if ($intent === 'create_invoice' && $this->runtimeSkillEnabled($runtime, 'skill_create_invoice', 'EMAIL_ASSISTANT_SKILL_CREATE_INVOICE', true)) {
            return $this->executeCreateInvoice($body, $userId);
        }
        if ($intent === 'update_invoice' && $this->runtimeSkillEnabled($runtime, 'skill_update_invoice', 'EMAIL_ASSISTANT_SKILL_UPDATE_INVOICE', true)) {
            return $this->executeUpdateInvoice($body, $userId);
        }
        if ($intent === 'list_invoices' && $this->runtimeSkillEnabled($runtime, 'skill_list_invoices', 'EMAIL_ASSISTANT_SKILL_LIST_INVOICES', true)) {
            return $this->executeListInvoices($body, $userId);
        }
        if ($intent === 'send_invoice' && $this->runtimeSkillEnabled($runtime, 'skill_send_invoice', 'EMAIL_ASSISTANT_SKILL_SEND_INVOICE', true)) {
            return $this->executeSendInvoice($body, $userId);
        }
        if ($intent === 'finalize_invoice' && $this->runtimeSkillEnabled($runtime, 'skill_finalize_invoice', 'EMAIL_ASSISTANT_SKILL_FINALIZE_INVOICE', true)) {
            return $this->executeFinalizeInvoice($body, $userId);
        }
        if ($intent === 'mark_invoice_paid' && $this->runtimeSkillEnabled($runtime, 'skill_mark_invoice_paid', 'EMAIL_ASSISTANT_SKILL_MARK_INVOICE_PAID', true)) {
            return $this->executeMarkInvoicePaid($body, $userId);
        }
        if ($intent === 'convert_quote_to_invoice' && $this->runtimeSkillEnabled($runtime, 'skill_convert_invoice', 'EMAIL_ASSISTANT_SKILL_CONVERT_INVOICE', true)) {
            return $this->executeConvertInvoice($body, $userId);
        }
        if ($intent === 'approve_commercial_action') {
            return $this->executeApproveCommercialAction($body, $userId);
        }
        if ($intent === 'reject_commercial_action') {
            return $this->executeRejectCommercialAction($body, $userId);
        }
        if ($intent === 'summarize_commercial_automation_state') {
            return $this->executeSummarizeCommercialAutomationState($body, $userId);
        }

        // Fallback to Q&A if enabled
        if ($qaEnabled || $instructionsEnabled) {
            return $this->answerQuestion($body, $userId);
        }

        return '';
    }

    private function getDisabledMessage(): string
    {
        $delivery = $this->assistantType === AssistantActionRuntimeConfig::ASSISTANT_WHATSAPP ? 'WhatsApp' : 'email';

        return "The {$this->assistantLabel} is configured but Q&A and instructions are disabled. "
            . "Enable them in {$this->assistantLabel} setup to ask questions or create tasks via {$delivery}.";
    }

    /**
     * @param array<string,mixed> $runtime
     */
    private function disabledActionMessageForIntent(string $intent, array $runtime): ?string
    {
        $settings = [
            'get_pipeline' => ['skill_get_pipeline', 'EMAIL_ASSISTANT_SKILL_GET_PIPELINE', false],
            'list_tasks' => ['skill_list_tasks', 'EMAIL_ASSISTANT_SKILL_LIST_TASKS', false],
            'create_contact' => ['skill_create_contact', 'EMAIL_ASSISTANT_SKILL_CREATE_CONTACT', false],
            'update_contact' => ['skill_update_contact', 'EMAIL_ASSISTANT_SKILL_UPDATE_CONTACT', false],
            'delete_contact' => ['skill_delete_contact', 'EMAIL_ASSISTANT_SKILL_DELETE_CONTACT', false],
            'enrich_contact' => ['skill_enrich_contact', 'EMAIL_ASSISTANT_SKILL_ENRICH_CONTACT', false],
            'verify_contact_email' => ['skill_verify_email', 'EMAIL_ASSISTANT_SKILL_VERIFY_EMAIL', false],
            'verify_email_value' => ['skill_verify_email', 'EMAIL_ASSISTANT_SKILL_VERIFY_EMAIL', false],
            'add_note' => ['skill_add_note', 'EMAIL_ASSISTANT_SKILL_ADD_NOTE', false],
            'schedule_event' => ['skill_schedule_event', 'EMAIL_ASSISTANT_SKILL_SCHEDULE_EVENT', false],
            'run_report' => ['skill_run_report', 'EMAIL_ASSISTANT_SKILL_RUN_REPORT', false],
            'create_invoice' => ['skill_create_invoice', 'EMAIL_ASSISTANT_SKILL_CREATE_INVOICE', true],
            'update_invoice' => ['skill_update_invoice', 'EMAIL_ASSISTANT_SKILL_UPDATE_INVOICE', true],
            'list_invoices' => ['skill_list_invoices', 'EMAIL_ASSISTANT_SKILL_LIST_INVOICES', true],
            'send_invoice' => ['skill_send_invoice', 'EMAIL_ASSISTANT_SKILL_SEND_INVOICE', true],
            'finalize_invoice' => ['skill_finalize_invoice', 'EMAIL_ASSISTANT_SKILL_FINALIZE_INVOICE', true],
            'mark_invoice_paid' => ['skill_mark_invoice_paid', 'EMAIL_ASSISTANT_SKILL_MARK_INVOICE_PAID', true],
            'convert_quote_to_invoice' => ['skill_convert_invoice', 'EMAIL_ASSISTANT_SKILL_CONVERT_INVOICE', true],
        ];
        if (!isset($settings[$intent])) {
            return null;
        }

        [$settingKey, $envKey, $default] = $settings[$intent];
        if ($this->runtimeSkillEnabled($runtime, $settingKey, $envKey, $default)) {
            return null;
        }

        $label = ucfirst(str_replace('_', ' ', $intent));
        return "{$this->assistantLabel} action disabled: {$label}. Enable this action in {$this->assistantLabel} setup before running it.";
    }

    /**
     * @param array<string,mixed> $runtime
     */
    private function runtimeSkillEnabled(array $runtime, string $settingKey, string $envKey, bool $default): bool
    {
        return $this->runtimeConfig->actionEnabled($runtime, $settingKey, $envKey, $default);
    }

    /**
     * Infer success from response text (error responses typically start with Failed, Error, Could not, etc.)
     */
    private function isSuccessResponse(string $response): bool
    {
        $r = trim($response);
        if ($r === '') {
            return false;
        }
        $rLower = strtolower($r);
        $errorPrefixes = ['failed', 'error', 'could not', 'please specify', 'contact not found', 'no valid', 'invalid'];
        foreach ($errorPrefixes as $prefix) {
            if (strpos($rLower, $prefix) === 0) {
                return false;
            }
        }
        return true;
    }

    /**
     * Answer CRM question using AI and context
     */
    public function answerQuestion(string $body, int $userId): string
    {
        try {
            $result = $this->applicationService->handleAdviceRequest([
                'intent' => 'question',
                'body' => $body,
                'query' => $body,
                'resolved' => true,
                'confidence' => 1.0,
                'assistant_type' => $this->assistantType,
            ], $userId);
            return (string) ($result['summary_text'] ?? "I couldn't generate an answer. Try rephrasing your question.");
        } catch (\Throwable $e) {
            error_log('EmailAssistantHandler::answerQuestion error: ' . $e->getMessage());
            return "Error answering question: " . $e->getMessage();
        }
    }

    /**
     * Execute instruction (e.g. create task)
     */
    public function executeInstruction(string $body, int $userId): string
    {
        $intent = $this->classifyIntent($body);
        if ($intent !== 'create_task') {
            return $this->answerQuestion($body, $userId);
        }

        try {
            $parsed = $this->aiService->process('email_assistant_parse_task', ['text' => substr($body, 0, 500)]);
            $data = json_decode($parsed, true);
            $title = $data['title'] ?? null;
            $dueDate = $data['due_date'] ?? null;

            if (!$title) {
                // Fallback: use first line or whole body as title
                $lines = preg_split('/\r\n|\r|\n/', trim($body), 2);
                $firstLine = trim($lines[0] ?? $body);
                $title = preg_replace('/^(create|add|new)\s+task\s*:?\s*/i', '', $firstLine);
                $title = trim($title) ?: 'Task from email';
            }

            $tasks = new Tasks();
            $assignment = (new TaskAssignmentAccessService())->resolveAiAssignee([$userId], [
                'title' => $title,
                'description' => $body,
                'metadata_json' => [
                    'source_surface' => $this->assistantSourceSurface(),
                    'assistant_type' => $this->assistantType,
                ],
            ]);
            $taskId = $tasks->create([
                'title' => Security::sanitizeInput($title, 'string'),
                'description' => '',
                'assigned_to' => $assignment['assigned_to'],
                'assignment_mode' => 'ai',
                'created_by' => $userId,
                'status' => 'pending',
                'priority' => 'medium',
                'due_date' => $dueDate,
                'origin_type' => 'ai',
                'completion_mode' => 'review',
                'automation_dedupe_key' => 'assistant:' . hash('sha256', implode('|', [
                    $this->assistantType,
                    $userId,
                    trim($body),
                ])),
                'metadata_json' => [
                    'source_surface' => $this->assistantSourceSurface(),
                    'assistant_type' => $this->assistantType,
                    'assignment_resolution' => $assignment,
                    'instruction_fingerprint' => hash('sha256', trim($body)),
                ],
            ]);

            $message = "Task created: \"{$title}\" (ID: {$taskId}). " . ($dueDate ? "Due: {$dueDate}." : '');
            if (empty($assignment['assigned_to'])) {
                $message .= " No eligible AI assignee was found, so the task was left unassigned.";
            }

            return $message;
        } catch (\Throwable $e) {
            error_log('EmailAssistantHandler::executeInstruction error: ' . $e->getMessage());
            return "Failed to create task: " . $e->getMessage();
        }
    }

    /**
     * Get pipeline status as readable text
     */
    private function executeGetPipeline(int $userId): string
    {
        try {
            $deals = new Deals();
            $stats = $deals->getPipelineStats();
            $lines = [];

            if (!empty($stats['by_stage'])) {
                foreach ($stats['by_stage'] as $row) {
                    $stage = str_replace('_', ' ', $row['stage']);
                    $count = (int) $row['count'];
                    $value = (float) ($row['total_value'] ?? 0);
                    $lines[] = "• {$stage}: {$count} deals" . ($value > 0 ? " (\${$value})" : '');
                }
            }

            $totalValue = (float) ($stats['total_pipeline_value'] ?? 0);
            $won = $stats['won'] ?? ['count' => 0, 'value' => 0];
            $lost = $stats['lost'] ?? ['count' => 0];

            $lines[] = '';
            $lines[] = "Total pipeline value: " . ($totalValue > 0 ? "\${$totalValue}" : '0');
            $lines[] = "Won: {$won['count']} deals" . (($won['value'] ?? 0) > 0 ? " (\${$won['value']})" : '');
            $lines[] = "Lost: {$lost['count']} deals";

            return "Pipeline status:\n\n" . implode("\n", $lines);
        } catch (\Throwable $e) {
            error_log('EmailAssistantHandler::executeGetPipeline error: ' . $e->getMessage());
            return "Failed to get pipeline status: " . $e->getMessage();
        }
    }

    /**
     * List tasks for user
     */
    private function executeListTasks(int $userId): string
    {
        try {
            $tasks = new Tasks();
            $items = $tasks->getAll(['assigned_to' => $userId], 15, 0);
            if (empty($items)) {
                return "You have no tasks.";
            }
            $lines = ["Your tasks:", ""];
            foreach ($items as $t) {
                $status = $t['status'] ?? 'pending';
                $due = !empty($t['due_date']) ? " (due: {$t['due_date']})" : '';
                $lines[] = "• {$t['title']} [{$status}]{$due}";
            }
            return implode("\n", $lines);
        } catch (\Throwable $e) {
            error_log('EmailAssistantHandler::executeListTasks error: ' . $e->getMessage());
            return "Failed to list tasks: " . $e->getMessage();
        }
    }

    /**
     * Create contact from parsed instruction
     */
    private function executeCreateContact(string $body, int $userId): string
    {
        try {
            $result = $this->aiService->process('email_assistant_parse_contact', ['text' => substr($body, 0, 500)]);
            $data = json_decode($result, true);
            if (!$data || empty($data['email'])) {
                $first = trim(explode("\n", $body)[0] ?? $body);
                return "Could not parse contact details. Please include at least: name and email. Example: Add contact John Doe, john@example.com";
            }

            $firstName = trim($data['first_name'] ?? '') ?: 'Unknown';
            $lastName = trim($data['last_name'] ?? '');
            $email = trim($data['email'] ?? '');
            $phone = trim($data['phone'] ?? '');
            $company = trim($data['company'] ?? '');

            if (empty($email)) {
                return "Email is required to create a contact.";
            }

            $contacts = new Contacts();
            $out = $contacts->create([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'phone' => $phone,
                'company' => $company,
                'assigned_to' => $userId,
                'created_by' => $userId,
            ]);

            if (is_array($out) && ($out['status'] ?? '') === 'duplicate') {
                return "Contact with email {$email} already exists.";
            }

            $id = is_array($out) ? ($out['id'] ?? null) : $out;
            return "Contact created: {$firstName} {$lastName} ({$email})" . ($id ? " (ID: {$id})" : '');
        } catch (\Throwable $e) {
            error_log('EmailAssistantHandler::executeCreateContact error: ' . $e->getMessage());
            return "Failed to create contact: " . $e->getMessage();
        }
    }

    /**
     * Update contact from parsed instruction
     */
    private function executeUpdateContact(string $body, int $userId): string
    {
        try {
            $result = $this->aiService->process('email_assistant_parse_contact_update', ['text' => substr($body, 0, 500)]);
            $data = json_decode($result, true);
            if (!$data || empty($data['contact_identifier'])) {
                return "Could not parse update instruction. Example: Update contact John: set company to Acme Inc";
            }

            $identifier = trim($data['contact_identifier'] ?? '');
            $updates = $data['updates'] ?? [];
            if (empty($updates) || !is_array($updates)) {
                return "Please specify which fields to update. Example: Update contact john@example.com: set company to Acme Inc";
            }

            $contact = $this->findContactByIdentifier($identifier);
            if (!$contact) {
                return "Contact not found: {$identifier}";
            }

            $allowedFields = ['first_name', 'last_name', 'email', 'phone', 'company', 'job_title', 'stage'];
            $toUpdate = [];
            foreach ($updates as $field => $value) {
                if (in_array($field, $allowedFields) && $value !== null && trim((string) $value) !== '') {
                    $toUpdate[$field] = Security::sanitizeInput(trim((string) $value), $field === 'email' ? 'email' : 'string');
                }
            }
            if (empty($toUpdate)) {
                return "No valid fields to update. Allowed: " . implode(', ', $allowedFields);
            }

            if (isset($toUpdate['email']) && !Security::validateEmail($toUpdate['email'])) {
                return "Invalid email address.";
            }

            $contacts = new Contacts();
            $contacts->update((int) $contact['id'], $toUpdate);

            $changed = implode(', ', array_keys($toUpdate));
            return "Contact {$contact['first_name']} {$contact['last_name']} updated. Changed: {$changed}.";
        } catch (\Throwable $e) {
            error_log('EmailAssistantHandler::executeUpdateContact error: ' . $e->getMessage());
            return "Failed to update contact: " . $e->getMessage();
        }
    }

    /**
     * Delete contact (hard delete) - requires explicit identifier
     */
    private function executeDeleteContact(string $body, int $userId): string
    {
        try {
            $identifier = $this->extractContactIdentifierFromBody($body);
            if (empty($identifier)) {
                return "Could not identify which contact to delete. Example: Delete contact john@example.com or Delete contact John Doe";
            }

            $contact = $this->findContactByIdentifier($identifier);
            if (!$contact) {
                return "Contact not found: {$identifier}. Deletion cancelled.";
            }

            $contacts = new Contacts();
            $contacts->delete((int) $contact['id']);

            $name = trim(($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? ''));
            $email = $contact['email'] ?? 'no email';
            return "Contact deleted: {$name} ({$email}). This action cannot be undone.";
        } catch (\Throwable $e) {
            error_log('EmailAssistantHandler::executeDeleteContact error: ' . $e->getMessage());
            return "Failed to delete contact: " . $e->getMessage();
        }
    }

    /**
     * Enrich contact using AI and third-party services
     */
    private function executeEnrichContact(string $body, int $userId): string
    {
        try {
            $identifier = $this->extractContactIdentifierFromBody($body);
            if (empty($identifier)) {
                return "Could not identify which contact to enrich. Example: Enrich contact john@example.com";
            }

            $contact = $this->findContactByIdentifier($identifier);
            if (!$contact) {
                return "Contact not found: {$identifier}";
            }

            $enrichmentService = new \CRM\Services\AIEnrichmentService();
            $result = $enrichmentService->enrichContact((int) $contact['id'], [
                'use_third_party' => true,
                'extract_web' => true,
                'extract_email' => true,
                'extract_social' => true,
                'discover_linkedin' => true,
                'infer_fields' => true,
                'validate_data' => true,
            ]);

            $status = $result['status'] ?? 'unknown';
            if ($status === 'success' || $status === 'partial') {
                $updated = $result['fields_updated'] ?? $result['updated_fields'] ?? [];
                $count = is_array($updated) ? count($updated) : 0;
                return "Contact {$contact['first_name']} {$contact['last_name']} enriched. Updated {$count} field(s).";
            }

            $msg = $result['message'] ?? $result['error'] ?? 'Enrichment failed';
            return "Enrichment could not complete: {$msg}. Ensure CLEARBIT_API_KEY or HUNTER_API_KEY is configured.";
        } catch (\Throwable $e) {
            error_log('EmailAssistantHandler::executeEnrichContact error: ' . $e->getMessage());
            return "Failed to enrich contact: " . $e->getMessage();
        }
    }

    /**
     * Verify contact email or raw email address
     */
    private function executeVerifyEmail(string $body, int $userId): string
    {
        try {
            $identifier = $this->extractContactIdentifierFromBody($body);
            $email = null;

            if (!empty($identifier)) {
                if (strpos($identifier, '@') !== false) {
                    $email = $identifier;
                } else {
                    $contact = $this->findContactByIdentifier($identifier);
                    if ($contact) {
                        $email = $contact['email'] ?? null;
                    }
                }
            }

            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return "Could not identify email to verify. Example: Verify email john@example.com or Verify email for contact John";
            }

            $verifyService = new \CRM\Services\ThirdPartyEnrichmentService();
            $result = $verifyService->verifyEmail($email);

            if (($result['status'] ?? '') !== 'success') {
                $msg = $result['message'] ?? 'Verification failed';
                return "Email verification failed: {$msg}. Ensure HUNTER_API_KEY is configured.";
            }

            $verified = $result['email_verified'] ?? false;
            $status = $result['email_verification_status'] ?? 'unknown';
            $score = $result['confidence_score'] ?? $result['smtp_score'] ?? null;

            $lines = ["Email: {$email}"];
            $lines[] = "Status: " . ($verified ? 'Deliverable' : ucfirst((string) $status));
            if ($score !== null) {
                $lines[] = "Confidence: " . (is_numeric($score) ? round((float) $score * 100) . '%' : $score);
            }

            $contacts = new Contacts();
            $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
            $c = Database::queryOne(
                "SELECT id
                 FROM contacts
                 WHERE workspace_id = ?
                   AND LOWER(email) = ?
                 LIMIT 1",
                [$workspaceId, strtolower($email)]
            );
            if ($c) {
                $contacts->update((int) $c['id'], ['email_verified' => $verified ? 1 : 0]);
                $lines[] = "Contact record updated with verification status.";
            }

            return implode("\n", $lines);
        } catch (\Throwable $e) {
            error_log('EmailAssistantHandler::executeVerifyEmail error: ' . $e->getMessage());
            return "Failed to verify email: " . $e->getMessage();
        }
    }

    /**
     * Extract contact identifier (name or email) from instruction body
     */
    private function extractContactIdentifierFromBody(string $body): ?string
    {
        $body = trim($body);
        if (empty($body)) {
            return null;
        }
        if (strpos($body, '@') !== false && preg_match('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', $body, $m)) {
            return $m[0];
        }
        try {
            $result = $this->aiService->process('email_assistant_parse_note', ['text' => substr($body, 0, 300)]);
            $data = json_decode($result, true);
            $id = $data['contact_identifier'] ?? null;
            return $id ? trim($id) : null;
        } catch (\Throwable $e) {
            $first = trim(explode("\n", $body)[0] ?? $body);
            $first = preg_replace('/^(delete|remove|enrich|verify)\s+(contact|email)\s*/i', '', $first);
            $first = preg_replace('/\s*:.*$/', '', $first);
            return trim($first) ?: null;
        }
    }

    /**
     * Add note to contact
     */
    private function executeAddNote(string $body, int $userId): string
    {
        try {
            $result = $this->aiService->process('email_assistant_parse_note', ['text' => substr($body, 0, 500)]);
            $data = json_decode($result, true);
            if (!$data || empty($data['content'])) {
                return "Could not parse note. Example: Add note to John: called him about the proposal";
            }

            $identifier = trim($data['contact_identifier'] ?? '');
            $content = trim($data['content'] ?? '');

            if (empty($identifier)) {
                return "Please specify which contact to add the note to (e.g. 'Add note to John: ...')";
            }

            $contact = $this->findContactByIdentifier($identifier);
            if (!$contact) {
                return "Contact not found: {$identifier}";
            }

            $notes = new Notes();
            $notes->create([
                'entity_type' => 'contact',
                'entity_id' => $contact['id'],
                'content' => $content,
                'created_by' => $userId,
            ]);

            return "Note added to {$contact['first_name']} {$contact['last_name']}.";
        } catch (\Throwable $e) {
            error_log('EmailAssistantHandler::executeAddNote error: ' . $e->getMessage());
            return "Failed to add note: " . $e->getMessage();
        }
    }

    /**
     * Schedule event from parsed instruction
     */
    private function executeScheduleEvent(string $body, int $userId): string
    {
        try {
            $result = $this->aiService->process('email_assistant_parse_event', ['text' => substr($body, 0, 500)]);
            $data = json_decode($result, true);
            if (!$data || empty($data['title']) || empty($data['start_time'])) {
                return "Could not parse event. Example: Schedule meeting tomorrow at 2pm - Team sync";
            }

            $title = trim($data['title']);
            $startTime = $data['start_time'];
            $endTime = !empty($data['end_time']) ? $data['end_time'] : null;
            $description = trim($data['description'] ?? '');

            $events = new Events();
            $eventId = $events->create([
                'title' => $title,
                'description' => $description,
                'start_time' => $startTime,
                'end_time' => $endTime,
                'assigned_to' => $userId,
                'created_by' => $userId,
            ]);

            return "Event scheduled: \"{$title}\" at {$startTime}" . ($eventId ? " (ID: {$eventId})" : '');
        } catch (\Throwable $e) {
            error_log('EmailAssistantHandler::executeScheduleEvent error: ' . $e->getMessage());
            return "Failed to schedule event: " . $e->getMessage();
        }
    }

    /**
     * Run report by name
     */
    private function executeRunReport(string $body, int $userId): string
    {
        try {
            $reports = new Reports();
            $all = $reports->getAll($userId);
            if (empty($all)) {
                return "No reports available.";
            }

            $bodyLower = strtolower($body);
            $report = null;
            foreach ($all as $r) {
                $nameLower = strtolower($r['name']);
                if (strpos($bodyLower, $nameLower) !== false) {
                    $report = $r;
                    break;
                }
            }
            if (!$report) {
                $names = array_map(fn($r) => $r['name'], array_slice($all, 0, 5));
                return "Report not found. Available: " . implode(', ', $names);
            }

            $data = $reports->execute((int) $report['id'], []);
            $count = is_array($data) ? count($data) : 0;
            return "Report \"{$report['name']}\" executed. Rows: {$count}.";
        } catch (\Throwable $e) {
            error_log('EmailAssistantHandler::executeRunReport error: ' . $e->getMessage());
            return "Failed to run report: " . $e->getMessage();
        }
    }

    private function executeCreateInvoice(string $body, int $userId): string
    {
        return $this->executeStructuredWithFallback($body, 'create_invoice', $userId, 'Failed to create invoice');
    }

    private function executeUpdateInvoice(string $body, int $userId): string
    {
        return $this->executeStructuredWithFallback($body, 'update_invoice', $userId, 'Failed to update invoice');
    }

    private function executeListInvoices(string $body, int $userId): string
    {
        try {
            $filters = [];
            if (preg_match('/\bquote(s)?\b/i', $body)) {
                $filters['document_type'] = 'quote';
            } elseif (preg_match('/\bproforma(s)?\b/i', $body)) {
                $filters['document_type'] = 'proforma';
            } elseif (preg_match('/\binvoice(s)?\b/i', $body)) {
                $filters['document_type'] = 'invoice';
            }
            $rows = (new Invoices())->list($filters, 10, 0);
            if (empty($rows)) {
                return 'No commercial documents found.';
            }
            $summaryLines = ['Recent documents:', ''];
            foreach ($rows as $row) {
                $summaryLines[] = "- {$row['invoice_number']} - {$row['document_type']} - {$row['status']} ({$row['currency']} "
                    . number_format((float) ($row['grand_total'] ?? 0), 2) . ')';
            }
            return implode("\n", $summaryLines);
            $lines = ['Recent documents:', ''];
            foreach ($rows as $row) {
                $lines[] = "• {$row['invoice_number']} - {$row['document_type']} - {$row['status']} ({$row['currency']} " . number_format((float) ($row['grand_total'] ?? 0), 2) . ')';
            }
            return implode("\n", $lines);
        } catch (\Throwable $e) {
            error_log('EmailAssistantHandler::executeListInvoices error: ' . $e->getMessage());
            return "Failed to list invoices: " . $e->getMessage();
        }
    }

    private function executeSendInvoice(string $body, int $userId): string
    {
        return $this->executeStructuredWithFallback($body, 'send_invoice', $userId, 'Failed to send invoice');
    }

    private function executeFinalizeInvoice(string $body, int $userId): string
    {
        return $this->executeStructuredWithFallback($body, 'finalize_invoice', $userId, 'Failed to finalize invoice');
    }

    private function executeMarkInvoicePaid(string $body, int $userId): string
    {
        return $this->executeStructuredWithFallback($body, 'mark_invoice_paid', $userId, 'Failed to mark invoice paid');
    }

    private function executeConvertInvoice(string $body, int $userId): string
    {
        return $this->executeStructuredWithFallback($body, 'convert_quote_to_invoice', $userId, 'Failed to convert quote');
    }

    private function executeApproveCommercialAction(string $body, int $userId): string
    {
        return $this->executeStructuredWithFallback($body, 'approve_commercial_action', $userId, 'Failed to approve commercial action');
    }

    private function executeRejectCommercialAction(string $body, int $userId): string
    {
        return $this->executeStructuredWithFallback($body, 'reject_commercial_action', $userId, 'Failed to reject commercial action');
    }

    private function executeSummarizeCommercialAutomationState(string $body, int $userId): string
    {
        $decision = $this->policyBridge->evaluateAssistantAdvice('admin_command', [
            'surface' => 'admin_command',
            'assistant_confidence' => 1.0,
            'assistant_requested_action' => 'summarize_commercial_automation_state',
        ], $userId);
        if (($decision['decision'] ?? '') === 'blocked') {
            return 'I need a clearer deal or invoice reference before summarizing commercial automation confidently.';
        }
        try {
            $dealId = $this->extractIntegerAfterKeyword($body, 'deal');
            $invoiceId = $this->extractIntegerAfterKeyword($body, 'invoice');
            $approvalService = new \CRM\Services\CommercialAutomationApprovalService();
            $orchestrator = new \CRM\Services\CommercialAutomationOrchestrator();
            $approvals = $approvalService->listPending($dealId > 0 ? $dealId : null, $invoiceId > 0 ? $invoiceId : null);
            $runs = $orchestrator->getRecentRuns($dealId > 0 ? $dealId : null, $invoiceId > 0 ? $invoiceId : null, 5);
            if (empty($approvals) && empty($runs)) {
                return 'No commercial automation activity found.';
            }
            $lines = [];
            if (!empty($approvals)) {
                $lines[] = 'Pending approvals:';
                foreach ($approvals as $approval) {
                    $lines[] = "- #{$approval['id']} {$approval['action_key']} ({$approval['reason']})";
                }
            }
            if (!empty($runs)) {
                if ($lines) {
                    $lines[] = '';
                }
                $lines[] = 'Recent runs:';
                foreach ($runs as $run) {
                    $lines[] = "- {$run['trigger_type']} => {$run['decision']} at {$run['created_at']}";
                }
            }
            $summary = implode("\n", $lines);
            if (in_array((string) ($decision['decision'] ?? ''), ['allow_with_warning', 'suggest_only'], true) && !empty($decision['reasons'])) {
                $summary = "Qualification note: " . implode(', ', (array) $decision['reasons']) . "\n\n" . $summary;
            }
            return $summary;
        } catch (\Throwable $e) {
            return "Failed to summarize commercial automation state: " . $e->getMessage();
        }
    }

    private function executeStructuredInstruction(string $body, string $intent, int $userId): string
    {
        $result = $this->applicationService->handleAdminCommand($body, $intent, $userId, [
            'assistant_type' => $this->assistantType,
        ]);
        return (string) ($result['summary_text'] ?? '');
    }

    private function assistantSourceSurface(): string
    {
        return $this->assistantType === AssistantActionRuntimeConfig::ASSISTANT_WHATSAPP
            ? 'whatsapp_assistant'
            : 'email_assistant';
    }

    private function formatStructuredResult(array $result, array $plan): string
    {
        return $this->applicationService->formatUserFacingSummary([
            'policy' => [
                'decision' => (string) ($result['policy_decision'] ?? $plan['policy_decision'] ?? 'blocked'),
                'reasons' => (array) ($result['policy_reasons'] ?? $plan['policy_reasons'] ?? []),
                'warnings' => (array) ($result['policy_warnings'] ?? $plan['policy_warnings'] ?? []),
            ],
            'draft' => (array) ($result['draft'] ?? []),
            'results' => (array) ($result['results'] ?? []),
        ]);
    }

    private function executeStructuredWithFallback(string $body, string $intent, int $userId, string $fallbackPrefix): string
    {
        try {
            $result = $this->executeStructuredInstruction($body, $intent, $userId);
            if (trim($result) !== '') {
                return $result;
            }
        } catch (\Throwable $e) {
            error_log('EmailAssistantHandler::executeStructuredWithFallback error: ' . $e->getMessage());
            return $fallbackPrefix . ': ' . $e->getMessage();
        }

        return 'No assistant action was executed.';
    }

    /**
     * Find contact by name or email
     */
    private function findContactByIdentifier(string $identifier): ?array
    {
        $identifier = trim($identifier);
        if (empty($identifier)) {
            return null;
        }
        $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        if (strpos($identifier, '@') !== false) {
            return Database::queryOne(
                "SELECT id, first_name, last_name, email
                 FROM contacts
                 WHERE workspace_id = ?
                   AND LOWER(email) = ?
                 LIMIT 1",
                [$workspaceId, strtolower($identifier)]
            ) ?: null;
        }
        $parts = preg_split('/\s+/', $identifier, 2);
        $firstName = $parts[0] ?? '';
        $lastName = $parts[1] ?? '';
        if ($lastName) {
            $c = Database::queryOne(
                "SELECT id, first_name, last_name, email
                 FROM contacts
                 WHERE workspace_id = ?
                   AND LOWER(first_name) = ?
                   AND LOWER(last_name) = ?
                 LIMIT 1",
                [$workspaceId, strtolower($firstName), strtolower($lastName)]
            );
            if ($c) {
                return $c;
            }
        }
        return Database::queryOne(
            "SELECT id, first_name, last_name, email
             FROM contacts
             WHERE workspace_id = ?
               AND (LOWER(first_name) = ? OR LOWER(last_name) = ?)
             LIMIT 1",
            [$workspaceId, strtolower($firstName), strtolower($firstName)]
        ) ?: null;
    }

    private function extractIntegerAfterKeyword(string $body, string $keyword): int
    {
        if (preg_match('/\b' . preg_quote($keyword, '/') . '\s*#?\s*(\d+)\b/i', $body, $m)) {
            return (int) $m[1];
        }

        return 0;
    }

    private function findInvoiceByBody(string $body): ?array
    {
        $body = trim($body);
        if ($body === '') {
            return null;
        }

        $invoices = new Invoices();
        $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);

        if (preg_match('/\b([A-Z]{2,}-?\d{2,}|\d{4,})\b/', strtoupper($body), $m)) {
            $row = Database::queryOne(
                "SELECT id
                 FROM invoices
                 WHERE workspace_id = ?
                   AND UPPER(invoice_number) = ?
                 ORDER BY id DESC
                 LIMIT 1",
                [$workspaceId, strtoupper($m[1])]
            );
            if ($row) {
                return $invoices->getById((int) $row['id']);
            }
        }

        $type = null;
        if (preg_match('/\bquote\b/i', $body)) {
            $type = 'quote';
        } elseif (preg_match('/\bproforma\b/i', $body)) {
            $type = 'proforma';
        } elseif (preg_match('/\binvoice\b/i', $body)) {
            $type = 'invoice';
        }

        $dealId = $this->extractIntegerAfterKeyword($body, 'deal');
        if ($dealId > 0) {
            $params = [$workspaceId, $dealId];
            $sql = "SELECT id FROM invoices WHERE workspace_id = ? AND deal_id = ?";
            if ($type) {
                $sql .= " AND document_type = ?";
                $params[] = $type;
            }
            $sql .= " ORDER BY id DESC LIMIT 1";
            $row = Database::queryOne($sql, $params);
            if ($row) {
                return $invoices->getById((int) $row['id']);
            }
        }

        $contactIdentifier = $this->extractContactIdentifierFromBody($body);
        if ($contactIdentifier) {
            $contact = $this->findContactByIdentifier($contactIdentifier);
            if ($contact) {
                $params = [$workspaceId, (int) $contact['id']];
                $sql = "SELECT id FROM invoices WHERE workspace_id = ? AND contact_id = ?";
                if ($type) {
                    $sql .= " AND document_type = ?";
                    $params[] = $type;
                }
                $sql .= " ORDER BY id DESC LIMIT 1";
                $row = Database::queryOne($sql, $params);
                if ($row) {
                    return $invoices->getById((int) $row['id']);
                }
            }
        }

        $params = [$workspaceId];
        $sql = "SELECT id FROM invoices WHERE workspace_id = ?";
        if ($type) {
            $sql .= " AND document_type = ?";
            $params[] = $type;
        }
        $sql .= " ORDER BY id DESC LIMIT 1";
        $row = Database::queryOne($sql, $params);

        return $row ? $invoices->getById((int) $row['id']) : null;
    }

    /**
     * Build CRM context for AI
     */
    private function buildContext(int $userId): array
    {
        $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        $contactCount = (int) Database::queryOne(
            "SELECT COUNT(*) as count FROM contacts WHERE workspace_id = ?",
            [$workspaceId]
        )['count'];
        $taskCount = (int) Database::queryOne(
            "SELECT COUNT(*) as count
             FROM tasks
             WHERE workspace_id = ?
               AND (assigned_to = ? OR created_by = ?)",
            [$workspaceId, $userId, $userId]
        )['count'];
        $dealCount = (int) Database::queryOne(
            "SELECT COUNT(*) as count FROM deals WHERE workspace_id = ?",
            [$workspaceId]
        )['count'];
        $pendingTasks = Database::query(
            "SELECT title, due_date, priority FROM tasks 
             WHERE workspace_id = ?
               AND (assigned_to = ? OR created_by = ?)
               AND status IN ('pending', 'in_progress')
             ORDER BY due_date ASC LIMIT 10",
            [$workspaceId, $userId, $userId]
        );

        return [
            'contact_count' => $contactCount,
            'task_count' => $taskCount,
            'deal_count' => $dealCount,
            'pending_tasks' => $pendingTasks,
        ];
    }

    public function handleCustomerThreadDraft(int $communicationId, int $userId, array $options = []): array
    {
        if (!$this->runtimeConfig->customerThreadEnabled()) {
            return [
                'success' => true,
                'mode' => 'customer_thread',
                'intent' => 'draft_customer_reply',
                'run_id' => null,
                'resolution_status' => 'blocked',
                'execution_status' => 'rejected',
                'policy' => ['decision' => 'blocked', 'reasons' => ['customer_thread_disabled'], 'warnings' => [], 'approval_required' => false, 'can_execute' => false],
                'draft' => ['plain_body' => 'Customer-thread assistant is disabled.'],
                'results' => [],
                'summary_text' => 'Customer-thread assistant is disabled.',
            ];
        }
        return $this->applicationService->handleCustomerThreadDraft($communicationId, $userId, $options);
    }

    public function handleCustomerThreadSend(int $communicationId, int $userId, array $options = []): array
    {
        if (!$this->runtimeConfig->customerThreadEnabled()) {
            return [
                'success' => true,
                'mode' => 'customer_thread',
                'intent' => 'send_customer_reply',
                'run_id' => null,
                'resolution_status' => 'blocked',
                'execution_status' => 'rejected',
                'policy' => ['decision' => 'blocked', 'reasons' => ['customer_thread_disabled'], 'warnings' => [], 'approval_required' => false, 'can_execute' => false],
                'draft' => [],
                'results' => [['action' => 'send_customer_reply', 'status' => 'blocked', 'reason' => 'Customer-thread assistant is disabled.']],
                'summary_text' => 'Customer-thread assistant is disabled.',
            ];
        }
        if (!$this->runtimeConfig->customerSendEnabled()) {
            return [
                'success' => true,
                'mode' => 'customer_thread',
                'intent' => 'send_customer_reply',
                'run_id' => null,
                'resolution_status' => 'blocked',
                'execution_status' => 'rejected',
                'policy' => ['decision' => 'blocked', 'reasons' => ['customer_send_disabled'], 'warnings' => [], 'approval_required' => false, 'can_execute' => false],
                'draft' => [],
                'results' => [['action' => 'send_customer_reply', 'status' => 'blocked', 'reason' => 'Assistant customer sending is disabled.']],
                'summary_text' => 'Assistant customer sending is disabled.',
            ];
        }
        return $this->applicationService->handleCustomerThreadSend($communicationId, $userId, $options);
    }

    /**
     * Send rejection reply to non-whitelisted/non-authorized sender
     */
    public function sendRejectionReply(array $emailData, string $reason = 'not_authorized'): void
    {
        $fromEmail = strtolower(trim($emailData['from_email'] ?? ''));
        $subject = $emailData['subject'] ?? '';
        $messageId = $emailData['message_id'] ?? null;
        if (empty($fromEmail)) {
            return;
        }
        $body = "You are not authorized to send instructions to the Personal Assistant. Only whitelisted addresses or admin users can use this feature.";
        $replySubject = (stripos($subject, 'Re:') === 0) ? $subject : 'Re: ' . $subject;
        $this->sendReply($fromEmail, $replySubject, $body, $messageId, $messageId);
    }

    /**
     * Send reply email with In-Reply-To and References for threading
     */
    public function sendReply(string $to, string $subject, string $body, ?string $inReplyTo, ?string $references): void
    {
        $from = $this->smtp->getFromEmail() ?? 'noreply@example.com';
        $fromName = $this->smtp->getFromName() ?? 'Personal Assistant';

        $renderer = new AssistantEmailRenderer();
        $rendered = $renderer->renderReply($body);

        $customHeaders = [];
        if ($inReplyTo) {
            $customHeaders['In-Reply-To'] = '<' . trim($inReplyTo, '<>') . '>';
        }
        if ($references) {
            $customHeaders['References'] = '<' . trim($references, '<>') . '>';
        }

        $this->smtp->sendWithHeaders($to, $from, $fromName, $subject, $rendered['plain'], [], $rendered['html'], $customHeaders);
    }
}
