<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\CompanyProfile;
use CRM\Modules\IdeaValidationContext;
use CRM\Modules\Products;
use CRM\Modules\UserStrategyProfile;
use CRM\Modules\UserStrategySnapshot;

class AICoachReadinessService
{
    public function getReadiness(int $workspaceId, int $userId): array
    {
        $workspaceId = max(0, $workspaceId);
        $userId = max(0, $userId);
        $journeyCoachContext = $userId > 0
            ? (new StartupJourneyCoachContextService())->readinessForCoach($workspaceId, $userId)
            : [
                'clarity_journey_ready' => false,
                'inherited_context_ready' => false,
                'personal_brief_source' => 'missing',
                'context_sources' => [],
                'remaining_personal_requirements' => [],
                'journey_readiness' => [],
            ];
        $strategy = $userId > 0 ? ((new UserStrategyProfile())->get($userId) ?: []) : [];
        $ideaValidation = $userId > 0 ? ((new IdeaValidationContext())->get($userId) ?: []) : [];
        $strategySnapshots = new UserStrategySnapshot();
        $personalBrief = $userId > 0 ? $strategySnapshots->getCurrentBrief($workspaceId, $userId, true) : [
            'personal_brief_ready' => false,
            'missing_requirements' => [],
            'active_strategy_snapshot' => null,
        ];
        $profile = (new CompanyProfile())->get() ?: [];
        $products = (new Products())->list();
        $onboardingService = new WorkspaceOnboardingService();
        $workspaceOnboarding = ($workspaceId > 0 && $onboardingService->tableReady())
            ? $onboardingService->getState($workspaceId, $userId)
            : [];
        $workspaceReadiness = (array) ($workspaceOnboarding['readiness'] ?? []);
        $onboardingRow = (array) ($workspaceOnboarding['row'] ?? []);
        $workspaceSetup = new AICoachWorkspaceSetupService();
        $installed = $workspaceSetup->isInstalled($workspaceId);
        $enabled = $workspaceSetup->isWorkspaceEnabled($workspaceId);
        $workspaceBriefComplete = (string) ($workspaceOnboarding['status'] ?? '') === 'completed';
        $personalBriefReady = !empty($personalBrief['personal_brief_ready']);
        $clarityJourneyReady = !empty($journeyCoachContext['clarity_journey_ready']);
        $inheritedContextReady = !empty($journeyCoachContext['inherited_context_ready']);
        $coachContextReady = $clarityJourneyReady;
        $personalBriefSource = (string) ($journeyCoachContext['personal_brief_source'] ?? ($personalBriefReady ? 'manual' : 'missing'));
        $personalStrategyRefinementReady = in_array($personalBriefSource, ['manual', 'mixed'], true);
        $personalOnboardingComplete = $clarityJourneyReady || $personalBriefReady || ($workspaceId > 0 && $userId > 0 && $this->isOnboardingComplete($workspaceId, $userId));
        $companyContextReady = !empty($workspaceReadiness['profile_ready']);
        $missing = [];

        if (!$installed) {
            $missing[] = $this->missingRequirement('workspace_setup', 'ai_coach_installed', 'Install AI Coach in Marketplace.', 'marketplace');
        }
        if (!$enabled) {
            $missing[] = $this->missingRequirement('workspace_setup', 'ai_coach_enabled', 'Enable AI Coach for this workspace in Marketplace setup.', 'marketplace');
        }
        if (!$companyContextReady) {
            $missing[] = $this->missingRequirement('company_context', 'company_context_ready', 'Add the company profile basics in Settings.', 'settings');
        }

        $sharedContextMissing = $this->missingSharedContextRequirements($profile, $products, $workspaceReadiness);
        $optionalPersonalMissing = $personalStrategyRefinementReady ? [] : (array) ($journeyCoachContext['remaining_personal_requirements'] ?? ($personalBrief['missing_requirements'] ?? []));
        $clarityMissing = [];
        if (!$clarityJourneyReady) {
            $journeyReadiness = (array) ($journeyCoachContext['journey_readiness'] ?? []);
            $currentStage = trim((string) ($journeyReadiness['current_stage_key'] ?? ''));
            $blockers = (array) ($journeyReadiness['blockers'] ?? []);
            $blockerLabel = trim((string) ($blockers[0] ?? 'Clarity Journey'));
            $message = $blockerLabel !== '' && $blockerLabel !== 'Clarity Journey'
                ? 'Complete the ' . $blockerLabel . ' stage in Clarity Journey.'
                : 'Complete Clarity Journey before AI Coach can use stronger recommendations.';
            $clarityMissing[] = $this->missingRequirement('clarity_journey', 'clarity_journey_ready', $message, 'clarity_journey');
            if ($currentStage !== '') {
                $clarityMissing[0]['stage_key'] = $currentStage;
            }
        }
        $missing = array_merge($missing, $sharedContextMissing, $clarityMissing);

        $strategyReady = $clarityJourneyReady || $personalBriefReady;
        $ideaReady = $clarityJourneyReady || $personalBriefReady;
        $recommendationsReady = $installed
            && $enabled
            && $companyContextReady
            && $clarityJourneyReady
            && $sharedContextMissing === [];
        $operatingMaturityContext = (new AICoachOperatingMaturityService())->determine(
            $workspaceId,
            $userId,
            [],
            (array) ($journeyCoachContext['journey_readiness'] ?? [])
        );
        $operatingMaturity = (string) ($operatingMaturityContext['stage'] ?? AICoachOperatingMaturityService::PRE_CLARITY_JOURNEY);

        $result = [
            'ai_coach_installed' => $installed,
            'ai_coach_enabled' => $enabled,
            'workspace_enabled' => $enabled,
            'workspace_ai_coach_enabled' => $enabled,
            'user_onboarding_complete' => $personalOnboardingComplete,
            'workspace_briefing_complete' => $workspaceBriefComplete,
            'company_context_ready' => $companyContextReady,
            'personal_brief_ready' => $personalBriefReady,
            'manual_personal_brief_ready' => !empty($journeyCoachContext['manual_personal_brief_ready']),
            'coach_context_ready' => $coachContextReady,
            'personal_strategy_optional' => true,
            'personal_strategy_refinement_ready' => $personalStrategyRefinementReady,
            'clarity_journey_ready' => $clarityJourneyReady,
            'inherited_context_ready' => $inheritedContextReady,
            'personal_brief_source' => in_array($personalBriefSource, ['manual', 'clarity_journey', 'mixed', 'missing'], true) ? $personalBriefSource : 'missing',
            'context_sources' => array_values(array_unique(array_merge(
                (array) ($journeyCoachContext['context_sources'] ?? []),
                ['founder_loop', 'crm', 'finance']
            ))),
            'remaining_personal_requirements' => $optionalPersonalMissing,
            'optional_personal_strategy_missing' => $optionalPersonalMissing,
            'clarity_journey_readiness' => (array) ($journeyCoachContext['journey_readiness'] ?? []),
            'inherited_context' => [
                'source' => 'clarity_journey',
                'ready' => $inheritedContextReady,
                'strategy_fields' => array_keys((array) ($journeyCoachContext['strategy'] ?? [])),
                'idea_validation_fields' => array_keys((array) ($journeyCoachContext['idea_validation'] ?? [])),
                'source_map' => (array) ($journeyCoachContext['source_map'] ?? []),
            ],
            'active_strategy_snapshot' => $personalBrief['active_strategy_snapshot'] ?? null,
            'personal_missing_requirements' => $optionalPersonalMissing,
            'strategy_ready' => $strategyReady,
            'idea_validation_ready' => $ideaReady,
            'recommendations_ready' => $recommendationsReady,
            'operating_maturity' => $operatingMaturity,
            'operating_maturity_context' => $operatingMaturityContext,
            'missing_requirements' => $missing,
            'marketplace_url' => 'workspace_skills.php?module=' . rawurlencode(WorkspaceSkillCatalogService::SKILL_AI_COACH) . '&setup_tab=workspace_readiness#setup',
            'onboarding_payload' => [
                'status' => $personalOnboardingComplete ? 'completed' : 'in_progress',
                'operating_maturity' => $operatingMaturity,
                'operating_maturity_context' => $operatingMaturityContext,
                'workspace_briefing' => [
                    'status' => (string) ($workspaceOnboarding['status'] ?? 'not_started'),
                    'readiness_score' => (int) ($workspaceReadiness['readiness_score'] ?? 0),
                    'completed_steps' => (array) ($workspaceOnboarding['completed_steps'] ?? []),
                    'missing_steps' => array_values(array_diff(
                        (array) ($workspaceOnboarding['required_steps'] ?? []),
                        (array) ($workspaceOnboarding['completed_steps'] ?? [])
                    )),
                ],
                'personal_brief' => [
                    'status' => $personalBriefReady ? 'completed' : 'in_progress',
                    'optional' => true,
                    'refinement_ready' => $personalStrategyRefinementReady,
                    'source' => in_array($personalBriefSource, ['manual', 'clarity_journey', 'mixed', 'missing'], true) ? $personalBriefSource : 'missing',
                    'inherited_context_ready' => $inheritedContextReady,
                    'clarity_journey_ready' => $clarityJourneyReady,
                    'missing_requirements' => $optionalPersonalMissing,
                    'remaining_personal_requirements' => $optionalPersonalMissing,
                    'optional_missing_requirements' => $optionalPersonalMissing,
                    'active_strategy_snapshot' => $personalBrief['active_strategy_snapshot'] ?? null,
                ],
                'clarity_journey' => [
                    'ready' => $clarityJourneyReady,
                    'inherited_context_ready' => $inheritedContextReady,
                    'readiness' => (array) ($journeyCoachContext['journey_readiness'] ?? []),
                    'derived_strategy' => (array) ($journeyCoachContext['strategy'] ?? []),
                    'derived_idea_validation' => (array) ($journeyCoachContext['idea_validation'] ?? []),
                ],
                'company' => [
                    'company_name' => (string) ($profile['company_name'] ?? ''),
                    'company_industry' => (string) ($profile['company_industry'] ?? ''),
                    'company_description' => (string) ($profile['company_description'] ?? ''),
                    'owner_company_context' => (string) ($profile['owner_company_context'] ?? ''),
                ],
                'products' => array_values(array_map(static fn(array $product): array => [
                    'name' => (string) ($product['name'] ?? ''),
                    'description' => (string) ($product['description'] ?? ''),
                    'pricing_info' => (string) ($product['pricing_info'] ?? ''),
                    'target_audience' => (string) ($product['target_audience'] ?? ''),
                ], $products)),
                'tone' => [
                    'draft_tone_preset' => (string) ($strategy['draft_tone_preset'] ?? ''),
                    'draft_voice_notes' => (string) ($strategy['draft_voice_notes'] ?? ''),
                    'relationship_style' => (string) ($onboardingRow['relationship_style'] ?? ''),
                ],
                'automation_preferences' => [
                    'technical_level' => (string) ($onboardingRow['technical_level'] ?? ''),
                    'automation_launch_mode' => (string) ($onboardingRow['automation_launch_mode'] ?? ''),
                    'ai_autoresponder_mode' => (string) ($onboardingRow['ai_autoresponder_mode'] ?? ''),
                    'ai_best_practices_enabled' => !empty($onboardingRow['ai_best_practices_enabled']),
                    'deal_automation_requested' => !empty($onboardingRow['deal_automation_enabled']),
                    'commercial_layer_requested' => !empty($onboardingRow['commercial_layer_enabled']),
                ],
                'strategy' => [
                    'target_market_focus' => (string) ($strategy['target_market_focus'] ?? ''),
                    'ideal_customer_profile' => (string) ($strategy['ideal_customer_profile'] ?? ''),
                    'offer_angle' => (string) ($strategy['offer_angle'] ?? ''),
                    'sales_motion' => (string) ($strategy['sales_motion'] ?? ''),
                    'segment_focus' => (string) ($strategy['segment_focus'] ?? ''),
                    'deal_movement_strategy' => (string) ($strategy['deal_movement_strategy'] ?? ''),
                    'outreach_posture' => (string) ($strategy['outreach_posture'] ?? ''),
                    'positioning_notes' => (string) ($strategy['positioning_notes'] ?? ''),
                    'market_view' => (string) ($strategy['market_view'] ?? ''),
                    'strategy_hypothesis' => (string) ($strategy['strategy_hypothesis'] ?? ''),
                ],
                'idea_validation' => [
                    'value_proposition' => (string) ($ideaValidation['value_proposition'] ?? ''),
                    'target_market' => (string) ($ideaValidation['target_market'] ?? ''),
                    'pain_points' => (string) ($ideaValidation['pain_points'] ?? ''),
                    'differentiator' => (string) ($ideaValidation['differentiator'] ?? ''),
                ],
            ],
        ];
        $this->logReadinessTransitions($workspaceId, $userId, $result);

        return $result;
    }

