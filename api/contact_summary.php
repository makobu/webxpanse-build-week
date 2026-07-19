<?php
/**
 * Contact Summary API
 * Generates AI summary for a contact using AIContactSummarizer
 */

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/constants.php';
use CRM\Database;
use CRM\Session;
use CRM\Auth;
use CRM\Modules\AIContactSummarizer;
use CRM\Services\WorkspaceScopeService;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$contactId = (int) ($_GET['contact_id'] ?? $_POST['contact_id'] ?? 0);
if (!$contactId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'contact_id required']);
    exit;
}

try {
    $summarizer = new AIContactSummarizer();
    $result = $summarizer->generateSummary($contactId);
    $summary = trim((string) ($result['summary'] ?? ''));
    if ($summary === '') {
        throw new \RuntimeException('AI returned an empty summary');
    }

    echo json_encode([
        'success' => true,
        'summary' => $summary,
        'last_updated' => $result['last_updated'] ?? time(),
        'used_fallback' => false
    ]);
} catch (\Exception $e) {
    error_log('Contact summary API error: ' . $e->getMessage());
    try {
        $fallback = buildFallbackContactSummary($contactId);
        echo json_encode([
            'success' => true,
            'summary' => $fallback['summary'],
            'last_updated' => $fallback['last_updated'],
            'used_fallback' => true,
            'fallback_reason' => 'ai_unavailable_or_failed'
        ]);
    } catch (\Throwable $fallbackError) {
        $status = stripos((string) $fallbackError->getMessage(), 'not found') !== false ? 404 : 500;
        http_response_code($status);
        echo json_encode([
            'success' => false,
            'error' => $status === 404 ? 'Contact not found.' : $fallbackError->getMessage()
        ]);
    }
}

function buildFallbackContactSummary(int $contactId): array
{
    $workspaceScope = new WorkspaceScopeService();
    $contactWorkspace = $workspaceScope->workspaceClause();
    $activityWorkspace = $workspaceScope->workspaceClause('a.');

    $contact = Database::queryOne(
        "SELECT first_name, last_name, email, company, stage, created_at, updated_at
         FROM contacts
         WHERE {$contactWorkspace['sql']}
           AND id = ?",
        array_merge($contactWorkspace['params'], [$contactId])
    );

    if (!$contact) {
        throw new \RuntimeException('Contact not found');
    }

    $name = trim((string) (($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? '')));
    if ($name === '') {
        $name = $contact['email'] ?? 'This contact';
    }

    $activitySummary = [
        'count' => 0,
        'last_activity_at' => null,
    ];
    try {
        $activityRow = Database::queryOne(
            "SELECT COUNT(*) AS count, MAX(a.created_at) AS last_activity_at
             FROM activities a
             WHERE {$activityWorkspace['sql']}
               AND a.contact_id = ?",
            array_merge($activityWorkspace['params'], [$contactId])
        );
        if ($activityRow) {
            $activitySummary['count'] = (int) ($activityRow['count'] ?? 0);
            $activitySummary['last_activity_at'] = $activityRow['last_activity_at'] ?? null;
        }
    } catch (\Throwable $ignored) {
    }

    $parts = [];
    $parts[] = $name . ' is in the CRM' . (!empty($contact['company']) ? ' under ' . $contact['company'] : '') . '.';

    if (!empty($contact['stage'])) {
        $parts[] = 'Current stage: ' . $contact['stage'] . '.';
    }

    if ($activitySummary['count'] > 0) {
        $parts[] = 'There are ' . $activitySummary['count'] . ' logged activities'
            . ($activitySummary['last_activity_at'] ? ', with the most recent on ' . date('M j, Y', strtotime((string) $activitySummary['last_activity_at'])) : '')
            . '.';
    } else {
        $parts[] = 'No recent activities are logged yet.';
    }

    $parts[] = 'Recommended next step: review recent notes and add the next follow-up action.';

    return [
        'summary' => implode(' ', $parts),
        'last_updated' => time()
    ];
}
