<?php

namespace CRM\Services;

use CRM\Database;

class EmailTemplateLearningReadinessService
{
    public const WINDOW_DAYS = 90;
    public const MIN_SENT_EMAILS = 25;
    public const MIN_AI_DRAFTS = 10;
    public const MIN_POSITIVE_ENGAGEMENTS = 3;
    public const REFRESH_DAYS = 14;
    public const MIN_NEW_SIGNALS_FOR_REFRESH = 5;

    private WorkspaceScopeService $workspaceScope;

    public function __construct(?WorkspaceScopeService $workspaceScope = null)
    {
        $this->workspaceScope = $workspaceScope ?? new WorkspaceScopeService();
    }

    public function getReadiness(int $userId, array $profileReadiness): array
    {
        $workspaceId = $this->workspaceId();
        $metrics = $this->collectMetrics($workspaceId, $userId);
        $missing = [];

        if (empty($profileReadiness['is_ready'])) {
            foreach ((array) ($profileReadiness['missing_requirements'] ?? []) as $item) {
                $missing[] = [
                    'section' => (string) ($item['section'] ?? 'profile'),
                    'field' => (string) ($item['field'] ?? 'profile_context'),
                    'label' => (string) ($item['label'] ?? 'Business context'),
                    'message' => (string) ($item['message'] ?? 'Complete business context.'),
                ];
            }
        }

        if ($metrics['sent_email_count'] < self::MIN_SENT_EMAILS) {
            $missing[] = [
                'section' => 'email_learning',
                'field' => 'sent_email_count',
                'label' => 'Recent sent emails',
                'message' => sprintf(
                    'Send %d recent emails so the system can learn your real voice (%d/%d).',
                    self::MIN_SENT_EMAILS,
                    $metrics['sent_email_count'],
                    self::MIN_SENT_EMAILS
                ),
            ];
        }

        if ($metrics['ai_draft_count'] < self::MIN_AI_DRAFTS) {
            $missing[] = [
                'section' => 'email_learning',
                'field' => 'ai_draft_count',
                'label' => 'AI draft assists',
                'message' => sprintf(
                    'Use AI drafting or record draft outcomes %d times before generating templates (%d/%d).',
                    self::MIN_AI_DRAFTS,
                    $metrics['ai_draft_count'],
                    self::MIN_AI_DRAFTS
                ),
            ];
        }

        if ($metrics['positive_engagement_count'] < self::MIN_POSITIVE_ENGAGEMENTS) {
            $missing[] = [
                'section' => 'email_learning',
                'field' => 'positive_engagement_count',
                'label' => 'Positive email signals',
                'message' => sprintf(
                    'Collect %d opens, clicks, replies, or accepted outcomes (%d/%d).',
                    self::MIN_POSITIVE_ENGAGEMENTS,
                    $metrics['positive_engagement_count'],
                    self::MIN_POSITIVE_ENGAGEMENTS
                ),
            ];
        }

        $isReady = empty($missing);
        $state = empty($profileReadiness['is_ready'])
            ? 'pre_learning'
            : ($isReady ? 'ready' : 'learning');
        $snapshot = [
            'thresholds' => $this->thresholds(),
            'metrics' => $metrics,
            'sample_window_days' => self::WINDOW_DAYS,
        ];
        $learningHash = hash(
            'sha256',
            json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        return [
            'is_ready' => $isReady,
            'state' => $state,
            'metrics' => $metrics,
            'thresholds' => $this->thresholds(),
            'missing_requirements' => $missing,
            'learning_hash' => $learningHash,
            'metrics_snapshot' => $snapshot,
        ];
    }

    public function buildPromptBlock(int $userId, array $learningReadiness): array
    {
        $workspaceId = $this->workspaceId();
        $samples = $this->loadPromptSamples($workspaceId, $userId);
        $metrics = (array) ($learningReadiness['metrics'] ?? []);

        $lines = [
            'EMAIL LEARNING EVIDENCE:',
            'Sent emails in window: ' . (int) ($metrics['sent_email_count'] ?? 0),
            'AI draft assists/outcomes: ' . (int) ($metrics['ai_draft_count'] ?? 0),
            'Positive signals: ' . (int) ($metrics['positive_engagement_count'] ?? 0),
            'Replies detected: ' . (int) ($metrics['reply_count'] ?? 0),
            '',
            'Representative samples:',
        ];

        foreach ($samples as $index => $sample) {
            $lines[] = sprintf(
                "%d. [%s/%s] %s\n%s",
                $index + 1,
                (string) ($sample['source'] ?? 'unknown'),
                (string) ($sample['outcome_label'] ?? 'sample'),
                trim((string) ($sample['subject'] ?? 'Untitled')),
                trim((string) ($sample['body_excerpt'] ?? ''))
            );
        }

        if ($samples === []) {
            $lines[] = 'No representative sample bodies were available yet.';
        }

        return [
            'key' => 'email_learning',
            'label' => 'Email Learning Evidence',
            'content' => trim(implode("\n", $lines)),
            'data' => [
                'metrics' => $metrics,
                'thresholds' => $this->thresholds(),
                'samples' => $samples,
            ],
        ];
    }

    public function isRefreshDue(?array $activeSet, array $learningReadiness): bool
    {
        if (empty($activeSet)) {
            return false;
        }

        $createdAt = strtotime((string) ($activeSet['created_at'] ?? ''));
        if ($createdAt === false) {
            return false;
        }

        $ageDays = (time() - $createdAt) / 86400;
        $metrics = (array) ($learningReadiness['metrics'] ?? []);
        $newSignals = (int) ($metrics['new_signal_count'] ?? 0);
        $activeLearningHash = (string) ($activeSet['learning_hash'] ?? '');
        $currentLearningHash = (string) ($learningReadiness['learning_hash'] ?? '');

        return $ageDays >= self::REFRESH_DAYS
            && $newSignals >= self::MIN_NEW_SIGNALS_FOR_REFRESH
            && $currentLearningHash !== ''
            && $currentLearningHash !== $activeLearningHash;
    }

    public function thresholds(): array
    {
        return [
            'window_days' => self::WINDOW_DAYS,
            'min_sent_emails' => self::MIN_SENT_EMAILS,
            'min_ai_drafts' => self::MIN_AI_DRAFTS,
            'min_positive_engagements' => self::MIN_POSITIVE_ENGAGEMENTS,
            'refresh_days' => self::REFRESH_DAYS,
            'min_new_signals_for_refresh' => self::MIN_NEW_SIGNALS_FOR_REFRESH,
        ];
    }

    private function collectMetrics(int $workspaceId, int $userId): array
    {
        (new EmailTemplateLearningSampleService())->markStaleGeneratedDraftsIgnored($workspaceId, $userId);

        $since = date('Y-m-d H:i:s', time() - (self::WINDOW_DAYS * 86400));
        $sentEmailCount = $this->countSentEmails($workspaceId, $userId, $since);
        $sampleDraftCount = $this->countSampleDrafts($workspaceId, $userId, $since);
        $positiveEngagements = $this->countPositiveEngagements($workspaceId, $userId, $since);
        $replyCount = $this->countReplies($workspaceId, $userId, $since);
        $newSignals = $this->countNewSignals($workspaceId, $userId);

        return [
            'sent_email_count' => $sentEmailCount,
            'ai_draft_count' => $sampleDraftCount,
            'positive_engagement_count' => $positiveEngagements,
            'reply_count' => $replyCount,
            'new_signal_count' => $newSignals,
            'sample_count' => $this->countLearningSamples($workspaceId, $userId, $since),
            'window_days' => self::WINDOW_DAYS,
        ];
    }

    private function countSentEmails(int $workspaceId, int $userId, string $since): int
    {
        if (!Database::tableExists('emails')) {
            return 0;
        }

        $userFilter = Database::columnExists('emails', 'user_id')
            ? 'AND (user_id = ? OR user_id IS NULL)'
            : '';
        $params = [$workspaceId];
        if ($userFilter !== '') {
            $params[] = $userId;
        }
        $params[] = $since;

        $row = Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM emails
             WHERE workspace_id = ?
               {$userFilter}
               AND status IN ('sent','delivered','opened','clicked')
               AND COALESCE(sent_at, created_at) >= ?",
            $params
        );

        return (int) ($row['c'] ?? 0);
    }

