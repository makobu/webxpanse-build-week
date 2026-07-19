<?php
/**
 * Chat Welcome API
 * GET: Returns personalized greeting from the assistant with pending items summary.
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
use CRM\Modules\ChatWelcomeService;
use CRM\Modules\OutcomeMetrics;
use CRM\Services\ClarityPageInsightService;
use CRM\Services\DemoSessionScopeService;
use CRM\Services\OutcomeRolloutService;
use CRM\Services\WorkspaceContext;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode([
        'error' => 'Unauthorized',
        'greeting' => 'Hi! I\'m ' . brandAssistantName() . '. ' . brandTaglineSecondary(),
        'assistant_name' => brandAssistantName(),
        'product_name' => brandProductName(),
        'positioning_line' => brandPositioningLine(),
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode([
        'error' => 'Method not allowed',
        'greeting' => 'Hi! I\'m ' . brandAssistantName() . '. ' . brandTaglineSecondary(),
        'assistant_name' => brandAssistantName(),
        'product_name' => brandProductName(),
        'positioning_line' => brandPositioningLine(),
    ]);
    exit;
}

$userId = (int) (Auth::userId() ?? 0);
$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
$currentPage = basename((string) ($_GET['current_page'] ?? $_GET['page'] ?? basename($_SERVER['PHP_SELF'] ?? '')));

try {
    $protectedDemoSession = null;
    try {
        $protectedDemoSession = $workspaceId > 0 ? (new DemoSessionScopeService())->activeSession($workspaceId) : null;
    } catch (\Throwable $inner) {
        $protectedDemoSession = null;
    }

    if ($protectedDemoSession !== null) {
        $inboxUrl = function_exists('publicUrl') ? publicUrl('inbox.php') : '/crm/public/inbox.php';
        echo json_encode([
            'greeting' => 'Hi, I\'m ' . brandAssistantName() . '. The Riverside demo is fully configured, private to this browser session, and ready to show how one client thread moves from message to triage, draft, task, target, and contact intelligence.',
            'assistant_name' => brandAssistantName(),
            'product_name' => brandProductName(),
            'positioning_line' => brandPositioningLine(),
            'outcome_summary' => null,
            'opening_insight' => [
                'kind' => 'protected_demo',
                'title' => 'Riverside should be handled first',
                'body' => 'Amina has live buying signals in one thread: revised proposal terms, finish selection, payment timing, and a Friday installation hold. The demo shows how Clarity turns that into the next best action without exposing anyone else\'s data.',
                'bullets' => [
                    'High-intent WhatsApp and email context are already connected.',
                    'The assistant draft answers the actual proposal questions.',
                    'Tasks, targets, and contact intelligence stay scoped to this demo session.',
                ],
                'cta_label' => 'Open private Inbox',
                'cta_url' => $inboxUrl,
            ],
            'marketplace_nudges' => [],
            'activation_bundle_nudges' => [],
            'metadata' => [
                'mode_variant' => 'protected_demo',
                'protected_demo' => true,
            ],
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $service = new ChatWelcomeService();
    $data = $service->getWelcomeData($userId);
    $greeting = $service->buildGreeting($data);
    $openingInsight = [];
    $outcomeSummary = null;
    $marketplaceNudges = [];
    $activationBundleNudges = [];
    try {
        $openingInsight = (new ClarityPageInsightService())->openingInsight($workspaceId, $userId, $currentPage);
    } catch (\Throwable $inner) {
        $openingInsight = [];
    }
    try {
        $rollout = new OutcomeRolloutService();
        if ($rollout->isEnabledForUser($userId)) {
            $metrics = new OutcomeMetrics();
            $outcomeSummary = [
                'north_star' => $metrics->getTTFVSummary(14),
                'today_focus' => $metrics->getTodayRevenueFocus($userId),
            ];
        }
    } catch (\Throwable $inner) {
        $outcomeSummary = null;
    }
    echo json_encode([
        'greeting' => $greeting,
        'assistant_name' => brandAssistantName(),
        'product_name' => brandProductName(),
        'positioning_line' => brandPositioningLine(),
        'outcome_summary' => $outcomeSummary,
        'opening_insight' => $openingInsight,
        'marketplace_nudges' => $marketplaceNudges,
        'activation_bundle_nudges' => $activationBundleNudges,
    ]);
} catch (\Throwable $e) {
    error_log('Chat welcome error: ' . $e->getMessage());
    echo json_encode([
        'greeting' => 'Hi! I\'m ' . brandAssistantName() . ', your AI Co-Founder for Structured Growth. How can I help you move revenue today?',
        'assistant_name' => brandAssistantName(),
        'product_name' => brandProductName(),
        'positioning_line' => brandPositioningLine(),
    ]);
}
