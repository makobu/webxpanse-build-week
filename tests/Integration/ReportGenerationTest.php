<?php
/**
 * Report Generation Integration Tests
 */

namespace CRM\Tests\Integration;

use CRM\Tests\DatabaseTestCase;
use CRM\Modules\Reports;
use CRM\Modules\Contacts;
use CRM\Modules\Activities;
use CRM\Database;

class ReportGenerationTest extends DatabaseTestCase
{
    private Reports $reports;
    private Contacts $contacts;
    private Activities $activities;
    private int $testUserId;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->reports = new Reports();
        $this->contacts = new Contacts();
        $this->activities = new Activities();
        
        // Create test user
        Database::execute(
            "INSERT INTO users (email, password_hash, role, created_at) 
             VALUES (?, ?, 'user', NOW())",
            ['report@example.com', password_hash('password', PASSWORD_DEFAULT)]
        );
        $this->testUserId = (int) Database::lastInsertId();
        
        // Create test data
        $this->createTestData();
    }
    
    protected function tearDown(): void
    {
        // Clean up
        Database::execute("DELETE FROM reports WHERE created_by = ?", [$this->testUserId]);
        Database::execute("DELETE FROM contacts WHERE email LIKE 'report%@example.com'");
        Database::execute("DELETE FROM activities WHERE contact_id IN (SELECT id FROM contacts WHERE email LIKE 'report%@example.com')");
        Database::execute("DELETE FROM users WHERE id = ?", [$this->testUserId]);
        parent::tearDown();
    }
    
    private function createTestData(): void
    {
        // Create test contacts
        for ($i = 1; $i <= 5; $i++) {
            $this->contacts->create([
                'first_name' => "Report{$i}",
                'last_name' => 'Test',
                'email' => "report{$i}@example.com",
                'stage' => $i % 2 === 0 ? 'new' : 'contacted',
                'lead_source' => $i <= 3 ? 'website' : 'referral'
            ]);
        }
    }
    
    public function testCreateAndExecuteContactReport(): void
    {
        // 1. Create report
        $reportId = $this->reports->create([
            'name' => 'Contact Report',
            'description' => 'Test contact report',
            'report_type' => 'contacts',
            'query_config' => [
                'fields' => ['first_name', 'last_name', 'email', 'stage'],
                'filters' => ['stage' => 'new']
            ],
            'created_by' => $this->testUserId
        ]);
        
        $this->assertIsInt($reportId);
        $this->assertGreaterThan(0, $reportId);
        
        // 2. Execute report
        $results = $this->reports->execute($reportId);
        
        $this->assertIsArray($results);
        $this->assertArrayHasKey('data', $results);
        $this->assertGreaterThan(0, count($results['data']));
        
        // 3. Verify results match filters
        foreach ($results['data'] as $row) {
            $this->assertEquals('new', $row['stage']);
        }
    }
    
    public function testReportWithChartConfiguration(): void
    {
        // 1. Create report with chart
        $reportId = $this->reports->create([
            'name' => 'Chart Report',
            'report_type' => 'contacts',
            'query_config' => [
                'fields' => ['stage', 'COUNT(*) as count'],
                'group_by' => ['stage']
            ],
            'chart_config' => [
                'type' => 'bar',
                'x_axis' => 'stage',
                'y_axis' => 'count'
            ],
            'created_by' => $this->testUserId
        ]);
        
        // 2. Execute report
        $results = $this->reports->execute($reportId);
        
        $this->assertIsArray($results);
        $this->assertArrayHasKey('data', $results);
        $this->assertArrayHasKey('chart', $results);
    }
    
    public function testReportExportToCSV(): void
    {
        // 1. Create report
        $reportId = $this->reports->create([
            'name' => 'Export Report',
            'report_type' => 'contacts',
            'query_config' => [
                'fields' => ['first_name', 'email', 'stage']
            ],
            'created_by' => $this->testUserId
        ]);
        
        // 2. Export to CSV
        $csv = $this->reports->exportToCSV($reportId);
        
        $this->assertIsString($csv);
        $this->assertStringContainsString('first_name', $csv);
        $this->assertStringContainsString('email', $csv);
        $this->assertStringContainsString('stage', $csv);
    }
    
    public function testReportWithDateFilters(): void
    {
        // 1. Create report with date filter
        $reportId = $this->reports->create([
            'name' => 'Date Filter Report',
            'report_type' => 'contacts',
            'query_config' => [
                'fields' => ['first_name', 'email', 'created_at']
            ],
            'filters' => [
                'date_from' => date('Y-m-d', strtotime('-7 days')),
                'date_to' => date('Y-m-d')
            ],
            'created_by' => $this->testUserId
        ]);
        
        // 2. Execute report
        $results = $this->reports->execute($reportId);
        
        $this->assertIsArray($results);
        $this->assertArrayHasKey('data', $results);
        
        // Verify dates are within range
        foreach ($results['data'] as $row) {
            if (isset($row['created_at'])) {
                $createdDate = date('Y-m-d', strtotime($row['created_at']));
                $this->assertGreaterThanOrEqual(date('Y-m-d', strtotime('-7 days')), $createdDate);
                $this->assertLessThanOrEqual(date('Y-m-d'), $createdDate);
            }
        }
    }
    
    public function testReportSharing(): void
    {
        // 1. Create public report
        $publicReportId = $this->reports->create([
            'name' => 'Public Report',
            'report_type' => 'contacts',
            'query_config' => ['fields' => ['first_name', 'email']],
            'is_public' => 1,
            'created_by' => $this->testUserId
        ]);
        
        // 2. Create private report
        $privateReportId = $this->reports->create([
            'name' => 'Private Report',
            'report_type' => 'contacts',
            'query_config' => ['fields' => ['first_name', 'email']],
            'is_public' => 0,
            'created_by' => $this->testUserId
        ]);
        
        // 3. Verify public report is accessible
        $publicReport = $this->reports->getById($publicReportId);
        $this->assertEquals(1, $publicReport['is_public']);
        
        // 4. Verify private report is not public
        $privateReport = $this->reports->getById($privateReportId);
        $this->assertEquals(0, $privateReport['is_public']);
    }
}
