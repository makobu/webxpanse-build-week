<?php

namespace CRM\Services;

use CRM\Database;
use PDO;

class SecurityRoleHardeningAuditService
{
    /** @var array<int,string> */
    private const REQUIRED_RBAC_TABLES = ['roles', 'permissions', 'role_permissions', 'user_roles'];

    /** @var array<int,string> */
    private const EXPECTED_ROLE_SLUGS = ['superadmin', 'admin', 'owner', 'viewer'];

    /** @var array<int,string> */
    private const EXPECTED_PERMISSION_KEYS = [
        'admin.users.access',
        'admin.users.manage',
        'admin.roles.manage',
        'admin.audit_logs.view',
        'settings.monitoring',
        'platform.settings.manage',
        'platform.system.reset',
        'platform.users.view',
        'ai.operations.manage',
        'ai.prompt_control.manage',
        'billing.trials.manage',
        'workspace.skills.manage',
    ];

    /** @var array<int,string> */
    private const PLATFORM_ONLY_PERMISSION_KEYS = [
        'admin.audit_logs.view',
        'billing.trials.manage',
        'platform.settings.manage',
        'platform.system.reset',
        'platform.users.view',
    ];

    /** @var array<int,string> */
    private const PLATFORM_ONLY_PERMISSION_PREFIXES = ['operator.', 'platform.'];

    /** @var array<int,string> */
    private const SENSITIVE_PERMISSION_KEYS = [
        'admin.users.manage',
        'admin.roles.manage',
        'admin.audit_logs.view',
        'billing.trials.manage',
        'commercial_automation.approvals',
        'ai.operations.manage',
        'ai.prompt_control.manage',
        'platform.settings.manage',
        'platform.system.reset',
        'platform.users.view',
        'workspace.skills.manage',
    ];

    /** @var array<int,string> */
    private const SENSITIVE_PERMISSION_PREFIXES = [
        'admin.',
        'operator.',
        'platform.',
        'settings.api_keys',
        'settings.billing',
        'settings.email',
        'settings.whatsapp',
        'workspace.delete',
        'exports.',
    ];

    /** @var array<int,string> */
    private const ALLOWED_PUBLIC_ENDPOINTS = [
        'public/forgot_password.php',
        'public/index.php',
        'public/login.php',
        'public/logout.php',
        'public/register.php',
        'public/reset_password.php',
        'public/signup.php',
        'public/system_health.php',
        'public/privacy-policy.php',
        'public/terms-of-service.php',
        'public/marketing_landing_public.php',
        'public/marketing_track.php',
        'public/marketing_unsubscribe.php',
        'public/meeting_schedule.php',
        'public/presentation_magic_login.php',
        'public/signature_asset.php',
        'api/webhooks/mpesa.php',
        'api/webhooks/paystack.php',
        'api/webhooks/sms.php',
        'api/webhooks/whatsapp.php',
        'api/webhooks/workflow_trigger.php',
    ];

    /** @var array<int,string> */
    private const ALLOWED_PUBLIC_PREFIXES = [
        'api/demo_access/',
    ];

    /** @var array<int,string> */
    private const IGNORED_ENDPOINT_SUFFIXES = [
        '/_bootstrap.php',
        '/_partial.php',
    ];

    public function __construct(
        private ?PDO $pdo = null,
        private ?string $rootPath = null
    ) {
        $this->rootPath = rtrim($rootPath ?: dirname(__DIR__), "\\/");
    }

