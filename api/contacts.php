<?php
/**
 * Contacts API Endpoint
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
use CRM\Concurrency;
use CRM\ConcurrencyConflictException;
use CRM\Database;
use CRM\Session;
use CRM\Modules\Contacts;
use CRM\Modules\Tags;
use CRM\Security;
use CRM\Services\ContactIntelligenceService;
use CRM\Services\DemoSessionScopeService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceScopeService;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

header('Content-Type: application/json');
Auth::requireAuth();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$contacts = new Contacts();
$tags = new Tags();
$contactIntelligenceService = new ContactIntelligenceService();
$user = Auth::user();
$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
$isProtectedDemoContactsApi = false;
try {
    $isProtectedDemoContactsApi = (new DemoSessionScopeService())->activeSession($workspaceId) !== null;
} catch (\Throwable $e) {
    $isProtectedDemoContactsApi = false;
}

function requireContactsApiCsrf(?array $jsonInput = null): bool
{
    $csrfToken = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($jsonInput['csrf_token'] ?? '');
    if (Security::validateCSRF((string) $csrfToken)) {
        return true;
    }

    http_response_code(403);
    echo json_encode(['error' => 'Invalid CSRF token']);
    return false;
}

function buildContactListQualitySignals(array $contact, ContactIntelligenceService $service, ?array $storedIntelligence = null): array
{
    $intel = $storedIntelligence ?? $service->getStoredOrCompute((int) ($contact['id'] ?? 0)) ?? [];
    if ($intel === []) {
        $missing = [];
        foreach (['phone', 'company', 'job_title', 'location'] as $field) {
            if (empty($contact[$field])) {
                $missing[] = $field;
            }
        }

        return [
            'health_score' => 0,
            'health_band' => 'unknown',
            'flags' => count($missing) >= 2 ? [['label' => 'Missing fields', 'class' => 'missing']] : [],
            'missing_critical_fields' => $missing,
            'duplicate_count' => 0,
        ];
    }

    $quality = $intel['data_quality'] ?? [];
    $health = $intel['relationship_health'] ?? [];
    $flags = [];

    if (!empty($quality['is_stale'])) {
        $flags[] = ['label' => 'Stale', 'class' => 'stale'];
    }
    if (!empty($quality['is_incomplete'])) {
        $flags[] = ['label' => 'Incomplete', 'class' => 'incomplete'];
    }
    $duplicateCount = count($quality['likely_duplicates'] ?? []);
    if ($duplicateCount > 0) {
        $flags[] = [
            'label' => $duplicateCount . ' duplicate' . ($duplicateCount === 1 ? '' : 's'),
            'class' => 'duplicate',
            'title' => 'Likely duplicate candidates found from matching contact details. Open the Review Queue to inspect and merge.',
        ];
    }
    if (!empty($quality['missing_critical_fields'])) {
        $flags[] = ['label' => 'Missing fields', 'class' => 'missing'];
    }

    return [
        'health_score' => (int) ($health['score'] ?? 0),
        'health_band' => (string) ($health['band'] ?? 'unknown'),
        'flags' => $flags,
        'missing_critical_fields' => array_values($quality['missing_critical_fields'] ?? []),
        'duplicate_count' => $duplicateCount,
    ];
}

function buildContactStageCounts(Contacts $contactsModule, string $ownerScope, int $userId): array
{
    $workspaceClause = (new WorkspaceScopeService())->workspaceClause('c.');
    $stageCountSql = "SELECT c.stage, COUNT(*) AS count FROM contacts c WHERE {$workspaceClause['sql']}";
    $stageCountParams = $workspaceClause['params'];
    $ownerScopeClause = $contactsModule->buildOwnerScopeClause($ownerScope, $userId, 'c.');

    if ($ownerScopeClause['sql'] !== '') {
        $stageCountSql .= " AND " . $ownerScopeClause['sql'];
        $stageCountParams = array_merge($stageCountParams, $ownerScopeClause['params']);
    }

    $stageCountSql .= " GROUP BY c.stage";
    $stageCounts = [];
    foreach (Database::query($stageCountSql, $stageCountParams) as $stat) {
        $stage = (string) ($stat['stage'] ?? '');
        if ($stage !== '') {
            $stageCounts[$stage] = (int) ($stat['count'] ?? 0);
        }
    }

    return $stageCounts;
}

function contactsApiCanAccessRecord(Contacts $contacts, ?array $contact, array $user): bool
{
    return $contact !== null && $contacts->isVisibleToUser(
        $contact,
        (int) ($user['id'] ?? 0),
        Authorization::can('contacts.view_all', $user)
    );
}

try {
    switch ($method) {
        case 'GET':
            Session::closeWrite();
            $id = $_GET['id'] ?? null;
            $uuid = $_GET['uuid'] ?? null;
            $search = $_GET['search'] ?? null;
            $stage = $_GET['stage'] ?? null;
            $tagId = !empty($_GET['tag']) ? (int) $_GET['tag'] : null;
            $ownerScope = (string) ($_GET['owner_scope'] ?? 'mine_unassigned');
            if (!in_array($ownerScope, ['mine_unassigned', 'all'], true)) {
                $ownerScope = 'mine_unassigned';
            }
            if ($ownerScope === 'all' && !Authorization::can('contacts.view_all', $user)) {
                $ownerScope = 'mine_unassigned';
            }
            $limit = (int) ($_GET['limit'] ?? 50);
            $offset = (int) ($_GET['offset'] ?? 0);
            $listWithFilters = isset($_GET['list']) || isset($_GET['search']) || isset($_GET['stage']) || isset($_GET['tag']);
            
            if ($listWithFilters) {
                // Full contacts list with filters (async)
                $page = max(1, (int) ($_GET['page'] ?? 1));
                $limit = min(500, max(1, (int) ($_GET['limit'] ?? 200)));
                $offset = ($page - 1) * $limit;
                
                if ($tagId) {
                    $results = $contacts->getByTag($tagId, $limit, $offset, $ownerScope, (int) ($user['id'] ?? 0));
                    $total = $contacts->countByTag($tagId, $ownerScope, (int) ($user['id'] ?? 0));
                } elseif ($search) {
                    $results = $contacts->search($search, $limit, $offset, $ownerScope, (int) ($user['id'] ?? 0));
                    $total = $contacts->countSearch($search, $ownerScope, (int) ($user['id'] ?? 0));
                } elseif ($stage) {
                    $results = $contacts->getAll($limit, $offset, $stage, $ownerScope, (int) ($user['id'] ?? 0));
                    $total = $contacts->countAll($stage, $ownerScope, (int) ($user['id'] ?? 0));
                } else {
                    $results = $contacts->getAll($limit, $offset, null, $ownerScope, (int) ($user['id'] ?? 0));
                    $total = $contacts->countAll(null, $ownerScope, (int) ($user['id'] ?? 0));
                }
                
                $contactIdsOnPage = array_map(static fn (array $contact): int => (int) ($contact['id'] ?? 0), $results);
                $contactTagsById = $tags->getTagsForEntities('contact', $contactIdsOnPage);
                $contactIntelligenceById = $contactIntelligenceService->getStoredForContactIds($contactIdsOnPage);

                foreach ($results as &$c) {
                    $contactId = (int) ($c['id'] ?? 0);
                    $c['_tags'] = $contactTagsById[$contactId] ?? [];
                    $c['_quality_signals'] = buildContactListQualitySignals($c, $contactIntelligenceService, $contactIntelligenceById[$contactId] ?? []);
                    if ($isProtectedDemoContactsApi) {
                        $c['_quality_signals'] = [
                            'health_score' => max(88, (int) ($c['_quality_signals']['health_score'] ?? 0)),
                            'health_band' => 'healthy',
                            'flags' => [],
                            'missing_critical_fields' => [],
                            'duplicate_count' => 0,
                        ];
                    }
                }
                unset($c);
                
                $totalPages = (int) ceil($total / $limit);
                
                echo json_encode([
                    'contacts' => $results,
                    'total' => $total,
                    'page' => $page,
                    'total_pages' => $totalPages,
                    'limit' => $limit,
                    'offset' => $offset,
                    'stage_counts' => buildContactStageCounts($contacts, $ownerScope, (int) ($user['id'] ?? 0)),
                ], JSON_PRETTY_PRINT);
            } elseif ($id) {
                $contact = $contacts->getById((int) $id);
                if (!contactsApiCanAccessRecord($contacts, $contact, $user)) {
                    http_response_code(404);
                    echo json_encode(['error' => 'Contact not found'], JSON_PRETTY_PRINT);
                    break;
                }
                echo json_encode($contact, JSON_PRETTY_PRINT);
            } elseif ($uuid) {
                $contact = $contacts->getByUuid($uuid);
                if (!contactsApiCanAccessRecord($contacts, $contact, $user)) {
                    http_response_code(404);
                    echo json_encode(['error' => 'Contact not found'], JSON_PRETTY_PRINT);
                    break;
                }
                echo json_encode($contact, JSON_PRETTY_PRINT);
            } elseif ($search) {
                $results = $contacts->search($search, $limit, $offset, $ownerScope, (int) ($user['id'] ?? 0));
                echo json_encode(['results' => $results], JSON_PRETTY_PRINT);
            } else {
                $results = $contacts->getAll($limit, $offset, $stage, $ownerScope, (int) ($user['id'] ?? 0));
                echo json_encode(['results' => $results], JSON_PRETTY_PRINT);
            }
            break;
            
        case 'POST':
            // Verify CSRF token
            $data = json_decode(file_get_contents('php://input'), true) ?: $_POST;
            if (!requireContactsApiCsrf(is_array($data) ? $data : null)) {
                break;
            }

            $data['created_by'] = (int) ($user['id'] ?? 0);
            $result = $contacts->create($data);
            http_response_code($result['status'] === 'success' ? 201 : 200);
            echo json_encode($result, JSON_PRETTY_PRINT);
            break;
            
        case 'PUT':
        case 'PATCH':
            $data = json_decode(file_get_contents('php://input'), true);
            if (!requireContactsApiCsrf(is_array($data) ? $data : null)) {
                break;
            }
            $id = $_GET['id'] ?? $data['id'] ?? null;
            
            if (!$id) {
                http_response_code(400);
                echo json_encode(['error' => 'Contact ID required']);
                break;
            }

            $contact = $contacts->getById((int) $id);
            if (!contactsApiCanAccessRecord($contacts, $contact, $user)) {
                http_response_code(404);
                echo json_encode(['error' => 'Contact not found'], JSON_PRETTY_PRINT);
                break;
            }
            
            $result = $contacts->update((int) $id, $data);
            echo json_encode(['success' => $result], JSON_PRETTY_PRINT);
            break;
            
        case 'DELETE':
            if (!requireContactsApiCsrf()) {
                break;
            }
            $id = $_GET['id'] ?? null;
            if (!$id) {
                http_response_code(400);
                echo json_encode(['error' => 'Contact ID required']);
                break;
            }
            $contact = $contacts->getById((int) $id);
            if (!contactsApiCanAccessRecord($contacts, $contact, $user)) {
                http_response_code(404);
                echo json_encode(['error' => 'Contact not found'], JSON_PRETTY_PRINT);
                break;
            }
            
            $result = $contacts->delete((int) $id);
            http_response_code($result ? 200 : 404);
            echo json_encode(['success' => $result], JSON_PRETTY_PRINT);
            break;
            
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Method not allowed']);
    }
} catch (ConcurrencyConflictException $e) {
    http_response_code(409);
    echo json_encode(Concurrency::conflictPayload($e), JSON_PRETTY_PRINT);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()], JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    error_log('Contacts API request failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'The contact request could not be completed.'], JSON_PRETTY_PRINT);
}
