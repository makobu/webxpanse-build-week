<?php

namespace CRM\Services;

use CRM\Database;

class OrganizationFunctionService
{
    public const ASSIGNMENT_TYPES = ['owner', 'oversight', 'contributor', 'temporary_owner'];
    public const RELEVANCE_STATUSES = ['active', 'deferred', 'outsourced', 'not_applicable'];

    public const DEFAULT_FUNCTIONS = [
        ['name' => 'Leadership', 'slug' => 'leadership', 'description' => 'Founder, executive, ownership, delegation, and operating cadence.', 'category' => 'core', 'measurement_strength' => 'partial'],
        ['name' => 'Strategy', 'slug' => 'strategy', 'description' => 'Strategic priorities, market direction, and strategy-to-execution follow-through.', 'category' => 'core', 'measurement_strength' => 'partial'],
        ['name' => 'Operations', 'slug' => 'operations', 'description' => 'Execution rhythm, workload balance, queue health, process reliability, and delivery follow-through.', 'category' => 'core', 'measurement_strength' => 'strong'],
        ['name' => 'Sales', 'slug' => 'sales', 'description' => 'Pipeline movement, follow-up discipline, deal ownership, and revenue-linked execution.', 'category' => 'core', 'measurement_strength' => 'strong'],
        ['name' => 'Marketing', 'slug' => 'marketing', 'description' => 'Campaign ownership, audience activity, growth work, and campaign-to-pipeline contribution.', 'category' => 'core', 'measurement_strength' => 'strong'],
        ['name' => 'Finance', 'slug' => 'finance', 'description' => 'Cash, billing, vendor, financial review, and reporting ownership where tracked.', 'category' => 'support', 'measurement_strength' => 'weak'],
        ['name' => 'Customer Success', 'slug' => 'customer_success', 'description' => 'Customer follow-up, retention, support ownership, and relationship continuity.', 'category' => 'core', 'measurement_strength' => 'partial'],
        ['name' => 'Product', 'slug' => 'product', 'description' => 'Product direction, roadmap work, feature follow-through, and delivery clarity.', 'category' => 'core', 'measurement_strength' => 'weak'],
        ['name' => 'Delivery', 'slug' => 'delivery', 'description' => 'Client/project delivery commitments, fulfilment, and operational handoff quality.', 'category' => 'core', 'measurement_strength' => 'partial'],
        ['name' => 'People / HR', 'slug' => 'people_hr', 'description' => 'Hiring, onboarding, people support, reviews, and employee operating health.', 'category' => 'support', 'measurement_strength' => 'partial'],
        ['name' => 'Administration', 'slug' => 'administration', 'description' => 'Administrative coordination, workspace hygiene, and support operations.', 'category' => 'support', 'measurement_strength' => 'partial'],
    ];

    public function tablesReady(): bool
    {
        return Database::tableExists('organization_functions') && Database::tableExists('user_function_assignments');
    }

    public function ensureDefaults(int $workspaceId): void
    {
        if ($workspaceId <= 0 || !$this->tablesReady()) {
            return;
        }

        foreach (self::DEFAULT_FUNCTIONS as $definition) {
            Database::execute(
                "INSERT INTO organization_functions (workspace_id, name, slug, description, category, measurement_strength, relevance_status, is_core, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, 'active', 1, 1)
                 ON DUPLICATE KEY UPDATE
                    name = VALUES(name),
                    description = COALESCE(organization_functions.description, VALUES(description)),
                    category = COALESCE(NULLIF(organization_functions.category, ''), VALUES(category)),
                    measurement_strength = COALESCE(NULLIF(organization_functions.measurement_strength, ''), VALUES(measurement_strength)),
                    relevance_status = COALESCE(NULLIF(organization_functions.relevance_status, ''), 'active'),
                    is_core = 1",
                [
                    $workspaceId,
                    $definition['name'],
                    $definition['slug'],
                    $definition['description'],
                    $definition['category'],
                    $definition['measurement_strength'],
                ]
            );
        }
    }

    public function listFunctions(int $workspaceId, bool $activeOnly = false): array
    {
        if ($workspaceId <= 0 || !$this->tablesReady()) {
            return [];
        }
        $this->ensureDefaults($workspaceId);

        $sql = "SELECT f.*,
                       COUNT(ufa.id) AS assignments_count
                FROM organization_functions f
                LEFT JOIN user_function_assignments ufa
                  ON ufa.function_id = f.id
                 AND ufa.workspace_id = f.workspace_id
                WHERE f.workspace_id = ?";
        if ($activeOnly) {
            $sql .= ' AND f.is_active = 1';
        }
        $sql .= ' GROUP BY f.id, f.workspace_id, f.name, f.slug, f.description, f.category, f.measurement_strength, f.relevance_status, f.relevance_note, f.is_core, f.is_active, f.created_at, f.updated_at
                  ORDER BY f.is_core DESC, f.name ASC';

        return Database::query($sql, [$workspaceId]);
    }

