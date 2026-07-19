<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\WorkspaceMarketplaceActivationBundleService;
use CRM\Services\WorkspaceSkillCatalogService;
use CRM\Tests\DatabaseTestCase;

class WorkspaceMarketplaceActivationBundleServiceTest extends DatabaseTestCase
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, 'admin', NOW())",
            [uniqid('bundle_admin_', true), 'bundle-admin@example.test', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $this->userId = (int) Database::lastInsertId();
    }

    public function testDefinitionTableIncludesExpectedColumnsAndSeededDefaults(): void
    {
        $columns = Database::query(
            "SELECT COLUMN_NAME
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'workspace_marketplace_activation_bundle_definitions'"
        );
        $columnNames = array_column($columns, 'COLUMN_NAME');
        foreach (['bundle_key', 'label', 'summary', 'included_skill_keys_json', 'thumbnail_url', 'banner_url', 'explainer_video_url', 'why_this_bundle', 'expected_outcome', 'is_active', 'archived_at', 'display_order', 'created_by_user_id', 'updated_by_user_id'] as $column) {
            $this->assertContains($column, $columnNames);
        }

        $service = new WorkspaceMarketplaceActivationBundleService();
        $definitions = $service->definitions();
        $this->assertArrayHasKey('strategy_foundation', $definitions);
        $this->assertArrayHasKey('communication_ai_operator', $definitions);
        $this->assertSame([
            WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS,
            WorkspaceSkillCatalogService::SKILL_PROFESSIONAL_MARKETER,
        ], (array) ($definitions['strategy_foundation']['included_skill_keys'] ?? []));

        $adminDefinitions = $service->allDefinitionsForAdmin();
        $this->assertGreaterThanOrEqual(5, count($adminDefinitions));
        $this->assertArrayHasKey('is_active', $adminDefinitions[0]);
        $this->assertArrayHasKey('display_order', $adminDefinitions[0]);
    }

    public function testSuperadminDefinitionLifecycleFiltersActiveAndArchivedBundles(): void
    {
        $service = new WorkspaceMarketplaceActivationBundleService();
        $created = $service->saveDefinition([
            'bundle_key' => 'Unit Test Growth Bundle',
            'label' => 'Unit Test Growth Bundle',
            'summary' => 'A focused bundle for unit testing the Marketplace bundle catalog.',
            'included_skill_keys' => [
                WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS,
                WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT,
            ],
            'thumbnail_url' => 'uploads/marketplace/unit_test_growth_bundle/thumb.webp',
            'banner_url' => 'uploads/marketplace/unit_test_growth_bundle/banner.webp',
            'explainer_video_url' => 'uploads/marketplace/unit_test_growth_bundle/video.mp4',
            'why_this_bundle' => 'It proves database-backed bundle definitions can be created.',
            'expected_outcome' => 'The bundle can be recommended while active.',
            'display_order' => 75,
            'is_active' => true,
        ], $this->userId);

        $this->assertSame('unit_test_growth_bundle', (string) ($created['bundle_key'] ?? ''));
        $this->assertSame('uploads/marketplace/unit_test_growth_bundle/thumb.webp', (string) ($created['thumbnail_url'] ?? ''));
        $this->assertSame('uploads/marketplace/unit_test_growth_bundle/banner.webp', (string) ($created['banner_url'] ?? ''));
        $this->assertSame('uploads/marketplace/unit_test_growth_bundle/video.mp4', (string) ($created['explainer_video_url'] ?? ''));
        $this->assertArrayHasKey('unit_test_growth_bundle', $service->definitions());

        $updated = $service->saveDefinition([
            'current_bundle_key' => 'unit_test_growth_bundle',
            'bundle_key' => 'unit_test_growth_bundle',
            'label' => 'Updated Unit Test Growth Bundle',
            'summary' => 'An updated summary for the same bundle.',
            'included_skill_keys' => [WorkspaceSkillCatalogService::SKILL_PROFESSIONAL_MARKETER],
            'thumbnail_url' => 'uploads/marketplace/unit_test_growth_bundle/thumb-updated.webp',
            'banner_url' => 'uploads/marketplace/unit_test_growth_bundle/banner-updated.webp',
            'explainer_video_url' => 'uploads/marketplace/unit_test_growth_bundle/video-updated.mp4',
            'display_order' => 80,
            'is_active' => false,
        ], $this->userId);

        $this->assertSame('Updated Unit Test Growth Bundle', (string) ($updated['label'] ?? ''));
        $this->assertSame('uploads/marketplace/unit_test_growth_bundle/thumb-updated.webp', (string) ($updated['thumbnail_url'] ?? ''));
        $this->assertArrayNotHasKey('unit_test_growth_bundle', $service->definitions());

        $service->setDefinitionActive('unit_test_growth_bundle', true, $this->userId);
        $this->assertArrayHasKey('unit_test_growth_bundle', $service->definitions());

        $service->archiveDefinition('unit_test_growth_bundle', $this->userId);
        $this->assertArrayNotHasKey('unit_test_growth_bundle', $service->definitions());

        $archived = null;
        foreach ($service->allDefinitionsForAdmin() as $definition) {
            if ((string) ($definition['bundle_key'] ?? '') === 'unit_test_growth_bundle') {
                $archived = $definition;
                break;
            }
        }

        $this->assertNotNull($archived);
        $this->assertNotSame('', (string) ($archived['archived_at'] ?? ''));
        $this->assertFalse((bool) ($archived['is_active'] ?? true));

        $service->deleteDefinition('unit_test_growth_bundle');
        $this->assertArrayNotHasKey('unit_test_growth_bundle', $service->definitions());
        $this->assertFalse((bool) Database::queryOne(
            "SELECT 1
             FROM workspace_marketplace_activation_bundle_definitions
             WHERE bundle_key = ?
             LIMIT 1",
            ['unit_test_growth_bundle']
        ));
    }

    public function testValidationRejectsMissingContentAndUnknownIncludedSkills(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new WorkspaceMarketplaceActivationBundleService())->saveDefinition([
            'bundle_key' => 'invalid_bundle',
            'label' => 'Invalid Bundle',
            'summary' => 'This should fail because the included module is unknown.',
            'included_skill_keys' => ['not_a_marketplace_module'],
            'is_active' => true,
        ], $this->userId);
    }

    public function testDefinitionsFallBackToCodeDefaultsWhenDefinitionTableIsMissing(): void
    {
        Database::execute('DROP TABLE workspace_marketplace_activation_bundle_definitions');

        $service = new WorkspaceMarketplaceActivationBundleService();
        $definitions = $service->definitions();

        $this->assertArrayHasKey('email_led_growth', $definitions);
        $this->assertSame('Email-led growth', (string) ($definitions['email_led_growth']['label'] ?? ''));
        $this->assertGreaterThanOrEqual(5, count($service->allDefinitionsForAdmin()));
    }
}
