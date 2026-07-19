<?php
/**
 * Full lifecycle nurture module.
 *
 * Keeps relationship state separate from deal pressure while reusing contacts,
 * activities, tasks, campaigns, and scoring signals.
 */

namespace CRM\Modules;

use CRM\Database;
use CRM\Security;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceScopeService;

class Nurture
{
    public const LANES = ['customer_success', 'expansion', 'at_risk', 'inactive'];
    public const STATUSES = ['active', 'needs_touch', 'paused', 'completed', 'exited'];
    public const TEMPERATURES = ['hot', 'warm', 'cool', 'cold'];
    public const CADENCES = ['weekly', 'biweekly', 'monthly', 'quarterly', 'manual'];
    public const ENTRY_SOURCES = ['deal_closed_won', 'paid_invoice', 'workspace_account', 'manual_customer_success'];
    public const PROGRAM_TYPES = ['customer_success', 'expansion', 'risk_recovery'];
    public const PROGRAM_STATUSES = ['draft', 'active', 'paused', 'archived'];
    public const PLAN_CHANNELS = ['task', 'email', 'whatsapp', 'sms', 'phone', 'meeting', 'note', 'other'];
    public const PLAN_TOUCH_TYPES = ['check_in', 'renewal', 'expansion', 'risk_recovery'];

    private WorkspaceScopeService $workspaceScope;
    private bool $workspaceProfilesSynced = false;

    public function __construct()
    {
        $this->workspaceScope = new WorkspaceScopeService();
    }

    public function syncWorkspaceProfilesOnce(int $limit = 250): void
    {
        if ($this->workspaceProfilesSynced) {
            return;
        }

        $this->syncWorkspaceProfiles(max(1, $limit));
        $this->workspaceProfilesSynced = true;
    }

    public function getOrCreateProfile(int $contactId): array
    {
        $contact = $this->getContact($contactId);
        if (!$contact) {
            throw new \RuntimeException('Contact not found in the active workspace.');
        }

        $purchaseEvidence = $this->resolvePurchaseEvidence($contact);
        $existing = $this->getProfileRow($contactId);
        if (!$purchaseEvidence && !$this->isManualCustomerSuccessProfile($existing)) {
            if ($existing) {
                $this->exitProfile($contactId, 'Exited because no payment or purchase evidence was found.');
            }
            throw new \RuntimeException('Customer Care is only for paying customers with purchase evidence.');
        }

        if ($existing) {
            return $this->refreshProfile($contactId);
        }

        $snapshot = $this->buildNurtureSnapshot($contact, $this->loadContactMetrics($contactId), null, $purchaseEvidence);
        $workspaceId = $this->workspaceId();

        Database::execute(
            "INSERT INTO nurture_profiles
             (workspace_id, contact_id, lifecycle_lane, nurture_status, temperature, cadence, owner_user_id,
              entry_source, entry_reference_id, entry_at, purchase_summary_json, last_touch_at, next_touch_at,
              next_touch_reason, health_score, health_signals_json, suggested_touch_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $workspaceId,
                $contactId,
                $snapshot['lifecycle_lane'],
                $snapshot['nurture_status'],
                $snapshot['temperature'],
                $snapshot['cadence'],
                $snapshot['owner_user_id'],
                $snapshot['entry_source'],
                $snapshot['entry_reference_id'],
                $snapshot['entry_at'],
                json_encode($snapshot['purchase_summary']),
                $snapshot['last_touch_at'],
                $snapshot['next_touch_at'],
                $snapshot['next_touch_reason'],
                $snapshot['health_score'],
                json_encode($snapshot['health_signals']),
                json_encode($snapshot['suggested_touch']),
            ]
        );

        return $this->getProfileForContact($contactId) ?? [];
    }

    public function refreshProfile(int $contactId): array
    {
        $contact = $this->getContact($contactId);
        if (!$contact) {
            throw new \RuntimeException('Contact not found in the active workspace.');
        }

        $existing = $this->getProfileRow($contactId);
        if (!$existing) {
            return $this->getOrCreateProfile($contactId);
        }

        $purchaseEvidence = $this->resolvePurchaseEvidence($contact);
        if (!$purchaseEvidence && !$this->isManualCustomerSuccessProfile($existing)) {
            $this->exitProfile($contactId, 'Exited because no payment or purchase evidence was found.');
            throw new \RuntimeException('Customer Care is only for paying customers with purchase evidence.');
        }

        $snapshot = $this->buildNurtureSnapshot($contact, $this->loadContactMetrics($contactId), $existing, $purchaseEvidence);
        Database::execute(
            "UPDATE nurture_profiles
             SET lifecycle_lane = ?,
                 nurture_status = ?,
                 temperature = ?,
                 cadence = ?,
                 owner_user_id = ?,
                 entry_source = ?,
                 entry_reference_id = ?,
                 entry_at = ?,
                 purchase_summary_json = ?,
                 last_touch_at = ?,
                 next_touch_at = ?,
                 next_touch_reason = ?,
                 health_score = ?,
                 health_signals_json = ?,
                 suggested_touch_json = ?
             WHERE workspace_id = ? AND contact_id = ?",
            [
                $snapshot['lifecycle_lane'],
                $snapshot['nurture_status'],
                $snapshot['temperature'],
                $snapshot['cadence'],
                $snapshot['owner_user_id'],
                $snapshot['entry_source'],
                $snapshot['entry_reference_id'],
                $snapshot['entry_at'],
                json_encode($snapshot['purchase_summary']),
                $snapshot['last_touch_at'],
                $snapshot['next_touch_at'],
                $snapshot['next_touch_reason'],
                $snapshot['health_score'],
                json_encode($snapshot['health_signals']),
                json_encode($snapshot['suggested_touch']),
                $this->workspaceId(),
                $contactId,
            ]
        );

        return $this->getProfileForContact($contactId) ?? [];
    }

    public function getProfileForContact(int $contactId): ?array
    {
        $profile = Database::queryOne(
            "SELECT np.*, c.first_name, c.last_name, c.email, c.phone, c.company, c.stage, c.lead_source,
                    c.lead_score, c.engagement_score, c.assigned_to, u.email AS owner_email
             FROM nurture_profiles np
             JOIN contacts c ON c.id = np.contact_id AND c.workspace_id = np.workspace_id
             LEFT JOIN users u ON u.id = np.owner_user_id
             WHERE np.workspace_id = ? AND np.contact_id = ?",
            [$this->workspaceId(), $contactId]
        );

        if (!$profile) {
            return null;
        }

        return $this->hydrateProfile($profile);
    }

    public function listProfiles(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        $this->syncWorkspaceProfilesOnce(250);

        $limit = min(max($limit, 1), 250);
        $offset = max($offset, 0);
        [$where, $params] = $this->buildProfileFilters($filters);

        $sql = "SELECT np.*, c.first_name, c.last_name, c.email, c.phone, c.company, c.stage, c.lead_source,
                       c.lead_score, c.engagement_score, c.assigned_to, u.email AS owner_email,
                       ne_active.id AS active_enrollment_id,
                       ne_active.program_id AS active_program_id,
                       ne_active.current_step_label AS active_program_step,
                       ne_active.next_touch_at AS active_program_next_touch_at,
                       nprog.name AS active_program_name,
                       nprog.program_type AS active_program_type,
                       nprog.cadence AS active_program_cadence,
                       nprog.first_touch_delay_days AS active_program_first_touch_delay_days,
                       nprog.preferred_channel AS active_program_preferred_channel,
                       nprog.default_touch_type AS active_program_default_touch_type,
                       nprog.touch_guidance AS active_program_touch_guidance
                FROM nurture_profiles np
                JOIN contacts c ON c.id = np.contact_id AND c.workspace_id = np.workspace_id
                LEFT JOIN users u ON u.id = np.owner_user_id
                LEFT JOIN nurture_enrollments ne_active ON ne_active.id = (
                    SELECT ne_latest.id
                    FROM nurture_enrollments ne_latest
                    WHERE ne_latest.workspace_id = np.workspace_id
                      AND ne_latest.contact_id = np.contact_id
                      AND ne_latest.status = 'active'
                    ORDER BY ne_latest.updated_at DESC, ne_latest.id DESC
                    LIMIT 1
                )
                LEFT JOIN nurture_programs nprog ON nprog.id = ne_active.program_id AND nprog.workspace_id = ne_active.workspace_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY
                    CASE WHEN np.next_touch_at IS NULL THEN 0 ELSE 1 END ASC,
                    np.next_touch_at ASC,
                    np.updated_at DESC
                LIMIT {$limit} OFFSET {$offset}";

        return array_map(fn(array $row): array => $this->hydrateProfile($row), Database::query($sql, $params));
    }

