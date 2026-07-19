<?php

namespace CRM\Tests\Unit\Services;

use CRM\Modules\DealAutomationConfig;
use CRM\Services\DealAutomationModeService;
use CRM\Tests\DatabaseTestCase;

class DealAutomationModeServiceTest extends DatabaseTestCase
{
    public function testRejectsFullAutoWhenReadinessHasBlockers(): void
    {
        $service = new DealAutomationModeService();

        $result = $service->updateMode('full_auto');

        $this->assertFalse($result['success']);
        $this->assertSame(422, $result['status']);
        $this->assertNotEmpty($result['state']['blocking_reasons'] ?? []);
        $this->assertNotSame('full_auto', (string) ((new DealAutomationConfig())->get()['mode'] ?? ''));
    }

    public function testAllowsAutoSafeThroughSharedModeWriter(): void
    {
        $service = new DealAutomationModeService();

        $result = $service->updateMode('auto_safe');
        $config = (new DealAutomationConfig())->get();

        $this->assertTrue($result['success']);
        $this->assertSame(200, $result['status']);
        $this->assertTrue((bool) ($config['enabled'] ?? false));
        $this->assertSame('auto_safe', (string) ($config['mode'] ?? ''));
    }
}
