<?php
/**
 * Google Calendar Service
 */

namespace CRM\Services;

use CRM\Database;
use CRM\Services\WorkspaceContext;

class GoogleCalendarService
{
    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;
    private string $apiUrl = 'https://www.googleapis.com/calendar/v3';
    
    public function __construct()
    {
        $this->clientId = $_ENV['GOOGLE_CALENDAR_CLIENT_ID'] ?? '';
        $this->clientSecret = $_ENV['GOOGLE_CALENDAR_CLIENT_SECRET'] ?? '';
        $this->redirectUri = ($_ENV['APP_URL'] ?? 'http://localhost/crm') . '/api/calendar/google/callback.php';
    }
    
    /**
     * Get OAuth authorization URL
     */
    public function getAuthUrl(int $userId, string $grantType = GoogleOAuthScopeCatalog::GRANT_CALENDAR_IMPORT, array $context = []): string
    {
        $grantType = GoogleOAuthScopeCatalog::normalizeGrantType($grantType);
        if (!in_array($grantType, [GoogleOAuthScopeCatalog::GRANT_CALENDAR_IMPORT, GoogleOAuthScopeCatalog::GRANT_CALENDAR_WRITE], true)) {
            $grantType = GoogleOAuthScopeCatalog::GRANT_CALENDAR_IMPORT;
        }
        $catalog = GoogleOAuthScopeCatalog::forGrant($grantType);
        $state = bin2hex(random_bytes(16));
        $_SESSION['google_calendar_state'] = [
            'state' => $state,
            'user_id' => $userId,
            'workspace_id' => (int) ($context['workspace_id'] ?? WorkspaceContext::currentWorkspaceId() ?? 0),
            'provider' => 'google',
            'grant_type' => $grantType,
            'purpose' => $this->normalizePurpose((string) ($context['purpose'] ?? 'sync')),
            'return_to' => $this->sanitizeReturnTo((string) ($context['return_to'] ?? '')),
            'requested_scopes' => $catalog['scopes'],
            'oauth_client_key' => $catalog['client_key'],
        ];
        $_SESSION['google_calendar_user_id'] = $userId;
        
        $params = [
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', $catalog['scopes']),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state
        ];
        
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
    }

    /**
     * @return array{user_id:int,workspace_id:int,provider:string,grant_type:string,purpose:string,return_to:string,requested_scopes:array<int,string>,oauth_client_key:string}
     */
    public function consumeAuthorizedContext(string $state): array
    {
        $state = trim($state);
        $stored = $_SESSION['google_calendar_state'] ?? null;
        $legacyUserId = (int) ($_SESSION['google_calendar_user_id'] ?? 0);
        unset($_SESSION['google_calendar_state'], $_SESSION['google_calendar_user_id']);

        if (is_array($stored)) {
            $expectedState = trim((string) ($stored['state'] ?? ''));
            $userId = (int) ($stored['user_id'] ?? $legacyUserId);
            $grantType = GoogleOAuthScopeCatalog::normalizeGrantType((string) ($stored['grant_type'] ?? GoogleOAuthScopeCatalog::GRANT_CALENDAR_IMPORT));
            $catalog = GoogleOAuthScopeCatalog::forGrant($grantType);

            if ($state === '' || $state !== $expectedState) {
                throw new \RuntimeException('Invalid OAuth state for Google Calendar.');
            }
            if ($userId <= 0) {
                throw new \RuntimeException('The OAuth user context for Google Calendar is missing.');
            }

            return [
                'user_id' => $userId,
                'workspace_id' => (int) ($stored['workspace_id'] ?? 0),
                'provider' => 'google',
                'grant_type' => $grantType,
                'purpose' => $this->normalizePurpose((string) ($stored['purpose'] ?? 'sync')),
                'return_to' => $this->sanitizeReturnTo((string) ($stored['return_to'] ?? '')),
                'requested_scopes' => GoogleOAuthScopeCatalog::parseScopes($stored['requested_scopes'] ?? $catalog['scopes']),
                'oauth_client_key' => trim((string) ($stored['oauth_client_key'] ?? $catalog['client_key'])),
            ];
        }

        $expectedState = trim((string) $stored);
        if ($state === '' || $state !== $expectedState) {
            throw new \RuntimeException('Invalid OAuth state for Google Calendar.');
        }
        if ($legacyUserId <= 0) {
            throw new \RuntimeException('The OAuth user context for Google Calendar is missing.');
        }

        $catalog = GoogleOAuthScopeCatalog::forGrant(GoogleOAuthScopeCatalog::GRANT_CALENDAR_IMPORT);
        return [
            'user_id' => $legacyUserId,
            'workspace_id' => (int) (WorkspaceContext::currentWorkspaceId() ?? 0),
            'provider' => 'google',
            'grant_type' => GoogleOAuthScopeCatalog::GRANT_CALENDAR_IMPORT,
            'purpose' => 'sync',
            'return_to' => '',
            'requested_scopes' => $catalog['scopes'],
            'oauth_client_key' => $catalog['client_key'],
        ];
    }

