<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\WhatsAppComplianceService;
use CRM\Services\WhatsAppFeatureGate;
use CRM\Services\WhatsAppTemplateService;
use CRM\Services\WorkspaceConnectService;
use CRM\Services\WorkspaceWhatsAppCreditService;
use CRM\Tests\DatabaseTestCase;

class WhatsAppManagedModeHardeningTest extends DatabaseTestCase
{
    public function testManagedBillingIsDisabledExceptAllowlistedWorkspace(): void
    {
        $workspaceId = $this->createWorkspace('whatsapp-hardening-gate');
        $gate = new WhatsAppFeatureGate();

        $this->assertTrue($gate->templateCenterEnabled(1));
        $this->assertTrue($gate->dualSetupModesEnabled(1));
        $this->assertTrue($gate->managedBillingEnabled(1));
        $this->assertFalse($gate->managedBillingEnabled($workspaceId));
    }

    public function testSuppressionUpsertKeepsOneActiveRowAndAllowsReleasedHistory(): void
    {
        $service = new WhatsAppComplianceService();
        $service->suppressPhone(1, '+254 700 100 200', 'First suppression');
        $service->suppressPhone(1, '254700100200', 'Updated suppression');

        $this->assertTrue($service->isSuppressed(1, '+254700100200'));
        $this->assertSame(1, $this->activeSuppressionCount(1, '254700100200'));

        $service->releaseSuppression(1, '+254700100200', 'test release');
        $this->assertFalse($service->isSuppressed(1, '+254700100200'));
        $this->assertSame(0, $this->activeSuppressionCount(1, '254700100200'));

        $service->suppressPhone(1, '+254700100200', 'Re-suppressed');
        $this->assertSame(1, $this->activeSuppressionCount(1, '254700100200'));
        $this->assertGreaterThanOrEqual(2, $this->totalSuppressionCount(1, '254700100200'));
    }

    public function testTemplateSyncStoresApprovedAndRejectedProviderStatuses(): void
    {
        (new WorkspaceConnectService())->storeManualWhatsAppIntegration(1, 0, [
            'phone_number_id' => 'template-sync-phone',
            'display_phone_number' => '+254700000001',
            'access_token' => 'EAA_template_sync_token',
            'whatsapp_business_account_id' => 'waba-template-sync',
        ]);

        $service = new FakeWhatsAppTemplateSyncService([
            [
                'id' => 'tpl-approved',
                'name' => 'invoice_ready',
                'status' => 'APPROVED',
                'language' => 'en_US',
                'category' => 'UTILITY',
                'body_text' => 'Hi {{1}}, your invoice is ready.',
                'parameter_schema' => ['body' => [['key' => '1', 'type' => 'text']]],
                'provider_payload' => ['id' => 'tpl-approved', 'status' => 'APPROVED'],
            ],
            [
                'id' => 'tpl-rejected',
                'name' => 'promo_blast',
                'status' => 'REJECTED',
                'language' => 'en_US',
                'category' => 'MARKETING',
                'body_text' => 'Buy now',
                'rejection_reason' => 'Template is too promotional for the selected category.',
                'parameter_schema' => ['body' => []],
                'provider_payload' => ['id' => 'tpl-rejected', 'status' => 'REJECTED', 'rejected_reason' => 'Template is too promotional for the selected category.'],
            ],
        ]);

        $result = $service->syncFromProvider(1);
        $this->assertSame(2, (int) ($result['synced'] ?? 0));

        $approved = Database::queryOne(
            "SELECT status, category FROM workspace_whatsapp_templates WHERE workspace_id = 1 AND template_name = 'invoice_ready' LIMIT 1"
        );
        $rejected = Database::queryOne(
            "SELECT status, category, rejection_reason FROM workspace_whatsapp_templates WHERE workspace_id = 1 AND template_name = 'promo_blast' LIMIT 1"
        );

        $this->assertSame('approved', (string) ($approved['status'] ?? ''));
        $this->assertSame('utility', (string) ($approved['category'] ?? ''));
        $this->assertSame('rejected', (string) ($rejected['status'] ?? ''));
        $this->assertSame('marketing', (string) ($rejected['category'] ?? ''));
        $this->assertStringContainsString('too promotional', (string) ($rejected['rejection_reason'] ?? ''));
    }

