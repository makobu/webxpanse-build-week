<?php

namespace CRM\Services;

use CRM\Database;

class CalendarBusyCacheService
{
    public function refreshAll(?int $workspaceId = null): array
    {
        if (!Database::tableExists('calendar_integrations')
            || !Database::tableExists('meeting_calendar_busy_cache')
            || !Database::columnExists('calendar_integrations', 'availability_enabled')) {
            return ['checked' => 0, 'updated' => 0, 'errors' => []];
        }

        $where = ['availability_enabled = 1'];
        $params = [];
        if ($workspaceId !== null && $workspaceId > 0) {
            $where[] = 'workspace_id = ?';
            $params[] = $workspaceId;
        }

        $rows = Database::query(
            "SELECT id
             FROM calendar_integrations
             WHERE " . implode(' AND ', $where) . "
             ORDER BY workspace_id ASC, user_id ASC, provider ASC",
            $params
        );

        $checked = 0;
        $updated = 0;
        $errors = [];
        foreach ($rows as $row) {
            $checked++;
            try {
                $result = $this->refreshIntegration((int) ($row['id'] ?? 0));
                $updated += (int) ($result['busy_count'] ?? 0);
            } catch (\Throwable $e) {
                $errors[] = 'Integration ' . (int) ($row['id'] ?? 0) . ': ' . $e->getMessage();
            }
        }

        return ['checked' => $checked, 'updated' => $updated, 'errors' => $errors];
    }

    public function refreshIntegration(int $integrationId, ?\DateTimeImmutable $from = null, ?\DateTimeImmutable $to = null): array
    {
        if (!Database::tableExists('calendar_integrations') || !Database::tableExists('meeting_calendar_busy_cache')) {
            return ['integration_id' => $integrationId, 'busy_count' => 0, 'skipped' => true];
        }

        $integration = Database::queryOne(
            "SELECT *
             FROM calendar_integrations
             WHERE id = ?
             LIMIT 1",
            [$integrationId]
        );
        if (!$integration) {
            throw new \RuntimeException('Calendar integration not found.');
        }

        $workspaceId = (int) ($integration['workspace_id'] ?? 0);
        if ($workspaceId <= 0) {
            throw new \RuntimeException('Calendar integration is missing workspace scope.');
        }

        if (Database::columnExists('calendar_integrations', 'availability_enabled') && empty($integration['availability_enabled'])) {
            return ['integration_id' => $integrationId, 'busy_count' => 0, 'skipped' => true];
        }

        $from = $from ?: new \DateTimeImmutable('now');
        $to = $to ?: $from->modify('+90 days');

        try {
            $accessToken = $this->refreshAccessTokenIfNeeded($integration);
            $provider = (string) ($integration['provider'] ?? '');
            $busy = match ($provider) {
                'google' => $this->fetchGoogleBusy($integration, $accessToken, $from, $to),
                'outlook' => $this->fetchOutlookBusy($integration, $accessToken, $from, $to),
                default => [],
            };

            Database::beginTransaction();
            try {
                Database::execute(
                    "DELETE FROM meeting_calendar_busy_cache
                     WHERE workspace_id = ?
                       AND integration_id = ?",
                    [$workspaceId, $integrationId]
                );

                foreach ($busy as $period) {
                    Database::execute(
                        "INSERT INTO meeting_calendar_busy_cache (
                            workspace_id, integration_id, source_label, busy_start, busy_end,
                            fetched_at, expires_at, status, error_message
                         ) VALUES (?, ?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL 1 HOUR), 'ok', NULL)",
                        [
                            $workspaceId,
                            $integrationId,
                            $this->sourceLabel($integration),
                            $period['start'],
                            $period['end'],
                        ]
                    );
                }

                Database::execute(
                    "UPDATE calendar_integrations
                     SET availability_last_checked_at = NOW(),
                         availability_last_error = NULL,
                         updated_at = NOW()
                     WHERE id = ?
                       AND workspace_id = ?",
                    [$integrationId, $workspaceId]
                );
                Database::commit();
            } catch (\Throwable $e) {
                Database::rollBack();
                throw $e;
            }

