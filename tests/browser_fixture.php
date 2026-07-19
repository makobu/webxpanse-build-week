<?php

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/constants.php';

use CRM\Database;
use CRM\Modules\Forms;
use CRM\Session;
use CRM\Services\SaaSBillingService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceGovernanceService;
use CRM\Services\WorkspaceProvisioningService;
use CRM\Services\WorkspaceSkillCatalogService;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();
Session::set('active_workspace_id', 1);
Session::set('active_workspace_uuid', '00000000-0000-4000-8000-000000000001');
Session::set('active_workspace_slug', 'default');
Session::set('active_workspace_name', 'Default Workspace');
Session::set('active_workspace_role', 'owner');
Session::set('active_workspace_membership_id', 1);
WorkspaceContext::activateRuntimeWorkspace(1);

function fixtureJsonResponse(array $payload, int $status = 200): void
{
    if (!headers_sent()) {
        http_response_code($status);
        header('Content-Type: application/json');
    }
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function fixtureUuid(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function fixtureFindUserIdByEmail(string $email): int
{
    $user = Database::queryOne('SELECT id FROM users WHERE email = ? LIMIT 1', [$email]);
    return (int) ($user['id'] ?? 0);
}

function fixtureFindUserByEmail(string $email): ?array
{
    $user = Database::queryOne(
        'SELECT id, uuid, email, role, first_name, last_name FROM users WHERE email = ? LIMIT 1',
        [$email]
    );
    return $user ?: null;
}

function fixtureFindRoleBySlug(string $roleSlug): ?array
{
    $role = Database::queryOne('SELECT id, name, slug FROM roles WHERE slug = ? LIMIT 1', [$roleSlug]);
    return $role ?: null;
}

function fixtureDefaultWorkspaceId(): int
{
    return 1;
}

function fixtureEnsureWorkspaceMembership(int $userId, string $profileRole = 'viewer'): void
{
    if ($userId <= 0 || !fixtureTableExists('workspace_memberships')) {
        return;
    }

    $workspaceId = fixtureDefaultWorkspaceId();
    $roleSlug = in_array($profileRole, ['admin', 'owner'], true) ? 'owner' : 'member';
    $isOwner = $roleSlug === 'owner' ? 1 : 0;

    $existing = Database::queryOne(
        'SELECT id FROM workspace_memberships WHERE workspace_id = ? AND user_id = ? LIMIT 1',
        [$workspaceId, $userId]
    );

    if ($existing) {
        Database::execute(
            "UPDATE workspace_memberships
             SET role_slug = ?, membership_status = 'active', is_owner = ?, joined_at = COALESCE(joined_at, NOW())
             WHERE id = ?",
            [$roleSlug, $isOwner, (int) $existing['id']]
        );
    } else {
        Database::execute(
            "INSERT INTO workspace_memberships
                (workspace_id, user_id, role_slug, membership_status, is_owner, joined_at)
             VALUES (?, ?, ?, 'active', ?, NOW())",
            [$workspaceId, $userId, $roleSlug, $isOwner]
        );
    }
}

function fixtureResolveUserWorkspaceId(int $userId): int
{
    if ($userId <= 0 || !fixtureTableExists('workspace_memberships')) {
        return fixtureDefaultWorkspaceId();
    }

    $membership = Database::queryOne(
        "SELECT workspace_id
         FROM workspace_memberships
         WHERE user_id = ?
           AND membership_status = 'active'
         ORDER BY is_owner DESC, id ASC
         LIMIT 1",
        [$userId]
    );

    return max(1, (int) ($membership['workspace_id'] ?? fixtureDefaultWorkspaceId()));
}

function fixtureTableExists(string $table): bool
{
    $row = Database::queryOne(
        'SELECT COUNT(*) AS cnt FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
        [$table]
    );
    return ((int) ($row['cnt'] ?? 0)) > 0;
}

function fixtureEnsureUserRole(string $email, string $roleSlug): array
{
    $user = fixtureEnsureUser($email);
    $role = fixtureFindRoleBySlug($roleSlug);
    $roleId = (int) ($role['id'] ?? 0);
    if ($roleId <= 0) {
        throw new RuntimeException('Role not found: ' . $roleSlug);
    }

    $existing = Database::queryOne('SELECT role_id FROM user_roles WHERE user_id = ? LIMIT 1', [$user['id']]);
    if ($existing) {
        Database::execute(
            'UPDATE user_roles SET role_id = ?, assigned_by = ?, updated_at = NOW() WHERE user_id = ?',
            [$roleId, $user['id'], $user['id']]
        );
    } else {
        Database::execute(
            'INSERT INTO user_roles (user_id, role_id, assigned_by) VALUES (?, ?, ?)',
            [$user['id'], $roleId, $user['id']]
        );
    }

    return [
        'user_id' => (int) $user['id'],
        'role_id' => $roleId,
        'role_slug' => $roleSlug,
        'email' => $email,
    ];
}

function fixtureEnsureUser(
    string $email,
    string $password = 'password',
    string $profileRole = 'viewer',
    string $firstName = 'Playwright',
    string $lastName = 'User'
): array {
    $email = trim(strtolower($email));
    if ($email === '') {
        throw new RuntimeException('Email is required for fixture user creation.');
    }

    $user = fixtureFindUserByEmail($email);
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    if ($user) {
        Database::execute(
            'UPDATE users
             SET first_name = ?, last_name = ?, role = ?, password_hash = ?, email_verified_at = COALESCE(email_verified_at, NOW())
             WHERE id = ?',
            [$firstName, $lastName, $profileRole, $passwordHash, (int) $user['id']]
        );
        $user = fixtureFindUserByEmail($email);
    } else {
        $uuid = fixtureUuid();
        try {
            Database::execute(
                'INSERT INTO users (uuid, first_name, last_name, email, password_hash, role, email_verified_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())',
                [$uuid, $firstName, $lastName, $email, $passwordHash, $profileRole]
            );
        } catch (\Throwable $e) {
            if (stripos($e->getMessage(), 'Duplicate entry') === false) {
                throw $e;
            }
        }
        $user = fixtureFindUserByEmail($email);
        if ($user) {
            Database::execute(
                'UPDATE users
                 SET first_name = ?, last_name = ?, role = ?, password_hash = ?, email_verified_at = COALESCE(email_verified_at, NOW())
                 WHERE id = ?',
                [$firstName, $lastName, $profileRole, $passwordHash, (int) $user['id']]
            );
            $user = fixtureFindUserByEmail($email);
        }
    }

    if (!$user) {
        throw new RuntimeException('Unable to ensure fixture user for email: ' . $email);
    }

    fixtureEnsureWorkspaceMembership((int) $user['id'], $profileRole);

    return [
        'id' => (int) ($user['id'] ?? 0),
        'email' => (string) ($user['email'] ?? $email),
        'role' => (string) ($user['role'] ?? $profileRole),
    ];
}

function fixtureEnsureOwnerHelpExpert(string $email): array
{
    if (!fixtureTableExists('owner_help_expert_profiles') || !fixtureTableExists('owner_help_expert_skills')) {
        throw new RuntimeException('Owner Help Center expert schema is not installed.');
    }

    $user = fixtureEnsureUser($email, 'password', 'admin', 'Verified', 'Expert');
    fixtureEnsureUserRole($email, 'superadmin');
    $userId = (int) ($user['id'] ?? 0);

    Database::execute(
        "INSERT INTO owner_help_expert_profiles
            (user_id, role_label, headline, bio, cv_summary, setup_areas_json, industries_json, languages_json, timezone, availability_summary, profile_status, is_internal)
         VALUES (?, 'Setup Specialist', 'Verified internal setup and launch support', 'Helps owners configure CRM setup, communication channels, automation readiness, and launch handoff.', 'Internal CRM operator with setup, support, and launch readiness experience.', JSON_ARRAY('Workspace setup', 'Email and WhatsApp setup', 'Automation readiness'), JSON_ARRAY('Startups', 'Service businesses'), JSON_ARRAY('English'), 'Africa/Nairobi', 'Available by request from Help Center.', 'active', 1)
         ON DUPLICATE KEY UPDATE
            role_label = VALUES(role_label),
            headline = VALUES(headline),
            bio = VALUES(bio),
            cv_summary = VALUES(cv_summary),
            setup_areas_json = VALUES(setup_areas_json),
            industries_json = VALUES(industries_json),
            languages_json = VALUES(languages_json),
            timezone = VALUES(timezone),
            availability_summary = VALUES(availability_summary),
            profile_status = 'active',
            is_internal = 1",
        [$userId]
    );
    $profile = Database::queryOne('SELECT id FROM owner_help_expert_profiles WHERE user_id = ? LIMIT 1', [$userId]);
    $profileId = (int) ($profile['id'] ?? 0);
    if ($profileId <= 0) {
        throw new RuntimeException('Unable to create owner help expert profile.');
    }

    foreach ([
        ['workspace_setup', 'Workspace setup', 'system_verified', 'Verified by fixture setup operations', 10],
        ['marketplace_setup', 'Marketplace setup', 'platform_verified', 'Reviewed for marketplace setup support', 20],
        ['launch_readiness', 'Launch readiness', 'platform_verified', 'Reviewed for launch readiness support', 30],
    ] as [$skillKey, $skillLabel, $level, $evidence, $sortOrder]) {
        Database::execute(
            "INSERT INTO owner_help_expert_skills (expert_profile_id, skill_key, skill_label, verification_level, evidence_label, sort_order)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                skill_label = VALUES(skill_label),
                verification_level = VALUES(verification_level),
                evidence_label = VALUES(evidence_label),
                sort_order = VALUES(sort_order)",
            [$profileId, $skillKey, $skillLabel, $level, $evidence, $sortOrder]
        );
    }

    return [
        'profile_id' => $profileId,
        'user_id' => $userId,
        'email' => $email,
    ];
}

function fixtureEnsureWorkspaceOwner(string $email, string $password = 'password', string $workspaceName = 'Playwright Help Workspace'): array
{
    $email = trim(strtolower($email));
    if ($email === '') {
        throw new RuntimeException('Email is required for workspace owner fixture creation.');
    }

    $existingUser = fixtureFindUserByEmail($email);
    if ($existingUser) {
        $membership = Database::queryOne(
            "SELECT wm.id AS membership_id, wm.workspace_id, w.uuid AS workspace_uuid, w.slug AS workspace_slug, w.name AS workspace_name
             FROM workspace_memberships wm
             JOIN workspaces w ON w.id = wm.workspace_id
             WHERE wm.user_id = ?
               AND wm.membership_status = 'active'
               AND (wm.is_owner = 1 OR wm.role_slug = 'owner')
               AND wm.workspace_id <> ?
             ORDER BY wm.id ASC
             LIMIT 1",
            [(int) $existingUser['id'], fixtureDefaultWorkspaceId()]
        );
        if ($membership) {
            Database::execute(
                "UPDATE users
                 SET first_name = 'Owner', last_name = 'Help', role = 'owner', password_hash = ?, email_verified_at = COALESCE(email_verified_at, NOW())
                 WHERE id = ?",
                [password_hash($password, PASSWORD_DEFAULT), (int) $existingUser['id']]
            );
            return [
                'user_id' => (int) $existingUser['id'],
                'email' => $email,
                'workspace_id' => (int) ($membership['workspace_id'] ?? 0),
                'workspace_uuid' => (string) ($membership['workspace_uuid'] ?? ''),
                'workspace_slug' => (string) ($membership['workspace_slug'] ?? ''),
                'workspace_name' => (string) ($membership['workspace_name'] ?? $workspaceName),
                'membership_id' => (int) ($membership['membership_id'] ?? 0),
            ];
        }
    }

    $seed = (new WorkspaceProvisioningService())->signupWorkspaceOwner([
        'workspace_name' => $workspaceName,
        'first_name' => 'Owner',
        'last_name' => 'Help',
        'email' => $email,
        'password' => $password,
        'starter_token_pack' => false,
    ]);

    $workspace = Database::queryOne(
        "SELECT uuid, slug, name FROM workspaces WHERE id = ? LIMIT 1",
        [(int) ($seed['workspace_id'] ?? 0)]
    ) ?: [];
    $membership = Database::queryOne(
        "SELECT id FROM workspace_memberships WHERE workspace_id = ? AND user_id = ? LIMIT 1",
        [(int) ($seed['workspace_id'] ?? 0), (int) ($seed['user_id'] ?? 0)]
    ) ?: [];

    return [
        'user_id' => (int) ($seed['user_id'] ?? 0),
        'email' => $email,
        'workspace_id' => (int) ($seed['workspace_id'] ?? 0),
        'workspace_uuid' => (string) ($workspace['uuid'] ?? ''),
        'workspace_slug' => (string) ($workspace['slug'] ?? ''),
        'workspace_name' => (string) ($workspace['name'] ?? $workspaceName),
        'membership_id' => (int) ($membership['id'] ?? 0),
    ];
}

function fixtureCompleteWorkspaceOnboarding(int $workspaceId): array
{
    if ($workspaceId <= 0) {
        throw new RuntimeException('Workspace id is required to complete onboarding.');
    }

    if (!fixtureTableExists('workspace_onboarding_state')) {
        return ['workspace_id' => $workspaceId, 'completed' => false, 'reason' => 'workspace_onboarding_state missing'];
    }

    Database::execute(
        "INSERT INTO workspace_onboarding_state
            (workspace_id, status, current_step, required_steps_json, completed_steps_json, skipped_optional_json, automation_launch_mode, ai_autoresponder_mode, deal_automation_enabled, completed_at)
         VALUES (?, 'completed', 5, JSON_ARRAY('company', 'review'), JSON_ARRAY('company', 'review'), JSON_ARRAY('products', 'voice', 'automation'), 'learning_on_the_go', 'draft_only', 1, NOW())
         ON DUPLICATE KEY UPDATE
            status = 'completed',
            current_step = 5,
            required_steps_json = JSON_ARRAY('company', 'review'),
            completed_steps_json = JSON_ARRAY('company', 'review'),
            skipped_optional_json = JSON_ARRAY('products', 'voice', 'automation'),
            automation_launch_mode = COALESCE(automation_launch_mode, 'learning_on_the_go'),
            ai_autoresponder_mode = COALESCE(ai_autoresponder_mode, 'draft_only'),
            deal_automation_enabled = COALESCE(deal_automation_enabled, 1),
            completed_at = COALESCE(completed_at, NOW()),
            updated_at = NOW()",
        [$workspaceId]
    );

    return ['workspace_id' => $workspaceId, 'completed' => true];
}

function fixtureInstallWorkspaceSkill(int $workspaceId, int $userId, string $skillKey, array $config = []): array
{
    $skillKey = trim(strtolower($skillKey));
    if ($workspaceId <= 0 || $userId <= 0 || $skillKey === '') {
        throw new RuntimeException('Workspace, user, and skill key are required for plugin installation.');
    }
    if (!fixtureTableExists('workspace_skill_installs')) {
        throw new RuntimeException('Workspace skill installation table is not available.');
    }

    (new WorkspaceSkillCatalogService())->syncDefinitions();
    $definition = Database::queryOne(
        'SELECT skill_key FROM workspace_skill_definitions WHERE skill_key = ? LIMIT 1',
        [$skillKey]
    );
    if (!$definition) {
        throw new RuntimeException('Unknown workspace skill: ' . $skillKey);
    }

    $config = array_merge(['source' => 'browser_fixture'], $config);
    Database::execute(
        "INSERT INTO workspace_skill_installs (
            workspace_id, skill_key, status, config_json, installed_by_user_id, updated_by_user_id, installed_at
         ) VALUES (?, ?, 'installed', ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE
            status = 'installed',
            config_json = VALUES(config_json),
            updated_by_user_id = VALUES(updated_by_user_id),
            installed_at = COALESCE(installed_at, NOW()),
            uninstalled_at = NULL,
            disabled_at = NULL",
        [
            $workspaceId,
            $skillKey,
            json_encode($config, JSON_UNESCAPED_SLASHES),
            $userId,
            $userId,
        ]
    );

    return [
        'workspace_id' => $workspaceId,
        'user_id' => $userId,
        'skill_key' => $skillKey,
        'installed' => true,
    ];
}

function fixtureUninstallWorkspaceSkill(int $workspaceId, string $skillKey): array
{
    $skillKey = trim(strtolower($skillKey));
    if ($workspaceId <= 0 || $skillKey === '') {
        throw new RuntimeException('Workspace and skill key are required for plugin removal.');
    }

    Database::execute(
        "UPDATE workspace_skill_installs
         SET status = 'disabled',
             disabled_at = NOW(),
             uninstalled_at = NOW(),
             updated_at = NOW()
         WHERE workspace_id = ?
           AND skill_key = ?",
        [$workspaceId, $skillKey]
    );

    return [
        'workspace_id' => $workspaceId,
        'skill_key' => $skillKey,
        'installed' => false,
    ];
}

function fixtureWorkspaceSkillInstallState(int $workspaceId, string $skillKey): array
{
    $skillKey = trim(strtolower($skillKey));
    if ($workspaceId <= 0 || $skillKey === '') {
        throw new RuntimeException('Workspace and skill key are required to inspect plugin state.');
    }

    $row = Database::queryOne(
        "SELECT status, config_json, installed_by_user_id, updated_by_user_id,
                installed_at, disabled_at, uninstalled_at, created_at, updated_at
         FROM workspace_skill_installs
         WHERE workspace_id = ?
           AND skill_key = ?
         LIMIT 1",
        [$workspaceId, $skillKey]
    );

    return [
        'workspace_id' => $workspaceId,
        'skill_key' => $skillKey,
        'exists' => $row !== null,
        'row' => $row ?: null,
    ];
}

function fixtureRestoreWorkspaceSkillInstallState(int $workspaceId, string $skillKey, array $state): array
{
    $skillKey = trim(strtolower($skillKey));
    if ($workspaceId <= 0 || $skillKey === '') {
        throw new RuntimeException('Workspace and skill key are required to restore plugin state.');
    }

    if (empty($state['exists']) || !is_array($state['row'] ?? null)) {
        Database::execute(
            'DELETE FROM workspace_skill_installs WHERE workspace_id = ? AND skill_key = ?',
            [$workspaceId, $skillKey]
        );
        return ['workspace_id' => $workspaceId, 'skill_key' => $skillKey, 'restored' => true, 'exists' => false];
    }

    $row = (array) $state['row'];
    Database::execute(
        "INSERT INTO workspace_skill_installs (
            workspace_id, skill_key, status, config_json, installed_by_user_id, updated_by_user_id,
            installed_at, disabled_at, uninstalled_at, created_at, updated_at
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, COALESCE(?, NOW()), COALESCE(?, NOW()))
         ON DUPLICATE KEY UPDATE
            status = VALUES(status),
            config_json = VALUES(config_json),
            installed_by_user_id = VALUES(installed_by_user_id),
            updated_by_user_id = VALUES(updated_by_user_id),
            installed_at = VALUES(installed_at),
            disabled_at = VALUES(disabled_at),
            uninstalled_at = VALUES(uninstalled_at),
            created_at = VALUES(created_at),
            updated_at = VALUES(updated_at)",
        [
            $workspaceId,
            $skillKey,
            (string) ($row['status'] ?? 'disabled'),
            $row['config_json'] ?? null,
            isset($row['installed_by_user_id']) ? (int) $row['installed_by_user_id'] : null,
            isset($row['updated_by_user_id']) ? (int) $row['updated_by_user_id'] : null,
            $row['installed_at'] ?? null,
            $row['disabled_at'] ?? null,
            $row['uninstalled_at'] ?? null,
            $row['created_at'] ?? null,
            $row['updated_at'] ?? null,
        ]
    );

    return ['workspace_id' => $workspaceId, 'skill_key' => $skillKey, 'restored' => true, 'exists' => true];
}

function fixtureEnsureRoleWithPermissions(string $slug, string $name, array $permissionKeys, string $description = ''): array
{
    $slug = trim(strtolower($slug));
    $name = trim($name);
    if ($slug === '' || $name === '') {
        throw new RuntimeException('Role slug and name are required.');
    }

    $role = fixtureFindRoleBySlug($slug);
    if ($role) {
        Database::execute(
            'UPDATE roles SET name = ?, description = ?, is_system = 0, is_active = 1, updated_at = NOW() WHERE id = ?',
            [$name, $description !== '' ? $description : null, (int) $role['id']]
        );
        $roleId = (int) $role['id'];
    } else {
        Database::execute(
            'INSERT INTO roles (name, slug, description, is_system, is_active) VALUES (?, ?, ?, 0, 1)',
            [$name, $slug, $description !== '' ? $description : null]
        );
        $roleId = (int) Database::lastInsertId();
    }

    Database::execute('DELETE FROM role_permissions WHERE role_id = ?', [$roleId]);

    if ($permissionKeys !== []) {
        $normalizedKeys = array_values(array_unique(array_filter(array_map(
            static fn($key): string => trim((string) $key),
            $permissionKeys
        ))));
        if ($normalizedKeys !== []) {
            $placeholders = implode(',', array_fill(0, count($normalizedKeys), '?'));
            $permissionRows = Database::query(
                "SELECT id, permission_key FROM permissions WHERE permission_key IN ($placeholders)",
                $normalizedKeys
            );
            $permissionMap = [];
            foreach ($permissionRows as $permissionRow) {
                $permissionMap[(string) $permissionRow['permission_key']] = (int) $permissionRow['id'];
            }

            $missing = array_values(array_diff($normalizedKeys, array_keys($permissionMap)));
            if ($missing !== []) {
                throw new RuntimeException('Unknown permissions: ' . implode(', ', $missing));
            }

            foreach ($normalizedKeys as $permissionKey) {
                Database::execute(
                    'INSERT INTO role_permissions (role_id, permission_id, can_access) VALUES (?, ?, 1)',
                    [$roleId, $permissionMap[$permissionKey]]
                );
            }
        }
    }

    return [
        'role_id' => $roleId,
        'role_slug' => $slug,
        'role_name' => $name,
        'permission_keys' => array_values($permissionKeys),
    ];
}

function fixtureEnsureUserWithRole(
    string $email,
    string $roleSlug,
    string $roleName,
    array $permissionKeys,
    string $password = 'password',
    string $profileRole = 'viewer',
    ?string $namespace = null
): array {
    fixtureEnsureRoleWithPermissions($roleSlug, $roleName, $permissionKeys, $namespace ? 'Playwright role ' . $namespace : 'Playwright role');
    $user = fixtureEnsureUser(
        $email,
        $password,
        $profileRole,
        'PW',
        $namespace ? strtoupper(substr(preg_replace('/[^a-z0-9]/i', '', $namespace), 0, 10)) : 'USER'
    );
    $roleAssignment = fixtureEnsureUserRole($email, $roleSlug);

    return [
        'user_id' => (int) $user['id'],
        'email' => $email,
        'role_slug' => $roleSlug,
        'role_id' => (int) $roleAssignment['role_id'],
    ];
}

function fixtureEnableFakePaystackMode(): void
{
    $_ENV['PAYSTACK_FAKE_MODE'] = 'true';
    $_ENV['PAYSTACK_SECRET_KEY'] = 'test-secret';
    $_ENV['PAYSTACK_FAKE_AUTH_BASE_URL'] = 'https://paystack.example/authorize';
    $_ENV['MPESA_ENABLED'] = 'true';
    $_ENV['MPESA_FAKE_MODE'] = 'true';
    $_ENV['MPESA_FAKE_STK_RESPONSE_CODE'] = '0';
    $_ENV['MPESA_FAKE_QUERY_RESULT_CODE'] = '0';
    $_ENV['MPESA_FAKE_RECEIPT_NUMBER'] = 'CRMTEST123';
    putenv('PAYSTACK_FAKE_MODE=true');
    putenv('PAYSTACK_SECRET_KEY=test-secret');
    putenv('PAYSTACK_FAKE_AUTH_BASE_URL=https://paystack.example/authorize');
    putenv('MPESA_ENABLED=true');
    putenv('MPESA_FAKE_MODE=true');
    putenv('MPESA_FAKE_STK_RESPONSE_CODE=0');
    putenv('MPESA_FAKE_QUERY_RESULT_CODE=0');
    putenv('MPESA_FAKE_RECEIPT_NUMBER=CRMTEST123');
}

function fixtureBillingPlanPriceIdByCode(string $priceCode): int
{
    $row = Database::queryOne(
        'SELECT id FROM billing_plan_prices WHERE price_code = ? AND is_active = 1 LIMIT 1',
        [trim($priceCode)]
    );
    $priceId = (int) ($row['id'] ?? 0);
    if ($priceId <= 0) {
        throw new RuntimeException('Billing plan price not found: ' . $priceCode);
    }
    return $priceId;
}

function fixtureTokenPackPriceIdByCode(string $priceCode): int
{
    $row = Database::queryOne(
        'SELECT tpp.id
         FROM token_pack_prices tpp
         JOIN billing_plan_prices bpp ON bpp.id = tpp.billing_plan_price_id
         WHERE bpp.price_code = ?
           AND tpp.is_active = 1
           AND bpp.is_active = 1
         LIMIT 1',
        [trim($priceCode)]
    );
    $priceId = (int) ($row['id'] ?? 0);
    if ($priceId <= 0) {
        throw new RuntimeException('Token pack price not found: ' . $priceCode);
    }
    return $priceId;
}

function fixtureGetWorkspaceContext(string $workspaceSlug, ?string $ownerEmail = null): array
{
    $workspaceSlug = trim($workspaceSlug);
    if ($workspaceSlug === '') {
        throw new RuntimeException('Workspace slug is required.');
    }

    $workspace = Database::queryOne(
        "SELECT id, uuid, name, slug, status, plan_status
         FROM workspaces
         WHERE slug = ?
         LIMIT 1",
        [$workspaceSlug]
    );
    if ($workspace === null) {
        throw new RuntimeException('Workspace not found for slug: ' . $workspaceSlug);
    }

    $owner = $ownerEmail !== null && trim($ownerEmail) !== ''
        ? Database::queryOne(
            "SELECT u.id, u.email, wm.id AS membership_id
             FROM users u
             JOIN workspace_memberships wm ON wm.user_id = u.id
             WHERE wm.workspace_id = ?
               AND u.email = ?
             LIMIT 1",
            [(int) $workspace['id'], trim(strtolower($ownerEmail))]
        )
        : Database::queryOne(
            "SELECT u.id, u.email, wm.id AS membership_id
             FROM workspace_memberships wm
             JOIN users u ON u.id = wm.user_id
             WHERE wm.workspace_id = ?
               AND wm.membership_status = 'active'
             ORDER BY wm.is_owner DESC, wm.id ASC
             LIMIT 1",
            [(int) $workspace['id']]
        );

    return [
        'workspace_id' => (int) ($workspace['id'] ?? 0),
        'workspace_slug' => (string) ($workspace['slug'] ?? ''),
        'workspace_name' => (string) ($workspace['name'] ?? ''),
        'user_id' => (int) ($owner['id'] ?? 0),
        'user_email' => (string) ($owner['email'] ?? ''),
        'membership_id' => (int) ($owner['membership_id'] ?? 0),
    ];
}

function fixtureCreateWorkspaceInvite(int $workspaceId, int $actorUserId, string $email, string $roleSlug = 'viewer'): array
{
    $result = (new WorkspaceGovernanceService())->createInvite($workspaceId, $actorUserId, $email, $roleSlug);

    return [
        'invite' => (array) ($result['invite'] ?? []),
        'token' => (string) ($result['token'] ?? ''),
        'invite_url' => (string) ($result['invite_url'] ?? ''),
        'delivery' => (array) ($result['delivery'] ?? []),
    ];
}

function fixtureCreateWorkspaceCheckout(
    int $workspaceId,
    int $userId,
    ?int $billingPlanPriceId = null,
    ?int $tokenPackPriceId = null,
    ?string $paymentMode = null,
    ?string $customerPhone = null
): array
{
    fixtureEnableFakePaystackMode();

    return (new SaaSBillingService())->createCheckout(
        $workspaceId,
        [
            'billing_plan_price_id' => $billingPlanPriceId,
            'token_pack_price_id' => $tokenPackPriceId,
            'payment_mode' => $paymentMode,
            'customer_phone' => $customerPhone,
        ],
        $userId
    );
}

function fixtureVerifyWorkspaceCheckout(string $reference): array
{
    fixtureEnableFakePaystackMode();
    return (new SaaSBillingService())->verifyCheckoutReference($reference);
}

function fixtureProcessWorkspaceCheckoutWebhook(string $reference, string $event = 'charge.success', string $status = 'success'): array
{
    fixtureEnableFakePaystackMode();

    $payload = json_encode([
        'event' => $event,
        'data' => [
            'reference' => $reference,
            'status' => $status,
            'paid_at' => date('c'),
        ],
    ], JSON_UNESCAPED_SLASHES) ?: '{}';

    return (new SaaSBillingService())->processWebhook(
        $payload,
        hash_hmac('sha512', $payload, 'test-secret')
    );
}

function fixtureCleanupWorkspaceNamespace(string $namespace): array
{
    $namespace = trim($namespace);
    if ($namespace === '') {
        return ['namespace' => $namespace, 'cleaned' => true];
    }

    $like = '%' . $namespace . '%';
    $workspaces = Database::query(
        "SELECT id
         FROM workspaces
         WHERE slug LIKE ?
            OR name LIKE ?",
        [$like, $like]
    );
    $workspaceIds = array_values(array_filter(array_map(static fn(array $row): int => (int) ($row['id'] ?? 0), $workspaces)));

    foreach ($workspaceIds as $workspaceId) {
        Database::execute('DELETE FROM workspace_ai_usage WHERE workspace_id = ?', [$workspaceId]);
        Database::execute('DELETE FROM billing_provider_events WHERE workspace_id = ?', [$workspaceId]);
        Database::execute('DELETE FROM billing_transactions WHERE workspace_id = ?', [$workspaceId]);
        Database::execute('DELETE FROM workspace_wallet_ledger WHERE workspace_id = ?', [$workspaceId]);
        Database::execute('DELETE FROM billing_checkout_sessions WHERE workspace_id = ?', [$workspaceId]);
        Database::execute('DELETE FROM workspace_subscriptions WHERE workspace_id = ?', [$workspaceId]);
        Database::execute('DELETE FROM workspace_wallets WHERE workspace_id = ?', [$workspaceId]);
        Database::execute('DELETE FROM workspace_governance_events WHERE workspace_id = ?', [$workspaceId]);
        Database::execute('DELETE FROM workspace_invites WHERE workspace_id = ?', [$workspaceId]);
        Database::execute('DELETE FROM mobile_auth_tokens WHERE workspace_id = ?', [$workspaceId]);
        Database::execute('DELETE FROM workspace_slugs WHERE workspace_id = ?', [$workspaceId]);
        Database::execute('DELETE FROM workspace_memberships WHERE workspace_id = ?', [$workspaceId]);
        if (fixtureTableExists('operator_audit_log')) {
            Database::execute('DELETE FROM operator_audit_log WHERE target_workspace_id = ?', [$workspaceId]);
        }
        Database::execute('DELETE FROM workspaces WHERE id = ?', [$workspaceId]);
    }

    Database::execute('DELETE FROM users WHERE email LIKE ?', [$like]);

    return ['namespace' => $namespace, 'workspace_count' => count($workspaceIds), 'cleaned' => true];
}

function fixtureCreateContact(string $namespace, int $userId): array
{
    $workspaceId = fixtureResolveUserWorkspaceId($userId);
    $email = strtolower($namespace) . '@example.test';
    $phone = '071' . str_pad((string) random_int(1000000, 9999999), 7, '0', STR_PAD_LEFT);
    $firstName = 'PW';
    $lastName = strtoupper(substr($namespace, -8));
    $company = 'Playwright ' . $namespace;

    Database::execute(
        'INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, phone, company, lead_source, stage, assigned_to, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $workspaceId,
            fixtureUuid(),
            $firstName,
            $lastName,
            $email,
            $phone,
            $company,
            'other',
            'proposal',
            $userId,
            $userId,
        ]
    );

    return [
        'id' => (int) Database::lastInsertId(),
        'email' => $email,
        'phone' => $phone,
        'full_name' => trim($firstName . ' ' . $lastName),
        'company' => $company,
    ];
}