    public function listActiveFunctions(int $workspaceId): array
    {
        return $this->listFunctions($workspaceId, true);
    }

    public function listAssignableFunctions(int $workspaceId): array
    {
        return array_values(array_filter(
            $this->listActiveFunctions($workspaceId),
            static fn(array $function): bool => (string) ($function['relevance_status'] ?? 'active') === 'active'
        ));
    }

    public function assignmentsForWorkspace(int $workspaceId, bool $assignableOnly = false): array
    {
        if ($workspaceId <= 0 || !$this->tablesReady()) {
            return [];
        }

        $where = 'WHERE ufa.workspace_id = ?';
        if ($assignableOnly) {
            $where .= " AND f.is_active = 1 AND f.relevance_status = 'active'";
        }

        $rows = Database::query(
            "SELECT ufa.*, f.name, f.slug, f.description, f.category, f.measurement_strength, f.relevance_status, f.relevance_note, f.is_active
             FROM user_function_assignments ufa
             JOIN organization_functions f ON f.id = ufa.function_id AND f.workspace_id = ufa.workspace_id
             {$where}
             ORDER BY ufa.is_primary DESC, f.name ASC",
            [$workspaceId]
        );

        $out = [];
        foreach ($rows as $row) {
            $userId = (int) ($row['user_id'] ?? 0);
            if ($userId <= 0) {
                continue;
            }
            $out[$userId][] = $this->normalizeAssignmentRow($row);
        }
        return $out;
    }

    public function assignmentsForUser(int $workspaceId, int $userId, bool $assignableOnly = false): array
    {
        if ($workspaceId <= 0 || $userId <= 0 || !$this->tablesReady()) {
            return [];
        }

        $where = 'WHERE ufa.workspace_id = ? AND ufa.user_id = ?';
        if ($assignableOnly) {
            $where .= " AND f.is_active = 1 AND f.relevance_status = 'active'";
        }

        $rows = Database::query(
            "SELECT ufa.*, f.name, f.slug, f.description, f.category, f.measurement_strength, f.relevance_status, f.relevance_note, f.is_active
             FROM user_function_assignments ufa
             JOIN organization_functions f ON f.id = ufa.function_id AND f.workspace_id = ufa.workspace_id
             {$where}
             ORDER BY ufa.is_primary DESC, f.name ASC",
            [$workspaceId, $userId]
        );

        return array_map(fn(array $row): array => $this->normalizeAssignmentRow($row), $rows);
    }

    public function summarizeAssignments(array $assignments): array
    {
        $normalizedAssignments = [];
        $primary = null;
        $supporting = [];

        foreach ($assignments as $assignment) {
            if (!is_array($assignment)) {
                continue;
            }

            $normalized = [
                'function_id' => (int) ($assignment['function_id'] ?? $assignment['id'] ?? 0),
                'name' => (string) ($assignment['name'] ?? 'Work Ownership'),
                'slug' => (string) ($assignment['slug'] ?? ''),
                'description' => (string) ($assignment['description'] ?? ''),
                'assignment_type' => $this->normalizeAssignmentType((string) ($assignment['assignment_type'] ?? 'contributor')),
                'is_primary' => !empty($assignment['is_primary']),
                'label' => $this->assignmentDisplayLabel($assignment),
            ];

            if ($normalized['function_id'] <= 0) {
                continue;
            }

            $normalizedAssignments[] = $normalized;
            if ($normalized['is_primary'] && $primary === null) {
                $primary = $normalized;
            } else {
                $supporting[] = $normalized;
            }
        }

        return [
            'assignments' => $normalizedAssignments,
            'primary' => $primary,
            'supporting' => $supporting,
            'has_assignments' => $normalizedAssignments !== [],
            'missing_label' => 'Missing Work Ownership',
        ];
    }

