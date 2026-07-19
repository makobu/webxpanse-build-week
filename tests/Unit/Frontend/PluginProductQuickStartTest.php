<?php

namespace CRM\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

class PluginProductQuickStartTest extends TestCase
{
    public function testAllFourMarketingProductsUseTheSharedQuickStart(): void
    {
        $expectedOutcomes = [
            'design.php' => 'Publish a page that captures one lead',
            'social_media.php' => 'Schedule your first on-brand post',
            'marketing.php' => 'Build a launch-ready campaign plan',
            'marketing_assistants.php' => 'Get one useful marketing recommendation',
        ];

        foreach ($expectedOutcomes as $page => $outcome) {
            $source = file_get_contents(__DIR__ . '/../../../public/' . $page);
            $this->assertNotFalse($source, $page);
            $this->assertStringContainsString($outcome, (string) $source, $page);
            $this->assertStringContainsString('plugin_product_quick_start.php', (string) $source, $page);
            $this->assertStringContainsString('plugin-workspaces.js', (string) $source, $page);
        }
    }

    public function testQuickStartUsesRealProgressAndAReusableDismissControl(): void
    {
        $partial = file_get_contents(__DIR__ . '/../../../views/partials/plugin_product_quick_start.php');
        $script = file_get_contents(__DIR__ . '/../../../public/assets/js/plugin-workspaces.js');
        $css = file_get_contents(__DIR__ . '/../../../public/assets/css/plugin-workspaces.css');

        $this->assertNotFalse($partial);
        $this->assertNotFalse($script);
        $this->assertNotFalse($css);
        $this->assertStringContainsString('role="progressbar"', (string) $partial);
        $this->assertStringContainsString('data-workspace-guide-hide', (string) $partial);
        $this->assertStringContainsString('data-workspace-guide-show', (string) $partial);
        $this->assertStringContainsString("storagePrefix = 'crm.plugin-workspace-guide.'", (string) $script);
        $this->assertStringContainsString('prefers-reduced-motion', (string) $css);
        $this->assertStringContainsString('.plugin-quick-start', (string) $css);
    }

    public function testEachProductDefinesExactlyThreeOutcomeSteps(): void
    {
        foreach (['design.php', 'social_media.php', 'marketing.php', 'marketing_assistants.php'] as $page) {
            $source = file_get_contents(__DIR__ . '/../../../public/' . $page);
            $this->assertNotFalse($source, $page);
            $this->assertMatchesRegularExpression(
                "/'steps'\\s*=>\\s*\\[(.*?)\\],\\s*'completion_action'/s",
                (string) $source,
                $page
            );
        }
    }

    public function testDesignLeadCaptureStepUsesAConnectedFormSignal(): void
    {
        $service = file_get_contents(__DIR__ . '/../../../services/DesignService.php');
        $page = file_get_contents(__DIR__ . '/../../../public/design.php');

        $this->assertNotFalse($service);
        $this->assertNotFalse($page);
        $this->assertStringContainsString("'pages_with_forms' => \$pagesWithForms", (string) $service);
        $this->assertStringContainsString("'complete' => (int) (\$summary['pages_with_forms'] ?? 0) > 0", (string) $page);
        $this->assertStringContainsString("'label' => 'Connect a lead form'", (string) $page);
    }
}
