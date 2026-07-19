<?php
/**
 * AI Coach Strategy Profile API
 * GET: Return stored per-user GTM strategy profile for current user.
 * POST: Save/update the profile and Lean Canvas context toggle for compatibility.
 * Marketplace is the canonical setup surface for Lean Canvas and AI Coach.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

$envFile = __DIR__ . '/../../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        if (strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../../config/constants.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Security;
use CRM\Session;
use CRM\Modules\UserPreferences;
use CRM\Modules\UserStrategyProfile;
use CRM\Modules\UserStrategySnapshot;
use CRM\Services\AICoachReadinessService;
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
$strategyProfile = new UserStrategyProfile();
$strategySnapshot = new UserStrategySnapshot();

/**
 * @param array<string,mixed> $input
 * @param array<string,mixed> $existing
 */
function aiCoachStrategyMergedInput(array $input, array $existing): array
{
    $fields = [
        'target_market_focus',
        'ideal_customer_profile',
        'offer_angle',
        'segment_focus',
        'sales_motion',
        'deal_movement_strategy',
        'outreach_posture',
        'positioning_notes',
        'market_view',
        'strategy_hypothesis',
        'draft_tone_preset',
        'draft_voice_notes',
        'draft_cta_style',
        'draft_formality_level',
        'draft_reading_level',
        'lean_problem',
        'lean_customer_segments',
        'lean_unique_value_proposition',
        'lean_solution',
        'lean_channels',
        'lean_revenue_streams',
        'lean_cost_structure',
        'lean_key_metrics',
        'lean_unfair_advantage',
    ];

    $merged = $input;
    foreach ($fields as $field) {
        if (!array_key_exists($field, $input) || trim((string) $input[$field]) === '') {
            $merged[$field] = (string) ($existing[$field] ?? '');
        }
    }

    return $merged;
}

$serialize = static function (?array $profile) use ($effectiveMode, $preferences, $strategyProfile, $strategySnapshot, $workspaceId, $userId): array {
    $canvas = $strategyProfile->getLeanCanvas($userId);
    $missingBlocks = $strategyProfile->getLeanCanvasMissingBlocks($userId);
    $completeness = $strategyProfile->getLeanCanvasCompleteness($userId);
    $enabled = $preferences->isLeanCanvasModeEnabled($userId);
    $brief = $strategySnapshot->getCurrentBrief($workspaceId, $userId);

    try {
        $readiness = (new AICoachReadinessService())->getReadiness($workspaceId, $userId);
    } catch (\Throwable $e) {
        $readiness = [];
    }

    return [
        'effective_mode' => $effectiveMode,
        'lean_canvas_mode_enabled' => $enabled,
        'mode_variant' => $enabled ? 'lean_canvas' : 'standard',
        'target_market_focus' => $profile['target_market_focus'] ?? '',
        'ideal_customer_profile' => $profile['ideal_customer_profile'] ?? '',
        'offer_angle' => $profile['offer_angle'] ?? '',
        'segment_focus' => $profile['segment_focus'] ?? '',
        'sales_motion' => $profile['sales_motion'] ?? '',
        'deal_movement_strategy' => $profile['deal_movement_strategy'] ?? '',
        'outreach_posture' => $profile['outreach_posture'] ?? '',
        'positioning_notes' => $profile['positioning_notes'] ?? '',
        'market_view' => $profile['market_view'] ?? '',
        'strategy_hypothesis' => $profile['strategy_hypothesis'] ?? '',
        'draft_tone_preset' => $profile['draft_tone_preset'] ?? '',
        'draft_voice_notes' => $profile['draft_voice_notes'] ?? '',
        'draft_cta_style' => $profile['draft_cta_style'] ?? '',
        'draft_formality_level' => $profile['draft_formality_level'] ?? '',
        'draft_reading_level' => $profile['draft_reading_level'] ?? '',
        'lean_canvas' => $canvas,
        'lean_problem' => $canvas['problem'] ?? '',
        'lean_customer_segments' => $canvas['customer_segments'] ?? '',
        'lean_unique_value_proposition' => $canvas['unique_value_proposition'] ?? '',
        'lean_solution' => $canvas['solution'] ?? '',
        'lean_channels' => $canvas['channels'] ?? '',
        'lean_revenue_streams' => $canvas['revenue_streams'] ?? '',
        'lean_cost_structure' => $canvas['cost_structure'] ?? '',
        'lean_key_metrics' => $canvas['key_metrics'] ?? '',
        'lean_unfair_advantage' => $canvas['unfair_advantage'] ?? '',
        'lean_canvas_completeness' => $completeness,
        'lean_canvas_missing_blocks' => $missingBlocks,
        'lean_canvas_status' => [
            'enabled' => $enabled,
            'completeness' => $completeness,
            'missing_blocks' => $missingBlocks,
            'last_updated_at' => (string) ($profile['updated_at'] ?? ''),
        ],
        'personal_brief_ready' => (bool) ($brief['personal_brief_ready'] ?? false),
        'personal_missing_requirements' => (array) ($brief['missing_requirements'] ?? []),
        'active_strategy_snapshot' => $brief['active_strategy_snapshot'] ?? null,
        'personal_strategy_optional' => (bool) ($readiness['personal_strategy_optional'] ?? true),
        'personal_strategy_refinement_ready' => (bool) ($readiness['personal_strategy_refinement_ready'] ?? ($brief['personal_brief_ready'] ?? false)),
        'optional_personal_strategy_missing' => (array) ($readiness['optional_personal_strategy_missing'] ?? ($brief['missing_requirements'] ?? [])),
        'clarity_journey_ready' => (bool) ($readiness['clarity_journey_ready'] ?? false),
        'clarity_journey_readiness' => (array) ($readiness['clarity_journey_readiness'] ?? []),
        'coach_context_ready' => (bool) ($readiness['coach_context_ready'] ?? false),
        'onboarding_payload' => $readiness['onboarding_payload'] ?? null,
        'ai_coach_readiness' => $readiness,
    ];
};

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode($serialize($strategyProfile->get($userId)));
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
        if (array_key_exists('lean_canvas_mode_enabled', $input)) {
            $modeEnabled = $input['lean_canvas_mode_enabled'];
            $modeOn = in_array(strtolower((string) $modeEnabled), ['1', 'true', 'yes', 'on'], true);
            $preferences->setLeanCanvasModeEnabled($userId, $modeOn);
        }
        $strategyProfile->save($userId, aiCoachStrategyMergedInput($input, $strategyProfile->get($userId) ?: []));
        $strategySnapshot->syncForUser($workspaceId, $userId);
        echo json_encode(array_merge(['success' => true], $serialize($strategyProfile->get($userId))));
    } catch (\Throwable $e) {
        error_log('User strategy profile save error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Failed to save strategy profile']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
