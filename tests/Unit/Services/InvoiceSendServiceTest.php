<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Modules\CompanyProfile;
use CRM\Modules\InvoiceSettings;
use CRM\Modules\Invoices;
use CRM\Services\EmailService;
use CRM\Services\InvoiceDeliveryAuditService;
use CRM\Services\InvoiceDeliveryReadinessService;
use CRM\Services\InvoicePdfService;
use CRM\Services\InvoiceSendService;
use CRM\Services\WorkspaceMembershipService;
use CRM\Tests\DatabaseTestCase;

class InvoiceSendServiceTest extends DatabaseTestCase
{
    private int $userId;
    private int $contactId;
    private int $invoiceId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureWorkspace(1, 'default', 'Default Workspace');

        Database::execute(
            "INSERT INTO users (email, password_hash, role, created_at) VALUES (?, ?, 'admin', NOW())",
            ['invoice-send@example.com', password_hash('password', PASSWORD_DEFAULT)]
        );
        $this->userId = (int) Database::lastInsertId();
        (new WorkspaceMembershipService())->addOrUpdateMembership(1, $this->userId, 'owner', true, $this->userId);

        Database::execute(
            "INSERT INTO contacts (workspace_id, first_name, last_name, email, created_at) VALUES (1, ?, ?, ?, NOW())",
            ['Invoice', 'Customer', 'invoice-customer@example.com']
        );
        $this->contactId = (int) Database::lastInsertId();

        (new CompanyProfile())->update([
            'company_legal_name' => 'Mak CRM Ltd',
            'company_email' => 'billing@makcrm.test',
            'company_address' => '123 Invoice Street',
            'company_phone' => '+254700000000',
        ]);

