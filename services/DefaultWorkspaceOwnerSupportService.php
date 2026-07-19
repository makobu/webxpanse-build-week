<?php

namespace CRM\Services;

use CRM\Authorization;
use CRM\Database;

class DefaultWorkspaceOwnerSupportService
{
    private const CHANNEL = 'web_chat';
    private const SIGNAL_TYPE = 'owner_support_request';
    private const CATEGORIES = ['setup', 'billing', 'technical', 'account', 'other'];
    private const STATUSES = ['open', 'in_progress', 'waiting_on_owner', 'waiting_on_provider', 'resolved', 'dismissed'];
    private const PRIORITIES = ['low', 'medium', 'high', 'urgent'];
    private const HELP_LANES = ['system_error', 'billing_access', 'account_access', 'setup_help', 'installation_help', 'strategy_mentor', 'account_manager'];
    private const COMMERCIAL_TYPES = ['free', 'paid_setup', 'paid_expert'];
    private const PRICING_STATES = ['free', 'quote_required', 'quoted', 'accepted', 'manual_payment_pending', 'not_applicable'];
    private const LIFECYCLE_STATUSES = ['new', 'triaged', 'quoted', 'waiting_on_owner', 'assigned', 'in_progress', 'resolved', 'cancelled'];

    private DefaultWorkspaceService $defaultWorkspace;
    private OperatorAuditService $audit;

    public function __construct(?DefaultWorkspaceService $defaultWorkspace = null, ?OperatorAuditService $audit = null)
    {
        $this->defaultWorkspace = $defaultWorkspace ?: new DefaultWorkspaceService();
        $this->audit = $audit ?: new OperatorAuditService();
    }

    /**
     * @return array<string,mixed>
     */
    public function createCase(int $ownerWorkspaceId, int $ownerUserId, string $category, string $subject, string $message, array $helpDetails = [], ?string $helpLane = null): array
    {
        $this->assertSchemaReady();
        $this->assertOwnerAccess($ownerWorkspaceId, $ownerUserId);
        $category = $this->normalizeCategory($category);
        $lane = $helpLane !== null ? $this->normalizeLane($helpLane) : $this->laneForLegacyCategory($category);
        $subject = $this->cleanSubject($subject);
        $message = $this->cleanMessage($message);
        if ($subject === '' || $message === '') {
            throw new \RuntimeException('Subject and message are required.');
        }

        $context = $this->ensureOwnerContact($ownerWorkspaceId, $ownerUserId);
        $defaultWorkspaceId = (int) ($context['default_workspace_id'] ?? 0);
        $contactId = (int) ($context['contact_id'] ?? 0);

        Database::beginTransaction();
        try {
            $fingerprint = 'owner_support:' . $ownerWorkspaceId . ':' . $ownerUserId . ':' . bin2hex(random_bytes(8));
            Database::execute(
                "INSERT INTO default_workspace_ops_events
                    (default_workspace_id, owner_workspace_id, owner_user_id, contact_id, signal_type, signal_fingerprint,
                     active_signal_key, severity, priority, status, owner_visible, owner_subject, owner_category,
                     detected_at, last_seen_at, last_owner_message_at, metadata_json)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'warning', ?, 'open', 1, ?, ?, NOW(), NOW(), NOW(), ?)",
                [
                    $defaultWorkspaceId,
                    $ownerWorkspaceId,
                    $ownerUserId,
                    $contactId,
                    self::SIGNAL_TYPE,
                    $fingerprint,
                    $this->activeSignalKey(self::SIGNAL_TYPE, $fingerprint),
                    $this->initialPriority($category),
                    $subject,
                    $category,
                    json_encode([
                        'source' => 'owner_support_center',
                        'created_by_owner_user_id' => $ownerUserId,
                        'owner_support' => true,
                        'category' => $category,
                    ], JSON_UNESCAPED_SLASHES),
                ]
            );
            $eventId = (int) Database::lastInsertId();
            $thread = $this->createThread($defaultWorkspaceId, $contactId, $eventId, null, 'open');
            $communicationId = $this->insertCommunication(
                $defaultWorkspaceId,
                $contactId,
                $thread['thread_key'],
                'inbound',
                $subject,
                $message,
                [
                    'source' => 'owner_support_center',
                    'ops_event_id' => $eventId,
                    'sender_user_id' => $ownerUserId,
                    'sender_role' => 'owner',
                ]
            );
            Database::execute(
                "UPDATE default_workspace_ops_events
                 SET conversation_thread_id = ?
                 WHERE id = ? AND default_workspace_id = ?",
                [(int) $thread['id'], $eventId, $defaultWorkspaceId]
            );
            Database::commit();
        } catch (\Throwable $e) {
            if (Database::getInstance()->inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }

        $case = $this->ownerCase($ownerWorkspaceId, $ownerUserId, $eventId);
        $this->upsertHelpRequestForCase($case, $lane, array_merge(['owner_goal' => $message], $helpDetails));
        $case = $this->ownerCase($ownerWorkspaceId, $ownerUserId, $eventId);
        $this->notifyPlatformOperators($defaultWorkspaceId, $eventId, 'Owner support case opened', $subject);

        return $case + ['communication_id' => $communicationId];
    }

