<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\ClarityConversationService;
use CRM\Tests\DatabaseTestCase;

class ClarityConversationServiceTest extends DatabaseTestCase
{
    public function testConversationHistoryPreservesFollowUpContextForOneUser(): void
    {
        Database::execute(
            "INSERT INTO users (email, password_hash, role, created_at) VALUES (?, ?, 'sales', NOW())",
            ['clarity-conversation@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $userId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at)
             VALUES (1, ?, 'owner', 'active', 1, NOW())",
            [$userId]
        );

        $service = new ClarityConversationService();
        $conversation = $service->currentOrCreate(1, $userId);
        $service->append((int) $conversation['id'], 1, $userId, 'user', 'Why is my risk score high?', 'hr_analytics.php');
        $service->append((int) $conversation['id'], 1, $userId, 'assistant', 'Task completion is the main factor.', 'hr_analytics.php');

        $context = $service->promptContext($service->currentOrCreate(1, $userId, (int) $conversation['id']), 1, $userId);

        $this->assertSame((int) $conversation['id'], $context['conversation_id']);
        $this->assertCount(2, $context['recent_messages']);
        $this->assertSame('assistant', $context['recent_messages'][1]['role']);
        $this->assertStringContainsString('Current server-owned page', $context['continuity_rule']);
    }
}
