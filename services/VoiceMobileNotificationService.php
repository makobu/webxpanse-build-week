<?php

namespace CRM\Services;

use CRM\Database;

/**
 * Creates deduplicated Voice notifications without depending on a browser
 * session. Provider callbacks and the voice worker run outside Auth::check().
 */
class VoiceMobileNotificationService
{
    public function notifyAssigned(int $workspaceId, int $callId, int $userId): void
    {
        $call = $this->call($workspaceId, $callId);
        if ($call === [] || $userId <= 0) {
            return;
        }

        $this->createOnce(
            $workspaceId,
            $userId,
            'voice_call_assigned',
            'Incoming call assigned',
            $this->customerLabel($call) . ' is being routed to your verified phone or SIP endpoint.',
            $callId,
            'warning'
        );
    }

    public function notifyMissed(int $workspaceId, int $callId): void
    {
        $call = $this->call($workspaceId, $callId);
        $userId = (int) ($call['agent_user_id'] ?? 0);
        if ($call === [] || $userId <= 0 || (string) ($call['direction'] ?? '') !== 'inbound') {
            return;
        }

        $this->createOnce(
            $workspaceId,
            $userId,
            'voice_call_missed',
            'Missed call',
            $this->customerLabel($call) . ' was not connected. Open Call Center to review and follow up.',
            $callId,
            'warning'
        );
    }

    public function notifyFailure(int $workspaceId, int $callId): void
    {
        $call = $this->call($workspaceId, $callId);
        $userId = (int) (($call['agent_user_id'] ?? 0) ?: ($call['created_by_user_id'] ?? 0));
        if ($call === [] || $userId <= 0) {
            return;
        }

        $this->createOnce(
            $workspaceId,
            $userId,
            'voice_call_failed',
            'Call could not connect',
            $this->customerLabel($call) . ' could not be connected. Open Call Center for the current status.',
            $callId,
            'warning'
        );
    }

    public function notifyIntelligenceReady(int $workspaceId, int $callId, bool $hasInsight): void
    {
        $call = $this->call($workspaceId, $callId);
        $userId = (int) (($call['agent_user_id'] ?? 0) ?: ($call['created_by_user_id'] ?? 0));
        if ($call === [] || $userId <= 0) {
            return;
        }

        $this->createOnce(
            $workspaceId,
            $userId,
            $hasInsight ? 'voice_insight_ready' : 'voice_transcript_ready',
            $hasInsight ? 'Call insight ready' : 'Call transcript ready',
            ($hasInsight ? 'The summary and suggested follow-up for ' : 'The transcript for ')
                . $this->customerLabel($call) . ' are ready to review.',
            $callId,
            'info'
        );
    }

    private function createOnce(
        int $workspaceId,
        int $userId,
        string $type,
        string $title,
        string $message,
        int $callId,
        string $severity
    ): void {
        if ($workspaceId <= 0 || $userId <= 0 || $callId <= 0) {
            return;
        }
        try {
            $existing = Database::queryOne(
                'SELECT id FROM notifications WHERE workspace_id = ? AND user_id = ? AND type = ? AND entity_type = ? AND entity_id = ? LIMIT 1',
                [$workspaceId, $userId, $type, 'voice_call', $callId]
            );
            if ($existing) {
                return;
            }

            Database::execute(
                "INSERT INTO notifications
                    (workspace_id, user_id, type, title, message, entity_type, entity_id, link, severity)
                 VALUES (?, ?, ?, ?, ?, 'voice_call', ?, ?, ?)",
                [
                    $workspaceId,
                    $userId,
                    $type,
                    $this->plain($title, 160),
                    $this->plain($message, 1000),
                    $callId,
                    'call_center.php?call_id=' . $callId,
                    $severity,
                ]
            );
            $notificationId = (int) Database::lastInsertId();
            if ($notificationId > 0) {
                (new MobilePushNotificationService())->sendForNotification($notificationId);
            }
        } catch (\Throwable $e) {
            error_log(
                'Voice mobile notification failed for workspace_id=' . $workspaceId
                . ', call_id=' . $callId . ', type=' . $type . ': ' . $e->getMessage()
            );
        }
    }

    private function call(int $workspaceId, int $callId): array
    {
        try {
            return Database::queryOne(
                "SELECT c.*, TRIM(CONCAT(COALESCE(ct.first_name, ''), ' ', COALESCE(ct.last_name, ''))) AS contact_name
                 FROM voice_calls c
                 LEFT JOIN contacts ct ON ct.workspace_id = c.workspace_id AND ct.id = c.contact_id
                 WHERE c.workspace_id = ? AND c.id = ? LIMIT 1",
                [$workspaceId, $callId]
            ) ?: [];
        } catch (\Throwable $e) {
            error_log(
                'Voice mobile notification lookup failed for workspace_id=' . $workspaceId
                . ', call_id=' . $callId . ': ' . $e->getMessage()
            );

            return [];
        }
    }

    private function customerLabel(array $call): string
    {
        $contact = trim((string) ($call['contact_name'] ?? ''));
        if ($contact !== '') {
            return $this->plain($contact, 120);
        }
        $number = (string) (($call['direction'] ?? '') === 'inbound'
            ? ($call['from_number_masked'] ?? '')
            : ($call['to_number_masked'] ?? ''));

        return trim($number) !== '' ? $this->plain($number, 80) : 'A customer';
    }

    private function plain(string $value, int $limit): string
    {
        $value = trim((string) preg_replace('/\s+/', ' ', strip_tags($value)));

        return mb_substr($value, 0, $limit);
    }
}
