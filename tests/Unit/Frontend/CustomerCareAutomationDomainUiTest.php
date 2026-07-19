<?php

namespace CRM\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

class CustomerCareAutomationDomainUiTest extends TestCase
{
    public function testCustomerCareDomainIsAvailableInOperatorAutomationSurfaces(): void
    {
        $learningReview = (string) file_get_contents(__DIR__ . '/../../../public/ai_learning_review.php');
        $recoveryWorkbench = (string) file_get_contents(__DIR__ . '/../../../public/ai_recovery_workbench.php');
        $learningService = (string) file_get_contents(__DIR__ . '/../../../services/AILearningReviewService.php');

        $this->assertStringContainsString("'customer_care'", $learningReview);
        $this->assertStringContainsString("'customer_care'", $recoveryWorkbench);
        $this->assertStringContainsString("'customer_care'", $learningService);
    }
}
