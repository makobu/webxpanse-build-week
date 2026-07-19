<?php

namespace CRM\Services;

use CRM\Authorization;
use CRM\Database;

class OperatingAnalyticsService
{
    private AnalyticsWorkspaceService $workspace;

    public function __construct(?AnalyticsWorkspaceService $workspace = null)
    {
        $this->workspace = $workspace ?? new AnalyticsWorkspaceService();
    }

    public function normalizeTimeframe(?string $timeframe): string
    {
        return in_array($timeframe, ['today', 'week', 'month', 'year', 'all'], true) ? $timeframe : 'month';
    }

    public function resolveSubjectUserId(array $viewer, int $workspaceId, mixed $requestedUserId): ?int
    {
        $viewerUserId = (int) ($viewer['id'] ?? 0);
        $canViewAll = Authorization::can('analytics.view_all', $viewer);

        if ($canViewAll && (string) $requestedUserId === '') {
            return null;
        }

        if ($canViewAll && ctype_digit((string) $requestedUserId)) {
            return $this->workspace->ensureScopedUserId((int) $requestedUserId, $workspaceId);
        }

        return $viewerUserId > 0 ? $viewerUserId : null;
    }

    public function getOperatingSnapshot(int $workspaceId, ?int $subjectUserId, string $timeframe): array
    {
        $timeframe = $this->normalizeTimeframe($timeframe);
        $revenue = $this->closedRevenue($workspaceId, $subjectUserId, $timeframe);
        $winRate = $this->winRate($workspaceId, $subjectUserId, $timeframe);
        $responseMinutes = $this->averageResponseMinutes($workspaceId, $timeframe);
        $activeTargets = $this->countRows('targets', $workspaceId, $subjectUserId, ['user_id'], "status = 'active'");
        $automationHealth = $this->automationHealthSummary($workspaceId, $subjectUserId, $timeframe);
        $aiActions = $this->aiActionCount($workspaceId, $subjectUserId, $timeframe);
        $activeSkills = $this->countRows('workspace_skill_installs', $workspaceId, null, [], "status = 'installed'");
        $readiness = $this->workspaceReadiness($workspaceId);

        return [
            'summary' => [
                'timeframe' => $timeframe,
                'scope' => $subjectUserId === null ? 'workspace' : 'user',
            ],
            'metrics' => [
                $this->metric('revenue', 'Revenue', $this->money($revenue), 'Closed won in this period', 'success', 'fa-dollar-sign'),
                $this->metric('win_rate', 'Win Rate', $this->percent($winRate), 'Closed won vs. closed deals', 'primary', 'fa-trophy'),
                $this->metric('response_time', 'Response Time', $responseMinutes === null ? 'N/A' : $this->duration($responseMinutes), 'Average first outbound reply', 'neutral', 'fa-reply'),
                $this->metric('active_targets', 'Active Targets', $activeTargets, 'Open workspace or user targets', 'primary', 'fa-bullseye'),
                $this->metric('automation_health', 'Automation Health', $automationHealth['label'], $automationHealth['detail'], $automationHealth['tone'], 'fa-bolt'),
                $this->metric('ai_actions', 'AI Actions', $aiActions, 'Completed AI or assistant actions', 'neutral', 'fa-wand-magic-sparkles'),
                $this->metric('active_skills', 'Active Skills', $activeSkills, 'Installed workspace skills', 'neutral', 'fa-puzzle-piece'),
                $this->metric('readiness', 'Workspace Readiness', $readiness['label'], $readiness['detail'], $readiness['tone'], 'fa-gauge-high'),
            ],
            'insights' => $this->compactInsights([
                $automationHealth['insight'] ?? null,
                $readiness['insight'] ?? null,
                $activeTargets === 0 ? $this->insight('info', 'No active targets are in scope for this view.') : null,
            ]),
            'charts' => [],
        ];
    }

