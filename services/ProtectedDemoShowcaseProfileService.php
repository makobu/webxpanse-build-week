<?php

declare(strict_types=1);

namespace CRM\Services;

class ProtectedDemoShowcaseProfileService
{
    /**
     * @return array<string,mixed>
     */
    public function automationBatteryStatus(int $subjectUserId): array
    {
        if ($this->profileKey() === 'metrodrive') {
            return $this->metroDriveAutomationBatteryStatus($subjectUserId);
        }

        $now = date('Y-m-d H:i:s');
        $expiresAt = date('Y-m-d H:i:s', time() + 3600);

        return [
            'score' => 100,
            'bucket' => 'full',
            'status_label' => 'Full automation',
            'headline_label' => 'Every revenue signal is connected',
            'mode_label' => 'Fully configured workspace',
            'summary' => 'Email, WhatsApp, triage, tasks, targets, contact intelligence, templates, and Clarity AI are configured for this protected demo.',
            'setup_progress_label' => '8/8 revenue systems live',
            'signal_title' => 'Demo workspace fully powered',
            'signal_copy' => 'The protected demo is already connected, tuned, and ready to show the Riverside lead journey.',
            'top_blockers' => [],
            'top_boosters' => [
                'Email and WhatsApp channels healthy',
                'AI triage and assistant drafts active',
                'Tasks, targets, and contact intelligence synced',
                'Plugin capabilities configured for the demo workflow',
            ],
            'layers' => $this->fullAutomationLayers(),
            'job_health' => [
                'healthy' => 12,
                'failed' => 0,
                'stale' => 0,
                'pending' => 0,
                'summary' => 'All protected demo automation jobs are healthy.',
            ],
            'subject_user_id' => $subjectUserId,
            'snapshot_calculated_at' => $now,
            'snapshot_expires_at' => $expiresAt,
            'snapshot_source' => 'protected_demo_showcase',
            'snapshot_fingerprint' => 'protected-demo-showcase-v1',
            'is_stale' => false,
            'updated_label' => 'Updated just now',
            'protected_demo_showcase' => true,
        ];
    }

    /**
     * @return array<int,array<string,string|int>>
     */
    public function storyMoments(): array
    {
        if ($this->profileKey() === 'metrodrive') {
            return [
                ['key' => 'dashboard_scene_started', 'time' => 0, 'state' => 'Ready', 'label' => 'Ready', 'caption' => 'MetroDrive workspace is fully configured', 'href' => 'inbox.php'],
                ['key' => 'lead_inquiry', 'time' => 0, 'state' => 'Lead arrived', 'label' => 'Lead inquiry', 'caption' => 'Beginner lesson WhatsApp lead arrives', 'href' => 'inbox.php'],
                ['key' => 'booking_confirmation', 'time' => 0, 'state' => 'Booking', 'label' => 'Booking ready', 'caption' => 'AI proposes structured starter package', 'href' => 'tasks.php'],
                ['key' => 'document_followup', 'time' => 0, 'state' => 'Documents', 'label' => 'Documents', 'caption' => 'Missing learner documents are followed up', 'href' => 'contacts.php'],
                ['key' => 'payment_reminder', 'time' => 0, 'state' => 'Payment', 'label' => 'Payment', 'caption' => 'Balance reminder is prepared', 'href' => 'invoices.php'],
                ['key' => 'email_digest', 'time' => 0, 'state' => 'Digest', 'label' => 'Digest', 'caption' => 'Owner digest summarizes the day', 'href' => 'notifications.php'],
                ['key' => 'escalation', 'time' => 0, 'state' => 'Escalation', 'label' => 'Escalation', 'caption' => 'Instructor complaint routes to human review', 'href' => 'tasks.php'],
                ['key' => 'report_snapshot', 'time' => 240, 'state' => 'Reports', 'label' => 'Reports', 'caption' => 'Weekly and monthly views are ready', 'href' => 'reports.php'],
            ];
        }

        return [
            [
                'key' => 'dashboard_scene_started',
                'time' => 0,
                'state' => 'Ready',
                'label' => 'Ready',
                'caption' => 'Workspace is fully configured',
                'href' => 'inbox.php',
            ],
            [
                'key' => 'inbox_message_sequence_started',
                'time' => 0,
                'state' => 'Open Inbox',
                'label' => 'Open Inbox',
                'caption' => 'Riverside messages arrive in sequence',
                'href' => 'inbox.php',
            ],
            [
                'key' => 'assistant_draft_typing_started',
                'time' => 0,
                'state' => 'Lead arrived',
                'label' => 'Draft ready',
                'caption' => 'Triage chips and reply draft appear',
                'href' => 'inbox.php',
            ],
            [
                'key' => 'tasks_sequence_started',
                'time' => 0,
                'state' => 'Task created',
                'label' => 'Task created',
                'caption' => 'Follow-up work is queued',
                'href' => 'tasks.php',
            ],
            [
                'key' => 'target_progress_spotlight',
                'time' => 0,
                'state' => 'Target moved',
                'label' => 'Target moved',
                'caption' => 'Response goal updates',
                'href' => 'targets.php',
            ],
            [
                'key' => 'marketplace_plugin_sequence_started',
                'time' => 0,
                'state' => 'Plugins ready',
                'label' => 'Plugins ready',
                'caption' => 'Capability setup animates safely',
                'href' => 'workspace_skills.php',
            ],
            [
                'key' => 'contacts_sequence_started',
                'time' => 0,
                'state' => 'Contact unified',
                'label' => 'Contact unified',
                'caption' => 'Amina becomes the connected record',
                'href' => 'contacts.php',
            ],
            [
                'key' => 'quiet_recap',
                'time' => 270,
                'state' => 'Recap',
                'label' => 'Recap',
                'caption' => 'Private demo feed is complete',
                'href' => 'notifications.php',
            ],
        ];
    }

