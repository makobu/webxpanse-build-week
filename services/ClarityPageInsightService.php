<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\Tasks;

class ClarityPageInsightService
{
    private const MARKETPLACE_RECENCY_DAYS = 14;

    private WorkspaceSkillCatalogService $catalog;
    private WorkspaceSkillInstallService $installer;
    private WorkspaceMarketplaceRecommendationService $recommendations;
    private WorkspaceMarketplaceActivationBundleService $activationBundles;
    private WorkspaceMarketplaceNextActionService $marketplaceNextActions;

    public function __construct(
        ?WorkspaceSkillCatalogService $catalog = null,
        ?WorkspaceSkillInstallService $installer = null,
        ?WorkspaceMarketplaceRecommendationService $recommendations = null,
        ?WorkspaceMarketplaceActivationBundleService $activationBundles = null,
        ?WorkspaceMarketplaceNextActionService $marketplaceNextActions = null
    ) {
        $this->catalog = $catalog ?? new WorkspaceSkillCatalogService();
        $this->installer = $installer ?? new WorkspaceSkillInstallService($this->catalog);
        $this->recommendations = $recommendations ?? new WorkspaceMarketplaceRecommendationService($this->catalog, $this->installer);
        $this->activationBundles = $activationBundles ?? new WorkspaceMarketplaceActivationBundleService($this->catalog, $this->installer, $this->recommendations);
        $this->marketplaceNextActions = $marketplaceNextActions ?? new WorkspaceMarketplaceNextActionService($this->catalog, $this->installer, null, $this->recommendations);
    }

    public function openingInsight(int $workspaceId, int $userId, string $currentPage, bool $recordSideEffects = true): array
    {
        $page = $this->normalizePage($currentPage);
        $marketingContext = MarketingPageContextService::contextForPage($page);
        if ($marketingContext !== null) {
            return $this->marketingPageInsight($page, $marketingContext);
        }

        if ($workspaceId <= 0 || $userId <= 0) {
            return $this->generalInsight($page);
        }

        return match ($page) {
            'dashboard.php' => $this->dashboardInsight($workspaceId, $userId),
            'contacts.php', 'leads.php' => $this->contactInsight($workspaceId, $userId),
            'deals.php' => $this->dealInsight($workspaceId, $userId),
            'tasks.php' => $this->taskInsight($workspaceId, $userId),
            'workspace_skills.php' => $this->marketplaceInsight($workspaceId, $userId, $recordSideEffects),
            'settings.php' => $this->settingsInsight($workspaceId, $userId),
            default => $this->generalInsight($page),
        };
    }

    private function marketingPageInsight(string $page, array $context): array
    {
        $insight = [
            'kind' => 'marketing_page_context',
            'title' => (string) ($context['founder_job'] ?? 'Move Marketing Forward'),
            'body' => (string) ($context['plain_english_purpose'] ?? 'This Marketing page supports the guided founder path.'),
            'bullets' => array_values(array_filter([
                'Stage: ' . $this->labelize((string) ($context['stage_key'] ?? 'marketing')),
                'Likely blocker: ' . (string) ($context['current_likely_blocker'] ?? 'The next step is not clear yet.'),
                'Next move: ' . (string) ($context['one_next_step'] ?? 'Choose the next useful marketing action.'),
                $this->marketingRecommendationBullet($context),
            ])),
            'source' => 'clarity_page_insight',
            'page' => $page,
            'marketing_page_context' => $context,
        ];

        return $this->withOptionalCta($insight, 'Open Marketing Path', 'marketing.php', 'marketing_founder_path');
    }

    private function marketingRecommendationBullet(array $context): string
    {
        $recommendationContext = (array) ($context['clarity_recommendation_context'] ?? []);
        $summary = trim((string) ($recommendationContext['summary'] ?? ''));

        return $summary !== '' ? 'Clarity recommendation role: ' . $summary : '';
    }

    private function pageProfile(string $page): array
    {
        $profiles = [
            'dashboard.php' => [
                'purpose' => 'system health and business focus',
                'entity_focus' => ['setup', 'skills', 'critical_customer_risk', 'tasks', 'deals', 'leads'],
                'empty_behavior' => 'confidence-building focus guidance',
                'cta_policy' => 'critical_cross_page_only',
                'preferred_kinds' => ['dashboard_setup_advice', 'dashboard_skill_setup_advice', 'dashboard_task_advice'],
            ],
            'contacts.php' => [
                'purpose' => 'contact usefulness and lead quality',
                'entity_focus' => ['contacts', 'leads', 'ownership', 'relationship_activity'],
                'empty_behavior' => 'import or add enough real contacts for useful guidance',
                'cta_policy' => 'no_current_page_links',
                'preferred_kinds' => ['contacts_empty_guidance', 'contacts_data_quality_advice', 'contacts_lead_momentum_advice'],
            ],
            'leads.php' => [
                'purpose' => 'lead usefulness and follow-up quality',
                'entity_focus' => ['leads', 'ownership', 'timing', 'relationship_activity'],
                'empty_behavior' => 'capture real lead data before scoring or follow-up advice',
                'cta_policy' => 'no_current_page_links',
                'preferred_kinds' => ['contacts_empty_guidance', 'contacts_data_quality_advice', 'contacts_lead_momentum_advice'],
            ],
            'deals.php' => [
                'purpose' => 'pipeline usefulness and close confidence',
                'entity_focus' => ['deals', 'close_dates', 'owners', 'next_steps'],
                'empty_behavior' => 'create real opportunities with value, stage, owner, and close date',
                'cta_policy' => 'no_current_page_links',
                'preferred_kinds' => ['deals_empty_guidance', 'deals_data_quality_advice', 'deals_momentum_advice'],
            ],
            'tasks.php' => [
                'purpose' => 'task hygiene and completion clarity',
                'entity_focus' => ['tasks', 'due_dates', 'owners', 'outcomes'],
                'empty_behavior' => 'create tasks from real setup, follow-up, or customer commitments',
                'cta_policy' => 'critical_customer_risk_only',
                'preferred_kinds' => ['tasks_empty_guidance', 'tasks_hygiene_advice', 'tasks_focus_advice'],
            ],
            'workspace_skills.php' => [
                'purpose' => 'Marketplace fit and installed skill context',
                'entity_focus' => ['plugins', 'installed_skills', 'setup_readiness'],
                'empty_behavior' => 'show one best-fit Marketplace action when available',
                'cta_policy' => 'marketplace_allowed',
                'preferred_kinds' => ['marketplace_plugin'],
            ],
            'settings.php' => [
                'purpose' => 'setup confidence and system readiness',
                'entity_focus' => ['workspace_setup', 'skill_setup', 'automation_readiness'],
                'empty_behavior' => 'explain the strongest setup confidence blocker',
                'cta_policy' => 'critical_cross_page_only',
                'preferred_kinds' => ['settings_setup_advice', 'dashboard_skill_setup_advice'],
            ],
        ];

        return $profiles[$page] ?? [
            'purpose' => 'workspace context',
            'entity_focus' => ['current_page', 'missing_data', 'next_action'],
            'empty_behavior' => 'make the current page more useful',
            'cta_policy' => 'none_by_default',
            'preferred_kinds' => ['general'],
        ];
    }