    public function getChannelAnalytics(int $workspaceId, ?int $subjectUserId, string $timeframe): array
    {
        $timeframe = $this->normalizeTimeframe($timeframe);
        $email = $this->emailChannelMetrics($workspaceId, $subjectUserId, $timeframe);
        $whatsapp = $this->whatsAppChannelMetrics($workspaceId, $subjectUserId, $timeframe);
        $inbox = $this->inboxMetrics($workspaceId, $timeframe);
        $campaigns = $this->countRows('campaigns', $workspaceId, null, [], null, 'created_at', $timeframe);
        $forms = $this->countRows('form_submissions', $workspaceId, null, [], null, 'created_at', $timeframe);

        $available = $email['available'] || $whatsapp['available'] || $inbox['available'] || $campaigns > 0 || $forms > 0;

        return [
            'summary' => ['timeframe' => $timeframe],
            'metrics' => [
                $this->metric('email_sent', 'Email Sent', $email['sent'], $this->percent($email['open_rate']) . ' open rate', 'primary', 'fa-envelope'),
                $this->metric('email_reply', 'Email Reply Rate', $this->percent($email['reply_rate']), $email['replies'] . ' replies tracked', 'neutral', 'fa-reply'),
                $this->metric('whatsapp_sent', 'WhatsApp Sent', $whatsapp['sent'], $whatsapp['failed'] . ' failed', $whatsapp['failed'] > 0 ? 'warning' : 'success', 'fa-brands fa-whatsapp'),
                $this->metric('inbox_attention', 'Inbox Attention', $inbox['attention'], 'Unread or inbound items needing review', $inbox['attention'] > 0 ? 'warning' : 'neutral', 'fa-inbox'),
                $this->metric('campaigns', 'Campaigns', $campaigns, 'Campaigns created in period', 'neutral', 'fa-bullhorn'),
                $this->metric('forms', 'Forms', $forms, 'Form submissions in period', 'neutral', 'fa-clipboard-list'),
            ],
            'insights' => $available
                ? $this->compactInsights([
                    $email['sent'] === 0 ? $this->insight('info', 'No email sends were found for this period.') : null,
                    $whatsapp['available'] && $whatsapp['failed'] > 0 ? $this->insight('warning', 'WhatsApp has failed sends that may need follow-up.') : null,
                    $inbox['attention'] > 0 ? $this->insight('warning', 'There are inbound or unread conversations that may need attention.') : null,
                ])
                : [$this->insight('info', 'Channel data is not available yet for this workspace.')],
            'charts' => [
                [
                    'type' => 'bar',
                    'title' => 'Channel Volume',
                    'labels' => ['Email', 'WhatsApp', 'Campaigns', 'Forms'],
                    'values' => [(int) $email['sent'], (int) $whatsapp['sent'], (int) $campaigns, (int) $forms],
                ],
            ],
        ];
    }

    public function getAIAutomationAnalytics(int $workspaceId, int $viewerUserId, ?int $subjectUserId, string $timeframe): array
    {
        $timeframe = $this->normalizeTimeframe($timeframe);
        $battery = $this->automationBattery($viewerUserId, $subjectUserId);
        $diagnostics = $this->aiDiagnostics($workspaceId, $subjectUserId, $timeframe);
        $workflowFailures = $this->countRows('workflow_executions', $workspaceId, $subjectUserId, ['user_id', 'created_by'], "status = 'failed'", 'executed_at', $timeframe);
        $workflowRetries = Database::columnExists('workflow_retry_queue', 'workspace_id')
            ? $this->countRows('workflow_retry_queue', $workspaceId, null, [], null, 'created_at', $timeframe)
            : 0;

        return [
            'summary' => ['timeframe' => $timeframe],
            'metrics' => [
                $this->metric('battery', 'Automation Battery', $battery['label'], $battery['detail'], $battery['tone'], 'fa-battery-three-quarters'),
                $this->metric('ai_completed', 'AI Completed', $diagnostics['completed'], 'Actions or events completed', 'success', 'fa-check'),
                $this->metric('ai_blocked', 'AI Blocked', $diagnostics['blocked'], 'Blocked, failed, or approval-required actions', $diagnostics['blocked'] > 0 ? 'warning' : 'neutral', 'fa-shield-halved'),
                $this->metric('workflow_failures', 'Workflow Failures', $workflowFailures, $workflowRetries . ' retries queued', $workflowFailures > 0 ? 'warning' : 'success', 'fa-triangle-exclamation'),
            ],
            'insights' => $this->compactInsights([
                $battery['insight'] ?? null,
                $diagnostics['insight'] ?? null,
                $workflowFailures > 0 ? $this->insight('warning', 'Some workflow executions failed during this period.') : null,
            ]),
            'charts' => [
                [
                    'type' => 'bar',
                    'title' => 'AI & Workflow Events',
                    'labels' => ['Completed', 'Blocked', 'Workflow failures', 'Retries'],
                    'values' => [(int) $diagnostics['completed'], (int) $diagnostics['blocked'], (int) $workflowFailures, (int) $workflowRetries],
                ],
            ],
        ];
    }

