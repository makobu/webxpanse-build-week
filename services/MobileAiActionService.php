<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\Notes;
use CRM\Modules\Tasks;

class MobileAiActionService
{
    private Tasks $tasks;
    private Notes $notes;

    public function __construct(?Tasks $tasks = null, ?Notes $notes = null)
    {
        $this->tasks = $tasks ?? new Tasks();
        $this->notes = $notes ?? new Notes();
    }

    public function createFollowUpTask(int $conversationId, int $userId): array
    {
        $conversation = $this->getConversation($conversationId);
        $contactId = (int) ($conversation['contact_id'] ?? 0);
        $contactName = trim((string) ($conversation['contact_name'] ?? ''));

        $taskId = $this->tasks->create([
            'title' => 'AI follow-up: ' . ($contactName !== '' ? $contactName : 'conversation'),
            'description' => 'AI-assisted follow-up created from mobile conversation review.',
            'contact_id' => $contactId > 0 ? $contactId : null,
            'created_by' => $userId,
            'assigned_to' => $userId,
            'status' => 'pending',
            'priority' => 'high',
            'origin_type' => 'ai',
            'completion_mode' => 'review',
            'automation_dedupe_key' => 'ai_mobile:conversation:' . $conversationId . ':follow_up',
            'metadata_json' => [
                'source_surface' => 'ai_mobile',
                'ai_assisted' => true,
                'conversation_id' => $conversationId,
                'completion_evidence_types' => ['email_reply_received'],
            ],
        ]);

        return [
            'task_id' => $taskId,
            'task' => $this->tasks->getById($taskId) ?? [],
        ];
    }

    public function saveAiNote(int $conversationId, int $userId, string $content): array
    {
        $conversation = $this->getConversation($conversationId);
        $contactId = (int) ($conversation['contact_id'] ?? 0);
        if ($contactId <= 0) {
            throw new \RuntimeException('This conversation is not linked to a contact.', 422);
        }

        $noteId = $this->notes->create([
            'entity_type' => 'contact',
            'entity_id' => $contactId,
            'title' => 'AI mobile note',
            'content' => $content !== '' ? $content : 'Conversation summary unavailable.',
            'created_by' => $userId,
        ]);

        return [
            'contact_id' => $contactId,
            'note_id' => $noteId,
            'note' => $this->notes->getById($noteId) ?? [],
        ];
    }

    private function getConversation(int $conversationId): array
    {
        $conversation = Database::queryOne(
            "SELECT c.id, c.contact_id,
                    TRIM(CONCAT(COALESCE(ct.first_name, ''), ' ', COALESCE(ct.last_name, ''))) AS contact_name
             FROM communications c
             LEFT JOIN contacts ct ON ct.id = c.contact_id
             WHERE c.id = ?
             LIMIT 1",
            [$conversationId]
        ) ?: [];

        if ($conversation === []) {
            throw new \RuntimeException('Conversation not found.', 404);
        }

        return $conversation;
    }
}
