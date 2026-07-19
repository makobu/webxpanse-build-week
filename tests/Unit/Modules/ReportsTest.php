<?php
/**
 * Reports Module Tests
 */

namespace CRM\Tests\Unit\Modules;

use CRM\Tests\DatabaseTestCase;
use CRM\Modules\Reports;
use CRM\Database;

class ReportsTest extends DatabaseTestCase
{
    private Reports $reports;
    private int $testUserId;
    private int $otherUserId;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->reports = new Reports();

        $this->testUserId = $this->createUser('report-owner@example.com');
        $this->otherUserId = $this->createUser('report-viewer@example.com');
    }

    protected function tearDown(): void
    {
        Database::execute("DELETE FROM report_executions");
        Database::execute("DELETE FROM reports");
        Database::execute("DELETE FROM users");
        parent::tearDown();
    }
    
    public function testCreateReport()
    {
        $id = $this->reports->create([
            'name' => 'Test Report',
            'description' => 'Test description',
            'report_type' => 'contacts',
            'query_config' => ['table' => 'contacts'],
            'created_by' => $this->testUserId
        ]);
        
        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);
        
        $report = Database::queryOne("SELECT * FROM reports WHERE id = ?", [$id]);
        $this->assertEquals('Test Report', $report['name']);
    }
    
    public function testCreateReportRequiresNameAndQueryConfig()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Report name and query configuration are required");
        
        $this->reports->create([]);
    }
    
    public function testGetReportById()
    {
        $id = $this->reports->create([
            'name' => 'Test Report',
            'query_config' => ['table' => 'contacts'],
            'created_by' => $this->testUserId
        ]);
        
        $report = $this->reports->getById($id);
        
        $this->assertIsArray($report);
        $this->assertEquals('Test Report', $report['name']);
        $this->assertIsArray($report['query_config']);
    }

    public function testPrivateReportIsNotViewableByAnotherUser(): void
    {
        $reportId = $this->reports->create([
            'name' => 'Private Report',
            'report_type' => 'contacts',
            'query_config' => ['fields' => ['id', 'email']],
            'created_by' => $this->testUserId,
            'is_public' => 0,
        ]);

        $this->assertNull($this->reports->getViewableById($reportId, $this->otherUserId));
        $this->assertNull($this->reports->getEditableById($reportId, $this->otherUserId));
    }

    public function testPublicReportIsViewableButNotEditableByAnotherUser(): void
    {
        $reportId = $this->reports->create([
            'name' => 'Public Report',
            'report_type' => 'contacts',
            'query_config' => ['fields' => ['id', 'email']],
            'created_by' => $this->testUserId,
            'is_public' => 1,
        ]);

        $report = $this->reports->getViewableById($reportId, $this->otherUserId);

        $this->assertIsArray($report);
        $this->assertSame($reportId, (int) $report['id']);
        $this->assertNull($this->reports->getEditableById($reportId, $this->otherUserId));
    }

    public function testExecuteForUserRejectsUnauthorizedViewer(): void
    {
        $reportId = $this->reports->create([
            'name' => 'Restricted Report',
            'report_type' => 'contacts',
            'query_config' => ['fields' => ['id', 'email']],
            'created_by' => $this->testUserId,
            'is_public' => 0,
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('You do not have permission to view this report');

        $this->reports->executeForUser($reportId, $this->otherUserId);
    }

    public function testUpdateForUserRejectsUnauthorizedEditor(): void
    {
        $reportId = $this->reports->create([
            'name' => 'Locked Report',
            'report_type' => 'contacts',
            'query_config' => ['fields' => ['id', 'email']],
            'created_by' => $this->testUserId,
            'is_public' => 1,
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('You do not have permission to edit this report');

        $this->reports->updateForUser($reportId, $this->otherUserId, ['name' => 'Hacked']);
    }

    public function testPrepareConfigurationDropsUnsupportedFieldsAndFilters(): void
    {
        $prepared = $this->reports->prepareConfiguration(
            'contacts',
            [
                'fields' => ['id', 'email', 'unknown_field'],
                'order_by' => 'not_allowed',
                'order_dir' => 'sideways',
                'limit' => '25',
            ],
            [
                'stage' => 'new',
                'search' => 'ignored',
                'date_from' => '2026-03-01',
            ]
        );

        $this->assertSame(['id', 'email'], $prepared['query_config']['fields']);
        $this->assertSame('created_at', $prepared['query_config']['order_by']);
        $this->assertSame('DESC', $prepared['query_config']['order_dir']);
        $this->assertSame(25, $prepared['query_config']['limit']);
        $this->assertSame('2026-03-01', $prepared['filters']['date_from']);
        $this->assertSame('new', $prepared['filters']['stage']);
        $this->assertArrayNotHasKey('search', $prepared['filters']);
    }

    public function testFormatDisplayValueFormatsIntegersFloatsDatesAndNulls(): void
    {
        $this->assertSame('42', $this->reports->formatDisplayValue('id', 42));
        $this->assertSame('1,234', $this->reports->formatDisplayValue('lead_count', '1234'));
        $this->assertSame('123.46', $this->reports->formatDisplayValue('conversion_rate', 123.456));
        $this->assertSame('', $this->reports->formatDisplayValue('email', null));
        $this->assertSame('Mar 31, 2026 9:15 AM', $this->reports->formatDisplayValue('created_at', '2026-03-31 09:15:00'));
        $this->assertSame('Plain Text', $this->reports->formatDisplayValue('description', 'Plain Text'));
    }

    public function testExportCapabilitiesAlwaysSupportCsv(): void
    {
        $capabilities = $this->reports->getExportCapabilities();

        $this->assertArrayHasKey('csv', $capabilities);
        $this->assertArrayHasKey('pdf', $capabilities);
        $this->assertArrayHasKey('excel', $capabilities);
        $this->assertTrue($capabilities['csv']);
        $this->assertSame('csv', $this->reports->normalizeExportFormat('invalid'));
    }

    private function createUser(string $email): int
    {
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at)
             VALUES (?, ?, ?, 'user', NOW())",
            [uniqid('report-user-', true), $email, password_hash('password', PASSWORD_DEFAULT)]
        );

        return (int) Database::lastInsertId();
    }
}
