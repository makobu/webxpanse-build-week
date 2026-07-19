<?php

namespace CRM\Tests\Unit\Modules;

use CRM\Authorization;
use CRM\Database;
use CRM\Modules\Targets;
use CRM\Tests\DatabaseTestCase;

class TargetsTest extends DatabaseTestCase
{
    private Targets $targets;
    private array $ownerUser;
    private array $viewerUser;
    private array $adminUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->targets = new Targets();

        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, 'sales', NOW())",
            [uniqid('target-owner-', true), 'target-owner@example.com', password_hash('password', PASSWORD_DEFAULT)]
        );
        $ownerId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, 'viewer', NOW())",
            [uniqid('target-viewer-', true), 'target-viewer@example.com', password_hash('password', PASSWORD_DEFAULT)]
        );
        $viewerId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, 'admin', NOW())",
            [uniqid('target-admin-', true), 'target-admin@example.com', password_hash('password', PASSWORD_DEFAULT)]
        );
        $adminId = (int) Database::lastInsertId();

        $adminRole = Database::queryOne("SELECT id FROM roles WHERE slug = 'admin' LIMIT 1");
        Authorization::assignUserRole($adminId, (int) ($adminRole['id'] ?? 0), $adminId);

        $this->ownerUser = ['id' => $ownerId, 'role' => 'sales'];
        $this->viewerUser = ['id' => $viewerId, 'role' => 'viewer'];
        $this->adminUser = ['id' => $adminId, 'role' => 'admin'];
    }

    public function testNonOwnerCanViewSharedTeamTarget(): void
    {
        $targetId = $this->targets->create([
            'title' => 'Shared Team Target',
            'description' => 'Shared target',
            'user_id' => (int) $this->ownerUser['id'],
            'scope' => 'team',
            'target_type' => 'custom',
            'target_value' => 10,
            'current_value' => 1,
            'target_date' => date('Y-m-d', strtotime('+14 days')),
        ]);

        $target = $this->targets->getById($targetId);

        $this->assertNotNull($target);
        $this->assertTrue($this->targets->canViewTarget($target, $this->viewerUser));
        $this->assertFalse($this->targets->canEditTarget($target, $this->viewerUser));
        $this->assertFalse($this->targets->canDeleteTarget($target, $this->viewerUser));
    }

    public function testOwnerCanEditAndDeleteOwnTarget(): void
    {
        $targetId = $this->targets->create([
            'title' => 'Owned Target',
            'description' => 'Owned target',
            'user_id' => (int) $this->ownerUser['id'],
            'scope' => 'personal',
            'target_type' => 'custom',
            'target_value' => 5,
            'current_value' => 2,
            'target_date' => date('Y-m-d', strtotime('+10 days')),
        ]);

        $target = $this->targets->getById($targetId);

        $this->assertNotNull($target);
        $this->assertTrue($this->targets->canViewTarget($target, $this->ownerUser));
        $this->assertTrue($this->targets->canEditTarget($target, $this->ownerUser));
        $this->assertTrue($this->targets->canDeleteTarget($target, $this->ownerUser));
    }

    public function testManageAllUserCanDeleteAnotherUsersTarget(): void
    {
        $targetId = $this->targets->create([
            'title' => 'Admin Managed Target',
            'description' => 'Admin can manage this target',
            'user_id' => (int) $this->ownerUser['id'],
            'scope' => 'personal',
            'target_type' => 'custom',
            'target_value' => 20,
            'current_value' => 4,
            'target_date' => date('Y-m-d', strtotime('+21 days')),
        ]);

        $target = $this->targets->getById($targetId);

        $this->assertNotNull($target);
        $this->assertTrue($this->targets->canViewTarget($target, $this->adminUser));
        $this->assertTrue($this->targets->canEditTarget($target, $this->adminUser));
        $this->assertTrue($this->targets->canDeleteTarget($target, $this->adminUser));
    }

    public function testCreateRejectsBlankTitleAfterSanitizing(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Target title is required');

        $this->targets->create([
            'title' => '   ',
            'user_id' => (int) $this->ownerUser['id'],
            'target_value' => 10,
            'target_date' => date('Y-m-d', strtotime('+7 days')),
        ]);
    }

    public function testCreateRejectsNonPositiveTargetValue(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Target value must be greater than zero');

        $this->targets->create([
            'title' => 'Impossible Target',
            'user_id' => (int) $this->ownerUser['id'],
            'target_value' => -10,
            'target_date' => date('Y-m-d', strtotime('+7 days')),
        ]);
    }

    public function testUpdateRejectsInvalidStatus(): void
    {
        $targetId = $this->targets->create([
            'title' => 'Status Target',
            'user_id' => (int) $this->ownerUser['id'],
            'target_value' => 10,
            'target_date' => date('Y-m-d', strtotime('+7 days')),
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid target status');

        $this->targets->update($targetId, ['status' => 'impossible']);
    }

    public function testUpdateRejectsTargetDateBeforeStartDate(): void
    {
        $startDate = date('Y-m-d');
        $targetDate = date('Y-m-d', strtotime('+7 days'));
        $targetId = $this->targets->create([
            'title' => 'Date Target',
            'user_id' => (int) $this->ownerUser['id'],
            'target_value' => 10,
            'start_date' => $startDate,
            'target_date' => $targetDate,
        ]);

        try {
            $this->targets->update($targetId, [
                'start_date' => date('Y-m-d', strtotime('+7 days')),
                'target_date' => date('Y-m-d'),
            ]);
            $this->fail('Expected invalid target date window to be rejected.');
        } catch (\Exception $e) {
            $this->assertSame('Target date cannot be before start date', $e->getMessage());
        }

        $target = $this->targets->getById($targetId);
        $this->assertSame($startDate, (string) ($target['start_date'] ?? ''));
        $this->assertSame($targetDate, (string) ($target['target_date'] ?? ''));
    }

    public function testUpdateRejectsNonNumericCurrentValueWithoutPersisting(): void
    {
        $targetId = $this->targets->create([
            'title' => 'Progress Target',
            'user_id' => (int) $this->ownerUser['id'],
            'target_value' => 10,
            'current_value' => 2,
            'target_date' => date('Y-m-d', strtotime('+7 days')),
        ]);

        try {
            $this->targets->update($targetId, ['current_value' => 'abc']);
            $this->fail('Expected non-numeric current value to be rejected.');
        } catch (\Exception $e) {
            $this->assertSame('Current value must be a valid number', $e->getMessage());
        }

        $target = $this->targets->getById($targetId);
        $this->assertSame(2.0, (float) ($target['current_value'] ?? 0));
    }

    public function testAutoRollupTargetRequiresValidRollupDefinition(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Auto-rollup targets require a valid rollup source and metric');

        $this->targets->create([
            'title' => 'Broken Rollup Target',
            'user_id' => (int) $this->ownerUser['id'],
            'target_value' => 10,
            'progress_mode' => 'auto_rollup',
            'target_date' => date('Y-m-d', strtotime('+7 days')),
        ]);
    }

    public function testAutoRollupTargetStoresNormalizedNativeRollupDefinition(): void
    {
        $targetId = $this->targets->create([
            'title' => 'Completed Tasks Rollup',
            'user_id' => (int) $this->ownerUser['id'],
            'target_value' => 10,
            'progress_mode' => 'auto_rollup',
            'target_date' => date('Y-m-d', strtotime('+7 days')),
            'metadata_json' => [
                'rollup_definition' => [
                    'source' => 'tasks',
                    'metric' => 'completed_count',
                ],
            ],
        ]);

        $target = Database::queryOne('SELECT progress_mode, metadata_json FROM targets WHERE id = ?', [$targetId]);
        $metadata = json_decode((string) ($target['metadata_json'] ?? ''), true);

        $this->assertSame('auto_rollup', (string) ($target['progress_mode'] ?? ''));
        $this->assertSame('tasks', (string) ($metadata['rollup_definition']['source'] ?? ''));
        $this->assertSame('completed_count', (string) ($metadata['rollup_definition']['metric'] ?? ''));
    }
}
