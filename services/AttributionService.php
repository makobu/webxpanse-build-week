<?php
/**
 * Attribution Service
 *
 * Computes attribution credits for conversions from touchpoints.
 */

namespace CRM\Services;

use CRM\Database;

class AttributionService
{
    private AnalyticsWorkspaceService $analyticsWorkspace;

    public function __construct()
    {
        $this->analyticsWorkspace = new AnalyticsWorkspaceService();
    }

    public function recomputeForDeal(int $dealId, ?string $modelSlug = null): int
    {
        $workspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId();
        $deal = Database::queryOne(
            "SELECT id, contact_id, campaign_id, stage, value, workspace_id, COALESCE(actual_close_date, DATE(updated_at)) AS conversion_date
             FROM deals
             WHERE workspace_id = ? AND id = ?",
            [$workspaceId, $dealId]
        );

        if (!$deal || (string) $deal['stage'] !== 'closed_won') {
            return 0;
        }

        if ($modelSlug !== null) {
            return $this->computeForModel($deal, $modelSlug);
        }

        $models = Database::query("SELECT slug FROM attribution_models WHERE is_active = 1");
        $inserted = 0;
        foreach ($models as $model) {
            $inserted += $this->computeForModel($deal, $model['slug']);
        }
        return $inserted;
    }

    public function recomputeAllWonDeals(?string $modelSlug = null, int $limit = 1000): array
    {
        $workspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId();
        $deals = Database::query(
            "SELECT id
             FROM deals
             WHERE workspace_id = ?
               AND stage = 'closed_won'
             ORDER BY COALESCE(actual_close_date, DATE(updated_at)) DESC
             LIMIT " . min(max((int) $limit, 1), 5000),
            [$workspaceId]
        );

        $dealCount = 0;
        $rowsInserted = 0;
        foreach ($deals as $deal) {
            $dealCount++;
            $rowsInserted += $this->recomputeForDeal((int) $deal['id'], $modelSlug);
        }

        return ['deals' => $dealCount, 'rows' => $rowsInserted];
    }

