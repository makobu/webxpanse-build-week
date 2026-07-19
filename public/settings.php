<?php
/**
 * Settings Page
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../views/partials/marketplace_plugin_setup.php';

// Load environment
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/constants.php';
use CRM\Database;
use CRM\Session;
use CRM\Auth;
use CRM\Authorization;
use CRM\Security;
use CRM\Modules\ColdOutreachWarmupConfig;
use CRM\Modules\CommercialAutomationConfig;
use CRM\Modules\CompanyProfile;
use CRM\Modules\InvoiceSettings;
use CRM\Modules\MeetingBotConfig;
use CRM\Modules\MeetingNoteTakerConfig;
use CRM\Modules\Products;
use CRM\Modules\UserStrategyProfile;
use CRM\Modules\UserPreferences;
use CRM\Modules\AILeadScoring;
use CRM\Modules\AIAutoResponderConfig;
use CRM\Modules\DemoModeManager;
use CRM\Modules\WorkspaceBillingSettings;
use CRM\Services\AutoAdminService;
use CRM\Services\AICoachWorkspaceSetupService;
use CRM\Services\AITokenRateLimiterService;
use CRM\Services\DefaultWorkspaceOperationalizationService;
use CRM\Services\DefaultWorkspaceSettingsActionService;
use CRM\Services\DealAutomationModeService;
use CRM\Services\EmailIntegrationService;
use CRM\Services\GmailMailService;
use CRM\Services\GoogleServicesSetupService;
use CRM\Services\GoogleWorkspaceMailService;
use CRM\Services\InvoiceTemplateService;
use CRM\Services\MeetingBotService;
use CRM\Services\MeetingNoteTakerService;
use CRM\Services\MarketplacePageExplainerService;
use CRM\Services\MarketplaceVideoAssetService;
use CRM\Services\OrganizationFunctionService;
use CRM\Services\OperatorAuditService;
use CRM\Services\PackageNegotiationMeetingService;
use CRM\Services\PlatformEmailDefaultService;
use CRM\Services\PlatformWorkspaceOperationsService;
use CRM\Services\SaaSBillingService;
use CRM\Services\SettingsResetService;
use CRM\Services\UIExperienceService;
use CRM\Services\WorkspaceNegotiatedPackageService;
use CRM\Services\WorkspaceLaunchGuardrailService;
use CRM\Services\WorkspaceLaunchThrottleException;
use CRM\Services\WorkflowAutomationControlService;
use CRM\Services\WorkflowAutomationProposalService;
use CRM\Services\WorkspaceBillingService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceConnectService;
use CRM\Services\WorkspaceGovernanceService;
use CRM\Services\WorkspaceChannelHealthService;
use CRM\Services\WorkspaceLanguageLevelService;
use CRM\Services\WorkspaceOnboardingService;
use CRM\Services\WorkspaceAIAutoResponderQuietHoursService;
use CRM\Services\WorkspaceScoringConfigService;
use CRM\Services\WorkspaceAutomationReadinessSettingsService;
use CRM\Services\WorkspaceSecuritySettingsService;
use CRM\Services\WhatsAppAssistantConfig;
use CRM\Services\WhatsAppAssistantDigestService;
use CRM\Services\WhatsAppSettingsHubService;

$settingsPerfTimeline = [
    ['label' => 'start', 'time' => microtime(true)],
];

if (!function_exists('settingsPerfEnabled')) {
    function settingsPerfEnabled(): bool
    {
        return (string) ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
    }
}

if (!function_exists('settingsPerfMark')) {
    function settingsPerfMark(array &$timeline, string $label): void
    {
        if (!settingsPerfEnabled()) {
            return;
        }

        $timeline[] = ['label' => $label, 'time' => microtime(true)];
    }
}

if (!function_exists('settingsPerfFlush')) {
    function settingsPerfFlush(array $timeline, string $activeTab, string $requestTab, string $method): void
    {
        if (!settingsPerfEnabled() || count($timeline) < 2) {
            return;
        }

        $segments = [];
        for ($i = 1, $len = count($timeline); $i < $len; $i++) {
            $previous = $timeline[$i - 1];
            $current = $timeline[$i];
            $segments[] = sprintf(
                '%s=%.1fms',
                (string) ($current['label'] ?? ('step_' . $i)),
                (((float) ($current['time'] ?? 0)) - ((float) ($previous['time'] ?? 0))) * 1000
            );
        }

        $totalMs = (((float) ($timeline[count($timeline) - 1]['time'] ?? 0)) - ((float) ($timeline[0]['time'] ?? 0))) * 1000;
        error_log(sprintf(
            'settings.php timing method=%s active_tab=%s request_tab=%s total=%.1fms %s',
            $method,
            $activeTab,
            $requestTab,
            $totalMs,
            implode(' ', $segments)
        ));
    }
}

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

// Require authentication
if (!Auth::check()) {
    header('Location: ' . Auth::loginUrl(null, Auth::currentAuthState() === 'expired'));
    exit;
}

// Require settings access
$user = Auth::user();
$activeWorkspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
$activeWorkspace = WorkspaceContext::currentWorkspace() ?? [];
$activeWorkspaceName = trim((string) ($activeWorkspace['name'] ?? Session::get('active_workspace_name') ?? 'Workspace'));
$activeWorkspaceSlug = trim((string) ($activeWorkspace['slug'] ?? Session::get('active_workspace_slug') ?? ''));
$activeWorkspaceRole = (string) (WorkspaceContext::currentRoleSlug() ?? 'viewer');
$isSuperAdmin = Authorization::isSuperAdmin($user);
$isDefaultWorkspace = $activeWorkspaceId > 0 && WorkspaceContext::isDefaultWorkspace($activeWorkspaceId, $activeWorkspace);
$canWorkspaceGovernanceTab = $activeWorkspaceId > 0 && ($activeWorkspaceRole === 'owner' || $isSuperAdmin);
$settingsRequestMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$hasExplicitSettingsTab = isset($_GET['tab']) && trim((string) $_GET['tab']) !== '';
$requestedSettingsTab = preg_replace('/[^a-z0-9_]+/', '_', strtolower((string) ($_GET['tab'] ?? 'general'))) ?: 'general';
$isWorkspaceOwner = $activeWorkspaceRole === 'owner';
$canManageGlobalAiAutoResponder = Authorization::can('settings.ai_autoresponder', $user);
$canManageWorkspaceAiQuietHours = $activeWorkspaceId > 0 && (
    in_array($activeWorkspaceRole, ['owner', 'admin'], true)
    || $canManageGlobalAiAutoResponder
);
$ownerSettingsAllowedTabs = ['workspace_governance', 'company', 'products', 'voice', 'billing', 'invoicing', 'ai_autoresponder'];
$ownerSettingsDefaultTab = 'workspace_governance';
$ownerElevatedSettingsPermissions = [
    'settings.general',
    'settings.email',
    'settings.email_assistant',
    'settings.whatsapp',
    'settings.ai',
    'settings.calendar',
    'settings.sms',
    'settings.ai_autoresponder',
    'settings.enrichment',
    'settings.scoring',
    'settings.company',
    'settings.monitoring',
    'settings.deal_automation',
    'settings.meeting_bot',
    'settings.meeting_note_taker',
    'settings.workflow_automation',
    'settings.commercial_automation',
    'platform.settings.manage',
    'platform.system.reset',
];
$hasElevatedSettingsAccess = $isSuperAdmin || Authorization::canAny($ownerElevatedSettingsPermissions, $user);
$useOwnerSettingsAllowlist = !$isSuperAdmin && $isWorkspaceOwner && !$hasElevatedSettingsAccess;
$ownerSettingsRedirects = [
    'email_assistant' => 'workspace_skills.php?module=email_assistant&setup_tab=identity&legacy_settings_redirect=1#setup',
    'ai' => 'workspace_skills.php?module=ai_coach&setup_tab=workspace_readiness#setup',
    'email' => 'workspace_skills.php?module=email&setup_tab=outreach_email#setup',
    'whatsapp' => 'workspace_skills.php?module=whatsapp&setup_tab=manual#setup',
    'sms' => 'workspace_skills.php?module=sms_channel#setup',
    'calendar' => 'workspace_skills.php?module=calendar_meetings#setup',
    'meeting_bot' => 'workspace_skills.php?module=calendar_meetings#setup',
    'meeting_note_taker' => 'workspace_skills.php?module=calendar_meetings#setup',
];
if ($requestedSettingsTab === 'email_assistant') {
    header('Location: ' . $ownerSettingsRedirects['email_assistant'], true, $settingsRequestMethod === 'POST' ? 303 : 302);
    exit;
}
if ($settingsRequestMethod === 'GET' && in_array($requestedSettingsTab, ['meeting_bot', 'meeting_note_taker'], true)) {
    header('Location: ' . $ownerSettingsRedirects[$requestedSettingsTab]);
    exit;
}
if ($settingsRequestMethod === 'GET' && $useOwnerSettingsAllowlist) {
    if (isset($ownerSettingsRedirects[$requestedSettingsTab])) {
        header('Location: ' . $ownerSettingsRedirects[$requestedSettingsTab]);
        exit;
    }

    if ($hasExplicitSettingsTab && !in_array($requestedSettingsTab, $ownerSettingsAllowedTabs, true)) {
        header('Location: settings.php?tab=' . $ownerSettingsDefaultTab);
        exit;
    }
}
if (!$isSuperAdmin && !Authorization::canAny([
    'settings.general',
    'settings.email',
    'settings.email_assistant',
    'settings.whatsapp',
    'settings.ai',
    'settings.calendar',
    'settings.sms',
    'settings.ai_autoresponder',
    'settings.enrichment',
    'settings.scoring',
    'settings.company',
    'settings.monitoring',
    'settings.deal_automation',
    'settings.meeting_bot',
    'settings.meeting_note_taker',
    'settings.workflow_automation',
    'settings.invoicing',
    'settings.billing',
    'billing.view',
    'billing.edit',
    'billing.manage',
    'meeting_bot.view_runs',
    'meeting_notes.view_runs',
    'settings.commercial_automation'
], $user) && !$canWorkspaceGovernanceTab) {
    header('Location: dashboard.php');
    exit;
}

$error = null;
$success = null;
$canBillingView = Authorization::canAny(['billing.view', 'billing.edit', 'billing.manage', 'settings.billing'], $user);
$canBillingEdit = Authorization::canAny(['billing.edit', 'billing.manage', 'settings.billing'], $user);
$canBillingAdmin = Authorization::canAny(['billing.manage', 'settings.billing'], $user);
$canPlatformBillingAdmin = Authorization::isSuperAdmin($user);
$activeTab = $useOwnerSettingsAllowlist && !$hasExplicitSettingsTab
    ? $ownerSettingsDefaultTab
    : ($_GET['tab'] ?? 'general');
if (isset($_GET['success']) && $_GET['success'] !== '') {
    $successCode = (string) $_GET['success'];
    if ($successCode === 'google_workspace_connected') {
        $success = 'Google Workspace connected successfully.';
    } elseif ($successCode === 'gmail_connected') {
        $success = 'Gmail connected successfully.';
    } elseif ($successCode === 'google_connected') {
        $success = 'Google Calendar connected successfully.';
    } elseif ($successCode === 'outlook_connected') {
        $success = 'Outlook Calendar connected successfully.';
    } elseif ($successCode === 'default_workspace_operationalized') {
        $success = 'Default workspace operationalized successfully. Review the Platform Admin tab for the current score and timestamp.';
    } elseif ($successCode === 'default_workspace_owner_contacts_reconciled') {
        $success = 'Default workspace owner contacts reconciled successfully.';
    } elseif ($successCode === 'default_workspace_recovered') {
        $success = 'Default workspace recovery completed. Review the Platform Admin tab for current health.';
    } else {
        $success = $successCode;
    }
}
if (isset($_GET['error']) && $_GET['error'] !== '') {
    $error = (string) $_GET['error'];
}
$tabPermissions = [
    'general' => 'settings.general',
    'page_videos' => 'settings.general',
    'email' => 'settings.email',
    'email_assistant' => 'settings.email_assistant',
    'whatsapp' => 'settings.whatsapp',
    'ai' => 'settings.ai',
    'calendar' => 'settings.calendar',
    'sms' => 'settings.sms',
    'ai_autoresponder' => 'settings.ai_autoresponder',
    'enrichment' => 'settings.enrichment',
    'scoring' => 'settings.scoring',
    'company' => 'settings.company',
    'products' => 'settings.company',
    'voice' => 'settings.company',
    'monitoring' => 'settings.monitoring',
    'deal_automation' => 'settings.deal_automation',
    'meeting_bot' => 'settings.meeting_bot',
    'meeting_note_taker' => 'settings.meeting_note_taker',
    'workflow_automation' => 'settings.workflow_automation',
    'invoicing' => 'settings.invoicing',
    'billing' => 'billing.view',
    'package_settings' => '__package_settings__',
    'workspace_governance' => '__workspace_governance__',
    'platform_admin' => '__platform_admin__',
    'google_services' => '__google_services__',
    'commercial_automation' => 'settings.commercial_automation',
];
$canTab = [];
foreach ($tabPermissions as $tabKey => $permissionKey) {
    if ($tabKey === 'billing') {
        $canTab[$tabKey] = $canBillingView || $canBillingEdit || $canBillingAdmin;
    } elseif ($tabKey === 'package_settings') {
        $canTab[$tabKey] = $isSuperAdmin;
    } elseif ($tabKey === 'workspace_governance') {
        $canTab[$tabKey] = $canWorkspaceGovernanceTab;
    } elseif ($tabKey === 'platform_admin') {
        $canTab[$tabKey] = $canPlatformBillingAdmin;
    } elseif ($tabKey === 'google_services') {
        $canTab[$tabKey] = $isSuperAdmin;
    } elseif ($tabKey === 'meeting_bot') {
        $canTab[$tabKey] = Authorization::can('settings.meeting_bot', $user) || Authorization::can('meeting_bot.view_runs', $user);
    } elseif ($tabKey === 'meeting_note_taker') {
        $canTab[$tabKey] = Authorization::can('settings.meeting_note_taker', $user) || Authorization::can('meeting_notes.view_runs', $user);
    } elseif ($tabKey === 'ai_autoresponder') {
        $canTab[$tabKey] = $canManageGlobalAiAutoResponder || $canManageWorkspaceAiQuietHours;
    } else {
        $canTab[$tabKey] = Authorization::can($permissionKey, $user);
    }
}
$ownerHiddenUniversalTabs = ['enrichment', 'scoring', 'monitoring'];
if ($useOwnerSettingsAllowlist) {
    $ownerSettingsAllowedTabLookup = array_fill_keys($ownerSettingsAllowedTabs, true);
    foreach (array_keys($canTab) as $tabKey) {
        $canTab[$tabKey] = isset($ownerSettingsAllowedTabLookup[$tabKey]);
    }
} elseif (!$isSuperAdmin && $isWorkspaceOwner) {
    foreach ($ownerHiddenUniversalTabs as $ownerHiddenUniversalTab) {
        $canTab[$ownerHiddenUniversalTab] = false;
    }
}
$canWorkspaceDataReset = $activeWorkspaceId > 0 && (
    $canWorkspaceGovernanceTab
    || Authorization::can('admin.users.manage', $user)
);
$canPlatformSystemReset = $isSuperAdmin;
$canAdminDataReset = $canWorkspaceDataReset || $canPlatformSystemReset;
$settingsResetService = new SettingsResetService();
$workspaceConnectService = new WorkspaceConnectService();
$whatsAppSettingsHub = new WhatsAppSettingsHubService();
$workspaceConnectState = $workspaceConnectService->buildHubState($user, $activeWorkspaceId);
$pageExplainers = new MarketplacePageExplainerService();
$pageVideoAssets = new MarketplaceVideoAssetService();
$pageVideoDefinitions = MarketplacePageExplainerService::settingsPageDefinitions();
$pageVideoExplainers = [];
foreach ($pageVideoDefinitions as $pageVideoKey => $pageVideoDefinition) {
    $pageVideoExplainers[$pageVideoKey] = $pageExplainers->get((string) $pageVideoKey) ?? [];
}
$pageVideoLibrary = $pageVideoAssets->listAssets();
$demoModeManager = null;
$demoModeState = [
    'is_enabled' => false,
    'simulation_only' => true,
    'active_run_id' => null,
    'active_run' => null,
    'seeded_records' => 0,
    'last_operation' => null,
    'updated_at' => null,
];
if ($canAdminDataReset) {
    try {
        $demoModeManager = new DemoModeManager();
        $demoModeState = $demoModeManager->getState();
    } catch (\Throwable $e) {
        $demoModeState['error'] = $e->getMessage();
    }
}
if ($useOwnerSettingsAllowlist && isset($tabPermissions[$activeTab]) && empty($canTab[$activeTab])) {
    $activeTab = $ownerSettingsDefaultTab;
} elseif (isset($tabPermissions[$activeTab]) && empty($canTab[$activeTab])) {
    foreach ($canTab as $candidateTab => $allowed) {
        if ($allowed) {
            $activeTab = $candidateTab;
            break;
        }
    }
}
settingsPerfMark($settingsPerfTimeline, 'bootstrap_ready');

if (!function_exists('settingsTableExists')) {
    function settingsTableExists(string $tableName): bool
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $tableName)) {
            return false;
        }

        $row = Database::queryOne(
            "SELECT COUNT(*) AS cnt
             FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?",
            [$tableName]
        );
        return ((int) ($row['cnt'] ?? 0)) > 0;
    }
}

if (!function_exists('settingsTableHasColumn')) {
    function settingsTableHasColumn(string $tableName, string $columnName): bool
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $tableName) || !preg_match('/^[A-Za-z0-9_]+$/', $columnName)) {
            return false;
        }

        $row = Database::queryOne(
            "SELECT COUNT(*) AS cnt
             FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?",
            [$tableName, $columnName]
        );
        return ((int) ($row['cnt'] ?? 0)) > 0;
    }
}

if (!function_exists('settingsResolveDemoRunId')) {
    function settingsResolveDemoRunId(int $preferredRunId = 0): int
    {
        if ($preferredRunId > 0) {
            return $preferredRunId;
        }
        if (!settingsTableExists('demo_runs')) {
            return 0;
        }

        $row = Database::queryOne(
            "SELECT id
             FROM demo_runs
             WHERE status IN ('active', 'inactive')
             ORDER BY id DESC
             LIMIT 1"
        );

        return (int) ($row['id'] ?? 0);
    }
}

if (!function_exists('settingsNormalizeAssistantPhone')) {
    function settingsNormalizeAssistantPhone(string $phone): string
    {
        $normalized = preg_replace('/[^\d+]/', '', trim($phone));
        $normalized = ltrim((string) $normalized, '+');
        $normalized = ltrim((string) $normalized, '0');
        return (string) $normalized;
    }
}

if (!function_exists('settingsUploadErrorMessage')) {
    function settingsUploadErrorMessage(string $label, int $errorCode): string
    {
        return match ($errorCode) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => $label . ' is larger than the server allows.',
            UPLOAD_ERR_PARTIAL => $label . ' was only partially uploaded. Please try again.',
            UPLOAD_ERR_NO_TMP_DIR => 'The server is missing a temporary upload directory.',
            UPLOAD_ERR_CANT_WRITE => 'The server could not write the uploaded file.',
            UPLOAD_ERR_EXTENSION => 'A server extension blocked the upload.',
            default => $label . ' upload failed. Please try again.',
        };
    }
}

if (!function_exists('settingsVideoExtension')) {
    function settingsVideoExtension(array $file, string $tmpName, string $label): string
    {
        $mime = function_exists('mime_content_type')
            ? strtolower((string) mime_content_type($tmpName))
            : strtolower((string) ($file['type'] ?? ''));
        $extensions = [
            'video/mp4' => 'mp4',
            'application/mp4' => 'mp4',
            'video/webm' => 'webm',
            'video/quicktime' => 'mov',
            'video/x-m4v' => 'm4v',
            'video/m4v' => 'm4v',
            'video/mp4v-es' => 'm4v',
        ];
        if (isset($extensions[$mime])) {
            return $extensions[$mime];
        }

        $nameExtension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        $allowedByExtension = [
            'mp4' => 'mp4',
            'webm' => 'webm',
            'mov' => 'mov',
            'm4v' => 'm4v',
        ];
        if (in_array($mime, ['', 'application/octet-stream', 'binary/octet-stream'], true) && isset($allowedByExtension[$nameExtension])) {
            return $allowedByExtension[$nameExtension];
        }

        throw new \RuntimeException($label . ' must be an MP4, WebM, MOV, or M4V file.');
    }
}

if (!function_exists('settingsUploadPageExplainerVideo')) {
    function settingsUploadPageExplainerVideo(string $field, string $pageKey): string
    {
        $file = $_FILES[$field] ?? null;
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return '';
        }

        $uploadError = (int) ($file['error'] ?? UPLOAD_ERR_OK);
        if ($uploadError !== UPLOAD_ERR_OK) {
            throw new \RuntimeException(settingsUploadErrorMessage('Page guide video', $uploadError));
        }
        if ((int) ($file['size'] ?? 0) > 50 * 1024 * 1024) {
            throw new \RuntimeException('Page guide videos must be 50 MB or smaller.');
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new \RuntimeException('Page guide video upload was not valid.');
        }

        $extension = settingsVideoExtension($file, $tmpName, 'Page guide video');
        $safeKey = preg_replace('/[^a-z0-9_]+/', '_', strtolower($pageKey)) ?: 'page';
        $uploadDir = __DIR__ . '/../uploads/marketplace/page_explainers/' . $safeKey . '/';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            throw new \RuntimeException('Could not create page guide upload directory.');
        }

        $fileName = $field . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
        $destination = $uploadDir . $fileName;
        if (!move_uploaded_file($tmpName, $destination)) {
            throw new \RuntimeException('Could not save page guide video.');
        }

        return 'uploads/marketplace/page_explainers/' . $safeKey . '/' . $fileName;
    }
}

if (!function_exists('settingsPageExplainerAssetUrl')) {
    function settingsPageExplainerAssetUrl(string $path): string
    {
        $path = trim($path);
        if ($path === '' || preg_match('#^https?://#i', $path) === 1 || str_starts_with($path, '/')) {
            return $path;
        }
        if (str_starts_with($path, 'uploads/')) {
            return function_exists('publicUrl') ? publicUrl('../' . $path) : '../' . $path;
        }

        return function_exists('assetUrl') ? assetUrl($path) : 'assets/' . ltrim($path, '/');
    }
}

if (!function_exists('settingsProductMediaUrl')) {
    function settingsProductMediaUrl(string $path): string
    {
        $path = trim($path);
        if ($path === '' || preg_match('#^https?://#i', $path) === 1 || str_starts_with($path, '/')) {
            return $path;
        }
        if (str_starts_with($path, 'uploads/')) {
            return function_exists('publicUrl') ? publicUrl('../' . $path) : '../' . $path;
        }

        return function_exists('assetUrl') ? assetUrl($path) : 'assets/' . ltrim($path, '/');
    }
}

$userPrefs = new UserPreferences();
$autoAdminService = new AutoAdminService($userPrefs);
$userId = (int) ($user['id'] ?? 0);
$globalAiConfigUserId = 1;
$autoAdminPlatformEnabled = $autoAdminService->isEnabled();
$autoAdminWorkspaceState = $activeWorkspaceId > 0 ? $autoAdminService->getWorkspaceState($activeWorkspaceId) : [];
$autoAdminEnabled = $autoAdminService->isEnabledForWorkspace($activeWorkspaceId);
$autoAdminWorkspaceRoleCanManage = in_array($activeWorkspaceRole, ['owner', 'admin', 'superadmin'], true);
$autoAdminWorkspacePermissionCanManage = Authorization::canAny([
    'settings.ai',
    'settings.ai_autoresponder',
    'settings.commercial_automation',
    'settings.deal_automation',
    'settings.workflow_automation',
], $user);
$canManageWorkspaceAutoAdmin = $activeWorkspaceId > 0 && (
    $isSuperAdmin
    || ($autoAdminWorkspaceRoleCanManage && $autoAdminWorkspacePermissionCanManage)
);
$autoAdminWorkspaceControlsAvailable = $autoAdminPlatformEnabled;
$autoAdminNotice = null;
$aiCoachEnabled = (new AICoachWorkspaceSetupService())->isWorkspaceEnabled($activeWorkspaceId);
$aiLeanCanvasModeEnabled = $userPrefs->isLeanCanvasModeEnabled($userId);
$aiGuidanceMode = $userPrefs->getAIGuidanceMode($userId);
$hoursPerWeekSales = $userPrefs->getHoursPerWeekSales($userId);
$inboxTriageOptOut = $userPrefs->isInboxTriageOptOut($userId);
$uiExperienceService = new UIExperienceService();
$uiExperienceMode = $uiExperienceService->modeForUser($user, $activeWorkspaceId);
$canManageUiExperienceMode = $isSuperAdmin
    || (string) ($user['role'] ?? '') === 'admin'
    || (string) ($activeWorkspaceRole ?? '') === 'admin';
$aiContextStrictness = $userPrefs->getAIContextStrictness($userId);
$aiAdviceMinConfidence = $userPrefs->getAIAdviceMinConfidence($userId);
$aiActionMinConfidence = $userPrefs->getAIActionMinConfidence($userId);
$aiGoalRelevanceMinScore = $userPrefs->getAIGoalRelevanceMinScore($userId);
$aiAutoTaskCompletionEnabled = $userPrefs->isAIAutoTaskCompletionEnabled($userId);
$aiAutoTaskCompletionMinConfidence = $userPrefs->getAIAutoTaskCompletionMinConfidence($userId);
$aiMissingContextBehavior = $userPrefs->getAIMissingContextBehavior($userId);
$aiModeLock = $userPrefs->getAIModeLock($userId);
$aiAutonomousThresholdTuningEnabled = $userPrefs->isAIAutonomousThresholdTuningEnabled($globalAiConfigUserId);
$aiCalibrationLastRunAt = $userPrefs->getAICalibrationLastRunAt($globalAiConfigUserId);
$aiCalibrationMinSampleSize = $userPrefs->getAICalibrationMinSampleSize($globalAiConfigUserId);
$aiCalibrationDailyChangeCap = $userPrefs->getAICalibrationDailyChangeCap($globalAiConfigUserId);
$aiCalibrationRollingChangeCap = $userPrefs->getAICalibrationRollingChangeCap($globalAiConfigUserId);
$aiIncidentAlertsEnabled = $userPrefs->isAIIncidentAlertsEnabled($globalAiConfigUserId);
$aiIncidentCheckEnabled = $userPrefs->isAIIncidentCheckEnabled($globalAiConfigUserId);
$aiIncidentMediumCooldownMinutes = $userPrefs->getAIIncidentMediumCooldownMinutes($globalAiConfigUserId);
$aiIncidentHighCooldownMinutes = $userPrefs->getAIIncidentHighCooldownMinutes($globalAiConfigUserId);
$aiIncidentCriticalCooldownMinutes = $userPrefs->getAIIncidentCriticalCooldownMinutes($globalAiConfigUserId);
$aiAutoResponderConfig = null;
$aiAutoResponderQuietTimezoneOptions = ['UTC' => 'UTC'];
$invoiceSettings = null;
$saasBillingPortal = [
    'snapshot' => [
        'subscription_status' => 'inactive',
        'billing_blocked' => false,
        'token_balance' => 0,
        'available_tokens' => 0,
        'is_trial_active' => false,
        'trial_starts_at' => null,
        'trial_ends_at' => null,
        'ai_blocked_reason' => null,
    ],
    'plans' => [],
    'token_packs' => [],
    'ledger' => [],
];
$workspaceBillingSettings = null;
$packageSettingsCatalog = [
    'subscription_prices' => [],
    'negotiated_offers' => [],
    'negotiated_workspace_summaries' => [],
    'negotiated_offer_metrics' => [],
    'token_pack_prices' => [],
    'operator_audit' => [],
];
$packageSettingsPaymentModes = [];
$workspaceGovernanceService = null;
$workspaceGovernanceData = [
    'workspace' => null,
    'members' => [],
    'pending_invites' => [],
    'invites' => [],
    'slugs' => [],
    'history' => [],
    'capabilities' => [
        'can_manage_workspace' => false,
        'can_manage_owners' => false,
        'can_transfer_ownership' => false,
    ],
    'actor_membership' => null,
];
$saasBillingReadiness = ['ready' => true, 'issues' => []];
$saasBillingReadinessError = null;
$workspaceGovernanceReadiness = ['ready' => true, 'issues' => []];
$workspaceGovernanceReadinessError = null;
$workspaceGovernanceActionResult = null;
$workspaceSecuritySettings = [
    'require_member_2fa' => false,
];
$workspaceSecurity2FASummary = [
    'total' => 0,
    'protected' => 0,
    'missing' => 0,
    'missing_members' => [],
];
$automationReadinessRefreshOptions = WorkspaceAutomationReadinessSettingsService::cadenceOptions();
$automationReadinessRefreshPolicy = [
    'cadence' => WorkspaceAutomationReadinessSettingsService::DEFAULT_CADENCE,
    'key' => WorkspaceAutomationReadinessSettingsService::DEFAULT_CADENCE,
    'label' => 'Every 6 hours',
    'seconds' => 21600,
    'automatic_enabled' => true,
    'manual_throttle_seconds' => WorkspaceAutomationReadinessSettingsService::MANUAL_REFRESH_THROTTLE_SECONDS,
];
$commercialAutomationConfig = null;
$coldOutreachWarmupConfig = new ColdOutreachWarmupConfig();
$emailColdOutreachWarmup = $coldOutreachWarmupConfig->get('email', $activeWorkspaceId > 0 ? $activeWorkspaceId : null);
$whatsappColdOutreachWarmup = $coldOutreachWarmupConfig->get('whatsapp', $activeWorkspaceId > 0 ? $activeWorkspaceId : null);
$emailColdOutreachUsage = $coldOutreachWarmupConfig->getUsageSummary('email');
$whatsappColdOutreachUsage = $coldOutreachWarmupConfig->getUsageSummary('whatsapp');

$requestTab = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? (string) ($_POST['tab'] ?? $activeTab)
    : $activeTab;
$normalizeEmailSettingsSection = static function (?string $section): string {
    $section = strtolower(trim((string) $section));
    return in_array($section, ['system', 'workspace', 'warmup'], true) ? $section : 'system';
};
$emailSettingsSection = $normalizeEmailSettingsSection(
    $_SERVER['REQUEST_METHOD'] === 'POST'
        ? (string) ($_POST['email_section'] ?? $_GET['email_section'] ?? 'system')
        : (string) ($_GET['email_section'] ?? 'system')
);
$shouldLoadAiAutoResponderConfig = $activeTab === 'ai_autoresponder' || $requestTab === 'ai_autoresponder';
$shouldLoadInvoiceSettings = $activeTab === 'invoicing' || $requestTab === 'invoicing';
$shouldLoadMeetingBotConfig = $activeTab === 'meeting_bot' || $requestTab === 'meeting_bot';
$shouldLoadMeetingNoteTakerConfig = $activeTab === 'meeting_note_taker' || $requestTab === 'meeting_note_taker';
$shouldLoadWorkspaceBilling = $activeTab === 'billing' || $requestTab === 'billing';
$shouldLoadPackageSettings = $activeTab === 'package_settings' || $requestTab === 'package_settings';
$shouldLoadWorkspaceGovernance = $activeTab === 'workspace_governance' || $requestTab === 'workspace_governance';
$shouldLoadCommercialAutomationConfig = $activeTab === 'commercial_automation' || $requestTab === 'commercial_automation';
$shouldLoadWorkflowAutomationConfig = $activeTab === 'workflow_automation' || $requestTab === 'workflow_automation';
$normalizePackageSettingsSection = static function (?string $section): string {
    $section = strtolower(trim((string) $section));
    $allowed = [
        'packages',
        'negotiated_offers',
        'ai_credit_packs',
        'feature_catalog',
        'payment_methods',
        'catalog_health',
        'analytics',
    ];

    return in_array($section, $allowed, true) ? $section : 'packages';
};
$packageSettingsSection = $normalizePackageSettingsSection(
    $_SERVER['REQUEST_METHOD'] === 'POST'
        ? (string) ($_POST['package_section'] ?? $_GET['package_section'] ?? 'packages')
        : (string) ($_GET['package_section'] ?? 'packages')
);
$packageAnalyticsFilters = [
    'from' => (string) ($_GET['analytics_from'] ?? ''),
    'to' => (string) ($_GET['analytics_to'] ?? ''),
];
$packageNegotiatedFilters = [
    'status' => (string) ($_GET['negotiated_status'] ?? ($_POST['negotiated_status'] ?? '')),
    'payment_mode' => (string) ($_GET['negotiated_payment_mode'] ?? ($_POST['negotiated_payment_mode'] ?? '')),
    'q' => (string) ($_GET['negotiated_q'] ?? ($_POST['negotiated_q'] ?? '')),
];
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $activeTab === 'package_settings' && $isSuperAdmin && isset($_GET['export'])) {
    $export = (string) $_GET['export'];
    if (!in_array($export, ['catalog_health', 'package_analytics'], true)) {
        header('Location: settings.php?tab=package_settings&error=' . rawurlencode('Unsupported package settings export.'));
        exit;
    }
    $catalog = (new PlatformWorkspaceOperationsService())->listBillingCatalogForOperators($packageAnalyticsFilters, $packageNegotiatedFilters);
    $filename = $export === 'catalog_health' ? 'package_catalog_health.csv' : 'package_analytics.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    if ($export === 'catalog_health') {
        fputcsv($out, ['section', 'id', 'label', 'details']);
        foreach ((array) (($catalog['catalog_health'] ?? [])['duplicate_packages'] ?? []) as $row) {
            fputcsv($out, ['duplicate_packages', (string) ($row['duplicate_key'] ?? ''), (string) ($row['packages'] ?? ''), (string) ($row['row_count'] ?? '')]);
        }
        foreach ((array) (($catalog['catalog_health'] ?? [])['missing_provider_plan_codes'] ?? []) as $row) {
            fputcsv($out, ['missing_provider_plan_codes', (string) ($row['id'] ?? ''), (string) ($row['plan_name'] ?? ''), (string) ($row['price_code'] ?? '')]);
        }
        foreach ((array) (($catalog['catalog_health'] ?? [])['unsafe_deletes'] ?? []) as $row) {
            fputcsv($out, ['unsafe_deletes', (string) ($row['id'] ?? ''), (string) ($row['label'] ?? ''), json_encode($row['reference_counts'] ?? [], JSON_UNESCAPED_SLASHES)]);
        }
    } else {
        fputcsv($out, ['section', 'package', 'metric_1', 'metric_2', 'metric_3', 'currency_or_date', 'window']);
        $windowLabel = (string) (($catalog['package_analytics']['window']['label'] ?? '') ?: 'All time');
        foreach ((array) (($catalog['package_analytics'] ?? [])['popularity'] ?? []) as $row) {
            fputcsv($out, ['popularity', (string) ($row['plan_name'] ?? $row['plan_code'] ?? ''), (string) ($row['active_subscriptions'] ?? 0), (string) ($row['paid_checkouts'] ?? 0), (string) ($row['score'] ?? 0), '', $windowLabel]);
        }
        foreach ((array) (($catalog['package_analytics'] ?? [])['revenue'] ?? []) as $row) {
            fputcsv($out, ['revenue', (string) ($row['package_name'] ?? ''), (string) ($row['transaction_count'] ?? 0), (string) ($row['revenue'] ?? 0), (string) ($row['transaction_type'] ?? ''), (string) ($row['currency'] ?? '') . ' ' . (string) ($row['revenue_date'] ?? ''), $windowLabel]);
        }
        foreach ((array) (($catalog['package_analytics'] ?? [])['checkouts'] ?? []) as $row) {
            fputcsv($out, ['checkouts', (string) ($row['package_name'] ?? ''), (string) ($row['checkout_starts'] ?? 0), (string) ($row['paid_checkouts'] ?? 0), (string) ($row['conversion_rate'] ?? 0), (string) ($row['currency'] ?? ''), $windowLabel]);
        }
    }
    fclose($out);
    exit;
}
$assistantWhatsAppMappings = [];
$assistantWhatsAppUsers = [];
$assistantWhatsAppDiagnostics = [];
$assistantWhatsAppDigestDiagnostics = [];
$assistantWhatsAppRecentMessages = [];
$assistantWhatsAppRecentDigests = [];
$assistantWhatsAppSessionDiagnostics = [];
$assistantWhatsAppRecentKeepaliveEvents = [];
$meetingBotState = null;
$meetingBotConfig = null;
$meetingBotRuns = [];
$meetingNoteTakerConfig = null;
$meetingNoteTakerRuns = [];

$workflowAutomationService = null;
$workflowAutomationState = null;
$workflowAutomationPendingApprovals = [];
$workflowAutomationMetrics = [];
if ($shouldLoadWorkflowAutomationConfig) {
    $workflowAutomationService = new WorkflowAutomationControlService();
    $workflowAutomationState = $workflowAutomationService->buildSettingsState();
    $workflowAutomationPendingApprovals = (new WorkflowAutomationProposalService())->listAll(['status' => 'pending', 'limit' => 20]);
    $workflowAutomationMetrics = (new WorkflowAutomationProposalService())->buildSummaryMetrics($workflowAutomationPendingApprovals);
}

if ($shouldLoadAiAutoResponderConfig) {
    try {
        $workspaceQuietHours = new WorkspaceAIAutoResponderQuietHoursService();
        $aiAutoResponderConfig = (new AIAutoResponderConfig())->get($activeWorkspaceId);
        $aiAutoResponderQuietTimezoneOptions = $workspaceQuietHours->timezoneOptions($activeWorkspaceId);
    } catch (\Throwable $e) {
        $aiAutoResponderConfig = null;
    }
}

if ($shouldLoadInvoiceSettings) {
    try {
        $invoiceSettings = (new InvoiceSettings())->get();
    } catch (\Throwable $e) {
        $invoiceSettings = null;
    }
}

if ($shouldLoadMeetingBotConfig) {
    try {
        $meetingBotService = new MeetingBotService();
        $meetingBotState = $meetingBotService->buildSettingsState();
        $meetingBotConfig = (array) ($meetingBotState['config'] ?? []);
        $meetingBotRuns = Authorization::can('meeting_bot.view_runs', $user)
            ? $meetingBotService->getRecentRuns(12)
            : [];
    } catch (\Throwable $e) {
        $meetingBotState = null;
        $meetingBotConfig = null;
        $meetingBotRuns = [];
    }
}

if ($shouldLoadMeetingNoteTakerConfig) {
    try {
        $meetingNoteTakerConfig = (new MeetingNoteTakerConfig())->get();
        $meetingNoteTakerRuns = Authorization::can('meeting_notes.view_runs', $user)
            ? (new MeetingNoteTakerService())->getRecentRuns(12)
            : [];
    } catch (\Throwable $e) {
        $meetingNoteTakerConfig = null;
        $meetingNoteTakerRuns = [];
    }
}

if ($shouldLoadWorkspaceBilling) {
    try {
        $workspaceBillingSettings = (new WorkspaceBillingSettings())->get();
        if ($activeWorkspaceId > 0) {
            $saasBillingPortal = (new SaaSBillingService())->getWorkspaceBillingPortalData($activeWorkspaceId, 20, $user);
        }
    } catch (\CRM\Services\WorkspaceLaunchReadinessException $e) {
        $workspaceBillingSettings = null;
        $saasBillingReadiness = $e->readiness();
        $saasBillingReadinessError = (string) ($saasBillingReadiness['customer_message'] ?? $e->getMessage());
    } catch (\Throwable $e) {
        $workspaceBillingSettings = null;
    }
}

if ($shouldLoadPackageSettings && $isSuperAdmin) {
    try {
        $packageSettingsCatalog = (new PlatformWorkspaceOperationsService())->listBillingCatalogForOperators($packageAnalyticsFilters, $packageNegotiatedFilters);
        $packageSettingsPaymentModes = (new WorkspaceBillingSettings())->get();
        $packageNegotiationMeetingState = (new PackageNegotiationMeetingService())->setupState();
    } catch (\Throwable $e) {
        $packageSettingsCatalog = [
            'subscription_prices' => [],
            'negotiated_offers' => [],
            'negotiated_workspace_summaries' => [],
            'negotiated_offer_metrics' => [],
            'token_pack_prices' => [],
            'operator_audit' => [],
        ];
        $packageSettingsPaymentModes = [];
        $packageNegotiationMeetingState = [];
    }
}

if ($shouldLoadWorkspaceGovernance && $canWorkspaceGovernanceTab && $activeWorkspaceId > 0) {
    try {
        $workspaceGovernanceService = new WorkspaceGovernanceService();
        $workspaceGovernanceData = $workspaceGovernanceService->getWorkspaceGovernanceData($activeWorkspaceId, $userId);
        $workspaceSecurityService = new WorkspaceSecuritySettingsService();
        $workspaceSecuritySettings = $workspaceSecurityService->getSettings($activeWorkspaceId);
        $workspaceSecurity2FASummary = $workspaceSecurityService->memberTwoFactorSummary($activeWorkspaceId);
        $automationReadinessRefreshPolicy = (new WorkspaceAutomationReadinessSettingsService())->getPolicy($activeWorkspaceId);
    } catch (\Throwable $e) {
        $workspaceGovernanceData = [
            'workspace' => null,
            'members' => [],
            'pending_invites' => [],
            'invites' => [],
            'slugs' => [],
            'history' => [],
            'capabilities' => [
                'can_manage_workspace' => false,
                'can_manage_owners' => false,
                'can_transfer_ownership' => false,
            ],
            'actor_membership' => null,
        ];
        if ($error === null) {
            $error = $e->getMessage();
        }
    }
}

if ($shouldLoadCommercialAutomationConfig) {
    try {
        $commercialAutomationConfig = (new CommercialAutomationConfig())->get($activeWorkspaceId > 0 ? $activeWorkspaceId : null);
    } catch (\Throwable $e) {
        $commercialAutomationConfig = null;
    }
}
if ($activeTab === 'email_assistant' || $requestTab === 'email_assistant') {
    $assistantWhatsAppDiagnostics = (new WhatsAppAssistantConfig())->validate();
    $assistantWhatsAppDigestDiagnostics = (new WhatsAppAssistantDigestService())->validateDigestConfig();
    if (settingsTableExists('whatsapp_assistant_authorized_numbers')) {
        $workspaceSql = '';
        $workspaceParams = [];
        if ($activeWorkspaceId > 0 && Database::columnExists('whatsapp_assistant_authorized_numbers', 'workspace_id')) {
            $workspaceSql = 'WHERE workspace_id = ?';
            $workspaceParams[] = $activeWorkspaceId;
        }
        $assistantWhatsAppMappings = Database::query(
            "SELECT *
             FROM whatsapp_assistant_authorized_numbers
             {$workspaceSql}
             ORDER BY is_active DESC, label ASC, phone_number ASC",
            $workspaceParams
        );
    }
    if (settingsTableExists('whatsapp_assistant_messages')) {
        $workspaceSql = '';
        $workspaceParams = [];
        if ($activeWorkspaceId > 0 && Database::columnExists('whatsapp_assistant_messages', 'workspace_id')) {
            $workspaceSql = 'WHERE wam.workspace_id = ?';
            $workspaceParams[] = $activeWorkspaceId;
        }
        $assistantWhatsAppRecentMessages = Database::query(
            "SELECT wam.*, u.first_name, u.last_name, u.email
             FROM whatsapp_assistant_messages wam
             LEFT JOIN users u ON u.id = wam.user_id
             {$workspaceSql}
             ORDER BY wam.created_at DESC, wam.id DESC
             LIMIT 10",
            $workspaceParams
        );
    }
    if (settingsTableExists('whatsapp_assistant_digest_log')) {
        $workspaceSql = '';
        $workspaceParams = [];
        if ($activeWorkspaceId > 0 && Database::columnExists('whatsapp_assistant_digest_log', 'workspace_id')) {
            $workspaceSql = 'WHERE wdl.workspace_id = ?';
            $workspaceParams[] = $activeWorkspaceId;
        }
        $assistantWhatsAppRecentDigests = Database::query(
            "SELECT wdl.*, u.first_name, u.last_name, u.email
             FROM whatsapp_assistant_digest_log wdl
             LEFT JOIN users u ON u.id = wdl.user_id
             {$workspaceSql}
             ORDER BY wdl.sent_at DESC, wdl.id DESC
             LIMIT 10",
            $workspaceParams
        );
    }
    if (settingsTableExists('whatsapp_assistant_sessions')) {
        $assistantWhatsAppSessionDiagnostics = (new \CRM\Services\WhatsAppAssistantSessionService())->getSessionDiagnostics();
    }
    if (settingsTableExists('whatsapp_assistant_keepalive_log')) {
        $workspaceSql = '';
        $workspaceParams = [];
        if ($activeWorkspaceId > 0 && Database::columnExists('whatsapp_assistant_keepalive_log', 'workspace_id')) {
            $workspaceSql = 'WHERE wkl.workspace_id = ?';
            $workspaceParams[] = $activeWorkspaceId;
        }
        $assistantWhatsAppRecentKeepaliveEvents = Database::query(
            "SELECT wkl.*, u.first_name, u.last_name, u.email
             FROM whatsapp_assistant_keepalive_log wkl
             LEFT JOIN users u ON u.id = wkl.user_id
             {$workspaceSql}
             ORDER BY wkl.created_at DESC, wkl.id DESC
             LIMIT 12",
            $workspaceParams
        );
    }
    if ($activeWorkspaceId > 0 && Database::tableExists('workspace_memberships')) {
        $assistantWhatsAppUsers = Database::query(
            "SELECT u.id, u.first_name, u.last_name, u.email
             FROM workspace_memberships wm
             JOIN users u ON u.id = wm.user_id
             WHERE wm.workspace_id = ?
               AND wm.membership_status = 'active'
             ORDER BY u.first_name ASC, u.last_name ASC, u.email ASC",
            [$activeWorkspaceId]
        );
    } else {
        $assistantWhatsAppUsers = Database::query(
            "SELECT id, first_name, last_name, email
             FROM users
             ORDER BY first_name ASC, last_name ASC, email ASC"
        );
    }
}
settingsPerfMark($settingsPerfTimeline, 'active_tab_data_loaded');

$emailIntegrationService = new EmailIntegrationService();
$platformEmailDefaults = new PlatformEmailDefaultService();
$platformMainEmailDefaults = $platformEmailDefaults->get(EmailIntegrationService::SCOPE_MAIN_EMAIL);
$platformMainEmailSettings = (array) ($platformMainEmailDefaults['settings'] ?? []);
$gmailMailService = new GmailMailService();
$googleWorkspaceMailService = new GoogleWorkspaceMailService();
$mainEmailProviderSummary = $emailIntegrationService->getMainProviderSummary();
$activeGmailIntegration = $emailIntegrationService->getActiveGmailIntegration();
$activeGoogleWorkspaceIntegration = $emailIntegrationService->getActiveGoogleWorkspaceIntegration();
$gmailMailConnectedEmail = trim((string) (($activeGmailIntegration['email_address'] ?? '')));
$googleWorkspaceMailConnectedEmail = trim((string) ($mainEmailProviderSummary['connected_email'] ?? ''));
$googleWorkspaceMailConnectedEmail = $googleWorkspaceMailConnectedEmail !== ''
    ? $googleWorkspaceMailConnectedEmail
    : trim((string) (($activeGoogleWorkspaceIntegration['email_address'] ?? '')));
$gmailMailConfigured = $gmailMailService->isConfigured();
$gmailMailRedirectUri = $gmailMailService->getRedirectUri();
$googleWorkspaceMailConfigured = $googleWorkspaceMailService->isConfigured();
$googleWorkspaceMailRedirectUri = $googleWorkspaceMailService->getRedirectUri();
$emailSettingsApiBase = getApiBasePath();
$googleServicesSetupService = new GoogleServicesSetupService($envFile);
$googleServicesSummary = [];
$defaultWorkspaceOpsStatus = null;
if ($isSuperAdmin) {
    try {
        $defaultWorkspaceOpsStatus = (new DefaultWorkspaceOperationalizationService())->status($userId);
    } catch (\Throwable $e) {
        $defaultWorkspaceOpsStatus = null;
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        try {
            $tab = $_POST['tab'] ?? 'general';
            $skipEnvWrite = false;
            $assistantWhatsAppRowsToSave = null;
            $autoAdminToggleOnly = in_array($tab, ['general', 'ai'], true) && isset($_POST['auto_admin_toggle_submitted']);
            $hasTabPermission = isset($canTab[$tab]) && !empty($canTab[$tab]);
            if ($tab === 'meeting_bot') {
                $hasTabPermission = Authorization::can('settings.meeting_bot', $user);
            } elseif ($tab === 'meeting_note_taker') {
                $hasTabPermission = Authorization::can('settings.meeting_note_taker', $user);
            } elseif ($tab === 'workspace_governance') {
                $hasTabPermission = $canWorkspaceGovernanceTab;
            }
            if (!isset($tabPermissions[$tab]) || !$hasTabPermission) {
                $error = 'You do not have permission for this settings section.';
                $activeTab = $useOwnerSettingsAllowlist
                    ? $ownerSettingsDefaultTab
                    : (isset($tabPermissions[$tab]) ? $tab : $activeTab);
                throw new \RuntimeException($error);
            }
            $settingsAction = (string) ($_POST['settings_action'] ?? '');
            $skillAction = (string) ($_POST['skill_action'] ?? '');
            $whatsAppSettingsSection = strtolower(trim(preg_replace('/[^a-z0-9_]+/', '_', (string) ($_POST['whatsapp_settings_section'] ?? 'platform')) ?? ''));
            if (($tab === 'platform_admin') && $settingsAction === 'operationalize_default_workspace') {
                $result = (new DefaultWorkspaceSettingsActionService())->operationalizeFromSettings(
                    $user,
                    (string) ($_POST['default_workspace_reason'] ?? '')
                );
                $defaultWorkspaceOpsStatus = $result;
                $success = sprintf(
                    'Default workspace operationalized at %d%%. Registered %d internal capabilities, updated %d templates, and %d tasks.',
                    (int) ($result['operational_score'] ?? 0),
                    (int) ($result['capabilities_registered'] ?? 0),
                    ((int) ($result['email_templates_upserted'] ?? 0) + (int) ($result['workflow_templates_upserted'] ?? 0)),
                    (int) ($result['tasks_upserted'] ?? 0)
                );
                header('Location: settings.php?tab=platform_admin&success=default_workspace_operationalized');
                exit;
            }
            if (($tab === 'platform_admin') && $settingsAction === 'reconcile_default_workspace_owner_contacts') {
                (new DefaultWorkspaceSettingsActionService())->reconcileOwnerContactsFromSettings(
                    $user,
                    (string) ($_POST['default_workspace_reason'] ?? '')
                );
                header('Location: settings.php?tab=platform_admin&success=default_workspace_owner_contacts_reconciled');
                exit;
            }
            if (($tab === 'platform_admin') && $settingsAction === 'recover_default_workspace') {
                (new DefaultWorkspaceSettingsActionService())->recoverFromSettings(
                    $user,
                    (string) ($_POST['default_workspace_reason'] ?? '')
                );
                header('Location: settings.php?tab=platform_admin&success=default_workspace_recovered');
                exit;
            }
            if (($tab === 'platform_admin') && $settingsAction === 'auto_admin_platform_toggle') {
                if (!$isSuperAdmin) {
                    throw new \RuntimeException('Only Super Admin can manage the platform Auto Admin switch.');
                }

                $autoAdminService->setPlatformEnabled(isset($_POST['auto_admin_platform_enabled']), $userId);
                $autoAdminPlatformEnabled = $autoAdminService->isEnabled();
                $autoAdminWorkspaceState = $activeWorkspaceId > 0 ? $autoAdminService->getWorkspaceState($activeWorkspaceId) : [];
                $autoAdminEnabled = $autoAdminService->isEnabledForWorkspace($activeWorkspaceId);
                $autoAdminWorkspaceControlsAvailable = $autoAdminPlatformEnabled;
                $success = $autoAdminPlatformEnabled
                    ? 'Platform Auto Admin is available. Workspace controls can now be managed per workspace.'
                    : 'Platform Auto Admin is off. Workspace controls are unavailable until a Super Admin enables the platform switch.';
                $skipEnvWrite = true;
            }
            if ($autoAdminToggleOnly) {
                if (!$canManageWorkspaceAutoAdmin) {
                    throw new \RuntimeException('You do not have permission to manage Auto Admin for this workspace.');
                }
                if (!$autoAdminPlatformEnabled) {
                    throw new \RuntimeException('Platform Auto Admin is off. Ask a Super Admin to enable it before managing this workspace.');
                }

                $requestedAutoAdminEnabled = isset($_POST['auto_admin_enabled']);
                $manualFreeze = isset($_POST['auto_admin_manual_freeze']);
                $freezeReason = trim((string) ($_POST['auto_admin_freeze_reason'] ?? ''));
                $targetModes = [
                    'deal_automation' => (string) ($_POST['auto_admin_target_deal_automation'] ?? ''),
                    'workflow_automation' => (string) ($_POST['auto_admin_target_workflow_automation'] ?? ''),
                    'ai_autoresponder' => (string) ($_POST['auto_admin_target_ai_autoresponder'] ?? ''),
                    'commercial_automation' => (string) ($_POST['auto_admin_target_commercial_automation'] ?? ''),
                ];

                if ($activeWorkspaceId > 0) {
                    $autoAdminService->setWorkspaceTargetModes($activeWorkspaceId, $targetModes, $userId);
                    $autoAdminService->setWorkspaceFreeze($activeWorkspaceId, $manualFreeze, $freezeReason, $userId);
                    $autoAdminService->setWorkspaceEnabled($activeWorkspaceId, $requestedAutoAdminEnabled, $userId);
                    if ($requestedAutoAdminEnabled && !$manualFreeze) {
                        $autoAdminService->applyManagedDefaultsForWorkspace($activeWorkspaceId, $userId);
                    }
                }

                $autoAdminPlatformEnabled = $autoAdminService->isEnabled();
                $autoAdminWorkspaceState = $activeWorkspaceId > 0 ? $autoAdminService->getWorkspaceState($activeWorkspaceId) : [];
                $autoAdminEnabled = $autoAdminService->isEnabledForWorkspace($activeWorkspaceId);
                $autoAdminWorkspaceControlsAvailable = $autoAdminPlatformEnabled;
                if ($autoAdminEnabled) {
                    $managedDefaults = $autoAdminService->getManagedDefaults();
                    $aiCoachEnabled = (new AICoachWorkspaceSetupService())->isWorkspaceEnabled($activeWorkspaceId);
                    $aiAutoTaskCompletionEnabled = (bool) $managedDefaults['ai_auto_task_completion_enabled'];
                    $aiAutoTaskCompletionMinConfidence = (float) $managedDefaults['ai_auto_task_completion_min_confidence'];
                    $aiAutonomousThresholdTuningEnabled = (bool) $managedDefaults['ai_autonomous_threshold_tuning_enabled'];
                    $aiIncidentAlertsEnabled = (bool) $managedDefaults['ai_incident_alerts_enabled'];
                    $aiIncidentCheckEnabled = (bool) $managedDefaults['ai_incident_check_enabled'];
                    $aiCalibrationMinSampleSize = (int) $managedDefaults['ai_calibration_min_sample_size'];
                    $aiCalibrationDailyChangeCap = (float) $managedDefaults['ai_calibration_daily_change_cap'];
                    $aiCalibrationRollingChangeCap = (float) $managedDefaults['ai_calibration_rolling_change_cap'];
                }
                $success = $autoAdminEnabled
                    ? 'Auto Admin is now managing AI and automation setup for this workspace.'
                    : 'Manual admin control restored for this workspace.';
                $skipEnvWrite = true;
            }
            if (!$autoAdminToggleOnly && $autoAdminService->isEnabledForWorkspace($activeWorkspaceId) && $autoAdminService->isManagedTab($tab)) {
                $error = 'Auto Admin is managing this workspace settings section right now. Turn it off first if you need manual control.';
                $activeTab = $tab;
                throw new \RuntimeException($error);
            }
            $envContent = file_get_contents($envFile);
            
            // Update settings based on tab
            if ($tab === 'whatsapp' && $skillAction !== '') {
                $hubResult = $whatsAppSettingsHub->handleWhatsAppHubPost($activeWorkspaceId, $userId, $user, $_POST, 'settings');
                $_GET['setup_module'] = (string) ($hubResult['setup_module'] ?? '');
                $_GET['setup_tab'] = (string) ($hubResult['setup_tab'] ?? '');
                $_GET['whatsapp_tab'] = ($_GET['setup_module'] ?? '') === \CRM\Services\WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT
                    ? 'assistant'
                    : 'connection';
                $success = (string) ($hubResult['success'] ?? 'WhatsApp setup saved.');
                $settings = [];
                $skipEnvWrite = true;
            } elseif ($tab === 'page_videos') {
                if (!$isSuperAdmin) {
                    throw new \RuntimeException('Only Super Admin can manage page guide videos.');
                }

                $pageVideoAction = trim((string) ($_POST['page_video_action'] ?? 'save_assignments'));
                if ($pageVideoAction === 'bulk_upload') {
                    $uploadResult = $pageVideoAssets->uploadManyFromFiles((array) ($_FILES['page_video_library_files'] ?? []), $userId);
                    $uploadedCount = (int) ($uploadResult['uploaded'] ?? 0);
                    $dedupedCount = (int) ($uploadResult['deduplicated'] ?? 0);
                    if ($uploadedCount + $dedupedCount <= 0) {
                        throw new \RuntimeException('Choose at least one page guide video to upload.');
                    }
                    $successParts = [];
                    if ($uploadedCount > 0) {
                        $successParts[] = $uploadedCount . ' uploaded';
                    }
                    if ($dedupedCount > 0) {
                        $successParts[] = $dedupedCount . ' already in library';
                    }
                    $success = 'Video library updated: ' . implode(', ', $successParts) . '.';
                } elseif ($pageVideoAction === 'delete_asset') {
                    $pageVideoAssets->deleteAsset((int) ($_POST['video_asset_id'] ?? 0));
                    $success = 'Page guide video deleted from the library.';
                } else {
                    foreach ($pageVideoDefinitions as $pageVideoKey => $pageVideoDefinition) {
                        $pageVideoKey = (string) $pageVideoKey;
                        $pageVideoLabel = (string) ($pageVideoDefinition['label'] ?? 'Page guide');
                        $selectedAssetId = (int) ($_POST[$pageVideoKey . '_page_video_asset_id'] ?? 0);
                        $isActive = !empty($_POST[$pageVideoKey . '_page_explainer_active']);
                        if ($isActive && $selectedAssetId <= 0) {
                            throw new \RuntimeException('Choose a library video before showing ' . $pageVideoLabel . '.');
                        }

                        if ($selectedAssetId > 0) {
                            $pageVideoAssets->assignToPage($pageVideoKey, $pageVideoLabel, $selectedAssetId, $isActive, $userId);
                        } else {
                            $pageVideoAssets->detachPage($pageVideoKey, $pageVideoLabel, $userId);
                        }
                    }
                    $success = 'Video assignments saved.';
                }

                $pageVideoLibrary = $pageVideoAssets->listAssets();
                foreach ($pageVideoDefinitions as $pageVideoKey => $pageVideoDefinition) {
                    $pageVideoExplainers[(string) $pageVideoKey] = $pageExplainers->get((string) $pageVideoKey) ?? [];
                }
                $settings = [];
                $skipEnvWrite = true;
            } elseif ($tab === 'google_services') {
                if (!$isSuperAdmin) {
                    throw new \RuntimeException('Only Super Admins can manage Google Services setup.');
                }

                $googleServicesAction = trim((string) ($_POST['google_services_action'] ?? 'save_credentials'));
                if ($googleServicesAction === 'save_credentials') {
                    $googleServicesSetupService->saveCredentials($_POST);
                    $settings = [];
                    $skipEnvWrite = true;
                    $success = 'Google Services settings saved.';
                } else {
                    $settings = [];
                    $skipEnvWrite = true;
                }
            } elseif ($tab === 'email') {
                $lockedWarmupConfig = $coldOutreachWarmupConfig->get('email', $activeWorkspaceId > 0 ? $activeWorkspaceId : null);
                $providerAction = trim((string) ($_POST['email_provider_action'] ?? ''));
                $smtpPort = (int) ($_POST['smtp_port'] ?? 587);
                // Auto-detect encryption based on port if not specified
                $smtpEncryption = $_POST['smtp_encryption'] ?? ($smtpPort === 465 ? 'ssl' : 'tls');
                
                $imapPort = (int) ($_POST['imap_port'] ?? 993);
                $imapProtocol = $_POST['imap_protocol'] ?? 'imap';
                $imapEncryption = $_POST['imap_encryption'] ?? ($imapPort === 993 ? 'ssl' : ($imapPort === 995 ? 'ssl' : 'tls'));
                
                if ($isSuperAdmin && $providerAction === 'copy_outreach_to_system') {
                    $outreachSmtp = $emailIntegrationService->getStrictManualSmtpConfigForRole(
                        'outreach',
                        $activeWorkspaceId > 0 ? $activeWorkspaceId : null
                    );
                    $outreachHost = trim((string) ($outreachSmtp['host'] ?? ''));
                    $outreachUsername = trim((string) ($outreachSmtp['username'] ?? ''));
                    $outreachPassword = (string) ($outreachSmtp['password'] ?? '');
                    if ($outreachHost === '' || $outreachUsername === '' || $outreachPassword === '') {
                        throw new \RuntimeException('Outreach Email SMTP is not ready yet. Save the Outreach Email plugin SMTP host, username, and password before copying it into System Mail.');
                    }

                    $outreachFromEmail = trim((string) ($outreachSmtp['from_email'] ?? ''));
                    $platformEmailDefaults->save(EmailIntegrationService::SCOPE_MAIN_EMAIL, [
                        'from_email' => $outreachFromEmail !== '' ? $outreachFromEmail : $outreachUsername,
                        'from_name' => trim((string) ($outreachSmtp['from_name'] ?? '')) ?: brandProductName() . ' System Mail',
                        'smtp_host' => $outreachHost,
                        'smtp_port' => (string) ((int) (($outreachSmtp['port'] ?? 0) ?: 587)),
                        'smtp_username' => $outreachUsername,
                        'smtp_password' => $outreachPassword,
                        'smtp_encryption' => (string) ($outreachSmtp['encryption'] ?? 'tls'),
                        'imap_enabled' => false,
                    ], $userId, $activeWorkspaceId > 0 ? $activeWorkspaceId : null);
                    $platformMainEmailDefaults = $platformEmailDefaults->get(EmailIntegrationService::SCOPE_MAIN_EMAIL);
                    $platformMainEmailSettings = (array) ($platformMainEmailDefaults['settings'] ?? []);
                    $settings = [];
                    $skipEnvWrite = true;
                    $success = 'Outreach SMTP settings copied to System Mail. Send a System Mail test before retrying workspace invites.';
                    $mainEmailProviderSummary = $emailIntegrationService->getMainProviderSummary();
                } elseif ($isSuperAdmin) {
                    $platformEmailDefaults->save(EmailIntegrationService::SCOPE_MAIN_EMAIL, [
                        'from_email' => $_POST['smtp_from_email'] ?? '',
                        'from_name' => $_POST['smtp_from_name'] ?? brandProductName(),
                        'smtp_host' => $_POST['smtp_host'] ?? '',
                        'smtp_port' => (string) $smtpPort,
                        'smtp_username' => $_POST['smtp_user'] ?? '',
                        'smtp_password' => $_POST['smtp_pass'] ?? '',
                        'smtp_encryption' => $smtpEncryption,
                        'imap_enabled' => isset($_POST['imap_enabled']),
                        'imap_host' => $_POST['imap_host'] ?? '',
                        'imap_port' => (string) $imapPort,
                        'imap_username' => $_POST['imap_user'] ?? $_POST['smtp_user'] ?? '',
                        'imap_password' => $_POST['imap_pass'] ?? '',
                        'imap_protocol' => $imapProtocol,
                        'imap_encryption' => $imapEncryption,
                        'imap_folder' => $_POST['imap_folder'] ?? 'INBOX',
                    ], $userId);
                    $platformMainEmailDefaults = $platformEmailDefaults->get(EmailIntegrationService::SCOPE_MAIN_EMAIL);
                    $platformMainEmailSettings = (array) ($platformMainEmailDefaults['settings'] ?? []);
                }

                if ($providerAction !== 'copy_outreach_to_system') {
                    $settings = [];
                    $skipEnvWrite = true;
                    $warmupManaged = $autoAdminEnabled && !empty($lockedWarmupConfig['auto_admin_warmup_enabled']);
                    $allowWarmupFieldEdit = !$warmupManaged;
                    $coldOutreachWarmupConfig->save('email', [
                        'enabled' => $warmupManaged ? !empty($lockedWarmupConfig['enabled']) : isset($_POST['email_cold_outreach_enabled']),
                        'auto_admin_warmup_enabled' => $warmupManaged ? !empty($lockedWarmupConfig['auto_admin_warmup_enabled']) : isset($_POST['email_auto_admin_warmup_enabled']),
                        'initial_daily_cold_limit' => $allowWarmupFieldEdit
                            ? (int) ($_POST['email_initial_daily_cold_limit'] ?? $lockedWarmupConfig['initial_daily_cold_limit'])
                            : (int) $lockedWarmupConfig['initial_daily_cold_limit'],
                        'current_daily_cold_limit' => $allowWarmupFieldEdit
                            ? (int) ($_POST['email_current_daily_cold_limit'] ?? $lockedWarmupConfig['current_daily_cold_limit'])
                            : (int) $lockedWarmupConfig['current_daily_cold_limit'],
                        'weekly_increment' => $allowWarmupFieldEdit
                            ? (int) ($_POST['email_weekly_increment'] ?? $lockedWarmupConfig['weekly_increment'])
                            : (int) $lockedWarmupConfig['weekly_increment'],
                        'max_limit' => $allowWarmupFieldEdit
                            ? (int) ($_POST['email_max_limit'] ?? $lockedWarmupConfig['max_limit'])
                            : (int) $lockedWarmupConfig['max_limit'],
                    ], $userId);
                    $success = $success ?? 'Email settings saved.';
                }
                if ($providerAction === 'disconnect_gmail') {
                    $emailIntegrationService->deactivateMainGmail();
                    $settings = [];
                    $skipEnvWrite = true;
                    $success = 'Gmail disconnected. Manual SMTP/IMAP fallback is still available.';
                    $mainEmailProviderSummary = $emailIntegrationService->getMainProviderSummary();
                    $activeGmailIntegration = $emailIntegrationService->getActiveGmailIntegration();
                    $gmailMailConnectedEmail = trim((string) (($activeGmailIntegration['email_address'] ?? '')));
                }
                if ($providerAction === 'disconnect_google_workspace') {
                    $emailIntegrationService->deactivateMainGoogleWorkspace();
                    $settings = [];
                    $skipEnvWrite = true;
                    $success = 'Google Workspace disconnected. Manual SMTP/IMAP fallback is still available.';
                    $mainEmailProviderSummary = $emailIntegrationService->getMainProviderSummary();
                    $activeGoogleWorkspaceIntegration = $emailIntegrationService->getActiveGoogleWorkspaceIntegration();
                    $googleWorkspaceMailConnectedEmail = trim((string) ($mainEmailProviderSummary['connected_email'] ?? ''));
                    $googleWorkspaceMailConnectedEmail = $googleWorkspaceMailConnectedEmail !== ''
                        ? $googleWorkspaceMailConnectedEmail
                        : trim((string) (($activeGoogleWorkspaceIntegration['email_address'] ?? '')));
                }
            } elseif ($tab === 'whatsapp') {
                $lockedWarmupConfig = $coldOutreachWarmupConfig->get('whatsapp', $activeWorkspaceId > 0 ? $activeWorkspaceId : null);
                if ($whatsAppSettingsSection === 'guardrails') {
                    $_GET['whatsapp_tab'] = 'guardrails';
                    $settings = [];
                    $skipEnvWrite = true;
                    $warmupManaged = $autoAdminEnabled && !empty($lockedWarmupConfig['auto_admin_warmup_enabled']);
                    $allowWarmupFieldEdit = !$warmupManaged;
                    $coldOutreachWarmupConfig->save('whatsapp', [
                        'enabled' => $warmupManaged ? !empty($lockedWarmupConfig['enabled']) : isset($_POST['whatsapp_cold_outreach_enabled']),
                        'auto_admin_warmup_enabled' => $warmupManaged ? !empty($lockedWarmupConfig['auto_admin_warmup_enabled']) : isset($_POST['whatsapp_auto_admin_warmup_enabled']),
                        'initial_daily_cold_limit' => $allowWarmupFieldEdit
                            ? (int) ($_POST['whatsapp_initial_daily_cold_limit'] ?? $lockedWarmupConfig['initial_daily_cold_limit'])
                            : (int) $lockedWarmupConfig['initial_daily_cold_limit'],
                        'current_daily_cold_limit' => $allowWarmupFieldEdit
                            ? (int) ($_POST['whatsapp_current_daily_cold_limit'] ?? $lockedWarmupConfig['current_daily_cold_limit'])
                            : (int) $lockedWarmupConfig['current_daily_cold_limit'],
                        'weekly_increment' => $allowWarmupFieldEdit
                            ? (int) ($_POST['whatsapp_weekly_increment'] ?? $lockedWarmupConfig['weekly_increment'])
                            : (int) $lockedWarmupConfig['weekly_increment'],
                        'max_limit' => $allowWarmupFieldEdit
                            ? (int) ($_POST['whatsapp_max_limit'] ?? $lockedWarmupConfig['max_limit'])
                            : (int) $lockedWarmupConfig['max_limit'],
                    ], $userId, $activeWorkspaceId > 0 ? $activeWorkspaceId : null);
                    $success = 'WhatsApp guardrails saved.';
                } else {
                    $_GET['whatsapp_tab'] = 'platform';
                    if (!$isSuperAdmin) {
                        throw new \RuntimeException('Only Super Admin can update WhatsApp platform credentials.');
                    }
                    $settings = [
                        'WHATSAPP_PHONE_NUMBER_ID' => $_POST['whatsapp_phone_number_id'] ?? '',
                        'WHATSAPP_ACCESS_TOKEN' => $_POST['whatsapp_access_token'] ?? '',
                        'WHATSAPP_BUSINESS_ACCOUNT_ID' => $_POST['whatsapp_business_account_id'] ?? '',
                        'META_APP_ID' => $_POST['meta_app_id'] ?? '',
                        'META_APP_SECRET' => $_POST['meta_app_secret'] ?? '',
                        'META_APP_ACCESS_TOKEN' => $_POST['meta_app_access_token'] ?? '',
                        'META_WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID' => $_POST['meta_whatsapp_embedded_signup_config_id'] ?? '',
                    ];

                    // Try to auto-detect WABA ID if not provided
                    if (empty($settings['WHATSAPP_BUSINESS_ACCOUNT_ID']) && !empty($settings['WHATSAPP_PHONE_NUMBER_ID']) && !empty($settings['WHATSAPP_ACCESS_TOKEN'])) {
                        try {
                            require_once __DIR__ . '/../services/WhatsAppService.php';
                            $whatsappService = new \CRM\Services\WhatsAppService();
                            $wabaId = $whatsappService->getWabaIdFromPhoneNumber();
                            if ($wabaId) {
                                $settings['WHATSAPP_BUSINESS_ACCOUNT_ID'] = $wabaId;
                            }
                        } catch (\Exception $e) {
                            // Log but don't fail - user can set manually
                            error_log("Auto-detect WABA ID failed: " . $e->getMessage());
                        }
                    }

                }
            } elseif ($tab === 'calendar') {
                $settings = $isSuperAdmin ? [
                    'MICROSOFT_CALENDAR_CLIENT_ID' => trim((string) ($_POST['microsoft_calendar_client_id'] ?? '')),
                    'MICROSOFT_CALENDAR_CLIENT_SECRET' => trim((string) ($_POST['microsoft_calendar_client_secret'] ?? '')),
                ] : [];
            } elseif ($tab === 'ai') {
                $settings = [
                    'AI_API_KEY' => $_POST['ai_api_key'] ?? '',
                    'AI_SERVICE_URL' => $_POST['ai_service_url'] ?? '',
                    'AI_MODEL' => trim((string) ($_POST['ai_model'] ?? '')),
                    'AI_TOKEN_RATE_LIMIT_ENABLED' => isset($_POST['ai_token_rate_limit_enabled']) ? 'true' : 'false',
                    'AI_DAILY_TOKEN_LIMIT' => trim((string) ($_POST['ai_daily_token_limit'] ?? '0')) !== '' ? (string) max(0, (int) $_POST['ai_daily_token_limit']) : '0',
                ];
            } elseif ($tab === 'general') {
                if ($autoAdminToggleOnly) {
                    $settings = [];
                } elseif (($_POST['demo_action'] ?? '') !== '') {
                    if (!$canAdminDataReset) {
                        throw new \RuntimeException('Only admins can perform demo mode operations.');
                    }
                    if (!$demoModeManager) {
                        throw new \RuntimeException('Demo mode manager unavailable. Ensure migration 104_create_demo_mode_tables.sql is applied.');
                    }

                    $demoAction = trim((string) ($_POST['demo_action'] ?? ''));
                    $seedProfile = trim((string) ($_POST['demo_seed_profile'] ?? 'full'));
                    $runId = (int) ($_POST['demo_run_id'] ?? 0);
                    $adminUserId = (int) ($user['id'] ?? 0);
                    $demoResult = null;

                    if ($demoAction === 'enable') {
                        $demoResult = $demoModeManager->enable($adminUserId, $seedProfile !== '' ? $seedProfile : 'full');
                    } elseif ($demoAction === 'disable') {
                        $demoResult = $demoModeManager->disable($adminUserId);
                    } elseif ($demoAction === 'simulation_on') {
                        $demoResult = $demoModeManager->setSimulationOnly(true, $adminUserId);
                    } elseif ($demoAction === 'simulation_off') {
                        $demoResult = $demoModeManager->setSimulationOnly(false, $adminUserId);
                    } elseif ($demoAction === 'reseed') {
                        $runId = settingsResolveDemoRunId($runId);
                        if ($runId <= 0) {
                            throw new \RuntimeException('No demo run found for reseed.');
                        }
                        $demoResult = $demoModeManager->seed($runId, $adminUserId, $seedProfile !== '' ? $seedProfile : 'full');
                    } elseif ($demoAction === 'purge') {
                        $runId = settingsResolveDemoRunId($runId);
                        if ($runId <= 0) {
                            throw new \RuntimeException('No demo run found for purge.');
                        }
                        $demoResult = $demoModeManager->purge($runId, $adminUserId);
                    } else {
                        throw new \RuntimeException('Unknown demo action.');
                    }

                    if (!($demoResult['success'] ?? false)) {
                        throw new \RuntimeException((string) ($demoResult['error'] ?? 'Demo action failed.'));
                    }

                    $settings = [];
                    $skipEnvWrite = true;
                    $success = 'Demo action completed successfully.';
                    if (!empty($demoResult['seed_summary']['created'])) {
                        $success .= ' Seeded: ' . json_encode($demoResult['seed_summary']['created']);
                    }
                    $demoModeState = $demoModeManager->getState();
                } elseif (($_POST['danger_action'] ?? '') === 'reset_core_data') {
                    if (!$canWorkspaceDataReset || $activeWorkspaceId <= 0) {
                        throw new \RuntimeException('Only workspace owners/admins can reset workspace data.');
                    }
                    $resetDefinitions = $settingsResetService->definitions();
                    $definition = $resetDefinitions['reset_core_data'] ?? null;
                    if (!$definition) {
                        throw new \RuntimeException('Reset definition unavailable.');
                    }
                    $confirmText = trim((string) ($_POST['danger_confirm_text'] ?? ''));
                    if ($confirmText !== (string) $definition['confirm_text']) {
                        throw new \RuntimeException('Confirmation text mismatch. Type exactly: ' . (string) $definition['confirm_text']);
                    }
                    $resetResult = $settingsResetService->execute($definition, $activeWorkspaceId, $userId);
                    $success = (string) $definition['success_prefix'] . ' Deleted rows -> ' . $resetResult['summary'];
                    $settings = [];
                    $skipEnvWrite = true;
                } elseif (($_POST['danger_action'] ?? '') === 'reset_company_context') {
                    if (!$canWorkspaceDataReset || $activeWorkspaceId <= 0) {
                        throw new \RuntimeException('Only workspace owners/admins can reset workspace context.');
                    }
                    $resetDefinitions = $settingsResetService->definitions();
                    $definition = $resetDefinitions['reset_company_context'] ?? null;
                    if (!$definition) {
                        throw new \RuntimeException('Reset definition unavailable.');
                    }
                    $confirmText = trim((string) ($_POST['danger_confirm_text'] ?? ''));
                    if ($confirmText !== (string) $definition['confirm_text']) {
                        throw new \RuntimeException('Confirmation text mismatch. Type exactly: ' . (string) $definition['confirm_text']);
                    }
                    $resetResult = $settingsResetService->execute($definition, $activeWorkspaceId, $userId);
                    $success = (string) $definition['success_prefix'] . ' Deleted rows -> ' . $resetResult['summary'];
                    $settings = [];
                    $skipEnvWrite = true;
                } elseif (($_POST['danger_action'] ?? '') === 'reset_platform_data') {
                    if (!$canPlatformSystemReset) {
                        throw new \RuntimeException('Only Super Admin can perform the platform reset.');
                    }
                    $resetDefinitions = $settingsResetService->definitions();
                    $definition = $resetDefinitions['reset_platform_data'] ?? null;
                    if (!$definition) {
                        throw new \RuntimeException('Reset definition unavailable.');
                    }
                    $confirmText = trim((string) ($_POST['danger_confirm_text'] ?? ''));
                    if ($confirmText !== (string) $definition['confirm_text']) {
                        throw new \RuntimeException('Confirmation text mismatch. Type exactly: ' . (string) $definition['confirm_text']);
                    }
                    $resetResult = $settingsResetService->execute($definition, null, $userId);
                    $success = (string) $definition['success_prefix'] . ' Deleted rows -> ' . $resetResult['summary'];
                    $settings = [];
                    $skipEnvWrite = true;
                } else {
                    $settings = $isSuperAdmin ? [
                        'APP_NAME' => $_POST['app_name'] ?? brandProductName(),
                        'APP_ENV' => $_POST['app_env'] ?? 'development',
                        'APP_DEBUG' => isset($_POST['app_debug']) ? 'true' : 'false',
                    ] : [];
                    $hoursValue = (string) ($_POST['hours_per_week_sales'] ?? '');
                    if ($hoursValue !== '') {
                    $userPrefs->setHoursPerWeekSales($userId, $hoursValue);
                }
                $userPrefs->setInboxTriageOptOut($userId, isset($_POST['inbox_triage_opt_out']));
                if ($canManageUiExperienceMode) {
                    $postedExperienceMode = (string) ($_POST['ui_experience_mode'] ?? $uiExperienceMode);
                    if (in_array($postedExperienceMode, [UIExperienceService::MODE_BEGINNER, UIExperienceService::MODE_ADVANCED], true)) {
                        $userPrefs->setPreference($userId, UIExperienceService::PREFERENCE_KEY, $postedExperienceMode);
                        $uiExperienceMode = $postedExperienceMode;
                    }
                }
                if (!$autoAdminService->isEnabledForWorkspace($activeWorkspaceId)) {
                    $userPrefs->setLeanCanvasModeEnabled($userId, isset($_POST['ai_lean_canvas_mode_enabled']));
                    $aiLeanCanvasModeEnabled = isset($_POST['ai_lean_canvas_mode_enabled']);
                    $mode = $_POST['ai_guidance_mode'] ?? '2';
                    if (in_array($mode, ['1', '2', '3', 'auto'], true)) {
                        $userPrefs->setAIGuidanceMode($userId, $mode);
                        $aiGuidanceMode = $mode;
                    }
                    $userPrefs->setAIContextStrictness($userId, (string) ($_POST['ai_context_strictness'] ?? 'strict'));
                    $userPrefs->setAIAdviceMinConfidence($userId, (float) ($_POST['ai_advice_min_confidence'] ?? 0.88));
                    $userPrefs->setAIActionMinConfidence($userId, (float) ($_POST['ai_action_min_confidence'] ?? 0.92));
                    $userPrefs->setAIGoalRelevanceMinScore($userId, (float) ($_POST['ai_goal_relevance_min_score'] ?? 0.70));
                    $userPrefs->setAIAutoTaskCompletionEnabled($userId, isset($_POST['ai_auto_task_completion_enabled']));
                    $userPrefs->setAIAutoTaskCompletionMinConfidence($userId, (float) ($_POST['ai_auto_task_completion_min_confidence'] ?? 0.95));
                    $userPrefs->setAIMissingContextBehavior($userId, (string) ($_POST['ai_missing_context_behavior'] ?? 'warn'));
                    $userPrefs->setAIModeLock($userId, (string) ($_POST['ai_mode_lock'] ?? 'auto'));
                    if ($isSuperAdmin) {
                        $userPrefs->setAIAutonomousThresholdTuningEnabled($globalAiConfigUserId, isset($_POST['ai_autonomous_threshold_tuning_enabled']));
                        $userPrefs->setAICalibrationMinSampleSize($globalAiConfigUserId, (int) ($_POST['ai_calibration_min_sample_size'] ?? 30));
                        $userPrefs->setAICalibrationDailyChangeCap($globalAiConfigUserId, (float) ($_POST['ai_calibration_daily_change_cap'] ?? 0.02));
                        $userPrefs->setAICalibrationRollingChangeCap($globalAiConfigUserId, (float) ($_POST['ai_calibration_rolling_change_cap'] ?? 0.05));
                        $userPrefs->setAIIncidentAlertsEnabled($globalAiConfigUserId, isset($_POST['ai_incident_alerts_enabled']));
                        $userPrefs->setAIIncidentCheckEnabled($globalAiConfigUserId, isset($_POST['ai_incident_check_enabled']));
                        $userPrefs->setAIIncidentMediumCooldownMinutes($globalAiConfigUserId, (int) ($_POST['ai_incident_medium_cooldown_minutes'] ?? 360));
                        $userPrefs->setAIIncidentHighCooldownMinutes($globalAiConfigUserId, (int) ($_POST['ai_incident_high_cooldown_minutes'] ?? 240));
                        $userPrefs->setAIIncidentCriticalCooldownMinutes($globalAiConfigUserId, (int) ($_POST['ai_incident_critical_cooldown_minutes'] ?? 120));
                    }
                }
                }
            } elseif ($tab === 'company') {
                // Company Profile settings
                require_once __DIR__ . '/../modules/CompanyProfile.php';
                
                $companyProfile = new CompanyProfile();
                $existingProfile = $companyProfile->get() ?: [];
                $settings = [];
                $companyLogoPath = trim((string) ($existingProfile['company_logo_url'] ?? ''));
                if (isset($_POST['company_logo_remove'])) {
                    $companyLogoPath = '';
                } elseif (!empty($_FILES['company_logo_file']['tmp_name']) && is_uploaded_file($_FILES['company_logo_file']['tmp_name'])) {
                    $file = $_FILES['company_logo_file'];
                    $allowedMimes = ['image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/svg+xml'];
                    $mime = function_exists('mime_content_type') ? (string) mime_content_type($file['tmp_name']) : (string) ($file['type'] ?? '');
                    if (!in_array($mime, $allowedMimes, true)) {
                        throw new \RuntimeException('Company logo must be a PNG, JPG, GIF, SVG, or WebP image.');
                    }
                    if ((int) ($file['size'] ?? 0) > 2 * 1024 * 1024) {
                        throw new \RuntimeException('Company logo must be 2MB or smaller.');
                    }
                    $uploadDir = __DIR__ . '/../uploads/company/';
                    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                        throw new \RuntimeException('Could not create company upload directory.');
                    }
                    $ext = strtolower(pathinfo((string) ($file['name'] ?? 'logo'), PATHINFO_EXTENSION));
                    if ($ext === '') {
                        $ext = match ($mime) {
                            'image/jpeg' => 'jpg',
                            'image/gif' => 'gif',
                            'image/webp' => 'webp',
                            'image/svg+xml' => 'svg',
                            default => 'png',
                        };
                    }
                    $fileName = 'company_logo_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . preg_replace('/[^a-z0-9]+/i', '', $ext);
                    $destination = $uploadDir . $fileName;
                    if (!move_uploaded_file($file['tmp_name'], $destination)) {
                        throw new \RuntimeException('Could not save uploaded company logo.');
                    }
                    $companyLogoPath = 'uploads/company/' . $fileName;
                }
                $profileData = [
                    'company_name' => $_POST['company_name'] ?? '',
                    'company_legal_name' => $_POST['company_legal_name'] ?? '',
                    'company_tax_id' => $_POST['company_tax_id'] ?? '',
                    'company_tagline' => $_POST['company_tagline'] ?? '',
                    'company_description' => $_POST['company_description'] ?? '',
                    'company_mission' => $_POST['company_mission'] ?? '',
                    'company_values' => $_POST['company_values'] ?? '',
                    'owner_company_context' => $_POST['owner_company_context'] ?? '',
                    'company_website' => $_POST['company_website'] ?? '',
                    'company_email' => $_POST['company_email'] ?? '',
                    'company_phone' => $_POST['company_phone'] ?? '',
                    'company_address' => $_POST['company_address'] ?? '',
                    'company_location' => $_POST['company_location'] ?? '',
                    'company_timezone' => $_POST['company_timezone'] ?? '',
                    'company_industry' => $_POST['company_industry'] ?? '',
                    'company_founded' => !empty($_POST['company_founded']) ? (int) $_POST['company_founded'] : null,
                    'company_size' => $_POST['company_size'] ?? '',
                    'company_logo_url' => $companyLogoPath,
                    'social_linkedin' => $_POST['social_linkedin'] ?? '',
                    'social_twitter' => $_POST['social_twitter'] ?? '',
                    'social_facebook' => $_POST['social_facebook'] ?? '',
                    'icp_job_titles' => $_POST['icp_job_titles'] ?? '',
                    'icp_industries' => $_POST['icp_industries'] ?? '',
                    'icp_pain_points' => $_POST['icp_pain_points'] ?? '',
                    'icp_channels' => $_POST['icp_channels'] ?? '',
                    'expected_lock_version' => $_POST['expected_lock_version'] ?? null,
                ];
                $companyProfile->update($profileData);
                $settings = [
                    'COMPANY_NAME' => $_POST['company_name'] ?? '',
                    'PRIVACY_CONTACT_EMAIL' => $_POST['privacy_contact_email'] ?? '',
                ];
                $success = 'Company profile updated successfully';
            } elseif ($tab === 'products') {
                require_once __DIR__ . '/../modules/Products.php';

                $products = new Products();
                $settings = [];
                $skipEnvWrite = true;
                $buildProductPayload = static function (array $input): array {
                    return [
                        'name' => trim((string) ($input['product_name'] ?? '')),
                        'description' => trim((string) ($input['product_description'] ?? '')),
                        'category' => trim((string) ($input['product_category'] ?? '')),
                        'features' => !empty($input['product_features'])
                            ? preg_split('/\r\n|\r|\n/', trim((string) $input['product_features'])) ?: []
                            : [],
                        'unit_price' => $input['product_unit_price'] ?? null,
                        'pricing_info' => trim((string) ($input['product_pricing'] ?? '')),
                        'target_audience' => trim((string) ($input['product_target_audience'] ?? '')),
                        'use_cases' => trim((string) ($input['product_use_cases'] ?? '')),
                        'benefits' => trim((string) ($input['product_benefits'] ?? '')),
                        'product_image_url' => trim((string) ($input['product_image_url'] ?? '')),
                        'product_demo_video_url' => trim((string) ($input['product_demo_video_url'] ?? '')),
                        'display_order' => (int) ($input['product_display_order'] ?? 0),
                        'expected_lock_version' => $input['expected_lock_version'] ?? null,
                    ];
                };

                $isProductRequest = ((string) ($_POST['product_request'] ?? '')) === '1';
                $productAction = trim((string) ($_POST['product_action'] ?? ''));
                $productIdRaw = trim((string) ($_POST['product_id'] ?? ''));
                $hasProductId = $productIdRaw !== '' && is_numeric($productIdRaw);
                if ($isProductRequest) {
                    $action = $productAction;
                    
                    if ($action === 'add') {
                        $productId = $products->create($buildProductPayload($_POST));
                        $success = 'Product added successfully (#' . $productId . ').';
                    } elseif ($action === 'update' && $hasProductId) {
                        $productId = (int) $productIdRaw;
                        $updated = $products->update($productId, $buildProductPayload($_POST));
                        if (!$updated) {
                            throw new \RuntimeException('Unable to update product.');
                        }
                        $success = 'Product updated successfully.';
                    } elseif ($action === 'delete' && $hasProductId) {
                        $deleted = $products->delete((int) $productIdRaw);
                        if ($deleted) {
                            $success = 'Product deleted successfully.';
                        } else {
                            $error = 'Unable to delete product. It may already be inactive or missing.';
                        }
                    } else {
                        error_log(
                            'Settings products request invalid action: '
                            . var_export($productAction, true)
                            . ' product_id='
                            . var_export($productIdRaw, true)
                        );
                        $error = 'Invalid product action.';
                    }
                } else {
                    $success = 'Products settings opened.';
                }
            } elseif ($tab === 'voice') {
                $settings = [];
                $skipEnvWrite = true;
                (new WorkspaceOnboardingService())->saveStepDraft($activeWorkspaceId, $userId, 3, [
                    'draft_tone_preset' => $_POST['draft_tone_preset'] ?? 'consultative',
                    'relationship_style' => $_POST['relationship_style'] ?? 'trusted_advisor',
                    'draft_cta_style' => $_POST['draft_cta_style'] ?? 'clear',
                    'draft_formality_level' => $_POST['draft_formality_level'] ?? 'balanced',
                    'draft_reading_level' => $_POST['draft_reading_level'] ?? WorkspaceLanguageLevelService::DEFAULT_LEVEL,
                    'draft_voice_notes' => $_POST['draft_voice_notes'] ?? '',
                    'words_to_avoid' => $_POST['words_to_avoid'] ?? '',
                    'escalation_preference' => $_POST['escalation_preference'] ?? '',
                ]);
                $success = 'Voice settings updated successfully.';
            } elseif ($tab === 'enrichment') {
                // Enrichment settings
                $autoEnrichContacts = isset($_POST['auto_enrich_contacts']) ? 'true' : 'false';
                $autoEnrichOnUpdate = isset($_POST['auto_enrich_on_update']) ? 'true' : 'false';
                $autoEnrichOnEmail = isset($_POST['auto_enrich_on_email']) ? 'true' : 'false';
                $confidenceThreshold = (float) ($_POST['confidence_threshold'] ?? 0.7);
                $maxCostPerContact = (float) ($_POST['max_cost_per_contact'] ?? 0.10);
                
                $settings = [
                    'AUTO_ENRICH_CONTACTS' => $autoEnrichContacts,
                    'AUTO_ENRICH_ON_UPDATE' => $autoEnrichOnUpdate,
                    'AUTO_ENRICH_ON_EMAIL' => $autoEnrichOnEmail,
                    // Third-party enrichment API keys
                    'CLEARBIT_API_KEY' => $_POST['clearbit_api_key'] ?? '',
                    'PDL_API_KEY' => $_POST['pdl_api_key'] ?? '',
                    'HUNTER_API_KEY' => $_POST['hunter_api_key'] ?? '',
                ];
                
                // Update database config
                Database::execute(
                    "UPDATE enrichment_config SET 
                     auto_enrich_on_create = ?, 
                     auto_enrich_on_update = ?, 
                     auto_enrich_on_email = ?,
                     confidence_threshold = ?,
                     max_cost_per_contact = ?
                     WHERE id = 1",
                    [$autoEnrichContacts === 'true', $autoEnrichOnUpdate === 'true', $autoEnrichOnEmail === 'true', $confidenceThreshold, $maxCostPerContact]
                );
            } elseif ($tab === 'scoring') {
                // Scoring weight settings
                $engagementPercent = (float) ($_POST['engagement_weight'] ?? 40);
                $mlPercent = (float) ($_POST['ml_weight'] ?? 40);
                $aiPercent = (float) ($_POST['ai_weight'] ?? 20);
                $percentSum = $engagementPercent + $mlPercent + $aiPercent;

                if ($engagementPercent < 0 || $engagementPercent > 100
                    || $mlPercent < 0 || $mlPercent > 100
                    || $aiPercent < 0 || $aiPercent > 100
                ) {
                    throw new \RuntimeException('Scoring weights must each be between 0% and 100%.');
                }

                if (abs($percentSum - 100.0) > 0.1) {
                    throw new \RuntimeException('Scoring weights must sum to 100%.');
                }

                $defaultWeights = AILeadScoring::validateWeights([
                    'engagement' => $engagementPercent / 100,
                    'ml' => $mlPercent / 100,
                    'ai' => $aiPercent / 100,
                ]);

                (new WorkspaceScoringConfigService())->saveConfig(
                    $activeWorkspaceId,
                    $defaultWeights,
                    isset($_POST['auto_use_recommended_weights'])
                );

                $settings = [];
                $skipEnvWrite = true;
                $success = 'Scoring settings updated successfully.';
            } elseif ($tab === 'deal_automation') {
                require_once __DIR__ . '/../modules/DealAutomationConfig.php';
                $config = new \CRM\Modules\DealAutomationConfig();
                $existing = $config->get($activeWorkspaceId > 0 ? $activeWorkspaceId : null);
                $transitions = $existing['transitions'];
                if (!empty($_POST['deal_automation_transitions'])) {
                    $decoded = json_decode($_POST['deal_automation_transitions'], true);
                    if (is_array($decoded) && isset($decoded['transitions'])) {
                        $transitions = $decoded['transitions'];
                    }
                }
                $dealAutomationResult = (new DealAutomationModeService($config))->save([
                    'enabled' => isset($_POST['deal_automation_enabled']),
                    'mode' => $_POST['deal_automation_mode'] ?? 'suggest_only',
                    'min_confidence' => (float) ($_POST['deal_automation_min_confidence'] ?? 0.85),
                    'lookback_days' => (int) ($_POST['deal_automation_lookback_days'] ?? 14),
                    'cooldown_hours' => (int) ($_POST['deal_automation_cooldown_hours'] ?? 24),
                    'require_approval_terminal' => isset($_POST['deal_automation_require_approval_terminal']),
                    'min_terminal_confidence' => (float) ($_POST['deal_automation_min_terminal_confidence'] ?? 0.92),
                    'inactivity_days_for_loss' => (int) ($_POST['deal_automation_inactivity_days'] ?? 14),
                    'allow_multi_stage_jump' => isset($_POST['deal_automation_allow_multi_stage_jump']),
                    'dry_run' => isset($_POST['deal_automation_dry_run']),
                    'reopen_lost_on_reengagement' => isset($_POST['deal_automation_reopen_lost_on_reengagement']),
                    'auto_create_from_inbound' => isset($_POST['deal_auto_create_from_inbound']),
                    'auto_create_require_non_negative' => isset($_POST['deal_auto_create_require_non_negative']),
                    'auto_create_require_meaningful_reply' => isset($_POST['deal_auto_create_require_meaningful']),
                    'auto_create_min_message_chars' => (int) ($_POST['deal_auto_create_min_chars'] ?? 20),
                    'auto_create_dedupe_hours' => (int) ($_POST['deal_auto_create_dedupe_hours'] ?? 24),
                    'auto_create_channels' => [
                        'email' => isset($_POST['deal_auto_create_channel_email']),
                        'whatsapp' => isset($_POST['deal_auto_create_channel_whatsapp']),
                        'sms' => isset($_POST['deal_auto_create_channel_sms']),
                    ],
                    'inbox_triage_enabled' => isset($_POST['inbox_triage_enabled']),
                    'inbox_triage_auto_apply' => isset($_POST['inbox_triage_auto_apply']),
                    'inbox_triage_channels' => [
                        'email' => isset($_POST['inbox_triage_channel_email']),
                        'whatsapp' => isset($_POST['inbox_triage_channel_whatsapp']),
                    ],
                    'inbox_triage_min_confidence' => (float) ($_POST['inbox_triage_min_confidence'] ?? 0.80),
                    'inbox_triage_task_due_hours' => (int) ($_POST['inbox_triage_task_due_hours'] ?? 24),
                    'inbox_triage_dedupe_hours' => (int) ($_POST['inbox_triage_dedupe_hours'] ?? 24),
                    'inbox_triage_owner_fallback' => $_POST['inbox_triage_owner_fallback'] ?? 'round_robin',
                    'inbox_triage_negative_phrase_blocklist' => $_POST['inbox_triage_negative_phrase_blocklist'] ?? '',
                    'inbox_triage_opt_out_phrase_blocklist' => $_POST['inbox_triage_opt_out_phrase_blocklist'] ?? '',
                    'outcome_layer_enabled' => isset($_POST['outcome_layer_enabled']),
                    'outcome_layer_checklist_enabled' => isset($_POST['outcome_layer_checklist_enabled']),
                    'outcome_layer_daily_focus_enabled' => isset($_POST['outcome_layer_daily_focus_enabled']),
                    'outcome_layer_metrics_enabled' => isset($_POST['outcome_layer_metrics_enabled']),
                    'outcome_layer_rollout_percent' => (int) ($_POST['outcome_layer_rollout_percent'] ?? 0),
                    'transitions' => $transitions,
                ], $userId, $activeWorkspaceId > 0 ? $activeWorkspaceId : null);
                if (empty($dealAutomationResult['success'])) {
                    throw new \RuntimeException((string) ($dealAutomationResult['error'] ?? 'Deal automation settings could not be saved.'));
                }
                $settings = [];
            } elseif ($tab === 'invoicing') {
                $docTypesByStage = [
                    'proposal' => array_values(array_filter($_POST['invoice_stage_proposal'] ?? [])),
                    'negotiation' => array_values(array_filter($_POST['invoice_stage_negotiation'] ?? [])),
                    'closed_won' => array_values(array_filter($_POST['invoice_stage_closed_won'] ?? [])),
                ];
                (new InvoiceSettings())->save([
                    'enabled' => isset($_POST['invoice_enabled']),
                    'invoice_prefix' => trim((string) ($_POST['invoice_prefix'] ?? 'INV-')),
                    'invoice_next_number' => (int) ($_POST['invoice_next_number'] ?? 1),
                    'proforma_prefix' => trim((string) ($_POST['proforma_prefix'] ?? 'PF-')),
                    'proforma_next_number' => (int) ($_POST['proforma_next_number'] ?? 1),
                    'quote_prefix' => trim((string) ($_POST['quote_prefix'] ?? 'QT-')),
                    'quote_next_number' => (int) ($_POST['quote_next_number'] ?? 1),
                    'default_currency' => trim((string) ($_POST['invoice_default_currency'] ?? 'USD')),
                    'default_tax_mode' => $_POST['invoice_default_tax_mode'] ?? 'exclusive',
                    'default_tax_rate' => (float) ($_POST['invoice_default_tax_rate'] ?? 0),
                    'default_payment_terms_days' => (int) ($_POST['invoice_default_payment_terms_days'] ?? 14),
                    'default_validity_days' => (int) ($_POST['invoice_default_validity_days'] ?? 14),
                    'default_notes' => trim((string) ($_POST['invoice_default_notes'] ?? '')),
                    'default_terms' => trim((string) ($_POST['invoice_default_terms'] ?? '')),
                    'bank_name' => trim((string) ($_POST['invoice_bank_name'] ?? '')),
                    'bank_account_name' => trim((string) ($_POST['invoice_bank_account_name'] ?? '')),
                    'bank_account_number' => trim((string) ($_POST['invoice_bank_account_number'] ?? '')),
                    'bank_branch' => trim((string) ($_POST['invoice_bank_branch'] ?? '')),
                    'bank_swift' => trim((string) ($_POST['invoice_bank_swift'] ?? '')),
                    'bank_instructions' => trim((string) ($_POST['invoice_bank_instructions'] ?? '')),
                    'footer_text' => trim((string) ($_POST['invoice_footer_text'] ?? '')),
                    'visual_theme' => trim((string) ($_POST['invoice_default_template_key'] ?? 'classic')),
                    'default_template_key' => trim((string) ($_POST['invoice_default_template_key'] ?? 'classic')),
                    'preview_document_type' => trim((string) ($_POST['invoice_preview_document_type'] ?? 'invoice')),
                    'proposal_intro_text' => trim((string) ($_POST['invoice_proposal_intro_text'] ?? '')),
                    'acceptance_instructions' => trim((string) ($_POST['invoice_acceptance_instructions'] ?? '')),
                    'ai_create_quotes' => isset($_POST['invoice_ai_create_quotes']),
                    'ai_revise_documents' => isset($_POST['invoice_ai_revise_documents']),
                    'ai_send_documents' => isset($_POST['invoice_ai_send_documents']),
                    'ai_finalize_invoices' => isset($_POST['invoice_ai_finalize_invoices']),
                    'ai_mark_paid' => isset($_POST['invoice_ai_mark_paid']),
                    'ai_require_approval_send' => isset($_POST['invoice_ai_require_approval_send']),
                    'ai_require_approval_finalize' => isset($_POST['invoice_ai_require_approval_finalize']),
                    'ai_allowed_channels' => [
                        'email' => isset($_POST['invoice_ai_channel_email']),
                        'whatsapp' => isset($_POST['invoice_ai_channel_whatsapp']),
                    ],
                    'ai_max_discount_percent' => (float) ($_POST['invoice_ai_max_discount_percent'] ?? 20),
                    'ai_max_total_change_percent' => (float) ($_POST['invoice_ai_max_total_change_percent'] ?? 25),
                    'ai_allowed_document_types_by_stage' => $docTypesByStage,
                ], (int) ($user['id'] ?? 0));
                $invoiceSettings = (new InvoiceSettings())->get();
                $settings = [];
            } elseif ($tab === 'meeting_bot') {
                $existingMeetingBotConfig = (array) ((new MeetingBotConfig())->get());
                (new MeetingBotConfig())->save([
                    'enabled' => isset($_POST['meeting_bot_enabled']),
                    'provider' => (string) ($_POST['meeting_bot_provider'] ?? 'zoom'),
                    'bot_display_name' => (string) ($_POST['meeting_bot_display_name'] ?? ''),
                    'join_policy' => (string) ($_POST['meeting_bot_join_policy'] ?? 'manual_invite_only'),
                    'recording_mode' => (string) ($_POST['meeting_bot_recording_mode'] ?? 'provider_native'),
                    'transcript_required' => isset($_POST['meeting_bot_transcript_required']),
                    'auto_apply_mode' => (string) ($_POST['meeting_bot_auto_apply_mode'] ?? 'auto_safe'),
                    'consent_notice' => (string) ($_POST['meeting_bot_consent_notice'] ?? ''),
                    'zoom_account_id' => $isSuperAdmin ? (string) ($_POST['meeting_bot_zoom_account_id'] ?? '') : (string) ($existingMeetingBotConfig['zoom_account_id'] ?? ''),
                    'zoom_client_id' => $isSuperAdmin ? (string) ($_POST['meeting_bot_zoom_client_id'] ?? '') : (string) ($existingMeetingBotConfig['zoom_client_id'] ?? ''),
                    'zoom_client_secret' => $isSuperAdmin ? (string) ($_POST['meeting_bot_zoom_client_secret'] ?? '') : (string) ($existingMeetingBotConfig['zoom_client_secret'] ?? ''),
                    'google_workspace_client_id' => $isSuperAdmin ? (string) ($_POST['meeting_bot_google_workspace_client_id'] ?? '') : (string) ($existingMeetingBotConfig['google_workspace_client_id'] ?? ''),
                    'google_workspace_client_secret' => $isSuperAdmin ? (string) ($_POST['meeting_bot_google_workspace_client_secret'] ?? '') : (string) ($existingMeetingBotConfig['google_workspace_client_secret'] ?? ''),
                    'google_workspace_project_id' => $isSuperAdmin ? (string) ($_POST['meeting_bot_google_workspace_project_id'] ?? '') : (string) ($existingMeetingBotConfig['google_workspace_project_id'] ?? ''),
                    'google_calendar_integration_id' => $isSuperAdmin ? (string) ($_POST['meeting_bot_google_calendar_integration_id'] ?? '') : (string) ($existingMeetingBotConfig['google_calendar_integration_id'] ?? ''),
                    'google_transcript_mode' => (string) ($_POST['meeting_bot_google_transcript_mode'] ?? 'manual_ingest'),
                    'webhook_secret' => !$isSuperAdmin
                        ? (string) ($existingMeetingBotConfig['webhook_secret'] ?? '')
                        : (isset($_POST['meeting_bot_regenerate_webhook_secret'])
                        ? ''
                        : (string) ($_POST['meeting_bot_webhook_secret'] ?? '')),
                    'scheduling_secret' => !$isSuperAdmin
                        ? (string) ($existingMeetingBotConfig['scheduling_secret'] ?? '')
                        : (isset($_POST['meeting_bot_regenerate_scheduling_secret'])
                        ? ''
                        : (string) ($_POST['meeting_bot_scheduling_secret'] ?? '')),
                ], (int) ($user['id'] ?? 0));
                $meetingBotService = new MeetingBotService();
                $meetingBotState = $meetingBotService->buildSettingsState();
                $meetingBotConfig = (array) ($meetingBotState['config'] ?? []);
                $meetingBotRuns = Authorization::can('meeting_bot.view_runs', $user)
                    ? $meetingBotService->getRecentRuns(12)
                    : [];
                $settings = [];
            } elseif ($tab === 'meeting_note_taker') {
                (new MeetingNoteTakerConfig())->save([
                    'enabled' => isset($_POST['meeting_note_taker_enabled']),
                    'auto_apply_mode' => (string) ($_POST['meeting_note_taker_auto_apply_mode'] ?? 'full_auto'),
                    'contact_updates_additive_only' => isset($_POST['meeting_note_taker_contact_updates_additive_only']),
                    'task_auto_create_enabled' => isset($_POST['meeting_note_taker_task_auto_create_enabled']),
                    'deal_stage_auto_move_enabled' => isset($_POST['meeting_note_taker_deal_stage_auto_move_enabled']),
                    'deal_stage_min_confidence' => (float) ($_POST['meeting_note_taker_deal_stage_min_confidence'] ?? 0.90),
                    'contact_update_min_confidence' => (float) ($_POST['meeting_note_taker_contact_update_min_confidence'] ?? 0.75),
                    'max_context_entries' => (int) ($_POST['meeting_note_taker_max_context_entries'] ?? 10),
                    'allowed_contact_fields' => array_values(array_filter((array) ($_POST['meeting_note_taker_allowed_contact_fields'] ?? []))),
                    'ingest_secret' => isset($_POST['meeting_note_taker_regenerate_secret'])
                        ? ''
                        : (string) ($_POST['meeting_note_taker_ingest_secret'] ?? ''),
                ], (int) ($user['id'] ?? 0));
                $meetingNoteTakerConfig = (new MeetingNoteTakerConfig())->get();
                $meetingNoteTakerRuns = Authorization::can('meeting_notes.view_runs', $user)
                    ? (new MeetingNoteTakerService())->getRecentRuns(12)
                    : [];
                $settings = [];
            } elseif ($tab === 'workspace_governance') {
                if (!$canWorkspaceGovernanceTab || $activeWorkspaceId <= 0) {
                    throw new \RuntimeException('You do not have permission to manage this workspace.');
                }

                $workspaceGovernanceService = $workspaceGovernanceService ?? new WorkspaceGovernanceService();
                $governanceAction = trim((string) ($_POST['workspace_governance_action'] ?? ''));
                (new WorkspaceLaunchGuardrailService())->enforceGovernanceAction($activeWorkspaceId, $governanceAction);

                if ($governanceAction === 'invite_member') {
                    $inviteFunctionIds = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['invite_function_ids'] ?? [])), static fn(int $id): bool => $id > 0)));
                    $invitePrimaryFunctionId = (int) ($_POST['invite_primary_function_id'] ?? 0);
                    $inviteFunctionAssignmentTypes = [];
                    foreach ((array) ($_POST['invite_function_assignment_types'] ?? []) as $functionId => $assignmentType) {
                        $inviteFunctionAssignmentTypes[(int) $functionId] = (string) $assignmentType;
                    }

                    $functionService = new OrganizationFunctionService();
                    if ($functionService->tablesReady()) {
                        $functionService->ensureDefaults($activeWorkspaceId);
                        if ($functionService->listAssignableFunctions($activeWorkspaceId) !== [] && $inviteFunctionIds === []) {
                            throw new \RuntimeException('Assign at least one work ownership area before creating an invite.');
                        }
                    }

                    $workspaceGovernanceActionResult = $workspaceGovernanceService->createInvite(
                        $activeWorkspaceId,
                        $userId,
                        (string) ($_POST['invite_email'] ?? ''),
                        (string) ($_POST['invite_role_slug'] ?? 'viewer'),
                        $inviteFunctionIds,
                        $invitePrimaryFunctionId,
                        $inviteFunctionAssignmentTypes
                    );
                    $success = !empty($workspaceGovernanceActionResult['delivery']['success'])
                        ? 'Workspace invite created and delivered successfully.'
                        : 'Workspace invite created. Delivery failed, so you can resend it or share the invite link manually.';
                } elseif ($governanceAction === 'update_workspace_security') {
                    $requireMember2FA = !empty($_POST['require_member_2fa']);
                    $workspaceSecurityService = new WorkspaceSecuritySettingsService();
                    $workspaceSecurityService->setRequireMember2FA($activeWorkspaceId, $requireMember2FA, $userId);
                    (new \CRM\Services\WorkspaceGovernanceEventLogService())->log(
                        $activeWorkspaceId,
                        'workspace_security_updated',
                        $userId,
                        null,
                        null,
                        ['require_member_2fa' => $requireMember2FA]
                    );
                    $workspaceSecuritySettings = $workspaceSecurityService->getSettings($activeWorkspaceId);
                    $workspaceSecurity2FASummary = $workspaceSecurityService->memberTwoFactorSummary($activeWorkspaceId);
                    $success = $requireMember2FA
                        ? 'Workspace 2FA requirement enabled. Members without 2FA must set it up before accessing this workspace.'
                        : 'Workspace 2FA requirement disabled.';
                } elseif ($governanceAction === 'update_automation_readiness_refresh') {
                    $automationReadinessRefreshService = new WorkspaceAutomationReadinessSettingsService();
                    $automationReadinessRefreshPolicy = $automationReadinessRefreshService->savePolicy(
                        $activeWorkspaceId,
                        (string) ($_POST['automation_readiness_refresh_cadence'] ?? ''),
                        $userId
                    );
                    (new \CRM\Services\WorkspaceGovernanceEventLogService())->log(
                        $activeWorkspaceId,
                        'automation_readiness_refresh_updated',
                        $userId,
                        null,
                        null,
                        [
                            'refresh_cadence' => (string) ($automationReadinessRefreshPolicy['cadence'] ?? WorkspaceAutomationReadinessSettingsService::DEFAULT_CADENCE),
                        ]
                    );
                    $success = 'Automation readiness refresh cadence updated.';
                } elseif ($governanceAction === 'resend_invite') {
                    $workspaceGovernanceActionResult = $workspaceGovernanceService->resendInvite(
                        $activeWorkspaceId,
                        (int) ($_POST['invite_id'] ?? 0),
                        $userId
                    );
                    $success = !empty($workspaceGovernanceActionResult['delivery']['success'])
                        ? 'Workspace invite resent successfully.'
                        : 'Workspace invite link was rotated, but delivery failed. You can share the new link manually.';
                } elseif ($governanceAction === 'revoke_invite') {
                    $workspaceGovernanceActionResult = [
                        'invite' => $workspaceGovernanceService->revokeInvite(
                            $activeWorkspaceId,
                            (int) ($_POST['invite_id'] ?? 0),
                            $userId
                        ),
                    ];
                    $success = 'Workspace invite revoked.';
                } elseif ($governanceAction === 'retry_invite_delivery') {
                    $workspaceGovernanceActionResult = $workspaceGovernanceService->retryInviteDelivery(
                        $activeWorkspaceId,
                        (int) ($_POST['invite_id'] ?? 0),
                        $userId
                    );
                    $success = !empty($workspaceGovernanceActionResult['delivery']['success'])
                        ? 'Workspace invite delivery retried successfully.'
                        : 'Workspace invite delivery retry failed. You can try again later.';
                } elseif ($governanceAction === 'update_member_role') {
                    $workspaceGovernanceActionResult = [
                        'membership' => $workspaceGovernanceService->updateMemberRole(
                            $activeWorkspaceId,
                            (int) ($_POST['membership_id'] ?? 0),
                            $userId,
                            (string) ($_POST['role_slug'] ?? 'viewer')
                        ),
                    ];
                    $success = 'Workspace member role updated.';
                } elseif ($governanceAction === 'assign_work_ownership') {
                    $workspaceGovernanceActionResult = [
                        'membership' => $workspaceGovernanceService->assignMemberWorkOwnership(
                            $activeWorkspaceId,
                            (int) ($_POST['membership_id'] ?? 0),
                            $userId,
                            (int) ($_POST['function_id'] ?? 0),
                            (string) ($_POST['assignment_type'] ?? 'contributor'),
                            !empty($_POST['make_primary'])
                        ),
                    ];
                    $success = !empty($_POST['make_primary'])
                        ? 'Primary Work Ownership assigned.'
                        : 'Supporting Work Ownership assigned.';
                } elseif ($governanceAction === 'remove_member') {
                    $workspaceGovernanceActionResult = [
                        'membership' => $workspaceGovernanceService->removeMember(
                            $activeWorkspaceId,
                            (int) ($_POST['membership_id'] ?? 0),
                            $userId
                        ),
                    ];
                    $success = 'Workspace member removed from this workspace.';
                } elseif ($governanceAction === 'restore_member') {
                    $workspaceGovernanceActionResult = [
                        'membership' => $workspaceGovernanceService->setMemberStatus(
                            $activeWorkspaceId,
                            (int) ($_POST['membership_id'] ?? 0),
                            $userId,
                            'active'
                        ),
                    ];
                    $success = 'Workspace member restored.';
                } elseif ($governanceAction === 'transfer_ownership') {
                    $workspaceGovernanceActionResult = $workspaceGovernanceService->transferOwnership(
                        $activeWorkspaceId,
                        $userId,
                        (int) ($_POST['target_membership_id'] ?? 0),
                        !empty($_POST['retain_actor_ownership']),
                        (string) ($_POST['demoted_actor_role'] ?? 'admin')
                    );
                    $success = 'Workspace ownership updated successfully.';
                } elseif ($governanceAction === 'set_primary_slug') {
                    if (!$canPlatformSystemReset) {
                        throw new \RuntimeException('Only Super Admin can update workspace slugs.');
                    }
                    $workspaceGovernanceActionResult = $workspaceGovernanceService->setPrimaryWorkspaceSlug(
                        $activeWorkspaceId,
                        $userId,
                        (string) ($_POST['workspace_slug'] ?? '')
                    );
                    $success = 'Workspace slug updated successfully.';
                } else {
                    throw new \RuntimeException('Unsupported workspace governance action.');
                }

                $workspaceGovernanceData = $workspaceGovernanceService->getWorkspaceGovernanceData(
                    $activeWorkspaceId,
                    $userId,
                    (string) ($_GET['history_filter'] ?? '')
                );
                $automationReadinessRefreshPolicy = (new WorkspaceAutomationReadinessSettingsService())->getPolicy($activeWorkspaceId);
                $settings = [];
            } elseif ($tab === 'billing') {
                $workspaceBillingService = new WorkspaceBillingService();
                $saasBillingService = new SaaSBillingService();
                $billingAction = trim((string) ($_POST['billing_action'] ?? 'save_settings'));

                if ($billingAction === 'save_settings') {
                    if (!$canPlatformBillingAdmin) {
                        throw new \RuntimeException('You do not have permission to update workspace billing.');
                    }

                    $billingPaystackSecretKey = trim((string) ($_POST['billing_paystack_secret_key'] ?? ''));
                    $billingMpesaEnabled = isset($_POST['billing_mpesa_enabled']);
                    $billingMpesaConsumerKey = trim((string) ($_POST['billing_mpesa_consumer_key'] ?? ''));
                    $billingMpesaConsumerSecret = trim((string) ($_POST['billing_mpesa_consumer_secret'] ?? ''));
                    $billingMpesaShortcode = trim((string) ($_POST['billing_mpesa_shortcode'] ?? ''));
                    $billingMpesaPasskey = trim((string) ($_POST['billing_mpesa_passkey'] ?? ''));
                    $billingSettingsPayload = [
                        'enabled' => isset($_POST['billing_enabled']),
                        'billing_mode' => trim((string) ($_POST['billing_mode'] ?? 'central_hub')),
                        'billing_hub_base_url' => trim((string) ($_POST['billing_hub_base_url'] ?? '')),
                        'billing_workspace_key' => trim((string) ($_POST['billing_workspace_key'] ?? '')),
                        'billing_hub_signing_secret' => trim((string) ($_POST['billing_hub_signing_secret'] ?? '')),
                        'billing_hub_checkout_path' => trim((string) ($_POST['billing_hub_checkout_path'] ?? '/api/billing/workspaces/{workspace_key}/checkout')),
                        'billing_hub_status_path' => trim((string) ($_POST['billing_hub_status_path'] ?? '/api/billing/workspaces/{workspace_key}/status')),
                        'paystack_mode' => trim((string) ($_POST['billing_paystack_mode'] ?? 'test')),
                        'paystack_public_key' => trim((string) ($_POST['billing_paystack_public_key'] ?? '')),
                        'paystack_secret_key' => $billingPaystackSecretKey,
                        'paystack_callback_url' => WorkspaceBillingSettings::generatedPaystackCallbackUrl(),
                        'paystack_webhook_url' => WorkspaceBillingSettings::generatedPaystackWebhookUrl(),
                        'mpesa_enabled' => $billingMpesaEnabled,
                        'mpesa_environment' => trim((string) ($_POST['billing_mpesa_environment'] ?? 'sandbox')),
                        'mpesa_consumer_key' => $billingMpesaConsumerKey,
                        'mpesa_consumer_secret' => $billingMpesaConsumerSecret,
                        'mpesa_shortcode' => $billingMpesaShortcode,
                        'mpesa_passkey' => $billingMpesaPasskey,
                        'mpesa_callback_url' => WorkspaceBillingSettings::generatedMpesaCallbackUrl(),
                        'mobile_return_url' => trim((string) ($_POST['billing_mobile_return_url'] ?? '')),
                        'default_currency' => trim((string) ($_POST['billing_default_currency'] ?? 'KES')),
                        'donations_enabled' => isset($_POST['billing_donations_enabled']),
                        'email_reminders_enabled' => isset($_POST['billing_email_reminders_enabled']),
                        'in_app_prompts_enabled' => isset($_POST['billing_in_app_prompts_enabled']),
                        'auto_lock_enabled' => isset($_POST['billing_auto_lock_enabled']),
                        'grace_days' => (int) ($_POST['billing_grace_days'] ?? 3),
                        'reminder_days_before_json' => array_values(array_filter(array_map('trim', explode(',', (string) ($_POST['billing_reminder_days'] ?? '7,3,1'))), static fn ($value): bool => $value !== '')),
                    ];
                    $workspaceBillingService->saveSettings($billingSettingsPayload, (int) ($user['id'] ?? 0));
                    $workspaceBillingService->saveContactsFromTextarea((string) ($_POST['billing_contacts'] ?? ''));
                } elseif ($billingAction === 'run_maintenance') {
                    if (!$canPlatformBillingAdmin) {
                        throw new \RuntimeException('You do not have permission to run workspace billing maintenance.');
                    }
                    $workspaceBillingService->runMaintenance((int) ($user['id'] ?? 0));
                }

                $workspaceBillingSettings = (new WorkspaceBillingSettings())->get();
                if ($activeWorkspaceId > 0) {
                    $saasBillingPortal = $saasBillingService->getWorkspaceBillingPortalData($activeWorkspaceId, 20, $user);
                }
                $settings = [];
            } elseif ($tab === 'package_settings') {
                if (!$isSuperAdmin) {
                    throw new \RuntimeException('Only Super Admin can manage package settings.');
                }

                $billingAction = trim((string) ($_POST['billing_action'] ?? ''));
                $reason = trim((string) ($_POST['reason'] ?? ''));
                if ($reason === '') {
                    throw new \RuntimeException('A reason is required for package setting changes.');
                }

                $operations = new PlatformWorkspaceOperationsService();
                $postedPaymentModes = static function (): ?array {
                    $scope = (string) ($_POST['payment_scope'] ?? 'all');
                    if ($scope !== 'custom') {
                        return null;
                    }
                    $supported = ['card', 'mpesa', 'bank_transfer'];
                    $posted = array_keys((array) ($_POST['payment_modes'] ?? []));
                    return array_values(array_intersect($supported, $posted));
                };
                $postedFeatureValues = static function (): array {
                    $values = [];
                    foreach ((array) ($_POST['feature_values'] ?? []) as $key => $value) {
                        $key = preg_replace('/[^a-zA-Z0-9_]+/', '_', (string) $key);
                        if ($key !== '') {
                            $values[$key] = $value;
                        }
                    }
                    foreach ((array) ($_POST['feature_enabled'] ?? []) as $key => $value) {
                        $key = preg_replace('/[^a-zA-Z0-9_]+/', '_', (string) $key);
                        if ($key !== '') {
                            $values[$key] = !empty($value);
                        }
                    }
                    foreach (['can_top_up', 'business_intelligence', 'personal_api_key'] as $key) {
                        if (isset($_POST[$key]) || isset($_POST[$key . '_enabled'])) {
                            $values[$key] = isset($_POST[$key]) || isset($_POST[$key . '_enabled']);
                        }
                    }
                    if (isset($_POST['seat_limit'])) {
                        $values['seat_limit'] = (int) $_POST['seat_limit'];
                    }
                    if (isset($_POST['credit_expiry_days'])) {
                        $values['credit_expiry_days'] = (int) $_POST['credit_expiry_days'];
                    }
                    return $values;
                };
                $requireDestructiveConfirmation = static function (): void {
                    if (strtoupper(trim((string) ($_POST['confirm_destructive'] ?? ''))) !== 'ARCHIVE') {
                        throw new \RuntimeException('Type ARCHIVE to confirm this archive/delete action.');
                    }
                };
                if ($billingAction === 'update_plan') {
                    $operations->updateSubscriptionPlanCommercials(
                        (int) ($_POST['billing_plan_price_id'] ?? 0),
                        (float) ($_POST['amount'] ?? 0),
                        (string) ($_POST['currency'] ?? 'KES'),
                        (int) ($_POST['included_credits'] ?? 0),
                        (string) ($_POST['interval_unit'] ?? 'monthly'),
                        (int) ($_POST['interval_count'] ?? 1),
                        (int) ($_POST['seat_limit'] ?? 0),
                        !empty($_POST['can_top_up']),
                        !empty($_POST['business_intelligence_enabled']),
                        !empty($_POST['personal_api_key_enabled']),
                        (int) ($_POST['credit_expiry_days'] ?? 180),
                        !empty($_POST['is_custom']),
                        (string) ($_POST['maturity_tier'] ?? ''),
                        (string) ($_POST['display_name'] ?? ''),
                        (string) ($_POST['display_copy'] ?? ''),
                        (int) ($_POST['display_order'] ?? 0),
                        !empty($_POST['is_active']),
                        !empty($_POST['is_default']),
                        $userId,
                        $reason
                    );
                    if (isset($_POST['payment_scope'])) {
                        $operations->savePricePaymentMethods((int) ($_POST['billing_plan_price_id'] ?? 0), $postedPaymentModes(), $userId, $reason);
                    }
                    $success = 'Package pricing and details updated.';
                } elseif ($billingAction === 'save_negotiated_offer') {
                    $negotiated = new WorkspaceNegotiatedPackageService();
                    $offerId = (int) ($_POST['offer_id'] ?? 0);
                    $payload = [
                        'workspace_id' => (int) ($_POST['workspace_id'] ?? 0),
                        'base_billing_plan_price_id' => (int) ($_POST['base_billing_plan_price_id'] ?? 0),
                        'amount' => (float) ($_POST['amount'] ?? 0),
                        'currency' => (string) ($_POST['currency'] ?? 'KES'),
                        'interval_unit' => (string) ($_POST['interval_unit'] ?? 'monthly'),
                        'interval_count' => (int) ($_POST['interval_count'] ?? 1),
                        'included_credits' => (int) ($_POST['included_credits'] ?? 0),
                        'seat_limit' => (int) ($_POST['seat_limit'] ?? 0),
                        'credit_expiry_days' => (int) ($_POST['credit_expiry_days'] ?? 180),
                        'can_top_up' => isset($_POST['can_top_up']),
                        'business_intelligence' => isset($_POST['business_intelligence']),
                        'personal_api_key' => isset($_POST['personal_api_key']),
                        'display_name' => (string) ($_POST['display_name'] ?? 'Negotiated Workspace Package'),
                        'display_copy' => (string) ($_POST['display_copy'] ?? ''),
                        'agreement_reference' => (string) ($_POST['agreement_reference'] ?? ''),
                        'starts_at' => (string) ($_POST['starts_at'] ?? ''),
                        'expires_at' => (string) ($_POST['expires_at'] ?? ''),
                        'notes' => (string) ($_POST['notes'] ?? ''),
                        'status' => (string) ($_POST['offer_status'] ?? 'draft'),
                        'features' => $postedFeatureValues(),
                        'payment_modes' => $postedPaymentModes(),
                    ];
                    if ($offerId > 0) {
                        $negotiated->updateOffer($offerId, $payload, $userId, $reason);
                        $success = 'Negotiated workspace package updated.';
                    } else {
                        $negotiated->createOffer($payload, $userId, $reason);
                        $success = 'Negotiated workspace package created.';
                    }
                } elseif ($billingAction === 'publish_negotiated_offer') {
                    (new WorkspaceNegotiatedPackageService())->publishOffer((int) ($_POST['offer_id'] ?? 0), $userId, $reason);
                    $success = 'Negotiated workspace package published.';
                } elseif ($billingAction === 'archive_negotiated_offer') {
                    $requireDestructiveConfirmation();
                    (new WorkspaceNegotiatedPackageService())->archiveOffer((int) ($_POST['offer_id'] ?? 0), $userId, $reason);
                    $success = 'Negotiated workspace package archived.';
                } elseif ($billingAction === 'activate_negotiated_offer') {
                    (new WorkspaceNegotiatedPackageService())->activateOffer((int) ($_POST['offer_id'] ?? 0), $userId, $reason);
                    $success = 'Negotiated workspace package activated for the workspace.';
                } elseif ($billingAction === 'create_package') {
                    $operations->createSubscriptionPackage([
                        'code' => (string) ($_POST['code'] ?? ''),
                        'name' => (string) ($_POST['name'] ?? ''),
                        'description' => (string) ($_POST['description'] ?? ''),
                        'currency' => (string) ($_POST['currency'] ?? 'KES'),
                        'amount' => (float) ($_POST['amount'] ?? 0),
                        'interval_unit' => (string) ($_POST['interval_unit'] ?? 'monthly'),
                        'interval_count' => (int) ($_POST['interval_count'] ?? 1),
                        'included_credits' => (int) ($_POST['included_credits'] ?? 0),
                        'seat_limit' => (int) ($_POST['seat_limit'] ?? 1),
                        'credit_expiry_days' => (int) ($_POST['credit_expiry_days'] ?? 180),
                        'can_top_up' => isset($_POST['can_top_up']),
                        'business_intelligence' => isset($_POST['business_intelligence']),
                        'personal_api_key' => isset($_POST['personal_api_key']),
                        'is_custom' => isset($_POST['is_custom']),
                        'is_active' => isset($_POST['is_active']),
                        'is_default' => isset($_POST['is_default']),
                        'features' => $postedFeatureValues(),
                        'payment_modes' => $postedPaymentModes(),
                    ], $userId, $reason);
                    $success = 'Subscription package created.';
                } elseif ($billingAction === 'update_package') {
                    $operations->updateSubscriptionPackage(
                        (int) ($_POST['plan_id'] ?? 0),
                        [
                            'name' => (string) ($_POST['name'] ?? ''),
                            'description' => (string) ($_POST['description'] ?? ''),
                            'display_order' => (int) ($_POST['display_order'] ?? 0),
                            'is_active' => isset($_POST['is_active']),
                            'features' => $postedFeatureValues(),
                        ],
                        $userId,
                        $reason
                    );
                    $success = 'Package updated.';
                } elseif ($billingAction === 'clone_package') {
                    $operations->cloneSubscriptionPackage(
                        (int) ($_POST['plan_id'] ?? 0),
                        (string) ($_POST['new_code'] ?? ''),
                        (string) ($_POST['new_name'] ?? ''),
                        $userId,
                        $reason
                    );
                    $success = 'Package cloned.';
                } elseif ($billingAction === 'delete_package') {
                    $requireDestructiveConfirmation();
                    $deleteResult = $operations->deleteSubscriptionPackage((int) ($_POST['plan_id'] ?? 0), $userId, $reason);
                    $success = !empty($deleteResult['hard_deleted']) ? 'Package deleted.' : 'Package archived because historical references exist.';
                } elseif ($billingAction === 'create_price') {
                    $operations->createSubscriptionPrice(
                        (int) ($_POST['plan_id'] ?? 0),
                        [
                            'price_code' => (string) ($_POST['price_code'] ?? ''),
                            'currency' => (string) ($_POST['currency'] ?? 'KES'),
                            'amount' => (float) ($_POST['amount'] ?? 0),
                            'interval_unit' => (string) ($_POST['interval_unit'] ?? 'monthly'),
                            'interval_count' => (int) ($_POST['interval_count'] ?? 1),
                            'included_credits' => (int) ($_POST['included_credits'] ?? 0),
                            'is_active' => isset($_POST['is_active']),
                            'is_default' => isset($_POST['is_default']),
                            'payment_modes' => $postedPaymentModes(),
                        ],
                        $userId,
                        $reason
                    );
                    $success = 'Package cadence created.';
                } elseif ($billingAction === 'delete_price') {
                    $requireDestructiveConfirmation();
                    $deleteResult = $operations->deleteSubscriptionPrice((int) ($_POST['billing_plan_price_id'] ?? 0), $userId, $reason);
                    $success = !empty($deleteResult['hard_deleted']) ? 'Package cadence deleted.' : 'Package cadence archived because historical references exist.';
                } elseif ($billingAction === 'update_price_payment_methods') {
                    $operations->savePricePaymentMethods((int) ($_POST['billing_plan_price_id'] ?? 0), $postedPaymentModes(), $userId, $reason);
                    $success = 'Package payment methods updated.';
                } elseif ($billingAction === 'save_package_features') {
                    $operations->savePackageFeatureValues((int) ($_POST['plan_id'] ?? 0), $postedFeatureValues(), $userId, $reason);
                    $success = 'Package feature values updated.';
                } elseif ($billingAction === 'save_feature_catalog') {
                    $operations->saveFeatureCatalog([
                        'feature_key' => (string) ($_POST['feature_key'] ?? ''),
                        'label' => (string) ($_POST['label'] ?? ''),
                        'description' => (string) ($_POST['description'] ?? ''),
                        'category' => (string) ($_POST['category'] ?? 'feature'),
                        'value_type' => (string) ($_POST['value_type'] ?? 'boolean'),
                        'default_value' => (string) ($_POST['default_value'] ?? ''),
                        'display_order' => (int) ($_POST['display_order'] ?? 0),
                        'is_active' => isset($_POST['is_active']),
                    ], $userId, $reason);
                    $success = 'Feature catalog saved.';
                } elseif ($billingAction === 'create_pack') {
                    $operations->createTokenPack([
                        'code' => (string) ($_POST['code'] ?? ''),
                        'name' => (string) ($_POST['name'] ?? ''),
                        'description' => (string) ($_POST['description'] ?? ''),
                        'amount' => (float) ($_POST['amount'] ?? 0),
                        'currency' => (string) ($_POST['currency'] ?? 'KES'),
                        'credit_quantity' => (int) ($_POST['credit_quantity'] ?? 0),
                        'credit_expiry_days' => (int) ($_POST['credit_expiry_days'] ?? 180),
                        'sort_order' => (int) ($_POST['sort_order'] ?? 0),
                        'is_active' => isset($_POST['is_active']),
                        'payment_modes' => $postedPaymentModes(),
                    ], $userId, $reason);
                    $success = 'AI Credit pack created.';
                } elseif ($billingAction === 'update_pack') {
                    $operations->updateTokenPackPrice(
                        (int) ($_POST['token_pack_price_id'] ?? 0),
                        (float) ($_POST['amount'] ?? 0),
                        (string) ($_POST['currency'] ?? 'KES'),
                        (int) ($_POST['credit_quantity'] ?? 0),
                        (int) ($_POST['sort_order'] ?? 0),
                        !empty($_POST['is_active']),
                        $userId,
                        $reason
                    );
                    if (isset($_POST['billing_plan_price_id'], $_POST['payment_scope'])) {
                        $operations->savePricePaymentMethods((int) ($_POST['billing_plan_price_id'] ?? 0), $postedPaymentModes(), $userId, $reason);
                    }
                    $success = 'AI Credit pack updated.';
                } elseif ($billingAction === 'delete_pack') {
                    $requireDestructiveConfirmation();
                    $deleteResult = $operations->deleteTokenPack((int) ($_POST['token_pack_price_id'] ?? 0), $userId, $reason);
                    $success = !empty($deleteResult['hard_deleted']) ? 'AI Credit pack deleted.' : 'AI Credit pack archived because historical references exist.';
                } elseif ($billingAction === 'update_payment_methods') {
                    $billingSettings = new WorkspaceBillingSettings();
                    $before = $billingSettings->get();
                    $after = [
                        'payment_card_enabled' => isset($_POST['payment_card_enabled']),
                        'payment_mpesa_enabled' => isset($_POST['payment_mpesa_enabled']),
                        'payment_bank_transfer_enabled' => isset($_POST['payment_bank_transfer_enabled']),
                    ];
                    $billingSettings->save($after, $userId);
                    (new OperatorAuditService())->log(
                        'billing_payment_methods_updated',
                        $userId,
                        null,
                        $reason,
                        [
                            'before' => [
                                'card' => !empty($before['payment_card_enabled']),
                                'mpesa' => !empty($before['payment_mpesa_enabled']),
                                'bank_transfer' => !empty($before['payment_bank_transfer_enabled']),
                            ],
                            'after' => [
                                'card' => $after['payment_card_enabled'],
                                'mpesa' => $after['payment_mpesa_enabled'],
                                'bank_transfer' => $after['payment_bank_transfer_enabled'],
                            ],
                        ]
                    );
                    $success = 'Payment method availability updated.';
                } elseif ($billingAction === 'update_package_negotiation_meeting_settings') {
                    $before = (new PackageNegotiationMeetingService())->setupState();
                    $packageNegotiationMeetingState = (new PackageNegotiationMeetingService())->setAutoApproval(
                        isset($_POST['package_meeting_auto_approval_enabled']),
                        $userId
                    );
                    (new OperatorAuditService())->log(
                        'package_negotiation_meeting_settings_updated',
                        $userId,
                        null,
                        $reason,
                        [
                            'before' => [
                                'auto_approval_enabled' => !empty($before['auto_approval_enabled']),
                                'profile_id' => (int) (($before['profile']['id'] ?? 0)),
                            ],
                            'after' => [
                                'auto_approval_enabled' => !empty($packageNegotiationMeetingState['auto_approval_enabled']),
                                'profile_id' => (int) (($packageNegotiationMeetingState['profile']['id'] ?? 0)),
                            ],
                        ]
                    );
                    $success = 'Negotiated package meeting settings updated.';
                } else {
                    throw new \RuntimeException('Unsupported package settings action.');
                }

                $packageSettingsCatalog = $operations->listBillingCatalogForOperators($packageAnalyticsFilters, $packageNegotiatedFilters);
                $packageSettingsPaymentModes = (new WorkspaceBillingSettings())->get();
                $packageNegotiationMeetingState = $packageNegotiationMeetingState ?? (new PackageNegotiationMeetingService())->setupState();
                $settings = [];
                $skipEnvWrite = true;
            } elseif ($tab === 'workflow_automation') {
                $workflowAutomationService = new WorkflowAutomationControlService();
                if ($workflowAutomationService->isManagedByAutoAdmin()) {
                    throw new \RuntimeException('Auto Admin is managing this workspace settings section right now. Turn it off first if you need manual control.');
                }
                $workflowAutomationService->saveWorkspaceControl([
                    'autonomy_mode' => (string) ($_POST['workflow_automation_mode'] ?? 'suggest_only'),
                    'promotion_status' => (string) ($_POST['workflow_automation_promotion_status'] ?? ($_POST['workflow_automation_mode'] ?? 'suggest_only')),
                    'demonstration_capture_enabled' => isset($_POST['workflow_automation_demonstration_capture_enabled']),
                    'policy_learning_enabled' => isset($_POST['workflow_automation_policy_learning_enabled']),
                    'review_ui_enabled' => isset($_POST['workflow_automation_review_ui_enabled']),
                    'fast_promotion_enabled' => isset($_POST['workflow_automation_fast_promotion_enabled']),
                    'auto_downgrade_on_drift' => isset($_POST['workflow_automation_auto_downgrade_on_drift']),
                    'metadata' => [
                        'allowed_actions' => array_values(array_filter(array_map('trim', explode(',', (string) ($_POST['workflow_automation_allowed_actions'] ?? ''))))),
                        'require_human_checkpoint_actions' => array_values(array_filter(array_map('trim', explode(',', (string) ($_POST['workflow_automation_checkpoint_actions'] ?? ''))))),
                        'max_daily_auto_actions' => (int) ($_POST['workflow_automation_max_daily_auto_actions'] ?? 50),
                        'max_customer_facing_risk' => (float) ($_POST['workflow_automation_max_customer_facing_risk'] ?? 0.95),
                        'block_customer_facing_full_auto' => isset($_POST['workflow_automation_block_customer_facing_full_auto']),
                        'approval_required_for_promotion' => isset($_POST['workflow_automation_approval_required_for_promotion']),
                    ],
                ], $userId);
                $workflowAutomationState = $workflowAutomationService->buildSettingsState();
                $workflowAutomationPendingApprovals = (new WorkflowAutomationProposalService())->listAll(['status' => 'pending', 'limit' => 20]);
                $workflowAutomationMetrics = (new WorkflowAutomationProposalService())->buildSummaryMetrics($workflowAutomationPendingApprovals);
                $settings = [];
            } elseif ($tab === 'commercial_automation') {
                (new CommercialAutomationConfig())->save([
                    'enabled' => isset($_POST['commercial_automation_enabled']),
                    'mode' => $_POST['commercial_automation_mode'] ?? 'auto_safe',
                    'stage_entry_enabled' => isset($_POST['commercial_stage_entry_enabled']),
                    'negotiation_revisions_enabled' => isset($_POST['commercial_negotiation_revisions_enabled']),
                    'auto_send_enabled' => isset($_POST['commercial_auto_send_enabled']),
                    'auto_convert_on_won_enabled' => isset($_POST['commercial_auto_convert_on_won_enabled']),
                    'auto_mark_overdue_enabled' => isset($_POST['commercial_auto_mark_overdue_enabled']),
                    'followup_reminders_enabled' => isset($_POST['commercial_followup_reminders_enabled']),
                    'send_delay_minutes' => (int) ($_POST['commercial_send_delay_minutes'] ?? 0),
                    'max_auto_discount_percent' => (float) ($_POST['commercial_max_auto_discount_percent'] ?? 20),
                    'max_auto_total_change_percent' => (float) ($_POST['commercial_max_auto_total_change_percent'] ?? 25),
                    'max_revision_count_before_approval' => (int) ($_POST['commercial_max_revision_count_before_approval'] ?? 2),
                    'require_recipient_for_send' => isset($_POST['commercial_require_recipient_for_send']),
                    'require_nonzero_total_for_send' => isset($_POST['commercial_require_nonzero_total_for_send']),
                    'require_billing_identity_for_final_invoice' => isset($_POST['commercial_require_billing_identity_for_final_invoice']),
                    'auto_convert_requires_status' => $_POST['commercial_auto_convert_requires_status'] ?? 'accepted',
                    'negotiation_stale_hours' => (int) ($_POST['commercial_negotiation_stale_hours'] ?? 48),
                    'proposal_followup_hours' => (int) ($_POST['commercial_proposal_followup_hours'] ?? 24),
                    'delivery_retry_limit' => (int) ($_POST['commercial_delivery_retry_limit'] ?? 2),
                    'delivery_retry_backoff_minutes' => (int) ($_POST['commercial_delivery_retry_backoff_minutes'] ?? 30),
                    'resend_cooldown_minutes' => (int) ($_POST['commercial_resend_cooldown_minutes'] ?? 180),
                    'task_owner_mode' => $_POST['commercial_task_owner_mode'] ?? 'deal_owner',
                    'default_document_by_stage' => [
                        'proposal' => $_POST['commercial_default_doc_proposal'] ?? 'quote',
                        'negotiation' => $_POST['commercial_default_doc_negotiation'] ?? 'quote',
                        'closed_won' => $_POST['commercial_default_doc_closed_won'] ?? 'invoice',
                    ],
                    'send_channels' => [
                        'email' => isset($_POST['commercial_send_channel_email']),
                        'whatsapp' => isset($_POST['commercial_send_channel_whatsapp']),
                    ],
                    'action_confidence_thresholds' => [
                        'create_draft' => (float) ($_POST['commercial_threshold_create_draft'] ?? 0.82),
                        'revise_document' => (float) ($_POST['commercial_threshold_revise_document'] ?? 0.86),
                        'send_document' => (float) ($_POST['commercial_threshold_send_document'] ?? 0.88),
                        'resend_document' => (float) ($_POST['commercial_threshold_resend_document'] ?? 0.90),
                        'convert_to_invoice' => (float) ($_POST['commercial_threshold_convert_to_invoice'] ?? 0.90),
                        'finalize_invoice' => (float) ($_POST['commercial_threshold_finalize_invoice'] ?? 0.93),
                        'mark_paid' => (float) ($_POST['commercial_threshold_mark_paid'] ?? 0.96),
                    ],
                ], $activeWorkspaceId > 0 ? $activeWorkspaceId : null);
                $commercialAutomationConfig = (new CommercialAutomationConfig())->get($activeWorkspaceId > 0 ? $activeWorkspaceId : null);
                $settings = [];
            } elseif ($tab === 'ai_autoresponder') {
                $config = new AIAutoResponderConfig();
                $quietHours = [
                    'enabled' => isset($_POST['ai_auto_quiet_hours_enabled']),
                    'start' => $_POST['ai_auto_quiet_start'] ?? '20:00',
                    'end' => $_POST['ai_auto_quiet_end'] ?? '08:00',
                    'timezone' => $_POST['ai_auto_quiet_timezone'] ?? 'UTC',
                ];
                if ($canManageGlobalAiAutoResponder) {
                    $channels = [
                        'email' => [
                            'enabled' => isset($_POST['ai_auto_email_enabled']),
                            'confidence_threshold' => (float) ($_POST['ai_auto_email_confidence'] ?? 0.88),
                            'max_chars' => (int) ($_POST['ai_auto_email_max_chars'] ?? 4000),
                            'draft_length_band' => (string) ($_POST['ai_auto_email_length_band'] ?? 'balanced'),
                            'draft_fullness' => (string) ($_POST['ai_auto_email_fullness'] ?? 'balanced'),
                            'draft_include_clear_cta' => isset($_POST['ai_auto_email_include_cta']),
                        ],
                        'whatsapp' => [
                            'enabled' => isset($_POST['ai_auto_whatsapp_enabled']),
                            'confidence_threshold' => (float) ($_POST['ai_auto_whatsapp_confidence'] ?? 0.90),
                            'max_chars' => (int) ($_POST['ai_auto_whatsapp_max_chars'] ?? 700),
                            'draft_length_band' => (string) ($_POST['ai_auto_whatsapp_length_band'] ?? 'short'),
                            'draft_fullness' => (string) ($_POST['ai_auto_whatsapp_fullness'] ?? 'concise'),
                            'draft_include_clear_cta' => isset($_POST['ai_auto_whatsapp_include_cta']),
                        ],
                        'sms' => [
                            'enabled' => isset($_POST['ai_auto_sms_enabled']),
                            'confidence_threshold' => (float) ($_POST['ai_auto_sms_confidence'] ?? 0.92),
                            'max_chars' => (int) ($_POST['ai_auto_sms_max_chars'] ?? 320),
                            'draft_length_band' => (string) ($_POST['ai_auto_sms_length_band'] ?? 'short'),
                            'draft_fullness' => (string) ($_POST['ai_auto_sms_fullness'] ?? 'concise'),
                            'draft_include_clear_cta' => isset($_POST['ai_auto_sms_include_cta']),
                        ],
                    ];
                    $config->save([
                        'enabled' => isset($_POST['ai_auto_enabled']),
                        'mode' => $_POST['ai_auto_mode'] ?? 'draft_only',
                        'default_confidence_threshold' => (float) ($_POST['ai_auto_default_confidence'] ?? 0.85),
                        'channels' => $channels,
                        'safety' => [
                            'escalation_keywords' => $_POST['ai_auto_escalation_keywords'] ?? '',
                            'opt_out_keywords' => $_POST['ai_auto_optout_keywords'] ?? '',
                            'max_auto_replies_per_contact_per_day' => (int) ($_POST['ai_auto_max_replies_per_day'] ?? 5),
                            'forbid_hallucinations' => isset($_POST['ai_auto_forbid_hallucinations']),
                            'require_human_for_sensitive_intents' => isset($_POST['ai_auto_require_human_sensitive']),
                        ],
                    ], $activeWorkspaceId > 0 ? $activeWorkspaceId : null);
                }
                if ($canManageWorkspaceAiQuietHours) {
                    (new WorkspaceAIAutoResponderQuietHoursService())->save($activeWorkspaceId, $quietHours, $userId);
                }
                $aiAutoResponderConfig = $config->get($activeWorkspaceId);
                $settings = [];
            } elseif ($tab === 'sms') {
                $settings = [];
                $skipEnvWrite = true;
                $success = 'SMS setup is now workspace-scoped. Open the workspace Marketplace SMS Channel setup to save Twilio credentials.';
            } else {
                $settings = [];
            }
            
            // Update .env file
            $appliedSettings = [];
            foreach ($settings as $key => $value) {
                // For secrets/tokens, don't overwrite if empty (preserve existing values)
                if (in_array($key, ['CLEARBIT_API_KEY', 'PDL_API_KEY', 'HUNTER_API_KEY', 'WHATSAPP_ACCESS_TOKEN', 'WHATSAPP_VERIFY_TOKEN', 'META_APP_SECRET', 'META_APP_ACCESS_TOKEN', 'GOOGLE_CALENDAR_CLIENT_SECRET', 'MICROSOFT_CALENDAR_CLIENT_SECRET', 'GMAIL_MAIL_CLIENT_SECRET', 'GOOGLE_WORKSPACE_MAIL_CLIENT_SECRET', 'GMAIL_ASSISTANT_MAIL_CLIENT_SECRET', 'GOOGLE_GMAIL_SEND_CLIENT_SECRET', 'GOOGLE_GMAIL_INBOX_CLIENT_SECRET', 'GOOGLE_ASSISTANT_GMAIL_CLIENT_SECRET', 'EMAIL_ASSISTANT_SMTP_PASS', 'EMAIL_ASSISTANT_IMAP_PASS']) && empty($value)) {
                    continue; // Skip empty secrets to preserve existing values
                }
                
                $pattern = '/^' . preg_quote($key, '/') . '=.*$/m';
                if (preg_match($pattern, $envContent)) {
                    $envContent = preg_replace($pattern, $key . '=' . $value, $envContent);
                } else {
                    // Add new setting
                    $envContent .= "\n$key=$value";
                }
                $appliedSettings[$key] = $value;
            }
            
            if (empty($error) && !$skipEnvWrite) {
                file_put_contents($envFile, $envContent);
                // Reload environment
                foreach ($appliedSettings as $key => $value) {
                    $_ENV[$key] = $value;
                }
                if ($tab === 'email') {
                    $gmailMailService = new GmailMailService();
                    $googleWorkspaceMailService = new GoogleWorkspaceMailService();
                    $mainEmailProviderSummary = $emailIntegrationService->getMainProviderSummary();
                    $activeGmailIntegration = $emailIntegrationService->getActiveGmailIntegration();
                    $activeGoogleWorkspaceIntegration = $emailIntegrationService->getActiveGoogleWorkspaceIntegration();
                    $gmailMailConnectedEmail = trim((string) (($activeGmailIntegration['email_address'] ?? '')));
                    $googleWorkspaceMailConnectedEmail = trim((string) ($mainEmailProviderSummary['connected_email'] ?? ''));
                    $googleWorkspaceMailConnectedEmail = $googleWorkspaceMailConnectedEmail !== ''
                        ? $googleWorkspaceMailConnectedEmail
                        : trim((string) (($activeGoogleWorkspaceIntegration['email_address'] ?? '')));
                    $gmailMailConfigured = $gmailMailService->isConfigured();
                    $googleWorkspaceMailConfigured = $googleWorkspaceMailService->isConfigured();
                    $gmailMailRedirectUri = $gmailMailService->getRedirectUri();
                    $googleWorkspaceMailRedirectUri = $googleWorkspaceMailService->getRedirectUri();
                }
	                if ($tab === 'email_assistant' && $assistantWhatsAppRowsToSave !== null && settingsTableExists('whatsapp_assistant_authorized_numbers')) {
	                    $assistantWhatsappHasWorkspace = Database::columnExists('whatsapp_assistant_authorized_numbers', 'workspace_id');
	                    if ($assistantWhatsappHasWorkspace && $activeWorkspaceId > 0) {
	                        Database::execute("DELETE FROM whatsapp_assistant_authorized_numbers WHERE workspace_id = ?", [$activeWorkspaceId]);
	                    } else {
	                        Database::execute("DELETE FROM whatsapp_assistant_authorized_numbers");
	                    }
	                    foreach ($assistantWhatsAppRowsToSave as $row) {
	                        $columns = ['phone_number', 'user_id', 'label', 'notes', 'is_active', 'digest_enabled', 'created_at', 'updated_at'];
	                        $placeholders = ['?', '?', '?', '?', '?', '?', 'NOW()', 'NOW()'];
	                        $params = [
	                            $row['phone_number'],
	                            $row['user_id'],
	                            $row['label'] !== '' ? $row['label'] : null,
	                            $row['notes'] !== '' ? $row['notes'] : null,
	                            $row['is_active'],
	                            $row['digest_enabled'],
	                        ];
	                        if ($assistantWhatsappHasWorkspace) {
	                            array_unshift($columns, 'workspace_id');
	                            array_unshift($placeholders, '?');
	                            array_unshift($params, $activeWorkspaceId > 0 ? $activeWorkspaceId : null);
	                        }
	                        Database::execute(
	                            "INSERT INTO whatsapp_assistant_authorized_numbers (" . implode(', ', $columns) . ")
	                             VALUES (" . implode(', ', $placeholders) . ")",
	                            $params
	                        );
	                    }
	                    $workspaceSql = '';
	                    $workspaceParams = [];
	                    if ($assistantWhatsappHasWorkspace && $activeWorkspaceId > 0) {
	                        $workspaceSql = 'WHERE workspace_id = ?';
	                        $workspaceParams[] = $activeWorkspaceId;
	                    }
	                    $assistantWhatsAppMappings = Database::query(
	                        "SELECT *
	                         FROM whatsapp_assistant_authorized_numbers
	                         {$workspaceSql}
	                         ORDER BY is_active DESC, label ASC, phone_number ASC",
	                        $workspaceParams
	                    );
	                }
                if ($tab === 'email_assistant') {
	                    $assistantWhatsAppDiagnostics = (new WhatsAppAssistantConfig())->validate();
	                    $assistantWhatsAppDigestDiagnostics = (new WhatsAppAssistantDigestService())->validateDigestConfig();
	                    if (settingsTableExists('whatsapp_assistant_messages')) {
	                        $workspaceSql = '';
	                        $workspaceParams = [];
	                        if ($activeWorkspaceId > 0 && Database::columnExists('whatsapp_assistant_messages', 'workspace_id')) {
	                            $workspaceSql = 'WHERE wam.workspace_id = ?';
	                            $workspaceParams[] = $activeWorkspaceId;
	                        }
	                        $assistantWhatsAppRecentMessages = Database::query(
	                            "SELECT wam.*, u.first_name, u.last_name, u.email
	                             FROM whatsapp_assistant_messages wam
	                             LEFT JOIN users u ON u.id = wam.user_id
	                             {$workspaceSql}
	                             ORDER BY wam.created_at DESC, wam.id DESC
	                             LIMIT 10",
	                            $workspaceParams
	                        );
	                    }
	                    if (settingsTableExists('whatsapp_assistant_digest_log')) {
	                        $workspaceSql = '';
	                        $workspaceParams = [];
	                        if ($activeWorkspaceId > 0 && Database::columnExists('whatsapp_assistant_digest_log', 'workspace_id')) {
	                            $workspaceSql = 'WHERE wdl.workspace_id = ?';
	                            $workspaceParams[] = $activeWorkspaceId;
	                        }
	                        $assistantWhatsAppRecentDigests = Database::query(
	                            "SELECT wdl.*, u.first_name, u.last_name, u.email
	                             FROM whatsapp_assistant_digest_log wdl
	                             LEFT JOIN users u ON u.id = wdl.user_id
	                             {$workspaceSql}
	                             ORDER BY wdl.sent_at DESC, wdl.id DESC
	                             LIMIT 10",
	                            $workspaceParams
	                        );
	                    }
                    if (settingsTableExists('whatsapp_assistant_sessions')) {
                        $assistantWhatsAppSessionDiagnostics = (new \CRM\Services\WhatsAppAssistantSessionService())->getSessionDiagnostics();
                    }
	                    if (settingsTableExists('whatsapp_assistant_keepalive_log')) {
	                        $workspaceSql = '';
	                        $workspaceParams = [];
	                        if ($activeWorkspaceId > 0 && Database::columnExists('whatsapp_assistant_keepalive_log', 'workspace_id')) {
	                            $workspaceSql = 'WHERE wkl.workspace_id = ?';
	                            $workspaceParams[] = $activeWorkspaceId;
	                        }
	                        $assistantWhatsAppRecentKeepaliveEvents = Database::query(
	                            "SELECT wkl.*, u.first_name, u.last_name, u.email
	                             FROM whatsapp_assistant_keepalive_log wkl
	                             LEFT JOIN users u ON u.id = wkl.user_id
	                             {$workspaceSql}
	                             ORDER BY wkl.created_at DESC, wkl.id DESC
	                             LIMIT 12",
	                            $workspaceParams
	                        );
	                    }
                }
                if (empty($success)) {
                    $success = 'Settings saved successfully!';
                }
                $workspaceConnectState = $workspaceConnectService->buildHubState($user, $activeWorkspaceId);
            }
            $emailColdOutreachWarmup = $coldOutreachWarmupConfig->get('email');
            $whatsappColdOutreachWarmup = $coldOutreachWarmupConfig->get('whatsapp');
            $emailColdOutreachUsage = $coldOutreachWarmupConfig->getUsageSummary('email');
            $whatsappColdOutreachUsage = $coldOutreachWarmupConfig->getUsageSummary('whatsapp');
            $activeTab = $tab;
        } catch (WorkspaceLaunchThrottleException $e) {
            http_response_code(429);
            header('Retry-After: ' . $e->retryAfter());
            $error = $e->getMessage();
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }
    }
}

$mainEmailProviderSummary = $emailIntegrationService->getMainProviderSummary();
$outreachEmailProviderSummary = $emailIntegrationService->getProviderSummaryForRole('outreach', $activeWorkspaceId > 0 ? $activeWorkspaceId : null);
$nurtureEmailProviderSummary = $emailIntegrationService->getProviderSummaryForRole('nurture', $activeWorkspaceId > 0 ? $activeWorkspaceId : null);
$assistantEmailProviderSummary = $emailIntegrationService->getAssistantProviderSummary($activeWorkspaceId > 0 ? $activeWorkspaceId : null);
$systemMailReadiness = (string) ($mainEmailProviderSummary['readiness'] ?? 'blocked');
$systemMailOutboundReady = in_array($systemMailReadiness, ['ready', 'warning'], true);
$strictOutreachOutboundReady = $emailIntegrationService->isStrictRoleOutboundReady('outreach', $activeWorkspaceId > 0 ? $activeWorkspaceId : null);
$strictNurtureOutboundReady = $emailIntegrationService->isStrictRoleOutboundReady('nurture', $activeWorkspaceId > 0 ? $activeWorkspaceId : null);
$workspaceEmailChannelHealth = $activeWorkspaceId > 0
    ? (new WorkspaceChannelHealthService())->summarize($activeWorkspaceId, $user)
    : [];
$legacySystemMailEnvKeys = array_values(array_filter(
    ['SMTP_HOST', 'SMTP_PORT', 'SMTP_USER', 'SMTP_FROM_EMAIL', 'SMTP_FROM_NAME', 'IMAP_HOST', 'IMAP_USER'],
    static fn(string $key): bool => trim((string) ($_ENV[$key] ?? '')) !== ''
));
$legacyAssistantMailEnvKeys = array_values(array_filter(
    ['EMAIL_ASSISTANT_SYSTEM_EMAIL', 'EMAIL_ASSISTANT_SMTP_HOST', 'EMAIL_ASSISTANT_SMTP_USER', 'EMAIL_ASSISTANT_FROM_EMAIL', 'EMAIL_ASSISTANT_IMAP_HOST', 'EMAIL_ASSISTANT_IMAP_USER'],
    static fn(string $key): bool => trim((string) ($_ENV[$key] ?? '')) !== ''
));
$showCopyOutreachToSystem = $isSuperAdmin && !$systemMailOutboundReady && $strictOutreachOutboundReady;
$googleServicesSummary = $isSuperAdmin ? $googleServicesSetupService->summary() : [];
$requestedWhatsAppSetupTab = strtolower(trim(preg_replace('/[^a-z0-9_]+/', '_', (string) ($_GET['setup_tab'] ?? '')) ?? ''));
$requestedWhatsAppSetupModule = strtolower(trim(preg_replace('/[^a-z0-9_]+/', '_', (string) ($_GET['setup_module'] ?? '')) ?? ''));
$whatsAppSettingsTabs = [
    'overview' => 'Overview',
    'platform' => 'Platform',
    'connection' => 'Connection',
    'assistant' => 'Assistant',
    'guardrails' => 'Guardrails',
    'activity' => 'Activity',
];
$whatsAppChannelSetupTabs = ['manual', 'webhook', 'templates', 'billing', 'migration', 'embedded_signup', 'tests', 'activity'];
$whatsAppAssistantSetupTabs = ['identity', 'session', 'tests', 'activity', 'advanced'];
$activeWhatsAppSettingsTab = strtolower(trim(preg_replace('/[^a-z0-9_]+/', '_', (string) ($_GET['whatsapp_tab'] ?? '')) ?? ''));
if ($activeWhatsAppSettingsTab === '') {
    if ($requestedWhatsAppSetupModule === 'whatsapp') {
        $activeWhatsAppSettingsTab = 'connection';
    } elseif ($requestedWhatsAppSetupModule === 'whatsapp_assistant') {
        $activeWhatsAppSettingsTab = 'assistant';
    } elseif (in_array($requestedWhatsAppSetupTab, $whatsAppChannelSetupTabs, true)) {
        $activeWhatsAppSettingsTab = 'connection';
    } elseif (in_array($requestedWhatsAppSetupTab, $whatsAppAssistantSetupTabs, true)) {
        $activeWhatsAppSettingsTab = 'assistant';
    } else {
        $activeWhatsAppSettingsTab = 'overview';
    }
}
if (!isset($whatsAppSettingsTabs[$activeWhatsAppSettingsTab])) {
    $activeWhatsAppSettingsTab = 'overview';
}
$whatsAppHubContext = $activeWorkspaceId > 0
    ? $whatsAppSettingsHub->buildWhatsAppHubContext($activeWorkspaceId, $user, $requestedWhatsAppSetupTab, 'settings')
    : [];
$marketplaceCssUrl = function_exists('assetUrl') ? assetUrl('css/marketplace.css') : 'assets/css/marketplace.css';
$marketplaceCssPath = __DIR__ . '/assets/css/marketplace.css';
if (is_file($marketplaceCssPath)) {
    $marketplaceCssUrl .= (strpos($marketplaceCssUrl, '?') === false ? '?' : '&') . 'v=' . rawurlencode((string) @filemtime($marketplaceCssPath));
}

$pageTitle = 'Settings - ' . brandProductName();
$autoAdminManagedAttr = $autoAdminEnabled ? 'disabled' : '';
$showSettingsTab = static function (string $tabKey) use ($canTab): bool {
    if (empty($canTab[$tabKey])) {
        return false;
    }

    return true;
};
$settingsTabGroups = [
    'Core' => [
        'general' => 'General',
        'page_videos' => 'Videos',
        'company' => 'Company Profile',
        'products' => 'Products & Services',
        'voice' => 'Voice',
        'invoicing' => 'Invoicing',
        'billing' => 'Billing',
        'workspace_governance' => 'Workspace Team',
    ],
    'Channels' => [
        'email' => 'Email',
        'whatsapp' => 'WhatsApp',
        'sms' => 'SMS',
        'calendar' => 'Calendar',
    ],
    'AI / Automation' => [
        'ai' => 'AI Services',
        'email_assistant' => 'Email Assistant',
        'ai_autoresponder' => 'AI Auto-Responder',
        'meeting_bot' => 'Meeting Bot',
        'meeting_note_taker' => 'Meeting Note Taker',
        'workflow_automation' => 'Workflow Automation',
        'commercial_automation' => 'Commercial Automation',
        'deal_automation' => 'Deal Automation',
        'monitoring' => 'Monitoring',
    ],
    'Advanced' => [
        'package_settings' => 'Package Settings',
        'platform_admin' => 'Platform Admin',
        'google_services' => 'Google Services',
        'enrichment' => 'Data Enrichment',
        'scoring' => 'Scoring',
    ],
];
$activeSettingsTabLabel = ucwords(str_replace('_', ' ', (string) $activeTab));
$activeSettingsTabGroup = 'Settings';
foreach ($settingsTabGroups as $groupLabel => $groupTabs) {
    if (array_key_exists($activeTab, $groupTabs)) {
        $activeSettingsTabGroup = (string) $groupLabel;
        $activeSettingsTabLabel = (string) ($groupTabs[$activeTab] ?? $activeSettingsTabLabel);
        break;
    }
}
$activeSettingsTabManaged = $autoAdminEnabled && $autoAdminService->isManagedTab((string) $activeTab);
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<link rel="stylesheet" href="assets/css/settings-ui.css?v=20260718-mobile-content-card-sizing">
<link rel="stylesheet" href="<?php echo htmlspecialchars($marketplaceCssUrl); ?>">

<div class="page-premium settings-page">
    <div class="container">
        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h1>Settings</h1>
                <p>Configure your Clarity CRM workspace for service-led onboarding and delivery.</p>
            </div>
</div>

<?php if ($error): ?>
            <div class="content-card settings-alert settings-alert--error">
        <?php echo htmlspecialchars($error); ?>
    </div>
<?php endif; ?>

<?php if ($success): ?>
            <div class="content-card settings-alert settings-alert--success">
        <?php echo htmlspecialchars($success); ?>
    </div>
<?php endif; ?>

<?php if ($autoAdminNotice): ?>
            <div class="content-card settings-alert settings-alert--info">
        <?php echo htmlspecialchars($autoAdminNotice); ?>
    </div>
<?php endif; ?>

<?php if (false): ?>
        <div class="content-card" style="margin-bottom: 1rem; border-color: <?php echo $autoAdminEnabled ? '#10b981' : '#cbd5f5'; ?>; background: <?php echo $autoAdminEnabled ? '#ecfdf5' : '#f8fafc'; ?>;">
            <div style="display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;flex-wrap:wrap;">
                <div style="max-width: 760px;">
                    <div style="font-size:0.75rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:<?php echo $autoAdminEnabled ? '#047857' : '#4f46e5'; ?>;">Auto Admin</div>
                    <h2 style="margin:.35rem 0 .5rem;color:#0f172a;font-size:1.15rem;"><?php echo $autoAdminEnabled ? 'Managed AI and automation mode is on' : 'Manual AI and automation control is on'; ?></h2>
                    <p style="margin:0;color:#475569;font-size:.92rem;line-height:1.6;">
                        <?php echo $autoAdminEnabled
                            ? 'Managed tabs remain visible for review, but their manual controls are locked for this workspace. Company profile, products, invoicing, email, WhatsApp, calendar, SMS, and diagnostics remain available.'
                            : 'Turn this on to apply safe AI and automation defaults for this workspace, badge the managed tabs, and keep business-specific setup editable.'; ?>
                    </p>
                </div>
                <form method="POST" action="" style="display:flex;flex-direction:column;gap:.75rem;min-width:260px;">
                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                    <input type="hidden" name="tab" value="general">
                    <input type="hidden" name="auto_admin_toggle_submitted" value="1">
                    <label style="display:flex;align-items:center;gap:.75rem;padding:.85rem 1rem;border:1px solid <?php echo $autoAdminEnabled ? '#6ee7b7' : '#cbd5e1'; ?>;border-radius:14px;background:#fff;cursor:pointer;">
                        <input type="checkbox" name="auto_admin_enabled" value="1" <?php echo $autoAdminEnabled ? 'checked' : ''; ?> style="width:1.1rem;height:1.1rem;">
                        <span style="display:flex;flex-direction:column;gap:.15rem;">
                            <span style="font-weight:600;color:#0f172a;"><?php echo $autoAdminEnabled ? 'Auto Admin manages this workspace setup' : 'Manual admin control'; ?></span>
                            <span style="font-size:.8rem;color:#64748b;"><?php echo $autoAdminEnabled ? 'Managed defaults are currently enforced for this workspace.' : 'Detailed AI and automation tabs stay editable.'; ?></span>
                        </span>
                    </label>
                    <div style="padding:0 0.1rem;color:#475569;font-size:.82rem;line-height:1.6;">
                        <div><strong>Checkbox checked:</strong> the system applies safe AI and automation defaults, hides the detailed automation admin tabs, blocks manual changes to those managed sections, and keeps only business-specific setup editable.</div>
                        <div><strong>Checkbox unchecked:</strong> all AI and automation admin tabs stay available and editable manually.</div>
                        <div><strong>Button action:</strong> saves the checkbox state as the live managed mode for this workspace.</div>
                    </div>
                    <button type="submit" class="btn-premium-primary" style="align-self:flex-start;"><?php echo $autoAdminEnabled ? 'Keep Auto Admin On' : 'Enable Auto Admin'; ?></button>
                    <div style="color:#64748b;font-size:.78rem;line-height:1.5;">
                        <?php echo $autoAdminEnabled
                            ? 'To turn Auto Admin off, uncheck the box first, then click the button to save the new mode.'
                            : 'To turn Auto Admin on, leave the box checked, then click the button to apply managed mode.'; ?>
                    </div>
                </form>
            </div>
        </div>

        <div class="content-card" style="margin-bottom: 1rem; border-color:#c7d2fe;background:#f8fafc;">
            <div style="display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;flex-wrap:wrap;">
                <div style="max-width:760px;">
                    <div style="font-size:0.75rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#4338ca;">Cold Outreach Warmup</div>
                    <h2 style="margin:.35rem 0 .5rem;color:#0f172a;font-size:1.05rem;">Daily brand-protection limits for first-time outreach</h2>
                    <p style="margin:0;color:#475569;font-size:.92rem;line-height:1.6;">
                        Admin-set starting caps live on the Email and WhatsApp tabs. Auto Admin can gradually increase the current daily cap when channel health stays acceptable.
                    </p>
                </div>
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:1rem;margin-top:1rem;">
                <?php foreach ([
                    'email' => $emailColdOutreachWarmup,
                    'whatsapp' => $whatsappColdOutreachWarmup,
                ] as $channelKey => $channelConfig): ?>
                    <?php
                    $channelUsage = $channelKey === 'email' ? $emailColdOutreachUsage : $whatsappColdOutreachUsage;
                    $nextIncreaseAt = !empty($channelConfig['last_auto_adjusted_at'])
                        ? date('M j, Y', strtotime((string) $channelConfig['last_auto_adjusted_at'] . ' +7 days'))
                        : 'Any time after enough healthy send history exists';
                    ?>
                    <div style="padding:1rem;border:1px solid #dbeafe;border-radius:14px;background:#fff;">
                        <div style="display:flex;justify-content:space-between;gap:.75rem;align-items:flex-start;">
                            <div>
                                <div style="font-weight:700;color:#0f172a;text-transform:capitalize;"><?php echo htmlspecialchars($channelKey); ?></div>
                                <div style="font-size:.82rem;color:#64748b;margin-top:.2rem;">
                                    Auto Admin warmup <?php echo !empty($channelConfig['auto_admin_warmup_enabled']) ? 'enabled' : 'disabled'; ?>
                                </div>
                            </div>
                            <span style="display:inline-block;padding:4px 8px;border-radius:999px;background:<?php echo !empty($channelConfig['enabled']) ? '#dcfce7' : '#e2e8f0'; ?>;color:<?php echo !empty($channelConfig['enabled']) ? '#166534' : '#475569'; ?>;font-size:12px;font-weight:700;">
                                <?php echo !empty($channelConfig['enabled']) ? 'Active' : 'Off'; ?>
                            </span>
                        </div>
                        <div style="display:grid;gap:.35rem;margin-top:.8rem;color:#334155;font-size:.88rem;">
                            <div>Start cap: <strong><?php echo (int) ($channelConfig['initial_daily_cold_limit'] ?? 0); ?>/day</strong></div>
                            <div>Current cap: <strong><?php echo (int) ($channelConfig['current_daily_cold_limit'] ?? 0); ?>/day</strong></div>
                            <div>Weekly increment: <strong>+<?php echo (int) ($channelConfig['weekly_increment'] ?? 0); ?></strong></div>
                            <div>Max cap: <strong><?php echo (int) ($channelConfig['max_limit'] ?? 0); ?>/day</strong></div>
                            <div>Used or reserved today: <strong><?php echo (int) ($channelUsage['reserved_or_sent'] ?? 0); ?></strong></div>
                            <div>Next eligible increase: <strong><?php echo htmlspecialchars($nextIncreaseAt); ?></strong></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

<?php endif; ?>

<!-- Tabs -->
        <div class="settings-main-layout">
            <aside class="table-card settings-nav-card">
                <div class="settings-nav-shell" data-settings-nav-shell>
                <div class="settings-nav-summary">
                    <div class="settings-nav-summary-copy">
                        <div class="settings-nav-summary-kicker"><?php echo htmlspecialchars($activeSettingsTabGroup); ?></div>
                        <div class="settings-nav-summary-title">
                            <span><?php echo htmlspecialchars((string) $activeSettingsTabLabel); ?></span>
                            <?php if ($activeSettingsTabManaged): ?><span class="settings-nav-badge">Managed</span><?php endif; ?>
                        </div>
                    </div>
                    <button type="button" class="settings-nav-toggle" data-settings-nav-toggle aria-expanded="false" aria-controls="settings-nav-grid">
                        <span>Change section</span>
                        <span class="settings-nav-toggle-icon" aria-hidden="true">&#9662;</span>
                    </button>
                </div>
                <div class="settings-nav-grid" id="settings-nav-grid">
                    <?php foreach ($settingsTabGroups as $groupLabel => $groupTabs): ?>
                        <?php
                        $visibleGroupTabs = array_filter(
                            $groupTabs,
                            static fn(string $label, string $tabKey): bool => $showSettingsTab($tabKey),
                            ARRAY_FILTER_USE_BOTH
                        );
                        ?>
                        <?php if (!empty($visibleGroupTabs)): ?>
                            <div class="settings-nav-group">
                                <div class="settings-nav-group-title"><?php echo htmlspecialchars((string) $groupLabel); ?></div>
                                <div class="settings-nav-links">
                                    <?php foreach ($visibleGroupTabs as $tabKey => $tabLabel): ?>
                                        <?php $isManagedTab = $autoAdminEnabled && $autoAdminService->isManagedTab((string) $tabKey); ?>
                                        <a href="?tab=<?php echo urlencode((string) $tabKey); ?>" class="settings-nav-link <?php echo $activeTab === $tabKey ? 'is-active' : ''; ?>">
                                            <span><?php echo htmlspecialchars((string) $tabLabel); ?></span>
                                            <?php if ($isManagedTab): ?><span class="settings-nav-badge">Managed</span><?php endif; ?>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <?php if (Authorization::can('settings.general', $user)): ?>
                        <div class="settings-nav-group">
                            <div class="settings-nav-group-title">Utilities</div>
                            <div class="settings-nav-links">
                                <a href="settings_mobile_push.php" class="settings-nav-link"><span>Mobile Push</span></a>
                                <a href="settings_readiness_capture.php" class="settings-nav-link"><span>Readiness Capture</span></a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            </aside>
            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    var shell = document.querySelector('[data-settings-nav-shell]');
                    var toggle = document.querySelector('[data-settings-nav-toggle]');
                    if (!shell || !toggle) {
                        return;
                    }

                    toggle.addEventListener('click', function () {
                        var isOpen = shell.classList.toggle('is-open');
                        toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                    });
                });
            </script>
            <section class="table-card settings-content-card">
            <div style="display: none; flex-wrap: wrap; border-bottom: 1px solid rgba(0, 0, 0, 0.1); gap: 0;">
                <?php if ($showSettingsTab('general')): ?><a href="?tab=general" style="padding: 0.75rem 0.875rem; text-decoration: none; color: <?php echo $activeTab === 'general' ? '#667eea' : '#64748b'; ?>; border-bottom: 2px solid <?php echo $activeTab === 'general' ? '#667eea' : 'transparent'; ?>; font-weight: <?php echo $activeTab === 'general' ? '600' : '400'; ?>; white-space: nowrap; font-size: 0.875rem;">General</a><?php endif; ?>
                <?php if ($showSettingsTab('email')): ?><a href="?tab=email" style="padding: 0.75rem 0.875rem; text-decoration: none; color: <?php echo $activeTab === 'email' ? '#667eea' : '#64748b'; ?>; border-bottom: 2px solid <?php echo $activeTab === 'email' ? '#667eea' : 'transparent'; ?>; font-weight: <?php echo $activeTab === 'email' ? '600' : '400'; ?>; white-space: nowrap; font-size: 0.875rem;">Email</a><?php endif; ?>
                <?php if ($showSettingsTab('email_assistant')): ?><a href="workspace_skills.php?module=email_assistant&amp;setup_tab=identity#setup" style="padding: 1rem; text-decoration: none; color: #64748b; border-bottom: 2px solid transparent; font-weight: 400; white-space: nowrap;">Email Assistant plugin</a><?php endif; ?>
                <?php if ($showSettingsTab('whatsapp')): ?><a href="?tab=whatsapp" style="padding: 0.75rem 0.875rem; text-decoration: none; color: <?php echo $activeTab === 'whatsapp' ? '#667eea' : '#64748b'; ?>; border-bottom: 2px solid <?php echo $activeTab === 'whatsapp' ? '#667eea' : 'transparent'; ?>; font-weight: <?php echo $activeTab === 'whatsapp' ? '600' : '400'; ?>; white-space: nowrap; font-size: 0.875rem;">WhatsApp</a><?php endif; ?>
                <?php if ($showSettingsTab('ai')): ?><a href="?tab=ai" style="padding: 1rem; text-decoration: none; color: <?php echo $activeTab === 'ai' ? '#667eea' : '#64748b'; ?>; border-bottom: 2px solid <?php echo $activeTab === 'ai' ? '#667eea' : 'transparent'; ?>; font-weight: <?php echo $activeTab === 'ai' ? '600' : '400'; ?>; white-space: nowrap;">AI Services</a><?php endif; ?>
                <?php if ($showSettingsTab('calendar')): ?><a href="?tab=calendar" style="padding: 0.75rem 0.875rem; text-decoration: none; color: <?php echo $activeTab === 'calendar' ? '#667eea' : '#64748b'; ?>; border-bottom: 2px solid <?php echo $activeTab === 'calendar' ? '#667eea' : 'transparent'; ?>; font-weight: <?php echo $activeTab === 'calendar' ? '600' : '400'; ?>; white-space: nowrap; font-size: 0.875rem;">Calendar</a><?php endif; ?>
                <?php if ($showSettingsTab('sms')): ?><a href="?tab=sms" style="padding: 0.75rem 0.875rem; text-decoration: none; color: <?php echo $activeTab === 'sms' ? '#667eea' : '#64748b'; ?>; border-bottom: 2px solid <?php echo $activeTab === 'sms' ? '#667eea' : 'transparent'; ?>; font-weight: <?php echo $activeTab === 'sms' ? '600' : '400'; ?>; white-space: nowrap; font-size: 0.875rem;">SMS</a><?php endif; ?>
                <?php if ($showSettingsTab('ai_autoresponder')): ?><a href="?tab=ai_autoresponder" style="padding: 0.75rem 0.875rem; text-decoration: none; color: <?php echo $activeTab === 'ai_autoresponder' ? '#667eea' : '#64748b'; ?>; border-bottom: 2px solid <?php echo $activeTab === 'ai_autoresponder' ? '#667eea' : 'transparent'; ?>; font-weight: <?php echo $activeTab === 'ai_autoresponder' ? '600' : '400'; ?>; white-space: nowrap; font-size: 0.875rem;">AI Auto-Responder</a><?php endif; ?>
                <?php if ($showSettingsTab('enrichment')): ?><a href="?tab=enrichment" style="padding: 1rem; text-decoration: none; color: <?php echo $activeTab === 'enrichment' ? '#667eea' : '#64748b'; ?>; border-bottom: 2px solid <?php echo $activeTab === 'enrichment' ? '#667eea' : 'transparent'; ?>; font-weight: <?php echo $activeTab === 'enrichment' ? '600' : '400'; ?>; white-space: nowrap;">Data Enrichment</a><?php endif; ?>
                <?php if ($showSettingsTab('scoring')): ?><a href="?tab=scoring" style="padding: 0.75rem 0.875rem; text-decoration: none; color: <?php echo $activeTab === 'scoring' ? '#667eea' : '#64748b'; ?>; border-bottom: 2px solid <?php echo $activeTab === 'scoring' ? '#667eea' : 'transparent'; ?>; font-weight: <?php echo $activeTab === 'scoring' ? '600' : '400'; ?>; white-space: nowrap; font-size: 0.875rem;">Scoring</a><?php endif; ?>
                <?php if ($showSettingsTab('company')): ?><a href="?tab=company" style="padding: 1rem; text-decoration: none; color: <?php echo $activeTab === 'company' ? '#667eea' : '#64748b'; ?>; border-bottom: 2px solid <?php echo $activeTab === 'company' ? '#667eea' : 'transparent'; ?>; font-weight: <?php echo $activeTab === 'company' ? '600' : '400'; ?>; white-space: nowrap;">Company Profile</a><?php endif; ?>
                <?php if ($showSettingsTab('invoicing')): ?><a href="?tab=invoicing" style="padding: 1rem; text-decoration: none; color: <?php echo $activeTab === 'invoicing' ? '#667eea' : '#64748b'; ?>; border-bottom: 2px solid <?php echo $activeTab === 'invoicing' ? '#667eea' : 'transparent'; ?>; font-weight: <?php echo $activeTab === 'invoicing' ? '600' : '400'; ?>; white-space: nowrap;">Invoicing</a><?php endif; ?>
                <?php if ($showSettingsTab('billing')): ?><a href="?tab=billing" style="padding: 1rem; text-decoration: none; color: <?php echo $activeTab === 'billing' ? '#667eea' : '#64748b'; ?>; border-bottom: 2px solid <?php echo $activeTab === 'billing' ? '#667eea' : 'transparent'; ?>; font-weight: <?php echo $activeTab === 'billing' ? '600' : '400'; ?>; white-space: nowrap;">Workspace Billing</a><?php endif; ?>
                <?php if ($showSettingsTab('workspace_governance')): ?><a href="?tab=workspace_governance" style="padding: 1rem; text-decoration: none; color: <?php echo $activeTab === 'workspace_governance' ? '#667eea' : '#64748b'; ?>; border-bottom: 2px solid <?php echo $activeTab === 'workspace_governance' ? '#667eea' : 'transparent'; ?>; font-weight: <?php echo $activeTab === 'workspace_governance' ? '600' : '400'; ?>; white-space: nowrap;">Workspace Team</a><?php endif; ?>
                <?php if ($showSettingsTab('meeting_bot')): ?><a href="?tab=meeting_bot" style="padding: 0.75rem 0.875rem; text-decoration: none; color: <?php echo $activeTab === 'meeting_bot' ? '#667eea' : '#64748b'; ?>; border-bottom: 2px solid <?php echo $activeTab === 'meeting_bot' ? '#667eea' : 'transparent'; ?>; font-weight: <?php echo $activeTab === 'meeting_bot' ? '600' : '400'; ?>; white-space: nowrap; font-size: 0.875rem;">Meeting Bot</a><?php endif; ?>
                <?php if ($showSettingsTab('meeting_note_taker')): ?><a href="?tab=meeting_note_taker" style="padding: 0.75rem 0.875rem; text-decoration: none; color: <?php echo $activeTab === 'meeting_note_taker' ? '#667eea' : '#64748b'; ?>; border-bottom: 2px solid <?php echo $activeTab === 'meeting_note_taker' ? '#667eea' : 'transparent'; ?>; font-weight: <?php echo $activeTab === 'meeting_note_taker' ? '600' : '400'; ?>; white-space: nowrap; font-size: 0.875rem;">Meeting Note Taker</a><?php endif; ?>
                <?php if ($showSettingsTab('workflow_automation')): ?><a href="?tab=workflow_automation" style="padding: 0.75rem 0.875rem; text-decoration: none; color: <?php echo $activeTab === 'workflow_automation' ? '#667eea' : '#64748b'; ?>; border-bottom: 2px solid <?php echo $activeTab === 'workflow_automation' ? '#667eea' : 'transparent'; ?>; font-weight: <?php echo $activeTab === 'workflow_automation' ? '600' : '400'; ?>; white-space: nowrap; font-size: 0.875rem;">Workflow Automation</a><?php endif; ?>
                <?php if (Authorization::can('settings.general', $user)): ?><a href="settings_mobile_push.php" style="padding: 0.75rem 0.875rem; text-decoration: none; color: #64748b; border-bottom: 2px solid transparent; font-weight: 400; white-space: nowrap; font-size: 0.875rem;">📱 Mobile Push</a><?php endif; ?>
                <?php if (Authorization::can('settings.general', $user)): ?><a href="settings_readiness_capture.php" style="padding: 0.75rem 0.875rem; text-decoration: none; color: #64748b; border-bottom: 2px solid transparent; font-weight: 400; white-space: nowrap; font-size: 0.875rem;">🧠 Readiness Capture</a><?php endif; ?>
                <?php if ($showSettingsTab('commercial_automation')): ?><a href="?tab=commercial_automation" style="padding: 0.75rem 0.875rem; text-decoration: none; color: <?php echo $activeTab === 'commercial_automation' ? '#667eea' : '#64748b'; ?>; border-bottom: 2px solid <?php echo $activeTab === 'commercial_automation' ? '#667eea' : 'transparent'; ?>; font-weight: <?php echo $activeTab === 'commercial_automation' ? '600' : '400'; ?>; white-space: nowrap; font-size: 0.875rem;">Commercial Automation</a><?php endif; ?>
                <?php if ($showSettingsTab('monitoring')): ?><a href="?tab=monitoring" style="padding: 0.75rem 0.875rem; text-decoration: none; color: <?php echo $activeTab === 'monitoring' ? '#667eea' : '#64748b'; ?>; border-bottom: 2px solid <?php echo $activeTab === 'monitoring' ? '#667eea' : 'transparent'; ?>; font-weight: <?php echo $activeTab === 'monitoring' ? '600' : '400'; ?>; white-space: nowrap; font-size: 0.875rem;">Monitoring</a><?php endif; ?>
                <?php if ($showSettingsTab('deal_automation')): ?><a href="?tab=deal_automation" style="padding: 0.75rem 0.875rem; text-decoration: none; color: <?php echo $activeTab === 'deal_automation' ? '#667eea' : '#64748b'; ?>; border-bottom: 2px solid <?php echo $activeTab === 'deal_automation' ? '#667eea' : 'transparent'; ?>; font-weight: <?php echo $activeTab === 'deal_automation' ? '600' : '400'; ?>; white-space: nowrap; font-size: 0.875rem;">Deal Automation</a><?php endif; ?>
    </div>
    
    <!-- Tab Content -->
            <div class="settings-tab-content">
        <?php
            $workspaceConnectSettingsCards = [
                'email_assistant' => [
                    'cards' => ['assistant_email'],
                    'heading' => 'Email assistant setup',
                    'description' => 'Connect the assistant mailbox used for replies and inbound instructions.',
                ],
                'calendar' => [
                    'cards' => ['calendar'],
                    'heading' => 'Calendar setup',
                    'description' => 'Connect the workspace calendar for meetings and follow-up events.',
                ],
                'meeting_note_taker' => [
                    'cards' => ['meetings'],
                    'heading' => 'Meeting notes setup',
                    'description' => 'Use the connected calendar to detect meeting links and power meeting notes.',
                ],
            ];
            if (isset($workspaceConnectSettingsCards[$activeTab])):
                $workspaceConnectSettingsCard = $workspaceConnectSettingsCards[$activeTab];
                $workspaceConnectSurface = 'settings-' . $activeTab;
                $workspaceConnectCards = $workspaceConnectSettingsCard['cards'];
                $workspaceConnectHeading = $workspaceConnectSettingsCard['heading'];
                $workspaceConnectDescription = $workspaceConnectSettingsCard['description'];
                require __DIR__ . '/../views/partials/workspace_connect_apps.php';
                unset($workspaceConnectCards, $workspaceConnectHeading, $workspaceConnectDescription, $workspaceConnectSettingsCard);
            endif;
        ?>
        <?php if ($activeTab === 'page_videos'): ?>
            <?php
                $pageVideosReady = $pageVideoAssets->tableReady();
                $pageVideoLibraryById = [];
                $pageVideoLibraryByPath = [];
                foreach ($pageVideoLibrary as $libraryAsset) {
                    $libraryAssetId = (int) ($libraryAsset['id'] ?? 0);
                    if ($libraryAssetId > 0) {
                        $pageVideoLibraryById[$libraryAssetId] = $libraryAsset;
                    }
                    $libraryAssetPath = trim((string) ($libraryAsset['stored_path'] ?? ''));
                    if ($libraryAssetPath !== '') {
                        $pageVideoLibraryByPath[$libraryAssetPath] = $libraryAsset;
                    }
                }
            ?>
            <section class="page-video-entrance-callout" aria-labelledby="entrance-video-settings-title">
                <div class="page-video-entrance-icon" aria-hidden="true"><i class="fas fa-play"></i></div>
                <div>
                    <p class="page-video-entrance-kicker">Public landing media</p>
                    <h2 id="entrance-video-settings-title">Entrance demo video</h2>
                    <p>Upload a landscape product demo to the library, then select it for <strong>Entrance landing demo</strong> below. A 16:9 MP4 is recommended for the widest browser support.</p>
                </div>
            </section>
            <form method="POST" action="settings.php?tab=page_videos" enctype="multipart/form-data" class="page-videos-form content-card">
                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                <input type="hidden" name="tab" value="page_videos">
                <input type="hidden" name="page_video_action" value="bulk_upload">
                <?php if (!$pageVideosReady): ?>
                    <div style="border:1px solid #fde68a;background:#fffbeb;color:#92400e;border-radius:8px;padding:0.85rem;">
                        Page video library is temporarily unavailable until the latest page video migrations are applied.
                    </div>
                <?php endif; ?>
                <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;flex-wrap:wrap;">
                    <div>
                        <h3 style="margin:0;color:#0f172a;">Reusable video library</h3>
                        <p style="margin:0.3rem 0 0;color:#64748b;font-size:0.9rem;line-height:1.45;">Upload product demos and page guides once, then reuse them across public and in-app placements.</p>
                    </div>
                    <label class="page-video-file-picker">
                        <span class="sr-only">Bulk upload page guide videos</span>
                        <input class="page-video-file-input" type="file" name="page_video_library_files[]" accept="video/mp4,video/webm,video/quicktime,video/x-m4v" multiple data-page-video-file-input <?php echo !$pageVideosReady ? 'disabled' : ''; ?>>
                        <span class="page-video-file-button">Choose videos</span>
                        <span class="page-video-file-name" data-page-video-file-name title="No videos selected">No videos selected</span>
                    </label>
                </div>
                <div class="page-video-actions">
                    <span class="page-video-help">MP4, WebM, MOV, or M4V. 50 MB max per file.</span>
                    <button type="submit" class="btn-premium-primary" <?php echo !$pageVideosReady ? 'disabled' : ''; ?>>Upload Videos</button>
                </div>
            </form>
            <div class="page-videos-table-wrap" style="margin-top:1rem;">
                <table class="premium-table page-videos-table page-video-library-table">
                    <thead>
                        <tr>
                            <th scope="col">Library video</th>
                            <th scope="col">File</th>
                            <th scope="col">Usage</th>
                            <th scope="col">Preview</th>
                            <th scope="col">Delete</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($pageVideoLibrary === []): ?>
                            <tr>
                                <td colspan="5"><span class="page-video-empty">No videos in the library yet.</span></td>
                            </tr>
                        <?php endif; ?>
                        <?php foreach ($pageVideoLibrary as $libraryAsset): ?>
                            <?php
                                $libraryAssetId = (int) ($libraryAsset['id'] ?? 0);
                                $libraryTitle = (string) ($libraryAsset['title'] ?? 'Page guide video');
                                $libraryPath = (string) ($libraryAsset['stored_path'] ?? '');
                                $libraryUrl = settingsPageExplainerAssetUrl($libraryPath);
                                $libraryName = (string) ($libraryAsset['original_filename'] ?? basename($libraryPath));
                                $libraryUsageCount = (int) ($libraryAsset['usage_count'] ?? 0);
                                $libraryUsedPages = trim((string) ($libraryAsset['used_page_keys'] ?? ''));
                                $librarySize = (int) ($libraryAsset['file_size'] ?? 0);
                                $librarySizeLabel = $librarySize > 0 ? number_format($librarySize / 1048576, 1) . ' MB' : 'Size unknown';
                            ?>
                            <tr>
                                <td class="page-video-guide-cell">
                                    <strong><?php echo htmlspecialchars($libraryTitle); ?></strong>
                                </td>
                                <td>
                                    <span title="<?php echo htmlspecialchars($libraryPath); ?>"><?php echo htmlspecialchars($libraryName); ?></span>
                                    <span class="page-video-help"><?php echo htmlspecialchars($librarySizeLabel); ?></span>
                                </td>
                                <td>
                                    <span class="page-video-status <?php echo $libraryUsageCount > 0 ? 'is-active' : ''; ?>">
                                        <?php echo $libraryUsageCount > 0 ? htmlspecialchars((string) $libraryUsageCount . ' page' . ($libraryUsageCount === 1 ? '' : 's')) : 'Unused'; ?>
                                    </span>
                                    <?php if ($libraryUsedPages !== ''): ?>
                                        <span class="page-video-help" title="<?php echo htmlspecialchars($libraryUsedPages); ?>"><?php echo htmlspecialchars($libraryUsedPages); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button type="button" class="page-video-current-link" data-page-video-preview-open data-video-url="<?php echo htmlspecialchars($libraryUrl); ?>" data-video-title="<?php echo htmlspecialchars($libraryTitle); ?>" data-video-name="<?php echo htmlspecialchars($libraryName); ?>" aria-label="Preview <?php echo htmlspecialchars($libraryTitle); ?>" title="<?php echo htmlspecialchars($libraryName); ?>">Preview</button>
                                </td>
                                <td>
                                    <form method="POST" action="settings.php?tab=page_videos">
                                        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                        <input type="hidden" name="tab" value="page_videos">
                                        <input type="hidden" name="page_video_action" value="delete_asset">
                                        <input type="hidden" name="video_asset_id" value="<?php echo $libraryAssetId; ?>">
                                        <button type="submit" class="btn-premium-secondary" <?php echo !$pageVideosReady || $libraryUsageCount > 0 ? 'disabled' : ''; ?> title="<?php echo $libraryUsageCount > 0 ? htmlspecialchars('Detach from pages before deleting.') : 'Delete this library video'; ?>">Delete</button>
                                    </form>
                                    <?php if ($libraryUsageCount > 0): ?>
                                        <span class="page-video-help">Delete blocked while attached to pages.</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <form method="POST" action="settings.php?tab=page_videos" class="page-videos-form" style="margin-top:1rem;">
                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                <input type="hidden" name="tab" value="page_videos">
                <input type="hidden" name="page_video_action" value="save_assignments">
                <div class="page-videos-table-wrap" id="video-assignments">
                    <table class="premium-table page-videos-table">
                        <thead>
                            <tr>
                                <th scope="col">Placement</th>
                                <th scope="col">Status</th>
                                <th scope="col">Library video</th>
                                <th scope="col">Visibility</th>
                                <th scope="col">Current video</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pageVideoDefinitions as $pageVideoKey => $pageVideoDefinition): ?>
                                <?php
                                    $pageVideoKey = (string) $pageVideoKey;
                                    $pageVideoLabel = (string) ($pageVideoDefinition['label'] ?? 'Page guide');
                                    $pageVideoContext = (string) ($pageVideoDefinition['button_context'] ?? 'Watch guide button');
                                    $pageVideoDescription = (string) ($pageVideoDefinition['description'] ?? '');
                                    $isEntranceVideo = $pageVideoKey === MarketplacePageExplainerService::PAGE_ENTRANCE;
                                    $pageVideoRow = (array) ($pageVideoExplainers[$pageVideoKey] ?? []);
                                    $pageGuideVideo = trim((string) ($pageVideoRow['video_url'] ?? ''));
                                    $pageGuideVideoUrl = settingsPageExplainerAssetUrl($pageGuideVideo);
                                    $pageGuideVideoPath = (string) (parse_url($pageGuideVideo, PHP_URL_PATH) ?: $pageGuideVideo);
                                    $pageGuideVideoName = basename($pageGuideVideoPath) ?: 'Open current video';
                                    $pageGuideAssetId = (int) ($pageVideoRow['video_asset_id'] ?? 0);
                                    if ($pageGuideAssetId <= 0 && isset($pageVideoLibraryByPath[$pageGuideVideo])) {
                                        $pageGuideAssetId = (int) ($pageVideoLibraryByPath[$pageGuideVideo]['id'] ?? 0);
                                    }
                                    $pageGuideAsset = $pageGuideAssetId > 0 ? ($pageVideoLibraryById[$pageGuideAssetId] ?? []) : [];
                                    if ($pageGuideAsset !== []) {
                                        $pageGuideVideo = trim((string) ($pageGuideAsset['stored_path'] ?? $pageGuideVideo));
                                        $pageGuideVideoUrl = settingsPageExplainerAssetUrl($pageGuideVideo);
                                        $pageGuideVideoName = (string) ($pageGuideAsset['original_filename'] ?? basename($pageGuideVideo));
                                    }
                                    $pageGuideActive = !empty($pageVideoRow['is_active']);
                                    $pageGuideStatusActive = $pageGuideActive && $pageGuideVideo !== '';
                                ?>
                                <tr class="<?php echo $isEntranceVideo ? 'page-video-featured-row' : ''; ?>">
                                    <td class="page-video-guide-cell">
                                        <?php if ($isEntranceVideo): ?>
                                            <span class="page-video-placement-badge">Public landing page</span>
                                        <?php endif; ?>
                                        <strong><?php echo htmlspecialchars($pageVideoLabel); ?></strong>
                                        <?php if ($pageVideoDescription !== ''): ?>
                                            <span class="page-video-placement-note"><?php echo htmlspecialchars($pageVideoDescription); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="page-video-status <?php echo $pageGuideStatusActive ? 'is-active' : ''; ?>">
                                            <?php echo $pageGuideStatusActive ? 'Active' : 'Inactive'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <select class="settings-input" name="<?php echo htmlspecialchars($pageVideoKey); ?>_page_video_asset_id" <?php echo !$pageVideosReady ? 'disabled' : ''; ?>>
                                            <option value="">No video attached</option>
                                            <?php foreach ($pageVideoLibrary as $libraryAsset): ?>
                                                <?php
                                                    $libraryAssetId = (int) ($libraryAsset['id'] ?? 0);
                                                    $libraryTitle = (string) ($libraryAsset['title'] ?? 'Page guide video');
                                                    $libraryName = (string) ($libraryAsset['original_filename'] ?? basename((string) ($libraryAsset['stored_path'] ?? '')));
                                                ?>
                                                <option value="<?php echo $libraryAssetId; ?>" <?php echo $libraryAssetId === $pageGuideAssetId ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($libraryTitle . ' - ' . $libraryName); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <label class="page-video-check page-video-check-compact">
                                            <input type="checkbox" name="<?php echo htmlspecialchars($pageVideoKey); ?>_page_explainer_active" value="1" <?php echo $pageGuideActive ? 'checked' : ''; ?> <?php echo !$pageVideosReady ? 'disabled' : ''; ?>>
                                            <span title="<?php echo htmlspecialchars('Show the ' . $pageVideoContext . ' when this video is available.'); ?>">Show</span>
                                        </label>
                                    </td>
                                    <td>
                                        <?php if ($pageGuideVideo !== ''): ?>
                                            <button type="button" class="page-video-current-link" data-page-video-preview-open data-video-url="<?php echo htmlspecialchars($pageGuideVideoUrl); ?>" data-video-title="<?php echo htmlspecialchars($pageVideoLabel); ?>" data-video-name="<?php echo htmlspecialchars($pageGuideVideoName); ?>" aria-label="Preview current <?php echo htmlspecialchars($pageVideoLabel); ?> video" title="<?php echo htmlspecialchars($pageGuideVideoName); ?>"><?php echo htmlspecialchars($pageGuideVideoName); ?></button>
                                        <?php else: ?>
                                            <span class="page-video-empty">No video</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="page-video-actions">
                    <button type="submit" class="btn-premium-primary" <?php echo !$pageVideosReady ? 'disabled' : ''; ?>>Save Page Video Assignments</button>
                </div>
            </form>
            <div class="page-video-preview-modal" data-page-video-preview-modal hidden>
                <div class="page-video-preview-dialog" role="dialog" aria-modal="true" aria-labelledby="page-video-preview-title">
                    <div class="page-video-preview-head">
                        <div>
                            <p class="page-video-preview-kicker">Page guide preview</p>
                            <h2 id="page-video-preview-title" data-page-video-preview-title>Current video</h2>
                            <p data-page-video-preview-name></p>
                        </div>
                        <button type="button" class="page-video-preview-close" data-page-video-preview-close aria-label="Close video preview">
                            <i class="fas fa-times" aria-hidden="true"></i>
                        </button>
                    </div>
                    <video class="page-video-preview-player" data-page-video-preview-player controls preload="metadata" playsinline></video>
                </div>
            </div>
        <?php elseif ($activeTab === 'platform_admin'): ?>
            <?php if ($isSuperAdmin): ?>
                <section class="content-card" style="display:grid;gap:1rem;margin-bottom:var(--spacing-lg);border-color:<?php echo $autoAdminPlatformEnabled ? '#86efac' : '#cbd5e1'; ?>;background:<?php echo $autoAdminPlatformEnabled ? '#f0fdf4' : '#f8fafc'; ?>;">
                    <div style="display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;flex-wrap:wrap;">
                        <div style="max-width:680px;">
                            <div style="font-size:.72rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:<?php echo $autoAdminPlatformEnabled ? '#15803d' : '#475569'; ?>;">Platform Auto Admin</div>
                            <h3 style="margin:.25rem 0 .35rem;color:#0f172a;font-size:1rem;"><?php echo $autoAdminPlatformEnabled ? 'Platform master switch is on' : 'Platform master switch is off'; ?></h3>
                            <p style="margin:0;color:#64748b;font-size:.88rem;line-height:1.55;">
                                This master switch controls whether workspace Auto Admin is available at all. Each workspace still keeps its own enable, freeze, target, readiness, and audit state.
                            </p>
                        </div>
                        <form method="POST" action="settings.php?tab=platform_admin" style="display:flex;gap:.75rem;align-items:center;flex-wrap:wrap;">
                            <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                            <input type="hidden" name="tab" value="platform_admin">
                            <input type="hidden" name="settings_action" value="auto_admin_platform_toggle">
                            <label style="display:flex;align-items:center;gap:.65rem;padding:.7rem .85rem;border:1px solid <?php echo $autoAdminPlatformEnabled ? '#86efac' : '#cbd5e1'; ?>;border-radius:8px;background:#fff;cursor:pointer;">
                                <input type="checkbox" name="auto_admin_platform_enabled" value="1" <?php echo $autoAdminPlatformEnabled ? 'checked' : ''; ?> style="width:1rem;height:1rem;">
                                <span style="font-weight:700;color:#0f172a;"><?php echo $autoAdminPlatformEnabled ? 'Available to workspaces' : 'Unavailable'; ?></span>
                            </label>
                            <button type="submit" class="btn-premium-primary">Save Platform Switch</button>
                        </form>
                    </div>
                </section>
            <?php endif; ?>
            <?php if ($canPlatformBillingAdmin): ?>
                <section class="content-card settings-platform-admin-card">
                    <div class="settings-platform-admin-shell">
                        <div class="settings-platform-admin-header">
                            <div class="settings-platform-admin-title">
                                <p class="settings-platform-admin-kicker">Platform Admin</p>
                                <h2>Workspace Directory and Onboarding Recovery</h2>
                                <p>View all workspaces, find stuck onboarding, send setup nudges, audit logins, and manage workspace deletion.</p>
                            </div>
                            <div class="settings-platform-admin-directory-actions">
                                <a href="workspaces.php" class="btn-premium-primary settings-platform-admin-action">Open Workspace Directory</a>
                                <a href="workspaces.php?onboarding=stuck" class="btn-premium-secondary settings-platform-admin-action">Review Stuck Onboarding</a>
                            </div>
                        </div>

                        <?php if ($isSuperAdmin && $defaultWorkspaceOpsStatus): ?>
                            <?php
                                $platformOpsHumanize = static function (string $value): string {
                                    $value = trim($value);
                                    if ($value === '') {
                                        return 'Unknown item';
                                    }
                                    $value = preg_replace('/^platform[-_ ]ops[-_]?/i', '', $value) ?? $value;
                                    $value = str_replace(['workspace_settings:', 'owner_helpline:', ':', '-', '_'], ['settings ', 'helpline ', ' ', ' ', ' '], $value);
                                    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
                                    $label = ucwords(trim($value));
                                    return str_replace([' Ai ', ' Api ', ' Crm ', ' Sms '], [' AI ', ' API ', ' CRM ', ' SMS '], $label);
                                };
                                $platformOpsRawKey = static function (string $componentKey, string $piece): string {
                                    $componentKey = trim($componentKey);
                                    $piece = trim($piece);
                                    if ($piece === '') {
                                        return $componentKey;
                                    }
                                    if (strpos($piece, ':') !== false) {
                                        return $piece;
                                    }
                                    return $componentKey !== '' ? $componentKey . ':' . $piece : $piece;
                                };
                                $platformOpsComponentLabel = static function (string $componentKey, array $component) use ($platformOpsHumanize): string {
                                    $label = trim((string) ($component['label'] ?? ''));
                                    return $label !== '' ? $label : $platformOpsHumanize($componentKey);
                                };
                                $platformOpsMissingLabel = static function (string $componentKey, string $piece) use ($platformOpsHumanize, $platformOpsRawKey): string {
                                    $known = [
                                        'workspace_settings:workspace_purpose' => 'Workspace purpose selected',
                                        'workspace_settings:internal_channel_ready' => 'Internal channel marked ready',
                                        'workspace_settings:internal_team_ready' => 'Internal team marked ready',
                                        'owner_helpline:owner_helpline_enabled' => 'Owner helpline enabled',
                                        'owner_helpline:platform-ops-owner_welcome_setup' => 'Owner welcome setup template',
                                        'owner_helpline:platform-ops-owner_problem_followup' => 'Owner problem follow-up template',
                                        'owner_helpline:platform-ops-owner_support_resolution' => 'Owner support resolution template',
                                        'profile:company_name' => 'Company name',
                                        'profile:company_description' => 'Company description',
                                        'profile:company_industry' => 'Company industry',
                                        'invoice_settings:invoice_enabled' => 'Invoicing enabled',
                                        'invoice_settings:default_currency' => 'Default currency',
                                        'invoice_settings:payment_instructions' => 'Payment instructions',
                                        'onboarding_state:completed_status' => 'Onboarding completed',
                                        'onboarding_state:readiness_score_100' => 'Readiness score at 100%',
                                        'onboarding_state:starter_kit' => 'Starter kit captured',
                                        'onboarding_state:optional_setup' => 'Optional setup captured',
                                        'operating_brief:operating_brief' => 'Operating brief',
                                        'operational_score:score_below_100' => 'Operational score at 100%',
                                    ];
                                    $rawKey = $platformOpsRawKey($componentKey, $piece);
                                    return $known[$rawKey] ?? $platformOpsHumanize($piece);
                                };
                                $opsDiagnostics = (array) ($defaultWorkspaceOpsStatus['diagnostics'] ?? []);
                                $opsComponents = (array) ($opsDiagnostics['components'] ?? []);
                                $opsMissing = (array) ($opsDiagnostics['missing'] ?? []);
                                $opsWarnings = array_slice((array) ($opsDiagnostics['warnings'] ?? []), 0, 4);
                                $opsHealthy = (int) ($opsDiagnostics['healthy_count'] ?? 0);
                                $opsTotal = (int) ($opsDiagnostics['total_count'] ?? 0);
                                $opsAreasNeedingAttention = max(0, $opsTotal - $opsHealthy);
                                $opsDiagnosticGroups = [];
                                foreach ($opsComponents as $componentKey => $component) {
                                    $component = (array) $component;
                                    $missingItems = [];
                                    foreach ((array) ($component['missing'] ?? []) as $piece) {
                                        $piece = trim((string) $piece);
                                        if ($piece === '') {
                                            continue;
                                        }
                                        $missingItems[] = [
                                            'label' => $platformOpsMissingLabel((string) $componentKey, $piece),
                                            'raw' => $platformOpsRawKey((string) $componentKey, $piece),
                                        ];
                                    }
                                    if ($missingItems === []) {
                                        continue;
                                    }
                                    $opsDiagnosticGroups[] = [
                                        'label' => $platformOpsComponentLabel((string) $componentKey, $component),
                                        'items' => $missingItems,
                                        'total' => count($missingItems),
                                    ];
                                }
                                if ($opsDiagnosticGroups === [] && $opsMissing !== []) {
                                    $fallbackGroups = [];
                                    foreach ($opsMissing as $missingKey) {
                                        $rawMissingKey = trim((string) $missingKey);
                                        if ($rawMissingKey === '') {
                                            continue;
                                        }
                                        $parts = explode(':', $rawMissingKey, 2);
                                        $componentKey = (string) ($parts[0] ?? 'setup');
                                        $piece = (string) ($parts[1] ?? $rawMissingKey);
                                        $fallbackGroups[$componentKey][] = [
                                            'label' => $platformOpsMissingLabel($componentKey, $piece),
                                            'raw' => $platformOpsRawKey($componentKey, $piece),
                                        ];
                                    }
                                    foreach ($fallbackGroups as $componentKey => $items) {
                                        $opsDiagnosticGroups[] = [
                                            'label' => $platformOpsHumanize((string) $componentKey),
                                            'items' => $items,
                                            'total' => count($items),
                                        ];
                                    }
                                }
                                $opsVisibleGroups = array_slice($opsDiagnosticGroups, 0, 4);
                                $opsHiddenCheckCount = 0;
                                foreach (array_slice($opsDiagnosticGroups, 4) as $hiddenGroup) {
                                    $opsHiddenCheckCount += (int) ($hiddenGroup['total'] ?? 0);
                                }
                            ?>
                            <div class="settings-platform-admin-summary-grid">
                                <div class="settings-platform-admin-panel">
                                    <span class="settings-platform-admin-panel-label">Default workspace</span>
                                    <strong><?php echo (int) ($defaultWorkspaceOpsStatus['operational_score'] ?? 0); ?>% operational</strong>
                                    <?php if (!empty($defaultWorkspaceOpsStatus['operationalized_at'])): ?>
                                        <small>Last operationalized <?php echo htmlspecialchars((string) $defaultWorkspaceOpsStatus['operationalized_at']); ?></small>
                                    <?php else: ?>
                                        <small>Operationalization has not been timestamped yet.</small>
                                    <?php endif; ?>
                                </div>
                                <div class="settings-platform-admin-panel <?php echo $opsMissing ? 'is-warning' : 'is-healthy'; ?>">
                                    <span class="settings-platform-admin-panel-label">Artifact health</span>
                                    <strong><?php echo $opsHealthy; ?>/<?php echo $opsTotal; ?> checks healthy</strong>
                                    <small>
                                        <?php if ($opsMissing): ?>
                                            <?php echo $opsAreasNeedingAttention; ?> setup <?php echo $opsAreasNeedingAttention === 1 ? 'area needs' : 'areas need'; ?> attention.
                                        <?php else: ?>
                                            All required artifacts are healthy.
                                        <?php endif; ?>
                                    </small>
                                </div>
                            </div>

                            <div class="settings-platform-admin-diagnostics <?php echo $opsMissing ? 'is-warning' : 'is-healthy'; ?>">
                                <div class="settings-platform-admin-status-row">
                                    <div class="settings-platform-admin-status-copy">
                                        <span class="settings-platform-admin-panel-label">Diagnostics</span>
                                        <strong>Default workspace setup review</strong>
                                        <p>
                                            <?php echo $opsMissing ? 'Workspace can operate, but setup artifacts need repair.' : 'Default workspace artifacts are aligned with the expected setup.'; ?>
                                        </p>
                                    </div>
                                    <span class="settings-platform-admin-status-badge <?php echo $opsMissing ? 'is-warning' : 'is-healthy'; ?>">
                                        <?php echo $opsMissing ? 'Repair needed' : 'Healthy'; ?>
                                    </span>
                                </div>

                                <?php if ($opsDiagnosticGroups): ?>
                                    <div class="settings-platform-admin-diagnostic-groups" aria-label="Setup areas needing attention">
                                        <?php foreach ($opsVisibleGroups as $group): ?>
                                            <?php
                                                $groupItems = (array) ($group['items'] ?? []);
                                                $visibleItems = array_slice($groupItems, 0, 5);
                                                $hiddenItemCount = max(0, (int) ($group['total'] ?? 0) - count($visibleItems));
                                            ?>
                                            <article class="settings-platform-admin-diagnostic-group">
                                                <div class="settings-platform-admin-diagnostic-group-header">
                                                    <strong><?php echo htmlspecialchars((string) ($group['label'] ?? 'Setup area')); ?></strong>
                                                    <span><?php echo (int) ($group['total'] ?? 0); ?> <?php echo (int) ($group['total'] ?? 0) === 1 ? 'item' : 'items'; ?></span>
                                                </div>
                                                <div class="settings-platform-admin-chip-list">
                                                    <?php foreach ($visibleItems as $item): ?>
                                                        <span class="settings-platform-admin-diagnostic-chip" title="<?php echo htmlspecialchars((string) ($item['raw'] ?? '')); ?>">
                                                            <?php echo htmlspecialchars((string) ($item['label'] ?? 'Missing setup item')); ?>
                                                        </span>
                                                    <?php endforeach; ?>
                                                    <?php if ($hiddenItemCount > 0): ?>
                                                        <span class="settings-platform-admin-diagnostic-chip is-muted">+<?php echo $hiddenItemCount; ?> more checks</span>
                                                    <?php endif; ?>
                                                </div>
                                            </article>
                                        <?php endforeach; ?>
                                        <?php if ($opsHiddenCheckCount > 0): ?>
                                            <div class="settings-platform-admin-diagnostic-more">
                                                <span class="settings-platform-admin-diagnostic-chip is-muted">+<?php echo $opsHiddenCheckCount; ?> more checks</span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>

                                <?php if ($opsWarnings): ?>
                                    <div class="settings-platform-admin-warning">
                                        <span>Diagnostics warning</span>
                                        <p><?php echo htmlspecialchars(implode(' ', $opsWarnings)); ?></p>
                                    </div>
                                <?php endif; ?>

                                <?php if ($opsMissing): ?>
                                    <form method="POST" action="settings.php?tab=platform_admin" class="settings-platform-admin-inline-repair">
                                        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                        <input type="hidden" name="tab" value="platform_admin">
                                        <input type="hidden" name="default_workspace_reason" value="Repair Platform Ops setup from diagnostics panel">
                                        <input type="hidden" name="settings_action" value="operationalize_default_workspace">
                                        <button type="submit" class="btn-premium-primary">Run/Repair Platform Ops Setup</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <div class="settings-platform-admin-section-heading">
                            <div>
                                <span class="settings-platform-admin-panel-label">Recovery actions</span>
                                <h3>Run targeted workspace repair tasks</h3>
                            </div>
                            <p>Use these controls when onboarding artifacts, owner contacts, or the default workspace need attention.</p>
                        </div>

                        <div class="settings-platform-admin-command-grid">
                            <?php if ($isSuperAdmin): ?>
                                <form method="POST" action="settings.php?tab=platform_admin" class="settings-platform-admin-action-form settings-platform-admin-command-card">
                                    <div class="settings-platform-admin-command-copy">
                                        <strong>Repair Platform Ops setup</strong>
                                        <span>Refresh workspace settings and required operational artifacts.</span>
                                    </div>
                                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                    <input type="hidden" name="tab" value="platform_admin">
                                    <label>
                                        <span>Reason</span>
                                        <input type="text" name="default_workspace_reason" value="Repair Platform Ops setup from settings" required aria-label="Platform Ops setup reason">
                                    </label>
                                    <input type="hidden" name="settings_action" value="operationalize_default_workspace">
                                    <button type="submit" class="btn-premium-secondary">Run/Repair Platform Ops Setup</button>
                                </form>
                                <form method="POST" action="settings.php?tab=platform_admin" class="settings-platform-admin-action-form settings-platform-admin-command-card">
                                    <div class="settings-platform-admin-command-copy">
                                        <strong>Reconcile owner contacts</strong>
                                        <span>Repair owner-linked contact records used by workspace recovery.</span>
                                    </div>
                                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                    <input type="hidden" name="tab" value="platform_admin">
                                    <label>
                                        <span>Reason</span>
                                        <input type="text" name="default_workspace_reason" value="Reconcile owner contacts from settings" required aria-label="Owner contact reconciliation reason">
                                    </label>
                                    <input type="hidden" name="settings_action" value="reconcile_default_workspace_owner_contacts">
                                    <button type="submit" class="btn-premium-secondary">Reconcile Owner Contacts</button>
                                </form>
                                <form method="POST" action="settings.php?tab=platform_admin" class="settings-platform-admin-action-form settings-platform-admin-command-card">
                                    <div class="settings-platform-admin-command-copy">
                                        <strong>Recover default workspace</strong>
                                        <span>Run the default workspace recovery check and repair pass.</span>
                                    </div>
                                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                    <input type="hidden" name="tab" value="platform_admin">
                                    <label>
                                        <span>Reason</span>
                                        <input type="text" name="default_workspace_reason" value="Recover default workspace from settings" required aria-label="Default workspace recovery reason">
                                    </label>
                                    <input type="hidden" name="settings_action" value="recover_default_workspace">
                                    <button type="submit" class="btn-premium-secondary">Run Recovery Check</button>
                                </form>
                                <div class="settings-platform-admin-command-card settings-platform-admin-utility-card">
                                    <div class="settings-platform-admin-command-copy">
                                        <strong>Platform Ops workspace</strong>
                                        <span>Open the operational workspace for hands-on follow-up.</span>
                                    </div>
                                    <a href="dashboard.php" class="btn-premium-secondary settings-platform-admin-action">Open Platform Ops Workspace</a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>
            <?php endif; ?>
        <?php elseif ($activeTab === 'general'): ?>
            <form method="POST" action="" class="general-settings-form">
                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                <input type="hidden" name="tab" value="general">
                
                <?php if ($autoAdminEnabled): ?>
                    <div class="content-card settings-general-card settings-general-card--full" style="border:1px solid #a7f3d0;border-radius:10px;background:#f0fdf4;color:#166534;font-size:0.875rem;">
                        Auto Admin is locking the AI governance and automation controls below. Diagnostics links remain available, but edits in this section are disabled while managed mode is on.
                    </div>
                <?php endif; ?>
                    <div class="content-card settings-general-card" style="background:#f8fafc;border:1px solid #dbeafe;">
                        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
                            <div>
                                <span style="color:#0f172a;font-weight:700;font-size:0.9rem;">AI Coach setup</span>
                                <small style="color:#64748b;font-size:0.75rem;display:block;margin-top:0.25rem;">
                                    AI Coach is now enabled and configured from Marketplace for the whole workspace. Current workspace status: <?php echo $aiCoachEnabled ? 'enabled' : 'disabled'; ?>.
                                </small>
                            </div>
                            <a href="workspace_skills.php?module=ai_coach&setup_tab=workspace_readiness#setup" class="btn btn-sm btn-primary" style="text-decoration:none;">Open Marketplace setup</a>
                        </div>
                    </div>
                    <?php if ($canManageUiExperienceMode): ?>
                    <div class="settings-general-card" style="margin-top: var(--spacing-md);">
                        <label for="ui_experience_mode" style="display: block; margin-bottom: 0.5rem; color: #0f172a; font-weight: 500; font-size: 0.875rem;">Interface mode</label>
                        <select
                            id="ui_experience_mode"
                            name="ui_experience_mode"
                            style="width: 100%; max-width: 400px; padding: 0.5rem; border: 1px solid rgba(0, 0, 0, 0.1); border-radius: var(--border-radius-sm); font-size: 0.875rem;"
                        >
                            <option value="<?php echo UIExperienceService::MODE_BEGINNER; ?>" <?php echo $uiExperienceMode === UIExperienceService::MODE_BEGINNER ? 'selected' : ''; ?>>Beginner - simple daily guidance</option>
                            <option value="<?php echo UIExperienceService::MODE_ADVANCED; ?>" <?php echo $uiExperienceMode === UIExperienceService::MODE_ADVANCED ? 'selected' : ''; ?>>Advanced - show full workspaces</option>
                        </select>
                        <small style="color: #64748b; font-size: 0.75rem; display: block; margin-top: 0.25rem;">
                            Beginner keeps the dashboard focused on the next action. Advanced shows full marketing and automation workspaces.
                        </small>
                    </div>
                    <?php endif; ?>
                    <div class="settings-general-card" style="margin-top: var(--spacing-md);">
                        <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                            <input
                                type="checkbox"
                                id="ai_lean_canvas_mode_enabled"
                                name="ai_lean_canvas_mode_enabled"
                                value="1"
                                <?php echo $aiLeanCanvasModeEnabled ? 'checked' : ''; ?>
                                <?php echo $autoAdminManagedAttr; ?>
                                style="width: 1.125rem; height: 1.125rem;"
                            >
                            <span style="color: #0f172a; font-weight: 500; font-size: 0.875rem;">Use Lean Canvas context in AI Coach and Clarity</span>
                        </label>
                        <small style="color: #64748b; font-size: 0.75rem; display: block; margin-top: 0.25rem; margin-left: 1.75rem;">
                            Lean Canvas setup now lives in Marketplace. This toggle only controls whether saved canvas context is used by AI guidance; turning it off keeps your saved canvas data.
                        </small>
                    </div>
                    <div class="settings-general-card" style="margin-top: var(--spacing-md);">
                        <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                            <input
                                type="checkbox"
                                id="inbox_triage_opt_out"
                                name="inbox_triage_opt_out"
                                value="1"
                                <?php echo $inboxTriageOptOut ? 'checked' : ''; ?>
                                style="width: 1.125rem; height: 1.125rem;"
                            >
                            <span style="color: #0f172a; font-weight: 500; font-size: 0.875rem;">My Inbox Triage Opt-out</span>
                        </label>
                        <small style="color: #64748b; font-size: 0.75rem; display: block; margin-top: 0.25rem; margin-left: 1.75rem;">
                            Personal setting. Inbox triage still runs for the system, but auto-owner and auto-task actions will not be applied to items assigned to you.
                        </small>
                    </div>
                    <div class="settings-general-card" style="margin-top: var(--spacing-md);">
                        <label for="ai_guidance_mode" style="display: block; margin-bottom: 0.5rem; color: #0f172a; font-weight: 500; font-size: 0.875rem;">AI Guidance Mode</label>
                        <select 
                            id="ai_guidance_mode" 
                            name="ai_guidance_mode" 
                            <?php echo $autoAdminManagedAttr; ?>
                            style="width: 100%; max-width: 400px; padding: 0.5rem; border: 1px solid rgba(0, 0, 0, 0.1); border-radius: var(--border-radius-sm); font-size: 0.875rem;"
                        >
                            <option value="1" <?php echo $aiGuidanceMode === '1' ? 'selected' : ''; ?>>Foundation Mode — Business mentor + operations auditor</option>
                            <option value="2" <?php echo $aiGuidanceMode === '2' ? 'selected' : ''; ?>>Operations Mode — CRM usage and execution focus</option>
                            <option value="3" <?php echo $aiGuidanceMode === '3' ? 'selected' : ''; ?>>Guardian Mode — Silent; only critical alerts (no daily coaching)</option>
                            <option value="auto" <?php echo $aiGuidanceMode === 'auto' ? 'selected' : ''; ?>>Auto — System chooses based on your setup (recommended)</option>
                        </select>
                        <small style="color: #64748b; font-size: 0.75rem; display: block; margin-top: 0.25rem;">
                            Foundation: covers business fundamentals. Operations: CRM-only focus. Guardian: alerts only. Auto: adapts to your maturity.
                        </small>
                    </div>
                    <div class="settings-general-card" style="margin-top: var(--spacing-md);">
                        <label for="hours_per_week_sales" style="display: block; margin-bottom: 0.5rem; color: #0f172a; font-weight: 500; font-size: 0.875rem;">Hours per week for sales/marketing</label>
                        <select 
                            id="hours_per_week_sales" 
                            name="hours_per_week_sales" 
                            style="width: 100%; max-width: 400px; padding: 0.5rem; border: 1px solid rgba(0, 0, 0, 0.1); border-radius: var(--border-radius-sm); font-size: 0.875rem;"
                        >
                            <option value="">Not set</option>
                            <option value="1-5" <?php echo $hoursPerWeekSales === '1-5' ? 'selected' : ''; ?>>1-5 hours</option>
                            <option value="6-10" <?php echo $hoursPerWeekSales === '6-10' ? 'selected' : ''; ?>>6-10 hours</option>
                            <option value="11-20" <?php echo $hoursPerWeekSales === '11-20' ? 'selected' : ''; ?>>11-20 hours</option>
                            <option value="21+" <?php echo $hoursPerWeekSales === '21+' ? 'selected' : ''; ?>>21+ hours</option>
                        </select>
                        <small style="color: #64748b; font-size: 0.75rem; display: block; margin-top: 0.25rem;">
                            Used by Foundation Mode to suggest realistic targets.
                        </small>
                    </div>
                    <div class="settings-general-card" style="margin-top: var(--spacing-lg); padding: 1rem; border: 1px solid #e2e8f0; border-radius: 10px; background: #fff;">
                        <h4 style="margin: 0 0 0.75rem 0; color: #0f172a; font-size: 0.95rem;">AI Task Automation</h4>
                        <label style="display:flex;align-items:center;gap:0.5rem;cursor:pointer;margin-bottom:0.75rem;">
                            <input type="checkbox" id="ai_auto_task_completion_enabled" name="ai_auto_task_completion_enabled" value="1" <?php echo $aiAutoTaskCompletionEnabled ? 'checked' : ''; ?> <?php echo $autoAdminManagedAttr; ?> style="width:1.125rem;height:1.125rem;">
                            <span style="color:#0f172a;font-weight:500;font-size:0.875rem;">Allow AI auto-complete tasks</span>
                        </label>
                        <small style="color:#64748b;font-size:0.75rem;display:block;margin:-0.5rem 0 0.75rem 1.75rem;">Evidence only. The system will auto-complete only when there is explicit system evidence.</small>
                        <div style="max-width:220px;">
                            <label for="ai_auto_task_completion_min_confidence" style="display:block;margin-bottom:0.35rem;color:#0f172a;font-weight:500;font-size:0.875rem;">Auto-complete confidence threshold</label>
                            <input type="number" id="ai_auto_task_completion_min_confidence" name="ai_auto_task_completion_min_confidence" min="0" max="1" step="0.01" value="<?php echo htmlspecialchars((string) $aiAutoTaskCompletionMinConfidence); ?>" <?php echo $autoAdminManagedAttr; ?> style="width:100%;padding:0.5rem;border:1px solid rgba(0,0,0,0.1);border-radius:6px;">
                        </div>
                    </div>
                    <div class="settings-general-card settings-general-card--full" style="margin-top: var(--spacing-lg); padding: 1rem; border: 1px solid #dbeafe; border-radius: 10px; background: #f8fbff;">
                        <h4 style="margin: 0 0 0.75rem 0; color: #0f172a; font-size: 0.95rem;">AI Governance</h4>
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 0.75rem;">
                            <div>
                                <label for="ai_context_strictness" style="display:block;margin-bottom:0.35rem;color:#0f172a;font-weight:500;font-size:0.875rem;">AI context strictness</label>
                                <select id="ai_context_strictness" name="ai_context_strictness" <?php echo $autoAdminManagedAttr; ?> style="width:100%;padding:0.5rem;border:1px solid rgba(0,0,0,0.1);border-radius:6px;">
                                    <option value="balanced" <?php echo $aiContextStrictness === 'balanced' ? 'selected' : ''; ?>>Balanced</option>
                                    <option value="strict" <?php echo $aiContextStrictness === 'strict' ? 'selected' : ''; ?>>Strict</option>
                                    <option value="maximum" <?php echo $aiContextStrictness === 'maximum' ? 'selected' : ''; ?>>Maximum</option>
                                </select>
                            </div>
                            <div>
                                <label for="ai_mode_lock" style="display:block;margin-bottom:0.35rem;color:#0f172a;font-weight:500;font-size:0.875rem;">Lock AI mode</label>
                                <select id="ai_mode_lock" name="ai_mode_lock" <?php echo $autoAdminManagedAttr; ?> style="width:100%;padding:0.5rem;border:1px solid rgba(0,0,0,0.1);border-radius:6px;">
                                    <option value="auto" <?php echo $aiModeLock === 'auto' ? 'selected' : ''; ?>>Auto</option>
                                    <option value="foundation" <?php echo $aiModeLock === 'foundation' ? 'selected' : ''; ?>>Foundation</option>
                                    <option value="operations" <?php echo $aiModeLock === 'operations' ? 'selected' : ''; ?>>Operations</option>
                                    <option value="guardian" <?php echo $aiModeLock === 'guardian' ? 'selected' : ''; ?>>Guardian</option>
                                </select>
                            </div>
                            <div>
                                <label for="ai_missing_context_behavior" style="display:block;margin-bottom:0.35rem;color:#0f172a;font-weight:500;font-size:0.875rem;">Missing context behavior</label>
                                <select id="ai_missing_context_behavior" name="ai_missing_context_behavior" <?php echo $autoAdminManagedAttr; ?> style="width:100%;padding:0.5rem;border:1px solid rgba(0,0,0,0.1);border-radius:6px;">
                                    <option value="warn" <?php echo $aiMissingContextBehavior === 'warn' ? 'selected' : ''; ?>>Warn</option>
                                    <option value="degrade" <?php echo $aiMissingContextBehavior === 'degrade' ? 'selected' : ''; ?>>Degrade</option>
                                    <option value="block_high_risk" <?php echo $aiMissingContextBehavior === 'block_high_risk' ? 'selected' : ''; ?>>Block high-risk</option>
                                </select>
                            </div>
                            <div>
                                <label for="ai_advice_min_confidence" style="display:block;margin-bottom:0.35rem;color:#0f172a;font-weight:500;font-size:0.875rem;">Minimum confidence for AI advice</label>
                                <input type="number" id="ai_advice_min_confidence" name="ai_advice_min_confidence" min="0" max="1" step="0.01" value="<?php echo htmlspecialchars((string) $aiAdviceMinConfidence); ?>" <?php echo $autoAdminManagedAttr; ?> style="width:100%;padding:0.5rem;border:1px solid rgba(0,0,0,0.1);border-radius:6px;">
                            </div>
                            <div>
                                <label for="ai_action_min_confidence" style="display:block;margin-bottom:0.35rem;color:#0f172a;font-weight:500;font-size:0.875rem;">Minimum confidence for AI actions</label>
                                <input type="number" id="ai_action_min_confidence" name="ai_action_min_confidence" min="0" max="1" step="0.01" value="<?php echo htmlspecialchars((string) $aiActionMinConfidence); ?>" <?php echo $autoAdminManagedAttr; ?> style="width:100%;padding:0.5rem;border:1px solid rgba(0,0,0,0.1);border-radius:6px;">
                            </div>
                            <div>
                                <label for="ai_goal_relevance_min_score" style="display:block;margin-bottom:0.35rem;color:#0f172a;font-weight:500;font-size:0.875rem;">Minimum goal relevance for proactive guidance</label>
                                <input type="number" id="ai_goal_relevance_min_score" name="ai_goal_relevance_min_score" min="0" max="1" step="0.01" value="<?php echo htmlspecialchars((string) $aiGoalRelevanceMinScore); ?>" <?php echo $autoAdminManagedAttr; ?> style="width:100%;padding:0.5rem;border:1px solid rgba(0,0,0,0.1);border-radius:6px;">
                            </div>
                        </div>
                    </div>
                    <div class="settings-general-card settings-general-card--full" style="margin-top: var(--spacing-lg); padding: 1rem; border: 1px solid #cbd5e1; border-radius: 10px; background: #fff;">
                        <h4 style="margin: 0 0 0.75rem 0; color: #0f172a; font-size: 0.95rem;">AI Confidence Calibration</h4>
                        <label style="display:flex;align-items:center;gap:0.5rem;cursor:pointer;margin-bottom:0.75rem;">
                            <input type="checkbox" id="ai_autonomous_threshold_tuning_enabled" name="ai_autonomous_threshold_tuning_enabled" value="1" <?php echo $aiAutonomousThresholdTuningEnabled ? 'checked' : ''; ?> <?php echo $autoAdminManagedAttr; ?> style="width:1.125rem;height:1.125rem;">
                            <span style="color:#0f172a;font-weight:500;font-size:0.875rem;">Enable autonomous AI threshold tuning</span>
                        </label>
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:0.75rem;margin-bottom:0.75rem;">
                            <div>
                                <label for="ai_calibration_min_sample_size" style="display:block;margin-bottom:0.35rem;color:#0f172a;font-weight:500;font-size:0.875rem;">Minimum sample size</label>
                                <input type="number" id="ai_calibration_min_sample_size" name="ai_calibration_min_sample_size" min="1" step="1" value="<?php echo (int) $aiCalibrationMinSampleSize; ?>" <?php echo $autoAdminManagedAttr; ?> style="width:100%;padding:0.5rem;border:1px solid rgba(0,0,0,0.1);border-radius:6px;">
                            </div>
                            <div>
                                <label for="ai_calibration_daily_change_cap" style="display:block;margin-bottom:0.35rem;color:#0f172a;font-weight:500;font-size:0.875rem;">Daily change cap</label>
                                <input type="number" id="ai_calibration_daily_change_cap" name="ai_calibration_daily_change_cap" min="0" max="1" step="0.01" value="<?php echo htmlspecialchars((string) $aiCalibrationDailyChangeCap); ?>" <?php echo $autoAdminManagedAttr; ?> style="width:100%;padding:0.5rem;border:1px solid rgba(0,0,0,0.1);border-radius:6px;">
                            </div>
                            <div>
                                <label for="ai_calibration_rolling_change_cap" style="display:block;margin-bottom:0.35rem;color:#0f172a;font-weight:500;font-size:0.875rem;">14-day rolling cap</label>
                                <input type="number" id="ai_calibration_rolling_change_cap" name="ai_calibration_rolling_change_cap" min="0" max="1" step="0.01" value="<?php echo htmlspecialchars((string) $aiCalibrationRollingChangeCap); ?>" <?php echo $autoAdminManagedAttr; ?> style="width:100%;padding:0.5rem;border:1px solid rgba(0,0,0,0.1);border-radius:6px;">
                            </div>
                        </div>
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:0.75rem;color:#475569;font-size:0.85rem;">
                            <div><strong>Last tuning run:</strong> <?php echo htmlspecialchars($aiCalibrationLastRunAt ?: 'not yet run'); ?></div>
                            <div><strong>Review changes:</strong> Calibration history now loads in the diagnostics workbench only.</div>
                        </div>
                        <div style="margin-top:0.75rem;">
                            <a href="ai_automation_diagnostics.php#calibration-section" style="color:var(--accent-blue);text-decoration:none;font-weight:600;">Open diagnostics calibration view</a>
                        </div>
                    </div>
                    <div class="settings-general-card settings-general-card--full" style="margin-top: var(--spacing-lg); padding: 1rem; border: 1px solid #cbd5e1; border-radius: 10px; background: #fff;">
                        <h4 style="margin: 0 0 0.75rem 0; color: #0f172a; font-size: 0.95rem;">AI Incident Alerting</h4>
                        <label style="display:flex;align-items:center;gap:0.5rem;cursor:pointer;margin-bottom:0.75rem;">
                            <input type="checkbox" id="ai_incident_alerts_enabled" name="ai_incident_alerts_enabled" value="1" <?php echo $aiIncidentAlertsEnabled ? 'checked' : ''; ?> <?php echo $autoAdminManagedAttr; ?> style="width:1.125rem;height:1.125rem;">
                            <span style="color:#0f172a;font-weight:500;font-size:0.875rem;">Enable AI incident alerts</span>
                        </label>
                        <label style="display:flex;align-items:center;gap:0.5rem;cursor:pointer;margin-bottom:0.75rem;">
                            <input type="checkbox" id="ai_incident_check_enabled" name="ai_incident_check_enabled" value="1" <?php echo $aiIncidentCheckEnabled ? 'checked' : ''; ?> <?php echo $autoAdminManagedAttr; ?> style="width:1.125rem;height:1.125rem;">
                            <span style="color:#0f172a;font-weight:500;font-size:0.875rem;">Enable scheduled AI incident checks</span>
                        </label>
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:0.75rem;margin-bottom:0.75rem;">
                            <div>
                                <label for="ai_incident_medium_cooldown_minutes" style="display:block;margin-bottom:0.35rem;color:#0f172a;font-weight:500;font-size:0.875rem;">Medium cooldown (minutes)</label>
                                <input type="number" id="ai_incident_medium_cooldown_minutes" name="ai_incident_medium_cooldown_minutes" min="1" step="1" value="<?php echo (int) $aiIncidentMediumCooldownMinutes; ?>" <?php echo $autoAdminManagedAttr; ?> style="width:100%;padding:0.5rem;border:1px solid rgba(0,0,0,0.1);border-radius:6px;">
                            </div>
                            <div>
                                <label for="ai_incident_high_cooldown_minutes" style="display:block;margin-bottom:0.35rem;color:#0f172a;font-weight:500;font-size:0.875rem;">High cooldown (minutes)</label>
                                <input type="number" id="ai_incident_high_cooldown_minutes" name="ai_incident_high_cooldown_minutes" min="1" step="1" value="<?php echo (int) $aiIncidentHighCooldownMinutes; ?>" <?php echo $autoAdminManagedAttr; ?> style="width:100%;padding:0.5rem;border:1px solid rgba(0,0,0,0.1);border-radius:6px;">
                            </div>
                            <div>
                                <label for="ai_incident_critical_cooldown_minutes" style="display:block;margin-bottom:0.35rem;color:#0f172a;font-weight:500;font-size:0.875rem;">Critical cooldown (minutes)</label>
                                <input type="number" id="ai_incident_critical_cooldown_minutes" name="ai_incident_critical_cooldown_minutes" min="1" step="1" value="<?php echo (int) $aiIncidentCriticalCooldownMinutes; ?>" <?php echo $autoAdminManagedAttr; ?> style="width:100%;padding:0.5rem;border:1px solid rgba(0,0,0,0.1);border-radius:6px;">
                            </div>
                        </div>
                        <div style="margin-top:0.75rem;color:#475569;font-size:0.85rem;">
                            Run <code>php cli/ai_incident_check.php</code> every 15 minutes to detect and alert on AI incidents.
                        </div>
                    </div>
                    <div class="settings-general-card settings-general-card--full" style="margin-top: var(--spacing-lg); padding: 1rem; border: 1px solid #cbd5e1; border-radius: 10px; background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);">
                        <h4 style="margin: 0 0 0.5rem 0; color: #0f172a; font-size: 0.95rem;">AI Diagnostics Shortcuts</h4>
                        <p style="margin: 0 0 0.85rem 0; color: #475569; font-size: 0.85rem;">
                            Detailed diagnostics now load on demand so the default settings page stays responsive.
                        </p>
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:0.75rem;">
                            <a href="ai_automation_diagnostics.php?source=assistant" style="display:block;padding:0.9rem 1rem;border:1px solid #dbeafe;border-radius:10px;background:#f8fbff;text-decoration:none;color:inherit;">
                                <strong style="display:block;color:#1d4ed8;">Context diagnostics</strong>
                                <span style="display:block;margin-top:0.35rem;color:#475569;font-size:0.82rem;">Review readiness, context quality, calibration history, and scheduled job health.</span>
                            </a>
                            <a href="ai_prompt_control.php" style="display:block;padding:0.9rem 1rem;border:1px solid #e2e8f0;border-radius:10px;background:#fff;text-decoration:none;color:inherit;">
                                <strong style="display:block;color:#0f172a;">Prompt control</strong>
                                <span style="display:block;margin-top:0.35rem;color:#475569;font-size:0.82rem;">Inspect active prompt versions, stale-context warnings, and dry-run details.</span>
                            </a>
                            <a href="ai_control_center.php" style="display:block;padding:0.9rem 1rem;border:1px solid #e2e8f0;border-radius:10px;background:#fff;text-decoration:none;color:inherit;">
                                <strong style="display:block;color:#0f172a;">AI control center</strong>
                                <span style="display:block;margin-top:0.35rem;color:#475569;font-size:0.82rem;">Manage runtime controls, pauses, and safety presets without loading them here.</span>
                            </a>
                            <a href="ai_incidents.php" style="display:block;padding:0.9rem 1rem;border:1px solid #fee2e2;border-radius:10px;background:#fff7f7;text-decoration:none;color:inherit;">
                                <strong style="display:block;color:#991b1b;">Incident workbench</strong>
                                <span style="display:block;margin-top:0.35rem;color:#7f1d1d;font-size:0.82rem;">Open current incidents, check status history, and recovery workflows on demand.</span>
                            </a>
                        </div>
                    </div>
                </div>
                
                <div class="content-card settings-general-card settings-general-card--full settings-general-card--actions" style="background:#ffffff;display:grid;gap:1rem;">
                    <div>
                        <label for="app_name" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Application Name</label>
                        <input 
                            type="text" 
                            id="app_name" 
                            name="app_name" 
                            value="<?php echo htmlspecialchars($_ENV['APP_NAME'] ?? brandProductName()); ?>"
                            style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                        >
                    </div>
                    
                    <div>
                        <label for="app_env" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Environment</label>
                        <select 
                            id="app_env" 
                            name="app_env" 
                            style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                        >
                            <option value="development" <?php echo ($_ENV['APP_ENV'] ?? 'development') === 'development' ? 'selected' : ''; ?>>Development</option>
                            <option value="production" <?php echo ($_ENV['APP_ENV'] ?? '') === 'production' ? 'selected' : ''; ?>>Production</option>
                        </select>
                    </div>
                    
                    <div>
                        <label style="display: flex; align-items: center; gap: var(--spacing-sm);">
                            <input 
                                type="checkbox" 
                                name="app_debug" 
                                <?php echo ($_ENV['APP_DEBUG'] ?? 'false') === 'true' ? 'checked' : ''; ?>
                                style="width: 18px; height: 18px;"
                            >
                            <span style="color: var(--midnight-black); font-weight: 500;">Enable Debug Mode</span>
                        </label>
                        <small style="color: var(--charcoal-grey); font-size: 12px; display: block; margin-top: var(--spacing-xs);">
                            Show detailed error messages (disable in production)
                        </small>
                    </div>
                    
                    <div style="display: flex; gap: var(--spacing-md); justify-content: flex-end; margin-top: var(--spacing-md);">
                        <button type="submit" class="btn-premium-primary">
                            Save Settings
                        </button>
                    </div>
                </div>
            </form>

            <?php if ($canAdminDataReset): ?>
            <form method="POST" action="" class="general-settings-followup-form" style="max-width: var(--settings-general-card-width); margin-top: var(--spacing-xl); padding: var(--spacing-lg); border: 1px solid #bfdbfe; background: #eff6ff; border-radius: 10px;">
                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                <input type="hidden" name="tab" value="general">
                <h3 style="margin: 0 0 0.5rem 0; color: #1e3a8a; font-size: 1rem;">Demo Mode Control Center</h3>
                <p style="margin: 0 0 0.75rem 0; color: #1e40af; font-size: 0.875rem;">
                    Admin-only mode for guided product demonstrations. When enabled, outbound Email/WhatsApp/SMS/Webhook actions are simulated (no external delivery).
                </p>
                <div style="margin-bottom: 0.75rem; padding: 0.7rem 0.85rem; border-radius: 8px; background: #ffffff; border: 1px solid #dbeafe; color: #334155; font-size: 0.8125rem; line-height: 1.6;">
                    Use <strong>interiors_contractor</strong> for quote-led project demos, <strong>whatsapp_heavy_smb</strong> for chat-first lead follow-up demos, <strong>agencies</strong> for discovery and proposal demos, or <strong>distributors_wholesalers</strong> for stock inquiry, pricing, and reorder demos.
                </div>
                <?php if (!empty($demoModeState['error'])): ?>
                    <div style="margin-bottom: 0.75rem; padding: 0.6rem; border-radius: 6px; background: #fee2e2; border: 1px solid #fecaca; color: #991b1b; font-size: 0.8125rem;">
                        <?php echo htmlspecialchars((string) $demoModeState['error']); ?>
                    </div>
                <?php endif; ?>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 0.75rem; margin-bottom: 0.75rem;">
                    <div style="background: white; border: 1px solid #dbeafe; border-radius: 8px; padding: 0.6rem;">
                        <div style="font-size: 0.75rem; color: #64748b;">Status</div>
                        <div style="font-weight: 700; color: <?php echo !empty($demoModeState['is_enabled']) ? '#065f46' : '#334155'; ?>;">
                            <?php echo !empty($demoModeState['is_enabled']) ? 'Enabled' : 'Disabled'; ?>
                        </div>
                    </div>
                    <div style="background: white; border: 1px solid #dbeafe; border-radius: 8px; padding: 0.6rem;">
                        <div style="font-size: 0.75rem; color: #64748b;">Active Run ID</div>
                        <div style="font-weight: 700; color: #0f172a;"><?php echo (int) ($demoModeState['active_run_id'] ?? 0); ?></div>
                    </div>
                    <div style="background: white; border: 1px solid #dbeafe; border-radius: 8px; padding: 0.6rem;">
                        <div style="font-size: 0.75rem; color: #64748b;">Seeded Records</div>
                        <div style="font-weight: 700; color: #0f172a;"><?php echo number_format((int) ($demoModeState['seeded_records'] ?? 0)); ?></div>
                    </div>
                    <div style="background: white; border: 1px solid #dbeafe; border-radius: 8px; padding: 0.6rem;">
                        <div style="font-size: 0.75rem; color: #64748b;">Simulation</div>
                        <div style="font-weight: 700; color: #0f172a;"><?php echo !empty($demoModeState['simulation_only']) ? 'ON' : 'OFF'; ?></div>
                    </div>
                </div>
                <?php if (!empty($demoModeState['last_operation'])): ?>
                    <div style="margin-bottom: 0.75rem; font-size: 0.8rem; color: #334155;">
                        Last operation: <strong><?php echo htmlspecialchars((string) ($demoModeState['last_operation']['operation'] ?? '')); ?></strong>
                        (<?php echo htmlspecialchars((string) ($demoModeState['last_operation']['result'] ?? '')); ?>)
                        at <?php echo htmlspecialchars((string) ($demoModeState['last_operation']['created_at'] ?? '')); ?>
                    </div>
                <?php endif; ?>
                <?php $resolvedDemoRunId = settingsResolveDemoRunId((int) ($demoModeState['active_run_id'] ?? 0)); ?>
                <div style="display: flex; flex-wrap: wrap; gap: 0.6rem; align-items: end;">
                    <label style="display: grid; gap: 0.35rem; font-size: 0.8rem; color: #1e3a8a;">
                        Seed Profile
                        <select name="demo_seed_profile" style="padding: 0.5rem 0.6rem; border: 1px solid #93c5fd; border-radius: 6px; background: #fff;">
                            <option value="full" <?php echo (($demoModeState['active_run']['seed_profile'] ?? 'full') === 'full') ? 'selected' : ''; ?>>full</option>
                            <option value="core" <?php echo (($demoModeState['active_run']['seed_profile'] ?? '') === 'core') ? 'selected' : ''; ?>>core</option>
                            <option value="light" <?php echo (($demoModeState['active_run']['seed_profile'] ?? '') === 'light') ? 'selected' : ''; ?>>light</option>
                            <option value="interiors_contractor" <?php echo (($demoModeState['active_run']['seed_profile'] ?? '') === 'interiors_contractor') ? 'selected' : ''; ?>>interiors_contractor</option>
                            <option value="whatsapp_heavy_smb" <?php echo (($demoModeState['active_run']['seed_profile'] ?? '') === 'whatsapp_heavy_smb') ? 'selected' : ''; ?>>whatsapp_heavy_smb</option>
                            <option value="agencies" <?php echo (($demoModeState['active_run']['seed_profile'] ?? '') === 'agencies') ? 'selected' : ''; ?>>agencies</option>
                            <option value="distributors_wholesalers" <?php echo (($demoModeState['active_run']['seed_profile'] ?? '') === 'distributors_wholesalers') ? 'selected' : ''; ?>>distributors_wholesalers</option>
                        </select>
                    </label>
                    <input type="hidden" name="demo_run_id" value="<?php echo (int) $resolvedDemoRunId; ?>">
                    <?php if (empty($demoModeState['is_enabled'])): ?>
                        <button type="submit" name="demo_action" value="enable" onclick="return confirm('Enable demo mode and seed full demo data? External sends will be simulated.');" style="background: #2563eb; color: #fff; border: none; border-radius: 6px; padding: 0.55rem 0.9rem; font-weight: 600; cursor: pointer;">
                            Enable + Seed Demo Mode
                        </button>
                        <?php if (!empty($demoModeState['simulation_only'])): ?>
                            <button type="submit" name="demo_action" value="simulation_off" onclick="return confirm('Turn simulation OFF? Outbound actions may send to real external channels when executed.');" style="background: #b45309; color: #fff; border: none; border-radius: 6px; padding: 0.55rem 0.9rem; font-weight: 600; cursor: pointer;">
                                Turn Simulation OFF
                            </button>
                        <?php else: ?>
                            <button type="submit" name="demo_action" value="simulation_on" onclick="return confirm('Turn simulation ON? Outbound actions will be simulated while Demo Mode is enabled.');" style="background: #0f766e; color: #fff; border: none; border-radius: 6px; padding: 0.55rem 0.9rem; font-weight: 600; cursor: pointer;">
                                Turn Simulation ON
                            </button>
                        <?php endif; ?>
                    <?php else: ?>
                        <button type="submit" name="demo_action" value="disable" onclick="return confirm('Disable demo mode? Seeded demo data will remain until you purge it.');" style="background: #0f172a; color: #fff; border: none; border-radius: 6px; padding: 0.55rem 0.9rem; font-weight: 600; cursor: pointer;">
                            Disable Demo Mode
                        </button>
                        <button type="submit" name="demo_action" value="reseed" onclick="return confirm('Reseed demo data into the current run? This adds more demo records.');" style="background: #1d4ed8; color: #fff; border: none; border-radius: 6px; padding: 0.55rem 0.9rem; font-weight: 600; cursor: pointer;">
                            Re-seed Current Run
                        </button>
                        <button type="submit" name="demo_action" value="simulation_on" onclick="return confirm('Keep simulation ON while demo mode is enabled?');" style="background: #0f766e; color: #fff; border: none; border-radius: 6px; padding: 0.55rem 0.9rem; font-weight: 600; cursor: pointer;">
                            Ensure Simulation ON
                        </button>
                    <?php endif; ?>
                    <button type="submit" name="demo_action" value="purge" onclick="return confirm('Purge all records tracked in this demo run? This cannot be undone.');" style="background: #dc2626; color: #fff; border: none; border-radius: 6px; padding: 0.55rem 0.9rem; font-weight: 600; cursor: pointer;">
                        Purge Demo Data
                    </button>
                </div>
            </form>

            <section class="general-settings-followup-section" style="max-width: var(--settings-general-card-width); margin-top: var(--spacing-xl); padding: var(--spacing-lg); border: 1px solid #fecdd3; background: linear-gradient(180deg, #fff7f7 0%, #fff1f2 100%); border-radius: 12px;">
                <h3 style="margin: 0 0 0.4rem 0; color: #881337; font-size: 1rem;">Data &amp; Context Reset</h3>
                <p style="margin: 0 0 1rem 0; color: #9f1239; font-size: 0.875rem; line-height: 1.6;">
                    Workspace reset tools clear only the active workspace. Platform-wide reset tools are visible only to Super Admin.
                </p>

                <?php if ($canWorkspaceDataReset): ?>
                <div style="margin: 0 0 0.85rem 0; padding: 0.65rem 0.75rem; border: 1px solid #fecdd3; border-radius: 8px; background: #fff; color: #7f1d1d; font-size: 0.8125rem;">
                    Active workspace: <strong><?php echo htmlspecialchars($activeWorkspaceName !== '' ? $activeWorkspaceName : 'Workspace'); ?></strong><?php if ($activeWorkspaceSlug !== ''): ?> <span style="color: #9f1239;">/<?php echo htmlspecialchars($activeWorkspaceSlug); ?></span><?php endif; ?>
                </div>
                <form id="workspace-data-reset-form" method="POST" action="" class="general-settings-followup-form" style="border: 1px solid #fecaca; background: #fff5f5; margin-top: 0;">
                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                    <input type="hidden" name="tab" value="general">
                    <input type="hidden" name="danger_action" value="reset_core_data">
                    <h4 style="margin: 0 0 0.5rem 0; color: #991b1b; font-size: 1rem;">Workspace Data Reset</h4>
                    <p style="margin: 0 0 0.75rem 0; color: #7f1d1d; font-size: 0.875rem;">
                        Clears operational records for this workspace across contacts, companies, deals, tasks, activities, forms, workflows, tags, communication history, targets, products, invoices, and AI/assistant context. It preserves workspace membership, slugs, global roles, permissions, billing plans, and Super Admin accounts.
                    </p>
                    <label for="danger_confirm_text" style="display: block; margin-bottom: 0.4rem; color: #7f1d1d; font-weight: 600; font-size: 0.8125rem;">
                        Type <code>RESET DATA</code> to confirm
                    </label>
                    <input
                        type="text"
                        id="danger_confirm_text"
                        name="danger_confirm_text"
                        placeholder="RESET DATA"
                        autocomplete="off"
                        style="width: 100%; max-width: 260px; padding: 0.55rem 0.7rem; border: 1px solid #fca5a5; border-radius: 6px; margin-bottom: 0.75rem; background: #fff;"
                        required
                    >
                    <div>
                        <button type="submit" style="background: #dc2626; color: #fff; border: none; border-radius: 6px; padding: 0.55rem 0.9rem; font-weight: 600; cursor: pointer;">
                            Reset This Workspace
                        </button>
                    </div>
                </form>

                <form id="workspace-context-reset-form" method="POST" action="" class="general-settings-followup-form" style="border: 1px solid #fed7aa; background: #fff7ed; margin-bottom: 0;">
                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                    <input type="hidden" name="tab" value="general">
                    <input type="hidden" name="danger_action" value="reset_company_context">
                    <h4 style="margin: 0 0 0.5rem 0; color: #9a3412; font-size: 1rem;">Workspace Company Context Reset</h4>
                    <p style="margin: 0 0 0.75rem 0; color: #9a3412; font-size: 0.875rem;">
                        Clears this workspace's business context and AI history for a fresh operating baseline. Settings, controls, integrations, workspace membership, and platform configuration stay in place.
                    </p>
                    <label for="danger_context_confirm_text" style="display: block; margin-bottom: 0.4rem; color: #9a3412; font-weight: 600; font-size: 0.8125rem;">
                        Type <code>RESET CONTEXT</code> to confirm
                    </label>
                    <input
                        type="text"
                        id="danger_context_confirm_text"
                        name="danger_confirm_text"
                        placeholder="RESET CONTEXT"
                        autocomplete="off"
                        style="width: 100%; max-width: 260px; padding: 0.55rem 0.7rem; border: 1px solid #fdba74; border-radius: 6px; margin-bottom: 0.75rem; background: #fff;"
                        required
                    >
                    <div>
                        <button type="submit" style="background: #ea580c; color: #fff; border: none; border-radius: 6px; padding: 0.55rem 0.9rem; font-weight: 600; cursor: pointer;">
                            Reset Workspace Context Only
                        </button>
                    </div>
                </form>
                <?php endif; ?>

                <?php if ($canPlatformSystemReset): ?>
                <form id="platform-data-reset-form" method="POST" action="" class="general-settings-followup-form" style="border: 1px solid #7f1d1d; background: #fef2f2; margin-top: 1rem; margin-bottom: 0;">
                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                    <input type="hidden" name="tab" value="general">
                    <input type="hidden" name="danger_action" value="reset_platform_data">
                    <h4 style="margin: 0 0 0.5rem 0; color: #7f1d1d; font-size: 1rem;">Super Admin Platform Reset</h4>
                    <p style="margin: 0 0 0.75rem 0; color: #7f1d1d; font-size: 0.875rem;">
                        Clears business/runtime data across all workspaces and resets workspace governance, including workspaces, memberships, invites, and slugs. Bootstrap login records are preserved.
                    </p>
                    <label for="danger_platform_confirm_text" style="display: block; margin-bottom: 0.4rem; color: #7f1d1d; font-weight: 600; font-size: 0.8125rem;">
                        Type <code>RESET PLATFORM</code> to confirm
                    </label>
                    <input
                        type="text"
                        id="danger_platform_confirm_text"
                        name="danger_confirm_text"
                        placeholder="RESET PLATFORM"
                        autocomplete="off"
                        style="width: 100%; max-width: 260px; padding: 0.55rem 0.7rem; border: 1px solid #ef4444; border-radius: 6px; margin-bottom: 0.75rem; background: #fff;"
                        required
                    >
                    <div>
                        <button type="submit" style="background: #991b1b; color: #fff; border: none; border-radius: 6px; padding: 0.55rem 0.9rem; font-weight: 600; cursor: pointer;">
                            Reset Entire Platform
                        </button>
                    </div>
                </form>
                <?php endif; ?>
            </section>
            <?php endif; ?>
            
        <?php elseif ($activeTab === 'email'): ?>
            <form method="POST" action="" style="display: flex; flex-direction: column; gap: var(--spacing-lg); max-width: 980px;" data-api-base="<?php echo htmlspecialchars($emailSettingsApiBase); ?>">
                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                <input type="hidden" name="tab" value="email">
                <input type="hidden" name="email_section" value="<?php echo htmlspecialchars($emailSettingsSection); ?>">
                <?php $emailWarmupLocked = $autoAdminEnabled && !empty($emailColdOutreachWarmup['auto_admin_warmup_enabled']); ?>
                <?php
                $emailSettingsSubtabs = [
                    'system' => ['label' => 'System Mail', 'description' => 'Global invites and notifications'],
                    'workspace' => ['label' => 'Workspace Email', 'description' => 'Marketplace mailbox readiness'],
                    'warmup' => ['label' => 'Warmup', 'description' => 'Cold outreach caps'],
                ];
                $workspaceEmailCards = [
                    [
                        'key' => 'outreach',
                        'label' => 'Outreach Email',
                        'description' => 'Sales and cold outreach messages from the Marketplace Email plugin.',
                        'summary' => $outreachEmailProviderSummary,
                        'health' => (array) ($workspaceEmailChannelHealth['outreach_email'] ?? []),
                        'ready' => $strictOutreachOutboundReady,
                        'url' => 'workspace_skills.php?module=email&setup_tab=outreach_email#setup',
                        'cta' => 'Open outreach setup',
                    ],
                    [
                        'key' => 'nurture',
                        'label' => 'Nurture Email',
                        'description' => 'Customer-care follow-up and nurture messages.',
                        'summary' => $nurtureEmailProviderSummary,
                        'health' => (array) ($workspaceEmailChannelHealth['nurture_email'] ?? []),
                        'ready' => $strictNurtureOutboundReady,
                        'url' => 'workspace_skills.php?module=email&setup_tab=nurture_email#setup',
                        'cta' => 'Open nurture setup',
                    ],
                    [
                        'key' => 'assistant',
                        'label' => 'Email Assistant',
                        'description' => 'Assistant mailbox, digests, and replies.',
                        'summary' => $assistantEmailProviderSummary,
                        'health' => (array) ($workspaceEmailChannelHealth['assistant_email'] ?? []),
                        'ready' => !empty($workspaceEmailChannelHealth['assistant_email']['outbound_ready'])
                            || in_array((string) (($workspaceEmailChannelHealth['assistant_email']['status'] ?? 'not_connected')), ['ready', 'warning'], true),
                        'url' => 'workspace_skills.php?module=email_assistant&setup_tab=identity#setup',
                        'cta' => 'Open assistant setup',
                    ],
                ];
                ?>

                <nav aria-label="Email settings sections" style="display:inline-flex;align-items:center;gap:.25rem;border:1px solid #dbeafe;background:#fff;border-radius:999px;padding:.25rem;align-self:flex-start;">
                    <?php foreach ($emailSettingsSubtabs as $sectionKey => $sectionMeta): ?>
                        <?php $sectionActive = $emailSettingsSection === $sectionKey; ?>
                        <a
                            href="?tab=email&amp;email_section=<?php echo urlencode((string) $sectionKey); ?>"
                            data-settings-email-section="<?php echo htmlspecialchars((string) $sectionKey); ?>"
                            aria-current="<?php echo $sectionActive ? 'page' : 'false'; ?>"
                            title="<?php echo htmlspecialchars((string) $sectionMeta['description']); ?>"
                            style="display:inline-flex;align-items:center;justify-content:center;border-radius:999px;padding:.45rem .75rem;text-decoration:none;font-size:13px;font-weight:800;color:<?php echo $sectionActive ? '#fff' : '#334155'; ?>;background:<?php echo $sectionActive ? '#0f172a' : 'transparent'; ?>;white-space:nowrap;"
                        ><?php echo htmlspecialchars((string) $sectionMeta['label']); ?></a>
                    <?php endforeach; ?>
                </nav>

                <section data-settings-email-panel="system" style="<?php echo $emailSettingsSection === 'system' ? 'display:grid;gap:var(--spacing-lg);' : 'display:none;'; ?>">
                <div style="background:#fff;border:1px solid #bfdbfe;border-radius:12px;padding:var(--spacing-md);display:grid;gap:1rem;">
                    <div style="display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;flex-wrap:wrap;">
                        <div>
                            <p style="margin:0 0 .25rem;color:#2563eb;font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;">Super Admin Email Hub</p>
                            <h2 style="margin:0;color:#0f172a;font-size:20px;line-height:1.2;">System Mail</h2>
                            <p style="margin:.4rem 0 0;color:#475569;font-size:13px;line-height:1.55;max-width:640px;">System Mail sends workspace invites and platform notifications. Marketplace email plugins are separate mailboxes for outreach, nurture, and assistants.</p>
                        </div>
                        <div style="display:grid;gap:.45rem;min-width:220px;">
                            <span style="display:inline-flex;align-items:center;justify-content:center;border-radius:999px;padding:.32rem .7rem;font-size:12px;font-weight:800;border:1px solid <?php echo $systemMailOutboundReady ? '#bbf7d0' : '#fed7aa'; ?>;background:<?php echo $systemMailOutboundReady ? '#f0fdf4' : '#fff7ed'; ?>;color:<?php echo $systemMailOutboundReady ? '#166534' : '#9a3412'; ?>;"><?php echo $systemMailOutboundReady ? 'System Mail ready' : 'System Mail needs setup'; ?></span>
                            <button type="button" onclick="testSMTP()" style="background:#2563eb;color:#fff;padding:.65rem .9rem;border:none;border-radius:8px;font-weight:700;cursor:pointer;">Test System Mail</button>
                        </div>
                    </div>
                    <?php if ($showCopyOutreachToSystem): ?>
                        <div style="display:flex;justify-content:space-between;gap:1rem;align-items:center;flex-wrap:wrap;padding:.85rem 1rem;border:1px solid #bfdbfe;border-radius:10px;background:#eff6ff;color:#1e3a8a;font-size:13px;">
                            <span><strong>Active workspace Outreach Email is ready, but System Mail is not.</strong> Copy that SMTP sender to send workspace invites now.</span>
                            <button type="submit" name="email_provider_action" value="copy_outreach_to_system" formnovalidate style="background:#0f172a;color:#fff;border:none;border-radius:8px;padding:.65rem .9rem;font-weight:800;cursor:pointer;">Use active Outreach SMTP for System Mail</button>
                        </div>
                    <?php elseif (!$systemMailOutboundReady): ?>
                        <div style="padding:.85rem 1rem;border:1px solid #fed7aa;border-radius:10px;background:#fff7ed;color:#9a3412;font-size:13px;line-height:1.5;">
                            System Mail is missing. Configure encrypted SMTP below, or save the active workspace Outreach Email SMTP settings first and copy them here.
                        </div>
                    <?php endif; ?>
                </div>
                </section>

                <section data-settings-email-panel="workspace" style="<?php echo $emailSettingsSection === 'workspace' ? 'display:grid;gap:var(--spacing-lg);' : 'display:none;'; ?>">
                    <div style="background:#fff;border:1px solid #dbeafe;border-radius:12px;padding:var(--spacing-md);display:grid;gap:1rem;">
                        <div>
                            <p style="margin:0 0 .25rem;color:#2563eb;font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;">Default Workspace Email</p>
                            <h2 style="margin:0;color:#0f172a;font-size:20px;line-height:1.2;">Workspace Email</h2>
                            <p style="margin:.4rem 0 0;color:#475569;font-size:13px;line-height:1.55;max-width:720px;">These mailboxes belong to <?php echo htmlspecialchars($activeWorkspaceName); ?> and are edited from Marketplace setup. Settings only summarizes readiness here so System Mail stays separate from workspace plugin mail.</p>
                        </div>
                        <div aria-label="How Email Works" style="display:grid;gap:.75rem;">
                            <h3 style="margin:0;color:#0f172a;font-size:15px;">How Email Works</h3>
                            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:.75rem;">
                                <div style="border:1px solid #e2e8f0;border-radius:10px;background:#f8fafc;padding:.85rem;display:grid;gap:.45rem;">
                                    <strong style="color:#0f172a;">System Mail</strong>
                                    <span style="color:#475569;font-size:12px;line-height:1.45;">Global workspace invites, admin notices, system notifications, and test email.</span>
                                    <span style="color:#64748b;font-size:11px;">Configured on the System Mail sub-tab.</span>
                                </div>
                                <?php foreach ($workspaceEmailCards as $card): ?>
                                    <?php
                                    $cardSummary = (array) ($card['summary'] ?? []);
                                    $cardHealth = (array) ($card['health'] ?? []);
                                    $cardReady = !empty($card['ready']);
                                    $cardInboundReady = !empty($cardHealth['inbound_ready'])
                                        || !empty($cardSummary['incoming_fallback_configured']);
                                    $cardBadge = $cardReady
                                        ? ($cardInboundReady ? 'Ready' : 'Sending ready')
                                        : 'Not configured';
                                    $cardBadgeOk = $cardReady && $cardInboundReady;
                                    $cardBadgeBg = $cardBadgeOk ? '#dcfce7' : ($cardReady ? '#fef3c7' : '#ffedd5');
                                    $cardBadgeColor = $cardBadgeOk ? '#166534' : ($cardReady ? '#92400e' : '#9a3412');
                                    $cardStatusText = trim((string) ($cardHealth['label'] ?? ''));
                                    ?>
                                    <div data-workspace-email-card="<?php echo htmlspecialchars((string) $card['key']); ?>" style="border:1px solid #e2e8f0;border-radius:10px;background:#f8fafc;padding:.85rem;display:grid;gap:.55rem;">
                                        <div style="display:flex;justify-content:space-between;gap:.5rem;align-items:flex-start;">
                                            <strong style="color:#0f172a;"><?php echo htmlspecialchars((string) $card['label']); ?></strong>
                                            <span style="border-radius:999px;padding:.16rem .5rem;font-size:11px;font-weight:800;background:<?php echo $cardBadgeBg; ?>;color:<?php echo $cardBadgeColor; ?>;"><?php echo htmlspecialchars($cardBadge); ?></span>
                                        </div>
                                        <span style="color:#475569;font-size:12px;line-height:1.45;"><?php echo htmlspecialchars((string) $card['description']); ?></span>
                                        <?php if ($cardStatusText !== ''): ?>
                                            <span style="color:#64748b;font-size:11px;">Status: <?php echo htmlspecialchars($cardStatusText); ?></span>
                                        <?php endif; ?>
                                        <span style="color:#64748b;font-size:11px;">Provider: <?php echo htmlspecialchars((string) ($cardSummary['provider_label'] ?? 'Manual SMTP / IMAP')); ?></span>
                                        <?php if (trim((string) ($cardSummary['connected_email'] ?? '')) !== ''): ?>
                                            <span style="color:#64748b;font-size:11px;">Mailbox: <?php echo htmlspecialchars((string) $cardSummary['connected_email']); ?></span>
                                        <?php endif; ?>
                                        <a href="<?php echo htmlspecialchars((string) $card['url']); ?>" style="display:inline-flex;align-items:center;justify-content:center;justify-self:start;border:1px solid #bfdbfe;border-radius:8px;padding:.5rem .75rem;color:#1d4ed8;text-decoration:none;font-size:12px;font-weight:800;"><?php echo htmlspecialchars((string) $card['cta']); ?></a>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </section>

                <section data-settings-email-panel="warmup" style="<?php echo $emailSettingsSection === 'warmup' ? 'display:grid;gap:var(--spacing-lg);' : 'display:none;'; ?>">
                <div style="background: #f8fafc; border: 1px solid #dbeafe; border-radius: 8px; padding: var(--spacing-md);">
                    <h3 style="color: var(--midnight-black); margin: 0 0 var(--spacing-xs); font-size: 16px;">Foundation Deliverability Guide</h3>
                    <p style="color: var(--charcoal-grey); font-size: 13px; margin: 0 0 var(--spacing-sm);">
                        Email warmup means building sender reputation gradually so emails land in inbox instead of spam.
                    </p>
                    <div style="font-size: 13px; color: var(--midnight-black);">
                        <p style="margin: 0 0 6px;"><strong>Worry level:</strong> low under ~30 emails/day to warm contacts; moderate around 50-150/day or heavy automation.</p>
                        <p style="margin: 0 0 6px;"><strong>Do first:</strong> SPF, DKIM, DMARC, and avoid spammy blast behavior.</p>
                        <p style="margin: 0 0 6px;"><strong>Safe ramp:</strong> week 1 = 10/day, week 2 = 20/day, week 3 = 30/day.</p>
                        <p style="margin: 0;"><strong>Protect brand:</strong> use a separate sending domain/subdomain (example: <code>mail.yourdomain.com</code>) for outreach.</p>
                    </div>
                </div>

                <div style="background:#fff;border:1px solid #dbeafe;border-radius:12px;padding:var(--spacing-md);display:grid;gap:.75rem;">
                    <div>
                        <h3 style="margin:0;color:#0f172a;font-size:16px;">Cold Outreach Warmup</h3>
                        <p style="margin:.35rem 0 0;color:#64748b;font-size:13px;">Cap first-time cold emails per day and let Auto Admin raise the current cap slowly when delivery stays healthy.</p>
                    </div>
                    <label style="display:flex;align-items:center;gap:.6rem;">
                        <input type="checkbox" name="email_cold_outreach_enabled" value="1" <?php echo !empty($emailColdOutreachWarmup['enabled']) ? 'checked' : ''; ?> <?php echo $emailWarmupLocked ? 'disabled' : ''; ?> style="width:18px;height:18px;">
                        <span style="font-weight:600;color:#0f172a;">Enable email cold outreach cap</span>
                    </label>
                    <label style="display:flex;align-items:center;gap:.6rem;">
                        <input type="checkbox" name="email_auto_admin_warmup_enabled" value="1" <?php echo !empty($emailColdOutreachWarmup['auto_admin_warmup_enabled']) ? 'checked' : ''; ?> <?php echo $emailWarmupLocked ? 'disabled' : ''; ?> style="width:18px;height:18px;">
                        <span style="font-weight:600;color:#0f172a;">Let Auto Admin increase the email cap gradually</span>
                    </label>
                    <?php if ($emailWarmupLocked): ?>
                        <div style="padding:.7rem .85rem;border:1px solid #a7f3d0;border-radius:10px;background:#f0fdf4;color:#166534;font-size:12px;">
                            Auto Admin warmup is managing the email warmup toggle and numeric values below. Turn off Auto Admin from AI Services to restore manual control.
                        </div>
                    <?php endif; ?>
                    <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.75rem;">
                        <div>
                            <label for="email_initial_daily_cold_limit" style="display:block;margin-bottom:.25rem;font-weight:500;">Initial daily cold limit</label>
                            <input type="number" min="0" id="email_initial_daily_cold_limit" name="email_initial_daily_cold_limit" value="<?php echo (int) ($emailColdOutreachWarmup['initial_daily_cold_limit'] ?? 0); ?>" <?php echo $emailWarmupLocked ? 'disabled' : ''; ?> style="width:100%;padding:var(--spacing-sm);border:1px solid var(--border-color);border-radius:4px;">
                        </div>
                        <div>
                            <label for="email_current_daily_cold_limit" style="display:block;margin-bottom:.25rem;font-weight:500;">Current daily cold limit</label>
                            <input type="number" min="0" id="email_current_daily_cold_limit" name="email_current_daily_cold_limit" value="<?php echo (int) ($emailColdOutreachWarmup['current_daily_cold_limit'] ?? 0); ?>" <?php echo $emailWarmupLocked ? 'disabled' : ''; ?> style="width:100%;padding:var(--spacing-sm);border:1px solid var(--border-color);border-radius:4px;">
                        </div>
                        <div>
                            <label for="email_weekly_increment" style="display:block;margin-bottom:.25rem;font-weight:500;">Weekly increment</label>
                            <input type="number" min="0" id="email_weekly_increment" name="email_weekly_increment" value="<?php echo (int) ($emailColdOutreachWarmup['weekly_increment'] ?? 0); ?>" <?php echo $emailWarmupLocked ? 'disabled' : ''; ?> style="width:100%;padding:var(--spacing-sm);border:1px solid var(--border-color);border-radius:4px;">
                        </div>
                        <div>
                            <label for="email_max_limit" style="display:block;margin-bottom:.25rem;font-weight:500;">Max limit</label>
                            <input type="number" min="0" id="email_max_limit" name="email_max_limit" value="<?php echo (int) ($emailColdOutreachWarmup['max_limit'] ?? 0); ?>" <?php echo $emailWarmupLocked ? 'disabled' : ''; ?> style="width:100%;padding:var(--spacing-sm);border:1px solid var(--border-color);border-radius:4px;">
                        </div>
                    </div>
                    <div style="font-size:12px;color:#475569;">
                        Today used or reserved: <strong><?php echo (int) ($emailColdOutreachUsage['reserved_or_sent'] ?? 0); ?></strong>
                    </div>
                </div>
                </section>

                <section data-settings-email-panel="system" style="<?php echo $emailSettingsSection === 'system' ? 'display:grid;gap:var(--spacing-lg);' : 'display:none;'; ?>">
                <?php if ($isSuperAdmin): ?>
                <div style="background:#fff;border:1px solid #dbeafe;border-radius:12px;padding:var(--spacing-md);display:grid;gap:1rem;">
                    <div style="display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;flex-wrap:wrap;">
                        <div>
                            <h3 style="margin:0;color:#0f172a;font-size:16px;">System Mail Source</h3>
                            <p style="margin:.35rem 0 0;color:#64748b;font-size:13px;">System Mail uses the encrypted platform SMTP/IMAP settings below for workspace invites, platform notifications, and System Mail tests. Legacy `.env` SMTP and workspace email plugin settings are not used unless you explicitly copy Outreach into System Mail.</p>
                        </div>
                        <div style="display:grid;gap:.5rem;min-width:220px;">
                            <div style="font-size:12px;color:#475569;">
                                Active source: <strong>Platform System Mail</strong>
                            </div>
                            <div style="font-size:12px;color:#475569;">
                                Readiness: <strong><?php echo htmlspecialchars(ucfirst((string) ($mainEmailProviderSummary['readiness'] ?? 'blocked'))); ?></strong>
                            </div>
                            <div style="font-size:12px;color:#475569;">
                                SMTP: <strong><?php echo !empty($mainEmailProviderSummary['smtp_fallback_configured']) ? 'Configured' : 'Not fully configured'; ?></strong>
                            </div>
                            <div style="font-size:12px;color:#475569;">
                                IMAP: <strong><?php echo !empty($mainEmailProviderSummary['incoming_fallback_configured']) ? 'Configured' : 'Not fully configured'; ?></strong>
                            </div>
                        </div>
                    </div>

                    <?php if (!empty($mainEmailProviderSummary['issues'])): ?>
                        <div style="padding:.85rem 1rem;border-radius:10px;background:#fff7ed;border:1px solid #fdba74;font-size:12px;color:#9a3412;line-height:1.6;">
                            <?php echo htmlspecialchars(implode(' ', array_map(static function (array $issue): string {
                                return (string) ($issue['message'] ?? '');
                            }, (array) ($mainEmailProviderSummary['issues'] ?? [])))); ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($legacySystemMailEnvKeys !== [] || $legacyAssistantMailEnvKeys !== []): ?>
                        <div style="padding:.85rem 1rem;border-radius:10px;background:#f8fafc;border:1px solid #cbd5e1;font-size:12px;color:#475569;line-height:1.6;">
                            <strong style="display:block;color:#0f172a;margin-bottom:.2rem;">Legacy config ignored</strong>
                            <?php if ($legacySystemMailEnvKeys !== []): ?>
                                <div>System Mail ignores legacy `.env` keys: <?php echo htmlspecialchars(implode(', ', $legacySystemMailEnvKeys)); ?>.</div>
                            <?php endif; ?>
                            <?php if ($legacyAssistantMailEnvKeys !== []): ?>
                                <div>Email Assistant mailbox setup ignores legacy `.env` keys: <?php echo htmlspecialchars(implode(', ', $legacyAssistantMailEnvKeys)); ?>. Configure assistant mail from Marketplace.</div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <div style="border:1px solid #ccfbf1;border-radius:10px;background:#f0fdfa;padding:1rem;display:flex;justify-content:space-between;gap:1rem;align-items:center;flex-wrap:wrap;">
                        <div>
                            <h3 style="margin:0;color:#0f172a;font-size:15px;">Google OAuth clients are managed in Google Services</h3>
                            <p style="margin:.35rem 0 0;color:#475569;font-size:12px;line-height:1.5;">Gmail Sending, Gmail Inbox Sync, and Assistant Gmail use separated Google grants from the central Super Admin setup.</p>
                        </div>
                        <?php if ($isSuperAdmin): ?>
                            <a href="settings.php?tab=google_services" style="display:inline-flex;align-items:center;justify-content:center;border:1px solid #99f6e4;border-radius:8px;background:#fff;color:#0f766e;text-decoration:none;padding:.65rem .9rem;font-weight:800;font-size:12px;">Open Google Services</a>
                        <?php endif; ?>
                    </div>
                </div>

                <details open style="background:#fff;border:1px solid var(--border-color);border-radius:12px;padding:var(--spacing-md);">
                    <summary style="cursor:pointer;color:#0f172a;font-size:16px;font-weight:800;">Advanced manual SMTP/IMAP</summary>
                    <p style="margin:.55rem 0 0;color:#64748b;font-size:13px;">Use these encrypted platform server settings for System Mail.</p>
                    <div style="margin-top:.75rem;display:grid;gap:.5rem;max-width:320px;">
                        <label for="mail_provider_preset" style="display:block;font-weight:500;color:#0f172a;">Preset guidance</label>
                        <select id="mail_provider_preset" onchange="applyMailProviderPreset()" style="width:100%;padding:var(--spacing-sm);border:1px solid var(--border-color);border-radius:4px;">
                            <option value="">Choose a preset</option>
                            <option value="gmail">Gmail SMTP + IMAP</option>
                            <option value="google_workspace">Google Workspace SMTP + IMAP</option>
                            <option value="custom">Generic custom SMTP / IMAP</option>
                        </select>
                        <div style="font-size:12px;color:#64748b;">Presets only fill host, port, and encryption hints. Stored credentials are encrypted platform defaults and are not written to `.env`.</div>
                    </div>
                    <div style="display:grid;gap:var(--spacing-lg);margin-top:var(--spacing-lg);">
                
                <div>
                    <label for="smtp_host" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">SMTP Host</label>
                    <input 
                        type="text" 
                        id="smtp_host" 
                        name="smtp_host" 
                        value="<?php echo htmlspecialchars((string) ($platformMainEmailSettings['smtp_host'] ?? '')); ?>"
                        placeholder="smtp.example.com"
                        style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                    >
                </div>
                
                <div>
                    <label for="smtp_port" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">SMTP Port</label>
                    <input 
                        type="number" 
                        id="smtp_port" 
                        name="smtp_port" 
                        value="<?php echo htmlspecialchars((string) ($platformMainEmailSettings['smtp_port'] ?? '587')); ?>"
                        style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                        onchange="updateEncryptionHint()"
                    >
                    <small style="color: var(--charcoal-grey); font-size: 12px; margin-top: 4px; display: block;">
                        Port 465 = SSL, Port 587 = TLS
                    </small>
                </div>
                
                <div>
                    <label for="smtp_encryption" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Encryption</label>
                    <select 
                        id="smtp_encryption" 
                        name="smtp_encryption" 
                        style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                    >
                        <option value="tls" <?php echo (($platformMainEmailSettings['smtp_encryption'] ?? 'tls') === 'tls') ? 'selected' : ''; ?>>TLS (Port 587)</option>
                        <option value="ssl" <?php echo (($platformMainEmailSettings['smtp_encryption'] ?? 'tls') === 'ssl') ? 'selected' : ''; ?>>SSL (Port 465)</option>
                    </select>
                </div>
                
                <div>
                    <label for="smtp_user" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">SMTP Username</label>
                    <input 
                        type="text" 
                        id="smtp_user" 
                        name="smtp_user" 
                        value="<?php echo htmlspecialchars((string) ($platformMainEmailSettings['smtp_username'] ?? $platformMainEmailSettings['from_email'] ?? '')); ?>"
                        style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                    >
                </div>
                
                <div>
                    <label for="smtp_pass" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">SMTP Password</label>
                    <input 
                        type="password" 
                        id="smtp_pass" 
                        name="smtp_pass" 
                        value=""
                        placeholder="<?php echo array_key_exists('smtp_password', $platformMainEmailSettings) ? 'Saved password' : ''; ?>"
                        style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                    >
                </div>
                
                <div>
                    <label for="smtp_from_email" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">From Email</label>
                    <input 
                        type="email" 
                        id="smtp_from_email" 
                        name="smtp_from_email" 
                        value="<?php echo htmlspecialchars((string) ($platformMainEmailSettings['from_email'] ?? '')); ?>"
                        placeholder="noreply@example.com"
                        style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                    >
                </div>
                
                <div>
                    <label for="smtp_from_name" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">From Name</label>
                    <input 
                        type="text" 
                        id="smtp_from_name" 
                        name="smtp_from_name" 
                        value="<?php echo htmlspecialchars((string) ($platformMainEmailSettings['from_name'] ?? brandProductName())); ?>"
                        style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                    >
                </div>
                
                <!-- Incoming Email Configuration -->
                <div style="margin-top: var(--spacing-xl); padding-top: var(--spacing-xl); border-top: 2px solid var(--border-color);">
                    <h3 style="color: var(--midnight-black); margin-bottom: var(--spacing-md); font-size: 18px;">Incoming Email (IMAP/POP3) Configuration</h3>
                    
                    <div>
                        <label style="display: flex; align-items: center; gap: var(--spacing-sm); margin-bottom: var(--spacing-md);">
                            <input 
                                type="checkbox" 
                                name="imap_enabled" 
                                <?php echo !empty($platformMainEmailSettings['imap_enabled']) ? 'checked' : ''; ?>
                                style="width: 18px; height: 18px;"
                            >
                            <span style="color: var(--midnight-black); font-weight: 500;">Enable Incoming Email Fetching</span>
                        </label>
                        <small style="color: var(--charcoal-grey); font-size: 12px; display: block; margin-top: var(--spacing-xs);">
                            Enable this to automatically fetch incoming emails and add them to your inbox
                        </small>
                    </div>
                    
                    <div>
                        <label for="imap_protocol" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Protocol</label>
                        <select 
                            id="imap_protocol" 
                            name="imap_protocol" 
                            style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                            onchange="updateImapPort()"
                        >
                            <option value="imap" <?php echo (($platformMainEmailSettings['imap_protocol'] ?? 'imap') === 'imap') ? 'selected' : ''; ?>>IMAP</option>
                            <option value="pop3" <?php echo (($platformMainEmailSettings['imap_protocol'] ?? 'imap') === 'pop3') ? 'selected' : ''; ?>>POP3</option>
                        </select>
                    </div>
                    
                    <div>
                        <label for="imap_host" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">IMAP/POP3 Host</label>
                        <input 
                            type="text" 
                            id="imap_host" 
                            name="imap_host" 
                            value="<?php echo htmlspecialchars((string) ($platformMainEmailSettings['imap_host'] ?? '')); ?>"
                            placeholder="imap.example.com or pop3.example.com"
                            style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                        >
                    </div>
                    
                    <div>
                        <label for="imap_port" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">IMAP/POP3 Port</label>
                        <input 
                            type="number" 
                            id="imap_port" 
                            name="imap_port" 
                            value="<?php echo htmlspecialchars((string) ($platformMainEmailSettings['imap_port'] ?? '993')); ?>"
                            style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                            onchange="updateImapEncryptionHint()"
                        >
                        <small style="color: var(--charcoal-grey); font-size: 12px; margin-top: 4px; display: block;">
                            IMAP: 993 (SSL), 143 (TLS/None) | POP3: 995 (SSL), 110 (TLS/None)
                        </small>
                    </div>
                    
                    <div>
                        <label for="imap_encryption" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Encryption</label>
                        <select 
                            id="imap_encryption" 
                            name="imap_encryption" 
                            style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                        >
                            <option value="ssl" <?php echo (($platformMainEmailSettings['imap_encryption'] ?? 'ssl') === 'ssl') ? 'selected' : ''; ?>>SSL</option>
                            <option value="tls" <?php echo (($platformMainEmailSettings['imap_encryption'] ?? 'ssl') === 'tls') ? 'selected' : ''; ?>>TLS</option>
                            <option value="none" <?php echo (($platformMainEmailSettings['imap_encryption'] ?? 'ssl') === 'none') ? 'selected' : ''; ?>>None</option>
                        </select>
                    </div>
                    
                    <div>
                        <label for="imap_user" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">IMAP/POP3 Username</label>
                        <input 
                            type="text" 
                            id="imap_user" 
                            name="imap_user" 
                            value="<?php echo htmlspecialchars((string) ($platformMainEmailSettings['imap_username'] ?? (!empty($platformMainEmailSettings['imap_enabled']) ? ($platformMainEmailSettings['from_email'] ?? '') : ''))); ?>"
                            placeholder="Leave empty to use SMTP username"
                            style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                        >
                        <small style="color: var(--charcoal-grey); font-size: 12px; margin-top: 4px; display: block;">
                            Leave empty to use SMTP username
                        </small>
                    </div>
                    
                    <div>
                        <label for="imap_pass" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">IMAP/POP3 Password</label>
                        <input 
                            type="password" 
                            id="imap_pass" 
                            name="imap_pass" 
                            value=""
                            placeholder="<?php echo array_key_exists('imap_password', $platformMainEmailSettings) ? 'Saved password' : 'Leave empty to use SMTP password'; ?>"
                            style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                        >
                        <small style="color: var(--charcoal-grey); font-size: 12px; margin-top: 4px; display: block;">
                            Leave empty to use SMTP password
                        </small>
                    </div>
                    
                    <div>
                        <label for="imap_folder" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Mailbox Folder</label>
                        <input 
                            type="text" 
                            id="imap_folder" 
                            name="imap_folder" 
                            value="<?php echo htmlspecialchars((string) ($platformMainEmailSettings['imap_folder'] ?? 'INBOX')); ?>"
                            placeholder="INBOX"
                            style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                        >
                        <small style="color: var(--charcoal-grey); font-size: 12px; margin-top: 4px; display: block;">
                            Default: INBOX (for POP3, this is ignored)
                        </small>
                    </div>
                    
                    <div>
                        <label for="imap_fetch_interval" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Fetch Interval (minutes)</label>
                        <input 
                            type="number" 
                            id="imap_fetch_interval" 
                            name="imap_fetch_interval" 
                            value="<?php echo htmlspecialchars($_ENV['IMAP_FETCH_INTERVAL'] ?? '5'); ?>"
                            min="1"
                            max="60"
                            style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                        >
                        <small style="color: var(--charcoal-grey); font-size: 12px; margin-top: 4px; display: block;">
                            How often to check for new emails (1-60 minutes). Set up a cron job to run cli/fetch_incoming_emails.php
                        </small>
                    </div>
                </div>
                
                    </div>
                </details>

                <?php else: ?>
                    <div class="content-card" style="background:#f8fafc;border:1px solid #e2e8f0;color:#475569;font-size:.9rem;line-height:1.55;">
                        Mailbox connection is handled by the Email setup card in this section. SMTP, IMAP, OAuth client IDs, and client secrets are platform settings managed by Super Admin.
                    </div>
                <?php endif; ?>
                </section>

                <?php if ($emailSettingsSection !== 'workspace'): ?>
                <div style="display: flex; gap: var(--spacing-md); justify-content: flex-end; margin-top: var(--spacing-md);">
                    <?php if ($isSuperAdmin && $emailSettingsSection === 'system'): ?>
                    <button 
                        type="button"
                        onclick="fetchEmailsNow()"
                        id="fetch-emails-btn"
                        style="background: #17a2b8; color: white; padding: var(--spacing-sm) var(--spacing-lg); border: none; border-radius: 4px; font-weight: 500; cursor: pointer;"
                    >
                        📥 Fetch Emails Now
                    </button>
                    <button 
                        type="button"
                        onclick="testSMTP()"
                        id="test-smtp-btn"
                        style="background: #28a745; color: white; padding: var(--spacing-sm) var(--spacing-lg); border: none; border-radius: 4px; font-weight: 500; cursor: pointer;"
                    >
                        Test System Mail
                    </button>
                    <?php endif; ?>
                <button type="submit" class="btn-premium-primary">
                        Save Settings
                    </button>
                </div>
                <?php endif; ?>
                <div id="fetch-emails-result" style="margin-top: var(--spacing-md);"></div>
            </form>
            
            <!-- SMTP Test Modal -->
            <div id="smtp-test-modal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
                <div style="background: white; padding: var(--spacing-xl); border-radius: 8px; max-width: 500px; width: 90%;">
                    <h2 style="margin-top: 0; margin-bottom: var(--spacing-md);">Test System Mail</h2>
                    <div id="smtp-test-result" style="margin-bottom: var(--spacing-md);"></div>
                    <div>
                        <label for="test-email" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Test Email Address</label>
                        <input 
                            type="email" 
                            id="test-email" 
                            placeholder="your-email@example.com"
                            style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px; margin-bottom: var(--spacing-md);"
                        >
                    </div>
                    <div style="display: flex; gap: var(--spacing-sm); justify-content: flex-end;">
                        <button 
                            onclick="closeSMTPTest()"
                            style="background: var(--light-grey); color: var(--charcoal-grey); padding: var(--spacing-sm) var(--spacing-md); border: none; border-radius: 4px; cursor: pointer;"
                        >
                            Close
                        </button>
                        <button 
                            onclick="sendSMTPTest()"
                            id="send-test-btn"
                            class="btn-premium-primary"
                        >
                            Send Test Email
                        </button>
                    </div>
                </div>
            </div>
            
        <?php elseif ($activeTab === 'whatsapp'): ?>
            <?php
            $whatsappWarmupLocked = $autoAdminEnabled && !empty($whatsappColdOutreachWarmup['auto_admin_warmup_enabled']);
            $whatsAppChannelHub = (array) ($whatsAppHubContext['channel'] ?? []);
            $whatsAppAssistantHub = (array) ($whatsAppHubContext['assistant'] ?? []);
            $whatsAppHubAvailable = !empty($whatsAppHubContext);
            $whatsAppPlatformStatus = [
                'Phone Number ID' => trim((string) ($_ENV['WHATSAPP_PHONE_NUMBER_ID'] ?? '')) !== '',
                'Access Token' => trim((string) ($_ENV['WHATSAPP_ACCESS_TOKEN'] ?? '')) !== '',
                'WABA ID' => trim((string) ($_ENV['WHATSAPP_BUSINESS_ACCOUNT_ID'] ?? '')) !== '',
                'Meta App' => trim((string) ($_ENV['META_APP_ID'] ?? '')) !== '',
            ];
            $whatsAppPanelAttr = static fn(string $tabKey): string => $activeWhatsAppSettingsTab === $tabKey ? '' : ' hidden';
            $whatsAppSettingsTabUrl = static function (string $tabKey) use ($whatsAppChannelHub, $whatsAppAssistantHub): string {
                $params = ['tab' => 'whatsapp', 'whatsapp_tab' => $tabKey];
                if ($tabKey === 'connection') {
                    $params['setup_module'] = 'whatsapp';
                    $channelSetupTab = (string) ($whatsAppChannelHub['active_tab'] ?? 'manual');
                    $params['setup_tab'] = $channelSetupTab === 'activity' ? 'manual' : $channelSetupTab;
                } elseif ($tabKey === 'assistant') {
                    $params['setup_module'] = 'whatsapp_assistant';
                    $assistantSetupTab = (string) ($whatsAppAssistantHub['active_tab'] ?? 'identity');
                    $params['setup_tab'] = $assistantSetupTab === 'activity' ? 'identity' : $assistantSetupTab;
                }
                return 'settings.php?' . http_build_query($params);
            };
            $whatsAppChannelReadiness = (array) ($whatsAppChannelHub['readiness'] ?? []);
            $whatsAppAssistantReadiness = (array) ($whatsAppAssistantHub['readiness'] ?? []);
            $whatsAppChannelEvents = (array) ($whatsAppChannelHub['events'] ?? []);
            $whatsAppAssistantEvents = (array) ($whatsAppAssistantHub['events'] ?? []);
            $whatsAppChannelStatusLabel = !empty($whatsAppHubContext['whatsapp_installed'])
                ? (!empty($whatsAppChannelReadiness['ready']) ? 'Ready' : 'Needs setup')
                : 'Install required';
            $whatsAppAssistantStatusLabel = !empty($whatsAppHubContext['assistant_installed'])
                ? (!empty($whatsAppAssistantReadiness['ready']) ? 'Ready' : 'Needs setup')
                : 'Install required';
            ?>
            <div class="whatsapp-settings-hub">
                <div class="whatsapp-settings-hero">
                    <div class="whatsapp-settings-hero__top">
                        <div>
                            <h2>WhatsApp Setup Hub</h2>
                            <p>
                                Manage platform credentials, workspace connection, webhook, templates, credits, migration, assistant controls, tests, activity, and cold outreach warmup from one superadmin surface.
                            </p>
                        </div>
                        <span class="whatsapp-settings-workspace-pill">
                            <?php echo htmlspecialchars($activeWorkspaceName); ?>
                        </span>
                    </div>
                    <div class="whatsapp-platform-status-row">
                        <?php foreach ($whatsAppPlatformStatus as $label => $ready): ?>
                            <span class="whatsapp-status-pill <?php echo $ready ? 'whatsapp-status-pill--ready' : ''; ?>">
                                <?php echo htmlspecialchars($label); ?>: <?php echo $ready ? 'set' : 'missing'; ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>

                <nav class="whatsapp-settings-tabs" aria-label="WhatsApp settings sections">
                    <?php foreach ($whatsAppSettingsTabs as $tabKey => $tabLabel): ?>
                        <a href="<?php echo htmlspecialchars($whatsAppSettingsTabUrl((string) $tabKey)); ?>"
                           class="whatsapp-settings-tab <?php echo $activeWhatsAppSettingsTab === $tabKey ? 'is-active' : ''; ?>"
                           <?php echo $activeWhatsAppSettingsTab === $tabKey ? 'aria-current="page"' : ''; ?>
                           aria-selected="<?php echo $activeWhatsAppSettingsTab === $tabKey ? 'true' : 'false'; ?>">
                            <?php echo htmlspecialchars((string) $tabLabel); ?>
                        </a>
                    <?php endforeach; ?>
                </nav>

                <section class="whatsapp-settings-panel" data-whatsapp-settings-panel="overview"<?php echo $whatsAppPanelAttr('overview'); ?>>
                    <div class="content-card whatsapp-overview-card">
                        <div class="whatsapp-section-head">
                            <h3>WhatsApp Overview</h3>
                            <p>A quick read on platform credentials, workspace connection, assistant setup, and warmup guardrails.</p>
                        </div>
                        <div class="whatsapp-overview-grid">
                            <div class="whatsapp-overview-tile">
                                <strong>Platform</strong>
                                <p><?php echo count(array_filter($whatsAppPlatformStatus)); ?> / <?php echo count($whatsAppPlatformStatus); ?> required signals are set.</p>
                                <a class="btn-premium-secondary" href="<?php echo htmlspecialchars($whatsAppSettingsTabUrl('platform')); ?>" style="text-decoration:none;font-size:.82rem;">Open platform</a>
                            </div>
                            <div class="whatsapp-overview-tile">
                                <strong>Workspace Connection</strong>
                                <p><?php echo htmlspecialchars($whatsAppChannelStatusLabel); ?></p>
                                <a class="btn-premium-secondary" href="<?php echo htmlspecialchars($whatsAppSettingsTabUrl('connection')); ?>" style="text-decoration:none;font-size:.82rem;">Open connection</a>
                            </div>
                            <div class="whatsapp-overview-tile">
                                <strong>WhatsApp Assistant</strong>
                                <p><?php echo htmlspecialchars($whatsAppAssistantStatusLabel); ?></p>
                                <a class="btn-premium-secondary" href="<?php echo htmlspecialchars($whatsAppSettingsTabUrl('assistant')); ?>" style="text-decoration:none;font-size:.82rem;">Open assistant</a>
                            </div>
                            <div class="whatsapp-overview-tile">
                                <strong>Guardrails</strong>
                                <p>Daily cap: <?php echo (int) ($whatsappColdOutreachWarmup['current_daily_cold_limit'] ?? 0); ?>. Used or reserved today: <?php echo (int) ($whatsappColdOutreachUsage['reserved_or_sent'] ?? 0); ?>.</p>
                                <a class="btn-premium-secondary" href="<?php echo htmlspecialchars($whatsAppSettingsTabUrl('guardrails')); ?>" style="text-decoration:none;font-size:.82rem;">Open guardrails</a>
                            </div>
                        </div>
                    </div>
                </section>

                <section class="whatsapp-settings-panel" data-whatsapp-settings-panel="platform"<?php echo $whatsAppPanelAttr('platform'); ?>>
                    <?php if ($isSuperAdmin): ?>
                        <form class="whatsapp-settings-form" method="POST" action="settings.php?tab=whatsapp&amp;whatsapp_tab=platform">
                            <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                            <input type="hidden" name="tab" value="whatsapp">
                            <input type="hidden" name="whatsapp_tab" value="platform">
                            <input type="hidden" name="whatsapp_settings_section" value="platform">
                            <section class="whatsapp-config-panel">
                                <div class="whatsapp-section-head">
                                    <h3>Platform Credentials</h3>
                                    <p>Global Meta and WhatsApp Business API credentials used by the platform.</p>
                                </div>
                                <div class="whatsapp-form-grid">
                                    <div class="whatsapp-form-field">
                                        <label for="whatsapp_phone_number_id">WhatsApp Phone Number ID</label>
                                        <div class="whatsapp-inline-control">
                                            <input type="text" id="whatsapp_phone_number_id" name="whatsapp_phone_number_id" value="<?php echo htmlspecialchars($_ENV['WHATSAPP_PHONE_NUMBER_ID'] ?? ''); ?>" placeholder="123456789012345" pattern="[0-9]+">
                                            <button type="button" onclick="autoDetectPhoneNumbers()" id="auto-detect-phone-btn" class="btn-premium-secondary">Detect</button>
                                        </div>
                                    </div>
                                    <div class="whatsapp-form-field">
                                        <label for="whatsapp_business_account_id">WABA ID</label>
                                        <div class="whatsapp-inline-control">
                                            <input type="text" id="whatsapp_business_account_id" name="whatsapp_business_account_id" value="<?php echo htmlspecialchars($_ENV['WHATSAPP_BUSINESS_ACCOUNT_ID'] ?? ''); ?>" placeholder="123456789012345" pattern="[0-9]+">
                                            <button type="button" onclick="autoDetectWabaId()" id="auto-detect-waba-btn" class="btn-premium-secondary">Detect</button>
                                        </div>
                                    </div>
                                    <div class="whatsapp-form-field">
                                        <label for="whatsapp_access_token">WhatsApp Access Token</label>
                                        <input type="password" id="whatsapp_access_token" name="whatsapp_access_token" value="<?php echo htmlspecialchars($_ENV['WHATSAPP_ACCESS_TOKEN'] ?? ''); ?>" placeholder="Permanent Meta token" autocomplete="new-password">
                                    </div>
                                    <div class="whatsapp-form-field">
                                        <label for="meta_app_id">Meta App ID</label>
                                        <input type="text" id="meta_app_id" name="meta_app_id" value="<?php echo htmlspecialchars($_ENV['META_APP_ID'] ?? ''); ?>" placeholder="123456789012345" pattern="[0-9]+">
                                    </div>
                                    <div class="whatsapp-form-field">
                                        <label for="meta_app_secret">Meta App Secret</label>
                                        <input type="password" id="meta_app_secret" name="meta_app_secret" value="" placeholder="<?php echo !empty($_ENV['META_APP_SECRET']) ? 'Already set (enter again to change)' : 'Enter Meta app secret'; ?>" autocomplete="new-password">
                                    </div>
                                    <div class="whatsapp-form-field">
                                        <label for="meta_app_access_token">Meta App Access Token</label>
                                        <input type="password" id="meta_app_access_token" name="meta_app_access_token" value="" placeholder="<?php echo !empty($_ENV['META_APP_ACCESS_TOKEN']) ? 'Already set (enter again to change)' : 'Optional app-level token'; ?>" autocomplete="new-password">
                                    </div>
                                    <div class="whatsapp-form-field">
                                        <label for="meta_whatsapp_embedded_signup_config_id">Embedded Signup Config ID</label>
                                        <input type="text" id="meta_whatsapp_embedded_signup_config_id" name="meta_whatsapp_embedded_signup_config_id" value="<?php echo htmlspecialchars($_ENV['META_WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID'] ?? ''); ?>" placeholder="Optional Meta signup config">
                                    </div>
                                </div>
                            </section>
                            <div class="whatsapp-platform-grid">
                                <section class="whatsapp-config-panel whatsapp-config-panel--blue">
                                    <div class="whatsapp-subpanel-head">
                                        <strong>Webhook subscription</strong>
                                        <button type="button" onclick="refreshWebhookStatus()" class="btn-premium-secondary" style="font-size:.82rem;">Refresh status</button>
                                    </div>
                                    <div id="webhook-status-display">
                                        <p style="color:#475569;font-size:.85rem;margin:0;">Loading webhook status...</p>
                                    </div>
                                </section>
                                <section class="whatsapp-config-panel">
                                    <div class="whatsapp-section-head">
                                        <h3>Workspace webhooks</h3>
                                        <p>Workspace callback URLs and verify tokens are generated after each workspace saves its WhatsApp number and access token.</p>
                                    </div>
                                    <a class="btn-premium-secondary" href="<?php echo htmlspecialchars($whatsAppSettingsTabUrl('connection')); ?>" style="font-size:.82rem;text-decoration:none;display:inline-flex;width:max-content;max-width:100%;">Open workspace setup</a>
                                </section>
                                <section class="whatsapp-config-panel whatsapp-config-panel--green">
                                    <div class="whatsapp-subpanel-head">
                                        <strong>Meta app review tests</strong>
                                        <button type="button" onclick="testMetaPermissions()" style="background:#16a34a;color:white;padding:.45rem .85rem;border:none;border-radius:6px;font-size:.82rem;cursor:pointer;">Test permissions</button>
                                    </div>
                                    <p style="margin:0;color:#475569;font-size:.85rem;">Run Graph API permission checks used for Meta app review evidence.</p>
                                    <a href="<?php echo htmlspecialchars(apiUrl('whatsapp/test-permissions.php')); ?>" target="_blank" class="btn-premium-secondary" style="font-size:.82rem;text-decoration:none;display:inline-flex;width:max-content;max-width:100%;">Open endpoint</a>
                                    <div id="meta-test-result" style="font-size:.8rem;"></div>
                                </section>
                            </div>
                            <div class="whatsapp-platform-actions">
                                <button type="submit" class="btn-premium-primary">Save Platform</button>
                            </div>
                        </form>
                    <?php else: ?>
                        <div class="whatsapp-muted-card">
                            Platform credentials are visible only to superadmins.
                        </div>
                    <?php endif; ?>
                </section>

                <section class="whatsapp-settings-panel" data-whatsapp-settings-panel="connection"<?php echo $whatsAppPanelAttr('connection'); ?>>
                    <?php if ($whatsAppHubAvailable): ?>
                        <section class="content-card whatsapp-shared-panel">
                            <div class="whatsapp-section-head">
                                <h3>Workspace Connection</h3>
                                <p>Manual or managed WhatsApp setup, webhook, templates, credits, migration, and tests.</p>
                            </div>
                            <?php marketplaceRenderWhatsAppChannelSetup(array_merge($whatsAppChannelHub, [
                                'csrf' => Security::getCsrfToken(),
                                'settings_tab' => 'whatsapp',
                                'hide_activity_tab' => true,
                            ])); ?>
                        </section>
                    <?php else: ?>
                        <div class="whatsapp-muted-card">Select an active workspace to manage workspace WhatsApp connection setup.</div>
                    <?php endif; ?>
                </section>

                <section class="whatsapp-settings-panel" data-whatsapp-settings-panel="assistant"<?php echo $whatsAppPanelAttr('assistant'); ?>>
                    <?php if ($whatsAppHubAvailable): ?>
                        <section class="content-card whatsapp-shared-panel">
                            <div class="whatsapp-section-head">
                                <h3>WhatsApp Assistant</h3>
                                <p>Identity, session behavior, digest tests, and assistant controls.</p>
                            </div>
                            <?php marketplaceRenderWhatsAppAssistantSetup(array_merge($whatsAppAssistantHub, [
                                'csrf' => Security::getCsrfToken(),
                                'settings_tab' => 'whatsapp',
                                'hide_activity_tab' => true,
                            ])); ?>
                        </section>
                    <?php else: ?>
                        <div class="whatsapp-muted-card">Select an active workspace to manage WhatsApp Assistant setup.</div>
                    <?php endif; ?>
                </section>

                <section class="whatsapp-settings-panel" data-whatsapp-settings-panel="guardrails"<?php echo $whatsAppPanelAttr('guardrails'); ?>>
                    <form class="whatsapp-guardrails-form" method="POST" action="settings.php?tab=whatsapp&amp;whatsapp_tab=guardrails">
                        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                        <input type="hidden" name="tab" value="whatsapp">
                        <input type="hidden" name="whatsapp_tab" value="guardrails">
                        <input type="hidden" name="whatsapp_settings_section" value="guardrails">
                        <section class="whatsapp-guardrails-card">
                            <div class="whatsapp-section-head">
                                <h3>Cold Outreach Warmup</h3>
                                <p>Cap first-time cold WhatsApp outreach per day and let Auto Admin raise the current cap slowly when channel health stays healthy.</p>
                            </div>
                            <label class="whatsapp-check-row">
                                <input type="checkbox" name="whatsapp_cold_outreach_enabled" value="1" <?php echo !empty($whatsappColdOutreachWarmup['enabled']) ? 'checked' : ''; ?> <?php echo $whatsappWarmupLocked ? 'disabled' : ''; ?>>
                                <span>Enable WhatsApp cold outreach cap</span>
                            </label>
                            <label class="whatsapp-check-row">
                                <input type="checkbox" name="whatsapp_auto_admin_warmup_enabled" value="1" <?php echo !empty($whatsappColdOutreachWarmup['auto_admin_warmup_enabled']) ? 'checked' : ''; ?> <?php echo $whatsappWarmupLocked ? 'disabled' : ''; ?>>
                                <span>Let Auto Admin increase the WhatsApp cap gradually</span>
                            </label>
                            <?php if ($whatsappWarmupLocked): ?>
                                <div class="whatsapp-notice">Auto Admin warmup is managing this toggle and the numeric values below. Turn off Auto Admin from AI Services to restore manual control.</div>
                            <?php endif; ?>
                            <div class="whatsapp-number-grid">
                                <label>Initial daily cold limit
                                    <input type="number" min="0" id="whatsapp_initial_daily_cold_limit" name="whatsapp_initial_daily_cold_limit" value="<?php echo (int) ($whatsappColdOutreachWarmup['initial_daily_cold_limit'] ?? 0); ?>" <?php echo $whatsappWarmupLocked ? 'disabled' : ''; ?>>
                                </label>
                                <label>Current daily cold limit
                                    <input type="number" min="0" id="whatsapp_current_daily_cold_limit" name="whatsapp_current_daily_cold_limit" value="<?php echo (int) ($whatsappColdOutreachWarmup['current_daily_cold_limit'] ?? 0); ?>" <?php echo $whatsappWarmupLocked ? 'disabled' : ''; ?>>
                                </label>
                                <label>Weekly increment
                                    <input type="number" min="0" id="whatsapp_weekly_increment" name="whatsapp_weekly_increment" value="<?php echo (int) ($whatsappColdOutreachWarmup['weekly_increment'] ?? 0); ?>" <?php echo $whatsappWarmupLocked ? 'disabled' : ''; ?>>
                                </label>
                                <label>Max limit
                                    <input type="number" min="0" id="whatsapp_max_limit" name="whatsapp_max_limit" value="<?php echo (int) ($whatsappColdOutreachWarmup['max_limit'] ?? 0); ?>" <?php echo $whatsappWarmupLocked ? 'disabled' : ''; ?>>
                                </label>
                            </div>
                            <div class="whatsapp-usage-note">Today used or reserved: <strong><?php echo (int) ($whatsappColdOutreachUsage['reserved_or_sent'] ?? 0); ?></strong></div>
                        </section>
                        <div class="whatsapp-form-actions">
                            <button type="submit" class="btn-premium-primary">Save Guardrails</button>
                        </div>
                    </form>
                </section>

                <section class="whatsapp-settings-panel" data-whatsapp-settings-panel="activity"<?php echo $whatsAppPanelAttr('activity'); ?>>
                    <?php if ($whatsAppHubAvailable): ?>
                        <div class="whatsapp-activity-grid">
                            <section class="content-card whatsapp-activity-card">
                                <h3 class="whatsapp-section-title">Connection Activity</h3>
                                <?php marketplaceRenderSetupActivity($whatsAppChannelEvents); ?>
                            </section>
                            <section class="content-card whatsapp-activity-card">
                                <h3 class="whatsapp-section-title">Assistant Activity</h3>
                                <?php marketplaceRenderSetupActivity($whatsAppAssistantEvents); ?>
                            </section>
                        </div>
                    <?php else: ?>
                        <div class="whatsapp-muted-card">Select an active workspace to view WhatsApp setup activity.</div>
                    <?php endif; ?>
                </section>
            </div>

        <?php elseif ($activeTab === 'ai'): ?>
            <?php
            $aiTokenRateLimitSummary = (new AITokenRateLimiterService())->getUsageSummary();
            $autoAdminTargetModes = (array) ($autoAdminWorkspaceState['target_modes'] ?? []);
            $autoAdminEffectiveModes = (array) ($autoAdminWorkspaceState['effective_modes'] ?? []);
            $autoAdminReadinessSnapshot = (array) ($autoAdminWorkspaceState['readiness_snapshot'] ?? []);
            $autoAdminFormDisabled = !$canManageWorkspaceAutoAdmin || !$autoAdminWorkspaceControlsAvailable;
            $autoAdminFormDisabledAttr = $autoAdminFormDisabled ? 'disabled' : '';
            $autoAdminModeOptions = [
                'deal_automation' => ['suggest_only' => 'Suggest only', 'auto_safe' => 'Auto-safe', 'full_auto' => 'Full auto'],
                'workflow_automation' => ['suggest_only' => 'Suggest only', 'auto_safe' => 'Auto-safe', 'full_auto' => 'Full auto'],
                'ai_autoresponder' => ['off' => 'Off', 'draft_only' => 'Draft only', 'hybrid' => 'Hybrid', 'full_auto' => 'Full auto'],
                'commercial_automation' => ['suggest_only' => 'Suggest only', 'auto_safe' => 'Auto-safe', 'full_auto' => 'Full auto'],
            ];
            $autoAdminDomainLabels = [
                'deal_automation' => 'Deal automation',
                'workflow_automation' => 'Workflow automation',
                'ai_autoresponder' => 'AI auto-responder',
                'commercial_automation' => 'Commercial automation',
            ];
            ?>
            <form method="POST" action="" class="content-card" style="display:grid;gap:1rem;max-width:900px;margin-bottom:var(--spacing-lg);border-color:<?php echo $autoAdminEnabled ? '#86efac' : '#cbd5e1'; ?>;background:<?php echo $autoAdminEnabled ? '#f0fdf4' : '#f8fafc'; ?>;">
                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                <input type="hidden" name="tab" value="ai">
                <input type="hidden" name="auto_admin_toggle_submitted" value="1">
                <div style="display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;flex-wrap:wrap;">
                    <div style="max-width:640px;">
                        <div style="font-size:.72rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:<?php echo $autoAdminEnabled ? '#15803d' : '#475569'; ?>;">Managed automation</div>
                        <h3 style="margin:.25rem 0 .35rem;color:#0f172a;font-size:1rem;"><?php echo $autoAdminEnabled ? 'Auto Admin is managing this workspace' : 'Manual AI and automation control'; ?></h3>
                        <p style="margin:0;color:#64748b;font-size:.88rem;line-height:1.55;">
                            <?php echo $autoAdminEnabled
                                ? 'Managed tabs remain visible for orientation, but their detailed controls are locked for this workspace until manual control is restored.'
                                : 'Enable Auto Admin to apply safe defaults across AI, autoresponder, workflow, commercial, and deal automation for this workspace.'; ?>
                        </p>
                    </div>
                    <label style="display:flex;align-items:center;gap:.65rem;padding:.7rem .85rem;border:1px solid <?php echo $autoAdminEnabled ? '#86efac' : '#cbd5e1'; ?>;border-radius:8px;background:#fff;cursor:pointer;">
                        <input type="checkbox" name="auto_admin_enabled" value="1" <?php echo $autoAdminEnabled ? 'checked' : ''; ?> <?php echo $autoAdminFormDisabledAttr; ?> style="width:1rem;height:1rem;">
                        <span style="font-weight:700;color:#0f172a;"><?php echo $autoAdminEnabled ? 'Auto Admin on for this workspace' : 'Auto Admin off'; ?></span>
                    </label>
                </div>

                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:.75rem;">
                    <?php foreach ($autoAdminDomainLabels as $domainKey => $domainLabel): ?>
                        <?php
                        $readiness = (array) ($autoAdminReadinessSnapshot[$domainKey] ?? []);
                        $targetMode = (string) ($autoAdminTargetModes[$domainKey] ?? array_key_first($autoAdminModeOptions[$domainKey]));
                        $effectiveMode = (string) ($autoAdminEffectiveModes[$domainKey] ?? 'not evaluated');
                        $ceilingMode = (string) ($readiness['ceiling'] ?? 'not evaluated');
                        $blockers = array_slice((array) ($readiness['blocking_reasons'] ?? []), 0, 2);
                        ?>
                        <div style="border:1px solid #dbeafe;border-radius:8px;background:#fff;padding:.75rem;display:grid;gap:.45rem;">
                            <label style="font-size:.78rem;font-weight:700;color:#0f172a;" for="auto_admin_target_<?php echo htmlspecialchars($domainKey); ?>"><?php echo htmlspecialchars($domainLabel); ?></label>
                            <select id="auto_admin_target_<?php echo htmlspecialchars($domainKey); ?>" name="auto_admin_target_<?php echo htmlspecialchars($domainKey); ?>" <?php echo $autoAdminFormDisabledAttr; ?> style="width:100%;padding:.55rem;border:1px solid #cbd5e1;border-radius:6px;background:#fff;">
                                <?php foreach ($autoAdminModeOptions[$domainKey] as $modeKey => $modeLabel): ?>
                                    <option value="<?php echo htmlspecialchars($modeKey); ?>" <?php echo $targetMode === $modeKey ? 'selected' : ''; ?>><?php echo htmlspecialchars($modeLabel); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div style="font-size:.76rem;color:#475569;line-height:1.45;">
                                <div>Effective: <strong><?php echo htmlspecialchars($effectiveMode); ?></strong></div>
                                <div>Ceiling: <strong><?php echo htmlspecialchars($ceilingMode); ?></strong></div>
                                <?php if (!empty($blockers)): ?>
                                    <div><?php echo htmlspecialchars(implode(' ', array_map('strval', $blockers))); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <label style="display:grid;gap:.45rem;border:1px solid #e2e8f0;border-radius:8px;background:#fff;padding:.75rem;">
                    <span style="display:flex;align-items:center;gap:.55rem;font-weight:700;color:#0f172a;">
                        <input type="checkbox" name="auto_admin_manual_freeze" value="1" <?php echo !empty($autoAdminWorkspaceState['manual_freeze']) ? 'checked' : ''; ?> <?php echo $autoAdminFormDisabledAttr; ?> style="width:1rem;height:1rem;">
                        Freeze Auto Admin writes for this workspace
                    </span>
                    <input type="text" name="auto_admin_freeze_reason" value="<?php echo htmlspecialchars((string) ($autoAdminWorkspaceState['freeze_reason'] ?? '')); ?>" placeholder="Freeze reason" <?php echo $autoAdminFormDisabledAttr; ?> style="width:100%;padding:.55rem;border:1px solid #cbd5e1;border-radius:6px;">
                </label>

                <?php if (!$autoAdminWorkspaceControlsAvailable): ?>
                    <div style="color:#b45309;font-size:.82rem;">Platform Auto Admin is off. Workspace controls become available after a Super Admin enables the platform switch.</div>
                <?php elseif (!$canManageWorkspaceAutoAdmin): ?>
                    <div style="color:#b45309;font-size:.82rem;">You can review this workspace state, but your role cannot change Auto Admin controls.</div>
                <?php endif; ?>

                <div style="display:flex;gap:.75rem;align-items:center;flex-wrap:wrap;">
                    <button type="submit" class="btn-premium-primary" <?php echo $autoAdminFormDisabledAttr; ?>><?php echo $autoAdminEnabled ? 'Save Auto Admin' : 'Enable Auto Admin'; ?></button>
                    <span style="color:#64748b;font-size:.82rem;"><?php echo $autoAdminEnabled ? 'Uncheck and save to restore manual control for this workspace.' : 'Leave checked and save to apply managed mode to this workspace.'; ?></span>
                </div>
            </form>
            <form method="POST" action="" style="display: flex; flex-direction: column; gap: var(--spacing-lg); max-width: 600px;">
                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                <input type="hidden" name="tab" value="ai">
                
                <div>
                    <label for="ai_api_key" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">AI API Key</label>
                    <input 
                        type="password" 
                        id="ai_api_key" 
                        name="ai_api_key" 
                        value="<?php echo htmlspecialchars($_ENV['AI_API_KEY'] ?? ''); ?>"
                        placeholder="Enter your AI service API key"
                        style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                    >
                </div>
                
                <div>
                    <label for="ai_service_url" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">AI Service URL</label>
                    <input 
                        type="url" 
                        id="ai_service_url" 
                        name="ai_service_url" 
                        value="<?php echo htmlspecialchars($_ENV['AI_SERVICE_URL'] ?? ''); ?>"
                        placeholder="https://api.openai.com/v1/chat/completions (or leave empty for auto-detection)"
                        style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                    >
                    <small style="color: var(--charcoal-grey); font-size: 12px; display: block; margin-top: var(--spacing-xs);">
                        For OpenAI: Use <code>https://api.openai.com/v1/chat/completions</code> or leave empty to auto-detect
                    </small>
                </div>

                <div>
                    <label for="ai_model" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">AI Model</label>
                    <input
                        type="text"
                        id="ai_model"
                        name="ai_model"
                        value="<?php echo htmlspecialchars($_ENV['AI_MODEL'] ?? ''); ?>"
                        placeholder="gpt-4o-mini"
                        style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                    >
                    <small style="color: var(--charcoal-grey); font-size: 12px; display: block; margin-top: var(--spacing-xs);">
                        e.g. <code>gpt-4o-mini</code>, <code>gpt-4o</code>, <code>gpt-3.5-turbo</code>. Leave empty to use the provider default.
                    </small>
                </div>
                
                <div style="background: var(--light-grey); padding: var(--spacing-md); border-radius: 4px; margin-top: var(--spacing-md);">
                    <p style="color: var(--charcoal-grey); font-size: 14px; margin-bottom: var(--spacing-xs);">
                        <strong>Note:</strong> Configure AI service settings to enable smart features like contact summarization and communication drafting.
                    </p>
                    <p style="color: var(--charcoal-grey); font-size: 12px; margin-bottom: var(--spacing-sm);">
                        For OpenAI, use: <code>https://api.openai.com/v1/chat/completions</code><br>
                        For local AI (Ollama), use: <code>http://localhost:11434</code><br>
                        <strong>Tip:</strong> If you only provide an API key, OpenAI will be used automatically.
                    </p>
                    <button 
                        type="button" 
                        id="test-ai-btn"
                        onclick="testAIConnection()"
                        style="background: #059669; color: #ffffff; padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; font-weight: 600; font-size: 14px; display: inline-flex; align-items: center; gap: 8px;"
                    >
                        <i class="fas fa-vial" aria-hidden="true"></i> Test AI Connection
                    </button>
                    <div id="ai-test-result" style="margin-top: var(--spacing-sm);"></div>
                </div>

                <div class="content-card" style="display:grid;gap:1rem;background:#f8fafc;border:1px solid #e2e8f0;">
                    <div>
                        <h3 style="margin:0 0 .35rem 0;color:#0f172a;">Daily AI Token Rate Limit</h3>
                        <p style="margin:0;color:#64748b;font-size:.9rem;">Apply one shared daily AI token cap across all providers and models for this workspace.</p>
                    </div>
                    <label style="display:flex;align-items:center;gap:.65rem;font-weight:600;color:#0f172a;">
                        <input type="checkbox" name="ai_token_rate_limit_enabled" value="1" <?php echo strtolower((string) ($_ENV['AI_TOKEN_RATE_LIMIT_ENABLED'] ?? 'false')) === 'true' ? 'checked' : ''; ?> style="width:1.1rem;height:1.1rem;">
                        <span>Enable daily AI token cap</span>
                    </label>
                    <div>
                        <label for="ai_daily_token_limit" style="display:block;margin-bottom:var(--spacing-xs);color:var(--midnight-black);font-weight:500;">Daily token limit</label>
                        <input
                            type="number"
                            min="0"
                            id="ai_daily_token_limit"
                            name="ai_daily_token_limit"
                            value="<?php echo htmlspecialchars((string) ($_ENV['AI_DAILY_TOKEN_LIMIT'] ?? '0')); ?>"
                            style="width:100%;padding:var(--spacing-sm);border:1px solid var(--border-color);border-radius:4px;"
                        >
                    </div>
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:.75rem;">
                        <div style="padding:.85rem 1rem;border-radius:12px;background:#fff;border:1px solid rgba(15,23,42,.08);">
                            <div style="font-size:.78rem;color:#64748b;">Used Today</div>
                            <div style="font-size:1.05rem;font-weight:700;color:#0f172a;"><?php echo number_format((int) ($aiTokenRateLimitSummary['used_today'] ?? 0)); ?></div>
                        </div>
                        <div style="padding:.85rem 1rem;border-radius:12px;background:#fff;border:1px solid rgba(15,23,42,.08);">
                            <div style="font-size:.78rem;color:#64748b;">Remaining</div>
                            <div style="font-size:1.05rem;font-weight:700;color:#0f172a;"><?php echo number_format((int) ($aiTokenRateLimitSummary['remaining_today'] ?? 0)); ?></div>
                        </div>
                        <div style="padding:.85rem 1rem;border-radius:12px;background:#fff;border:1px solid rgba(15,23,42,.08);">
                            <div style="font-size:.78rem;color:#64748b;">Resets At</div>
                            <div style="font-size:1.05rem;font-weight:700;color:#0f172a;"><?php echo htmlspecialchars((string) ($aiTokenRateLimitSummary['resets_at'] ?? '')); ?></div>
                        </div>
                    </div>
                    <?php if (!empty($aiTokenRateLimitSummary['is_limited'])): ?>
                        <div style="padding:.85rem 1rem;border-radius:12px;background:#fef2f2;color:#991b1b;border:1px solid rgba(239,68,68,.18);">
                            Daily AI token limit reached. AI features are blocked until <?php echo htmlspecialchars((string) ($aiTokenRateLimitSummary['resets_at'] ?? '')); ?>.
                        </div>
                    <?php endif; ?>
                </div>
                
                <div style="display: flex; gap: var(--spacing-md); justify-content: flex-end; margin-top: var(--spacing-md);">
                <button type="submit" class="btn-premium-primary">
                        Save Settings
                    </button>
                </div>
            </form>
            
        <?php elseif ($activeTab === 'google_services'): ?>
            <?php
            $googleFeatureRows = (array) ($googleServicesSummary['features'] ?? []);
            $googleClientRows = (array) ($googleServicesSummary['clients'] ?? []);
            $googleTokenSafety = (array) ($googleServicesSummary['token_safety'] ?? []);
            $googleAuditRows = (array) ($googleServicesSummary['recent_audit'] ?? []);
            $googleStatusStyle = static function (string $status): string {
                return match ($status) {
                    'ready' => 'background:#dcfce7;color:#166534;border-color:#bbf7d0;',
                    'legacy_fallback' => 'background:#fef3c7;color:#92400e;border-color:#fde68a;',
                    'reconnect_recommended' => 'background:#ffedd5;color:#9a3412;border-color:#fed7aa;',
                    'not_connected' => 'background:#e2e8f0;color:#475569;border-color:#cbd5e1;',
                    default => 'background:#fee2e2;color:#991b1b;border-color:#fecaca;',
                };
            };
            ?>
            <?php if (!$isSuperAdmin): ?>
                <div class="content-card" style="max-width:760px;background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;line-height:1.55;">
                    Google Services setup is available only to Super Admins.
                </div>
            <?php else: ?>
                <form method="POST" action="settings.php?tab=google_services" style="display:grid;gap:var(--spacing-lg);">
                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                    <input type="hidden" name="tab" value="google_services">
                    <input type="hidden" name="google_services_action" value="save_credentials">

                    <div style="background:#fff;border:1px solid #ccfbf1;border-radius:12px;padding:var(--spacing-md);display:grid;gap:1rem;">
                        <div style="display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;flex-wrap:wrap;">
                            <div>
                                <p style="margin:0 0 .25rem;color:#0f766e;font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.05em;">Super Admin Google Setup</p>
                                <h2 style="margin:0;color:#0f172a;font-size:20px;line-height:1.2;">Google Services</h2>
                                <p style="margin:.4rem 0 0;color:#475569;font-size:13px;line-height:1.55;max-width:760px;">Manage platform OAuth clients for Gmail, Assistant Gmail, and Google Calendar in one place while keeping the underlying grants separated.</p>
                            </div>
                            <label style="display:flex;align-items:center;gap:.55rem;border:1px solid #fde68a;border-radius:10px;background:#fffbeb;color:#92400e;padding:.65rem .8rem;font-size:12px;font-weight:700;">
                                <input type="checkbox" name="allow_legacy_google_oauth_fallback" value="1" <?php echo !empty($googleServicesSummary['legacy_fallback_enabled']) ? 'checked' : ''; ?>>
                                Allow legacy fallback
                            </label>
                        </div>

                        <div style="overflow:auto;border:1px solid #e2e8f0;border-radius:10px;">
                            <table style="width:100%;border-collapse:collapse;min-width:720px;">
                                <thead>
                                    <tr style="background:#f8fafc;color:#475569;font-size:12px;text-align:left;">
                                        <th style="padding:.75rem;">Service</th>
                                        <th style="padding:.75rem;">Status</th>
                                        <th style="padding:.75rem;">Client</th>
                                        <th style="padding:.75rem;">Required scopes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($googleFeatureRows as $feature): ?>
                                        <?php $featureStatus = (string) ($feature['status'] ?? 'missing_client'); ?>
                                        <tr style="border-top:1px solid #e2e8f0;">
                                            <td style="padding:.75rem;color:#0f172a;font-weight:800;"><?php echo htmlspecialchars((string) ($feature['label'] ?? 'Google service')); ?></td>
                                            <td style="padding:.75rem;"><span style="display:inline-flex;border:1px solid;border-radius:999px;padding:.2rem .55rem;font-size:11px;font-weight:800;<?php echo $googleStatusStyle($featureStatus); ?>"><?php echo htmlspecialchars((string) ($feature['status_label'] ?? 'Missing client')); ?></span></td>
                                            <td style="padding:.75rem;color:#475569;font-size:12px;"><?php echo htmlspecialchars((string) ($feature['client_label'] ?? $feature['client_key'] ?? 'Google client')); ?></td>
                                            <td style="padding:.75rem;color:#64748b;font-size:11px;line-height:1.5;"><?php echo htmlspecialchars(implode(', ', (array) ($feature['scopes'] ?? []))); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:var(--spacing-md);display:grid;gap:1rem;">
                        <div>
                            <h3 style="margin:0;color:#0f172a;font-size:16px;">OAuth Clients</h3>
                            <p style="margin:.35rem 0 0;color:#64748b;font-size:13px;">Blank secret fields preserve the saved secret. Client IDs remain visible for admin verification.</p>
                        </div>
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:.85rem;">
                            <?php foreach ($googleClientRows as $client): ?>
                                <?php
                                $fieldPrefix = (string) ($client['field_prefix'] ?? '');
                                $clientId = trim((string) ($client['client_id'] ?? ''));
                                $secretSaved = !empty($client['secret_saved']);
                                ?>
                                <div style="border:1px solid #e2e8f0;border-radius:8px;background:#f8fafc;padding:.85rem;display:grid;gap:.65rem;">
                                    <div style="display:flex;justify-content:space-between;gap:.75rem;align-items:center;">
                                        <strong style="color:#0f172a;font-size:13px;"><?php echo htmlspecialchars((string) ($client['label'] ?? 'Google client')); ?></strong>
                                        <span style="font-size:11px;font-weight:800;color:<?php echo $clientId !== '' && $secretSaved ? '#166534' : '#9a3412'; ?>;"><?php echo $clientId !== '' && $secretSaved ? 'Saved' : 'Missing'; ?></span>
                                    </div>
                                    <label for="<?php echo htmlspecialchars($fieldPrefix); ?>_client_id" style="font-size:12px;font-weight:700;color:#334155;">Client ID</label>
                                    <input type="text" id="<?php echo htmlspecialchars($fieldPrefix); ?>_client_id" name="<?php echo htmlspecialchars($fieldPrefix); ?>_client_id" value="<?php echo htmlspecialchars($clientId); ?>" style="width:100%;padding:.65rem;border:1px solid var(--border-color);border-radius:6px;">
                                    <label for="<?php echo htmlspecialchars($fieldPrefix); ?>_client_secret" style="font-size:12px;font-weight:700;color:#334155;">Client Secret</label>
                                    <input type="password" id="<?php echo htmlspecialchars($fieldPrefix); ?>_client_secret" name="<?php echo htmlspecialchars($fieldPrefix); ?>_client_secret" placeholder="<?php echo $secretSaved ? 'Saved - leave blank to keep' : 'Client secret'; ?>" style="width:100%;padding:.65rem;border:1px solid var(--border-color);border-radius:6px;">
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div style="display:flex;justify-content:flex-end;">
                            <button type="submit" class="btn-premium-primary">Save Google Services</button>
                        </div>
                    </div>

                    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:var(--spacing-md);display:grid;gap:1rem;">
                        <h3 style="margin:0;color:#0f172a;font-size:16px;">Redirect URIs</h3>
                        <div style="display:grid;gap:.65rem;">
                            <?php foreach ($googleFeatureRows as $feature): ?>
                                <label style="display:grid;gap:.25rem;">
                                    <span style="color:#334155;font-size:12px;font-weight:800;"><?php echo htmlspecialchars((string) ($feature['label'] ?? 'Google service')); ?></span>
                                    <input type="text" readonly value="<?php echo htmlspecialchars((string) ($feature['redirect_uri'] ?? '')); ?>" style="width:100%;padding:.65rem;border:1px solid #e2e8f0;border-radius:6px;background:#f8fafc;color:#475569;">
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </form>

                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:var(--spacing-lg);margin-top:var(--spacing-lg);">
                    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:var(--spacing-md);display:grid;gap:.85rem;">
                        <h3 style="margin:0;color:#0f172a;font-size:16px;">Token Safety</h3>
                        <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.65rem;">
                            <?php foreach ([
                                'Gmail encrypted' => (int) ($googleTokenSafety['gmail_encrypted'] ?? 0),
                                'Gmail plaintext' => (int) ($googleTokenSafety['gmail_plaintext'] ?? 0),
                                'Calendar encrypted' => (int) ($googleTokenSafety['calendar_encrypted'] ?? 0),
                                'Calendar plaintext' => (int) ($googleTokenSafety['calendar_plaintext'] ?? 0),
                            ] as $tokenLabel => $tokenCount): ?>
                                <div style="border:1px solid #e2e8f0;border-radius:8px;background:#f8fafc;padding:.75rem;">
                                    <div style="color:#64748b;font-size:11px;"><?php echo htmlspecialchars($tokenLabel); ?></div>
                                    <div style="color:#0f172a;font-size:18px;font-weight:800;"><?php echo $tokenCount; ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div style="padding:.75rem;border:1px solid #dbeafe;border-radius:8px;background:#eff6ff;color:#1e3a8a;font-size:12px;line-height:1.5;">
                            To encrypt legacy plaintext tokens from the server console, run <code><?php echo htmlspecialchars((string) ($googleServicesSummary['cli_encrypt_command'] ?? 'php cli/encrypt_google_oauth_tokens.php')); ?></code>.
                        </div>
                    </div>

                    <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:var(--spacing-md);display:grid;gap:.85rem;">
                        <h3 style="margin:0;color:#0f172a;font-size:16px;">Recent OAuth Audit</h3>
                        <?php if ($googleAuditRows === []): ?>
                            <p style="margin:0;color:#64748b;font-size:13px;">No Google OAuth audit rows yet.</p>
                        <?php else: ?>
                            <div style="display:grid;gap:.5rem;">
                                <?php foreach ($googleAuditRows as $auditRow): ?>
                                    <div style="border:1px solid #e2e8f0;border-radius:8px;background:#f8fafc;padding:.75rem;display:grid;gap:.25rem;">
                                        <div style="display:flex;justify-content:space-between;gap:.75rem;align-items:center;">
                                            <strong style="color:#0f172a;font-size:12px;"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) ($auditRow['oauth_grant_type'] ?? 'google')))); ?></strong>
                                            <span style="color:#64748b;font-size:11px;"><?php echo htmlspecialchars((string) ($auditRow['created_at'] ?? '')); ?></span>
                                        </div>
                                        <div style="color:#475569;font-size:12px;"><?php echo htmlspecialchars((string) ($auditRow['operation'] ?? 'event')); ?> - <?php echo htmlspecialchars((string) ($auditRow['status'] ?? '')); ?></div>
                                        <?php if (trim((string) ($auditRow['message'] ?? '')) !== ''): ?>
                                            <div style="color:#64748b;font-size:11px;line-height:1.45;"><?php echo htmlspecialchars((string) $auditRow['message']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

        <?php elseif ($activeTab === 'calendar'): ?>
            <?php
            $appUrl = rtrim($_ENV['APP_URL'] ?? (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . (strpos($_SERVER['SCRIPT_NAME'] ?? '', '/crm/') !== false ? '/crm' : ''), '/');
            $googleRedirectUri = $appUrl . '/api/calendar/google/callback.php';
            $outlookRedirectUri = $appUrl . '/api/calendar/outlook/callback.php';
            $hasGoogle = !empty($_ENV['GOOGLE_CALENDAR_CLIENT_ID']);
            $hasMicrosoft = !empty($_ENV['MICROSOFT_CALENDAR_CLIENT_ID']);
            ?>
            <?php if ($isSuperAdmin): ?>
            <form method="POST" action="" style="display: flex; flex-direction: column; gap: var(--spacing-lg); max-width: 600px;">
                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                <input type="hidden" name="tab" value="calendar">
                
                <div style="background: #f8fafc; border-left: 4px solid #667eea; padding: var(--spacing-md); border-radius: 4px; margin-bottom: var(--spacing-lg);">
                    <p style="color: #0f172a; font-size: 14px; margin: 0 0 var(--spacing-sm); line-height: 1.5;">
                        Configure Outlook calendar OAuth here. Google Calendar OAuth is managed from <a href="settings.php?tab=google_services" style="color: #0f766e; font-weight: 700;">Google Services</a>. Users connect calendars from <a href="workspace_skills.php?module=calendar_meetings&amp;setup_tab=calendar#setup" style="color: #667eea; font-weight: 500;">Calendar &amp; Meetings setup</a>.
                    </p>
                    <p style="color: #64748b; font-size: 12px; margin: 0;">
                        <strong>Redirect base:</strong> <code style="word-break: break-all; background: rgba(0,0,0,0.05); padding: 2px 6px; border-radius: 4px;"><?php echo htmlspecialchars($appUrl); ?></code>
                    </p>
                </div>
                
                <div class="content-card" style="margin-bottom: var(--spacing-lg); background:#f0fdfa;border-color:#99f6e4;">
                    <div style="display:flex;justify-content:space-between;gap:1rem;align-items:center;flex-wrap:wrap;">
                        <div>
                            <h3 style="color:#0f172a;margin:0 0 .35rem;font-size:1rem;font-weight:800;">Google Calendar OAuth is managed in Google Services</h3>
                            <p style="margin:0;color:#475569;font-size:13px;line-height:1.5;">Calendar import and outbound write readiness use the central Super Admin Google Services setup.</p>
                            <p style="margin:.35rem 0 0;color:#64748b;font-size:12px;">Redirect URI: <code style="word-break:break-all;background:rgba(0,0,0,0.05);padding:2px 6px;border-radius:4px;"><?php echo htmlspecialchars($googleRedirectUri); ?></code></p>
                        </div>
                        <div style="display:flex;align-items:center;gap:.65rem;flex-wrap:wrap;">
                            <span style="font-size:12px;font-weight:800;color:<?php echo $hasGoogle ? '#166534' : '#9a3412'; ?>;"><?php echo $hasGoogle ? 'Configured' : 'Missing'; ?></span>
                            <a href="settings.php?tab=google_services" style="display:inline-flex;align-items:center;justify-content:center;border:1px solid #99f6e4;border-radius:8px;background:#fff;color:#0f766e;text-decoration:none;padding:.65rem .9rem;font-weight:800;font-size:12px;">Open Google Services</a>
                        </div>
                    </div>
                </div>
                
                <div class="content-card" style="margin-bottom: var(--spacing-lg); background: #f8fafc;">
                    <h3 style="color: #0f172a; margin: 0 0 var(--spacing-md); font-size: 1.125rem; font-weight: 600; display: flex; align-items: center; gap: 0.5rem;">
                        <span style="font-size: 1.25rem;">📆</span> Microsoft / Outlook Calendar
                    </h3>
                    <div>
                        <label for="microsoft_calendar_client_id" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Client ID</label>
                        <div style="position: relative;">
                            <input 
                                type="text" 
                                id="microsoft_calendar_client_id" 
                                name="microsoft_calendar_client_id" 
                                value="<?php echo htmlspecialchars($_ENV['MICROSOFT_CALENDAR_CLIENT_ID'] ?? ''); ?>"
                                placeholder="Application (client) ID from Azure Portal"
                                style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;<?php echo $hasMicrosoft ? ' padding-right: 100px;' : ''; ?>"
                            >
                            <?php if ($hasMicrosoft): ?>
                                <span style="position: absolute; right: 8px; top: 50%; transform: translateY(-50%); color: #28a745; font-size: 12px; font-weight: 500;">✓ Configured</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div style="margin-top: var(--spacing-md);">
                        <label for="microsoft_calendar_client_secret" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Client Secret</label>
                        <input 
                            type="password" 
                            id="microsoft_calendar_client_secret" 
                            name="microsoft_calendar_client_secret" 
                            placeholder="Leave blank to keep existing"
                            style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                        >
                    </div>
                    <small style="color: #64748b; font-size: 12px; display: block; margin-top: var(--spacing-sm); line-height: 1.5;">
                        Register app in Azure Portal. Add redirect URI: <code style="word-break: break-all; background: rgba(0,0,0,0.05); padding: 2px 6px; border-radius: 4px;"><?php echo htmlspecialchars($outlookRedirectUri); ?></code>
                    </small>
                </div>
                
                <div style="display: flex; gap: var(--spacing-md); justify-content: flex-end; margin-top: var(--spacing-sm);">
                    <button type="submit" class="btn-premium-primary">
                        Save Calendar Settings
                    </button>
                </div>
            </form>
            <?php else: ?>
                <div class="content-card" style="max-width:760px;background:#f8fafc;border:1px solid #e2e8f0;color:#475569;line-height:1.55;">
                    Calendar connection is handled by the Calendar setup card in this section. Google OAuth setup is managed in Google Services, and Microsoft OAuth setup is managed here by Super Admin.
                </div>
            <?php endif; ?>
            
        <?php elseif ($activeTab === 'enrichment'): ?>
            <?php
            // Check if enrichment_config table exists, if not use defaults
            try {
                $enrichmentConfig = Database::queryOne("SELECT * FROM enrichment_config WHERE id = 1");
                if (!$enrichmentConfig) {
                    // Create default config if table exists but no row
                    try {
                        Database::execute(
                            "INSERT INTO enrichment_config (id, auto_enrich_on_create, auto_enrich_on_update, auto_enrich_on_email, confidence_threshold, max_cost_per_contact) 
                             VALUES (1, FALSE, FALSE, FALSE, 0.7, 0.10)"
                        );
                        $enrichmentConfig = Database::queryOne("SELECT * FROM enrichment_config WHERE id = 1");
                    } catch (\Exception $e) {
                        // Table doesn't exist, use defaults
                        $enrichmentConfig = [
                            'auto_enrich_on_create' => false,
                            'auto_enrich_on_update' => false,
                            'auto_enrich_on_email' => false,
                            'confidence_threshold' => 0.7,
                            'max_cost_per_contact' => 0.10
                        ];
                    }
                }
            } catch (\Exception $e) {
                // Table doesn't exist, use defaults
                $enrichmentConfig = [
                    'auto_enrich_on_create' => false,
                    'auto_enrich_on_update' => false,
                    'auto_enrich_on_email' => false,
                    'confidence_threshold' => 0.7,
                    'max_cost_per_contact' => 0.10
                ];
            }
            ?>
            <form method="POST" action="" style="display: flex; flex-direction: column; gap: var(--spacing-lg); max-width: 600px;">
                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                <input type="hidden" name="tab" value="enrichment">
                
                <h3 style="color: var(--midnight-black); font-size: 18px; font-weight: 600; margin-bottom: var(--spacing-lg);">AI-Powered Data Enrichment</h3>
                
                <div>
                    <label style="display: flex; align-items: center; gap: var(--spacing-sm);">
                        <input 
                            type="checkbox" 
                            name="auto_enrich_contacts" 
                            <?php echo (($_ENV['AUTO_ENRICH_CONTACTS'] ?? 'false') === 'true' || ($enrichmentConfig['auto_enrich_on_create'] ?? false)) ? 'checked' : ''; ?>
                            style="width: 18px; height: 18px;"
                        >
                        <span style="color: var(--midnight-black); font-weight: 500;">Auto-enrich contacts on creation</span>
                    </label>
                    <small style="color: var(--charcoal-grey); font-size: 12px; display: block; margin-top: var(--spacing-xs);">
                        Automatically enrich contact data when a new contact is created.
                    </small>
                </div>
                
                <div>
                    <label style="display: flex; align-items: center; gap: var(--spacing-sm);">
                        <input 
                            type="checkbox" 
                            name="auto_enrich_on_update" 
                            <?php echo (($_ENV['AUTO_ENRICH_ON_UPDATE'] ?? 'false') === 'true' || ($enrichmentConfig['auto_enrich_on_update'] ?? false)) ? 'checked' : ''; ?>
                            style="width: 18px; height: 18px;"
                        >
                        <span style="color: var(--midnight-black); font-weight: 500;">Auto-enrich on contact update</span>
                    </label>
                    <small style="color: var(--charcoal-grey); font-size: 12px; display: block; margin-top: var(--spacing-xs);">
                        Re-enrich contacts when email or company fields are updated.
                    </small>
                </div>
                
                <div>
                    <label style="display: flex; align-items: center; gap: var(--spacing-sm);">
                        <input 
                            type="checkbox" 
                            name="auto_enrich_on_email" 
                            <?php echo (($_ENV['AUTO_ENRICH_ON_EMAIL'] ?? 'false') === 'true' || ($enrichmentConfig['auto_enrich_on_email'] ?? false)) ? 'checked' : ''; ?>
                            style="width: 18px; height: 18px;"
                        >
                        <span style="color: var(--midnight-black); font-weight: 500;">Extract data from incoming emails</span>
                    </label>
                    <small style="color: var(--charcoal-grey); font-size: 12px; display: block; margin-top: var(--spacing-xs);">
                        Extract contact information from email signatures and bodies.
                    </small>
                </div>
                
                <div>
                    <label for="confidence_threshold" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Confidence Threshold</label>
                    <input 
                        type="number" 
                        id="confidence_threshold" 
                        name="confidence_threshold" 
                        value="<?php echo htmlspecialchars($enrichmentConfig['confidence_threshold'] ?? 0.7); ?>"
                        min="0"
                        max="1"
                        step="0.1"
                        style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                    >
                    <small style="color: var(--charcoal-grey); font-size: 12px; margin-top: 4px; display: block;">
                        Minimum confidence score (0.0-1.0) required to accept enriched data. Higher values = more conservative.
                    </small>
                </div>
                
                <div>
                    <label for="max_cost_per_contact" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Max Cost Per Contact ($)</label>
                    <input 
                        type="number" 
                        id="max_cost_per_contact" 
                        name="max_cost_per_contact" 
                        value="<?php echo htmlspecialchars($enrichmentConfig['max_cost_per_contact'] ?? 0.10); ?>"
                        min="0"
                        max="10"
                        step="0.01"
                        style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                    >
                    <small style="color: var(--charcoal-grey); font-size: 12px; margin-top: 4px; display: block;">
                        Maximum cost allowed per contact enrichment operation.
                    </small>
                </div>
                
                <hr style="border: none; border-top: 1px solid var(--border-color); margin: var(--spacing-xl) 0;">
                
                <h3 style="color: var(--midnight-black); font-size: 18px; font-weight: 600; margin-bottom: var(--spacing-md);">Third-Party Enrichment APIs</h3>
                <p style="color: var(--charcoal-grey); font-size: 14px; margin-bottom: var(--spacing-sm);">
                    Configure verified enrichment APIs for authoritative contact profile fields. Structured fields like social URLs, company website, job title, and location are only auto-written from trusted APIs or exact values explicitly found in communication.
                </p>
                <p style="color: var(--charcoal-grey); font-size: 13px; margin-bottom: var(--spacing-lg);">
                    AI still adds context, summaries, and recommendations, but it does not guess profile fields or construct URLs when provider data is missing.
                </p>
                
                <div>
                    <label for="clearbit_api_key" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">
                        Clearbit API Key
                        <span style="color: var(--accent-blue); font-size: 12px; font-weight: normal;">⭐ Recommended</span>
                    </label>
                    <div style="position: relative;">
                        <input 
                            type="password" 
                            id="clearbit_api_key" 
                            name="clearbit_api_key" 
                            value=""
                            placeholder="<?php echo !empty($_ENV['CLEARBIT_API_KEY']) ? '•••••••• (enter new key to update, leave blank to keep existing)' : 'sk_...'; ?>"
                            style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px; padding-right: 120px;"
                        >
                        <?php if (!empty($_ENV['CLEARBIT_API_KEY'])): ?>
                            <span style="position: absolute; right: 8px; top: 50%; transform: translateY(-50%); color: #28a745; font-size: 12px; font-weight: 500;">✓ Configured</span>
                        <?php endif; ?>
                    </div>
                    <small style="color: var(--charcoal-grey); font-size: 12px; margin-top: 4px; display: block;">
                        Get your API key from <a href="https://clearbit.com" target="_blank" style="color: var(--accent-blue);">clearbit.com</a>. Free tier: 100 calls/month. Provides comprehensive contact and company data including LinkedIn profiles.
                    </small>
                </div>
                
                <div>
                    <label for="pdl_api_key" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">People Data Labs API Key</label>
                    <div style="position: relative;">
                        <input 
                            type="password" 
                            id="pdl_api_key" 
                            name="pdl_api_key" 
                            value=""
                            placeholder="<?php echo !empty($_ENV['PDL_API_KEY']) ? '•••••••• (enter new key to update, leave blank to keep existing)' : 'your_pdl_api_key'; ?>"
                            style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px; padding-right: 120px;"
                        >
                        <?php if (!empty($_ENV['PDL_API_KEY'])): ?>
                            <span style="position: absolute; right: 8px; top: 50%; transform: translateY(-50%); color: #28a745; font-size: 12px; font-weight: 500;">✓ Configured</span>
                        <?php endif; ?>
                    </div>
                    <small style="color: var(--charcoal-grey); font-size: 12px; margin-top: 4px; display: block;">
                        Get your API key from <a href="https://www.peopledatalabs.com" target="_blank" style="color: var(--accent-blue);">peopledatalabs.com</a>. Free tier: 100 calls/month. Excellent for professional profiles and LinkedIn data.
                    </small>
                </div>
                
                <div>
                    <label for="hunter_api_key" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Hunter.io API Key</label>
                    <div style="position: relative;">
                        <input 
                            type="password" 
                            id="hunter_api_key" 
                            name="hunter_api_key" 
                            value=""
                            placeholder="<?php echo !empty($_ENV['HUNTER_API_KEY']) ? '•••••••• (enter new key to update, leave blank to keep existing)' : 'your_hunter_api_key'; ?>"
                            style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px; padding-right: 120px;"
                        >
                        <?php if (!empty($_ENV['HUNTER_API_KEY'])): ?>
                            <span style="position: absolute; right: 8px; top: 50%; transform: translateY(-50%); color: #28a745; font-size: 12px; font-weight: 500;">✓ Configured</span>
                        <?php endif; ?>
                    </div>
                    <small style="color: var(--charcoal-grey); font-size: 12px; margin-top: 4px; display: block;">
                        Get your API key from <a href="https://hunter.io" target="_blank" style="color: var(--accent-blue);">hunter.io</a>. Free tier: 25 searches/month. Best for email verification and finding contact emails.
                    </small>
                </div>
                
                <div style="background: #e3f2fd; border-left: 4px solid var(--accent-blue); padding: var(--spacing-md); border-radius: 4px; margin-top: var(--spacing-md);">
                    <p style="color: var(--midnight-black); font-size: 14px; margin-bottom: var(--spacing-xs); font-weight: 500;">
                        💡 Why use third-party APIs?
                    </p>
                    <ul style="color: var(--charcoal-grey); font-size: 13px; margin-left: 20px; line-height: 1.6;">
                        <li><strong>Real LinkedIn URLs</strong> - Actual profiles from databases, not constructed URLs</li>
                        <li><strong>Verified Data</strong> - Professional databases with 85-90%+ accuracy</li>
                        <li><strong>Email Verification</strong> - Know if emails are valid and deliverable</li>
                        <li><strong>Complete Profiles</strong> - Phone numbers, locations, company details</li>
                    </ul>
                    <p style="color: var(--charcoal-grey); font-size: 12px; margin-top: var(--spacing-sm);">
                        If APIs are not configured, those structured fields remain unavailable for automatic verification rather than being guessed by AI.
                    </p>
                </div>
                
                <div style="background: var(--light-grey); padding: var(--spacing-md); border-radius: 4px; margin-top: var(--spacing-md);">
                    <p style="color: var(--charcoal-grey); font-size: 14px; margin-bottom: var(--spacing-xs);">
                        <strong>Note:</strong> The enrichment system now uses AI for context enrichment, summaries, and recommendations while verified APIs and explicit communication evidence control authoritative profile fields.
                    </p>
                    <p style="color: var(--charcoal-grey); font-size: 12px; margin-bottom: var(--spacing-sm);">
                        Manual enrichment from a contact page updates verified fields when trusted provider data exists and stores weaker communication-derived values as reviewable suggestions.
                    </p>
                </div>

                <?php
                $providerPanels = [
                    [
                        'name' => 'Clearbit',
                        'configured' => !empty($_ENV['CLEARBIT_API_KEY']),
                        'payload' => 'person.employment.title, person.location, person.linkedin.handle, company.domain',
                        'mapped' => ['job_title', 'location', 'linkedin_url', 'company_website', 'company_industry', 'company_description'],
                        'allowed' => ['job_title', 'location', 'linkedin_url', 'company_website'],
                        'missing' => ['Verified LinkedIn URL', 'Verified company website/domain', 'Professional title and location'],
                    ],
                    [
                        'name' => 'People Data Labs',
                        'configured' => !empty($_ENV['PDL_API_KEY']),
                        'payload' => 'job_title, job_company_website, location_name, linkedin_url, twitter_url',
                        'mapped' => ['job_title', 'company_website', 'location', 'linkedin_url', 'twitter_url', 'company_size'],
                        'allowed' => ['job_title', 'company_website', 'location', 'linkedin_url', 'twitter_url'],
                        'missing' => ['Verified social profile URLs', 'Professional title', 'Company website'],
                    ],
                    [
                        'name' => 'Hunter.io',
                        'configured' => !empty($_ENV['HUNTER_API_KEY']),
                        'payload' => 'email, email_verified, verification.status, company, domain',
                        'mapped' => ['email_verified', 'email_verification_status', 'company_website', 'company'],
                        'allowed' => ['email_verified', 'company_website'],
                        'missing' => ['Email verification status', 'Deliverability confidence', 'Verified domain-to-company match'],
                    ],
                ];
                ?>
                <div style="display: grid; gap: var(--spacing-md); margin-top: var(--spacing-md);">
                    <?php foreach ($providerPanels as $providerPanel): ?>
                        <div style="border: 1px solid var(--border-color); border-radius: 10px; padding: var(--spacing-md); background: white;">
                            <div style="display: flex; justify-content: space-between; gap: var(--spacing-md); align-items: start; margin-bottom: var(--spacing-sm);">
                                <div>
                                    <div style="font-weight: 600; color: var(--midnight-black);"><?php echo htmlspecialchars($providerPanel['name']); ?></div>
                                    <div style="font-size: 12px; color: var(--charcoal-grey); margin-top: 4px;">Sample provider payload: <code><?php echo htmlspecialchars($providerPanel['payload']); ?></code></div>
                                </div>
                                <span style="padding: 6px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; background: <?php echo $providerPanel['configured'] ? '#e8f7ef' : '#fff4e5'; ?>; color: <?php echo $providerPanel['configured'] ? '#1f7a46' : '#a65b00'; ?>;">
                                    <?php echo $providerPanel['configured'] ? 'Configured' : 'Missing key'; ?>
                                </span>
                            </div>
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: var(--spacing-md);">
                                <div>
                                    <div style="font-size: 12px; font-weight: 600; color: var(--midnight-black); margin-bottom: 6px;">Mapped CRM fields</div>
                                    <div style="font-size: 13px; color: var(--charcoal-grey);"><?php echo htmlspecialchars(implode(', ', $providerPanel['mapped'])); ?></div>
                                </div>
                                <div>
                                    <div style="font-size: 12px; font-weight: 600; color: var(--midnight-black); margin-bottom: 6px;">Allowed structured updates</div>
                                    <div style="font-size: 13px; color: var(--charcoal-grey);"><?php echo htmlspecialchars(implode(', ', $providerPanel['allowed'])); ?></div>
                                </div>
                                <div>
                                    <div style="font-size: 12px; font-weight: 600; color: var(--midnight-black); margin-bottom: 6px;">Unavailable when missing</div>
                                    <div style="font-size: 13px; color: var(--charcoal-grey);"><?php echo htmlspecialchars(implode(', ', $providerPanel['missing'])); ?></div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <div style="display: flex; gap: var(--spacing-md); justify-content: flex-end; margin-top: var(--spacing-md);">
                <button type="submit" class="btn-premium-primary">
                        Save Settings
                    </button>
                </div>
            </form>
            
        <?php elseif ($activeTab === 'sms'): ?>
            <form method="POST" action="" style="display: flex; flex-direction: column; gap: var(--spacing-lg); max-width: 760px;">
                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                <input type="hidden" name="tab" value="sms">
                <div style="background: var(--light-grey); padding: var(--spacing-lg); border-radius: 6px; border: 1px solid var(--border-color);">
                    <h3 style="margin-top: 0; margin-bottom: var(--spacing-sm); color: var(--midnight-black);">SMS setup moved to the workspace Marketplace</h3>
                    <p style="color: var(--charcoal-grey); font-size: 14px; margin-bottom: var(--spacing-md);">
                        Twilio credentials, sender numbers, readiness checks, and test sends are now saved per workspace in the SMS Channel setup surface.
                    </p>
                    <p style="color: var(--charcoal-grey); font-size: 14px; margin-bottom: 0;">
                        Legacy environment values remain available only as a compatibility fallback for the default workspace and superadmin runtime paths.
                    </p>
                </div>
                <div style="display: flex; gap: var(--spacing-md); justify-content: flex-end; margin-top: var(--spacing-md);">
                    <a href="workspace_skills.php?module=sms_channel#setup" class="btn-premium-primary" style="text-decoration: none;">
                        Open SMS Channel setup
                    </a>
                </div>
            </form>

        <?php elseif ($activeTab === 'ai_autoresponder'): ?>
            <?php
            $config = $aiAutoResponderConfig ?? [
                'enabled' => false,
                'mode' => 'draft_only',
                'default_confidence_threshold' => 0.85,
                'channels' => [
                    'email' => ['enabled' => true, 'confidence_threshold' => 0.88, 'max_chars' => 4000, 'draft_length_band' => 'balanced', 'draft_fullness' => 'balanced', 'draft_include_clear_cta' => true],
                    'whatsapp' => ['enabled' => true, 'confidence_threshold' => 0.90, 'max_chars' => 700, 'draft_length_band' => 'short', 'draft_fullness' => 'concise', 'draft_include_clear_cta' => true],
                    'sms' => ['enabled' => true, 'confidence_threshold' => 0.92, 'max_chars' => 320, 'draft_length_band' => 'short', 'draft_fullness' => 'concise', 'draft_include_clear_cta' => true],
                ],
                'quiet_hours' => ['enabled' => false, 'start' => '20:00', 'end' => '08:00', 'timezone' => 'UTC'],
                'safety' => [
                    'escalation_keywords' => ['refund', 'lawyer', 'legal', 'complaint', 'angry'],
                    'opt_out_keywords' => ['stop', 'unsubscribe', 'cancel'],
                    'max_auto_replies_per_contact_per_day' => 5,
                    'forbid_hallucinations' => true,
                    'require_human_for_sensitive_intents' => true,
                ],
            ];
            $quietTimezone = (string) ($config['quiet_hours']['timezone'] ?? 'UTC');
            if ($quietTimezone !== '' && !isset($aiAutoResponderQuietTimezoneOptions[$quietTimezone])) {
                $aiAutoResponderQuietTimezoneOptions = [$quietTimezone => $quietTimezone] + $aiAutoResponderQuietTimezoneOptions;
            }
            $stats = ['auto_sent' => 0, 'drafts' => 0, 'blocked' => 0, 'failed' => 0];
            try {
                $statsRow = Database::queryOne(
                    "SELECT
                        SUM(CASE WHEN decision = 'auto_sent' THEN 1 ELSE 0 END) AS auto_sent,
                        SUM(CASE WHEN decision = 'draft' THEN 1 ELSE 0 END) AS drafts,
                        SUM(CASE WHEN decision = 'blocked' THEN 1 ELSE 0 END) AS blocked,
                        SUM(CASE WHEN decision = 'failed' THEN 1 ELSE 0 END) AS failed
                     FROM ai_autoresponder_logs
                     WHERE workspace_id = ?
                       AND created_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)",
                    [$activeWorkspaceId]
                );
                if ($statsRow) {
                    $stats['auto_sent'] = (int) ($statsRow['auto_sent'] ?? 0);
                    $stats['drafts'] = (int) ($statsRow['drafts'] ?? 0);
                    $stats['blocked'] = (int) ($statsRow['blocked'] ?? 0);
                    $stats['failed'] = (int) ($statsRow['failed'] ?? 0);
                }
            } catch (\Throwable $e) {
                // table may not exist if migration not run
            }
            ?>
            <form method="POST" action="" style="display: flex; flex-direction: column; gap: var(--spacing-lg); max-width: 840px;">
                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                <input type="hidden" name="tab" value="ai_autoresponder">

                <?php if ($canManageGlobalAiAutoResponder): ?>
                <div class="content-card" style="background: #f8fafc;">
                    <h3 style="color: #0f172a; margin-bottom: 0.75rem; font-size: 1.125rem; font-weight: 600;">AI Auto-Responder</h3>
                    <p style="color: #64748b; margin-bottom: 1rem; font-size: 0.875rem;">
                        Configure AI replies across Email, WhatsApp, and SMS using your business context. Start with Draft only, then graduate to Hybrid.
                    </p>

                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div>
                            <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                                <input type="checkbox" name="ai_auto_enabled" value="1" <?php echo !empty($config['enabled']) ? 'checked' : ''; ?> style="width: 1.125rem; height: 1.125rem;">
                                <span style="font-weight: 500; color: #0f172a;">Enable AI auto-responder</span>
                            </label>
                        </div>
                        <div>
                            <label for="ai_auto_mode" style="display: block; margin-bottom: 0.4rem; font-weight: 500; font-size: 0.875rem;">Mode</label>
                            <select id="ai_auto_mode" name="ai_auto_mode" style="width: 100%; padding: 0.5rem; border: 1px solid rgba(0,0,0,0.1); border-radius: 4px;">
                                <option value="off" <?php echo ($config['mode'] ?? '') === 'off' ? 'selected' : ''; ?>>Off</option>
                                <option value="draft_only" <?php echo ($config['mode'] ?? '') === 'draft_only' ? 'selected' : ''; ?>>Draft only</option>
                                <option value="hybrid" <?php echo ($config['mode'] ?? '') === 'hybrid' ? 'selected' : ''; ?>>Hybrid (auto above confidence)</option>
                                <option value="full_auto" <?php echo ($config['mode'] ?? '') === 'full_auto' ? 'selected' : ''; ?>>Full auto (strict policy)</option>
                            </select>
                        </div>
                        <div>
                            <label for="ai_auto_default_confidence" style="display: block; margin-bottom: 0.4rem; font-weight: 500; font-size: 0.875rem;">Default confidence threshold</label>
                            <input type="number" id="ai_auto_default_confidence" name="ai_auto_default_confidence" min="0" max="1" step="0.01" value="<?php echo htmlspecialchars((string) ($config['default_confidence_threshold'] ?? 0.85)); ?>" style="width: 100%; padding: 0.5rem; border: 1px solid rgba(0,0,0,0.1); border-radius: 4px;">
                        </div>
                        <div>
                            <label for="ai_auto_max_replies_per_day" style="display: block; margin-bottom: 0.4rem; font-weight: 500; font-size: 0.875rem;">Max auto replies/contact/day</label>
                            <input type="number" id="ai_auto_max_replies_per_day" name="ai_auto_max_replies_per_day" min="1" max="100" value="<?php echo (int) ($config['safety']['max_auto_replies_per_contact_per_day'] ?? 5); ?>" style="width: 100%; padding: 0.5rem; border: 1px solid rgba(0,0,0,0.1); border-radius: 4px;">
                        </div>
                    </div>
                </div>

                <div class="content-card" style="background: #f8fafc;">
                    <h4 style="color: #0f172a; margin-bottom: 0.75rem; font-size: 1rem; font-weight: 600;">Per Channel</h4>
                    <p style="margin: 0 0 1rem 0; color: #64748b; font-size: 0.875rem;">
                        Personal tone and voice come from each user's Strategy profile. These controls shape channel behavior and safety for automated drafts.
                    </p>
                    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem;">
                        <div>
                            <label style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem;">
                                <input type="checkbox" name="ai_auto_email_enabled" value="1" <?php echo !empty($config['channels']['email']['enabled']) ? 'checked' : ''; ?>>
                                <span style="font-weight: 500;">Email</span>
                            </label>
                            <label style="display:block; margin-bottom:0.35rem; color:#64748b; font-size:0.75rem;">Confidence threshold</label>
                            <input type="number" name="ai_auto_email_confidence" min="0" max="1" step="0.01" value="<?php echo htmlspecialchars((string) ($config['channels']['email']['confidence_threshold'] ?? 0.88)); ?>" style="width: 100%; margin-bottom: 0.5rem; padding: 0.5rem; border: 1px solid rgba(0,0,0,0.1); border-radius: 4px;">
                            <label style="display:block; margin-bottom:0.35rem; color:#64748b; font-size:0.75rem;">Max characters</label>
                            <input type="number" name="ai_auto_email_max_chars" min="100" max="10000" value="<?php echo (int) ($config['channels']['email']['max_chars'] ?? 4000); ?>" style="width: 100%; padding: 0.5rem; border: 1px solid rgba(0,0,0,0.1); border-radius: 4px;">
                            <label style="display:block; margin:0.5rem 0 0.35rem; color:#64748b; font-size:0.75rem;">Draft length</label>
                            <select name="ai_auto_email_length_band" style="width:100%; margin-bottom:0.5rem; padding:0.5rem; border:1px solid rgba(0,0,0,0.1); border-radius:4px;">
                                <option value="short" <?php echo (($config['channels']['email']['draft_length_band'] ?? 'balanced') === 'short') ? 'selected' : ''; ?>>Short</option>
                                <option value="balanced" <?php echo (($config['channels']['email']['draft_length_band'] ?? 'balanced') === 'balanced') ? 'selected' : ''; ?>>Balanced</option>
                                <option value="detailed" <?php echo (($config['channels']['email']['draft_length_band'] ?? 'balanced') === 'detailed') ? 'selected' : ''; ?>>Detailed</option>
                            </select>
                            <label style="display:block; margin-bottom:0.35rem; color:#64748b; font-size:0.75rem;">Draft fullness</label>
                            <select name="ai_auto_email_fullness" style="width:100%; margin-bottom:0.5rem; padding:0.5rem; border:1px solid rgba(0,0,0,0.1); border-radius:4px;">
                                <option value="concise" <?php echo (($config['channels']['email']['draft_fullness'] ?? 'balanced') === 'concise') ? 'selected' : ''; ?>>Concise</option>
                                <option value="balanced" <?php echo (($config['channels']['email']['draft_fullness'] ?? 'balanced') === 'balanced') ? 'selected' : ''; ?>>Balanced</option>
                                <option value="fuller" <?php echo (($config['channels']['email']['draft_fullness'] ?? 'balanced') === 'fuller') ? 'selected' : ''; ?>>Fuller</option>
                            </select>
                            <label style="display:flex; align-items:center; gap:0.5rem; margin-top:0.35rem;">
                                <input type="checkbox" name="ai_auto_email_include_cta" value="1" <?php echo !empty($config['channels']['email']['draft_include_clear_cta']) ? 'checked' : ''; ?>>
                                <span style="font-size:0.875rem;">Include clear next-step CTA</span>
                            </label>
                        </div>
                        <div>
                            <label style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem;">
                                <input type="checkbox" name="ai_auto_whatsapp_enabled" value="1" <?php echo !empty($config['channels']['whatsapp']['enabled']) ? 'checked' : ''; ?>>
                                <span style="font-weight: 500;">WhatsApp</span>
                            </label>
                            <label style="display:block; margin-bottom:0.35rem; color:#64748b; font-size:0.75rem;">Confidence threshold</label>
                            <input type="number" name="ai_auto_whatsapp_confidence" min="0" max="1" step="0.01" value="<?php echo htmlspecialchars((string) ($config['channels']['whatsapp']['confidence_threshold'] ?? 0.90)); ?>" style="width: 100%; margin-bottom: 0.5rem; padding: 0.5rem; border: 1px solid rgba(0,0,0,0.1); border-radius: 4px;">
                            <label style="display:block; margin-bottom:0.35rem; color:#64748b; font-size:0.75rem;">Max characters</label>
                            <input type="number" name="ai_auto_whatsapp_max_chars" min="60" max="2000" value="<?php echo (int) ($config['channels']['whatsapp']['max_chars'] ?? 700); ?>" style="width: 100%; padding: 0.5rem; border: 1px solid rgba(0,0,0,0.1); border-radius: 4px;">
                            <label style="display:block; margin:0.5rem 0 0.35rem; color:#64748b; font-size:0.75rem;">Draft length</label>
                            <select name="ai_auto_whatsapp_length_band" style="width:100%; margin-bottom:0.5rem; padding:0.5rem; border:1px solid rgba(0,0,0,0.1); border-radius:4px;">
                                <option value="short" <?php echo (($config['channels']['whatsapp']['draft_length_band'] ?? 'short') === 'short') ? 'selected' : ''; ?>>Short</option>
                                <option value="balanced" <?php echo (($config['channels']['whatsapp']['draft_length_band'] ?? 'short') === 'balanced') ? 'selected' : ''; ?>>Balanced</option>
                                <option value="detailed" <?php echo (($config['channels']['whatsapp']['draft_length_band'] ?? 'short') === 'detailed') ? 'selected' : ''; ?>>Detailed</option>
                            </select>
                            <label style="display:block; margin-bottom:0.35rem; color:#64748b; font-size:0.75rem;">Draft fullness</label>
                            <select name="ai_auto_whatsapp_fullness" style="width:100%; margin-bottom:0.5rem; padding:0.5rem; border:1px solid rgba(0,0,0,0.1); border-radius:4px;">
                                <option value="concise" <?php echo (($config['channels']['whatsapp']['draft_fullness'] ?? 'concise') === 'concise') ? 'selected' : ''; ?>>Concise</option>
                                <option value="balanced" <?php echo (($config['channels']['whatsapp']['draft_fullness'] ?? 'concise') === 'balanced') ? 'selected' : ''; ?>>Balanced</option>
                                <option value="fuller" <?php echo (($config['channels']['whatsapp']['draft_fullness'] ?? 'concise') === 'fuller') ? 'selected' : ''; ?>>Fuller</option>
                            </select>
                            <label style="display:flex; align-items:center; gap:0.5rem; margin-top:0.35rem;">
                                <input type="checkbox" name="ai_auto_whatsapp_include_cta" value="1" <?php echo !empty($config['channels']['whatsapp']['draft_include_clear_cta']) ? 'checked' : ''; ?>>
                                <span style="font-size:0.875rem;">Include clear next-step CTA</span>
                            </label>
                        </div>
                        <div>
                            <label style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem;">
                                <input type="checkbox" name="ai_auto_sms_enabled" value="1" <?php echo !empty($config['channels']['sms']['enabled']) ? 'checked' : ''; ?>>
                                <span style="font-weight: 500;">SMS</span>
                            </label>
                            <label style="display:block; margin-bottom:0.35rem; color:#64748b; font-size:0.75rem;">Confidence threshold</label>
                            <input type="number" name="ai_auto_sms_confidence" min="0" max="1" step="0.01" value="<?php echo htmlspecialchars((string) ($config['channels']['sms']['confidence_threshold'] ?? 0.92)); ?>" style="width: 100%; margin-bottom: 0.5rem; padding: 0.5rem; border: 1px solid rgba(0,0,0,0.1); border-radius: 4px;">
                            <label style="display:block; margin-bottom:0.35rem; color:#64748b; font-size:0.75rem;">Max characters</label>
                            <input type="number" name="ai_auto_sms_max_chars" min="60" max="1000" value="<?php echo (int) ($config['channels']['sms']['max_chars'] ?? 320); ?>" style="width: 100%; padding: 0.5rem; border: 1px solid rgba(0,0,0,0.1); border-radius: 4px;">
                            <label style="display:block; margin:0.5rem 0 0.35rem; color:#64748b; font-size:0.75rem;">Draft length</label>
                            <select name="ai_auto_sms_length_band" style="width:100%; margin-bottom:0.5rem; padding:0.5rem; border:1px solid rgba(0,0,0,0.1); border-radius:4px;">
                                <option value="short" <?php echo (($config['channels']['sms']['draft_length_band'] ?? 'short') === 'short') ? 'selected' : ''; ?>>Short</option>
                                <option value="balanced" <?php echo (($config['channels']['sms']['draft_length_band'] ?? 'short') === 'balanced') ? 'selected' : ''; ?>>Balanced</option>
                                <option value="detailed" <?php echo (($config['channels']['sms']['draft_length_band'] ?? 'short') === 'detailed') ? 'selected' : ''; ?>>Detailed</option>
                            </select>
                            <label style="display:block; margin-bottom:0.35rem; color:#64748b; font-size:0.75rem;">Draft fullness</label>
                            <select name="ai_auto_sms_fullness" style="width:100%; margin-bottom:0.5rem; padding:0.5rem; border:1px solid rgba(0,0,0,0.1); border-radius:4px;">
                                <option value="concise" <?php echo (($config['channels']['sms']['draft_fullness'] ?? 'concise') === 'concise') ? 'selected' : ''; ?>>Concise</option>
                                <option value="balanced" <?php echo (($config['channels']['sms']['draft_fullness'] ?? 'concise') === 'balanced') ? 'selected' : ''; ?>>Balanced</option>
                                <option value="fuller" <?php echo (($config['channels']['sms']['draft_fullness'] ?? 'concise') === 'fuller') ? 'selected' : ''; ?>>Fuller</option>
                            </select>
                            <label style="display:flex; align-items:center; gap:0.5rem; margin-top:0.35rem;">
                                <input type="checkbox" name="ai_auto_sms_include_cta" value="1" <?php echo !empty($config['channels']['sms']['draft_include_clear_cta']) ? 'checked' : ''; ?>>
                                <span style="font-size:0.875rem;">Include clear next-step CTA</span>
                            </label>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                <div class="content-card" style="background: #f8fafc;">
                    <h3 style="color: #0f172a; margin-bottom: 0.75rem; font-size: 1.125rem; font-weight: 600;">AI Auto-Responder Quiet Hours</h3>
                    <p style="color: #64748b; margin-bottom: 0; font-size: 0.875rem;">
                        Set when this workspace should hold AI auto-responder replies for review instead of sending automatically.
                    </p>
                </div>
                <?php endif; ?>

                <div class="content-card" style="background: #f8fafc;">
                    <h4 style="color: #0f172a; margin-bottom: 0.75rem; font-size: 1rem; font-weight: 600;"><?php echo $canManageGlobalAiAutoResponder ? 'Safety and Compliance' : 'Workspace Quiet Hours'; ?></h4>
                    <div style="display: grid; grid-template-columns: <?php echo $canManageGlobalAiAutoResponder ? '1fr 1fr' : '1fr'; ?>; gap: 1rem;">
                        <?php if ($canManageGlobalAiAutoResponder): ?>
                        <div>
                            <label for="ai_auto_escalation_keywords" style="display: block; margin-bottom: 0.4rem; font-weight: 500; font-size: 0.875rem;">Escalation keywords</label>
                            <textarea id="ai_auto_escalation_keywords" name="ai_auto_escalation_keywords" rows="3" style="width: 100%; padding: 0.5rem; border: 1px solid rgba(0,0,0,0.1); border-radius: 4px;"><?php echo htmlspecialchars(implode(', ', $config['safety']['escalation_keywords'] ?? [])); ?></textarea>
                        </div>
                        <div>
                            <label for="ai_auto_optout_keywords" style="display: block; margin-bottom: 0.4rem; font-weight: 500; font-size: 0.875rem;">Opt-out keywords</label>
                            <textarea id="ai_auto_optout_keywords" name="ai_auto_optout_keywords" rows="3" style="width: 100%; padding: 0.5rem; border: 1px solid rgba(0,0,0,0.1); border-radius: 4px;"><?php echo htmlspecialchars(implode(', ', $config['safety']['opt_out_keywords'] ?? [])); ?></textarea>
                        </div>
                        <div>
                            <label style="display: flex; align-items: center; gap: 0.5rem; margin-top: 0.5rem;">
                                <input type="checkbox" name="ai_auto_forbid_hallucinations" value="1" <?php echo !empty($config['safety']['forbid_hallucinations']) ? 'checked' : ''; ?>>
                                <span>Forbid invented facts</span>
                            </label>
                            <label style="display: flex; align-items: center; gap: 0.5rem; margin-top: 0.5rem;">
                                <input type="checkbox" name="ai_auto_require_human_sensitive" value="1" <?php echo !empty($config['safety']['require_human_for_sensitive_intents']) ? 'checked' : ''; ?>>
                                <span>Require human review for sensitive intents</span>
                            </label>
                        </div>
                        <?php endif; ?>
                        <div>
                            <label style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.5rem;">
                                <input type="checkbox" name="ai_auto_quiet_hours_enabled" value="1" <?php echo !empty($config['quiet_hours']['enabled']) ? 'checked' : ''; ?>>
                                <span>Enable quiet hours</span>
                            </label>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.5rem;">
                                <input type="time" name="ai_auto_quiet_start" value="<?php echo htmlspecialchars((string) ($config['quiet_hours']['start'] ?? '20:00')); ?>" style="padding: 0.5rem; border: 1px solid rgba(0,0,0,0.1); border-radius: 4px;">
                                <input type="time" name="ai_auto_quiet_end" value="<?php echo htmlspecialchars((string) ($config['quiet_hours']['end'] ?? '08:00')); ?>" style="padding: 0.5rem; border: 1px solid rgba(0,0,0,0.1); border-radius: 4px;">
                            </div>
                            <select name="ai_auto_quiet_timezone" style="margin-top: 0.5rem; width: 100%; padding: 0.5rem; border: 1px solid rgba(0,0,0,0.1); border-radius: 4px; background: #fff;">
                                <?php foreach ($aiAutoResponderQuietTimezoneOptions as $timezoneValue => $timezoneLabel): ?>
                                    <option value="<?php echo htmlspecialchars((string) $timezoneValue); ?>" <?php echo (string) $timezoneValue === $quietTimezone ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars((string) $timezoneLabel); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="content-card" style="background: #ffffff;">
                    <h4 style="color: #0f172a; margin-bottom: 0.75rem; font-size: 1rem; font-weight: 600;">Last 24 Hours</h4>
                    <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.75rem;">
                        <div style="background: #f1f5f9; border-radius: 8px; padding: 0.75rem;"><div style="font-size: 0.75rem; color: #64748b;">Auto sent</div><div style="font-weight: 700; font-size: 1.1rem;"><?php echo (int) $stats['auto_sent']; ?></div></div>
                        <div style="background: #f1f5f9; border-radius: 8px; padding: 0.75rem;"><div style="font-size: 0.75rem; color: #64748b;">Drafted</div><div style="font-weight: 700; font-size: 1.1rem;"><?php echo (int) $stats['drafts']; ?></div></div>
                        <div style="background: #f1f5f9; border-radius: 8px; padding: 0.75rem;"><div style="font-size: 0.75rem; color: #64748b;">Blocked</div><div style="font-weight: 700; font-size: 1.1rem;"><?php echo (int) $stats['blocked']; ?></div></div>
                        <div style="background: #f1f5f9; border-radius: 8px; padding: 0.75rem;"><div style="font-size: 0.75rem; color: #64748b;">Failed</div><div style="font-weight: 700; font-size: 1.1rem;"><?php echo (int) $stats['failed']; ?></div></div>
                    </div>
                </div>

                <button type="submit" class="btn-premium-primary"><?php echo $canManageGlobalAiAutoResponder ? 'Save Settings' : 'Save Quiet Hours'; ?></button>
            </form>
            
        <?php elseif ($activeTab === 'scoring'): ?>
            <?php
            // Get current default weights
            try {
                $scoringConfig = (new WorkspaceScoringConfigService())->getConfig($activeWorkspaceId);
            } catch (\Throwable $e) {
                $scoringConfig = [
                    'default_score_weights' => AILeadScoring::DEFAULT_WEIGHTS,
                    'auto_use_recommended_weights' => false,
                ];
            }
            $defaultWeights = $scoringConfig['default_score_weights'] ?? AILeadScoring::DEFAULT_WEIGHTS;
            $autoUseRecommendedWeights = !empty($scoringConfig['auto_use_recommended_weights']);
            ?>
            <form method="POST" action="" style="display: flex; flex-direction: column; gap: var(--spacing-lg); max-width: 800px;">
                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                <input type="hidden" name="tab" value="scoring">
                
                <h2 style="margin-top: 0; margin-bottom: var(--spacing-md);">Score Weight Configuration</h2>
                <p style="color: var(--charcoal-grey); margin-bottom: var(--spacing-md);">
                    Configure the default weights for the three-score system. Weights must sum to 100%.
                    You can override these per-contact or use recommended weights based on data availability.
                </p>
                
                <div style="background: #f8f9fa; padding: var(--spacing-md); border-radius: 8px; margin-bottom: var(--spacing-md);">
                    <h3 style="margin-top: 0; margin-bottom: var(--spacing-sm); font-size: 16px;">Score Definitions</h3>
                    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: var(--spacing-md);">
                        <div style="background: white; padding: var(--spacing-sm); border-radius: 4px; border-left: 4px solid #28a745;">
                            <div style="font-weight: 600; margin-bottom: 4px;">Engagement Score</div>
                            <div style="font-size: 12px; color: var(--charcoal-grey);">Data-driven score based on activities, email opens, clicks, form submissions</div>
                        </div>
                        <div style="background: white; padding: var(--spacing-sm); border-radius: 4px; border-left: 4px solid var(--accent-blue);">
                            <div style="font-weight: 600; margin-bottom: 4px;">ML Score</div>
                            <div style="font-size: 12px; color: var(--charcoal-grey);">Pattern-based prediction from machine learning models trained on historical conversions</div>
                        </div>
                        <div style="background: white; padding: var(--spacing-sm); border-radius: 4px; border-left: 4px solid #9c27b0;">
                            <div style="font-weight: 600; margin-bottom: 4px;">AI Score</div>
                            <div style="font-size: 12px; color: var(--charcoal-grey);">Insight-based assessment from AI analysis of profile, role level, industry, and data quality</div>
                        </div>
                    </div>
                </div>
                
                <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: var(--spacing-md);">
                    <div>
                        <label for="engagement_weight" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Engagement Weight (%)</label>
                        <input 
                            type="number" 
                            id="engagement_weight" 
                            name="engagement_weight" 
                            value="<?php echo number_format($defaultWeights['engagement'] * 100, 1); ?>"
                            min="0" 
                            max="100" 
                            step="0.1"
                            oninput="updateWeightTotal()"
                            style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                        >
                        <small style="color: var(--charcoal-grey); font-size: 12px; display: block; margin-top: var(--spacing-xs);">
                            Recommended: 40%
                        </small>
                    </div>
                    
                    <div>
                        <label for="ml_weight" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">ML Weight (%)</label>
                        <input 
                            type="number" 
                            id="ml_weight" 
                            name="ml_weight" 
                            value="<?php echo number_format($defaultWeights['ml'] * 100, 1); ?>"
                            min="0" 
                            max="100" 
                            step="0.1"
                            oninput="updateWeightTotal()"
                            style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                        >
                        <small style="color: var(--charcoal-grey); font-size: 12px; display: block; margin-top: var(--spacing-xs);">
                            Recommended: 40%
                        </small>
                    </div>
                    
                    <div>
                        <label for="ai_weight" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">AI Weight (%)</label>
                        <input 
                            type="number" 
                            id="ai_weight" 
                            name="ai_weight" 
                            value="<?php echo number_format($defaultWeights['ai'] * 100, 1); ?>"
                            min="0" 
                            max="100" 
                            step="0.1"
                            oninput="updateWeightTotal()"
                            style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"
                        >
                        <small style="color: var(--charcoal-grey); font-size: 12px; display: block; margin-top: var(--spacing-xs);">
                            Recommended: 20%
                        </small>
                    </div>
                </div>
                
                <div id="weight_total" style="padding: var(--spacing-sm); background: #e3f2fd; border-radius: 4px; margin-top: var(--spacing-sm);">
                    <strong>Total:</strong> <span id="total_percent">100.0</span>%
                </div>
                
                <div>
                    <label style="display: flex; align-items: center; gap: var(--spacing-sm);">
                            <input
                            type="checkbox"
                            name="auto_use_recommended_weights"
                            <?php echo $autoUseRecommendedWeights ? 'checked' : ''; ?>
                            style="width: 18px; height: 18px;"
                        >
                        <span style="color: var(--midnight-black); font-weight: 500;">Automatically use recommended weights per contact</span>
                    </label>
                    <small style="color: var(--charcoal-grey); font-size: 12px; display: block; margin-top: var(--spacing-xs); margin-left: 26px;">
                        When enabled, the system will automatically use recommended weights based on data availability and confidence for each contact, instead of the default weights above.
                    </small>
                </div>
                
                <div style="display: flex; gap: var(--spacing-md); justify-content: flex-end; margin-top: var(--spacing-md);">
                <button type="submit" class="btn-premium-primary">
                        Save Settings
                    </button>
                </div>
            </form>
            
            <script>
            function updateWeightTotal() {
                const engagement = parseFloat(document.getElementById('engagement_weight').value) || 0;
                const ml = parseFloat(document.getElementById('ml_weight').value) || 0;
                const ai = parseFloat(document.getElementById('ai_weight').value) || 0;
                const total = engagement + ml + ai;
                
                const totalSpan = document.getElementById('total_percent');
                totalSpan.textContent = total.toFixed(1);
                
                const totalDiv = document.getElementById('weight_total');
                if (Math.abs(total - 100) < 0.1) {
                    totalDiv.style.background = '#d4edda';
                    totalDiv.style.border = '1px solid #28a745';
                } else {
                    totalDiv.style.background = '#f8d7da';
                    totalDiv.style.border = '1px solid #dc3545';
                }
            }
            updateWeightTotal();
            </script>
            
        <?php elseif ($activeTab === 'company'): ?>
            <?php
            require_once __DIR__ . '/../modules/CompanyProfile.php';

            $companyProfileModule = new CompanyProfile();
            $companyProfile = $companyProfileModule->get();
            $beginnerBudget = null;
            try {
                require_once __DIR__ . '/../modules/BeginnerBudget.php';
                $budgetModule = new \CRM\Modules\BeginnerBudget();
                $beginnerBudget = $budgetModule->get($userId);
            } catch (\Throwable $e) {
                error_log('BeginnerBudget not available: ' . $e->getMessage());
            }
            $companyLogoPath = trim((string) ($companyProfile['company_logo_url'] ?? ''));
            $companyLogoPreview = '';
            if ($companyLogoPath !== '') {
                $companyLogoPreview = preg_match('#^(https?:)?//#i', $companyLogoPath) || str_starts_with($companyLogoPath, 'data:image/')
                    ? $companyLogoPath
                    : getBasePath() . '/../' . ltrim($companyLogoPath, '/');
            }
            ?>
            
            <div style="max-width: 860px;">
                <div>
                    <h3 style="color: var(--midnight-black); font-size: 18px; font-weight: 600; margin-bottom: var(--spacing-lg);">Company Profile</h3>
                    <form method="POST" action="" enctype="multipart/form-data" style="display: flex; flex-direction: column; gap: var(--spacing-md);">
                        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                        <input type="hidden" name="tab" value="company">
                        <input type="hidden" name="expected_lock_version" value="<?php echo (int) ($companyProfile['lock_version'] ?? 0); ?>">

                        <div class="content-card" style="margin-bottom: var(--spacing-md); background: #f8fafc; border: 1px solid #e2e8f0;">
                            <h4 style="color: #0f172a; margin-bottom: 0.75rem; font-size: 1rem; font-weight: 600;">GDPR & Privacy</h4>
                            <p style="color: #64748b; margin-bottom: 1rem; font-size: 0.875rem;">
                                Keep your public privacy details with the rest of the company profile so legal pages and GDPR requests stay aligned.
                            </p>
                            <div style="display: flex; gap: 0.75rem; flex-wrap: wrap; margin-bottom: 1rem;">
                                <a href="gdpr-request.php" target="_blank" class="btn-premium-primary">Submit GDPR Request</a>
                                <a href="privacy-policy.php" target="_blank" class="btn-premium-secondary">View Privacy Policy</a>
                            </div>
                            <div>
                                <label for="privacy_contact_email" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Privacy Contact Email</label>
                                <input type="email" id="privacy_contact_email" name="privacy_contact_email" value="<?php echo htmlspecialchars($_ENV['PRIVACY_CONTACT_EMAIL'] ?? ''); ?>" placeholder="privacy@example.com" style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;">
                                <small style="color: var(--charcoal-grey); font-size: 0.75rem; display: block; margin-top: 0.25rem;">
                                    Email address used for privacy inquiries and GDPR requests.
                                </small>
                            </div>
                        </div>
                        
                        <div>
                            <label for="company_name" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Company Name *</label>
                            <input type="text" id="company_name" name="company_name" value="<?php echo htmlspecialchars(($companyProfile['company_name'] ?? '') !== '' ? $companyProfile['company_name'] : ($_ENV['COMPANY_NAME'] ?? '')); ?>" required style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;">
                            <small style="color: var(--charcoal-grey); font-size: 0.75rem; display: block; margin-top: 0.25rem;">
                                Company name used across workspace setup and customer-facing documents.
                            </small>
                        </div>

                        <div>
                            <label for="company_legal_name" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Legal / Billing Name</label>
                            <input type="text" id="company_legal_name" name="company_legal_name" value="<?php echo htmlspecialchars($companyProfile['company_legal_name'] ?? ''); ?>" placeholder="Defaults to company name if blank" style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;">
                            <small style="color: var(--charcoal-grey); font-size: 0.75rem; display: block; margin-top: 0.25rem;">
                                Used as the sender identity on invoices, quotes, proformas, and finance reports.
                            </small>
                        </div>

                        <div>
                            <label for="company_tax_id" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Tax ID / VAT</label>
                            <input type="text" id="company_tax_id" name="company_tax_id" value="<?php echo htmlspecialchars($companyProfile['company_tax_id'] ?? ''); ?>" placeholder="Optional tax or VAT registration" style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;">
                        </div>

                        <div>
                            <label for="company_logo_file" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Company Logo</label>
                            <div style="display:grid;grid-template-columns:minmax(0,220px) 1fr;gap:1rem;align-items:start;">
                                <div style="border:1px dashed rgba(0,0,0,0.15);border-radius:12px;padding:1rem;background:#fcfcfd;min-height:120px;display:flex;align-items:center;justify-content:center;">
                                    <?php if ($companyLogoPreview !== ''): ?>
                                        <img src="<?php echo htmlspecialchars($companyLogoPreview); ?>" alt="Company logo" style="max-width:180px;max-height:72px;object-fit:contain;display:block;">
                                    <?php else: ?>
                                        <span style="font-size:0.875rem;color:#64748b;">No logo uploaded</span>
                                    <?php endif; ?>
                                </div>
                                <div style="display:grid;gap:.6rem;">
                                    <input type="file" id="company_logo_file" name="company_logo_file" accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml" style="width:100%;padding:.6rem;border:1px solid rgba(0,0,0,0.1);border-radius:6px;background:white;">
                                    <small style="color:#64748b;">Used for invoices, quotes, proformas, and finance reports. PNG, JPG, GIF, WebP, or SVG up to 2MB.</small>
                                    <?php if ($companyLogoPreview !== ''): ?>
                                        <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;">
                                            <input type="checkbox" name="company_logo_remove" value="1">
                                            <span>Remove current company logo</span>
                                        </label>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <div>
                            <label for="company_tagline" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Tagline</label>
                            <input type="text" id="company_tagline" name="company_tagline" value="<?php echo htmlspecialchars($companyProfile['company_tagline'] ?? ''); ?>" placeholder="Your company tagline" style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;">
                        </div>
                        
                        <div>
                            <label for="company_description" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Description</label>
                            <textarea id="company_description" name="company_description" rows="3" placeholder="Brief description of your company" style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"><?php echo htmlspecialchars($companyProfile['company_description'] ?? ''); ?></textarea>
                        </div>
                        
                        <div>
                            <label for="company_mission" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Mission Statement</label>
                            <textarea id="company_mission" name="company_mission" rows="2" placeholder="Your company's mission" style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"><?php echo htmlspecialchars($companyProfile['company_mission'] ?? ''); ?></textarea>
                        </div>
                        
                        <div>
                            <label for="company_values" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Core Values</label>
                            <textarea id="company_values" name="company_values" rows="2" placeholder="Your company's core values" style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"><?php echo htmlspecialchars($companyProfile['company_values'] ?? ''); ?></textarea>
                        </div>

                        <div>
                            <label for="owner_company_context" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Additional Company Context From Owner</label>
                            <textarea id="owner_company_context" name="owner_company_context" rows="4" placeholder="Optional notes for AI: important positioning, delivery model, customer nuances, promises to avoid, team realities, or anything else the owner wants the system to remember." style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"><?php echo htmlspecialchars($companyProfile['owner_company_context'] ?? ''); ?></textarea>
                            <small style="color: var(--charcoal-grey); font-size: 0.75rem; display: block; margin-top: 0.25rem;">
                                Optional. This gives AI extra business context without making the section required.
                            </small>
                        </div>
                        
                        <div>
                            <label for="company_industry" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Industry</label>
                            <input type="text" id="company_industry" name="company_industry" value="<?php echo htmlspecialchars($companyProfile['company_industry'] ?? ''); ?>" placeholder="e.g., Technology, Healthcare" style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;">
                        </div>
                        
                        <div>
                            <label for="company_website" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Website</label>
                            <input type="url" id="company_website" name="company_website" value="<?php echo htmlspecialchars($companyProfile['company_website'] ?? ''); ?>" placeholder="https://example.com" style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;">
                        </div>
                        
                        <div>
                            <label for="company_email" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Email</label>
                            <input type="email" id="company_email" name="company_email" value="<?php echo htmlspecialchars($companyProfile['company_email'] ?? ''); ?>" placeholder="contact@example.com" style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;">
                        </div>
                        
                        <div>
                            <label for="company_phone" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Phone</label>
                            <input type="tel" id="company_phone" name="company_phone" value="<?php echo htmlspecialchars($companyProfile['company_phone'] ?? ''); ?>" placeholder="+1 (555) 123-4567" style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;">
                        </div>
                        
                        <div>
                            <label for="company_address" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Address</label>
                            <textarea id="company_address" name="company_address" rows="2" placeholder="Street address" style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"><?php echo htmlspecialchars($companyProfile['company_address'] ?? ''); ?></textarea>
                        </div>
                        
                        <div>
                            <label for="company_location" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Location</label>
                            <input type="text" id="company_location" name="company_location" value="<?php echo htmlspecialchars($companyProfile['company_location'] ?? ''); ?>" placeholder="City, State, Country" style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;">
                        </div>
                        
                        <div>
                            <label for="company_timezone" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Timezone</label>
                            <input type="text" id="company_timezone" name="company_timezone" value="<?php echo htmlspecialchars($companyProfile['company_timezone'] ?? ''); ?>" placeholder="America/New_York" style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;">
                        </div>
                        
                        <div>
                            <label for="company_founded" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Year Founded</label>
                            <input type="number" id="company_founded" name="company_founded" value="<?php echo htmlspecialchars($companyProfile['company_founded'] ?? ''); ?>" placeholder="2020" min="1900" max="<?php echo date('Y'); ?>" style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;">
                        </div>
                        
                        <div>
                            <label for="company_size" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Company Size</label>
                            <input type="text" id="company_size" name="company_size" value="<?php echo htmlspecialchars($companyProfile['company_size'] ?? ''); ?>" placeholder="e.g., 1-10, 11-50, 51-200" style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;">
                        </div>
                        
                        <div>
                            <label for="social_linkedin" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">LinkedIn URL</label>
                            <input type="url" id="social_linkedin" name="social_linkedin" value="<?php echo htmlspecialchars($companyProfile['social_linkedin'] ?? ''); ?>" placeholder="https://linkedin.com/company/example" style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;">
                        </div>
                        
                        <div>
                            <label for="social_twitter" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Twitter URL</label>
                            <input type="url" id="social_twitter" name="social_twitter" value="<?php echo htmlspecialchars($companyProfile['social_twitter'] ?? ''); ?>" placeholder="https://twitter.com/example" style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;">
                        </div>
                        
                        <div>
                            <label for="social_facebook" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Facebook URL</label>
                            <input type="url" id="social_facebook" name="social_facebook" value="<?php echo htmlspecialchars($companyProfile['social_facebook'] ?? ''); ?>" placeholder="https://facebook.com/example" style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;">
                        </div>

                        <details style="margin-top: var(--spacing-lg); border: 1px solid var(--border-color); border-radius: 4px; padding: var(--spacing-md);">
                            <summary style="cursor: pointer; font-weight: 600; color: var(--midnight-black);">Ideal Customer Profile (ICP)</summary>
                            <p style="color: var(--charcoal-grey); font-size: 0.875rem; margin: var(--spacing-sm) 0;">Define your ideal customer for AI Coach segmentation and targeting advice.</p>
                            <div style="display: flex; flex-direction: column; gap: var(--spacing-md); margin-top: var(--spacing-md);">
                                <div>
                                    <label for="icp_job_titles" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Job Titles (comma-separated)</label>
                                    <input type="text" id="icp_job_titles" name="icp_job_titles" value="<?php echo htmlspecialchars($companyProfile['icp_job_titles'] ?? ''); ?>" placeholder="e.g., Marketing Manager, CEO, Sales Director" style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;">
                                </div>
                                <div>
                                    <label for="icp_industries" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Industries (comma-separated)</label>
                                    <input type="text" id="icp_industries" name="icp_industries" value="<?php echo htmlspecialchars($companyProfile['icp_industries'] ?? ''); ?>" placeholder="e.g., Technology, Healthcare, Finance" style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;">
                                </div>
                                <div>
                                    <label for="icp_pain_points" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Pain Points</label>
                                    <textarea id="icp_pain_points" name="icp_pain_points" rows="2" placeholder="What problems does your ideal customer have?" style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"><?php echo htmlspecialchars($companyProfile['icp_pain_points'] ?? ''); ?></textarea>
                                </div>
                                <div>
                                    <label for="icp_channels" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Where They Hang Out (channels)</label>
                                    <input type="text" id="icp_channels" name="icp_channels" value="<?php echo htmlspecialchars($companyProfile['icp_channels'] ?? ''); ?>" placeholder="e.g., LinkedIn, industry forums, conferences" style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;">
                                </div>
                            </div>
                        </details>

                        <details style="margin-top: var(--spacing-lg); border: 1px solid var(--border-color); border-radius: 4px; padding: var(--spacing-md);">
                            <summary style="cursor: pointer; font-weight: 600; color: var(--midnight-black);">Beginner Budget (Foundation Mode)</summary>
                            <p style="color: var(--charcoal-grey); font-size: 0.875rem; margin: var(--spacing-sm) 0;">Minimal budgeting for AI Coach Foundation Mode. Used for break-even and CAC-aware recommendations.</p>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: var(--spacing-md); margin-top: var(--spacing-md);">
                                <div>
                                    <label for="monthly_marketing_budget" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Monthly Marketing Budget</label>
                                    <input type="number" id="monthly_marketing_budget" name="monthly_marketing_budget" value="<?php echo htmlspecialchars($beginnerBudget['monthly_marketing_budget'] ?? '0'); ?>" min="0" step="0.01" placeholder="0" style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;">
                                </div>
                                <div>
                                    <label for="monthly_fixed_costs" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Monthly Fixed Costs</label>
                                    <input type="number" id="monthly_fixed_costs" name="monthly_fixed_costs" value="<?php echo htmlspecialchars(($beginnerBudget ?? [])['monthly_fixed_costs'] ?? '0'); ?>" min="0" step="0.01" placeholder="0" style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;">
                                </div>
                                <div>
                                    <label for="target_deal_value" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Target Deal Value (avg)</label>
                                    <input type="number" id="target_deal_value" name="target_deal_value" value="<?php echo htmlspecialchars($beginnerBudget['target_deal_value'] ?? '0'); ?>" min="0" step="0.01" placeholder="0" style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;">
                                </div>
                                <div>
                                    <label for="target_cac" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Target CAC (optional)</label>
                                    <input type="number" id="target_cac" name="target_cac" value="<?php echo htmlspecialchars(($beginnerBudget ?? [])['target_cac'] ?? ''); ?>" min="0" step="0.01" placeholder="Max cost per customer" style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;">
                                </div>
                            </div>
                            <input type="hidden" name="budget_currency_code" value="USD">
                        </details>
                        
                        <div style="display: flex; gap: var(--spacing-md); justify-content: flex-end; margin-top: var(--spacing-md);">
                            <button type="submit" style="background: var(--accent-blue); color: white; padding: var(--spacing-sm) var(--spacing-lg); border: none; border-radius: 4px; font-weight: 500; cursor: pointer;">
                                Save Company Profile
                            </button>
                        </div>
                    </form>
                </div>
            </div>
                
                <?php if (false): ?>
                <!-- Legacy products management moved to the Products settings tab. -->
                <!-- Right Column: Products Management -->
                <div>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--spacing-lg);">
                        <h3 style="color: var(--midnight-black); font-size: 18px; font-weight: 600; margin: 0;">Products & Services</h3>
                        <button type="button" onclick="showAddProductModal()" style="background: var(--accent-blue); color: white; padding: var(--spacing-sm) var(--spacing-md); border: none; border-radius: 4px; font-weight: 500; cursor: pointer;">
                            Add Product
                        </button>
                    </div>
                    
                    <div id="products-list" style="display: flex; flex-direction: column; gap: var(--spacing-md);">
                        <?php if (empty($allProducts)): ?>
                            <p style="color: var(--charcoal-grey); text-align: center; padding: var(--spacing-xl);">No products added yet. Click "Add Product" to get started.</p>
                        <?php else: ?>
                            <?php foreach ($allProducts as $product): ?>
                                <div style="border: 1px solid var(--border-color); border-radius: 4px; padding: var(--spacing-md); background: white;">
                                    <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: var(--spacing-sm);">
                                        <div>
                                            <h4 style="margin: 0; color: var(--midnight-black); font-weight: 600;"><?php echo htmlspecialchars($product['name']); ?></h4>
                                            <?php if (!empty($product['category'])): ?>
                                                <span style="color: var(--charcoal-grey); font-size: 12px;"><?php echo htmlspecialchars($product['category']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div style="display: flex; gap: var(--spacing-xs);">
                                            <button type="button" onclick="editProduct(<?php echo $product['id']; ?>)" style="background: var(--accent-blue); color: white; padding: 4px 8px; border: none; border-radius: 4px; font-size: 12px; cursor: pointer;">Edit</button>
                                            <button type="button" onclick="deleteProduct(<?php echo $product['id']; ?>)" style="background: #dc3545; color: white; padding: 4px 8px; border: none; border-radius: 4px; font-size: 12px; cursor: pointer;">Delete</button>
                                        </div>
                                    </div>
                                    <?php if (!empty($product['description'])): ?>
                                        <p style="color: var(--charcoal-grey); font-size: 14px; margin: var(--spacing-xs) 0;"><?php echo htmlspecialchars(substr($product['description'], 0, 150)); ?><?php echo strlen($product['description']) > 150 ? '...' : ''; ?></p>
                                    <?php endif; ?>
                                    <div style="display:flex;gap:0.75rem;flex-wrap:wrap;margin:0.35rem 0;">
                                        <span style="font-size:12px;color:var(--midnight-black);background:#f8fafc;border:1px solid var(--border-color);border-radius:999px;padding:4px 8px;">
                                            Unit price: <?php echo htmlspecialchars(number_format((float) ($product['unit_price'] ?? 0), 2)); ?>
                                        </span>
                                        <?php if (!empty($product['pricing_info'])): ?>
                                            <span style="font-size:12px;color:var(--charcoal-grey);background:#fff;border:1px solid var(--border-color);border-radius:999px;padding:4px 8px;">
                                                Pricing info set
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($product['product_image_url']) || !empty($product['product_demo_video_url'])): ?>
                                        <div style="display: flex; gap: var(--spacing-sm); align-items: center; margin-top: var(--spacing-xs);">
                                            <?php if (!empty($product['product_image_url'])): ?>
                                                <a href="<?php echo htmlspecialchars($product['product_image_url']); ?>" target="_blank" rel="noopener noreferrer" style="font-size: 12px; color: var(--accent-blue); text-decoration: none;">
                                                    View Product Image
                                                </a>
                                            <?php endif; ?>
                                            <?php if (!empty($product['product_demo_video_url'])): ?>
                                                <a href="<?php echo htmlspecialchars($product['product_demo_video_url']); ?>" target="_blank" rel="noopener noreferrer" style="font-size: 12px; color: var(--accent-blue); text-decoration: none;">
                                                    View Demo Video
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Product Modal -->
            <div id="productModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center; padding: 16px; box-sizing: border-box;">
                <div style="background: white; padding: var(--spacing-xl); border-radius: 8px; max-width: 600px; width: 90%; max-height: 90vh; overflow-y: auto;">
                    <h3 id="productModalTitle" style="margin-top: 0; color: var(--midnight-black); font-size: 20px; font-weight: 600; margin-bottom: var(--spacing-lg);">Add Product</h3>
                    <form id="productForm" method="POST" action="" style="display: flex; flex-direction: column; gap: var(--spacing-md);">
                        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                        <input type="hidden" name="tab" value="company">
                        <input type="hidden" name="product_request" value="1">
                        <input type="hidden" name="product_action" id="product_action" value="add">
                        <input type="hidden" name="product_id" id="product_id" value="">
                        <input type="hidden" name="expected_lock_version" id="product_expected_lock_version" value="">
                        
                        <div>
                            <label for="product_name" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Product Name *</label>
                            <input type="text" id="product_name" name="product_name" required style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;">
                        </div>
                        
                        <div>
                            <label for="product_category" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Category</label>
                            <input type="text" id="product_category" name="product_category" placeholder="e.g., Software, Service, Hardware" style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;">
                        </div>
                        
                        <div>
                            <label for="product_description" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Description</label>
                            <textarea id="product_description" name="product_description" rows="3" placeholder="Describe your product or service" style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"></textarea>
                        </div>
                        
                        <div>
                            <label for="product_features" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Features (one per line)</label>
                            <textarea id="product_features" name="product_features" rows="4" placeholder="Feature 1&#10;Feature 2&#10;Feature 3" style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"></textarea>
                        </div>
                        
                        <div>
                            <label for="product_benefits" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Benefits</label>
                            <textarea id="product_benefits" name="product_benefits" rows="2" placeholder="Key benefits of this product" style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"></textarea>
                        </div>
                        
                        <div>
                            <label for="product_target_audience" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Target Audience</label>
                            <textarea id="product_target_audience" name="product_target_audience" rows="2" placeholder="Who is this product for?" style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"></textarea>
                        </div>
                        
                        <div>
                            <label for="product_use_cases" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Use Cases</label>
                            <textarea id="product_use_cases" name="product_use_cases" rows="2" placeholder="Common use cases for this product" style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"></textarea>
                        </div>
                        
                        <div>
                            <label for="product_unit_price" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Unit Price</label>
                            <input type="number" id="product_unit_price" name="product_unit_price" min="0" step="0.01" placeholder="0.00" style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;">
                        </div>

                        <div>
                            <label for="product_pricing" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Pricing Information</label>
                            <textarea id="product_pricing" name="product_pricing" rows="5" placeholder="Explain the pricing model in plain language. Examples: 'USD 120 per seat per month', 'Starting at USD 2,500 per site', 'USD 300-450 per visit depending on scope', 'Base package USD 900 plus USD 75 per extra user'." style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;"></textarea>
                            <small style="display:block;margin-top:var(--spacing-xs);color:var(--charcoal-grey);">This text can now be used to infer a numeric unit price when the structured unit price is empty.</small>
                        </div>

                        <div>
                            <label for="product_image_url" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Product Image URL</label>
                            <input type="url" id="product_image_url" name="product_image_url" placeholder="https://example.com/product-image.jpg" style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;">
                        </div>

                        <div>
                            <label for="product_demo_video_url" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Product Demo Video URL</label>
                            <input type="url" id="product_demo_video_url" name="product_demo_video_url" placeholder="https://youtube.com/watch?v=..." style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;">
                        </div>
                        
                        <div>
                            <label for="product_display_order" style="display: block; margin-bottom: var(--spacing-xs); color: var(--midnight-black); font-weight: 500;">Display Order</label>
                            <input type="number" id="product_display_order" name="product_display_order" value="0" min="0" style="width: 100%; padding: var(--spacing-sm); border: 1px solid var(--border-color); border-radius: 4px;">
                        </div>
                        
                        <div style="display: flex; gap: var(--spacing-md); justify-content: flex-end; margin-top: var(--spacing-md);">
                            <button type="button" onclick="closeProductModal()" style="background: var(--charcoal-grey); color: white; padding: var(--spacing-sm) var(--spacing-lg); border: none; border-radius: 4px; font-weight: 500; cursor: pointer;">
                                Cancel
                            </button>
                            <button type="submit" style="background: var(--accent-blue); color: white; padding: var(--spacing-sm) var(--spacing-lg); border: none; border-radius: 4px; font-weight: 500; cursor: pointer;">
                                Save Product
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <script>
            let productData = <?php echo json_encode(array_map(function($p) {
                $features = json_decode($p['features'] ?? '[]', true);
                return [
                    'id' => $p['id'],
                    'name' => $p['name'],
                    'category' => $p['category'],
                    'description' => $p['description'],
                    'features' => is_array($features) ? implode("\n", $features) : '',
                    'benefits' => $p['benefits'],
                    'target_audience' => $p['target_audience'],
                    'use_cases' => $p['use_cases'],
                    'unit_price' => isset($p['unit_price']) ? (float) $p['unit_price'] : 0,
                    'pricing_info' => $p['pricing_info'],
                    'product_image_url' => $p['product_image_url'] ?? '',
                    'product_demo_video_url' => $p['product_demo_video_url'] ?? '',
                    'display_order' => $p['display_order']
                ];
            }, $allProducts)); ?>;

            function openProductModal() {
                const modal = document.getElementById('productModal');
                if (!modal) return;

                // Ensure fixed-position modal is attached to body so it stays viewport-centered.
                if (modal.parentElement !== document.body) {
                    document.body.appendChild(modal);
                }

                modal.style.display = 'flex';
                modal.scrollTop = 0;

                const panel = modal.firstElementChild;
                if (panel) panel.scrollTop = 0;

                document.body.style.overflow = 'hidden';
            }
            
            function showAddProductModal() {
                document.getElementById('productModalTitle').textContent = 'Add Product';
                document.getElementById('productForm').reset();
                document.getElementById('product_action').value = 'add';
                document.getElementById('product_id').value = '';
                document.getElementById('product_expected_lock_version').value = '';
                openProductModal();
            }
            
            function editProduct(productId) {
                const product = productData.find(p => p.id === productId);
                if (!product) return;
                
                document.getElementById('productModalTitle').textContent = 'Edit Product';
                document.getElementById('product_action').value = 'update';
                document.getElementById('product_id').value = product.id;
                document.getElementById('product_expected_lock_version').value = product.lock_version || 0;
                document.getElementById('product_name').value = product.name || '';
                document.getElementById('product_category').value = product.category || '';
                document.getElementById('product_description').value = product.description || '';
                document.getElementById('product_features').value = product.features || '';
                document.getElementById('product_benefits').value = product.benefits || '';
                document.getElementById('product_target_audience').value = product.target_audience || '';
                document.getElementById('product_use_cases').value = product.use_cases || '';
                document.getElementById('product_unit_price').value = product.unit_price || 0;
                document.getElementById('product_pricing').value = product.pricing_info || '';
                document.getElementById('product_image_url').value = product.product_image_url || '';
                document.getElementById('product_demo_video_url').value = product.product_demo_video_url || '';
                document.getElementById('product_display_order').value = product.display_order || 0;
                openProductModal();
            }
            
            function deleteProduct(productId) {
                if (!confirm('Are you sure you want to delete this product?')) return;
                
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '';
                
                const csrfToken = document.createElement('input');
                csrfToken.type = 'hidden';
                csrfToken.name = 'csrf_token';
                csrfToken.value = '<?php echo Security::getCsrfToken(); ?>';
                form.appendChild(csrfToken);
                
                const tab = document.createElement('input');
                tab.type = 'hidden';
                tab.name = 'tab';
                tab.value = 'company';
                form.appendChild(tab);

                const request = document.createElement('input');
                request.type = 'hidden';
                request.name = 'product_request';
                request.value = '1';
                form.appendChild(request);
                
                const action = document.createElement('input');
                action.type = 'hidden';
                action.name = 'product_action';
                action.value = 'delete';
                form.appendChild(action);
                
                const id = document.createElement('input');
                id.type = 'hidden';
                id.name = 'product_id';
                id.value = productId;
                form.appendChild(id);
                
                document.body.appendChild(form);
                form.submit();
            }
            
            function closeProductModal() {
                document.getElementById('productModal').style.display = 'none';
                document.body.style.overflow = '';
            }
            
            // Close modal on outside click
            document.getElementById('productModal')?.addEventListener('click', function(e) {
                if (e.target === this) {
                    closeProductModal();
                }
            });
            </script>
                <?php endif; ?>
            
        <?php elseif ($activeTab === 'products'): ?>
            <?php
            $productsModule = new Products();
            $allProducts = $productsModule->list();
            $productCount = count($allProducts);
            $productImageUploadEndpoint = function_exists('apiUrl') ? apiUrl('workspace/product_image_upload.php') : '../api/workspace/product_image_upload.php';
            $productSnippet = static function (?string $value, int $limit = 140): string {
                $text = trim((string) $value);
                if ($text === '') {
                    return '';
                }
                if (function_exists('mb_strlen') && function_exists('mb_substr')) {
                    return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit - 1) . '...' : $text;
                }
                return strlen($text) > $limit ? substr($text, 0, $limit - 1) . '...' : $text;
            };
            ?>
            <div class="settings-products-ui" data-settings-products-ui data-product-image-upload-endpoint="<?php echo htmlspecialchars($productImageUploadEndpoint); ?>" data-csrf-token="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
                <div class="settings-products-header">
                    <div>
                        <h3>Products & Services</h3>
                        <p>Manage the products and services your business sells. These offers can be recommended and added to customer quotes and invoices.</p>
                    </div>
                    <div class="settings-products-count" aria-label="<?php echo (int) $productCount; ?> saved products or services">
                        <strong><?php echo (int) $productCount; ?></strong>
                        <span>saved offers</span>
                    </div>
                </div>

                <form method="POST" action="" class="content-card settings-product-form settings-product-form--add" data-product-form>
                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                    <input type="hidden" name="tab" value="products">
                    <input type="hidden" name="product_request" value="1">
                    <input type="hidden" name="product_action" value="add">
                    <input type="hidden" name="product_image_url" value="" data-product-image-url-field>
                    <div class="settings-product-form-head">
                        <div>
                            <h4>Add product or service</h4>
                            <p>Capture the offer, pricing context, and a visual that can travel into quotes and drafts.</p>
                        </div>
                    </div>
                    <div class="settings-product-form-grid">
                        <label class="settings-form-field">
                            <span>Name *</span>
                            <input class="settings-input" type="text" id="product_name_add" name="product_name" required>
                        </label>
                        <label class="settings-form-field">
                            <span>Category</span>
                            <input class="settings-input" type="text" id="product_category_add" name="product_category" placeholder="Product or service">
                        </label>
                        <label class="settings-form-field settings-product-span-all">
                            <span>Description</span>
                            <textarea class="settings-input" id="product_description_add" name="product_description" rows="3"></textarea>
                        </label>
                        <label class="settings-form-field">
                            <span>Target audience</span>
                            <textarea class="settings-input" id="product_target_add" name="product_target_audience" rows="2"></textarea>
                        </label>
                        <label class="settings-form-field">
                            <span>Benefits</span>
                            <textarea class="settings-input" id="product_benefits_add" name="product_benefits" rows="2"></textarea>
                        </label>
                        <label class="settings-form-field">
                            <span>Unit price</span>
                            <input class="settings-input" type="number" id="product_price_add" name="product_unit_price" min="0" step="0.01">
                        </label>
                        <label class="settings-form-field">
                            <span>Display order</span>
                            <input class="settings-input" type="number" id="product_order_add" name="product_display_order" min="0" value="0">
                        </label>
                        <label class="settings-form-field settings-product-span-all">
                            <span>Pricing information</span>
                            <textarea class="settings-input" id="product_pricing_add" name="product_pricing" rows="3" placeholder="Plain-language pricing notes, ranges, or conditions."></textarea>
                        </label>
                        <label class="settings-form-field settings-product-span-all">
                            <span>Demo video URL</span>
                            <input class="settings-input" type="url" id="product_demo_video_add" name="product_demo_video_url" placeholder="https://youtube.com/watch?v=...">
                        </label>
                    </div>
                    <div class="settings-product-media-panel" data-product-image-uploader>
                        <div class="settings-product-media-preview" data-product-image-preview>
                            <span>No image yet</span>
                        </div>
                        <div class="settings-product-media-controls">
                            <label class="settings-product-dropzone" data-product-image-dropzone>
                                <input type="file" accept="image/jpeg,image/png,image/webp,image/gif" data-product-image-file>
                                <span class="settings-product-dropzone__title">Upload product image</span>
                                <span class="settings-product-dropzone__copy">JPG, PNG, WebP, or GIF up to 4 MB</span>
                            </label>
                            <div class="settings-action-row">
                                <button type="button" class="btn-premium-secondary" data-product-image-upload disabled>Upload Image</button>
                                <button type="button" class="btn-premium-secondary" data-product-image-remove>Remove Image</button>
                            </div>
                            <p class="settings-product-upload-status" data-product-image-status role="status" aria-live="polite"></p>
                            <details class="settings-product-url-fallback">
                                <summary>Use image URL instead</summary>
                                <label class="settings-form-field">
                                    <span>Image URL or uploaded path</span>
                                    <input class="settings-input" type="text" data-product-image-url-input placeholder="https://example.com/product.jpg">
                                </label>
                            </details>
                        </div>
                    </div>
                    <div class="settings-product-actions">
                        <button type="submit" class="btn-premium-primary">Add Product</button>
                    </div>
                </form>

                <div class="settings-product-list">
                    <?php if (empty($allProducts)): ?>
                        <div class="content-card settings-product-empty">
                            <strong>No products or services yet.</strong>
                            <span>Add your first offer above when you are ready.</span>
                        </div>
                    <?php else: ?>
                        <?php foreach ($allProducts as $product): ?>
                            <?php
                            $features = json_decode((string) ($product['features'] ?? '[]'), true);
                            $features = is_array($features) ? $features : [];
                            $productImagePath = trim((string) ($product['product_image_url'] ?? ''));
                            $productImageSrc = $productImagePath !== '' ? settingsProductMediaUrl($productImagePath) : '';
                            $productDescription = $productSnippet($product['description'] ?? '');
                            $productBenefits = $productSnippet($product['benefits'] ?? '', 120);
                            $productTarget = $productSnippet($product['target_audience'] ?? '', 120);
                            ?>
                            <section class="content-card settings-product-card">
                                <div class="settings-product-card__media">
                                    <?php if ($productImageSrc !== ''): ?>
                                        <img src="<?php echo htmlspecialchars($productImageSrc); ?>" alt="<?php echo htmlspecialchars((string) ($product['name'] ?? 'Product')); ?>">
                                    <?php else: ?>
                                        <span><?php echo htmlspecialchars(strtoupper(substr(trim((string) ($product['name'] ?? 'P')) ?: 'P', 0, 1))); ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="settings-product-card__body">
                                    <div class="settings-product-card__topline">
                                        <div class="settings-product-title-block">
                                            <h4><?php echo htmlspecialchars((string) ($product['name'] ?? 'Product')); ?></h4>
                                            <p><?php echo htmlspecialchars((string) ($product['category'] ?? 'Uncategorized')); ?></p>
                                        </div>
                                        <form method="POST" action="">
                                            <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                            <input type="hidden" name="tab" value="products">
                                            <input type="hidden" name="product_request" value="1">
                                            <input type="hidden" name="product_action" value="delete">
                                            <input type="hidden" name="product_id" value="<?php echo (int) ($product['id'] ?? 0); ?>">
                                            <button type="submit" class="btn-premium-secondary settings-product-delete" onclick="return confirm('Delete this product?');">Delete</button>
                                        </form>
                                    </div>
                                    <div class="settings-product-meta">
                                        <?php if (trim((string) ($product['unit_price'] ?? '')) !== ''): ?>
                                            <span>Unit price: <?php echo htmlspecialchars(number_format((float) ($product['unit_price'] ?? 0), 2)); ?></span>
                                        <?php endif; ?>
                                        <span>Order: <?php echo (int) ($product['display_order'] ?? 0); ?></span>
                                        <?php if ($features !== []): ?>
                                            <span><?php echo count($features); ?> <?php echo count($features) === 1 ? 'feature' : 'features'; ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if ($productDescription !== ''): ?>
                                        <p class="settings-product-description"><?php echo htmlspecialchars($productDescription); ?></p>
                                    <?php endif; ?>
                                    <div class="settings-product-context-grid">
                                        <?php if ($productTarget !== ''): ?>
                                            <div>
                                                <strong>Audience</strong>
                                                <span><?php echo htmlspecialchars($productTarget); ?></span>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($productBenefits !== ''): ?>
                                            <div>
                                                <strong>Benefits</strong>
                                                <span><?php echo htmlspecialchars($productBenefits); ?></span>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($product['product_demo_video_url'])): ?>
                                        <a class="settings-product-media-link" href="<?php echo htmlspecialchars((string) $product['product_demo_video_url']); ?>" target="_blank" rel="noopener noreferrer">Open demo video</a>
                                    <?php endif; ?>
                                    <details class="settings-product-edit">
                                        <summary>Edit product</summary>
                                        <form method="POST" action="" class="settings-product-form settings-product-form--edit" data-product-form>
                                            <?php $editProductImageSrc = $productImagePath !== '' ? settingsProductMediaUrl($productImagePath) : ''; ?>
                                            <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                            <input type="hidden" name="tab" value="products">
                                            <input type="hidden" name="product_request" value="1">
                                            <input type="hidden" name="product_action" value="update">
                                            <input type="hidden" name="product_id" value="<?php echo (int) ($product['id'] ?? 0); ?>">
                                            <input type="hidden" name="expected_lock_version" value="<?php echo (int) ($product['lock_version'] ?? 0); ?>">
                                            <input type="hidden" name="product_image_url" value="<?php echo htmlspecialchars($productImagePath); ?>" data-product-image-url-field>
                                            <div class="settings-product-form-grid">
                                                <label class="settings-form-field">
                                                    <span>Name *</span>
                                                    <input class="settings-input" type="text" name="product_name" required value="<?php echo htmlspecialchars((string) ($product['name'] ?? '')); ?>">
                                                </label>
                                                <label class="settings-form-field">
                                                    <span>Category</span>
                                                    <input class="settings-input" type="text" name="product_category" value="<?php echo htmlspecialchars((string) ($product['category'] ?? '')); ?>">
                                                </label>
                                                <label class="settings-form-field settings-product-span-all">
                                                    <span>Description</span>
                                                    <textarea class="settings-input" name="product_description" rows="3"><?php echo htmlspecialchars((string) ($product['description'] ?? '')); ?></textarea>
                                                </label>
                                                <label class="settings-form-field">
                                                    <span>Target audience</span>
                                                    <textarea class="settings-input" name="product_target_audience" rows="2"><?php echo htmlspecialchars((string) ($product['target_audience'] ?? '')); ?></textarea>
                                                </label>
                                                <label class="settings-form-field">
                                                    <span>Benefits</span>
                                                    <textarea class="settings-input" name="product_benefits" rows="2"><?php echo htmlspecialchars((string) ($product['benefits'] ?? '')); ?></textarea>
                                                </label>
                                                <label class="settings-form-field">
                                                    <span>Use cases</span>
                                                    <textarea class="settings-input" name="product_use_cases" rows="2"><?php echo htmlspecialchars((string) ($product['use_cases'] ?? '')); ?></textarea>
                                                </label>
                                                <label class="settings-form-field">
                                                    <span>Features, one per line</span>
                                                    <textarea class="settings-input" name="product_features" rows="2"><?php echo htmlspecialchars(implode("\n", array_map('strval', $features))); ?></textarea>
                                                </label>
                                                <label class="settings-form-field">
                                                    <span>Unit price</span>
                                                    <input class="settings-input" type="number" name="product_unit_price" min="0" step="0.01" value="<?php echo htmlspecialchars((string) ($product['unit_price'] ?? '')); ?>">
                                                </label>
                                                <label class="settings-form-field">
                                                    <span>Display order</span>
                                                    <input class="settings-input" type="number" name="product_display_order" min="0" value="<?php echo (int) ($product['display_order'] ?? 0); ?>">
                                                </label>
                                                <label class="settings-form-field settings-product-span-all">
                                                    <span>Pricing information</span>
                                                    <textarea class="settings-input" name="product_pricing" rows="3"><?php echo htmlspecialchars((string) ($product['pricing_info'] ?? '')); ?></textarea>
                                                </label>
                                                <label class="settings-form-field settings-product-span-all">
                                                    <span>Demo video URL</span>
                                                    <input class="settings-input" type="url" name="product_demo_video_url" value="<?php echo htmlspecialchars((string) ($product['product_demo_video_url'] ?? '')); ?>">
                                                </label>
                                            </div>
                                            <div class="settings-product-media-panel" data-product-image-uploader>
                                                <div class="settings-product-media-preview" data-product-image-preview>
                                                    <?php if ($editProductImageSrc !== ''): ?>
                                                        <img src="<?php echo htmlspecialchars($editProductImageSrc); ?>" alt="<?php echo htmlspecialchars((string) ($product['name'] ?? 'Product')); ?>">
                                                    <?php else: ?>
                                                        <span>No image yet</span>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="settings-product-media-controls">
                                                    <label class="settings-product-dropzone" data-product-image-dropzone>
                                                        <input type="file" accept="image/jpeg,image/png,image/webp,image/gif" data-product-image-file>
                                                        <span class="settings-product-dropzone__title">Upload replacement image</span>
                                                        <span class="settings-product-dropzone__copy">JPG, PNG, WebP, or GIF up to 4 MB</span>
                                                    </label>
                                                    <div class="settings-action-row">
                                                        <button type="button" class="btn-premium-secondary" data-product-image-upload disabled>Upload Image</button>
                                                        <button type="button" class="btn-premium-secondary" data-product-image-remove>Remove Image</button>
                                                    </div>
                                                    <p class="settings-product-upload-status" data-product-image-status role="status" aria-live="polite"></p>
                                                    <details class="settings-product-url-fallback">
                                                        <summary>Use image URL instead</summary>
                                                        <label class="settings-form-field">
                                                            <span>Image URL or uploaded path</span>
                                                            <input class="settings-input" type="text" data-product-image-url-input value="<?php echo htmlspecialchars($productImagePath); ?>">
                                                        </label>
                                                    </details>
                                                </div>
                                            </div>
                                            <div class="settings-product-actions">
                                                <button type="submit" class="btn-premium-primary">Save Product</button>
                                            </div>
                                        </form>
                                    </details>
                                </div>
                            </section>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <script>
            (function () {
                var root = document.querySelector('[data-settings-products-ui]');
                if (!root) return;
                var endpoint = root.getAttribute('data-product-image-upload-endpoint') || '';
                var csrfToken = root.getAttribute('data-csrf-token') || '';
                var maxBytes = 4 * 1024 * 1024;
                var allowedTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

                function mediaSrc(path) {
                    path = (path || '').trim();
                    if (!path) return '';
                    if (/^https?:\/\//i.test(path) || path.charAt(0) === '/') return path;
                    if (path.indexOf('uploads/') === 0) return '../' + path;
                    return path;
                }

                function setStatus(uploader, message, state) {
                    var status = uploader.querySelector('[data-product-image-status]');
                    if (!status) return;
                    status.textContent = message || '';
                    status.dataset.state = state || '';
                }

                function setPreview(uploader, src) {
                    var preview = uploader.querySelector('[data-product-image-preview]');
                    if (!preview) return;
                    preview.innerHTML = '';
                    if (src) {
                        var img = document.createElement('img');
                        img.src = src;
                        img.alt = '';
                        preview.appendChild(img);
                    } else {
                        var empty = document.createElement('span');
                        empty.textContent = 'No image yet';
                        preview.appendChild(empty);
                    }
                }

                function setStoredPath(uploader, storedPath, previewSrc) {
                    var form = uploader.closest('[data-product-form]');
                    var hidden = form ? form.querySelector('[data-product-image-url-field]') : null;
                    var urlInput = uploader.querySelector('[data-product-image-url-input]');
                    if (hidden) hidden.value = storedPath || '';
                    if (urlInput) urlInput.value = storedPath || '';
                    setPreview(uploader, previewSrc || mediaSrc(storedPath));
                }

                function validateFile(file) {
                    if (!file) return 'Choose an image before uploading.';
                    if (allowedTypes.indexOf(file.type || '') === -1) return 'Choose a JPG, PNG, WebP, or GIF image.';
                    if (file.size > maxBytes) return 'Product images must be 4 MB or smaller.';
                    return '';
                }

                function prepareFile(uploader, file) {
                    var error = validateFile(file);
                    var uploadButton = uploader.querySelector('[data-product-image-upload]');
                    if (error) {
                        uploader.__productImageFile = null;
                        if (uploadButton) uploadButton.disabled = true;
                        setStatus(uploader, error, 'error');
                        return;
                    }
                    uploader.__productImageFile = file;
                    if (uploadButton) uploadButton.disabled = false;
                    setPreview(uploader, URL.createObjectURL(file));
                    setStatus(uploader, 'Ready to upload.', '');
                }

                root.querySelectorAll('[data-product-image-uploader]').forEach(function (uploader) {
                    var fileInput = uploader.querySelector('[data-product-image-file]');
                    var uploadButton = uploader.querySelector('[data-product-image-upload]');
                    var removeButton = uploader.querySelector('[data-product-image-remove]');
                    var urlInput = uploader.querySelector('[data-product-image-url-input]');
                    var dropzone = uploader.querySelector('[data-product-image-dropzone]');

                    if (fileInput) {
                        fileInput.addEventListener('change', function () {
                            prepareFile(uploader, fileInput.files && fileInput.files[0] ? fileInput.files[0] : null);
                        });
                    }

                    if (dropzone) {
                        ['dragenter', 'dragover'].forEach(function (eventName) {
                            dropzone.addEventListener(eventName, function (event) {
                                event.preventDefault();
                                dropzone.classList.add('is-dragging');
                            });
                        });
                        ['dragleave', 'drop'].forEach(function (eventName) {
                            dropzone.addEventListener(eventName, function () {
                                dropzone.classList.remove('is-dragging');
                            });
                        });
                        dropzone.addEventListener('drop', function (event) {
                            event.preventDefault();
                            var file = event.dataTransfer && event.dataTransfer.files && event.dataTransfer.files[0]
                                ? event.dataTransfer.files[0]
                                : null;
                            prepareFile(uploader, file);
                        });
                    }

                    if (urlInput) {
                        urlInput.addEventListener('input', function () {
                            setStoredPath(uploader, urlInput.value.trim(), mediaSrc(urlInput.value.trim()));
                            setStatus(uploader, urlInput.value.trim() ? 'Image URL ready to save.' : '', '');
                        });
                    }

                    if (removeButton) {
                        removeButton.addEventListener('click', function () {
                            uploader.__productImageFile = null;
                            if (fileInput) fileInput.value = '';
                            if (uploadButton) uploadButton.disabled = true;
                            setStoredPath(uploader, '', '');
                            setStatus(uploader, 'Image removed. Save the product to keep this change.', '');
                        });
                    }

                    if (uploadButton) {
                        uploadButton.addEventListener('click', function () {
                            var file = uploader.__productImageFile || (fileInput && fileInput.files && fileInput.files[0] ? fileInput.files[0] : null);
                            var error = validateFile(file);
                            if (error) {
                                setStatus(uploader, error, 'error');
                                return;
                            }
                            if (!endpoint) {
                                setStatus(uploader, 'The upload endpoint is unavailable.', 'error');
                                return;
                            }
                            var payload = new FormData();
                            payload.append('csrf_token', csrfToken);
                            payload.append('image_file', file);
                            uploadButton.disabled = true;
                            setStatus(uploader, 'Uploading image...', 'working');
                            fetch(endpoint, {
                                method: 'POST',
                                body: payload,
                                credentials: 'same-origin',
                                headers: { 'Accept': 'application/json' }
                            })
                                .then(function (response) {
                                    return response.json().catch(function () {
                                        return { success: false, error: 'The upload response was not valid JSON.' };
                                    }).then(function (data) {
                                        if (!response.ok || !data.success) {
                                            throw new Error(data.error || 'The image could not be uploaded.');
                                        }
                                        return data;
                                    });
                                })
                                .then(function (data) {
                                    uploader.__productImageFile = null;
                                    if (fileInput) fileInput.value = '';
                                    setStoredPath(uploader, data.stored_path || '', data.html_src || '');
                                    setStatus(uploader, 'Image uploaded. Save the product to keep it attached.', 'success');
                                })
                                .catch(function (error) {
                                    uploadButton.disabled = false;
                                    setStatus(uploader, error.message || 'The image could not be uploaded.', 'error');
                                });
                        });
                    }
                });
            })();
            </script>

        <?php elseif ($activeTab === 'voice'): ?>
            <?php
            $strategy = (new UserStrategyProfile())->get($userId) ?: [];
            $voiceState = (new WorkspaceOnboardingService())->getState($activeWorkspaceId, $userId);
            $voiceRow = (array) ($voiceState['row'] ?? []);
            $toneContext = [];
            if (!empty($voiceRow['tone_json'])) {
                $decodedTone = json_decode((string) $voiceRow['tone_json'], true);
                $toneContext = is_array($decodedTone) ? $decodedTone : [];
            }
            $voiceValue = static function (array $primary, array $secondary, string $key, string $default = ''): string {
                return (string) ($primary[$key] ?? ($secondary[$key] ?? $default));
            };
            $voiceSelect = static function (string $actual, string $expected): string {
                return $actual === $expected ? 'selected' : '';
            };
            $tonePreset = $voiceValue($strategy, $toneContext, 'draft_tone_preset', 'consultative');
            $relationshipStyle = (string) ($voiceRow['relationship_style'] ?? $voiceValue($strategy, $toneContext, 'relationship_style', 'trusted_advisor'));
            $languageLevels = new WorkspaceLanguageLevelService();
            $languageLevel = $languageLevels->context($voiceValue($strategy, $toneContext, 'draft_reading_level', WorkspaceLanguageLevelService::DEFAULT_LEVEL));
            ?>
            <form method="POST" action="" style="display:flex;flex-direction:column;gap:var(--spacing-lg);max-width:860px;">
                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                <input type="hidden" name="tab" value="voice">
                <div>
                    <h3 style="color:var(--midnight-black);font-size:18px;font-weight:600;margin:0 0 var(--spacing-xs);">Voice</h3>
                    <p style="color:var(--charcoal-grey);font-size:0.875rem;margin:0;">Set how AI drafts, summaries, and auto-responder suggestions should sound. Owners can refine this any time.</p>
                </div>
                <div class="content-card" style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:var(--spacing-md);background:#f8fafc;">
                    <div>
                        <label style="display:block;margin-bottom:var(--spacing-xs);font-weight:500;">Tone preset</label>
                        <select name="draft_tone_preset" style="width:100%;padding:var(--spacing-sm);border:1px solid var(--border-color);border-radius:4px;">
                            <?php foreach (['consultative' => 'Consultative', 'professional' => 'Professional', 'warm' => 'Warm', 'direct' => 'Direct', 'friendly' => 'Friendly'] as $value => $label): ?>
                                <option value="<?php echo htmlspecialchars($value); ?>" <?php echo $voiceSelect($tonePreset, $value); ?>><?php echo htmlspecialchars($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label style="display:block;margin-bottom:var(--spacing-xs);font-weight:500;">Relationship style</label>
                        <select name="relationship_style" style="width:100%;padding:var(--spacing-sm);border:1px solid var(--border-color);border-radius:4px;">
                            <?php foreach (['trusted_advisor' => 'Trusted advisor', 'friendly_operator' => 'Friendly operator', 'direct_expert' => 'Direct expert', 'premium_concierge' => 'Premium concierge'] as $value => $label): ?>
                                <option value="<?php echo htmlspecialchars($value); ?>" <?php echo $voiceSelect($relationshipStyle, $value); ?>><?php echo htmlspecialchars($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label style="display:block;margin-bottom:var(--spacing-xs);font-weight:500;">Call-to-action style</label>
                        <select name="draft_cta_style" style="width:100%;padding:var(--spacing-sm);border:1px solid var(--border-color);border-radius:4px;">
                            <?php $ctaStyle = $voiceValue($strategy, $toneContext, 'draft_cta_style', 'clear'); ?>
                            <?php foreach (['clear' => 'Clear', 'soft' => 'Soft', 'direct' => 'Direct'] as $value => $label): ?>
                                <option value="<?php echo htmlspecialchars($value); ?>" <?php echo $voiceSelect($ctaStyle, $value); ?>><?php echo htmlspecialchars($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label style="display:block;margin-bottom:var(--spacing-xs);font-weight:500;">Formality</label>
                        <select name="draft_formality_level" style="width:100%;padding:var(--spacing-sm);border:1px solid var(--border-color);border-radius:4px;">
                            <?php $formality = $voiceValue($strategy, $toneContext, 'draft_formality_level', 'balanced'); ?>
                            <?php foreach (['balanced' => 'Balanced', 'formal' => 'Formal', 'casual' => 'Casual'] as $value => $label): ?>
                                <option value="<?php echo htmlspecialchars($value); ?>" <?php echo $voiceSelect($formality, $value); ?>><?php echo htmlspecialchars($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label style="display:block;margin-bottom:var(--spacing-xs);font-weight:500;">Language level</label>
                        <select name="draft_reading_level" style="width:100%;padding:var(--spacing-sm);border:1px solid var(--border-color);border-radius:4px;">
                            <?php foreach ($languageLevels->options() as $value => $option): ?>
                                <option value="<?php echo htmlspecialchars($value); ?>" <?php echo $voiceSelect((string) $languageLevel['level'], $value); ?>><?php echo htmlspecialchars($option['label']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p style="color:var(--charcoal-grey);font-size:0.78rem;line-height:1.45;margin:var(--spacing-xs) 0 0;">Controls jargon density and explanation depth across Clarity and plugin AI outputs. Current mode: <?php echo htmlspecialchars($languageLevel['description']); ?>.</p>
                    </div>
                    <div>
                        <label style="display:block;margin-bottom:var(--spacing-xs);font-weight:500;">Human review moments</label>
                        <input name="escalation_preference" value="<?php echo htmlspecialchars($voiceValue($toneContext, $strategy, 'escalation_preference')); ?>" placeholder="Complaints, refunds, urgent issues" style="width:100%;padding:var(--spacing-sm);border:1px solid var(--border-color);border-radius:4px;">
                    </div>
                    <div style="grid-column:1 / -1;">
                        <label style="display:block;margin-bottom:var(--spacing-xs);font-weight:500;">Voice notes</label>
                        <textarea name="draft_voice_notes" rows="4" placeholder="House style, phrases, promises, tone preferences, examples, or customer-facing nuance." style="width:100%;padding:var(--spacing-sm);border:1px solid var(--border-color);border-radius:4px;"><?php echo htmlspecialchars($voiceValue($strategy, $toneContext, 'draft_voice_notes')); ?></textarea>
                    </div>
                    <div style="grid-column:1 / -1;">
                        <label style="display:block;margin-bottom:var(--spacing-xs);font-weight:500;">Words or promises to avoid</label>
                        <textarea name="words_to_avoid" rows="3" placeholder="Claims, phrases, guarantees, or tones AI should avoid." style="width:100%;padding:var(--spacing-sm);border:1px solid var(--border-color);border-radius:4px;"><?php echo htmlspecialchars($voiceValue($toneContext, $strategy, 'words_to_avoid')); ?></textarea>
                    </div>
                </div>
                <div style="display:flex;justify-content:flex-end;">
                    <button type="submit" class="btn-premium-primary">Save Voice</button>
                </div>
            </form>

        <?php elseif ($activeTab === 'invoicing'): ?>
            <?php
            $invoiceSettings = $invoiceSettings ?? (new InvoiceSettings())->get();
            $invoiceTemplateOptions = (new InvoiceTemplateService())->getAvailableTemplates();
            $invoiceCurrencies = [];
            try {
                $invoiceCurrencies = Database::query("SELECT code, symbol FROM currencies WHERE is_active = 1 ORDER BY code ASC");
            } catch (\Throwable $e) {
                $invoiceCurrencies = [['code' => 'USD', 'symbol' => '$']];
            }
            $allDocTypes = ['quote', 'proforma', 'invoice'];
            $invoiceLogoPath = trim((string) ($invoiceSettings['logo_asset_path'] ?? ''));
            $invoiceLogoPreview = '';
            if ($invoiceLogoPath !== '') {
                $invoiceLogoPreview = preg_match('#^(https?:)?//#i', $invoiceLogoPath) || str_starts_with($invoiceLogoPath, 'data:image/')
                    ? $invoiceLogoPath
                    : getBasePath() . '/../' . ltrim($invoiceLogoPath, '/');
            }
            ?>
            <form method="POST" action="" style="display:flex;flex-direction:column;gap:var(--spacing-lg);max-width:900px;">
                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                <input type="hidden" name="tab" value="invoicing">

                <div class="content-card" style="background:#f8fafc;">
                    <h3 style="margin-top:0;color:#0f172a;">Invoicing</h3>
                    <p style="color:#64748b;font-size:0.875rem;">Configure quotes, proformas, invoices, numbering, branding, and AI permissions.</p>
                    <label style="display:flex;align-items:center;gap:0.5rem;cursor:pointer;margin-top:1rem;">
                        <input type="checkbox" name="invoice_enabled" value="1" <?php echo !empty($invoiceSettings['enabled']) ? 'checked' : ''; ?> style="width:1.125rem;height:1.125rem;">
                        <span style="font-weight:600;">Enable invoicing module</span>
                    </label>
                </div>

                <div class="content-card" style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:1rem;">
                    <div>
                        <label style="display:block;margin-bottom:0.5rem;font-weight:600;">Invoice prefix</label>
                        <input type="text" name="invoice_prefix" value="<?php echo htmlspecialchars($invoiceSettings['invoice_prefix'] ?? 'INV-'); ?>" style="width:100%;padding:0.6rem;border:1px solid rgba(0,0,0,0.1);border-radius:6px;">
                    </div>
                    <div>
                        <label style="display:block;margin-bottom:0.5rem;font-weight:600;">Invoice next #</label>
                        <input type="number" name="invoice_next_number" min="1" value="<?php echo (int) ($invoiceSettings['invoice_next_number'] ?? 1); ?>" style="width:100%;padding:0.6rem;border:1px solid rgba(0,0,0,0.1);border-radius:6px;">
                    </div>
                    <div>
                        <label style="display:block;margin-bottom:0.5rem;font-weight:600;">Default currency</label>
                        <select id="invoice_default_currency" name="invoice_default_currency" style="width:100%;padding:0.6rem;border:1px solid rgba(0,0,0,0.1);border-radius:6px;">
                            <?php foreach ($invoiceCurrencies as $currency): ?>
                                <option value="<?php echo htmlspecialchars($currency['code']); ?>" <?php echo ($invoiceSettings['default_currency'] ?? 'USD') === $currency['code'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($currency['code'] . ' (' . ($currency['symbol'] ?? '$') . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label style="display:block;margin-bottom:0.5rem;font-weight:600;">Proforma prefix</label>
                        <input type="text" name="proforma_prefix" value="<?php echo htmlspecialchars($invoiceSettings['proforma_prefix'] ?? 'PF-'); ?>" style="width:100%;padding:0.6rem;border:1px solid rgba(0,0,0,0.1);border-radius:6px;">
                    </div>
                    <div>
                        <label style="display:block;margin-bottom:0.5rem;font-weight:600;">Proforma next #</label>
                        <input type="number" name="proforma_next_number" min="1" value="<?php echo (int) ($invoiceSettings['proforma_next_number'] ?? 1); ?>" style="width:100%;padding:0.6rem;border:1px solid rgba(0,0,0,0.1);border-radius:6px;">
                    </div>
                    <div>
                        <label style="display:block;margin-bottom:0.5rem;font-weight:600;">Default tax mode</label>
                        <select name="invoice_default_tax_mode" style="width:100%;padding:0.6rem;border:1px solid rgba(0,0,0,0.1);border-radius:6px;">
                            <?php foreach (['exclusive' => 'Exclusive', 'inclusive' => 'Inclusive', 'none' => 'None'] as $modeValue => $modeLabel): ?>
                                <option value="<?php echo $modeValue; ?>" <?php echo ($invoiceSettings['default_tax_mode'] ?? 'exclusive') === $modeValue ? 'selected' : ''; ?>><?php echo $modeLabel; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label style="display:block;margin-bottom:0.5rem;font-weight:600;">Quote prefix</label>
                        <input type="text" name="quote_prefix" value="<?php echo htmlspecialchars($invoiceSettings['quote_prefix'] ?? 'QT-'); ?>" style="width:100%;padding:0.6rem;border:1px solid rgba(0,0,0,0.1);border-radius:6px;">
                    </div>
                    <div>
                        <label style="display:block;margin-bottom:0.5rem;font-weight:600;">Quote next #</label>
                        <input type="number" name="quote_next_number" min="1" value="<?php echo (int) ($invoiceSettings['quote_next_number'] ?? 1); ?>" style="width:100%;padding:0.6rem;border:1px solid rgba(0,0,0,0.1);border-radius:6px;">
                    </div>
                    <div>
                        <label style="display:block;margin-bottom:0.5rem;font-weight:600;">Default tax rate %</label>
                        <input type="number" name="invoice_default_tax_rate" min="0" step="0.01" value="<?php echo htmlspecialchars((string) ($invoiceSettings['default_tax_rate'] ?? 0)); ?>" style="width:100%;padding:0.6rem;border:1px solid rgba(0,0,0,0.1);border-radius:6px;">
                    </div>
                    <div>
                        <label style="display:block;margin-bottom:0.5rem;font-weight:600;">Payment terms days</label>
                        <input type="number" name="invoice_default_payment_terms_days" min="1" value="<?php echo (int) ($invoiceSettings['default_payment_terms_days'] ?? 14); ?>" style="width:100%;padding:0.6rem;border:1px solid rgba(0,0,0,0.1);border-radius:6px;">
                    </div>
                    <div>
                        <label style="display:block;margin-bottom:0.5rem;font-weight:600;">Validity days</label>
                        <input type="number" name="invoice_default_validity_days" min="1" value="<?php echo (int) ($invoiceSettings['default_validity_days'] ?? 14); ?>" style="width:100%;padding:0.6rem;border:1px solid rgba(0,0,0,0.1);border-radius:6px;">
                    </div>
                    <div>
                        <label style="display:block;margin-bottom:0.5rem;font-weight:600;">Default document template</label>
                        <select id="invoice_default_template_key" name="invoice_default_template_key" style="width:100%;padding:0.6rem;border:1px solid rgba(0,0,0,0.1);border-radius:6px;">
                            <?php foreach ($invoiceTemplateOptions as $templateOption): ?>
                                <option value="<?php echo htmlspecialchars((string) ($templateOption['key'] ?? 'classic')); ?>" <?php echo (($invoiceSettings['default_template_key'] ?? $invoiceSettings['visual_theme'] ?? 'classic') === ($templateOption['key'] ?? 'classic')) ? 'selected' : ''; ?>><?php echo htmlspecialchars((string) ($templateOption['label'] ?? 'Classic')); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div style="margin-top:0.45rem;color:#64748b;font-size:0.82rem;">
                            <?php
                            $activeInvoiceTemplate = (string) ($invoiceSettings['default_template_key'] ?? $invoiceSettings['visual_theme'] ?? 'classic');
                            foreach ($invoiceTemplateOptions as $templateOption) {
                                if (($templateOption['key'] ?? '') === $activeInvoiceTemplate) {
                                    echo htmlspecialchars((string) ($templateOption['description'] ?? ''));
                                    break;
                                }
                            }
                            ?>
                        </div>
                    </div>
                    <div>
                        <label style="display:block;margin-bottom:0.5rem;font-weight:600;">Preview document type</label>
                        <select id="invoice_preview_document_type" name="invoice_preview_document_type" style="width:100%;padding:0.6rem;border:1px solid rgba(0,0,0,0.1);border-radius:6px;">
                            <?php foreach (['quote' => 'Quote', 'proforma' => 'Proforma', 'invoice' => 'Invoice'] as $previewTypeValue => $previewTypeLabel): ?>
                                <option value="<?php echo $previewTypeValue; ?>" <?php echo ($invoiceSettings['preview_document_type'] ?? 'invoice') === $previewTypeValue ? 'selected' : ''; ?>><?php echo htmlspecialchars($previewTypeLabel); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="content-card" style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:1rem;">
                    <div style="grid-column:1 / -1;border:1px solid #e2e8f0;background:#f8fafc;border-radius:12px;padding:1rem;display:grid;gap:1rem;">
                        <div style="display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;flex-wrap:wrap;">
                            <div>
                                <h3 style="margin:0;color:#0f172a;">Document identity</h3>
                                <p style="margin:0.25rem 0 0;color:#64748b;font-size:0.875rem;">Invoices, quotes, proformas, and finance reports use Company Profile for logo and company details.</p>
                            </div>
                            <a href="?tab=company" class="btn-premium-secondary">Edit Company Profile</a>
                        </div>
                        <div style="display:grid;grid-template-columns:minmax(0,180px) 1fr;gap:1rem;align-items:start;">
                            <div style="border:1px dashed rgba(0,0,0,0.15);border-radius:12px;padding:1rem;background:#fff;min-height:110px;display:flex;align-items:center;justify-content:center;">
                                <?php if ($invoiceLogoPreview !== ''): ?>
                                    <img src="<?php echo htmlspecialchars($invoiceLogoPreview); ?>" alt="Company logo" style="max-width:150px;max-height:64px;object-fit:contain;display:block;">
                                <?php else: ?>
                                    <span style="font-size:0.875rem;color:#64748b;">No profile logo</span>
                                <?php endif; ?>
                            </div>
                            <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0.75rem;color:#334155;font-size:0.9rem;">
                                <div><strong style="display:block;color:#0f172a;">Legal / billing name</strong><?php echo htmlspecialchars(($invoiceSettings['company_legal_name'] ?? '') !== '' ? (string) $invoiceSettings['company_legal_name'] : 'Not set'); ?></div>
                                <div><strong style="display:block;color:#0f172a;">Tax ID / VAT</strong><?php echo htmlspecialchars(($invoiceSettings['company_tax_id'] ?? '') !== '' ? (string) $invoiceSettings['company_tax_id'] : 'Not set'); ?></div>
                                <div><strong style="display:block;color:#0f172a;">Email</strong><?php echo htmlspecialchars(($invoiceSettings['company_email'] ?? '') !== '' ? (string) $invoiceSettings['company_email'] : 'Not set'); ?></div>
                                <div><strong style="display:block;color:#0f172a;">Phone</strong><?php echo htmlspecialchars(($invoiceSettings['company_phone'] ?? '') !== '' ? (string) $invoiceSettings['company_phone'] : 'Not set'); ?></div>
                                <div style="grid-column:1 / -1;"><strong style="display:block;color:#0f172a;">Address</strong><?php echo nl2br(htmlspecialchars(($invoiceSettings['company_address'] ?? '') !== '' ? (string) $invoiceSettings['company_address'] : 'Not set')); ?></div>
                            </div>
                        </div>
                    </div>
                    <div>
                        <label style="display:block;margin-bottom:0.5rem;font-weight:600;">Bank name</label>
                        <input type="text" name="invoice_bank_name" value="<?php echo htmlspecialchars($invoiceSettings['bank_name'] ?? ''); ?>" style="width:100%;padding:0.6rem;border:1px solid rgba(0,0,0,0.1);border-radius:6px;">
                    </div>
                    <div>
                        <label style="display:block;margin-bottom:0.5rem;font-weight:600;">Account name</label>
                        <input type="text" name="invoice_bank_account_name" value="<?php echo htmlspecialchars($invoiceSettings['bank_account_name'] ?? ''); ?>" style="width:100%;padding:0.6rem;border:1px solid rgba(0,0,0,0.1);border-radius:6px;">
                    </div>
                    <div>
                        <label style="display:block;margin-bottom:0.5rem;font-weight:600;">Account number</label>
                        <input type="text" name="invoice_bank_account_number" value="<?php echo htmlspecialchars($invoiceSettings['bank_account_number'] ?? ''); ?>" style="width:100%;padding:0.6rem;border:1px solid rgba(0,0,0,0.1);border-radius:6px;">
                    </div>
                    <div>
                        <label style="display:block;margin-bottom:0.5rem;font-weight:600;">Bank branch</label>
                        <input type="text" name="invoice_bank_branch" value="<?php echo htmlspecialchars($invoiceSettings['bank_branch'] ?? ''); ?>" style="width:100%;padding:0.6rem;border:1px solid rgba(0,0,0,0.1);border-radius:6px;">
                    </div>
                    <div>
                        <label style="display:block;margin-bottom:0.5rem;font-weight:600;">SWIFT</label>
                        <input type="text" name="invoice_bank_swift" value="<?php echo htmlspecialchars($invoiceSettings['bank_swift'] ?? ''); ?>" style="width:100%;padding:0.6rem;border:1px solid rgba(0,0,0,0.1);border-radius:6px;">
                    </div>
                    <div style="grid-column:1 / -1;">
                        <label style="display:block;margin-bottom:0.5rem;font-weight:600;">Bank instructions</label>
                        <textarea name="invoice_bank_instructions" rows="3" style="width:100%;padding:0.6rem;border:1px solid rgba(0,0,0,0.1);border-radius:6px;"><?php echo htmlspecialchars($invoiceSettings['bank_instructions'] ?? ''); ?></textarea>
                    </div>
                    <div style="grid-column:1 / -1;">
                        <label style="display:block;margin-bottom:0.5rem;font-weight:600;">Default notes</label>
                        <textarea name="invoice_default_notes" rows="3" style="width:100%;padding:0.6rem;border:1px solid rgba(0,0,0,0.1);border-radius:6px;"><?php echo htmlspecialchars($invoiceSettings['default_notes'] ?? ''); ?></textarea>
                    </div>
                    <div style="grid-column:1 / -1;">
                        <label style="display:block;margin-bottom:0.5rem;font-weight:600;">Default terms</label>
                        <textarea name="invoice_default_terms" rows="3" style="width:100%;padding:0.6rem;border:1px solid rgba(0,0,0,0.1);border-radius:6px;"><?php echo htmlspecialchars($invoiceSettings['default_terms'] ?? ''); ?></textarea>
                    </div>
                    <div style="grid-column:1 / -1;">
                        <label style="display:block;margin-bottom:0.5rem;font-weight:600;">Footer text</label>
                        <textarea name="invoice_footer_text" rows="3" style="width:100%;padding:0.6rem;border:1px solid rgba(0,0,0,0.1);border-radius:6px;"><?php echo htmlspecialchars($invoiceSettings['footer_text'] ?? ''); ?></textarea>
                    </div>
                    <div style="grid-column:1 / -1;">
                        <label style="display:block;margin-bottom:0.5rem;font-weight:600;">Proposal intro text</label>
                        <textarea name="invoice_proposal_intro_text" rows="3" style="width:100%;padding:0.6rem;border:1px solid rgba(0,0,0,0.1);border-radius:6px;"><?php echo htmlspecialchars($invoiceSettings['proposal_intro_text'] ?? ''); ?></textarea>
                    </div>
                    <div style="grid-column:1 / -1;">
                        <label style="display:block;margin-bottom:0.5rem;font-weight:600;">Acceptance instructions</label>
                        <textarea name="invoice_acceptance_instructions" rows="3" style="width:100%;padding:0.6rem;border:1px solid rgba(0,0,0,0.1);border-radius:6px;"><?php echo htmlspecialchars($invoiceSettings['acceptance_instructions'] ?? ''); ?></textarea>
                    </div>
                </div>

                <div class="content-card" style="display:grid;gap:1rem;">
                    <h3 style="margin:0;color:#0f172a;">AI Controls</h3>
                    <div style="display:flex;flex-wrap:wrap;gap:1rem;">
                        <?php foreach ([
                            'invoice_ai_create_quotes' => 'Allow AI to create quotes',
                            'invoice_ai_revise_documents' => 'Allow AI to revise commercial docs',
                            'invoice_ai_send_documents' => 'Allow AI to send docs',
                            'invoice_ai_finalize_invoices' => 'Allow AI to finalize invoices',
                            'invoice_ai_mark_paid' => 'Allow AI to mark paid',
                            'invoice_ai_require_approval_send' => 'Require approval before send',
                            'invoice_ai_require_approval_finalize' => 'Require approval before finalize',
                        ] as $field => $label): ?>
                            <?php $settingKey = str_replace('invoice_', '', $field); ?>
                            <label style="display:flex;align-items:center;gap:0.5rem;cursor:pointer;">
                                <input type="checkbox" name="<?php echo $field; ?>" value="1" <?php echo !empty($invoiceSettings[$settingKey]) ? 'checked' : ''; ?>>
                                <span><?php echo htmlspecialchars($label); ?></span>
                            </label>
                        <?php endforeach; ?>
                        <label style="display:flex;align-items:center;gap:0.5rem;cursor:pointer;">
                            <input type="checkbox" name="invoice_ai_channel_email" value="1" <?php echo !empty($invoiceSettings['ai_allowed_channels']['email']) ? 'checked' : ''; ?>>
                            <span>Email channel</span>
                        </label>
                        <label style="display:flex;align-items:center;gap:0.5rem;cursor:pointer;">
                            <input type="checkbox" name="invoice_ai_channel_whatsapp" value="1" <?php echo !empty($invoiceSettings['ai_allowed_channels']['whatsapp']) ? 'checked' : ''; ?>>
                            <span>WhatsApp channel</span>
                        </label>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                        <div>
                            <label style="display:block;margin-bottom:0.5rem;font-weight:600;">Max AI discount %</label>
                            <input type="number" name="invoice_ai_max_discount_percent" min="0" step="0.01" value="<?php echo htmlspecialchars((string) ($invoiceSettings['ai_max_discount_percent'] ?? 20)); ?>" style="width:100%;padding:0.6rem;border:1px solid rgba(0,0,0,0.1);border-radius:6px;">
                        </div>
                        <div>
                            <label style="display:block;margin-bottom:0.5rem;font-weight:600;">Max AI total change %</label>
                            <input type="number" name="invoice_ai_max_total_change_percent" min="0" step="0.01" value="<?php echo htmlspecialchars((string) ($invoiceSettings['ai_max_total_change_percent'] ?? 25)); ?>" style="width:100%;padding:0.6rem;border:1px solid rgba(0,0,0,0.1);border-radius:6px;">
                        </div>
                    </div>
                    <div style="display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:1rem;">
                        <?php foreach (['proposal', 'negotiation', 'closed_won'] as $stageName): ?>
                            <div>
                                <div style="font-weight:600;margin-bottom:0.5rem;text-transform:capitalize;"><?php echo str_replace('_', ' ', $stageName); ?></div>
                                <?php foreach ($allDocTypes as $docType): ?>
                                    <label style="display:flex;align-items:center;gap:0.5rem;cursor:pointer;margin-bottom:0.35rem;">
                                        <input type="checkbox" name="invoice_stage_<?php echo $stageName; ?>[]" value="<?php echo $docType; ?>" <?php echo in_array($docType, $invoiceSettings['ai_allowed_document_types_by_stage'][$stageName] ?? [], true) ? 'checked' : ''; ?>>
                                        <span><?php echo htmlspecialchars(ucfirst($docType)); ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div style="display:flex;gap:var(--spacing-md);align-items:center;">
                    <button type="submit" class="btn-premium-primary">Save Settings</button>
                </div>

                <div class="content-card" style="display:grid;gap:0.75rem;">
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;">
                        <div>
                            <h3 style="margin:0;color:#0f172a;">Saved Preview</h3>
                            <p style="margin:0.25rem 0 0 0;color:#64748b;font-size:0.875rem;">Preview the current invoice design using the selected currency and your real product catalog when products exist.</p>
                        </div>
                        <a id="invoice-preview-link" href="../api/invoice_settings_preview.php?document_type=<?php echo urlencode((string) ($invoiceSettings['preview_document_type'] ?? 'invoice')); ?>&template_key=<?php echo urlencode((string) ($invoiceSettings['default_template_key'] ?? $invoiceSettings['visual_theme'] ?? 'classic')); ?>&currency=<?php echo urlencode((string) ($invoiceSettings['default_currency'] ?? 'USD')); ?>" target="_blank" class="btn-premium-secondary">Open full preview</a>
                    </div>
                    <iframe
                        id="invoice-preview-frame"
                        src="../api/invoice_settings_preview.php?document_type=<?php echo urlencode((string) ($invoiceSettings['preview_document_type'] ?? 'invoice')); ?>&template_key=<?php echo urlencode((string) ($invoiceSettings['default_template_key'] ?? $invoiceSettings['visual_theme'] ?? 'classic')); ?>&currency=<?php echo urlencode((string) ($invoiceSettings['default_currency'] ?? 'USD')); ?>"
                        title="Saved invoice settings preview"
                        style="width:100%;min-height:960px;border:1px solid rgba(0,0,0,0.1);border-radius:12px;background:#fff;"
                    ></iframe>
                    <script>
                        (function () {
                            var currencyEl = document.getElementById('invoice_default_currency');
                            var themeEl = document.getElementById('invoice_default_template_key');
                            var documentTypeEl = document.getElementById('invoice_preview_document_type');
                            var frameEl = document.getElementById('invoice-preview-frame');
                            var linkEl = document.getElementById('invoice-preview-link');
                            if (!currencyEl || !themeEl || !documentTypeEl || !frameEl || !linkEl) {
                                return;
                            }

                            function buildPreviewUrl() {
                                var params = new URLSearchParams({
                                    document_type: documentTypeEl.value || 'invoice',
                                    template_key: themeEl.value || 'classic',
                                    currency: currencyEl.value || 'USD'
                                });
                                return '../api/invoice_settings_preview.php?' + params.toString();
                            }

                            function refreshPreview() {
                                var nextUrl = buildPreviewUrl();
                                frameEl.src = nextUrl;
                                linkEl.href = nextUrl;
                            }

                            [currencyEl, themeEl, documentTypeEl].forEach(function (el) {
                                el.addEventListener('change', refreshPreview);
                            });
                        })();
                    </script>
                </div>
            </form>
        <?php elseif ($activeTab === 'billing'): ?>
            <?php
            $workspaceBillingSettings = $workspaceBillingSettings ?? (new WorkspaceBillingSettings())->get();
            $launchReadinessService = new \CRM\Services\WorkspaceLaunchReadinessService();
            $saasBillingReadiness = $launchReadinessService->checkWorkspaceSaasReadiness('billing_portal');
            if ($activeWorkspaceId > 0 && empty($saasBillingPortal['plans']) && empty($saasBillingPortal['token_packs']) && empty($saasBillingPortal['ledger'])) {
                if (!empty($saasBillingReadiness['ready'])) {
                    $saasBillingPortal = (new SaaSBillingService())->getWorkspaceBillingPortalData($activeWorkspaceId, 20, $user);
                } else {
                    $saasBillingReadinessError = (string) ($saasBillingReadiness['customer_message'] ?? 'Workspace billing is temporarily unavailable until the latest SaaS migrations are applied.');
                    $saasBillingService = new SaaSBillingService();
                    if (!empty($launchReadinessService->checkWorkspaceSaasReadiness('billing_snapshot')['ready'])) {
                        $saasBillingPortal['snapshot'] = $saasBillingService->getWorkspaceSnapshot($activeWorkspaceId, $user);
                    }
                    if (!empty($launchReadinessService->checkWorkspaceSaasReadiness('billing_checkout')['ready'])) {
                        $saasBillingPortal['plans'] = $saasBillingService->listSubscriptionPrices();
                        $saasBillingPortal['token_packs'] = $saasBillingService->listTokenPacks();
                    }
                }
            }
            $saasBillingSnapshot = (array) ($saasBillingPortal['snapshot'] ?? []);
            require __DIR__ . '/../views/partials/settings_billing_saas.php';
            ?>
        <?php elseif ($activeTab === 'package_settings'): ?>
            <?php
            $packageSettingsCatalog = $packageSettingsCatalog ?? (new PlatformWorkspaceOperationsService())->listBillingCatalogForOperators($packageAnalyticsFilters, $packageNegotiatedFilters);
            $packageSettingsPaymentModes = $packageSettingsPaymentModes ?? (new WorkspaceBillingSettings())->get();
            require __DIR__ . '/../views/partials/settings_package_settings.php';
            ?>
        <?php elseif ($activeTab === 'workspace_governance'): ?>
            <?php
            $workspaceGovernanceService = $workspaceGovernanceService ?? new WorkspaceGovernanceService();
            $workspaceGovernanceReadiness = (new \CRM\Services\WorkspaceLaunchReadinessService())->checkWorkspaceSaasReadiness('workspace_governance');
            if ($canWorkspaceGovernanceTab && $activeWorkspaceId > 0 && empty($workspaceGovernanceData['members']) && empty($workspaceGovernanceData['pending_invites']) && empty($workspaceGovernanceData['slugs'])) {
                if (!empty($workspaceGovernanceReadiness['ready'])) {
                    try {
                        $workspaceGovernanceData = $workspaceGovernanceService->getWorkspaceGovernanceData(
                            $activeWorkspaceId,
                            $userId,
                            (string) ($_GET['history_filter'] ?? '')
                        );
                    } catch (\Throwable $e) {
                        $workspaceGovernanceReadiness = [
                            'ready' => false,
                            'issues' => [
                                ['message' => $e->getMessage()],
                            ],
                            'customer_message' => $e->getMessage(),
                        ];
                        $workspaceGovernanceReadinessError = $e->getMessage();
                        $workspaceGovernanceData['workspace'] = [
                            'id' => $activeWorkspaceId,
                            'name' => (string) ($activeWorkspaceName ?? 'Workspace'),
                            'slug' => (string) ($activeWorkspaceSlug ?? ''),
                        ];
                        $workspaceGovernanceData['actor_membership'] = [
                            'role_slug' => (string) ($activeWorkspaceRole ?? 'viewer'),
                        ];
                    }
                } else {
                    $workspaceGovernanceReadinessError = (string) ($workspaceGovernanceReadiness['customer_message'] ?? 'Workspace team settings are temporarily unavailable until the latest governance migrations are applied.');
                    $workspaceGovernanceData['workspace'] = [
                        'id' => $activeWorkspaceId,
                        'name' => (string) ($activeWorkspaceName ?? 'Workspace'),
                        'slug' => (string) ($activeWorkspaceSlug ?? ''),
                    ];
                    $workspaceGovernanceData['actor_membership'] = [
                        'role_slug' => (string) ($activeWorkspaceRole ?? 'viewer'),
                    ];
                }
            }
            if ($canWorkspaceGovernanceTab && $activeWorkspaceId > 0) {
                try {
                    $automationReadinessRefreshPolicy = (new WorkspaceAutomationReadinessSettingsService())->getPolicy($activeWorkspaceId);
                } catch (\Throwable $e) {
                    $automationReadinessRefreshPolicy = $automationReadinessRefreshPolicy ?? [];
                }
            }
            require __DIR__ . '/../views/partials/settings_workspace_governance.php';
            ?>
        <?php elseif ($activeTab === 'meeting_bot'): ?>
            <?php $meetingBotService = $meetingBotService ?? new MeetingBotService(); ?>
            <?php $meetingBotState = $meetingBotState ?? $meetingBotService->buildSettingsState(); ?>
            <?php $meetingBotConfig = $meetingBotConfig ?? (array) ($meetingBotState['config'] ?? []); ?>
            <?php $meetingBotRuns = $meetingBotRuns ?? (Authorization::can('meeting_bot.view_runs', $user) ? $meetingBotService->getRecentRuns(12) : []); ?>
            <?php $meetingBotResolvedName = (string) ($meetingBotState['resolved_display_name'] ?? 'Meeting Assistant'); ?>
            <?php $meetingBotInviteInstructions = (string) ($meetingBotState['invite_instructions'] ?? ''); ?>
            <?php $meetingBotProvider = (string) ($meetingBotConfig['provider'] ?? 'zoom'); ?>
            <?php $canManageMeetingBot = Authorization::can('settings.meeting_bot', $user); ?>
            <form method="POST" action="" style="display:flex;flex-direction:column;gap:1.5rem;max-width:980px;">
                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                <input type="hidden" name="tab" value="meeting_bot">

                <div class="content-card" style="display:grid;gap:1rem;">
                    <div>
                        <h2 style="margin:0 0 .35rem 0;">Meeting Bot</h2>
                        <p style="margin:0;color:#64748b;line-height:1.6;">Run your own meeting assistant across Zoom and Google Meet, keep the visible bot name aligned with the organization, and route transcripts into CRM updates from one control surface.</p>
                    </div>
                    <?php if (!$canManageMeetingBot): ?>
                        <div style="padding:.85rem 1rem;border:1px solid #bfdbfe;border-radius:10px;background:#eff6ff;color:#1d4ed8;">
                            You can view meeting bot state and runs, but only users with settings access can change this configuration.
                        </div>
                    <?php endif; ?>
                    <label style="display:flex;align-items:center;gap:.65rem;font-weight:600;">
                        <input type="checkbox" name="meeting_bot_enabled" value="1" <?php echo !empty($meetingBotConfig['enabled']) ? 'checked' : ''; ?> <?php echo !$canManageMeetingBot ? 'disabled' : ''; ?> style="width:1.125rem;height:1.125rem;">
                        <span>Enable meeting bot</span>
                    </label>
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;">
                        <div>
                            <label style="display:block;margin-bottom:.4rem;font-weight:600;">Provider</label>
                            <select name="meeting_bot_provider" <?php echo !$canManageMeetingBot ? 'disabled' : ''; ?> style="width:100%;padding:.65rem;border:1px solid var(--border-color);border-radius:8px;">
                                <?php foreach (['zoom' => 'Zoom', 'google_meet' => 'Google Meet'] as $value => $label): ?>
                                    <option value="<?php echo $value; ?>" <?php echo $meetingBotProvider === $value ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label style="display:block;margin-bottom:.4rem;font-weight:600;">Bot display name</label>
                            <input type="text" name="meeting_bot_display_name" value="<?php echo htmlspecialchars((string) ($meetingBotConfig['bot_display_name'] ?? '')); ?>" <?php echo !$canManageMeetingBot ? 'readonly' : ''; ?> style="width:100%;padding:.65rem;border:1px solid var(--border-color);border-radius:8px;">
                            <div style="margin-top:.45rem;font-size:.82rem;color:#64748b;">Resolved default: <strong><?php echo htmlspecialchars($meetingBotResolvedName); ?></strong></div>
                        </div>
                        <div>
                            <label style="display:block;margin-bottom:.4rem;font-weight:600;">Join policy</label>
                            <select name="meeting_bot_join_policy" <?php echo !$canManageMeetingBot ? 'disabled' : ''; ?> style="width:100%;padding:.65rem;border:1px solid var(--border-color);border-radius:8px;">
                                <?php foreach (['manual_invite_only' => 'Manual invite only', 'calendar_suggested' => 'Calendar-linked suggested', 'auto_join_eligible' => 'Auto-join eligible meetings'] as $value => $label): ?>
                                    <option value="<?php echo $value; ?>" <?php echo (($meetingBotConfig['join_policy'] ?? 'manual_invite_only') === $value) ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label style="display:block;margin-bottom:.4rem;font-weight:600;">Recording mode</label>
                            <select name="meeting_bot_recording_mode" <?php echo !$canManageMeetingBot ? 'disabled' : ''; ?> style="width:100%;padding:.65rem;border:1px solid var(--border-color);border-radius:8px;">
                                <?php foreach (['provider_native' => 'Provider native', 'bot_requested' => 'Bot requested'] as $value => $label): ?>
                                    <option value="<?php echo $value; ?>" <?php echo (($meetingBotConfig['recording_mode'] ?? 'provider_native') === $value) ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label style="display:block;margin-bottom:.4rem;font-weight:600;">Auto-apply mode</label>
                            <select name="meeting_bot_auto_apply_mode" <?php echo !$canManageMeetingBot ? 'disabled' : ''; ?> style="width:100%;padding:.65rem;border:1px solid var(--border-color);border-radius:8px;">
                                <?php foreach (['suggest_only' => 'Suggest only', 'auto_safe' => 'Auto-safe', 'full_auto' => 'Full auto'] as $value => $label): ?>
                                    <option value="<?php echo $value; ?>" <?php echo (($meetingBotConfig['auto_apply_mode'] ?? 'auto_safe') === $value) ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <label style="display:flex;gap:.6rem;align-items:flex-start;">
                        <input type="checkbox" name="meeting_bot_transcript_required" value="1" <?php echo !empty($meetingBotConfig['transcript_required']) ? 'checked' : ''; ?> <?php echo !$canManageMeetingBot ? 'disabled' : ''; ?> style="width:1.05rem;height:1.05rem;margin-top:.2rem;">
                        <span>Require transcript capture before downstream CRM changes are allowed</span>
                    </label>
                </div>

                <?php if ($isSuperAdmin): ?>
                <div class="content-card" style="display:grid;gap:1rem;">
                    <div>
                        <h3 style="margin:0 0 .35rem 0;"><?php echo $meetingBotProvider === 'google_meet' ? 'Google Meet Provider' : 'Zoom Provider'; ?></h3>
                        <p style="margin:0;color:#64748b;"><?php echo $meetingBotProvider === 'google_meet'
                            ? 'Configure transcript-first Google Meet capture using Google Workspace and calendar-linked identifiers. Google Meet v1 uses transcript or notes ingestion rather than a live participant bot.'
                            : 'Store the Zoom app identifiers used by your first-party meeting bot orchestration and webhook handling.'; ?></p>
                    </div>
                    <?php if ($meetingBotProvider === 'google_meet'): ?>
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;">
                            <div>
                                <label style="display:block;margin-bottom:.4rem;font-weight:600;">Google Workspace client ID</label>
                                <input type="text" name="meeting_bot_google_workspace_client_id" value="<?php echo htmlspecialchars((string) ($meetingBotConfig['google_workspace_client_id'] ?? '')); ?>" <?php echo !$canManageMeetingBot ? 'readonly' : ''; ?> style="width:100%;padding:.65rem;border:1px solid var(--border-color);border-radius:8px;">
                            </div>
                            <div>
                                <label style="display:block;margin-bottom:.4rem;font-weight:600;">Google Workspace client secret</label>
                                <input type="text" name="meeting_bot_google_workspace_client_secret" value="<?php echo htmlspecialchars((string) ($meetingBotConfig['google_workspace_client_secret'] ?? '')); ?>" <?php echo !$canManageMeetingBot ? 'readonly' : ''; ?> style="width:100%;padding:.65rem;border:1px solid var(--border-color);border-radius:8px;">
                            </div>
                            <div>
                                <label style="display:block;margin-bottom:.4rem;font-weight:600;">Google project ID</label>
                                <input type="text" name="meeting_bot_google_workspace_project_id" value="<?php echo htmlspecialchars((string) ($meetingBotConfig['google_workspace_project_id'] ?? '')); ?>" <?php echo !$canManageMeetingBot ? 'readonly' : ''; ?> style="width:100%;padding:.65rem;border:1px solid var(--border-color);border-radius:8px;">
                            </div>
                            <div>
                                <label style="display:block;margin-bottom:.4rem;font-weight:600;">Calendar integration ID</label>
                                <input type="text" name="meeting_bot_google_calendar_integration_id" value="<?php echo htmlspecialchars((string) ($meetingBotConfig['google_calendar_integration_id'] ?? '')); ?>" <?php echo !$canManageMeetingBot ? 'readonly' : ''; ?> style="width:100%;padding:.65rem;border:1px solid var(--border-color);border-radius:8px;">
                            </div>
                            <div>
                                <label style="display:block;margin-bottom:.4rem;font-weight:600;">Transcript mode</label>
                                <select name="meeting_bot_google_transcript_mode" <?php echo !$canManageMeetingBot ? 'disabled' : ''; ?> style="width:100%;padding:.65rem;border:1px solid var(--border-color);border-radius:8px;">
                                    <?php foreach (['manual_ingest' => 'Manual ingest', 'workspace_export' => 'Workspace export'] as $value => $label): ?>
                                        <option value="<?php echo $value; ?>" <?php echo (($meetingBotConfig['google_transcript_mode'] ?? 'manual_ingest') === $value) ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    <?php else: ?>
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;">
                            <div>
                                <label style="display:block;margin-bottom:.4rem;font-weight:600;">Zoom account ID</label>
                                <input type="text" name="meeting_bot_zoom_account_id" value="<?php echo htmlspecialchars((string) ($meetingBotConfig['zoom_account_id'] ?? '')); ?>" <?php echo !$canManageMeetingBot ? 'readonly' : ''; ?> style="width:100%;padding:.65rem;border:1px solid var(--border-color);border-radius:8px;">
                            </div>
                            <div>
                                <label style="display:block;margin-bottom:.4rem;font-weight:600;">Zoom client ID</label>
                                <input type="text" name="meeting_bot_zoom_client_id" value="<?php echo htmlspecialchars((string) ($meetingBotConfig['zoom_client_id'] ?? '')); ?>" <?php echo !$canManageMeetingBot ? 'readonly' : ''; ?> style="width:100%;padding:.65rem;border:1px solid var(--border-color);border-radius:8px;">
                            </div>
                            <div>
                                <label style="display:block;margin-bottom:.4rem;font-weight:600;">Zoom client secret</label>
                                <input type="text" name="meeting_bot_zoom_client_secret" value="<?php echo htmlspecialchars((string) ($meetingBotConfig['zoom_client_secret'] ?? '')); ?>" <?php echo !$canManageMeetingBot ? 'readonly' : ''; ?> style="width:100%;padding:.65rem;border:1px solid var(--border-color);border-radius:8px;">
                            </div>
                        </div>
                    <?php endif; ?>
                    <div>
                        <label style="display:block;margin-bottom:.4rem;font-weight:600;">Consent notice</label>
                        <textarea name="meeting_bot_consent_notice" rows="3" <?php echo !$canManageMeetingBot ? 'readonly' : ''; ?> style="width:100%;padding:.75rem;border:1px solid var(--border-color);border-radius:10px;"><?php echo htmlspecialchars((string) ($meetingBotConfig['consent_notice'] ?? '')); ?></textarea>
                    </div>
                    <div style="padding:.9rem 1rem;border:1px solid #e2e8f0;border-radius:10px;background:#f8fafc;color:#475569;">
                        <?php echo htmlspecialchars($meetingBotInviteInstructions); ?>
                    </div>
                </div>

                <div class="content-card" style="display:grid;gap:1rem;">
                    <div>
                        <h3 style="margin:0 0 .35rem 0;">Secrets And Endpoints</h3>
                        <p style="margin:0;color:#64748b;">Use the webhook secret for provider callbacks and the scheduling secret for controlled external meeting registration if you expose that surface outside the authenticated UI.</p>
                    </div>
                    <div>
                        <label style="display:block;margin-bottom:.4rem;font-weight:600;">Webhook endpoint</label>
                        <code><?php echo htmlspecialchars(publicUrl('api/meeting_bot/webhook.php')); ?></code>
                    </div>
                    <div>
                        <label style="display:block;margin-bottom:.4rem;font-weight:600;">Scheduling endpoint</label>
                        <code><?php echo htmlspecialchars(publicUrl('api/meeting_bot/schedule.php')); ?></code>
                    </div>
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:1rem;">
                        <div>
                            <label style="display:block;margin-bottom:.4rem;font-weight:600;">Webhook secret</label>
                            <input type="text" name="meeting_bot_webhook_secret" value="<?php echo htmlspecialchars((string) ($meetingBotConfig['webhook_secret'] ?? '')); ?>" <?php echo !$canManageMeetingBot ? 'readonly' : ''; ?> style="width:100%;padding:.65rem;border:1px solid var(--border-color);border-radius:8px;font-family:monospace;">
                            <label style="display:flex;align-items:center;gap:.55rem;margin-top:.55rem;">
                                <input type="checkbox" name="meeting_bot_regenerate_webhook_secret" value="1" <?php echo !$canManageMeetingBot ? 'disabled' : ''; ?> style="width:1.05rem;height:1.05rem;">
                                <span>Generate a new webhook secret on save</span>
                            </label>
                        </div>
                        <div>
                            <label style="display:block;margin-bottom:.4rem;font-weight:600;">Scheduling secret</label>
                            <input type="text" name="meeting_bot_scheduling_secret" value="<?php echo htmlspecialchars((string) ($meetingBotConfig['scheduling_secret'] ?? '')); ?>" <?php echo !$canManageMeetingBot ? 'readonly' : ''; ?> style="width:100%;padding:.65rem;border:1px solid var(--border-color);border-radius:8px;font-family:monospace;">
                            <label style="display:flex;align-items:center;gap:.55rem;margin-top:.55rem;">
                                <input type="checkbox" name="meeting_bot_regenerate_scheduling_secret" value="1" <?php echo !$canManageMeetingBot ? 'disabled' : ''; ?> style="width:1.05rem;height:1.05rem;">
                                <span>Generate a new scheduling secret on save</span>
                            </label>
                        </div>
                    </div>
                </div>
                <?php else: ?>
                    <div class="content-card" style="display:grid;gap:.7rem;background:#f8fafc;border:1px solid #e2e8f0;">
                        <h3 style="margin:0;color:#0f172a;">Calendar-connected meeting notes</h3>
                        <p style="margin:0;color:#475569;line-height:1.55;">
                            Meeting notes are enabled from your connected calendar. The system detects Google Meet and Zoom links from calendar events; bot provider credentials and webhook secrets are platform-managed by Super Admin.
                        </p>
                    </div>
                <?php endif; ?>

                <?php if ($canManageMeetingBot): ?>
                    <div style="display:flex;justify-content:flex-end;">
                        <button type="submit" class="btn-premium-primary">Save Meeting Bot Settings</button>
                    </div>
                <?php endif; ?>
            </form>

            <?php if (Authorization::can('meeting_bot.view_runs', $user)): ?>
                <div class="content-card" style="margin-top:1.5rem;">
                    <h3 style="margin-top:0;">Recent Meeting Bot Runs</h3>
                    <div style="overflow:auto;">
                        <table class="premium-table">
                            <thead>
                                <tr>
                                    <th>Bot</th>
                                    <th>Meeting</th>
                                    <th>Status</th>
                                    <th>Transcript</th>
                                    <th>Downstream</th>
                                    <th>Created</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($meetingBotRuns)): ?>
                                    <tr><td colspan="6" style="padding:1.25rem;text-align:center;color:#64748b;">No meeting bot runs yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($meetingBotRuns as $run): ?>
                                        <tr>
                                            <td>
                                                <div style="font-weight:600;color:#0f172a;"><?php echo htmlspecialchars((string) ($run['bot_display_name_used'] ?? 'Meeting Assistant')); ?></div>
                                                <div style="font-size:.82rem;color:#64748b;"><?php echo htmlspecialchars((string) ($run['provider'] ?? 'zoom')); ?></div>
                                            </td>
                                            <td>
                                                <div style="font-weight:600;color:#0f172a;"><?php echo htmlspecialchars((string) ($run['title'] ?? 'Untitled meeting')); ?></div>
                                                <div style="font-size:.82rem;color:#64748b;"><?php echo htmlspecialchars((string) ($run['external_meeting_id'] ?? '')); ?></div>
                                                <?php if (!empty($run['external_event_id'])): ?><div style="font-size:.82rem;color:#94a3b8;"><?php echo htmlspecialchars((string) $run['external_event_id']); ?></div><?php endif; ?>
                                            </td>
                                            <td>
                                                <div><?php echo htmlspecialchars((string) ($run['status'] ?? 'scheduled')); ?></div>
                                                <?php if (!empty($run['failure_reason'])): ?><div style="font-size:.82rem;color:#b45309;"><?php echo htmlspecialchars((string) $run['failure_reason']); ?></div><?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars((string) ($run['transcript_status'] ?? 'pending')); ?></td>
                                            <td><?php echo htmlspecialchars((string) ($run['note_taker_status'] ?? ($run['meeting_note_apply_status'] ?? 'pending'))); ?></td>
                                            <td><?php echo htmlspecialchars((string) ($run['created_at'] ?? '')); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        <?php elseif ($activeTab === 'meeting_note_taker'): ?>
            <?php $meetingNoteTakerConfig = $meetingNoteTakerConfig ?? (new MeetingNoteTakerConfig())->get(); ?>
            <?php $canManageMeetingNoteTaker = Authorization::can('settings.meeting_note_taker', $user); ?>
            <form method="POST" action="" style="display:flex;flex-direction:column;gap:1.5rem;max-width:980px;">
                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                <input type="hidden" name="tab" value="meeting_note_taker">

                <div class="content-card" style="display:grid;gap:1rem;">
                    <div>
                        <h2 style="margin:0 0 .35rem 0;">Meeting Note Taker</h2>
                        <p style="margin:0;color:#64748b;line-height:1.6;">Ingest meeting transcripts or summaries from external providers, then automatically add notes, update safe contact context, create tasks, and move deals when confidence is high enough.</p>
                    </div>
                    <?php if (!$canManageMeetingNoteTaker): ?>
                        <div style="padding:.85rem 1rem;border:1px solid #bfdbfe;border-radius:10px;background:#eff6ff;color:#1d4ed8;">
                            You can view meeting note taker status and runs, but only users with settings access can change this configuration.
                        </div>
                    <?php endif; ?>
                    <label style="display:flex;align-items:center;gap:.65rem;font-weight:600;">
                        <input type="checkbox" name="meeting_note_taker_enabled" value="1" <?php echo !empty($meetingNoteTakerConfig['enabled']) ? 'checked' : ''; ?> <?php echo !$canManageMeetingNoteTaker ? 'disabled' : ''; ?> style="width:1.125rem;height:1.125rem;">
                        <span>Enable meeting note taker</span>
                    </label>
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;">
                        <div>
                            <label style="display:block;margin-bottom:.4rem;font-weight:600;">Auto-apply mode</label>
                            <select name="meeting_note_taker_auto_apply_mode" <?php echo !$canManageMeetingNoteTaker ? 'disabled' : ''; ?> style="width:100%;padding:.65rem;border:1px solid var(--border-color);border-radius:8px;">
                                <?php foreach (['suggest_only' => 'Suggest only', 'auto_safe' => 'Auto-safe', 'full_auto' => 'Full auto'] as $value => $label): ?>
                                    <option value="<?php echo $value; ?>" <?php echo (($meetingNoteTakerConfig['auto_apply_mode'] ?? 'full_auto') === $value) ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label style="display:block;margin-bottom:.4rem;font-weight:600;">Deal move confidence</label>
                            <input type="number" step="0.01" min="0" max="1" name="meeting_note_taker_deal_stage_min_confidence" value="<?php echo htmlspecialchars((string) ($meetingNoteTakerConfig['deal_stage_min_confidence'] ?? 0.90)); ?>" <?php echo !$canManageMeetingNoteTaker ? 'disabled' : ''; ?> style="width:100%;padding:.65rem;border:1px solid var(--border-color);border-radius:8px;">
                        </div>
                        <div>
                            <label style="display:block;margin-bottom:.4rem;font-weight:600;">Contact update confidence</label>
                            <input type="number" step="0.01" min="0" max="1" name="meeting_note_taker_contact_update_min_confidence" value="<?php echo htmlspecialchars((string) ($meetingNoteTakerConfig['contact_update_min_confidence'] ?? 0.75)); ?>" <?php echo !$canManageMeetingNoteTaker ? 'disabled' : ''; ?> style="width:100%;padding:.65rem;border:1px solid var(--border-color);border-radius:8px;">
                        </div>
                        <div>
                            <label style="display:block;margin-bottom:.4rem;font-weight:600;">Stored context entries per contact</label>
                            <input type="number" min="1" max="50" name="meeting_note_taker_max_context_entries" value="<?php echo (int) ($meetingNoteTakerConfig['max_context_entries'] ?? 10); ?>" <?php echo !$canManageMeetingNoteTaker ? 'disabled' : ''; ?> style="width:100%;padding:.65rem;border:1px solid var(--border-color);border-radius:8px;">
                        </div>
                    </div>
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:1rem;">
                        <label style="display:flex;gap:.6rem;align-items:flex-start;"><input type="checkbox" name="meeting_note_taker_contact_updates_additive_only" value="1" <?php echo !empty($meetingNoteTakerConfig['contact_updates_additive_only']) ? 'checked' : ''; ?> <?php echo !$canManageMeetingNoteTaker ? 'disabled' : ''; ?> style="width:1.05rem;height:1.05rem;margin-top:.2rem;"><span>Only fill empty contact fields when applying structured updates</span></label>
                        <label style="display:flex;gap:.6rem;align-items:flex-start;"><input type="checkbox" name="meeting_note_taker_task_auto_create_enabled" value="1" <?php echo !empty($meetingNoteTakerConfig['task_auto_create_enabled']) ? 'checked' : ''; ?> <?php echo !$canManageMeetingNoteTaker ? 'disabled' : ''; ?> style="width:1.05rem;height:1.05rem;margin-top:.2rem;"><span>Auto-create follow-up tasks from action items</span></label>
                        <label style="display:flex;gap:.6rem;align-items:flex-start;"><input type="checkbox" name="meeting_note_taker_deal_stage_auto_move_enabled" value="1" <?php echo !empty($meetingNoteTakerConfig['deal_stage_auto_move_enabled']) ? 'checked' : ''; ?> <?php echo !$canManageMeetingNoteTaker ? 'disabled' : ''; ?> style="width:1.05rem;height:1.05rem;margin-top:.2rem;"><span>Allow automatic deal movement when confidence passes threshold</span></label>
                    </div>
                </div>

                <div class="content-card" style="display:grid;gap:1rem;">
                    <div>
                        <h3 style="margin:0 0 .35rem 0;">Allowed Contact Fields</h3>
                        <p style="margin:0;color:#64748b;">These are the only structured contact fields the meeting note taker may update automatically.</p>
                    </div>
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:.75rem;">
                        <?php foreach (['job_title' => 'Job title', 'location' => 'Location', 'company_website' => 'Company website', 'linkedin_url' => 'LinkedIn URL', 'twitter_url' => 'Twitter URL', 'timezone' => 'Timezone'] as $fieldKey => $fieldLabel): ?>
                            <label style="display:flex;align-items:center;gap:.55rem;">
                                <input type="checkbox" name="meeting_note_taker_allowed_contact_fields[]" value="<?php echo htmlspecialchars($fieldKey); ?>" <?php echo in_array($fieldKey, (array) ($meetingNoteTakerConfig['allowed_contact_fields'] ?? []), true) ? 'checked' : ''; ?> <?php echo !$canManageMeetingNoteTaker ? 'disabled' : ''; ?> style="width:1.05rem;height:1.05rem;">
                                <span><?php echo htmlspecialchars($fieldLabel); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="content-card" style="display:grid;gap:1rem;">
                    <div>
                        <h3 style="margin:0 0 .35rem 0;">Ingest Secret</h3>
                        <p style="margin:0;color:#64748b;">External meeting tools can POST transcripts to <code><?php echo htmlspecialchars(publicUrl('api/meeting_notes/ingest.php')); ?></code> using the <code>X-Meeting-Notes-Key</code> header.</p>
                    </div>
                    <input type="text" name="meeting_note_taker_ingest_secret" value="<?php echo htmlspecialchars((string) ($meetingNoteTakerConfig['ingest_secret'] ?? '')); ?>" <?php echo !$canManageMeetingNoteTaker ? 'readonly' : ''; ?> style="width:100%;padding:.65rem;border:1px solid var(--border-color);border-radius:8px;font-family:monospace;">
                    <label style="display:flex;align-items:center;gap:.55rem;">
                        <input type="checkbox" name="meeting_note_taker_regenerate_secret" value="1" <?php echo !$canManageMeetingNoteTaker ? 'disabled' : ''; ?> style="width:1.05rem;height:1.05rem;">
                        <span>Generate a new ingest secret on save</span>
                    </label>
                </div>

                <?php if ($canManageMeetingNoteTaker): ?>
                    <div style="display:flex;justify-content:flex-end;">
                        <button type="submit" class="btn-premium-primary">Save Meeting Note Taker Settings</button>
                    </div>
                <?php endif; ?>
            </form>

            <?php if (Authorization::can('meeting_notes.view_runs', $user)): ?>
                <div class="content-card" style="margin-top:1.5rem;">
                    <h3 style="margin-top:0;">Recent Meeting Note Runs</h3>
                    <div style="overflow:auto;">
                        <table class="premium-table">
                            <thead>
                                <tr>
                                    <th>Provider</th>
                                    <th>Meeting</th>
                                    <th>Match</th>
                                    <th>Apply Status</th>
                                    <th>Confidence</th>
                                    <th>Created</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($meetingNoteTakerRuns)): ?>
                                    <tr><td colspan="6" style="padding:1.25rem;text-align:center;color:#64748b;">No meeting note runs yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($meetingNoteTakerRuns as $run): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars((string) ($run['provider'] ?? 'generic')); ?></td>
                                            <td>
                                                <div style="font-weight:600;color:#0f172a;"><?php echo htmlspecialchars((string) ($run['title'] ?? 'Untitled meeting')); ?></div>
                                                <div style="font-size:.82rem;color:#64748b;"><?php echo htmlspecialchars((string) ($run['organizer_email'] ?? '')); ?></div>
                                            </td>
                                            <td>
                                                <?php if (!empty($run['matched_contact_id'])): ?>
                                                    <div><?php echo htmlspecialchars(trim((string) (($run['first_name'] ?? '') . ' ' . ($run['last_name'] ?? ''))) ?: (string) ($run['contact_email'] ?? 'Contact #' . (int) $run['matched_contact_id'])); ?></div>
                                                <?php else: ?>
                                                    <div style="color:#b45309;">No contact match</div>
                                                <?php endif; ?>
                                                <?php if (!empty($run['matched_deal_id'])): ?>
                                                    <div style="font-size:.82rem;color:#64748b;"><?php echo htmlspecialchars((string) ($run['deal_title'] ?? ('Deal #' . (int) $run['matched_deal_id']))); ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars((string) ($run['apply_status'] ?? 'skipped')); ?></td>
                                            <td><?php echo htmlspecialchars(number_format((float) ($run['confidence'] ?? 0), 2)); ?></td>
                                            <td><?php echo htmlspecialchars((string) ($run['created_at'] ?? '')); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        <?php elseif ($activeTab === 'workflow_automation'): ?>
            <?php
            $workflowAutomationService = $workflowAutomationService ?? new WorkflowAutomationControlService();
            $workflowAutomationState = $workflowAutomationState ?? $workflowAutomationService->buildSettingsState();
            $workflowAutomationPendingApprovals = $workflowAutomationPendingApprovals ?? (new WorkflowAutomationProposalService())->listAll(['status' => 'pending', 'limit' => 20]);
            $workflowAutomationMetrics = $workflowAutomationMetrics ?? (new WorkflowAutomationProposalService())->buildSummaryMetrics($workflowAutomationPendingApprovals);
            $workflowAutomationControl = (array) ($workflowAutomationState['control'] ?? []);
            $workflowAutomationPromotion = (array) ($workflowAutomationState['promotion'] ?? []);
            $workflowAutomationMetadata = (array) ($workflowAutomationControl['metadata'] ?? []);
            $workflowAutomationManaged = !empty($workflowAutomationState['managed_by_auto_admin']);
            ?>
            <form method="POST" action="" style="display:flex;flex-direction:column;gap:1.5rem;max-width:980px;">
                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                <input type="hidden" name="tab" value="workflow_automation">

                <div class="content-card" style="display:grid;gap:1rem;">
                    <div style="display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;flex-wrap:wrap;">
                        <div>
                            <h2 style="margin:0 0 .4rem 0;">Workflow Automation</h2>
                            <p style="margin:0;color:#64748b;">Control whether AI only suggests workflows, creates pending proposals, or auto-applies confident workflow changes.</p>
                        </div>
                        <div style="display:flex;gap:.75rem;flex-wrap:wrap;">
                            <a href="workflow_approvals.php" class="btn-premium-secondary">Open approvals workbench</a>
                            <a href="ai_learning_review.php?domain=workflow_execution" class="btn-premium-secondary">Learning review</a>
                        </div>
                    </div>
                    <?php if ($workflowAutomationManaged): ?>
                        <div style="padding:.85rem 1rem;border:1px solid #86efac;border-radius:10px;background:#f0fdf4;color:#166534;">
                            Auto Admin is managing workflow automation right now. This page remains readable, but changes are locked until manual control is restored.
                        </div>
                    <?php endif; ?>
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;">
                        <div style="padding:.9rem;border:1px solid #e2e8f0;border-radius:12px;background:#f8fafc;">
                            <div style="font-size:12px;color:#64748b;text-transform:uppercase;">Current mode</div>
                            <div style="margin-top:.35rem;font-size:1.35rem;font-weight:700;color:#0f172a;"><?php echo htmlspecialchars((string) ($workflowAutomationState['mode_label'] ?? 'Suggest only')); ?></div>
                        </div>
                        <div style="padding:.9rem;border:1px solid #e2e8f0;border-radius:12px;background:#f8fafc;">
                            <div style="font-size:12px;color:#64748b;text-transform:uppercase;">Pending approvals</div>
                            <div style="margin-top:.35rem;font-size:1.35rem;font-weight:700;color:#0f172a;"><?php echo (int) ($workflowAutomationMetrics['pending_count'] ?? 0); ?></div>
                        </div>
                        <div style="padding:.9rem;border:1px solid #e2e8f0;border-radius:12px;background:#f8fafc;">
                            <div style="font-size:12px;color:#64748b;text-transform:uppercase;">Promotion gate</div>
                            <div style="margin-top:.35rem;font-size:1.05rem;font-weight:700;color:#0f172a;"><?php echo htmlspecialchars((string) ($workflowAutomationPromotion['decision'] ?? 'hold')); ?></div>
                        </div>
                        <div style="padding:.9rem;border:1px solid #e2e8f0;border-radius:12px;background:#f8fafc;">
                            <div style="font-size:12px;color:#64748b;text-transform:uppercase;">Recommended mode</div>
                            <div style="margin-top:.35rem;font-size:1.05rem;font-weight:700;color:#0f172a;"><?php echo htmlspecialchars((string) ($workflowAutomationPromotion['recommended_mode'] ?? 'suggest_only')); ?></div>
                        </div>
                    </div>
                </div>

                <div class="content-card" style="display:grid;gap:1rem;">
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;">
                        <div>
                            <label style="display:block;margin-bottom:.45rem;font-weight:600;">Automation mode</label>
                            <select name="workflow_automation_mode" <?php echo $workflowAutomationManaged ? 'disabled' : ''; ?> style="width:100%;padding:.65rem;border:1px solid var(--border-color);border-radius:8px;">
                                <?php foreach (['suggest_only' => 'Suggest only', 'auto_safe' => 'Auto-safe', 'full_auto' => 'Full auto'] as $value => $label): ?>
                                    <option value="<?php echo $value; ?>" <?php echo (($workflowAutomationControl['autonomy_mode'] ?? 'suggest_only') === $value) ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label style="display:block;margin-bottom:.45rem;font-weight:600;">Promotion status</label>
                            <select name="workflow_automation_promotion_status" <?php echo $workflowAutomationManaged ? 'disabled' : ''; ?> style="width:100%;padding:.65rem;border:1px solid var(--border-color);border-radius:8px;">
                                <?php foreach (['suggest_only', 'auto_safe', 'full_auto', 'blocked'] as $value): ?>
                                    <option value="<?php echo $value; ?>" <?php echo (($workflowAutomationControl['promotion_status'] ?? 'suggest_only') === $value) ? 'selected' : ''; ?>><?php echo htmlspecialchars($value); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label style="display:block;margin-bottom:.45rem;font-weight:600;">Max daily auto actions</label>
                            <input type="number" min="0" name="workflow_automation_max_daily_auto_actions" value="<?php echo (int) ($workflowAutomationMetadata['max_daily_auto_actions'] ?? 50); ?>" <?php echo $workflowAutomationManaged ? 'disabled' : ''; ?> style="width:100%;padding:.65rem;border:1px solid var(--border-color);border-radius:8px;">
                        </div>
                        <div>
                            <label style="display:block;margin-bottom:.45rem;font-weight:600;">Max customer-facing risk</label>
                            <input type="number" step="0.01" min="0" max="1" name="workflow_automation_max_customer_facing_risk" value="<?php echo htmlspecialchars((string) ($workflowAutomationMetadata['max_customer_facing_risk'] ?? 0.95)); ?>" <?php echo $workflowAutomationManaged ? 'disabled' : ''; ?> style="width:100%;padding:.65rem;border:1px solid var(--border-color);border-radius:8px;">
                        </div>
                    </div>
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:1rem;">
                        <label style="display:flex;gap:.6rem;align-items:flex-start;"><input type="checkbox" name="workflow_automation_demonstration_capture_enabled" value="1" <?php echo !empty($workflowAutomationControl['demonstration_capture_enabled']) ? 'checked' : ''; ?> <?php echo $workflowAutomationManaged ? 'disabled' : ''; ?> style="width:1.05rem;height:1.05rem;margin-top:.2rem;"><span>Capture workflow automation demonstrations</span></label>
                        <label style="display:flex;gap:.6rem;align-items:flex-start;"><input type="checkbox" name="workflow_automation_policy_learning_enabled" value="1" <?php echo !empty($workflowAutomationControl['policy_learning_enabled']) ? 'checked' : ''; ?> <?php echo $workflowAutomationManaged ? 'disabled' : ''; ?> style="width:1.05rem;height:1.05rem;margin-top:.2rem;"><span>Use demonstrations for policy learning</span></label>
                        <label style="display:flex;gap:.6rem;align-items:flex-start;"><input type="checkbox" name="workflow_automation_review_ui_enabled" value="1" <?php echo !empty($workflowAutomationControl['review_ui_enabled']) ? 'checked' : ''; ?> <?php echo $workflowAutomationManaged ? 'disabled' : ''; ?> style="width:1.05rem;height:1.05rem;margin-top:.2rem;"><span>Keep review UI enabled</span></label>
                        <label style="display:flex;gap:.6rem;align-items:flex-start;"><input type="checkbox" name="workflow_automation_fast_promotion_enabled" value="1" <?php echo !empty($workflowAutomationControl['fast_promotion_enabled']) ? 'checked' : ''; ?> <?php echo $workflowAutomationManaged ? 'disabled' : ''; ?> style="width:1.05rem;height:1.05rem;margin-top:.2rem;"><span>Allow fast promotion when quality gates pass</span></label>
                        <label style="display:flex;gap:.6rem;align-items:flex-start;"><input type="checkbox" name="workflow_automation_auto_downgrade_on_drift" value="1" <?php echo !empty($workflowAutomationControl['auto_downgrade_on_drift']) ? 'checked' : ''; ?> <?php echo $workflowAutomationManaged ? 'disabled' : ''; ?> style="width:1.05rem;height:1.05rem;margin-top:.2rem;"><span>Auto-downgrade on drift</span></label>
                        <label style="display:flex;gap:.6rem;align-items:flex-start;"><input type="checkbox" name="workflow_automation_block_customer_facing_full_auto" value="1" <?php echo !empty($workflowAutomationMetadata['block_customer_facing_full_auto']) ? 'checked' : ''; ?> <?php echo $workflowAutomationManaged ? 'disabled' : ''; ?> style="width:1.05rem;height:1.05rem;margin-top:.2rem;"><span>Block customer-facing workflow actions in full auto</span></label>
                        <label style="display:flex;gap:.6rem;align-items:flex-start;"><input type="checkbox" name="workflow_automation_approval_required_for_promotion" value="1" <?php echo !empty($workflowAutomationMetadata['approval_required_for_promotion']) ? 'checked' : ''; ?> <?php echo $workflowAutomationManaged ? 'disabled' : ''; ?> style="width:1.05rem;height:1.05rem;margin-top:.2rem;"><span>Require approval before any promotion</span></label>
                    </div>
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:1rem;">
                        <div>
                            <label style="display:block;margin-bottom:.45rem;font-weight:600;">Allowed actions cap</label>
                            <input type="text" name="workflow_automation_allowed_actions" value="<?php echo htmlspecialchars(implode(', ', (array) ($workflowAutomationMetadata['allowed_actions'] ?? []))); ?>" <?php echo $workflowAutomationManaged ? 'disabled' : ''; ?> placeholder="leave blank for any action" style="width:100%;padding:.65rem;border:1px solid var(--border-color);border-radius:8px;">
                        </div>
                        <div>
                            <label style="display:block;margin-bottom:.45rem;font-weight:600;">Human checkpoint actions</label>
                            <input type="text" name="workflow_automation_checkpoint_actions" value="<?php echo htmlspecialchars(implode(', ', (array) ($workflowAutomationMetadata['require_human_checkpoint_actions'] ?? []))); ?>" <?php echo $workflowAutomationManaged ? 'disabled' : ''; ?> placeholder="send_email, call_webhook" style="width:100%;padding:.65rem;border:1px solid var(--border-color);border-radius:8px;">
                        </div>
                    </div>
                    <div style="display:flex;justify-content:flex-end;">
                        <button type="submit" class="btn-premium-primary" <?php echo $workflowAutomationManaged ? 'disabled' : ''; ?>>Save Workflow Automation Settings</button>
                    </div>
                </div>

                <div class="content-card" style="display:grid;gap:.8rem;">
                    <h3 style="margin:0;">Recent pending workflow approvals</h3>
                    <?php if (empty($workflowAutomationPendingApprovals)): ?>
                        <p style="margin:0;color:#64748b;">No workflow approvals are pending right now.</p>
                    <?php else: ?>
                        <div style="display:grid;gap:.75rem;">
                            <?php foreach (array_slice($workflowAutomationPendingApprovals, 0, 5) as $proposal): ?>
                                <a href="workflow_approvals.php?id=<?php echo (int) ($proposal['id'] ?? 0); ?>" style="display:block;padding:.9rem;border:1px solid var(--border-color);border-radius:12px;background:#fff;text-decoration:none;color:inherit;">
                                    <div style="display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;flex-wrap:wrap;">
                                        <div>
                                            <strong style="color:#0f172a;"><?php echo htmlspecialchars((string) ($proposal['target_workflow_name'] ?? 'Workflow proposal')); ?></strong>
                                            <div style="margin-top:.3rem;color:#64748b;font-size:.88rem;">
                                                <?php echo htmlspecialchars((string) ($proposal['proposal_type'] ?? 'create')); ?> proposal
                                                <?php if (!empty($proposal['risk_summary']['customer_facing'])): ?> • customer-facing<?php endif; ?>
                                                <?php if (!empty($proposal['confidence_score'])): ?> • confidence <?php echo htmlspecialchars(number_format((float) $proposal['confidence_score'], 2)); ?><?php endif; ?>
                                            </div>
                                        </div>
                                        <div style="color:#64748b;font-size:.82rem;"><?php echo htmlspecialchars((string) ($proposal['created_at'] ?? '')); ?></div>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </form>

        <?php elseif ($activeTab === 'commercial_automation'): ?>
            <?php
            $commercialAutomationConfig = $commercialAutomationConfig ?? (new CommercialAutomationConfig())->get($activeWorkspaceId > 0 ? $activeWorkspaceId : null);
            $recentCommercialRuns = (new \CRM\Services\CommercialAutomationOrchestrator())->getRecentRuns(null, null, 8);
            $commercialApprovalService = new \CRM\Services\CommercialAutomationApprovalService();
            $pendingCommercialApprovalsSummary = $commercialApprovalService->listAll(['status' => 'pending', 'limit' => 20]);
            $commercialApprovalMetrics = $commercialApprovalService->buildSummaryMetrics($pendingCommercialApprovalsSummary);
            ?>
            <form method="POST" action="" style="display:flex;flex-direction:column;gap:1.5rem;max-width:980px;">
                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                <input type="hidden" name="tab" value="commercial_automation">

                <div class="content-card" style="display:grid;gap:1rem;">
                    <div>
                        <h2 style="margin:0 0 .4rem 0;">Commercial Automation</h2>
                        <p style="margin:0;color:#64748b;">Coordinate quote, negotiation, invoice delivery, conversion, approvals, and overdue follow-up.</p>
                    </div>
                    <label style="display:flex;align-items:center;gap:.65rem;font-weight:600;">
                        <input type="checkbox" name="commercial_automation_enabled" value="1" <?php echo !empty($commercialAutomationConfig['enabled']) ? 'checked' : ''; ?> style="width:1.125rem;height:1.125rem;">
                        <span>Enable commercial automation</span>
                    </label>
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;">
                        <div>
                            <label style="display:block;margin-bottom:.4rem;font-weight:500;">Mode</label>
                            <select name="commercial_automation_mode" style="width:100%;padding:.6rem;border:1px solid rgba(0,0,0,0.1);border-radius:6px;">
                                <?php foreach (['suggest_only' => 'Suggest only', 'auto_safe' => 'Auto-safe', 'full_auto' => 'Full auto'] as $value => $label): ?>
                                    <option value="<?php echo $value; ?>" <?php echo ($commercialAutomationConfig['mode'] ?? 'auto_safe') === $value ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label style="display:block;margin-bottom:.4rem;font-weight:500;">Send delay (minutes)</label>
                            <input type="number" min="0" name="commercial_send_delay_minutes" value="<?php echo (int) ($commercialAutomationConfig['send_delay_minutes'] ?? 0); ?>" style="width:100%;padding:.6rem;border:1px solid rgba(0,0,0,0.1);border-radius:6px;">
                        </div>
                        <div>
                            <label style="display:block;margin-bottom:.4rem;font-weight:500;">Negotiation stale hours</label>
                            <input type="number" min="1" name="commercial_negotiation_stale_hours" value="<?php echo (int) ($commercialAutomationConfig['negotiation_stale_hours'] ?? 48); ?>" style="width:100%;padding:.6rem;border:1px solid rgba(0,0,0,0.1);border-radius:6px;">
                        </div>
                        <div>
                            <label style="display:block;margin-bottom:.4rem;font-weight:500;">Proposal follow-up hours</label>
                            <input type="number" min="1" name="commercial_proposal_followup_hours" value="<?php echo (int) ($commercialAutomationConfig['proposal_followup_hours'] ?? 24); ?>" style="width:100%;padding:.6rem;border:1px solid rgba(0,0,0,0.1);border-radius:6px;">
                        </div>
                    </div>
                </div>

                <div class="content-card" style="display:grid;gap:1rem;">
                    <h3 style="margin:0;">Stage actions</h3>
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;">
                        <div>
                            <label style="display:block;margin-bottom:.4rem;font-weight:500;">Proposal default</label>
                            <select name="commercial_default_doc_proposal" style="width:100%;padding:.6rem;border:1px solid rgba(0,0,0,0.1);border-radius:6px;">
                                <?php foreach (['quote' => 'Quote', 'proforma' => 'Proforma'] as $value => $label): ?>
                                    <option value="<?php echo $value; ?>" <?php echo (($commercialAutomationConfig['default_document_by_stage']['proposal'] ?? 'quote') === $value) ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label style="display:block;margin-bottom:.4rem;font-weight:500;">Negotiation default</label>
                            <select name="commercial_default_doc_negotiation" style="width:100%;padding:.6rem;border:1px solid rgba(0,0,0,0.1);border-radius:6px;">
                                <?php foreach (['quote' => 'Quote', 'proforma' => 'Proforma', 'invoice' => 'Invoice'] as $value => $label): ?>
                                    <option value="<?php echo $value; ?>" <?php echo (($commercialAutomationConfig['default_document_by_stage']['negotiation'] ?? 'quote') === $value) ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label style="display:block;margin-bottom:.4rem;font-weight:500;">Closed won default</label>
                            <select name="commercial_default_doc_closed_won" style="width:100%;padding:.6rem;border:1px solid rgba(0,0,0,0.1);border-radius:6px;">
                                <option value="invoice" selected>Invoice</option>
                            </select>
                        </div>
                        <div>
                            <label style="display:block;margin-bottom:.4rem;font-weight:500;">Convert quote status requirement</label>
                            <select name="commercial_auto_convert_requires_status" style="width:100%;padding:.6rem;border:1px solid rgba(0,0,0,0.1);border-radius:6px;">
                                <?php foreach (['accepted' => 'Accepted only', 'sent' => 'Sent or better', 'any_non_draft' => 'Any non-draft'] as $value => $label): ?>
                                    <option value="<?php echo $value; ?>" <?php echo (($commercialAutomationConfig['auto_convert_requires_status'] ?? 'accepted') === $value) ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:.75rem;">
                        <?php foreach ([
                            'commercial_stage_entry_enabled' => 'Stage-entry draft creation',
                            'commercial_negotiation_revisions_enabled' => 'Negotiation revisions',
                            'commercial_auto_send_enabled' => 'Auto-send allowed',
                            'commercial_auto_convert_on_won_enabled' => 'Auto-convert on won',
                            'commercial_auto_mark_overdue_enabled' => 'Auto-mark overdue',
                            'commercial_followup_reminders_enabled' => 'Follow-up reminders',
                            'commercial_require_recipient_for_send' => 'Require recipient before send',
                            'commercial_require_nonzero_total_for_send' => 'Require non-zero total before send',
                            'commercial_require_billing_identity_for_final_invoice' => 'Require billing identity for final invoice',
                        ] as $field => $label): ?>
                            <?php $configKey = str_replace('commercial_', '', $field); ?>
                            <label style="display:flex;align-items:center;gap:.6rem;">
                                <input type="checkbox" name="<?php echo $field; ?>" value="1" <?php echo !empty($commercialAutomationConfig[$configKey]) ? 'checked' : ''; ?>>
                                <span><?php echo htmlspecialchars($label); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="content-card" style="display:grid;gap:1rem;">
                    <h3 style="margin:0;">Thresholds and delivery</h3>
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;">
                        <div>
                            <label style="display:block;margin-bottom:.4rem;font-weight:500;">Max auto discount %</label>
                            <input type="number" step="0.01" min="0" name="commercial_max_auto_discount_percent" value="<?php echo htmlspecialchars((string) ($commercialAutomationConfig['max_auto_discount_percent'] ?? 20)); ?>" style="width:100%;padding:.6rem;border:1px solid rgba(0,0,0,0.1);border-radius:6px;">
                        </div>
                        <div>
                            <label style="display:block;margin-bottom:.4rem;font-weight:500;">Max total change %</label>
                            <input type="number" step="0.01" min="0" name="commercial_max_auto_total_change_percent" value="<?php echo htmlspecialchars((string) ($commercialAutomationConfig['max_auto_total_change_percent'] ?? 25)); ?>" style="width:100%;padding:.6rem;border:1px solid rgba(0,0,0,0.1);border-radius:6px;">
                        </div>
                        <div>
                            <label style="display:block;margin-bottom:.4rem;font-weight:500;">Max revision count before approval</label>
                            <input type="number" min="0" name="commercial_max_revision_count_before_approval" value="<?php echo (int) ($commercialAutomationConfig['max_revision_count_before_approval'] ?? 2); ?>" style="width:100%;padding:.6rem;border:1px solid rgba(0,0,0,0.1);border-radius:6px;">
                        </div>
                        <div>
                            <label style="display:block;margin-bottom:.4rem;font-weight:500;">Task owner mode</label>
                            <select name="commercial_task_owner_mode" style="width:100%;padding:.6rem;border:1px solid rgba(0,0,0,0.1);border-radius:6px;">
                                <?php foreach (['deal_owner' => 'Deal owner', 'contact_owner' => 'Contact owner', 'round_robin' => 'Round robin'] as $value => $label): ?>
                                    <option value="<?php echo $value; ?>" <?php echo (($commercialAutomationConfig['task_owner_mode'] ?? 'deal_owner') === $value) ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label style="display:block;margin-bottom:.4rem;font-weight:500;">Delivery retry limit</label>
                            <input type="number" min="0" name="commercial_delivery_retry_limit" value="<?php echo (int) ($commercialAutomationConfig['delivery_retry_limit'] ?? 2); ?>" style="width:100%;padding:.6rem;border:1px solid rgba(0,0,0,0.1);border-radius:6px;">
                        </div>
                        <div>
                            <label style="display:block;margin-bottom:.4rem;font-weight:500;">Retry backoff (minutes)</label>
                            <input type="number" min="0" name="commercial_delivery_retry_backoff_minutes" value="<?php echo (int) ($commercialAutomationConfig['delivery_retry_backoff_minutes'] ?? 30); ?>" style="width:100%;padding:.6rem;border:1px solid rgba(0,0,0,0.1);border-radius:6px;">
                        </div>
                        <div>
                            <label style="display:block;margin-bottom:.4rem;font-weight:500;">Resend cooldown (minutes)</label>
                            <input type="number" min="0" name="commercial_resend_cooldown_minutes" value="<?php echo (int) ($commercialAutomationConfig['resend_cooldown_minutes'] ?? 180); ?>" style="width:100%;padding:.6rem;border:1px solid rgba(0,0,0,0.1);border-radius:6px;">
                        </div>
                    </div>
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;">
                        <div>
                            <label style="display:block;margin-bottom:.4rem;font-weight:500;">Create draft confidence</label>
                            <input type="number" min="0" max="1" step="0.01" name="commercial_threshold_create_draft" value="<?php echo htmlspecialchars((string) (($commercialAutomationConfig['action_confidence_thresholds']['create_draft'] ?? 0.82))); ?>" style="width:100%;padding:.6rem;border:1px solid rgba(0,0,0,0.1);border-radius:6px;">
                        </div>
                        <div>
                            <label style="display:block;margin-bottom:.4rem;font-weight:500;">Revise negotiation confidence</label>
                            <input type="number" min="0" max="1" step="0.01" name="commercial_threshold_revise_document" value="<?php echo htmlspecialchars((string) (($commercialAutomationConfig['action_confidence_thresholds']['revise_document'] ?? 0.86))); ?>" style="width:100%;padding:.6rem;border:1px solid rgba(0,0,0,0.1);border-radius:6px;">
                        </div>
                        <div>
                            <label style="display:block;margin-bottom:.4rem;font-weight:500;">First send confidence</label>
                            <input type="number" min="0" max="1" step="0.01" name="commercial_threshold_send_document" value="<?php echo htmlspecialchars((string) (($commercialAutomationConfig['action_confidence_thresholds']['send_document'] ?? 0.88))); ?>" style="width:100%;padding:.6rem;border:1px solid rgba(0,0,0,0.1);border-radius:6px;">
                        </div>
                        <div>
                            <label style="display:block;margin-bottom:.4rem;font-weight:500;">Resend confidence</label>
                            <input type="number" min="0" max="1" step="0.01" name="commercial_threshold_resend_document" value="<?php echo htmlspecialchars((string) (($commercialAutomationConfig['action_confidence_thresholds']['resend_document'] ?? 0.90))); ?>" style="width:100%;padding:.6rem;border:1px solid rgba(0,0,0,0.1);border-radius:6px;">
                        </div>
                        <div>
                            <label style="display:block;margin-bottom:.4rem;font-weight:500;">Convert to invoice confidence</label>
                            <input type="number" min="0" max="1" step="0.01" name="commercial_threshold_convert_to_invoice" value="<?php echo htmlspecialchars((string) (($commercialAutomationConfig['action_confidence_thresholds']['convert_to_invoice'] ?? 0.90))); ?>" style="width:100%;padding:.6rem;border:1px solid rgba(0,0,0,0.1);border-radius:6px;">
                        </div>
                        <div>
                            <label style="display:block;margin-bottom:.4rem;font-weight:500;">Finalize invoice confidence</label>
                            <input type="number" min="0" max="1" step="0.01" name="commercial_threshold_finalize_invoice" value="<?php echo htmlspecialchars((string) (($commercialAutomationConfig['action_confidence_thresholds']['finalize_invoice'] ?? 0.93))); ?>" style="width:100%;padding:.6rem;border:1px solid rgba(0,0,0,0.1);border-radius:6px;">
                        </div>
                        <div>
                            <label style="display:block;margin-bottom:.4rem;font-weight:500;">Mark paid confidence</label>
                            <input type="number" min="0" max="1" step="0.01" name="commercial_threshold_mark_paid" value="<?php echo htmlspecialchars((string) (($commercialAutomationConfig['action_confidence_thresholds']['mark_paid'] ?? 0.96))); ?>" style="width:100%;padding:.6rem;border:1px solid rgba(0,0,0,0.1);border-radius:6px;">
                        </div>
                    </div>
                    <div style="display:flex;gap:1rem;flex-wrap:wrap;">
                        <label style="display:flex;align-items:center;gap:.6rem;">
                            <input type="checkbox" name="commercial_send_channel_email" value="1" <?php echo !empty($commercialAutomationConfig['send_channels']['email']) ? 'checked' : ''; ?>>
                            <span>Email delivery</span>
                        </label>
                        <label style="display:flex;align-items:center;gap:.6rem;">
                            <input type="checkbox" name="commercial_send_channel_whatsapp" value="1" <?php echo !empty($commercialAutomationConfig['send_channels']['whatsapp']) ? 'checked' : ''; ?>>
                            <span>WhatsApp delivery</span>
                        </label>
                    </div>
                </div>

                <div class="content-card" style="display:grid;gap:1rem;">
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;flex-wrap:wrap;">
                        <div>
                            <h3 style="margin:0;">Recent automation runs</h3>
                            <p style="margin:.35rem 0 0 0;color:#64748b;font-size:.875rem;">Latest orchestration decisions across draft, revision, sending, conversion, and overdue handling.</p>
                        </div>
                        <div style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:center;">
                            <a href="ai_automation_diagnostics.php?source=commercial" class="btn-premium-secondary">Open diagnostics</a>
                            <a href="commercial_approvals.php?status=pending" class="btn-premium-secondary">Open approvals workbench</a>
                            <button type="submit" class="btn-premium-primary">Save Commercial Automation</button>
                        </div>
                    </div>
                    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.75rem;">
                        <div style="padding:.9rem 1rem;border:1px solid var(--border-color);border-radius:10px;background:#fcfcfd;">
                            <div style="font-size:12px;color:#64748b;text-transform:uppercase;">Pending approvals</div>
                            <div style="margin-top:.35rem;font-size:24px;font-weight:700;color:#0f172a;"><?php echo (int) ($commercialApprovalMetrics['pending_count'] ?? 0); ?></div>
                        </div>
                        <div style="padding:.9rem 1rem;border:1px solid var(--border-color);border-radius:10px;background:#fcfcfd;">
                            <div style="font-size:12px;color:#64748b;text-transform:uppercase;">Assistant-origin</div>
                            <div style="margin-top:.35rem;font-size:24px;font-weight:700;color:#0f172a;"><?php echo (int) ($commercialApprovalMetrics['assistant_origin_count'] ?? 0); ?></div>
                        </div>
                        <div style="padding:.9rem 1rem;border:1px solid var(--border-color);border-radius:10px;background:#fcfcfd;">
                            <div style="font-size:12px;color:#64748b;text-transform:uppercase;">Customer-send approvals</div>
                            <div style="margin-top:.35rem;font-size:24px;font-weight:700;color:#0f172a;"><?php echo (int) ($commercialApprovalMetrics['customer_send_count'] ?? 0); ?></div>
                        </div>
                        <div style="padding:.9rem 1rem;border:1px solid var(--border-color);border-radius:10px;background:#fcfcfd;">
                            <div style="font-size:12px;color:#64748b;text-transform:uppercase;">Top reason</div>
                            <div style="margin-top:.35rem;font-size:18px;font-weight:700;color:#0f172a;"><?php echo htmlspecialchars((string) ($commercialApprovalMetrics['top_reason_category'] ?? 'none')); ?></div>
                        </div>
                    </div>
                    <?php if (!empty($pendingCommercialApprovalsSummary)): ?>
                        <div style="display:grid;gap:.75rem;">
                            <?php foreach (array_slice($pendingCommercialApprovalsSummary, 0, 3) as $approval): ?>
                                <a href="commercial_approvals.php?id=<?php echo (int) $approval['id']; ?>" style="display:block;padding:.9rem 1rem;border:1px solid #fdba74;border-radius:10px;background:#fff7ed;text-decoration:none;color:inherit;">
                                    <div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
                                        <strong style="color:#9a3412;"><?php echo htmlspecialchars((string) ($approval['action_key'] ?? 'approval')); ?></strong>
                                        <span style="color:#9a3412;font-size:.85rem;"><?php echo htmlspecialchars((string) ($approval['created_at'] ?? '')); ?></span>
                                    </div>
                                    <div style="margin-top:.35rem;color:#9a3412;"><?php echo htmlspecialchars((string) ($approval['reason'] ?? 'Approval required')); ?></div>
                                    <div style="margin-top:.35rem;color:#7c2d12;font-size:.85rem;"><?php echo htmlspecialchars((string) ($approval['preview']['summary'] ?? '')); ?></div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <?php if (empty($recentCommercialRuns)): ?>
                        <p style="margin:0;color:#64748b;">No commercial automation runs yet.</p>
                    <?php else: ?>
                        <div style="display:grid;gap:.75rem;">
                            <?php foreach ($recentCommercialRuns as $run): ?>
                                <div style="padding:.9rem 1rem;border:1px solid var(--border-color);border-radius:10px;background:#fcfcfd;">
                                    <div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
                                        <strong><?php echo htmlspecialchars(str_replace('_', ' ', (string) ($run['trigger_type'] ?? 'manual'))); ?></strong>
                                        <span style="color:#475569;"><?php echo htmlspecialchars((string) ($run['decision'] ?? 'reject')); ?></span>
                                    </div>
                                    <div style="margin-top:.3rem;color:#64748b;font-size:.85rem;">Deal #<?php echo (int) ($run['deal_id'] ?? 0); ?><?php if (!empty($run['invoice_id'])): ?> • Invoice #<?php echo (int) $run['invoice_id']; ?><?php endif; ?> • <?php echo htmlspecialchars((string) ($run['created_at'] ?? '')); ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </form>
        <?php elseif ($activeTab === 'monitoring'): ?>
            <form method="POST" action="" style="display: flex; flex-direction: column; gap: var(--spacing-lg); max-width: 600px;">
                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                <input type="hidden" name="tab" value="monitoring">
                
                <div class="content-card" style="margin-bottom: 2rem; background: #f8fafc;">
                    <h3 style="color: #0f172a; margin-bottom: 1rem; font-size: 1.125rem; font-weight: 600;">External Alert Delivery</h3>
                    <p style="color: #64748b; margin-bottom: 1rem; font-size: 0.875rem;">
                        When high or critical alerts are created (e.g. database issues, disk space, queue backlog), admins can be notified via email and Slack.
                    </p>
                    
                    <div style="margin-bottom: 1.5rem;">
                        <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                            <input 
                                type="checkbox" 
                                id="alert_email_admins" 
                                name="alert_email_admins" 
                                value="1" 
                                <?php echo ($_ENV['ALERT_EMAIL_ADMINS'] ?? 'true') === 'true' ? 'checked' : ''; ?>
                                style="width: 1.125rem; height: 1.125rem;"
                            >
                            <span style="color: #0f172a; font-weight: 500; font-size: 0.875rem;">Email admins on high/critical alerts</span>
                        </label>
                        <small style="color: #64748b; font-size: 0.75rem; display: block; margin-top: 0.25rem; margin-left: 1.75rem;">
                            Sends email to all admin users when system alerts are created with high or critical severity. Requires SMTP to be configured.
                        </small>
                    </div>
                    
                    <div>
                        <label for="alert_slack_webhook_url" style="display: block; margin-bottom: 0.5rem; color: #0f172a; font-weight: 500; font-size: 0.875rem;">Slack Webhook URL (optional)</label>
                        <input 
                            type="url" 
                            id="alert_slack_webhook_url" 
                            name="alert_slack_webhook_url" 
                            value="<?php echo htmlspecialchars($_ENV['ALERT_SLACK_WEBHOOK_URL'] ?? ''); ?>"
                            placeholder="https://hooks.slack.com/services/..."
                            style="width: 100%; padding: 0.5rem; border: 1px solid rgba(0, 0, 0, 0.1); border-radius: var(--border-radius-sm); font-size: 0.875rem;"
                        >
                        <small style="color: #64748b; font-size: 0.75rem; display: block; margin-top: 0.25rem;">
                            Critical alerts only. Create an Incoming Webhook in Slack to get the URL.
                        </small>
                    </div>
                </div>
                
                <div class="content-card" style="margin-bottom: 2rem; background: #f8fafc;">
                    <h3 style="color: #0f172a; margin-bottom: 1rem; font-size: 1.125rem; font-weight: 600;">Alert Checker Cron</h3>
                    <p style="color: #64748b; margin-bottom: 1rem; font-size: 0.875rem;">
                        Run <code>php cli/alert_checker.php</code> periodically (e.g. every 15 minutes) to check system health and create alerts. Add to crontab:
                    </p>
                    <pre style="background: #1e293b; color: #e2e8f0; padding: 1rem; border-radius: 8px; font-size: 0.8125rem; overflow-x: auto;">*/15 * * * * cd /path/to/crm && php cli/alert_checker.php >> /var/log/crm/alert_checker.log 2>&1</pre>
                </div>
                
                <button type="submit" style="background: var(--accent-blue); color: white; padding: var(--spacing-sm) var(--spacing-lg); border: none; border-radius: 4px; font-weight: 500; cursor: pointer; align-self: flex-start;">
                    Save Settings
                </button>
            </form>

        <?php elseif ($activeTab === 'deal_automation'): ?>
            <?php
            $dealAutomationConfig = (new \CRM\Modules\DealAutomationConfig())->get($activeWorkspaceId > 0 ? $activeWorkspaceId : null);
            $stageLabels = [
                'prospecting' => 'Prospecting',
                'qualification' => 'Qualification',
                'proposal' => 'Proposal',
                'negotiation' => 'Negotiation',
                'closed_won' => 'Closed Won',
                'closed_lost' => 'Closed Lost',
                'any_open' => 'Any Open Stage',
            ];
            $describeRule = function (array $rule, array $globals = []): string {
                $type = $rule['type'] ?? 'unknown';
                $lookbackDays = (int) ($globals['lookback_days'] ?? 14);
                switch ($type) {
                    case 'intent_count':
                        $intent = $rule['intent'] ?? 'intent';
                        $min = (int) ($rule['min'] ?? 1);
                        $days = (int) ($rule['window_days'] ?? 14);
                        $effectiveDays = max(1, min($days, $lookbackDays));
                        return "At least {$min} {$intent} message(s) in the last {$effectiveDays} day(s).";
                    case 'proposal_sent':
                        return 'A proposal/quote was sent to the contact.';
                    case 'lead_score_min':
                        $value = (int) ($rule['value'] ?? 0);
                        return "Lead score is at least {$value}.";
                    case 'bidirectional_exchange':
                        $minMessages = (int) ($rule['min_messages'] ?? 2);
                        $days = (int) ($rule['window_days'] ?? 14);
                        $effectiveDays = max(1, min($days, $lookbackDays));
                        return "Two-way conversation exists with at least {$minMessages} message(s) in {$effectiveDays} day(s).";
                    case 'explicit_acceptance':
                        return 'Customer gave explicit acceptance evidence.';
                    case 'explicit_rejection':
                        return 'Customer gave explicit rejection evidence.';
                    case 'inactivity_timeout':
                        $days = (int) ($rule['days'] ?? 14);
                        return "No activity for at least {$days} day(s).";
                    case 'no_negative_trend':
                        return 'No negative sentiment trend detected.';
                    case 'no_negative_in_window':
                        $days = (int) ($rule['window_days'] ?? 7);
                        $effectiveDays = max(1, min($days, $lookbackDays));
                        return "No negative signals in the last {$effectiveDays} day(s).";
                    case 'no_positive_in_window':
                        $days = (int) ($rule['window_days'] ?? 14);
                        $effectiveDays = max(1, min($days, $lookbackDays));
                        return "No positive signals in the last {$effectiveDays} day(s).";
                    default:
                        return 'Custom rule is enabled for this transition.';
                }
            };
            ?>
            <form method="POST" action="" style="display: flex; flex-direction: column; gap: var(--spacing-lg); max-width: 800px;">
                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                <input type="hidden" name="tab" value="deal_automation">
                <input type="hidden" name="deal_automation_transitions" value="<?php echo htmlspecialchars(json_encode(['transitions' => $dealAutomationConfig['transitions']])); ?>">

                <div class="content-card" style="margin-bottom: 2rem; background: #f8fafc;">
                    <h3 style="color: #0f172a; margin-bottom: 1rem; font-size: 1.125rem; font-weight: 600;">Deal Automation (AI)</h3>
                    <p style="color: #64748b; margin-bottom: 1rem; font-size: 0.875rem;">
                        Automatically suggest or apply deal stage changes based on communication intent, sentiment, proposal events, and the composite lead score. Phase 3: auto-close only when strict evidence checklist passes.
                    </p>
                    <div style="background: #ffffff; border: 1px solid #dbeafe; border-radius: 8px; padding: 0.875rem; margin-bottom: 1rem;">
                        <div style="font-weight: 600; color: #1e3a8a; margin-bottom: 0.375rem; font-size: 0.875rem;">Quick start guide</div>
                        <ol style="margin: 0; padding-left: 1rem; color: #334155; font-size: 0.8125rem; line-height: 1.6;">
                            <li>Start with <strong>Suggest only</strong> for safe monitoring.</li>
                            <li>Review suggestions and automation audit logs for a few days.</li>
                            <li>Move to <strong>Auto-safe</strong> when behavior looks reliable.</li>
                        </ol>
                    </div>
                    <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 0.875rem; margin-bottom: 1rem;">
                        <div style="font-weight: 600; color: #0f172a; margin-bottom: 0.375rem; font-size: 0.875rem;">Recommended setup profiles</div>
                        <ul style="margin: 0; padding-left: 1rem; color: #334155; font-size: 0.8125rem; line-height: 1.6;">
                            <li><strong>Conservative:</strong> Suggest only, min confidence 0.90+, terminal approval ON.</li>
                            <li><strong>Balanced:</strong> Auto-safe, min confidence around 0.85, cooldown 24h.</li>
                            <li><strong>Aggressive:</strong> Full auto, confidence 0.80-0.85, only after proven stability.</li>
                        </ul>
                    </div>
                    <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; margin-bottom: 1rem;" title="Master switch for this feature. Turn this on to allow AI to suggest or apply stage changes.">
                        <input type="checkbox" name="deal_automation_enabled" value="1" <?php echo $dealAutomationConfig['enabled'] ? 'checked' : ''; ?> style="width: 1.125rem; height: 1.125rem;">
                        <span style="font-weight: 500;">Enable AI deal automation <span title="Master switch for automation." style="display: inline-flex; justify-content: center; align-items: center; width: 1rem; height: 1rem; border: 1px solid #cbd5e1; border-radius: 999px; color: #475569; font-size: 0.6875rem; margin-left: 0.25rem;">?</span></span>
                    </label>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div>
                            <label for="deal_automation_mode" style="display: block; margin-bottom: 0.5rem; font-weight: 500; font-size: 0.875rem;" title="How much authority AI has: Suggest only (safest), Auto-safe (auto updates non-terminal stages), Full auto (all stages with strict checks).">Mode <span title="How much AI is allowed to do automatically." style="display: inline-flex; justify-content: center; align-items: center; width: 1rem; height: 1rem; border: 1px solid #cbd5e1; border-radius: 999px; color: #475569; font-size: 0.6875rem; margin-left: 0.25rem;">?</span></label>
                            <select id="deal_automation_mode" name="deal_automation_mode" style="width: 100%; padding: 0.5rem; border: 1px solid rgba(0,0,0,0.1); border-radius: 4px;">
                                <option value="suggest_only" <?php echo ($dealAutomationConfig['mode'] ?? '') === 'suggest_only' ? 'selected' : ''; ?>>Suggest only</option>
                                <option value="auto_safe" <?php echo ($dealAutomationConfig['mode'] ?? '') === 'auto_safe' ? 'selected' : ''; ?>>Auto-safe (non-terminal)</option>
                                <option value="full_auto" <?php echo ($dealAutomationConfig['mode'] ?? '') === 'full_auto' ? 'selected' : ''; ?>>Full auto (strict checklist)</option>
                            </select>
                            <p style="margin: 0.35rem 0 0; color: #64748b; font-size: 0.75rem;">Use Suggest only during rollout, then graduate to Auto-safe, and only then Full auto.</p>
                        </div>
                        <div>
                            <label for="deal_automation_min_confidence" style="display: block; margin-bottom: 0.5rem; font-weight: 500; font-size: 0.875rem;" title="Global confidence floor for AI decisions. Higher means safer but fewer automatic updates.">Min confidence (0–1) <span title="Higher value = stricter automation." style="display: inline-flex; justify-content: center; align-items: center; width: 1rem; height: 1rem; border: 1px solid #cbd5e1; border-radius: 999px; color: #475569; font-size: 0.6875rem; margin-left: 0.25rem;">?</span></label>
                            <input type="number" id="deal_automation_min_confidence" name="deal_automation_min_confidence" step="0.01" min="0" max="1" value="<?php echo htmlspecialchars((string)($dealAutomationConfig['min_confidence'] ?? 0.85)); ?>" style="width: 100%; padding: 0.5rem; border: 1px solid rgba(0,0,0,0.1); border-radius: 4px;">
                            <p style="margin: 0.35rem 0 0; color: #64748b; font-size: 0.75rem;">Suggested range: 0.82-0.90. Increase this value if you see poor suggestions.</p>
                        </div>
                        <div>
                            <label for="deal_automation_lookback_days" style="display: block; margin-bottom: 0.5rem; font-weight: 500; font-size: 0.875rem;" title="How far back the system checks communications and signals when evaluating a stage change.">Lookback days <span title="Longer window uses more historical evidence." style="display: inline-flex; justify-content: center; align-items: center; width: 1rem; height: 1rem; border: 1px solid #cbd5e1; border-radius: 999px; color: #475569; font-size: 0.6875rem; margin-left: 0.25rem;">?</span></label>
                            <input type="number" id="deal_automation_lookback_days" name="deal_automation_lookback_days" min="1" max="90" value="<?php echo (int)($dealAutomationConfig['lookback_days'] ?? 14); ?>" style="width: 100%; padding: 0.5rem; border: 1px solid rgba(0,0,0,0.1); border-radius: 4px;">
                            <p style="margin: 0.35rem 0 0; color: #64748b; font-size: 0.75rem;">Use 7-21 days for most teams. Longer windows capture more context but react slower.</p>
                        </div>
                        <div>
                            <label for="deal_automation_cooldown_hours" style="display: block; margin-bottom: 0.5rem; font-weight: 500; font-size: 0.875rem;" title="Minimum time between automated stage changes for the same deal. Prevents rapid stage flipping.">Cooldown (hours) <span title="Prevents repeated/rapid changes." style="display: inline-flex; justify-content: center; align-items: center; width: 1rem; height: 1rem; border: 1px solid #cbd5e1; border-radius: 999px; color: #475569; font-size: 0.6875rem; margin-left: 0.25rem;">?</span></label>
                            <input type="number" id="deal_automation_cooldown_hours" name="deal_automation_cooldown_hours" min="0" max="168" value="<?php echo (int)($dealAutomationConfig['cooldown_hours'] ?? 24); ?>" style="width: 100%; padding: 0.5rem; border: 1px solid rgba(0,0,0,0.1); border-radius: 4px;">
                            <p style="margin: 0.35rem 0 0; color: #64748b; font-size: 0.75rem;">24 hours is a good default to avoid stage “ping-pong”.</p>
                        </div>
                        <div>
                            <label for="deal_automation_min_terminal_confidence" style="display: block; margin-bottom: 0.5rem; font-weight: 500; font-size: 0.875rem;" title="Extra strict confidence requirement for final stages (Closed Won / Closed Lost).">Min terminal confidence (won/lost) <span title="Higher is safer for closing decisions." style="display: inline-flex; justify-content: center; align-items: center; width: 1rem; height: 1rem; border: 1px solid #cbd5e1; border-radius: 999px; color: #475569; font-size: 0.6875rem; margin-left: 0.25rem;">?</span></label>
                            <input type="number" id="deal_automation_min_terminal_confidence" name="deal_automation_min_terminal_confidence" step="0.01" min="0" max="1" value="<?php echo htmlspecialchars((string)($dealAutomationConfig['min_terminal_confidence'] ?? 0.92)); ?>" style="width: 100%; padding: 0.5rem; border: 1px solid rgba(0,0,0,0.1); border-radius: 4px;">
                            <p style="margin: 0.35rem 0 0; color: #64748b; font-size: 0.75rem;">Keep this high (0.90+) to reduce accidental won/lost updates.</p>
                        </div>
                        <div>
                            <label for="deal_automation_inactivity_days" style="display: block; margin-bottom: 0.5rem; font-weight: 500; font-size: 0.875rem;" title="Number of inactive days before AI can consider a deal as potentially lost.">Inactivity days for loss <span title="Used by inactivity sweep for lost recommendations." style="display: inline-flex; justify-content: center; align-items: center; width: 1rem; height: 1rem; border: 1px solid #cbd5e1; border-radius: 999px; color: #475569; font-size: 0.6875rem; margin-left: 0.25rem;">?</span></label>
                            <input type="number" id="deal_automation_inactivity_days" name="deal_automation_inactivity_days" min="1" max="90" value="<?php echo (int)($dealAutomationConfig['inactivity_days_for_loss'] ?? 14); ?>" style="width: 100%; padding: 0.5rem; border: 1px solid rgba(0,0,0,0.1); border-radius: 4px;">
                            <p style="margin: 0.35rem 0 0; color: #64748b; font-size: 0.75rem;">Typical value is 14-30 days depending on your sales cycle length.</p>
                        </div>
                    </div>
                    <div style="display: flex; flex-wrap: wrap; gap: 1rem; margin-top: 1rem;">
                        <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;" title="Keep final stage moves (Won/Lost) human-approved for safety.">
                            <input type="checkbox" name="deal_automation_require_approval_terminal" value="1" <?php echo ($dealAutomationConfig['require_approval_terminal'] ?? true) ? 'checked' : ''; ?> style="width: 1.125rem; height: 1.125rem;">
                            <span>Require approval for terminal stages (won/lost)</span>
                        </label>
                        <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;" title="Allow skipping intermediate stages when confidence and rules support it.">
                            <input type="checkbox" name="deal_automation_allow_multi_stage_jump" value="1" <?php echo ($dealAutomationConfig['allow_multi_stage_jump'] ?? false) ? 'checked' : ''; ?> style="width: 1.125rem; height: 1.125rem;">
                            <span>Allow multi-stage jump</span>
                        </label>
                        <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;" title="Run full evaluations and logs without actually changing deal stages.">
                            <input type="checkbox" name="deal_automation_dry_run" value="1" <?php echo ($dealAutomationConfig['dry_run'] ?? false) ? 'checked' : ''; ?> style="width: 1.125rem; height: 1.125rem;">
                            <span>Dry run (log only, no apply)</span>
                        </label>
                        <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;" title="If a customer with a previously lost deal re-engages, allow the system to reopen that last lost deal.">
                            <input type="checkbox" name="deal_automation_reopen_lost_on_reengagement" value="1" <?php echo ($dealAutomationConfig['reopen_lost_on_reengagement'] ?? false) ? 'checked' : ''; ?> style="width: 1.125rem; height: 1.125rem;">
                            <span>Reopen last lost deal if customer re-engages</span>
                        </label>
                    </div>
                    <div style="margin-top: 1rem; padding: 0.875rem; border: 1px solid #e2e8f0; border-radius: 8px; background: #ffffff;">
                        <div style="font-weight: 600; color: #0f172a; margin-bottom: 0.5rem; font-size: 0.875rem;">Auto-create Prospecting Deal from Inbound Replies</div>
                        <p style="margin: 0 0 0.75rem; color: #64748b; font-size: 0.75rem;">
                            Optional: when a contact replies to outreach with a non-negative and meaningful response, automatically open a new deal in <strong>Prospecting</strong> if no open deal exists.
                        </p>
                        <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer; margin-bottom: 0.75rem;">
                            <input type="checkbox" name="deal_auto_create_from_inbound" value="1" <?php echo !empty($dealAutomationConfig['auto_create_from_inbound']) ? 'checked' : ''; ?> style="width: 1.125rem; height: 1.125rem;">
                            <span>Enable auto-create from inbound reply</span>
                        </label>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; margin-bottom: 0.75rem;">
                            <div>
                                <label for="deal_auto_create_min_chars" style="display: block; margin-bottom: 0.35rem; font-size: 0.8125rem; font-weight: 500;">Minimum message characters</label>
                                <input type="number" id="deal_auto_create_min_chars" name="deal_auto_create_min_chars" min="1" max="1000" value="<?php echo (int) ($dealAutomationConfig['auto_create_min_message_chars'] ?? 20); ?>" style="width: 100%; padding: 0.45rem; border: 1px solid rgba(0,0,0,0.1); border-radius: 4px;">
                            </div>
                            <div>
                                <label for="deal_auto_create_dedupe_hours" style="display: block; margin-bottom: 0.35rem; font-size: 0.8125rem; font-weight: 500;">Dedupe window (hours)</label>
                                <input type="number" id="deal_auto_create_dedupe_hours" name="deal_auto_create_dedupe_hours" min="1" max="168" value="<?php echo (int) ($dealAutomationConfig['auto_create_dedupe_hours'] ?? 24); ?>" style="width: 100%; padding: 0.45rem; border: 1px solid rgba(0,0,0,0.1); border-radius: 4px;">
                            </div>
                        </div>
                        <div style="display: flex; flex-wrap: wrap; gap: 1rem; margin-bottom: 0.75rem;">
                            <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                                <input type="checkbox" name="deal_auto_create_require_non_negative" value="1" <?php echo !empty($dealAutomationConfig['auto_create_require_non_negative']) ? 'checked' : ''; ?> style="width: 1.125rem; height: 1.125rem;">
                                <span>Require non-negative sentiment</span>
                            </label>
                            <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                                <input type="checkbox" name="deal_auto_create_require_meaningful" value="1" <?php echo !empty($dealAutomationConfig['auto_create_require_meaningful_reply']) ? 'checked' : ''; ?> style="width: 1.125rem; height: 1.125rem;">
                                <span>Require meaningful reply signal</span>
                            </label>
                        </div>
                        <div style="display: flex; flex-wrap: wrap; gap: 1rem;">
                            <?php $autoCreateChannels = $dealAutomationConfig['auto_create_channels'] ?? ['email' => true, 'whatsapp' => true, 'sms' => false]; ?>
                            <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                                <input type="checkbox" name="deal_auto_create_channel_email" value="1" <?php echo !empty($autoCreateChannels['email']) ? 'checked' : ''; ?> style="width: 1.125rem; height: 1.125rem;">
                                <span>Email</span>
                            </label>
                            <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                                <input type="checkbox" name="deal_auto_create_channel_whatsapp" value="1" <?php echo !empty($autoCreateChannels['whatsapp']) ? 'checked' : ''; ?> style="width: 1.125rem; height: 1.125rem;">
                                <span>WhatsApp</span>
                            </label>
                            <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                                <input type="checkbox" name="deal_auto_create_channel_sms" value="1" <?php echo !empty($autoCreateChannels['sms']) ? 'checked' : ''; ?> style="width: 1.125rem; height: 1.125rem;">
                                <span>SMS</span>
                            </label>
                        </div>
                    </div>
                    <div style="margin-top: 1rem; padding: 0.875rem; border: 1px solid #e2e8f0; border-radius: 8px; background: #ffffff;">
                        <div style="font-weight: 600; color: #0f172a; margin-bottom: 0.5rem; font-size: 0.875rem;">Global Inbox Triage (Email + WhatsApp)</div>
                        <p style="margin: 0 0 0.75rem; color: #64748b; font-size: 0.75rem;">
                            System-wide automation. Scores inbound messages, sets priority, assigns owners, and creates follow-up tasks with guardrails for all eligible users and channels.
                        </p>
                        <div style="display: flex; flex-wrap: wrap; gap: 1rem; margin-bottom: 0.75rem;">
                            <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                                <input type="checkbox" name="inbox_triage_enabled" value="1" <?php echo !empty($dealAutomationConfig['inbox_triage_enabled']) ? 'checked' : ''; ?> style="width: 1.125rem; height: 1.125rem;">
                                <span>Enable triage engine globally</span>
                            </label>
                            <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                                <input type="checkbox" name="inbox_triage_auto_apply" value="1" <?php echo !empty($dealAutomationConfig['inbox_triage_auto_apply']) ? 'checked' : ''; ?> style="width: 1.125rem; height: 1.125rem;">
                                <span>Auto-apply owner + task globally</span>
                            </label>
                        </div>
                        <p style="margin: 0 0 0.75rem; color: #64748b; font-size: 0.75rem;">
                            This is different from <strong>My Inbox Triage Opt-out</strong> on the General tab. That personal preference only stops auto-application for one user and does not disable the system-wide triage engine.
                        </p>
                        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 0.75rem; margin-bottom: 0.75rem;">
                            <div>
                                <label for="inbox_triage_min_confidence" style="display: block; margin-bottom: 0.35rem; font-size: 0.8125rem; font-weight: 500;">Min confidence</label>
                                <input type="number" id="inbox_triage_min_confidence" name="inbox_triage_min_confidence" min="0" max="1" step="0.01" value="<?php echo htmlspecialchars((string) ($dealAutomationConfig['inbox_triage_min_confidence'] ?? 0.80)); ?>" style="width: 100%; padding: 0.45rem; border: 1px solid rgba(0,0,0,0.1); border-radius: 4px;">
                            </div>
                            <div>
                                <label for="inbox_triage_task_due_hours" style="display: block; margin-bottom: 0.35rem; font-size: 0.8125rem; font-weight: 500;">Task due (hours)</label>
                                <input type="number" id="inbox_triage_task_due_hours" name="inbox_triage_task_due_hours" min="1" max="168" value="<?php echo (int) ($dealAutomationConfig['inbox_triage_task_due_hours'] ?? 24); ?>" style="width: 100%; padding: 0.45rem; border: 1px solid rgba(0,0,0,0.1); border-radius: 4px;">
                            </div>
                            <div>
                                <label for="inbox_triage_dedupe_hours" style="display: block; margin-bottom: 0.35rem; font-size: 0.8125rem; font-weight: 500;">Dedupe window (hours)</label>
                                <input type="number" id="inbox_triage_dedupe_hours" name="inbox_triage_dedupe_hours" min="1" max="168" value="<?php echo (int) ($dealAutomationConfig['inbox_triage_dedupe_hours'] ?? 24); ?>" style="width: 100%; padding: 0.45rem; border: 1px solid rgba(0,0,0,0.1); border-radius: 4px;">
                            </div>
                        </div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; margin-bottom: 0.75rem;">
                            <div>
                                <label for="inbox_triage_owner_fallback" style="display: block; margin-bottom: 0.35rem; font-size: 0.8125rem; font-weight: 500;">Owner fallback</label>
                                <select id="inbox_triage_owner_fallback" name="inbox_triage_owner_fallback" style="width: 100%; padding: 0.45rem; border: 1px solid rgba(0,0,0,0.1); border-radius: 4px;">
                                    <option value="round_robin" <?php echo (($dealAutomationConfig['inbox_triage_owner_fallback'] ?? 'round_robin') === 'round_robin') ? 'selected' : ''; ?>>Round robin</option>
                                </select>
                            </div>
                            <div>
                                <?php $triageChannels = $dealAutomationConfig['inbox_triage_channels'] ?? ['email' => true, 'whatsapp' => true]; ?>
                                <label style="display: block; margin-bottom: 0.35rem; font-size: 0.8125rem; font-weight: 500;">Channels</label>
                                <div style="display: flex; gap: 1rem; padding-top: 0.35rem;">
                                    <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                                        <input type="checkbox" name="inbox_triage_channel_email" value="1" <?php echo !empty($triageChannels['email']) ? 'checked' : ''; ?> style="width: 1.125rem; height: 1.125rem;">
                                        <span>Email</span>
                                    </label>
                                    <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                                        <input type="checkbox" name="inbox_triage_channel_whatsapp" value="1" <?php echo !empty($triageChannels['whatsapp']) ? 'checked' : ''; ?> style="width: 1.125rem; height: 1.125rem;">
                                        <span>WhatsApp</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                            <div>
                                <label for="inbox_triage_negative_phrase_blocklist" style="display: block; margin-bottom: 0.35rem; font-size: 0.8125rem; font-weight: 500;">Negative phrase blocklist</label>
                                <textarea id="inbox_triage_negative_phrase_blocklist" name="inbox_triage_negative_phrase_blocklist" rows="4" style="width: 100%; padding: 0.45rem; border: 1px solid rgba(0,0,0,0.1); border-radius: 4px;"><?php echo htmlspecialchars((string) ($dealAutomationConfig['inbox_triage_negative_phrase_blocklist'] ?? '')); ?></textarea>
                            </div>
                            <div>
                                <label for="inbox_triage_opt_out_phrase_blocklist" style="display: block; margin-bottom: 0.35rem; font-size: 0.8125rem; font-weight: 500;">Opt-out phrase blocklist</label>
                                <textarea id="inbox_triage_opt_out_phrase_blocklist" name="inbox_triage_opt_out_phrase_blocklist" rows="4" style="width: 100%; padding: 0.45rem; border: 1px solid rgba(0,0,0,0.1); border-radius: 4px;"><?php echo htmlspecialchars((string) ($dealAutomationConfig['inbox_triage_opt_out_phrase_blocklist'] ?? '')); ?></textarea>
                            </div>
                        </div>
                    </div>
                    <div style="margin-top: 1rem; padding: 0.875rem; border: 1px solid #e2e8f0; border-radius: 8px; background: #ffffff;">
                        <div style="font-weight: 600; color: #0f172a; margin-bottom: 0.5rem; font-size: 0.875rem;">Outcome Layer (Feature-Flagged)</div>
                        <p style="margin: 0 0 0.75rem; color: #64748b; font-size: 0.75rem;">
                            Outcome-first shell with activation checklist, daily revenue focus, and TTFV KPI cards.
                        </p>
                        <div style="display: flex; flex-wrap: wrap; gap: 1rem; margin-bottom: 0.75rem;">
                            <label style="display: flex; align-items: center; gap: 0.5rem; cursor: pointer;">
                                <input type="checkbox" name="outcome_layer_enabled" value="1" <?php echo !empty($dealAutomationConfig['outcome_layer_enabled']) ? 'checked' : ''; ?> style="width: 1.125rem; height: 1.125rem;">
                                <span>Enable outcome layer</span>
                            </label>
                        </div>
                        <details style="border: 1px solid #e2e8f0; border-radius: 8px; padding: 0.6rem 0.75rem; background: #f8fafc;">
                            <summary style="cursor: pointer; color: #0f172a; font-weight: 600; font-size: 0.8125rem;">Advanced rollout + modules</summary>
                            <div style="margin-top: 0.75rem; display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;">
                                <div>
                                    <label for="outcome_layer_rollout_percent" style="display: block; margin-bottom: 0.35rem; font-size: 0.8125rem; font-weight: 500;">Rollout percent</label>
                                    <input type="number" id="outcome_layer_rollout_percent" name="outcome_layer_rollout_percent" min="0" max="100" value="<?php echo (int) ($dealAutomationConfig['outcome_layer_rollout_percent'] ?? 0); ?>" style="width: 100%; padding: 0.45rem; border: 1px solid rgba(0,0,0,0.1); border-radius: 4px;">
                                </div>
                                <div style="display: flex; flex-wrap: wrap; gap: 0.75rem; align-items: end;">
                                    <label style="display: flex; align-items: center; gap: 0.4rem;">
                                        <input type="checkbox" name="outcome_layer_checklist_enabled" value="1" <?php echo !empty($dealAutomationConfig['outcome_layer_checklist_enabled']) ? 'checked' : ''; ?>>
                                        <span style="font-size: 0.8125rem;">Checklist</span>
                                    </label>
                                    <label style="display: flex; align-items: center; gap: 0.4rem;">
                                        <input type="checkbox" name="outcome_layer_daily_focus_enabled" value="1" <?php echo !empty($dealAutomationConfig['outcome_layer_daily_focus_enabled']) ? 'checked' : ''; ?>>
                                        <span style="font-size: 0.8125rem;">Daily focus</span>
                                    </label>
                                    <label style="display: flex; align-items: center; gap: 0.4rem;">
                                        <input type="checkbox" name="outcome_layer_metrics_enabled" value="1" <?php echo !empty($dealAutomationConfig['outcome_layer_metrics_enabled']) ? 'checked' : ''; ?>>
                                        <span style="font-size: 0.8125rem;">Metrics</span>
                                    </label>
                                </div>
                            </div>
                            <p style="margin: 0.6rem 0 0; color: #64748b; font-size: 0.75rem;">
                                Rollout gate is deterministic: user bucket = <code>user_id % 100</code>.
                            </p>
                        </details>
                    </div>
                    <p style="margin: 0.75rem 0 0; color: #64748b; font-size: 0.75rem;">
                        Suggestion: keep <strong>approval for terminal stages</strong> enabled until your team trusts the automation behavior.
                    </p>
                </div>

                <div class="content-card" style="margin-bottom: 2rem; background: #f8fafc;">
                    <h3 style="color: #0f172a; margin-bottom: 1rem; font-size: 1.125rem; font-weight: 600;">Automation Rules (Plain Language)</h3>
                    <p style="color: #64748b; margin-bottom: 1rem; font-size: 0.875rem;">
                        These are the current rules the system uses when deciding whether to change stages. You do not need to read or edit JSON to understand this.
                    </p>
                    <p id="deal-automation-effective-preview" style="margin: 0 0 1rem; color: #475569; font-size: 0.8125rem;">
                        Effective preview: lookback <?php echo (int) ($dealAutomationConfig['lookback_days'] ?? 14); ?> day(s), inactivity threshold <?php echo (int) ($dealAutomationConfig['inactivity_days_for_loss'] ?? 14); ?> day(s), global confidence <?php echo number_format((float) ($dealAutomationConfig['min_confidence'] ?? 0.85), 2); ?>.
                    </p>
                    <div id="deal-automation-rules-preview" style="display: flex; flex-direction: column; gap: 0.75rem;">
                        <?php foreach (($dealAutomationConfig['transitions'] ?? []) as $transition): ?>
                            <?php
                            $from = $stageLabels[$transition['from'] ?? ''] ?? ucfirst((string) ($transition['from'] ?? 'Unknown'));
                            $to = $stageLabels[$transition['to'] ?? ''] ?? ucfirst((string) ($transition['to'] ?? 'Unknown'));
                            $isTerminal = !empty($transition['terminal']);
                            $requireAll = !empty($transition['require_all']);
                            $minConfidence = (float) ($transition['min_confidence'] ?? ($dealAutomationConfig['min_confidence'] ?? 0.85));
                            ?>
                            <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 0.875rem;">
                                <div style="display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; margin-bottom: 0.5rem;">
                                    <span style="font-weight: 600; color: #0f172a;"><?php echo htmlspecialchars($from); ?> -> <?php echo htmlspecialchars($to); ?></span>
                                    <?php if ($isTerminal): ?>
                                        <span style="font-size: 0.6875rem; color: #7c2d12; background: #ffedd5; border: 1px solid #fed7aa; border-radius: 999px; padding: 0.1rem 0.5rem;">Terminal rule</span>
                                    <?php endif; ?>
                                    <span class="deal-automation-min-confidence"
                                          data-transition-min="<?php echo isset($transition['min_confidence']) ? htmlspecialchars((string) $transition['min_confidence']) : ''; ?>"
                                          style="font-size: 0.6875rem; color: #334155; background: #e2e8f0; border-radius: 999px; padding: 0.1rem 0.5rem;">Min confidence: <?php echo number_format($minConfidence, 2); ?></span>
                                    <span style="font-size: 0.6875rem; color: #334155; background: #e2e8f0; border-radius: 999px; padding: 0.1rem 0.5rem;"><?php echo $requireAll ? 'All conditions required' : 'Any one condition can pass'; ?></span>
                                </div>
                                <ul style="margin: 0; padding-left: 1rem; color: #475569; font-size: 0.8125rem; line-height: 1.6;">
                                    <?php foreach (($transition['rules'] ?? []) as $rule): ?>
                                        <li class="deal-automation-rule"
                                            data-rule-type="<?php echo htmlspecialchars((string) ($rule['type'] ?? 'unknown')); ?>"
                                            data-intent="<?php echo htmlspecialchars((string) ($rule['intent'] ?? '')); ?>"
                                            data-min="<?php echo htmlspecialchars((string) ($rule['min'] ?? '')); ?>"
                                            data-window-days="<?php echo htmlspecialchars((string) ($rule['window_days'] ?? '')); ?>"
                                            data-min-messages="<?php echo htmlspecialchars((string) ($rule['min_messages'] ?? '')); ?>"
                                            data-days="<?php echo htmlspecialchars((string) ($rule['days'] ?? '')); ?>"
                                            data-value="<?php echo htmlspecialchars((string) ($rule['value'] ?? '')); ?>">
                                            <span class="deal-automation-rule-text"><?php echo htmlspecialchars($describeRule($rule, $dealAutomationConfig)); ?></span>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <p style="color: #64748b; font-size: 0.75rem; margin-top: 0.5rem;">
                        Advanced rule editing is managed by technical admins. Most teams only need to tune the settings above.
                    </p>
                </div>

                <button type="submit" class="btn-premium-primary">Save Settings</button>
            </form>
            
        <?php endif; ?>
            </div>
            </section>
        </div>
    </div>
</div>

<script>
function initDealAutomationDynamicPreview() {
    const lookbackInput = document.getElementById('deal_automation_lookback_days');
    const inactivityInput = document.getElementById('deal_automation_inactivity_days');
    const globalMinConfidenceInput = document.getElementById('deal_automation_min_confidence');
    const previewText = document.getElementById('deal-automation-effective-preview');
    const rulesContainer = document.getElementById('deal-automation-rules-preview');
    if (!lookbackInput || !inactivityInput || !globalMinConfidenceInput || !previewText || !rulesContainer) {
        return;
    }

    const toInt = (val, fallback) => {
        const n = parseInt(val, 10);
        return Number.isFinite(n) ? n : fallback;
    };
    const toFloat = (val, fallback) => {
        const n = parseFloat(val);
        return Number.isFinite(n) ? n : fallback;
    };
    const fixed2 = (n) => toFloat(n, 0).toFixed(2);

    const renderRuleText = (el, lookbackDays, inactivityDays) => {
        const type = el.dataset.ruleType || 'unknown';
        const intent = el.dataset.intent || 'intent';
        const min = toInt(el.dataset.min, 1);
        const windowDaysRaw = toInt(el.dataset.windowDays, lookbackDays);
        const windowDays = Math.max(1, Math.min(windowDaysRaw, lookbackDays));
        const minMessages = toInt(el.dataset.minMessages, 2);
        const days = toInt(el.dataset.days, inactivityDays);
        const value = toInt(el.dataset.value, 0);

        switch (type) {
            case 'intent_count':
                return `At least ${min} ${intent} message(s) in the last ${windowDays} day(s).`;
            case 'proposal_sent':
                return 'A proposal/quote was sent to the contact.';
            case 'lead_score_min':
                return `Lead score is at least ${value}.`;
            case 'bidirectional_exchange':
                return `Two-way conversation exists with at least ${minMessages} message(s) in ${windowDays} day(s).`;
            case 'explicit_acceptance':
                return 'Customer gave explicit acceptance evidence.';
            case 'explicit_rejection':
                return 'Customer gave explicit rejection evidence.';
            case 'inactivity_timeout':
                return `No activity for at least ${days} day(s).`;
            case 'no_negative_trend':
                return 'No negative sentiment trend detected.';
            case 'no_negative_in_window':
                return `No negative signals in the last ${windowDays} day(s).`;
            case 'no_positive_in_window':
                return `No positive signals in the last ${windowDays} day(s).`;
            default:
                return 'Custom rule is enabled for this transition.';
        }
    };

    const refresh = () => {
        const lookbackDays = Math.max(1, toInt(lookbackInput.value, 14));
        const inactivityDays = Math.max(1, toInt(inactivityInput.value, 14));
        const globalMinConfidence = toFloat(globalMinConfidenceInput.value, 0.85);

        previewText.textContent = `Effective preview: lookback ${lookbackDays} day(s), inactivity threshold ${inactivityDays} day(s), global confidence ${fixed2(globalMinConfidence)}.`;

        rulesContainer.querySelectorAll('.deal-automation-min-confidence').forEach((badge) => {
            const transitionMinRaw = badge.dataset.transitionMin;
            const transitionMin = toFloat(transitionMinRaw, globalMinConfidence);
            badge.textContent = `Min confidence: ${fixed2(transitionMin)}`;
        });

        rulesContainer.querySelectorAll('.deal-automation-rule').forEach((ruleEl) => {
            const textEl = ruleEl.querySelector('.deal-automation-rule-text');
            if (textEl) {
                textEl.textContent = renderRuleText(ruleEl, lookbackDays, inactivityDays);
            }
        });
    };

    [lookbackInput, inactivityInput, globalMinConfidenceInput].forEach((input) => {
        input.addEventListener('input', refresh);
        input.addEventListener('change', refresh);
    });
    refresh();
}

initDealAutomationDynamicPreview();

const settingsApiBase = <?php echo json_encode(getApiBasePath()); ?>;
function settingsApiUrl(path) {
    return (settingsApiBase || '') + '/api/' + String(path || '').replace(/^\/+/, '');
}

async function testAIConnection() {
    const btn = document.getElementById('test-ai-btn');
    if (!btn) return; // Button might not exist if not on AI tab
    
    const resultDiv = document.getElementById('ai-test-result');
    const originalText = btn.textContent;
    
    btn.disabled = true;
    btn.textContent = 'Testing...';
    if (resultDiv) {
        resultDiv.innerHTML = '<div style="color: var(--charcoal-grey);">Testing AI connection...</div>';
    }
    
    try {
        const response = await fetch(settingsApiUrl('test_ai_connection.php'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': '<?php echo Security::getCsrfToken(); ?>'
            },
            body: JSON.stringify({})
        });
        
        // Check if response is actually JSON
        const contentType = response.headers.get('content-type');
        let result;
        
        if (contentType && contentType.includes('application/json')) {
            result = await response.json();
        } else {
            // Response is not JSON (likely HTML error page)
            const text = await response.text();
            console.error('Non-JSON response:', text.substring(0, 200));
            throw new Error('Server returned HTML instead of JSON. Check API endpoint configuration.');
        }
        
        if (resultDiv) {
            if (result.success) {
                resultDiv.innerHTML = `
                    <div style="background: #efe; border: 1px solid #9c9; color: #393; padding: var(--spacing-sm) var(--spacing-md); border-radius: 4px; margin-top: var(--spacing-sm);">
                        ✅ <strong>Connection Successful!</strong><br>
                        Model: ${result.model || 'N/A'}<br>
                        Response: ${result.response || 'N/A'}
                    </div>
                `;
            } else {
                resultDiv.innerHTML = `
                    <div style="background: #fee; border: 1px solid #fcc; color: #c33; padding: var(--spacing-sm) var(--spacing-md); border-radius: 4px; margin-top: var(--spacing-sm);">
                        ❌ <strong>Connection Failed</strong><br>
                        ${result.error || 'Unknown error'}<br>
                        ${result.configured ? '' : 'Please configure AI API Key and Service URL above.'}
                        ${result.url_used ? '<br><small>URL used: ' + result.url_used + '</small>' : ''}
                    </div>
                `;
            }
        }
    } catch (error) {
        console.error('Test AI Connection Error:', error);
        if (resultDiv) {
            let errorMsg = error.message;
            if (error.message.includes('JSON')) {
                errorMsg = 'Server returned invalid response. Please check that the API endpoint is correct and accessible.';
            }
            resultDiv.innerHTML = `
                <div style="background: #fee; border: 1px solid #fcc; color: #c33; padding: var(--spacing-sm) var(--spacing-md); border-radius: 4px; margin-top: var(--spacing-sm);">
                    ❌ <strong>Network Error</strong><br>
                    ${errorMsg}<br>
                    <small>Check browser console for details.</small>
                </div>
            `;
        }
    } finally {
        btn.disabled = false;
        btn.textContent = originalText;
    }
}

function testSMTP() {
    document.getElementById('smtp-test-modal').style.display = 'flex';
    document.getElementById('smtp-test-result').innerHTML = '';
    document.getElementById('test-email').value = '';
}

function closeSMTPTest() {
    document.getElementById('smtp-test-modal').style.display = 'none';
}

function escapeHtml(value) {
    const div = document.createElement('div');
    div.textContent = value === null || value === undefined ? '' : String(value);
    return div.innerHTML;
}

async function sendSMTPTest() {
    const testEmail = document.getElementById('test-email').value;
    const resultDiv = document.getElementById('smtp-test-result');
    const sendBtn = document.getElementById('send-test-btn');
    
    if (!testEmail || !testEmail.includes('@')) {
        resultDiv.innerHTML = '<div style="background: #fee; border: 1px solid #fcc; color: #c33; padding: var(--spacing-sm); border-radius: 4px; font-size: 14px;">Please enter a valid email address</div>';
        return;
    }
    
    const originalText = sendBtn.textContent;
    sendBtn.disabled = true;
    sendBtn.textContent = 'Sending...';
    resultDiv.innerHTML = '<div style="background: #e3f2fd; border: 1px solid #90caf9; color: #1976d2; padding: var(--spacing-sm); border-radius: 4px; font-size: 14px;">Testing System Mail and sending a test email...</div>';
    
    try {
        const formData = new FormData();
        formData.append('test_email', testEmail);
        formData.append('action', 'send');
        
        const response = await fetch(settingsApiUrl('test_smtp.php'), {
            method: 'POST',
            body: formData
        });
        
        // Check if response is actually JSON
        const contentType = response.headers.get('content-type');
        let result;
        
        if (contentType && contentType.includes('application/json')) {
            result = await response.json();
        } else {
            // Response is not JSON (likely HTML error page)
            const text = await response.text();
            console.error('Non-JSON response:', text.substring(0, 200));
            throw new Error('Server returned HTML instead of JSON. Please check the API endpoint.');
        }
        
        if (result.success) {
            resultDiv.innerHTML = '<div style="background: #e8f5e9; border: 1px solid #81c784; color: #2e7d32; padding: var(--spacing-sm); border-radius: 4px; font-size: 14px;"><strong>Success</strong><br>' + escapeHtml(result.message || 'System Mail test email sent.') + '<br>Please check your inbox at: ' + escapeHtml(testEmail) + '</div>';
            if (result.results) {
                let details = '<div style="margin-top: var(--spacing-sm); font-size: 12px; color: #666;">';
                details += '<strong>System Mail test results:</strong><ul style="margin: var(--spacing-xs) 0; padding-left: 20px;">';
                if (result.results.active_provider_label) {
                    details += '<li>Provider: ' + escapeHtml(result.results.active_provider_label) + '</li>';
                }
                if (result.results.method_used) {
                    details += '<li>Delivery method: ' + escapeHtml(result.results.method_used) + '</li>';
                }
                details += '<li>Connection: ' + (result.results.connection ? 'Success' : 'Failed') + '</li>';
                details += '<li>Authentication: ' + (result.results.authentication ? 'Success' : 'Failed') + '</li>';
                details += '<li>Send: ' + (result.results.send ? 'Success' : 'Failed') + '</li>';
                details += '</ul></div>';
                resultDiv.innerHTML += details;
            }
            return;
            resultDiv.innerHTML = '<div style="background: #e8f5e9; border: 1px solid #81c784; color: #2e7d32; padding: var(--spacing-sm); border-radius: 4px; font-size: 14px;"><strong>✓ Success!</strong><br>' + result.message + '<br>Please check your inbox at: ' + testEmail + '</div>';
            
            if (result.results) {
                let details = '<div style="margin-top: var(--spacing-sm); font-size: 12px; color: #666;">';
                details += '<strong>Test Results:</strong><ul style="margin: var(--spacing-xs) 0; padding-left: 20px;">';
                if (result.results.active_provider_label) {
                    details += '<li>Preferred Provider: ' + result.results.active_provider_label + '</li>';
                }
                if (result.results.method_used) {
                    details += '<li>Delivery Method Used: ' + result.results.method_used + '</li>';
                }
                details += '<li>Connection: ' + (result.results.connection ? '✓ Success' : '✗ Failed') + '</li>';
                details += '<li>Authentication: ' + (result.results.authentication ? '✓ Success' : '✗ Failed') + '</li>';
                details += '<li>Send: ' + (result.results.send ? '✓ Success' : '✗ Failed') + '</li>';
                details += '</ul></div>';
                resultDiv.innerHTML += details;
            }
        } else {
            const title = result.diagnostic_title || result.message || 'System Mail test failed';
            const action = result.recommended_action || 'Review System Mail settings and try the test again.';
            const provider = result.provider_summary && result.provider_summary.provider_label ? result.provider_summary.provider_label : '';
            const technical = result.technical_error || result.error || (result.results && result.results.errors ? result.results.errors.join("\n") : '');
            let friendlyErrorMsg = '<div style="background: #fff7ed; border: 1px solid #fdba74; color: #9a3412; padding: var(--spacing-sm); border-radius: 4px; font-size: 14px; line-height:1.5;">';
            friendlyErrorMsg += '<strong>' + escapeHtml(title) + '</strong><br>' + escapeHtml(action);
            if (provider) {
                friendlyErrorMsg += '<br><small>Provider: ' + escapeHtml(provider) + '</small>';
            }
            if (technical) {
                friendlyErrorMsg += '<details style="margin-top: var(--spacing-sm);"><summary style="cursor:pointer;font-weight:700;">Technical details</summary><pre style="white-space:pre-wrap;background:rgba(154,52,18,.07);border:1px solid rgba(154,52,18,.18);border-radius:4px;padding:.6rem;margin:.5rem 0 0;font-size:12px;">' + escapeHtml(technical) + '</pre></details>';
            }
            friendlyErrorMsg += '</div>';
            resultDiv.innerHTML = friendlyErrorMsg;
            return;
            let errorMsg = '<div style="background: #fee; border: 1px solid #fcc; color: #c33; padding: var(--spacing-sm); border-radius: 4px; font-size: 14px;"><strong>✗ Error:</strong><br>' + (result.message || result.error || 'Failed to send test email');
            
            if (result.results && result.results.errors && result.results.errors.length > 0) {
                errorMsg += '<ul style="margin: var(--spacing-xs) 0; padding-left: 20px;">';
                result.results.errors.forEach(err => {
                    errorMsg += '<li>' + err + '</li>';
                });
                errorMsg += '</ul>';
            }
            
            errorMsg += '</div>';
            resultDiv.innerHTML = errorMsg;
        }
    } catch (error) {
        resultDiv.innerHTML = '<div style="background: #fee; border: 1px solid #fcc; color: #c33; padding: var(--spacing-sm); border-radius: 4px; font-size: 14px;"><strong>✗ Error:</strong><br>' + error.message + '</div>';
    } finally {
        sendBtn.disabled = false;
        sendBtn.textContent = originalText;
    }
}

function updateEncryptionHint() {
    const port = document.getElementById('smtp_port').value;
    const encryption = document.getElementById('smtp_encryption');
    
    if (port === '465') {
        encryption.value = 'ssl';
    } else if (port === '587') {
        encryption.value = 'tls';
    }
}

function applyMailProviderPreset() {
    const preset = document.getElementById('mail_provider_preset');
    if (!preset) {
        return;
    }

    const smtpHost = document.getElementById('smtp_host');
    const smtpPort = document.getElementById('smtp_port');
    const smtpEncryption = document.getElementById('smtp_encryption');
    const imapHost = document.getElementById('imap_host');
    const imapPort = document.getElementById('imap_port');
    const imapProtocol = document.getElementById('imap_protocol');
    const imapEncryption = document.getElementById('imap_encryption');

    if (preset.value === 'gmail' || preset.value === 'google_workspace') {
        smtpHost.value = 'smtp.gmail.com';
        smtpPort.value = '587';
        smtpEncryption.value = 'tls';
        imapHost.value = 'imap.gmail.com';
        imapProtocol.value = 'imap';
        imapPort.value = '993';
        imapEncryption.value = 'ssl';
    } else if (preset.value === 'custom') {
        smtpHost.focus();
    }
}

function updateImapPort() {
    const protocol = document.getElementById('imap_protocol').value;
    const portInput = document.getElementById('imap_port');
    if (protocol === 'imap') {
        portInput.value = '993';
        document.getElementById('imap_encryption').value = 'ssl';
    } else if (protocol === 'pop3') {
        portInput.value = '995';
        document.getElementById('imap_encryption').value = 'ssl';
    }
    updateImapEncryptionHint();
}

function updateImapEncryptionHint() {
    const port = parseInt(document.getElementById('imap_port').value);
    const protocol = document.getElementById('imap_protocol').value;
    const encryptionSelect = document.getElementById('imap_encryption');
    
    // Auto-update encryption based on port
    if (protocol === 'imap') {
        if (port === 993) {
            encryptionSelect.value = 'ssl';
        } else if (port === 143) {
            encryptionSelect.value = 'tls';
        }
    } else if (protocol === 'pop3') {
        if (port === 995) {
            encryptionSelect.value = 'ssl';
        } else if (port === 110) {
            encryptionSelect.value = 'none';
        }
    }
}

async function fetchEmailsNow() {
    const btn = document.getElementById('fetch-emails-btn');
    if (!btn) return;
    
    const resultDiv = document.getElementById('fetch-emails-result');
    const originalText = btn.textContent;
    
    btn.disabled = true;
    btn.textContent = 'Fetching...';
    if (resultDiv) {
        resultDiv.innerHTML = '<div style="color: var(--charcoal-grey);">Fetching emails from server...</div>';
    }
    
    try {
        const response = await fetch(settingsApiUrl('fetch_emails.php'), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': '<?php echo Security::getCsrfToken(); ?>'
            }
        });
        
        const contentType = response.headers.get('content-type');
        let result;
        
        if (contentType && contentType.includes('application/json')) {
            result = await response.json();
        } else {
            const text = await response.text();
            console.error('Non-JSON response:', text.substring(0, 200));
            throw new Error('Server returned invalid response');
        }
        
        if (resultDiv) {
            if (result.success) {
                let message = `<div style="background: #e8f5e9; border: 1px solid #81c784; color: #2e7d32; padding: var(--spacing-sm); border-radius: 4px; font-size: 14px;">
                    <strong>✓ Success!</strong><br>
                    ${result.provider_label ? 'Provider: ' + result.provider_label + '<br>' : ''}
                    Found ${result.emails_found || 0} new email(s)<br>
                    Processed: ${result.processed || 0}<br>
                    ${result.auto_created > 0 ? 'Auto-created contacts: ' + result.auto_created + '<br>' : ''}
                    ${result.skipped > 0 ? 'Skipped: ' + result.skipped + '<br>' : ''}
                    ${result.errors > 0 ? 'Errors: ' + result.errors + '<br>' : ''}
                </div>`;
                
                if (result.error_messages && result.error_messages.length > 0) {
                    message += `<div style="margin-top: var(--spacing-sm); font-size: 12px; color: #666;">
                        <strong>Error Details:</strong><ul style="margin: var(--spacing-xs) 0; padding-left: 20px;">`;
                    result.error_messages.forEach(msg => {
                        message += `<li>${msg}</li>`;
                    });
                    message += `</ul></div>`;
                }
                
                resultDiv.innerHTML = message;
            } else {
                let errorMsg = `<div style="background: #fee; border: 1px solid #fcc; color: #c33; padding: var(--spacing-sm); border-radius: 4px; font-size: 14px;">
                    <strong>✗ Error:</strong><br>${result.error || 'Failed to fetch emails'}`;
                
                // Add configuration details if available
                if (result.details) {
                    errorMsg += `<br><br><strong>Configuration Status:</strong><ul style="margin: var(--spacing-xs) 0; padding-left: 20px; font-size: 12px;">`;
                    if (result.provider_label) {
                        errorMsg += `<li>Preferred Provider: ${result.provider_label}</li>`;
                    }
                    if (result.details.google_workspace_active !== undefined) {
                        errorMsg += `<li>Google Workspace Active: ${result.details.google_workspace_active ? '✓' : '✗'}</li>`;
                    }
                    errorMsg += `<li>IMAP Enabled: ${result.details.imap_enabled ? '✓' : '✗'}</li>`;
                    errorMsg += `<li>Host Configured: ${result.details.has_host ? '✓' : '✗'}</li>`;
                    errorMsg += `<li>Username Configured: ${result.details.has_username ? '✓' : '✗'}</li>`;
                    errorMsg += `<li>Password Configured: ${result.details.has_password ? '✓' : '✗'}</li>`;
                    errorMsg += `</ul>`;
                    errorMsg += `<small style="display: block; margin-top: var(--spacing-xs);">💡 Tip: Make sure to click "Save Settings" after configuring IMAP settings.</small>`;
                }
                
                errorMsg += `</div>`;
                resultDiv.innerHTML = errorMsg;
            }
        }
    } catch (error) {
        console.error('Fetch Emails Error:', error);
        if (resultDiv) {
            resultDiv.innerHTML = `<div style="background: #fee; border: 1px solid #fcc; color: #c33; padding: var(--spacing-sm); border-radius: 4px; font-size: 14px;">
                <strong>✗ Network Error</strong><br>${error.message}<br>
                <small>Check browser console for details.</small>
            </div>`;
        }
    } finally {
        btn.disabled = false;
        btn.textContent = originalText;
    }
}

async function autoDetectWabaId() {
    const btn = document.getElementById('auto-detect-waba-btn');
    const input = document.getElementById('whatsapp_business_account_id');
    if (!btn || !input) return;
    
    const originalText = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Detecting...';
    
    try {
        const response = await fetch(settingsApiUrl('whatsapp/auto-detect.php'), {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
            },
            credentials: 'same-origin'
        });
        
        const data = await response.json();
        
        if (data.success && data.data.waba_id) {
            input.value = data.data.waba_id;
            alert('WABA ID detected: ' + data.data.waba_id);
        } else {
            alert('Could not auto-detect WABA ID. Please enter it manually or ensure your access token has the required permissions.');
        }
    } catch (error) {
        alert('Error auto-detecting WABA ID: ' + error.message);
    } finally {
        btn.disabled = false;
        btn.textContent = originalText;
    }
}

async function autoDetectPhoneNumbers() {
    const btn = document.getElementById('auto-detect-phone-btn');
    const input = document.getElementById('whatsapp_phone_number_id');
    if (!btn || !input) return;
    
    const originalText = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Detecting...';
    
    try {
        const response = await fetch(settingsApiUrl('whatsapp/auto-detect.php'), {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
            },
            credentials: 'same-origin'
        });
        
        const data = await response.json();
        
        if (data.success && data.data.phone_numbers && data.data.phone_numbers.length > 0) {
            if (data.data.phone_numbers.length === 1) {
                // Only one phone number, auto-fill it
                input.value = data.data.phone_numbers[0].id;
                alert('Phone Number ID detected: ' + data.data.phone_numbers[0].id);
            } else {
                // Multiple phone numbers, show selection dialog
                const phoneList = data.data.phone_numbers.map((p, i) => 
                    `${i + 1}. ${p.display_phone_number || p.id} (ID: ${p.id})`
                ).join('\n');
                const selection = prompt(`Multiple phone numbers found:\n\n${phoneList}\n\nEnter the number (1-${data.data.phone_numbers.length}) to select:`, '1');
                const index = parseInt(selection) - 1;
                if (index >= 0 && index < data.data.phone_numbers.length) {
                    input.value = data.data.phone_numbers[index].id;
                    alert('Phone Number ID selected: ' + data.data.phone_numbers[index].id);
                }
            }
        } else {
            alert('Could not auto-detect phone numbers. Please enter the Phone Number ID manually or ensure your access token has the required permissions.');
        }
    } catch (error) {
        alert('Error auto-detecting phone numbers: ' + error.message);
    } finally {
        btn.disabled = false;
        btn.textContent = originalText;
    }
}

async function refreshWebhookStatus() {
    const statusDiv = document.getElementById('webhook-status-display');
    if (!statusDiv) return;
    
    statusDiv.innerHTML = '<p style="color: var(--charcoal-grey); font-size: 13px;">Loading webhook status...</p>';
    
    try {
        const response = await fetch(settingsApiUrl('whatsapp/webhook-status.php'), {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
            },
            credentials: 'same-origin'
        });
        
        const data = await response.json();
        
        if (data.success) {
            let html = '';
            const status = data.status;
            const subscription = data.whatsapp_subscription;
            
            if (status === 'active' && subscription) {
                html = '<div style="background: #d4edda; border: 1px solid #28a745; padding: var(--spacing-sm); border-radius: 4px;">';
                html += '<p style="color: #155724; font-weight: 600; margin: 0 0 var(--spacing-xs) 0;">✓ Webhook Active</p>';
                html += '<p style="color: #155724; font-size: 12px; margin: var(--spacing-xs) 0;">Subscribed Events: ' + (subscription.fields ? subscription.fields.map(f => (f && typeof f === 'object' && f.name) ? f.name : (typeof f === 'string' ? f : '')).filter(Boolean).join(', ') || 'messages' : 'messages') + '</p>';
                html += '<p style="color: #155724; font-size: 12px; margin: var(--spacing-xs) 0;">Workspace callback URLs are managed from each workspace WhatsApp setup.</p>';
                html += '</div>';
            } else if (status === 'not_subscribed') {
                html = '<div style="background: #fff3cd; border: 1px solid #ffc107; padding: var(--spacing-sm); border-radius: 4px;">';
                html += '<p style="color: #856404; font-weight: 600; margin: 0 0 var(--spacing-xs) 0;">⚠ Webhook Not Subscribed</p>';
                html += '<p style="color: #856404; font-size: 12px; margin: var(--spacing-xs) 0;">Configure each workspace-specific callback URL in Meta from the workspace WhatsApp setup page.</p>';
                html += '</div>';
            } else if (status === 'app_id_not_set') {
                html = '<div style="background: #f8d7da; border: 1px solid #dc3545; padding: var(--spacing-sm); border-radius: 4px;">';
                html += '<p style="color: #721c24; font-weight: 600; margin: 0 0 var(--spacing-xs) 0;">✗ Meta App ID Not Set</p>';
                html += '<p style="color: #721c24; font-size: 12px; margin: var(--spacing-xs) 0;">Please set the Meta App ID above if you need to inspect app-level subscriptions.</p>';
                html += '</div>';
            } else {
                html = '<div style="background: #f8d7da; border: 1px solid #dc3545; padding: var(--spacing-sm); border-radius: 4px;">';
                html += '<p style="color: #721c24; font-weight: 600; margin: 0 0 var(--spacing-xs) 0;">✗ Error Checking Status</p>';
                html += '<p style="color: #721c24; font-size: 12px; margin: var(--spacing-xs) 0;">' + (data.error || 'Unknown error') + '</p>';
                const is190 = (data.error || '').indexOf('190') !== -1 || (data.error || '').indexOf('Application Secret') !== -1;
                if (is190 && data.app_secret_configured === false) {
                    html += '<p style="color: #721c24; font-size: 12px; margin: var(--spacing-xs) 0 0 0;">Enter <strong>Meta App Secret</strong> in the field above and click <strong>Save Settings</strong>.</p>';
                } else if (is190 && data.app_secret_configured === true) {
                    html += '<p style="color: #721c24; font-size: 12px; margin: var(--spacing-xs) 0 0 0;">App secret is set. Ensure the <strong>access token</strong> was generated for the <strong>same app</strong> as the Meta App ID and App Secret (Meta Developers → same app). Re-enter the exact App Secret from Settings → Basic (no spaces or quotes), or try turning off <strong>Require App Secret</strong> under App Settings → Advanced → Security.</p>';
                }
                html += '</div>';
            }
            
            statusDiv.innerHTML = html;
        } else {
            statusDiv.innerHTML = '<div style="background: #f8d7da; border: 1px solid #dc3545; padding: var(--spacing-sm); border-radius: 4px; color: #721c24;">Error: ' + (data.error || 'Unknown error') + '</div>';
        }
    } catch (error) {
        statusDiv.innerHTML = '<div style="background: #f8d7da; border: 1px solid #dc3545; padding: var(--spacing-sm); border-radius: 4px; color: #721c24;">Error: ' + error.message + '</div>';
    }
}

async function testMetaPermissions() {
    const resultDiv = document.getElementById('meta-test-result');
    if (!resultDiv) return;
    
    resultDiv.innerHTML = '<div style="color: var(--charcoal-grey);">Testing Meta permissions...</div>';
    
    try {
        const response = await fetch(settingsApiUrl('whatsapp/test-permissions.php'), {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
            },
            credentials: 'same-origin'
        });
        
        const data = await response.json();
        
        if (data.success) {
            let html = '<div style="background: #d4edda; border: 1px solid #28a745; padding: var(--spacing-sm); border-radius: 4px; margin-top: var(--spacing-xs);">';
            html += '<p style="color: #155724; font-weight: 600; margin: 0 0 var(--spacing-xs) 0;">Test Results:</p>';
            html += `<p style="color: #155724; margin: 0; font-size: 12px;">Total: ${data.summary.total_tests} | Passed: ${data.summary.passed} | Failed: ${data.summary.failed}</p>`;
            
            // Show detailed results
            html += '<div style="margin-top: var(--spacing-xs); font-size: 11px;">';
            for (const [permission, tests] of Object.entries(data.results)) {
                html += `<div style="margin-top: var(--spacing-xs);"><strong>${permission}:</strong> `;
                const testResults = Object.values(tests);
                const passed = testResults.filter(t => t.success).length;
                const total = testResults.length;
                html += `${passed}/${total} passed</div>`;
            }
            html += '</div>';
            html += '</div>';
            resultDiv.innerHTML = html;
        } else {
            resultDiv.innerHTML = `<div style="background: #f8d7da; border: 1px solid #dc3545; padding: var(--spacing-sm); border-radius: 4px; color: #721c24; margin-top: var(--spacing-xs);">Error: ${data.error || 'Unknown error'}</div>`;
        }
    } catch (error) {
        resultDiv.innerHTML = `<div style="background: #f8d7da; border: 1px solid #dc3545; padding: var(--spacing-sm); border-radius: 4px; color: #721c24; margin-top: var(--spacing-xs);">Error: ${error.message}</div>`;
    }
}

document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('[data-marketplace-setup-tabs]').forEach(function(group) {
        const tabs = Array.prototype.slice.call(group.querySelectorAll('[data-marketplace-setup-tab]'));
        const container = group.nextElementSibling;
        const panels = container ? Array.prototype.slice.call(container.querySelectorAll('[data-marketplace-setup-panel]')) : [];
        if (!tabs.length || !panels.length) return;

        function activate(tabName, updateUrl, href) {
            if (!tabs.some(function(tab) { return tab.getAttribute('data-marketplace-setup-tab') === tabName; })) {
                tabName = tabs[0].getAttribute('data-marketplace-setup-tab') || 'setup';
                href = tabs[0].href;
            }
            tabs.forEach(function(tab) {
                const selected = tab.getAttribute('data-marketplace-setup-tab') === tabName;
                tab.classList.toggle('is-active', selected);
                tab.setAttribute('aria-selected', selected ? 'true' : 'false');
            });
            panels.forEach(function(panel) {
                panel.hidden = panel.getAttribute('data-marketplace-setup-panel') !== tabName;
            });
            if (updateUrl && href && window.history && window.history.replaceState) {
                window.history.replaceState({}, '', new URL(href, window.location.href).toString());
            }
        }

        tabs.forEach(function(tab) {
            tab.addEventListener('click', function(event) {
                event.preventDefault();
                activate(tab.getAttribute('data-marketplace-setup-tab') || 'setup', true, tab.href);
            });
        });

        const selected = tabs.find(function(tab) {
            return tab.getAttribute('aria-selected') === 'true';
        });
        activate(selected ? selected.getAttribute('data-marketplace-setup-tab') : (tabs[0].getAttribute('data-marketplace-setup-tab') || 'setup'), false, null);
    });
});

// Load webhook status on page load
document.addEventListener('DOMContentLoaded', function() {
    if (document.getElementById('webhook-status-display')) {
        refreshWebhookStatus();
    }
});

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-page-video-file-input]').forEach(function (input) {
        input.addEventListener('change', function () {
            const nameTarget = input.closest('.page-video-file-picker')?.querySelector('[data-page-video-file-name]');
            const fileCount = input.files ? input.files.length : 0;
            const fileName = fileCount > 1 ? fileCount + ' videos selected' : (fileCount === 1 ? input.files[0].name : '');
            if (nameTarget && fileName) {
                nameTarget.textContent = fileName;
                nameTarget.setAttribute('title', fileName);
            }
        });
    });

    const modal = document.querySelector('[data-page-video-preview-modal]');
    const player = document.querySelector('[data-page-video-preview-player]');
    const title = document.querySelector('[data-page-video-preview-title]');
    const name = document.querySelector('[data-page-video-preview-name]');
    const closeButton = document.querySelector('[data-page-video-preview-close]');
    let lastFocused = null;

    if (!modal || !player) {
        return;
    }

    function openPageVideoPreview(trigger) {
        const videoUrl = trigger.getAttribute('data-video-url') || '';
        if (!videoUrl) {
            return;
        }

        lastFocused = trigger;
        if (title) {
            title.textContent = trigger.getAttribute('data-video-title') || 'Current video';
        }
        if (name) {
            name.textContent = trigger.getAttribute('data-video-name') || '';
        }
        player.setAttribute('src', videoUrl);
        modal.hidden = false;
        document.body.style.overflow = 'hidden';
        player.load();
        if (closeButton) {
            closeButton.focus();
        }
    }

    function closePageVideoPreview() {
        modal.hidden = true;
        document.body.style.overflow = '';
        player.pause();
        player.removeAttribute('src');
        player.load();
        if (lastFocused && typeof lastFocused.focus === 'function') {
            lastFocused.focus();
        }
    }

    document.querySelectorAll('[data-page-video-preview-open]').forEach(function (trigger) {
        trigger.addEventListener('click', function () {
            openPageVideoPreview(trigger);
        });
    });

    if (closeButton) {
        closeButton.addEventListener('click', closePageVideoPreview);
    }
    modal.addEventListener('click', function (event) {
        if (event.target === modal) {
            closePageVideoPreview();
        }
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !modal.hidden) {
            closePageVideoPreview();
        }
    });
});
</script>

<?php
settingsPerfMark($settingsPerfTimeline, 'render_complete');
settingsPerfFlush($settingsPerfTimeline, (string) $activeTab, (string) $requestTab, (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