    public function testManagedBillingReserveDebitReleaseAndDuplicateWebhookIdempotency(): void
    {
        $this->upsertManagedIntegration(1);
        $credits = new WorkspaceWhatsAppCreditService();
        $credits->ensureWallet(1, null, 'KES');
        $credits->grantCredits(1, 5.0, 'test', 'managed-hardening-topup', null, [], 'Test WhatsApp top-up');

        $deliveredMessageId = $this->insertManagedMessage(1, 'wamid.managed.delivered');
        $reservation = $credits->reserveForMessage(1, $deliveredMessageId);
        $this->assertTrue((bool) ($reservation['reserved'] ?? false));

        $delivered = $credits->settleDeliveredWebhook([
            'id' => 'wamid.managed.delivered',
            'status' => 'delivered',
            'timestamp' => (string) time(),
        ], 1);
        $this->assertTrue((bool) ($delivered['debited'] ?? false));

        $duplicate = $credits->settleDeliveredWebhook([
            'id' => 'wamid.managed.delivered',
            'status' => 'delivered',
            'timestamp' => (string) time(),
        ], 1);
        $this->assertTrue((bool) ($duplicate['duplicate'] ?? false));

        $failedMessageId = $this->insertManagedMessage(1, 'wamid.managed.failed');
        $credits->reserveForMessage(1, $failedMessageId);
        $failed = $credits->settleDeliveredWebhook([
            'id' => 'wamid.managed.failed',
            'status' => 'failed',
            'timestamp' => (string) time(),
        ], 1);
        $this->assertNotEmpty($failed['released'] ?? []);

        $failedMessage = Database::queryOne("SELECT billing_status FROM whatsapp_messages WHERE id = ? LIMIT 1", [$failedMessageId]);
        $this->assertSame('released', (string) ($failedMessage['billing_status'] ?? ''));
    }

    private function createWorkspace(string $slug): int
    {
        Database::execute(
            "INSERT INTO workspaces (uuid, name, slug, status, plan_status, created_at, updated_at)
             VALUES (?, ?, ?, 'active', 'trialing', NOW(), NOW())",
            [uuid_v4(), ucwords(str_replace('-', ' ', $slug)), $slug . '-' . bin2hex(random_bytes(4))]
        );

        return (int) Database::lastInsertId();
    }

    private function activeSuppressionCount(int $workspaceId, string $phone): int
    {
        return (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM workspace_whatsapp_suppression_list
             WHERE workspace_id = ?
               AND phone_number = ?
               AND released_at IS NULL",
            [$workspaceId, $phone]
        )['c'] ?? 0);
    }

    private function totalSuppressionCount(int $workspaceId, string $phone): int
    {
        return (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM workspace_whatsapp_suppression_list
             WHERE workspace_id = ?
               AND phone_number = ?",
            [$workspaceId, $phone]
        )['c'] ?? 0);
    }

    private function upsertManagedIntegration(int $workspaceId): void
    {
        Database::execute(
            "INSERT INTO workspace_whatsapp_integrations
                (workspace_id, connection_mode, connection_status, managed_status, managed_billing_status,
                 phone_number_id, display_phone_number, whatsapp_business_account_id, access_token, connected_at)
             VALUES (?, 'platform_managed', 'connected', 'active', 'active', 'managed-phone', '+254700000010', 'managed-waba', 'managed-token', NOW())
             ON DUPLICATE KEY UPDATE
                connection_mode = VALUES(connection_mode),
                connection_status = VALUES(connection_status),
                managed_status = VALUES(managed_status),
                managed_billing_status = VALUES(managed_billing_status),
                phone_number_id = VALUES(phone_number_id),
                whatsapp_business_account_id = VALUES(whatsapp_business_account_id),
                access_token = VALUES(access_token),
                connected_at = NOW()",
            [$workspaceId]
        );
    }

    private function insertManagedMessage(int $workspaceId, string $providerMessageId): int
    {
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, phone, created_at)
             VALUES (?, ?, 'Managed', 'Contact', ?, '254700555100', NOW())",
            [$workspaceId, uuid_v4(), str_replace('.', '-', $providerMessageId) . '@example.test']
        );
        $contactId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO whatsapp_messages
                (workspace_id, uuid, contact_id, to_number, message_type, message_body, template_name,
                 template_category, whatsapp_message_id, status, direction, connection_mode, estimated_cost, billing_status)
             VALUES (?, ?, ?, '254700555100', 'template', 'Managed template body', 'invoice_ready',
                     'utility', ?, 'sent', 'outbound', 'platform_managed', 0.5, 'estimated')",
            [$workspaceId, uuid_v4(), $contactId, $providerMessageId]
        );

        return (int) Database::lastInsertId();
    }
}

class FakeWhatsAppTemplateSyncService extends WhatsAppTemplateService
{
    /**
     * @param array<int,array<string,mixed>> $catalog
     */
    public function __construct(private array $catalog)
    {
    }

    protected function providerTemplateCatalog(): array
    {
        return $this->catalog;
    }
}
