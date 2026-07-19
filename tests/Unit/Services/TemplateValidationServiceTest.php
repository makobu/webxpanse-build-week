<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\TemplateValidationService;
use CRM\Tests\DatabaseTestCase;

class TemplateValidationServiceTest extends DatabaseTestCase
{
    public function testCleanSeededPlatformOpsTemplatesPass(): void
    {
        $report = (new TemplateValidationService())->validate([
            'include_active_email_templates' => false,
            'include_active_public_workflows' => false,
        ]);

        $this->assertSame('ok', $report['status'] ?? null, json_encode($report['findings'] ?? []) ?: '');
        $this->assertSame(0, (int) ($report['summary']['critical'] ?? -1));
        $this->assertSame(0, (int) ($report['summary']['warning'] ?? -1));
    }

    public function testEmptyEmailSubjectAndBodyAreCritical(): void
    {
        $this->insertEmailTemplate([
            'slug' => 'phpunit-empty-template',
            'subject' => '',
            'body_html' => '',
            'body_text' => '',
            'variables' => [],
        ]);

        $report = (new TemplateValidationService())->validate();

        $this->assertHasFindingRule($report, 'empty_email_subject');
        $this->assertHasFindingRule($report, 'empty_email_body');
    }

    public function testUndeclaredEmailPlaceholderIsReported(): void
    {
        $this->insertEmailTemplate([
            'slug' => 'phpunit-undeclared-placeholder',
            'subject' => 'Hello {first_name}',
            'body_html' => '<p>Hi {first_name}, use {missing_value} today.</p>',
            'body_text' => 'Hi {first_name}, use {missing_value} today.',
            'variables' => ['first_name'],
        ]);

        $report = (new TemplateValidationService())->validate();

        $this->assertHasFindingRule($report, 'undeclared_placeholder', 'missing_value');
    }

    public function testMissingRequiredEmailPlaceholderIsCritical(): void
    {
        $this->insertEmailTemplate([
            'slug' => 'phpunit-missing-required-placeholder',
            'subject' => 'Hello {first_name}',
            'body_html' => '<p>Hi {first_name}</p>',
            'body_text' => 'Hi {first_name}',
            'variables' => ['first_name', 'support_url'],
            'match_metadata_json' => ['required_placeholders' => ['support_url']],
        ]);

        $report = (new TemplateValidationService())->validate();

        $this->assertHasFindingRule($report, 'required_placeholder_missing', 'support_url');
    }

    public function testInactiveRequiredPlatformOpsTemplateIsCritical(): void
    {
        Database::execute(
            "UPDATE email_templates SET is_active = 0 WHERE slug = 'platform-ops-owner_welcome_setup'"
        );

        $report = (new TemplateValidationService())->validate([
            'include_active_email_templates' => false,
            'include_active_public_workflows' => false,
        ]);

        $this->assertHasFindingRule($report, 'inactive_required_production_template');
    }

    public function testDemoLanguageIsReportedWithoutFlaggingNormalDemoWord(): void
    {
        $this->insertEmailTemplate([
            'slug' => 'phpunit-demo-language',
            'subject' => 'Hello {first_name}',
            'body_html' => '<p>Hi Test User, visit example.test for lorem ipsum notes from Acme.</p>',
            'body_text' => 'Hi Test User, visit example.test for lorem ipsum notes from Acme.',
            'variables' => ['first_name'],
        ]);

        $this->insertEmailTemplate([
            'slug' => 'phpunit-normal-demo-word',
            'subject' => 'Demo readiness for {first_name}',
            'body_html' => '<p>Hi {first_name}, your product demo readiness is confirmed.</p>',
            'body_text' => 'Hi {first_name}, your product demo readiness is confirmed.',
            'variables' => ['first_name'],
        ]);

        $report = (new TemplateValidationService())->validate();

        $this->assertHasFindingRule($report, 'demo_language_detected');
        foreach ((array) ($report['findings'] ?? []) as $finding) {
            if (!is_array($finding) || ($finding['slug'] ?? '') !== 'phpunit-normal-demo-word') {
                continue;
            }
            $this->assertNotSame('demo_language_detected', $finding['rule'] ?? null);
        }
    }

    public function testWorkflowSendEmailRequiredPlaceholderValidation(): void
    {
        Database::execute(
            "INSERT INTO workflow_templates
                (name, description, category, trigger_config, conditions, actions, variables, is_public, is_active, template_key, recipe_metadata_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                'PHPUnit Workflow Missing Placeholder',
                'Validates required placeholders in send-email workflow actions.',
                'platform_ops',
                json_encode(['type' => 'scheduled_review']),
                json_encode([]),
                json_encode([
                    [
                        'type' => 'send_email',
                        'template_query' => [
                            'required_variables' => ['workspace_name', 'meeting_link'],
                        ],
                        'subject' => 'Review {workspace_name}',
                        'body' => 'Review {workspace_name} today.',
                    ],
                ]),
                json_encode(['workspace_name', 'meeting_link']),
                1,
                1,
                'phpunit_workflow_missing_required',
                json_encode(['seed_source' => 'phpunit']),
            ]
        );

        $report = (new TemplateValidationService())->validate();

        $this->assertHasFindingRule($report, 'workflow_required_placeholder_missing', 'meeting_link');
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function insertEmailTemplate(array $overrides): void
    {
        $defaults = [
            'workspace_id' => 1,
            'name' => 'PHPUnit Template',
            'slug' => 'phpunit-template-' . bin2hex(random_bytes(4)),
            'subject' => 'Hello {first_name}',
            'body_html' => '<p>Hi {first_name}</p>',
            'body_text' => 'Hi {first_name}',
            'category' => 'platform_ops',
            'variables' => ['first_name'],
            'is_active' => 1,
            'is_library' => 0,
            'purpose' => 'platform_ops',
            'match_metadata_json' => null,
        ];
        $values = array_replace($defaults, $overrides);

        Database::execute(
            "INSERT INTO email_templates
                (workspace_id, name, slug, subject, body_html, body_text, category, variables, is_active, is_library, purpose, match_metadata_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $values['workspace_id'],
                $values['name'],
                $values['slug'],
                $values['subject'],
                $values['body_html'],
                $values['body_text'],
                $values['category'],
                json_encode($values['variables']),
                (int) $values['is_active'],
                (int) $values['is_library'],
                $values['purpose'],
                $values['match_metadata_json'] === null ? null : json_encode($values['match_metadata_json']),
            ]
        );
    }

    /**
     * @param array<string,mixed> $report
     */
    private function assertHasFindingRule(array $report, string $rule, ?string $placeholder = null): void
    {
        foreach ((array) ($report['findings'] ?? []) as $finding) {
            if (!is_array($finding) || ($finding['rule'] ?? '') !== $rule) {
                continue;
            }
            if ($placeholder === null || ($finding['placeholder'] ?? '') === $placeholder) {
                $this->addToAssertionCount(1);
                return;
            }
        }

        $this->fail('Expected finding rule ' . $rule . ' in ' . (json_encode($report['findings'] ?? []) ?: '[]'));
    }
}