function fixtureSeedContactRecord(string $namespace, ?string $assignedToEmail = null, ?string $createdByEmail = null): array
{
    $assignedToId = $assignedToEmail ? fixtureFindUserIdByEmail($assignedToEmail) : 0;
    $createdById = $createdByEmail ? fixtureFindUserIdByEmail($createdByEmail) : 0;
    $workspaceId = fixtureResolveUserWorkspaceId($assignedToId > 0 ? $assignedToId : $createdById);

    if ($assignedToEmail && $assignedToId <= 0) {
        throw new RuntimeException('Unable to resolve assigned contact owner: ' . $assignedToEmail);
    }
    if ($createdByEmail && $createdById <= 0) {
        throw new RuntimeException('Unable to resolve contact creator: ' . $createdByEmail);
    }

    $email = strtolower($namespace) . '@example.test';
    $phone = '071' . str_pad((string) random_int(1000000, 9999999), 7, '0', STR_PAD_LEFT);

    Database::execute(
        'INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, phone, company, lead_source, stage, assigned_to, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [
            $workspaceId,
            fixtureUuid(),
            'PW',
            strtoupper(substr($namespace, -8)),
            $email,
            $phone,
            'Playwright ' . $namespace,
            'other',
            'qualification',
            $assignedToId > 0 ? $assignedToId : null,
            $createdById > 0 ? $createdById : null,
        ]
    );

    return [
        'contact_id' => (int) Database::lastInsertId(),
        'email' => $email,
        'namespace' => $namespace,
    ];
}

