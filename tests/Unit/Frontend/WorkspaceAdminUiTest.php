<?php

namespace CRM\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

class WorkspaceAdminUiTest extends TestCase
{
    private function workspaceAdminPage(): string
    {
        $page = file_get_contents(__DIR__ . '/../../../public/workspace_admin.php');

        $this->assertNotFalse($page);

        return str_replace(["\r\n", "\r"], "\n", (string) $page);
    }

    private function adminControlsCss(): string
    {
        $css = file_get_contents(__DIR__ . '/../../../public/assets/css/admin-controls-ui.css');

        $this->assertNotFalse($css);

        return str_replace(["\r\n", "\r"], "\n", (string) $css);
    }

    public function testWorkspaceAdminLoadsSharedAdminStyles(): void
    {
        $page = $this->workspaceAdminPage();

        $this->assertStringContainsString('<link rel="stylesheet" href="assets/css/premium-pages.css">', $page);
        $this->assertStringContainsString('<link rel="stylesheet" href="assets/css/admin-controls-ui.css">', $page);
        $this->assertStringContainsString('class="page-premium workspace-ops-page"', $page);
        $this->assertStringContainsString('<h1 id="workspace-ops-title">Workspace Operations</h1>', $page);
    }

    public function testWorkspaceAdminRendersSectionRailContract(): void
    {
        $page = $this->workspaceAdminPage();

        foreach ([
            'ops-overview',
            'ops-onboarding',
            'ops-launch',
            'ops-runtime-health',
            'ops-billing',
            'ops-runtime-console',
            'ops-activity',
            'ops-actions',
            'ops-governance',
        ] as $sectionId) {
            $this->assertStringContainsString($sectionId, $page);
        }

        $this->assertStringContainsString('class="workspace-ops-section-nav"', $page);
        $this->assertStringContainsString('class="workspace-ops-main"', $page);
        $this->assertStringContainsString('class="workspace-ops-rail"', $page);
    }

    public function testWorkspaceAdminKeepsCoreOperatorActions(): void
    {
        $page = $this->workspaceAdminPage();

        foreach ([
            'value="wallet_adjustment"',
            'value="runtime_replay"',
            'value="provider_event_replay"',
            'value="impersonate"',
            'value="governance_override"',
            'value="transfer_ownership"',
            'value="update_member_role"',
            'value="update_member_status"',
        ] as $actionNeedle) {
            $this->assertStringContainsString($actionNeedle, $page);
        }

        $this->assertStringContainsString('workspace-ops-form', $page);
        $this->assertStringContainsString('class="workspace-ops-list"', $page);
        $this->assertStringContainsString('Workspace Lifecycle', $page);
        $this->assertStringContainsString('Manual AI Credit Adjustment', $page);
        $this->assertStringContainsString('Tenant Runtime Console', $page);
        $this->assertStringContainsString('Workspace Governance', $page);
    }

    public function testWorkspaceAdminUsesCompactLaunchReadinessContract(): void
    {
        $page = $this->workspaceAdminPage();
        $css = $this->adminControlsCss();

        $this->assertStringContainsString('<section id="ops-launch" class="workspace-ops-card">', $page);
        $this->assertStringContainsString('class="workspace-ops-launch-summary"', $page);
        $this->assertStringContainsString('class="workspace-ops-disclosure"', $page);
        $this->assertStringContainsString('class="workspace-ops-readiness-list"', $page);
        $this->assertStringContainsString('workspace-ops-readiness-row--blocked', $page);
        $this->assertStringContainsString('Surface readiness details', $page);

        foreach ([
            '.workspace-ops-launch-summary',
            '.workspace-ops-disclosure',
            '.workspace-ops-readiness-list',
            '.workspace-ops-readiness-row',
            '.workspace-ops-readiness-row--blocked',
        ] as $needle) {
            $this->assertStringContainsString($needle, $css);
        }
    }

    public function testWorkspaceAdminUsesSemanticOnboardingPanelColors(): void
    {
        $page = $this->workspaceAdminPage();
        $css = $this->adminControlsCss();

        foreach ([
            'workspace-ops-panel--info',
            'workspace-ops-panel--warning',
            'workspace-ops-panel--success',
            'workspace-ops-panel--neutral',
            'workspace-ops-panel--action',
            'workspace-ops-panel--danger',
        ] as $className) {
            $this->assertStringContainsString($className, $page);
            $this->assertStringContainsString('.' . $className, $css);
        }

        foreach ([
            'value="generate_onboarding_nudge"',
            'value="send_onboarding_nudge"',
            'value="resend_owner_setup_link"',
            'value="reset_onboarding_state"',
            'value="mark_onboarding_complete"',
        ] as $actionNeedle) {
            $this->assertStringContainsString($actionNeedle, $page);
        }
    }

    public function testWorkspaceAdminUsesDensityCleanupPrimitives(): void
    {
        $page = $this->workspaceAdminPage();
        $css = $this->adminControlsCss();

        foreach ([
            'workspace-ops-section-head',
            'workspace-ops-muted',
            'workspace-ops-stack',
            'workspace-ops-content-grid',
            'workspace-ops-stat-grid',
            'workspace-ops-stat',
            'workspace-ops-list-row',
            'workspace-ops-list-row__meta',
            'workspace-ops-form-grid',
            'workspace-ops-form-actions',
            'workspace-ops-subpanel',
        ] as $className) {
            $this->assertStringContainsString($className, $page);
            $this->assertStringContainsString('.' . $className, $css);
        }

        $this->assertStringNotContainsString('style="', $page);
        $this->assertStringNotContainsString('[style*=', $css);
    }

    public function testWorkspaceOpsCssDefinesResponsiveLayoutContract(): void
    {
        $css = $this->adminControlsCss();

        foreach ([
            '.workspace-ops-hero',
            '.workspace-ops-metrics',
            '.workspace-ops-layout',
            '.workspace-ops-section-nav',
            '.workspace-ops-main',
            '.workspace-ops-rail',
            '.workspace-ops-card',
            '.workspace-ops-list',
            '.workspace-ops-form',
            '.workspace-ops-content-grid',
            '.workspace-ops-stat-grid',
            '.workspace-ops-list-row',
            '.workspace-ops-launch-summary',
            '.workspace-ops-disclosure',
            '.workspace-ops-readiness-row',
            '@media (max-width: 920px)',
            '@media (max-width: 760px)',
        ] as $needle) {
            $this->assertStringContainsString($needle, $css);
        }
    }
}
