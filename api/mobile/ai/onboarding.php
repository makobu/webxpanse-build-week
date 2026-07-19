<?php

require_once __DIR__ . '/../_bootstrap.php';
require_once __DIR__ . '/_helpers.php';

use CRM\Modules\CompanyProfile;
use CRM\Modules\IdeaValidationContext;
use CRM\Modules\Products;
use CRM\Modules\UserStrategyProfile;
use CRM\Modules\UserStrategySnapshot;
use CRM\Services\AICoachReadinessService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceOnboardingService;

$auth = mobileRequireAuth();
$userId = (int) ($auth['user_id'] ?? 0);
$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? ($auth['workspace_id'] ?? 0));
$readiness = new AICoachReadinessService();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    mobileJson([
        'success' => true,
        'data' => $readiness->getReadiness($workspaceId, $userId),
    ]);
}

if ($method !== 'POST') {
    mobileJson(['error' => 'Method not allowed'], 405);
}

$input = mobileRequestBody();

/**
 * @param array<string,mixed> $input
 * @param list<string> $fields
 */
function mobileAiHasInput(array $input, array $fields): bool
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
function mobileAiMergedInputValue(array $input, string $field, array $existing, ?string $existingField = null, ?string $fallbackField = null): string
{
    foreach (array_filter([$field, $fallbackField]) as $candidate) {
        if (array_key_exists((string) $candidate, $input) && trim((string) $input[(string) $candidate]) !== '') {
            return (string) $input[(string) $candidate];
        }
    }

    return (string) ($existing[$existingField ?? $field] ?? '');
}

$workspaceOnboarding = new WorkspaceOnboardingService();
if (mobileAiHasInput($input, ['company_name', 'company_industry', 'company_description', 'owner_company_context', 'success_outcome'])) {
    $existingCompany = (new CompanyProfile())->get() ?: [];
    $workspaceOnboarding->saveStep($workspaceId, $userId, 1, [
        'company_name' => $input['company_name'] ?? ($existingCompany['company_name'] ?? ''),
        'company_industry' => $input['company_industry'] ?? ($existingCompany['company_industry'] ?? ''),
        'company_description' => $input['company_description'] ?? ($existingCompany['company_description'] ?? ''),
        'success_outcome' => $input['owner_company_context'] ?? ($input['success_outcome'] ?? ($existingCompany['owner_company_context'] ?? '')),
    ]);
}
if (mobileAiHasInput($input, ['product_name', 'product_description', 'target_audience', 'pricing_info'])) {
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
        'market_view' => $input['market_view'] ?? ($input['target_market_focus'] ?? ($existingStrategyForProduct['market_view'] ?? '')),
        'strategy_hypothesis' => $input['strategy_hypothesis'] ?? ($input['sales_motion'] ?? ($existingStrategyForProduct['strategy_hypothesis'] ?? '')),
    ]);
}
if (mobileAiHasInput($input, ['draft_tone_preset', 'relationship_style', 'draft_cta_style', 'draft_formality_level', 'draft_reading_level', 'draft_voice_notes', 'words_to_avoid', 'escalation_preference'])) {
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
if (mobileAiHasInput($input, ['technical_level', 'ai_best_practices_enabled', 'deal_automation_enabled', 'commercial_layer_enabled'])) {
    $workspaceOnboarding->saveStep($workspaceId, $userId, 4, [
        'technical_level' => $input['technical_level'] ?? 'guide_me',
        'ai_best_practices_enabled' => $input['ai_best_practices_enabled'] ?? '1',
        'deal_automation_enabled' => $input['deal_automation_enabled'] ?? '',
        'commercial_layer_enabled' => $input['commercial_layer_enabled'] ?? '',
    ]);
}

$strategyFields = [
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
];
if (mobileAiHasInput($input, $strategyFields)) {
    $existingStrategy = (new UserStrategyProfile())->get($userId) ?: [];
    (new UserStrategyProfile())->save($userId, array_merge($existingStrategy, [
        'target_market_focus' => mobileAiMergedInputValue($input, 'target_market_focus', $existingStrategy, null, 'target_market'),
        'ideal_customer_profile' => mobileAiMergedInputValue($input, 'ideal_customer_profile', $existingStrategy, null, 'target_market'),
        'offer_angle' => mobileAiMergedInputValue($input, 'offer_angle', $existingStrategy, null, 'differentiator'),
        'segment_focus' => mobileAiMergedInputValue($input, 'segment_focus', $existingStrategy),
        'sales_motion' => mobileAiMergedInputValue($input, 'sales_motion', $existingStrategy),
        'deal_movement_strategy' => mobileAiMergedInputValue($input, 'deal_movement_strategy', $existingStrategy),
        'outreach_posture' => mobileAiMergedInputValue($input, 'outreach_posture', $existingStrategy),
        'positioning_notes' => mobileAiMergedInputValue($input, 'positioning_notes', $existingStrategy, null, 'pain_points'),
        'market_view' => mobileAiMergedInputValue($input, 'market_view', $existingStrategy, null, 'target_market_focus'),
        'strategy_hypothesis' => mobileAiMergedInputValue($input, 'strategy_hypothesis', $existingStrategy, null, 'sales_motion'),
        'draft_tone_preset' => mobileAiMergedInputValue($input, 'draft_tone_preset', $existingStrategy),
        'draft_voice_notes' => mobileAiMergedInputValue($input, 'draft_voice_notes', $existingStrategy),
        'draft_cta_style' => mobileAiMergedInputValue($input, 'draft_cta_style', $existingStrategy),
        'draft_formality_level' => mobileAiMergedInputValue($input, 'draft_formality_level', $existingStrategy),
        'draft_reading_level' => mobileAiMergedInputValue($input, 'draft_reading_level', $existingStrategy),
    ]));
}

$ideaFields = ['value_proposition', 'target_market', 'pain_points', 'assumptions_to_test', 'competitors', 'differentiator'];
if (mobileAiHasInput($input, $ideaFields)) {
    $existingIdea = (new IdeaValidationContext())->get($userId) ?: [];
    (new IdeaValidationContext())->save($userId, array_merge($existingIdea, [
        'value_proposition' => mobileAiMergedInputValue($input, 'value_proposition', $existingIdea),
        'target_market' => mobileAiMergedInputValue($input, 'target_market', $existingIdea),
        'pain_points' => mobileAiMergedInputValue($input, 'pain_points', $existingIdea),
        'assumptions_to_test' => mobileAiMergedInputValue($input, 'assumptions_to_test', $existingIdea),
        'competitors' => mobileAiMergedInputValue($input, 'competitors', $existingIdea),
        'differentiator' => mobileAiMergedInputValue($input, 'differentiator', $existingIdea),
    ]));
}
(new UserStrategySnapshot())->syncForUser($workspaceId, $userId);

$afterSave = $readiness->getReadiness($workspaceId, $userId);

if (!empty($afterSave['recommendations_ready'])) {
    $readiness->completeOnboarding($workspaceId, $userId);
}
$finalReadiness = $readiness->getReadiness($workspaceId, $userId);

mobileJson([
    'success' => true,
    'onboarding_complete' => !empty($finalReadiness['recommendations_ready']),
    'data' => $finalReadiness,
]);
