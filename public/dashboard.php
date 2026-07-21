<?php
/**
 * Dashboard Page
 */

// Enable error reporting for debugging (will be caught by error handler)
error_reporting(E_ALL);
ini_set('display_errors', 0); // Don't display errors directly, use our handler
ini_set('log_errors', 1);

// Set up error handler to catch fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        $showDebug = (($_ENV['APP_ENV'] ?? 'production') === 'development')
            || filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL)
            || filter_var($_ENV['DEBUG'] ?? false, FILTER_VALIDATE_BOOL);
        
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        echo "<!DOCTYPE html><html><head><title>500 Internal Server Error</title></head><body>";
        echo "<h1>500 Internal Server Error</h1>";
        echo "<p>A fatal error occurred while loading the dashboard.</p>";
        if ($showDebug) {
            echo "<p><strong>Error:</strong> " . htmlspecialchars($error['message']) . "</p>";
            echo "<p><strong>File:</strong> " . htmlspecialchars($error['file']) . ":" . $error['line'] . "</p>";
            echo "<p><strong>Type:</strong> " . $error['type'] . "</p>";
        } else {
            echo "<p>Please contact the administrator or try again later.</p>";
        }
        echo "</body></html>";
        exit;
    }
});

// Try to load autoloader with error handling
try {
    $autoloadPath = __DIR__ . '/../vendor/autoload.php';
    if (!file_exists($autoloadPath)) {
        throw new \RuntimeException("Composer autoloader not found at: $autoloadPath. Please run 'composer install'.");
    }
    require_once $autoloadPath;
} catch (\Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html><html><head><title>500 Internal Server Error</title></head><body>";
    echo "<h1>500 Internal Server Error</h1>";
    echo "<p>Failed to load required files.</p>";
    $showDebug = (($_ENV['APP_ENV'] ?? 'production') === 'development')
        || filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL)
        || filter_var($_ENV['DEBUG'] ?? false, FILTER_VALIDATE_BOOL);
    if ($showDebug) {
        echo "<p><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
        echo "<p><strong>File:</strong> " . htmlspecialchars($e->getFile()) . ":" . $e->getLine() . "</p>";
    }
    echo "</body></html>";
    exit;
}

// Load environment
$envFile = __DIR__ . '/../.env';
$envLoaded = false;
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        // Remove quotes if present
        if ((substr($value, 0, 1) === '"' && substr($value, -1) === '"') || 
            (substr($value, 0, 1) === "'" && substr($value, -1) === "'")) {
            $value = substr($value, 1, -1);
        }
        $_ENV[$key] = $value;
        $envLoaded = true;
    }
} else {
    error_log('WARNING: .env file not found at: ' . $envFile);
}

// Try to load constants file with error handling
try {
    $constantsPath = __DIR__ . '/../config/constants.php';
    if (!file_exists($constantsPath)) {
        throw new \RuntimeException("Constants file not found at: $constantsPath");
    }
    require_once $constantsPath;
} catch (\Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html><html><head><title>500 Internal Server Error</title></head><body>";
    echo "<h1>500 Internal Server Error</h1>";
    echo "<p>Failed to load configuration files.</p>";
    $showDebug = (($_ENV['APP_ENV'] ?? 'production') === 'development')
        || filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL)
        || filter_var($_ENV['DEBUG'] ?? false, FILTER_VALIDATE_BOOL);
    if ($showDebug) {
        echo "<p><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
        echo "<p><strong>File:</strong> " . htmlspecialchars($e->getFile()) . ":" . $e->getLine() . "</p>";
    }
    echo "</body></html>";
    exit;
}

use CRM\Database;
use CRM\CacheManager;
use CRM\Session;
use CRM\Auth;
use CRM\Authorization;
use CRM\Security;
use CRM\Modules\AnalyticsDashboard;
use CRM\Modules\Events;
use CRM\Modules\Tasks;
use CRM\Modules\Notes;
use CRM\Modules\Deals;
use CRM\Modules\Currencies;
use CRM\Modules\UserPreferences;
use CRM\Modules\OutcomeMetrics;
use CRM\Services\AnalyticsWorkspaceService;
use CRM\Services\AICoachWorkspaceSetupService;
use CRM\Services\DefaultWorkspaceOperationalizationService;
use CRM\Services\DefaultWorkspaceHealthService;
use CRM\Services\DefaultWorkspaceOpsAutomationService;
use CRM\Services\DefaultWorkspaceOpsEventService;
use CRM\Services\DefaultWorkspaceService;
use CRM\Services\DemoSessionScopeService;
use CRM\Services\FounderCommandCenterAccessService;
use CRM\Services\GuidedDemoSessionService;
use CRM\Services\OutcomeEventService;
use CRM\Services\OutcomeRolloutService;
use CRM\Services\MarketplacePageExplainerService;
use CRM\Services\VideoBrandOverlayUi;
use CRM\Services\PlatformWorkspaceOperationsService;
use CRM\Services\PlainLanguageReadinessService;
use CRM\Services\PresentationWorkspaceGuardService;
use CRM\Services\ProtectedDemoShowcaseProfileService;
use CRM\Services\WorkspaceConnectService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceLaunchChecklistService;
use CRM\Services\WorkspaceOnboardingService;
use CRM\Services\WorkspaceOperatingBriefService;
use CRM\Services\WorkspaceSampleWorkflowService;
use CRM\Services\UIExperienceService;

try {
    $dbConfigPath = __DIR__ . '/../config/database.php';
    if (!file_exists($dbConfigPath)) {
        throw new \RuntimeException("Database config file not found at: $dbConfigPath");
    }
    $dbConfig = require $dbConfigPath;
    Database::init($dbConfig);
    Session::start();
} catch (\Exception $e) {
    error_log('Dashboard initialization error: ' . $e->getMessage());
    $showDebug = (($_ENV['APP_ENV'] ?? 'production') === 'development')
               || filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL)
               || filter_var($_ENV['DEBUG'] ?? false, FILTER_VALIDATE_BOOL);
    
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html><html><head><title>500 Internal Server Error</title></head><body>";
    echo "<h1>500 Internal Server Error</h1>";
    echo "<p>An error occurred while initializing the dashboard.</p>";
    if ($showDebug) {
        echo "<p><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
        echo "<p><strong>File:</strong> " . htmlspecialchars($e->getFile()) . ":" . $e->getLine() . "</p>";
        if (!$envLoaded) {
            echo "<p style='color: #c33;'><strong>WARNING:</strong> .env file not found or not loaded.</p>";
        }
    } else {
        echo "<p>Please contact the administrator or try again later.</p>";
    }
    echo "</body></html>";
    exit;
}

// Get base path for redirects (function defined in config/constants.php)
$basePath = getBasePath();
$dashboardPerfEnabled = (
    isset($_GET['perf_debug'])
    && (
        (($_ENV['APP_ENV'] ?? 'production') === 'development')
        || filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL)
        || filter_var($_ENV['DEBUG'] ?? false, FILTER_VALIDATE_BOOL)
    )
);
$dashboardPerfMarks = [['start', microtime(true)]];
$dashboardPerfMark = static function (string $label) use (&$dashboardPerfMarks, $dashboardPerfEnabled): void {
    if ($dashboardPerfEnabled) {
        $dashboardPerfMarks[] = [$label, microtime(true)];
    }
};
$dashboardPerfFlush = static function () use (&$dashboardPerfMarks, $dashboardPerfEnabled): void {
    if (!$dashboardPerfEnabled || headers_sent()) {
        return;
    }
    $parts = [];
    for ($i = 1, $count = count($dashboardPerfMarks); $i < $count; $i++) {
        $label = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) $dashboardPerfMarks[$i][0]);
        $duration = max(0, ($dashboardPerfMarks[$i][1] - $dashboardPerfMarks[$i - 1][1]) * 1000);
        $parts[] = $label . ';dur=' . number_format($duration, 1, '.', '');
    }
    $total = max(0, ($dashboardPerfMarks[count($dashboardPerfMarks) - 1][1] - $dashboardPerfMarks[0][1]) * 1000);
    $parts[] = 'dashboard_total;dur=' . number_format($total, 1, '.', '');
    header('Server-Timing: ' . implode(', ', $parts));
};

// Require authentication
if (!Auth::check()) {
    header('Location: ' . Auth::loginUrl(null, Auth::currentAuthState() === 'expired'));
    exit;
}

