<?php
/**
 * Outlook/Microsoft Calendar Service
 * Uses Microsoft Graph API
 */

namespace CRM\Services;

use CRM\Database;
use CRM\Services\WorkspaceContext;

class OutlookCalendarService
{
    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;
    private string $apiUrl = 'https://graph.microsoft.com/v1.0';

    public function __construct()
    {
        $this->clientId = $_ENV['MICROSOFT_CALENDAR_CLIENT_ID'] ?? '';
        $this->clientSecret = $_ENV['MICROSOFT_CALENDAR_CLIENT_SECRET'] ?? '';
        $this->redirectUri = ($_ENV['APP_URL'] ?? 'http://localhost/crm') . '/api/calendar/outlook/callback.php';
    }

    public function getAuthUrl(int $userId, array $context = []): string
    {
        $state = bin2hex(random_bytes(16));
        $_SESSION['outlook_calendar_state'] = [
            'state' => $state,
            'user_id' => $userId,
            'workspace_id' => (int) ($context['workspace_id'] ?? WorkspaceContext::currentWorkspaceId() ?? 0),
            'provider' => 'outlook',
            'grant_type' => 'calendar_import',
            'purpose' => $this->normalizePurpose((string) ($context['purpose'] ?? 'sync')),
            'return_to' => $this->sanitizeReturnTo((string) ($context['return_to'] ?? '')),
        ];
        $_SESSION['outlook_calendar_user_id'] = $userId;

        $params = [
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => 'offline_access Calendars.Read User.Read',
            'response_mode' => 'query',
            'state' => $state
        ];

        return 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize?' . http_build_query($params);
    }

    /**
     * @return array{user_id:int,workspace_id:int,provider:string,grant_type:string,purpose:string,return_to:string}
     */
    public function consumeAuthorizedContext(string $state): array
    {
        $state = trim($state);
        $stored = $_SESSION['outlook_calendar_state'] ?? null;
        $legacyUserId = (int) ($_SESSION['outlook_calendar_user_id'] ?? 0);
        unset($_SESSION['outlook_calendar_state'], $_SESSION['outlook_calendar_user_id']);

        if (is_array($stored)) {
            $expectedState = trim((string) ($stored['state'] ?? ''));
            $userId = (int) ($stored['user_id'] ?? $legacyUserId);
            if ($state === '' || $state !== $expectedState) {
                throw new \RuntimeException('Invalid OAuth state for Outlook Calendar.');
            }
            if ($userId <= 0) {
                throw new \RuntimeException('The OAuth user context for Outlook Calendar is missing.');
            }

            return [
                'user_id' => $userId,
                'workspace_id' => (int) ($stored['workspace_id'] ?? 0),
                'provider' => 'outlook',
                'grant_type' => 'calendar_import',
                'purpose' => $this->normalizePurpose((string) ($stored['purpose'] ?? 'sync')),
                'return_to' => $this->sanitizeReturnTo((string) ($stored['return_to'] ?? '')),
            ];
        }

        if ($state === '' || $state !== trim((string) $stored)) {
            throw new \RuntimeException('Invalid OAuth state for Outlook Calendar.');
        }
        if ($legacyUserId <= 0) {
            throw new \RuntimeException('The OAuth user context for Outlook Calendar is missing.');
        }

        return [
            'user_id' => $legacyUserId,
            'workspace_id' => (int) (WorkspaceContext::currentWorkspaceId() ?? 0),
            'provider' => 'outlook',
            'grant_type' => 'calendar_import',
            'purpose' => 'sync',
            'return_to' => '',
        ];
    }

    public function fetchAccountEmail(string $accessToken): string
    {
        $ch = curl_init($this->apiUrl . '/me?$select=mail,userPrincipalName');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Accept: application/json',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode >= 400) {
            return '';
        }

        $payload = json_decode((string) $response, true);
        if (!is_array($payload)) {
            return '';
        }

