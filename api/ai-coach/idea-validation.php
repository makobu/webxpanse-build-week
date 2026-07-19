<?php
/**
 * AI Coach Idea Validation API
 * GET: Return stored idea validation context for current user.
 * POST: Save/update idea validation context.
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
use CRM\Authorization;
use CRM\Security;
use CRM\Modules\IdeaValidationContext;
use CRM\Modules\UserPreferences;
use CRM\Modules\UserStrategySnapshot;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceSkillCatalogService;
use CRM\Services\WorkspaceSkillInstallService;

Database::init(require __DIR__ . '/../../config/database.php');
Session::start();

header('Content-Type: application/json');

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);
$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
$aiCoachInstaller = new WorkspaceSkillInstallService();
if ((new WorkspaceSkillCatalogService())->isGloballyDeactivated(WorkspaceSkillCatalogService::SKILL_AI_COACH)
    || (!Authorization::isSuperAdmin($user) && !$aiCoachInstaller->canExposeRuntimeModule($workspaceId, WorkspaceSkillCatalogService::SKILL_AI_COACH))) {
    http_response_code(403);
    echo json_encode(['error' => 'AI Coach is not installed for this workspace']);
    exit;
}

$preferences = new UserPreferences();
$effectiveMode = $preferences->getEffectiveAIGuidanceMode($userId);

$ideaContext = new IdeaValidationContext();
$strategySnapshot = new UserStrategySnapshot();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $ctx = $ideaContext->get($userId);
    $brief = $strategySnapshot->getCurrentBrief($workspaceId, $userId);
    $data = [
        'effective_mode' => $effectiveMode,
        'value_proposition' => $ctx['value_proposition'] ?? '',
        'target_market' => $ctx['target_market'] ?? '',
        'pain_points' => $ctx['pain_points'] ?? '',
        'assumptions_to_test' => $ctx['assumptions_to_test'] ?? '',
        'competitors' => $ctx['competitors'] ?? '',
        'differentiator' => $ctx['differentiator'] ?? '',
        'personal_brief_ready' => (bool) ($brief['personal_brief_ready'] ?? false),
        'personal_missing_requirements' => (array) ($brief['missing_requirements'] ?? []),
        'active_strategy_snapshot' => $brief['active_strategy_snapshot'] ?? null,
    ];
    echo json_encode($data);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = $_POST;
    if (empty($input) && strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $input = json_decode($raw, true) ?? [];
    }

    $csrfToken = $input['csrf_token'] ?? '';
    if (!Security::validateCSRF($csrfToken)) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid security token']);
        exit;
    }

    try {
        $ideaContext->save($userId, $input);
        $strategySnapshot->syncForUser($workspaceId, $userId);
        $ctx = $ideaContext->get($userId);
        $brief = $strategySnapshot->getCurrentBrief($workspaceId, $userId);
        echo json_encode([
            'success' => true,
            'effective_mode' => $effectiveMode,
            'value_proposition' => $ctx['value_proposition'] ?? '',
            'target_market' => $ctx['target_market'] ?? '',
            'pain_points' => $ctx['pain_points'] ?? '',
            'assumptions_to_test' => $ctx['assumptions_to_test'] ?? '',
            'competitors' => $ctx['competitors'] ?? '',
            'differentiator' => $ctx['differentiator'] ?? '',
            'personal_brief_ready' => (bool) ($brief['personal_brief_ready'] ?? false),
            'personal_missing_requirements' => (array) ($brief['missing_requirements'] ?? []),
            'active_strategy_snapshot' => $brief['active_strategy_snapshot'] ?? null,
        ]);
    } catch (\Throwable $e) {
        error_log('Idea validation save error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Failed to save idea validation context']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
