<?php

namespace CRM\Modules;

use CRM\Database;

class WorkspaceBillingSettings
{
    private const DEFAULTS = [
        'id' => 1,
        'enabled' => false,
        'billing_mode' => 'central_hub',
        'billing_hub_base_url' => '',
        'billing_workspace_key' => '',
        'billing_hub_signing_secret' => '',
        'billing_hub_checkout_path' => '/api/billing/workspaces/{workspace_key}/checkout',
        'billing_hub_status_path' => '/api/billing/workspaces/{workspace_key}/status',
        'hub_synced_amount_due' => 0.0,
        'hub_synced_currency' => 'KES',
        'hub_synced_due_at' => null,
        'hub_synced_grace_expires_at' => null,
        'hub_last_synced_at' => null,
        'hub_last_payment_reference' => null,
        'paystack_mode' => 'test',
        'paystack_public_key' => '',
        'paystack_secret_key' => '',
        'paystack_callback_url' => '',
        'paystack_webhook_url' => '',
        'mpesa_enabled' => false,
        'mpesa_environment' => 'sandbox',
        'mpesa_consumer_key' => '',
        'mpesa_consumer_secret' => '',
        'mpesa_shortcode' => '',
        'mpesa_passkey' => '',
        'mpesa_callback_url' => '',
        'payment_card_enabled' => true,
        'payment_mpesa_enabled' => true,
        'payment_bank_transfer_enabled' => true,
        'donations_enabled' => true,
        'mobile_return_url' => '',
        'default_currency' => 'KES',
        'email_reminders_enabled' => true,
        'in_app_prompts_enabled' => true,
        'auto_lock_enabled' => true,
        'grace_days' => 3,
        'reminder_days_before_json' => [7, 3, 1],
        'workspace_status' => 'current',
        'last_status_changed_at' => null,
        'last_locked_at' => null,
        'last_paid_at' => null,
        'trial_starts_at' => null,
        'trial_ends_at' => null,
        'trial_granted_by' => null,
        'trial_notes' => null,
    ];

    public function get(): array
    {
        if (!$this->tablesAvailable()) {
            return self::DEFAULTS;
        }

        $row = Database::queryOne("SELECT * FROM workspace_billing_settings WHERE id = 1");
        if (!$row) {
            return self::DEFAULTS;
        }

        $settings = self::DEFAULTS;
        foreach ($row as $key => $value) {
            if (array_key_exists($key, $settings)) {
                $settings[$key] = $value;
            }
        }

        foreach (['enabled', 'mpesa_enabled', 'payment_card_enabled', 'payment_mpesa_enabled', 'payment_bank_transfer_enabled', 'donations_enabled', 'email_reminders_enabled', 'in_app_prompts_enabled', 'auto_lock_enabled'] as $boolKey) {
            $settings[$boolKey] = !empty($settings[$boolKey]);
        }

        $settings['billing_mode'] = in_array((string) ($settings['billing_mode'] ?? 'central_hub'), ['central_hub', 'local_provider'], true)
            ? (string) $settings['billing_mode']
            : 'central_hub';
        $settings['grace_days'] = max(0, (int) ($settings['grace_days'] ?? 0));
        $settings['hub_synced_amount_due'] = round((float) ($settings['hub_synced_amount_due'] ?? 0), 2);
        $settings['reminder_days_before_json'] = $this->decodeJsonArray(
            $settings['reminder_days_before_json'] ?? null,
            self::DEFAULTS['reminder_days_before_json']
        );
        $settings['paystack_callback_url'] = self::generatedPaystackCallbackUrl();
        $settings['paystack_webhook_url'] = self::generatedPaystackWebhookUrl();
        $settings['mpesa_callback_url'] = self::generatedMpesaCallbackUrl();

        return $settings;
    }