    /**
     * @return array<string,mixed>
     */
    public function audit(): array
    {
        $findings = [];
        $summary = [
            'rbac_tables_checked' => 0,
            'permissions_checked' => 0,
            'roles_checked' => 0,
            'superadmin_users' => 0,
            'public_php_files_checked' => 0,
            'api_php_files_checked' => 0,
            'public_allowed_files' => 0,
            'auth_guarded_files' => 0,
            'privileged_files_checked' => 0,
            'setup_script_candidates' => 0,
            'findings' => 0,
            'critical' => 0,
            'warning' => 0,
            'by_rule' => [],
        ];

        $this->auditRbacDefaults($findings, $summary);
        $this->auditSuperAdminAccess($findings, $summary);
        $this->auditPermissionDrift($findings, $summary);
        $this->auditPublicEndpointSurface($findings, $summary);

        foreach ($findings as $finding) {
            $severity = (string) ($finding['severity'] ?? 'warning');
            if (!isset($summary[$severity])) {
                $severity = 'warning';
            }
            $summary[$severity]++;
            $summary['findings']++;
            $rule = (string) ($finding['rule'] ?? 'unknown');
            $summary['by_rule'][$rule] = (int) ($summary['by_rule'][$rule] ?? 0) + 1;
        }

        return [
            'status' => $summary['critical'] > 0 ? 'critical' : ($summary['warning'] > 0 ? 'warning' : 'ok'),
            'summary' => $summary,
            'findings' => $findings,
            'checked_at' => date('c'),
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $findings
     * @param array<string,mixed> $summary
     */
    private function auditRbacDefaults(array &$findings, array &$summary): void
    {
        foreach (self::REQUIRED_RBAC_TABLES as $table) {
            $summary['rbac_tables_checked']++;
            if (!$this->tableExists($table)) {
                $this->addFinding($findings, 'critical', 'rbac_table_missing', 'Required RBAC table is missing.', [
                    'table' => $table,
                    'recommendation' => 'Run migrations and stop role or permission edits until RBAC tables are present.',
                ]);
            }
        }

        if (!$this->hasRbacTables()) {
            return;
        }

        $summary['permissions_checked'] = (int) ($this->queryOne('SELECT COUNT(*) AS c FROM permissions')['c'] ?? 0);
        $summary['roles_checked'] = (int) ($this->queryOne('SELECT COUNT(*) AS c FROM roles')['c'] ?? 0);

        foreach (self::EXPECTED_ROLE_SLUGS as $slug) {
            $role = $this->queryOne('SELECT id, slug, is_active, is_system FROM roles WHERE slug = ? LIMIT 1', [$slug]);
            if ($role === null) {
                $this->addFinding($findings, 'critical', 'required_role_missing', 'Required access profile is missing.', [
                    'role_slug' => $slug,
                    'recommendation' => 'Restore the canonical role seed before changing user access.',
                ]);
                continue;
            }
            if ((int) ($role['is_active'] ?? 0) !== 1) {
                $this->addFinding($findings, 'critical', 'required_role_inactive', 'Required access profile is inactive.', [
                    'role_slug' => $slug,
                    'recommendation' => 'Reactivate or repair the canonical role seed.',
                ]);
            }
        }

        foreach (self::EXPECTED_PERMISSION_KEYS as $permissionKey) {
            $permission = $this->queryOne('SELECT id, permission_key FROM permissions WHERE permission_key = ? LIMIT 1', [$permissionKey]);
            if ($permission === null) {
                $this->addFinding($findings, 'warning', 'expected_permission_missing', 'Expected production permission key is missing.', [
                    'permission_key' => $permissionKey,
                    'recommendation' => 'Confirm whether the permission was renamed or add a migration to seed it.',
                ]);
            }
        }

        $legacyRoles = $this->query(
            "SELECT id, slug, is_active
             FROM roles
             WHERE slug IN ('admin_ops', 'super_admin', 'super-admin')
             ORDER BY slug ASC"
        );
        foreach ($legacyRoles as $role) {
            $this->addFinding($findings, 'warning', 'legacy_superadmin_role_slug_present', 'Legacy or misspelled Super Admin role slug is present.', [
                'role_id' => (int) ($role['id'] ?? 0),
                'role_slug' => (string) ($role['slug'] ?? ''),
                'is_active' => (int) ($role['is_active'] ?? 0),
                'recommendation' => 'Normalize assignments to the canonical superadmin role.',
            ]);
        }

        $sensitiveRows = $this->query(
            "SELECT permission_key
             FROM permissions
             WHERE is_sensitive = 0
               AND (" . $this->sensitivePermissionSqlCondition('permission_key') . ")
             ORDER BY permission_key ASC
             LIMIT 30"
        );
        foreach ($sensitiveRows as $permission) {
            $this->addFinding($findings, 'warning', 'sensitive_permission_not_flagged', 'Sensitive permission is not flagged as sensitive.', [
                'permission_key' => (string) ($permission['permission_key'] ?? ''),
                'recommendation' => 'Mark high-risk admin, platform, integration, and export permissions as sensitive.',
            ]);
        }
    }

    /**
     * @param array<int,array<string,mixed>> $findings
     * @param array<string,mixed> $summary
     */
    private function auditSuperAdminAccess(array &$findings, array &$summary): void
    {
        if (!$this->hasRbacTables() || !$this->tableExists('users')) {
            return;
        }

        $role = $this->queryOne("SELECT id, is_active, is_system FROM roles WHERE slug = 'superadmin' LIMIT 1");
        if ($role === null || (int) ($role['is_active'] ?? 0) !== 1) {
            $this->addFinding($findings, 'critical', 'superadmin_role_unavailable', 'Canonical Super Admin role is missing or inactive.', [
                'recommendation' => 'Repair the canonical superadmin role before live upload.',
            ]);
            return;
        }

        $roleId = (int) ($role['id'] ?? 0);
        $missingGrants = $this->query(
            "SELECT p.permission_key
             FROM permissions p
             LEFT JOIN role_permissions rp
               ON rp.permission_id = p.id
              AND rp.role_id = ?
              AND rp.can_access = 1
             WHERE rp.permission_id IS NULL
             ORDER BY p.permission_key ASC
             LIMIT 30",
            [$roleId]
        );
        foreach ($missingGrants as $grant) {
            $this->addFinding($findings, 'critical', 'superadmin_missing_permission_grant', 'Super Admin role is missing a permission grant.', [
                'permission_key' => (string) ($grant['permission_key'] ?? ''),
                'role_slug' => 'superadmin',
                'recommendation' => 'Backfill the canonical Super Admin role against every seeded permission.',
            ]);
        }

        $activeCondition = $this->activeUserCondition('u');
        $superAdmins = $this->query(
            "SELECT u.id, u.email" . ($this->columnExists('users', 'two_factor_enabled') ? ', u.two_factor_enabled' : '') . "
             FROM users u
             JOIN user_roles ur ON ur.user_id = u.id
             JOIN roles r ON r.id = ur.role_id
             WHERE r.slug = 'superadmin'
               AND r.is_active = 1
               {$activeCondition}
             ORDER BY u.id ASC"
        );
        $summary['superadmin_users'] = count($superAdmins);
        if ($superAdmins === []) {
            $userCount = (int) ($this->queryOne('SELECT COUNT(*) AS c FROM users')['c'] ?? 0);
            $this->addFinding($findings, $userCount > 0 ? 'critical' : 'warning', 'superadmin_user_missing', 'No active user has the canonical Super Admin role.', [
                'users_checked' => $userCount,
                'recommendation' => 'Assign at least one active operator to the superadmin role before production upload.',
            ]);
        }

        if ($this->columnExists('users', 'two_factor_enabled')) {
            foreach ($superAdmins as $user) {
                if ((int) ($user['two_factor_enabled'] ?? 0) === 1) {
                    continue;
                }
                $this->addFinding($findings, 'warning', 'superadmin_2fa_not_enabled', 'Super Admin account does not have 2FA enabled.', [
                    'user_id' => (int) ($user['id'] ?? 0),
                    'email' => (string) ($user['email'] ?? ''),
                    'recommendation' => 'Require 2FA for Super Admin accounts before live server upload.',
                ]);
            }
        }

        if ($this->tableExists('workspace_memberships')) {
            $membership = $this->queryOne(
                "SELECT COUNT(*) AS c
                 FROM workspace_memberships wm
                 JOIN users u ON u.id = wm.user_id
                 WHERE wm.workspace_id = 1
                   AND wm.membership_status = 'active'
                   AND wm.role_slug = 'superadmin'
                   {$this->activeUserCondition('u')}"
            );
            if ((int) ($membership['c'] ?? 0) === 0) {
                $this->addFinding($findings, 'warning', 'default_workspace_superadmin_membership_missing', 'Default workspace has no active Super Admin membership.', [
                    'workspace_id' => 1,
                    'recommendation' => 'Repair the default workspace Super Admin owner membership before operational handoff.',
                ]);
            }
        }
    }

    /**
     * @param array<int,array<string,mixed>> $findings
     * @param array<string,mixed> $summary
     */
    private function auditPermissionDrift(array &$findings, array &$summary): void
    {
        if (!$this->hasRbacTables()) {
            return;
        }

        $platformOnlyRows = $this->query(
            "SELECT r.slug AS role_slug, p.permission_key
             FROM role_permissions rp
             JOIN roles r ON r.id = rp.role_id
             JOIN permissions p ON p.id = rp.permission_id
             WHERE rp.can_access = 1
               AND r.slug <> 'superadmin'
               AND (" . $this->platformOnlyPermissionSqlCondition('p.permission_key') . ")
             ORDER BY r.slug ASC, p.permission_key ASC
             LIMIT 60"
        );
        foreach ($platformOnlyRows as $row) {
            $permissionKey = (string) ($row['permission_key'] ?? '');
            $this->addFinding($findings, $this->isHardPlatformOnlyPermission($permissionKey) ? 'critical' : 'warning', 'platform_only_permission_granted_to_non_superadmin', 'Platform-only permission is granted to a non-Super Admin role.', [
                'role_slug' => (string) ($row['role_slug'] ?? ''),
                'permission_key' => $permissionKey,
                'recommendation' => 'Remove platform-only permissions from tenant/workspace roles or document an explicit Super Admin-only exception.',
            ]);
        }

        $temporaryRoleRows = $this->query(
            "SELECT r.slug AS role_slug, p.permission_key
             FROM role_permissions rp
             JOIN roles r ON r.id = rp.role_id
             JOIN permissions p ON p.id = rp.permission_id
             WHERE rp.can_access = 1
               AND r.slug IN ('demo_owner', 'presentation_owner', 'demo_presenter')
               AND (
                    p.permission_key LIKE 'admin.%'
                 OR p.permission_key LIKE 'billing.%'
                 OR p.permission_key LIKE 'exports.%'
                 OR p.permission_key LIKE 'integrations.%'
                 OR p.permission_key LIKE 'operator.%'
                 OR p.permission_key LIKE 'platform.%'
                 OR p.permission_key LIKE 'settings.%'
                 OR p.permission_key LIKE 'workspace.delete%'
                 OR p.permission_key LIKE 'workspace.invite%'
                 OR p.permission_key LIKE 'workspace.members%'
                 OR p.permission_key LIKE 'workspace.user%'
                 OR p.permission_key = 'crm.custom_fields.manage'
               )
             ORDER BY r.slug ASC, p.permission_key ASC
             LIMIT 60"
        );
        foreach ($temporaryRoleRows as $row) {
            $this->addFinding($findings, 'warning', 'temporary_role_high_risk_permission', 'Temporary or presentation role has a high-risk permission grant.', [
                'role_slug' => (string) ($row['role_slug'] ?? ''),
                'permission_key' => (string) ($row['permission_key'] ?? ''),
                'recommendation' => 'Keep demo and presentation roles scoped away from billing, export, integration, workspace-delete, and platform-administration powers.',
            ]);
        }
    }

    /**
     * @param array<int,array<string,mixed>> $findings
     * @param array<string,mixed> $summary
     */
    private function auditPublicEndpointSurface(array &$findings, array &$summary): void
    {
        foreach ($this->phpEntrypointFiles() as $file) {
            $relative = $file['relative'];
            $source = (string) file_get_contents($file['path']);
            if (str_starts_with($relative, 'public/')) {
                $summary['public_php_files_checked']++;
            } elseif (str_starts_with($relative, 'api/')) {
                $summary['api_php_files_checked']++;
            }

            if ($this->isIgnoredEndpoint($relative)) {
                continue;
            }

            $isAllowedPublic = $this->isAllowedPublicEndpoint($relative);
            if ($isAllowedPublic) {
                $summary['public_allowed_files']++;
            }

            $hasAuthGuard = $this->hasAuthGuard($source);
            if ($hasAuthGuard) {
                $summary['auth_guarded_files']++;
            }

            $isSetupCandidate = $this->isSetupScriptCandidate($relative);
            if ($isSetupCandidate) {
                $summary['setup_script_candidates']++;
                $this->addFinding($findings, $relative === 'public/install_once.php' ? 'critical' : 'warning', 'public_setup_or_test_script_present', 'Public or API setup/test/debug script remains reachable by path.', [
                    'file' => $relative,
                    'auth_guarded' => $hasAuthGuard,
                    'recommendation' => 'Remove, move behind CLI-only execution, or restrict to Super Admin with CSRF and environment gates before live upload.',
                ]);
            }

            if (!$hasAuthGuard && !$isAllowedPublic) {
                $this->addFinding($findings, 'warning', 'public_endpoint_missing_auth_guard', 'Public/API PHP endpoint does not show an Auth or Authorization guard.', [
                    'file' => $relative,
                    'recommendation' => 'Add an explicit auth/authorization guard or move this file to a non-public include path.',
                ]);
            }

            if ($this->isMutatingEndpoint($source) && !$this->isWebhookEndpoint($relative) && !$isAllowedPublic && !$this->hasCsrfGuard($source)) {
                $this->addFinding($findings, 'warning', 'mutating_endpoint_missing_csrf_guard', 'Mutating endpoint does not show a CSRF guard.', [
                    'file' => $relative,
                    'recommendation' => 'Require Security::validateCSRF for session-authenticated mutating actions.',
                ]);
            }

            if ($this->isPrivilegedEndpoint($relative, $source)) {
                $summary['privileged_files_checked']++;
                if (!$this->hasExplicitAuthorizationGuard($source)) {
                    $this->addFinding($findings, 'warning', 'privileged_endpoint_without_explicit_authorization', 'Privileged export/admin/settings endpoint has no explicit Authorization check.', [
                        'file' => $relative,
                        'recommendation' => 'Gate export, admin, settings, delete, and user/role actions with explicit Authorization checks in addition to Auth::check.',
                    ]);
                }
            }
        }
    }

    /**
     * @param array<int,array<string,mixed>> $findings
     * @param array<string,mixed> $context
     */
    private function addFinding(array &$findings, string $severity, string $rule, string $message, array $context): void
    {
        $findings[] = [
            'severity' => $severity,
            'rule' => $rule,
            'message' => $message,
        ] + $context;
    }

    /**
     * @return array<int,array{path:string,relative:string}>
     */
    private function phpEntrypointFiles(): array
    {
        $files = [];
        foreach (['public', 'api'] as $dir) {
            $base = $this->rootPath . DIRECTORY_SEPARATOR . $dir;
            if (!is_dir($base)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $item) {
                if (!$item->isFile() || strtolower($item->getExtension()) !== 'php') {
                    continue;
                }
                $path = $item->getPathname();
                $files[] = [
                    'path' => $path,
                    'relative' => $this->relativePath($path),
                ];
            }
        }

        usort($files, static fn(array $a, array $b): int => strcmp($a['relative'], $b['relative']));
        return $files;
    }

    private function relativePath(string $path): string
    {
        $relative = str_replace('\\', '/', $path);
        $root = str_replace('\\', '/', $this->rootPath);
        if (str_starts_with($relative, $root . '/')) {
            $relative = substr($relative, strlen($root) + 1);
        }
        return $relative;
    }

    private function isIgnoredEndpoint(string $relative): bool
    {
        foreach (self::IGNORED_ENDPOINT_SUFFIXES as $suffix) {
            if (str_ends_with($relative, $suffix)) {
                return true;
            }
        }
        return str_starts_with(basename($relative), '_');
    }

    private function isAllowedPublicEndpoint(string $relative): bool
    {
        if (in_array($relative, self::ALLOWED_PUBLIC_ENDPOINTS, true)) {
            return true;
        }
        foreach (self::ALLOWED_PUBLIC_PREFIXES as $prefix) {
            if (str_starts_with($relative, $prefix)) {
                return true;
            }
        }
        return str_contains($relative, '/callback.php') && str_contains($relative, '/calendar/');
    }

    private function isWebhookEndpoint(string $relative): bool
    {
        return str_starts_with($relative, 'api/webhooks/');
    }

    private function hasAuthGuard(string $source): bool
    {
        return str_contains($source, 'Auth::check(')
            || str_contains($source, 'Authorization::')
            || str_contains($source, 'requirePermission(')
            || str_contains($source, 'canOperateDemo(')
            || str_contains($source, 'ApiAuth::')
            || str_contains($source, "require_once __DIR__ . '/_bootstrap.php'")
            || str_contains($source, "require __DIR__ . '/_bootstrap.php'");
    }

    private function hasExplicitAuthorizationGuard(string $source): bool
    {
        return str_contains($source, 'Authorization::')
            || str_contains($source, 'requirePermission(')
            || str_contains($source, 'canAccessUsersPage(')
            || str_contains($source, 'PresentationWorkspaceGuardService')
            || str_contains($source, 'DemoSessionScopeService')
            || str_contains($source, 'ApiAuth::');
    }

    private function hasCsrfGuard(string $source): bool
    {
        return str_contains($source, 'Security::validateCSRF')
            || str_contains($source, 'validateCSRF(')
            || str_contains($source, 'ApiAuth::');
    }

    private function isMutatingEndpoint(string $source): bool
    {
        return str_contains($source, 'php://input')
            || str_contains($source, '$_POST')
            || preg_match("/REQUEST_METHOD['\"]\\]\\s*===?\\s*['\"]POST['\"]/", $source) === 1
            || str_contains($source, 'Database::execute(')
            || str_contains($source, '->delete(')
            || str_contains($source, 'unlink(')
            || str_contains($source, 'move_uploaded_file(');
    }

    private function isSetupScriptCandidate(string $relative): bool
    {
        if ($relative === 'public/reset_password.php') {
            return false;
        }
        return preg_match('/(?:^|[\/_-])(install|migrate|migration|debug|test|seed|reseed|shell|cmd|command)(?:[\/_.-]|$)/i', $relative) === 1;
    }

    private function isPrivilegedEndpoint(string $relative, string $source): bool
    {
        if ($this->isAllowedPublicEndpoint($relative)) {
            return false;
        }

        return preg_match('/(?:^|[\/_-])(admin|role|roles|user|users|settings|delete|export|download|migrate|seed)(?:[\/_.-]|$)/i', $relative) === 1;
    }

    private function hasRbacTables(): bool
    {
        foreach (self::REQUIRED_RBAC_TABLES as $table) {
            if (!$this->tableExists($table)) {
                return false;
            }
        }
        return true;
    }

    private function activeUserCondition(string $alias): string
    {
        $conditions = [];
        if ($this->columnExists('users', 'is_active')) {
            $conditions[] = "{$alias}.is_active = 1";
        }
        if ($this->columnExists('users', 'status')) {
            $conditions[] = "{$alias}.status NOT IN ('inactive', 'disabled', 'suspended', 'deleted')";
        }
        if ($this->columnExists('users', 'deleted_at')) {
            $conditions[] = "{$alias}.deleted_at IS NULL";
        }

        return $conditions === [] ? '' : ' AND ' . implode(' AND ', $conditions);
    }

    private function sensitivePermissionSqlCondition(string $column): string
    {
        $conditions = [];
        foreach (self::SENSITIVE_PERMISSION_KEYS as $key) {
            $conditions[] = "{$column} = " . $this->quote($key);
        }
        foreach (self::SENSITIVE_PERMISSION_PREFIXES as $prefix) {
            $conditions[] = "{$column} LIKE " . $this->quote($prefix . '%');
        }
        return implode(' OR ', $conditions);
    }

    private function platformOnlyPermissionSqlCondition(string $column): string
    {
        $conditions = [];
        foreach (self::PLATFORM_ONLY_PERMISSION_KEYS as $key) {
            $conditions[] = "{$column} = " . $this->quote($key);
        }
        foreach (self::PLATFORM_ONLY_PERMISSION_PREFIXES as $prefix) {
            $conditions[] = "{$column} LIKE " . $this->quote($prefix . '%');
        }
        return implode(' OR ', $conditions);
    }

    private function isHardPlatformOnlyPermission(string $permissionKey): bool
    {
        return str_starts_with($permissionKey, 'platform.')
            || str_starts_with($permissionKey, 'operator.')
            || in_array($permissionKey, ['platform.settings.manage', 'platform.system.reset', 'platform.users.view'], true);
    }

    /**
     * @param array<int,mixed> $params
     * @return array<int,array<string,mixed>>
     */
    private function query(string $sql, array $params = []): array
    {
        if ($this->pdo instanceof PDO) {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        return Database::query($sql, $params);
    }

    /**
     * @param array<int,mixed> $params
     * @return array<string,mixed>|null
     */
    private function queryOne(string $sql, array $params = []): ?array
    {
        if ($this->pdo instanceof PDO) {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        }
        return Database::queryOne($sql, $params);
    }

    private function tableExists(string $table): bool
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            return false;
        }
        if ($this->pdo instanceof PDO) {
            $stmt = $this->pdo->query('SHOW TABLES LIKE ' . $this->quote($table));
            return (bool) ($stmt && $stmt->fetch(PDO::FETCH_NUM));
        }
        return Database::tableExists($table);
    }

    private function columnExists(string $table, string $column): bool
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
            return false;
        }
        if ($this->pdo instanceof PDO) {
            $stmt = $this->pdo->query('SHOW COLUMNS FROM ' . $this->quoteIdentifier($table) . ' WHERE Field = ' . $this->quote($column));
            return (bool) ($stmt && $stmt->fetch(PDO::FETCH_ASSOC));
        }
        return Database::columnExists($table, $column);
    }

    private function quote(string $value): string
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo->quote($value);
        }
        return Database::getInstance()->quote($value);
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
