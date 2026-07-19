<?php

namespace CRM\Services;

use CRM\Modules\CompanyProfile;
use CRM\Modules\MeetingBotConfig;

class MeetingBotIdentityService
{
    public function __construct(
        private ?MeetingBotConfig $config = null,
        private ?CompanyProfile $companyProfile = null,
    ) {
        $this->config = $this->config ?? new MeetingBotConfig();
        $this->companyProfile = $this->companyProfile ?? new CompanyProfile();
    }

    public function resolveDisplayName(?array $config = null): string
    {
        $config = $config ?? $this->config->get();
        $configured = trim((string) ($config['bot_display_name'] ?? ''));
        if ($configured !== '') {
            return $configured;
        }

        $profile = $this->companyProfile->get() ?? [];
        $companyName = trim((string) ($profile['company_name'] ?? ''));
        if ($companyName !== '') {
            return $companyName . ' Meeting Assistant';
        }

        $brandProduct = trim((string) brandProductName());
        if ($brandProduct !== '') {
            return $brandProduct . ' Meeting Assistant';
        }

        $assistant = trim((string) brandAssistantName());
        return $assistant !== '' ? $assistant . ' Meeting Assistant' : 'Meeting Assistant';
    }
}