    public function completeOnboarding(int $workspaceId, int $userId): void
    {
        if ($workspaceId <= 0 || $userId <= 0 || !Database::tableExists('ai_coach_user_onboarding')) {
            return;
        }

        Database::execute(
            "INSERT INTO ai_coach_user_onboarding (workspace_id, user_id, status, completed_at)
             VALUES (?, ?, 'completed', NOW())
             ON DUPLICATE KEY UPDATE
                status = 'completed',
                completed_at = COALESCE(completed_at, NOW()),
                updated_at = NOW()",
            [$workspaceId, $userId]
        );
    }

    private function isOnboardingComplete(int $workspaceId, int $userId): bool
    {
        if (!Database::tableExists('ai_coach_user_onboarding')) {
            return false;
        }

        $row = Database::queryOne(
            "SELECT status
             FROM ai_coach_user_onboarding
             WHERE workspace_id = ?
               AND user_id = ?
             LIMIT 1",
            [$workspaceId, $userId]
        );

        return (string) ($row['status'] ?? '') === 'completed';
    }

    /**
     * @param array<string,mixed> $profile
     * @param list<array<string,mixed>> $products
     * @param array<string,mixed> $workspaceReadiness
     * @return list<array<string,string>>
     */
    private function missingSharedContextRequirements(array $profile, array $products, array $workspaceReadiness): array
    {
        $missing = [];
        if (empty($workspaceReadiness['profile_ready'])) {
            if (trim((string) ($profile['company_name'] ?? '')) === '') {
                $missing[] = $this->missingRequirement('company_context', 'company_name', 'Add the company name in Settings.', 'settings');
            }
        }

        if (empty($workspaceReadiness['product_ready']) || $products === []) {
            $missing[] = $this->missingRequirement('products', 'product_name', 'Add at least one product or offer in Settings.', 'settings');
        }

        return $missing;
    }

