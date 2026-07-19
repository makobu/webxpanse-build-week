<?php

namespace CRM\Services;

use CRM\Database;

class CalendarSyncService
{
    public function syncIntegration(int $integrationId): array
    {
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

        return AsyncWorkspaceRunner::runWithWorkspace($workspaceId, function () use ($integration, $workspaceId): array {
            $provider = (string) ($integration['provider'] ?? '');
            $direction = $this->normalizeDirection($provider, (string) ($integration['sync_direction'] ?? 'to_crm'));
            $accessToken = $this->refreshAccessTokenIfNeeded($integration);
            $summary = [
                'success' => true,
                'integration_id' => (int) $integration['id'],
                'workspace_id' => $workspaceId,
                'provider' => $provider,
                'direction' => $direction,
                'imported' => 0,
                'exported' => 0,
                'warnings' => [],
            ];

            if (in_array($direction, ['to_crm', 'both'], true)) {
                if ($provider === 'google' && !$this->supportsCalendarImport($integration)) {
                    $summary['warnings'][] = 'Google Calendar import requires an import-capable grant.';
                    $this->recordAudit($workspaceId, (int) $integration['id'], $provider, 'import', 'import_events', 'warning', null, null, 'Google Calendar import requires an import-capable grant.');
                } else {
                    try {
                        $result = $this->syncImport($integration, $accessToken, $workspaceId);
                        $summary['imported'] = (int) ($result['count'] ?? 0);
                        $this->recordAudit($workspaceId, (int) $integration['id'], $provider, 'import', 'import_events', 'success', null, null, 'Imported ' . $summary['imported'] . ' event(s).', $result);
                    } catch (\Throwable $e) {
                        $summary['success'] = false;
                        $summary['warnings'][] = $e->getMessage();
                        $this->recordAudit($workspaceId, (int) $integration['id'], $provider, 'import', 'import_events', 'failed', null, null, $e->getMessage());
                    }
                }
            }

            if ($provider === 'google' && in_array($direction, ['from_crm', 'both'], true)) {
                if (!$this->supportsCalendarWrite($integration)) {
                    $summary['warnings'][] = 'Google outbound sync requires reconnecting with Calendar write access.';
                    $this->recordAudit($workspaceId, (int) $integration['id'], $provider, 'export', 'export_events', 'warning', null, null, 'Google outbound sync requires reconnecting with Calendar write access.');
                } else {
                    try {
                        $eventIds = $this->eligibleOutboundEventIds($workspaceId, $integration);
                        if ($eventIds !== []) {
                            $google = new GoogleCalendarService();
                            $result = $google->syncToGoogle((int) $integration['id'], $accessToken, $eventIds, $workspaceId);
                            $summary['exported'] = (int) ($result['count'] ?? 0);
                        }
                        $this->recordAudit($workspaceId, (int) $integration['id'], $provider, 'export', 'export_events', 'success', null, null, 'Exported ' . $summary['exported'] . ' event(s).', ['event_ids' => $eventIds ?? []]);
                    } catch (\Throwable $e) {
                        $summary['success'] = false;
                        $summary['warnings'][] = $e->getMessage();
                        $this->recordAudit($workspaceId, (int) $integration['id'], $provider, 'export', 'export_events', 'failed', null, null, $e->getMessage());
                    }
                }
            } elseif ($provider !== 'google' && in_array($direction, ['from_crm', 'both'], true)) {
                $summary['warnings'][] = ucfirst($provider) . ' outbound sync is not available yet.';
                $this->recordAudit($workspaceId, (int) $integration['id'], $provider, 'export', 'export_events', 'warning', null, null, ucfirst($provider) . ' outbound sync is not available yet.');
            }

            return $summary;
        });
    }