    public function getCounts(array $baseFilters = []): array
    {
        $this->syncWorkspaceProfilesOnce(250);

        $countKeys = [
            'due_checkins',
            'onboarding',
            'healthy',
            'needs_attention',
            'expansion',
            'renewal',
            'dormant',
            'all',
        ];
        $counts = array_fill_keys($countKeys, 0);

        if (!empty($baseFilters['tab'])) {
            return $this->getCountsLegacy($baseFilters);
        }

        $filters = $baseFilters;
        unset($filters['tab']);
        [$where, $params] = $this->buildProfileFilters($filters);
        $row = Database::queryOne(
            "SELECT
                COUNT(*) AS all_count,
                SUM(CASE WHEN (np.nurture_status = 'needs_touch' OR np.next_touch_at IS NULL OR np.next_touch_at <= NOW()) THEN 1 ELSE 0 END) AS due_checkins,
                SUM(CASE WHEN np.lifecycle_lane = 'customer_success'
                              AND np.entry_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                              AND np.nurture_status NOT IN ('paused', 'completed', 'exited') THEN 1 ELSE 0 END) AS onboarding,
                SUM(CASE WHEN np.lifecycle_lane = 'customer_success'
                              AND np.health_score >= 70
                              AND np.nurture_status NOT IN ('needs_touch', 'paused', 'completed', 'exited') THEN 1 ELSE 0 END) AS healthy,
                SUM(CASE WHEN (np.lifecycle_lane = 'at_risk' OR np.health_score < 50 OR np.nurture_status = 'needs_touch') THEN 1 ELSE 0 END) AS needs_attention,
                SUM(CASE WHEN np.lifecycle_lane = 'expansion' THEN 1 ELSE 0 END) AS expansion,
                SUM(CASE WHEN (np.next_touch_reason LIKE '%renewal%' OR JSON_UNQUOTE(JSON_EXTRACT(np.purchase_summary_json, '$.status')) IN ('active', 'current'))
                              AND np.nurture_status NOT IN ('paused', 'completed', 'exited') THEN 1 ELSE 0 END) AS renewal,
                SUM(CASE WHEN " . $this->reactivationProfileConditionSql() . "
                              AND np.nurture_status <> 'completed' THEN 1 ELSE 0 END) AS dormant
             FROM nurture_profiles np
             JOIN contacts c ON c.id = np.contact_id AND c.workspace_id = np.workspace_id
             WHERE " . implode(' AND ', $where),
            $params
        );

        foreach (array_diff($countKeys, ['all']) as $key) {
            $counts[$key] = (int) ($row[$key] ?? 0);
        }
        $counts['all'] = (int) ($row['all_count'] ?? 0);

        return $counts;
    }

    private function getCountsLegacy(array $baseFilters = []): array
    {
        $counts = [
            'due_checkins' => 0,
            'onboarding' => 0,
            'healthy' => 0,
            'needs_attention' => 0,
            'expansion' => 0,
            'renewal' => 0,
            'dormant' => 0,
            'all' => 0,
        ];

        foreach (array_diff(array_keys($counts), ['all']) as $tab) {
            $filters = array_merge($baseFilters, ['tab' => $tab]);
            [$where, $params] = $this->buildProfileFilters($filters);
            $row = Database::queryOne(
                "SELECT COUNT(*) AS count FROM nurture_profiles np
                 JOIN contacts c ON c.id = np.contact_id AND c.workspace_id = np.workspace_id
                 WHERE " . implode(' AND ', $where),
                $params
            );
            $counts[$tab] = (int) ($row['count'] ?? 0);
        }
        $allFilters = $baseFilters;
        unset($allFilters['tab']);
        [$where, $params] = $this->buildProfileFilters($allFilters);
        $row = Database::queryOne(
            "SELECT COUNT(*) AS count FROM nurture_profiles np
             JOIN contacts c ON c.id = np.contact_id AND c.workspace_id = np.workspace_id
             WHERE " . implode(' AND ', $where),
            $params
        );
        $counts['all'] = (int) ($row['count'] ?? 0);

        return $counts;
    }

    public function updateProfile(int $contactId, array $data): array
    {
        $this->getOrCreateProfile($contactId);
        $updates = [];
        $params = [];

        $fieldMap = [
            'lifecycle_lane' => self::LANES,
            'nurture_status' => self::STATUSES,
            'temperature' => self::TEMPERATURES,
            'cadence' => self::CADENCES,
        ];

        foreach ($fieldMap as $field => $allowed) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $value = (string) $data[$field];
            if (!in_array($value, $allowed, true)) {
                throw new \InvalidArgumentException("Invalid {$field}");
            }
            $updates[] = "{$field} = ?";
            $params[] = $value;
        }

        if (array_key_exists('next_touch_at', $data)) {
            $updates[] = 'next_touch_at = ?';
            $params[] = $this->normalizeDateTime($data['next_touch_at'] ?? null);
        }
        if (array_key_exists('next_touch_reason', $data)) {
            $updates[] = 'next_touch_reason = ?';
            $params[] = Security::sanitizeInput((string) ($data['next_touch_reason'] ?? ''), 'string') ?: null;
        }
        if (array_key_exists('owner_user_id', $data)) {
            $updates[] = 'owner_user_id = ?';
            $params[] = !empty($data['owner_user_id']) ? (int) $data['owner_user_id'] : null;
        }

        if ($updates) {
            $params[] = $this->workspaceId();
            $params[] = $contactId;
            Database::execute(
                "UPDATE nurture_profiles SET " . implode(', ', $updates) . " WHERE workspace_id = ? AND contact_id = ?",
                $params
            );
        }