function fixtureSeedEmailConversation(string $namespace, string $userEmail): array
{
    $userId = fixtureFindUserIdByEmail($userEmail);
    if ($userId <= 0) {
        throw new RuntimeException('Unable to resolve user for seeded conversation.');
    }

    $workspaceId = fixtureResolveUserWorkspaceId($userId);
    $contact = fixtureCreateContact($namespace, $userId);
    $threadKey = 'playwright:' . $namespace;
    $subject = 'Proposal and scope shared ' . $namespace;
    $fromEmail = $contact['email'];
    $toEmail = (string) ($_ENV['SMTP_FROM_EMAIL'] ?? 'sales@example.test');
    $messageId = '<' . $namespace . '-inbound@example.test>';

    Database::execute(
        "INSERT INTO communications
            (workspace_id, uuid, contact_id, thread_key, channel, direction, subject, body, metadata, status, created_at, from_email, to_email, message_id)
         VALUES
            (?, ?, ?, ?, 'email', 'inbound', ?, ?, ?, 'sent', DATE_SUB(NOW(), INTERVAL 10 MINUTE), ?, ?, ?)",
        [
            $workspaceId,
            fixtureUuid(),
            $contact['id'],
            $threadKey,
            $subject,
            "Hi team,\n\nPlease share the latest proposal, quote, and rollout timeline for our showroom refresh.\n\nThanks,\n" . $contact['full_name'],
            json_encode(['source' => 'playwright_fixture', 'namespace' => $namespace]),
            $fromEmail,
            $toEmail,
            $messageId,
        ]
    );
    $communicationId = (int) Database::lastInsertId();

    Database::execute(
        "INSERT INTO communications
            (workspace_id, uuid, contact_id, thread_key, channel, direction, subject, body, metadata, status, created_at, from_email, to_email, in_reply_to, message_id)
         VALUES
            (?, ?, ?, ?, 'email', 'outbound', ?, ?, ?, 'sent', DATE_SUB(NOW(), INTERVAL 5 MINUTE), ?, ?, ?, ?)",
        [
            $workspaceId,
            fixtureUuid(),
            $contact['id'],
            $threadKey,
            'Re: ' . $subject,
            "Thanks {$contact['full_name']},\n\nWe have prepared the initial scope and pricing summary for your review.",
            json_encode(['source' => 'playwright_fixture', 'namespace' => $namespace]),
            $toEmail,
            $fromEmail,
            $messageId,
            '<' . $namespace . '-outbound@example.test>',
        ]
    );

    if (fixtureTableExists('conversation_threads')) {
        $existingThread = Database::queryOne(
            'SELECT id FROM conversation_threads WHERE thread_key = ? LIMIT 1',
            [$threadKey]
        );
        if (!$existingThread) {
            Database::execute(
                "INSERT INTO conversation_threads
                    (workspace_id, contact_id, channel, thread_key, last_message_at, last_channel, status, message_count, is_resolved)
                 VALUES (?, ?, 'email', ?, NOW(), 'email', 'waiting_on_contact', 2, 0)",
                [$workspaceId, $contact['id'], $threadKey]
            );
        }
    }

    return [
        'communication_id' => $communicationId,
        'contact_id' => $contact['id'],
        'contact_email' => $contact['email'],
        'subject' => $subject,
        'search_term' => $namespace,
        'thread_key' => $threadKey,
    ];
}

