<?php
/**
 * Touchpoint Ingestion Service
 *
 * Normalizes channel events into touchpoints and maintains identity links.
 */

namespace CRM\Services;

use CRM\Database;

class TouchpointIngestionService
{
    public function ingestTouchpoint(array $data): int
    {
        $contactId = (int) ($data['contact_id'] ?? 0);
        if ($contactId <= 0) {
            throw new \InvalidArgumentException('touchpoint contact_id is required');
        }

        Database::execute(
            "INSERT INTO touchpoints
             (contact_id, visitor_id, campaign_id, source_table, source_id, channel, touch_type, occurred_at,
              utm_source, utm_medium, utm_campaign, utm_term, utm_content, value_amount, metadata)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $contactId,
                !empty($data['visitor_id']) ? (string) $data['visitor_id'] : null,
                !empty($data['campaign_id']) ? (int) $data['campaign_id'] : null,
                !empty($data['source_table']) ? (string) $data['source_table'] : null,
                !empty($data['source_id']) ? (int) $data['source_id'] : null,
                (string) ($data['channel'] ?? 'activity'),
                (string) ($data['touch_type'] ?? 'event'),
                !empty($data['occurred_at']) ? (string) $data['occurred_at'] : date('Y-m-d H:i:s'),
                $data['utm_source'] ?? null,
                $data['utm_medium'] ?? null,
                $data['utm_campaign'] ?? null,
                $data['utm_term'] ?? null,
                $data['utm_content'] ?? null,
                isset($data['value_amount']) ? (float) $data['value_amount'] : null,
                isset($data['metadata']) ? json_encode($data['metadata']) : null
            ]
        );
        $touchpointId = (int) Database::lastInsertId();
        $this->refreshContactTouchSummary($contactId);
        return $touchpointId;
    }

    public function linkVisitorToContact(string $visitorId, int $contactId, string $confidence = 'high'): void
    {
        if ($visitorId === '' || $contactId <= 0) {
            return;
        }

        Database::execute(
            "INSERT INTO visitor_identity_links (visitor_id, contact_id, confidence)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE confidence = VALUES(confidence), linked_at = CURRENT_TIMESTAMP",
            [$visitorId, $contactId, $confidence]
        );

        $this->backfillVisitorData($visitorId, $contactId);
    }

    public function backfillVisitorData(string $visitorId, int $contactId): void
    {
        Database::execute(
            "UPDATE page_views
             SET contact_id = ?
             WHERE visitor_id = ? AND (contact_id IS NULL OR contact_id = 0)",
            [$contactId, $visitorId]
        );

        Database::execute(
            "UPDATE form_submissions
             SET contact_id = ?
             WHERE visitor_id = ? AND (contact_id IS NULL OR contact_id = 0)",
            [$contactId, $visitorId]
        );

        $pageViews = Database::query(
            "SELECT id, viewed_at, utm_source, utm_medium, utm_campaign, utm_term, utm_content, page_path
             FROM page_views
             WHERE visitor_id = ? AND contact_id = ?",
            [$visitorId, $contactId]
        );
        foreach ($pageViews as $pv) {
            $exists = Database::queryOne(
                "SELECT id FROM touchpoints
                 WHERE source_table = 'page_views' AND source_id = ? LIMIT 1",
                [$pv['id']]
            );
            if ($exists) {
                continue;
            }
            $this->ingestTouchpoint([
                'contact_id' => $contactId,
                'visitor_id' => $visitorId,
                'source_table' => 'page_views',
                'source_id' => (int) $pv['id'],
                'channel' => 'web',
                'touch_type' => 'page_view',
                'occurred_at' => $pv['viewed_at'],
                'utm_source' => $pv['utm_source'],
                'utm_medium' => $pv['utm_medium'],
                'utm_campaign' => $pv['utm_campaign'],
                'utm_term' => $pv['utm_term'],
                'utm_content' => $pv['utm_content'],
                'metadata' => ['page_path' => $pv['page_path']]
            ]);
        }
    }

    private function refreshContactTouchSummary(int $contactId): void
    {
        $first = Database::queryOne(
            "SELECT utm_source, utm_campaign
             FROM touchpoints
             WHERE contact_id = ?
               AND (utm_source IS NOT NULL OR utm_campaign IS NOT NULL)
             ORDER BY occurred_at ASC
             LIMIT 1",
            [$contactId]
        );

        $last = Database::queryOne(
            "SELECT utm_source, utm_campaign
             FROM touchpoints
             WHERE contact_id = ?
               AND (utm_source IS NOT NULL OR utm_campaign IS NOT NULL)
             ORDER BY occurred_at DESC
             LIMIT 1",
            [$contactId]
        );

        Database::execute(
            "UPDATE contacts
             SET first_touch_source = ?,
                 first_touch_campaign = ?,
                 last_touch_source = ?,
                 last_touch_campaign = ?
             WHERE id = ?",
            [
                $first['utm_source'] ?? null,
                $first['utm_campaign'] ?? null,
                $last['utm_source'] ?? null,
                $last['utm_campaign'] ?? null,
                $contactId
            ]
        );
    }
}
