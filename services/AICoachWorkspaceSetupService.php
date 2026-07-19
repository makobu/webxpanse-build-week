<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\IdeaValidationContext;
use CRM\Modules\UserStrategyProfile;
use CRM\Modules\UserStrategySnapshot;

class AICoachWorkspaceSetupService
{
    private WorkspaceSkillInstallService $installer;

    public function __construct(?WorkspaceSkillInstallService $installer = null)
    {
        $this->installer = $installer ?? new WorkspaceSkillInstallService();
    }

    public function isInstalled(int $workspaceId): bool
    {
        return $workspaceId > 0
            && $this->installer->isInstalled($workspaceId, WorkspaceSkillCatalogService::SKILL_AI_COACH);
    }

    public function isWorkspaceEnabled(int $workspaceId): bool
    {
        if (!$this->isInstalled($workspaceId)) {
            return false;
        }

        $config = $this->getInstallConfig($workspaceId);
        if (array_key_exists('ai_coach', $config) && is_array($config['ai_coach']) && array_key_exists('enabled', $config['ai_coach'])) {
            return filter_var($config['ai_coach']['enabled'], FILTER_VALIDATE_BOOL);
        }
        if (array_key_exists('ai_coach_enabled', $config)) {
            return filter_var($config['ai_coach_enabled'], FILTER_VALIDATE_BOOL);
        }
        if (array_key_exists('enabled', $config)) {
            return filter_var($config['enabled'], FILTER_VALIDATE_BOOL);
        }

        return true;
    }

