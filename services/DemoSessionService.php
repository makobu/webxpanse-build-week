<?php

declare(strict_types=1);

namespace CRM\Services;

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Security;
use CRM\Session;

class DemoSessionService
{
    private DemoWorkspaceService $workspaceService;
    private DemoCryptoService $crypto;
    private DemoSessionScopeService $scope;
    private DefaultWorkspaceDemoVisitorContactService $defaultContactCapture;

    public function __construct(
        ?DemoWorkspaceService $workspaceService = null,
        ?DemoCryptoService $crypto = null,
        ?DemoSessionScopeService $scope = null,
        ?DefaultWorkspaceDemoVisitorContactService $defaultContactCapture = null
    ) {
        $this->workspaceService = $workspaceService ?? new DemoWorkspaceService();
        $this->crypto = $crypto ?? new DemoCryptoService();
        $this->scope = $scope ?? new DemoSessionScopeService();
        $this->defaultContactCapture = $defaultContactCapture ?? new DefaultWorkspaceDemoVisitorContactService();
    }

    public function start(array $input = []): array
    {
        Session::start();
        $workspace = $this->workspaceService->resolve();
        $workspaceId = (int) ($workspace['id'] ?? 0);
        $realUser = Auth::check() ? Auth::user() : null;
        $userId = (int) ($realUser['id'] ?? 0);
        $isLoggedIn = $userId > 0;

        $name = trim((string) ($input['name'] ?? ($realUser ? trim(($realUser['first_name'] ?? '') . ' ' . ($realUser['last_name'] ?? '')) : '')));
        $email = $this->crypto->normalizeEmail((string) ($input['email'] ?? ($realUser['email'] ?? '')));
        $phone = $this->crypto->normalizePhone((string) ($input['phone'] ?? $input['whatsapp_phone'] ?? ''));
        $consentContact = !empty($input['consent_contact']);
        $consentPrivacy = true;

        if (!$isLoggedIn) {
            if ($name === '' || $email === '' || $phone === '') {
                throw new \InvalidArgumentException('Name, email, and WhatsApp phone are required for guest demo access.');
            }
            if (!Security::validateEmail($email)) {
                throw new \InvalidArgumentException('Enter a valid email address.');
            }
        }

        $emailHash = $this->crypto->lookupHash($email);
        $phoneHash = $this->crypto->lookupHash($phone);
        $ipHash = $this->crypto->lookupHash((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        $userAgentHash = $this->crypto->lookupHash((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        $this->assertStartRateLimit($emailHash, $phoneHash, $ipHash);

        $sessionUuid = $this->uuid();
        $expiresAt = $this->workspaceService->expiresAt();
        $guestUserId = null;
        $sessionMetadata = [
            'instant_access' => true,
            'delivery_mode' => 'simulated_first',
            'user_agent_sample' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 160),
        ];

        Database::beginTransaction();
        try {
            if (!$isLoggedIn) {
                $guestUserId = $this->createGuestUser($sessionUuid, $expiresAt, $name);
                $userId = $guestUserId;
                Session::set('user_id', $guestUserId);
                Session::set('user_uuid', $this->userUuid($guestUserId));
                Session::set('user_email', 'demo-' . $sessionUuid . '@demo.local.invalid');
                Session::set('user_role', 'viewer');
            }

            (new WorkspaceMembershipService())->addOrUpdateMembership($workspaceId, $userId, 'demo_viewer', false, $isLoggedIn ? $userId : null);
            Authorization::assignWorkspaceUserRoleBySlug($workspaceId, $userId, 'demo_viewer', $isLoggedIn ? $userId : null);
            (new DemoWorkspaceSeedService())->ensureSeeded($workspaceId, $userId);

            Database::execute(
                "INSERT INTO demo_visitor_sessions
                    (session_uuid, workspace_id, user_id, guest_user_id, encrypted_name, encrypted_email, encrypted_phone,
                     email_hash, phone_hash, consent_contact, consent_privacy, access_source, ip_hash, user_agent_hash,
                     expires_at, purge_after, consent_retention_until, last_seen_at, metadata_json)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)",
                [
                    $sessionUuid,
                    $workspaceId,
                    $userId,
                    $guestUserId,
                    $this->crypto->encrypt($name),
                    $this->crypto->encrypt($email),
                    $this->crypto->encrypt($phone),
                    $emailHash,
                    $phoneHash,
                    $consentContact ? 1 : 0,
                    $consentPrivacy ? 1 : 0,
                    $isLoggedIn ? 'logged_in' : 'guest',
                    $ipHash,
                    $userAgentHash,
                    $expiresAt,
                    $guestUserId !== null ? $expiresAt : $this->workspaceService->purgeAfter(),
                    $this->workspaceService->consentRetentionUntil($consentContact),
                    json_encode($sessionMetadata, JSON_UNESCAPED_SLASHES),
                ]
            );
            $demoSessionId = (int) Database::lastInsertId();

            if (!$isLoggedIn) {
                $sessionMetadata['default_workspace_contact'] = $this->defaultContactCapture->capture([
                    'name' => $name,
                    'email' => $email,
                    'phone' => $phone,
                    'consent_contact' => $consentContact,
                ], $sessionUuid, $demoSessionId, $workspaceId);

                Database::execute(
                    "UPDATE demo_visitor_sessions SET metadata_json = ? WHERE id = ?",
                    [json_encode($sessionMetadata, JSON_UNESCAPED_SLASHES), $demoSessionId]
                );
            }

            if ($guestUserId !== null) {
                Database::execute(
                    "UPDATE users SET demo_session_uuid = ? WHERE id = ?",
                    [$sessionUuid, $guestUserId]
                );
            }

            $membership = (new WorkspaceMembershipService())->getActiveMembership($workspaceId, $userId);
            if (!$membership) {
                throw new \RuntimeException('Unable to activate demo workspace membership.');
            }

            WorkspaceContext::setWorkspaceSession($membership);
            Session::set('demo_visitor_session_id', $demoSessionId);
            Session::set('demo_visitor_session_uuid', $sessionUuid);
            Session::set('demo_workspace_id', $workspaceId);

            Database::commit();

            return $this->state();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    public function state(): array
    {
        Session::start();
        $workspace = $this->workspaceService->resolve();
        $workspaceId = (int) ($workspace['id'] ?? 0);
        $session = $this->scope->activeSession($workspaceId);

        return [
            'active' => $session !== null,
            'workspace_id' => $workspaceId,
            'workspace_slug' => (string) ($workspace['slug'] ?? DemoWorkspaceService::DEMO_WORKSPACE_SLUG),
            'workspace_name' => (string) ($workspace['name'] ?? 'Protected Demo Workspace'),
            'session_uuid' => $session['session_uuid'] ?? null,
            'expires_at' => $session['expires_at'] ?? null,
            'route' => $this->workspaceService->publicRoute(),
            'user_id' => Auth::userId(),
            'access_source' => $session['access_source'] ?? null,
        ];
    }

    public function end(): array
    {
        $session = $this->scope->activeSession();
        if ($session) {
            Database::execute(
                "UPDATE demo_visitor_sessions
                 SET status = 'ended',
                     ended_at = NOW(),
                     cleanup_status = 'pending',
                     purge_after = CASE WHEN guest_user_id IS NOT NULL THEN NOW() ELSE purge_after END
                 WHERE id = ? AND status = 'active'",
                [(int) $session['id']]
            );
        }

        Session::remove('demo_visitor_session_id');
        Session::remove('demo_visitor_session_uuid');
        Session::remove('demo_workspace_id');

        if (!empty($session['guest_user_id']) && (int) (Auth::userId() ?? 0) === (int) $session['guest_user_id']) {
            Auth::discardRememberedBrowserSession();
        } elseif (Auth::check()) {
            WorkspaceContext::activateForUser((int) Auth::userId());
        } else {
            WorkspaceContext::clear();
        }

        if (!empty($session['guest_user_id'])) {
            $this->purgePrivateOverlay((int) $session['id'], (int) $session['workspace_id']);
        }

        return ['success' => true, 'ended' => $session !== null];
    }

    public function touch(int $sessionId): void
    {
        if ($sessionId <= 0) {
            return;
        }

        Database::execute(
            "UPDATE demo_visitor_sessions SET last_seen_at = NOW() WHERE id = ? AND status = 'active'",
            [$sessionId]
        );
    }

    public function revoke(int $sessionId): bool
    {
        if ($sessionId <= 0) {
            return false;
        }

        return Database::execute(
            "UPDATE demo_visitor_sessions
             SET status = 'revoked',
                 revoked_at = NOW(),
                 cleanup_status = 'pending',
                 purge_after = CASE WHEN guest_user_id IS NOT NULL THEN NOW() ELSE purge_after END
             WHERE id = ? AND status = 'active'",
            [$sessionId]
        ) > 0;
    }

    public function purgeExpired(int $limit = 200): array
    {
        $limit = min(1000, max(1, $limit));
        Database::execute(
            "UPDATE demo_visitor_sessions
             SET status = 'expired', cleanup_status = 'pending'
             WHERE status = 'active'
               AND expires_at <= NOW()"
        );

        $sessions = Database::query(
            "SELECT *
             FROM demo_visitor_sessions
             WHERE cleanup_status IN ('pending', 'failed')
               AND (
                    purge_after <= NOW()
                    OR (guest_user_id IS NOT NULL AND expires_at <= NOW())
               )
             ORDER BY purge_after ASC
             LIMIT {$limit}"
        );

        $purged = 0;
        foreach ($sessions as $session) {
            $purged += $this->purgePrivateOverlay((int) $session['id'], (int) $session['workspace_id']);
        }

        return ['success' => true, 'sessions_checked' => count($sessions), 'records_purged' => $purged];
    }

    private function purgePrivateOverlay(int $sessionId, int $workspaceId): int
    {
        $session = Database::queryOne(
            "SELECT id, session_uuid, guest_user_id, metadata_json
             FROM demo_visitor_sessions
             WHERE id = ?
             LIMIT 1",
            [$sessionId]
        ) ?: [];

        $records = Database::query(
            "SELECT table_name, record_id
             FROM demo_session_entities
             WHERE demo_session_id = ?
               AND cleanup_status = 'active'
             ORDER BY id DESC",
            [$sessionId]
        );

        $deleted = 0;
        $deletedGuestUsers = 0;
        $guestUserId = (int) ($session['guest_user_id'] ?? 0);
        $sessionUuid = (string) ($session['session_uuid'] ?? '');
        $metadata = json_decode((string) ($session['metadata_json'] ?? ''), true) ?: [];
        Database::beginTransaction();
        try {
            foreach ($records as $record) {
                $table = (string) ($record['table_name'] ?? '');
                $id = (int) ($record['record_id'] ?? 0);
                if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || $id <= 0 || !Database::tableExists($table)) {
                    continue;
                }
                $deleted += Database::execute(
                    "DELETE FROM `{$table}` WHERE workspace_id = ? AND demo_session_id = ? AND id = ?",
                    [$workspaceId, $sessionId, $id]
                );
                Database::execute(
                    "UPDATE demo_session_entities SET cleanup_status = 'purged' WHERE demo_session_id = ? AND table_name = ? AND record_id = ?",
                    [$sessionId, $table, $id]
                );
            }

            Database::execute("DELETE FROM demo_realtime_events WHERE workspace_id = ? AND demo_session_id = ?", [$workspaceId, $sessionId]);
            if ($guestUserId > 0 && $sessionUuid !== '') {
                Database::execute("DELETE FROM workspace_user_roles WHERE user_id = ?", [$guestUserId]);
                Database::execute("DELETE FROM user_roles WHERE user_id = ?", [$guestUserId]);
                Database::execute("DELETE FROM workspace_memberships WHERE user_id = ?", [$guestUserId]);
                $deletedGuestUsers = Database::execute(
                    "DELETE FROM users
                     WHERE id = ?
                       AND is_demo_guest = 1
                       AND demo_session_uuid = ?",
                    [$guestUserId, $sessionUuid]
                );
            }

            $summary = [
                'deleted_records' => $deleted,
                'deleted_guest_users' => $deletedGuestUsers,
                'deleted_guest_user_id' => $deletedGuestUsers > 0 ? $guestUserId : null,
            ];
            if (isset($metadata['default_workspace_contact'])) {
                $summary['default_workspace_contact'] = $metadata['default_workspace_contact'];
            }

            Database::execute(
                "UPDATE demo_visitor_sessions
                 SET cleanup_status = 'succeeded',
                     encrypted_name = NULL,
                     encrypted_email = CASE WHEN consent_contact = 1 AND consent_retention_until > NOW() THEN encrypted_email ELSE NULL END,
                     encrypted_phone = CASE WHEN consent_contact = 1 AND consent_retention_until > NOW() THEN encrypted_phone ELSE NULL END,
                     cleanup_summary_json = ?
                 WHERE id = ?",
                [json_encode($summary, JSON_UNESCAPED_SLASHES), $sessionId]
            );
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            Database::execute(
                "UPDATE demo_visitor_sessions SET cleanup_status = 'failed', cleanup_summary_json = ? WHERE id = ?",
                [json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_SLASHES), $sessionId]
            );
        }

        return $deleted;
    }

    private function assertStartRateLimit(?string $emailHash, ?string $phoneHash, ?string $ipHash): void
    {
        $clauses = [];
        $params = [];
        foreach (['email_hash' => $emailHash, 'phone_hash' => $phoneHash, 'ip_hash' => $ipHash] as $column => $hash) {
            if ($hash === null || $hash === '') {
                continue;
            }
            $clauses[] = "{$column} = ?";
            $params[] = $hash;
        }
        if ($clauses === []) {
            return;
        }

        $row = Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM demo_visitor_sessions
             WHERE (" . implode(' OR ', $clauses) . ")
               AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)",
            $params
        );

        if ((int) ($row['c'] ?? 0) >= 8) {
            throw new \RuntimeException('Too many demo access attempts. Please try again later.');
        }
    }

