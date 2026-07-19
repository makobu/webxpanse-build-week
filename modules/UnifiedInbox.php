<?php
/**
 * Unified Inbox Module
 * Aggregates communications from all channels
 */

namespace CRM\Modules;

use CRM\Database;
use CRM\Services\DemoSessionScopeService;
use CRM\Services\WorkspaceScopeService;

class UnifiedInbox
{
    private const PREVIEW_LENGTH = 180;
    public const OWNER_SCOPE_MINE_UNASSIGNED = 'mine_unassigned';
    public const OWNER_SCOPE_ALL = 'all';
    private WorkspaceScopeService $workspaceScope;
    private DemoSessionScopeService $demoScope;

    public function __construct()
    {
        $this->workspaceScope = new WorkspaceScopeService();
        $this->demoScope = new DemoSessionScopeService();
    }

    public function resolveOwnerScope(?string $requestedScope, bool $canViewAllConversations = false): string
    {
        $canViewAllConversations = $this->demoScope->forceOwnerScopeAllAllowed($canViewAllConversations);
        $scope = strtolower(trim((string) $requestedScope));
        if ($scope === self::OWNER_SCOPE_ALL && $canViewAllConversations) {
            return self::OWNER_SCOPE_ALL;
        }

        return self::OWNER_SCOPE_MINE_UNASSIGNED;
    }

    /**
     * Get all communications for a contact
     */
    public function getByContact(int $contactId, int $limit = 50, int $offset = 0): array
    {
        $limit = max(0, (int) $limit);
        $offset = max(0, (int) $offset);
        $hasArchivedColumn = $this->columnExists('communications', 'archived_at');
        $hasDeletedColumn = $this->columnExists('communications', 'deleted_at');
        
        $workspaceId = $this->workspaceId();
        $where = ["workspace_id = ?", "contact_id = ?"];
        $params = [$workspaceId, $contactId];
        [$demoSql, $demoParams] = $this->demoEntityClause('communications');
        if ($demoSql !== '') {
            $where[] = $demoSql;
            $params = array_merge($params, $demoParams);
        }
        
        if ($hasArchivedColumn) {
            $where[] = "archived_at IS NULL";
        }
        if ($hasDeletedColumn) {
            $where[] = "deleted_at IS NULL";
        }
        
        $whereClause = "WHERE " . implode(" AND ", $where);
        
        return Database::query(
            "SELECT * FROM communications 
             $whereClause
             ORDER BY created_at DESC 
             LIMIT {$limit} OFFSET {$offset}",
            $params
        );
    }
    
    /**
     * Get unread communications
     */
    public function getUnread(?int $contactId = null, int $limit = 50): array
    {
        $limit = max(0, (int) $limit);
        $hasArchivedColumn = $this->columnExists('communications', 'archived_at');
        $hasDeletedColumn = $this->columnExists('communications', 'deleted_at');
        
        $workspaceId = $this->workspaceId();
        $where = ["workspace_id = ?", "read_at IS NULL"];
        $params = [$workspaceId];
        [$demoSql, $demoParams] = $this->demoEntityClause('communications');
        if ($demoSql !== '') {
            $where[] = $demoSql;
            $params = array_merge($params, $demoParams);
        }
        
        if ($hasArchivedColumn) {
            $where[] = "archived_at IS NULL";
        }
        if ($hasDeletedColumn) {
            $where[] = "deleted_at IS NULL";
        }
        
        if ($contactId) {
            $where[] = "contact_id = ?";
            $params[] = $contactId;
        }
        
        $whereClause = "WHERE " . implode(" AND ", $where);
        $sql = "SELECT * FROM communications $whereClause ORDER BY created_at DESC LIMIT {$limit}";
        
        return Database::query($sql, $params);
    }
    
    /**
     * Get communications by channel
     */
    public function getByChannel(string $channel, int $limit = 50, int $offset = 0): array
    {
        $limit = max(0, (int) $limit);
        $offset = max(0, (int) $offset);
        $hasArchivedColumn = $this->columnExists('communications', 'archived_at');
        $hasDeletedColumn = $this->columnExists('communications', 'deleted_at');
        
        $workspaceId = $this->workspaceId();
        $where = ["workspace_id = ?", "channel = ?"];
        $params = [$workspaceId, $channel];
        [$demoSql, $demoParams] = $this->demoEntityClause('communications');
        if ($demoSql !== '') {
            $where[] = $demoSql;
            $params = array_merge($params, $demoParams);
        }
        
        if ($hasArchivedColumn) {
            $where[] = "archived_at IS NULL";
        }
        if ($hasDeletedColumn) {
            $where[] = "deleted_at IS NULL";
        }
        
        $whereClause = "WHERE " . implode(" AND ", $where);
        
        return Database::query(
            "SELECT * FROM communications 
             $whereClause
             ORDER BY created_at DESC 
             LIMIT {$limit} OFFSET {$offset}",
            $params
        );
    }
    
    /**
     * Mark communication as read
     */
    public function markAsRead(int $communicationId): bool
    {
        if ($this->hasPerUserInboxState()) {
            return $this->setCommunicationUserState($communicationId, 'read_at', true);
        }

        [$demoSql, $demoParams] = $this->demoEntityClause('communications');
        $sql = "UPDATE communications SET read_at = NOW() WHERE workspace_id = ? AND id = ? AND read_at IS NULL";
        $params = [$this->workspaceId(), $communicationId];
        if ($demoSql !== '') {
            $sql .= " AND {$demoSql}";
            $params = array_merge($params, $demoParams);
        }

        return Database::execute($sql, $params) > 0;
    }

    public function markAsUnread(int $communicationId): bool
    {
        if ($this->hasPerUserInboxState()) {
            return $this->setCommunicationUserState($communicationId, 'read_at', false);
        }

        [$demoSql, $demoParams] = $this->demoEntityClause('communications');
        $sql = "UPDATE communications SET read_at = NULL WHERE workspace_id = ? AND id = ?";
        $params = [$this->workspaceId(), $communicationId];
        if ($demoSql !== '') {
            $sql .= " AND {$demoSql}";
            $params = array_merge($params, $demoParams);
        }

        return Database::execute($sql, $params) > 0;
    }

    public function markThreadAsRead(int $threadId): bool
    {
        $thread = $this->getThreadById($threadId);
        if (!$thread) {
            return false;
        }

        if ($this->hasPerUserInboxState()) {
            $changed = 0;
            foreach ($this->getThreadCommunicationIds($thread) as $communicationId) {
                $changed += $this->markAsRead($communicationId) ? 1 : 0;
            }
            return $changed > 0;
        }

        [$whereClause, $params] = $this->buildThreadCommunicationWhere($thread);
        Database::execute(
            "UPDATE communications
             SET read_at = NOW()
             WHERE {$whereClause}
               AND read_at IS NULL",
            $params
        );

        return true;
    }

    public function markThreadAsUnread(int $threadId): bool
    {
        $thread = $this->getThreadById($threadId);
        if (!$thread) {
            return false;
        }

        if ($this->hasPerUserInboxState()) {
            $changed = 0;
            foreach ($this->getThreadCommunicationIds($thread) as $communicationId) {
                $changed += $this->markAsUnread($communicationId) ? 1 : 0;
            }
            return $changed > 0;
        }

        [$whereClause, $params] = $this->buildThreadCommunicationWhere($thread);
        Database::execute(
            "UPDATE communications
             SET read_at = NULL
             WHERE {$whereClause}",
            $params
        );

        return true;
    }
    
