<?php
/**
 * Attribution Reports Module
 */

namespace CRM\Modules;

use CRM\Database;
use CRM\Services\AttributionService;
use CRM\Services\AnalyticsWorkspaceService;

class AttributionReports
{
    private AttributionService $service;
    private AnalyticsWorkspaceService $analyticsWorkspace;

    public function __construct()
    {
        $this->service = new AttributionService();
        $this->analyticsWorkspace = new AnalyticsWorkspaceService();
    }

    public function getModelOptions(): array
    {
        $workspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId();
        return Database::query(
            "SELECT DISTINCT am.slug, am.name, am.is_default
             FROM attribution_models am
             LEFT JOIN attribution_results ar ON ar.model_id = am.id AND ar.workspace_id = ?
             LEFT JOIN contacts c ON c.id = ar.contact_id AND c.workspace_id = ar.workspace_id
             LEFT JOIN deals d ON d.id = ar.deal_id AND d.workspace_id = ar.workspace_id
             LEFT JOIN campaigns cam ON cam.id = ar.campaign_id AND cam.workspace_id = ar.workspace_id
             WHERE am.is_active = 1
               AND (ar.id IS NULL OR ar.workspace_id = ?)
              ORDER BY is_default DESC, name ASC",
            [$workspaceId, $workspaceId]
        );
    }

    public function getRevenueByCampaign(string $modelSlug, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        return $this->service->getRevenueByCampaign($modelSlug, $dateFrom, $dateTo);
    }

    public function getChannelContribution(string $modelSlug, ?string $dateFrom = null, ?string $dateTo = null): array
    {
        $workspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId();
        $where = ["am.slug = ?", "ar.workspace_id = ?"];
        $params = [$modelSlug, $workspaceId];
        if ($dateFrom) {
            $where[] = "ar.conversion_at >= ?";
            $params[] = $dateFrom . ' 00:00:00';
        }
        if ($dateTo) {
            $where[] = "ar.conversion_at <= ?";
            $params[] = $dateTo . ' 23:59:59';
        }

        return Database::query(
            "SELECT ar.channel,
                    SUM(ar.credited_value) AS credited_revenue,
                    SUM(ar.attribution_weight) AS total_weight,
                    COUNT(DISTINCT ar.deal_id) AS deals_count
             FROM attribution_results ar
             JOIN attribution_models am ON am.id = ar.model_id
             LEFT JOIN contacts c ON c.id = ar.contact_id AND c.workspace_id = ar.workspace_id
             LEFT JOIN deals d ON d.id = ar.deal_id AND d.workspace_id = ar.workspace_id
             LEFT JOIN campaigns cam ON cam.id = ar.campaign_id AND cam.workspace_id = ar.workspace_id
             WHERE " . implode(' AND ', $where) . "
             GROUP BY ar.channel
             ORDER BY credited_revenue DESC",
            $params
        );
    }

    public function getTopJourneys(string $modelSlug, int $limit = 20): array
    {
        $workspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId();
        $limit = min(max((int) $limit, 1), 100);
        return Database::query(
            "SELECT ar.deal_id, ar.contact_id,
                    GROUP_CONCAT(CONCAT(ar.channel, ':', ar.touch_type) ORDER BY tp.occurred_at SEPARATOR ' -> ') AS journey,
                    SUM(ar.credited_value) AS credited_value
             FROM attribution_results ar
             JOIN attribution_models am ON am.id = ar.model_id
             LEFT JOIN touchpoints tp ON tp.id = ar.touchpoint_id
              LEFT JOIN contacts c ON c.id = ar.contact_id AND c.workspace_id = ar.workspace_id
              LEFT JOIN deals d ON d.id = ar.deal_id AND d.workspace_id = ar.workspace_id
              LEFT JOIN campaigns cam ON cam.id = ar.campaign_id AND cam.workspace_id = ar.workspace_id
              WHERE am.slug = ?
                AND ar.workspace_id = ?
             GROUP BY ar.deal_id, ar.contact_id
             ORDER BY credited_value DESC
             LIMIT " . $limit,
            [$modelSlug, $workspaceId]
        );
    }

    public function getModelComparisonForDeal(int $dealId): array
    {
        return $this->service->compareModelsForDeal($dealId);
    }
}
