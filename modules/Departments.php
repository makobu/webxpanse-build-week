<?php
/**
 * Department management module.
 */

namespace CRM\Modules;

use CRM\Database;
use CRM\Services\OrganizationFunctionService;
use CRM\Services\WorkspaceScopeService;

class Departments
{
    public const STARTER_DEPARTMENTS = [
        [
            'name' => 'Leadership / Admin',
            'slug' => 'admin',
            'description' => 'Leadership, ownership, administration, and workspace coordination.',
        ],
        [
            'name' => 'Sales',
            'slug' => 'sales',
            'description' => 'Sales and revenue team.',
        ],
        [
            'name' => 'Marketing',
            'slug' => 'marketing',
            'description' => 'Marketing and growth team.',
        ],
        [
            'name' => 'Operations',
            'slug' => 'operations',
            'description' => 'General operations and delivery coordination.',
        ],
        [
            'name' => 'Finance',
            'slug' => 'finance',
            'description' => 'Cash, billing, vendor, and reporting coordination.',
        ],
        [
            'name' => 'Customer Success',
            'slug' => 'customer_success',
            'description' => 'Post-sale customer follow-up, retention, support, and relationship continuity.',
        ],
    ];

    public function ensureStarterDefaults(?int $workspaceId = null): void
    {
        if (!Database::tableExists('departments')) {
            return;
        }

        $workspaceId = $this->resolveWorkspaceId($workspaceId);
        foreach (self::STARTER_DEPARTMENTS as $department) {
            $slug = (string) $department['slug'];
            $existing = Database::queryOne(
                "SELECT id, name, description, is_system FROM departments WHERE workspace_id = ? AND slug = ? LIMIT 1",
                [$workspaceId, $slug]
            );

            if ($existing !== null) {
                if (
                    $slug === 'admin'
                    && $this->isUntouchedAdminStarter($existing)
                    && !$this->departmentNameExists($workspaceId, (string) $department['name'], (int) $existing['id'])
                ) {
                    Database::execute(
                        "UPDATE departments
                         SET name = ?, description = ?, is_system = 0
                         WHERE id = ? AND workspace_id = ?",
                        [
                            (string) $department['name'],
                            (string) $department['description'],
                            (int) $existing['id'],
                            $workspaceId,
                        ]
                    );
                    continue;
                }

                if ((int) ($existing['is_system'] ?? 0) === 1) {
                    Database::execute(
                        "UPDATE departments SET is_system = 0 WHERE id = ? AND workspace_id = ?",
                        [(int) $existing['id'], $workspaceId]
                    );
                }
                continue;
            }

            Database::execute(
                "INSERT INTO departments (workspace_id, name, slug, description, is_active, is_system)
                 VALUES (?, ?, ?, ?, 1, 0)",
                [
                    $workspaceId,
                    (string) $department['name'],
                    $slug,
                    (string) $department['description'],
                ]
            );
        }
    }

    public function getAll(bool $includeInactive = true, ?int $workspaceId = null): array
    {
        $workspaceId = $this->resolveWorkspaceId($workspaceId);
        $sql = "SELECT d.*,
                       COUNT(wm.id) AS users_count
                FROM departments d
                LEFT JOIN workspace_memberships wm
                  ON wm.department_id = d.id
                 AND wm.workspace_id = d.workspace_id
                 AND wm.membership_status = 'active'
                %s
                GROUP BY d.id, d.workspace_id, d.name, d.slug, d.description, d.is_active, d.is_system, d.created_at, d.updated_at
                ORDER BY d.is_system DESC, d.name ASC";

        $where = $includeInactive ? 'WHERE d.workspace_id = ?' : 'WHERE d.workspace_id = ? AND d.is_active = 1';
        return Database::query(sprintf($sql, $where), [$workspaceId]);
    }

    public function getActive(?int $workspaceId = null): array
    {
        return $this->getAll(false, $workspaceId);
    }