    private function countSampleDrafts(int $workspaceId, int $userId, string $since): int
    {
        if (!Database::tableExists('email_template_learning_samples')) {
            return 0;
        }

        $row = Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM email_template_learning_samples
             WHERE workspace_id = ?
               AND (user_id = ? OR user_id IS NULL)
               AND sample_kind IN ('draft', 'outcome')
               AND source IN ('ai_intention_draft', 'ai_polish_draft', 'ai_reply_draft', 'ai_assisted_draft')
               AND COALESCE(generated_at, created_at) >= ?",
            [$workspaceId, $userId, $since]
        );

        return (int) ($row['c'] ?? 0);
    }

    private function countPositiveEngagements(int $workspaceId, int $userId, string $since): int
    {
        $signals = [];

        if (Database::tableExists('emails')) {
            $rows = Database::query(
                "SELECT id
                 FROM emails
                 WHERE workspace_id = ?
                   AND (user_id = ? OR user_id IS NULL)
                   AND COALESCE(sent_at, created_at) >= ?
                   AND (opened_at IS NOT NULL OR clicked_at IS NOT NULL OR status IN ('opened','clicked'))",
                [$workspaceId, $userId, $since]
            );
            foreach ($rows as $row) {
                $emailId = (int) ($row['id'] ?? 0);
                if ($emailId > 0) {
                    $signals['email:' . $emailId] = true;
                }
            }
        }

        if (Database::tableExists('email_template_learning_samples')) {
            $rows = Database::query(
                "SELECT id, email_id, outcome_label
                 FROM email_template_learning_samples
                 WHERE workspace_id = ?
                   AND (user_id = ? OR user_id IS NULL)
                   AND COALESCE(sent_at, generated_at, created_at) >= ?
                   AND (
                        opened_at IS NOT NULL
                        OR clicked_at IS NOT NULL
                        OR replied_at IS NOT NULL
                        OR outcome_label IN ('opened','clicked','replied','accepted','edited')
                   )",
                [$workspaceId, $userId, $since]
            );
            foreach ($rows as $row) {
                $emailId = (int) ($row['email_id'] ?? 0);
                $sampleId = (int) ($row['id'] ?? 0);
                $outcome = (string) ($row['outcome_label'] ?? '');
                if ($emailId > 0 && in_array($outcome, ['opened', 'clicked', 'replied'], true)) {
                    $signals['email:' . $emailId] = true;
                } elseif ($sampleId > 0) {
                    $signals['sample:' . $sampleId] = true;
                }
            }
        }

        if (Database::tableExists('communications') && Database::tableExists('emails')) {
            $rows = Database::query(
                "SELECT DISTINCT inbound.id
                 FROM communications inbound
                 WHERE inbound.workspace_id = ?
                   AND inbound.channel = 'email'
                   AND inbound.direction = 'inbound'
                   AND inbound.created_at >= ?
                   AND EXISTS (
                        SELECT 1
                        FROM emails e
                        WHERE e.workspace_id = inbound.workspace_id
                          AND e.contact_id = inbound.contact_id
                          AND (e.user_id = ? OR e.user_id IS NULL)
                          AND e.status IN ('sent','delivered','opened','clicked')
                          AND COALESCE(e.sent_at, e.created_at) <= inbound.created_at
                          AND COALESCE(e.sent_at, e.created_at) >= DATE_SUB(inbound.created_at, INTERVAL 21 DAY)
                   )",
                [$workspaceId, $since, $userId]
            );
            foreach ($rows as $row) {
                $replyId = (int) ($row['id'] ?? 0);
                if ($replyId > 0) {
                    $signals['reply:' . $replyId] = true;
                }
            }
        }

        return count($signals);
    }

    private function countReplies(int $workspaceId, int $userId, string $since): int
    {
        if (!Database::tableExists('communications')) {
            return 0;
        }

        $row = Database::queryOne(
            "SELECT COUNT(DISTINCT inbound.id) AS c
             FROM communications inbound
             WHERE inbound.workspace_id = ?
               AND inbound.channel = 'email'
               AND inbound.direction = 'inbound'
               AND inbound.created_at >= ?
               AND EXISTS (
                    SELECT 1
                    FROM emails e
                    WHERE e.workspace_id = inbound.workspace_id
                      AND e.contact_id = inbound.contact_id
                      AND (e.user_id = ? OR e.user_id IS NULL)
                      AND e.status IN ('sent','delivered','opened','clicked')
                      AND COALESCE(e.sent_at, e.created_at) <= inbound.created_at
                      AND COALESCE(e.sent_at, e.created_at) >= DATE_SUB(inbound.created_at, INTERVAL 21 DAY)
               )",
            [$workspaceId, $since, $userId]
        );

        return (int) ($row['c'] ?? 0);
    }

    private function countNewSignals(int $workspaceId, int $userId): int
    {
        if (!Database::tableExists('email_template_learning_samples')) {
            return 0;
        }

        $since = date('Y-m-d H:i:s', time() - (self::REFRESH_DAYS * 86400));
        $row = Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM email_template_learning_samples
             WHERE workspace_id = ?
               AND (user_id = ? OR user_id IS NULL)
               AND updated_at >= ?",
            [$workspaceId, $userId, $since]
        );

        return (int) ($row['c'] ?? 0);
    }

    private function countLearningSamples(int $workspaceId, int $userId, string $since): int
    {
        if (!Database::tableExists('email_template_learning_samples')) {
            return 0;
        }

        $row = Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM email_template_learning_samples
             WHERE workspace_id = ?
               AND (user_id = ? OR user_id IS NULL)
               AND COALESCE(sent_at, generated_at, created_at) >= ?",
            [$workspaceId, $userId, $since]
        );

        return (int) ($row['c'] ?? 0);
    }

    private function loadPromptSamples(int $workspaceId, int $userId): array
    {
        if (Database::tableExists('email_template_learning_samples')) {
            $rows = Database::query(
                "SELECT source, outcome_label, subject, body_excerpt, intent_key
                 FROM email_template_learning_samples
                 WHERE workspace_id = ?
                   AND (user_id = ? OR user_id IS NULL)
                   AND body_excerpt IS NOT NULL
                   AND body_excerpt <> ''
                 ORDER BY
                   CASE WHEN outcome_label IN ('clicked','replied','accepted') THEN 0
                        WHEN outcome_label IN ('opened','edited') THEN 1
                        ELSE 2 END,
                   updated_at DESC
                 LIMIT 12",
                [$workspaceId, $userId]
            );
            if ($rows !== []) {
                return $rows;
            }
        }

        if (!Database::tableExists('emails')) {
            return [];
        }

        $rows = Database::query(
            "SELECT
                CASE WHEN source_template_id IS NOT NULL THEN 'template_send' ELSE 'manual_outbound' END AS source,
                status AS outcome_label,
                subject,
                LEFT(COALESCE(NULLIF(body, ''), body_html, ''), 2400) AS body_excerpt,
                draft_source AS intent_key
             FROM emails
             WHERE workspace_id = ?
               AND (user_id = ? OR user_id IS NULL)
               AND status IN ('sent','delivered','opened','clicked')
             ORDER BY
                CASE WHEN status IN ('clicked','opened') THEN 0 ELSE 1 END,
                COALESCE(sent_at, created_at) DESC
             LIMIT 12",
            [$workspaceId, $userId]
        );

        return array_map(static function (array $row): array {
            $row['body_excerpt'] = trim((string) preg_replace('/\s+/', ' ', strip_tags((string) ($row['body_excerpt'] ?? ''))));
            return $row;
        }, $rows);
    }

    private function workspaceId(): int
    {
        return $this->workspaceScope->requireActiveWorkspaceId();
    }
}
