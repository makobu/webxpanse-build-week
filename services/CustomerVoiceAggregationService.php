<?php

namespace CRM\Services;

use CRM\Database;

class CustomerVoiceAggregationService
{
    public function aggregateInsight(int $workspaceId, int $voiceInsightId): int
    {
        $row = Database::queryOne(
            "SELECT i.*, c.consent_status, c.contact_id, cfg.customer_voice_enabled,
                    ct.first_name AS contact_first_name, ct.last_name AS contact_last_name,
                    ct.email AS contact_email, ct.phone AS contact_phone
             FROM voice_call_insights i INNER JOIN voice_calls c ON c.id = i.call_id AND c.workspace_id = i.workspace_id
             INNER JOIN workspace_voice_configs cfg ON cfg.workspace_id = i.workspace_id
             LEFT JOIN contacts ct ON ct.id = c.contact_id AND ct.workspace_id = c.workspace_id
             WHERE i.workspace_id = ? AND i.id = ? LIMIT 1",
            [$workspaceId, $voiceInsightId]
        );
        if (!$row || empty($row['customer_voice_enabled']) || (string) $row['consent_status'] !== 'granted' || empty($row['contact_id'])) {
            return 0;
        }
        $sources = [
            'pain' => $this->jsonList($row['pains_json'] ?? null),
            'goal' => $this->jsonList($row['goals_json'] ?? null),
            'objection' => $this->jsonList($row['objections_json'] ?? null),
            'requested_feature' => $this->jsonList($row['requested_actions_json'] ?? null),
        ];
        $count = 0;
        foreach ($sources as $category => $topics) {
            foreach ($topics as $topic) {
                $topic = $this->anonymize($topic, $row);
                if ($topic === '' || mb_strlen($topic) < 4) continue;
                $normalized = mb_strtolower(trim(preg_replace('/\s+/', ' ', $topic) ?? ''));
                $hash = hash('sha256', $normalized);
                Database::execute(
                    "INSERT INTO marketing_customer_voice_insights (workspace_id, category, topic_hash, topic_label, summary, confidence, first_seen_at, last_seen_at)
                     VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
                     ON DUPLICATE KEY UPDATE last_seen_at = NOW(), topic_label = VALUES(topic_label), confidence = GREATEST(COALESCE(confidence, 0), VALUES(confidence))",
                    [$workspaceId, $category, $hash, mb_substr($topic, 0, 255), $topic, (float) ($row['confidence'] ?? 0)]
                );
                $topicId = (int) (Database::queryOne('SELECT id FROM marketing_customer_voice_insights WHERE workspace_id = ? AND category = ? AND topic_hash = ?', [$workspaceId, $category, $hash])['id'] ?? 0);
                if ($topicId <= 0) continue;
                Database::execute(
                    "INSERT IGNORE INTO marketing_customer_voice_evidence (workspace_id, customer_voice_insight_id, voice_call_insight_id, contact_id, evidence_hash) VALUES (?, ?, ?, ?, ?)",
                    [$workspaceId, $topicId, $voiceInsightId, (int) $row['contact_id'], hash('sha256', $voiceInsightId . '|' . $hash)]
                );
                Database::execute(
                    "UPDATE marketing_customer_voice_insights SET
                        evidence_count = (SELECT COUNT(*) FROM marketing_customer_voice_evidence e WHERE e.workspace_id = ? AND e.customer_voice_insight_id = ?),
                        distinct_contact_count = (SELECT COUNT(DISTINCT contact_id) FROM marketing_customer_voice_evidence e WHERE e.workspace_id = ? AND e.customer_voice_insight_id = ?)
                     WHERE workspace_id = ? AND id = ?",
                    [$workspaceId, $topicId, $workspaceId, $topicId, $workspaceId, $topicId]
                );
                $count++;
            }
        }
        return $count;
    }

    public function reviewable(int $workspaceId, int $limit = 100): array
    {
        return Database::query(
            "SELECT * FROM marketing_customer_voice_insights WHERE workspace_id = ? AND distinct_contact_count >= 3 ORDER BY review_status = 'pending' DESC, confidence DESC, evidence_count DESC LIMIT " . max(1, min(200, $limit)),
            [$workspaceId]
        );
    }