    public function getFounderJourneyAnalytics(int $workspaceId, int $userId): array
    {
        $journey = $this->safeCall(static fn() => (new StartupJourneyService())->getJourney($workspaceId, $userId), []);
        $loop = $this->safeCall(static fn() => (new FounderOperatingLoopService())->summary($workspaceId, $userId), []);
        $progress = (array) ($journey['progress'] ?? []);
        $completion = (float) ($progress['completion_percent'] ?? $progress['percent'] ?? 0);
        $stages = (array) ($journey['stages'] ?? []);
        $completedStages = 0;
        foreach ($stages as $stage) {
            if (($stage['status'] ?? '') === 'complete' || ($stage['is_complete'] ?? false)) {
                $completedStages++;
            }
        }
        $currentStage = (string) ($journey['current_stage']['label'] ?? $journey['current_stage_key'] ?? 'Not started');
        $blockedSteps = (array) ($loop['blocked_steps'] ?? []);
        $review = (array) ($loop['review'] ?? []);
        $commitments = (array) ($loop['commitments'] ?? []);

        return [
            'summary' => ['user_id' => $userId],
            'metrics' => [
                $this->metric('journey_completion', 'Journey Completion', $this->percent($completion), $currentStage, 'primary', 'fa-route'),
                $this->metric('completed_stages', 'Completed Stages', $completedStages . '/' . count($stages), 'Clarity Journey progress', 'neutral', 'fa-list-check'),
                $this->metric('founder_review', 'Founder Review', $review ? ucfirst((string) ($review['review_status'] ?? 'draft')) : 'Not started', 'Current weekly review state', $review ? 'success' : 'neutral', 'fa-rotate'),
                $this->metric('commitments', 'Weekly Commitments', count($commitments), 'Founder Loop commitments', count($commitments) > 0 ? 'success' : 'neutral', 'fa-flag'),
            ],
            'insights' => $this->compactInsights([
                !$journey ? $this->insight('info', 'Clarity Journey data is not available yet for this user.') : null,
                $blockedSteps ? $this->insight('warning', 'Founder Loop is blocked on: ' . implode(', ', array_slice($blockedSteps, 0, 3))) : null,
                !empty($loop['next_action']) ? $this->insight('info', (string) $loop['next_action']) : null,
            ]),
            'charts' => [
                [
                    'type' => 'bar',
                    'title' => 'Journey Progress',
                    'labels' => ['Complete', 'Remaining'],
                    'values' => [(int) round($completion), max(0, 100 - (int) round($completion))],
                ],
            ],
        ];
    }

    public function getTargetTaskAnalytics(int $workspaceId, ?int $subjectUserId, string $timeframe): array
    {
        $timeframe = $this->normalizeTimeframe($timeframe);
        $activeTargets = $this->countRows('targets', $workspaceId, $subjectUserId, ['user_id'], "status = 'active'");
        $atRiskTargets = $this->countRows('targets', $workspaceId, $subjectUserId, ['user_id'], "status = 'active' AND target_date < CURDATE()");
        $completedTargets = $this->countRows('targets', $workspaceId, $subjectUserId, ['user_id'], "status = 'completed'", 'completed_at', $timeframe);
        $completedTasks = $this->countRows('tasks', $workspaceId, $subjectUserId, ['assigned_to', 'created_by'], "status = 'completed'", 'completed_at', $timeframe);
        $overdueTasks = $this->countRows('tasks', $workspaceId, $subjectUserId, ['assigned_to', 'created_by'], "status IN ('pending', 'in_progress') AND due_date < NOW()");
        $aiLinkedTasks = $this->countRows('tasks', $workspaceId, $subjectUserId, ['assigned_to', 'created_by'], $this->aiLinkedTaskWhere(), 'created_at', $timeframe);

        return [
            'summary' => ['timeframe' => $timeframe],
            'metrics' => [
                $this->metric('active_targets', 'Active Targets', $activeTargets, $atRiskTargets . ' at risk or overdue', $atRiskTargets > 0 ? 'warning' : 'success', 'fa-bullseye'),
                $this->metric('completed_targets', 'Completed Targets', $completedTargets, 'Completed in this period', 'success', 'fa-flag-checkered'),
                $this->metric('completed_tasks', 'Completed Tasks', $completedTasks, 'Completed in this period', 'success', 'fa-check-double'),
                $this->metric('overdue_tasks', 'Overdue Tasks', $overdueTasks, 'Pending or in progress after due date', $overdueTasks > 0 ? 'warning' : 'neutral', 'fa-clock'),
                $this->metric('ai_tasks', 'AI-linked Tasks', $aiLinkedTasks, 'Created from AI/assistant context where tracked', 'neutral', 'fa-wand-magic-sparkles'),
            ],
            'insights' => $this->compactInsights([
                $overdueTasks > 0 ? $this->insight('warning', 'Some tasks are overdue and may be slowing execution.') : null,
                $atRiskTargets > 0 ? $this->insight('warning', 'Some active targets have passed their target date.') : null,
                ($activeTargets + $completedTasks) === 0 ? $this->insight('info', 'Targets and tasks are quiet for this selected scope.') : null,
            ]),
            'charts' => [
                [
                    'type' => 'bar',
                    'title' => 'Execution State',
                    'labels' => ['Active targets', 'At risk', 'Completed tasks', 'Overdue tasks'],
                    'values' => [(int) $activeTargets, (int) $atRiskTargets, (int) $completedTasks, (int) $overdueTasks],
                ],
            ],
        ];
    }