    /**
     * Get all communications with filters
     */
    public function getAll(int $limit = 50, int $offset = 0, array $filters = []): array
    {
        $limit = max(0, (int) $limit);
        $offset = max(0, (int) $offset);
        $query = $this->buildListFilterParts($filters, true);
        
        return Database::query(
            "SELECT c.id, c.contact_id, c.channel, c.direction, c.subject, c.from_email,
                    c.created_at, {$query['readAtExpression']} AS read_at,
                    " . ($query['hasArchivedColumn'] ? "{$query['archivedAtExpression']} AS archived_at," : "NULL AS archived_at,") . "
                    " . ($query['hasDeletedColumn'] ? "{$query['deletedAtExpression']} AS deleted_at," : "NULL AS deleted_at,") . "
                    " . ($query['hasTriagePriorityColumn'] ? "c.triage_priority," : "NULL AS triage_priority,") . "
                    " . ($query['hasTriageStatusColumn'] ? "c.triage_status," : "NULL AS triage_status,") . "
                    " . ($query['hasTriageStatusColumn'] && $this->columnExists('communications', 'triage_reason_codes') ? "c.triage_reason_codes," : "NULL AS triage_reason_codes,") . "
                    " . ($query['hasThreadKeyColumn'] ? "c.thread_key," : "NULL AS thread_key,") . "
                    c.metadata,
                    SUBSTRING(COALESCE(c.body, ''), 1, " . self::PREVIEW_LENGTH . ") AS body_preview,
                    CHAR_LENGTH(COALESCE(c.body, '')) AS body_length,
                    " . ($query['hasThreadTable'] ? "th.id AS thread_id, th.status AS thread_status, th.current_owner_id AS thread_owner_id,
                    th.priority AS thread_priority, th.response_due_at AS thread_response_due_at,
                    th.unresolved_item_count AS thread_unresolved_item_count, th.escalation_status AS thread_escalation_status,
                    th.metadata_json AS thread_metadata_json, th.message_count AS thread_message_count," : "NULL AS thread_id, NULL AS thread_status, NULL AS thread_owner_id,
                    NULL AS thread_priority, NULL AS thread_response_due_at, NULL AS thread_unresolved_item_count,
                    NULL AS thread_escalation_status, NULL AS thread_metadata_json, NULL AS thread_message_count,") . "
                    ct.first_name, ct.last_name, ct.email as contact_email,
                    TRIM(CONCAT(COALESCE(ct.first_name, ''), ' ', COALESCE(ct.last_name, ''))) as contact_name
             FROM communications c
             LEFT JOIN contacts ct ON c.contact_id = ct.id AND ct.workspace_id = c.workspace_id
             {$query['joins']}
             {$query['whereClause']}
             ORDER BY c.created_at DESC
             LIMIT {$limit} OFFSET {$offset}",
            $query['params']
        );
    }

    public function getThreadSummaries(int $limit = 50, int $offset = 0, array $filters = []): array
    {
        $limit = max(0, (int) $limit);
        $offset = max(0, (int) $offset);
        if (!$this->columnExists('communications', 'thread_key') || !$this->tableExists('conversation_threads')) {
            return $this->getAll($limit, $offset, $filters);
        }

        $query = $this->buildThreadFilterParts($filters);
        $viewerUserId = (int) ($filters['viewer_user_id'] ?? 0);
        $latestStateJoin = $this->communicationUserStateJoin('c1', 'c1_state', $viewerUserId);
        $statsStateJoin = $this->communicationUserStateJoin('c2', 'c2_state', $viewerUserId);
        $latestRowStateJoin = $this->communicationUserStateJoin('latest', 'latest_state', $viewerUserId);
        $latestReadExpression = $latestRowStateJoin !== ''
            ? $this->effectiveUserStateExpression('read_at', 'latest', 'latest_state')
            : 'latest.read_at';
        $statsReadExpression = $statsStateJoin !== ''
            ? $this->effectiveUserStateExpression('read_at', 'c2', 'c2_state')
            : 'c2.read_at';
        $latestScopeClause = $this->buildCommunicationScopeClause('c1', $viewerUserId, 'c1_state');
        $latestPredicate = $this->buildThreadCommunicationPredicate('th', 'c1');
        $statsPredicate = $this->buildThreadCommunicationPredicate('th', 'c2');

        return Database::query(
            "SELECT latest.id,
                    latest.id AS latest_communication_id,
                    latest.contact_id,
                    latest.channel,
                    latest.direction,
                    latest.subject,
                    latest.from_email,
                    latest.created_at,
                    {$latestReadExpression} AS read_at,
                    SUBSTRING(COALESCE(latest.body, ''), 1, " . self::PREVIEW_LENGTH . ") AS body_preview,
                    CHAR_LENGTH(COALESCE(latest.body, '')) AS body_length,
                    ct.first_name,
                    ct.last_name,
                    ct.email AS contact_email,
                    TRIM(CONCAT(COALESCE(ct.first_name, ''), ' ', COALESCE(ct.last_name, ''))) AS contact_name,
                    th.id AS thread_id,
                    th.thread_key,
                    th.status AS thread_status,
                    th.current_owner_id AS thread_owner_id,
                    th.priority AS thread_priority,
                    th.response_due_at AS thread_response_due_at,
                    th.unresolved_item_count AS thread_unresolved_item_count,
                    th.escalation_status AS thread_escalation_status,
                    th.metadata_json AS thread_metadata_json,
                    COALESCE((
                        SELECT SUM(CASE WHEN {$statsReadExpression} IS NULL THEN 1 ELSE 0 END)
                        FROM communications c2
                        {$statsStateJoin}
                        WHERE {$statsPredicate}
                        {$this->buildCommunicationScopeClause('c2', $viewerUserId, 'c2_state')}
                    ), 0) AS thread_unread_count,
                    COALESCE((
                        SELECT COUNT(*)
                        FROM communications c2
                        {$statsStateJoin}
                        WHERE {$statsPredicate}
                        {$this->buildCommunicationScopeClause('c2', $viewerUserId, 'c2_state')}
                    ), 0) AS thread_message_count,
                    COALESCE((
                        SELECT GROUP_CONCAT(DISTINCT c2.channel ORDER BY c2.channel SEPARATOR ',')
                        FROM communications c2
                        {$statsStateJoin}
                        WHERE {$statsPredicate}
                        {$this->buildCommunicationScopeClause('c2', $viewerUserId, 'c2_state')}
                    ), '') AS thread_channels
             FROM conversation_threads th
             JOIN communications latest ON latest.id = (
                    SELECT c1.id
                    FROM communications c1
                    {$latestStateJoin}
                    WHERE {$latestPredicate}
                    {$latestScopeClause}
                    ORDER BY c1.created_at DESC, c1.id DESC
                    LIMIT 1
             )
             LEFT JOIN contacts ct ON ct.id = COALESCE(latest.contact_id, th.contact_id) AND ct.workspace_id = th.workspace_id
             {$latestRowStateJoin}
             {$query['whereClause']}
             ORDER BY latest.created_at DESC
             LIMIT {$limit} OFFSET {$offset}",
            $query['params']
        );
    }

