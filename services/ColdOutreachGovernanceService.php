<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\ColdOutreachWarmupConfig;

class ColdOutreachGovernanceService
{
    private ColdOutreachWarmupConfig $config;

    public function __construct(?ColdOutreachWarmupConfig $config = null)
    {
        $this->config = $config ?? new ColdOutreachWarmupConfig();
    }

    public function planDispatch(string $channel, int $contactId, ?string $requestedScheduledAt = null, string $sourceType = 'manual', array $metadata = []): array
    {
        $channel = $this->normalizeChannel($channel);
        $contactId = (int) $contactId;
        if ($contactId <= 0) {
            return $this->baseResult($channel, null, false);
        }

        $config = $this->config->get($channel);
        $requestedScheduledAt = $this->normalizeDateTime($requestedScheduledAt);
        $requestedTimestamp = $requestedScheduledAt ? strtotime($requestedScheduledAt) : false;
        $requestedDate = $requestedTimestamp ? date('Y-m-d', $requestedTimestamp) : date('Y-m-d');
        $requestedTime = $requestedTimestamp ? date('H:i:s', $requestedTimestamp) : date('H:i:s');
        $isCold = $this->isColdFirstOutreach($channel, $contactId);

        if (!$config['enabled'] || !$isCold || (int) ($config['current_daily_cold_limit'] ?? 0) <= 0) {
            return $this->baseResult($channel, $requestedScheduledAt, $isCold, $config);
        }

        $reservation = $this->reserveNextAvailableSlot(
            $channel,
            $contactId,
            $requestedDate,
            $requestedTime,
            (int) ($config['current_daily_cold_limit'] ?? 0),
            $sourceType,
            $metadata
        );

        return [
            'channel' => $channel,
            'is_cold' => true,
            'is_governed' => true,
            'was_deferred' => $reservation['reserved_for_date'] !== $requestedDate,
            'scheduled_at' => $reservation['scheduled_at'],
            'reservation_id' => $reservation['reservation_id'],
            'reserved_for_date' => $reservation['reserved_for_date'],
            'current_daily_cold_limit' => (int) ($config['current_daily_cold_limit'] ?? 0),
            'config' => $config,
        ];
    }

    public function isColdFirstOutreach(string $channel, int $contactId): bool
    {
        $channel = $this->normalizeChannel($channel);
        $contactId = (int) $contactId;
        if ($contactId <= 0) {
            return false;
        }

        if ($channel === 'email') {
            $emailHistory = Database::queryOne(
                "SELECT id
                 FROM emails
                 WHERE contact_id = ?
                 LIMIT 1",
                [$contactId]
            );
            if ($emailHistory) {
                return false;
            }

            $communicationHistory = Database::queryOne(
                "SELECT id
                 FROM communications
                 WHERE contact_id = ?
                   AND channel = 'email'
                 LIMIT 1",
                [$contactId]
            );

            return !$communicationHistory;
        }

        $messageHistory = Database::queryOne(
            "SELECT id
             FROM whatsapp_messages
             WHERE contact_id = ?
             LIMIT 1",
            [$contactId]
        );
        if ($messageHistory) {
            return false;
        }

        $communicationHistory = Database::queryOne(
            "SELECT id
             FROM communications
             WHERE contact_id = ?
               AND channel = 'whatsapp'
             LIMIT 1",
            [$contactId]
        );

        return !$communicationHistory;
    }

    public function attachReservation(int $reservationId, ?int $sourceId = null, ?string $sourceUuid = null): void
    {
        if ($reservationId <= 0) {
            return;
        }

        Database::execute(
            "UPDATE cold_outreach_reservations
             SET source_id = COALESCE(?, source_id),
                 source_uuid = COALESCE(?, source_uuid),
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = ?",
            [$sourceId, $sourceUuid, $reservationId]
        );
    }