    public function coverageForWorkspace(int $workspaceId): array
    {
        if ($workspaceId <= 0 || !$this->tablesReady()) {
            return [
                'functions' => [],
                'active_function_count' => 0,
                'missing_primary_count' => 0,
                'assigned_member_count' => 0,
                'unassigned_active_members' => [],
            ];
        }

        $this->ensureDefaults($workspaceId);
        $functions = $this->listAssignableFunctions($workspaceId);
        $coverage = [];
        foreach ($functions as $function) {
            $functionId = (int) ($function['id'] ?? 0);
            if ($functionId <= 0) {
                continue;
            }

            $coverage[$functionId] = [
                'function_id' => $functionId,
                'name' => (string) ($function['name'] ?? 'Work Ownership'),
                'slug' => (string) ($function['slug'] ?? ''),
                'description' => (string) ($function['description'] ?? ''),
                'category' => (string) ($function['category'] ?? 'core'),
                'measurement_strength' => (string) ($function['measurement_strength'] ?? 'partial'),
                'primary_members' => [],
                'supporting_members' => [],
                'pending_primary_invites' => [],
                'pending_supporting_invites' => [],
                'missing_primary' => true,
                'active_assignment_count' => 0,
                'pending_assignment_count' => 0,
            ];
        }

        if ($coverage === []) {
            return [
                'functions' => [],
                'active_function_count' => 0,
                'missing_primary_count' => 0,
                'assigned_member_count' => 0,
                'unassigned_active_members' => $this->unassignedActiveMembers($workspaceId),
            ];
        }

        $assignmentRows = Database::query(
            "SELECT ufa.function_id, ufa.assignment_type, ufa.is_primary,
                    wm.id AS membership_id, wm.user_id, wm.role_slug, wm.department_id,
                    u.email, u.first_name, u.last_name,
                    d.name AS department_name, d.slug AS department_slug,
                    COALESCE(wr.name, gr.name) AS access_role_name,
                    COALESCE(wr.slug, gr.slug) AS access_role_slug
             FROM user_function_assignments ufa
             JOIN workspace_memberships wm
               ON wm.workspace_id = ufa.workspace_id
              AND wm.user_id = ufa.user_id
              AND wm.membership_status = 'active'
             JOIN users u ON u.id = wm.user_id
             LEFT JOIN departments d
               ON d.id = wm.department_id
              AND d.workspace_id = wm.workspace_id
             LEFT JOIN workspace_user_roles wur
               ON wur.workspace_id = wm.workspace_id
              AND wur.user_id = wm.user_id
             LEFT JOIN roles wr ON wr.id = wur.role_id
             LEFT JOIN user_roles gur ON gur.user_id = wm.user_id
             LEFT JOIN roles gr ON gr.id = gur.role_id
             WHERE ufa.workspace_id = ?
               AND ufa.function_id IN (" . implode(',', array_fill(0, count($coverage), '?')) . ")
             ORDER BY ufa.is_primary DESC, u.first_name ASC, u.last_name ASC, u.email ASC",
            array_merge([$workspaceId], array_keys($coverage))
        );

        $assignedMemberIds = [];
        foreach ($assignmentRows as $row) {
            $functionId = (int) ($row['function_id'] ?? 0);
            if (!isset($coverage[$functionId])) {
                continue;
            }

            $member = $this->coverageMemberRow($row);
            $assignedMemberIds[(int) ($member['user_id'] ?? 0)] = true;
            $coverage[$functionId]['active_assignment_count']++;
            if (!empty($row['is_primary'])) {
                $coverage[$functionId]['primary_members'][] = $member;
                $coverage[$functionId]['missing_primary'] = false;
            } else {
                $coverage[$functionId]['supporting_members'][] = $member;
            }
        }

        if (Database::tableExists('workspace_invite_function_assignments')) {
            $inviteRows = Database::query(
                "SELECT wifa.function_id, wifa.assignment_type, wifa.is_primary,
                        wi.id AS invite_id, wi.email, wi.role_slug, wi.expires_at
                 FROM workspace_invite_function_assignments wifa
                 JOIN workspace_invites wi
                   ON wi.id = wifa.invite_id
                  AND wi.workspace_id = wifa.workspace_id
                  AND wi.invite_status = 'pending'
                  AND wi.expires_at > NOW()
                 WHERE wifa.workspace_id = ?
                   AND wifa.function_id IN (" . implode(',', array_fill(0, count($coverage), '?')) . ")
                 ORDER BY wifa.is_primary DESC, wi.email ASC",
                array_merge([$workspaceId], array_keys($coverage))
            );

            foreach ($inviteRows as $row) {
                $functionId = (int) ($row['function_id'] ?? 0);
                if (!isset($coverage[$functionId])) {
                    continue;
                }

                $invite = [
                    'invite_id' => (int) ($row['invite_id'] ?? 0),
                    'email' => (string) ($row['email'] ?? ''),
                    'role_slug' => (string) ($row['role_slug'] ?? 'viewer'),
                    'assignment_type' => $this->normalizeAssignmentType((string) ($row['assignment_type'] ?? 'contributor')),
                    'is_primary' => !empty($row['is_primary']),
                    'expires_at' => $row['expires_at'] ?? null,
                ];
                $coverage[$functionId]['pending_assignment_count']++;
                if (!empty($row['is_primary'])) {
                    $coverage[$functionId]['pending_primary_invites'][] = $invite;
                } else {
                    $coverage[$functionId]['pending_supporting_invites'][] = $invite;
                }
            }
        }

        $coverageRows = array_values($coverage);

        return [
            'functions' => $coverageRows,
            'active_function_count' => count($coverageRows),
            'missing_primary_count' => count(array_filter($coverageRows, static fn(array $row): bool => !empty($row['missing_primary']))),
            'assigned_member_count' => count($assignedMemberIds),
            'unassigned_active_members' => $this->unassignedActiveMembers($workspaceId),
        ];
    }

