<?php

use CRM\Services\MarketingMarketplaceGateService;
use CRM\Authorization;

if (!function_exists('crm_require_marketing_marketplace_access')) {
    function crm_require_marketing_marketplace_access(?array $user = null, ?string $feature = null): void
    {
        if (!Authorization::can('marketing.read', $user)) {
            return;
        }

        $gate = new MarketingMarketplaceGateService();
        $feature = $feature ?: $gate->featureForPage((string) ($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? ''));
        if ($gate->canRun($user, $feature)) {
            return;
        }

        $target = $gate->marketplaceUrl($feature) . '&source=marketing_gate';
        if (function_exists('getBasePath')) {
            $target = rtrim(getBasePath(), '/') . '/' . $target;
        }

        header('Location: ' . $target);
        exit;
    }
}
