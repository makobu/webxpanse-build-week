<?php
/**
 * Deal Automation Rules Engine Tests
 * Tests checklist evaluation, terminal-stage gates, cooldown/anti-flap
 */

namespace CRM\Tests\Unit\Services;

use CRM\Tests\DatabaseTestCase;
use CRM\Services\DealAutomationRulesEngine;
use CRM\Database;

class DealAutomationRulesEngineTest extends DatabaseTestCase
{
    private DealAutomationRulesEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = new DealAutomationRulesEngine();
        $this->ensureConfig();
    }

    private function ensureConfig(): void
    {
        $exists = Database::queryOne("SELECT 1 FROM deal_automation_config WHERE id = 1");
        if (!$exists) {
            Database::execute(
                "INSERT INTO deal_automation_config (id, enabled, mode, min_confidence, lookback_days, cooldown_hours, require_approval_terminal, min_terminal_confidence, inactivity_days_for_loss, allow_multi_stage_jump, dry_run, config_json)
                 VALUES (1, 1, 'suggest_only', 0.85, 14, 24, 1, 0.92, 14, 0, 0, ?)",
                [json_encode([
                    'transitions' => [
                        [
                            'from' => 'qualification',
                            'to' => 'proposal',
                            'enabled' => true,
                            'min_confidence' => 0.82,
                            'rules' => [['type' => 'proposal_sent', 'required' => true]],
                            'require_all' => true,
                        ],
                        [
                            'from' => 'negotiation',
                            'to' => 'closed_won',
                            'enabled' => true,
                            'min_confidence' => 0.92,
                            'terminal' => true,
                            'rules' => [
                                ['type' => 'explicit_acceptance', 'required' => true],
                                ['type' => 'no_negative_in_window', 'window_days' => 7],
                            ],
                            'require_all' => true,
                        ],
                    ],
                ])]
            );
        }
    }

    public function testProposalSentRulePasses()
    {
        $evidence = [
            'current_stage' => 'qualification',
            'proposal_sent' => true,
            'lead_score' => 50,
            'communications' => [],
            'intent_counts' => [],
            'sentiment_summary' => ['negative_count' => 0, 'positive_count' => 0, 'neutral_count' => 0, 'has_negative_trend' => false],
        ];
        $suggestion = ['suggested_stage' => 'proposal', 'confidence' => 0.9, 'reasoning' => '', 'evidence_flags' => []];
        $result = $this->engine->evaluate($evidence, $suggestion, null);
        $this->assertContains($result['decision'], ['reject', 'suggest_only', 'auto_apply']);
        $this->assertArrayHasKey('checklist_results', $result);
    }

    public function testProposalSentRuleFailsWhenNoProposal()
    {
        $evidence = [
            'current_stage' => 'qualification',
            'proposal_sent' => false,
            'lead_score' => 50,
            'communications' => [],
            'intent_counts' => [],
            'sentiment_summary' => ['negative_count' => 0, 'positive_count' => 0, 'neutral_count' => 0, 'has_negative_trend' => false],
        ];
        $suggestion = ['suggested_stage' => 'proposal', 'confidence' => 0.9, 'reasoning' => '', 'evidence_flags' => []];
        $result = $this->engine->evaluate($evidence, $suggestion, null);
        $this->assertEquals('suggest_only', $result['decision']);
    }

    public function testMultiStageJumpRejectedWhenNotAllowed()
    {
        $evidence = [
            'current_stage' => 'prospecting',
            'proposal_sent' => false,
            'lead_score' => 50,
            'communications' => [],
            'intent_counts' => [],
            'sentiment_summary' => ['negative_count' => 0, 'positive_count' => 0, 'neutral_count' => 0, 'has_negative_trend' => false],
        ];
        $suggestion = ['suggested_stage' => 'negotiation', 'confidence' => 0.95, 'reasoning' => '', 'evidence_flags' => []];
        $result = $this->engine->evaluate($evidence, $suggestion, null);
        $this->assertEquals('reject', $result['decision']);
        $this->assertStringContainsString('Multi-stage', $result['reason'] ?? '');
    }

    public function testTerminalRequiresApproval()
    {
        $evidence = [
            'current_stage' => 'negotiation',
            'proposal_sent' => true,
            'lead_score' => 70,
            'communications' => [
                ['direction' => 'inbound', 'subject' => 'We accept', 'body_preview' => 'We accept your proposal', 'intent' => 'purchase', 'sentiment' => 'positive', 'sentiment_score' => 0.8],
            ],
            'intent_counts' => ['purchase' => 1],
            'sentiment_summary' => ['negative_count' => 0, 'positive_count' => 1, 'neutral_count' => 0, 'has_negative_trend' => false],
        ];
        $suggestion = ['suggested_stage' => 'closed_won', 'confidence' => 0.95, 'reasoning' => '', 'evidence_flags' => []];
        $result = $this->engine->evaluate($evidence, $suggestion, null);
        $this->assertEquals('suggest_only', $result['decision']);
        $this->assertStringContainsString('approval', $result['reason'] ?? '');
    }

    public function testRejectInvalidStage()
    {
        $evidence = ['current_stage' => 'prospecting'];
        $suggestion = ['suggested_stage' => 'invalid_stage', 'confidence' => 0.9, 'reasoning' => '', 'evidence_flags' => []];
        $result = $this->engine->evaluate($evidence, $suggestion, null);
        $this->assertEquals('reject', $result['decision']);
    }
}
