<?php
/**
 * Attribution Service Tests
 */

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Modules\Contacts;
use CRM\Services\AttributionService;
use CRM\Services\TouchpointIngestionService;
use CRM\Tests\DatabaseTestCase;

class AttributionServiceTest extends DatabaseTestCase
{
    private int $userId;
    private int $contactId;
    private int $dealId;
    private AttributionService $service;
    private TouchpointIngestionService $touchpoints;

    protected function setUp(): void
    {
        parent::setUp();

        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role) VALUES (UUID(), ?, ?, 'admin')",
            ['attr-test@example.com', password_hash('secret123', PASSWORD_BCRYPT)]
        );
        $this->userId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status) VALUES (1, ?, 'owner', 'active')",
            [$this->userId]
        );

        $contacts = new Contacts();
        $created = $contacts->create([
            'first_name' => 'Attribution',
            'last_name' => 'Tester',
            'email' => 'attribution.contact@example.com',
            'created_by' => $this->userId
        ]);
        $this->contactId = (int) $created['id'];

        Database::execute(
            "INSERT INTO deals
             (workspace_id, title, contact_id, created_by, stage, value, probability, currency, actual_close_date)
             VALUES (1, ?, ?, ?, 'closed_won', ?, 100, 'USD', CURDATE())",
            ['Attribution Test Deal', $this->contactId, $this->userId, 1000.00]
        );
        $this->dealId = (int) Database::lastInsertId();

        $this->touchpoints = new TouchpointIngestionService();
        $this->service = new AttributionService();
    }

    public function testRecomputeForDealLinearCreatesWeightedRows(): void
    {
        $this->touchpoints->ingestTouchpoint([
            'contact_id' => $this->contactId,
            'channel' => 'web',
            'touch_type' => 'page_view',
            'occurred_at' => date('Y-m-d H:i:s', strtotime('-5 days')),
            'utm_source' => 'google',
            'utm_campaign' => 'campaign-a'
        ]);

        $this->touchpoints->ingestTouchpoint([
            'contact_id' => $this->contactId,
            'channel' => 'email',
            'touch_type' => 'email_opened',
            'occurred_at' => date('Y-m-d H:i:s', strtotime('-2 days')),
            'utm_source' => 'email',
            'utm_campaign' => 'campaign-b'
        ]);

        $rows = $this->service->recomputeForDeal($this->dealId, 'linear');
        $this->assertGreaterThanOrEqual(2, $rows);

        $resultRows = Database::query(
            "SELECT ar.workspace_id, attribution_weight, credited_value
             FROM attribution_results ar
             JOIN attribution_models am ON am.id = ar.model_id
             WHERE ar.deal_id = ? AND am.slug = 'linear'",
            [$this->dealId]
        );
        $this->assertCount(2, $resultRows);
        $this->assertSame(1, (int) $resultRows[0]['workspace_id']);

        $totalWeight = 0.0;
        $totalValue = 0.0;
        foreach ($resultRows as $row) {
            $totalWeight += (float) $row['attribution_weight'];
            $totalValue += (float) $row['credited_value'];
        }

        $this->assertEqualsWithDelta(1.0, $totalWeight, 0.0001);
        $this->assertEqualsWithDelta(1000.0, $totalValue, 0.01);
    }
}
