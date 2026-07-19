<?php

require_once dirname(__DIR__) . '/_bootstrap.php';
require_once dirname(__DIR__) . '/_conversation_resolver.php';

use CRM\Database;
use CRM\Modules\Contacts;
use CRM\Modules\MeetingPrepService;

$auth = mobileRequireAuth();
$userId = (int) $auth['user_id'];
$user = Database::queryOne('SELECT * FROM users WHERE id = ? LIMIT 1', [$userId]) ?? [];
$contactId = (int) ($_GET['contact_id'] ?? $_GET['id'] ?? 0);
$dealId = !empty($_GET['deal_id']) ? (int) $_GET['deal_id'] : null;

if ($contactId <= 0) {
    mobileJson(['error' => 'contact_id is required.'], 422);
}

$contact = (new Contacts())->getById($contactId);
if (!$contact || !mobileCanAccessContact($contact, $userId, $user)) {
    mobileJson(['error' => 'Contact not found or not accessible.'], 404);
}

try {
    $service = new MeetingPrepService();
    $result = $service->getPrepSummary($contactId, $dealId, $userId);
    $summary = trim((string) ($result['summary'] ?? ''));
    $keyPoints = array_values(array_filter($result['key_points'] ?? [], static fn($value) => (is_string($value) && trim($value) !== '') || is_array($value)));
    $openQuestions = array_values(array_filter($result['open_questions'] ?? [], static fn($value) => is_string($value) && trim($value) !== ''));
    $suggestedTopics = array_values(array_filter($result['suggested_topics'] ?? [], static fn($value) => is_string($value) && trim($value) !== ''));

    if ($summary === '' && $keyPoints === [] && $openQuestions === [] && $suggestedTopics === []) {
        throw new RuntimeException('AI returned empty meeting prep');
    }

    mobileJson([
        'success' => true,
        'data' => [
            'summary' => $summary !== '' ? $summary : 'Meeting prep generated.',
            'key_points' => $keyPoints,
            'open_questions' => $openQuestions,
            'suggested_topics' => $suggestedTopics,
            'used_fallback' => !empty($result['used_fallback']),
        ],
    ]);
} catch (Throwable $e) {
    error_log('Mobile meeting prep API error: ' . $e->getMessage());
    $service = $service ?? new MeetingPrepService();
    $fallback = $service->buildFallbackSummary($contactId, $dealId, $userId);
    mobileJson([
        'success' => true,
        'data' => [
            'summary' => (string) ($fallback['summary'] ?? ''),
            'key_points' => array_values($fallback['key_points'] ?? []),
            'open_questions' => array_values($fallback['open_questions'] ?? []),
            'suggested_topics' => array_values($fallback['suggested_topics'] ?? []),
            'used_fallback' => true,
            'fallback_reason' => 'ai_unavailable_or_failed',
        ],
    ]);
}
