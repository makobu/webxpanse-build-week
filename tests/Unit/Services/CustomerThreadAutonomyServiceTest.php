<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\CustomerThreadAutonomyService;
use CRM\Tests\DatabaseTestCase;

class CustomerThreadAutonomyServiceTest extends DatabaseTestCase
{
    public function testAssessFlagsMissingIdentityAndStaleThreads(): void
    {
        $service = new CustomerThreadAutonomyService();
        $assessment = $service->assess([
            'messages' => [
                ['direction' => 'inbound', 'created_at' => date('Y-m-d H:i:s', strtotime('-10 days')), 'body' => 'Need an update'],
            ],
        ], [], [], []);

        $this->assertFalse($assessment['has_contact_identity']);
        $this->assertTrue($assessment['stale_thread_state']);
        $this->assertContains('missing_thread_contact_identity', $service->explanation($assessment));
    }
}
