<?php

namespace CRM\Services;

use CRM\Database;

class EmailTemplateLearningSampleService
{
    public function recordDraftGenerated(
        int $workspaceId,
        int $userId,
        ?int $contactId,
        string $mode,
        string $intention,
        array $draft,
        array $metadata = []
    ): int {
        if (!$this->tableReady()) {
            return 0;
        }

        $body = (string) ($draft['body_text'] ?? strip_tags((string) ($draft['body_html'] ?? '')));
        $subject = (string) ($draft['subject'] ?? '');
        $source = $mode === 'polish_existing' ? 'ai_polish_draft' : 'ai_intention_draft';

        Database::execute(
            "INSERT INTO email_template_learning_samples
                (workspace_id, user_id, contact_id, sample_kind, source, draft_mode, draft_intention,
                 subject, body_hash, body_excerpt, outcome_label, generated_at, metadata_json)
             VALUES (?, ?, ?, 'draft', ?, ?, ?, ?, ?, ?, 'generated', NOW(), ?)",
            [
                $workspaceId,
                $userId > 0 ? $userId : null,
                $contactId !== null && $contactId > 0 ? $contactId : null,
                $source,
                $mode,
                $this->excerpt($intention, 2000),
                $this->excerpt($subject, 500),
                $this->hashBody($body),
                $this->excerpt($body, 2400),
                json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]
        );

        return (int) Database::lastInsertId();
    }

    public function recordOutboundEmail(
        int $workspaceId,
        ?int $userId,
        int $contactId,
        int $emailId,
        string $subject,
        string $bodyText,
        string $bodyHtml,
        array $options = []
    ): int {
        if (!$this->tableReady() || $emailId <= 0) {
            return 0;
        }

        $sourceTemplateId = (int) ($options['source_template_id'] ?? $options['template_id'] ?? 0);
        $source = trim((string) ($options['learning_source'] ?? ''));
        if ($source === '') {
            $source = $sourceTemplateId > 0 ? 'template_send' : 'manual_outbound';
        }

        $body = trim($bodyText) !== '' ? $bodyText : strip_tags($bodyHtml);
        $metadata = [
            'source_template_slug' => (string) ($options['source_template_slug'] ?? ''),
            'draft_source' => (string) ($options['draft_source'] ?? ''),
            'sender_profile' => (string) ($options['sender_profile'] ?? $options['smtp_profile'] ?? ''),
        ];
        if (!empty($options['learning_metadata']) && is_array($options['learning_metadata'])) {
            $metadata = array_merge($metadata, $options['learning_metadata']);
        }

        Database::execute(
            "INSERT INTO email_template_learning_samples
                (workspace_id, user_id, contact_id, email_id, source_template_id, sample_kind, source,
                 intent_key, draft_mode, draft_intention, subject, body_hash, body_excerpt,
                 outcome_label, generated_at, metadata_json)
             VALUES (?, ?, ?, ?, ?, 'sent_email', ?, ?, ?, ?, ?, ?, ?, 'queued', NOW(), ?)
             ON DUPLICATE KEY UPDATE
                workspace_id = VALUES(workspace_id),
                user_id = VALUES(user_id),
                contact_id = VALUES(contact_id),
                source_template_id = VALUES(source_template_id),
                source = VALUES(source),
                intent_key = VALUES(intent_key),
                draft_mode = VALUES(draft_mode),
                draft_intention = VALUES(draft_intention),
                subject = VALUES(subject),
                body_hash = VALUES(body_hash),
                body_excerpt = VALUES(body_excerpt),
                metadata_json = VALUES(metadata_json)",
            [
                $workspaceId,
                $userId !== null && $userId > 0 ? $userId : null,
                $contactId > 0 ? $contactId : null,
                $emailId,
                $sourceTemplateId > 0 ? $sourceTemplateId : null,
                $source,
                (string) ($options['learning_intent_key'] ?? $options['template_key'] ?? ''),
                (string) ($options['draft_mode'] ?? ''),
                $this->excerpt((string) ($options['draft_intention'] ?? ''), 2000),
                $this->excerpt($subject, 500),
                $this->hashBody($body),
                $this->excerpt($body, 2400),
                json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]
        );

        $row = Database::queryOne(
            "SELECT id FROM email_template_learning_samples WHERE email_id = ? LIMIT 1",
            [$emailId]
        );

        return (int) ($row['id'] ?? 0);
    }

