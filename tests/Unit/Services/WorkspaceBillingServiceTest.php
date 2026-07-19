<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\BillingHubClientInterface;
use CRM\Services\WorkspaceBillingService;
use CRM\Tests\DatabaseTestCase;

class WorkspaceBillingServiceTest extends DatabaseTestCase
{
    private WorkspaceBillingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new WorkspaceBillingService();
        $this->service->saveSettings([
            'enabled' => true,
            'default_currency' => 'KES',
            'grace_days' => 3,
            'auto_lock_enabled' => true,
        ]);
    }

    public function testOpenFutureChargeSetsPaymentDueStatus(): void
    {
        $this->service->createOneTimeCharge([
            'title' => 'March workspace subscription',
            'amount' => 5000,
            'currency' => 'KES',
            'due_date' => date('Y-m-d', strtotime('+2 days')),
        ]);

        $summary = $this->service->getWorkspaceSummary();

        $this->assertSame('payment_due', $summary['status']);
        $this->assertSame(5000.0, (float) $summary['amount_due']);
    }

    public function testExpiredGraceLocksWorkspace(): void
    {
        $this->service->createOneTimeCharge([
            'title' => 'Late workspace subscription',
            'amount' => 7200,
            'currency' => 'KES',
            'due_date' => date('Y-m-d', strtotime('-5 days')),
        ]);

        $summary = $this->service->getWorkspaceSummary();

        $this->assertSame('locked', $summary['status']);
        $this->assertGreaterThan(0, (float) $summary['amount_due']);
    }

    public function testRecurringMaintenanceCreatesCharge(): void
    {
        $this->service->saveDefaultPlan([
            'name' => 'Workspace Subscription',
            'is_active' => true,
            'interval_unit' => 'monthly',
            'interval_count' => 1,
            'amount' => 12000,
            'currency' => 'KES',
            'grace_days' => 3,
            'next_charge_date' => date('Y-m-d'),
        ]);

        $result = $this->service->runMaintenance();
        $count = (int) (Database::queryOne("SELECT COUNT(*) AS c FROM workspace_billing_charges WHERE charge_type = 'recurring'")['c'] ?? 0);

        $this->assertGreaterThanOrEqual(1, $result['generated_charges']);
        $this->assertGreaterThanOrEqual(1, $count);
    }

    public function testLegacyTrialDatesDoNotSuspendLocking(): void
    {
        $this->service->createOneTimeCharge([
            'title' => 'Overdue with legacy trial dates',
            'amount' => 9000,
            'currency' => 'KES',
            'due_date' => date('Y-m-d', strtotime('-10 days')),
        ]);
        $this->service->saveSettings([
            'trial_starts_at' => date('Y-m-d 00:00:00'),
            'trial_ends_at' => date('Y-m-d 23:59:59', strtotime('+7 days')),
            'trial_notes' => 'Partner onboarding period',
        ]);

        $summary = $this->service->getWorkspaceSummary();

        $this->assertSame('locked', $summary['status']);
        $this->assertFalse((bool) $summary['is_trial_active']);
        $this->assertNotNull($summary['grace_expires_at']);
    }

    public function testCentralHubCheckoutUsesRemoteHostedUrl(): void
    {
        $client = new class implements BillingHubClientInterface {
            public array $lastPayload = [];

            public function createCheckout(array $settings, array $payload): array
            {
                $this->lastPayload = $payload;
                return [
                    'reference' => 'hub_checkout_123',
                    'authorization_url' => 'https://billing.example.com/checkout/hub_checkout_123',
                    'expires_at' => date('Y-m-d H:i:s', strtotime('+1 hour')),
                ];
            }

            public function fetchStatus(array $settings): array
            {
                return [
                    'workspace_key' => 'tenant-alpha',
                    'status' => 'payment_due',
                    'amount_due' => 4500,
                    'currency' => 'KES',
                    'due_at' => date('Y-m-d H:i:s', strtotime('+2 days')),
                    'grace_expires_at' => null,
                    'updated_at' => date('Y-m-d H:i:s'),
                ];
            }
        };

        $service = new WorkspaceBillingService($client);
        $service->saveSettings([
            'enabled' => true,
            'billing_mode' => 'central_hub',
            'billing_hub_base_url' => 'https://billing.example.com',
            'billing_workspace_key' => 'tenant-alpha',
            'billing_hub_signing_secret' => 'shared-secret',
        ]);

        $checkout = $service->createHostedCheckout(1, 'web');

        $this->assertSame('https://billing.example.com/checkout/hub_checkout_123', $checkout['authorization_url']);
        $this->assertSame('tenant-alpha', $client->lastPayload['workspace_key']);
        $this->assertSame('payment_due', $service->getWorkspaceSummary()['status']);
    }

    public function testCentralHubSyncRejectsInvalidAndStalePayloads(): void
    {
        $service = new WorkspaceBillingService(new class implements BillingHubClientInterface {
            public function createCheckout(array $settings, array $payload): array
            {
                return [];
            }

            public function fetchStatus(array $settings): array
            {
                return [];
            }
        });

        $service->saveSettings([
            'enabled' => true,
            'billing_mode' => 'central_hub',
            'billing_hub_base_url' => 'https://billing.example.com',
            'billing_workspace_key' => 'tenant-beta',
            'billing_hub_signing_secret' => 'sync-secret',
        ]);

        $freshPayload = json_encode([
            'workspace_key' => 'tenant-beta',
            'status' => 'grace',
            'amount_due' => 8200,
            'currency' => 'KES',
            'due_at' => date('Y-m-d H:i:s', strtotime('-1 day')),
            'grace_expires_at' => date('Y-m-d H:i:s', strtotime('+2 days')),
            'updated_at' => date('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_SLASHES);
        $freshSignature = hash_hmac('sha256', $freshPayload, 'sync-secret');
        $accepted = $service->processHubStatusSync($freshPayload, $freshSignature);

        $this->assertTrue($accepted['accepted']);
        $this->assertSame('grace', $service->getWorkspaceSummary()['status']);

        $invalid = $service->processHubStatusSync($freshPayload, 'bad-signature');
        $this->assertFalse($invalid['accepted']);

        $stalePayload = json_encode([
            'workspace_key' => 'tenant-beta',
            'status' => 'locked',
            'amount_due' => 8200,
            'currency' => 'KES',
            'updated_at' => date('Y-m-d H:i:s', strtotime('-1 day')),
        ], JSON_UNESCAPED_SLASHES);
        $staleSignature = hash_hmac('sha256', $stalePayload, 'sync-secret');
        $stale = $service->processHubStatusSync($stalePayload, $staleSignature);

        $this->assertFalse($stale['accepted']);
        $this->assertSame('grace', $service->getWorkspaceSummary()['status']);
    }

    public function testCompatibilityStateIsHiddenByDefaultForSaasMode(): void
    {
        $this->assertFalse($this->service->shouldExposeCompatibilityState());
    }

    public function testCompatibilityStateIsExposedForExplicitLegacyLocalProviderMode(): void
    {
        $this->service->saveSettings([
            'enabled' => true,
            'billing_mode' => 'local_provider',
            'default_currency' => 'KES',
            'grace_days' => 3,
            'auto_lock_enabled' => true,
        ]);

        $this->assertTrue($this->service->shouldExposeCompatibilityState());
    }
}
