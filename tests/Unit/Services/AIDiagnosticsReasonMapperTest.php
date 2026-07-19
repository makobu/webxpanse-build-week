<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\AIDiagnosticsReasonMapper;
use PHPUnit\Framework\TestCase;

class AIDiagnosticsReasonMapperTest extends TestCase
{
    public function testMapsAssistantContextReasonToStableBucket(): void
    {
        $mapper = new AIDiagnosticsReasonMapper();
        $mapped = $mapper->map([
            'source' => 'assistant',
            'decision' => 'blocked',
            'reason_codes' => ['missing_context'],
            'human_summary' => 'Assistant blocked due to insufficient thread context.',
        ]);

        $this->assertSame('missing_context', $mapped['reason_bucket']);
        $this->assertSame('high', $mapped['severity']);
    }

    public function testMapsWorkflowFailureToWorkflowBucket(): void
    {
        $mapper = new AIDiagnosticsReasonMapper();
        $mapped = $mapper->map([
            'source' => 'workflow',
            'decision' => 'failed',
            'reason_codes' => [],
            'human_summary' => 'Workflow node failed with last_error timeout.',
        ]);

        $this->assertSame('workflow_error', $mapped['reason_bucket']);
        $this->assertSame('high', $mapped['severity']);
    }
}