    private function createGuestUser(string $sessionUuid, string $expiresAt, string $name): int
    {
        $parts = preg_split('/\s+/', trim($name), 2) ?: [];
        $firstName = Security::sanitizeInput($parts[0] ?? 'Demo', 'string') ?: 'Demo';
        $lastName = Security::sanitizeInput($parts[1] ?? 'Visitor', 'string') ?: 'Visitor';
        $email = 'demo-' . $sessionUuid . '@demo.local.invalid';

        Database::execute(
            "INSERT INTO users (uuid, first_name, last_name, email, password_hash, role, is_demo_guest, demo_expires_at, demo_session_uuid, email_verified_at)
             VALUES (?, ?, ?, ?, ?, 'viewer', 1, ?, ?, NOW())",
            [
                $this->uuid(),
                $firstName,
                $lastName,
                $email,
                password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT),
                $expiresAt,
                $sessionUuid,
            ]
        );

        $userId = (int) Database::lastInsertId();
        Authorization::assignUserRoleBySlug($userId, 'demo_viewer');
        return $userId;
    }

    private function userUuid(int $userId): string
    {
        $row = Database::queryOne("SELECT uuid FROM users WHERE id = ? LIMIT 1", [$userId]);
        return (string) ($row['uuid'] ?? '');
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