try {
    $user = Auth::user();
    if (!$user) {
        throw new \RuntimeException('User session not found. Please log in again.');
    }
    
    $analytics = new AnalyticsDashboard();
    $analyticsWorkspace = new AnalyticsWorkspaceService();
    $eventsModule = new Events();
    $tasksModule = new Tasks();
    $notesModule = new Notes();
    $dealsModule = new Deals();
    $currenciesModule = new Currencies();
    $userId = (int) ($user['id'] ?? 0);
    $workspaceId = $analyticsWorkspace->requireAnalyticsWorkspaceId();
    $isProtectedDemoDashboard = false;
    $isPresentationDashboard = false;
    $protectedDemoProfileKey = 'riverside';
    try {
        $isProtectedDemoDashboard = (new DemoSessionScopeService())->activeSession($workspaceId) !== null;
        if ($isProtectedDemoDashboard) {
            $protectedDemoProfileKey = (new \CRM\Services\DemoWorkspaceService())->profileKey($workspaceId);
        }
    } catch (\Throwable $e) {
        $isProtectedDemoDashboard = false;
        $protectedDemoProfileKey = 'riverside';
    }
    try {
        $isPresentationDashboard = (new PresentationWorkspaceGuardService())->isPresentationWorkspace($workspaceId);
    } catch (\Throwable $e) {
        $isPresentationDashboard = false;
    }
    $protectedDemoShowcase = $isProtectedDemoDashboard ? new ProtectedDemoShowcaseProfileService() : null;
    $dashboardExperienceMode = (new UIExperienceService())->modeForUser($user, $workspaceId);
    $dashboardActionError = '';
    $dashboardActionNotice = '';
    $dashboardExplainer = (new MarketplacePageExplainerService())->getActive(MarketplacePageExplainerService::PAGE_DASHBOARD);
    $dashboardExplainerVideo = trim((string) ($dashboardExplainer['video_url'] ?? ''));
    $dashboardPerfMark('workspace_ready');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string) ($_POST['action'] ?? '');
        if (in_array($action, ['create_sample_workflow', 'remove_sample_workflow'], true)) {
            try {
                if (!Security::validateCSRF((string) ($_POST['csrf_token'] ?? ''))) {
                    throw new \RuntimeException('Security check failed. Refresh the dashboard and try again.');
                }

                (new WorkspaceConnectService())->requireWorkspaceAdmin($user, $workspaceId);

                $sampleService = new WorkspaceSampleWorkflowService();
                if ($action === 'create_sample_workflow') {
                    $sampleService->create($workspaceId, $userId);
                    (new CacheManager())->delete('dashboard_setup_cards:v1:' . $workspaceId);
                    header('Location: dashboard.php?sample_workflow=created');
                    exit;
                }

                $sampleService->remove($workspaceId, $userId);
                (new CacheManager())->delete('dashboard_setup_cards:v1:' . $workspaceId);
                header('Location: dashboard.php?sample_workflow=removed');
                exit;
            } catch (\Throwable $e) {
                $dashboardActionError = $e->getMessage();
            }
        }
        if (in_array($action, ['transition_default_workspace_ops_event', 'automate_default_workspace_ops_event', 'run_default_workspace_ops_automation'], true)) {
            try {
                if (!Security::validateCSRF((string) ($_POST['csrf_token'] ?? ''))) {
                    throw new \RuntimeException('Security check failed. Refresh the dashboard and try again.');
                }
                if (!Authorization::isSuperAdmin($user) || !WorkspaceContext::isDefaultWorkspace($workspaceId)) {
                    throw new \RuntimeException('Only Super Admin can manage default workspace ops events.');
                }
                $opsEventService = new DefaultWorkspaceOpsEventService();
                if ($action === 'run_default_workspace_ops_automation') {
                    $result = (new DefaultWorkspaceOpsAutomationService())->run($userId, 50, true, !empty($_POST['force']));
                    $query = http_build_query([
                        'platform_ops_automation' => 'ran',
                        'sent' => (int) ($result['sent'] ?? 0),
                        'skipped' => (int) ($result['skipped'] ?? 0),
                        'failed' => (int) ($result['failed'] ?? 0),
                        'human_only' => (int) ($result['human_only'] ?? 0),
                    ]);
                    header('Location: dashboard.php?' . $query);
                    exit;
                }
                if ($action === 'automate_default_workspace_ops_event') {
                    $result = (new DefaultWorkspaceOpsAutomationService())->automateEvent(
                        (int) ($_POST['event_id'] ?? 0),
                        $userId,
                        !empty($_POST['force'])
                    );
                    header('Location: dashboard.php?platform_ops_automation=' . rawurlencode((string) ($result['status'] ?? 'updated')) . '&event_id=' . (int) ($_POST['event_id'] ?? 0));
                    exit;
                }

                $opsEventService->transitionEvent(
                    (int) ($_POST['event_id'] ?? 0),
                    (string) ($_POST['event_status'] ?? ''),
                    $userId,
                    (string) ($_POST['reason'] ?? ''),
                    (string) ($_POST['resolution_summary'] ?? '')
                );
                header('Location: dashboard.php?platform_ops_events=updated');
                exit;
            } catch (\Throwable $e) {
                $dashboardActionError = $e->getMessage();
            }
        }
    }

    // Everything below is read-only dashboard assembly. Release the file-session
    // write lock so same-user dashboard/API requests can execute concurrently.
    Session::closeWrite();

    $canViewAllAnalytics = Authorization::can('analytics.view_all', $user);
    $canUseDashboardFilter = Authorization::can('feature.dashboard_filter', $user);
    $hasSelectedUserParam = array_key_exists('user_id', $_GET);
    $selectedUserId = null;
    if (!$hasSelectedUserParam) {
        $selectedUserId = $userId > 0 ? $userId : null;
    } elseif ($canViewAllAnalytics && ($_GET['user_id'] ?? '') === '') {
        $selectedUserId = null;
    } elseif ($canViewAllAnalytics && ctype_digit((string) ($_GET['user_id'] ?? ''))) {
        $selectedUserId = $analyticsWorkspace->ensureScopedUserId((int) $_GET['user_id'], $workspaceId);
    } else {
        $selectedUserId = $userId > 0 ? $userId : null;
    }
    $allUsers = $analyticsWorkspace->listActiveWorkspaceUsers($workspaceId);
    $selectedUserLabel = 'My Dashboard';
    if ($selectedUserId === null) {
        $selectedUserLabel = 'All Users';
    } elseif ($selectedUserId !== $userId) {
        foreach ($allUsers as $scopeUser) {
            if ((int) ($scopeUser['id'] ?? 0) === $selectedUserId) {
                $selectedUserLabel = trim((string) (($scopeUser['first_name'] ?? '') . ' ' . ($scopeUser['last_name'] ?? '')));
                if ($selectedUserLabel === '') {
                    $selectedUserLabel = (string) ($scopeUser['email'] ?? 'Selected User');
                }
                break;
            }
        }
    }
    if ($isProtectedDemoDashboard) {
        $selectedUserId = null;
        $selectedUserLabel = $protectedDemoProfileKey === 'metrodrive'
            ? 'MetroDrive Academy'
            : 'Riverside Demo Workspace';
        $canUseDashboardFilter = false;
        $canViewAllAnalytics = false;
    }
    $outcomeRollout = new OutcomeRolloutService();
    $outcomeEventService = new OutcomeEventService();
    $outcomeMetricsModule = new OutcomeMetrics();
    $hasLegacyAutomationReadiness = \CRM\Authorization::can('feature.automation_readiness');
    $showAutomationBattery = \CRM\Authorization::can('feature.automation_battery') || $hasLegacyAutomationReadiness;
    $showAutomationReadinessCard = \CRM\Authorization::hasAccessProfilePermission('feature.automation_readiness_card', $user);
    $automationBattery = [
        'score' => 0,
        'bucket' => 'pending',
        'status_label' => 'Ready to check',
        'headline_label' => 'Not checked yet',
        'mode_label' => 'Not checked yet',
        'summary' => 'No background calculation is running. Check this when you want a fresh automation score and next setup moves.',
        'setup_progress_label' => '',
        'signal_title' => 'Ready when you are',
        'signal_copy' => 'Check once to see the current score, strongest signals, required actions, and optional enrichments.',
        'top_blockers' => [],
        'top_boosters' => ['Fresh score', 'Actions to review', 'Strong signals'],
        'top_actions' => [],
        'top_progress_signals' => [],
        'context_enrichments' => [],
        'attention_counts' => [
            'required_actions' => 0,
            'recommended_actions' => 0,
            'optional_enrichments' => 0,
            'progress_signals' => 0,
            'failed_jobs' => 0,
            'stale_jobs' => 0,
            'running_jobs' => 0,
            'healthy_jobs' => 0,
            'total_jobs' => 0,
        ],
        'health_summary' => [
            'status' => 'building',
            'tone' => 'building',
            'label' => 'Ready when you are',
            'message' => 'Check once to see automation health, required actions, learning signals, and worker status.',
            'last_checked_label' => 'Not checked yet',
            'refresh_state_label' => 'Not checked yet.',
            'next_refresh_at' => null,
            'no_setup_checks_waiting' => false,
        ],
        'refresh_state_label' => 'Not checked yet.',
        'is_visible' => true,
        'layers' => [],
    ];
    $automationBatterySubjectUserId = $selectedUserId ?? $userId;
    if ($selectedUserId === null) {
        $automationBatterySubjectLabel = 'My Automation Readiness while viewing All Users';
    } else {
        $automationBatterySubjectLabel = $automationBatterySubjectUserId === $userId
            ? 'My Automation Readiness'
            : 'Automation Readiness for ' . $selectedUserLabel;
    }
    $automationBatteryHasSnapshot = false;
    $automationBatteryRefreshPolicy = [
        'cadence' => 'six_hours',
        'key' => 'six_hours',
        'label' => 'Every 6 hours',
        'seconds' => 21600,
        'automatic_enabled' => true,
        'manual_throttle_seconds' => 60,
    ];
    // The automation battery is hydrated by dashboard/automation_battery.php after first paint.
    // Keeping its policy and snapshot queries off this read path prevents optional diagnostics
    // from delaying every dashboard document response.
    if ($protectedDemoShowcase !== null) {
        $showAutomationBattery = true;
        $showAutomationReadinessCard = false;
        $automationBattery = array_replace(
            $automationBattery,
            $protectedDemoShowcase->automationBatteryStatus($automationBatterySubjectUserId)
        );
        $automationBatteryHasSnapshot = true;
    }
    if ($isPresentationDashboard) {
        $showAutomationBattery = false;
        $showAutomationReadinessCard = false;
    }
    $automationBatteryUpdatedLabel = (string) ($automationBattery['updated_label'] ?? '');
    $automationBatteryLastCheckedLabel = $automationBatteryUpdatedLabel !== ''
        ? preg_replace('/^Updated\b/i', 'Last checked', $automationBatteryUpdatedLabel)
        : '';
    $automationBatteryRefreshSuffix = $automationBatteryLastCheckedLabel !== ''
        ? rtrim((string) $automationBatteryLastCheckedLabel, '.') . '.'
        : 'Not checked yet.';
    $automationBatteryRefreshTooltip = 'Check your automation score. ' . $automationBatteryRefreshSuffix;
    $automationBatteryStatusTooltipMap = [
        'pending' => 'Check your current automation score when you are ready.',
        'low' => 'Your workspace is just getting started. A few basics still need attention.',
        'medium' => 'Your workspace is making progress, but a few important pieces still need strengthening.',
        'high' => 'Most of your workspace is running well. Only a few gaps remain.',
        'full' => 'Your workspace is in strong shape and ready to support more automated work.',
    ];
    $automationBatteryStatusTooltip = $automationBatteryStatusTooltipMap[(string) ($automationBattery['bucket'] ?? 'pending')]
        ?? 'Check your current automation score when you are ready.';
    $automationBatteryIdleRefreshDue = $automationBatteryHasSnapshot
        ? !empty($automationBattery['idle_refresh_due'])
        : !empty($automationBatteryRefreshPolicy['automatic_enabled']);
    $automationBatteryShouldIdleRefresh = ($showAutomationBattery || $showAutomationReadinessCard)
        && $automationBatteryIdleRefreshDue;
    if ($isProtectedDemoDashboard) {
        $automationBatteryShouldIdleRefresh = false;
    }
    $showAutomationHealthBand = $showAutomationReadinessCard && !WorkspaceContext::isDefaultWorkspace($workspaceId);
    $showFounderCommandCenter = (new FounderCommandCenterAccessService())->canView($user, $workspaceId);
    $founderCommandCenterSubjectUserId = $selectedUserId ?? $userId;
    $dashboardPerfMark('access_and_automation');
    $outcomeRolloutEnabled = $outcomeRollout->isEnabledForUser($userId);
    $showOutcomeDashboardCard = !$isPresentationDashboard;
    $outcomeFeatures = [
        'daily_focus' => !$outcomeRolloutEnabled || $outcomeRollout->isFeatureEnabled($userId, 'daily_focus'),
        'checklist' => !$outcomeRolloutEnabled || $outcomeRollout->isFeatureEnabled($userId, 'checklist'),
        'metrics' => !$outcomeRolloutEnabled || $outcomeRollout->isFeatureEnabled($userId, 'metrics'),
    ];
    $outcomeTTFV = ['median_hours' => 0.0, 'p75_hours' => 0.0, 'sample_size' => 0, 'trend_vs_prev_pct' => 0.0];
    $outcomeActivation = ['rate' => 0.0];
    $outcomeRevenueActions = ['rate' => 0.0];
    $initialPlainReadiness = null;
    $plainReadinessActionableHref = static function (string $href): bool {
        $href = trim($href);
        if ($href === '' || $href === '#' || stripos($href, 'javascript:') === 0) {
            return false;
        }

        $parts = parse_url($href);
        $path = is_array($parts) ? (string) ($parts['path'] ?? '') : '';
        if ($path === '') {
            $path = strtok($href, '?#') ?: '';
        }

        return !preg_match('/(^|\/)dashboard\.php$/i', $path);
    };
    $dashboardAppendDestinationContext = static function (string $href, array $params) use ($plainReadinessActionableHref): string {
        $href = trim($href);
        if (!$plainReadinessActionableHref($href)) {
            return $href;
        }

        $params = array_filter($params, static fn($value): bool => trim((string) $value) !== '');
        if ($params === []) {
            return $href;
        }

        $fragment = '';
        $hashPos = strpos($href, '#');
        if ($hashPos !== false) {
            $fragment = substr($href, $hashPos);
            $href = substr($href, 0, $hashPos);
        }

        $queryPos = strpos($href, '?');
        $base = $queryPos === false ? $href : substr($href, 0, $queryPos);
        $existing = [];
        if ($queryPos !== false) {
            parse_str(substr($href, $queryPos + 1), $existing);
        }
        foreach ($params as $key => $value) {
            if (!array_key_exists((string) $key, $existing)) {
                $existing[(string) $key] = $value;
            }
        }

        $query = http_build_query($existing);
        return $base . ($query !== '' ? '?' . $query : '') . $fragment;
    };
    $automationBatteryAppendActionContext = static function (string $href, array $action): string {
        $href = trim($href);
        if ($href === '' || $href === '#' || stripos($href, 'javascript:') === 0) {
            return $href;
        }

        $fragment = '';
        $hashPos = strpos($href, '#');
        if ($hashPos !== false) {
            $fragment = substr($href, $hashPos);
            $href = substr($href, 0, $hashPos);
        }

        $params = array_filter([
            'source' => 'automation_battery',
            'action' => (string) ($action['key'] ?? ''),
            'layer' => (string) ($action['layer_key'] ?? ''),
        ], static fn($value): bool => trim((string) $value) !== '');
        if ($params === []) {
            return $href . $fragment;
        }

        $separator = str_contains($href, '?') ? '&' : '?';
        return $href . $separator . http_build_query($params) . $fragment;
    };
    $automationBatteryActionKindClass = static function (string $kind): string {
        return match ($kind) {
            'required' => 'required',
            'optional_enrichment' => 'optional-enrichment',
            default => 'recommended',
        };
    };
    $automationBatteryRenderActionChip = static function (array $action, string $scope = 'readiness') use ($automationBatteryAppendActionContext, $automationBatteryActionKindClass): string {
        $label = trim((string) ($action['label'] ?? 'Open action'));
        $href = $automationBatteryAppendActionContext((string) ($action['href'] ?? ''), $action);
        $kind = (string) ($action['kind'] ?? 'recommended');
        $kindClass = $automationBatteryActionKindClass($kind);
        $class = $scope === 'layer' ? 'automation-layer-chip' : 'automation-readiness-chip';
        $icon = $kind === 'required' ? 'fa-bolt' : ($kind === 'optional_enrichment' ? 'fa-sparkles' : 'fa-arrow-up-right-from-square');
        $attributes = sprintf(
            ' data-automation-action-key="%s" data-automation-action-layer="%s" data-automation-action-kind="%s" data-automation-action-href="%s"',
            htmlspecialchars((string) ($action['key'] ?? ''), ENT_QUOTES, 'UTF-8'),
            htmlspecialchars((string) ($action['layer_key'] ?? ''), ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($kind, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($href, ENT_QUOTES, 'UTF-8')
        );
        $content = '<i class="fas ' . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') . '" aria-hidden="true"></i>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8');

        if ($href !== '') {
            return '<a class="' . $class . ' ' . $kindClass . '" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '" data-automation-action-link' . $attributes . '>' . $content . '</a>';
        }

        return '<span class="' . $class . ' ' . $kindClass . '"' . $attributes . '>' . $content . '</span>';
    };
    $automationBatteryRenderProgressChip = static function (array $signal, string $scope = 'readiness'): string {
        $label = trim((string) ($signal['label'] ?? $signal['source_blocker'] ?? 'Progress signal'));
        if ($label === '') {
            return '';
        }
        $severity = (string) ($signal['severity'] ?? 'info');
        if (!in_array($severity, ['info', 'warning', 'critical'], true)) {
            $severity = 'info';
        }
        $class = $scope === 'layer' ? 'automation-layer-chip' : 'automation-readiness-chip';
        $icon = $severity === 'critical' ? 'fa-triangle-exclamation' : ($severity === 'warning' ? 'fa-clock' : 'fa-circle-info');
        $attributes = sprintf(
            ' data-automation-progress-key="%s" data-automation-progress-layer="%s" data-automation-progress-severity="%s"',
            htmlspecialchars((string) ($signal['key'] ?? ''), ENT_QUOTES, 'UTF-8'),
            htmlspecialchars((string) ($signal['layer_key'] ?? ''), ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($severity, ENT_QUOTES, 'UTF-8')
        );

        return '<span class="' . $class . ' progress ' . htmlspecialchars($severity, ENT_QUOTES, 'UTF-8') . '"' . $attributes . '><i class="fas ' . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') . '" aria-hidden="true"></i>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
    };
    $dashboardCache = new CacheManager();
    $workspaceSetupImpact = $dashboardCache->getWithCache(
        'dashboard_setup_impact:v3:' . $workspaceId . ':' . $userId,
        static function () use ($workspaceId, $userId): array {
            return (new WorkspaceOnboardingService())->getDashboardSetupImpact($workspaceId, $userId);
        },
        120
    );
    $dashboardPerfMark('setup_impact');
    $platformOpsSummary = null;
    if (Authorization::isSuperAdmin($user) && WorkspaceContext::isDefaultWorkspace($workspaceId)) {
        try {
            $defaultWorkspaceService = new DefaultWorkspaceService();
            $defaultWorkspaceHealth = (new DefaultWorkspaceHealthService($defaultWorkspaceService))->health($userId);
            $opsEventService = new DefaultWorkspaceOpsEventService($defaultWorkspaceService);
            $opsSeverityFilter = array_key_exists('ops_severity', $_GET)
                ? trim((string) ($_GET['ops_severity'] ?? ''))
                : 'critical';
            $opsEventFilters = [
                'status' => trim((string) ($_GET['ops_status'] ?? '')),
                'signal_type' => trim((string) ($_GET['ops_signal_type'] ?? '')),
                'severity' => $opsSeverityFilter,
                'assigned_user_id' => ctype_digit((string) ($_GET['ops_assigned_user_id'] ?? '')) ? (int) $_GET['ops_assigned_user_id'] : null,
            ];
            $opsDashboardAutomation = null;
            $opsDashboardAutomationError = '';
            $opsDashboardAutomationCheckedAt = 0;
            $opsEventRows = $opsEventService->listEvents($opsEventFilters, 25);
            $opsStatus = (array) ($defaultWorkspaceHealth['operationalization_status'] ?? []);
            if ($opsStatus === []) {
                $opsStatus = (new DefaultWorkspaceOperationalizationService())->status($userId);
            }
            $opsDiagnostics = (array) ($opsStatus['diagnostics'] ?? []);
            $identityHealth = (array) ($opsStatus['identity_health'] ?? $defaultWorkspaceService->health());
            $countOne = static function (string $sql, array $params = []): int {
                $row = Database::queryOne($sql, $params) ?: [];
                return (int) ($row['c'] ?? 0);
            };
            $opsEventsTableReady = Database::tableExists('default_workspace_ops_events');
            $opsAutomationReady = $opsEventsTableReady && Database::columnExists('default_workspace_ops_events', 'automation_status');

            $ownerCounts = ['qualified_workspace_lead' => 0, 'current_paying_customer' => 0, 'inactive' => 0];
            if (Database::tableExists('default_workspace_owner_contacts')) {
                foreach (Database::query(
                    "SELECT customer_state, relationship_status, COUNT(*) AS c
                     FROM default_workspace_owner_contacts
                     WHERE default_workspace_id = ?
                     GROUP BY customer_state, relationship_status",
                    [$workspaceId]
                ) as $row) {
                    $state = (string) ($row['customer_state'] ?? '');
                    if ((string) ($row['relationship_status'] ?? '') === 'inactive') {
                        $ownerCounts['inactive'] += (int) ($row['c'] ?? 0);
                    } elseif (isset($ownerCounts[$state])) {
                        $ownerCounts[$state] += (int) ($row['c'] ?? 0);
                    }
                }
            }

            $platformOpsSummary = [
                'score' => (int) ($opsStatus['operational_score'] ?? 0),
                'healthy_checks' => (int) ($opsDiagnostics['healthy_count'] ?? 0),
                'total_checks' => (int) ($opsDiagnostics['total_count'] ?? 0),
                'missing' => array_slice((array) ($opsDiagnostics['missing'] ?? []), 0, 4),
                'ai_context_active' => true,
                'ai_prompt_overrides' => (int) ($opsDiagnostics['components']['ai_prompts']['active_override_count'] ?? 0),
                'ai_prompt_missing' => count((array) ($opsDiagnostics['components']['ai_prompts']['missing'] ?? [])),
                'ai_prompt_customized' => (int) ($opsDiagnostics['components']['ai_prompts']['customized_override_count'] ?? 0),
                'identity_healthy' => !empty($identityHealth['healthy']),
                'identity_warnings' => array_slice(array_merge((array) ($identityHealth['issues'] ?? []), (array) ($identityHealth['warnings'] ?? [])), 0, 3),
                'stuck_onboarding' => Database::tableExists('workspace_onboarding_state') ? $countOne(
                    "SELECT COUNT(*) AS c
                     FROM workspace_onboarding_state wos
                     JOIN workspaces w ON w.id = wos.workspace_id
                     WHERE wos.workspace_id <> ?
                       AND wos.status <> 'completed'
                       AND w.status NOT IN ('archived', 'suspended')",
                    [$workspaceId]
                ) : 0,
                'low_tokens' => Database::tableExists('workspace_wallets') ? $countOne(
                    "SELECT COUNT(*) AS c
                     FROM workspace_wallets
                     WHERE workspace_id <> ?
                       AND (token_balance - reserved_tokens) < 10000",
                    [$workspaceId]
                ) : 0,
                'failed_billing_events' => Database::tableExists('billing_provider_events') ? $countOne(
                    "SELECT COUNT(DISTINCT workspace_id) AS c
                     FROM billing_provider_events
                     WHERE workspace_id IS NOT NULL
                       AND workspace_id <> ?
                       AND processing_status = 'failed'",
                    [$workspaceId]
                ) : 0,
                'channel_gaps' => Database::tableExists('email_integrations') ? $countOne(
                    "SELECT COUNT(*) AS c
                     FROM workspaces w
                     WHERE w.id <> ?
                       AND w.status NOT IN ('archived', 'suspended', 'deleted')
                       AND NOT EXISTS (
                           SELECT 1 FROM email_integrations ei WHERE ei.workspace_id = w.id AND ei.is_active = 1
                       )",
                    [$workspaceId]
                ) : 0,
                'recent_operator_actions' => Database::tableExists('operator_audit_log') ? $countOne(
                    "SELECT COUNT(*) AS c
                     FROM operator_audit_log
                     WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)"
                ) : 0,
                'owner_counts' => $ownerCounts,
                'health_status' => (string) ($defaultWorkspaceHealth['status'] ?? 'warning'),
                'health_metrics' => (array) ($defaultWorkspaceHealth['metrics'] ?? []),
                'health_warnings' => array_slice(array_merge((array) ($defaultWorkspaceHealth['issues'] ?? []), (array) ($defaultWorkspaceHealth['warnings'] ?? [])), 0, 5),
                'ops_events' => $opsEventRows,
                'ops_event_filters' => $opsEventFilters,
                'ops_dashboard_automation' => $opsDashboardAutomation,
                'ops_dashboard_automation_error' => $opsDashboardAutomationError,
                'ops_dashboard_automation_deferred' => true,
                'ops_dashboard_automation_checked_at' => $opsDashboardAutomationCheckedAt,
                'open_ops_events' => $opsEventsTableReady ? $countOne(
                    "SELECT COUNT(*) AS c
                     FROM default_workspace_ops_events
                     WHERE default_workspace_id = ?
                       AND status IN ('open','in_progress','waiting_on_owner','waiting_on_provider')",
                    [$workspaceId]
                ) : 0,
                'ops_events_waiting_on_owner' => $opsEventsTableReady ? $countOne(
                    "SELECT COUNT(*) AS c
                     FROM default_workspace_ops_events
                     WHERE default_workspace_id = ?
                       AND status = 'waiting_on_owner'",
                    [$workspaceId]
                ) : 0,
                'ops_events_needs_admin' => $opsEventsTableReady ? $countOne(
                    "SELECT COUNT(*) AS c
                     FROM default_workspace_ops_events
                     WHERE default_workspace_id = ?
                       AND severity = 'critical'
                       AND status IN ('open','in_progress','waiting_on_provider')",
                    [$workspaceId]
                ) : 0,
                'ops_events_auto_resolved' => $opsAutomationReady ? $countOne(
                    "SELECT COUNT(*) AS c
                     FROM default_workspace_ops_events
                     WHERE default_workspace_id = ?
                       AND status = 'resolved'
                       AND automation_last_result = 'signal_cleared'",
                    [$workspaceId]
                ) : 0,
                'ops_events_owner_followups_sent' => $opsAutomationReady ? $countOne(
                    "SELECT COUNT(*) AS c
                     FROM default_workspace_ops_events
                     WHERE default_workspace_id = ?
                       AND automation_status = 'sent'
                       AND automation_last_sent_at IS NOT NULL",
                    [$workspaceId]
                ) : 0,
                'ops_events_automation_failed' => $opsAutomationReady ? $countOne(
                    "SELECT COUNT(*) AS c
                     FROM default_workspace_ops_events
                     WHERE default_workspace_id = ?
                       AND status IN ('open','in_progress','waiting_on_owner','waiting_on_provider')
                       AND automation_status = 'failed'",
                    [$workspaceId]
                ) : 0,
                'ops_events_human_only' => $opsAutomationReady ? $countOne(
                    "SELECT COUNT(*) AS c
                     FROM default_workspace_ops_events
                     WHERE default_workspace_id = ?
                       AND status IN ('open','in_progress','waiting_on_provider')
                       AND automation_status = 'human_only'",
                    [$workspaceId]
                ) : 0,
            ];
        } catch (\Throwable $e) {
            error_log('Unable to build Platform Ops dashboard summary: ' . $e->getMessage());
        }
    }
    $dashboardPerfMark('platform_ops');
    $onboardingOwnerPrompt = null;
    $setupCardData = $dashboardCache->getWithCache(
        'dashboard_setup_cards:v1:' . $workspaceId,
        static function () use ($workspaceId): array {
            $operatingBriefService = new WorkspaceOperatingBriefService();
            $latestOperatingBrief = $operatingBriefService->latest($workspaceId);

            return [
                'launch_checklist' => (new WorkspaceLaunchChecklistService())->summary($workspaceId),
                'latest_operating_brief' => $latestOperatingBrief,
                'sample_workflow' => (new WorkspaceSampleWorkflowService())->status($workspaceId),
            ];
        },
        120
    );
    $dashboardPerfMark('setup_card_cache');
    $launchChecklist = (array) ($setupCardData['launch_checklist'] ?? []);
    $latestOperatingBrief = (array) ($setupCardData['latest_operating_brief'] ?? []);
    $sampleWorkflow = (array) ($setupCardData['sample_workflow'] ?? []);
    if ((string) ($_GET['sample_workflow'] ?? '') === 'created') {
        $dashboardActionNotice = 'Sample workflow created. You can open each sample record below.';
    } elseif ((string) ($_GET['sample_workflow'] ?? '') === 'removed') {
        $dashboardActionNotice = 'Sample workflow removed.';
    } elseif ((string) ($_GET['platform_ops_events'] ?? '') === 'refreshed') {
        $dashboardActionNotice = 'Platform Ops events refreshed.';
    } elseif ((string) ($_GET['platform_ops_events'] ?? '') === 'updated') {
        $dashboardActionNotice = 'Platform Ops event updated.';
    } elseif ((string) ($_GET['platform_ops_automation'] ?? '') === 'ran') {
        $dashboardActionNotice = 'Platform Ops automation complete: ' . (int) ($_GET['sent'] ?? 0) . ' sent, ' . (int) ($_GET['skipped'] ?? 0) . ' skipped, ' . (int) ($_GET['failed'] ?? 0) . ' failed, ' . (int) ($_GET['human_only'] ?? 0) . ' human-only.';
    } elseif ((string) ($_GET['platform_ops_automation'] ?? '') !== '') {
        $dashboardActionNotice = 'Platform Ops automation result: ' . (string) ($_GET['platform_ops_automation'] ?? 'updated') . '.';
    } elseif ((string) ($_GET['demo'] ?? '') === 'complete') {
        $dashboardActionNotice = 'Product demo complete. Your dashboard is ready for real work.';
    } elseif ((string) ($_GET['demo'] ?? '') === 'exited') {
        $dashboardActionNotice = 'Product demo closed. Your dashboard is ready whenever you want to explore.';
    }
    $dashboardCsrfToken = Security::getCsrfToken();
    $dashboardProductDemoPromptVisible = false;
    try {
        $dashboardOnboardingState = (new WorkspaceOnboardingService())->getState($workspaceId, $userId);
        $guidedDemoSessions = new GuidedDemoSessionService();
        $dashboardActiveDemoSession = $guidedDemoSessions->activeSession($workspaceId, $userId);
        $dashboardLatestDemoSession = $guidedDemoSessions->latestSession($workspaceId, $userId);
        $dashboardLatestDemoStatus = (string) ($dashboardLatestDemoSession['status'] ?? '');
        $dashboardLatestDemoCleanupStatus = (string) ($dashboardLatestDemoSession['cleanup_status'] ?? '');

        $dashboardProductDemoPromptVisible =
            (string) ($dashboardOnboardingState['status'] ?? '') === 'completed'
            && $dashboardActiveDemoSession === null
            && !in_array($dashboardLatestDemoStatus, ['completed', 'exited', 'cleanup_failed'], true)
            && $dashboardLatestDemoCleanupStatus !== 'failed';
    } catch (\Throwable $e) {
        error_log('Dashboard product demo prompt lookup failed: ' . $e->getMessage());
    }
    $dashboardPerfMark('demo_prompt');

    $userPrefs = new UserPreferences();
    $aiCoachBriefGate = [];
    $showAICoachBriefGate = false;
    $aiCoachExplainerVideoRaw = '';
    $dashboardAICoachInstalled = false;
    try {
        $dashboardAICoachInstalled = (new AICoachWorkspaceSetupService())->isInstalled($workspaceId);
    } catch (\Throwable $e) {
        error_log('Dashboard AI Coach installation check failed: ' . $e->getMessage());
    }
    $dashboardPerfMark('ai_coach_access');
    $setupPopupDismissed = $userPrefs->getPreference($userId, 'dashboard_setup_popup_dismissed') === '1';
    $setupPopupScore = (int) ($workspaceSetupImpact['score'] ?? 0);
    $setupImpactComplete = $setupPopupScore >= 100;
    $setupCompleteDismissed = $userPrefs->getPreference($userId, 'dashboard_setup_complete_dismissed:' . $workspaceId) === '1';
    $showWorkspaceSetupImpactCard = !empty($workspaceSetupImpact['visible']) && !$setupImpactComplete;
    $showWorkspaceSetupCompleteBanner = !empty($workspaceSetupImpact['visible']) && $setupImpactComplete && !$setupCompleteDismissed;
    $showDashboardSetupPopup = !empty($workspaceSetupImpact['visible'])
        && $setupPopupScore < 80
        && !$setupPopupDismissed;
    $setupPopupActions = array_slice((array) ($workspaceSetupImpact['actions'] ?? []), 0, 2);
    $setupPopupPrimaryUrl = (string) (($onboardingOwnerPrompt['url'] ?? null)
        ?: (($setupPopupActions[0]['url'] ?? null) ?: 'onboarding.php'));
    $setupPopupPrimaryLabel = !empty($onboardingOwnerPrompt)
        ? (string) ($onboardingOwnerPrompt['title'] ?? 'Continue setup')
        : (string) (($setupPopupActions[0]['title'] ?? null) ?: 'Continue setup');
    if ($isProtectedDemoDashboard || $isPresentationDashboard) {
        $dashboardProductDemoPromptVisible = false;
        $showWorkspaceSetupImpactCard = false;
        $showWorkspaceSetupCompleteBanner = false;
        $showDashboardSetupPopup = false;
        $setupPopupActions = [];
        $showAICoachBriefGate = false;
    }
    $dashboardPerfMark('setup_cards');

    if ($showOutcomeDashboardCard) {
        $progress = $outcomeEventService->getActivationProgress($userId);
        if (empty($progress['first_login_at'])) {
            $outcomeEventService->track('user.first_login', [
                'user_id' => $userId,
                'event_source' => 'dashboard',
                'metadata' => ['page' => 'dashboard'],
            ]);
        }
        if ($outcomeFeatures['metrics']) {
            $outcomeTTFV = $outcomeMetricsModule->getTTFVSummary(14);
            $outcomeActivation = $outcomeMetricsModule->getActivationRateSummary(7);
            $outcomeRevenueActions = $outcomeMetricsModule->getRevenueActionRateSummary(7);
        }
    }
    $dashboardPerfMark('outcome_metrics');

    try {
        $readinessPayload = (new PlainLanguageReadinessService())->summaryFor($workspaceId, $userId, [
            'current_page' => 'dashboard.php',
            'mode' => $dashboardExperienceMode,
        ]);
        if (($readinessPayload['source'] ?? '') !== 'activation_progress_fallback') {
            $initialPlainReadiness = $readinessPayload;
        }
    } catch (\Throwable $e) {
        $initialPlainReadiness = null;
    }
    $dashboardPerfMark('plain_readiness');

    // Get default currency for formatting
    $defaultCurrency = $currenciesModule->getDefault();
    $formatDashboardCurrency = static function (float $amount) use ($currenciesModule, $defaultCurrency): string {
        if ($defaultCurrency) {
            return $currenciesModule->formatAmount($amount, (string) $defaultCurrency['code']);
        }

        return '$' . number_format($amount, 2);
    };
    $dashboardCurrencyTickPrefix = ($defaultCurrency && ($defaultCurrency['symbol_position'] ?? 'before') === 'before')
        ? (string) ($defaultCurrency['symbol'] ?? '')
        : '$';
    $dashboardCurrencyTickSuffix = ($defaultCurrency && ($defaultCurrency['symbol_position'] ?? 'before') !== 'before')
        ? ' ' . (string) ($defaultCurrency['symbol'] ?? '')
        : '';

    // Get metrics
    $metricsToday = $analytics->getRealTimeMetrics('today', $selectedUserId);
    $metricsWeek = $analytics->getRealTimeMetrics('week', $selectedUserId);
    $metricsMonth = $analytics->getRealTimeMetrics('month', $selectedUserId);
    $dashboardPerfMark('realtime_metrics');

    // Get task statistics
    $taskFilters = [];
    if ($selectedUserId !== null) {
        $taskFilters['assigned_to'] = $selectedUserId;
    }
    $myTasksCount = $tasksModule->getCount($taskFilters);
    $myPendingTasks = $tasksModule->getCount(array_merge($taskFilters, ['status' => 'pending']));
    $myOverdueTasks = $tasksModule->getCount(array_merge($taskFilters, ['overdue' => true]));
    $canReadTasks = Authorization::can('tasks.read', $user);
    $recentTasks = [];
    $aiStarterTasks = [];
    $dashboardPerfMark('task_counts');

    // Get recent contacts
    $contactScopeSql = '';
    $contactScopeParams = [$workspaceId];
    if ($selectedUserId !== null) {
        $contactScopeSql = ' AND assigned_to = ?';
        $contactScopeParams[] = $selectedUserId;
    }
    $recentContacts = Database::query(
        "SELECT id, first_name, last_name, email, stage, created_at 
         FROM contacts 
         WHERE workspace_id = ?{$contactScopeSql}
         ORDER BY created_at DESC 
         LIMIT 10",
        $contactScopeParams
    );

    // Get recent activities
    $activityScopeSql = '';
    $activityScopeParams = [$workspaceId];
    if ($selectedUserId !== null) {
        $activityScopeSql = ' AND a.user_id = ?';
        $activityScopeParams[] = $selectedUserId;
    }
    $recentActivities = Database::query(
        "SELECT a.*, c.first_name, c.last_name 
         FROM activities a 
         LEFT JOIN contacts c ON a.contact_id = c.id AND c.workspace_id = a.workspace_id
         WHERE a.workspace_id = ?{$activityScopeSql}
         ORDER BY a.created_at DESC 
         LIMIT 10",
        $activityScopeParams
    );

    // Get upcoming events
    $upcomingEvents = $eventsModule->getUpcoming(5, $selectedUserId);

    // Get recent notes
    $recentNotes = $notesModule->getRecent(5, $selectedUserId);

    $pipelineStats = $dealsModule->getPipelineStats($selectedUserId !== null ? ['assigned_to' => $selectedUserId] : []);
    $myOpenDealsCount = array_sum(array_map(
        static fn (array $stage): int => (int) ($stage['count'] ?? 0),
        $pipelineStats['by_stage'] ?? []
    ));
    $myDealsValue = (float) ($pipelineStats['total_pipeline_value'] ?? 0);
    $dashboardPerfMark('recent_lists');
    
    // Calculate trends
    $calculateTrend = function($current, $previous) {
        if ($previous == 0) return $current > 0 ? 100 : 0;
        return (($current - $previous) / $previous) * 100;
    };
    
    $trends = [
        'leads' => $calculateTrend($metricsToday['leads'], $metricsWeek['leads'] / 7),
        'emails_sent' => $calculateTrend($metricsToday['emails_sent'], $metricsWeek['emails_sent'] / 7),
        'emails_opened' => $calculateTrend($metricsToday['emails_opened'], $metricsWeek['emails_opened'] / 7),
        'form_submissions' => $calculateTrend($metricsToday['form_submissions'], $metricsWeek['form_submissions'] / 7),
    ];
    
    // Get daily trends for charts (last 7 days)
    $dailyTrends = Database::query(
        "SELECT 
            DATE(created_at) as date,
            COUNT(*) as contacts
         FROM contacts
         WHERE workspace_id = ?
           AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
         " . ($selectedUserId !== null ? "AND assigned_to = ?" : "") . "
         GROUP BY DATE(created_at)
         ORDER BY date ASC",
        $selectedUserId !== null ? [$workspaceId, $selectedUserId] : [$workspaceId]
    );
    
    // Get email performance trends
    $emailTrends = Database::query(
        "SELECT 
            DATE(e.created_at) as date,
            COUNT(DISTINCT e.id) as sent,
            COUNT(DISTINCT et.email_id) as opened
         FROM emails e
         LEFT JOIN email_tracking et ON e.id = et.email_id AND et.tracking_type = 'open'
         WHERE e.workspace_id = ?
          AND e.status IN ('sent', 'delivered', 'opened', 'clicked')
           AND e.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
         " . ($selectedUserId !== null ? "AND e.user_id = ?" : "") . "
         GROUP BY DATE(e.created_at)
         ORDER BY date ASC",
        $selectedUserId !== null ? [$workspaceId, $selectedUserId] : [$workspaceId]
    );
    
    // Get stage distribution
    $stageDistribution = Database::query(
        "SELECT stage, COUNT(*) as count 
         FROM contacts 
         WHERE workspace_id = ?
         " . ($selectedUserId !== null ? "AND assigned_to = ?" : "") . "
         GROUP BY stage 
         ORDER BY 
            CASE stage
                WHEN 'new' THEN 1
                WHEN 'contacted' THEN 2
                WHEN 'qualified' THEN 3
                WHEN 'proposal' THEN 4
                WHEN 'negotiation' THEN 5
                WHEN 'won' THEN 6
                WHEN 'lost' THEN 7
            END",
        $selectedUserId !== null ? [$workspaceId, $selectedUserId] : [$workspaceId]
    );
    
    // Get deal value by stage
    $dealValueByStage = Database::query(
        "SELECT 
            stage,
            COUNT(*) as deal_count,
            SUM(value) as total_value,
            AVG(value) as avg_value
         FROM deals
         WHERE workspace_id = ?
         AND stage NOT IN ('closed_won', 'closed_lost')
         " . ($selectedUserId !== null ? "AND assigned_to = ?" : "") . "
         GROUP BY stage
         ORDER BY 
            CASE stage
                WHEN 'prospecting' THEN 1
                WHEN 'qualification' THEN 2
                WHEN 'proposal' THEN 3
                WHEN 'negotiation' THEN 4
            END",
        $selectedUserId !== null ? [$workspaceId, $selectedUserId] : [$workspaceId]
    );
    $dealStageLabels = [];
    $dealStageValues = [];
    $dealStageValueLabels = [];
    $dealStageCounts = [];
    $dealStageCountLabels = [];
    $dealStageSummaries = [];
    $dealStageColors = ['#38bdf8', '#6366f1', '#f59e0b', '#10b981'];
    foreach ($dealValueByStage as $stageRow) {
        $rawStage = trim((string) ($stageRow['stage'] ?? ''));
        $stageValue = (float) ($stageRow['total_value'] ?? 0);
        $dealCount = (int) ($stageRow['deal_count'] ?? 0);
        if ($dealCount <= 0) {
            continue;
        }

        $stageLabel = $rawStage !== '' ? ucwords(str_replace('_', ' ', $rawStage)) : 'Open';
        $countLabel = number_format($dealCount) . ' deal' . ($dealCount === 1 ? '' : 's');
        $valueLabel = $formatDashboardCurrency($stageValue);
        $color = $dealStageColors[count($dealStageLabels) % count($dealStageColors)];

        $dealStageLabels[] = $stageLabel;
        $dealStageValues[] = round($stageValue, 2);
        $dealStageValueLabels[] = $valueLabel;
        $dealStageCounts[] = $dealCount;
        $dealStageCountLabels[] = $countLabel;
        $dealStageSummaries[] = [
            'label' => $stageLabel,
            'count_label' => $countLabel,
            'value_label' => $valueLabel,
            'color' => $color,
        ];
    }
    
    // Get total contacts count
    $totalContactsCount = (int) (Database::queryOne(
        "SELECT COUNT(*) as count FROM contacts WHERE workspace_id = ?" . ($selectedUserId !== null ? " AND assigned_to = ?" : ""),
        $selectedUserId !== null ? [$workspaceId, $selectedUserId] : [$workspaceId]
    )['count'] ?? 0);
    $contactsThisWeek = (int) (Database::queryOne(
        "SELECT COUNT(*) as count FROM contacts WHERE workspace_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)" . ($selectedUserId !== null ? " AND assigned_to = ?" : ""),
        $selectedUserId !== null ? [$workspaceId, $selectedUserId] : [$workspaceId]
    )['count'] ?? 0);
    
    // Get conversion rate
    $totalContacts = $totalContactsCount > 0 ? $totalContactsCount : 1;
    $wonContacts = (int) (Database::queryOne(
        "SELECT COUNT(*) as count FROM contacts WHERE workspace_id = ? AND stage = 'won'" . ($selectedUserId !== null ? " AND assigned_to = ?" : ""),
        $selectedUserId !== null ? [$workspaceId, $selectedUserId] : [$workspaceId]
    )['count'] ?? 0);
    $conversionRate = $totalContacts > 0 ? ($wonContacts / $totalContacts) * 100 : 0;
    
    // Get email open rate
    $totalEmailsSent = $metricsMonth['emails_sent'];
    $totalEmailsOpened = $metricsMonth['emails_opened'];
    $emailOpenRate = $totalEmailsSent > 0 ? ($totalEmailsOpened / $totalEmailsSent) * 100 : 0;
    if ($protectedDemoShowcase !== null) {
        $showcaseMetrics = $protectedDemoShowcase->dashboardMetrics();
        $metricsToday = array_replace($metricsToday, (array) ($showcaseMetrics['metrics_today'] ?? []));
        $metricsWeek = array_replace($metricsWeek, (array) ($showcaseMetrics['metrics_week'] ?? []));
        $metricsMonth = array_replace($metricsMonth, (array) ($showcaseMetrics['metrics_month'] ?? []));
        $myTasksCount = (int) ($showcaseMetrics['tasks_count'] ?? $myTasksCount);
        $myPendingTasks = (int) ($showcaseMetrics['pending_tasks'] ?? $myPendingTasks);
        $myOverdueTasks = (int) ($showcaseMetrics['overdue_tasks'] ?? 0);
        $myOpenDealsCount = (int) ($showcaseMetrics['open_deals_count'] ?? $myOpenDealsCount);
        $myDealsValue = (float) ($showcaseMetrics['deals_value'] ?? $myDealsValue);
        $pipelineStats['total_pipeline_value'] = (float) ($showcaseMetrics['pipeline_total'] ?? $myDealsValue);
        $pipelineStats['by_stage'] = [
            ['stage' => 'qualification', 'count' => 2, 'total_value' => 420000],
            ['stage' => 'proposal', 'count' => 3, 'total_value' => 810000],
            ['stage' => 'negotiation', 'count' => 2, 'total_value' => 450000],
        ];
        $totalContactsCount = (int) ($showcaseMetrics['total_contacts'] ?? $totalContactsCount);
        $contactsThisWeek = (int) ($showcaseMetrics['contacts_this_week'] ?? $contactsThisWeek);
        $wonContacts = (int) ($showcaseMetrics['won_contacts'] ?? $wonContacts);
        $totalContacts = $totalContactsCount > 0 ? $totalContactsCount : 1;
        $conversionRate = $totalContacts > 0 ? ($wonContacts / $totalContacts) * 100 : 0;
        $emailOpenRate = $metricsMonth['emails_sent'] > 0 ? ($metricsMonth['emails_opened'] / $metricsMonth['emails_sent']) * 100 : 0;
        $trends = [
            'leads' => 35.5,
            'emails_sent' => 14.0,
            'emails_opened' => 18.2,
            'form_submissions' => 23.5,
        ];
        $dailyTrends = (array) ($showcaseMetrics['daily_trends'] ?? $dailyTrends);
        $emailTrends = (array) ($showcaseMetrics['email_trends'] ?? $emailTrends);
        $stageDistribution = (array) ($showcaseMetrics['stage_distribution'] ?? $stageDistribution);
        $dealStageLabels = ['Qualification', 'Proposal', 'Negotiation'];
        $dealStageValues = [420000, 810000, 450000];
        $dealStageValueLabels = array_map($formatDashboardCurrency, $dealStageValues);
        $dealStageCounts = [2, 3, 2];
        $dealStageCountLabels = ['2 deals', '3 deals', '2 deals'];
        $dealStageColors = ['#38bdf8', '#f59e0b', '#10b981'];
        $dealStageSummaries = [
            ['label' => 'Qualification', 'count_label' => '2 deals', 'value_label' => $dealStageValueLabels[0], 'color' => $dealStageColors[0]],
            ['label' => 'Proposal', 'count_label' => '3 deals', 'value_label' => $dealStageValueLabels[1], 'color' => $dealStageColors[1]],
            ['label' => 'Negotiation', 'count_label' => '2 deals', 'value_label' => $dealStageValueLabels[2], 'color' => $dealStageColors[2]],
        ];
        $outcomeTTFV = (array) ($showcaseMetrics['outcome_ttfv'] ?? $outcomeTTFV);
        $outcomeActivation = (array) ($showcaseMetrics['outcome_activation'] ?? $outcomeActivation);
        $outcomeRevenueActions = (array) ($showcaseMetrics['outcome_revenue_actions'] ?? $outcomeRevenueActions);
        $initialPlainReadiness = [
            'headline_label' => 'Showcase ready',
            'progress_label' => '8/8 revenue systems live',
            'phase' => 'revenue_momentum',
            'source' => 'protected_demo_showcase',
            'milestones' => [
                ['label' => 'Profile', 'complete' => true],
                ['label' => 'Channels', 'complete' => true],
                ['label' => 'Templates', 'complete' => true],
                ['label' => 'Plugins', 'complete' => true],
                ['label' => 'AI triage', 'complete' => true],
                ['label' => 'Targets', 'complete' => true],
            ],
            'primary_gap' => [
                'reason' => $protectedDemoProfileKey === 'metrodrive'
                    ? 'The demo is already configured; focus on the MetroDrive lesson booking motion.'
                    : 'The demo is already configured; focus on the Riverside revenue motion.',
                'status_label' => 'All setup issues resolved in protected demo mode.',
            ],
        ];
    }
    $dashboardPerfMark('charts_and_totals');
} catch (\Exception $e) {
    if ($e->getMessage() === 'An active workspace is required.') {
        WorkspaceContext::clear();
        $sessionUserId = (int) (Auth::userId() ?? 0);
        if ($sessionUserId > 0 && empty($_GET['workspace_recovered'])) {
            $recoveredWorkspace = WorkspaceContext::activateForUser($sessionUserId);
            if ($recoveredWorkspace !== null) {
                header('Location: dashboard.php?workspace_recovered=1');
                exit;
            }
        }

        if ($sessionUserId > 0 && isset($user) && PlatformWorkspaceOperationsService::isPlatformAdmin($user)) {
            header('Location: workspaces.php?error=' . rawurlencode('Your previous workspace is no longer available. Choose or provision a workspace to continue.'));
            exit;
        }

        if ($sessionUserId > 0) {
            header('Location: workspaces.php?error=' . rawurlencode('Your previous workspace is no longer available. Choose another workspace to continue.'));
            exit;
        }

        header('Location: ' . Auth::loginUrl('dashboard.php', true));
        exit;
    }

    error_log('Dashboard data loading error: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
    $showDebug = (($_ENV['APP_ENV'] ?? 'production') === 'development')
               || filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL)
               || filter_var($_ENV['DEBUG'] ?? false, FILTER_VALIDATE_BOOL);
    
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html><html><head><title>500 Internal Server Error</title></head><body>";
    echo "<h1>500 Internal Server Error</h1>";
    echo "<p>An error occurred while loading dashboard data.</p>";
    if ($showDebug) {
        echo "<p><strong>Error:</strong> " . htmlspecialchars($e->getMessage()) . "</p>";
        echo "<p><strong>File:</strong> " . htmlspecialchars($e->getFile()) . ":" . $e->getLine() . "</p>";
        echo "<pre style='background: #f5f5f5; padding: 10px; overflow: auto;'>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
    } else {
        echo "<p>Please contact the administrator or try again later.</p>";
        echo "<p><a href='" . htmlspecialchars($basePath . '/login.php') . "'>Return to Login</a></p>";
    }
    echo "</body></html>";
    exit;
}

$pageTitle = 'Dashboard - ' . brandProductName();
$dashboardPerfFlush();
ob_start();

// Extract display name
$displayName = trim((string) ($user['first_name'] ?? ''));
if ($displayName === '') {
    $displayName = (string) ($user['email'] ?? 'User');
    if (strpos($displayName, '@') !== false) {
        $displayName = ucfirst(explode('@', $displayName)[0]);
    }
}
$dashboardPossessiveLabel = $selectedUserId === $userId ? 'My' : ($selectedUserId === null ? 'All Users' : $selectedUserLabel);
$onboardingCelebrationMode = (string) ($_GET['onboarding'] ?? '');
$showOnboardingCelebration = in_array($onboardingCelebrationMode, ['complete', 'quick_start'], true);
$dashboardExplainerAsset = static function (string $path): string {
    $path = trim($path);
    if ($path === '' || preg_match('#^https?://#i', $path) === 1 || str_starts_with($path, '/')) {
        return $path;
    }
    if (str_starts_with($path, 'uploads/')) {
        return function_exists('publicUrl') ? publicUrl('../' . $path) : '../' . $path;
    }

    return function_exists('assetUrl') ? assetUrl($path) : 'assets/' . ltrim($path, '/');
};
$dashboardExplainerVideoUrl = $dashboardExplainerAsset((string) ($dashboardExplainerVideo ?? ''));
$dashboardVideoEmbed = static function (string $rawUrl) use ($dashboardExplainerAsset): array {
    $rawUrl = trim($rawUrl);
    if ($rawUrl === '') {
        return ['type' => '', 'url' => ''];
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

    return ['type' => 'video', 'url' => $dashboardExplainerAsset($rawUrl)];
};
$aiCoachExplainerVideoEmbed = $dashboardVideoEmbed((string) ($aiCoachExplainerVideoRaw ?? ''));
$aiCoachExplainerVideoType = (string) ($aiCoachExplainerVideoEmbed['type'] ?? '');
$aiCoachExplainerVideoUrl = (string) ($aiCoachExplainerVideoEmbed['url'] ?? '');
?>

<link rel="stylesheet" href="<?php echo htmlspecialchars(assetUrl('css/dashboard-premium.css') . '?v=' . urlencode(APP_VERSION . '-' . (string) @filemtime(__DIR__ . '/assets/css/dashboard-premium.css'))); ?>">
<?php if ($dashboardAICoachInstalled): ?>
<link rel="stylesheet" href="<?php echo htmlspecialchars(assetUrl('css/ai-coach.css') . '?v=' . urlencode(APP_VERSION . '-' . (string) @filemtime(__DIR__ . '/assets/css/ai-coach.css'))); ?>">
<?php endif; ?>
<style>
.charts-grid {
    grid-template-columns: repeat(auto-fit, minmax(min(400px, 100%), 1fr));
}

.content-grid {
    grid-template-columns: repeat(auto-fit, minmax(min(350px, 100%), 1fr));
}

@media (max-width: 640px) {
    .charts-grid,
    .content-grid {
        padding-left: 1rem;
        padding-right: 1rem;
        gap: 1rem;
    }

    .chart-container {
        min-width: 0;
        padding: 1.1rem;
    }
}

@keyframes dashboardTasksPulse {
    0% { background-position: 200% 0; }
    100% { background-position: -200% 0; }
}

.hero-actions-row {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.6rem;
    flex-wrap: wrap;
    margin-top: 1rem;
}

.hero-actions-row .hero-ai-coach-btn {
    margin-top: 0;
}

.hero-guide-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    min-height: 46px;
    border: 1px solid rgba(148, 163, 184, 0.35);
    border-radius: 14px;
    background: rgba(255, 255, 255, 0.94);
    color: #1d4ed8;
    box-shadow: 0 12px 28px rgba(15, 23, 42, 0.08);
    cursor: pointer;
    font-size: 0.88rem;
    font-weight: 700;
    line-height: 1.2;
    padding: 0 1rem;
    white-space: nowrap;
    transition: border-color 0.18s ease, box-shadow 0.18s ease, color 0.18s ease, transform 0.18s ease;
}

.hero-guide-btn:hover,
.hero-guide-btn:focus-visible {
    border-color: rgba(59, 130, 246, 0.45);
    color: #1e40af;
    box-shadow: 0 14px 30px rgba(59, 130, 246, 0.16), 0 0 0 4px rgba(59, 130, 246, 0.08);
    outline: none;
    transform: translateY(-1px);
}

.hero-guide-btn i {
    color: #2563eb;
}

.dashboard-video-modal[hidden] {
    display: none;
}

.dashboard-video-modal {
    position: fixed;
    inset: 0;
    z-index: 13000;
    box-sizing: border-box;
    display: grid;
    place-items: center;
    padding: clamp(1rem, 3vw, 2rem);
    overflow: hidden;
    background: rgba(15, 23, 42, 0.62);
}

.dashboard-video-dialog {
    width: min(920px, 100%);
    max-height: min(760px, calc(100vh - 2rem));
    overflow: hidden;
    display: flex;
    flex-direction: column;
    border: 1px solid rgba(226, 232, 240, 0.7);
    border-radius: 16px;
    background: #ffffff;
    box-shadow: 0 30px 80px rgba(15, 23, 42, 0.28);
}

.dashboard-video-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 1rem 1.1rem;
    border-bottom: 1px solid rgba(15, 23, 42, 0.08);
}

.dashboard-video-title {
    margin: 0;
    color: #0f172a;
    font-size: 1.05rem;
    font-weight: 700;
    line-height: 1.2;
}

.dashboard-video-close {
    width: 2.35rem;
    height: 2.35rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid rgba(148, 163, 184, 0.32);
    border-radius: 8px;
    background: #ffffff;
    color: #334155;
    cursor: pointer;
}

.dashboard-video-close:hover,
.dashboard-video-close:focus-visible {
    border-color: rgba(37, 99, 235, 0.34);
    color: #1d4ed8;
    outline: none;
}

.dashboard-video-frame {
    background: #020617;
    min-height: 0;
    flex: 1 1 auto;
}

.dashboard-video-frame video {
    width: 100%;
    max-height: min(640px, calc(100vh - 7.25rem));
    display: block;
    object-fit: contain;
    background: #020617;
}

.dashboard-video-placeholder {
    align-items: center;
    background:
        linear-gradient(135deg, rgba(15, 23, 42, 0.96), rgba(30, 64, 175, 0.92)),
        linear-gradient(90deg, rgba(14, 165, 233, 0.12), rgba(37, 99, 235, 0.08));
    color: #f8fafc;
    display: grid;
    gap: 0.85rem;
    justify-items: center;
    min-height: min(420px, calc(100vh - 8rem));
    padding: 2rem;
    text-align: center;
}

.dashboard-video-placeholder i {
    color: #67e8f9;
    font-size: 2.4rem;
}

.dashboard-video-placeholder strong {
    color: #ffffff;
    font-size: 1.15rem;
}

.dashboard-video-placeholder span {
    color: #dbeafe;
    line-height: 1.5;
    max-width: 520px;
}

.clarity-workspace-welcome {
    position: fixed;
    inset: 0;
    z-index: 9999;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1.25rem;
    background:
        radial-gradient(circle at 50% 24%, rgba(37, 99, 235, 0.2), transparent 26%),
        radial-gradient(circle at 15% 78%, rgba(6, 182, 212, 0.16), transparent 24%),
        radial-gradient(circle at 86% 70%, rgba(245, 158, 11, 0.15), transparent 22%),
        rgba(248, 250, 252, 0.86);
    backdrop-filter: blur(18px);
    animation: clarityWelcomeIn 0.45s cubic-bezier(.2, .8, .2, 1) both;
}

.clarity-workspace-welcome.is-leaving {
    animation: clarityWelcomeOut 0.26s ease both;
}

.clarity-welcome-card {
    position: relative;
    width: min(680px, 100%);
    overflow: hidden;
    border-radius: 30px;
    border: 1px solid rgba(148, 163, 184, 0.28);
    background: rgba(255, 255, 255, 0.9);
    box-shadow: 0 28px 80px rgba(15, 23, 42, 0.2), 0 8px 24px rgba(37, 99, 235, 0.12);
    padding: clamp(1.4rem, 4vw, 2.4rem);
    text-align: center;
}

.clarity-welcome-card::before {
    content: "";
    position: absolute;
    inset: -45%;
    background: conic-gradient(from 120deg, transparent, rgba(37, 99, 235, .16), rgba(6, 182, 212, .14), rgba(245, 158, 11, .18), transparent);
    animation: clarityWelcomeGlow 9s linear infinite;
}

.clarity-welcome-card > * {
    position: relative;
    z-index: 1;
}

.clarity-welcome-logo {
    width: 6rem;
    height: 6rem;
    margin: 0 auto 1rem;
    padding: .45rem;
    border-radius: 26px;
    background: rgba(255, 255, 255, 0.82);
    border: 1px solid rgba(37, 99, 235, 0.16);
    box-shadow: 0 18px 44px rgba(37, 99, 235, 0.18);
    object-fit: contain;
}

.clarity-welcome-kicker {
    margin: 0 0 .55rem;
    color: #2563eb;
    font-size: .76rem;
    font-weight: 800;
    letter-spacing: .14em;
    text-transform: uppercase;
}

.clarity-welcome-title {
    margin: 0;
    color: #0f172a;
    font-size: clamp(2.3rem, 7vw, 4.4rem);
    line-height: .98;
    letter-spacing: 0;
}

.clarity-welcome-copy {
    max-width: 520px;
    margin: 1rem auto 0;
    color: #475569;
    font-size: 1.03rem;
    line-height: 1.65;
}

.clarity-welcome-actions {
    display: flex;
    justify-content: center;
    margin-top: 1.35rem;
}

.clarity-welcome-start {
    min-height: 3rem;
    padding: .85rem 1.3rem;
    border: 0;
    border-radius: 14px;
    background: #1d4ed8;
    color: #fff;
    font-weight: 800;
    cursor: pointer;
    box-shadow: 0 14px 30px rgba(37, 99, 235, .22);
    transition: transform .18s ease, box-shadow .18s ease, background .18s ease;
}

.clarity-welcome-start:hover {
    transform: translateY(-2px);
    background: #1e40af;
    box-shadow: 0 18px 38px rgba(37, 99, 235, .26);
}

.clarity-welcome-sparkles {
    position: absolute;
    inset: 0;
    pointer-events: none;
}

.clarity-welcome-sparkles span {
    position: absolute;
    width: .42rem;
    height: .42rem;
    border-radius: 999px;
    background: #2563eb;
    box-shadow: 0 0 18px rgba(37, 99, 235, .45);
    animation: claritySparkle 2.4s ease-in-out infinite;
}

