<?php

namespace CRM\Tests\Unit\Frontend;

use CRM\DatabaseConnectionException;
use CRM\WebExceptionHandler;
use PHPUnit\Framework\TestCase;

class SystemHealthPageTest extends TestCase
{
    public function testSystemHealthPageExistsAndUsesLocalDownMode(): void
    {
        $page = file_get_contents(__DIR__ . '/../../../public/system_health.php');

        $this->assertNotFalse($page);
        $this->assertStringContainsString('new SystemReadinessService', (string) $page);
        $this->assertStringContainsString('$databaseAvailable', (string) $page);
        $this->assertStringContainsString('System health diagnostics are only available locally', (string) $page);
        $this->assertStringContainsString('Production Preflight', (string) $page);
        $this->assertStringContainsString('System Context Registry', (string) $page);
        $this->assertStringContainsString('system_context_registry', (string) $page);
        $this->assertStringContainsString('$systemContextSummary', (string) $page);
        $this->assertStringContainsString('$systemContextFindings', (string) $page);
        $this->assertStringContainsString('Template Validation', (string) $page);
        $this->assertStringContainsString('template_validation', (string) $page);
        $this->assertStringContainsString('$templateValidationSummary', (string) $page);
        $this->assertStringContainsString('$templateValidationFindings', (string) $page);
        $this->assertStringContainsString('Security Hardening', (string) $page);
        $this->assertStringContainsString('security_hardening', (string) $page);
        $this->assertStringContainsString('$securityHardeningSummary', (string) $page);
        $this->assertStringContainsString('$securityHardeningFindings', (string) $page);
        $this->assertStringContainsString('Integration Readiness', (string) $page);
        $this->assertStringContainsString('integration_readiness', (string) $page);
        $this->assertStringContainsString('$integrationReadinessSummary', (string) $page);
        $this->assertStringContainsString('$integrationReadinessDomains', (string) $page);
        $this->assertStringContainsString('$integrationReadinessFindings', (string) $page);
        $this->assertStringContainsString('Demo Quarantine', (string) $page);
        $this->assertStringContainsString('demo_quarantine', (string) $page);
        $this->assertStringContainsString('$demoQuarantineSummary', (string) $page);
        $this->assertStringContainsString('$demoQuarantineFindings', (string) $page);
        $this->assertStringContainsString('Backup &amp; Restore', (string) $page);
        $this->assertStringContainsString('backup_restore_readiness', (string) $page);
        $this->assertStringContainsString('$backupRestoreSummary', (string) $page);
        $this->assertStringContainsString('$backupRestoreExpectations', (string) $page);
        $this->assertStringContainsString('$backupRestoreFindings', (string) $page);
        $this->assertStringContainsString('Super Admin Automation Review Queue', (string) $page);
        $this->assertStringContainsString('automation_review_queue.php', (string) $page);
        $this->assertStringContainsString('automation_review_action', (string) $page);
        $this->assertStringContainsString('duplicate_refresh_count', (string) $page);
        $this->assertStringContainsString('Refreshed', (string) $page);
        $this->assertStringContainsString('apply_escalation_policy', (string) $page);
        $this->assertStringContainsString('dispatch_escalation_notifications', (string) $page);
        $this->assertStringContainsString('Escalated Findings', (string) $page);
        $this->assertStringContainsString('urgent_escalation_count', (string) $page);
        $this->assertStringContainsString('escalation_notification_pending_count', (string) $page);
        $this->assertStringContainsString('Send escalation notifications', (string) $page);
        $this->assertStringContainsString('$preflightManualConfirmations', (string) $page);
        $this->assertStringContainsString("Authorization::canAny(['settings.monitoring', 'admin.users.manage', 'admin.roles.manage']", (string) $page);
    }

    public function testDatabaseSetupRendererDoesNotExposeStackTraceOrSecrets(): void
    {
        $html = WebExceptionHandler::renderDatabaseSetupRequiredPage(new DatabaseConnectionException(
            'Database connection failed: SQLSTATE[HY000] [1049] Unknown database crm_db with password phase13-secret',
            'missing_database',
            'The configured database does not exist.',
            [
                'host' => 'localhost',
                'database' => 'crm_db',
                'user' => 'root',
                'password_configured' => true,
                'sanitized_error' => 'Unknown database crm_db',
            ]
        ));

        $this->assertStringContainsString('System setup required', $html);
        $this->assertStringContainsString('Configured database', $html);
        $this->assertStringContainsString('Open system health', $html);
        $this->assertStringNotContainsString('Stack trace', $html);
        $this->assertStringNotContainsString('phase13-secret', $html);
        $this->assertStringNotContainsString('DatabaseConnectionException', $html);
    }
}
