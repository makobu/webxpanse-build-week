<?php
/**
 * Companies Import/Export Integration Tests
 */

namespace CRM\Tests\Integration;

use CRM\Database;
use CRM\Modules\Companies;
use CRM\Tests\DatabaseTestCase;

class CompaniesImportExportTest extends DatabaseTestCase
{
    private Companies $companies;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->companies = new Companies();

        Database::execute(
            "INSERT INTO users (email, password_hash, role, created_at) VALUES (?, ?, 'admin', NOW())",
            ['account.owner@example.com', password_hash('password', PASSWORD_DEFAULT)]
        );
        $this->userId = (int) Database::lastInsertId();
    }

    public function testImportRowsCreatesUpdatesAndReportsErrors(): void
    {
        $columnMap = $this->companies->mapImportHeaders([
            'Company Name',
            'Website',
            'Phone',
            'Industry',
            'Size',
            'Address',
            'Assigned To',
        ]);

        $rows = [
            2 => ['Northwind', 'https://northwind.test', '+123456789', 'Software', '11-50', 'Nairobi', 'account.owner@example.com'],
            3 => ['northwind', 'https://northwind-updated.test', '', 'SaaS', '', '', (string) $this->userId],
            4 => ['', 'https://invalid.test', '', '', '', '', ''],
        ];

        $results = $this->companies->importRows($rows, $columnMap);

        $this->assertSame(3, $results['total']);
        $this->assertSame(1, $results['imported']);
        $this->assertSame(1, $results['updated']);
        $this->assertSame(1, $results['skipped']);
        $this->assertCount(1, $results['errors']);

        $company = Database::queryOne("SELECT * FROM companies WHERE name = ?", ['Northwind']);
        $this->assertNotNull($company);
        $this->assertSame('https://northwind-updated.test', $company['website']);
        $this->assertSame('SaaS', $company['industry']);
        $this->assertSame($this->userId, (int) $company['assigned_to']);
    }

    public function testExportReturnsRoundTripFieldsAndSupportsFilters(): void
    {
        $firstId = $this->companies->create([
            'name' => 'Acme Export',
            'website' => 'https://acme-export.test',
            'industry' => 'Manufacturing',
            'assigned_to' => $this->userId,
        ]);

        $secondId = $this->companies->create([
            'name' => 'Bravo Labs',
            'website' => 'https://bravo.test',
            'industry' => 'Biotech',
        ]);

        $allRows = $this->companies->export();
        $this->assertNotEmpty($allRows);

        $row = null;
        foreach ($allRows as $candidate) {
            if ((int) $candidate['id'] === $firstId) {
                $row = $candidate;
                break;
            }
        }

        $this->assertNotNull($row);
        $this->assertSame('Acme Export', $row['name']);
        $this->assertSame('account.owner@example.com', $row['assigned_to_email']);
        $this->assertArrayHasKey('uuid', $row);
        $this->assertArrayHasKey('created_at', $row);
        $this->assertArrayHasKey('updated_at', $row);

        $filtered = $this->companies->export([
            'search' => 'Acme',
            'assigned_to' => $this->userId,
        ]);

        $this->assertCount(1, $filtered);
        $this->assertSame($firstId, (int) $filtered[0]['id']);

        $assignedToOther = $this->companies->export([
            'assigned_to' => $this->userId + 999,
        ]);

        $this->assertSame([], $assignedToOther);

        $this->assertNotSame($firstId, $secondId);
    }
}
