<?php

require_once dirname(__DIR__) . '/_bootstrap.php';
require_once dirname(__DIR__) . '/_serializers.php';

use CRM\Database;
use CRM\CacheManager;
use CRM\Authorization;
use CRM\Modules\AICoach;
use CRM\Modules\AIContactSummarizer;
use CRM\Modules\AIDealIntelligence;
use CRM\Modules\AITaskAutomationService;
use CRM\Modules\AIThreadSummarizer;
use CRM\Modules\Contacts;
use CRM\Modules\ConversationThreads;
use CRM\Modules\Deals;
use CRM\Modules\Notes;
use CRM\Modules\Tasks;
use CRM\Modules\UnifiedInbox;
use CRM\Services\CustomerReplyAssistantService;
use CRM\Services\AICoachReadinessService;
use CRM\Services\AICoachOperatingMaturityService;
use CRM\Services\AIService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceScopeService;

function mobileAiWhiteLabelText(string $value): string
{
    $value = preg_replace('/\b(?:an?\s+)?Open\s*AI(?:\s+model|\s+assistant|\s+system)?\b/i', 'Clarity', $value) ?? $value;
    $value = preg_replace('/\bChatGPT\b/i', 'Clarity', $value) ?? $value;
    $value = preg_replace('/\bGPT[-\s]?(?:3(?:\.5)?|4(?:o)?|5)?\b/i', 'AI', $value) ?? $value;
    $value = preg_replace('/\bOpenAI[-\s]?compatible\b/i', 'AI', $value) ?? $value;
    $value = preg_replace('/\s{2,}/', ' ', $value) ?? $value;
    return trim($value);
}

function mobileAiWhiteLabelPayload($value)
{
    if (is_string($value)) {
        return mobileAiWhiteLabelText($value);
    }
    if (!is_array($value)) {
        return $value;
    }

    $clean = [];
    foreach ($value as $key => $item) {
        $clean[$key] = mobileAiWhiteLabelPayload($item);
    }
    return $clean;
}

function mobileAiAction(
    string $actionKey,
    string $label,
    string $kind,
    array $payload = [],
    ?string $preview = null,
    bool $requiresConfirmation = false,
    ?string $resultRoute = null
): array {
    return mobileAiWhiteLabelPayload([
        'action_key' => $actionKey,
        'label' => $label,
        'kind' => $kind,
        'payload' => $payload,
        'preview' => $preview,
        'requires_confirmation' => $requiresConfirmation,
        'result_route' => $resultRoute,
    ]);
}

function mobileAiCard(
    string $id,
    string $type,
    string $title,
    string $summary,
    string $whyThisMatters,
    float $confidence,
    int $priority,
    ?string $targetRoute,
    ?string $targetEntityType,
    $targetEntityId,
    array $actions = [],
    array $explanations = [],
    bool $requiresReview = false,
    array $sourceContext = []
): array {
    return mobileAiWhiteLabelPayload([
        'id' => $id,
        'type' => $type,
        'title' => $title,
        'summary' => $summary,
        'why_this_matters' => $whyThisMatters,
        'confidence' => max(0.0, min(1.0, $confidence)),
        'priority' => $priority,
        'target_route' => $targetRoute,
        'target_entity_type' => $targetEntityType,
        'target_entity_id' => $targetEntityId,
        'actions' => array_values($actions),
        'explanations' => array_values(array_filter($explanations, static fn($item): bool => trim((string) $item) !== '')),
        'requires_review' => $requiresReview,
        'evidence' => array_values((array) ($sourceContext['evidence'] ?? [])),
        'task_state' => (string) ($sourceContext['task_state'] ?? 'available'),
        'recommendation_key' => $sourceContext['recommendation_key'] ?? null,
        'generation_status' => (array) ($sourceContext['generation_status'] ?? []),
        'source_context' => $sourceContext,
    ]);
}

function mobileAiRoleWeight(string $type, array $user): int
{
    $role = strtolower((string) ($user['role'] ?? ''));
    $salesTypes = ['deal_focus', 'deal_risk', 'contact_reengage'];
    $supportTypes = ['inbox_follow_up', 'thread_summary', 'task_focus'];

    if (str_contains($role, 'sales') || str_contains($role, 'account')) {
        return in_array($type, $salesTypes, true) ? 14 : 0;
    }
    if (str_contains($role, 'support') || str_contains($role, 'success')) {
        return in_array($type, $supportTypes, true) ? 14 : 0;
    }
    return 6;
}