function fixtureSeedScopedEmailConversation(
    string $namespace,
    string $createdByEmail,
    ?string $threadOwnerEmail = null
): array {
    $creatorId = fixtureFindUserIdByEmail($createdByEmail);
    if ($creatorId <= 0) {
        throw new RuntimeException('Unable to resolve creator for seeded conversation.');
    }

    $threadOwnerId = $threadOwnerEmail ? fixtureFindUserIdByEmail($threadOwnerEmail) : 0;
    if ($threadOwnerEmail && $threadOwnerId <= 0) {
        throw new RuntimeException('Unable to resolve conversation owner: ' . $threadOwnerEmail);
    }

    $workspaceSeedUserId = $threadOwnerId > 0 ? $threadOwnerId : $creatorId;
    $workspaceId = fixtureResolveUserWorkspaceId($workspaceSeedUserId);
    $contactSeed = fixtureSeedContactRecord($namespace . '-contact', $threadOwnerEmail, $createdByEmail);
    $threadKey = 'playwright:' . $namespace;
    $subject = 'Playwright Scoped Thread ' . $namespace;
    $contactEmail = $contactSeed['email'];
    $toEmail = (string) ($_ENV['SMTP_FROM_EMAIL'] ?? 'sales@example.test');
    $messageId = '<' . $namespace . '-seed@example.test>';

    Database::execute(
        "INSERT INTO communications
            (workspace_id, uuid, contact_id, thread_key, channel, direction, subject, body, metadata, status, created_at, from_email, to_email, message_id)
         VALUES
            (?, ?, ?, ?, 'email', 'inbound', ?, ?, ?, 'sent', DATE_SUB(NOW(), INTERVAL 9 MINUTE), ?, ?, ?)",
        [
            $workspaceId,
            fixtureUuid(),
            $contactSeed['contact_id'],
            $threadKey,
            $subject,
            "Scoped thread seed {$namespace}",
            json_encode(['source' => 'playwright_fixture', 'namespace' => $namespace, 'created_by_email' => $createdByEmail]),
            $contactEmail,
            $toEmail,
            $messageId,
        ]
    );
    $communicationId = (int) Database::lastInsertId();

    if (fixtureTableExists('conversation_threads')) {
        Database::execute(
            "INSERT INTO conversation_threads
                (workspace_id, contact_id, channel, thread_key, last_message_at, status, current_owner_id, last_inbound_at, last_channel, message_count, is_resolved)
             VALUES (?, ?, 'email', ?, NOW(), 'open', ?, NOW(), 'email', 1, 0)
             ON DUPLICATE KEY UPDATE
                workspace_id = VALUES(workspace_id),
                current_owner_id = VALUES(current_owner_id),
                last_message_at = VALUES(last_message_at),
                last_inbound_at = VALUES(last_inbound_at),
                last_channel = VALUES(last_channel),
                message_count = VALUES(message_count),
                is_resolved = VALUES(is_resolved),
                status = VALUES(status)",
            [$workspaceId, $contactSeed['contact_id'], $threadKey, $threadOwnerId > 0 ? $threadOwnerId : null]
        );
    } else {
        Database::execute(
            "UPDATE communications
             SET metadata = JSON_SET(COALESCE(metadata, JSON_OBJECT()), '$.seed_owner_email', ?)
             WHERE id = ?",
            [$threadOwnerEmail, $communicationId]
        );
    }

    return [
        'communication_id' => $communicationId,
        'contact_id' => $contactSeed['contact_id'],
        'thread_key' => $threadKey,
        'subject' => $subject,
        'namespace' => $namespace,
    ];
}