    public function setWorkspaceEnabled(int $workspaceId, int $actorUserId, bool $enabled): void
    {
        if ($workspaceId <= 0) {
            throw new \InvalidArgumentException('Workspace is required.');
        }

        $now = gmdate('c');
        $config = $this->getInstallConfig($workspaceId);
        if (!$this->isInstalled($workspaceId)) {
            $config = [
                'source' => 'workspace_marketplace_setup',
                'ai_coach_enabled' => $enabled,
                'ai_coach' => [
                    'enabled' => $enabled,
                    'enabled_at' => $enabled ? $now : null,
                    'disabled_at' => $enabled ? null : $now,
                    'updated_at' => $now,
                    'updated_by_user_id' => $actorUserId > 0 ? $actorUserId : null,
                ],
            ];
            $this->installer->install($workspaceId, WorkspaceSkillCatalogService::SKILL_AI_COACH, $actorUserId, $config);
            return;
        }

        $aiCoachConfig = is_array($config['ai_coach'] ?? null) ? $config['ai_coach'] : [];
        $aiCoachConfig['enabled'] = $enabled;
        $aiCoachConfig['updated_at'] = $now;
        $aiCoachConfig['updated_by_user_id'] = $actorUserId > 0 ? $actorUserId : null;
        if ($enabled) {
            $aiCoachConfig['enabled_at'] = (string) ($aiCoachConfig['enabled_at'] ?? $now);
            $aiCoachConfig['disabled_at'] = null;
        } else {
            $aiCoachConfig['disabled_at'] = $now;
        }

        $config['ai_coach'] = $aiCoachConfig;
        $config['ai_coach_enabled'] = $enabled;
        $config['updated_from'] = 'workspace_marketplace_setup';

        Database::execute(
            "UPDATE workspace_skill_installs
             SET config_json = ?,
                 updated_by_user_id = ?,
                 updated_at = NOW()
             WHERE workspace_id = ?
               AND skill_key = ?
               AND status = 'installed'
               AND uninstalled_at IS NULL",
            [
                json_encode($config, JSON_UNESCAPED_SLASHES),
                $actorUserId > 0 ? $actorUserId : null,
                $workspaceId,
                WorkspaceSkillCatalogService::SKILL_AI_COACH,
            ]
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function getDashboardBriefGate(int $workspaceId, int $userId): array
    {
        $readiness = (new AICoachReadinessService())->getReadiness($workspaceId, $userId);
        $installed = !empty($readiness['ai_coach_installed']);
        $workspaceEnabled = !empty($readiness['ai_coach_enabled']);
        $personalReady = !empty($readiness['personal_brief_ready']);
        $companyReady = !empty($readiness['company_context_ready']);
        $recommendationsReady = !empty($readiness['recommendations_ready']);

        return [
            'installed' => $installed,
            'workspace_enabled' => $workspaceEnabled,
            'required' => $installed && $workspaceEnabled,
            'should_prompt' => $installed && $workspaceEnabled && $companyReady && !$recommendationsReady,
            'personal_brief_ready' => $personalReady,
            'personal_strategy_optional' => !empty($readiness['personal_strategy_optional']),
            'personal_strategy_refinement_ready' => !empty($readiness['personal_strategy_refinement_ready']),
            'recommendations_ready' => $recommendationsReady,
            'coach_context_ready' => !empty($readiness['coach_context_ready']),
            'company_context_ready' => $companyReady,
            'operating_maturity' => (string) ($readiness['operating_maturity'] ?? AICoachOperatingMaturityService::PRE_CLARITY_JOURNEY),
            'operating_maturity_context' => (array) ($readiness['operating_maturity_context'] ?? []),
            'clarity_journey_ready' => !empty($readiness['clarity_journey_ready']),
            'clarity_journey_readiness' => (array) ($readiness['clarity_journey_readiness'] ?? []),
            'inherited_context_ready' => !empty($readiness['inherited_context_ready']),
            'personal_brief_source' => (string) ($readiness['personal_brief_source'] ?? 'missing'),
            'context_sources' => (array) ($readiness['context_sources'] ?? []),
            'remaining_personal_requirements' => (array) ($readiness['remaining_personal_requirements'] ?? []),
            'optional_personal_strategy_missing' => (array) ($readiness['optional_personal_strategy_missing'] ?? []),
            'missing_requirements' => (array) ($readiness['missing_requirements'] ?? []),
            'active_strategy_snapshot' => $readiness['active_strategy_snapshot'] ?? null,
            'onboarding_payload' => (array) ($readiness['onboarding_payload'] ?? []),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function getCurrentUserBriefPayload(int $workspaceId, int $userId, bool $syncSnapshot = false): array
    {
        if ($workspaceId <= 0 || $userId <= 0) {
            return [
                'strategy' => [],
                'idea_validation' => [],
                'source' => [],
                'personal_brief_ready' => false,
                'missing_requirements' => [],
                'active_strategy_snapshot' => null,
            ];
        }

        return (new UserStrategySnapshot())->getCurrentBrief($workspaceId, $userId, $syncSnapshot);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function getTeamBriefStatus(int $workspaceId): array
    {
        if ($workspaceId <= 0 || !Database::tableExists('workspace_memberships')) {
            return [];
        }

        $rows = Database::query(
            "SELECT wm.user_id, wm.role_slug, wm.is_owner, wm.joined_at,
                    u.first_name, u.last_name, u.email, u.role
             FROM workspace_memberships wm
             INNER JOIN users u ON u.id = wm.user_id
             WHERE wm.workspace_id = ?
               AND wm.membership_status = 'active'
             ORDER BY wm.is_owner DESC,
                      FIELD(wm.role_slug, 'superadmin', 'owner', 'admin', 'manager', 'member', 'viewer'),
                      u.email ASC",
            [$workspaceId]
        );

        $snapshots = new UserStrategySnapshot();
        $readinessService = new AICoachReadinessService();
        $team = [];
        foreach ($rows as $row) {
            $memberUserId = (int) ($row['user_id'] ?? 0);
            $brief = $memberUserId > 0 ? $snapshots->getCurrentBrief($workspaceId, $memberUserId, false) : [];
            try {
                $readiness = $memberUserId > 0 ? $readinessService->getReadiness($workspaceId, $memberUserId) : [];
            } catch (\Throwable $e) {
                $readiness = [];
            }
            $snapshot = (array) ($brief['active_strategy_snapshot'] ?? []);
            $team[] = [
                'user_id' => $memberUserId,
                'display_name' => $this->displayName($row),
                'email' => (string) ($row['email'] ?? ''),
                'role_slug' => (string) ($row['role_slug'] ?? $row['role'] ?? 'member'),
                'is_owner' => !empty($row['is_owner']),
                'joined_at' => (string) ($row['joined_at'] ?? ''),
                'personal_brief_ready' => !empty($brief['personal_brief_ready']),
                'missing_requirements' => (array) ($brief['missing_requirements'] ?? []),
                'clarity_journey_ready' => !empty($readiness['clarity_journey_ready']),
                'recommendations_ready' => !empty($readiness['recommendations_ready']),
                'coach_context_ready' => !empty($readiness['coach_context_ready']),
                'personal_strategy_optional' => !empty($readiness['personal_strategy_optional']),
                'personal_strategy_refinement_ready' => !empty($readiness['personal_strategy_refinement_ready']),
                'optional_personal_strategy_missing' => (array) ($readiness['optional_personal_strategy_missing'] ?? []),
                'active_strategy_snapshot' => $brief['active_strategy_snapshot'] ?? null,
                'last_snapshot_at' => (string) ($snapshot['started_at'] ?? ''),
            ];
        }

        return $team;
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function savePersonalBrief(int $workspaceId, int $userId, array $input): array
    {
        if ($workspaceId <= 0 || $userId <= 0) {
            throw new \InvalidArgumentException('Workspace and user are required.');
        }

        $strategyModule = new UserStrategyProfile();
        $ideaModule = new IdeaValidationContext();
        $strategy = $strategyModule->get($userId) ?: [];
        $idea = $ideaModule->get($userId) ?: [];

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
        foreach ($strategyFields as $field) {
            if (array_key_exists($field, $input)) {
                $strategy[$field] = trim((string) $input[$field]);
            }
        }
        $strategyModule->save($userId, $strategy);

        $ideaFields = [
            'value_proposition',
            'target_market',
            'pain_points',
            'assumptions_to_test',
            'competitors',
            'differentiator',
        ];
        foreach ($ideaFields as $field) {
            if (array_key_exists($field, $input)) {
                $idea[$field] = trim((string) $input[$field]);
            }
        }
        $ideaModule->save($userId, $idea);

        return $this->getCurrentUserBriefPayload($workspaceId, $userId, true);
    }

    /**
     * @return array<string,mixed>
     */
    private function getInstallConfig(int $workspaceId): array
    {
        if ($workspaceId <= 0 || !Database::tableExists('workspace_skill_installs')) {
            return [];
        }

        $row = Database::queryOne(
            "SELECT config_json
             FROM workspace_skill_installs
             WHERE workspace_id = ?
               AND skill_key = ?
               AND status = 'installed'
               AND uninstalled_at IS NULL
             LIMIT 1",
            [$workspaceId, WorkspaceSkillCatalogService::SKILL_AI_COACH]
        );
        if (!$row) {
            return [];
        }

        $decoded = json_decode((string) ($row['config_json'] ?? '{}'), true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string,mixed> $row
     */
    private function displayName(array $row): string
    {
        $name = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        return (string) ($row['email'] ?? 'Workspace member');
    }
}
