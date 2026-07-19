<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\AutomationJobHealthService;
use CRM\Tests\DatabaseTestCase;

class AutomationJobHealthServiceTest extends DatabaseTestCase
{
    public function testMarksSuccessAndFailureAndComputesSummary(): void
    {
        $service = new AutomationJobHealthService();

        $service->markStarted('ai_outcome_reconciliation');
        $service->markSuccess('ai_outcome_reconciliation', 'Completed reconciliation.', 1200, ['assistant_count' => 3]);
        $service->markFailure('ai_confidence_calibration', 'Calibration failed.', 900, ['applied' => 0]);

        $jobs = $service->getJobs();
        $this->assertCount(2, $jobs);

        $reconciliation = $service->getJob('ai_outcome_reconciliation');
        $this->assertNotNull($reconciliation);
        $this->assertSame('ok', $reconciliation['status']);
        $this->assertSame('ok', $reconciliation['derived_status']);
        $this->assertSame(1200, (int) $reconciliation['last_duration_ms']);
        $this->assertSame(3, (int) ($reconciliation['metadata']['assistant_count'] ?? 0));

        $calibration = $service->getJob('ai_confidence_calibration');
        $this->assertNotNull($calibration);
        $this->assertSame('failed', $calibration['status']);
        $this->assertSame('failed', $calibration['derived_status']);

        $summary = $service->getSummary();
        $this->assertSame(1, $summary['healthy']);
        $this->assertSame(1, $summary['failed']);
        $this->assertSame(0, $summary['stale']);
        $this->assertSame(0, $summary['running']);
        $this->assertSame(2, $summary['total']);
    }
}
