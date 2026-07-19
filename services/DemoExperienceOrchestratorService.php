<?php

declare(strict_types=1);

namespace CRM\Services;

use CRM\Auth;
use CRM\Database;
use CRM\Modules\Notifications;
use CRM\Security;

class DemoExperienceOrchestratorService
{
    private const MIN_VISIBLE_SPACING_SECONDS = 18;
    private const MOMENT_TOTAL = 18;
    private const STORY_VERSION = 'riverside-cue-v1';

    /** @var array<int,array<string,mixed>> */
    private const TIMELINE = [
        [
            'key' => 'dashboard_scene_started',
            'type' => 'scene_marker',
            'offset_seconds' => 1,
            'trigger_mode' => 'cue',
            'trigger_page' => 'dashboard.php',
            'trigger_name' => 'page_enter',
            'min_delay_seconds' => 1,
            'fallback_after_seconds' => 3,
            'next_cue' => 'Open Inbox',
            'highlight_selector' => 'a[href$="inbox.php"], a[href*="inbox.php"]',
            'scene_key' => 'dashboard_scene_started',
            'scene_step' => 'clarity_concierge',
            'auto_action' => 'open_clarity',
            'animation_payload' => [
                'clarity_message' => 'Welcome to the Riverside demo workspace. Everything is already configured: channels, drafting, targets, contact intelligence, and plugin capabilities. Start with Inbox when you are ready to see the lead arrive.',
            ],
            'phase' => 'showcase_start',
            'moment_index' => 1,
            'progress_label' => 'Ready',
        ],
        [
            'key' => 'welcome_private_sandbox',
            'type' => 'notification',
            'offset_seconds' => 8,
            'trigger_mode' => 'cue',
            'trigger_page' => 'dashboard.php',
            'trigger_name' => 'page_enter',
            'min_delay_seconds' => 6,
            'fallback_after_seconds' => 8,
            'depends_on_event_key' => 'dashboard_scene_started',
            'next_cue' => 'Open Inbox',
            'highlight_selector' => 'a[href$="inbox.php"], a[href*="inbox.php"]',
            'title' => 'Demo sandbox ready',
            'message' => 'Your demo activity is private to this browser session.',
            'notification_type' => 'demo_experience',
            'entity_type' => 'demo_session',
            'link' => 'inbox.php',
            'severity' => 'info',
            'ai_insight' => 'The protected demo scope is active.',
            'ai_action' => 'Open Inbox.',
            'toast_label' => 'Private sandbox',
            'toast_action_label' => 'Open Inbox',
            'toast_context_url' => 'inbox.php',
            'phase' => 'showcase_start',
            'moment_index' => 2,
            'progress_label' => 'Sandbox live',
        ],
        [
            'key' => 'dashboard_charts_revealed',
            'type' => 'scene_marker',
            'offset_seconds' => 12,
            'trigger_mode' => 'cue',
            'trigger_page' => 'dashboard.php',
            'trigger_name' => 'dashboard_charts_revealed',
            'trigger_names' => ['dashboard_charts_revealed', 'dashboard_charts_visible'],
            'min_delay_seconds' => 0,
            'fallback_after_seconds' => 45,
            'depends_on_event_key' => 'dashboard_scene_started',
            'next_cue' => 'Open Inbox',
            'highlight_selector' => '#dashboard-charts, [data-dashboard-charts]',
            'scene_key' => 'dashboard_charts_revealed',
            'scene_step' => 'charts_fill',
            'animation_payload' => [
                'metric_context' => 'Riverside metrics reveal when the chart section enters view.',
                'charts' => ['contacts', 'email', 'stage', 'deal_value'],
            ],
            'phase' => 'dashboard_overview',
            'moment_index' => 3,
            'progress_label' => 'Graphs live',
        ],
        [
            'key' => 'inbox_message_sequence_started',
            'type' => 'scene_marker',
            'offset_seconds' => 2,
            'trigger_mode' => 'cue',
            'trigger_page' => 'inbox.php',
            'trigger_name' => 'inbox_visible',
            'trigger_names' => ['page_enter', 'inbox_visible'],
            'min_delay_seconds' => 1,
            'fallback_after_seconds' => 4,
            'next_cue' => 'Watch Riverside messages arrive',
            'highlight_selector' => '.inbox-list, [data-demo-riverside-thread="1"]',
            'scene_key' => 'inbox_message_sequence_started',
            'scene_step' => 'message_sequence',
            'animation_payload' => [
                'copy' => 'Three related Riverside signals arrive in order: WhatsApp lead, email context, procurement follow-up.',
            ],
            'phase' => 'inbox_scene',
            'moment_index' => 4,
            'progress_label' => 'Inbox listening',
        ],
        [
            'key' => 'whatsapp_lead_received',
            'type' => 'whatsapp_inbound',
            'offset_seconds' => 24,
            'trigger_mode' => 'cue',
            'trigger_page' => 'inbox.php',
            'trigger_name' => 'inbox_visible',
            'trigger_names' => ['page_enter', 'inbox_visible'],
            'min_delay_seconds' => 2,
            'fallback_after_seconds' => 24,
            'depends_on_event_key' => 'inbox_message_sequence_started',
            'next_cue' => 'Related email context arrives next',
            'highlight_selector' => '[data-demo-riverside-thread="1"]',
            'scene_key' => 'inbox_message_sequence_started',
            'scene_step' => 'whatsapp_lead',
            'phase' => 'lead_capture',
            'moment_index' => 5,
            'progress_label' => 'WhatsApp lead',
        ],
        [
            'key' => 'email_inquiry_received',
            'type' => 'email_inbound',
            'offset_seconds' => 72,
            'trigger_mode' => 'cue',
            'trigger_page' => 'inbox.php',
            'trigger_name' => 'inbox_visible',
            'trigger_names' => ['page_enter', 'inbox_visible'],
            'min_delay_seconds' => 5,
            'fallback_after_seconds' => 72,
            'depends_on_event_key' => 'whatsapp_lead_received',
            'next_cue' => 'Procurement follows up',
            'highlight_selector' => '[data-demo-riverside-thread="1"]',
            'scene_key' => 'inbox_message_sequence_started',
            'scene_step' => 'email_context',
            'phase' => 'email_context',
            'moment_index' => 6,
            'progress_label' => 'Email context',
        ],
        [
            'key' => 'procurement_followup_received',
            'type' => 'email_procurement_inbound',
            'offset_seconds' => 84,
            'trigger_mode' => 'cue',
            'trigger_page' => 'inbox.php',
            'trigger_name' => 'inbox_visible',
            'trigger_names' => ['page_enter', 'inbox_visible'],
            'min_delay_seconds' => 8,
            'fallback_after_seconds' => 84,
            'depends_on_event_key' => 'email_inquiry_received',
            'next_cue' => 'Open Riverside thread',
            'highlight_selector' => '[data-demo-riverside-thread="1"]',
            'scene_key' => 'inbox_message_sequence_started',
            'scene_step' => 'procurement_followup',
            'phase' => 'procurement_context',
            'moment_index' => 7,
            'progress_label' => 'Procurement follow-up',
        ],
        [
            'key' => 'riverside_thread_auto_opened',
            'type' => 'scene_marker',
            'offset_seconds' => 90,
            'trigger_mode' => 'cue',
            'trigger_page' => 'inbox.php',
            'trigger_name' => 'idle',
            'trigger_names' => ['idle'],
            'min_delay_seconds' => 0,
            'fallback_after_seconds' => 120,
            'depends_on_event_key' => 'procurement_followup_received',
            'next_cue' => 'Review triage and draft',
            'highlight_selector' => '[data-demo-riverside-thread="1"]',
            'scene_key' => 'riverside_thread_auto_opened',
            'scene_step' => 'open_thread',
            'auto_action' => 'open_riverside_thread',
            'phase' => 'thread_focus',
            'moment_index' => 8,
            'progress_label' => 'Thread focus',
        ],
        [
            'key' => 'assistant_draft_typing_started',
            'type' => 'assistant_draft',
            'offset_seconds' => 100,
            'trigger_mode' => 'cue',
            'trigger_page' => 'conversation.php',
            'trigger_name' => 'riverside_thread_opened',
            'trigger_names' => ['page_enter', 'riverside_thread_opened', 'draft_panel_visible'],
            'min_delay_seconds' => 1,
            'fallback_after_seconds' => 150,
            'depends_on_event_key' => 'procurement_followup_received',
            'next_cue' => 'Open Tasks',
            'highlight_selector' => '[data-protected-demo-draft], #conversation-reply-form, #suggest-reply-btn',
            'title' => 'Assistant draft ready',
            'message' => 'Clarity drafted a Riverside reply that acknowledges the finish, payment terms, review window, and Friday hold without losing the customer thread.',
            'notification_type' => 'demo_assistant_draft_ready',
            'entity_type' => 'assistant_draft',
            'link' => 'inbox.php',
            'severity' => 'info',
            'ai_insight' => 'Amina is high intent because her WhatsApp request, Rose\'s rollout email, and Njeri\'s procurement window now point to one time-sensitive proposal update.',
            'ai_action' => 'Review the suggested Riverside reply.',
            'publish_triage' => true,
            'scene_key' => 'assistant_draft_typing_started',
            'scene_step' => 'draft_typing',
            'auto_action' => 'type_draft',
            'phase' => 'triage_and_draft',
            'moment_index' => 9,
            'progress_label' => 'Draft ready',
            'draft_preview' => "Hi Amina, thank you for the Riverside update. I can revise the proposal today with the matte stone finish, split payment terms, WhatsApp install reminders, and the Friday installation hold included. I will send the revised proposal by 3:00 PM so Rose and procurement can review it before noon tomorrow. Once you confirm the finish schedule, I will keep the Friday slot reserved.",
        ],
        [
            'key' => 'tasks_sequence_started',
            'type' => 'tasks_sequence',
            'offset_seconds' => 115,
            'trigger_mode' => 'cue',
            'trigger_page' => 'tasks.php',
            'trigger_name' => 'tasks_page_visible',
            'trigger_names' => ['page_enter', 'tasks_page_visible', 'draft_panel_visible'],
            'min_delay_seconds' => 1,
            'fallback_after_seconds' => 180,
            'depends_on_event_key' => 'assistant_draft_typing_started',
            'next_cue' => 'Open Riverside task',
            'highlight_selector' => '[data-demo-riverside-task="1"]',
            'scene_key' => 'tasks_sequence_started',
            'scene_step' => 'tasks_arrive',
            'phase' => 'follow_through',
            'moment_index' => 10,
            'progress_label' => 'Tasks queued',
        ],
        [
            'key' => 'riverside_task_opened',
            'type' => 'scene_marker',
            'offset_seconds' => 122,
            'trigger_mode' => 'cue',
            'trigger_page' => 'tasks.php',
            'trigger_name' => 'idle',
            'trigger_names' => ['idle', 'tasks_page_visible'],
            'min_delay_seconds' => 3,
            'fallback_after_seconds' => 190,
            'depends_on_event_key' => 'tasks_sequence_started',
            'next_cue' => 'Open Targets',
            'highlight_selector' => '[data-demo-riverside-task="1"], a[href$="targets.php"], a[href*="targets.php"]',
            'scene_key' => 'riverside_task_opened',
            'scene_step' => 'open_task',
            'auto_action' => 'open_riverside_task',
            'phase' => 'follow_through',
            'moment_index' => 11,
            'progress_label' => 'Task focus',
        ],
        [
            'key' => 'target_progress_spotlight',
            'type' => 'target_spotlight',
            'offset_seconds' => 126,
            'trigger_mode' => 'cue',
            'trigger_page' => 'targets.php',
            'trigger_name' => 'page_enter',
            'trigger_names' => ['page_enter', 'targets_page_visible'],
            'min_delay_seconds' => 1,
            'fallback_after_seconds' => 210,
            'depends_on_event_key' => 'tasks_sequence_started',
            'next_cue' => 'Ask Clarity',
            'highlight_selector' => '[data-demo-riverside-target="1"], [data-clarity-chat-open], .clarity-chat-launcher',
            'scene_key' => 'target_moved',
            'scene_step' => 'target_progress',
            'phase' => 'operating_goal',
            'moment_index' => 12,
            'progress_label' => 'Target moved',
        ],
        [
            'key' => 'clarity_next_action',
            'type' => 'notification',
            'offset_seconds' => 156,
            'trigger_mode' => 'cue',
            'trigger_page' => 'startup_journey.php',
            'trigger_name' => 'page_enter',
            'trigger_names' => ['page_enter', 'target_moved'],
            'min_delay_seconds' => 1,
            'fallback_after_seconds' => 240,
            'depends_on_event_key' => 'target_progress_spotlight',
            'next_cue' => 'Open Plugins',
            'highlight_selector' => '[data-clarity-chat-open], .clarity-chat-launcher, a[href$="workspace_skills.php"], a[href*="workspace_skills.php"]',
            'title' => 'Clarity AI surfaced a next-best action',
            'message' => 'Handle Riverside first: the lead, procurement context, draft, task, and response-time target all point to one revenue-protecting move.',
            'notification_type' => 'ai_coach_nudge',
            'entity_type' => 'clarity',
            'link' => 'startup_journey.php',
            'severity' => 'info',
            'ai_insight' => 'The Riverside thread, draft, task, and response-time target all point to one revenue-protecting move.',
            'ai_action' => 'Ask Clarity why Riverside should be handled first.',
            'open_clarity' => true,
            'scene_key' => 'clarity_next_action',
            'scene_step' => 'clarity_prompt',
            'auto_action' => 'open_clarity',
            'phase' => 'clarity_action',
            'moment_index' => 13,
            'progress_label' => 'Clarity AI',
        ],
        [
            'key' => 'marketplace_plugin_sequence_started',
            'type' => 'plugin_scene',
            'offset_seconds' => 190,
            'trigger_mode' => 'cue',
            'trigger_page' => 'workspace_skills.php',
            'trigger_name' => 'plugins_page_visible',
            'trigger_names' => ['page_enter', 'plugins_page_visible'],
            'min_delay_seconds' => 1,
            'fallback_after_seconds' => 260,
            'next_cue' => 'Open Contacts',
            'highlight_selector' => '[data-marketplace-installed="1"], a[href$="contacts.php"], a[href*="contacts.php"]',
            'scene_key' => 'marketplace_plugin_sequence_started',
            'scene_step' => 'plugin_install_sequence',
            'phase' => 'configured_plugins',
            'moment_index' => 14,
            'progress_label' => 'Plugins ready',
        ],
        [
            'key' => 'contacts_sequence_started',
            'type' => 'contacts_sequence',
            'offset_seconds' => 200,
            'trigger_mode' => 'cue',
            'trigger_page' => 'contacts.php',
            'trigger_name' => 'contacts_page_visible',
            'trigger_names' => ['page_enter', 'contacts_page_visible'],
            'min_delay_seconds' => 1,
            'fallback_after_seconds' => 270,
            'next_cue' => 'Open Amina',
            'highlight_selector' => '[data-demo-riverside-contact="1"]',
            'scene_key' => 'contacts_sequence_started',
            'scene_step' => 'contacts_arrive',
            'phase' => 'contact_memory',
            'moment_index' => 15,
            'progress_label' => 'Contacts added',
        ],
        [
            'key' => 'amina_contact_opened',
            'type' => 'scene_marker',
            'offset_seconds' => 212,
            'trigger_mode' => 'cue',
            'trigger_page' => 'contacts.php',
            'trigger_name' => 'idle',
            'trigger_names' => ['idle', 'contacts_page_visible'],
            'min_delay_seconds' => 3,
            'fallback_after_seconds' => 285,
            'depends_on_event_key' => 'contacts_sequence_started',
            'next_cue' => 'Run meeting prep',
            'highlight_selector' => '[data-demo-riverside-contact="1"]',
            'scene_key' => 'amina_contact_opened',
            'scene_step' => 'open_contact',
            'auto_action' => 'open_amina_contact',
            'phase' => 'contact_memory',
            'moment_index' => 16,
            'progress_label' => 'Amina focus',
        ],
        [
            'key' => 'meeting_prep_revealed',
            'type' => 'contact_spotlight',
            'offset_seconds' => 225,
            'trigger_mode' => 'cue',
            'trigger_page' => 'contact_view.php',
            'trigger_name' => 'page_enter',
            'trigger_names' => ['page_enter', 'meeting_prep_visible'],
            'min_delay_seconds' => 1,
            'fallback_after_seconds' => 300,
            'depends_on_event_key' => 'contacts_sequence_started',
            'next_cue' => 'Review recap',
            'highlight_selector' => '#meeting-prep-card, #contact-timeline, #contact-scoring',
            'scene_key' => 'meeting_prep_revealed',
            'scene_step' => 'meeting_prep',
            'phase' => 'contact_memory',
            'moment_index' => 17,
            'progress_label' => 'Meeting prep',
        ],
        [
            'key' => 'quiet_recap',
            'type' => 'notification',
            'offset_seconds' => 270,
            'trigger_mode' => 'cue',
            'trigger_page' => 'notifications.php',
            'trigger_name' => 'page_enter',
            'trigger_names' => ['page_enter', 'notifications_page_visible'],
            'min_delay_seconds' => 1,
            'fallback_after_seconds' => 330,
            'next_cue' => 'Create your workspace',
            'highlight_selector' => '[data-notification-thread], .notification-thread-card',
            'title' => 'Riverside demo recap is ready',
            'message' => 'Your private feed now contains the Riverside conversation, draft, tasks, target movement, plugin scene, and contact intelligence.',
            'notification_type' => 'demo_experience_recap',
            'entity_type' => 'demo_session',
            'link' => 'notifications.php',
            'severity' => 'info',
            'ai_insight' => 'The page-native scenes completed without real outbound sends or cross-session data exposure.',
            'ai_action' => 'Review notifications.',
            'phase' => 'recap',
            'moment_index' => 18,
            'progress_label' => 'Recap',
        ],
    ];

