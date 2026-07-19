<?php

namespace CRM\Services;

use CRM\Authorization;
use CRM\Database;
use CRM\Modules\DraftReview;
use CRM\Modules\UnifiedInbox;

class MobileConversationDraftService
{
    private UnifiedInbox $inbox;
    private CustomerReplyAssistantService $replyAssistant;
    private DraftReview $drafts;

    public function __construct(
        ?UnifiedInbox $inbox = null,
        ?CustomerReplyAssistantService $replyAssistant = null,
        ?DraftReview $drafts = null
    ) {
        $this->inbox = $inbox ?? new UnifiedInbox();
        $this->replyAssistant = $replyAssistant ?? new CustomerReplyAssistantService();
        $this->drafts = $drafts ?? new DraftReview();
    }

    /**
     * @param array<string,mixed> $user
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function generate(int $userId, array $user, array $input): array
    {
        $conversationId = (int) ($input['conversation_id'] ?? $input['communication_id'] ?? 0);
        $contactId = (int) ($input['contact_id'] ?? 0);
        $channel = $this->normalizeChannel((string) ($input['channel'] ?? 'email'));
        $ownerScope = (string) ($input['owner_scope'] ?? 'mine_unassigned');

        if ($conversationId > 0) {
            $this->assertConversationAccess($conversationId, $userId, $user, $ownerScope);
            $communication = $this->communication($conversationId);
            if ($communication) {
                $channel = $this->normalizeChannel((string) ($communication['channel'] ?? $channel));
                $contactId = (int) ($communication['contact_id'] ?? $contactId);
            }
            $result = $this->replyAssistant->generateDraftFromCommunication($conversationId, $userId, [
                'channel' => $channel,
                'surface' => 'mobile',
                'tone' => (string) ($input['tone'] ?? ''),
                'purpose' => (string) ($input['purpose'] ?? 'reply'),
                'goal' => (string) ($input['goal'] ?? 'draft'),
            ]);
        } elseif ($contactId > 0) {
            $this->assertContactAccess($contactId, $userId, $user);
            $result = $this->replyAssistant->generateDraftFromContact($contactId, $channel, $userId, [
                'surface' => 'mobile',
                'tone' => (string) ($input['tone'] ?? ''),
                'purpose' => (string) ($input['purpose'] ?? 'reply'),
                'goal' => (string) ($input['goal'] ?? 'draft'),
            ]);
        } else {
            throw new \RuntimeException('Conversation or contact id is required.', 422);
        }

        $draft = $this->normalizedDraftPayload($result, $channel, $contactId, $conversationId);
        $save = filter_var($input['save'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($save) {
            $draft['draft_id'] = $this->saveDraft($userId, $draft);
            $draft['status'] = 'draft';
        }

        return [
            'draft' => $draft,
            'source' => (string) ($result['source'] ?? 'assistant'),
            'summary_text' => (string) ($result['summary_text'] ?? ''),
            'policy' => (array) ($result['policy'] ?? []),
            'fallback' => !empty($result['fallback']),
            'ai_status' => (new AIExecutionStatusService())->present([], [
                'surface' => 'customer_thread',
                'success' => empty($result['fallback']),
                'fallback' => !empty($result['fallback']),
                'source' => (string) ($result['source'] ?? 'assistant'),
                'message' => (string) ($result['summary_text'] ?? ''),
            ]),
        ];
    }

    /**
     * @param array<string,mixed> $user
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function saveManual(int $userId, array $user, array $input): array
    {
        $conversationId = (int) ($input['conversation_id'] ?? $input['communication_id'] ?? 0);
        $contactId = (int) ($input['contact_id'] ?? 0);
        $channel = $this->normalizeChannel((string) ($input['channel'] ?? 'email'));
        $ownerScope = (string) ($input['owner_scope'] ?? 'mine_unassigned');
        $body = trim((string) ($input['body'] ?? ''));

        if ($body === '') {
            throw new \RuntimeException('Draft body is required.', 422);
        }

        if ($conversationId > 0) {
            $this->assertConversationAccess($conversationId, $userId, $user, $ownerScope);
            $communication = $this->communication($conversationId);
            if ($communication) {
                $channel = $this->normalizeChannel((string) ($communication['channel'] ?? $channel));
                $contactId = (int) ($communication['contact_id'] ?? $contactId);
            }
        } elseif ($contactId > 0) {
            $this->assertContactAccess($contactId, $userId, $user);
        } else {
            throw new \RuntimeException('Conversation or contact id is required.', 422);
        }

        $draft = [
            'draft_id' => null,
            'conversation_id' => $conversationId > 0 ? $conversationId : null,
            'communication_id' => $conversationId > 0 ? $conversationId : null,
            'contact_id' => $contactId > 0 ? $contactId : null,
            'channel' => $channel,
            'subject' => trim((string) ($input['subject'] ?? '')),
            'body' => $body,
            'html_body' => nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8')),
            'tone' => trim((string) ($input['tone'] ?? '')) ?: ($channel === 'whatsapp' ? 'casual' : 'professional'),
            'status' => 'draft',
            'generated_at' => gmdate('c'),
        ];
        $draft['draft_id'] = $this->saveDraft($userId, $draft);

        return [
            'draft' => $draft,
            'source' => 'manual',
            'summary_text' => 'Mobile draft saved.',
            'policy' => [],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function list(int $userId, ?string $status = 'draft'): array
    {
        return array_map([$this, 'serializeDraft'], $this->drafts->getUserDrafts($userId, $status));
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function update(int $draftId, int $userId, array $input): array
    {
        $draft = $this->requireUserDraft($draftId, $userId);
        $this->drafts->updateDraft($draftId, [
            'subject' => (string) ($input['subject'] ?? $draft['subject'] ?? ''),
            'body' => (string) ($input['body'] ?? $draft['body'] ?? ''),
            'tone' => (string) ($input['tone'] ?? $draft['tone'] ?? 'professional'),
        ]);

        return $this->serializeDraft($this->drafts->getById($draftId) ?? []);
    }

    public function delete(int $draftId, int $userId): void
    {
        $this->requireUserDraft($draftId, $userId);
        $this->drafts->deleteDraft($draftId);
    }

    /**
     * @param array<string,mixed> $user
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function send(int $draftId, int $conversationId, int $userId, array $user, array $input): array
    {
        if ($conversationId <= 0) {
            throw new \RuntimeException('Conversation id is required to send a saved draft.', 422);
        }

        $draft = $this->requireUserDraft($draftId, $userId);
        $ownerScope = (string) ($input['owner_scope'] ?? 'mine_unassigned');
        $this->assertConversationAccess($conversationId, $userId, $user, $ownerScope);
        $communication = $this->communication($conversationId);
        if (!$communication) {
            throw new \RuntimeException('Conversation not found.', 404);
        }

        $body = trim((string) ($input['body'] ?? $draft['body'] ?? ''));
        if ($body === '') {
            throw new \RuntimeException('Draft body is required.', 422);
        }

        $channel = $this->normalizeChannel((string) ($communication['channel'] ?? $draft['draft_type'] ?? 'email'));
        $workspaceId = (int) ($communication['workspace_id'] ?? 0);
        $communicationGate = new WorkspaceCommunicationGateService();
        if (!$communicationGate->isChannelRuntimeReady($workspaceId, $channel, $user)) {
            $status = $communicationGate->channelStatus($workspaceId, $channel, $user);
            $message = !empty($status['can_manage'])
                ? (string) ($status['message'] ?? 'Communication channel setup is required.')
                : (string) ($status['owner_message'] ?? 'Communication channel setup is required.');
            throw new \RuntimeException($message, 403);
        }
        if ($channel === 'email') {
            $result = (new ConversationEmailReplyService())->sendReply(
                $conversationId,
                $userId,
                $body,
                trim((string) ($input['subject'] ?? $draft['subject'] ?? '')) ?: null,
                'mobile_draft'
            );
        } elseif ($channel === 'whatsapp') {
            $result = $this->sendWhatsAppReply($communication, $body, $userId);
        } else {
            throw new \RuntimeException('This channel does not support mobile draft sending yet.', 422);
        }

        Database::execute(
            "UPDATE draft_reviews
             SET status = 'sent', reviewed_by = ?, reviewed_at = NOW()
             WHERE id = ? AND workspace_id = ?",
            [$userId, $draftId, (int) ($draft['workspace_id'] ?? 0)]
        );

        return [
            'draft' => $this->serializeDraft($this->drafts->getById($draftId) ?? []),
            'send_result' => $result,
        ];
    }

    /**
     * @param array<string,mixed> $draft
     */
    private function saveDraft(int $userId, array $draft): int
    {
        return $this->drafts->createDraft([
            'draft_type' => (string) ($draft['channel'] ?? 'email'),
            'contact_id' => !empty($draft['contact_id']) ? (int) $draft['contact_id'] : null,
            'subject' => (string) ($draft['subject'] ?? ''),
            'body' => (string) ($draft['body'] ?? ''),
            'original_body' => (string) ($draft['body'] ?? ''),
            'tone' => (string) ($draft['tone'] ?? 'professional'),
            'created_by' => $userId,
        ]);
    }