    /**
     * Return one inbox row per linked contact, plus conventional thread rows
     * for communications that are not linked to a contact.
     */
    public function getContactConversationSummaries(int $limit = 50, int $offset = 0, array $filters = []): array
    {
        $limit = max(1, $limit);
        $offset = max(0, $offset);
        $take = $limit + $offset;
        $query = $this->buildListFilterParts($filters, true);

        $contactRows = Database::query(
            "SELECT latest.id,
                    latest.id AS latest_communication_id,
                    grouped.contact_id,
                    latest.channel,
                    latest.direction,
                    latest.subject,
                    latest.from_email,
                    latest.created_at,
                    CASE WHEN grouped.thread_unread_count > 0 THEN NULL ELSE latest.read_at END AS read_at,
                    SUBSTRING(COALESCE(latest.body, ''), 1, " . self::PREVIEW_LENGTH . ") AS body_preview,
                    CHAR_LENGTH(COALESCE(latest.body, '')) AS body_length,
                    latest.triage_priority,
                    latest.triage_status,
                    latest.triage_reason_codes,
                    latest.thread_key,
                    ct.first_name,
                    ct.last_name,
                    ct.email AS contact_email,
                    TRIM(CONCAT(COALESCE(ct.first_name, ''), ' ', COALESCE(ct.last_name, ''))) AS contact_name,
                    latest_thread.id AS thread_id,
                    latest_thread.id AS latest_thread_id,
                    latest_thread.status AS thread_status,
                    ct.assigned_to AS thread_owner_id,
                    ct.assigned_to AS owner_id,
                    latest_thread.priority AS thread_priority,
                    latest_thread.response_due_at AS thread_response_due_at,
                    latest_thread.unresolved_item_count AS thread_unresolved_item_count,
                    latest_thread.escalation_status AS thread_escalation_status,
                    latest_thread.metadata_json AS thread_metadata_json,
                    grouped.thread_unread_count,
                    grouped.thread_message_count,
                    grouped.thread_channels,
                    'contact' AS group_type,
                    grouped.contact_id AS group_id
             FROM (
                    SELECT c.contact_id,
                           CAST(SUBSTRING_INDEX(GROUP_CONCAT(c.id ORDER BY c.created_at DESC, c.id DESC), ',', 1) AS UNSIGNED) AS latest_id,
                           COUNT(*) AS thread_message_count,
                           SUM(CASE WHEN {$query['readAtExpression']} IS NULL THEN 1 ELSE 0 END) AS thread_unread_count,
                           GROUP_CONCAT(DISTINCT c.channel ORDER BY c.channel SEPARATOR ',') AS thread_channels
                    FROM communications c
                    LEFT JOIN contacts ct ON ct.id = c.contact_id AND ct.workspace_id = c.workspace_id
                    {$query['joins']}
                    {$query['whereClause']}
                      AND c.contact_id IS NOT NULL
                    GROUP BY c.contact_id
             ) grouped
             JOIN communications latest ON latest.workspace_id = ? AND latest.id = grouped.latest_id
             LEFT JOIN contacts ct ON ct.id = grouped.contact_id AND ct.workspace_id = latest.workspace_id
             LEFT JOIN conversation_threads latest_thread
                    ON latest_thread.workspace_id = latest.workspace_id
                   AND latest_thread.thread_key COLLATE utf8mb4_general_ci = latest.thread_key
             ORDER BY latest.created_at DESC, latest.id DESC
             LIMIT {$take}",
            array_merge($query['params'], [$this->workspaceId()])
        );

        $unlinkedFilters = $filters;
        $unlinkedFilters['unlinked_only'] = true;
        $unlinkedRows = $this->getThreadSummaries($take, 0, $unlinkedFilters);
        foreach ($unlinkedRows as &$row) {
            $row['group_type'] = 'thread';
            $row['group_id'] = (int) ($row['thread_id'] ?? $row['id'] ?? 0);
            $row['latest_thread_id'] = !empty($row['thread_id']) ? (int) $row['thread_id'] : null;
            $row['owner_id'] = !empty($row['thread_owner_id']) ? (int) $row['thread_owner_id'] : null;
        }
        unset($row);

        $rows = array_merge($contactRows, $unlinkedRows);
        usort($rows, static function (array $left, array $right): int {
            $timeCompare = strcmp((string) ($right['created_at'] ?? ''), (string) ($left['created_at'] ?? ''));
            return $timeCompare !== 0
                ? $timeCompare
                : ((int) ($right['id'] ?? 0) <=> (int) ($left['id'] ?? 0));
        });

        return array_slice($rows, $offset, $limit);
    }

    public function getContactConversationCount(array $filters = []): int
    {
        $query = $this->buildListFilterParts($filters, true);
        $contactCount = Database::queryOne(
            "SELECT COUNT(DISTINCT c.contact_id) AS count
             FROM communications c
             LEFT JOIN contacts ct ON ct.id = c.contact_id AND ct.workspace_id = c.workspace_id
             {$query['joins']}
             {$query['whereClause']}
               AND c.contact_id IS NOT NULL",
            $query['params']
        );

        $unlinkedFilters = $filters;
        $unlinkedFilters['unlinked_only'] = true;

        return (int) ($contactCount['count'] ?? 0) + $this->getThreadCount($unlinkedFilters);
    }

    /** @return array<int,array<string,mixed>> */
    public function getContactConversationMessages(int $contactId, array $filters = []): array
    {
        if ($contactId <= 0) {
            return [];
        }
        $filters['contact_id'] = $contactId;
        $query = $this->buildListFilterParts($filters, true);

        return Database::query(
            "SELECT c.*, {$query['readAtExpression']} AS read_at
             FROM communications c
             LEFT JOIN contacts ct ON ct.id = c.contact_id AND ct.workspace_id = c.workspace_id
             {$query['joins']}
             {$query['whereClause']}
             ORDER BY c.created_at ASC, c.id ASC",
            $query['params']
        );
    }

    /** @return list<int> */
    public function getContactConversationIds(int $contactId, array $filters = []): array
    {
        return array_values(array_map(
            static fn(array $row): int => (int) ($row['id'] ?? 0),
            $this->getContactConversationMessages($contactId, $filters)
        ));
    }

    public function markContactAsRead(int $contactId, array $filters = []): bool
    {
        return $this->applyContactAction($contactId, $filters, fn(int $id): bool => $this->markAsRead($id));
    }

    public function markContactAsUnread(int $contactId, array $filters = []): bool
    {
        return $this->applyContactAction($contactId, $filters, fn(int $id): bool => $this->markAsUnread($id));
    }

    public function archiveContact(int $contactId, array $filters = []): bool
    {
        return $this->applyContactAction($contactId, $filters, fn(int $id): bool => $this->archive($id));
    }

    private function applyContactAction(int $contactId, array $filters, callable $action): bool
    {
        $ids = $this->getContactConversationIds($contactId, $filters);
        if ($ids === []) {
            return false;
        }
        foreach ($ids as $id) {
            $action($id);
        }
        return true;
    }
    
    /**
     * Get count of communications with filters
     */
    public function getCount(array $filters = []): int
    {
        $query = $this->buildListFilterParts($filters, false);
        
        $result = Database::queryOne(
            "SELECT COUNT(*) as count
             FROM communications c
             LEFT JOIN contacts ct ON c.contact_id = ct.id AND ct.workspace_id = c.workspace_id
             {$query['joins']}
             {$query['whereClause']}",
            $query['params']
        );
        
        return (int) ($result['count'] ?? 0);
    }

