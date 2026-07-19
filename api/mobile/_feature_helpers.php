<?php

use CRM\Authorization;
use CRM\Database;
use CRM\Services\WorkspaceCommunicationGateService;
use CRM\Services\WorkspaceBusinessIntelligenceGateService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceHRAnalyticsGateService;
use CRM\Services\WorkspaceSkillCatalogService;
use CRM\Services\WorkspaceSkillInstallService;
use CRM\Services\WorkspaceVoiceEntitlementService;

function mobileCurrentUser(array $auth): array
{
    return Database::queryOne('SELECT * FROM users WHERE id = ? LIMIT 1', [(int) ($auth['user_id'] ?? 0)]) ?? [];
}

function mobileWorkspaceId(array $auth): int
{
    return (int) (WorkspaceContext::currentWorkspaceId() ?? ($auth['workspace_id'] ?? 0));
}

function mobileCanAny(array $user, array $permissions): bool
{
    foreach ($permissions as $permission) {
        if (Authorization::can((string) $permission, $user)) {
            return true;
        }
    }

    return false;
}

function mobileRequireAnyPermission(array $user, array $permissions, string $message = 'Forbidden'): void
{
    if (Authorization::isSuperAdmin($user) || mobileCanAny($user, $permissions)) {
        return;
    }

    mobileJson([
        'success' => false,
        'error' => $message,
        'error_code' => 'permission_denied',
        'setup_required' => false,
        'setup_url' => null,
    ], 403);
}

function mobileOperationCapability(
    bool $canView,
    bool $canManage,
    ?array $runtime = null,
    string $permissionMessage = 'This feature is not available to this user.'
): array {
    $runtimeAvailable = $runtime === null || !empty($runtime['available']);
    $available = $canView && $runtimeAvailable;
    $canManageSetup = !$runtimeAvailable && !empty($runtime['can_manage_setup']);

    $reasonCode = null;
    $message = null;
    if (!$canView) {
        $reasonCode = 'permission_denied';
        $message = $permissionMessage;
    } elseif (!$runtimeAvailable) {
        $reasonCode = (string) ($runtime['reason_code'] ?? 'setup_required');
        $message = (string) ($runtime['message'] ?? 'Workspace setup is required.');
    }

    $setupUrl = $canManageSetup ? trim((string) ($runtime['setup_url'] ?? '')) : '';

    return [
        'available' => $available,
        'can_view' => $canView,
        'can_manage' => $available && $canManage,
        'can_manage_setup' => $canManageSetup,
        'reason_code' => $reasonCode,
        'message' => $message,
        'setup_url' => $setupUrl !== '' ? $setupUrl : null,
    ];
}

function mobileOperationCapabilities(int $workspaceId, array $user): array
{
    $isAdmin = Authorization::isSuperAdmin($user);
    $organizationRuntime = mobileOrganizationRuntimeStatus($workspaceId, $user);
    $analyticsRuntime = mobileAnalyticsRuntimeStatus($workspaceId, $user);
    $calendarRuntime = mobileCalendarMeetingsRuntimeStatus($workspaceId, $user);
    $communicationRuntime = mobileCommunicationRuntimeStatus($workspaceId, $user);
    $voiceRuntime = mobileVoiceRuntimeStatus($workspaceId, $user);

    $canViewOrganization = $isAdmin || mobileCanAny($user, ['hr.analytics.view']);
    $canManageOrganization = $isAdmin || mobileCanAny($user, ['hr.analytics.manage']);
    $canViewBookings = $isAdmin || mobileCanAny($user, ['meeting_bookings.view', 'meeting_bookings.manage']);
    $canManageBookings = $isAdmin || mobileCanAny($user, ['meeting_bookings.manage']);
    $canViewShares = $isAdmin || mobileCanAny($user, [
        'meeting_bookings.view',
        'meeting_bookings.manage',
        'meeting_availability.manage',
        'settings.calendar',
    ]);
    $canManageShares = $isAdmin || mobileCanAny($user, [
        'meeting_bookings.manage',
        'meeting_availability.manage',
        'settings.calendar',
    ]);
    $canManageDecisions = $isAdmin || mobileCanAny($user, [
        'commercial_automation.approvals',
        'workflow_automation.approvals',
        'campaigns.manage',
    ]);
    $canViewDecisions = $canManageDecisions || !empty($analyticsRuntime['available']);

    return [
        'decision_center' => mobileOperationCapability(
            $canViewDecisions,
            $canManageDecisions,
            null,
            'Decision Center is not available to this user.'
        ),
        'organization' => mobileOperationCapability($canViewOrganization, $canManageOrganization, $organizationRuntime, 'Organization Intelligence is not available to this user.'),
        'analytics' => mobileOperationCapability(true, false, $analyticsRuntime, 'Analytics is not available to this user.'),
        'bookings' => mobileOperationCapability($canViewBookings, $canManageBookings, $calendarRuntime, 'Meeting bookings are not available to this user.'),
        'calendar_shares' => mobileOperationCapability($canViewShares, $canManageShares, $calendarRuntime, 'Calendar sharing is not available to this user.'),
        'targets' => mobileOperationCapability(true, true),
        'activities' => mobileOperationCapability(true, true),
        'forms' => mobileOperationCapability(true, $isAdmin || Authorization::can('tasks.write', $user), $communicationRuntime),
        'communications' => mobileOperationCapability(true, true, $communicationRuntime),
        'voice' => mobileOperationCapability(
            $isAdmin || mobileCanAny($user, ['voice.calls.use']),
            $isAdmin || mobileCanAny($user, ['voice.calls.use']),
            $voiceRuntime,
            'Voice calling is not available to this user.'
        ),
    ];
}

