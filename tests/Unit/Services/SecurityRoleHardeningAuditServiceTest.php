<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\SecurityRoleHardeningAuditService;
use CRM\Tests\DatabaseTestCase;

class SecurityRoleHardeningAuditServiceTest extends DatabaseTestCase
{
    public function testSeededHardeningBaselineHasNoCriticalFindings(): void
    {
        $this->ensureSuperAdminUser();
        $root = $this->temporaryAuditRoot([
            'public/login.php' => '<?php echo "login";',
            'api/guarded.php' => '<?php if (!\CRM\Auth::check()) { exit; }',
        ]);

        try {
            $report = (new SecurityRoleHardeningAuditService(null, $root))->audit();

            $this->assertSame(0, (int) ($report['summary']['critical'] ?? -1), json_encode($report['findings'] ?? []) ?: '');
            $this->assertNotContains('superadmin_missing_permission_grant', $this->findingRules($report));
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testMissingSuperAdminPermissionGrantIsCritical(): void
    {
        $this->ensureSuperAdminUser();
        $permission = Database::queryOne("SELECT id FROM permissions WHERE permission_key = 'admin.users.manage' LIMIT 1");
        $role = Database::queryOne("SELECT id FROM roles WHERE slug = 'superadmin' LIMIT 1");
        Database::execute(
            'DELETE FROM role_permissions WHERE role_id = ? AND permission_id = ?',
            [(int) ($role['id'] ?? 0), (int) ($permission['id'] ?? 0)]
        );
        $root = $this->temporaryAuditRoot([
            'public/login.php' => '<?php echo "login";',
        ]);

        try {
            $report = (new SecurityRoleHardeningAuditService(null, $root))->audit();

            $this->assertHasFindingRule($report, 'superadmin_missing_permission_grant');
            $this->assertGreaterThanOrEqual(1, (int) ($report['summary']['critical'] ?? 0));
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function testPublicEndpointAndSetupScriptFindingsAreReported(): void
    {
        $this->ensureSuperAdminUser();
        $root = $this->temporaryAuditRoot([
            'public/login.php' => '<?php echo "login";',
            'public/open.php' => '<?php echo "open";',
            'public/debug_2fa.php' => '<?php echo "debug";',
            'api/mutate.php' => '<?php if (!\CRM\Auth::check()) { exit; } \CRM\Database::execute("UPDATE users SET last_login = NOW() WHERE id = 0");',
        ]);

        try {
            $report = (new SecurityRoleHardeningAuditService(null, $root))->audit();

            $this->assertHasFindingRule($report, 'public_endpoint_missing_auth_guard');
            $this->assertHasFindingRule($report, 'public_setup_or_test_script_present');
            $this->assertHasFindingRule($report, 'mutating_endpoint_missing_csrf_guard');
        } finally {
            $this->removeDirectory($root);
        }
    }

    private function ensureSuperAdminUser(): int
    {
        Database::execute(
            "INSERT INTO users (uuid, email, password_hash, role, two_factor_enabled)
             VALUES (UUID(), ?, ?, 'admin', 1)",
            ['security-hardening-superadmin-' . bin2hex(random_bytes(5)) . '@example.test', password_hash('secret', PASSWORD_DEFAULT)]
        );
        $userId = (int) Database::lastInsertId();
        $role = Database::queryOne("SELECT id FROM roles WHERE slug = 'superadmin' LIMIT 1");
        Database::execute(
            'INSERT INTO user_roles (user_id, role_id, assigned_by) VALUES (?, ?, ?)',
            [$userId, (int) ($role['id'] ?? 0), $userId]
        );
        return $userId;
    }

    /**
     * @param array<string,string> $files
     */
    private function temporaryAuditRoot(array $files): string
    {
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'crm-security-audit-' . bin2hex(random_bytes(6));
        foreach ($files as $relative => $contents) {
            $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            file_put_contents($path, $contents);
        }
        if (!is_dir($root . DIRECTORY_SEPARATOR . 'api')) {
            mkdir($root . DIRECTORY_SEPARATOR . 'api', 0777, true);
        }
        if (!is_dir($root . DIRECTORY_SEPARATOR . 'public')) {
            mkdir($root . DIRECTORY_SEPARATOR . 'public', 0777, true);
        }
        return $root;
    }

    /**
     * @param array<string,mixed> $report
     * @return array<int,string>
     */
    private function findingRules(array $report): array
    {
        return array_values(array_map(
            static fn(array $finding): string => (string) ($finding['rule'] ?? ''),
            array_filter((array) ($report['findings'] ?? []), 'is_array')
        ));
    }

    /**
     * @param array<string,mixed> $report
     */
    private function assertHasFindingRule(array $report, string $rule): void
    {
        $this->assertContains($rule, $this->findingRules($report), json_encode($report['findings'] ?? []) ?: '');
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($path);
    }
}
