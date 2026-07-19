<?php
/**
 * AI Coach workspace-user onboarding API.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

$envFile = __DIR__ . '/../../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../../config/constants.php';

use CRM\Auth;
use CRM\Database;
use CRM\Security;
use CRM\Session;
use CRM\Modules\CompanyProfile;
use CRM\Modules\IdeaValidationContext;
use CRM\Modules\Products;
use CRM\Modules\UserStrategyProfile;
use CRM\Modules\UserStrategySnapshot;
use CRM\Services\AICoachReadinessService;
use CRM\Services\AICoachWorkspaceSetupService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceOnboardingService;
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
$installer = new WorkspaceSkillInstallService();
$workspaceSetup = new AICoachWorkspaceSetupService($installer);

if ($workspaceId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'No active workspace']);
    exit;
}

if ((new WorkspaceSkillCatalogService())->isGloballyDeactivated(WorkspaceSkillCatalogService::SKILL_AI_COACH)
    || (!$workspaceSetup->isInstalled($workspaceId))) {
    http_response_code(403);
    echo json_encode(['error' => 'AI Coach is not installed for this workspace']);
    exit;
}

if (!$workspaceSetup->isWorkspaceEnabled($workspaceId)) {
    http_response_code(403);
    echo json_encode(['error' => 'AI Coach is disabled for this workspace']);
    exit;
}

$readiness = new AICoachReadinessService();

/**
 * @param array<string,mixed> $input
 * @param list<string> $fields
 */
function aiCoachHasInput(array $input, array $fields): bool
{
    foreach ($fields as $field) {
        if (array_key_exists($field, $input) && trim((string) $input[$field]) !== '') {
            return true;
        }
    }
    return false;
}

/**
 * @param array<string,mixed> $input
 * @param array<string,mixed> $existing
 */