    public function upsertUserAssignment(
        int $workspaceId,
        int $userId,
        int $functionId,
        string $assignmentType = 'contributor',
        bool $makePrimary = false
    ): void {
        if ($workspaceId <= 0 || $userId <= 0 || $functionId <= 0 || !$this->tablesReady()) {
            return;
        }

        $allowed = [];
        foreach ($this->listAssignableFunctions($workspaceId) as $function) {
            $allowed[(int) ($function['id'] ?? 0)] = true;
        }
        if (!isset($allowed[$functionId])) {
            throw new \RuntimeException('Choose an active Work Ownership area.');
        }

        $assignmentType = $this->normalizeAssignmentType($assignmentType);
        if ($makePrimary && $assignmentType === 'contributor') {
            $assignmentType = 'owner';
        }

        Database::beginTransaction();
        try {
            if ($makePrimary) {
                Database::execute(
                    "UPDATE user_function_assignments
                     SET is_primary = 0,
                         importance = CASE WHEN importance = 'primary' THEN 'secondary' ELSE importance END,
                         updated_at = NOW()
                     WHERE workspace_id = ?
                       AND user_id = ?",
                    [$workspaceId, $userId]
                );
            }

            Database::execute(
                "INSERT INTO user_function_assignments (workspace_id, user_id, function_id, assignment_type, importance, is_primary)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    assignment_type = VALUES(assignment_type),
                    importance = VALUES(importance),
                    is_primary = VALUES(is_primary),
                    updated_at = NOW()",
                [
                    $workspaceId,
                    $userId,
                    $functionId,
                    $assignmentType,
                    $makePrimary ? 'primary' : 'secondary',
                    $makePrimary ? 1 : 0,
                ]
            );

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    public function saveUserAssignments(int $workspaceId, int $userId, array $functionIds, int $primaryFunctionId = 0, array $assignmentTypes = []): void
    {
        if ($workspaceId <= 0 || $userId <= 0 || !$this->tablesReady()) {
            return;
        }

        $activeFunctions = $this->listAssignableFunctions($workspaceId);
        $allowed = [];
        foreach ($activeFunctions as $function) {
            $allowed[(int) $function['id']] = $function;
        }

        $functionIds = array_values(array_unique(array_filter(array_map('intval', $functionIds), static fn(int $id): bool => $id > 0)));
        $functionIds = array_values(array_filter($functionIds, static fn(int $id): bool => isset($allowed[$id])));

        if ($primaryFunctionId <= 0 || !in_array($primaryFunctionId, $functionIds, true)) {
            $primaryFunctionId = $functionIds[0] ?? 0;
        }

        Database::execute(
            "UPDATE user_function_assignments
             SET is_primary = 0, importance = CASE WHEN importance = 'primary' THEN 'secondary' ELSE importance END
             WHERE workspace_id = ?
               AND user_id = ?",
            [$workspaceId, $userId]
        );

        Database::execute(
            "DELETE FROM user_function_assignments
             WHERE workspace_id = ?
               AND user_id = ?",
            [$workspaceId, $userId]
        );

        foreach ($functionIds as $functionId) {
            $isPrimary = $functionId === $primaryFunctionId;
            $assignmentType = $this->normalizeAssignmentType((string) ($assignmentTypes[$functionId] ?? ($isPrimary ? 'owner' : 'contributor')));
            if ($isPrimary && $assignmentType === 'contributor') {
                $assignmentType = 'owner';
            }
            Database::execute(
                "INSERT INTO user_function_assignments (workspace_id, user_id, function_id, assignment_type, importance, is_primary)
                 VALUES (?, ?, ?, ?, ?, ?)",
                [
                    $workspaceId,
                    $userId,
                    $functionId,
                    $assignmentType,
                    $isPrimary ? 'primary' : 'secondary',
                    $isPrimary ? 1 : 0,
                ]
            );
        }
    }

    public function assignDefaultFunctionsForRole(int $workspaceId, int $userId, string $roleSlug, bool $isOwner = false): void
    {
        if ($workspaceId <= 0 || $userId <= 0 || !$this->tablesReady()) {
            return;
        }

        $functionIds = $this->defaultFunctionIdsForRole($workspaceId, $roleSlug, $isOwner);
        if ($functionIds === []) {
            return;
        }

        $assignmentTypes = $this->defaultAssignmentTypesForRole($functionIds, $roleSlug, $isOwner);
        $primaryFunctionId = $this->primaryFunctionIdForDefaults($workspaceId, $functionIds);
        $this->saveUserAssignments($workspaceId, $userId, $functionIds, $primaryFunctionId, $assignmentTypes);
    }

    public function ensureOwnerFunctionCoverage(int $workspaceId, int $userId): void
    {
        if ($workspaceId <= 0 || $userId <= 0 || !$this->tablesReady()) {
            return;
        }

        $this->ensureDefaults($workspaceId);
        $functionIds = $this->defaultFunctionIdsForRole($workspaceId, 'owner', true);
        if ($functionIds === []) {
            return;
        }

        $primaryFunctionId = $this->primaryFunctionIdForDefaults($workspaceId, $functionIds);
        Database::execute(
            "UPDATE user_function_assignments
             SET importance = CASE WHEN importance = 'primary' THEN 'secondary' ELSE importance END,
                 is_primary = 0,
                 updated_at = NOW()
             WHERE workspace_id = ?
               AND user_id = ?",
            [$workspaceId, $userId]
        );

        foreach ($functionIds as $functionId) {
            $isPrimary = $functionId === $primaryFunctionId;
            Database::execute(
                "INSERT INTO user_function_assignments (workspace_id, user_id, function_id, assignment_type, importance, is_primary)
                 VALUES (?, ?, ?, 'owner', ?, ?)
                 ON DUPLICATE KEY UPDATE
                    assignment_type = 'owner',
                    importance = VALUES(importance),
                    is_primary = VALUES(is_primary),
                    updated_at = NOW()",
                [
                    $workspaceId,
                    $userId,
                    $functionId,
                    $isPrimary ? 'primary' : 'secondary',
                    $isPrimary ? 1 : 0,
                ]
            );
        }
    }

    public function ensureOwnerFunctionCoverageForWorkspace(int $workspaceId): int
    {
        if ($workspaceId <= 0 || !$this->tablesReady() || !Database::tableExists('workspace_memberships')) {
            return 0;
        }

        $owners = Database::query(
            "SELECT DISTINCT user_id
             FROM workspace_memberships
             WHERE workspace_id = ?
               AND membership_status = 'active'
               AND (is_owner = 1 OR role_slug = 'owner')",
            [$workspaceId]
        );

        $repaired = 0;
        foreach ($owners as $owner) {
            $userId = (int) ($owner['user_id'] ?? 0);
            if ($userId <= 0) {
                continue;
            }
            $this->ensureOwnerFunctionCoverage($workspaceId, $userId);
            $repaired++;
        }

        return $repaired;
    }

    public function applySuggestedAssignmentsForUsers(int $workspaceId, array $userIds): array
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn(int $id): bool => $id > 0)));
        $summary = [
            'requested' => count($userIds),
            'applied' => 0,
            'skipped' => 0,
            'users' => [],
            'skipped_users' => [],
        ];

        if ($workspaceId <= 0 || $userIds === [] || !$this->tablesReady()) {
            return $summary;
        }

        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $members = Database::query(
            "SELECT wm.user_id, wm.role_slug, wm.is_owner, u.email, u.first_name, u.last_name
             FROM workspace_memberships wm
             JOIN users u ON u.id = wm.user_id
             WHERE wm.workspace_id = ?
               AND wm.membership_status = 'active'
               AND wm.user_id IN ({$placeholders})
             ORDER BY COALESCE(NULLIF(u.first_name, ''), u.email) ASC",
            array_merge([$workspaceId], $userIds)
        );

        $memberByUserId = [];
        foreach ($members as $member) {
            $memberByUserId[(int) ($member['user_id'] ?? 0)] = $member;
        }

        $assignableFunctions = $this->listAssignableFunctions($workspaceId);
        $functionNamesById = [];
        foreach ($assignableFunctions as $function) {
            $functionNamesById[(int) ($function['id'] ?? 0)] = (string) ($function['name'] ?? 'Function');
        }

        foreach ($userIds as $userId) {
            $member = $memberByUserId[$userId] ?? null;
            if (!$member) {
                $summary['skipped']++;
                $summary['skipped_users'][] = ['user_id' => $userId, 'reason' => 'not_active_member'];
                continue;
            }

            $label = trim((string) (($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? '')));
            if ($label === '') {
                $label = (string) ($member['email'] ?? 'Member');
            }

            if ($this->assignmentsForUser($workspaceId, $userId, true) !== []) {
                $summary['skipped']++;
                $summary['skipped_users'][] = ['user_id' => $userId, 'label' => $label, 'reason' => 'already_assigned'];
                continue;
            }

            $functionIds = $this->defaultFunctionIdsForRole($workspaceId, (string) ($member['role_slug'] ?? 'viewer'), !empty($member['is_owner']));
            if ($functionIds === []) {
                $summary['skipped']++;
                $summary['skipped_users'][] = ['user_id' => $userId, 'label' => $label, 'reason' => 'no_default_functions'];
                continue;
            }

            $assignmentTypes = $this->defaultAssignmentTypesForRole($functionIds, (string) ($member['role_slug'] ?? 'viewer'), !empty($member['is_owner']));
            $primaryFunctionId = $this->primaryFunctionIdForDefaults($workspaceId, $functionIds);
            $this->saveUserAssignments($workspaceId, $userId, $functionIds, $primaryFunctionId, $assignmentTypes);

            $summary['applied']++;
            $summary['users'][] = [
                'user_id' => $userId,
                'label' => $label,
                'function_ids' => $functionIds,
                'function_names' => array_values(array_filter(array_map(
                    static fn(int $functionId): string => $functionNamesById[$functionId] ?? '',
                    $functionIds
                ))),
                'primary_function_id' => $primaryFunctionId,
            ];
        }

        return $summary;
    }

    public function createFunction(int $workspaceId, array $data): int
    {
        if ($workspaceId <= 0 || !$this->tablesReady()) {
            throw new \RuntimeException('Organization functions are not available. Run migrations first.');
        }

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('Function name is required.');
        }

        $slug = $this->normalizeSlug((string) ($data['slug'] ?? ''), $name);
        $description = trim((string) ($data['description'] ?? ''));
        $category = $this->normalizeCategory((string) ($data['category'] ?? 'core'));
        $measurementStrength = $this->normalizeMeasurementStrength((string) ($data['measurement_strength'] ?? 'partial'));
        $relevanceStatus = $this->normalizeRelevanceStatus((string) ($data['relevance_status'] ?? 'active'));
        $relevanceNote = trim((string) ($data['relevance_note'] ?? ''));

        Database::execute(
            "INSERT INTO organization_functions (workspace_id, name, slug, description, category, measurement_strength, relevance_status, relevance_note, is_core, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 1)",
            [$workspaceId, $name, $slug, $description !== '' ? $description : null, $category, $measurementStrength, $relevanceStatus, $relevanceNote !== '' ? $relevanceNote : null]
        );

        return (int) Database::lastInsertId();
    }

    public function updateFunction(int $workspaceId, int $functionId, array $data): void
    {
        $function = $this->getFunction($workspaceId, $functionId);
        if (!$function) {
            throw new \RuntimeException('Organization function not found.');
        }

        $name = trim((string) ($data['name'] ?? $function['name']));
        if ($name === '') {
            throw new \InvalidArgumentException('Function name is required.');
        }

        $slug = $this->normalizeSlug((string) ($data['slug'] ?? $function['slug']), $name);
        $description = trim((string) ($data['description'] ?? ($function['description'] ?? '')));
        $category = $this->normalizeCategory((string) ($data['category'] ?? $function['category'] ?? 'core'));
        $measurementStrength = $this->normalizeMeasurementStrength((string) ($data['measurement_strength'] ?? $function['measurement_strength'] ?? 'partial'));
        $relevanceStatus = $this->normalizeRelevanceStatus((string) ($data['relevance_status'] ?? $function['relevance_status'] ?? 'active'));
        $relevanceNote = trim((string) ($data['relevance_note'] ?? ($function['relevance_note'] ?? '')));
        $active = array_key_exists('is_active', $data) ? !empty($data['is_active']) : !empty($function['is_active']);

        if (!$active && $this->activeAssignmentCount($workspaceId, $functionId) > 0) {
            throw new \RuntimeException('Remove active user assignments before deactivating this function.');
        }

        Database::execute(
            "UPDATE organization_functions
             SET name = ?, slug = ?, description = ?, category = ?, measurement_strength = ?, relevance_status = ?, relevance_note = ?, is_active = ?
             WHERE id = ?
               AND workspace_id = ?",
            [$name, $slug, $description !== '' ? $description : null, $category, $measurementStrength, $relevanceStatus, $relevanceNote !== '' ? $relevanceNote : null, $active ? 1 : 0, $functionId, $workspaceId]
        );
    }

    public function defaultFunctionIdsForRole(int $workspaceId, string $roleSlug, bool $isOwner = false): array
    {
        $functions = $this->listAssignableFunctions($workspaceId);
        $bySlug = [];
        foreach ($functions as $function) {
            $bySlug[(string) $function['slug']] = (int) $function['id'];
        }

        $role = strtolower(trim($roleSlug));
        if ($isOwner || $role === 'owner') {
            return $this->orderedFunctionIds($functions);
        }
        if ($role === 'admin') {
            return array_values(array_filter([$bySlug['leadership'] ?? 0, $bySlug['strategy'] ?? 0]));
        }
        if ($role === 'accountant') {
            return array_values(array_filter([$bySlug['finance'] ?? 0]));
        }
        if (str_contains($role, 'marketing')) {
            return array_values(array_filter([$bySlug['marketing'] ?? 0]));
        }
        if (str_contains($role, 'sales')) {
            return array_values(array_filter([$bySlug['sales'] ?? 0]));
        }
        return array_values(array_filter([$bySlug['operations'] ?? ($bySlug['administration'] ?? 0)]));
    }

    public function defaultAssignmentTypesForRole(array $functionIds, string $roleSlug, bool $isOwner = false): array
    {
        $functionIds = array_values(array_unique(array_filter(array_map('intval', $functionIds), static fn(int $id): bool => $id > 0)));
        $role = strtolower(trim($roleSlug));
        $types = [];

        foreach ($functionIds as $index => $functionId) {
            $types[$functionId] = ($isOwner || $role === 'owner') ? 'owner' : ($index === 0 ? 'owner' : 'contributor');
        }

        return $types;
    }

    public function primaryFunctionIdForDefaults(int $workspaceId, array $functionIds): int
    {
        $functionIds = array_values(array_unique(array_filter(array_map('intval', $functionIds), static fn(int $id): bool => $id > 0)));
        if ($functionIds === [] || $workspaceId <= 0 || !$this->tablesReady()) {
            return 0;
        }

        $leadership = Database::queryOne(
            "SELECT id
             FROM organization_functions
             WHERE workspace_id = ?
               AND slug = 'leadership'
               AND id IN (" . implode(',', array_fill(0, count($functionIds), '?')) . ")
             LIMIT 1",
            array_merge([$workspaceId], $functionIds)
        );
        $leadershipId = (int) ($leadership['id'] ?? 0);

        return $leadershipId > 0 ? $leadershipId : (int) $functionIds[0];
    }

    private function orderedFunctionIds(array $functions): array
    {
        $bySlug = [];
        $seenIds = [];
        foreach ($functions as $function) {
            $id = (int) ($function['id'] ?? 0);
            $slug = (string) ($function['slug'] ?? '');
            if ($id <= 0 || $slug === '') {
                continue;
            }
            $bySlug[$slug] = $id;
        }

        $ordered = [];
        foreach (self::DEFAULT_FUNCTIONS as $definition) {
            $slug = (string) ($definition['slug'] ?? '');
            $id = (int) ($bySlug[$slug] ?? 0);
            if ($id > 0 && !isset($seenIds[$id])) {
                $ordered[] = $id;
                $seenIds[$id] = true;
            }
        }

        foreach ($functions as $function) {
            $id = (int) ($function['id'] ?? 0);
            if ($id > 0 && !isset($seenIds[$id])) {
                $ordered[] = $id;
                $seenIds[$id] = true;
            }
        }

        return $ordered;
    }

    private function getFunction(int $workspaceId, int $functionId): ?array
    {
        if ($workspaceId <= 0 || $functionId <= 0 || !$this->tablesReady()) {
            return null;
        }

        return Database::queryOne(
            "SELECT f.*,
                    COUNT(ufa.id) AS assignments_count
             FROM organization_functions f
             LEFT JOIN user_function_assignments ufa
               ON ufa.function_id = f.id
              AND ufa.workspace_id = f.workspace_id
             WHERE f.workspace_id = ?
               AND f.id = ?
             GROUP BY f.id, f.workspace_id, f.name, f.slug, f.description, f.category, f.measurement_strength, f.relevance_status, f.relevance_note, f.is_core, f.is_active, f.created_at, f.updated_at",
            [$workspaceId, $functionId]
        );
    }

    private function activeAssignmentCount(int $workspaceId, int $functionId): int
    {
        $row = Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM user_function_assignments ufa
             JOIN workspace_memberships wm
               ON wm.workspace_id = ufa.workspace_id
              AND wm.user_id = ufa.user_id
              AND wm.membership_status = 'active'
             WHERE ufa.workspace_id = ?
               AND ufa.function_id = ?",
            [$workspaceId, $functionId]
        );

        return (int) ($row['c'] ?? 0);
    }

    private function normalizeAssignmentRow(array $row): array
    {
        return [
            'assignment_id' => (int) ($row['id'] ?? 0),
            'function_id' => (int) ($row['function_id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'slug' => (string) ($row['slug'] ?? ''),
            'description' => (string) ($row['description'] ?? ''),
            'category' => (string) ($row['category'] ?? 'core'),
            'measurement_strength' => (string) ($row['measurement_strength'] ?? 'partial'),
            'relevance_status' => (string) ($row['relevance_status'] ?? 'active'),
            'relevance_note' => (string) ($row['relevance_note'] ?? ''),
            'assignment_type' => $this->normalizeAssignmentType((string) ($row['assignment_type'] ?? 'owner')),
            'importance' => (string) ($row['importance'] ?? 'secondary'),
            'is_primary' => !empty($row['is_primary']),
            'is_active' => !empty($row['is_active']),
            'source' => 'explicit_assignment',
            'is_inferred' => false,
            'assignment_confidence' => 'high',
        ];
    }

    private function assignmentDisplayLabel(array $assignment): string
    {
        $name = trim((string) ($assignment['name'] ?? 'Work Ownership'));
        if (!empty($assignment['is_primary'])) {
            return $name . ' (Primary)';
        }

        return $name;
    }

    private function coverageMemberRow(array $row): array
    {
        $displayName = trim((string) (($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')));
        if ($displayName === '') {
            $displayName = (string) ($row['email'] ?? 'Member');
        }

        return [
            'membership_id' => (int) ($row['membership_id'] ?? 0),
            'user_id' => (int) ($row['user_id'] ?? 0),
            'display_name' => $displayName,
            'email' => (string) ($row['email'] ?? ''),
            'role_slug' => (string) ($row['role_slug'] ?? 'viewer'),
            'department_id' => (int) ($row['department_id'] ?? 0),
            'department_name' => (string) ($row['department_name'] ?? 'Unassigned'),
            'department_slug' => (string) ($row['department_slug'] ?? 'unassigned'),
            'access_role_name' => (string) ($row['access_role_name'] ?? ''),
            'access_role_slug' => (string) ($row['access_role_slug'] ?? ''),
            'assignment_type' => $this->normalizeAssignmentType((string) ($row['assignment_type'] ?? 'contributor')),
            'is_primary' => !empty($row['is_primary']),
        ];
    }

    private function unassignedActiveMembers(int $workspaceId): array
    {
        if ($workspaceId <= 0) {
            return [];
        }

        $rows = Database::query(
            "SELECT wm.id AS membership_id, wm.user_id, wm.role_slug, wm.department_id,
                    u.email, u.first_name, u.last_name,
                    d.name AS department_name, d.slug AS department_slug,
                    COALESCE(wr.name, gr.name) AS access_role_name,
                    COALESCE(wr.slug, gr.slug) AS access_role_slug
             FROM workspace_memberships wm
             JOIN users u ON u.id = wm.user_id
             LEFT JOIN departments d
               ON d.id = wm.department_id
              AND d.workspace_id = wm.workspace_id
             LEFT JOIN workspace_user_roles wur
               ON wur.workspace_id = wm.workspace_id
              AND wur.user_id = wm.user_id
             LEFT JOIN roles wr ON wr.id = wur.role_id
             LEFT JOIN user_roles gur ON gur.user_id = wm.user_id
             LEFT JOIN roles gr ON gr.id = gur.role_id
             LEFT JOIN user_function_assignments ufa
               ON ufa.workspace_id = wm.workspace_id
              AND ufa.user_id = wm.user_id
             WHERE wm.workspace_id = ?
               AND wm.membership_status = 'active'
             GROUP BY wm.id, wm.user_id, wm.role_slug, wm.department_id, u.email, u.first_name, u.last_name, d.name, d.slug, wr.name, gr.name, wr.slug, gr.slug
             HAVING COUNT(ufa.id) = 0
             ORDER BY u.first_name ASC, u.last_name ASC, u.email ASC",
            [$workspaceId]
        );

        return array_map(fn(array $row): array => $this->coverageMemberRow($row), $rows);
    }

    private function normalizeSlug(string $slug, string $fallbackName): string
    {
        $value = trim($slug) !== '' ? trim($slug) : $fallbackName;
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? '';
        $value = trim($value, '_');
        if ($value === '') {
            throw new \InvalidArgumentException('Function slug is required.');
        }
        return $value;
    }

    private function normalizeCategory(string $category): string
    {
        $category = strtolower(trim($category));
        return in_array($category, ['core', 'support', 'optional'], true) ? $category : 'core';
    }

    private function normalizeMeasurementStrength(string $strength): string
    {
        $strength = strtolower(trim($strength));
        return in_array($strength, ['strong', 'partial', 'weak'], true) ? $strength : 'partial';
    }

    private function normalizeAssignmentType(string $type): string
    {
        $type = strtolower(trim($type));
        return in_array($type, self::ASSIGNMENT_TYPES, true) ? $type : 'contributor';
    }

    private function normalizeRelevanceStatus(string $status): string
    {
        $status = strtolower(trim($status));
        return in_array($status, self::RELEVANCE_STATUSES, true) ? $status : 'active';
    }
}
