<?php

require_once __DIR__ . '/_public_bootstrap.php';
require_once __DIR__ . '/../views/partials/marketplace_plugin_setup.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Security;
use CRM\Session;
use CRM\Services\MarketplaceCacheService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceMarketplaceActivationBundleEventService;
use CRM\Services\WorkspaceMarketplaceActivationBundleInsightService;
use CRM\Services\WorkspaceMarketplaceActivationBundleService;
use CRM\Services\WorkspaceMarketplaceRecommendationControlService;
use CRM\Services\WorkspaceMarketplaceRecommendationEventService;
use CRM\Services\WorkspaceMarketplaceRecommendationInsightService;
use CRM\Services\WorkspaceMarketplaceRecommendationService;
use CRM\Services\WorkspaceMarketplaceAccessService;
use CRM\Services\WorkspaceMarketplaceCatalogCopyService;
use CRM\Services\WorkspaceMarketplaceNextActionService;
use CRM\Services\WorkspaceMarketplacePerformanceService;
use CRM\Services\WorkspaceMarketplaceSetupJourneyEventService;
use CRM\Services\WorkspaceMarketplaceSetupJourneyService;
use CRM\Services\AICoachReadinessService;
use CRM\Services\AICoachWorkspaceSetupService;
use CRM\Services\DemoSessionScopeService;
use CRM\Services\MarketplacePageExplainerService;
use CRM\Services\MarketplaceUploadCleanupService;
use CRM\Services\ManagedWhatsAppProvisioningService;
use CRM\Services\PageGuideVideoUi;
use CRM\Services\VideoBrandOverlayUi;
use CRM\Services\OrganizationFunctionService;
use CRM\Services\PluginRuntimeEventService;
use CRM\Services\PluginRuntimeRegistryService;
use CRM\Services\SMSService;
use CRM\Services\SmartTemplateGenerationService;
use CRM\Services\StartupJourneyService;
use CRM\Services\TargetPluginIntegrationService;
use CRM\Services\EmailIntegrationService;
use CRM\Services\EmailAssistantDigestService;
use CRM\Services\AutomationJobHealthService;
use CRM\Services\GuidedDemoAccessService;
use CRM\Services\GuidedSetupDestinationService;
use CRM\Services\WorkflowCapabilityRegistryService;
use CRM\Services\WorkspaceFinanceGateService;
use CRM\Services\FinanceOwnerEquityService;
use CRM\Services\WhatsAppAssistantConfig;
use CRM\Services\WhatsAppAssistantDigestService;
use CRM\Services\WhatsAppFeatureGate;
use CRM\Services\WhatsAppMigrationService;
use CRM\Services\WhatsAppSettingsHubService;
use CRM\Services\WorkspaceAssistantConfigService;
use CRM\Services\WorkspaceAIProviderConfigService;
use CRM\Services\WorkspaceAIProviderResolverService;
use CRM\Services\WorkspaceChannelHealthService;
use CRM\Services\WorkspaceConnectService;
use CRM\Services\WorkspaceHRAnalyticsSetupService;
use CRM\Services\WorkspaceSkillCatalogService;
use CRM\Services\WorkspaceSkillInstallService;
use CRM\Services\SocialMediaService;
use CRM\Services\WorkspaceWhatsAppCreditService;
use CRM\Services\WorkspaceSmsChannelConfigService;
use CRM\Services\WorkspaceVoiceConfigService;
use CRM\Services\WorkspaceVoiceEntitlementService;
use CRM\Services\VoiceAgentService;
use CRM\Services\VoiceQueueService;
use CRM\Services\AfricaTalkingVoiceProvider;
use CRM\Services\VoiceObservabilityService;
use CRM\Services\VoiceTranscriptionJobService;
use CRM\Services\VoiceContactPhoneIndexService;
use CRM\Services\UIExperienceService;
use CRM\Modules\HRAnalyticsSettings;
use CRM\Modules\MeetingBotConfig;
use CRM\Modules\MeetingNoteTakerConfig;
use CRM\Modules\Currencies;
use CRM\Modules\UserStrategyProfile;
use CRM\Modules\UserStrategySnapshot;
use CRM\Services\MeetingBotService;
use CRM\Services\MeetingNoteTakerService;
use CRM\Services\CalendarSyncService;
use CRM\Services\GoogleOAuthScopeCatalog;
use CRM\Services\MeetingBookingService;
use CRM\Services\MeetingAvailabilityService;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);
$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
if ($workspaceId <= 0) {
    header('Location: dashboard.php');
    exit;
}
$isProtectedDemoMarketplace = false;
try {
    $isProtectedDemoMarketplace = (new DemoSessionScopeService())->activeSession($workspaceId) !== null;
} catch (\Throwable $e) {
    $isProtectedDemoMarketplace = false;
}
$uiExperience = new UIExperienceService();
$marketplaceExperienceMode = $uiExperience->modeForUser($user, $workspaceId);
$marketplaceModeIsBeginner = $marketplaceExperienceMode === UIExperienceService::MODE_BEGINNER;
$marketplaceProductLabel = $uiExperience->marketplaceLabel($marketplaceExperienceMode);
if ($isProtectedDemoMarketplace) {
    $marketplaceProductLabel = 'Configured capabilities';
    $marketplaceModeIsBeginner = false;
}
$catalog = new WorkspaceSkillCatalogService();
$installer = new WorkspaceSkillInstallService($catalog);
$earlyRequestedModuleKey = preg_replace('/[^a-z0-9_]+/', '_', strtolower(trim((string) ($_GET['module'] ?? '')))) ?? '';
$earlyRequestedModuleKey = trim($earlyRequestedModuleKey, '_');
$earlyPostedSkillKey = preg_replace('/[^a-z0-9_]+/', '_', strtolower(trim((string) ($_POST['skill_key'] ?? '')))) ?? '';
$earlyPostedSkillKey = trim($earlyPostedSkillKey, '_');
$earlyPostedSkillAction = (string) ($_POST['skill_action'] ?? '');
$canViewCalendarMeetingsSetup = false;
$isCalendarMeetingsSetupRequest = ($_SERVER['REQUEST_METHOD'] === 'GET' && $earlyRequestedModuleKey === WorkspaceSkillCatalogService::PLUGIN_CALENDAR_MEETINGS)
    || ($_SERVER['REQUEST_METHOD'] === 'POST'
        && $earlyPostedSkillKey === WorkspaceSkillCatalogService::PLUGIN_CALENDAR_MEETINGS
        && in_array($earlyPostedSkillAction, ['manage_calendar_connection', 'save_calendar_meetings_setup'], true));
if ($isCalendarMeetingsSetupRequest) {
    $canViewCalendarMeetingsSetup = !$catalog->isGloballyDeactivated(WorkspaceSkillCatalogService::PLUGIN_CALENDAR_MEETINGS)
        && $installer->isInstalled($workspaceId, WorkspaceSkillCatalogService::PLUGIN_CALENDAR_MEETINGS);
}

$isGuidedDemoVisit = (string) ($_GET['guided_demo'] ?? '') === '1';
$canViewActiveGuidedDemoStep = false;
if ($isGuidedDemoVisit) {
    $canViewActiveGuidedDemoStep = (new GuidedDemoAccessService())->canViewActiveStepRoute(
        $workspaceId,
        $user ?: [],
        'workspace_skills.php',
        $_GET
    );
}

$canViewMarketplace = Authorization::isSuperAdmin($user)
    || Authorization::can('workspace.skills.view', $user)
    || Authorization::can('workspace.skills.manage', $user)
    || $isProtectedDemoMarketplace
    || $canViewCalendarMeetingsSetup
    || $canViewActiveGuidedDemoStep;
$canManageMarketplace = Authorization::isSuperAdmin($user)
    || Authorization::can('workspace.skills.manage', $user);
$canManageMarketplace = $isProtectedDemoMarketplace ? false : $canManageMarketplace;
$canManage = $canManageMarketplace;
$canViewMarketplacePerformance = Authorization::isSuperAdmin($user);
$canEditMarketplaceCatalog = Authorization::isSuperAdmin($user);
$canViewMarketplacePerformance = $isProtectedDemoMarketplace ? false : $canViewMarketplacePerformance;
$canEditMarketplaceCatalog = $isProtectedDemoMarketplace ? false : $canEditMarketplaceCatalog;

if (!$canViewMarketplace) {
    http_response_code(403);
    echo 'Access denied: Marketplace access requires the Workspace Skills access profile.';
    exit;
}

$marketplaceAccess = new WorkspaceMarketplaceAccessService($catalog, $installer);
$assistantConfig = new WorkspaceAssistantConfigService();
$smsChannelConfig = new WorkspaceSmsChannelConfigService();
$aiProviderConfigs = new WorkspaceAIProviderConfigService();
$aiProviderResolver = new WorkspaceAIProviderResolverService($aiProviderConfigs);
$emailIntegrations = new EmailIntegrationService();
$workspaceConnect = new WorkspaceConnectService();
$channelHealth = new WorkspaceChannelHealthService($emailIntegrations, $workspaceConnect, $smsChannelConfig);
$recommender = new WorkspaceMarketplaceRecommendationService($catalog, $installer, null, null, $marketplaceAccess);
$setupJourneys = new WorkspaceMarketplaceSetupJourneyService($catalog);
$setupJourneyEvents = new WorkspaceMarketplaceSetupJourneyEventService();
$guidedDestinations = new GuidedSetupDestinationService(null, $catalog, $recommender, $setupJourneys);
$pluginRuntimeRegistry = new PluginRuntimeRegistryService($catalog, $installer);
$pluginRuntimeEvents = new PluginRuntimeEventService();
$targetPluginIntegration = new TargetPluginIntegrationService($pluginRuntimeRegistry, $pluginRuntimeEvents);
$workflowCapabilityRegistry = new WorkflowCapabilityRegistryService($pluginRuntimeRegistry);
$recommendationEvents = new WorkspaceMarketplaceRecommendationEventService();
$whatsAppSettingsHub = new WhatsAppSettingsHubService($catalog, $installer, $assistantConfig, $workspaceConnect, $channelHealth, $setupJourneyEvents, $recommendationEvents);
$recommendationControls = new WorkspaceMarketplaceRecommendationControlService();
$activationBundles = new WorkspaceMarketplaceActivationBundleService($catalog, $installer, $recommender, $setupJourneys, null, $marketplaceAccess);
$activationBundleEvents = new WorkspaceMarketplaceActivationBundleEventService();
$marketplaceNextActions = new WorkspaceMarketplaceNextActionService($catalog, $installer, $marketplaceAccess, $recommender);
$catalogCopy = new WorkspaceMarketplaceCatalogCopyService();
$marketplacePerformance = new WorkspaceMarketplacePerformanceService();
$pageExplainers = new MarketplacePageExplainerService();
$marketplaceUploadCleanup = new MarketplaceUploadCleanupService();
$marketplaceGuideVideoUrl = PageGuideVideoUi::activeVideoUrl(MarketplacePageExplainerService::PAGE_MARKETPLACE);
$hrAnalyticsSettings = new HRAnalyticsSettings();
$hrAnalyticsSetup = new WorkspaceHRAnalyticsSetupService();
$organizationFunctions = new OrganizationFunctionService();
$organizationFunctions->ensureDefaults($workspaceId);
$financeGate = new WorkspaceFinanceGateService($installer);
$financeOwnerEquity = new FinanceOwnerEquityService();
$activationBundleAttributionKey = preg_replace('/[^a-z0-9_]+/', '_', strtolower(trim((string) ($_POST['activation_bundle_key'] ?? $_GET['activation_bundle_key'] ?? '')))) ?? '';
$activationBundleAttributionKey = trim($activationBundleAttributionKey, '_');
$error = null;
$success = null;
if ((string) ($_GET['legacy_settings_redirect'] ?? '') === '1') {
    $success = 'Email Assistant settings now live exclusively in this Marketplace plugin.';
}
$marketplaceWantsJson = false;
$marketplaceJsonPayload = [];
$catalogEditorDraftByKey = [];

$bundleByKey = static function (array $bundles, string $bundleKey): ?array {
    foreach ($bundles as $bundle) {
        if ((string) ($bundle['bundle_key'] ?? '') === $bundleKey) {
            return $bundle;
        }
    }

    return null;
};

$activationBundleUrl = static function (string $url, string $bundleKey, string $nextSkillKey): string {
    if ($bundleKey === '') {
        return $url;
    }

    $separator = str_contains($url, '?') ? '&' : '?';
    return $url . $separator . http_build_query([
        'source' => 'activation_bundle',
        'activation_bundle_key' => $bundleKey,
        'activation_bundle_next_skill' => $nextSkillKey,
    ]);
};

$marketplacePostString = static function (string $key, string $default = ''): string {
    return trim((string) ($_POST[$key] ?? $default));
};
$marketplacePostBool = static function (string $key): bool {
    return isset($_POST[$key]) && !in_array((string) $_POST[$key], ['', '0', 'false', 'off'], true);
};
$marketplaceFormatBytes = static function (int $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $value = (float) max(0, $bytes);
    $unit = 0;
    while ($value >= 1024 && $unit < count($units) - 1) {
        $value /= 1024;
        $unit++;
    }

    return $unit === 0 ? ((string) max(0, $bytes) . ' B') : number_format($value, 1) . ' ' . $units[$unit];
};
$marketplaceCatalogStatusLabel = static function (string $status): string {
    return match ($status) {
        WorkspaceSkillCatalogService::CATALOG_STATUS_HIDDEN => 'Hidden',
        WorkspaceSkillCatalogService::CATALOG_STATUS_DEACTIVATED => 'Deactivated',
        default => 'Visible',
    };
};
$marketplaceCatalogStatusMessage = static function (string $status): string {
    return match ($status) {
        WorkspaceSkillCatalogService::CATALOG_STATUS_HIDDEN => 'Hidden from Marketplace discovery and new installs. Existing installs keep running.',
        WorkspaceSkillCatalogService::CATALOG_STATUS_DEACTIVATED => 'Deactivated globally. Hidden from discovery and blocked from workspace runtime.',
        default => 'Visible in Marketplace discovery and available for workspace installs.',
    };
};
$marketplacePostInt = static function (string $key, int $default, int $min = 0, int $max = 100000): int {
    $raw = trim((string) ($_POST[$key] ?? ''));
    if ($raw === '') {
        return $default;
    }

    return max($min, min($max, (int) $raw));
};
$startupJourneyService = new StartupJourneyService();
$marketplacePostFloat = static function (string $key, float $default, float $min = 0.0, float $max = 1.0): float {
    $raw = trim((string) ($_POST[$key] ?? ''));
    if ($raw === '') {
        return $default;
    }

    return max($min, min($max, (float) $raw));
};
$marketplacePreserveSecret = static function (string $postKey, string $settingsKey, array $currentSettings) use ($marketplacePostString): string {
    $posted = $marketplacePostString($postKey);
    return $posted !== '' ? $posted : (string) ($currentSettings[$settingsKey] ?? '');
};
$marketplacePostLines = static function (string $key): array {
    $raw = (string) ($_POST[$key] ?? '');
    return array_values(array_filter(array_map(
        static fn(string $line): string => trim($line),
        preg_split('/\R/', $raw) ?: []
    ), static fn(string $line): bool => $line !== ''));
};
$marketplacePostTokens = static function (string $key) use ($marketplacePostLines): array {
    $tokens = [];
    foreach ($marketplacePostLines($key) as $line) {
        foreach (preg_split('/,/', $line) ?: [] as $token) {
            $token = trim($token);
            if ($token !== '') {
                $tokens[] = $token;
            }
        }
    }

    return array_values(array_unique($tokens));
};
$marketplacePostContextFields = static function (string $key) use ($marketplacePostLines): array {
    $fields = [];
    foreach ($marketplacePostLines($key) as $line) {
        $required = true;
        $line = trim($line);
        if (str_ends_with($line, '?')) {
            $required = false;
            $line = rtrim(substr($line, 0, -1));
        }
        if ($line !== '') {
            $fields[] = ['label' => $line, 'required' => $required];
        }
    }

    return $fields;
};
$marketplaceSaveEnvSettings = static function (array $settings, array $secretKeys = []): void {
    $envFile = __DIR__ . '/../.env';
    $envContent = file_exists($envFile) ? (string) file_get_contents($envFile) : '';
    foreach ($settings as $key => $value) {
        $key = preg_replace('/[^A-Z0-9_]+/', '', strtoupper((string) $key)) ?? '';
        if ($key === '') {
            continue;
        }
        $value = trim((string) $value);
        if ($value === '' && in_array($key, $secretKeys, true)) {
            continue;
        }
        $line = $key . '=' . str_replace(["\r", "\n"], '', $value);
        $pattern = '/^' . preg_quote($key, '/') . '=.*$/m';
        if (preg_match($pattern, $envContent) === 1) {
            $envContent = preg_replace($pattern, $line, $envContent) ?? $envContent;
        } else {
            $envContent = rtrim($envContent) . "\n" . $line . "\n";
        }
        $_ENV[$key] = $value;
    }
    file_put_contents($envFile, $envContent);
};
$marketplaceUploadImage = static function (string $field, string $skillKey): string {
    $file = $_FILES[$field] ?? null;
    if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return '';
    }
    if ((int) ($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new \RuntimeException('Marketplace image upload failed.');
    }
    if ((int) ($file['size'] ?? 0) > 4 * 1024 * 1024) {
        throw new \RuntimeException('Marketplace images must be 4 MB or smaller.');
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new \RuntimeException('Marketplace image upload was not valid.');
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? (string) finfo_file($finfo, $tmpName) : (string) ($file['type'] ?? '');
    if ($finfo) {
        finfo_close($finfo);
    }

    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];
    if (!isset($extensions[$mime])) {
        throw new \RuntimeException('Marketplace images must be JPG, PNG, WebP, or GIF files.');
    }

    $safeKey = preg_replace('/[^a-z0-9_]+/', '_', strtolower($skillKey)) ?: 'module';
    $uploadDir = __DIR__ . '/../uploads/marketplace/' . $safeKey . '/';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        throw new \RuntimeException('Could not create marketplace upload directory.');
    }

    $fileName = $field . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $extensions[$mime];
    $destination = $uploadDir . $fileName;
    if (!move_uploaded_file($tmpName, $destination)) {
        throw new \RuntimeException('Could not save marketplace image.');
    }

    return 'uploads/marketplace/' . $safeKey . '/' . $fileName;
};
$marketplaceUploadErrorMessage = static function (string $label, int $error): string {
    return match ($error) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => $label . ' exceeds the server upload limit.',
        UPLOAD_ERR_PARTIAL => $label . ' was only partially uploaded. Please try again.',
        UPLOAD_ERR_NO_TMP_DIR => 'The server is missing a temporary upload folder.',
        UPLOAD_ERR_CANT_WRITE => 'The server could not write the uploaded ' . strtolower($label) . '.',
        UPLOAD_ERR_EXTENSION => $label . ' was blocked by a server upload extension.',
        default => $label . ' upload failed.',
    };
};
$marketplaceVideoExtension = static function (array $file, string $tmpName, string $label): string {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = $finfo ? (string) finfo_file($finfo, $tmpName) : (string) ($file['type'] ?? '');
    if ($finfo) {
        finfo_close($finfo);
    }

    $extensions = [
        'video/mp4' => 'mp4',
        'video/x-mp4' => 'mp4',
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
};
$marketplaceUploadVideo = static function (string $field, string $skillKey) use ($marketplaceUploadErrorMessage, $marketplaceVideoExtension): string {
    $file = $_FILES[$field] ?? null;
    if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return '';
    }
    $uploadError = (int) ($file['error'] ?? UPLOAD_ERR_OK);
    if ($uploadError !== UPLOAD_ERR_OK) {
        throw new \RuntimeException($marketplaceUploadErrorMessage('Marketplace video', $uploadError));
    }
    if ((int) ($file['size'] ?? 0) > 50 * 1024 * 1024) {
        throw new \RuntimeException('Marketplace videos must be 50 MB or smaller.');
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new \RuntimeException('Marketplace video upload was not valid.');
    }

    $extension = $marketplaceVideoExtension($file, $tmpName, 'Marketplace video');

    $safeKey = preg_replace('/[^a-z0-9_]+/', '_', strtolower($skillKey)) ?: 'module';
    $uploadDir = __DIR__ . '/../uploads/marketplace/' . $safeKey . '/';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        throw new \RuntimeException('Could not create marketplace upload directory.');
    }

    $fileName = $field . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
    $destination = $uploadDir . $fileName;
    if (!move_uploaded_file($tmpName, $destination)) {
        throw new \RuntimeException('Could not save marketplace video.');
    }

    return 'uploads/marketplace/' . $safeKey . '/' . $fileName;
};
$marketplaceUploadPageExplainerVideo = static function (string $field, string $pageKey) use ($marketplaceUploadErrorMessage, $marketplaceVideoExtension): string {
    $file = $_FILES[$field] ?? null;
    if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return '';
    }
    $uploadError = (int) ($file['error'] ?? UPLOAD_ERR_OK);
    if ($uploadError !== UPLOAD_ERR_OK) {
        throw new \RuntimeException($marketplaceUploadErrorMessage('Marketplace page explainer video', $uploadError));
    }
    if ((int) ($file['size'] ?? 0) > 50 * 1024 * 1024) {
        throw new \RuntimeException('Marketplace page explainer videos must be 50 MB or smaller.');
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        throw new \RuntimeException('Marketplace page explainer video upload was not valid.');
    }

    $extension = $marketplaceVideoExtension($file, $tmpName, 'Marketplace page explainer video');

    $safeKey = preg_replace('/[^a-z0-9_]+/', '_', strtolower($pageKey)) ?: 'page';
    $uploadDir = __DIR__ . '/../uploads/marketplace/page_explainers/' . $safeKey . '/';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        throw new \RuntimeException('Could not create marketplace page explainer upload directory.');
    }

    $fileName = $field . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
    $destination = $uploadDir . $fileName;
    if (!move_uploaded_file($tmpName, $destination)) {
        throw new \RuntimeException('Could not save marketplace page explainer video.');
    }

    return 'uploads/marketplace/page_explainers/' . $safeKey . '/' . $fileName;
};
$marketplacePageExplainerConfig = [
    MarketplacePageExplainerService::PAGE_STARTUP_JOURNEY => [
        'page_key' => MarketplacePageExplainerService::PAGE_STARTUP_JOURNEY,
        'label' => 'Clarity Journey page guide',
        'short_label' => 'Clarity Journey guide',
        'file_field' => 'startup_journey_page_explainer_video_file',
        'active_field' => 'startup_journey_page_explainer_active',
        'remove_field' => 'startup_journey_page_explainer_remove_video',
    ],
    MarketplacePageExplainerService::PAGE_FOUNDER_OPERATING_LOOP => [
        'page_key' => MarketplacePageExplainerService::PAGE_FOUNDER_OPERATING_LOOP,
        'label' => 'Founder Loop page guide',
        'short_label' => 'Founder Loop guide',
        'file_field' => 'founder_loop_page_explainer_video_file',
        'active_field' => 'founder_loop_page_explainer_active',
        'remove_field' => 'founder_loop_page_explainer_remove_video',
    ],
    MarketplacePageExplainerService::PAGE_FINANCE => [
        'page_key' => MarketplacePageExplainerService::PAGE_FINANCE,
        'label' => 'Finance page guide',
        'short_label' => 'Finance guide',
        'file_field' => 'finance_page_explainer_video_file',
        'active_field' => 'finance_page_explainer_active',
        'remove_field' => 'finance_page_explainer_remove_video',
    ],
];
$marketplaceSavePageExplainerVideo = static function (array $config) use ($pageExplainers, $marketplaceUploadPageExplainerVideo, $marketplacePostBool, $userId): bool {
    $pageKey = (string) ($config['page_key'] ?? '');
    $label = (string) ($config['label'] ?? 'Page guide');
    $activeField = (string) ($config['active_field'] ?? '');
    $removeField = (string) ($config['remove_field'] ?? '');
    $currentExplainer = $pageExplainers->get($pageKey) ?? [];
    $uploadedVideoPath = $marketplaceUploadPageExplainerVideo((string) ($config['file_field'] ?? ''), $pageKey);
    $videoUrl = $uploadedVideoPath !== '' ? $uploadedVideoPath : (string) ($currentExplainer['video_url'] ?? '');
    $removeVideo = $removeField !== '' && $marketplacePostBool($removeField);
    if ($removeVideo) {
        $videoUrl = '';
    }

    $isActive = $removeVideo ? false : ($activeField !== '' && array_key_exists($activeField, $_POST)
        ? $marketplacePostBool($activeField)
        : !empty($currentExplainer['is_active']));
    if ($isActive && trim($videoUrl) === '') {
        throw new \RuntimeException('Upload a ' . $label . ' video before activating it.');
    }

    $pageExplainers->save($pageKey, $label, $videoUrl, $isActive, $userId);

    return $uploadedVideoPath !== '' && $videoUrl !== '';
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $marketplaceWantsJson = (string) ($_POST['autosave'] ?? '') === '1'
        || str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json')
        || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    try {
        if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
            throw new \RuntimeException('Invalid security token. Please refresh and try again.');
        }
        $skillKey = (string) ($_POST['skill_key'] ?? '');
        $bundleKey = (string) ($_POST['bundle_key'] ?? '');
        $action = (string) ($_POST['skill_action'] ?? '');
        $canManageThisAction = $canManage
            || (
                $action === 'save_finance_setup'
                && $skillKey === WorkspaceSkillCatalogService::PLUGIN_FINANCE
                && Authorization::can('finance.manage', $user)
            )
            || (
                $action === 'save_calendar_meetings_setup'
                && $skillKey === WorkspaceSkillCatalogService::PLUGIN_CALENDAR_MEETINGS
                && Authorization::canAny(['settings.calendar', 'settings.meeting_bot', 'settings.meeting_note_taker', 'meeting_availability.manage'], $user)
            )
            || (
                $action === 'manage_calendar_connection'
                && $skillKey === WorkspaceSkillCatalogService::PLUGIN_CALENDAR_MEETINGS
                && $workspaceConnect->canUseCalendarConnections($user, $workspaceId)
            )
            || (
                $action === 'save_communication_setup'
                && $skillKey === WorkspaceSkillCatalogService::PLUGIN_WHATSAPP
                && $workspaceConnect->canManageWhatsAppConnection($user, $workspaceId)
            )
            || (
                (in_array($action, ['save_voice_call_center_setup', 'test_voice_call_center_provider'], true)
                    || str_starts_with($action, 'retry_voice_transcription_job:'))
                && $skillKey === WorkspaceSkillCatalogService::PLUGIN_VOICE_CALL_CENTER
                && Authorization::can('voice.settings.manage', $user)
            )
            || (
                $skillKey === WorkspaceSkillCatalogService::PLUGIN_SOCIAL_MEDIA
                && (
                    in_array($action, ['save_social_media_brand_setup', 'save_social_media_publishing_setup'], true)
                    || str_starts_with($action, 'verify_social_media_account:')
                    || str_starts_with($action, 'disconnect_social_media_account:')
                )
                && Authorization::can('marketing.manage', $user)
            );
        if (!$canManageThisAction) {
            throw new \RuntimeException('Your access profile does not allow Marketplace install or setup changes.');
        }
        if ($action === 'clone_template') {
            throw new \RuntimeException('Template cloning is no longer available.');
        }
        $catalogStatusActions = [
            'draft_catalog_copy',
            'save_catalog_override',
            'save_catalog_media',
            'save_catalog_status',
            'reset_catalog_override',
            'save_activation_bundle_definition',
            'toggle_activation_bundle_definition',
            'archive_activation_bundle_definition',
            'delete_activation_bundle_definition',
        ];
        $hiddenBlockedActions = [
            'install',
            'save_skill_context',
            'save_lean_canvas_setup',
            'save_professional_marketer_setup',
            'save_communication_setup',
            'save_email_assistant_setup',
            'save_whatsapp_assistant_setup',
            'save_sms_channel_setup',
            'save_calendar_meetings_setup',
            'save_hr_analytics_setup',
            'create_organization_function',
            'update_organization_function',
            'save_ai_api_setup',
            'save_ai_coach_setup',
            'save_ai_coach_personal_brief',
            'save_finance_setup',
            'save_social_media_brand_setup',
            'save_social_media_publishing_setup',
            'generate_smart_templates',
            'run_marketplace_readiness_check',
            'run_communication_channel_test',
            'disconnect_communication_email',
            'clear_email_assistant_mail',
            'send_email_assistant_test_digest',
            'send_whatsapp_assistant_test_digest',
            'send_sms_channel_test',
            'disconnect_email_assistant_gmail',
            'refresh_plugin_health',
            'complete_setup_step',
            'skip_setup_step',
            'reset_setup_step',
        ];
        if ($skillKey !== '' && !in_array($action, $catalogStatusActions, true)) {
            if ($catalog->isGloballyDeactivated($skillKey)) {
                throw new \RuntimeException('This Marketplace module is globally deactivated. Reactivate it in the superadmin catalog editor before workspace actions can run.');
            }
            if (
                in_array($action, $hiddenBlockedActions, true)
                && $catalog->isGloballyHidden($skillKey)
            ) {
                throw new \RuntimeException('This Marketplace module is hidden from workspace actions. Make it visible in the superadmin catalog editor before continuing.');
            }
            if ($action === 'install') {
                $marketplaceAccess->assertCanInstall($workspaceId, $userId, $skillKey);
            } elseif (in_array($action, $hiddenBlockedActions, true)) {
                $actionAccess = $marketplaceAccess->accessForModule($workspaceId, $userId, $skillKey);
                if (!empty($actionAccess['is_locked'])) {
                    throw new \RuntimeException((string) ($actionAccess['message'] ?? 'This Marketplace module is locked until prerequisite setup is complete.') . ' Next action: ' . (string) ($actionAccess['next_action_label'] ?? 'Open the prerequisite setup') . '.');
                }
            }
        }
        $actionRecommendation = null;
        foreach ($recommender->recommendationsForWorkspace($workspaceId, $userId) as $candidate) {
            if ((string) ($candidate['skill_key'] ?? '') === $skillKey) {
                $actionRecommendation = $candidate;
                break;
            }
        }
        $actionAdaptiveMetadata = [
            'base_score' => isset($actionRecommendation['base_score']) ? (int) $actionRecommendation['base_score'] : null,
            'adaptive_score_delta' => isset($actionRecommendation['adaptive_score_delta']) ? (int) $actionRecommendation['adaptive_score_delta'] : null,
            'adaptive_reason_codes' => array_values(array_filter(array_map('strval', (array) ($actionRecommendation['adaptive_reason_codes'] ?? [])))),
            'adaptive_confidence' => (string) ($actionRecommendation['adaptive_confidence'] ?? ''),
        ];
        $actionActivationBundles = $activationBundles->bundlesForWorkspace($workspaceId, $userId, 0, 'marketplace');
        $actionActivationBundle = $bundleKey !== '' ? $bundleByKey($actionActivationBundles, $bundleKey) : null;
        if ($action === 'delete_all_marketplace_videos') {
            if (!$canEditMarketplaceCatalog) {
                throw new \RuntimeException('Only superadmins can delete Marketplace videos.');
            }
            if ($marketplacePostString('marketplace_video_delete_confirmation') !== 'DELETE VIDEOS') {
                throw new \RuntimeException('Type DELETE VIDEOS to confirm deleting all Marketplace videos.');
            }

            $purgeReport = $marketplaceUploadCleanup->purgeAllVideos($userId);
            $purgeSummary = (array) ($purgeReport['summary'] ?? []);
            if ((int) ($purgeSummary['errors'] ?? 0) > 0) {
                $firstError = (array) ((array) ($purgeReport['errors'] ?? []))[0];
                throw new \RuntimeException((string) ($firstError['message'] ?? 'Marketplace video deletion failed.'));
            }

            $success = 'Marketplace videos deleted. Removed '
                . (int) ($purgeSummary['deleted_files'] ?? 0)
                . ' local video file(s), freed '
                . $marketplaceFormatBytes((int) ($purgeSummary['deleted_bytes'] ?? 0))
                . ', cleared '
                . (int) ($purgeSummary['cleared_catalog_video_refs'] ?? 0)
                . ' catalog video reference(s), '
                . (int) ($purgeSummary['cleared_bundle_video_refs'] ?? 0)
                . ' bundle video reference(s), and '
                . (int) ($purgeSummary['cleared_page_video_refs'] ?? 0)
                . ' page guide video reference(s).';
        } elseif (in_array($action, ['save_activation_bundle_definition', 'toggle_activation_bundle_definition', 'archive_activation_bundle_definition', 'delete_activation_bundle_definition'], true)) {
            if (!$canEditMarketplaceCatalog) {
                throw new \RuntimeException('Only superadmins can manage Marketplace activation bundles.');
            }

            if ($action === 'save_activation_bundle_definition') {
                $currentBundleKey = preg_replace('/[^a-z0-9_]+/', '_', strtolower($marketplacePostString('current_bundle_key'))) ?? '';
                $currentBundleKey = trim($currentBundleKey, '_');
                $postedBundleKey = preg_replace('/[^a-z0-9_]+/', '_', strtolower($marketplacePostString('bundle_key'))) ?? '';
                $postedBundleKey = trim($postedBundleKey, '_');
                $bundleUploadKey = $currentBundleKey !== '' ? $currentBundleKey : ($postedBundleKey !== '' ? $postedBundleKey : 'activation_bundle');
                $existingBundleKey = $currentBundleKey !== '' ? $currentBundleKey : $postedBundleKey;
                $existingDefinition = null;
                foreach ($activationBundles->allDefinitionsForAdmin() as $candidateDefinition) {
                    if ((string) ($candidateDefinition['bundle_key'] ?? '') === $existingBundleKey) {
                        $existingDefinition = $candidateDefinition;
                        break;
                    }
                }
                $bundleThumbnailPath = $marketplaceUploadImage('bundle_thumbnail_file', $bundleUploadKey);
                $bundleBannerPath = $marketplaceUploadImage('bundle_banner_file', $bundleUploadKey);
                $bundleVideoPath = $marketplaceUploadVideo('bundle_video_file', $bundleUploadKey);
                $definition = $activationBundles->saveDefinition([
                    'current_bundle_key' => $marketplacePostString('current_bundle_key'),
                    'bundle_key' => $marketplacePostString('bundle_key'),
                    'label' => $marketplacePostString('bundle_label'),
                    'summary' => $marketplacePostString('bundle_summary'),
                    'included_skill_keys' => (array) ($_POST['bundle_included_skill_keys'] ?? []),
                    'thumbnail_url' => $bundleThumbnailPath !== '' ? $bundleThumbnailPath : (string) ($existingDefinition['thumbnail_url'] ?? ''),
                    'banner_url' => $bundleBannerPath !== '' ? $bundleBannerPath : (string) ($existingDefinition['banner_url'] ?? ''),
                    'explainer_video_url' => $bundleVideoPath !== '' ? $bundleVideoPath : (string) ($existingDefinition['explainer_video_url'] ?? ''),
                    'why_this_bundle' => $marketplacePostString('bundle_why_this_bundle'),
                    'expected_outcome' => $marketplacePostString('bundle_expected_outcome'),
                    'display_order' => $marketplacePostInt('bundle_display_order', 100, 0, 100000),
                    'is_active' => $marketplacePostBool('bundle_is_active'),
                ], $userId);
                $success = 'Marketplace activation bundle saved: ' . (string) ($definition['label'] ?? 'Activation bundle') . '.';
            } elseif ($action === 'toggle_activation_bundle_definition') {
                $active = $marketplacePostBool('bundle_definition_active');
                $activationBundles->setDefinitionActive($bundleKey, $active, $userId);
                $success = $active ? 'Marketplace activation bundle activated.' : 'Marketplace activation bundle deactivated.';
            } elseif ($action === 'archive_activation_bundle_definition') {
                $activationBundles->archiveDefinition($bundleKey, $userId);
                $success = 'Marketplace activation bundle archived.';
            } else {
                $activationBundles->deleteDefinition($bundleKey);
                $success = 'Marketplace activation bundle deleted.';
            }
        } elseif ($action === 'create_custom_skill') {
            $created = $catalog->createCustomSkill($workspaceId, $userId, [
                'label' => $marketplacePostString('custom_skill_label'),
                'summary' => $marketplacePostString('custom_skill_summary'),
                'category' => $marketplacePostString('custom_skill_category', 'custom'),
                'advice_domains' => $marketplacePostTokens('custom_skill_advice_domains'),
                'context_fields' => $marketplacePostContextFields('custom_skill_context_fields'),
                'task_templates' => $marketplacePostLines('custom_skill_task_templates'),
                'routing_examples' => $marketplacePostLines('custom_skill_routing_examples'),
                'guidance_instructions' => $marketplacePostString('custom_skill_guidance_instructions'),
            ]);
            $success = 'Custom skill created: ' . (string) ($created['label'] ?? 'Workspace skill') . '.';
            $skillKey = (string) ($created['key'] ?? $skillKey);
        } elseif ($action === 'update_custom_skill') {
            $updated = $catalog->updateCustomSkill($workspaceId, $skillKey, $userId, [
                'label' => $marketplacePostString('custom_skill_label'),
                'summary' => $marketplacePostString('custom_skill_summary'),
                'category' => $marketplacePostString('custom_skill_category', 'custom'),
                'advice_domains' => $marketplacePostTokens('custom_skill_advice_domains'),
                'context_fields' => $marketplacePostContextFields('custom_skill_context_fields'),
                'task_templates' => $marketplacePostLines('custom_skill_task_templates'),
                'routing_examples' => $marketplacePostLines('custom_skill_routing_examples'),
                'guidance_instructions' => $marketplacePostString('custom_skill_guidance_instructions'),
            ]);
            $success = 'Custom skill updated: ' . (string) ($updated['label'] ?? 'Workspace skill') . '.';
        } elseif ($action === 'archive_custom_skill') {
            $catalog->archiveCustomSkill($workspaceId, $skillKey);
            if ($installer->isInstalled($workspaceId, $skillKey)) {
                $installer->uninstall($workspaceId, $skillKey, $userId);
            }
            $success = 'Custom skill archived.';
        } elseif ($action === 'save_skill_context') {
            $installer->saveSkillContext($workspaceId, $skillKey, $userId, (array) ($_POST['skill_context'] ?? []));
            $success = 'Skill context saved.';
        } elseif (in_array($action, ['select_activation_bundle', 'dismiss_activation_bundle', 'complete_activation_bundle'], true)) {
            $bundleStatus = match ($action) {
                'select_activation_bundle' => 'selected',
                'dismiss_activation_bundle' => 'dismissed',
                default => 'completed',
            };
            $activationBundles->updateState($workspaceId, $userId, $bundleKey, $bundleStatus, [
                'source' => 'workspace_marketplace_page',
                'bundle_key' => $bundleKey,
                'status' => $bundleStatus,
            ]);
            $success = match ($bundleStatus) {
                'selected' => 'Activation bundle selected.',
                'dismissed' => 'Activation bundle dismissed.',
                default => 'Activation bundle marked complete.',
            };
            $activationBundleEvents->recordEvent($workspaceId, $userId, $bundleKey, $bundleStatus, [
                'bundle' => $actionActivationBundle ?? [],
                'bundle_status' => $bundleStatus,
                'metadata' => [
                    'source' => 'workspace_marketplace_page',
                    'label' => (string) ($actionActivationBundle['label'] ?? $bundleKey),
                    'next_action_skill_key' => (string) ($actionActivationBundle['next_action']['skill_key'] ?? ''),
                ],
            ]);
        } elseif (in_array($action, ['pin_recommendation', 'unpin_recommendation', 'mute_recommendation', 'unmute_recommendation', 'disable_recommendation_surface', 'enable_recommendation_surface'], true)) {
            $surface = (string) ($_POST['control_surface'] ?? 'all');
            if ($action === 'pin_recommendation' || $action === 'unpin_recommendation') {
                $recommendationControls->setControl($workspaceId, $userId, $skillKey, 'pinned', 'all', $action === 'pin_recommendation', 'marketplace_page', [
                    'source' => 'workspace_marketplace_page',
                    'label' => (string) ($actionRecommendation['label'] ?? $skillKey),
                    'control_type' => 'pinned',
                ]);
                $success = $action === 'pin_recommendation' ? 'Recommendation pinned.' : 'Recommendation unpinned.';
            } elseif ($action === 'mute_recommendation' || $action === 'unmute_recommendation') {
                $recommendationControls->setControl($workspaceId, $userId, $skillKey, 'muted', 'all', $action === 'mute_recommendation', 'marketplace_page', [
                    'source' => 'workspace_marketplace_page',
                    'label' => (string) ($actionRecommendation['label'] ?? $skillKey),
                    'control_type' => 'muted',
                ]);
                $success = $action === 'mute_recommendation' ? 'Recommendation muted.' : 'Recommendation unmuted.';
            } else {
                $recommendationControls->setControl($workspaceId, $userId, $skillKey, 'surface_disabled', $surface, $action === 'disable_recommendation_surface', 'marketplace_page', [
                    'source' => 'workspace_marketplace_page',
                    'surface' => $surface,
                    'label' => (string) ($actionRecommendation['label'] ?? $skillKey),
                    'control_type' => 'surface_disabled',
                ]);
                $success = $action === 'disable_recommendation_surface' ? 'Recommendation surface hidden.' : 'Recommendation surface restored.';
            }
        } elseif ($action === 'save_lean_canvas_setup') {
            if ($skillKey !== WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS) {
                throw new \RuntimeException('Lean Canvas setup could not be matched to a marketplace skill.');
            }
            $profile = new UserStrategyProfile();
            $profile->save($userId, array_merge($profile->get($userId) ?: [], [
                'lean_problem' => $marketplacePostString('lean_problem'),
                'lean_customer_segments' => $marketplacePostString('lean_customer_segments'),
                'lean_unique_value_proposition' => $marketplacePostString('lean_unique_value_proposition'),
                'lean_solution' => $marketplacePostString('lean_solution'),
                'lean_channels' => $marketplacePostString('lean_channels'),
                'lean_revenue_streams' => $marketplacePostString('lean_revenue_streams'),
                'lean_cost_structure' => $marketplacePostString('lean_cost_structure'),
                'lean_key_metrics' => $marketplacePostString('lean_key_metrics'),
                'lean_unfair_advantage' => $marketplacePostString('lean_unfair_advantage'),
            ]));
            if (!$installer->isInstalled($workspaceId, WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS)) {
                $installer->install($workspaceId, WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS, $userId, ['source' => 'workspace_marketplace_setup']);
            }
            $setupJourneyEvents->recordEvent($workspaceId, $userId, $skillKey, 'setup_saved', [
                'label' => 'Lean Canvas setup saved',
                'source' => 'workspace_marketplace_page',
                'metadata' => ['label' => 'Lean Canvas setup saved'],
            ]);
            $success = 'Lean Canvas setup saved.';
        } elseif ($action === 'save_professional_marketer_setup') {
            if (!in_array($skillKey, [WorkspaceSkillCatalogService::SKILL_PROFESSIONAL_MARKETER, WorkspaceSkillCatalogService::PLUGIN_MARKETING_PRO], true)) {
                throw new \RuntimeException('Campaign Manager setup could not be matched to a marketplace plugin.');
            }
            $profile = new UserStrategyProfile();
            $profile->save($userId, array_merge($profile->get($userId) ?: [], [
                'target_market_focus' => $marketplacePostString('target_market_focus'),
                'ideal_customer_profile' => $marketplacePostString('ideal_customer_profile'),
                'offer_angle' => $marketplacePostString('offer_angle'),
                'segment_focus' => $marketplacePostString('segment_focus'),
                'sales_motion' => $marketplacePostString('sales_motion'),
                'deal_movement_strategy' => $marketplacePostString('deal_movement_strategy'),
                'outreach_posture' => $marketplacePostString('outreach_posture'),
                'positioning_notes' => $marketplacePostString('positioning_notes'),
                'market_view' => $marketplacePostString('market_view'),
                'strategy_hypothesis' => $marketplacePostString('strategy_hypothesis'),
            ]));
            (new UserStrategySnapshot())->syncForUser($workspaceId, $userId);
            if (!$installer->isInstalled($workspaceId, $skillKey)) {
                $installer->install($workspaceId, $skillKey, $userId, ['source' => 'workspace_marketplace_setup']);
            }
            $setupJourneyEvents->recordEvent($workspaceId, $userId, $skillKey, 'setup_saved', [
                'label' => 'Campaign Manager setup saved',
                'source' => 'workspace_marketplace_page',
                'metadata' => ['label' => 'Campaign Manager setup saved'],
            ]);
            $success = 'Campaign Manager setup saved.';
        } elseif (in_array($action, ['save_social_media_brand_setup', 'save_social_media_publishing_setup'], true)) {
            if ($skillKey !== WorkspaceSkillCatalogService::PLUGIN_SOCIAL_MEDIA) {
                throw new \RuntimeException('Social Media setup could not be matched to the installed plugin.');
            }
            $socialMedia = new SocialMediaService($workspaceId);
            $currentSocialSettings = $socialMedia->getSettings();
            if ($action === 'save_social_media_brand_setup') {
                $updatedSocialSettings = array_merge($currentSocialSettings, [
                    'brand_name' => $marketplacePostString('social_brand_name'),
                    'brand_voice' => $marketplacePostString('social_brand_voice'),
                    'target_audience' => $marketplacePostString('social_target_audience'),
                    'products_services' => $marketplacePostString('social_products_services'),
                    'approved_claims' => $marketplacePostString('social_approved_claims'),
                    'prohibited_claims' => $marketplacePostString('social_prohibited_claims'),
                    'preferred_cta' => $marketplacePostString('social_preferred_cta'),
                ]);
                $success = 'Social Media brand guardrails saved.';
            } else {
                $updatedSocialSettings = array_merge($currentSocialSettings, [
                    'enabled' => $marketplacePostBool('social_enabled'),
                    'approval_required' => $marketplacePostBool('social_approval_required'),
                    'metrics_sync_enabled' => $marketplacePostBool('social_metrics_sync_enabled'),
                    'timezone' => $marketplacePostString('social_timezone', 'Africa/Nairobi'),
                    'default_utm_source' => $marketplacePostString('social_default_utm_source', 'social'),
                    'default_utm_medium' => $marketplacePostString('social_default_utm_medium', 'organic_social'),
                ]);
                $success = 'Social Media publishing policy saved.';
            }
            $socialMedia->saveSettings($updatedSocialSettings, $userId);
        } elseif (str_starts_with($action, 'verify_social_media_account:')) {
            if ($skillKey !== WorkspaceSkillCatalogService::PLUGIN_SOCIAL_MEDIA) {
                throw new \RuntimeException('Social Media account action could not be matched to the installed plugin.');
            }
            $accountId = (int) substr($action, strlen('verify_social_media_account:'));
            (new SocialMediaService($workspaceId))->verifyAccount($accountId, $userId);
            $success = 'Social Media destination verified.';
        } elseif (str_starts_with($action, 'disconnect_social_media_account:')) {
            if ($skillKey !== WorkspaceSkillCatalogService::PLUGIN_SOCIAL_MEDIA) {
                throw new \RuntimeException('Social Media account action could not be matched to the installed plugin.');
            }
            $accountId = (int) substr($action, strlen('disconnect_social_media_account:'));
            (new SocialMediaService($workspaceId))->disconnectAccount($accountId, $userId);
            $success = 'Social Media destination disconnected.';
        } elseif ($action === 'save_catalog_status') {
            if (!$canEditMarketplaceCatalog) {
                throw new \RuntimeException('Only superadmins can change Marketplace catalog availability.');
            }
            $updated = $catalog->setCatalogStatus($skillKey, $marketplacePostString('catalog_status'), $userId);
            $success = 'Marketplace availability saved: '
                . $marketplaceCatalogStatusLabel((string) ($updated['catalog_status'] ?? WorkspaceSkillCatalogService::CATALOG_STATUS_VISIBLE))
                . '.';
        } elseif ($action === 'draft_catalog_copy') {
            if (!$canEditMarketplaceCatalog) {
                throw new \RuntimeException('Only superadmins can draft Marketplace catalog content.');
            }
            $currentModule = $catalog->find($skillKey, true);
            if ($currentModule === null) {
                throw new \RuntimeException('Marketplace module not found.');
            }
            $currentProfile = (array) ($currentModule['plugin_metadata']['marketplace_profile'] ?? []);
            $catalogEditorDraftByKey[$skillKey] = $catalogCopy->draft($workspaceId, $userId, $currentModule, [
                'label' => $marketplacePostString('catalog_label', (string) ($currentModule['label'] ?? '')),
                'summary' => $marketplacePostString('catalog_summary', (string) ($currentModule['summary'] ?? '')),
                'pitch' => $marketplacePostString('catalog_pitch', (string) ($currentProfile['pitch'] ?? '')),
                'thumbnail_alt' => $marketplacePostString('catalog_thumbnail_alt', (string) ($currentProfile['thumbnail_alt'] ?? '')),
                'banner_alt' => $marketplacePostString('catalog_banner_alt', (string) ($currentProfile['banner_alt'] ?? '')),
                'overview_brief_content' => $marketplacePostString('catalog_overview_brief_content', (string) ($currentProfile['overview_brief_content'] ?? '')),
                'overview_brief_format' => $marketplacePostString('catalog_overview_brief_format', (string) ($currentProfile['overview_brief_format'] ?? 'text')),
                'overview_deep_dive_content' => $marketplacePostString('catalog_overview_deep_dive_content', (string) ($currentProfile['overview_deep_dive_content'] ?? '')),
                'overview_deep_dive_format' => $marketplacePostString('catalog_overview_deep_dive_format', (string) ($currentProfile['overview_deep_dive_format'] ?? 'text')),
            ]);
            $success = 'Clarity draft ready. Review the updated fields, then save catalog content.';
        } elseif (in_array($action, ['save_catalog_override', 'save_catalog_media'], true)) {
            if (!$canEditMarketplaceCatalog) {
                throw new \RuntimeException('Only superadmins can edit Marketplace catalog content.');
            }
            $currentModule = $catalog->find($skillKey, true);
            if ($currentModule === null) {
                throw new \RuntimeException('Marketplace module not found.');
            }
            $currentProfile = (array) ($currentModule['plugin_metadata']['marketplace_profile'] ?? []);
            $currentRequiresSetup = !empty($currentModule['settings_schema']['requires_configuration']);
            $mediaOnlySave = $action === 'save_catalog_media';
            $thumbnailPath = $marketplaceUploadImage('marketplace_thumbnail_file', $skillKey);
            $bannerPath = $marketplaceUploadImage('marketplace_banner_file', $skillKey);
            $videoPath = $marketplaceUploadVideo('marketplace_explainer_video_file', $skillKey);
            $setupVideoPath = $currentRequiresSetup ? $marketplaceUploadVideo('marketplace_setup_video_file', $skillKey) : '';
            $postedVideoUrl = $marketplacePostString('catalog_explainer_video_url');
            if ($postedVideoUrl !== '' && preg_match('#^https?://#i', $postedVideoUrl) !== 1) {
                throw new \RuntimeException('Explainer video URL must start with http:// or https://.');
            }
            $explainerVideoUrl = (string) ($currentProfile['explainer_video_url'] ?? '');
            $setupVideoUrl = (string) ($currentProfile['setup_video_url'] ?? '');
            $setupVideoUploadedAt = (string) ($currentProfile['setup_video_uploaded_at'] ?? '');
            $mediaChanges = [];
            $removeModuleExplainer = $marketplacePostBool('catalog_remove_explainer_video');
            $removeSetupVideo = $currentRequiresSetup && $marketplacePostBool('catalog_remove_setup_video');
            if ($removeModuleExplainer) {
                $explainerVideoUrl = '';
                $mediaChanges[] = 'explainer video removed';
            }
            if ($removeSetupVideo) {
                $setupVideoUrl = '';
                $setupVideoUploadedAt = '';
                $mediaChanges[] = 'setup video removed';
            }
            if ($postedVideoUrl !== '') {
                $explainerVideoUrl = $postedVideoUrl;
                $mediaChanges[] = 'explainer video URL saved';
            }
            if ($videoPath !== '') {
                $explainerVideoUrl = $videoPath;
                $mediaChanges[] = 'explainer video uploaded';
            }
            if ($setupVideoPath !== '') {
                $setupVideoUrl = $setupVideoPath;
                $setupVideoUploadedAt = gmdate('c');
                $mediaChanges[] = 'setup video uploaded';
            }
            if ($thumbnailPath !== '') {
                $mediaChanges[] = 'thumbnail uploaded';
            }
            if ($bannerPath !== '') {
                $mediaChanges[] = 'banner uploaded';
            }
            $catalogPageExplainerKeys = match ($skillKey) {
                WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS => [
                    MarketplacePageExplainerService::PAGE_STARTUP_JOURNEY,
                    MarketplacePageExplainerService::PAGE_FOUNDER_OPERATING_LOOP,
                ],
                WorkspaceSkillCatalogService::PLUGIN_FINANCE => [
                    MarketplacePageExplainerService::PAGE_FINANCE,
                ],
                default => [],
            };
            if ($catalogPageExplainerKeys !== []) {
                foreach ($catalogPageExplainerKeys as $pageExplainerKey) {
                    $pageConfig = (array) ($marketplacePageExplainerConfig[$pageExplainerKey] ?? []);
                    $pageRemoved = !empty($pageConfig['remove_field']) && $marketplacePostBool((string) $pageConfig['remove_field']);
                    if ($pageConfig !== [] && $marketplaceSavePageExplainerVideo($pageConfig)) {
                        $mediaChanges[] = (string) ($pageConfig['short_label'] ?? $pageConfig['label'] ?? 'page guide') . ' uploaded';
                    } elseif ($pageRemoved) {
                        $mediaChanges[] = (string) ($pageConfig['short_label'] ?? $pageConfig['label'] ?? 'page guide') . ' removed';
                    }
                }
            }
            $explainerOrientation = $marketplacePostString('catalog_explainer_orientation', (string) ($currentProfile['explainer_orientation'] ?? 'landscape'));
            $explainerOrientation = $explainerOrientation === 'portrait' ? 'portrait' : 'landscape';
            if ($mediaOnlySave) {
                $catalog->saveCatalogOverride($skillKey, [
                    'thumbnail_url' => $thumbnailPath !== '' ? $thumbnailPath : (string) ($currentProfile['thumbnail_url'] ?? ''),
                    'thumbnail_alt' => (string) ($currentProfile['thumbnail_alt'] ?? ''),
                    'banner_url' => $bannerPath !== '' ? $bannerPath : (string) ($currentProfile['banner_url'] ?? ''),
                    'banner_alt' => (string) ($currentProfile['banner_alt'] ?? ''),
                    'explainer_video_url' => $explainerVideoUrl,
                    'explainer_orientation' => $explainerOrientation,
                    'setup_video_url' => $setupVideoUrl,
                    'setup_video_uploaded_at' => $setupVideoUploadedAt,
                    'catalog_status' => $marketplacePostString('catalog_status', (string) ($currentModule['catalog_status'] ?? WorkspaceSkillCatalogService::CATALOG_STATUS_VISIBLE)),
                ], $userId);
                $success = $mediaChanges === []
                    ? 'Catalog media saved. Existing media selections preserved.'
                    : 'Catalog media saved with ' . implode(', ', $mediaChanges) . '.';
            } else {
                $catalog->saveCatalogOverride($skillKey, [
                    'label' => $marketplacePostString('catalog_label', (string) ($currentModule['label'] ?? '')),
                    'summary' => $marketplacePostString('catalog_summary', (string) ($currentModule['summary'] ?? '')),
                    'pitch' => $marketplacePostString('catalog_pitch', (string) ($currentProfile['pitch'] ?? '')),
                    'thumbnail_url' => $thumbnailPath !== '' ? $thumbnailPath : (string) ($currentProfile['thumbnail_url'] ?? ''),
                    'thumbnail_alt' => $marketplacePostString('catalog_thumbnail_alt', (string) ($currentProfile['thumbnail_alt'] ?? '')),
                    'banner_url' => $bannerPath !== '' ? $bannerPath : (string) ($currentProfile['banner_url'] ?? ''),
                    'banner_alt' => $marketplacePostString('catalog_banner_alt', (string) ($currentProfile['banner_alt'] ?? '')),
                    'explainer_video_url' => $explainerVideoUrl,
                    'explainer_orientation' => $explainerOrientation,
                    'setup_video_url' => $setupVideoUrl,
                    'setup_video_uploaded_at' => $setupVideoUploadedAt,
                    'overview_brief_content' => $marketplacePostString('catalog_overview_brief_content', (string) ($currentProfile['overview_brief_content'] ?? '')),
                    'overview_brief_format' => $marketplacePostString('catalog_overview_brief_format', (string) ($currentProfile['overview_brief_format'] ?? 'text')),
                    'overview_deep_dive_content' => $marketplacePostString('catalog_overview_deep_dive_content', (string) ($currentProfile['overview_deep_dive_content'] ?? '')),
                    'overview_deep_dive_format' => $marketplacePostString('catalog_overview_deep_dive_format', (string) ($currentProfile['overview_deep_dive_format'] ?? 'text')),
                    'catalog_status' => $marketplacePostString('catalog_status', (string) ($currentModule['catalog_status'] ?? WorkspaceSkillCatalogService::CATALOG_STATUS_VISIBLE)),
                ], $userId);
                $success = $mediaChanges === []
                    ? 'Marketplace catalog content saved.'
                    : 'Marketplace catalog content saved. Updated media: ' . implode(', ', $mediaChanges) . '.';
            }
        } elseif ($action === 'reset_catalog_override') {
            if (!$canEditMarketplaceCatalog) {
                throw new \RuntimeException('Only superadmins can reset Marketplace catalog content.');
            }
            $catalog->resetCatalogOverride($skillKey);
            $success = 'Marketplace catalog content reset to defaults.';
        } elseif ($action === 'save_communication_setup') {
            $communicationChannel = strtolower($marketplacePostString('communication_channel', 'email'));
            $allowedCommunicationSetupKeys = [
                WorkspaceSkillCatalogService::PLUGIN_EMAIL,
                WorkspaceSkillCatalogService::PLUGIN_WHATSAPP,
            ];
            if (!in_array($skillKey, $allowedCommunicationSetupKeys, true)) {
                throw new \RuntimeException('Channel setup could not be matched to a marketplace plugin.');
            }
            if ($communicationChannel === 'whatsapp' && $skillKey !== WorkspaceSkillCatalogService::PLUGIN_WHATSAPP) {
                throw new \RuntimeException('Open WhatsApp setup before saving WhatsApp settings.');
            }
            if ($communicationChannel !== 'whatsapp' && $skillKey !== WorkspaceSkillCatalogService::PLUGIN_EMAIL) {
                throw new \RuntimeException('Open Email setup before saving email settings.');
            }
            if (!$installer->isInstalled($workspaceId, $skillKey)) {
                throw new \RuntimeException('Install this channel module before saving settings.');
            }

            if ($communicationChannel === 'whatsapp') {
                $hubResult = $whatsAppSettingsHub->handleWhatsAppHubPost($workspaceId, $userId, $user, $_POST, 'marketplace');
                $_GET['module'] = (string) ($hubResult['setup_module'] ?? $skillKey);
                $_GET['setup_tab'] = (string) ($hubResult['setup_tab'] ?? 'manual');
                $success = (string) ($hubResult['success'] ?? 'WhatsApp settings saved.');
            } else {
                $emailRole = strtolower($marketplacePostString('email_role', 'outreach'));
                if (!in_array($emailRole, ['outreach', 'nurture', 'assistant'], true)) {
                    throw new \RuntimeException('Choose outreach, nurture, or email assistant before saving email settings.');
                }
                if ($skillKey === WorkspaceSkillCatalogService::PLUGIN_EMAIL && $emailRole === 'assistant') {
                    throw new \RuntimeException('Open Email Assistant setup before saving assistant email settings.');
                }
                $_GET['module'] = $skillKey;
                $_GET['setup_tab'] = match ($emailRole) {
                    'nurture' => 'nurture_email',
                    'assistant' => 'assistant_email',
                    default => 'outreach_email',
                };
                $emailPayload = [
                    'smtp_host' => $marketplacePostString('smtp_host'),
                    'smtp_port' => (string) $marketplacePostInt('smtp_port', 587, 1, 65535),
                    'smtp_username' => $marketplacePostString('smtp_username'),
                    'smtp_password' => $marketplacePostString('smtp_password'),
                    'smtp_encryption' => $marketplacePostString('smtp_encryption', 'tls'),
                    'from_email' => $marketplacePostString('from_email'),
                    'from_name' => $marketplacePostString('from_name'),
                    'imap_enabled' => $marketplacePostBool('imap_enabled'),
                    'imap_host' => $marketplacePostString('imap_host'),
                    'imap_port' => (string) $marketplacePostInt('imap_port', 993, 1, 65535),
                    'imap_protocol' => 'imap',
                    'imap_encryption' => $marketplacePostString('imap_encryption', 'ssl'),
                    'imap_username' => $marketplacePostString('imap_username'),
                    'imap_password' => $marketplacePostString('imap_password'),
                    'imap_folder' => $marketplacePostString('imap_folder', 'INBOX'),
                ];
                if ($emailPayload['smtp_username'] === '' && $emailPayload['from_email'] !== '') {
                    $emailPayload['smtp_username'] = $emailPayload['from_email'];
                }
                if (!empty($emailPayload['imap_enabled']) && $emailPayload['imap_username'] === '' && $emailPayload['from_email'] !== '') {
                    $emailPayload['imap_username'] = $emailPayload['from_email'];
                }
                $postedMailboxIdentityFields = [
                    'from_email',
                    'from_name',
                    'smtp_host',
                    'smtp_username',
                    'smtp_password',
                    'imap_host',
                    'imap_username',
                    'imap_password',
                ];
                $postedMailboxBlank = !$emailPayload['imap_enabled'];
                foreach ($postedMailboxIdentityFields as $fieldName) {
                    if (trim((string) ($_POST[$fieldName] ?? '')) !== '') {
                        $postedMailboxBlank = false;
                        break;
                    }
                }

                if ($emailRole === 'assistant') {
                    $currentEmailConfig = $assistantConfig->get($workspaceId, 'email', true);
                    $currentEmailSettings = (array) ($currentEmailConfig['settings'] ?? []);
                    $assistantConfig->save($workspaceId, 'email', array_merge($currentEmailSettings, [
                        'system_email' => $emailPayload['from_email'] !== '' ? $emailPayload['from_email'] : (string) ($currentEmailSettings['system_email'] ?? ''),
                        'smtp_host' => $emailPayload['smtp_host'],
                        'smtp_port' => $emailPayload['smtp_port'],
                        'smtp_username' => $emailPayload['smtp_username'],
                        'smtp_password' => $marketplacePreserveSecret('smtp_password', 'smtp_password', $currentEmailSettings),
                        'smtp_encryption' => $emailPayload['smtp_encryption'],
                        'from_email' => $emailPayload['from_email'],
                        'from_name' => $emailPayload['from_name'],
                        'imap_enabled' => $emailPayload['imap_enabled'],
                        'imap_host' => $emailPayload['imap_host'],
                        'imap_port' => $emailPayload['imap_port'],
                        'imap_protocol' => 'imap',
                        'imap_encryption' => $emailPayload['imap_encryption'],
                        'imap_username' => $emailPayload['imap_username'],
                        'imap_password' => $marketplacePreserveSecret('imap_password', 'imap_password', $currentEmailSettings),
                        'imap_folder' => $emailPayload['imap_folder'],
                    ]), true, $userId);
                } elseif ($postedMailboxBlank) {
                    $clearScope = $emailRole === 'nurture'
                        ? EmailIntegrationService::SCOPE_NURTURE_EMAIL
                        : EmailIntegrationService::SCOPE_OUTREACH_EMAIL;
                    $emailIntegrations->clearScopeIntegration($clearScope, $workspaceId);
                    $setupJourneyEvents->recordEvent($workspaceId, $userId, $skillKey, 'step_reset', [
                        'step_key' => $emailRole === 'nurture' ? 'test_nurture_email' : 'test_outreach_email',
                        'label' => ucfirst($emailRole) . ' Email cleared',
                        'source' => 'workspace_marketplace_page',
                        'metadata' => ['label' => ucfirst($emailRole) . ' Email cleared', 'role' => $emailRole],
                    ]);
                    $success = ucfirst($emailRole) . ' Email disconnected because no mailbox settings were provided.';
                } else {
                    $emailIntegrations->storeManualMailIntegrationForRole($emailRole, $userId, $emailPayload, $workspaceId);
                }

                if ($success === null) {
                    $setupJourneyEvents->recordEvent($workspaceId, $userId, $skillKey, 'setup_saved', [
                        'label' => ucfirst($emailRole) . ' email settings saved',
                        'source' => 'workspace_marketplace_page',
                        'metadata' => ['label' => ucfirst($emailRole) . ' email settings saved', 'channel' => 'email', 'role' => $emailRole],
                    ]);
                    $success = ucfirst($emailRole) . ' email settings saved.';
                }
            }
        } elseif ($action === 'run_communication_channel_test') {
            if (!in_array($skillKey, [
                WorkspaceSkillCatalogService::PLUGIN_EMAIL,
                WorkspaceSkillCatalogService::PLUGIN_WHATSAPP,
            ], true)) {
                throw new \RuntimeException('Channel test could not be matched to a marketplace plugin.');
            }
            if (!$installer->isInstalled($workspaceId, $skillKey)) {
                throw new \RuntimeException('Install this channel module before testing settings.');
            }

            $channelKey = strtolower(trim(preg_replace('/[^a-z0-9_]+/', '_', $marketplacePostString('communication_test_channel')) ?? ''));
            $channelTests = [
                'outreach_email' => ['label' => 'Outreach Email', 'step_key' => 'test_outreach_email'],
                'nurture_email' => ['label' => 'Nurture Email', 'step_key' => 'test_nurture_email'],
                'assistant_email' => ['label' => 'Email Assistant', 'step_key' => 'test_assistant_email'],
                'whatsapp' => ['label' => 'WhatsApp', 'step_key' => 'test_whatsapp'],
            ];
            if (!isset($channelTests[$channelKey])) {
                throw new \RuntimeException('Choose a communication channel before running a test.');
            }
            if ($skillKey === WorkspaceSkillCatalogService::PLUGIN_EMAIL && !in_array($channelKey, ['outreach_email', 'nurture_email'], true)) {
                throw new \RuntimeException('Open WhatsApp or Email Assistant setup before testing that channel.');
            }
            if ($skillKey === WorkspaceSkillCatalogService::PLUGIN_WHATSAPP && $channelKey !== 'whatsapp') {
                throw new \RuntimeException('Open Email setup before testing email settings.');
            }

            $_GET['module'] = $skillKey;
            $_GET['setup_tab'] = $channelKey;

            $latestHealth = $channelHealth->summarize($workspaceId, $user);
            $selectedHealth = (array) ($latestHealth[$channelKey] ?? []);
            $status = (string) ($selectedHealth['status'] ?? 'not_connected');
            $label = (string) ($channelTests[$channelKey]['label'] ?? 'Channel');
            $healthLabel = trim((string) ($selectedHealth['label'] ?? ''));
            $detailCandidates = array_values(array_filter(array_merge(
                array_map('strval', (array) ($selectedHealth['issues'] ?? [])),
                array_map('strval', (array) ($selectedHealth['actions'] ?? [])),
                [$healthLabel]
            ), static fn(string $item): bool => trim($item) !== ''));
            $detail = trim((string) ($detailCandidates[0] ?? ''));
            $passed = in_array($status, ['ready', 'warning'], true);

            $setupJourneyEvents->recordEvent($workspaceId, $userId, $skillKey, $passed ? 'step_completed' : 'step_reset', [
                'step_key' => (string) ($channelTests[$channelKey]['step_key'] ?? 'test_channel'),
                'label' => $label . ' readiness test',
                'source' => 'workspace_marketplace_page',
                'step_status' => $passed ? 'completed' : 'pending',
                'metadata' => [
                    'label' => $label . ' readiness test',
                    'channel' => $channelKey,
                    'status' => $status,
                    'health_label' => $healthLabel,
                    'detail' => $detail,
                ],
            ]);

            if ($status === 'ready') {
                $success = $label . ' readiness test passed.';
            } elseif ($status === 'warning') {
                $success = $label . ' readiness test completed with warnings' . ($detail !== '' ? ': ' . $detail : '') . '.';
            } else {
                $success = $label . ' readiness test needs setup' . ($detail !== '' ? ': ' . $detail : '') . '.';
            }
        } elseif ($action === 'save_email_assistant_setup') {
            if ($skillKey !== WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT) {
                throw new \RuntimeException('Email Assistant setup could not be matched to a marketplace plugin.');
            }
            if (!$installer->isInstalled($workspaceId, WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT)) {
                throw new \RuntimeException('Install Email Assistant before saving setup.');
            }
            $currentEmailConfig = $assistantConfig->get($workspaceId, 'email', true);
            $currentEmailSettings = (array) ($currentEmailConfig['settings'] ?? []);
            $assistantFromEmail = $marketplacePostString('email_assistant_from_email');
            $assistantSmtpUsername = $marketplacePostString('email_assistant_smtp_user');
            $assistantImapEnabled = $marketplacePostBool('email_assistant_imap_enabled');
            $assistantImapUsername = $marketplacePostString('email_assistant_imap_user');
            if ($assistantSmtpUsername === '' && $assistantFromEmail !== '') {
                $assistantSmtpUsername = $assistantFromEmail;
            }
            if ($assistantImapEnabled && $assistantImapUsername === '' && $assistantFromEmail !== '') {
                $assistantImapUsername = $assistantFromEmail;
            }
            $digestRecipientMode = strtolower($marketplacePostString('email_assistant_digest_recipient_mode'));
            $digestRecipients = $digestRecipientMode === 'custom'
                ? $marketplacePostString('email_assistant_digest_custom_emails')
                : $marketplacePostString('email_assistant_digest_recipients', 'admins');
            if ($digestRecipientMode === 'admins' || trim($digestRecipients) === '') {
                $digestRecipients = 'admins';
            }
            $assistantConfig->save($workspaceId, 'email', [
                'system_email' => $marketplacePostString('email_assistant_system_email'),
                'smtp_host' => $marketplacePostString('email_assistant_smtp_host'),
                'smtp_port' => (string) $marketplacePostInt('email_assistant_smtp_port', 587, 1, 65535),
                'smtp_username' => $assistantSmtpUsername,
                'smtp_password' => $marketplacePreserveSecret('email_assistant_smtp_pass', 'smtp_password', $currentEmailSettings),
                'smtp_encryption' => $marketplacePostString('email_assistant_smtp_encryption', 'tls'),
                'from_email' => $assistantFromEmail,
                'from_name' => $marketplacePostString('email_assistant_from_name'),
                'qa_enabled' => $marketplacePostBool('email_assistant_qa_enabled'),
                'instructions_enabled' => $marketplacePostBool('email_assistant_instructions_enabled'),
                'customer_thread_enabled' => $marketplacePostBool('email_assistant_customer_thread_enabled'),
                'customer_send_enabled' => $marketplacePostBool('email_assistant_customer_send_enabled'),
                'default_tone' => $marketplacePostString('email_assistant_default_tone', 'professional'),
                'thread_context_window' => (string) $marketplacePostInt('email_assistant_thread_context_window', 8, 3, 20),
                'min_confidence' => (string) $marketplacePostFloat('email_assistant_min_confidence', 0.65),
                'min_send_confidence' => (string) $marketplacePostFloat('email_assistant_min_send_confidence', 0.8),
                'allow_clarifying_questions' => $marketplacePostBool('email_assistant_allow_clarifying_questions'),
                'activity_logging' => $marketplacePostBool('email_assistant_activity_logging'),
                'allowed_senders' => $marketplacePostString('email_assistant_allowed_senders'),
                'imap_enabled' => $assistantImapEnabled,
                'imap_host' => $marketplacePostString('email_assistant_imap_host'),
                'imap_port' => (string) $marketplacePostInt('email_assistant_imap_port', 993, 1, 65535),
                'imap_protocol' => $marketplacePostString('email_assistant_imap_protocol', 'imap'),
                'imap_encryption' => $marketplacePostString('email_assistant_imap_encryption', 'ssl'),
                'imap_username' => $assistantImapUsername,
                'imap_password' => $marketplacePreserveSecret('email_assistant_imap_pass', 'imap_password', $currentEmailSettings),
                'imap_folder' => $marketplacePostString('email_assistant_imap_folder', 'INBOX'),
                'skill_create_contact' => $marketplacePostBool('email_assistant_skill_create_contact'),
                'skill_update_contact' => $marketplacePostBool('email_assistant_skill_update_contact'),
                'skill_delete_contact' => $marketplacePostBool('email_assistant_skill_delete_contact'),
                'skill_enrich_contact' => $marketplacePostBool('email_assistant_skill_enrich_contact'),
                'skill_verify_email' => $marketplacePostBool('email_assistant_skill_verify_email'),
                'skill_add_note' => $marketplacePostBool('email_assistant_skill_add_note'),
                'skill_get_pipeline' => $marketplacePostBool('email_assistant_skill_get_pipeline'),
                'skill_list_tasks' => $marketplacePostBool('email_assistant_skill_list_tasks'),
                'skill_schedule_event' => $marketplacePostBool('email_assistant_skill_schedule_event'),
                'skill_run_report' => $marketplacePostBool('email_assistant_skill_run_report'),
                'digest_enabled' => $marketplacePostBool('email_assistant_digest_enabled'),
                'digest_time' => $marketplacePostString('email_assistant_digest_time', '07:00'),
                'digest_recipients' => $digestRecipients,
                'reopen_template_enabled' => $marketplacePostBool('email_assistant_reopen_template_enabled'),
                'reopen_template_name' => $marketplacePostString('email_assistant_reopen_template_name'),
                'reopen_template_language' => $marketplacePostString('email_assistant_reopen_template_language', 'en_US'),
                'reopen_template_subject' => $marketplacePostString('email_assistant_reopen_template_subject'),
                'reopen_template_body' => $marketplacePostString('email_assistant_reopen_template_body'),
                'reopen_template_cta_label' => $marketplacePostString('email_assistant_reopen_template_cta_label'),
                'reopen_template_cta_url' => $marketplacePostString('email_assistant_reopen_template_cta_url'),
            ], $marketplacePostBool('email_assistant_enabled'), $userId);
            $setupJourneyEvents->recordEvent($workspaceId, $userId, $skillKey, 'setup_saved', [
                'label' => 'Email Assistant setup saved',
                'source' => 'workspace_marketplace_page',
                'metadata' => ['label' => 'Email Assistant setup saved'],
            ]);
            $success = 'Email Assistant setup saved.';
        } elseif ($action === 'save_whatsapp_assistant_setup') {
            $hubResult = $whatsAppSettingsHub->handleWhatsAppHubPost($workspaceId, $userId, $user, $_POST, 'marketplace');
            $_GET['module'] = (string) ($hubResult['setup_module'] ?? $skillKey);
            $_GET['setup_tab'] = (string) ($hubResult['setup_tab'] ?? 'identity');
            $success = (string) ($hubResult['success'] ?? 'WhatsApp Assistant setup saved.');
        } elseif ($action === 'save_sms_channel_setup') {
            if ($skillKey !== WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL) {
                throw new \RuntimeException('SMS Channel setup could not be matched to a marketplace plugin.');
            }
            if (!$installer->isInstalled($workspaceId, WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL)) {
                throw new \RuntimeException('Install SMS Channel before saving setup.');
            }
            $smsChannelConfig->save($workspaceId, [
                'enabled' => $marketplacePostBool('sms_channel_enabled'),
                'account_sid' => $marketplacePostString('sms_twilio_account_sid'),
                'auth_token' => $marketplacePostString('sms_twilio_auth_token'),
                'from_number' => $marketplacePostString('sms_twilio_from_number'),
                'webhook_enabled' => $marketplacePostBool('sms_webhook_enabled'),
                'status_callbacks_enabled' => $marketplacePostBool('sms_status_callbacks_enabled'),
            ], $userId);
            $setupJourneyEvents->recordEvent($workspaceId, $userId, $skillKey, 'setup_saved', [
                'label' => 'SMS Channel setup saved',
                'source' => 'workspace_marketplace_page',
                'metadata' => ['label' => 'SMS Channel setup saved'],
            ]);
            $success = 'SMS Channel setup saved.';
        } elseif ($action === 'save_voice_call_center_setup') {
            if ($skillKey !== WorkspaceSkillCatalogService::PLUGIN_VOICE_CALL_CENTER) {
                throw new \RuntimeException('Voice setup could not be matched to the Voice & Call Center plugin.');
            }
            if (!$installer->isInstalled($workspaceId, WorkspaceSkillCatalogService::PLUGIN_VOICE_CALL_CENTER)) {
                throw new \RuntimeException('Install Voice & Call Center before saving setup.');
            }
            $voiceSetupTab = $marketplacePostString('voice_setup_tab', 'overview');
            $voiceEditableTabs = ['overview', 'provider', 'team', 'queues', 'ai_consent', 'advanced'];
            if (!in_array($voiceSetupTab, $voiceEditableTabs, true)) {
                throw new \InvalidArgumentException('Choose a valid Voice & Call Center setup section.');
            }
            $voiceConfigTabs = ['overview', 'provider', 'ai_consent', 'advanced'];
            if (in_array($voiceSetupTab, $voiceConfigTabs, true)) {
                $voiceEntitlements = (new WorkspaceVoiceEntitlementService())->assertEnabled($workspaceId);
                $voiceConfig = new WorkspaceVoiceConfigService();
                $existingVoiceConfig = $voiceConfig->get($workspaceId, false);
                $settings = (array) ($existingVoiceConfig['settings'] ?? []);
                $voicePayload = [
                    'enabled' => !empty($existingVoiceConfig['enabled']),
                    'inbound_enabled' => !empty($existingVoiceConfig['inbound_enabled']),
                    'outbound_enabled' => !empty($existingVoiceConfig['outbound_enabled']),
                    'recording_enabled' => !empty($existingVoiceConfig['recording_enabled']),
                    'transcription_enabled' => !empty($existingVoiceConfig['transcription_enabled']),
                    'ai_application_enabled' => !empty($existingVoiceConfig['ai_application_enabled']),
                    'customer_voice_enabled' => !empty($existingVoiceConfig['customer_voice_enabled']),
                    'account_username' => (string) ($existingVoiceConfig['account_username'] ?? ''),
                    'virtual_number' => (string) ($existingVoiceConfig['virtual_number'] ?? ''),
                    'consent_mode' => (string) ($existingVoiceConfig['consent_mode'] ?? 'explicit_keypress'),
                    'consent_notice' => (string) ($existingVoiceConfig['consent_notice'] ?? ''),
                    'automation_policy' => (array) ($existingVoiceConfig['automation_policy'] ?? []),
                    'allowed_country_codes' => (array) ($existingVoiceConfig['allowed_country_codes'] ?? ['+254']),
                    'blocked_prefixes' => (array) ($existingVoiceConfig['blocked_prefixes'] ?? []),
                    'max_call_duration_seconds' => (int) ($existingVoiceConfig['max_call_duration_seconds'] ?? 3600),
                    'hourly_call_limit' => (int) ($existingVoiceConfig['hourly_call_limit'] ?? 60),
                    'daily_minute_limit' => (int) ($existingVoiceConfig['daily_minute_limit'] ?? 1000),
                    'recording_retention_days' => (int) ($existingVoiceConfig['recording_retention_days'] ?? 30),
                    'transcript_retention_days' => (int) ($existingVoiceConfig['transcript_retention_days'] ?? 180),
                    'transcription_model' => (string) ($existingVoiceConfig['transcription_model'] ?? 'gpt-4o-mini-transcribe'),
                    'settings' => $settings,
                ];

                if ($voiceSetupTab === 'overview') {
                    $voicePayload['enabled'] = $marketplacePostBool('voice_enabled');
                    $voicePayload['inbound_enabled'] = $marketplacePostBool('voice_inbound_enabled');
                    $voicePayload['outbound_enabled'] = $marketplacePostBool('voice_outbound_enabled');
                } elseif ($voiceSetupTab === 'provider') {
                    $voicePayload['account_username'] = $marketplacePostString('voice_account_username');
                    $voicePayload['api_key'] = $marketplacePostString('voice_api_key');
                    $voicePayload['virtual_number'] = $marketplacePostString('voice_virtual_number');
                } elseif ($voiceSetupTab === 'ai_consent') {
                    $voicePayload['recording_enabled'] = $marketplacePostBool('voice_recording_enabled');
                    $voicePayload['transcription_enabled'] = $marketplacePostBool('voice_transcription_enabled');
                    $voicePayload['ai_application_enabled'] = $marketplacePostBool('voice_ai_application_enabled');
                    $voicePayload['customer_voice_enabled'] = $marketplacePostBool('voice_customer_voice_enabled');
                    $voicePayload['consent_mode'] = $marketplacePostString('voice_consent_mode', 'explicit_keypress');
                    $voicePayload['consent_notice'] = $marketplacePostString('voice_consent_notice');
                    $voicePayload['compliance_acknowledged'] = $marketplacePostBool('voice_compliance_acknowledged');
                    $voicePayload['automation_policy'] = [
                        'minimum_confidence' => ((float) $marketplacePostInt('voice_automation_minimum_confidence', 80, 50, 100)) / 100,
                        'call_summary' => $marketplacePostString('voice_automation_call_summary', 'automatic'),
                        'contact_context' => $marketplacePostString('voice_automation_contact_context', 'approval_required'),
                        'follow_up_tasks' => $marketplacePostString('voice_automation_follow_up_tasks', 'approval_required'),
                        'deal_stage' => $marketplacePostString('voice_automation_deal_stage', 'suggest'),
                        'customer_voice' => $marketplacePostString('voice_automation_customer_voice', 'approval_required'),
                        'follow_up_messages' => $marketplacePostString('voice_automation_follow_up_messages', 'off'),
                    ];
                    $voicePayload['recording_retention_days'] = $marketplacePostInt('voice_recording_retention_days', 30, 1, 3650);
                    $voicePayload['transcript_retention_days'] = $marketplacePostInt('voice_transcript_retention_days', 180, 1, 3650);
                    $voicePayload['transcription_model'] = $marketplacePostString('voice_transcription_model', 'gpt-4o-mini-transcribe');
                } elseif ($voiceSetupTab === 'advanced') {
                    $settings['fallback_number'] = $marketplacePostString('voice_fallback_number', (string) ($settings['fallback_number'] ?? ''));
                    $callbackIps = array_values(array_filter(array_map('trim', preg_split('/\s*,\s*/', $marketplacePostString('voice_callback_ip_allowlist')) ?: [])));
                    foreach ($callbackIps as $callbackIp) {
                        if (filter_var($callbackIp, FILTER_VALIDATE_IP) === false) {
                            throw new \InvalidArgumentException('Callback source allowlist entries must be exact IPv4 or IPv6 addresses.');
                        }
                    }
                    $settings['callback_ip_allowlist'] = array_values(array_unique($callbackIps));
                    $voicePayload['allowed_country_codes'] = $marketplacePostString('voice_allowed_country_codes', '+254');
                    $voicePayload['blocked_prefixes'] = $marketplacePostString('voice_blocked_prefixes');
                    $voicePayload['max_call_duration_seconds'] = $marketplacePostInt('voice_max_call_duration_seconds', 3600, 60, 14400);
                    $voicePayload['hourly_call_limit'] = $marketplacePostInt('voice_hourly_call_limit', 60, 1, 10000);
                    $voicePayload['daily_minute_limit'] = $marketplacePostInt('voice_daily_minute_limit', 1000, 1, 1000000);
                    $voicePayload['settings'] = $settings;
                }

                if (!empty($voicePayload['recording_enabled']) && empty($voiceEntitlements['recording'])) {
                    throw new \RuntimeException('Call recording is not included in this workspace voice package.');
                }
                if (!empty($voicePayload['transcription_enabled']) && empty($voiceEntitlements['transcription'])) {
                    throw new \RuntimeException('Voice transcription is not included in this workspace voice package.');
                }
                if (!empty($voicePayload['customer_voice_enabled']) && empty($voiceEntitlements['customer_voice'])) {
                    throw new \RuntimeException('Customer Voice is not included in this workspace voice package.');
                }
                $voiceConfig->save($workspaceId, $voicePayload, $userId);
            }
            $savedQueueId = $marketplacePostInt('voice_queue_id', 0, 0);
            if ($voiceSetupTab === 'queues') {
                $voiceQueueService = new VoiceQueueService();
                $queue = $voiceQueueService->saveQueue($workspaceId, [
                    'name' => $marketplacePostString('voice_queue_name', 'Main queue'),
                    'enabled' => $marketplacePostBool('voice_queue_enabled'),
                    'is_default' => $marketplacePostBool('voice_queue_is_default'),
                    'max_wait_seconds' => $marketplacePostInt('voice_queue_max_wait_seconds', 120, 15, 600),
                    'fallback_action' => $marketplacePostString('voice_queue_fallback_action', 'reject'),
                    'fallback_destination' => $marketplacePostString('voice_queue_fallback_destination'),
                    'fallback_queue_id' => $marketplacePostInt('voice_queue_fallback_queue_id', 0, 0),
                    'business_hours' => [
                        'enabled' => $marketplacePostBool('voice_business_hours_enabled'),
                        'timezone' => $marketplacePostString('voice_business_timezone', 'Africa/Nairobi'),
                        'days' => preg_split('/\s*,\s*/', $marketplacePostString('voice_business_days', '1,2,3,4,5')) ?: [],
                        'start' => $marketplacePostString('voice_business_start', '08:00'),
                        'end' => $marketplacePostString('voice_business_end', '17:00'),
                    ],
                ], $savedQueueId > 0 ? $savedQueueId : null, $userId);
                $savedQueueId = (int) ($queue['id'] ?? 0);
                if (isset($_POST['voice_queue_members_present'])) {
                    $voiceQueueService->setMembers($workspaceId, $savedQueueId, (array) ($_POST['voice_queue_agent_ids'] ?? []));
                }
            }
            if ($voiceSetupTab === 'team') {
                if (!Authorization::can('voice.agents.manage', $user) && !Authorization::isSuperAdmin($user)) {
                    throw new \RuntimeException('Voice agent management permission is required.');
                }
                $agentUserId = $marketplacePostInt('voice_agent_user_id', 0, 1);
                if ($agentUserId <= 0) {
                    throw new \InvalidArgumentException('Choose an active workspace user for this voice agent.');
                }
                $voiceAgentService = new VoiceAgentService();
                $existingAgent = $voiceAgentService->findForUser($workspaceId, $agentUserId, false);
                $agentEndpoint = $marketplacePostString('voice_agent_endpoint');
                if ($agentEndpoint === '' && $existingAgent === []) {
                    throw new \InvalidArgumentException('Enter a valid phone or SIP endpoint for a new voice agent.');
                }
                $voiceAgentService->save($workspaceId, $agentUserId, [
                    'display_name' => $marketplacePostString('voice_agent_display_name', (string) ($existingAgent['display_name'] ?? '')),
                    'endpoint_type' => $marketplacePostString('voice_agent_endpoint_type', 'phone'),
                    'endpoint' => $agentEndpoint,
                    'presence_status' => 'offline',
                    'enabled' => $marketplacePostBool('voice_agent_enabled'),
                ], $userId);
            }
            (new VoiceContactPhoneIndexService())->refreshWorkspace($workspaceId);
            $voiceSetupLabels = [
                'overview' => 'voice controls', 'provider' => 'provider settings', 'team' => 'team setup',
                'queues' => 'queue setup', 'ai_consent' => 'AI and consent policy', 'advanced' => 'advanced policy',
            ];
            $setupJourneyEvents->recordEvent($workspaceId, $userId, $skillKey, 'setup_saved', [
                'label' => 'Voice & Call Center ' . $voiceSetupLabels[$voiceSetupTab] . ' saved', 'source' => 'workspace_marketplace_page',
                'metadata' => ['label' => 'Voice & Call Center setup saved', 'setup_tab' => $voiceSetupTab, 'queue_id' => $savedQueueId],
            ]);
            $_GET['module'] = WorkspaceSkillCatalogService::PLUGIN_VOICE_CALL_CENTER;
            $_GET['setup_tab'] = $voiceSetupTab;
            if ($savedQueueId > 0) {
                $_GET['voice_queue_id'] = (string) $savedQueueId;
            }
            $success = 'Voice & Call Center ' . $voiceSetupLabels[$voiceSetupTab] . ' saved.';
        } elseif ($action === 'test_voice_call_center_provider') {
            if ($skillKey !== WorkspaceSkillCatalogService::PLUGIN_VOICE_CALL_CENTER || !$installer->isInstalled($workspaceId, $skillKey)) {
                throw new \RuntimeException('Install Voice & Call Center before testing the provider.');
            }
            $voiceConfig = new WorkspaceVoiceConfigService();
            $test = (new AfricaTalkingVoiceProvider())->verifyConfiguration($voiceConfig->get($workspaceId, true));
            $voiceConfig->markVerified(
                $workspaceId,
                !empty($test['ok']),
                empty($test['ok']) ? (string) ($test['message'] ?? 'Provider verification failed.') : '',
                (array) ($test['metadata'] ?? [])
            );
            if (empty($test['ok'])) throw new \RuntimeException((string) ($test['message'] ?? 'Provider verification failed.'));
            $_GET['module'] = WorkspaceSkillCatalogService::PLUGIN_VOICE_CALL_CENTER;
            $_GET['setup_tab'] = 'tests';
            $success = (string) $test['message'];
        } elseif (str_starts_with($action, 'retry_voice_transcription_job:')) {
            if ($skillKey !== WorkspaceSkillCatalogService::PLUGIN_VOICE_CALL_CENTER || !$installer->isInstalled($workspaceId, $skillKey)) {
                throw new \RuntimeException('Install Voice & Call Center before retrying transcription jobs.');
            }
            if (!Authorization::isSuperAdmin($user) && !Authorization::can('voice.settings.manage', $user)) {
                throw new \RuntimeException('Voice settings permission is required to retry transcription jobs.');
            }
            $jobId = max(1, (int) substr($action, strlen('retry_voice_transcription_job:')));
            (new VoiceTranscriptionJobService())->retry($workspaceId, $jobId);
            $_GET['module'] = WorkspaceSkillCatalogService::PLUGIN_VOICE_CALL_CENTER;
            $_GET['setup_tab'] = 'tests';
            $success = 'The transcription job was safely returned to the worker queue.';
        } elseif ($action === 'manage_calendar_connection') {
            if ($skillKey !== WorkspaceSkillCatalogService::PLUGIN_CALENDAR_MEETINGS) {
                throw new \RuntimeException('Calendar connection action could not be matched to Calendar & Meetings.');
            }
            if (!$installer->isInstalled($workspaceId, WorkspaceSkillCatalogService::PLUGIN_CALENDAR_MEETINGS)) {
                throw new \RuntimeException('Install Calendar & Meetings before connecting a calendar.');
            }
            if (!$workspaceConnect->canUseCalendarConnections($user, $workspaceId)) {
                throw new \RuntimeException('Calendar & Meetings access is required to manage your calendar connection.');
            }
            if (!Database::tableExists('calendar_integrations')) {
                throw new \RuntimeException('Calendar integrations are not available in this workspace yet.');
            }

            $calendarAction = (string) ($_POST['calendar_action'] ?? '');
            $integrationId = (int) ($_POST['integration_id'] ?? 0);
            $integration = Database::queryOne(
                "SELECT *
                 FROM calendar_integrations
                 WHERE id = ?
                   AND workspace_id = ?
                   AND user_id = ?
                 LIMIT 1",
                [$integrationId, $workspaceId, $userId]
            );
            if (!$integration) {
                throw new \RuntimeException('Calendar connection not found.');
            }

            if ($calendarAction === 'disconnect_connection') {
                Database::execute(
                    "DELETE FROM calendar_integrations
                     WHERE id = ?
                       AND workspace_id = ?
                       AND user_id = ?",
                    [$integrationId, $workspaceId, $userId]
                );
                $success = 'Calendar disconnected.';
            } elseif ($calendarAction === 'update_connection') {
                $syncService = new CalendarSyncService();
                $provider = (string) ($integration['provider'] ?? '');
                $direction = (string) ($_POST['sync_direction'] ?? 'to_crm');
                $allowedDirections = array_keys($syncService->providerDirectionOptions($provider));
                if (!in_array($direction, $allowedDirections, true)) {
                    throw new \RuntimeException('That sync direction is not supported for this calendar provider.');
                }

                $syncEnabled = isset($_POST['sync_enabled']) ? 1 : 0;
                $availabilityEnabled = isset($_POST['availability_enabled']) ? 1 : 0;
                $grantType = GoogleOAuthScopeCatalog::normalizeGrantType((string) ($integration['oauth_grant_type'] ?? GoogleOAuthScopeCatalog::GRANT_LEGACY_CALENDAR));
                if ($syncEnabled
                    && $provider === 'google'
                    && in_array($direction, ['from_crm', 'both'], true)
                    && !GoogleOAuthScopeCatalog::grantSupportsCalendarWrite($grantType)) {
                    throw new \RuntimeException('Reconnect Google Calendar with write access before enabling outbound sync.');
                }

                Database::execute(
                    "UPDATE calendar_integrations
                     SET sync_direction = ?,
                         sync_enabled = ?,
                         availability_enabled = ?,
                         updated_at = NOW()
                     WHERE id = ?
                       AND workspace_id = ?
                       AND user_id = ?",
                    [$direction, $syncEnabled, $availabilityEnabled, $integrationId, $workspaceId, $userId]
                );
                $success = 'Calendar connection settings updated.';
            } else {
                throw new \RuntimeException('Unsupported calendar connection action.');
            }

            $_GET['module'] = WorkspaceSkillCatalogService::PLUGIN_CALENDAR_MEETINGS;
            $_GET['setup_tab'] = 'calendar';
        } elseif ($action === 'save_calendar_meetings_setup') {
            if ($skillKey !== WorkspaceSkillCatalogService::PLUGIN_CALENDAR_MEETINGS) {
                throw new \RuntimeException('Calendar & Meetings setup could not be matched to a marketplace plugin.');
            }
            if (!$installer->isInstalled($workspaceId, WorkspaceSkillCatalogService::PLUGIN_CALENDAR_MEETINGS)) {
                throw new \RuntimeException('Install Calendar & Meetings before saving setup.');
            }
            $canManageCalendarSetup = $canManage || Authorization::can('settings.calendar', $user);
            $canManageMeetingBotSetup = $canManage || Authorization::can('settings.meeting_bot', $user);
            $canManageMeetingNotesSetup = $canManage || Authorization::can('settings.meeting_note_taker', $user);
            $postedBotOrCalendarSection = array_key_exists('meeting_bot_provider', $_POST)
                || array_key_exists('meeting_bot_google_calendar_integration_id', $_POST);
            $postedNotesSection = array_key_exists('meeting_note_taker_auto_apply_mode', $_POST)
                || array_key_exists('meeting_note_taker_enabled', $_POST);
            if (($canManageCalendarSetup || $canManageMeetingBotSetup) && $postedBotOrCalendarSection) {
                $meetingBot = new MeetingBotConfig();
                $existingMeetingBotConfig = $meetingBot->get($workspaceId, true);
                $meetingBotPayload = [];
                if ($canManageMeetingBotSetup) {
                    $meetingBotPayload = [
                        'enabled' => $marketplacePostBool('meeting_bot_enabled'),
                        'provider' => $marketplacePostString('meeting_bot_provider', (string) ($existingMeetingBotConfig['provider'] ?? 'zoom')),
                        'bot_display_name' => $marketplacePostString('meeting_bot_display_name', (string) ($existingMeetingBotConfig['bot_display_name'] ?? '')),
                        'join_policy' => $marketplacePostString('meeting_bot_join_policy', (string) ($existingMeetingBotConfig['join_policy'] ?? 'manual_invite_only')),
                        'recording_mode' => $marketplacePostString('meeting_bot_recording_mode', (string) ($existingMeetingBotConfig['recording_mode'] ?? 'provider_native')),
                        'transcript_required' => $marketplacePostBool('meeting_bot_transcript_required'),
                        'auto_apply_mode' => $marketplacePostString('meeting_bot_auto_apply_mode', (string) ($existingMeetingBotConfig['auto_apply_mode'] ?? 'auto_safe')),
                        'consent_notice' => $marketplacePostString('meeting_bot_consent_notice', (string) ($existingMeetingBotConfig['consent_notice'] ?? '')),
                        'zoom_account_id' => $marketplacePostString('meeting_bot_zoom_account_id', (string) ($existingMeetingBotConfig['zoom_account_id'] ?? '')),
                        'zoom_client_id' => $marketplacePostString('meeting_bot_zoom_client_id', (string) ($existingMeetingBotConfig['zoom_client_id'] ?? '')),
                        'zoom_client_secret' => $marketplacePostString('meeting_bot_zoom_client_secret', 'saved'),
                        'google_workspace_client_id' => $marketplacePostString('meeting_bot_google_workspace_client_id', (string) ($existingMeetingBotConfig['google_workspace_client_id'] ?? '')),
                        'google_workspace_client_secret' => $marketplacePostString('meeting_bot_google_workspace_client_secret', 'saved'),
                        'google_workspace_project_id' => $marketplacePostString('meeting_bot_google_workspace_project_id', (string) ($existingMeetingBotConfig['google_workspace_project_id'] ?? '')),
                        'google_transcript_mode' => $marketplacePostString('meeting_bot_google_transcript_mode', (string) ($existingMeetingBotConfig['google_transcript_mode'] ?? 'manual_ingest')),
                        'webhook_secret' => $marketplacePostBool('meeting_bot_regenerate_webhook_secret')
                            ? '__regenerate__'
                            : $marketplacePostString('meeting_bot_webhook_secret', 'saved'),
                        'scheduling_secret' => $marketplacePostBool('meeting_bot_regenerate_scheduling_secret')
                            ? '__regenerate__'
                            : $marketplacePostString('meeting_bot_scheduling_secret', 'saved'),
                    ];
                }
                if ($canManageCalendarSetup || $canManageMeetingBotSetup) {
                    $meetingBotPayload['google_calendar_integration_id'] = $marketplacePostString(
                        'meeting_bot_google_calendar_integration_id',
                        (string) ($existingMeetingBotConfig['google_calendar_integration_id'] ?? '')
                    );
                }
                $meetingBot->save($meetingBotPayload, $userId, $workspaceId);
            }
            if ($canManageMeetingNotesSetup && $postedNotesSection) {
                $meetingNotes = new MeetingNoteTakerConfig();
                $existingMeetingNotesConfig = $meetingNotes->get($workspaceId, true);
                $allowedContactFields = array_values(array_filter(array_map('strval', (array) ($_POST['meeting_note_taker_allowed_contact_fields'] ?? []))));
                $meetingNotes->save([
                    'enabled' => $marketplacePostBool('meeting_note_taker_enabled'),
                    'auto_apply_mode' => $marketplacePostString('meeting_note_taker_auto_apply_mode', (string) ($existingMeetingNotesConfig['auto_apply_mode'] ?? 'full_auto')),
                    'contact_updates_additive_only' => $marketplacePostBool('meeting_note_taker_contact_updates_additive_only'),
                    'task_auto_create_enabled' => $marketplacePostBool('meeting_note_taker_task_auto_create_enabled'),
                    'deal_stage_auto_move_enabled' => $marketplacePostBool('meeting_note_taker_deal_stage_auto_move_enabled'),
                    'deal_stage_min_confidence' => $marketplacePostFloat('meeting_note_taker_deal_stage_min_confidence', (float) ($existingMeetingNotesConfig['deal_stage_min_confidence'] ?? 0.90)),
                    'contact_update_min_confidence' => $marketplacePostFloat('meeting_note_taker_contact_update_min_confidence', (float) ($existingMeetingNotesConfig['contact_update_min_confidence'] ?? 0.75)),
                    'max_context_entries' => $marketplacePostInt('meeting_note_taker_max_context_entries', (int) ($existingMeetingNotesConfig['max_context_entries'] ?? 10), 1, 50),
                    'allowed_contact_fields' => $allowedContactFields !== [] ? $allowedContactFields : (array) ($existingMeetingNotesConfig['allowed_contact_fields'] ?? []),
                    'ingest_secret' => $marketplacePostBool('meeting_note_taker_regenerate_secret')
                        ? '__regenerate__'
                        : $marketplacePostString('meeting_note_taker_ingest_secret', 'saved'),
                ], $userId, $workspaceId);
            }
            $setupJourneyEvents->recordEvent($workspaceId, $userId, $skillKey, 'setup_saved', [
                'label' => 'Calendar & Meetings setup saved',
                'source' => 'workspace_marketplace_page',
                'metadata' => ['label' => 'Calendar & Meetings setup saved'],
            ]);
            $success = 'Calendar & Meetings setup saved.';
        } elseif ($action === 'save_hr_analytics_setup') {
            $hrSetupActionStarted = microtime(true);
            if ($skillKey !== WorkspaceSkillCatalogService::PLUGIN_HR_ANALYTICS_SETUP) {
                throw new \RuntimeException('Organization Intelligence setup could not be matched to a marketplace plugin.');
            }
            if (!$canEditMarketplaceCatalog) {
                throw new \RuntimeException('Only superadmins can manage Organization Intelligence technical setup.');
            }
            if (!$installer->isInstalled($workspaceId, WorkspaceSkillCatalogService::PLUGIN_HR_ANALYTICS_SETUP)) {
                $installer->installRequiredCorePlugins($workspaceId, $userId);
            }
            $hrAnalyticsSettings->save([
                'ai_enabled' => $marketplacePostBool('hr_ai_enabled'),
                'thresholds' => [
                    'high_performer' => $marketplacePostInt('hr_threshold_high_performer', 75, 50, 100),
                    'at_risk' => $marketplacePostInt('hr_threshold_at_risk', 45, 0, 80),
                    'needs_coaching' => $marketplacePostInt('hr_threshold_needs_coaching', 55, 0, 90),
                    'overloaded_task_count' => $marketplacePostInt('hr_threshold_overloaded_task_count', 7, 1, 50),
                    'inactive_days' => $marketplacePostInt('hr_threshold_inactive_days', 10, 1, 60),
                ],
                'scoring_weights' => [
                    'marketing' => [
                        'task_completion' => $marketplacePostFloat('hr_weight_marketing_task_completion', 0.18),
                        'timeliness' => $marketplacePostFloat('hr_weight_marketing_timeliness', 0.12),
                        'activity_consistency' => $marketplacePostFloat('hr_weight_marketing_activity_consistency', 0.18),
                        'outcome_impact' => $marketplacePostFloat('hr_weight_marketing_outcome_impact', 0.17),
                        'pipeline_movement' => $marketplacePostFloat('hr_weight_marketing_pipeline_movement', 0.05),
                        'campaign_output' => $marketplacePostFloat('hr_weight_marketing_campaign_output', 0.20),
                        'workload_balance' => $marketplacePostFloat('hr_weight_marketing_workload_balance', 0.10),
                    ],
                    'sales' => [
                        'task_completion' => $marketplacePostFloat('hr_weight_sales_task_completion', 0.18),
                        'timeliness' => $marketplacePostFloat('hr_weight_sales_timeliness', 0.14),
                        'activity_consistency' => $marketplacePostFloat('hr_weight_sales_activity_consistency', 0.13),
                        'outcome_impact' => $marketplacePostFloat('hr_weight_sales_outcome_impact', 0.20),
                        'pipeline_movement' => $marketplacePostFloat('hr_weight_sales_pipeline_movement', 0.22),
                        'campaign_output' => $marketplacePostFloat('hr_weight_sales_campaign_output', 0.03),
                        'workload_balance' => $marketplacePostFloat('hr_weight_sales_workload_balance', 0.10),
                    ],
                    'general' => [
                        'task_completion' => $marketplacePostFloat('hr_weight_general_task_completion', 0.26),
                        'timeliness' => $marketplacePostFloat('hr_weight_general_timeliness', 0.19),
                        'activity_consistency' => $marketplacePostFloat('hr_weight_general_activity_consistency', 0.14),
                        'outcome_impact' => $marketplacePostFloat('hr_weight_general_outcome_impact', 0.12),
                        'pipeline_movement' => $marketplacePostFloat('hr_weight_general_pipeline_movement', 0.04),
                        'campaign_output' => $marketplacePostFloat('hr_weight_general_campaign_output', 0.03),
                        'workload_balance' => $marketplacePostFloat('hr_weight_general_workload_balance', 0.22),
                    ],
                ],
                'department_mappings' => [
                    'marketing' => $marketplacePostString('hr_department_marketing', 'Marketing'),
                    'sales' => $marketplacePostString('hr_department_sales', 'Sales'),
                    'admin' => $marketplacePostString('hr_department_admin', 'Leadership'),
                    'owner' => $marketplacePostString('hr_department_owner', 'Leadership'),
                    'viewer' => $marketplacePostString('hr_department_viewer', 'Operations'),
                ],
                'prompt_config' => [
                    'manager_focus' => $marketplacePostString('hr_manager_focus'),
                    'swot_focus' => $marketplacePostString('hr_swot_focus'),
                ],
            ], $userId, $workspaceId);
            $setupJourneyEvents->recordEvent($workspaceId, $userId, $skillKey, 'setup_saved', [
                'label' => 'Organization Intelligence setup saved',
                'source' => 'workspace_marketplace_page',
                'metadata' => ['label' => 'Organization Intelligence setup saved'],
            ]);
            (new PluginRuntimeEventService())->record([
                'workspace_id' => $workspaceId,
                'user_id' => $userId,
                'skill_key' => $skillKey,
                'capability_key' => 'hr_analytics.setup_saved',
                'event_type' => 'workflow_action_executed',
                'status' => 'success',
                'duration_ms' => (int) round((microtime(true) - $hrSetupActionStarted) * 1000),
                'metadata' => ['source' => 'workspace_marketplace_page'],
            ]);
            $success = 'Organization Intelligence setup saved.';
        } elseif ($action === 'save_ai_api_setup') {
            if ($skillKey !== WorkspaceSkillCatalogService::PLUGIN_AI_API) {
                throw new \RuntimeException('AI API setup could not be matched to a marketplace plugin.');
            }
            if (!$installer->isInstalled($workspaceId, WorkspaceSkillCatalogService::PLUGIN_AI_API)) {
                throw new \RuntimeException('Install AI API before saving provider settings.');
            }
            $credentialScope = $aiProviderConfigs->normalizeCredentialScope(
                $marketplacePostString('ai_credential_scope', WorkspaceAIProviderConfigService::SCOPE_GENERAL)
            );
            $aiSettings = [
                'credential_scope' => $credentialScope,
                'provider_key' => $marketplacePostString('ai_provider_key', 'openai'),
                'api_url' => $marketplacePostString('ai_api_url'),
                'model' => $marketplacePostString('ai_model', 'gpt-4o-mini'),
                'api_key' => $marketplacePostString('ai_api_key'),
                'enabled' => $marketplacePostBool('ai_provider_enabled'),
                'settings' => [
                    'source' => 'workspace_marketplace_page',
                    'saved_from_module' => WorkspaceSkillCatalogService::PLUGIN_AI_API,
                    'credential_scope' => $credentialScope,
                ],
            ];
            if (WorkspaceContext::isDefaultWorkspace($workspaceId)) {
                $aiSettings['shared_daily_token_cap'] = $marketplacePostInt('ai_shared_daily_token_cap', 0, 0, 100000000);
            }
            $aiProviderConfigs->save($workspaceId, $aiSettings, $userId);
            $setupJourneyEvents->recordEvent($workspaceId, $userId, $skillKey, 'setup_saved', [
                'label' => 'AI API setup saved',
                'source' => 'workspace_marketplace_page',
                'metadata' => [
                    'label' => 'AI API setup saved',
                    'is_default_workspace' => WorkspaceContext::isDefaultWorkspace($workspaceId),
                    'credential_scope' => $credentialScope,
                ],
            ]);
            $scopeLabel = $credentialScope === WorkspaceAIProviderConfigService::SCOPE_CONTENT_GENERATION
                ? 'Content Generation'
                : 'General AI';
            $success = $scopeLabel . ' API setup saved for ' . (WorkspaceContext::isDefaultWorkspace($workspaceId) ? 'the common workspace.' : 'this workspace.');
        } elseif ($action === 'create_organization_function') {
            $hrSetupActionStarted = microtime(true);
            if ($skillKey !== WorkspaceSkillCatalogService::PLUGIN_HR_ANALYTICS_SETUP) {
                throw new \RuntimeException('Organization function setup could not be matched to a marketplace plugin.');
            }
            $createdFunctionId = $organizationFunctions->createFunction($workspaceId, [
                'name' => $marketplacePostString('function_name'),
                'slug' => $marketplacePostString('function_slug'),
                'description' => $marketplacePostString('function_description'),
                'category' => $marketplacePostString('function_category', 'core'),
                'measurement_strength' => $marketplacePostString('function_measurement_strength', 'partial'),
                'relevance_status' => $marketplacePostString('function_relevance_status', 'active'),
                'relevance_note' => $marketplacePostString('function_relevance_note'),
            ]);
            $setupJourneyEvents->recordEvent($workspaceId, $userId, $skillKey, 'setup_saved', [
                'label' => 'Business function created',
                'source' => 'workspace_marketplace_page',
                'metadata' => ['function_id' => $createdFunctionId],
            ]);
            (new PluginRuntimeEventService())->record([
                'workspace_id' => $workspaceId,
                'user_id' => $userId,
                'skill_key' => $skillKey,
                'capability_key' => 'hr_analytics.function_created',
                'entity_type' => 'organization_function',
                'entity_id' => $createdFunctionId,
                'event_type' => 'workflow_action_executed',
                'status' => 'success',
                'duration_ms' => (int) round((microtime(true) - $hrSetupActionStarted) * 1000),
                'metadata' => ['source' => 'workspace_marketplace_page'],
            ]);
            $success = 'Business function created.';
        } elseif ($action === 'update_organization_function') {
            $hrSetupActionStarted = microtime(true);
            if ($skillKey !== WorkspaceSkillCatalogService::PLUGIN_HR_ANALYTICS_SETUP) {
                throw new \RuntimeException('Organization function setup could not be matched to a marketplace plugin.');
            }
            $functionId = $marketplacePostInt('function_id', 0, 1, 1000000);
            $organizationFunctions->updateFunction($workspaceId, $functionId, [
                'name' => $marketplacePostString('function_name'),
                'slug' => $marketplacePostString('function_slug'),
                'description' => $marketplacePostString('function_description'),
                'category' => $marketplacePostString('function_category', 'core'),
                'measurement_strength' => $marketplacePostString('function_measurement_strength', 'partial'),
                'relevance_status' => $marketplacePostString('function_relevance_status', 'active'),
                'relevance_note' => $marketplacePostString('function_relevance_note'),
                'is_active' => $marketplacePostBool('function_is_active'),
            ]);
            $setupJourneyEvents->recordEvent($workspaceId, $userId, $skillKey, 'setup_saved', [
                'label' => 'Business function updated',
                'source' => 'workspace_marketplace_page',
                'metadata' => ['function_id' => $functionId],
            ]);
            (new PluginRuntimeEventService())->record([
                'workspace_id' => $workspaceId,
                'user_id' => $userId,
                'skill_key' => $skillKey,
                'capability_key' => 'hr_analytics.function_updated',
                'entity_type' => 'organization_function',
                'entity_id' => $functionId,
                'event_type' => 'workflow_action_executed',
                'status' => 'success',
                'duration_ms' => (int) round((microtime(true) - $hrSetupActionStarted) * 1000),
                'metadata' => ['source' => 'workspace_marketplace_page'],
            ]);
            $success = 'Business function updated.';
        } elseif ($action === 'save_ai_coach_setup') {
            if ($skillKey !== WorkspaceSkillCatalogService::SKILL_AI_COACH) {
                throw new \RuntimeException('AI Coach setup could not be matched to a marketplace skill.');
            }
            $workspaceAiCoachEnabled = $marketplacePostBool('ai_coach_enabled');
            (new AICoachWorkspaceSetupService($installer))->setWorkspaceEnabled($workspaceId, $userId, $workspaceAiCoachEnabled);
            $setupJourneyEvents->recordEvent($workspaceId, $userId, $skillKey, 'setup_saved', [
                'label' => 'AI Coach workspace setup saved',
                'source' => 'workspace_marketplace_page',
                'metadata' => [
                    'label' => 'AI Coach workspace setup saved',
                    'workspace_enabled' => $workspaceAiCoachEnabled,
                ],
            ]);
            $success = 'AI Coach workspace setup saved.';
        } elseif ($action === 'save_ai_coach_personal_brief') {
            if ($skillKey !== WorkspaceSkillCatalogService::SKILL_AI_COACH) {
                throw new \RuntimeException('AI Coach personal strategy could not be matched to a marketplace skill.');
            }
            if (!$installer->isInstalled($workspaceId, WorkspaceSkillCatalogService::SKILL_AI_COACH)) {
                throw new \RuntimeException('Install AI Coach before saving optional personal strategy.');
            }
            $setupService = new AICoachWorkspaceSetupService($installer);
            $setupService->savePersonalBrief($workspaceId, $userId, $_POST);
            $readinessAfterBrief = (new AICoachReadinessService())->getReadiness($workspaceId, $userId);
            if (!empty($readinessAfterBrief['recommendations_ready'])) {
                (new AICoachReadinessService())->completeOnboarding($workspaceId, $userId);
            }
            $setupJourneyEvents->recordEvent($workspaceId, $userId, $skillKey, 'setup_saved', [
                'label' => 'Optional personal strategy saved',
                'source' => 'workspace_marketplace_page',
                'metadata' => [
                    'label' => 'Optional personal strategy saved',
                    'personal_strategy_refinement_ready' => !empty($readinessAfterBrief['personal_strategy_refinement_ready']),
                    'optional_personal_strategy_missing' => (array) ($readinessAfterBrief['optional_personal_strategy_missing'] ?? []),
                ],
            ]);
            $success = 'Optional personal strategy saved.';
        } elseif ($action === 'save_finance_setup') {
            if ($skillKey !== WorkspaceSkillCatalogService::PLUGIN_FINANCE) {
                throw new \RuntimeException('Finance setup could not be matched to a marketplace plugin.');
            }
            $financeSetupTab = strtolower(trim((string) ($_POST['finance_setup_tab'] ?? '')));
            $financeStatusAfterSave = $financeGate->saveInitialSetup($workspaceId, $_POST, $userId);
            $financeSetupAfterSave = $financeGate->setupFormData($workspaceId);
            $marketplaceJsonPayload['finance_setup'] = [
                'status' => $financeStatusAfterSave,
                'summary' => (array) ($financeSetupAfterSave['summary'] ?? []),
            ];
            $setupJourneyEvents->recordEvent($workspaceId, $userId, $skillKey, 'setup_saved', [
                'label' => 'Finance setup saved',
                'source' => 'workspace_marketplace_page',
                'metadata' => ['label' => 'Finance setup saved', 'tab' => $financeSetupTab],
            ]);
            $success = $financeSetupTab === 'review'
                ? 'Finance setup complete.'
                : 'Finance setup saved.';
        } elseif ($action === 'generate_smart_templates') {
            if ($skillKey !== WorkspaceSkillCatalogService::SKILL_AI_COACH) {
                throw new \RuntimeException('Smart Template generation belongs to the AI Coach Marketplace setup.');
            }
            if (!$installer->isInstalled($workspaceId, WorkspaceSkillCatalogService::SKILL_AI_COACH)) {
                throw new \RuntimeException('Install AI Coach before generating Smart Templates.');
            }
            $result = (new SmartTemplateGenerationService())->generateForUser($userId, [
                'as_candidate' => true,
                'generation_mode' => 'manual_candidate',
                'generated_reason' => 'marketplace_review_candidate',
            ]);
            $setupJourneyEvents->recordEvent($workspaceId, $userId, $skillKey, 'setup_saved', [
                'label' => 'Smart Template candidate generated',
                'source' => 'workspace_marketplace_page',
                'metadata' => [
                    'label' => 'Smart Template candidate generated',
                    'smart_template_set_id' => (int) ($result['smart_template_set_id'] ?? 0),
                ],
            ]);
            $success = 'Smart Template candidate generated for review.';
        } elseif ($action === 'run_marketplace_readiness_check') {
            if (!$installer->isInstalled($workspaceId, $skillKey)) {
                throw new \RuntimeException('Install this module before running readiness checks.');
            }
            $recommendationEvents->recordEvent($workspaceId, $userId, $skillKey, 'marketplace', 'test_attempted', [
                'metadata' => ['source' => 'workspace_marketplace_page', 'label' => 'Readiness check started'],
            ]);
            $readinessCheck = $installer->buildReadinessForModule($workspaceId, $userId, $skillKey);
            $passed = (bool) ($readinessCheck['ready'] ?? false);
            $recommendationEvents->recordEvent($workspaceId, $userId, $skillKey, 'marketplace', $passed ? 'test_passed' : 'test_failed', [
                'metadata' => [
                    'source' => 'workspace_marketplace_page',
                    'label' => 'Readiness check ' . ($passed ? 'passed' : 'failed'),
                    'status' => (string) ($readinessCheck['status'] ?? ''),
                    'blockers' => (array) ($readinessCheck['blockers'] ?? []),
                ],
            ]);
            $success = $passed
                ? 'Readiness check passed. No external message was sent.'
                : 'Readiness check finished. Complete the setup blockers before running live tests.';
        } elseif ($action === 'send_email_assistant_test_digest') {
            if ($skillKey !== WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT || !$installer->isInstalled($workspaceId, WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT)) {
                throw new \RuntimeException('Install Email Assistant before sending a test digest.');
            }
            $testEmail = trim((string) ($_POST['email_test_recipient'] ?? ''));
            if (!filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
                throw new \RuntimeException('Enter an explicit test recipient email before sending a live test digest.');
            }
            $recommendationEvents->recordEvent($workspaceId, $userId, $skillKey, 'marketplace', 'test_attempted', [
                'metadata' => ['source' => 'workspace_marketplace_page', 'label' => 'Email live test attempted'],
            ]);
            $digest = new EmailAssistantDigestService();
            $digestConfig = $digest->validateDigestConfig();
            if (empty($digestConfig['outbound_ready'])) {
                $recommendationEvents->recordEvent($workspaceId, $userId, $skillKey, 'marketplace', 'test_failed', [
                    'metadata' => ['source' => 'workspace_marketplace_page', 'label' => 'Email live test blocked by readiness'],
                ]);
                throw new \RuntimeException((string) ($digestConfig['message'] ?? 'Email Assistant outbound setup is not ready.'));
            }
            $digest->sendDigestToUser($userId, $testEmail, true);
            $recommendationEvents->recordEvent($workspaceId, $userId, $skillKey, 'marketplace', 'test_passed', [
                'metadata' => ['source' => 'workspace_marketplace_page', 'label' => 'Email live test sent'],
            ]);
            $success = 'Email Assistant test digest sent.';
        } elseif ($action === 'disconnect_communication_email') {
            if ($skillKey !== WorkspaceSkillCatalogService::PLUGIN_EMAIL || !$installer->isInstalled($workspaceId, WorkspaceSkillCatalogService::PLUGIN_EMAIL)) {
                throw new \RuntimeException('Install Email before disconnecting workspace email settings.');
            }
            $emailRole = strtolower($marketplacePostString('email_role', 'outreach'));
            if (!in_array($emailRole, ['outreach', 'nurture'], true)) {
                throw new \RuntimeException('Choose Outreach or Nurture Email before disconnecting settings.');
            }
            $scope = $emailRole === 'nurture'
                ? EmailIntegrationService::SCOPE_NURTURE_EMAIL
                : EmailIntegrationService::SCOPE_OUTREACH_EMAIL;
            $emailIntegrations->clearScopeIntegration($scope, $workspaceId);
            $_GET['module'] = $skillKey;
            $_GET['setup_tab'] = $emailRole === 'nurture' ? 'nurture_email' : 'outreach_email';
            $setupJourneyEvents->recordEvent($workspaceId, $userId, $skillKey, 'step_reset', [
                'step_key' => $emailRole === 'nurture' ? 'test_nurture_email' : 'test_outreach_email',
                'label' => ucfirst($emailRole) . ' Email disconnected',
                'source' => 'workspace_marketplace_page',
                'metadata' => ['label' => ucfirst($emailRole) . ' Email disconnected', 'role' => $emailRole],
            ]);
            $success = ucfirst($emailRole) . ' Email disconnected.';
        } elseif ($action === 'disconnect_email_assistant_gmail') {
            if ($skillKey !== WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT || !$installer->isInstalled($workspaceId, WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT)) {
                throw new \RuntimeException('Install Email Assistant before disconnecting Assistant Gmail.');
            }
            $emailIntegrations->deactivateAssistantGmail($workspaceId);
            $setupJourneyEvents->recordEvent($workspaceId, $userId, $skillKey, 'setup_saved', [
                'label' => 'Assistant Gmail disconnected',
                'source' => 'workspace_marketplace_page',
                'metadata' => ['label' => 'Assistant Gmail disconnected'],
            ]);
            $success = 'Assistant Gmail disconnected.';
        } elseif ($action === 'clear_email_assistant_mail') {
            if ($skillKey !== WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT || !$installer->isInstalled($workspaceId, WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT)) {
                throw new \RuntimeException('Install Email Assistant before clearing assistant mail settings.');
            }
            $currentEmailConfig = $assistantConfig->get($workspaceId, 'email', true);
            $currentEmailSettings = (array) ($currentEmailConfig['settings'] ?? []);
            foreach ([
                'system_email',
                'smtp_host',
                'smtp_port',
                'smtp_username',
                'smtp_password',
                'smtp_encryption',
                'from_email',
                'from_name',
                'imap_enabled',
                'imap_host',
                'imap_port',
                'imap_protocol',
                'imap_encryption',
                'imap_username',
                'imap_password',
                'imap_folder',
                'allowed_senders',
            ] as $mailKey) {
                unset($currentEmailSettings[$mailKey]);
            }
            $assistantConfig->save($workspaceId, 'email', $currentEmailSettings, !empty($currentEmailConfig['enabled']), $userId);
            $emailIntegrations->clearScopeIntegration(EmailIntegrationService::SCOPE_ASSISTANT_EMAIL, $workspaceId);
            $_GET['module'] = $skillKey;
            $_GET['setup_tab'] = 'outbound';
            $setupJourneyEvents->recordEvent($workspaceId, $userId, $skillKey, 'step_reset', [
                'step_key' => 'test_assistant_email',
                'label' => 'Email Assistant mail settings cleared',
                'source' => 'workspace_marketplace_page',
                'metadata' => ['label' => 'Email Assistant mail settings cleared'],
            ]);
            $success = 'Email Assistant mail settings cleared.';
        } elseif ($action === 'send_whatsapp_assistant_test_digest') {
            $hubResult = $whatsAppSettingsHub->handleWhatsAppHubPost($workspaceId, $userId, $user, $_POST, 'marketplace');
            $_GET['module'] = (string) ($hubResult['setup_module'] ?? $skillKey);
            $_GET['setup_tab'] = (string) ($hubResult['setup_tab'] ?? 'tests');
            $success = (string) ($hubResult['success'] ?? 'WhatsApp Assistant test digest sent.');
        } elseif ($action === 'send_sms_channel_test') {
            if ($skillKey !== WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL || !$installer->isInstalled($workspaceId, WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL)) {
                throw new \RuntimeException('Install SMS Channel before sending a test SMS.');
            }
            $testPhone = trim((string) ($_POST['sms_test_recipient'] ?? ''));
            if ($testPhone === '') {
                throw new \RuntimeException('Enter an explicit test phone number before sending a live SMS test.');
            }
            $recommendationEvents->recordEvent($workspaceId, $userId, $skillKey, 'marketplace', 'test_attempted', [
                'metadata' => ['source' => 'workspace_marketplace_page', 'label' => 'SMS live test attempted'],
            ]);
            $readinessCheck = $smsChannelConfig->readiness($workspaceId);
            if (empty($readinessCheck['outbound_ready'])) {
                $recommendationEvents->recordEvent($workspaceId, $userId, $skillKey, 'marketplace', 'test_failed', [
                    'metadata' => ['source' => 'workspace_marketplace_page', 'label' => 'SMS live test blocked by readiness'],
                ]);
                throw new \RuntimeException((string) ($readinessCheck['message'] ?? 'SMS Channel outbound setup is not ready.'));
            }

            try {
                (new SMSService($smsChannelConfig))->sendSMS($testPhone, 'Clarity SMS Channel test for this workspace.', [
                    'workspace_id' => $workspaceId,
                ]);
            } catch (\Throwable $e) {
                $recommendationEvents->recordEvent($workspaceId, $userId, $skillKey, 'marketplace', 'test_failed', [
                    'metadata' => [
                        'source' => 'workspace_marketplace_page',
                        'label' => 'SMS live test failed',
                        'error' => substr($e->getMessage(), 0, 240),
                    ],
                ]);
                throw new \RuntimeException('SMS test failed: ' . $e->getMessage());
            }

            $recommendationEvents->recordEvent($workspaceId, $userId, $skillKey, 'marketplace', 'test_passed', [
                'metadata' => ['source' => 'workspace_marketplace_page', 'label' => 'SMS live test sent'],
            ]);
            $success = 'SMS test sent.';
        } elseif ($action === 'refresh_plugin_health') {
            $success = 'Marketplace health refreshed.';
        } elseif ($action === 'install') {
            $preInstallJourneys = $actionRecommendation !== null
                ? $setupJourneys->journeysBySkill($workspaceId, $userId, [$actionRecommendation], [], [])
                : [];
            $installActivationBundle = $activationBundleAttributionKey !== '' ? $bundleByKey($actionActivationBundles, $activationBundleAttributionKey) : null;
            $installed = $installer->install($workspaceId, $skillKey, $userId, ['source' => 'workspace_marketplace_page']);
            $recommendationEvents->recordEvent($workspaceId, $userId, $skillKey, 'marketplace', 'installed', [
                'recommendation_score' => $actionRecommendation['score'] ?? null,
                'priority' => (string) ($actionRecommendation['priority'] ?? ''),
                'reason_codes' => (array) ($actionRecommendation['reason_codes'] ?? []),
                'metadata' => array_merge(['source' => 'workspace_marketplace_page', 'label' => (string) ($installed['label'] ?? '')], $actionAdaptiveMetadata),
            ]);
            if (isset($preInstallJourneys[$skillKey])) {
                $setupJourneyEvents->recordEvent($workspaceId, $userId, $skillKey, 'install_completed', [
                    'label' => (string) ($installed['label'] ?? ''),
                    'source' => 'workspace_marketplace_page',
                    'metadata' => ['label' => (string) ($installed['label'] ?? '')],
                ]);
            }
            if ($activationBundleAttributionKey !== '') {
                $activationBundleEvents->recordEvent($workspaceId, $userId, $activationBundleAttributionKey, 'module_installed', [
                    'bundle' => $installActivationBundle ?? [],
                    'metadata' => [
                        'source' => 'workspace_marketplace_page',
                        'module_skill_key' => $skillKey,
                        'module_label' => (string) ($installed['label'] ?? ''),
                        'label' => (string) ($installActivationBundle['label'] ?? $activationBundleAttributionKey),
                    ],
                ]);
            }
            $success = (string) ($installed['label'] ?? 'Marketplace item') . ' installed.';
        } elseif ($action === 'uninstall') {
            $skill = $catalog->find($skillKey);
            $installer->uninstall($workspaceId, $skillKey, $userId);
            $recommendationEvents->recordEvent($workspaceId, $userId, $skillKey, 'marketplace', 'uninstalled', [
                'metadata' => ['source' => 'workspace_marketplace_page', 'label' => (string) ($skill['label'] ?? $skillKey)],
            ]);
            $success = (string) ($skill['label'] ?? 'Marketplace item') . ' removed from this workspace.';
        } elseif ($action === 'dismiss_recommendation') {
            $recommender->recordFeedback($workspaceId, $userId, $skillKey, 'dismissed', 'marketplace_page', null, ['source' => 'workspace_marketplace_page']);
            $recommendationEvents->recordEvent($workspaceId, $userId, $skillKey, 'marketplace', 'dismissed', [
                'recommendation_score' => $actionRecommendation['score'] ?? null,
                'priority' => (string) ($actionRecommendation['priority'] ?? ''),
                'reason_codes' => (array) ($actionRecommendation['reason_codes'] ?? []),
                'metadata' => array_merge(['source' => 'workspace_marketplace_page'], $actionAdaptiveMetadata),
            ]);
            $success = 'Recommendation dismissed.';
        } elseif ($action === 'snooze_recommendation') {
            $recommender->recordFeedback($workspaceId, $userId, $skillKey, 'snoozed', 'marketplace_page', date('Y-m-d H:i:s', strtotime('+14 days')), ['source' => 'workspace_marketplace_page']);
            $recommendationEvents->recordEvent($workspaceId, $userId, $skillKey, 'marketplace', 'snoozed', [
                'recommendation_score' => $actionRecommendation['score'] ?? null,
                'priority' => (string) ($actionRecommendation['priority'] ?? ''),
                'reason_codes' => (array) ($actionRecommendation['reason_codes'] ?? []),
                'metadata' => array_merge(['source' => 'workspace_marketplace_page'], $actionAdaptiveMetadata),
            ]);
            $success = 'Recommendation snoozed for 14 days.';
        } elseif (in_array($action, ['complete_setup_step', 'skip_setup_step', 'reset_setup_step'], true)) {
            $stepStatus = match ($action) {
                'complete_setup_step' => 'completed',
                'skip_setup_step' => 'skipped',
                default => 'pending',
            };
            $setupJourneys->updateStepStatus(
                $workspaceId,
                $userId,
                $skillKey,
                (string) ($_POST['setup_step_key'] ?? ''),
                $stepStatus,
                (string) ($_POST['setup_step_label'] ?? 'Setup step'),
                (string) ($_POST['setup_step_source'] ?? 'manual'),
                ['source' => 'workspace_marketplace_page']
            );
            $setupJourneyEvents->recordEvent(
                $workspaceId,
                $userId,
                $skillKey,
                match ($stepStatus) {
                    'completed' => 'step_completed',
                    'skipped' => 'step_skipped',
                    default => 'step_reset',
                },
                [
                    'step_key' => (string) ($_POST['setup_step_key'] ?? ''),
                    'label' => (string) ($_POST['setup_step_label'] ?? 'Setup step'),
                    'step_status' => $stepStatus,
                    'source' => 'workspace_marketplace_page',
                    'metadata' => [
                        'step_key' => (string) ($_POST['setup_step_key'] ?? ''),
                        'label' => (string) ($_POST['setup_step_label'] ?? 'Setup step'),
                    ],
                ]
            );
            $success = match ($stepStatus) {
                'completed' => 'Setup step marked done.',
                'skipped' => 'Setup step skipped.',
                default => 'Setup step reset.',
            };
        }
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }

    if ($error === null) {
        (new MarketplaceCacheService())->invalidateAfterMutation(
            $workspaceId,
            $userId,
            (string) ($skillKey ?? $_POST['skill_key'] ?? '')
        );
    }

    if ($marketplaceWantsJson) {
        if ($error !== null) {
            http_response_code(422);
        }
        header('Content-Type: application/json');
        echo json_encode(array_merge([
            'success' => $error === null,
            'message' => $error ?? $success ?? 'Saved',
        ], $marketplaceJsonPayload));
        exit;
    }
}

$requestedModuleKey = strtolower(trim(preg_replace('/[^a-z0-9_]+/', '_', (string) ($_GET['module'] ?? '')) ?? ''));
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $requestedModuleKey === WorkspaceSkillCatalogService::PLUGIN_COMMUNICATION_SETUP) {
    header('Location: workspace_skills.php?module=' . rawurlencode(WorkspaceSkillCatalogService::PLUGIN_EMAIL) . '&setup_required=communication');
    exit;
}
$marketplaceDeferCatalogIntelligence = $_SERVER['REQUEST_METHOD'] === 'GET';
$marketplaceNeedsInitialIntelligence = !$marketplaceDeferCatalogIntelligence && $requestedModuleKey !== '';

$available = $catalog->availableForWorkspace($workspaceId, $canEditMarketplaceCatalog);
$installed = $installer->installedForWorkspace($workspaceId);
$installedKeysForCoreCheck = array_fill_keys(array_map(static fn(array $skill): string => (string) ($skill['key'] ?? ''), $installed), true);
$missingRequiredCoreInstall = false;
foreach ($available as $availableModule) {
    $availableKey = (string) ($availableModule['key'] ?? '');
    if ($availableKey === '' || isset($installedKeysForCoreCheck[$availableKey])) {
        continue;
    }
    $availableMetadata = (array) ($availableModule['plugin_metadata'] ?? []);
    $availableCapabilities = (array) ($availableModule['capabilities'] ?? []);
    if (
        !empty($availableMetadata['protected_install'])
        || !empty($availableMetadata['required_core'])
        || !empty($availableCapabilities['protected_install'])
        || !empty($availableCapabilities['required_core'])
    ) {
        $missingRequiredCoreInstall = true;
        break;
    }
}
try {
    if ($missingRequiredCoreInstall) {
        $installer->installRequiredCorePlugins($workspaceId, $userId);
        $installed = $installer->installedForWorkspace($workspaceId);
    }
} catch (\Throwable $e) {
    $error = $error ?: $e->getMessage();
}
try {
    $installer->installLegacyLeanCanvasIfPresent($workspaceId, $userId, (new UserStrategyProfile())->get($userId) ?: []);
} catch (\Throwable $e) {
    // Legacy Lean Canvas data can still be shown through AI context if install is not allowed.
}
$installed = $installer->installedForWorkspace($workspaceId);
$moduleContext = $marketplaceNeedsInitialIntelligence
    ? $installer->buildContextForWorkspace($workspaceId, $userId)
    : ['readiness' => []];
$marketplaceRecommendationQueue = $marketplaceNeedsInitialIntelligence
    ? $recommender->recommendationsForWorkspace($workspaceId, $userId, 0, 'marketplace')
    : [];
$recommendations = array_slice($marketplaceRecommendationQueue, 0, 3);
$marketplaceRecommendationRankByKey = [];
$marketplaceRecommendationScoreByKey = [];
foreach ($marketplaceRecommendationQueue as $rank => $recommendation) {
    $recommendationKey = (string) ($recommendation['skill_key'] ?? '');
    if ($recommendationKey === '' || isset($marketplaceRecommendationRankByKey[$recommendationKey])) {
        continue;
    }
    $marketplaceRecommendationRankByKey[$recommendationKey] = $rank + 1;
    $marketplaceRecommendationScoreByKey[$recommendationKey] = (int) ($recommendation['score'] ?? 0);
}
$activationBundlesForWorkspace = $canManage && $marketplaceNeedsInitialIntelligence
    ? $activationBundles->bundlesForWorkspace($workspaceId, $userId, 3, 'marketplace')
    : [];
$readinessByKey = (array) ($moduleContext['readiness'] ?? []);
$installedByKey = [];
foreach ($installed as $skill) {
    $installedByKey[(string) ($skill['key'] ?? '')] = $skill;
}
$recommendationInsightsBySkill = $canManage && $marketplaceNeedsInitialIntelligence
    ? (new WorkspaceMarketplaceRecommendationInsightService())->marketplaceInsightsBySkill([
        'workspace_id' => $workspaceId,
        'date_from' => date('Y-m-d', strtotime('-7 days')),
        'date_to' => date('Y-m-d'),
    ])
    : [];
$activationBundleInsightsByBundle = $canManage && $marketplaceNeedsInitialIntelligence
    ? (new WorkspaceMarketplaceActivationBundleInsightService())->marketplaceInsightsByBundle([
        'workspace_id' => $workspaceId,
        'date_from' => date('Y-m-d', strtotime('-7 days')),
        'date_to' => date('Y-m-d'),
    ])
    : [];
$activeRecommendationControlsBySkill = $canManage && $marketplaceNeedsInitialIntelligence
    ? $recommendationControls->marketplaceControlsBySkill(['workspace_id' => $workspaceId])
    : [];
$setupJourneysBySkill = $canManage && $marketplaceNeedsInitialIntelligence
    ? $setupJourneys->journeysBySkill($workspaceId, $userId, $recommendations, $installedByKey, $readinessByKey)
    : [];
if ($canManage && $setupJourneysBySkill !== []) {
    $setupJourneyEvents->recordJourneyImpressions($workspaceId, $userId, array_values($setupJourneysBySkill), ['source' => 'workspace_marketplace_page']);
}
$modulesByType = ['skill' => [], 'plugin' => []];
foreach ($available as $module) {
    $type = (string) ($module['module_type'] ?? 'skill');
    $modulesByType[$type === 'plugin' ? 'plugin' : 'skill'][] = $module;
}

$pageTitle = 'Workspace Marketplace - ' . brandProductName();
$csrf = Security::getCsrfToken();
ob_start();
$marketplaceList = static function (array $values): array {
    return array_values(array_filter(array_map(static fn(mixed $value): string => trim((string) $value), $values), static fn(string $value): bool => $value !== ''));
};
$marketplaceIsProtectedInstall = static function (array $module): bool {
    $metadata = (array) ($module['plugin_metadata'] ?? []);
    $capabilities = (array) ($module['capabilities'] ?? []);

    return !empty($metadata['protected_install'])
        || !empty($metadata['required_core'])
        || !empty($capabilities['protected_install'])
        || !empty($capabilities['required_core']);
};
$marketplaceAsset = static function (string $path): string {
    $path = trim($path);
    if ($path === '') {
        return function_exists('assetUrl') ? assetUrl('images/clarity-logo-256.png') : 'assets/images/clarity-logo-256.png';
    }
    if (preg_match('#^https?://#i', $path) === 1 || str_starts_with($path, '/')) {
        return $path;
    }
    if (str_starts_with($path, 'assets/')) {
        $path = substr($path, 7);
    }
    if (str_starts_with($path, 'uploads/')) {
        return function_exists('publicUrl') ? publicUrl('../' . $path) : '../' . $path;
    }
    return function_exists('assetUrl') ? assetUrl($path) : 'assets/' . ltrim($path, '/');
};
$marketplaceAllowedRichImageSource = static function (string $src): bool {
    $src = trim($src);
    if ($src === '' || preg_match('/[\x00-\x1F\x7F]/', $src) === 1) {
        return false;
    }
    if (preg_match('#^https://#i', $src) === 1) {
        return true;
    }

    $normalized = ltrim($src, './');
    return str_starts_with($normalized, 'uploads/marketplace/')
        || str_starts_with($normalized, '../uploads/marketplace/')
        || str_starts_with($normalized, '/uploads/marketplace/');
};
$marketplaceSanitizeRichHtml = static function (string $html) use ($marketplaceAllowedRichImageSource): string {
    $html = trim(str_replace("\0", '', $html));
    if ($html === '') {
        return '';
    }

    $html = preg_replace('/<\s*(script|style|iframe|object|embed|form|input|button|select|textarea|link|meta|base)\b[^>]*>.*?<\s*\/\s*\1\s*>/is', '', $html) ?? $html;
    $html = preg_replace('/<\s*(script|style|iframe|object|embed|form|input|button|select|textarea|link|meta|base)\b[^>]*\/?\s*>/is', '', $html) ?? $html;
    $allowedTags = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'a', 'blockquote', 'pre', 'code', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'figure', 'figcaption', 'img', 'hr'];
    $html = strip_tags($html, '<' . implode('><', $allowedTags) . '>');

    return trim(preg_replace_callback('/<([a-z0-9]+)([^>]*)>/i', static function (array $matches) use ($marketplaceAllowedRichImageSource): string {
        $tag = strtolower((string) $matches[1]);
        $attrs = (string) $matches[2];
        $cleanAttrs = [];

        if ($tag === 'a' && preg_match('/\shref\s*=\s*(["\'])(.*?)\1/is', $attrs, $hrefMatch) === 1) {
            $href = html_entity_decode(trim((string) $hrefMatch[2]), ENT_QUOTES, 'UTF-8');
            if (preg_match('#^https?://#i', $href) === 1 || str_starts_with($href, 'mailto:')) {
                $cleanAttrs[] = 'href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"';
                $cleanAttrs[] = 'target="_blank"';
                $cleanAttrs[] = 'rel="noopener noreferrer"';
            }
        }

        if ($tag === 'img') {
            if (preg_match('/\ssrc\s*=\s*(["\'])(.*?)\1/is', $attrs, $srcMatch) === 1) {
                $src = html_entity_decode(trim((string) $srcMatch[2]), ENT_QUOTES, 'UTF-8');
                if ($marketplaceAllowedRichImageSource($src)) {
                    $cleanAttrs[] = 'src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '"';
                    $alt = '';
                    if (preg_match('/\salt\s*=\s*(["\'])(.*?)\1/is', $attrs, $altMatch) === 1) {
                        $alt = trim(strip_tags((string) $altMatch[2]));
                    }
                    $cleanAttrs[] = 'alt="' . htmlspecialchars(mb_substr($alt, 0, 255), ENT_QUOTES, 'UTF-8') . '"';
                    $cleanAttrs[] = 'loading="lazy"';
                }
            }

            if ($cleanAttrs === []) {
                return '';
            }
        }

        return '<' . $tag . ($cleanAttrs !== [] ? ' ' . implode(' ', $cleanAttrs) : '') . '>';
    }, $html) ?? $html);
};
$marketplaceTextToRichHtml = static function (string $text): string {
    $text = trim(str_replace("\0", '', $text));
    if ($text === '') {
        return '';
    }

    $html = '';
    $listItems = [];
    $flushList = static function () use (&$html, &$listItems): void {
        if ($listItems === []) {
            return;
        }
        $html .= '<ul>';
        foreach ($listItems as $item) {
            $html .= '<li>' . htmlspecialchars($item, ENT_QUOTES, 'UTF-8') . '</li>';
        }
        $html .= '</ul>';
        $listItems = [];
    };

    foreach (preg_split('/\R/', $text) ?: [] as $line) {
        $line = trim((string) $line);
        if ($line === '') {
            $flushList();
            continue;
        }
        if (preg_match('/^[-*]\s+(.+)$/', $line, $matches) === 1) {
            $listItems[] = (string) $matches[1];
            continue;
        }
        $flushList();
        $html .= '<p>' . htmlspecialchars($line, ENT_QUOTES, 'UTF-8') . '</p>';
    }
    $flushList();

    return $html;
};
$marketplaceRenderRichContent = static function (string $content, string $format) use ($marketplaceSanitizeRichHtml, $marketplaceTextToRichHtml): string {
    return strtolower(trim($format)) === 'html'
        ? $marketplaceSanitizeRichHtml($content)
        : $marketplaceTextToRichHtml($content);
};
$marketplaceLocalMediaPath = static function (string $rawUrl): string {
    $rawUrl = trim($rawUrl);
    if ($rawUrl === '' || preg_match('#^https?://#i', $rawUrl) === 1) {
        return '';
    }

    $path = (string) (parse_url($rawUrl, PHP_URL_PATH) ?: $rawUrl);
    $path = str_replace('\\', '/', $path);
    if (str_contains($path, '/uploads/')) {
        $path = substr($path, (int) strpos($path, '/uploads/') + 1);
    }
    $path = ltrim($path, '/');
    if (!str_starts_with($path, 'uploads/')) {
        return '';
    }

    return __DIR__ . '/../' . $path;
};
$marketplaceLocalMediaMissing = static function (string $rawUrl) use ($marketplaceLocalMediaPath): bool {
    $localPath = $marketplaceLocalMediaPath($rawUrl);
    return $localPath !== '' && !is_file($localPath);
};
$marketplaceVideoEmbed = static function (string $rawUrl) use ($marketplaceAsset, $marketplaceLocalMediaMissing): array {
    $rawUrl = trim($rawUrl);
    if ($rawUrl === '') {
        return ['type' => '', 'url' => ''];
    }
    if ($marketplaceLocalMediaMissing($rawUrl)) {
        return ['type' => 'missing', 'url' => '', 'raw_url' => $rawUrl];
    }
    if (preg_match('#^https?://#i', $rawUrl) === 1) {
        $host = strtolower((string) parse_url($rawUrl, PHP_URL_HOST));
        $path = (string) parse_url($rawUrl, PHP_URL_PATH);
        $query = [];
        parse_str((string) parse_url($rawUrl, PHP_URL_QUERY), $query);
        if (str_contains($host, 'youtube.com') || str_contains($host, 'youtu.be')) {
            $videoId = '';
            if (str_contains($host, 'youtu.be')) {
                $videoId = trim($path, '/');
            } elseif (isset($query['v'])) {
                $videoId = (string) $query['v'];
            } elseif (preg_match('#/(?:embed|shorts)/([^/?]+)#', $path, $matches) === 1) {
                $videoId = (string) $matches[1];
            }
            $videoId = preg_replace('/[^A-Za-z0-9_-]/', '', $videoId) ?? '';
            if ($videoId !== '') {
                return ['type' => 'iframe', 'url' => 'https://www.youtube.com/embed/' . $videoId];
            }
        }
        if (str_contains($host, 'vimeo.com') && preg_match('#/(\d+)#', $path, $matches) === 1) {
            return ['type' => 'iframe', 'url' => 'https://player.vimeo.com/video/' . $matches[1]];
        }

        return ['type' => 'video', 'url' => $rawUrl];
    }

    return ['type' => 'video', 'url' => $marketplaceAsset($rawUrl)];
};
$marketplaceCanPreviewVideo = static function (string $url): bool {
    $url = trim($url);
    if ($url === '') {
        return false;
    }

    $path = strtolower((string) parse_url($url, PHP_URL_PATH));
    return preg_match('/\.(mp4|m4v|webm|ogv|ogg|mov)$/', $path) === 1;
};
$marketplaceModules = [];
$marketplaceModulesByKey = [];
$marketplaceAccessData = [];
$marketplaceCanonicalTags = [
    'Setup required',
    'Connect channels',
    'AI guidance',
    'Strategy',
    'Marketing',
    'Sales follow-up',
    'People ops',
    'Meetings',
    'Communication',
    'Required core',
];
if ($isProtectedDemoMarketplace) {
    $marketplaceCanonicalTags = [
        'Connected channels',
        'AI guidance',
        'Strategy',
        'Marketing',
        'Sales follow-up',
        'People ops',
        'Meetings',
        'Communication',
        'Ready to run',
    ];
}
$marketplaceTagKey = static function (string $tag): string {
    return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($tag)) ?? '', '-');
};
$marketplaceNormalizeTags = static function (array $tags) use ($marketplaceCanonicalTags, $marketplaceTagKey): array {
    $canonicalByKey = [];
    foreach ($marketplaceCanonicalTags as $tag) {
        $canonicalByKey[$marketplaceTagKey($tag)] = $tag;
    }

    $out = [];
    foreach ($tags as $tag) {
        $key = $marketplaceTagKey((string) $tag);
        if ($key !== '' && isset($canonicalByKey[$key])) {
            $out[$key] = $canonicalByKey[$key];
        }
    }

    return array_values($out);
};
$marketplaceLightReadiness = static function (string $moduleKey, bool $installed, array $access = []): array {
    $state = (string) ($access['state'] ?? '');
    if ($state === WorkspaceMarketplaceAccessService::STATE_LOCKED_BY_PLAN) {
        return [
            'ready' => false,
            'status' => 'locked_by_plan',
            'message' => (string) ($access['message'] ?? 'This module is gated by the current package.'),
            'checks' => [],
            'blockers' => ['Plan upgrade'],
            'installed' => $installed,
            'skill_key' => $moduleKey,
        ];
    }

    return [
        'ready' => false,
        'status' => $installed ? 'checking' : 'available',
        'message' => $installed ? 'Checking setup readiness...' : 'Open this module to check setup readiness.',
        'checks' => [],
        'blockers' => [],
        'installed' => $installed,
        'skill_key' => $moduleKey,
    ];
};
$marketplaceProtectedDemoReadiness = static function (string $moduleKey): array {
    return [
        'ready' => true,
        'status' => 'ready',
        'message' => 'Configured, healthy, and running inside the protected Riverside demo.',
        'checks' => [
            ['label' => 'Workspace install', 'ok' => true, 'detail' => 'Enabled for the showcase workspace.'],
            ['label' => 'Runtime health', 'ok' => true, 'detail' => 'Demo-safe runtime is available.'],
            ['label' => 'Privacy mode', 'ok' => true, 'detail' => 'Visitor-created records stay inside the private session overlay.'],
        ],
        'blockers' => [],
        'installed' => true,
        'outbound_ready' => true,
        'inbound_ready' => true,
        'skill_key' => $moduleKey,
        'protected_demo_showcase' => true,
    ];
};
$marketplaceLightAccess = static function (array $module, bool $installed) use ($marketplaceLightReadiness): array {
    $skillKey = (string) ($module['key'] ?? '');
    $label = (string) ($module['label'] ?? ($skillKey !== '' ? ucwords(str_replace('_', ' ', $skillKey)) : 'Marketplace module'));
    $setupUrl = 'workspace_skills.php?module=' . rawurlencode($skillKey);
    $state = $installed
        ? WorkspaceMarketplaceAccessService::STATE_INSTALLED_NEEDS_SETUP
        : WorkspaceMarketplaceAccessService::STATE_AVAILABLE_TO_INSTALL;

    return [
        'skill_key' => $skillKey,
        'label' => $label,
        'state' => $state,
        'access_state' => $state,
        'is_locked' => false,
        'is_installed' => $installed,
        'can_install' => !$installed,
        'can_open' => true,
        'can_configure' => $installed,
        'can_run' => false,
        'message' => $installed ? 'Checking setup readiness...' : $label . ' can be installed for this workspace.',
        'why' => '',
        'requirements' => [],
        'completed_requirements' => [],
        'pending_requirements' => [],
        'root_blocker_skill_key' => '',
        'root_blocker_label' => '',
        'root_blocker_url' => '',
        'next_action_url' => $setupUrl,
        'next_action_label' => $installed ? 'Open setup' : 'Install ' . $label,
        'readiness' => $marketplaceLightReadiness($skillKey, $installed),
        'admin_bypass' => false,
        'admin_bypass_reason' => '',
        'bypassed_requirements' => [],
        'deferred' => true,
    ];
};
foreach (['skill', 'plugin'] as $moduleType) {
    foreach ($modulesByType[$moduleType] as $module) {
        $moduleKey = (string) ($module['key'] ?? '');
        if ($moduleKey === '') {
            continue;
        }
        if ($isProtectedDemoMarketplace && $moduleKey === WorkspaceSkillCatalogService::PLUGIN_AI_API) {
            continue;
        }
        $catalogOrder = count($marketplaceModules);
        $profile = (array) ($module['plugin_metadata']['marketplace_profile'] ?? []);
        $profileTags = $marketplaceNormalizeTags((array) ($profile['tags'] ?? []));
        if ($isProtectedDemoMarketplace) {
            $profileTags = array_values(array_unique(array_merge(
                array_filter($profileTags, static fn(string $tag): bool => !in_array(strtolower($tag), ['setup required', 'required core'], true)),
                ['Ready to run']
            )));
        }
        $profile['tags'] = $profileTags;
        $capabilities = array_keys(array_filter((array) ($module['capabilities'] ?? [])));
        $isInstalledForWorkspace = isset($installedByKey[$moduleKey]);
        $shouldResolveNow = !$marketplaceDeferCatalogIntelligence || $moduleKey === $requestedModuleKey;
        $access = $shouldResolveNow
            ? $marketplaceAccess->accessForDefinition($workspaceId, $userId, $module)
            : $marketplaceLightAccess($module, $isInstalledForWorkspace);
        $readiness = $shouldResolveNow
            ? $installer->buildReadinessForModule($workspaceId, $userId, $moduleKey)
            : $marketplaceLightReadiness($moduleKey, $isInstalledForWorkspace, $access);
        if ($isProtectedDemoMarketplace) {
            $isInstalledForWorkspace = true;
            $access = [
                'skill_key' => $moduleKey,
                'label' => (string) ($module['label'] ?? $moduleKey),
                'state' => WorkspaceMarketplaceAccessService::STATE_READY,
                'access_state' => WorkspaceMarketplaceAccessService::STATE_READY,
                'is_locked' => false,
                'is_installed' => true,
                'can_install' => false,
                'can_open' => true,
                'can_configure' => false,
                'can_run' => true,
                'message' => 'Configured and healthy in the protected Riverside demo.',
                'why' => '',
                'requirements' => [],
                'completed_requirements' => [],
                'pending_requirements' => [],
                'root_blocker_skill_key' => '',
                'root_blocker_label' => '',
                'root_blocker_url' => '',
                'next_action_url' => 'workspace_skills.php?module=' . rawurlencode($moduleKey),
                'next_action_label' => 'Open capability',
                'readiness' => $marketplaceProtectedDemoReadiness($moduleKey),
                'admin_bypass' => false,
                'admin_bypass_reason' => '',
                'bypassed_requirements' => [],
                'deferred' => false,
            ];
            $readiness = $marketplaceProtectedDemoReadiness($moduleKey);
        }
        $marketplaceAccessData[$moduleKey] = $access;
        $readinessByKey[$moduleKey] = $readiness;
        $setupUrl = (string) ($module['plugin_metadata']['setup_url'] ?? $module['navigation']['url'] ?? '');
        $marketplaceModules[] = [
            'module' => $module,
            'key' => $moduleKey,
            'type' => $moduleType,
            'catalog_order' => $catalogOrder,
            'is_installed' => $isInstalledForWorkspace,
            'capabilities' => $capabilities,
            'readiness' => $readiness,
            'access' => $access,
            'setup_url' => $setupUrl,
            'profile' => $profile,
            'tags' => $profileTags,
            'thumbnail_url' => $marketplaceAsset((string) ($profile['thumbnail_url'] ?? '')),
            'search_text' => strtolower(trim(implode(' ', array_filter([
                $moduleKey,
                (string) ($module['label'] ?? ''),
                (string) ($module['summary'] ?? ''),
                (string) ($module['category'] ?? ''),
                $moduleType,
                implode(' ', array_map('strval', $profileTags)),
                (string) ($profile['pitch'] ?? ''),
                implode(' ', array_map('strval', $capabilities)),
                implode(' ', array_map('strval', (array) ($module['advice_domains'] ?? []))),
            ])))),
        ];
        $marketplaceModulesByKey[$moduleKey] = $marketplaceModules[array_key_last($marketplaceModules)];
    }
}
$marketplaceRootBlockerKeys = [];
foreach ($marketplaceModules as $marketplaceItem) {
    $rootBlockerKey = (string) ($marketplaceItem['access']['root_blocker_skill_key'] ?? '');
    if ($rootBlockerKey !== '') {
        $marketplaceRootBlockerKeys[$rootBlockerKey] = true;
    }
}
foreach ($marketplaceModules as $marketplaceIndex => $marketplaceItem) {
    $module = (array) ($marketplaceItem['module'] ?? []);
    $moduleKey = (string) ($marketplaceItem['key'] ?? '');
    $access = (array) ($marketplaceItem['access'] ?? []);
    $readiness = (array) ($marketplaceItem['readiness'] ?? []);
    $isInstalled = !empty($marketplaceItem['is_installed']);
    $isReady = (string) ($access['state'] ?? '') === WorkspaceMarketplaceAccessService::STATE_READY
        || ($isInstalled && empty($access['is_locked']) && !empty($readiness['ready']));
    $isRootBlocker = isset($marketplaceRootBlockerKeys[$moduleKey]);
    $isRequired = $marketplaceIsProtectedInstall($module);
    $recommendationRank = (int) ($marketplaceRecommendationRankByKey[$moduleKey] ?? 0);
    $sortRecommendationRank = $recommendationRank > 0 ? $recommendationRank : PHP_INT_MAX;
    $recommendationScore = (int) ($marketplaceRecommendationScoreByKey[$moduleKey] ?? 0);
    $sortBucket = 30;
    $sortPriority = $sortRecommendationRank === PHP_INT_MAX ? 1 : 0;

    if (($isRootBlocker || $isRequired) && !$isReady) {
        $sortBucket = 10;
        $sortPriority = $isRootBlocker ? 0 : 1;
    } elseif ($isInstalled && !$isReady) {
        $sortBucket = 20;
        $sortPriority = !empty($access['is_locked']) ? 0 : 1;
    } elseif ($isInstalled && $isReady) {
        $sortBucket = 40;
        $sortPriority = 0;
    }

    $marketplaceModules[$marketplaceIndex]['sort_bucket'] = $sortBucket;
    $marketplaceModules[$marketplaceIndex]['sort_priority'] = $sortPriority;
    $marketplaceModules[$marketplaceIndex]['sort_recommendation_rank'] = $sortRecommendationRank;
    $marketplaceModules[$marketplaceIndex]['recommendation_rank'] = $recommendationRank;
    $marketplaceModules[$marketplaceIndex]['recommendation_score'] = $recommendationScore;
}
usort($marketplaceModules, static function (array $left, array $right): int {
    $leftBucket = (int) ($left['sort_bucket'] ?? 30);
    $rightBucket = (int) ($right['sort_bucket'] ?? 30);
    if ($leftBucket !== $rightBucket) {
        return $leftBucket <=> $rightBucket;
    }

    $leftOrder = (int) ($left['catalog_order'] ?? 0);
    $rightOrder = (int) ($right['catalog_order'] ?? 0);
    $priority = ((int) ($left['sort_priority'] ?? 0)) <=> ((int) ($right['sort_priority'] ?? 0));
    if ($priority !== 0) {
        return $priority;
    }

    $rank = ((int) ($left['sort_recommendation_rank'] ?? PHP_INT_MAX)) <=> ((int) ($right['sort_recommendation_rank'] ?? PHP_INT_MAX));
    if ($rank !== 0) {
        return $rank;
    }

    $score = ((int) ($right['recommendation_score'] ?? 0)) <=> ((int) ($left['recommendation_score'] ?? 0));
    if ($score !== 0) {
        return $score;
    }

    $typePriority = static function (array $item): int {
        return (string) ($item['type'] ?? '') === 'plugin' ? 0 : 1;
    };
    $type = $typePriority($left) <=> $typePriority($right);
    if ($type !== 0) {
        return $type;
    }

    $order = $leftOrder <=> $rightOrder;
    if ($order !== 0) {
        return $order;
    }

    return strcmp((string) ($left['module']['label'] ?? $left['key'] ?? ''), (string) ($right['module']['label'] ?? $right['key'] ?? ''));
});
$marketplaceModulesByKey = [];
foreach ($marketplaceModules as $marketplaceItem) {
    $marketplaceModulesByKey[(string) ($marketplaceItem['key'] ?? '')] = $marketplaceItem;
}
$marketplaceNextActionModules = $marketplaceModules;
if ($marketplaceDeferCatalogIntelligence && !$isProtectedDemoMarketplace) {
    foreach ($marketplaceNextActionModules as $marketplaceIndex => $marketplaceItem) {
        if (empty($marketplaceItem['is_installed'])) {
            continue;
        }

        $moduleKey = (string) ($marketplaceItem['key'] ?? '');
        $module = (array) ($marketplaceItem['module'] ?? []);
        if ($moduleKey === '' || $module === []) {
            continue;
        }

        try {
            $resolvedAccess = $marketplaceAccess->accessForDefinition($workspaceId, $userId, $module);
            $resolvedReadiness = (array) ($resolvedAccess['readiness'] ?? []);
            if ($resolvedReadiness === []) {
                $resolvedReadiness = $installer->buildReadinessForModule($workspaceId, $userId, $moduleKey);
            }

            $marketplaceNextActionModules[$marketplaceIndex]['access'] = $resolvedAccess;
            $marketplaceNextActionModules[$marketplaceIndex]['readiness'] = $resolvedReadiness;
        } catch (\Throwable $e) {
            // Keep the Marketplace page fast and let the async catalog status hydrate detailed failures.
        }
    }
}
$marketplaceInstalledCount = count(array_filter(
    $marketplaceModules,
    static fn(array $item): bool => !empty($item['is_installed'])
));
$marketplaceAvailablePluginCount = count(array_filter(
    $marketplaceModules,
    static function (array $item): bool {
        $module = (array) ($item['module'] ?? []);
        $access = (array) ($item['access'] ?? []);
        return (string) ($item['type'] ?? '') === 'plugin'
            && empty($item['is_installed'])
            && (string) ($access['state'] ?? '') === WorkspaceMarketplaceAccessService::STATE_AVAILABLE_TO_INSTALL
            && (string) ($module['catalog_status'] ?? WorkspaceSkillCatalogService::CATALOG_STATUS_VISIBLE) === WorkspaceSkillCatalogService::CATALOG_STATUS_VISIBLE;
    }
));
$marketplaceSetupNeededCount = count(array_filter(
    $marketplaceModules,
    static fn(array $item): bool => !empty($item['is_installed']) && (!empty($item['access']['is_locked']) || empty($item['readiness']['ready']))
));
if ($isProtectedDemoMarketplace) {
    $marketplaceInstalledCount = max($marketplaceInstalledCount, count($marketplaceModules));
    $marketplaceAvailablePluginCount = count(array_filter(
        $marketplaceModules,
        static fn(array $item): bool => (string) ($item['type'] ?? '') === 'plugin'
    ));
    $marketplaceSetupNeededCount = 0;
}
$marketplaceNextAction = $marketplaceNextActions->nextActionFromMarketplaceModules($marketplaceNextActionModules, $canManageMarketplace);
if ($isProtectedDemoMarketplace) {
    $marketplaceNextAction = [
        'skill_key' => '',
        'label' => 'Review configured capabilities',
        'url' => '#marketplace-card-grid',
        'kind' => 'browse',
        'message' => 'Email, WhatsApp, AI drafting, targets, tasks, and contact intelligence are already wired for the Riverside story.',
        'is_actionable' => false,
    ];
}
$marketplaceNextActionLabel = (string) ($marketplaceNextAction['label'] ?? 'Browse catalog');
$marketplaceNextActionUrl = (string) ($marketplaceNextAction['url'] ?? '#marketplace-card-grid');
$marketplaceNextActionSkillKey = (string) ($marketplaceNextAction['skill_key'] ?? '');
$marketplaceNextActionMessage = trim((string) ($marketplaceNextAction['message'] ?? ''));
$marketplaceNextActionBundleKey = '';
$marketplaceNextActionSetupJourneyOpen = isset($setupJourneysBySkill[$marketplaceNextActionSkillKey]) ? '1' : '0';
$marketplaceBundleDefinitionsForAdmin = $canEditMarketplaceCatalog
    ? $activationBundles->allDefinitionsForAdmin()
    : [];
$marketplaceVideoInventory = $canEditMarketplaceCatalog
    ? $marketplaceUploadCleanup->videoInventory()
    : [
        'local_video_files' => 0,
        'local_video_bytes' => 0,
        'catalog_video_refs' => 0,
        'bundle_video_refs' => 0,
        'page_video_refs' => 0,
        'errors' => 0,
    ];
$startupJourneyPageExplainer = $canEditMarketplaceCatalog
    ? ($pageExplainers->get(MarketplacePageExplainerService::PAGE_STARTUP_JOURNEY) ?? [])
    : [];
$founderLoopPageExplainer = $canEditMarketplaceCatalog
    ? ($pageExplainers->get(MarketplacePageExplainerService::PAGE_FOUNDER_OPERATING_LOOP) ?? [])
    : [];
$financePageExplainer = $canEditMarketplaceCatalog
    ? ($pageExplainers->get(MarketplacePageExplainerService::PAGE_FINANCE) ?? [])
    : [];
$marketplaceDefaultThumbnail = $marketplaceAsset('');
$selectedMarketplaceItem = $requestedModuleKey !== '' ? ($marketplaceModulesByKey[$requestedModuleKey] ?? null) : null;
$requestedLockedMarketplaceItem = null;
$requestedLockedModuleKey = '';
$requestedSetupTab = strtolower(trim(preg_replace('/[^a-z0-9_]+/', '_', (string) ($_GET['setup_tab'] ?? '')) ?? ''));
if ($selectedMarketplaceItem !== null && !empty($selectedMarketplaceItem['access']['is_locked'])) {
    $requestedLockedMarketplaceItem = $selectedMarketplaceItem;
    $requestedLockedModuleKey = $requestedModuleKey;
    $selectedMarketplaceItem = null;
}
if ($requestedModuleKey !== '' && $selectedMarketplaceItem === null && $requestedLockedMarketplaceItem === null) {
    $error = $error ?: 'Marketplace module not found.';
}
if (
    $_SERVER['REQUEST_METHOD'] === 'GET'
    && $requestedModuleKey === ''
    && !empty($marketplaceNextAction['is_actionable'])
    && !$isProtectedDemoMarketplace
) {
    try {
        $marketplaceNextActions->ensureNotification($workspaceId, $userId, $marketplaceNextAction);
    } catch (\Throwable $e) {
        // The Marketplace page should never block on a notification nudge.
    }
}
$guidedDestination = [];
try {
    $guidedDestination = $guidedDestinations->destinationFor($workspaceId, $userId, [
        'mode' => $marketplaceExperienceMode,
        'current_page' => 'workspace_skills.php',
        'surface' => 'workspace_skills',
        'source' => (string) ($_GET['source'] ?? 'direct'),
        'action' => (string) ($_GET['action'] ?? ''),
        'gap' => (string) ($_GET['gap'] ?? ''),
        'module' => $requestedModuleKey,
        'setup_tab' => $requestedSetupTab,
        'next_action' => $marketplaceNextAction,
        'recommendations' => $marketplaceRecommendationQueue,
        'installed_by_key' => $installedByKey,
        'readiness_by_key' => $readinessByKey,
        'journeys_by_skill' => $setupJourneysBySkill,
        'marketplace_modules_by_key' => $marketplaceModulesByKey,
    ]);
} catch (\Throwable $e) {
    $guidedDestination = [];
}
$showGuidedDestination = $marketplaceModeIsBeginner && !empty($guidedDestination['show_guidance']);
$guidedDestinationSource = (string) ($guidedDestination['source'] ?? 'direct');
$guidedDestinationModuleKey = (string) ($guidedDestination['module_key'] ?? '');
if (
    $_SERVER['REQUEST_METHOD'] === 'GET'
    && $showGuidedDestination
    && $guidedDestinationModuleKey !== ''
    && in_array($guidedDestinationSource, ['dashboard_guidance', 'dashboard_readiness'], true)
) {
    $setupJourneyEvents->recordEvent($workspaceId, $userId, $guidedDestinationModuleKey, 'setup_opened', [
        'label' => (string) ($guidedDestination['headline'] ?? ''),
        'source' => $guidedDestinationSource,
        'metadata' => [
            'gap' => (string) ($_GET['gap'] ?? ''),
            'action' => (string) ($_GET['action'] ?? ''),
            'module_key' => $guidedDestinationModuleKey,
            'cta_href' => (string) (($guidedDestination['primary_step']['href'] ?? '') ?: ''),
        ],
    ]);
}
$marketplaceModuleVisual = static function (string $skillKey) use ($marketplaceModulesByKey, $marketplaceDefaultThumbnail): array {
    $item = (array) ($marketplaceModulesByKey[$skillKey] ?? []);
    $module = (array) ($item['module'] ?? []);
    $profile = (array) ($item['profile'] ?? []);
    $label = (string) ($module['label'] ?? ($skillKey !== '' ? ucwords(str_replace('_', ' ', $skillKey)) : 'Marketplace item'));

    return [
        'key' => $skillKey,
        'label' => $label,
        'type' => (string) ($item['type'] ?? ''),
        'thumbnail_url' => (string) ($item['thumbnail_url'] ?? $marketplaceDefaultThumbnail),
        'thumbnail_alt' => (string) ($profile['thumbnail_alt'] ?? ($label . ' marketplace thumbnail')),
        'is_installed' => !empty($item['is_installed']),
    ];
};
$marketplaceBundleVisual = static function (array $bundle) use ($marketplaceModuleVisual, $marketplaceModulesByKey, $marketplaceDefaultThumbnail, $marketplaceAsset): array {
    $bundleThumbnail = trim((string) ($bundle['thumbnail_url'] ?? ''));
    if ($bundleThumbnail !== '') {
        return [
            'key' => (string) ($bundle['bundle_key'] ?? ''),
            'label' => (string) ($bundle['label'] ?? 'Activation bundle'),
            'type' => 'bundle',
            'thumbnail_url' => $marketplaceAsset($bundleThumbnail),
            'thumbnail_alt' => ((string) ($bundle['label'] ?? 'Activation bundle')) . ' marketplace bundle thumbnail',
            'is_installed' => false,
        ];
    }

    $nextAction = (array) ($bundle['next_action'] ?? []);
    $candidateKeys = [];
    $nextSkillKey = (string) ($nextAction['skill_key'] ?? '');
    if ($nextSkillKey !== '') {
        $candidateKeys[] = $nextSkillKey;
    }
    foreach (['recommended_skill_keys', 'included_skill_keys', 'installed_skill_keys'] as $keyList) {
        foreach ((array) ($bundle[$keyList] ?? []) as $skillKey) {
            $candidateKeys[] = (string) $skillKey;
        }
    }

    $fallback = null;
    foreach (array_values(array_unique(array_filter($candidateKeys))) as $candidateKey) {
        $visual = $marketplaceModuleVisual($candidateKey);
        if ($fallback === null && $visual['thumbnail_url'] !== $marketplaceDefaultThumbnail) {
            $fallback = $visual;
        }
        if (($marketplaceModulesByKey[$candidateKey]['type'] ?? '') === 'plugin') {
            return $visual;
        }
    }

    return $fallback ?? [
        'key' => '',
        'label' => (string) ($bundle['label'] ?? 'Activation bundle'),
        'type' => '',
        'thumbnail_url' => $marketplaceDefaultThumbnail,
        'thumbnail_alt' => ((string) ($bundle['label'] ?? 'Activation bundle')) . ' marketplace thumbnail',
        'is_installed' => false,
    ];
};
$marketplaceDetailData = [];
foreach ($marketplaceModules as $marketplaceItem) {
    $module = (array) $marketplaceItem['module'];
    $profile = (array) $marketplaceItem['profile'];
    $key = (string) $marketplaceItem['key'];
    $marketplaceDetailData[$key] = [
        'key' => $key,
        'label' => (string) ($module['label'] ?? $key),
        'type' => (string) $marketplaceItem['type'],
        'category' => (string) ($module['category'] ?? 'module'),
        'thumbnail_url' => (string) $marketplaceItem['thumbnail_url'],
        'thumbnail_alt' => (string) ($profile['thumbnail_alt'] ?? (($module['label'] ?? $key) . ' marketplace thumbnail')),
        'pitch' => (string) ($profile['pitch'] ?? $module['summary'] ?? ''),
        'access_state' => (string) ($marketplaceItem['access']['state'] ?? ''),
        'root_blocker_skill_key' => (string) ($marketplaceItem['access']['root_blocker_skill_key'] ?? ''),
        'recommendations' => $marketplaceList((array) ($profile['recommendations'] ?? [])),
        'prerequisites' => $marketplaceList((array) ($profile['prerequisites'] ?? $module['dependencies'] ?? [])),
        'setup_guide' => $marketplaceList((array) ($profile['setup_guide'] ?? $module['setup_steps'] ?? [])),
    ];
}

$marketplaceAssistantConfigByType = $selectedMarketplaceItem !== null
    ? [
        'email' => $assistantConfig->get($workspaceId, 'email', false),
        'whatsapp' => $assistantConfig->get($workspaceId, 'whatsapp', false),
    ]
    : ['email' => [], 'whatsapp' => []];
$marketplaceChannelHealth = $selectedMarketplaceItem !== null ? $channelHealth->summarize($workspaceId, $user) : [];
$marketplaceEmailSettingsForForm = static function (array $settings, array $fallback = []): array {
    $fromEmail = (string) ($settings['from_email'] ?? $fallback['from_email'] ?? '');
    $imapEnabled = !empty($settings['imap_enabled']);
    $smtpUsername = (string) ($settings['smtp_username'] ?? $fallback['smtp_username'] ?? '');
    $imapUsername = (string) ($settings['imap_username'] ?? $fallback['imap_username'] ?? '');
    if ($smtpUsername === '' && $fromEmail !== '') {
        $smtpUsername = $fromEmail;
    }
    if ($imapEnabled && $imapUsername === '' && $fromEmail !== '') {
        $imapUsername = $fromEmail;
    }

    return [
        'smtp_host' => (string) ($settings['smtp_host'] ?? $fallback['smtp_host'] ?? ''),
        'smtp_port' => (string) ($settings['smtp_port'] ?? $fallback['smtp_port'] ?? '587'),
        'smtp_username' => $smtpUsername,
        'smtp_password_saved' => !empty($settings['smtp_password']),
        'smtp_encryption' => (string) ($settings['smtp_encryption'] ?? $fallback['smtp_encryption'] ?? 'tls'),
        'from_email' => $fromEmail,
        'from_name' => (string) ($settings['from_name'] ?? $fallback['from_name'] ?? ''),
        'imap_enabled' => $imapEnabled,
        'imap_host' => (string) ($settings['imap_host'] ?? $fallback['imap_host'] ?? ''),
        'imap_port' => (string) ($settings['imap_port'] ?? $fallback['imap_port'] ?? '993'),
        'imap_username' => $imapUsername,
        'imap_password_saved' => !empty($settings['imap_password']),
        'imap_encryption' => (string) ($settings['imap_encryption'] ?? $fallback['imap_encryption'] ?? 'ssl'),
        'imap_folder' => (string) ($settings['imap_folder'] ?? $fallback['imap_folder'] ?? 'INBOX'),
    ];
};
$marketplaceSecretState = static function (bool $saved): string {
    return $saved ? 'Saved' : 'Not saved';
};
$marketplaceEmailSettingsInUse = static function (array $settings, string $sourceLabel) use ($marketplaceSecretState): array {
    return [
        ['label' => 'Source', 'value' => $sourceLabel],
        ['label' => 'From email', 'value' => (string) ($settings['from_email'] ?? '')],
        ['label' => 'From name', 'value' => (string) ($settings['from_name'] ?? '')],
        ['label' => 'SMTP host', 'value' => (string) ($settings['smtp_host'] ?? '')],
        ['label' => 'SMTP port', 'value' => (string) ($settings['smtp_port'] ?? '')],
        ['label' => 'SMTP username', 'value' => (string) ($settings['smtp_username'] ?? '')],
        ['label' => 'SMTP password', 'value' => $marketplaceSecretState(!empty($settings['smtp_password_saved']))],
        ['label' => 'SMTP encryption', 'value' => strtoupper((string) ($settings['smtp_encryption'] ?? ''))],
        ['label' => 'IMAP', 'value' => !empty($settings['imap_enabled']) ? 'Enabled' : 'Disabled'],
        ['label' => 'IMAP host', 'value' => (string) ($settings['imap_host'] ?? '')],
        ['label' => 'IMAP port', 'value' => (string) ($settings['imap_port'] ?? '')],
        ['label' => 'IMAP username', 'value' => (string) ($settings['imap_username'] ?? '')],
        ['label' => 'IMAP password', 'value' => $marketplaceSecretState(!empty($settings['imap_password_saved']))],
        ['label' => 'IMAP folder', 'value' => (string) ($settings['imap_folder'] ?? '')],
    ];
};
$marketplaceWhatsAppSettingsInUse = static function (array $settings): array {
    $items = [
        ['label' => 'Source', 'value' => (string) ($settings['source_label'] ?? '')],
        ['label' => 'Mode', 'value' => (string) ($settings['connection_mode_label'] ?? '')],
        ['label' => 'Connection', 'value' => (string) ($settings['connection_status'] ?? '')],
        ['label' => 'Provider', 'value' => (string) ($settings['provider'] ?? '')],
        ['label' => 'Phone number ID', 'value' => (string) ($settings['phone_number_id'] ?? '')],
        ['label' => 'Display number', 'value' => (string) ($settings['display_phone_number'] ?? '')],
        ['label' => 'Verified name', 'value' => (string) ($settings['verified_name'] ?? '')],
        ['label' => 'WABA ID', 'value' => (string) ($settings['whatsapp_business_account_id'] ?? '')],
        ['label' => 'Meta business ID', 'value' => (string) ($settings['meta_business_id'] ?? '')],
        ['label' => 'Access token', 'value' => !empty($settings['access_token_saved']) ? 'Saved' : 'Not saved'],
        ['label' => 'Webhook URL', 'value' => (string) ($settings['webhook_callback_url'] ?? '')],
        ['label' => 'Verify token', 'value' => !empty($settings['webhook_verify_token_saved']) ? 'Saved' : 'Not saved'],
        ['label' => 'Notes', 'value' => (string) ($settings['notes'] ?? '')],
    ];
    if (!empty($settings['meta_app_configured'])) {
        $items[] = ['label' => 'Meta app ID', 'value' => (string) ($settings['meta_app_id'] ?? '')];
        $items[] = ['label' => 'Meta app secret', 'value' => !empty($settings['meta_app_secret_saved']) ? 'Saved' : 'Not saved'];
    }
    if (!empty($settings['embedded_signup_configured'])) {
        $items[] = ['label' => 'Embedded signup config', 'value' => (string) ($settings['embedded_signup_config_id'] ?? '')];
    }
    if ((string) ($settings['connection_mode'] ?? '') === 'platform_managed') {
        $items[] = ['label' => 'Managed status', 'value' => (string) ($settings['managed_status'] ?? '')];
        $items[] = ['label' => 'Credit balance', 'value' => trim((string) ($settings['managed_currency'] ?? 'KES') . ' ' . number_format((float) ($settings['managed_credit_balance'] ?? 0), 2))];
        $items[] = ['label' => 'Reserved credits', 'value' => trim((string) ($settings['managed_currency'] ?? 'KES') . ' ' . number_format((float) ($settings['managed_credit_reserved'] ?? 0), 2))];
    }

    return $items;
};
$marketplaceCommunicationSetupContext = static function () use (
    $assistantConfig,
    $emailIntegrations,
    $marketplaceChannelHealth,
    $marketplaceEmailSettingsInUse,
    $marketplaceEmailSettingsForForm,
    $marketplaceWhatsAppSettingsInUse,
    $workspaceConnect,
    $userId,
    $workspaceId
): array {
    $assistantEmail = $assistantConfig->get($workspaceId, 'email', false);
    $assistantSettings = (array) ($assistantEmail['settings'] ?? []);
    $roleSpecs = [
        'outreach' => [
            'label' => 'Outreach Email',
            'scope' => EmailIntegrationService::SCOPE_OUTREACH_EMAIL,
            'health_key' => 'outreach_email',
        ],
        'nurture' => [
            'label' => 'Nurture Email',
            'scope' => EmailIntegrationService::SCOPE_NURTURE_EMAIL,
            'health_key' => 'nurture_email',
        ],
        'assistant' => [
            'label' => 'Email Assistant',
            'scope' => EmailIntegrationService::SCOPE_ASSISTANT_EMAIL,
            'health_key' => 'assistant_email',
            'settings' => $marketplaceEmailSettingsForForm($assistantSettings),
        ],
    ];

    $emailRoles = [];
    foreach ($roleSpecs as $roleKey => $spec) {
        $settings = (array) ($spec['settings'] ?? []);
        $sourceLabel = 'Not configured';
        $settingsInUse = $settings;
        if ($settings === []) {
            $integration = $emailIntegrations->getActiveScopeIntegration((string) $spec['scope'], $workspaceId);
            $integrationSettings = (array) ($integration['settings'] ?? []);
            $settings = $marketplaceEmailSettingsForForm($integrationSettings, [
                'from_email' => (string) ($integration['email_address'] ?? ''),
            ]);

            $effectiveIntegration = $emailIntegrations->getActiveRoleIntegration((string) $roleKey, $workspaceId);
            if ($effectiveIntegration) {
                $effectiveSettings = (array) ($effectiveIntegration['settings'] ?? []);
                $settingsInUse = $marketplaceEmailSettingsForForm($effectiveSettings, [
                    'from_email' => (string) ($effectiveIntegration['email_address'] ?? ''),
                ]);
                $sourceLabel = 'Workspace role settings';
            }
        } else {
            $sourceLabel = !empty($assistantEmail) ? 'Workspace assistant settings' : 'Not configured';
            $settingsInUse = !empty($assistantEmail) ? $settings : [];
        }
        $emailRoles[] = [
            'key' => $roleKey,
            'label' => (string) $spec['label'],
            'health_key' => (string) $spec['health_key'],
            'settings' => $settings,
            'settings_in_use' => $marketplaceEmailSettingsInUse($settingsInUse, $sourceLabel),
            'health' => (array) ($marketplaceChannelHealth[(string) $spec['health_key']] ?? []),
        ];
    }

    $activeWhatsApp = $workspaceConnect->getActiveWhatsAppIntegration($workspaceId) ?: [];
    $whatsAppMetadata = json_decode((string) ($activeWhatsApp['settings_json'] ?? '{}'), true);
    if (!is_array($whatsAppMetadata)) {
        $whatsAppMetadata = [];
    }
    $tokenExpiresAt = trim((string) ($activeWhatsApp['token_expires_at'] ?? ''));
    $tokenExpiresLocal = '';
    if ($tokenExpiresAt !== '' && strtotime($tokenExpiresAt) !== false) {
        $tokenExpiresLocal = date('Y-m-d\TH:i', strtotime($tokenExpiresAt));
    }
    $webhookToken = trim((string) ($activeWhatsApp['webhook_token'] ?? ''));
    $webhookCallbackUrl = $workspaceConnect->buildWhatsAppWebhookCallbackUrl($webhookToken);
    $metaAppId = trim((string) ($_ENV['META_APP_ID'] ?? ''));
    $metaAppSecretSaved = trim((string) ($_ENV['META_APP_SECRET'] ?? '')) !== '';
    $embeddedSignupConfigId = trim((string) ($_ENV['META_WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID'] ?? $_ENV['META_EMBEDDED_SIGNUP_CONFIG_ID'] ?? ''));
    $metaAppConfigured = $metaAppId !== '' && $metaAppSecretSaved;
    $embeddedSignupConfigured = $metaAppConfigured && $embeddedSignupConfigId !== '';
    $whatsAppMode = (string) ($activeWhatsApp['connection_mode'] ?? 'self_managed');
    $whatsAppMode = $whatsAppMode === 'platform_managed' ? 'platform_managed' : 'self_managed';
    $whatsAppFeatureGate = new WhatsAppFeatureGate();
    $whatsAppFeatureFlags = $whatsAppFeatureGate->snapshot($workspaceId);
    try {
        $managedWhatsAppReadiness = (new ManagedWhatsAppProvisioningService())->platformReadiness($workspaceId);
    } catch (\Throwable $e) {
        $managedWhatsAppReadiness = [
            'available' => false,
            'missing' => ['managed_provisioning_service'],
            'missing_labels' => ['Managed provisioning service'],
        ];
    }
    try {
        $whatsAppCreditSummary = $whatsAppMode === 'platform_managed' && !empty($whatsAppFeatureFlags['whatsapp_managed_billing'])
            ? (new WorkspaceWhatsAppCreditService())->summary($workspaceId)
            : [];
    } catch (\Throwable $e) {
        $whatsAppCreditSummary = ['error' => $e->getMessage()];
    }
    $whatsAppSettings = [
        'source_label' => !empty($activeWhatsApp) ? ($whatsAppMode === 'platform_managed' ? 'Platform-managed WhatsApp' : 'Workspace manual settings') : 'Not configured',
        'connection_mode' => $whatsAppMode,
        'connection_mode_label' => $whatsAppMode === 'platform_managed' ? 'Managed WhatsApp' : 'Self-managed Meta account',
        'connection_status' => (string) ($activeWhatsApp['connection_status'] ?? ''),
        'provider' => (string) ($whatsAppMetadata['provider'] ?? 'meta_cloud'),
        'phone_number_id' => (string) ($activeWhatsApp['phone_number_id'] ?? ''),
        'display_phone_number' => (string) ($activeWhatsApp['display_phone_number'] ?? ''),
        'access_token_saved' => trim((string) ($activeWhatsApp['access_token'] ?? '')) !== '',
        'verified_name' => (string) ($activeWhatsApp['verified_name'] ?? ''),
        'whatsapp_business_account_id' => (string) ($activeWhatsApp['whatsapp_business_account_id'] ?? ''),
        'meta_business_id' => (string) ($activeWhatsApp['meta_business_id'] ?? ''),
        'token_expires_at_local' => $tokenExpiresLocal,
        'token_expires_at_display' => $tokenExpiresAt,
        'notes' => (string) ($whatsAppMetadata['manual_notes'] ?? ''),
        'webhook_token' => $webhookToken,
        'webhook_callback_url' => $webhookCallbackUrl,
        'webhook_verify_token' => (string) ($activeWhatsApp['webhook_verify_token'] ?? ''),
        'webhook_verify_token_saved' => trim((string) ($activeWhatsApp['webhook_verify_token'] ?? '')) !== '',
        'webhook_verified_at' => (string) ($activeWhatsApp['webhook_verified_at'] ?? ''),
        'webhook_last_status' => (string) ($activeWhatsApp['webhook_last_status'] ?? ''),
        'webhook_last_error' => (string) ($activeWhatsApp['webhook_last_error'] ?? ''),
        'webhook_last_event_at' => (string) ($activeWhatsApp['webhook_last_event_at'] ?? ''),
        'meta_app_id' => $metaAppId,
        'meta_app_secret_saved' => $metaAppSecretSaved,
        'meta_app_configured' => $metaAppConfigured,
        'embedded_signup_config_id' => $embeddedSignupConfigId,
        'embedded_signup_configured' => $embeddedSignupConfigured,
        'managed_available' => !empty($managedWhatsAppReadiness['available']),
        'feature_flags' => $whatsAppFeatureFlags,
        'dual_setup_modes_enabled' => !empty($whatsAppFeatureFlags['whatsapp_dual_setup_modes']),
        'template_center_enabled' => !empty($whatsAppFeatureFlags['whatsapp_template_center']),
        'managed_billing_enabled' => !empty($whatsAppFeatureFlags['whatsapp_managed_billing']),
        'managed_missing_configuration' => (array) ($managedWhatsAppReadiness['missing'] ?? []),
        'managed_missing_configuration_labels' => (array) ($managedWhatsAppReadiness['missing_labels'] ?? []),
        'managed_status' => (string) ($activeWhatsApp['managed_status'] ?? ''),
        'managed_billing_status' => (string) ($activeWhatsApp['managed_billing_status'] ?? ''),
        'managed_provider_reference' => (string) ($activeWhatsApp['managed_provider_reference'] ?? ''),
        'managed_credit_balance' => (float) ($activeWhatsApp['managed_credit_balance'] ?? 0),
        'managed_credit_reserved' => (float) ($activeWhatsApp['managed_credit_reserved'] ?? 0),
        'managed_currency' => (string) ($activeWhatsApp['managed_currency'] ?? 'KES'),
        'managed_low_balance_threshold' => (float) ($activeWhatsApp['managed_low_balance_threshold'] ?? 100),
        'managed_daily_spend_cap' => $activeWhatsApp['managed_daily_spend_cap'] ?? null,
        'managed_monthly_spend_cap' => $activeWhatsApp['managed_monthly_spend_cap'] ?? null,
        'managed_auto_topup_enabled' => !empty($activeWhatsApp['managed_auto_topup_enabled']),
        'managed_auto_topup_threshold' => $activeWhatsApp['managed_auto_topup_threshold'] ?? null,
        'managed_auto_topup_amount' => $activeWhatsApp['managed_auto_topup_amount'] ?? null,
        'managed_last_health_at' => (string) ($activeWhatsApp['managed_last_health_at'] ?? ''),
        'managed_last_health_status' => (string) ($activeWhatsApp['managed_last_health_status'] ?? ''),
        'managed_last_health_error' => (string) ($activeWhatsApp['managed_last_health_error'] ?? ''),
        'credit_summary' => $whatsAppCreditSummary,
        'template_center_url' => 'whatsapp_templates.php',
        'managed_admin_url' => 'whatsapp_managed_admin.php',
    ];
    try {
        $whatsAppMigrationStatus = (new WhatsAppMigrationService($workspaceId, $userId))->getMigrationStatus();
    } catch (\Throwable $e) {
        $whatsAppMigrationStatus = null;
    }

    return [
        'email_roles' => $emailRoles,
        'whatsapp' => [
            'health' => (array) ($marketplaceChannelHealth['whatsapp'] ?? []),
            'settings' => $whatsAppSettings,
            'settings_in_use' => $marketplaceWhatsAppSettingsInUse($whatsAppSettings),
        ],
        'migration_status' => is_array($whatsAppMigrationStatus) ? $whatsAppMigrationStatus : [],
        'health' => $marketplaceChannelHealth,
    ];
};
$marketplaceAiApiUsageToday = static function (int $workspaceId): array {
    if ($workspaceId <= 0 || !Database::tableExists('workspace_ai_usage')) {
        return [];
    }

    $sourceExpr = Database::columnExists('workspace_ai_usage', 'provider_source')
        ? 'provider_source'
        : "'env'";
    $scopeExpr = Database::columnExists('workspace_ai_usage', 'credential_scope')
        ? 'credential_scope'
        : "'general'";

    try {
        return Database::query(
            "SELECT {$sourceExpr} AS provider_source,
                    {$scopeExpr} AS credential_scope,
                    COUNT(*) AS request_count,
                    COALESCE(SUM(billable_tokens), 0) AS total_billable_tokens
             FROM workspace_ai_usage
             WHERE workspace_id = ?
               AND created_at >= ?
             GROUP BY {$scopeExpr}, {$sourceExpr}
             ORDER BY credential_scope ASC, total_billable_tokens DESC, provider_source ASC",
            [$workspaceId, date('Y-m-d 00:00:00')]
        );
    } catch (\Throwable $e) {
        return [];
    }
};
$marketplaceAiApiSetupContext = static function () use (
    $aiProviderConfigs,
    $aiProviderResolver,
    $marketplaceAiApiUsageToday,
    $workspaceId
): array {
    $workspaceConfigs = [];
    $defaultConfigs = [];
    $resolvedByScope = [];

    try {
        $workspaceConfigs = $aiProviderConfigs->getCredentialSlots($workspaceId, false);
        $defaultConfigs = $aiProviderConfigs->getCredentialSlots($aiProviderConfigs->defaultWorkspaceId(), false);
        foreach (WorkspaceAIProviderConfigService::CREDENTIAL_SCOPES as $credentialScope) {
            $resolvedByScope[$credentialScope] = $aiProviderResolver->resolveForWorkspace(
                $workspaceId,
                'marketplace_setup',
                $credentialScope
            );
        }
    } catch (\Throwable $e) {
        $resolvedByScope[WorkspaceAIProviderConfigService::SCOPE_GENERAL] = [
            'available' => false,
            'source' => WorkspaceAIProviderResolverService::SOURCE_LOCAL_FALLBACK,
            'message' => 'AI provider routing is unavailable until setup is saved.',
        ];
    }

    $workspaceConfig = (array) ($workspaceConfigs[WorkspaceAIProviderConfigService::SCOPE_GENERAL] ?? []);
    $defaultConfig = (array) ($defaultConfigs[WorkspaceAIProviderConfigService::SCOPE_GENERAL] ?? []);
    $resolved = (array) ($resolvedByScope[WorkspaceAIProviderConfigService::SCOPE_GENERAL] ?? []);

    return [
        'workspace_config' => $workspaceConfig,
        'default_config' => $defaultConfig,
        'resolved' => $resolved,
        'workspace_configs' => $workspaceConfigs,
        'default_configs' => $defaultConfigs,
        'resolved_by_scope' => $resolvedByScope,
        'usage_today' => $marketplaceAiApiUsageToday($workspaceId),
        'default_workspace_id' => $aiProviderConfigs->defaultWorkspaceId(),
        'current_workspace_id' => $workspaceId,
        'is_default_workspace' => WorkspaceContext::isDefaultWorkspace($workspaceId),
    ];
};
$marketplaceHrSetupStatus = $selectedMarketplaceItem !== null ? $hrAnalyticsSetup->status($workspaceId) : [];
$marketplaceHrSettings = $selectedMarketplaceItem !== null ? $hrAnalyticsSettings->get($workspaceId) : [];
$marketplacePluginPerformanceByKey = [];
$marketplacePreloadPerformance = false;
if ($marketplacePreloadPerformance && $canViewMarketplacePerformance) {
    foreach ($marketplaceModules as $marketplaceItem) {
        if ((string) ($marketplaceItem['type'] ?? '') !== 'plugin') {
            continue;
        }
        $pluginKey = (string) ($marketplaceItem['key'] ?? '');
        $assistantConfigKey = match ($pluginKey) {
            WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT => 'email',
            WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT => 'whatsapp',
            default => '',
        };
        $marketplacePluginPerformanceByKey[$pluginKey] = array_merge($marketplacePerformance->buildModulePerformance($workspaceId, $pluginKey, 30), [
            'config_updated_at' => $assistantConfigKey !== ''
                ? (string) ($marketplaceAssistantConfigByType[$assistantConfigKey]['updated_at'] ?? '')
                : '',
        ]);
    }
    foreach ([
        WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT,
        WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT,
        WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL,
        WorkspaceSkillCatalogService::PLUGIN_CALENDAR_MEETINGS,
    ] as $runtimePluginKey) {
        if (isset($marketplacePluginPerformanceByKey[$runtimePluginKey])) {
            $marketplacePluginPerformanceByKey[$runtimePluginKey]['runtime'] = $marketplacePerformance->buildModuleRuntimeUsage($workspaceId, $runtimePluginKey, 30);
        }
    }
}
$marketplaceCssUrl = function_exists('assetUrl') ? assetUrl('css/marketplace.css') : 'assets/css/marketplace.css';
$marketplaceCssPath = __DIR__ . '/assets/css/marketplace.css';
if (is_file($marketplaceCssPath)) {
    $marketplaceCssUrl .= (strpos($marketplaceCssUrl, '?') === false ? '?' : '&') . 'v=' . rawurlencode((string) @filemtime($marketplaceCssPath));
}
$marketplaceRenderGuidedDestination = static function (array $destination): void {
    $primaryStep = (array) ($destination['primary_step'] ?? []);
    $steps = array_slice(array_values(array_filter((array) ($destination['steps'] ?? []), 'is_array')), 0, 5);
    $recommendedModules = array_slice(array_values(array_filter((array) ($destination['recommended_modules'] ?? []), 'is_array')), 0, 3);
    $headline = trim((string) ($destination['headline'] ?? 'Next setup step'));
    $reason = trim((string) ($destination['reason'] ?? ''));
    $status = trim((string) ($destination['status_label'] ?? ($primaryStep['status_label'] ?? '')));
    $progress = trim((string) ($destination['progress_label'] ?? ''));
    $href = trim((string) ($primaryStep['href'] ?? ''));
    $ctaLabel = trim((string) ($primaryStep['cta_label'] ?? 'Open setup'));
    ?>
    <section class="marketplace-guided-destination" data-guided-setup-destination>
        <div class="marketplace-guided-main">
            <p class="marketplace-eyebrow">Next setup step</p>
            <h2><?php echo htmlspecialchars($headline !== '' ? $headline : 'Next setup step'); ?></h2>
            <?php if ($reason !== ''): ?>
                <p><?php echo htmlspecialchars($reason); ?></p>
            <?php endif; ?>
            <div class="marketplace-guided-actions">
                <?php if ($href !== ''): ?>
                    <a class="btn-premium-primary marketplace-recommendation-cta"
                       href="<?php echo htmlspecialchars($href); ?>"
                       data-marketplace-skill-key="<?php echo htmlspecialchars((string) ($destination['module_key'] ?? '')); ?>"
                       data-marketplace-setup-label="<?php echo htmlspecialchars($headline !== '' ? $headline : $ctaLabel); ?>"
                       data-marketplace-setup-journey-open="<?php echo !empty($destination['module_key']) ? '1' : '0'; ?>"
                       style="text-decoration:none;"><?php echo htmlspecialchars($ctaLabel !== '' ? $ctaLabel : 'Open setup'); ?></a>
                <?php endif; ?>
                <?php if ($status !== ''): ?>
                    <span class="marketplace-guided-status"><?php echo htmlspecialchars($status); ?></span>
                <?php endif; ?>
                <?php if ($progress !== ''): ?>
                    <span class="marketplace-guided-progress"><?php echo htmlspecialchars($progress); ?></span>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($steps !== []): ?>
            <div class="marketplace-guided-steps" aria-label="Setup steps">
                <?php foreach ($steps as $step): ?>
                    <?php $complete = !empty($step['complete']); ?>
                    <div class="marketplace-guided-step <?php echo $complete ? 'is-complete' : ''; ?>">
                        <span><?php echo $complete ? '&#10003;' : ''; ?></span>
                        <strong><?php echo htmlspecialchars((string) ($step['label'] ?? 'Setup step')); ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if ($recommendedModules !== []): ?>
            <div class="marketplace-guided-recommendations" aria-label="Best capability matches">
                <?php foreach ($recommendedModules as $module): ?>
                    <?php
                        $moduleHref = trim((string) ($module['href'] ?? ''));
                        $moduleLabel = trim((string) ($module['label'] ?? 'Capability'));
                        $moduleReason = trim((string) ($module['reason'] ?? ''));
                    ?>
                    <a href="<?php echo htmlspecialchars($moduleHref !== '' ? $moduleHref : 'workspace_skills.php'); ?>" class="marketplace-guided-recommendation">
                        <strong><?php echo htmlspecialchars($moduleLabel !== '' ? $moduleLabel : 'Capability'); ?></strong>
                        <?php if ($moduleReason !== ''): ?><span><?php echo htmlspecialchars($moduleReason); ?></span><?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
    <?php
};
$marketplaceRenderSideRail = static function () use (
    $showGuidedDestination,
    $guidedDestination,
    $marketplaceRenderGuidedDestination,
    $canManageMarketplace,
    $marketplaceNextActionUrl,
    $marketplaceNextActionSkillKey,
    $marketplaceNextActionLabel,
    $marketplaceNextActionMessage,
    $marketplaceNextActionSetupJourneyOpen,
    $marketplaceNextActionBundleKey
): void {
    ?>
    <aside class="marketplace-side-rail" aria-label="Marketplace setup guidance">
        <details class="marketplace-side-rail-disclosure" open>
            <summary>
                <span>Setup guidance</span>
                <strong><?php echo htmlspecialchars($marketplaceNextActionLabel); ?></strong>
            </summary>
            <div class="marketplace-side-rail-content">
                <section class="marketplace-side-panel marketplace-side-next-action">
                    <p class="marketplace-eyebrow">Recommended next</p>
                    <h2><?php echo htmlspecialchars($marketplaceNextActionLabel); ?></h2>
                    <?php if ($marketplaceNextActionMessage !== ''): ?>
                        <p><?php echo htmlspecialchars($marketplaceNextActionMessage); ?></p>
                    <?php endif; ?>
                    <a href="<?php echo htmlspecialchars($marketplaceNextActionUrl); ?>"
                       class="btn-premium-primary marketplace-recommendation-cta marketplace-side-action-link"
                       data-marketplace-skill-key="<?php echo htmlspecialchars($marketplaceNextActionSkillKey); ?>"
                       data-marketplace-setup-label="<?php echo htmlspecialchars($marketplaceNextActionLabel); ?>"
                       data-marketplace-setup-journey-open="<?php echo htmlspecialchars($marketplaceNextActionSetupJourneyOpen); ?>"
                       <?php echo $marketplaceNextActionBundleKey !== '' ? 'data-marketplace-activation-bundle-key="' . htmlspecialchars($marketplaceNextActionBundleKey) . '"' : ''; ?>
                       style="text-decoration:none;"><?php echo htmlspecialchars($marketplaceNextActionLabel); ?></a>
                </section>
                <?php if ($showGuidedDestination): ?>
                    <?php $marketplaceRenderGuidedDestination($guidedDestination); ?>
                <?php endif; ?>
                <?php if ($canManageMarketplace): ?>
                    <section class="marketplace-guided-destination marketplace-async-strip" data-marketplace-async-recommendations hidden aria-live="polite"></section>
                <?php endif; ?>
            </div>
        </details>
    </aside>
    <?php
};
?>

<link rel="stylesheet" href="<?php echo htmlspecialchars(function_exists('assetUrl') ? assetUrl('css/premium-pages.css') : 'assets/css/premium-pages.css'); ?>">
<link rel="stylesheet" href="<?php echo htmlspecialchars($marketplaceCssUrl); ?>">
<link rel="stylesheet" href="<?php echo htmlspecialchars(function_exists('assetUrl') ? assetUrl('css/calendar-meetings.css') : 'assets/css/calendar-meetings.css'); ?>">
<?php echo PageGuideVideoUi::assets(); ?>

<div class="page-premium marketplace-page">
    <div class="container">
    <?php if ($selectedMarketplaceItem === null): ?>
        <div class="page-header marketplace-hero marketplace-dashboard-hero">
            <div class="marketplace-hero-main">
                <p class="marketplace-eyebrow">Workspace capabilities</p>
                <h1><?php echo htmlspecialchars($marketplaceProductLabel); ?></h1>
                <p><?php echo $isProtectedDemoMarketplace
                    ? 'Email, WhatsApp, AI drafting, targets, tasks, templates, and contact intelligence are already connected for the Riverside command-center story.'
                    : ($marketplaceModeIsBeginner
                    ? 'Add the next capability only when it helps with customer replies, invoices, setup, or daily work.'
                    : 'Choose the next capability for everyday customer work, then follow the setup path until the workspace is ready to run it.'); ?></p>
            </div>
            <?php if ($marketplaceGuideVideoUrl !== ''): ?>
                <div class="marketplace-hero-actions marketplace-dashboard-hero-actions">
                    <?php echo PageGuideVideoUi::button(MarketplacePageExplainerService::PAGE_MARKETPLACE, 'Marketplace page guide', 'btn-premium-secondary marketplace-guide-action'); ?>
                </div>
            <?php endif; ?>
        </div>
        <section class="marketplace-dashboard-strip" aria-label="Marketplace workspace summary" data-guided-demo-target="marketplace-demo-plugins">
            <article class="marketplace-dashboard-metric">
                <span>Installed</span>
                <strong data-marketplace-count="installed"><?php echo (int) $marketplaceInstalledCount; ?></strong>
            </article>
            <article class="marketplace-dashboard-metric">
                <span><?php echo $isProtectedDemoMarketplace ? 'Configured plugins' : 'Available plugins'; ?></span>
                <strong data-marketplace-count="available_plugins"><?php echo (int) $marketplaceAvailablePluginCount; ?></strong>
            </article>
            <article class="marketplace-dashboard-metric <?php echo $marketplaceSetupNeededCount > 0 ? 'is-warning' : 'is-good'; ?>">
                <span><?php echo $isProtectedDemoMarketplace ? 'Readiness gaps' : 'Setup needed'; ?></span>
                <strong data-marketplace-count="setup_needed"><?php echo (int) $marketplaceSetupNeededCount; ?></strong>
            </article>
        </section>
        <?php if ($isProtectedDemoMarketplace): ?>
            <section class="protected-demo-plugin-scene" data-protected-demo-plugin-scene hidden aria-live="polite">
                <div class="protected-demo-plugin-scene__header">
                    <span>Private capability overlay</span>
                    <strong>Demo plugins are configuring safely</strong>
                </div>
                <div class="protected-demo-plugin-scene__grid" data-protected-demo-plugin-list></div>
            </section>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($error): ?>
        <div style="padding:.85rem 1rem;border:1px solid #fecaca;background:#fff1f2;color:#991b1b;border-radius:8px;margin-bottom:1rem;"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div style="padding:.85rem 1rem;border:1px solid #bbf7d0;background:#f0fdf4;color:#166534;border-radius:8px;margin-bottom:1rem;"><?php echo htmlspecialchars($success); ?></div>
    <?php endif; ?>

    <?php if ($selectedMarketplaceItem !== null): ?>
        <?php
        $module = (array) $selectedMarketplaceItem['module'];
        $moduleKey = (string) $selectedMarketplaceItem['key'];
        $moduleType = (string) $selectedMarketplaceItem['type'];
        $moduleProfile = (array) $selectedMarketplaceItem['profile'];
        $moduleCatalogStatus = (string) ($module['catalog_status'] ?? WorkspaceSkillCatalogService::CATALOG_STATUS_VISIBLE);
        $moduleCatalogStatusLabel = $marketplaceCatalogStatusLabel($moduleCatalogStatus);
        $moduleCatalogStatusMessage = $marketplaceCatalogStatusMessage($moduleCatalogStatus);
        $moduleCatalogInstallable = $moduleCatalogStatus === WorkspaceSkillCatalogService::CATALOG_STATUS_VISIBLE;
        $moduleCatalogDraft = (array) ($catalogEditorDraftByKey[$moduleKey] ?? []);
        if (!empty($moduleCatalogDraft)) {
            foreach (['label', 'summary'] as $field) {
                if (array_key_exists($field, $moduleCatalogDraft)) {
                    $module[$field] = (string) $moduleCatalogDraft[$field];
                }
            }
            foreach (['pitch', 'thumbnail_alt', 'banner_alt', 'overview_brief_content', 'overview_brief_format', 'overview_deep_dive_content', 'overview_deep_dive_format'] as $field) {
                if (array_key_exists($field, $moduleCatalogDraft)) {
                    $moduleProfile[$field] = $moduleCatalogDraft[$field];
                }
            }
        }
        $moduleInstalled = !empty($selectedMarketplaceItem['is_installed']);
        $moduleAccess = (array) ($selectedMarketplaceItem['access'] ?? []);
        $moduleForceFullSetup = (string) ($_GET['full_setup'] ?? '') === '1' || $requestedSetupTab !== '';
        $moduleDeferDetailHydration = $_SERVER['REQUEST_METHOD'] === 'GET' && !$moduleForceFullSetup;
        $moduleProtectedInstall = $marketplaceIsProtectedInstall($module);
        $moduleCanManage = $canManage
            || (
                $moduleKey === WorkspaceSkillCatalogService::PLUGIN_FINANCE
                && Authorization::can('finance.manage', $user)
            )
            || (
                $moduleKey === WorkspaceSkillCatalogService::PLUGIN_WHATSAPP
                && $workspaceConnect->canManageWhatsAppConnection($user, $workspaceId)
            )
            || (
                $moduleKey === WorkspaceSkillCatalogService::PLUGIN_SOCIAL_MEDIA
                && Authorization::can('marketing.manage', $user)
            );
        $moduleCanManageCalendarSettings = $canManage || Authorization::can('settings.calendar', $user);
        $moduleCanManageMeetingBotSettings = $canManage || Authorization::can('settings.meeting_bot', $user);
        $moduleCanManageMeetingNotesSettings = $canManage || Authorization::can('settings.meeting_note_taker', $user);
        $moduleCanManageMeetingAvailability = $canManage || Authorization::can('meeting_availability.manage', $user);
        $moduleCalendarMeetingsCanManage = $moduleKey === WorkspaceSkillCatalogService::PLUGIN_CALENDAR_MEETINGS
            && (
                $moduleCanManageCalendarSettings
                || $moduleCanManageMeetingBotSettings
                || $moduleCanManageMeetingNotesSettings
                || $moduleCanManageMeetingAvailability
            );
        $moduleReadiness = $moduleDeferDetailHydration
            ? [
                'ready' => false,
                'status' => $moduleInstalled ? 'checking' : 'available',
                'message' => 'Checking setup status...',
                'checks' => [],
                'deferred' => true,
            ]
            : $installer->buildReadinessForModule($workspaceId, $userId, $moduleKey);
        $moduleSetupChecklist = $moduleDeferDetailHydration
            ? [
                'current_blocker' => $moduleInstalled ? 'Checking setup status...' : '',
                'required' => [],
                'optional' => [],
            ]
            : $installer->buildSetupChecklist($workspaceId, $userId, $moduleKey);
        $moduleSetupTabs = marketplaceSetupTabsForModule($moduleKey);
        $moduleActiveSetupTab = marketplaceNormalizeSetupTab($moduleKey, $requestedSetupTab);
        $moduleSetupEvents = $moduleDeferDetailHydration
            ? []
            : $setupJourneyEvents->getEvents([
                'workspace_id' => $workspaceId,
                'skill_key' => $moduleKey,
            ], 8);
        $moduleReady = (bool) ($moduleReadiness['ready'] ?? false);
        $moduleStatus = (string) ($moduleReadiness['status'] ?? ($moduleInstalled ? 'installed' : 'available'));
        $moduleStatusIsSendingReady = $moduleReady && in_array($moduleStatus, ['warning', 'sending_ready'], true);
        $moduleStatusDisplay = $moduleStatusIsSendingReady
            ? 'Sending ready'
            : ($moduleReady ? 'Ready' : ucwords(str_replace('_', ' ', $moduleStatus)));
        $moduleStatusClass = $moduleStatusIsSendingReady
            ? 'is-warning'
            : ($moduleReady ? 'is-installed' : 'is-needs-setup');
        $moduleCapabilities = (array) $selectedMarketplaceItem['capabilities'];
        $moduleRecommendations = $marketplaceList((array) ($moduleProfile['recommendations'] ?? []));
        $modulePrerequisites = $marketplaceList((array) ($moduleProfile['prerequisites'] ?? $module['dependencies'] ?? []));
        $moduleSetupGuide = $marketplaceList((array) ($moduleProfile['setup_guide'] ?? $module['setup_steps'] ?? []));
        $moduleBenefitBullets = $marketplaceList((array) ($moduleProfile['benefit_bullets'] ?? []));
        if (empty($moduleBenefitBullets)) {
            $moduleBenefitBullets = $moduleRecommendations;
        }
        $moduleUseCaseBullets = $marketplaceList((array) ($moduleProfile['use_case_bullets'] ?? []));
        if (empty($moduleUseCaseBullets)) {
            $moduleUseCaseBullets = $modulePrerequisites;
        }
        $moduleOutcomeBullets = $marketplaceList((array) ($moduleProfile['outcome_bullets'] ?? []));
        $moduleHowItWorksBullets = $marketplaceList((array) ($moduleProfile['how_it_works_bullets'] ?? []));
        if (empty($moduleHowItWorksBullets)) {
            $moduleHowItWorksBullets = $moduleSetupGuide;
        }
        $moduleSummaryText = trim((string) ($module['summary'] ?? ''));
        $modulePitchText = trim((string) ($moduleProfile['pitch'] ?? ''));
        $moduleOverviewHeadline = trim((string) ($module['label'] ?? $moduleKey)) . ' for your workspace';
        $moduleOverviewIntro = $modulePitchText !== '' ? $modulePitchText : $moduleSummaryText;
        $moduleThumbnailRaw = trim((string) ($moduleProfile['thumbnail_url'] ?? ''));
        $moduleBannerRaw = trim((string) ($moduleProfile['banner_url'] ?? ''));
        $moduleVideoRaw = trim((string) ($moduleProfile['explainer_video_url'] ?? ''));
        $moduleVideoEmbed = $marketplaceVideoEmbed($moduleVideoRaw);
        $moduleVideoOrientation = (string) ($moduleProfile['explainer_orientation'] ?? 'landscape') === 'portrait' ? 'portrait' : 'landscape';
        $moduleRequiresSetup = !empty($module['settings_schema']['requires_configuration']);
        $moduleSetupVideoRaw = $moduleRequiresSetup ? trim((string) ($moduleProfile['setup_video_url'] ?? '')) : '';
        $moduleSetupVideoMissing = $moduleSetupVideoRaw !== '' && $marketplaceLocalMediaMissing($moduleSetupVideoRaw);
        $moduleSetupVideoUrl = $moduleSetupVideoRaw !== '' && !$moduleSetupVideoMissing ? $marketplaceAsset($moduleSetupVideoRaw) : '';
        $moduleBannerUrl = $marketplaceAsset($moduleBannerRaw);
        $moduleBannerIsFallback = $moduleBannerRaw === '' || $moduleBannerUrl === $marketplaceDefaultThumbnail;
        if ($moduleBannerIsFallback) {
            $moduleBannerUrl = (string) $selectedMarketplaceItem['thumbnail_url'];
        }
        $moduleBannerAlt = (string) ($moduleProfile['banner_alt'] ?? $moduleProfile['thumbnail_alt'] ?? (($module['label'] ?? $moduleKey) . ' marketplace banner'));
        $moduleAssistantType = match ($moduleKey) {
            WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT => 'whatsapp',
            WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT => 'email',
            default => '',
        };
        $moduleAssistantConfigRow = $moduleAssistantType !== '' ? (array) ($marketplaceAssistantConfigByType[$moduleAssistantType] ?? []) : [];
        $moduleAssistantSettings = (array) ($moduleAssistantConfigRow['settings'] ?? []);
        $moduleAssistantEnabled = !empty($moduleAssistantConfigRow['enabled']);
        $moduleHealthKey = match ($moduleKey) {
            WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT => 'whatsapp',
            WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT => 'assistant_email',
            WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL => 'sms',
            default => '',
        };
        $modulePluginHealth = (array) ($marketplaceChannelHealth[$moduleHealthKey] ?? []);
        $modulePluginPerformance = (array) ($marketplacePluginPerformanceByKey[$moduleKey] ?? []);
        if ($marketplacePreloadPerformance && $canViewMarketplacePerformance && !isset($modulePluginPerformance['metrics'])) {
            $modulePluginPerformance = $marketplacePerformance->buildModulePerformance($workspaceId, $moduleKey, 30);
        }
        $modulePluginRuntime = (array) ($modulePluginPerformance['runtime'] ?? []);
        $strategyProfile = !$moduleDeferDetailHydration ? ((new UserStrategyProfile())->get($userId) ?: []) : [];
        $startupJourney = !$moduleDeferDetailHydration && $moduleKey === WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS
            ? $startupJourneyService->getJourney($workspaceId, $userId)
            : [];
        $moduleMeetingBotConfig = [];
        $moduleMeetingNoteConfig = [];
        $moduleCalendarIntegrations = [];
        $moduleMeetingBotRuns = [];
        $moduleMeetingNoteRuns = [];
        $moduleMeetingBookingProfile = [];
        $moduleMeetingAvailabilityWindows = [];
        $moduleMeetingBlockedTimes = [];
        $moduleMeetingProfileHosts = [];
        $moduleMeetingBookings = [];
        $moduleCalendarSyncHealth = [];
        $moduleWorkspaceUsers = [];
        $moduleWorkspaceSlug = '';
        if (!$moduleDeferDetailHydration && $moduleKey === WorkspaceSkillCatalogService::PLUGIN_CALENDAR_MEETINGS) {
            try {
                $moduleMeetingBotConfig = (new MeetingBotConfig())->get($workspaceId, $moduleCanManageMeetingBotSettings);
            } catch (\Throwable $e) {
                $moduleMeetingBotConfig = [];
            }
            try {
                $moduleMeetingNoteConfig = (new MeetingNoteTakerConfig())->get($workspaceId, $moduleCanManageMeetingNotesSettings);
            } catch (\Throwable $e) {
                $moduleMeetingNoteConfig = [];
            }
            try {
                if (Database::tableExists('calendar_integrations')) {
                    $moduleCalendarIntegrations = Database::query(
                        "SELECT ci.id, ci.provider, ci.user_id, ci.calendar_id, ci.calendar_name, ci.provider_account_email,
                                ci.oauth_grant_type,
                                ci.availability_enabled, ci.sync_enabled, ci.sync_direction, ci.last_sync_at,
                                ci.availability_last_checked_at, ci.availability_last_error, ci.updated_at,
                                u.email AS owner_email
                         FROM calendar_integrations ci
                         LEFT JOIN users u ON u.id = ci.user_id
                         WHERE ci.workspace_id = ?
                         ORDER BY ci.availability_enabled DESC, ci.sync_enabled DESC, ci.provider ASC, ci.calendar_name ASC, ci.id DESC",
                        [$workspaceId]
                    );
                }
            } catch (\Throwable $e) {
                $moduleCalendarIntegrations = [];
            }
            try {
                if (Database::tableExists('workspace_memberships')) {
                    $moduleWorkspaceUsers = Database::query(
                        "SELECT u.id, u.email, wm.role_slug, wm.is_owner
                         FROM workspace_memberships wm
                         JOIN users u ON u.id = wm.user_id
                         WHERE wm.workspace_id = ?
                           AND wm.membership_status = 'active'
                         ORDER BY wm.is_owner DESC,
                                  FIELD(wm.role_slug, 'owner', 'admin', 'sales', 'marketing', 'viewer'),
                                  u.email ASC",
                        [$workspaceId]
                    );
                }
            } catch (\Throwable $e) {
                $moduleWorkspaceUsers = [];
            }
            try {
                $workspaceRow = Database::queryOne("SELECT slug FROM workspaces WHERE id = ? LIMIT 1", [$workspaceId]);
                $moduleWorkspaceSlug = (string) ($workspaceRow['slug'] ?? '');
            } catch (\Throwable $e) {
                $moduleWorkspaceSlug = '';
            }
            try {
                if (Database::tableExists('meeting_booking_profiles')) {
                    $moduleMeetingBookingProfile = Database::queryOne(
                        "SELECT *
                         FROM meeting_booking_profiles
                         WHERE workspace_id = ?
                         ORDER BY status = 'active' DESC, public_enabled DESC, id ASC
                         LIMIT 1",
                        [$workspaceId]
                    ) ?: [];
                }
            } catch (\Throwable $e) {
                $moduleMeetingBookingProfile = [];
            }
            try {
                if ($moduleMeetingBookingProfile !== [] && Database::tableExists('meeting_booking_profile_hosts')) {
                    $moduleMeetingProfileHosts = (new MeetingAvailabilityService())->listRoundRobinHosts($workspaceId, (int) ($moduleMeetingBookingProfile['id'] ?? 0));
                }
            } catch (\Throwable $e) {
                $moduleMeetingProfileHosts = [];
            }
            try {
                if ($moduleMeetingBookingProfile !== [] && Database::tableExists('meeting_availability_windows')) {
                    $moduleMeetingAvailabilityWindows = Database::query(
                        "SELECT *
                         FROM meeting_availability_windows
                         WHERE profile_id = ?
                         ORDER BY day_of_week ASC, start_time ASC",
                        [(int) ($moduleMeetingBookingProfile['id'] ?? 0)]
                    );
                }
            } catch (\Throwable $e) {
                $moduleMeetingAvailabilityWindows = [];
            }
            try {
                if ($moduleMeetingBookingProfile !== [] && Database::tableExists('meeting_blocked_times')) {
                    $moduleMeetingBlockedTimes = Database::query(
                        "SELECT *
                         FROM meeting_blocked_times
                         WHERE workspace_id = ?
                           AND (profile_id IS NULL OR profile_id = ?)
                           AND end_time >= DATE_SUB(NOW(), INTERVAL 1 DAY)
                         ORDER BY start_time ASC, id ASC
                         LIMIT 200",
                        [$workspaceId, (int) ($moduleMeetingBookingProfile['id'] ?? 0)]
                    );
                }
            } catch (\Throwable $e) {
                $moduleMeetingBlockedTimes = [];
            }
            try {
                if (Database::tableExists('meeting_booking_requests')) {
                    $moduleMeetingBookings = (new MeetingBookingService())->listBookings($workspaceId, [], 8);
                }
            } catch (\Throwable $e) {
                $moduleMeetingBookings = [];
            }
            try {
                if (Database::tableExists('calendar_sync_audit_log')) {
                    $moduleCalendarSyncHealth = (new CalendarSyncService())->healthForWorkspace($workspaceId);
                }
            } catch (\Throwable $e) {
                $moduleCalendarSyncHealth = [];
            }
            try {
                $moduleMeetingBotRuns = (new MeetingBotService(workspaceId: $workspaceId))->getRecentRuns(8);
            } catch (\Throwable $e) {
                $moduleMeetingBotRuns = [];
            }
            try {
                $moduleMeetingNoteRuns = (new MeetingNoteTakerService(workspaceId: $workspaceId))->getRecentRuns(8);
            } catch (\Throwable $e) {
                $moduleMeetingNoteRuns = [];
            }
        }
        $moduleAiCoachEnabled = !$moduleDeferDetailHydration && $moduleKey === WorkspaceSkillCatalogService::SKILL_AI_COACH
            ? (new AICoachWorkspaceSetupService($installer))->isWorkspaceEnabled($workspaceId)
            : false;
        $moduleFinanceSetup = !$moduleDeferDetailHydration && $moduleKey === WorkspaceSkillCatalogService::PLUGIN_FINANCE
            ? $financeGate->setupFormData($workspaceId)
            : [];
        $moduleFinanceStatus = !$moduleDeferDetailHydration && $moduleKey === WorkspaceSkillCatalogService::PLUGIN_FINANCE
            ? $financeGate->status($workspaceId, $user)
            : [];
        $moduleWhatsAppHubContext = !$moduleDeferDetailHydration && in_array($moduleKey, [
            WorkspaceSkillCatalogService::PLUGIN_WHATSAPP,
            WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT,
        ], true)
            ? $whatsAppSettingsHub->buildWhatsAppHubContext($workspaceId, $user, $requestedSetupTab, 'marketplace')
            : [];
        $moduleCommunicationSetup = !$moduleDeferDetailHydration && in_array($moduleKey, [
            WorkspaceSkillCatalogService::PLUGIN_EMAIL,
            WorkspaceSkillCatalogService::PLUGIN_WHATSAPP,
        ], true)
            ? (
                $moduleKey === WorkspaceSkillCatalogService::PLUGIN_WHATSAPP
                    ? (array) ($moduleWhatsAppHubContext['channel'] ?? [])
                    : $marketplaceCommunicationSetupContext()
            )
            : [];
        $moduleWhatsAppAssistantSetup = !$moduleDeferDetailHydration && $moduleKey === WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT
            ? (array) ($moduleWhatsAppHubContext['assistant'] ?? [])
            : [];
        $moduleSmsSettings = !$moduleDeferDetailHydration && $moduleKey === WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL
            ? $smsChannelConfig->get($workspaceId, false)
            : [];
        $moduleAiApiSetup = !$moduleDeferDetailHydration && $moduleKey === WorkspaceSkillCatalogService::PLUGIN_AI_API
            ? $marketplaceAiApiSetupContext()
            : [];
        $moduleSmartTemplateStatus = [];
        $aiCoachReadiness = [];
        $aiCoachDecisionChecks = [];
        $aiCoachPairings = [];
        $aiCoachExamples = [];
        $aiCoachInstallIf = [];
        $aiCoachWaitIf = [];
        $aiCoachDoes = [];
        $aiCoachDoesNot = [];
        $aiCoachTeamBriefs = [];
        $aiCoachCurrentBrief = [];
        if (!$moduleDeferDetailHydration && $moduleKey === WorkspaceSkillCatalogService::SKILL_AI_COACH) {
            $aiCoachSetupService = new AICoachWorkspaceSetupService($installer);
            try {
                $moduleSmartTemplateStatus = (new SmartTemplateGenerationService())->getStatus($userId);
            } catch (\Throwable $e) {
                $moduleSmartTemplateStatus = ['is_ready' => false, 'missing_requirements' => [], 'error' => 'Smart Template readiness is unavailable.'];
            }
            try {
                $aiCoachReadiness = (new AICoachReadinessService())->getReadiness($workspaceId, $userId);
            } catch (\Throwable $e) {
                $aiCoachReadiness = [
                    'ai_coach_installed' => $moduleInstalled,
                    'ai_coach_enabled' => $moduleAiCoachEnabled,
                    'company_context_ready' => false,
                    'personal_brief_ready' => false,
                    'coach_context_ready' => false,
                    'personal_strategy_optional' => true,
                    'personal_strategy_refinement_ready' => false,
                    'clarity_journey_ready' => false,
                    'optional_personal_strategy_missing' => [],
                    'recommendations_ready' => false,
                    'missing_requirements' => [],
                    'onboarding_payload' => [],
                ];
            }
            try {
                $aiCoachTeamBriefs = $aiCoachSetupService->getTeamBriefStatus($workspaceId);
                $aiCoachCurrentBrief = $aiCoachSetupService->getCurrentUserBriefPayload($workspaceId, $userId, true);
            } catch (\Throwable $e) {
                $aiCoachTeamBriefs = [];
                $aiCoachCurrentBrief = [];
            }
            $aiCoachProducts = (array) ($aiCoachReadiness['onboarding_payload']['products'] ?? []);
            $aiCoachProductReady = false;
            foreach ($aiCoachProducts as $product) {
                if (trim((string) ($product['name'] ?? '')) !== '') {
                    $aiCoachProductReady = true;
                    break;
                }
            }
            $readyAdviceSkills = array_values((array) ($moduleReadiness['ready_advice_skills'] ?? []));
            $aiCoachDecisionChecks = [
                [
                    'label' => 'AI Coach installed',
                    'ok' => !empty($aiCoachReadiness['ai_coach_installed']),
                    'detail' => !empty($aiCoachReadiness['ai_coach_installed']) ? 'Installed for this workspace.' : 'Install AI Coach from Marketplace.',
                ],
                [
                    'label' => 'Enabled for workspace',
                    'ok' => !empty($aiCoachReadiness['ai_coach_enabled']),
                    'detail' => !empty($aiCoachReadiness['ai_coach_enabled']) ? 'AI Coach is enabled for workspace members.' : 'Enable AI Coach for this workspace in setup.',
                ],
                [
                    'label' => 'Company context ready',
                    'ok' => !empty($aiCoachReadiness['company_context_ready']),
                    'detail' => !empty($aiCoachReadiness['company_context_ready']) ? 'Company profile basics are available.' : 'Complete company profile basics in Settings.',
                ],
                [
                    'label' => 'Product or offer ready',
                    'ok' => $aiCoachProductReady,
                    'detail' => $aiCoachProductReady ? 'At least one product or offer is saved.' : 'Add at least one product or offer in Settings.',
                ],
                [
                    'label' => 'Clarity Journey ready',
                    'ok' => !empty($aiCoachReadiness['clarity_journey_ready']),
                    'detail' => !empty($aiCoachReadiness['clarity_journey_ready']) ? 'Completed Journey context is available.' : 'Complete Clarity Journey before relying on AI Coach recommendations.',
                ],
                [
                    'label' => 'Optional personal strategy',
                    'ok' => !empty($aiCoachReadiness['personal_strategy_refinement_ready']) || !empty($aiCoachReadiness['clarity_journey_ready']),
                    'detail' => !empty($aiCoachReadiness['personal_strategy_refinement_ready']) ? 'Personal refinement notes are saved.' : 'Optional refinement only; Clarity Journey remains the source of truth.',
                ],
                [
                    'label' => 'AI services available',
                    'ok' => class_exists(\CRM\Services\AIService::class) && class_exists(\CRM\Modules\AICoach::class),
                    'detail' => class_exists(\CRM\Services\AIService::class) && class_exists(\CRM\Modules\AICoach::class) ? 'AI Coach and AI service classes are available.' : 'AI service or Coach module is unavailable.',
                ],
                [
                    'label' => 'Ready advice skills',
                    'ok' => $readyAdviceSkills !== [],
                    'detail' => $readyAdviceSkills !== [] ? count($readyAdviceSkills) . ' ready: ' . implode(', ', $readyAdviceSkills) . '.' : 'Add Lean Canvas, Campaign Manager, or another ready advice skill for strategy recommendations.',
                ],
            ];
            $aiCoachInstallIf = [
                'Users need proactive dashboard guidance instead of asking chat what to do next.',
                'The workspace needs priorities, quick wins, and setup gap visibility in one place.',
                'Teams want CRM follow-through tasks connected to deals, contacts, targets, and readiness.',
            ];
            $aiCoachWaitIf = [
                'The company profile, product or offer, or Clarity Journey is still incomplete.',
                'AI services are not configured or the recommendation API is unavailable.',
                'You expect business strategy advice but have not completed Lean Canvas, Campaign Manager, or another advice skill.',
            ];
            $aiCoachDoes = [
                'Shows recommendation cards for foundation gaps, priorities, quick wins, and missing features.',
                'Surfaces readiness nudges before users rely on strategic or channel-specific recommendations.',
                'Creates task candidates with subtasks from CRM evidence or ready skill templates.',
                'Exposes diagnostics such as confidence, context quality, mode, and suppressed recommendations.',
            ];
            $aiCoachDoesNot = [
                'Does not replace Lean Canvas or own its business-model fields.',
                'Does not unlock generic business strategy advice without a ready advice skill.',
                'Does not run email, WhatsApp, SMS, calendar, or workflow automation by itself.',
            ];
            $aiCoachPairings = [
                'Lean Canvas: strategy, validation, pricing, metrics, and business-model recommendations.',
                'Campaign Manager: campaign management, positioning, messaging, analytics, attribution, and outreach guidance.',
                'Calendar & Meetings: meeting prep, notes, and follow-up context.',
                'Email Assistant: inbound, outbound, reply, and digest follow-through.',
                'WhatsApp Assistant: short operational loops, internal instructions, and session-aware nudges.',
            ];
            $aiCoachExamples = [
                'Follow up with stale proposal-stage deals that have no next activity.',
                'Complete a missing Lean Canvas block before asking for pricing or validation advice.',
                'Create a task to review contacts that have no owner, next step, or recent touchpoint.',
                'Install or finish a channel module when setup is blocking better follow-through recommendations.',
            ];
        }
        $moduleNeedsHrSetupData = $moduleKey === WorkspaceSkillCatalogService::PLUGIN_HR_ANALYTICS_SETUP
            && (!$moduleDeferDetailHydration || $canEditMarketplaceCatalog);
        $moduleHrSetupStatus = $moduleNeedsHrSetupData
            ? $hrAnalyticsSetup->status($workspaceId)
            : [];
        $moduleHrSettings = $moduleNeedsHrSetupData
            ? $hrAnalyticsSettings->get($workspaceId)
            : [];
        $moduleOrganizationFunctions = !$moduleDeferDetailHydration && $moduleKey === WorkspaceSkillCatalogService::PLUGIN_HR_ANALYTICS_SETUP
            ? $organizationFunctions->listFunctions($workspaceId, true)
            : [];
        $hrCounts = (array) ($moduleHrSetupStatus['counts'] ?? []);
        $hrChecks = (array) ($moduleHrSetupStatus['checks'] ?? []);
        $hrThresholds = (array) ($moduleHrSettings['thresholds'] ?? []);
        $hrWeights = (array) ($moduleHrSettings['scoring_weights'] ?? []);
        $hrMappings = (array) ($moduleHrSettings['department_mappings'] ?? []);
        $hrPromptConfig = (array) ($moduleHrSettings['prompt_config'] ?? []);
        $hrWeightLabels = [
            'task_completion' => 'Task completion',
            'timeliness' => 'Timeliness',
            'activity_consistency' => 'Activity consistency',
            'outcome_impact' => 'Outcome impact',
            'pipeline_movement' => 'Pipeline movement',
            'campaign_output' => 'Campaign output',
            'workload_balance' => 'Workload balance',
        ];
        $hrWeightRoles = [
            'marketing' => 'Marketing weights',
            'sales' => 'Sales weights',
            'general' => 'General weights',
        ];
        $moduleSkillSetupSummary = ['completed' => 0, 'total' => 0, 'label' => 'Not required'];
        if (!$moduleDeferDetailHydration && $moduleKey === WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS) {
            $leanStatus = (new UserStrategyProfile())->getLeanCanvasStatus($userId);
            $leanMissingBlocks = array_values((array) ($leanStatus['missing_blocks'] ?? []));
            $leanTotalBlocks = 9;
            $leanCompletedBlocks = max(0, $leanTotalBlocks - count($leanMissingBlocks));
            $moduleSkillSetupSummary = [
                'completed' => $leanCompletedBlocks,
                'total' => $leanTotalBlocks,
                'label' => $leanCompletedBlocks >= $leanTotalBlocks ? 'Complete' : 'Needs setup',
            ];
        } elseif (!$moduleDeferDetailHydration && in_array($moduleKey, [WorkspaceSkillCatalogService::SKILL_PROFESSIONAL_MARKETER, WorkspaceSkillCatalogService::PLUGIN_MARKETING_PRO], true)) {
            $marketingFields = ['target_market_focus', 'ideal_customer_profile', 'offer_angle', 'segment_focus', 'sales_motion', 'deal_movement_strategy', 'outreach_posture', 'positioning_notes'];
            $filledMarketingFields = array_values(array_filter($marketingFields, static fn(string $field): bool => trim((string) ($strategyProfile[$field] ?? '')) !== ''));
            $moduleSkillSetupSummary = [
                'completed' => count($filledMarketingFields),
                'total' => count($marketingFields),
                'label' => count($filledMarketingFields) >= count($marketingFields) ? 'Complete' : 'Needs setup',
            ];
        } elseif (!$moduleDeferDetailHydration && !empty($module['is_custom'])) {
            $customFields = (array) ($module['context_schema']['fields'] ?? []);
            $customValues = (array) ($installedByKey[$moduleKey]['config']['context_values'] ?? []);
            $requiredCustomFields = array_values(array_filter($customFields, static fn(array $field): bool => !array_key_exists('required', $field) || !empty($field['required'])));
            $filledCustomFields = array_values(array_filter($requiredCustomFields, static fn(array $field): bool => trim((string) ($customValues[(string) ($field['key'] ?? '')] ?? '')) !== ''));
            $moduleSkillSetupSummary = [
                'completed' => count($filledCustomFields),
                'total' => count($requiredCustomFields),
                'label' => count($filledCustomFields) >= count($requiredCustomFields) ? 'Complete' : 'Needs setup',
            ];
        }
        $moduleRole = match ($moduleKey) {
            WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS => [
                'title' => 'Business model context',
                'body' => 'Lean Canvas owns the workspace business-model assumptions: problem, customer, promise, solution, channels, revenue, costs, metrics, and unfair advantage. It feeds AI Coach and Clarity, but it does not run recommendations itself.',
                'feeds' => ['AI Coach recommendations', 'Clarity chat guidance', 'Validation and pricing advice', 'Strategic task templates'],
            ],
            WorkspaceSkillCatalogService::SKILL_AI_COACH => [
                'title' => 'Recommendation orchestration',
                'body' => 'AI Coach is the proactive "what should I do next?" layer. It reads installed skill contracts, readiness, company and product context, completed Clarity Journey data, optional personal strategy refinements, CRM activity, tasks, deals, contacts, targets, and channel modules. It produces recommendation cards without owning Lean Canvas fields or unlocking business advice by itself.',
                'feeds' => ['Dashboard next-step coaching', 'Readiness and Marketplace setup nudges', 'Goal-aware priorities and quick wins', 'Follow-through tasks from CRM evidence or ready skills'],
            ],
            WorkspaceSkillCatalogService::SKILL_PROFESSIONAL_MARKETER => [
                'title' => 'Marketing advice skill',
                'body' => 'Marketing Assistants own marketing, positioning, campaign, messaging, content, and outreach guidance. They supply the advice boundary AI Coach and Clarity use for customer-facing strategy.',
                'feeds' => ['Campaign recommendations', 'Message review', 'Positioning guidance', 'Marketing task templates'],
            ],
            WorkspaceSkillCatalogService::PLUGIN_SOCIAL_MEDIA => [
                'title' => 'Social media plugin',
                'body' => 'Social Media owns social connectors, content creation, distribution posts, channel export bundles, social analytics, and social ad preparation.',
                'feeds' => ['Content calendar', 'Social connector readiness', 'Channel exports', 'Social campaign analytics'],
            ],
            WorkspaceSkillCatalogService::PLUGIN_DESIGN => [
                'title' => 'Design plugin',
                'body' => 'Design owns forms, landing pages, page previews, creative readiness, and conversion-focused page workflows.',
                'feeds' => ['Landing pages', 'Lead capture forms', 'Page previews', 'Creative asset readiness'],
            ],
            WorkspaceSkillCatalogService::PLUGIN_MARKETING_PRO => [
                'title' => 'Marketing management plugin',
                'body' => 'Campaign Manager owns campaign management, strategy setup, heavy analytics, attribution, launch control, and live orchestration while Social Media and Design handle focused execution surfaces.',
                'feeds' => ['Marketing command center', 'Campaign management', 'Attribution and analytics', 'Launch and live execution controls'],
            ],
            WorkspaceSkillCatalogService::PLUGIN_EMAIL => [
                'title' => 'Email channel plugin',
                'body' => 'Email owns the workspace email channel setup, sending readiness, inbox health, templates, signatures, and email runtime links. Assistants can use it after the channel is ready.',
                'feeds' => ['Shared Inbox email readiness', 'Email send workflows', 'Email templates and signatures', 'Email Assistant prerequisite'],
            ],
            WorkspaceSkillCatalogService::PLUGIN_WHATSAPP => [
                'title' => 'WhatsApp channel plugin',
                'body' => 'WhatsApp owns WhatsApp Business setup, account and webhook readiness, send health, queue visibility, and WhatsApp runtime links. WhatsApp Assistant depends on this channel being ready.',
                'feeds' => ['Shared Inbox WhatsApp readiness', 'WhatsApp send workflows', 'Bulk WhatsApp operations', 'WhatsApp Assistant prerequisite'],
            ],
            WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT => [
                'title' => 'Email automation plugin',
                'body' => 'Email Assistant owns email assistant setup, inbound instruction handling, customer reply support, outbound drafts, and digest workflows.',
                'feeds' => ['Email channel readiness', 'Customer reply workflows', 'Daily digests', 'Inbox instruction intake'],
            ],
            WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT => [
                'title' => 'WhatsApp automation plugin',
                'body' => 'WhatsApp Assistant owns WhatsApp automation setup, digest behavior, reopen policy, session handling, and messaging constraints.',
                'feeds' => ['WhatsApp channel readiness', 'Internal instruction loops', 'Short digests', 'Session continuity'],
            ],
            WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL => [
                'title' => 'SMS delivery plugin',
                'body' => 'SMS Channel owns SMS provider configuration, sender readiness, queueing, delivery tracking, and webhook health.',
                'feeds' => ['SMS send readiness', 'Delivery tracking', 'Reminder workflows', 'Campaign nudges'],
            ],
            WorkspaceSkillCatalogService::PLUGIN_CALENDAR_MEETINGS => [
                'title' => 'Meeting context plugin',
                'body' => 'Calendar & Meetings owns calendar connection, meeting bot policy, transcript readiness, prep, notes, and follow-up context.',
                'feeds' => ['Meeting prep', 'Meeting notes', 'Follow-up context', 'Calendar readiness'],
            ],
            WorkspaceSkillCatalogService::PLUGIN_FINANCE => [
                'title' => 'Finance gate and owner ROI',
                'body' => 'Finance opens after opening balances and owner equity allocation are saved. Owner ROI stays tied to owner logins only.',
                'feeds' => ['Finance tab access', 'Statement trust checks', 'Capital and funding context', 'Owner-only ROI cards'],
            ],
            WorkspaceSkillCatalogService::PLUGIN_AI_API => [
                'title' => 'Workspace AI provider routing',
                'body' => 'AI API owns workspace-scoped provider setup. The default workspace common key is used first; this workspace key takes over after the shared cap is reached.',
                'feeds' => ['AI provider routing', 'Common cap visibility', 'Workspace API override', 'AI usage continuity'],
            ],
            default => !empty($module['is_custom'])
                ? [
                    'title' => 'Workspace-defined advice skill',
                    'body' => 'This custom skill owns its saved advice domains, setup fields, routing examples, guidance instructions, and task templates for this workspace.',
                    'feeds' => ['Clarity routing', 'AI Coach advice coverage', 'Skill-specific task templates'],
                ]
                : [
                    'title' => 'Marketplace module',
                    'body' => 'This module owns its Marketplace setup, readiness, and runtime responsibilities.',
                    'feeds' => ['Workspace Marketplace readiness'],
                ],
        };
        $moduleOverviewSetupMessage = trim((string) ($moduleReadiness['message'] ?? ''));
        if ($moduleOverviewSetupMessage === '') {
            $moduleOverviewSetupMessage = $moduleReady ? 'Setup is ready for this workspace.' : 'Open setup to finish configuration.';
        }
        $moduleOverviewSetupPills = [
            ['label' => $moduleInstalled ? 'Installed' : 'Not installed', 'ready' => $moduleInstalled],
            ['label' => $moduleStatusDisplay, 'ready' => $moduleReady],
        ];
        if (array_key_exists('outbound_ready', $moduleReadiness)) {
            $moduleOverviewSetupPills[] = [
                'label' => !empty($moduleReadiness['outbound_ready']) ? 'Outbound ready' : 'Outbound needs setup',
                'ready' => !empty($moduleReadiness['outbound_ready']),
            ];
        }
        if (array_key_exists('inbound_ready', $moduleReadiness)) {
            $moduleOverviewSetupPills[] = [
                'label' => !empty($moduleReadiness['inbound_ready']) ? 'Inbound ready' : 'Inbound optional',
                'ready' => !empty($moduleReadiness['inbound_ready']) || $moduleKey === WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT,
            ];
        }
        if ($moduleKey === WorkspaceSkillCatalogService::SKILL_AI_COACH) {
            $moduleOverviewSetupPills[] = [
                'label' => $moduleAiCoachEnabled ? 'Workspace enabled' : 'Workspace disabled',
                'ready' => $moduleAiCoachEnabled,
            ];
            $moduleOverviewSetupPills[] = [
                'label' => !empty($aiCoachReadiness['clarity_journey_ready']) ? 'Journey complete' : 'Journey needed',
                'ready' => !empty($aiCoachReadiness['clarity_journey_ready']),
            ];
            $moduleOverviewSetupPills[] = [
                'label' => !empty($aiCoachReadiness['personal_strategy_refinement_ready']) ? 'Strategy refined' : 'Strategy optional',
                'ready' => true,
            ];
            $moduleOverviewSetupPills[] = [
                'label' => !empty($aiCoachReadiness['recommendations_ready']) ? 'Recommendations ready' : 'Recommendations blocked',
                'ready' => !empty($aiCoachReadiness['recommendations_ready']),
            ];
        }
        if ($moduleKey === WorkspaceSkillCatalogService::PLUGIN_AI_API) {
            $aiApiResolved = (array) ($moduleAiApiSetup['resolved'] ?? []);
            $moduleOverviewSetupPills[] = [
                'label' => 'Route: ' . ucwords(str_replace('_', ' ', (string) ($aiApiResolved['source'] ?? $moduleReadiness['provider_source'] ?? 'local fallback'))),
                'ready' => !empty($moduleReadiness['ready']),
            ];
        }
        $moduleOverviewSetupLinks = [];
        $moduleAddOverviewSetupLink = static function (array &$links, string $label, string $href, string $variant = 'secondary'): void {
            if ($label === '' || $href === '') {
                return;
            }
            foreach ($links as $link) {
                if (($link['href'] ?? '') === $href || ($link['label'] ?? '') === $label) {
                    return;
                }
            }
            $links[] = ['label' => $label, 'href' => $href, 'variant' => $variant === 'primary' ? 'primary' : 'secondary'];
        };
        if ($moduleKey === WorkspaceSkillCatalogService::PLUGIN_HR_ANALYTICS_SETUP) {
            $moduleAddOverviewSetupLink($moduleOverviewSetupLinks, 'Continue guided setup', 'organization_intelligence_setup.php', 'primary');
            if (!empty($moduleReadiness['ready'])) {
                $moduleAddOverviewSetupLink($moduleOverviewSetupLinks, 'Open Organization Intelligence', 'hr_analytics.php');
            }
        } else {
            $moduleAddOverviewSetupLink($moduleOverviewSetupLinks, 'Open setup', marketplaceSetupTabUrl($moduleKey, $moduleActiveSetupTab), 'primary');
        }
        switch ($moduleKey) {
            case WorkspaceSkillCatalogService::PLUGIN_DESIGN:
                if ($moduleInstalled) {
                    $moduleAddOverviewSetupLink($moduleOverviewSetupLinks, 'Open Design', 'design.php');
                    $moduleAddOverviewSetupLink($moduleOverviewSetupLinks, 'Create landing page', 'marketing_landing_page_edit.php');
                }
                break;
            case WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT:
                $moduleAddOverviewSetupLink($moduleOverviewSetupLinks, 'Tests', marketplaceSetupTabUrl($moduleKey, 'tests'));
                $moduleAddOverviewSetupLink($moduleOverviewSetupLinks, 'Assistant runs', 'email_assistant_runs.php');
                $moduleAddOverviewSetupLink($moduleOverviewSetupLinks, 'Capabilities', 'email_assistant_capabilities.php');
                break;
            case WorkspaceSkillCatalogService::PLUGIN_WHATSAPP:
                $moduleAddOverviewSetupLink($moduleOverviewSetupLinks, 'WhatsApp Messages', 'whatsapp_messages.php');
                $moduleAddOverviewSetupLink($moduleOverviewSetupLinks, 'Send Message', 'whatsapp_compose.php');
                $moduleAddOverviewSetupLink($moduleOverviewSetupLinks, 'Bulk WhatsApp', 'bulk_whatsapp.php');
                break;
            case WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT:
                $moduleAddOverviewSetupLink($moduleOverviewSetupLinks, 'Tests', marketplaceSetupTabUrl($moduleKey, 'tests'));
                break;
            case WorkspaceSkillCatalogService::SKILL_AI_COACH:
                $moduleAddOverviewSetupLink($moduleOverviewSetupLinks, 'Workspace readiness', marketplaceSetupTabUrl($moduleKey, 'workspace_readiness'));
                $moduleAddOverviewSetupLink($moduleOverviewSetupLinks, 'Personal Strategy', marketplaceSetupTabUrl($moduleKey, 'my_brief'));
                $moduleAddOverviewSetupLink($moduleOverviewSetupLinks, 'Clarity Journey', 'startup_journey.php');
                if (!empty($aiCoachReadiness['recommendations_ready'])) {
                    $moduleAddOverviewSetupLink($moduleOverviewSetupLinks, 'Open AI Coach', 'dashboard.php#ai-coach');
                }
                break;
            case WorkspaceSkillCatalogService::PLUGIN_HR_ANALYTICS_SETUP:
                break;
            case WorkspaceSkillCatalogService::PLUGIN_AI_API:
                $moduleAddOverviewSetupLink($moduleOverviewSetupLinks, 'Usage', marketplaceSetupTabUrl($moduleKey, 'usage'));
                break;
        }
        $moduleTabs = ['overview' => 'Overview', 'setup' => $moduleKey === WorkspaceSkillCatalogService::PLUGIN_HR_ANALYTICS_SETUP ? 'Guided Setup' : 'Setup'];
        $moduleUseOwnerSimpleTabs = !$canViewMarketplacePerformance && in_array($moduleKey, [
            WorkspaceSkillCatalogService::PLUGIN_EMAIL,
            WorkspaceSkillCatalogService::PLUGIN_WHATSAPP,
            WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT,
        ], true);
        if ($moduleKey === WorkspaceSkillCatalogService::PLUGIN_HR_ANALYTICS_SETUP) {
            $moduleUseOwnerSimpleTabs = true;
        }
        if (!$moduleUseOwnerSimpleTabs) {
            $moduleTabs['health'] = 'Test & Health';
            $moduleTabs['runtime'] = 'Runtime';
        }
        $moduleRuntimeCapabilities = array_values(array_filter(
            $pluginRuntimeRegistry->capabilitiesForWorkspace($workspaceId, $userId, true),
            static fn(array $capability): bool => (string) ($capability['skill_key'] ?? '') === $moduleKey
        ));
        $moduleRuntimeEvents = $pluginRuntimeEvents->recent(['workspace_id' => $workspaceId, 'skill_key' => $moduleKey], 8);
        $moduleTargetMetricProviders = array_values(array_filter(
            $targetPluginIntegration->providersForWorkspace($workspaceId),
            static fn(array $provider): bool => (string) ($provider['skill_key'] ?? '') === $moduleKey
        ));
        $moduleWorkflowActions = array_values(array_filter(
            $moduleRuntimeCapabilities,
            static fn(array $capability): bool => (string) ($capability['capability_type'] ?? '') === 'workflow_action'
        ));
        $moduleAppendOverviewText = static function (array &$items, string $text): void {
            $text = trim($text);
            if ($text !== '' && !in_array($text, $items, true)) {
                $items[] = $text;
            }
        };
        $moduleAppendOverviewList = static function (array &$items, array $values) use ($moduleAppendOverviewText): void {
            foreach ($values as $value) {
                $moduleAppendOverviewText($items, (string) $value);
            }
        };
        $moduleAccessRequirementItems = [];
        foreach ((array) ($module['access_requirements'] ?? []) as $requirement) {
            if (!is_array($requirement)) {
                $moduleAppendOverviewText($moduleAccessRequirementItems, (string) $requirement);
                continue;
            }
            $requirementLabel = trim((string) ($requirement['label'] ?? ''));
            $requirementMessage = trim((string) ($requirement['message'] ?? $requirement['why'] ?? ''));
            $moduleAppendOverviewText(
                $moduleAccessRequirementItems,
                $requirementLabel !== '' && $requirementMessage !== ''
                    ? $requirementLabel . ': ' . $requirementMessage
                    : ($requirementMessage !== '' ? $requirementMessage : $requirementLabel)
            );
        }
        $moduleNeedItems = [];
        $moduleAppendOverviewList($moduleNeedItems, $modulePrerequisites);
        $moduleAppendOverviewList($moduleNeedItems, (array) ($module['dependencies'] ?? []));
        $moduleAppendOverviewList($moduleNeedItems, $moduleAccessRequirementItems);
        foreach ((array) ($moduleReadiness['blockers'] ?? []) as $blocker) {
            $blockerText = (string) $blocker;
            if ($moduleKey === WorkspaceSkillCatalogService::PLUGIN_HR_ANALYTICS_SETUP) {
                $normalizedBlocker = strtolower($blockerText);
                if (str_contains($normalizedBlocker, 'active work ownership') || str_contains($normalizedBlocker, 'active business functions')) {
                    $blockerText = 'Business areas';
                } elseif (str_contains($normalizedBlocker, 'settings')) {
                    $blockerText = 'Default scoring profile';
                }
            }
            $moduleAppendOverviewText($moduleNeedItems, 'Setup blocker: ' . $blockerText);
        }
        if (!empty($moduleSetupChecklist['current_blocker'])) {
            $moduleAppendOverviewText($moduleNeedItems, 'Current blocker: ' . (string) $moduleSetupChecklist['current_blocker']);
        }
        $moduleSetupItems = [];
        $moduleAppendOverviewList($moduleSetupItems, $moduleSetupGuide);
        if (empty($moduleSetupItems)) {
            $moduleAppendOverviewList($moduleSetupItems, (array) ($module['setup_steps'] ?? []));
        }
        $moduleAdvancedSections = array_values(array_filter([
            ['title' => 'Expected Outcomes', 'items' => $moduleOutcomeBullets],
            ['title' => 'How It Works', 'items' => $moduleHowItWorksBullets],
            ['title' => 'Recommendations', 'items' => $moduleRecommendations],
            ['title' => 'Role Feeds', 'items' => (array) ($moduleRole['feeds'] ?? [])],
        ], static fn(array $section): bool => !empty($section['items'])));
        $moduleOverviewGroups = [
            [
                'title' => 'What This Does',
                'body' => array_values(array_filter([
                    (string) ($moduleRole['body'] ?? ''),
                    $moduleSummaryText,
                    $modulePitchText,
                ], static fn(string $text): bool => trim($text) !== '')),
                'items' => $moduleBenefitBullets,
            ],
            [
                'title' => 'What You Need',
                'body' => [],
                'items' => $moduleNeedItems,
            ],
            [
                'title' => 'Setup Steps',
                'body' => [],
                'items' => $moduleSetupItems,
                'action' => 'setup',
            ],
        ];
        $moduleFallbackRichSection = static function (string $title, array $body = [], array $items = []) use ($marketplaceTextToRichHtml): string {
            $body = array_values(array_filter(array_map('strval', $body), static fn(string $text): bool => trim($text) !== ''));
            $cleanItems = array_values(array_filter(array_map(static fn(mixed $item): string => trim((string) $item), $items), static fn(string $item): bool => $item !== ''));
            if ($body === [] && $cleanItems === []) {
                return '';
            }
            $html = '<h4>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h4>';
            foreach ($body as $text) {
                $rendered = $marketplaceTextToRichHtml((string) $text);
                if ($rendered !== '') {
                    $html .= $rendered;
                }
            }
            if ($cleanItems !== []) {
                $html .= '<ul>';
                foreach ($cleanItems as $item) {
                    $html .= '<li>' . htmlspecialchars($item, ENT_QUOTES, 'UTF-8') . '</li>';
                }
                $html .= '</ul>';
            }

            return $html;
        };
        $moduleBriefContent = trim((string) ($moduleProfile['overview_brief_content'] ?? ''));
        $moduleBriefFormat = (string) ($moduleProfile['overview_brief_format'] ?? 'text') === 'html' ? 'html' : 'text';
        if ($moduleBriefContent === '') {
            $moduleBriefFormat = 'html';
            $moduleBriefContent = $moduleFallbackRichSection(
                'Brief Overview',
                array_values(array_filter([
                    (string) ($moduleRole['body'] ?? ''),
                    $moduleSummaryText,
                    $modulePitchText,
                ], static fn(string $text): bool => trim($text) !== '')),
                $moduleBenefitBullets
            );
        }
        $moduleDeepDiveContent = trim((string) ($moduleProfile['overview_deep_dive_content'] ?? ''));
        $moduleDeepDiveFormat = (string) ($moduleProfile['overview_deep_dive_format'] ?? 'text') === 'html' ? 'html' : 'text';
        if ($moduleDeepDiveContent === '') {
            $moduleDeepDiveFormat = 'html';
            $moduleDeepDiveContent = $moduleFallbackRichSection('What You Need', [], $moduleNeedItems)
                . $moduleFallbackRichSection('Setup Steps', [], $moduleSetupItems)
                . $moduleFallbackRichSection('Expected Outcomes', [], $moduleOutcomeBullets)
                . $moduleFallbackRichSection('How It Works', [], $moduleHowItWorksBullets)
                . $moduleFallbackRichSection('Recommendations', [], $moduleRecommendations)
                . $moduleFallbackRichSection('Role Feeds', [], (array) ($moduleRole['feeds'] ?? []));
        }
        $moduleBriefHtml = $marketplaceRenderRichContent($moduleBriefContent, $moduleBriefFormat);
        $moduleDeepDiveHtml = $marketplaceRenderRichContent($moduleDeepDiveContent, $moduleDeepDiveFormat);
        $moduleRichOverviewCards = [
            [
                'title' => 'Brief Overview',
                'html' => $moduleBriefHtml,
            ],
            [
                'title' => 'Technical Deep Dive',
                'html' => $moduleDeepDiveHtml,
            ],
        ];
        if (!empty($module['is_custom']) && $canManage) {
            $moduleTabs['skill-editor'] = 'Skill Editor';
        }
        if ($canViewMarketplacePerformance) {
            $moduleTabs['performance'] = 'Performance';
        }
        if ($canEditMarketplaceCatalog) {
            $moduleTabs['catalog-editor'] = 'Catalog Editor';
        }
        $moduleGuidedDemoTarget = match ($moduleKey) {
            WorkspaceSkillCatalogService::PLUGIN_EMAIL,
            WorkspaceSkillCatalogService::PLUGIN_WHATSAPP => 'module-email-whatsapp',
            WorkspaceSkillCatalogService::PLUGIN_CALENDAR_MEETINGS => 'module-calendar',
            WorkspaceSkillCatalogService::PLUGIN_FINANCE => 'module-finance',
            WorkspaceSkillCatalogService::SKILL_AI_COACH => 'module-ai-coach',
            default => '',
        };
        ?>
        <section class="marketplace-module-page" data-marketplace-current-module-url="workspace_skills.php?module=<?php echo urlencode($moduleKey); ?>"<?php echo $moduleGuidedDemoTarget !== '' ? ' data-guided-demo-target="' . htmlspecialchars($moduleGuidedDemoTarget) . '"' : ''; ?> aria-label="<?php echo htmlspecialchars((string) ($module['label'] ?? $moduleKey)); ?> marketplace module">
            <div class="marketplace-module-topbar">
                <a href="workspace_skills.php" class="btn-premium-secondary marketplace-back-link" style="text-decoration:none;">Back to <?php echo htmlspecialchars($marketplaceProductLabel); ?></a>
            </div>

            <?php if ($showGuidedDestination): ?>
                <?php $marketplaceRenderGuidedDestination($guidedDestination); ?>
            <?php endif; ?>

            <div class="marketplace-module-hero <?php echo $moduleBannerIsFallback ? 'is-thumbnail-fallback' : 'has-banner'; ?>">
                <img class="marketplace-module-banner-media" src="<?php echo htmlspecialchars($moduleBannerUrl); ?>" alt="<?php echo htmlspecialchars($moduleBannerAlt); ?>">
                <div class="marketplace-module-hero-copy">
                    <p class="marketplace-eyebrow"><?php echo htmlspecialchars($moduleType); ?> / <?php echo htmlspecialchars((string) ($module['category'] ?? 'module')); ?></p>
                    <div class="marketplace-module-status-row">
                        <span class="marketplace-status <?php echo $moduleInstalled ? 'is-installed' : ''; ?>" data-marketplace-module-installed-status><?php echo $moduleInstalled ? 'Installed' : 'Available'; ?></span>
                        <span class="marketplace-status <?php echo htmlspecialchars($moduleStatusClass); ?>" data-marketplace-module-readiness-status><?php echo htmlspecialchars($moduleStatusDisplay); ?></span>
                    </div>
                    <h2><?php echo htmlspecialchars((string) ($module['label'] ?? $moduleKey)); ?></h2>
                    <?php if ($canEditMarketplaceCatalog): ?>
                        <p class="marketplace-module-catalog-status">Catalog availability: <?php echo htmlspecialchars($moduleCatalogStatusLabel); ?></p>
                    <?php endif; ?>
                    <?php if (!empty($moduleAccess['admin_bypass'])): ?>
                        <p class="marketplace-module-admin-bypass"><?php echo htmlspecialchars((string) ($moduleAccess['admin_bypass_reason'] ?? 'Superadmin default workspace access bypasses package and prerequisite gates.')); ?> Customer package and setup status are unchanged.</p>
                    <?php endif; ?>
                    <p><?php echo htmlspecialchars((string) ($moduleProfile['pitch'] ?? $module['summary'] ?? '')); ?></p>
                    <?php if ($canManage): ?>
                        <form method="POST" class="marketplace-hero-actions">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                            <input type="hidden" name="skill_key" value="<?php echo htmlspecialchars($moduleKey); ?>">
                            <?php if ($activationBundleAttributionKey !== ''): ?>
                                <input type="hidden" name="activation_bundle_key" value="<?php echo htmlspecialchars($activationBundleAttributionKey); ?>">
                            <?php endif; ?>
                            <?php if ($moduleInstalled && $moduleProtectedInstall): ?>
                                <span class="btn-premium-secondary" style="cursor:default;">Required - always enabled</span>
                            <?php elseif ($moduleInstalled): ?>
                                <button class="btn-premium-secondary" type="submit" name="skill_action" value="uninstall">Remove from workspace</button>
                            <?php elseif (!$moduleCatalogInstallable): ?>
                                <span class="btn-premium-secondary" style="cursor:default;"><?php echo htmlspecialchars($moduleCatalogStatusLabel); ?> - install blocked</span>
                            <?php else: ?>
                                <button class="btn-premium-primary" type="submit" name="skill_action" value="install">Install <?php echo $moduleType === 'plugin' ? 'plugin' : 'skill'; ?></button>
                            <?php endif; ?>
                        </form>
                    <?php else: ?>
                        <p style="margin:.85rem 0 0;color:#e2e8f0;font-weight:700;">Your access profile can view this Marketplace item. Install and setup actions require Marketplace management access.</p>
                    <?php endif; ?>
                </div>
            </div>

            <nav class="marketplace-module-tabs" role="tablist" aria-label="Module sections">
                <?php foreach ($moduleTabs as $tabId => $tabLabel): ?>
                    <button type="button" role="tab" data-marketplace-module-tab="<?php echo htmlspecialchars($tabId); ?>" aria-selected="<?php echo $tabId === 'overview' ? 'true' : 'false'; ?>"><?php echo htmlspecialchars($tabLabel); ?></button>
                <?php endforeach; ?>
            </nav>

            <div class="marketplace-module-layout">
                <main class="marketplace-module-main">
                    <section id="overview" class="marketplace-detail-section marketplace-module-panel marketplace-module-overview" data-marketplace-module-panel="overview" role="tabpanel">
                        <div class="marketplace-overview-lede <?php echo ($moduleVideoEmbed['url'] ?? '') === '' && !$canEditMarketplaceCatalog ? 'is-text-only' : ''; ?>">
                            <div class="marketplace-overview-copy">
                                <p class="marketplace-eyebrow"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) ($module['category'] ?? 'module')))); ?> module</p>
                                <h3><?php echo htmlspecialchars($moduleOverviewHeadline); ?></h3>
                                <p><?php echo htmlspecialchars($moduleOverviewIntro); ?></p>
                            </div>
                            <?php if (($moduleVideoEmbed['url'] ?? '') !== '' || $canEditMarketplaceCatalog): ?>
                                <div class="marketplace-overview-media <?php echo $moduleVideoOrientation === 'portrait' ? 'is-portrait' : 'is-landscape'; ?>">
                                    <?php if (($moduleVideoEmbed['type'] ?? '') === 'iframe'): ?>
                                        <?php echo VideoBrandOverlayUi::frame(
                                            '<iframe src="' . htmlspecialchars((string) $moduleVideoEmbed['url'], ENT_QUOTES, 'UTF-8') . '" title="' . htmlspecialchars((string) ($module['label'] ?? $moduleKey), ENT_QUOTES, 'UTF-8') . ' explainer video" loading="lazy" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen></iframe>'
                                        ); ?>
                                    <?php elseif (($moduleVideoEmbed['url'] ?? '') !== ''): ?>
                                        <?php echo VideoBrandOverlayUi::frame(
                                            '<video controls preload="metadata" poster="' . htmlspecialchars($moduleBannerUrl, ENT_QUOTES, 'UTF-8') . '">'
                                            . '<source src="' . htmlspecialchars((string) $moduleVideoEmbed['url'], ENT_QUOTES, 'UTF-8') . '">'
                                            . 'Your browser does not support embedded video.'
                                            . '</video>'
                                        ); ?>
                                    <?php elseif (($moduleVideoEmbed['type'] ?? '') === 'missing'): ?>
                                        <div class="marketplace-overview-video-empty" style="--module-poster: url('<?php echo htmlspecialchars($moduleBannerUrl, ENT_QUOTES); ?>');">
                                            <strong>Explainer video missing</strong>
                                            <span><?php echo $canEditMarketplaceCatalog ? 'The saved video file is no longer available. Upload a replacement or remove it in Catalog Editor.' : 'This explainer video needs to be re-uploaded by a Marketplace admin.'; ?></span>
                                        </div>
                                    <?php else: ?>
                                        <div class="marketplace-overview-video-empty" style="--module-poster: url('<?php echo htmlspecialchars($moduleBannerUrl, ENT_QUOTES); ?>');">
                                            <strong>Explainer Video Slot</strong>
                                            <span>Add a walkthrough in Catalog Editor to give owners a faster way to understand this module.</span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="marketplace-rich-overview-grid">
                            <?php foreach ($moduleRichOverviewCards as $richCard): ?>
                                <?php $richCardTitle = (string) ($richCard['title'] ?? 'Overview'); ?>
                                <?php if ($richCardTitle === 'Technical Deep Dive'): ?>
                                    <details class="marketplace-rich-overview-card marketplace-rich-overview-disclosure">
                                        <summary><?php echo htmlspecialchars($richCardTitle); ?></summary>
                                        <div class="marketplace-rich-overview-copy">
                                            <div class="marketplace-rich-overview-content">
                                                <?php echo (string) ($richCard['html'] ?? ''); ?>
                                            </div>
                                        </div>
                                    </details>
                                <?php else: ?>
                                    <article class="marketplace-rich-overview-card">
                                        <div class="marketplace-rich-overview-copy">
                                            <h4><?php echo htmlspecialchars($richCardTitle); ?></h4>
                                            <div class="marketplace-rich-overview-content">
                                                <?php echo (string) ($richCard['html'] ?? ''); ?>
                                            </div>
                                        </div>
                                    </article>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                        <div class="marketplace-setup-checklist marketplace-setup-checklist-compact marketplace-overview-setup-compact" data-marketplace-module-overview-status>
                            <div class="marketplace-setup-checklist-head">
                                <div>
                                    <strong>Status</strong>
                                    <span data-marketplace-module-status-message><?php echo htmlspecialchars($moduleOverviewSetupMessage); ?></span>
                                </div>
                                <span class="marketplace-status <?php echo htmlspecialchars($moduleStatusClass); ?>" data-marketplace-module-status-label><?php echo htmlspecialchars($moduleStatusDisplay); ?></span>
                            </div>
                            <div class="marketplace-setup-checks-compact" data-marketplace-module-status-pills>
                                <?php foreach (array_slice($moduleOverviewSetupPills, 0, 6) as $pill): ?>
                                    <?php $pillReady = !empty($pill['ready']); ?>
                                    <span class="marketplace-setup-check-pill <?php echo $pillReady ? 'is-ready' : 'is-needed'; ?>"><?php echo htmlspecialchars((string) ($pill['label'] ?? 'Status')); ?></span>
                                <?php endforeach; ?>
                            </div>
                            <?php if ($moduleOverviewSetupLinks !== []): ?>
                                <div class="marketplace-detail-actions marketplace-overview-setup-actions">
                                    <?php foreach ($moduleOverviewSetupLinks as $link): ?>
                                        <a class="<?php echo (string) ($link['variant'] ?? 'secondary') === 'primary' ? 'btn-premium-primary' : 'btn-premium-secondary'; ?>" href="<?php echo htmlspecialchars((string) ($link['href'] ?? '#')); ?>" style="text-decoration:none;"><?php echo htmlspecialchars((string) ($link['label'] ?? 'Open')); ?></a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($moduleCapabilities)): ?>
                            <div class="marketplace-chip-row marketplace-module-capability-tags">
                                <?php foreach ($moduleCapabilities as $capability): ?>
                                    <span class="marketplace-chip"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) $capability))); ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($moduleKey === WorkspaceSkillCatalogService::SKILL_AI_COACH): ?>
                            <div style="margin-top:1.4rem;">
                                <div style="display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;flex-wrap:wrap;">
                                    <div>
                                        <p class="marketplace-eyebrow" style="margin:0 0 .25rem;">Decision guide</p>
                                        <h3 style="margin:0;color:#0f172a;font-size:1.18rem;">Decide if AI Coach is ready for this workspace</h3>
                                        <p style="margin:.35rem 0 0;color:#475569;line-height:1.6;">AI Coach works best when setup context, user strategy, and at least one advice skill are ready. Use this guide to decide whether to enable it now or finish setup first.</p>
                                    </div>
                                    <span style="border:1px solid <?php echo !empty($aiCoachReadiness['recommendations_ready']) ? '#86efac' : '#fed7aa'; ?>;background:<?php echo !empty($aiCoachReadiness['recommendations_ready']) ? '#f0fdf4' : '#fff7ed'; ?>;color:<?php echo !empty($aiCoachReadiness['recommendations_ready']) ? '#166534' : '#9a3412'; ?>;border-radius:999px;padding:.28rem .7rem;font-weight:900;font-size:.8rem;">
                                        <?php echo !empty($aiCoachReadiness['recommendations_ready']) ? 'Recommendations ready' : 'Setup needed'; ?>
                                    </span>
                                </div>
                                <div class="marketplace-overview-sections" style="margin-top:1rem;">
                                    <?php foreach ([
                                        'Install AI Coach If' => $aiCoachInstallIf,
                                        'Wait To Enable If' => $aiCoachWaitIf,
                                        'What It Does' => $aiCoachDoes,
                                        'What It Does Not Do' => $aiCoachDoesNot,
                                        'Recommended Pairings' => $aiCoachPairings,
                                        'Example Recommendations' => $aiCoachExamples,
                                    ] as $decisionTitle => $decisionItems): ?>
                                        <div class="marketplace-overview-block">
                                            <h4><?php echo htmlspecialchars($decisionTitle); ?></h4>
                                            <ul>
                                                <?php foreach ($decisionItems as $decisionItem): ?>
                                                    <li><?php echo htmlspecialchars((string) $decisionItem); ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <div style="margin-top:1.25rem;">
                                    <h4 style="margin:0 0 .75rem;color:#0f172a;font-size:1rem;">AI Coach Readiness Checklist</h4>
                                    <div class="marketplace-overview-sections" style="margin-top:0;">
                                        <?php foreach ($aiCoachDecisionChecks as $check): ?>
                                            <?php $checkReady = !empty($check['ok']); ?>
                                            <div class="marketplace-overview-block">
                                                <h4><?php echo $checkReady ? 'Ready' : 'Needs Setup'; ?>: <?php echo htmlspecialchars((string) ($check['label'] ?? 'Readiness check')); ?></h4>
                                                <p><?php echo htmlspecialchars((string) ($check['detail'] ?? '')); ?></p>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </section>

                    <section id="setup" class="marketplace-detail-section marketplace-module-panel" data-marketplace-module-panel="setup" role="tabpanel" hidden>
                        <h3 class="sr-only">Setup</h3>
                        <?php if ($moduleDeferDetailHydration): ?>
                            <?php
                                $fullSetupUrl = 'workspace_skills.php?module=' . urlencode($moduleKey) . '&full_setup=1';
                                if ($requestedSetupTab !== '') {
                                    $fullSetupUrl .= '&setup_tab=' . urlencode($requestedSetupTab);
                                }
                                $fullSetupUrl .= '#setup';
                            ?>
                            <div class="marketplace-setup-checklist marketplace-setup-checklist-compact" data-marketplace-module-setup-shell>
                                <div class="marketplace-setup-checklist-head">
                                    <div>
                                        <strong>Setup editor</strong>
                                        <span>Open the setup editor to continue.</span>
                                    </div>
                                    <span class="marketplace-status is-needs-setup">Loading</span>
                                </div>
                                <div class="marketplace-detail-actions" style="justify-content:flex-start;margin-top:.85rem;">
                                    <a class="btn-premium-primary" href="<?php echo htmlspecialchars($fullSetupUrl); ?>" style="text-decoration:none;">Open setup editor</a>
                                </div>
                            </div>
                        <?php else: ?>
                        <?php $moduleSetupIsFinance = $moduleKey === WorkspaceSkillCatalogService::PLUGIN_FINANCE; ?>
                        <?php if (!$moduleSetupIsFinance): ?>
                        <?php if ($moduleSetupVideoUrl !== ''): ?>
                            <div class="marketplace-detail-actions" style="justify-content:flex-start;margin:.35rem 0 .75rem;">
                                <button class="btn-premium-secondary marketplace-guide-action" type="button" data-marketplace-setup-video-open aria-label="Watch setup guide for <?php echo htmlspecialchars((string) ($module['label'] ?? $moduleKey)); ?>"><i class="fa-solid fa-play-circle" aria-hidden="true"></i><span>Watch setup guide</span></button>
                            </div>
                            <div class="marketplace-page-guide-modal" data-marketplace-setup-video-modal role="dialog" aria-modal="true" aria-labelledby="marketplace-setup-video-title" hidden>
                                <div class="marketplace-page-guide-dialog" role="document">
                                    <div class="marketplace-page-guide-head">
                                        <h3 id="marketplace-setup-video-title"><?php echo htmlspecialchars((string) ($module['label'] ?? $moduleKey)); ?> setup guide</h3>
                                        <button class="marketplace-page-guide-close" type="button" data-marketplace-setup-video-close aria-label="Close setup guide"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                                    </div>
                                    <div class="marketplace-page-guide-frame">
                                        <?php echo VideoBrandOverlayUi::frame(
                                            '<video controls preload="metadata" playsinline data-marketplace-setup-video>'
                                            . '<source src="' . htmlspecialchars($moduleSetupVideoUrl, ENT_QUOTES, 'UTF-8') . '">'
                                            . 'Your browser does not support embedded video.'
                                            . '</video>'
                                        ); ?>
                                    </div>
                                </div>
                            </div>
                        <?php elseif ($moduleSetupVideoMissing): ?>
                            <p class="marketplace-page-guide-empty">The setup guide video file is missing. <?php echo $canEditMarketplaceCatalog ? 'Upload a replacement or remove it in Catalog Editor.' : 'Ask a Marketplace admin to re-upload it.'; ?></p>
                        <?php endif; ?>
                        <details class="marketplace-setup-checklist marketplace-setup-checklist-compact" data-marketplace-module-setup-checklist>
                            <summary class="marketplace-setup-checklist-head">
                                <div>
                                    <strong>Status</strong>
                                    <?php if (!empty($moduleSetupChecklist['current_blocker'])): ?>
                                        <span class="marketplace-setup-blocker" data-marketplace-module-setup-blocker>Blocker: <?php echo htmlspecialchars((string) $moduleSetupChecklist['current_blocker']); ?></span>
                                    <?php endif; ?>
                                </div>
                                <span class="marketplace-status <?php echo htmlspecialchars($moduleStatusClass); ?>" data-marketplace-module-setup-status><?php echo htmlspecialchars($moduleStatusDisplay); ?></span>
                            </summary>
                            <div class="marketplace-setup-checklist-body">
                                <div class="marketplace-setup-checks-compact" data-marketplace-module-setup-pills>
                                    <?php foreach (array_merge((array) ($moduleSetupChecklist['required'] ?? []), (array) ($moduleSetupChecklist['optional'] ?? [])) as $check): ?>
                                        <?php $checkComplete = (string) ($check['status'] ?? '') === 'complete'; ?>
                                        <span class="marketplace-setup-check-pill <?php echo $checkComplete ? 'is-ready' : 'is-needed'; ?>"><span class="sr-only"><?php echo $checkComplete ? 'Ready: ' : 'Needs setup: '; ?></span><?php echo htmlspecialchars((string) ($check['label'] ?? 'Setup check')); ?></span>
                                    <?php endforeach; ?>
                                </div>
                                <?php if (!empty($moduleSetupGuide)): ?>
                                    <details class="marketplace-secondary-details marketplace-setup-notes">
                                        <summary>Notes</summary>
                                        <ol>
                                            <?php foreach (array_slice($moduleSetupGuide, 0, 4) as $item): ?>
                                                <li><?php echo htmlspecialchars($item); ?></li>
                                            <?php endforeach; ?>
                                        </ol>
                                    </details>
                                <?php endif; ?>
                            </div>
                        </details>
                        <?php endif; ?>

                        <?php if (!$moduleCanManage && $moduleKey !== WorkspaceSkillCatalogService::PLUGIN_CALENDAR_MEETINGS): ?>
                            <p style="margin-top:.85rem;">Your access profile can view setup guidance but cannot change Marketplace setup.</p>
                        <?php elseif ($moduleKey === WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS): ?>
                            <?php
                                $startupStages = (array) ($startupJourney['stages'] ?? []);
                                $startupProgress = (array) ($startupJourney['progress'] ?? []);
                                $startupCurrentStageKey = (string) ($startupJourney['current_stage_key'] ?? '');
                                $startupCurrentStage = $startupCurrentStageKey !== '' && isset($startupStages[$startupCurrentStageKey])
                                    ? (array) $startupStages[$startupCurrentStageKey]
                                    : (array) (reset($startupStages) ?: []);
                                $startupCurrentStageLabel = (string) ($startupCurrentStage['label'] ?? 'Clarity Journey');
                                $startupHubUrl = $startupCurrentStageKey !== ''
                                    ? 'startup_journey.php?stage=' . urlencode($startupCurrentStageKey)
                                    : 'startup_journey.php';
                                $startupCompleted = (int) ($startupProgress['completed'] ?? 0);
                                $startupTotal = (int) ($startupProgress['total'] ?? count($startupStages));
                                $startupPercent = $startupTotal > 0 ? (int) round(($startupCompleted / $startupTotal) * 100) : 0;
                            ?>
                            <div style="margin:.75rem 0 1rem;color:#475569;">
                                <strong style="display:block;color:#0f172a;">Clarity Journey</strong>
                                <span>Open the hub to edit stages and continue setup.</span>
                            </div>
                            <div style="border:1px solid #e2e8f0;background:#fff;border-radius:8px;padding:1rem;display:grid;gap:.85rem;margin-bottom:1rem;">
                                <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
                                    <div>
                                        <strong style="display:block;color:#0f172a;font-size:.98rem;">Journey progress: <?php echo $startupCompleted; ?> / <?php echo $startupTotal; ?> stages complete</strong>
                                        <span style="display:block;color:#64748b;margin-top:.25rem;font-size:.88rem;">Current stage: <?php echo htmlspecialchars($startupCurrentStageLabel); ?> - <?php echo $startupPercent; ?>% complete</span>
                                    </div>
                                    <span style="border:1px solid #bfdbfe;background:#eff6ff;color:#1d4ed8;border-radius:999px;padding:.25rem .65rem;font-weight:900;font-size:.8rem;">Hub-managed</span>
                                </div>
                                <div class="marketplace-detail-actions" style="justify-content:flex-start;margin:0;">
                                    <a class="btn-premium-primary" href="<?php echo htmlspecialchars($startupHubUrl); ?>" style="text-decoration:none;">Open Clarity Journey hub</a>
                                    <a class="btn-premium-secondary" href="finance.php" style="text-decoration:none;">Open Finance</a>
                                </div>
                            </div>
                        <?php elseif (in_array($moduleKey, [WorkspaceSkillCatalogService::SKILL_PROFESSIONAL_MARKETER, WorkspaceSkillCatalogService::PLUGIN_MARKETING_PRO], true)): ?>
                            <form method="POST" class="marketplace-setup-form" style="margin-top:1rem;">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                                <input type="hidden" name="skill_key" value="<?php echo htmlspecialchars($moduleKey); ?>">
                                <div class="marketplace-form-grid">
                                    <?php foreach ([
                                        'target_market_focus' => 'Target market focus',
                                        'ideal_customer_profile' => 'Ideal customer profile',
                                        'offer_angle' => 'Offer angle',
                                        'segment_focus' => 'Segment focus',
                                        'sales_motion' => 'Sales motion',
                                        'deal_movement_strategy' => 'Deal movement strategy',
                                        'outreach_posture' => 'Outreach posture',
                                        'positioning_notes' => 'Positioning notes',
                                        'market_view' => 'Market view',
                                        'strategy_hypothesis' => 'Strategy hypothesis',
                                    ] as $field => $label): ?>
                                        <label class="marketplace-form-field"><?php echo htmlspecialchars($label); ?><textarea name="<?php echo htmlspecialchars($field); ?>" rows="3"><?php echo htmlspecialchars((string) ($strategyProfile[$field] ?? '')); ?></textarea></label>
                                    <?php endforeach; ?>
                                </div>
                                <div class="marketplace-detail-actions"><button class="btn-premium-primary" type="submit" name="skill_action" value="save_professional_marketer_setup">Save marketing setup</button></div>
                            </form>
                        <?php elseif ($moduleKey === WorkspaceSkillCatalogService::PLUGIN_EMAIL): ?>
                            <?php marketplaceRenderEmailChannelSetup(array_merge($moduleCommunicationSetup, [
                                'active_tab' => $moduleActiveSetupTab,
                                'csrf' => $csrf,
                                'installed' => $moduleInstalled,
                                'can_manage' => $moduleCanManage,
                                'readiness' => $moduleReadiness,
                                'events' => $moduleSetupEvents,
                            ])); ?>
                        <?php elseif ($moduleKey === WorkspaceSkillCatalogService::PLUGIN_WHATSAPP): ?>
                            <?php marketplaceRenderWhatsAppChannelSetup(array_merge($moduleCommunicationSetup, [
                                'active_tab' => $moduleActiveSetupTab,
                                'csrf' => $csrf,
                                'installed' => $moduleInstalled,
                                'can_manage' => $moduleCanManage,
                                'readiness' => $moduleReadiness,
                                'events' => $moduleSetupEvents,
                            ])); ?>
                        <?php elseif ($moduleKey === WorkspaceSkillCatalogService::PLUGIN_HR_ANALYTICS_SETUP): ?>
                            <?php $hrSetupReady = !empty($moduleHrSetupStatus['ready']); ?>
                            <div style="border:1px solid <?php echo $hrSetupReady ? '#bbf7d0' : '#fed7aa'; ?>;border-radius:12px;background:<?php echo $hrSetupReady ? '#f0fdf4' : '#fff7ed'; ?>;padding:1rem;margin-top:1rem;">
                                <strong style="display:block;color:#0f172a;font-weight:800;">Guided owner setup</strong>
                                <p style="margin:.35rem 0 .9rem;color:#475569;">Use one guided page to confirm business areas and decide whether formal teams matter yet. Owner responsibility coverage is handled from user accounts.</p>
                                <div class="marketplace-detail-actions" style="justify-content:flex-start;margin:0;">
                                    <a class="btn-premium-primary" href="organization_intelligence_setup.php" style="text-decoration:none;">Continue guided setup</a>
                                    <?php if ($hrSetupReady): ?>
                                        <a class="btn-premium-secondary" href="hr_analytics.php" style="text-decoration:none;">Open Organization Intelligence</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="marketplace-form-grid" style="margin-top:1rem;">
                                <div style="border:1px solid #e2e8f0;border-radius:12px;background:#fff;padding:1rem;">
                                    <strong style="display:block;color:#0f172a;font-weight:700;">Setup progress</strong>
                                    <p style="margin:.35rem 0 .8rem;color:#475569;"><?php echo htmlspecialchars((string) ($moduleHrSetupStatus['message'] ?? 'Review Organization Intelligence setup.')); ?></p>
                                    <div style="display:flex;gap:.45rem;flex-wrap:wrap;">
                                        <span class="marketplace-setup-check-pill <?php echo ((int) ($hrCounts['active_functions'] ?? 0)) > 0 ? 'is-ready' : 'is-needed'; ?>"><?php echo (int) ($hrCounts['active_functions'] ?? 0); ?> business area(s)</span>
                                        <span class="marketplace-setup-check-pill is-ready"><?php echo (int) ($hrCounts['active_departments'] ?? 0); ?> optional team(s)</span>
                                    </div>
                                </div>
                                <div style="border:1px solid #e2e8f0;border-radius:12px;background:#fff;padding:1rem;">
                                    <strong style="display:block;color:#0f172a;font-weight:700;">People & HR clarity</strong>
                                    <p style="margin:.35rem 0;color:#475569;">The dashboard reads responsibility coverage, workload pressure, coaching signals, follow-up risk, and team comparison when departments exist.</p>
                                    <p style="margin:.65rem 0 0;color:#64748b;font-size:.9rem;">Departments are optional; they improve formal HR reporting but do not block small teams from launching.</p>
                                </div>
                            </div>
                            <?php if ($canManage): ?>
                                <?php if (!$canEditMarketplaceCatalog): ?>
                                    <p style="margin:.85rem 0 0;color:#64748b;">Advanced technical tuning is managed by superadmins in Catalog Editor.</p>
                                <?php endif; ?>
                            <?php else: ?>
                                <p style="margin-top:.85rem;color:#475569;">Your access profile can view Organization Intelligence setup. Saving setup requires Marketplace management access.</p>
                            <?php endif; ?>
                        <?php elseif ($moduleType === 'plugin' && !$moduleInstalled): ?>
                            <p style="margin-top:.85rem;">Install this plugin to unlock setup fields.</p>
                        <?php elseif ($moduleKey === WorkspaceSkillCatalogService::PLUGIN_AI_API): ?>
                            <?php marketplaceRenderAiApiSetup(array_merge($moduleAiApiSetup, [
                                'active_tab' => $moduleActiveSetupTab,
                                'csrf' => $csrf,
                                'installed' => $moduleInstalled,
                                'can_manage' => $moduleCanManage,
                                'readiness' => $moduleReadiness,
                                'events' => $moduleSetupEvents,
                            ])); ?>
                        <?php elseif ($moduleKey === WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT): ?>
                            <?php marketplaceRenderEmailAssistantSetup([
                                'active_tab' => $moduleActiveSetupTab,
                                'settings' => $moduleAssistantSettings,
                                'enabled' => $moduleAssistantEnabled,
                                'csrf' => $csrf,
                                'installed' => $moduleInstalled,
                                'can_manage' => $canManage,
                                'readiness' => $moduleReadiness,
                                'events' => $moduleSetupEvents,
                                'delivery_attempts' => (new EmailAssistantDigestService())->recentAttempts(10),
                                'worker_health' => [
                                    'digest' => (new AutomationJobHealthService())->getJob('email_assistant_digest'),
                                    'inbound' => (new AutomationJobHealthService())->getJob('email_assistant_inbound'),
                                ],
                                'platform' => [
                                    'gmail_configured' => trim((string) ($_ENV['GMAIL_MAIL_CLIENT_ID'] ?? getenv('GMAIL_MAIL_CLIENT_ID') ?: '')) !== ''
                                        && trim((string) ($_ENV['GMAIL_MAIL_CLIENT_SECRET'] ?? getenv('GMAIL_MAIL_CLIENT_SECRET') ?: '')) !== '',
                                    'google_workspace_configured' => trim((string) ($_ENV['GOOGLE_WORKSPACE_MAIL_CLIENT_ID'] ?? getenv('GOOGLE_WORKSPACE_MAIL_CLIENT_ID') ?: '')) !== ''
                                        && trim((string) ($_ENV['GOOGLE_WORKSPACE_MAIL_CLIENT_SECRET'] ?? getenv('GOOGLE_WORKSPACE_MAIL_CLIENT_SECRET') ?: '')) !== '',
                                    'assistant_provider' => $emailIntegrations->getAssistantProviderSummary($workspaceId),
                                ],
                            ]); ?>
                        <?php elseif ($moduleKey === WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT): ?>
                            <?php marketplaceRenderWhatsAppAssistantSetup(array_merge($moduleWhatsAppAssistantSetup, [
                                'active_tab' => $moduleActiveSetupTab,
                                'settings' => (array) (($moduleWhatsAppAssistantSetup['settings'] ?? []) ?: $moduleAssistantSettings),
                                'enabled' => array_key_exists('enabled', $moduleWhatsAppAssistantSetup) ? !empty($moduleWhatsAppAssistantSetup['enabled']) : $moduleAssistantEnabled,
                                'csrf' => $csrf,
                                'installed' => $moduleInstalled,
                                'can_manage' => $moduleCanManage,
                                'readiness' => $moduleReadiness,
                                'events' => $moduleSetupEvents,
                            ])); ?>
                        <?php elseif ($moduleKey === WorkspaceSkillCatalogService::PLUGIN_SOCIAL_MEDIA): ?>
                            <?php
                                $socialMediaSetupService = new SocialMediaService($workspaceId);
                                marketplaceRenderSocialMediaSetup([
                                    'active_tab' => $moduleActiveSetupTab,
                                    'csrf' => $csrf,
                                    'installed' => $moduleInstalled,
                                    'can_manage' => $moduleCanManage,
                                    'settings' => $socialMediaSetupService->getSettings(),
                                    'accounts' => $socialMediaSetupService->listAccounts(true),
                                    'summary' => $socialMediaSetupService->dashboardSummary(),
                                    'readiness' => $moduleReadiness,
                                    'platform' => $socialMediaSetupService->platformReadiness(),
                                    'events' => $socialMediaSetupService->recentEvents(20),
                                ]);
                            ?>
                        <?php elseif ($moduleKey === WorkspaceSkillCatalogService::PLUGIN_DESIGN): ?>
                            <?php marketplaceRenderDesignSetup([
                                'active_tab' => $moduleActiveSetupTab,
                                'installed' => $moduleInstalled,
                                'readiness' => $moduleReadiness,
                            ]); ?>
                        <?php elseif ($moduleKey === WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL): ?>
                            <?php
                                $smsDisabled = (!$moduleInstalled || !$moduleCanManage) ? 'disabled' : '';
                                $smsTokenSaved = !empty($moduleSmsSettings['auth_token_saved']);
                            ?>
                            <form method="POST" class="marketplace-setup-form" style="margin-top:1rem;">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                                <input type="hidden" name="skill_key" value="<?php echo htmlspecialchars($moduleKey); ?>">
                                <label class="marketplace-form-check"><input type="checkbox" name="sms_channel_enabled" <?php echo !empty($moduleSmsSettings['enabled']) ? 'checked' : ''; ?> <?php echo $smsDisabled; ?>> Enable SMS Channel for this workspace</label>
                                <div class="marketplace-form-grid">
                                    <label class="marketplace-form-field">Twilio account SID <input type="text" name="sms_twilio_account_sid" value="<?php echo htmlspecialchars((string) ($moduleSmsSettings['account_sid'] ?? '')); ?>" <?php echo $smsDisabled; ?>></label>
                                    <label class="marketplace-form-field">Twilio auth token <input type="password" name="sms_twilio_auth_token" value="" placeholder="<?php echo $smsTokenSaved ? 'Saved token' : 'Auth token'; ?>" <?php echo $smsDisabled; ?>></label>
                                    <label class="marketplace-form-field">Sender number <input type="text" name="sms_twilio_from_number" value="<?php echo htmlspecialchars((string) ($moduleSmsSettings['from_number'] ?? '')); ?>" placeholder="+15551234567" <?php echo $smsDisabled; ?>></label>
                                    <label class="marketplace-form-field">Twilio webhook URL <input type="text" readonly value="<?php echo htmlspecialchars((string) ($moduleReadiness['webhook_url'] ?? 'Configure APP_URL to generate this URL.')); ?>"></label>
                                    <label class="marketplace-form-check"><input type="checkbox" name="sms_webhook_enabled" <?php echo !empty($moduleSmsSettings['webhook_enabled']) ? 'checked' : ''; ?> <?php echo $smsDisabled; ?>> Track inbound webhooks</label>
                                    <label class="marketplace-form-check"><input type="checkbox" name="sms_status_callbacks_enabled" <?php echo !empty($moduleSmsSettings['status_callbacks_enabled']) ? 'checked' : ''; ?> <?php echo $smsDisabled; ?>> Track delivery callbacks</label>
                                </div>
                                <div class="marketplace-overview-sections" style="margin-top:1rem;">
                                    <div class="marketplace-overview-block">
                                        <h4>Workspace SMS State</h4>
                                        <p><?php echo htmlspecialchars((string) ($moduleReadiness['message'] ?? 'SMS readiness is being evaluated.')); ?></p>
                                    </div>
                                    <div class="marketplace-overview-block">
                                        <h4>Queue Health</h4>
                                        <p><?php echo (int) ($moduleReadiness['queued_messages'] ?? 0); ?> pending, <?php echo (int) ($moduleReadiness['failed_messages'] ?? 0); ?> failed for this workspace.</p>
                                    </div>
                                </div>
                                <?php if (!$moduleCanManage): ?>
                                    <p style="margin-top:.85rem;color:#475569;">Your access profile can view SMS setup. Saving setup requires Marketplace management access.</p>
                                <?php endif; ?>
                                <div class="marketplace-detail-actions"><button class="btn-premium-primary" type="submit" name="skill_action" value="save_sms_channel_setup" <?php echo $smsDisabled; ?>>Save SMS setup</button></div>
                            </form>
                        <?php elseif ($moduleKey === WorkspaceSkillCatalogService::PLUGIN_VOICE_CALL_CENTER): ?>
                            <?php
                                $voiceConfigService = new WorkspaceVoiceConfigService();
                                $voiceSettings = $voiceConfigService->get($workspaceId, false);
                                $voiceAgents = (new VoiceAgentService())->list($workspaceId);
                                $voiceQueueService = new VoiceQueueService();
                                $voiceQueues = $voiceQueueService->listQueues($workspaceId, true);
                                $requestedVoiceQueue = trim((string) ($_GET['voice_queue_id'] ?? ''));
                                $voiceQueue = $requestedVoiceQueue === 'new'
                                    ? []
                                    : ($requestedVoiceQueue !== '' ? $voiceQueueService->find($workspaceId, (int) $requestedVoiceQueue) : $voiceQueueService->defaultQueue($workspaceId));
                                if ($voiceQueue === [] && $requestedVoiceQueue !== 'new') {
                                    $voiceQueue = $voiceQueueService->defaultQueue($workspaceId);
                                }
                                $voiceQueueMembers = $voiceQueueService->members($workspaceId, (int) ($voiceQueue['id'] ?? 0));
                                $voiceUsage = Database::queryOne(
                                    "SELECT COUNT(*) AS calls, COALESCE(SUM(estimated_billable_minutes),0) AS minutes, COALESCE(SUM(estimated_provider_cost),0) AS provider_cost FROM voice_usage_ledger WHERE workspace_id = ? AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')",
                                    [$workspaceId]
                                ) ?: [];
                                $voiceObservability = (new VoiceObservabilityService())->snapshot($workspaceId);
                                $voiceDeadLetters = (new VoiceTranscriptionJobService())->deadLetters($workspaceId);
                                $voiceEvents = Database::query(
                                    "SELECT event_type, normalized_state, accepted, rejection_reason, created_at FROM voice_call_events WHERE workspace_id = ? ORDER BY id DESC LIMIT 30",
                                    [$workspaceId]
                                );
                                $voiceWorkspaceUsers = Database::query(
                                    "SELECT u.id, u.email FROM users u INNER JOIN workspace_memberships wm ON wm.user_id = u.id WHERE wm.workspace_id = ? AND wm.membership_status = 'active' ORDER BY u.email ASC",
                                    [$workspaceId]
                                );
                            ?>
                            <?php marketplaceRenderVoiceCallCenterSetup([
                                'active_tab' => $moduleActiveSetupTab,
                                'csrf' => $csrf,
                                'workspace_id' => $workspaceId,
                                'show_callback_urls' => !Authorization::isSuperAdmin($user),
                                'installed' => $moduleInstalled,
                                'can_manage' => $moduleCanManage || Authorization::can('voice.settings.manage', $user),
                                'can_manage_agents' => Authorization::isSuperAdmin($user) || Authorization::can('voice.agents.manage', $user),
                                'readiness' => $moduleReadiness,
                                'settings' => $voiceSettings,
                                'agents' => $voiceAgents,
                                'queue' => $voiceQueue,
                                'queues' => $voiceQueues,
                                'queue_members' => $voiceQueueMembers,
                                'usage' => $voiceUsage,
                                'observability' => $voiceObservability,
                                'dead_letters' => $voiceDeadLetters,
                                'events' => $voiceEvents,
                                'workspace_users' => $voiceWorkspaceUsers,
                            ]); ?>
                        <?php elseif ($moduleKey === WorkspaceSkillCatalogService::PLUGIN_CALENDAR_MEETINGS): ?>
                            <?php marketplaceRenderCalendarMeetingsSetup([
                                'active_tab' => $moduleActiveSetupTab,
                                'csrf' => $csrf,
                                'user_id' => $userId,
                                'installed' => $moduleInstalled,
                                'can_manage' => $moduleCalendarMeetingsCanManage,
                                'can_manage_calendar' => $moduleCanManageCalendarSettings,
                                'can_connect_calendar' => $workspaceConnect->canUseCalendarConnections($user, $workspaceId),
                                'can_manage_availability' => $moduleCanManageMeetingAvailability,
                                'can_manage_bot' => $moduleCanManageMeetingBotSettings,
                                'can_manage_notes' => $moduleCanManageMeetingNotesSettings,
                                'readiness' => $moduleReadiness,
                                'events' => $moduleSetupEvents,
                                'bot_config' => $moduleMeetingBotConfig,
                                'note_config' => $moduleMeetingNoteConfig,
                                'calendar_integrations' => $moduleCalendarIntegrations,
                                'workspace_users' => $moduleWorkspaceUsers,
                                'sync_health' => $moduleCalendarSyncHealth,
                                'booking_profile' => $moduleMeetingBookingProfile,
                                'availability_windows' => $moduleMeetingAvailabilityWindows,
                                'blocked_times' => $moduleMeetingBlockedTimes,
                                'profile_hosts' => $moduleMeetingProfileHosts,
                                'booking_requests' => $moduleMeetingBookings,
                                'workspace_slug' => $moduleWorkspaceSlug,
                                'bot_runs' => $moduleMeetingBotRuns,
                                'note_runs' => $moduleMeetingNoteRuns,
                            ]); ?>
                        <?php elseif ($moduleKey === WorkspaceSkillCatalogService::PLUGIN_FINANCE): ?>
                            <?php marketplaceRenderFinanceSetup([
                                'active_tab' => $moduleActiveSetupTab,
                                'csrf' => $csrf,
                                'can_manage' => $moduleCanManage,
                                'status' => $moduleFinanceStatus,
                                'setup' => $moduleFinanceSetup,
                            ]); ?>
                        <?php elseif ($moduleKey === WorkspaceSkillCatalogService::SKILL_AI_COACH): ?>
                            <?php marketplaceRenderAiCoachSetup([
                                'active_tab' => $moduleActiveSetupTab,
                                'csrf' => $csrf,
                                'installed' => $moduleInstalled,
                                'enabled' => $moduleAiCoachEnabled,
                                'workspace_enabled' => $moduleAiCoachEnabled,
                                'can_manage' => $canManage,
                                'readiness' => $moduleReadiness,
                                'full_readiness' => $aiCoachReadiness,
                                'team_briefs' => $aiCoachTeamBriefs,
                                'current_brief' => $aiCoachCurrentBrief,
                                'decision_checks' => $aiCoachDecisionChecks,
                                'pairings' => $aiCoachPairings,
                                'examples' => $aiCoachExamples,
                                'does' => $aiCoachDoes,
                                'does_not' => $aiCoachDoesNot,
                                'smart_templates' => $moduleSmartTemplateStatus,
                                'events' => $moduleSetupEvents,
                                'is_superadmin' => $isSuperAdmin ?? Authorization::isSuperAdmin($user),
                            ]); ?>
                            <?php if (false): ?>
                            <?php
                                $smartReadiness = (array) ($moduleSmartTemplateStatus['readiness'] ?? []);
                                $smartMissing = (array) ($smartReadiness['missing_requirements'] ?? []);
                                $smartReady = !empty($moduleSmartTemplateStatus['is_ready']);
                                $smartHasActiveSet = !empty($moduleSmartTemplateStatus['has_active_set']);
                                $smartActiveSet = (array) ($moduleSmartTemplateStatus['active_set'] ?? []);
                            ?>
                            <div style="margin-top:1rem;border:1px solid #dbe4ff;border-radius:12px;background:#f8faff;padding:1rem;">
                                <strong style="display:block;color:#0f172a;">Smart Templates</strong>
                                <p style="margin:.4rem 0 .75rem;color:#475569;">Generate email and workflow template packs from Marketplace after company profile, strategy, and idea validation context are ready.</p>
                                <?php if (!empty($moduleSmartTemplateStatus['error'])): ?>
                                    <p style="margin:0;color:#b91c1c;"><?php echo htmlspecialchars((string) $moduleSmartTemplateStatus['error']); ?></p>
                                <?php elseif ($smartReady): ?>
                                    <p style="margin:0;color:#166534;font-weight:700;"><?php echo $smartHasActiveSet ? 'An active Smart Template pack is available.' : 'Context is ready for Smart Template generation.'; ?></p>
                                    <?php if ($smartHasActiveSet): ?>
                                        <p style="margin:.35rem 0 0;color:#475569;">Email templates: <?php echo (int) ($smartActiveSet['email_template_count'] ?? 0); ?> - Workflow templates: <?php echo (int) ($smartActiveSet['workflow_template_count'] ?? 0); ?></p>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <p style="margin:0;color:#9a3412;font-weight:700;">Finish these before generation:</p>
                                    <ul style="margin:.45rem 0 0;padding-left:1.1rem;color:#475569;">
                                        <?php foreach ($smartMissing as $missing): ?>
                                            <li><?php echo htmlspecialchars((string) ($missing['label'] ?? 'Missing context')); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                                <div class="marketplace-detail-actions" style="margin-top:.85rem;">
                                    <a class="btn-premium-secondary" href="email_templates.php" style="text-decoration:none;">Email Templates</a>
                                    <a class="btn-premium-secondary" href="workflow_templates.php" style="text-decoration:none;">Workflow Templates</a>
                                </div>
                                <form method="POST" class="marketplace-setup-form" style="margin-top:.75rem;">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                                    <input type="hidden" name="skill_key" value="<?php echo htmlspecialchars($moduleKey); ?>">
                                    <button class="btn-premium-primary" type="submit" name="skill_action" value="generate_smart_templates" <?php echo $smartReady ? '' : 'disabled'; ?>><?php echo $smartHasActiveSet ? 'Regenerate Smart Template Pack' : 'Generate Smart Template Pack'; ?></button>
                                </form>
                            </div>
                            <?php endif; ?>
                        <?php elseif (!empty($module['is_custom'])): ?>
                            <?php $customContextValues = (array) ($installedByKey[$moduleKey]['config']['context_values'] ?? []); ?>
                            <?php if (!$moduleInstalled): ?>
                                <p style="margin-top:.85rem;">Install this custom skill before saving its setup context.</p>
                            <?php elseif (!empty($module['context_schema']['fields'])): ?>
                                <form method="POST" class="marketplace-setup-form" style="margin-top:1rem;">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                                    <input type="hidden" name="skill_key" value="<?php echo htmlspecialchars($moduleKey); ?>">
                                    <div class="marketplace-form-grid">
                                        <?php foreach ((array) ($module['context_schema']['fields'] ?? []) as $field): ?>
                                            <?php $fieldKey = (string) ($field['key'] ?? ''); ?>
                                            <?php if ($fieldKey === '') continue; ?>
                                            <label class="marketplace-form-field marketplace-form-field-wide"><?php echo htmlspecialchars((string) ($field['label'] ?? $fieldKey)); ?><?php echo !array_key_exists('required', $field) || !empty($field['required']) ? ' *' : ''; ?>
                                                <textarea name="skill_context[<?php echo htmlspecialchars($fieldKey); ?>]" rows="3"><?php echo htmlspecialchars((string) ($customContextValues[$fieldKey] ?? '')); ?></textarea>
                                            </label>
                                        <?php endforeach; ?>
                                    </div>
                                    <div class="marketplace-detail-actions"><button class="btn-premium-primary" type="submit" name="skill_action" value="save_skill_context">Save skill context</button></div>
                                </form>
                            <?php else: ?>
                                <p style="margin-top:.85rem;">This custom skill does not require setup fields.</p>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php endif; ?>
                    </section>

                    <?php if (!$moduleUseOwnerSimpleTabs): ?>
                    <section id="runtime" class="marketplace-detail-section marketplace-module-panel" data-marketplace-module-panel="runtime" role="tabpanel" hidden>
                        <h3>Runtime</h3>
                        <p>Installed runtime contracts for tasks, targets, workflow actions, AI context, gates, and background jobs.</p>
                        <div class="marketplace-health-grid" style="margin-top:.75rem;">
                            <div class="marketplace-metric-card"><strong><?php echo count($moduleRuntimeCapabilities); ?></strong><span>Capabilities</span></div>
                            <div class="marketplace-metric-card"><strong><?php echo count(array_filter($moduleRuntimeCapabilities, static fn(array $capability): bool => !empty($capability['available']))); ?></strong><span>Available now</span></div>
                            <div class="marketplace-metric-card"><strong><?php echo count($moduleTargetMetricProviders); ?></strong><span>Target metrics</span></div>
                            <div class="marketplace-metric-card"><strong><?php echo count($moduleWorkflowActions); ?></strong><span>Workflow actions</span></div>
                        </div>
                        <?php if (empty($moduleRuntimeCapabilities)): ?>
                            <p style="margin-top:1rem;color:#64748b;">No runtime capabilities are registered for this module yet.</p>
                        <?php else: ?>
                            <div class="marketplace-overview-sections" style="margin-top:1rem;">
                                <?php foreach ($moduleRuntimeCapabilities as $capability): ?>
                                    <div class="marketplace-overview-block">
                                        <h4><?php echo htmlspecialchars((string) ($capability['capability_key'] ?? 'Capability')); ?></h4>
                                        <p><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) ($capability['capability_type'] ?? 'runtime')))); ?> - <?php echo !empty($capability['available']) ? 'Available' : 'Unavailable: ' . htmlspecialchars((string) ($capability['unavailable_reason'] ?? 'not ready')); ?></p>
                                        <?php if (!empty($capability['permissions'])): ?>
                                            <p style="font-size:.86rem;color:#64748b;">Permissions: <?php echo htmlspecialchars(implode(', ', array_map('strval', (array) $capability['permissions']))); ?></p>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($moduleTargetMetricProviders)): ?>
                            <h4 style="margin:1.2rem 0 .5rem;color:#0f172a;">Target Metrics</h4>
                            <div class="marketplace-chip-row">
                                <?php foreach ($moduleTargetMetricProviders as $provider): ?>
                                    <span class="marketplace-chip"><?php echo htmlspecialchars((string) ($provider['metric_source'] ?? '') . '.' . (string) ($provider['metric_key'] ?? '')); ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <h4 style="margin:1.2rem 0 .5rem;color:#0f172a;">Recent Runtime Events</h4>
                        <?php if (empty($moduleRuntimeEvents)): ?>
                            <p style="color:#64748b;">No runtime events recorded for this module yet.</p>
                        <?php else: ?>
                            <div class="marketplace-overview-sections" style="margin-top:.5rem;">
                                <?php foreach ($moduleRuntimeEvents as $runtimeEvent): ?>
                                    <div class="marketplace-overview-block">
                                        <h4><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) ($runtimeEvent['event_type'] ?? 'runtime event')))); ?></h4>
                                        <p><?php echo htmlspecialchars((string) ($runtimeEvent['status'] ?? 'info')); ?> - <?php echo htmlspecialchars((string) ($runtimeEvent['capability_key'] ?? '')); ?> - <?php echo htmlspecialchars((string) ($runtimeEvent['created_at'] ?? '')); ?></p>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>

                    <section id="health" class="marketplace-detail-section marketplace-module-panel" data-marketplace-module-panel="health" role="tabpanel" hidden>
                        <h3>Test & Health</h3>
                        <p><?php echo htmlspecialchars((string) ($moduleReadiness['message'] ?? 'Install and configure this module to see readiness.')); ?></p>
                        <div class="marketplace-health-grid" style="margin-top:.75rem;">
                            <div class="marketplace-metric-card"><strong><?php echo htmlspecialchars($moduleStatusDisplay); ?></strong><span>Status</span></div>
                            <div class="marketplace-metric-card"><strong><?php echo $moduleInstalled ? 'Installed' : 'Available'; ?></strong><span>Workspace state</span></div>
                            <?php if ($moduleKey === WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS): ?>
                                <div class="marketplace-metric-card"><strong><?php echo !empty($moduleReadiness['ready']) ? 'Ready' : 'Needs setup'; ?></strong><span>Business model context</span></div>
                                <div class="marketplace-metric-card"><strong><?php echo !empty($moduleReadiness['ready']) ? 'Feeding AI' : 'Blocked'; ?></strong><span>Feeds AI guidance</span></div>
                            <?php elseif ($moduleKey === WorkspaceSkillCatalogService::SKILL_AI_COACH): ?>
                                <div class="marketplace-metric-card"><strong><?php echo !empty($moduleReadiness['ready']) ? 'Ready' : 'Needs setup'; ?></strong><span>Recommendation API</span></div>
                                <div class="marketplace-metric-card"><strong><?php echo count((array) ($moduleReadiness['ready_advice_skills'] ?? [])); ?></strong><span>Ready advice skills</span></div>
                            <?php elseif ($moduleKey === WorkspaceSkillCatalogService::PLUGIN_FINANCE): ?>
                                <div class="marketplace-metric-card"><strong><?php echo !empty($moduleReadiness['ready']) ? 'Ready' : 'Setup required'; ?></strong><span>Finance access</span></div>
                                <div class="marketplace-metric-card"><strong><?php echo (float) (($moduleReadiness['owners']['total_percent'] ?? 0)); ?>%</strong><span>Owner allocation</span></div>
                            <?php elseif ($moduleKey === WorkspaceSkillCatalogService::PLUGIN_AI_API): ?>
                                <div class="marketplace-metric-card"><strong><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) ($moduleReadiness['provider_source'] ?? 'local_fallback')))); ?></strong><span>Current AI route</span></div>
                                <div class="marketplace-metric-card"><strong><?php echo (int) ($moduleReadiness['common_used_today'] ?? 0); ?> / <?php echo (int) ($moduleReadiness['common_daily_token_cap'] ?? 0); ?></strong><span>Common cap today</span></div>
                            <?php else: ?>
                                <div class="marketplace-metric-card"><strong><?php echo !empty($moduleReadiness['outbound_ready']) ? 'Ready' : (!empty($moduleReadiness['ready']) ? 'Ready' : 'Needs setup'); ?></strong><span>Outbound/runtime</span></div>
                                <div class="marketplace-metric-card"><strong><?php echo !empty($moduleReadiness['inbound_ready']) ? 'Ready' : 'Optional'; ?></strong><span>Inbound/context</span></div>
                            <?php endif; ?>
                            <?php if ($moduleType !== 'plugin'): ?>
                                <div class="marketplace-metric-card"><strong><?php echo (int) ($moduleSkillSetupSummary['completed'] ?? 0); ?> / <?php echo (int) ($moduleSkillSetupSummary['total'] ?? 0); ?></strong><span>Setup fields</span></div>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($moduleReadiness['checks'])): ?>
                            <div class="marketplace-overview-sections" style="margin-top:1rem;">
                                <?php foreach ((array) $moduleReadiness['checks'] as $check): ?>
                                    <div class="marketplace-overview-block">
                                        <h4><?php echo !empty($check['ok']) ? 'Ready' : 'Needs Setup'; ?>: <?php echo htmlspecialchars((string) ($check['label'] ?? 'Check')); ?></h4>
                                        <p><?php echo htmlspecialchars((string) ($check['detail'] ?? '')); ?></p>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($canManage): ?>
                            <form method="POST" class="marketplace-detail-actions" style="margin-top:.85rem;">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                                <input type="hidden" name="skill_key" value="<?php echo htmlspecialchars($moduleKey); ?>">
                                <button class="btn-premium-primary" type="submit" name="skill_action" value="run_marketplace_readiness_check" <?php echo $moduleInstalled ? '' : 'disabled'; ?>>Run readiness check</button>
                                <button class="btn-premium-secondary" type="submit" name="skill_action" value="refresh_plugin_health">Refresh health</button>
                            </form>
                            <?php if ($moduleKey === WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT): ?>
                                <form method="POST" class="marketplace-setup-form" style="margin-top:1rem;border-top:1px solid #e2e8f0;padding-top:1rem;">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                                    <input type="hidden" name="skill_key" value="<?php echo htmlspecialchars($moduleKey); ?>">
                                    <label class="marketplace-form-field">Explicit test recipient email <input type="email" name="email_test_recipient" placeholder="owner@example.com"></label>
                                    <div class="marketplace-detail-actions"><button class="btn-premium-secondary" type="submit" name="skill_action" value="send_email_assistant_test_digest" <?php echo $moduleInstalled ? '' : 'disabled'; ?>>Send live test digest</button></div>
                                </form>
                            <?php elseif ($moduleKey === WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT): ?>
                                <form method="POST" class="marketplace-setup-form" style="margin-top:1rem;border-top:1px solid #e2e8f0;padding-top:1rem;">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                                    <input type="hidden" name="skill_key" value="<?php echo htmlspecialchars($moduleKey); ?>">
                                    <label class="marketplace-form-field">Explicit authorized WhatsApp test number <input type="text" name="whatsapp_test_recipient" placeholder="+254700000000"></label>
                                    <div class="marketplace-detail-actions"><button class="btn-premium-secondary" type="submit" name="skill_action" value="send_whatsapp_assistant_test_digest" <?php echo $moduleInstalled ? '' : 'disabled'; ?>>Send live test digest</button></div>
                                </form>
                            <?php elseif ($moduleKey === WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL): ?>
                                <form method="POST" class="marketplace-setup-form" style="margin-top:1rem;border-top:1px solid #e2e8f0;padding-top:1rem;">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                                    <input type="hidden" name="skill_key" value="<?php echo htmlspecialchars($moduleKey); ?>">
                                    <label class="marketplace-form-field">Explicit SMS test number <input type="text" name="sms_test_recipient" placeholder="+254700000000"></label>
                                    <div class="marketplace-detail-actions"><button class="btn-premium-secondary" type="submit" name="skill_action" value="send_sms_channel_test" <?php echo ($moduleInstalled && $moduleCanManage) ? '' : 'disabled'; ?>>Send live SMS test</button></div>
                                </form>
                            <?php endif; ?>
                            <div class="marketplace-detail-actions" style="margin-top:.85rem;">
                                <button class="btn-premium-secondary" type="button" data-marketplace-module-jump="setup">Open setup</button>
                            </div>
                        <?php endif; ?>
                    </section>
                    <?php endif; ?>

                    <?php if (!empty($module['is_custom']) && $canManage): ?>
                        <section id="skill-editor" class="marketplace-detail-section marketplace-module-panel" data-marketplace-module-panel="skill-editor" role="tabpanel" hidden>
                            <h3>Skill Editor</h3>
                            <form method="POST" class="marketplace-setup-form" style="margin-top:1rem;">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                                <input type="hidden" name="skill_key" value="<?php echo htmlspecialchars($moduleKey); ?>">
                                <div class="marketplace-form-grid">
                                    <label class="marketplace-form-field">Skill name <input type="text" name="custom_skill_label" value="<?php echo htmlspecialchars((string) ($module['label'] ?? '')); ?>" required></label>
                                    <label class="marketplace-form-field">Category <input type="text" name="custom_skill_category" value="<?php echo htmlspecialchars((string) ($module['category'] ?? 'custom')); ?>"></label>
                                    <label class="marketplace-form-field marketplace-form-field-wide">Purpose <textarea name="custom_skill_summary" rows="3"><?php echo htmlspecialchars((string) ($module['summary'] ?? '')); ?></textarea></label>
                                    <label class="marketplace-form-field marketplace-form-field-wide">Advice domains <textarea name="custom_skill_advice_domains" rows="2"><?php echo htmlspecialchars(implode(', ', (array) ($module['advice_domains'] ?? []))); ?></textarea></label>
                                    <label class="marketplace-form-field marketplace-form-field-wide">Required context fields <textarea name="custom_skill_context_fields" rows="4"><?php echo htmlspecialchars(implode("\n", array_map(static fn(array $field): string => (string) ($field['label'] ?? $field['key'] ?? ''), (array) ($module['context_schema']['fields'] ?? [])))); ?></textarea></label>
                                    <label class="marketplace-form-field marketplace-form-field-wide">Guidance instructions <textarea name="custom_skill_guidance_instructions" rows="4"><?php echo htmlspecialchars((string) ($module['plugin_metadata']['guidance_instructions'] ?? '')); ?></textarea></label>
                                    <label class="marketplace-form-field marketplace-form-field-wide">Task templates <textarea name="custom_skill_task_templates" rows="4"><?php echo htmlspecialchars(implode("\n", array_map(static fn(array $task): string => (string) ($task['title'] ?? ''), (array) ($module['task_templates'] ?? [])))); ?></textarea></label>
                                    <label class="marketplace-form-field marketplace-form-field-wide">Routing examples <textarea name="custom_skill_routing_examples" rows="3"><?php echo htmlspecialchars(implode("\n", (array) ($module['routing_examples'] ?? []))); ?></textarea></label>
                                </div>
                                <div class="marketplace-detail-actions">
                                    <button class="btn-premium-primary" type="submit" name="skill_action" value="update_custom_skill">Save skill</button>
                                    <button class="btn-premium-secondary" type="submit" name="skill_action" value="archive_custom_skill" onclick="return confirm('Archive this custom skill for this workspace?');">Archive skill</button>
                                </div>
                            </form>
                        </section>
                    <?php endif; ?>

                    <?php if ($canViewMarketplacePerformance): ?>
                        <section id="performance" class="marketplace-detail-section marketplace-module-panel" data-marketplace-module-panel="performance" data-marketplace-performance-panel data-marketplace-performance-module="<?php echo htmlspecialchars($moduleKey); ?>" role="tabpanel" hidden>
                            <h3>Superadmin performance</h3>
                            <?php $modulePerformanceMetrics = (array) ($modulePluginPerformance['metrics'] ?? []); ?>
                            <div class="marketplace-performance-grid">
                                <div class="marketplace-metric-card"><strong><?php echo (int) ($modulePerformanceMetrics['impressions'] ?? 0); ?></strong><span>Impressions 30d</span></div>
                                <div class="marketplace-metric-card"><strong><?php echo (int) ($modulePerformanceMetrics['module_page_views'] ?? 0); ?></strong><span>Page views 30d</span></div>
                                <div class="marketplace-metric-card"><strong><?php echo (int) ($modulePerformanceMetrics['catalog_clicks'] ?? 0); ?></strong><span>Catalog clicks 30d</span></div>
                                <div class="marketplace-metric-card"><strong><?php echo (int) ($modulePerformanceMetrics['clicks'] ?? 0); ?></strong><span>Clicks 30d</span></div>
                                <div class="marketplace-metric-card"><strong><?php echo (int) ($modulePerformanceMetrics['installs'] ?? 0); ?></strong><span>Installs 30d</span></div>
                                <div class="marketplace-metric-card"><strong><?php echo (int) ($modulePerformanceMetrics['removals'] ?? 0); ?></strong><span>Removals 30d</span></div>
                                <div class="marketplace-metric-card"><strong><?php echo (int) ($modulePerformanceMetrics['active_users'] ?? 0); ?></strong><span>Active users 30d</span></div>
                                <div class="marketplace-metric-card"><strong><?php echo number_format(((float) ($modulePerformanceMetrics['click_through_rate'] ?? 0)) * 100, 1); ?>%</strong><span>Click-through rate</span></div>
                                <div class="marketplace-metric-card"><strong><?php echo (int) ($modulePerformanceMetrics['setup_saves'] ?? 0); ?></strong><span>Setup saves 30d</span></div>
                                <div class="marketplace-metric-card"><strong><?php echo (int) ($modulePerformanceMetrics['test_attempts'] ?? 0); ?></strong><span>Test attempts 30d</span></div>
                                <div class="marketplace-metric-card"><strong><?php echo (int) ($modulePerformanceMetrics['test_passes'] ?? 0); ?> / <?php echo (int) ($modulePerformanceMetrics['test_failures'] ?? 0); ?></strong><span>Test pass / fail</span></div>
                                <?php if ($moduleType === 'plugin'): ?>
                                    <div class="marketplace-metric-card"><strong><?php echo htmlspecialchars((string) (($modulePluginPerformance['config_updated_at'] ?? '') ?: 'Not saved')); ?></strong><span>Config updated</span></div>
                                <?php else: ?>
                                    <div class="marketplace-metric-card"><strong><?php echo !empty($modulePerformanceMetrics['current_installed']) ? 'Installed' : 'Available'; ?></strong><span>Current state</span></div>
                                <?php endif; ?>
                                <?php foreach ($modulePluginRuntime as $runtimeLabel => $runtimeValue): ?>
                                    <div class="marketplace-metric-card"><strong><?php echo htmlspecialchars((string) ($runtimeValue !== '' ? $runtimeValue : 'None')); ?></strong><span><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) $runtimeLabel))); ?></span></div>
                                <?php endforeach; ?>
                            </div>
                            <?php if (!empty($modulePluginPerformance['recent_events'])): ?>
                                <div style="margin-top:1rem;">
                                    <h4 style="margin:0 0 .55rem;color:#0f172a;font-size:1rem;">Recent marketplace activity</h4>
                                    <div class="marketplace-activity-list">
                                        <?php foreach ((array) $modulePluginPerformance['recent_events'] as $event): ?>
                                            <div class="marketplace-activity-row">
                                                <strong><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) ($event['event_type'] ?? 'event')))); ?></strong>
                                                <span><?php echo htmlspecialchars((string) ($event['source'] ?? 'marketplace')); ?><?php echo !empty($event['created_at']) ? ' - ' . htmlspecialchars((string) $event['created_at']) : ''; ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <script type="application/json" id="marketplace-performance-context-<?php echo htmlspecialchars($moduleKey); ?>"><?php echo json_encode($modulePluginPerformance, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES); ?></script>
                        </section>
                    <?php endif; ?>

                    <?php if ($canEditMarketplaceCatalog): ?>
                        <section id="catalog-editor" class="marketplace-detail-section marketplace-module-panel marketplace-catalog-editor" data-marketplace-module-panel="catalog-editor" role="tabpanel" hidden>
                            <div class="marketplace-catalog-editor-heading">
                                <div>
                                    <h3>Superadmin catalog editor</h3>
                                    <p>Use Clarity to draft or optimise the text fields, then review the copy before saving it to the global Marketplace catalog.</p>
                                </div>
                                <form method="POST" action="workspace_skills.php?module=<?php echo urlencode($moduleKey); ?>#catalog-editor" style="margin:0;">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                                    <input type="hidden" name="skill_key" value="<?php echo htmlspecialchars($moduleKey); ?>">
                                    <button class="btn-premium-secondary" type="submit" name="skill_action" value="draft_catalog_copy">Draft/optimise with Clarity</button>
                                </form>
                            </div>
                            <form method="POST" enctype="multipart/form-data" class="marketplace-setup-form marketplace-catalog-editor-form" action="workspace_skills.php?module=<?php echo urlencode($moduleKey); ?>#catalog-editor" data-marketplace-catalog-form data-marketplace-inline-image-endpoint="../api/workspace/marketplace_catalog_inline_image.php">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                                <input type="hidden" name="skill_key" value="<?php echo htmlspecialchars($moduleKey); ?>">
                                <div class="marketplace-catalog-editor-grid">
                                    <section class="marketplace-catalog-editor-panel marketplace-catalog-editor-panel-copy">
                                        <div class="marketplace-catalog-editor-panel-head">
                                            <div>
                                                <strong>Catalog copy</strong>
                                                <span>Top-level listing and hero copy used across Marketplace discovery.</span>
                                            </div>
                                        </div>
                                        <div class="marketplace-form-grid">
                                            <label class="marketplace-form-field">Title <input type="text" name="catalog_label" value="<?php echo htmlspecialchars((string) ($module['label'] ?? '')); ?>"></label>
                                            <label class="marketplace-form-field marketplace-form-field-wide">Short description <textarea name="catalog_summary" rows="4"><?php echo htmlspecialchars((string) ($module['summary'] ?? '')); ?></textarea></label>
                                            <label class="marketplace-form-field marketplace-form-field-wide">Pitch <textarea name="catalog_pitch" rows="5"><?php echo htmlspecialchars((string) ($moduleProfile['pitch'] ?? '')); ?></textarea></label>
                                        </div>
                                    </section>
                                    <?php if (empty($module['is_custom'])): ?>
                                        <section class="marketplace-catalog-editor-panel marketplace-catalog-editor-panel-availability">
                                            <div class="marketplace-catalog-editor-panel-head">
                                                <div>
                                                    <strong>Availability</strong>
                                                    <span><?php echo htmlspecialchars($moduleCatalogStatusMessage); ?></span>
                                                </div>
                                            </div>
                                            <div class="marketplace-form-grid">
                                                <label class="marketplace-form-field marketplace-form-field-wide">Catalog status
                                                    <select name="catalog_status">
                                                        <option value="<?php echo WorkspaceSkillCatalogService::CATALOG_STATUS_VISIBLE; ?>" <?php echo $moduleCatalogStatus === WorkspaceSkillCatalogService::CATALOG_STATUS_VISIBLE ? 'selected' : ''; ?>>Visible</option>
                                                        <option value="<?php echo WorkspaceSkillCatalogService::CATALOG_STATUS_HIDDEN; ?>" <?php echo $moduleCatalogStatus === WorkspaceSkillCatalogService::CATALOG_STATUS_HIDDEN ? 'selected' : ''; ?>>Hidden</option>
                                                        <option value="<?php echo WorkspaceSkillCatalogService::CATALOG_STATUS_DEACTIVATED; ?>" <?php echo $moduleCatalogStatus === WorkspaceSkillCatalogService::CATALOG_STATUS_DEACTIVATED ? 'selected' : ''; ?>>Deactivated</option>
                                                    </select>
                                                </label>
                                            </div>
                                            <div class="marketplace-detail-actions" style="justify-content:flex-start;margin:0;">
                                                <button class="btn-premium-secondary" type="submit" name="skill_action" value="save_catalog_status">Save availability</button>
                                            </div>
                                        </section>
                                    <?php endif; ?>
                                    <?php if ($moduleKey === WorkspaceSkillCatalogService::PLUGIN_HR_ANALYTICS_SETUP): ?>
                                        <section class="marketplace-catalog-editor-panel marketplace-catalog-editor-panel-technical marketplace-form-field-wide">
                                            <div class="marketplace-catalog-editor-panel-head">
                                                <div>
                                                    <strong>Organization Intelligence technical setup</strong>
                                                    <span>Superadmin-only tuning for scoring thresholds, weights, labels, and AI guidance prompts.</span>
                                                </div>
                                            </div>
                                            <label class="marketplace-form-check"><input type="checkbox" name="hr_ai_enabled" <?php echo !empty($moduleHrSettings['ai_enabled']) ? 'checked' : ''; ?>> Enable AI-generated SWOT, tips, and action-plan drafts</label>
                                            <div class="marketplace-form-grid">
                                                <label class="marketplace-form-field">High performer threshold <input type="number" min="50" max="100" name="hr_threshold_high_performer" value="<?php echo (int) ($hrThresholds['high_performer'] ?? 75); ?>"></label>
                                                <label class="marketplace-form-field">At-risk threshold <input type="number" min="0" max="80" name="hr_threshold_at_risk" value="<?php echo (int) ($hrThresholds['at_risk'] ?? 45); ?>"></label>
                                                <label class="marketplace-form-field">Needs coaching threshold <input type="number" min="0" max="90" name="hr_threshold_needs_coaching" value="<?php echo (int) ($hrThresholds['needs_coaching'] ?? 55); ?>"></label>
                                                <label class="marketplace-form-field">Overloaded task count <input type="number" min="1" max="50" name="hr_threshold_overloaded_task_count" value="<?php echo (int) ($hrThresholds['overloaded_task_count'] ?? 7); ?>"></label>
                                                <label class="marketplace-form-field">Inactive days <input type="number" min="1" max="60" name="hr_threshold_inactive_days" value="<?php echo (int) ($hrThresholds['inactive_days'] ?? 10); ?>"></label>
                                                <?php foreach ($hrWeightRoles as $weightRole => $weightRoleLabel): ?>
                                                    <div class="marketplace-form-field marketplace-form-field-wide" style="border:1px solid #e2e8f0;border-radius:8px;padding:.85rem;background:#fff;">
                                                        <strong style="display:block;color:#0f172a;margin-bottom:.55rem;"><?php echo htmlspecialchars($weightRoleLabel); ?></strong>
                                                        <div class="marketplace-form-grid">
                                                            <?php foreach ($hrWeightLabels as $weightKey => $weightLabel): ?>
                                                                <label class="marketplace-form-field"><?php echo htmlspecialchars($weightLabel); ?>
                                                                    <input type="number" min="0" max="1" step="0.01" name="hr_weight_<?php echo htmlspecialchars($weightRole . '_' . $weightKey); ?>" value="<?php echo htmlspecialchars((string) ($hrWeights[$weightRole][$weightKey] ?? 0)); ?>">
                                                                </label>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                                <label class="marketplace-form-field">Marketing department label <input type="text" name="hr_department_marketing" value="<?php echo htmlspecialchars((string) ($hrMappings['marketing'] ?? 'Marketing')); ?>"></label>
                                                <label class="marketplace-form-field">Sales department label <input type="text" name="hr_department_sales" value="<?php echo htmlspecialchars((string) ($hrMappings['sales'] ?? 'Sales')); ?>"></label>
                                                <label class="marketplace-form-field">Leadership department label <input type="text" name="hr_department_admin" value="<?php echo htmlspecialchars((string) ($hrMappings['admin'] ?? 'Leadership')); ?>"></label>
                                                <label class="marketplace-form-field">Owner department label <input type="text" name="hr_department_owner" value="<?php echo htmlspecialchars((string) ($hrMappings['owner'] ?? 'Leadership')); ?>"></label>
                                                <label class="marketplace-form-field">Operations department label <input type="text" name="hr_department_viewer" value="<?php echo htmlspecialchars((string) ($hrMappings['viewer'] ?? 'Operations')); ?>"></label>
                                                <label class="marketplace-form-field marketplace-form-field-wide">Manager guidance focus <textarea name="hr_manager_focus" rows="3"><?php echo htmlspecialchars((string) ($hrPromptConfig['manager_focus'] ?? '')); ?></textarea></label>
                                                <label class="marketplace-form-field marketplace-form-field-wide">SWOT guidance focus <textarea name="hr_swot_focus" rows="3"><?php echo htmlspecialchars((string) ($hrPromptConfig['swot_focus'] ?? '')); ?></textarea></label>
                                            </div>
                                            <div class="marketplace-detail-actions" style="justify-content:flex-start;margin:0;">
                                                <button class="btn-premium-secondary" type="submit" name="skill_action" value="save_hr_analytics_setup">Save technical setup</button>
                                            </div>
                                        </section>
                                    <?php endif; ?>
                                    <section class="marketplace-catalog-editor-panel marketplace-catalog-editor-panel-media">
                                        <div class="marketplace-catalog-editor-panel-head">
                                            <div>
                                                <strong>Media</strong>
                                                <span>Listing artwork, hero banner, explainer videos, and setup/page guide videos.</span>
                                            </div>
                                        </div>
                                        <div class="marketplace-form-grid">
                                            <label class="marketplace-form-field">Thumbnail upload
                                                <?php if ($moduleThumbnailRaw !== ''): ?><a href="<?php echo htmlspecialchars($marketplaceAsset($moduleThumbnailRaw)); ?>" target="_blank" rel="noopener noreferrer" style="font-size:.78rem;color:#2563eb;">Current thumbnail</a><?php endif; ?>
                                                <input type="file" name="marketplace_thumbnail_file" accept="image/jpeg,image/png,image/webp,image/gif">
                                            </label>
                                            <label class="marketplace-form-field">Thumbnail alt <input type="text" name="catalog_thumbnail_alt" value="<?php echo htmlspecialchars((string) ($moduleProfile['thumbnail_alt'] ?? '')); ?>"></label>
                                            <label class="marketplace-form-field">Banner upload
                                                <?php if ($moduleBannerRaw !== ''): ?><a href="<?php echo htmlspecialchars($marketplaceAsset($moduleBannerRaw)); ?>" target="_blank" rel="noopener noreferrer" style="font-size:.78rem;color:#2563eb;">Current banner</a><?php endif; ?>
                                                <input type="file" name="marketplace_banner_file" accept="image/jpeg,image/png,image/webp,image/gif">
                                            </label>
                                            <label class="marketplace-form-field">Banner alt <input type="text" name="catalog_banner_alt" value="<?php echo htmlspecialchars((string) ($moduleProfile['banner_alt'] ?? '')); ?>"></label>
                                            <label class="marketplace-form-field">Explainer video upload
                                                <?php if ($moduleVideoRaw !== ''): ?><a href="<?php echo htmlspecialchars($marketplaceAsset($moduleVideoRaw)); ?>" target="_blank" rel="noopener noreferrer" style="font-size:.78rem;color:#2563eb;">Current video</a><?php endif; ?>
                                                <input type="file" name="marketplace_explainer_video_file" accept="video/mp4,video/webm,video/quicktime,video/x-m4v">
                                            </label>
                                            <label class="marketplace-form-field">Video orientation
                                                <select name="catalog_explainer_orientation">
                                                    <option value="landscape" <?php echo $moduleVideoOrientation === 'landscape' ? 'selected' : ''; ?>>Landscape</option>
                                                    <option value="portrait" <?php echo $moduleVideoOrientation === 'portrait' ? 'selected' : ''; ?>>Portrait</option>
                                                </select>
                                            </label>
                                            <label class="marketplace-form-field marketplace-form-field-wide">Explainer video URL <input type="url" name="catalog_explainer_video_url" value="<?php echo preg_match('#^https?://#i', $moduleVideoRaw) === 1 ? htmlspecialchars($moduleVideoRaw) : ''; ?>" placeholder="https://youtube.com/watch?v=... or https://cdn.example.com/demo.mp4"></label>
                                            <?php if ($moduleKey === WorkspaceSkillCatalogService::SKILL_AI_COACH): ?>
                                                <div class="marketplace-catalog-editor-note marketplace-form-field-wide">
                                                    <strong>AI Coach explainer placement</strong>
                                                    <span>Used on the Marketplace card and optional Personal Strategy guidance surfaces.</span>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($moduleVideoRaw !== ''): ?>
                                                <label class="marketplace-form-check marketplace-form-field-wide"><input type="checkbox" name="catalog_remove_explainer_video" value="1"> Remove current explainer video</label>
                                            <?php endif; ?>
                                            <?php if ($moduleRequiresSetup): ?>
                                                <div class="marketplace-catalog-editor-nested marketplace-form-field-wide">
                                                    <strong>Setup video</strong>
                                                    <span>Shown inside this module's Setup tab. Separate from the Marketplace explainer and page guide videos.</span>
                                                    <label class="marketplace-form-field">
                                                        Setup video upload
                                                        <?php if ($moduleSetupVideoRaw !== ''): ?><a href="<?php echo htmlspecialchars($moduleSetupVideoUrl); ?>" target="_blank" rel="noopener noreferrer" style="font-size:.78rem;color:#2563eb;">Current setup video</a><?php endif; ?>
                                                        <input type="file" name="marketplace_setup_video_file" accept="video/mp4,video/webm,video/quicktime,video/x-m4v">
                                                    </label>
                                                    <?php if ($moduleSetupVideoRaw !== ''): ?>
                                                        <label class="marketplace-form-check"><input type="checkbox" name="catalog_remove_setup_video" value="1"> Remove setup video</label>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($moduleKey === WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS): ?>
                                                <?php
                                                    $catalogPageGuideRows = [
                                                        MarketplacePageExplainerService::PAGE_STARTUP_JOURNEY => (array) $startupJourneyPageExplainer,
                                                        MarketplacePageExplainerService::PAGE_FOUNDER_OPERATING_LOOP => (array) $founderLoopPageExplainer,
                                                    ];
                                                ?>
                                                <div class="marketplace-catalog-editor-nested marketplace-form-field-wide">
                                                    <strong>Page guide videos</strong>
                                                    <span>Separate help videos for the Clarity Journey and Founder Loop pages.</span>
                                                    <div class="marketplace-catalog-editor-media-grid">
                                                        <?php foreach ([MarketplacePageExplainerService::PAGE_STARTUP_JOURNEY, MarketplacePageExplainerService::PAGE_FOUNDER_OPERATING_LOOP] as $pageGuideKey): ?>
                                                            <?php
                                                                $pageGuideConfig = (array) ($marketplacePageExplainerConfig[$pageGuideKey] ?? []);
                                                                $pageGuideRow = (array) ($catalogPageGuideRows[$pageGuideKey] ?? []);
                                                                $pageGuideVideo = trim((string) ($pageGuideRow['video_url'] ?? ''));
                                                                $pageGuideActive = !empty($pageGuideRow['is_active']);
                                                            ?>
                                                            <div class="marketplace-catalog-editor-media-tile">
                                                                <label class="marketplace-form-field">
                                                                    <?php echo htmlspecialchars((string) ($pageGuideConfig['label'] ?? 'Page guide')); ?>
                                                                    <?php if ($pageGuideVideo !== ''): ?><a href="<?php echo htmlspecialchars($marketplaceAsset($pageGuideVideo)); ?>" target="_blank" rel="noopener noreferrer" style="font-size:.78rem;color:#2563eb;">Current video</a><?php endif; ?>
                                                                    <input type="file" name="<?php echo htmlspecialchars((string) ($pageGuideConfig['file_field'] ?? '')); ?>" accept="video/mp4,video/webm,video/quicktime,video/x-m4v">
                                                                </label>
                                                                <input type="hidden" name="<?php echo htmlspecialchars((string) ($pageGuideConfig['active_field'] ?? '')); ?>" value="0">
                                                                <label class="marketplace-form-check"><input type="checkbox" name="<?php echo htmlspecialchars((string) ($pageGuideConfig['active_field'] ?? '')); ?>" value="1" <?php echo $pageGuideActive ? 'checked' : ''; ?>> Active</label>
                                                                <?php if ($pageGuideVideo !== ''): ?>
                                                                    <label class="marketplace-form-check"><input type="checkbox" name="<?php echo htmlspecialchars((string) ($pageGuideConfig['remove_field'] ?? '')); ?>" value="1"> Remove current video</label>
                                                                <?php endif; ?>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                </div>
                                            <?php elseif ($moduleKey === WorkspaceSkillCatalogService::PLUGIN_FINANCE): ?>
                                                <?php
                                                    $pageGuideKey = MarketplacePageExplainerService::PAGE_FINANCE;
                                                    $pageGuideConfig = (array) ($marketplacePageExplainerConfig[$pageGuideKey] ?? []);
                                                    $pageGuideRow = (array) $financePageExplainer;
                                                    $pageGuideVideo = trim((string) ($pageGuideRow['video_url'] ?? ''));
                                                    $pageGuideActive = !empty($pageGuideRow['is_active']);
                                                ?>
                                                <div class="marketplace-catalog-editor-nested marketplace-form-field-wide">
                                                    <strong>Page guide video</strong>
                                                    <span>Separate help video for the Finance page.</span>
                                                    <div class="marketplace-catalog-editor-media-tile">
                                                        <label class="marketplace-form-field">
                                                            <?php echo htmlspecialchars((string) ($pageGuideConfig['label'] ?? 'Finance page guide')); ?>
                                                            <?php if ($pageGuideVideo !== ''): ?><a href="<?php echo htmlspecialchars($marketplaceAsset($pageGuideVideo)); ?>" target="_blank" rel="noopener noreferrer" style="font-size:.78rem;color:#2563eb;">Current video</a><?php endif; ?>
                                                            <input type="file" name="<?php echo htmlspecialchars((string) ($pageGuideConfig['file_field'] ?? '')); ?>" accept="video/mp4,video/webm,video/quicktime,video/x-m4v">
                                                        </label>
                                                        <input type="hidden" name="<?php echo htmlspecialchars((string) ($pageGuideConfig['active_field'] ?? '')); ?>" value="0">
                                                        <label class="marketplace-form-check"><input type="checkbox" name="<?php echo htmlspecialchars((string) ($pageGuideConfig['active_field'] ?? '')); ?>" value="1" <?php echo $pageGuideActive ? 'checked' : ''; ?>> Active</label>
                                                        <?php if ($pageGuideVideo !== ''): ?>
                                                            <label class="marketplace-form-check"><input type="checkbox" name="<?php echo htmlspecialchars((string) ($pageGuideConfig['remove_field'] ?? '')); ?>" value="1"> Remove current video</label>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="marketplace-detail-actions" style="justify-content:flex-start;margin:0;">
                                            <button class="btn-premium-secondary" type="submit" name="skill_action" value="save_catalog_media">Save media</button>
                                        </div>
                                    </section>
                                    <section class="marketplace-rich-editor-panel marketplace-form-field-wide" data-marketplace-rich-editor>
                                        <div class="marketplace-rich-editor-head">
                                            <div>
                                                <strong>Brief Overview card</strong>
                                                <span>Short owner-facing introduction for the module overview.</span>
                                            </div>
                                            <label>Mode
                                                <?php $briefEditorFormat = (string) ($moduleProfile['overview_brief_format'] ?? 'text') === 'html' ? 'html' : 'text'; ?>
                                                <select name="catalog_overview_brief_format" data-marketplace-rich-format>
                                                    <option value="text" <?php echo $briefEditorFormat === 'text' ? 'selected' : ''; ?>>Text</option>
                                                    <option value="html" <?php echo $briefEditorFormat === 'html' ? 'selected' : ''; ?>>HTML</option>
                                                </select>
                                            </label>
                                        </div>
                                        <div class="marketplace-rich-editor-tools" aria-label="Brief Overview rich text helpers">
                                            <button type="button" data-marketplace-rich-insert="heading">Heading</button>
                                            <button type="button" data-marketplace-rich-insert="blockquote">Quote</button>
                                            <button type="button" data-marketplace-rich-insert="image">Image</button>
                                            <button type="button" data-marketplace-rich-insert="link">Link</button>
                                            <button type="button" data-marketplace-rich-insert="list">List</button>
                                        </div>
                                        <div class="marketplace-rich-editor-grid">
                                            <label class="marketplace-form-field">Brief content
                                                <textarea name="catalog_overview_brief_content" rows="12" data-marketplace-rich-content><?php echo htmlspecialchars((string) ($moduleProfile['overview_brief_content'] ?? '')); ?></textarea>
                                            </label>
                                            <div class="marketplace-rich-editor-preview" aria-live="polite">
                                                <span>Preview</span>
                                                <div data-marketplace-rich-preview></div>
                                            </div>
                                        </div>
                                    </section>
                                    <section class="marketplace-rich-editor-panel marketplace-form-field-wide" data-marketplace-rich-editor>
                                        <div class="marketplace-rich-editor-head">
                                            <div>
                                                <strong>Technical Deep Dive card</strong>
                                                <span>Full technical details, setup nuance, requirements, and recommendations.</span>
                                            </div>
                                            <label>Mode
                                                <?php $deepDiveEditorFormat = (string) ($moduleProfile['overview_deep_dive_format'] ?? 'text') === 'html' ? 'html' : 'text'; ?>
                                                <select name="catalog_overview_deep_dive_format" data-marketplace-rich-format>
                                                    <option value="text" <?php echo $deepDiveEditorFormat === 'text' ? 'selected' : ''; ?>>Text</option>
                                                    <option value="html" <?php echo $deepDiveEditorFormat === 'html' ? 'selected' : ''; ?>>HTML</option>
                                                </select>
                                            </label>
                                        </div>
                                        <div class="marketplace-rich-editor-tools" aria-label="Technical Deep Dive rich text helpers">
                                            <button type="button" data-marketplace-rich-insert="heading">Heading</button>
                                            <button type="button" data-marketplace-rich-insert="blockquote">Quote</button>
                                            <button type="button" data-marketplace-rich-insert="image">Image</button>
                                            <button type="button" data-marketplace-rich-insert="link">Link</button>
                                            <button type="button" data-marketplace-rich-insert="list">List</button>
                                        </div>
                                        <div class="marketplace-rich-editor-grid">
                                            <label class="marketplace-form-field">Technical deep dive content
                                                <textarea name="catalog_overview_deep_dive_content" rows="16" data-marketplace-rich-content><?php echo htmlspecialchars((string) ($moduleProfile['overview_deep_dive_content'] ?? '')); ?></textarea>
                                            </label>
                                            <div class="marketplace-rich-editor-preview" aria-live="polite">
                                                <span>Preview</span>
                                                <div data-marketplace-rich-preview></div>
                                            </div>
                                        </div>
                                    </section>
                                </div>
                                <div class="marketplace-detail-actions">
                                    <button class="btn-premium-primary" type="submit" name="skill_action" value="save_catalog_override">Save catalog content</button>
                                    <button class="btn-premium-secondary" type="submit" name="skill_action" value="reset_catalog_override">Reset to defaults</button>
                                </div>
                            </form>
                            <div class="marketplace-inline-image-modal" data-marketplace-inline-image-modal hidden>
                                <div class="marketplace-inline-image-dialog" role="dialog" aria-modal="true" aria-labelledby="marketplace-inline-image-title">
                                    <div class="marketplace-inline-image-head">
                                        <div>
                                            <strong id="marketplace-inline-image-title">Insert image</strong>
                                            <span>Upload a Marketplace image, add useful alt text, and insert it into the selected overview section.</span>
                                        </div>
                                        <button type="button" class="marketplace-inline-image-close" data-marketplace-inline-image-cancel aria-label="Close image upload">&times;</button>
                                    </div>
                                    <div class="marketplace-inline-image-body">
                                        <label class="marketplace-form-field">Image file
                                            <input type="file" name="image_file" accept="image/jpeg,image/png,image/webp,image/gif" data-marketplace-inline-image-file>
                                        </label>
                                        <label class="marketplace-form-field">Alt text
                                            <input type="text" name="alt" data-marketplace-inline-image-alt placeholder="Describe the image for screen readers" required>
                                        </label>
                                        <label class="marketplace-form-field">Caption
                                            <input type="text" name="caption" data-marketplace-inline-image-caption placeholder="Optional caption">
                                        </label>
                                        <div class="marketplace-inline-image-preview" data-marketplace-inline-image-preview>
                                            <span>Choose an image to preview it here.</span>
                                        </div>
                                        <div class="marketplace-inline-image-status" data-marketplace-inline-image-status aria-live="polite"></div>
                                    </div>
                                    <div class="marketplace-detail-actions marketplace-inline-image-actions">
                                        <button type="button" class="btn-premium-secondary" data-marketplace-inline-image-cancel>Cancel</button>
                                        <button type="button" class="btn-premium-primary" data-marketplace-inline-image-upload>Upload and insert</button>
                                    </div>
                                </div>
                            </div>
                        </section>
                    <?php endif; ?>
                </main>

            </div>
        </section>
    <?php else: ?>

    <div class="marketplace-dashboard-body">
    <main class="marketplace-dashboard-catalog">

    <section class="content-card marketplace-catalog-card" aria-label="Workspace marketplace catalog">
    <div class="marketplace-shell">
    <div class="marketplace-toolbar">
        <div class="marketplace-tabs" role="tablist" aria-label="Marketplace filters">
            <?php
            $marketplaceFilters = ['all' => 'All', 'skill' => 'Skills', 'plugin' => 'Plugins', 'installed' => 'Installed', 'available' => 'Available'];
            if ($canManage) {
                $marketplaceFilters['custom-skill'] = 'Create skill';
            }
            if ($canEditMarketplaceCatalog) {
                $marketplaceFilters['bundle-manager'] = 'Bundles';
            }
            ?>
            <?php foreach ($marketplaceFilters as $filter => $label): ?>
                <button type="button" class="marketplace-filter" data-marketplace-filter="<?php echo htmlspecialchars($filter); ?>" aria-pressed="<?php echo $filter === 'all' ? 'true' : 'false'; ?>"><?php echo htmlspecialchars($label); ?></button>
            <?php endforeach; ?>
        </div>
        <label class="marketplace-goal-select">Goal
            <select id="marketplace-goal-filter" aria-label="Filter marketplace by goal">
                <option value="">Any goal</option>
            <?php foreach ($marketplaceCanonicalTags as $tagLabel): ?>
                <?php $tagValue = $marketplaceTagKey($tagLabel); ?>
                    <option value="<?php echo htmlspecialchars($tagValue); ?>"><?php echo htmlspecialchars($tagLabel); ?></option>
            <?php endforeach; ?>
            </select>
        </label>
        <label class="marketplace-search">
            <i class="fas fa-search" aria-hidden="true"></i>
            <span class="sr-only">Search marketplace</span>
            <input id="marketplace-search" type="search" placeholder="Search marketplace" autocomplete="off">
        </label>
        <div class="marketplace-filter-actions">
            <span id="marketplace-result-count" class="marketplace-result-count" aria-live="polite"></span>
            <button type="button" id="marketplace-reset-filters" class="marketplace-filter-reset">Reset</button>
        </div>
    </div>

    <?php if ($canManage): ?>
        <section class="marketplace-detail-section marketplace-section" data-marketplace-section="custom-skill" hidden style="margin:0 0 1rem;">
            <div style="display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;flex-wrap:wrap;margin-bottom:.75rem;">
                <div>
                    <h2 style="font-size:1.05rem;margin:0;color:#0f172a;">Create custom skill</h2>
                    <p style="margin:.25rem 0 0;color:#64748b;font-size:.9rem;">Create workspace-owned guidance skills for context Clarity should understand.</p>
                </div>
            </div>
            <form method="POST" class="marketplace-setup-form" style="margin-top:1rem;">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                <div class="marketplace-form-grid">
                    <label class="marketplace-form-field">Skill name <input type="text" name="custom_skill_label" placeholder="Growth Coach" required></label>
                    <label class="marketplace-form-field">Category <input type="text" name="custom_skill_category" value="custom"></label>
                    <label class="marketplace-form-field marketplace-form-field-wide">Purpose <textarea name="custom_skill_summary" rows="3" placeholder="What this skill should help the workspace decide or improve."></textarea></label>
                    <label class="marketplace-form-field marketplace-form-field-wide">Advice domains <textarea name="custom_skill_advice_domains" rows="2" placeholder="growth, retention, partnerships"></textarea></label>
                    <label class="marketplace-form-field marketplace-form-field-wide">Required context fields <textarea name="custom_skill_context_fields" rows="4" placeholder="Current growth goal&#10;Primary constraint&#10;Monthly budget?"></textarea></label>
                    <label class="marketplace-form-field marketplace-form-field-wide">Guidance instructions <textarea name="custom_skill_guidance_instructions" rows="4" placeholder="How Clarity should reason when this skill is matched."></textarea></label>
                    <label class="marketplace-form-field marketplace-form-field-wide">Task templates <textarea name="custom_skill_task_templates" rows="4" placeholder="Review the primary growth constraint&#10;Define one measurable experiment"></textarea></label>
                    <label class="marketplace-form-field marketplace-form-field-wide">Routing examples <textarea name="custom_skill_routing_examples" rows="3" placeholder="How should we grow retention this quarter?"></textarea></label>
                </div>
                <div class="marketplace-detail-actions"><button class="btn-premium-primary" type="submit" name="skill_action" value="create_custom_skill">Create skill</button></div>
            </form>
        </section>
    <?php endif; ?>

    <div class="marketplace-section" data-marketplace-section="catalog">
    <div class="marketplace-card-grid" id="marketplace-card-grid">
        <?php foreach ($marketplaceModules as $marketplaceItem): ?>
            <?php
            $skill = (array) $marketplaceItem['module'];
            $skillKey = (string) $marketplaceItem['key'];
            $type = (string) $marketplaceItem['type'];
            $isInstalled = !empty($marketplaceItem['is_installed']);
            $isProtectedInstall = $marketplaceIsProtectedInstall($skill);
            $catalogStatus = (string) ($skill['catalog_status'] ?? WorkspaceSkillCatalogService::CATALOG_STATUS_VISIBLE);
            $catalogStatusLabel = $marketplaceCatalogStatusLabel($catalogStatus);
            $profile = (array) $marketplaceItem['profile'];
            $cardAccess = (array) ($marketplaceItem['access'] ?? []);
            $cardAccessState = (string) ($cardAccess['state'] ?? '');
            $cardLocked = !empty($cardAccess['is_locked']);
            $cardDeferred = !empty($cardAccess['deferred']);
            $itemTags = (array) ($marketplaceItem['tags'] ?? []);
            $tagTokens = trim(implode(' ', array_map($marketplaceTagKey, $itemTags)));
            $cardTooltip = trim((string) ($profile['pitch'] ?? $skill['summary'] ?? ''));
            $cardTooltip = preg_replace('/\s+/', ' ', $cardTooltip) ?? $cardTooltip;
            $cardSummary = $cardTooltip !== '' ? $cardTooltip : 'Open the setup path and readiness details for this module.';
            if ($isProtectedDemoMarketplace) {
                $cardSummary = str_ireplace(
                    ['Set up', 'setup path', 'setup', 'Install ', 'install ', 'API key'],
                    ['Configured', 'capability view', 'configuration', 'Enable ', 'enable ', 'AI route'],
                    $cardSummary
                );
                $cardTooltip = str_ireplace(
                    ['Set up', 'setup path', 'setup', 'Install ', 'install ', 'API key'],
                    ['Configured', 'capability view', 'configuration', 'Enable ', 'enable ', 'AI route'],
                    $cardTooltip
                );
            }
            if (strlen($cardSummary) > 150) {
                $cardSummary = rtrim(substr($cardSummary, 0, 147)) . '...';
            }
            if (strlen($cardTooltip) > 190) {
                $cardTooltip = rtrim(substr($cardTooltip, 0, 187)) . '...';
            }
            $cardTooltipId = $cardTooltip !== ''
                ? 'marketplace-tooltip-' . (preg_replace('/[^a-z0-9_-]+/i', '-', $skillKey) ?: 'marketplace-card')
                : '';
            $cardVideoEmbed = $marketplaceVideoEmbed((string) ($profile['explainer_video_url'] ?? ''));
            $cardVideoType = (string) ($cardVideoEmbed['type'] ?? '');
            $cardVideoUrl = (string) ($cardVideoEmbed['url'] ?? '');
            $cardHasVideo = $cardVideoUrl !== '';
            $cardPreviewUrl = $cardVideoType === 'video' && $marketplaceCanPreviewVideo($cardVideoUrl) ? $cardVideoUrl : '';
            $cardOrientation = (string) ($profile['explainer_orientation'] ?? 'landscape') === 'portrait' ? 'portrait' : 'landscape';
            $cardVideoTitle = (string) ($skill['label'] ?? $skillKey) . ' explainer video';
            $cardReady = (bool) ($marketplaceItem['readiness']['ready'] ?? false);
            $cardReadinessStatus = (string) ($marketplaceItem['readiness']['status'] ?? '');
            $cardSendingReady = $cardReady && in_array($cardReadinessStatus, ['warning', 'sending_ready'], true);
            $cardGuidedDemoTarget = match ($skillKey) {
                WorkspaceSkillCatalogService::PLUGIN_EMAIL,
                WorkspaceSkillCatalogService::PLUGIN_WHATSAPP => 'module-email-whatsapp',
                WorkspaceSkillCatalogService::PLUGIN_CALENDAR_MEETINGS => 'module-calendar',
                WorkspaceSkillCatalogService::PLUGIN_FINANCE => 'module-finance',
                WorkspaceSkillCatalogService::SKILL_AI_COACH => 'module-ai-coach',
                default => '',
            };
            $cardStatusClass = '';
            $cardStatusText = 'Available';
            $cardLockedRequirement = 'Prerequisite required';
            if ($cardLocked) {
                if ($cardAccessState === WorkspaceMarketplaceAccessService::STATE_LOCKED_BY_PLAN) {
                    $cardLockedRequirement = 'Requires a paid subscription';
                } else {
                    $cardPendingRequirements = array_values((array) ($cardAccess['pending_requirements'] ?? []));
                    $cardDirectRequirement = (array) ($cardPendingRequirements[0] ?? []);
                    $cardDirectRequirementLabel = trim((string) ($cardDirectRequirement['module_label'] ?? ''));
                    if ($cardDirectRequirementLabel === '') {
                        $cardDirectRequirementLabel = trim((string) ($cardDirectRequirement['label'] ?? ''));
                        $cardDirectRequirementLabel = trim((string) preg_replace('/\s+(?:ready|installed)$/i', '', $cardDirectRequirementLabel));
                    }
                    if ($cardDirectRequirementLabel === '') {
                        $cardDirectRequirementLabel = trim((string) ($cardAccess['root_blocker_label'] ?? ''));
                    }
                    if ($cardDirectRequirementLabel !== '') {
                        $cardLockedRequirement = 'Requires ' . $cardDirectRequirementLabel;
                    }
                }
            }
            if ($cardDeferred && $isInstalled) {
                $cardStatusClass = 'is-needs-setup';
                $cardStatusText = 'Checking';
            } elseif ($canEditMarketplaceCatalog && $catalogStatus !== WorkspaceSkillCatalogService::CATALOG_STATUS_VISIBLE) {
                $cardStatusClass = 'is-muted';
                $cardStatusText = $catalogStatusLabel;
            } elseif ($cardLocked && $isInstalled) {
                $cardStatusClass = 'is-locked';
                $cardStatusText = 'Installed - locked';
            } elseif ($cardLocked) {
                $cardStatusClass = 'is-locked';
                $cardStatusText = 'Locked';
            } elseif ($isProtectedInstall) {
                $cardStatusClass = 'is-required';
                $cardStatusText = 'Required';
            } elseif ($isInstalled && !$cardReady) {
                $cardStatusClass = 'is-needs-setup';
                $cardStatusText = 'Needs setup';
            } elseif ($isInstalled && $cardSendingReady) {
                $cardStatusClass = 'is-warning';
                $cardStatusText = 'Sending ready';
            } elseif ($isInstalled) {
                $cardStatusClass = 'is-installed';
                $cardStatusText = 'Ready';
            }
            if ($isProtectedDemoMarketplace) {
                $cardStatusClass = 'is-installed';
                $cardStatusText = 'Ready';
            }
            $cardShowReadiness = $isInstalled || $cardLocked;
            $cardReadinessClass = $cardLocked || !$cardReady || $cardSendingReady ? 'is-warning' : 'is-good';
            $cardReadinessText = $cardDeferred ? 'Checking setup...' : ($cardLocked ? $cardLockedRequirement : ($cardReady ? ($cardSendingReady ? 'Inbox setup optional' : 'Ready to run') : 'Setup path open'));
            if ($isProtectedDemoMarketplace) {
                $cardShowReadiness = true;
                $cardReadinessClass = 'is-good';
                $cardReadinessText = 'Ready to run';
            }
            ?>
            <article class="marketplace-card" data-marketplace-type="<?php echo htmlspecialchars($type); ?>" data-marketplace-installed="<?php echo $isInstalled ? '1' : '0'; ?>" data-marketplace-access-state="<?php echo htmlspecialchars($cardAccessState); ?>" data-marketplace-access-locked="<?php echo $cardLocked ? '1' : '0'; ?>" data-marketplace-sort-bucket="<?php echo (int) ($marketplaceItem['sort_bucket'] ?? 30); ?>" data-marketplace-recommendation-rank="<?php echo (int) ($marketplaceItem['recommendation_rank'] ?? 0); ?>" data-marketplace-tags=" <?php echo htmlspecialchars($tagTokens); ?> " data-marketplace-search="<?php echo htmlspecialchars((string) $marketplaceItem['search_text']); ?>"<?php echo $cardGuidedDemoTarget !== '' ? ' data-guided-demo-target="' . htmlspecialchars($cardGuidedDemoTarget) . '"' : ''; ?>>
                <a class="marketplace-card-button marketplace-recommendation-cta" href="workspace_skills.php?module=<?php echo urlencode($skillKey); ?>" data-marketplace-skill-key="<?php echo htmlspecialchars($skillKey); ?>" data-marketplace-access-locked="<?php echo $cardLocked ? '1' : '0'; ?>" data-marketplace-setup-label="<?php echo htmlspecialchars((string) ($skill['label'] ?? $skillKey)); ?>"<?php echo $cardTooltipId !== '' ? ' aria-describedby="' . htmlspecialchars($cardTooltipId) . '"' : ''; ?>>
                    <span class="marketplace-thumb is-<?php echo htmlspecialchars($cardOrientation); ?>" data-marketplace-card-media>
                        <img src="<?php echo htmlspecialchars((string) $marketplaceItem['thumbnail_url']); ?>" alt="<?php echo htmlspecialchars((string) ($profile['thumbnail_alt'] ?? (($skill['label'] ?? $skillKey) . ' thumbnail'))); ?>" loading="lazy" onerror="this.onerror=null;this.src='<?php echo htmlspecialchars($marketplaceDefaultThumbnail, ENT_QUOTES); ?>';">
                        <?php if ($cardPreviewUrl !== ''): ?>
                            <video muted loop playsinline preload="metadata" poster="<?php echo htmlspecialchars((string) $marketplaceItem['thumbnail_url']); ?>" data-marketplace-card-video aria-hidden="true">
                                <source src="<?php echo htmlspecialchars($cardPreviewUrl); ?>">
                            </video>
                        <?php endif; ?>
                        <span class="marketplace-status <?php echo htmlspecialchars($cardStatusClass); ?>" data-marketplace-card-status><?php echo htmlspecialchars($cardStatusText); ?></span>
                    </span>
                    <span class="marketplace-card-content">
                        <span class="marketplace-card-status-row">
                            <span class="marketplace-card-title"><?php echo htmlspecialchars((string) ($skill['label'] ?? $skillKey)); ?></span>
                        </span>
                        <span class="marketplace-card-meta"><?php echo htmlspecialchars($type); ?> / <?php echo htmlspecialchars((string) ($skill['category'] ?? 'module')); ?></span>
                        <span class="marketplace-card-summary"><?php echo htmlspecialchars($cardSummary); ?></span>
                        <span class="marketplace-card-readiness <?php echo htmlspecialchars($cardReadinessClass); ?>" data-marketplace-card-readiness <?php echo $cardShowReadiness ? '' : 'hidden'; ?>><?php echo htmlspecialchars($cardReadinessText); ?></span>
                    </span>
                    <?php if ($cardTooltipId !== ''): ?>
                        <span id="<?php echo htmlspecialchars($cardTooltipId); ?>" class="marketplace-plugin-tooltip" role="tooltip"><?php echo htmlspecialchars($cardTooltip); ?></span>
                    <?php endif; ?>
                </a>
                <?php if ($cardHasVideo): ?>
                    <button
                        type="button"
                        class="marketplace-card-play-badge"
                        data-marketplace-card-video-open
                        data-marketplace-video-type="<?php echo htmlspecialchars($cardVideoType); ?>"
                        data-marketplace-video-url="<?php echo htmlspecialchars($cardVideoUrl); ?>"
                        data-marketplace-video-poster="<?php echo htmlspecialchars((string) $marketplaceItem['thumbnail_url']); ?>"
                        data-marketplace-video-title="<?php echo htmlspecialchars($cardVideoTitle); ?>"
                        aria-label="Play <?php echo htmlspecialchars($cardVideoTitle); ?>">
                        <span class="marketplace-card-play-icon" aria-hidden="true"></span>
                    </button>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>

    <div class="marketplace-card-video-modal" data-marketplace-card-video-modal role="dialog" aria-modal="true" aria-labelledby="marketplace-card-video-title" hidden>
        <div class="marketplace-card-video-dialog" role="document">
            <div class="marketplace-card-video-head">
                <h3 id="marketplace-card-video-title">Explainer video</h3>
                <button class="marketplace-card-video-close" type="button" data-marketplace-card-video-close aria-label="Close explainer video"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
            </div>
            <div class="marketplace-card-video-frame" data-marketplace-card-video-frame></div>
        </div>
    </div>

    <div class="marketplace-access-modal" data-marketplace-access-modal role="dialog" aria-modal="true" aria-labelledby="marketplace-access-title" hidden>
        <div class="marketplace-access-dialog" role="document">
            <div class="marketplace-access-head">
                <div>
                    <p class="marketplace-eyebrow">Workspace readiness</p>
                    <h3 id="marketplace-access-title">Module locked</h3>
                </div>
                <button class="marketplace-card-video-close" type="button" data-marketplace-access-close aria-label="Close access requirements"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
            </div>
            <div class="marketplace-access-body">
                <p class="marketplace-access-message" data-marketplace-access-message></p>
                <section class="marketplace-access-section" data-marketplace-access-completed-section hidden>
                    <h4>Completed</h4>
                    <ul data-marketplace-access-completed></ul>
                </section>
                <section class="marketplace-access-section" data-marketplace-access-pending-section>
                    <h4>Pending</h4>
                    <ul data-marketplace-access-pending></ul>
                </section>
                <p class="marketplace-access-root" data-marketplace-access-root>Finish the required setup first to unlock this module.</p>
                <p class="marketplace-access-why" data-marketplace-access-why></p>
            </div>
            <div class="marketplace-access-actions">
                <a class="btn-premium-primary" href="workspace_skills.php" data-marketplace-access-action style="text-decoration:none;">Open setup</a>
                <button class="btn-premium-secondary" type="button" data-marketplace-access-close>Close</button>
            </div>
        </div>
    </div>

    <div id="marketplace-empty" class="marketplace-empty">No marketplace items match this view.</div>
    </div>

    <script type="application/json" id="marketplace-detail-data"><?php echo json_encode($marketplaceDetailData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES); ?></script>
    <script type="application/json" id="marketplace-access-data"><?php echo json_encode($marketplaceAccessData, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES); ?></script>

    <?php $marketplaceTemplateItems = $selectedMarketplaceItem !== null ? [$selectedMarketplaceItem] : []; ?>
    <?php foreach ($marketplaceTemplateItems as $marketplaceItem): ?>
        <?php
        $skill = (array) $marketplaceItem['module'];
        $skillKey = (string) $marketplaceItem['key'];
        $type = (string) $marketplaceItem['type'];
        $isInstalled = !empty($marketplaceItem['is_installed']);
        $isProtectedInstall = $marketplaceIsProtectedInstall($skill);
        $catalogStatus = (string) ($skill['catalog_status'] ?? WorkspaceSkillCatalogService::CATALOG_STATUS_VISIBLE);
        $catalogStatusLabel = $marketplaceCatalogStatusLabel($catalogStatus);
        $catalogInstallable = $catalogStatus === WorkspaceSkillCatalogService::CATALOG_STATUS_VISIBLE;
        $profile = (array) $marketplaceItem['profile'];
        $capabilities = (array) $marketplaceItem['capabilities'];
        $readiness = (array) $marketplaceItem['readiness'];
        $ready = (bool) ($readiness['ready'] ?? false);
        $status = (string) ($readiness['status'] ?? '');
        $setupUrl = (string) $marketplaceItem['setup_url'];
        $videoUrl = (string) ($profile['explainer_video_url'] ?? '');
        $orientation = (string) ($profile['explainer_orientation'] ?? 'landscape') === 'portrait' ? 'portrait' : 'landscape';
        $recommendationsList = $marketplaceList((array) ($profile['recommendations'] ?? []));
        $prerequisitesList = $marketplaceList((array) ($profile['prerequisites'] ?? $skill['dependencies'] ?? []));
        $setupGuideList = $marketplaceList((array) ($profile['setup_guide'] ?? $skill['setup_steps'] ?? []));
        $assistantType = $skillKey === WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT ? 'whatsapp' : 'email';
        $assistantConfigRow = (array) ($marketplaceAssistantConfigByType[$assistantType] ?? []);
        $assistantSettings = (array) ($assistantConfigRow['settings'] ?? []);
        $assistantEnabled = !empty($assistantConfigRow['enabled']);
        $healthKey = $skillKey === WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT ? 'whatsapp' : 'assistant_email';
        $pluginHealth = (array) ($marketplaceChannelHealth[$healthKey] ?? []);
        $pluginPerformance = (array) ($marketplacePluginPerformanceByKey[$skillKey] ?? []);
        $pluginRuntime = (array) ($pluginPerformance['runtime'] ?? []);
        $drawerTabs = [
            'overview' => 'Overview',
            'install' => $isInstalled ? 'Manage' : 'Install',
        ];
        if ($type === 'plugin') {
            $drawerTabs['setup'] = 'Setup';
            $drawerTabs['health'] = 'Test & Health';
        }
        if ($type === 'plugin' && $canViewMarketplacePerformance) {
            $drawerTabs['performance'] = 'Performance';
        }
        $drawerSetupTabs = marketplaceSetupTabsForModule($skillKey);
        $drawerSetupTab = marketplaceNormalizeSetupTab($skillKey, '');
        $drawerOverviewSetupLinks = [];
        $drawerAddSetupLink = static function (array &$links, string $label, string $href, string $variant = 'secondary'): void {
            if ($label === '' || $href === '') {
                return;
            }
            foreach ($links as $link) {
                if (($link['href'] ?? '') === $href || ($link['label'] ?? '') === $label) {
                    return;
                }
            }
            $links[] = ['label' => $label, 'href' => $href, 'variant' => $variant === 'primary' ? 'primary' : 'secondary'];
        };
        if ($type === 'plugin') {
            $drawerAddSetupLink($drawerOverviewSetupLinks, 'Open setup', marketplaceSetupTabUrl($skillKey, $drawerSetupTab), 'primary');
        } elseif ($setupUrl !== '') {
            $drawerAddSetupLink($drawerOverviewSetupLinks, 'Open setup', $setupUrl, 'primary');
        }
        switch ($skillKey) {
            case WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT:
                $drawerAddSetupLink($drawerOverviewSetupLinks, 'Tests', marketplaceSetupTabUrl($skillKey, 'tests'));
                $drawerAddSetupLink($drawerOverviewSetupLinks, 'Assistant runs', 'email_assistant_runs.php');
                $drawerAddSetupLink($drawerOverviewSetupLinks, 'Capabilities', 'email_assistant_capabilities.php');
                break;
            case WorkspaceSkillCatalogService::PLUGIN_WHATSAPP:
                $drawerAddSetupLink($drawerOverviewSetupLinks, 'WhatsApp Messages', 'whatsapp_messages.php');
                $drawerAddSetupLink($drawerOverviewSetupLinks, 'Send Message', 'whatsapp_compose.php');
                $drawerAddSetupLink($drawerOverviewSetupLinks, 'Bulk WhatsApp', 'bulk_whatsapp.php');
                break;
            case WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT:
                $drawerAddSetupLink($drawerOverviewSetupLinks, 'Tests', marketplaceSetupTabUrl($skillKey, 'tests'));
                break;
            case WorkspaceSkillCatalogService::SKILL_AI_COACH:
                $drawerAddSetupLink($drawerOverviewSetupLinks, 'Workspace readiness', marketplaceSetupTabUrl($skillKey, 'workspace_readiness'));
                $drawerAddSetupLink($drawerOverviewSetupLinks, 'Personal Strategy', marketplaceSetupTabUrl($skillKey, 'my_brief'));
                break;
            case WorkspaceSkillCatalogService::PLUGIN_AI_API:
                $drawerAddSetupLink($drawerOverviewSetupLinks, 'Usage', marketplaceSetupTabUrl($skillKey, 'usage'));
                break;
        }
        ?>
        <template class="marketplace-template" id="marketplace-detail-template-<?php echo htmlspecialchars($skillKey); ?>">
            <div class="marketplace-detail-layout">
                <div class="marketplace-detail-media <?php echo $orientation === 'portrait' ? 'is-portrait' : 'is-landscape'; ?>">
                    <img src="<?php echo htmlspecialchars((string) $marketplaceItem['thumbnail_url']); ?>" alt="<?php echo htmlspecialchars((string) ($profile['thumbnail_alt'] ?? (($skill['label'] ?? $skillKey) . ' thumbnail'))); ?>">
                    <?php if ($videoUrl !== ''): ?>
                        <?php echo VideoBrandOverlayUi::frame(
                            '<video controls preload="metadata" poster="' . htmlspecialchars((string) $marketplaceItem['thumbnail_url'], ENT_QUOTES, 'UTF-8') . '">'
                            . '<source src="' . htmlspecialchars($videoUrl, ENT_QUOTES, 'UTF-8') . '">'
                            . '</video>'
                        ); ?>
                    <?php else: ?>
                        <div class="marketplace-video-placeholder">
                            <strong><?php echo htmlspecialchars((string) ($skill['label'] ?? $skillKey)); ?> explainer</strong>
                            <span><?php echo $orientation === 'portrait' ? 'Portrait' : 'Landscape'; ?> explainer slot. Add a video URL in the catalog metadata when a walkthrough is ready.</span>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="marketplace-drawer-tabs" role="tablist" aria-label="<?php echo htmlspecialchars((string) ($skill['label'] ?? $skillKey)); ?> marketplace sections">
                    <?php foreach ($drawerTabs as $panelKey => $panelLabel): ?>
                        <button type="button" class="marketplace-drawer-tab" data-marketplace-drawer-tab="<?php echo htmlspecialchars($panelKey); ?>" aria-selected="<?php echo $panelKey === 'overview' ? 'true' : 'false'; ?>"><?php echo htmlspecialchars($panelLabel); ?></button>
                    <?php endforeach; ?>
                </div>

                <div class="marketplace-drawer-panel" data-marketplace-drawer-panel="overview">
                <section class="marketplace-detail-section">
                    <h3>Why you need it</h3>
                    <p><?php echo htmlspecialchars((string) ($profile['pitch'] ?? $skill['summary'] ?? '')); ?></p>
                </section>

                <section class="marketplace-detail-section">
                    <h3>Description</h3>
                    <p><?php echo htmlspecialchars((string) ($skill['summary'] ?? '')); ?></p>
                </section>

                <?php if (!empty($recommendationsList)): ?>
                    <section class="marketplace-detail-section">
                        <h3>Recommendations</h3>
                        <ul>
                            <?php foreach ($recommendationsList as $item): ?>
                                <li><?php echo htmlspecialchars($item); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </section>
                <?php endif; ?>

                <?php if (!empty($prerequisitesList)): ?>
                    <section class="marketplace-detail-section">
                        <h3>Prerequisites</h3>
                        <ul>
                            <?php foreach ($prerequisitesList as $item): ?>
                                <li><?php echo htmlspecialchars($item); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </section>
                <?php endif; ?>

                <?php if (!empty($setupGuideList)): ?>
                    <section class="marketplace-detail-section">
                        <h3>Install and setup</h3>
                        <ol>
                            <?php foreach ($setupGuideList as $item): ?>
                                <li><?php echo htmlspecialchars($item); ?></li>
                            <?php endforeach; ?>
                        </ol>
                    </section>
                <?php endif; ?>

                <?php if ($type === 'plugin' && $isInstalled && !empty($readiness)): ?>
                    <section class="marketplace-detail-section" style="border-color:<?php echo $ready ? '#bbf7d0' : '#fed7aa'; ?>;background:<?php echo $ready ? '#f0fdf4' : '#fff7ed'; ?>;">
                        <h3><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $status ?: ($ready ? 'ready' : 'needs setup')))); ?></h3>
                        <p><?php echo htmlspecialchars((string) ($readiness['message'] ?? 'Open setup to finish configuration.')); ?></p>
                    </section>
                <?php endif; ?>

                <?php if ($drawerOverviewSetupLinks !== []): ?>
                    <section class="marketplace-detail-section marketplace-overview-setup-compact">
                        <div class="marketplace-setup-checklist-head">
                            <div>
                                <strong>Setup status</strong>
                                <span><?php echo htmlspecialchars((string) ($readiness['message'] ?? ($ready ? 'Setup is ready for this workspace.' : 'Open setup to finish configuration.'))); ?></span>
                            </div>
                            <span class="marketplace-status <?php echo $ready ? 'is-installed' : 'is-needs-setup'; ?>"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $status ?: ($ready ? 'ready' : 'needs setup')))); ?></span>
                        </div>
                        <div class="marketplace-setup-checks-compact">
                            <span class="marketplace-setup-check-pill <?php echo $isInstalled ? 'is-ready' : 'is-needed'; ?>"><?php echo $isInstalled ? 'Installed' : 'Not installed'; ?></span>
                            <span class="marketplace-setup-check-pill <?php echo $ready ? 'is-ready' : 'is-needed'; ?>"><?php echo $ready ? 'Ready' : 'Needs setup'; ?></span>
                            <?php if (array_key_exists('outbound_ready', $readiness)): ?>
                                <span class="marketplace-setup-check-pill <?php echo !empty($readiness['outbound_ready']) ? 'is-ready' : 'is-needed'; ?>"><?php echo !empty($readiness['outbound_ready']) ? 'Outbound ready' : 'Outbound needs setup'; ?></span>
                            <?php endif; ?>
                            <?php if (array_key_exists('inbound_ready', $readiness)): ?>
                                <span class="marketplace-setup-check-pill <?php echo !empty($readiness['inbound_ready']) ? 'is-ready' : 'is-needed'; ?>"><?php echo !empty($readiness['inbound_ready']) ? 'Inbound ready' : 'Inbound optional'; ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="marketplace-detail-actions marketplace-overview-setup-actions">
                            <?php foreach ($drawerOverviewSetupLinks as $link): ?>
                                <a class="<?php echo (string) ($link['variant'] ?? 'secondary') === 'primary' ? 'btn-premium-primary' : 'btn-premium-secondary'; ?>" href="<?php echo htmlspecialchars((string) ($link['href'] ?? '#')); ?>" style="text-decoration:none;"><?php echo htmlspecialchars((string) ($link['label'] ?? 'Open')); ?></a>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endif; ?>

                <?php if (!empty($capabilities)): ?>
                    <div class="marketplace-chip-row">
                        <?php foreach ($capabilities as $capability): ?>
                            <span class="marketplace-chip"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) $capability))); ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                </div>

                <?php if ($type === 'plugin'): ?>
                    <div class="marketplace-drawer-panel" data-marketplace-drawer-panel="setup" hidden>
                        <?php if (in_array($skillKey, [
                            WorkspaceSkillCatalogService::PLUGIN_EMAIL,
                            WorkspaceSkillCatalogService::PLUGIN_WHATSAPP,
                        ], true)): ?>
                            <?php $communicationDrawerSetup = $marketplaceCommunicationSetupContext(); ?>
                            <section class="marketplace-detail-section">
                                <h3><?php echo $skillKey === WorkspaceSkillCatalogService::PLUGIN_WHATSAPP ? 'WhatsApp settings' : 'Email settings'; ?></h3>
                                <div class="marketplace-communication-summary">
                                    <?php
                                        $drawerChannels = $skillKey === WorkspaceSkillCatalogService::PLUGIN_WHATSAPP
                                            ? ['whatsapp' => 'WhatsApp']
                                            : ['outreach_email' => 'Outreach', 'nurture_email' => 'Nurture'];
                                    ?>
                                    <?php foreach ($drawerChannels as $drawerChannelKey => $drawerLabel): ?>
                                        <?php $drawerHealth = (array) (($communicationDrawerSetup['health'] ?? [])[$drawerChannelKey] ?? []); ?>
                                        <?php $drawerTargetModule = $drawerChannelKey === 'whatsapp' ? WorkspaceSkillCatalogService::PLUGIN_WHATSAPP : WorkspaceSkillCatalogService::PLUGIN_EMAIL; ?>
                                        <?php $drawerSetupTab = $drawerChannelKey === 'whatsapp' ? 'manual' : $drawerChannelKey; ?>
                                        <a class="marketplace-status <?php echo marketplaceCommunicationStatusClass($drawerHealth); ?>" href="workspace_skills.php?module=<?php echo rawurlencode($drawerTargetModule); ?>&setup_tab=<?php echo rawurlencode($drawerSetupTab); ?>#setup">
                                            <?php echo htmlspecialchars($drawerLabel . ': ' . (string) ($drawerHealth['label'] ?? 'Not connected')); ?>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                                <div class="marketplace-detail-actions" style="margin-top:.85rem;">
                                    <?php if ($skillKey !== WorkspaceSkillCatalogService::PLUGIN_WHATSAPP): ?>
                                        <a class="btn-premium-primary" href="workspace_skills.php?module=<?php echo rawurlencode(WorkspaceSkillCatalogService::PLUGIN_EMAIL); ?>&setup_tab=outreach_email#setup" style="text-decoration:none;">Open email setup</a>
                                    <?php endif; ?>
                                    <?php if ($skillKey !== WorkspaceSkillCatalogService::PLUGIN_EMAIL): ?>
                                        <a class="btn-premium-secondary" href="workspace_skills.php?module=<?php echo rawurlencode(WorkspaceSkillCatalogService::PLUGIN_WHATSAPP); ?>&setup_tab=manual#setup" style="text-decoration:none;">Open WhatsApp setup</a>
                                    <?php endif; ?>
                                </div>
                            </section>
                        <?php elseif (!$isInstalled): ?>
                            <section class="marketplace-detail-section">
                                <h3>Install first</h3>
                                <p>Install <?php echo htmlspecialchars((string) ($skill['label'] ?? $skillKey)); ?> to unlock marketplace setup for this workspace.</p>
                            </section>
                        <?php elseif ($skillKey === WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT): ?>
                            <section class="marketplace-detail-section">
                                <h3>Email Assistant setup</h3>
                                <p>Complete Email Assistant setup from the Marketplace plugin page. The full setup now lives there with identity, outbound, inbound, behavior, tests, activity, and advanced sections.</p>
                                <div class="marketplace-detail-actions">
                                    <a class="btn-premium-primary" href="workspace_skills.php?module=<?php echo rawurlencode(WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT); ?>&setup_tab=identity#setup" style="text-decoration:none;">Open full setup</a>
                                    <a class="btn-premium-secondary" href="workspace_skills.php?module=<?php echo rawurlencode(WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT); ?>&setup_tab=tests#setup" style="text-decoration:none;">Open tests</a>
                                </div>
                            </section>
                            <?php if (false): ?>
                            <form method="POST" class="marketplace-setup-form">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                                <input type="hidden" name="skill_key" value="<?php echo htmlspecialchars($skillKey); ?>">
                                <section class="marketplace-detail-section marketplace-setup-form">
                                    <h3>Email Assistant setup</h3>
                                    <label class="marketplace-form-check"><input type="checkbox" name="email_assistant_enabled" <?php echo $assistantEnabled ? 'checked' : ''; ?>> Enable Email Assistant</label>
                                    <div class="marketplace-form-grid">
                                        <label class="marketplace-form-field">System email <input type="email" name="email_assistant_system_email" value="<?php echo htmlspecialchars((string) ($assistantSettings['system_email'] ?? '')); ?>" placeholder="assistant@example.com"></label>
                                        <label class="marketplace-form-field">From email <input type="email" name="email_assistant_from_email" value="<?php echo htmlspecialchars((string) ($assistantSettings['from_email'] ?? '')); ?>" placeholder="assistant@example.com"></label>
                                        <label class="marketplace-form-field">From name <input type="text" name="email_assistant_from_name" value="<?php echo htmlspecialchars((string) ($assistantSettings['from_name'] ?? '')); ?>" placeholder="Workspace Assistant"></label>
                                        <label class="marketplace-form-field">Tone
                                            <select name="email_assistant_default_tone">
                                                <?php foreach (['professional' => 'Professional', 'warm' => 'Warm', 'direct' => 'Direct'] as $toneValue => $toneLabel): ?>
                                                    <option value="<?php echo htmlspecialchars($toneValue); ?>" <?php echo (string) ($assistantSettings['default_tone'] ?? 'professional') === $toneValue ? 'selected' : ''; ?>><?php echo htmlspecialchars($toneLabel); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                    </div>
                                </section>
                                <section class="marketplace-detail-section marketplace-setup-form">
                                    <h3>SMTP sending</h3>
                                    <div class="marketplace-form-grid">
                                        <label class="marketplace-form-field">SMTP host <input type="text" name="email_assistant_smtp_host" value="<?php echo htmlspecialchars((string) ($assistantSettings['smtp_host'] ?? '')); ?>" placeholder="smtp.example.com"></label>
                                        <label class="marketplace-form-field">SMTP port <input type="number" name="email_assistant_smtp_port" value="<?php echo htmlspecialchars((string) ($assistantSettings['smtp_port'] ?? '587')); ?>" min="1" max="65535"></label>
                                        <label class="marketplace-form-field">SMTP username <input type="text" name="email_assistant_smtp_user" value="<?php echo htmlspecialchars((string) (($assistantSettings['smtp_username'] ?? '') ?: ($assistantSettings['from_email'] ?? ''))); ?>" autocomplete="username"></label>
                                        <label class="marketplace-form-field">SMTP password <input type="password" name="email_assistant_smtp_pass" value="" autocomplete="new-password" placeholder="<?php echo array_key_exists('smtp_password', $assistantSettings) ? 'Saved password' : 'App password'; ?>"></label>
                                        <label class="marketplace-form-field">Encryption
                                            <select name="email_assistant_smtp_encryption">
                                                <?php foreach (['' => 'Default from port', 'tls' => 'TLS', 'ssl' => 'SSL'] as $encValue => $encLabel): ?>
                                                    <option value="<?php echo htmlspecialchars($encValue); ?>" <?php echo (string) ($assistantSettings['smtp_encryption'] ?? 'tls') === $encValue ? 'selected' : ''; ?>><?php echo htmlspecialchars($encLabel); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>
                                    </div>
                                </section>
                                <section class="marketplace-detail-section marketplace-setup-form">
                                    <h3>Inbound and controls</h3>
                                    <label class="marketplace-form-check"><input type="checkbox" name="email_assistant_imap_enabled" <?php echo !empty($assistantSettings['imap_enabled']) ? 'checked' : ''; ?>> Enable inbound IMAP</label>
                                    <div class="marketplace-form-grid">
                                        <label class="marketplace-form-field">IMAP host <input type="text" name="email_assistant_imap_host" value="<?php echo htmlspecialchars((string) ($assistantSettings['imap_host'] ?? '')); ?>" placeholder="imap.example.com"></label>
                                        <label class="marketplace-form-field">IMAP port <input type="number" name="email_assistant_imap_port" value="<?php echo htmlspecialchars((string) ($assistantSettings['imap_port'] ?? '993')); ?>" min="1" max="65535"></label>
                                        <label class="marketplace-form-field">IMAP username <input type="text" name="email_assistant_imap_user" value="<?php echo htmlspecialchars((string) (($assistantSettings['imap_username'] ?? '') ?: (!empty($assistantSettings['imap_enabled']) ? ($assistantSettings['from_email'] ?? '') : ''))); ?>" autocomplete="username"></label>
                                        <label class="marketplace-form-field">IMAP password <input type="password" name="email_assistant_imap_pass" value="" autocomplete="new-password" placeholder="<?php echo array_key_exists('imap_password', $assistantSettings) ? 'Saved password' : 'App password'; ?>"></label>
                                        <label class="marketplace-form-field">Protocol <input type="text" name="email_assistant_imap_protocol" value="<?php echo htmlspecialchars((string) ($assistantSettings['imap_protocol'] ?? 'imap')); ?>"></label>
                                        <label class="marketplace-form-field">Encryption <input type="text" name="email_assistant_imap_encryption" value="<?php echo htmlspecialchars((string) ($assistantSettings['imap_encryption'] ?? 'ssl')); ?>"></label>
                                        <label class="marketplace-form-field">Folder <input type="text" name="email_assistant_imap_folder" value="<?php echo htmlspecialchars((string) ($assistantSettings['imap_folder'] ?? 'INBOX')); ?>"></label>
                                        <label class="marketplace-form-field">Allowed senders <textarea name="email_assistant_allowed_senders" rows="3" placeholder="One email or domain per line"><?php echo htmlspecialchars((string) ($assistantSettings['allowed_senders'] ?? '')); ?></textarea></label>
                                        <label class="marketplace-form-field">Thread context <input type="number" name="email_assistant_thread_context_window" value="<?php echo htmlspecialchars((string) ($assistantSettings['thread_context_window'] ?? '8')); ?>" min="3" max="20"></label>
                                        <label class="marketplace-form-field">Minimum confidence <input type="number" name="email_assistant_min_confidence" value="<?php echo htmlspecialchars((string) ($assistantSettings['min_confidence'] ?? '0.65')); ?>" min="0" max="1" step="0.01"></label>
                                        <label class="marketplace-form-field">Send confidence <input type="number" name="email_assistant_min_send_confidence" value="<?php echo htmlspecialchars((string) ($assistantSettings['min_send_confidence'] ?? '0.8')); ?>" min="0" max="1" step="0.01"></label>
                                    </div>
                                    <div class="marketplace-chip-row">
                                        <?php foreach (['email_assistant_qa_enabled' => ['qa_enabled', 'Q&A'], 'email_assistant_instructions_enabled' => ['instructions_enabled', 'Inbound instructions'], 'email_assistant_customer_thread_enabled' => ['customer_thread_enabled', 'Customer threads'], 'email_assistant_customer_send_enabled' => ['customer_send_enabled', 'Customer send'], 'email_assistant_allow_clarifying_questions' => ['allow_clarifying_questions', 'Clarifying questions'], 'email_assistant_activity_logging' => ['activity_logging', 'Activity logging']] as $inputName => [$settingKey, $labelText]): ?>
                                            <label class="marketplace-form-check"><input type="checkbox" name="<?php echo htmlspecialchars($inputName); ?>" <?php echo !empty($assistantSettings[$settingKey]) ? 'checked' : ''; ?>> <?php echo htmlspecialchars($labelText); ?></label>
                                        <?php endforeach; ?>
                                    </div>
                                </section>
                                <div class="marketplace-detail-actions">
                                    <button class="btn-premium-primary" type="submit" name="skill_action" value="save_email_assistant_setup">Save Email Assistant setup</button>
                                </div>
                            </form>
                            <?php endif; ?>
                        <?php elseif ($skillKey === WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT): ?>
                            <section class="marketplace-detail-section">
                                <h3>WhatsApp Assistant setup</h3>
                                <p>Complete WhatsApp Assistant setup from the Marketplace plugin page. The full setup now lives there with identity, session, tests, activity, and advanced sections.</p>
                                <div class="marketplace-detail-actions">
                                    <a class="btn-premium-primary" href="workspace_skills.php?module=<?php echo rawurlencode(WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT); ?>&setup_tab=identity#setup" style="text-decoration:none;">Open full setup</a>
                                    <a class="btn-premium-secondary" href="workspace_skills.php?module=<?php echo rawurlencode(WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT); ?>&setup_tab=tests#setup" style="text-decoration:none;">Open tests</a>
                                </div>
                            </section>
                        <?php endif; ?>
                    </div>

                    <div class="marketplace-drawer-panel" data-marketplace-drawer-panel="health" hidden>
                        <section class="marketplace-detail-section">
                            <h3>Marketplace health check</h3>
                            <p><?php echo htmlspecialchars((string) ($pluginHealth['label'] ?? ($readiness['message'] ?? 'Install and configure this plugin to see readiness.'))); ?></p>
                            <div class="marketplace-health-grid" style="margin-top:.75rem;">
                                <div class="marketplace-metric-card"><strong><?php echo htmlspecialchars((string) ($pluginHealth['status'] ?? ($status ?: 'unknown'))); ?></strong><span>Status</span></div>
                                <div class="marketplace-metric-card"><strong><?php echo !empty($readiness['outbound_ready']) ? 'Ready' : 'Needs setup'; ?></strong><span>Outbound</span></div>
                                <div class="marketplace-metric-card"><strong><?php echo !empty($readiness['inbound_ready']) ? 'Ready' : 'Optional'; ?></strong><span>Inbound</span></div>
                            </div>
                            <?php if (!empty($pluginHealth['badges'])): ?>
                                <div class="marketplace-chip-row" style="margin-top:.75rem;">
                                    <?php foreach ((array) $pluginHealth['badges'] as $badge): ?>
                                        <span class="marketplace-chip"><?php echo htmlspecialchars((string) $badge); ?></span>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($pluginHealth['issues']) || !empty($pluginHealth['actions'])): ?>
                                <details class="marketplace-secondary-details" style="margin-top:.75rem;">
                                    <summary>Issues and next actions</summary>
                                    <?php foreach (array_merge((array) ($pluginHealth['issues'] ?? []), (array) ($pluginHealth['actions'] ?? [])) as $healthLine): ?>
                                        <div style="margin-top:.35rem;"><?php echo htmlspecialchars((string) $healthLine); ?></div>
                                    <?php endforeach; ?>
                                </details>
                            <?php endif; ?>
                            <form method="POST" class="marketplace-detail-actions" style="margin-top:.85rem;">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                                <input type="hidden" name="skill_key" value="<?php echo htmlspecialchars($skillKey); ?>">
                                <?php if ($skillKey === WorkspaceSkillCatalogService::PLUGIN_EMAIL_ASSISTANT): ?>
                                    <button class="btn-premium-primary" type="submit" name="skill_action" value="send_email_assistant_test_digest" <?php echo $isInstalled ? '' : 'disabled'; ?>>Send test digest</button>
                                <?php elseif ($skillKey === WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT): ?>
                                    <button class="btn-premium-primary" type="submit" name="skill_action" value="send_whatsapp_assistant_test_digest" <?php echo $isInstalled ? '' : 'disabled'; ?>>Send test digest</button>
                                <?php endif; ?>
                                <button class="btn-premium-secondary" type="submit" name="skill_action" value="refresh_plugin_health">Refresh health</button>
                            </form>
                        </section>
                    </div>

                    <?php if ($canViewMarketplacePerformance): ?>
                        <div class="marketplace-drawer-panel" data-marketplace-drawer-panel="performance" hidden>
                            <section class="marketplace-detail-section">
                                <h3>Superadmin performance</h3>
                                <?php $pluginMetrics = (array) ($pluginPerformance['metrics'] ?? []); ?>
                                <div class="marketplace-performance-grid">
                                    <div class="marketplace-metric-card"><strong><?php echo (int) ($pluginMetrics['impressions'] ?? 0); ?></strong><span>Impressions 30d</span></div>
                                    <div class="marketplace-metric-card"><strong><?php echo (int) ($pluginMetrics['clicks'] ?? 0); ?></strong><span>Clicks 30d</span></div>
                                    <div class="marketplace-metric-card"><strong><?php echo (int) ($pluginMetrics['installs'] ?? 0); ?></strong><span>Installs 30d</span></div>
                                    <div class="marketplace-metric-card"><strong><?php echo (int) ($pluginMetrics['active_users'] ?? 0); ?></strong><span>Active users 30d</span></div>
                                    <div class="marketplace-metric-card"><strong><?php echo number_format(((float) ($pluginMetrics['click_through_rate'] ?? 0)) * 100, 1); ?>%</strong><span>Click-through rate</span></div>
                                    <div class="marketplace-metric-card"><strong><?php echo (int) ($pluginMetrics['setup_saves'] ?? 0); ?></strong><span>Setup saves 30d</span></div>
                                    <div class="marketplace-metric-card"><strong><?php echo htmlspecialchars((string) ($pluginPerformance['config_updated_at'] ?: 'Not saved')); ?></strong><span>Config updated</span></div>
                                    <?php foreach ($pluginRuntime as $runtimeLabel => $runtimeValue): ?>
                                        <div class="marketplace-metric-card"><strong><?php echo htmlspecialchars((string) ($runtimeValue !== '' ? $runtimeValue : 'None')); ?></strong><span><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) $runtimeLabel))); ?></span></div>
                                    <?php endforeach; ?>
                                </div>
                                <details class="marketplace-secondary-details" style="margin-top:.85rem;">
                                    <summary>Event breakdown</summary>
                                    <pre style="white-space:pre-wrap;margin:.65rem 0 0;color:#334155;font-size:.82rem;"><?php echo htmlspecialchars(json_encode([
                                        'skill_events_30d' => (array) ($pluginPerformance['skill_events'] ?? []),
                                        'recommendation_events_30d' => (array) ($pluginPerformance['recommendation_events'] ?? []),
                                        'setup_events_30d' => (array) ($pluginPerformance['setup_events'] ?? []),
                                    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre>
                                </details>
                            </section>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <div class="marketplace-drawer-panel" data-marketplace-drawer-panel="install" hidden>
                <section class="marketplace-detail-section">
                    <h3><?php echo $isProtectedInstall ? 'Required in every workspace' : ($isInstalled ? 'Installed in this workspace' : 'Add to this workspace'); ?></h3>
                    <p><?php echo !$canManage ? 'Your access profile can view this Marketplace item. Install and setup actions require Marketplace management access.' : ($isProtectedInstall ? 'This protected Marketplace plugin is always enabled and cannot be removed.' : ($isInstalled ? 'This item is installed in this workspace.' : 'Install this item to add it to the workspace.')); ?></p>
                </section>
                <?php if ($canManage): ?>
                    <div class="marketplace-detail-actions">
                        <form method="POST" style="margin:0;">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                            <input type="hidden" name="skill_key" value="<?php echo htmlspecialchars($skillKey); ?>">
                            <?php if ($activationBundleAttributionKey !== ''): ?>
                                <input type="hidden" name="activation_bundle_key" value="<?php echo htmlspecialchars($activationBundleAttributionKey); ?>">
                            <?php endif; ?>
                            <?php if ($isInstalled && $isProtectedInstall): ?>
                                <span class="btn-premium-secondary" style="cursor:default;">Required - always enabled</span>
                            <?php elseif ($isInstalled): ?>
                                <button class="btn-premium-secondary" type="submit" name="skill_action" value="uninstall">Remove from workspace</button>
                            <?php elseif (!$catalogInstallable): ?>
                                <span class="btn-premium-secondary" style="cursor:default;"><?php echo htmlspecialchars($catalogStatusLabel); ?> - install blocked</span>
                            <?php else: ?>
                                <button class="btn-premium-primary" type="submit" name="skill_action" value="install">Install <?php echo $type === 'plugin' ? 'plugin' : 'skill'; ?></button>
                            <?php endif; ?>
                        </form>
                        <?php if ($setupUrl !== '' && $type !== 'plugin'): ?>
                            <a class="btn-premium-secondary marketplace-recommendation-cta" href="<?php echo htmlspecialchars($setupUrl); ?>" data-marketplace-skill-key="<?php echo htmlspecialchars($skillKey); ?>" data-marketplace-setup-journey-open="<?php echo isset($setupJourneysBySkill[$skillKey]) ? '1' : '0'; ?>" data-marketplace-setup-label="<?php echo htmlspecialchars((string) ($skill['label'] ?? $skillKey)); ?>" style="text-decoration:none;">Open setup</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                </div>
            </div>
        </template>
    <?php endforeach; ?>
    </div>
    </section>

    <?php if ($canEditMarketplaceCatalog): ?>
        <section class="marketplace-section" data-marketplace-section="video-danger-zone" style="margin-top:1rem;border:1px solid #fecaca;background:#fffafa;border-radius:8px;padding:1rem;">
            <div style="display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;flex-wrap:wrap;margin-bottom:.85rem;">
                <div>
                    <h2 style="font-size:1.1rem;margin:0;color:#991b1b;">Delete all Marketplace videos</h2>
                    <p style="margin:.2rem 0 0;color:#7f1d1d;font-size:.92rem;">Superadmin danger zone. This clears every Marketplace video placement and deletes local video files from uploads/marketplace. Images and other uploads stay untouched.</p>
                </div>
                <span style="font-size:.78rem;font-weight:800;color:#991b1b;background:#fee2e2;border:1px solid #fecaca;border-radius:999px;padding:.25rem .55rem;">Global destructive action</span>
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:.65rem;margin-bottom:.85rem;">
                <div class="marketplace-metric-card"><strong><?php echo (int) ($marketplaceVideoInventory['local_video_files'] ?? 0); ?></strong><span>Local video files</span></div>
                <div class="marketplace-metric-card"><strong><?php echo htmlspecialchars($marketplaceFormatBytes((int) ($marketplaceVideoInventory['local_video_bytes'] ?? 0))); ?></strong><span>Local video size</span></div>
                <div class="marketplace-metric-card"><strong><?php echo (int) ($marketplaceVideoInventory['catalog_video_refs'] ?? 0); ?></strong><span>Catalog video refs</span></div>
                <div class="marketplace-metric-card"><strong><?php echo (int) ($marketplaceVideoInventory['bundle_video_refs'] ?? 0); ?></strong><span>Bundle video refs</span></div>
                <div class="marketplace-metric-card"><strong><?php echo (int) ($marketplaceVideoInventory['page_video_refs'] ?? 0); ?></strong><span>Page guide refs</span></div>
            </div>
            <?php if ((int) ($marketplaceVideoInventory['errors'] ?? 0) > 0): ?>
                <p style="margin:.35rem 0 .85rem;color:#991b1b;font-weight:700;">Video inventory has errors. Deletion will be blocked until the inventory can be read safely.</p>
            <?php endif; ?>
            <form method="POST" class="marketplace-setup-form" style="border-top:1px solid #fecaca;padding-top:.85rem;">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                <div class="marketplace-form-grid">
                    <label class="marketplace-form-field marketplace-form-field-wide">Type DELETE VIDEOS to confirm
                        <input type="text" name="marketplace_video_delete_confirmation" autocomplete="off" placeholder="DELETE VIDEOS" required>
                    </label>
                </div>
                <div class="marketplace-detail-actions" style="justify-content:flex-start;margin-top:.85rem;">
                    <button class="btn-premium-secondary" type="submit" name="skill_action" value="delete_all_marketplace_videos" style="border-color:#fecaca;color:#b91c1c;background:#fff5f5;">Delete all Marketplace videos</button>
                </div>
            </form>
        </section>

        <section class="marketplace-bundle-manager marketplace-section" data-marketplace-section="bundle-manager" style="margin-top:1rem;border:1px solid #e2e8f0;background:#fff;border-radius:8px;padding:1rem;">
            <div style="display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;flex-wrap:wrap;margin-bottom:.85rem;">
                <div>
                    <h2 style="font-size:1.1rem;margin:0;color:#0f172a;">Superadmin bundle manager</h2>
                    <p style="margin:.2rem 0 0;color:#475569;font-size:.92rem;">Create and manage global Marketplace activation bundles. Archived bundles stay in historical event data but no longer appear in recommendations.</p>
                </div>
                <span style="font-size:.78rem;font-weight:800;color:#1d4ed8;background:#eff6ff;border:1px solid #bfdbfe;border-radius:999px;padding:.25rem .55rem;">Global catalog</span>
            </div>

            <details class="marketplace-secondary-details" open>
                <summary>Create bundle</summary>
                <form method="POST" enctype="multipart/form-data" class="marketplace-setup-form" style="margin-top:.85rem;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                    <div class="marketplace-form-grid">
                        <label class="marketplace-form-field">Bundle key <input type="text" name="bundle_key" placeholder="example_growth_path" pattern="[A-Za-z0-9_\- ]+" required></label>
                        <label class="marketplace-form-field">Title <input type="text" name="bundle_label" required></label>
                        <label class="marketplace-form-field">Display order <input type="number" name="bundle_display_order" value="100" min="0"></label>
                        <label class="marketplace-form-check" style="align-self:end;"><input type="checkbox" name="bundle_is_active" value="1" checked> Active</label>
                        <label class="marketplace-form-field marketplace-form-field-wide">Summary <textarea name="bundle_summary" rows="3" required></textarea></label>
                        <label class="marketplace-form-field marketplace-form-field-wide">Why this bundle <textarea name="bundle_why_this_bundle" rows="3"></textarea></label>
                        <label class="marketplace-form-field marketplace-form-field-wide">Expected outcome <textarea name="bundle_expected_outcome" rows="3"></textarea></label>
                        <label class="marketplace-form-field">Thumbnail upload <input type="file" name="bundle_thumbnail_file" accept="image/jpeg,image/png,image/webp,image/gif"></label>
                        <label class="marketplace-form-field">Banner upload <input type="file" name="bundle_banner_file" accept="image/jpeg,image/png,image/webp,image/gif"></label>
                        <label class="marketplace-form-field marketplace-form-field-wide">Video upload <input type="file" name="bundle_video_file" accept="video/mp4,video/webm,video/quicktime,video/x-m4v"></label>
                    </div>
                    <div style="margin-top:.85rem;">
                        <strong style="display:block;margin-bottom:.45rem;color:#0f172a;">Included Marketplace modules</strong>
                        <div class="marketplace-chip-row">
                            <?php foreach ($marketplaceModules as $moduleOption): ?>
                                <?php $moduleOptionKey = (string) ($moduleOption['key'] ?? ''); ?>
                                <?php if ($moduleOptionKey === '' || !empty($moduleOption['module']['owner_workspace_id'])) { continue; } ?>
                                <label class="marketplace-form-check"><input type="checkbox" name="bundle_included_skill_keys[]" value="<?php echo htmlspecialchars($moduleOptionKey); ?>"> <?php echo htmlspecialchars((string) ($moduleOption['module']['label'] ?? $moduleOptionKey)); ?></label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="marketplace-detail-actions" style="margin-top:.85rem;">
                        <button class="btn-premium-primary" type="submit" name="skill_action" value="save_activation_bundle_definition">Create bundle</button>
                    </div>
                </form>
            </details>

            <div style="display:grid;gap:.75rem;margin-top:1rem;">
                <?php foreach ($marketplaceBundleDefinitionsForAdmin as $definition): ?>
                    <?php
                    $definitionKey = (string) ($definition['bundle_key'] ?? '');
                    $definitionIncluded = array_values(array_filter(array_map('strval', (array) ($definition['included_skill_keys'] ?? []))));
                    $definitionArchived = trim((string) ($definition['archived_at'] ?? '')) !== '';
                    $definitionActive = !empty($definition['is_active']) && !$definitionArchived;
                    $definitionThumbnailUrl = trim((string) ($definition['thumbnail_url'] ?? ''));
                    $definitionBannerUrl = trim((string) ($definition['banner_url'] ?? ''));
                    $definitionVideoUrl = trim((string) ($definition['explainer_video_url'] ?? ''));
                    ?>
                    <article class="marketplace-compact-card" style="grid-template-columns:1fr;">
                        <form method="POST" enctype="multipart/form-data" class="marketplace-setup-form">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                            <input type="hidden" name="current_bundle_key" value="<?php echo htmlspecialchars($definitionKey); ?>">
                            <div style="display:flex;justify-content:space-between;gap:.75rem;align-items:flex-start;flex-wrap:wrap;margin-bottom:.65rem;">
                                <div>
                                    <div style="font-size:.76rem;text-transform:uppercase;letter-spacing:.04em;color:#64748b;font-weight:800;"><?php echo htmlspecialchars($definitionKey); ?></div>
                                    <h3 style="font-size:1rem;margin:.12rem 0 0;color:#0f172a;"><?php echo htmlspecialchars((string) ($definition['label'] ?? 'Activation bundle')); ?></h3>
                                </div>
                                <span style="font-size:.78rem;font-weight:800;padding:.24rem .55rem;border-radius:999px;background:<?php echo $definitionArchived ? '#f1f5f9' : ($definitionActive ? '#dcfce7' : '#fff7ed'); ?>;color:<?php echo $definitionArchived ? '#475569' : ($definitionActive ? '#166534' : '#9a3412'); ?>;border:1px solid <?php echo $definitionArchived ? '#cbd5e1' : ($definitionActive ? '#bbf7d0' : '#fed7aa'); ?>;"><?php echo $definitionArchived ? 'Archived' : ($definitionActive ? 'Active' : 'Inactive'); ?></span>
                            </div>
                            <div class="marketplace-form-grid">
                                <label class="marketplace-form-field">Bundle key <input type="text" name="bundle_key" value="<?php echo htmlspecialchars($definitionKey); ?>" readonly></label>
                                <label class="marketplace-form-field">Title <input type="text" name="bundle_label" value="<?php echo htmlspecialchars((string) ($definition['label'] ?? '')); ?>" required></label>
                                <label class="marketplace-form-field">Display order <input type="number" name="bundle_display_order" value="<?php echo (int) ($definition['display_order'] ?? 100); ?>" min="0"></label>
                                <label class="marketplace-form-check" style="align-self:end;"><input type="checkbox" name="bundle_is_active" value="1" <?php echo $definitionActive ? 'checked' : ''; ?>> Active</label>
                                <label class="marketplace-form-field marketplace-form-field-wide">Summary <textarea name="bundle_summary" rows="3" required><?php echo htmlspecialchars((string) ($definition['summary'] ?? '')); ?></textarea></label>
                                <label class="marketplace-form-field marketplace-form-field-wide">Why this bundle <textarea name="bundle_why_this_bundle" rows="3"><?php echo htmlspecialchars((string) ($definition['why_this_bundle'] ?? '')); ?></textarea></label>
                                <label class="marketplace-form-field marketplace-form-field-wide">Expected outcome <textarea name="bundle_expected_outcome" rows="3"><?php echo htmlspecialchars((string) ($definition['expected_outcome'] ?? '')); ?></textarea></label>
                                <label class="marketplace-form-field">
                                    Thumbnail upload
                                    <?php if ($definitionThumbnailUrl !== ''): ?><a href="<?php echo htmlspecialchars($marketplaceAsset($definitionThumbnailUrl)); ?>" target="_blank" rel="noopener noreferrer" style="font-size:.78rem;color:#2563eb;">Current thumbnail</a><?php endif; ?>
                                    <input type="file" name="bundle_thumbnail_file" accept="image/jpeg,image/png,image/webp,image/gif">
                                </label>
                                <label class="marketplace-form-field">
                                    Banner upload
                                    <?php if ($definitionBannerUrl !== ''): ?><a href="<?php echo htmlspecialchars($marketplaceAsset($definitionBannerUrl)); ?>" target="_blank" rel="noopener noreferrer" style="font-size:.78rem;color:#2563eb;">Current banner</a><?php endif; ?>
                                    <input type="file" name="bundle_banner_file" accept="image/jpeg,image/png,image/webp,image/gif">
                                </label>
                                <label class="marketplace-form-field marketplace-form-field-wide">
                                    Video upload
                                    <?php if ($definitionVideoUrl !== ''): ?><a href="<?php echo htmlspecialchars($marketplaceAsset($definitionVideoUrl)); ?>" target="_blank" rel="noopener noreferrer" style="font-size:.78rem;color:#2563eb;">Current video</a><?php endif; ?>
                                    <input type="file" name="bundle_video_file" accept="video/mp4,video/webm,video/quicktime,video/x-m4v">
                                </label>
                            </div>
                            <div style="margin-top:.85rem;">
                                <strong style="display:block;margin-bottom:.45rem;color:#0f172a;">Included Marketplace modules</strong>
                                <div class="marketplace-chip-row">
                                    <?php foreach ($marketplaceModules as $moduleOption): ?>
                                        <?php $moduleOptionKey = (string) ($moduleOption['key'] ?? ''); ?>
                                        <?php if ($moduleOptionKey === '' || !empty($moduleOption['module']['owner_workspace_id'])) { continue; } ?>
                                        <label class="marketplace-form-check"><input type="checkbox" name="bundle_included_skill_keys[]" value="<?php echo htmlspecialchars($moduleOptionKey); ?>" <?php echo in_array($moduleOptionKey, $definitionIncluded, true) ? 'checked' : ''; ?>> <?php echo htmlspecialchars((string) ($moduleOption['module']['label'] ?? $moduleOptionKey)); ?></label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="marketplace-detail-actions" style="margin-top:.85rem;">
                                <button class="btn-premium-primary" type="submit" name="skill_action" value="save_activation_bundle_definition">Save bundle</button>
                            </div>
                        </form>
                        <div class="marketplace-detail-actions" style="margin-top:.65rem;">
                            <form method="POST" style="margin:0;">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                                <input type="hidden" name="bundle_key" value="<?php echo htmlspecialchars($definitionKey); ?>">
                                <input type="hidden" name="bundle_definition_active" value="<?php echo $definitionActive ? '0' : '1'; ?>">
                                <button class="btn-premium-secondary" type="submit" name="skill_action" value="toggle_activation_bundle_definition"><?php echo $definitionActive ? 'Deactivate' : 'Reactivate'; ?></button>
                            </form>
                            <?php if (!$definitionArchived): ?>
                                <form method="POST" style="margin:0;">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                                    <input type="hidden" name="bundle_key" value="<?php echo htmlspecialchars($definitionKey); ?>">
                                    <button class="btn-premium-secondary" type="submit" name="skill_action" value="archive_activation_bundle_definition">Archive</button>
                                </form>
                            <?php endif; ?>
                            <form method="POST" style="margin:0;" onsubmit="return confirm('Delete this Marketplace activation bundle? Historical events remain, but the bundle definition will be removed.');">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                                <input type="hidden" name="bundle_key" value="<?php echo htmlspecialchars($definitionKey); ?>">
                                <button class="btn-premium-secondary" type="submit" name="skill_action" value="delete_activation_bundle_definition" style="border-color:#fecaca;color:#b91c1c;background:#fff5f5;">Delete</button>
                            </form>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($canManage && !empty($activeRecommendationControlsBySkill)): ?>
        <section class="marketplace-controls-panel marketplace-section" data-marketplace-section="controls" style="margin-top:1rem;border:1px solid #e2e8f0;background:#f8fafc;border-radius:8px;padding:.85rem;">
            <div style="display:flex;justify-content:space-between;gap:.75rem;align-items:flex-start;flex-wrap:wrap;margin-bottom:.65rem;">
                <div>
                    <strong style="color:#0f172a;">Marketplace controls</strong>
                    <p style="margin:.15rem 0 0;color:#64748b;font-size:.88rem;">Owner controls currently shaping recommendation visibility.</p>
                </div>
            </div>
            <div style="display:flex;gap:.5rem;flex-wrap:wrap;">
                <?php foreach ($activeRecommendationControlsBySkill as $controlSkillKey => $controlState): ?>
                    <?php $controlModule = $catalog->find((string) $controlSkillKey) ?: []; ?>
                    <?php if (!empty($controlState['pinned'])): ?>
                        <form method="POST" style="margin:0;">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                            <input type="hidden" name="skill_key" value="<?php echo htmlspecialchars((string) $controlSkillKey); ?>">
                            <button type="submit" name="skill_action" value="unpin_recommendation" style="border:1px solid #bfdbfe;background:#eff6ff;color:#1d4ed8;border-radius:999px;padding:.35rem .6rem;font-weight:800;font-size:.78rem;cursor:pointer;">Unpin <?php echo htmlspecialchars((string) ($controlModule['label'] ?? $controlSkillKey)); ?></button>
                        </form>
                    <?php endif; ?>
                    <?php if (!empty($controlState['muted'])): ?>
                        <form method="POST" style="margin:0;">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                            <input type="hidden" name="skill_key" value="<?php echo htmlspecialchars((string) $controlSkillKey); ?>">
                            <button type="submit" name="skill_action" value="unmute_recommendation" style="border:1px solid #fed7aa;background:#fff7ed;color:#9a3412;border-radius:999px;padding:.35rem .6rem;font-weight:800;font-size:.78rem;cursor:pointer;">Unmute <?php echo htmlspecialchars((string) ($controlModule['label'] ?? $controlSkillKey)); ?></button>
                        </form>
                    <?php endif; ?>
                    <?php foreach ((array) ($controlState['surface_disabled'] ?? []) as $disabledSurface): ?>
                        <form method="POST" style="margin:0;">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                            <input type="hidden" name="skill_key" value="<?php echo htmlspecialchars((string) $controlSkillKey); ?>">
                            <input type="hidden" name="control_surface" value="<?php echo htmlspecialchars((string) $disabledSurface); ?>">
                            <button type="submit" name="skill_action" value="enable_recommendation_surface" style="border:1px solid #cbd5e1;background:#fff;color:#334155;border-radius:999px;padding:.35rem .6rem;font-weight:800;font-size:.78rem;cursor:pointer;">Show <?php echo htmlspecialchars(str_replace('_', ' ', (string) $disabledSurface)); ?> for <?php echo htmlspecialchars((string) ($controlModule['label'] ?? $controlSkillKey)); ?></button>
                        </form>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if (!$canManage): ?>
        <p style="margin:1rem 0 0;color:#64748b;font-size:.9rem;">You can browse the Marketplace. Install, setup, and recommendation actions require Marketplace management access.</p>
    <?php endif; ?>
    </main>
    <?php $marketplaceRenderSideRail(); ?>
    </div>
    <?php endif; ?>
    </div>
</div>

<script>
(function () {
    var activeFilter = 'all';
    var activeTag = '';
    var search = document.getElementById('marketplace-search');
    var buttons = Array.prototype.slice.call(document.querySelectorAll('[data-marketplace-filter]'));
    var goalFilter = document.getElementById('marketplace-goal-filter');
    var cards = Array.prototype.slice.call(document.querySelectorAll('.marketplace-card'));
    var sections = Array.prototype.slice.call(document.querySelectorAll('[data-marketplace-section]'));
    var resultCount = document.getElementById('marketplace-result-count');
    var resetFilters = document.getElementById('marketplace-reset-filters');
    var empty = document.getElementById('marketplace-empty');
    var csrfToken = '<?php echo htmlspecialchars($csrf, ENT_QUOTES); ?>';
    var requestedLockedModuleKey = <?php echo json_encode($requestedLockedModuleKey, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
    var selectedModuleKey = <?php echo json_encode($selectedMarketplaceItem !== null ? (string) ($selectedMarketplaceItem['key'] ?? '') : '', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
    var isGuidedDemoVisit = <?php echo $isGuidedDemoVisit ? 'true' : 'false'; ?>;
    var isProtectedDemoMarketplace = <?php echo $isProtectedDemoMarketplace ? 'true' : 'false'; ?>;
    var marketplaceAccessData = {};
    var openMarketplaceAccessModal = function () {};

    function escapeMarketplaceScene(text) {
        var div = document.createElement('div');
        div.textContent = String(text || '');
        return div.innerHTML;
    }

    var protectedDemoDefaultPlugins = [
        { key: 'email_assistant', label: 'Email Assistant', state: 'Configured', proof: 'Drafts and procurement context are ready.' },
        { key: 'whatsapp_assistant', label: 'WhatsApp Assistant', state: 'Configured', proof: 'Inbound lead capture is simulated and scoped.' },
        { key: 'clarity_ai', label: 'Clarity AI', state: 'Live', proof: 'Next-best-action guidance is active.' },
        { key: 'targets', label: 'Targets', state: 'Live', proof: 'Response metric moves with the Riverside story.' },
        { key: 'meeting_prep', label: 'Meeting Prep', state: 'Ready', proof: 'Contact brief can be revealed without live AI calls.' }
    ];

    function renderProtectedDemoPluginScene(plugins) {
        var scene = document.querySelector('[data-protected-demo-plugin-scene]');
        var list = document.querySelector('[data-protected-demo-plugin-list]');
        plugins = plugins && plugins.length ? plugins : protectedDemoDefaultPlugins;
        if (!scene || !list || !plugins.length) return;
        scene.hidden = false;
        scene.classList.add('is-visible');
        list.innerHTML = plugins.map(function(plugin, index) {
            return '<article class="protected-demo-plugin-scene__item" style="animation-delay:' + (index * 300) + 'ms">' +
                '<span>' + escapeMarketplaceScene(plugin.state || 'Ready') + '</span>' +
                '<strong>' + escapeMarketplaceScene(plugin.label || 'Demo plugin') + '</strong>' +
                '<p>' + escapeMarketplaceScene(plugin.proof || 'Configured for the protected demo.') + '</p>' +
                '</article>';
        }).join('');
    }

    window.addEventListener('protected-demo:scene', function(event) {
        if (!isProtectedDemoMarketplace) return;
        var detail = event.detail || {};
        var payload = detail.payload || {};
        var sceneKey = String(detail.scene_key || payload.scene_key || payload.demo_event_key || '');
        if (sceneKey !== 'marketplace_plugin_sequence_started') return;

        var plugins = ((payload.animation_payload || {}).plugins || []);
        renderProtectedDemoPluginScene(plugins);
        document.querySelectorAll('[data-marketplace-installed="1"], .marketplace-card').forEach(function(card, index) {
            if (index > 4) return;
            card.classList.add('protected-demo-scene-new');
            window.setTimeout(function() {
                card.classList.remove('protected-demo-scene-new');
            }, 2800 + (index * 180));
        });
    });

    if (isProtectedDemoMarketplace) {
        window.setTimeout(function() {
            var scene = document.querySelector('[data-protected-demo-plugin-scene]');
            if (scene && scene.hidden) {
                renderProtectedDemoPluginScene(protectedDemoDefaultPlugins);
            }
        }, 1800);
    }

    try {
        marketplaceAccessData = JSON.parse((document.getElementById('marketplace-access-data') || {}).textContent || '{}') || {};
    } catch (error) {
        marketplaceAccessData = {};
    }

    function trackRecommendationClick(link) {
        var skillKey = link.getAttribute('data-marketplace-skill-key') || '';
        if (!skillKey || !window.fetch) return;
        var eventType = link.closest('.marketplace-card') ? 'catalog_click' : 'cta_clicked';
        try {
            window.fetch('../api/workspace/marketplace_recommendation_event.php', {
                method: 'POST',
                keepalive: true,
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    csrf_token: csrfToken,
                    skill_key: skillKey,
                    surface: 'marketplace',
                    event_type: eventType,
                    source: 'workspace_marketplace_page',
                    target_url: link.getAttribute('href') || '',
                    activation_bundle_key: link.getAttribute('data-marketplace-activation-bundle-key') || ''
                })
            }).catch(function () {});
        } catch (e) {}
    }

    function trackActivationBundleClick(link) {
        var bundleKey = link.getAttribute('data-marketplace-activation-bundle-key') || '';
        if (!bundleKey || !window.fetch) return;
        try {
            window.fetch('../api/workspace/marketplace_activation_bundle_event.php', {
                method: 'POST',
                keepalive: true,
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    csrf_token: csrfToken,
                    bundle_key: bundleKey,
                    event_type: 'cta_clicked',
                    source: 'workspace_marketplace_page',
                    target_url: link.getAttribute('href') || '',
                    next_action_skill_key: link.getAttribute('data-marketplace-skill-key') || ''
                })
            }).catch(function () {});
        } catch (e) {}
    }

    function trackSetupJourneyOpen(link) {
        if (link.getAttribute('data-marketplace-setup-journey-open') !== '1') return;
        var skillKey = link.getAttribute('data-marketplace-skill-key') || '';
        if (!skillKey || !window.fetch) return;
        try {
            window.fetch('../api/workspace/marketplace_setup_journey_event.php', {
                method: 'POST',
                keepalive: true,
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    csrf_token: csrfToken,
                    skill_key: skillKey,
                    event_type: 'setup_opened',
                    label: link.getAttribute('data-marketplace-setup-label') || '',
                    source: 'workspace_marketplace_page',
                    target_url: link.getAttribute('href') || ''
                })
            }).catch(function () {});
        } catch (e) {}
    }

    function trackModulePageView() {
        if (!selectedModuleKey || !window.fetch) return;
        try {
            window.fetch('../api/workspace/marketplace_recommendation_event.php', {
                method: 'POST',
                keepalive: true,
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    csrf_token: csrfToken,
                    skill_key: selectedModuleKey,
                    surface: 'marketplace',
                    event_type: 'module_page_view',
                    source: 'workspace_marketplace_module_page',
                    target_url: window.location.href
                })
            }).catch(function () {});
        } catch (e) {}
    }

    var previewMotionQuery = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;
    var previewPointerQuery = window.matchMedia ? window.matchMedia('(hover: hover) and (pointer: fine)') : null;
    var cardPreviewEnabled = (!previewMotionQuery || !previewMotionQuery.matches)
        && (!previewPointerQuery || previewPointerQuery.matches);

    function cardPreviewVideo(card) {
        return card ? card.querySelector('[data-marketplace-card-video]') : null;
    }

    function stopCardPreview(card) {
        var video = cardPreviewVideo(card);
        if (!video) return;
        card.classList.remove('is-previewing');
        try {
            video.pause();
            video.currentTime = 0;
        } catch (error) {}
    }

    function startCardPreview(card) {
        if (!cardPreviewEnabled) return;
        var video = cardPreviewVideo(card);
        if (!video) return;
        try {
            video.muted = true;
            video.loop = true;
            video.playsInline = true;
            var attempt = video.play();
            if (attempt && typeof attempt.then === 'function') {
                attempt.then(function () {
                    card.classList.add('is-previewing');
                }).catch(function () {
                    card.classList.remove('is-previewing');
                });
                return;
            }
            card.classList.add('is-previewing');
        } catch (error) {
            card.classList.remove('is-previewing');
        }
    }

    function initCardPreviews() {
        cards.forEach(function (card) {
            if (!cardPreviewVideo(card)) return;
            card.addEventListener('mouseenter', function () {
                startCardPreview(card);
            });
            card.addEventListener('mouseleave', function () {
                stopCardPreview(card);
            });
            card.addEventListener('focusin', function () {
                startCardPreview(card);
            });
            card.addEventListener('focusout', function (event) {
                if (!card.contains(event.relatedTarget)) {
                    stopCardPreview(card);
                }
            });
        });
    }

    function initAccessRequirementsModal() {
        var modal = document.querySelector('[data-marketplace-access-modal]');
        if (!modal) return;

        var title = modal.querySelector('#marketplace-access-title');
        var message = modal.querySelector('[data-marketplace-access-message]');
        var completedSection = modal.querySelector('[data-marketplace-access-completed-section]');
        var pendingSection = modal.querySelector('[data-marketplace-access-pending-section]');
        var completedList = modal.querySelector('[data-marketplace-access-completed]');
        var pendingList = modal.querySelector('[data-marketplace-access-pending]');
        var root = modal.querySelector('[data-marketplace-access-root]');
        var why = modal.querySelector('[data-marketplace-access-why]');
        var action = modal.querySelector('[data-marketplace-access-action]');
        var closeButtons = Array.prototype.slice.call(modal.querySelectorAll('[data-marketplace-access-close]'));
        var lastFocused = null;
        var previousOverflow = '';

        function fillList(list, items) {
            if (!list) return;
            list.innerHTML = '';
            items.forEach(function (item) {
                var li = document.createElement('li');
                li.textContent = item.label || item.module_label || item.skill_key || 'Workspace requirement';
                list.appendChild(li);
            });
        }

        function accessRequirementSentence(access) {
            var moduleLabel = access.label || 'this module';
            var blocker = access.root_blocker_label || '';
            if (!blocker && access.next_action_label) {
                blocker = access.next_action_label.replace(/^Open\s+/i, '').replace(/^Set up\s+/i, '');
            }
            blocker = (blocker || 'the required setup').trim();

            var hasSetup = blocker.toLowerCase().indexOf('setup') !== -1;
            var blockerPhrase = hasSetup ? blocker : blocker + ' setup';
            var verb = hasSetup ? 'Finish' : 'Complete';

            return verb + ' ' + blockerPhrase + ' first to unlock ' + moduleLabel + '.';
        }

        function closeModal() {
            modal.hidden = true;
            document.body.style.overflow = previousOverflow;
            if (lastFocused && typeof lastFocused.focus === 'function') {
                lastFocused.focus({ preventScroll: true });
            }
        }

        openMarketplaceAccessModal = function (skillKey) {
            var access = marketplaceAccessData[skillKey] || {};
            if (!access || !access.skill_key) return;

            lastFocused = document.activeElement;
            previousOverflow = document.body.style.overflow;
            if (modal.parentNode !== document.body) {
                document.body.appendChild(modal);
            }
            if (title) {
                title.textContent = (access.label || 'Module') + ' requirements';
            }
            if (message) {
                message.textContent = access.message || 'Complete the pending workspace requirements before this module can be used.';
            }

            var completed = Array.isArray(access.completed_requirements) ? access.completed_requirements : [];
            var pending = Array.isArray(access.pending_requirements) ? access.pending_requirements : [];
            fillList(completedList, completed);
            fillList(pendingList, pending);
            if (completedSection) {
                completedSection.hidden = completed.length === 0;
            }
            if (pendingSection) {
                pendingSection.hidden = pending.length === 0;
            }
            if (root) {
                root.textContent = accessRequirementSentence(access);
            }
            if (why) {
                why.textContent = access.why || 'This gate keeps modules from running with incomplete upstream context.';
            }
            if (action) {
                action.href = access.next_action_url || 'workspace_skills.php';
                action.textContent = access.next_action_label || 'Open setup';
                action.setAttribute('data-marketplace-skill-key', access.root_blocker_skill_key || access.skill_key || '');
            }

            modal.hidden = false;
            modal.scrollTop = 0;
            document.body.style.overflow = 'hidden';
            var firstClose = closeButtons[0];
            if (firstClose) {
                firstClose.focus({ preventScroll: true });
            }
        };

        closeButtons.forEach(function (button) {
            button.addEventListener('click', closeModal);
        });
        modal.addEventListener('click', function (event) {
            if (event.target === modal) closeModal();
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !modal.hidden) closeModal();
        });

        if (requestedLockedModuleKey && !isGuidedDemoVisit) {
            window.setTimeout(function () {
                openMarketplaceAccessModal(requestedLockedModuleKey);
            }, 80);
        }
    }

    function initCatalogVideoModal() {
        var modal = document.querySelector('[data-marketplace-card-video-modal]');
        if (!modal) return;

        var title = modal.querySelector('#marketplace-card-video-title');
        var frame = modal.querySelector('[data-marketplace-card-video-frame]');
        var closeButton = modal.querySelector('[data-marketplace-card-video-close]');
        var openers = Array.prototype.slice.call(document.querySelectorAll('[data-marketplace-card-video-open]'));
        var lastFocused = null;
        var previousOverflow = '';
        var watermarkLogoUrl = <?php echo json_encode(VideoBrandOverlayUi::logoUrl(), JSON_UNESCAPED_SLASHES); ?>;

        function autoplayUrl(url) {
            try {
                var parsed = new URL(url, window.location.href);
                parsed.searchParams.set('autoplay', '1');
                parsed.searchParams.set('playsinline', '1');
                return parsed.toString();
            } catch (error) {
                return url;
            }
        }

        function clearFrame() {
            if (!frame) return;
            var video = frame.querySelector('video');
            if (video) {
                try {
                    video.pause();
                    video.removeAttribute('src');
                    video.load();
                } catch (error) {}
            }
            frame.innerHTML = '';
        }

        function appendWatermarkedMedia(mediaNode) {
            if (!frame) return;
            var wrapper = document.createElement('div');
            wrapper.className = 'video-brand-overlay-frame';
            wrapper.appendChild(mediaNode);

            var logo = document.createElement('img');
            logo.className = 'video-brand-overlay-logo';
            logo.src = watermarkLogoUrl;
            logo.alt = '';
            logo.setAttribute('aria-hidden', 'true');
            logo.loading = 'lazy';
            wrapper.appendChild(logo);

            frame.appendChild(wrapper);
        }

        function closeModal() {
            modal.hidden = true;
            document.body.style.overflow = previousOverflow;
            clearFrame();
            if (lastFocused && typeof lastFocused.focus === 'function') {
                lastFocused.focus({ preventScroll: true });
            }
        }

        function openModal(opener) {
            var videoType = opener.getAttribute('data-marketplace-video-type') || '';
            var videoUrl = opener.getAttribute('data-marketplace-video-url') || '';
            var poster = opener.getAttribute('data-marketplace-video-poster') || '';
            var videoTitle = opener.getAttribute('data-marketplace-video-title') || 'Explainer video';
            if (!videoUrl || !frame) return;

            cards.forEach(stopCardPreview);
            lastFocused = document.activeElement;
            previousOverflow = document.body.style.overflow;
            if (modal.parentNode !== document.body) {
                document.body.appendChild(modal);
            }
            clearFrame();
            if (title) {
                title.textContent = videoTitle;
            }

            if (videoType === 'iframe') {
                var iframe = document.createElement('iframe');
                iframe.src = autoplayUrl(videoUrl);
                iframe.title = videoTitle;
                iframe.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share';
                iframe.allowFullscreen = true;
                appendWatermarkedMedia(iframe);
            } else {
                var video = document.createElement('video');
                video.controls = true;
                video.autoplay = true;
                video.playsInline = true;
                video.preload = 'auto';
                if (poster) {
                    video.poster = poster;
                }
                var source = document.createElement('source');
                source.src = videoUrl;
                video.appendChild(source);
                appendWatermarkedMedia(video);
                var playAttempt = video.play();
                if (playAttempt && typeof playAttempt.catch === 'function') {
                    playAttempt.catch(function () {});
                }
            }

            modal.hidden = false;
            modal.scrollTop = 0;
            document.body.style.overflow = 'hidden';
            if (closeButton) {
                closeButton.focus({ preventScroll: true });
            }
        }

        openers.forEach(function (opener) {
            opener.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                openModal(opener);
            });
        });
        if (closeButton) {
            closeButton.addEventListener('click', closeModal);
        }
        modal.addEventListener('click', function (event) {
            if (event.target === modal) closeModal();
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !modal.hidden) closeModal();
        });
    }

    function applyFilters() {
        var query = search ? search.value.trim().toLowerCase() : '';
        activeTag = goalFilter ? goalFilter.value : '';
        var visibleCount = 0;
        var catalogVisible = ['all', 'skill', 'plugin', 'installed'].indexOf(activeFilter) !== -1;

        sections.forEach(function (section) {
            var sectionName = section.getAttribute('data-marketplace-section') || '';
            var visible = (activeFilter === 'all' && sectionName !== 'bundle-manager' && sectionName !== 'custom-skill')
                || (activeFilter === 'recommended' && sectionName === 'recommended')
                || (activeFilter === 'bundles' && sectionName === 'bundles')
                || (activeFilter === 'bundle-manager' && sectionName === 'bundle-manager')
                || (activeFilter === 'custom-skill' && sectionName === 'custom-skill')
                || (catalogVisible && sectionName === 'catalog');
            section.hidden = !visible;
        });

        cards.forEach(function (card) {
            var type = card.getAttribute('data-marketplace-type') || '';
            var installed = card.getAttribute('data-marketplace-installed') === '1';
            var tags = card.getAttribute('data-marketplace-tags') || '';
            var haystack = card.getAttribute('data-marketplace-search') || '';
            var filterMatch = activeFilter === 'all'
                || activeFilter === type
                || (activeFilter === 'installed' && installed)
                || (activeFilter === 'available' && !installed);
            var searchMatch = query === '' || haystack.indexOf(query) !== -1;
            var tagMatch = activeTag === '' || tags.indexOf(' ' + activeTag + ' ') !== -1;
            var visible = filterMatch && searchMatch && tagMatch;
            if (!visible) {
                stopCardPreview(card);
            }
            card.style.display = visible ? 'grid' : 'none';
            if (visible) {
                visibleCount++;
            }
        });

        if (empty) {
            empty.style.display = catalogVisible && visibleCount === 0 ? 'block' : 'none';
        }

        if (search) {
            search.disabled = !catalogVisible;
            var searchWrap = search.closest('.marketplace-search');
            if (searchWrap) {
                searchWrap.style.opacity = catalogVisible ? '1' : '.55';
            }
        }

        if (goalFilter) {
            goalFilter.disabled = !catalogVisible;
            var goalWrap = goalFilter.closest('.marketplace-goal-select');
            if (goalWrap) {
                goalWrap.style.opacity = catalogVisible ? '1' : '.55';
            }
        }

        if (resetFilters) {
            resetFilters.disabled = !catalogVisible;
        }

        if (resultCount) {
            resultCount.textContent = catalogVisible
                ? visibleCount + ' ' + (visibleCount === 1 ? 'match' : 'matches')
                : '';
        }
    }

    buttons.forEach(function (button) {
        button.addEventListener('click', function () {
            activeFilter = button.getAttribute('data-marketplace-filter') || 'all';
            buttons.forEach(function (other) {
                var selected = other === button;
                other.setAttribute('aria-pressed', selected ? 'true' : 'false');
            });
            applyFilters();
        });
    });

    if (search) {
        search.addEventListener('input', applyFilters);
    }

    if (goalFilter) {
        goalFilter.addEventListener('change', applyFilters);
    }

    if (resetFilters) {
        resetFilters.addEventListener('click', function () {
            activeFilter = 'all';
            activeTag = '';
            buttons.forEach(function (button) {
                button.setAttribute('aria-pressed', (button.getAttribute('data-marketplace-filter') || 'all') === 'all' ? 'true' : 'false');
            });
            if (goalFilter) {
                goalFilter.value = '';
            }
            if (search) {
                search.value = '';
            }
            applyFilters();
        });
    }

    function initSideRailDisclosure() {
        var disclosure = document.querySelector('.marketplace-side-rail-disclosure');
        if (!disclosure || !window.matchMedia) return;
        var query = window.matchMedia('(max-width: 1100px)');
        var sync = function () {
            if (query.matches) {
                disclosure.removeAttribute('open');
            } else {
                disclosure.setAttribute('open', '');
            }
        };
        sync();
        if (typeof query.addEventListener === 'function') {
            query.addEventListener('change', sync);
        } else if (typeof query.addListener === 'function') {
            query.addListener(sync);
        }
    }

    function initModuleTabs() {
        var tabGroups = Array.prototype.slice.call(document.querySelectorAll('.marketplace-module-tabs'));
        tabGroups.forEach(function (group) {
            var tabs = Array.prototype.slice.call(group.querySelectorAll('[data-marketplace-module-tab]'));
            var panels = Array.prototype.slice.call(document.querySelectorAll('[data-marketplace-module-panel]'));
            if (!tabs.length || !panels.length) return;

            function activate(panelName, focusTab) {
                var matched = tabs.some(function (tab) {
                    return tab.getAttribute('data-marketplace-module-tab') === panelName;
                });
                if (!matched) {
                    panelName = 'overview';
                }

                tabs.forEach(function (tab) {
                    var selected = tab.getAttribute('data-marketplace-module-tab') === panelName;
                    tab.setAttribute('aria-selected', selected ? 'true' : 'false');
                    if (selected && focusTab) {
                        tab.focus();
                    }
                });
                panels.forEach(function (panel) {
                    panel.hidden = panel.getAttribute('data-marketplace-module-panel') !== panelName;
                });
            }

            tabs.forEach(function (tab, index) {
                tab.addEventListener('click', function () {
                    activate(tab.getAttribute('data-marketplace-module-tab') || 'overview', false);
                });
                tab.addEventListener('keydown', function (event) {
                    if (event.key !== 'ArrowRight' && event.key !== 'ArrowLeft') return;
                    event.preventDefault();
                    var nextIndex = event.key === 'ArrowRight'
                        ? (index + 1) % tabs.length
                        : (index - 1 + tabs.length) % tabs.length;
                    activate(tabs[nextIndex].getAttribute('data-marketplace-module-tab') || 'overview', true);
                });
            });

            document.querySelectorAll('[data-marketplace-module-jump]').forEach(function (jump) {
                jump.addEventListener('click', function () {
                    activate(jump.getAttribute('data-marketplace-module-jump') || 'overview', true);
                });
            });

            var params = new URLSearchParams(window.location.search || '');
            var initial = (window.location.hash || '').replace('#', '');
            if (params.has('setup_tab') && tabs.some(function (tab) {
                return tab.getAttribute('data-marketplace-module-tab') === 'setup';
            })) {
                initial = 'setup';
            }
            activate(initial || 'overview', false);
        });
    }

    function initRichCatalogEditors() {
        var editors = Array.prototype.slice.call(document.querySelectorAll('[data-marketplace-rich-editor]'));
        if (!editors.length) return;

        function escapeHtml(value) {
            return String(value || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function textToHtml(value) {
            var html = '';
            var listItems = [];
            function flushList() {
                if (!listItems.length) return;
                html += '<ul>' + listItems.map(function (item) {
                    return '<li>' + escapeHtml(item) + '</li>';
                }).join('') + '</ul>';
                listItems = [];
            }

            String(value || '').split(/\r?\n/).forEach(function (line) {
                var trimmed = line.trim();
                var bullet = trimmed.match(/^[-*]\s+(.+)$/);
                if (!trimmed) {
                    flushList();
                    return;
                }
                if (bullet) {
                    listItems.push(bullet[1]);
                    return;
                }
                flushList();
                html += '<p>' + escapeHtml(trimmed) + '</p>';
            });
            flushList();

            return html;
        }

        function sanitizePreviewHtml(value) {
            var html = String(value || '')
                .replace(/<\s*(script|style|iframe|object|embed|form|input|button|select|textarea|link|meta|base)\b[^>]*>.*?<\s*\/\s*\1\s*>/gis, '')
                .replace(/<\s*(script|style|iframe|object|embed|form|input|button|select|textarea|link|meta|base)\b[^>]*\/?\s*>/gis, '');
            var template = document.createElement('template');
            template.innerHTML = html;
            var allowed = ['P', 'BR', 'STRONG', 'B', 'EM', 'I', 'U', 'S', 'H2', 'H3', 'H4', 'H5', 'H6', 'UL', 'OL', 'LI', 'A', 'BLOCKQUOTE', 'PRE', 'CODE', 'TABLE', 'THEAD', 'TBODY', 'TR', 'TH', 'TD', 'FIGURE', 'FIGCAPTION', 'IMG', 'HR'];
            Array.prototype.slice.call(template.content.querySelectorAll('*')).forEach(function (node) {
                if (allowed.indexOf(node.tagName) === -1) {
                    node.replaceWith(document.createTextNode(node.textContent || ''));
                    return;
                }
                Array.prototype.slice.call(node.attributes).forEach(function (attr) {
                    var name = attr.name.toLowerCase();
                    var value = attr.value || '';
                    if (node.tagName === 'A' && name === 'href' && (/^https?:\/\//i.test(value) || /^mailto:/i.test(value))) {
                        node.setAttribute('target', '_blank');
                        node.setAttribute('rel', 'noopener noreferrer');
                        return;
                    }
                    if (node.tagName === 'IMG' && (name === 'src' || name === 'alt')) {
                        if (name === 'src' && !(/^https:\/\//i.test(value) || /^(\.\.\/)?uploads\/marketplace\//i.test(value) || /^\/uploads\/marketplace\//i.test(value))) {
                            node.removeAttribute(name);
                        }
                        return;
                    }
                    node.removeAttribute(attr.name);
                });
                if (node.tagName === 'IMG' && !node.getAttribute('src')) {
                    node.remove();
                }
            });

            return template.innerHTML.trim();
        }

        var imageModal = document.querySelector('[data-marketplace-inline-image-modal]');
        var imageModalState = null;
        var imagePreviewUrl = '';
        var imageFileInput = imageModal ? imageModal.querySelector('[data-marketplace-inline-image-file]') : null;
        var imageAltInput = imageModal ? imageModal.querySelector('[data-marketplace-inline-image-alt]') : null;
        var imageCaptionInput = imageModal ? imageModal.querySelector('[data-marketplace-inline-image-caption]') : null;
        var imagePreview = imageModal ? imageModal.querySelector('[data-marketplace-inline-image-preview]') : null;
        var imageStatus = imageModal ? imageModal.querySelector('[data-marketplace-inline-image-status]') : null;
        var imageUploadButton = imageModal ? imageModal.querySelector('[data-marketplace-inline-image-upload]') : null;

        function setInlineImageStatus(message, tone) {
            if (!imageStatus) return;
            imageStatus.textContent = message || '';
            imageStatus.classList.remove('is-error', 'is-success', 'is-working');
            if (tone) {
                imageStatus.classList.add('is-' + tone);
            }
        }

        function revokeInlineImagePreview() {
            if (imagePreviewUrl) {
                URL.revokeObjectURL(imagePreviewUrl);
                imagePreviewUrl = '';
            }
        }

        function resetInlineImageModal() {
            revokeInlineImagePreview();
            if (imageFileInput) imageFileInput.value = '';
            if (imageAltInput) imageAltInput.value = '';
            if (imageCaptionInput) imageCaptionInput.value = '';
            if (imagePreview) imagePreview.innerHTML = '<span>Choose an image to preview it here.</span>';
            setInlineImageStatus('', '');
            if (imageUploadButton) imageUploadButton.disabled = false;
        }

        function openInlineImageModal(state) {
            if (!imageModal) return;
            imageModalState = state;
            resetInlineImageModal();
            imageModal.hidden = false;
            window.setTimeout(function () {
                if (imageFileInput) imageFileInput.focus();
            }, 0);
        }

        function closeInlineImageModal() {
            if (!imageModal) return;
            imageModal.hidden = true;
            imageModalState = null;
            resetInlineImageModal();
        }

        function buildInlineImageFigure(data) {
            var src = String(data && data.html_src ? data.html_src : '');
            var alt = String(data && data.alt ? data.alt : '');
            var caption = String(data && data.caption ? data.caption : '');
            if (!src) return '';
            var figure = '<figure>\n<img src="' + escapeHtml(src) + '" alt="' + escapeHtml(alt) + '">';
            if (caption) {
                figure += '\n<figcaption>' + escapeHtml(caption) + '</figcaption>';
            }
            return figure + '\n</figure>';
        }

        function previewInlineImageFile() {
            if (!imageFileInput || !imagePreview) return;
            revokeInlineImagePreview();
            var file = imageFileInput.files && imageFileInput.files[0] ? imageFileInput.files[0] : null;
            if (!file) {
                imagePreview.innerHTML = '<span>Choose an image to preview it here.</span>';
                setInlineImageStatus('', '');
                return;
            }
            if (!/^image\/(jpeg|png|webp|gif)$/i.test(file.type || '')) {
                imagePreview.innerHTML = '<span>JPG, PNG, WebP, and GIF files are supported.</span>';
                setInlineImageStatus('Choose a JPG, PNG, WebP, or GIF image.', 'error');
                return;
            }
            imagePreviewUrl = URL.createObjectURL(file);
            imagePreview.innerHTML = '<img src="' + escapeHtml(imagePreviewUrl) + '" alt="">';
            setInlineImageStatus('Ready to upload.', '');
        }

        function uploadInlineImage() {
            if (!imageModalState || !imageFileInput || !imageAltInput || !imageUploadButton) return;
            var form = imageModalState.editor.closest('[data-marketplace-catalog-form]');
            var endpoint = form ? form.getAttribute('data-marketplace-inline-image-endpoint') || '' : '';
            var file = imageFileInput.files && imageFileInput.files[0] ? imageFileInput.files[0] : null;
            var alt = (imageAltInput.value || '').trim();
            if (!endpoint) {
                setInlineImageStatus('The image upload endpoint is unavailable.', 'error');
                return;
            }
            if (!file) {
                setInlineImageStatus('Choose an image before uploading.', 'error');
                return;
            }
            if (!alt) {
                setInlineImageStatus('Add alt text before inserting this image.', 'error');
                imageAltInput.focus();
                return;
            }

            var csrfInput = form ? form.querySelector('input[name="csrf_token"]') : null;
            var skillInput = form ? form.querySelector('input[name="skill_key"]') : null;
            var payload = new FormData();
            payload.append('csrf_token', csrfInput ? csrfInput.value : '');
            payload.append('skill_key', skillInput ? skillInput.value : '');
            payload.append('image_file', file);
            payload.append('alt', alt);
            payload.append('caption', imageCaptionInput ? imageCaptionInput.value || '' : '');

            imageUploadButton.disabled = true;
            setInlineImageStatus('Uploading image...', 'working');
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
                    var snippet = buildInlineImageFigure(data);
                    if (!snippet) {
                        throw new Error('The upload did not return an image path.');
                    }
                    if (imageModalState.format.value !== 'html') {
                        imageModalState.format.value = 'html';
                    }
                    insertRichSnippet(imageModalState.content, snippet);
                    imageModalState.render();
                    setInlineImageStatus('Image inserted.', 'success');
                    closeInlineImageModal();
                })
                .catch(function (error) {
                    imageUploadButton.disabled = false;
                    setInlineImageStatus(error.message || 'The image could not be uploaded.', 'error');
                });
        }

        if (imageModal) {
            Array.prototype.slice.call(imageModal.querySelectorAll('[data-marketplace-inline-image-cancel]')).forEach(function (button) {
                button.addEventListener('click', closeInlineImageModal);
            });
            imageModal.addEventListener('click', function (event) {
                if (event.target === imageModal) {
                    closeInlineImageModal();
                }
            });
            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && !imageModal.hidden) {
                    closeInlineImageModal();
                }
            });
            if (imageFileInput) {
                imageFileInput.addEventListener('change', previewInlineImageFile);
            }
            if (imageUploadButton) {
                imageUploadButton.addEventListener('click', uploadInlineImage);
            }
        }

        function richSnippetFor(type) {
            switch (type) {
                case 'heading':
                    return '<h3>Section heading</h3>';
                case 'blockquote':
                    return '<blockquote>\n<p>Quote or proof point goes here.</p>\n</blockquote>';
                case 'link':
                    return '<a href="https://example.com">Link text</a>';
                case 'list':
                    return '<ul>\n<li>First point</li>\n<li>Second point</li>\n</ul>';
                default:
                    return '';
            }
        }

        function insertRichSnippet(content, snippet) {
            if (!snippet) return;
            var value = content.value || '';
            var start = typeof content.selectionStart === 'number' ? content.selectionStart : value.length;
            var end = typeof content.selectionEnd === 'number' ? content.selectionEnd : start;
            var before = value.slice(0, start);
            var after = value.slice(end);
            var prefix = before && !/\n\n$/.test(before) ? (/\n$/.test(before) ? '\n' : '\n\n') : '';
            var suffix = after && !/^\n\n/.test(after) ? (/^\n/.test(after) ? '\n' : '\n\n') : '';
            var nextValue = before + prefix + snippet + suffix + after;
            var cursor = (before + prefix + snippet).length;
            content.value = nextValue;
            content.focus();
            if (typeof content.setSelectionRange === 'function') {
                content.setSelectionRange(cursor, cursor);
            }
            content.dispatchEvent(new Event('input', { bubbles: true }));
        }

        editors.forEach(function (editor) {
            var format = editor.querySelector('[data-marketplace-rich-format]');
            var content = editor.querySelector('[data-marketplace-rich-content]');
            var preview = editor.querySelector('[data-marketplace-rich-preview]');
            if (!format || !content || !preview) return;

            function render() {
                preview.innerHTML = format.value === 'html'
                    ? sanitizePreviewHtml(content.value)
                    : textToHtml(content.value);
                if (!preview.innerHTML.trim()) {
                    preview.innerHTML = '<p>Preview appears here as you type.</p>';
                }
            }

            Array.prototype.slice.call(editor.querySelectorAll('[data-marketplace-rich-insert]')).forEach(function (button) {
                button.addEventListener('click', function () {
                    var insertType = button.getAttribute('data-marketplace-rich-insert') || '';
                    if (format.value !== 'html') {
                        format.value = 'html';
                    }
                    if (insertType === 'image' && imageModal) {
                        openInlineImageModal({
                            editor: editor,
                            format: format,
                            content: content,
                            render: render
                        });
                        render();
                        return;
                    }
                    insertRichSnippet(content, richSnippetFor(insertType));
                    render();
                });
            });
            format.addEventListener('change', render);
            content.addEventListener('input', render);
            render();
        });
    }

    function initSetupTabs() {
        var groups = Array.prototype.slice.call(document.querySelectorAll('[data-marketplace-setup-tabs]'));
        groups.forEach(function (group) {
            var tabs = Array.prototype.slice.call(group.querySelectorAll('[data-marketplace-setup-tab]'));
            var container = group.nextElementSibling;
            var panels = container
                ? Array.prototype.slice.call(container.querySelectorAll('[data-marketplace-setup-panel]'))
                : [];
            if (!tabs.length || !panels.length) return;

            function activate(tabName, updateUrl) {
                var matched = tabs.some(function (tab) {
                    return tab.getAttribute('data-marketplace-setup-tab') === tabName;
                });
                if (!matched) {
                    tabName = tabs[0].getAttribute('data-marketplace-setup-tab') || 'setup';
                }

                tabs.forEach(function (tab) {
                    var selected = tab.getAttribute('data-marketplace-setup-tab') === tabName;
                    tab.classList.toggle('is-active', selected);
                    tab.setAttribute('aria-selected', selected ? 'true' : 'false');
                });
                panels.forEach(function (panel) {
                    panel.hidden = panel.getAttribute('data-marketplace-setup-panel') !== tabName;
                });

                if (updateUrl && window.history && window.history.replaceState) {
                    var url = new URL(window.location.href);
                    url.searchParams.set('setup_tab', tabName);
                    url.hash = 'setup';
                    window.history.replaceState({}, '', url.toString());
                }
            }

            tabs.forEach(function (tab) {
                tab.addEventListener('click', function (event) {
                    var nextTab = tab.getAttribute('data-marketplace-setup-tab') || 'setup';
                    var isFinanceSetup = container && container.classList.contains('marketplace-finance-setup');
                    event.preventDefault();
                    if (isFinanceSetup && typeof window.marketplaceFinanceSetupFlushCurrent === 'function') {
                        window.marketplaceFinanceSetupFlushCurrent().then(function () {
                            if (nextTab === 'review') {
                                window.location.href = tab.href;
                                return;
                            }
                            activate(nextTab, true);
                        }).catch(function () {});
                        return;
                    }
                    activate(nextTab, true);
                });
            });

            var params = new URLSearchParams(window.location.search || '');
            var initial = params.get('setup_tab') || '';
            var selected = tabs.find(function (tab) {
                return tab.getAttribute('aria-selected') === 'true';
            });
            activate(initial || (selected ? selected.getAttribute('data-marketplace-setup-tab') : (tabs[0].getAttribute('data-marketplace-setup-tab') || 'setup')), false);
        });
    }

    function initVoiceRecordingAcknowledgement() {
        var form = document.querySelector('[data-vcc-setup-form]');
        if (!form) return;
        var acknowledgement = form.querySelector('[name="voice_compliance_acknowledged"]');
        var status = form.querySelector('[data-vcc-recording-ack-status]');
        if (!acknowledgement || acknowledgement.getAttribute('data-vcc-recording-ack-current') !== '1') return;
        var materialNames = [
            'voice_consent_mode', 'voice_consent_notice', 'voice_allowed_country_codes',
            'voice_recording_retention_days', 'voice_transcript_retention_days',
            'voice_transcription_enabled', 'voice_automation_call_summary',
            'voice_automation_contact_context', 'voice_automation_follow_up_tasks',
            'voice_automation_deal_stage', 'voice_automation_customer_voice',
            'voice_automation_follow_up_messages', 'voice_automation_minimum_confidence'
        ];
        materialNames.forEach(function (name) {
            var field = form.querySelector('[name="' + name + '"]');
            if (!field) return;
            field.addEventListener('change', function () {
                acknowledgement.checked = false;
                acknowledgement.setAttribute('data-vcc-recording-ack-current', '0');
                if (status) status.textContent = 'This policy changed. A workspace owner must review it and check the acknowledgement again before recording can remain enabled.';
            }, { once: true });
        });
    }

    function initVoiceSetupUx() {
        var form = document.querySelector('[data-vcc-setup-form]');
        if (!form) return;
        var canManageVoice = form.getAttribute('data-vcc-can-manage') === '1';
        var activeTab = document.querySelector('[data-marketplace-setup-tabs] [aria-selected="true"]');
        if (activeTab) {
            var tabList = activeTab.closest('[data-marketplace-setup-tabs]');
            window.setTimeout(function () {
                if (tabList) {
                    tabList.scrollLeft = Math.max(0, activeTab.offsetLeft - ((tabList.clientWidth - activeTab.offsetWidth) / 2));
                }
                var navbar = document.querySelector('.navbar');
                var safeTop = navbar ? navbar.getBoundingClientRect().bottom + 12 : 12;
                var activeRect = activeTab.getBoundingClientRect();
                if (activeRect.top < safeTop) {
                    window.scrollBy(0, activeRect.top - safeTop);
                }
            }, 180);
        }

        var copyStatus = form.querySelector('[data-vcc-copy-status]');
        Array.prototype.slice.call(form.querySelectorAll('[data-vcc-copy]')).forEach(function (button) {
            button.addEventListener('click', function () {
                var input = button.parentElement ? button.parentElement.querySelector('input[readonly]') : null;
                if (!input) return;
                var copied = function () {
                    if (copyStatus) copyStatus.textContent = 'Callback URL copied.';
                    var original = button.innerHTML;
                    button.innerHTML = '<i class="fas fa-check" aria-hidden="true"></i> Copied';
                    window.setTimeout(function () { button.innerHTML = original; }, 1600);
                };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(input.value).then(copied).catch(function () {
                        input.select();
                        if (document.execCommand('copy')) copied();
                    });
                    return;
                }
                input.select();
                if (document.execCommand('copy')) copied();
            });
        });

        var fallbackAction = form.querySelector('[data-vcc-fallback-action]');
        var fallbackFields = Array.prototype.slice.call(form.querySelectorAll('[data-vcc-fallback-field]'));
        function syncFallbackFields() {
            if (!fallbackAction) return;
            fallbackFields.forEach(function (field) {
                var visible = field.getAttribute('data-vcc-fallback-field') === fallbackAction.value;
                field.hidden = !visible;
                Array.prototype.slice.call(field.querySelectorAll('input,select')).forEach(function (control) {
                    control.disabled = !canManageVoice || !visible;
                });
            });
        }
        if (fallbackAction) {
            fallbackAction.addEventListener('change', syncFallbackFields);
            syncFallbackFields();
        }

        var businessDays = form.querySelector('[data-vcc-business-days]');
        Array.prototype.slice.call(form.querySelectorAll('[data-vcc-weekday]')).forEach(function (button) {
            button.addEventListener('click', function () {
                var active = button.getAttribute('aria-pressed') !== 'true';
                button.setAttribute('aria-pressed', active ? 'true' : 'false');
                button.classList.toggle('is-active', active);
                if (!businessDays) return;
                businessDays.value = Array.prototype.slice.call(form.querySelectorAll('[data-vcc-weekday][aria-pressed="true"]'))
                    .map(function (dayButton) { return dayButton.getAttribute('data-vcc-weekday'); })
                    .join(',');
            });
        });

        var agentUser = form.querySelector('[data-vcc-agent-user]');
        var agentName = form.querySelector('[name="voice_agent_display_name"]');
        var agentType = form.querySelector('[name="voice_agent_endpoint_type"]');
        var agentEndpoint = form.querySelector('[name="voice_agent_endpoint"]');
        var agentEnabled = form.querySelector('[name="voice_agent_enabled"]');
        var agentHelp = form.querySelector('[data-vcc-agent-endpoint-help]');
        function syncAgentFields() {
            if (!agentUser) return;
            var option = agentUser.options[agentUser.selectedIndex];
            var configured = option && option.getAttribute('data-configured') === '1';
            var endpointType = option ? (option.getAttribute('data-endpoint-type') || 'phone') : 'phone';
            if (agentName) agentName.value = option ? (option.getAttribute('data-display-name') || '') : '';
            if (agentType) agentType.value = endpointType;
            if (agentEnabled) agentEnabled.checked = !option || option.getAttribute('data-enabled') !== '0';
            if (agentEndpoint) {
                agentEndpoint.value = '';
                agentEndpoint.placeholder = configured
                    ? 'Leave blank to keep ' + (option.getAttribute('data-endpoint-masked') || 'saved endpoint')
                    : (endpointType === 'sip' ? 'sip:user@domain' : '+254...');
            }
            if (agentHelp) agentHelp.textContent = configured ? 'Optional when updating; blank keeps the encrypted saved endpoint.' : 'Required for a new agent.';
        }
        if (agentUser) {
            agentUser.addEventListener('change', syncAgentFields);
            if (agentType) agentType.addEventListener('change', function () {
                if (!agentEndpoint || !agentUser) return;
                var option = agentUser.options[agentUser.selectedIndex];
                if (!option || option.getAttribute('data-configured') !== '1') {
                    agentEndpoint.placeholder = agentType.value === 'sip' ? 'sip:user@domain' : '+254...';
                }
            });
        }
    }

    function initFinanceSetupRows() {
        var timers = new WeakMap();
        var savePromises = new WeakMap();

        function autosaveStatus(form, text, state) {
            var status = form ? form.querySelector('[data-finance-autosave-status]') : null;
            if (!status) return;
            status.textContent = text;
            status.classList.toggle('is-saving', state === 'saving');
            status.classList.toggle('is-error', state === 'error');
        }

        function markDirty(form) {
            if (!form || form.getAttribute('data-finance-autosave') !== '1') return;
            form.setAttribute('data-finance-dirty', '1');
            autosaveStatus(form, 'Unsaved', 'dirty');
        }

        function autosaveForm(form) {
            if (!form || form.getAttribute('data-finance-autosave') !== '1') {
                return Promise.resolve({ success: true });
            }
            if (form.getAttribute('data-finance-saving') === '1') {
                form.setAttribute('data-finance-pending', '1');
                return savePromises.get(form) || Promise.resolve({ success: true });
            }

            form.setAttribute('data-finance-saving', '1');
            form.removeAttribute('data-finance-pending');
            autosaveStatus(form, 'Saving', 'saving');

            var payload = new FormData(form);
            payload.set('skill_action', 'save_finance_setup');
            payload.set('autosave', '1');

            var promise = fetch(form.getAttribute('action') || window.location.href, {
                method: 'POST',
                body: payload,
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            }).then(function (response) {
                return response.json().catch(function () {
                    return {
                        success: response.ok,
                        message: response.ok ? 'Saved' : 'Not saved'
                    };
                });
            }).then(function (data) {
                if (!data || data.success === false) {
                    throw new Error((data && data.message) || 'Not saved');
                }
                autosaveStatus(form, data.message || 'Saved', 'saved');
                form.removeAttribute('data-finance-dirty');
                return data;
            }).catch(function (error) {
                autosaveStatus(form, error && error.message ? error.message : 'Not saved', 'error');
                throw error;
            }).finally(function () {
                form.removeAttribute('data-finance-saving');
                savePromises.delete(form);
                if (form.getAttribute('data-finance-pending') === '1') {
                    form.removeAttribute('data-finance-pending');
                    return autosaveForm(form);
                }
            });

            savePromises.set(form, promise);
            return promise;
        }

        function scheduleAutosave(form, delay) {
            if (!form || form.getAttribute('data-finance-autosave') !== '1') return;
            markDirty(form);
            if (timers.has(form)) {
                window.clearTimeout(timers.get(form));
            }
            timers.set(form, window.setTimeout(function () {
                timers.delete(form);
                autosaveForm(form);
            }, delay));
        }

        function flushAutosave(form) {
            if (!form || form.getAttribute('data-finance-autosave') !== '1') {
                return Promise.resolve({ success: true });
            }
            if (timers.has(form)) {
                window.clearTimeout(timers.get(form));
                timers.delete(form);
            }
            if (form.getAttribute('data-finance-saving') === '1') {
                form.setAttribute('data-finance-pending', '1');
                return savePromises.get(form) || Promise.resolve({ success: true });
            }
            if (form.getAttribute('data-finance-dirty') !== '1') {
                return Promise.resolve({ success: true });
            }
            return autosaveForm(form);
        }

        window.marketplaceFinanceSetupFlushCurrent = function () {
            var activePanel = document.querySelector('.marketplace-finance-setup [data-marketplace-setup-panel]:not([hidden])');
            var form = activePanel ? activePanel.querySelector('[data-finance-autosave="1"]') : null;
            return flushAutosave(form);
        };

        document.querySelectorAll('[data-finance-autosave="1"]').forEach(function (form) {
            autosaveStatus(form, 'Saved', 'saved');
            form.addEventListener('submit', function (event) {
                event.preventDefault();
                autosaveForm(form);
            });
            form.addEventListener('input', function (event) {
                if (event.target && event.target.matches('input, textarea')) {
                    scheduleAutosave(form, 700);
                }
            });
            form.addEventListener('change', function () {
                scheduleAutosave(form, 150);
            });
        });

        document.querySelectorAll('[data-finance-none]').forEach(function (checkbox) {
            var groupName = checkbox.getAttribute('data-finance-none') || '';
            var panel = checkbox.closest('[data-marketplace-setup-panel]') || document;
            var group = panel.querySelector('[data-finance-row-group="' + groupName + '"]');
            var tableWrap = group ? group.closest('.finance-setup-table-wrap') : null;
            var addButton = panel.querySelector('[data-finance-add-row="' + groupName + '"]');

            function syncNoneState() {
                var checked = checkbox.checked;
                if (tableWrap) {
                    tableWrap.hidden = checked;
                }
                if (addButton) {
                    addButton.hidden = checked;
                }
                if (group) {
                    group.querySelectorAll('input, select, textarea').forEach(function (field) {
                        field.disabled = checked;
                    });
                }
            }

            checkbox.addEventListener('change', function () {
                syncNoneState();
                scheduleAutosave(checkbox.closest('form'), 150);
            });
            syncNoneState();
        });

        document.querySelectorAll('[data-finance-add-row]').forEach(function (button) {
            button.addEventListener('click', function () {
                var groupName = button.getAttribute('data-finance-add-row') || '';
                var group = document.querySelector('[data-finance-row-group="' + groupName + '"]');
                if (!group) return;
                var rows = Array.prototype.slice.call(group.querySelectorAll('[data-finance-row]'));
                var source = rows[rows.length - 1];
                if (!source) return;
                var nextIndex = rows.length;
                var clone = source.cloneNode(true);
                clone.querySelectorAll('input, select, textarea').forEach(function (field) {
                    if (field.name) {
                        field.name = field.name.replace(/\[\d+\]/, '[' + nextIndex + ']');
                    }
                    if (field.type === 'checkbox') {
                        field.checked = false;
                    } else if (field.tagName === 'SELECT') {
                        field.selectedIndex = 0;
                    } else {
                        field.value = '';
                    }
                });
                group.appendChild(clone);
                scheduleAutosave(button.closest('form'), 250);
            });
        });
    }

    function initSetupGuideModal() {
        var opener = document.querySelector('[data-marketplace-setup-video-open]');
        var modal = document.querySelector('[data-marketplace-setup-video-modal]');
        if (!opener || !modal) return;

        var closeButton = modal.querySelector('[data-marketplace-setup-video-close]');
        var video = modal.querySelector('[data-marketplace-setup-video]');
        var lastFocused = null;
        var previousOverflow = '';

        function openModal() {
            lastFocused = document.activeElement;
            previousOverflow = document.body.style.overflow;
            if (modal.parentNode !== document.body) {
                document.body.appendChild(modal);
            }
            modal.hidden = false;
            modal.scrollTop = 0;
            document.body.style.overflow = 'hidden';
            if (video) {
                try { video.currentTime = 0; } catch (error) {}
                var playAttempt = video.play();
                if (playAttempt && typeof playAttempt.catch === 'function') {
                    playAttempt.catch(function () {});
                }
            }
            if (closeButton) {
                closeButton.focus({ preventScroll: true });
            }
        }

        function closeModal() {
            modal.hidden = true;
            document.body.style.overflow = previousOverflow;
            if (video) {
                video.pause();
                try { video.currentTime = 0; } catch (error) {}
            }
            if (lastFocused && typeof lastFocused.focus === 'function') {
                lastFocused.focus({ preventScroll: true });
            }
        }

        opener.addEventListener('click', openModal);
        if (closeButton) {
            closeButton.addEventListener('click', closeModal);
        }
        modal.addEventListener('click', function (event) {
            if (event.target === modal) closeModal();
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !modal.hidden) closeModal();
        });
    }

    function marketplaceText(value, fallback) {
        value = value == null ? '' : String(value).trim();
        return value !== '' ? value : fallback;
    }

    function marketplaceHref(value, fallback) {
        value = marketplaceText(value, fallback);
        if (/^\s*javascript:/i.test(value)) return fallback;
        return value;
    }

    function renderAsyncStrip(container, options) {
        var items = Array.isArray(options.items) ? options.items : [];
        container.innerHTML = '';
        if (!items.length) {
            container.hidden = true;
            return;
        }

        var main = document.createElement('div');
        main.className = 'marketplace-guided-main';
        var eyebrow = document.createElement('p');
        eyebrow.className = 'marketplace-eyebrow';
        eyebrow.textContent = options.eyebrow;
        var heading = document.createElement('h2');
        heading.textContent = options.heading;
        var copy = document.createElement('p');
        copy.textContent = options.copy;
        main.appendChild(eyebrow);
        main.appendChild(heading);
        main.appendChild(copy);
        container.appendChild(main);

        var list = document.createElement('div');
        list.className = 'marketplace-guided-recommendations';
        items.forEach(function (item) {
            var card = document.createElement('a');
            card.className = 'marketplace-guided-recommendation marketplace-recommendation-cta';
            card.href = marketplaceHref(item.href, 'workspace_skills.php');
            if (item.skillKey) {
                card.setAttribute('data-marketplace-skill-key', item.skillKey);
            }
            if (item.bundleKey) {
                card.setAttribute('data-marketplace-activation-bundle-key', item.bundleKey);
            }
            card.setAttribute('data-marketplace-setup-label', item.title);
            card.setAttribute('data-marketplace-setup-journey-open', item.opensSetupJourney ? '1' : '0');

            var title = document.createElement('strong');
            title.textContent = item.title;
            var body = document.createElement('span');
            body.textContent = item.body;
            card.appendChild(title);
            card.appendChild(body);
            if (item.meta) {
                var meta = document.createElement('span');
                meta.textContent = item.meta;
                card.appendChild(meta);
            }
            list.appendChild(card);
        });
        container.appendChild(list);
        container.hidden = false;
    }

    function hydrateRecommendations() {
        var container = document.querySelector('[data-marketplace-async-recommendations]');
        if (!container || !window.fetch) return;
        window.fetch('../api/workspace/marketplace_recommendations.php?surface=marketplace', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (response) {
                return response.ok ? response.json() : null;
            })
            .then(function (payload) {
                var recommendations = payload && payload.success && Array.isArray(payload.recommendations)
                    ? payload.recommendations
                    : [];
                renderAsyncStrip(container, {
                    eyebrow: 'Recommended next',
                    heading: 'Recommended modules',
                    copy: 'Shortlisted from this workspace.',
                    items: recommendations.slice(0, 3).map(function (rec) {
                        var skillKey = marketplaceText(rec.skill_key, '');
                        return {
                            title: marketplaceText(rec.label, 'Marketplace module'),
                            body: marketplaceText(rec.why_now || rec.expected_benefit, 'Recommended from this workspace context.'),
                            meta: marketplaceText(rec.priority, ''),
                            href: marketplaceHref(rec.next_action_url || rec.setup_url, skillKey ? 'workspace_skills.php?module=' + encodeURIComponent(skillKey) : 'workspace_skills.php'),
                            skillKey: skillKey,
                            opensSetupJourney: !!rec.is_installed
                        };
                    })
                });
            })
            .catch(function () {
                container.hidden = true;
            });
    }

    function setStatusClass(element, className) {
        if (!element) return;
        ['is-muted', 'is-locked', 'is-required', 'is-needs-setup', 'is-installed', 'is-warning', 'is-good'].forEach(function (name) {
            element.classList.remove(name);
        });
        if (className) {
            className.split(/\s+/).forEach(function (name) {
                if (name) element.classList.add(name);
            });
        }
    }

    function hydrateCatalogStatus() {
        if (isProtectedDemoMarketplace) return;
        if (!window.fetch || !cards.length) return;
        window.fetch('../api/workspace/marketplace_catalog_status.php', {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (response) {
                return response.ok ? response.json() : null;
            })
            .then(function (payload) {
                var modules = payload && payload.success && payload.modules ? payload.modules : {};
                var counts = payload && payload.success && payload.counts ? payload.counts : {};
                Object.keys(counts).forEach(function (key) {
                    var target = document.querySelector('[data-marketplace-count="' + key + '"]');
                    if (target) {
                        target.textContent = String(counts[key]);
                    }
                    if (key === 'setup_needed' && target) {
                        var metric = target.closest('.marketplace-dashboard-metric');
                        if (metric) {
                            metric.classList.toggle('is-warning', Number(counts[key]) > 0);
                            metric.classList.toggle('is-good', Number(counts[key]) <= 0);
                        }
                    }
                });
                cards.forEach(function (card) {
                    var link = card.querySelector('[data-marketplace-skill-key]');
                    var skillKey = link ? (link.getAttribute('data-marketplace-skill-key') || '') : '';
                    var state = skillKey ? modules[skillKey] : null;
                    if (!state) return;

                    var locked = !!state.is_locked;
                    var installed = !!state.is_installed;
                    var status = card.querySelector('[data-marketplace-card-status]');
                    var readiness = card.querySelector('[data-marketplace-card-readiness]');

                    marketplaceAccessData[skillKey] = state.access || marketplaceAccessData[skillKey] || {};
                    card.setAttribute('data-marketplace-installed', installed ? '1' : '0');
                    card.setAttribute('data-marketplace-access-state', state.access_state || '');
                    card.setAttribute('data-marketplace-access-locked', locked ? '1' : '0');
                    if (link) {
                        link.setAttribute('data-marketplace-access-locked', locked ? '1' : '0');
                    }

                    if (status) {
                        status.textContent = state.status_text || 'Available';
                        setStatusClass(status, state.status_class || '');
                    }

                    if (readiness) {
                        readiness.textContent = state.readiness_text || '';
                        setStatusClass(readiness, state.readiness_class || '');
                        readiness.hidden = !state.show_readiness;
                    }
                });
                applyFilters();
            })
            .catch(function () {});
    }

    function moduleStatusLabel(readiness, installed) {
        var status = marketplaceText(readiness && readiness.status, installed ? 'installed' : 'available');
        if (readiness && readiness.ready && (status === 'warning' || status === 'sending_ready')) return 'Sending ready';
        if (readiness && readiness.ready) return 'Ready';
        return status.replace(/_/g, ' ').replace(/\b\w/g, function (letter) {
            return letter.toUpperCase();
        });
    }

    function moduleStatusClass(readiness) {
        var status = marketplaceText(readiness && readiness.status, '');
        if (readiness && readiness.ready && (status === 'warning' || status === 'sending_ready')) return 'is-warning';
        if (readiness && readiness.ready) return 'is-installed';
        return 'is-needs-setup';
    }

    function renderModulePills(container, readiness, installed) {
        if (!container) return;
        container.innerHTML = '';
        var checks = readiness && Array.isArray(readiness.checks) ? readiness.checks : [];
        var pills = [
            { label: installed ? 'Installed' : 'Not installed', ready: !!installed },
            { label: moduleStatusLabel(readiness || {}, installed), ready: !!(readiness && readiness.ready) }
        ];
        checks.slice(0, 4).forEach(function (check) {
            pills.push({
                label: marketplaceText(check.label, 'Setup check'),
                ready: !!check.ok
            });
        });
        pills.slice(0, 6).forEach(function (pill) {
            var span = document.createElement('span');
            span.className = 'marketplace-setup-check-pill ' + (pill.ready ? 'is-ready' : 'is-needed');
            span.textContent = pill.label;
            container.appendChild(span);
        });
    }

    function hydrateSelectedModuleDetail() {
        if (!selectedModuleKey || !window.fetch) return;
        window.fetch('../api/workspace/marketplace_module_detail.php?module=' + encodeURIComponent(selectedModuleKey), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (response) {
                return response.ok ? response.json() : null;
            })
            .then(function (payload) {
                if (!payload || !payload.success) return;
                var readiness = payload.readiness || {};
                var installed = !!payload.installed;
                var ready = !!readiness.ready;
                var label = moduleStatusLabel(readiness, installed);
                var message = marketplaceText(readiness.message, ready ? 'Setup is ready for this workspace.' : 'Open setup to finish configuration.');

                var installedStatus = document.querySelector('[data-marketplace-module-installed-status]');
                if (installedStatus) {
                    installedStatus.textContent = installed ? 'Installed' : 'Available';
                    setStatusClass(installedStatus, installed ? 'is-installed' : '');
                }

                [
                    document.querySelector('[data-marketplace-module-readiness-status]'),
                    document.querySelector('[data-marketplace-module-status-label]'),
                    document.querySelector('[data-marketplace-module-setup-status]')
                ].forEach(function (status) {
                    if (!status) return;
                    status.textContent = label;
                    setStatusClass(status, moduleStatusClass(readiness));
                });

                var messageTarget = document.querySelector('[data-marketplace-module-status-message]');
                if (messageTarget) {
                    messageTarget.textContent = message;
                }

                renderModulePills(document.querySelector('[data-marketplace-module-status-pills]'), readiness, installed);
                renderModulePills(document.querySelector('[data-marketplace-module-setup-pills]'), readiness, installed);

                var blocker = document.querySelector('[data-marketplace-module-setup-blocker]');
                if (blocker) {
                    var blockers = Array.isArray(readiness.blockers) ? readiness.blockers : [];
                    blocker.textContent = blockers.length ? 'Blocker: ' + blockers[0] : message;
                    blocker.hidden = ready;
                }
            })
            .catch(function () {});
    }

    function hydratePerformancePanel() {
        var panel = document.querySelector('[data-marketplace-performance-panel]');
        var moduleKey = panel ? marketplaceText(panel.getAttribute('data-marketplace-performance-module'), '') : '';
        if (!panel || !moduleKey || !window.fetch) return;

        window.fetch('../api/workspace/marketplace_performance.php?module=' + encodeURIComponent(moduleKey), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (response) {
                return response.ok ? response.json() : null;
            })
            .then(function (payload) {
                if (!payload || !payload.success || !payload.performance) return;
                var performance = payload.performance || {};
                var metrics = performance.metrics || {};
                var runtime = performance.runtime || {};
                var rows = [
                    ['Impressions 30d', metrics.impressions || 0],
                    ['Page views 30d', metrics.module_page_views || 0],
                    ['Catalog clicks 30d', metrics.catalog_clicks || 0],
                    ['Clicks 30d', metrics.clicks || 0],
                    ['Installs 30d', metrics.installs || 0],
                    ['Removals 30d', metrics.removals || 0],
                    ['Active users 30d', metrics.active_users || 0],
                    ['Setup saves 30d', metrics.setup_saves || 0],
                    ['Test attempts 30d', metrics.test_attempts || 0]
                ];

                panel.innerHTML = '';
                var heading = document.createElement('h3');
                heading.textContent = 'Superadmin performance';
                panel.appendChild(heading);

                var grid = document.createElement('div');
                grid.className = 'marketplace-performance-grid';
                rows.forEach(function (row) {
                    var card = document.createElement('div');
                    card.className = 'marketplace-metric-card';
                    var value = document.createElement('strong');
                    value.textContent = String(row[1]);
                    var label = document.createElement('span');
                    label.textContent = row[0];
                    card.appendChild(value);
                    card.appendChild(label);
                    grid.appendChild(card);
                });
                Object.keys(runtime).forEach(function (key) {
                    var card = document.createElement('div');
                    card.className = 'marketplace-metric-card';
                    var value = document.createElement('strong');
                    value.textContent = marketplaceText(runtime[key], 'None');
                    var label = document.createElement('span');
                    label.textContent = key.replace(/_/g, ' ').replace(/\b\w/g, function (letter) { return letter.toUpperCase(); });
                    card.appendChild(value);
                    card.appendChild(label);
                    grid.appendChild(card);
                });
                panel.appendChild(grid);

                var events = Array.isArray(performance.recent_events) ? performance.recent_events : [];
                if (events.length) {
                    var eventWrap = document.createElement('div');
                    eventWrap.style.marginTop = '1rem';
                    var eventTitle = document.createElement('h4');
                    eventTitle.style.margin = '0 0 .55rem';
                    eventTitle.style.color = '#0f172a';
                    eventTitle.style.fontSize = '1rem';
                    eventTitle.textContent = 'Recent marketplace activity';
                    var list = document.createElement('div');
                    list.className = 'marketplace-activity-list';
                    events.slice(0, 8).forEach(function (event) {
                        var row = document.createElement('div');
                        row.className = 'marketplace-activity-row';
                        var type = document.createElement('strong');
                        type.textContent = marketplaceText(event.event_type, 'event').replace(/_/g, ' ');
                        var meta = document.createElement('span');
                        meta.textContent = marketplaceText(event.source, 'marketplace') + (event.created_at ? ' - ' + event.created_at : '');
                        row.appendChild(type);
                        row.appendChild(meta);
                        list.appendChild(row);
                    });
                    eventWrap.appendChild(eventTitle);
                    eventWrap.appendChild(list);
                    panel.appendChild(eventWrap);
                }
            })
            .catch(function () {});
    }

    document.addEventListener('click', function (event) {
        var cta = event.target.closest('.marketplace-recommendation-cta');
        if (cta) {
            if (cta.getAttribute('data-marketplace-access-locked') === '1') {
                if (isGuidedDemoVisit) {
                    event.preventDefault();
                    event.stopPropagation();
                    return;
                }
                event.preventDefault();
                event.stopPropagation();
                openMarketplaceAccessModal(cta.getAttribute('data-marketplace-skill-key') || '');
                return;
            }
            trackRecommendationClick(cta);
            trackActivationBundleClick(cta);
            trackSetupJourneyOpen(cta);
        }
    });

    initCardPreviews();
    initAccessRequirementsModal();
    initCatalogVideoModal();
    applyFilters();
    initSideRailDisclosure();
    initRichCatalogEditors();
    initModuleTabs();
    initSetupTabs();
    initVoiceRecordingAcknowledgement();
    initVoiceSetupUx();
    initFinanceSetupRows();
    initSetupGuideModal();
    hydrateRecommendations();
    hydrateCatalogStatus();
    hydrateSelectedModuleDetail();
    hydratePerformancePanel();
    trackModulePageView();
})();
</script>

<?php echo PageGuideVideoUi::modal(MarketplacePageExplainerService::PAGE_MARKETPLACE, 'How to use Marketplace', $marketplaceGuideVideoUrl); ?>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
