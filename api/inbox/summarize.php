<?php
/**
 * Inbox Thread Summarize API
 * Generates AI summary of a conversation thread
 */

require_once __DIR__ . '/../../vendor/autoload.php';

$envFile = __DIR__ . '/../../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../../config/constants.php';
use CRM\Database;
use CRM\Session;
use CRM\Auth;
use CRM\Modules\AIThreadSummarizer;
use CRM\Services\AIService;
use CRM\Services\AIExecutionStatusService;
use CRM\Services\WorkspaceScopeService;

function extractStructuredSummary(string $raw): ?array
{
    $text = trim($raw);
    if ($text === '') {
        return null;
    }

    $decoded = json_decode($text, true);
    if (is_array($decoded)) {
        return $decoded;
    }

    if (preg_match('/\{[\s\S]*\}/', $text, $m)) {
        $decoded = json_decode($m[0], true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    return null;
}

function toList($value): array
{
    if (is_array($value)) {
        return array_values(array_filter(array_map(static fn($v) => trim((string) $v), $value), static fn($v) => $v !== ''));
    }
    $text = trim((string) $value);
    if ($text === '') {
        return [];
    }
    return [$text];
}

function formatSummaryOutput(string $raw): string
{
    $structured = extractStructuredSummary($raw);
    if (!$structured) {
        $text = trim($raw);
        if ($text === '') {
            return "Thread Summary\n- Summary unavailable right now.";
        }
        return "Thread Summary\n- " . preg_replace('/\s+/', ' ', $text);
    }

    $mainTopic = trim((string) ($structured['main_topic'] ?? $structured['topic'] ?? 'General discussion'));
    $keyDecisions = toList($structured['key_decisions'] ?? $structured['decisions'] ?? []);
    $openQuestions = toList($structured['open_questions'] ?? $structured['questions'] ?? []);
    $nextStep = trim((string) ($structured['suggested_next_step'] ?? $structured['next_step'] ?? ''));

    $lines = ["Thread Summary"];
    $lines[] = "- Main topic: " . ($mainTopic !== '' ? $mainTopic : 'General discussion');

    if (!empty($keyDecisions)) {
        $lines[] = "- Key decisions:";
        foreach ($keyDecisions as $item) {
            $lines[] = "  - " . $item;
        }
    }

    if (!empty($openQuestions)) {
        $lines[] = "- Open questions:";
        foreach ($openQuestions as $item) {
            $lines[] = "  - " . $item;
        }
    }

    if ($nextStep !== '') {
        $lines[] = "- Suggested next step: " . $nextStep;
    }

    return implode("\n", $lines);
}

function fallbackSummaryFromCommunication(int $communicationId, int $workspaceId): string
{
    $seed = Database::queryOne(
        "SELECT contact_id, channel
         FROM communications
         WHERE workspace_id = ? AND id = ?",
        [$workspaceId, $communicationId]
    );
    if (!$seed) {
        return 'No messages found for this thread.';
    }

    $rows = Database::query(
        "SELECT direction, subject, body, created_at
         FROM communications
         WHERE workspace_id = ? AND contact_id = ? AND channel = ?
         ORDER BY created_at ASC
         LIMIT 100",
        [$workspaceId, (int) $seed['contact_id'], (string) $seed['channel']]
    );
    if (empty($rows)) {
        return 'No messages found for this thread.';
    }

    $total = count($rows);
    $inbound = 0;
    $outbound = 0;
    $topics = [];
    foreach ($rows as $r) {
        if (($r['direction'] ?? '') === 'inbound') {
            $inbound++;
        } else {
            $outbound++;
        }
        $s = trim((string) ($r['subject'] ?? ''));
        if ($s !== '') {
            $topics[strtolower($s)] = $s;
        }
    }

    $firstAt = $rows[0]['created_at'] ?? '';
    $lastAt = $rows[$total - 1]['created_at'] ?? '';
    $topic = !empty($topics) ? array_values($topics)[0] : 'General follow-up';

    return "Thread summary: {$total} messages ({$inbound} inbound, {$outbound} outbound). "
        . "Main topic: {$topic}. "
        . "Timeline: " . ($firstAt ? date('M j, Y g:i A', strtotime($firstAt)) : 'N/A')
        . " to " . ($lastAt ? date('M j, Y g:i A', strtotime($lastAt)) : 'N/A') . ".";
}

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$workspaceId = (new WorkspaceScopeService())->requireActiveWorkspaceId();

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$threadId = !empty($input['thread_id']) ? (int) $input['thread_id'] : (!empty($_GET['thread_id']) ? (int) $_GET['thread_id'] : null);
$communicationId = !empty($input['communication_id']) ? (int) $input['communication_id'] : (!empty($_GET['communication_id']) ? (int) $_GET['communication_id'] : null);
$contactId = !empty($input['contact_id']) ? (int) $input['contact_id'] : (!empty($_GET['contact_id']) ? (int) $_GET['contact_id'] : 0);
$channel = trim((string) ($input['channel'] ?? $_GET['channel'] ?? ''));
$threadText = trim((string) ($input['thread_text'] ?? ''));

if (!$threadId && !$communicationId) {
    $refQuery = parse_url((string) ($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_QUERY);
    if (is_string($refQuery) && $refQuery !== '') {
        $ref = [];
        parse_str($refQuery, $ref);
        $threadId = $threadId ?: (!empty($ref['thread_id']) ? (int) $ref['thread_id'] : null);
        $communicationId = $communicationId ?: (!empty($ref['id']) ? (int) $ref['id'] : null);
        if ($contactId <= 0) {
            $contactId = (int) ($ref['contact_id'] ?? 0);
        }
        if ($channel === '') {
            $channel = trim((string) ($ref['channel'] ?? ''));
        }
    }
}

if (!$threadId && !$communicationId && $contactId > 0) {
    if ($channel !== '') {
        $latest = Database::queryOne(
            "SELECT id
             FROM communications
             WHERE workspace_id = ? AND contact_id = ? AND channel = ?
             ORDER BY created_at DESC, id DESC
             LIMIT 1",
            [$workspaceId, $contactId, $channel]
        );
    } else {
        $latest = Database::queryOne(
            "SELECT id
             FROM communications
             WHERE workspace_id = ? AND contact_id = ?
             ORDER BY created_at DESC, id DESC
             LIMIT 1",
            [$workspaceId, $contactId]
        );
    }
    $communicationId = (int) ($latest['id'] ?? 0);
}

if (!$threadId && !$communicationId) {
    if ($threadText !== '') {
        try {
            $ai = new AIService();
            $summary = trim((string) $ai->process('thread_summary', ['text' => $threadText], ['surface' => 'customer_thread']));
            $usedFallback = $summary === '';
            if ($summary === '') {
                $summary = 'Summary unavailable right now.';
            }
            echo json_encode([
                'success' => true,
                'summary' => formatSummaryOutput($summary),
                'fallback' => $usedFallback,
                'source' => $usedFallback ? 'thread_text_fallback' : 'thread_text',
                'ai_status' => (new AIExecutionStatusService())->present($ai->getLastProviderStatus(), [
                    'surface' => 'customer_thread',
                    'fallback' => $usedFallback,
                    'source' => $usedFallback ? 'deterministic_fallback' : '',
                ]),
            ]);
            exit;
        } catch (\Throwable $e) {
            $snippet = mb_substr(preg_replace('/\s+/', ' ', $threadText), 0, 280);
            echo json_encode([
                'success' => true,
                'summary' => formatSummaryOutput($snippet !== '' ? $snippet . (mb_strlen($threadText) > 280 ? '...' : '') : 'Summary unavailable right now.'),
                'fallback' => true,
                'source' => 'thread_text_fallback',
                'ai_status' => (new AIExecutionStatusService())->present([], [
                    'surface' => 'customer_thread',
                    'fallback' => true,
                    'source' => 'deterministic_fallback',
                    'blocked_reason' => 'request_failed',
                ]),
            ]);
            exit;
        }
    }
    echo json_encode([
        'success' => true,
        'summary' => 'Unable to locate a specific thread automatically. Open a conversation from Inbox and try again.',
        'fallback' => true,
        'warning' => 'thread_or_communication_id_missing',
        'ai_status' => (new AIExecutionStatusService())->present([], [
            'surface' => 'customer_thread',
            'blocked_reason' => 'thread_or_communication_id_missing',
            'message' => 'Open a specific conversation before requesting a summary.',
        ]),
    ]);
    exit;
}

try {
    $summarizer = new AIThreadSummarizer();
    if ($threadId) {
        $summary = $summarizer->summarizeThread($threadId);
    } else {
        $communication = Database::queryOne(
            "SELECT id FROM communications WHERE workspace_id = ? AND id = ? LIMIT 1",
            [$workspaceId, $communicationId]
        );
        if (!$communication) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Communication not found']);
            exit;
        }
        $summary = $summarizer->summarizeByCommunication($communicationId);
    }
    echo json_encode([
        'success' => true,
        'summary' => formatSummaryOutput((string) $summary),
        'fallback' => trim((string) $summary) === '',
        'ai_status' => (new AIExecutionStatusService())->present($summarizer->getLastProviderStatus(), [
            'surface' => 'customer_thread',
            'fallback' => trim((string) $summary) === '',
        ]),
    ]);
} catch (\Exception $e) {
    error_log('Thread summarize API error: ' . $e->getMessage());
    $fallback = $communicationId ? fallbackSummaryFromCommunication((int) $communicationId, $workspaceId) : 'Unable to summarize this thread right now.';
    echo json_encode([
        'success' => true,
        'summary' => formatSummaryOutput($fallback),
        'fallback' => true,
        'ai_status' => (new AIExecutionStatusService())->present([], [
            'surface' => 'customer_thread',
            'fallback' => true,
            'source' => 'deterministic_fallback',
            'blocked_reason' => 'request_failed',
        ]),
    ]);
}