function mobileVoiceRuntimeStatus(int $workspaceId, array $user): array
{
    $catalog = new WorkspaceSkillCatalogService();
    $installer = new WorkspaceSkillInstallService($catalog);
    $skillKey = WorkspaceSkillCatalogService::PLUGIN_VOICE_CALL_CENTER;
    $installed = $workspaceId > 0 && $installer->isInstalled($workspaceId, $skillKey);
    $globallyActive = !$catalog->isGloballyDeactivated($skillKey);
    $entitlements = $workspaceId > 0
        ? (new WorkspaceVoiceEntitlementService())->forWorkspace($workspaceId)
        : [];
    $available = $installed && $globallyActive && !empty($entitlements['enabled']);
    $canManageSetup = Authorization::isSuperAdmin($user)
        || mobileCanAny($user, ['workspace.skills.manage', 'voice.settings.manage', 'voice.agents.manage']);

    if (!$installed) {
        $reasonCode = 'voice_setup_required';
        $message = 'Install Voice & Call Center before using mobile calling.';
    } elseif (!$globallyActive) {
        $reasonCode = 'voice_disabled';
        $message = 'Voice & Call Center is currently disabled.';
    } elseif (empty($entitlements['enabled'])) {
        $reasonCode = 'voice_entitlement_required';
        $message = 'The active workspace package does not include Voice & Call Center.';
    } else {
        $reasonCode = null;
        $message = null;
    }

    return [
        'available' => $available,
        'can_manage_setup' => !$available && $canManageSetup,
        'reason_code' => $reasonCode,
        'message' => $message,
        'setup_url' => !$available ? 'workspace_skills.php?module=' . $skillKey . '#setup' : null,
    ];
}

function mobileAnalyticsRuntimeStatus(int $workspaceId, array $user): array
{
    $gate = new WorkspaceBusinessIntelligenceGateService();
    $status = $gate->status($workspaceId, $user, 'Analytics');
    $available = !empty($status['allowed']);
    $role = (string) (WorkspaceContext::currentRoleSlug() ?? '');
    $canManageSetup = Authorization::isSuperAdmin($user)
        || in_array($role, ['owner', 'admin'], true)
        || mobileCanAny($user, ['settings.billing', 'billing.edit', 'billing.manage']);

    return [
        'available' => $available,
        'can_manage_setup' => !$available && $canManageSetup,
        'reason_code' => $available ? null : WorkspaceBusinessIntelligenceGateService::ERROR_CODE,
        'message' => $available ? null : (string) ($status['error'] ?? 'Analytics is not enabled for this workspace.'),
        'setup_url' => $available ? null : WorkspaceBusinessIntelligenceGateService::ACTION_URL,
    ];
}