    private function dashboardInsight(int $workspaceId, int $userId): array
    {
        $criticalAdvice = $this->dashboardCriticalCustomerRiskAdvice($workspaceId, $userId);
        if ($criticalAdvice !== null) {
            return $criticalAdvice;
        }

        $setupAdvice = $this->dashboardSetupAdvice($workspaceId, $userId);
        if ($setupAdvice !== null) {
            return $setupAdvice;
        }

        $skillSetupAdvice = $this->dashboardSkillSetupAdvice($workspaceId, $userId);
        if ($skillSetupAdvice !== null) {
            return $skillSetupAdvice;
        }

        $taskAdvice = $this->dashboardTaskAdvice($workspaceId, $userId);
        if ($taskAdvice !== null) {
            return $taskAdvice;
        }

        $issues = [];

        $overdueTasks = $this->countQuery(
            "SELECT COUNT(*) AS c FROM tasks
             WHERE workspace_id = ?
               AND (assigned_to = ? OR created_by = ?)
               AND status NOT IN ('completed', 'cancelled')
               AND due_date IS NOT NULL
               AND due_date < NOW()",
            [$workspaceId, $userId, $userId]
        );
        if ($overdueTasks > 0) {
            $issues[] = $overdueTasks . ' overdue task' . ($overdueTasks === 1 ? '' : 's') . ' may be blocking today\'s revenue work.';
        }

        $staleDeals = $this->countQuery(
            "SELECT COUNT(*) AS c FROM deals
             WHERE workspace_id = ?
               AND stage NOT IN ('closed_won', 'closed_lost')
               AND COALESCE(updated_at, created_at) < DATE_SUB(NOW(), INTERVAL 7 DAY)",
            [$workspaceId]
        );
        if ($staleDeals > 0) {
            $issues[] = $staleDeals . ' active deal' . ($staleDeals === 1 ? '' : 's') . ' have gone quiet for at least a week.';
        }

        $staleLeads = $this->countQuery(
            "SELECT COUNT(*) AS c FROM contacts
             WHERE workspace_id = ?
               AND stage IN ('new', 'contacted', 'qualified')
               AND COALESCE(updated_at, created_at) < DATE_SUB(NOW(), INTERVAL 7 DAY)",
            [$workspaceId]
        );
        if ($staleLeads > 0) {
            $issues[] = $staleLeads . ' lead' . ($staleLeads === 1 ? '' : 's') . ' need a fresh next step before they cool off.';
        }

        foreach ($this->installedSetupGaps($workspaceId, $userId) as $gap) {
            $issues[] = $gap;
        }

        $marketplaceCandidate = $this->marketplaceCandidate($workspaceId, $userId);
        if ($marketplaceCandidate !== null && $this->marketplaceIsStale($workspaceId, $userId)) {
            $issues[] = 'You have not visited the Marketplace recently, and ' . $marketplaceCandidate['label'] . ' now looks relevant.';
        }

        if ($issues === []) {
            return [
                'kind' => 'dashboard_issue',
                'title' => 'Choose One Clean Next Move',
                'body' => 'There is no single urgent signal to push first, so the best move is to strengthen the workspace where it feels least clear.',
                'bullets' => [
                    'Look for one setup, lead, or deal detail that would make tomorrow easier to decide.',
                    'Use Clarity to compare two options if the next move is not obvious.',
                    'The goal is confidence: one completed improvement beats another broad status review.',
                ],
                'source' => 'clarity_page_insight',
            ];
        }

        $insight = [
            'kind' => 'dashboard_issue',
            'title' => 'Make The Dashboard More Trustworthy',
            'body' => 'The strongest dashboard signal is not a counter; it is the workspace area that would make Clarity more confident after one focused action.',
            'bullets' => array_slice($issues, 0, 3),
            'source' => 'clarity_page_insight',
        ];

        if ($marketplaceCandidate !== null && count($issues) === 1 && $this->shouldLinkForInsight((string) ($marketplaceCandidate['kind'] ?? ''), 'dashboard.php', ['marketplace_item' => true])) {
            $insight = $this->withOptionalCta(
                $insight,
                (string) ($marketplaceCandidate['cta_label'] ?? ''),
                (string) ($marketplaceCandidate['cta_url'] ?? ''),
                'marketplace_recommendation'
            );
        }

        return $insight;
    }

    private function dashboardSetupAdvice(int $workspaceId, int $userId): ?array
    {
        try {
            $summary = (new WorkspaceLaunchChecklistService())->summary($workspaceId);
        } catch (\Throwable $e) {
            return null;
        }

        $items = array_values(array_filter(
            (array) ($summary['items'] ?? []),
            static fn(array $item): bool => empty($item['complete'])
        ));
        if ($items === []) {
            return null;
        }

        usort($items, static function (array $left, array $right): int {
            $priority = ['high' => 1, 'medium' => 2, 'low' => 3];
            $leftPriority = $priority[(string) ($left['priority'] ?? 'medium')] ?? 2;
            $rightPriority = $priority[(string) ($right['priority'] ?? 'medium')] ?? 2;
            return $leftPriority <=> $rightPriority;
        });

        $item = $items[0];
        $title = trim((string) ($item['title'] ?? 'Finish workspace setup'));
        $description = trim((string) ($item['description'] ?? ''));
        $url = trim((string) ($item['url'] ?? 'dashboard.php'));
        $taskId = (int) ($item['task_id'] ?? 0);

        $insight = [
            'kind' => 'dashboard_setup_advice',
            'title' => $title !== '' ? $title : 'Finish workspace setup',
            'body' => $description !== ''
                ? $description
                : 'This setup step gives Clarity better context before revenue and lead advice take over.',
            'bullets' => array_values(array_filter([
                'Setup comes first here because the workspace still needs a stronger operating foundation.',
                'Next move: complete this setup step, then return to the dashboard for sharper task and revenue guidance.',
                'After this, Clarity can give more confident advice using cleaner customer, workflow, and skill context.',
            ])),
            'source' => 'clarity_page_insight',
            'setup_key' => (string) ($item['key'] ?? ''),
            'task_id' => $taskId,
        ];

        if ($taskId <= 0 && $this->shouldLinkForInsight('dashboard_setup_advice', 'dashboard.php', [
            'url' => $url,
            'critical_cross_page' => $this->normalizePage($url) !== 'dashboard.php',
        ])) {
            $insight = $this->withOptionalCta($insight, 'Open Setup', $this->withQuery($url, ['source' => 'clarity_chat']), 'critical_setup');
        }

        return $insight;
    }