.clarity-welcome-sparkles span:nth-child(1) { left: 12%; top: 22%; }
.clarity-welcome-sparkles span:nth-child(2) { right: 16%; top: 18%; background: #06b6d4; animation-delay: .25s; }
.clarity-welcome-sparkles span:nth-child(3) { left: 20%; bottom: 18%; background: #f59e0b; animation-delay: .5s; }
.clarity-welcome-sparkles span:nth-child(4) { right: 20%; bottom: 24%; animation-delay: .75s; }
.clarity-welcome-sparkles span:nth-child(5) { left: 50%; top: 10%; background: #06b6d4; animation-delay: 1s; }

@keyframes clarityWelcomeIn {
    from { opacity: 0; transform: scale(1.01); }
    to { opacity: 1; transform: scale(1); }
}

@keyframes clarityWelcomeOut {
    to { opacity: 0; transform: scale(.99); }
}

@keyframes clarityWelcomeGlow {
    to { transform: rotate(360deg); }
}

@keyframes claritySparkle {
    0%, 100% { transform: translateY(0) scale(.72); opacity: .18; }
    45% { transform: translateY(-18px) scale(1.2); opacity: .95; }
}

@media (prefers-reduced-motion: reduce) {
    .clarity-workspace-welcome,
    .clarity-workspace-welcome.is-leaving,
    .clarity-welcome-card::before,
    .clarity-welcome-sparkles span {
        animation: none !important;
    }
}

.hero-automation-battery {
    margin: 0 auto 1.1rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.9rem;
    opacity: 0;
    transform: translateY(14px);
    animation: heroTitleEnter 0.55s cubic-bezier(0.34, 1.56, 0.64, 1) 0.55s forwards;
}

.hero-automation-battery.is-loading {
    opacity: 0.82;
}

.hero-minimal-controls {
    position: absolute;
    top: 2rem;
    right: 2rem;
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 0.65rem;
    z-index: 2;
}

.hero-filter-popover {
    position: relative;
}

.hero-filter-trigger {
    width: 46px;
    height: 46px;
    border-radius: 16px;
    border: 1px solid rgba(147, 197, 253, 0.68);
    background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
    box-shadow: 0 14px 30px rgba(37, 99, 235, 0.10);
    color: #0f172a;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    list-style: none;
    transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease, background 0.18s ease, color 0.18s ease;
}

.hero-filter-trigger i {
    font-size: 0.95rem;
    line-height: 1;
}

.hero-filter-trigger::-webkit-details-marker {
    display: none;
}

.hero-filter-trigger:hover,
.hero-filter-popover[open] .hero-filter-trigger {
    transform: translateY(-1px);
    border-color: rgba(37, 99, 235, 0.48);
    background: linear-gradient(180deg, #ffffff 0%, #eff6ff 100%);
    color: #1d4ed8;
    box-shadow: 0 16px 34px rgba(37, 99, 235, 0.16);
}

.hero-filter-trigger:focus-visible {
    outline: 3px solid rgba(14, 165, 233, 0.26);
    outline-offset: 3px;
}

.hero-filter-panel {
    position: absolute;
    top: calc(100% + 0.75rem);
    right: 0;
    min-width: 268px;
    box-sizing: border-box;
    padding: 0.95rem;
    border-radius: 14px;
    background: rgba(255, 255, 255, 0.98);
    border: 1px solid rgba(203, 213, 225, 0.78);
    box-shadow: 0 18px 44px rgba(15, 23, 42, 0.12);
    backdrop-filter: blur(12px);
}

.hero-filter-panel::before {
    content: '';
    position: absolute;
    top: -6px;
    right: 1.45rem;
    width: 11px;
    height: 11px;
    border-left: 1px solid rgba(203, 213, 225, 0.78);
    border-top: 1px solid rgba(203, 213, 225, 0.78);
    background: rgba(255, 255, 255, 0.98);
    transform: rotate(45deg);
}

.hero-filter-label {
    font-size: 0.74rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: #64748b;
    margin-bottom: 0.62rem;
}

.hero-filter-select-wrap {
    position: relative;
}

.hero-filter-select-wrap::before {
    content: '\f0b0';
    position: absolute;
    left: 0.82rem;
    top: 50%;
    z-index: 1;
    color: #2563eb;
    font-family: "Font Awesome 5 Free";
    font-size: 0.78rem;
    font-weight: 900;
    pointer-events: none;
    transform: translateY(-50%);
}

.hero-filter-select-wrap::after {
    content: '\f078';
    position: absolute;
    right: 0.82rem;
    top: 50%;
    z-index: 1;
    color: #64748b;
    font-family: "Font Awesome 5 Free";
    font-size: 0.68rem;
    font-weight: 900;
    pointer-events: none;
    transform: translateY(-50%);
}

.hero-minimal-controls .timeframe-select {
    width: 100%;
    min-width: 220px;
    min-height: 42px;
    appearance: none;
    border: 1px solid rgba(203, 213, 225, 0.94);
    border-radius: 12px;
    background: #f8fafc;
    color: #0f172a;
    cursor: pointer;
    font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    font-size: 0.86rem;
    font-weight: 700;
    line-height: 1.2;
    padding: 0.65rem 2.35rem 0.65rem 2.35rem;
    transition: border-color 0.18s ease, box-shadow 0.18s ease, background 0.18s ease;
}

.hero-minimal-controls .timeframe-select:hover {
    border-color: rgba(59, 130, 246, 0.5);
    background: #ffffff;
}

.hero-minimal-controls .timeframe-select:focus-visible {
    outline: none;
    border-color: rgba(37, 99, 235, 0.72);
    background: #ffffff;
    box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.12);
}

.hero-automation-battery-top {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.9rem;
}

.hero-automation-tooltip-anchor {
    position: relative;
    display: inline-flex;
    align-items: center;
    flex-shrink: 0;
}

.hero-automation-tooltip {
    position: absolute;
    bottom: calc(100% + 0.85rem);
    left: 50%;
    z-index: 25;
    width: max-content;
    max-width: min(260px, calc(100vw - 2rem));
    box-sizing: border-box;
    padding: 0.62rem 0.72rem;
    border: 1px solid rgba(226, 232, 240, 0.92);
    border-radius: 8px;
    background: rgba(255, 255, 255, 0.98);
    color: #334155;
    box-shadow: 0 16px 34px rgba(15, 23, 42, 0.13);
    font-size: 0.76rem;
    font-weight: 700;
    line-height: 1.38;
    text-align: left;
    white-space: normal;
    opacity: 0;
    pointer-events: none;
    visibility: hidden;
    transform: translate(-50%, 4px);
    transition: opacity 0.16s ease, transform 0.16s ease, visibility 0.16s ease;
}

.hero-automation-tooltip::before {
    content: '';
    position: absolute;
    bottom: -5px;
    left: 50%;
    width: 9px;
    height: 9px;
    border-right: 1px solid rgba(226, 232, 240, 0.92);
    border-bottom: 1px solid rgba(226, 232, 240, 0.92);
    background: rgba(255, 255, 255, 0.98);
    transform: translateX(-50%) rotate(45deg);
}

.hero-automation-tooltip-anchor:hover .hero-automation-tooltip,
.hero-automation-tooltip-anchor:focus-within .hero-automation-tooltip {
    opacity: 1;
    visibility: visible;
    transform: translate(-50%, 0);
}

@media (max-width: 560px) {
    [data-automation-battery-refresh-tooltip].hero-automation-tooltip {
        left: 0;
        transform: translate(0, 4px);
    }

    [data-automation-battery-refresh-tooltip].hero-automation-tooltip::before {
        left: 3.45rem;
        transform: rotate(45deg);
    }

    .hero-automation-tooltip-anchor:hover [data-automation-battery-refresh-tooltip].hero-automation-tooltip,
    .hero-automation-tooltip-anchor:focus-within [data-automation-battery-refresh-tooltip].hero-automation-tooltip {
        transform: translate(0, 0);
    }

    [data-automation-battery-status-tooltip].hero-automation-tooltip {
        left: auto;
        right: 0.5rem;
        transform: translate(0, 4px);
    }

    [data-automation-battery-status-tooltip].hero-automation-tooltip::before {
        left: auto;
        right: 3.45rem;
        transform: rotate(45deg);
    }

    .hero-automation-tooltip-anchor:hover [data-automation-battery-status-tooltip].hero-automation-tooltip,
    .hero-automation-tooltip-anchor:focus-within [data-automation-battery-status-tooltip].hero-automation-tooltip {
        transform: translate(0, 0);
    }
}

.hero-automation-battery-status {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.38rem 0.72rem;
    border-radius: 999px;
    font-size: 0.78rem;
    font-weight: 700;
    white-space: nowrap;
}

.hero-automation-battery-status.pending {
    background: #f8fafc;
    color: #475569;
    border: 1px solid rgba(148, 163, 184, 0.28);
}

.hero-automation-battery-status.error {
    background: #fef2f2;
    color: #b91c1c;
    border: 1px solid rgba(248, 113, 113, 0.34);
}

.hero-automation-battery-status.low {
    background: #fff7ed;
    color: #c2410c;
}

.hero-automation-battery-status.medium {
    background: #fef3c7;
    color: #a16207;
}

.hero-automation-battery-status.high {
    background: #dcfce7;
    color: #166534;
}

.hero-automation-battery-status.full {
    background: #dbeafe;
    color: #1d4ed8;
}

.hero-automation-shell {
    appearance: none;
    display: block;
    width: 140px;
    height: 62px;
    box-sizing: border-box;
    border: 3px solid #0f172a;
    border-radius: 18px;
    padding: 5px;
    position: relative;
    background: #ffffff;
    color: #0f172a;
    cursor: pointer;
    flex-shrink: 0;
    margin-right: 0.35rem;
    font: inherit;
    line-height: normal;
    overflow: visible;
    transition: transform 0.16s ease, box-shadow 0.16s ease, border-color 0.16s ease;
}

.hero-automation-shell:hover {
    transform: translateY(-1px);
    box-shadow: 0 12px 24px rgba(8, 145, 178, 0.14);
}

.hero-automation-shell:focus-visible {
    outline: none;
    border-color: #0891b2;
    box-shadow: 0 0 0 4px rgba(8, 145, 178, 0.22), 0 12px 24px rgba(8, 145, 178, 0.14);
}

.hero-automation-shell:disabled {
    cursor: wait;
    transform: none;
}

.hero-automation-shell.is-refreshing .hero-automation-fill {
    animation: automationBatteryPulse 0.95s ease-in-out infinite;
}

.hero-automation-refresh-spinner {
    position: absolute;
    right: 12px;
    top: 50%;
    z-index: 2;
    color: #155e75;
    font-size: 0.78rem;
    opacity: 0;
    transform: translateY(-50%) scale(0.8);
    transition: opacity 0.16s ease, transform 0.16s ease;
    pointer-events: none;
}

.hero-automation-shell.is-refreshing .hero-automation-refresh-spinner {
    opacity: 1;
    transform: translateY(-50%) scale(1);
}

@keyframes automationBatteryPulse {
    0%, 100% {
        filter: saturate(1);
        opacity: 0.94;
    }
    50% {
        filter: saturate(1.18);
        opacity: 0.7;
    }
}

.hero-automation-shell::after {
    content: '';
    position: absolute;
    right: -9px;
    top: 18px;
    width: 7px;
    height: 20px;
    border-radius: 0 6px 6px 0;
    background: #0f172a;
    pointer-events: none;
}

.hero-automation-fill {
    height: 100%;
    border-radius: 11px;
    transition: width 0.3s ease;
}

.hero-automation-fill.pending {
    background: linear-gradient(90deg, #cbd5e1 0%, #94a3b8 100%);
}

.hero-automation-fill.low {
    background: linear-gradient(90deg, #fb923c 0%, #f97316 100%);
}

.hero-automation-fill.medium {
    background: linear-gradient(90deg, #facc15 0%, #f59e0b 100%);
}

.hero-automation-fill.high {
    background: linear-gradient(90deg, #4ade80 0%, #22c55e 100%);
}

.hero-automation-fill.full {
    background: linear-gradient(90deg, #60a5fa 0%, #2563eb 100%);
}

.hero-automation-score-inside {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.15rem;
    font-weight: 800;
    letter-spacing: -0.02em;
    color: #0f172a;
    z-index: 1;
}

.automation-readiness-card {
    margin: 0 auto 1rem;
    max-width: 1080px;
}

.automation-readiness-card.is-on-demand:not(.is-loaded) .automation-layer-grid {
    display: none;
}

.automation-readiness-card.is-on-demand:not(.is-loaded) .automation-readiness-list {
    opacity: 0.78;
}

.workspace-setup-impact {
    margin: 0 auto 1rem;
    max-width: 1080px;
}

.workspace-setup-impact-card {
    display: grid;
    grid-template-columns: minmax(220px, 310px) 1fr;
    gap: 1rem;
    align-items: stretch;
    padding: 1rem;
    border: 1px solid rgba(37, 99, 235, 0.16);
    border-radius: 18px;
    background:
        linear-gradient(135deg, rgba(239, 246, 255, 0.9), rgba(255, 255, 255, 0.96)),
        #ffffff;
    box-shadow: 0 18px 45px rgba(15, 23, 42, 0.07);
}

.workspace-setup-score {
    display: grid;
    gap: 0.75rem;
    align-content: center;
    padding: 1rem;
    border-radius: 14px;
    background: #ffffff;
    border: 1px solid rgba(148, 163, 184, 0.22);
}

.workspace-setup-label {
    color: #2563eb;
    font-size: 0.72rem;
    font-weight: 800;
    letter-spacing: 0.12em;
    text-transform: uppercase;
}

.workspace-setup-headline {
    color: #0f172a;
    font-size: clamp(1.4rem, 3vw, 2.15rem);
    font-weight: 850;
    line-height: 1.05;
}

.workspace-setup-meter {
    height: 0.58rem;
    overflow: hidden;
    border-radius: 999px;
    background: #e2e8f0;
}

.workspace-setup-meter span {
    display: block;
    height: 100%;
    border-radius: inherit;
    background: linear-gradient(90deg, #2563eb, #06b6d4);
}

.workspace-setup-summary {
    margin: 0;
    color: #475569;
    font-size: 0.88rem;
    line-height: 1.55;
}

.workspace-setup-actions {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
    gap: 0.75rem;
}

.workspace-setup-action {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: 0.7rem;
    align-items: start;
    min-height: 100%;
    padding: 0.9rem;
    border: 1px solid rgba(148, 163, 184, 0.24);
    border-radius: 14px;
    background: #ffffff;
    color: inherit;
    text-decoration: none;
    transition: transform 0.18s ease, border-color 0.18s ease, box-shadow 0.18s ease;
}

.workspace-setup-action:hover {
    transform: translateY(-2px);
    border-color: rgba(37, 99, 235, 0.35);
    box-shadow: 0 16px 34px rgba(37, 99, 235, 0.1);
}

.workspace-setup-action-icon {
    width: 2.25rem;
    height: 2.25rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 12px;
    background: #eff6ff;
    color: #2563eb;
}

.workspace-setup-action strong {
    display: block;
    color: #0f172a;
    font-size: 0.9rem;
    line-height: 1.2;
}

.workspace-setup-action span:last-child {
    display: block;
    margin-top: 0.22rem;
    color: #64748b;
    font-size: 0.78rem;
    line-height: 1.35;
}

.workspace-setup-complete {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 0.65rem;
    padding: 1rem;
    border: 1px solid #bbf7d0;
    border-radius: 14px;
    background: #f0fdf4;
    color: #166534;
    font-weight: 800;
}

.workspace-setup-complete-note {
    display: inline-flex;
    align-items: center;
    gap: 0.65rem;
}

.workspace-setup-onboarding-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.45rem;
    padding: 0.55rem 0.75rem;
    border: 1px solid rgba(22, 101, 52, 0.22);
    border-radius: 10px;
    background: #ffffff;
    color: #166534;
    font-size: 0.84rem;
    font-weight: 850;
    text-decoration: none;
    transition: transform 0.18s ease, border-color 0.18s ease, box-shadow 0.18s ease;
}

.workspace-setup-onboarding-link:hover {
    transform: translateY(-1px);
    border-color: rgba(22, 101, 52, 0.38);
    box-shadow: 0 10px 20px rgba(22, 101, 52, 0.1);
}

.workspace-setup-complete-banner {
    margin: 0 auto 1rem;
    max-width: 1080px;
}

.dashboard-product-demo-banner {
    margin: 0 auto 1rem;
    max-width: 1080px;
}

.dashboard-product-demo-card {
    display: grid;
    grid-template-columns: minmax(0, 1.45fr) minmax(240px, 0.95fr);
    gap: 1rem;
    align-items: center;
    padding: 1rem 1.05rem;
    border: 1px solid rgba(14, 116, 144, 0.16);
    border-radius: 18px;
    background:
        radial-gradient(circle at top right, rgba(125, 211, 252, 0.24), transparent 38%),
        linear-gradient(135deg, rgba(248, 250, 252, 0.98), rgba(239, 246, 255, 0.96)),
        #ffffff;
    box-shadow: 0 18px 42px rgba(15, 23, 42, 0.08);
}

.dashboard-product-demo-copy {
    display: grid;
    gap: 0.4rem;
}

.dashboard-product-demo-kicker {
    margin: 0;
    color: #0f766e;
    font-size: 0.72rem;
    font-weight: 800;
    letter-spacing: 0.12em;
    text-transform: uppercase;
}

.dashboard-product-demo-title {
    margin: 0;
    color: #0f172a;
    font-size: clamp(1.25rem, 2.4vw, 1.75rem);
    font-weight: 850;
    line-height: 1.08;
}

.dashboard-product-demo-body {
    margin: 0;
    color: #475569;
    font-size: 0.9rem;
    line-height: 1.6;
    max-width: 41rem;
}

.dashboard-product-demo-actions {
    display: grid;
    gap: 0.65rem;
    justify-items: start;
}

.dashboard-product-demo-form {
    margin: 0;
}

.dashboard-product-demo-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.55rem;
    min-height: 46px;
    padding: 0.8rem 1.05rem;
    border: 0;
    border-radius: 14px;
    background: linear-gradient(135deg, #0f172a, #1d4ed8);
    color: #ffffff;
    font-size: 0.9rem;
    font-weight: 800;
    text-decoration: none;
    cursor: pointer;
    box-shadow: 0 18px 34px rgba(29, 78, 216, 0.24);
    transition: transform 0.18s ease, box-shadow 0.18s ease, filter 0.18s ease;
}

.dashboard-product-demo-button:hover,
.dashboard-product-demo-button:focus-visible {
    transform: translateY(-1px);
    filter: brightness(1.02);
    box-shadow: 0 22px 40px rgba(29, 78, 216, 0.3);
}

.dashboard-product-demo-button:focus-visible {
    outline: 3px solid rgba(14, 165, 233, 0.24);
    outline-offset: 3px;
}

.dashboard-product-demo-note {
    color: #64748b;
    font-size: 0.8rem;
    line-height: 1.45;
}

.workspace-setup-complete-card {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 0.95rem 1rem;
    border: 1px solid #bbf7d0;
    border-radius: 14px;
    background: #f0fdf4;
    color: #166534;
    box-shadow: 0 12px 30px rgba(22, 101, 52, 0.08);
}

.workspace-setup-complete-message {
    display: flex;
    align-items: center;
    gap: 0.8rem;
    min-width: 0;
}

.workspace-setup-complete-icon {
    width: 2.25rem;
    height: 2.25rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    border-radius: 999px;
    background: #dcfce7;
    color: #15803d;
}

.workspace-setup-complete-title {
    margin: 0;
    color: #14532d;
    font-size: 0.95rem;
    font-weight: 850;
}

.workspace-setup-complete-copy {
    margin: 0.18rem 0 0;
    color: #166534;
    font-size: 0.84rem;
    line-height: 1.45;
}

.workspace-setup-complete-dismiss {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.35rem;
    flex: 0 0 auto;
    padding: 0.5rem 0.7rem;
    border: 1px solid rgba(22, 101, 52, 0.2);
    border-radius: 10px;
    background: #ffffff;
    color: #166534;
    font-weight: 750;
    cursor: pointer;
    transition: transform 0.18s ease, border-color 0.18s ease, box-shadow 0.18s ease;
}

.workspace-setup-complete-dismiss:hover {
    transform: translateY(-1px);
    border-color: rgba(22, 101, 52, 0.36);
    box-shadow: 0 10px 20px rgba(22, 101, 52, 0.1);
}

.dashboard-setup-popup {
    position: fixed;
    inset: 0;
    z-index: 1200;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1.25rem;
    background: rgba(15, 23, 42, 0.52);
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.18s ease;
}

.dashboard-setup-popup.is-visible {
    opacity: 1;
    pointer-events: auto;
}

.dashboard-setup-popup.is-leaving {
    opacity: 0;
}

.dashboard-setup-popup-card {
    width: min(100%, 520px);
    max-height: min(720px, calc(100vh - 2.5rem));
    overflow: auto;
    padding: 1.35rem;
    border-radius: 16px;
    background: #ffffff;
    border: 1px solid rgba(148, 163, 184, 0.24);
    box-shadow: 0 28px 80px rgba(15, 23, 42, 0.24);
    transform: translateY(10px) scale(0.98);
    transition: transform 0.18s ease;
}

.dashboard-setup-popup.is-visible .dashboard-setup-popup-card {
    transform: translateY(0) scale(1);
}

.dashboard-setup-popup-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
}

.dashboard-setup-popup-kicker {
    margin: 0 0 0.35rem;
    color: #2563eb;
    font-size: 0.72rem;
    font-weight: 800;
    letter-spacing: 0.12em;
    text-transform: uppercase;
}

.dashboard-setup-popup-title {
    margin: 0;
    color: #0f172a;
    font-size: 1.45rem;
    line-height: 1.12;
}

.dashboard-setup-popup-close {
    width: 2.25rem;
    height: 2.25rem;
    flex: 0 0 auto;
    border: 0;
    border-radius: 999px;
    background: #f1f5f9;
    color: #334155;
    cursor: pointer;
}

.dashboard-setup-popup-close:hover {
    background: #e2e8f0;
}

.dashboard-setup-popup-meter {
    position: relative;
    height: 0.65rem;
    margin: 1rem 0 0.75rem;
    overflow: hidden;
    border-radius: 999px;
    background: #e2e8f0;
}

.dashboard-setup-popup-meter span {
    display: block;
    height: 100%;
    border-radius: inherit;
    background: linear-gradient(90deg, #2563eb, #06b6d4);
}

.dashboard-setup-popup-summary {
    margin: 0;
    color: #475569;
    font-size: 0.94rem;
    line-height: 1.55;
}

.dashboard-setup-popup-actions {
    display: grid;
    gap: 0.7rem;
    margin-top: 1rem;
}

.dashboard-setup-popup-action {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: 0.7rem;
    padding: 0.85rem;
    border: 1px solid rgba(148, 163, 184, 0.24);
    border-radius: 12px;
    color: inherit;
    text-decoration: none;
    background: #f8fafc;
}

.dashboard-setup-popup-action.is-owner-prompt {
    border-color: #bfdbfe;
    background: #eff6ff;
}

.dashboard-setup-popup-action-icon {
    width: 2rem;
    height: 2rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 10px;
    background: #ffffff;
    color: #2563eb;
}

.dashboard-setup-popup-action strong,
.dashboard-setup-popup-action span:last-child {
    display: block;
}

.dashboard-setup-popup-action strong {
    color: #0f172a;
    font-size: 0.9rem;
}

.dashboard-setup-popup-action span:last-child {
    margin-top: 0.18rem;
    color: #64748b;
    font-size: 0.8rem;
    line-height: 1.35;
}

.dashboard-setup-popup-footer {
    display: flex;
    flex-wrap: wrap;
    gap: 0.65rem;
    justify-content: flex-end;
    margin-top: 1.15rem;
}

.dashboard-setup-popup-primary,
.dashboard-setup-popup-dismiss {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    min-height: 2.6rem;
    padding: 0.72rem 0.95rem;
    border-radius: 10px;
    font-weight: 800;
    text-decoration: none;
    cursor: pointer;
}

.dashboard-setup-popup-primary {
    border: 1px solid #2563eb;
    background: #2563eb;
    color: #ffffff;
}

.dashboard-setup-popup-dismiss {
    border: 1px solid rgba(148, 163, 184, 0.36);
    background: #ffffff;
    color: #334155;
}

.ai-coach-brief-gate-card {
    width: min(1120px, calc(100vw - 2rem));
    max-height: min(840px, calc(100vh - 2rem));
    padding: 0;
    overflow: hidden;
}

.ai-coach-brief-gate-shell {
    display: grid;
    grid-template-columns: minmax(290px, 0.78fr) minmax(0, 1.22fr);
    min-height: min(720px, calc(100vh - 2rem));
    max-height: inherit;
}

.ai-coach-brief-context-card {
    display: flex;
    flex-direction: column;
    gap: 1rem;
    min-width: 0;
    padding: clamp(1.45rem, 2.4vw, 2rem);
    border-right: 1px solid rgba(148, 163, 184, 0.22);
    background:
        linear-gradient(160deg, rgba(239, 246, 255, 0.96), rgba(248, 250, 252, 0.98)),
        #f8fafc;
}

.ai-coach-brief-context-panel,
.ai-coach-brief-video-card {
    border: 1px solid rgba(148, 163, 184, 0.24);
    border-radius: 8px;
    background: rgba(255, 255, 255, 0.86);
    padding: 1rem;
}

.ai-coach-brief-context-panel strong,
.ai-coach-brief-video-card strong {
    display: block;
    color: #0f172a;
    font-size: 0.92rem;
    font-weight: 850;
}

.ai-coach-brief-context-panel p,
.ai-coach-brief-video-card p {
    margin: 0.35rem 0 0;
    color: #475569;
    font-size: 0.86rem;
    line-height: 1.5;
}

.ai-coach-brief-context-list {
    display: grid;
    gap: 0.55rem;
    margin: 0.8rem 0 0;
    padding: 0;
    list-style: none;
}

.ai-coach-brief-context-list li {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: 0.55rem;
    align-items: start;
    color: #334155;
    font-size: 0.84rem;
    line-height: 1.45;
}

.ai-coach-brief-context-list i {
    margin-top: 0.18rem;
    color: #2563eb;
}

.ai-coach-brief-video-button {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    width: 100%;
    min-height: 2.7rem;
    margin-top: 0.8rem;
    border: 1px solid rgba(37, 99, 235, 0.28);
    border-radius: 8px;
    background: #ffffff;
    color: #1d4ed8;
    font-weight: 850;
    cursor: pointer;
}

.ai-coach-brief-video-button:hover,
.ai-coach-brief-video-button:focus-visible {
    border-color: rgba(37, 99, 235, 0.48);
    box-shadow: 0 12px 24px rgba(37, 99, 235, 0.12);
    outline: none;
}

.ai-coach-brief-workspace {
    display: flex;
    flex-direction: column;
    min-width: 0;
    min-height: 0;
    background: #ffffff;
}

.ai-coach-brief-gate-form {
    display: flex;
    flex-direction: column;
    min-height: 0;
    margin: 0;
    flex: 1 1 auto;
}

.ai-coach-brief-form-head {
    padding: clamp(1.25rem, 2.2vw, 1.75rem) clamp(1.25rem, 2.6vw, 2rem) 1rem;
    border-bottom: 1px solid rgba(148, 163, 184, 0.16);
}

.ai-coach-brief-form-head h3 {
    margin: 0;
    color: #0f172a;
    font-size: 1.05rem;
    line-height: 1.25;
}

.ai-coach-brief-form-head p {
    margin: 0.35rem 0 0;
    color: #64748b;
    font-size: 0.88rem;
    line-height: 1.5;
}

.ai-coach-brief-gate-fields {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    align-content: start;
    gap: 1rem;
    min-height: 0;
    overflow: auto;
    padding: clamp(1.25rem, 2.6vw, 2rem);
    background: #f8fafc;
}

.ai-coach-brief-gate-field {
    display: grid;
    gap: 0.55rem;
    color: #0f172a;
    font-size: 0.86rem;
    font-weight: 750;
}

.ai-coach-brief-field-card {
    min-width: 0;
    padding: 1rem;
    border: 1px solid rgba(148, 163, 184, 0.24);
    border-radius: 8px;
    background: #ffffff;
}

.ai-coach-brief-field-title {
    color: #0f172a;
    font-size: 0.9rem;
    font-weight: 850;
}

.ai-coach-brief-field-help {
    color: #64748b;
    font-size: 0.8rem;
    line-height: 1.45;
}

.ai-coach-brief-gate-field textarea {
    width: 100%;
    resize: vertical;
    min-height: 7.2rem;
    padding: 0.85rem 0.9rem;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    background: #ffffff;
    color: #0f172a;
    font: inherit;
    font-weight: 500;
    line-height: 1.45;
}

.ai-coach-brief-gate-field textarea:focus {
    outline: 3px solid rgba(37, 99, 235, 0.16);
    border-color: #2563eb;
}

.ai-coach-brief-gate-error {
    margin: 0 clamp(1.25rem, 2.6vw, 2rem) 1rem;
    padding: 0.75rem 0.85rem;
    border: 1px solid #fecaca;
    border-radius: 8px;
    background: #fef2f2;
    color: #991b1b;
    font-size: 0.86rem;
    font-weight: 700;
}

.ai-coach-brief-gate-form .dashboard-setup-popup-footer {
    margin: 0;
    padding: 1rem clamp(1.25rem, 2.6vw, 2rem);
    border-top: 1px solid rgba(148, 163, 184, 0.18);
    background: #ffffff;
    box-shadow: 0 -12px 28px rgba(15, 23, 42, 0.04);
}

.dashboard-video-frame iframe {
    width: 100%;
    aspect-ratio: 16 / 9;
    min-height: min(520px, calc(100vh - 8rem));
    display: block;
    border: 0;
    background: #020617;
}

.dashboard-inline-alert {
    margin: 0 auto 1rem;
    max-width: 1080px;
    padding: 0.8rem 1rem;
    border-radius: 12px;
    font-weight: 700;
}

.dashboard-inline-alert.success {
    background: #f0fdf4;
    border: 1px solid #bbf7d0;
    color: #166534;
}

.dashboard-inline-alert.error {
    background: #fef2f2;
    border: 1px solid #fecaca;
    color: #991b1b;
}

.platform-ops-summary {
    max-width: 1080px;
    margin: 0 auto 1rem;
    padding: 1rem;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    background: #ffffff;
}

.platform-ops-summary-top {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
    align-items: flex-start;
    margin-bottom: 0.9rem;
}

.platform-ops-summary-label {
    margin: 0 0 0.25rem;
    color: #64748b;
    font-size: 0.75rem;
    font-weight: 800;
    text-transform: uppercase;
}

.platform-ops-summary-title {
    margin: 0;
    color: #0f172a;
    font-size: 1.05rem;
}

.platform-ops-summary-actions {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.platform-ops-summary-actions a {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    min-height: 2.25rem;
    padding: 0.55rem 0.75rem;
    border-radius: 8px;
    border: 1px solid #cbd5e1;
    color: #0f172a;
    text-decoration: none;
    font-weight: 800;
    font-size: 0.82rem;
}

.platform-ops-summary-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 0.65rem;
}

.platform-ops-summary-metric {
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 0.75rem;
    background: #f8fafc;
}

.platform-ops-summary-value {
    color: #0f172a;
    font-size: 1.35rem;
    font-weight: 900;
    line-height: 1;
}

.platform-ops-summary-caption {
    margin-top: 0.35rem;
    color: #64748b;
    font-size: 0.78rem;
    line-height: 1.3;
}

.platform-ops-workload {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
    gap: 0.65rem;
    margin-top: 0.9rem;
}

.platform-ops-workload-card {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: 0.7rem;
    align-items: center;
    min-height: 72px;
    padding: 0.75rem;
    border: 1px solid #dbeafe;
    border-radius: 8px;
    background: #f8fafc;
}

.platform-ops-workload-card.admin {
    border-color: #fed7aa;
    background: #fff7ed;
}

.platform-ops-workload-card.waiting {
    border-color: #bfdbfe;
    background: #eff6ff;
}

.platform-ops-workload-card.cleared {
    border-color: #bbf7d0;
    background: #f0fdf4;
}

.platform-ops-workload-card.failed {
    border-color: #fecaca;
    background: #fef2f2;
}

.platform-ops-workload-icon {
    width: 2.25rem;
    height: 2.25rem;
    display: inline-grid;
    place-items: center;
    border-radius: 8px;
    background: #ffffff;
    color: #0f172a;
    border: 1px solid rgba(148, 163, 184, 0.35);
}

.platform-ops-workload-value {
    color: #0f172a;
    font-size: 1.3rem;
    font-weight: 900;
    line-height: 1;
}

.platform-ops-workload-label {
    margin-top: 0.25rem;
    color: #475569;
    font-size: 0.78rem;
    line-height: 1.3;
}

.platform-ops-status-band {
    display: grid;
    grid-template-columns: minmax(190px, 0.7fr) minmax(420px, 1.45fr) minmax(300px, 1fr);
    gap: 1rem;
    align-items: stretch;
    margin-top: 0.9rem;
    padding-top: 0.9rem;
    border-top: 1px solid rgba(148, 163, 184, 0.3);
}

.platform-ops-status-main,
.platform-ops-status-metrics,
.platform-ops-review-panel {
    min-width: 0;
}

.platform-ops-status-metrics,
.platform-ops-review-panel {
    padding-left: 1rem;
    border-left: 1px solid #e2e8f0;
}

.platform-ops-status-title {
    margin-bottom: 0.4rem;
    color: #64748b;
    font-size: 0.72rem;
    font-weight: 900;
    text-transform: uppercase;
}

.platform-ops-health-badge {
    display: flex;
    gap: 0.45rem;
    align-items: center;
    flex-wrap: wrap;
    color: #0f172a;
    font-size: 1rem;
    font-weight: 900;
}

.platform-ops-status-subtitle {
    display: block;
    margin-top: 0.45rem;
    color: #64748b;
    font-weight: 600;
    font-size: 0.78rem;
    line-height: 1.35;
}

.platform-ops-status-dot {
    width: 0.55rem;
    height: 0.55rem;
    border-radius: 8px;
    background: #16a34a;
}

.platform-ops-status-dot.warning {
    background: #d97706;
}

.platform-ops-status-dot.critical {
    background: #dc2626;
}

.platform-ops-status-chips,
.platform-ops-review-chips {
    display: grid;
    gap: 0.45rem;
}

.platform-ops-status-chips {
    grid-template-columns: repeat(3, minmax(0, 1fr));
}

.platform-ops-review-chips {
    grid-template-columns: repeat(2, minmax(0, 1fr));
}

.platform-ops-status-chip,
.platform-ops-review-chip {
    min-height: 2.35rem;
    padding: 0.45rem 0.55rem;
    border: 1px solid #dbeafe;
    border-radius: 8px;
    background: #f8fafc;
    color: #334155;
    line-height: 1.25;
}

.platform-ops-status-chip {
    display: grid;
    gap: 0.1rem;
    align-content: center;
    text-align: left;
}

.platform-ops-chip-label {
    color: #64748b;
    font-size: 0.66rem;
    font-weight: 900;
    text-transform: uppercase;
}

.platform-ops-chip-value {
    color: #0f172a;
    font-size: 0.88rem;
    font-weight: 900;
    overflow-wrap: anywhere;
}

.platform-ops-review-chip {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-align: center;
    color: #9a3412;
    font-size: 0.78rem;
    font-weight: 800;
}

.platform-ops-review-chip {
    border-color: #fed7aa;
    background: #fff7ed;
}

.platform-ops-review-title {
    margin-bottom: 0.45rem;
    color: #64748b;
    font-size: 0.74rem;
    font-weight: 800;
    text-transform: uppercase;
}

@media (max-width: 980px) {
    .ai-coach-brief-gate-card {
        width: min(100%, calc(100vw - 1.25rem));
        max-height: calc(100vh - 1.25rem);
    }

    .ai-coach-brief-gate-shell {
        grid-template-columns: 1fr;
        min-height: 0;
        overflow: auto;
    }

    .ai-coach-brief-context-card {
        border-right: 0;
        border-bottom: 1px solid rgba(148, 163, 184, 0.22);
    }

    .ai-coach-brief-workspace {
        min-height: 520px;
    }

    .platform-ops-status-band {
        grid-template-columns: 1fr;
    }

    .platform-ops-status-metrics,
    .platform-ops-review-panel {
        padding-left: 0;
        border-left: 0;
    }

    .platform-ops-status-chips,
    .platform-ops-review-chips {
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    }
}

.platform-ops-row-actions {
    display: flex;
    gap: 0.35rem;
    flex-wrap: wrap;
    align-items: center;
    min-width: 150px;
}

.platform-ops-row-actions form {
    margin: 0;
}

.platform-ops-action-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 2rem;
    padding: 0.42rem 0.65rem;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    background: #ffffff;
    color: #0f172a;
    font-size: 0.78rem;
    font-weight: 800;
    line-height: 1;
    text-decoration: none;
}

.platform-ops-action-link.primary {
    border-color: #bfdbfe;
    background: #eff6ff;
    color: #1d4ed8;
}

.automation-health-band {
    display: grid;
    grid-template-columns: minmax(0, 1.2fr) minmax(360px, 1fr);
    gap: 1rem;
    align-items: center;
    margin-bottom: 1rem;
    padding: 1rem 1.1rem;
    border: 1px solid #dbe4ef;
    border-radius: 16px;
    background: #ffffff;
    box-shadow: 0 14px 32px rgba(15, 23, 42, 0.06);
}

.automation-health-band.healthy {
    border-color: #bbf7d0;
    background: #f7fef9;
}

.automation-health-band.building {
    border-color: #bae6fd;
    background: #f8fcff;
}

.automation-health-band.warning {
    border-color: #fde68a;
    background: #fffbeb;
}

.automation-health-band.critical {
    border-color: #fecaca;
    background: #fef2f2;
}

.automation-health-main {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: 0.85rem;
    align-items: start;
    min-width: 0;
}

.automation-health-dot {
    width: 0.72rem;
    height: 0.72rem;
    margin-top: 0.42rem;
    border-radius: 999px;
    background: #0ea5e9;
    box-shadow: 0 0 0 4px rgba(14, 165, 233, 0.14);
}

.automation-health-band.healthy .automation-health-dot {
    background: #16a34a;
    box-shadow: 0 0 0 4px rgba(22, 163, 74, 0.14);
}

.automation-health-band.warning .automation-health-dot {
    background: #f59e0b;
    box-shadow: 0 0 0 4px rgba(245, 158, 11, 0.16);
}

.automation-health-band.critical .automation-health-dot {
    background: #dc2626;
    box-shadow: 0 0 0 4px rgba(220, 38, 38, 0.14);
}

.automation-health-copy {
    min-width: 0;
}

.automation-health-title {
    color: #0f172a;
    font-size: 1.18rem;
    font-weight: 850;
    line-height: 1.18;
}

.automation-health-message {
    margin-top: 0.35rem;
    color: #475569;
    font-size: 0.9rem;
    line-height: 1.5;
}

.automation-health-clear {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    margin-top: 0.65rem;
    padding: 0.38rem 0.62rem;
    border: 1px solid #bbf7d0;
    border-radius: 999px;
    background: #f0fdf4;
    color: #166534;
    font-size: 0.78rem;
    font-weight: 850;
}

.automation-health-clear[hidden] {
    display: none;
}

.automation-health-metrics {
    display: grid;
    grid-template-columns: repeat(3, minmax(82px, 1fr));
    gap: 0.65rem;
    min-width: 0;
}

.automation-health-metric {
    min-width: 0;
    padding: 0.68rem 0.72rem;
    border: 1px solid rgba(148, 163, 184, 0.26);
    border-radius: 12px;
    background: rgba(255, 255, 255, 0.82);
}

.automation-health-metric.wide {
    grid-column: span 3;
}

.automation-health-metric strong {
    display: block;
    min-width: 0;
    color: #0f172a;
    font-size: 0.98rem;
    font-weight: 850;
    line-height: 1.2;
    overflow-wrap: anywhere;
}

.automation-health-metric span {
    display: block;
    margin-top: 0.18rem;
    color: #64748b;
    font-size: 0.7rem;
    font-weight: 800;
    letter-spacing: 0.08em;
    text-transform: uppercase;
}

.automation-readiness-grid {
    display: grid;
    grid-template-columns: minmax(220px, 280px) 1fr;
    gap: 1rem;
    align-items: start;
}

.automation-readiness-summary-card,
.automation-readiness-signals-card {
    background: #ffffff;
    border: 1px solid #e2e8f0;
    border-radius: 18px;
    padding: 1rem 1.1rem;
    box-shadow: 0 14px 32px rgba(15, 23, 42, 0.06);
}

.automation-readiness-actions {
    display: flex;
    gap: 0.65rem;
    flex-wrap: wrap;
    margin-top: 0.9rem;
}

.automation-readiness-load-button {
    appearance: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.45rem;
    border-radius: 999px;
    border: 1px solid rgba(8, 145, 178, 0.24);
    background: #ecfeff;
    color: #155e75;
    font-size: 0.82rem;
    font-weight: 850;
    padding: 0.58rem 0.9rem;
    cursor: pointer;
    text-decoration: none;
}

.automation-readiness-load-button {
    width: 2.5rem;
    height: 2.5rem;
    padding: 0;
}

.automation-readiness-load-button:disabled {
    cursor: wait;
    opacity: 0.72;
}

.automation-readiness-label {
    font-size: 0.76rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: #64748b;
    margin-bottom: 0.45rem;
}

.automation-readiness-value {
    font-size: 1.6rem;
    font-weight: 800;
    color: #0f172a;
    margin-bottom: 0.35rem;
}

.automation-readiness-copy {
    font-size: 0.93rem;
    color: #475569;
    line-height: 1.6;
}

.automation-readiness-list {
    display: flex;
    flex-wrap: wrap;
    gap: 0.55rem;
    margin-top: 0.85rem;
}

.automation-readiness-meta {
    margin-top: 0.75rem;
    font-size: 0.82rem;
    color: #64748b;
    font-weight: 600;
}

.automation-readiness-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.45rem 0.72rem;
    border-radius: 999px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    color: #334155;
    font-size: 0.8rem;
    line-height: 1.2;
    text-decoration: none;
}

.automation-readiness-chip.blocker {
    background: #fff7ed;
    border-color: #fdba74;
    color: #9a3412;
}

.automation-readiness-chip.required {
    background: #fff7ed;
    border-color: #fdba74;
    color: #9a3412;
}

.automation-readiness-chip.recommended {
    background: #eff6ff;
    border-color: #bfdbfe;
    color: #1d4ed8;
}

.automation-readiness-chip.optional-enrichment {
    background: #f8fafc;
    border-color: #cbd5e1;
    color: #475569;
}

.automation-readiness-chip.booster {
    background: #ecfeff;
    border-color: #99f6e4;
    color: #0f766e;
}

.automation-readiness-chip.progress {
    background: #f8fafc;
    border-color: #cbd5e1;
    color: #475569;
}

.automation-readiness-chip.progress.warning {
    background: #fffbeb;
    border-color: #fde68a;
    color: #92400e;
}

.automation-readiness-chip.progress.critical {
    background: #fef2f2;
    border-color: #fecaca;
    color: #991b1b;
}

a.automation-readiness-chip:hover,
a.automation-readiness-chip:focus-visible {
    transform: translateY(-1px);
    box-shadow: 0 8px 18px rgba(15, 23, 42, 0.08);
}

.automation-layer-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
    gap: 0.85rem;
    margin-top: 1rem;
}

.automation-layer-card {
    border: 1px solid #e2e8f0;
    border-radius: 16px;
    padding: 0.9rem;
    background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
}

.automation-layer-top {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 0.75rem;
}

.automation-layer-name {
    font-size: 0.74rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: #64748b;
    margin-bottom: 0.3rem;
}

.automation-layer-status {
    display: inline-flex;
    align-items: center;
    padding: 0.22rem 0.55rem;
    border-radius: 999px;
    font-size: 0.72rem;
    font-weight: 700;
    white-space: nowrap;
}

.automation-layer-status.low {
    background: #fff7ed;
    color: #c2410c;
}

.automation-layer-status.medium {
    background: #fef3c7;
    color: #a16207;
}

.automation-layer-status.high {
    background: #dcfce7;
    color: #166534;
}

.automation-layer-status.full {
    background: #dbeafe;
    color: #1d4ed8;
}

.automation-layer-value {
    font-size: 1.08rem;
    font-weight: 800;
    color: #0f172a;
}

.automation-layer-copy {
    margin-top: 0.5rem;
    font-size: 0.82rem;
    color: #475569;
    line-height: 1.5;
}

.automation-layer-signals {
    display: flex;
    flex-wrap: wrap;
    gap: 0.45rem;
    margin-top: 0.7rem;
}

.automation-layer-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    padding: 0.32rem 0.55rem;
    border-radius: 999px;
    font-size: 0.72rem;
    line-height: 1.2;
    border: 1px solid #e2e8f0;
    background: #ffffff;
    color: #334155;
    text-decoration: none;
}

.automation-layer-chip.blocker {
    background: #fff7ed;
    border-color: #fdba74;
    color: #9a3412;
}

.automation-layer-chip.required {
    background: #fff7ed;
    border-color: #fdba74;
    color: #9a3412;
}

.automation-layer-chip.recommended {
    background: #eff6ff;
    border-color: #bfdbfe;
    color: #1d4ed8;
}

.automation-layer-chip.optional-enrichment {
    background: #f8fafc;
    border-color: #cbd5e1;
    color: #475569;
}

.automation-layer-chip.signal {
    background: #eff6ff;
    border-color: #bfdbfe;
    color: #1d4ed8;
}

.automation-layer-chip.progress {
    background: #f8fafc;
    border-color: #cbd5e1;
    color: #475569;
}

.automation-layer-chip.progress.warning {
    background: #fffbeb;
    border-color: #fde68a;
    color: #92400e;
}

.automation-layer-chip.progress.critical {
    background: #fef2f2;
    border-color: #fecaca;
    color: #991b1b;
}

a.automation-layer-chip:hover,
a.automation-layer-chip:focus-visible {
    transform: translateY(-1px);
    box-shadow: 0 6px 14px rgba(15, 23, 42, 0.08);
}

.ttfv-heading {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
}

.ttfv-help {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 0.9rem;
    height: 0.9rem;
    border: 1px solid #cbd5e1;
    border-radius: 999px;
    background: #f8fafc;
    color: #64748b;
    font-size: 0.62rem;
    font-weight: 800;
    line-height: 1;
    cursor: help;
    user-select: none;
}

.ttfv-help:focus-visible {
    outline: 2px solid #93c5fd;
    outline-offset: 2px;
}

.ttfv-tooltip {
    position: absolute;
    z-index: 20;
    left: 50%;
    bottom: calc(100% + 0.45rem);
    width: max-content;
    max-width: 220px;
    padding: 0.4rem 0.5rem;
    border: 1px solid #cbd5e1;
    border-radius: 8px;
    background: #ffffff;
    box-shadow: 0 10px 24px rgba(15, 23, 42, 0.14);
    color: #334155;
    font-size: 0.68rem;
    font-weight: 500;
    line-height: 1.35;
    opacity: 0;
    pointer-events: none;
    transform: translateX(-50%) translateY(3px);
    visibility: hidden;
    white-space: normal;
}

.ttfv-help:hover .ttfv-tooltip,
.ttfv-help:focus .ttfv-tooltip,
.ttfv-help:focus-visible .ttfv-tooltip {
    opacity: 1;
    transform: translateX(-50%) translateY(0);
    visibility: visible;
}

@media (min-width: 768px) and (max-width: 1280px) {
    .dashboard-premium {
        padding-top: 0.75rem;
    }

    .hero-minimal {
        min-height: 76vh;
        padding: 2.5rem 1.5rem 2rem;
        align-items: flex-start;
    }

    .hero-minimal-content {
        max-width: 760px;
        padding-top: 5.5rem;
    }

    .hero-minimal-controls {
        top: 1.15rem;
        right: 1.15rem;
    }

    .hero-filter-trigger {
        width: 40px;
        height: 40px;
        border-radius: 12px;
        box-shadow: 0 10px 24px rgba(15, 23, 42, 0.08);
    }

    .hero-guide-btn {
        min-height: 40px;
        border-radius: 12px;
        padding: 0 0.85rem;
    }

    .hero-filter-panel {
        min-width: 220px;
        padding: 0.75rem;
        border-radius: 14px;
    }

    .hero-minimal-controls .timeframe-select {
        min-width: 200px;
    }

    .hero-automation-battery {
        margin-bottom: 0.8rem;
    }

    .hero-automation-battery-top {
        gap: 0.65rem;
    }

    .hero-automation-battery-top > div {
        gap: 0.55rem !important;
    }

    .hero-automation-shell {
        width: 128px;
        height: 56px;
        border-radius: 16px;
    }

    .hero-automation-shell::after {
        top: 16px;
        height: 18px;
    }

    .hero-automation-score-inside {
        font-size: 1rem;
    }

    .hero-automation-battery-status {
        font-size: 0.72rem;
        padding: 0.34rem 0.62rem;
    }

    .hero-minimal-title {
        font-size: clamp(3.1rem, 6vw, 4.35rem);
        margin-bottom: 0.9rem;
        line-height: 1.02;
    }

    .hero-minimal-subtitle {
        max-width: 680px;
        margin: 0 auto;
        font-size: 1rem;
        line-height: 1.5;
    }

    .hero-ai-coach-btn {
        margin-top: 1rem;
    }

    .automation-readiness-card {
        max-width: 980px;
        margin-bottom: 0.75rem;
    }
}

@media (min-width: 768px) and (max-width: 980px) {
    .hero-minimal {
        min-height: 68vh;
        padding-top: 1.75rem;
    }

    .hero-minimal-content {
        max-width: 680px;
        padding-top: 4.5rem;
    }

    .hero-minimal-controls {
        top: 0.9rem;
        right: 0.9rem;
    }

    .hero-minimal-title {
        font-size: clamp(2.75rem, 7vw, 3.75rem);
    }

    .hero-minimal-subtitle {
        max-width: 560px;
        font-size: 0.96rem;
    }

    .automation-readiness-grid {
        grid-template-columns: 1fr;
    }

    .automation-health-band {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 767px) {
    .dashboard-setup-popup {
        padding: 0.55rem;
    }

    .ai-coach-brief-gate-card {
        width: 100%;
        max-height: calc(100vh - 1.1rem);
        border-radius: 12px;
    }

    .ai-coach-brief-context-card,
    .ai-coach-brief-form-head,
    .ai-coach-brief-gate-fields,
    .ai-coach-brief-gate-form .dashboard-setup-popup-footer {
        padding-left: 1rem;
        padding-right: 1rem;
    }

    .ai-coach-brief-gate-fields {
        grid-template-columns: 1fr;
    }

    .ai-coach-brief-gate-field textarea {
        min-height: 6.5rem;
    }

    .ai-coach-brief-gate-form .dashboard-setup-popup-footer {
        justify-content: stretch;
    }

    .ai-coach-brief-gate-form .dashboard-setup-popup-primary {
        width: 100%;
        justify-content: center;
    }

    .hero-minimal {
        min-height: min(540px, 60svh);
        flex-direction: column;
        justify-content: flex-start;
        padding: clamp(2.35rem, 6.5vh, 3.75rem) 1rem 1.35rem;
        overflow: visible;
    }

    .hero-minimal-content {
        order: 1;
        width: min(100%, 22rem);
        max-width: 22rem;
        margin: 0 auto;
        text-align: center;
    }

    .hero-minimal-controls {
        order: 2;
        position: static;
        width: min(100%, 19rem);
        flex-wrap: wrap;
        justify-content: center;
        gap: 0.6rem;
        margin: 1.35rem auto 0;
    }

    .hero-guide-btn {
        min-height: 42px;
        border-radius: 13px;
        padding: 0 0.9rem;
        box-shadow: 0 12px 26px rgba(15, 23, 42, 0.07);
    }

    .hero-filter-trigger {
        width: 42px;
        height: 42px;
        border-radius: 13px;
    }

    .hero-filter-panel {
        position: fixed;
        top: 4.75rem;
        left: 50%;
        right: auto;
        min-width: 0;
        box-sizing: border-box;
        transform: translateX(-50%);
        width: min(292px, calc(100vw - 2rem));
        z-index: 40;
    }

    .hero-filter-panel::before {
        right: auto;
        left: 50%;
        transform: translateX(-50%) rotate(45deg);
    }

    .hero-minimal-controls .timeframe-select {
        min-width: 0;
        width: 100%;
    }

    .hero-automation-battery {
        display: flex;
        width: 100%;
        margin: 0 auto 1rem;
        justify-content: center;
    }

    .hero-automation-battery-top {
        flex-direction: row;
        flex-wrap: wrap;
        justify-content: center;
        width: 100%;
    }

    .hero-automation-battery-top > div {
        justify-content: center;
        gap: 0.7rem !important;
        max-width: 100%;
    }

    .hero-automation-shell {
        width: 124px;
        height: 56px;
    }

    .hero-automation-score-inside {
        font-size: 1rem;
    }

    .hero-automation-battery-status {
        font-size: 0.72rem;
        padding: 0.34rem 0.62rem;
    }

    .hero-minimal-title {
        max-width: 17rem;
        margin: 0 auto 0.8rem;
        font-size: clamp(2.25rem, 8.2vw, 2.9rem);
        line-height: 1.08;
        letter-spacing: 0;
        overflow: visible;
        padding-bottom: 0.04em;
    }

    .hero-minimal-subtitle {
        max-width: 18.5rem;
        margin: 0 auto;
        font-size: 0.92rem;
        line-height: 1.58;
        letter-spacing: 0.01em;
    }

    .automation-readiness-grid {
        grid-template-columns: 1fr;
    }

    .automation-health-band {
        grid-template-columns: 1fr;
        padding: 0.9rem;
    }

    .automation-health-metrics {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .automation-health-metric.wide {
        grid-column: span 2;
    }

    .workspace-setup-impact-card {
        grid-template-columns: 1fr;
    }

    .dashboard-product-demo-card {
        grid-template-columns: 1fr;
    }

    .workspace-setup-complete-card {
        align-items: flex-start;
        flex-direction: column;
    }

    .workspace-setup-complete-dismiss {
        align-self: flex-end;
    }

    .automation-layer-grid {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 430px) {
    .hero-minimal {
        min-height: min(460px, 54svh);
        padding-top: clamp(2rem, 5.5vh, 3rem);
        padding-bottom: 0.85rem;
    }

    .hero-minimal-content {
        max-width: 20.5rem;
    }

    .hero-automation-battery {
        margin-bottom: 0.85rem;
    }

    .automation-health-metrics {
        grid-template-columns: 1fr;
    }

    .automation-health-metric.wide {
        grid-column: span 1;
    }

    .hero-minimal-title {
        max-width: 16rem;
        font-size: clamp(2.05rem, 8.8vw, 2.55rem);
        margin-bottom: 0.7rem;
    }

    .hero-minimal-subtitle {
        max-width: 17.2rem;
        font-size: 0.88rem;
        line-height: 1.55;
    }

    .hero-minimal-controls {
        margin-top: 1.1rem;
    }
}
</style>

<?php echo VideoBrandOverlayUi::assets(); ?>

<?php if ($showOnboardingCelebration): ?>
<div class="clarity-workspace-welcome" id="clarity-workspace-welcome" role="dialog" aria-modal="true" aria-labelledby="clarity-welcome-title">
    <div class="clarity-welcome-card">
        <div class="clarity-welcome-sparkles" aria-hidden="true">
            <span></span>
            <span></span>
            <span></span>
            <span></span>
            <span></span>
        </div>
        <img class="clarity-welcome-logo" src="<?php echo htmlspecialchars(assetUrl('images/clarity-logo-256.png')); ?>" alt="Clarity">
        <p class="clarity-welcome-kicker">Workspace ready</p>
        <h2 class="clarity-welcome-title" id="clarity-welcome-title">Welcome to Clarity</h2>
        <p class="clarity-welcome-copy"><?php echo $onboardingCelebrationMode === 'quick_start' ? 'Quick Start is done. Clarity has the essentials, and the remaining setup is waiting on your dashboard checklist.' : 'Your workspace is open. Clarity has the first context it needs to help with follow-ups, tasks, targets, templates, and calmer decisions.'; ?></p>
        <div class="clarity-welcome-actions">
            <button type="button" class="clarity-welcome-start" id="clarity-welcome-start">Start exploring</button>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($showAICoachBriefGate)): ?>
<?php
    $aiCoachBriefPayload = (array) ($aiCoachBriefGate['onboarding_payload'] ?? []);
    $aiCoachBriefStrategy = (array) ($aiCoachBriefPayload['strategy'] ?? []);
    $aiCoachBriefIdea = (array) ($aiCoachBriefPayload['idea_validation'] ?? []);
    $aiCoachBriefClarityReady = !empty($aiCoachBriefGate['clarity_journey_ready']);
    $aiCoachBriefClarityReadiness = (array) ($aiCoachBriefGate['clarity_journey_readiness'] ?? []);
    $aiCoachBriefCurrentStage = trim((string) ($aiCoachBriefClarityReadiness['current_stage_key'] ?? ''));
    $aiCoachBriefClarityUrl = 'startup_journey.php' . ($aiCoachBriefCurrentStage !== '' ? '?stage=' . urlencode($aiCoachBriefCurrentStage) : '');
    $aiCoachBriefMissingFields = array_values(array_unique(array_filter(array_map(
        static function ($item): string {
            $item = (array) $item;
            return (string) ($item['field'] ?? '');
        },
        (array) ($aiCoachBriefGate['missing_requirements'] ?? [])
    ))));
    $aiCoachBriefFieldDefinitions = [
        'ideal_customer_profile' => [
            'label' => 'Ideal customer profile',
            'source' => 'strategy',
            'help' => 'This anchors the audience AI Coach should keep in mind when ranking next actions.',
            'placeholder' => 'Who should AI Coach assume you are trying to reach?',
        ],
        'market_view' => [
            'label' => 'Market view',
            'source' => 'strategy',
            'help' => 'Name the shifts, constraints, or opportunities AI Coach should consider.',
            'placeholder' => 'What is changing in your market or segment?',
        ],
        'strategy_hypothesis' => [
            'label' => 'Strategy to test',
            'source' => 'strategy',
            'help' => 'Describe the bet you want AI Coach to pressure-test through recommendations.',
            'placeholder' => 'What approach should AI Coach pressure-test for you?',
        ],
        'competitors' => [
            'label' => 'Competitors or alternatives',
            'source' => 'idea',
            'help' => 'Help AI Coach understand the alternatives your customers compare you against.',
            'placeholder' => 'Who or what are you positioned against?',
        ],
    ];
?>
<div class="dashboard-setup-popup ai-coach-brief-gate" id="ai-coach-brief-gate" role="dialog" aria-modal="true" aria-labelledby="ai-coach-brief-gate-title" data-action-url="<?php echo htmlspecialchars(apiUrl('ai-coach/onboarding.php')); ?>" data-csrf-token="<?php echo htmlspecialchars($dashboardCsrfToken); ?>">
    <div class="dashboard-setup-popup-card ai-coach-brief-gate-card" role="document">
        <div class="ai-coach-brief-gate-shell">
            <aside class="ai-coach-brief-context-card">
                <div class="dashboard-setup-popup-header">
                    <div>
                        <p class="dashboard-setup-popup-kicker">AI Coach context</p>
                        <h2 class="dashboard-setup-popup-title" id="ai-coach-brief-gate-title"><?php echo $aiCoachBriefClarityReady ? 'Refine your Coach context' : 'Add optional Journey context'; ?></h2>
                    </div>
                </div>
                <p class="dashboard-setup-popup-summary"><?php echo $aiCoachBriefClarityReady
                    ? 'AI Coach has Journey context. Add only the missing personal lens so recommendations can match your role and operating strategy.'
                    : 'AI Coach is enabled. Clarity Journey can improve strategy recommendations with customer, problem, offer, GTM, MVP, metrics, and OKR context, but it does not block automation readiness.'; ?></p>
                <div class="ai-coach-brief-context-panel">
                    <strong>Why this is optional</strong>
                    <p><?php echo $aiCoachBriefClarityReady
                        ? 'Clarity Journey supplies useful business context. These extra fields only sharpen the personal operating lens where Journey context is still silent.'
                        : 'Clarity Journey is optional AI context for stronger Coach guidance. Core automation readiness is still based on operational setup, controls, and evidence.'; ?></p>
                    <ul class="ai-coach-brief-context-list">
                        <?php if ($aiCoachBriefClarityReady): ?>
                            <li><i class="fas fa-check-circle" aria-hidden="true"></i><span>Keeps recommendations explainable by tying them back to your Journey context.</span></li>
                            <li><i class="fas fa-check-circle" aria-hidden="true"></i><span>Uses personal refinements only where inherited context is missing.</span></li>
                            <li><i class="fas fa-check-circle" aria-hidden="true"></i><span>Supports better task suggestions without changing shared company setup.</span></li>
                        <?php else: ?>
                            <li><i class="fas fa-check-circle" aria-hidden="true"></i><span>Saves optional strategy context AI Coach can use.</span></li>
                            <li><i class="fas fa-check-circle" aria-hidden="true"></i><span>Gives Founder Loop the inputs it needs to push first deals.</span></li>
                            <li><i class="fas fa-check-circle" aria-hidden="true"></i><span>Prevents duplicate strategy forms after Journey completion.</span></li>
                        <?php endif; ?>
                    </ul>
                </div>
                <?php if ($aiCoachExplainerVideoUrl !== ''): ?>
                    <div class="ai-coach-brief-video-card">
                        <strong>Need the quick tour?</strong>
                        <p>Watch the AI Coach explainer, then complete the required fields here.</p>
                        <button type="button" class="ai-coach-brief-video-button" data-ai-coach-brief-video-open>
                            <i class="fas fa-play" aria-hidden="true"></i>
                            <span>Watch explainer</span>
                        </button>
                    </div>
                <?php endif; ?>
            </aside>
            <div class="ai-coach-brief-workspace">
                <?php if (!$aiCoachBriefClarityReady): ?>
                    <div class="ai-coach-brief-gate-form">
                        <div class="ai-coach-brief-form-head">
                            <h3>Continue the Journey</h3>
                            <p>Open Clarity Journey when you want richer strategy context for AI Coach recommendations.</p>
                        </div>
                        <div class="dashboard-setup-popup-footer">
                            <a class="dashboard-setup-popup-primary" href="<?php echo htmlspecialchars($aiCoachBriefClarityUrl); ?>">
                                <i class="fas fa-compass" aria-hidden="true"></i>
                                <span>Open Clarity Journey</span>
                            </a>
                        </div>
                    </div>
                <?php else: ?>
                    <form class="ai-coach-brief-gate-form" data-ai-coach-brief-form>
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($dashboardCsrfToken); ?>">
                        <div class="ai-coach-brief-form-head">
                            <h3>Fill only the missing lens</h3>
                            <p>Clarity Journey already supplies useful strategy context. Keep these refinements practical and specific enough for AI Coach to act on.</p>
                        </div>
                        <div class="ai-coach-brief-gate-fields">
                            <?php foreach ($aiCoachBriefMissingFields as $fieldName): ?>
                                <?php if (!isset($aiCoachBriefFieldDefinitions[$fieldName])) { continue; } ?>
                                <?php
                                    $definition = $aiCoachBriefFieldDefinitions[$fieldName];
                                    $valueSource = (string) ($definition['source'] ?? 'strategy') === 'idea' ? $aiCoachBriefIdea : $aiCoachBriefStrategy;
                                    $fieldValue = (string) ($valueSource[$fieldName] ?? '');
                                ?>
                                <label class="ai-coach-brief-gate-field ai-coach-brief-field-card">
                                    <span class="ai-coach-brief-field-title"><?php echo htmlspecialchars((string) ($definition['label'] ?? $fieldName)); ?></span>
                                    <span class="ai-coach-brief-field-help"><?php echo htmlspecialchars((string) ($definition['help'] ?? '')); ?></span>
                                    <textarea name="<?php echo htmlspecialchars($fieldName); ?>" rows="4" required placeholder="<?php echo htmlspecialchars((string) ($definition['placeholder'] ?? '')); ?>"><?php echo htmlspecialchars($fieldValue); ?></textarea>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <div class="ai-coach-brief-gate-error" data-ai-coach-brief-error hidden></div>
                        <div class="dashboard-setup-popup-footer">
                            <button type="submit" class="dashboard-setup-popup-primary">
                                <i class="fas fa-check" aria-hidden="true"></i>
                                <span>Save Coach refinement</span>
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php if ($aiCoachExplainerVideoUrl !== ''): ?>
<div class="dashboard-video-modal ai-coach-brief-video-modal" data-ai-coach-brief-video-modal role="dialog" aria-modal="true" aria-labelledby="ai-coach-brief-video-title" hidden>
    <div class="dashboard-video-dialog" role="document">
        <div class="dashboard-video-head">
            <h2 class="dashboard-video-title" id="ai-coach-brief-video-title">AI Coach explainer</h2>
            <button class="dashboard-video-close" type="button" data-ai-coach-brief-video-close aria-label="Close AI Coach explainer video">
                <i class="fas fa-times" aria-hidden="true"></i>
            </button>
        </div>
        <div class="dashboard-video-frame">
            <?php if ($aiCoachExplainerVideoType === 'iframe'): ?>
                <?php echo VideoBrandOverlayUi::frame(
                    '<iframe src="' . htmlspecialchars($aiCoachExplainerVideoUrl, ENT_QUOTES, 'UTF-8') . '" title="AI Coach explainer video" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen data-ai-coach-brief-video-iframe></iframe>'
                ); ?>
            <?php else: ?>
                <?php echo VideoBrandOverlayUi::frame(
                    '<video controls preload="metadata" playsinline data-ai-coach-brief-video>'
                    . '<source src="' . htmlspecialchars($aiCoachExplainerVideoUrl, ENT_QUOTES, 'UTF-8') . '">'
                    . 'Your browser does not support embedded video playback.'
                    . '</video>'
                ); ?>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if ($showDashboardSetupPopup): ?>
<div class="dashboard-setup-popup" id="dashboard-setup-popup" role="dialog" aria-modal="true" aria-labelledby="dashboard-setup-popup-title" data-dismiss-url="<?php echo htmlspecialchars(apiUrl('dashboard/dismiss-setup-popup.php')); ?>" data-csrf-token="<?php echo htmlspecialchars($dashboardCsrfToken); ?>">
    <div class="dashboard-setup-popup-card" role="document">
        <div class="dashboard-setup-popup-header">
            <div>
                <p class="dashboard-setup-popup-kicker">Setup Progress</p>
                <h2 class="dashboard-setup-popup-title" id="dashboard-setup-popup-title"><?php echo htmlspecialchars((string) ($workspaceSetupImpact['headline'] ?? 'Your workspace setup is in progress')); ?></h2>
            </div>
            <button type="button" class="dashboard-setup-popup-close" data-dashboard-setup-close aria-label="Close setup popup">
                <i class="fas fa-times" aria-hidden="true"></i>
            </button>
        </div>
        <div class="dashboard-setup-popup-meter" aria-label="Workspace daily work readiness score <?php echo $setupPopupScore; ?> percent">
            <span style="width: <?php echo max(4, min(100, $setupPopupScore)); ?>%;"></span>
        </div>
        <p class="dashboard-setup-popup-summary"><?php echo htmlspecialchars((string) ($workspaceSetupImpact['summary'] ?? 'Finish a few setup actions when you are ready.')); ?></p>
        <div class="dashboard-setup-popup-actions">
            <?php if (!empty($onboardingOwnerPrompt)): ?>
                <a class="dashboard-setup-popup-action is-owner-prompt" href="<?php echo htmlspecialchars((string) ($onboardingOwnerPrompt['url'] ?? 'onboarding.php')); ?>">
                    <span class="dashboard-setup-popup-action-icon" aria-hidden="true"><i class="fa-solid fa-compass"></i></span>
                    <span>
                        <strong><?php echo htmlspecialchars((string) ($onboardingOwnerPrompt['title'] ?? 'Finish workspace setup')); ?></strong>
                        <span>
                            <?php echo htmlspecialchars((string) ($onboardingOwnerPrompt['message'] ?? 'Finish your channel setup to start capturing conversations.')); ?>
                            <?php if (isset($onboardingOwnerPrompt['score'])): ?>
                                <?php echo ' Current setup progress: ' . (int) $onboardingOwnerPrompt['score'] . '%.'; ?>
                            <?php endif; ?>
                        </span>
                    </span>
                </a>
            <?php endif; ?>
            <?php foreach ($setupPopupActions as $action): ?>
                <a class="dashboard-setup-popup-action" href="<?php echo htmlspecialchars((string) ($action['url'] ?? '#')); ?>">
                    <span class="dashboard-setup-popup-action-icon" aria-hidden="true"><i class="<?php echo htmlspecialchars((string) ($action['icon'] ?? 'fa-solid fa-circle-check')); ?>"></i></span>
                    <span>
                        <strong><?php echo htmlspecialchars((string) ($action['title'] ?? 'Setup action')); ?></strong>
                        <span><?php echo htmlspecialchars((string) ($action['description'] ?? '')); ?></span>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
        <div class="dashboard-setup-popup-footer">
            <button type="button" class="dashboard-setup-popup-dismiss" data-dashboard-setup-dismiss>Dismiss</button>
            <a class="dashboard-setup-popup-primary" href="<?php echo htmlspecialchars($setupPopupPrimaryUrl); ?>" id="dashboard-setup-popup-primary">
                <i class="fas fa-arrow-right" aria-hidden="true"></i>
                <span><?php echo htmlspecialchars($setupPopupPrimaryLabel); ?></span>
            </a>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Minimalist Hero Section -->
<div class="hero-minimal <?php echo $showFounderCommandCenter ? 'has-founder-command-center' : ''; ?>">
    <div class="hero-minimal-controls">
        <?php if ($canUseDashboardFilter): ?>
        <details class="hero-filter-popover">
            <summary class="hero-filter-trigger" aria-label="Open dashboard filter">
                <i class="fas fa-filter" aria-hidden="true"></i>
            </summary>
            <div class="hero-filter-panel">
                <div class="hero-filter-label">Dashboard View</div>
                <div class="hero-filter-select-wrap">
                <select
                    id="dashboard_user_scope_filter"
                    aria-label="Dashboard view"
                    onchange="window.location.href='dashboard.php?user_id=' + this.value"
                    class="timeframe-select"
                >
                    <option value="<?php echo htmlspecialchars((string) $userId); ?>" <?php echo $selectedUserId === $userId ? 'selected' : ''; ?>>My Dashboard</option>
                    <?php if ($canViewAllAnalytics): ?>
                        <option value="" <?php echo $selectedUserId === null ? 'selected' : ''; ?>>All Users</option>
                        <?php foreach ($allUsers as $scopeUser): ?>
                            <?php
                            $scopeUserId = (int) ($scopeUser['id'] ?? 0);
                            if ($scopeUserId <= 0 || $scopeUserId === $userId) {
                                continue;
                            }
                            $scopeUserLabel = trim((string) (($scopeUser['first_name'] ?? '') . ' ' . ($scopeUser['last_name'] ?? '')));
                            if ($scopeUserLabel === '') {
                                $scopeUserLabel = (string) ($scopeUser['email'] ?? ('User #' . $scopeUserId));
                            }
                            ?>
                            <option value="<?php echo $scopeUserId; ?>" <?php echo $selectedUserId === $scopeUserId ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($scopeUserLabel); ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
                </div>
            </div>
        </details>
        <?php endif; ?>
        <?php if ($dashboardExplainerVideoUrl !== '' || $isProtectedDemoDashboard): ?>
        <button type="button" class="hero-guide-btn" data-dashboard-video-open aria-label="Watch the Dashboard page guide">
            <i class="fas fa-circle-play" aria-hidden="true"></i>
            <span>Watch guide</span>
        </button>
        <?php endif; ?>
    </div>
    <div class="hero-minimal-content" data-guided-demo-target="dashboard-ai-cofounder">
        <?php if ($showAutomationBattery): ?>
        <div class="hero-automation-battery <?php echo $automationBatteryHasSnapshot ? 'is-loaded' : ''; ?>" data-automation-battery-hero>
            <div class="hero-automation-battery-top">
                <div style="display:flex;align-items:center;gap:0.75rem;">
                    <span class="hero-automation-tooltip-anchor">
                        <button type="button" class="hero-automation-shell" data-automation-battery-shell data-automation-battery-load data-automation-battery-last-label="<?php echo htmlspecialchars((string) $automationBatteryLastCheckedLabel); ?>" aria-label="<?php echo htmlspecialchars($automationBatteryRefreshTooltip); ?>" aria-describedby="automation-battery-refresh-tooltip">
                            <div class="hero-automation-fill <?php echo htmlspecialchars((string) ($automationBattery['bucket'] ?? 'low')); ?>" data-automation-battery-fill style="width: <?php echo max(6, min(100, (int) ($automationBattery['score'] ?? 0))); ?>%;"></div>
                            <div class="hero-automation-score-inside" data-automation-battery-score aria-live="polite"><?php echo (int) ($automationBattery['score'] ?? 0); ?>%</div>
                            <i class="fas fa-spinner fa-spin hero-automation-refresh-spinner" aria-hidden="true"></i>
                        </button>
                        <span class="hero-automation-tooltip" id="automation-battery-refresh-tooltip" data-automation-battery-refresh-tooltip role="tooltip"><?php echo htmlspecialchars($automationBatteryRefreshTooltip); ?></span>
                    </span>
                    <span class="hero-automation-tooltip-anchor">
                        <div class="hero-automation-battery-status <?php echo htmlspecialchars((string) ($automationBattery['bucket'] ?? 'low')); ?>" data-automation-battery-status aria-live="polite" tabindex="0" aria-describedby="automation-battery-status-tooltip" aria-label="<?php echo htmlspecialchars((string) ($automationBattery['status_label'] ?? 'Ready to check') . '. ' . $automationBatteryStatusTooltip); ?>">
                            <?php echo htmlspecialchars((string) ($automationBattery['status_label'] ?? 'Ready to check')); ?>
                        </div>
                        <span class="hero-automation-tooltip" id="automation-battery-status-tooltip" data-automation-battery-status-tooltip role="tooltip"><?php echo htmlspecialchars($automationBatteryStatusTooltip); ?></span>
                    </span>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <h1 class="hero-minimal-title">Welcome back, <?php echo htmlspecialchars($displayName); ?></h1>
        <p class="hero-minimal-subtitle"><?php echo htmlspecialchars(brandOutcomeLine()); ?> Viewing: <?php echo htmlspecialchars($selectedUserLabel); ?></p>
    </div>
</div>

<?php if ($showFounderCommandCenter): ?>
<div class="founder-command-wrap">
    <section class="founder-command-center is-loading" data-founder-command-center aria-labelledby="founder-command-title" aria-busy="true">
        <header class="founder-command-header">
            <div>
                <p class="founder-command-kicker">Your operating rhythm</p>
                <h2 id="founder-command-title" data-founder-command-headline>Finding the one thing that matters now</h2>
                <p class="founder-command-summary" data-founder-command-summary>Connecting business context, work, automation, and evidence.</p>
            </div>
            <details class="founder-command-context" data-founder-command-context>
                <summary data-founder-command-context-label>Business context</summary>
                <div class="founder-command-context-panel" data-founder-command-context-panel></div>
            </details>
        </header>

        <div class="founder-command-focus">
            <span class="founder-command-focus-label">Constraint to resolve</span>
            <h3 data-founder-command-focus-title>Reviewing current signals</h3>
            <p data-founder-command-focus-summary>The system is separating founder judgment from work it can safely handle.</p>
            <p class="founder-command-why" data-founder-command-focus-why></p>
            <a class="founder-command-action" data-founder-command-focus-action hidden>Open</a>
        </div>

        <div class="founder-command-grid">
            <section class="founder-command-lane" aria-labelledby="founder-needs-you-title">
                <div class="founder-command-lane-heading">
                    <div>
                        <span class="founder-command-lane-label">Founder work</span>
                        <h3 id="founder-needs-you-title">Needs you</h3>
                    </div>
                    <span class="founder-command-count" data-founder-command-needs-count>0</span>
                </div>
                <div class="founder-command-list" data-founder-command-needs-list>
                    <p class="founder-command-empty">Checking for decisions and meaningful exceptions.</p>
                </div>
            </section>

            <section class="founder-command-lane" aria-labelledby="founder-handled-title">
                <div class="founder-command-lane-heading">
                    <div>
                        <span class="founder-command-lane-label">Verified execution</span>
                        <h3 id="founder-handled-title">Handled for you</h3>
                    </div>
                    <span class="founder-command-count is-handled" data-founder-command-handled-count>0</span>
                </div>
                <p class="founder-command-lane-summary" data-founder-command-activity-summary>Only completed work with evidence appears here.</p>
                <div class="founder-command-list" data-founder-command-handled-list></div>
            </section>

            <section class="founder-command-lane" aria-labelledby="founder-growth-title">
                <div class="founder-command-lane-heading">
                    <div>
                        <span class="founder-command-lane-label">Growth loop</span>
                        <h3 id="founder-growth-title">Growth move</h3>
                    </div>
                    <span class="founder-command-state" data-founder-command-growth-state>Watching</span>
                </div>
                <h4 class="founder-command-growth-title" data-founder-command-growth-title>Choose one measurable move</h4>
                <p class="founder-command-lane-summary" data-founder-command-growth-hypothesis></p>
                <dl class="founder-command-metric">
                    <div><dt>Measure</dt><dd data-founder-command-growth-metric>Not selected</dd></div>
                    <div><dt>Evidence</dt><dd data-founder-command-growth-evidence>Not started</dd></div>
                </dl>
                <p class="founder-command-learning" data-founder-command-growth-learning hidden>
                    <span>Last learning</span>
                    <strong data-founder-command-growth-learning-text></strong>
                </p>
                <a class="founder-command-text-link" data-founder-command-growth-action hidden>Open growth move</a>
            </section>
        </div>

        <footer class="founder-command-footer">
            <div class="founder-command-commitment">
                <span>Current commitment</span>
                <a data-founder-command-commitment-link>Choose one measurable next move</a>
                <small data-founder-command-commitment-meta></small>
            </div>
            <div class="founder-command-footer-actions">
                <?php if ($dashboardAICoachInstalled): ?>
                <button type="button" class="founder-command-coach" id="ai-coach-open" data-founder-command-coach hidden aria-hidden="true">Ask Clarity why</button>
                <?php endif; ?>
                <small data-founder-command-updated>Updating quietly</small>
            </div>
        </footer>
    </section>
</div>
<?php endif; ?>

<?php if ($dashboardExplainerVideoUrl !== '' || $isProtectedDemoDashboard): ?>
<div class="dashboard-video-modal" data-dashboard-video-modal role="dialog" aria-modal="true" aria-labelledby="dashboard-video-title" hidden>
    <div class="dashboard-video-dialog" role="document">
        <div class="dashboard-video-head">
            <h2 class="dashboard-video-title" id="dashboard-video-title">How to use Dashboard</h2>
            <button class="dashboard-video-close" type="button" data-dashboard-video-close aria-label="Close guide video">
                <i class="fas fa-times" aria-hidden="true"></i>
            </button>
        </div>
        <div class="dashboard-video-frame">
            <?php if ($dashboardExplainerVideoUrl !== ''): ?>
            <?php echo VideoBrandOverlayUi::frame(
                '<video controls preload="metadata" playsinline data-dashboard-video>'
                . '<source src="' . htmlspecialchars($dashboardExplainerVideoUrl, ENT_QUOTES, 'UTF-8') . '">'
                . 'Your browser does not support embedded video playback.'
                . '</video>'
            ); ?>
            <?php else: ?>
            <div class="dashboard-video-placeholder" data-dashboard-video>
                <i class="fas fa-circle-play" aria-hidden="true"></i>
                <strong>Dashboard guide preview</strong>
                <span>The production guide video can be attached from Marketplace page explainers. This protected demo keeps the premium trigger, pause behavior, and sandbox rhythm active while no external video is configured.</span>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="dashboard-premium">
    <?php if (!empty($dashboardActionNotice)): ?>
        <div class="dashboard-inline-alert success"><?php echo htmlspecialchars($dashboardActionNotice); ?></div>
    <?php endif; ?>
    <?php if (!empty($dashboardActionError)): ?>
        <div class="dashboard-inline-alert error"><?php echo htmlspecialchars($dashboardActionError); ?></div>
    <?php endif; ?>
    <?php if ($dashboardProductDemoPromptVisible): ?>
    <div class="container dashboard-product-demo-banner" id="dashboard-product-demo-prompt">
        <section class="dashboard-product-demo-card" aria-label="Product demo prompt">
            <div class="dashboard-product-demo-copy">
                <p class="dashboard-product-demo-kicker">Product Demo</p>
                <h2 class="dashboard-product-demo-title">See a sample CRM workday</h2>
                <p class="dashboard-product-demo-body">Walk through contacts, replies, tasks, quotes, and module handoffs using safe demo records. It is optional, and nothing in your real workspace gets sent.</p>
            </div>
            <div class="dashboard-product-demo-actions">
                <form class="dashboard-product-demo-form" method="POST" action="<?php echo htmlspecialchars(publicUrl('guided_demo.php')); ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($dashboardCsrfToken); ?>">
                    <input type="hidden" name="action" value="start_demo">
                    <button class="dashboard-product-demo-button" type="submit">
                        <i class="fas fa-circle-play" aria-hidden="true"></i>
                        <span>View product demo</span>
                    </button>
                </form>
                <div class="dashboard-product-demo-note">Optional. Your dashboard is ready either way.</div>
            </div>
        </section>
    </div>
    <?php endif; ?>
    <?php if ($showWorkspaceSetupImpactCard): ?>
    <div class="container workspace-setup-impact">
        <section class="workspace-setup-impact-card" aria-label="Workspace setup impact">
            <div class="workspace-setup-score">
                <div class="workspace-setup-label">Setup Progress</div>
                <div class="workspace-setup-headline"><?php echo htmlspecialchars((string) ($workspaceSetupImpact['headline'] ?? 'Your workspace is ready for daily work')); ?></div>
                <div class="workspace-setup-meter" aria-label="Workspace daily work readiness score <?php echo (int) ($workspaceSetupImpact['score'] ?? 0); ?> percent">
                    <span style="width: <?php echo max(4, min(100, (int) ($workspaceSetupImpact['score'] ?? 0))); ?>%;"></span>
                </div>
                <p class="workspace-setup-summary"><?php echo htmlspecialchars((string) ($workspaceSetupImpact['summary'] ?? '')); ?></p>
            </div>
            <div class="workspace-setup-actions">
                <?php if (!empty($onboardingOwnerPrompt)): ?>
                    <a class="workspace-setup-action" href="<?php echo htmlspecialchars((string) ($onboardingOwnerPrompt['url'] ?? 'onboarding.php')); ?>" style="border-color:#bfdbfe;background:#eff6ff;">
                        <span class="workspace-setup-action-icon" aria-hidden="true"><i class="fa-solid fa-compass"></i></span>
                        <span>
                            <strong><?php echo htmlspecialchars((string) ($onboardingOwnerPrompt['title'] ?? 'Finish workspace setup')); ?></strong>
                            <span>
                                <?php echo htmlspecialchars((string) ($onboardingOwnerPrompt['message'] ?? 'Finish your channel setup to start capturing conversations.')); ?>
                                <?php if (isset($onboardingOwnerPrompt['score'])): ?>
                                    <?php echo ' Current setup progress: ' . (int) $onboardingOwnerPrompt['score'] . '%.'; ?>
                                <?php endif; ?>
                            </span>
                        </span>
                    </a>
                <?php endif; ?>
                <?php if (!empty($workspaceSetupImpact['actions'])): ?>
                    <?php foreach ((array) $workspaceSetupImpact['actions'] as $action): ?>
                        <a class="workspace-setup-action" href="<?php echo htmlspecialchars((string) ($action['url'] ?? '#')); ?>">
                            <span class="workspace-setup-action-icon" aria-hidden="true"><i class="<?php echo htmlspecialchars((string) ($action['icon'] ?? 'fa-solid fa-circle-check')); ?>"></i></span>
                            <span>
                                <strong><?php echo htmlspecialchars((string) ($action['title'] ?? 'Setup action')); ?></strong>
                                <span><?php echo htmlspecialchars((string) ($action['description'] ?? '')); ?></span>
                            </span>
                        </a>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="workspace-setup-complete">
                        <span class="workspace-setup-complete-note">
                            <i class="fas fa-circle-check" aria-hidden="true"></i>
                            <span>Core setup actions are complete.</span>
                        </span>
                        <a class="workspace-setup-onboarding-link" href="<?php echo htmlspecialchars((string) ($onboardingOwnerPrompt['url'] ?? 'onboarding.php')); ?>">
                            <i class="fa-solid fa-compass" aria-hidden="true"></i>
                            <span>Open onboarding</span>
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </div>
    <?php elseif ($showWorkspaceSetupCompleteBanner): ?>
    <div class="container workspace-setup-complete-banner">
        <section class="workspace-setup-complete-card" aria-label="Workspace setup complete" data-dashboard-setup-complete-banner data-dismiss-url="<?php echo htmlspecialchars(apiUrl('dashboard/dismiss-setup-popup.php')); ?>" data-csrf-token="<?php echo htmlspecialchars($dashboardCsrfToken); ?>">
            <div class="workspace-setup-complete-message">
                <span class="workspace-setup-complete-icon" aria-hidden="true"><i class="fas fa-circle-check"></i></span>
                <div>
                    <h2 class="workspace-setup-complete-title">Ready for daily work</h2>
                    <p class="workspace-setup-complete-copy">Core setup is complete. Dashboard focus has shifted to revenue, tasks, pipeline, and guided next actions.</p>
                </div>
            </div>
            <button type="button" class="workspace-setup-complete-dismiss" data-dashboard-setup-complete-dismiss aria-label="Dismiss daily work readiness message">
                <i class="fas fa-times" aria-hidden="true"></i>
                <span>Dismiss</span>
            </button>
        </section>
    </div>
    <?php endif; ?>
    <?php if (!empty($platformOpsSummary)): ?>
        <section class="platform-ops-summary" aria-labelledby="platform-ops-summary-title">
            <div class="platform-ops-summary-top">
                <div>
                    <p class="platform-ops-summary-label">Platform Ops HQ</p>
                    <h2 class="platform-ops-summary-title" id="platform-ops-summary-title">
                        Your workspace is <?php echo (int) ($platformOpsSummary['score'] ?? 0); ?>% operational
                    </h2>
                </div>
                <div class="platform-ops-summary-actions">
                    <a href="workspaces.php"><i class="fas fa-building" aria-hidden="true"></i><span>Workspaces</span></a>
                </div>
            </div>
            <div class="platform-ops-summary-grid">
                <div class="platform-ops-summary-metric">
                    <div class="platform-ops-summary-value"><?php echo (int) ($platformOpsSummary['healthy_checks'] ?? 0); ?>/<?php echo (int) ($platformOpsSummary['total_checks'] ?? 0); ?></div>
                    <div class="platform-ops-summary-caption">artifact checks healthy</div>
                </div>
                <div class="platform-ops-summary-metric">
                    <div class="platform-ops-summary-value"><?php echo (int) ($platformOpsSummary['stuck_onboarding'] ?? 0); ?></div>
                    <div class="platform-ops-summary-caption">stuck onboarding workspaces</div>
                </div>
                <div class="platform-ops-summary-metric">
                    <div class="platform-ops-summary-value"><?php echo (int) ($platformOpsSummary['low_tokens'] ?? 0); ?></div>
                    <div class="platform-ops-summary-caption">low-token workspaces</div>
                </div>
                <div class="platform-ops-summary-metric">
                    <div class="platform-ops-summary-value"><?php echo (int) ($platformOpsSummary['failed_billing_events'] ?? 0); ?></div>
                    <div class="platform-ops-summary-caption">failed billing-event workspaces</div>
                </div>
                <div class="platform-ops-summary-metric">
                    <div class="platform-ops-summary-value"><?php echo (int) ($platformOpsSummary['channel_gaps'] ?? 0); ?></div>
                    <div class="platform-ops-summary-caption">workspace channel gaps</div>
                </div>
                <div class="platform-ops-summary-metric">
                    <div class="platform-ops-summary-value"><?php echo (int) (($platformOpsSummary['owner_counts']['current_paying_customer'] ?? 0)); ?></div>
                    <div class="platform-ops-summary-caption">paying owner contacts</div>
                </div>
                <div class="platform-ops-summary-metric">
                    <div class="platform-ops-summary-value"><?php echo (int) ($platformOpsSummary['recent_operator_actions'] ?? 0); ?></div>
                    <div class="platform-ops-summary-caption">operator actions in 7 days</div>
                </div>
                <div class="platform-ops-summary-metric">
                    <div class="platform-ops-summary-value"><?php echo !empty($platformOpsSummary['ai_context_active']) ? 'On' : 'Off'; ?></div>
                    <div class="platform-ops-summary-caption">Platform Ops AI context</div>
                </div>
                <div class="platform-ops-summary-metric">
                    <div class="platform-ops-summary-value"><?php echo (int) ($platformOpsSummary['ai_prompt_overrides'] ?? 0); ?></div>
                    <div class="platform-ops-summary-caption">active AI prompt overrides</div>
                </div>
                <div class="platform-ops-summary-metric">
                    <div class="platform-ops-summary-value"><?php echo (int) ($platformOpsSummary['ai_prompt_missing'] ?? 0); ?></div>
                    <div class="platform-ops-summary-caption">missing AI prompt overrides</div>
                </div>
                <div class="platform-ops-summary-metric">
                    <div class="platform-ops-summary-value"><?php echo (int) ($platformOpsSummary['ai_prompt_customized'] ?? 0); ?></div>
                    <div class="platform-ops-summary-caption">customized AI prompts preserved</div>
                </div>
            </div>
            <?php
                $opsNeedsAdmin = (int) ($platformOpsSummary['ops_events_needs_admin'] ?? 0);
                $opsWaitingOnOwner = (int) ($platformOpsSummary['ops_events_waiting_on_owner'] ?? 0);
                $opsAutoResolved = (int) ($platformOpsSummary['ops_events_auto_resolved'] ?? 0);
                $opsAutomationFailed = (int) ($platformOpsSummary['ops_events_automation_failed'] ?? 0);
                $opsOwnerFollowupsSent = (int) ($platformOpsSummary['ops_events_owner_followups_sent'] ?? 0);
                $opsHumanOnly = (int) ($platformOpsSummary['ops_events_human_only'] ?? 0);
            ?>
            <div class="platform-ops-workload" aria-label="Platform Ops workload">
                <div class="platform-ops-workload-card admin">
                    <span class="platform-ops-workload-icon"><i class="fas fa-user-shield" aria-hidden="true"></i></span>
                    <div>
                        <div class="platform-ops-workload-value"><?php echo $opsNeedsAdmin; ?></div>
                        <div class="platform-ops-workload-label">critical issues needing admin</div>
                    </div>
                </div>
                <div class="platform-ops-workload-card waiting">
                    <span class="platform-ops-workload-icon"><i class="fas fa-envelope-open-text" aria-hidden="true"></i></span>
                    <div>
                        <div class="platform-ops-workload-value"><?php echo $opsWaitingOnOwner; ?></div>
                        <div class="platform-ops-workload-label"><?php echo $opsOwnerFollowupsSent; ?> owner follow-ups sent</div>
                    </div>
                </div>
                <div class="platform-ops-workload-card cleared">
                    <span class="platform-ops-workload-icon"><i class="fas fa-circle-check" aria-hidden="true"></i></span>
                    <div>
                        <div class="platform-ops-workload-value"><?php echo $opsAutoResolved; ?></div>
                        <div class="platform-ops-workload-label">auto-resolved after signal cleared</div>
                    </div>
                </div>
                <div class="platform-ops-workload-card failed">
                    <span class="platform-ops-workload-icon"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i></span>
                    <div>
                        <div class="platform-ops-workload-value"><?php echo $opsAutomationFailed; ?></div>
                        <div class="platform-ops-workload-label">automation failures</div>
                    </div>
                </div>
            </div>
            <?php
                $platformOpsNotes = array_values(array_filter(array_merge(
                    (array) ($platformOpsSummary['identity_warnings'] ?? []),
                    (array) ($platformOpsSummary['missing'] ?? [])
                )));
                $platformOpsHealthWarnings = array_slice((array) ($platformOpsSummary['health_warnings'] ?? []), 0, 3);
                $platformOpsReviewSource = array_slice(array_merge($platformOpsNotes, $platformOpsHealthWarnings), 0, 5);
                $platformOpsReviewLabel = static function (string $item): string {
                    $key = strtolower(trim($item));
                    $known = [
                        'workspace_settings:workspace_purpose' => 'Workspace purpose',
                        'workspace_settings:internal_channel_ready' => 'Internal channel ready',
                        'workspace_settings:internal_team_ready' => 'Internal team ready',
                        'owner_helpline:owner_helpline_enabled' => 'Owner helpline enabled',
                    ];
                    if (isset($known[$key])) {
                        return $known[$key];
                    }
                    $label = str_replace(['workspace_settings:', 'owner_helpline:', '_', ':'], ['', '', ' ', ' / '], $key);
                    return ucwords(trim($label));
                };
                $platformOpsReviewItems = array_map($platformOpsReviewLabel, $platformOpsReviewSource);
                $platformOpsHealthStatus = strtolower((string) ($platformOpsSummary['health_status'] ?? 'warning'));
                $platformOpsHealthDot = in_array($platformOpsHealthStatus, ['critical', 'error', 'failed'], true)
                    ? 'critical'
                    : (in_array($platformOpsHealthStatus, ['healthy', 'ok', 'good'], true) ? '' : 'warning');
                $platformOpsDashboardAutomation = (array) ($platformOpsSummary['ops_dashboard_automation'] ?? []);
                $platformOpsAutomationError = trim((string) ($platformOpsSummary['ops_dashboard_automation_error'] ?? ''));
                $platformOpsAutomationCheckedAt = (int) ($platformOpsSummary['ops_dashboard_automation_checked_at'] ?? 0);
                $platformOpsAutomationLabel = 'Automation';
                if ($platformOpsAutomationError !== '') {
                    $platformOpsAutomationValue = 'Check failed';
                } elseif ($platformOpsDashboardAutomation !== [] && empty($platformOpsDashboardAutomation['success'])) {
                    $platformOpsAutomationValue = 'Unavailable';
                } elseif ($platformOpsDashboardAutomation !== []) {
                    $platformOpsAutomationValue = (int) ($platformOpsDashboardAutomation['sent'] ?? 0) . ' sent / ' . (int) ($platformOpsDashboardAutomation['failed'] ?? 0) . ' failed';
                } elseif (!empty($platformOpsSummary['ops_dashboard_automation_deferred'])) {
                    $platformOpsAutomationValue = 'On demand';
                } elseif ($platformOpsAutomationCheckedAt > 0) {
                    $platformOpsAutomationValue = 'Checked ' . date('H:i', $platformOpsAutomationCheckedAt);
                } else {
                    $platformOpsAutomationValue = 'Automatic';
                }
            ?>
            <div class="platform-ops-status-band">
                <div class="platform-ops-status-main">
                    <div class="platform-ops-status-title">Ops Health</div>
                    <div class="platform-ops-health-badge">
                        <span class="platform-ops-status-dot <?php echo htmlspecialchars($platformOpsHealthDot); ?>"></span>
                        <span><?php echo htmlspecialchars(strtoupper($platformOpsHealthStatus)); ?></span>
                    </div>
                    <?php if ($platformOpsReviewItems): ?>
                        <span class="platform-ops-status-subtitle"><?php echo count($platformOpsReviewItems); ?> setup checks need review</span>
                    <?php else: ?>
                        <span class="platform-ops-status-subtitle">No setup checks need review</span>
                    <?php endif; ?>
                </div>
                <div class="platform-ops-status-metrics" aria-label="Platform Ops counters">
                    <div class="platform-ops-status-chips">
                        <span class="platform-ops-status-chip"><span class="platform-ops-chip-label">Open Events</span><span class="platform-ops-chip-value"><?php echo (int) ($platformOpsSummary['open_ops_events'] ?? 0); ?></span></span>
                        <span class="platform-ops-status-chip"><span class="platform-ops-chip-label">Human-Only</span><span class="platform-ops-chip-value"><?php echo $opsHumanOnly; ?></span></span>
                        <span class="platform-ops-status-chip"><span class="platform-ops-chip-label">Auto-Resolved</span><span class="platform-ops-chip-value"><?php echo $opsAutoResolved; ?></span></span>
                        <span class="platform-ops-status-chip"><span class="platform-ops-chip-label">Stale Syncs</span><span class="platform-ops-chip-value"><?php echo (int) (($platformOpsSummary['health_metrics']['owner_contacts_stale'] ?? 0)); ?></span></span>
                        <span class="platform-ops-status-chip"><span class="platform-ops-chip-label">Sync Errors</span><span class="platform-ops-chip-value"><?php echo (int) (($platformOpsSummary['health_metrics']['sync_errors_open'] ?? 0)); ?></span></span>
                        <span class="platform-ops-status-chip"><span class="platform-ops-chip-label"><?php echo htmlspecialchars($platformOpsAutomationLabel); ?></span><span class="platform-ops-chip-value"><?php echo htmlspecialchars($platformOpsAutomationValue); ?></span></span>
                    </div>
                </div>
                <div class="platform-ops-review-panel">
                    <div class="platform-ops-review-title"><?php echo $platformOpsReviewItems ? 'Checks To Review' : 'Setup Checks'; ?></div>
                    <div class="platform-ops-review-chips" aria-label="Platform Ops checks to review">
                        <?php if ($platformOpsReviewItems): ?>
                            <?php foreach ($platformOpsReviewItems as $reviewItem): ?>
                                <span class="platform-ops-review-chip"><?php echo htmlspecialchars($reviewItem); ?></span>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <span class="platform-ops-review-chip">No setup checks waiting</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div style="margin-top:1rem;border-top:1px solid rgba(148,163,184,.3);padding-top:1rem;">
                <form method="GET" style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:.8rem;align-items:end;">
                    <label style="display:grid;gap:.25rem;font-size:.78rem;color:#475569;">Status
                        <select name="ops_status" style="padding:.5rem;border:1px solid #cbd5e1;border-radius:8px;">
                            <?php foreach (['' => 'All', 'open' => 'Open', 'in_progress' => 'In progress', 'waiting_on_owner' => 'Waiting on owner', 'waiting_on_provider' => 'Waiting on provider', 'resolved' => 'Resolved', 'dismissed' => 'Dismissed'] as $value => $label): ?>
                                <option value="<?php echo htmlspecialchars($value); ?>" <?php echo ((string) ($platformOpsSummary['ops_event_filters']['status'] ?? '') === $value) ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label style="display:grid;gap:.25rem;font-size:.78rem;color:#475569;">Severity
                        <select name="ops_severity" style="padding:.5rem;border:1px solid #cbd5e1;border-radius:8px;">
                            <?php foreach (['' => 'All', 'info' => 'Info', 'warning' => 'Warning', 'critical' => 'Critical'] as $value => $label): ?>
                                <option value="<?php echo htmlspecialchars($value); ?>" <?php echo ((string) ($platformOpsSummary['ops_event_filters']['severity'] ?? '') === $value) ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label style="display:grid;gap:.25rem;font-size:.78rem;color:#475569;">Signal
                        <input type="text" name="ops_signal_type" value="<?php echo htmlspecialchars((string) ($platformOpsSummary['ops_event_filters']['signal_type'] ?? '')); ?>" placeholder="billing, onboarding..." style="padding:.5rem;border:1px solid #cbd5e1;border-radius:8px;">
                    </label>
                    <button type="submit" class="btn-premium-secondary">Filter</button>
                </form>
                <div style="overflow:auto;">
                    <table style="width:100%;border-collapse:collapse;font-size:.86rem;">
                        <thead>
                            <tr style="color:#475569;text-align:left;border-bottom:1px solid #e2e8f0;">
                                <th style="padding:.55rem;">Workspace</th>
                                <th style="padding:.55rem;">Signal</th>
                                <th style="padding:.55rem;">Priority</th>
                                <th style="padding:.55rem;">Status</th>
                                <th style="padding:.55rem;">Automation</th>
                                <th style="padding:.55rem;">Last seen</th>
                                <th style="padding:.55rem;">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($platformOpsSummary['ops_events'])): ?>
                                <tr><td colspan="7" style="padding:.8rem;color:#64748b;">No Platform Ops events match these filters.</td></tr>
                            <?php else: ?>
                                <?php foreach ((array) $platformOpsSummary['ops_events'] as $event): ?>
                                    <?php
                                        $automationStatus = (string) ($event['automation_status'] ?? 'not_attempted');
                                        $automationLabel = $automationStatus === 'not_attempted' ? 'ready' : str_replace('_', ' ', $automationStatus);
                                        $automationDetail = (string) (($event['automation_last_error'] ?? '') ?: ($event['automation_last_result'] ?? ''));
                                        $eventStatus = (string) ($event['status'] ?? '');
                                        $workspaceAdminUrl = !empty($event['owner_visible'])
                                            ? 'owner_support_admin.php?event_id=' . (int) ($event['id'] ?? 0)
                                            : 'workspace_admin.php?id=' . (int) ($event['owner_workspace_id'] ?? 0);
                                    ?>
                                    <tr style="border-bottom:1px solid #f1f5f9;">
                                        <td style="padding:.55rem;">
                                            <a href="<?php echo htmlspecialchars($workspaceAdminUrl); ?>" style="color:#1d4ed8;text-decoration:none;">
                                                <?php echo htmlspecialchars((string) (($event['owner_workspace_name'] ?? '') ?: ('Workspace #' . (int) ($event['owner_workspace_id'] ?? 0)))); ?>
                                            </a>
                                        </td>
                                        <td style="padding:.55rem;"><?php echo htmlspecialchars((string) ($event['signal_type'] ?? '')); ?></td>
                                        <td style="padding:.55rem;"><?php echo htmlspecialchars((string) ($event['severity'] ?? '')); ?> / <?php echo htmlspecialchars((string) ($event['priority'] ?? '')); ?></td>
                                        <td style="padding:.55rem;"><?php echo htmlspecialchars((string) ($event['status'] ?? '')); ?></td>
                                        <td style="padding:.55rem;color:#475569;">
                                            <div><?php echo htmlspecialchars($automationLabel); ?></div>
                                            <?php if ($automationDetail !== ''): ?>
                                                <small style="display:block;color:<?php echo $automationStatus === 'failed' ? '#b91c1c' : '#64748b'; ?>;max-width:220px;"><?php echo htmlspecialchars($automationDetail); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td style="padding:.55rem;color:#64748b;"><?php echo htmlspecialchars((string) ($event['last_seen_at'] ?? '')); ?></td>
                                        <td style="padding:.55rem;">
                                            <?php if (!in_array($eventStatus, ['resolved', 'dismissed'], true)): ?>
                                                <div class="platform-ops-row-actions">
                                                    <a class="platform-ops-action-link primary" href="<?php echo htmlspecialchars($workspaceAdminUrl); ?>">Review</a>
                                                <?php if (!in_array($eventStatus, ['waiting_on_owner', 'waiting_on_provider'], true) && $automationStatus === 'failed'): ?>
                                                    <form method="POST">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($dashboardCsrfToken); ?>">
                                                        <input type="hidden" name="action" value="automate_default_workspace_ops_event">
                                                        <input type="hidden" name="event_id" value="<?php echo (int) ($event['id'] ?? 0); ?>">
                                                        <button type="submit" class="btn-premium-secondary">Retry automation</button>
                                                    </form>
                                                <?php endif; ?>
                                                <form method="POST">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($dashboardCsrfToken); ?>">
                                                    <input type="hidden" name="action" value="transition_default_workspace_ops_event">
                                                    <input type="hidden" name="event_id" value="<?php echo (int) ($event['id'] ?? 0); ?>">
                                                    <input type="hidden" name="event_status" value="resolved">
                                                    <input type="hidden" name="reason" value="Resolved after Platform Ops review">
                                                    <input type="hidden" name="resolution_summary" value="Resolved after Platform Ops review.">
                                                    <button type="submit" class="btn-premium-secondary">Resolve</button>
                                                </form>
                                                </div>
                                            <?php else: ?>
                                                <span style="color:#64748b;">Closed</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    <?php endif; ?>
    <?php if ($showAutomationReadinessCard): ?>
    <?php
        $automationBatteryTopActions = array_values(array_filter((array) ($automationBattery['top_actions'] ?? []), 'is_array'));
        $automationBatteryTopProgressSignals = array_values(array_filter((array) ($automationBattery['top_progress_signals'] ?? []), 'is_array'));
        $automationBatteryContextEnrichments = array_values(array_filter((array) ($automationBattery['context_enrichments'] ?? []), 'is_array'));
        $automationBatteryFallbackProgressLabels = array_values(array_filter(array_map('strval', (array) ($automationBattery['top_blockers'] ?? []))));
        $automationBatteryHasProgressSignals = $automationBatteryTopProgressSignals !== [] || $automationBatteryFallbackProgressLabels !== [];
        $automationBatterySignalActions = $automationBatteryTopActions !== []
            ? array_slice($automationBatteryTopActions, 0, 3)
            : ($automationBatteryHasProgressSignals ? [] : array_slice($automationBatteryContextEnrichments, 0, 3));
        $automationBatteryHasActions = $automationBatterySignalActions !== [];
        $automationBatteryCardHidden = $automationBatteryHasSnapshot
            && array_key_exists('is_visible', $automationBattery)
            && empty($automationBattery['is_visible']);
        $automationBatterySignalTitle = $automationBatteryTopActions !== []
            ? 'Next Automation Actions'
            : ($automationBatteryHasProgressSignals
                ? 'Automation Progress'
                : ($automationBatteryContextEnrichments !== []
                    ? 'Optional AI Context'
                    : (string) ($automationBattery['signal_title'] ?? 'Strongest Automation Signals')));
        $automationBatterySignalCopy = $automationBatteryTopActions !== []
            ? 'Open the existing setup or review page that clears each readiness signal.'
            : ($automationBatteryHasProgressSignals
                ? 'These are evidence and system-learning signals. They clear as normal work runs or admin-managed controls improve.'
                : ($automationBatteryContextEnrichments !== []
                    ? 'These improve AI guidance but do not block automation readiness.'
                    : (string) ($automationBattery['signal_copy'] ?? 'These are the strongest signals pushing the system toward full automation.')));
        $automationHealthSummary = (array) ($automationBattery['health_summary'] ?? []);
        $automationAttentionCounts = (array) ($automationBattery['attention_counts'] ?? []);
        $automationHealthTone = (string) ($automationHealthSummary['tone'] ?? $automationHealthSummary['status'] ?? 'building');
        if (!in_array($automationHealthTone, ['healthy', 'building', 'warning', 'critical'], true)) {
            $automationHealthTone = 'building';
        }
        $automationHealthLabel = (string) ($automationHealthSummary['label'] ?? 'Automation Health');
        $automationHealthMessage = (string) ($automationHealthSummary['message'] ?? 'Automation health is based on setup, workers, actions, and learning evidence.');
        $automationHealthLastChecked = (string) ($automationHealthSummary['last_checked_label'] ?? $automationBattery['updated_label'] ?? 'Not checked yet');
        $automationHealthRefreshState = (string) ($automationHealthSummary['refresh_state_label'] ?? $automationBattery['refresh_state_label'] ?? 'Not checked yet.');
        $automationRequiredCount = (int) ($automationAttentionCounts['required_actions'] ?? 0);
        $automationFailedJobs = (int) ($automationAttentionCounts['failed_jobs'] ?? 0);
        $automationStaleJobs = (int) ($automationAttentionCounts['stale_jobs'] ?? 0);
        $automationJobsToReview = $automationFailedJobs + $automationStaleJobs;
        $automationNoSetupChecksWaiting = !empty($automationHealthSummary['no_setup_checks_waiting']);
    ?>
    <div class="container automation-readiness-card is-on-demand <?php echo $automationBatteryHasSnapshot ? 'is-loaded' : ''; ?>" data-automation-readiness-card <?php echo $automationBatteryCardHidden ? 'hidden' : ''; ?>>
        <?php if ($showAutomationHealthBand): ?>
        <div class="automation-health-band <?php echo htmlspecialchars($automationHealthTone); ?>" data-automation-health-band>
            <div class="automation-health-main">
                <span class="automation-health-dot" aria-hidden="true"></span>
                <div class="automation-health-copy">
                    <div class="automation-readiness-label">Automation Health</div>
                    <div class="automation-health-title" data-automation-health-label><?php echo htmlspecialchars($automationHealthLabel); ?></div>
                    <div class="automation-health-message" data-automation-health-message><?php echo htmlspecialchars($automationHealthMessage); ?></div>
                    <span class="automation-health-clear" data-automation-health-clear <?php echo $automationNoSetupChecksWaiting ? '' : 'hidden'; ?>>
                        <i class="fas fa-circle-check" aria-hidden="true"></i>
                        <span>No setup checks waiting</span>
                    </span>
                </div>
            </div>
            <div class="automation-health-metrics" aria-label="Automation health counters">
                <span class="automation-health-metric">
                    <strong data-automation-health-score><?php echo (int) ($automationBattery['score'] ?? 0); ?>%</strong>
                    <span>score</span>
                </span>
                <span class="automation-health-metric">
                    <strong data-automation-health-required><?php echo $automationRequiredCount; ?></strong>
                    <span>required</span>
                </span>
                <span class="automation-health-metric">
                    <strong data-automation-health-jobs><?php echo $automationJobsToReview; ?></strong>
                    <span>jobs to review</span>
                </span>
                <span class="automation-health-metric wide">
                    <strong data-automation-health-last><?php echo htmlspecialchars($automationHealthLastChecked); ?></strong>
                    <span>last checked</span>
                </span>
                <span class="automation-health-metric wide">
                    <strong data-automation-health-refresh><?php echo htmlspecialchars($automationHealthRefreshState); ?></strong>
                    <span>refresh</span>
                </span>
            </div>
        </div>
        <?php endif; ?>
        <div class="automation-readiness-grid">
            <div class="automation-readiness-summary-card">
                <div class="automation-readiness-label"><?php echo htmlspecialchars($automationBatterySubjectLabel); ?></div>
                <div class="automation-readiness-value" data-automation-readiness-headline><?php echo htmlspecialchars((string) ($automationBattery['headline_label'] ?? $automationBattery['mode_label'] ?? 'Manual')); ?></div>
                <div class="automation-readiness-copy" data-automation-readiness-summary>
                    <?php echo htmlspecialchars((string) ($automationBattery['summary'] ?? 'Automation readiness is being calculated from current system configuration and health.')); ?>
                </div>
                <div class="automation-readiness-meta" data-automation-readiness-progress>
                    <?php echo htmlspecialchars((string) ($automationBattery['setup_progress_label'] ?? '')); ?>
                </div>
                <div class="automation-readiness-actions">
                    <button type="button" class="automation-readiness-load-button" data-automation-battery-load aria-label="Refresh automation readiness" title="Refresh automation readiness">
                        <i class="fas fa-rotate-right" aria-hidden="true"></i>
                        <span class="sr-only">Refresh automation readiness</span>
                    </button>
                </div>
            </div>
            <div class="automation-readiness-signals-card">
                <div class="automation-readiness-label" data-automation-readiness-signal-title>
                    <?php echo htmlspecialchars($automationBatterySignalTitle); ?>
                </div>
                <div class="automation-readiness-copy" data-automation-readiness-signal-copy>
                    <?php echo htmlspecialchars($automationBatterySignalCopy); ?>
                </div>
                <div class="automation-readiness-list" data-automation-readiness-list>
                    <?php if ($automationBatteryHasActions): ?>
                        <?php foreach ($automationBatterySignalActions as $action): ?>
                            <?php echo $automationBatteryRenderActionChip($action, 'readiness'); ?>
                        <?php endforeach; ?>
                    <?php elseif ($automationBatteryTopProgressSignals !== []): ?>
                        <?php foreach (array_slice($automationBatteryTopProgressSignals, 0, 4) as $signal): ?>
                            <?php echo $automationBatteryRenderProgressChip($signal, 'readiness'); ?>
                        <?php endforeach; ?>
                    <?php elseif ($automationBatteryFallbackProgressLabels !== []): ?>
                        <?php foreach (array_slice($automationBatteryFallbackProgressLabels, 0, 4) as $label): ?>
                            <?php echo $automationBatteryRenderProgressChip(['label' => $label, 'severity' => 'info'], 'readiness'); ?>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <?php foreach ((array) ($automationBattery['top_boosters'] ?? []) as $booster): ?>
                            <span class="automation-readiness-chip booster">
                                <i class="fas fa-check"></i>
                                <?php echo htmlspecialchars((string) $booster); ?>
                            </span>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div class="automation-layer-grid" data-automation-layer-grid>
                    <?php foreach ((array) ($automationBattery['layers'] ?? []) as $layer): ?>
                        <?php if (isset($layer['is_visible']) && empty($layer['is_visible'])) { continue; } ?>
                        <div class="automation-layer-card">
                            <div class="automation-layer-top">
                                <div>
                                    <div class="automation-layer-name"><?php echo htmlspecialchars((string) ($layer['label'] ?? 'Layer')); ?></div>
                                    <div class="automation-layer-value"><?php echo htmlspecialchars((string) ($layer['detail_value'] ?? (($layer['score'] ?? 0) . '%'))); ?></div>
                                </div>
                                <div class="automation-layer-status <?php echo htmlspecialchars((string) ($layer['bucket'] ?? 'low')); ?>">
                                    <?php echo htmlspecialchars((string) ($layer['status_label'] ?? 'Tracking')); ?>
                                </div>
                            </div>
                            <div class="automation-layer-copy">
                                <?php echo htmlspecialchars((string) ($layer['summary'] ?? '')); ?>
                            </div>
                            <div class="automation-layer-signals">
                                <?php
                                    $layerActions = array_values(array_filter((array) ($layer['actions'] ?? []), 'is_array'));
                                    $layerProgressSignals = array_values(array_filter((array) ($layer['progress_signals'] ?? []), 'is_array'));
                                ?>
                                <?php if (!empty($layerActions)): ?>
                                    <?php foreach (array_slice($layerActions, 0, 2) as $action): ?>
                                        <?php echo $automationBatteryRenderActionChip($action, 'layer'); ?>
                                    <?php endforeach; ?>
                                <?php elseif (!empty($layerProgressSignals)): ?>
                                    <?php foreach (array_slice($layerProgressSignals, 0, 2) as $signal): ?>
                                        <?php echo $automationBatteryRenderProgressChip($signal, 'layer'); ?>
                                    <?php endforeach; ?>
                                <?php elseif (!empty($layer['blockers'])): ?>
                                    <?php foreach (array_slice((array) $layer['blockers'], 0, 2) as $blocker): ?>
                                        <?php echo $automationBatteryRenderProgressChip(['label' => (string) $blocker, 'severity' => 'info'], 'layer'); ?>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <?php foreach (array_slice((array) ($layer['signals'] ?? []), 0, 2) as $signal): ?>
                                        <span class="automation-layer-chip signal">
                                            <i class="fas fa-check"></i>
                                            <?php echo htmlspecialchars((string) $signal); ?>
                                        </span>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php if (!empty($showOutcomeDashboardCard)): ?>
    <div class="container" style="margin-bottom: 1rem;">
        <div class="content-card" style="padding: 1rem; border: 1px solid #dbeafe; background: #f8fbff;">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 0.75rem; align-items: stretch;">
                <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 0.75rem;" data-plain-readiness-card>
                    <?php
                        $plainReadinessFallbackTitle = $dashboardExperienceMode === UIExperienceService::MODE_BEGINNER
                            ? 'Setup Progress'
                            : 'Activation Progress';
                        if ($isProtectedDemoDashboard) {
                            $plainReadinessFallbackTitle = 'Operational Readiness';
                        }
                        $initialPlainMilestones = is_array($initialPlainReadiness)
                            ? array_slice((array) ($initialPlainReadiness['milestones'] ?? []), 0, 6)
                            : [];
                        $initialPlainGap = is_array($initialPlainReadiness) && is_array($initialPlainReadiness['primary_gap'] ?? null)
                            ? (array) $initialPlainReadiness['primary_gap']
                            : [];
                        $initialPlainTitle = is_array($initialPlainReadiness)
                            ? (string) ($initialPlainReadiness['headline_label'] ?? $plainReadinessFallbackTitle)
                            : $plainReadinessFallbackTitle;
                        $initialPlainProgress = is_array($initialPlainReadiness)
                            ? (string) ($initialPlainReadiness['progress_label'] ?? '')
                            : ($isProtectedDemoDashboard ? 'Refreshing operational readiness...' : 'Loading setup progress...');
                        $initialPlainReason = trim((string) ($initialPlainGap['reason'] ?? ''));
                        $initialPlainStatus = trim((string) ($initialPlainGap['status_label'] ?? ''));
                        $initialPlainHref = trim((string) ($initialPlainGap['href'] ?? ''));
                        $initialPlainPhase = (string) ($initialPlainReadiness['phase'] ?? 'setup');
                        $initialPlainHasAction = $initialPlainPhase !== 'revenue_momentum'
                            && $plainReadinessActionableHref($initialPlainHref);
                        $initialPlainDestinationHref = $initialPlainHasAction
                            ? $dashboardAppendDestinationContext($initialPlainHref, [
                                'source' => (string) ($initialPlainReadiness['source'] ?? 'dashboard_readiness'),
                                'gap' => (string) ($initialPlainGap['key'] ?? ''),
                            ])
                            : '';
                        $initialPlainCtaLabel = $initialPlainHasAction
                            ? (string) ($initialPlainGap['cta_label'] ?? 'Open setup')
                            : '';
                    ?>
                    <div style="font-size: 0.8rem; color: #475569; font-weight: 600; margin-bottom: 0.4rem;" data-plain-readiness-title><?php echo htmlspecialchars($initialPlainTitle); ?></div>
                    <div style="display: <?php echo $initialPlainProgress !== '' ? 'block' : 'none'; ?>; color: #64748b; font-size: 0.72rem; line-height: 1.35; margin: -0.15rem 0 0.35rem;" data-plain-readiness-progress><?php echo htmlspecialchars($initialPlainProgress); ?></div>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 0.35rem;" data-plain-readiness-milestones>
                        <?php foreach ($initialPlainMilestones as $step): ?>
                            <?php $stepLabel = trim((string) ($step['label'] ?? 'Setup item')); ?>
                            <?php
                                $stepComplete = !empty($step['complete']);
                                $isMomentumGap = !$stepComplete && $initialPlainPhase === 'revenue_momentum';
                                $chipColor = $stepComplete ? '#166534' : ($isMomentumGap ? '#92400e' : '#64748b');
                                $chipBackground = $stepComplete ? '#dcfce7' : ($isMomentumGap ? '#fffbeb' : '#f8fafc');
                                $chipBorder = $stepComplete ? '#bbf7d0' : ($isMomentumGap ? '#fde68a' : '#e2e8f0');
                            ?>
                            <div style="font-size: 0.75rem; color: <?php echo $chipColor; ?>; background: <?php echo $chipBackground; ?>; border: 1px solid <?php echo $chipBorder; ?>; border-radius: 999px; padding: 0.2rem 0.45rem; text-align: center; min-width: 0; overflow-wrap: anywhere;">
                                <?php echo !empty($step['complete']) ? '&#10003; ' : ''; ?><?php echo htmlspecialchars($stepLabel !== '' ? $stepLabel : 'Setup item'); ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div style="color: #64748b; font-size: 0.76rem; line-height: 1.4; margin-top: 0.4rem; display: <?php echo $initialPlainReason !== '' ? 'block' : 'none'; ?>;" data-plain-readiness-reason><?php echo htmlspecialchars($initialPlainReason); ?></div>
                    <div style="color: #166534; font-size: 0.74rem; line-height: 1.35; margin-top: 0.2rem; display: <?php echo $initialPlainStatus !== '' ? 'block' : 'none'; ?>;" data-plain-readiness-status><?php echo htmlspecialchars($initialPlainStatus); ?></div>
                    <div style="display: <?php echo $initialPlainHasAction ? 'flex' : 'none'; ?>; align-items: center; gap: 0.45rem; flex-wrap: wrap; margin-top: 0.5rem;" data-plain-readiness-actions>
                        <a
                            <?php if ($initialPlainHasAction): ?>href="<?php echo htmlspecialchars($initialPlainDestinationHref); ?>"<?php endif; ?>
                            style="display: inline-flex; align-items: center; gap: 0.35rem; border-radius: 999px; padding: 0.3rem 0.6rem; background: #0f172a; color: #ffffff; text-decoration: none; font-size: 0.74rem; font-weight: 700;"
                            data-readiness-gap-key="<?php echo htmlspecialchars((string) ($initialPlainGap['key'] ?? '')); ?>"
                            data-readiness-href="<?php echo htmlspecialchars($initialPlainHasAction ? $initialPlainDestinationHref : ''); ?>"
                            data-readiness-source="<?php echo htmlspecialchars((string) ($initialPlainReadiness['source'] ?? 'plain_readiness')); ?>"
                            data-readiness-mode="<?php echo htmlspecialchars((string) ($initialPlainReadiness['mode'] ?? $dashboardExperienceMode)); ?>"
                            data-plain-readiness-cta
                        ><?php echo htmlspecialchars($initialPlainCtaLabel); ?></a>
                    </div>
                </div>
                <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 0.75rem;">
                    <div class="ttfv-heading" style="font-size: 0.8rem; color: #475569; font-weight: 600;">
                        <span>TTFV (14d)</span>
                        <span class="ttfv-help" tabindex="0" aria-label="TTFV means time to first value: median hours from first login to first completed follow-up task.">?
                            <span class="ttfv-tooltip" role="tooltip">Time to first value: median hours from first login to first completed follow-up task.</span>
                        </span>
                    </div>
                    <div style="font-size: 1.25rem; font-weight: 700; color: #0f172a; margin-top: 0.25rem;">
                        <?php echo number_format((float) ($outcomeTTFV['median_hours'] ?? 0.0), 1); ?>h
                    </div>
                    <div style="font-size: 0.72rem; color: #64748b; margin-top: 0.2rem;">
                        P75 <?php echo number_format((float) ($outcomeTTFV['p75_hours'] ?? 0.0), 1); ?>h
                    </div>
                    <div style="font-size: 0.72rem; color: <?php echo ((float) ($outcomeTTFV['trend_vs_prev_pct'] ?? 0.0)) <= 0 ? '#166534' : '#b91c1c'; ?>;">
                        Trend <?php echo number_format((float) ($outcomeTTFV['trend_vs_prev_pct'] ?? 0.0), 1); ?>%
                    </div>
                    <div style="font-size: 0.72rem; color: #64748b; margin-top: 0.35rem;">
                        Activation <?php echo number_format((float) ($outcomeActivation['rate'] ?? 0.0), 1); ?>%
                    </div>
                    <div style="font-size: 0.72rem; color: #64748b;">
                        Revenue action <?php echo number_format((float) ($outcomeRevenueActions['rate'] ?? 0.0), 1); ?>%
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <!-- Premium Metrics Grid -->
    <div class="container">
    <!-- Premium Metrics Grid - Organized by Importance -->
    <div class="metrics-grid">
        <!-- HIGH IMPORTANCE: Leads Today - Wide Rectangle (2 columns) -->
        <div class="metric-card metric-high-importance gradient-1 animate-slide-up" style="animation-delay: 0.1s;">
            <div class="metric-card-header">
                <div>
                    <div class="metric-label">Leads Today</div>
                </div>
                <div class="metric-icon"><i class="fas fa-users"></i></div>
            </div>
            <div class="metric-value"><?php echo number_format($metricsToday['leads']); ?></div>
            <div class="metric-subtext">
                <?php 
                $trend = $trends['leads'];
                $trendClass = $trend > 0 ? 'trend-up' : ($trend < 0 ? 'trend-down' : 'trend-neutral');
                $trendIcon = $trend > 0 ? '↑' : ($trend < 0 ? '↓' : '→');
                ?>
                <span class="trend-indicator <?php echo $trendClass; ?>">
                    <?php echo $trendIcon; ?> <?php echo abs(round($trend, 1)); ?>%
                </span>
                <span style="color: #94a3b8;"><?php echo number_format($metricsWeek['leads']); ?> this week</span>
            </div>
        </div>

        <!-- MEDIUM IMPORTANCE: Emails Sent - Square -->
        <div class="metric-card metric-medium-importance gradient-2 animate-slide-up" style="animation-delay: 0.2s;">
            <div class="metric-card-header">
                <div>
                    <div class="metric-label">Emails Sent</div>
                </div>
                <div class="metric-icon"><i class="far fa-envelope"></i></div>
            </div>
            <div class="metric-value"><?php echo number_format($metricsToday['emails_sent']); ?></div>
            <div class="metric-subtext">
                <?php 
                $trend = $trends['emails_sent'];
                $trendClass = $trend > 0 ? 'trend-up' : ($trend < 0 ? 'trend-down' : 'trend-neutral');
                $trendIcon = $trend > 0 ? '↑' : ($trend < 0 ? '↓' : '→');
                ?>
                <span class="trend-indicator <?php echo $trendClass; ?>">
                    <?php echo $trendIcon; ?> <?php echo abs(round($trend, 1)); ?>%
                </span>
                <span style="color: #94a3b8;"><?php echo number_format($metricsMonth['emails_sent']); ?> this month</span>
            </div>
        </div>

        <!-- MEDIUM IMPORTANCE: Emails Opened - Square -->
        <div class="metric-card metric-medium-importance gradient-3 animate-slide-up" style="animation-delay: 0.3s;">
            <div class="metric-card-header">
                <div>
                    <div class="metric-label">Emails Opened</div>
                </div>
                <div class="metric-icon"><i class="far fa-envelope-open"></i></div>
            </div>
            <div class="metric-value"><?php echo number_format($metricsToday['emails_opened']); ?></div>
            <div class="metric-subtext">
                <span style="color: #94a3b8;"><?php echo number_format(round($emailOpenRate, 1)); ?>% open rate</span>
                <span style="color: #cbd5e1;">•</span>
                <span style="color: #94a3b8;">Last 24 hours</span>
            </div>
        </div>

        <!-- LOW IMPORTANCE: Form Submissions - Small Square -->
        <div class="metric-card metric-low-importance gradient-4 animate-slide-up" style="animation-delay: 0.4s;">
            <div class="metric-card-header">
                <div>
                    <div class="metric-label">Form Submissions</div>
                </div>
                <div class="metric-icon"><i class="far fa-file-lines"></i></div>
            </div>
            <div class="metric-value"><?php echo number_format($metricsToday['form_submissions']); ?></div>
            <div class="metric-subtext">
                <?php 
                $trend = $trends['form_submissions'];
                $trendClass = $trend > 0 ? 'trend-up' : ($trend < 0 ? 'trend-down' : 'trend-neutral');
                $trendIcon = $trend > 0 ? '↑' : ($trend < 0 ? '↓' : '→');
                ?>
                <span class="trend-indicator <?php echo $trendClass; ?>">
                    <?php echo $trendIcon; ?> <?php echo abs(round($trend, 1)); ?>%
                </span>
                <span style="color: #94a3b8;"><?php echo number_format($metricsWeek['form_submissions']); ?> this week</span>
            </div>
        </div>

        <!-- MEDIUM IMPORTANCE: Tasks - Square -->
        <div class="metric-card metric-medium-importance gradient-5 animate-slide-up" style="animation-delay: 0.5s;">
            <div class="metric-card-header">
                <div>
                    <div class="metric-label"><?php echo htmlspecialchars($dashboardPossessiveLabel); ?> Tasks</div>
                </div>
                <div class="metric-icon"><i class="fas fa-tasks"></i></div>
            </div>
            <div class="metric-value"><?php echo number_format($myTasksCount); ?></div>
            <div class="metric-subtext">
                <span style="color: #94a3b8;"><?php echo number_format($myPendingTasks); ?> pending</span>
                <?php if ($myOverdueTasks > 0): ?>
                    <span style="color: #cbd5e1;">•</span>
                    <span style="color: #dc2626; font-weight: 600;"><?php echo number_format($myOverdueTasks); ?> overdue</span>
                <?php endif; ?>
            </div>
        </div>

        <!-- HIGH IMPORTANCE: Open Deals - Wide Rectangle (2 columns) -->
        <div class="metric-card metric-high-importance gradient-6 animate-slide-up" style="animation-delay: 0.6s;">
            <div class="metric-card-header">
                <div>
                    <div class="metric-label"><?php echo htmlspecialchars($dashboardPossessiveLabel); ?> Open Deals</div>
                </div>
                <div class="metric-icon"><i class="fas fa-handshake"></i></div>
            </div>
            <div class="metric-value"><?php echo number_format($myOpenDealsCount); ?></div>
            <div class="metric-subtext">
                <span style="color: #94a3b8;">
                <?php 
                if ($defaultCurrency) {
                    echo $currenciesModule->formatAmount($myDealsValue, $defaultCurrency['code']);
                } else {
                    echo '$' . number_format($myDealsValue, 2);
                }
                ?> value
                </span>
            </div>
        </div>

        <!-- HIGH IMPORTANCE: Total Pipeline - Tall Rectangle (2 rows) -->
        <div class="metric-card metric-high-importance metric-tall gradient-7 animate-slide-up" style="animation-delay: 0.7s;" data-guided-demo-target="dashboard-first-paid-signal">
            <div class="metric-card-header">
                <div>
                    <div class="metric-label">Total Pipeline</div>
                </div>
                <div class="metric-icon"><i class="fas fa-money-bill-1"></i></div>
            </div>
            <div class="metric-value">
                <?php 
                if ($defaultCurrency) {
                    echo $currenciesModule->formatAmount($pipelineStats['total_pipeline_value'], $defaultCurrency['code']);
                } else {
                    echo '$' . number_format($pipelineStats['total_pipeline_value'], 0);
                }
                ?>
            </div>
            <div class="metric-subtext">
                <span style="color: #94a3b8;"><?php echo number_format($pipelineStats['won']['count']); ?> won</span>
                <span style="color: #cbd5e1;">•</span>
                <span style="color: #94a3b8;"><?php echo number_format(round($conversionRate, 1)); ?>% conversion</span>
            </div>
        </div>

        <!-- HIGH IMPORTANCE: Total Contacts - Wide Rectangle (2 columns) -->
        <div class="metric-card metric-high-importance gradient-8 animate-slide-up" style="animation-delay: 0.8s;">
            <div class="metric-card-header">
                <div>
                    <div class="metric-label">Total Contacts</div>
                </div>
                <div class="metric-icon"><i class="fas fa-address-book"></i></div>
            </div>
            <div class="metric-value"><?php echo number_format($totalContactsCount); ?></div>
            <div class="metric-subtext">
                <?php 
                $contactsTrend = $contactsThisWeek > 0 ? (($contactsThisWeek / 7) / max($totalContactsCount / 30, 1)) * 100 : 0;
                $trendClass = $contactsTrend > 0 ? 'trend-up' : ($contactsTrend < 0 ? 'trend-down' : 'trend-neutral');
                $trendIcon = $contactsTrend > 0 ? '↑' : ($contactsTrend < 0 ? '↓' : '→');
                ?>
                <span class="trend-indicator <?php echo $trendClass; ?>">
                    <?php echo $trendIcon; ?> <?php echo abs(round($contactsTrend, 1)); ?>%
                </span>
                <span style="color: #94a3b8;"><?php echo number_format($contactsThisWeek); ?> this week</span>
            </div>
        </div>
    </div>
    </div>

    <!-- Analytics Charts -->
    <div class="charts-grid" id="dashboard-charts" data-dashboard-charts data-demo-cue-key="dashboard_charts_visible">
        <!-- Contacts Trend Chart -->
        <div class="chart-container animate-slide-up" style="animation-delay: 0.3s;">
            <div class="chart-header">
                <h3 class="chart-title">Contacts Created (Last 7 Days)</h3>
                <p class="chart-subtitle">Daily contact creation trend</p>
            </div>
            <canvas id="contactsChart" style="max-height: 300px;"></canvas>
        </div>

        <!-- Email Performance Chart -->
        <div class="chart-container animate-slide-up" style="animation-delay: 0.4s;">
            <div class="chart-header">
                <h3 class="chart-title">Email Performance</h3>
                <p class="chart-subtitle">Sent vs opened emails</p>
            </div>
            <canvas id="emailChart" style="max-height: 300px;"></canvas>
        </div>

        <!-- Stage Distribution Chart -->
        <div class="chart-container animate-slide-up" style="animation-delay: 0.5s;">
            <div class="chart-header">
                <h3 class="chart-title">Stage Distribution</h3>
                <p class="chart-subtitle">Contacts by stage</p>
            </div>
            <canvas id="stageChart" style="max-height: 300px;"></canvas>
        </div>

        <!-- Deal Value by Stage Chart -->
        <div class="chart-container animate-slide-up" style="animation-delay: 0.6s;">
            <div class="chart-header">
                <h3 class="chart-title">Deals by Stage</h3>
                <p class="chart-subtitle">Open deal count and pipeline value</p>
            </div>
            <canvas id="dealValueChart" style="max-height: 300px;"></canvas>
            <div id="dealValueChartEmpty" class="empty-state" style="display:none;padding-top:1rem;">
                <div class="empty-state-icon"><i class="fas fa-handshake"></i></div>
                <p>No open deals yet.</p>
            </div>
            <?php if ($dealStageSummaries): ?>
                <div class="deal-stage-summary" aria-label="Open deal stage summary">
                    <?php foreach ($dealStageSummaries as $stageSummary): ?>
                        <div class="deal-stage-summary-row">
                            <span class="deal-stage-summary-marker" style="background: <?php echo htmlspecialchars((string) $stageSummary['color']); ?>"></span>
                            <span class="deal-stage-summary-name"><?php echo htmlspecialchars((string) $stageSummary['label']); ?></span>
                            <span class="deal-stage-summary-count"><?php echo htmlspecialchars((string) $stageSummary['count_label']); ?></span>
                            <span class="deal-stage-summary-value"><?php echo htmlspecialchars((string) $stageSummary['value_label']); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>


    <!-- Content Sections -->
    <div class="content-grid">
        <!-- Recent Contacts -->
        <div class="section-card animate-slide-up" style="animation-delay: 0.6s;">
            <div class="section-header">
                <h2 class="section-title">Recent Contacts</h2>
                <a href="contacts.php" class="section-link">View All →</a>
            </div>
            <?php if (empty($recentContacts)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon"><i class="fas fa-user"></i></div>
                    <p>No contacts yet.</p>
                </div>
            <?php else: ?>
                <div>
                    <?php foreach (array_slice($recentContacts, 0, 5) as $contact): ?>
                        <div class="list-item">
                            <div style="font-weight: 600; color: #0f172a; margin-bottom: 0.25rem;">
                                <a href="contact_view.php?id=<?php echo $contact['id']; ?>" style="color: #0f172a; text-decoration: none;">
                                    <?php echo htmlspecialchars($contact['first_name'] . ' ' . $contact['last_name']); ?>
                                </a>
                            </div>
                            <div style="color: #64748b; font-size: 0.875rem;">
                                <?php echo htmlspecialchars($contact['email'] ?? 'No email'); ?> • 
                                <span style="text-transform: capitalize; font-weight: 500;"><?php echo htmlspecialchars($contact['stage']); ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            </div>
        </div>
    
        <!-- Recent Activities -->
        <div class="section-card animate-slide-up" style="animation-delay: 0.7s;">
            <div class="section-header">
                <h2 class="section-title">Recent Activities</h2>
                <a href="activities.php" class="section-link">View All →</a>
            </div>
            <?php if (empty($recentActivities)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon"><i class="fas fa-chart-bar"></i></div>
                    <p>No activities yet.</p>
                </div>
            <?php else: ?>
                <div>
                    <?php foreach (array_slice($recentActivities, 0, 5) as $activity): ?>
                        <div class="list-item">
                            <div style="font-weight: 600; color: #0f172a; margin-bottom: 0.25rem;">
                                <?php echo htmlspecialchars($activity['first_name'] . ' ' . $activity['last_name']); ?>
                            </div>
                            <div style="color: #64748b; font-size: 0.875rem;">
                                <span style="text-transform: capitalize; font-weight: 500;"><?php echo str_replace('_', ' ', htmlspecialchars($activity['activity_type'])); ?></span>
                                <?php if ($activity['description']): ?>
                                    • <?php echo htmlspecialchars(substr($activity['description'], 0, 50)); ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            </div>
        </div>
    
    <!-- Tasks -->
        <div class="section-card animate-slide-up" style="animation-delay: 0.8s;" data-guided-demo-target="dashboard-founder-priorities">
            <div class="section-header">
            <h2 class="section-title"><?php echo htmlspecialchars($dashboardPossessiveLabel); ?> Tasks</h2>
                <a href="tasks.php" class="section-link">View All →</a>
            </div>
            <div
                id="dashboard-tasks-panel"
                data-endpoint="../api/dashboard_tasks.php"
                data-can-read="<?php echo !empty($canReadTasks) ? '1' : '0'; ?>"
                style="margin-bottom: 0.5rem;"
            >
                <div style="display:grid;gap:0.75rem;">
                    <div style="height:88px;border-radius:14px;background:linear-gradient(90deg,#f8fafc 0%,#eef2ff 50%,#f8fafc 100%);background-size:200% 100%;animation:dashboardTasksPulse 1.4s ease-in-out infinite;"></div>
                    <div style="height:88px;border-radius:14px;background:linear-gradient(90deg,#f8fafc 0%,#eef2ff 50%,#f8fafc 100%);background-size:200% 100%;animation:dashboardTasksPulse 1.4s ease-in-out infinite .15s;"></div>
                </div>
            </div>
            <div style="display:none;" aria-hidden="true">
            <?php if (!empty($aiStarterTasks)): ?>
                <div style="margin-bottom: 1rem; padding: 0.9rem 1rem; border: 1px solid #bfdbfe; border-radius: 14px; background: linear-gradient(135deg, #eff6ff, #f8fbff);">
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:0.75rem;flex-wrap:wrap;margin-bottom:0.6rem;">
                        <div>
                            <div style="font-size:0.78rem;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:#1d4ed8;">Starter Tasks</div>
                            <div style="font-size:0.9rem;color:#334155;">AI seeded these setup tasks so they are visible outside Clarity too.</div>
                        </div>
                        <a href="tasks.php" style="font-size:0.85rem;font-weight:600;color:#2563eb;text-decoration:none;">Open task list &rarr;</a>
                    </div>
                    <div style="display:grid;gap:0.55rem;">
                        <?php foreach ($aiStarterTasks as $starterTask): ?>
                            <a href="task_view.php?id=<?php echo (int) $starterTask['id']; ?>" style="display:flex;justify-content:space-between;align-items:center;gap:0.75rem;padding:0.75rem 0.85rem;border-radius:12px;background:#ffffff;border:1px solid #dbeafe;color:#0f172a;text-decoration:none;">
                                <div>
                                    <div style="font-weight:600;"><?php echo htmlspecialchars((string) $starterTask['title']); ?></div>
                                    <div style="font-size:0.82rem;color:#64748b;">
                                        <?php if (!empty($starterTask['due_date'])): ?>
                                            Due <?php echo htmlspecialchars(date('M d, Y', strtotime((string) $starterTask['due_date']))); ?>
                                        <?php else: ?>
                                            Ready to start
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <span class="badge badge-priority-medium">AI Starter</span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
            <?php if (empty($recentTasks)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon"><i class="fas fa-check-circle"></i></div>
                    <p>No pending tasks.</p>
                </div>
            <?php else: ?>
                <div>
                    <?php foreach ($recentTasks as $task): 
                        $isOverdue = $task['due_date'] && strtotime($task['due_date']) < time();
                        $priorityBadges = [
                            'urgent' => 'badge-priority-urgent',
                            'high' => 'badge-priority-high',
                            'medium' => 'badge-priority-medium',
                            'low' => 'badge-priority-low'
                        ];
                        $badgeClass = $priorityBadges[$task['priority']] ?? 'badge-priority-low';
                    ?>
                        <div class="list-item" style="<?php echo $isOverdue ? 'background: #fee2e2; border-left: 3px solid #dc2626;' : ''; ?>">
                            <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 0.5rem;">
                                <div style="font-weight: 600; color: #0f172a; flex: 1;">
                                    <a href="task_view.php?id=<?php echo $task['id']; ?>" style="color: #0f172a; text-decoration: none;">
                                        <?php echo htmlspecialchars($task['title']); ?>
                                    </a>
                                </div>
                                <span class="badge <?php echo $badgeClass; ?>">
                                    <?php echo htmlspecialchars($task['priority']); ?>
                                </span>
                            </div>
                            <div style="color: #64748b; font-size: 0.875rem;">
                                <?php if ($task['due_date']): ?>
                                    <span style="<?php echo $isOverdue ? 'color: #dc2626; font-weight: 600;' : ''; ?>">
                                        Due: <?php echo date('M d, Y', strtotime($task['due_date'])); ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ($task['contact_id']): ?>
                                    <span> • </span>
                                    <a href="contact_view.php?id=<?php echo $task['contact_id']; ?>" style="color: #667eea; text-decoration: none;">
                                        <?php echo htmlspecialchars(($task['contact_first_name'] ?? '') . ' ' . ($task['contact_last_name'] ?? '')); ?>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    
        <!-- Upcoming Events -->
        <div class="section-card animate-slide-up" style="animation-delay: 0.9s;">
            <div class="section-header">
                <h2 class="section-title">Upcoming Events</h2>
                <a href="calendar.php" class="section-link">View All →</a>
            </div>
            <?php if (empty($upcomingEvents)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon"><i class="fas fa-calendar-alt"></i></div>
                    <p>No upcoming events.</p>
                </div>
            <?php else: ?>
                <div>
                    <?php foreach ($upcomingEvents as $event): 
                        $eventTypeColors = [
                            'meeting' => '#10b981',
                            'call' => '#f59e0b',
                            'email' => '#3b82f6',
                            'task' => '#64748b',
                            'other' => '#ef4444'
                        ];
                        $color = $eventTypeColors[$event['event_type']] ?? '#64748b';
                    ?>
                        <div class="list-item">
                            <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 0.5rem;">
                                <div style="font-weight: 600; color: #0f172a; flex: 1;">
                                    <a href="event_view.php?id=<?php echo $event['id']; ?>" style="color: #0f172a; text-decoration: none;">
                                        <?php echo htmlspecialchars($event['title']); ?>
                                    </a>
                                </div>
                                <span class="badge" style="background: <?php echo $color; ?>20; color: <?php echo $color; ?>;">
                                    <?php echo htmlspecialchars($event['event_type']); ?>
                                </span>
                            </div>
                            <div style="color: #64748b; font-size: 0.875rem;">
                                📅 <?php echo date('M d, Y g:i A', strtotime($event['start_time'])); ?>
                                <?php if ($event['location']): ?>
                                    • 📍 <?php echo htmlspecialchars($event['location']); ?>
                                <?php endif; ?>
                            </div>
                            <?php if ($event['contact_id']): ?>
                                <div style="color: #64748b; font-size: 0.875rem; margin-top: 0.25rem;">
                                    <a href="contact_view.php?id=<?php echo $event['contact_id']; ?>" style="color: #667eea; text-decoration: none;">
                                        👤 <?php echo htmlspecialchars(($event['contact_first_name'] ?? '') . ' ' . ($event['contact_last_name'] ?? '')); ?>
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function initDashboardCharts() {
    if (typeof window.Chart === 'undefined') {
        return;
    }

    const chartColors = {
        primary: '#667eea',
        secondary: '#764ba2',
        success: '#10b981',
        warning: '#f59e0b',
        danger: '#ef4444',
        info: '#3b82f6',
        gradient1: 'rgba(102, 126, 234, 0.15)',
        gradient2: 'rgba(118, 75, 162, 0.15)'
    };

    Chart.defaults.font.family = 'system-ui, -apple-system, sans-serif';
    Chart.defaults.font.size = 12;
    Chart.defaults.color = '#64748b';
    Chart.defaults.elements.point.radius = 5;
    Chart.defaults.elements.point.hoverRadius = 7;
    Chart.defaults.elements.point.borderWidth = 2;
    Chart.defaults.elements.line.borderWidth = 3;
    Chart.defaults.elements.bar.borderRadius = 8;

    // Contacts Chart
<?php
$contactsTrendMap = [];
foreach ($dailyTrends as $trend) {
    $contactsTrendMap[(string) ($trend['date'] ?? '')] = (int) ($trend['contacts'] ?? 0);
}

$contactsLabels = [];
$contactsData = [];
$today = new DateTimeImmutable('today');
for ($offset = 6; $offset >= 0; $offset--) {
    $day = $today->sub(new DateInterval('P' . $offset . 'D'));
    $key = $day->format('Y-m-d');
    $contactsLabels[] = $day->format('M j');
    $contactsData[] = (int) ($contactsTrendMap[$key] ?? 0);
}
?>
    const contactsCtx = document.getElementById('contactsChart');
    if (contactsCtx) {
        new Chart(contactsCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode($contactsLabels); ?>,
                datasets: [{
                    label: 'Contacts Created',
                    data: <?php echo json_encode($contactsData); ?>,
                    borderColor: chartColors.primary,
                    backgroundColor: function(context) {
                        const chart = context.chart;
                        const chartArea = chart.chartArea;
                        if (!chartArea) return null;
                        const gradient = chart.ctx.createLinearGradient(0, chartArea.bottom, 0, chartArea.top);
                        gradient.addColorStop(0, 'rgba(102, 126, 234, 0.2)');
                        gradient.addColorStop(0.5, 'rgba(102, 126, 234, 0.1)');
                        gradient.addColorStop(1, 'rgba(102, 126, 234, 0.05)');
                        return gradient;
                    },
                    tension: 0.5,
                    fill: true,
                    pointRadius: 5,
                    pointHoverRadius: 8,
                    pointBackgroundColor: '#ffffff',
                    pointBorderColor: chartColors.primary,
                    pointBorderWidth: 3,
                    pointHoverBackgroundColor: chartColors.primary,
                    pointHoverBorderColor: '#ffffff',
                    pointHoverBorderWidth: 3
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        backgroundColor: 'rgba(15, 23, 42, 0.95)',
                        padding: 14,
                        titleFont: { size: 14, weight: '600', family: 'system-ui' },
                        bodyFont: { size: 13, weight: '500', family: 'system-ui' },
                        cornerRadius: 10,
                        displayColors: false,
                        titleColor: '#ffffff',
                        bodyColor: '#e2e8f0',
                        borderColor: 'rgba(102, 126, 234, 0.3)',
                        borderWidth: 1
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(0, 0, 0, 0.06)',
                            drawBorder: false,
                            lineWidth: 1
                        },
                        ticks: {
                            precision: 0,
                            color: '#64748b',
                            font: { size: 11, weight: '500' },
                            padding: 10
                        }
                    },
                    x: {
                        grid: {
                            display: false,
                            drawBorder: false
                        },
                        ticks: {
                            color: '#64748b',
                            font: { size: 11, weight: '600' },
                            padding: 12
                        }
                    }
                }
            }
        });
    }

    // Email Performance Chart
<?php
$emailLabels = [];
$emailSentData = [];
$emailOpenedData = [];
$emailTrendMap = [];
foreach ($emailTrends as $trend) {
    $emailTrendMap[(string) ($trend['date'] ?? '')] = [
        'sent' => (int) ($trend['sent'] ?? 0),
        'opened' => (int) ($trend['opened'] ?? 0),
    ];
}
$emailPeriod = new DatePeriod(
    new DateTimeImmutable('-6 days'),
    new DateInterval('P1D'),
    (new DateTimeImmutable('tomorrow'))
);
foreach ($emailPeriod as $day) {
    $key = $day->format('Y-m-d');
    $emailLabels[] = $day->format('M j');
    $emailSentData[] = (int) ($emailTrendMap[$key]['sent'] ?? 0);
    $emailOpenedData[] = (int) ($emailTrendMap[$key]['opened'] ?? 0);
}
?>
    const emailCtx = document.getElementById('emailChart');
    if (emailCtx) {
        new Chart(emailCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode($emailLabels); ?>,
                datasets: [{
                    label: 'Sent',
                    data: <?php echo json_encode($emailSentData); ?>,
                    backgroundColor: function(context) {
                        const gradient = context.chart.ctx.createLinearGradient(0, 0, 0, 400);
                        gradient.addColorStop(0, 'rgba(102, 126, 234, 0.9)');
                        gradient.addColorStop(1, 'rgba(102, 126, 234, 0.6)');
                        return gradient;
                    },
                    borderRadius: {
                        topLeft: 8,
                        topRight: 8
                    },
                    borderSkipped: false,
                    maxBarThickness: 50
                }, {
                    label: 'Opened',
                    data: <?php echo json_encode($emailOpenedData); ?>,
                    backgroundColor: function(context) {
                        const gradient = context.chart.ctx.createLinearGradient(0, 0, 0, 400);
                        gradient.addColorStop(0, 'rgba(16, 185, 129, 0.9)');
                        gradient.addColorStop(1, 'rgba(16, 185, 129, 0.6)');
                        return gradient;
                    },
                    borderRadius: {
                        topLeft: 8,
                        topRight: 8
                    },
                    borderSkipped: false,
                    maxBarThickness: 50
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                        align: 'end',
                        labels: {
                            usePointStyle: true,
                            padding: 18,
                            font: { size: 12, weight: '600', family: 'system-ui' },
                            color: '#64748b',
                            boxWidth: 12,
                            boxHeight: 12
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(15, 23, 42, 0.95)',
                        padding: 14,
                        titleFont: { size: 14, weight: '600', family: 'system-ui' },
                        bodyFont: { size: 13, weight: '500', family: 'system-ui' },
                        cornerRadius: 10,
                        titleColor: '#ffffff',
                        bodyColor: '#e2e8f0',
                        borderColor: 'rgba(102, 126, 234, 0.3)',
                        borderWidth: 1
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(0, 0, 0, 0.06)',
                            drawBorder: false,
                            lineWidth: 1
                        },
                        ticks: {
                            precision: 0,
                            color: '#64748b',
                            font: { size: 11, weight: '500' },
                            padding: 10
                        }
                    },
                    x: {
                        grid: {
                            display: false,
                            drawBorder: false
                        },
                        ticks: {
                            color: '#64748b',
                            font: { size: 11, weight: '600' },
                            padding: 12
                        }
                    }
                }
            }
        });
    }

    // Stage Distribution Chart
<?php
$stageLabels = [];
$stageData = [];
$stageColors = ['#667eea', '#764ba2', '#f093fb', '#f5576c', '#4facfe', '#00f2fe', '#43e97b'];
foreach ($stageDistribution as $index => $stage) {
    $rawStage = trim((string) ($stage['stage'] ?? ''));
    $count = (int) ($stage['count'] ?? 0);
    if ($count <= 0) {
        continue;
    }
    $stageLabels[] = $rawStage !== '' ? ucfirst($rawStage) : 'Unassigned';
    $stageData[] = $count;
}
?>
    const stageCtx = document.getElementById('stageChart');
    if (stageCtx) {
        new Chart(stageCtx, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode($stageLabels); ?>,
                datasets: [{
                    data: <?php echo json_encode($stageData); ?>,
                    backgroundColor: <?php echo json_encode(array_slice($stageColors, 0, count($stageData))); ?>,
                    borderWidth: 3,
                    borderColor: '#ffffff',
                    hoverBorderWidth: 4,
                    hoverOffset: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '65%',
                plugins: {
                    legend: {
                        position: 'right',
                        labels: {
                            usePointStyle: true,
                            padding: 15,
                            font: { size: 12, weight: '600', family: 'system-ui' },
                            color: '#64748b'
                        }
                    },
                    tooltip: {
                        backgroundColor: 'rgba(15, 23, 42, 0.95)',
                        padding: 14,
                        titleFont: { size: 14, weight: '600', family: 'system-ui' },
                        bodyFont: { size: 13, weight: '500', family: 'system-ui' },
                        cornerRadius: 10,
                        displayColors: true,
                        titleColor: '#ffffff',
                        bodyColor: '#e2e8f0',
                        borderColor: 'rgba(102, 126, 234, 0.3)',
                        borderWidth: 1,
                        callbacks: {
                            label: function(context) {
                                let label = context.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                label += context.parsed;
                                return label;
                            }
                        }
                    }
                }
            }
        });
    }

    // Deals by Stage Chart
    const dealValueCtx = document.getElementById('dealValueChart');
    const dealValueEmpty = document.getElementById('dealValueChartEmpty');
    const dealValueLabels = <?php echo json_encode($dealStageLabels); ?>;
    const dealValueData = <?php echo json_encode($dealStageValues); ?>;
    const dealValueText = <?php echo json_encode($dealStageValueLabels); ?>;
    const dealCountData = <?php echo json_encode($dealStageCounts); ?>;
    const dealCountText = <?php echo json_encode($dealStageCountLabels); ?>;
    const dealStageColors = <?php echo json_encode($dealStageColors); ?>;
    const dealCurrencyTickPrefix = <?php echo json_encode($dashboardCurrencyTickPrefix); ?>;
    const dealCurrencyTickSuffix = <?php echo json_encode($dashboardCurrencyTickSuffix); ?>;
    const hasDealValue = dealValueData.some(value => Number(value || 0) > 0);
    if (dealValueCtx) {
        if (!dealCountData.length) {
            dealValueCtx.style.display = 'none';
            if (dealValueEmpty) {
                dealValueEmpty.style.display = 'block';
            }
        } else {
            if (dealValueEmpty) {
                dealValueEmpty.style.display = 'none';
            }
            dealValueCtx.style.display = 'block';
            const dealDatasets = [{
                type: 'bar',
                label: 'Open Deals',
                data: dealCountData,
                yAxisID: 'yDeals',
                backgroundColor: dealCountData.map((_, index) => dealStageColors[index % dealStageColors.length]),
                borderRadius: 12,
                maxBarThickness: 56
            }];
            if (hasDealValue) {
                dealDatasets.push({
                    type: 'line',
                    label: 'Pipeline Value',
                    data: dealValueData,
                    yAxisID: 'yValue',
                    borderColor: '#0f172a',
                    backgroundColor: '#0f172a',
                    borderWidth: 2,
                    tension: 0.35,
                    pointRadius: 3,
                    pointHoverRadius: 5
                });
            }
            new Chart(dealValueCtx, {
                type: 'bar',
                data: {
                    labels: dealValueLabels,
                    datasets: dealDatasets
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: hasDealValue,
                            labels: {
                                color: '#475569',
                                boxWidth: 12,
                                boxHeight: 12,
                                font: { size: 11, weight: '600' }
                            }
                        },
                        tooltip: {
                            backgroundColor: 'rgba(15, 23, 42, 0.95)',
                            padding: 14,
                            titleFont: { size: 14, weight: '600', family: 'system-ui' },
                            bodyFont: { size: 13, weight: '500', family: 'system-ui' },
                            cornerRadius: 10,
                            titleColor: '#ffffff',
                            bodyColor: '#e2e8f0',
                            borderColor: 'rgba(56, 189, 248, 0.25)',
                            borderWidth: 1,
                            callbacks: {
                                label: function(context) {
                                    const index = context.dataIndex;
                                    if (context.dataset.yAxisID === 'yDeals') {
                                        return 'Open deals: ' + (dealCountText[index] || Number(context.parsed.y || 0).toLocaleString());
                                    }
                                    return 'Pipeline value: ' + (dealValueText[index] || Number(context.parsed.y || 0).toLocaleString());
                                },
                                afterBody: function(items) {
                                    if (!items.length || items[0].dataset.yAxisID !== 'yDeals') {
                                        return [];
                                    }
                                    const index = items[0].dataIndex;
                                    return ['Pipeline value: ' + (dealValueText[index] || '0')];
                                }
                            }
                        }
                    },
                    scales: {
                        yDeals: {
                            type: 'linear',
                            position: 'left',
                            beginAtZero: true,
                            grid: {
                                color: 'rgba(0, 0, 0, 0.06)',
                                drawBorder: false,
                                lineWidth: 1
                            },
                            ticks: {
                                color: '#64748b',
                                font: { size: 11, weight: '500' },
                                padding: 10,
                                precision: 0,
                                callback: function(value) {
                                    return Number(value).toLocaleString();
                                }
                            },
                            title: {
                                display: true,
                                text: 'Deals',
                                color: '#64748b',
                                font: { size: 11, weight: '700' }
                            }
                        },
                        yValue: {
                            type: 'linear',
                            position: 'right',
                            display: hasDealValue,
                            beginAtZero: true,
                            grid: {
                                drawOnChartArea: false,
                                drawBorder: false
                            },
                            ticks: {
                                color: '#64748b',
                                font: { size: 11, weight: '500' },
                                padding: 10,
                                callback: function(value) {
                                    return dealCurrencyTickPrefix + Number(value).toLocaleString() + dealCurrencyTickSuffix;
                                }
                            },
                            title: {
                                display: hasDealValue,
                                text: 'Value',
                                color: '#64748b',
                                font: { size: 11, weight: '700' }
                            }
                        },
                        x: {
                            grid: {
                                display: false,
                                drawBorder: false
                            },
                            ticks: {
                                color: '#64748b',
                                font: { size: 11, weight: '600' },
                                padding: 12
                            }
                        }
                    }
                }
            });
        }
    }
}
</script>

<div id="ai-coach-modal-container" aria-live="polite"></div>
<script>
<?php if ($dashboardExplainerVideoUrl !== '' || $isProtectedDemoDashboard): ?>
(function () {
    var openButton = document.querySelector('[data-dashboard-video-open]');
    var modal = document.querySelector('[data-dashboard-video-modal]');
    if (!openButton || !modal) {
        return;
    }

    var closeButton = modal.querySelector('[data-dashboard-video-close]');
    var video = modal.querySelector('[data-dashboard-video]');
    var lastFocused = null;

    function openModal() {
        lastFocused = document.activeElement;
        if (modal.parentNode !== document.body) {
            document.body.appendChild(modal);
        }
        modal.hidden = false;
        modal.scrollTop = 0;
        document.body.style.overflow = 'hidden';
        if (video && typeof video.play === 'function') {
            try { video.currentTime = 0; } catch (error) {}
            var playAttempt = video.play();
            if (playAttempt && typeof playAttempt.catch === 'function') {
                playAttempt.catch(function () {});
            }
        }
        if (closeButton) {
            closeButton.focus();
        }
    }

    function closeModal() {
        modal.hidden = true;
        document.body.style.overflow = '';
        if (video && typeof video.pause === 'function') {
            video.pause();
            try { video.currentTime = 0; } catch (error) {}
        }
        if (lastFocused && typeof lastFocused.focus === 'function') {
            lastFocused.focus();
        }
    }

    openButton.addEventListener('click', openModal);
    if (closeButton) {
        closeButton.addEventListener('click', closeModal);
    }
    modal.addEventListener('click', function (event) {
        if (event.target === modal) {
            closeModal();
        }
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !modal.hidden) {
            closeModal();
        }
    });
})();
<?php endif; ?>

<?php if ($aiCoachExplainerVideoUrl !== ''): ?>
(function () {
    var openButton = document.querySelector('[data-ai-coach-brief-video-open]');
    var modal = document.querySelector('[data-ai-coach-brief-video-modal]');
    if (!openButton || !modal) {
        return;
    }

    var closeButton = modal.querySelector('[data-ai-coach-brief-video-close]');
    var video = modal.querySelector('[data-ai-coach-brief-video]');
    var iframe = modal.querySelector('[data-ai-coach-brief-video-iframe]');
    var iframeSrc = iframe ? iframe.getAttribute('src') : '';
    var lastFocused = null;
    var previousOverflow = '';

    function openAICoachVideo() {
        lastFocused = document.activeElement;
        previousOverflow = document.body.style.overflow;
        if (modal.parentNode !== document.body) {
            document.body.appendChild(modal);
        }
        if (iframe && iframeSrc && !iframe.getAttribute('src')) {
            iframe.setAttribute('src', iframeSrc);
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
            closeButton.focus();
        }
    }

    function closeAICoachVideo() {
        modal.hidden = true;
        document.body.style.overflow = previousOverflow;
        if (video) {
            video.pause();
            try { video.currentTime = 0; } catch (error) {}
        }
        if (iframe && iframeSrc) {
            iframe.setAttribute('src', '');
        }
        if (lastFocused && typeof lastFocused.focus === 'function') {
            lastFocused.focus();
        }
    }

    openButton.addEventListener('click', openAICoachVideo);
    if (closeButton) {
        closeButton.addEventListener('click', closeAICoachVideo);
    }
    modal.addEventListener('click', function (event) {
        if (event.target === modal) {
            closeAICoachVideo();
        }
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !modal.hidden) {
            closeAICoachVideo();
        }
    });
})();
<?php endif; ?>

