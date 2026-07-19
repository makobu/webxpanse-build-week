<?php

namespace CRM\Tests\Unit\Frontend;

use CRM\Tests\TestCase;

class TaskCompletionV2ContractSourceTest extends TestCase
{
    public function testMobileContractIsAdditiveAndLegacyCompletionRemainsAvailable(): void
    {
        $serializer = (string) file_get_contents(__DIR__ . '/../../../api/mobile/_serializers.php');
        $tasks = (string) file_get_contents(__DIR__ . '/../../../api/mobile/tasks.php');
        $completion = (string) file_get_contents(__DIR__ . '/../../../api/mobile/tasks/completion.php');
        $subtasks = (string) file_get_contents(__DIR__ . '/../../../api/mobile/tasks/subtasks.php');

        $this->assertStringContainsString('$summary[\'source\'] = [', $serializer);
        $this->assertStringContainsString('$summary[\'subtasks\'] =', $serializer);
        $this->assertStringContainsString('$summary[\'completion\'] =', $serializer);
        $this->assertStringContainsString('$updates[\'_completion_source\'] = \'compatibility\'', $tasks);
        foreach (['evaluate', 'complete_recommended', 'complete_anyway', 'reopen', 'set_mode'] as $action) {
            $this->assertStringContainsString("'{$action}'", $completion);
        }
        $this->assertStringContainsString("Authorization::can('tasks.write'", $completion);
        $this->assertStringContainsString("Authorization::can('tasks.write'", $subtasks);
        $this->assertStringContainsString('workspace_id', $subtasks);
    }

    public function testWebRolloutAndEvidenceControlsRemainVisibleAtNarrowWidths(): void
    {
        $tasks = (string) file_get_contents(__DIR__ . '/../../../public/tasks.php');
        $taskView = (string) file_get_contents(__DIR__ . '/../../../public/task_view.php');
        $taskCss = (string) file_get_contents(__DIR__ . '/../../../public/assets/css/tasks-ui.css');

        $this->assertStringContainsString('Review recommendations', $tasks);
        $this->assertStringContainsString('Full auto for eligible tasks', $tasks);
        $this->assertStringContainsString('Completion evidence', $taskView);
        $this->assertStringContainsString('Completed by Clarity', $taskView);
        $this->assertStringContainsString('flex-wrap:wrap', $tasks);
        $this->assertStringContainsString('flex-wrap: wrap', $taskCss);
        $this->assertStringContainsString('@media (max-width: 640px)', $taskCss);
        $this->assertStringContainsString('width: 100%', $taskCss);
    }
}