    public function markReservationStatus(int $reservationId, string $status): void
    {
        if ($reservationId <= 0) {
            return;
        }

        $status = in_array($status, ['reserved', 'sent', 'failed', 'cancelled'], true) ? $status : 'reserved';
        Database::execute(
            "UPDATE cold_outreach_reservations
             SET reservation_status = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ?",
            [$status, $reservationId]
        );
    }

    public function markByEntity(string $channel, int $sourceId, string $status): void
    {
        if ($sourceId <= 0) {
            return;
        }

        $channel = $this->normalizeChannel($channel);
        $status = in_array($status, ['reserved', 'sent', 'failed', 'cancelled'], true) ? $status : 'reserved';
        Database::execute(
            "UPDATE cold_outreach_reservations
             SET reservation_status = ?, updated_at = CURRENT_TIMESTAMP
             WHERE channel = ?
               AND source_id = ?",
            [$status, $channel, $sourceId]
        );
    }

    public function getTodayUsage(string $channel): array
    {
        return $this->config->getUsageSummary($channel, date('Y-m-d'));
    }

    private function reserveNextAvailableSlot(
        string $channel,
        int $contactId,
        string $requestedDate,
        string $requestedTime,
        int $dailyLimit,
        string $sourceType,
        array $metadata
    ): array {
        Database::beginTransaction();
        try {
            Database::queryOne(
                "SELECT channel
                 FROM cold_outreach_warmup_config
                 WHERE channel = ?
                 FOR UPDATE",
                [$channel]
            );

            $date = $requestedDate;
            $attempts = 0;
            while ($attempts < 365) {
                $row = Database::queryOne(
                    "SELECT COUNT(*) AS c
                     FROM cold_outreach_reservations
                     WHERE channel = ?
                       AND reserved_for_date = ?
                       AND reservation_status IN ('reserved', 'sent')
                     FOR UPDATE",
                    [$channel, $date]
                );
                $count = (int) ($row['c'] ?? 0);
                if ($count < $dailyLimit) {
                    $scheduledAt = $date . ' ' . $requestedTime;
                    if ($date === date('Y-m-d') && strtotime($scheduledAt) < time()) {
                        $scheduledAt = date('Y-m-d H:i:s', time() + 60);
                    }

                    Database::execute(
                        "INSERT INTO cold_outreach_reservations
                            (channel, contact_id, reserved_for_date, scheduled_at, reservation_status, source_type, metadata_json)
                         VALUES (?, ?, ?, ?, 'reserved', ?, ?)",
                        [
                            $channel,
                            $contactId,
                            $date,
                            $scheduledAt,
                            $sourceType,
                            json_encode($metadata),
                        ]
                    );

                    $reservationId = (int) Database::lastInsertId();
                    Database::commit();

                    return [
                        'reservation_id' => $reservationId,
                        'reserved_for_date' => $date,
                        'scheduled_at' => $scheduledAt,
                    ];
                }

                $date = date('Y-m-d', strtotime($date . ' +1 day'));
                $attempts++;
            }

            throw new \RuntimeException('No cold outreach slot available within the next year.');
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    private function normalizeChannel(string $channel): string
    {
        $channel = strtolower(trim($channel));
        if (!in_array($channel, ['email', 'whatsapp'], true)) {
            throw new \InvalidArgumentException('Unsupported cold outreach channel.');
        }

        return $channel;
    }

    private function normalizeDateTime(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d H:i:s', $timestamp);
    }

    private function baseResult(string $channel, ?string $scheduledAt, bool $isCold, array $config = []): array
    {
        return [
            'channel' => $channel,
            'is_cold' => $isCold,
            'is_governed' => !empty($config['enabled']),
            'was_deferred' => false,
            'scheduled_at' => $scheduledAt,
            'reservation_id' => null,
            'reserved_for_date' => $scheduledAt ? date('Y-m-d', strtotime($scheduledAt)) : date('Y-m-d'),
            'current_daily_cold_limit' => (int) ($config['current_daily_cold_limit'] ?? 0),
            'config' => $config,
        ];
    }
}