    public function getThreadCount(array $filters = []): int
    {
        if (!$this->columnExists('communications', 'thread_key') || !$this->tableExists('conversation_threads')) {
            return $this->getCount($filters);
        }

        $query = $this->buildThreadFilterParts($filters);
        $viewerUserId = (int) ($filters['viewer_user_id'] ?? 0);
        $latestStateJoin = $this->communicationUserStateJoin('c1', 'c1_state', $viewerUserId);
        $latestScopeClause = $this->buildCommunicationScopeClause('c1', $viewerUserId, 'c1_state');
        $latestPredicate = $this->buildThreadCommunicationPredicate('th', 'c1');

        $result = Database::queryOne(
            "SELECT COUNT(*) AS count
             FROM conversation_threads th
             JOIN communications latest ON latest.id = (
                    SELECT c1.id
                    FROM communications c1
                    {$latestStateJoin}
                    WHERE {$latestPredicate}
                    {$latestScopeClause}
                    ORDER BY c1.created_at DESC, c1.id DESC
                    LIMIT 1
             )
             LEFT JOIN contacts ct ON ct.id = COALESCE(latest.contact_id, th.contact_id) AND ct.workspace_id = th.workspace_id
             {$query['whereClause']}",
            $query['params']
        );

        return (int) ($result['count'] ?? 0);
    }
    
    /**
     * Get channel counts and unread per channel (excludes archived and deleted)
     * Returns ['email' => ['count' => n, 'unread' => n], 'whatsapp' => ..., 'sms' => ...]
     */
    public function getChannelStats(array $filters = []): array
    {
        $baseQuery = $this->buildListFilterParts($filters, false);
        $unreadFilters = $filters;
        $unreadFilters['status'] = 'unread';
        $unreadQuery = $this->buildListFilterParts($unreadFilters, false);

        $channelCounts = Database::query(
            "SELECT c.channel, COUNT(*) as count
             FROM communications c
             LEFT JOIN contacts ct ON c.contact_id = ct.id AND ct.workspace_id = c.workspace_id
             {$baseQuery['joins']}
             {$baseQuery['whereClause']}
             GROUP BY c.channel",
            $baseQuery['params']
        );
        $unreadCounts = Database::query(
            "SELECT c.channel, COUNT(*) as count
             FROM communications c
             LEFT JOIN contacts ct ON c.contact_id = ct.id AND ct.workspace_id = c.workspace_id
             {$unreadQuery['joins']}
             {$unreadQuery['whereClause']}
             GROUP BY c.channel",
            $unreadQuery['params']
        );

        $channelStats = [];
        $unreadStats = [];
        foreach ($channelCounts as $row) {
            $channelStats[$row['channel']] = (int) $row['count'];
        }
        foreach ($unreadCounts as $row) {
            $unreadStats[$row['channel']] = (int) $row['count'];
        }
        return ['counts' => $channelStats, 'unread' => $unreadStats];
    }

    public function canUserAccessCommunication(
        int $communicationId,
        int $userId,
        bool $canViewAll = false,
        ?string $ownerScope = null
    ): bool
    {
        if ($communicationId <= 0) {
            return false;
        }

        $effectiveOwnerScope = $ownerScope !== null
            ? $this->resolveOwnerScope($ownerScope, $canViewAll)
            : ($canViewAll ? self::OWNER_SCOPE_ALL : self::OWNER_SCOPE_MINE_UNASSIGNED);

        if ($effectiveOwnerScope === self::OWNER_SCOPE_ALL) {
            [$demoSql, $demoParams] = $this->demoEntityClause('communications');
            $sql = "SELECT id FROM communications WHERE workspace_id = ? AND id = ?";
            $params = [$this->workspaceId(), $communicationId];
            if ($demoSql !== '') {
                $sql .= " AND {$demoSql}";
                $params = array_merge($params, $demoParams);
            }
            $row = Database::queryOne($sql . " LIMIT 1", $params);
            return !empty($row['id']);
        }

        $filters = [
            'viewer_user_id' => $userId,
            'can_view_all_conversations' => $canViewAll,
            'owner_scope' => $effectiveOwnerScope,
        ];
        $query = $this->buildListFilterParts($filters, false);
        $whereClause = $query['whereClause'] !== ''
            ? $query['whereClause'] . ' AND c.id = ?'
            : 'WHERE c.id = ?';
        $params = $query['whereClause'] !== ''
            ? array_merge($query['params'], [$communicationId])
            : [$communicationId];

        $row = Database::queryOne(
            "SELECT c.id
             FROM communications c
             LEFT JOIN contacts ct ON c.contact_id = ct.id AND ct.workspace_id = c.workspace_id
             {$query['joins']}
             {$whereClause}
             LIMIT 1",
            $params
        );

        return !empty($row['id']);
    }

    public function canUserAccessThread(
        int $threadId,
        int $userId,
        bool $canViewAll = false,
        ?string $ownerScope = null
    ): bool
    {
        if ($threadId <= 0 || !$this->tableExists('conversation_threads')) {
            return false;
        }

        $effectiveOwnerScope = $ownerScope !== null
            ? $this->resolveOwnerScope($ownerScope, $canViewAll)
            : ($canViewAll ? self::OWNER_SCOPE_ALL : self::OWNER_SCOPE_MINE_UNASSIGNED);

        if ($effectiveOwnerScope === self::OWNER_SCOPE_ALL) {
            [$demoSql, $demoParams] = $this->demoEntityClause('conversation_threads');
            $sql = "SELECT id FROM conversation_threads WHERE workspace_id = ? AND id = ?";
            $params = [$this->workspaceId(), $threadId];
            if ($demoSql !== '') {
                $sql .= " AND {$demoSql}";
                $params = array_merge($params, $demoParams);
            }
            $row = Database::queryOne(
                $sql . " LIMIT 1",
                $params
            );
            return !empty($row['id']);
        }

        $filters = [
            'viewer_user_id' => $userId,
            'can_view_all_conversations' => $canViewAll,
            'owner_scope' => $effectiveOwnerScope,
        ];
        $query = $this->buildThreadFilterParts($filters);
        $latestStateJoin = $this->communicationUserStateJoin('c1', 'c1_state', $userId);
        $latestScopeClause = $this->buildCommunicationScopeClause('c1', $userId, 'c1_state');
        $latestPredicate = $this->buildThreadCommunicationPredicate('th', 'c1');
        $whereClause = $query['whereClause'] . ' AND th.id = ?';
        $params = array_merge($query['params'], [$threadId]);

        $row = Database::queryOne(
            "SELECT th.id
             FROM conversation_threads th
             JOIN communications latest ON latest.id = (
                    SELECT c1.id
                    FROM communications c1
                    {$latestStateJoin}
                    WHERE {$latestPredicate}
                    {$latestScopeClause}
                    ORDER BY c1.created_at DESC, c1.id DESC
                    LIMIT 1
             )
             LEFT JOIN contacts ct ON ct.id = COALESCE(latest.contact_id, th.contact_id) AND ct.workspace_id = th.workspace_id
             {$whereClause}
             LIMIT 1",
            $params
        );

        return !empty($row['id']);
    }

    public function getLatestCommunicationForThread(int $threadId): ?array
    {
        $thread = $this->getThreadById($threadId);
        if (!$thread) {
            return null;
        }

        [$whereClause, $params] = $this->buildThreadCommunicationWhere($thread);

        return Database::queryOne(
            "SELECT *
             FROM communications
             WHERE {$whereClause}
             ORDER BY created_at DESC, id DESC
             LIMIT 1",
            $params
        ) ?: null;
    }