    public function fetchAccountEmail(string $accessToken): string
    {
        $ch = curl_init('https://www.googleapis.com/oauth2/v2/userinfo');
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
        return is_array($payload) ? trim((string) ($payload['email'] ?? '')) : '';
    }
    
    /**
     * Exchange authorization code for tokens
     */
    public function exchangeCodeForTokens(string $code): array
    {
        $data = [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->redirectUri
        ];
        
        $ch = curl_init('https://oauth2.googleapis.com/token');
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
            throw new \RuntimeException("Google OAuth error: " . $response);
        }
        
        return json_decode($response, true);
    }
    
    /**
     * Refresh access token
     */
    public function refreshToken(string $refreshToken): array
    {
        $data = [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token'
        ];
        
        $ch = curl_init('https://oauth2.googleapis.com/token');
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
    
    /**
     * Sync events from Google Calendar
     */
    public function syncFromGoogle(int $integrationId, string $accessToken, ?int $workspaceId = null): array
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
        
        $calendarId = $integration['calendar_id'] ?? 'primary';
        $url = "{$this->apiUrl}/calendars/{$calendarId}/events";
        
        $ch = curl_init($url . '?' . http_build_query([
            'timeMin' => date('c', strtotime('-30 days')),
            'timeMax' => date('c', strtotime('+90 days')),
            'singleEvents' => 'true',
            'orderBy' => 'startTime'
        ]));
        
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
            throw new \RuntimeException("Google Calendar API error: " . $response);
        }
        
        $data = json_decode($response, true);
        $synced = [];
        
        foreach ($data['items'] ?? [] as $event) {
            $eventId = $this->createOrUpdateEventFromGoogle($event, (int) $integration['user_id'], $integrationId, $resolvedWorkspaceId);
            $synced[] = $eventId;
        }
        
        // Update last sync time
        Database::execute(
            "UPDATE calendar_integrations
             SET last_sync_at = NOW()
             WHERE id = ?
               AND workspace_id = ?",
            [$integrationId, $resolvedWorkspaceId]
        );
        
        return ['synced' => $synced, 'count' => count($synced)];
    }
    
    /**
     * Sync events to Google Calendar
     */
    public function syncToGoogle(int $integrationId, string $accessToken, array $eventIds, ?int $workspaceId = null): array
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
        
        $calendarId = $integration['calendar_id'] ?? 'primary';
        $synced = [];
        
        foreach ($eventIds as $eventId) {
            $event = Database::queryOne(
                "SELECT *
                 FROM events
                 WHERE id = ?
                   AND workspace_id = ?",
                [$eventId, $resolvedWorkspaceId]
            );
            
            if ($event) {
                $googleEventId = $this->createOrUpdateGoogleEvent($event, $calendarId, $accessToken);
                $synced[] = $googleEventId;
            }
        }
        
        return ['synced' => $synced, 'count' => count($synced)];
    }
    
    /**
     * Create or update event from Google Calendar
     */
    private function createOrUpdateEventFromGoogle(array $googleEvent, int $userId, int $integrationId, int $workspaceId): int
    {
        $googleId = (string) ($googleEvent['id'] ?? '');
        $etag = (string) ($googleEvent['etag'] ?? '');
        $integration = Database::queryOne(
            "SELECT calendar_id FROM calendar_integrations WHERE id = ? AND workspace_id = ? LIMIT 1",
            [$integrationId, $workspaceId]
        ) ?: [];
        $calendarId = (string) ($integration['calendar_id'] ?? 'primary');

        $existing = $this->findExistingImportedEvent($workspaceId, $calendarId, $googleId, 'google_event_id');
        $customFields = json_encode(['google_event_id' => $googleId, 'integration_id' => $integrationId]);
        
        $startPayload = is_array($googleEvent['start'] ?? null) ? $googleEvent['start'] : [];
        $endPayload = is_array($googleEvent['end'] ?? null) ? $googleEvent['end'] : [];
        $isAllDay = empty($startPayload['dateTime']) && !empty($startPayload['date']);
        $startTime = $this->normalizeProviderDateTime($startPayload['dateTime'] ?? null, $startPayload['timeZone'] ?? null)
            ?? $this->normalizeProviderDate($startPayload['date'] ?? null, false)
            ?? date('Y-m-d H:i:s');
        $endTime = $this->normalizeProviderDateTime($endPayload['dateTime'] ?? null, $endPayload['timeZone'] ?? null)
            ?? $this->normalizeProviderDate($endPayload['date'] ?? null, $isAllDay)
            ?? ($isAllDay ? date('Y-m-d 23:59:59', strtotime($startTime)) : $startTime);
        if (strtotime($endTime) < strtotime($startTime)) {
            $endTime = $isAllDay ? date('Y-m-d 23:59:59', strtotime($startTime)) : $startTime;
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
                $googleEvent['summary'] ?? 'Untitled Event',
                $googleEvent['description'] ?? null,
                $startTime,
                $endTime,
                $googleEvent['location'] ?? null,
                $isAllDay ? 1 : 0,
                $userId,
            ];
            if (Database::columnExists('events', 'custom_fields')) {
                $updates[] = 'custom_fields = ?';
                $params[] = $customFields;
            }
            $this->appendSyncIdentityUpdate($updates, $params, 'google', $calendarId, $googleId, $etag, 'provider');
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
        } else {
            $fields = ['workspace_id', 'title', 'description', 'start_time', 'end_time', 'location', 'event_type', 'assigned_to', 'created_by', 'is_all_day'];
            $values = [$workspaceId, $googleEvent['summary'] ?? 'Untitled Event', $googleEvent['description'] ?? null, $startTime, $endTime, $googleEvent['location'] ?? null, 'meeting', $userId, $userId, $isAllDay ? 1 : 0];
            if (Database::columnExists('events', 'custom_fields')) {
                $fields[] = 'custom_fields';
                $values[] = $customFields;
            }
            $this->appendSyncIdentityInsert($fields, $values, 'google', $calendarId, $googleId, $etag, 'provider');
            Database::execute(
                "INSERT INTO events (" . implode(', ', $fields) . ") VALUES (" . implode(', ', array_fill(0, count($fields), '?')) . ")",
                $values
            );
            return (int) Database::lastInsertId();
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
    
    /**
     * Create or update Google Calendar event
     */
    private function createOrUpdateGoogleEvent(array $event, string $calendarId, string $accessToken): string
    {
        $customFields = [];
        if (!empty($event['custom_fields'])) {
            $decoded = json_decode((string) $event['custom_fields'], true);
            $customFields = is_array($decoded) ? $decoded : [];
        }
        $googleEventId = null;
        if (!empty($event['external_event_id']) && (string) ($event['calendar_provider'] ?? '') === 'google') {
            $googleEventId = (string) $event['external_event_id'];
        } elseif ($customFields !== []) {
            $googleEventId = $customFields['google_event_id'] ?? null;
        }

        $googleEvent = $this->buildGoogleEventPayload($event, $customFields);
        $createGoogleMeet = isset($googleEvent['conferenceData']);

        $url = "{$this->apiUrl}/calendars/" . rawurlencode((string) $calendarId) . "/events";
        $method = 'POST';

        if ($googleEventId) {
            $url .= "/" . rawurlencode((string) $googleEventId);
            $method = 'PUT';
        }
        if ($createGoogleMeet) {
            $url .= '?conferenceDataVersion=1';
        }
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => json_encode($googleEvent),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
                'Accept: application/json'
            ]
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode >= 400) {
            throw new \RuntimeException("Google Calendar API error: " . $response);
        }
        
        $result = json_decode($response, true);
        $externalId = (string) ($result['id'] ?? $googleEventId ?? '');
        if (!empty($event['id']) && $externalId !== '') {
            $updates = [];
            $params = [];
            $this->appendSyncIdentityUpdate($updates, $params, 'google', $calendarId, $externalId, (string) ($result['etag'] ?? ''), 'crm');
            if (Database::columnExists('events', 'custom_fields')) {
                $customFields['google_event_id'] = $externalId;
                $updates[] = 'custom_fields = ?';
                $params[] = json_encode($customFields);
            }
            $joinUrl = trim((string) ($result['hangoutLink'] ?? ''));
            if ($joinUrl === '' && is_array($result['conferenceData']['entryPoints'] ?? null)) {
                foreach ($result['conferenceData']['entryPoints'] as $entryPoint) {
                    if (is_array($entryPoint) && (string) ($entryPoint['entryPointType'] ?? '') === 'video') {
                        $joinUrl = trim((string) ($entryPoint['uri'] ?? ''));
                        if ($joinUrl !== '') {
                            break;
                        }
                    }
                }
            }
            if ($joinUrl !== '') {
                $updates[] = 'location = ?';
                $params[] = $joinUrl;
            }
            if ($updates !== []) {
                $params[] = (int) $event['id'];
                $params[] = (int) ($event['workspace_id'] ?? 0);
                Database::execute(
                    "UPDATE events SET " . implode(', ', $updates) . " WHERE id = ? AND workspace_id = ?",
                    $params
                );
            }
        }

        return $externalId;
    }

    /**
     * @param array<string,mixed> $event
     * @param array<string,mixed> $customFields
     * @return array<string,mixed>
     */
    private function buildGoogleEventPayload(array $event, array $customFields): array
    {
        $eventTimezoneName = trim((string) ($customFields['timezone'] ?? date_default_timezone_get()));
        try {
            $eventTimezone = new \DateTimeZone($eventTimezoneName);
        } catch (\Throwable $e) {
            $eventTimezoneName = date_default_timezone_get() ?: 'UTC';
            $eventTimezone = new \DateTimeZone($eventTimezoneName);
        }
        $eventStart = new \DateTimeImmutable((string) $event['start_time'], $eventTimezone);
        $eventEnd = new \DateTimeImmutable((string) ($event['end_time'] ?? $event['start_time']), $eventTimezone);

        $payload = [
            'summary' => $event['title'],
            'description' => $event['description'] ?? '',
            'location' => $event['location'] ?? '',
            'start' => [
                'dateTime' => $eventStart->format(\DateTimeInterface::ATOM),
                'timeZone' => $eventTimezoneName,
            ],
            'end' => [
                'dateTime' => $eventEnd->format(\DateTimeInterface::ATOM),
                'timeZone' => $eventTimezoneName,
            ],
        ];

        if ((string) ($customFields['meeting_format'] ?? '') === 'google_meet') {
            $payload['conferenceData'] = [
                'createRequest' => [
                    'requestId' => 'crm-booking-' . (int) ($event['workspace_id'] ?? 0) . '-' . (int) ($event['id'] ?? 0),
                    'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
                ],
            ];
        }

        return $payload;
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

    private function findExistingImportedEvent(int $workspaceId, string $calendarId, string $externalId, string $legacyKey): ?array
    {
        if ($externalId === '') {
            return null;
        }

        if (Database::columnExists('events', 'external_event_id')) {
            $existing = Database::queryOne(
                "SELECT id, assigned_to
                 FROM events
                 WHERE workspace_id = ?
                   AND calendar_provider = 'google'
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
                [$workspaceId, '%"' . $legacyKey . '":"' . str_replace(['%', '_'], ['\\%', '\\_'], $externalId) . '"%']
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
}
