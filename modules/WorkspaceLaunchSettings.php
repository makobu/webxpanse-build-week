<?php

declare(strict_types=1);

namespace CRM\Modules;

use CRM\Database;

class WorkspaceLaunchSettings
{
    public function get(): array
    {
        $defaults = [
            'id' => 1,
            'active_package' => ClarityPackageCatalog::PACKAGE_LAUNCH,
            'target_niche' => ClarityPackageCatalog::NICHE_SOLO_FOUNDERS,
            'launch_model' => 'service_led_saas',
            'success_milestone' => 'first_paid_signal',
            'demo_scenario_key' => null,
            'notes' => null,
        ];

        if (!$this->tableExists()) {
            return $defaults;
        }

        $columns = [
            'id',
            'active_package',
            'target_niche',
            'launch_model',
            'success_milestone',
            'notes',
        ];
        if ($this->columnExists('workspace_launch_settings', 'demo_scenario_key')) {
            $columns[] = 'demo_scenario_key';
        }

        $row = Database::queryOne('SELECT ' . implode(', ', $columns) . ' FROM workspace_launch_settings WHERE id = 1 LIMIT 1');
        if (!$row) {
            return $defaults;
        }

        return array_merge($defaults, $row);
    }

    public function update(array $data, int $userId): bool
    {
        if (!$this->tableExists()) {
            return false;
        }

        $current = $this->get();
        $sql = 'UPDATE workspace_launch_settings
                SET active_package = ?, target_niche = ?, launch_model = ?, success_milestone = ?';
        $params = [
            (string) ($data['active_package'] ?? $current['active_package']),
            (string) ($data['target_niche'] ?? $current['target_niche']),
            (string) ($data['launch_model'] ?? $current['launch_model']),
            (string) ($data['success_milestone'] ?? $current['success_milestone']),
        ];

        if ($this->columnExists('workspace_launch_settings', 'demo_scenario_key')) {
            $sql .= ', demo_scenario_key = ?';
            $params[] = ($data['demo_scenario_key'] ?? $current['demo_scenario_key']) !== null
                ? (string) ($data['demo_scenario_key'] ?? $current['demo_scenario_key'])
                : null;
        }

        $sql .= ', notes = ?, updated_by = ? WHERE id = 1';
        $params[] = (string) ($data['notes'] ?? $current['notes']);
        $params[] = $userId;

        Database::execute($sql, $params);

        return true;
    }

    public function tableExists(): bool
    {
        return Database::tableExists('workspace_launch_settings');
    }

    private function columnExists(string $tableName, string $columnName): bool
    {
        return Database::columnExists($tableName, $columnName);
    }
}