    public function getMarketplaceSkillAnalytics(int $workspaceId, int $userId): array
    {
        $installed = $this->safeCall(static fn() => (new WorkspaceSkillInstallService())->installedForWorkspace($workspaceId), []);
        $performance = $this->safeCall(static fn() => (new WorkspaceMarketplacePerformanceService())->buildWorkspaceSummary($workspaceId, 30), []);
        $metrics = (array) ($performance['metrics'] ?? []);
        $topModules = (array) ($performance['top_modules'] ?? []);
        $installedCount = count($installed);
        $activeWithUsage = 0;
        foreach ($topModules as $module) {
            $moduleMetrics = (array) ($module['metrics'] ?? []);
            if (((int) ($moduleMetrics['active_users'] ?? 0) + (int) ($moduleMetrics['clicks'] ?? 0) + (int) ($moduleMetrics['module_page_views'] ?? 0)) > 0) {
                $activeWithUsage++;
            }
        }

        return [
            'summary' => ['window_days' => 30],
            'metrics' => [
                $this->metric('installed_skills', 'Installed Skills', $installedCount, 'Workspace skills enabled', 'primary', 'fa-puzzle-piece'),
                $this->metric('active_skills', 'Active With Usage', $activeWithUsage, 'Top modules with usage signals', 'success', 'fa-chart-simple'),
                $this->metric('recommendation_impressions', 'Recommendation Impressions', (int) ($metrics['impressions'] ?? 0), (int) ($metrics['clicks'] ?? 0) . ' clicks', 'neutral', 'fa-eye'),
                $this->metric('installs', 'Installs', (int) ($metrics['installs'] ?? 0), 'Installs in the last 30 days', 'neutral', 'fa-download'),
                $this->metric('setup_completion', 'Setup Activity', (int) ($metrics['setup_saves'] ?? 0), (int) ($metrics['setup_opens'] ?? 0) . ' setup opens', 'neutral', 'fa-sliders'),
            ],
            'insights' => $this->compactInsights([
                $installedCount === 0 ? $this->insight('info', 'No workspace skills are installed yet.') : null,
                ((int) ($metrics['impressions'] ?? 0) > 0 && (float) ($metrics['click_through_rate'] ?? 0) === 0.0)
                    ? $this->insight('warning', 'Marketplace recommendations are being seen but not clicked.')
                    : null,
            ]),
            'charts' => [
                [
                    'type' => 'bar',
                    'title' => 'Marketplace Signals',
                    'labels' => ['Impressions', 'Clicks', 'Installs', 'Setup saves'],
                    'values' => [
                        (int) ($metrics['impressions'] ?? 0),
                        (int) ($metrics['clicks'] ?? 0),
                        (int) ($metrics['installs'] ?? 0),
                        (int) ($metrics['setup_saves'] ?? 0),
                    ],
                ],
            ],
        ];
    }

