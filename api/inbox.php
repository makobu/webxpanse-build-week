<?php
/**
 * Inbox API Endpoint
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Load environment (index.php routing returns 404 for API paths - use bootstrap instead)
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}
require_once __DIR__ . '/../config/constants.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Session;
use CRM\Modules\Contacts;
use CRM\Modules\UnifiedInbox;
use CRM\Modules\ConversationThreads;
use CRM\Services\GuidedDemoSessionService;
use CRM\Services\DemoSessionScopeService;
use CRM\Services\WorkspaceCommunicationGateService;
use CRM\Services\WorkspaceContext;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

header('Content-Type: application/json');
Auth::requireAuth();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$inbox = new UnifiedInbox();
$threads = new ConversationThreads();
$contacts = new Contacts();
$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);
$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
$demoScope = new DemoSessionScopeService();
$canViewAllConversations = $demoScope->forceOwnerScopeAllAllowed(Authorization::can('conversations.view_all', $user));
$demoCommunicationClause = static function () use ($demoScope, $workspaceId): array {
    return $demoScope->entityOwnershipClause('communications', '', $workspaceId);
};
$preflightPostData = null;
if (($method === 'POST') && isset($_GET['action'])) {
    $rawInput = file_get_contents('php://input');
    $decodedInput = is_string($rawInput) && trim($rawInput) !== '' ? json_decode($rawInput, true) : null;
    $preflightPostData = is_array($decodedInput) ? $decodedInput : $_POST;
    $preflightIds = [];
    if (isset($preflightPostData['id'])) {
        $preflightIds = is_array($preflightPostData['id']) ? array_map('intval', $preflightPostData['id']) : [(int) $preflightPostData['id']];
    } elseif (isset($preflightPostData['ids'])) {
        $preflightIds = is_array($preflightPostData['ids']) ? array_map('intval', $preflightPostData['ids']) : [(int) $preflightPostData['ids']];
    } elseif (isset($_GET['id'])) {
        $preflightIds = [(int) $_GET['id']];
    }
    $preflightIds = array_values(array_filter($preflightIds, static fn(int $id): bool => $id > 0));
    if ($preflightIds !== []) {
        $placeholders = implode(',', array_fill(0, count($preflightIds), '?'));
        $scope = $demoCommunicationClause();
        $scopeSql = $scope['sql'] !== '' ? ' AND ' . $scope['sql'] : '';
        $found = (int) ((Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM communications
             WHERE workspace_id = ?
               AND id IN ({$placeholders})
               {$scopeSql}",
            array_merge([$workspaceId], $preflightIds, $scope['params'])
        ) ?: [])['c'] ?? 0);
        if ($found < count(array_unique($preflightIds))) {
            http_response_code(404);
            echo json_encode(['error' => 'Conversation not found.']);
            exit;
        }
    }
}
$guidedDemoInboxPreview = $method === 'GET'
    && (string) ($_GET['guided_demo'] ?? '') === '1'
    && (new GuidedDemoSessionService())->activeSession($workspaceId, $userId) !== null;
$communicationGate = new WorkspaceCommunicationGateService();
if ($method !== 'GET' && !$guidedDemoInboxPreview && !$communicationGate->isRuntimeReady($workspaceId, $user)) {
    http_response_code(403);
    echo json_encode($communicationGate->jsonBlockPayload($workspaceId, $user), JSON_UNESCAPED_SLASHES);
    exit;
}

$buildOwnerScope = static function (?string $requestedScope) use ($inbox, $canViewAllConversations): string {
    return $inbox->resolveOwnerScope($requestedScope, $canViewAllConversations);
};

$sortContacts = static function (array $rows): array {
    usort($rows, static function (array $left, array $right): int {
        $leftLabel = trim((string) (($left['first_name'] ?? '') . ' ' . ($left['last_name'] ?? '')));
        $rightLabel = trim((string) (($right['first_name'] ?? '') . ' ' . ($right['last_name'] ?? '')));
        return strcasecmp($leftLabel, $rightLabel);
    });

    return $rows;
};

$serializeContacts = static function (array $rows): array {
    return array_map(static function (array $row): array {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'first_name' => (string) ($row['first_name'] ?? ''),
            'last_name' => (string) ($row['last_name'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
        ];
    }, $rows);
};

try {
    switch ($method) {
        case 'GET':
            Session::closeWrite();
            $contactId = $_GET['contact_id'] ?? null;
            $channel = $_GET['channel'] ?? null;
            $unread = isset($_GET['unread']) && $_GET['unread'] === 'true';
            $limit = (int) ($_GET['limit'] ?? 50);
            $offset = (int) ($_GET['offset'] ?? 0);
            $ownerScope = $buildOwnerScope($_GET['owner_scope'] ?? null);
            $listWithFilters = isset($_GET['list']) || isset($_GET['search']) || isset($_GET['status']) || isset($_GET['triage_priority']) || isset($_GET['triage_status']);
            $normalizeTriage = static function (array $rows): array {
                foreach ($rows as &$row) {
                    if (array_key_exists('triage_reason_codes', $row)) {
                        if (is_string($row['triage_reason_codes']) && $row['triage_reason_codes'] !== '') {
                            $decoded = json_decode($row['triage_reason_codes'], true);
                            $row['triage_reason_codes'] = is_array($decoded) ? array_values($decoded) : [];
                        } elseif (!is_array($row['triage_reason_codes'])) {
                            $row['triage_reason_codes'] = [];
                        }
                    } else {
                        $row['triage_reason_codes'] = [];
                    }
                    if (array_key_exists('thread_metadata_json', $row)) {
                        if (is_string($row['thread_metadata_json']) && $row['thread_metadata_json'] !== '') {
                            $decodedThread = json_decode($row['thread_metadata_json'], true);
                            $row['thread_metadata_json'] = is_array($decodedThread) ? $decodedThread : [];
                        } elseif (!is_array($row['thread_metadata_json'])) {
                            $row['thread_metadata_json'] = [];
                        }
                    } else {
                        $row['thread_metadata_json'] = [];
                    }
                }
                unset($row);
                return $rows;
            };
            
            if ($listWithFilters) {
                // Full inbox list with filters (async)
                $search = $_GET['search'] ?? '';
                $status = $_GET['status'] ?? 'unread';
                $contactIdFilter = (int) ($_GET['contact_id'] ?? 0);
                $channelFilter = $_GET['channel'] ?? '';
                $triagePriority = $_GET['triage_priority'] ?? '';
                $triageStatus = $_GET['triage_status'] ?? '';
                $includeBodySearch = isset($_GET['include_body_search']) && $_GET['include_body_search'] === '1';
                $page = max(1, (int) ($_GET['page'] ?? 1));
                $limit = min(100, max(1, (int) ($_GET['limit'] ?? 30)));
                $offset = ($page - 1) * $limit;
                
                $filters = [
                    'channel' => $channelFilter ?: null,
                    'contact_id' => $contactIdFilter ?: null,
                    'search' => $search ?: null,
                    'include_body_search' => $includeBodySearch,
                    'triage_priority' => $triagePriority ?: null,
                    'triage_status' => $triageStatus ?: null,
                    'viewer_user_id' => $userId,
                    'can_view_all_conversations' => $canViewAllConversations,
                    'owner_scope' => $ownerScope,
                ];
                if ($status === 'archived') {
                    $filters['archived'] = true;
                } elseif ($status !== 'all') {
                    $filters['status'] = $status;
                }
                
                $communications = $normalizeTriage($inbox->getAll($limit, $offset, $filters));
                $total = $inbox->getCount($filters);
                $totalPages = (int) ceil($total / $limit);
                $channelStatsData = $inbox->getChannelStats($filters);
                $contactOptions = $serializeContacts($sortContacts($contacts->getAll(500, 0, null, $ownerScope, $userId)));
                
                echo json_encode([
                    'communications' => $communications,
                    'contacts' => $contactOptions,
                    'channel_stats' => $channelStatsData['counts'] ?? [],
                    'unread_stats' => $channelStatsData['unread'] ?? [],
                    'owner_scope' => $ownerScope,
                    'total' => $total,
                    'page' => $page,
                    'total_pages' => $totalPages,
                    'limit' => $limit,
                    'offset' => $offset
                ], JSON_PRETTY_PRINT);
            } elseif ($unread) {
                $filters = [
                    'status' => 'unread',
                    'contact_id' => $contactId ? (int) $contactId : null,
                    'viewer_user_id' => $userId,
                    'can_view_all_conversations' => $canViewAllConversations,
                    'owner_scope' => $ownerScope,
                ];
                $results = $normalizeTriage($inbox->getAll($limit, 0, $filters));
                echo json_encode([
                    'communications' => $results,
                    'owner_scope' => $ownerScope,
                ], JSON_PRETTY_PRINT);
            } elseif ($contactId) {
                $filters = [
                    'contact_id' => (int) $contactId,
                    'viewer_user_id' => $userId,
                    'can_view_all_conversations' => $canViewAllConversations,
                    'owner_scope' => $ownerScope,
                ];
                if (isset($_GET['threads']) && $_GET['threads'] === 'true') {
                    $results = $normalizeTriage($inbox->getThreadSummaries($limit, $offset, $filters));
                    echo json_encode([
                        'threads' => $results,
                        'owner_scope' => $ownerScope,
                    ], JSON_PRETTY_PRINT);
                } else {
                    $results = $normalizeTriage($inbox->getAll($limit, $offset, $filters));
                    echo json_encode([
                        'communications' => $results,
                        'owner_scope' => $ownerScope,
                    ], JSON_PRETTY_PRINT);
                }
            } elseif ($channel) {
                $filters = [
                    'channel' => (string) $channel,
                    'viewer_user_id' => $userId,
                    'can_view_all_conversations' => $canViewAllConversations,
                    'owner_scope' => $ownerScope,
                ];
                $results = $normalizeTriage($inbox->getAll($limit, $offset, $filters));
                echo json_encode([
                    'communications' => $results,
                    'owner_scope' => $ownerScope,
                ], JSON_PRETTY_PRINT);
            } elseif (isset($_GET['summary'])) {
                $filters = [
                    'viewer_user_id' => $userId,
                    'can_view_all_conversations' => $canViewAllConversations,
                    'owner_scope' => $ownerScope,
                ];
                $channelStats = $inbox->getChannelStats($filters);
                $summary = [
                    'total' => $inbox->getCount($filters),
                    'unread' => $inbox->getCount(array_merge($filters, ['status' => 'unread'])),
                    'channels' => $channelStats['counts'] ?? [],
                    'unread_by_channel' => $channelStats['unread'] ?? [],
                ];
                echo json_encode([
                    'summary' => $summary,
                    'owner_scope' => $ownerScope,
                ], JSON_PRETTY_PRINT);
            } else {
                $filters = [
                    'viewer_user_id' => $userId,
                    'can_view_all_conversations' => $canViewAllConversations,
                    'owner_scope' => $ownerScope,
                ];
                $results = $normalizeTriage($inbox->getThreadSummaries($limit, $offset, $filters));
                echo json_encode([
                    'threads' => $results,
                    'owner_scope' => $ownerScope,
                ], JSON_PRETTY_PRINT);
            }
            break;
            
        case 'POST':
            $action = $_GET['action'] ?? $_POST['action'] ?? null;
            $data = $preflightPostData ?? (json_decode(file_get_contents('php://input'), true) ?? $_POST);
            $ownerScope = $buildOwnerScope($data['owner_scope'] ?? $_GET['owner_scope'] ?? null);
            
            if (!$action) {
                http_response_code(400);
                echo json_encode(['error' => 'Action parameter required']);
                break;
            }
            
            // Get IDs (single or array)
            $ids = [];
            if (isset($data['id'])) {
                $ids = is_array($data['id']) ? $data['id'] : [(int) $data['id']];
            } elseif (isset($data['ids'])) {
                $ids = is_array($data['ids']) ? array_map('intval', $data['ids']) : [(int) $data['ids']];
            } elseif (isset($_GET['id'])) {
                $ids = [(int) $_GET['id']];
            }
            
            if (empty($ids)) {
                http_response_code(400);
                echo json_encode(['error' => 'ID(s) required']);
                break;
            }

            $workspaceIdForAction = $workspaceId;
            $applyCommunicationAction = static function (int $id, string $actionName) use (
                $inbox,
                $userId,
                $canViewAllConversations,
                $ownerScope,
                $workspaceIdForAction,
                $demoCommunicationClause
            ): string {
                if ($id <= 0) {
                    return 'not_found';
                }
                $scope = $demoCommunicationClause();
                $scopeSql = $scope['sql'] !== '' ? ' AND ' . $scope['sql'] : '';
                $exists = Database::queryOne(
                    "SELECT id
                     FROM communications
                     WHERE workspace_id = ?
                       AND id = ?
                       {$scopeSql}
                     LIMIT 1",
                    array_merge([$workspaceIdForAction, $id], $scope['params'])
                );
                if (!$exists) {
                    return 'not_found';
                }
                if (!$inbox->canUserAccessCommunication($id, $userId, $canViewAllConversations, $ownerScope)) {
                    return 'forbidden';
                }

                $changed = match ($actionName) {
                    'mark_read' => $inbox->markAsRead($id),
                    'mark_unread' => $inbox->markAsUnread($id),
                    'archive' => $inbox->archive($id),
                    'unarchive' => $inbox->unarchive($id),
                    'delete' => $inbox->delete($id),
                    'restore' => $inbox->restore($id),
                    default => null,
                };

                return $changed === true ? 'changed' : 'already_current';
            };
            
            switch ($action) {
                case 'mark_read':
                case 'mark_unread':
                case 'archive':
                case 'unarchive':
                case 'delete':
                case 'restore':
                    $statuses = [];
                    $count = 0;
                    foreach ($ids as $id) {
                        $status = $applyCommunicationAction((int) $id, $action);
                        $statuses[(string) (int) $id] = $status;
                        if ($status === 'changed') {
                            $count++;
                        }
                    }
                    echo json_encode([
                        'success' => true,
                        'count' => $count,
                        'statuses' => $statuses,
                    ], JSON_PRETTY_PRINT);
                    break;
                    
                default:
                    http_response_code(400);
                    echo json_encode(['error' => 'Invalid action']);
            }
            break;
            
        case 'PUT':
        case 'PATCH':
            $data = json_decode(file_get_contents('php://input'), true);
            $id = $_GET['id'] ?? $data['id'] ?? null;
            $ownerScope = $buildOwnerScope($data['owner_scope'] ?? $_GET['owner_scope'] ?? null);
            
            if ($id && !$inbox->canUserAccessCommunication((int) $id, $userId, $canViewAllConversations, $ownerScope)) {
                http_response_code(404);
                echo json_encode(['error' => 'Conversation not found.']);
            } elseif (isset($data['read']) && $data['read'] === true) {
                $inbox->markAsRead((int) $id);
                echo json_encode(['success' => true], JSON_PRETTY_PRINT);
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'Invalid action']);
            }
            break;
            
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_PRETTY_PRINT);
}
