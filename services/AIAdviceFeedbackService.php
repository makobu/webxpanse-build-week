<?php

namespace CRM\Services;

use CRM\Database;

class AIAdviceFeedbackService
{
    private const VALID_SURFACES = ['coach', 'clarity_chat'];
    private const VALID_FEEDBACK_TYPES = ['useful', 'not_useful', 'acted_on', 'already_done', 'dismissed'];
    private AIWorkspaceScopeService $workspaceScope;

    public function __construct(
        private ?AIDecisionOutcomeService $outcomes = null,
        ?AIWorkspaceScopeService $workspaceScope = null
    ) {
        $this->outcomes = $this->outcomes ?? new AIDecisionOutcomeService();
        $this->workspaceScope = $workspaceScope ?? new AIWorkspaceScopeService();
    }

    public function recordCoachFeedback(array $payload): int
    {
        $payload['surface'] = 'coach';
        return $this->recordFeedback($payload);
    }

    public function recordClarityFeedback(array $payload): int
    {
        $payload['surface'] = 'clarity_chat';
        return $this->recordFeedback($payload);
    }

    public function recordFeedback(array $payload): int
    {
        $this->assertTableExists();
        $surface = (string) ($payload['surface'] ?? '');
        $feedbackType = (string) ($payload['feedback_type'] ?? '');
        $guidanceRunId = (int) ($payload['guidance_run_id'] ?? 0);
        $workspaceId = $this->workspaceScope->requireWorkspaceId();

        if (!in_array($surface, self::VALID_SURFACES, true)) {
            throw new \InvalidArgumentException('Unsupported feedback surface.');
        }
        if (!in_array($feedbackType, self::VALID_FEEDBACK_TYPES, true)) {
            throw new \InvalidArgumentException('Unsupported feedback type.');
        }
        if ($guidanceRunId <= 0) {
            throw new \InvalidArgumentException('Guidance run is required.');
        }
        if ($surface === 'coach' && trim((string) ($payload['recommendation_key'] ?? '')) === '') {
            throw new \InvalidArgumentException('Recommendation key is required for coach feedback.');
        }
        if ($surface === 'clarity_chat' && trim((string) ($payload['message_hash'] ?? '')) === '') {
            throw new \InvalidArgumentException('Message hash is required for Clarity feedback.');
        }

        $existingId = $this->hasExistingFeedback($payload);
        if ($existingId > 0) {
            return $existingId;
        }

        $run = Database::queryOne(
            "SELECT *
             FROM ai_guidance_runs
             WHERE id = ?
               AND workspace_id = ?
               AND user_id = ?
               AND surface = ?
             LIMIT 1",
            [$guidanceRunId, $workspaceId, (int) ($payload['user_id'] ?? 0), $surface]
        );
        if (!$run) {
            throw new \InvalidArgumentException('Guidance run not found for the current user.');
        }

        Database::execute(
            "INSERT INTO ai_advice_feedback
                (workspace_id, guidance_run_id, user_id, surface, feedback_type, recommendation_key, message_hash, linked_task_id, linked_contact_id, linked_deal_id, feedback_notes, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $workspaceId,
                $guidanceRunId,
                (int) ($payload['user_id'] ?? 0),
                $surface,
                $feedbackType,
                $this->nullableString($payload['recommendation_key'] ?? null),
                $this->nullableString($payload['message_hash'] ?? null),
                !empty($payload['linked_task_id']) ? (int) $payload['linked_task_id'] : null,
                !empty($payload['linked_contact_id']) ? (int) $payload['linked_contact_id'] : null,
                !empty($payload['linked_deal_id']) ? (int) $payload['linked_deal_id'] : null,
                $this->nullableString($payload['feedback_notes'] ?? null),
                json_encode((array) ($payload['metadata_json'] ?? [])),
            ]
        );
        $feedbackId = (int) Database::lastInsertId();

        $feedback = Database::queryOne("SELECT * FROM ai_advice_feedback WHERE id = ?", [$feedbackId]) ?: [];
        $outcome = $this->mapFeedbackToOutcome($feedback);
        $this->outcomes->recordGuidanceOutcome($run, $outcome);

        return $feedbackId;
    }

    public function mapFeedbackToOutcome(array $feedback): array
    {
        $feedbackType = (string) ($feedback['feedback_type'] ?? '');
        $label = match ($feedbackType) {
            'useful', 'acted_on', 'already_done' => 'accepted',
            'not_useful' => 'rejected',
            'dismissed' => 'ignored',
            default => 'ignored',
        };

        return [
            'outcome_label' => $label,
            'outcome_score' => (new AIOutcomeClassifier())->scoreOutcomeLabel($label),
            'metadata' => [
                'feedback_id' => (int) ($feedback['id'] ?? 0),
                'feedback_type' => $feedbackType,
                'recommendation_key' => $feedback['recommendation_key'] ?? null,
                'message_hash' => $feedback['message_hash'] ?? null,
                'linked_task_id' => !empty($feedback['linked_task_id']) ? (int) $feedback['linked_task_id'] : null,
                'linked_contact_id' => !empty($feedback['linked_contact_id']) ? (int) $feedback['linked_contact_id'] : null,
                'linked_deal_id' => !empty($feedback['linked_deal_id']) ? (int) $feedback['linked_deal_id'] : null,
                'feedback_notes' => $feedback['feedback_notes'] ?? null,
                'explicit_feedback' => true,
            ],
        ];
    }