    private DemoSessionScopeService $scope;
    private DemoRealtimeEventService $events;
    private DemoChannelSimulatorService $simulator;

    public function __construct(
        ?DemoSessionScopeService $scope = null,
        ?DemoRealtimeEventService $events = null,
        ?DemoChannelSimulatorService $simulator = null
    ) {
        $this->scope = $scope ?? new DemoSessionScopeService();
        $this->events = $events ?? new DemoRealtimeEventService();
        $this->simulator = $simulator ?? new DemoChannelSimulatorService($this->scope, $this->events);
    }

    /**
     * @return array<string,mixed>
     */
    public function tick(array $session, array $input = []): array
    {
        $workspaceId = (int) ($session['workspace_id'] ?? 0);
        $sessionId = (int) ($session['id'] ?? 0);
        if ($workspaceId <= 0 || $sessionId <= 0) {
            throw new \InvalidArgumentException('A valid demo session is required.');
        }

        if (!empty($session['presentation_pitch']) && (new DemoWorkspaceService())->profileKey($workspaceId) === 'metrodrive') {
            return $this->metroDrivePresentationTick($workspaceId, $sessionId);
        }

        if (!Database::tableExists('demo_experience_events')) {
            return [
                'success' => true,
                'enabled' => false,
                'paused' => false,
                'emitted_count' => 0,
                'events' => [],
                'director_state' => $this->emptyDirectorState(),
            ];
        }

        $this->ensureTimeline($workspaceId, $sessionId);
        $cueContext = $this->cueContext($input);
        $pausedReason = $this->pauseReason($input);
        if ($pausedReason !== '') {
            return [
                'success' => true,
                'enabled' => true,
                'paused' => true,
                'paused_reason' => $pausedReason,
                'emitted_count' => 0,
                'events' => [],
                'director_state' => $this->directorState($workspaceId, $sessionId),
            ];
        }

        $this->recordCueContext($workspaceId, $sessionId, $cueContext);

        $selection = $this->nextDirectorEvent($workspaceId, $sessionId, $cueContext);
        if (!$selection) {
            return [
                'success' => true,
                'enabled' => true,
                'paused' => false,
                'pending_count' => $this->pendingCount($workspaceId, $sessionId),
                'emitted_count' => 0,
                'events' => [],
                'director_state' => $this->directorState($workspaceId, $sessionId),
            ];
        }

        $event = (array) ($selection['event'] ?? []);
        $definition = (array) ($selection['definition'] ?? []);

        try {
            $result = $this->emitDefinition($session, $definition, $cueContext);
            $this->markEventSent((int) $event['id'], $workspaceId, $sessionId, $result);
            if ((string) ($definition['key'] ?? '') !== 'welcome_private_sandbox') {
                $this->skipPendingWelcomeIfSuperseded($workspaceId, $sessionId);
            }
            return [
                'success' => true,
                'enabled' => true,
                'paused' => false,
                'emitted_count' => 1,
                'director_state' => $this->directorState($workspaceId, $sessionId, $definition),
                'events' => [
                    [
                        'event_key' => (string) $definition['key'],
                        'event_type' => (string) $definition['type'],
                        'trigger_mode' => (string) ($definition['trigger_mode'] ?? 'time'),
                        'result' => $result,
                    ],
                ],
            ];
        } catch (\Throwable $e) {
            error_log('DemoExperienceOrchestratorService event failed: ' . $e->getMessage());
            $this->markEventSkipped((int) $event['id'], $workspaceId, $sessionId, $e->getMessage());
            return [
                'success' => true,
                'enabled' => true,
                'paused' => false,
                'emitted_count' => 0,
                'events' => [],
                'skipped' => (string) $definition['key'],
                'director_state' => $this->directorState($workspaceId, $sessionId),
            ];
        }
    }

    /**
     * MetroDrive presentation sessions are presenter-driven from the scenario
     * console. Keep the realtime client alive without scheduling Riverside cues.
     *
     * @return array<string,mixed>
     */
    private function metroDrivePresentationTick(int $workspaceId, int $sessionId): array
    {
        return [
            'success' => true,
            'enabled' => true,
            'paused' => false,
            'pending_count' => 0,
            'emitted_count' => 0,
            'events' => [],
            'director_state' => [
                'story_version' => 'metrodrive-presentation-v1',
                'moment_total' => 8,
                'sent_count' => 0,
                'pending_count' => 0,
                'next_cue' => 'Run a presenter scenario',
                'current_label' => 'MetroDrive ready',
                'progress_label' => 'Presentation',
                'workspace_id' => $workspaceId,
                'demo_session_id' => $sessionId,
            ],
        ];
    }

