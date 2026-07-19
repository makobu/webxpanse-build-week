<?php

require_once dirname(__DIR__) . '/_bootstrap.php';
require_once dirname(__DIR__) . '/_conversation_resolver.php';

use CRM\Database;
use CRM\Modules\AIContactSummarizer;
use CRM\Modules\Contacts;
use CRM\Services\WorkspaceScopeService;

$auth = mobileRequireAuth();
$userId = (int) $auth['user_id'];
$user = Database::queryOne('SELECT * FROM users WHERE id = ? LIMIT 1', [$userId]) ?? [];
$contactId = (int) ($_GET['contact_id'] ?? $_GET['id'] ?? 0);

if ($contactId <= 0) {
    mobileJson(['error' => 'contact_id is required.'], 422);
}

$contact = (new Contacts())->getById($contactId);
if (!$contact || !mobileCanAccessContact($contact, $userId, $user)) {
    mobileJson(['error' => 'Contact not found or not accessible.'], 404);
}

try {
    $summarizer = new AIContactSummarizer();
    $result = $summarizer->generateSummary($contactId);
    $summary = trim((string) ($result['summary'] ?? ''));
    if ($summary === '') {
        throw new RuntimeException('AI returned an empty summary');
    }

    mobileJson([
        'success' => true,
        'data' => [
            'summary' => $summary,
            'last_updated' => $result['last_updated'] ?? time(),
            'used_fallback' => false,
        ],
    ]);
} catch (Throwable $e) {
    error_log('Mobile contact summary API error: ' . $e->getMessage());
    $fallback = mobileBuildFallbackContactSummary($contactId);
    mobileJson([
        'success' => true,
        'data' => [
            'summary' => $fallback['summary'],
            'last_updated' => $fallback['last_updated'],
            'used_fallback' => true,
            'fallback_reason' => 'ai_unavailable_or_failed',
        ],
    ]);
}

function mobileBuildFallbackContactSummary(int $contactId): array
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
        throw new RuntimeException('Contact not found');
    }

    $name = trim((string) (($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? '')));
    if ($name === '') {
        $name = (string) ($contact['email'] ?? 'This contact');
    }

    $activityRow = Database::queryOne(
        "SELECT COUNT(*) AS count, MAX(created_at) AS last_activity_at
         FROM activities a
         WHERE {$activityWorkspace['sql']}
           AND a.contact_id = ?",
        array_merge($activityWorkspace['params'], [$contactId])
    ) ?? [];

    $parts = [];
    $parts[] = $name . ' is in the CRM' . (!empty($contact['company']) ? ' under ' . $contact['company'] : '') . '.';

    if (!empty($contact['stage'])) {
        $parts[] = 'Current stage: ' . $contact['stage'] . '.';
    }

    $activityCount = (int) ($activityRow['count'] ?? 0);
    $lastActivityAt = (string) ($activityRow['last_activity_at'] ?? '');
    if ($activityCount > 0) {
        $parts[] = 'There are ' . $activityCount . ' logged activities'
            . ($lastActivityAt !== '' ? ', with the most recent on ' . date('M j, Y', strtotime($lastActivityAt)) : '')
            . '.';
    } else {
        $parts[] = 'No recent activities are logged yet.';
    }

    $parts[] = 'Recommended next step: review recent notes and capture the next follow-up action.';

    return [
        'summary' => implode(' ', $parts),
        'last_updated' => time(),
    ];
}
