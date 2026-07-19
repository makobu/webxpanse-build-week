<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\WorkspacePackageBillingInvoiceService;
use CRM\Services\WorkspaceProvisioningService;
use CRM\Tests\DatabaseTestCase;

class WorkspacePackageBillingInvoiceServiceTest extends DatabaseTestCase
{
    public function testIssuesOneInvoiceForSuccessfulPackageTransaction(): void
    {
        $context = $this->createPackageTransaction([
            'slug' => 'billing-invoice-success',
            'email' => 'billing.invoice.success@example.com',
            'price_code' => 'founder-plus-monthly',
            'provider_reference' => 'txn_invoice_success_001',
            'amount' => 6500,
            'included_tokens' => 500,
        ]);

        $service = new WorkspacePackageBillingInvoiceService();
        $invoice = $service->issueForSubscriptionTransaction($context['transaction_id']);
        $again = $service->issueForSubscriptionTransaction($context['transaction_id']);

        $count = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM billing_invoices
             WHERE billing_transaction_id = ?",
            [$context['transaction_id']]
        )['c'] ?? 0);

        $this->assertNotNull($invoice);
        $this->assertSame((int) ($invoice['id'] ?? 0), (int) ($again['id'] ?? 0));
        $this->assertSame(1, $count);
        $this->assertSame('transaction:' . $context['transaction_id'], (string) ($invoice['document_key'] ?? ''));
        $this->assertStringStartsWith('PKG-' . date('Ymd') . '-', (string) ($invoice['document_number'] ?? ''));
        $this->assertSame($context['workspace_id'], (int) ($invoice['workspace_id'] ?? 0));
        $this->assertSame($context['subscription_id'], (int) ($invoice['subscription_id'] ?? 0));
        $this->assertSame($context['price_id'], (int) ($invoice['billing_plan_price_id'] ?? 0));
        $this->assertSame('paystack', (string) ($invoice['provider'] ?? ''));
        $this->assertSame('Founder Plus', (string) ($invoice['package_name'] ?? ''));
        $this->assertSame('billing.invoice.success@example.com', (string) ($invoice['buyer_email'] ?? ''));
        $this->assertSame(6500.0, (float) ($invoice['amount'] ?? 0));
        $this->assertSame(0.0, (float) ($invoice['tax_total'] ?? -1));
        $this->assertSame(6500.0, (float) ($invoice['grand_total'] ?? 0));
        $this->assertSame('subscription_transaction', (string) (($invoice['metadata']['source'] ?? '')));
        $this->assertSame(500, (int) (($invoice['metadata']['included_credits'] ?? 0)));
        $this->assertStringEndsWith('billing_invoice.php?id=' . (int) $invoice['id'], (string) (($invoice['summary']['url'] ?? '')));
    }

    public function testSkipsFailedNonPackageMissingPriceAndDefaultWorkspaceTransactions(): void
    {
        $failed = $this->createPackageTransaction([
            'slug' => 'billing-invoice-failed',
            'email' => 'billing.invoice.failed@example.com',
            'provider_reference' => 'txn_invoice_failed_001',
            'transaction_status' => 'failed',
        ]);
        $tokenPack = $this->createNonPackageTransaction('token_pack_purchase', 'token_pack', 'txn_invoice_token_001');
        $donation = $this->createNonPackageTransaction('donation', 'donation', 'txn_invoice_donation_001');
        $missingPrice = $this->createNonPackageTransaction('subscription_charge', 'subscription', 'txn_invoice_missing_price_001');
        $defaultWorkspace = $this->createPackageTransaction([
            'workspace_id' => 1,
            'user_id' => $this->defaultWorkspaceUserId(),
            'provider_reference' => 'txn_invoice_default_workspace_001',
        ]);

        $service = new WorkspacePackageBillingInvoiceService();

        $this->assertNull($service->issueForSubscriptionTransaction($failed['transaction_id']));
        $this->assertNull($service->issueForSubscriptionTransaction($tokenPack));
        $this->assertNull($service->issueForSubscriptionTransaction($donation));
        $this->assertNull($service->issueForSubscriptionTransaction($missingPrice));
        $this->assertNull($service->issueForSubscriptionTransaction($defaultWorkspace['transaction_id']));
        $this->assertSame(0, (int) (Database::queryOne("SELECT COUNT(*) AS c FROM billing_invoices")['c'] ?? 0));
    }

    public function testRendersMaturePackageReceiptHtmlAndPdfSafeMarkup(): void
    {
        $context = $this->createPackageTransaction([
            'slug' => 'billing-invoice-render',
            'email' => 'billing.invoice.render@example.com',
            'price_code' => 'founder-plus-monthly',
            'provider_reference' => 'txn_invoice_render_001',
            'amount' => 6500,
        ]);

        $service = new WorkspacePackageBillingInvoiceService();
        $invoice = $service->issueForSubscriptionTransaction($context['transaction_id']);
        $this->assertNotNull($invoice);

        $html = $service->renderHtml($invoice);
        $this->assertStringContainsString('<h1 class="headline">Package Receipt</h1>', $html);
        $this->assertStringContainsString('Document No.', $html);
        $this->assertStringContainsString((string) ($invoice['document_number'] ?? ''), $html);
        $this->assertStringContainsString('Paid', $html);
        $this->assertStringContainsString('Service Period', $html);
        $this->assertStringContainsString('Founder Plus', $html);
        $this->assertStringContainsString('billing.invoice.render@example.com', $html);
        $this->assertStringContainsString('Total Paid', $html);

        $pdfHtml = $service->renderHtml($invoice, true);
        $this->assertStringContainsString('Package Receipt', $pdfHtml);
        $this->assertStringContainsString('Service Period', $pdfHtml);
        $this->assertStringNotContainsString('display:flex', strtolower($pdfHtml));
        $this->assertStringNotContainsString('display:grid', strtolower($pdfHtml));
    }

    public function testRendersNonEmptyPackageReceiptPdfFile(): void
    {
        if (!class_exists(\TCPDF::class)) {
            $this->markTestSkipped('TCPDF library is not available.');
        }

        $context = $this->createPackageTransaction([
            'slug' => 'billing-invoice-pdf',
            'email' => 'billing.invoice.pdf@example.com',
            'provider_reference' => 'txn_invoice_pdf_001',
        ]);

        $service = new WorkspacePackageBillingInvoiceService();
        $invoice = $service->issueForSubscriptionTransaction($context['transaction_id']);
        $this->assertNotNull($invoice);

        $tmpBase = tempnam(sys_get_temp_dir(), 'package_receipt_test_');
        $this->assertNotFalse($tmpBase);
        $pdfFile = $tmpBase . '.pdf';
        @unlink($tmpBase);

        try {
            $service->renderPdfToFile($invoice, $pdfFile);
            $this->assertFileExists($pdfFile);
            $this->assertGreaterThan(1000, (int) filesize($pdfFile));
        } finally {
            @unlink($pdfFile);
        }
    }

    public function testIssuesOneOperatorActivationInvoiceForSameActivePeriod(): void
    {
        $provisioned = $this->provisionWorkspace('billing-invoice-operator', 'billing.invoice.operator@example.com');
        $workspaceId = (int) $provisioned['workspace_id'];
        $userId = (int) $provisioned['user_id'];
        $priceId = $this->packagePriceId('growth-studio-monthly', 11900, 900);
        $periodStart = date('Y-m-d H:i:s', strtotime('-2 hours'));
        $periodEnd = date('Y-m-d H:i:s', strtotime('+30 days'));

        Database::execute(
            "INSERT INTO workspace_subscriptions
             (workspace_id, billing_plan_price_id, provider, provider_reference, provider_subscription_status,
              renewal_status, subscription_status, current_period_start, current_period_end, next_billing_at, created_by)
             VALUES (?, ?, 'operator', 'operator_invoice_test', 'active', 'manual', 'active', ?, ?, ?, ?)",
            [$workspaceId, $priceId, $periodStart, $periodEnd, $periodEnd, $userId]
        );
        $subscriptionId = (int) Database::lastInsertId();

        $service = new WorkspacePackageBillingInvoiceService();
        $invoice = $service->issueForOperatorActivation($workspaceId, $subscriptionId, $priceId, $userId, null, 'Manual package activation');
        $again = $service->issueForOperatorActivation($workspaceId, $subscriptionId, $priceId, $userId, null, 'Manual package activation replay');

        $count = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM billing_invoices
             WHERE document_key = ?",
            ['operator_activation:' . $subscriptionId . ':' . $priceId . ':' . $periodStart]
        )['c'] ?? 0);

        $this->assertNotNull($invoice);
        $this->assertSame((int) ($invoice['id'] ?? 0), (int) ($again['id'] ?? 0));
        $this->assertSame(1, $count);
        $this->assertSame('operator', (string) ($invoice['provider'] ?? ''));
        $this->assertSame('operator_activation', (string) (($invoice['metadata']['source'] ?? '')));
        $this->assertSame('Manual package activation', (string) (($invoice['metadata']['reason'] ?? '')));
        $this->assertSame(11900.0, (float) ($invoice['amount'] ?? 0));
        $this->assertStringContainsString('Activated', $service->renderHtml($invoice));
    }

    /**
     * @param array<string,mixed> $options
     * @return array{workspace_id:int,user_id:int,price_id:int,subscription_id:int,checkout_id:int,transaction_id:int}
     */
    private function createPackageTransaction(array $options = []): array
    {
        $workspaceId = (int) ($options['workspace_id'] ?? 0);
        $userId = (int) ($options['user_id'] ?? 0);
        if ($workspaceId <= 0 || $userId <= 0) {
            $provisioned = $this->provisionWorkspace(
                (string) ($options['slug'] ?? 'billing-invoice-workspace'),
                (string) ($options['email'] ?? 'billing.invoice.workspace@example.com')
            );
            $workspaceId = (int) $provisioned['workspace_id'];
            $userId = (int) $provisioned['user_id'];
        }

        $priceId = $this->packagePriceId(
            (string) ($options['price_code'] ?? 'solo-launch-monthly'),
            (float) ($options['amount'] ?? 1990),
            (int) ($options['included_tokens'] ?? 125)
        );
        $periodStart = date('Y-m-d H:i:s');
        $periodEnd = date('Y-m-d H:i:s', strtotime('+1 month'));
        $reference = (string) ($options['provider_reference'] ?? ('txn_invoice_' . bin2hex(random_bytes(5))));

        Database::execute(
            "INSERT INTO workspace_subscriptions
             (workspace_id, billing_plan_price_id, provider, provider_reference, provider_subscription_code,
              provider_customer_code, provider_subscription_status, renewal_status, subscription_status,
              current_period_start, current_period_end, next_billing_at, created_by)
             VALUES (?, ?, ?, ?, 'SUB_invoice_test', 'CUS_invoice_test', 'active', 'renewing', 'active', ?, ?, ?, ?)",
            [
                $workspaceId,
                $priceId,
                (string) ($options['provider'] ?? 'paystack'),
                'sub_' . $reference,
                $periodStart,
                $periodEnd,
                $periodEnd,
                $userId,
            ]
        );
        $subscriptionId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO billing_checkout_sessions
             (workspace_id, user_id, provider, checkout_type, provider_reference, status, currency, amount,
              billing_plan_price_id, subscription_id, payment_mode, flow_type, paid_at)
             VALUES (?, ?, ?, 'subscription', ?, 'paid', 'KES', ?, ?, ?, ?, ?, NOW())",
            [
                $workspaceId,
                $userId,
                (string) ($options['provider'] ?? 'paystack'),
                'checkout_' . $reference,
                (float) ($options['amount'] ?? 1990),
                $priceId,
                $subscriptionId,
                (string) ($options['payment_mode'] ?? 'card'),
                (string) ($options['flow_type'] ?? 'redirect'),
            ]
        );
        $checkoutId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO billing_transactions
             (workspace_id, checkout_session_id, subscription_id, provider, provider_reference, provider_subscription_code,
              provider_customer_code, transaction_type, transaction_status, payment_mode, flow_type, amount, currency, metadata_json)
             VALUES (?, ?, ?, ?, ?, 'SUB_invoice_test', 'CUS_invoice_test', 'subscription_charge', ?, ?, ?, ?, 'KES', ?)",
            [
                $workspaceId,
                $checkoutId,
                $subscriptionId,
                (string) ($options['provider'] ?? 'paystack'),
                $reference,
                (string) ($options['transaction_status'] ?? 'succeeded'),
                (string) ($options['payment_mode'] ?? 'card'),
                (string) ($options['flow_type'] ?? 'redirect'),
                (float) ($options['amount'] ?? 1990),
                json_encode(['test' => true], JSON_UNESCAPED_SLASHES),
            ]
        );

        return [
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'price_id' => $priceId,
            'subscription_id' => $subscriptionId,
            'checkout_id' => $checkoutId,
            'transaction_id' => (int) Database::lastInsertId(),
        ];
    }

    private function createNonPackageTransaction(string $transactionType, string $checkoutType, string $reference): int
    {
        $provisioned = $this->provisionWorkspace('billing-invoice-skip-' . $checkoutType, 'billing.invoice.' . $checkoutType . '@example.com');

        Database::execute(
            "INSERT INTO billing_checkout_sessions
             (workspace_id, user_id, provider, checkout_type, provider_reference, status, currency, amount, payment_mode, flow_type, paid_at)
             VALUES (?, ?, 'paystack', ?, ?, 'paid', 'KES', 100, 'card', 'redirect', NOW())",
            [(int) $provisioned['workspace_id'], (int) $provisioned['user_id'], $checkoutType, 'checkout_' . $reference]
        );
        $checkoutId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO billing_transactions
             (workspace_id, checkout_session_id, subscription_id, provider, provider_reference,
              transaction_type, transaction_status, payment_mode, flow_type, amount, currency, metadata_json)
             VALUES (?, ?, NULL, 'paystack', ?, ?, 'succeeded', 'card', 'redirect', 100, 'KES', ?)",
            [
                (int) $provisioned['workspace_id'],
                $checkoutId,
                $reference,
                $transactionType,
                json_encode(['test' => true], JSON_UNESCAPED_SLASHES),
            ]
        );

        return (int) Database::lastInsertId();
    }

    /**
     * @return array{workspace_id:int,user_id:int}
     */
    private function provisionWorkspace(string $slug, string $email): array
    {
        $slug = $slug . '-' . substr(hash('sha256', $email . microtime(true)), 0, 8);

        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => ucwords(str_replace('-', ' ', $slug)),
            'workspace_slug' => $slug,
            'first_name' => 'Billing',
            'last_name' => 'Invoice',
            'email' => $email,
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        return [
            'workspace_id' => (int) ($provisioned['workspace_id'] ?? 0),
            'user_id' => (int) ($provisioned['user_id'] ?? 0),
        ];
    }

    private function packagePriceId(string $priceCode, float $amount, int $includedTokens): int
    {
        $price = Database::queryOne(
            "SELECT id
             FROM billing_plan_prices
             WHERE price_code = ?
             LIMIT 1",
            [$priceCode]
        );
        $this->assertNotEmpty($price['id'] ?? null, 'Expected package price ' . $priceCode . ' to exist.');

        Database::execute(
            "UPDATE billing_plan_prices
             SET provider = 'paystack',
                 provider_plan_code = ?,
                 provider_plan_status = 'active',
                 provider_plan_synced_at = NOW(),
                 amount = ?,
                 included_tokens = ?,
                 is_active = 1
             WHERE id = ?",
            ['PLN_invoice_' . preg_replace('/[^a-z0-9]+/', '_', $priceCode), $amount, $includedTokens, (int) $price['id']]
        );

        return (int) $price['id'];
    }

    private function defaultWorkspaceUserId(): int
    {
        $row = Database::queryOne(
            "SELECT user_id
             FROM workspace_memberships
             WHERE workspace_id = 1
             ORDER BY is_owner DESC, id ASC
             LIMIT 1"
        );

        $userId = (int) ($row['user_id'] ?? 0);
        if ($userId > 0) {
            return $userId;
        }

        $user = Database::queryOne("SELECT id FROM users ORDER BY id ASC LIMIT 1");
        $this->assertNotEmpty($user['id'] ?? null, 'Expected at least one seeded user.');
        return (int) $user['id'];
    }
}