    public function hasExistingFeedback(array $payload): int
    {
        $surface = (string) ($payload['surface'] ?? '');
        $feedbackType = (string) ($payload['feedback_type'] ?? '');
        $guidanceRunId = (int) ($payload['guidance_run_id'] ?? 0);
        $userId = (int) ($payload['user_id'] ?? 0);
        $workspaceId = $this->workspaceScope->requireWorkspaceId();

        if ($surface === '' || $feedbackType === '' || $guidanceRunId <= 0 || $userId <= 0) {
            return 0;
        }

        $where = [
            'workspace_id = ?',
            'guidance_run_id = ?',
            'user_id = ?',
            'surface = ?',
            'feedback_type = ?',
        ];
        $params = [$workspaceId, $guidanceRunId, $userId, $surface, $feedbackType];

        if ($surface === 'coach') {
            $where[] = 'recommendation_key = ?';
            $params[] = (string) ($payload['recommendation_key'] ?? '');
        } else {
            $where[] = 'message_hash = ?';
            $params[] = (string) ($payload['message_hash'] ?? '');
        }

        $existing = Database::queryOne(
            'SELECT id FROM ai_advice_feedback WHERE ' . implode(' AND ', $where) . ' LIMIT 1',
            $params
        );

        return (int) ($existing['id'] ?? 0);
    }

    /**
     * @param list<string> $signatures
     * @param array<string,string> $ignoreBeforeBySignature
     * @return array<string,array{accepted:int,rejected:int,dismissed:int,total:int,latest_feedback_type:string}>
     */
    public function getRecentCoachFeedbackBySignature(int $userId, array $signatures, int $days = 90, array $ignoreBeforeBySignature = []): array
    {
        $signatures = array_values(array_unique(array_filter(array_map(
            static fn($signature): string => trim((string) $signature),
            $signatures
        ))));
        if ($userId <= 0 || $signatures === []) {
            return [];
        }

        $this->assertTableExists();
        $workspaceId = $this->workspaceScope->requireWorkspaceId();
        $signatureLookup = array_fill_keys($signatures, true);
        $since = date('Y-m-d H:i:s', time() - (max(1, $days) * 86400));
        $rows = Database::query(
            "SELECT feedback_type, metadata_json, created_at
             FROM ai_advice_feedback
             WHERE workspace_id = ?
               AND user_id = ?
               AND surface = 'coach'
               AND created_at >= ?
             ORDER BY created_at DESC
             LIMIT 500",
            [$workspaceId, $userId, $since]
        );

        $summary = [];
        foreach ($rows as $row) {
            $metadata = [];
            if (!empty($row['metadata_json'])) {
                $decoded = json_decode((string) $row['metadata_json'], true);
                $metadata = is_array($decoded) ? $decoded : [];
            }
            $signature = trim((string) ($metadata['feedback_signature'] ?? ''));
            if ($signature === '' || !isset($signatureLookup[$signature])) {
                continue;
            }
            $cutoff = (string) ($ignoreBeforeBySignature[$signature] ?? '');
            if ($cutoff !== '' && strcmp((string) ($row['created_at'] ?? ''), $cutoff) <= 0) {
                continue;
            }

            if (!isset($summary[$signature])) {
                $summary[$signature] = [
                    'accepted' => 0,
                    'rejected' => 0,
                    'dismissed' => 0,
                    'total' => 0,
                    'latest_feedback_type' => '',
                ];
            }

            $type = (string) ($row['feedback_type'] ?? '');
            $summary[$signature]['total']++;
            if ($summary[$signature]['latest_feedback_type'] === '') {
                $summary[$signature]['latest_feedback_type'] = $type;
            }
            if (in_array($type, ['useful', 'acted_on', 'already_done'], true)) {
                $summary[$signature]['accepted']++;
            } elseif ($type === 'not_useful') {
                $summary[$signature]['rejected']++;
            } elseif ($type === 'dismissed') {
                $summary[$signature]['dismissed']++;
            }
        }

        return $summary;
    }

    private function nullableString($value): ?string
    {
        $string = trim((string) $value);
        return $string === '' ? null : $string;
    }

    private function assertTableExists(): void
    {
        $exists = Database::queryOne(
            "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_advice_feedback'"
        );
        if (!$exists) {
            throw new \RuntimeException('Advice feedback table is not available.');
        }
    }
}
