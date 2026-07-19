<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\EmailTemplates;
use CRM\Tests\DatabaseTestCase;

class EmailTemplatesTest extends DatabaseTestCase
{
    private EmailTemplates $templates;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->templates = new EmailTemplates();
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, 'viewer', NOW())",
            [uniqid('template-user-', true), 'templates-owner@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $this->userId = (int) Database::lastInsertId();
    }

    public function testGetSendableTemplatesForUserReturnsOnlyActiveOwnedTemplates(): void
    {
        $sendableId = $this->templates->create([
            'name' => 'Sendable Template',
            'slug' => 'sendable-template',
            'subject' => 'Hello {first_name}',
            'body_html' => '<p>Hi {first_name}</p>',
            'body_text' => 'Hi {first_name}',
            'category' => 'sales',
            'variables' => ['first_name'],
            'is_active' => 1,
            'created_by' => $this->userId,
            'is_library' => 0,
        ]);
        $this->templates->create([
            'name' => 'Library Template',
            'slug' => 'library-template',
            'subject' => 'Library',
            'body_html' => '<p>Library</p>',
            'body_text' => 'Library',
            'category' => 'sales',
            'variables' => [],
            'is_active' => 1,
            'created_by' => $this->userId,
            'is_library' => 1,
        ]);
        $this->templates->create([
            'name' => 'Inactive Template',
            'slug' => 'inactive-template',
            'subject' => 'Inactive',
            'body_html' => '<p>Inactive</p>',
            'body_text' => 'Inactive',
            'category' => 'sales',
            'variables' => [],
            'is_active' => 0,
            'created_by' => $this->userId,
            'is_library' => 0,
        ]);

        $sendableTemplates = $this->templates->getSendableTemplatesForUser($this->userId);

        $this->assertSame([$sendableId], array_values(array_map(static fn (array $template): int => (int) $template['id'], $sendableTemplates)));
    }

    public function testSendableTemplateLookupsRejectLibraryAndInactiveTemplates(): void
    {
        $this->templates->create([
            'name' => 'Installed Template',
            'slug' => 'installed-template',
            'subject' => 'Hello {first_name}',
            'body_html' => '<p>Hi {first_name}</p>',
            'body_text' => 'Hi {first_name}',
            'category' => 'sales',
            'variables' => ['first_name'],
            'is_active' => 1,
            'created_by' => $this->userId,
            'is_library' => 0,
        ]);
        $this->templates->create([
            'name' => 'Hidden Library Template',
            'slug' => 'hidden-library-template',
            'subject' => 'Library',
            'body_html' => '<p>Library</p>',
            'body_text' => 'Library',
            'category' => 'sales',
            'variables' => [],
            'is_active' => 1,
            'created_by' => $this->userId,
            'is_library' => 1,
        ]);
        $this->templates->create([
            'name' => 'Hidden Inactive Template',
            'slug' => 'hidden-inactive-template',
            'subject' => 'Inactive',
            'body_html' => '<p>Inactive</p>',
            'body_text' => 'Inactive',
            'category' => 'sales',
            'variables' => [],
            'is_active' => 0,
            'created_by' => $this->userId,
            'is_library' => 0,
        ]);

        $this->assertNotNull($this->templates->getSendableTemplateBySlug('installed-template', $this->userId));
        $this->assertNull($this->templates->getSendableTemplateBySlug('hidden-library-template', $this->userId));
        $this->assertNull($this->templates->getSendableTemplateBySlug('hidden-inactive-template', $this->userId));
    }

    public function testSendableTemplatesOnlyExposeApprovedLearnedAiTemplates(): void
    {
        Database::execute(
            "INSERT INTO smart_template_sets (workspace_id, user_id, status, context_hash, context_snapshot_json, created_at, updated_at)
             VALUES (1, ?, 'candidate', 'candidate-hash', '{}', NOW(), NOW())",
            [$this->userId]
        );
        $candidateSetId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO smart_template_sets (workspace_id, user_id, status, context_hash, context_snapshot_json, created_at, updated_at)
             VALUES (1, ?, 'active', 'active-hash', '{}', NOW(), NOW())",
            [$this->userId]
        );
        $activeSetId = (int) Database::lastInsertId();

        $candidateTemplateId = $this->templates->create([
            'name' => 'Candidate Learned Template',
            'slug' => 'candidate-learned-template',
            'subject' => 'Candidate',
            'body_html' => '<p>Candidate</p>',
            'body_text' => 'Candidate',
            'category' => 'sales',
            'variables' => [],
            'is_active' => 1,
            'created_by' => $this->userId,
            'is_library' => 0,
            'is_ai_generated' => 1,
            'smart_template_set_id' => $candidateSetId,
        ]);
        $activeTemplateId = $this->templates->create([
            'name' => 'Approved Learned Template',
            'slug' => 'approved-learned-template',
            'subject' => 'Approved',
            'body_html' => '<p>Approved</p>',
            'body_text' => 'Approved',
            'category' => 'sales',
            'variables' => [],
            'is_active' => 1,
            'created_by' => $this->userId,
            'is_library' => 0,
            'is_ai_generated' => 1,
            'smart_template_set_id' => $activeSetId,
        ]);

        $sendableTemplateIds = array_values(array_map(
            static fn(array $template): int => (int) $template['id'],
            $this->templates->getSendableTemplatesForUser($this->userId)
        ));

        $this->assertContains($activeTemplateId, $sendableTemplateIds);
        $this->assertNotContains($candidateTemplateId, $sendableTemplateIds);
        $this->assertNotNull($this->templates->getSendableTemplateBySlug('approved-learned-template', $this->userId));
        $this->assertNull($this->templates->getSendableTemplateBySlug('candidate-learned-template', $this->userId));
    }

    public function testGeneratedSmartTemplateRendersWithQuietLetterShell(): void
    {
        $setId = $this->createActiveSmartTemplateSet();
        $this->templates->create([
            'name' => 'Generated Lead Intro',
            'slug' => 'generated-lead-intro',
            'subject' => 'Hello {first_name}',
            'body_html' => '<p>Hi {first_name},</p><p>Please review <a href="https://example.com/plan">the plan</a>.</p>',
            'body_text' => 'Hi {first_name}, Please review the plan.',
            'category' => 'sales',
            'variables' => ['first_name'],
            'is_active' => 1,
            'created_by' => $this->userId,
            'is_library' => 0,
            'is_ai_generated' => 1,
            'smart_template_set_id' => $setId,
        ]);

        $rendered = $this->templates->renderSendableTemplate('generated-lead-intro', $this->userId, [
            'first_name' => '<Alex & Co>',
        ]);

        $this->assertStringContainsString('data-crm-generated-email-shell="v1"', $rendered['body_html']);
        $this->assertStringContainsString('<meta name="viewport"', $rendered['body_html']);
        $this->assertStringContainsString('<meta name="color-scheme" content="light dark"', $rendered['body_html']);
        $this->assertStringContainsString('@media (prefers-color-scheme: dark)', $rendered['body_html']);
        $this->assertStringContainsString('role="presentation"', $rendered['body_html']);
        $this->assertStringContainsString('#e9f0f5', $rendered['body_html']);
        $this->assertStringContainsString('linear-gradient(135deg,#0b2942', $rendered['body_html']);
        $this->assertStringContainsString('#145c7d', $rendered['body_html']);
        $this->assertStringContainsString('&lt;Alex &amp; Co&gt;', $rendered['body_html']);
        $this->assertStringNotContainsString('Email Assistant', $rendered['body_html']);
        $this->assertSame('Hi &lt;Alex &amp; Co&gt;, Please review the plan.', $rendered['body_text']);
    }

    public function testDefaultWorkspaceOpsTemplateRendersWithQuietLetterShell(): void
    {
        $this->templates->create([
            'name' => 'Platform Ops Test Welcome',
            'slug' => 'platform-ops-test-welcome',
            'subject' => 'Welcome to {workspace_name}',
            'body_html' => '<p>Hi {owner_name},</p><p><a href="{setup_url}">Start setup</a></p>',
            'body_text' => 'Hi {owner_name}, Start setup: {setup_url}',
            'category' => 'platform_ops',
            'variables' => ['owner_name', 'workspace_name', 'setup_url'],
            'is_active' => 1,
            'created_by' => $this->userId,
            'is_library' => 0,
            'is_ai_generated' => 0,
            'tags' => ['platform_ops_owner_helpline', 'workspace_owner'],
        ]);

        $variables = [
            'owner_name' => 'Dana',
            'workspace_name' => 'Northwind HQ',
            'setup_url' => 'https://example.test/setup',
        ];
        $sendable = $this->templates->renderSendableTemplate('platform-ops-test-welcome', $this->userId, $variables);
        $template = $this->templates->getSendableTemplateBySlug('platform-ops-test-welcome', $this->userId);
        $preview = $this->templates->renderTemplateRecord($template ?? [], $variables, 'platform-ops-test-welcome', false);

        $this->assertSame($sendable, $preview);
        $this->assertStringContainsString('data-crm-generated-email-shell="v1"', $sendable['body_html']);
        $this->assertStringContainsString('<meta name="viewport"', $sendable['body_html']);
        $this->assertStringContainsString('<meta name="color-scheme" content="light dark"', $sendable['body_html']);
        $this->assertStringContainsString('@media (prefers-color-scheme: dark)', $sendable['body_html']);
        $this->assertStringContainsString('role="presentation"', $sendable['body_html']);
        $this->assertStringContainsString('#e9f0f5', $sendable['body_html']);
        $this->assertStringContainsString('linear-gradient(135deg,#0b2942', $sendable['body_html']);
        $this->assertStringContainsString('#145c7d', $sendable['body_html']);
        $this->assertStringContainsString('Hi Dana', $sendable['body_html']);
        $this->assertStringNotContainsString('Email Assistant', $sendable['body_html']);
        $this->assertSame('Hi Dana, Start setup: https://webxpanse.com/setup', $sendable['body_text']);
    }

    public function testWorkspaceGeneratedTemplateWithoutSmartSetUsesPremiumShellAndSafeLinks(): void
    {
        $this->templates->create([
            'name' => 'Workspace Starter',
            'slug' => 'workspace-starter-test',
            'subject' => 'A next step for {{ first_name }}',
            'body_html' => '<p>Hi {{ first_name }},</p><p><a href="/crm/public/onboarding.php">Continue</a></p><p><a href="">Broken action</a></p>',
            'body_text' => 'Hi {{ first_name }}, continue at http://localhost/crm/public/onboarding.php',
            'category' => 'workspace_starter',
            'variables' => ['first_name'],
            'is_active' => 1,
            'created_by' => $this->userId,
            'is_library' => 0,
            'is_ai_generated' => 1,
        ]);

        $rendered = $this->templates->renderSendableTemplate('workspace-starter-test', $this->userId, [
            'first_name' => 'Amina',
        ]);

        $this->assertStringContainsString('data-crm-generated-email-shell="v1"', $rendered['body_html']);
        $this->assertStringContainsString('A note from your team', $rendered['body_html']);
        $this->assertStringContainsString('Hi Amina', $rendered['body_html']);
        $this->assertStringContainsString('href="https://webxpanse.com/onboarding.php"', $rendered['body_html']);
        $this->assertStringNotContainsString('href=""', $rendered['body_html']);
        $this->assertStringNotContainsString('{{', $rendered['body_html']);
        $this->assertStringContainsString('Broken action', $rendered['body_html']);
        $this->assertSame('Hi Amina, continue at https://webxpanse.com/onboarding.php', $rendered['body_text']);
    }

    public function testManualTemplateRendersUnchanged(): void
    {
        $this->templates->create([
            'name' => 'Manual Template',
            'slug' => 'manual-template',
            'subject' => 'Hello {first_name}',
            'body_html' => '<p>Hi {first_name}</p>',
            'body_text' => 'Hi {first_name}',
            'category' => 'sales',
            'variables' => ['first_name'],
            'is_active' => 1,
            'created_by' => $this->userId,
            'is_library' => 0,
        ]);

        $rendered = $this->templates->renderSendableTemplate('manual-template', $this->userId, [
            'first_name' => 'Jane',
        ]);

        $this->assertSame('<p>Hi Jane</p>', $rendered['body_html']);
        $this->assertStringNotContainsString('data-crm-generated-email-shell="v1"', $rendered['body_html']);
    }

    public function testGenericStarterAndLibraryTemplatesAreRetiredByMigrations(): void
    {
        $activeGenericCount = (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM email_templates
             WHERE COALESCE(is_active, 0) = 1
               AND (
                   slug IN ('welcome', 'follow_up', 'thank_you')
                   OR (slug LIKE 'library-%' AND (workspace_id IS NULL OR workspace_id = 1))
               )"
        )['c'] ?? 0);

        $this->assertSame(0, $activeGenericCount);
    }

    public function testGeneratedFullDocumentHtmlIsNormalizedBeforeWrapping(): void
    {
        $setId = $this->createActiveSmartTemplateSet();
        $this->templates->create([
            'name' => 'Generated Full Document',
            'slug' => 'generated-full-document',
            'subject' => 'Document',
            'body_html' => '<!DOCTYPE html><html><head><style>body{color:red}</style></head><body><p onclick="alert(1)">Hi {first_name}</p><script>alert(2)</script></body></html>',
            'body_text' => 'Hi {first_name}',
            'category' => 'sales',
            'variables' => ['first_name'],
            'is_active' => 1,
            'created_by' => $this->userId,
            'is_library' => 0,
            'is_ai_generated' => 1,
            'smart_template_set_id' => $setId,
        ]);

        $rendered = $this->templates->renderSendableTemplate('generated-full-document', $this->userId, [
            'first_name' => 'Sam',
        ]);

        $this->assertStringContainsString('data-crm-generated-email-shell="v1"', $rendered['body_html']);
        $this->assertStringContainsString('Hi Sam', $rendered['body_html']);
        $this->assertStringNotContainsString('<script', $rendered['body_html']);
        $this->assertStringNotContainsString('color:red', $rendered['body_html']);
        $this->assertStringNotContainsString('onclick', $rendered['body_html']);
        $this->assertSame(1, substr_count($rendered['body_html'], '<body'));
    }

    public function testGeneratedTemplateAlreadyWrappedIsNotWrappedTwice(): void
    {
        $setId = $this->createActiveSmartTemplateSet();
        $this->templates->create([
            'name' => 'Already Wrapped',
            'slug' => 'already-wrapped',
            'subject' => 'Wrapped',
            'body_html' => '<html><body><table data-crm-generated-email-shell="v1"><tr><td><p>Hi {first_name}</p></td></tr></table></body></html>',
            'body_text' => 'Hi {first_name}',
            'category' => 'sales',
            'variables' => ['first_name'],
            'is_active' => 1,
            'created_by' => $this->userId,
            'is_library' => 0,
            'is_ai_generated' => 1,
            'smart_template_set_id' => $setId,
        ]);

        $rendered = $this->templates->renderSendableTemplate('already-wrapped', $this->userId, [
            'first_name' => 'Mina',
        ]);

        $this->assertSame(1, substr_count($rendered['body_html'], 'data-crm-generated-email-shell="v1"'));
        $this->assertStringContainsString('Hi Mina', $rendered['body_html']);
    }

    private function createActiveSmartTemplateSet(): int
    {
        Database::execute(
            "INSERT INTO smart_template_sets (workspace_id, user_id, status, context_hash, context_snapshot_json, created_at, updated_at)
             VALUES (1, ?, 'active', ?, '{}', NOW(), NOW())",
            [$this->userId, uniqid('smart-set-', true)]
        );

        return (int) Database::lastInsertId();
    }
}
