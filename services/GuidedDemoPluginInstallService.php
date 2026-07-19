<?php

declare(strict_types=1);

namespace CRM\Services;

use CRM\Database;

class GuidedDemoPluginInstallService
{
    public function __construct(private ?WorkspaceSkillCatalogService $catalog = null)
    {
        $this->catalog = $catalog ?? new WorkspaceSkillCatalogService();
    }

    /**
     * @return array{installed:int,preexisting:int,created:int,errors:list<string>}
     */
    public function installSimulatedForSession(int $sessionId, int $workspaceId, int $userId): array
    {
        $summary = ['installed' => 0, 'preexisting' => 0, 'created' => 0, 'errors' => []];
        if ($sessionId <= 0 || $workspaceId <= 0 || !Database::tableExists('workspace_skill_installs')) {
            return $summary;
        }

        $this->catalog->syncDefinitions();
        foreach ($this->catalog->availableForWorkspace($workspaceId, true) as $skill) {
            $skillKey = trim((string) ($skill['key'] ?? ''));
            if ($skillKey === '' || $this->catalog->isGloballyDeactivated($skillKey)) {
                continue;
            }

            try {
                $before = Database::queryOne(
                    "SELECT id, status, config_json
                     FROM workspace_skill_installs
                     WHERE workspace_id = ? AND skill_key = ?
                     LIMIT 1",
                    [$workspaceId, $skillKey]
                );
                $wasPreexisting = $before !== null;
                $previousConfig = (string) ($before['config_json'] ?? '');
                $config = $this->decodeAssoc($previousConfig);
                $config['source'] = 'guided_demo';
                $config['demo_only'] = true;
                $config['session_id'] = $sessionId;
                $config['simulation_only'] = true;

                Database::execute(
                    "INSERT INTO guided_demo_plugin_installs
                        (session_id, workspace_id, skill_key, install_id, was_preexisting, previous_status, previous_config_json)
                     VALUES (?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                        install_id = VALUES(install_id),
                        was_preexisting = VALUES(was_preexisting),
                        previous_status = COALESCE(guided_demo_plugin_installs.previous_status, VALUES(previous_status)),
                        previous_config_json = COALESCE(guided_demo_plugin_installs.previous_config_json, VALUES(previous_config_json)),
                        updated_at = NOW()",
                    [
                        $sessionId,
                        $workspaceId,
                        $skillKey,
                        $before !== null ? (int) ($before['id'] ?? 0) : null,
                        $wasPreexisting ? 1 : 0,
                        $before !== null ? (string) ($before['status'] ?? 'disabled') : null,
                        $before !== null ? ($previousConfig !== '' ? $previousConfig : null) : null,
                    ]
                );

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
                        $userId > 0 ? $userId : null,
                        $userId > 0 ? $userId : null,
                    ]
                );

                $after = Database::queryOne(
                    "SELECT id FROM workspace_skill_installs WHERE workspace_id = ? AND skill_key = ? LIMIT 1",
                    [$workspaceId, $skillKey]
                );
                if (!empty($after['id'])) {
                    Database::execute(
                        "UPDATE guided_demo_plugin_installs
                         SET install_id = ?, updated_at = NOW()
                         WHERE session_id = ? AND skill_key = ?",
                        [(int) $after['id'], $sessionId, $skillKey]
                    );
                }

                $summary['installed']++;
                $summary[$wasPreexisting ? 'preexisting' : 'created']++;
            } catch (\Throwable $e) {
                $summary['errors'][] = $skillKey . ': ' . $e->getMessage();
            }
        }

        return $summary;
    }

    /**
     * @return array{restored:int,removed:int,errors:list<string>}
     */
    public function restoreForSession(int $sessionId, int $workspaceId, int $userId): array
    {
        $summary = ['restored' => 0, 'removed' => 0, 'errors' => []];
        if ($sessionId <= 0 || $workspaceId <= 0 || !Database::tableExists('guided_demo_plugin_installs')) {
            return $summary;
        }

        $rows = Database::query(
            "SELECT *
             FROM guided_demo_plugin_installs
             WHERE session_id = ? AND workspace_id = ?
             ORDER BY id DESC",
            [$sessionId, $workspaceId]
        );

        foreach ($rows as $row) {
            $skillKey = trim((string) ($row['skill_key'] ?? ''));
            if ($skillKey === '') {
                continue;
            }

            try {
                if (!empty($row['was_preexisting'])) {
                    $previousStatus = in_array((string) ($row['previous_status'] ?? ''), ['installed', 'disabled'], true)
                        ? (string) $row['previous_status']
                        : 'disabled';
                    $previousConfig = $row['previous_config_json'] ?? null;
                    Database::execute(
                        "UPDATE workspace_skill_installs
                         SET status = ?,
                             config_json = ?,
                             updated_by_user_id = ?,
                             disabled_at = IF(? = 'disabled', COALESCE(disabled_at, NOW()), NULL),
                             uninstalled_at = IF(? = 'disabled', COALESCE(uninstalled_at, NOW()), NULL),
                             updated_at = NOW()
                         WHERE workspace_id = ? AND skill_key = ?",
                        [
                            $previousStatus,
                            $previousConfig !== '' ? $previousConfig : null,
                            $userId > 0 ? $userId : null,
                            $previousStatus,
                            $previousStatus,
                            $workspaceId,
                            $skillKey,
                        ]
                    );
                    $summary['restored']++;
                } else {
                    Database::execute(
                        "UPDATE workspace_skill_installs
                         SET status = 'disabled',
                             updated_by_user_id = ?,
                             disabled_at = COALESCE(disabled_at, NOW()),
                             uninstalled_at = COALESCE(uninstalled_at, NOW()),
                             updated_at = NOW()
                         WHERE workspace_id = ? AND skill_key = ?",
                        [$userId > 0 ? $userId : null, $workspaceId, $skillKey]
                    );
                    $summary['removed']++;
                }

                Database::execute(
                    "UPDATE guided_demo_plugin_installs
                     SET restored_at = COALESCE(restored_at, NOW()), updated_at = NOW()
                     WHERE id = ?",
                    [(int) $row['id']]
                );
            } catch (\Throwable $e) {
                $summary['errors'][] = $skillKey . ': ' . $e->getMessage();
            }
        }

        return $summary;
    }

    private function decodeAssoc(?string $json): array
    {
        $decoded = json_decode((string) $json, true);
        return is_array($decoded) ? $decoded : [];
    }
}