function fixtureSeedNotifications(string $namespace, string $userEmail): array
{
    $userId = fixtureFindUserIdByEmail($userEmail);
    if ($userId <= 0) {
        throw new RuntimeException('Unable to resolve user for seeded notifications.');
    }

    $workspaceId = fixtureResolveUserWorkspaceId($userId);
    $rows = [
        ['system', 'Playwright Notice A ' . $namespace, 'First notification ' . $namespace, 0],
        ['task', 'Playwright Notice B ' . $namespace, 'Second notification ' . $namespace, 0],
        ['deal', 'Playwright Notice C ' . $namespace, 'Third notification ' . $namespace, 1],
    ];

    $ids = [];
    foreach ($rows as [$type, $title, $message, $isRead]) {
        Database::execute(
            'INSERT INTO notifications (workspace_id, user_id, type, title, message, is_read) VALUES (?, ?, ?, ?, ?, ?)',
            [$workspaceId, $userId, $type, $title, $message, $isRead]
        );
        $ids[] = (int) Database::lastInsertId();
    }

    return ['notification_ids' => $ids, 'user_id' => $userId];
}

function fixtureSeedDeal(string $namespace, string $userEmail): array
{
    $userId = fixtureFindUserIdByEmail($userEmail);
    if ($userId <= 0) {
        throw new RuntimeException('Unable to resolve user for seeded deal.');
    }

    $workspaceId = fixtureResolveUserWorkspaceId($userId);
    $contact = fixtureCreateContact($namespace . '-deal', $userId);
    $title = 'Playwright Deal ' . $namespace;

    Database::execute(
        'INSERT INTO deals (workspace_id, title, description, contact_id, assigned_to, created_by, stage, value, probability, expected_close_date, currency)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, DATE_ADD(CURDATE(), INTERVAL 14 DAY), ?)',
        [
            $workspaceId,
            $title,
            'Deterministic Playwright deal fixture.',
            $contact['id'],
            $userId,
            $userId,
            'proposal',
            12500.00,
            70,
            'KES',
        ]
    );

    return [
        'deal_id' => (int) Database::lastInsertId(),
        'contact_id' => $contact['id'],
        'title' => $title,
    ];
}