    /**
     * Get inbox summary
     */
    public function getSummary(): array
    {
        $hasArchivedColumn = $this->columnExists('communications', 'archived_at');
        $hasDeletedColumn = $this->columnExists('communications', 'deleted_at');
        
        $where = ['workspace_id = ?'];
        $params = [$this->workspaceId()];
        [$demoSql, $demoParams] = $this->demoEntityClause('communications');
        if ($demoSql !== '') {
            $where[] = $demoSql;
            $params = array_merge($params, $demoParams);
        }
        if ($hasArchivedColumn) {
            $where[] = "archived_at IS NULL";
        }
        if ($hasDeletedColumn) {
            $where[] = "deleted_at IS NULL";
        }
        
        $whereClause = "WHERE " . implode(" AND ", $where);
        
        $summary = Database::queryOne(
            "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN read_at IS NULL THEN 1 ELSE 0 END) as unread,
                SUM(CASE WHEN channel = 'email' THEN 1 ELSE 0 END) as emails,
                SUM(CASE WHEN channel = 'whatsapp' THEN 1 ELSE 0 END) as whatsapp,
                SUM(CASE WHEN direction = 'inbound' THEN 1 ELSE 0 END) as inbound,
                SUM(CASE WHEN direction = 'outbound' THEN 1 ELSE 0 END) as outbound
             FROM communications
             $whereClause",
            $params
        );
        
        return $summary ?: [
            'total' => 0,
            'unread' => 0,
            'emails' => 0,
            'whatsapp' => 0,
            'inbound' => 0,
            'outbound' => 0
        ];
    }
    
    /**
     * Check if a column exists in a table
     */
    private function columnExists(string $table, string $column): bool
    {
        static $cache = [];
        $cacheKey = $table . '.' . $column;
        
        if (isset($cache[$cacheKey])) {
            return $cache[$cacheKey];
        }
        
        try {
            $result = Database::queryOne(
                "SELECT COUNT(*) as count 
                 FROM information_schema.COLUMNS 
                 WHERE TABLE_SCHEMA = DATABASE() 
                 AND TABLE_NAME = ? 
                 AND COLUMN_NAME = ?",
                [$table, $column]
            );
            
            $exists = ($result['count'] ?? 0) > 0;
            $cache[$cacheKey] = $exists;
            return $exists;
        } catch (\Exception $e) {
            // If query fails, assume column doesn't exist
            $cache[$cacheKey] = false;
            return false;
        }
    }

    private function tableExists(string $table): bool
    {
        static $cache = [];
        if (array_key_exists($table, $cache)) {
            return $cache[$table];
        }

        try {
            $result = Database::queryOne(
                "SELECT COUNT(*) as count
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                 AND TABLE_NAME = ?",
                [$table]
            );
            $cache[$table] = ($result['count'] ?? 0) > 0;
            return $cache[$table];
        } catch (\Exception $e) {
            $cache[$table] = false;
            return false;
        }
    }

    /**
     * @return array{whereClause:string, params:array<int, mixed>, joins:string, hasArchivedColumn:bool, hasDeletedColumn:bool, hasTriagePriorityColumn:bool, hasTriageStatusColumn:bool, hasThreadKeyColumn:bool, hasThreadTable:bool, readAtExpression:string, archivedAtExpression:string, deletedAtExpression:string}
     */
    private function buildListFilterParts(array $filters, bool $withAlias): array
    {
        $workspaceId = $this->workspaceId();
        $where = ['c.workspace_id = ?'];
        $params = [$workspaceId];
        $joinParams = [];
        $prefix = 'c.';
        $contactPrefix = 'ct.';
        $joins = [];
        $viewerUserId = (int) ($filters['viewer_user_id'] ?? 0);
        $canViewAllConversations = !empty($filters['can_view_all_conversations']);
        $ownerScope = $this->resolveOwnerScope(
            isset($filters['owner_scope']) ? (string) $filters['owner_scope'] : null,
            $canViewAllConversations
        );

        $hasArchivedColumn = $this->columnExists('communications', 'archived_at');
        $hasDeletedColumn = $this->columnExists('communications', 'deleted_at');
        $hasTriagePriorityColumn = $this->columnExists('communications', 'triage_priority');
        $hasTriageStatusColumn = $this->columnExists('communications', 'triage_status');
        $hasThreadKeyColumn = $this->columnExists('communications', 'thread_key');
        $hasThreadTable = $this->tableExists('conversation_threads');
        $hasUserStateTable = $viewerUserId > 0 && $this->tableExists('communication_user_state');
        $readAtExpression = "{$prefix}read_at";
        $archivedAtExpression = "{$prefix}archived_at";
        $deletedAtExpression = "{$prefix}deleted_at";

        if ($hasUserStateTable) {
            $joins[] = "LEFT JOIN communication_user_state cus ON cus.workspace_id = c.workspace_id AND cus.communication_id = c.id AND cus.user_id = ?";
            $joinParams[] = $viewerUserId;
            $readAtExpression = $this->effectiveUserStateExpression('read_at', 'c', 'cus');
            $archivedAtExpression = $this->effectiveUserStateExpression('archived_at', 'c', 'cus');
            $deletedAtExpression = $this->effectiveUserStateExpression('deleted_at', 'c', 'cus');
        }

        [$demoSql, $demoParams] = $this->demoEntityClause('communications', 'c');
        if ($demoSql !== '') {
            $where[] = $demoSql;
            $params = array_merge($params, $demoParams);
        }

        if ($hasThreadKeyColumn && $hasThreadTable) {
            $threadJoin = "LEFT JOIN conversation_threads th ON th.workspace_id = c.workspace_id AND th.thread_key COLLATE utf8mb4_general_ci = c.thread_key";
            [$threadDemoSql, $threadDemoParams] = $this->demoEntityClause('conversation_threads', 'th');
            if ($threadDemoSql !== '') {
                $threadJoin .= " AND {$threadDemoSql}";
                $joinParams = array_merge($joinParams, $threadDemoParams);
            }
            $joins[] = $threadJoin;
        }

        if ($hasArchivedColumn && empty($filters['include_archived']) && empty($filters['archived'])) {
            $where[] = "{$archivedAtExpression} IS NULL";
        }
        if ($hasDeletedColumn && empty($filters['include_deleted']) && empty($filters['deleted'])) {
            $where[] = "{$deletedAtExpression} IS NULL";
        }
        if (!empty($filters['channel'])) {
            $where[] = "{$prefix}channel = ?";
            $params[] = $filters['channel'];
        }
        if (!empty($filters['status'])) {
            if ($filters['status'] === 'unread') {
                $where[] = "{$readAtExpression} IS NULL";
            } elseif ($filters['status'] === 'read') {
                $where[] = "{$readAtExpression} IS NOT NULL";
            }
        }
        if ($hasArchivedColumn && !empty($filters['archived'])) {
            $where[] = "{$archivedAtExpression} IS NOT NULL";
        }
        if ($hasDeletedColumn && !empty($filters['deleted'])) {
            $where[] = "{$deletedAtExpression} IS NOT NULL";
        }
        if (!empty($filters['contact_id'])) {
            $where[] = "{$prefix}contact_id = ?";
            $params[] = $filters['contact_id'];
        }

        if (!empty($filters['search'])) {
            $searchTerm = '%' . $filters['search'] . '%';
            $includeBodySearch = !empty($filters['include_body_search']);
            if ($includeBodySearch) {
                $where[] = "({$prefix}subject LIKE ? OR {$prefix}from_email LIKE ? OR {$contactPrefix}first_name LIKE ? OR {$contactPrefix}last_name LIKE ? OR {$prefix}body LIKE ?)";
                array_push($params, $searchTerm, $searchTerm, $searchTerm, $searchTerm, $searchTerm);
            } else {
                $where[] = "({$prefix}subject LIKE ? OR {$prefix}from_email LIKE ? OR {$contactPrefix}first_name LIKE ? OR {$contactPrefix}last_name LIKE ?)";
                array_push($params, $searchTerm, $searchTerm, $searchTerm, $searchTerm);
            }
        }

        if ($hasTriagePriorityColumn && !empty($filters['triage_priority'])) {
            $where[] = "{$prefix}triage_priority = ?";
            $params[] = $filters['triage_priority'];
        }
        if ($hasTriageStatusColumn && !empty($filters['triage_status'])) {
            if ($filters['triage_status'] === 'skipped') {
                $where[] = "({$prefix}triage_status = ? OR {$prefix}triage_status IS NULL OR {$prefix}triage_status = '')";
                $params[] = 'skipped';
            } else {
                $where[] = "{$prefix}triage_status = ?";
                $params[] = $filters['triage_status'];
            }
        }

        $ownerScopeClause = $this->buildConversationOwnerScopeClause(
            $ownerScope,
            $viewerUserId,
            $contactPrefix,
            "{$prefix}contact_id"
        );
        if ($ownerScopeClause['sql'] !== '') {
            $where[] = $ownerScopeClause['sql'];
            $params = array_merge($params, $ownerScopeClause['params']);
        }

        return [
            'whereClause' => !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '',
            'params' => array_merge($joinParams, $params),
            'joins' => !empty($joins) ? implode(' ', $joins) : '',
            'hasArchivedColumn' => $hasArchivedColumn,
            'hasDeletedColumn' => $hasDeletedColumn,
            'hasTriagePriorityColumn' => $hasTriagePriorityColumn,
            'hasTriageStatusColumn' => $hasTriageStatusColumn,
            'hasThreadKeyColumn' => $hasThreadKeyColumn,
            'hasThreadTable' => $hasThreadTable,
            'readAtExpression' => $readAtExpression,
            'archivedAtExpression' => $archivedAtExpression,
            'deletedAtExpression' => $deletedAtExpression,
        ];
    }

