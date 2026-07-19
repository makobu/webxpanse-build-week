<?php
/**
 * Contact intelligence service tests
 */

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Modules\Contacts;
use CRM\Modules\Notes;
use CRM\Services\ContactIntelligenceService;
use CRM\Tests\DatabaseTestCase;

class ContactIntelligenceServiceTest extends DatabaseTestCase
{
    private Contacts $contacts;
    private Notes $notes;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->contacts = new Contacts();
        $this->notes = new Notes();

        Database::execute(
            "INSERT INTO users (email, password_hash, role, created_at) VALUES (?, ?, 'admin', NOW())",
            ['contact-intelligence@example.com', password_hash('password', PASSWORD_DEFAULT)]
        );
        $this->userId = (int) Database::lastInsertId();
    }

    public function testBuildsUnifiedTimelineAndOperationalContext(): void
    {
        $primary = $this->contacts->create([
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'email' => 'jane@starter.example.com',
            'company' => 'Starter Co',
            'job_title' => 'Founder',
        ]);
        $related = $this->contacts->create([
            'first_name' => 'Alex',
            'last_name' => 'Ops',
            'email' => 'alex@starter.example.com',
            'company' => 'Starter Co',
            'job_title' => 'Operations Lead',
            'phone' => '+254700000001',
        ]);

        $contactId = (int) $primary['id'];
        $relatedId = (int) $related['id'];

        $this->notes->create([
            'entity_type' => 'contact',
            'entity_id' => $contactId,
            'title' => 'Discovery recap',
            'content' => 'Budget sensitivity came up early.',
            'created_by' => $this->userId,
        ]);

        Database::execute(
            "INSERT INTO communications (contact_id, channel, direction, subject, body, created_at)
             VALUES (?, 'email', 'inbound', 'Pricing questions', 'Can we start smaller first?', NOW())",
            [$contactId]
        );

        Database::execute(
            "INSERT INTO deals (contact_id, title, stage, value, created_by, created_at)
             VALUES (?, 'Starter rollout', 'proposal', 15000, ?, NOW())",
            [$contactId, $this->userId]
        );

        Database::execute(
            "INSERT INTO tasks (contact_id, title, status, priority, due_date, created_by, created_at)
             VALUES (?, 'Send final pricing', 'open', 'high', DATE_ADD(CURDATE(), INTERVAL 1 DAY), ?, NOW())",
            [$contactId, $this->userId]
        );

        Database::execute(
            "INSERT INTO deals (contact_id, title, stage, value, created_by, created_at)
             VALUES (?, 'Operations expansion', 'qualified', 4000, ?, NOW())",
            [$relatedId, $this->userId]
        );

        $service = new ContactIntelligenceService();

        $timeline = $service->buildUnifiedTimeline($contactId, 20);
        $intelligence = $service->computeAndPersist($contactId);

        $this->assertNotEmpty($timeline);
        $this->assertContains('email', array_column($timeline, 'type'));
        $this->assertContains('note', array_column($timeline, 'type'));
        $this->assertContains('deal', array_column($timeline, 'type'));
        $this->assertContains('task', array_column($timeline, 'type'));

        $this->assertIsArray($intelligence);
        $this->assertArrayHasKey('relationship_summary', $intelligence);
        $this->assertArrayHasKey('account_context', $intelligence);
        $this->assertArrayHasKey('data_quality', $intelligence);
        $this->assertArrayHasKey('generated_at', $intelligence);

        $this->assertNotEmpty($intelligence['relationship_summary']['last_note'] ?? null);
        $this->assertSame('Starter Co', $intelligence['account_context']['company_name'] ?? '');
        $this->assertNotEmpty($intelligence['account_context']['related_contacts'] ?? []);
        $this->assertNotEmpty($intelligence['account_context']['shared_deals'] ?? []);
        $this->assertContains('phone', $intelligence['data_quality']['missing_critical_fields'] ?? []);
        $this->assertContains('location', $intelligence['data_quality']['missing_critical_fields'] ?? []);

        $stored = $service->getStoredOrCompute($contactId);
        $this->assertIsArray($stored);
        $this->assertSame(
            'Starter Co',
            $stored['account_context']['company_name'] ?? ''
        );

        $this->contacts->delete($contactId);
        $this->contacts->delete($relatedId);
    }

    public function testPublicEmailProviderDomainIsExcludedFromAccountContext(): void
    {
        $primary = $this->contacts->create([
            'first_name' => 'Jane',
            'last_name' => 'Personal',
            'email' => 'jane.personal@gmail.com',
        ]);
        $related = $this->contacts->create([
            'first_name' => 'Alex',
            'last_name' => 'Personal',
            'email' => 'alex.personal@gmail.com',
        ]);

        $service = new ContactIntelligenceService();
        $intelligence = $service->computeAndPersist((int) $primary['id']);

        $this->assertIsArray($intelligence);
        $this->assertSame('', (string) ($intelligence['account_context']['email_domain'] ?? ''));
        $this->assertSame([], $intelligence['account_context']['related_contacts'] ?? []);
        $this->assertSame([], $intelligence['account_context']['shared_deals'] ?? []);
        $this->assertSame([], $intelligence['account_context']['shared_tasks'] ?? []);
        $this->assertSame([], $intelligence['account_context']['shared_invoices'] ?? []);

        $this->contacts->delete((int) $primary['id']);
        $this->contacts->delete((int) $related['id']);
    }

    public function testNewContactWithoutEngagementEvidenceIsNotStaleOrNonResponsive(): void
    {
        $contact = $this->contacts->create([
            'first_name' => 'Fresh',
            'last_name' => 'Lead',
            'email' => 'fresh.lead@example.test',
            'phone' => '+254700000101',
            'company' => 'Fresh Co',
            'job_title' => 'Founder',
            'location' => 'Nairobi',
        ]);

        $service = new ContactIntelligenceService();
        $intelligence = $service->computeAndPersist((int) $contact['id']);

        $riskFlags = $intelligence['relationship_health']['risk_flags'] ?? [];
        $this->assertNotContains('No response in 21 days', $riskFlags);
        $this->assertNotContains('Contact is stale', $riskFlags);
        $this->assertFalse((bool) ($intelligence['data_quality']['is_stale'] ?? true));
        $this->assertFalse((bool) ($intelligence['data_quality']['has_engagement_evidence'] ?? true));
        $this->assertNull($intelligence['data_quality']['stale_days'] ?? null);
        $this->assertContains(
            'Log the first outreach or create the first next step for this contact.',
            $intelligence['next_best_actions'] ?? []
        );

        $this->contacts->delete((int) $contact['id']);
    }

    public function testOldContactWithoutEngagementEvidenceIsNotStaleOrNonResponsive(): void
    {
        $contact = $this->contacts->create([
            'first_name' => 'Old',
            'last_name' => 'Quiet',
            'email' => 'old.quiet@example.test',
            'phone' => '+254700000102',
            'company' => 'Quiet Co',
            'job_title' => 'Owner',
            'location' => 'Nairobi',
        ]);
        Database::execute(
            "UPDATE contacts SET created_at = DATE_SUB(NOW(), INTERVAL 90 DAY), updated_at = DATE_SUB(NOW(), INTERVAL 90 DAY) WHERE id = ?",
            [(int) $contact['id']]
        );

        $service = new ContactIntelligenceService();
        $intelligence = $service->computeAndPersist((int) $contact['id']);

        $riskFlags = $intelligence['relationship_health']['risk_flags'] ?? [];
        $this->assertNotContains('No response in 21 days', $riskFlags);
        $this->assertNotContains('Contact is stale', $riskFlags);
        $this->assertFalse((bool) ($intelligence['data_quality']['is_stale'] ?? true));
        $this->assertFalse((bool) ($intelligence['data_quality']['has_engagement_evidence'] ?? true));

        $this->contacts->delete((int) $contact['id']);
    }

    public function testOldOutboundWithoutNewerInboundGetsNoResponseRisk(): void
    {
        $contact = $this->contacts->create([
            'first_name' => 'Pending',
            'last_name' => 'Reply',
            'email' => 'pending.reply@example.test',
            'phone' => '+254700000103',
            'company' => 'Pending Co',
            'job_title' => 'Director',
            'location' => 'Nairobi',
        ]);
        $contactId = (int) $contact['id'];
        Database::execute(
            "INSERT INTO communications (contact_id, channel, direction, subject, body, created_at)
             VALUES (?, 'email', 'outbound', 'Checking in', 'Following up on the proposal.', DATE_SUB(NOW(), INTERVAL 22 DAY))",
            [$contactId]
        );

        $service = new ContactIntelligenceService();
        $intelligence = $service->computeAndPersist($contactId);

        $riskFlags = $intelligence['relationship_health']['risk_flags'] ?? [];
        $this->assertContains('No response in 21 days', $riskFlags);
        $this->assertSame('outbound_reply', (string) ($intelligence['data_quality']['last_touch_source'] ?? ''));
        $this->assertNotEmpty($intelligence['data_quality']['awaiting_response_since'] ?? null);

        $this->contacts->delete($contactId);
    }

    public function testNewerInboundSuppressesNoResponseRisk(): void
    {
        $contact = $this->contacts->create([
            'first_name' => 'Replied',
            'last_name' => 'Contact',
            'email' => 'replied.contact@example.test',
            'phone' => '+254700000104',
            'company' => 'Reply Co',
            'job_title' => 'Manager',
            'location' => 'Nairobi',
        ]);
        $contactId = (int) $contact['id'];
        Database::execute(
            "INSERT INTO communications (uuid, contact_id, channel, direction, subject, body, created_at)
             VALUES
                (?, ?, 'email', 'outbound', 'Checking in', 'Following up on the proposal.', DATE_SUB(NOW(), INTERVAL 25 DAY)),
                (?, ?, 'email', 'inbound', 'Re: Checking in', 'Thanks, we are reviewing it.', DATE_SUB(NOW(), INTERVAL 2 DAY))",
            [uniqid('comm-outbound-', true), $contactId, uniqid('comm-inbound-', true), $contactId]
        );

        $service = new ContactIntelligenceService();
        $intelligence = $service->computeAndPersist($contactId);

        $riskFlags = $intelligence['relationship_health']['risk_flags'] ?? [];
        $this->assertNotContains('No response in 21 days', $riskFlags);
        $this->assertNotContains('Contact is stale', $riskFlags);
        $this->assertFalse((bool) ($intelligence['data_quality']['is_stale'] ?? true));
        $this->assertNull($intelligence['data_quality']['awaiting_response_since'] ?? null);

        $this->contacts->delete($contactId);
    }

    public function testOldRelationshipActivityGetsStaleRisk(): void
    {
        $contact = $this->contacts->create([
            'first_name' => 'Aging',
            'last_name' => 'Activity',
            'email' => 'aging.activity@example.test',
            'phone' => '+254700000105',
            'company' => 'Aging Co',
            'job_title' => 'Operations Lead',
            'location' => 'Nairobi',
        ]);
        $contactId = (int) $contact['id'];
        Database::execute(
            "INSERT INTO notes (workspace_id, entity_type, entity_id, title, content, is_private, created_by, created_at)
             VALUES (1, 'contact', ?, 'Discovery recap', 'Old discovery notes.', 0, ?, DATE_SUB(NOW(), INTERVAL 30 DAY))",
            [$contactId, $this->userId]
        );

        $service = new ContactIntelligenceService();
        $intelligence = $service->computeAndPersist($contactId);

        $riskFlags = $intelligence['relationship_health']['risk_flags'] ?? [];
        $this->assertContains('Contact is stale', $riskFlags);
        $this->assertNotContains('No response in 21 days', $riskFlags);
        $this->assertTrue((bool) ($intelligence['data_quality']['is_stale'] ?? false));
        $this->assertSame('note', (string) ($intelligence['data_quality']['last_touch_source'] ?? ''));

        $this->contacts->delete($contactId);
    }

    public function testRecomputingPersistedFalsePositiveRiskFlagsRepairsNeverEngagedContact(): void
    {
        $contact = $this->contacts->create([
            'first_name' => 'Repair',
            'last_name' => 'Candidate',
            'email' => 'repair.candidate@example.test',
            'phone' => '+254700000106',
            'company' => 'Repair Co',
            'job_title' => 'Founder',
            'location' => 'Nairobi',
        ]);
        $contactId = (int) $contact['id'];

        $metadata = [
            'contact_intelligence' => [
                'relationship_health' => [
                    'score' => 60,
                    'band' => 'at_risk',
                    'risk_flags' => ['No response in 21 days', 'Contact is stale'],
                ],
                'risk_flags' => ['No response in 21 days', 'Contact is stale'],
                'data_quality' => [
                    'is_stale' => true,
                    'stale_days' => null,
                    'missing_critical_fields' => [],
                    'likely_duplicates' => [],
                ],
            ],
        ];
        Database::execute(
            "UPDATE contacts SET metadata_json = ? WHERE id = ?",
            [json_encode($metadata, JSON_UNESCAPED_SLASHES), $contactId]
        );

        $service = new ContactIntelligenceService();
        $intelligence = $service->computeAndPersist($contactId);

        $riskFlags = $intelligence['relationship_health']['risk_flags'] ?? [];
        $this->assertNotContains('No response in 21 days', $riskFlags);
        $this->assertNotContains('Contact is stale', $riskFlags);
        $this->assertFalse((bool) ($intelligence['data_quality']['is_stale'] ?? true));

        $stored = $service->getStoredOrCompute($contactId);
        $this->assertNotContains('No response in 21 days', $stored['relationship_health']['risk_flags'] ?? []);
        $this->assertNotContains('Contact is stale', $stored['relationship_health']['risk_flags'] ?? []);

        $this->contacts->delete($contactId);
    }

    public function testDuplicateSignalsStayInsideActiveWorkspace(): void
    {
        $primary = $this->contacts->create([
            'first_name' => 'Riley',
            'last_name' => 'Stone',
            'email' => 'riley@acme.test',
            'company' => 'Acme Studio',
        ]);
        $sameWorkspaceDuplicate = $this->contacts->create([
            'first_name' => 'Riley',
            'last_name' => 'Stone',
            'email' => 'riley.alt@acme.test',
            'company' => 'Acme Studio',
        ]);

        Database::execute(
            "INSERT INTO workspaces (uuid, name, slug, status, plan_status, created_at, updated_at)
             VALUES (?, 'Other Workspace', ?, 'active', 'inactive', NOW(), NOW())",
            [uniqid('workspace-', true), uniqid('other-workspace-', true)]
        );
        $foreignWorkspaceId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, company, created_at)
             VALUES (?, ?, 'Riley', 'Stone', 'riley.foreign@acme.test', 'Acme Studio', NOW())",
            [$foreignWorkspaceId, uniqid('foreign-contact-', true)]
        );
        $foreignContactId = (int) Database::lastInsertId();

        $service = new ContactIntelligenceService();
        $intelligence = $service->computeAndPersist((int) $primary['id']);
        $duplicateIds = array_map(
            static fn (array $row): int => (int) $row['id'],
            $intelligence['data_quality']['likely_duplicates'] ?? []
        );

        $this->assertContains((int) $sameWorkspaceDuplicate['id'], $duplicateIds);
        $this->assertNotContains($foreignContactId, $duplicateIds);

        $row = Database::queryOne("SELECT metadata_json FROM contacts WHERE id = ?", [(int) $primary['id']]);
        $metadata = json_decode((string) ($row['metadata_json'] ?? '{}'), true) ?: [];
        $metadata['contact_intelligence']['data_quality']['likely_duplicates'][] = [
            'id' => $foreignContactId,
            'first_name' => 'Riley',
            'last_name' => 'Stone',
            'email' => 'riley.foreign@acme.test',
            'duplicate_score' => 1,
        ];
        $metadata['contact_intelligence']['duplicates'] = $metadata['contact_intelligence']['data_quality']['likely_duplicates'];
        Database::execute(
            "UPDATE contacts SET metadata_json = ? WHERE id = ?",
            [json_encode($metadata, JSON_UNESCAPED_SLASHES), (int) $primary['id']]
        );

        $storedById = $service->getStoredForContactIds([(int) $primary['id']]);
        $storedDuplicateIds = array_map(
            static fn (array $row): int => (int) $row['id'],
            $storedById[(int) $primary['id']]['data_quality']['likely_duplicates'] ?? []
        );

        $this->assertContains((int) $sameWorkspaceDuplicate['id'], $storedDuplicateIds);
        $this->assertNotContains($foreignContactId, $storedDuplicateIds);

        $this->contacts->delete((int) $primary['id']);
        $this->contacts->delete((int) $sameWorkspaceDuplicate['id']);
        Database::execute("DELETE FROM contacts WHERE id = ?", [$foreignContactId]);
        Database::execute("DELETE FROM workspaces WHERE id = ?", [$foreignWorkspaceId]);
    }
}
