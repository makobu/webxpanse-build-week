<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\CommercialAutomationActionPlanner;
use PHPUnit\Framework\TestCase;

class CommercialAutomationActionPlannerTest extends TestCase
{
    private CommercialAutomationActionPlanner $planner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->planner = new CommercialAutomationActionPlanner();
    }

    public function testProposalWithNoDocumentCreatesDraft(): void
    {
        $actions = $this->planner->plan([
            'deal' => ['stage' => 'proposal'],
        ], [
            'commercial' => [
                'default_document_by_stage' => ['proposal' => 'quote', 'negotiation' => 'quote', 'closed_won' => 'invoice'],
            ],
        ]);

        $this->assertSame('create_draft', $actions[0]['action'] ?? null);
    }

    public function testNegotiationWithRevisionSignalPlansReviseAndResend(): void
    {
        $actions = $this->planner->plan([
            'deal' => ['stage' => 'negotiation'],
            'invoice' => ['id' => 7, 'document_type' => 'quote'],
            'needs_revision' => true,
            'recipient' => 'buyer@example.com',
            'recent_send_blocked' => false,
            'trigger_type' => 'communication',
        ], [
            'commercial' => [
                'default_document_by_stage' => ['proposal' => 'quote', 'negotiation' => 'quote', 'closed_won' => 'invoice'],
            ],
        ]);

        $this->assertSame(['revise_document', 'resend_document'], array_column($actions, 'action'));
    }

    public function testWonDealPlansFinalizeAndSendInvoice(): void
    {
        $actions = $this->planner->plan([
            'deal' => ['stage' => 'closed_won'],
            'invoice' => ['id' => 8, 'document_type' => 'invoice'],
            'recipient' => 'buyer@example.com',
            'recent_send_blocked' => false,
        ], [
            'commercial' => [
                'default_document_by_stage' => ['proposal' => 'quote', 'negotiation' => 'quote', 'closed_won' => 'invoice'],
            ],
        ]);

        $this->assertSame(['finalize_invoice', 'send_document'], array_column($actions, 'action'));
    }
}
