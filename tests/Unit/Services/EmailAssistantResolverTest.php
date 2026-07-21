<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\EmailAssistantResolver;
use CRM\Tests\DatabaseTestCase;

class EmailAssistantResolverTest extends DatabaseTestCase
{
    public function testResolveInvoiceByDealContext(): void
    {
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, 'owner@example.com', 'x', 'admin', NOW())",
            [uuid_v4()]
        );
        $userId = (int) Database::lastInsertId();
        Database::execute("INSERT INTO contacts (workspace_id, first_name, last_name, email, created_by) VALUES (1, 'John', 'Buyer', 'john@example.com', ?)", [$userId]);
        $contactId = (int) Database::lastInsertId();
        Database::execute("INSERT INTO deals (workspace_id, title, stage, contact_id, created_by) VALUES (1, 'Website Retainer', 'proposal', ?, ?)", [$contactId, $userId]);
        $dealId = (int) Database::lastInsertId();
        Database::execute("INSERT INTO invoice_settings (id) VALUES (1) ON DUPLICATE KEY UPDATE id = id");
        Database::execute("INSERT INTO invoices (workspace_id, document_type, status, invoice_number, revision_number, deal_id, contact_id, created_by, currency, issue_date, subtotal, discount_total, tax_total, grand_total, balance_due, tax_mode, tax_rate, billing_name, billing_email) VALUES (1, 'quote', 'draft', 'Q-1001', 1, ?, ?, ?, 'USD', CURDATE(), 100, 0, 0, 100, 100, 'exclusive', 0, 'John Buyer', 'john@example.com')", [$dealId, $contactId, $userId]);

        $resolved = (new EmailAssistantResolver())->resolveFromAdminEmail('Send the latest quote for deal ' . $dealId, $userId);

        $this->assertTrue($resolved['resolved']);
        $this->assertSame($dealId, (int) ($resolved['primary_entities']['deal']['id'] ?? 0));
        $this->assertSame('Q-1001', (string) ($resolved['primary_entities']['invoice']['invoice_number'] ?? ''));
    }

    public function testResolveFromCustomerThreadUsesThreadContext(): void
    {
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, 'agent@example.com', 'x', 'admin', NOW())",
            [uuid_v4()]
        );
        $userId = (int) Database::lastInsertId();
        Database::execute("INSERT INTO contacts (workspace_id, first_name, last_name, email, created_by) VALUES (1, 'Alice', 'Client', 'alice@example.com', ?)", [$userId]);
        $contactId = (int) Database::lastInsertId();
        Database::execute("INSERT INTO deals (workspace_id, title, stage, contact_id, created_by) VALUES (1, 'Consulting Package', 'negotiation', ?, ?)", [$contactId, $userId]);
        $dealId = (int) Database::lastInsertId();
        Database::execute("INSERT INTO communications (workspace_id, uuid, contact_id, thread_key, channel, direction, subject, body, status, created_at) VALUES (1, ?, ?, ?, 'email', 'inbound', 'Pricing', 'Can you reduce the setup fee?', 'received', NOW())", [uuid_v4(), $contactId, 'email:contact:' . $contactId]);
        $communicationId = (int) Database::lastInsertId();

        $resolved = (new EmailAssistantResolver())->resolveFromCustomerThread($communicationId);

        $this->assertTrue($resolved['resolved']);
        $this->assertSame($contactId, (int) ($resolved['primary_entities']['contact']['id'] ?? 0));
        $this->assertSame($dealId, (int) ($resolved['primary_entities']['deal']['id'] ?? 0));
        $this->assertTrue((bool) ($resolved['thread_context']['signals']['asks_for_discount'] ?? false));
    }

    public function testResolverDoesNotResolveForeignWorkspaceEntities(): void
    {
        Database::execute(
            "INSERT INTO workspaces (id, uuid, name, slug, status, plan_status, created_at, updated_at)
             VALUES (2, ?, 'Email Assistant Foreign', 'email-assistant-foreign', 'active', 'trialing', NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                slug = VALUES(slug),
                status = VALUES(status),
                plan_status = VALUES(plan_status),
                updated_at = VALUES(updated_at)",
            ['00000000-0000-4000-8000-000000000002']
        );
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, 'foreign-agent@example.com', 'x', 'admin', NOW())",
            [uuid_v4()]
        );
        $userId = (int) Database::lastInsertId();
        Database::execute("INSERT INTO contacts (workspace_id, first_name, last_name, email, created_by) VALUES (2, 'Foreign', 'Client', 'foreign-client@example.com', ?)", [$userId]);
        $contactId = (int) Database::lastInsertId();
        Database::execute("INSERT INTO deals (workspace_id, title, stage, contact_id, created_by) VALUES (2, 'Foreign Workspace Deal', 'proposal', ?, ?)", [$contactId, $userId]);
        $dealId = (int) Database::lastInsertId();
        Database::execute("INSERT INTO communications (workspace_id, uuid, contact_id, thread_key, channel, direction, subject, body, status, created_at) VALUES (2, ?, ?, ?, 'email', 'inbound', 'Foreign Pricing', 'Can you reduce the setup fee?', 'received', NOW())", [uuid_v4(), $contactId, 'email:contact:' . $contactId]);
        $communicationId = (int) Database::lastInsertId();

        $resolver = new EmailAssistantResolver();
        $adminResolved = $resolver->resolveFromAdminEmail('Send the latest quote for deal ' . $dealId, $userId);
        $threadResolved = $resolver->resolveFromCustomerThread($communicationId);

        $this->assertFalse($adminResolved['resolved']);
        $this->assertEmpty($adminResolved['primary_entities']['deal'] ?? null);
        $this->assertFalse($threadResolved['resolved']);
        $this->assertSame(['Communication thread not found.'], $threadResolved['ambiguities']);
    }
}