function mobileAiNormalizeRecommendationCards(array $recommendations): array
{
    $cards = [];
    $sections = [
        'priorities' => 96,
        'quick_wins' => 84,
        'missing_features' => 72,
        'foundation_gaps' => 64,
    ];

    foreach ($sections as $section => $basePriority) {
        foreach ((array) ($recommendations[$section] ?? []) as $index => $item) {
            $title = trim((string) ($item['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $reason = trim((string) ($item['reason'] ?? ''));
            $targetId = !empty($item['target_id']) ? (int) $item['target_id'] : null;
            $targetRoute = $targetId ? '/deals/' . $targetId : '/ai';
            $evidence = array_values((array) ($item['evidence'] ?? []));
            $sourceContext = array_merge((array) ($item['source_context'] ?? []), [
                'source' => 'ai_coach',
                'section' => $section,
                'recommendation_key' => $item['recommendation_key'] ?? null,
                'task_state' => (string) ($item['task_state'] ?? 'available'),
                'evidence' => $evidence,
                'generation_status' => (array) ($recommendations['generation_status'] ?? []),
            ]);
            $actions = [
                mobileAiAction('open_target', $targetId ? 'Open record' : 'Open Clarity', 'open', [], null, false, $targetRoute),
                mobileAiAction('add_as_task', 'Add as task', 'api', [
                    'endpoint' => '/api/tasks/create.php',
                    'method' => 'POST',
                    'title' => $title,
                    'description' => $reason,
                    'target_id' => $targetId,
                    'metadata_json' => [
                        'source_surface' => 'ai_coach',
                        'source_recommendation_type' => (string) ($item['source_recommendation_type'] ?? $section),
                        'guidance_run_id' => (int) ($item['guidance_run_id'] ?? ($recommendations['diagnostics']['guidance_run_id'] ?? 0)),
                        'recommendation_key' => $item['recommendation_key'] ?? null,
                        'source_context' => $sourceContext,
                        'evidence' => $evidence,
                    ],
                ]),
                mobileAiAction('send_feedback', 'Send feedback', 'api', [
                    'endpoint' => '/api/ai/feedback.php',
                    'method' => 'POST',
                    'surface' => 'coach',
                    'guidance_run_id' => (int) ($item['guidance_run_id'] ?? ($recommendations['diagnostics']['guidance_run_id'] ?? 0)),
                    'recommendation_key' => $item['recommendation_key'] ?? null,
                ]),
            ];
            if (!empty($item['marketplace_skill_key'])) {
                $actions[] = mobileAiAction('open_marketplace_setup', 'Open Marketplace setup', 'open', [
                    'skill_key' => (string) $item['marketplace_skill_key'],
                ], null, false, (string) ($item['marketplace_setup_url'] ?? 'workspace_skills.php?source=coach'));
            }
            $cards[] = mobileAiCard(
                'coach-' . $section . '-' . $index,
                $section === 'priorities' ? 'priority_action' : 'coach_recommendation',
                $title,
                $reason !== '' ? $reason : 'Recommended next move based on workspace activity.',
                $reason !== '' ? $reason : 'This recommendation is ranked from your current workload and AI coach guidance.',
                0.7,
                $basePriority - $index,
                $targetRoute,
                $targetId ? 'deal' : null,
                $targetId,
                $actions,
                array_merge([
                    'Expected impact: ' . trim((string) ($item['impact'] ?? 'medium')),
                    'Effort: ' . trim((string) ($item['effort'] ?? 'medium')),
                ], array_values(array_filter(array_map(static function ($evidenceItem): string {
                    if (is_array($evidenceItem)) {
                        $label = trim((string) ($evidenceItem['label'] ?? ''));
                        $value = trim((string) ($evidenceItem['value'] ?? ''));
                        return $label && $value ? $label . ': ' . $value : ($label ?: $value);
                    }
                    return trim((string) $evidenceItem);
                }, $evidence)))),
                false,
                $sourceContext
            );
        }
    }

    return $cards;
}

function mobileAiCoachReadiness(int $userId): array
{
    $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
    if ($workspaceId <= 0 || $userId <= 0) {
        return [
            'ai_coach_installed' => false,
            'ai_coach_enabled' => false,
            'user_onboarding_complete' => false,
            'strategy_ready' => false,
            'idea_validation_ready' => false,
            'clarity_journey_ready' => false,
            'inherited_context_ready' => false,
            'personal_brief_source' => 'missing',
            'context_sources' => [],
            'remaining_personal_requirements' => [],
            'operating_maturity' => AICoachOperatingMaturityService::PRE_CLARITY_JOURNEY,
            'operating_maturity_context' => [],
            'recommendations_ready' => false,
            'missing_requirements' => [[
                'section' => 'workspace_setup',
                'field' => 'active_workspace',
                'label' => 'Active Workspace',
                'message' => 'Choose an active workspace.',
                'action' => 'workspace',
            ]],
            'marketplace_url' => 'workspace_skills.php?source=coach',
            'onboarding_payload' => null,
        ];
    }

    return (new AICoachReadinessService())->getReadiness($workspaceId, $userId);
}

function mobileAiReadinessEnvelope(array $readiness): array
{
    $ready = !empty($readiness['recommendations_ready']);
    return [
        'recommendations_ready' => $ready,
        'clarity_journey_ready' => !empty($readiness['clarity_journey_ready']),
        'inherited_context_ready' => !empty($readiness['inherited_context_ready']),
        'personal_brief_source' => (string) ($readiness['personal_brief_source'] ?? 'missing'),
        'context_sources' => (array) ($readiness['context_sources'] ?? []),
        'remaining_personal_requirements' => array_values((array) ($readiness['remaining_personal_requirements'] ?? [])),
        'operating_maturity' => (string) ($readiness['operating_maturity'] ?? AICoachOperatingMaturityService::PRE_CLARITY_JOURNEY),
        'operating_maturity_context' => (array) ($readiness['operating_maturity_context'] ?? []),
        'missing_requirements' => array_values((array) ($readiness['missing_requirements'] ?? [])),
        'marketplace_url' => (string) ($readiness['marketplace_url'] ?? 'workspace_skills.php?source=coach'),
        'onboarding_payload' => $readiness['onboarding_payload'] ?? null,
        'ai_coach_readiness' => $readiness,
        'why_this_matters' => $ready
            ? 'AI Coach recommendations are using the shared company baseline and inherited Coach context.'
            : (!empty($readiness['clarity_journey_ready'])
                ? 'AI Coach needs the shared company baseline and product or offer context before it can generate recommendations.'
                : 'AI Coach needs Clarity Journey completed before it can generate stronger recommendations.'),
    ];
}

function mobileAiBuildRecommendationPayload(int $userId): array
{
    $coach = new AICoach();
    $recommendations = $coach->generateRecommendations($userId, '2', ['live_ai' => true, 'include_provider_status' => true]);
    return [
        'why_this_matters' => (string) ($recommendations['why_this_matters'] ?? ''),
        'cards' => mobileAiNormalizeRecommendationCards($recommendations),
        'diagnostics' => (array) ($recommendations['diagnostics'] ?? []),
        'generation_status' => (array) ($recommendations['generation_status'] ?? []),
        'operating_maturity' => (string) ($recommendations['operating_maturity'] ?? AICoachOperatingMaturityService::PRE_CLARITY_JOURNEY),
        'operating_maturity_context' => (array) ($recommendations['operating_maturity_context'] ?? []),
        'assumption_conflicts' => (array) ($recommendations['assumption_conflicts'] ?? []),
    ];
}

function mobileAiBuildTodayBrief(array $user, array $summary, array $cards): array
{
    $topActions = array_slice($cards, 0, 3);
    $lines = [];
    if (!empty($summary['unread_conversations'])) {
        $lines[] = (int) $summary['unread_conversations'] . ' unread conversations need triage.';
    }
    if (!empty($summary['overdue_tasks'])) {
        $lines[] = (int) $summary['overdue_tasks'] . ' overdue tasks are still open.';
    }
    if (!empty($summary['open_deals'])) {
        $lines[] = (int) $summary['open_deals'] . ' active deals are still in motion.';
    }
    if ($lines === []) {
        $lines[] = 'Your workspace looks steady. Focus on the highest-leverage follow-up next.';
    }

    $name = trim((string) (($user['first_name'] ?? '') ?: ($user['email'] ?? 'team')));
    return [
        'headline' => 'Today brief for ' . $name,
        'summary' => implode(' ', array_slice($lines, 0, 2)),
        'urgent_risks' => array_values(array_filter([
            !empty($summary['overdue_tasks']) ? 'Overdue tasks may slow follow-through.' : null,
            !empty($summary['unread_notifications']) ? 'Unread alerts may hide urgent changes.' : null,
        ])),
        'likely_wins' => array_values(array_filter([
            !empty($summary['tasks_due_today']) ? 'Closing today\'s scheduled tasks can reduce backlog quickly.' : null,
            !empty($summary['open_deals']) ? 'Focused follow-up on active deals could unlock movement.' : null,
        ])),
        'blocked_items' => [],
        'top_actions' => $topActions,
    ];
}

function mobileAiDecorateConversationItem(array $row, int $viewerUserId): array
{
    $item = mobileConversationSummary($row);
    $badges = [];
    if (((int) ($item['unread_count'] ?? 0)) > 0 || empty($item['read_at'])) {
        $badges[] = 'Needs reply';
    }
    if (($item['thread']['priority'] ?? '') === 'high') {
        $badges[] = 'Customer risk';
    }
    if ((int) ($item['thread']['owner_id'] ?? 0) === $viewerUserId) {
        $badges[] = 'High-value thread';
    }
    if (($item['thread']['owner_id'] ?? null) === null) {
        $badges[] = 'Suggested follow-up';
    }
    $item['ai_badges'] = $badges;
    $item['ai_summary_preview'] = trim((string) ($item['body_preview'] ?? ''));
    $item['ai_priority'] = !empty($badges) ? 'high' : 'normal';
    return $item;
}

function mobileAiBuildInboxCards(int $userId, array $user, string $ownerScope = 'mine_unassigned'): array
{
    $inbox = new UnifiedInbox();
    $rows = $inbox->getThreadSummaries(4, 0, [
        'viewer_user_id' => $userId,
        'can_view_all_conversations' => Authorization::can('conversations.view_all', $user),
        'owner_scope' => $ownerScope,
    ]);
    $cards = [];
    foreach ($rows as $index => $row) {
        $decorated = mobileAiDecorateConversationItem($row, $userId);
        $conversationId = (int) ($decorated['latest_communication_id'] ?? $decorated['id'] ?? 0);
        $cards[] = mobileAiCard(
            'inbox-' . $conversationId,
            'inbox_follow_up',
            ($decorated['contact_name'] ?: $decorated['subject'] ?: 'Conversation') . ' needs attention',
            (string) ($decorated['ai_summary_preview'] ?? ''),
            !empty($decorated['ai_badges']) ? implode(', ', $decorated['ai_badges']) : 'Recent conversation activity needs a response or review.',
            0.62,
            88 - $index,
            '/inbox/' . $conversationId,
            'conversation',
            $conversationId,
            [
                mobileAiAction('open_thread', 'Open thread', 'open', [], null, false, '/inbox/' . $conversationId),
                mobileAiAction('save_draft_reply', 'Save draft', 'draft', ['conversation_id' => $conversationId], 'AI can prefill a reply draft.', false, '/inbox/' . $conversationId),
            ],
            $decorated['ai_badges'] ?? [],
            false,
            [
                'surface' => 'inbox',
                'thread_id' => $decorated['thread']['id'] ?? null,
                'thread_key' => $decorated['thread_key'] ?? null,
            ]
        );
    }
    return $cards;
}

function mobileAiBuildDealCards(int $userId): array
{
    $deals = new Deals();
    $rows = $deals->getAll(4, 0, ['assigned_to' => $userId]);
    $cards = [];
    foreach ($rows as $index => $deal) {
        $stage = (string) ($deal['stage'] ?? 'prospecting');
        $cards[] = mobileAiCard(
            'deal-' . (int) $deal['id'],
            'deal_focus',
            (string) ($deal['title'] ?? 'Deal'),
            'Stage: ' . str_replace('_', ' ', $stage) . '. Reconfirm next step and timing.',
            'Active pipeline items move fastest when the next owner action is explicit.',
            0.66,
            74 - $index,
            '/deals/' . (int) $deal['id'],
            'deal',
            (int) $deal['id'],
            [mobileAiAction('open_deal', 'Open deal', 'open', [], null, false, '/deals/' . (int) $deal['id'])],
            ['Expected outcome: unblock stage progression.'],
            false,
            ['surface' => 'deal']
        );
    }
    return $cards;
}

function mobileAiBuildContactCards(int $userId, array $user): array
{
    $contacts = new Contacts();
    $rows = $contacts->getAll(4, 0, null, 'mine_unassigned', $userId);
    $cards = [];
    foreach ($rows as $index => $contact) {
        $fullName = trim((string) (($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? '')));
        $cards[] = mobileAiCard(
            'contact-' . (int) $contact['id'],
            'contact_reengage',
            $fullName !== '' ? $fullName : (string) ($contact['email'] ?? 'Contact'),
            'Review relationship context and plan the next outreach.',
            'Strong follow-up timing helps maintain momentum with active contacts.',
            0.58,
            60 - $index,
            '/contacts/' . (int) $contact['id'],
            'contact',
            (int) $contact['id'],
            [mobileAiAction('open_contact', 'Open contact', 'open', [], null, false, '/contacts/' . (int) $contact['id'])],
            [],
            false,
            ['surface' => 'contact']
        );
    }
    return $cards;
}

function mobileAiRankCards(array $cards, array $user): array
{
    usort($cards, static function (array $left, array $right) use ($user): int {
        $leftScore = ((int) ($left['priority'] ?? 0)) + mobileAiRoleWeight((string) ($left['type'] ?? ''), $user);
        $rightScore = ((int) ($right['priority'] ?? 0)) + mobileAiRoleWeight((string) ($right['type'] ?? ''), $user);
        return $rightScore <=> $leftScore;
    });

    return array_values($cards);
}

function mobileAiBuildFastFeed(int $userId, array $user, array $summary = [], string $ownerScope = 'mine_unassigned'): array
{
    $cards = array_merge(
        mobileAiBuildInboxCards($userId, $user, $ownerScope),
        mobileAiBuildDealCards($userId),
        mobileAiBuildContactCards($userId, $user)
    );
    $cards = mobileAiRankCards($cards, $user);

    return [
        'today_brief' => mobileAiBuildTodayBrief($user, $summary, $cards),
        'cards' => $cards,
        'sections' => [
            'priority_actions' => array_slice($cards, 0, 5),
            'inbox_copilot' => array_values(array_filter($cards, static fn(array $card): bool => (string) ($card['type'] ?? '') === 'inbox_follow_up')),
            'pipeline_copilot' => array_values(array_filter($cards, static fn(array $card): bool => in_array((string) ($card['type'] ?? ''), ['deal_focus', 'contact_reengage'], true))),
        ],
        'recommended_prompts' => [
            'What should I do next?',
            'Summarize my inbox',
            'Which deals are at risk?',
            'What follow-ups matter most?',
        ],
        'generated_at' => date(DATE_ATOM),
        'feed_mode' => 'light',
    ];
}

function mobileAiBuildFeed(int $userId, array $user, array $summary = [], string $ownerScope = 'mine_unassigned'): array
{
    $recommendationPayload = mobileAiBuildRecommendationPayload($userId);
    $cards = array_merge(
        $recommendationPayload['cards'],
        mobileAiBuildInboxCards($userId, $user, $ownerScope),
        mobileAiBuildDealCards($userId),
        mobileAiBuildContactCards($userId, $user)
    );
    $cards = mobileAiRankCards($cards, $user);

    return [
        'today_brief' => mobileAiBuildTodayBrief($user, $summary, $cards),
        'cards' => $cards,
        'sections' => [
            'priority_actions' => array_slice($cards, 0, 5),
            'inbox_copilot' => array_values(array_filter($cards, static fn(array $card): bool => (string) ($card['type'] ?? '') === 'inbox_follow_up')),
            'pipeline_copilot' => array_values(array_filter($cards, static fn(array $card): bool => in_array((string) ($card['type'] ?? ''), ['deal_focus', 'contact_reengage'], true))),
        ],
        'recommended_prompts' => [
            'What should I do next?',
            'Summarize my inbox',
            'Which deals are at risk?',
            'What follow-ups matter most?',
        ],
        'diagnostics' => $recommendationPayload['diagnostics'],
        'generated_at' => date(DATE_ATOM),
        'feed_mode' => 'rich',
    ];
}

function mobileAiBuildCompactHomePayload(int $userId, array $user, array $summary = [], string $ownerScope = 'mine_unassigned'): array
{
    $cards = array_merge(
        mobileAiBuildInboxCards($userId, $user, $ownerScope),
        mobileAiBuildDealCards($userId),
        mobileAiBuildContactCards($userId, $user)
    );
    $cards = mobileAiRankCards($cards, $user);
    $topActions = array_slice($cards, 0, 2);

    return [
        'today_brief' => mobileAiBuildTodayBrief($user, $summary, $topActions),
        'top_actions' => $topActions,
    ];
}

function mobileAiNormalizeThreadSummary(string $summary): string
{
    $summary = trim($summary);
    if ($summary === '') {
        return '';
    }

    $decoded = json_decode($summary, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        return $summary;
    }

    if (isset($decoded['summary']) && is_string($decoded['summary'])) {
        $embedded = trim((string) $decoded['summary']);
        if ($embedded !== '') {
            return $embedded;
        }
    }

    $lines = [];

    $mainTopic = trim((string) ($decoded['main_topic'] ?? ''));
    if ($mainTopic !== '') {
        $lines[] = 'Main topic: ' . $mainTopic;
    }

    $keyDecisions = array_values(array_filter(
        array_map(
            static fn($item): string => trim((string) $item),
            (array) ($decoded['key_decisions'] ?? [])
        ),
        static fn(string $item): bool => $item !== ''
    ));
    if ($keyDecisions !== []) {
        $lines[] = 'Key decisions: ' . implode('; ', $keyDecisions);
    }

    $openQuestions = array_values(array_filter(
        array_map(
            static fn($item): string => trim((string) $item),
            (array) ($decoded['open_questions'] ?? [])
        ),
        static fn(string $item): bool => $item !== ''
    ));
    if ($openQuestions !== []) {
        $lines[] = 'Open questions: ' . implode('; ', $openQuestions);
    }

    $suggestedNextStep = trim((string) ($decoded['suggested_next_step'] ?? ''));
    if ($suggestedNextStep !== '') {
        $lines[] = 'Suggested next step: ' . $suggestedNextStep;
    }

    if ($lines !== []) {
        return implode("\n\n", $lines);
    }

    return $summary;
}

function mobileAiConversationInsights(int $conversationId, int $userId, int $threadId = 0): array
{
    $workspaceScope = new WorkspaceScopeService();
    $communicationWorkspace = $workspaceScope->workspaceClause('c.');
    $taskWorkspace = $workspaceScope->workspaceClause();
    $dealWorkspace = $workspaceScope->workspaceClause();
    $cache = new CacheManager();
    $communication = Database::queryOne(
        "SELECT c.id, c.contact_id, c.read_at, c.thread_key, c.channel
         FROM communications c
         WHERE {$communicationWorkspace['sql']}
           AND c.id = ?
         LIMIT 1",
        array_merge($communicationWorkspace['params'], [$conversationId])
    ) ?: [];
    $threadModule = new ConversationThreads();
    $thread = $threadId > 0
        ? $threadModule->getByCommunication($conversationId)
        : ($conversationId > 0 ? $threadModule->getByCommunication($conversationId) : null);
    $resolvedThreadId = (int) ($thread['id'] ?? $threadId);
    $latestMessageId = mobileAiConversationLatestMessageId($communication, $thread);
    $cacheKey = sprintf(
        'mobile_ai:conversation:%d:%d:%d',
        $resolvedThreadId > 0 ? $resolvedThreadId : $conversationId,
        max(0, $latestMessageId),
        max(0, $userId)
    );

    $cached = $cache->get($cacheKey);
    if (is_array($cached)) {
        return $cached;
    }
    $cached = mobileAiFileCacheGet($cacheKey, 900);
    if (is_array($cached)) {
        return $cached;
    }

    $result = (static function () use (
        $conversationId,
        $userId,
        $communication,
        $thread
    ): array {
    $summarizer = new AIThreadSummarizer();
    $replyAssistant = new CustomerReplyAssistantService();
    $contactId = (int) ($communication['contact_id'] ?? 0);
    $viewer = $userId > 0
        ? (Database::queryOne('SELECT * FROM users WHERE id = ? LIMIT 1', [$userId]) ?? [])
        : [];
    $canViewAllTasks = $viewer !== [] && Authorization::can('tasks.view_all', $viewer);
    $taskCountRow = $contactId > 0
        ? Database::queryOne(
            "SELECT COUNT(*) AS total FROM tasks WHERE {$taskWorkspace['sql']} AND contact_id = ?" . ($canViewAllTasks ? '' : " AND assigned_to = ?"),
            $canViewAllTasks
                ? array_merge($taskWorkspace['params'], [$contactId])
                : array_merge($taskWorkspace['params'], [$contactId, $userId])
        )
        : ['total' => 0];
    $dealCountRow = $contactId > 0
        ? Database::queryOne(
            "SELECT COUNT(*) AS total FROM deals WHERE {$dealWorkspace['sql']} AND contact_id = ?",
            array_merge($dealWorkspace['params'], [$contactId])
        )
        : ['total' => 0];

    $summary = '';
    try {
        $summary = mobileAiNormalizeThreadSummary(
            trim($summarizer->summarizeByCommunication($conversationId))
        );
    } catch (Throwable $e) {
        $summary = '';
    }

    $reply = [];
    try {
        $reply = $replyAssistant->generateDraftFromCommunication($conversationId, $userId, ['surface' => 'inbox']);
    } catch (Throwable $e) {
        $reply = [];
    }

    $nextSteps = [];
    if ((int) ($taskCountRow['total'] ?? 0) > 0) {
        $nextSteps[] = 'Review the existing follow-up tasks before replying.';
    }
    if ((int) ($dealCountRow['total'] ?? 0) > 0) {
        $nextSteps[] = 'Check whether this conversation should move an attached deal forward.';
    }
    if ($nextSteps === []) {
        $nextSteps[] = 'Draft a response and capture the next commitment.';
    }

    return [
        'thread_summary' => $summary !== '' ? $summary : 'AI summary is not available yet for this thread.',
        'suggested_reply' => [
            'subject' => (string) (($reply['compat']['subject'] ?? $reply['draft']['subject'] ?? '')),
            'body' => (string) (($reply['compat']['body'] ?? $reply['draft']['plain_body'] ?? '')),
            'explanation' => (string) (($reply['summary_text'] ?? $reply['draft']['explanation'] ?? '')),
            'source' => (string) ($reply['source'] ?? 'fallback'),
        ],
        'next_steps' => $nextSteps,
        'risk_flags' => array_values(array_filter([
            empty($communication['read_at']) ? 'Needs reply' : null,
            ((string) ($thread['priority'] ?? '') === 'high') ? 'Customer risk' : null,
        ])),
        'recommended_actions' => [
            mobileAiAction('create_follow_up_task', 'Create follow-up task', 'one_tap', ['conversation_id' => $conversationId], 'Create a follow-up task from this thread.', true, '/inbox/' . $conversationId),
            mobileAiAction('save_contact_note', 'Save AI note', 'one_tap', ['conversation_id' => $conversationId], 'Save a concise AI-generated note to the linked contact.', true, '/inbox/' . $conversationId),
            mobileAiAction('save_draft_reply', 'Use suggested reply', 'draft', ['conversation_id' => $conversationId], 'Load the AI draft into the reply composer.', false, '/inbox/' . $conversationId),
        ],
    ];
    })();

    $cache->set($cacheKey, $result, 900);
    mobileAiFileCacheSet($cacheKey, $result, 900);
    return $result;
}

function mobileAiConversationLatestMessageId(array $communication, ?array $thread): int
{
    $workspaceScope = new WorkspaceScopeService();
    $communicationWorkspace = $workspaceScope->workspaceClause();
    if (!empty($thread['thread_key'])) {
        $latest = Database::queryOne(
            "SELECT MAX(id) AS latest_id
             FROM communications
             WHERE {$communicationWorkspace['sql']}
               AND thread_key = ?",
            array_merge($communicationWorkspace['params'], [(string) $thread['thread_key']])
        );
        return (int) ($latest['latest_id'] ?? 0);
    }

    $contactId = (int) ($communication['contact_id'] ?? 0);
    $channel = trim((string) ($communication['channel'] ?? ''));
    if ($contactId > 0 && $channel !== '') {
        $latest = Database::queryOne(
            "SELECT MAX(id) AS latest_id
             FROM communications
             WHERE {$communicationWorkspace['sql']}
               AND contact_id = ?
               AND channel = ?",
            array_merge($communicationWorkspace['params'], [$contactId, $channel])
        );
        return (int) ($latest['latest_id'] ?? 0);
    }

    return (int) ($communication['id'] ?? 0);
}

function mobileAiFileCacheGet(string $cacheKey, int $ttlSeconds): ?array
{
    $path = mobileAiFileCachePath($cacheKey);
    if (!is_file($path)) {
        return null;
    }

    $raw = @file_get_contents($path);
    if ($raw === false || trim($raw) === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return null;
    }

    $cachedAt = strtotime((string) ($decoded['cached_at'] ?? ''));
    if ($cachedAt === false || (time() - $cachedAt) > $ttlSeconds) {
        @unlink($path);
        return null;
    }

    $payload = $decoded['payload'] ?? null;
    return is_array($payload) ? $payload : null;
}

function mobileAiFileCacheSet(string $cacheKey, array $payload, int $ttlSeconds): void
{
    $path = mobileAiFileCachePath($cacheKey);
    $directory = dirname($path);
    if (!is_dir($directory)) {
        @mkdir($directory, 0777, true);
    }

    @file_put_contents($path, json_encode([
        'cached_at' => gmdate('c'),
        'ttl_seconds' => $ttlSeconds,
        'payload' => $payload,
    ]));
}

function mobileAiFileCachePath(string $cacheKey): string
{
    $root = dirname(__DIR__, 3);
    return $root . DIRECTORY_SEPARATOR . 'cache'
        . DIRECTORY_SEPARATOR . 'mobile_ai'
        . DIRECTORY_SEPARATOR . sha1($cacheKey) . '.json';
}

function mobileAiDealInsights(int $dealId, int $userId): array
{
    $intelligence = new AIDealIntelligence();
    $dealSummary = '';
    $nextSteps = [];
    $prediction = null;

    try {
        $dealSummary = trim($intelligence->getDealSummary($dealId, $userId));
    } catch (Throwable $e) {
        $dealSummary = '';
    }
    try {
        $nextSteps = (array) ($intelligence->getNextSteps($dealId, $userId)['next_steps'] ?? []);
    } catch (Throwable $e) {
        $nextSteps = [];
    }
    try {
        $prediction = $intelligence->predictCloseDate($dealId, $userId);
    } catch (Throwable $e) {
        $prediction = null;
    }

    $deal = (new Deals())->getById($dealId) ?? [];
    return [
        'deal_health' => $dealSummary !== '' ? $dealSummary : 'Review the latest notes and confirm the next buyer commitment.',
        'likely_blocker' => !empty($nextSteps) ? 'The next concrete buyer action is not yet locked.' : 'More context is needed to identify a blocker.',
        'suggested_next_step' => !empty($nextSteps) ? (string) (($nextSteps[0]['action'] ?? $nextSteps[0]['title'] ?? 'Confirm the next action.')) : 'Confirm the next action with the contact.',
        'recommended_stage_change' => [
            'current_stage' => (string) ($deal['stage'] ?? ''),
            'suggested_stage' => (string) ($deal['stage'] ?? ''),
            'reason' => 'Review the stage after confirming the next committed action.',
        ],
        'generate_update_note' => 'Capture the buyer state, blocker, and agreed next step before the next check-in.',
        'close_prediction' => $prediction,
        'recommended_actions' => [
            mobileAiAction('update_deal_description', 'Apply summary to notes', 'one_tap', ['deal_id' => $dealId], 'Add an AI-assisted update to the deal description.', true, '/deals/' . $dealId),
            mobileAiAction('update_deal_stage', 'Review stage change', 'review_required', ['deal_id' => $dealId, 'stage' => (string) ($deal['stage'] ?? '')], 'Stage updates require confirmation.', true, '/deals/' . $dealId),
        ],
    ];
}

function mobileAiContactInsights(int $contactId): array
{
    $summarizer = new AIContactSummarizer();
    $workspaceScope = new WorkspaceScopeService();
    $activityWorkspace = $workspaceScope->workspaceClause();
    $summary = [];
    try {
        $summary = $summarizer->generateSummary($contactId);
    } catch (Throwable $e) {
        $summary = [];
    }

    $contact = (new Contacts())->getById($contactId) ?? [];
    $recentActivity = Database::queryOne(
        "SELECT description, created_at
         FROM activities
         WHERE {$activityWorkspace['sql']}
           AND contact_id = ?
         ORDER BY created_at DESC
         LIMIT 1",
        array_merge($activityWorkspace['params'], [$contactId])
    );

    return [
        'relationship_summary' => (string) ($summary['summary'] ?? 'AI relationship summary is not ready yet.'),
        'last_meaningful_activity' => (string) ($recentActivity['description'] ?? 'No recent activity logged.'),
        'recommended_next_outreach' => 'Reach out with a focused update or question tied to their latest activity.',
        'profile_gaps' => array_values(array_filter([
            empty($contact['job_title']) ? 'Job title missing' : null,
            empty($contact['company']) ? 'Company missing' : null,
            empty($contact['phone']) ? 'Phone missing' : null,
        ])),
    ];
}

function mobileAiTaskInsights(int $taskId): array
{
    $task = (new Tasks())->getById($taskId) ?? [];
    $description = trim((string) ($task['description'] ?? ''));
    $subtasks = [];
    try {
        $response = (new AIService())->process('action_item_extraction', [
            'text' => $description !== '' ? $description : (string) ($task['title'] ?? 'Task'),
        ]);
        $decoded = json_decode($response, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            $subtasks = array_values(array_filter((array) ($decoded['action_items'] ?? $decoded['subtasks'] ?? [])));
        }
    } catch (Throwable $e) {
        $subtasks = [];
    }

    if ($subtasks === []) {
        $subtasks = ['Clarify the deliverable.', 'Complete the core work.', 'Log the outcome in the CRM.'];
    }

    return [
        'why_this_matters' => 'This task affects customer momentum, pipeline progress, or queue reliability.',
        'completion_plan' => 'Finish the task, capture the outcome, and update the next dependency immediately.',
        'suggested_subtasks' => $subtasks,
        'reprioritize_reason' => !empty($task['due_date']) ? 'Due date pressure suggests this should stay visible.' : 'Priority can be revisited once the next outcome is clear.',
    ];
}
