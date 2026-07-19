<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\InvoiceProductRecommendationService;
use CRM\Tests\DatabaseTestCase;

class InvoiceProductRecommendationServiceTest extends DatabaseTestCase
{
    private int $userId;
    private int $contactId;
    private int $companyId;
    private int $dealId;

    protected function setUp(): void
    {
        parent::setUp();

        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, 'admin', NOW())",
            [bin2hex(random_bytes(16)), 'invoice-rec@example.com', password_hash('password', PASSWORD_DEFAULT)]
        );
        $this->userId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO companies (workspace_id, uuid, name, address, created_at) VALUES (1, ?, ?, ?, NOW())",
            [bin2hex(random_bytes(16)), 'Northwind Doors', '145 Harbor Avenue']
        );
        $this->companyId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO contacts (workspace_id, first_name, last_name, email, company_id, company, created_at) VALUES (1, ?, ?, ?, ?, ?, NOW())",
            ['Nina', 'Shaw', 'nina@northwind.example', $this->companyId, 'Northwind Doors']
        );
        $this->contactId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO deals (workspace_id, title, contact_id, company_id, created_by, stage, value, currency, created_at)
             VALUES (1, ?, ?, ?, ?, 'proposal', 1000, 'USD', NOW())",
            ['Door installation proposal', $this->contactId, $this->companyId, $this->userId]
        );
        $this->dealId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO products (workspace_id, name, description, category, pricing_info, unit_price, display_order, is_active, created_at, updated_at)
             VALUES (1, ?, ?, ?, ?, ?, 0, 1, NOW(), NOW())",
            ['Door Installation', 'Installation package for commercial doors', 'Door', 'Installed per site', 450]
        );
        $dealProductId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO products (workspace_id, name, description, category, pricing_info, unit_price, display_order, is_active, created_at, updated_at)
             VALUES (1, ?, ?, ?, ?, ?, 1, 1, NOW(), NOW())",
            ['Maintenance Visit', 'Annual maintenance support', 'Service', 'Annual visit', 150]
        );

        Database::execute(
            "INSERT INTO products (workspace_id, name, description, category, pricing_info, unit_price, display_order, is_active, created_at, updated_at)
             VALUES (1, ?, ?, ?, ?, ?, 2, 1, NOW(), NOW())",
            ['Door Hardware', 'Hardware package for installed doors', 'Hardware', 'Custom quoted', 0]
        );

        Database::execute(
            "INSERT INTO deal_line_items (deal_id, product_id, description, quantity, unit_price, discount_percent, total, sort_order, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
            [$this->dealId, $dealProductId, 'Door installation', 1, 450, 0, 450, 1]
        );
    }

    public function testRecommendationsPrioritizeDealLineItems(): void
    {
        $service = new InvoiceProductRecommendationService();
        $results = $service->recommend([
            'deal_id' => $this->dealId,
            'contact_id' => $this->contactId,
            'company_id' => $this->companyId,
            'document_type' => 'quote',
        ]);

        $this->assertNotEmpty($results);
        $this->assertSame('Door Installation', $results[0]['name']);
        $this->assertSame('deal_line_items', $results[0]['source']);
        $this->assertEquals(450.0, (float) $results[0]['unit_price']);
    }

    public function testZeroPricedProductsCanStillBeRecommended(): void
    {
        $service = new InvoiceProductRecommendationService();
        $results = $service->recommend([
            'deal_id' => $this->dealId,
            'document_type' => 'invoice',
        ]);

        $names = array_column($results, 'name');
        $this->assertContains('Door Hardware', $names);
    }
}
