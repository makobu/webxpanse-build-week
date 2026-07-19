<?php

namespace CRM\Tests\Unit\Modules;

use CRM\Database;
use CRM\Modules\AIAutoResponderConfig;
use CRM\Services\WorkspaceAIAutoResponderQuietHoursService;
use CRM\Tests\DatabaseTestCase;

class AIAutoResponderConfigTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Database::execute('DELETE FROM workspace_ai_autoresponder_quiet_hours');
        Database::execute('DELETE FROM ai_autoresponder_config');
    }

    public function testSaveAndGetRoundTripsChannelDraftBehaviorSettings(): void
    {
        $module = new AIAutoResponderConfig();
        $module->save([
            'enabled' => true,
            'mode' => 'hybrid',
            'channels' => [
                'email' => [
                    'enabled' => true,
                    'confidence_threshold' => 0.88,
                    'max_chars' => 4200,
                    'draft_length_band' => 'detailed',
                    'draft_fullness' => 'fuller',
                    'draft_include_clear_cta' => false,
                ],
                'whatsapp' => [
                    'enabled' => true,
                    'confidence_threshold' => 0.9,
                    'max_chars' => 700,
                    'draft_length_band' => 'short',
                    'draft_fullness' => 'concise',
                    'draft_include_clear_cta' => true,
                ],
            ],
        ]);

        $config = $module->get();

        $this->assertSame('detailed', $config['channels']['email']['draft_length_band'] ?? null);
        $this->assertSame('fuller', $config['channels']['email']['draft_fullness'] ?? null);
        $this->assertFalse((bool) ($config['channels']['email']['draft_include_clear_cta'] ?? true));
        $this->assertSame('short', $config['channels']['whatsapp']['draft_length_band'] ?? null);
        $this->assertSame('concise', $config['channels']['whatsapp']['draft_fullness'] ?? null);
        $this->assertTrue((bool) ($config['channels']['whatsapp']['draft_include_clear_cta'] ?? false));
    }

    public function testWorkspaceQuietHoursOverlayKeepsSharedGlobalChannelDefaults(): void
    {
        Database::execute(
            "INSERT INTO workspaces (id, uuid, name, slug, status, plan_status, created_at, updated_at)
             VALUES (2, ?, 'Quiet Workspace', 'quiet-workspace', 'active', 'trialing', NOW(), NOW())",
            ['00000000-0000-4000-8000-000000000002']
        );

        $module = new AIAutoResponderConfig();
        $module->save([
            'enabled' => true,
            'mode' => 'hybrid',
            'channels' => [
                'email' => [
                    'enabled' => true,
                    'confidence_threshold' => 0.77,
                    'max_chars' => 4500,
                    'draft_length_band' => 'detailed',
                    'draft_fullness' => 'fuller',
                    'draft_include_clear_cta' => true,
                ],
            ],
        ]);

        $quietHours = new WorkspaceAIAutoResponderQuietHoursService();
        $quietHours->save(1, [
            'enabled' => true,
            'start' => '21:30',
            'end' => '07:15',
            'timezone' => 'Africa/Nairobi',
        ], 1);
        $quietHours->save(2, [
            'enabled' => false,
            'start' => '22:00',
            'end' => '06:00',
            'timezone' => 'America/New_York',
        ], 1);

        $workspaceOne = $module->get(1);
        $workspaceTwo = $module->get(2);
        $global = $module->get();

        $this->assertTrue((bool) ($workspaceOne['quiet_hours']['enabled'] ?? false));
        $this->assertSame('21:30', $workspaceOne['quiet_hours']['start'] ?? null);
        $this->assertSame('07:15', $workspaceOne['quiet_hours']['end'] ?? null);
        $this->assertSame('Africa/Nairobi', $workspaceOne['quiet_hours']['timezone'] ?? null);

        $this->assertFalse((bool) ($workspaceTwo['quiet_hours']['enabled'] ?? true));
        $this->assertSame('22:00', $workspaceTwo['quiet_hours']['start'] ?? null);
        $this->assertSame('06:00', $workspaceTwo['quiet_hours']['end'] ?? null);
        $this->assertSame('America/New_York', $workspaceTwo['quiet_hours']['timezone'] ?? null);

        $this->assertSame(4500, (int) ($workspaceOne['channels']['email']['max_chars'] ?? 0));
        $this->assertSame(4500, (int) ($workspaceTwo['channels']['email']['max_chars'] ?? 0));
        $this->assertSame('detailed', $workspaceOne['channels']['email']['draft_length_band'] ?? null);
        $this->assertSame('detailed', $workspaceTwo['channels']['email']['draft_length_band'] ?? null);
        $this->assertSame('20:00', $global['quiet_hours']['start'] ?? null);
    }

    public function testWorkspaceQuietHoursTimezoneFallsBackToCompanyProfileCountry(): void
    {
        Database::execute(
            "INSERT INTO company_profile (workspace_id, company_name, company_location, company_timezone, is_active)
             VALUES (1, 'Quiet Kenya Co', 'Nairobi, Kenya', NULL, TRUE)"
        );
        Database::execute(
            "INSERT INTO workspace_ai_autoresponder_quiet_hours
                (workspace_id, enabled, start_time, end_time, timezone, updated_by_user_id)
             VALUES (1, 1, '20:00:00', '08:00:00', 'UTC', NULL)
             ON DUPLICATE KEY UPDATE
                enabled = VALUES(enabled),
                start_time = VALUES(start_time),
                end_time = VALUES(end_time),
                timezone = VALUES(timezone),
                updated_by_user_id = VALUES(updated_by_user_id)"
        );

        $service = new WorkspaceAIAutoResponderQuietHoursService();
        $config = (new AIAutoResponderConfig())->get(1);
        $options = $service->timezoneOptions(1);

        $this->assertSame('Africa/Nairobi', $config['quiet_hours']['timezone'] ?? null);
        $this->assertSame('Africa/Nairobi', array_key_first($options));

        $service->save(1, [
            'enabled' => true,
            'start' => '20:00',
            'end' => '08:00',
            'timezone' => 'UTC',
        ], 1);

        $this->assertSame('UTC', $service->get(1)['timezone'] ?? null);
    }
}