window.AICoachConfig = <?php if ($dashboardAICoachInstalled): ?>{
    basePath: <?php echo json_encode($basePath ?? ''); ?>,
    recommendationsUrl: <?php echo json_encode(apiUrl('ai-coach/recommendations.php')); ?>,
    onboardingUrl: <?php echo json_encode(apiUrl('ai-coach/onboarding.php')); ?>,
    ideaValidationUrl: <?php echo json_encode(apiUrl('ai-coach/idea-validation.php')); ?>,
    strategyProfileUrl: <?php echo json_encode(apiUrl('ai-coach/strategy-profile.php')); ?>,
    dismissCelebrationUrl: <?php echo json_encode(apiUrl('ai-coach/dismiss-celebration.php')); ?>,
    taskCreateUrl: <?php echo json_encode(apiUrl('tasks/create.php')); ?>
}<?php else: ?>null<?php endif; ?>;

window.DashboardAsyncConfig = {
    chartJsUrl: 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js',
    aiCoachScriptUrl: <?php echo $dashboardAICoachInstalled ? json_encode(assetUrl('js/ai-coach-modal.js') . '?v=' . urlencode(APP_VERSION . '-' . (string) @filemtime(__DIR__ . '/assets/js/ai-coach-modal.js'))) : 'null'; ?>,
    aiCoachAccessUrl: <?php echo $dashboardAICoachInstalled ? json_encode(apiUrl('dashboard/ai_coach_access.php')) : 'null'; ?>,
    automationBatteryUrl: <?php echo json_encode(apiUrl('dashboard/automation_battery.php') . '?subject_user_id=' . urlencode((string) $automationBatterySubjectUserId)); ?>,
    automationBatteryRefreshPolicy: <?php echo json_encode($automationBatteryRefreshPolicy, JSON_UNESCAPED_SLASHES); ?>,
    automationBatteryShouldIdleRefresh: <?php echo $automationBatteryShouldIdleRefresh ? 'true' : 'false'; ?>,
    automationBatteryIdleDelayMs: 3000,
    founderCommandCenterUrl: <?php echo $showFounderCommandCenter ? json_encode(apiUrl('dashboard/founder_command_center.php') . '?subject_user_id=' . urlencode((string) $founderCommandCenterSubjectUserId)) : 'null'; ?>,
    plainReadinessUrl: <?php echo ($isProtectedDemoDashboard || $initialPlainReadiness !== null) ? 'null' : json_encode(apiUrl('dashboard/plain_readiness.php')); ?>,
    outcomeEventUrl: <?php echo json_encode(apiUrl('outcomes/events.php')); ?>,
    csrfToken: <?php echo json_encode($dashboardCsrfToken); ?>
};

