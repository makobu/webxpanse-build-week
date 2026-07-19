<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\IntegrationReadinessService;
use CRM\Tests\DatabaseTestCase;

class IntegrationReadinessServiceTest extends DatabaseTestCase
{
    public function testReportIncludesExpectedIntegrationDomains(): void
    {
        $report = (new IntegrationReadinessService())->check();
        $domains = array_column((array) ($report['domains'] ?? []), 'key');

        $this->assertContains($report['status'] ?? null, ['ok', 'warning', 'critical']);
        $this->assertSame(7, (int) ($report['summary']['domains_checked'] ?? 0));
        $this->assertEqualsCanonicalizing(
            ['smtp_email', 'whatsapp', 'calendar', 'payment', 'ai_provider', 'cron_jobs', 'queues'],
            $domains
        );
        $this->assertArrayHasKey('findings', $report);
        $this->assertArrayHasKey('by_domain', (array) ($report['summary'] ?? []));
    }

    public function testBrokenActiveWhatsAppCredentialIsCriticalInProductionMode(): void
    {
        $this->withAppEnv('production', function (): void {
            Database::execute(
                "INSERT INTO workspace_whatsapp_integrations
                    (workspace_id, connection_status, phone_number_id, access_token, disconnected_at)
                 VALUES (1, 'connected', '', '', NULL)
                 ON DUPLICATE KEY UPDATE
                    connection_status = VALUES(connection_status),
                    phone_number_id = VALUES(phone_number_id),
                    access_token = VALUES(access_token),
                    disconnected_at = NULL"
            );

            $report = (new IntegrationReadinessService())->check();

            $this->assertContains('whatsapp_active_integration_missing_credential', $this->findingRules($report));
            $this->assertSame('critical', $this->findingSeverity($report, 'whatsapp_active_integration_missing_credential'));
        });
    }

    public function testQueueBacklogFindingsAreReported(): void
    {
        Database::execute(
            "INSERT INTO ai_autoresponder_queue
                (communication_id, contact_id, channel, direction, message_text, normalized_payload, dedupe_key, status, available_at)
             VALUES (?, ?, 'email', 'inbound', 'Queued message', '{}', ?, 'pending', DATE_SUB(NOW(), INTERVAL 4 HOUR))",
            [999001, 999001, hash('sha256', __METHOD__ . random_int(1, PHP_INT_MAX))]
        );

        $report = (new IntegrationReadinessService())->check();

        $this->assertContains('queue_stale_pending_items', $this->findingRules($report));
    }

    public function testMissingBillingSettingsKeepsCredentialedPaymentMethodsOptIn(): void
    {
        Database::execute('DELETE FROM workspace_billing_settings WHERE id = 1');

        $report = (new IntegrationReadinessService())->check();
        $rules = $this->findingRules($report);
        $payment = $this->domainSummary($report, 'payment');

        $this->assertNotContains('paystack_provider_missing', $rules);
        $this->assertNotContains('mpesa_provider_missing', $rules);
        $this->assertTrue((bool) ($payment['payment_bank_transfer_enabled'] ?? false));
        $this->assertFalse((bool) ($payment['payment_card_enabled'] ?? true));
        $this->assertFalse((bool) ($payment['payment_mpesa_enabled'] ?? true));
    }

    /**
     * @param array<string,mixed> $report
     * @return array<int,string>
     */
    private function findingRules(array $report): array
    {
        return array_values(array_map(
            static fn(array $finding): string => (string) ($finding['rule'] ?? ''),
            array_filter((array) ($report['findings'] ?? []), 'is_array')
        ));
    }

    /**
     * @param array<string,mixed> $report
     */
    private function findingSeverity(array $report, string $rule): string
    {
        foreach ((array) ($report['findings'] ?? []) as $finding) {
            if (is_array($finding) && ($finding['rule'] ?? '') === $rule) {
                return (string) ($finding['severity'] ?? '');
            }
        }
        return '';
    }

    /**
     * @param array<string,mixed> $report
     * @return array<string,mixed>
     */
    private function domainSummary(array $report, string $key): array
    {
        foreach ((array) ($report['domains'] ?? []) as $domain) {
            if (is_array($domain) && ($domain['key'] ?? '') === $key) {
                return (array) ($domain['summary'] ?? []);
            }
        }

        return [];
    }

    private function withAppEnv(string $value, callable $callback): void
    {
        $oldEnv = getenv('APP_ENV');
        $oldArray = $_ENV['APP_ENV'] ?? null;
        putenv('APP_ENV=' . $value);
        $_ENV['APP_ENV'] = $value;

        try {
            $callback();
        } finally {
            if ($oldEnv === false) {
                putenv('APP_ENV');
            } else {
                putenv('APP_ENV=' . $oldEnv);
            }
            if ($oldArray === null) {
                unset($_ENV['APP_ENV']);
            } else {
                $_ENV['APP_ENV'] = $oldArray;
            }
        }
    }
}