    private function getThreadById(int $threadId): ?array
    {
        [$demoSql, $demoParams] = $this->demoEntityClause('conversation_threads');
        $sql = "SELECT * FROM conversation_threads WHERE workspace_id = ? AND id = ?";
        $params = [$this->workspaceId(), $threadId];
        if ($demoSql !== '') {
            $sql .= " AND {$demoSql}";
            $params = array_merge($params, $demoParams);
        }

        return Database::queryOne(
            $sql . " LIMIT 1",
            $params
        ) ?: null;
    }

    private function buildThreadCommunicationWhere(array $thread): array
    {
        $threadKey = trim((string) ($thread['thread_key'] ?? ''));
        [$demoSql, $demoParams] = $this->demoEntityClause('communications');
        if ($threadKey !== '') {
            $sql = 'workspace_id = ? AND thread_key = ?';
            $params = [$this->workspaceId(), $threadKey];
            if ($demoSql !== '') {
                $sql .= " AND {$demoSql}";
                $params = array_merge($params, $demoParams);
            }
            return [$sql, $params];
        }

        $sql = 'workspace_id = ? AND contact_id <=> ? AND channel = ?';
        $params = [
            $this->workspaceId(),
            $thread['contact_id'] ?? null,
            (string) ($thread['last_channel'] ?? $thread['channel'] ?? ''),
        ];
        if ($demoSql !== '') {
            $sql .= " AND {$demoSql}";
            $params = array_merge($params, $demoParams);
        }
        return [$sql, $params];
    }

