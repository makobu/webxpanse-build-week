<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\AIOperatorDemonstrationService;
use CRM\Tests\DatabaseTestCase;

class AIOperatorDemonstrationServiceTest extends DatabaseTestCase
{
    private int $userId;
    private AIOperatorDemonstrationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        Database::execute(
            "INSERT INTO users (email, password_hash, role, created_at) VALUES (?, ?, 'admin', NOW())",
            ['demo-service@example.com', password_hash('password', PASSWORD_DEFAULT)]
        );
        $this->userId = (int) Database::lastInsertId();
        $this->service = new AIOperatorDemonstrationService();
    }

    public function testRecordCommercialActionDeduplicatesAndIndexesSimilarity(): void
    {
        $action = ['action' => 'send_document', 'document_type' => 'quote'];
        $context = [
            'deal' => ['id' => 11, 'stage' => 'proposal', 'contact_id' => 33],
            'invoice' => ['id' => 22, 'document_type' => 'quote', 'contact_id' => 33],
            'channel' => 'email',
            'recipient' => 'buyer@example.com',
            'assistant_confidence' => 0.94,
        ];
        $result = ['action' => 'send_document', 'status' => 'sent', 'invoice_id' => 22];

        $firstId = $this->service->recordCommercialAction($action, $context, $result, 'system', $this->userId, null, null);
        $secondId = $this->service->recordCommercialAction($action, $context, $result, 'system', $this->userId, null, null);

        $this->assertGreaterThan(0, $firstId);
        $this->assertSame($firstId, $secondId);
        $this->assertSame(
            1,
            (int) (Database::queryOne("SELECT COUNT(*) AS c FROM ai_operator_demonstrations")['c'] ?? 0)
        );
        $this->assertSame(
            1,
            (int) (Database::queryOne("SELECT COUNT(*) AS c FROM ai_action_similarity_index")['c'] ?? 0)
        );
    }

    public function testMarkOutcomeUpdatesFlags(): void
    {
        $demoId = $this->service->record([
            'tenant_key' => 'contact:44',
            'actor_user_id' => $this->userId,
            'actor_type' => 'user',
            'source_surface' => 'commercial_automation',
            'entity_type' => 'deal',
            'entity_id' => 44,
            'action_key' => 'revise_document',
            'action_payload' => ['revision' => 2],
            'outcome_state' => ['status' => 'revised'],
            'was_successful' => true,
        ]);

        $updated = $this->service->markOutcome($demoId, [
            'outcome_label' => 'edited',
            'outcome_state' => ['status' => 'edited_after_send'],
            'was_successful' => false,
            'was_edited' => true,
        ]);

        $row = Database::queryOne("SELECT * FROM ai_operator_demonstrations WHERE id = ?", [$demoId]);

        $this->assertTrue($updated);
        $this->assertSame('edited', $row['outcome_label']);
        $this->assertSame(1, (int) $row['was_edited']);
        $this->assertSame(0, (int) $row['was_successful']);
    }
}
