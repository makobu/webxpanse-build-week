<?php

namespace CRM\Services;

use CRM\Modules\HRAnalyticsSettings;

class OrganizationIntelligenceEngineService
{
    public function __construct(private ?HRAnalyticsService $v2 = null)
    {
        $this->v2 ??= new HRAnalyticsService(new HRAnalyticsSettings());
    }

    public function buildDashboard(array $filters = [], bool $allowAi = false, array $options = []): array
    {
        $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        $profileService = new OrganizationIntelligenceProfileService();
        $profile = $profileService->get($workspaceId);
        $startedAt = microtime(true);
        try {
            $v2Dashboard = $this->v2->buildDashboard($filters, $allowAi, $options);
            $v2Dashboard['organization_profile'] = $profile;
            $v1Dashboard = (new OrganizationIntelligenceV1Adapter())->adapt($v2Dashboard, $workspaceId);
            if ((string) ($profile['rollout_state'] ?? '') === 'shadow') {
                $profileService->recordShadowComparison($workspaceId, $this->comparison($v1Dashboard, $v2Dashboard, $profile, $startedAt));
            }
            $serveV1 = (string) ($profile['engine_version'] ?? 'v2') === 'v1'
                && (string) ($profile['rollout_state'] ?? '') !== 'v1_retired';
            $result = $serveV1 ? $v1Dashboard : $v2Dashboard;
            $result['organization_profile'] = $profile;
            $result['data_quality']['active_engine_version'] = $serveV1 ? 'v1' : 'v2';
            $result['data_quality']['rollout_state'] = (string) ($profile['rollout_state'] ?? 'v2_enabled');
            return $result;
        } catch (\Throwable $e) {
            if ((string) ($profile['rollout_state'] ?? '') === 'shadow') {
                $profileService->recordShadowComparison($workspaceId, [
                    'status' => 'failed',
                    'error_code' => substr(preg_replace('/[^a-z0-9_]+/i', '_', strtolower(get_class($e))) ?: 'engine_error', 0, 60),
                    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                    'operating_model' => (string) ($profile['effective_model'] ?? ''),
                ]);
            }
            throw $e;
        }
    }

    private function comparison(array $v1, array $v2, array $profile, float $startedAt): array
    {
        $v1Health = $v1['organization_health']['score'] ?? null;
        $v2Health = $v2['organization_health']['score'] ?? null;
        return [
            'v1_health' => is_numeric($v1Health) ? (float) $v1Health : null,
            'v2_health' => is_numeric($v2Health) ? (float) $v2Health : null,
            'health_delta' => is_numeric($v1Health) && is_numeric($v2Health) ? round((float) $v2Health - (float) $v1Health, 1) : null,
            'v1_eligible_people' => count((array) ($v1['employees'] ?? [])),
            'v2_eligible_people' => (int) ($v2['data_quality']['eligible_people_count'] ?? 0),
            'evidence_coverage' => (float) ($v2['data_quality']['average_evidence_coverage'] ?? 0),
            'operating_model' => (string) ($profile['effective_model'] ?? ''),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'status' => 'ok',
        ];
    }
}
