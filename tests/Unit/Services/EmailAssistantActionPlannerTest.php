<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\EmailAssistantActionPlanner;
use CRM\Tests\TestCase;

class EmailAssistantActionPlannerTest extends TestCase
{
    public function testPlanCustomerReplyPrefersRevisionForNegotiationSignal(): void
    {
        $planner = new EmailAssistantActionPlanner();
        $plan = $planner->planCustomerReply(
            [
                'signals' => ['asks_for_discount' => true, 'asks_for_revision' => true],
                'deal' => ['id' => 4, 'stage' => 'negotiation'],
                'invoice' => ['id' => 8, 'document_type' => 'quote'],
            ],
            [
                'confidence' => 0.91,
                'primary_entities' => [
                    'deal' => ['id' => 4, 'stage' => 'negotiation'],
                    'invoice' => ['id' => 8, 'document_type' => 'quote'],
                ],
            ],
            'send',
            1
        );

        $actions = array_column($plan['actions'], 'action');
        $this->assertContains('revise_document', $actions);
        $this->assertContains('draft_customer_reply', $actions);
        $this->assertContains('send_customer_reply', $actions);
    }
}
