<?php

namespace CRM\Tests\Integration;

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Modules\WorkspaceBillingSettings;
use CRM\Services\MobileTokenAuthService;
use CRM\Services\SaaSBillingService;
use CRM\Services\WorkspaceProvisioningService;
use CRM\Tests\DatabaseTestCase;
use CRM\Tests\Support\EndpointHarness;

class SaaSBillingEndpointTest extends DatabaseTestCase
{
    use EndpointHarness;

    private int $userId;
    private int $workspaceId;
    private int $membershipId;
    private string $workspaceUuid;
    private string $workspaceSlug;
    private string $workspaceName;

    protected function setUp(): void
    {
        parent::setUp();

        $_ENV['PAYSTACK_FAKE_MODE'] = 'true';
        $_ENV['PAYSTACK_SECRET_KEY'] = 'test-secret';
        $_ENV['PAYSTACK_FAKE_AUTH_BASE_URL'] = 'https://paystack.example/authorize';
        $_ENV['MPESA_FAKE_MODE'] = 'true';
        $_ENV['MPESA_ENABLED'] = 'true';
        $_ENV['MPESA_CALLBACK_URL'] = 'https://crm.example/api/webhooks/mpesa.php';
        putenv('PAYSTACK_FAKE_MODE=true');
        putenv('PAYSTACK_SECRET_KEY=test-secret');
        putenv('PAYSTACK_FAKE_AUTH_BASE_URL=https://paystack.example/authorize');
        putenv('MPESA_FAKE_MODE=true');
        putenv('MPESA_ENABLED=true');
        putenv('MPESA_CALLBACK_URL=https://crm.example/api/webhooks/mpesa.php');

        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Billing Endpoint Workspace',
            'workspace_slug' => 'billing-endpoint-workspace',
            'first_name' => 'Billing',
            'last_name' => 'Owner',
            'email' => 'billing.owner@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $this->workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $this->userId = (int) ($provisioned['user_id'] ?? 0);
        Database::execute(
            "UPDATE users SET role = 'admin' WHERE id = ?",
            [$this->userId]
        );
        $superadminRole = Database::queryOne("SELECT id FROM roles WHERE slug = 'superadmin' LIMIT 1");
        Authorization::assignUserRole($this->userId, (int) ($superadminRole['id'] ?? 0), $this->userId);

        $workspace = Database::queryOne(
            "SELECT uuid, slug, name
             FROM workspaces
             WHERE id = ?",
            [$this->workspaceId]
        ) ?? [];
        $membership = Database::queryOne(
            "SELECT id
             FROM workspace_memberships
             WHERE workspace_id = ?
               AND user_id = ?
             LIMIT 1",
            [$this->workspaceId, $this->userId]
        ) ?? [];

        $this->workspaceUuid = (string) ($workspace['uuid'] ?? '');
        $this->workspaceSlug = (string) ($workspace['slug'] ?? '');
        $this->workspaceName = (string) ($workspace['name'] ?? '');
        $this->membershipId = (int) ($membership['id'] ?? 0);
    }

    protected function tearDown(): void
    {
        putenv('PAYSTACK_FAKE_MODE');
        putenv('PAYSTACK_SECRET_KEY');
        putenv('PAYSTACK_FAKE_AUTH_BASE_URL');
        putenv('MPESA_FAKE_MODE');
        putenv('MPESA_ENABLED');
        putenv('MPESA_CALLBACK_URL');
        unset(
            $_ENV['PAYSTACK_FAKE_MODE'],
            $_ENV['PAYSTACK_SECRET_KEY'],
            $_ENV['PAYSTACK_FAKE_AUTH_BASE_URL'],
            $_ENV['MPESA_FAKE_MODE'],
            $_ENV['MPESA_ENABLED'],
            $_ENV['MPESA_CALLBACK_URL']
        );

        parent::tearDown();
    }

