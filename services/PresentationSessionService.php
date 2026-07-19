<?php

declare(strict_types=1);

namespace CRM\Services;

use CRM\Authorization;
use CRM\Database;
use CRM\Security;
use CRM\Session;

class PresentationSessionService
{
    private DemoCryptoService $crypto;
    private PresentationWorkspaceService $workspaces;
    private PresentationSeedPackService $seedPacks;
    private PresentationWorkspaceCapabilityService $capabilities;

    public function __construct(
        ?DemoCryptoService $crypto = null,
        ?PresentationWorkspaceService $workspaces = null,
        ?PresentationSeedPackService $seedPacks = null,
        ?PresentationWorkspaceCapabilityService $capabilities = null
    ) {
        $this->crypto = $crypto ?? new DemoCryptoService();
        $this->workspaces = $workspaces ?? new PresentationWorkspaceService();
        $this->seedPacks = $seedPacks ?? new PresentationSeedPackService();
        $this->capabilities = $capabilities ?? new PresentationWorkspaceCapabilityService();
    }

    /**
     * @return array<string,mixed>
     */
    public function create(array $input, int $presenterUserId): array
    {
        if ($presenterUserId <= 0) {
            throw new \InvalidArgumentException('Presenter user is required.');
        }

        $name = trim((string) ($input['name'] ?? $input['prospect_name'] ?? ''));
        $email = $this->crypto->normalizeEmail((string) ($input['email'] ?? $input['prospect_email'] ?? ''));
        $phone = $this->crypto->normalizePhone((string) ($input['phone'] ?? $input['whatsapp_phone'] ?? $input['prospect_whatsapp'] ?? $input['prospect_phone'] ?? ''));
        $company = trim((string) ($input['company'] ?? $input['prospect_company'] ?? ''));
        $pitchTitle = trim((string) ($input['pitch_title'] ?? ''));
        $seedPackKey = trim((string) ($input['seed_pack_key'] ?? 'sales_pipeline'));
        $audienceKey = trim((string) ($input['audience_key'] ?? ''));

        if ($name === '' || $email === '' || $phone === '') {
            throw new \InvalidArgumentException('Prospect name, email, and WhatsApp phone are required.');
        }
        if (!Security::validateEmail($email)) {
            throw new \InvalidArgumentException('Enter a valid prospect email address.');
        }

        $pack = $this->seedPacks->pack($seedPackKey);
        if ($audienceKey === '') {
            $audienceKey = (string) ($pack['audience'] ?? $seedPackKey);
        }
        if ($pitchTitle === '') {
            $pitchTitle = ($company !== '' ? $company : $name) . ' - ' . (string) ($pack['label'] ?? 'Presentation');
        }

        $ttlHours = (int) ($input['ttl_hours'] ?? $input['expiry_hours'] ?? 4);
        $ttlHours = min(72, max(1, $ttlHours));
        $expiresAt = date('Y-m-d H:i:s', time() + ($ttlHours * 3600));
        $sessionUuid = $this->uuid();
        $password = $this->temporaryPassword();
        $loginEmail = 'presentation-' . substr(str_replace('-', '', $sessionUuid), 0, 18) . '@demo.local.invalid';
        $featureEmphasis = $this->normalizeFeatureEmphasis($input['feature_emphasis'] ?? []);
        $deliveryMode = trim((string) ($input['delivery_mode'] ?? 'simulated_first')) ?: 'simulated_first';

        Database::beginTransaction();
        try {
            $temporaryUserId = $this->createTemporaryUser($sessionUuid, $expiresAt, $name, $loginEmail, $password);
            Authorization::assignUserRoleBySlug($temporaryUserId, 'presentation_owner', $presenterUserId);

            $workspace = $this->workspaces->provision([
                'company' => $company,
                'pitch_title' => $pitchTitle,
                'audience_key' => $audienceKey,
                'seed_pack_key' => $seedPackKey,
            ], $temporaryUserId, $presenterUserId, $expiresAt);
            $workspaceId = (int) ($workspace['id'] ?? 0);
            if ($workspaceId <= 0) {
                throw new \RuntimeException('Presentation workspace could not be created.');
            }

            Database::execute(
                "INSERT INTO presentation_sessions
                    (session_uuid, workspace_id, presenter_user_id, temporary_user_id, pitch_title, prospect_name,
                     prospect_company, encrypted_email, encrypted_phone, email_hash, phone_hash, audience_key,
                     seed_pack_key, feature_emphasis_json, delivery_mode, expires_at, metadata_json)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $sessionUuid,
                    $workspaceId,
                    $presenterUserId,
                    $temporaryUserId,
                    mb_substr($pitchTitle, 0, 180),
                    mb_substr($name, 0, 180),
                    mb_substr($company, 0, 255),
                    $this->crypto->encrypt($email),
                    $this->crypto->encrypt($phone),
                    $this->crypto->lookupHash($email),
                    $this->crypto->lookupHash($phone),
                    mb_substr($audienceKey, 0, 80),
                    (string) ($pack['key'] ?? $seedPackKey),
                    json_encode($featureEmphasis, JSON_UNESCAPED_SLASHES),
                    mb_substr($deliveryMode, 0, 40),
                    $expiresAt,
                    json_encode([
                        'source' => 'presentation_workspace_mode',
                        'pack_label' => (string) ($pack['label'] ?? ''),
                        'live_delivery_default' => false,
                    ], JSON_UNESCAPED_SLASHES),
                ]
            );
            $sessionId = (int) Database::lastInsertId();

            Database::execute(
                "UPDATE users
                 SET demo_session_uuid = ?,
                     demo_expires_at = ?
                 WHERE id = ?",
                [$sessionUuid, $expiresAt, $temporaryUserId]
            );

            $token = bin2hex(random_bytes(32));
            Database::execute(
                "INSERT INTO presentation_access_tokens
                    (presentation_session_id, workspace_id, user_id, token_hash, status, expires_at, created_by)
                 VALUES (?, ?, ?, ?, 'active', ?, ?)",
                [$sessionId, $workspaceId, $temporaryUserId, $this->tokenHash($token), $expiresAt, $presenterUserId]
            );

            $recipients = $this->storeRecipients($sessionId, $workspaceId, $presenterUserId, $name, $email, $phone, $input);
            $capabilities = $this->capabilities->installForWorkspace($workspaceId, $temporaryUserId, $sessionId);
            $seed = $this->seedPacks->seed($workspaceId, $temporaryUserId, (string) ($pack['key'] ?? $seedPackKey), $sessionId, false);

            Database::commit();

            return [
                'success' => true,
                'workspace' => $this->workspaces->workspacePayload($workspaceId),
                'session' => $this->sessionPayload($sessionId),
                'temporary_login' => [
                    'email' => $loginEmail,
                    'password' => $password,
                    'magic_login_url' => $this->magicLoginUrl($token),
                    'expires_at' => $expiresAt,
                ],
                'recipients' => $recipients,
                'capabilities' => $capabilities,
                'seed' => $seed,
            ];
        } catch (\Throwable $e) {
            if (Database::getInstance()->inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function state(int $limit = 50): array
    {
        $this->expireStaleSessions();
        $limit = min(100, max(1, $limit));
        $rows = Database::query(
            "SELECT ps.*, w.name AS workspace_name, w.slug AS workspace_slug, w.status AS workspace_status
             FROM presentation_sessions ps
             JOIN workspaces w ON w.id = ps.workspace_id
             ORDER BY ps.created_at DESC
             LIMIT {$limit}"
        );

        $sessions = [];
        foreach ($rows as $row) {
            $sessions[] = $this->sessionPayload((int) ($row['id'] ?? 0), $row);
        }

        $summary = Database::queryOne(
            "SELECT
                SUM(status = 'active' AND expires_at > NOW()) AS active_sessions,
                SUM(live_armed = 1 AND status = 'active' AND expires_at > NOW()) AS armed_sessions,
                SUM(status = 'archived') AS archived_sessions,
                COUNT(*) AS total_sessions
             FROM presentation_sessions"
        ) ?: [];

        return [
            'success' => true,
            'sessions' => $sessions,
            'summary' => [
                'active_sessions' => (int) ($summary['active_sessions'] ?? 0),
                'armed_sessions' => (int) ($summary['armed_sessions'] ?? 0),
                'archived_sessions' => (int) ($summary['archived_sessions'] ?? 0),
                'total_sessions' => (int) ($summary['total_sessions'] ?? 0),
            ],
            'seed_packs' => $this->seedPacks->catalog(),
            'scenarios' => $this->seedPacks->scenarioCatalog('sales_pipeline'),
            'live_enabled' => $this->liveDeliveryGloballyEnabled(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function arm(int $sessionId, int $actorUserId, bool $armed): array
    {
        $session = $this->requireSession($sessionId);
        $this->assertActive($session);
        if ($armed && !$this->liveDeliveryGloballyEnabled()) {
            throw new \RuntimeException('Live presentation delivery is disabled by the global kill switch.');
        }

        Database::execute(
            "UPDATE presentation_sessions
             SET live_armed = ?,
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

        return ['success' => true, 'session' => $this->sessionPayload((int) $session['id'])];
    }

    /**
     * @return array<string,mixed>
     */
    public function revoke(int $sessionId, int $actorUserId): array
    {
        $session = $this->requireSession($sessionId);
        $this->disableAccess($session, 'revoked', $actorUserId);
        return ['success' => true, 'session' => $this->sessionPayload($sessionId), 'revoked_by' => $actorUserId];
    }

    /**
     * @return array<string,mixed>
     */
    public function archive(int $sessionId, int $actorUserId): array
    {
        $session = $this->requireSession($sessionId);
        $this->disableAccess($session, 'archived', $actorUserId);
        $this->workspaces->archive((int) ($session['workspace_id'] ?? 0), $actorUserId);
        return ['success' => true, 'session' => $this->sessionPayload($sessionId), 'archived_by' => $actorUserId];
    }

    /**
     * @return array<string,mixed>
     */
    public function reseed(int $sessionId, int $actorUserId): array
    {
        $session = $this->requireSession($sessionId);
        $this->assertActive($session);
        $seed = $this->seedPacks->seed(
            (int) ($session['workspace_id'] ?? 0),
            (int) (($session['temporary_user_id'] ?? 0) ?: $actorUserId),
            (string) ($session['seed_pack_key'] ?? 'sales_pipeline'),
            (int) ($session['id'] ?? 0),
            true
        );

        return ['success' => true, 'seed' => $seed, 'session' => $this->sessionPayload($sessionId)];
    }

    /**
     * @return array<string,mixed>
     */
    public function consumeMagicToken(string $token): array
    {
        $token = trim($token);
        if ($token === '') {
            throw new \InvalidArgumentException('Presentation login token is required.');
        }

        $tokenRow = Database::queryOne(
            "SELECT t.*, ps.session_uuid, ps.status AS session_status, ps.expires_at AS session_expires_at,
                    ps.workspace_id, ps.temporary_user_id
             FROM presentation_access_tokens t
             JOIN presentation_sessions ps ON ps.id = t.presentation_session_id
             WHERE t.token_hash = ?
             LIMIT 1",
            [$this->tokenHash($token)]
        );

        if (!$tokenRow || (string) ($tokenRow['status'] ?? '') !== 'active') {
            throw new \RuntimeException('This presentation login link is no longer valid.');
        }
        if (strtotime((string) ($tokenRow['expires_at'] ?? '')) <= time() || strtotime((string) ($tokenRow['session_expires_at'] ?? '')) <= time()) {
            Database::execute("UPDATE presentation_access_tokens SET status = 'expired', updated_at = NOW() WHERE id = ?", [(int) $tokenRow['id']]);
            throw new \RuntimeException('This presentation login link has expired.');
        }
        if ((string) ($tokenRow['session_status'] ?? '') !== 'active') {
            throw new \RuntimeException('This presentation session is no longer active.');
        }

        $userId = (int) ($tokenRow['user_id'] ?? $tokenRow['temporary_user_id'] ?? 0);
        $workspaceId = (int) ($tokenRow['workspace_id'] ?? 0);
        $sessionId = (int) ($tokenRow['presentation_session_id'] ?? 0);
        $sessionUuid = (string) ($tokenRow['session_uuid'] ?? '');
        $user = Database::queryOne("SELECT id, uuid, email, role FROM users WHERE id = ? LIMIT 1", [$userId]);
        if (!$user) {
            throw new \RuntimeException('Temporary presentation user is missing.');
        }
        $membership = (new WorkspaceMembershipService())->getActiveMembership($workspaceId, $userId);
        if (!$membership) {
            throw new \RuntimeException('Temporary presentation membership is missing.');
        }

        Session::start();
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        Session::set('presentation_session_id', $sessionId);
        Session::set('presentation_session_uuid', $sessionUuid);
        Session::set('user_id', $userId);
        Session::set('user_uuid', (string) ($user['uuid'] ?? ''));
        Session::set('user_email', (string) ($user['email'] ?? ''));
        Session::set('user_role', (string) ($user['role'] ?? 'viewer'));
        WorkspaceContext::setWorkspaceSession($membership);

        Database::execute(
            "UPDATE presentation_access_tokens
             SET status = 'used',
                 used_at = NOW(),
                 updated_at = NOW()
             WHERE id = ?",
            [(int) $tokenRow['id']]
        );
        Database::execute(
            "UPDATE presentation_sessions
             SET last_seen_at = NOW()
             WHERE id = ?",
            [$sessionId]
        );

        return [
            'success' => true,
            'route' => function_exists('publicUrl') ? publicUrl('dashboard.php?presentation=1') : 'dashboard.php?presentation=1',
            'session_id' => $sessionId,
            'workspace_id' => $workspaceId,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function requireSession(int $sessionId): array
    {
        if ($sessionId <= 0) {
            throw new \InvalidArgumentException('Presentation session is required.');
        }

        $session = Database::queryOne("SELECT * FROM presentation_sessions WHERE id = ? LIMIT 1", [$sessionId]);
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
        $session = $this->requireSession($sessionId);
        $where = '';
        if ($channel === 'email') {
            $where = ' AND can_email = 1';
        } elseif ($channel === 'whatsapp') {
            $where = ' AND can_whatsapp = 1';
        }

        $rows = Database::query(
            "SELECT *
             FROM presentation_recipients
             WHERE presentation_session_id = ?
               AND workspace_id = ?
               {$where}
             ORDER BY id ASC",
            [(int) $session['id'], (int) $session['workspace_id']]
        );

        return array_map(fn(array $row): array => $this->recipientPayload($row), $rows);
    }

    public function liveDeliveryGloballyEnabled(): bool
    {
        $raw = (string) (getenv('PRESENTATION_LIVE_ENABLED') ?: ($_ENV['PRESENTATION_LIVE_ENABLED'] ?? 'false'));
        return in_array(strtolower(trim($raw)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public function sessionPayload(int $sessionId, array $row = []): array
    {
        if ($row === []) {
            $row = Database::queryOne(
                "SELECT ps.*, w.name AS workspace_name, w.slug AS workspace_slug, w.status AS workspace_status
                 FROM presentation_sessions ps
                 JOIN workspaces w ON w.id = ps.workspace_id
                 WHERE ps.id = ?
                 LIMIT 1",
                [$sessionId]
            ) ?: [];
        }
        if ($row === []) {
            return [];
        }

        $delivery = Database::queryOne(
            "SELECT
                SUM(channel = 'email' AND status = 'success' AND live_sent = 1) AS live_email_sent,
                SUM(channel = 'whatsapp' AND status = 'success' AND live_sent = 1) AS live_whatsapp_sent,
                SUM(delivery_kind = 'email_digest' AND status IN ('success','simulated')) AS email_digest_runs,
                SUM(delivery_kind = 'whatsapp_digest' AND status IN ('success','simulated')) AS whatsapp_digest_runs,
                COUNT(*) AS audit_events
             FROM presentation_delivery_audit
             WHERE presentation_session_id = ?",
            [(int) ($row['id'] ?? 0)]
        ) ?: [];

        return [
            'id' => (int) ($row['id'] ?? 0),
            'session_uuid' => (string) ($row['session_uuid'] ?? ''),
            'workspace_id' => (int) ($row['workspace_id'] ?? 0),
            'workspace_name' => (string) ($row['workspace_name'] ?? ''),
            'workspace_slug' => (string) ($row['workspace_slug'] ?? ''),
            'workspace_status' => (string) ($row['workspace_status'] ?? ''),
            'presenter_user_id' => (int) ($row['presenter_user_id'] ?? 0),
            'temporary_user_id' => (int) ($row['temporary_user_id'] ?? 0),
            'pitch_title' => (string) ($row['pitch_title'] ?? ''),
            'name' => (string) ($row['prospect_name'] ?? ''),
            'email' => $this->crypto->decrypt($row['encrypted_email'] ?? null),
            'phone' => $this->crypto->decrypt($row['encrypted_phone'] ?? null),
            'company' => (string) ($row['prospect_company'] ?? ''),
            'audience_key' => (string) ($row['audience_key'] ?? ''),
            'seed_pack_key' => (string) ($row['seed_pack_key'] ?? ''),
            'status' => (string) ($row['status'] ?? ''),
            'expires_at' => (string) ($row['expires_at'] ?? ''),
            'last_seen_at' => (string) ($row['last_seen_at'] ?? ''),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'live_armed' => !empty($row['live_armed']),
            'live_armed_at' => $row['live_armed_at'] ?? null,
            'delivery_counts' => [
                'live_email_sent' => (int) ($delivery['live_email_sent'] ?? 0),
                'live_whatsapp_sent' => (int) ($delivery['live_whatsapp_sent'] ?? 0),
                'email_digest_runs' => (int) ($delivery['email_digest_runs'] ?? 0),
                'whatsapp_digest_runs' => (int) ($delivery['whatsapp_digest_runs'] ?? 0),
                'audit_events' => (int) ($delivery['audit_events'] ?? 0),
            ],
        ];
    }

    /**
     * @param array<string,mixed> $session
     */
    private function assertActive(array $session): void
    {
        if ((string) ($session['status'] ?? '') !== 'active' || strtotime((string) ($session['expires_at'] ?? '')) <= time()) {
            throw new \RuntimeException('Presentation session is not active.');
        }
    }

    /**
     * @param array<string,mixed> $session
     */
    private function disableAccess(array $session, string $status, int $actorUserId): void
    {
        $sessionId = (int) ($session['id'] ?? 0);
        $temporaryUserId = (int) ($session['temporary_user_id'] ?? 0);
        $workspaceId = (int) ($session['workspace_id'] ?? 0);
        if (!in_array($status, ['revoked', 'archived', 'expired'], true)) {
            throw new \InvalidArgumentException('Unsupported presentation session status.');
        }

        Database::beginTransaction();
        try {
            $fieldAt = $status === 'archived' ? 'archived_at' : 'revoked_at';
            $fieldBy = $status === 'archived' ? 'archived_by' : 'revoked_by';
            Database::execute(
                "UPDATE presentation_sessions
                 SET status = ?,
                     {$fieldAt} = NOW(),
                     {$fieldBy} = ?,
                     live_armed = 0,
                     updated_at = NOW()
                 WHERE id = ?",
                [$status, $actorUserId, $sessionId]
            );
            Database::execute(
                "UPDATE presentation_access_tokens
                 SET status = ?,
                     updated_at = NOW()
                 WHERE presentation_session_id = ?
                   AND status = 'active'",
                [$status === 'expired' ? 'expired' : 'revoked', $sessionId]
            );
            if ($temporaryUserId > 0) {
                Database::execute(
                    "UPDATE workspace_memberships
                     SET membership_status = 'removed',
                         updated_at = NOW()
                     WHERE workspace_id = ?
                       AND user_id = ?",
                    [$workspaceId, $temporaryUserId]
                );
                Database::execute(
                    "UPDATE users
                     SET demo_expires_at = NOW()
                     WHERE id = ?",
                    [$temporaryUserId]
                );
            }
            Database::commit();
        } catch (\Throwable $e) {
            if (Database::getInstance()->inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }
    }

    private function expireStaleSessions(): void
    {
        $expired = Database::query(
            "SELECT *
             FROM presentation_sessions
             WHERE status = 'active'
               AND expires_at <= NOW()
             LIMIT 50"
        );
        foreach ($expired as $session) {
            try {
                $this->disableAccess($session, 'expired', 0);
                Database::execute(
                    "UPDATE workspaces
                     SET settings_json = JSON_SET(COALESCE(NULLIF(settings_json, ''), JSON_OBJECT()), '$.presentation_status', 'expired')
                     WHERE id = ?",
                    [(int) ($session['workspace_id'] ?? 0)]
                );
            } catch (\Throwable $e) {
                error_log('Presentation expiry cleanup failed: ' . $e->getMessage());
            }
        }
    }

    private function createTemporaryUser(string $sessionUuid, string $expiresAt, string $name, string $email, string $password): int
    {
        $parts = preg_split('/\s+/', trim($name), 2) ?: [];
        $firstName = Security::sanitizeInput($parts[0] ?? 'Presentation', 'string') ?: 'Presentation';
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
     * @param mixed $input
     * @return array<int,string>
     */
    private function normalizeFeatureEmphasis(mixed $input): array
    {
        if (is_string($input)) {
            $input = preg_split('/[,\\n]+/', $input) ?: [];
        }
        if (!is_array($input)) {
            return [];
        }

        $values = [];
        foreach ($input as $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                $values[] = mb_substr($value, 0, 80);
            }
        }

        return array_values(array_unique($values));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function storeRecipients(int $sessionId, int $workspaceId, int $actorUserId, string $name, string $email, string $phone, array $input): array
    {
        $rawRecipients = [
            ['label' => $name, 'email' => $email, 'phone' => $phone, 'can_email' => true, 'can_whatsapp' => true],
        ];

        foreach ((array) ($input['approved_recipients'] ?? $input['recipients'] ?? []) as $recipient) {
            if (is_array($recipient)) {
                $rawRecipients[] = $recipient;
            }
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
            $label = trim((string) ($recipient['label'] ?? $recipient['name'] ?? 'Approved presentation recipient'));
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

            $encryptedLabel = $this->crypto->encrypt($label);
            $encryptedEmail = $this->crypto->encrypt($recipientEmail);
            $encryptedPhone = $this->crypto->encrypt($recipientPhone);
            Database::execute(
                "INSERT INTO presentation_recipients
                    (presentation_session_id, workspace_id, encrypted_label, encrypted_email, encrypted_phone,
                     email_hash, phone_hash, can_email, can_whatsapp, consent_source, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $sessionId,
                    $workspaceId,
                    $encryptedLabel,
                    $encryptedEmail,
                    $encryptedPhone,
                    $this->crypto->lookupHash($recipientEmail),
                    $this->crypto->lookupHash($recipientPhone),
                    $canEmail ? 1 : 0,
                    $canWhatsApp ? 1 : 0,
                    'presenter_confirmed_verbal_consent',
                    $actorUserId,
                ]
            );
            $stored[] = $this->recipientPayload([
                'id' => Database::lastInsertId(),
                'encrypted_label' => $encryptedLabel,
                'encrypted_email' => $encryptedEmail,
                'encrypted_phone' => $encryptedPhone,
                'can_email' => $canEmail ? 1 : 0,
                'can_whatsapp' => $canWhatsApp ? 1 : 0,
            ]);
        }

        return $stored;
    }

    /**
     * @param array<string,mixed> $row
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
        $path = 'presentation_magic_login.php?token=' . rawurlencode($token);
        return function_exists('publicUrl') ? publicUrl($path) : '/public/' . $path;
    }

    private function tokenHash(string $token): string
    {
        return hash('sha256', trim($token));
    }

    private function temporaryPassword(): string
    {
        return 'PRES-' . substr(bin2hex(random_bytes(6)), 0, 12) . '!';
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
