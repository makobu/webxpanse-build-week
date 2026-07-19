<?php

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/_helpers.php';

use CRM\Authorization;
use CRM\Database;
use CRM\Modules\Deals;
use CRM\Modules\Notifications;
use CRM\Modules\Tasks;
use CRM\Modules\UnifiedInbox;
use CRM\Services\AIOperatingContextService;
use CRM\Services\AIExecutionStatusService;
use CRM\Services\AIService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceLanguageLevelService;
use CRM\Services\OrganizationIntelligenceContextService;
use CRM\Services\OrganizationIntelligenceConversationService;
use CRM\Services\OrganizationIntelligenceSnapshotService;

$auth = mobileRequireAuth();
$user = Database::queryOne('SELECT * FROM users WHERE id = ? LIMIT 1', [(int) $auth['user_id']]) ?? [];
$userId = (int) $auth['user_id'];
$input = mobileRequestBody();
$question = trim((string) ($input['question'] ?? ''));
$surface = strtolower(trim((string) ($input['surface'] ?? 'clarity_chat')));
if ($question === '') {
    mobileJson(['error' => 'Question is required.'], 422);
}

$inbox = new UnifiedInbox();
$tasks = new Tasks();
$deals = new Deals();
$notifications = new Notifications();
$conversationOwnerScope = mobileResolveConversationOwnerScope($user, $input['owner_scope'] ?? $_GET['owner_scope'] ?? null);
$workspaceId = mobileWorkspaceId($auth);
$organizationConversation = null;
$organizationMessageId = null;
$organizationSnapshotId = null;
$organizationScope = [];
$operatingContext = [];
try {
    $operatingContext = (new AIOperatingContextService())->buildForSurface($userId, 'clarity_chat', [
        'current_page' => 'mobile',
    ]);
} catch (Throwable $e) {
    $operatingContext = [
        'ai_settings' => [
            'response_style_contract' => (new WorkspaceLanguageLevelService())->currentResponseStyleContract($userId),
        ],
    ];
}
$responseStyleContract = (array) ($operatingContext['ai_settings']['response_style_contract'] ?? []);
$context = [
    'user' => [
        'id' => $userId,
        'role' => (string) ($user['role'] ?? ''),
        'name' => trim((string) (($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''))),
    ],
    'summary' => [
        'unread_conversations' => $inbox->getCount([
            'status' => 'unread',
            'viewer_user_id' => $userId,
            'can_view_all_conversations' => Authorization::can('conversations.view_all', $user),
            'owner_scope' => $conversationOwnerScope,
        ]),
        'overdue_tasks' => $tasks->getCount(['assigned_to' => $userId, 'overdue' => true]),
        'open_deals' => $deals->getCount(['assigned_to' => $userId, 'exclude_stages' => ['closed_won', 'closed_lost']]),
        'unread_notifications' => $notifications->getUnreadCount($userId),
    ],
    'recommended_prompts' => [
        'What should I do next?',
        'Summarize my inbox',
        'Which deals are at risk?',
        'What follow-ups matter most?',
    ],
    'response_style_contract' => [
        'level' => (string) ($responseStyleContract['level'] ?? ''),
        'label' => (string) ($responseStyleContract['label'] ?? ''),
        'description' => (string) ($responseStyleContract['description'] ?? ''),
    ],
];