    private function dashboardSkillSetupAdvice(int $workspaceId, int $userId): ?array
    {
        try {
            $context = $this->installer->buildContextForWorkspace($workspaceId, $userId);
        } catch (\Throwable $e) {
            return null;
        }

        $installedByKey = [];
        foreach ((array) ($context['installed'] ?? []) as $module) {
            $key = (string) ($module['key'] ?? '');
            if ($key !== '') {
                $installedByKey[$key] = $module;
            }
        }

        $candidates = [];
        foreach ((array) ($context['readiness'] ?? []) as $skillKey => $readiness) {
            if (!is_array($readiness) || !empty($readiness['ready'])) {
                continue;
            }
            $key = (string) $skillKey;
            $module = (array) ($installedByKey[$key] ?? []);
            $label = (string) ($module['label'] ?? $readiness['label'] ?? ucwords(str_replace('_', ' ', $key)));
            $setupUrl = (string) (
                $context['context'][$key]['setup_url']
                ?? $module['navigation']['url']
                ?? $module['plugin_metadata']['setup_url']
                ?? ('workspace_skills.php?module=' . $key)
            );
            $blocker = (string) (($readiness['blockers'][0] ?? '') ?: ($readiness['message'] ?? ''));
            $nextAction = (string) ($readiness['next_action'] ?? 'Complete the required setup fields.');
            $candidates[] = [
                'key' => $key,
                'label' => $label,
                'setup_url' => $setupUrl,
                'blocker' => $blocker,
                'next_action' => $nextAction,
                'score' => $this->skillSetupScore($key),
            ];
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, static function (array $left, array $right): int {
            $score = ((int) ($right['score'] ?? 0)) <=> ((int) ($left['score'] ?? 0));
            if ($score !== 0) {
                return $score;
            }
            return strcmp((string) ($left['label'] ?? ''), (string) ($right['label'] ?? ''));
        });

        $candidate = $candidates[0];
        $label = (string) ($candidate['label'] ?? 'Installed skill');
        $blocker = trim((string) ($candidate['blocker'] ?? ''));
        $nextAction = trim((string) ($candidate['next_action'] ?? 'Complete the required setup fields.'));

        return [
            'kind' => 'dashboard_skill_setup_advice',
            'title' => 'Finish Setting Up ' . $label,
            'body' => $label . ' is installed, but Clarity needs its setup finished before it can use that context confidently.',
            'bullets' => array_values(array_filter([
                $blocker !== '' ? 'Blocking confidence: ' . $blocker : 'Blocking confidence: the installed skill is not ready for dependable guidance yet.',
                'Next move: ' . $nextAction,
                'After this, Clarity can use ' . $label . ' as part of its system-guide and business-advisor context.',
            ])),
            'cta_label' => 'Open Setup',
            'cta_url' => $this->withQuery((string) ($candidate['setup_url'] ?? 'workspace_skills.php'), ['source' => 'clarity_chat']),
            'source' => 'clarity_page_insight',
            'skill_key' => (string) ($candidate['key'] ?? ''),
        ];
    }

    private function skillSetupScore(string $skillKey): int
    {
        return match ($skillKey) {
            WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT,
            WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT => 100,
            WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL,
            WorkspaceSkillCatalogService::PLUGIN_CALENDAR_MEETINGS => 90,
            WorkspaceSkillCatalogService::SKILL_AI_COACH => 80,
            WorkspaceSkillCatalogService::PLUGIN_MARKETING_PRO,
            WorkspaceSkillCatalogService::SKILL_PROFESSIONAL_MARKETER,
            WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS => 70,
            default => 50,
        };
    }

    private function dashboardCriticalCustomerRiskAdvice(int $workspaceId, int $userId): ?array
    {
        if (!Database::tableExists('tasks')) {
            return null;
        }

        $ranked = [];
        foreach ($this->dashboardTaskCandidates($workspaceId, $userId) as $task) {
            $metadata = $this->decodeJson($task['metadata_json'] ?? null);
            $deal = $this->linkedDealForTask($workspaceId, $metadata);
            $notifications = $this->linkedNotificationsForTask($workspaceId, $userId, $task, $metadata);
            if (!$this->isCriticalCustomerRiskTask($task, $metadata, $deal, $notifications)) {
                continue;
            }
            $ranked[] = [
                'task' => $task,
                'metadata' => $metadata,
                'deal' => $deal,
                'notifications' => $notifications,
                'score' => $this->scoreDashboardTask($task, $metadata, $deal, $notifications) + 500,
            ];
        }

        if ($ranked === []) {
            return null;
        }

        usort($ranked, static fn(array $left, array $right): int => ((int) ($right['score'] ?? 0)) <=> ((int) ($left['score'] ?? 0)));
        $winner = $ranked[0];

        return $this->buildDashboardTaskAdvice(
            (array) $winner['task'],
            (array) $winner['metadata'],
            (array) $winner['deal'],
            (array) $winner['notifications'],
            (int) $winner['score'],
            true
        );
    }

    private function isCriticalCustomerRiskTask(array $task, array $metadata, array $deal, array $notifications): bool
    {
        $dueAt = strtotime((string) ($task['due_date'] ?? '')) ?: null;
        if ($dueAt === null || $dueAt >= time()) {
            return false;
        }

        if (strtolower((string) ($task['priority'] ?? '')) !== 'urgent') {
            return false;
        }

        $hasCustomerContext = (int) ($task['contact_id'] ?? 0) > 0 || (int) ($metadata['deal_id'] ?? 0) > 0 || $deal !== [];
        if (!$hasCustomerContext) {
            return false;
        }

        foreach ($notifications as $notification) {
            if (strtolower((string) ($notification['severity'] ?? '')) === 'urgent') {
                return true;
            }
        }

        return false;
    }

    private function dashboardTaskAdvice(int $workspaceId, int $userId): ?array
    {
        if (!Database::tableExists('tasks')) {
            return null;
        }

        $tasks = $this->dashboardTaskCandidates($workspaceId, $userId);
        if ($tasks === []) {
            return null;
        }

        $ranked = [];
        foreach ($tasks as $task) {
            $metadata = $this->decodeJson($task['metadata_json'] ?? null);
            $deal = $this->linkedDealForTask($workspaceId, $metadata);
            $notifications = $this->linkedNotificationsForTask($workspaceId, $userId, $task, $metadata);
            $score = $this->scoreDashboardTask($task, $metadata, $deal, $notifications);
            if ($score <= 0) {
                continue;
            }
            $ranked[] = [
                'task' => $task,
                'metadata' => $metadata,
                'deal' => $deal,
                'notifications' => $notifications,
                'score' => $score,
            ];
        }

        if ($ranked === []) {
            return null;
        }

        usort($ranked, static function (array $left, array $right): int {
            $score = ((int) ($right['score'] ?? 0)) <=> ((int) ($left['score'] ?? 0));
            if ($score !== 0) {
                return $score;
            }

            $leftDue = strtotime((string) ($left['task']['due_date'] ?? '')) ?: PHP_INT_MAX;
            $rightDue = strtotime((string) ($right['task']['due_date'] ?? '')) ?: PHP_INT_MAX;
            if ($leftDue !== $rightDue) {
                return $leftDue <=> $rightDue;
            }

            return ((int) ($right['task']['id'] ?? 0)) <=> ((int) ($left['task']['id'] ?? 0));
        });

        $winner = $ranked[0];
        return $this->buildDashboardTaskAdvice(
            (array) $winner['task'],
            (array) $winner['metadata'],
            (array) $winner['deal'],
            (array) $winner['notifications'],
            (int) $winner['score'],
            false
        );
    }

