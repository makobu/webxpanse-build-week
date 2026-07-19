<?php

require_once __DIR__ . '/../../_bootstrap.php';
require_once __DIR__ . '/../../_conversation_detail.php';

use CRM\Modules\Deals;
use CRM\Services\MobileAiActionService;
use CRM\Services\MobileConversationDraftService;

$auth = mobileRequireAuth();
$userId = (int) $auth['user_id'];
$input = mobileRequestBody();
$actionKey = trim((string) ($input['action_key'] ?? ''));
if ($actionKey === '') {
    mobileJson(['error' => 'Action key is required.'], 422);
}

switch ($actionKey) {
    case 'save_draft_reply':
        $conversationId = (int) ($input['conversation_id'] ?? 0);
        if ($conversationId <= 0) {
            mobileJson(['error' => 'Conversation id is required.'], 422);
        }
        try {
            $result = (new MobileConversationDraftService())->generate($userId, $user, [
                'conversation_id' => $conversationId,
                'save' => true,
                'owner_scope' => mobileResolveConversationOwnerScope($user, $input['owner_scope'] ?? $_GET['owner_scope'] ?? null),
            ]);
        } catch (\RuntimeException $e) {
            $status = (int) $e->getCode();
            mobileJson(['error' => $e->getMessage()], $status >= 400 && $status <= 599 ? $status : 422);
        }
        mobileJson([
            'success' => true,
            'message' => 'Draft saved.',
            'data' => [
                'status' => 'draft_saved',
                'ai_assisted' => true,
                'result_route' => '/inbox/' . $conversationId,
                'draft' => $result['draft'],
            ],
        ]);

    case 'create_follow_up_task':
        $conversationId = (int) ($input['conversation_id'] ?? 0);
        if ($conversationId <= 0) {
            mobileJson(['error' => 'Conversation id is required.'], 422);
        }
        try {
            $result = (new MobileAiActionService())->createFollowUpTask($conversationId, $userId);
        } catch (\RuntimeException $e) {
            $status = (int) $e->getCode();
            mobileJson(['error' => $e->getMessage()], $status >= 400 && $status <= 599 ? $status : 422);
        }
        $taskId = (int) ($result['task_id'] ?? 0);
        mobileJson([
            'success' => true,
            'message' => 'Follow-up task created.',
            'data' => [
                'status' => 'applied',
                'ai_assisted' => true,
                'result_route' => '/tasks/' . $taskId,
                'task' => mobileTaskSummary((array) ($result['task'] ?? [])),
            ],
        ]);

    case 'save_contact_note':
        $conversationId = (int) ($input['conversation_id'] ?? 0);
        if ($conversationId <= 0) {
            mobileJson(['error' => 'Conversation id is required.'], 422);
        }
        $insights = mobileAiConversationInsights($conversationId, $userId);
        try {
            $result = (new MobileAiActionService())->saveAiNote(
                $conversationId,
                $userId,
                '[AI assisted mobile]' . "\n" . trim((string) ($insights['thread_summary'] ?? 'Conversation summary unavailable.'))
            );
        } catch (\RuntimeException $e) {
            $status = (int) $e->getCode();
            mobileJson(['error' => $e->getMessage()], $status >= 400 && $status <= 599 ? $status : 422);
        }
        $contactId = (int) ($result['contact_id'] ?? 0);
        mobileJson([
            'success' => true,
            'message' => 'AI note saved.',
            'data' => [
                'status' => 'applied',
                'ai_assisted' => true,
                'result_route' => '/contacts/' . $contactId,
                'note' => mobileNoteSummary((array) ($result['note'] ?? [])),
            ],
        ]);

    case 'update_deal_description':
        $dealId = (int) ($input['deal_id'] ?? 0);
        if ($dealId <= 0) {
            mobileJson(['error' => 'Deal id is required.'], 422);
        }
        $deals = new Deals();
        $deal = $deals->getById($dealId);
        if (!$deal || !in_array($userId, array_filter([(int) ($deal['assigned_to'] ?? 0), (int) ($deal['created_by'] ?? 0)]), true)) {
            mobileJson(['error' => 'Deal not found or not accessible.'], 404);
        }
        $insights = mobileAiDealInsights($dealId, $userId);
        $summary = trim((string) ($insights['deal_health'] ?? ''));
        $nextStep = trim((string) ($insights['suggested_next_step'] ?? ''));
        $addition = "\n\n[AI assisted mobile]\nSummary: {$summary}\nNext step: {$nextStep}";
        $deals->update($dealId, [
            'description' => trim((string) ($deal['description'] ?? '')) . $addition,
        ]);
        mobileJson([
            'success' => true,
            'data' => [
                'status' => 'applied',
                'ai_assisted' => true,
                'result_route' => '/deals/' . $dealId,
                'deal' => mobileDealSummary($deals->getById($dealId) ?? []),
            ],
        ]);

    case 'update_deal_stage':
        $dealId = (int) ($input['deal_id'] ?? 0);
        $stage = trim((string) ($input['stage'] ?? ''));
        $confirmed = !empty($input['confirm']);
        if ($dealId <= 0 || $stage === '') {
            mobileJson(['error' => 'Deal id and stage are required.'], 422);
        }
        if (!$confirmed) {
            mobileJson([
                'success' => true,
                'data' => [
                    'status' => 'review_required',
                    'requires_review' => true,
                    'result_route' => '/deals/' . $dealId,
                    'message' => 'Stage changes require explicit confirmation.',
                ],
            ]);
        }
        $deals = new Deals();
        $deal = $deals->getById($dealId);
        if (!$deal || !in_array($userId, array_filter([(int) ($deal['assigned_to'] ?? 0), (int) ($deal['created_by'] ?? 0)]), true)) {
            mobileJson(['error' => 'Deal not found or not accessible.'], 404);
        }
        $deals->update($dealId, ['stage' => $stage]);
        mobileJson([
            'success' => true,
            'data' => [
                'status' => 'applied',
                'ai_assisted' => true,
                'result_route' => '/deals/' . $dealId,
                'deal' => mobileDealSummary($deals->getById($dealId) ?? []),
            ],
        ]);

    default:
        mobileJson(['error' => 'Unsupported AI action.'], 422);
}
