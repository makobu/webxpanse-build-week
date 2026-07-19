<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\AITextResponseNormalizerService;
use PHPUnit\Framework\TestCase;

class AITextResponseNormalizerServiceTest extends TestCase
{
    public function testNormalizesSupportedAssistantResponseEnvelopes(): void
    {
        $service = new AITextResponseNormalizerService();

        $this->assertSame('Plain response', $service->normalize(' Plain response '));
        $this->assertSame('Wrapped answer', $service->normalize('{"answer":"Wrapped answer"}'));
        $this->assertSame('Nested answer', $service->normalize('{"message":{"content":[{"type":"output_text","text":"Nested answer"}]}}'));
        $this->assertSame('First block' . "\n" . 'Second block', $service->normalize('[{"type":"text","text":"First block"},{"type":"output_text","text":{"value":"Second block"}}]'));
    }

    public function testRejectsMetadataOnlyTextEnvelopes(): void
    {
        $service = new AITextResponseNormalizerService();

        $this->assertSame('', $service->normalize('{"type":"text"}'));
        $this->assertSame('', $service->normalize('{"type":"output_text","text":""}'));
        $this->assertSame('', $service->normalize('{"message":{"type":"text"}}'));
    }

    public function testPreservesMalformedAndArbitraryJsonText(): void
    {
        $service = new AITextResponseNormalizerService();

        $this->assertSame('{not valid json}', $service->normalize('{not valid json}'));
        $this->assertSame('{"metric":3,"status":"ready"}', $service->normalize('{"metric":3,"status":"ready"}'));
    }
}
