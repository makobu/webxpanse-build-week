<?php

namespace CRM\Tests\Unit\Core;

use PHPUnit\Framework\TestCase;

final class ConcurrentUseHardeningSourceTest extends TestCase
{
    public function testCoreReadOnlySurfacesReleaseTheSessionWriteLock(): void
    {
        $paths = [
            'public/dashboard.php',
            'public/contacts.php',
            'public/companies.php',
            'public/calendar.php',
            'public/invoices.php',
            'public/tasks.php',
            'public/reports.php',
            'api/search.php',
            'api/contacts.php',
            'api/inbox.php',
            'api/invoice_preview.php',
        ];

        foreach ($paths as $path) {
            $source = file_get_contents(dirname(__DIR__, 3) . '/' . $path);
            $this->assertNotFalse($source, $path);
            $this->assertStringContainsString('Session::closeWrite()', (string) $source, $path);
        }
    }

    public function testEmailAndWorkflowQueuesUseRecoverableOwnedClaims(): void
    {
        foreach (['services/EmailQueue.php', 'services/WorkflowQueueService.php'] as $path) {
            $source = file_get_contents(dirname(__DIR__, 3) . '/' . $path);
            $this->assertNotFalse($source, $path);
            $this->assertStringContainsString('claim_token', (string) $source, $path);
            $this->assertStringContainsString('lease_expires_at', (string) $source, $path);
            $this->assertStringContainsString("status = 'processing'", (string) $source, $path);
        }

        $emailQueue = (string) file_get_contents(dirname(__DIR__, 3) . '/services/EmailQueue.php');
        $this->assertStringContainsString('Database::beginTransaction()', $emailQueue);
        $this->assertStringContainsString('FOR UPDATE', $emailQueue);
    }

    public function testSharedEditModulesUseOptimisticConcurrency(): void
    {
        foreach ([
            'modules/Companies.php',
            'modules/Products.php',
            'modules/Events.php',
            'modules/Invoices.php',
            'modules/CompanyProfile.php',
            'modules/CustomFields.php',
            'modules/ScheduledReports.php',
        ] as $path) {
            $source = file_get_contents(dirname(__DIR__, 3) . '/' . $path);
            $this->assertNotFalse($source, $path);
            $this->assertStringContainsString('Concurrency::executeWorkspaceUpdate(', (string) $source, $path);
            $this->assertStringContainsString('Concurrency::expectedVersionFromData($data)', (string) $source, $path);
        }
    }
}
