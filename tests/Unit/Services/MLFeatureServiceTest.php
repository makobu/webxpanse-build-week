<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Session;
use CRM\Services\MLFeatureService;
use CRM\Services\WorkspaceContext;
use CRM\Tests\DatabaseTestCase;

class MLFeatureServiceTest extends DatabaseTestCase
{
    private MLFeatureService $featureService;
    private int $userId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->featureService = new MLFeatureService();
        $this->ensureWorkspace(2, 'ml-feature-two', 'ML Feature Two');
        $this->userId = $this->createUser();
        $this->switchWorkspace(1);
    }

    public function testExtractAllFeaturesRejectsForeignWorkspaceContact(): void
    {
        $foreignContactId = $this->createContact(2, 'foreign-feature@example.test');

        $this->switchWorkspace(1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Contact not found in the active workspace.');
        $this->featureService->extractAllFeatures($foreignContactId);
    }

    public function testExtractAllFeaturesUsesOnlyActiveWorkspaceRows(): void
    {
        $contactId = $this->createContact(1, 'current-feature@example.test');

        $this->createEmail(1, $contactId, 'opened');
        $this->createEmail(2, $contactId, 'clicked');
        $this->createActivity(1, $contactId, 'email_opened');
        $this->createActivity(2, $contactId, 'form_submitted');
        $this->createDeal(1, $contactId, 100.00, 'closed_won');
        $this->createDeal(2, $contactId, 900.00, 'closed_lost');

        $this->switchWorkspace(1);
        $features = $this->featureService->extractAllFeatures($contactId);

        $this->assertSame(1, $features['total_emails_sent']);
        $this->assertSame(1, $features['total_emails_opened']);
        $this->assertSame(0, $features['total_emails_clicked']);
        $this->assertSame(1, $features['total_activities']);
        $this->assertSame(0, $features['form_submissions']);
        $this->assertSame(1, $features['deal_count']);
        $this->assertSame(100.00, $features['total_deal_value']);
    }

    public function testFeatureCacheIsSeparatedByWorkspace(): void
    {
        $contactId = $this->createContact(1, 'cache-feature@example.test');
        $this->createActivity(1, $contactId, 'email_opened');

        $this->featureService->cacheFeatures($contactId, ['total_activities' => 99], 2);

        $this->switchWorkspace(1);
        $features = $this->featureService->extractAllFeatures($contactId);

        $this->assertSame(1, $features['total_activities']);
    }

    private function createUser(): int
    {
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, first_name, last_name, created_at)
             VALUES (?, ?, ?, 'admin', 'ML', 'Tester', NOW())",
            [uniqid('ml-feature-user-', true), 'ml-feature-user@example.test', password_hash('secret', PASSWORD_DEFAULT)]
        );

        return (int) Database::lastInsertId();
    }

    private function createContact(int $workspaceId, string $email): int
    {
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, created_at)
             VALUES (?, ?, 'Feature', 'Contact', ?, NOW())",
            [$workspaceId, uniqid('ml-feature-contact-', true), $email]
        );

        return (int) Database::lastInsertId();
    }

    private function createEmail(int $workspaceId, int $contactId, string $status): void
    {
        Database::execute(
            "INSERT INTO emails (workspace_id, uuid, contact_id, to_email, from_email, subject, body, status, created_at)
             VALUES (?, ?, ?, 'lead@example.test', 'sender@example.test', 'Subject', 'Body', ?, NOW())",
            [$workspaceId, uniqid('ml-feature-email-', true), $contactId, $status]
        );
    }

    private function createActivity(int $workspaceId, int $contactId, string $activityType): void
    {
        Database::execute(
            "INSERT INTO activities (workspace_id, contact_id, activity_type, created_at)
             VALUES (?, ?, ?, NOW())",
            [$workspaceId, $contactId, $activityType]
        );
    }

    private function createDeal(int $workspaceId, int $contactId, float $value, string $stage): void
    {
        Database::execute(
            "INSERT INTO deals (workspace_id, title, contact_id, created_by, stage, value, probability, created_at, updated_at)
             VALUES (?, 'Feature Deal', ?, ?, ?, ?, 50, NOW(), NOW())",
            [$workspaceId, $contactId, $this->userId, $stage, $value]
        );
    }

    private function ensureWorkspace(int $id, string $slug, string $name): void
    {
        $existing = Database::queryOne("SELECT id FROM workspaces WHERE id = ? LIMIT 1", [$id]);
        if ($existing) {
            return;
        }

        Database::execute(
            "INSERT INTO workspaces (id, uuid, name, slug, status, plan_status, created_at, updated_at)
             VALUES (?, ?, ?, ?, 'active', 'trialing', NOW(), NOW())",
            [$id, sprintf('00000000-0000-4000-8000-%012d', $id), $name, $slug]
        );
    }

    private function switchWorkspace(int $workspaceId): void
    {
        Session::set('active_workspace_id', $workspaceId);
        WorkspaceContext::activateRuntimeWorkspace($workspaceId);
    }
}