    /**
     * @param array<string,mixed> $profile
     * @param list<array<string,mixed>> $products
     * @param array<string,mixed> $strategy
     * @param array<string,mixed> $onboardingRow
     * @param array<string,mixed> $workspaceReadiness
     * @return list<array<string,string>>
     */
    private function missingContextRequirements(array $profile, array $products, array $strategy, array $onboardingRow, array $workspaceReadiness): array
    {
        $missing = [];
        if (empty($workspaceReadiness['profile_ready'])) {
            if (trim((string) ($profile['company_name'] ?? '')) === '') {
                $missing[] = $this->missingRequirement('company_context', 'company_name', 'Add the company name in Settings.', 'settings');
            }
        }

        if (empty($workspaceReadiness['product_ready'])) {
            $missing[] = $this->missingRequirement('products', 'product_name', 'Add at least one product or offer in Settings.', 'settings');
        }

        if (empty($workspaceReadiness['offer_ready'])) {
            $hasAudience = trim((string) ($strategy['ideal_customer_profile'] ?? '')) !== '';
            foreach ($products as $product) {
                $hasAudience = $hasAudience || trim((string) ($product['target_audience'] ?? '')) !== '';
            }
            if (!$hasAudience) {
                $missing[] = $this->missingRequirement('products', 'ideal_customer_profile', 'Add the ideal customer profile in Settings or Clarity Journey.', 'settings');
            }
        }

        if (empty($workspaceReadiness['voice_ready'])) {
            if (trim((string) ($strategy['draft_tone_preset'] ?? '')) === '') {
                $missing[] = $this->missingRequirement('voice', 'draft_tone_preset', 'Choose the AI writing tone in Settings.', 'settings');
            }
            if (trim((string) ($onboardingRow['relationship_style'] ?? '')) === '') {
                $missing[] = $this->missingRequirement('voice', 'relationship_style', 'Choose the relationship style in Settings.', 'settings');
            }
        }

        if (empty($workspaceReadiness['autopilot_ready'])) {
            $missing[] = $this->missingRequirement('automation_preferences', 'technical_level', 'Choose the automation preference in Settings.', 'settings');
        }

        return $missing;
    }

