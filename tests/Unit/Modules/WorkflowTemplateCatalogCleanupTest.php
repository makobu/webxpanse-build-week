<?php

namespace CRM\Tests\Unit\Modules;

use CRM\Database;
use CRM\Modules\AutomationEngine;
use CRM\Modules\WorkflowRecommendationService;
use CRM\Modules\WorkflowTemplates;
use CRM\Session;
use CRM\Tests\DatabaseTestCase;

class WorkflowTemplateCatalogCleanupTest extends DatabaseTestCase
{
    public function testMigrationArchivesDuplicatesDemoAndUnsupportedPublicTemplates(): void
    {
        foreach ($this->duplicateStarterNames() as $name) {
            $row = Database::queryOne(
                "SELECT COUNT(*) AS c FROM workflow_templates WHERE name = ? AND is_active = 1",
                [$name]
            );
            $this->assertSame(1, (int) ($row['c'] ?? 0), $name . ' should have one active canonical row.');
        }

        $demo = Database::queryOne(
            "SELECT COUNT(*) AS c FROM workflow_templates WHERE name = 'Demo Escalation Template' AND is_public = 1 AND is_active = 1"
        );
        $this->assertSame(0, (int) ($demo['c'] ?? 0));

        foreach ($this->unsupportedPublicNames() as $name) {
            $row = Database::queryOne(
                "SELECT COUNT(*) AS c FROM workflow_templates WHERE name = ? AND is_public = 1 AND is_active = 1",
                [$name]
            );
            $this->assertSame(0, (int) ($row['c'] ?? 0), $name . ' should not remain active and public.');
        }

        $platformOpsPublic = Database::queryOne(
            "SELECT COUNT(*) AS c FROM workflow_templates WHERE category = 'platform_ops' AND is_public = 1"
        );
        $this->assertSame(0, (int) ($platformOpsPublic['c'] ?? 0));
    }

    public function testPublicTemplateListIsCuratedExecutableAndDeduped(): void
    {
        $templates = (new WorkflowTemplates())->getPublicTemplates();
        $names = array_map(static fn(array $template): string => (string) ($template['name'] ?? ''), $templates);

        foreach ($this->duplicateStarterNames() as $name) {
            $this->assertSame(1, count(array_filter($names, static fn(string $candidate): bool => $candidate === $name)));
        }

        $this->assertNotContains('Demo Escalation Template', $names);
        foreach ($this->unsupportedPublicNames() as $name) {
            $this->assertNotContains($name, $names);
        }

        $supportedTriggers = (new AutomationEngine())->getTriggers();
        foreach ($templates as $template) {
            $trigger = json_decode((string) ($template['trigger_config'] ?? ''), true);
            $this->assertIsArray($trigger);
            $this->assertContains((string) ($trigger['type'] ?? ''), $supportedTriggers);
        }
    }

    public function testTemplateLookupAndCreationRejectInactivePrivateAndUnsupportedIds(): void
    {
        $userId = $this->createUser('workflow-viewer');
        $ownerId = $this->createUser('workflow-owner');
        Session::set('user_id', $userId);

        $templates = new WorkflowTemplates();
        $inactiveId = $this->insertWorkflowTemplate([
            'name' => 'Inactive Public Runtime Guard',
            'trigger_config' => ['type' => 'contact_created'],
            'actions' => [['type' => 'create_task', 'title' => 'Inactive follow-up']],
            'is_public' => 1,
            'is_active' => 0,
            'created_by' => $ownerId,
        ]);
        $this->assertNull($templates->getTemplate($inactiveId, $userId));

        $unsupported = Database::queryOne("SELECT id FROM workflow_templates WHERE name = 'Invoice Payment Reminder' LIMIT 1");
        $this->assertNotNull($unsupported);
        $this->assertNull($templates->getTemplate((int) $unsupported['id'], $userId));

        $privateId = $this->insertWorkflowTemplate([
            'name' => 'Private Owner Workflow',
            'trigger_config' => ['type' => 'contact_created'],
            'actions' => [['type' => 'create_task', 'title' => 'Owner only follow-up']],
            'is_public' => 0,
            'created_by' => $ownerId,
        ]);

        $this->assertNull($templates->getTemplate($privateId, $userId));
        $this->assertNotNull($templates->getTemplate($privateId, $ownerId));

        $this->expectException(\Exception::class);
        $templates->createFromTemplate($privateId, 'Should not create', [], $userId);
    }

    public function testCreateFromTemplateRejectsUnsupportedTriggerBeforeWorkflowCreation(): void
    {
        $userId = $this->createUser('workflow-unsupported');
        Session::set('user_id', $userId);

        $unsupportedId = $this->insertWorkflowTemplate([
            'name' => 'Unsupported Trigger Runtime Guard',
            'trigger_config' => ['type' => 'invoice_overdue'],
            'actions' => [['type' => 'create_task', 'title' => 'Review invoice']],
            'is_public' => 1,
            'created_by' => $userId,
        ]);

        $templates = new WorkflowTemplates();
        $this->assertNull($templates->getTemplate($unsupportedId, $userId));

        $this->expectException(\Exception::class);
        $templates->createFromTemplate($unsupportedId, 'Unsupported workflow', [], $userId);
    }

    public function testRecommendationFallbackUsesCuratedCatalogOnly(): void
    {
        $templates = (new WorkflowTemplates())->getPublicTemplates();
        $recommendations = $this->fallbackRecommendations($templates);

        foreach (['recommended_templates', 'popular_templates'] as $section) {
            $names = [];
            foreach ($recommendations[$section] ?? [] as $item) {
                $template = $item['template'] ?? $item;
                if (is_array($template)) {
                    $names[] = (string) ($template['name'] ?? '');
                }
            }

            $this->assertNotContains('Demo Escalation Template', $names);
            foreach ($this->unsupportedPublicNames() as $name) {
                $this->assertNotContains($name, $names);
            }
            foreach ($this->duplicateStarterNames() as $name) {
                $this->assertLessThanOrEqual(1, count(array_filter($names, static fn(string $candidate): bool => $candidate === $name)));
            }
        }
    }

