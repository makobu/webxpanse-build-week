<?php

namespace CRM\Tests\Unit\Modules;

use CRM\Database;
use CRM\Modules\HRAnalyticsSettings;
use CRM\Tests\DatabaseTestCase;

class HRAnalyticsSettingsTest extends DatabaseTestCase
{
    public function testDefaultsLoadFromMigrationSeed(): void
    {
        $module = new HRAnalyticsSettings();
        $settings = $module->get();

        $this->assertTrue($settings['ai_enabled']);
        $this->assertSame(75, $settings['thresholds']['high_performer']);
        $this->assertSame('Marketing', $settings['department_mappings']['marketing']);
    }

    public function testSaveNormalizesThresholdsAndMappings(): void
    {
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, 'admin', NOW())",
            [uniqid('hr-settings-', true), 'hr-settings@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $userId = (int) Database::lastInsertId();

        $module = new HRAnalyticsSettings();
        $module->save([
            'ai_enabled' => false,
            'thresholds' => [
                'high_performer' => 120,
                'at_risk' => -10,
                'needs_coaching' => 999,
                'overloaded_task_count' => 0,
                'inactive_days' => 200,
            ],
            'department_mappings' => [
                'marketing' => 'Growth',
                'sales' => 'Revenue',
            ],
        ], $userId);

        $settings = $module->get();
        $this->assertFalse($settings['ai_enabled']);
        $this->assertSame(100, $settings['thresholds']['high_performer']);
        $this->assertSame(0, $settings['thresholds']['at_risk']);
        $this->assertSame(90, $settings['thresholds']['needs_coaching']);
        $this->assertSame(1, $settings['thresholds']['overloaded_task_count']);
        $this->assertSame(60, $settings['thresholds']['inactive_days']);
        $this->assertSame('Growth', $settings['department_mappings']['marketing']);
        $this->assertSame('Revenue', $settings['department_mappings']['sales']);
    }

    public function testSaveRejectsInvalidThresholdOrdering(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at risk < needs coaching < high performer');

        (new HRAnalyticsSettings())->save([
            'thresholds' => [
                'at_risk' => 60,
                'needs_coaching' => 50,
                'high_performer' => 80,
            ],
        ]);
    }
}