    public function save(array $data, ?int $updatedBy = null): void
    {
        if (!$this->tablesAvailable()) {
            return;
        }

        $current = $this->get();
        $merged = array_merge($current, $data);

        $paystackWasReady = trim((string) ($current['paystack_secret_key'] ?? '')) !== '';
        $paystackIsReady = trim((string) ($merged['paystack_secret_key'] ?? '')) !== '';
        if (!$paystackWasReady && $paystackIsReady) {
            if (!array_key_exists('payment_card_enabled', $data)) {
                $merged['payment_card_enabled'] = true;
            }
            if (!array_key_exists('payment_bank_transfer_enabled', $data)) {
                $merged['payment_bank_transfer_enabled'] = true;
            }
        }

        $mpesaWasReady = $this->isMpesaSetupComplete($current);
        $mpesaIsReady = $this->isMpesaSetupComplete($merged);
        if (!$mpesaWasReady && $mpesaIsReady && !array_key_exists('payment_mpesa_enabled', $data)) {
            $merged['payment_mpesa_enabled'] = true;
        }

        foreach (['enabled', 'mpesa_enabled', 'payment_card_enabled', 'payment_mpesa_enabled', 'payment_bank_transfer_enabled', 'donations_enabled', 'email_reminders_enabled', 'in_app_prompts_enabled', 'auto_lock_enabled'] as $boolKey) {
            $merged[$boolKey] = !empty($merged[$boolKey]);
        }

        $merged['billing_mode'] = in_array((string) ($merged['billing_mode'] ?? 'central_hub'), ['central_hub', 'local_provider'], true)
            ? (string) $merged['billing_mode']
            : 'central_hub';
        $merged['paystack_mode'] = in_array((string) ($merged['paystack_mode'] ?? 'test'), ['test', 'live'], true)
            ? (string) $merged['paystack_mode']
            : 'test';
        $merged['mpesa_environment'] = in_array((string) ($merged['mpesa_environment'] ?? 'sandbox'), ['sandbox', 'live'], true)
            ? (string) $merged['mpesa_environment']
            : 'sandbox';
        $merged['grace_days'] = max(0, (int) ($merged['grace_days'] ?? 0));
        $merged['hub_synced_amount_due'] = round(max(0, (float) ($merged['hub_synced_amount_due'] ?? 0)), 2);
        $merged['workspace_status'] = in_array((string) ($merged['workspace_status'] ?? 'current'), ['current', 'payment_due', 'grace', 'locked'], true)
            ? (string) $merged['workspace_status']
            : 'current';
        $merged['paystack_callback_url'] = self::generatedPaystackCallbackUrl();
        $merged['paystack_webhook_url'] = self::generatedPaystackWebhookUrl();
        $merged['mpesa_callback_url'] = self::generatedMpesaCallbackUrl();
        $merged['reminder_days_before_json'] = array_values(array_unique(array_filter(array_map(
            static fn ($value): int => max(0, (int) $value),
            is_array($merged['reminder_days_before_json'] ?? null) ? $merged['reminder_days_before_json'] : self::DEFAULTS['reminder_days_before_json']
        ), static fn ($value): bool => $value >= 0)));

        $params = [
            $merged['enabled'] ? 1 : 0,
            $merged['billing_mode'],
            (string) ($merged['billing_hub_base_url'] ?? ''),
            (string) ($merged['billing_workspace_key'] ?? ''),
            (string) ($merged['billing_hub_signing_secret'] ?? ''),
            (string) ($merged['billing_hub_checkout_path'] ?? self::DEFAULTS['billing_hub_checkout_path']),
            (string) ($merged['billing_hub_status_path'] ?? self::DEFAULTS['billing_hub_status_path']),
            $merged['hub_synced_amount_due'],
            (string) ($merged['hub_synced_currency'] ?? 'KES'),
            $merged['hub_synced_due_at'],
            $merged['hub_synced_grace_expires_at'],
            $merged['hub_last_synced_at'],
            (string) ($merged['hub_last_payment_reference'] ?? ''),
            $merged['paystack_mode'],
            (string) ($merged['paystack_public_key'] ?? ''),
            (string) ($merged['paystack_secret_key'] ?? ''),
            (string) ($merged['paystack_callback_url'] ?? ''),
            (string) ($merged['paystack_webhook_url'] ?? ''),
            $merged['mpesa_enabled'] ? 1 : 0,
            (string) ($merged['mpesa_environment'] ?? 'sandbox'),
            (string) ($merged['mpesa_consumer_key'] ?? ''),
            (string) ($merged['mpesa_consumer_secret'] ?? ''),
            (string) ($merged['mpesa_shortcode'] ?? ''),
            (string) ($merged['mpesa_passkey'] ?? ''),
            (string) ($merged['mpesa_callback_url'] ?? ''),
            $merged['payment_card_enabled'] ? 1 : 0,
            $merged['payment_mpesa_enabled'] ? 1 : 0,
            $merged['payment_bank_transfer_enabled'] ? 1 : 0,
            $merged['donations_enabled'] ? 1 : 0,
            (string) ($merged['mobile_return_url'] ?? ''),
            (string) ($merged['default_currency'] ?? 'KES'),
            $merged['email_reminders_enabled'] ? 1 : 0,
            $merged['in_app_prompts_enabled'] ? 1 : 0,
            $merged['auto_lock_enabled'] ? 1 : 0,
            $merged['grace_days'],
            json_encode($merged['reminder_days_before_json']),
            $merged['workspace_status'],
            $merged['last_status_changed_at'],
            $merged['last_locked_at'],
            $merged['last_paid_at'],
            $merged['trial_starts_at'],
            $merged['trial_ends_at'],
            $merged['trial_granted_by'],
            (string) ($merged['trial_notes'] ?? ''),
            $updatedBy,
        ];

        $exists = Database::queryOne("SELECT id FROM workspace_billing_settings WHERE id = 1");
        if ($exists) {
            $params[] = 1;
            Database::execute(
                "UPDATE workspace_billing_settings SET
                    enabled = ?, billing_mode = ?, billing_hub_base_url = ?, billing_workspace_key = ?, billing_hub_signing_secret = ?,
                    billing_hub_checkout_path = ?, billing_hub_status_path = ?, hub_synced_amount_due = ?, hub_synced_currency = ?,
                    hub_synced_due_at = ?, hub_synced_grace_expires_at = ?, hub_last_synced_at = ?, hub_last_payment_reference = ?,
                    paystack_mode = ?, paystack_public_key = ?, paystack_secret_key = ?,
                    paystack_callback_url = ?, paystack_webhook_url = ?,
                    mpesa_enabled = ?, mpesa_environment = ?, mpesa_consumer_key = ?, mpesa_consumer_secret = ?,
                    mpesa_shortcode = ?, mpesa_passkey = ?, mpesa_callback_url = ?,
                    payment_card_enabled = ?, payment_mpesa_enabled = ?, payment_bank_transfer_enabled = ?,
                    donations_enabled = ?,
                    mobile_return_url = ?,
                    default_currency = ?, email_reminders_enabled = ?, in_app_prompts_enabled = ?,
                    auto_lock_enabled = ?, grace_days = ?, reminder_days_before_json = ?, workspace_status = ?,
                    last_status_changed_at = ?, last_locked_at = ?, last_paid_at = ?, trial_starts_at = ?,
                    trial_ends_at = ?, trial_granted_by = ?, trial_notes = ?, updated_by = ?
                 WHERE id = ?",
                $params
            );
            return;
        }

        array_unshift($params, 1);
        $placeholders = implode(', ', array_fill(0, count($params), '?'));
        Database::execute(
            "INSERT INTO workspace_billing_settings (
                id, enabled, billing_mode, billing_hub_base_url, billing_workspace_key, billing_hub_signing_secret,
                billing_hub_checkout_path, billing_hub_status_path, hub_synced_amount_due, hub_synced_currency,
                hub_synced_due_at, hub_synced_grace_expires_at, hub_last_synced_at, hub_last_payment_reference,
                paystack_mode, paystack_public_key, paystack_secret_key,
                paystack_callback_url, paystack_webhook_url,
                mpesa_enabled, mpesa_environment, mpesa_consumer_key, mpesa_consumer_secret,
                mpesa_shortcode, mpesa_passkey, mpesa_callback_url,
                payment_card_enabled, payment_mpesa_enabled, payment_bank_transfer_enabled,
                donations_enabled,
                mobile_return_url, default_currency,
                email_reminders_enabled, in_app_prompts_enabled, auto_lock_enabled, grace_days,
                reminder_days_before_json, workspace_status, last_status_changed_at, last_locked_at,
                last_paid_at, trial_starts_at, trial_ends_at, trial_granted_by, trial_notes, updated_by
            ) VALUES ({$placeholders})",
            $params
        );
    }

    public static function generatedPaystackCallbackUrl(): string
    {
        return self::absoluteCrmUrl(function_exists('publicUrl') ? publicUrl('billing_callback.php') : '/public/billing_callback.php');
    }

    /** @param array<string,mixed> $settings */
    private function isMpesaSetupComplete(array $settings): bool
    {
        return !empty($settings['mpesa_enabled'])
            && trim((string) ($settings['mpesa_consumer_key'] ?? '')) !== ''
            && trim((string) ($settings['mpesa_consumer_secret'] ?? '')) !== ''
            && trim((string) ($settings['mpesa_shortcode'] ?? '')) !== ''
            && trim((string) ($settings['mpesa_passkey'] ?? '')) !== '';
    }

    public static function donationsEnabled(): bool
    {
        try {
            return !empty((new self())->get()['donations_enabled']);
        } catch (\Throwable $e) {
            return true;
        }
    }

    public static function generatedPaystackWebhookUrl(): string
    {
        return self::absoluteCrmUrl(function_exists('apiUrl') ? apiUrl('webhooks/paystack.php') : '/api/webhooks/paystack.php');
    }

    public static function generatedMpesaCallbackUrl(): string
    {
        return self::absoluteCrmUrl(function_exists('apiUrl') ? apiUrl('webhooks/mpesa.php') : '/api/webhooks/mpesa.php');
    }

    public static function absoluteCrmUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '' || preg_match('/^https?:\/\//i', $url) === 1) {
            return $url;
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $url) === 1 || strpos($url, '\\') !== false || strpos($url, '//') === 0) {
            return $url;
        }

        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $url) === 1) {
            return $url;
        }

        return rtrim(self::crmOrigin(), '/') . '/' . ltrim($url, '/');
    }

    private static function crmOrigin(): string
    {
        $appUrl = trim((string) ($_ENV['APP_URL'] ?? getenv('APP_URL') ?: ''));
        if ($appUrl !== '' && preg_match('/^https?:\/\//i', $appUrl) === 1) {
            $parts = parse_url($appUrl);
            $scheme = strtolower((string) ($parts['scheme'] ?? ''));
            $host = (string) ($parts['host'] ?? '');
            if ($scheme !== '' && $host !== '') {
                $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
                return $scheme . '://' . $host . $port;
            }
        }

        $scheme = 'http';
        $forwardedProto = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
        if (in_array($forwardedProto, ['http', 'https'], true)) {
            $scheme = $forwardedProto;
        } elseif (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            $scheme = 'https';
        } elseif (!empty($_SERVER['REQUEST_SCHEME']) && in_array(strtolower((string) $_SERVER['REQUEST_SCHEME']), ['http', 'https'], true)) {
            $scheme = strtolower((string) $_SERVER['REQUEST_SCHEME']);
        }

        $host = trim((string) ($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost'));
        return $scheme . '://' . ($host !== '' ? $host : 'localhost');
    }

    private function decodeJsonArray(mixed $value, array $fallback): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return $fallback;
    }

    private function tablesAvailable(): bool
    {
        try {
            $row = Database::queryOne(
                "SELECT COUNT(*) AS c
                 FROM information_schema.tables
                 WHERE table_schema = DATABASE()
                   AND table_name = 'workspace_billing_settings'"
            );
            return ((int) ($row['c'] ?? 0)) > 0;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