(function () {
    var asyncConfig = window.DashboardAsyncConfig || {};
    var dashboardTasksRequested = false;
    var welcomeOverlay = document.getElementById('clarity-workspace-welcome');
    var setupPopup = document.getElementById('dashboard-setup-popup');
    var setupCompleteBanner = document.querySelector('[data-dashboard-setup-complete-banner]');
    var aiCoachBriefGate = document.getElementById('ai-coach-brief-gate');
    var welcomeDismissTimer = null;
    var setupPopupShown = false;
    var setupPopupLeaving = false;
    var aiCoachBriefGateShown = false;
    var aiCoachBriefGateLeaving = false;

    function cleanOnboardingQuery() {
        if (!window.history || !window.history.replaceState) {
            return;
        }
        var url = new URL(window.location.href);
        if (url.searchParams.get('onboarding') === 'complete') {
            url.searchParams.delete('onboarding');
        }
        if (url.searchParams.get('demo') === 'complete' || url.searchParams.get('demo') === 'exited') {
            url.searchParams.delete('demo');
        }
        if (url.searchParams.toString() !== window.location.search.replace(/^\?/, '')) {
            window.history.replaceState({}, document.title, url.pathname + url.search + url.hash);
        }
    }

    function dismissWorkspaceWelcome() {
        if (!welcomeOverlay) {
            return;
        }
        if (welcomeDismissTimer) {
            window.clearTimeout(welcomeDismissTimer);
        }
        welcomeOverlay.classList.add('is-leaving');
        window.setTimeout(function () {
            if (welcomeOverlay && welcomeOverlay.parentNode) {
                welcomeOverlay.parentNode.removeChild(welcomeOverlay);
            }
            welcomeOverlay = null;
            if (!showAICoachBriefGate()) {
                showDashboardSetupPopup();
            }
        }, 280);
    }

    function showDashboardSetupPopup() {
        if (aiCoachBriefGate && !aiCoachBriefGateLeaving) {
            showAICoachBriefGate();
            return;
        }
        if (!setupPopup || setupPopupShown || welcomeOverlay) {
            return;
        }
        setupPopupShown = true;
        window.setTimeout(function () {
            if (!setupPopup) {
                return;
            }
            setupPopup.classList.add('is-visible');
            var primary = document.getElementById('dashboard-setup-popup-primary');
            var dismiss = setupPopup.querySelector('[data-dashboard-setup-dismiss]');
            (primary || dismiss || setupPopup).focus();
        }, 80);
    }

    function dismissDashboardSetupPopup() {
        if (!setupPopup || setupPopupLeaving) {
            return;
        }
        persistDashboardSetupDismissal();
        removeDashboardSetupPopup();
    }

    function removeDashboardSetupPopup() {
        if (!setupPopup || setupPopupLeaving) {
            return;
        }
        setupPopupLeaving = true;
        setupPopup.classList.add('is-leaving');
        setupPopup.classList.remove('is-visible');
        window.setTimeout(function () {
            if (setupPopup && setupPopup.parentNode) {
                setupPopup.parentNode.removeChild(setupPopup);
            }
            setupPopup = null;
        }, 220);
    }

    function showAICoachBriefGate() {
        if (!aiCoachBriefGate || aiCoachBriefGateShown || welcomeOverlay) {
            return false;
        }
        aiCoachBriefGateShown = true;
        window.setTimeout(function () {
            if (!aiCoachBriefGate) {
                return;
            }
            aiCoachBriefGate.classList.add('is-visible');
            var firstField = aiCoachBriefGate.querySelector('textarea');
            (firstField || aiCoachBriefGate).focus();
        }, 80);
        return true;
    }

    function removeAICoachBriefGate(showSetupAfter) {
        if (!aiCoachBriefGate || aiCoachBriefGateLeaving) {
            return;
        }
        aiCoachBriefGateLeaving = true;
        aiCoachBriefGate.classList.add('is-leaving');
        aiCoachBriefGate.classList.remove('is-visible');
        window.setTimeout(function () {
            if (aiCoachBriefGate && aiCoachBriefGate.parentNode) {
                aiCoachBriefGate.parentNode.removeChild(aiCoachBriefGate);
            }
            aiCoachBriefGate = null;
            if (showSetupAfter) {
                showDashboardSetupPopup();
            }
        }, 220);
    }

    function persistDashboardSetupDismissal() {
        if (!setupPopup) {
            return;
        }
        var dismissUrl = setupPopup.getAttribute('data-dismiss-url') || '';
        var csrfToken = setupPopup.getAttribute('data-csrf-token') || '';
        if (!dismissUrl) {
            return;
        }
        var body = 'csrf_token=' + encodeURIComponent(csrfToken);
        try {
            if (window.fetch) {
                window.fetch(dismissUrl, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    credentials: 'same-origin',
                    body: body
                }).catch(function () {});
                return;
            }
            var req = new XMLHttpRequest();
            req.open('POST', dismissUrl);
            req.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            req.send(body);
        } catch (e) {}
    }

    function dismissDashboardSetupCompleteBanner() {
        if (!setupCompleteBanner) {
            return;
        }
        persistDashboardSetupCompleteDismissal();
        var bannerNode = setupCompleteBanner.closest ? (setupCompleteBanner.closest('.workspace-setup-complete-banner') || setupCompleteBanner) : setupCompleteBanner;
        if (bannerNode.parentNode) {
            bannerNode.parentNode.removeChild(bannerNode);
        }
        setupCompleteBanner = null;
    }

    function persistDashboardSetupCompleteDismissal() {
        if (!setupCompleteBanner) {
            return;
        }
        var dismissUrl = setupCompleteBanner.getAttribute('data-dismiss-url') || '';
        var csrfToken = setupCompleteBanner.getAttribute('data-csrf-token') || '';
        if (!dismissUrl) {
            return;
        }
        var body = 'csrf_token=' + encodeURIComponent(csrfToken) + '&dismiss_type=setup_complete';
        try {
            if (window.fetch) {
                window.fetch(dismissUrl, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    credentials: 'same-origin',
                    body: body
                }).catch(function () {});
                return;
            }
            var req = new XMLHttpRequest();
            req.open('POST', dismissUrl);
            req.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            req.send(body);
        } catch (e) {}
    }

    if (aiCoachBriefGate) {
        var briefForm = aiCoachBriefGate.querySelector('[data-ai-coach-brief-form]');
        var briefError = aiCoachBriefGate.querySelector('[data-ai-coach-brief-error]');
        if (briefForm) {
            briefForm.addEventListener('submit', function (event) {
                event.preventDefault();
                if (!aiCoachBriefGate) {
                    return;
                }
                var actionUrl = aiCoachBriefGate.getAttribute('data-action-url') || '';
                var submitButton = briefForm.querySelector('button[type="submit"]');
                var submitLabel = submitButton ? submitButton.querySelector('span') : null;
                if (briefError) {
                    briefError.hidden = true;
                    briefError.textContent = '';
                }
                if (submitButton) {
                    submitButton.disabled = true;
                    submitButton.dataset.originalText = submitLabel ? (submitLabel.textContent || '') : (submitButton.textContent || '');
                    if (submitLabel) {
                        submitLabel.textContent = 'Saving...';
                    }
                }
                window.fetch(actionUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: new FormData(briefForm)
                }).then(function (response) {
                    return response.json().catch(function () { return {}; }).then(function (payload) {
                        if (!response.ok || !payload.success) {
                            var message = payload.error || 'Please complete the required fields.';
                            var missing = Array.isArray(payload.personal_missing_requirements) ? payload.personal_missing_requirements : [];
                            if (missing.length) {
                                message += ' Missing: ' + missing.map(function (item) {
                                    return item.label || item.field || 'Context';
                                }).join(', ') + '.';
                            }
                            throw new Error(message);
                        }
                        window.location.reload();
                    });
                }).catch(function (error) {
                    if (briefError) {
                        briefError.textContent = error && error.message ? error.message : 'Failed to save your optional strategy refinement.';
                        briefError.hidden = false;
                    }
                }).finally(function () {
                    if (submitButton) {
                        submitButton.disabled = false;
                        if (submitLabel) {
                            submitLabel.textContent = submitButton.dataset.originalText || 'Save Optional Strategy';
                        }
                    }
                });
            });
        }
    }

    if (setupPopup) {
        setupPopup.addEventListener('click', function (event) {
            if (event.target === setupPopup || event.target.closest('[data-dashboard-setup-close]')) {
                dismissDashboardSetupPopup();
            }
        });
        var setupDismiss = setupPopup.querySelector('[data-dashboard-setup-dismiss]');
        if (setupDismiss) {
            setupDismiss.addEventListener('click', function () {
                dismissDashboardSetupPopup();
            });
        }
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && setupPopup) {
                dismissDashboardSetupPopup();
            }
        });
    }

    if (setupCompleteBanner) {
        var setupCompleteDismiss = setupCompleteBanner.querySelector('[data-dashboard-setup-complete-dismiss]');
        if (setupCompleteDismiss) {
            setupCompleteDismiss.addEventListener('click', dismissDashboardSetupCompleteBanner);
        }
    }

    if (welcomeOverlay) {
        cleanOnboardingQuery();
        var welcomeStart = document.getElementById('clarity-welcome-start');
        if (welcomeStart) {
            welcomeStart.focus();
            welcomeStart.addEventListener('click', dismissWorkspaceWelcome);
        }
        welcomeOverlay.addEventListener('click', function (event) {
            if (event.target === welcomeOverlay) {
                dismissWorkspaceWelcome();
            }
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                dismissWorkspaceWelcome();
            }
        });
        welcomeDismissTimer = window.setTimeout(dismissWorkspaceWelcome, 6200);
    } else {
        if (!showAICoachBriefGate()) {
            showDashboardSetupPopup();
        }
    }

    function runWhenIdle(callback, timeoutMs) {
        if (typeof callback !== 'function') {
            return;
        }
        if ('requestIdleCallback' in window) {
            window.requestIdleCallback(callback, { timeout: timeoutMs || 3000 });
            return;
        }
        window.setTimeout(callback, Math.min(timeoutMs || 1000, 1000));
    }

    function loadScriptOnce(key, src, onLoad) {
        if (!src) {
            if (typeof onLoad === 'function') {
                onLoad();
            }
            return;
        }

        var existing = document.querySelector('script[data-dashboard-key="' + key + '"]');
        if (existing) {
            if (existing.dataset.loaded === 'true') {
                if (typeof onLoad === 'function') {
                    onLoad();
                }
            } else if (typeof onLoad === 'function') {
                existing.addEventListener('load', onLoad, { once: true });
            }
            return;
        }

        var script = document.createElement('script');
        script.src = src;
        script.async = true;
        script.dataset.dashboardKey = key;
        if (typeof onLoad === 'function') {
            script.addEventListener('load', onLoad, { once: true });
        }
        script.addEventListener('load', function () {
            script.dataset.loaded = 'true';
        }, { once: true });
        document.body.appendChild(script);
    }

    function loadDashboardTasks(force) {
        if (dashboardTasksRequested && force !== true) {
            return;
        }
        dashboardTasksRequested = true;

        var panel = document.getElementById('dashboard-tasks-panel');
        if (!panel) {
            return;
        }

        if (panel.dataset.canRead !== '1') {
            panel.innerHTML = '<div class="empty-state"><div class="empty-state-icon"><i class="fas fa-lock"></i></div><p>Task access is not available for this role.</p></div>';
            return;
        }

        var endpoint = panel.dataset.endpoint;
        if (!endpoint) {
            panel.innerHTML = '<div class="empty-state"><div class="empty-state-icon"><i class="fas fa-exclamation-circle"></i></div><p>Unable to load tasks right now.</p></div>';
            return;
        }

        fetch(endpoint, {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('Task panel request failed with status ' + response.status);
                }
                return response.json();
            })
            .then(function (payload) {
                panel.innerHTML = payload && typeof payload.html === 'string'
                    ? payload.html
                    : '<div class="empty-state"><div class="empty-state-icon"><i class="fas fa-check-circle"></i></div><p>No pending tasks.</p></div>';
            })
            .catch(function () {
                panel.innerHTML = '<div class="empty-state"><div class="empty-state-icon"><i class="fas fa-exclamation-circle"></i></div><p>Unable to load tasks right now.</p></div>';
            });
    }

    window.refreshDashboardTasksPanel = function () {
        dashboardTasksRequested = false;
        loadDashboardTasks(true);
    };

    function escapeHtml(value) {
        var div = document.createElement('div');
        div.textContent = value == null ? '' : String(value);
        return div.innerHTML;
    }

    function setText(selector, value) {
        var node = document.querySelector(selector);
        if (node) {
            node.textContent = value == null ? '' : String(value);
        }
    }

    var readinessViewedTracked = false;

    function trackReadinessEvent(eventKey, gap, href, payload) {
        if (!asyncConfig.outcomeEventUrl || !asyncConfig.csrfToken || !eventKey) {
            return;
        }

        var metadata = {
            mode: payload && payload.mode ? String(payload.mode) : '',
            primary_gap_key: gap && gap.key ? String(gap.key) : '',
            href: href || (gap && gap.href ? String(gap.href) : ''),
            source: payload && payload.source ? String(payload.source) : 'plain_readiness',
            cta_present: !!(gap && gap.cta_present)
        };

        try {
            fetch(asyncConfig.outcomeEventUrl, {
                method: 'POST',
                credentials: 'same-origin',
                keepalive: true,
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify({
                    csrf_token: asyncConfig.csrfToken,
                    event_key: eventKey,
                    metadata: metadata
                })
            }).catch(function () {});
        } catch (error) {
            // Navigation should never wait for measurement.
        }
    }

    function isActionableGuidanceHref(href) {
        if (!href) {
            return false;
        }

        try {
            var target = new URL(String(href), window.location.href);
            var current = new URL(window.location.href);
            var targetPath = target.pathname.replace(/\/+$/, '');
            var currentPath = current.pathname.replace(/\/+$/, '');
            if (target.origin === current.origin && targetPath === currentPath) {
                return false;
            }
            if (/\/dashboard\.php$/i.test(targetPath)) {
                return false;
            }
            return true;
        } catch (error) {
            return false;
        }
    }

    function appendDashboardDestinationContext(href, params) {
        href = href ? String(href).trim() : '';
        if (!href || href === '#' || /^javascript:/i.test(href)) {
            return href;
        }

        params = params || {};
        var clean = {};
        Object.keys(params).forEach(function (key) {
            var value = params[key] == null ? '' : String(params[key]).trim();
            if (value) {
                clean[key] = value;
            }
        });
        if (!Object.keys(clean).length) {
            return href;
        }

        var hash = '';
        var hashIndex = href.indexOf('#');
        if (hashIndex !== -1) {
            hash = href.slice(hashIndex);
            href = href.slice(0, hashIndex);
        }

        var queryIndex = href.indexOf('?');
        var base = queryIndex === -1 ? href : href.slice(0, queryIndex);
        var existing = new URLSearchParams(queryIndex === -1 ? '' : href.slice(queryIndex + 1));
        Object.keys(clean).forEach(function (key) {
            if (!existing.has(key)) {
                existing.set(key, clean[key]);
            }
        });

        var query = existing.toString();
        return base + (query ? '?' + query : '') + hash;
    }

    function founderCommandSetText(root, selector, value, fallback) {
        var element = root.querySelector(selector);
        if (element) {
            element.textContent = value || fallback || '';
        }
    }

    function founderCommandHumanize(value) {
        value = value == null ? '' : String(value).trim();
        if (!value) {
            return '';
        }
        value = value.replace(/_/g, ' ');
        return value.charAt(0).toUpperCase() + value.slice(1);
    }

    function founderCommandSetLink(root, selector, label, href) {
        var link = root.querySelector(selector);
        if (!link) {
            return;
        }
        var safeHref = isActionableGuidanceHref(href ? String(href) : '');
        link.hidden = !safeHref;
        if (!safeHref) {
            link.removeAttribute('href');
            return;
        }
        link.href = appendDashboardDestinationContext(String(href), { source: 'founder_command_center' });
        link.textContent = label || 'Open';
    }

    function founderCommandItem(item, handled) {
        var wrapper = document.createElement(item && item.action_url ? 'a' : 'div');
        wrapper.className = 'founder-command-item' + (handled ? ' is-handled' : '');
        if (wrapper.tagName === 'A') {
            wrapper.href = appendDashboardDestinationContext(String(item.action_url), {
                source: 'founder_command_center',
                signal: item.id || ''
            });
        }
        var title = document.createElement('strong');
        title.textContent = item && item.title ? String(item.title) : (handled ? 'Verified action completed' : 'Review needed');
        wrapper.appendChild(title);
        var summary = item && item.summary ? String(item.summary) : '';
        if (summary) {
            var detail = document.createElement('span');
            detail.textContent = summary;
            wrapper.appendChild(detail);
        }
        return wrapper;
    }

    function renderFounderCommandCenter(commandCenter) {
        var root = document.querySelector('[data-founder-command-center]');
        if (!root || !commandCenter || typeof commandCenter !== 'object') {
            return;
        }

        var focus = commandCenter.primary_constraint || {};
        var context = commandCenter.context || {};
        var health = context.health || {};
        var activity = commandCenter.system_activity || {};
        var lanes = activity.lanes || {};
        var counts = activity.counts || {};
        var growth = commandCenter.growth_experiment || {};
        var growthMetric = growth.metric || {};
        var growthLearning = growth.last_learning || {};
        var commitment = commandCenter.current_commitment || {};

        founderCommandSetText(root, '[data-founder-command-headline]', commandCenter.headline, 'Your operating rhythm is clear');
        founderCommandSetText(root, '[data-founder-command-summary]', commandCenter.summary, 'No immediate decisions are competing for your attention.');
        founderCommandSetText(root, '[data-founder-command-focus-title]', focus.title, 'No immediate constraint needs a decision');
        founderCommandSetText(root, '[data-founder-command-focus-summary]', focus.summary, 'The system will keep watching for meaningful changes.');
        founderCommandSetText(root, '[data-founder-command-focus-why]', focus.why_now, '');
        founderCommandSetLink(root, '[data-founder-command-focus-action]', focus.action_label, focus.action_url);

        var contextLabel = 'Business context';
        if (typeof health.strength !== 'undefined') {
            contextLabel += ' · ' + String(health.strength) + '%';
        }
        founderCommandSetText(root, '[data-founder-command-context-label]', contextLabel);
        var contextPanel = root.querySelector('[data-founder-command-context-panel]');
        if (contextPanel) {
            contextPanel.innerHTML = '';
            var contextSummary = document.createElement('p');
            var contextSummaryData = context.summary || {};
            var contextSummaryParts = [
                contextSummaryData.customer_focus || '',
                contextSummaryData.offer || '',
                contextSummaryData.operating_stage_label || ''
            ].filter(Boolean);
            contextSummary.textContent = contextSummaryParts.length
                ? contextSummaryParts.join(' · ')
                : 'Context is still being assembled from verified business records.';
            contextPanel.appendChild(contextSummary);
            var contextNotes = [];
            var missingContext = Array.isArray(context.missing_context) ? context.missing_context.slice(0, 3) : [];
            missingContext.forEach(function (missing) {
                var label = missing && (missing.label || missing.key) ? String(missing.label || missing.key).replace(/_/g, ' ') : '';
                if (label) {
                    contextNotes.push('Missing: ' + label);
                }
            });
            var conflicts = Array.isArray(context.conflicts) ? context.conflicts.slice(0, 3) : [];
            conflicts.forEach(function (conflict) {
                var label = conflict && (conflict.message || conflict.operating_evidence || conflict.type)
                    ? String(conflict.message || conflict.operating_evidence || conflict.type).replace(/_/g, ' ')
                    : 'A business assumption needs confirmation.';
                contextNotes.push(label);
            });
            if (contextNotes.length) {
                var conflictList = document.createElement('ul');
                contextNotes.slice(0, 4).forEach(function (note) {
                    var item = document.createElement('li');
                    item.textContent = note;
                    conflictList.appendChild(item);
                });
                contextPanel.appendChild(conflictList);
            }
        }

        var needs = Array.isArray(commandCenter.needs_you) ? commandCenter.needs_you.slice(0, 3) : [];
        var needsList = root.querySelector('[data-founder-command-needs-list]');
        founderCommandSetText(root, '[data-founder-command-needs-count]', String(needs.length), '0');
        if (needsList) {
            needsList.innerHTML = '';
            if (!needs.length) {
                var empty = document.createElement('p');
                empty.className = 'founder-command-empty';
                empty.textContent = 'Nothing needs your judgment right now.';
                needsList.appendChild(empty);
            } else {
                needs.forEach(function (item) { needsList.appendChild(founderCommandItem(item || {}, false)); });
            }
        }

        var handled = Array.isArray(lanes.handled) ? lanes.handled.slice(0, 2) : [];
        var handledList = root.querySelector('[data-founder-command-handled-list]');
        founderCommandSetText(root, '[data-founder-command-handled-count]', String(counts.handled || handled.length || 0), '0');
        founderCommandSetText(root, '[data-founder-command-activity-summary]', activity.summary, 'Only completed work with evidence appears here.');
        if (handledList) {
            handledList.innerHTML = '';
            if (!handled.length) {
                var watching = document.createElement('p');
                watching.className = 'founder-command-empty';
                watching.textContent = 'No verified actions completed in this window.';
                handledList.appendChild(watching);
            } else {
                handled.forEach(function (item) { handledList.appendChild(founderCommandItem(item || {}, true)); });
            }
        }

        founderCommandSetText(root, '[data-founder-command-growth-state]', founderCommandHumanize(growth.status_label || growth.status || growth.evidence_state), 'Watching');
        founderCommandSetText(root, '[data-founder-command-growth-title]', growth.title, 'Choose one measurable move');
        founderCommandSetText(root, '[data-founder-command-growth-hypothesis]', growth.hypothesis, 'Turn the current constraint into a small measurable test.');
        founderCommandSetText(root, '[data-founder-command-growth-metric]', growthMetric.label || growthMetric.key, 'Not selected');
        founderCommandSetText(root, '[data-founder-command-growth-evidence]', growth.evidence_label || growth.evidence_state, 'Not started');
        var growthLearningEl = root.querySelector('[data-founder-command-growth-learning]');
        if (growthLearningEl) {
            var hasLearning = !!growthLearning.has_conclusion && !!growthLearning.lesson;
            growthLearningEl.hidden = !hasLearning;
            if (hasLearning) {
                var decision = founderCommandHumanize(growthLearning.decision || 'learned');
                founderCommandSetText(root, '[data-founder-command-growth-learning-text]', decision + ': ' + String(growthLearning.lesson));
            }
        }
        founderCommandSetLink(root, '[data-founder-command-growth-action]', growth.action_label, growth.action_url);

        var commitmentLink = root.querySelector('[data-founder-command-commitment-link]');
        if (commitmentLink) {
            commitmentLink.textContent = commitment.title || 'Choose one measurable next move';
            if (isActionableGuidanceHref(commitment.action_url ? String(commitment.action_url) : '')) {
                commitmentLink.href = appendDashboardDestinationContext(String(commitment.action_url), { source: 'founder_command_center' });
            } else {
                commitmentLink.removeAttribute('href');
            }
        }
        var commitmentMeta = commitment.status && commitment.status !== 'not_selected'
            ? founderCommandHumanize(commitment.status) + (commitment.due_date ? ' · due ' + String(commitment.due_date) : '')
            : 'One commitment keeps the rhythm calm.';
        founderCommandSetText(root, '[data-founder-command-commitment-meta]', commitmentMeta);

        var coachButton = root.querySelector('[data-founder-command-coach]');
        if (coachButton) {
            var focusEvidence = Array.isArray(focus.evidence) ? focus.evidence.slice(0, 3) : [];
            coachButton.dataset.constraintTitle = focus.title ? String(focus.title) : '';
            coachButton.dataset.constraintSummary = focus.summary ? String(focus.summary) : '';
            coachButton.dataset.constraintWhy = focus.why_now ? String(focus.why_now) : '';
            coachButton.dataset.constraintEvidence = focusEvidence.map(function (item) {
                if (!item || typeof item !== 'object') {
                    return '';
                }
                return String(item.label || item.summary || item.source || '').trim();
            }).filter(Boolean).join('; ');
        }

        var generatedAt = commandCenter.generated_at ? new Date(commandCenter.generated_at) : null;
        founderCommandSetText(
            root,
            '[data-founder-command-updated]',
            generatedAt && !isNaN(generatedAt.getTime()) ? 'Updated ' + generatedAt.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : 'Updated quietly'
        );
        root.classList.remove('is-loading');
        root.setAttribute('aria-busy', 'false');
    }

    function loadFounderCommandCenter() {
        if (!asyncConfig.founderCommandCenterUrl || !document.querySelector('[data-founder-command-center]')) {
            return;
        }
        fetch(asyncConfig.founderCommandCenterUrl, {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('Founder Command Center request failed with status ' + response.status);
                }
                return response.json();
            })
            .then(function (payload) {
                renderFounderCommandCenter(payload && payload.command_center ? payload.command_center : null);
            })
            .catch(function () {
                var root = document.querySelector('[data-founder-command-center]');
                if (root) {
                    root.classList.remove('is-loading');
                    root.classList.add('is-unavailable');
                    root.setAttribute('aria-busy', 'false');
                    founderCommandSetText(root, '[data-founder-command-summary]', 'The operating rhythm could not refresh. Your existing workspace is unchanged.');
                }
            });
    }

    function makeReadinessChip(milestone, phase) {
        var chip = document.createElement('div');
        var complete = !!(milestone && milestone.complete);
        var momentumGap = !complete && phase === 'revenue_momentum';
        chip.style.fontSize = '0.75rem';
        chip.style.color = complete ? '#166534' : (momentumGap ? '#92400e' : '#64748b');
        chip.style.background = complete ? '#dcfce7' : (momentumGap ? '#fffbeb' : '#f8fafc');
        chip.style.border = '1px solid ' + (complete ? '#bbf7d0' : (momentumGap ? '#fde68a' : '#e2e8f0'));
        chip.style.borderRadius = '999px';
        chip.style.padding = '0.2rem 0.45rem';
        chip.style.textAlign = 'center';
        chip.style.minWidth = '0';
        chip.style.overflowWrap = 'anywhere';
        chip.textContent = (complete ? '\u2713 ' : '') + (milestone && milestone.label ? String(milestone.label) : 'Setup item');
        return chip;
    }

    function renderPlainReadiness(readiness) {
        if (!readiness || typeof readiness !== 'object') {
            return;
        }

        var card = document.querySelector('[data-plain-readiness-card]');
        if (!card) {
            return;
        }

        var title = card.querySelector('[data-plain-readiness-title]');
        if (title && readiness.headline_label) {
            title.textContent = String(readiness.headline_label);
        }

        var progress = card.querySelector('[data-plain-readiness-progress]');
        if (progress) {
            progress.textContent = readiness.progress_label || '';
            progress.style.display = readiness.progress_label ? 'block' : 'none';
        }

        var milestones = Array.isArray(readiness.milestones) ? readiness.milestones.slice(0, 6) : [];
        var milestoneGrid = card.querySelector('[data-plain-readiness-milestones]');
        if (milestoneGrid && milestones.length) {
            milestoneGrid.innerHTML = '';
            milestones.forEach(function (milestone) {
                milestoneGrid.appendChild(makeReadinessChip(milestone || {}, readiness.phase || 'setup'));
            });
        }

        var gap = readiness.primary_gap && typeof readiness.primary_gap === 'object'
            ? readiness.primary_gap
            : {};

        var reason = card.querySelector('[data-plain-readiness-reason]');
        if (reason) {
            reason.textContent = gap.reason || '';
            reason.style.display = gap.reason ? 'block' : 'none';
        }

        var status = card.querySelector('[data-plain-readiness-status]');
        if (status) {
            status.textContent = gap.status_label || '';
            status.style.display = gap.status_label ? 'block' : 'none';
        }

        var actions = card.querySelector('[data-plain-readiness-actions]');
        var cta = card.querySelector('[data-plain-readiness-cta]');
        var href = gap.href ? String(gap.href) : '';
        var hasActionableCta = readiness.phase !== 'revenue_momentum' && !!(cta && isActionableGuidanceHref(href));
        var destinationHref = hasActionableCta
            ? appendDashboardDestinationContext(href, {
                source: readiness.source || 'dashboard_readiness',
                gap: gap.key || ''
            })
            : '';
        gap.cta_present = hasActionableCta;
        if (hasActionableCta) {
            cta.href = destinationHref;
            cta.textContent = gap.cta_label || 'Open setup';
            cta.dataset.readinessGapKey = gap.key || '';
            cta.dataset.readinessHref = destinationHref;
            cta.dataset.readinessSource = readiness.source || 'plain_readiness';
            cta.dataset.readinessMode = readiness.mode || '';
            if (actions) {
                actions.style.display = 'flex';
            }
        } else {
            if (cta) {
                cta.removeAttribute('href');
                cta.textContent = '';
                cta.dataset.readinessGapKey = gap.key || '';
                cta.dataset.readinessHref = '';
                cta.dataset.readinessSource = readiness.source || 'plain_readiness';
                cta.dataset.readinessMode = readiness.mode || '';
            }
            if (actions) {
                actions.style.display = 'none';
            }
        }

        if (!readinessViewedTracked) {
            readinessViewedTracked = true;
            trackReadinessEvent('dashboard.readiness.viewed', gap, destinationHref || href, readiness);
        }
    }

    function loadPlainReadiness() {
        if (!asyncConfig.plainReadinessUrl || !document.querySelector('[data-plain-readiness-card]')) {
            return;
        }

        fetch(asyncConfig.plainReadinessUrl, {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('Plain readiness request failed with status ' + response.status);
                }
                return response.json();
            })
            .then(function (payload) {
                renderPlainReadiness(payload && payload.readiness ? payload.readiness : payload);
            })
            .catch(function () {});
    }

    function automationActionKindClass(kind) {
        var value = String(kind || 'recommended');
        if (value === 'required') {
            return 'required';
        }
        if (value === 'optional_enrichment') {
            return 'optional-enrichment';
        }
        return 'recommended';
    }

    function automationActionIcon(kind, fallback) {
        var value = String(kind || '');
        if (value === 'required') {
            return 'fa-bolt';
        }
        if (value === 'optional_enrichment') {
            return 'fa-sparkles';
        }
        return fallback || 'fa-arrow-up-right-from-square';
    }

    function automationActionHref(action) {
        if (!action || typeof action !== 'object' || !action.href) {
            return '';
        }
        return appendDashboardDestinationContext(String(action.href), {
            source: 'automation_battery',
            action: action.key || '',
            layer: action.layer_key || ''
        });
    }

    function renderAutomationActionChip(action, scope) {
        action = action && typeof action === 'object' ? action : {};
        var label = String(action.label || action.cta_label || 'Open action');
        var kind = String(action.kind || 'recommended');
        var className = (scope === 'layer' ? 'automation-layer-chip ' : 'automation-readiness-chip ') + automationActionKindClass(kind);
        var href = automationActionHref(action);
        var icon = automationActionIcon(kind, scope === 'layer' ? 'fa-arrow-up-right-from-square' : 'fa-arrow-up-right-from-square');
        var attrs = ' data-automation-action-key="' + escapeHtml(action.key || '') + '"' +
            ' data-automation-action-layer="' + escapeHtml(action.layer_key || '') + '"' +
            ' data-automation-action-kind="' + escapeHtml(kind) + '"' +
            ' data-automation-action-href="' + escapeHtml(href) + '"';
        var content = '<i class="fas ' + icon + '" aria-hidden="true"></i>' + escapeHtml(label);

        if (href) {
            return '<a class="' + className + '" href="' + escapeHtml(href) + '" data-automation-action-link' + attrs + '>' + content + '</a>';
        }
        return '<span class="' + className + '"' + attrs + '>' + content + '</span>';
    }

    function renderAutomationActionChips(actions, scope) {
        return actions.slice(0, 3).map(function (action) {
            return renderAutomationActionChip(action, scope || 'readiness');
        }).join('');
    }

    function renderAutomationProgressChip(signal, scope) {
        signal = signal && typeof signal === 'object' ? signal : {};
        var label = String(signal.label || signal.source_blocker || 'Progress signal');
        var severity = String(signal.severity || 'info');
        if (['info', 'warning', 'critical'].indexOf(severity) === -1) {
            severity = 'info';
        }
        var className = (scope === 'layer' ? 'automation-layer-chip ' : 'automation-readiness-chip ') + 'progress ' + severity;
        var icon = severity === 'critical' ? 'fa-triangle-exclamation' : (severity === 'warning' ? 'fa-clock' : 'fa-circle-info');
        var attrs = ' data-automation-progress-key="' + escapeHtml(signal.key || '') + '"' +
            ' data-automation-progress-layer="' + escapeHtml(signal.layer_key || '') + '"' +
            ' data-automation-progress-severity="' + escapeHtml(severity) + '"';

        return '<span class="' + className + '"' + attrs + '><i class="fas ' + icon + '" aria-hidden="true"></i>' + escapeHtml(label) + '</span>';
    }

    function renderAutomationProgressChips(signals, scope) {
        if (!Array.isArray(signals) || !signals.length) {
            return '';
        }
        return signals.slice(0, 4).map(function (signal) {
            if (signal && typeof signal === 'object') {
                return renderAutomationProgressChip(signal, scope || 'readiness');
            }
            return renderAutomationProgressChip({ label: String(signal || ''), severity: 'info' }, scope || 'readiness');
        }).join('');
    }

    function renderAutomationChips(items, type) {
        if (!Array.isArray(items) || !items.length) {
            return '<span class="automation-readiness-chip booster"><i class="fas fa-check"></i> No blocking signals found</span>';
        }
        return items.slice(0, 4).map(function (item) {
            var icon = type === 'blocker' ? 'fa-bolt' : 'fa-check';
            var chipClass = type === 'blocker' ? 'blocker' : 'booster';
            return '<span class="automation-readiness-chip ' + chipClass + '"><i class="fas ' + icon + '"></i>' + escapeHtml(item) + '</span>';
        }).join('');
    }

    function renderAutomationLayers(layers) {
        if (!layers || typeof layers !== 'object') {
            return '';
        }
        return Object.keys(layers).filter(function (key) {
            var layer = layers[key] || {};
            return layer.is_visible !== false;
        }).map(function (key) {
            var layer = layers[key] || {};
            var bucket = escapeHtml(layer.bucket || 'low');
            var blockers = Array.isArray(layer.blockers) ? layer.blockers : [];
            var signals = Array.isArray(layer.signals) ? layer.signals : [];
            var actions = Array.isArray(layer.actions) ? layer.actions : [];
            var progressSignals = Array.isArray(layer.progress_signals) ? layer.progress_signals : [];
            var chips = actions.length ? actions.slice(0, 2).map(function (action) {
                return renderAutomationActionChip(action, 'layer');
            }).join('') : (progressSignals.length ? progressSignals.slice(0, 2).map(function (signal) {
                return renderAutomationProgressChip(signal, 'layer');
            }) : (blockers.length ? blockers.slice(0, 2).map(function (blocker) {
                return renderAutomationProgressChip({ label: blocker, severity: 'info' }, 'layer');
            }) : signals.slice(0, 2).map(function (signal) {
                return '<span class="automation-layer-chip signal"><i class="fas fa-check"></i>' + escapeHtml(signal) + '</span>';
            }))).join('');
            return '<div class="automation-layer-card">' +
                '<div class="automation-layer-top">' +
                    '<div>' +
                        '<div class="automation-layer-name">' + escapeHtml(layer.label || 'Layer') + '</div>' +
                        '<div class="automation-layer-value">' + escapeHtml(layer.detail_value || ((layer.score || 0) + '%')) + '</div>' +
                    '</div>' +
                    '<div class="automation-layer-status ' + bucket + '">' + escapeHtml(layer.status_label || 'Tracking') + '</div>' +
                '</div>' +
                '<div class="automation-layer-copy">' + escapeHtml(layer.summary || '') + '</div>' +
                '<div class="automation-layer-signals">' + chips + '</div>' +
            '</div>';
        }).join('');
    }

    function automationAttentionCount(status, key) {
        var counts = status && status.attention_counts && typeof status.attention_counts === 'object'
            ? status.attention_counts
            : {};
        return Math.max(0, parseInt(counts[key] || '0', 10) || 0);
    }

    function automationHealthTone(status) {
        var summary = status && status.health_summary && typeof status.health_summary === 'object'
            ? status.health_summary
            : {};
        var tone = String(summary.tone || summary.status || 'building');
        if (['healthy', 'building', 'warning', 'critical'].indexOf(tone) === -1) {
            tone = 'building';
        }
        return tone;
    }

    function applyAutomationHealthBand(status) {
        var band = document.querySelector('[data-automation-health-band]');
        if (!band || !status || typeof status !== 'object') {
            return;
        }

        var summary = status.health_summary && typeof status.health_summary === 'object' ? status.health_summary : {};
        var tone = automationHealthTone(status);
        var requiredActions = automationAttentionCount(status, 'required_actions');
        var jobsToReview = automationAttentionCount(status, 'failed_jobs') + automationAttentionCount(status, 'stale_jobs');
        var clearNode = document.querySelector('[data-automation-health-clear]');

        band.className = 'automation-health-band ' + tone;
        setText('[data-automation-health-label]', summary.label || 'Automation Health');
        setText('[data-automation-health-message]', summary.message || 'Automation health is based on setup, workers, actions, and learning evidence.');
        setText('[data-automation-health-score]', (Math.max(0, Math.min(100, parseInt(status.score || '0', 10) || 0))) + '%');
        setText('[data-automation-health-required]', String(requiredActions));
        setText('[data-automation-health-jobs]', String(jobsToReview));
        setText('[data-automation-health-last]', summary.last_checked_label || status.updated_label || 'Not checked yet');
        setText('[data-automation-health-refresh]', summary.refresh_state_label || status.refresh_state_label || 'Not checked yet.');

        if (clearNode) {
            clearNode.hidden = !summary.no_setup_checks_waiting;
        }
    }

    function markAutomationHealthRefreshLoading() {
        var band = document.querySelector('[data-automation-health-band]');
        if (!band) {
            return;
        }
        setText('[data-automation-health-refresh]', 'Checking automation health...');
    }

    function markAutomationHealthRefreshFailed() {
        var band = document.querySelector('[data-automation-health-band]');
        if (!band) {
            return;
        }
        band.className = 'automation-health-band warning';
        setText('[data-automation-health-label]', 'Refresh failed');
        setText('[data-automation-health-message]', 'Refresh failed. Showing the last saved score.');
        setText('[data-automation-health-refresh]', 'Refresh failed. Showing the last saved score.');
    }

    function automationBatteryTooltipSuffix(label, fallback) {
        var suffix = String(label || fallback || '').trim();
        if (!suffix) {
            return '';
        }
        return /[.!?]$/.test(suffix) ? suffix : suffix + '.';
    }

    function automationBatteryLastCheckedLabel(updatedLabel) {
        var label = String(updatedLabel || '').trim();
        return label ? label.replace(/^Updated\b/i, 'Last checked') : '';
    }

    function automationBatteryRefreshTooltip(updatedLabel, state) {
        if (state === 'failed') {
            return 'Refresh failed. Showing the last saved score.';
        }
        if (state === 'loading') {
            return 'Checking your automation score. ' + automationBatteryTooltipSuffix(automationBatteryLastCheckedLabel(updatedLabel), 'Showing the last saved score.');
        }
        return 'Check your automation score. ' + automationBatteryTooltipSuffix(automationBatteryLastCheckedLabel(updatedLabel), 'Not checked yet.');
    }

    function automationBatteryStatusTooltip(status) {
        var bucket = String((status && status.bucket) || 'pending');
        var label = String((status && status.status_label) || '');
        if (bucket === 'low' || label === 'Early automation') {
            return 'Your workspace is just getting started. A few basics still need attention.';
        }
        if (bucket === 'medium' || label === 'Building automation') {
            return 'Your workspace is making progress, but a few important pieces still need strengthening.';
        }
        if (bucket === 'high' || label === 'Near full automation') {
            return 'Most of your workspace is running well. Only a few gaps remain.';
        }
        if (bucket === 'full' || label === 'Full automation') {
            return 'Your workspace is in strong shape and ready to support more automated work.';
        }
        return 'Check your current automation score when you are ready.';
    }

    function setAutomationBatteryShellState(shell, state, updatedLabel) {
        if (!shell) {
            return;
        }
        var loading = state === 'loading';
        var tooltip = automationBatteryRefreshTooltip(updatedLabel, state);
        var tooltipNode = document.querySelector('[data-automation-battery-refresh-tooltip]');

        shell.disabled = loading;
        shell.classList.toggle('is-refreshing', loading);
        if (loading) {
            shell.setAttribute('aria-busy', 'true');
        } else {
            shell.removeAttribute('aria-busy');
        }
        if (updatedLabel) {
            shell.setAttribute('data-automation-battery-last-label', automationBatteryLastCheckedLabel(updatedLabel));
        }
        shell.setAttribute('aria-label', tooltip);
        shell.removeAttribute('title');
        if (tooltipNode) {
            tooltipNode.textContent = tooltip;
        }
    }

    function applyAutomationBattery(status) {
        if (!status || typeof status !== 'object') {
            return;
        }

        var readinessCard = document.querySelector('[data-automation-readiness-card]');
        if (readinessCard) {
            readinessCard.hidden = status.is_visible === false;
        }

        var score = Math.max(0, Math.min(100, parseInt(status.score || '0', 10) || 0));
        var bucket = String(status.bucket || 'low');
        var fill = document.querySelector('[data-automation-battery-fill]');
        var shell = document.querySelector('[data-automation-battery-shell]');
        var scoreNode = document.querySelector('[data-automation-battery-score]');
        var statusNode = document.querySelector('[data-automation-battery-status]');

        if (fill) {
            fill.className = 'hero-automation-fill ' + bucket;
            fill.style.width = Math.max(6, score) + '%';
        }
        if (shell) {
            setAutomationBatteryShellState(shell, 'idle', status.updated_label || '');
        }
        if (scoreNode) {
            scoreNode.textContent = score + '%';
        }
        if (statusNode) {
            var statusTooltip = automationBatteryStatusTooltip(status);
            var statusLabel = status.status_label || 'Checked';
            var statusTooltipNode = document.querySelector('[data-automation-battery-status-tooltip]');
            statusNode.className = 'hero-automation-battery-status ' + bucket;
            statusNode.textContent = statusLabel;
            statusNode.setAttribute('aria-label', statusLabel + '. ' + statusTooltip);
            statusNode.removeAttribute('title');
            if (statusTooltipNode) {
                statusTooltipNode.textContent = statusTooltip;
            }
        }

        applyAutomationHealthBand(status);
        setText('[data-automation-readiness-headline]', status.headline_label || status.mode_label || 'Manual');
        setText('[data-automation-readiness-summary]', status.summary || '');
        setText('[data-automation-readiness-progress]', status.setup_progress_label || '');

        var blockers = Array.isArray(status.top_blockers) ? status.top_blockers : [];
        var boosters = Array.isArray(status.top_boosters) ? status.top_boosters : [];
        var topActions = Array.isArray(status.top_actions) ? status.top_actions : [];
        var topProgressSignals = Array.isArray(status.top_progress_signals) ? status.top_progress_signals : [];
        var contextEnrichments = Array.isArray(status.context_enrichments) ? status.context_enrichments : [];
        var hasProgressSignals = topProgressSignals.length > 0 || blockers.length > 0;
        var signalActions = topActions.length ? topActions : (hasProgressSignals ? [] : contextEnrichments);
        var hasActionChips = signalActions.length > 0;

        setText('[data-automation-readiness-signal-title]', topActions.length
            ? 'Next Automation Actions'
            : (hasProgressSignals ? 'Automation Progress' : (contextEnrichments.length ? 'Optional AI Context' : 'Strongest Automation Signals')));
        setText('[data-automation-readiness-signal-copy]', topActions.length
            ? 'Open the existing setup or review page that clears each readiness signal.'
            : (hasProgressSignals
                ? 'These are evidence and system-learning signals. They clear as normal work runs or admin-managed controls improve.'
                : (contextEnrichments.length
                    ? 'These improve AI guidance but do not block automation readiness.'
                    : 'These are the strongest signals pushing the system toward full automation.')));

        var list = document.querySelector('[data-automation-readiness-list]');
        if (list) {
            list.innerHTML = hasActionChips
                ? renderAutomationActionChips(signalActions, 'readiness')
                : (topProgressSignals.length
                    ? renderAutomationProgressChips(topProgressSignals, 'readiness')
                    : (blockers.length
                        ? renderAutomationProgressChips(blockers, 'readiness')
                        : renderAutomationChips(boosters, 'booster')));
        }

        var layerGrid = document.querySelector('[data-automation-layer-grid]');
        if (layerGrid) {
            layerGrid.innerHTML = renderAutomationLayers(status.layers || {});
        }
    }

    function automationBatteryIconHtml(iconClass, label, spin) {
        return '<i class="fas ' + iconClass + (spin ? ' fa-spin' : '') + '" aria-hidden="true"></i><span class="sr-only">' + escapeHtml(label) + '</span>';
    }

    function setAutomationBatteryIconTriggers(triggers, state) {
        var iconClass = 'fa-rotate-right';
        var label = 'Refresh automation readiness';
        var spin = false;
        var disabled = false;

        if (state === 'loading') {
            iconClass = 'fa-spinner';
            label = 'Refreshing automation readiness';
            spin = true;
            disabled = true;
        } else if (state === 'loaded') {
            iconClass = 'fa-check';
            label = 'Automation readiness refreshed';
        } else if (state === 'skipped') {
            iconClass = 'fa-check';
            label = 'Automation readiness already fresh';
        } else if (state === 'manual_only') {
            iconClass = 'fa-circle-info';
            label = 'Automatic checks are off';
        } else if (state === 'failed') {
            iconClass = 'fa-rotate-right';
            label = 'Retry automation readiness refresh';
        }

        triggers.forEach(function (trigger) {
            trigger.disabled = disabled;
            trigger.setAttribute('aria-label', label);
            trigger.setAttribute('title', label);
            trigger.innerHTML = automationBatteryIconHtml(iconClass, label, spin);
        });
    }

    function automationBatteryResultState(status) {
        if (!status || typeof status !== 'object' || !status.refresh_skipped) {
            return 'loaded';
        }
        if (String(status.refresh_skip_reason || '') === 'manual_only') {
            return 'manual_only';
        }
        return 'skipped';
    }

    function automationBatteryRequestUrl(source) {
        var sourceValue = source || 'manual';
        try {
            var requestUrl = new URL(asyncConfig.automationBatteryUrl, window.location.href);
            requestUrl.searchParams.set('source', sourceValue);
            return requestUrl.toString();
        } catch (error) {
            var separator = asyncConfig.automationBatteryUrl.indexOf('?') === -1 ? '?' : '&';
            return asyncConfig.automationBatteryUrl + separator + 'source=' + encodeURIComponent(sourceValue);
        }
    }

    function loadAutomationBattery(source) {
        if (!asyncConfig.automationBatteryUrl || (!document.querySelector('[data-automation-battery-hero]') && !document.querySelector('[data-automation-readiness-card]'))) {
            return;
        }

        var hero = document.querySelector('[data-automation-battery-hero]');
        var card = document.querySelector('[data-automation-readiness-card]');
        var batteryShell = document.querySelector('[data-automation-battery-shell]');
        var iconTriggers = Array.prototype.slice.call(document.querySelectorAll('.automation-readiness-load-button[data-automation-battery-load]'));
        var lastUpdatedLabel = loadAutomationBattery.updatedLabel || (batteryShell ? batteryShell.getAttribute('data-automation-battery-last-label') : '') || '';
        if (loadAutomationBattery.requested) {
            return;
        }
        loadAutomationBattery.requested = true;
        if (hero) {
            hero.classList.add('is-loading');
            hero.setAttribute('aria-busy', 'true');
        }
        if (card) {
            card.classList.add('is-loading');
            card.setAttribute('aria-busy', 'true');
        }
        setAutomationBatteryShellState(batteryShell, 'loading', lastUpdatedLabel);
        setAutomationBatteryIconTriggers(iconTriggers, 'loading');
        markAutomationHealthRefreshLoading();

        fetch(automationBatteryRequestUrl(source || 'manual'), {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('Automation battery request failed with status ' + response.status);
                }
                return response.json();
            })
            .then(function (payload) {
                var status = payload && payload.status ? payload.status : payload;
                applyAutomationBattery(status);
                loadAutomationBattery.updatedLabel = status && status.updated_label ? status.updated_label : '';
                loadAutomationBattery.requested = false;
                if (hero) {
                    hero.classList.remove('is-loading');
                    hero.classList.add('is-loaded');
                    hero.removeAttribute('aria-busy');
                }
                if (card) {
                    card.classList.remove('is-loading');
                    card.classList.add('is-loaded');
                    card.removeAttribute('aria-busy');
                }
                setAutomationBatteryShellState(batteryShell, 'idle', loadAutomationBattery.updatedLabel);
                setAutomationBatteryIconTriggers(iconTriggers, automationBatteryResultState(status));
                window.setTimeout(function () {
                    setAutomationBatteryIconTriggers(iconTriggers, 'idle');
                }, 1200);
            })
            .catch(function () {
                loadAutomationBattery.requested = false;
                if (hero) {
                    hero.classList.remove('is-loading');
                    hero.removeAttribute('aria-busy');
                }
                if (card) {
                    card.classList.remove('is-loading');
                    card.removeAttribute('aria-busy');
                }
                setAutomationBatteryShellState(batteryShell, 'failed', lastUpdatedLabel);
                setAutomationBatteryIconTriggers(iconTriggers, 'failed');
                markAutomationHealthRefreshFailed();
                setText('[data-automation-readiness-summary]', 'Automation readiness is temporarily unavailable. The dashboard shell is still ready to use.');
            });
    }

    function loadDashboardChartsWhenUseful() {
        var charts = document.querySelector('[data-dashboard-charts]');
        if (!charts) {
            return;
        }

        if (!('IntersectionObserver' in window)) {
            runWhenIdle(function () {
                loadScriptOnce('dashboard-chartjs', asyncConfig.chartJsUrl, initDashboardCharts);
            }, 3500);
            return;
        }

        var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) {
                    return;
                }
                observer.disconnect();
                if (window.protectedDemoClientState && window.protectedDemoClientState.is_protected_demo) {
                    window.dispatchEvent(new CustomEvent('protected-demo:cue', {
                        detail: { cue: 'dashboard_charts_revealed', visibleKey: 'dashboard_charts' }
                    }));
                    charts.classList.add('protected-demo-chart-reveal');
                }
                loadScriptOnce('dashboard-chartjs', asyncConfig.chartJsUrl, initDashboardCharts);
            });
        }, { rootMargin: '240px 0px' });

        observer.observe(charts);
    }

    function hydrateAICoachAccess() {
        if (!aiCoachButton || !asyncConfig.aiCoachAccessUrl) {
            return;
        }

        fetch(asyncConfig.aiCoachAccessUrl, { credentials: 'same-origin' })
            .then(function (response) { return response.json(); })
            .then(function (payload) {
                if (!payload || !payload.enabled) {
                    return;
                }
                aiCoachButton.hidden = false;
                aiCoachButton.removeAttribute('aria-hidden');
                if (window.location.hash === '#ai-coach') {
                    loadScriptOnce('dashboard-ai-coach', asyncConfig.aiCoachScriptUrl, function () {
                        window.setTimeout(function () {
                            aiCoachButton.click();
                        }, 0);
                    });
                }
            })
            .catch(function () {
                // Access is deny-by-default; the optional button remains hidden.
            });
    }

    var aiCoachButton = document.getElementById('ai-coach-open');
    if (aiCoachButton) {
        aiCoachButton.addEventListener('click', function firstCoachClick(event) {
            if (aiCoachButton.hasAttribute('data-founder-command-coach')
                && window.ClarityChatBubble
                && typeof window.ClarityChatBubble.ask === 'function') {
                event.preventDefault();
                event.stopImmediatePropagation();
                var title = String(aiCoachButton.dataset.constraintTitle || 'the current founder constraint').trim();
                var summary = String(aiCoachButton.dataset.constraintSummary || '').trim();
                var whyNow = String(aiCoachButton.dataset.constraintWhy || '').trim();
                var evidence = String(aiCoachButton.dataset.constraintEvidence || '').trim();
                var question = 'Why is "' + title + '" the highest-priority constraint right now?';
                if (summary) {
                    question += ' Current signal: ' + summary + '.';
                }
                if (whyNow) {
                    question += ' Why now: ' + whyNow + '.';
                }
                if (evidence) {
                    question += ' Evidence: ' + evidence + '.';
                }
                question += ' Explain the evidence, what still needs founder judgment, and the next measurable action.';
                window.ClarityChatBubble.ask(question, 'founder_command_center');
                return;
            }
            var scriptLoaded = document.querySelector('script[data-dashboard-key="dashboard-ai-coach"][data-loaded="true"]');
            if (scriptLoaded) {
                return;
            }
            event.preventDefault();
            event.stopImmediatePropagation();
            loadScriptOnce('dashboard-ai-coach', asyncConfig.aiCoachScriptUrl, function () {
                window.setTimeout(function () {
                    aiCoachButton.click();
                }, 0);
            });
        }, { capture: true });

    }

    var readinessCta = document.querySelector('[data-plain-readiness-cta]');
    if (readinessCta) {
        readinessCta.addEventListener('click', function (event) {
            var targetHref = readinessCta.dataset.readinessHref || readinessCta.getAttribute('href') || '';
            if (!isActionableGuidanceHref(targetHref)) {
                event.preventDefault();
                return;
            }
            trackReadinessEvent('dashboard.readiness.clicked', {
                key: readinessCta.dataset.readinessGapKey || '',
                href: targetHref,
                source: readinessCta.dataset.readinessSource || 'plain_readiness',
                mode: readinessCta.dataset.readinessMode || '',
                cta_present: true
            }, targetHref, {
                source: readinessCta.dataset.readinessSource || 'plain_readiness',
                mode: readinessCta.dataset.readinessMode || ''
            });
        });
    }

    document.addEventListener('click', function (event) {
        var target = event.target && event.target.closest ? event.target.closest('[data-automation-action-link]') : null;
        if (!target) {
            return;
        }

        var targetHref = target.dataset.automationActionHref || target.getAttribute('href') || '';
        if (!targetHref) {
            event.preventDefault();
            return;
        }

        trackReadinessEvent('dashboard.readiness.clicked', {
            key: target.dataset.automationActionKey || '',
            href: targetHref,
            source: 'automation_battery',
            mode: '',
            cta_present: true
        }, targetHref, {
            source: 'automation_battery',
            mode: ''
        });
    });

    document.querySelectorAll('[data-automation-battery-load]').forEach(function (trigger) {
        trigger.addEventListener('click', function () {
            loadAutomationBattery('manual');
        });
    });

    window.addEventListener('load', function () {
        loadDashboardChartsWhenUseful();
        runWhenIdle(loadFounderCommandCenter, 350);
        runWhenIdle(hydrateAICoachAccess, 300);
        runWhenIdle(loadPlainReadiness, 900);
        runWhenIdle(loadDashboardTasks, 2500);
        if (asyncConfig.automationBatteryShouldIdleRefresh) {
            runWhenIdle(function () {
                loadAutomationBattery('idle');
            }, asyncConfig.automationBatteryIdleDelayMs || 30000);
        }
    }, { once: true });
})();
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