    /**
     * @return array<string,string|int>
     */
    public function heroCopy(): array
    {
        if ($this->profileKey() === 'metrodrive') {
            return [
                'eyebrow' => 'MetroDrive Academy presentation workspace',
                'title' => 'A driving school AI operator is already at work.',
                'subtitle' => 'Watch lessons, bookings, documents, payments, digests, reports, and human escalations move through a protected supervised demo.',
            ];
        }

        return [
            'eyebrow' => 'Riverside demo workspace',
            'title' => 'A fully configured workspace is already working.',
            'subtitle' => 'Watch WhatsApp, email, triage, assistant drafts, tasks, targets, plugins, and contact intelligence move together in a private protected demo.',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function dashboardMetrics(): array
    {
        if ($this->profileKey() === 'metrodrive') {
            return [
                'metrics_today' => [
                    'leads' => 9,
                    'emails_sent' => 11,
                    'emails_opened' => 8,
                    'form_submissions' => 4,
                ],
                'metrics_week' => [
                    'leads' => 44,
                    'emails_sent' => 79,
                    'emails_opened' => 58,
                    'form_submissions' => 19,
                ],
                'metrics_month' => [
                    'leads' => 163,
                    'emails_sent' => 318,
                    'emails_opened' => 226,
                    'form_submissions' => 72,
                ],
                'tasks_count' => 32,
                'pending_tasks' => 7,
                'overdue_tasks' => 0,
                'open_deals_count' => 12,
                'deals_value' => 1485000.0,
                'pipeline_total' => 1485000.0,
                'total_contacts' => 48,
                'contacts_this_week' => 15,
                'won_contacts' => 8,
                'outcome_focus' => [
                    'Confirm Amina\'s beginner package slot before the evening class fills.',
                    'Review the instructor concern before any automated reply is sent.',
                    'Send the owner digest after document and payment reminders are queued.',
                ],
                'outcome_ttfv' => [
                    'median_hours' => 0.8,
                    'p75_hours' => 1.9,
                    'sample_size' => 34,
                    'trend_vs_prev_pct' => -24.0,
                ],
                'outcome_activation' => ['rate' => 91.0],
                'outcome_revenue_actions' => ['rate' => 82.0],
                'daily_trends' => [
                    ['date' => date('Y-m-d', strtotime('-6 days')), 'contacts' => 5],
                    ['date' => date('Y-m-d', strtotime('-5 days')), 'contacts' => 6],
                    ['date' => date('Y-m-d', strtotime('-4 days')), 'contacts' => 7],
                    ['date' => date('Y-m-d', strtotime('-3 days')), 'contacts' => 5],
                    ['date' => date('Y-m-d', strtotime('-2 days')), 'contacts' => 8],
                    ['date' => date('Y-m-d', strtotime('-1 days')), 'contacts' => 6],
                    ['date' => date('Y-m-d'), 'contacts' => 9],
                ],
                'email_trends' => [
                    ['date' => date('Y-m-d', strtotime('-6 days')), 'sent' => 9, 'opened' => 6],
                    ['date' => date('Y-m-d', strtotime('-5 days')), 'sent' => 10, 'opened' => 7],
                    ['date' => date('Y-m-d', strtotime('-4 days')), 'sent' => 12, 'opened' => 8],
                    ['date' => date('Y-m-d', strtotime('-3 days')), 'sent' => 11, 'opened' => 8],
                    ['date' => date('Y-m-d', strtotime('-2 days')), 'sent' => 13, 'opened' => 9],
                    ['date' => date('Y-m-d', strtotime('-1 days')), 'sent' => 13, 'opened' => 10],
                    ['date' => date('Y-m-d'), 'sent' => 11, 'opened' => 8],
                ],
                'stage_distribution' => [
                    ['stage' => 'new', 'count' => 9],
                    ['stage' => 'qualified', 'count' => 12],
                    ['stage' => 'proposal', 'count' => 11],
                    ['stage' => 'negotiation', 'count' => 8],
                    ['stage' => 'won', 'count' => 8],
                ],
                'deal_stage_summaries' => [
                    ['label' => 'Qualification', 'count_label' => '4 deals', 'value_label' => 'KES 246,000.00', 'color' => '#0ea5e9'],
                    ['label' => 'Proposal', 'count_label' => '5 deals', 'value_label' => 'KES 395,000.00', 'color' => '#f59e0b'],
                    ['label' => 'Negotiation', 'count_label' => '3 deals', 'value_label' => 'KES 844,000.00', 'color' => '#10b981'],
                ],
            ];
        }

        return [
            'metrics_today' => [
                'leads' => 6,
                'emails_sent' => 14,
                'emails_opened' => 10,
                'form_submissions' => 3,
            ],
            'metrics_week' => [
                'leads' => 31,
                'emails_sent' => 86,
                'emails_opened' => 63,
                'form_submissions' => 17,
            ],
            'metrics_month' => [
                'leads' => 118,
                'emails_sent' => 342,
                'emails_opened' => 251,
                'form_submissions' => 64,
            ],
            'tasks_count' => 18,
            'pending_tasks' => 4,
            'overdue_tasks' => 0,
            'open_deals_count' => 7,
            'deals_value' => 1680000.0,
            'pipeline_total' => 1680000.0,
            'total_contacts' => 42,
            'contacts_this_week' => 11,
            'won_contacts' => 9,
            'outcome_focus' => [
                'Send Amina the revised Riverside proposal while the Friday installation hold is still warm.',
                'Review Rose\'s rollout email before the procurement call.',
                'Use the response-time target to keep qualified lead follow-up visible.',
            ],
            'outcome_ttfv' => [
                'median_hours' => 1.6,
                'p75_hours' => 3.2,
                'sample_size' => 24,
                'trend_vs_prev_pct' => -18.4,
            ],
            'outcome_activation' => ['rate' => 87.5],
            'outcome_revenue_actions' => ['rate' => 76.0],
            'daily_trends' => [
                ['date' => date('Y-m-d', strtotime('-6 days')), 'contacts' => 3],
                ['date' => date('Y-m-d', strtotime('-5 days')), 'contacts' => 4],
                ['date' => date('Y-m-d', strtotime('-4 days')), 'contacts' => 5],
                ['date' => date('Y-m-d', strtotime('-3 days')), 'contacts' => 4],
                ['date' => date('Y-m-d', strtotime('-2 days')), 'contacts' => 6],
                ['date' => date('Y-m-d', strtotime('-1 days')), 'contacts' => 5],
                ['date' => date('Y-m-d'), 'contacts' => 6],
            ],
            'email_trends' => [
                ['date' => date('Y-m-d', strtotime('-6 days')), 'sent' => 10, 'opened' => 7],
                ['date' => date('Y-m-d', strtotime('-5 days')), 'sent' => 11, 'opened' => 8],
                ['date' => date('Y-m-d', strtotime('-4 days')), 'sent' => 12, 'opened' => 8],
                ['date' => date('Y-m-d', strtotime('-3 days')), 'sent' => 13, 'opened' => 9],
                ['date' => date('Y-m-d', strtotime('-2 days')), 'sent' => 12, 'opened' => 9],
                ['date' => date('Y-m-d', strtotime('-1 days')), 'sent' => 14, 'opened' => 10],
                ['date' => date('Y-m-d'), 'sent' => 14, 'opened' => 10],
            ],
            'stage_distribution' => [
                ['stage' => 'new', 'count' => 8],
                ['stage' => 'qualified', 'count' => 11],
                ['stage' => 'proposal', 'count' => 12],
                ['stage' => 'negotiation', 'count' => 7],
                ['stage' => 'won', 'count' => 4],
            ],
            'deal_stage_summaries' => [
                ['label' => 'Qualification', 'count_label' => '2 deals', 'value_label' => 'KES 420,000.00', 'color' => '#38bdf8'],
                ['label' => 'Proposal', 'count_label' => '3 deals', 'value_label' => 'KES 810,000.00', 'color' => '#f59e0b'],
                ['label' => 'Negotiation', 'count_label' => '2 deals', 'value_label' => 'KES 450,000.00', 'color' => '#10b981'],
            ],
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function fullAutomationLayers(): array
    {
        return [
            'setup' => $this->layer('Workspace readiness', 100, 'Company profile, channels, products, templates, and operating context are ready.'),
            'autoresponder' => $this->layer('AI Auto Responder', 100, 'Assistant drafts are available with safe simulated delivery.'),
            'commercial' => $this->layer('Commercial Layer', 100, 'Proposal and follow-up context is connected to revenue actions.'),
            'workflow_automation' => $this->layer('Workflow Automation', 100, 'Lead response, task creation, and target updates are coordinated.'),
            'deal' => $this->layer('Deal Automation', 100, 'Riverside opportunity movement is ready to be tracked.'),
            'learning' => $this->layer('Learning Loop', 100, 'Clarity AI can explain the next best action from live demo signals.'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function metroDriveAutomationBatteryStatus(int $subjectUserId): array
    {
        $now = date('Y-m-d H:i:s');
        $expiresAt = date('Y-m-d H:i:s', time() + 3600);

        return [
            'score' => 100,
            'bucket' => 'full',
            'status_label' => 'Full automation',
            'headline_label' => 'Every learner signal is connected',
            'mode_label' => 'Fully configured presentation workspace',
            'summary' => 'MetroDrive Academy has learner intake, bookings, document follow-up, payment reminders, instructor escalation, reports, email digest, and WhatsApp digest ready for a supervised pitch.',
            'setup_progress_label' => '8/8 driving-school systems live',
            'signal_title' => 'MetroDrive demo fully powered',
            'signal_copy' => 'The presentation workspace is seeded, resettable, and ready to show live or simulated email and WhatsApp actions.',
            'top_blockers' => [],
            'top_boosters' => [
                'Email and WhatsApp presentation channels are staged',
                'Lesson booking, document, and payment workflows are loaded',
                'Digest and report context has realistic business history',
                'Low-confidence cases escalate to human review',
            ],
            'layers' => [
                'setup' => $this->layer('Driving school context', 100, 'Branches, lesson packages, instructors, pricing, documents, and booking rules are ready.'),
                'autoresponder' => $this->layer('Learner messaging', 100, 'Email and WhatsApp replies are staged for high-confidence learner operations.'),
                'commercial' => $this->layer('Payments and invoices', 100, 'Deposits, balances, invoices, and payment reminders are connected to learner records.'),
                'workflow_automation' => $this->layer('Booking workflows', 100, 'Lesson slots, follow-ups, tasks, and reminders move together.'),
                'deal' => $this->layer('Enrollment pipeline', 100, 'Beginner, refresher, test-prep, defensive, and corporate opportunities are visible.'),
                'learning' => $this->layer('Escalation loop', 100, 'Instructor complaints, refunds, and sensitive requests route to human review.'),
            ],
            'job_health' => [
                'healthy' => 14,
                'failed' => 0,
                'stale' => 0,
                'pending' => 0,
                'summary' => 'All MetroDrive presentation automation jobs are healthy.',
            ],
            'subject_user_id' => $subjectUserId,
            'snapshot_calculated_at' => $now,
            'snapshot_expires_at' => $expiresAt,
            'snapshot_source' => 'metrodrive_demo_showcase',
            'snapshot_fingerprint' => 'metrodrive-demo-showcase-v1',
            'is_stale' => false,
            'updated_label' => 'Updated just now',
            'protected_demo_showcase' => true,
        ];
    }

    private function profileKey(): string
    {
        try {
            return (new DemoWorkspaceService())->profileKey();
        } catch (\Throwable $e) {
            return 'riverside';
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function layer(string $name, int $score, string $summary): array
    {
        return [
            'name' => $name,
            'score' => $score,
            'is_complete' => true,
            'summary' => $summary,
            'completed_count' => 4,
            'total_count' => 4,
            'blockers' => [],
            'signals' => [$summary],
            'counted' => true,
        ];
    }
}