function fixtureGetDeal(int $dealId): array
{
    if ($dealId <= 0) {
        throw new RuntimeException('Deal id is required.');
    }

    $deal = Database::queryOne(
        'SELECT id, workspace_id, title, stage, assigned_to, created_by FROM deals WHERE id = ? LIMIT 1',
        [$dealId]
    );

    if (!$deal) {
        throw new RuntimeException('Deal not found.');
    }

    return [
        'deal_id' => (int) ($deal['id'] ?? 0),
        'workspace_id' => (int) ($deal['workspace_id'] ?? 0),
        'title' => (string) ($deal['title'] ?? ''),
        'stage' => (string) ($deal['stage'] ?? ''),
        'assigned_to' => (int) ($deal['assigned_to'] ?? 0),
        'created_by' => (int) ($deal['created_by'] ?? 0),
    ];
}

function fixtureSeedTarget(string $namespace, string $ownerEmail, string $scope = 'personal'): array
{
    $ownerId = fixtureFindUserIdByEmail($ownerEmail);
    if ($ownerId <= 0) {
        throw new RuntimeException('Unable to resolve user for seeded target.');
    }

    $targets = new \CRM\Modules\Targets();
    $title = 'Playwright Target ' . $namespace;
    $targetId = $targets->create([
        'title' => $title,
        'description' => 'Deterministic Playwright target fixture ' . $namespace,
        'user_id' => $ownerId,
        'scope' => $scope,
        'target_type' => 'custom',
        'progress_mode' => 'manual',
        'target_value' => 10,
        'current_value' => 2,
        'unit' => 'items',
        'start_date' => date('Y-m-d'),
        'target_date' => date('Y-m-d', strtotime('+30 days')),
        'reminder_frequency' => 'weekly',
    ]);

    return [
        'target_id' => $targetId,
        'title' => $title,
        'scope' => $scope,
        'owner_id' => $ownerId,
    ];
}

function fixtureSeedTaskWithSubtasks(string $namespace, string $userEmail): array
{
    $userId = fixtureFindUserIdByEmail($userEmail);
    if ($userId <= 0) {
        throw new RuntimeException('Unable to resolve user for seeded task.');
    }

    $workspaceId = fixtureResolveUserWorkspaceId($userId);
    $contact = fixtureCreateContact($namespace . '-task', $userId);
    $title = 'Playwright Task ' . $namespace;

    Database::execute(
        'INSERT INTO tasks (workspace_id, title, description, contact_id, assigned_to, created_by, status, priority, due_date)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 2 DAY))',
        [
            $workspaceId,
            $title,
            'Deterministic Playwright task fixture.',
            $contact['id'],
            $userId,
            $userId,
            'pending',
            'medium',
        ]
    );
    $taskId = (int) Database::lastInsertId();

    foreach ([
        'Confirm requirements',
        'Prepare scope summary',
    ] as $index => $subtaskTitle) {
        Database::execute(
            'INSERT INTO task_subtasks (task_id, title, description, completed, `order`) VALUES (?, ?, ?, 0, ?)',
            [$taskId, $subtaskTitle, 'Fixture subtask for Playwright.', $index]
        );
    }

    return [
        'task_id' => $taskId,
        'contact_id' => $contact['id'],
        'title' => $title,
    ];
}

function fixtureSeedForm(string $namespace, string $userEmail = ''): array
{
    $namespace = trim($namespace);
    if ($namespace === '') {
        throw new RuntimeException('Namespace is required for form fixture seeding.');
    }

    $createdBy = $userEmail !== '' ? fixtureFindUserIdByEmail($userEmail) : 0;
    $name = 'Smoke Form ' . $namespace;
    $forms = new Forms();
    $formId = $forms->create([
        'name' => $name,
        'fields' => [
            ['type' => 'text', 'label' => 'Name', 'name' => 'name', 'required' => true],
            ['type' => 'email', 'label' => 'Email', 'name' => 'email', 'required' => true],
        ],
        'success_message' => 'Thanks, we will follow up soon.',
        'redirect_url' => '',
        'created_by' => $createdBy > 0 ? $createdBy : null,
        'settings' => [
            'layout' => 'compact',
            'primary_color' => '#2563eb',
            'background_color' => '#f5f5f5',
            'button_color' => '#2563eb',
            'text_color' => '#1a1a1a',
            'border_radius' => 8,
        ],
    ]);

    $form = Database::queryOne(
        'SELECT id, uuid, name FROM forms WHERE id = ? LIMIT 1',
        [$formId]
    ) ?: [];

    return [
        'form_id' => (int) ($form['id'] ?? $formId),
        'uuid' => (string) ($form['uuid'] ?? ''),
        'name' => (string) ($form['name'] ?? $name),
    ];
}

