<?php
/**
 * Companies Module Tests
 */

namespace CRM\Tests\Unit\Modules;

use CRM\Database;
use CRM\Modules\Companies;
use CRM\Tests\DatabaseTestCase;

class CompaniesTest extends DatabaseTestCase
{
    private Companies $companies;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->companies = new Companies();

        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, 'admin', NOW())",
            [uniqid('user_', true), 'owner@example.com', password_hash('password', PASSWORD_DEFAULT)]
        );
        $this->userId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, joined_at)
             VALUES (1, ?, 'admin', 'active', NOW())",
            [$this->userId]
        );
    }

    public function testMapImportHeadersRecognizesAliases(): void
    {
        $columnMap = $this->companies->mapImportHeaders([
            'Company Name',
            'Web',
            'Telephone',
            'Sector',
            'Employees',
            'Company Address',
            'Assigned Email',
        ]);

        $this->assertSame(0, $columnMap['name']);
        $this->assertSame(1, $columnMap['website']);
        $this->assertSame(2, $columnMap['phone']);
        $this->assertSame(3, $columnMap['industry']);
        $this->assertSame(4, $columnMap['size']);
        $this->assertSame(5, $columnMap['address']);
        $this->assertSame(6, $columnMap['assigned_to']);
    }

    public function testResolveAssignedToSupportsEmailAndNumericId(): void
    {
        $this->assertSame($this->userId, $this->companies->resolveAssignedTo('owner@example.com'));
        $this->assertSame($this->userId, $this->companies->resolveAssignedTo((string) $this->userId));
        $this->assertNull($this->companies->resolveAssignedTo(''));
    }

    public function testResolveAssignedToRejectsUserOutsideActiveWorkspace(): void
    {
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, 'user', NOW())",
            [uniqid('user_', true), 'foreign@example.com', password_hash('password', PASSWORD_DEFAULT)]
        );
        $foreignUserId = (int) Database::lastInsertId();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not a member of the active workspace');
        $this->companies->resolveAssignedTo((string) $foreignUserId);
    }

    public function testCreateRejectsUnsafeWebsiteScheme(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must use HTTP or HTTPS');
        $this->companies->create([
            'name' => 'Unsafe Website Co',
            'website' => 'javascript:alert(1)',
        ]);
    }

    public function testCreateRejectsPrimaryContactBeforeCompanyLinkExists(): void
    {
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, email, created_at) VALUES (1, ?, ?, ?, NOW())",
            [uniqid('contact_', true), 'Unlinked', 'unlinked@example.com']
        );
        $contactId = (int) Database::lastInsertId();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('can only be selected after the contact is linked');
        $this->companies->create([
            'name' => 'Forged Primary Co',
            'primary_contact_id' => $contactId,
        ]);
    }

    public function testImportRowsCreatesAndUpdatesCompaniesByNormalizedName(): void
    {
        $columnMap = $this->companies->mapImportHeaders(['Name', 'Website', 'Assigned To']);
        $rows = [
            2 => ['Acme Inc', 'https://acme.test', 'owner@example.com'],
            3 => ['  acme   inc  ', 'https://acme-updated.test', (string) $this->userId],
        ];

        $results = $this->companies->importRows($rows, $columnMap);

        $this->assertSame(2, $results['total']);
        $this->assertSame(1, $results['imported']);
        $this->assertSame(1, $results['updated']);
        $this->assertSame(0, $results['skipped']);

        $companies = Database::query("SELECT * FROM companies WHERE workspace_id = 1");
        $this->assertCount(1, $companies);
        $company = $companies[0];
        $this->assertSame('https://acme-updated.test', $company['website']);
        $this->assertSame($this->userId, (int) $company['assigned_to']);
    }

    public function testImportRowsSkipsMissingRequiredName(): void
    {
        $columnMap = $this->companies->mapImportHeaders(['Name', 'Website']);
        $results = $this->companies->importRows([
            2 => ['', 'https://missing-name.test'],
        ], $columnMap);

        $this->assertSame(1, $results['total']);
        $this->assertSame(0, $results['imported']);
        $this->assertSame(0, $results['updated']);
        $this->assertSame(1, $results['skipped']);
        $this->assertCount(1, $results['errors']);
        $this->assertStringContainsString('Company name is required', $results['errors'][0]);
    }

    public function testAssignPrimaryContactRequiresLinkedContact(): void
    {
        $companyId = $this->companies->create(['name' => 'Primary Co']);

        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, email, company, company_id, created_at) VALUES (1, ?, ?, ?, ?, ?, NOW())",
            [uniqid('contact_', true), 'Primary', 'primary@example.com', 'Primary Co', $companyId]
        );
        $contactId = (int) Database::lastInsertId();

        $result = $this->companies->assignPrimaryContact($companyId, $contactId);
        $this->assertTrue($result);

        $company = $this->companies->getById($companyId);
        $this->assertSame($contactId, (int) $company['primary_contact_id']);
    }

    public function testDeleteUnlinksRelatedContactsAndDealsBeforeRemovingCompany(): void
    {
        $companyId = $this->companies->create(['name' => 'Delete Me Co']);

        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, email, company, company_id, created_at) VALUES (1, ?, ?, ?, ?, ?, NOW())",
            [uniqid('contact_', true), 'Delete', 'delete.contact@example.com', 'Delete Me Co', $companyId]
        );
        $contactId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO deals (workspace_id, title, value, stage, company_id, created_by, created_at) VALUES (1, ?, ?, ?, ?, ?, NOW())",
            ['Delete Me Deal', 1250, 'lead', $companyId, $this->userId]
        );
        $dealId = (int) Database::lastInsertId();

        $this->assertTrue($this->companies->delete($companyId));
        $this->assertNull($this->companies->getById($companyId));

        $contact = Database::queryOne("SELECT company_id FROM contacts WHERE id = ?", [$contactId]);
        $deal = Database::queryOne("SELECT company_id FROM deals WHERE id = ?", [$dealId]);

        $this->assertNotNull($contact);
        $this->assertNotNull($deal);
        $this->assertNull($contact['company_id']);
        $this->assertNull($deal['company_id']);
    }

    public function testDeleteReturnsFalseForMissingCompany(): void
    {
        $this->assertFalse($this->companies->delete(999999));
    }

    public function testCompanyEnrichmentGracefullyReturnsNoResultWithoutProviderKey(): void
    {
        $companyId = $this->companies->create([
            'name' => 'No Provider Co',
            'phone' => '+1234567890',
        ]);

        $previousHunterKey = $_ENV['HUNTER_API_KEY'] ?? null;
        $_ENV['HUNTER_API_KEY'] = '';

        try {
            $result = $this->companies->enrichCompanyAndDiscoverContacts($companyId);
        } finally {
            if ($previousHunterKey === null) {
                unset($_ENV['HUNTER_API_KEY']);
            } else {
                $_ENV['HUNTER_API_KEY'] = $previousHunterKey;
            }
        }

        $this->assertSame('success', $result['status']);
        $this->assertFalse($result['company_updated']);
        $this->assertSame(0, $result['contacts_created']);
        $this->assertSame(0, $result['contacts_linked']);
    }

    public function testGetByIdReturnsNullForAnotherWorkspace(): void
    {
        Database::execute(
            "INSERT INTO workspaces (id, uuid, name, slug, status, plan_status, created_at, updated_at)
             VALUES (2, ?, 'Other Workspace', 'other-workspace', 'active', 'inactive', NOW(), NOW())",
            ['00000000-0000-4000-8000-000000000002']
        );

        Database::execute(
            "INSERT INTO companies (workspace_id, name, created_at, updated_at)
             VALUES (2, 'Outside Co', NOW(), NOW())"
        );
        $foreignCompanyId = (int) Database::lastInsertId();

        $this->assertNull($this->companies->getById($foreignCompanyId));
    }
}
