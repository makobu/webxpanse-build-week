<?php

namespace CRM\Tests\Unit\Scripts;

use CRM\Tests\DatabaseTestCase;

class ProductionPreflightScriptTest extends DatabaseTestCase
{
    public function testComposerAliasPointsToProductionPreflightScript(): void
    {
        $composer = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/composer.json'), true);

        $this->assertIsArray($composer);
        $this->assertSame(
            'php scripts/production_preflight.php',
            $composer['scripts']['preflight:production'] ?? null
        );
        $this->assertSame(
            'php scripts/validate_templates.php',
            $composer['scripts']['templates:validate'] ?? null
        );
        $this->assertSame(
            'php scripts/security_hardening_audit.php',
            $composer['scripts']['security:audit'] ?? null
        );
        $this->assertSame(
            'php scripts/check_integration_readiness.php',
            $composer['scripts']['integrations:check'] ?? null
        );
        $this->assertSame(
            'php scripts/verify_demo_quarantine.php',
            $composer['scripts']['demo:quarantine'] ?? null
        );
        $this->assertSame(
            'php scripts/verify_production_cleanup.php',
            $composer['scripts']['cleanup:verify'] ?? null
        );
        $this->assertSame(
            'php scripts/check_backup_restore_readiness.php',
            $composer['scripts']['backup:check'] ?? null
        );
        $this->assertSame(
            'php scripts/prepare_runtime_paths.php',
            $composer['scripts']['runtime:prepare'] ?? null
        );
        $this->assertSame(
            'php scripts/run_automation_detectors.php',
            $composer['scripts']['automation:detectors'] ?? null
        );
    }

    public function testProductionPreflightJsonCommandReportsReadinessPayload(): void
    {
        $root = dirname(__DIR__, 3);
        $command = escapeshellarg(PHP_BINARY)
            . ' '
            . escapeshellarg($root . '/scripts/production_preflight.php')
            . ' --json';
        $output = [];
        $exitCode = 1;

        exec($command, $output, $exitCode);
        $payload = json_decode(implode("\n", $output), true);

        $this->assertContains($exitCode, [0, 1], implode("\n", $output));
        $this->assertIsArray($payload);
        $this->assertSame($exitCode, (int) ($payload['exit_code'] ?? -1));
        $this->assertArrayHasKey('production_preflight', $payload);
        $this->assertSame('production_preflight', $payload['production_preflight']['key'] ?? null);
        $this->assertSame([], (array) ($payload['production_preflight']['metadata']['blockers'] ?? ['missing']));
    }

    public function testTemplateValidationJsonCommandReportsValidationPayload(): void
    {
        $root = dirname(__DIR__, 3);
        $command = escapeshellarg(PHP_BINARY)
            . ' '
            . escapeshellarg($root . '/scripts/validate_templates.php')
            . ' --json';
        $output = [];
        $exitCode = 1;

        exec($command, $output, $exitCode);
        $payload = json_decode(implode("\n", $output), true);

        $this->assertSame(0, $exitCode, implode("\n", $output));
        $this->assertIsArray($payload);
        $this->assertSame(0, (int) ($payload['exit_code'] ?? -1));
        $this->assertArrayHasKey('template_validation', $payload);
        $this->assertArrayHasKey('summary', $payload['template_validation'] ?? []);
        $this->assertArrayHasKey('findings', $payload['template_validation'] ?? []);
    }

    public function testSecurityHardeningJsonCommandReportsAuditPayload(): void
    {
        $root = dirname(__DIR__, 3);
        $command = escapeshellarg(PHP_BINARY)
            . ' '
            . escapeshellarg($root . '/scripts/security_hardening_audit.php')
            . ' --json';
        $output = [];
        $exitCode = 1;

        exec($command, $output, $exitCode);
        $payload = json_decode(implode("\n", $output), true);

        $this->assertSame(0, $exitCode, implode("\n", $output));
        $this->assertIsArray($payload);
        $this->assertSame(0, (int) ($payload['exit_code'] ?? -1));
        $this->assertArrayHasKey('security_hardening', $payload);
        $this->assertArrayHasKey('summary', $payload['security_hardening'] ?? []);
        $this->assertArrayHasKey('findings', $payload['security_hardening'] ?? []);
    }