    public function getOperationsHealthAnalytics(int $workspaceId, string $timeframe): array
    {
        $timeframe = $this->normalizeTimeframe($timeframe);
        $readiness = $this->workspaceReadiness($workspaceId);
        $wallet = $this->safeCall(static fn() => (new WorkspaceWalletService())->getSummary($workspaceId), []);
        $workflowFailures = $this->countRows('workflow_executions', $workspaceId, null, [], "status = 'failed'", 'executed_at', $timeframe);
        $queueFailures = $this->countRows('workflow_queue', $workspaceId, null, [], "status = 'failed'", 'created_at', $timeframe);
        $integrationWarnings = $this->integrationWarnings($workspaceId);
        $walletWarning = $this->walletWarning($wallet);

        return [
            'summary' => ['timeframe' => $timeframe],
            'metrics' => [
                $this->metric('readiness', 'Workspace Readiness', $readiness['label'], $readiness['detail'], $readiness['tone'], 'fa-gauge-high'),
                $this->metric('integrations', 'Integration Warnings', $integrationWarnings, 'Connection warnings detected', $integrationWarnings > 0 ? 'warning' : 'success', 'fa-plug'),
                $this->metric('workflow_failures', 'Workflow Failures', $workflowFailures + $queueFailures, 'Execution and queue failures', ($workflowFailures + $queueFailures) > 0 ? 'warning' : 'success', 'fa-triangle-exclamation'),
                $this->metric('wallet', 'Wallet / Tokens', $walletWarning['label'], $walletWarning['detail'], $walletWarning['tone'], 'fa-wallet'),
            ],
            'insights' => $this->compactInsights([
                $readiness['insight'] ?? null,
                $integrationWarnings > 0 ? $this->insight('warning', 'Some workspace integrations may need attention.') : null,
                $walletWarning['insight'] ?? null,
            ]),
            'charts' => [
                [
                    'type' => 'bar',
                    'title' => 'Operations Warnings',
                    'labels' => ['Integrations', 'Workflow failures', 'Queue failures'],
                    'values' => [(int) $integrationWarnings, (int) $workflowFailures, (int) $queueFailures],
                ],
            ],
        ];
    }

    private function emailChannelMetrics(int $workspaceId, ?int $subjectUserId, string $timeframe): array
    {
        if (!Database::tableExists('emails')) {
            return ['available' => false, 'sent' => 0, 'opened' => 0, 'clicked' => 0, 'replies' => 0, 'open_rate' => 0.0, 'reply_rate' => 0.0];
        }

        [$where, $params] = $this->conditions('emails', $workspaceId, $subjectUserId, ['user_id'], 'created_at', $timeframe);
        $row = Database::queryOne(
            "SELECT COUNT(*) AS sent,
                    SUM(CASE WHEN status IN ('opened', 'clicked') OR opened_at IS NOT NULL THEN 1 ELSE 0 END) AS opened,
                    SUM(CASE WHEN status = 'clicked' OR clicked_at IS NOT NULL THEN 1 ELSE 0 END) AS clicked
             FROM emails
             WHERE {$where}",
            $params
        ) ?: [];
        $sent = (int) ($row['sent'] ?? 0);
        $opened = (int) ($row['opened'] ?? 0);
        $replies = $this->countRows('communications', $workspaceId, null, [], "channel = 'email' AND direction = 'inbound'", 'created_at', $timeframe);

        return [
            'available' => true,
            'sent' => $sent,
            'opened' => $opened,
            'clicked' => (int) ($row['clicked'] ?? 0),
            'replies' => $replies,
            'open_rate' => $sent > 0 ? round(($opened / $sent) * 100, 1) : 0.0,
            'reply_rate' => $sent > 0 ? round(($replies / $sent) * 100, 1) : 0.0,
        ];
    }

    private function whatsAppChannelMetrics(int $workspaceId, ?int $subjectUserId, string $timeframe): array
    {
        if (!Database::tableExists('whatsapp_messages')) {
            return ['available' => false, 'sent' => 0, 'replied' => 0, 'failed' => 0];
        }

        [$where, $params] = $this->conditions('whatsapp_messages', $workspaceId, $subjectUserId, ['user_id'], 'created_at', $timeframe);
        $row = Database::queryOne(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN direction = 'outbound' THEN 1 ELSE 0 END) AS sent,
                    SUM(CASE WHEN direction = 'inbound' THEN 1 ELSE 0 END) AS replied,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed
             FROM whatsapp_messages
             WHERE {$where}",
            $params
        ) ?: [];

