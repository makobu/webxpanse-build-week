<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\InvoicePricingInferenceService;
use CRM\Tests\TestCase;

class InvoicePricingInferenceServiceTest extends TestCase
{
    public function testInfersBasePriceFromDescriptiveText(): void
    {
        $service = new InvoicePricingInferenceService();
        $result = $service->infer([
            'description' => 'Managed support plan',
            'pricing_context' => 'Starting at USD 120 per seat per month for standard support.',
            'unit_price' => 0,
        ]);

        $this->assertTrue($result['inferred']);
        $this->assertSame(120.0, (float) $result['unit_price']);
    }

    public function testInfersLowerBoundFromRange(): void
    {
        $service = new InvoicePricingInferenceService();
        $result = $service->infer([
            'description' => 'On-site maintenance',
            'pricing_context' => 'USD 300-450 per visit depending on scope.',
            'unit_price' => 0,
        ]);

        $this->assertTrue($result['inferred']);
        $this->assertSame(300.0, (float) $result['unit_price']);
    }
}
