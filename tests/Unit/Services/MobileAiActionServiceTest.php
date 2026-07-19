<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\MobileAiActionService;
use CRM\Tests\DatabaseTestCase;

class MobileAiActionServiceTest extends DatabaseTestCase
{
    private int $userId;
    private int $contactId;
    private int $conversationId;

    protected function setUp(): void
    {
        parent::setUp();

        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, 'admin', NOW())",
            [uniqid('mobile-ai-user-', true), 'mobile-ai@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $this->userId = (int) Database::lastInsertId();
        $adminRole = Database::queryOne("SELECT id FROM roles WHERE slug = 'admin' LIMIT 1");
        if (!empty($adminRole['id'])) {
            Database::execute(
                "INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)",
                [$this->userId, (int) $adminRole['id']]
            );
        }

        Database::execute(
            "INSERT INTO contacts (uuid, first_name, last_name, email, assigned_to, created_at, updated_at)
             VALUES (?, 'Ava', 'Client', 'ava@example.com', ?, NOW(), NOW())",
            [uniqid('mobile-ai-contact-', true), $this->userId]
        );
        $this->contactId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO communications (uuid, contact_id, channel, direction, subject, body, status, created_at, updated_at)
             VALUES (?, ?, 'email', 'inbound', 'Need help', 'Can you follow up with me?', 'sent', NOW(), NOW())",
            [uniqid('mobile-ai-comm-', true), $this->contactId]
        );
        $this->conversationId = (int) Database::lastInsertId();
    }

    public function testCreateFollowUpTaskCreatesAssignedTaskForConversation(): void
    {
        $service = new MobileAiActionService();

        $result = $service->createFollowUpTask($this->conversationId, $this->userId);

        $this->assertGreaterThan(0, (int) ($result['task_id'] ?? 0));
        $task = $result['task'] ?? [];
        $this->assertSame($this->userId, (int) ($task['assigned_to'] ?? 0));
        $this->assertSame($this->userId, (int) ($task['created_by'] ?? 0));
        $this->assertSame($this->contactId, (int) ($task['contact_id'] ?? 0));

        $row = Database::queryOne("SELECT metadata_json FROM tasks WHERE id = ?", [(int) $result['task_id']]);
        $metadata = json_decode((string) ($row['metadata_json'] ?? '{}'), true);
        $this->assertSame($this->conversationId, (int) ($metadata['conversation_id'] ?? 0));
        $this->assertTrue(!empty($metadata['ai_assisted']));
    }

    public function testSaveAiNoteCreatesContactNote(): void
    {
        $service = new MobileAiActionService();

        $result = $service->saveAiNote($this->conversationId, $this->userId, '[AI assisted mobile]' . "\n" . 'Customer needs a callback.');

        $this->assertSame($this->contactId, (int) ($result['contact_id'] ?? 0));
        $this->assertGreaterThan(0, (int) ($result['note_id'] ?? 0));
        $note = $result['note'] ?? [];
        $this->assertSame('AI mobile note', (string) ($note['title'] ?? ''));
        $this->assertStringContainsString('Customer needs a callback.', (string) ($note['content'] ?? ''));
        $this->assertSame($this->userId, (int) ($note['created_by'] ?? 0));
    }
}