    /**
     * @param array<string,mixed> $details
     * @return array<string,mixed>
     */
    public function createHelpRequest(int $ownerWorkspaceId, int $ownerUserId, string $lane, string $subject, string $message, array $details = []): array
    {
        $lane = $this->normalizeLane($lane);
        return $this->createCase($ownerWorkspaceId, $ownerUserId, $this->categoryForLane($lane), $subject, $message, $details, $lane);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listOwnerCases(int $ownerWorkspaceId, int $ownerUserId): array
    {
        $this->assertOwnerAccess($ownerWorkspaceId, $ownerUserId);
        if (!Database::tableExists('default_workspace_ops_events') || !Database::columnExists('default_workspace_ops_events', 'owner_visible')) {
            return [];
        }
        $helpSelect = $this->helpRequestSelectSql();
        $helpJoin = $this->helpRequestJoinSql();

        return array_map(fn(array $row): array => $this->normalizeCaseRow($row), Database::query(
            "SELECT e.*, w.name AS owner_workspace_name, c.email AS contact_email{$helpSelect}
             FROM default_workspace_ops_events e
             LEFT JOIN workspaces w ON w.id = e.owner_workspace_id
             LEFT JOIN contacts c ON c.id = e.contact_id AND c.workspace_id = e.default_workspace_id
             {$helpJoin}
             WHERE e.owner_workspace_id = ?
               AND e.owner_visible = 1
             ORDER BY FIELD(e.status, 'open', 'in_progress', 'waiting_on_owner', 'waiting_on_provider', 'resolved', 'dismissed'),
                      e.last_seen_at DESC,
                      e.id DESC",
            [$ownerWorkspaceId]
        ));
    }

    /**
     * @return array<string,mixed>
     */
    public function ownerCase(int $ownerWorkspaceId, int $ownerUserId, int $eventId): array
    {
        $this->assertOwnerAccess($ownerWorkspaceId, $ownerUserId);
        $case = $this->caseRow($eventId);
        if (!$case || (int) ($case['owner_workspace_id'] ?? 0) !== $ownerWorkspaceId || empty($case['owner_visible'])) {
            throw new \RuntimeException('Support case was not found.');
        }

        return $this->normalizeCaseRow($case);
    }

    /**
     * @return array<string,mixed>
     */
    public function addOwnerReply(int $ownerWorkspaceId, int $ownerUserId, int $eventId, string $message): array
    {
        $this->assertOwnerAccess($ownerWorkspaceId, $ownerUserId);
        $message = $this->cleanMessage($message);
        if ($message === '') {
            throw new \RuntimeException('Message is required.');
        }

        $case = $this->ownerCase($ownerWorkspaceId, $ownerUserId, $eventId);
        $defaultWorkspaceId = (int) ($case['default_workspace_id'] ?? $this->defaultWorkspace->id());
        $contactId = (int) ($case['contact_id'] ?? 0);
        $thread = $this->ensureThreadForCase($case);

        Database::beginTransaction();
        try {
            $communicationId = $this->insertCommunication(
                $defaultWorkspaceId,
                $contactId,
                (string) $thread['thread_key'],
                'inbound',
                (string) ($case['owner_subject'] ?? 'Owner support request'),
                $message,
                [
                    'source' => 'owner_support_center',
                    'ops_event_id' => $eventId,
                    'sender_user_id' => $ownerUserId,
                    'sender_role' => 'owner',
                ]
            );
            $nextStatus = in_array((string) ($case['status'] ?? ''), ['resolved', 'dismissed', 'waiting_on_owner'], true)
                ? 'open'
                : (string) ($case['status'] ?? 'open');
            Database::execute(
                "UPDATE default_workspace_ops_events
                 SET status = ?,
                     active_signal_key = CASE WHEN ? IN ('resolved','dismissed') THEN ? ELSE active_signal_key END,
                     resolved_at = CASE WHEN ? IN ('resolved','dismissed') THEN NULL ELSE resolved_at END,
                     last_owner_message_at = NOW(),
                     last_seen_at = NOW(),
                     conversation_thread_id = ?,
                     updated_at = NOW()
                 WHERE id = ? AND default_workspace_id = ?",
                [
                    $nextStatus,
                    (string) ($case['status'] ?? ''),
                    $this->activeSignalKey((string) ($case['signal_type'] ?? self::SIGNAL_TYPE), (string) ($case['signal_fingerprint'] ?? 'owner_support:' . $eventId)),
                    (string) ($case['status'] ?? ''),
                    (int) $thread['id'],
                    $eventId,
                    $defaultWorkspaceId,
                ]
            );
            if ($this->helpSchemaReady()) {
                Database::execute(
                    "UPDATE owner_help_service_requests
                     SET lifecycle_status = 'new', updated_at = NOW()
                     WHERE ops_event_id = ?",
                    [$eventId]
                );
            }
            $this->touchThread((int) $thread['id'], $defaultWorkspaceId, 'open', true);
            Database::commit();
        } catch (\Throwable $e) {
            if (Database::getInstance()->inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }

        $this->notifyPlatformOperators($defaultWorkspaceId, $eventId, 'Owner replied to support case', (string) ($case['owner_subject'] ?? 'Owner support request'));

        return $this->ownerCase($ownerWorkspaceId, $ownerUserId, $eventId) + ['communication_id' => $communicationId];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function messagesForOwner(int $ownerWorkspaceId, int $ownerUserId, int $eventId): array
    {
        $case = $this->ownerCase($ownerWorkspaceId, $ownerUserId, $eventId);
        return $this->messagesForCase($case);
    }

    /**
     * @param array<string,mixed>|null $actor
     * @return array<int,array<string,mixed>>
     */
    public function listAdminCases(?array $actor, array $filters = [], int $limit = 50): array
    {
        $this->assertPlatformOperator($actor);
        $defaultWorkspaceId = $this->defaultWorkspace->id();
        $where = ['e.default_workspace_id = ?', 'e.owner_visible = 1'];
        $params = [$defaultWorkspaceId];
        foreach (['status', 'owner_category'] as $key) {
            $value = trim((string) ($filters[$key] ?? ''));
            if ($value !== '') {
                $where[] = "e.{$key} = ?";
                $params[] = $value;
            }
        }
        $helpReady = $this->helpSchemaReady();
        if ($helpReady) {
            foreach (['lane', 'commercial_type', 'pricing_state', 'lifecycle_status'] as $key) {
                $value = trim((string) ($filters[$key] ?? ''));
                if ($value !== '') {
                    $where[] = "r.{$key} = ?";
                    $params[] = $value;
                }
            }
            $expertProfileId = (int) ($filters['expert_profile_id'] ?? 0);
            if ($expertProfileId > 0) {
                $where[] = 'r.expert_profile_id = ?';
                $params[] = $expertProfileId;
            }
        }
        $limit = max(1, min(200, $limit));
        $helpSelect = $this->helpRequestSelectSql();
        $helpJoin = $this->helpRequestJoinSql();

        return array_map(fn(array $row): array => $this->normalizeCaseRow($row), Database::query(
            "SELECT e.*, w.name AS owner_workspace_name, w.slug AS owner_workspace_slug,
                    owner.email AS owner_email, c.first_name AS contact_first_name, c.last_name AS contact_last_name, c.email AS contact_email,
                    assignee.email AS assigned_email{$helpSelect}
             FROM default_workspace_ops_events e
             LEFT JOIN workspaces w ON w.id = e.owner_workspace_id
             LEFT JOIN users owner ON owner.id = e.owner_user_id
             LEFT JOIN contacts c ON c.id = e.contact_id AND c.workspace_id = e.default_workspace_id
             LEFT JOIN users assignee ON assignee.id = e.assigned_user_id
             {$helpJoin}
             WHERE " . implode(' AND ', $where) . "
             ORDER BY FIELD(e.status, 'open', 'in_progress', 'waiting_on_owner', 'waiting_on_provider', 'resolved', 'dismissed'),
                      FIELD(e.priority, 'urgent', 'high', 'medium', 'low'),
                      e.last_seen_at DESC,
                      e.id DESC
             LIMIT {$limit}",
            $params
        ));
    }

    /**
     * @param array<string,mixed>|null $actor
     * @return array<string,mixed>
     */
    public function adminCase(?array $actor, int $eventId): array
    {
        $this->assertPlatformOperator($actor);
        $case = $this->caseRow($eventId);
        if (!$case || empty($case['owner_visible'])) {
            throw new \RuntimeException('Support case was not found.');
        }
        return $this->normalizeCaseRow($case);
    }

    /**
     * @param array<string,mixed>|null $actor
     * @return array<int,array<string,mixed>>
     */
    public function messagesForAdmin(?array $actor, int $eventId): array
    {
        $case = $this->adminCase($actor, $eventId);
        return $this->messagesForCase($case);
    }

    /**
     * @param array<string,mixed>|null $actor
     * @return array<string,mixed>
     */
    public function addOperatorReply(?array $actor, int $eventId, string $message): array
    {
        $this->assertPlatformOperator($actor);
        $actorUserId = (int) ($actor['id'] ?? 0);
        $message = $this->cleanMessage($message);
        if ($message === '') {
            throw new \RuntimeException('Message is required.');
        }

        $case = $this->adminCase($actor, $eventId);
        $defaultWorkspaceId = (int) ($case['default_workspace_id'] ?? $this->defaultWorkspace->id());
        $contactId = (int) ($case['contact_id'] ?? 0);
        $thread = $this->ensureThreadForCase($case, $actorUserId);

        Database::beginTransaction();
        try {
            $communicationId = $this->insertCommunication(
                $defaultWorkspaceId,
                $contactId,
                (string) $thread['thread_key'],
                'outbound',
                (string) ($case['owner_subject'] ?? 'Owner support update'),
                $message,
                [
                    'source' => 'owner_support_center',
                    'ops_event_id' => $eventId,
                    'sender_user_id' => $actorUserId,
                    'sender_role' => 'operator',
                ]
            );
            Database::execute(
                "UPDATE default_workspace_ops_events
                 SET status = 'waiting_on_owner',
                     assigned_user_id = COALESCE(assigned_user_id, ?),
                     last_operator_message_at = NOW(),
                     last_seen_at = NOW(),
                     conversation_thread_id = ?,
                     resolution_summary = ?,
                     updated_at = NOW()
                 WHERE id = ? AND default_workspace_id = ?",
                [
                    $actorUserId ?: null,
                    (int) $thread['id'],
                    'Operator replied from Owner Support Center.',
                    $eventId,
                    $defaultWorkspaceId,
                ]
            );
            if ($this->helpSchemaReady()) {
                Database::execute(
                    "UPDATE owner_help_service_requests
                     SET lifecycle_status = 'waiting_on_owner', updated_at = NOW()
                     WHERE ops_event_id = ?",
                    [$eventId]
                );
            }
            $this->touchThread((int) $thread['id'], $defaultWorkspaceId, 'waiting_on_customer', false);
            Database::commit();
        } catch (\Throwable $e) {
            if (Database::getInstance()->inTransaction()) {
                Database::rollBack();
            }
            throw $e;
        }

        $this->audit->log('owner_support_operator_replied', $actorUserId, (int) ($case['owner_workspace_id'] ?? 0), 'Operator replied to owner support case.', [
            'ops_event_id' => $eventId,
            'communication_id' => $communicationId,
        ], !empty($case['owner_user_id']) ? (int) $case['owner_user_id'] : null);
        $this->notifyTenantOwners((int) ($case['owner_workspace_id'] ?? 0), $eventId, 'Support replied', (string) ($case['owner_subject'] ?? 'Owner support update'));

        return $this->adminCase($actor, $eventId) + ['communication_id' => $communicationId];
    }

    /**
     * @param array<string,mixed>|null $actor
     */
    public function transitionCase(?array $actor, int $eventId, string $status, string $reason, ?string $resolutionSummary = null): array
    {
        $this->assertPlatformOperator($actor);
        $status = $this->normalizeStatus($status);
        $reason = trim($reason);
        if ($reason === '') {
            throw new \RuntimeException('A reason is required.');
        }
        $case = $this->adminCase($actor, $eventId);
        $activeKey = in_array($status, ['resolved', 'dismissed'], true)
            ? null
            : $this->activeSignalKey((string) ($case['signal_type'] ?? self::SIGNAL_TYPE), (string) ($case['signal_fingerprint'] ?? 'owner_support:' . $eventId));

        Database::execute(
            "UPDATE default_workspace_ops_events
             SET status = ?,
                 active_signal_key = ?,
                 resolved_at = CASE WHEN ? IN ('resolved','dismissed') THEN NOW() ELSE NULL END,
                 resolution_summary = ?,
                 updated_at = NOW()
             WHERE id = ? AND default_workspace_id = ?",
            [
                $status,
                $activeKey,
                $status,
                $resolutionSummary !== null && trim($resolutionSummary) !== '' ? substr(trim($resolutionSummary), 0, 500) : null,
                $eventId,
                $this->defaultWorkspace->id(),
            ]
        );
        if ($this->helpSchemaReady()) {
            Database::execute(
                "UPDATE owner_help_service_requests
                 SET lifecycle_status = ?, updated_at = NOW()
                 WHERE ops_event_id = ?",
                [$this->lifecycleForStatus($status), $eventId]
            );
        }

        $this->audit->log('owner_support_status_changed', (int) ($actor['id'] ?? 0), (int) ($case['owner_workspace_id'] ?? 0), $reason, [
            'ops_event_id' => $eventId,
            'previous_status' => (string) ($case['status'] ?? ''),
            'new_status' => $status,
        ], !empty($case['owner_user_id']) ? (int) $case['owner_user_id'] : null);

        return $this->adminCase($actor, $eventId);
    }

    /**
     * @param array<string,mixed>|null $actor
     */
    public function assignCase(?array $actor, int $eventId, ?int $assignedUserId): array
    {
        $this->assertPlatformOperator($actor);
        $case = $this->adminCase($actor, $eventId);
        if ($assignedUserId !== null && $assignedUserId > 0 && !$this->isDefaultWorkspaceMember($assignedUserId)) {
            throw new \RuntimeException('Assignee must be an active default workspace member.');
        }
        Database::execute(
            "UPDATE default_workspace_ops_events SET assigned_user_id = ?, updated_at = NOW() WHERE id = ? AND default_workspace_id = ?",
            [$assignedUserId && $assignedUserId > 0 ? $assignedUserId : null, $eventId, $this->defaultWorkspace->id()]
        );
        $this->audit->log('owner_support_assigned', (int) ($actor['id'] ?? 0), (int) ($case['owner_workspace_id'] ?? 0), 'Owner support case assignment changed.', [
            'ops_event_id' => $eventId,
            'assigned_user_id' => $assignedUserId,
        ], !empty($case['owner_user_id']) ? (int) $case['owner_user_id'] : null);

        return $this->adminCase($actor, $eventId);
    }

    /**
     * @param array<string,mixed>|null $actor
     */
    public function updatePriority(?array $actor, int $eventId, string $priority): array
    {
        $this->assertPlatformOperator($actor);
        $priority = in_array($priority, self::PRIORITIES, true) ? $priority : 'medium';
        $case = $this->adminCase($actor, $eventId);
        Database::execute(
            "UPDATE default_workspace_ops_events SET priority = ?, updated_at = NOW() WHERE id = ? AND default_workspace_id = ?",
            [$priority, $eventId, $this->defaultWorkspace->id()]
        );
        $this->audit->log('owner_support_priority_changed', (int) ($actor['id'] ?? 0), (int) ($case['owner_workspace_id'] ?? 0), 'Owner support case priority changed.', [
            'ops_event_id' => $eventId,
            'previous_priority' => (string) ($case['priority'] ?? ''),
            'new_priority' => $priority,
        ], !empty($case['owner_user_id']) ? (int) $case['owner_user_id'] : null);

        return $this->adminCase($actor, $eventId);
    }

    /**
     * @param array<string,mixed>|null $actor
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    public function updateHelpRequest(?array $actor, int $eventId, array $data): array
    {
        $this->assertPlatformOperator($actor);
        if (!$this->helpSchemaReady()) {
            throw new \RuntimeException('Owner Help Center schema is missing. Run migrations first.');
        }

        $case = $this->adminCase($actor, $eventId);
        $current = (array) ($case['help_request'] ?? []);
        $lane = $this->normalizeLane((string) ($data['lane'] ?? $current['lane'] ?? $this->laneForLegacyCategory((string) ($case['owner_category'] ?? 'other'))));
        $commercialType = (string) ($data['commercial_type'] ?? '');
        if (!in_array($commercialType, self::COMMERCIAL_TYPES, true)) {
            $commercialType = $this->commercialTypeForLane($lane);
        }
        $pricingState = (string) ($data['pricing_state'] ?? '');
        if (!in_array($pricingState, self::PRICING_STATES, true)) {
            $pricingState = $this->defaultPricingState($commercialType);
        }
        $lifecycleStatus = (string) ($data['lifecycle_status'] ?? $current['lifecycle_status'] ?? 'triaged');
        $lifecycleStatus = in_array($lifecycleStatus, self::LIFECYCLE_STATUSES, true) ? $lifecycleStatus : 'triaged';
        $quoteNotes = $this->cleanOptionalText((string) ($data['quote_notes'] ?? $current['quote_notes'] ?? ''), 10000);
        $adminNotes = $this->cleanOptionalText((string) ($data['admin_notes'] ?? $current['admin_notes'] ?? ''), 10000);
        $offeringId = max(0, (int) ($data['offering_id'] ?? $current['offering_id'] ?? 0));
        $expertProfileId = max(0, (int) ($data['expert_profile_id'] ?? $current['expert_profile_id'] ?? 0));

        if ($commercialType !== 'free' && (string) ($current['commercial_type'] ?? 'free') === 'free' && $quoteNotes === '' && $adminNotes === '') {
            throw new \RuntimeException('Add an owner-visible quote note or admin note before classifying free support as paid help.');
        }
        if ($offeringId > 0 && !$this->offeringExists($offeringId)) {
            throw new \RuntimeException('Selected setup offering was not found.');
        }
        if ($expertProfileId > 0 && !$this->expertProfileExists($expertProfileId)) {
            throw new \RuntimeException('Selected expert profile was not found.');
        }

        Database::execute(
            "INSERT INTO owner_help_service_requests (
                ops_event_id, owner_workspace_id, owner_user_id, lane, commercial_type, pricing_state, lifecycle_status,
                offering_id, expert_profile_id, quote_notes, admin_notes
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                lane = VALUES(lane),
                commercial_type = VALUES(commercial_type),
                pricing_state = VALUES(pricing_state),
                lifecycle_status = VALUES(lifecycle_status),
                offering_id = VALUES(offering_id),
                expert_profile_id = VALUES(expert_profile_id),
                quote_notes = VALUES(quote_notes),
                admin_notes = VALUES(admin_notes),
                updated_at = NOW()",
            [
                $eventId,
                (int) ($case['owner_workspace_id'] ?? 0),
                (int) ($case['owner_user_id'] ?? 0),
                $lane,
                $commercialType,
                $pricingState,
                $lifecycleStatus,
                $offeringId > 0 ? $offeringId : null,
                $expertProfileId > 0 ? $expertProfileId : null,
                $quoteNotes !== '' ? $quoteNotes : null,
                $adminNotes !== '' ? $adminNotes : null,
            ]
        );

        $status = $this->statusForLifecycle($lifecycleStatus);
        if ($status !== '') {
            Database::execute(
                "UPDATE default_workspace_ops_events
                 SET status = ?, updated_at = NOW()
                 WHERE id = ? AND default_workspace_id = ?",
                [$status, $eventId, $this->defaultWorkspace->id()]
            );
        }

        $this->audit->log('owner_help_request_updated', (int) ($actor['id'] ?? 0), (int) ($case['owner_workspace_id'] ?? 0), 'Owner help request triage updated.', [
            'ops_event_id' => $eventId,
            'lane' => $lane,
            'commercial_type' => $commercialType,
            'pricing_state' => $pricingState,
            'lifecycle_status' => $lifecycleStatus,
            'offering_id' => $offeringId,
            'expert_profile_id' => $expertProfileId,
        ], !empty($case['owner_user_id']) ? (int) $case['owner_user_id'] : null);

        $changedPricing = (string) ($current['pricing_state'] ?? '') !== $pricingState;
        $changedExpert = (int) ($current['expert_profile_id'] ?? 0) !== $expertProfileId;
        if ($changedPricing || $changedExpert) {
            $this->notifyTenantOwners((int) ($case['owner_workspace_id'] ?? 0), $eventId, 'Help request updated', (string) ($case['owner_subject'] ?? 'Owner help request'));
        }

        return $this->adminCase($actor, $eventId);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function defaultWorkspaceMembers(): array
    {
        return Database::query(
            "SELECT u.id, u.email, u.first_name, u.last_name, wm.role_slug, wm.is_owner
             FROM workspace_memberships wm
             JOIN users u ON u.id = wm.user_id
             LEFT JOIN user_roles ur ON ur.user_id = u.id
             LEFT JOIN roles global_role ON global_role.id = ur.role_id
             WHERE wm.workspace_id = ?
               AND wm.membership_status = 'active'
               AND (
                    wm.is_owner = 1
                    OR wm.role_slug IN ('superadmin', 'admin')
                    OR global_role.slug = 'superadmin'
               )
             ORDER BY wm.is_owner DESC, FIELD(wm.role_slug, 'superadmin', 'owner', 'admin', 'accountant', 'expert', 'viewer'), u.email ASC",
            [$this->defaultWorkspace->id()]
        );
    }

    private function helpSchemaReady(): bool
    {
        return Database::tableExists('owner_help_service_requests')
            && Database::tableExists('owner_help_offerings')
            && Database::tableExists('owner_help_expert_profiles');
    }

    private function helpRequestSelectSql(): string
    {
        if (!$this->helpSchemaReady()) {
            return '';
        }

        $expertPhotoSelect = Database::columnExists('owner_help_expert_profiles', 'profile_photo_path')
            ? ",\n                    p.profile_photo_path AS help_expert_profile_photo_path"
            : '';
        $expertSlugSelect = Database::columnExists('owner_help_expert_profiles', 'public_slug')
            ? ",\n                    p.public_slug AS help_expert_public_slug"
            : '';

        return ", r.id AS help_request_id,
                    r.lane AS help_lane,
                    r.commercial_type AS help_commercial_type,
                    r.pricing_state AS help_pricing_state,
                    r.lifecycle_status AS help_lifecycle_status,
                    r.offering_id AS help_offering_id,
                    r.expert_profile_id AS help_expert_profile_id,
                    r.owner_goal AS help_owner_goal,
                    r.preferred_contact_method AS help_preferred_contact_method,
                    r.preferred_time AS help_preferred_time,
                    r.quote_notes AS help_quote_notes,
                    r.admin_notes AS help_admin_notes,
                    o.label AS help_offering_label,
                    o.pricing_label AS help_offering_pricing_label,
                    p.role_label AS help_expert_role_label,
                    p.headline AS help_expert_headline,
                    expert_user.email AS help_expert_email,
                    expert_user.first_name AS help_expert_first_name,
                    expert_user.last_name AS help_expert_last_name{$expertPhotoSelect}{$expertSlugSelect}";
    }

    private function helpRequestJoinSql(): string
    {
        if (!$this->helpSchemaReady()) {
            return '';
        }

        return "LEFT JOIN owner_help_service_requests r ON r.ops_event_id = e.id
             LEFT JOIN owner_help_offerings o ON o.id = r.offering_id
             LEFT JOIN owner_help_expert_profiles p ON p.id = r.expert_profile_id
             LEFT JOIN users expert_user ON expert_user.id = p.user_id";
    }

    /**
     * @param array<string,mixed> $case
     * @param array<string,mixed> $details
     */
    private function upsertHelpRequestForCase(array $case, string $lane, array $details = []): void
    {
        if (!$this->helpSchemaReady()) {
            return;
        }

        $eventId = (int) ($case['id'] ?? 0);
        $ownerWorkspaceId = (int) ($case['owner_workspace_id'] ?? 0);
        $ownerUserId = (int) ($case['owner_user_id'] ?? 0);
        if ($eventId <= 0 || $ownerWorkspaceId <= 0 || $ownerUserId <= 0) {
            return;
        }

        $lane = $this->normalizeLane($lane);
        $commercialType = $this->commercialTypeForLane($lane);
        $pricingState = $this->defaultPricingState($commercialType);
        $offeringId = max(0, (int) ($details['offering_id'] ?? 0));
        $expertProfileId = max(0, (int) ($details['expert_profile_id'] ?? 0));
        $ownerGoal = $this->cleanOptionalText((string) ($details['owner_goal'] ?? ''), 10000);
        $preferredContact = $this->cleanOptionalText((string) ($details['preferred_contact_method'] ?? ''), 80);
        $preferredTime = $this->cleanOptionalText((string) ($details['preferred_time'] ?? ''), 160);

        if ($offeringId > 0 && !$this->offeringExists($offeringId)) {
            $offeringId = 0;
        }
        if ($expertProfileId > 0 && !$this->expertProfileExists($expertProfileId)) {
            $expertProfileId = 0;
        }

        Database::execute(
            "INSERT INTO owner_help_service_requests (
                ops_event_id, owner_workspace_id, owner_user_id, lane, commercial_type, pricing_state, lifecycle_status,
                offering_id, expert_profile_id, owner_goal, preferred_contact_method, preferred_time
             ) VALUES (?, ?, ?, ?, ?, ?, 'new', ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                lane = VALUES(lane),
                commercial_type = VALUES(commercial_type),
                pricing_state = VALUES(pricing_state),
                offering_id = VALUES(offering_id),
                expert_profile_id = VALUES(expert_profile_id),
                owner_goal = VALUES(owner_goal),
                preferred_contact_method = VALUES(preferred_contact_method),
                preferred_time = VALUES(preferred_time),
                updated_at = NOW()",
            [
                $eventId,
                $ownerWorkspaceId,
                $ownerUserId,
                $lane,
                $commercialType,
                $pricingState,
                $offeringId > 0 ? $offeringId : null,
                $expertProfileId > 0 ? $expertProfileId : null,
                $ownerGoal !== '' ? $ownerGoal : null,
                $preferredContact !== '' ? $preferredContact : null,
                $preferredTime !== '' ? $preferredTime : null,
            ]
        );
    }

    private function offeringExists(int $offeringId): bool
    {
        return $offeringId > 0 && Database::queryOne(
            "SELECT id FROM owner_help_offerings WHERE id = ? AND is_active = 1 LIMIT 1",
            [$offeringId]
        ) !== null;
    }

    private function expertProfileExists(int $expertProfileId): bool
    {
        $approvalSql = Database::columnExists('owner_help_expert_profiles', 'approval_status')
            ? " AND approval_status = 'approved'"
            : '';

        return $expertProfileId > 0 && Database::queryOne(
            "SELECT id FROM owner_help_expert_profiles WHERE id = ? AND profile_status = 'active' AND is_internal = 1{$approvalSql} LIMIT 1",
            [$expertProfileId]
        ) !== null;
    }

    private function normalizeLane(string $lane): string
    {
        $lane = strtolower(trim($lane));
        return in_array($lane, self::HELP_LANES, true) ? $lane : 'system_error';
    }

    private function categoryForLane(string $lane): string
    {
        return match ($this->normalizeLane($lane)) {
            'billing_access' => 'billing',
            'account_access' => 'account',
            'setup_help', 'installation_help', 'strategy_mentor', 'account_manager' => 'setup',
            default => 'technical',
        };
    }

    private function laneForLegacyCategory(string $category): string
    {
        return match ($this->normalizeCategory($category)) {
            'billing' => 'billing_access',
            'account' => 'account_access',
            'setup' => 'setup_help',
            default => 'system_error',
        };
    }

    private function commercialTypeForLane(string $lane): string
    {
        return match ($this->normalizeLane($lane)) {
            'setup_help', 'installation_help' => 'paid_setup',
            'strategy_mentor', 'account_manager' => 'paid_expert',
            default => 'free',
        };
    }

    private function defaultPricingState(string $commercialType): string
    {
        return match ($commercialType) {
            'paid_setup', 'paid_expert' => 'quote_required',
            'free' => 'free',
            default => 'not_applicable',
        };
    }

    private function statusForLifecycle(string $lifecycleStatus): string
    {
        return match ($lifecycleStatus) {
            'assigned', 'in_progress', 'triaged', 'quoted' => 'in_progress',
            'waiting_on_owner' => 'waiting_on_owner',
            'resolved' => 'resolved',
            'cancelled' => 'dismissed',
            default => 'open',
        };
    }

    private function lifecycleForStatus(string $status): string
    {
        return match ($status) {
            'in_progress' => 'in_progress',
            'waiting_on_owner' => 'waiting_on_owner',
            'resolved' => 'resolved',
            'dismissed' => 'cancelled',
            default => 'triaged',
        };
    }

    private function assertSchemaReady(): void
    {
        if (!Database::tableExists('default_workspace_ops_events')
            || !Database::columnExists('default_workspace_ops_events', 'owner_visible')
            || !Database::columnExists('default_workspace_ops_events', 'conversation_thread_id')) {
            throw new \RuntimeException('Owner support center schema is missing. Run migrations first.');
        }
    }

    private function assertOwnerAccess(int $workspaceId, int $userId): void
    {
        if (!$this->isOwnerMember($workspaceId, $userId)) {
            throw new \RuntimeException('Only active workspace owners can use the owner support center.');
        }
    }

    private function isOwnerMember(int $workspaceId, int $userId): bool
    {
        if ($workspaceId <= 0 || $userId <= 0) {
            return false;
        }
        $membership = Database::queryOne(
            "SELECT id
             FROM workspace_memberships
             WHERE workspace_id = ?
               AND user_id = ?
               AND membership_status = 'active'
               AND (is_owner = 1 OR role_slug = 'owner')
             LIMIT 1",
            [$workspaceId, $userId]
        );

        return $membership !== null;
    }

    /**
     * @return array<string,mixed>
     */
    private function ensureOwnerContact(int $ownerWorkspaceId, int $ownerUserId): array
    {
        $sync = (new DefaultWorkspaceOwnerContactService($this->defaultWorkspace))->syncOwnerForWorkspace($ownerWorkspaceId, $ownerUserId, $ownerUserId);
        $contact = (new DefaultWorkspaceOwnerContactService($this->defaultWorkspace))->statusForOwner($ownerUserId);
        $contactId = (int) ($contact['id'] ?? $sync['contact_id'] ?? 0);
        if ($contactId <= 0) {
            throw new \RuntimeException('Could not prepare the owner contact for support.');
        }

        return [
            'default_workspace_id' => (int) ($contact['workspace_id'] ?? $sync['workspace_id'] ?? $this->defaultWorkspace->id()),
            'contact_id' => $contactId,
            'contact' => $contact ?: [],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function createThread(int $defaultWorkspaceId, int $contactId, int $eventId, ?int $ownerUserId, string $status): array
    {
        $threadKey = $this->threadKey($eventId);
        $existing = Database::queryOne(
            "SELECT * FROM conversation_threads WHERE workspace_id = ? AND thread_key = ? LIMIT 1",
            [$defaultWorkspaceId, $threadKey]
        );
        if ($existing) {
            return $existing;
        }

        Database::execute(
            "INSERT INTO conversation_threads
                (workspace_id, contact_id, channel, thread_key, last_message_at, last_channel, status, current_owner_id, message_count, is_resolved, metadata_json)
             VALUES (?, ?, ?, ?, NOW(), ?, ?, ?, 0, 0, ?)",
            [
                $defaultWorkspaceId,
                $contactId,
                self::CHANNEL,
                $threadKey,
                self::CHANNEL,
                $status,
                $ownerUserId ?: null,
                json_encode(['source' => 'owner_support_center', 'ops_event_id' => $eventId], JSON_UNESCAPED_SLASHES),
            ]
        );

        return Database::queryOne(
            "SELECT * FROM conversation_threads WHERE workspace_id = ? AND id = ? LIMIT 1",
            [$defaultWorkspaceId, (int) Database::lastInsertId()]
        ) ?: [];
    }

    /**
     * @param array<string,mixed> $case
     * @return array<string,mixed>
     */
    private function ensureThreadForCase(array $case, ?int $operatorUserId = null): array
    {
        $eventId = (int) ($case['id'] ?? 0);
        $defaultWorkspaceId = (int) ($case['default_workspace_id'] ?? $this->defaultWorkspace->id());
        $contactId = (int) ($case['contact_id'] ?? 0);
        if ($eventId <= 0 || $defaultWorkspaceId <= 0 || $contactId <= 0) {
            throw new \RuntimeException('Support case is missing its thread context.');
        }
        $threadId = (int) ($case['conversation_thread_id'] ?? 0);
        if ($threadId > 0) {
            $thread = Database::queryOne(
                "SELECT * FROM conversation_threads WHERE workspace_id = ? AND id = ? LIMIT 1",
                [$defaultWorkspaceId, $threadId]
            );
            if ($thread) {
                return $thread;
            }
        }

        $thread = $this->createThread($defaultWorkspaceId, $contactId, $eventId, $operatorUserId, 'open');
        Database::execute(
            "UPDATE default_workspace_ops_events SET conversation_thread_id = ? WHERE id = ? AND default_workspace_id = ?",
            [(int) ($thread['id'] ?? 0), $eventId, $defaultWorkspaceId]
        );

        return $thread;
    }

    /**
     * @param array<string,mixed> $metadata
     */
    private function insertCommunication(int $workspaceId, int $contactId, string $threadKey, string $direction, string $subject, string $body, array $metadata): int
    {
        Database::execute(
            "INSERT INTO communications
                (workspace_id, uuid, contact_id, thread_key, channel, direction, subject, body, metadata, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'sent', NOW())",
            [
                $workspaceId,
                $this->uuid(),
                $contactId,
                $threadKey,
                self::CHANNEL,
                $direction,
                $subject,
                $body,
                json_encode($metadata, JSON_UNESCAPED_SLASHES),
            ]
        );
        $communicationId = (int) Database::lastInsertId();
        Database::execute(
            "UPDATE conversation_threads
             SET message_count = message_count + 1,
                 last_message_at = NOW(),
                 last_channel = ?,
                 last_inbound_at = CASE WHEN ? = 'inbound' THEN NOW() ELSE last_inbound_at END,
                 last_outbound_at = CASE WHEN ? = 'outbound' THEN NOW() ELSE last_outbound_at END,
                 updated_at = NOW()
             WHERE workspace_id = ? AND thread_key = ?",
            [self::CHANNEL, $direction, $direction, $workspaceId, $threadKey]
        );

        return $communicationId;
    }

    private function touchThread(int $threadId, int $workspaceId, string $status, bool $ownerMessage): void
    {
        Database::execute(
            "UPDATE conversation_threads
             SET status = ?,
                 is_resolved = CASE WHEN ? = 'resolved' THEN 1 ELSE 0 END,
                 resolved_at = CASE WHEN ? = 'resolved' THEN NOW() ELSE NULL END,
                 unresolved_item_count = CASE WHEN ? = 1 THEN unresolved_item_count + 1 ELSE unresolved_item_count END,
                 updated_at = NOW()
             WHERE workspace_id = ? AND id = ?",
            [$status, $status, $status, $ownerMessage ? 1 : 0, $workspaceId, $threadId]
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    private function caseRow(int $eventId): ?array
    {
        if ($eventId <= 0) {
            return null;
        }
        $helpSelect = $this->helpRequestSelectSql();
        $helpJoin = $this->helpRequestJoinSql();

        return Database::queryOne(
            "SELECT e.*, w.name AS owner_workspace_name, w.slug AS owner_workspace_slug,
                    owner.email AS owner_email, owner.first_name AS owner_first_name, owner.last_name AS owner_last_name,
                    c.first_name AS contact_first_name, c.last_name AS contact_last_name, c.email AS contact_email,
                    assignee.email AS assigned_email{$helpSelect}
             FROM default_workspace_ops_events e
             LEFT JOIN workspaces w ON w.id = e.owner_workspace_id
             LEFT JOIN users owner ON owner.id = e.owner_user_id
             LEFT JOIN contacts c ON c.id = e.contact_id AND c.workspace_id = e.default_workspace_id
             LEFT JOIN users assignee ON assignee.id = e.assigned_user_id
             {$helpJoin}
             WHERE e.default_workspace_id = ?
               AND e.id = ?
             LIMIT 1",
            [$this->defaultWorkspace->id(), $eventId]
        );
    }

    /**
     * @param array<string,mixed> $case
     * @return array<int,array<string,mixed>>
     */
    private function messagesForCase(array $case): array
    {
        $defaultWorkspaceId = (int) ($case['default_workspace_id'] ?? $this->defaultWorkspace->id());
        $threadId = (int) ($case['conversation_thread_id'] ?? 0);
        $threadKey = $this->threadKey((int) ($case['id'] ?? 0));
        if ($threadId > 0) {
            $threadRow = Database::queryOne(
                "SELECT thread_key FROM conversation_threads WHERE workspace_id = ? AND id = ? LIMIT 1",
                [$defaultWorkspaceId, $threadId]
            ) ?: [];
            $threadKey = (string) (($threadRow['thread_key'] ?? '') ?: $threadKey);
        }
        if ($threadKey === '') {
            return [];
        }

        return array_map(function (array $row): array {
            $row['metadata_decoded'] = $this->decodeJson($row['metadata'] ?? null);
            return $row;
        }, Database::query(
            "SELECT *
             FROM communications
             WHERE workspace_id = ?
               AND thread_key = ?
             ORDER BY created_at ASC, id ASC",
            [$defaultWorkspaceId, $threadKey]
        ));
    }

    private function notifyPlatformOperators(int $defaultWorkspaceId, int $eventId, string $title, string $message): void
    {
        foreach ($this->defaultWorkspaceMembers() as $member) {
            $userId = (int) ($member['id'] ?? 0);
            if ($userId <= 0) {
                continue;
            }
            $this->insertNotification($defaultWorkspaceId, $userId, 'owner_support', $title, $message, 'default_workspace_ops_event', $eventId, 'owner_support_admin.php?event_id=' . $eventId, 'high');
        }
    }

    private function notifyTenantOwners(int $ownerWorkspaceId, int $eventId, string $title, string $message): void
    {
        foreach ($this->ownerUserIds($ownerWorkspaceId) as $userId) {
            $this->insertNotification($ownerWorkspaceId, $userId, 'owner_support', $title, $message, 'owner_support_case', $eventId, 'owner_support.php?case_id=' . $eventId, 'info');
        }
    }

    private function insertNotification(int $workspaceId, int $userId, string $type, string $title, string $message, string $entityType, int $entityId, string $link, string $severity): void
    {
        Database::execute(
            "INSERT INTO notifications (workspace_id, user_id, type, title, message, entity_type, entity_id, link, severity, is_read, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NOW())",
            [$workspaceId, $userId, $type, substr($title, 0, 255), $message, $entityType, $entityId, $link, $severity]
        );
    }

    /**
     * @return array<int,int>
     */
    private function ownerUserIds(int $ownerWorkspaceId): array
    {
        return array_values(array_unique(array_map(static fn(array $row): int => (int) ($row['user_id'] ?? 0), Database::query(
            "SELECT user_id
             FROM workspace_memberships
             WHERE workspace_id = ?
               AND membership_status = 'active'
               AND (is_owner = 1 OR role_slug = 'owner')
             ORDER BY is_owner DESC, id ASC",
            [$ownerWorkspaceId]
        ))));
    }

    private function assertPlatformOperator(?array $actor): void
    {
        if (!Authorization::isSuperAdmin($actor)) {
            throw new \RuntimeException('Only Super Admin can manage owner support cases.');
        }
    }

    private function isDefaultWorkspaceMember(int $userId): bool
    {
        return Database::queryOne(
            "SELECT id FROM workspace_memberships WHERE workspace_id = ? AND user_id = ? AND membership_status = 'active' LIMIT 1",
            [$this->defaultWorkspace->id(), $userId]
        ) !== null;
    }

    private function normalizeCategory(string $category): string
    {
        $category = strtolower(trim($category));
        return in_array($category, self::CATEGORIES, true) ? $category : 'other';
    }

    private function normalizeStatus(string $status): string
    {
        $status = strtolower(trim($status));
        if (!in_array($status, self::STATUSES, true)) {
            throw new \RuntimeException('Unsupported support case status.');
        }
        return $status;
    }

    private function cleanSubject(string $subject): string
    {
        return substr(trim(preg_replace('/\s+/', ' ', strip_tags($subject)) ?: ''), 0, 255);
    }

    private function cleanMessage(string $message): string
    {
        $message = str_replace(["\r\n", "\r"], "\n", trim(strip_tags($message)));
        $message = preg_replace("/\n{4,}/", "\n\n\n", $message) ?: '';
        return substr(trim($message), 0, 10000);
    }

    private function cleanOptionalText(string $value, int $maxLength): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", trim(strip_tags($value)));
        $value = preg_replace("/\n{4,}/", "\n\n\n", $value) ?: '';
        if (function_exists('mb_substr')) {
            return mb_substr(trim($value), 0, $maxLength);
        }
        return substr(trim($value), 0, $maxLength);
    }

    private function initialPriority(string $category): string
    {
        return in_array($category, ['billing', 'technical'], true) ? 'high' : 'medium';
    }

    private function threadKey(int $eventId): string
    {
        return 'owner_support:event:' . $eventId;
    }

    private function activeSignalKey(string $signalType, string $fingerprint): string
    {
        return substr($signalType . ':' . $fingerprint, 0, 191);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function normalizeCaseRow(array $row): array
    {
        $row['metadata'] = $this->decodeJson($row['metadata_json'] ?? null);
        $row['owner_visible'] = !empty($row['owner_visible']);
        $row['owner_subject'] = (string) (($row['owner_subject'] ?? '') ?: 'Owner support request');
        $row['owner_category'] = (string) (($row['owner_category'] ?? '') ?: 'other');
        $lane = (string) (($row['help_lane'] ?? '') ?: $this->laneForLegacyCategory((string) $row['owner_category']));
        $commercialType = (string) (($row['help_commercial_type'] ?? '') ?: $this->commercialTypeForLane($lane));
        $pricingState = (string) (($row['help_pricing_state'] ?? '') ?: $this->defaultPricingState($commercialType));
        $expertName = trim((string) ($row['help_expert_first_name'] ?? '') . ' ' . (string) ($row['help_expert_last_name'] ?? ''));
        if ($expertName === '') {
            $expertName = (string) ($row['help_expert_email'] ?? '');
        }
        $row['help_request'] = [
            'id' => isset($row['help_request_id']) ? (int) $row['help_request_id'] : 0,
            'lane' => $lane,
            'commercial_type' => $commercialType,
            'pricing_state' => $pricingState,
            'lifecycle_status' => (string) (($row['help_lifecycle_status'] ?? '') ?: $this->lifecycleForStatus((string) ($row['status'] ?? 'open'))),
            'offering_id' => isset($row['help_offering_id']) ? (int) $row['help_offering_id'] : 0,
            'offering_label' => (string) ($row['help_offering_label'] ?? ''),
            'offering_pricing_label' => (string) ($row['help_offering_pricing_label'] ?? ''),
            'expert_profile_id' => isset($row['help_expert_profile_id']) ? (int) $row['help_expert_profile_id'] : 0,
            'expert_name' => $expertName,
            'expert_role_label' => (string) ($row['help_expert_role_label'] ?? ''),
            'expert_headline' => (string) ($row['help_expert_headline'] ?? ''),
            'expert_profile_photo_path' => (string) ($row['help_expert_profile_photo_path'] ?? ''),
            'expert_public_slug' => (string) ($row['help_expert_public_slug'] ?? ''),
            'owner_goal' => (string) ($row['help_owner_goal'] ?? ''),
            'preferred_contact_method' => (string) ($row['help_preferred_contact_method'] ?? ''),
            'preferred_time' => (string) ($row['help_preferred_time'] ?? ''),
            'quote_notes' => (string) ($row['help_quote_notes'] ?? ''),
            'admin_notes' => (string) ($row['help_admin_notes'] ?? ''),
        ];
        return $row;
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJson(mixed $json): array
    {
        if (is_array($json)) {
            return $json;
        }
        if (!is_string($json) || trim($json) === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