    public function getById(int $id, ?int $workspaceId = null): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $workspaceId = $this->resolveWorkspaceId($workspaceId);
        return Database::queryOne(
            "SELECT d.*,
                    COUNT(wm.id) AS users_count
             FROM departments d
             LEFT JOIN workspace_memberships wm
               ON wm.department_id = d.id
              AND wm.workspace_id = d.workspace_id
              AND wm.membership_status = 'active'
             WHERE d.id = ?
               AND d.workspace_id = ?
             GROUP BY d.id, d.workspace_id, d.name, d.slug, d.description, d.is_active, d.is_system, d.created_at, d.updated_at",
            [$id, $workspaceId]
        );
    }

    public function create(array $data, ?int $workspaceId = null): int
    {
        $workspaceId = $this->resolveWorkspaceId($workspaceId);
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('Department name is required.');
        }

        $slug = $this->normalizeSlug((string) ($data['slug'] ?? ''), $name);
        $description = trim((string) ($data['description'] ?? ''));

        Database::execute(
            "INSERT INTO departments (workspace_id, name, slug, description, is_active, is_system)
             VALUES (?, ?, ?, ?, ?, 0)",
            [
                $workspaceId,
                $name,
                $slug,
                $description !== '' ? $description : null,
                !empty($data['is_active']) ? 1 : 0,
            ]
        );

        return (int) Database::lastInsertId();
    }

    public function update(int $id, array $data, ?int $workspaceId = null): bool
    {
        $workspaceId = $this->resolveWorkspaceId($workspaceId);
        $department = $this->getById($id, $workspaceId);
        if (!$department) {
            throw new \RuntimeException('Department not found.');
        }

        $name = trim((string) ($data['name'] ?? $department['name']));
        if ($name === '') {
            throw new \InvalidArgumentException('Department name is required.');
        }

        $slug = $this->normalizeSlug((string) ($data['slug'] ?? $department['slug']), $name);
        $description = trim((string) ($data['description'] ?? ($department['description'] ?? '')));
        $isActive = array_key_exists('is_active', $data) ? !empty($data['is_active']) : (int) $department['is_active'] === 1;

        if (!$isActive && (int) ($department['users_count'] ?? 0) > 0) {
            throw new \RuntimeException('Reassign users before deactivating this department.');
        }

        Database::execute(
            "UPDATE departments
             SET name = ?, slug = ?, description = ?, is_active = ?
             WHERE id = ?
               AND workspace_id = ?",
            [
                $name,
                $slug,
                $description !== '' ? $description : null,
                $isActive ? 1 : 0,
                $id,
                $workspaceId,
            ]
        );

        return true;
    }

    public function delete(int $id, ?int $workspaceId = null): bool
    {
        $workspaceId = $this->resolveWorkspaceId($workspaceId);
        $department = $this->getById($id, $workspaceId);
        if (!$department) {
            throw new \RuntimeException('Department not found.');
        }

        if ((int) ($department['users_count'] ?? 0) > 0) {
            throw new \RuntimeException('Reassign users before deleting this department.');
        }

        if ((int) ($department['is_system'] ?? 0) === 1) {
            throw new \RuntimeException('System departments cannot be deleted.');
        }

        Database::execute("DELETE FROM departments WHERE id = ? AND workspace_id = ?", [$id, $workspaceId]);
        return true;
    }

    public function syncUserDepartment(int $userId, int $departmentId, ?int $workspaceId = null): void
    {
        $workspaceId = $this->resolveWorkspaceId($workspaceId);
        $department = $this->getById($departmentId, $workspaceId);
        if (!$department || (int) ($department['is_active'] ?? 0) !== 1) {
            throw new \RuntimeException('Please select a valid department.');
        }

        Database::execute(
            "UPDATE workspace_memberships
             SET department_id = ?, updated_at = NOW()
             WHERE workspace_id = ?
               AND user_id = ?
               AND membership_status = 'active'",
            [
                $departmentId,
                $workspaceId,
                $userId,
            ]
        );

        Database::execute(
            "UPDATE users SET department_id = ?, role = ? WHERE id = ?",
            [
                $departmentId,
                $this->legacyRoleForDepartment($department),
                $userId,
            ]
        );
    }

    public function getDepartmentOptions(?int $workspaceId = null): array
    {
        $options = [];
        foreach ($this->getActive($workspaceId) as $department) {
            $options[(int) $department['id']] = (string) $department['name'];
        }
        return $options;
    }

    public function workOwnershipSummaryByDepartment(?int $workspaceId = null): array
    {
        $workspaceId = $this->resolveWorkspaceId($workspaceId);
        if (!Database::tableExists('workspace_memberships') || !Database::tableExists('user_function_assignments')) {
            return [];
        }

        $rows = Database::query(
            "SELECT d.id AS department_id,
                    COUNT(DISTINCT wm.user_id) AS active_members,
                    COUNT(DISTINCT CASE WHEN ufa.id IS NOT NULL THEN wm.user_id END) AS assigned_members,
                    COUNT(DISTINCT CASE WHEN ufa.id IS NULL THEN wm.user_id END) AS missing_members,
                    GROUP_CONCAT(DISTINCT CASE WHEN ufa.is_primary = 1 THEN f.name END ORDER BY f.name ASC SEPARATOR ', ') AS primary_areas
             FROM departments d
             LEFT JOIN workspace_memberships wm
               ON wm.department_id = d.id
              AND wm.workspace_id = d.workspace_id
              AND wm.membership_status = 'active'
             LEFT JOIN user_function_assignments ufa
               ON ufa.workspace_id = wm.workspace_id
              AND ufa.user_id = wm.user_id
             LEFT JOIN organization_functions f
               ON f.id = ufa.function_id
              AND f.workspace_id = ufa.workspace_id
             WHERE d.workspace_id = ?
             GROUP BY d.id
             ORDER BY d.name ASC",
            [$workspaceId]
        );

        $summary = [];
        foreach ($rows as $row) {
            $summary[(int) ($row['department_id'] ?? 0)] = [
                'active_members' => (int) ($row['active_members'] ?? 0),
                'assigned_members' => (int) ($row['assigned_members'] ?? 0),
                'missing_members' => (int) ($row['missing_members'] ?? 0),
                'primary_areas' => array_values(array_filter(array_map('trim', explode(',', (string) ($row['primary_areas'] ?? ''))))),
            ];
        }

        return $summary;
    }

    public function membersWithWorkOwnership(int $departmentId, ?int $workspaceId = null): array
    {
        if ($departmentId <= 0) {
            return [];
        }

        $workspaceId = $this->resolveWorkspaceId($workspaceId);
        $members = Database::query(
            "SELECT wm.id AS membership_id, wm.user_id, wm.role_slug, wm.membership_status,
                    u.email, u.first_name, u.last_name
             FROM workspace_memberships wm
             JOIN users u ON u.id = wm.user_id
             WHERE wm.workspace_id = ?
               AND wm.department_id = ?
               AND wm.membership_status = 'active'
             ORDER BY u.first_name ASC, u.last_name ASC, u.email ASC",
            [$workspaceId, $departmentId]
        );

        $assignmentsByUser = [];
        $functionService = new OrganizationFunctionService();
        if ($functionService->tablesReady()) {
            $assignmentsByUser = $functionService->assignmentsForWorkspace($workspaceId, true);
        }

        foreach ($members as &$member) {
            $userId = (int) ($member['user_id'] ?? 0);
            $member['work_ownership'] = $assignmentsByUser[$userId] ?? [];
        }
        unset($member);

        return $members;
    }

    public function suggestedWorkOwnershipForDepartment(array $department, ?int $workspaceId = null): array
    {
        $workspaceId = $this->resolveWorkspaceId($workspaceId);
        $slug = strtolower(trim((string) ($department['slug'] ?? '')));
        $name = strtolower(trim((string) ($department['name'] ?? '')));
        $key = $slug !== '' ? $slug : $name;

        $slugMap = [
            'admin' => ['leadership', 'administration'],
            'leadership' => ['leadership', 'strategy', 'administration'],
            'sales' => ['sales'],
            'marketing' => ['marketing'],
            'operations' => ['operations', 'delivery'],
            'finance' => ['finance'],
            'customer_success' => ['customer_success'],
            'success' => ['customer_success'],
            'product' => ['product'],
            'delivery' => ['delivery'],
            'people_hr' => ['people_hr'],
            'hr' => ['people_hr'],
        ];

        $wantedSlugs = [];
        foreach ($slugMap as $needle => $functionSlugs) {
            if ($key === $needle || str_contains($key, $needle) || str_contains($name, str_replace('_', ' ', $needle))) {
                $wantedSlugs = array_merge($wantedSlugs, $functionSlugs);
            }
        }
        $wantedSlugs = array_values(array_unique($wantedSlugs));
        if ($wantedSlugs === []) {
            return [];
        }

        $functionService = new OrganizationFunctionService();
        if (!$functionService->tablesReady()) {
            return [];
        }

        $suggestions = [];
        foreach ($functionService->listAssignableFunctions($workspaceId) as $function) {
            if (in_array((string) ($function['slug'] ?? ''), $wantedSlugs, true)) {
                $suggestions[] = $function;
            }
        }

        return $suggestions;
    }

    public function legacyRoleForDepartment(array $department): string
    {
        $slug = strtolower(trim((string) ($department['slug'] ?? '')));
        $name = strtolower(trim((string) ($department['name'] ?? '')));
        $haystack = $slug . ' ' . $name;

        if (str_contains($haystack, 'admin') || str_contains($haystack, 'leadership') || str_contains($haystack, 'executive')) {
            return 'admin';
        }
        if (str_contains($haystack, 'sales')) {
            return 'sales';
        }
        if (str_contains($haystack, 'marketing') || str_contains($haystack, 'growth')) {
            return 'marketing';
        }

        return 'viewer';
    }

    private function normalizeSlug(string $slug, string $fallbackName): string
    {
        $value = trim($slug);
        if ($value === '') {
            $value = strtolower($fallbackName);
        }

        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';
        $value = trim($value, '_');

        if ($value === '') {
            throw new \InvalidArgumentException('Department slug is required.');
        }

        return $value;
    }

    private function isUntouchedAdminStarter(array $department): bool
    {
        $name = trim((string) ($department['name'] ?? ''));
        $description = trim((string) ($department['description'] ?? ''));

        return in_array($name, ['Admin', 'Administration'], true)
            && in_array(
                $description,
                [
                    '',
                    'Administrative and executive leadership department',
                    'Executive and administrative staff',
                ],
                true
            );
    }

    private function departmentNameExists(int $workspaceId, string $name, int $exceptId): bool
    {
        $existing = Database::queryOne(
            "SELECT id FROM departments WHERE workspace_id = ? AND name = ? AND id <> ? LIMIT 1",
            [$workspaceId, $name, $exceptId]
        );

        return $existing !== null;
    }

    private function resolveWorkspaceId(?int $workspaceId): int
    {
        return (new WorkspaceScopeService())->requireActiveWorkspaceId($workspaceId);
    }
}
