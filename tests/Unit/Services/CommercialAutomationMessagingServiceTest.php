<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Modules\CompanyProfile;
use CRM\Modules\InvoiceSettings;
use CRM\Modules\Invoices;
use CRM\Services\CommercialAutomationMessagingService;
use CRM\Services\InvoiceSendService;
use CRM\Tests\DatabaseTestCase;

class CommercialAutomationMessagingServiceTest extends DatabaseTestCase
{
    public function testEmailSendingUsesSharedInvoiceSendServicePath(): void
    {
        Database::execute(
            "INSERT INTO users (email, password_hash, role, created_at) VALUES (?, ?, 'admin', NOW())",
            ['commercial-message@example.com', password_hash('password', PASSWORD_DEFAULT)]
        );
        $userId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO contacts (workspace_id, first_name, last_name, email, created_at) VALUES (1, ?, ?, ?, NOW())",
            ['Commercial', 'Recipient', 'commercial-recipient@example.com']
        );
        $contactId = (int) Database::lastInsertId();

        (new CompanyProfile())->update([
            'company_legal_name' => 'Mak CRM Ltd',
            'company_email' => 'billing@makcrm.test',
            'company_address' => '123 Invoice Street',
        ]);

        $invoiceId = (new Invoices())->create([
            'document_type' => 'quote',
            'contact_id' => $contactId,
            'created_by' => $userId,
            'title' => 'Commercial Messaging Quote',
            'billing_name' => 'Commercial Recipient',
            'billing_email' => 'commercial-recipient@example.com',
            'billing_address' => 'Customer Address',
            'line_items' => [[
                'description' => 'Commercial Package',
                'quantity' => 1,
                'unit_price' => 500,
                'discount_percent' => 0,
                'tax_percent' => 16,
            ]],
        ], 'user', $userId);

        $sharedSendService = new class extends InvoiceSendService {
            public array $calls = [];

            public function __construct()
            {
            }

            public function send(int $invoiceId, string $recipient, ?int $actorId = null, string $actorType = 'user'): array
            {
                $this->calls[] = [
                    'invoice_id' => $invoiceId,
                    'recipient' => $recipient,
                    'actor_id' => $actorId,
                    'actor_type' => $actorType,
                ];

                return [
                    'success' => true,
                    'recipient' => $recipient,
                    'subject' => 'Shared invoice subject',
                    'delivery_status' => 'sent',
                    'email_uuid' => 'shared-email-uuid',
                    'smtp_method' => 'phpmailer',
                ];
            }
        };

        $service = new CommercialAutomationMessagingService($sharedSendService);
        $result = $service->sendInvoice($invoiceId, 'email', 'commercial-recipient@example.com', $userId, 'system');

        $this->assertTrue($result['success']);
        $this->assertSame('commercial-recipient@example.com', $result['recipient']);
        $this->assertSame('sent', $result['delivery_status']);
        $this->assertCount(1, $sharedSendService->calls);
        $this->assertSame($invoiceId, $sharedSendService->calls[0]['invoice_id']);
        $this->assertSame('system', $sharedSendService->calls[0]['actor_type']);
    }
}