if ($surface === 'organization_intelligence') {
    mobileRequireAnyPermission($user, ['hr.analytics.view'], 'Organization Intelligence is not available to this user.');
    $organizationResult = (new OrganizationIntelligenceContextService())->build($workspaceId, $userId, [
        'room' => (string) ($input['room'] ?? 'brief'),
        'sub_room' => (string) ($input['sub_room'] ?? ''),
        'timeframe' => (string) ($input['timeframe'] ?? 'month'),
        'role' => (string) ($input['role'] ?? ''),
        'department' => (string) ($input['department'] ?? ''),
        'user_id' => !empty($input['user_id']) ? (int) $input['user_id'] : null,
    ]);
    $organizationScope = (array) ($organizationResult['filters'] ?? []);
    $organizationContext = (array) ($organizationResult['context'] ?? []);
    $conversationService = new OrganizationIntelligenceConversationService();
    $organizationConversation = $conversationService->currentOrCreate(
        $workspaceId,
        $userId,
        !empty($input['conversation_id']) ? (int) $input['conversation_id'] : null
    );
    $snapshot = (new OrganizationIntelligenceSnapshotService())->latest($workspaceId);
    $organizationSnapshotId = !empty($snapshot['id']) ? (int) $snapshot['id'] : null;
    $conversationId = (int) ($organizationConversation['id'] ?? 0);
    $room = (string) ($organizationResult['room'] ?? 'brief');
    $subRoom = (string) ($organizationResult['sub_room'] ?? '');
    $conversationService->appendScopeDividerIfChanged($conversationId, $workspaceId, $userId, $room, $subRoom, $organizationScope, $organizationSnapshotId);
    $conversationService->append($conversationId, $workspaceId, $userId, 'user', $question, $room, $subRoom, $organizationScope, $organizationSnapshotId);
    $context = [
        'organization_intelligence' => $organizationContext,
        'conversation_summary' => (string) ($organizationConversation['rolling_summary'] ?? ''),
        'conversation_history' => $conversationService->history($conversationId, $workspaceId, $userId, 20),
        'response_style_contract' => $context['response_style_contract'],
        'recommended_prompts' => [
            'What matters most in this room?',
            'Where is evidence still too thin?',
            'What should leadership do next?',
        ],
    ];
    $operatingContext['organization_intelligence_page_context'] = $organizationContext;
    $operatingContext['organization_intelligence_conversation_history'] = $context['conversation_history'];
}

$answer = '';
$aiService = new AIService();
try {
    $answer = trim($aiService->process('website_assistant_question', [
        'question' => $question,
        'context' => $context,
        'operating_context' => $operatingContext,
        'text' => json_encode($context, JSON_PRETTY_PRINT),
    ], [
        'surface' => 'clarity_chat',
        'workspace_id' => $workspaceId,
        'user_id' => $userId,
    ]));
} catch (Throwable $e) {
    $answer = '';
}
$usedFallback = $answer === '';
if ($surface === 'organization_intelligence') {
    (new CRM\Services\OrganizationIntelligenceMonitoringService())->recordSignal(
        $workspaceId,
        'clarity_ai',
        $usedFallback ? 'oi_ai_fallback' : '',
        $userId
    );
}
if ($answer === '') {
    $answer = $surface === 'organization_intelligence'
        ? 'I could not reach live AI. Review the server-verified priorities and evidence coverage before choosing the next leadership action.'
        : 'Focus first on the highest-risk follow-up, then clear overdue work, then push the next deal action forward.';
}
$answer = function_exists('mobileAiWhiteLabelText')
    ? mobileAiWhiteLabelText($answer)
    : $answer;

if ($surface === 'organization_intelligence' && !empty($organizationConversation['id'])) {
    $organizationMessageId = (new OrganizationIntelligenceConversationService())->append(
        (int) $organizationConversation['id'],
        $workspaceId,
        $userId,
        'assistant',
        $answer,
        (string) ($input['room'] ?? 'brief'),
        (string) ($input['sub_room'] ?? ''),
        $organizationScope,
        $organizationSnapshotId
    );
}

mobileJson([
    'success' => true,
    'data' => [
        'answer' => $answer,
        'surface' => $surface,
        'conversation_id' => !empty($organizationConversation['id']) ? (int) $organizationConversation['id'] : null,
        'message_id' => $organizationMessageId,
        'context_snapshot_id' => $organizationSnapshotId,
        'recommended_prompts' => $context['recommended_prompts'],
        'context_summary' => $context['summary'],
        'conversation_owner_scope' => $conversationOwnerScope,
        'language_level' => (string) ($responseStyleContract['label'] ?? ''),
        'ai_status' => (new AIExecutionStatusService())->present($aiService->getLastProviderStatus(), [
            'surface' => 'clarity_chat',
            'fallback' => $usedFallback,
            'source' => $usedFallback ? 'deterministic_fallback' : '',
            'message' => $usedFallback
                ? 'Clarity used safe workspace guidance because live AI was unavailable.'
                : '',
        ]),
    ],
]);
