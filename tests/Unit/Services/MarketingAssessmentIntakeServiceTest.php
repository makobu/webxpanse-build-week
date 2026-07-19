<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\MarketingAssessmentIntakeService;
use CRM\Tests\DatabaseTestCase;

class MarketingAssessmentIntakeServiceTest extends DatabaseTestCase
{
    private MarketingAssessmentIntakeService $service;
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MarketingAssessmentIntakeService();

        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at) VALUES (?, ?, ?, 'owner', NOW())",
            [uniqid('assessment-user-', true), 'assessment-owner@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $this->userId = (int) Database::lastInsertId();
    }

    public function testIngestCreatesContactAndStoresAssessmentMetadata(): void
    {
        $result = $this->service->ingest($this->payload('new-lead@example.com'), $this->userId);

        $this->assertTrue($result['success']);
        $this->assertFalse($result['updated_existing']);

        $contact = Database::queryOne("SELECT * FROM contacts WHERE email = ?", ['new-lead@example.com']);
        $this->assertNotNull($contact);
        $this->assertSame('web_assessment', (string) ($contact['lead_source'] ?? ''));

        $metadata = json_decode((string) ($contact['metadata_json'] ?? '{}'), true) ?: [];
        $latest = $metadata['marketing_assessment_latest'] ?? [];
        $this->assertSame(58, (int) ($latest['score'] ?? 0));
        $this->assertSame('Momentum', (string) ($latest['readiness_band'] ?? ''));
        $this->assertSame('follow_up_consistency', (string) (($latest['answers'] ?? [])['bottleneck'] ?? ''));

        $submission = Database::queryOne(
            "SELECT form_id, contact_id FROM form_submissions WHERE contact_id = ? ORDER BY id DESC LIMIT 1",
            [(int) $contact['id']]
        );
        $this->assertNotNull($submission);
        $this->assertSame('business_ai_readiness_check', (string) ($submission['form_id'] ?? ''));

        $tag = Database::queryOne(
            "SELECT t.name FROM tags t
             INNER JOIN tag_assignments ta ON ta.tag_id = t.id
             WHERE ta.entity_type = 'contact' AND ta.entity_id = ? AND t.name = ?",
            [(int) $contact['id'], 'web-assessment-momentum']
        );
        $this->assertNotNull($tag);
    }

    public function testIngestUpdatesExistingContactInsteadOfCreatingDuplicate(): void
    {
        Database::execute(
            "INSERT INTO contacts (uuid, first_name, email, lead_source, stage, created_at) VALUES (?, ?, ?, 'form', 'new', NOW())",
            [uniqid('contact-', true), 'Existing', 'existing-lead@example.com']
        );
        $existingId = (int) Database::lastInsertId();

        $result = $this->service->ingest($this->payload('existing-lead@example.com'), $this->userId);

        $this->assertTrue($result['success']);
        $this->assertTrue($result['updated_existing']);
        $this->assertSame($existingId, (int) $result['contact_id']);

        $count = Database::queryOne("SELECT COUNT(*) AS count FROM contacts WHERE email = ?", ['existing-lead@example.com']);
        $this->assertSame(1, (int) ($count['count'] ?? 0));

        $contact = Database::queryOne("SELECT * FROM contacts WHERE id = ?", [$existingId]);
        $metadata = json_decode((string) ($contact['metadata_json'] ?? '{}'), true) ?: [];
        $this->assertSame('web_assessment', (string) ($contact['lead_source'] ?? ''));
        $this->assertSame('Momentum', (string) (($metadata['marketing_assessment_latest'] ?? [])['readiness_band'] ?? ''));
    }

    public function testIngestFallsBackToWebAssessmentForUnsupportedSourceLabel(): void
    {
        $payload = $this->payload('fallback-source@example.com');
        $payload['source'] = 'landing_page_campaign_q2';

        $result = $this->service->ingest($payload, $this->userId);

        $this->assertTrue($result['success']);
        $contact = Database::queryOne("SELECT * FROM contacts WHERE email = ?", ['fallback-source@example.com']);
        $this->assertNotNull($contact);
        $this->assertSame('web_assessment', (string) ($contact['lead_source'] ?? ''));

        $metadata = json_decode((string) ($contact['metadata_json'] ?? '{}'), true) ?: [];
        $this->assertSame('web_assessment', (string) (($metadata['marketing_assessment_latest'] ?? [])['source'] ?? ''));
    }

    private function payload(string $email): array
    {
        return [
            'source' => 'web_assessment',
            'lead' => [
                'name' => 'Mako B',
                'email' => $email,
                'phone' => '+254700000000',
                'company' => 'WebXpanse Labs',
            ],
            'summary' => [
                'score' => 58,
                'readiness_band' => 'Momentum',
                'primary_bottleneck' => 'follow_up_consistency',
                'recommended_next_step' => 'Define one clean follow-up rhythm before widening automation.',
                'top_frictions' => [
                    'Follow-up still depends too much on memory and manual effort.',
                    'Customer context is spread across tools instead of living in one operating picture.',
                ],
                'quick_wins' => [
                    'Turn follow-up into a fixed sequence instead of a founder memory test.',
                    'Pull WhatsApp, email, tasks, and pipeline notes into one operating workflow so context stops fragmenting.',
                ],
            ],
            'answers' => [
                'business_model' => 'service_sales',
                'team_shape' => 'founder_plus_small_team',
                'tool_stack' => 'crm_plus_manual',
                'response_time' => 'same_day',
                'follow_up' => 'mostly_manual',
                'bottleneck' => 'follow_up_consistency',
                'automation_comfort' => 'needs_structure',
                'growth_goal' => 'less_founder_dependence',
            ],
            'attribution' => [
                'visitor_id' => 'vis_test_' . uniqid(),
                'source_page' => '/business-ai-readiness',
                'referrer' => 'https://webxpanse.com/',
                'utm' => [
                    'utm_source' => 'linkedin',
                    'utm_medium' => 'social',
                    'utm_campaign' => 'readiness-check',
                ],
            ],
        ];
    }
}
