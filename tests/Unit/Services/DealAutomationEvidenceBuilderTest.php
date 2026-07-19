<?php
/**
 * Deal Automation Evidence Builder Tests
 */

namespace CRM\Tests\Unit\Services;

use CRM\Tests\DatabaseTestCase;
use CRM\Services\DealAutomationEvidenceBuilder;
use CRM\Database;

class DealAutomationEvidenceBuilderTest extends DatabaseTestCase
{
    private DealAutomationEvidenceBuilder $builder;
    private int $userId;
    private int $contactId;
    private int $dealId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new DealAutomationEvidenceBuilder();
        $this->createTestData();
    }

    private function createTestData(): void
    {
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, 'admin', NOW())",
            [bin2hex(random_bytes(16)), 'evidence-builder@example.com', password_hash('password', PASSWORD_DEFAULT)]
        );
        $this->userId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO contacts (first_name, last_name, email, lead_score, created_at) VALUES (?, ?, ?, ?, NOW())",
            ['Test', 'Contact', 'test@example.com', 65]
        );
        $this->contactId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO deals (title, contact_id, created_by, stage, value, currency, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())",
            ['Test Deal', $this->contactId, $this->userId, 'qualification', 1000, 'USD']
        );
        $this->dealId = (int) Database::lastInsertId();
    }

    public function testBuildForDealReturnsPayload()
    {
        $evidence = $this->builder->buildForDeal($this->dealId, 14);
        $this->assertIsArray($evidence);
        $this->assertEquals($this->dealId, $evidence['deal_id']);
        $this->assertEquals($this->contactId, $evidence['contact_id']);
        $this->assertEquals('qualification', $evidence['current_stage']);
        $this->assertArrayHasKey('communications', $evidence);
        $this->assertArrayHasKey('intent_counts', $evidence);
        $this->assertArrayHasKey('sentiment_summary', $evidence);
        $this->assertArrayHasKey('proposal_sent', $evidence);
        $this->assertArrayHasKey('lead_score', $evidence);
        $this->assertArrayHasKey('lead_score_band', $evidence);
    }

    public function testBuildForDealWithNoContactReturnsDealOnlyPayload()
    {
        Database::execute(
            "INSERT INTO deals (title, contact_id, created_by, stage, value, currency, created_at) VALUES (?, NULL, ?, ?, ?, ?, NOW())",
            ['Deal No Contact', $this->userId, 'prospecting', 500, 'USD']
        );
        $dealId = (int) Database::lastInsertId();
        $evidence = $this->builder->buildForDeal($dealId, 14);
        $this->assertNull($evidence['contact_id']);
        $this->assertEquals('prospecting', $evidence['current_stage']);
        $this->assertEmpty($evidence['communications']);
    }

    public function testBuildForContactReturnsPayload()
    {
        $evidence = $this->builder->buildForContact($this->contactId, 14);
        $this->assertIsArray($evidence);
        $this->assertNull($evidence['deal_id']);
        $this->assertEquals($this->contactId, $evidence['contact_id']);
        $this->assertEquals(65, $evidence['lead_score']);
        $this->assertEquals('warm', $evidence['lead_score_band']);
    }

    public function testProposalSentDetectedFromCommunications()
    {
        $metadata = json_encode(['purpose' => 'proposal']);
        Database::execute(
            "INSERT INTO communications (uuid, contact_id, channel, direction, subject, body, status, metadata, created_at)
             VALUES (?, ?, 'email', 'outbound', 'Proposal', 'Body', 'sent', ?, NOW())",
            [bin2hex(random_bytes(16)), $this->contactId, $metadata]
        );
        $evidence = $this->builder->buildForDeal($this->dealId, 14);
        $this->assertTrue($evidence['proposal_sent']);
    }

    public function testLeadScoreBandCold()
    {
        Database::execute("UPDATE contacts SET lead_score = 20 WHERE id = ?", [$this->contactId]);
        $evidence = $this->builder->buildForContact($this->contactId, 14);
        $this->assertEquals('cold', $evidence['lead_score_band']);
    }

    public function testLeadScoreBandHot()
    {
        Database::execute("UPDATE contacts SET lead_score = 80 WHERE id = ?", [$this->contactId]);
        $evidence = $this->builder->buildForContact($this->contactId, 14);
        $this->assertEquals('hot', $evidence['lead_score_band']);
    }
}