function fixtureCleanupNamespace(string $namespace): array
{
    $like = '%' . $namespace . '%';

    if (fixtureTableExists('forms')) {
        $formRows = Database::query('SELECT id, uuid FROM forms WHERE name LIKE ?', [$like]);
        $formIds = array_map(static fn(array $row): int => (int) $row['id'], $formRows);
        $formUuids = array_values(array_filter(array_map(static fn(array $row): string => (string) ($row['uuid'] ?? ''), $formRows)));
        if ($formIds !== [] && fixtureTableExists('form_submissions') && Database::columnExists('form_submissions', 'form_definition_id')) {
            $formIdPlaceholders = implode(',', array_fill(0, count($formIds), '?'));
            Database::execute("DELETE FROM form_submissions WHERE form_definition_id IN ($formIdPlaceholders)", $formIds);
        }
        if ($formUuids !== [] && fixtureTableExists('form_submissions') && Database::columnExists('form_submissions', 'form_id')) {
            $formUuidPlaceholders = implode(',', array_fill(0, count($formUuids), '?'));
            Database::execute("DELETE FROM form_submissions WHERE form_id IN ($formUuidPlaceholders)", $formUuids);
        }
        if ($formIds !== []) {
            $formIdPlaceholders = implode(',', array_fill(0, count($formIds), '?'));
            Database::execute("DELETE FROM forms WHERE id IN ($formIdPlaceholders)", $formIds);
        }
    }

    $taskRows = Database::query('SELECT id FROM tasks WHERE title LIKE ? OR description LIKE ?', [$like, $like]);
    $taskIds = array_map(static fn(array $row): int => (int) $row['id'], $taskRows);
    if ($taskIds !== []) {
        $taskPlaceholders = implode(',', array_fill(0, count($taskIds), '?'));
        Database::execute("DELETE FROM task_subtasks WHERE task_id IN ($taskPlaceholders)", $taskIds);
        Database::execute("DELETE FROM tasks WHERE id IN ($taskPlaceholders)", $taskIds);
    }

    $dealRows = Database::query('SELECT id FROM deals WHERE title LIKE ? OR description LIKE ?', [$like, $like]);
    $dealIds = array_map(static fn(array $row): int => (int) $row['id'], $dealRows);
    if ($dealIds !== []) {
        $dealPlaceholders = implode(',', array_fill(0, count($dealIds), '?'));
        Database::execute("DELETE FROM deals WHERE id IN ($dealPlaceholders)", $dealIds);
    }

    $targetRows = Database::query('SELECT id FROM targets WHERE title LIKE ? OR description LIKE ?', [$like, $like]);
    foreach ($targetRows as $targetRow) {
        (new \CRM\Modules\Targets())->delete((int) ($targetRow['id'] ?? 0));
    }

    Database::execute(
        'DELETE FROM communications WHERE thread_key LIKE ? OR subject LIKE ? OR body LIKE ?',
        [$like, $like, $like]
    );

    Database::execute(
        'DELETE FROM notifications WHERE title LIKE ? OR message LIKE ?',
        [$like, $like]
    );

    if (fixtureTableExists('conversation_threads')) {
        Database::execute(
            'DELETE FROM conversation_threads WHERE thread_key LIKE ?',
            [$like]
        );
    }

    Database::execute(
        'DELETE FROM contacts WHERE email LIKE ? OR company LIKE ?',
        [$like, $like]
    );

    if (fixtureTableExists('workspace_billing_payment_intent_items') && fixtureTableExists('workspace_billing_payment_intents') && fixtureTableExists('workspace_billing_charges')) {
        $chargeRows = Database::query(
            'SELECT id FROM workspace_billing_charges WHERE title LIKE ? OR description LIKE ?',
            [$like, $like]
        );
        $chargeIds = array_map(static fn(array $row): int => (int) $row['id'], $chargeRows);
        if ($chargeIds !== []) {
            $chargePlaceholders = implode(',', array_fill(0, count($chargeIds), '?'));
            Database::execute(
                "DELETE FROM workspace_billing_payment_intent_items WHERE charge_id IN ($chargePlaceholders)",
                $chargeIds
            );
            Database::execute(
                "DELETE FROM workspace_billing_charges WHERE id IN ($chargePlaceholders)",
                $chargeIds
            );
        }
    }

    $roleRows = Database::query('SELECT id FROM roles WHERE slug LIKE ? OR name LIKE ?', [$like, $like]);
    $roleIds = array_map(static fn(array $row): int => (int) $row['id'], $roleRows);
    if ($roleIds !== []) {
        $rolePlaceholders = implode(',', array_fill(0, count($roleIds), '?'));
        Database::execute("DELETE FROM user_roles WHERE role_id IN ($rolePlaceholders)", $roleIds);
        Database::execute("DELETE FROM role_permissions WHERE role_id IN ($rolePlaceholders)", $roleIds);
        Database::execute("DELETE FROM roles WHERE id IN ($rolePlaceholders)", $roleIds);
    }

    Database::execute(
        'DELETE FROM users WHERE email LIKE ?',
        [$like]
    );

    return ['namespace' => $namespace, 'cleaned' => true];
}

function fixtureConfigureWorkspaceBilling(array $settings = []): array
{
    if (!fixtureTableExists('workspace_billing_settings')) {
        throw new RuntimeException('Workspace billing settings table is not available.');
    }

    $defaults = [
        'enabled' => 1,
        'paystack_mode' => 'test',
        'default_currency' => 'KES',
        'email_reminders_enabled' => 1,
        'in_app_prompts_enabled' => 1,
        'auto_lock_enabled' => 1,
        'grace_days' => 3,
        'reminder_days_before_json' => json_encode([7, 3, 1]),
        'workspace_status' => 'current',
        'trial_starts_at' => null,
        'trial_ends_at' => null,
        'trial_granted_by' => null,
        'trial_notes' => null,
    ];
    $data = array_merge($defaults, $settings);

    $exists = Database::queryOne('SELECT id FROM workspace_billing_settings WHERE id = 1');
    if ($exists) {
        Database::execute(
            "UPDATE workspace_billing_settings
             SET enabled = ?, paystack_mode = ?, default_currency = ?, email_reminders_enabled = ?,
                 in_app_prompts_enabled = ?, auto_lock_enabled = ?, grace_days = ?, reminder_days_before_json = ?,
                 workspace_status = ?, trial_starts_at = ?, trial_ends_at = ?, trial_granted_by = ?, trial_notes = ?,
                 updated_at = NOW()
             WHERE id = 1",
            [
                (int) $data['enabled'],
                (string) $data['paystack_mode'],
                (string) $data['default_currency'],
                (int) $data['email_reminders_enabled'],
                (int) $data['in_app_prompts_enabled'],
                (int) $data['auto_lock_enabled'],
                (int) $data['grace_days'],
                (string) $data['reminder_days_before_json'],
                (string) $data['workspace_status'],
                $data['trial_starts_at'],
                $data['trial_ends_at'],
                $data['trial_granted_by'],
                $data['trial_notes'],
            ]
        );
    } else {
        Database::execute(
            "INSERT INTO workspace_billing_settings
                (id, enabled, paystack_mode, default_currency, email_reminders_enabled, in_app_prompts_enabled, auto_lock_enabled, grace_days, reminder_days_before_json, workspace_status, trial_starts_at, trial_ends_at, trial_granted_by, trial_notes)
             VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                (int) $data['enabled'],
                (string) $data['paystack_mode'],
                (string) $data['default_currency'],
                (int) $data['email_reminders_enabled'],
                (int) $data['in_app_prompts_enabled'],
                (int) $data['auto_lock_enabled'],
                (int) $data['grace_days'],
                (string) $data['reminder_days_before_json'],
                (string) $data['workspace_status'],
                $data['trial_starts_at'],
                $data['trial_ends_at'],
                $data['trial_granted_by'],
                $data['trial_notes'],
            ]
        );
    }

    return ['configured' => true];
}

