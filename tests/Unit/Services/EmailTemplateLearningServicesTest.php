<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\EmailTemplateLearningReadinessService;
use CRM\Services\EmailTemplateLearningSampleService;
use CRM\Tests\DatabaseTestCase;

class EmailTemplateLearningServicesTest extends DatabaseTestCase
{
    private int $userId;
    private int $contactId;

    protected function setUp(): void
    {
        parent::setUp();

        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, created_at)
             VALUES (?, ?, ?, 'admin', NOW())",
            [uniqid('learning-user-', true), 'learning@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $this->userId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, company, stage, created_at)
             VALUES (1, UUID(), 'Learning', 'Contact', 'learning-contact@example.com', 'Learning Co', 'qualified', NOW())"
        );
        $this->contactId = (int) Database::lastInsertId();
    }

    public function testDraftOutcomeIsMarkedAcceptedOrEditedWhenLinkedToSend(): void
    {
        $samples = new EmailTemplateLearningSampleService();

        $acceptedSampleId = $samples->recordDraftGenerated(1, $this->userId, $this->contactId, 'draft_from_intention', 'Follow up', [
            'subject' => 'Follow up',
            'body_text' => 'Same body',
        ]);
        $editedSampleId = $samples->recordDraftGenerated(1, $this->userId, $this->contactId, 'polish_existing', 'Polish this', [
            'subject' => 'Polish',
            'body_text' => 'Original body',
        ]);

        $samples->markDraftOutcomeForSend(1, $this->userId, $acceptedSampleId, 101, null, 'Follow up', 'Same body', '');
        $samples->markDraftOutcomeForSend(1, $this->userId, $editedSampleId, 102, null, 'Polish', 'Edited body', '');

        $accepted = Database::queryOne("SELECT sample_kind, outcome_label, metadata_json FROM email_template_learning_samples WHERE id = ?", [$acceptedSampleId]);
        $edited = Database::queryOne("SELECT sample_kind, outcome_label, metadata_json FROM email_template_learning_samples WHERE id = ?", [$editedSampleId]);

        $this->assertSame('outcome', $accepted['sample_kind'] ?? null);
        $this->assertSame('accepted', $accepted['outcome_label'] ?? null);
        $this->assertSame(101, (int) (json_decode((string) ($accepted['metadata_json'] ?? '{}'), true)['linked_email_id'] ?? 0));
        $this->assertSame('outcome', $edited['sample_kind'] ?? null);
        $this->assertSame('edited', $edited['outcome_label'] ?? null);
        $this->assertSame(102, (int) (json_decode((string) ($edited['metadata_json'] ?? '{}'), true)['linked_email_id'] ?? 0));
    }

    public function testStaleGeneratedDraftsAreMarkedIgnored(): void
    {
        $samples = new EmailTemplateLearningSampleService();
        $sampleId = $samples->recordDraftGenerated(1, $this->userId, $this->contactId, 'draft_from_intention', 'Old draft', [
            'subject' => 'Old draft',
            'body_text' => 'Old body',
        ]);
        Database::execute(
            "UPDATE email_template_learning_samples
             SET generated_at = DATE_SUB(NOW(), INTERVAL 3 DAY), created_at = DATE_SUB(NOW(), INTERVAL 3 DAY)
             WHERE id = ?",
            [$sampleId]
        );

        $updated = $samples->markStaleGeneratedDraftsIgnored(1, $this->userId, 48);
        $sample = Database::queryOne("SELECT outcome_label FROM email_template_learning_samples WHERE id = ?", [$sampleId]);

        $this->assertSame(1, $updated);
        $this->assertSame('ignored', $sample['outcome_label'] ?? null);
    }

    public function testPositiveEngagementReadinessDedupesEmailAndSampleSignalsAndCountsReplies(): void
    {
        Database::execute(
            "INSERT INTO emails
                (workspace_id, uuid, contact_id, user_id, to_email, from_email, from_name, subject, body, body_html, status, sent_at, opened_at, clicked_at, created_at)
             VALUES (1, UUID(), ?, ?, 'learning-contact@example.com', 'sender@example.com', 'Sender', 'Opened email', 'Body', '<p>Body</p>', 'clicked', DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_SUB(NOW(), INTERVAL 23 HOUR), DATE_SUB(NOW(), INTERVAL 22 HOUR), DATE_SUB(NOW(), INTERVAL 1 DAY))",
            [$this->contactId, $this->userId]
        );
        $emailId = (int) Database::lastInsertId();

        Database::execute(
            "INSERT INTO email_template_learning_samples
                (workspace_id, user_id, contact_id, email_id, sample_kind, source, subject, body_hash, body_excerpt, outcome_label, sent_at, opened_at, clicked_at, created_at)
             VALUES (1, ?, ?, ?, 'sent_email', 'ai_intention_draft', 'Opened email', SHA2('Body', 256), 'Body', 'clicked', DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_SUB(NOW(), INTERVAL 23 HOUR), DATE_SUB(NOW(), INTERVAL 22 HOUR), DATE_SUB(NOW(), INTERVAL 1 DAY))",
            [$this->userId, $this->contactId, $emailId]
        );
        Database::execute(
            "INSERT INTO email_template_learning_samples
                (workspace_id, user_id, contact_id, sample_kind, source, draft_mode, subject, body_hash, body_excerpt, outcome_label, generated_at, sent_at, created_at)
             VALUES (1, ?, ?, 'outcome', 'ai_polish_draft', 'polish_existing', 'Accepted draft', SHA2('Accepted', 256), 'Accepted', 'accepted', DATE_SUB(NOW(), INTERVAL 2 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY), DATE_SUB(NOW(), INTERVAL 2 DAY))",
            [$this->userId, $this->contactId]
        );
        Database::execute(
            "INSERT INTO communications (workspace_id, uuid, contact_id, channel, direction, subject, body, status, created_at)
             VALUES (1, UUID(), ?, 'email', 'inbound', 'Reply', 'Thanks for the note', 'sent', NOW())",
            [$this->contactId]
        );

        $readiness = (new EmailTemplateLearningReadinessService())->getReadiness($this->userId, [
            'is_ready' => true,
            'missing_requirements' => [],
            'context_hash' => 'profile-hash',
            'context_snapshot' => [],
        ]);

        $this->assertSame(3, (int) ($readiness['metrics']['positive_engagement_count'] ?? 0));
        $this->assertSame(1, (int) ($readiness['metrics']['reply_count'] ?? 0));
    }
}