    /**
     * @param array<string,mixed> $result
     * @return array<string,mixed>
     */
    private function normalizedDraftPayload(array $result, string $channel, int $contactId, int $conversationId): array
    {
        $compat = (array) ($result['compat'] ?? []);
        $draft = (array) ($result['draft'] ?? []);
        $body = trim((string) ($compat['body'] ?? $draft['plain_body'] ?? $draft['body'] ?? ''));
        if ($body === '') {
            $body = trim(strip_tags((string) ($compat['html_body'] ?? $draft['html_body'] ?? '')));
        }

        return [
            'draft_id' => null,
            'conversation_id' => $conversationId > 0 ? $conversationId : null,
            'communication_id' => $conversationId > 0 ? $conversationId : null,
            'contact_id' => $contactId > 0 ? $contactId : (int) ($result['contact_id'] ?? 0),
            'channel' => $channel,
            'subject' => (string) ($compat['subject'] ?? $draft['subject'] ?? ''),
            'body' => $body,
            'html_body' => (string) ($compat['html_body'] ?? $draft['html_body'] ?? ''),
            'tone' => (string) ($draft['tone'] ?? ($channel === 'whatsapp' ? 'casual' : 'professional')),
            'status' => 'generated',
            'generated_at' => gmdate('c'),
        ];
    }

