<?php

require_once __DIR__ . '/_bootstrap.php';

use CRM\Authorization;
use CRM\Services\HRAnalyticsService;

Authorization::requirePermission('hr.analytics.view', true);
hrAnalyticsRequireRuntimeReady('summary');

$service = new HRAnalyticsService();

echo json_encode([
    'success' => true,
    'payload' => $service->buildSummaryPayload(hrAnalyticsApiFilters($_GET)),
]);