            return ['integration_id' => $integrationId, 'busy_count' => count($busy)];
        } catch (\Throwable $e) {
            if (Database::columnExists('calendar_integrations', 'availability_last_error')) {
                Database::execute(
                    "UPDATE calendar_integrations
                     SET availability_last_checked_at = NOW(),
                         availability_last_error = ?,
                         updated_at = NOW()
                     WHERE id = ?
                       AND workspace_id = ?",
                    [substr($e->getMessage(), 0, 2000), $integrationId, $workspaceId]
                );
            }
            throw $e;
        }
    }

    /**
     * @return list<array{start:string,end:string}>
     */
    private function fetchGoogleBusy(array $integration, string $accessToken, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $calendarId = (string) ($integration['calendar_id'] ?? 'primary');
        $payload = [
            'timeMin' => $from->format(\DateTimeInterface::ATOM),
            'timeMax' => $to->format(\DateTimeInterface::ATOM),
            'items' => [['id' => $calendarId !== '' ? $calendarId : 'primary']],
        ];

        $ch = curl_init('https://www.googleapis.com/calendar/v3/freeBusy');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($response === false || $httpCode >= 400) {
            throw new \RuntimeException('Google busy-time refresh failed: ' . (string) $response);
        }

        $decoded = json_decode((string) $response, true);
        $busyRows = (array) ($decoded['calendars'][$calendarId]['busy'] ?? $decoded['calendars']['primary']['busy'] ?? []);
        return $this->normalizeBusyRows($busyRows, 'start', 'end');
    }

    /**
     * @return list<array{start:string,end:string}>
     */
    private function fetchOutlookBusy(array $integration, string $accessToken, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $query = http_build_query([
            'startDateTime' => $from->format(\DateTimeInterface::ATOM),
            'endDateTime' => $to->format(\DateTimeInterface::ATOM),
            '$select' => 'start,end,isAllDay,showAs',
            '$top' => '1000',
        ]);
        $ch = curl_init('https://graph.microsoft.com/v1.0/me/calendar/calendarView?' . $query);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Accept: application/json',
                'Prefer: outlook.timezone="UTC"',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($response === false || $httpCode >= 400) {
            throw new \RuntimeException('Outlook busy-time refresh failed: ' . (string) $response);
        }

        $decoded = json_decode((string) $response, true);
        $busy = [];
        foreach ((array) ($decoded['value'] ?? []) as $event) {
            if (in_array((string) ($event['showAs'] ?? ''), ['free', 'workingElsewhere'], true)) {
                continue;
            }
            $start = $this->providerDateTime($event['start']['dateTime'] ?? null, $event['start']['timeZone'] ?? 'UTC');
            $end = $this->providerDateTime($event['end']['dateTime'] ?? null, $event['end']['timeZone'] ?? 'UTC');
            if ($start !== null && $end !== null && $end > $start) {
                $busy[] = ['start' => $this->formatUtc($start), 'end' => $this->formatUtc($end)];
            }
        }

        return $busy;
    }

    /**
     * @return list<array{start:string,end:string}>
     */
    private function normalizeBusyRows(array $rows, string $startKey, string $endKey): array
    {
        $busy = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $start = $this->providerDateTime($row[$startKey] ?? null, null);
            $end = $this->providerDateTime($row[$endKey] ?? null, null);
            if ($start !== null && $end !== null && $end > $start) {
                $busy[] = ['start' => $this->formatUtc($start), 'end' => $this->formatUtc($end)];
            }
        }

        return $busy;
    }

    private function refreshAccessTokenIfNeeded(array $integration): string
    {
        $vault = new OAuthTokenVault();
        $accessToken = $vault->decrypt($integration['access_token'] ?? null);
        $expiresAt = $integration['token_expires_at'] ?? null;
        if (!$expiresAt || strtotime((string) $expiresAt) >= time() + 300 || empty($integration['refresh_token'])) {
            return $accessToken;
        }

        $refreshToken = $vault->decrypt($integration['refresh_token'] ?? null);
        if ($refreshToken === '') {
            return $accessToken;
        }

        $provider = (string) ($integration['provider'] ?? '');
        $tokens = $provider === 'google'
            ? (new GoogleCalendarService())->refreshToken($refreshToken)
            : (new OutlookCalendarService())->refreshToken($refreshToken);
        $accessToken = (string) ($tokens['access_token'] ?? $accessToken);
        $nextRefreshToken = (string) ($tokens['refresh_token'] ?? $refreshToken);
        Database::execute(
            "UPDATE calendar_integrations
             SET access_token = ?,
                 refresh_token = ?,
                 token_expires_at = ?,
                 token_encrypted = 1
             WHERE id = ?
               AND workspace_id = ?",
            [
                $vault->encrypt($accessToken),
                $vault->encrypt($nextRefreshToken),
                date('Y-m-d H:i:s', time() + (int) ($tokens['expires_in'] ?? 3600)),
                (int) $integration['id'],
                (int) $integration['workspace_id'],
            ]
        );

        return $accessToken;
    }

    private function providerDateTime(mixed $value, ?string $timezone): ?\DateTimeImmutable
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        try {
            $sourceTimezone = new \DateTimeZone($timezone ?: 'UTC');
            return (new \DateTimeImmutable($value, $sourceTimezone))->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Throwable $e) {
            $timestamp = strtotime($value);
            return $timestamp !== false ? (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone('UTC')) : null;
        }
    }

    private function formatUtc(\DateTimeImmutable $dateTime): string
    {
        return $dateTime->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function sourceLabel(array $integration): string
    {
        $provider = ucfirst((string) ($integration['provider'] ?? 'Calendar'));
        $email = trim((string) ($integration['provider_account_email'] ?? ''));
        $name = trim((string) ($integration['calendar_name'] ?? $integration['calendar_id'] ?? 'Primary calendar'));

        return trim($provider . ' ' . ($email !== '' ? $email : $name));
    }
}
