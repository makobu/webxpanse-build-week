<?php

declare(strict_types=1);

namespace CRM\Services;

use CRM\Database;

final class PresentationWorkspaceCapabilityService
{
    /** @var list<string> */
    private const DEMO_SKILLS = [
        WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS,
        WorkspaceSkillCatalogService::SKILL_AI_COACH,
    ];

    public function __construct(
        private ?PresentationWorkspaceGuardService $guard = null,
        private ?WorkspaceSkillCatalogService $catalog = null
    ) {
        $this->guard = $guard ?? new PresentationWorkspaceGuardService();
        $this->catalog = $catalog ?? new WorkspaceSkillCatalogService();
    }

    /**
     * Install only the two capabilities required by the founder presentation
     * story. This intentionally bypasses Marketplace billing gates only after
     * the workspace has been marked as an active presentation workspace.
     *
     * @return array{installed:int,skills:list<string>}
     */
    public function installForWorkspace(int $workspaceId, int $userId, int $sessionId): array
    {
        if ($workspaceId <= 0 || $userId <= 0 || $sessionId <= 0) {
            throw new \InvalidArgumentException('Presentation workspace, user, and session are required.');
        }
        if (!$this->guard->isPresentationWorkspace($workspaceId) || $this->guard->status($workspaceId) !== 'active') {
            throw new \RuntimeException('Presentation capabilities can only be installed in an active presentation workspace.');
        }
        if (!Database::tableExists('workspace_skill_installs')) {
            throw new \RuntimeException('Workspace skill installs are unavailable.');
        }

        $this->catalog->syncDefinitions();
        $installed = [];
        foreach (self::DEMO_SKILLS as $skillKey) {
            $definition = $this->catalog->findForWorkspace($skillKey, $workspaceId, true);
            if ($definition === null || $this->catalog->isGloballyDeactivated($skillKey)) {
                throw new \RuntimeException('Required presentation capability is unavailable: ' . $skillKey);
            }

            $config = [
                'source' => 'presentation_workspace',
                'demo_only' => true,
                'simulation_only' => true,
                'presentation_session_id' => $sessionId,
            ];
            if ($skillKey === WorkspaceSkillCatalogService::SKILL_AI_COACH) {
                $config['ai_coach_enabled'] = true;
                $config['ai_coach'] = [
                    'enabled' => true,
                    'enabled_at' => gmdate('c'),
                    'updated_by_user_id' => $userId,
                ];
            }

            Database::execute(
                "INSERT INTO workspace_skill_installs (
                    workspace_id, skill_key, status, config_json, installed_by_user_id,
                    updated_by_user_id, installed_at, disabled_at, uninstalled_at
                 ) VALUES (?, ?, 'installed', ?, ?, ?, NOW(), NULL, NULL)
                 ON DUPLICATE KEY UPDATE
                    status = 'installed',
                    config_json = VALUES(config_json),
                    updated_by_user_id = VALUES(updated_by_user_id),
                    disabled_at = NULL,
                    uninstalled_at = NULL,
                    updated_at = NOW()",
                [
                    $workspaceId,
                    $skillKey,
                    json_encode($config, JSON_UNESCAPED_SLASHES),
                    $userId,
                    $userId,
                ]
            );

            if (Database::tableExists('workspace_skill_events')) {
                Database::execute(
                    "INSERT INTO workspace_skill_events
                        (workspace_id, skill_key, event_type, actor_user_id, metadata_json)
                     VALUES (?, ?, 'installed', ?, ?)",
                    [
                        $workspaceId,
                        $skillKey,
                        $userId,
                        json_encode([
                            'source' => 'presentation_workspace',
                            'presentation_session_id' => $sessionId,
                            'simulation_only' => true,
                        ], JSON_UNESCAPED_SLASHES),
                    ]
                );
            }

            $installed[] = $skillKey;
        }

        return ['installed' => count($installed), 'skills' => $installed];
    }
}