    public function testIntegrationReadinessJsonCommandReportsReadinessPayload(): void
    {
        $root = dirname(__DIR__, 3);
        $command = escapeshellarg(PHP_BINARY)
            . ' '
            . escapeshellarg($root . '/scripts/check_integration_readiness.php')
            . ' --json';
        $output = [];
        $exitCode = 1;

        exec($command, $output, $exitCode);
        $payload = json_decode(implode("\n", $output), true);

        $this->assertContains($exitCode, [0, 1]);
        $this->assertIsArray($payload);
        $this->assertSame($exitCode, (int) ($payload['exit_code'] ?? -1));
        $this->assertArrayHasKey('integration_readiness', $payload);
        $this->assertArrayHasKey('summary', $payload['integration_readiness'] ?? []);
        $this->assertArrayHasKey('domains', $payload['integration_readiness'] ?? []);
        $this->assertArrayHasKey('findings', $payload['integration_readiness'] ?? []);
    }

    public function testDemoQuarantineJsonCommandReportsVerificationPayload(): void
    {
        $root = dirname(__DIR__, 3);
        $command = escapeshellarg(PHP_BINARY)
            . ' '
            . escapeshellarg($root . '/scripts/verify_demo_quarantine.php')
            . ' --json';
        $output = [];
        $exitCode = 1;

        exec($command, $output, $exitCode);
        $payload = json_decode(implode("\n", $output), true);

        $this->assertContains($exitCode, [0, 1]);
        $this->assertIsArray($payload);
        $this->assertSame($exitCode, (int) ($payload['exit_code'] ?? -1));
        $this->assertArrayHasKey('demo_quarantine', $payload);
        $this->assertArrayHasKey('summary', $payload['demo_quarantine'] ?? []);
        $this->assertArrayHasKey('findings', $payload['demo_quarantine'] ?? []);
    }

    public function testProductionCleanupJsonCommandReportsVerificationPayload(): void
    {
        $root = dirname(__DIR__, 3);
        $command = escapeshellarg(PHP_BINARY)
            . ' '
            . escapeshellarg($root . '/scripts/verify_production_cleanup.php')
            . ' --json';
        $output = [];
        $exitCode = 1;

        exec($command, $output, $exitCode);
        $payload = json_decode(implode("\n", $output), true);

        $this->assertContains($exitCode, [0, 1]);
        $this->assertIsArray($payload);
        $this->assertSame($exitCode, (int) ($payload['exit_code'] ?? -1));
        $this->assertArrayHasKey('production_cleanup', $payload);
        $this->assertArrayHasKey('summary', $payload['production_cleanup'] ?? []);
        $this->assertArrayHasKey('sections', $payload['production_cleanup'] ?? []);
        $this->assertArrayHasKey('findings', $payload['production_cleanup'] ?? []);
    }

    public function testBackupRestoreReadinessJsonCommandReportsPayload(): void
    {
        $root = dirname(__DIR__, 3);
        $command = escapeshellarg(PHP_BINARY)
            . ' '
            . escapeshellarg($root . '/scripts/check_backup_restore_readiness.php')
            . ' --json';
        $output = [];
        $exitCode = 1;

        exec($command, $output, $exitCode);
        $payload = json_decode(implode("\n", $output), true);

        $this->assertContains($exitCode, [0, 1]);
        $this->assertIsArray($payload);
        $this->assertSame($exitCode, (int) ($payload['exit_code'] ?? -1));
        $this->assertArrayHasKey('backup_restore_readiness', $payload);
        $this->assertArrayHasKey('summary', $payload['backup_restore_readiness'] ?? []);
        $this->assertArrayHasKey('expectations', $payload['backup_restore_readiness'] ?? []);
        $this->assertArrayHasKey('findings', $payload['backup_restore_readiness'] ?? []);
    }
}