    public function syncOutboundForEvent(int $workspaceId, int $eventId): array
    {
        if ($workspaceId <= 0 || $eventId <= 0) {
            return ['success' => false, 'reason' => 'missing_scope'];
        }

        $event = Database::queryOne(
            "SELECT id, assigned_to, created_by
             FROM events
             WHERE id = ?
               AND workspace_id = ?
             LIMIT 1",
            [$eventId, $workspaceId]
        );
        if (!$event) {
            return ['success' => false, 'reason' => 'event_not_found'];
        }

        $ownerUserId = (int) ($event['assigned_to'] ?? 0);
        if ($ownerUserId <= 0) {
            $ownerUserId = (int) ($event['created_by'] ?? 0);
        }
        if ($ownerUserId <= 0) {
            return ['success' => true, 'exported' => 0, 'reason' => 'event_has_no_owner'];
        }

        $integrations = Database::query(
            "SELECT *
             FROM calendar_integrations
             WHERE workspace_id = ?
               AND user_id = ?
               AND provider = 'google'
               AND sync_enabled = 1
               AND sync_direction IN ('from_crm', 'both')",
            [$workspaceId, $ownerUserId]
        );
        if ($integrations === []) {
            return ['success' => true, 'exported' => 0, 'reason' => 'no_google_outbound_integration'];
        }

        $exported = 0;
        $warnings = [];
        foreach ($integrations as $integration) {
            try {
                if (!$this->supportsCalendarWrite($integration)) {
                    $warnings[] = 'Google outbound sync requires reconnecting with Calendar write access.';
                    $this->recordAudit($workspaceId, (int) $integration['id'], 'google', 'export', 'export_event', 'warning', $eventId, null, 'Google outbound sync requires reconnecting with Calendar write access.');
                    continue;
                }
                $accessToken = $this->refreshAccessTokenIfNeeded($integration);
                $result = (new GoogleCalendarService())->syncToGoogle((int) $integration['id'], $accessToken, [$eventId], $workspaceId);
                $exported += (int) ($result['count'] ?? 0);
                $this->recordAudit($workspaceId, (int) $integration['id'], 'google', 'export', 'export_event', 'success', $eventId, null, 'Exported CRM event to Google.', $result);
            } catch (\Throwable $e) {
                $warnings[] = $e->getMessage();
                $this->recordAudit($workspaceId, (int) $integration['id'], 'google', 'export', 'export_event', 'failed', $eventId, null, $e->getMessage());
            }
        }

        return ['success' => $warnings === [], 'exported' => $exported, 'warnings' => $warnings];
    }

    public function normalizeDirection(string $provider, string $direction): string
    {
        $direction = in_array($direction, ['both', 'to_crm', 'from_crm'], true) ? $direction : 'to_crm';
        if ($provider !== 'google' && $direction !== 'to_crm') {
            return 'to_crm';
        }

        return $direction;
    }

    public function providerDirectionOptions(string $provider): array
    {
        if ($provider === 'google') {
            return [
                'to_crm' => 'Import only',
                'from_crm' => 'CRM to Google',
                'both' => 'Two-way',
            ];
        }

        return ['to_crm' => 'Import only'];
    }

    public function healthForWorkspace(int $workspaceId): array
    {
        $integrations = Database::query(
            "SELECT ci.*,
                    latest.status AS latest_status,
                    latest.message AS latest_message,
                    latest.created_at AS latest_audit_at
             FROM calendar_integrations ci
             LEFT JOIN (
                SELECT l1.*
                FROM calendar_sync_audit_log l1
                JOIN (
                    SELECT integration_id, MAX(id) AS id
                    FROM calendar_sync_audit_log
                    WHERE workspace_id = ?
                    GROUP BY integration_id
                ) latest_ids ON latest_ids.id = l1.id
             ) latest ON latest.integration_id = ci.id
             WHERE ci.workspace_id = ?
             ORDER BY ci.provider ASC, ci.id ASC",
            [$workspaceId, $workspaceId]
        );

        $recentAudit = $this->recentAudit($workspaceId, 8);
        $hasError = false;
        foreach ($integrations as $integration) {
            if ((string) ($integration['latest_status'] ?? '') === 'failed') {
                $hasError = true;
                break;
            }
        }

        return [
            'integrations' => $integrations,
            'recent_audit' => $recentAudit,
            'status' => $hasError ? 'warning' : ($integrations === [] ? 'not_connected' : 'healthy'),
            'worker_hint' => 'Run cli/calendar_sync_worker.php every 15 minutes.',
        ];
    }

    public function recentAudit(int $workspaceId, int $limit = 20): array
    {
        return Database::query(
            "SELECT l.*, ci.calendar_name, ci.provider AS integration_provider
             FROM calendar_sync_audit_log l
             LEFT JOIN calendar_integrations ci ON ci.id = l.integration_id AND ci.workspace_id = l.workspace_id
             WHERE l.workspace_id = ?
             ORDER BY l.created_at DESC, l.id DESC
             LIMIT " . max(1, min(100, $limit)),
            [$workspaceId]
        );
    }