function aiCoachMergedInputValue(array $input, string $field, array $existing, ?string $existingField = null, ?string $fallbackField = null): string
{
    foreach (array_filter([$field, $fallbackField]) as $candidate) {
        if (array_key_exists((string) $candidate, $input) && trim((string) $input[(string) $candidate]) !== '') {
            return (string) $input[(string) $candidate];
        }
    }

    return (string) ($existing[$existingField ?? $field] ?? '');
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode($readiness->getReadiness($workspaceId, $userId));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = $_POST;
    if (empty($input) && strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
    }

    if (!Security::validateCSRF($input['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['error' => 'Invalid security token']);
        exit;
    }

    try {
        $workspaceOnboarding = new WorkspaceOnboardingService();
        $hasCompanyInput = aiCoachHasInput($input, ['company_name', 'company_industry', 'company_description', 'owner_company_context', 'success_outcome']);
        $hasProductInput = aiCoachHasInput($input, ['product_name', 'product_description', 'target_audience', 'pricing_info']);

        if ($hasCompanyInput) {
            $existingCompany = (new CompanyProfile())->get() ?: [];
            $workspaceOnboarding->saveStep($workspaceId, $userId, 1, [
                'company_name' => $input['company_name'] ?? ($existingCompany['company_name'] ?? ''),
                'company_industry' => $input['company_industry'] ?? ($existingCompany['company_industry'] ?? ''),
                'company_description' => $input['company_description'] ?? ($existingCompany['company_description'] ?? ''),
                'success_outcome' => $input['owner_company_context'] ?? ($input['success_outcome'] ?? ($existingCompany['owner_company_context'] ?? '')),
            ]);
        }

        if ($hasProductInput) {
            $existingProduct = (array) ((new Products())->list()[0] ?? []);
            $existingStrategyForProduct = (new UserStrategyProfile())->get($userId) ?: [];
            $workspaceOnboarding->saveStep($workspaceId, $userId, 2, [
                'product_name' => $input['product_name'] ?? ($existingProduct['name'] ?? ''),
                'product_description' => $input['product_description'] ?? ($existingProduct['description'] ?? ''),
                'target_audience' => $input['target_audience'] ?? ($existingProduct['target_audience'] ?? ''),
                'pricing_info' => $input['pricing_info'] ?? ($existingProduct['pricing_info'] ?? ''),
                'target_market_focus' => $input['target_market_focus'] ?? ($input['target_market'] ?? ($existingStrategyForProduct['target_market_focus'] ?? '')),
                'ideal_customer_profile' => $input['ideal_customer_profile'] ?? ($input['target_market'] ?? ($existingStrategyForProduct['ideal_customer_profile'] ?? '')),
                'offer_angle' => $input['offer_angle'] ?? ($input['differentiator'] ?? ($existingStrategyForProduct['offer_angle'] ?? '')),
                'segment_focus' => $input['segment_focus'] ?? ($existingStrategyForProduct['segment_focus'] ?? ''),
                'sales_motion' => $input['sales_motion'] ?? ($existingStrategyForProduct['sales_motion'] ?? ''),
                'deal_movement_strategy' => $input['deal_movement_strategy'] ?? ($existingStrategyForProduct['deal_movement_strategy'] ?? ''),
                'outreach_posture' => $input['outreach_posture'] ?? ($existingStrategyForProduct['outreach_posture'] ?? ''),
                'positioning_notes' => $input['positioning_notes'] ?? ($input['pain_points'] ?? ($existingStrategyForProduct['positioning_notes'] ?? '')),
                'market_view' => $input['market_view'] ?? ($existingStrategyForProduct['market_view'] ?? ''),
                'strategy_hypothesis' => $input['strategy_hypothesis'] ?? ($existingStrategyForProduct['strategy_hypothesis'] ?? ''),
            ]);
        }

        if (
            !empty($input['draft_tone_preset'])
            || !empty($input['relationship_style'])
            || !empty($input['draft_voice_notes'])
            || !empty($input['draft_cta_style'])
            || !empty($input['draft_formality_level'])
            || !empty($input['draft_reading_level'])
        ) {
            $workspaceOnboarding->saveStep($workspaceId, $userId, 3, [
                'draft_tone_preset' => $input['draft_tone_preset'] ?? 'consultative',
                'relationship_style' => $input['relationship_style'] ?? 'trusted_advisor',
                'draft_cta_style' => $input['draft_cta_style'] ?? 'clear',
                'draft_formality_level' => $input['draft_formality_level'] ?? 'balanced',
                'draft_reading_level' => $input['draft_reading_level'] ?? 'professional',
                'draft_voice_notes' => $input['draft_voice_notes'] ?? '',
                'words_to_avoid' => $input['words_to_avoid'] ?? '',
                'escalation_preference' => $input['escalation_preference'] ?? '',
            ]);
        }

        if (!empty($input['technical_level'])) {
            $workspaceOnboarding->saveStep($workspaceId, $userId, 4, [
                'technical_level' => $input['technical_level'] ?? 'guide_me',
                'ai_best_practices_enabled' => $input['ai_best_practices_enabled'] ?? '1',
                'deal_automation_enabled' => $input['deal_automation_enabled'] ?? '',
                'commercial_layer_enabled' => $input['commercial_layer_enabled'] ?? '',
            ]);
        }

        if (
            !empty($input['target_market_focus'])
            || !empty($input['ideal_customer_profile'])
            || !empty($input['offer_angle'])
            || !empty($input['segment_focus'])
            || !empty($input['sales_motion'])
            || !empty($input['deal_movement_strategy'])
            || !empty($input['outreach_posture'])
            || !empty($input['positioning_notes'])
            || !empty($input['market_view'])
            || !empty($input['strategy_hypothesis'])
            || !empty($input['draft_tone_preset'])
            || !empty($input['draft_voice_notes'])
        ) {
            $existingStrategy = (new UserStrategyProfile())->get($userId) ?: [];
            (new UserStrategyProfile())->save($userId, array_merge($existingStrategy, [
                'target_market_focus' => aiCoachMergedInputValue($input, 'target_market_focus', $existingStrategy),
                'ideal_customer_profile' => aiCoachMergedInputValue($input, 'ideal_customer_profile', $existingStrategy),
                'offer_angle' => aiCoachMergedInputValue($input, 'offer_angle', $existingStrategy),
                'segment_focus' => aiCoachMergedInputValue($input, 'segment_focus', $existingStrategy),
                'sales_motion' => aiCoachMergedInputValue($input, 'sales_motion', $existingStrategy),
                'deal_movement_strategy' => aiCoachMergedInputValue($input, 'deal_movement_strategy', $existingStrategy),
                'outreach_posture' => aiCoachMergedInputValue($input, 'outreach_posture', $existingStrategy),
                'positioning_notes' => aiCoachMergedInputValue($input, 'positioning_notes', $existingStrategy),
                'market_view' => aiCoachMergedInputValue($input, 'market_view', $existingStrategy),
                'strategy_hypothesis' => aiCoachMergedInputValue($input, 'strategy_hypothesis', $existingStrategy),
                'draft_tone_preset' => aiCoachMergedInputValue($input, 'draft_tone_preset', $existingStrategy),
                'draft_voice_notes' => aiCoachMergedInputValue($input, 'draft_voice_notes', $existingStrategy),
            ]));
        }

        if (
            !empty($input['value_proposition'])
            || !empty($input['target_market'])
            || !empty($input['pain_points'])
            || !empty($input['assumptions_to_test'])
            || !empty($input['competitors'])
            || !empty($input['differentiator'])
        ) {
            $existingIdea = (new IdeaValidationContext())->get($userId) ?: [];
            (new IdeaValidationContext())->save($userId, array_merge($existingIdea, [
                'value_proposition' => aiCoachMergedInputValue($input, 'value_proposition', $existingIdea),
                'target_market' => aiCoachMergedInputValue($input, 'target_market', $existingIdea),
                'pain_points' => aiCoachMergedInputValue($input, 'pain_points', $existingIdea),
                'assumptions_to_test' => aiCoachMergedInputValue($input, 'assumptions_to_test', $existingIdea),
                'competitors' => aiCoachMergedInputValue($input, 'competitors', $existingIdea),
                'differentiator' => aiCoachMergedInputValue($input, 'differentiator', $existingIdea),
            ]));
        }

        (new UserStrategySnapshot())->syncForUser($workspaceId, $userId);

        $afterSave = $readiness->getReadiness($workspaceId, $userId);

        if (!empty($afterSave['recommendations_ready'])) {
            $readiness->completeOnboarding($workspaceId, $userId);
        }
        echo json_encode(array_merge([
            'success' => true,
            'onboarding_complete' => !empty($afterSave['recommendations_ready']),
        ], $readiness->getReadiness($workspaceId, $userId)));
    } catch (\Throwable $e) {
        error_log('AI Coach onboarding save error: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Failed to save AI Coach onboarding']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
