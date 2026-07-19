<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\BuiltInPluginCapabilityHandler;
use CRM\Services\PluginRuntimeEventService;
use CRM\Services\PluginRuntimeRegistryService;
use CRM\Tests\DatabaseTestCase;

class PluginRuntimeFoundationServiceTest extends DatabaseTestCase
{
    public function testMigrationCreatesRuntimeTablesAndSourceColumns(): void
    {
        $this->assertTrue(Database::tableExists('workspace_plugin_capabilities'));
        $this->assertTrue(Database::tableExists('target_metric_providers'));
        $this->assertTrue(Database::tableExists('workspace_plugin_runtime_events'));
        $this->assertTrue(Database::columnExists('tasks', 'source_skill_key'));
        $this->assertTrue(Database::columnExists('tasks', 'source_capability_key'));
        $this->assertTrue(Database::columnExists('targets', 'source_skill_key'));
    }

    public function testInstalledReadySkillExposesRuntimeCapabilities(): void
    {
        $this->installDefinition('runtime_test_skill', 'skill');

        $capabilities = (new PluginRuntimeRegistryService())->capabilitiesForWorkspace(1, 0, true);
        $matching = array_values(array_filter(
            $capabilities,
            static fn(array $capability): bool => (string) ($capability['skill_key'] ?? '') === 'runtime_test_skill'
        ));

        $this->assertNotEmpty($matching);
        $this->assertContains('runtime_test_skill.task_enricher', array_column($matching, 'capability_key'));
        $this->assertTrue((bool) ($matching[0]['available'] ?? false));
    }

    public function testUninstalledSkillDoesNotExposeRuntimeCapabilities(): void
    {
        $this->upsertDefinition('runtime_uninstalled_skill', 'skill');

        $capabilities = (new PluginRuntimeRegistryService())->capabilitiesForWorkspace(1, 0, true);
        $matching = array_values(array_filter(
            $capabilities,
            static fn(array $capability): bool => (string) ($capability['skill_key'] ?? '') === 'runtime_uninstalled_skill'
        ));

        $this->assertSame([], $matching);
    }

    public function testRuntimeEventsRecordAndRespectDateFilters(): void
    {
        $events = new PluginRuntimeEventService();
        $events->record([
            'workspace_id' => 1,
            'skill_key' => 'runtime_test_skill',
            'capability_key' => 'runtime_test_skill.task_enricher',
            'event_type' => 'task_enriched',
            'status' => 'success',
            'metadata' => ['source' => 'test'],
        ]);

        $today = date('Y-m-d');
        $recent = $events->recent(['workspace_id' => 1, 'date_from' => $today, 'date_to' => $today], 10);
        $this->assertNotEmpty($recent);
        $this->assertSame('task_enriched', (string) ($recent[0]['event_type'] ?? ''));

        $past = $events->recent(['workspace_id' => 1, 'date_from' => '2000-01-01', 'date_to' => '2000-01-01'], 10);
        $this->assertSame([], $past);
    }

    public function testBuiltInPluginAIContextIncludesResponseStyleContract(): void
    {
        Database::execute(
            "INSERT INTO workspace_onboarding_state (workspace_id, status, current_step, tone_json)
             VALUES (1, 'in_progress', 1, ?)
             ON DUPLICATE KEY UPDATE tone_json = VALUES(tone_json)",
            [json_encode(['draft_reading_level' => 'level_1'])]
        );

        $context = (new BuiltInPluginCapabilityHandler())->provideAIContext([
            'skill_key' => 'runtime_test_skill',
            'capability_key' => 'runtime_test_skill.ai_context',
        ], 1, 1);

        $contract = (array) ($context['response_style_contract'] ?? []);
        $this->assertSame('runtime_test_skill', $context['skill_key'] ?? null);
        $this->assertSame('level_1', $contract['level'] ?? null);
        $this->assertSame('Level 1', $contract['label'] ?? null);
        $this->assertStringContainsString('plain terms', (string) ($contract['prompt_instruction'] ?? ''));
    }

    private function installDefinition(string $skillKey, string $moduleType): void
    {
        $this->upsertDefinition($skillKey, $moduleType);
        Database::execute(
            "INSERT INTO workspace_skill_installs (
                workspace_id, skill_key, status, config_json, installed_at, uninstalled_at
             ) VALUES (1, ?, 'installed', '{}', NOW(), NULL)
             ON DUPLICATE KEY UPDATE status = 'installed', uninstalled_at = NULL, updated_at = NOW()",
            [$skillKey]
        );
    }

    private function upsertDefinition(string $skillKey, string $moduleType): void
    {
        Database::execute(
            "INSERT INTO workspace_skill_definitions (
                skill_key, label, summary, category, module_type, version, capabilities_json,
                onboarding_fields_json, settings_schema_json, ai_context_provider,
                navigation_json, permissions_json, is_active
             ) VALUES (?, ?, ?, 'strategy', ?, '1.0.0', '{}', '[]', '{}', NULL, '[]', '[]', 1)
             ON DUPLICATE KEY UPDATE
                label = VALUES(label),
                module_type = VALUES(module_type),
                is_active = 1,
                updated_at = NOW()",
            [$skillKey, ucwords(str_replace('_', ' ', $skillKey)), 'Runtime foundation test', $moduleType]
        );
    }
}