    private function ensureTimeline(int $workspaceId, int $sessionId): void
    {
        foreach (self::TIMELINE as $definition) {
            Database::execute(
                "INSERT INTO demo_experience_events
                    (workspace_id, demo_session_id, event_key, event_type, status, scheduled_at, payload_json,
                     trigger_mode, trigger_page, trigger_name, depends_on_event_key, eligible_at, deadline_at, director_state_json)
                 VALUES (?, ?, ?, ?, 'pending', DATE_ADD(NOW(), INTERVAL ? SECOND), ?,
                         ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), DATE_ADD(NOW(), INTERVAL ? SECOND), ?)
                 ON DUPLICATE KEY UPDATE
                    event_type = VALUES(event_type),
                    scheduled_at = IF(status = 'pending' AND (scheduled_at IS NULL OR scheduled_at > VALUES(scheduled_at)), VALUES(scheduled_at), scheduled_at),
                    trigger_mode = VALUES(trigger_mode),
                    trigger_page = VALUES(trigger_page),
                    trigger_name = VALUES(trigger_name),
                    depends_on_event_key = VALUES(depends_on_event_key),
                    deadline_at = IF(status = 'pending' AND (deadline_at IS NULL OR deadline_at > VALUES(deadline_at)), VALUES(deadline_at), deadline_at),
                    payload_json = VALUES(payload_json),
                    director_state_json = VALUES(director_state_json),
                    updated_at = NOW()",
                [
                    $workspaceId,
                    $sessionId,
                    (string) $definition['key'],
                    (string) $definition['type'],
                    (int) $definition['offset_seconds'],
                    json_encode($definition, JSON_UNESCAPED_SLASHES),
                    (string) ($definition['trigger_mode'] ?? 'time'),
                    (string) ($definition['trigger_page'] ?? ''),
                    (string) ($definition['trigger_name'] ?? ''),
                    isset($definition['depends_on_event_key']) && $definition['depends_on_event_key'] !== null
                        ? (string) $definition['depends_on_event_key']
                        : null,
                    max(0, (int) ($definition['min_delay_seconds'] ?? $definition['offset_seconds'] ?? 0)),
                    max(0, (int) ($definition['fallback_after_seconds'] ?? $definition['offset_seconds'] ?? 0)),
                    json_encode($this->directorPayloadForDefinition($definition), JSON_UNESCAPED_SLASHES),
                ]
            );
        }
    }

    private function secondsSinceLastSent(int $workspaceId, int $sessionId): ?int
    {
        $row = Database::queryOne(
            "SELECT TIMESTAMPDIFF(SECOND, MAX(sent_at), NOW()) AS age_seconds
             FROM demo_experience_events
             WHERE workspace_id = ?
               AND demo_session_id = ?
               AND status = 'sent'
               AND sent_at IS NOT NULL",
            [$workspaceId, $sessionId]
        );

        if ($row === null || $row['age_seconds'] === null) {
            return null;
        }

        return max(0, (int) $row['age_seconds']);
    }

    /**
     * @param array<string,mixed> $cueContext
     * @return array{event:array<string,mixed>,definition:array<string,mixed>}|null
     */
    private function nextDirectorEvent(int $workspaceId, int $sessionId, array $cueContext): ?array
    {
        $rows = Database::query(
            "SELECT *
             FROM demo_experience_events
             WHERE workspace_id = ?
               AND demo_session_id = ?
               AND status = 'pending'
             ORDER BY COALESCE(eligible_at, scheduled_at, deadline_at) ASC, id ASC",
            [$workspaceId, $sessionId]
        );

        $sent = $this->sentEventKeys($workspaceId, $sessionId);
        $fallbackSelection = null;
        foreach ($rows as $row) {
            $definition = $this->definitionForKey((string) ($row['event_key'] ?? ''));
            if ($definition === null) {
                $this->markEventSkipped((int) ($row['id'] ?? 0), $workspaceId, $sessionId, 'unknown_event_key');
                continue;
            }

            if (!$this->directorEventIsReady($row, $definition, $cueContext)) {
                continue;
            }

            $dependency = trim((string) ($definition['depends_on_event_key'] ?? ''));
            if ($dependency !== '' && !in_array($dependency, $sent, true)) {
                $dependencySelection = $this->firstPendingDependencySelection(
                    $workspaceId,
                    $sessionId,
                    $definition,
                    $sent
                );
                if ($dependencySelection !== null) {
                    return $dependencySelection;
                }
                continue;
            }

            if ($this->readyBecauseOfCue($row, $definition)) {
                return ['event' => $row, 'definition' => $definition];
            }

            $fallbackSelection ??= ['event' => $row, 'definition' => $definition];
        }

        return $fallbackSelection;
    }

    /**
     * @param array<string,mixed> $definition
     * @param array<int,string> $sent
     * @param array<int,string> $seen
     * @return array{event:array<string,mixed>,definition:array<string,mixed>}|null
     */
    private function firstPendingDependencySelection(
        int $workspaceId,
        int $sessionId,
        array $definition,
        array $sent,
        array $seen = []
    ): ?array {
        $dependency = trim((string) ($definition['depends_on_event_key'] ?? ''));
        if ($dependency === '' || in_array($dependency, $sent, true) || in_array($dependency, $seen, true)) {
            return null;
        }

        $dependencyDefinition = $this->definitionForKey($dependency);
        $dependencyRow = $this->pendingEventRow($workspaceId, $sessionId, $dependency);
        if (!$dependencyDefinition || !$dependencyRow) {
            return null;
        }

        $ancestor = $this->firstPendingDependencySelection(
            $workspaceId,
            $sessionId,
            $dependencyDefinition,
            $sent,
            array_merge($seen, [$dependency])
        );
        if ($ancestor !== null) {
            return $ancestor;
        }

        $dependencyDefinition['_suppress_toast'] = true;
        $dependencyDefinition['_backfill_for_event_key'] = (string) ($definition['key'] ?? '');
        return ['event' => $dependencyRow, 'definition' => $dependencyDefinition];
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $definition
     * @param array<string,mixed> $cueContext
     */
    private function directorEventIsReady(array $row, array $definition, array $cueContext): bool
    {
        $mode = (string) ($definition['trigger_mode'] ?? $row['trigger_mode'] ?? 'time');
        if ($this->readyBecauseOfCue($row, $definition)) {
            return true;
        }

        if ($mode === 'cue') {
            return false;
        }

        $deadline = strtotime((string) ($row['deadline_at'] ?? ''));
        $scheduled = strtotime((string) ($row['scheduled_at'] ?? ''));
        if (($deadline > 0 && $deadline <= time()) || ($scheduled > 0 && $scheduled <= time())) {
            return true;
        }

        return $mode === 'time' && $scheduled > 0 && $scheduled <= time();
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $definition
     */
    private function readyBecauseOfCue(array $row, array $definition): bool
    {
        if (empty($row['cue_seen_at'])) {
            return false;
        }

        $eligibleAt = strtotime((string) ($row['eligible_at'] ?? ''));
        return $eligibleAt <= 0 || $eligibleAt <= time();
    }

    private function pendingCount(int $workspaceId, int $sessionId): int
    {
        $row = Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM demo_experience_events
             WHERE workspace_id = ?
               AND demo_session_id = ?
               AND status = 'pending'",
            [$workspaceId, $sessionId]
        );

        return (int) ($row['c'] ?? 0);
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function cueContext(array $input): array
    {
        $currentPage = $this->normalizePage((string) ($input['current_page'] ?? ''));
        $cues = $this->normalizeStringList($input['cue_events'] ?? $input['cue_events_json'] ?? []);
        $visibleKeys = $this->normalizeStringList($input['visible_demo_keys'] ?? $input['visible_demo_keys_json'] ?? []);

        if ($currentPage !== '') {
            $cues[] = 'page_enter';
            $pageCue = match ($currentPage) {
                'inbox.php' => 'inbox_visible',
                'conversation.php' => 'riverside_thread_opened',
                'tasks.php' => 'tasks_page_visible',
                'targets.php' => 'targets_page_visible',
                'contacts.php' => 'contacts_page_visible',
                'workspace_skills.php' => 'plugins_page_visible',
                'notifications.php' => 'notifications_page_visible',
                default => '',
            };
            if ($pageCue !== '') {
                $cues[] = $pageCue;
            }
        }

        return [
            'current_page' => $currentPage,
            'cue_events' => array_values(array_unique(array_filter(array_map('strval', $cues)))),
            'visible_demo_keys' => $visibleKeys,
            'active_entity_type' => Security::sanitizeInput((string) ($input['active_entity_type'] ?? ''), 'string'),
            'active_entity_id' => max(0, (int) ($input['active_entity_id'] ?? 0)),
            'idle_ms' => max(0, (int) ($input['idle_ms'] ?? 0)),
            'interaction_state' => Security::sanitizeInput((string) ($input['interaction_state'] ?? ''), 'string'),
            'autoplay_allowed' => !empty($input['autoplay_allowed']) && (string) $input['autoplay_allowed'] !== '0',
        ];
    }

    /**
     * @param mixed $value
     * @return array<int,string>
     */
    private function normalizeStringList(mixed $value): array
    {
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed !== '' && ($trimmed[0] === '[' || $trimmed[0] === '{')) {
                $decoded = json_decode($trimmed, true);
                $value = is_array($decoded) ? $decoded : [];
            } elseif ($trimmed !== '') {
                $value = preg_split('/\s*,\s*/', $trimmed) ?: [];
            } else {
                $value = [];
            }
        }

        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $item = $item['cue'] ?? $item['name'] ?? $item['key'] ?? '';
            }
            $item = preg_replace('/[^a-zA-Z0-9_.:-]/', '', (string) $item) ?: '';
            if ($item !== '') {
                $items[] = mb_substr($item, 0, 120);
            }
        }

        return array_values(array_unique($items));
    }

    private function normalizePage(string $page): string
    {
        $path = parse_url($page, PHP_URL_PATH);
        $base = basename((string) ($path ?: $page));
        return preg_match('/^[a-zA-Z0-9_-]+\.php$/', $base) ? $base : '';
    }

    /**
     * @param array<string,mixed> $cueContext
     */
    private function recordCueContext(int $workspaceId, int $sessionId, array $cueContext): void
    {
        $currentPage = (string) ($cueContext['current_page'] ?? '');
        $cues = (array) ($cueContext['cue_events'] ?? []);
        if ($currentPage === '' || $cues === []) {
            return;
        }

        foreach (self::TIMELINE as $definition) {
            $key = (string) ($definition['key'] ?? '');
            if ($key === '' || !$this->definitionMatchesCue($definition, $currentPage, $cues)) {
                continue;
            }

            $delay = max(0, (int) ($definition['min_delay_seconds'] ?? $definition['offset_seconds'] ?? 0));
            Database::execute(
                "UPDATE demo_experience_events
                 SET cue_seen_at = COALESCE(cue_seen_at, NOW()),
                     cue_count = COALESCE(cue_count, 0) + 1,
                     eligible_at = CASE
                         WHEN cue_seen_at IS NULL THEN DATE_ADD(NOW(), INTERVAL ? SECOND)
                         WHEN eligible_at IS NULL THEN DATE_ADD(cue_seen_at, INTERVAL ? SECOND)
                         ELSE eligible_at
                     END,
                     director_state_json = ?,
                     updated_at = NOW()
                 WHERE workspace_id = ?
                   AND demo_session_id = ?
                   AND event_key = ?
                   AND status = 'pending'",
                [
                    $delay,
                    $delay,
                    json_encode($this->directorPayloadForDefinition($definition, $cueContext), JSON_UNESCAPED_SLASHES),
                    $workspaceId,
                    $sessionId,
                    $key,
                ]
            );
        }
    }

    /**
     * @param array<string,mixed> $definition
     * @param array<int,string> $cues
     */
    private function definitionMatchesCue(array $definition, string $currentPage, array $cues): bool
    {
        $pages = array_map([$this, 'normalizePage'], (array) ($definition['trigger_pages'] ?? []));
        $primaryPage = $this->normalizePage((string) ($definition['trigger_page'] ?? ''));
        if ($primaryPage !== '') {
            $pages[] = $primaryPage;
        }
        $pages = array_values(array_unique(array_filter($pages)));
        if ($pages !== [] && !in_array($currentPage, $pages, true)) {
            return false;
        }

        $names = (array) ($definition['trigger_names'] ?? []);
        $primaryName = trim((string) ($definition['trigger_name'] ?? ''));
        if ($primaryName !== '') {
            $names[] = $primaryName;
        }
        $names = array_values(array_unique(array_filter(array_map('strval', $names))));
        if ($names === []) {
            return true;
        }

        return count(array_intersect($names, $cues)) > 0;
    }

    /**
     * @return array<int,string>
     */
    private function sentEventKeys(int $workspaceId, int $sessionId): array
    {
        $rows = Database::query(
            "SELECT event_key
             FROM demo_experience_events
             WHERE workspace_id = ?
               AND demo_session_id = ?
               AND status = 'sent'",
            [$workspaceId, $sessionId]
        );

        return array_values(array_filter(array_map(static fn(array $row): string => (string) ($row['event_key'] ?? ''), $rows)));
    }

    /**
     * @return array<string,mixed>|null
     */
    private function pendingEventRow(int $workspaceId, int $sessionId, string $eventKey): ?array
    {
        $row = Database::queryOne(
            "SELECT *
             FROM demo_experience_events
             WHERE workspace_id = ?
               AND demo_session_id = ?
               AND event_key = ?
               AND status = 'pending'
             LIMIT 1",
            [$workspaceId, $sessionId, $eventKey]
        );

        return $row ?: null;
    }

    private function skipPendingWelcomeIfSuperseded(int $workspaceId, int $sessionId): void
    {
        Database::execute(
            "UPDATE demo_experience_events
             SET status = 'skipped',
                 payload_json = ?,
                 updated_at = NOW()
             WHERE workspace_id = ?
               AND demo_session_id = ?
               AND event_key = 'welcome_private_sandbox'
               AND status = 'pending'",
            [
                json_encode(['skipped_reason' => 'superseded_by_cue'], JSON_UNESCAPED_SLASHES),
                $workspaceId,
                $sessionId,
            ]
        );
    }

    /**
     * @param array<string,mixed>|null $justEmitted
     * @return array<string,mixed>
     */
    private function directorState(int $workspaceId, int $sessionId, ?array $justEmitted = null): array
    {
        $rows = Database::query(
            "SELECT event_key, status, sent_at, cue_seen_at, auto_action_state, director_state_json
             FROM demo_experience_events
             WHERE workspace_id = ?
               AND demo_session_id = ?
             ORDER BY id ASC",
            [$workspaceId, $sessionId]
        );

        $completed = [];
        $next = null;
        foreach ($rows as $row) {
            $key = (string) ($row['event_key'] ?? '');
            if ($key === '') {
                continue;
            }
            if ((string) ($row['status'] ?? '') === 'sent') {
                $completed[] = $key;
                continue;
            }
            if ($next === null && (string) ($row['status'] ?? '') === 'pending') {
                $definition = $this->definitionForKey($key);
                if ($definition) {
                    $next = $this->directorPayloadForDefinition($definition);
                }
            }
        }

        $state = $this->emptyDirectorState();
        $state['story_version'] = self::STORY_VERSION;
        $state['completed_keys'] = $completed;
        $state['next_cue'] = (string) ($next['next_cue'] ?? '');
        $state['highlight_selector'] = (string) ($next['highlight_selector'] ?? '');
        $state['progress_label'] = (string) ($next['progress_label'] ?? '');
        if ($justEmitted !== null) {
            $state['last_event_key'] = (string) ($justEmitted['key'] ?? '');
            $state['last_progress_label'] = (string) ($justEmitted['progress_label'] ?? '');
        }

        return $state;
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyDirectorState(): array
    {
        return [
            'story_version' => self::STORY_VERSION,
            'completed_keys' => [],
            'next_cue' => '',
            'highlight_selector' => '',
            'progress_label' => '',
            'last_event_key' => '',
            'last_progress_label' => '',
        ];
    }

    /**
     * @param array<string,mixed> $definition
     * @param array<string,mixed> $cueContext
     * @return array<string,mixed>
     */
    private function directorPayloadForDefinition(array $definition, array $cueContext = []): array
    {
        return [
            'cue_key' => (string) ($definition['trigger_name'] ?? ''),
            'moment_state' => (string) ($definition['phase'] ?? ''),
            'next_cue' => (string) ($definition['next_cue'] ?? ''),
            'highlight_selector' => (string) ($definition['highlight_selector'] ?? ''),
            'progress_label' => (string) ($definition['progress_label'] ?? ''),
            'scene_key' => (string) ($definition['scene_key'] ?? $definition['key'] ?? ''),
            'scene_step' => (string) ($definition['scene_step'] ?? ''),
            'auto_action' => (string) ($definition['auto_action'] ?? ''),
            'auto_action_url' => (string) ($definition['auto_action_url'] ?? ''),
            'animation_payload' => (array) ($definition['animation_payload'] ?? []),
            'story_version' => self::STORY_VERSION,
            'current_page' => (string) ($cueContext['current_page'] ?? ''),
            'idle_ms' => (int) ($cueContext['idle_ms'] ?? 0),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function definitionForKey(string $eventKey): ?array
    {
        foreach (self::TIMELINE as $definition) {
            if ((string) $definition['key'] === $eventKey) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    private function emitDefinition(array $session, array $definition, array $cueContext = []): array
    {
        $type = (string) ($definition['type'] ?? '');
        return match ($type) {
            'scene_marker' => $this->emitSceneMarker($session, $definition, $cueContext),
            'whatsapp_inbound' => $this->emitWhatsAppLead($session, $definition, $cueContext),
            'email_inbound' => $this->emitEmailInquiry($session, $definition, $cueContext),
            'email_procurement_inbound' => $this->emitProcurementFollowup($session, $definition, $cueContext),
            'assistant_draft' => $this->emitNotification($session, $definition),
            'tasks_sequence' => $this->emitTasksSequence($session, $definition, $cueContext),
            'task_spotlight' => $this->emitTaskSpotlight($session, $definition, $cueContext),
            'target_spotlight' => $this->emitTargetSpotlight($session, $definition, $cueContext),
            'contacts_sequence' => $this->emitContactsSequence($session, $definition, $cueContext),
            'contact_spotlight' => $this->emitContactSpotlight($session, $definition, $cueContext),
            'plugin_scene' => $this->emitPluginScene($session, $definition, $cueContext),
            default => $this->emitNotification($session, $definition),
        };
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function scenePayload(array $definition, array $cueContext = [], array $extra = []): array
    {
        $payload = $this->directorPayloadForDefinition($definition, $cueContext);
        $payload['toast'] = false;
        $payload['sound'] = false;
        $payload['demo_event_key'] = (string) ($definition['key'] ?? '');
        $payload['phase'] = (string) ($definition['phase'] ?? '');
        $payload['moment_index'] = (int) ($definition['moment_index'] ?? 0);
        $payload['moment_total'] = self::MOMENT_TOTAL;
        $payload['persisted_entity_refs'] = (array) ($extra['persisted_entity_refs'] ?? []);

        foreach ($extra as $key => $value) {
            if ($key === 'persisted_entity_refs') {
                continue;
            }
            $payload[$key] = $value;
        }

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function emitSceneMarker(array $session, array $definition, array $cueContext = []): array
    {
        $workspaceId = (int) ($session['workspace_id'] ?? 0);
        $sessionId = (int) ($session['id'] ?? 0);
        $extra = [];
        $autoAction = (string) ($definition['auto_action'] ?? '');
        if ($autoAction === 'open_riverside_thread') {
            $communication = $this->latestRiversideCommunication($workspaceId, $sessionId);
            $url = $this->conversationUrlForCommunication($communication);
            if ($url !== '') {
                $extra['auto_action_url'] = $url;
                $extra['persisted_entity_refs'] = [
                    'communication_id' => (int) ($communication['id'] ?? 0),
                    'contact_id' => (int) ($communication['contact_id'] ?? 0),
                ];
            }
        } elseif ($autoAction === 'open_amina_contact') {
            $contactId = $this->riversideSessionContactId($workspaceId, $sessionId);
            if ($contactId <= 0) {
                $contactId = $this->createSpotlightContact($session);
            }
            $extra['auto_action_url'] = 'contact_view.php?id=' . $contactId . '&demo_scene=meeting_prep';
            $extra['persisted_entity_refs'] = ['contact_id' => $contactId];
        } elseif ($autoAction === 'open_riverside_task') {
            $taskId = $this->riversideSessionTaskId($workspaceId, $sessionId);
            if ($taskId > 0) {
                $extra['auto_action_url'] = 'task_view.php?id=' . $taskId . '&demo_scene=ready_to_send';
                $extra['persisted_entity_refs'] = ['task_id' => $taskId];
            }
        }

        $payload = $this->scenePayload($definition, $cueContext, $extra);
        $eventId = $this->events->publish(
            $workspaceId,
            $sessionId,
            'demo_scene',
            'demo_experience_event',
            0,
            $payload
        );

        return [
            'event_id' => $eventId,
            'scene_key' => (string) ($payload['scene_key'] ?? ''),
            'auto_action' => (string) ($payload['auto_action'] ?? ''),
            'auto_action_url' => (string) ($payload['auto_action_url'] ?? ''),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function emitWhatsAppLead(array $session, array $definition = [], array $cueContext = []): array
    {
        $sessionId = (int) ($session['id'] ?? 0);
        $payload = $this->scenePayload($definition, $cueContext);
        $result = $this->simulator->simulate(array_merge([
            'channel' => 'whatsapp',
            'direction' => 'inbound',
            'name' => 'Amina Otieno',
            'company' => 'Riverside Residence',
            'email' => 'amina.otieno@riverside-residence.example',
            'phone' => '+25470010' . str_pad((string) ($sessionId % 10000), 4, '0', STR_PAD_LEFT),
            'subject' => 'Riverside proposal revision',
            'message' => 'Hi, it is Amina from Riverside Residence. Can you revise the proposal with the matte stone finish, split payment terms, and WhatsApp reminders for the install team? If the numbers work, we would like to keep the Friday installation slot on hold.',
            'notification_title' => 'Riverside WhatsApp lead received',
            'notification_message' => 'Amina asked for revised proposal terms, finish selection, install reminders, and a Friday installation hold.',
            'ai_insight' => 'This is a high-intent Riverside lead because finish choice, payment terms, reminders, and install timing appeared in one message.',
            'ai_action' => 'Open the Riverside thread.',
            'demo_event_key' => 'whatsapp_lead_received',
            'toast_label' => 'WhatsApp',
            'toast_action_label' => 'Open inbox',
            'toast_context_url' => 'inbox.php',
            'toast' => false,
        ], $payload, ['toast' => false]));

        return ['simulated_message' => $result, 'channel' => 'whatsapp'];
    }

    /**
     * @return array<string,mixed>
     */
    private function emitEmailInquiry(array $session, array $definition = [], array $cueContext = []): array
    {
        $sessionId = (int) ($session['id'] ?? 0);
        $payload = $this->scenePayload($definition, $cueContext);
        $result = $this->simulator->simulate(array_merge([
            'channel' => 'email',
            'direction' => 'inbound',
            'name' => 'Rose Kamau',
            'company' => 'Riverside Residence',
            'email' => 'rose.kamau@riverside-residence.example',
            'phone' => '+25471120' . str_pad((string) ($sessionId % 10000), 4, '0', STR_PAD_LEFT),
            'subject' => 'Riverside rollout details for revised proposal',
            'message' => "Hi team,\n\nAmina asked me to send the rollout details for the Riverside revision before the review call. Please include the matte stone finish, split payment terms, Friday installation hold, and WhatsApp reminders for the install team.\n\nIf you can send the revised proposal by 3:00 PM today, procurement can review before noon tomorrow.\n\nRose",
            'notification_title' => 'Riverside email context received',
            'notification_message' => 'Rose added rollout details and the procurement review window for Amina\'s Riverside request.',
            'ai_insight' => 'Rose confirms the same project, deadline, and buying process as Amina\'s WhatsApp thread.',
            'ai_action' => 'Open the Riverside email.',
            'demo_event_key' => 'email_inquiry_received',
            'toast_label' => 'Email',
            'toast_action_label' => 'Open inbox',
            'toast_context_url' => 'inbox.php',
            'toast' => false,
        ], $payload, ['toast' => false]));

        return ['simulated_message' => $result, 'channel' => 'email'];
    }

    /**
     * @return array<string,mixed>
     */
    private function emitProcurementFollowup(array $session, array $definition = [], array $cueContext = []): array
    {
        $sessionId = (int) ($session['id'] ?? 0);
        $payload = $this->scenePayload($definition, $cueContext, [
            'animation_payload' => [
                'arrival_role' => 'procurement',
                'sequence_label' => 'Procurement follow-up',
            ],
        ]);
        $result = $this->simulator->simulate(array_merge([
            'channel' => 'email',
            'direction' => 'inbound',
            'name' => 'Njeri Wambui',
            'company' => 'Riverside Residence',
            'email' => 'njeri.wambui@riverside-residence.example',
            'phone' => '+25472230' . str_pad((string) ($sessionId % 10000), 4, '0', STR_PAD_LEFT),
            'subject' => 'Procurement check: Riverside Friday installation hold',
            'message' => "Hello,\n\nAmina and Rose looped procurement in for the Riverside revision. Please make sure the revised proposal includes the matte stone finish, split payment schedule, install reminders, and the Friday installation hold.\n\nIf the proposal lands before 3:00 PM, we can clear the review queue before noon tomorrow.\n\nNjeri",
            'notification_title' => 'Riverside procurement follow-up received',
            'notification_message' => 'Procurement confirmed the 3:00 PM proposal window and Friday installation hold.',
            'ai_insight' => 'Procurement has confirmed a buying process and deadline, so the Riverside reply should be handled before lower-intent threads.',
            'ai_action' => 'Open the Riverside thread.',
            'demo_event_key' => 'procurement_followup_received',
            'toast_label' => 'Email',
            'toast_action_label' => 'Open thread',
            'toast_context_url' => 'inbox.php',
            'toast' => false,
        ], $payload, ['toast' => false]));

        return ['simulated_message' => $result, 'channel' => 'email'];
    }

    /**
     * @return array<string,mixed>
     */
    private function emitTasksSequence(array $session, array $definition = [], array $cueContext = []): array
    {
        $workspaceId = (int) ($session['workspace_id'] ?? 0);
        $sessionId = (int) ($session['id'] ?? 0);
        $userId = $this->sessionUserId($session);
        $contactId = $this->riversideSessionContactId($workspaceId, $sessionId);
        if ($contactId <= 0) {
            $contactId = $this->createSpotlightContact($session);
        }

        $tasks = [
            [
                'title' => 'Check Rose and Njeri context before proposal',
                'description' => 'Confirm the rollout details and procurement review window match Amina\'s WhatsApp request before the revised proposal goes out.',
                'priority' => 'medium',
                'due' => '+2 hours',
                'demo_key' => 'riverside_rollout_review',
            ],
            [
                'title' => 'Send revised Riverside proposal before 3:00 PM',
                'description' => 'Use the assistant draft to confirm matte stone finish pricing, split payment terms, install reminders, and the Friday installation hold.',
                'priority' => 'high',
                'due' => '+4 hours',
                'demo_key' => 'riverside_revised_proposal',
            ],
            [
                'title' => 'Confirm Friday install hold and reminders',
                'description' => 'After Amina reviews the proposal, confirm whether the Friday slot and WhatsApp install reminders should stay reserved.',
                'priority' => 'high',
                'due' => '+6 hours',
                'demo_key' => 'riverside_friday_hold',
            ],
        ];

        $taskIds = [];
        foreach ($tasks as $task) {
            $taskIds[] = $this->createDemoTask($session, $contactId, $task);
        }

        $riversideTaskId = $this->riversideSessionTaskId($workspaceId, $sessionId);
        $payload = $this->scenePayload($definition, $cueContext, [
            'animation_payload' => [
                'tasks' => array_map(static fn(array $task, int $id): array => [
                    'id' => $id,
                    'title' => $task['title'],
                    'priority' => $task['priority'],
                ], $tasks, $taskIds),
            ],
            'persisted_entity_refs' => [
                'task_ids' => $taskIds,
                'riverside_task_id' => $riversideTaskId,
                'contact_id' => $contactId,
            ],
        ]);
        $this->events->publish($workspaceId, $sessionId, 'demo_scene', 'task', $riversideTaskId, $payload);

        $notification = $this->createNotification($session, [
            'type' => 'demo_task_spotlight',
            'title' => 'Riverside follow-up tasks created',
            'message' => 'Clarity turned the Riverside thread into context review, proposal, and Friday-hold tasks.',
            'entity_type' => 'task',
            'entity_id' => $riversideTaskId,
            'link' => 'tasks.php',
            'severity' => 'info',
            'ai_insight' => 'Tasks convert the Riverside inbox thread into owned follow-through with the customer, rollout, and procurement context intact.',
            'ai_action' => 'Open the Riverside task.',
            'demo_event_key' => (string) ($definition['key'] ?? 'tasks_sequence_started'),
            'toast' => false,
        ] + $payload);

        return [
            'task_ids' => $taskIds,
            'riverside_task_id' => $riversideTaskId,
            'notification_id' => $notification['notification_id'] ?? 0,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function emitTaskSpotlight(array $session, array $definition = [], array $cueContext = []): array
    {
        $workspaceId = (int) ($session['workspace_id'] ?? 0);
        $sessionId = (int) ($session['id'] ?? 0);
        $userId = $this->sessionUserId($session);
        $contactId = $this->riversideSessionContactId($workspaceId, $sessionId);
        if ($contactId <= 0) {
            $contactId = $this->latestSessionContactId($workspaceId, $sessionId);
        }
        if ($contactId <= 0) {
            $contactId = $this->createSpotlightContact($session);
        }

        $columns = [
            'workspace_id',
            'demo_visibility',
            'demo_session_id',
            'title',
            'description',
            'assigned_to',
            'created_by',
            'contact_id',
            'status',
            'priority',
            'due_date',
        ];
        $values = [
            $workspaceId,
            'session_private',
            $sessionId,
            'Send revised Riverside proposal before 3:00 PM',
            'Use the assistant draft to confirm matte stone finish pricing, split payment terms, install reminders, and the Friday installation hold.',
            $userId,
            $userId,
            $contactId > 0 ? $contactId : null,
            'pending',
            'high',
            date('Y-m-d H:i:s', strtotime('+4 hours')),
        ];

        if (Database::columnExists('tasks', 'metadata_json')) {
            $columns[] = 'metadata_json';
            $values[] = json_encode([
                'source' => 'protected_demo_experience',
                'demo_event_key' => 'follow_up_task_spotlight',
                'simulated' => true,
                'demo_assignee_label' => 'You (demo owner)',
                'demo_created_by_label' => 'Clarity demo automation',
                'demo_contact_label' => 'Amina Otieno / Riverside Residence',
            ], JSON_UNESCAPED_SLASHES);
        }
        foreach ([
            'source_surface' => 'protected_demo',
            'source_capability_key' => 'demo_timed_task',
            'source_plugin_key' => 'clarity_demo',
        ] as $column => $value) {
            if (Database::columnExists('tasks', $column)) {
                $columns[] = $column;
                $values[] = $value;
            }
        }

        Database::execute(
            "INSERT INTO tasks (" . implode(', ', $columns) . ")
             VALUES (" . implode(', ', array_fill(0, count($columns), '?')) . ")",
            $values
        );
        $taskId = (int) Database::lastInsertId();
        $this->scope->registerEntity($sessionId, $workspaceId, 'tasks', $taskId, 'session_private', [
            'source' => 'protected_demo_experience',
        ]);

        $notification = $this->createNotification($session, [
            'type' => 'demo_task_spotlight',
            'title' => 'Riverside follow-up task created',
            'message' => 'A high-priority task now turns the Riverside thread into a concrete 3:00 PM proposal follow-up.',
            'entity_type' => 'task',
            'entity_id' => $taskId,
            'link' => 'tasks.php',
            'severity' => 'info',
            'ai_insight' => 'Tasks convert the Riverside inbox context into an owned action with timing, proposal detail, and customer follow-through.',
            'ai_action' => 'Open tasks',
            'sound' => false,
            'demo_event_key' => 'follow_up_task_spotlight',
            'toast_label' => 'Task',
            'toast_action_label' => 'Open tasks',
            'toast_context_url' => 'tasks.php',
            'phase' => 'follow_through',
            'moment_index' => 5,
            'moment_total' => self::MOMENT_TOTAL,
            'progress_label' => 'Task created',
            'toast' => false,
            'cue_key' => (string) ($definition['trigger_name'] ?? ''),
            'moment_state' => (string) ($definition['phase'] ?? 'follow_through'),
            'next_cue' => (string) ($definition['next_cue'] ?? 'Open Targets'),
            'highlight_selector' => (string) ($definition['highlight_selector'] ?? '[data-demo-riverside-task="1"]'),
            'scene_key' => (string) ($definition['scene_key'] ?? 'tasks_sequence_started'),
            'scene_step' => (string) ($definition['scene_step'] ?? 'task_created'),
            'story_version' => self::STORY_VERSION,
        ]);

        return ['task_id' => $taskId, 'notification_id' => $notification['notification_id'] ?? 0];
    }

    /**
     * @return array<string,mixed>
     */
    private function emitTargetSpotlight(array $session, array $definition = [], array $cueContext = []): array
    {
        $workspaceId = (int) ($session['workspace_id'] ?? 0);
        $sessionId = (int) ($session['id'] ?? 0);
        $userId = $this->sessionUserId($session);

        $columns = ['workspace_id'];
        $values = [$workspaceId];
        if (Database::columnExists('targets', 'demo_visibility')) {
            $columns[] = 'demo_visibility';
            $values[] = 'session_private';
        }
        if (Database::columnExists('targets', 'demo_session_id')) {
            $columns[] = 'demo_session_id';
            $values[] = $sessionId;
        }
        $columns = array_merge($columns, [
            'user_id',
            'title',
            'description',
            'target_type',
            'target_value',
            'current_value',
            'unit',
            'start_date',
            'target_date',
            'reminder_frequency',
            'status',
        ]);
        $values = array_merge($values, [
            $userId,
            'Keep qualified lead response under 15 minutes',
            'Riverside response activity moves the demo target so follow-up speed stays visible while the inbox changes.',
            'performance',
            100,
            91,
            '%',
            date('Y-m-d'),
            date('Y-m-d', strtotime('+14 days')),
            'weekly',
            'active',
        ]);

        if (Database::columnExists('targets', 'scope')) {
            $columns[] = 'scope';
            $values[] = 'personal';
        }
        if (Database::columnExists('targets', 'progress_mode')) {
            $columns[] = 'progress_mode';
            $values[] = 'manual';
        }
        if (Database::columnExists('targets', 'manual_adjustment_value')) {
            $columns[] = 'manual_adjustment_value';
            $values[] = 91;
        }
        if (Database::columnExists('targets', 'custom_reminder_days')) {
            $columns[] = 'custom_reminder_days';
            $values[] = null;
        }
        if (Database::columnExists('targets', 'metadata_json')) {
            $columns[] = 'metadata_json';
            $values[] = json_encode([
                'source' => 'protected_demo_experience',
                'demo_event_key' => 'target_progress_spotlight',
                'previous_value' => 82,
                'simulated' => true,
            ], JSON_UNESCAPED_SLASHES);
        }
        foreach ([
            'source_surface' => 'protected_demo',
            'source_capability_key' => 'demo_timed_target',
            'source_plugin_key' => 'clarity_demo',
        ] as $column => $value) {
            if (Database::columnExists('targets', $column)) {
                $columns[] = $column;
                $values[] = $value;
            }
        }

        Database::execute(
            "INSERT INTO targets (" . implode(', ', $columns) . ")
             VALUES (" . implode(', ', array_fill(0, count($columns), '?')) . ")",
            $values
        );
        $targetId = (int) Database::lastInsertId();
        $this->scope->registerEntity($sessionId, $workspaceId, 'targets', $targetId, 'session_private', [
            'source' => 'protected_demo_experience',
        ]);

        $notification = $this->createNotification($session, [
            'type' => 'demo_target_spotlight',
            'title' => 'Response target moved to 91%',
            'message' => 'The Riverside follow-up moved the qualified-lead response target from 82% to 91%.',
            'entity_type' => 'target',
            'entity_id' => $targetId,
            'link' => 'targets.php',
            'severity' => 'info',
            'ai_insight' => 'Targets make response quality visible while the Riverside thread is still warm.',
            'ai_action' => 'Open targets',
            'sound' => false,
            'demo_event_key' => 'target_progress_spotlight',
            'toast_label' => 'Target',
            'toast_action_label' => 'Open targets',
            'toast_context_url' => 'targets.php',
            'phase' => 'operating_goal',
            'moment_index' => 6,
            'moment_total' => self::MOMENT_TOTAL,
            'progress_label' => 'Target moved',
            'toast' => false,
            'cue_key' => (string) ($definition['trigger_name'] ?? ''),
            'moment_state' => (string) ($definition['phase'] ?? 'operating_goal'),
            'next_cue' => (string) ($definition['next_cue'] ?? 'Ask Clarity'),
            'highlight_selector' => (string) ($definition['highlight_selector'] ?? '[data-demo-riverside-target="1"]'),
            'scene_key' => (string) ($definition['scene_key'] ?? 'target_moved'),
            'scene_step' => (string) ($definition['scene_step'] ?? 'target_progress'),
            'story_version' => self::STORY_VERSION,
        ]);

        return ['target_id' => $targetId, 'notification_id' => $notification['notification_id'] ?? 0];
    }

    /**
     * @return array<string,mixed>
     */
    private function emitContactSpotlight(array $session, array $definition = [], array $cueContext = []): array
    {
        $workspaceId = (int) ($session['workspace_id'] ?? 0);
        $sessionId = (int) ($session['id'] ?? 0);
        $contactId = $this->riversideSessionContactId($workspaceId, $sessionId);
        if ($contactId <= 0) {
            $contactId = $this->latestSessionContactId($workspaceId, $sessionId);
        }
        if ($contactId <= 0) {
            $contactId = $this->createSpotlightContact($session);
        }

        $notification = $this->createNotification($session, [
            'type' => 'demo_contact_spotlight',
            'title' => 'Riverside contact intelligence updated',
            'message' => 'Amina\'s contact now carries the WhatsApp lead, email context, draft, task, and response target in your private overlay.',
            'entity_type' => 'contact',
            'entity_id' => $contactId,
            'link' => 'contacts.php',
            'severity' => 'info',
            'ai_insight' => 'Contacts become useful when they inherit the full Riverside conversation, not just a name and phone number.',
            'ai_action' => 'Open contacts',
            'sound' => false,
            'demo_event_key' => 'contact_intelligence_spotlight',
            'toast_label' => 'Contact',
            'toast_action_label' => 'Open contacts',
            'toast_context_url' => 'contacts.php',
            'phase' => 'contact_memory',
            'moment_index' => 9,
            'moment_total' => self::MOMENT_TOTAL,
            'progress_label' => 'Contact intelligence',
            'toast' => false,
            'cue_key' => (string) ($definition['trigger_name'] ?? ''),
            'moment_state' => (string) ($definition['phase'] ?? 'contact_memory'),
            'next_cue' => (string) ($definition['next_cue'] ?? 'Review recap'),
            'highlight_selector' => (string) ($definition['highlight_selector'] ?? '[data-demo-riverside-contact="1"]'),
            'scene_key' => (string) ($definition['scene_key'] ?? 'meeting_prep_revealed'),
            'scene_step' => (string) ($definition['scene_step'] ?? 'meeting_prep'),
            'story_version' => self::STORY_VERSION,
        ]);

        return ['contact_id' => $contactId, 'notification_id' => $notification['notification_id'] ?? 0];
    }

    /**
     * @return array<string,mixed>
     */
    private function emitContactsSequence(array $session, array $definition = [], array $cueContext = []): array
    {
        $workspaceId = (int) ($session['workspace_id'] ?? 0);
        $sessionId = (int) ($session['id'] ?? 0);

        $contacts = [
            [
                'first_name' => 'Daniel',
                'last_name' => 'Mwangi',
                'email' => 'daniel.mwangi@nairobifitout.example',
                'phone' => '+25473340' . str_pad((string) ($sessionId % 10000), 4, '0', STR_PAD_LEFT),
                'company' => 'Nairobi Fitout Studio',
                'stage' => 'qualified',
                'lead_score' => 76,
                'job_title' => 'Operations Lead',
                'source' => 'email',
                'context' => 'Asked for a delivery calendar after the Riverside proposal draft.',
            ],
            [
                'first_name' => 'Kevin',
                'last_name' => 'Shah',
                'email' => 'kevin.shah@kilimanilofts.example',
                'phone' => '+25474450' . str_pad((string) ($sessionId % 10000), 4, '0', STR_PAD_LEFT),
                'company' => 'Kileleshwa Lofts',
                'stage' => 'proposal',
                'lead_score' => 82,
                'job_title' => 'Project Sponsor',
                'source' => 'referral',
                'context' => 'Warm referral watching how Riverside handles payment terms.',
            ],
            [
                'first_name' => 'Amina',
                'last_name' => 'Otieno',
                'email' => 'amina.otieno@riverside-residence.example',
                'phone' => '+25470010' . str_pad((string) ($sessionId % 10000), 4, '0', STR_PAD_LEFT),
                'company' => 'Riverside Residence',
                'stage' => 'proposal',
                'lead_score' => 94,
                'job_title' => 'Property Manager',
                'source' => 'whatsapp',
                'context' => 'High-intent buyer tied to WhatsApp, procurement email, draft, task, and response target.',
            ],
        ];

        $contactIds = [];
        foreach ($contacts as $contact) {
            $contactIds[] = $this->createDemoContact($session, $contact);
        }

        $aminaId = $this->riversideSessionContactId($workspaceId, $sessionId);
        if ($aminaId > 0) {
            $this->createContactActivity($session, $aminaId, 'meeting_prep', 'Meeting prep assembled from Amina\'s WhatsApp lead, Rose\'s email, procurement follow-up, and the proposal task.');
            $this->createContactActivity($session, $aminaId, 'score_update', 'Lead score increased to 94 after procurement confirmed the review window and Friday installation hold.');
            $this->createContactActivity($session, $aminaId, 'timeline_link', 'Riverside thread, assistant draft, task, and response target are linked to this contact.');
        }

        $payload = $this->scenePayload($definition, $cueContext, [
            'animation_payload' => [
                'contacts' => array_map(static fn(array $contact, int $id): array => [
                    'id' => $id,
                    'name' => trim($contact['first_name'] . ' ' . $contact['last_name']),
                    'company' => $contact['company'],
                    'score' => $contact['lead_score'],
                ], $contacts, $contactIds),
            ],
            'persisted_entity_refs' => [
                'contact_ids' => $contactIds,
                'amina_contact_id' => $aminaId,
            ],
        ]);
        $this->events->publish($workspaceId, $sessionId, 'demo_scene', 'contact', $aminaId, $payload);

        return [
            'contact_ids' => $contactIds,
            'amina_contact_id' => $aminaId,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function emitPluginScene(array $session, array $definition = [], array $cueContext = []): array
    {
        $workspaceId = (int) ($session['workspace_id'] ?? 0);
        $sessionId = (int) ($session['id'] ?? 0);
        $plugins = [
            ['key' => 'email_assistant', 'label' => 'Email Assistant', 'state' => 'Configured', 'proof' => 'Drafts and procurement context are ready.'],
            ['key' => 'whatsapp_assistant', 'label' => 'WhatsApp Assistant', 'state' => 'Configured', 'proof' => 'Inbound lead capture is simulated and scoped.'],
            ['key' => 'clarity_ai', 'label' => 'Clarity AI', 'state' => 'Live', 'proof' => 'Next-best-action guidance is active.'],
            ['key' => 'targets', 'label' => 'Targets', 'state' => 'Live', 'proof' => 'Response metric moves with the Riverside story.'],
            ['key' => 'meeting_prep', 'label' => 'Meeting Prep', 'state' => 'Ready', 'proof' => 'Contact brief can be revealed without live AI calls.'],
        ];

        $payload = $this->scenePayload($definition, $cueContext, [
            'animation_payload' => [
                'plugins' => $plugins,
                'mutates_workspace_installs' => false,
            ],
            'persisted_entity_refs' => [
                'overlay' => 'demo_experience_events',
            ],
        ]);
        $eventId = $this->events->publish($workspaceId, $sessionId, 'demo_scene', 'plugin_overlay', 0, $payload);
        $notification = $this->createNotification($session, [
            'type' => 'demo_plugin_spotlight',
            'title' => 'Configured plugin capabilities are live',
            'message' => 'Email Assistant, WhatsApp Assistant, Clarity AI, Targets, and Meeting Prep are showcased as private demo capabilities.',
            'entity_type' => 'plugin_overlay',
            'entity_id' => null,
            'link' => 'workspace_skills.php',
            'severity' => 'info',
            'ai_insight' => 'Plugin state is presented as a private scene overlay, while real workspace install rows remain unchanged.',
            'ai_action' => 'Review configured demo plugins.',
            'demo_event_key' => (string) ($definition['key'] ?? 'marketplace_plugin_sequence_started'),
            'toast' => false,
        ] + $payload);

        return [
            'event_id' => $eventId,
            'notification_id' => $notification['notification_id'] ?? 0,
            'plugins' => $plugins,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function emitNotification(array $session, array $definition): array
    {
        $entityType = (string) ($definition['entity_type'] ?? 'demo_session');
        $entityId = null;
        $definitionKey = (string) ($definition['key'] ?? '');
        if (in_array($definitionKey, ['assistant_draft_ready', 'assistant_draft_typing_started'], true)) {
            $draftId = $this->createAssistantDraftCommunication(
                $session,
                (string) ($definition['draft_preview'] ?? '')
            );
            if ($draftId > 0) {
                $entityType = 'communication';
                $entityId = $draftId;
            }
        }

        $notification = $this->createNotification($session, [
            'type' => (string) ($definition['notification_type'] ?? 'demo_experience'),
            'title' => (string) ($definition['title'] ?? 'Demo update'),
            'message' => (string) ($definition['message'] ?? 'A protected demo update is ready.'),
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'link' => (string) ($definition['link'] ?? 'notifications.php'),
            'severity' => (string) ($definition['severity'] ?? 'info'),
            'ai_insight' => (string) ($definition['ai_insight'] ?? ''),
            'ai_action' => (string) ($definition['ai_action'] ?? ''),
            'sound' => !empty($definition['sound']),
            'open_clarity' => !empty($definition['open_clarity']),
            'demo_event_key' => $definitionKey,
            'toast_label' => (string) ($definition['toast_label'] ?? ''),
            'toast_action_label' => (string) ($definition['toast_action_label'] ?? ''),
            'toast_context_url' => (string) ($definition['toast_context_url'] ?? ($definition['link'] ?? 'notifications.php')),
            'phase' => (string) ($definition['phase'] ?? ''),
            'moment_index' => (int) ($definition['moment_index'] ?? 0),
            'moment_total' => self::MOMENT_TOTAL,
            'progress_label' => (string) ($definition['progress_label'] ?? ''),
            'draft_preview' => (string) ($definition['draft_preview'] ?? ''),
            'toast' => empty($definition['_suppress_toast']),
            'cue_key' => (string) ($definition['trigger_name'] ?? ''),
            'moment_state' => (string) ($definition['phase'] ?? ''),
            'next_cue' => (string) ($definition['next_cue'] ?? ''),
            'highlight_selector' => (string) ($definition['highlight_selector'] ?? ''),
            'scene_key' => (string) ($definition['scene_key'] ?? $definitionKey),
            'scene_step' => (string) ($definition['scene_step'] ?? ''),
            'auto_action' => (string) ($definition['auto_action'] ?? ''),
            'auto_action_url' => (string) ($definition['auto_action_url'] ?? ''),
            'animation_payload' => (array) ($definition['animation_payload'] ?? []),
            'story_version' => self::STORY_VERSION,
            'toast' => false,
        ]);

        if (!empty($definition['publish_triage'])) {
            $latestCommunicationId = $this->latestSessionCommunicationId(
                (int) ($session['workspace_id'] ?? 0),
                (int) ($session['id'] ?? 0)
            );
            if ($latestCommunicationId > 0) {
                $this->events->publish(
                    (int) ($session['workspace_id'] ?? 0),
                    (int) ($session['id'] ?? 0),
                    'triage_completed',
                    'communication',
                    $latestCommunicationId,
                    [
                        'priority' => 'high',
                        'reasons' => ['visitor_private_thread', 'assistant_draft_ready'],
                        'demo_event_key' => (string) ($definition['key'] ?? ''),
                        'cue_key' => (string) ($definition['trigger_name'] ?? ''),
                        'moment_state' => (string) ($definition['phase'] ?? ''),
                        'next_cue' => (string) ($definition['next_cue'] ?? ''),
                        'highlight_selector' => (string) ($definition['highlight_selector'] ?? ''),
                        'progress_label' => (string) ($definition['progress_label'] ?? ''),
                        'scene_key' => (string) ($definition['scene_key'] ?? $definitionKey),
                        'scene_step' => (string) ($definition['scene_step'] ?? ''),
                        'toast' => false,
                        'sound' => false,
                    ]
                );
            }
        }

        return $notification;
    }

    private function createAssistantDraftCommunication(array $session, string $draftPreview): int
    {
        $workspaceId = (int) ($session['workspace_id'] ?? 0);
        $sessionId = (int) ($session['id'] ?? 0);
        $userId = $this->sessionUserId($session);
        if ($workspaceId <= 0 || $sessionId <= 0 || trim($draftPreview) === '') {
            return 0;
        }

        $source = Database::queryOne(
            "SELECT contact_id, thread_key, channel
             FROM communications
             WHERE workspace_id = ?
               AND demo_session_id = ?
               AND demo_visibility = 'session_private'
             ORDER BY id DESC
             LIMIT 1",
            [$workspaceId, $sessionId]
        ) ?: [];

        $contactId = (int) ($source['contact_id'] ?? 0);
        $threadKey = (string) ($source['thread_key'] ?? '');
        $channel = (string) ($source['channel'] ?? 'whatsapp');
        if ($contactId <= 0 || $threadKey === '') {
            return 0;
        }

        Database::execute(
            "INSERT INTO communications
                (workspace_id, demo_visibility, demo_session_id, uuid, contact_id, thread_key, channel, direction, subject, body, metadata, status, read_at,
                 triage_priority, triage_score, triage_confidence, triage_status, triage_reason_codes, triage_decided_at, from_email, to_email, message_id)
             VALUES (?, 'session_private', ?, UUID(), ?, ?, ?, 'outbound', ?, ?, ?, 'sent', NOW(),
                 'high', 91.00, 94.00, 'suggested', ?, NOW(), 'workspace-demo@demo.local.invalid', ?, ?)",
            [
                $workspaceId,
                $sessionId,
                $contactId,
                $threadKey,
                $channel,
                'Draft reply: Riverside proposal revision',
                $draftPreview,
                json_encode([
                    'source' => 'protected_demo_experience',
                    'demo_event_key' => 'assistant_draft_typing_started',
                    'delivery_mode' => 'simulated_first',
                    'provider_call' => false,
                    'draft_status' => 'assistant_suggested',
                    'draft_preview' => $draftPreview,
                ], JSON_UNESCAPED_SLASHES),
                json_encode(['visitor_private_thread', 'assistant_draft_ready', 'riverside_proposal_revision'], JSON_UNESCAPED_SLASHES),
                'amina.otieno@riverside-residence.example',
                'demo-draft-' . $sessionId . '-' . bin2hex(random_bytes(6)) . '@demo.local',
            ]
        );

        $draftId = (int) Database::lastInsertId();
        $this->scope->registerEntity($sessionId, $workspaceId, 'communications', $draftId, 'session_private', [
            'source' => 'protected_demo_experience',
            'demo_event_key' => 'assistant_draft_typing_started',
        ]);
        $this->events->publish($workspaceId, $sessionId, 'communication_created', 'communication', $draftId, [
            'channel' => $channel,
            'direction' => 'outbound',
            'status' => 'draft',
            'thread_key' => $threadKey,
            'demo_event_key' => 'assistant_draft_typing_started',
            'phase' => 'triage_and_draft',
            'moment_index' => 9,
            'moment_total' => self::MOMENT_TOTAL,
            'progress_label' => 'AI draft',
            'cue_key' => 'riverside_thread_opened',
            'moment_state' => 'triage_and_draft',
            'next_cue' => 'Review draft, then open Tasks',
            'highlight_selector' => '[data-protected-demo-draft], #conversation-reply-form, #suggest-reply-btn',
            'scene_key' => 'assistant_draft_typing_started',
            'scene_step' => 'draft_typing',
            'auto_action' => 'type_draft',
            'draft_preview' => $draftPreview,
            'toast' => false,
            'sound' => false,
            'story_version' => self::STORY_VERSION,
        ]);

        return $draftId;
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function createNotification(array $session, array $options): array
    {
        $workspaceId = (int) ($session['workspace_id'] ?? 0);
        $sessionId = (int) ($session['id'] ?? 0);
        $userId = $this->sessionUserId($session);
        $link = (string) ($options['link'] ?? 'notifications.php');

        $notifications = new Notifications();
        $notificationId = $notifications->create(
            $userId,
            (string) ($options['type'] ?? 'demo_experience'),
            (string) ($options['title'] ?? 'Demo update'),
            (string) ($options['message'] ?? ''),
            [
                'entity_type' => (string) ($options['entity_type'] ?? 'demo_session'),
                'entity_id' => !empty($options['entity_id']) ? (int) $options['entity_id'] : null,
                'link' => function_exists('publicUrl') ? publicUrl($link) : '/' . ltrim($link, '/'),
                'severity' => (string) ($options['severity'] ?? 'info'),
                'ai_insight' => (string) ($options['ai_insight'] ?? ''),
                'ai_action' => (string) ($options['ai_action'] ?? ''),
                'demo_session_id' => $sessionId,
            ]
        );
        $this->scope->registerEntity($sessionId, $workspaceId, 'notifications', $notificationId, 'session_private', [
            'source' => 'protected_demo_experience',
            'demo_event_key' => (string) ($options['demo_event_key'] ?? ''),
        ]);

        $payload = [
            'notification' => $this->notificationPayload($notificationId, $workspaceId, $sessionId),
            'toast' => (bool) ($options['toast'] ?? false),
            'sound' => !empty($options['sound']) && !empty($options['toast']),
            'sound_type' => (!empty($options['sound']) && !empty($options['toast'])) ? 'message' : '',
            'open_clarity' => !empty($options['open_clarity']),
            'demo_event_key' => (string) ($options['demo_event_key'] ?? ''),
            'toast_label' => (string) ($options['toast_label'] ?? ''),
            'toast_action_label' => (string) ($options['toast_action_label'] ?? 'Open'),
            'toast_context_url' => function_exists('publicUrl')
                ? publicUrl((string) ($options['toast_context_url'] ?? $link))
                : '/' . ltrim((string) ($options['toast_context_url'] ?? $link), '/'),
            'phase' => (string) ($options['phase'] ?? ''),
            'moment_index' => (int) ($options['moment_index'] ?? 0),
            'moment_total' => (int) ($options['moment_total'] ?? self::MOMENT_TOTAL),
            'progress_label' => (string) ($options['progress_label'] ?? ''),
            'draft_preview' => (string) ($options['draft_preview'] ?? ''),
            'cue_key' => (string) ($options['cue_key'] ?? ''),
            'moment_state' => (string) ($options['moment_state'] ?? ''),
            'next_cue' => (string) ($options['next_cue'] ?? ''),
            'highlight_selector' => (string) ($options['highlight_selector'] ?? ''),
            'auto_action' => (string) ($options['auto_action'] ?? ''),
            'auto_action_url' => (string) ($options['auto_action_url'] ?? ''),
            'scene_key' => (string) ($options['scene_key'] ?? $options['demo_event_key'] ?? ''),
            'scene_step' => (string) ($options['scene_step'] ?? ''),
            'animation_payload' => (array) ($options['animation_payload'] ?? []),
            'persisted_entity_refs' => (array) ($options['persisted_entity_refs'] ?? []),
            'story_version' => (string) ($options['story_version'] ?? self::STORY_VERSION),
        ];
        $this->events->publish($workspaceId, $sessionId, 'notification_created', 'notification', $notificationId, $payload);

        return [
            'notification_id' => $notificationId,
            'notification' => $payload['notification'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function notificationPayload(int $notificationId, int $workspaceId, int $sessionId): array
    {
        $notification = Database::queryOne(
            "SELECT *
             FROM notifications
             WHERE workspace_id = ?
               AND demo_session_id = ?
               AND id = ?
             LIMIT 1",
            [$workspaceId, $sessionId, $notificationId]
        ) ?: [];

        if ($notification === []) {
            return [];
        }

        $type = (string) ($notification['type'] ?? '');
        $notifications = new Notifications();
        $notification = $this->decodeNotificationTextFields($notification);
        $notification['icon'] = $this->demoIconForType($type);
        $notification['color'] = $notifications->getColor($type);

        return $notification;
    }

    /**
     * @param array<string,mixed> $notification
     * @return array<string,mixed>
     */
    private function decodeNotificationTextFields(array $notification): array
    {
        foreach (['title', 'message', 'ai_insight', 'ai_action'] as $field) {
            if (isset($notification[$field]) && is_scalar($notification[$field])) {
                $notification[$field] = html_entity_decode((string) $notification[$field], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        return $notification;
    }

    private function demoIconForType(string $type): string
    {
        return match ($type) {
            'demo_task_spotlight' => 'Task',
            'demo_target_spotlight' => 'Target',
            'demo_contact_spotlight' => 'Contact',
            'demo_plugin_spotlight' => 'Plugin',
            'ai_coach_nudge' => 'AI',
            'demo_assistant_draft_ready' => 'Draft',
            'demo_experience_recap' => 'Recap',
            default => 'Demo',
        };
    }

    private function latestSessionContactId(int $workspaceId, int $sessionId): int
    {
        $row = Database::queryOne(
            "SELECT id
             FROM contacts
             WHERE workspace_id = ?
               AND demo_session_id = ?
               AND demo_visibility = 'session_private'
             ORDER BY id DESC
             LIMIT 1",
            [$workspaceId, $sessionId]
        );

        return (int) ($row['id'] ?? 0);
    }

    private function riversideSessionContactId(int $workspaceId, int $sessionId): int
    {
        $row = Database::queryOne(
            "SELECT id
             FROM contacts
             WHERE workspace_id = ?
               AND demo_session_id = ?
               AND demo_visibility = 'session_private'
               AND (
                    email = 'amina.otieno@riverside-residence.example'
                    OR email LIKE 'amina.demo+%@example.test'
                    OR first_name = 'Amina'
                    OR company = 'Riverside Residence'
               )
             ORDER BY
                CASE WHEN first_name = 'Amina' THEN 0 ELSE 1 END,
                id ASC
             LIMIT 1",
            [$workspaceId, $sessionId]
        );

        return (int) ($row['id'] ?? 0);
    }

    private function latestSessionCommunicationId(int $workspaceId, int $sessionId): int
    {
        $row = Database::queryOne(
            "SELECT id
             FROM communications
             WHERE workspace_id = ?
               AND demo_session_id = ?
               AND demo_visibility = 'session_private'
             ORDER BY id DESC
             LIMIT 1",
            [$workspaceId, $sessionId]
        );

        return (int) ($row['id'] ?? 0);
    }

    /**
     * @return array<string,mixed>
     */
    private function latestRiversideCommunication(int $workspaceId, int $sessionId): array
    {
        $row = Database::queryOne(
            "SELECT id, contact_id, channel, thread_key
             FROM communications
             WHERE workspace_id = ?
               AND demo_session_id = ?
               AND demo_visibility = 'session_private'
               AND (
                    subject LIKE '%Riverside%'
                    OR body LIKE '%Riverside%'
                    OR body LIKE '%Amina%'
               )
             ORDER BY id DESC
             LIMIT 1",
            [$workspaceId, $sessionId]
        );

        return $row ?: [];
    }

    /**
     * @param array<string,mixed> $communication
     */
    private function conversationUrlForCommunication(array $communication): string
    {
        $communicationId = (int) ($communication['id'] ?? 0);
        if ($communicationId <= 0) {
            return '';
        }

        $params = [
            'id' => $communicationId,
            'channel' => (string) ($communication['channel'] ?? 'email'),
            'owner_scope' => 'mine_unassigned',
        ];
        $contactId = (int) ($communication['contact_id'] ?? 0);
        if ($contactId > 0) {
            $params['contact_id'] = $contactId;
        }

        return 'conversation.php?' . http_build_query($params);
    }

    private function riversideSessionTaskId(int $workspaceId, int $sessionId): int
    {
        $row = Database::queryOne(
            "SELECT id
             FROM tasks
             WHERE workspace_id = ?
               AND demo_session_id = ?
               AND demo_visibility = 'session_private'
               AND (
                    title LIKE '%Riverside proposal%'
                    OR metadata_json LIKE '%riverside_revised_proposal%'
               )
             ORDER BY id DESC
             LIMIT 1",
            [$workspaceId, $sessionId]
        );

        return (int) ($row['id'] ?? 0);
    }

    /**
     * @param array<string,mixed> $task
     */
    private function createDemoTask(array $session, int $contactId, array $task): int
    {
        $workspaceId = (int) ($session['workspace_id'] ?? 0);
        $sessionId = (int) ($session['id'] ?? 0);
        $userId = $this->sessionUserId($session);
        $demoKey = (string) ($task['demo_key'] ?? '');
        $title = (string) ($task['title'] ?? 'Riverside follow-up');

        $existing = Database::queryOne(
            "SELECT id
             FROM tasks
             WHERE workspace_id = ?
               AND demo_session_id = ?
               AND demo_visibility = 'session_private'
               AND (title = ? OR metadata_json LIKE ?)
             ORDER BY id DESC
             LIMIT 1",
            [$workspaceId, $sessionId, $title, '%' . $demoKey . '%']
        );
        if ($existing) {
            return (int) ($existing['id'] ?? 0);
        }

        $metadata = [
            'source' => 'protected_demo_experience',
            'demo_event_key' => 'tasks_sequence_started',
            'demo_task_key' => $demoKey,
            'simulated' => true,
            'demo_assignee_label' => 'You (demo owner)',
            'demo_created_by_label' => 'Clarity demo automation',
            'demo_contact_label' => 'Amina Otieno / Riverside Residence',
            'scene_key' => 'tasks_sequence_started',
            'ready_state' => $demoKey === 'riverside_revised_proposal' ? 'draft_reviewed_ready_to_send' : 'queued_follow_up',
        ];

        Database::execute(
            "INSERT INTO tasks
                (workspace_id, demo_visibility, demo_session_id, title, description, metadata_json, source_plugin_key, source_surface, source_capability_key, contact_id, assigned_to, created_by, status, priority, due_date)
             VALUES (?, 'session_private', ?, ?, ?, ?, 'clarity_demo', 'protected_demo', 'demo_autopilot_task', ?, ?, ?, 'pending', ?, ?)",
            [
                $workspaceId,
                $sessionId,
                $title,
                (string) ($task['description'] ?? ''),
                json_encode($metadata, JSON_UNESCAPED_SLASHES),
                $contactId > 0 ? $contactId : null,
                $userId,
                $userId,
                (string) ($task['priority'] ?? 'medium'),
                date('Y-m-d H:i:s', strtotime((string) ($task['due'] ?? '+4 hours'))),
            ]
        );

        $taskId = (int) Database::lastInsertId();
        $this->scope->registerEntity($sessionId, $workspaceId, 'tasks', $taskId, 'session_private', [
            'source' => 'protected_demo_experience',
            'demo_task_key' => $demoKey,
        ]);

        return $taskId;
    }

    /**
     * @param array<string,mixed> $contact
     */
    private function createDemoContact(array $session, array $contact): int
    {
        $workspaceId = (int) ($session['workspace_id'] ?? 0);
        $sessionId = (int) ($session['id'] ?? 0);
        $userId = $this->sessionUserId($session);
        $email = strtolower((string) ($contact['email'] ?? 'visitor-' . $sessionId . '@demo.local.invalid'));
        $existing = Database::queryOne(
            "SELECT id
             FROM contacts
             WHERE workspace_id = ?
               AND demo_session_id = ?
               AND demo_visibility = 'session_private'
               AND LOWER(email) = ?
             LIMIT 1",
            [$workspaceId, $sessionId, $email]
        );
        $leadSource = (string) ($contact['source'] ?? 'other');
        if (!in_array($leadSource, ['form', 'whatsapp', 'ad', 'referral', 'social', 'import', 'other', 'mobile_app', 'web_assessment'], true)) {
            $leadSource = 'other';
        }

        $metadata = [
            'source' => 'protected_demo_experience',
            'demo_event_key' => 'contacts_sequence_started',
            'scene_key' => 'contacts_sequence_started',
            'context' => (string) ($contact['context'] ?? ''),
            'meeting_prep' => [
                'summary' => 'Riverside is warm because Amina, Rose, and procurement are aligned around a revised proposal before 3:00 PM.',
                'key_points' => [
                    'Confirm matte stone finish and split payment terms.',
                    'Protect the Friday installation hold while procurement reviews.',
                    'Send the revised proposal with WhatsApp reminder setup included.',
                ],
                'open_questions' => [
                    'Should the finish schedule be locked before procurement signs off?',
                    'Who gives final approval after the noon review window?',
                ],
                'suggested_topics' => [
                    'Proposal delta',
                    'Payment terms',
                    'Installation hold',
                    'Reminder workflow',
                ],
            ],
            'score_timeline' => [
                ['label' => 'WhatsApp lead captured', 'score' => 82],
                ['label' => 'Email context matched', 'score' => 88],
                ['label' => 'Procurement deadline confirmed', 'score' => (int) ($contact['lead_score'] ?? 90)],
            ],
        ];
        $aiContext = [
            'source' => 'protected_demo_experience',
            'summary' => (string) ($contact['context'] ?? ''),
            'signals' => [
                'Riverside proposal revision',
                'Procurement review window',
                'Friday installation hold',
                'WhatsApp reminder workflow',
            ],
            'recommended_next_step' => 'Review the assistant draft and confirm the revised proposal before 3:00 PM.',
        ];
        $aiContextJson = json_encode($aiContext, JSON_UNESCAPED_SLASHES) ?: null;

        if ($existing) {
            $existingId = (int) ($existing['id'] ?? 0);
            Database::execute(
                "UPDATE contacts
                 SET stage = ?,
                     lead_score = GREATEST(COALESCE(lead_score, 0), ?),
                     engagement_score = GREATEST(COALESCE(engagement_score, 0), 92),
                     ai_score = GREATEST(COALESCE(ai_score, 0), 96),
                     ml_score = GREATEST(COALESCE(ml_score, 0), 91.00),
                     confidence_score = GREATEST(COALESCE(confidence_score, 0), 94),
                     job_title = COALESCE(NULLIF(job_title, ''), ?),
                     ai_context = COALESCE(NULLIF(ai_context, ''), ?),
                     metadata_json = ?,
                     updated_at = NOW()
                 WHERE workspace_id = ?
                   AND demo_session_id = ?
                   AND id = ?",
                [
                    (string) ($contact['stage'] ?? 'qualified'),
                    (int) ($contact['lead_score'] ?? 80),
                    (string) ($contact['job_title'] ?? ''),
                    $aiContextJson,
                    json_encode($metadata, JSON_UNESCAPED_SLASHES),
                    $workspaceId,
                    $sessionId,
                    $existingId,
                ]
            );
            return $existingId;
        }

        Database::execute(
            "INSERT INTO contacts
                (workspace_id, demo_visibility, demo_session_id, uuid, first_name, last_name, email, phone, company, lead_source, stage, assigned_to, created_by, lead_score, engagement_score, ai_score, ml_score, confidence_score, job_title, location, timezone, company_industry, ai_context, metadata_json)
             VALUES (?, 'session_private', ?, UUID(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 92, 96, 91.00, 94, ?, 'Nairobi, Kenya', 'Africa/Nairobi', 'Real estate', ?, ?)",
            [
                $workspaceId,
                $sessionId,
                (string) ($contact['first_name'] ?? ''),
                (string) ($contact['last_name'] ?? ''),
                $email,
                (string) ($contact['phone'] ?? ''),
                (string) ($contact['company'] ?? ''),
                $leadSource,
                (string) ($contact['stage'] ?? 'qualified'),
                $userId,
                $userId,
                (int) ($contact['lead_score'] ?? 80),
                (string) ($contact['job_title'] ?? ''),
                $aiContextJson,
                json_encode($metadata, JSON_UNESCAPED_SLASHES),
            ]
        );

        $contactId = (int) Database::lastInsertId();
        $this->scope->registerEntity($sessionId, $workspaceId, 'contacts', $contactId, 'session_private', [
            'source' => 'protected_demo_experience',
        ]);

        return $contactId;
    }

    private function createContactActivity(array $session, int $contactId, string $type, string $description): int
    {
        if ($contactId <= 0 || trim($description) === '') {
            return 0;
        }

        $workspaceId = (int) ($session['workspace_id'] ?? 0);
        $sessionId = (int) ($session['id'] ?? 0);
        $userId = $this->sessionUserId($session);
        $existing = Database::queryOne(
            "SELECT id
             FROM activities
             WHERE workspace_id = ?
               AND demo_session_id = ?
               AND demo_visibility = 'session_private'
               AND contact_id = ?
               AND activity_type = ?
               AND description = ?
             LIMIT 1",
            [$workspaceId, $sessionId, $contactId, $type, $description]
        );
        if ($existing) {
            return (int) ($existing['id'] ?? 0);
        }

        Database::execute(
            "INSERT INTO activities (workspace_id, demo_visibility, demo_session_id, contact_id, user_id, activity_type, description, metadata)
             VALUES (?, 'session_private', ?, ?, ?, ?, ?, ?)",
            [
                $workspaceId,
                $sessionId,
                $contactId,
                $userId,
                $type,
                $description,
                json_encode([
                    'source' => 'protected_demo_experience',
                    'scene_key' => 'meeting_prep_revealed',
                ], JSON_UNESCAPED_SLASHES),
            ]
        );

        $activityId = (int) Database::lastInsertId();
        $this->scope->registerEntity($sessionId, $workspaceId, 'activities', $activityId, 'session_private', [
            'source' => 'protected_demo_experience',
        ]);

        return $activityId;
    }

    private function createSpotlightContact(array $session): int
    {
        $workspaceId = (int) ($session['workspace_id'] ?? 0);
        $sessionId = (int) ($session['id'] ?? 0);
        $userId = $this->sessionUserId($session);

        Database::execute(
            "INSERT INTO contacts
                (workspace_id, demo_visibility, demo_session_id, uuid, first_name, last_name, email, phone, company, lead_source, stage, assigned_to, created_by, metadata_json)
             VALUES (?, 'session_private', ?, UUID(), 'Amina', 'Otieno', ?, ?, 'Riverside Residence', 'whatsapp', 'proposal', ?, ?, ?)",
            [
                $workspaceId,
                $sessionId,
                'amina.otieno@riverside-residence.example',
                '+25470010' . str_pad((string) ($sessionId % 10000), 4, '0', STR_PAD_LEFT),
                $userId,
                $userId,
                json_encode(['source' => 'protected_demo_experience'], JSON_UNESCAPED_SLASHES),
            ]
        );

        $contactId = (int) Database::lastInsertId();
        $this->scope->registerEntity($sessionId, $workspaceId, 'contacts', $contactId, 'session_private', [
            'source' => 'protected_demo_experience',
        ]);

        return $contactId;
    }

    private function markEventSent(int $eventId, int $workspaceId, int $sessionId, array $result): void
    {
        Database::execute(
            "UPDATE demo_experience_events
             SET status = 'sent',
                 sent_at = NOW(),
                 payload_json = ?
             WHERE id = ?
               AND workspace_id = ?
               AND demo_session_id = ?",
            [json_encode($result, JSON_UNESCAPED_SLASHES), $eventId, $workspaceId, $sessionId]
        );
    }

    private function markEventSkipped(int $eventId, int $workspaceId, int $sessionId, string $reason): void
    {
        Database::execute(
            "UPDATE demo_experience_events
             SET status = 'skipped',
                 payload_json = ?
             WHERE id = ?
               AND workspace_id = ?
               AND demo_session_id = ?",
            [
                json_encode(['skipped_reason' => mb_substr($reason, 0, 500)], JSON_UNESCAPED_SLASHES),
                $eventId,
                $workspaceId,
                $sessionId,
            ]
        );
    }

    private function sessionUserId(array $session): int
    {
        $userId = (int) (Auth::userId() ?? 0);
        if ($userId > 0) {
            return $userId;
        }

        $sessionUserId = (int) ($session['user_id'] ?? 0);
        if ($sessionUserId > 0) {
            return $sessionUserId;
        }

        return (int) ($session['guest_user_id'] ?? 0);
    }

    /**
     * @param array<string,mixed> $input
     */
    private function pauseReason(array $input): string
    {
        $reason = Security::sanitizeInput((string) ($input['paused_reason'] ?? ''), 'string');
        if ($reason !== '') {
            return mb_substr($reason, 0, 80);
        }

        $visibility = strtolower(trim((string) ($input['client_visibility'] ?? '')));
        return $visibility === 'hidden' ? 'document_hidden' : '';
    }
}
