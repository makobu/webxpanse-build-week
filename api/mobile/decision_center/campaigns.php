<?php

require_once __DIR__ . '/_bootstrap.php';

use CRM\Services\MobileCampaignMonitoringService;

mobileDecisionRequire('campaigns.manage');
$method = mobileDecisionRequireMethod('GET', 'POST');

try {
    $service = new MobileCampaignMonitoringService();
    if ($method === 'POST') {
        $campaignId = (int) ($mobileDecisionInput['campaign_id'] ?? 0);
        $action = strtolower(trim((string) ($mobileDecisionInput['action'] ?? '')));
        $note = trim((string) ($mobileDecisionInput['note'] ?? ''));
        if ($campaignId <= 0) {
            throw new InvalidArgumentException('Campaign is required.');
        }
        $campaign = $service->control($campaignId, $action, $mobileDecisionUserId, $note);
        mobileJson([
            'success' => true,
            'data' => [
                'message' => $action === 'pause'
                    ? 'Campaign paused.'
                    : ($action === 'resume' ? 'Campaign resumed.' : 'Campaign warning acknowledged.'),
                'campaign' => $campaign,
            ],
        ]);
    }

    mobileJson(['success' => true, 'data' => $service->dashboard()]);
} catch (Throwable $e) {
    error_log('Mobile Decision Center campaign monitoring failed: ' . $e->getMessage());
    mobileJson([
        'success' => false,
        'error' => $e->getMessage(),
        'error_code' => 'campaign_monitoring_failed',
    ], 422);
}