    /**
     * @param array<string,string> $requiredFields
     * @param array<string,mixed> $source
     * @return list<array<string,string>>
     */
    private function missingFields(string $section, array $requiredFields, array $source): array
    {
        $missing = [];
        foreach ($requiredFields as $field => $label) {
            if (trim((string) ($source[$field] ?? '')) === '') {
                $missing[] = $this->missingRequirement($section, $field, 'Add ' . strtolower($label) . '.', 'onboarding');
            }
        }

        return $missing;
    }

    /**
     * @return array<string,string>
     */
    private function missingRequirement(string $section, string $field, string $message, string $action): array
    {
        return [
            'section' => $section,
            'field' => $field,
            'label' => ucwords(str_replace('_', ' ', $field)),
            'message' => $message,
            'action' => $action,
        ];
    }

    /**
     * @param array<string,mixed> $readiness
     */
    private function logReadinessTransitions(int $workspaceId, int $userId, array $readiness): void
    {
        if ($workspaceId <= 0 || $userId <= 0 || !Database::tableExists('ai_capability_state_log')) {
            return;
        }

        $states = [
            'clarity_journey_completed' => !empty($readiness['clarity_journey_ready']),
            'inherited_coach_context_synced' => !empty($readiness['inherited_context_ready']),
            'ai_coach_recommendations_ready' => !empty($readiness['recommendations_ready']),
            'founder_loop_context_present' => in_array((string) ($readiness['operating_maturity'] ?? ''), [
                AICoachOperatingMaturityService::FOUNDER_LOOP_ACTIVE,
                AICoachOperatingMaturityService::OPERATING_SYSTEM_ACTIVE,
            ], true),
        ];

        foreach ($states as $capability => $ready) {
            $status = $ready ? 'ready' : 'missing';
            try {
                $latest = Database::queryOne(
                    "SELECT status
                     FROM ai_capability_state_log
                     WHERE workspace_id = ?
                       AND user_id = ?
                       AND surface = 'coach'
                       AND capability_key = ?
                     ORDER BY id DESC
                     LIMIT 1",
                    [$workspaceId, $userId, $capability]
                ) ?: [];
                if ((string) ($latest['status'] ?? '') === $status) {
                    continue;
                }

                Database::execute(
                    "INSERT INTO ai_capability_state_log (workspace_id, user_id, surface, capability_key, status, reason, metadata_json)
                     VALUES (?, ?, 'coach', ?, ?, ?, ?)",
                    [
                        $workspaceId,
                        $userId,
                        $capability,
                        $status,
                        $status === 'ready' ? 'AI Coach readiness transition reached.' : 'AI Coach readiness transition not yet reached.',
                        json_encode([
                            'operating_maturity' => (string) ($readiness['operating_maturity'] ?? ''),
                            'personal_brief_source' => (string) ($readiness['personal_brief_source'] ?? 'missing'),
                            'context_sources' => (array) ($readiness['context_sources'] ?? []),
                        ], JSON_UNESCAPED_SLASHES),
                    ]
                );
            } catch (\Throwable $e) {
                error_log('AI Coach readiness transition log failed: ' . $e->getMessage());
            }
        }
    }
}
