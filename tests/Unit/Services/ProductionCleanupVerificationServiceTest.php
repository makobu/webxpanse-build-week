<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\ProductionCleanupVerificationService;
use CRM\Tests\DatabaseTestCase;

class ProductionCleanupVerificationServiceTest extends DatabaseTestCase
{
    public function testReportIncludesCleanupSummarySections(): void
    {
        $report = (new ProductionCleanupVerificationService(dirname(__DIR__, 3), null, null, []))->verify();
        $summary = (array) ($report['summary'] ?? []);
        $sections = (array) ($report['sections'] ?? []);

        $this->assertContains($report['status'] ?? null, ['ok', 'warning', 'critical']);
        $this->assertArrayHasKey('default_workspace_demo_rows', $summary);
        $this->assertArrayHasKey('protected_demo_workspace_rows', $summary);
        $this->assertArrayHasKey('public_setup_artifacts', $summary);
        $this->assertArrayHasKey('staged_generated_artifacts', $summary);
        $this->assertArrayHasKey('demo_quarantine', $sections);
        $this->assertArrayHasKey('public_setup_artifacts', $sections);
        $this->assertArrayHasKey('staged_generated_artifacts', $sections);
    }

    public function testStagedGeneratedArtifactsAreCritical(): void
    {
        $report = (new ProductionCleanupVerificationService(dirname(__DIR__, 3), null, null, [
            'screenshots/dashboard-smoke.png',
            'logs/debug.log',
            '.env',
            'services/ProductionCleanupVerificationService.php',
        ]))->verify();

        $this->assertSame('critical', $report['status'] ?? null);
        $this->assertContains('staged_generated_artifacts_present', $this->findingRules($report));
        $this->assertSame(3, (int) ($report['summary']['staged_generated_artifacts'] ?? 0));
    }

    public function testPublicInstallerIsCritical(): void
    {
        $root = $this->temporaryCleanupRoot();
        try {
            file_put_contents($root . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'install_once.php', '<?php // test installer');

            $report = (new ProductionCleanupVerificationService($root, null, null, []))->verify();

            $this->assertSame('critical', $report['status'] ?? null);
            $this->assertContains('public_install_once_present', $this->findingRules($report));
        } finally {
            $this->removeDirectory($root);
        }
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

    private function temporaryCleanupRoot(): string
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'crm-cleanup-' . bin2hex(random_bytes(6));
        mkdir($root . DIRECTORY_SEPARATOR . 'public', 0777, true);
        mkdir($root . DIRECTORY_SEPARATOR . 'api', 0777, true);
        return $root;
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($path);
    }
}
