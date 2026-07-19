<?php

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_feature_helpers.php';

use CRM\Authorization;
use CRM\Services\OperatingAnalyticsService;
use CRM\Services\WorkspaceBusinessIntelligenceGateService;

$auth = mobileRequireAuth();
$user = mobileCurrentUser($auth);
$workspaceId = mobileWorkspaceId($auth);
$viewerUserId = (int) ($user['id'] ?? 0);

$gate = new WorkspaceBusinessIntelligenceGateService();
if (!$gate->canAccess($workspaceId, $user)) {
    $runtime = mobileAnalyticsRuntimeStatus($workspaceId, $user);
    $payload = $gate->jsonBlockPayload($workspaceId, $user, 'Analytics');
    $payload['setup_required'] = true;
    $payload['setup_url'] = !empty($runtime['can_manage_setup'])
        ? (string) ($runtime['setup_url'] ?? '')
        : null;
    mobileJson($payload, 402);
}

$canViewAutomation = Authorization::can('ai.operations.manage', $user)
    || Authorization::can('feature.automation_battery', $user)
    || Authorization::can('feature.automation_readiness', $user)
    || Authorization::hasAccessProfilePermission('feature.automation_readiness_card', $user);
$canViewSkills = Authorization::isSuperAdmin($user)
    || Authorization::can('workspace.skills.view', $user)
    || Authorization::can('workspace.skills.manage', $user);
$canViewOperations = Authorization::isSuperAdmin($user)
    || Authorization::can('admin.users.manage', $user)
    || Authorization::can('ai.operations.manage', $user)
    || Authorization::can('workflows.manage', $user);

$sectionDefinitions = [
    'operating' => ['label' => 'Operating', 'available' => true],
    'channels' => ['label' => 'Channels', 'available' => true],
    'ai_automation' => ['label' => 'AI & Automation', 'available' => $canViewAutomation],
    'founder_journey' => ['label' => 'Founder', 'available' => true],
    'targets_tasks' => ['label' => 'Targets & Tasks', 'available' => true],
    'marketplace_skills' => ['label' => 'Skills', 'available' => $canViewSkills],
    'operations' => ['label' => 'Operations', 'available' => $canViewOperations],
];

$requestedSection = strtolower(trim((string) ($_GET['section'] ?? 'operating')));
if (!isset($sectionDefinitions[$requestedSection])) {
    mobileJson([
        'success' => false,
        'error' => 'Unsupported analytics section.',
        'error_code' => 'analytics_section_invalid',
    ], 422);
}
if (empty($sectionDefinitions[$requestedSection]['available'])) {
    mobileJson([
        'success' => false,
        'error' => 'This analytics section is not available to this user.',
        'error_code' => 'permission_denied',
    ], 403);
}

$analytics = new OperatingAnalyticsService();
$timeframe = $analytics->normalizeTimeframe($_GET['timeframe'] ?? 'month');
$subjectUserId = $analytics->resolveSubjectUserId($user, $workspaceId, $_GET['user_id'] ?? null);

try {
    $sectionPayload = match ($requestedSection) {
        'operating' => $analytics->getOperatingSnapshot($workspaceId, $subjectUserId, $timeframe),
        'channels' => $analytics->getChannelAnalytics($workspaceId, $subjectUserId, $timeframe),
        'ai_automation' => $analytics->getAIAutomationAnalytics($workspaceId, $viewerUserId, $subjectUserId, $timeframe),
        'founder_journey' => $analytics->getFounderJourneyAnalytics($workspaceId, $subjectUserId ?? $viewerUserId),
        'targets_tasks' => $analytics->getTargetTaskAnalytics($workspaceId, $subjectUserId, $timeframe),
        'marketplace_skills' => $analytics->getMarketplaceSkillAnalytics($workspaceId, $viewerUserId),
        'operations' => $analytics->getOperationsHealthAnalytics($workspaceId, $timeframe),
    };

    $sections = [];
    foreach ($sectionDefinitions as $key => $definition) {
        if (!empty($definition['available'])) {
            $sections[] = ['key' => $key, 'label' => (string) $definition['label']];
        }
    }

    mobileJson([
        'success' => true,
        'data' => [
            'api_version' => 1,
            'section' => $requestedSection,
            'timeframe' => $timeframe,
            'scope' => $subjectUserId === null ? 'workspace' : 'user',
            'generated_at' => gmdate('c'),
            'sections' => $sections,
            'summary' => (array) ($sectionPayload['summary'] ?? []),
            'metrics' => array_values((array) ($sectionPayload['metrics'] ?? [])),
            'insights' => array_values((array) ($sectionPayload['insights'] ?? [])),
            'charts' => array_values((array) ($sectionPayload['charts'] ?? [])),
        ],
    ]);
} catch (\Throwable $e) {
    error_log('Mobile analytics endpoint failed: ' . $e->getMessage());
    mobileJson([
        'success' => false,
        'error' => 'Analytics is temporarily unavailable.',
        'error_code' => 'analytics_unavailable',
    ], 500);
}