    private function buildThreadCommunicationPredicate(string $threadAlias, string $communicationAlias): string
    {
        return "(
            (
                COALESCE({$threadAlias}.thread_key, '') <> ''
                AND {$communicationAlias}.thread_key = {$threadAlias}.thread_key
            )
            OR
            (
                COALESCE({$threadAlias}.thread_key, '') = ''
                AND {$communicationAlias}.contact_id <=> {$threadAlias}.contact_id
                AND {$communicationAlias}.channel = COALESCE(NULLIF({$threadAlias}.last_channel, ''), {$threadAlias}.channel)
            )
        )";
    }

    private function threadMessageCountExpression(string $threadAlias, int $viewerUserId = 0): string
    {
        $predicate = $this->buildThreadCommunicationPredicate($threadAlias, 'c_count');
        $stateJoin = $this->communicationUserStateJoin('c_count', 'c_count_state', $viewerUserId);
        return "COALESCE((
            SELECT COUNT(*)
            FROM communications c_count
            {$stateJoin}
            WHERE {$predicate}
            {$this->buildCommunicationScopeClause('c_count', $viewerUserId, 'c_count_state')}
        ), 0)";
    }

    private function threadUnreadCountExpression(string $threadAlias, int $viewerUserId = 0): string
    {
        $predicate = $this->buildThreadCommunicationPredicate($threadAlias, 'c_unread');
        $stateJoin = $this->communicationUserStateJoin('c_unread', 'c_unread_state', $viewerUserId);
        $readExpression = $stateJoin !== ''
            ? $this->effectiveUserStateExpression('read_at', 'c_unread', 'c_unread_state')
            : 'c_unread.read_at';
        return "COALESCE((
            SELECT SUM(CASE WHEN {$readExpression} IS NULL THEN 1 ELSE 0 END)
            FROM communications c_unread
            {$stateJoin}
            WHERE {$predicate}
            {$this->buildCommunicationScopeClause('c_unread', $viewerUserId, 'c_unread_state')}
        ), 0)";
    }

    private function buildCommunicationScopeClause(string $alias, int $viewerUserId = 0, string $stateAlias = ''): string
    {
        $parts = ["{$alias}.workspace_id = " . $this->workspaceId()];
        [$demoSql] = $this->demoEntityClause('communications', $alias);
        if ($demoSql !== '') {
            $parts[] = $demoSql;
        }
        if ($this->columnExists('communications', 'archived_at')) {
            $archivedExpression = $stateAlias !== '' && $viewerUserId > 0 && $this->tableExists('communication_user_state')
                ? $this->effectiveUserStateExpression('archived_at', $alias, $stateAlias)
                : "{$alias}.archived_at";
            $parts[] = "{$archivedExpression} IS NULL";
        }
        if ($this->columnExists('communications', 'deleted_at')) {
            $deletedExpression = $stateAlias !== '' && $viewerUserId > 0 && $this->tableExists('communication_user_state')
                ? $this->effectiveUserStateExpression('deleted_at', $alias, $stateAlias)
                : "{$alias}.deleted_at";
            $parts[] = "{$deletedExpression} IS NULL";
        }

        return ' AND ' . implode(' AND ', $parts);
    }

    private function buildThreadFilterParts(array $filters): array
    {
        $viewerUserId = (int) ($filters['viewer_user_id'] ?? 0);
        $messageCountExpression = $this->threadMessageCountExpression('th', $viewerUserId);
        $unreadCountExpression = $this->threadUnreadCountExpression('th', $viewerUserId);
        $workspaceId = $this->workspaceId();
        $where = ['th.workspace_id = ?', "{$messageCountExpression} > 0"];
        $params = [$workspaceId];
        [$demoSql, $demoParams] = $this->demoEntityClause('conversation_threads', 'th');
        if ($demoSql !== '') {
            $where[] = $demoSql;
            $params = array_merge($params, $demoParams);
        }
        $canViewAllConversations = !empty($filters['can_view_all_conversations']);
        $ownerScope = $this->resolveOwnerScope(
            isset($filters['owner_scope']) ? (string) $filters['owner_scope'] : null,
            $canViewAllConversations
        );

        $ownerScopeClause = $this->buildConversationOwnerScopeClause(
            $ownerScope,
            $viewerUserId,
            'ct.',
            'COALESCE(latest.contact_id, th.contact_id)'
        );
        if ($ownerScopeClause['sql'] !== '') {
            $where[] = $ownerScopeClause['sql'];
            $params = array_merge($params, $ownerScopeClause['params']);
        }

        if (!empty($filters['contact_id'])) {
            $where[] = 'th.contact_id = ?';
            $params[] = (int) $filters['contact_id'];
        }

        if (!empty($filters['unlinked_only'])) {
            $where[] = 'COALESCE(latest.contact_id, th.contact_id) IS NULL';
        }

        if (!empty($filters['channel'])) {
            $channelStateJoin = $this->communicationUserStateJoin('c_filter_channel', 'c_filter_channel_state', $viewerUserId);
            $where[] = "EXISTS (
                SELECT 1
                FROM communications c_filter_channel
                {$channelStateJoin}
                WHERE {$this->buildThreadCommunicationPredicate('th', 'c_filter_channel')}
                  AND c_filter_channel.channel = ?
                  {$this->buildCommunicationScopeClause('c_filter_channel', $viewerUserId, 'c_filter_channel_state')}
            )";
            $params[] = (string) $filters['channel'];
        }

        if (!empty($filters['status'])) {
            if ($filters['status'] === 'unread') {
                $where[] = "{$unreadCountExpression} > 0";
            } elseif ($filters['status'] === 'read') {
                $where[] = "{$unreadCountExpression} = 0";
            }
        }

        if (!empty($filters['triage_priority'])) {
            $where[] = 'th.priority = ?';
            $params[] = (string) $filters['triage_priority'];
        }

        if (!empty($filters['search'])) {
            $search = '%' . (string) $filters['search'] . '%';
            $where[] = '(latest.subject LIKE ? OR latest.from_email LIKE ? OR latest.body LIKE ? OR ct.first_name LIKE ? OR ct.last_name LIKE ?)';
            array_push($params, $search, $search, $search, $search, $search);
        }

        return [
            'whereClause' => 'WHERE ' . implode(' AND ', $where),
            'params' => $params,
        ];
    }

    /**
     * @return array{sql:string, params:array<int, mixed>}
     */
    private function buildConversationOwnerScopeClause(
        string $ownerScope,
        int $viewerUserId,
        string $contactAlias,
        string $contactIdExpression
    ): array {
        if ($ownerScope === self::OWNER_SCOPE_ALL) {
            return ['sql' => '', 'params' => []];
        }

        $unassignedClauses = [
            "{$contactIdExpression} IS NULL",
            "{$contactAlias}id IS NULL",
            "{$contactAlias}assigned_to IS NULL",
            "{$contactAlias}assigned_to = 0",
        ];

        if ($viewerUserId > 0) {
            return [
                'sql' => '((' . implode(' OR ', $unassignedClauses) . ') OR ' . $contactAlias . 'assigned_to = ?)',
                'params' => [$viewerUserId],
            ];
        }

        return [
            'sql' => '(' . implode(' OR ', $unassignedClauses) . ')',
            'params' => [],
        ];
    }
    
    /**
     * Archive communication
     */
    public function archive(int $communicationId): bool
    {
        if ($this->hasPerUserInboxState()) {
            return $this->setCommunicationUserState($communicationId, 'archived_at', true);
        }

        if (!$this->columnExists('communications', 'archived_at')) {
            return false; // Column doesn't exist yet
        }
        
        [$demoSql, $demoParams] = $this->demoEntityClause('communications');
        $sql = "UPDATE communications SET archived_at = NOW() WHERE workspace_id = ? AND id = ? AND archived_at IS NULL";
        $params = [$this->workspaceId(), $communicationId];
        if ($demoSql !== '') {
            $sql .= " AND {$demoSql}";
            $params = array_merge($params, $demoParams);
        }

        return Database::execute($sql, $params) > 0;
    }

    public function archiveThread(int $threadId): bool
    {
        $thread = $this->getThreadById($threadId);
        if (!$thread) {
            return false;
        }

        if ($this->hasPerUserInboxState()) {
            $changed = 0;
            foreach ($this->getThreadCommunicationIds($thread) as $communicationId) {
                $changed += $this->archive($communicationId) ? 1 : 0;
            }
            return $changed > 0;
        }

        if (!$this->columnExists('communications', 'archived_at')) {
            return false;
        }

        [$whereClause, $params] = $this->buildThreadCommunicationWhere($thread);
        Database::execute(
            "UPDATE communications
             SET archived_at = NOW()
             WHERE {$whereClause}
               AND archived_at IS NULL",
            $params
        );

        return true;
    }
    
    /**
     * Unarchive communication
     */
    public function unarchive(int $communicationId): bool
    {
        if ($this->hasPerUserInboxState()) {
            return $this->setCommunicationUserState($communicationId, 'archived_at', false);
        }

        if (!$this->columnExists('communications', 'archived_at')) {
            return false; // Column doesn't exist yet
        }
        
        [$demoSql, $demoParams] = $this->demoEntityClause('communications');
        $sql = "UPDATE communications SET archived_at = NULL WHERE workspace_id = ? AND id = ?";
        $params = [$this->workspaceId(), $communicationId];
        if ($demoSql !== '') {
            $sql .= " AND {$demoSql}";
            $params = array_merge($params, $demoParams);
        }

        return Database::execute($sql, $params) > 0;
    }
    
    /**
     * Delete communication (soft delete)
     */
    public function delete(int $communicationId): bool
    {
        if ($this->hasPerUserInboxState()) {
            return $this->setCommunicationUserState($communicationId, 'deleted_at', true);
        }

        if (!$this->columnExists('communications', 'deleted_at')) {
            return false; // Column doesn't exist yet
        }
        
        [$demoSql, $demoParams] = $this->demoEntityClause('communications');
        $sql = "UPDATE communications SET deleted_at = NOW() WHERE workspace_id = ? AND id = ? AND deleted_at IS NULL";
        $params = [$this->workspaceId(), $communicationId];
        if ($demoSql !== '') {
            $sql .= " AND {$demoSql}";
            $params = array_merge($params, $demoParams);
        }

        return Database::execute($sql, $params) > 0;
    }
    
    /**
     * Restore deleted communication
     */
    public function restore(int $communicationId): bool
    {
        if ($this->hasPerUserInboxState()) {
            return $this->setCommunicationUserState($communicationId, 'deleted_at', false);
        }

        if (!$this->columnExists('communications', 'deleted_at')) {
            return false; // Column doesn't exist yet
        }
        
        [$demoSql, $demoParams] = $this->demoEntityClause('communications');
        $sql = "UPDATE communications SET deleted_at = NULL WHERE workspace_id = ? AND id = ?";
        $params = [$this->workspaceId(), $communicationId];
        if ($demoSql !== '') {
            $sql .= " AND {$demoSql}";
            $params = array_merge($params, $demoParams);
        }

        return Database::execute($sql, $params) > 0;
    }
    
    /**
     * Bulk archive communications
     */
    public function bulkArchive(array $communicationIds): int
    {
        if ($this->hasPerUserInboxState()) {
            $changed = 0;
            foreach ($communicationIds as $communicationId) {
                $changed += $this->archive((int) $communicationId) ? 1 : 0;
            }
            return $changed;
        }

        if (empty($communicationIds) || !$this->columnExists('communications', 'archived_at')) {
            return 0;
        }
        
        $placeholders = implode(',', array_fill(0, count($communicationIds), '?'));
        [$demoSql, $demoParams] = $this->demoEntityClause('communications');
        $sql = "UPDATE communications SET archived_at = NOW() WHERE workspace_id = ? AND id IN ($placeholders) AND archived_at IS NULL";
        $params = array_merge([$this->workspaceId()], $communicationIds);
        if ($demoSql !== '') {
            $sql .= " AND {$demoSql}";
            $params = array_merge($params, $demoParams);
        }
        return Database::execute($sql, $params);
    }
    
    /**
     * Bulk delete communications
     */
    public function bulkDelete(array $communicationIds): int
    {
        if ($this->hasPerUserInboxState()) {
            $changed = 0;
            foreach ($communicationIds as $communicationId) {
                $changed += $this->delete((int) $communicationId) ? 1 : 0;
            }
            return $changed;
        }

        if (empty($communicationIds) || !$this->columnExists('communications', 'deleted_at')) {
            return 0;
        }
        
        $placeholders = implode(',', array_fill(0, count($communicationIds), '?'));
        [$demoSql, $demoParams] = $this->demoEntityClause('communications');
        $sql = "UPDATE communications SET deleted_at = NOW() WHERE workspace_id = ? AND id IN ($placeholders) AND deleted_at IS NULL";
        $params = array_merge([$this->workspaceId()], $communicationIds);
        if ($demoSql !== '') {
            $sql .= " AND {$demoSql}";
            $params = array_merge($params, $demoParams);
        }
        return Database::execute($sql, $params);
    }

    private function hasPerUserInboxState(): bool
    {
        return $this->currentUserId() > 0 && $this->tableExists('communication_user_state');
    }

    private function currentUserId(): int
    {
        return (int) ($_SESSION['user_id'] ?? 0);
    }

    private function effectiveUserStateExpression(string $column, string $communicationAlias, string $stateAlias): string
    {
        return "CASE WHEN {$stateAlias}.user_id IS NULL THEN {$communicationAlias}.{$column} ELSE {$stateAlias}.{$column} END";
    }

    private function communicationUserStateJoin(string $communicationAlias, string $stateAlias, int $viewerUserId): string
    {
        if ($viewerUserId <= 0 || !$this->tableExists('communication_user_state')) {
            return '';
        }

        return "LEFT JOIN communication_user_state {$stateAlias}
                ON {$stateAlias}.workspace_id = {$communicationAlias}.workspace_id
               AND {$stateAlias}.communication_id = {$communicationAlias}.id
               AND {$stateAlias}.user_id = " . (int) $viewerUserId;
    }

    private function setCommunicationUserState(int $communicationId, string $field, bool $setTimestamp): bool
    {
        if (!in_array($field, ['read_at', 'archived_at', 'deleted_at'], true)) {
            throw new \InvalidArgumentException('Unsupported inbox state field.');
        }

        $row = $this->getCommunicationStateForCurrentUser($communicationId);
        if (!$row) {
            return false;
        }

        $hasUserState = $row['state_user_id'] !== null;
        $effectiveValue = $hasUserState
            ? ($row['state_' . $field] ?? null)
            : ($row['global_' . $field] ?? null);
        if ($setTimestamp && $effectiveValue !== null) {
            return false;
        }
        if (!$setTimestamp && $effectiveValue === null) {
            return false;
        }

        $newValues = [
            'read_at' => $hasUserState ? ($row['state_read_at'] ?? null) : ($row['global_read_at'] ?? null),
            'archived_at' => $hasUserState ? ($row['state_archived_at'] ?? null) : ($row['global_archived_at'] ?? null),
            'deleted_at' => $hasUserState ? ($row['state_deleted_at'] ?? null) : ($row['global_deleted_at'] ?? null),
        ];
        $newValues[$field] = $setTimestamp ? date('Y-m-d H:i:s') : null;

        Database::execute(
            "INSERT INTO communication_user_state (workspace_id, communication_id, user_id, read_at, archived_at, deleted_at, created_at, updated_at)
             SELECT c.workspace_id, c.id, ?, ?, ?, ?, NOW(), NOW()
             FROM communications c
             WHERE c.workspace_id = ? AND c.id = ?
             ON DUPLICATE KEY UPDATE read_at = VALUES(read_at), archived_at = VALUES(archived_at), deleted_at = VALUES(deleted_at), updated_at = NOW()",
            [
                $this->currentUserId(),
                $newValues['read_at'],
                $newValues['archived_at'],
                $newValues['deleted_at'],
                $this->workspaceId(),
                $communicationId,
            ]
        );

        return true;
    }

    private function getCommunicationStateForCurrentUser(int $communicationId): ?array
    {
        $archivedColumn = $this->columnExists('communications', 'archived_at') ? 'c.archived_at' : 'NULL';
        $deletedColumn = $this->columnExists('communications', 'deleted_at') ? 'c.deleted_at' : 'NULL';

        return Database::queryOne(
            "SELECT c.id,
                    c.read_at AS global_read_at,
                    {$archivedColumn} AS global_archived_at,
                    {$deletedColumn} AS global_deleted_at,
                    cus.user_id AS state_user_id,
                    cus.read_at AS state_read_at,
                    cus.archived_at AS state_archived_at,
                    cus.deleted_at AS state_deleted_at
             FROM communications c
             LEFT JOIN communication_user_state cus
                ON cus.workspace_id = c.workspace_id
               AND cus.communication_id = c.id
               AND cus.user_id = ?
             WHERE c.workspace_id = ? AND c.id = ?
               {$this->demoEntityClauseSql('communications', 'c')}
             LIMIT 1",
            [$this->currentUserId(), $this->workspaceId(), $communicationId]
        ) ?: null;
    }

    /**
     * @return list<int>
     */
    private function getThreadCommunicationIds(array $thread): array
    {
        [$whereClause, $params] = $this->buildThreadCommunicationWhere($thread);
        $rows = Database::query(
            "SELECT id FROM communications WHERE {$whereClause} ORDER BY created_at DESC, id DESC",
            $params
        );

        return array_map(static fn(array $row): int => (int) $row['id'], $rows);
    }

    private function workspaceId(): int
    {
        return $this->workspaceScope->requireActiveWorkspaceId();
    }

    /**
     * @return array{0:string,1:array<int,mixed>}
     */
    private function demoEntityClause(string $table, string $alias = ''): array
    {
        if (!Database::columnExists($table, 'demo_visibility')) {
            return ['', []];
        }

        $clause = $this->demoScope->entityOwnershipClause($table, $alias);
        return [$clause['sql'], $clause['params']];
    }

    private function demoEntityClauseSql(string $table, string $alias = ''): string
    {
        [$sql, $params] = $this->demoEntityClause($table, $alias);
        if ($sql === '') {
            return '';
        }

        foreach ($params as $param) {
            $sql = preg_replace('/\?/', (string) (int) $param, $sql, 1) ?? $sql;
        }

        return ' AND ' . $sql;
    }
}