    public function recordAudit(
        int $workspaceId,
        ?int $integrationId,
        ?string $provider,
        string $direction,
        string $operation,
        string $status,
        ?int $eventId = null,
        ?string $externalEventId = null,
        ?string $message = null,
        ?array $payload = null
    ): void {
        if (!Database::tableExists('calendar_sync_audit_log')) {
            return;
        }

        Database::execute(
            "INSERT INTO calendar_sync_audit_log (
                workspace_id, integration_id, provider, direction, operation, status,
                event_id, external_event_id, message, payload_json
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $workspaceId,
                $integrationId,
                $provider,
                in_array($direction, ['import', 'export', 'two_way', 'health'], true) ? $direction : 'health',
                $operation,
                in_array($status, ['success', 'warning', 'failed'], true) ? $status : 'warning',
                $eventId,
                $externalEventId,
                $message,
                $payload !== null ? json_encode($payload) : null,
            ]
        );
    }

    private function syncImport(array $integration, string $accessToken, int $workspaceId): array
    {
        return match ((string) ($integration['provider'] ?? '')) {
            'google' => (new GoogleCalendarService())->syncFromGoogle((int) $integration['id'], $accessToken, $workspaceId),
            'outlook' => (new OutlookCalendarService())->syncFromOutlook((int) $integration['id'], $accessToken, $workspaceId),
            default => ['synced' => [], 'count' => 0],
        };
    }

    private function refreshAccessTokenIfNeeded(array $integration): string
    {
        $vault = new OAuthTokenVault();
        $accessToken = $vault->decrypt($integration['access_token'] ?? null);
        $expiresAt = $integration['token_expires_at'] ?? null;
        if (!$expiresAt || strtotime((string) $expiresAt) >= time() + 300 || empty($integration['refresh_token'])) {
            return $accessToken;
        }

        $provider = (string) ($integration['provider'] ?? '');
        $refreshToken = $vault->decrypt($integration['refresh_token'] ?? null);
        if ($refreshToken === '') {
            return $accessToken;
        }

        $tokens = $provider === 'google'
            ? (new GoogleCalendarService())->refreshToken($refreshToken)
            : (new OutlookCalendarService())->refreshToken($refreshToken);
        $accessToken = (string) ($tokens['access_token'] ?? $accessToken);
        $expiresIn = (int) ($tokens['expires_in'] ?? 3600);
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
                $vault->encrypt((string) ($tokens['refresh_token'] ?? $refreshToken)),
                date('Y-m-d H:i:s', time() + $expiresIn),
                (int) $integration['id'],
                (int) $integration['workspace_id'],
            ]
        );

        return $accessToken;
    }

    private function supportsCalendarImport(array $integration): bool
    {
        $grantType = GoogleOAuthScopeCatalog::normalizeGrantType((string) ($integration['oauth_grant_type'] ?? GoogleOAuthScopeCatalog::GRANT_LEGACY_CALENDAR));
        return GoogleOAuthScopeCatalog::grantSupportsCalendarImport($grantType);
    }

    private function supportsCalendarWrite(array $integration): bool
    {
        $grantType = GoogleOAuthScopeCatalog::normalizeGrantType((string) ($integration['oauth_grant_type'] ?? GoogleOAuthScopeCatalog::GRANT_LEGACY_CALENDAR));
        return GoogleOAuthScopeCatalog::grantSupportsCalendarWrite($grantType);
    }

    private function eligibleOutboundEventIds(int $workspaceId, array $integration): array
    {
        $lastSyncAt = (string) ($integration['last_sync_at'] ?? '');
        $ownerUserId = (int) ($integration['user_id'] ?? 0);
        if ($ownerUserId <= 0) {
            return [];
        }
        $where = [
            "workspace_id = ?",
            "status <> 'cancelled'",
            "start_time >= DATE_SUB(NOW(), INTERVAL 30 DAY)",
            "start_time <= DATE_ADD(NOW(), INTERVAL 180 DAY)",
        ];
        $params = [$workspaceId];
        if (Database::columnExists('events', 'created_by')) {
            $where[] = "(assigned_to = ? OR (assigned_to IS NULL AND created_by = ?))";
            $params[] = $ownerUserId;
            $params[] = $ownerUserId;
        } else {
            $where[] = "assigned_to = ?";
            $params[] = $ownerUserId;
        }
        if (Database::columnExists('events', 'sync_origin')) {
            $where[] = "(sync_origin IS NULL OR sync_origin IN ('crm', 'booking'))";
        }
        if (Database::columnExists('events', 'calendar_provider')) {
            $where[] = "(calendar_provider IS NULL OR calendar_provider = '' OR calendar_provider = 'google')";
        }
        if ($lastSyncAt !== '') {
            $where[] = "(last_synced_at IS NULL OR updated_at >= ?)";
            $params[] = $lastSyncAt;
        }

        $rows = Database::query(
            "SELECT id
             FROM events
             WHERE " . implode(' AND ', $where) . "
             ORDER BY start_time ASC
             LIMIT 200",
            $params
        );

        return array_values(array_filter(array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), $rows)));
    }
}