    public function testDealTitleTokenResolvesFromExplicitDealOrLatestContactDeal(): void
    {
        $userId = $this->createUser('workflow-token');
        Session::set('user_id', $userId);

        $contactId = $this->createContact($userId);
        $olderDealId = $this->createDeal($contactId, $userId, 'Legacy Expansion', '2026-01-01 09:00:00');
        $this->createDeal($contactId, $userId, 'Priority Renewal', '2026-02-01 09:00:00');

        $engine = new AutomationEngine();
        $engine->executeAction(
            ['type' => 'create_task', 'title' => 'Follow up on {deal.title}', 'description' => 'Stage: {deal.stage}'],
            ['contact_id' => $contactId, 'deal_id' => $olderDealId, 'workspace_id' => 1, 'user_id' => $userId]
        );
        $explicitTask = $this->latestTaskForContact($contactId);
        $this->assertSame('Follow up on Legacy Expansion', $explicitTask['title'] ?? null);
        $this->assertSame('Stage: proposal', $explicitTask['description'] ?? null);

        $engine->executeAction(
            ['type' => 'create_task', 'title' => 'Follow up on {deal.title}'],
            ['contact_id' => $contactId, 'workspace_id' => 1, 'user_id' => $userId]
        );
        $fallbackTask = $this->latestTaskForContact($contactId);
        $this->assertSame('Follow up on Priority Renewal', $fallbackTask['title'] ?? null);
    }

    /**
     * @return array<int, string>
     */
    private function duplicateStarterNames(): array
    {
        return [
            'Welcome New Contacts',
            'Lead Nurturing Sequence',
            'Re-engagement Campaign',
            'Deal Follow-up',
            'Birthday Automation',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function unsupportedPublicNames(): array
    {
        return [
            'Invoice Payment Reminder',
            'Support Follow-up and Escalation',
            'Channel Setup Reminder',
        ];
    }

    private function createUser(string $prefix): int
    {
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role) VALUES (?, ?, ?, 'admin')",
            [bin2hex(random_bytes(16)), $prefix . '-' . bin2hex(random_bytes(4)) . '@example.test', password_hash('secret', PASSWORD_BCRYPT)]
        );

        $userId = (int) Database::lastInsertId();
        $this->addWorkspaceMembership($userId);
        $this->assignRole($userId, 'admin');

        return $userId;
    }

    private function addWorkspaceMembership(int $userId): void
    {
        Database::execute(
            "INSERT INTO workspace_memberships (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at)
             VALUES (1, ?, 'admin', 'active', 1, NOW())
             ON DUPLICATE KEY UPDATE role_slug = VALUES(role_slug), membership_status = 'active', is_owner = VALUES(is_owner)",
            [$userId]
        );
    }

    private function assignRole(int $userId, string $roleSlug): void
    {
        $role = Database::queryOne("SELECT id FROM roles WHERE slug = ? LIMIT 1", [$roleSlug]);
        if (!$role) {
            return;
        }

        Database::execute(
            "INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE role_id = VALUES(role_id)",
            [$userId, (int) $role['id']]
        );
    }

    private function createContact(int $userId): int
    {
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, assigned_to, created_at, updated_at)
             VALUES (1, ?, 'Workflow', 'Token', ?, ?, NOW(), NOW())",
            [bin2hex(random_bytes(18)), 'workflow-token-' . bin2hex(random_bytes(4)) . '@example.test', $userId]
        );

        return (int) Database::lastInsertId();
    }

    private function createDeal(int $contactId, int $userId, string $title, string $createdAt): int
    {
        Database::execute(
            "INSERT INTO deals (workspace_id, title, contact_id, created_by, stage, value, created_at, updated_at)
             VALUES (1, ?, ?, ?, 'proposal', 1000, ?, ?)",
            [$title, $contactId, $userId, $createdAt, $createdAt]
        );

        return (int) Database::lastInsertId();
    }

    private function latestTaskForContact(int $contactId): array
    {
        return Database::queryOne(
            "SELECT title, description FROM tasks WHERE contact_id = ? ORDER BY id DESC LIMIT 1",
            [$contactId]
        ) ?: [];
    }

    private function insertWorkflowTemplate(array $overrides): int
    {
        $row = array_merge([
            'name' => 'Runtime Guard Workflow',
            'description' => 'Runtime guard test template.',
            'category' => 'test',
            'trigger_config' => ['type' => 'contact_created'],
            'conditions' => [],
            'actions' => [['type' => 'create_task', 'title' => 'Review contact']],
            'variables' => [],
            'is_public' => 1,
            'is_active' => 1,
            'created_by' => null,
        ], $overrides);

        Database::execute(
            "INSERT INTO workflow_templates
                (name, description, category, trigger_config, conditions, actions, variables, is_public, is_active, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $row['name'],
                $row['description'],
                $row['category'],
                json_encode($row['trigger_config']),
                json_encode($row['conditions']),
                json_encode($row['actions']),
                json_encode($row['variables']),
                (int) $row['is_public'],
                (int) $row['is_active'],
                $row['created_by'],
            ]
        );

        return (int) Database::lastInsertId();
    }

    private function fallbackRecommendations(array $templates): array
    {
        $service = new WorkflowRecommendationService();
        $method = new \ReflectionMethod($service, 'getFallbackRecommendations');
        $method->setAccessible(true);

        return $method->invoke($service, $templates);
    }
}