        return trim((string) ($payload['mail'] ?? $payload['userPrincipalName'] ?? ''));
    }

    public function exchangeCodeForTokens(string $code): array
    {
        $data = [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->redirectUri
        ];

        $ch = curl_init('https://login.microsoftonline.com/common/oauth2/v2.0/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded']
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400) {
            throw new \RuntimeException("Microsoft OAuth error: " . $response);
        }

        return json_decode($response, true);
    }

    public function refreshToken(string $refreshToken): array
    {
        $data = [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token'
        ];

        $ch = curl_init('https://login.microsoftonline.com/common/oauth2/v2.0/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded']
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400) {
            throw new \RuntimeException("Token refresh error: " . $response);
        }

        return json_decode($response, true);
    }

    public function syncFromOutlook(int $integrationId, string $accessToken, ?int $workspaceId = null): array
    {
        $resolvedWorkspaceId = $this->requireWorkspaceId($workspaceId);
        $integration = Database::queryOne(
            "SELECT *
             FROM calendar_integrations
             WHERE id = ?
               AND workspace_id = ?",
            [$integrationId, $resolvedWorkspaceId]
        );

        if (!$integration) {
            throw new \Exception("Integration not found");
        }

        $start = date('c', strtotime('-30 days'));
        $end = date('c', strtotime('+90 days'));
        $url = $this->apiUrl . '/me/calendar/calendarView?startDateTime=' . urlencode($start) . '&endDateTime=' . urlencode($end) . '&$orderby=start/dateTime';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Accept: application/json'
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400) {
            throw new \RuntimeException("Microsoft Graph API error: " . $response);
        }

        $data = json_decode($response, true);
        $synced = [];

        foreach ($data['value'] ?? [] as $event) {
            $eventId = $this->createOrUpdateEventFromOutlook($event, (int) $integration['user_id'], $integrationId, $resolvedWorkspaceId);
            $synced[] = $eventId;
        }

        Database::execute(
            "UPDATE calendar_integrations
             SET last_sync_at = NOW()
             WHERE id = ?
               AND workspace_id = ?",
            [$integrationId, $resolvedWorkspaceId]
        );

        return ['synced' => $synced, 'count' => count($synced)];
    }

    private function createOrUpdateEventFromOutlook(array $outlookEvent, int $userId, int $integrationId, int $workspaceId): int
    {
        $outlookId = (string) ($outlookEvent['id'] ?? '');
        $existing = $this->findExistingImportedEvent($workspaceId, $outlookId);

        $customFields = json_encode(['outlook_event_id' => $outlookId, 'integration_id' => $integrationId]);

        $startPayload = is_array($outlookEvent['start'] ?? null) ? $outlookEvent['start'] : [];
        $endPayload = is_array($outlookEvent['end'] ?? null) ? $outlookEvent['end'] : [];
        $startRaw = (string) ($startPayload['dateTime'] ?? $startPayload['date'] ?? '');
        $isAllDay = !empty($outlookEvent['isAllDay']) || (strpos($startRaw, 'T') === false && $startRaw !== '');
        $start = $this->normalizeProviderDateTime($startPayload['dateTime'] ?? null, $startPayload['timeZone'] ?? null)
            ?? $this->normalizeProviderDate($startPayload['date'] ?? null, false)
            ?? date('Y-m-d H:i:s');
        $end = $this->normalizeProviderDateTime($endPayload['dateTime'] ?? null, $endPayload['timeZone'] ?? null)
            ?? $this->normalizeProviderDate($endPayload['date'] ?? null, $isAllDay)
            ?? ($isAllDay ? date('Y-m-d 23:59:59', strtotime($start)) : $start);
        if (strtotime($end) < strtotime($start)) {
            $end = $isAllDay ? date('Y-m-d 23:59:59', strtotime($start)) : $start;
        }

        if ($existing) {
            $updates = [
                'title = ?',
                'description = ?',
                'start_time = ?',
                'end_time = ?',
                'location = ?',
                'is_all_day = ?',
                'assigned_to = COALESCE(assigned_to, ?)',
            ];
            $params = [
                $outlookEvent['subject'] ?? 'Untitled Event',
                $outlookEvent['body']['content'] ?? null,
                $start,
                $end,
                $outlookEvent['location']['displayName'] ?? null,
                $isAllDay ? 1 : 0,
                $userId,
            ];
            if (Database::columnExists('events', 'custom_fields')) {
                $updates[] = 'custom_fields = ?';
                $params[] = $customFields;
            }
            $this->appendSyncIdentityUpdate($updates, $params, 'outlook', 'primary', $outlookId, (string) ($outlookEvent['changeKey'] ?? ''), 'provider');
            $params[] = $existing['id'];
            $params[] = $workspaceId;
            Database::execute(
                "UPDATE events
                 SET " . implode(', ', $updates) . "
                 WHERE id = ?
                   AND workspace_id = ?",
                $params
            );
            return $existing['id'];
        }

        $fields = ['workspace_id', 'title', 'description', 'start_time', 'end_time', 'location', 'event_type', 'assigned_to', 'created_by', 'is_all_day'];
        $values = [$workspaceId, $outlookEvent['subject'] ?? 'Untitled Event', $outlookEvent['body']['content'] ?? null, $start, $end, $outlookEvent['location']['displayName'] ?? null, 'meeting', $userId, $userId, $isAllDay ? 1 : 0];
        if (Database::columnExists('events', 'custom_fields')) {
            $fields[] = 'custom_fields';
            $values[] = $customFields;
        }
        $this->appendSyncIdentityInsert($fields, $values, 'outlook', 'primary', $outlookId, (string) ($outlookEvent['changeKey'] ?? ''), 'provider');
        Database::execute(
            "INSERT INTO events (" . implode(', ', $fields) . ") VALUES (" . implode(', ', array_fill(0, count($fields), '?')) . ")",
            $values
        );
        return (int) Database::lastInsertId();
    }

    private function findExistingImportedEvent(int $workspaceId, string $externalId): ?array
    {
        if ($externalId === '') {
            return null;
        }

        if (Database::columnExists('events', 'external_event_id')) {
            $existing = Database::queryOne(
                "SELECT id, assigned_to
                 FROM events
                 WHERE workspace_id = ?
                   AND calendar_provider = 'outlook'
                   AND external_event_id = ?
                 LIMIT 1",
                [$workspaceId, $externalId]
            );
            if ($existing) {
                return $existing;
            }
        }

        if (Database::columnExists('events', 'custom_fields')) {
            return Database::queryOne(
                "SELECT id, assigned_to
                 FROM events
                 WHERE workspace_id = ?
                   AND custom_fields LIKE ?
                 LIMIT 1",
                [$workspaceId, '%"outlook_event_id":"' . str_replace(['%', '_'], ['\\%', '\\_'], $externalId) . '"%']
            );
        }

        return null;
    }

    private function appendSyncIdentityInsert(array &$fields, array &$values, string $provider, string $calendarId, string $externalId, string $etag, string $origin): void
    {
        foreach ([
            'calendar_provider' => $provider,
            'external_calendar_id' => $calendarId,
            'external_event_id' => $externalId,
            'external_event_etag' => $etag,
            'sync_origin' => $origin,
            'last_synced_at' => date('Y-m-d H:i:s'),
        ] as $column => $value) {
            if (Database::columnExists('events', $column)) {
                $fields[] = $column;
                $values[] = $value;
            }
        }
    }

    private function appendSyncIdentityUpdate(array &$updates, array &$params, string $provider, string $calendarId, string $externalId, string $etag, string $origin): void
    {
        foreach ([
            'calendar_provider' => $provider,
            'external_calendar_id' => $calendarId,
            'external_event_id' => $externalId,
            'external_event_etag' => $etag,
            'sync_origin' => $origin,
            'last_synced_at' => date('Y-m-d H:i:s'),
        ] as $column => $value) {
            if (Database::columnExists('events', $column)) {
                $updates[] = $column . ' = ?';
                $params[] = $value;
            }
        }
    }

    private function normalizeProviderDateTime(?string $value, ?string $timezone = null): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        try {
            $sourceTimezone = new \DateTimeZone($timezone ?: date_default_timezone_get());
            $dateTime = new \DateTimeImmutable($value, $sourceTimezone);
            return $dateTime->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            $timestamp = strtotime($value);
            return $timestamp !== false ? date('Y-m-d H:i:s', $timestamp) : null;
        }
    }

    private function normalizeProviderDate(?string $value, bool $exclusiveEnd): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        try {
            $dateTime = new \DateTimeImmutable($value . ' 00:00:00', new \DateTimeZone(date_default_timezone_get()));
            if ($exclusiveEnd) {
                $dateTime = $dateTime->modify('-1 second');
            }

            return $dateTime->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function requireWorkspaceId(?int $workspaceId = null): int
    {
        $resolvedWorkspaceId = $workspaceId ?? (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        if ($resolvedWorkspaceId <= 0) {
            throw new \RuntimeException('Calendar sync requires an active workspace.');
        }

        return $resolvedWorkspaceId;
    }

    private function normalizePurpose(string $purpose): string
    {
        return in_array($purpose, ['availability', 'sync', 'both'], true) ? $purpose : 'sync';
    }

    private function sanitizeReturnTo(string $returnTo): string
    {
        $returnTo = trim($returnTo);
        if ($returnTo === '' || str_starts_with($returnTo, 'http://') || str_starts_with($returnTo, 'https://') || str_contains($returnTo, "\n")) {
            return '';
        }

        return str_starts_with($returnTo, '/') ? $returnTo : '';
    }
}