        $this->invoiceId = (new Invoices())->create([
            'document_type' => 'invoice',
            'contact_id' => $this->contactId,
            'created_by' => $this->userId,
            'title' => 'Implementation Services',
            'billing_name' => 'Invoice Customer',
            'billing_email' => 'invoice-customer@example.com',
            'billing_phone' => '+254711111111',
            'billing_address' => 'Customer Address',
            'line_items' => [[
                'description' => 'Implementation Services',
                'quantity' => 1,
                'unit_price' => 1500,
                'discount_percent' => 0,
                'tax_percent' => 16,
            ]],
        ], 'user', $this->userId);
    }

    public function testSendUsesSharedEmailPipelineAndRecordsSuccessfulAudit(): void
    {
        $emailService = new class extends EmailService {
            public array $calls = [];

            public function __construct()
            {
            }

            public function sendImmediateDetailed(int $contactId, string $to, string $subject, string $body, array $options = []): array
            {
                $this->calls[] = [
                    'contact_id' => $contactId,
                    'to' => $to,
                    'subject' => $subject,
                    'body' => $body,
                    'attachments' => $options['attachments'] ?? [],
                    'body_html' => $options['body_html'] ?? null,
                    'attachment_exists' => !empty($options['attachments'][0]) && file_exists($options['attachments'][0]),
                ];

                return [
                    'success' => true,
                    'status' => 'sent',
                    'email_id' => 321,
                    'email_uuid' => 'email-uuid-123',
                    'provider_key' => 'smtp',
                    'smtp_method' => 'phpmailer',
                    'communication_id' => 654,
                    'sync_error' => null,
                ];
            }
        };

        $pdfService = new class extends InvoicePdfService {
            public function renderToTemporaryFile(array $invoice, string $prefix = 'invoice_pdf_'): array
            {
                $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('invoice-send-test-', true) . '.pdf';
                file_put_contents($path, '%PDF-1.4 invoice test');
                return ['path' => $path, 'filename' => 'invoice-test.pdf'];
            }
        };

        $service = new InvoiceSendService(
            new Invoices(),
            $pdfService,
            $emailService,
            new InvoiceDeliveryReadinessService(),
            new InvoiceDeliveryAuditService()
        );

        $result = $service->send($this->invoiceId, 'invoice-customer@example.com', $this->userId, 'user');

        $this->assertTrue($result['success']);
        $this->assertSame('sent', $result['delivery_status']);
        $this->assertSame(321, $result['email_id']);
        $this->assertCount(1, $emailService->calls);
        $this->assertTrue($emailService->calls[0]['attachment_exists']);
        $this->assertCount(1, $emailService->calls[0]['attachments']);

        $invoice = Database::queryOne("SELECT status, last_sent_at FROM invoices WHERE workspace_id = 1 AND id = ?", [$this->invoiceId]);
        $this->assertSame('sent', $invoice['status']);
        $this->assertNotEmpty($invoice['last_sent_at']);

        $deliveryLog = Database::queryOne(
            "SELECT * FROM invoice_delivery_log WHERE workspace_id = 1 AND invoice_id = ? ORDER BY id DESC LIMIT 1",
            [$this->invoiceId]
        );
        $this->assertSame('sent', $deliveryLog['delivery_status']);
        $this->assertSame('smtp', $deliveryLog['provider_key']);
        $this->assertSame('phpmailer', $deliveryLog['provider_method']);
        $this->assertSame('email-uuid-123', $deliveryLog['email_uuid']);

        $activity = Database::queryOne(
            "SELECT * FROM invoice_activity_log WHERE workspace_id = 1 AND invoice_id = ? AND action_key = 'invoice_sent' ORDER BY id DESC LIMIT 1",
            [$this->invoiceId]
        );
        $this->assertNotNull($activity);
    }

    public function testSendRejectsInvalidRecipientAndRecordsFailure(): void
    {
        $service = new InvoiceSendService(
            new Invoices(),
            new InvoicePdfService(),
            new class extends EmailService {
                public function __construct()
                {
                }
            },
            new InvoiceDeliveryReadinessService(),
            new InvoiceDeliveryAuditService()
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('A valid recipient email is required');

        try {
            $service->send($this->invoiceId, 'not-an-email', $this->userId, 'user');
        } finally {
            $deliveryLog = Database::queryOne(
                "SELECT * FROM invoice_delivery_log WHERE workspace_id = 1 AND invoice_id = ? ORDER BY id DESC LIMIT 1",
                [$this->invoiceId]
            );
            $this->assertSame('failed', $deliveryLog['delivery_status']);
            $this->assertStringContainsString('valid recipient email', strtolower((string) $deliveryLog['failure_reason']));

            $invoice = Database::queryOne("SELECT status FROM invoices WHERE workspace_id = 1 AND id = ?", [$this->invoiceId]);
            $this->assertSame('draft', $invoice['status']);

            $activity = Database::queryOne(
                "SELECT * FROM invoice_activity_log WHERE workspace_id = 1 AND invoice_id = ? AND action_key = 'invoice_send_failed' ORDER BY id DESC LIMIT 1",
                [$this->invoiceId]
            );
            $this->assertNotNull($activity);
        }
    }

    public function testSendReturnsProviderFailureAndKeepsInvoiceStatusUntouched(): void
    {
        $emailService = new class extends EmailService {
            public function __construct()
            {
            }

            public function sendImmediateDetailed(int $contactId, string $to, string $subject, string $body, array $options = []): array
            {
                return [
                    'success' => false,
                    'status' => 'failed',
                    'error' => 'SMTP rejected the message.',
                    'email_id' => 900,
                    'email_uuid' => 'failed-email-uuid',
                    'provider_key' => 'smtp',
                    'smtp_method' => 'custom_smtp',
                ];
            }
        };

        $pdfService = new class extends InvoicePdfService {
            public function renderToTemporaryFile(array $invoice, string $prefix = 'invoice_pdf_'): array
            {
                $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('invoice-send-fail-', true) . '.pdf';
                file_put_contents($path, '%PDF-1.4 invoice fail test');
                return ['path' => $path, 'filename' => 'invoice-fail-test.pdf'];
            }
        };

        $service = new InvoiceSendService(
            new Invoices(),
            $pdfService,
            $emailService,
            new InvoiceDeliveryReadinessService(),
            new InvoiceDeliveryAuditService()
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('SMTP rejected the message.');

        try {
            $service->send($this->invoiceId, 'invoice-customer@example.com', $this->userId, 'user');
        } finally {
            $invoice = Database::queryOne("SELECT status, last_sent_at FROM invoices WHERE workspace_id = 1 AND id = ?", [$this->invoiceId]);
            $this->assertSame('draft', $invoice['status']);
            $this->assertEmpty($invoice['last_sent_at']);

            $deliveryLog = Database::queryOne(
                "SELECT * FROM invoice_delivery_log WHERE workspace_id = 1 AND invoice_id = ? ORDER BY id DESC LIMIT 1",
                [$this->invoiceId]
            );
            $this->assertSame('failed', $deliveryLog['delivery_status']);
            $this->assertSame('SMTP rejected the message.', $deliveryLog['failure_reason']);
            $this->assertSame('custom_smtp', $deliveryLog['provider_method']);

            $activity = Database::queryOne(
                "SELECT * FROM invoice_activity_log WHERE workspace_id = 1 AND invoice_id = ? AND action_key = 'invoice_send_failed' ORDER BY id DESC LIMIT 1",
                [$this->invoiceId]
            );
            $this->assertNotNull($activity);
        }
    }

    public function testSendBlocksWhenPricingIsIncomplete(): void
    {
        $invoiceId = (new Invoices())->create([
            'document_type' => 'invoice',
            'contact_id' => $this->contactId,
            'created_by' => $this->userId,
            'title' => 'Incomplete Pricing Invoice',
            'billing_name' => 'Invoice Customer',
            'billing_email' => 'invoice-customer@example.com',
            'billing_phone' => '+254711111111',
            'billing_address' => 'Customer Address',
            'line_items' => [[
                'description' => 'Undefined Price Item',
                'quantity' => 1,
                'unit_price' => 0,
                'discount_percent' => 0,
                'tax_percent' => 0,
            ]],
        ], 'user', $this->userId);

        $service = new InvoiceSendService(
            new Invoices(),
            new InvoicePdfService(),
            new class extends EmailService {
                public function __construct()
                {
                }
            },
            new InvoiceDeliveryReadinessService(),
            new InvoiceDeliveryAuditService()
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('One or more line items still have unresolved pricing.');

        try {
            $service->send($invoiceId, 'invoice-customer@example.com', $this->userId, 'user');
        } finally {
            $deliveryLog = Database::queryOne(
                "SELECT * FROM invoice_delivery_log WHERE workspace_id = 1 AND invoice_id = ? ORDER BY id DESC LIMIT 1",
                [$invoiceId]
            );
            $this->assertSame('failed', $deliveryLog['delivery_status']);
            $this->assertStringContainsString('pricing', strtolower((string) $deliveryLog['failure_reason']));
        }
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