    public function getRevenueByCampaign(string $modelSlug, ?string $dateFrom = null, ?string $dateTo = null): array
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
            "SELECT ar.campaign_id, campaign.name AS campaign_name,
                    SUM(ar.credited_value) AS credited_revenue,
                    COUNT(DISTINCT ar.deal_id) AS deals_count
             FROM attribution_results ar
             JOIN attribution_models am ON am.id = ar.model_id
             LEFT JOIN contacts contact ON contact.id = ar.contact_id AND contact.workspace_id = ar.workspace_id
             LEFT JOIN deals d ON d.id = ar.deal_id AND d.workspace_id = ar.workspace_id
             LEFT JOIN campaigns campaign ON campaign.id = ar.campaign_id AND campaign.workspace_id = ar.workspace_id
             WHERE " . implode(' AND ', $where) . "
             GROUP BY ar.campaign_id, campaign.name
             ORDER BY credited_revenue DESC",
            $params
        );
    }

    public function compareModelsForDeal(int $dealId): array
    {
        $workspaceId = $this->analyticsWorkspace->requireAnalyticsWorkspaceId();
        return Database::query(
            "SELECT am.slug, ar.channel, ar.touch_type, ar.attribution_weight, ar.credited_value, ar.campaign_id
             FROM attribution_results ar
             JOIN attribution_models am ON am.id = ar.model_id
             LEFT JOIN deals d ON d.id = ar.deal_id AND d.workspace_id = ar.workspace_id
             WHERE ar.deal_id = ?
               AND ar.workspace_id = ?
             ORDER BY am.slug, ar.attribution_weight DESC",
            [$dealId, $workspaceId]
        );
    }

    private function computeForModel(array $deal, string $modelSlug): int
    {
        $model = Database::queryOne(
            "SELECT * FROM attribution_models WHERE slug = ? AND is_active = 1 LIMIT 1",
            [$modelSlug]
        );
        if (!$model) {
            return 0;
        }

        $settings = json_decode($model['settings'] ?? '{}', true) ?? [];
        $lookbackDays = max(1, (int) ($settings['lookback_days'] ?? 90));
        $conversionAt = $deal['conversion_date'] . ' 00:00:00';
        $lookbackStart = date('Y-m-d H:i:s', strtotime($conversionAt . ' -' . $lookbackDays . ' days'));

        $touchpoints = Database::query(
            "SELECT *
             FROM touchpoints
             WHERE contact_id = ?
               AND (
                   EXISTS (SELECT 1 FROM contacts c WHERE c.id = touchpoints.contact_id AND c.workspace_id = ?)
                   OR EXISTS (SELECT 1 FROM campaigns campaign WHERE campaign.id = touchpoints.campaign_id AND campaign.workspace_id = ?)
               )
               AND occurred_at BETWEEN ? AND ?
             ORDER BY occurred_at ASC, id ASC",
            [(int) $deal['contact_id'], (int) ($deal['workspace_id'] ?? 0), (int) ($deal['workspace_id'] ?? 0), $lookbackStart, $conversionAt]
        );

        if (empty($touchpoints)) {
            return 0;
        }

        $weights = $this->computeWeights($modelSlug, $touchpoints, $conversionAt, $settings);
        if (empty($weights)) {
            return 0;
        }

        Database::execute(
            "DELETE ar FROM attribution_results ar
             JOIN attribution_models am ON am.id = ar.model_id
             WHERE ar.workspace_id = ? AND ar.deal_id = ? AND am.slug = ?",
            [(int) ($deal['workspace_id'] ?? 0), (int) $deal['id'], $modelSlug]
        );

        $dealValue = (float) ($deal['value'] ?? 0);
        $inserted = 0;
        foreach ($weights as $touchpointId => $weight) {
            if ($weight <= 0) {
                continue;
            }
            $tp = $touchpoints[$touchpointId] ?? null;
            if (!$tp) {
                continue;
            }

            Database::execute(
                "INSERT INTO attribution_results
                 (workspace_id, model_id, contact_id, deal_id, campaign_id, conversion_event, conversion_at, touchpoint_id, touch_type, channel, attribution_weight, credited_value)
                 VALUES (?, ?, ?, ?, ?, 'deal.closed_won', ?, ?, ?, ?, ?, ?)",
                [
                    (int) ($deal['workspace_id'] ?? 0),
                    (int) $model['id'],
                    (int) $deal['contact_id'],
                    (int) $deal['id'],
                    !empty($tp['campaign_id']) ? (int) $tp['campaign_id'] : (!empty($deal['campaign_id']) ? (int) $deal['campaign_id'] : null),
                    $conversionAt,
                    (int) $tp['id'],
                    (string) $tp['touch_type'],
                    (string) $tp['channel'],
                    (float) $weight,
                    round($dealValue * (float) $weight, 2)
                ]
            );
            $inserted++;
        }

        return $inserted;
    }

    /**
     * @param array<int,array<string,mixed>> $touchpoints
     * @return array<int,float> touchpointArrayIndex => weight
     */
    private function computeWeights(string $modelSlug, array $touchpoints, string $conversionAt, array $settings): array
    {
        $count = count($touchpoints);
        if ($count === 0) {
            return [];
        }

        if ($modelSlug === 'first_touch') {
            return [0 => 1.0];
        }

        if ($modelSlug === 'last_touch') {
            return [$count - 1 => 1.0];
        }

        if ($modelSlug === 'linear') {
            $w = 1.0 / $count;
            $result = [];
            for ($i = 0; $i < $count; $i++) {
                $result[$i] = $w;
            }
            return $result;
        }

        if ($modelSlug === 'position_based') {
            if ($count === 1) {
                return [0 => 1.0];
            }
            if ($count === 2) {
                return [0 => 0.5, 1 => 0.5];
            }
            $firstWeight = (float) ($settings['first_weight'] ?? 0.4);
            $middleWeight = (float) ($settings['middle_weight'] ?? 0.2);
            $lastWeight = (float) ($settings['last_weight'] ?? 0.4);
            $middleCount = max(1, $count - 2);
            $middlePer = $middleWeight / $middleCount;

            $result = [0 => $firstWeight, $count - 1 => $lastWeight];
            for ($i = 1; $i < ($count - 1); $i++) {
                $result[$i] = $middlePer;
            }
            return $this->normalizeWeights($result);
        }

        // Default to time-decay for unknown slugs that request decay behavior.
        $halfLifeDays = max(1.0, (float) ($settings['half_life_days'] ?? 7.0));
        $raw = [];
        foreach ($touchpoints as $index => $tp) {
            $seconds = max(0, strtotime($conversionAt) - strtotime((string) $tp['occurred_at']));
            $days = $seconds / 86400;
            $raw[$index] = pow(0.5, $days / $halfLifeDays);
        }

        return $this->normalizeWeights($raw);
    }

    /**
     * @param array<int,float> $weights
     * @return array<int,float>
     */
    private function normalizeWeights(array $weights): array
    {
        $sum = array_sum($weights);
        if ($sum <= 0) {
            return [];
        }
        foreach ($weights as $key => $value) {
            $weights[$key] = $value / $sum;
        }
        return $weights;
    }
}