    /**
     * @param array<string,mixed> $draft
     * @return array<string,mixed>
     */
    private function serializeDraft(array $draft): array
    {
        return [
            'id' => (int) ($draft['id'] ?? 0),
            'draft_id' => (int) ($draft['id'] ?? 0),
            'channel' => (string) ($draft['draft_type'] ?? $draft['channel'] ?? ''),
            'draft_type' => (string) ($draft['draft_type'] ?? ''),
            'contact_id' => !empty($draft['contact_id']) ? (int) $draft['contact_id'] : null,
            'contact_name' => trim((string) (($draft['contact_first_name'] ?? '') . ' ' . ($draft['contact_last_name'] ?? ''))),
            'contact_email' => (string) ($draft['contact_email'] ?? ''),
            'subject' => (string) ($draft['subject'] ?? ''),
            'body' => (string) ($draft['body'] ?? ''),
            'original_body' => (string) ($draft['original_body'] ?? ''),
            'tone' => (string) ($draft['tone'] ?? ''),
            'status' => (string) ($draft['status'] ?? ''),
            'created_by' => !empty($draft['created_by']) ? (int) $draft['created_by'] : null,
            'created_at' => (string) ($draft['created_at'] ?? ''),
            'updated_at' => (string) ($draft['updated_at'] ?? ''),
        ];
    }

    /**
     * @param array<string,mixed> $user
     */
    private function assertConversationAccess(int $conversationId, int $userId, array $user, string $ownerScope): void
    {
        $canViewAll = Authorization::can('conversations.view_all', $user);
        if (!$this->inbox->canUserAccessCommunication($conversationId, $userId, $canViewAll, $ownerScope)) {
            throw new \RuntimeException('Conversation not found or not accessible.', 404);
        }
    }

    /**
     * @param array<string,mixed> $user
     */
    private function assertContactAccess(int $contactId, int $userId, array $user): void
    {
        $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        $contact = Database::queryOne(
            "SELECT id, assigned_to
             FROM contacts
             WHERE workspace_id = ? AND id = ?
             LIMIT 1",
            [$workspaceId, $contactId]
        );
        if (!$contact) {
            throw new \RuntimeException('Contact not found or not accessible.', 404);
        }
        $assignedTo = (int) ($contact['assigned_to'] ?? 0);
        if ($assignedTo !== 0 && $assignedTo !== $userId && !Authorization::can('contacts.view_all', $user)) {
            throw new \RuntimeException('Contact not found or not accessible.', 404);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function requireUserDraft(int $draftId, int $userId): array
    {
        $draft = $this->drafts->getById($draftId);
        if (!$draft || (int) ($draft['created_by'] ?? 0) !== $userId) {
            throw new \RuntimeException('Draft not found or not accessible.', 404);
        }

        return $draft;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function communication(int $communicationId): ?array
    {
        $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        return Database::queryOne(
            "SELECT c.*, ct.email AS contact_email, ct.phone AS contact_phone
             FROM communications c
             LEFT JOIN contacts ct ON ct.id = c.contact_id AND ct.workspace_id = c.workspace_id
             WHERE c.workspace_id = ?
               AND c.id = ?
             LIMIT 1",
            [$workspaceId, $communicationId]
        ) ?: null;
    }

    /**
     * @param array<string,mixed> $communication
     * @return array<string,mixed>
     */
    private function sendWhatsAppReply(array $communication, string $body, int $userId): array
    {
        $contactId = (int) ($communication['contact_id'] ?? 0);
        if ($contactId <= 0) {
            throw new \RuntimeException('A linked contact is required for WhatsApp replies.', 422);
        }

        $whatsApp = new WhatsAppService();
        if (!$whatsApp->isWithin24HourWindow($contactId)) {
            throw new \RuntimeException("Custom messages can only be sent within 24 hours of the customer's last message. The 24-hour window is closed.", 422);
        }

        $to = $whatsApp->normalizePhoneNumber((string) ($communication['contact_phone'] ?? ''));
        if ($to === '') {
            throw new \RuntimeException('A valid contact phone number is required to send this WhatsApp reply.', 422);
        }

        $result = $whatsApp->sendTextMessage($to, $body);
        $whatsappMessageId = $result['messages'][0]['id'] ?? null;
        $whatsApp->storeMessage($contactId, $to, 'text', $body, [
            'user_id' => $userId,
            'whatsapp_message_id' => $whatsappMessageId,
        ]);

        return $result;
    }

    private function normalizeChannel(string $channel): string
    {
        $channel = strtolower(trim($channel));
        return in_array($channel, ['email', 'whatsapp'], true) ? $channel : 'email';
    }
}
