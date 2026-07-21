<?php

namespace CRM\Tests\Integration;

use CRM\Database;
use CRM\Modules\Invoices;
use CRM\Services\InvoiceDeliveryAuditService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceMembershipService;
use CRM\Tests\DatabaseTestCase;
use CRM\Tests\Support\EndpointHarness;

class InvoiceWorkspaceIsolationTest extends DatabaseTestCase
{
    use EndpointHarness;

    private int $userId = 0;
    private int $workspaceOneInvoiceId = 0;
    private int $workspaceTwoInvoiceId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureWorkspace(2, 'workspace-two', 'Workspace Two');

        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at)
             VALUES (?, ?, ?, 'admin', NOW())",
            [uuid_v4(), 'invoice-isolation@example.test', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $this->userId = (int) Database::lastInsertId();

        $memberships = new WorkspaceMembershipService();
        $memberships->addOrUpdateMembership(1, $this->userId, 'owner', true, $this->userId);
        $memberships->addOrUpdateMembership(2, $this->userId, 'owner', true, $this->userId);
        $this->grantInvoiceAccess($this->userId);

        $this->workspaceOneInvoiceId = $this->createInvoiceForWorkspace(1, 'current-workspace@example.test', 'Current Workspace Invoice');
        $this->workspaceTwoInvoiceId = $this->createInvoiceForWorkspace(2, 'foreign-workspace@example.test', 'Foreign Workspace Invoice');

        $this->activateWorkspace(1);
    }

    public function testInvoiceListOnlyShowsCurrentWorkspaceDocuments(): void
    {
        $response = $this->runWebEndpoint('public/invoices.php', $this->workspaceSession(1), [
            'method' => 'GET',
        ]);

        $body = (string) ($response['body'] ?? '');
        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertStringContainsString('Current Workspace Invoice', $body);
        $this->assertStringNotContainsString('Foreign Workspace Invoice', $body);
    }

    public function testInvoiceViewRedirectsForForeignWorkspaceInvoice(): void
    {
        $response = $this->runWebEndpoint('public/invoice_view.php', $this->workspaceSession(1), [
            'method' => 'GET',
            'query' => ['id' => $this->workspaceTwoInvoiceId],
        ]);

        $this->assertSame(302, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
    }

    public function testInvoiceActionCannotMutateForeignWorkspaceInvoice(): void
    {
        $before = Database::queryOne(
            "SELECT status, amount_paid
             FROM invoices
             WHERE workspace_id = 2
               AND id = ?",
            [$this->workspaceTwoInvoiceId]
        );
        $deliveryCountBefore = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM invoice_delivery_log
             WHERE workspace_id = 2
               AND invoice_id = ?",
            [$this->workspaceTwoInvoiceId]
        )['c'] ?? 0);

        $response = $this->runWebEndpoint('public/invoice_view.php', $this->workspaceSession(1), [
            'method' => 'POST',
            'query' => ['id' => $this->workspaceTwoInvoiceId],
            'post' => [
                'csrf_token' => 'test-csrf-token',
                'action' => 'mark_paid',
                'amount' => '50',
            ],
        ]);

        $after = Database::queryOne(
            "SELECT status, amount_paid
             FROM invoices
             WHERE workspace_id = 2
               AND id = ?",
            [$this->workspaceTwoInvoiceId]
        );
        $deliveryCountAfter = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM invoice_delivery_log
             WHERE workspace_id = 2
               AND invoice_id = ?",
            [$this->workspaceTwoInvoiceId]
        )['c'] ?? 0);

        $this->assertSame(302, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertSame((string) ($before['status'] ?? ''), (string) ($after['status'] ?? ''));
        $this->assertSame((float) ($before['amount_paid'] ?? 0), (float) ($after['amount_paid'] ?? 0));
        $this->assertSame($deliveryCountBefore, $deliveryCountAfter);
    }

    private function createInvoiceForWorkspace(int $workspaceId, string $email, string $title): int
    {
        $this->activateWorkspace($workspaceId);

        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())",
            [$workspaceId, uniqid('invoice-contact-', true), 'Invoice', (string) $workspaceId, $email]
        );
        $contactId = (int) Database::lastInsertId();

        $invoiceId = (new Invoices())->create([
            'document_type' => 'invoice',
            'contact_id' => $contactId,
            'created_by' => $this->userId,
            'title' => $title,
            'billing_name' => 'Billing ' . $workspaceId,
            'billing_email' => $email,
            'billing_phone' => '+25470000000' . $workspaceId,
            'billing_address' => 'Workspace ' . $workspaceId . ' Address',
            'line_items' => [[
                'description' => 'Invoice line ' . $workspaceId,
                'quantity' => 1,
                'unit_price' => 250 + $workspaceId,
                'discount_percent' => 0,
                'tax_percent' => 0,
            ]],
        ], 'user', $this->userId);

        (new InvoiceDeliveryAuditService())->recordEmailDelivery(
            $invoiceId,
            $email,
            'Invoice Delivery ' . $workspaceId,
            'sent',
            ['provider_key' => 'test'],
            $this->userId,
            'user'
        );

        return $invoiceId;
    }

    private function activateWorkspace(int $workspaceId): void
    {
        WorkspaceContext::activateRuntimeWorkspace($workspaceId, $this->userId, 'owner');
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

    private function grantInvoiceAccess(int $userId): void
    {
        Database::execute(
            "INSERT INTO roles (name, slug, description, is_system, is_active)
             VALUES ('Invoice Workspace Admin', 'invoice-workspace-admin', 'Invoice isolation test role', 1, 1)
             ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description), is_active = VALUES(is_active)"
        );

        foreach ([
            'invoices.view' => 'View invoices',
            'invoices.create' => 'Create invoices',
            'invoices.edit' => 'Edit invoices',
        ] as $permissionKey => $label) {
            Database::execute(
                "INSERT INTO permissions (permission_key, label, description, is_sensitive)
                 VALUES (?, ?, ?, 0)
                 ON DUPLICATE KEY UPDATE label = VALUES(label), description = VALUES(description)",
                [$permissionKey, $label, $label . ' for invoice isolation tests']
            );
        }

        $role = Database::queryOne("SELECT id FROM roles WHERE slug = 'invoice-workspace-admin' LIMIT 1");
        $roleId = (int) ($role['id'] ?? 0);

        foreach (['invoices.view', 'invoices.create', 'invoices.edit'] as $permissionKey) {
            $permission = Database::queryOne("SELECT id FROM permissions WHERE permission_key = ? LIMIT 1", [$permissionKey]);
            Database::execute(
                "INSERT INTO role_permissions (role_id, permission_id, can_access)
                 VALUES (?, ?, 1)
                 ON DUPLICATE KEY UPDATE can_access = VALUES(can_access)",
                [$roleId, (int) ($permission['id'] ?? 0)]
            );
        }

        Database::execute(
            "INSERT INTO user_roles (user_id, role_id, assigned_by)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE role_id = VALUES(role_id), assigned_by = VALUES(assigned_by)",
            [$userId, $roleId, $userId]
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function workspaceSession(int $workspaceId): array
    {
        $workspace = Database::queryOne(
            "SELECT id, uuid, name, slug
             FROM workspaces
             WHERE id = ?
             LIMIT 1",
            [$workspaceId]
        ) ?? [];
        $membership = Database::queryOne(
            "SELECT id, role_slug
             FROM workspace_memberships
             WHERE workspace_id = ?
               AND user_id = ?
             LIMIT 1",
            [$workspaceId, $this->userId]
        ) ?? [];
        $user = Database::queryOne(
            "SELECT uuid, email, role
             FROM users
             WHERE id = ?
             LIMIT 1",
            [$this->userId]
        ) ?? [];

        return [
            'user_id' => $this->userId,
            'user_uuid' => (string) ($user['uuid'] ?? ''),
            'user_email' => (string) ($user['email'] ?? ''),
            'user_role' => (string) ($user['role'] ?? 'admin'),
            'active_workspace_id' => $workspaceId,
            'active_workspace_uuid' => (string) ($workspace['uuid'] ?? ''),
            'active_workspace_slug' => (string) ($workspace['slug'] ?? ''),
            'active_workspace_name' => (string) ($workspace['name'] ?? ''),
            'active_workspace_role' => (string) ($membership['role_slug'] ?? 'owner'),
            'active_workspace_membership_id' => (int) ($membership['id'] ?? 0),
            'csrf_token' => 'test-csrf-token',
        ];
    }
}
