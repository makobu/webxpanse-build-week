<?php

namespace CRM\Tests\Unit\Modules;

use CRM\Database;
use CRM\Modules\Invoices;
use CRM\Services\WorkspaceMembershipService;
use CRM\Tests\DatabaseTestCase;

class InvoicesTest extends DatabaseTestCase
{
    private Invoices $invoices;
    private int $userId;
    private int $contactId;
    private int $dealId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->invoices = new Invoices();
        $this->ensureWorkspace(1, 'default', 'Default Workspace');

        Database::execute(
            "INSERT INTO users (email, password_hash, role, created_at) VALUES (?, ?, 'admin', NOW())",
            ['invoice-test@example.com', password_hash('password', PASSWORD_DEFAULT)]
        );
        $this->userId = (int) Database::lastInsertId();
        (new WorkspaceMembershipService())->addOrUpdateMembership(1, $this->userId, 'owner', true, $this->userId);

        Database::execute(
            "INSERT INTO contacts (workspace_id, first_name, last_name, email, created_at) VALUES (1, ?, ?, ?, NOW())",
            ['Invoice', 'Contact', 'invoice-contact@example.com']
        );
        $this->contactId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO deals (workspace_id, title, contact_id, assigned_to, created_by, stage, value, currency, created_at)
             VALUES (1, ?, ?, ?, ?, 'proposal', ?, 'USD', NOW())",
            ['Invoice Test Deal', $this->contactId, $this->userId, $this->userId, 0]
        );
        $this->dealId = (int) Database::lastInsertId();
    }

    public function testCreateFromDealClonesLineItemsAndTotals(): void
    {
        Database::execute(
            "INSERT INTO deal_line_items (deal_id, description, quantity, unit_price, discount_percent, total, sort_order, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
            [$this->dealId, 'Discovery workshop', 2, 150, 10, 270, 1]
        );

        $invoiceId = $this->invoices->createFromDeal($this->dealId, 'quote', $this->userId);
        $invoice = $this->invoices->getById($invoiceId);

        $this->assertSame('quote', $invoice['document_type']);
        $this->assertSame($this->dealId, (int) $invoice['deal_id']);
        $this->assertCount(1, $invoice['line_items']);
        $this->assertSame('Discovery workshop', $invoice['line_items'][0]['description']);
        $this->assertEquals(300.00, (float) $invoice['line_items'][0]['quantity'] * (float) $invoice['line_items'][0]['unit_price']);
        $this->assertEquals(30.00, (float) $invoice['discount_total']);
        $this->assertEquals(270.00, (float) $invoice['grand_total']);
        $this->assertEquals(270.00, (float) $invoice['balance_due']);
    }

    public function testFinalizeAndMarkPaidUpdatesStatusAndBalance(): void
    {
        $invoiceId = $this->invoices->create([
            'document_type' => 'invoice',
            'contact_id' => $this->contactId,
            'created_by' => $this->userId,
            'line_items' => [
                [
                    'description' => 'Implementation',
                    'quantity' => 1,
                    'unit_price' => 500,
                    'discount_percent' => 0,
                    'tax_percent' => 0,
                ],
            ],
        ], 'user', $this->userId);

        $this->invoices->transitionStatus($invoiceId, 'finalized', $this->userId);
        $this->invoices->markPaid($invoiceId, 200, date('Y-m-d H:i:s'), $this->userId);

        $invoice = $this->invoices->getById($invoiceId);

        $this->assertSame('partially_paid', $invoice['status']);
        $this->assertEquals(200.00, (float) $invoice['amount_paid']);
        $this->assertEquals(300.00, (float) $invoice['balance_due']);
        $this->assertNotEmpty($invoice['status_history']);
        $this->assertNotEmpty($invoice['activity_log']);
    }

    public function testCreateInfersUnitPriceFromPricingContext(): void
    {
        $invoiceId = $this->invoices->create([
            'document_type' => 'quote',
            'contact_id' => $this->contactId,
            'created_by' => $this->userId,
            'line_items' => [
                [
                    'description' => 'Managed support',
                    'pricing_context' => 'Starting at USD 120 per seat per month.',
                    'quantity' => 2,
                    'unit_price' => 0,
                    'discount_percent' => 0,
                    'tax_percent' => 0,
                ],
            ],
        ], 'user', $this->userId);

        $invoice = $this->invoices->getById($invoiceId);

        $this->assertSame(120.0, (float) $invoice['line_items'][0]['unit_price']);
        $this->assertSame('Starting at USD 120 per seat per month.', (string) $invoice['line_items'][0]['pricing_context']);
        $this->assertEquals(240.0, (float) $invoice['grand_total']);
    }

    public function testCreateStoresSelectedTemplateKey(): void
    {
        $invoiceId = $this->invoices->create([
            'document_type' => 'invoice',
            'contact_id' => $this->contactId,
            'created_by' => $this->userId,
            'template_key' => 'bold',
            'line_items' => [
                [
                    'description' => 'Implementation',
                    'quantity' => 1,
                    'unit_price' => 200,
                    'discount_percent' => 0,
                    'tax_percent' => 0,
                ],
            ],
        ], 'user', $this->userId);

        $invoice = $this->invoices->getById($invoiceId);

        $this->assertSame('bold', $invoice['template_key']);
    }

    public function testCreateRejectsProductFromAnotherWorkspaceEvenWithSubmittedPrice(): void
    {
        $this->ensureWorkspace(2, 'foreign-invoice-test', 'Foreign Invoice Test');
        Database::execute(
            "INSERT INTO products (workspace_id, name, category, unit_price, is_active, display_order, created_at, updated_at)
             VALUES (2, 'Foreign offer', 'service', 99, 1, 0, NOW(), NOW())"
        );
        $foreignProductId = (int) Database::lastInsertId();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not available in this workspace');
        $this->invoices->create([
            'document_type' => 'invoice',
            'contact_id' => $this->contactId,
            'created_by' => $this->userId,
            'line_items' => [[
                'product_id' => $foreignProductId,
                'description' => 'Forged foreign offer',
                'quantity' => 1,
                'unit_price' => 99,
            ]],
        ], 'user', $this->userId);
    }

    public function testTransitionRejectsOversizedReasonWithoutChangingStatus(): void
    {
        $invoiceId = $this->invoices->create([
            'document_type' => 'invoice',
            'contact_id' => $this->contactId,
            'created_by' => $this->userId,
        ], 'user', $this->userId);

        try {
            $this->invoices->transitionStatus($invoiceId, 'finalized', $this->userId, 'user', str_repeat('x', 256));
            $this->fail('Expected oversized status reason to be rejected.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('255 characters or fewer', $e->getMessage());
        }

        $this->assertSame('draft', $this->invoices->getById($invoiceId)['status']);
    }

    public function testCreateFallsBackToWorkspaceDefaultTemplateKey(): void
    {
        (new \CRM\Modules\InvoiceSettings())->save([
            'default_template_key' => 'minimal',
        ], $this->userId);

        $invoiceId = $this->invoices->create([
            'document_type' => 'quote',
            'contact_id' => $this->contactId,
            'created_by' => $this->userId,
            'line_items' => [
                [
                    'description' => 'Support',
                    'quantity' => 1,
                    'unit_price' => 75,
                    'discount_percent' => 0,
                    'tax_percent' => 0,
                ],
            ],
        ], 'user', $this->userId);

        $invoice = $this->invoices->getById($invoiceId);

        $this->assertSame('minimal', $invoice['template_key']);
    }

    public function testUpdateCanChangeTemplateKey(): void
    {
        $invoiceId = $this->invoices->create([
            'document_type' => 'invoice',
            'contact_id' => $this->contactId,
            'created_by' => $this->userId,
            'template_key' => 'classic',
            'line_items' => [
                [
                    'description' => 'Implementation',
                    'quantity' => 1,
                    'unit_price' => 120,
                    'discount_percent' => 0,
                    'tax_percent' => 0,
                ],
            ],
        ], 'user', $this->userId);

        $this->invoices->update($invoiceId, ['template_key' => 'bold'], 'user', $this->userId);
        $invoice = $this->invoices->getById($invoiceId);

        $this->assertSame('bold', $invoice['template_key']);
    }

    private function ensureWorkspace(int $id, string $slug, string $name): void
    {
        $existing = Database::queryOne("SELECT id FROM workspaces WHERE id = ? LIMIT 1", [$id]);
        if ($existing) {
            return;
        }

        Database::execute(
            "INSERT INTO workspaces (id, uuid, name, slug, status, plan_status, created_at, updated_at)
             VALUES (?, ?, ?, ?, 'active', 'trialing', NOW(), NOW())",
            [$id, sprintf('00000000-0000-4000-8000-%012d', $id), $name, $slug]
        );
    }
}