    public function markDraftOutcomeForSend(
        int $workspaceId,
        ?int $userId,
        int $draftSampleId,
        int $emailId,
        ?int $communicationId,
        string $subject,
        string $bodyText,
        string $bodyHtml
    ): void {
        if (!$this->tableReady() || $draftSampleId <= 0 || $emailId <= 0) {
            return;
        }

        $params = [$draftSampleId, $workspaceId];
        $userSql = '';
        if ($userId !== null && $userId > 0) {
            $userSql = 'AND (user_id = ? OR user_id IS NULL)';
            $params[] = $userId;
        }

        $sample = Database::queryOne(
            "SELECT id, body_hash, metadata_json
             FROM email_template_learning_samples
             WHERE id = ?
               AND workspace_id = ?
               {$userSql}
               AND sample_kind IN ('draft', 'outcome')
               AND source IN ('ai_intention_draft', 'ai_polish_draft', 'ai_reply_draft', 'ai_assisted_draft')
             LIMIT 1",
            $params
        );
        if (!$sample) {
            return;
        }

        $sentBody = trim($bodyText) !== '' ? $bodyText : strip_tags($bodyHtml);
        $sentBodyHash = $this->hashBody($sentBody);
        $originalBodyHash = (string) ($sample['body_hash'] ?? '');
        $outcome = $originalBodyHash !== '' && hash_equals($originalBodyHash, $sentBodyHash)
            ? 'accepted'
            : 'edited';
        $metadata = json_decode((string) ($sample['metadata_json'] ?? ''), true);
        $metadata = is_array($metadata) ? $metadata : [];
        $metadata['linked_email_id'] = $emailId;
        if ($communicationId !== null && $communicationId > 0) {
            $metadata['linked_communication_id'] = $communicationId;
        }
        $metadata['sent_subject'] = $this->excerpt($subject, 500);
        $metadata['sent_body_hash'] = $sentBodyHash;
        $metadata['sent_body_excerpt'] = $this->excerpt($sentBody, 1200);
        $metadata['outcome_recorded_at'] = date('c');

        Database::execute(
            "UPDATE email_template_learning_samples
             SET sample_kind = 'outcome',
                 outcome_label = ?,
                 communication_id = COALESCE(communication_id, ?),
                 sent_at = COALESCE(sent_at, NOW()),
                 metadata_json = ?
             WHERE id = ? AND workspace_id = ?",
            [
                $outcome,
                $communicationId !== null && $communicationId > 0 ? $communicationId : null,
                json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $draftSampleId,
                $workspaceId,
            ]
        );
    }

    public function markStaleGeneratedDraftsIgnored(int $workspaceId, ?int $userId = null, int $olderThanHours = 48): int
    {
        if (!$this->tableReady()) {
            return 0;
        }

        $cutoff = date('Y-m-d H:i:s', time() - (max(1, $olderThanHours) * 3600));
        $params = [$workspaceId, $cutoff];
        $userSql = '';
        if ($userId !== null && $userId > 0) {
            $userSql = 'AND (user_id = ? OR user_id IS NULL)';
            $params[] = $userId;
        }

        return Database::execute(
            "UPDATE email_template_learning_samples
             SET outcome_label = 'ignored'
             WHERE workspace_id = ?
               AND sample_kind = 'draft'
               AND outcome_label = 'generated'
               AND generated_at < ?
               {$userSql}
               AND source IN ('ai_intention_draft', 'ai_polish_draft', 'ai_reply_draft', 'ai_assisted_draft')",
            $params
        );
    }

    public function markEmailSent(int $emailId, ?int $communicationId = null): void
    {
        if (!$this->tableReady() || $emailId <= 0) {
            return;
        }

        $sets = ["outcome_label = 'sent'", 'sent_at = COALESCE(sent_at, NOW())'];
        $params = [];
        if ($communicationId !== null && $communicationId > 0) {
            $sets[] = 'communication_id = ?';
            $params[] = $communicationId;
        }
        $params[] = $emailId;

        Database::execute(
            "UPDATE email_template_learning_samples SET " . implode(', ', $sets) . " WHERE email_id = ?",
            $params
        );
    }

    public function markEmailEngagement(int $emailId, string $type): void
    {
        if (!$this->tableReady() || $emailId <= 0) {
            return;
        }

        if ($type === 'click') {
            Database::execute(
                "UPDATE email_template_learning_samples
                 SET outcome_label = 'clicked', clicked_at = COALESCE(clicked_at, NOW())
                 WHERE email_id = ?",
                [$emailId]
            );
            return;
        }

        if ($type === 'open') {
            Database::execute(
                "UPDATE email_template_learning_samples
                 SET outcome_label = CASE WHEN clicked_at IS NOT NULL THEN outcome_label ELSE 'opened' END,
                     opened_at = COALESCE(opened_at, NOW())
                 WHERE email_id = ?",
                [$emailId]
            );
        }
    }

    private function tableReady(): bool
    {
        return Database::tableExists('email_template_learning_samples');
    }

    private function hashBody(string $body): string
    {
        $plain = strtolower(trim((string) preg_replace('/\s+/', ' ', strip_tags($body))));
        return hash('sha256', $plain);
    }

    private function excerpt(string $value, int $limit): string
    {
        $plain = trim((string) preg_replace('/\s+/', ' ', strip_tags($value)));
        if (strlen($plain) <= $limit) {
            return $plain;
        }

        return substr($plain, 0, $limit);
    }
}
