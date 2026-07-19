<?php

declare(strict_types=1);

namespace CRM\Services;

use CRM\Authorization;
use CRM\Database;
use CRM\Security;
use CRM\Session;

class DemoPresentationSessionService
{
    private DemoWorkspaceService $workspaces;
    private DemoCryptoService $crypto;
    private MetroDriveDemoSeedService $seed;

    public function __construct(
        ?DemoWorkspaceService $workspaces = null,
        ?DemoCryptoService $crypto = null,
        ?MetroDriveDemoSeedService $seed = null
    ) {
        $this->workspaces = $workspaces ?? new DemoWorkspaceService();
        $this->crypto = $crypto ?? new DemoCryptoService();
        $this->seed = $seed ?? new MetroDriveDemoSeedService();
    }

    /**
     * @return array<string,mixed>
     */
    public function create(array $input, int $presenterUserId): array
    {
        if ($presenterUserId <= 0) {
            throw new \InvalidArgumentException('Presenter user is required.');
        }

        $workspace = $this->workspaces->metroDrive();
        $workspaceId = (int) ($workspace['id'] ?? 0);
        if ($workspaceId <= 0) {
            throw new \RuntimeException('MetroDrive Academy demo workspace is not ready.');
        }

        $name = trim((string) ($input['name'] ?? $input['prospect_name'] ?? ''));
        $email = $this->crypto->normalizeEmail((string) ($input['email'] ?? $input['prospect_email'] ?? ''));
        $phone = $this->crypto->normalizePhone((string) ($input['phone'] ?? $input['whatsapp_phone'] ?? $input['prospect_whatsapp'] ?? $input['prospect_phone'] ?? ''));
        $company = Security::sanitizeInput((string) ($input['company'] ?? $input['prospect_company'] ?? ''), 'string');
        if ($name === '' || $email === '' || $phone === '') {
            throw new \InvalidArgumentException('Prospect name, email, and WhatsApp phone are required.');
        }
        if (!Security::validateEmail($email)) {
            throw new \InvalidArgumentException('Enter a valid prospect email address.');
        }

        $this->seed->ensureSeeded($workspaceId, $presenterUserId);

        $ttlHours = (int) ($input['ttl_hours'] ?? DemoWorkspaceService::PRESENTATION_SESSION_TTL_HOURS);
        $ttlHours = min(24, max(1, $ttlHours));
        $expiresAt = $this->workspaces->expiresAt($ttlHours);
        $sessionUuid = $this->uuid();
        $password = $this->temporaryPassword();
        $loginEmail = 'metrodrive-demo-' . substr(str_replace('-', '', $sessionUuid), 0, 16) . '@demo.local.invalid';
        $recipientSource = trim((string) ($input['consent_source'] ?? 'presenter_confirmed_verbal_consent'));

        Database::beginTransaction();
        try {
            $userId = $this->createTemporaryUser($sessionUuid, $expiresAt, $name, $loginEmail, $password);
            (new WorkspaceMembershipService())->addOrUpdateMembership($workspaceId, $userId, 'demo_owner', false, $presenterUserId);
            Authorization::assignWorkspaceUserRoleBySlug($workspaceId, $userId, 'demo_owner', $presenterUserId);
            Authorization::assignUserRoleBySlug($userId, 'demo_owner', $presenterUserId);

            $metadata = [
                'source' => 'metrodrive_presentation',
                'created_by' => $presenterUserId,
                'prospect_company' => $company,
                'delivery_mode' => 'simulated_first',
                'live_delivery_default' => false,
            ];
            Database::execute(
                "INSERT INTO demo_visitor_sessions
                    (session_uuid, workspace_id, user_id, guest_user_id, encrypted_name, encrypted_email, encrypted_phone,
                     email_hash, phone_hash, consent_contact, consent_privacy, access_source, delivery_mode,
                     presentation_pitch, demo_owner, presenter_created, magic_link, live_presentation, presentation_company,
                     presentation_mode, expires_at, purge_after, consent_retention_until, last_seen_at, metadata_json)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1, 'guest', 'simulated_first',
                         1, 1, 1, 1, 0, ?, 'supervised_pitch', ?, ?, ?, NOW(), ?)",
                [
                    $sessionUuid,
                    $workspaceId,
                    $userId,
                    $userId,
                    $this->crypto->encrypt($name),
                    $this->crypto->encrypt($email),
                    $this->crypto->encrypt($phone),
                    $this->crypto->lookupHash($email),
                    $this->crypto->lookupHash($phone),
                    $company !== '' ? $company : null,
                    $expiresAt,
                    $expiresAt,
                    $this->workspaces->consentRetentionUntil(true),
                    json_encode($metadata, JSON_UNESCAPED_SLASHES),
                ]
            );
            $sessionId = (int) Database::lastInsertId();
            Database::execute("UPDATE users SET demo_session_uuid = ? WHERE id = ?", [$sessionUuid, $userId]);

            $token = bin2hex(random_bytes(32));
            Database::execute(
                "INSERT INTO demo_presentation_access_tokens
                    (demo_session_id, workspace_id, user_id, token_hash, status, expires_at, created_by)
                 VALUES (?, ?, ?, ?, 'active', ?, ?)",
                [$sessionId, $workspaceId, $userId, $this->tokenHash($token), $expiresAt, $presenterUserId]
            );

            $recipients = $this->storeRecipients($sessionId, $workspaceId, $presenterUserId, $name, $email, $phone, $recipientSource, $input);
            $this->upsertAuthorizedWhatsAppNumber($workspaceId, $userId, $phone, $name);

            Database::commit();

            return [
                'success' => true,
                'workspace' => [
                    'id' => $workspaceId,
                    'slug' => (string) ($workspace['slug'] ?? DemoWorkspaceService::METRODRIVE_WORKSPACE_SLUG),
                    'name' => (string) ($workspace['name'] ?? 'MetroDrive Academy'),
                ],
                'session' => $this->presenterSessionPayload($sessionId),
                'temporary_login' => [
                    'email' => $loginEmail,
                    'password' => $password,
                    'magic_login_url' => $this->magicLoginUrl($token),
                    'expires_at' => $expiresAt,
                ],
                'recipients' => $recipients,
            ];
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function state(int $limit = 30): array
    {
        $workspaceId = $this->workspaces->metroDriveId();
        $limit = min(100, max(1, $limit));
        $rows = Database::query(
            "SELECT *
             FROM demo_visitor_sessions
             WHERE workspace_id = ?
               AND presentation_pitch = 1
             ORDER BY created_at DESC
             LIMIT {$limit}",
            [$workspaceId]
        );

        $sessions = [];
        foreach ($rows as $row) {
            $sessions[] = $this->presenterSessionPayload((int) ($row['id'] ?? 0), $row);
        }

        $summary = Database::queryOne(
            "SELECT
                SUM(status = 'active' AND expires_at > NOW()) AS active_sessions,
                SUM(live_presentation = 1 AND status = 'active' AND expires_at > NOW()) AS armed_sessions,
                COALESCE(SUM(message_count), 0) AS scenario_messages
             FROM demo_visitor_sessions
             WHERE workspace_id = ?
               AND presentation_pitch = 1",
            [$workspaceId]
        ) ?: [];

        return [
            'success' => true,
            'workspace_id' => $workspaceId,
            'workspace_slug' => DemoWorkspaceService::METRODRIVE_WORKSPACE_SLUG,
            'sessions' => $sessions,
            'summary' => $summary,
            'live_enabled' => $this->liveDeliveryGloballyEnabled(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function arm(int $sessionId, int $actorUserId, bool $armed): array
    {
        $session = $this->requirePresentationSession($sessionId);
        if ((string) ($session['status'] ?? '') !== 'active' || strtotime((string) ($session['expires_at'] ?? '')) <= time()) {
            throw new \RuntimeException('Presentation session is not active.');
        }
        if ($armed && !$this->liveDeliveryGloballyEnabled()) {
            throw new \RuntimeException('Live demo delivery is disabled by the global kill switch.');
        }

        Database::execute(
            "UPDATE demo_visitor_sessions
             SET live_presentation = ?,
                 live_armed_at = ?,
                 live_armed_by = ?,
                 updated_at = NOW()
             WHERE id = ?",
            [
                $armed ? 1 : 0,
                $armed ? date('Y-m-d H:i:s') : null,
                $armed ? $actorUserId : null,
                (int) $session['id'],
            ]
        );

        return ['success' => true, 'session' => $this->presenterSessionPayload((int) $session['id'])];
    }

    /**
     * @return array<string,mixed>
     */
    public function revoke(int $sessionId, int $actorUserId): array
    {
        $session = $this->requirePresentationSession($sessionId);
        Database::beginTransaction();
        try {
            Database::execute(
                "UPDATE demo_visitor_sessions
                 SET status = 'revoked',
                     revoked_at = NOW(),
                     cleanup_status = 'pending',
                     purge_after = NOW(),
                     live_presentation = 0,
                     updated_at = NOW()
                 WHERE id = ?
                   AND status = 'active'",
                [(int) $session['id']]
            );
            Database::execute(
                "UPDATE demo_presentation_access_tokens
                 SET status = 'revoked', updated_at = NOW()
                 WHERE demo_session_id = ?
                   AND status = 'active'",
                [(int) $session['id']]
            );
            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        $purge = ['records_purged' => 0];
        try {
            $purge = (new DemoSessionService())->purgeExpired(25);
        } catch (\Throwable $e) {
            error_log('MetroDrive presentation revoke purge failed: ' . $e->getMessage());
        }

        return [
            'success' => true,
            'session_id' => (int) $session['id'],
            'revoked_by' => $actorUserId,
            'records_purged' => (int) ($purge['records_purged'] ?? 0),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function consumeMagicToken(string $token): array
    {
        $token = trim($token);
        if ($token === '') {
            throw new \InvalidArgumentException('Demo login token is required.');
        }

        $tokenRow = Database::queryOne(
            "SELECT t.*, s.session_uuid, s.status AS session_status, s.expires_at AS session_expires_at,
                    s.workspace_id, s.user_id AS session_user_id
             FROM demo_presentation_access_tokens t
             JOIN demo_visitor_sessions s ON s.id = t.demo_session_id
             WHERE t.token_hash = ?
             LIMIT 1",
            [$this->tokenHash($token)]
        );

        if (!$tokenRow || (string) ($tokenRow['status'] ?? '') !== 'active') {
            throw new \RuntimeException('This demo login link is no longer valid.');
        }
        if (strtotime((string) ($tokenRow['expires_at'] ?? '')) <= time() || strtotime((string) ($tokenRow['session_expires_at'] ?? '')) <= time()) {
            Database::execute("UPDATE demo_presentation_access_tokens SET status = 'expired', updated_at = NOW() WHERE id = ?", [(int) $tokenRow['id']]);
            throw new \RuntimeException('This demo login link has expired.');
        }
        if ((string) ($tokenRow['session_status'] ?? '') !== 'active') {
            throw new \RuntimeException('This demo session is no longer active.');
        }

        $userId = (int) ($tokenRow['user_id'] ?? $tokenRow['session_user_id'] ?? 0);
        $workspaceId = (int) ($tokenRow['workspace_id'] ?? 0);
        $sessionId = (int) ($tokenRow['demo_session_id'] ?? 0);
        $sessionUuid = (string) ($tokenRow['session_uuid'] ?? '');
        $user = Database::queryOne("SELECT id, uuid, email, role FROM users WHERE id = ? LIMIT 1", [$userId]);
        if (!$user) {
            throw new \RuntimeException('Temporary demo user is missing.');
        }
        $membership = (new WorkspaceMembershipService())->getActiveMembership($workspaceId, $userId);
        if (!$membership) {
            throw new \RuntimeException('Temporary demo membership is missing.');
        }

        Session::start();
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        Session::set('demo_visitor_session_id', $sessionId);
        Session::set('demo_visitor_session_uuid', $sessionUuid);
        Session::set('demo_workspace_id', $workspaceId);
        Session::set('user_id', $userId);
        Session::set('user_uuid', (string) ($user['uuid'] ?? ''));
        Session::set('user_email', (string) ($user['email'] ?? ''));
        Session::set('user_role', (string) ($user['role'] ?? 'viewer'));
        WorkspaceContext::setWorkspaceSession($membership);

        Database::execute(
            "UPDATE demo_presentation_access_tokens
             SET status = 'used', used_at = NOW(), updated_at = NOW()
             WHERE id = ?",
            [(int) $tokenRow['id']]
        );
        Database::execute(
            "UPDATE demo_visitor_sessions
             SET last_seen_at = NOW()
             WHERE id = ?",
            [$sessionId]
        );

        return [
            'success' => true,
            'route' => function_exists('publicUrl') ? publicUrl('dashboard.php?demo=metrodrive') : 'dashboard.php?demo=metrodrive',
            'session_id' => $sessionId,
            'workspace_id' => $workspaceId,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function requirePresentationSession(int $sessionId): array
    {
        if ($sessionId <= 0) {
            throw new \InvalidArgumentException('Presentation session is required.');
        }

        $workspaceId = $this->workspaces->metroDriveId();
        $session = Database::queryOne(
            "SELECT *
             FROM demo_visitor_sessions
             WHERE id = ?
               AND workspace_id = ?
               AND presentation_pitch = 1
             LIMIT 1",
            [$sessionId, $workspaceId]
        );

        if (!$session) {
            throw new \RuntimeException('Presentation session was not found.');
        }

        return $session;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function recipients(int $sessionId, ?string $channel = null): array
    {
        $session = $this->requirePresentationSession($sessionId);
        $where = '';
        if ($channel === 'email') {
            $where = ' AND can_email = 1';
        } elseif ($channel === 'whatsapp') {
            $where = ' AND can_whatsapp = 1';
        }

        $rows = Database::query(
            "SELECT *
             FROM demo_presentation_recipients
             WHERE demo_session_id = ?
               AND workspace_id = ?
               {$where}
             ORDER BY id ASC",
            [(int) $session['id'], (int) $session['workspace_id']]
        );

        return array_map(fn(array $row): array => $this->recipientPayload($row), $rows);
    }

    public function liveDeliveryGloballyEnabled(): bool
    {
        $raw = (string) (getenv('DEMO_PRESENTATION_LIVE_ENABLED') ?: ($_ENV['DEMO_PRESENTATION_LIVE_ENABLED'] ?? 'false'));
        return in_array(strtolower(trim($raw)), ['1', 'true', 'yes', 'on'], true);
    }

    private function createTemporaryUser(string $sessionUuid, string $expiresAt, string $name, string $email, string $password): int
    {
        $parts = preg_split('/\s+/', trim($name), 2) ?: [];
        $firstName = Security::sanitizeInput($parts[0] ?? 'Demo', 'string') ?: 'Demo';
        $lastName = Security::sanitizeInput($parts[1] ?? 'Owner', 'string') ?: 'Owner';

        Database::execute(
            "INSERT INTO users
                (uuid, first_name, last_name, email, password_hash, role, is_demo_guest, demo_expires_at, demo_session_uuid, email_verified_at)
             VALUES (?, ?, ?, ?, ?, 'viewer', 1, ?, ?, NOW())",
            [
                $this->uuid(),
                $firstName,
                $lastName,
                $email,
                password_hash($password, PASSWORD_DEFAULT),
                $expiresAt,
                $sessionUuid,
            ]
        );

        return (int) Database::lastInsertId();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function storeRecipients(
        int $sessionId,
        int $workspaceId,
        int $actorUserId,
        string $name,
        string $email,
        string $phone,
        string $consentSource,
        array $input
    ): array {
        $rawRecipients = [
            ['label' => $name, 'email' => $email, 'phone' => $phone, 'can_email' => true, 'can_whatsapp' => true],
        ];

        foreach ((array) ($input['approved_recipients'] ?? $input['recipients'] ?? []) as $recipient) {
            if (!is_array($recipient)) {
                continue;
            }
            $rawRecipients[] = $recipient;
        }

        foreach (['presenter_email', 'approved_email'] as $key) {
            $value = $this->crypto->normalizeEmail((string) ($input[$key] ?? ''));
            if ($value !== '') {
                $rawRecipients[] = ['label' => 'Presenter approved email', 'email' => $value, 'phone' => '', 'can_email' => true, 'can_whatsapp' => false];
            }
        }
        foreach (['presenter_phone', 'approved_phone'] as $key) {
            $value = $this->crypto->normalizePhone((string) ($input[$key] ?? ''));
            if ($value !== '') {
                $rawRecipients[] = ['label' => 'Presenter approved WhatsApp', 'email' => '', 'phone' => $value, 'can_email' => false, 'can_whatsapp' => true];
            }
        }

        $seen = [];
        $stored = [];
        foreach ($rawRecipients as $recipient) {
            $label = trim((string) ($recipient['label'] ?? $recipient['name'] ?? 'Approved demo recipient'));
            $channel = strtolower(trim((string) ($recipient['channel'] ?? '')));
            $rawRecipient = trim((string) ($recipient['recipient'] ?? ''));
            $recipientEmail = $this->crypto->normalizeEmail((string) ($recipient['email'] ?? ($channel === 'email' ? $rawRecipient : '')));
            $recipientPhone = $this->crypto->normalizePhone((string) ($recipient['phone'] ?? $recipient['whatsapp_phone'] ?? ($channel === 'whatsapp' ? $rawRecipient : '')));
            $canEmail = ($channel === 'email' || !empty($recipient['can_email'])) && $recipientEmail !== '';
            $canWhatsApp = ($channel === 'whatsapp' || !empty($recipient['can_whatsapp'])) && $recipientPhone !== '';
            if (!$canEmail && !$canWhatsApp) {
                continue;
            }
            if ($recipientEmail !== '' && !Security::validateEmail($recipientEmail)) {
                continue;
            }

            $key = ($recipientEmail !== '' ? 'e:' . $recipientEmail : '') . '|' . ($recipientPhone !== '' ? 'p:' . $recipientPhone : '');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            Database::execute(
                "INSERT INTO demo_presentation_recipients
                    (demo_session_id, workspace_id, encrypted_label, encrypted_email, encrypted_phone,
                     email_hash, phone_hash, can_email, can_whatsapp, consent_source, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $sessionId,
                    $workspaceId,
                    $this->crypto->encrypt($label),
                    $this->crypto->encrypt($recipientEmail),
                    $this->crypto->encrypt($recipientPhone),
                    $this->crypto->lookupHash($recipientEmail),
                    $this->crypto->lookupHash($recipientPhone),
                    $canEmail ? 1 : 0,
                    $canWhatsApp ? 1 : 0,
                    $consentSource,
                    $actorUserId,
                ]
            );
            $stored[] = $this->recipientPayload([
                'id' => Database::lastInsertId(),
                'encrypted_label' => $this->crypto->encrypt($label),
                'encrypted_email' => $this->crypto->encrypt($recipientEmail),
                'encrypted_phone' => $this->crypto->encrypt($recipientPhone),
                'can_email' => $canEmail ? 1 : 0,
                'can_whatsapp' => $canWhatsApp ? 1 : 0,
            ]);
        }

        return $stored;
    }

    private function upsertAuthorizedWhatsAppNumber(int $workspaceId, int $userId, string $phone, string $label): void
    {
        if ($workspaceId <= 0 || $userId <= 0 || $phone === '' || !Database::tableExists('whatsapp_assistant_authorized_numbers')) {
            return;
        }

        $normalized = preg_replace('/[^\d+]/', '', $phone);
        $normalized = ltrim((string) $normalized, '+');
        if ($normalized === '') {
            return;
        }

        Database::execute(
            "INSERT INTO whatsapp_assistant_authorized_numbers
                (workspace_id, phone_number, user_id, label, notes, is_active, digest_enabled)
             VALUES (?, ?, ?, ?, 'MetroDrive presentation demo recipient', 1, 1)
             ON DUPLICATE KEY UPDATE
                user_id = VALUES(user_id),
                label = VALUES(label),
                is_active = 1,
                digest_enabled = 1,
                updated_at = NOW()",
            [$workspaceId, $normalized, $userId, $label]
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function presenterSessionPayload(int $sessionId, array $row = []): array
    {
        if ($row === []) {
            $row = Database::queryOne("SELECT * FROM demo_visitor_sessions WHERE id = ? LIMIT 1", [$sessionId]) ?: [];
        }
        if ($row === []) {
            return [];
        }

        $delivery = Database::queryOne(
            "SELECT
                SUM(channel = 'email' AND status = 'success' AND live_sent = 1) AS live_email_sent,
                SUM(channel = 'whatsapp' AND status = 'success' AND live_sent = 1) AS live_whatsapp_sent,
                SUM(delivery_kind = 'email_digest' AND status IN ('success','simulated')) AS email_digest_runs,
                SUM(delivery_kind = 'whatsapp_digest' AND status IN ('success','simulated')) AS whatsapp_digest_runs
             FROM demo_presentation_delivery_audit
             WHERE demo_session_id = ?",
            [(int) ($row['id'] ?? 0)]
        ) ?: [];

        return [
            'id' => (int) ($row['id'] ?? 0),
            'session_uuid' => (string) ($row['session_uuid'] ?? ''),
            'workspace_id' => (int) ($row['workspace_id'] ?? 0),
            'user_id' => (int) ($row['user_id'] ?? 0),
            'guest_user_id' => (int) ($row['guest_user_id'] ?? 0),
            'name' => $this->crypto->decrypt($row['encrypted_name'] ?? null),
            'email' => $this->crypto->decrypt($row['encrypted_email'] ?? null),
            'phone' => $this->crypto->decrypt($row['encrypted_phone'] ?? null),
            'company' => (string) ($row['presentation_company'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'expires_at' => (string) ($row['expires_at'] ?? ''),
            'last_seen_at' => (string) ($row['last_seen_at'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'live_presentation' => !empty($row['live_presentation']),
            'live_armed_at' => $row['live_armed_at'] ?? null,
            'message_count' => (int) ($row['message_count'] ?? 0),
            'delivery_counts' => [
                'live_email_sent' => (int) ($delivery['live_email_sent'] ?? 0),
                'live_whatsapp_sent' => (int) ($delivery['live_whatsapp_sent'] ?? 0),
                'email_digest_runs' => (int) ($delivery['email_digest_runs'] ?? 0),
                'whatsapp_digest_runs' => (int) ($delivery['whatsapp_digest_runs'] ?? 0),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function recipientPayload(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'label' => $this->crypto->decrypt($row['encrypted_label'] ?? null),
            'email' => $this->crypto->decrypt($row['encrypted_email'] ?? null),
            'phone' => $this->crypto->decrypt($row['encrypted_phone'] ?? null),
            'can_email' => !empty($row['can_email']),
            'can_whatsapp' => !empty($row['can_whatsapp']),
        ];
    }

    private function magicLoginUrl(string $token): string
    {
        $path = 'demo_magic_login.php?token=' . rawurlencode($token);
        return function_exists('publicUrl') ? publicUrl($path) : '/public/' . $path;
    }

    private function tokenHash(string $token): string
    {
        return hash('sha256', trim($token));
    }

    private function temporaryPassword(): string
    {
        return 'MDA-' . substr(bin2hex(random_bytes(6)), 0, 12) . '!';
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
