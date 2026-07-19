<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\WorkspaceLaunchReadinessException;
use CRM\Services\WorkspaceLaunchReadinessService;
use CRM\Services\EmailIntegrationService;
use CRM\Services\PlatformEmailDefaultService;
use CRM\Tests\DatabaseTestCase;

class WorkspaceLaunchReadinessServiceTest extends DatabaseTestCase
{
    public function testFullyMigratedSchemaPassesBillingPortalAndGovernanceChecks(): void
    {
        $service = new WorkspaceLaunchReadinessService();

        $this->saveSystemMailDefaults();

        $billing = $service->checkWorkspaceSaasReadiness('billing_portal');
        $governance = $service->checkWorkspaceSaasReadiness('workspace_governance');
        $inviteAcceptance = $service->checkWorkspaceSaasReadiness('invite_acceptance');
        $mobilePayload = $service->checkWorkspaceSaasReadiness('mobile_workspace_payload');
        $mailProvider = $service->checkMailProviderReadiness(1);

        $this->assertTrue((bool) ($billing['ready'] ?? false));
        $this->assertTrue((bool) ($governance['ready'] ?? false));
        $this->assertTrue((bool) ($inviteAcceptance['ready'] ?? false));
        $this->assertTrue((bool) ($mobilePayload['ready'] ?? false));
        $this->assertSame([], $billing['issues'] ?? []);
        $this->assertSame([], $governance['issues'] ?? []);
        $this->assertSame([], $inviteAcceptance['issues'] ?? []);
        $this->assertSame([], $mobilePayload['issues'] ?? []);
        $this->assertTrue((bool) ($mailProvider['ready'] ?? false));
    }

    public function testMissingBillingColumnProducesBoundedReadinessFailure(): void
    {
        $service = new WorkspaceLaunchReadinessService();

        $this->dropForeignKeysForColumn('billing_transactions', 'subscription_id');
        \CRM\Database::execute("ALTER TABLE billing_transactions DROP COLUMN subscription_id");

        $readiness = $service->checkWorkspaceSaasReadiness('billing_portal');

        $this->assertFalse((bool) ($readiness['ready'] ?? true));
        $this->assertNotEmpty($readiness['issues'] ?? []);
        $this->assertSame('missing_column', (string) (($readiness['issues'][0]['type'] ?? '')));
        $this->assertSame('billing_transactions', (string) (($readiness['issues'][0]['table'] ?? '')));
        $this->assertSame('subscription_id', (string) (($readiness['issues'][0]['column'] ?? '')));

        try {
            $service->assertWorkspaceSaasReadiness('billing_portal');
            $this->fail('Expected readiness assertion to throw.');
        } catch (WorkspaceLaunchReadinessException $e) {
            $this->assertSame('billing_portal', $e->surface());
            $this->assertFalse((bool) ($e->readiness()['ready'] ?? true));
        }
    }

    public function testLaunchCriticalSurfaceSummaryReportsBlockedStateWhenAnySurfaceDrifts(): void
    {
        $this->saveSystemMailDefaults();

        $service = new WorkspaceLaunchReadinessService();

        \CRM\Database::execute("ALTER TABLE workspace_invites DROP COLUMN delivery_status");

        $summary = $service->getWorkspaceLaunchStatus(55);

        $this->assertSame(55, (int) ($summary['workspace_id'] ?? 0));
        $this->assertFalse((bool) ($summary['ready'] ?? true));
        $this->assertGreaterThan(0, (int) ($summary['surface_count'] ?? 0));
        $this->assertLessThan((int) ($summary['surface_count'] ?? 0), (int) ($summary['ready_surface_count'] ?? 0) + 1);
        $this->assertSame('workspace_governance', (string) (($summary['issues'][0]['surface'] ?? '')));
        $this->assertSame('delivery_status', (string) (($summary['issues'][0]['column'] ?? '')));
    }

    public function testMailProviderReadinessBlocksWhenNoOutboundProviderExists(): void
    {
        $_ENV['SMTP_HOST'] = '';
        $_ENV['SMTP_USER'] = '';
        $_ENV['IMAP_ENABLED'] = 'false';
        $_ENV['IMAP_HOST'] = '';
        $_ENV['IMAP_USER'] = '';
        $_ENV['IMAP_PASS'] = '';

        $service = new WorkspaceLaunchReadinessService();
        $readiness = $service->checkMailProviderReadiness(1);

        $this->assertFalse((bool) ($readiness['ready'] ?? true));
        $this->assertSame('mail_provider', (string) ($readiness['surface'] ?? ''));
        $this->assertSame('outbound_provider_missing', (string) (($readiness['issues'][0]['type'] ?? '')));
    }

    private function dropForeignKeysForColumn(string $table, string $column): void
    {
        $config = $this->currentTestDatabaseConfig();
        $rows = \CRM\Database::query(
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

            \CRM\Database::execute("ALTER TABLE {$table} DROP FOREIGN KEY {$constraintName}");
        }
    }

    private function saveSystemMailDefaults(): void
    {
        (new PlatformEmailDefaultService())->save(EmailIntegrationService::SCOPE_MAIN_EMAIL, [
            'from_email' => 'system@example.test',
            'from_name' => 'System Mail',
            'smtp_host' => 'smtp.example.test',
            'smtp_username' => 'system@example.test',
            'smtp_password' => 'smtp-secret',
            'imap_enabled' => true,
            'imap_host' => 'imap.example.test',
            'imap_username' => 'system@example.test',
            'imap_password' => 'imap-secret',
        ], 1);
    }
}