    private function dashboardTaskCandidates(int $workspaceId, int $userId): array
    {
        $targetSelect = '';
        $targetJoin = '';
        if (Database::tableExists('targets') && Database::columnExists('tasks', 'target_id')) {
            $targetSelect = ',
                        tg.title AS target_title,
                        tg.target_date AS target_deadline';
            $targetJoin = 'LEFT JOIN targets tg ON tg.id = t.target_id AND tg.workspace_id = t.workspace_id';
        }

        try {
            return Database::query(
                "SELECT t.*,
                        c.first_name AS contact_first_name,
                        c.last_name AS contact_last_name,
                        c.email AS contact_email,
                        c.company AS contact_company,
                        c.stage AS contact_stage,
                        c.lead_score AS contact_lead_score,
                        c.updated_at AS contact_updated_at
                        {$targetSelect}
                 FROM tasks t
                 LEFT JOIN contacts c ON c.id = t.contact_id AND c.workspace_id = t.workspace_id
                 {$targetJoin}
                 WHERE t.workspace_id = ?
                   AND (t.assigned_to = ? OR t.created_by = ?)
                   AND t.status NOT IN ('completed', 'cancelled')
                 ORDER BY
                    CASE t.priority
                        WHEN 'urgent' THEN 1
                        WHEN 'high' THEN 2
                        WHEN 'medium' THEN 3
                        ELSE 4
                    END,
                    t.due_date ASC,
                    t.created_at DESC
                 LIMIT 80",
                [$workspaceId, $userId, $userId]
            );
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function scoreDashboardTask(array $task, array $metadata, array $deal, array $notifications): int
    {
        $score = 0;
        $dueAt = strtotime((string) ($task['due_date'] ?? '')) ?: null;
        if ($dueAt !== null && $dueAt < time()) {
            $score += 100;
        } elseif ($dueAt !== null && date('Y-m-d', $dueAt) === date('Y-m-d')) {
            $score += 70;
        }

        $priority = strtolower((string) ($task['priority'] ?? ''));
        if ($priority === 'urgent') {
            $score += 50;
        } elseif ($priority === 'high') {
            $score += 35;
        }

        if ($notifications !== []) {
            $score += 35;
            foreach ($notifications as $notification) {
                if (in_array(strtolower((string) ($notification['severity'] ?? '')), ['urgent', 'high'], true)) {
                    $score += 25;
                    break;
                }
            }
        }

        $leadScore = (int) ($task['contact_lead_score'] ?? 0);
        $contactStage = strtolower((string) ($task['contact_stage'] ?? ''));
        $contactUpdatedAt = strtotime((string) ($task['contact_updated_at'] ?? '')) ?: null;
        if ($leadScore >= 70 || ($contactStage !== '' && in_array($contactStage, ['new', 'contacted', 'qualified'], true) && $contactUpdatedAt !== null && $contactUpdatedAt < strtotime('-7 days'))) {
            $score += 25;
        }

        if ($this->dealNeedsAttention($deal)) {
            $score += 25;
        }

        if ($this->isAiStarterTask($task, $metadata) || $this->isSetupTask($task, $metadata)) {
            $score += 15;
        }

        return $score;
    }

    private function buildDashboardTaskAdvice(array $task, array $metadata, array $deal, array $notifications, int $score, bool $allowTaskCta = false): array
    {
        $title = trim((string) ($task['title'] ?? 'Recommended Next Task'));
        $timing = $this->taskTimingSentence($task);
        $businessReason = $this->taskBusinessReason($task, $metadata, $deal);
        $notificationReason = $this->notificationReason($notifications);
        $action = $this->taskNextAction($task, $metadata, $deal);

        $body = $businessReason !== ''
            ? $businessReason
            : 'This is the strongest task to move first because its timing and priority make it more sensitive than the rest of the queue.';

        $bullets = array_values(array_filter([
            $timing,
            $notificationReason,
            $action,
        ]));

        if (count($bullets) < 2 && $businessReason !== '') {
            $bullets[] = $businessReason;
        }

        $insight = [
            'kind' => 'dashboard_task_advice',
            'title' => $title !== '' ? $title : 'Recommended Next Task',
            'body' => $body,
            'bullets' => array_slice($bullets, 0, 3),
            'source' => 'clarity_page_insight',
            'task_id' => (int) ($task['id'] ?? 0),
            'rank_score' => $score,
        ];

        if ($allowTaskCta && $this->shouldLinkForInsight('dashboard_task_advice', 'dashboard.php', ['critical_customer_risk' => true])) {
            $insight = $this->withOptionalCta(
                $insight,
                'Open Task',
                'task_view.php?id=' . (int) ($task['id'] ?? 0) . '&source=clarity_chat',
                'critical_customer_risk'
            );
        }

        return $insight;
    }

    private function taskTimingSentence(array $task): string
    {
        $dueAt = strtotime((string) ($task['due_date'] ?? '')) ?: null;
        if ($dueAt === null) {
            $priority = strtolower((string) ($task['priority'] ?? ''));
            if (in_array($priority, ['urgent', 'high'], true)) {
                return 'Its priority is ' . $priority . ', so it should be handled before lower-risk work.';
            }
            return '';
        }

        if ($dueAt < time()) {
            return 'This is already overdue, so delaying it further increases follow-through risk.';
        }

        if (date('Y-m-d', $dueAt) === date('Y-m-d')) {
            return 'This is due today, which makes it the cleanest task to move before the day gets away.';
        }

        return 'Its due date is coming up on ' . date('M j', $dueAt) . '.';
    }

    private function taskBusinessReason(array $task, array $metadata, array $deal): string
    {
        if ($this->dealNeedsAttention($deal)) {
            $dealLabel = trim((string) ($deal['title'] ?? 'an open deal'));
            return 'This protects momentum on ' . $dealLabel . ', which is still open and needs a next step.';
        }

        $contactName = trim((string) ($task['contact_first_name'] ?? '') . ' ' . (string) ($task['contact_last_name'] ?? ''));
        if ($contactName !== '') {
            $leadScore = (int) ($task['contact_lead_score'] ?? 0);
            if ($leadScore >= 70) {
                return 'This is tied to ' . $contactName . ', a high-score lead that should not be left waiting.';
            }
            return 'This is tied to ' . $contactName . ', so completing it keeps the relationship moving.';
        }

        if ($this->isSetupTask($task, $metadata)) {
            return 'This looks like a setup blocker; clearing it makes the workspace more useful for automation and follow-up.';
        }

        if ($this->isAiStarterTask($task, $metadata)) {
            return 'AI Coach created this as a starter task because it supports the next structured-growth step.';
        }

        if (!empty($task['target_title'])) {
            return 'This supports the target "' . (string) $task['target_title'] . '", so finishing it helps keep that goal moving.';
        }

        return '';
    }

    private function taskNextAction(array $task, array $metadata, array $deal): string
    {
        $intent = strtolower((string) ($metadata['task_intent'] ?? ''));
        $title = strtolower((string) ($task['title'] ?? ''));

        if ($intent === 'follow_up' || str_contains($title, 'follow') || str_contains($title, 'outreach') || str_contains($title, 'reply')) {
            return 'Next move: send or record the follow-up, then capture the reply or next commitment.';
        }

        if ($intent === 'pricing' || str_contains($title, 'pricing') || str_contains($title, 'price')) {
            return 'Next move: finish the pricing detail, save it, and verify it is visible where the team will use it.';
        }

        if ($intent === 'company_profile' || str_contains($title, 'company profile')) {
            return 'Next move: complete the missing company profile fields and save the setup.';
        }

        if ($this->dealNeedsAttention($deal)) {
            return 'Next move: open the task, update the linked deal or customer, and set the next follow-up.';
        }

        if ($this->isSetupTask($task, $metadata)) {
            return 'Next move: complete the setup step and confirm the saved configuration works.';
        }

        return 'Next move: open the task, do the smallest concrete step, and record the outcome.';
    }

    private function notificationReason(array $notifications): string
    {
        if ($notifications === []) {
            return '';
        }

        $notification = $notifications[0];
        $title = trim((string) ($notification['title'] ?? ''));
        $message = trim((string) ($notification['message'] ?? ''));
        $summary = $title !== '' ? $title : $message;
        if ($summary === '') {
            return 'A recent unread signal points back to this same item, so it is worth resolving before it drifts.';
        }

        return 'A recent unread signal adds context: ' . $this->limitSentence($summary, 120);
    }

    private function linkedNotificationsForTask(int $workspaceId, int $userId, array $task, array $metadata): array
    {
        if (!Database::tableExists('notifications')) {
            return [];
        }

        $clauses = ['(entity_type = ? AND entity_id = ?)'];
        $params = [$workspaceId, $userId, 'task', (int) ($task['id'] ?? 0)];

        $contactId = (int) ($task['contact_id'] ?? 0);
        if ($contactId > 0) {
            $clauses[] = '(entity_type = ? AND entity_id = ?)';
            $params[] = 'contact';
            $params[] = $contactId;
        }

        $dealId = (int) ($metadata['deal_id'] ?? 0);
        if ($dealId > 0) {
            $clauses[] = '(entity_type = ? AND entity_id = ?)';
            $params[] = 'deal';
            $params[] = $dealId;
        }

        $taskId = (int) ($task['id'] ?? 0);
        if ($taskId > 0) {
            $clauses[] = 'link LIKE ?';
            $params[] = '%task_view.php?id=' . $taskId . '%';
        }

        $severitySelect = Database::columnExists('notifications', 'severity') ? 'severity' : "'' AS severity";
        $aiInsightSelect = Database::columnExists('notifications', 'ai_insight') ? 'ai_insight' : "'' AS ai_insight";
        $aiActionSelect = Database::columnExists('notifications', 'ai_action') ? 'ai_action' : "'' AS ai_action";

        try {
            return Database::query(
                "SELECT id, title, message, entity_type, entity_id, link, created_at, {$severitySelect}, {$aiInsightSelect}, {$aiActionSelect}
                 FROM notifications
                 WHERE workspace_id = ?
                   AND user_id = ?
                   AND is_read = 0
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY)
                   AND (" . implode(' OR ', $clauses) . ")
                 ORDER BY
                    CASE LOWER(COALESCE(severity, ''))
                        WHEN 'urgent' THEN 1
                        WHEN 'high' THEN 2
                        ELSE 3
                    END,
                    created_at DESC
                 LIMIT 3",
                $params
            );
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function linkedDealForTask(int $workspaceId, array $metadata): array
    {
        $dealId = (int) ($metadata['deal_id'] ?? 0);
        if ($dealId <= 0 || !Database::tableExists('deals')) {
            return [];
        }

        try {
            return Database::queryOne(
                "SELECT id, title, stage, value, expected_close_date, updated_at, created_at
                 FROM deals
                 WHERE workspace_id = ? AND id = ?
                 LIMIT 1",
                [$workspaceId, $dealId]
            ) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function dealNeedsAttention(array $deal): bool
    {
        if ($deal === []) {
            return false;
        }

        $stage = strtolower((string) ($deal['stage'] ?? ''));
        if (in_array($stage, ['closed_won', 'closed_lost'], true)) {
            return false;
        }

        $updatedAt = strtotime((string) ($deal['updated_at'] ?? $deal['created_at'] ?? '')) ?: null;
        $expectedClose = strtotime((string) ($deal['expected_close_date'] ?? '')) ?: null;

        return ($updatedAt !== null && $updatedAt < strtotime('-7 days'))
            || ($expectedClose !== null && $expectedClose <= strtotime('+2 days'));
    }

    private function isAiStarterTask(array $task, array $metadata): bool
    {
        return (string) ($metadata['source_surface'] ?? '') === 'ai_coach'
            || (string) ($metadata['source_recommendation_type'] ?? '') === 'coach';
    }

    private function isSetupTask(array $task, array $metadata): bool
    {
        $intent = strtolower((string) ($metadata['task_intent'] ?? ''));
        if (in_array($intent, ['pricing', 'company_profile', 'sales_process', 'segmentation', 'ops', 'billing'], true)) {
            return true;
        }

        $title = strtolower((string) ($task['title'] ?? ''));
        return str_contains($title, 'setup')
            || str_contains($title, 'configuration')
            || str_contains($title, 'pricing')
            || str_contains($title, 'company profile');
    }

    private function contactInsight(int $workspaceId, int $userId): array
    {
        if (!Database::tableExists('contacts')) {
            return $this->generalInsight('contacts.php');
        }

        $totalContacts = $this->countQuery("SELECT COUNT(*) AS c FROM contacts WHERE workspace_id = ?", [$workspaceId]);
        if ($totalContacts <= 0) {
            return [
                'kind' => 'contacts_empty_guidance',
                'title' => 'Make Contacts Useful First',
                'body' => 'This page becomes valuable once it has enough real people for Clarity to reason from.',
                'bullets' => [
                    'Start with 5-10 real leads, customers, or partners instead of sample records.',
                    'Add name, phone or email, company, owner, and source wherever you know them.',
                    'After that, Clarity can spot stale leads, missing ownership, and follow-up timing.',
                ],
                'source' => 'clarity_page_insight',
            ];
        }

        $missingData = $this->countQuery(
            "SELECT COUNT(*) AS c FROM contacts
             WHERE workspace_id = ?
               AND stage IN ('new', 'contacted', 'qualified')
               AND (COALESCE(email, '') = '' OR COALESCE(phone, '') = '' OR COALESCE(company, '') = '')",
            [$workspaceId]
        );
        if ($missingData > 0) {
            return [
                'kind' => 'contacts_data_quality_advice',
                'title' => 'Complete The Lead Details',
                'body' => 'Clarity can only give strong contact advice when the basic relationship fields are dependable.',
                'bullets' => [
                    $missingData . ' open lead' . ($missingData === 1 ? ' is' : 's are') . ' missing phone, email, or company context.',
                    'Next move: fill the easiest missing contact route first so follow-up advice has somewhere to point.',
                    'Once the records are cleaner, Clarity can separate urgent leads from quiet admin cleanup.',
                ],
                'source' => 'clarity_page_insight',
            ];
        }

        $unassigned = $this->countQuery(
            "SELECT COUNT(*) AS c FROM contacts
             WHERE workspace_id = ?
               AND stage IN ('new', 'contacted', 'qualified')
               AND (assigned_to IS NULL OR assigned_to = 0)",
            [$workspaceId]
        );
        if ($unassigned > 0) {
            return [
                'kind' => 'contacts_lead_momentum_advice',
                'title' => 'Assign Lead Ownership',
                'body' => 'Before follow-up gets clever, each active lead needs a person responsible for moving it.',
                'bullets' => [
                    $unassigned . ' open lead' . ($unassigned === 1 ? ' has' : 's have') . ' no owner, which makes next-step advice less reliable.',
                    'Next move: assign ownership before writing sequences or creating more tasks.',
                    'After ownership is clear, Clarity can prioritize timing and relationship risk with more confidence.',
                ],
                'source' => 'clarity_page_insight',
            ];
        }

        $staleNoActivity = $this->countQuery(
            "SELECT COUNT(*) AS c
             FROM contacts c
             LEFT JOIN (
                SELECT contact_id, MAX(created_at) AS latest_activity
                FROM activities
                WHERE workspace_id = ?
                GROUP BY contact_id
             ) a ON a.contact_id = c.id
             WHERE c.workspace_id = ?
               AND c.stage IN ('new', 'contacted', 'qualified')
               AND COALESCE(a.latest_activity, c.updated_at, c.created_at) < DATE_SUB(NOW(), INTERVAL 7 DAY)",
            [$workspaceId, $workspaceId]
        );
        $highScore = $this->countQuery(
            "SELECT COUNT(*) AS c FROM contacts
             WHERE workspace_id = ?
               AND stage IN ('new', 'contacted', 'qualified')
               AND COALESCE(lead_score, 0) >= 70",
            [$workspaceId]
        );
        if ($staleNoActivity > 0 || $highScore > 0) {
            return [
                'kind' => 'contacts_lead_momentum_advice',
                'title' => 'Refresh Lead Momentum',
                'body' => 'The useful move on this page is to turn promising or quiet leads into clear next steps.',
                'bullets' => array_values(array_filter([
                    $highScore > 0 ? $highScore . ' high-score lead' . ($highScore === 1 ? ' needs' : 's need') . ' timing reviewed before interest cools.' : '',
                    $staleNoActivity > 0 ? $staleNoActivity . ' lead' . ($staleNoActivity === 1 ? ' has' : 's have') . ' gone quiet long enough to deserve a fresh touch.' : '',
                    'Next move: pick one lead, record the last real interaction, and set the next follow-up outcome.',
                ])),
                'source' => 'clarity_page_insight',
            ];
        }

        return [
            'kind' => 'contacts_data_quality_advice',
            'title' => 'Keep Contacts Decision-Ready',
            'body' => 'This page is stable enough for Clarity to help, so the best next move is keeping each record decision-ready.',
            'bullets' => [
                'Check that new records have a real source, owner, and next relationship status.',
                'Use notes or recent activity to explain why the person matters now.',
                'That gives Clarity better context for prioritizing follow-up instead of repeating visible lists.',
            ],
            'source' => 'clarity_page_insight',
        ];
    }

    private function dealInsight(int $workspaceId, int $userId): array
    {
        if (!Database::tableExists('deals')) {
            return $this->generalInsight('deals.php');
        }

        $openStageClause = "stage NOT IN ('closed_won', 'closed_lost')";
        $openDeals = $this->countQuery("SELECT COUNT(*) AS c FROM deals WHERE workspace_id = ? AND {$openStageClause}", [$workspaceId]);
        if ($openDeals <= 0) {
            return [
                'kind' => 'deals_empty_guidance',
                'title' => 'Make Pipeline Visible',
                'body' => 'Deals become useful when they represent real opportunities Clarity can track through value, timing, and next step.',
                'bullets' => [
                    'Add one real opportunity only when there is a customer, need, or proposal worth tracking.',
                    'Include stage, value, owner, and expected close date so pipeline advice has a real shape.',
                    'Once the first deal exists, Clarity can watch momentum instead of describing an empty pipeline.',
                ],
                'source' => 'clarity_page_insight',
            ];
        }

        $ownerClause = Database::columnExists('deals', 'assigned_to') ? ' OR assigned_to IS NULL OR assigned_to = 0' : '';
        $missingCloseOrOwner = $this->countQuery(
            "SELECT COUNT(*) AS c FROM deals
             WHERE workspace_id = ?
               AND {$openStageClause}
               AND (expected_close_date IS NULL OR expected_close_date = '0000-00-00' OR COALESCE(value, 0) <= 0{$ownerClause})",
            [$workspaceId]
        );
        if ($missingCloseOrOwner > 0) {
            return [
                'kind' => 'deals_data_quality_advice',
                'title' => 'Give The Pipeline A Shape',
                'body' => 'Clarity can judge pipeline risk better after each open opportunity has a clear owner, value, and close timing.',
                'bullets' => [
                    $missingCloseOrOwner . ' open deal' . ($missingCloseOrOwner === 1 ? ' is' : 's are') . ' missing value, close date, or ownership context.',
                    'Next move: fix the deal that has the clearest customer intent first.',
                    'After that, Clarity can tell whether the pipeline is healthy instead of just open.',
                ],
                'source' => 'clarity_page_insight',
            ];
        }

        $staleDeals = $this->countQuery(
            "SELECT COUNT(*) AS c FROM deals
             WHERE workspace_id = ?
               AND {$openStageClause}
               AND COALESCE(updated_at, created_at) < DATE_SUB(NOW(), INTERVAL 7 DAY)",
            [$workspaceId]
        );
        if ($staleDeals > 0) {
            return [
                'kind' => 'deals_momentum_advice',
                'title' => 'Restart Deal Momentum',
                'body' => 'The best pipeline move is to refresh the deal most likely to stall without a next commitment.',
                'bullets' => [
                    $staleDeals . ' open deal' . ($staleDeals === 1 ? ' has' : 's have') . ' gone quiet for at least a week.',
                    'Next move: update the stage, last customer touch, or next commitment on the strongest deal.',
                    'That lets Clarity separate real pipeline risk from normal waiting time.',
                ],
                'source' => 'clarity_page_insight',
            ];
        }

        return [
            'kind' => 'deals_momentum_advice',
            'title' => 'Keep Deals Actionable',
            'body' => 'Your open deals have enough structure for better advice, so the useful move is keeping next commitments explicit.',
            'bullets' => [
                'Make sure every active deal shows the latest customer signal, not just a stage.',
                'Use the next step field or notes to capture the specific commitment you are waiting on.',
                'Clarity can then advise from momentum instead of only pipeline value.',
            ],
            'source' => 'clarity_page_insight',
        ];
    }

    private function taskInsight(int $workspaceId, int $userId): array
    {
        $starterInsight = $this->tasksStarterInsight($userId);
        if ($starterInsight !== null) {
            return $starterInsight;
        }

        $criticalAdvice = $this->dashboardCriticalCustomerRiskAdvice($workspaceId, $userId);
        if ($criticalAdvice !== null) {
            return $criticalAdvice;
        }

        if (!Database::tableExists('tasks')) {
            return $this->generalInsight('tasks.php');
        }

        $openTasks = $this->countQuery(
            "SELECT COUNT(*) AS c FROM tasks
             WHERE workspace_id = ?
               AND (assigned_to = ? OR created_by = ?)
               AND status NOT IN ('completed', 'cancelled')",
            [$workspaceId, $userId, $userId]
        );
        if ($openTasks <= 0) {
            return [
                'kind' => 'tasks_empty_guidance',
                'title' => 'Use Tasks For Real Commitments',
                'body' => 'An empty task page is healthy only if the important setup, customer, and follow-up commitments are captured elsewhere.',
                'bullets' => [
                    'Create tasks from real commitments, not from vague reminders.',
                    'Give each task an owner, due date, and outcome so Clarity can rank it later.',
                    'That turns this page into a focus system instead of another list.',
                ],
                'source' => 'clarity_page_insight',
            ];
        }

        $overdue = $this->countQuery(
            "SELECT COUNT(*) AS c FROM tasks
             WHERE workspace_id = ?
               AND (assigned_to = ? OR created_by = ?)
               AND status NOT IN ('completed', 'cancelled')
               AND due_date IS NOT NULL
               AND due_date < NOW()",
            [$workspaceId, $userId, $userId]
        );
        if ($overdue > 0) {
            return [
                'kind' => 'tasks_focus_advice',
                'title' => 'Recover Task Trust',
                'body' => 'The useful move here is not opening another task; it is deciding what the overdue work is actually blocking.',
                'bullets' => [
                    $overdue . ' task' . ($overdue === 1 ? ' is' : 's are') . ' overdue, so the list may be losing trust as a planning tool.',
                    'Next move: complete, reschedule, or cancel the one overdue task with the clearest business consequence.',
                    'After that, Clarity can rank the remaining work without stale noise dominating the advice.',
                ],
                'source' => 'clarity_page_insight',
            ];
        }

        $missingShape = $this->countQuery(
            "SELECT COUNT(*) AS c FROM tasks
             WHERE workspace_id = ?
               AND (assigned_to = ? OR created_by = ?)
               AND status NOT IN ('completed', 'cancelled')
               AND (due_date IS NULL OR assigned_to IS NULL OR assigned_to = 0)",
            [$workspaceId, $userId, $userId]
        );
        if ($missingShape > 0) {
            return [
                'kind' => 'tasks_hygiene_advice',
                'title' => 'Make Tasks Rankable',
                'body' => 'Clarity can prioritize tasks better once each open item has timing and ownership.',
                'bullets' => [
                    $missingShape . ' open task' . ($missingShape === 1 ? ' needs' : 's need') . ' a due date or owner before ranking feels dependable.',
                    'Next move: add timing to the task that matters most this week.',
                    'That makes the chat advice less generic and more like a true focus companion.',
                ],
                'source' => 'clarity_page_insight',
            ];
        }

        return [
            'kind' => 'tasks_focus_advice',
            'title' => 'Choose The Outcome Before The Task',
            'body' => 'This page is useful when each task points to a business outcome, not just activity.',
            'bullets' => [
                'Pick the task whose completion changes setup confidence, customer momentum, or team clarity.',
                'Record the outcome after doing it so future advice can learn from the result.',
                'That keeps Clarity focused on progress instead of repeating the task list back to you.',
            ],
            'source' => 'clarity_page_insight',
        ];
    }

    private function tasksStarterInsight(int $userId): ?array
    {
        try {
            $starterTasks = (new Tasks())->getAiStarterTasks($userId, 3);
        } catch (\Throwable $e) {
            return null;
        }

        if ($starterTasks === []) {
            return null;
        }

        $bullets = [];
        $taskIds = [];
        foreach ($starterTasks as $task) {
            $title = trim((string) ($task['title'] ?? 'Starter task'));
            if ($title === '') {
                $title = 'Starter task';
            }
            $taskIds[] = (int) ($task['id'] ?? 0);

            $dueAt = !empty($task['due_date']) ? strtotime((string) $task['due_date']) : false;
            $dueText = $dueAt !== false ? ' due ' . date('M j, Y', $dueAt) : ' ready for action';
            $bullets[] = $this->limitSentence($title . ' is' . $dueText . '.', 150);
        }

        $insight = [
            'kind' => 'tasks_starter_tasks',
            'title' => 'Starter Tasks Ready',
            'body' => 'Clarity seeded these setup actions so the workspace has a clean first path before the task list turns into normal follow-through.',
            'bullets' => $bullets,
            'source' => 'clarity_page_insight',
            'task_ids' => array_values(array_filter($taskIds, static fn(int $id): bool => $id > 0)),
        ];

        $firstTaskId = (int) ($starterTasks[0]['id'] ?? 0);
        if ($firstTaskId > 0 && $this->shouldLinkForInsight('tasks_starter_tasks', 'tasks.php', ['ai_starter_task' => true])) {
            $insight = $this->withOptionalCta(
                $insight,
                'Open First Starter Task',
                'task_view.php?id=' . $firstTaskId . '&source=clarity_chat',
                'ai_starter_task'
            );
        }

        return $insight;
    }

    private function settingsInsight(int $workspaceId, int $userId): array
    {
        $setupAdvice = $this->dashboardSetupAdvice($workspaceId, $userId);
        if ($setupAdvice !== null) {
            unset($setupAdvice['cta_label'], $setupAdvice['cta_url'], $setupAdvice['cta_reason']);
            $setupAdvice['kind'] = 'settings_setup_advice';
            $setupAdvice['title'] = $setupAdvice['title'] !== '' ? $setupAdvice['title'] : 'Strengthen Setup Confidence';
            return $setupAdvice;
        }

        $skillSetupAdvice = $this->dashboardSkillSetupAdvice($workspaceId, $userId);
        if ($skillSetupAdvice !== null) {
            return $skillSetupAdvice;
        }

        return [
            'kind' => 'settings_setup_advice',
            'title' => 'Keep Settings Confidence High',
            'body' => 'Settings are most useful when they make the rest of the workspace easier for Clarity to trust.',
            'bullets' => [
                'Review the setting that most affects automation, ownership, or customer follow-up.',
                'Prefer completing one missing configuration over browsing every option.',
                'Once setup is clean, Clarity can spend more of its advice on business growth instead of system guidance.',
            ],
            'source' => 'clarity_page_insight',
        ];
    }

    private function marketplaceInsight(int $workspaceId, int $userId, bool $recordSideEffects = true): array
    {
        $candidate = $this->marketplaceCandidate($workspaceId, $userId);
        if ($candidate === null) {
            return [
                'kind' => 'general',
                'title' => 'Marketplace Looks Current',
                'body' => 'There is not a strong Marketplace action to push right now.',
                'bullets' => [
                    'Installed skills are already shaping Clarity context where they are available.',
                    'Return here after channel, lead, or setup activity changes for fresher recommendations.',
                ],
                'source' => 'clarity_page_insight',
            ];
        }

        if ($recordSideEffects) {
            $this->recordMarketplaceImpression($workspaceId, $userId, $candidate);
        }
        return $candidate;
    }

    private function marketplaceCandidate(int $workspaceId, int $userId): ?array
    {
        $action = $this->marketplaceNextActions->nextActionForWorkspace($workspaceId, $userId);
        if (empty($action['is_actionable'])) {
            return null;
        }

        return $this->pluginCandidateFromNextAction($action);
    }

    private function pluginCandidateFromNextAction(array $action): ?array
    {
        $skillKey = $this->normalizeKey((string) ($action['skill_key'] ?? ''));
        if ($skillKey === '') {
            return null;
        }

        $module = $this->catalog->find($skillKey) ?? [];
        $visual = $this->moduleVisual($module, $skillKey);
        $label = (string) (($action['module_label'] ?? '') ?: ($module['label'] ?? 'Marketplace item'));
        $kind = (string) ($action['kind'] ?? '');
        $isFinishSetup = $kind === 'finish_setup';
        $setupUrl = (string) (($action['url'] ?? '') ?: ($module['navigation']['url'] ?? 'workspace_skills.php'));
        $message = trim((string) ($action['message'] ?? ''));

        return [
            'kind' => 'marketplace_plugin',
            'title' => $isFinishSetup ? 'Finish Setting Up ' . $label : 'Recommended Plugin',
            'body' => $isFinishSetup
                ? 'You already installed ' . $label . ', but setup still needs attention before Clarity can use it well.'
                : 'The ' . $label . ' plugin looks like the strongest Marketplace fit right now.',
            'bullets' => array_values(array_filter([
                $message !== '' ? $message : '',
                $isFinishSetup
                    ? 'Next move: finish this setup so Clarity can use the installed module confidently.'
                    : 'Next move: install this module if it matches the workspace motion.',
            ])),
            'thumbnail_url' => $visual['thumbnail_url'],
            'thumbnail_alt' => $visual['thumbnail_alt'],
            'cta_label' => $isFinishSetup ? 'Finish Setup' : 'Install Recommended Module',
            'cta_url' => $this->withQuery($setupUrl, ['source' => 'clarity_chat']),
            'source' => 'clarity_page_insight',
            'skill_key' => $skillKey,
            'label' => $label,
        ];
    }

    private function installedSetupGaps(int $workspaceId, int $userId): array
    {
        $context = $this->installer->buildContextForWorkspace($workspaceId, $userId);
        $installedByKey = [];
        foreach ((array) ($context['installed'] ?? []) as $module) {
            $installedByKey[(string) ($module['key'] ?? '')] = (string) ($module['label'] ?? $module['key'] ?? 'Installed module');
        }

        $gaps = [];
        foreach ((array) ($context['readiness'] ?? []) as $skillKey => $readiness) {
            if (!is_array($readiness) || !empty($readiness['ready'])) {
                continue;
            }
            $label = $installedByKey[(string) $skillKey] ?? ucwords(str_replace('_', ' ', (string) $skillKey));
            $message = trim((string) ($readiness['message'] ?? $readiness['next_action'] ?? ''));
            $gaps[] = $label . ' is installed but still needs setup' . ($message !== '' ? ': ' . $message : '.');
            if (count($gaps) >= 2) {
                break;
            }
        }

        return $gaps;
    }

    private function marketplaceIsStale(int $workspaceId, int $userId): bool
    {
        $latest = null;
        if (Database::tableExists('workspace_marketplace_recommendation_events')) {
            $row = Database::queryOne(
                "SELECT MAX(created_at) AS latest_at
                 FROM workspace_marketplace_recommendation_events
                 WHERE workspace_id = ? AND user_id = ? AND surface = 'marketplace'",
                [$workspaceId, $userId]
            );
            $latest = (string) ($row['latest_at'] ?? '');
        }
        if (Database::tableExists('workspace_marketplace_activation_bundle_events')) {
            $row = Database::queryOne(
                "SELECT MAX(created_at) AS latest_at
                 FROM workspace_marketplace_activation_bundle_events
                 WHERE workspace_id = ? AND user_id = ? AND surface = 'marketplace'",
                [$workspaceId, $userId]
            );
            $bundleLatest = (string) ($row['latest_at'] ?? '');
            if ($bundleLatest !== '' && ($latest === null || strcmp($bundleLatest, $latest) > 0)) {
                $latest = $bundleLatest;
            }
        }

        if ($latest === null || $latest === '') {
            return true;
        }

        return strtotime($latest) < strtotime('-' . self::MARKETPLACE_RECENCY_DAYS . ' days');
    }

    private function recordMarketplaceImpression(int $workspaceId, int $userId, array $candidate): void
    {
        try {
            (new WorkspaceMarketplaceRecommendationEventService())->recordEvent(
                $workspaceId,
                $userId,
                (string) ($candidate['skill_key'] ?? ''),
                'clarity_chat',
                'impression',
                ['metadata' => ['source' => 'clarity_page_insight', 'label' => (string) ($candidate['label'] ?? '')]]
            );
        } catch (\Throwable $e) {
            // Insight rendering should not fail because analytics could not be recorded.
        }
    }

    private function moduleVisual(array $module, string $skillKey): array
    {
        $profile = (array) ($module['plugin_metadata']['marketplace_profile'] ?? []);
        $label = (string) ($module['label'] ?? ($skillKey !== '' ? ucwords(str_replace('_', ' ', $skillKey)) : 'Marketplace item'));

        return [
            'label' => $label,
            'thumbnail_url' => $this->marketplaceAsset((string) ($profile['thumbnail_url'] ?? '')),
            'thumbnail_alt' => (string) ($profile['thumbnail_alt'] ?? ($label . ' marketplace thumbnail')),
        ];
    }

    private function marketplaceAsset(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return function_exists('assetUrl') ? assetUrl('images/clarity-logo-256.png') : 'assets/images/clarity-logo-256.png';
        }
        if (preg_match('#^https?://#i', $path) === 1 || str_starts_with($path, '/')) {
            return $path;
        }
        if (str_starts_with($path, 'assets/')) {
            $path = substr($path, 7);
        }
        if (str_starts_with($path, 'uploads/')) {
            return function_exists('publicUrl') ? publicUrl('../' . $path) : '../' . $path;
        }
        return function_exists('assetUrl') ? assetUrl($path) : 'assets/' . ltrim($path, '/');
    }

    private function setupBlockerSummary(array $blockers): string
    {
        $blockers = array_values(array_filter(array_map('strval', $blockers)));
        if ($blockers === []) {
            return '';
        }
        return 'Setup note: ' . $blockers[0];
    }

    private function withQuery(string $url, array $params): string
    {
        $url = trim($url) !== '' ? trim($url) : 'workspace_skills.php';
        $safeParams = array_filter($params, static fn($value): bool => trim((string) $value) !== '');
        if ($safeParams === []) {
            return $url;
        }
        return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($safeParams);
    }

    private function withOptionalCta(array $insight, ?string $label, ?string $url, string $reason): array
    {
        $label = trim((string) $label);
        $url = trim((string) $url);
        if ($label === '' || $url === '') {
            unset($insight['cta_label'], $insight['cta_url'], $insight['cta_reason']);
            return $insight;
        }

        $insight['cta_label'] = $label;
        $insight['cta_url'] = $url;
        if ($reason !== '') {
            $insight['cta_reason'] = $reason;
        }
        return $insight;
    }

    private function shouldLinkForInsight(string $kind, string $currentPage, array $context = []): bool
    {
        if (str_starts_with($kind, 'marketplace_') || !empty($context['marketplace_item'])) {
            return true;
        }

        if (!empty($context['critical_customer_risk'])) {
            return true;
        }

        if (!empty($context['ai_starter_task'])) {
            return true;
        }

        if (empty($context['critical_cross_page'])) {
            return false;
        }

        $targetPage = $this->normalizePage((string) ($context['url'] ?? ''));
        return $targetPage !== '' && $targetPage !== $this->normalizePage($currentPage);
    }

    private function limitSentence(string $value, int $limit): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
        if ($value === '' || strlen($value) <= $limit) {
            return $value;
        }

        return rtrim(substr($value, 0, max(1, $limit - 3))) . '...';
    }

    private function decodeJson($raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function generalInsight(string $page = ''): array
    {
        $profile = $this->pageProfile($this->normalizePage($page));
        $purpose = (string) ($profile['purpose'] ?? 'workspace context');

        return [
            'kind' => 'general',
            'title' => 'Make This Page More Useful',
            'body' => 'Clarity is reading this page as ' . $purpose . ', but there is not a stronger page-specific signal yet.',
            'bullets' => [
                'Ask what information on this page is missing, stale, or hard to trust.',
                'Use Clarity to decide what would make this page more useful before jumping elsewhere.',
                'Installed workspace skills will shape the answer when they add useful context.',
            ],
            'source' => 'clarity_page_insight',
        ];
    }

    private function normalizePage(string $page): string
    {
        $page = strtolower(trim($page));
        $page = basename(parse_url($page, PHP_URL_PATH) ?: $page);
        return $page !== '' ? $page : 'dashboard.php';
    }

    private function normalizeKey(string $key): string
    {
        return strtolower(trim(preg_replace('/[^a-z0-9_]+/', '_', $key) ?? ''));
    }

    private function labelize(string $value): string
    {
        $value = trim(str_replace('_', ' ', $value));

        return $value !== '' ? ucwords($value) : 'Marketing';
    }

    private function countQuery(string $sql, array $params): int
    {
        try {
            return (int) (Database::queryOne($sql, $params)['c'] ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
