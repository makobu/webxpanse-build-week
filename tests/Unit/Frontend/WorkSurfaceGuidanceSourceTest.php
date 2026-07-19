<?php

namespace Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

class WorkSurfaceGuidanceSourceTest extends TestCase
{
    public function testOutcomeEventsWhitelistWorkSurfaceGuidanceKeys(): void
    {
        $service = file_get_contents(__DIR__ . '/../../../services/OutcomeEventService.php');

        $this->assertStringContainsString('work_surface.guidance.viewed', (string) $service);
        $this->assertStringContainsString('work_surface.guidance.clicked', (string) $service);
        $this->assertStringContainsString('work_surface.ai_help.requested', (string) $service);
    }

    public function testSharedPartialUsesApiEndpointAndMetadataTracking(): void
    {
        $partial = file_get_contents(__DIR__ . '/../../../views/partials/beginner_work_surface_guidance.php');

        $this->assertStringContainsString('../api/outcomes/events.php', (string) $partial);
        $this->assertStringContainsString('data-work-surface-guidance', (string) $partial);
        $this->assertStringContainsString('work_surface.guidance.viewed', (string) $partial);
        $this->assertStringContainsString('work_surface.guidance.clicked', (string) $partial);
    }

    public function testCoreWorkPagesWireGuidanceWithoutDashboardBundle(): void
    {
        $pages = [
            'public/inbox.php',
            'public/tasks.php',
            'public/contacts.php',
            'public/deals.php',
            'public/invoices.php',
            'public/contacts_create.php',
            'public/invoice_create.php',
            'public/task_view.php',
            'public/deal_view.php',
            'public/conversation.php',
        ];

        foreach ($pages as $pagePath) {
            $page = file_get_contents(__DIR__ . '/../../../' . $pagePath);
            $this->assertStringContainsString('BeginnerWorkSurfaceGuidanceService', (string) $page, $pagePath);
            $this->assertStringContainsString('work-surface-guidance.css', (string) $page, $pagePath);
            $this->assertStringContainsString('beginner_work_surface_guidance.php', (string) $page, $pagePath);
        }

        $dashboard = file_get_contents(__DIR__ . '/../../../public/dashboard.php');
        $this->assertStringNotContainsString('work-surface-guidance.css', (string) $dashboard);
        $this->assertStringNotContainsString('BeginnerWorkSurfaceGuidanceService', (string) $dashboard);
    }

    public function testServiceAvoidsAdvancedMarketingTermsInBeginnerCopy(): void
    {
        $service = strtolower((string) file_get_contents(__DIR__ . '/../../../services/BeginnerWorkSurfaceGuidanceService.php'));

        foreach (['campaign workspace', 'automation battery', 'diagnostics', 'launch control', 'personas'] as $term) {
            $this->assertStringNotContainsString($term, $service);
        }
    }
}