        return [
            'available' => true,
            'sent' => (int) ($row['sent'] ?? 0),
            'replied' => (int) ($row['replied'] ?? 0),
            'failed' => (int) ($row['failed'] ?? 0),
        ];
    }

    private function inboxMetrics(int $workspaceId, string $timeframe): array
    {
        if (!Database::tableExists('communications')) {
            return ['available' => false, 'attention' => 0];
        }

        $where = "direction = 'inbound'";
        if (Database::columnExists('communications', 'read_at')) {
            $where .= ' AND read_at IS NULL';
        }

        return [
            'available' => true,
            'attention' => $this->countRows('communications', $workspaceId, null, [], $where, 'created_at', $timeframe),
        ];
    }

    private function closedRevenue(int $workspaceId, ?int $subjectUserId, string $timeframe): float
    {
        if (!Database::tableExists('deals')) {
            return 0.0;
        }

        [$where, $params] = $this->conditions('deals', $workspaceId, $subjectUserId, ['assigned_to', 'created_by'], 'actual_close_date', $timeframe);
        $where .= " AND stage = 'closed_won'";
        $row = Database::queryOne("SELECT COALESCE(SUM(value), 0) AS total FROM deals WHERE {$where}", $params);

        return (float) ($row['total'] ?? 0);
    }

    private function winRate(int $workspaceId, ?int $subjectUserId, string $timeframe): float
    {
        if (!Database::tableExists('deals')) {
            return 0.0;
        }

        [$where, $params] = $this->conditions('deals', $workspaceId, $subjectUserId, ['assigned_to', 'created_by'], 'actual_close_date', $timeframe);
        $where .= " AND stage IN ('closed_won', 'closed_lost')";
        $row = Database::queryOne(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN stage = 'closed_won' THEN 1 ELSE 0 END) AS won
             FROM deals
             WHERE {$where}",
            $params
        ) ?: [];
        $total = (int) ($row['total'] ?? 0);

        return $total > 0 ? round(((int) ($row['won'] ?? 0) / $total) * 100, 1) : 0.0;
    }

    private function averageResponseMinutes(int $workspaceId, string $timeframe): ?int
    {
        if (!Database::tableExists('communications') || !Database::columnExists('communications', 'contact_id')) {
            return null;
        }

        [$where, $params] = $this->conditions('communications', $workspaceId, null, [], 'created_at', $timeframe);
        $outboundScope = Database::columnExists('communications', 'workspace_id') ? ' AND o.workspace_id = i.workspace_id' : '';
        $row = Database::queryOne(
            "SELECT AVG(TIMESTAMPDIFF(MINUTE, i.created_at, (
                    SELECT MIN(o.created_at)
                    FROM communications o
                    WHERE o.contact_id = i.contact_id
                      AND o.direction = 'outbound'
                      AND o.created_at > i.created_at
                      {$outboundScope}
                ))) AS avg_minutes
             FROM communications i
             WHERE {$where}
               AND i.direction = 'inbound'",
            $params
        );

        return isset($row['avg_minutes']) ? (int) round((float) $row['avg_minutes']) : null;
    }

    private function automationHealthSummary(int $workspaceId, ?int $subjectUserId, string $timeframe): array
    {
        $failures = $this->countRows('workflow_executions', $workspaceId, $subjectUserId, ['user_id', 'created_by'], "status = 'failed'", 'executed_at', $timeframe);
        $completed = $this->countRows('workflow_executions', $workspaceId, $subjectUserId, ['user_id', 'created_by'], "status = 'completed'", 'executed_at', $timeframe);

        if ($failures > 0) {
            return [
                'label' => 'Needs review',
                'detail' => $failures . ' workflow failures',
                'tone' => 'warning',
                'insight' => $this->insight('warning', 'Automation has failures that should be reviewed.'),
            ];
        }

        if ($completed > 0) {
            return ['label' => 'Healthy', 'detail' => $completed . ' completed runs', 'tone' => 'success'];
        }

        return ['label' => 'Quiet', 'detail' => 'No workflow runs in scope', 'tone' => 'neutral'];
    }

    private function automationBattery(int $viewerUserId, ?int $subjectUserId): array
    {
        $status = $this->safeCall(static fn() => (new AutomationBatteryService())->getStatus($viewerUserId, $subjectUserId), []);
        $score = (int) ($status['score'] ?? $status['battery_score'] ?? 0);
        $label = (string) ($status['status_label'] ?? $status['label'] ?? ($score > 0 ? $score . '%' : 'Unavailable'));
        $detail = $score > 0 ? $score . '% readiness' : 'Diagnostics are not available';
        $tone = $score >= 80 ? 'success' : ($score >= 50 ? 'warning' : 'neutral');

        return [
            'label' => $label,
            'detail' => $detail,
            'tone' => $tone,
            'insight' => !empty($status['headline'] ?? '') ? $this->insight('info', (string) $status['headline']) : null,
        ];
    }

    private function aiDiagnostics(int $workspaceId, ?int $subjectUserId, string $timeframe): array
    {
        $filters = [
            'workspace_id' => $workspaceId,
            'user_id' => $subjectUserId,
            'timeframe' => $timeframe,
        ];
        $summary = $this->safeCall(static fn() => (new AIAutomationDiagnosticsService())->getSummary($filters), []);

        $completed = (int) ($summary['completed'] ?? $summary['actions_completed'] ?? $summary['total_completed'] ?? 0);
        $blocked = (int) ($summary['blocked'] ?? 0)
            + (int) ($summary['approval_required'] ?? 0)
            + (int) ($summary['failed'] ?? 0);

        if ($completed === 0) {
            $completed = $this->aiActionCount($workspaceId, $subjectUserId, $timeframe);
        }

        return [
            'completed' => $completed,
            'blocked' => $blocked,
            'insight' => empty($summary) ? $this->insight('info', 'AI automation diagnostics are not fully available yet.') : null,
        ];
    }

    private function aiActionCount(int $workspaceId, ?int $subjectUserId, string $timeframe): int
    {
        $total = 0;
        if (Database::tableExists('ai_guidance_runs')) {
            $extra = Database::columnExists('ai_guidance_runs', 'decision')
                ? "decision IN ('allow', 'allow_with_warning', 'suggest_only')"
                : null;
            $total += $this->countRows('ai_guidance_runs', $workspaceId, $subjectUserId, ['user_id'], $extra, 'created_at', $timeframe);
        }
        if (Database::tableExists('ai_advice_feedback')) {
            $total += $this->countRows('ai_advice_feedback', $workspaceId, $subjectUserId, ['user_id'], null, 'created_at', $timeframe);
        }
        if (Database::tableExists('workflow_automation_proposals')) {
            $extra = Database::columnExists('workflow_automation_proposals', 'status')
                ? "status IN ('approved', 'applied')"
                : null;
            $total += $this->countRows('workflow_automation_proposals', $workspaceId, $subjectUserId, ['created_by', 'user_id', 'requested_by_id'], $extra, 'created_at', $timeframe);
        }
        if (Database::tableExists('email_assistant_actions')) {
            $extra = Database::columnExists('email_assistant_actions', 'status')
                ? "status IN ('completed', 'sent', 'applied')"
                : null;
            $total += $this->countRows('email_assistant_actions', $workspaceId, $subjectUserId, ['user_id'], $extra, 'created_at', $timeframe);
        }

        return $total;
    }

    private function workspaceReadiness(int $workspaceId): array
    {
        $status = $this->safeCall(static fn() => (new WorkspaceLaunchReadinessService())->getWorkspaceLaunchStatus($workspaceId), []);
        $score = (float) ($status['score'] ?? $status['completion_percent'] ?? $status['readiness_percent'] ?? 0);
        $label = $score > 0 ? $this->percent($score) : 'Not scored';
        $detail = (string) ($status['label'] ?? $status['status_label'] ?? 'Workspace launch readiness');
        $tone = $score >= 80 ? 'success' : ($score >= 50 ? 'warning' : 'neutral');

        return [
            'label' => $label,
            'detail' => $detail,
            'tone' => $tone,
            'insight' => empty($status) ? $this->insight('info', 'Workspace readiness is not configured yet.') : null,
        ];
    }

    private function integrationWarnings(int $workspaceId): int
    {
        $warnings = 0;
        foreach (['email_integrations', 'workspace_whatsapp_integrations', 'calendar_integrations'] as $table) {
            if (!Database::tableExists($table)) {
                continue;
            }
            $statusColumn = Database::columnExists($table, 'status') ? 'status' : (Database::columnExists($table, 'connection_status') ? 'connection_status' : null);
            if ($statusColumn === null) {
                continue;
            }
            $warnings += $this->countRows($table, $workspaceId, null, [], "{$statusColumn} IN ('failed', 'error', 'disconnected')");
        }

        return $warnings;
    }

    private function walletWarning(array $wallet): array
    {
        if (!$wallet) {
            return ['label' => 'N/A', 'detail' => 'Wallet summary unavailable', 'tone' => 'neutral'];
        }

        $balance = (float) ($wallet['token_balance'] ?? $wallet['balance'] ?? $wallet['remaining_tokens'] ?? 0);
        if ($balance <= 0) {
            return [
                'label' => 'Needs top-up',
                'detail' => 'No AI Credit balance detected',
                'tone' => 'warning',
                'insight' => $this->insight('warning', 'Workspace wallet or AI Credit balance may need attention.'),
            ];
        }

        return ['label' => 'OK', 'detail' => number_format($balance) . ' AI Credits available', 'tone' => 'success'];
    }

    private function aiLinkedTaskWhere(): ?string
    {
        if (!Database::tableExists('tasks')) {
            return null;
        }

        if (Database::columnExists('tasks', 'source')) {
            return "source LIKE '%ai%'";
        }

        if (Database::columnExists('tasks', 'metadata_json')) {
            return "JSON_EXTRACT(metadata_json, '$.ai') IS NOT NULL";
        }

        return '1 = 0';
    }

    private function countRows(
        string $table,
        int $workspaceId,
        ?int $subjectUserId = null,
        array $userColumns = [],
        ?string $extraWhere = null,
        string $dateColumn = 'created_at',
        string $timeframe = 'all'
    ): int {
        if (!Database::tableExists($table)) {
            return 0;
        }

        [$where, $params] = $this->conditions($table, $workspaceId, $subjectUserId, $userColumns, $dateColumn, $timeframe);
        if ($extraWhere !== null && trim($extraWhere) !== '') {
            $where .= ' AND (' . $extraWhere . ')';
        }

        $row = Database::queryOne("SELECT COUNT(*) AS total FROM {$table} WHERE {$where}", $params);
        return (int) ($row['total'] ?? 0);
    }

    private function conditions(
        string $table,
        int $workspaceId,
        ?int $subjectUserId,
        array $userColumns,
        string $dateColumn,
        string $timeframe
    ): array {
        $where = ['1 = 1'];
        $params = [];

        if (Database::columnExists($table, 'workspace_id')) {
            $where[] = 'workspace_id = ?';
            $params[] = $workspaceId;
        } elseif ($subjectUserId === null && $userColumns) {
            foreach ($userColumns as $column) {
                if (!Database::columnExists($table, $column)) {
                    continue;
                }
                $where[] = "EXISTS (
                    SELECT 1
                    FROM workspace_memberships wm
                    WHERE wm.workspace_id = ?
                      AND wm.user_id = {$column}
                      AND wm.membership_status = 'active'
                )";
                $params[] = $workspaceId;
                break;
            }
        }

        if ($subjectUserId !== null && $userColumns) {
            $userWhere = [];
            foreach ($userColumns as $column) {
                if (Database::columnExists($table, $column)) {
                    $userWhere[] = "{$column} = ?";
                    $params[] = $subjectUserId;
                }
            }
            if ($userWhere) {
                $where[] = '(' . implode(' OR ', $userWhere) . ')';
            }
        }

        $date = $this->dateCondition($table, $dateColumn, $timeframe);
        if ($date !== null) {
            $where[] = $date['sql'];
            $params[] = $date['value'];
        }

        return [implode(' AND ', $where), $params];
    }

    private function dateCondition(string $table, string $column, string $timeframe): ?array
    {
        if ($timeframe === 'all' || !Database::columnExists($table, $column)) {
            return null;
        }

        $value = match ($timeframe) {
            'today' => date('Y-m-d 00:00:00'),
            'week' => date('Y-m-d H:i:s', strtotime('-7 days')),
            'year' => date('Y-m-d H:i:s', strtotime('-365 days')),
            default => date('Y-m-d H:i:s', strtotime('-30 days')),
        };

        return ['sql' => "{$column} >= ?", 'value' => $value];
    }

    private function metric(string $key, string $label, int|float|string $value, string $detail = '', string $tone = 'neutral', string $icon = 'fa-circle'): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'value' => (string) $value,
            'detail' => $detail,
            'tone' => $tone,
            'icon' => $icon,
        ];
    }

    private function insight(string $tone, string $message): array
    {
        return ['tone' => $tone, 'message' => $message];
    }

    private function compactInsights(array $insights): array
    {
        return array_values(array_filter($insights, static fn($insight): bool => is_array($insight) && !empty($insight['message'])));
    }

    private function safeCall(callable $callback, mixed $fallback): mixed
    {
        try {
            return $callback();
        } catch (\Throwable $e) {
            return $fallback;
        }
    }

    private function money(float $amount): string
    {
        return '$' . number_format($amount, 0);
    }

    private function percent(float $value): string
    {
        return number_format($value, 1) . '%';
    }

    private function duration(int $minutes): string
    {
        if ($minutes < 60) {
            return $minutes . 'm';
        }

        return number_format($minutes / 60, 1) . 'h';
    }
}
