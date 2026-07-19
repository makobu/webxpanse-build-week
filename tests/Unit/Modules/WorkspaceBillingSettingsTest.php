<?php

namespace CRM\Tests\Unit\Modules;

use CRM\Database;
use CRM\Modules\WorkspaceBillingSettings;
use CRM\Tests\DatabaseTestCase;

class WorkspaceBillingSettingsTest extends DatabaseTestCase
{
    public function testFirstCompletedProviderSetupEnablesAllEligibleModesWithoutUndoingLaterAdminSwitches(): void
    {
        Database::execute('DELETE FROM workspace_billing_settings WHERE id = 1');
        $settings = new WorkspaceBillingSettings();

        $settings->save([
            'paystack_secret_key' => 'sk_test_first_setup',
            'mpesa_enabled' => true,
            'mpesa_consumer_key' => 'consumer',
            'mpesa_consumer_secret' => 'secret',
            'mpesa_shortcode' => '174379',
            'mpesa_passkey' => 'passkey',
        ], 1);

        $firstSetup = $settings->get();
        $this->assertTrue((bool) $firstSetup['payment_card_enabled']);
        $this->assertTrue((bool) $firstSetup['payment_mpesa_enabled']);
        $this->assertTrue((bool) $firstSetup['payment_bank_transfer_enabled']);

        $settings->save([
            'payment_card_enabled' => false,
            'payment_mpesa_enabled' => false,
            'payment_bank_transfer_enabled' => false,
        ], 1);
        $settings->save(['default_currency' => 'KES'], 1);

        $afterAdminSwitches = $settings->get();
        $this->assertFalse((bool) $afterAdminSwitches['payment_card_enabled']);
        $this->assertFalse((bool) $afterAdminSwitches['payment_mpesa_enabled']);
        $this->assertFalse((bool) $afterAdminSwitches['payment_bank_transfer_enabled']);
    }

    public function testSaveRecreatesMissingSettingsRow(): void
    {
        $originalAppUrlEnv = $_ENV['APP_URL'] ?? null;
        $originalAppUrlValue = getenv('APP_URL');
        $_ENV['APP_URL'] = 'https://crm.example';
        putenv('APP_URL=https://crm.example');
        Database::execute('DELETE FROM workspace_billing_settings WHERE id = 1');

        try {
            (new WorkspaceBillingSettings())->save([
                'enabled' => true,
                'billing_mode' => 'local_provider',
                'paystack_mode' => 'test',
                'paystack_public_key' => 'pk_test_regression',
                'paystack_secret_key' => 'sk_test_regression',
                'paystack_callback_url' => 'https://stale.example/return',
                'paystack_webhook_url' => 'https://stale.example/webhook',
                'mpesa_enabled' => true,
                'mpesa_environment' => 'live',
                'mpesa_consumer_key' => 'mpesa-consumer-key',
                'mpesa_consumer_secret' => 'mpesa-consumer-secret',
                'mpesa_shortcode' => '123456',
                'mpesa_passkey' => 'mpesa-passkey',
                'mpesa_callback_url' => 'https://stale.example/mpesa',
                'payment_card_enabled' => false,
                'payment_mpesa_enabled' => true,
                'payment_bank_transfer_enabled' => false,
                'donations_enabled' => false,
                'mobile_return_url' => 'clarity://billing-return',
                'default_currency' => 'KES',
                'email_reminders_enabled' => false,
                'in_app_prompts_enabled' => true,
                'auto_lock_enabled' => true,
                'grace_days' => 5,
                'reminder_days_before_json' => [5, 1],
                'workspace_status' => 'current',
                'trial_notes' => 'Regression insert path',
            ], 1);

            $row = Database::queryOne('SELECT * FROM workspace_billing_settings WHERE id = 1');

            $this->assertNotEmpty($row);
            $this->assertSame(1, (int) ($row['enabled'] ?? 0));
            $this->assertSame('local_provider', (string) ($row['billing_mode'] ?? ''));
            $this->assertSame('pk_test_regression', (string) ($row['paystack_public_key'] ?? ''));
            $this->assertSame('sk_test_regression', (string) ($row['paystack_secret_key'] ?? ''));
            $this->assertSame('https://crm.example/billing_callback.php', (string) ($row['paystack_callback_url'] ?? ''));
            $this->assertSame('https://crm.example/api/webhooks/paystack.php', (string) ($row['paystack_webhook_url'] ?? ''));
            $this->assertSame(1, (int) ($row['mpesa_enabled'] ?? 0));
            $this->assertSame('live', (string) ($row['mpesa_environment'] ?? ''));
            $this->assertSame('mpesa-consumer-key', (string) ($row['mpesa_consumer_key'] ?? ''));
            $this->assertSame('mpesa-consumer-secret', (string) ($row['mpesa_consumer_secret'] ?? ''));
            $this->assertSame('123456', (string) ($row['mpesa_shortcode'] ?? ''));
            $this->assertSame('mpesa-passkey', (string) ($row['mpesa_passkey'] ?? ''));
            $this->assertSame('https://crm.example/api/webhooks/mpesa.php', (string) ($row['mpesa_callback_url'] ?? ''));
            $this->assertSame(0, (int) ($row['payment_card_enabled'] ?? 1));
            $this->assertSame(1, (int) ($row['payment_mpesa_enabled'] ?? 0));
            $this->assertSame(0, (int) ($row['payment_bank_transfer_enabled'] ?? 1));
            $this->assertSame(0, (int) ($row['donations_enabled'] ?? 1));
            $this->assertSame('clarity://billing-return', (string) ($row['mobile_return_url'] ?? ''));
            $this->assertSame('KES', (string) ($row['default_currency'] ?? ''));
            $this->assertSame(0, (int) ($row['email_reminders_enabled'] ?? 1));
            $this->assertSame(5, (int) ($row['grace_days'] ?? 0));
            $this->assertSame('[5,1]', (string) ($row['reminder_days_before_json'] ?? ''));
            $this->assertSame('Regression insert path', (string) ($row['trial_notes'] ?? ''));
            $this->assertSame(1, (int) ($row['updated_by'] ?? 0));

            $settings = (new WorkspaceBillingSettings())->get();
            $this->assertFalse((bool) ($settings['donations_enabled'] ?? true));
        } finally {
            if ($originalAppUrlEnv === null) {
                unset($_ENV['APP_URL']);
            } else {
                $_ENV['APP_URL'] = $originalAppUrlEnv;
            }
            if ($originalAppUrlValue === false) {
                putenv('APP_URL');
            } else {
                putenv('APP_URL=' . $originalAppUrlValue);
            }
        }
    }
}