    public function review(int $workspaceId, int $insightId, int $userId, string $status): void
    {
        if (!in_array($status, ['accepted', 'dismissed'], true)) {
            throw new \InvalidArgumentException('Invalid Customer Voice review status.');
        }
        Database::beginTransaction();
        try {
            $insight = Database::queryOne(
                'SELECT * FROM marketing_customer_voice_insights WHERE workspace_id = ? AND id = ? AND distinct_contact_count >= 3 FOR UPDATE',
                [$workspaceId, $insightId]
            );
            if (!$insight) {
                throw new \RuntimeException('Reviewable Customer Voice insight not found.');
            }
            $targetId = (int) ($insight['accepted_target_id'] ?? 0);
            if ($status === 'accepted' && $targetId <= 0) {
                $typeMap = [
                    'requested_feature' => 'offer',
                    'buying_language' => 'differentiator',
                    'pain' => 'content_pillar',
                    'goal' => 'content_pillar',
                    'objection' => 'content_pillar',
                    'recurring_question' => 'content_pillar',
                ];
                $itemType = $typeMap[(string) ($insight['category'] ?? '')] ?? 'content_pillar';
                $metadata = [
                    'source' => 'voice_customer_voice',
                    'customer_voice_insight_id' => $insightId,
                    'evidence_count' => (int) ($insight['evidence_count'] ?? 0),
                    'distinct_contact_count' => (int) ($insight['distinct_contact_count'] ?? 0),
                    'confidence' => (float) ($insight['confidence'] ?? 0),
                    'review_required' => true,
                ];
                Database::execute(
                    "INSERT INTO marketing_context_items
                     (workspace_id, uuid, item_type, title, body, status, metadata_json, created_by)
                     VALUES (?, ?, ?, ?, ?, 'draft', ?, ?)",
                    [$workspaceId, $this->uuid(), $itemType,
                        mb_substr('Customer Voice: ' . (string) $insight['topic_label'], 0, 255),
                        (string) ($insight['summary'] ?: $insight['topic_label']),
                        json_encode($metadata, JSON_UNESCAPED_SLASHES), $userId]
                );
                $targetId = (int) Database::lastInsertId();
            }
            Database::execute(
                "UPDATE marketing_customer_voice_insights SET review_status = ?, reviewed_by_user_id = ?, reviewed_at = NOW(),
                        accepted_target_type = ?, accepted_target_id = ?, updated_at = NOW()
                 WHERE workspace_id = ? AND id = ?",
                [$status, $userId, $status === 'accepted' ? 'candidate_marketing_context' : null,
                    $status === 'accepted' && $targetId > 0 ? $targetId : null, $workspaceId, $insightId]
            );
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    private function jsonList($value): array
    {
        $decoded = $value ? json_decode((string) $value, true) : [];
        return is_array($decoded) ? array_values(array_filter(array_map('strval', $decoded))) : [];
    }

    private function anonymize(string $text, array $context = []): string
    {
        $directValues = array_filter(array_map('trim', [
            (string) ($context['contact_first_name'] ?? ''),
            (string) ($context['contact_last_name'] ?? ''),
            trim((string) ($context['contact_first_name'] ?? '') . ' ' . (string) ($context['contact_last_name'] ?? '')),
            (string) ($context['contact_email'] ?? ''),
            (string) ($context['contact_phone'] ?? ''),
        ]), static fn(string $value): bool => mb_strlen($value) >= 2);
        if ($directValues !== []) {
            $text = str_ireplace($directValues, '[person]', $text);
        }
        $text = preg_replace('/\b[\w.%+\-]+@[\w.\-]+\.[A-Za-z]{2,}\b/u', '[email]', $text) ?? '';
        $text = preg_replace('/\+?[0-9][0-9\s().-]{7,}[0-9]/u', '[phone]', $text) ?? '';
        $text = preg_replace('/\b(?:ID|account|customer)\s*[:#-]?\s*[A-Z0-9-]{4,}\b/ui', '[identifier]', $text) ?? '';
        return trim($text);
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
