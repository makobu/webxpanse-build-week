<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\AIRuntimeControlService;
use CRM\Services\WorkspaceContext;
use CRM\Tests\DatabaseTestCase;

class AIRuntimeControlServiceTest extends DatabaseTestCase
{
    public function testSurfaceControlOverridesGlobalWhenStricter(): void
    {
        $service = new AIRuntimeControlService();

        $service->setControl('global', 'suggest_only', 1, 'Global safe mode');
        $service->setControl('assistant', 'paused', 1, 'Pause assistant');

        $assistant = $service->getEffectiveControl('assistant');
        $coach = $service->getEffectiveControl('coach');

        $this->assertSame('paused', $assistant['control_mode']);
        $this->assertSame('suggest_only', $coach['control_mode']);
    }

    public function testDiagnosticsOnlyBeatsSuggestOnlyBecauseItBlocksExecution(): void
    {
        $service = new AIRuntimeControlService();

        $service->setControl('global', 'suggest_only', 1, 'Global suggest mode', null, [], 1);
        $service->setControl('assistant', 'diagnostics_only', 1, 'Diagnostics-only assistant', null, [], 1);

        $assistant = $service->getEffectiveControl('assistant', 1);

        $this->assertSame('diagnostics_only', $assistant['control_mode']);
        $this->assertFalse($service->isExecutionAllowed('assistant', 1));
    }

    public function testExpiredControlsAreClearedAutomatically(): void
    {
        $service = new AIRuntimeControlService();

        $service->setControl('clarity_chat', 'paused', 1, 'Temporary', date('Y-m-d H:i:s', strtotime('-1 hour')));
        $service->clearExpiredControls();

        $control = $service->getEffectiveControl('clarity_chat');
        $this->assertSame('normal', $control['control_mode']);

        $log = Database::queryOne("SELECT * FROM ai_runtime_control_log ORDER BY id DESC LIMIT 1");
        $this->assertSame('normal', $log['new_mode']);
    }

    public function testControlsAreScopedToWorkspace(): void
    {
        $service = new AIRuntimeControlService();
        $workspaceTwoId = $this->createWorkspace('runtime-control-ws2');

        $service->setControl('assistant', 'paused', 1, 'Workspace one pause', null, [], 1);
        $service->setControl('assistant', 'suggest_only', 1, 'Workspace two suggest', null, [], $workspaceTwoId);

        $this->assertSame('paused', $service->getEffectiveControl('assistant', 1)['control_mode']);
        $this->assertSame('suggest_only', $service->getEffectiveControl('assistant', $workspaceTwoId)['control_mode']);
        $this->assertCount(1, $service->getConfiguredControls(1));
        $this->assertCount(1, $service->getConfiguredControls($workspaceTwoId));
        $this->assertSame(1, (int) ($service->getRecentLog(1, 1)[0]['workspace_id'] ?? 0));
    }

    public function testExpiredControlsCanClearOneWorkspaceOrAllWorkspaces(): void
    {
        $service = new AIRuntimeControlService();
        $workspaceTwoId = $this->createWorkspace('runtime-control-expiry');
        $expiredAt = date('Y-m-d H:i:s', strtotime('-1 hour'));

        $service->setControl('assistant', 'paused', 1, 'Expired one', $expiredAt, [], 1);
        $service->setControl('assistant', 'paused', 1, 'Expired two', $expiredAt, [], $workspaceTwoId);

        $this->assertSame(1, $service->clearExpiredControls(1));
        $this->assertSame('normal', $service->getEffectiveControl('assistant', 1)['control_mode']);
        $this->assertCount(1, $service->getConfiguredControls($workspaceTwoId));

        $this->assertSame(1, $service->clearExpiredControls(null));
        $this->assertCount(0, $service->getConfiguredControls($workspaceTwoId));
    }

    private function createWorkspace(string $slugPrefix): int
    {
        $slug = $slugPrefix . '-' . bin2hex(random_bytes(4));
        Database::execute(
            "INSERT INTO workspaces (uuid, name, slug, status, plan_status, created_at, updated_at)
             VALUES (UUID(), ?, ?, 'active', 'active', NOW(), NOW())",
            ['Runtime Control Workspace', $slug]
        );
        $workspaceId = (int) Database::lastInsertId();
        WorkspaceContext::activateRuntimeWorkspace(1);

        return $workspaceId;
    }
}
