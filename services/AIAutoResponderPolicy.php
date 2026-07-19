<?php
/**
 * AI Auto-responder Policy Engine
 * Applies compliance and safety checks before/after generation.
 */

namespace CRM\Services;

class AIAutoResponderPolicy
{
    public function evaluateInbound(array $payload, array $config): array
    {
        $text = strtolower(trim((string) ($payload['message_text'] ?? '')));
        $channel = strtolower((string) ($payload['channel'] ?? 'email'));

        if ($text === '') {
            return ['decision' => 'skip', 'reason_code' => 'empty_message', 'notes' => 'No content to reply to.'];
        }

        $channelConfig = $config['channels'][$channel] ?? null;
        if (!$channelConfig || empty($channelConfig['enabled'])) {
            return ['decision' => 'skip', 'reason_code' => 'channel_disabled', 'notes' => "Channel {$channel} disabled."];
        }

        if ($this->isQuietHours($config)) {
            return ['decision' => 'review', 'reason_code' => 'quiet_hours', 'notes' => 'Configured quiet hours active.'];
        }

        $safety = $config['safety'] ?? [];
        $optOutKeywords = $this->normalizeKeywordList($safety['opt_out_keywords'] ?? []);
        if ($this->containsAnyKeyword($text, $optOutKeywords)) {
            return ['decision' => 'blocked', 'reason_code' => 'opt_out_detected', 'notes' => 'Opt-out keyword detected.'];
        }

        $escalationKeywords = $this->normalizeKeywordList($safety['escalation_keywords'] ?? []);
        if (!empty($safety['require_human_for_sensitive_intents']) && $this->containsAnyKeyword($text, $escalationKeywords)) {
            return ['decision' => 'review', 'reason_code' => 'sensitive_intent', 'notes' => 'Sensitive keyword requires human review.'];
        }

        return ['decision' => 'continue', 'reason_code' => 'ok', 'notes' => 'Policy checks passed.'];
    }

    public function enforceDailyRateLimit(int $contactId, array $config): bool
    {
        $maxPerDay = (int) (($config['safety']['max_auto_replies_per_contact_per_day'] ?? 5));
        $maxPerDay = max(1, min(100, $maxPerDay));

        $count = \CRM\Database::queryOne(
            "SELECT COUNT(*) AS total
             FROM ai_autoresponder_logs
             WHERE contact_id = ?
               AND decision = 'auto_sent'
               AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)",
            [$contactId]
        );

        return ((int) ($count['total'] ?? 0)) < $maxPerDay;
    }

    private function isQuietHours(array $config): bool
    {
        $quiet = $config['quiet_hours'] ?? [];
        if (empty($quiet['enabled'])) {
            return false;
        }

        $start = (string) ($quiet['start'] ?? '20:00');
        $end = (string) ($quiet['end'] ?? '08:00');
        $timezone = trim((string) ($quiet['timezone'] ?? 'UTC')) ?: 'UTC';

        try {
            $tz = new \DateTimeZone($timezone);
        } catch (\Exception $e) {
            $tz = new \DateTimeZone('UTC');
        }

        $now = new \DateTimeImmutable('now', $tz);
        $today = $now->format('Y-m-d');
        $startDt = \DateTimeImmutable::createFromFormat('Y-m-d H:i', "{$today} {$start}", $tz);
        $endDt = \DateTimeImmutable::createFromFormat('Y-m-d H:i', "{$today} {$end}", $tz);
        if (!$startDt || !$endDt) {
            return false;
        }

        if ($startDt <= $endDt) {
            return $now >= $startDt && $now <= $endDt;
        }

        return $now >= $startDt || $now <= $endDt;
    }

    /**
     * @param array<int, string> $keywords
     */
    private function containsAnyKeyword(string $text, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if ($keyword !== '' && strpos($text, $keyword) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param mixed $raw
     * @return array<int, string>
     */
    private function normalizeKeywordList(mixed $raw): array
    {
        if (is_string($raw)) {
            $parts = preg_split('/[\r\n,]+/', strtolower($raw), -1, PREG_SPLIT_NO_EMPTY);
            return array_values(array_unique(array_map('trim', $parts ?: [])));
        }
        if (is_array($raw)) {
            $normalized = [];
            foreach ($raw as $item) {
                $text = trim(strtolower((string) $item));
                if ($text !== '') {
                    $normalized[] = $text;
                }
            }
            return array_values(array_unique($normalized));
        }
        return [];
    }
}
