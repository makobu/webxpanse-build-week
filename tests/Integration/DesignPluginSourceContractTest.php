<?php

namespace CRM\Tests\Integration;

use CRM\Services\MarketingMarketplaceGateService;
use CRM\Services\WorkspaceSkillCatalogService;
use PHPUnit\Framework\TestCase;

class DesignPluginSourceContractTest extends TestCase
{
    public function testCatalogPointsToDedicatedRuntimeThatCanCompleteItsOwnSetup(): void
    {
        $catalog = (new WorkspaceSkillCatalogService())->definitions();
        $design = $catalog[WorkspaceSkillCatalogService::PLUGIN_DESIGN] ?? null;

        $this->assertIsArray($design);
        $this->assertTrue($design['capabilities']['runtime_plugin']);
        $this->assertTrue($design['capabilities']['runtime_during_setup']);
        $this->assertTrue($design['capabilities']['email_signatures']);
        $this->assertArrayNotHasKey('websites', $design['capabilities']);
        $this->assertSame('design.php', $design['navigation']['url']);
        $this->assertSame('Design', $design['plugin_metadata']['runtime_provider']);
        $this->assertSame('workspace_skills.php?module=design#setup', $design['plugin_metadata']['setup_url']);
    }

    public function testDedicatedWorkspaceExposesLandingFormAndCreativeEntryPoints(): void
    {
        $page = file_get_contents(__DIR__ . '/../../public/design.php');
        $service = file_get_contents(__DIR__ . '/../../services/DesignService.php');
        $styles = file_get_contents(__DIR__ . '/../../public/assets/css/design.css');

        $this->assertStringContainsString('FEATURE_DESIGN', (string) $page);
        $this->assertStringContainsString('data-design-workspace', (string) $page);
        $this->assertStringContainsString('marketing_landing_page_edit.php', (string) $page);
        $this->assertStringContainsString('form_edit.php', (string) $page);
        $this->assertStringContainsString('marketing_assets.php', (string) $page);
        $this->assertStringContainsString('email_signatures.php', (string) $page);
        $this->assertStringContainsString('DesignService', (string) $page);
        $this->assertStringContainsString("Authorization::can('marketing.write'", (string) $page);
        $this->assertStringContainsString('form_submissions.php?form_uuid=', (string) $page);
        $this->assertStringNotContainsString('form_submissions.php?form=', (string) $page);
        $this->assertStringContainsString('$isReady = !empty($readiness[\'ready\']);', (string) $page);
        $this->assertStringContainsString('<h2>Next up</h2>', (string) $page);
        $this->assertStringContainsString('plugin-workspaces.css', (string) $page);
        $this->assertStringContainsString('.design-tabs { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); overflow: visible; }', (string) $styles);

        $this->assertStringContainsString('assertInstalled', (string) $service);
        $this->assertStringContainsString('WHERE lp.workspace_id = ?', (string) $service);
        $this->assertStringContainsString('WHERE f.workspace_id = ?', (string) $service);
        $this->assertStringNotContainsString('workspace_id IS NULL', (string) $service);
    }

    public function testReadinessUsesEitherConversionSurfaceAndReportsTheAlternativeBlocker(): void
    {
        $installer = file_get_contents(__DIR__ . '/../../services/WorkspaceSkillInstallService.php');

        $this->assertStringContainsString(
            '$ready = $landingPages > 0 || $forms > 0 || $publishedPages > 0;',
            (string) $installer
        );
        $this->assertStringContainsString("['label' => 'Conversion surface', 'ok' => \$ready, 'required' => true", (string) $installer);
        $this->assertStringContainsString("'blockers' => \$ready ? [] : ['Landing page or lead capture form']", (string) $installer);
        $this->assertStringNotContainsString("'blockers' => \$ready ? [] : ['Landing pages']", (string) $installer);
    }

    public function testFormsAndLandingPageToolsUseTheDesignMarketplaceGate(): void
    {
        foreach ([
            'public/forms.php',
            'public/form_edit.php',
            'public/form_delete.php',
            'public/form_submissions.php',
            'public/marketing_landing_pages.php',
            'public/marketing_landing_page_edit.php',
            'public/marketing_landing_page_view.php',
            'public/marketing_landing_page_preview.php',
        ] as $path) {
            $source = file_get_contents(__DIR__ . '/../../' . $path);
            $this->assertStringContainsString('_marketing_marketplace_gate.php', (string) $source, $path);
        }

        $gate = new MarketingMarketplaceGateService();
        foreach (['design.php', 'forms.php', 'form_edit.php', 'form_delete.php', 'form_asset_upload.php', 'form_submissions.php', 'marketing_landing_pages.php', 'email_signatures.php', 'email_signature_create.php', 'email_signature_edit.php'] as $page) {
            $this->assertSame(MarketingMarketplaceGateService::FEATURE_DESIGN, $gate->featureForPage($page), $page);
        }

        foreach (['public/form_edit.php', 'public/form_delete.php', 'public/form_asset_upload.php'] as $path) {
            $source = file_get_contents(__DIR__ . '/../../' . $path);
            $this->assertStringContainsString("Authorization::can('marketing.write'", (string) $source, $path);
        }

        $assetUpload = file_get_contents(__DIR__ . '/../../public/form_asset_upload.php');
        $this->assertStringContainsString('updateByUuid', (string) $assetUpload);
        $this->assertStringNotContainsString('UPDATE forms SET settings', (string) $assetUpload);
    }

    public function testMarketplaceSetupProvidesDirectSetupCompletionActions(): void
    {
        require_once __DIR__ . '/../../views/partials/marketplace_plugin_setup.php';
        ob_start();
        \marketplaceRenderDesignSetup([
            'active_tab' => 'overview',
            'installed' => true,
            'readiness' => [
                'ready' => false,
                'message' => 'Design needs a landing page or lead capture form to be ready.',
                'counts' => ['landing_pages' => 0, 'forms' => 0, 'published_pages' => 0, 'media_files' => 0],
            ],
        ]);
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('Open Design', $html);
        $this->assertStringContainsString('Create landing page', $html);
        $this->assertStringContainsString('Create form', $html);
        $this->assertStringContainsString('Design needs a landing page or lead capture form to be ready.', $html);
        $this->assertStringContainsString('Setup needed', $html);
        $this->assertStringContainsString('no separate configuration save is required', $html);
        $this->assertStringContainsString('href="design.php"', $html);
    }
}