function fixtureCreateWorkspaceBillingCharge(string $namespace, float $amount, string $dueDate, string $chargeType = 'one_time', string $status = 'open'): array
{
    if (!fixtureTableExists('workspace_billing_charges')) {
        throw new RuntimeException('Workspace billing charges table is not available.');
    }

    Database::execute(
        "INSERT INTO workspace_billing_charges
            (charge_type, status, title, description, amount, currency, quantity, due_date, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, 'KES', 1, ?, NOW(), NOW())",
        [
            $chargeType,
            $status,
            'PW Billing ' . $namespace,
            'Playwright billing fixture ' . $namespace,
            $amount,
            $dueDate,
        ]
    );

    return [
        'charge_id' => (int) Database::lastInsertId(),
        'namespace' => $namespace,
    ];
}

$input = json_decode((string) file_get_contents('php://stdin'), true);
if (!is_array($input)) {
    fixtureJsonResponse(['success' => false, 'error' => 'Expected JSON request body.'], 422);
}

$action = (string) ($input['action'] ?? '');

try {
    $result = match ($action) {
        'ensure_user_role' => fixtureEnsureUserRole(
            (string) ($input['email'] ?? ''),
            (string) ($input['role_slug'] ?? 'admin')
        ),
        'ensure_user' => fixtureEnsureUser(
            (string) ($input['email'] ?? ''),
            (string) ($input['password'] ?? 'password'),
            (string) ($input['profile_role'] ?? 'viewer'),
            (string) ($input['first_name'] ?? 'Playwright'),
            (string) ($input['last_name'] ?? 'User')
        ),
        'ensure_owner_help_expert' => fixtureEnsureOwnerHelpExpert(
            (string) ($input['email'] ?? 'owner-help-expert@example.test')
        ),
        'ensure_workspace_owner' => fixtureEnsureWorkspaceOwner(
            (string) ($input['email'] ?? ''),
            (string) ($input['password'] ?? 'password'),
            (string) ($input['workspace_name'] ?? 'Playwright Help Workspace')
        ),
        'complete_workspace_onboarding' => fixtureCompleteWorkspaceOnboarding(
            (int) ($input['workspace_id'] ?? 0)
        ),
        'install_workspace_skill' => fixtureInstallWorkspaceSkill(
            (int) ($input['workspace_id'] ?? 0),
            (int) ($input['user_id'] ?? 0),
            (string) ($input['skill_key'] ?? ''),
            is_array($input['config'] ?? null) ? $input['config'] : []
        ),
        'uninstall_workspace_skill' => fixtureUninstallWorkspaceSkill(
            (int) ($input['workspace_id'] ?? 0),
            (string) ($input['skill_key'] ?? '')
        ),
        'get_workspace_skill_install_state' => fixtureWorkspaceSkillInstallState(
            (int) ($input['workspace_id'] ?? 0),
            (string) ($input['skill_key'] ?? '')
        ),
        'restore_workspace_skill_install_state' => fixtureRestoreWorkspaceSkillInstallState(
            (int) ($input['workspace_id'] ?? 0),
            (string) ($input['skill_key'] ?? ''),
            is_array($input['state'] ?? null) ? $input['state'] : []
        ),
        'ensure_role_with_permissions' => fixtureEnsureRoleWithPermissions(
            (string) ($input['role_slug'] ?? ''),
            (string) ($input['role_name'] ?? ''),
            is_array($input['permission_keys'] ?? null) ? $input['permission_keys'] : [],
            (string) ($input['description'] ?? '')
        ),
        'ensure_user_with_role' => fixtureEnsureUserWithRole(
            (string) ($input['email'] ?? ''),
            (string) ($input['role_slug'] ?? ''),
            (string) ($input['role_name'] ?? ''),
            is_array($input['permission_keys'] ?? null) ? $input['permission_keys'] : [],
            (string) ($input['password'] ?? 'password'),
            (string) ($input['profile_role'] ?? 'viewer'),
            (string) ($input['namespace'] ?? '')
        ),
        'seed_contact_record' => fixtureSeedContactRecord(
            (string) ($input['namespace'] ?? ''),
            isset($input['assigned_to_email']) ? (string) $input['assigned_to_email'] : null,
            isset($input['created_by_email']) ? (string) $input['created_by_email'] : null
        ),
        'seed_email_conversation' => fixtureSeedEmailConversation(
            (string) ($input['namespace'] ?? ''),
            (string) ($input['email'] ?? '')
        ),
        'seed_scoped_email_conversation' => fixtureSeedScopedEmailConversation(
            (string) ($input['namespace'] ?? ''),
            (string) ($input['created_by_email'] ?? ''),
            isset($input['thread_owner_email']) ? (string) $input['thread_owner_email'] : null
        ),
        'seed_notifications' => fixtureSeedNotifications(
            (string) ($input['namespace'] ?? ''),
            (string) ($input['email'] ?? '')
        ),
        'seed_deal' => fixtureSeedDeal(
            (string) ($input['namespace'] ?? ''),
            (string) ($input['email'] ?? '')
        ),
        'get_deal' => fixtureGetDeal(
            (int) ($input['deal_id'] ?? 0)
        ),
        'seed_target' => fixtureSeedTarget(
            (string) ($input['namespace'] ?? ''),
            (string) ($input['email'] ?? ''),
            (string) ($input['scope'] ?? 'personal')
        ),
        'seed_task_with_subtasks' => fixtureSeedTaskWithSubtasks(
            (string) ($input['namespace'] ?? ''),
            (string) ($input['email'] ?? '')
        ),
        'seed_form' => fixtureSeedForm(
            (string) ($input['namespace'] ?? ''),
            (string) ($input['email'] ?? '')
        ),
        'get_workspace_context' => fixtureGetWorkspaceContext(
            (string) ($input['workspace_slug'] ?? ''),
            isset($input['owner_email']) ? (string) $input['owner_email'] : null
        ),
        'create_workspace_invite' => fixtureCreateWorkspaceInvite(
            (int) ($input['workspace_id'] ?? 0),
            (int) ($input['actor_user_id'] ?? 0),
            (string) ($input['email'] ?? ''),
            (string) ($input['role_slug'] ?? 'viewer')
        ),
        'create_workspace_checkout' => fixtureCreateWorkspaceCheckout(
            (int) ($input['workspace_id'] ?? 0),
            (int) ($input['user_id'] ?? 0),
            isset($input['billing_plan_price_id'])
                ? (int) $input['billing_plan_price_id']
                : (isset($input['billing_plan_price_code']) ? fixtureBillingPlanPriceIdByCode((string) $input['billing_plan_price_code']) : null),
            isset($input['token_pack_price_id'])
                ? (int) $input['token_pack_price_id']
                : (isset($input['token_pack_price_code']) ? fixtureTokenPackPriceIdByCode((string) $input['token_pack_price_code']) : null),
            isset($input['payment_mode']) ? (string) $input['payment_mode'] : null,
            isset($input['customer_phone']) ? (string) $input['customer_phone'] : null
        ),
        'verify_workspace_checkout' => fixtureVerifyWorkspaceCheckout(
            (string) ($input['reference'] ?? '')
        ),
        'process_workspace_checkout_webhook' => fixtureProcessWorkspaceCheckoutWebhook(
            (string) ($input['reference'] ?? ''),
            (string) ($input['event'] ?? 'charge.success'),
            (string) ($input['status'] ?? 'success')
        ),
        'configure_workspace_billing' => fixtureConfigureWorkspaceBilling(
            is_array($input['settings'] ?? null) ? $input['settings'] : []
        ),
        'create_workspace_billing_charge' => fixtureCreateWorkspaceBillingCharge(
            (string) ($input['namespace'] ?? ''),
            (float) ($input['amount'] ?? 0),
            (string) ($input['due_date'] ?? date('Y-m-d')),
            (string) ($input['charge_type'] ?? 'one_time'),
            (string) ($input['status'] ?? 'open')
        ),
        'cleanup_namespace' => fixtureCleanupNamespace((string) ($input['namespace'] ?? '')),
        'cleanup_workspace_namespace' => fixtureCleanupWorkspaceNamespace((string) ($input['namespace'] ?? '')),
        default => throw new RuntimeException('Unknown fixture action: ' . $action),
    };

    fixtureJsonResponse(['success' => true, 'result' => $result]);
} catch (Throwable $e) {
    fixtureJsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
}
