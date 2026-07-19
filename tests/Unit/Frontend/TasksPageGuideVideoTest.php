<?php

namespace CRM\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

class TasksPageGuideVideoTest extends TestCase
{
    public function testTasksPageGuideButtonIsRenderedBesideNewTaskAction(): void
    {
        $tasks = file_get_contents(__DIR__ . '/../../../public/tasks.php');

        $this->assertNotFalse($tasks);
        $this->assertStringContainsString('MarketplacePageExplainerService::PAGE_TASKS', (string) $tasks);
        $this->assertStringContainsString('PageGuideVideoUi::activeVideoUrl', (string) $tasks);
        $this->assertStringContainsString('PageGuideVideoUi::assets()', (string) $tasks);
        $this->assertStringContainsString('PageGuideVideoUi::modal(MarketplacePageExplainerService::PAGE_TASKS', (string) $tasks);

        $headerActionsPosition = strpos((string) $tasks, '<div class="page-header-actions">');
        $guidePosition = strpos((string) $tasks, "PageGuideVideoUi::button(MarketplacePageExplainerService::PAGE_TASKS");
        $newTaskPosition = strpos((string) $tasks, 'href="task_create.php"');
        $filterActionsPosition = strpos((string) $tasks, '<div class="filter-actions">');

        $this->assertIsInt($headerActionsPosition);
        $this->assertIsInt($filterActionsPosition);
        $this->assertIsInt($guidePosition);
        $this->assertIsInt($newTaskPosition);
        $this->assertGreaterThan($headerActionsPosition, $guidePosition);
        $this->assertGreaterThan($guidePosition, $newTaskPosition);
        $this->assertLessThan($filterActionsPosition, $guidePosition);
    }

    public function testAssigneeFilterClarifiesItsWorkspaceScope(): void
    {
        $tasks = file_get_contents(__DIR__ . '/../../../public/tasks.php');

        $this->assertNotFalse($tasks);
        $this->assertStringContainsString('<option value="">All workspace members</option>', (string) $tasks);
    }
}
