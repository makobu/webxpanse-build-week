<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Modules\Invoices;
use CRM\Services\InvoiceProductRecommendationService;
use CRM\Services\UnifiedCommercialCatalogService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspacePackageRecommendationService;
use CRM\Tests\DatabaseTestCase;

class UnifiedCommercialCatalogServiceTest extends DatabaseTestCase
{
    public function testCatalogSeparatesBusinessOffersFromGroupedWorkspacePackages(): void
    {
        $userId = $this->createSuperAdmin('unified-catalog@example.test');
        WorkspaceContext::activateRuntimeWorkspace(1, $userId, 'superadmin');

        $catalog = (new UnifiedCommercialCatalogService())->catalogForWorkspace(1);

        $this->assertCount(5, $catalog['packages']);
        $this->assertSame([], array_values(array_filter(
            $catalog['offers'],
            static fn(array $offer): bool => (string) ($offer['pricing_info'] ?? '') === 'Internal service'
        )));
        $solo = array_values(array_filter($catalog['packages'], static fn(array $package): bool => ($package['plan_code'] ?? '') === 'solo-launch'))[0] ?? [];
        $this->assertCount(2, (array) ($solo['price_variants'] ?? []));
        $this->assertNotEmpty($catalog['package_recommendation']);
    }

    public function testPackageRecommendationChoosesLowestTierThatFitsSeats(): void
    {
        $userId = $this->createSuperAdmin('package-fit@example.test');
        WorkspaceContext::activateRuntimeWorkspace(1, $userId, 'superadmin');
        $packages = (new UnifiedCommercialCatalogService())->catalogForWorkspace(1)['packages'];

        $recommendation = (new WorkspacePackageRecommendationService())->recommend(1, $packages, ['required_seats' => 4]);

        $this->assertSame('growth-studio', (string) ($recommendation['plan_code'] ?? ''));
        $this->assertStringContainsString('4-seat', (string) ($recommendation['reason'] ?? ''));
    }

    public function testInvoiceRecommendationsExcludeInternalCapabilities(): void
    {
        $userId = $this->createSuperAdmin('catalog-recommendation@example.test');
        WorkspaceContext::activateRuntimeWorkspace(1, $userId, 'superadmin');
        Database::execute(
            "INSERT INTO products (workspace_id, name, description, pricing_info, unit_price, is_active, display_order)
             VALUES (1, 'Internal Test Capability', 'Should never be recommended.', 'Internal service', 100, 1, 1)"
        );

        $recommendations = (new InvoiceProductRecommendationService())->recommend([]);

        $this->assertNotContains('Internal Test Capability', array_column($recommendations, 'name'));
    }

    public function testPackageQuoteLineUsesAuthoritativePriceWithoutActivatingSubscription(): void
    {
        $userId = $this->createSuperAdmin('package-quote@example.test');
        WorkspaceContext::activateRuntimeWorkspace(1, $userId, 'superadmin');
        $price = Database::queryOne(
            "SELECT bpp.id, bpp.amount
             FROM billing_plan_prices bpp
             WHERE bpp.price_code = 'founder-plus-monthly'
             LIMIT 1"
        );
        $this->assertNotEmpty($price);

        $invoiceId = (new Invoices())->create([
            'document_type' => 'quote',
            'created_by' => $userId,
            'currency' => 'KES',
            'issue_date' => date('Y-m-d'),
            'title' => 'Founder Plus package quote',
            'line_items' => [[
                'catalog_source_type' => 'workspace_package',
                'billing_plan_price_id' => (int) $price['id'],
                'description' => 'Tampered description',
                'quantity' => 3,
                'unit_price' => 1,
                'discount_percent' => 50,
                'tax_percent' => 20,
            ]],
        ]);

        $line = Database::queryOne("SELECT * FROM invoice_line_items WHERE invoice_id = ? LIMIT 1", [$invoiceId]);
        $this->assertSame('workspace_package', (string) ($line['catalog_source_type'] ?? ''));
        $this->assertSame((int) $price['id'], (int) ($line['billing_plan_price_id'] ?? 0));
        $this->assertSame((float) $price['amount'], (float) ($line['unit_price'] ?? 0));
        $this->assertSame(1.0, (float) ($line['quantity'] ?? 0));
        $this->assertSame(0.0, (float) ($line['discount_percent'] ?? -1));
        $this->assertSame(0.0, (float) ($line['tax_percent'] ?? -1));
        $this->assertStringContainsString('Founder Plus', (string) ($line['description'] ?? ''));
        $this->assertSame(0, (int) (Database::queryOne("SELECT COUNT(*) AS c FROM billing_invoices")['c'] ?? 0));
    }

    private function createSuperAdmin(string $email): int
    {
        Database::execute(
            "INSERT INTO users (email, password_hash, role, first_name, last_name, created_at)
             VALUES (?, ?, 'admin', 'Super', 'Admin', NOW())",
            [$email, password_hash('secret', PASSWORD_DEFAULT)]
        );
        $userId = (int) Database::lastInsertId();
        $role = Database::queryOne("SELECT id FROM roles WHERE slug = 'superadmin' LIMIT 1");
        Database::execute(
            "INSERT INTO user_roles (user_id, role_id, assigned_by) VALUES (?, ?, ?)",
            [$userId, (int) ($role['id'] ?? 0), $userId]
        );
        return $userId;
    }
}
