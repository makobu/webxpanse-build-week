<?php

require_once __DIR__ . '/_bootstrap.php';

use CRM\Authorization;
use CRM\Services\HRAnalyticsService;

Authorization::requirePermission('hr.analytics.view', true);
hrAnalyticsRequireRuntimeReady('swot');

$service = new HRAnalyticsService();

echo json_encode([
    'success' => true,
    'payload' => $service->buildSwotPayload(hrAnalyticsApiFilters($_GET)),
]);
