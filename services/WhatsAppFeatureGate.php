<?php

namespace CRM\Services;

use CRM\Database;

class WhatsAppFeatureGate
{
    public const DUAL_SETUP_MODES = 'whatsapp_dual_setup_modes';
    public const MANAGED_BILLING = 'whatsapp_managed_billing';
    public const TEMPLATE_CENTER = 'whatsapp_template_center';

    /** @var array<string,mixed>|null */
    private ?array $flags = null;

    public function dualSetupModesEnabled(int $workspaceId = 0): bool
    {
        return $this->isEnabled(self::DUAL_SETUP_MODES, $workspaceId);
    }

    public function templateCenterEnabled(int $workspaceId = 0): bool
    {
        return $this->isEnabled(self::TEMPLATE_CENTER, $workspaceId);
    }

    public function managedBillingEnabled(int $workspaceId = 0): bool
    {
        if ($this->isEnabled(self::MANAGED_BILLING, $workspaceId)) {
            return true;
        }

        return $workspaceId > 0 && $this->isManagedBillingAllowlisted($workspaceId);
    }

    /**
     * @return array{whatsapp_dual_setup_modes:bool,whatsapp_template_center:bool,whatsapp_managed_billing:bool,managed_billing_allowlisted:bool}
     */
    public function snapshot(int $workspaceId = 0): array
    {
        return [
            self::DUAL_SETUP_MODES => $this->dualSetupModesEnabled($workspaceId),
            self::TEMPLATE_CENTER => $this->templateCenterEnabled($workspaceId),
            self::MANAGED_BILLING => $this->managedBillingEnabled($workspaceId),
            'managed_billing_allowlisted' => $workspaceId > 0 && $this->isManagedBillingAllowlisted($workspaceId),
        ];
    }

    public function assertManagedBillingEnabled(int $workspaceId): void
    {
        if (!$this->managedBillingEnabled($workspaceId)) {
            throw new \RuntimeException('Managed WhatsApp billing is not enabled for this workspace.');
        }
    }

    public function assertTemplateCenterEnabled(int $workspaceId): void
    {
        if (!$this->templateCenterEnabled($workspaceId)) {
            throw new \RuntimeException('WhatsApp Template Center is not enabled for this workspace.');
        }
    }

    private function isEnabled(string $flag, int $workspaceId): bool
    {
        $workspaceOverride = $this->workspaceFlag($workspaceId, $flag);
        if ($workspaceOverride !== null) {
            return $workspaceOverride;
        }

        $flags = $this->platformFlags();
        if (!array_key_exists($flag, $flags)) {
            return false;
        }

        return $this->truthy($flags[$flag]);
    }

    private function isManagedBillingAllowlisted(int $workspaceId): bool
    {
        if ($workspaceId <= 0) {
            return false;
        }

        $workspaceOverride = $this->workspaceFlag($workspaceId, self::MANAGED_BILLING);
        if ($workspaceOverride === true) {
            return true;
        }

        $flags = $this->platformFlags();
        foreach (['whatsapp_managed_billing_workspace_ids', 'whatsapp_managed_billing_allowlist'] as $key) {
            $ids = $this->normalizeWorkspaceIds($flags[$key] ?? []);
            if (in_array($workspaceId, $ids, true)) {
                return true;
            }
        }

        $envIds = $this->normalizeWorkspaceIds((string) ($_ENV['WHATSAPP_MANAGED_BILLING_WORKSPACE_IDS'] ?? ''));
        return in_array($workspaceId, $envIds, true);
    }

    /**
     * @return array<string,mixed>
     */
    private function platformFlags(): array
    {
        if ($this->flags !== null) {
            return $this->flags;
        }

        $this->flags = [
            self::DUAL_SETUP_MODES => true,
            self::TEMPLATE_CENTER => true,
            self::MANAGED_BILLING => false,
            'whatsapp_managed_billing_workspace_ids' => [1],
        ];
        if (!Database::tableExists('workspace_skill_definitions')) {
            return $this->flags;
        }

        $row = Database::queryOne(
            "SELECT settings_schema_json, plugin_metadata_json
             FROM workspace_skill_definitions
             WHERE skill_key = ?
             ORDER BY owner_workspace_id IS NULL DESC, id ASC
             LIMIT 1",
            [WorkspaceSkillCatalogService::PLUGIN_WHATSAPP]
        );
        if (!$row) {
            return $this->flags;
        }

        foreach (['settings_schema_json', 'plugin_metadata_json'] as $column) {
            $decoded = json_decode((string) ($row[$column] ?? '{}'), true);
            if (!is_array($decoded)) {
                continue;
            }
            $featureFlags = $decoded['feature_flags'] ?? [];
            if (is_array($featureFlags)) {
                $this->flags = array_replace($this->flags, $featureFlags);
            }
        }

        return $this->flags;
    }

    private function workspaceFlag(int $workspaceId, string $flag): ?bool
    {
        if ($workspaceId <= 0 || !Database::tableExists('workspaces') || !Database::columnExists('workspaces', 'settings_json')) {
            return null;
        }

        $row = Database::queryOne("SELECT settings_json FROM workspaces WHERE id = ? LIMIT 1", [$workspaceId]);
        $settings = json_decode((string) ($row['settings_json'] ?? '{}'), true);
        if (!is_array($settings)) {
            return null;
        }

        $flags = $settings['feature_flags'] ?? [];
        if (!is_array($flags) || !array_key_exists($flag, $flags)) {
            return null;
        }

        return $this->truthy($flags[$flag]);
    }

    /**
     * @return list<int>
     */
    private function normalizeWorkspaceIds(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[,\s]+/', $value) ?: [];
        }
        if (!is_array($value)) {
            return [];
        }

        $ids = [];
        foreach ($value as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    private function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on', 'enabled'], true);
    }
}