        return $this->getProfileForContact($contactId) ?? [];
    }

    public function careBrief(array $profile): array
    {
        $whyNow = $this->plainWhyNow($profile);
        $suggested = trim((string) ($profile['next_touch_reason'] ?? $profile['suggested_touch']['angle'] ?? ''));
        if ($suggested === '') {
            $suggested = 'Check whether the customer is getting the promised outcome.';
        }

        $nextTouchAt = (string) ($profile['next_touch_at'] ?? '');
        $nextLabel = $nextTouchAt !== '' ? date('M j, Y', strtotime($nextTouchAt)) : 'Set when needed';
        $contactName = trim((string) ($profile['contact_name'] ?? 'This customer'));
        if ($contactName === '') {
            $contactName = 'This customer';
        }

        return [
            'why_now' => $whyNow,
            'status_sentence' => $this->plainStatusSentence($profile, $whyNow),
            'suggested_next_step' => $suggested,
            'suggested_plan' => $this->suggestedPlanName($profile),
            'next_check_in_label' => $nextLabel,
            'task_title' => 'Check in with ' . $contactName,
            'task_description' => $suggested,
        ];
    }

    public function recordCheckIn(int $contactId, array $data = []): array
    {
        $profile = $this->getOrCreateProfile($contactId);
        $activePlan = $this->getActiveEnrollment($contactId);
        $brief = $this->careBrief($profile);
        $checkedAt = $this->normalizeDateTime($data['checked_at'] ?? null) ?? date('Y-m-d H:i:s');
        $cadence = (string) ($activePlan['cadence'] ?? ($profile['cadence'] ?? 'monthly'));
        $nextTouchAt = $cadence === 'manual' ? null : $this->nextTouchFrom($checkedAt, $cadence);
        $subject = Security::sanitizeInput((string) ($data['subject'] ?? 'Customer check-in completed'), 'string');
        $planGuidance = trim((string) ($activePlan['touch_guidance'] ?? ''));
        $notes = Security::sanitizeInput((string) ($data['notes'] ?? ($planGuidance !== '' ? $planGuidance : $brief['suggested_next_step'])), 'string');
        $channel = $this->normalizePlanChannel((string) ($data['channel'] ?? ($activePlan['preferred_channel'] ?? 'note')));
        $touchType = $this->normalizePlanTouchType((string) ($data['touch_type'] ?? ($activePlan['default_touch_type'] ?? 'check_in')));

        $this->createTouchpoint([
            'profile_id' => (int) $profile['id'],
            'contact_id' => $contactId,
            'touch_type' => $touchType,
            'channel' => $channel,
            'status' => 'completed',
            'subject' => $subject,
            'notes' => $notes,
            'scheduled_at' => $checkedAt,
            'completed_at' => $checkedAt,
            'created_by' => !empty($data['created_by']) ? (int) $data['created_by'] : null,
            'metadata_json' => ['source' => 'customer_care_check_in'],
        ]);

        Database::execute(
            "UPDATE nurture_profiles
             SET nurture_status = 'active',
                 last_touch_at = ?,
                 next_touch_at = ?,
                 next_touch_reason = ?
             WHERE workspace_id = ? AND contact_id = ?",
            [
                $checkedAt,
                $nextTouchAt,
                $planGuidance !== '' ? substr($planGuidance, 0, 240) : 'Check in again to confirm progress.',
                $this->workspaceId(),
                $contactId,
            ]
        );

        return $this->getProfileForContact($contactId) ?? [];
    }

    public function getTransitionReadinessForContact(int $contactId, bool $createWhenReady = false): array
    {
        $contact = $this->getWorkspaceContact($contactId);
        if (!$contact) {
            return [
                'status' => 'not_ready',
                'label' => 'Needs CRM contact',
                'reason' => 'Customer Care needs a synced CRM contact before the post-purchase handoff can continue.',
                'next_step' => 'Sync the outreach handoff to a CRM contact first.',
                'contact_id' => $contactId,
                'profile' => null,
                'purchase_evidence' => null,
            ];
        }

        $existing = $this->getProfileRow($contactId);
        $purchaseEvidence = $this->resolvePurchaseEvidence($contact);
        $manualProfile = $this->isManualCustomerSuccessProfile($existing);
        $profileStatus = (string) ($existing['nurture_status'] ?? '');

        if ($existing && $profileStatus !== 'exited' && ($purchaseEvidence !== null || $manualProfile)) {
            return [
                'status' => 'active',
                'label' => 'In Customer Care',
                'reason' => 'This customer already has an active post-purchase care profile.',
                'next_step' => 'Open Customer Care and continue the next check-in.',
                'contact_id' => $contactId,
                'profile' => $this->getProfileForContact($contactId),
                'purchase_evidence' => $purchaseEvidence ?? $this->existingPurchaseEvidence($existing),
            ];
        }

        if ($purchaseEvidence !== null) {
            $profile = null;
            $status = 'ready';
            if ($createWhenReady) {
                $profile = $existing ? $this->refreshProfile($contactId) : $this->getOrCreateProfile($contactId);
                $status = 'active';
            }

            return [
                'status' => $status,
                'label' => $status === 'active' ? 'In Customer Care' : 'Ready for Customer Care',
                'reason' => 'Purchase evidence exists, so the outreach handoff can move into customer care.',
                'next_step' => 'Open Customer Care to start the post-purchase care path.',
                'contact_id' => $contactId,
                'profile' => $profile,
                'purchase_evidence' => $purchaseEvidence,
            ];
        }

        return [
            'status' => 'not_ready',
            'label' => 'Needs purchase evidence',
            'reason' => 'This contact is still a lead or sales opportunity; Customer Care starts only after payment or closed-won evidence.',
            'next_step' => 'Close the deal as won or record a paid invoice before moving this handoff into Customer Care.',
            'contact_id' => $contactId,
            'profile' => $existing ? $this->getProfileForContact($contactId) : null,
            'purchase_evidence' => null,
        ];
    }

    public function listPrograms(array $filters = []): array
    {
        $where = ['workspace_id = ?'];
        $params = [$this->workspaceId()];
        if (!empty($filters['status'])) {
            $where[] = 'status = ?';
            $params[] = Security::sanitizeInput((string) $filters['status'], 'string');
        } elseif (!empty($filters['active_only'])) {
            $where[] = "status = 'active'";
        } elseif (!empty($filters['archived_only'])) {
            $where[] = "status = 'archived'";
        } elseif (!empty($filters['exclude_archived'])) {
            $where[] = "status <> 'archived'";
        }

        return Database::query(
            "SELECT * FROM nurture_programs
             WHERE " . implode(' AND ', $where) . "
             ORDER BY FIELD(status, 'active', 'draft', 'paused', 'archived'), name ASC",
            $params
        );
    }

    public function listProgramsWithStats(array $filters = []): array
    {
        $programs = $this->listPrograms($filters);
        if ($programs === []) {
            return [];
        }

        $ids = array_map(static fn(array $program): int => (int) $program['id'], $programs);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $rows = Database::query(
            "SELECT program_id,
                    COUNT(*) AS enrollment_count,
                    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_enrollment_count
             FROM nurture_enrollments
             WHERE workspace_id = ? AND program_id IN ({$placeholders})
             GROUP BY program_id",
            array_merge([$this->workspaceId()], $ids)
        );

        $stats = [];
        foreach ($rows as $row) {
            $stats[(int) $row['program_id']] = [
                'enrollment_count' => (int) ($row['enrollment_count'] ?? 0),
                'active_enrollment_count' => (int) ($row['active_enrollment_count'] ?? 0),
            ];
        }

        foreach ($programs as &$program) {
            $programStats = $stats[(int) $program['id']] ?? [
                'enrollment_count' => 0,
                'active_enrollment_count' => 0,
            ];
            $program['enrollment_count'] = $programStats['enrollment_count'];
            $program['active_enrollment_count'] = $programStats['active_enrollment_count'];
        }
        unset($program);

        return $programs;
    }

    public function createProgram(array $data): int
    {
        $program = $this->normalizeProgramData($data);

        Database::execute(
            "INSERT INTO nurture_programs
             (workspace_id, name, description, program_type, status, cadence, first_touch_delay_days,
              preferred_channel, default_touch_type, touch_guidance, linked_campaign_id, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $this->workspaceId(),
                $program['name'],
                $program['description'],
                $program['program_type'],
                $program['status'],
                $program['cadence'],
                $program['first_touch_delay_days'],
                $program['preferred_channel'],
                $program['default_touch_type'],
                $program['touch_guidance'],
                $program['linked_campaign_id'],
                (int) ($data['created_by'] ?? ($_SESSION['user_id'] ?? 0)) ?: null,
            ]
        );

        return (int) Database::lastInsertId();
    }

    public function updateProgram(int $programId, array $data): array
    {
        $programId = max(0, $programId);
        if ($programId <= 0) {
            throw new \InvalidArgumentException('Follow-up plan was not found.');
        }

        $existing = $this->getProgram($programId);
        $program = $this->normalizeProgramData($data, $existing);

        Database::beginTransaction();
        try {
            Database::execute(
                "UPDATE nurture_programs
                 SET name = ?, description = ?, program_type = ?, status = ?, cadence = ?,
                     first_touch_delay_days = ?, preferred_channel = ?, default_touch_type = ?,
                     touch_guidance = ?, linked_campaign_id = ?
                 WHERE workspace_id = ? AND id = ?",
                [
                    $program['name'],
                    $program['description'],
                    $program['program_type'],
                    $program['status'],
                    $program['cadence'],
                    $program['first_touch_delay_days'],
                    $program['preferred_channel'],
                    $program['default_touch_type'],
                    $program['touch_guidance'],
                    $program['linked_campaign_id'],
                    $this->workspaceId(),
                    $programId,
                ]
            );

            if ($program['status'] === 'archived') {
                $this->exitProgramEnrollments($programId);
            }

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }

        return $this->getProgram($programId);
    }

    public function deleteProgram(int $programId): array
    {
        $programId = max(0, $programId);
        if ($programId <= 0) {
            throw new \InvalidArgumentException('Follow-up plan was not found.');
        }

        $program = $this->getProgram($programId);
        $enrollmentCount = (int) (Database::queryOne(
            "SELECT COUNT(*) AS count
             FROM nurture_enrollments
             WHERE workspace_id = ? AND program_id = ?",
            [$this->workspaceId(), $programId]
        )['count'] ?? 0);

        Database::beginTransaction();
        try {
            if ($enrollmentCount === 0) {
                Database::execute(
                    "DELETE FROM nurture_programs WHERE workspace_id = ? AND id = ?",
                    [$this->workspaceId(), $programId]
                );
                Database::commit();
                return [
                    'mode' => 'deleted',
                    'deleted' => true,
                    'archived' => false,
                    'program_id' => $programId,
                    'program_name' => (string) ($program['name'] ?? ''),
                    'enrollment_count' => 0,
                    'exited_enrollments' => 0,
                ];
            }

            Database::execute(
                "UPDATE nurture_programs
                 SET status = 'archived'
                 WHERE workspace_id = ? AND id = ?",
                [$this->workspaceId(), $programId]
            );
            $exited = $this->exitProgramEnrollments($programId);
            Database::commit();

            return [
                'mode' => 'archived',
                'deleted' => false,
                'archived' => true,
                'program_id' => $programId,
                'program_name' => (string) ($program['name'] ?? ''),
                'enrollment_count' => $enrollmentCount,
                'exited_enrollments' => $exited,
            ];
        } catch (\Throwable $e) {
            Database::rollBack();
            throw $e;
        }
    }

    private function getProgram(int $programId): array
    {
        $program = Database::queryOne(
            "SELECT * FROM nurture_programs WHERE workspace_id = ? AND id = ?",
            [$this->workspaceId(), $programId]
        );
        if (!$program) {
            throw new \RuntimeException('Follow-up plan was not found.');
        }

        return $program;
    }

    private function normalizeProgramData(array $data, array $existing = []): array
    {
        $name = array_key_exists('name', $data)
            ? Security::sanitizeInput((string) ($data['name'] ?? ''), 'string')
            : Security::sanitizeInput((string) ($existing['name'] ?? ''), 'string');
        if ($name === '') {
            throw new \InvalidArgumentException('Program name is required.');
        }

        $programType = (string) ($data['program_type'] ?? ($existing['program_type'] ?? 'customer_success'));
        if (!in_array($programType, self::PROGRAM_TYPES, true)) {
            $programType = 'customer_success';
        }

        $cadence = (string) ($data['cadence'] ?? ($existing['cadence'] ?? 'monthly'));
        if (!in_array($cadence, self::CADENCES, true)) {
            $cadence = 'monthly';
        }

        $status = (string) ($data['status'] ?? ($existing['status'] ?? 'active'));
        if (!in_array($status, self::PROGRAM_STATUSES, true)) {
            $status = 'active';
        }

        $linkedCampaignId = $data['linked_campaign_id'] ?? ($existing['linked_campaign_id'] ?? null);
        $linkedCampaignId = (int) $linkedCampaignId > 0 ? (int) $linkedCampaignId : null;
        $firstTouchDelay = array_key_exists('first_touch_delay_days', $data)
            ? $this->normalizeFirstTouchDelay($data['first_touch_delay_days'])
            : $this->normalizeFirstTouchDelay($existing['first_touch_delay_days'] ?? null);
        $preferredChannel = $this->normalizePlanChannel((string) ($data['preferred_channel'] ?? ($existing['preferred_channel'] ?? 'task')));
        $defaultTouchType = $this->normalizePlanTouchType((string) ($data['default_touch_type'] ?? ($existing['default_touch_type'] ?? 'check_in')));
        $touchGuidance = Security::sanitizeInput((string) ($data['touch_guidance'] ?? ($existing['touch_guidance'] ?? '')), 'string') ?: null;

        return [
            'name' => $name,
            'description' => Security::sanitizeInput((string) ($data['description'] ?? ($existing['description'] ?? '')), 'string') ?: null,
            'program_type' => $programType,
            'cadence' => $cadence,
            'status' => $status,
            'first_touch_delay_days' => $firstTouchDelay,
            'preferred_channel' => $preferredChannel,
            'default_touch_type' => $defaultTouchType,
            'touch_guidance' => $touchGuidance,
            'linked_campaign_id' => $linkedCampaignId,
        ];
    }

    private function normalizeFirstTouchDelay(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return max(0, min(365, (int) $value));
    }

    private function normalizePlanChannel(string $channel): string
    {
        return in_array($channel, self::PLAN_CHANNELS, true) ? $channel : 'task';
    }

    private function normalizePlanTouchType(string $touchType): string
    {
        return in_array($touchType, self::PLAN_TOUCH_TYPES, true) ? $touchType : 'check_in';
    }

    private function exitProgramEnrollments(int $programId): int
    {
        return Database::execute(
            "UPDATE nurture_enrollments
             SET status = 'exited',
                 completed_at = COALESCE(completed_at, NOW()),
                 next_touch_at = NULL,
                 exit_reason = 'Follow-up plan archived'
             WHERE workspace_id = ?
               AND program_id = ?
               AND status IN ('active', 'paused')",
            [$this->workspaceId(), $programId]
        );
    }

    public function enrollContacts(int $programId, array $contactIds): array
    {
        $program = Database::queryOne(
            "SELECT * FROM nurture_programs WHERE workspace_id = ? AND id = ?",
            [$this->workspaceId(), $programId]
        );
        if (!$program) {
            throw new \RuntimeException('Nurture program not found.');
        }
        if ((string) ($program['status'] ?? '') !== 'active') {
            throw new \RuntimeException('Follow-up plan is not available for new enrollments.');
        }

        $created = 0;
        $updated = 0;
        foreach (array_unique(array_map('intval', $contactIds)) as $contactId) {
            if ($contactId <= 0) {
                continue;
            }
            $profile = $this->getOrCreateProfile($contactId);
            $nextTouchAt = $this->initialTouchDateForProgram($program);

            $existing = Database::queryOne(
                "SELECT id FROM nurture_enrollments
                 WHERE workspace_id = ? AND program_id = ? AND contact_id = ?",
                [$this->workspaceId(), $programId, $contactId]
            );
            if ($existing) {
                Database::execute(
                    "UPDATE nurture_enrollments
                     SET status = 'active', next_touch_at = ?, completed_at = NULL, exit_reason = NULL
                     WHERE workspace_id = ? AND id = ?",
                    [$nextTouchAt, $this->workspaceId(), (int) $existing['id']]
                );
                $updated++;
            } else {
                Database::execute(
                    "INSERT INTO nurture_enrollments
                     (workspace_id, profile_id, program_id, contact_id, status, current_step_label, next_touch_at, metadata_json)
                     VALUES (?, ?, ?, ?, 'active', ?, ?, ?)",
                    [
                        $this->workspaceId(),
                        (int) $profile['id'],
                        $programId,
                        $contactId,
                        'Program start',
                        $nextTouchAt,
                        json_encode([
                            'source' => 'nurture_module',
                            'preferred_channel' => (string) ($program['preferred_channel'] ?? 'task'),
                            'default_touch_type' => (string) ($program['default_touch_type'] ?? 'check_in'),
                        ]),
                    ]
                );
                $created++;
            }

            $this->updateProfile($contactId, [
                'cadence' => (string) ($program['cadence'] ?? 'monthly'),
                'nurture_status' => 'active',
                'next_touch_at' => $nextTouchAt,
                'next_touch_reason' => 'Added to follow-up plan: ' . (string) $program['name'],
            ]);
        }

        return ['created' => $created, 'updated' => $updated];
    }

    public function createFollowUpTask(int $contactId, array $data = []): int
    {
        $profile = $this->getOrCreateProfile($contactId);
        $activePlan = $this->getActiveEnrollment($contactId);
        $contactName = trim((string) ($profile['first_name'] ?? '') . ' ' . (string) ($profile['last_name'] ?? ''));
        $title = Security::sanitizeInput((string) ($data['title'] ?? ''), 'string');
        if ($title === '') {
            $title = 'Customer check-in: ' . ($contactName !== '' ? $contactName : ('Contact #' . $contactId));
        }

        $dueDate = $this->normalizeDateTime($data['due_date'] ?? ($profile['next_touch_at'] ?? null));
        if ($dueDate === null) {
            $dueDate = date('Y-m-d H:i:s', strtotime('+2 days'));
        }
        $channel = $this->normalizePlanChannel((string) ($data['channel'] ?? ($activePlan['preferred_channel'] ?? 'task')));
        $touchType = $activePlan
            ? $this->normalizePlanTouchType((string) ($data['touch_type'] ?? ($activePlan['default_touch_type'] ?? 'check_in')))
            : 'manual_task';
        if (!$activePlan && array_key_exists('touch_type', $data)) {
            $requestedTouchType = (string) $data['touch_type'];
            $touchType = in_array($requestedTouchType, self::PLAN_TOUCH_TYPES, true) ? $requestedTouchType : 'manual_task';
        }
        $description = (string) ($data['description'] ?? $this->defaultTaskDescription($profile, $activePlan));

        $taskId = (new Tasks())->create([
            'title' => $title,
            'description' => $description,
            'contact_id' => $contactId,
            'assigned_to' => !empty($profile['owner_user_id']) ? (int) $profile['owner_user_id'] : null,
            'created_by' => (int) ($data['created_by'] ?? ($_SESSION['user_id'] ?? 0)),
            'actor_user_id' => (int) ($data['actor_user_id'] ?? ($_SESSION['user_id'] ?? 0)),
            'priority' => $profile['temperature'] === 'hot' || $profile['lifecycle_lane'] === 'at_risk' ? 'high' : 'medium',
            'due_date' => $dueDate,
            'metadata_json' => [
                'source_surface' => 'nurture',
                'task_intent' => 'customer_relationship_follow_up',
                'nurture_profile_id' => (int) $profile['id'],
                'lifecycle_lane' => (string) $profile['lifecycle_lane'],
                'preferred_channel' => $channel,
                'default_touch_type' => $touchType,
            ],
        ]);

        $this->createTouchpoint([
            'profile_id' => (int) $profile['id'],
            'contact_id' => $contactId,
            'task_id' => $taskId,
            'touch_type' => $touchType,
            'channel' => $channel,
            'status' => 'planned',
            'subject' => $title,
            'notes' => $description,
            'scheduled_at' => $dueDate,
            'created_by' => (int) ($data['created_by'] ?? ($_SESSION['user_id'] ?? 0)) ?: null,
            'metadata_json' => ['source' => 'nurture_follow_up_task'],
        ]);

        $this->updateProfile($contactId, [
            'nurture_status' => 'active',
            'next_touch_at' => $dueDate,
            'next_touch_reason' => 'Follow-up task created',
        ]);

        return $taskId;
    }

    public function createTouchpoint(array $data): int
    {
        Database::execute(
            "INSERT INTO nurture_touchpoints
             (workspace_id, profile_id, contact_id, enrollment_id, task_id, activity_id, campaign_id, touch_type,
              channel, status, subject, notes, scheduled_at, completed_at, created_by, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $this->workspaceId(),
                (int) $data['profile_id'],
                (int) $data['contact_id'],
                !empty($data['enrollment_id']) ? (int) $data['enrollment_id'] : null,
                !empty($data['task_id']) ? (int) $data['task_id'] : null,
                !empty($data['activity_id']) ? (int) $data['activity_id'] : null,
                !empty($data['campaign_id']) ? (int) $data['campaign_id'] : null,
                in_array(($data['touch_type'] ?? 'planned'), ['planned','manual_task','campaign','activity','check_in','renewal','expansion','risk_recovery'], true) ? $data['touch_type'] : 'planned',
                in_array(($data['channel'] ?? 'task'), ['email','phone','whatsapp','sms','meeting','task','note','other'], true) ? $data['channel'] : 'task',
                in_array(($data['status'] ?? 'planned'), ['planned','completed','skipped','cancelled'], true) ? $data['status'] : 'planned',
                Security::sanitizeInput((string) ($data['subject'] ?? 'Nurture touch'), 'string'),
                Security::sanitizeInput((string) ($data['notes'] ?? ''), 'string') ?: null,
                $this->normalizeDateTime($data['scheduled_at'] ?? null),
                $this->normalizeDateTime($data['completed_at'] ?? null),
                !empty($data['created_by']) ? (int) $data['created_by'] : null,
                json_encode($data['metadata_json'] ?? []),
            ]
        );

        return (int) Database::lastInsertId();
    }

    public function getTouchpoints(int $contactId, int $limit = 30): array
    {
        $limit = min(max($limit, 1), 100);
        return Database::query(
            "SELECT nt.*, t.status AS task_status, t.due_date AS task_due_date, a.activity_type, a.description AS activity_description
             FROM nurture_touchpoints nt
             LEFT JOIN tasks t ON t.id = nt.task_id AND t.workspace_id = nt.workspace_id
             LEFT JOIN activities a ON a.id = nt.activity_id AND a.workspace_id = nt.workspace_id
             WHERE nt.workspace_id = ? AND nt.contact_id = ?
             ORDER BY COALESCE(nt.scheduled_at, nt.completed_at, nt.created_at) DESC
             LIMIT {$limit}",
            [$this->workspaceId(), $contactId]
        );
    }

    public function getOpenTasks(int $contactId, int $limit = 10): array
    {
        $limit = min(max($limit, 1), 50);
        return Database::query(
            "SELECT *
             FROM tasks
             WHERE workspace_id = ?
               AND contact_id = ?
               AND status IN ('pending', 'in_progress')
             ORDER BY due_date IS NULL ASC, due_date ASC, created_at DESC
             LIMIT {$limit}",
            [$this->workspaceId(), $contactId]
        );
    }

    public function getActiveEnrollment(int $contactId): ?array
    {
        return Database::queryOne(
            "SELECT ne.*, np.name AS program_name, np.program_type, np.cadence,
                    np.first_touch_delay_days, np.preferred_channel, np.default_touch_type, np.touch_guidance
             FROM nurture_enrollments ne
             JOIN nurture_programs np ON np.id = ne.program_id AND np.workspace_id = ne.workspace_id
             WHERE ne.workspace_id = ?
               AND ne.contact_id = ?
               AND ne.status = 'active'
             ORDER BY ne.updated_at DESC
             LIMIT 1",
            [$this->workspaceId(), $contactId]
        );
    }

    public function getMarketingHandoffLineage(int $contactId, int $limit = 6): array
    {
        if ($contactId <= 0 || !Database::tableExists('marketing_lead_handoffs')) {
            return [];
        }

        $limit = min(max($limit, 1), 25);
        $rows = Database::query(
            "SELECT h.*,
                    assignee.email AS assigned_to_email,
                    camp.name AS campaign_name,
                    lp.title AS landing_page_title,
                    goal.title AS conversion_goal_title,
                    task.title AS task_title
             FROM marketing_lead_handoffs h
             LEFT JOIN users assignee ON assignee.id = h.assigned_to
             LEFT JOIN campaigns camp ON camp.id = h.campaign_id AND camp.workspace_id = h.workspace_id
             LEFT JOIN marketing_landing_pages lp ON lp.id = h.landing_page_id AND lp.workspace_id = h.workspace_id
             LEFT JOIN marketing_conversion_goals goal ON goal.id = h.conversion_goal_id AND goal.workspace_id = h.workspace_id
             LEFT JOIN tasks task ON task.id = h.task_id AND task.workspace_id = h.workspace_id
             WHERE h.workspace_id = ? AND h.contact_id = ?
             ORDER BY COALESCE(h.feedback_at, h.converted_at, h.qualified_at, h.updated_at, h.created_at) DESC
             LIMIT {$limit}",
            [$this->workspaceId(), $contactId]
        );

        return array_map(function (array $row): array {
            $metadata = $this->decodeJson($row['metadata_json'] ?? null);
            $title = trim((string) ($row['campaign_name'] ?? ''));
            if ($title === '') {
                $title = trim((string) ($row['landing_page_title'] ?? ''));
            }
            if ($title === '') {
                $title = trim((string) ($row['conversion_goal_title'] ?? ''));
            }
            if ($title === '') {
                $title = 'Marketing handoff #' . (int) ($row['id'] ?? 0);
            }

            $row['metadata'] = $metadata;
            $row['lineage_title'] = $title;
            $row['lineage_outcome'] = (string) ($row['sales_outcome'] ?? $row['status'] ?? 'new');
            $row['lineage_source'] = (string) ($row['source'] ?? ($metadata['source'] ?? 'marketing_handoff'));

            return $row;
        }, $rows);
    }

    public function buildNurtureSnapshot(array $contact, array $metrics = [], ?array $existingProfile = null, ?array $purchaseEvidence = null): array
    {
        $purchaseEvidence = $purchaseEvidence ?? $this->resolvePurchaseEvidence($contact) ?? $this->existingPurchaseEvidence($existingProfile);
        $lastTouchAt = $metrics['last_touch_at'] ?? null;
        $leadScore = (int) ($contact['lead_score'] ?? 0);
        $engagementScore = (int) ($contact['engagement_score'] ?? 0);
        $score = max($leadScore, $engagementScore);
        $hasWonDeal = (int) ($metrics['won_deals'] ?? 0) > 0;
        $activeDeals = (int) ($metrics['active_deals'] ?? 0);
        $openTasks = (int) ($metrics['open_tasks'] ?? 0);
        $activeCampaigns = (int) ($metrics['active_campaigns'] ?? 0);
        $daysSinceTouch = $this->daysSince($lastTouchAt);

        $metrics['has_purchase_evidence'] = $purchaseEvidence !== null;
        $metrics['entry_at'] = $purchaseEvidence['entry_at'] ?? ($existingProfile['entry_at'] ?? null);

        $lane = $this->classifyLifecycleLane($contact, $metrics);
        $temperature = $this->computeTemperature($score, $daysSinceTouch, $activeDeals, $activeCampaigns, $lane);
        $cadence = $existingProfile['cadence'] ?? $this->defaultCadence($lane, $temperature);
        if (!in_array($cadence, self::CADENCES, true)) {
            $cadence = 'monthly';
        }

        $nextTouchAt = $existingProfile['next_touch_at'] ?? null;
        if (empty($nextTouchAt) || ($existingProfile === null && $cadence !== 'manual')) {
            $nextTouchAt = $this->nextTouchFrom($lastTouchAt, $cadence);
        }

        $isDue = $this->isTouchDue($nextTouchAt);
        $existingStatus = (string) ($existingProfile['nurture_status'] ?? '');
        $status = in_array($existingStatus, ['paused', 'completed'], true)
            ? $existingStatus
            : ($isDue ? 'needs_touch' : 'active');

        $healthScore = $this->computeHealthScore($score, $daysSinceTouch, $openTasks, $activeCampaigns, $hasWonDeal, $lane);
        $signals = [
            'score' => $score,
            'days_since_touch' => $daysSinceTouch,
            'active_deals' => $activeDeals,
            'won_deals' => (int) ($metrics['won_deals'] ?? 0),
            'paid_invoices' => (int) ($metrics['paid_invoices'] ?? 0),
            'open_tasks' => $openTasks,
            'active_campaigns' => $activeCampaigns,
            'is_stale' => $this->isStale($lastTouchAt, $cadence),
            'entry_source' => $purchaseEvidence['source'] ?? ($existingProfile['entry_source'] ?? null),
            'entry_at' => $purchaseEvidence['entry_at'] ?? ($existingProfile['entry_at'] ?? null),
        ];

        return [
            'lifecycle_lane' => $lane,
            'nurture_status' => $status,
            'temperature' => $temperature,
            'cadence' => $cadence,
            'owner_user_id' => !empty($contact['assigned_to']) ? (int) $contact['assigned_to'] : (!empty($contact['created_by']) ? (int) $contact['created_by'] : null),
            'entry_source' => $purchaseEvidence['source'] ?? ($existingProfile['entry_source'] ?? 'manual_customer_success'),
            'entry_reference_id' => $purchaseEvidence['reference_id'] ?? ($existingProfile['entry_reference_id'] ?? null),
            'entry_at' => $purchaseEvidence['entry_at'] ?? ($existingProfile['entry_at'] ?? null),
            'purchase_summary' => $purchaseEvidence['summary'] ?? $this->decodeJson($existingProfile['purchase_summary_json'] ?? null),
            'last_touch_at' => $lastTouchAt,
            'next_touch_at' => $nextTouchAt,
            'next_touch_reason' => $this->nextTouchReason($lane, $temperature, $signals),
            'health_score' => $healthScore,
            'health_signals' => $signals,
            'suggested_touch' => $this->suggestTouch($lane, $temperature, $signals),
        ];
    }

    public function classifyLifecycleLane(array $contact, array $metrics = []): string
    {
        $metadata = $this->decodeContactMetadata($contact['metadata_json'] ?? null);
        if (!empty($metadata['current_paying_customer'])
            && ($metadata['default_workspace_contact_scope'] ?? '') === 'current_paying_customer'
        ) {
            return 'customer_success';
        }

        $score = max((int) ($contact['lead_score'] ?? 0), (int) ($contact['engagement_score'] ?? 0));
        $daysSinceTouch = $this->daysSince($metrics['last_touch_at'] ?? null);
        $hasWonDeal = (int) ($metrics['won_deals'] ?? 0) > 0;
        $activeDeals = (int) ($metrics['active_deals'] ?? 0);
        $hasPurchaseEvidence = $hasWonDeal
            || (int) ($metrics['paid_invoices'] ?? 0) > 0
            || !empty($metrics['has_purchase_evidence']);

        if ($hasPurchaseEvidence) {
            if ($daysSinceTouch !== null && $daysSinceTouch > 60) {
                return 'at_risk';
            }
            if ($activeDeals > 0 || $score >= 70) {
                return 'expansion';
            }
            return 'customer_success';
        }

        return 'inactive';
    }

    public function computeTemperature(int $score, ?int $daysSinceTouch, int $activeDeals = 0, int $activeCampaigns = 0, string $lane = 'customer_success'): string
    {
        if ($lane === 'at_risk') {
            return 'cold';
        }
        if ($score >= 70 || $activeDeals > 0 || ($daysSinceTouch !== null && $daysSinceTouch <= 7)) {
            return 'hot';
        }
        if ($score >= 35 || $activeCampaigns > 0 || ($daysSinceTouch !== null && $daysSinceTouch <= 30)) {
            return 'warm';
        }
        if ($daysSinceTouch !== null && $daysSinceTouch <= 60) {
            return 'cool';
        }
        return 'cold';
    }

    public function isStale(?string $lastTouchAt, string $cadence = 'monthly'): bool
    {
        if ($cadence === 'manual') {
            return false;
        }
        $days = $this->daysSince($lastTouchAt);
        return $days === null || $days > ($this->cadenceDays($cadence) * 2);
    }

    public function cadenceDays(string $cadence): int
    {
        return match ($cadence) {
            'weekly' => 7,
            'biweekly' => 14,
            'quarterly' => 90,
            'manual' => 3650,
            default => 30,
        };
    }

    private function syncWorkspaceProfiles(int $limit): void
    {
        $this->exitIneligibleProfiles();
        $this->reactivateEligibleExitedProfiles($limit);

        $contacts = Database::query(
            "SELECT c.id
             FROM contacts c
             LEFT JOIN nurture_profiles np ON np.contact_id = c.id AND np.workspace_id = c.workspace_id
             WHERE c.workspace_id = ?
               AND np.id IS NULL
               AND " . $this->postPurchaseEligibilitySql('c') . "
             ORDER BY c.updated_at DESC, c.id DESC
             LIMIT " . max(1, $limit),
            [$this->workspaceId()]
        );

        foreach ($contacts as $contact) {
            try {
                $this->getOrCreateProfile((int) $contact['id']);
            } catch (\Throwable $e) {
                error_log('Nurture profile sync failed for contact ' . (int) $contact['id'] . ': ' . $e->getMessage());
            }
        }
    }

    private function buildProfileFilters(array $filters): array
    {
        $where = ['np.workspace_id = ?'];
        $params = [$this->workspaceId()];
        $where[] = $this->postPurchaseEligibilitySql('c', 'np');

        $tab = (string) ($filters['tab'] ?? '');
        if ($tab === 'due_checkins' || $tab === 'needs_touch') {
            $where[] = "(np.nurture_status = 'needs_touch' OR np.next_touch_at IS NULL OR np.next_touch_at <= NOW())";
        } elseif ($tab === 'onboarding') {
            $where[] = "np.lifecycle_lane = 'customer_success'";
            $where[] = "np.entry_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
            $where[] = "np.nurture_status NOT IN ('paused', 'completed', 'exited')";
        } elseif ($tab === 'healthy') {
            $where[] = "np.lifecycle_lane = 'customer_success'";
            $where[] = "np.health_score >= 70";
            $where[] = "np.nurture_status NOT IN ('needs_touch', 'paused', 'completed', 'exited')";
        } elseif ($tab === 'needs_attention' || $tab === 'at_risk') {
            $where[] = "(np.lifecycle_lane = 'at_risk' OR np.health_score < 50 OR np.nurture_status = 'needs_touch')";
        } elseif ($tab === 'expansion') {
            $where[] = "np.lifecycle_lane = 'expansion'";
        } elseif ($tab === 'renewal') {
            $where[] = "(np.next_touch_reason LIKE '%renewal%' OR JSON_UNQUOTE(JSON_EXTRACT(np.purchase_summary_json, '$.status')) IN ('active', 'current'))";
            $where[] = "np.nurture_status NOT IN ('paused', 'completed', 'exited')";
        } elseif ($tab === 'dormant') {
            $where[] = $this->reactivationProfileConditionSql();
            $where[] = "np.nurture_status <> 'completed'";
        } elseif ($tab === 'customers') {
            $where[] = "np.lifecycle_lane IN ('customer_success', 'expansion')";
        }

        if (empty($filters['nurture_status'])) {
            $where[] = "np.nurture_status <> 'exited'";
        }

        if ($tab === 'at_risk') {
            $where[] = "np.lifecycle_lane = 'at_risk'";
        }

        foreach (['lifecycle_lane', 'nurture_status', 'temperature', 'cadence'] as $field) {
            if (!empty($filters[$field])) {
                $where[] = "np.{$field} = ?";
                $params[] = Security::sanitizeInput((string) $filters[$field], 'string');
            }
        }
        $ownerFilter = (string) ($filters['owner_user_id'] ?? '');
        if ($ownerFilter === '__unassigned') {
            $where[] = 'np.owner_user_id IS NULL';
        } elseif ($ownerFilter !== '') {
            $where[] = 'np.owner_user_id = ?';
            $params[] = (int) $ownerFilter;
        }
        if (!empty($filters['program_id'])) {
            $where[] = "EXISTS (
                SELECT 1
                FROM nurture_enrollments ne_filter
                WHERE ne_filter.workspace_id = np.workspace_id
                  AND ne_filter.contact_id = np.contact_id
                  AND ne_filter.status = 'active'
                  AND ne_filter.program_id = ?
            )";
            $params[] = (int) $filters['program_id'];
        }
        if (!empty($filters['stale'])) {
            $where[] = "(np.last_touch_at IS NULL OR np.next_touch_at IS NULL OR np.next_touch_at <= NOW())";
        }
        if (!empty($filters['due_before'])) {
            $dueBefore = $this->normalizeEndOfDayDateTime($filters['due_before']);
            if ($dueBefore !== null) {
                $where[] = 'np.next_touch_at <= ?';
                $params[] = $dueBefore;
            }
        }

        return [$where, $params];
    }

    private function reactivationProfileConditionSql(): string
    {
        return "(np.lifecycle_lane = 'at_risk'
            OR np.health_score < 50
            OR np.nurture_status IN ('paused', 'needs_touch')
            OR (
                np.cadence <> 'manual'
                AND (
                    np.last_touch_at IS NULL
                    OR np.last_touch_at <= DATE_SUB(NOW(), INTERVAL (
                        CASE np.cadence
                            WHEN 'weekly' THEN 14
                            WHEN 'biweekly' THEN 28
                            WHEN 'quarterly' THEN 180
                            ELSE 60
                        END
                    ) DAY)
                )
            )
            OR (
                np.last_touch_at IS NOT NULL
                AND np.last_touch_at <= DATE_SUB(NOW(), INTERVAL 90 DAY)
            ))";
    }

    private function hydrateProfile(array $profile): array
    {
        $profile['health_signals'] = json_decode((string) ($profile['health_signals_json'] ?? '{}'), true) ?: [];
        $profile['suggested_touch'] = json_decode((string) ($profile['suggested_touch_json'] ?? '{}'), true) ?: [];
        $profile['purchase_summary'] = $this->decodeJson($profile['purchase_summary_json'] ?? null);
        $profile['entry_label'] = (string) ($profile['purchase_summary']['label'] ?? $this->entrySourceLabel((string) ($profile['entry_source'] ?? '')));
        $profile['contact_name'] = trim((string) ($profile['first_name'] ?? '') . ' ' . (string) ($profile['last_name'] ?? ''));
        if ($profile['contact_name'] === '') {
            $profile['contact_name'] = (string) ($profile['email'] ?? ('Contact #' . ($profile['contact_id'] ?? '')));
        }
        return $profile;
    }

    private function plainWhyNow(array $profile): string
    {
        $status = (string) ($profile['nurture_status'] ?? '');
        $lane = (string) ($profile['lifecycle_lane'] ?? '');
        $nextTouchAt = (string) ($profile['next_touch_at'] ?? '');
        $lastTouchAt = (string) ($profile['last_touch_at'] ?? '');
        $healthScore = (int) ($profile['health_score'] ?? 100);
        $reason = strtolower((string) ($profile['next_touch_reason'] ?? ''));

        if ($status === 'paused') {
            return 'Paused';
        }
        if ($status === 'needs_touch' || $nextTouchAt === '' || strtotime($nextTouchAt) <= time()) {
            return 'Check-in due';
        }
        if ($lane === 'at_risk' || $healthScore < 50) {
            return 'Needs attention';
        }
        if ($lastTouchAt !== '' && strtotime($lastTouchAt) <= strtotime('-90 days')) {
            return 'Quiet customer';
        }
        if (str_contains($reason, 'renewal')) {
            return 'Renewal coming';
        }
        if ($lane === 'expansion') {
            return 'Growth opportunity';
        }

        return 'Keep in touch';
    }

    private function plainStatusSentence(array $profile, string $whyNow): string
    {
        $name = trim((string) ($profile['contact_name'] ?? 'This customer'));
        if ($name === '') {
            $name = 'This customer';
        }

        return match ($whyNow) {
            'Paused' => $name . ' is paused for now.',
            'Check-in due' => $name . ' needs a check-in today.',
            'Needs attention' => $name . ' may need attention.',
            'Quiet customer' => $name . ' has been quiet for a while.',
            'Renewal coming' => $name . ' has renewal context to review.',
            'Growth opportunity' => $name . ' may be ready for a value conversation.',
            default => $name . ' is in customer care.',
        };
    }

    private function suggestedPlanName(array $profile): string
    {
        $lane = (string) ($profile['lifecycle_lane'] ?? '');
        $whyNow = $this->plainWhyNow($profile);
        $reason = strtolower((string) ($profile['next_touch_reason'] ?? ''));

        if ($whyNow === 'Quiet customer' || $lane === 'at_risk' || $lane === 'inactive') {
            return 'Quiet Customer';
        }
        if (str_contains($reason, 'renewal')) {
            return 'Renewal';
        }
        if ($lane === 'expansion') {
            return 'Quarterly Check-in';
        }

        return 'First 30 Days';
    }

    private function entrySourceLabel(string $source): string
    {
        return match ($source) {
            'deal_closed_won' => 'Closed-won deal',
            'paid_invoice' => 'Paid invoice',
            'workspace_account' => 'Workspace account purchase',
            'manual_customer_success' => 'Manual customer care override',
            default => 'Purchase evidence',
        };
    }

    private function getWorkspaceContact(int $contactId): ?array
    {
        return Database::queryOne(
            "SELECT *
             FROM contacts
             WHERE workspace_id = ? AND id = ?",
            [$this->workspaceId(), $contactId]
        );
    }

    private function getContact(int $contactId): ?array
    {
        $where = ['workspace_id = ?', 'id = ?'];
        $this->appendDefaultWorkspacePaidCustomerFilter($where, '');

        return Database::queryOne(
            "SELECT *
             FROM contacts
             WHERE " . implode(' AND ', $where),
            [$this->workspaceId(), $contactId]
        );
    }

    private function getProfileRow(int $contactId): ?array
    {
        return Database::queryOne(
            "SELECT * FROM nurture_profiles WHERE workspace_id = ? AND contact_id = ?",
            [$this->workspaceId(), $contactId]
        );
    }

    private function loadContactMetrics(int $contactId): array
    {
        $workspaceId = $this->workspaceId();
        $activity = Database::queryOne(
            "SELECT MAX(created_at) AS last_touch_at, COUNT(*) AS activity_count
             FROM activities
             WHERE workspace_id = ? AND contact_id = ?",
            [$workspaceId, $contactId]
        );
        $deals = Database::queryOne(
            "SELECT
                SUM(CASE WHEN stage = 'closed_won' THEN 1 ELSE 0 END) AS won_deals,
                SUM(CASE WHEN stage NOT IN ('closed_won', 'closed_lost') THEN 1 ELSE 0 END) AS active_deals
             FROM deals
             WHERE workspace_id = ? AND contact_id = ?",
            [$workspaceId, $contactId]
        );
        $tasks = Database::queryOne(
            "SELECT COUNT(*) AS open_tasks
             FROM tasks
             WHERE workspace_id = ? AND contact_id = ? AND status IN ('pending', 'in_progress')",
            [$workspaceId, $contactId]
        );
        $campaigns = Database::queryOne(
            "SELECT COUNT(*) AS active_campaigns
             FROM campaign_enrollments
             WHERE workspace_id = ? AND contact_id = ? AND status = 'active'",
            [$workspaceId, $contactId]
        );
        $paidInvoices = Database::queryOne(
            "SELECT COUNT(*) AS paid_invoices
             FROM invoices i
             LEFT JOIN deals d ON d.id = i.deal_id AND d.workspace_id = i.workspace_id
             LEFT JOIN companies co ON co.id = i.company_id AND co.workspace_id = i.workspace_id
             WHERE i.workspace_id = ?
               AND i.document_type = 'invoice'
               AND i.status IN ('paid', 'partially_paid')
               AND COALESCE(i.contact_id, d.contact_id, co.primary_contact_id) = ?",
            [$workspaceId, $contactId]
        );

        return [
            'last_touch_at' => $activity['last_touch_at'] ?? null,
            'activity_count' => (int) ($activity['activity_count'] ?? 0),
            'won_deals' => (int) ($deals['won_deals'] ?? 0),
            'active_deals' => (int) ($deals['active_deals'] ?? 0),
            'open_tasks' => (int) ($tasks['open_tasks'] ?? 0),
            'active_campaigns' => (int) ($campaigns['active_campaigns'] ?? 0),
            'paid_invoices' => (int) ($paidInvoices['paid_invoices'] ?? 0),
        ];
    }

    private function defaultCadence(string $lane, string $temperature): string
    {
        if ($temperature === 'hot' || $lane === 'at_risk') {
            return 'weekly';
        }
        if ($lane === 'customer_success' || $lane === 'expansion') {
            return 'monthly';
        }
        if ($lane === 'inactive') {
            return 'quarterly';
        }
        return $temperature === 'warm' ? 'biweekly' : 'monthly';
    }

    private function nextTouchFrom(?string $lastTouchAt, string $cadence): ?string
    {
        if ($cadence === 'manual') {
            return null;
        }
        $base = $lastTouchAt ? strtotime($lastTouchAt) : time();
        return date('Y-m-d H:i:s', strtotime('+' . $this->cadenceDays($cadence) . ' days', $base));
    }

    private function dateAfterCadence(string $cadence): string
    {
        return date('Y-m-d H:i:s', strtotime('+' . $this->cadenceDays($cadence) . ' days'));
    }

    private function initialTouchDateForProgram(array $program): string
    {
        $firstTouchDelay = $this->normalizeFirstTouchDelay($program['first_touch_delay_days'] ?? null);
        if ($firstTouchDelay !== null) {
            return date('Y-m-d H:i:s', strtotime('+' . $firstTouchDelay . ' days'));
        }

        return $this->dateAfterCadence((string) ($program['cadence'] ?? 'monthly'));
    }

    private function isTouchDue(?string $nextTouchAt): bool
    {
        return empty($nextTouchAt) || strtotime($nextTouchAt) <= time();
    }

    private function computeHealthScore(int $score, ?int $daysSinceTouch, int $openTasks, int $activeCampaigns, bool $hasWonDeal, string $lane): int
    {
        $health = 45 + (int) round($score * 0.35);
        if ($daysSinceTouch === null) {
            $health -= 20;
        } elseif ($daysSinceTouch <= 14) {
            $health += 20;
        } elseif ($daysSinceTouch > 60) {
            $health -= 25;
        }
        if ($openTasks > 0) {
            $health += 5;
        }
        if ($activeCampaigns > 0) {
            $health += 5;
        }
        if ($hasWonDeal) {
            $health += 5;
        }
        if ($lane === 'at_risk' || $lane === 'inactive') {
            $health -= 15;
        }
        return max(0, min(100, $health));
    }

    private function nextTouchReason(string $lane, string $temperature, array $signals): string
    {
        if ($lane === 'customer_success') {
            return 'Customer relationship check-in: confirm outcome progress and blockers.';
        }
        if ($lane === 'expansion') {
            return 'Growth signal: explore what has changed since the purchase and where more value is needed.';
        }
        if ($lane === 'at_risk') {
            return 'Relationship risk: re-open the conversation and look for unresolved value gaps.';
        }
        if ($lane === 'inactive') {
            return 'Reactivation review: decide whether to re-open care or archive the customer relationship.';
        }
        return 'Maintain the customer relationship with a useful check-in tied to their purchased outcome.';
    }

    private function decodeContactMetadata(mixed $metadata): array
    {
        if (is_array($metadata)) {
            return $metadata;
        }
        if (!is_string($metadata) || trim($metadata) === '') {
            return [];
        }
        $decoded = json_decode($metadata, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function suggestTouch(string $lane, string $temperature, array $signals): array
    {
        $action = match ($lane) {
            'customer_success' => 'Check whether the customer is getting the promised outcome.',
            'expansion' => 'Ask what has changed since the purchase and where more value would help.',
            'at_risk' => 'Acknowledge the quiet period and ask what would make the relationship useful again.',
            'inactive' => 'Review whether this customer should be reactivated or archived.',
            default => 'Create a useful customer check-in tied to the original purchase.',
        };

        return [
            'angle' => $action,
            'tone' => $temperature === 'hot' ? 'timely and specific' : 'helpful and low pressure',
            'guardrail' => 'Create a task or draft first; do not auto-send customer-facing messages.',
        ];
    }

    private function defaultTaskDescription(array $profile, ?array $activePlan = null): string
    {
        $guidance = trim((string) ($activePlan['touch_guidance'] ?? ''));
        $suggested = $profile['suggested_touch']['angle'] ?? '';
        $reason = (string) ($profile['next_touch_reason'] ?? '');
        if ($guidance !== '') {
            return trim($reason . "\n\nPlan guidance: " . $guidance);
        }

        return trim($reason . "\n\nSuggested angle: " . $suggested);
    }

    private function daysSince(?string $date): ?int
    {
        if (empty($date)) {
            return null;
        }
        $timestamp = strtotime($date);
        if ($timestamp === false) {
            return null;
        }
        return max(0, (int) floor((time() - $timestamp) / 86400));
    }

    private function normalizeDateTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $timestamp = strtotime((string) $value);
        return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
    }

    private function normalizeEndOfDayDateTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $raw = trim((string) $value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1) {
            $timestamp = strtotime($raw . ' 23:59:59');
            return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
        }

        return $this->normalizeDateTime($raw);
    }

    private function resolvePurchaseEvidence(array $contact): ?array
    {
        $metadataEvidence = $this->workspaceAccountEvidence($contact);
        if ($metadataEvidence !== null) {
            return $metadataEvidence;
        }

        $invoiceEvidence = $this->paidInvoiceEvidence((int) ($contact['id'] ?? 0));
        if ($invoiceEvidence !== null) {
            return $invoiceEvidence;
        }

        return $this->closedWonDealEvidence((int) ($contact['id'] ?? 0));
    }

    private function workspaceAccountEvidence(array $contact): ?array
    {
        $metadata = $this->decodeContactMetadata($contact['metadata_json'] ?? null);
        if (empty($metadata['current_paying_customer'])) {
            return null;
        }

        $paymentState = is_array($metadata['subscription_payment_state'] ?? null)
            ? $metadata['subscription_payment_state']
            : [];
        $entryAt = $paymentState['last_paid_at'] ?? $metadata['last_synced_at'] ?? null;

        return [
            'source' => 'workspace_account',
            'reference_id' => !empty($metadata['owner_workspace_id']) ? (int) $metadata['owner_workspace_id'] : null,
            'entry_at' => $this->normalizeDateTime($entryAt) ?? date('Y-m-d H:i:s'),
            'summary' => [
                'source' => 'workspace_account',
                'label' => 'Workspace account purchase',
                'title' => (string) ($metadata['owner_workspace_name'] ?? $contact['company'] ?? 'Workspace account'),
                'status' => (string) ($metadata['owner_workspace_plan_status'] ?? ''),
                'workspace_id' => !empty($metadata['owner_workspace_id']) ? (int) $metadata['owner_workspace_id'] : null,
            ],
        ];
    }

    private function paidInvoiceEvidence(int $contactId): ?array
    {
        if ($contactId <= 0) {
            return null;
        }

        $invoice = Database::queryOne(
            "SELECT i.id, i.invoice_number, i.title, i.status, i.currency, i.grand_total,
                    COALESCE(i.paid_at, i.finalized_at, i.updated_at, i.created_at) AS entry_at
             FROM invoices i
             LEFT JOIN deals d ON d.id = i.deal_id AND d.workspace_id = i.workspace_id
             LEFT JOIN companies co ON co.id = i.company_id AND co.workspace_id = i.workspace_id
             WHERE i.workspace_id = ?
               AND i.document_type = 'invoice'
               AND i.status IN ('paid', 'partially_paid')
               AND COALESCE(i.contact_id, d.contact_id, co.primary_contact_id) = ?
             ORDER BY COALESCE(i.paid_at, i.finalized_at, i.updated_at, i.created_at) DESC, i.id DESC
             LIMIT 1",
            [$this->workspaceId(), $contactId]
        );

        if (!$invoice) {
            return null;
        }

        return [
            'source' => 'paid_invoice',
            'reference_id' => (int) $invoice['id'],
            'entry_at' => $this->normalizeDateTime($invoice['entry_at'] ?? null) ?? date('Y-m-d H:i:s'),
            'summary' => [
                'source' => 'paid_invoice',
                'label' => 'Paid invoice',
                'title' => (string) ($invoice['title'] ?? 'Invoice'),
                'number' => (string) ($invoice['invoice_number'] ?? ''),
                'amount' => (float) ($invoice['grand_total'] ?? 0),
                'currency' => (string) ($invoice['currency'] ?? ''),
                'status' => (string) ($invoice['status'] ?? ''),
            ],
        ];
    }

    private function closedWonDealEvidence(int $contactId): ?array
    {
        if ($contactId <= 0) {
            return null;
        }

        $deal = Database::queryOne(
            "SELECT id, title, value, currency, actual_close_date, updated_at, created_at
             FROM deals
             WHERE workspace_id = ?
               AND contact_id = ?
               AND stage = 'closed_won'
             ORDER BY COALESCE(actual_close_date, DATE(updated_at), DATE(created_at)) DESC, id DESC
             LIMIT 1",
            [$this->workspaceId(), $contactId]
        );

        if (!$deal) {
            return null;
        }

        return [
            'source' => 'deal_closed_won',
            'reference_id' => (int) $deal['id'],
            'entry_at' => $this->normalizeDateTime($deal['actual_close_date'] ?? $deal['updated_at'] ?? $deal['created_at'] ?? null) ?? date('Y-m-d H:i:s'),
            'summary' => [
                'source' => 'deal_closed_won',
                'label' => 'Closed-won deal',
                'title' => (string) ($deal['title'] ?? 'Closed deal'),
                'amount' => (float) ($deal['value'] ?? 0),
                'currency' => (string) ($deal['currency'] ?? ''),
            ],
        ];
    }

    private function existingPurchaseEvidence(?array $existingProfile): ?array
    {
        if (!$existingProfile || empty($existingProfile['entry_source'])) {
            return null;
        }

        return [
            'source' => (string) $existingProfile['entry_source'],
            'reference_id' => !empty($existingProfile['entry_reference_id']) ? (int) $existingProfile['entry_reference_id'] : null,
            'entry_at' => $existingProfile['entry_at'] ?? null,
            'summary' => $this->decodeJson($existingProfile['purchase_summary_json'] ?? null),
        ];
    }

    private function isManualCustomerSuccessProfile(?array $profile): bool
    {
        return is_array($profile) && ($profile['entry_source'] ?? '') === 'manual_customer_success';
    }

    private function exitProfile(int $contactId, string $reason): void
    {
        Database::execute(
            "UPDATE nurture_profiles
             SET nurture_status = 'exited', next_touch_reason = ?
             WHERE workspace_id = ? AND contact_id = ?",
            [$reason, $this->workspaceId(), $contactId]
        );
    }

    private function exitIneligibleProfiles(): void
    {
        Database::execute(
            "UPDATE nurture_profiles np
             JOIN contacts c ON c.id = np.contact_id AND c.workspace_id = np.workspace_id
             SET np.nurture_status = 'exited',
                 np.next_touch_reason = 'Exited because no payment or purchase evidence was found.'
             WHERE np.workspace_id = ?
               AND np.nurture_status NOT IN ('completed', 'exited')
               AND NOT (" . $this->postPurchaseEligibilitySql('c', 'np') . ")",
            [$this->workspaceId()]
        );
    }

    private function reactivateEligibleExitedProfiles(int $limit): void
    {
        $contacts = Database::query(
            "SELECT np.contact_id
             FROM nurture_profiles np
             JOIN contacts c ON c.id = np.contact_id AND c.workspace_id = np.workspace_id
             WHERE np.workspace_id = ?
               AND np.nurture_status = 'exited'
               AND " . $this->postPurchaseEligibilitySql('c', 'np') . "
             ORDER BY np.updated_at DESC, np.id DESC
             LIMIT " . max(1, $limit),
            [$this->workspaceId()]
        );

        foreach ($contacts as $contact) {
            try {
                $this->refreshProfile((int) $contact['contact_id']);
            } catch (\Throwable $e) {
                error_log('Nurture profile reactivation failed for contact ' . (int) $contact['contact_id'] . ': ' . $e->getMessage());
            }
        }
    }

    private function postPurchaseEligibilitySql(string $contactAlias, ?string $profileAlias = null): string
    {
        if (WorkspaceContext::isDefaultWorkspace($this->workspaceId())) {
            return $this->defaultWorkspacePaidCustomerCondition($contactAlias);
        }

        $contactPrefix = trim($contactAlias) !== '' ? trim($contactAlias) . '.' : '';
        $manualProfile = '';
        if ($profileAlias !== null && trim($profileAlias) !== '') {
            $profilePrefix = trim($profileAlias) . '.';
            $manualProfile = "{$profilePrefix}entry_source = 'manual_customer_success' OR ";
        }

        return "({$manualProfile}" . $this->workspaceAccountMetadataCondition($contactAlias) . "
            OR EXISTS (
                SELECT 1
                FROM deals d
                WHERE d.workspace_id = {$contactPrefix}workspace_id
                  AND d.contact_id = {$contactPrefix}id
                  AND d.stage = 'closed_won'
            )
            OR EXISTS (
                SELECT 1
                FROM invoices i
                LEFT JOIN deals d2 ON d2.id = i.deal_id AND d2.workspace_id = i.workspace_id
                LEFT JOIN companies co ON co.id = i.company_id AND co.workspace_id = i.workspace_id
                WHERE i.workspace_id = {$contactPrefix}workspace_id
                  AND i.document_type = 'invoice'
                  AND i.status IN ('paid', 'partially_paid')
                  AND COALESCE(i.contact_id, d2.contact_id, co.primary_contact_id) = {$contactPrefix}id
            ))";
    }

    private function workspaceAccountMetadataCondition(string $alias): string
    {
        $prefix = trim($alias) !== '' ? trim($alias) . '.' : '';
        return "{$prefix}metadata_json IS NOT NULL
            AND JSON_UNQUOTE(JSON_EXTRACT({$prefix}metadata_json, '$.current_paying_customer')) = 'true'";
    }

    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<int,string> $where
     */
    private function appendDefaultWorkspacePaidCustomerFilter(array &$where, string $alias): void
    {
        $sql = trim($this->defaultWorkspacePaidCustomerSql($alias));
        if ($sql !== '') {
            $where[] = preg_replace('/^\s*AND\s+/i', '', $sql) ?? $sql;
        }
    }

    private function defaultWorkspacePaidCustomerSql(string $alias): string
    {
        if (!WorkspaceContext::isDefaultWorkspace($this->workspaceId())) {
            return '';
        }

        return ' AND ' . $this->defaultWorkspacePaidCustomerCondition($alias);
    }

    private function defaultWorkspacePaidCustomerCondition(string $alias): string
    {
        $prefix = trim($alias) !== '' ? trim($alias) . '.' : '';
        $metadataCondition = "{$prefix}metadata_json IS NOT NULL
               AND JSON_UNQUOTE(JSON_EXTRACT({$prefix}metadata_json, '$.source')) = 'default_workspace_owner_contact'
               AND JSON_UNQUOTE(JSON_EXTRACT({$prefix}metadata_json, '$.default_workspace_contact_scope')) = 'current_paying_customer'
               AND JSON_UNQUOTE(JSON_EXTRACT({$prefix}metadata_json, '$.current_paying_customer')) = 'true'";

        if (!Database::tableExists('default_workspace_owner_contacts')) {
            return $metadataCondition;
        }

        return "(($metadataCondition)
               OR EXISTS (
                   SELECT 1
                   FROM default_workspace_owner_contacts dwoc
                   WHERE dwoc.default_workspace_id = {$prefix}workspace_id
                     AND dwoc.contact_id = {$prefix}id
                     AND dwoc.relationship_status = 'active'
                     AND dwoc.customer_state = 'current_paying_customer'
               ))";
    }

    private function workspaceId(): int
    {
        return $this->workspaceScope->requireActiveWorkspaceId();
    }
}
