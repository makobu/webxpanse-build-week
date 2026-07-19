<?php

namespace CRM\Services;

final class WorkspaceSmsRuntimeGateService
{
    public function __construct(
        private readonly ?WorkspaceSmsChannelConfigService $configService = null,
        private readonly ?WorkspaceSkillCatalogService $catalogService = null,
        private readonly ?WorkspaceSkillInstallService $installService = null
    ) {
    }

    public function requireOutboundConfig(int $workspaceId, bool $allowLegacyFallback = false): array
    {
        if ($workspaceId <= 0) {
            throw new \RuntimeException('A workspace is required to send SMS.');
        }

        $catalog = $this->catalogService ?? new WorkspaceSkillCatalogService();
        if ($catalog->isGloballyDeactivated(WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL)) {
            throw new \RuntimeException('SMS Channel is deactivated by the platform.');
        }

        $installer = $this->installService ?? new WorkspaceSkillInstallService($catalog);
        if (!$installer->isInstalled($workspaceId, WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL)) {
            throw new \RuntimeException('SMS Channel is not installed for this workspace.');
        }

        $config = ($this->configService ?? new WorkspaceSmsChannelConfigService())
            ->runtimeConfig($workspaceId, $allowLegacyFallback);
        if (empty($config['ready'])) {
            throw new \RuntimeException('Twilio credentials are not configured for this workspace.');
        }

        return $config;
    }
}
