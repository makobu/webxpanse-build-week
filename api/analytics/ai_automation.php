<?php

require __DIR__ . '/_bootstrap.php';

$canViewAutomation = \CRM\Authorization::can('ai.operations.manage', $user)
    || \CRM\Authorization::can('feature.automation_battery', $user)
    || \CRM\Authorization::can('feature.automation_readiness', $user)
    || \CRM\Authorization::hasAccessProfilePermission('feature.automation_readiness_card', $user);

if (!$canViewAutomation) {
    $forbidden();
}

try {
    $respond($operatingAnalytics->getAIAutomationAnalytics($workspaceId, $viewerUserId, $subjectUserId, $timeframe));
} catch (\Throwable $e) {
    $fail('ai_automation', $e);
}