function mobileOrganizationRuntimeStatus(int $workspaceId, array $user): array
{
    $gate = new WorkspaceHRAnalyticsGateService();
    $status = $gate->status($workspaceId, $user);

    return [
        'available' => !empty($status['runtime_ready']),
        'can_manage_setup' => !empty($status['can_manage']),
        'reason_code' => !empty($status['runtime_ready']) ? null : 'hr_analytics_setup_required',
        'message' => !empty($status['runtime_ready'])
            ? null
            : (string) (!empty($status['can_manage']) ? ($status['message'] ?? '') : ($status['owner_message'] ?? '')),
        'setup_url' => !empty($status['runtime_ready']) ? null : (string) ($status['setup_url'] ?? WorkspaceHRAnalyticsGateService::SETUP_URL),
    ];
}

function mobileCommunicationRuntimeStatus(int $workspaceId, array $user): array
{
    $gate = new WorkspaceCommunicationGateService();
    $status = $gate->status($workspaceId, $user);

    return [
        'available' => !empty($status['ready']),
        'can_manage_setup' => !empty($status['can_manage']),
        'reason_code' => !empty($status['ready']) ? null : 'communication_setup_required',
        'message' => !empty($status['ready'])
            ? null
            : (string) (!empty($status['can_manage']) ? ($status['message'] ?? '') : ($status['owner_message'] ?? '')),
        'setup_url' => !empty($status['ready']) ? null : (string) ($status['setup_url'] ?? WorkspaceCommunicationGateService::SETUP_URL),
    ];
}

function mobileCalendarMeetingsRuntimeStatus(int $workspaceId, array $user): array
{
    $available = Authorization::isSuperAdmin($user);
    if (!$available) {
        $catalog = new WorkspaceSkillCatalogService();
        $installer = new WorkspaceSkillInstallService($catalog);
        $available = $installer->canExposeRuntimeModule($workspaceId, WorkspaceSkillCatalogService::PLUGIN_CALENDAR_MEETINGS);
    }

    return [
        'available' => $available,
        'can_manage_setup' => Authorization::isSuperAdmin($user)
            || mobileCanAny($user, ['workspace.skills.manage', 'settings.calendar', 'meeting_availability.manage']),
        'reason_code' => $available ? null : 'calendar_meetings_setup_required',
        'message' => $available ? null : 'Calendar & Meetings setup is required before mobile can use booking and sharing tools.',
        'setup_url' => $available ? null : 'workspace_skills.php?module=' . WorkspaceSkillCatalogService::PLUGIN_CALENDAR_MEETINGS . '#setup',
    ];
}

function mobileRequireOrganizationRuntime(int $workspaceId, array $user): void
{
    $status = mobileOrganizationRuntimeStatus($workspaceId, $user);
    if (!empty($status['available'])) {
        return;
    }

    $gate = new WorkspaceHRAnalyticsGateService();
    mobileJson($gate->jsonBlockPayload($workspaceId, $user), 403);
}

function mobileRequireCommunicationRuntime(int $workspaceId, array $user): void
{
    $status = mobileCommunicationRuntimeStatus($workspaceId, $user);
    if (!empty($status['available'])) {
        return;
    }

    $gate = new WorkspaceCommunicationGateService();
    mobileJson($gate->jsonBlockPayload($workspaceId, $user), 403);
}

function mobileRequireCommunicationChannelRuntime(int $workspaceId, array $user, string $channel): void
{
    $gate = new WorkspaceCommunicationGateService();
    if ($gate->isChannelRuntimeReady($workspaceId, $channel, $user)) {
        return;
    }

    mobileJson($gate->jsonChannelBlockPayload($workspaceId, $channel, $user), 403);
}

function mobileRequireCalendarMeetingsRuntime(int $workspaceId, array $user): void
{
    $status = mobileCalendarMeetingsRuntimeStatus($workspaceId, $user);
    if (!empty($status['available'])) {
        return;
    }

    mobileJson([
        'success' => false,
        'error' => (string) $status['message'],
        'error_code' => (string) $status['reason_code'],
        'setup_required' => true,
        'setup_url' => (string) $status['setup_url'],
    ], 403);
}

function mobileBoundedLimit($value, int $default = 50, int $max = 100): int
{
    return max(1, min($max, (int) ($value ?: $default)));
}

function mobileBoundedOffset($value): int
{
    return max(0, (int) ($value ?? 0));
}
