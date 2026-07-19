<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Modules\Targets;
use CRM\Services\TargetCoordinator;
use CRM\Services\TargetCreationPolicy;
use CRM\Services\TargetIntelligenceScanQueueService;
use CRM\Services\TargetIntelligenceService;
use CRM\Services\TargetMeasurementCollectorRegistry;
use CRM\Services\WorkspaceTargetAutomationSettingsService;
use CRM\Tests\DatabaseTestCase;

class TargetsV2Test extends DatabaseTestCase
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        Database::execute("INSERT INTO users (uuid,email,password_hash,role,created_at) VALUES (?,?,?,'sales',NOW())", [uniqid('target-v2-', true), uniqid('target-v2-') . '@example.com', password_hash('password', PASSWORD_DEFAULT)]);
        $this->userId = (int) Database::lastInsertId();
        Database::execute("INSERT IGNORE INTO workspace_memberships (workspace_id,user_id,role_slug,membership_status,is_owner,joined_at) VALUES (1,?,'owner','active',1,NOW())", [$this->userId]);
        $_SESSION['user_id'] = $this->userId;
    }

    public function testCollectorIsWorkspaceAndTargetPeriodScoped(): void
    {
        $workspaceTwo = $this->workspace('Target V2 Other');
        $inside = date('Y-m-d H:i:s', strtotime('-1 day'));
        $outside = date('Y-m-d H:i:s', strtotime('-30 days'));
        $this->deal(1, 'Included', $inside, 'USD');
        $this->deal(1, 'Too old', $outside, 'USD');
        $this->deal($workspaceTwo, 'Other workspace', $inside, 'USD');

        $target = $this->targetArray(['start_date' => date('Y-m-d', strtotime('-7 days')), 'target_date' => date('Y-m-d', strtotime('+7 days'))]);
        $result = (new TargetMeasurementCollectorRegistry())->collect($target, ['source' => 'deals', 'metric' => 'closed_won_count']);

        $this->assertSame(1.0, $result['value']);
        $this->assertCount(1, $result['evidence']);
        $this->assertSame('Included', $result['evidence'][0]['label']);
    }

    public function testMonetaryCollectorFiltersCurrencyAndReportsConflict(): void
    {
        $when = date('Y-m-d H:i:s');
        $this->deal(1, 'USD deal', $when, 'USD', 100);
        $this->deal(1, 'KES deal', $when, 'KES', 200);
        $target = $this->targetArray(['currency_code' => 'USD', 'rollup_metric' => 'won_value']);

        $result = (new TargetMeasurementCollectorRegistry())->collect($target, ['source' => 'deals', 'metric' => 'won_value']);

        $this->assertSame(100.0, $result['value']);
        $this->assertNotEmpty($result['conflicts']);
    }

    public function testHybridSetTotalDerivesAdjustment(): void
    {
        for ($i = 0; $i < 5; $i++) {
            Database::execute("INSERT INTO tasks (workspace_id,title,created_by,assigned_to,status,completed_at,created_at) VALUES (1,?,?,?,'completed',NOW(),NOW())", ['Completed ' . $i, $this->userId, $this->userId]);
        }
        $targetId = $this->createTarget(['progress_mode' => 'hybrid', 'rollup_source' => 'tasks', 'rollup_metric' => 'completed_count', 'target_value' => 10]);

        (new Targets())->updateProgress($targetId, 7);
        $row = Database::queryOne('SELECT current_value,manual_adjustment_value FROM targets WHERE workspace_id=1 AND id=?', [$targetId]);

        $this->assertSame(7.0, (float) $row['current_value']);
        $this->assertSame(2.0, (float) $row['manual_adjustment_value']);
    }

    public function testReviewModeRecommendsWithoutCompleting(): void
    {
        Database::execute("INSERT INTO tasks (workspace_id,title,created_by,assigned_to,status,completed_at,created_at) VALUES (1,'Done',?,?, 'completed',NOW(),NOW())", [$this->userId, $this->userId]);
        $targetId = $this->createTarget(['progress_mode' => 'auto_rollup', 'automation_mode' => 'auto', 'rollup_source' => 'tasks', 'rollup_metric' => 'completed_count', 'target_value' => 1]);

        (new TargetIntelligenceService())->syncTarget($targetId, 1);
        $target = Database::queryOne('SELECT status FROM targets WHERE workspace_id=1 AND id=?', [$targetId]);
        $proposal = Database::queryOne("SELECT id FROM target_automation_proposals WHERE workspace_id=1 AND target_id=? AND decision_state='pending'", [$targetId]);

        $this->assertSame('active', $target['status']);
        $this->assertNotNull($proposal);
    }

    public function testFullAutoCompletesAndRejectedEvidenceCannotRepeat(): void
    {
        (new WorkspaceTargetAutomationSettingsService())->save(1, ['mode' => 'full_auto'], $this->userId);
        Database::execute("INSERT INTO tasks (workspace_id,title,created_by,assigned_to,status,completed_at,created_at) VALUES (1,'Done',?,?, 'completed',NOW(),NOW())", [$this->userId, $this->userId]);
        $targetId = $this->createTarget(['progress_mode' => 'auto_rollup', 'automation_mode' => 'auto', 'rollup_source' => 'tasks', 'rollup_metric' => 'completed_count', 'target_value' => 1]);
        $service = new TargetIntelligenceService();
        $service->syncTarget($targetId, 1);
        $this->assertSame('completed', Database::queryOne('SELECT status FROM targets WHERE id=?', [$targetId])['status']);
        $evidenceRows = Database::query('SELECT id,evidence_fingerprint,decision_state FROM target_evidence WHERE target_id=?', [$targetId]);
        $this->assertNotNull(Database::queryOne("SELECT id FROM target_evidence WHERE target_id=? AND decision_state='accepted'", [$targetId]), json_encode($evidenceRows));

        $this->assertTrue((new TargetCoordinator())->reopen($targetId, 1, $this->userId, 'Evidence was not sufficient.'));
        $this->assertSame('active', Database::queryOne('SELECT status FROM targets WHERE id=?', [$targetId])['status']);
        $this->assertNotNull(Database::queryOne("SELECT id FROM target_evidence WHERE target_id=? AND decision_state='rejected'", [$targetId]));
        $service->syncTarget($targetId, 1);

        $this->assertSame('active', Database::queryOne('SELECT status FROM targets WHERE id=?', [$targetId])['status']);
        $this->assertNotNull(Database::queryOne("SELECT id FROM target_evidence WHERE target_id=? AND decision_state='rejected'", [$targetId]));
    }

    public function testAutomatedCreationIsDeduplicated(): void
    {
        $policy = new TargetCreationPolicy();
        $data = ['title' => 'Starter target', 'user_id' => $this->userId, 'target_value' => 1, 'target_date' => date('Y-m-d', strtotime('+7 days'))];
        $source = ['surface' => 'workflow', 'run_id' => 'run-1', 'dedupe_key' => 'workflow:run-1:target-1'];

        $first = $policy->createAutomated($data, $source);
        $second = $policy->createAutomated($data, $source);

        $this->assertSame($first, $second);
        $this->assertSame(1, (int) Database::queryOne("SELECT COUNT(*) AS count FROM targets WHERE automation_dedupe_key='workflow:run-1:target-1'")['count']);
    }

    public function testMilestonesCascadeWhenTargetIsDeleted(): void
    {
        $targetId = $this->createTarget();
        Database::execute("INSERT INTO target_milestones (workspace_id,target_id,title,target_value) VALUES (1,?,'First',1)", [$targetId]);
        (new Targets())->delete($targetId);
        $this->assertSame(0, (int) Database::queryOne('SELECT COUNT(*) AS count FROM target_milestones WHERE target_id=?', [$targetId])['count']);
    }

    public function testCursorQueueProcessesMoreThanTwoHundredTargets(): void
    {
        for ($i = 0; $i < 205; $i++) {
            Database::execute("INSERT INTO targets (workspace_id,user_id,title,target_value,current_value,start_date,target_date,status,progress_mode) VALUES (1,?,?,1,0,CURDATE(),DATE_ADD(CURDATE(),INTERVAL 7 DAY),'active','manual')", [$this->userId, 'Queued ' . $i]);
        }
        $queue = new TargetIntelligenceScanQueueService();
        $jobId = $queue->enqueueReconciliation(1);
        do {
            $result = $queue->processNext();
        } while (!empty($result['continuation']));
        $job = Database::queryOne('SELECT state,cursor_target_id,continuation_count FROM target_intelligence_scan_queue WHERE id=?', [$jobId]);
        $this->assertSame('completed', $job['state']);
        $this->assertGreaterThanOrEqual(205, (int) $job['cursor_target_id']);
        $this->assertGreaterThanOrEqual(2, (int) $job['continuation_count']);
    }

    private function createTarget(array $overrides = []): int
    {
        return (new Targets())->create($overrides + [
            'title' => 'Target V2', 'user_id' => $this->userId, 'target_value' => 10,
            'target_date' => date('Y-m-d', strtotime('+7 days')), 'scope' => 'personal',
        ]);
    }

    private function targetArray(array $overrides = []): array
    {
        return $overrides + ['id' => 9001, 'workspace_id' => 1, 'user_id' => $this->userId, 'scope' => 'personal',
            'rollup_source' => 'deals', 'rollup_metric' => 'closed_won_count', 'rollup_window' => 'target_period',
            'start_date' => date('Y-m-d', strtotime('-7 days')), 'target_date' => date('Y-m-d', strtotime('+7 days'))];
    }

    private function deal(int $workspaceId, string $title, string $when, string $currency, float $value = 10): void
    {
        Database::execute("INSERT INTO deals (workspace_id,title,created_by,assigned_to,stage,value,currency,actual_close_date,created_at,updated_at) VALUES (?,?,?,?,'closed_won',?,?,DATE(?),?,?)",
            [$workspaceId,$title,$this->userId,$this->userId,$value,$currency,$when,$when,$when]);
    }

    private function workspace(string $name): int
    {
        Database::execute('INSERT INTO workspaces (uuid,name,slug,status,created_by) VALUES (?,?,?,\'active\',?)', [uniqid('workspace-', true), $name, uniqid('target-v2-'), $this->userId]);
        return (int) Database::lastInsertId();
    }
}
