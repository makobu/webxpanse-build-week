<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Modules\Documents;
use CRM\Tests\DatabaseTestCase;

$_SERVER['REQUEST_METHOD'] ??= 'GET';
require_once dirname(__DIR__, 3) . '/api/mobile/_conversation_detail.php';

class MobileConversationAttachmentSerializationTest extends DatabaseTestCase
{
    public function testMobileConversationAttachmentsLoadsMultipleMessageDocumentsInWorkspace(): void
    {
        $userId = $this->createUser();
        $firstCommunicationId = $this->createCommunication('First attachment message');
        $secondCommunicationId = $this->createCommunication('Second attachment message');
        $firstDocumentId = $this->createDocument($firstCommunicationId, $userId, 'first.txt');
        $secondDocumentId = $this->createDocument($secondCommunicationId, $userId, 'second.txt');

        $attachments = mobileConversationAttachments(new Documents(), [$firstCommunicationId, $secondCommunicationId]);
        $ids = array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), $attachments);

        $this->assertContains($firstDocumentId, $ids);
        $this->assertContains($secondDocumentId, $ids);
    }

    private function createUser(): int
    {
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role)
             VALUES (?, ?, ?, 'sales')",
            [$this->uuid(), 'mobile-attachment.' . bin2hex(random_bytes(3)) . '@example.test', password_hash('password', PASSWORD_DEFAULT)]
        );

        return (int) Database::lastInsertId();
    }

    private function createCommunication(string $body): int
    {
        Database::execute(
            "INSERT INTO communications
                (workspace_id, uuid, channel, direction, subject, body, status, from_email, created_at)
             VALUES
                (1, ?, 'email', 'inbound', 'Attachment', ?, 'received', 'client@example.test', NOW())",
            [$this->uuid(), $body]
        );

        return (int) Database::lastInsertId();
    }

    private function createDocument(int $communicationId, int $userId, string $name): int
    {
        Database::execute(
            "INSERT INTO documents
                (workspace_id, entity_type, entity_id, file_name, original_name, file_path, file_size, mime_type, description, uploaded_by, created_at)
             VALUES
                (1, 'communication', ?, ?, ?, ?, 12, 'text/plain', 'Mobile upload test', ?, NOW())",
            [$communicationId, $name, $name, sys_get_temp_dir() . DIRECTORY_SEPARATOR . $name, $userId]
        );

        return (int) Database::lastInsertId();
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
