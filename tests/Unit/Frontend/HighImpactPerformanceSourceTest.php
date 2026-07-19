<?php

namespace CRM\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

class HighImpactPerformanceSourceTest extends TestCase
{
    public function testDashboardTaskEndpointUsesDatabaseLimitAndEmitsTiming(): void
    {
        $endpoint = $this->readFile('api/dashboard_tasks.php');

        $this->assertStringContainsString("getUserTasks(\$userId, ['status' => 'pending'], 5)", $endpoint);
        $this->assertStringNotContainsString('array_slice($tasksModule->getUserTasks', $endpoint);
        $this->assertStringContainsString('dashboard_tasks_total', $endpoint);
        $this->assertStringContainsString('Session::closeWrite();', $endpoint);
    }

    public function testReadOnlyDashboardApisReleaseSessionWriteLock(): void
    {
        foreach (['api/dashboard/plain_readiness.php', 'api/dashboard/beginner_guidance.php'] as $path) {
            $this->assertStringContainsString('Session::closeWrite();', $this->readFile($path), $path);
        }
    }

    public function testSharedNotificationRuntimeCachesBacksOffAndPausesWhenHidden(): void
    {
        $layout = $this->readFile('views/layouts/base.php');

        $this->assertStringContainsString('notificationCacheTtlMs = 30000', $layout);
        $this->assertStringContainsString('notificationPollMs = 60000', $layout);
        $this->assertStringContainsString('if (document.hidden && !forceRefresh)', $layout);
        $this->assertStringContainsString("document.addEventListener('visibilitychange'", $layout);
        $this->assertStringContainsString('browserCooldownMs = 60000', $layout);
    }

    public function testDashboardDoesNotGenerateOperatingBriefOnReadPath(): void
    {
        $dashboard = $this->readFile('public/dashboard.php');
        $onboarding = $this->readFile('services/WorkspaceOnboardingService.php');

        $this->assertStringNotContainsString('$operatingBriefService->generate(', $dashboard);
        $this->assertStringContainsString('$this->ensureOperatingBrief($workspaceId, $userId);', $onboarding);
    }

    public function testAutomationBatteryHydratesAfterFirstPaint(): void
    {
        $dashboard = $this->readFile('public/dashboard.php');

        $this->assertStringNotContainsString('getStoredStatus($userId, $automationBatterySubjectUserId)', $dashboard);
        $this->assertStringContainsString('automationBatteryIdleDelayMs: 3000', $dashboard);
        $this->assertStringContainsString('$initialPlainReadiness !== null', $dashboard);
        $this->assertStringContainsString("loadAutomationBattery('idle')", $dashboard);
    }

    public function testAiCoachAccessHydratesAfterFirstPaintAndDeniesByDefault(): void
    {
        $dashboard = $this->readFile('public/dashboard.php');
        $endpoint = $this->readFile('api/dashboard/ai_coach_access.php');

        $this->assertStringContainsString('data-ai-coach-access-pending hidden aria-hidden="true"', $dashboard);
        $this->assertStringContainsString('runWhenIdle(hydrateAICoachAccess, 300)', $dashboard);
        $this->assertStringContainsString("fetch(asyncConfig.aiCoachAccessUrl, { credentials: 'same-origin' })", $dashboard);
        $this->assertStringContainsString('Session::closeWrite();', $endpoint);
        $this->assertStringContainsString("'enabled' => false", $endpoint);
    }

    private function readFile(string $path): string
    {
        $contents = file_get_contents(__DIR__ . '/../../../' . $path);
        $this->assertNotFalse($contents);

        return str_replace(["\r\n", "\r"], "\n", (string) $contents);
    }
}