    public function testCheckoutEndpointRejectsEmptySelection(): void
    {
        $response = $this->runWebEndpoint('api/workspace_billing/checkout.php', $this->webSession(), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-billing',
            ],
            'env' => $this->billingEnv(),
        ]);

        $payload = $this->decodeJsonResponse($response);

        $this->assertSame(422, (int) ($response['status'] ?? 0));
        $this->assertSame('Select a workspace package and/or token pack before checkout.', (string) ($payload['error'] ?? ''));
    }

    public function testCheckoutEndpointCreatesSaasCheckoutForActiveWorkspace(): void
    {
        $priceId = $this->paidLaunchPriceId();
        $response = $this->runWebEndpoint('api/workspace_billing/checkout.php', $this->webSession(), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-billing',
                'billing_plan_price_id' => $priceId,
            ],
            'server' => [
                'REMOTE_ADDR' => '10.40.0.11',
            ],
            'env' => $this->billingEnv(),
        ]);

        $payload = $this->decodeJsonResponse($response);
        $data = (array) ($payload['data'] ?? []);
        $session = Database::queryOne(
            "SELECT workspace_id, checkout_type, status
             FROM billing_checkout_sessions
             WHERE id = ?",
            [(int) ($data['checkout_session_id'] ?? 0)]
        );

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['body'] ?? '') . "\n" . (string) ($response['stderr'] ?? ''));
        $this->assertTrue((bool) ($payload['success'] ?? false));
        $this->assertSame('subscription', (string) ($data['checkout_type'] ?? ''));
        $this->assertSame('PLN_endpoint_solo_monthly', (string) ($data['provider_plan_code'] ?? ''));
        $this->assertSame('https://paystack.example/authorize/' . (string) ($data['reference'] ?? ''), (string) ($data['authorization_url'] ?? ''));
        $this->assertSame($this->workspaceId, (int) ($session['workspace_id'] ?? 0));
        $this->assertSame('subscription', (string) ($session['checkout_type'] ?? ''));
        $this->assertSame('pending', (string) ($session['status'] ?? ''));
    }

    public function testCheckoutEndpointIgnoresHostileCallbackUrl(): void
    {
        $tokenPackId = $this->eligibleTokenPackPriceId();
        $response = $this->runWebEndpoint('api/workspace_billing/checkout.php', $this->webSession(), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-billing',
                'token_pack_price_id' => $tokenPackId,
                'callback_url' => 'https://evil.example/paystack-return',
                'return_to' => 'settings.php?tab=billing',
            ],
            'server' => [
                'REMOTE_ADDR' => '10.40.0.15',
            ],
            'env' => $this->billingEnv(),
        ]);

        $payload = $this->decodeJsonResponse($response);
        $data = (array) ($payload['data'] ?? []);
        $session = Database::queryOne(
            "SELECT callback_url, metadata_json
             FROM billing_checkout_sessions
             WHERE id = ?",
            [(int) ($data['checkout_session_id'] ?? 0)]
        );
        $metadata = json_decode((string) ($session['metadata_json'] ?? '{}'), true) ?: [];

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['body'] ?? ''));
        $this->assertSame('settings.php?tab=billing', (string) ($data['return_to'] ?? ''));
        $this->assertStringContainsString('billing_callback.php', (string) ($session['callback_url'] ?? ''));
        $this->assertStringNotContainsString('evil.example', (string) ($session['callback_url'] ?? ''));
        $this->assertSame('settings.php?tab=billing', (string) (($metadata['selection']['return_to'] ?? '')));
    }

    public function testCheckoutEndpointCreatesMpesaChargeForKesWorkspace(): void
    {
        $tokenPackId = $this->eligibleTokenPackPriceId();
        $response = $this->runWebEndpoint('api/workspace_billing/checkout.php', $this->webSession(), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-billing',
                'token_pack_price_id' => $tokenPackId,
                'payment_mode' => 'mpesa',
                'customer_phone' => '254712345678',
            ],
            'server' => [
                'REMOTE_ADDR' => '10.40.0.12',
            ],
            'env' => $this->billingEnv(),
        ]);

        $payload = $this->decodeJsonResponse($response);
        $data = (array) ($payload['data'] ?? []);
        $session = Database::queryOne(
            "SELECT provider, provider_reference, payment_mode, flow_type, customer_phone
             FROM billing_checkout_sessions
             WHERE id = ?",
            [(int) ($data['checkout_session_id'] ?? 0)]
        );

        $this->assertSame(200, (int) ($response['status'] ?? 0));
        $this->assertSame('mpesa', (string) ($session['provider'] ?? ''));
        $this->assertSame((string) ($data['reference'] ?? ''), (string) ($session['provider_reference'] ?? ''));
        $this->assertStringStartsWith('ws_FAKE_', (string) ($data['reference'] ?? ''));
        $this->assertSame('mpesa', (string) ($data['payment_mode'] ?? ''));
        $this->assertSame('offline_charge', (string) ($data['flow_type'] ?? ''));
        $this->assertEmpty((string) ($data['authorization_url'] ?? ''));
        $this->assertSame('mpesa', (string) ($session['payment_mode'] ?? ''));
        $this->assertSame('254712345678', (string) ($session['customer_phone'] ?? ''));
    }

    public function testMpesaWebhookEndpointFinalizesSuccessfulCheckout(): void
    {
        $tokenPackId = $this->eligibleTokenPackPriceId();
        $checkout = (new SaaSBillingService())->createCheckout($this->workspaceId, [
            'token_pack_price_id' => $tokenPackId,
            'payment_mode' => 'mpesa',
            'customer_phone' => '254712345678',
        ], $this->userId);

        $callback = json_encode([
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => 'merchant_endpoint_123',
                    'CheckoutRequestID' => (string) ($checkout['reference'] ?? ''),
                    'ResultCode' => 0,
                    'ResultDesc' => 'The service request is processed successfully.',
                    'CallbackMetadata' => [
                        'Item' => [
                            ['Name' => 'Amount', 'Value' => 2500],
                            ['Name' => 'MpesaReceiptNumber', 'Value' => 'ENDPOINT123'],
                            ['Name' => 'TransactionDate', 'Value' => 20260614140102],
                            ['Name' => 'PhoneNumber', 'Value' => 254712345678],
                        ],
                    ],
                ],
            ],
        ], JSON_UNESCAPED_SLASHES) ?: '{}';

        $response = $this->runWebEndpoint('api/webhooks/mpesa.php', [], [
            'method' => 'POST',
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'raw_body' => $callback,
            'env' => $this->billingEnv(),
        ]);

        $payload = $this->decodeJsonResponse($response);
        $session = Database::queryOne(
            "SELECT status, paid_at, metadata_json
             FROM billing_checkout_sessions
             WHERE id = ?",
            [(int) ($checkout['checkout_session_id'] ?? 0)]
        );
        $metadata = json_decode((string) ($session['metadata_json'] ?? '{}'), true) ?: [];
        $transaction = Database::queryOne(
            "SELECT provider, transaction_status
             FROM billing_transactions
             WHERE checkout_session_id = ?
             ORDER BY id DESC
             LIMIT 1",
            [(int) ($checkout['checkout_session_id'] ?? 0)]
        );

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['body'] ?? ''));
        $this->assertSame(0, (int) ($payload['ResultCode'] ?? -1));
        $this->assertSame('paid', (string) ($session['status'] ?? ''));
        $this->assertSame('2026-06-14 14:01:02', (string) ($session['paid_at'] ?? ''));
        $this->assertSame('ENDPOINT123', (string) ($metadata['receipt_number'] ?? ''));
        $this->assertSame('mpesa', (string) ($transaction['provider'] ?? ''));
        $this->assertSame('succeeded', (string) ($transaction['transaction_status'] ?? ''));
    }

    public function testCheckoutEndpointRejectsMpesaWithoutPhone(): void
    {
        $tokenPackId = $this->eligibleTokenPackPriceId();
        $response = $this->runWebEndpoint('api/workspace_billing/checkout.php', $this->webSession(), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-billing',
                'token_pack_price_id' => $tokenPackId,
                'payment_mode' => 'mpesa',
            ],
            'server' => [
                'REMOTE_ADDR' => '10.40.0.13',
            ],
            'env' => $this->billingEnv(),
        ]);

        $payload = $this->decodeJsonResponse($response);

        $this->assertSame(400, (int) ($response['status'] ?? 0));
        $this->assertSame('A customer phone number is required for the selected payment mode.', (string) ($payload['error'] ?? ''));
    }

    public function testCheckoutEndpointRejectsBankTransferForKesWorkspace(): void
    {
        $tokenPackId = $this->eligibleTokenPackPriceId();
        $response = $this->runWebEndpoint('api/workspace_billing/checkout.php', $this->webSession(), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-billing',
                'token_pack_price_id' => $tokenPackId,
                'payment_mode' => 'bank_transfer',
            ],
            'server' => [
                'REMOTE_ADDR' => '10.40.0.14',
            ],
            'env' => $this->billingEnv(),
        ]);

        $payload = $this->decodeJsonResponse($response);

        $this->assertSame(400, (int) ($response['status'] ?? 0));
        $this->assertSame('Bank transfer is only enabled for NGN-supported workspaces in this slice.', (string) ($payload['error'] ?? ''));
    }

    public function testCheckoutEndpointRateLimitsRepeatedRequests(): void
    {
        $tokenPackId = $this->eligibleTokenPackPriceId();
        $lastResponse = null;
        for ($attempt = 0; $attempt < 7; $attempt++) {
            $lastResponse = $this->runWebEndpoint('api/workspace_billing/checkout.php', $this->webSession(), [
                'method' => 'POST',
                'post' => [
                    'csrf_token' => 'csrf-billing',
                    'token_pack_price_id' => $tokenPackId,
                ],
                'server' => [
                    'REMOTE_ADDR' => '10.40.0.77',
                ],
                'env' => $this->billingEnv(),
            ]);
        }

        $payload = $this->decodeJsonResponse((array) $lastResponse);

        $this->assertSame(429, (int) ($lastResponse['status'] ?? 0));
        $this->assertSame(
            'Too many checkout attempts were made. Please wait a moment before trying again.',
            (string) ($payload['error'] ?? '')
        );
        $this->assertGreaterThan(0, (int) ($payload['retry_after'] ?? 0));
    }

    public function testSummaryEndpointReturnsTransactionsAndAiUsageRollups(): void
    {
        $service = new SaaSBillingService();
        $checkout = $service->createCheckout($this->workspaceId, ['token_pack_price_id' => $this->eligibleTokenPackPriceId()], $this->userId);
        $service->verifyCheckoutReference((string) ($checkout['reference'] ?? ''));

        Database::execute(
            "INSERT INTO workspace_ai_usage
             (workspace_id, user_id, provider, model, feature_key, request_id, input_tokens, output_tokens, billable_tokens, provider_cost, created_at)
             VALUES (?, ?, 'openai', 'gpt-test', 'summary_panel', ?, 55, 65, 120, 0.03, NOW())",
            [$this->workspaceId, $this->userId, 'summary-usage-' . bin2hex(random_bytes(4))]
        );

        $response = $this->runWebEndpoint('api/workspace_billing/summary.php', $this->webSession(), [
            'method' => 'GET',
        ]);

        $payload = $this->decodeJsonResponse($response);
        $saas = (array) (($payload['data']['saas_billing'] ?? []));

        $this->assertSame(200, (int) ($response['status'] ?? 0));
        $this->assertTrue((bool) ($payload['success'] ?? false));
        $this->assertNotEmpty((array) ($saas['transactions'] ?? []));
        $this->assertNotEmpty((array) ($saas['checkout_sessions'] ?? []));
        $this->assertNull($payload['data']['workspace_billing'] ?? null);
        $this->assertSame(120, (int) ($saas['ai_usage']['overview']['total_billable_tokens'] ?? 0));
        $this->assertSame('summary_panel', (string) ($saas['ai_usage']['by_feature'][0]['feature_key'] ?? ''));
    }

    public function testSummaryEndpointIncludesPackageBillingInvoiceMetadata(): void
    {
        $priceId = $this->paidLaunchPriceId('founder-plus-monthly', 'PLN_endpoint_invoice_summary');
        (new WorkspaceBillingSettings())->save([
            'payment_card_enabled' => true,
            'payment_mpesa_enabled' => true,
            'payment_bank_transfer_enabled' => true,
        ], $this->userId);
        $service = new SaaSBillingService();
        $checkout = $service->createCheckout($this->workspaceId, ['billing_plan_price_id' => $priceId], $this->userId);
        $service->verifyCheckoutReference((string) ($checkout['reference'] ?? ''));

        $response = $this->runWebEndpoint('api/workspace_billing/summary.php', $this->webSession(), [
            'method' => 'GET',
            'env' => $this->billingEnv(),
        ]);

        $payload = $this->decodeJsonResponse($response);
        $saas = (array) (($payload['data']['saas_billing'] ?? []));
        $packageTransaction = null;
        foreach ((array) ($saas['transactions'] ?? []) as $transaction) {
            if ((string) ($transaction['transaction_type'] ?? '') === 'subscription_charge') {
                $packageTransaction = $transaction;
                break;
            }
        }
        $billingInvoices = (array) ($saas['billing_invoices'] ?? []);

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['body'] ?? ''));
        $this->assertTrue((bool) ($payload['success'] ?? false));
        $this->assertNotNull($packageTransaction);
        $this->assertNotEmpty($packageTransaction['billing_invoice'] ?? []);
        $this->assertStringStartsWith('PKG-', (string) (($packageTransaction['billing_invoice']['document_number'] ?? '')));
        $this->assertStringEndsWith('billing_invoice.php?id=' . (int) (($packageTransaction['billing_invoice']['id'] ?? 0)), (string) (($packageTransaction['billing_invoice']['url'] ?? '')));
        $this->assertNotEmpty($billingInvoices);
        $this->assertSame((int) (($packageTransaction['billing_invoice']['id'] ?? 0)), (int) (($billingInvoices[0]['id'] ?? 0)));
    }

    public function testSummaryEndpointReturnsSafeReadinessErrorWhenBillingSchemaDrifts(): void
    {
        $this->dropForeignKeysForColumn('billing_transactions', 'subscription_id');
        Database::execute("ALTER TABLE billing_transactions DROP COLUMN subscription_id");

        $response = $this->runWebEndpoint('api/workspace_billing/summary.php', $this->webSession(), [
            'method' => 'GET',
        ]);

        $payload = $this->decodeJsonResponse($response);

        $this->assertSame(503, (int) ($response['status'] ?? 0));
        $this->assertSame(
            'Workspace billing details are temporarily unavailable until the latest SaaS migrations are applied.',
            (string) ($payload['error'] ?? '')
        );
        $this->assertSame('billing_transactions', (string) (($payload['readiness']['issues'][0]['table'] ?? '')));
        $this->assertSame('subscription_id', (string) (($payload['readiness']['issues'][0]['column'] ?? '')));
    }

    public function testMobileBillingPayloadsUseSaasSnapshotWithoutLegacyCompatibility(): void
    {
        $service = new SaaSBillingService();
        $checkout = $service->createCheckout($this->workspaceId, ['token_pack_price_id' => $this->eligibleTokenPackPriceId()], $this->userId);
        $service->verifyCheckoutReference((string) ($checkout['reference'] ?? ''));

        Database::execute(
            "INSERT INTO workspace_ai_usage
             (workspace_id, user_id, provider, model, feature_key, request_id, input_tokens, output_tokens, billable_tokens, provider_cost, created_at)
             VALUES (?, ?, 'openai', 'gpt-test', 'mobile_summary', ?, 35, 45, 80, 0.02, NOW())",
            [$this->workspaceId, $this->userId, 'mobile-usage-' . bin2hex(random_bytes(4))]
        );

        $billingResponse = $this->runEndpointScript('api/mobile/billing.php', [
            'method' => 'GET',
            'headers' => ['Authorization' => 'Bearer ' . $this->mobileAccessToken()],
        ]);
        $meResponse = $this->runEndpointScript('api/mobile/me.php', [
            'method' => 'GET',
            'headers' => ['Authorization' => 'Bearer ' . $this->mobileAccessToken()],
        ]);
        $homeResponse = $this->runEndpointScript('api/mobile/home.php', [
            'method' => 'GET',
            'headers' => ['Authorization' => 'Bearer ' . $this->mobileAccessToken()],
        ]);

        $billingPayload = $this->decodeJsonResponse($billingResponse);
        $mePayload = $this->decodeJsonResponse($meResponse);
        $homePayload = $this->decodeJsonResponse($homeResponse);

        $this->assertSame(200, (int) ($billingResponse['status'] ?? 0));
        $this->assertSame(200, (int) ($meResponse['status'] ?? 0));
        $this->assertSame(200, (int) ($homeResponse['status'] ?? 0));

        $billingData = (array) ($billingPayload['data'] ?? []);
        $meData = (array) ($mePayload['data'] ?? []);
        $homeData = (array) ($homePayload['data'] ?? []);

        $this->assertNull($billingData['workspace_billing'] ?? null);
        $this->assertNull($meData['workspace_billing'] ?? null);
        $this->assertNull($homeData['workspace_billing'] ?? null);

        $this->assertSame('active', (string) ($billingData['subscription_status'] ?? ''));
        $this->assertFalse((bool) ($billingData['billing_blocked'] ?? true));
        $this->assertSame(80, (int) ($billingData['saas_billing']['ai_usage']['overview']['total_billable_tokens'] ?? 0));

        $this->assertSame('active', (string) ($meData['subscription_status'] ?? ''));
        $this->assertSame((int) ($billingData['token_balance'] ?? 0), (int) ($meData['token_balance'] ?? -1));
        $this->assertSame((int) ($billingData['available_tokens'] ?? 0), (int) ($meData['available_tokens'] ?? -1));

        $this->assertSame('active', (string) ($homeData['subscription_status'] ?? ''));
        $this->assertSame((int) ($billingData['token_balance'] ?? 0), (int) ($homeData['token_balance'] ?? -1));
        $this->assertSame((int) ($billingData['available_tokens'] ?? 0), (int) ($homeData['available_tokens'] ?? -1));
        $this->assertNull($homeData['ai_blocked_reason'] ?? null);
    }

    public function testMobileCheckoutFallsBackFromHostileReturnUrl(): void
    {
        $priceId = $this->paidLaunchPriceId();

        $response = $this->runEndpointScript('api/mobile/billing.php', [
            'method' => 'POST',
            'headers' => ['Authorization' => 'Bearer ' . $this->mobileAccessToken()],
            'post' => [
                'action' => 'checkout',
                'billing_plan_price_id' => $priceId,
                'return_url' => 'https://evil.example/mobile-return',
            ],
            'env' => $this->billingEnv(),
        ]);

        $payload = $this->decodeJsonResponse($response);
        $data = (array) ($payload['data'] ?? []);
        $session = Database::queryOne(
            "SELECT callback_url, metadata_json
             FROM billing_checkout_sessions
             WHERE id = ?",
            [(int) ($data['checkout_session_id'] ?? 0)]
        );

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['body'] ?? ''));
        $this->assertTrue((bool) ($payload['success'] ?? false));
        $this->assertStringContainsString('billing_callback.php', (string) ($data['return_to'] ?? ''));
        $this->assertStringNotContainsString('evil.example', (string) ($data['return_to'] ?? ''));
        $this->assertStringContainsString('billing_callback.php', (string) ($session['callback_url'] ?? ''));
        $this->assertStringNotContainsString('evil.example', (string) ($session['callback_url'] ?? ''));
        $this->assertNotSame((string) ($data['return_to'] ?? ''), (string) ($session['callback_url'] ?? ''));
    }

    public function testMobileCheckoutUsesServerCallbackForTrustedDeepLinkReturnUrl(): void
    {
        $priceId = $this->paidLaunchPriceId();
        Database::execute(
            "UPDATE workspace_billing_settings
             SET mobile_return_url = ?
             WHERE id = 1",
            ['crmapp://billing/return']
        );

        $response = $this->runEndpointScript('api/mobile/billing.php', [
            'method' => 'POST',
            'headers' => ['Authorization' => 'Bearer ' . $this->mobileAccessToken()],
            'post' => [
                'action' => 'checkout',
                'billing_plan_price_id' => $priceId,
                'return_url' => 'crmapp://billing/return?checkout=1',
            ],
            'env' => array_merge($this->billingEnv(), [
                'MOBILE_APP_SCHEME' => 'crmapp',
            ]),
        ]);

        $payload = $this->decodeJsonResponse($response);
        $data = (array) ($payload['data'] ?? []);
        $session = Database::queryOne(
            "SELECT callback_url, metadata_json
             FROM billing_checkout_sessions
             WHERE id = ?",
            [(int) ($data['checkout_session_id'] ?? 0)]
        );
        $metadata = json_decode((string) ($session['metadata_json'] ?? '{}'), true) ?: [];

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['body'] ?? ''));
        $this->assertTrue((bool) ($payload['success'] ?? false));
        $this->assertSame('crmapp://billing/return?checkout=1', (string) ($data['return_to'] ?? ''));
        $this->assertSame('crmapp://billing/return?checkout=1', (string) (($metadata['selection']['return_to'] ?? '')));
        $this->assertStringContainsString('billing_callback.php', (string) ($session['callback_url'] ?? ''));
        $this->assertStringContainsString('return_to=crmapp%3A%2F%2Fbilling%2Freturn%3Fcheckout%3D1', (string) ($session['callback_url'] ?? ''));
    }

    public function testMobileCheckoutRateLimitsRepeatedRequests(): void
    {
        $priceId = $this->paidLaunchPriceId();
        $env = array_merge($this->billingEnv(), [
            'LAUNCH_GUARDRAIL_CHECKOUT_ATTEMPTS' => '1',
            'LAUNCH_GUARDRAIL_CHECKOUT_WINDOW' => '60',
        ]);

        $first = $this->runEndpointScript('api/mobile/billing.php', [
            'method' => 'POST',
            'headers' => ['Authorization' => 'Bearer ' . $this->mobileAccessToken()],
            'post' => [
                'action' => 'checkout',
                'billing_plan_price_id' => $priceId,
            ],
            'server' => [
                'REMOTE_ADDR' => '10.40.0.199',
            ],
            'env' => $env,
        ]);
        $second = $this->runEndpointScript('api/mobile/billing.php', [
            'method' => 'POST',
            'headers' => ['Authorization' => 'Bearer ' . $this->mobileAccessToken()],
            'post' => [
                'action' => 'checkout',
                'billing_plan_price_id' => $priceId,
            ],
            'server' => [
                'REMOTE_ADDR' => '10.40.0.199',
            ],
            'env' => $env,
        ]);

        $firstPayload = $this->decodeJsonResponse($first);
        $secondPayload = $this->decodeJsonResponse($second);

        $this->assertSame(200, (int) ($first['status'] ?? 0), (string) ($first['body'] ?? ''));
        $this->assertTrue((bool) ($firstPayload['success'] ?? false));
        $this->assertSame(429, (int) ($second['status'] ?? 0), (string) ($second['body'] ?? ''));
        $this->assertSame(
            'Too many checkout attempts were made. Please wait a moment before trying again.',
            (string) ($secondPayload['error'] ?? '')
        );
        $this->assertGreaterThan(0, (int) ($secondPayload['retry_after'] ?? 0));
    }

    public function testSettingsBillingPageRendersCompactAdminHubAndPortalLinks(): void
    {
        Database::execute(
            "INSERT INTO workspace_ai_usage
             (workspace_id, user_id, provider, model, feature_key, request_id, input_tokens, output_tokens, billable_tokens, provider_cost, created_at)
             VALUES (?, ?, 'openai', 'gpt-test', 'settings_panel', ?, 50, 70, 120, 0.03, NOW())",
            [$this->workspaceId, $this->userId, 'settings-usage-' . bin2hex(random_bytes(4))]
        );

        $response = $this->runWebEndpoint('public/settings.php', $this->webSession(), [
            'method' => 'GET',
            'query' => ['tab' => 'billing'],
            'env' => $this->billingEnv(),
        ]);

        $body = (string) ($response['body'] ?? '');

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertStringContainsString('Billing admin', $body);
        $this->assertStringContainsString('Payment portal', $body);
        $this->assertStringContainsString('Review current limits, upgrades, and plugin unlocks.', $body);
        $this->assertStringContainsString('billing_payment_required.php?tab=packages#workspace-packages', $body);
        $this->assertStringContainsString('billing_payment_required.php?tab=tokens#ai-token-refill', $body);
        $this->assertStringContainsString('billing_payment_required.php?tab=activity', $body);
        $this->assertStringContainsString('billing_payment_required.php?tab=help', $body);
        $this->assertStringContainsString('Workspace billing status', $body);
        $this->assertStringContainsString('Included AI Credits', $body);
        $this->assertStringContainsString('Package capability:', $body);
        $this->assertStringContainsString('Top-up packs are separate from recurring package AI Credits.', $body);
        $this->assertStringContainsString('AI usage snapshot', $body);
        $this->assertStringNotContainsString('billing_start_payment.php', $body);
        $this->assertStringNotContainsString('name="billing_plan_price_id"', $body);
        $this->assertStringNotContainsString('name="token_pack_price_id"', $body);
        $this->assertStringNotContainsString('Billing transactions', $body);
        $this->assertStringNotContainsString('Checkout sessions', $body);
        $this->assertStringNotContainsString('Usage by feature', $body);
        $this->assertStringNotContainsString('Usage by user', $body);
        $this->assertStringNotContainsString('Payment method', $body);
    }

    public function testSettingsBillingPageShowsReadinessWarningInsteadOfFatalWhenSchemaDrifts(): void
    {
        $this->dropForeignKeysForColumn('billing_transactions', 'subscription_id');
        Database::execute("ALTER TABLE billing_transactions DROP COLUMN subscription_id");

        $response = $this->runWebEndpoint('public/settings.php', $this->webSession(), [
            'method' => 'GET',
            'query' => ['tab' => 'billing'],
            'env' => $this->billingEnv(),
        ]);

        $body = (string) ($response['body'] ?? '');

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertStringContainsString('Billing readiness warning', $body);
        $this->assertStringContainsString('Workspace billing details are temporarily unavailable until the latest SaaS migrations are applied.', $body);
    }

    public function testPaymentRequiredAndWorkspacesPagesUseSaasSnapshotMessaging(): void
    {
        $this->saveDefaultWorkspaceLegalName('Clarity Systems Legal Ltd');

        $paymentRequiredResponse = $this->runWebEndpoint('public/billing_payment_required.php', $this->webSession(), [
            'method' => 'GET',
            'env' => $this->billingEnv(),
        ]);
        $packagesResponse = $this->runWebEndpoint('public/billing_payment_required.php', $this->webSession(), [
            'method' => 'GET',
            'query' => ['tab' => 'packages'],
            'env' => $this->billingEnv(),
        ]);
        $tokensResponse = $this->runWebEndpoint('public/billing_payment_required.php', $this->webSession(), [
            'method' => 'GET',
            'query' => ['tab' => 'tokens'],
            'env' => $this->billingEnv(),
        ]);
        $activityResponse = $this->runWebEndpoint('public/billing_payment_required.php', $this->webSession(), [
            'method' => 'GET',
            'query' => ['tab' => 'activity'],
            'env' => $this->billingEnv(),
        ]);
        $helpResponse = $this->runWebEndpoint('public/billing_payment_required.php', $this->webSession(), [
            'method' => 'GET',
            'query' => ['tab' => 'help'],
            'env' => $this->billingEnv(),
        ]);
        $invalidTabResponse = $this->runWebEndpoint('public/billing_payment_required.php', $this->webSession(), [
            'method' => 'GET',
            'query' => ['tab' => 'unknown'],
            'env' => $this->billingEnv(),
        ]);
        $workspaceResponse = $this->runWebEndpoint('public/workspaces.php', $this->webSession(), [
            'method' => 'GET',
            'env' => $this->billingEnv(),
        ]);

        $paymentBody = (string) ($paymentRequiredResponse['body'] ?? '');
        $packagesBody = (string) ($packagesResponse['body'] ?? '');
        $tokensBody = (string) ($tokensResponse['body'] ?? '');
        $activityBody = (string) ($activityResponse['body'] ?? '');
        $helpBody = (string) ($helpResponse['body'] ?? '');
        $invalidTabBody = (string) ($invalidTabResponse['body'] ?? '');
        $workspaceBody = (string) ($workspaceResponse['body'] ?? '');

        $this->assertSame(200, (int) ($paymentRequiredResponse['status'] ?? 0), (string) ($paymentRequiredResponse['stderr'] ?? ''));
        $this->assertStringContainsString('billing-required-tabs', $paymentBody);
        $this->assertStringContainsString('billing_payment_required.php?tab=tokens#ai-token-refill', $paymentBody);
        $this->assertStringContainsString('Workspace packages', $paymentBody);
        $this->assertStringContainsString('System legal name: <strong>Clarity Systems Legal Ltd</strong>', $paymentBody);
        $this->assertStringNotContainsString('Plans unlock capability. AI Credits control usage. Plugins expand the system as the business matures.', $paymentBody);
        $this->assertStringNotContainsString('Current package status', $paymentBody);
        $this->assertStringNotContainsString('AI actions are blocked until prepaid AI Credits are topped up', $paymentBody);
        $this->assertStringNotContainsString('Recent payment activity', $paymentBody);

        $this->assertSame(200, (int) ($packagesResponse['status'] ?? 0), (string) ($packagesResponse['stderr'] ?? ''));
        $this->assertStringContainsString('Workspace packages', $packagesBody);
        $this->assertStringContainsString('billing-required-page--packages', $packagesBody);
        $this->assertStringNotContainsString('Plans unlock capability. AI Credits control usage. Plugins expand the system as the business matures.', $packagesBody);
        $this->assertStringNotContainsString('Current package status', $packagesBody);
        $this->assertStringNotContainsString('Credits expiring soon', $packagesBody);
        $this->assertStringContainsString('Current package', $packagesBody);
        $this->assertStringContainsString('Package capability', $packagesBody);
        $this->assertStringContainsString('launch-package-modal__capability', $packagesBody);
        $this->assertStringContainsString('Business Intelligence included', $packagesBody);
        $this->assertStringContainsString('Personal API key included', $packagesBody);
        $this->assertStringContainsString('180-day credit expiry', $packagesBody);
        $this->assertStringContainsString('AI Credit top-ups stay separate from package changes.', $packagesBody);
        $this->assertStringContainsString('Book now', $packagesBody);
        $this->assertStringContainsString('data-package-booking-trigger', $packagesBody);
        $this->assertStringContainsString('data-package-booking-calendar', $packagesBody);
        $this->assertStringContainsString('data-package-booking-slot', $packagesBody);
        $this->assertStringContainsString('name="meeting_slot"', $packagesBody);
        $this->assertStringNotContainsString('<select name="meeting_slot"', $packagesBody);
        $this->assertStringContainsString('Account and workspace details will be added automatically.', $packagesBody);
        $this->assertStringNotContainsString('owner_support.php?tab=setup&amp;prefill=package_sales&amp;package_code=scale-custom', $packagesBody);
        $this->assertStringNotContainsString('name="meeting_date"', $packagesBody);
        $this->assertStringNotContainsString('name="meeting_time"', $packagesBody);
        $this->assertStringNotContainsString('mailto:', $packagesBody);
        $this->assertStringContainsString('billing_payment_required.php?tab=packages', $packagesBody);
        $this->assertStringContainsString('id="package-details-modal"', $packagesBody);
        $this->assertStringContainsString('class="launch-package-modal"', $packagesBody);
        $this->assertStringContainsString('data-package-modal', $packagesBody);
        $this->assertStringContainsString('aria-controls="package-details-modal"', $packagesBody);
        $this->assertStringContainsString('aria-expanded="false"', $packagesBody);
        $this->assertStringNotContainsString('billing-required-card-metrics', $packagesBody);
        $this->assertStringNotContainsString('billing-required-card-metric', $packagesBody);
        $this->assertStringNotContainsString('aria-label="Capability summary"', $packagesBody);
        $this->assertStringNotContainsString('data-package-detail-panels', $packagesBody);
        $this->assertStringNotContainsString('class="launch-package-detail-panel"', $packagesBody);
        $this->assertStringNotContainsString('Recent payment activity', $packagesBody);

        $this->assertSame(200, (int) ($tokensResponse['status'] ?? 0), (string) ($tokensResponse['stderr'] ?? ''));
        $this->assertStringContainsString('Buy AI Credits', $tokensBody);
        $this->assertStringContainsString('data-billing-payment-mode-fields', $tokensBody);
        $this->assertStringContainsString('data-billing-payment-mode-select', $tokensBody);
        $this->assertStringContainsString('data-requires-phone="0"', $tokensBody);
        $this->assertStringContainsString('phoneField.hidden = !requiresPhone;', $tokensBody);
        $this->assertStringContainsString('phoneInput.required = requiresPhone;', $tokensBody);

        $this->assertSame(200, (int) ($activityResponse['status'] ?? 0), (string) ($activityResponse['stderr'] ?? ''));
        $this->assertStringContainsString('Recent payment activity', $activityBody);
        $this->assertStringContainsString('Latest checkout', $activityBody);
        $this->assertStringNotContainsString('Start Package', $activityBody);

        $this->assertSame(200, (int) ($helpResponse['status'] ?? 0), (string) ($helpResponse['stderr'] ?? ''));
        $this->assertStringContainsString('Recovery checklist', $helpBody);
        $this->assertStringContainsString('Workspace affordability support', $helpBody);
        $this->assertStringNotContainsString('Recent payment activity', $helpBody);

        $this->assertSame(200, (int) ($invalidTabResponse['status'] ?? 0), (string) ($invalidTabResponse['stderr'] ?? ''));
        $this->assertStringContainsString('Workspace packages', $invalidTabBody);

        $this->assertSame(200, (int) ($workspaceResponse['status'] ?? 0), (string) ($workspaceResponse['stderr'] ?? ''));
        $this->assertStringContainsString('Workspaces', $workspaceBody);
        $this->assertStringContainsString('Billing Endpoint Workspace', $workspaceBody);
    }

    public function testPackageReceiptLinksAndDocumentEndpointAccessAreWorkspaceScoped(): void
    {
        $invoiceContext = $this->createPackageInvoiceForNewWorkspace('receipt-document-owner', 'receipt.document.owner@example.com');
        $invoiceId = (int) ($invoiceContext['invoice']['id'] ?? 0);
        $documentNumber = (string) ($invoiceContext['invoice']['document_number'] ?? '');
        $ownerSession = $this->workspaceSessionFor((int) $invoiceContext['workspace_id'], (int) $invoiceContext['user_id']);

        $activityResponse = $this->runWebEndpoint('public/billing_payment_required.php', $ownerSession, [
            'method' => 'GET',
            'query' => ['tab' => 'activity'],
            'env' => $this->billingEnv(),
        ]);
        $adminResponse = $this->runWebEndpoint('public/workspace_admin.php', $this->webSession(), [
            'method' => 'GET',
            'query' => ['workspace_id' => (int) $invoiceContext['workspace_id']],
            'env' => $this->billingEnv(),
        ]);
        $superAdminReceipt = $this->runWebEndpoint('public/billing_invoice.php', $this->webSession(), [
            'method' => 'GET',
            'query' => ['id' => $invoiceId],
            'env' => $this->billingEnv(),
        ]);

        $foreign = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => 'Foreign Receipt Workspace',
            'workspace_slug' => 'foreign-receipt-workspace-' . substr(hash('sha256', microtime(true)), 0, 8),
            'first_name' => 'Foreign',
            'last_name' => 'Owner',
            'email' => 'foreign.receipt.' . substr(hash('sha256', microtime(true)), 0, 8) . '@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);
        Authorization::assignUserRoleBySlug((int) ($foreign['user_id'] ?? 0), 'owner', $this->userId);
        Authorization::resetCaches();
        $foreignReceipt = $this->runWebEndpoint('public/billing_invoice.php', $this->workspaceSessionFor((int) $foreign['workspace_id'], (int) $foreign['user_id']), [
            'method' => 'GET',
            'query' => ['id' => $invoiceId],
            'env' => $this->billingEnv(),
        ]);

        $this->assertGreaterThan(0, $invoiceId);
        $this->assertSame(200, (int) ($activityResponse['status'] ?? 0), (string) ($activityResponse['stderr'] ?? ''));
        $this->assertStringContainsString('billing_invoice.php?id=' . $invoiceId, (string) ($activityResponse['body'] ?? ''));
        $this->assertStringContainsString('Receipt', (string) ($activityResponse['body'] ?? ''));
        $this->assertSame(200, (int) ($adminResponse['status'] ?? 0), (string) ($adminResponse['stderr'] ?? ''));
        $this->assertStringContainsString('billing_invoice.php?id=' . $invoiceId, (string) ($adminResponse['body'] ?? ''));
        $this->assertSame(200, (int) ($superAdminReceipt['status'] ?? 0), (string) ($superAdminReceipt['stderr'] ?? ''));
        $this->assertStringContainsString($documentNumber, (string) ($superAdminReceipt['body'] ?? ''));
        $this->assertSame(404, (int) ($foreignReceipt['status'] ?? 0), (string) ($foreignReceipt['body'] ?? ''));
    }

    public function testPackagePortalUsesMpesaCheckoutControlsWhenCardIsDisabled(): void
    {
        (new WorkspaceBillingSettings())->save([
            'payment_card_enabled' => false,
            'payment_mpesa_enabled' => true,
            'payment_bank_transfer_enabled' => true,
        ], $this->userId);
        $this->paidLaunchPriceId();

        $packagesResponse = $this->runWebEndpoint('public/billing_payment_required.php', $this->webSession(), [
            'method' => 'GET',
            'query' => ['tab' => 'packages'],
            'env' => $this->billingEnv(),
        ]);
        $body = (string) ($packagesResponse['body'] ?? '');

        $this->assertSame(200, (int) ($packagesResponse['status'] ?? 0), (string) ($packagesResponse['stderr'] ?? ''));
        $this->assertStringContainsString('billing_start_payment.php', $body);
        $this->assertStringContainsString('<option value="mpesa" data-requires-phone="1" selected>', $body);
        $this->assertStringContainsString('name="customer_phone"', $body);
        $this->assertStringContainsString('data-billing-payment-phone-input', $body);
        $this->assertStringNotContainsString('<option value="card"', $body);
        $this->assertStringNotContainsString('Paystack billing is not ready.', $body);
        $this->assertStringNotContainsString('name="payment_mode" value="card"', $body);
    }

    public function testPackagePortalCentersRemainingCardsWhenPackagesAreDisabled(): void
    {
        Database::execute(
            "UPDATE billing_plan_prices
             SET is_active = 0
             WHERE price_code IN (?, ?, ?, ?)",
            [
                'solo-launch-monthly',
                'solo-launch-annual',
                'founder-plus-monthly',
                'founder-plus-annual',
            ]
        );

        $packagesResponse = $this->runWebEndpoint('public/billing_payment_required.php', $this->webSession(), [
            'method' => 'GET',
            'query' => ['tab' => 'packages'],
            'env' => $this->billingEnv(),
        ]);
        $body = (string) ($packagesResponse['body'] ?? '');

        $this->assertSame(200, (int) ($packagesResponse['status'] ?? 0), (string) ($packagesResponse['stderr'] ?? ''));
        $this->assertStringContainsString('billing-required-grid billing-packages-grid is-many', $body);
        $this->assertStringContainsString('data-package-code="compass-free"', $body);
        $this->assertStringContainsString('data-package-code="growth-studio"', $body);
        $this->assertStringContainsString('data-package-code="scale-custom"', $body);
        $this->assertStringNotContainsString('data-package-code="solo-launch"', $body);
        $this->assertStringNotContainsString('data-package-code="founder-plus"', $body);
        $this->assertStringContainsString('Compass Free', $body);
        $this->assertStringContainsString('Growth Studio', $body);
        $this->assertStringContainsString('Scale Custom', $body);
        $this->assertStringNotContainsString('Solo Launch', $body);
        $this->assertMatchesRegularExpression(
            '/\\.billing-required-page--packages \\.billing-packages-grid\\s*\\{[^}]*display:\\s*flex;[^}]*flex-wrap:\\s*wrap;[^}]*justify-content:\\s*center;/s',
            $body
        );
        $this->assertMatchesRegularExpression(
            '/\\.billing-required-page--packages \\.billing-required-package-card\\s*\\{[^}]*flex:\\s*0 1 clamp\\(13\\.75rem, 16vw, 15\\.5rem\\);/s',
            $body
        );
    }

    public function testPackagePortalSchedulesAndCancelsSelfServiceDowngrade(): void
    {
        $currentPriceId = $this->paidLaunchPriceId('growth-studio-monthly', 'PLN_endpoint_growth_monthly');
        $targetPriceId = $this->paidLaunchPriceId('solo-launch-monthly', 'PLN_endpoint_solo_monthly');
        $this->setEndpointPaystackSubscription($currentPriceId);

        $packagesResponse = $this->runWebEndpoint('public/billing_payment_required.php', $this->webSession(), [
            'method' => 'GET',
            'query' => ['tab' => 'packages'],
            'env' => $this->billingEnv(),
        ]);
        $packagesBody = (string) ($packagesResponse['body'] ?? '');

        $this->assertSame(200, (int) ($packagesResponse['status'] ?? 0), (string) ($packagesResponse['stderr'] ?? ''));
        $this->assertStringContainsString('Upgrade now', $packagesBody);
        $this->assertStringContainsString('Schedule downgrade', $packagesBody);
        $this->assertStringContainsString('billing_change_package.php', $packagesBody);

        $scheduleResponse = $this->runWebEndpoint('public/billing_change_package.php', $this->webSession(), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-billing',
                'billing_change_action' => 'schedule_downgrade',
                'billing_plan_price_id' => $targetPriceId,
                'return_to' => 'billing_payment_required.php?tab=packages',
            ],
            'env' => $this->billingEnv(),
        ]);
        $subscription = Database::queryOne("SELECT * FROM workspace_subscriptions WHERE workspace_id = ? ORDER BY id DESC LIMIT 1", [$this->workspaceId]) ?? [];
        $metadata = json_decode((string) ($subscription['scheduled_change_metadata_json'] ?? '{}'), true) ?: [];

        $this->assertSame(302, (int) ($scheduleResponse['status'] ?? 0), (string) ($scheduleResponse['body'] ?? ''));
        $this->assertSame($targetPriceId, (int) ($subscription['scheduled_billing_plan_price_id'] ?? 0));
        $this->assertSame('downgrade', (string) ($subscription['scheduled_change_type'] ?? ''));
        $this->assertStringStartsWith('SUB_', (string) ($metadata['target_provider_subscription_code'] ?? ''));

        $scheduledPage = $this->runWebEndpoint('public/billing_payment_required.php', $this->webSession(), [
            'method' => 'GET',
            'query' => ['tab' => 'packages'],
            'env' => $this->billingEnv(),
        ]);
        $scheduledBody = (string) ($scheduledPage['body'] ?? '');
        $this->assertStringContainsString('Scheduled downgrade', $scheduledBody);
        $this->assertStringContainsString('Cancel scheduled change', $scheduledBody);

        $cancelResponse = $this->runWebEndpoint('public/billing_change_package.php', $this->webSession(), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-billing',
                'billing_change_action' => 'cancel_scheduled_change',
                'return_to' => 'billing_payment_required.php?tab=packages',
            ],
            'env' => $this->billingEnv(),
        ]);
        $afterCancel = Database::queryOne("SELECT * FROM workspace_subscriptions WHERE workspace_id = ? ORDER BY id DESC LIMIT 1", [$this->workspaceId]) ?? [];

        $this->assertSame(302, (int) ($cancelResponse['status'] ?? 0), (string) ($cancelResponse['body'] ?? ''));
        $this->assertNull($afterCancel['scheduled_billing_plan_price_id'] ?? null);
        $this->assertNull($afterCancel['scheduled_change_type'] ?? null);
    }

    public function testPackageChangeEndpointRejectsInvalidCsrfToken(): void
    {
        $response = $this->runWebEndpoint('public/billing_change_package.php', $this->webSession(), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'wrong-token',
                'billing_change_action' => 'cancel_scheduled_change',
                'return_to' => 'billing_payment_required.php?tab=packages',
            ],
            'env' => $this->billingEnv(),
        ]);

        $this->assertSame(403, (int) ($response['status'] ?? 0));
        $this->assertStringContainsString('Invalid security token', (string) ($response['body'] ?? ''));
    }

    public function testBillingCallbackRedirectsToWorkspaceBillingViewOnSuccess(): void
    {
        $priceId = $this->paidLaunchPriceId();
        $service = new SaaSBillingService();
        $checkout = $service->createCheckout($this->workspaceId, ['billing_plan_price_id' => $priceId], $this->userId);

        $response = $this->runWebEndpoint('public/billing_callback.php', $this->webSession(), [
            'method' => 'GET',
            'query' => [
                'reference' => (string) ($checkout['reference'] ?? ''),
            ],
            'env' => $this->billingEnv(),
        ]);

        $session = Database::queryOne(
            "SELECT status, paid_at
             FROM billing_checkout_sessions
             WHERE provider_reference = ?
             LIMIT 1",
            [(string) ($checkout['reference'] ?? '')]
        );

        $this->assertSame(302, (int) ($response['status'] ?? 0));
        $this->assertSame('paid', (string) ($session['status'] ?? ''));
        $this->assertNotEmpty((string) ($session['paid_at'] ?? ''));
    }

    public function testBillingCallbackReportsUpdatedBillingWhenAdminSuspensionStillBlocksAccess(): void
    {
        $priceId = $this->paidLaunchPriceId();
        $service = new SaaSBillingService();
        $checkout = $service->createCheckout($this->workspaceId, ['billing_plan_price_id' => $priceId], $this->userId);
        Database::execute(
            "UPDATE workspaces
             SET status = 'suspended',
                 plan_status = 'past_due'
             WHERE id = ?",
            [$this->workspaceId]
        );

        $response = $this->runEndpointScript('public/billing_callback.php', [
            'method' => 'GET',
            'query' => [
                'reference' => (string) ($checkout['reference'] ?? ''),
            ],
            'env' => $this->billingEnv(),
        ]);
        $workspace = Database::queryOne("SELECT status, plan_status FROM workspaces WHERE id = ? LIMIT 1", [$this->workspaceId]);

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['body'] ?? ''));
        $this->assertStringContainsString('Billing status has been updated', (string) ($response['body'] ?? ''));
        $this->assertStringNotContainsString('Workspace access has been restored', (string) ($response['body'] ?? ''));
        $this->assertSame('suspended', (string) ($workspace['status'] ?? ''));
        $this->assertSame('active', (string) ($workspace['plan_status'] ?? ''));
    }

    public function testWorkspaceAdminPageRendersOperatorPanelsForPlatformAdmin(): void
    {
        $response = $this->runWebEndpoint('public/workspace_admin.php', $this->webSession(), [
            'method' => 'GET',
            'query' => ['workspace_id' => $this->workspaceId],
            'env' => $this->billingEnv(),
        ]);

        $body = (string) ($response['body'] ?? '');

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertStringContainsString('Workspace Lifecycle', $body);
        $this->assertStringContainsString('Manual AI Credit Adjustment', $body);
        $this->assertStringContainsString('Provider Events', $body);
        $this->assertStringContainsString('Impersonate Workspace', $body);
    }

    public function testWorkspaceAdminPageShowsLaunchReadinessPanelWhenSchemaDrifts(): void
    {
        $this->dropForeignKeysForColumn('billing_transactions', 'subscription_id');
        Database::execute("ALTER TABLE billing_transactions DROP COLUMN subscription_id");

        $response = $this->runWebEndpoint('public/workspace_admin.php', $this->webSession(), [
            'method' => 'GET',
            'query' => ['workspace_id' => $this->workspaceId],
            'env' => $this->billingEnv(),
        ]);

        $body = (string) ($response['body'] ?? '');

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['stderr'] ?? ''));
        $this->assertStringContainsString('Launch Readiness', $body);
        $this->assertStringContainsString('Launch Gate', $body);
        $this->assertStringContainsString('Ready Surfaces', $body);
        $this->assertStringContainsString('Migration drift detected', $body);
        $this->assertStringContainsString('Missing required column `billing_transactions.subscription_id`.', $body);
    }

    public function testWorkspaceAdminPageRejectsNonPlatformUser(): void
    {
        $viewerUserId = (int) (Auth::createUser('viewer.billing@example.com', 'P@ssword123!', 'viewer', 'Viewer', 'User') ?? 0);
        $this->assertGreaterThan(0, $viewerUserId);

        $response = $this->runWebEndpoint('public/workspace_admin.php', [
            'user_id' => $viewerUserId,
            'user_uuid' => 'viewer-user',
            'user_email' => 'viewer.billing@example.com',
            'user_role' => 'viewer',
            'csrf_token' => 'csrf-billing',
            '__remember_restore_attempted' => true,
        ], [
            'method' => 'GET',
            'query' => ['workspace_id' => $this->workspaceId],
            'env' => $this->billingEnv(),
        ]);

        $this->assertSame(302, (int) ($response['status'] ?? 0));
    }

    /**
     * @return array<string,mixed>
     */
    private function webSession(): array
    {
        return [
            'user_id' => $this->userId,
            'user_uuid' => 'billing-owner',
            'user_email' => 'billing.owner@example.com',
            'user_role' => 'admin',
            'active_workspace_id' => $this->workspaceId,
            'active_workspace_uuid' => $this->workspaceUuid,
            'active_workspace_slug' => $this->workspaceSlug,
            'active_workspace_name' => $this->workspaceName,
            'active_workspace_role' => 'owner',
            'active_workspace_membership_id' => $this->membershipId,
            'csrf_token' => 'csrf-billing',
            '__remember_restore_attempted' => true,
        ];
    }

    /**
     * @return array<string,string>
     */
    private function billingEnv(): array
    {
        return [
            'PAYSTACK_FAKE_MODE' => 'true',
            'PAYSTACK_SECRET_KEY' => 'test-secret',
            'PAYSTACK_FAKE_AUTH_BASE_URL' => 'https://paystack.example/authorize',
            'MPESA_FAKE_MODE' => 'true',
            'MPESA_ENABLED' => 'true',
            'MPESA_CALLBACK_URL' => 'https://crm.example/api/webhooks/mpesa.php',
        ];
    }

    private function mobileAccessToken(): string
    {
        $service = new MobileTokenAuthService();
        $session = $service->issueTokenPair([
            'id' => $this->userId,
            'uuid' => 'billing-owner',
            'email' => 'billing.owner@example.com',
            'role' => 'admin',
        ], [
            'workspace_id' => $this->workspaceId,
            'workspace_slug' => $this->workspaceSlug,
        ]);

        return (string) ($session['access_token'] ?? '');
    }

    /**
     * @return array{workspace_id:int,user_id:int,invoice:array<string,mixed>}
     */
    private function createPackageInvoiceForNewWorkspace(string $slug, string $email): array
    {
        $suffix = substr(hash('sha256', $slug . $email . microtime(true)), 0, 8);
        $provisioned = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
            'workspace_name' => ucwords(str_replace('-', ' ', $slug)),
            'workspace_slug' => $slug . '-' . $suffix,
            'first_name' => 'Receipt',
            'last_name' => 'Owner',
            'email' => $email,
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);
        $workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $userId = (int) ($provisioned['user_id'] ?? 0);
        Authorization::assignUserRoleBySlug($userId, 'owner', $this->userId);
        Authorization::resetCaches();

        $priceId = $this->paidLaunchPriceId('founder-plus-monthly', 'PLN_endpoint_receipt_' . $suffix);
        (new WorkspaceBillingSettings())->save([
            'payment_card_enabled' => true,
            'payment_mpesa_enabled' => true,
            'payment_bank_transfer_enabled' => true,
        ], $userId);
        $service = new SaaSBillingService();
        $checkout = $service->createCheckout($workspaceId, ['billing_plan_price_id' => $priceId], $userId);
        $service->verifyCheckoutReference((string) ($checkout['reference'] ?? ''));

        $invoice = Database::queryOne(
            "SELECT bi.*
             FROM billing_invoices bi
             JOIN billing_transactions bt ON bt.id = bi.billing_transaction_id
             WHERE bt.checkout_session_id = ?
             ORDER BY bi.id DESC
             LIMIT 1",
            [(int) ($checkout['checkout_session_id'] ?? 0)]
        ) ?? [];

        return [
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'invoice' => $invoice,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function workspaceSessionFor(int $workspaceId, int $userId): array
    {
        $workspace = Database::queryOne(
            "SELECT uuid, slug, name
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
            [$workspaceId, $userId]
        ) ?? [];
        $user = Database::queryOne(
            "SELECT uuid, email, role
             FROM users
             WHERE id = ?
             LIMIT 1",
            [$userId]
        ) ?? [];

        return [
            'user_id' => $userId,
            'user_uuid' => (string) ($user['uuid'] ?? ''),
            'user_email' => (string) ($user['email'] ?? ''),
            'user_role' => (string) ($user['role'] ?? 'admin'),
            'active_workspace_id' => $workspaceId,
            'active_workspace_uuid' => (string) ($workspace['uuid'] ?? ''),
            'active_workspace_slug' => (string) ($workspace['slug'] ?? ''),
            'active_workspace_name' => (string) ($workspace['name'] ?? ''),
            'active_workspace_role' => (string) ($membership['role_slug'] ?? 'owner'),
            'active_workspace_membership_id' => (int) ($membership['id'] ?? 0),
            'csrf_token' => 'csrf-billing',
            '__remember_restore_attempted' => true,
        ];
    }

    private function eligibleTokenPackPriceId(): int
    {
        $this->makeWorkspaceTopUpEligible();
        return $this->tokenPackPriceId();
    }

    private function makeWorkspaceTopUpEligible(): void
    {
        $priceId = $this->paidLaunchPriceId();
        $reference = 'endpoint-topup-eligible-' . $this->workspaceId;
        $existing = Database::queryOne(
            "SELECT id
             FROM workspace_subscriptions
             WHERE provider_reference = ?
             LIMIT 1",
            [$reference]
        );

        if ($existing) {
            Database::execute(
                "UPDATE workspace_subscriptions
                 SET billing_plan_price_id = ?,
                     subscription_status = 'active',
                     renewal_status = 'renewing',
                     current_period_start = NOW(),
                     current_period_end = DATE_ADD(NOW(), INTERVAL 30 DAY),
                     next_billing_at = DATE_ADD(NOW(), INTERVAL 30 DAY),
                     updated_at = NOW()
                 WHERE id = ?",
                [$priceId, (int) $existing['id']]
            );
            return;
        }

        Database::execute(
            "INSERT INTO workspace_subscriptions (
                workspace_id,
                billing_plan_price_id,
                provider,
                provider_reference,
                subscription_status,
                renewal_status,
                current_period_start,
                current_period_end,
                next_billing_at,
                created_by
             ) VALUES (?, ?, 'test', ?, 'active', 'renewing', NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), DATE_ADD(NOW(), INTERVAL 30 DAY), ?)",
            [$this->workspaceId, $priceId, $reference, $this->userId]
        );
    }

    private function tokenPackPriceId(): int
    {
        $pack = Database::queryOne(
            "SELECT tpp.id
             FROM token_pack_prices tpp
             JOIN billing_plan_prices bpp ON bpp.id = tpp.billing_plan_price_id
             JOIN billing_plans bp ON bp.id = bpp.plan_id
             WHERE tpp.is_active = 1
               AND bpp.is_active = 1
               AND bp.is_active = 1
             ORDER BY tpp.sort_order ASC, tpp.id ASC
             LIMIT 1"
        );
        $this->assertNotEmpty($pack['id'] ?? null, 'Expected an active token pack price.');
        return (int) $pack['id'];
    }

    private function setEndpointPaystackSubscription(int $priceId): void
    {
        Database::execute(
            "UPDATE workspace_subscriptions
             SET billing_plan_price_id = ?,
                 provider = 'paystack',
                 provider_reference = ?,
                 provider_subscription_code = ?,
                 provider_customer_code = ?,
                 provider_email_token = ?,
                 provider_subscription_status = 'active',
                 renewal_status = 'renewing',
                 provider_metadata_json = ?,
                 subscription_status = 'active',
                 current_period_start = NOW(),
                 current_period_end = DATE_ADD(NOW(), INTERVAL 30 DAY),
                 next_billing_at = DATE_ADD(NOW(), INTERVAL 30 DAY),
                 scheduled_billing_plan_price_id = NULL,
                 scheduled_change_type = NULL,
                 scheduled_change_at = NULL,
                 scheduled_change_metadata_json = NULL,
                 updated_at = NOW()
             WHERE workspace_id = ?",
            [
                $priceId,
                'endpoint_current_' . $this->workspaceId,
                'SUB_endpoint_current_' . $this->workspaceId,
                'CUS_endpoint_current_' . $this->workspaceId,
                'tok_endpoint_current_' . $this->workspaceId,
                json_encode([
                    'authorization' => [
                        'authorization_code' => 'AUTH_endpoint_current_' . $this->workspaceId,
                    ],
                ], JSON_UNESCAPED_SLASHES),
                $this->workspaceId,
            ]
        );
        Database::execute("UPDATE workspaces SET status = 'active', plan_status = 'active' WHERE id = ?", [$this->workspaceId]);
    }

    private function paidLaunchPriceId(string $priceCode = 'solo-launch-monthly', string $providerPlanCode = 'PLN_endpoint_solo_monthly'): int
    {
        $price = Database::queryOne(
            "SELECT id
             FROM billing_plan_prices
             WHERE price_code = ?
             LIMIT 1"
            ,
            [$priceCode]
        );
        $this->assertNotEmpty($price['id'] ?? null, 'Expected launch price ' . $priceCode . ' to exist.');
        Database::execute(
            "UPDATE billing_plan_prices
             SET provider = 'paystack',
                 provider_plan_code = ?,
                 provider_plan_status = 'active',
                 provider_plan_synced_at = NOW()
             WHERE id = ?",
            [$providerPlanCode, (int) $price['id']]
        );

        return (int) $price['id'];
    }

    private function saveDefaultWorkspaceLegalName(string $legalName): void
    {
        $profile = Database::queryOne(
            "SELECT id
             FROM company_profile
             WHERE workspace_id = 1
               AND is_active = 1
             ORDER BY id ASC
             LIMIT 1"
        );

        if ($profile) {
            Database::execute(
                "UPDATE company_profile
                 SET company_name = 'Clarity Platform Operations HQ',
                     company_legal_name = ?,
                     is_active = 1
                 WHERE id = ?",
                [$legalName, (int) $profile['id']]
            );
            return;
        }

        Database::execute(
            "INSERT INTO company_profile (workspace_id, company_name, company_legal_name, is_active)
             VALUES (1, 'Clarity Platform Operations HQ', ?, 1)",
            [$legalName]
        );
    }

    private function dropForeignKeysForColumn(string $table, string $column): void
    {
        $config = $this->currentTestDatabaseConfig();
        $rows = Database::query(
            "SELECT CONSTRAINT_NAME
             FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = ?
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
               AND REFERENCED_TABLE_NAME IS NOT NULL",
            [$config['name'], $table, $column]
        );

        foreach ($rows as $row) {
            $constraintName = (string) ($row['CONSTRAINT_NAME'] ?? '');
            if ($constraintName === '' || !preg_match('/^[A-Za-z0-9_]+$/', $constraintName)) {
                continue;
            }

            Database::execute("ALTER TABLE {$table} DROP FOREIGN KEY {$constraintName}");
        }
    }

    /**
     * @param array<string,mixed> $response
     * @return array<string,mixed>
     */
    private function decodeJsonResponse(array $response): array
    {
        $decoded = json_decode((string) ($response['body'] ?? ''), true);
        $this->assertIsArray($decoded, 'Response body was not valid JSON. STDERR: ' . trim((string) ($response['stderr'] ?? '')));
        return $decoded;
    }
}
