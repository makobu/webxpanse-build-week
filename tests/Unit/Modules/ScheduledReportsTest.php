<?php
/**
 * Scheduled Reports Module Tests
 */

namespace CRM\Tests\Unit\Modules;

use CRM\Tests\DatabaseTestCase;
use CRM\Modules\ScheduledReports;
use CRM\Modules\Reports;
use CRM\Database;

class ScheduledReportsTest extends DatabaseTestCase
{
    private ScheduledReports $scheduledReports;
    private Reports $reports;
    private int $testUserId;
    private int $otherUserId;
    private int $testReportId;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->scheduledReports = new ScheduledReports();
        $this->reports = new Reports();

        $this->testUserId = $this->createUser('schedule-owner@example.com');
        $this->otherUserId = $this->createUser('schedule-other@example.com');
        
        // Create test report
        $this->testReportId = $this->reports->create([
            'name' => 'Test Report',
            'description' => 'Test description',
            'report_type' => 'contacts',
            'query_config' => json_encode(['fields' => ['first_name', 'email']]),
            'created_by' => $this->testUserId
        ]);
    }
    
    protected function tearDown(): void
    {
        Database::execute("DELETE FROM scheduled_reports");
        Database::execute("DELETE FROM report_executions");
        Database::execute("DELETE FROM reports");
        Database::execute("DELETE FROM users");
        parent::tearDown();
    }
    
    public function testCreateScheduledReport(): void
    {
        $data = [
            'report_id' => $this->testReportId,
            'schedule_name' => 'Daily Report',
            'schedule_type' => 'daily',
            'schedule_config' => ['time' => '09:00'],
            'recipients' => ['test@example.com'],
            'format' => 'csv',
            'created_by' => $this->testUserId
        ];
        
        $id = $this->scheduledReports->create($data);
        
        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);
        
        // Verify it was created
        $schedule = $this->scheduledReports->getById($id);
        $this->assertEquals('Daily Report', $schedule['schedule_name']);
        $this->assertEquals('daily', $schedule['schedule_type']);
    }
    
    public function testCreateScheduledReportMissingRequiredFields(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Report ID, schedule name, and schedule type are required');
        
        $this->scheduledReports->create(['schedule_name' => 'Test']);
    }
    
    public function testCreateScheduledReportInvalidScheduleType(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid schedule type');
        
        $this->scheduledReports->create([
            'report_id' => $this->testReportId,
            'schedule_name' => 'Test',
            'schedule_type' => 'invalid'
        ]);
    }
    
    public function testGetById(): void
    {
        $id = $this->scheduledReports->create([
            'report_id' => $this->testReportId,
            'schedule_name' => 'Test Schedule',
            'schedule_type' => 'weekly',
            'created_by' => $this->testUserId
        ]);
        
        $schedule = $this->scheduledReports->getById($id);
        
        $this->assertIsArray($schedule);
        $this->assertEquals($id, $schedule['id']);
        $this->assertEquals('Test Schedule', $schedule['schedule_name']);
    }
    
    public function testGetByIdNotFound(): void
    {
        $schedule = $this->scheduledReports->getById(99999);
        $this->assertNull($schedule);
    }
    
    public function testUpdateScheduledReport(): void
    {
        $id = $this->scheduledReports->create([
            'report_id' => $this->testReportId,
            'schedule_name' => 'Original Name',
            'schedule_type' => 'daily',
            'created_by' => $this->testUserId
        ]);
        
        $result = $this->scheduledReports->update($id, [
            'schedule_name' => 'Updated Name',
            'schedule_type' => 'weekly'
        ]);
        
        $this->assertTrue($result);
        
        // Verify update
        $schedule = $this->scheduledReports->getById($id);
        $this->assertEquals('Updated Name', $schedule['schedule_name']);
        $this->assertEquals('weekly', $schedule['schedule_type']);
    }
    
    public function testDeleteScheduledReport(): void
    {
        $id = $this->scheduledReports->create([
            'report_id' => $this->testReportId,
            'schedule_name' => 'To Delete',
            'schedule_type' => 'daily',
            'created_by' => $this->testUserId
        ]);
        
        $result = $this->scheduledReports->delete($id);
        $this->assertTrue($result);
        
        // Verify deletion
        $schedule = $this->scheduledReports->getById($id);
        $this->assertNull($schedule);
    }
    
    public function testGetDueSchedules(): void
    {
        // Create a schedule that's due
        $scheduleId = $this->scheduledReports->create([
            'report_id' => $this->testReportId,
            'schedule_name' => 'Due Schedule',
            'schedule_type' => 'daily',
            'is_active' => 1,
            'created_by' => $this->testUserId
        ]);

        Database::execute(
            "UPDATE scheduled_reports SET next_run_at = ? WHERE id = ?",
            [date('Y-m-d H:i:s', strtotime('-1 hour')), $scheduleId]
        );
        
        $due = $this->scheduledReports->getDueSchedules();
        $this->assertIsArray($due);
        $this->assertGreaterThanOrEqual(1, count($due));
    }

    public function testGetOwnedByIdRejectsAnotherUsersSchedule(): void
    {
        $id = $this->scheduledReports->create([
            'report_id' => $this->testReportId,
            'schedule_name' => 'Private Schedule',
            'schedule_type' => 'daily',
            'created_by' => $this->testUserId,
        ]);

        $this->assertNull($this->scheduledReports->getOwnedById($id, $this->otherUserId));
    }

    public function testUpdateForUserRejectsAnotherUsersSchedule(): void
    {
        $id = $this->scheduledReports->create([
            'report_id' => $this->testReportId,
            'schedule_name' => 'Update Guard',
            'schedule_type' => 'daily',
            'created_by' => $this->testUserId,
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('You do not have permission to edit this scheduled report');

        $this->scheduledReports->updateForUser($id, $this->otherUserId, ['schedule_name' => 'Nope']);
    }

    public function testDeleteForUserRejectsAnotherUsersSchedule(): void
    {
        $id = $this->scheduledReports->create([
            'report_id' => $this->testReportId,
            'schedule_name' => 'Delete Guard',
            'schedule_type' => 'daily',
            'created_by' => $this->testUserId,
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('You do not have permission to delete this scheduled report');

        $this->scheduledReports->deleteForUser($id, $this->otherUserId);
    }

    public function testToggleActiveForUserRejectsAnotherUsersSchedule(): void
    {
        $id = $this->scheduledReports->create([
            'report_id' => $this->testReportId,
            'schedule_name' => 'Toggle Guard',
            'schedule_type' => 'daily',
            'created_by' => $this->testUserId,
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('You do not have permission to change this scheduled report');

        $this->scheduledReports->toggleActiveForUser($id, $this->otherUserId);
    }

    public function testUnavailableFormatIsRejectedWhenCreatingSchedule(): void
    {
        $reports = new Reports();
        $unavailableFormat = $this->firstUnavailableFormat($reports->getExportCapabilities());

        if ($unavailableFormat === null) {
            $this->markTestSkipped('All export formats are available in this environment.');
        }

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage($reports->getExportUnavailableMessage($unavailableFormat));

        $this->scheduledReports->create([
            'report_id' => $this->testReportId,
            'schedule_name' => 'Unavailable Format',
            'schedule_type' => 'daily',
            'format' => $unavailableFormat,
            'created_by' => $this->testUserId,
        ]);
    }

    public function testUnavailableFormatIsRejectedWhenUpdatingSchedule(): void
    {
        $reports = new Reports();
        $unavailableFormat = $this->firstUnavailableFormat($reports->getExportCapabilities());

        if ($unavailableFormat === null) {
            $this->markTestSkipped('All export formats are available in this environment.');
        }

        $id = $this->scheduledReports->create([
            'report_id' => $this->testReportId,
            'schedule_name' => 'Format Update Guard',
            'schedule_type' => 'daily',
            'format' => 'csv',
            'created_by' => $this->testUserId,
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage($reports->getExportUnavailableMessage($unavailableFormat));

        $this->scheduledReports->update($id, ['format' => $unavailableFormat]);
    }

    private function createUser(string $email): int
    {
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at)
             VALUES (?, ?, ?, 'user', NOW())",
            [uniqid('schedule-user-', true), $email, password_hash('password', PASSWORD_DEFAULT)]
        );

        return (int) Database::lastInsertId();
    }

    private function firstUnavailableFormat(array $capabilities): ?string
    {
        foreach (['pdf', 'excel'] as $format) {
            if (empty($capabilities[$format])) {
                return $format;
            }
        }

        return null;
    }
}
