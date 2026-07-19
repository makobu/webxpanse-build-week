<?php

namespace CRM\Tests\Integration;

use CRM\Database;
use CRM\Modules\WorkspaceBillingSettings;
use CRM\Services\SaaSBillingService;
use CRM\Services\WorkspaceProvisioningService;
use CRM\Services\WorkspaceWalletService;
use CRM\Tests\DatabaseTestCase;
use CRM\Tests\Support\EndpointHarness;

class DonationCheckoutEndpointTest extends DatabaseTestCase
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
            'workspace_name' => 'Donation Endpoint Workspace',
            'workspace_slug' => 'donation-endpoint-workspace',
            'first_name' => 'Donation',
            'last_name' => 'Owner',
            'email' => 'donation.owner@example.com',
            'password' => 'P@ssword123!',
            'starter_token_pack' => false,
        ]);

        $this->workspaceId = (int) ($provisioned['workspace_id'] ?? 0);
        $this->userId = (int) ($provisioned['user_id'] ?? 0);
        Database::execute("UPDATE users SET role = 'admin' WHERE id = ?", [$this->userId]);

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

    public function testDonationCheckoutEndpointRejectsEmptyAmount(): void
    {
        $response = $this->runWebEndpoint('api/donations/checkout.php', $this->webSession(), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-donation',
                'amount' => 0,
            ],
            'env' => $this->billingEnv(),
        ]);

        $payload = $this->decodeJsonResponse($response);

        $this->assertSame(422, (int) ($response['status'] ?? 0));
        $this->assertSame('Enter a donation amount greater than zero.', (string) ($payload['error'] ?? ''));
    }

    public function testDonationCheckoutEndpointRejectsWhenDonationSupportIsDisabled(): void
    {
        (new WorkspaceBillingSettings())->save([
            'donations_enabled' => false,
        ]);

        $response = $this->runWebEndpoint('api/donations/checkout.php', $this->publicSession(), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-public-donation',
                'amount' => 250,
                'currency' => 'KES',
                'payment_mode' => 'card',
                'customer_email' => 'donor@example.com',
            ],
            'server' => [
                'REMOTE_ADDR' => '10.50.1.10',
            ],
            'env' => $this->billingEnv(),
        ]);

        $payload = $this->decodeJsonResponse($response);

        $this->assertSame(403, (int) ($response['status'] ?? 0));
        $this->assertSame('Donation support is currently unavailable.', (string) ($payload['error'] ?? ''));
    }

    public function testDonationCheckoutCreatesAndFinalizesWithoutWalletCredit(): void
    {
        $wallets = new WorkspaceWalletService();
        $beforeWallet = $wallets->getSummary($this->workspaceId);

        $response = $this->runWebEndpoint('api/donations/checkout.php', $this->webSession(), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-donation',
                'amount' => 250,
                'currency' => 'KES',
                'payment_mode' => 'card',
                'customer_phone' => '254712345678',
                'message' => 'Keep founders free',
            ],
            'server' => [
                'REMOTE_ADDR' => '10.50.0.11',
            ],
            'env' => $this->billingEnv(),
        ]);

        $payload = $this->decodeJsonResponse($response);
        $data = (array) ($payload['data'] ?? []);
        $session = Database::queryOne(
            "SELECT id, checkout_type, status, amount, token_pack_price_id, billing_plan_price_id, customer_phone, metadata_json
             FROM billing_checkout_sessions
             WHERE id = ?
             LIMIT 1",
            [(int) ($data['checkout_session_id'] ?? 0)]
        );

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['body'] ?? ''));
        $this->assertTrue((bool) ($payload['success'] ?? false));
        $this->assertSame('donation', (string) ($data['checkout_type'] ?? ''));
        $this->assertSame('donation', (string) ($session['checkout_type'] ?? ''));
        $this->assertSame('pending', (string) ($session['status'] ?? ''));
        $this->assertNull($session['token_pack_price_id'] ?? null);
        $this->assertNull($session['billing_plan_price_id'] ?? null);
        $this->assertNull($session['customer_phone'] ?? null);
        $metadata = json_decode((string) ($session['metadata_json'] ?? '{}'), true);
        $this->assertNull($metadata['selection']['customer_phone'] ?? null);

        $result = (new SaaSBillingService())->verifyCheckoutReference((string) ($data['reference'] ?? ''));
        $transaction = Database::queryOne(
            "SELECT transaction_type, transaction_status, amount, wallet_ledger_id, subscription_id
             FROM billing_transactions
             WHERE checkout_session_id = ?
             LIMIT 1",
            [(int) ($data['checkout_session_id'] ?? 0)]
        );
        $afterWallet = $wallets->getSummary($this->workspaceId);

        $this->assertTrue((bool) ($result['success'] ?? false));
        $this->assertSame('donation', (string) ($transaction['transaction_type'] ?? ''));
        $this->assertSame('succeeded', (string) ($transaction['transaction_status'] ?? ''));
        $this->assertNull($transaction['wallet_ledger_id'] ?? null);
        $this->assertNull($transaction['subscription_id'] ?? null);
        $this->assertSame((int) ($beforeWallet['token_balance'] ?? 0), (int) ($afterWallet['token_balance'] ?? -1));
    }

    public function testDonationCheckoutRejectsMpesaWithoutPhone(): void
    {
        $response = $this->runWebEndpoint('api/donations/checkout.php', $this->webSession(), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-donation',
                'amount' => 500,
                'currency' => 'KES',
                'payment_mode' => 'mpesa',
            ],
            'server' => [
                'REMOTE_ADDR' => '10.50.0.12',
            ],
            'env' => $this->billingEnv(),
        ]);

        $payload = $this->decodeJsonResponse($response);

        $this->assertSame(400, (int) ($response['status'] ?? 0));
        $this->assertSame('A customer phone number is required for the selected payment mode.', (string) ($payload['error'] ?? ''));
    }

    public function testDonationCheckoutCreatesMpesaOfflineChargeWithPhone(): void
    {
        $response = $this->runWebEndpoint('api/donations/checkout.php', $this->webSession(), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-donation',
                'amount' => 500,
                'currency' => 'KES',
                'payment_mode' => 'mpesa',
                'customer_phone' => '254712345678',
            ],
            'server' => [
                'REMOTE_ADDR' => '10.50.0.13',
            ],
            'env' => $this->billingEnv(),
        ]);

        $payload = $this->decodeJsonResponse($response);
        $data = (array) ($payload['data'] ?? []);
        $session = Database::queryOne(
            "SELECT provider, provider_reference, checkout_type, payment_mode, flow_type, customer_phone, display_text, authorization_url
             FROM billing_checkout_sessions
             WHERE id = ?",
            [(int) ($data['checkout_session_id'] ?? 0)]
        );

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['body'] ?? ''));
        $this->assertTrue((bool) ($payload['success'] ?? false));
        $this->assertSame('donation', (string) ($data['checkout_type'] ?? ''));
        $this->assertSame('mpesa', (string) ($session['provider'] ?? ''));
        $this->assertSame((string) ($data['reference'] ?? ''), (string) ($session['provider_reference'] ?? ''));
        $this->assertStringStartsWith('ws_FAKE_', (string) ($data['reference'] ?? ''));
        $this->assertSame('mpesa', (string) ($data['payment_mode'] ?? ''));
        $this->assertSame('offline_charge', (string) ($data['flow_type'] ?? ''));
        $this->assertSame('', (string) ($data['authorization_url'] ?? ''));
        $this->assertSame('mpesa', (string) ($session['payment_mode'] ?? ''));
        $this->assertSame('offline_charge', (string) ($session['flow_type'] ?? ''));
        $this->assertSame('254712345678', (string) ($session['customer_phone'] ?? ''));
        $this->assertNotEmpty((string) ($session['display_text'] ?? ''));
    }

    public function testDonationCheckoutRejectsLoggedInUsdMpesaBeforeProviderCheckout(): void
    {
        $response = $this->runWebEndpoint('api/donations/checkout.php', $this->webSession(), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-donation',
                'amount' => 20,
                'currency' => 'USD',
                'payment_mode' => 'mpesa',
                'customer_phone' => '254712345678',
            ],
            'server' => [
                'REMOTE_ADDR' => '10.50.0.14',
            ],
            'env' => $this->billingEnv(),
        ]);

        $payload = $this->decodeJsonResponse($response);

        $this->assertSame(422, (int) ($response['status'] ?? 0));
        $this->assertSame('M-Pesa checkout is only available in KSh / KES.', (string) ($payload['error'] ?? ''));
    }

    public function testPublicDonationCheckoutCreatesCardSessionOnDefaultWorkspace(): void
    {
        $wallets = new WorkspaceWalletService();
        $beforeWallet = $wallets->getSummary(1);

        $response = $this->runWebEndpoint('api/donations/checkout.php', $this->publicSession(), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-public-donation',
                'amount' => 350,
                'currency' => 'KES',
                'payment_mode' => 'card',
                'customer_phone' => '254712345678',
                'customer_email' => 'supporter@example.com',
                'message' => 'Public founder support',
                'return_to' => '/crm/public/donate.php',
            ],
            'server' => [
                'REMOTE_ADDR' => '10.50.1.11',
            ],
            'env' => $this->billingEnv(),
        ]);

        $payload = $this->decodeJsonResponse($response);
        $data = (array) ($payload['data'] ?? []);
        $session = Database::queryOne(
            "SELECT workspace_id, user_id, checkout_type, payment_mode, status, amount, customer_phone, metadata_json
             FROM billing_checkout_sessions
             WHERE id = ?
             LIMIT 1",
            [(int) ($data['checkout_session_id'] ?? 0)]
        ) ?? [];

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['body'] ?? ''));
        $this->assertTrue((bool) ($payload['success'] ?? false));
        $this->assertSame(1, (int) ($session['workspace_id'] ?? 0));
        $this->assertNull($session['user_id'] ?? null);
        $this->assertSame('donation', (string) ($session['checkout_type'] ?? ''));
        $this->assertSame('card', (string) ($session['payment_mode'] ?? ''));
        $this->assertNull($session['customer_phone'] ?? null);

        $metadata = json_decode((string) ($session['metadata_json'] ?? '{}'), true);
        $this->assertSame('public_donation', (string) ($metadata['selection']['source'] ?? ''));
        $this->assertSame('supporter@example.com', (string) ($metadata['selection']['customer_email'] ?? ''));
        $this->assertNull($metadata['selection']['customer_phone'] ?? null);

        $result = (new SaaSBillingService())->verifyCheckoutReference((string) ($data['reference'] ?? ''));
        $transaction = Database::queryOne(
            "SELECT transaction_type, transaction_status, wallet_ledger_id, subscription_id
             FROM billing_transactions
             WHERE checkout_session_id = ?
             LIMIT 1",
            [(int) ($data['checkout_session_id'] ?? 0)]
        ) ?? [];
        $afterWallet = $wallets->getSummary(1);

        $this->assertTrue((bool) ($result['success'] ?? false));
        $this->assertSame('donation', (string) ($transaction['transaction_type'] ?? ''));
        $this->assertSame('succeeded', (string) ($transaction['transaction_status'] ?? ''));
        $this->assertNull($transaction['wallet_ledger_id'] ?? null);
        $this->assertNull($transaction['subscription_id'] ?? null);
        $this->assertSame((int) ($beforeWallet['token_balance'] ?? 0), (int) ($afterWallet['token_balance'] ?? -1));
    }

    public function testPublicDonationCheckoutCreatesNgnBankTransferSessionOnDefaultWorkspace(): void
    {
        (new WorkspaceBillingSettings())->save([
            'payment_bank_transfer_enabled' => true,
        ]);

        $response = $this->runWebEndpoint('api/donations/checkout.php', $this->publicSession(), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-public-donation',
                'amount' => 350,
                'currency' => 'NGN',
                'payment_mode' => 'bank_transfer',
                'customer_email' => 'supporter@example.com',
                'message' => 'Public founder support',
                'return_to' => '/crm/public/donate.php',
            ],
            'server' => [
                'REMOTE_ADDR' => '10.50.1.18',
            ],
            'env' => $this->billingEnv(),
        ]);

        $payload = $this->decodeJsonResponse($response);
        $data = (array) ($payload['data'] ?? []);
        $session = Database::queryOne(
            "SELECT workspace_id, user_id, checkout_type, payment_mode, flow_type, status, currency, customer_phone, metadata_json
             FROM billing_checkout_sessions
             WHERE id = ?
             LIMIT 1",
            [(int) ($data['checkout_session_id'] ?? 0)]
        ) ?? [];

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['body'] ?? ''));
        $this->assertTrue((bool) ($payload['success'] ?? false));
        $this->assertSame(1, (int) ($session['workspace_id'] ?? 0));
        $this->assertNull($session['user_id'] ?? null);
        $this->assertSame('donation', (string) ($session['checkout_type'] ?? ''));
        $this->assertSame('bank_transfer', (string) ($session['payment_mode'] ?? ''));
        $this->assertSame('offline_charge', (string) ($session['flow_type'] ?? ''));
        $this->assertSame('NGN', (string) ($session['currency'] ?? ''));
        $this->assertNull($session['customer_phone'] ?? null);
        $this->assertSame('bank_transfer', (string) ($data['payment_mode'] ?? ''));

        $metadata = json_decode((string) ($session['metadata_json'] ?? '{}'), true);
        $this->assertSame('supporter@example.com', (string) ($metadata['selection']['customer_email'] ?? ''));
        $this->assertNull($metadata['selection']['customer_phone'] ?? null);
    }

    public function testPublicDonationCheckoutRejectsMissingCardEmail(): void
    {
        $response = $this->runWebEndpoint('api/donations/checkout.php', $this->publicSession(), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-public-donation',
                'amount' => 350,
                'currency' => 'KES',
                'payment_mode' => 'card',
            ],
            'server' => [
                'REMOTE_ADDR' => '10.50.1.12',
            ],
            'env' => $this->billingEnv(),
        ]);

        $payload = $this->decodeJsonResponse($response);

        $this->assertSame(422, (int) ($response['status'] ?? 0));
        $this->assertSame('Enter a valid email address for card checkout.', (string) ($payload['error'] ?? ''));
    }

    public function testPublicDonationCheckoutRejectsInvalidCardEmail(): void
    {
        $response = $this->runWebEndpoint('api/donations/checkout.php', $this->publicSession(), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-public-donation',
                'amount' => 350,
                'currency' => 'KES',
                'payment_mode' => 'card',
                'customer_email' => 'not-an-email',
            ],
            'server' => [
                'REMOTE_ADDR' => '10.50.1.13',
            ],
            'env' => $this->billingEnv(),
        ]);

        $payload = $this->decodeJsonResponse($response);

        $this->assertSame(422, (int) ($response['status'] ?? 0));
        $this->assertSame('Enter a valid email address for card checkout.', (string) ($payload['error'] ?? ''));
    }

    public function testPublicDonationCheckoutCreatesMpesaSessionOnDefaultWorkspace(): void
    {
        $response = $this->runWebEndpoint('api/donations/checkout.php', $this->publicSession(), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-public-donation',
                'amount' => 500,
                'currency' => 'KES',
                'payment_mode' => 'mpesa',
                'customer_phone' => '254712345678',
            ],
            'server' => [
                'REMOTE_ADDR' => '10.50.1.14',
            ],
            'env' => $this->billingEnv(),
        ]);

        $payload = $this->decodeJsonResponse($response);
        $data = (array) ($payload['data'] ?? []);
        $session = Database::queryOne(
            "SELECT workspace_id, user_id, provider, checkout_type, payment_mode, customer_phone, instructions_json, metadata_json
             FROM billing_checkout_sessions
             WHERE id = ?
             LIMIT 1",
            [(int) ($data['checkout_session_id'] ?? 0)]
        ) ?? [];

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['body'] ?? ''));
        $this->assertTrue((bool) ($payload['success'] ?? false));
        $this->assertSame(1, (int) ($session['workspace_id'] ?? 0));
        $this->assertNull($session['user_id'] ?? null);
        $this->assertSame('mpesa', (string) ($session['provider'] ?? ''));
        $this->assertSame('donation', (string) ($session['checkout_type'] ?? ''));
        $this->assertSame('mpesa', (string) ($session['payment_mode'] ?? ''));
        $this->assertSame('254712345678', (string) ($session['customer_phone'] ?? ''));
        $this->assertSame([], (array) ($data['instructions'] ?? []));
        $this->assertSame('M-Pesa prompt sent. Approve it on your phone to complete payment.', (string) ($data['display_text'] ?? ''));
        $this->assertStringContainsString('checkout_request_id', (string) ($session['instructions_json'] ?? ''));
        $this->assertStringNotContainsString('checkout_request_id', (string) ($response['body'] ?? ''));
        $this->assertStringNotContainsString('merchant_request_id', (string) ($response['body'] ?? ''));
        $this->assertStringNotContainsString('phone_number', (string) ($response['body'] ?? ''));
        $this->assertStringNotContainsString('254712345678', (string) ($response['body'] ?? ''));

        $metadata = json_decode((string) ($session['metadata_json'] ?? '{}'), true);
        $this->assertSame('public_donation', (string) ($metadata['selection']['source'] ?? ''));
        $this->assertSame('254712345678', (string) ($metadata['selection']['customer_phone'] ?? ''));
    }

    public function testPublicDonationCheckoutNormalizesRelativeMpesaCallbackUrl(): void
    {
        $env = array_merge($this->billingEnv(), [
            'MPESA_CALLBACK_URL' => '/api/webhooks/mpesa.php',
        ]);

        $response = $this->runWebEndpoint('api/donations/checkout.php', $this->publicSession(), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-public-donation',
                'amount' => 500,
                'currency' => 'KES',
                'payment_mode' => 'mpesa',
                'customer_phone' => '254712345678',
            ],
            'server' => [
                'HTTP_HOST' => 'webxpanse.com',
                'HTTPS' => 'on',
                'REMOTE_ADDR' => '10.50.1.18',
            ],
            'env' => $env,
        ]);

        $payload = $this->decodeJsonResponse($response);
        $data = (array) ($payload['data'] ?? []);
        $session = Database::queryOne(
            "SELECT callback_url
             FROM billing_checkout_sessions
             WHERE id = ?
             LIMIT 1",
            [(int) ($data['checkout_session_id'] ?? 0)]
        ) ?? [];

        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['body'] ?? ''));
        $this->assertTrue((bool) ($payload['success'] ?? false));
        $this->assertSame('https://webxpanse.com/api/webhooks/mpesa.php', (string) ($session['callback_url'] ?? ''));
    }

    public function testPublicDonationStatusReturnsSanitizedPendingMpesaState(): void
    {
        $checkout = $this->createPublicMpesaDonation();

        $response = $this->runWebEndpoint('api/donations/status.php', $this->publicSession(), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-public-donation',
                'checkout_session_id' => (int) ($checkout['checkout_session_id'] ?? 0),
                'reference' => (string) ($checkout['reference'] ?? ''),
            ],
            'env' => $this->billingEnv(),
        ]);

        $payload = $this->decodeJsonResponse($response);
        $data = (array) ($payload['data'] ?? []);
        $body = (string) ($response['body'] ?? '');

        $this->assertSame(200, (int) ($response['status'] ?? 0), $body);
        $this->assertTrue((bool) ($payload['success'] ?? false));
        $this->assertSame('pending', (string) ($data['status'] ?? ''));
        $this->assertSame('waiting', (string) ($data['state'] ?? ''));
        $this->assertFalse((bool) ($data['terminal'] ?? true));
        $this->assertSame(500.0, (float) ($data['amount'] ?? 0));
        $this->assertSame('KES', (string) ($data['currency'] ?? ''));
        $this->assertStringContainsString('Approve it on your phone', (string) ($data['message'] ?? ''));
        $this->assertStringNotContainsString('checkout_request_id', $body);
        $this->assertStringNotContainsString('merchant_request_id', $body);
        $this->assertStringNotContainsString('callback_url', $body);
        $this->assertStringNotContainsString('metadata_json', $body);
        $this->assertStringNotContainsString('phone_number', $body);
        $this->assertStringNotContainsString('254712345678', $body);
    }

    public function testPublicDonationStatusReturnsPaidSuccessState(): void
    {
        $response = $this->runWebEndpoint('api/donations/checkout.php', $this->publicSession(), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-public-donation',
                'amount' => 350,
                'currency' => 'KES',
                'payment_mode' => 'card',
                'customer_email' => 'status-supporter@example.com',
            ],
            'server' => [
                'REMOTE_ADDR' => '10.50.1.21',
            ],
            'env' => $this->billingEnv(),
        ]);
        $payload = $this->decodeJsonResponse($response);
        $checkout = (array) ($payload['data'] ?? []);

        (new SaaSBillingService())->verifyCheckoutReference((string) ($checkout['reference'] ?? ''));

        $statusResponse = $this->runWebEndpoint('api/donations/status.php', $this->publicSession(), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-public-donation',
                'checkout_session_id' => (int) ($checkout['checkout_session_id'] ?? 0),
                'reference' => (string) ($checkout['reference'] ?? ''),
            ],
            'env' => $this->billingEnv(),
        ]);
        $statusPayload = $this->decodeJsonResponse($statusResponse);
        $data = (array) ($statusPayload['data'] ?? []);

        $this->assertSame(200, (int) ($statusResponse['status'] ?? 0), (string) ($statusResponse['body'] ?? ''));
        $this->assertSame('paid', (string) ($data['status'] ?? ''));
        $this->assertSame('success', (string) ($data['state'] ?? ''));
        $this->assertTrue((bool) ($data['terminal'] ?? false));
        $this->assertSame(350.0, (float) ($data['amount'] ?? 0));
        $this->assertSame('KES', (string) ($data['currency'] ?? ''));
        $this->assertNotEmpty((string) ($data['paid_at'] ?? ''));
    }

    public function testPublicDonationStatusReturnsFailedAndExpiredStates(): void
    {
        $failedCheckout = $this->createPublicMpesaDonation('10.50.1.22');
        Database::execute(
            "UPDATE billing_checkout_sessions SET status = 'failed', updated_at = NOW() WHERE id = ?",
            [(int) ($failedCheckout['checkout_session_id'] ?? 0)]
        );

        $failedPayload = $this->decodeJsonResponse($this->runWebEndpoint('api/donations/status.php', $this->publicSession(), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-public-donation',
                'checkout_session_id' => (int) ($failedCheckout['checkout_session_id'] ?? 0),
                'reference' => (string) ($failedCheckout['reference'] ?? ''),
            ],
            'env' => $this->billingEnv(),
        ]));
        $failedData = (array) ($failedPayload['data'] ?? []);

        $expiredCheckout = $this->createPublicMpesaDonation('10.50.1.23');
        Database::execute(
            "UPDATE billing_checkout_sessions SET status = 'pending', expires_at = DATE_SUB(NOW(), INTERVAL 1 MINUTE), updated_at = NOW() WHERE id = ?",
            [(int) ($expiredCheckout['checkout_session_id'] ?? 0)]
        );

        $expiredPayload = $this->decodeJsonResponse($this->runWebEndpoint('api/donations/status.php', $this->publicSession(), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-public-donation',
                'checkout_session_id' => (int) ($expiredCheckout['checkout_session_id'] ?? 0),
                'reference' => (string) ($expiredCheckout['reference'] ?? ''),
            ],
            'env' => $this->billingEnv(),
        ]));
        $expiredData = (array) ($expiredPayload['data'] ?? []);

        $this->assertSame('failed', (string) ($failedData['state'] ?? ''));
        $this->assertTrue((bool) ($failedData['terminal'] ?? false));
        $this->assertSame('expired', (string) ($expiredData['status'] ?? ''));
        $this->assertSame('expired', (string) ($expiredData['state'] ?? ''));
        $this->assertTrue((bool) ($expiredData['terminal'] ?? false));
    }

    public function testPublicDonationStatusRejectsWrongReferenceAndNonDonationCheckout(): void
    {
        $checkout = $this->createPublicMpesaDonation('10.50.1.24');
        $wrongReferenceResponse = $this->runWebEndpoint('api/donations/status.php', $this->publicSession(), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-public-donation',
                'checkout_session_id' => (int) ($checkout['checkout_session_id'] ?? 0),
                'reference' => 'wrong_reference',
            ],
            'env' => $this->billingEnv(),
        ]);

        Database::execute(
            "INSERT INTO billing_checkout_sessions
                (workspace_id, user_id, provider, checkout_type, provider_reference, status, currency, amount, payment_mode, flow_type, metadata_json, expires_at)
             VALUES (?, NULL, 'paystack', 'subscription', 'not_a_donation_ref', 'pending', 'KES', 100, 'card', 'redirect', JSON_OBJECT(), DATE_ADD(NOW(), INTERVAL 1 HOUR))",
            [$this->workspaceId]
        );
        $nonDonationId = (int) Database::lastInsertId();
        $nonDonationResponse = $this->runWebEndpoint('api/donations/status.php', $this->publicSession(), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-public-donation',
                'checkout_session_id' => $nonDonationId,
                'reference' => 'not_a_donation_ref',
            ],
            'env' => $this->billingEnv(),
        ]);

        $this->assertSame(404, (int) ($wrongReferenceResponse['status'] ?? 0));
        $this->assertStringContainsString('Donation checkout was not found.', (string) ($wrongReferenceResponse['body'] ?? ''));
        $this->assertSame(404, (int) ($nonDonationResponse['status'] ?? 0));
        $this->assertStringContainsString('Donation checkout was not found.', (string) ($nonDonationResponse['body'] ?? ''));
    }

    public function testPublicDonationCardCallbackRedirectsToThankYouState(): void
    {
        $checkoutResponse = $this->runWebEndpoint('api/donations/checkout.php', $this->publicSession(), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-public-donation',
                'amount' => 425,
                'currency' => 'KES',
                'payment_mode' => 'card',
                'customer_email' => 'callback-supporter@example.com',
                'return_to' => '/crm/public/donate.php',
            ],
            'server' => [
                'REMOTE_ADDR' => '10.50.1.25',
            ],
            'env' => $this->billingEnv(),
        ]);
        $checkoutPayload = $this->decodeJsonResponse($checkoutResponse);
        $checkout = (array) ($checkoutPayload['data'] ?? []);

        $callbackResponse = $this->runWebEndpoint('public/billing_callback.php', $this->publicSession(), [
            'method' => 'GET',
            'query' => [
                'reference' => (string) ($checkout['reference'] ?? ''),
                'return_to' => '/crm/public/donate.php',
            ],
            'env' => $this->billingEnv(),
        ]);
        $session = Database::queryOne(
            "SELECT status
             FROM billing_checkout_sessions
             WHERE id = ?
             LIMIT 1",
            [(int) ($checkout['checkout_session_id'] ?? 0)]
        );

        $this->assertSame(302, (int) ($callbackResponse['status'] ?? 0), (string) ($callbackResponse['body'] ?? ''));
        $this->assertSame('paid', (string) ($session['status'] ?? ''));
        $this->assertSame('', trim((string) ($callbackResponse['body'] ?? '')));
    }

    public function testPublicDonationCheckoutRejectsUsdMpesaBeforeProviderCheckout(): void
    {
        $response = $this->runWebEndpoint('api/donations/checkout.php', $this->publicSession(), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-public-donation',
                'amount' => 20,
                'currency' => 'USD',
                'payment_mode' => 'mpesa',
                'customer_phone' => '254712345678',
            ],
            'server' => [
                'REMOTE_ADDR' => '10.50.1.16',
            ],
            'env' => $this->billingEnv(),
        ]);

        $payload = $this->decodeJsonResponse($response);

        $this->assertSame(422, (int) ($response['status'] ?? 0));
        $this->assertSame('M-Pesa checkout is only available in KSh / KES.', (string) ($payload['error'] ?? ''));
    }

    public function testPublicDonationCheckoutRejectsMpesaWithoutPhone(): void
    {
        $response = $this->runWebEndpoint('api/donations/checkout.php', $this->publicSession(), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-public-donation',
                'amount' => 500,
                'currency' => 'KES',
                'payment_mode' => 'mpesa',
            ],
            'server' => [
                'REMOTE_ADDR' => '10.50.1.15',
            ],
            'env' => $this->billingEnv(),
        ]);

        $payload = $this->decodeJsonResponse($response);

        $this->assertSame(422, (int) ($response['status'] ?? 0));
        $this->assertSame('Enter the M-Pesa phone number to start this donation.', (string) ($payload['error'] ?? ''));
    }

    public function testPublicDonationCheckoutRejectsUnavailableMpesaBeforeStartingCheckout(): void
    {
        $env = array_merge($this->billingEnv(), [
            'MPESA_FAKE_MODE' => 'false',
            'MPESA_ENABLED' => 'false',
        ]);

        $response = $this->runWebEndpoint('api/donations/checkout.php', $this->publicSession(), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-public-donation',
                'amount' => 500,
                'currency' => 'KES',
                'payment_mode' => 'mpesa',
                'customer_phone' => '254712345678',
            ],
            'server' => [
                'REMOTE_ADDR' => '10.50.1.17',
            ],
            'env' => $env,
        ]);

        $payload = $this->decodeJsonResponse($response);

        $this->assertSame(422, (int) ($response['status'] ?? 0));
        $this->assertSame('The selected donation payment method is not available for this currency.', (string) ($payload['error'] ?? ''));
    }

    private function webSession(): array
    {
        return [
            'user_id' => $this->userId,
            'user_uuid' => 'donation-owner',
            'user_email' => 'donation.owner@example.com',
            'user_role' => 'admin',
            'active_workspace_id' => $this->workspaceId,
            'active_workspace_uuid' => $this->workspaceUuid,
            'active_workspace_slug' => $this->workspaceSlug,
            'active_workspace_name' => $this->workspaceName,
            'active_workspace_role' => 'owner',
            'active_workspace_membership_id' => $this->membershipId,
            'csrf_token' => 'csrf-donation',
            '__remember_restore_attempted' => true,
        ];
    }

    private function publicSession(): array
    {
        return [
            'csrf_token' => 'csrf-public-donation',
            '__remember_restore_attempted' => true,
        ];
    }

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

    private function createPublicMpesaDonation(string $remoteAddr = '10.50.1.20'): array
    {
        $response = $this->runWebEndpoint('api/donations/checkout.php', $this->publicSession(), [
            'method' => 'POST',
            'post' => [
                'csrf_token' => 'csrf-public-donation',
                'amount' => 500,
                'currency' => 'KES',
                'payment_mode' => 'mpesa',
                'customer_phone' => '254712345678',
            ],
            'server' => [
                'REMOTE_ADDR' => $remoteAddr,
            ],
            'env' => $this->billingEnv(),
        ]);

        $payload = $this->decodeJsonResponse($response);
        $this->assertSame(200, (int) ($response['status'] ?? 0), (string) ($response['body'] ?? ''));
        $this->assertTrue((bool) ($payload['success'] ?? false));

        return (array) ($payload['data'] ?? []);
    }

    private function decodeJsonResponse(array $response): array
    {
        $decoded = json_decode((string) ($response['body'] ?? ''), true);
        $this->assertIsArray($decoded, 'Response body was not valid JSON. STDERR: ' . trim((string) ($response['stderr'] ?? '')));
        return $decoded;
    }
}
