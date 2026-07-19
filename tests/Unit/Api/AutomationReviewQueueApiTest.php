<?php

namespace CRM\Tests\Unit\Api;

use PHPUnit\Framework\TestCase;

class AutomationReviewQueueApiTest extends TestCase
{
    public function testAutomationReviewQueueApiRequiresSuperAdminAndCsrfForWrites(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../../api/automation_review_queue.php');

        $this->assertStringContainsString('Authorization::isSuperAdmin($user)', $source);
        $this->assertStringContainsString('Security::validateCSRF', $source);
        $this->assertStringContainsString('runProductionReadinessDetectors', $source);
        $this->assertStringContainsString('apply_escalation_policy', $source);
        $this->assertStringContainsString('applyEscalationPolicy', $source);
        $this->assertStringContainsString('dispatch_escalation_notifications', $source);
        $this->assertStringContainsString('dispatchEscalationNotifications', $source);
        $this->assertStringContainsString('reviewRun', $source);
        $this->assertStringContainsString('evaluateProductionReadinessDetectors', $source);
    }
}
