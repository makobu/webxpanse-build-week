<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\AIAutonomyScoringService;
use CRM\Services\AIDemonstrationCaptureService;
use CRM\Tests\DatabaseTestCase;

class AIAutonomyScoringServiceTest extends DatabaseTestCase
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        Database::execute(
            "INSERT INTO users (email, password_hash, role, created_at) VALUES (?, ?, 'admin', NOW())",
            ['scoring@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $this->userId = (int) Database::lastInsertId();
    }

    public function testScoreUsesSimilarityAndTenantMemory(): void
    {
        $capture = new AIDemonstrationCaptureService();
        $capture->capture([
            'tenant_key' => 'contact:12',
            'actor_user_id' => $this->userId,
            'actor_type' => 'user',
            'source_surface' => 'invoice_view',
            'domain_key' => 'commercial_mvp',
            'entity_type' => 'invoice',
            'entity_id' => 22,
            'action_key' => 'send_document',
            'action_payload' => ['document_type' => 'quote'],
            'outcome_state' => ['status' => 'sent'],
            'outcome_label' => 'accepted',
            'metadata' => ['document_type' => 'quote', 'channel' => 'email', 'deal_stage' => 'proposal'],
            'was_successful' => true,
        ]);

        $service = new AIAutonomyScoringService();
        $score = $service->score('contact:12', 'commercial_mvp', 'send_document', [
            'deal' => ['stage' => 'proposal'],
            'document_type' => 'quote',
            'channel' => 'email',
            'surface' => 'commercial_automation',
            'assistant_confidence' => 0.8,
        ]);

        $this->assertGreaterThan(0.6, $score['assistant_confidence']);
        $this->assertNotEmpty($score['similar_examples']);
        $this->assertSame('email', $score['tenant_policy']['preferred_channel']);
    }
}
