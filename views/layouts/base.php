<?php
$layoutPerfEnabled = isset($_GET['perf_debug']) && (
    (($_ENV['APP_ENV'] ?? 'production') === 'development')
    || filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL)
    || filter_var($_ENV['DEBUG'] ?? false, FILTER_VALIDATE_BOOL)
);
$layoutPerfStartedAt = microtime(true);
$layoutPerfMarks = [];
$layoutPerfMark = static function (string $label) use (&$layoutPerfMarks, $layoutPerfEnabled): void {
    if ($layoutPerfEnabled) {
        $layoutPerfMarks[] = [$label, microtime(true)];
    }
};
$layoutPerfFlush = static function () use (&$layoutPerfMarks, $layoutPerfEnabled, $layoutPerfStartedAt): void {
    if (!$layoutPerfEnabled || headers_sent()) {
        return;
    }

    $parts = [];
    $previous = $layoutPerfStartedAt;
    foreach ($layoutPerfMarks as [$label, $markedAt]) {
        $safeLabel = preg_replace('/[^A-Za-z0-9_-]+/', '_', $label);
        $parts[] = 'layout_' . $safeLabel . ';dur=' . number_format(max(0, ($markedAt - $previous) * 1000), 1, '.', '');
        $previous = $markedAt;
    }
    $parts[] = 'layout_total;dur=' . number_format(max(0, (microtime(true) - $layoutPerfStartedAt) * 1000), 1, '.', '');
    header('Server-Timing: ' . implode(', ', $parts), false);
};

$basePath = function_exists('getBasePath') ? rtrim(getBasePath(), '/') : '';
$assetBase = function_exists('assetUrl') ? rtrim(assetUrl(''), '/') : ($basePath . '/assets');
$publicAssetsDir = dirname(__DIR__, 2) . '/public/assets';
$versionedAssetUrl = static function (string $path) use ($assetBase, $publicAssetsDir): string {
    $path = ltrim($path, '/');
    $assetPath = $publicAssetsDir . '/' . $path;
    $appVersion = defined('APP_VERSION') ? (string) APP_VERSION : 'dev';
    $fileVersion = is_file($assetPath) ? (string) @filemtime($assetPath) : 'missing';

    return $assetBase . '/' . $path . '?v=' . rawurlencode($appVersion . '-' . $fileVersion);
};
$mainCssUrl = $assetBase . '/css/main.css?v=visual-system-20260623-cue-scenes';
$chatBubbleCssUrl = $versionedAssetUrl('css/chat-bubble.css');
$aiUiConsistencyJsUrl = $versionedAssetUrl('js/ai-ui-consistency.js');
$chatBubbleJsUrl = $versionedAssetUrl('js/chat-bubble.js');
$brandLogoUrl = $assetBase . '/images/logo-web.png';
$brandLogoSrcset = $assetBase . '/images/logo-web.png 1x, ' . $assetBase . '/images/logo-web@2x.png 2x';
$faviconUrl = $assetBase . '/images/clarity-logo-64.png';
$layoutContent = (string) ($content ?? '');
$layoutCurrentPage = basename(parse_url((string) ($_SERVER['REQUEST_URI'] ?? ($_SERVER['SCRIPT_NAME'] ?? '')), PHP_URL_PATH) ?: '');
$layoutBodyClasses = [];
if (!empty($bodyClass)) {
    foreach (preg_split('/\s+/', trim((string) $bodyClass)) ?: [] as $bodyClassName) {
        $bodyClassName = (string) preg_replace('/[^A-Za-z0-9_-]+/', '', (string) $bodyClassName);
        if ($bodyClassName !== '') {
            $layoutBodyClasses[] = $bodyClassName;
        }
    }
}
$layoutContentIncludesQuill = strpos($layoutContent, 'cdn.quilljs.com/1.3.6/quill.js') !== false;
$layoutContentIncludesQuillCss = strpos($layoutContent, 'cdn.quilljs.com/1.3.6/quill.snow.css') !== false;
$layoutNeedsRichTextEditor = !empty($requiresRichTextEditor)
    || strpos($layoutContent, 'class="rich-text-editor') !== false
    || strpos($layoutContent, "class='rich-text-editor") !== false;
$layoutNeedsQuill = !empty($requiresQuillEditor)
    || (!$layoutContentIncludesQuill && strpos($layoutContent, 'new Quill') !== false);
$sessionAutomationPage = basename(parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: '');
$workspaceBillingState = null;
$workspaceAiBillingState = null;
$workspacePlanSummary = null;
$platformImpersonationContext = null;
$guidedDemoClientState = null;
$guidedDemoUnpaidFunnelActive = false;
$protectedDemoClientState = null;
$isProtectedDemoSession = false;
$isPresentationWorkspaceSession = false;
$layoutWorkspace2faSetupPending = !empty($workspace2faSetupFlowActive ?? false);
if (!$layoutWorkspace2faSetupPending && $sessionAutomationPage === 'settings_2fa.php' && \CRM\Auth::check()) {
    try {
        $layoutPendingWorkspace2fa = \CRM\Auth::pendingWorkspace2FASetup();
        $layoutCurrentUser = \CRM\Auth::user() ?: [];
        $layoutWorkspace2faSetupPending = is_array($layoutPendingWorkspace2fa)
            && (int) ($layoutPendingWorkspace2fa['user_id'] ?? 0) === (int) ($layoutCurrentUser['id'] ?? 0)
            && (int) ($layoutPendingWorkspace2fa['workspace_id'] ?? 0) > 0;
    } catch (\Throwable $e) {
        $layoutWorkspace2faSetupPending = false;
    }
}
$sessionAutomationEnabled = empty($disableSessionAutomationWorker) && !$layoutWorkspace2faSetupPending;
$protectedDemoAllowedRoutes = [
    'dashboard.php',
    'contacts.php',
    'contact_view.php',
    'inbox.php',
    'conversation.php',
    'tasks.php',
    'task_view.php',
    'targets.php',
    'target_view.php',
    'reports.php',
    'analytics.php',
    'invoices.php',
    'workspace_skills.php',
    'startup_journey.php',
    'notifications.php',
    'owner_support.php',
    'docs.php',
    'privacy-policy.php',
    'cookie-policy.php',
    'terms-of-service.php',
    'logout.php',
];
$layoutPerfMark('bootstrap');

if (\CRM\Auth::check()) {
    try {
        $activeWorkspaceId = (int) (\CRM\Services\WorkspaceContext::currentWorkspaceId() ?? 0);
        $activeUser = \CRM\Auth::user() ?: [];
        $layoutPerfMark('workspace_context');
        if ($activeWorkspaceId > 0) {
            try {
                $isPresentationWorkspaceSession = (new \CRM\Services\PresentationWorkspaceGuardService())->isPresentationWorkspace($activeWorkspaceId);
            } catch (\Throwable $e) {
                $isPresentationWorkspaceSession = false;
            }
            $layoutPerfMark('presentation_guard');
            try {
                $protectedDemoSession = (new \CRM\Services\DemoSessionScopeService())->activeSession($activeWorkspaceId);
                if ($protectedDemoSession !== null) {
                    $protectedDemoProfile = (new \CRM\Services\DemoWorkspaceService())->profileKey($activeWorkspaceId);
                    $isProtectedDemoSession = true;
                    $protectedDemoClientState = [
                        'session_uuid' => (string) ($protectedDemoSession['session_uuid'] ?? ''),
                        'expires_at' => (string) ($protectedDemoSession['expires_at'] ?? ''),
                        'poll_url' => apiUrl('demo_realtime/poll.php'),
                        'end_url' => apiUrl('demo_access/end.php'),
                        'experience_tick_url' => apiUrl('demo_experience/tick.php'),
                        'csrf_token' => \CRM\Security::getCsrfToken(),
                        'experience_enabled' => true,
                        'video_pause_enabled' => true,
                        'toast_spacing_ms' => 18000,
                        'demo_toasts_enabled' => false,
                        'is_protected_demo' => true,
                        'runtime_health_enabled' => true,
                        'allowed_demo_routes' => $protectedDemoAllowedRoutes,
                        'cue_director_enabled' => true,
                        'cue_tick_debounce_ms' => 500,
                        'auto_open_enabled' => true,
                        'auto_open_idle_ms' => 8000,
                        'cue_story_version' => $protectedDemoProfile === 'metrodrive' ? 'metrodrive-presentation-v1' : 'riverside-cue-v1',
                    ];
                }
            } catch (\Throwable $e) {
                $protectedDemoClientState = null;
                $isProtectedDemoSession = false;
            }
            $layoutPerfMark('demo_scope');
        }
        if ($isProtectedDemoSession && !in_array($sessionAutomationPage, $protectedDemoAllowedRoutes, true)) {
            header('Location: ' . publicUrl('dashboard.php?demo=protected'));
            exit;
        }
        if ($activeWorkspaceId > 0 && !$isProtectedDemoSession && !$isPresentationWorkspaceSession) {
            $workspaceBillingService = new \CRM\Services\SaaSBillingService();
            $workspaceBillingState = $workspaceBillingService->getWorkspaceSnapshot($activeWorkspaceId, $activeUser ?: null);
        }
        $layoutPerfMark('billing_snapshot');
        $platformImpersonationContext = (new \CRM\Services\PlatformImpersonationService())->context();
        $billingAllowedPages = [
            'billing_payment_required.php',
            'billing_choose_package.php',
            'billing_start_payment.php',
            'billing_callback.php',
            'guided_demo.php',
            'guided_demo_wrap.php',
            'workspaces.php',
            'workspace_admin.php',
            'workspace_provision.php',
            'owner_support.php',
            'owner_support_admin.php',
            'owner_help_expert.php',
            'owner_help_experts_admin.php',
            'workspace_delete.php',
            'workspace_delete_2fa.php',
            'logout.php',
        ];
        if (!$isProtectedDemoSession && !$isPresentationWorkspaceSession && !empty($workspaceBillingState['billing_blocked']) && !in_array($sessionAutomationPage, $billingAllowedPages, true)) {
            header('Location: ' . publicUrl('billing_payment_required.php'));
            exit;
        }
        $layoutPerfMark('billing_gate');
        if (!$isProtectedDemoSession && !$isPresentationWorkspaceSession && (new \CRM\Services\WorkspaceOnboardingService())->shouldGateWorkspace(
            $activeWorkspaceId,
            $activeUser,
            $sessionAutomationPage
        )) {
            header('Location: ' . publicUrl('onboarding.php'));
            exit;
        }
        $layoutPerfMark('onboarding_gate');
        if (!$isProtectedDemoSession && !$isPresentationWorkspaceSession) {
            $guidedDemoAccess = new \CRM\Services\GuidedDemoAccessService();
            $guidedDemoUnpaidFunnelActive = !$guidedDemoAccess->hasPaidOrExemptAccess(
                $activeWorkspaceId,
                $activeUser
            );
            $guidedDemoRedirect = $guidedDemoAccess->redirectForPage(
                $activeWorkspaceId,
                $activeUser,
                $sessionAutomationPage
            );
            if ($guidedDemoRedirect !== null) {
                header('Location: ' . $guidedDemoRedirect);
                exit;
            }
            $guidedDemoClientState = (new \CRM\Services\GuidedDemoSessionService())->state(
                $activeWorkspaceId,
                (int) ($activeUser['id'] ?? 0)
            );
        }
        $layoutPerfMark('guided_demo');
        if (!$isProtectedDemoSession && !$isPresentationWorkspaceSession && ($workspaceBillingState['ai_blocked_reason'] ?? null) === 'wallet_depleted') {
            $workspaceAiBillingState = $workspaceBillingState;
        }
        if (
            !$isProtectedDemoSession
            && !$isPresentationWorkspaceSession
            &&
            $sessionAutomationPage === 'dashboard.php'
            && empty($workspaceBillingState['billing_blocked'])
            && empty($workspaceAiBillingState)
            && !\CRM\Services\WorkspaceContext::isDefaultWorkspace($activeWorkspaceId, (array) ($workspaceBillingState['workspace'] ?? []))
        ) {
            $workspacePlanSummaryEntitlements = (array) ($workspaceBillingState['entitlements'] ?? []);
            $workspacePlanSummarySubscription = (array) ($workspaceBillingState['subscription'] ?? []);
            $workspacePlanSummaryName = trim((string) (($workspacePlanSummarySubscription['plan_name'] ?? '') ?: ($workspacePlanSummaryEntitlements['public_display_name'] ?? '')));
            if ($workspacePlanSummaryName !== '') {
                $workspacePlanSummary = [
                    'workspace_id' => $activeWorkspaceId,
                    'plan_name' => $workspacePlanSummaryName,
                    'available_credits' => (int) ($workspaceBillingState['available_credits'] ?? $workspaceBillingState['available_tokens'] ?? 0),
                    'seat_limit' => (int) ($workspacePlanSummaryEntitlements['seat_limit'] ?? 0),
                    'can_top_up' => !empty($workspacePlanSummaryEntitlements['can_top_up']),
                ];
            }
        }
        $layoutPerfMark('plan_summary');
    } catch (\Throwable $e) {
        $workspaceBillingState = null;
        $workspaceAiBillingState = null;
        $workspacePlanSummary = null;
    }
}
$layoutPerfMark('authenticated_boot');
$layoutPerfFlush();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo \CRM\Security::getCsrfToken(); ?>">
    <title><?php echo $pageTitle ?? htmlspecialchars(brandProductName()); ?></title>
    <link rel="icon" type="image/png" sizes="64x64" href="<?php echo htmlspecialchars($faviconUrl); ?>">
    <link rel="shortcut icon" type="image/png" href="<?php echo htmlspecialchars($faviconUrl); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars($mainCssUrl); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars($assetBase . '/css/cookie-consent.css'); ?>">
    <link rel="stylesheet" href="<?php echo htmlspecialchars($chatBubbleCssUrl); ?>">
    <?php if (!empty($guidedDemoClientState) || !empty($guidedDemoPageStyles)): ?>
        <link rel="stylesheet" href="<?php echo htmlspecialchars($assetBase . '/css/guided-demo.css?v=' . urlencode(APP_VERSION . '-package-premium-modal')); ?>">
    <?php endif; ?>
    <?php if (class_exists(\CRM\Services\MarketingUi::class) && \CRM\Services\MarketingUi::isRefinementPage($layoutCurrentPage)): ?>
        <?php echo \CRM\Services\MarketingUi::stylesheetTag(); ?>
    <?php endif; ?>
    <script>
        (function () {
            function loadDeferredStylesheet(href, marker) {
                if (document.querySelector('link[data-deferred-style="' + marker + '"]')) {
                    return;
                }
                var link = document.createElement('link');
                link.rel = 'stylesheet';
                link.href = href;
                link.dataset.deferredStyle = marker;
                document.head.appendChild(link);
            }
            function loadEnhancementStyles() {
                loadDeferredStylesheet('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap', 'inter');
                loadDeferredStylesheet('https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css', 'font-awesome');
            }
            if (document.readyState === 'complete') {
                window.setTimeout(loadEnhancementStyles, 0);
            } else {
                window.addEventListener('load', loadEnhancementStyles, { once: true });
            }
        }());
    </script>
    <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"></noscript>
    <?php if ($layoutNeedsQuill && !$layoutContentIncludesQuillCss): ?>
        <link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
    <?php endif; ?>
    <style>
    /* Header Reorganization - Neumorphic Design */
    .workspace-system-notice-banner,
    #workspace-ai-wallet-banner {
        position: sticky;
        top: 0;
        z-index: 11000;
        padding: 0.45rem 0.75rem 0;
        pointer-events: none;
        transition: opacity 0.25s ease, transform 0.25s ease, max-height 0.25s ease, padding 0.25s ease;
    }

    .workspace-system-notice-banner.is-hidden,
    #workspace-ai-wallet-banner.is-hidden {
        max-height: 0;
        opacity: 0;
        overflow: hidden;
        padding-top: 0;
        transform: translateY(-8px);
    }

    .workspace-system-notice,
    .workspace-ai-wallet-notice {
        box-sizing: border-box;
        width: min(760px, calc(100vw - 1.5rem));
        margin: 0 auto;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
        padding: 0.45rem 0.5rem 0.45rem 0.7rem;
        border: 1px solid rgba(245, 158, 11, 0.18);
        border-radius: 12px;
        background: rgba(255, 251, 235, 0.96);
        box-shadow: 0 12px 34px rgba(15, 23, 42, 0.08);
        color: #78350f;
        font-size: 0.82rem;
        line-height: 1.35;
        pointer-events: auto;
        backdrop-filter: blur(10px);
    }

    .protected-demo-banner {
        position: fixed;
        top: calc(var(--nav-height, 64px) + 12px);
        right: 16px;
        z-index: 1040;
        display: flex;
        align-items: center;
        justify-content: space-between;
        box-sizing: border-box;
        width: min(320px, calc(100vw - 32px));
        gap: 0.7rem;
        padding: 0.55rem 0.6rem 0.55rem 0.75rem;
        border: 1px solid rgba(148, 163, 184, 0.22);
        border-radius: 12px;
        background: rgba(15, 23, 42, 0.94);
        color: #f8fafc;
        box-shadow: 0 14px 36px rgba(15, 23, 42, 0.18);
        font-size: 0.78rem;
        line-height: 1.25;
        backdrop-filter: blur(12px);
    }

    .protected-demo-banner > div {
        min-width: 0;
    }

    .protected-demo-banner strong {
        display: block;
        margin: 0 0 0.08rem;
        color: #93c5fd;
        font-size: 0.76rem;
        line-height: 1.1;
    }

    .protected-demo-banner form {
        margin: 0;
        flex: 0 0 auto;
    }

    .protected-demo-banner button {
        border: 1px solid rgba(147, 197, 253, 0.45);
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.08);
        color: #f8fafc;
        cursor: pointer;
        font-weight: 700;
        padding: 0.32rem 0.58rem;
        font-size: 0.74rem;
        white-space: nowrap;
    }

    .protected-demo-banner button:hover {
        background: rgba(147, 197, 253, 0.16);
    }

    .search-panel:not(.active) {
        width: 0 !important;
        min-width: 0 !important;
        box-shadow: none !important;
    }

    .search-panel:not(.active) > * {
        display: none !important;
    }

    @media (max-width: 680px) {
        .protected-demo-banner {
            top: calc(var(--nav-height, 56px) + 8px);
            right: 12px;
            width: min(300px, calc(100vw - 24px));
        }
    }

    .workspace-system-notice {
        border-color: rgba(8, 145, 178, 0.2);
        background: rgba(236, 254, 255, 0.96);
        color: #155e75;
    }

    .workspace-system-notice.is-warning {
        border-color: rgba(249, 115, 22, 0.22);
        background: rgba(255, 247, 237, 0.97);
        color: #9a3412;
    }

    .workspace-system-notice-copy,
    .workspace-ai-wallet-copy,
    .workspace-system-notice-actions,
    .workspace-ai-wallet-actions {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        min-width: 0;
    }

    .workspace-system-notice-copy,
    .workspace-ai-wallet-copy {
        flex: 1 1 auto;
    }

    .workspace-system-notice-dot,
    .workspace-ai-wallet-dot {
        width: 0.5rem;
        height: 0.5rem;
        border-radius: 999px;
        background: #f59e0b;
        box-shadow: 0 0 0 4px rgba(245, 158, 11, 0.14);
        flex: 0 0 auto;
    }

    .workspace-system-notice-dot {
        background: #0891b2;
        box-shadow: 0 0 0 4px rgba(8, 145, 178, 0.13);
    }

    .workspace-system-notice.is-warning .workspace-system-notice-dot {
        background: #f97316;
        box-shadow: 0 0 0 4px rgba(249, 115, 22, 0.14);
    }

    .workspace-system-notice-actions,
    .workspace-ai-wallet-actions {
        flex: 0 0 auto;
    }

    .workspace-system-notice-actions a,
    .workspace-ai-wallet-actions a {
        color: #1d4ed8;
        font-weight: 700;
        text-decoration: none;
        white-space: nowrap;
    }

    .workspace-system-notice-actions a:hover,
    .workspace-ai-wallet-actions a:hover {
        text-decoration: underline;
    }

    .workspace-plan-summary-notice {
        width: min(980px, calc(100vw - 1.5rem));
        flex-wrap: wrap;
        border-color: rgba(15, 118, 110, 0.18);
        background: rgba(240, 253, 250, 0.97);
        color: #115e59;
    }

    .workspace-plan-summary-copy {
        flex-wrap: wrap;
        row-gap: 0.35rem;
    }

    .workspace-plan-summary-details {
        display: inline-flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 0.25rem 0.65rem;
    }

    .workspace-plan-summary-title {
        color: #0f172a;
    }

    .workspace-plan-summary-note,
    .workspace-plan-summary-detail {
        color: #334155;
        white-space: nowrap;
    }

    .workspace-plan-summary-detail strong {
        color: #0f172a;
    }

    #workspace-ai-wallet-dismiss {
        width: 1.75rem;
        height: 1.75rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border: 1px solid rgba(120, 53, 15, 0.12);
        border-radius: 8px;
        background: rgba(255, 255, 255, 0.76);
        color: #92400e;
        cursor: pointer;
    }

    .workspace-affordability-nav-button {
        appearance: none;
        position: relative;
        isolation: isolate;
        display: inline-grid;
        place-items: center;
        width: 2.5rem;
        height: 2.5rem;
        min-width: 2.5rem;
        min-height: 2.5rem;
        box-sizing: border-box;
        border: 1px solid rgba(16, 185, 129, 0.26);
        border-radius: 0.9rem;
        padding: 0;
        overflow: hidden;
        background:
            linear-gradient(145deg, rgba(255, 255, 255, 0.98), rgba(236, 253, 245, 0.94));
        color: #047857;
        cursor: pointer;
        flex: 0 0 auto;
        box-shadow:
            6px 6px 14px rgba(15, 23, 42, 0.08),
            -5px -5px 12px rgba(255, 255, 255, 0.86),
            inset 0 1px 0 rgba(255, 255, 255, 0.92);
        transition: color 0.18s ease, border-color 0.18s ease, background 0.18s ease, transform 0.18s ease, box-shadow 0.18s ease;
    }

    .workspace-affordability-nav-button::before {
        content: '';
        position: absolute;
        inset: 4px;
        z-index: 0;
        border-radius: 0.65rem;
        background: radial-gradient(circle at 35% 25%, rgba(16, 185, 129, 0.2), rgba(16, 185, 129, 0) 62%);
        opacity: 0.9;
        pointer-events: none;
        transition: opacity 0.18s ease, transform 0.18s ease;
    }

    .workspace-affordability-nav-button i {
        position: relative;
        z-index: 1;
        font-size: 1.05rem;
        line-height: 1;
    }

    .workspace-affordability-nav-button:hover,
    .workspace-affordability-nav-button:focus-visible {
        border-color: rgba(5, 150, 105, 0.42);
        background:
            linear-gradient(145deg, rgba(255, 255, 255, 1), rgba(209, 250, 229, 0.96));
        color: #065f46;
        outline: 2px solid rgba(16, 185, 129, 0.24);
        outline-offset: 3px;
        transform: translateY(-1px);
        box-shadow:
            8px 8px 18px rgba(15, 23, 42, 0.1),
            -6px -6px 14px rgba(255, 255, 255, 0.9),
            inset 0 1px 0 rgba(255, 255, 255, 0.95);
    }

    .workspace-affordability-nav-button:hover::before,
    .workspace-affordability-nav-button:focus-visible::before {
        opacity: 1;
        transform: scale(1.08);
    }

    .workspace-affordability-nav-button:active {
        transform: translateY(0);
        box-shadow:
            inset 3px 3px 7px rgba(15, 23, 42, 0.08),
            inset -3px -3px 7px rgba(255, 255, 255, 0.85);
    }

    .workspace-affordability-modal[hidden] {
        display: none !important;
    }

    .workspace-affordability-modal {
        position: fixed;
        inset: 0;
        z-index: 12050;
        display: grid;
        place-items: center;
        padding: 1rem;
        background: rgba(15, 23, 42, 0.56);
        backdrop-filter: blur(8px);
    }

    .workspace-affordability-dialog {
        width: min(540px, 100%);
        overflow: hidden;
        border: 1px solid #d8e2ef;
        border-radius: 10px 20px 10px 16px;
        background: #ffffff;
        box-shadow: 0 20px 48px rgba(15, 23, 42, 0.26);
        font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    }

    .workspace-affordability-head {
        display: flex;
        justify-content: space-between;
        gap: 1rem;
        align-items: flex-start;
        padding: 1.1rem 1.1rem 0.65rem;
    }

    .workspace-affordability-head h2 {
        margin: 0.18rem 0 0;
        color: #0f172a;
        font-size: 1rem;
        font-weight: 700;
        line-height: 1.25;
        letter-spacing: 0;
    }

    .workspace-affordability-eyebrow {
        margin: 0;
        color: #0891b2;
        font-size: 0.72rem;
        font-weight: 700;
        letter-spacing: 0.08em;
        text-transform: uppercase;
    }

    .workspace-affordability-close {
        width: 2.15rem;
        height: 2.15rem;
        border: 1px solid #cbd5e1;
        border-radius: 14px;
        background: #fff;
        color: #0f172a;
        cursor: pointer;
    }

    .workspace-affordability-body {
        display: grid;
        gap: 0.75rem;
        padding: 0 1.1rem 1.1rem;
    }

    .workspace-affordability-body p {
        margin: 0;
        color: #64748b;
        font-size: 0.82rem;
        font-weight: 400;
        line-height: 1.45;
    }

    .workspace-affordability-options {
        display: grid;
        gap: 0.7rem;
    }

    .workspace-affordability-option {
        display: flex;
        justify-content: space-between;
        gap: 0.75rem;
        align-items: center;
        box-sizing: border-box;
        width: 100%;
        border: 1px solid #d8e2ef;
        border-radius: 8px 18px 8px 14px;
        padding: 0.78rem 0.85rem;
        background: #ffffff;
        color: inherit;
        font: inherit;
        text-align: left;
        text-decoration: none;
        box-shadow: 0 1px 0 rgba(15, 23, 42, 0.03);
    }

    button.workspace-affordability-option {
        appearance: none;
        cursor: pointer;
    }

    .workspace-affordability-option:hover,
    .workspace-affordability-option:focus-visible {
        border-color: rgba(8, 145, 178, 0.42);
        background: #f8fafc;
        outline: none;
    }

    .workspace-affordability-option.is-inactive,
    .workspace-affordability-option.is-inactive:hover,
    .workspace-affordability-option.is-inactive:focus-visible {
        border-color: #e2e8f0;
        background: #f8fafc;
        box-shadow: none;
        color: #94a3b8;
        cursor: not-allowed;
        outline: none;
    }

    .workspace-affordability-option-copy {
        display: grid;
        gap: 0.32rem;
        min-width: 0;
    }

    .workspace-affordability-option strong {
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
        flex-wrap: wrap;
        color: #0f172a;
        font-size: 0.88rem;
        font-weight: 700;
        line-height: 1.3;
        letter-spacing: 0;
    }

    .workspace-affordability-option-copy > span {
        display: block;
        color: #64748b;
        font-size: 0.8rem;
        font-weight: 400;
        line-height: 1.4;
    }

    .workspace-affordability-badge {
        display: inline-flex;
        align-items: center;
        border-radius: 999px;
        padding: 0.16rem 0.45rem;
        background: #dbeafe;
        color: #1d4ed8;
        font-size: 0.66rem;
        font-weight: 700;
        line-height: 1.2;
        letter-spacing: 0;
    }

    .workspace-affordability-option.is-inactive strong,
    .workspace-affordability-option.is-inactive .workspace-affordability-option-copy > span,
    .workspace-affordability-option.is-inactive i {
        color: #94a3b8;
    }

    .workspace-affordability-option.is-inactive .workspace-affordability-badge {
        background: #e2e8f0;
        color: #64748b;
    }

    .workspace-affordability-option i {
        color: #0891b2;
    }

    .workspace-affordability-help {
        display: flex;
        justify-content: space-between;
        gap: 0.75rem;
        flex-wrap: wrap;
        align-items: center;
        padding-top: 0.15rem;
        color: #64748b;
        font-size: 0.82rem;
        font-weight: 400;
        line-height: 1.45;
    }

    .workspace-affordability-help a {
        color: #0f766e;
        font-weight: 700;
        text-decoration: none;
    }

    .workspace-affordability-help a:hover,
    .workspace-affordability-help a:focus-visible,
    .workspace-affordability-donate-link:hover,
    .workspace-affordability-donate-link:focus-visible {
        text-decoration: underline;
    }

    .workspace-affordability-donate-link {
        appearance: none;
        border: 0;
        padding: 0;
        background: transparent;
        color: #0f766e;
        cursor: pointer;
        font: inherit;
        font-weight: 700;
        text-decoration: none;
    }

    .workspace-affordability-footer-link {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
    }

    .workspace-donation-dialog {
        width: min(640px, 100%);
    }

    .workspace-donation-form {
        display: grid;
        gap: 0.95rem;
    }

    .workspace-donation-system-legal {
        margin: -0.25rem 0 0.85rem;
        color: #64748b;
        font-size: 0.82rem;
        line-height: 1.42;
    }

    .workspace-donation-system-legal strong {
        color: #334155;
        font-weight: 800;
    }

    .workspace-donation-fields {
        display: grid;
        grid-template-columns: minmax(120px, 1fr) minmax(110px, 0.75fr) minmax(120px, 0.85fr);
        gap: 0.75rem;
    }

    .workspace-donation-form label {
        display: grid;
        gap: 0.34rem;
        color: #475569;
        font-size: 0.76rem;
        font-weight: 800;
    }

    .workspace-donation-form label[hidden] {
        display: none;
    }

    .workspace-donation-form input,
    .workspace-donation-form select {
        appearance: none;
        box-sizing: border-box;
        width: 100%;
        min-height: 2.55rem;
        border: 1px solid #c9d7e6;
        border-radius: 10px;
        background: #f8fafc;
        color: #0f172a;
        font: inherit;
        font-size: 0.88rem;
        font-weight: 650;
        padding: 0.55rem 0.72rem;
        transition: border-color 0.18s ease, background 0.18s ease, box-shadow 0.18s ease;
    }

    .workspace-donation-form select {
        padding-right: 2.35rem;
        background-image:
            linear-gradient(45deg, transparent 50%, #475569 50%),
            linear-gradient(135deg, #475569 50%, transparent 50%);
        background-position:
            calc(100% - 1rem) 50%,
            calc(100% - 0.7rem) 50%;
        background-size: 0.32rem 0.32rem, 0.32rem 0.32rem;
        background-repeat: no-repeat;
    }

    .workspace-donation-form input:focus,
    .workspace-donation-form select:focus {
        border-color: #0891b2;
        background: #fff;
        box-shadow: 0 0 0 3px rgba(8, 145, 178, 0.12);
        outline: none;
    }

    .workspace-donation-form input:disabled,
    .workspace-donation-form select:disabled {
        border-color: #dbe4ee;
        background-color: #eef4f8;
        color: #64748b;
        cursor: not-allowed;
        opacity: 1;
    }

    .workspace-donation-actions {
        display: flex;
        justify-content: flex-end;
        gap: 0.7rem;
        flex-wrap: wrap;
        align-items: center;
        margin-top: 0.25rem;
        padding-top: 0.85rem;
        border-top: 1px solid rgba(203, 213, 225, 0.72);
    }

    .workspace-donation-actions .btn-premium-primary,
    .workspace-donation-actions .btn-premium-secondary {
        appearance: none;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 0.48rem;
        min-height: 2.55rem;
        box-sizing: border-box;
        border-radius: 12px;
        padding: 0.68rem 1rem;
        font-family: Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        font-size: 0.85rem;
        font-weight: 800;
        line-height: 1;
        letter-spacing: 0;
        text-decoration: none;
        cursor: pointer;
        transition: border-color 0.18s ease, background 0.18s ease, color 0.18s ease, transform 0.18s ease, box-shadow 0.18s ease;
    }

    .workspace-donation-actions .btn-premium-primary i,
    .workspace-donation-actions .btn-premium-secondary i {
        font-size: 0.9rem;
        line-height: 1;
    }

    .workspace-donation-actions .btn-premium-primary {
        min-width: min(100%, 15.5rem);
        border: 1px solid rgba(8, 145, 178, 0.34);
        background:
            linear-gradient(135deg, #0891b2 0%, #0f766e 100%);
        color: #fff;
        box-shadow:
            0 12px 24px rgba(8, 145, 178, 0.22),
            inset 0 1px 0 rgba(255, 255, 255, 0.24);
    }

    .workspace-donation-actions .btn-premium-secondary {
        border: 1px solid #cbd5e1;
        background: #fff;
        color: #334155;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.06);
    }

    .workspace-donation-actions .btn-premium-primary:hover,
    .workspace-donation-actions .btn-premium-primary:focus-visible {
        border-color: rgba(15, 118, 110, 0.5);
        background:
            linear-gradient(135deg, #0e7490 0%, #0f766e 100%);
        color: #fff;
        outline: none;
        transform: translateY(-1px);
        box-shadow:
            0 16px 30px rgba(8, 145, 178, 0.26),
            0 0 0 3px rgba(8, 145, 178, 0.14),
            inset 0 1px 0 rgba(255, 255, 255, 0.28);
    }

    .workspace-donation-actions .btn-premium-secondary:hover,
    .workspace-donation-actions .btn-premium-secondary:focus-visible {
        border-color: rgba(8, 145, 178, 0.34);
        background: #f8fafc;
        color: #0f172a;
        outline: none;
        transform: translateY(-1px);
        box-shadow:
            0 10px 20px rgba(15, 23, 42, 0.08),
            0 0 0 3px rgba(8, 145, 178, 0.1);
    }

    .workspace-donation-actions .btn-premium-primary:disabled,
    .workspace-donation-actions .btn-premium-primary[aria-busy="true"] {
        cursor: wait;
        opacity: 0.76;
        transform: none;
        box-shadow:
            0 8px 18px rgba(8, 145, 178, 0.14),
            inset 0 1px 0 rgba(255, 255, 255, 0.2);
    }

    .workspace-donation-status {
        min-height: 1.1rem;
        color: #166534;
        font-size: 0.82rem;
        font-weight: 800;
        line-height: 1.35;
    }

    .workspace-donation-status.is-error {
        color: #991b1b;
    }

    @media (max-width: 560px) {
        .workspace-system-notice-banner,
        #workspace-ai-wallet-banner {
            padding: 0.35rem 0.5rem 0;
        }

        .workspace-system-notice,
        .workspace-ai-wallet-notice {
            width: 100%;
            align-items: flex-start;
            gap: 0.5rem;
            padding: 0.5rem;
        }

        .workspace-system-notice-copy,
        .workspace-ai-wallet-copy {
            align-items: flex-start;
        }

        .workspace-plan-summary-notice {
            flex-direction: column;
            align-items: stretch;
        }

        .workspace-plan-summary-note,
        .workspace-plan-summary-detail {
            white-space: normal;
        }

        .workspace-plan-summary-notice .workspace-system-notice-actions {
            justify-content: flex-start;
            flex-wrap: wrap;
        }

        .workspace-affordability-nav-button {
            width: 2.25rem;
            height: 2.25rem;
            min-width: 2.25rem;
            min-height: 2.25rem;
        }

        .workspace-donation-fields {
            grid-template-columns: 1fr;
        }

        .workspace-donation-actions .btn-premium-primary,
        .workspace-donation-actions .btn-premium-secondary {
            width: 100%;
            justify-content: center;
        }

        .workspace-donation-actions {
            flex-direction: column-reverse;
            align-items: stretch;
        }
    }
    
    /* Main Navigation Expandable Tabs (Horizontal) */
    .main-nav-container {
        display: flex;
        align-items: center;
        gap: clamp(0.28rem, 0.35vw, 0.45rem);
        padding: 0.45rem;
        border-radius: 1rem;
        background: #f0f0f3;
        box-shadow: 
            8px 8px 16px rgba(163, 177, 198, 0.6),
            -8px -8px 16px rgba(255, 255, 255, 0.5);
        border: 1px solid rgba(255, 255, 255, 0.18);
    }

    .main-nav-tab {
        position: relative;
        display: flex;
        align-items: center;
        gap: 0;
        padding: 0.5rem;
        border: none;
        background: transparent;
        border-radius: 0.75rem;
        color: var(--charcoal-grey, #666);
        font-size: 0.875rem;
        font-weight: 500;
        text-decoration: none;
        cursor: pointer;
        transition: all 0.6s cubic-bezier(0.4, 0, 0.2, 1);
        overflow: hidden;
        white-space: nowrap;
        min-width: 2.35rem;
        height: 2.35rem;
        justify-content: center;
    }

    .main-nav-tab i {
        font-size: 1rem;
        line-height: 1;
        flex-shrink: 0;
        transition: all 0.3s ease;
    }

    .main-nav-tab .tab-label {
        width: 0;
        opacity: 0;
        overflow: hidden;
        white-space: nowrap;
        transition: width 0.6s cubic-bezier(0.4, 0, 0.2, 1), 
                    opacity 0.6s cubic-bezier(0.4, 0, 0.2, 1) 0.1s,
                    margin-left 0.6s cubic-bezier(0.4, 0, 0.2, 1);
        margin-left: 0;
    }

    .main-nav-tab:hover {
        gap: 0.5rem;
        padding-left: 1rem;
        padding-right: 1rem;
        background: rgba(255, 255, 255, 0.7);
        box-shadow: 
            inset 4px 4px 8px rgba(163, 177, 198, 0.3),
            inset -4px -4px 8px rgba(255, 255, 255, 0.8);
        color: var(--accent-blue, #0066cc);
    }

    .main-nav-tab:hover .tab-label {
        width: auto;
        opacity: 1;
        margin-left: 0.25rem;
    }

    .main-nav-tab.active {
        gap: 0.5rem !important;
        padding-left: 1rem !important;
        padding-right: 1rem !important;
        background: rgba(255, 255, 255, 0.8);
        box-shadow: 
            inset 4px 4px 8px rgba(163, 177, 198, 0.3),
            inset -4px -4px 8px rgba(255, 255, 255, 0.8);
        color: var(--accent-blue, #0066cc);
        font-weight: 600;
    }

    .main-nav-tab.active .tab-label {
        width: auto !important;
        opacity: 1 !important;
        margin-left: 0.25rem !important;
    }

    .main-nav-separator {
        width: 1.2px;
        height: 24px;
        background: rgba(163, 177, 198, 0.4);
        margin: 0 0.25rem;
        flex-shrink: 0;
    }

    /* Enhanced Navbar Brand */
    .navbar-brand {
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        justify-content: center;
        font-size: 1.5rem !important;
        font-weight: 700 !important;
        padding: 0.5rem 0 !important;
        margin-right: 1rem !important;
        text-decoration: none;
        color: var(--midnight-black, #0f172a);
    }
    
    .navbar-brand .brand-logo {
        line-height: 1.2;
        display: inline-flex;
        align-items: center;
    }

    .navbar-brand .brand-logo-img {
        display: block;
        width: auto;
        height: 56px;
        max-width: 240px;
        object-fit: contain;
    }
    
    .navbar-brand .brand-name { display: none; }

    /* Enhanced Navbar */
    .navbar {
        padding: 1rem 0 !important;
        background: rgba(255, 255, 255, 0.98) !important;
        /* The app keeps the browser's default 8px body margin; offset only the navbar. */
        margin-left: -8px !important;
        margin-right: -8px !important;
        width: calc(100% + 16px);
    }

    .navbar .container {
        box-sizing: border-box;
        max-width: none;
        width: 100%;
        margin: 0;
        padding-left: 16px;
        padding-right: 16px;
    }

    .navbar-shell {
        display: grid !important;
        grid-template-columns:
            max-content
            minmax(0, 1fr);
        align-items: center;
        column-gap: clamp(0.5rem, 1.4vw, 1.5rem) !important;
        min-height: 44px;
        padding: 0.5rem 0 !important;
        position: relative;
    }

    .navbar-brand-slot {
        display: flex;
        align-items: center;
        gap: var(--spacing-md);
        grid-column: 1;
        justify-self: start;
        min-width: 0;
    }

    .desktop-nav {
        display: flex !important;
        align-items: center;
        flex: 0 1 auto;
        flex-wrap: nowrap !important;
        gap: clamp(0.5rem, 0.9vw, 1rem) !important;
        grid-column: 2;
        justify-content: space-between !important;
        justify-self: stretch;
        min-width: 0;
        width: 100%;
    }

    .desktop-nav .main-nav-container,
    .desktop-nav .expandable-tabs-container {
        flex: 0 0 auto;
        flex-wrap: nowrap !important;
    }

    .desktop-nav .expandable-tabs-container {
        margin-left: 0 !important;
    }

    /* Search Button in Neumorphic Style */
    .search-toggle-neu {
        display: flex;
        align-items: center;
        gap: 0;
        padding: 0.5rem;
        border: none;
        background: transparent;
        border-radius: 0.75rem;
        color: var(--charcoal-grey, #666);
        font-size: 0.875rem;
        font-weight: 500;
        cursor: pointer;
        transition: all 0.6s cubic-bezier(0.4, 0, 0.2, 1);
        min-width: 2.5rem;
        height: 2.5rem;
        justify-content: center;
    }

    .search-toggle-neu:hover {
        gap: 0.5rem;
        padding-left: 1rem;
        padding-right: 1rem;
        background: rgba(255, 255, 255, 0.7);
        box-shadow: 
            inset 4px 4px 8px rgba(163, 177, 198, 0.3),
            inset -4px -4px 8px rgba(255, 255, 255, 0.8);
        color: var(--accent-blue, #0066cc);
    }

    .search-toggle-neu .tab-label {
        width: 0;
        opacity: 0;
        overflow: hidden;
        white-space: nowrap;
        transition: width 0.6s cubic-bezier(0.4, 0, 0.2, 1), 
                    opacity 0.6s cubic-bezier(0.4, 0, 0.2, 1) 0.1s,
                    margin-left 0.6s cubic-bezier(0.4, 0, 0.2, 1);
        margin-left: 0;
    }

    .search-toggle-neu:hover .tab-label {
        width: auto;
        opacity: 1;
        margin-left: 0.25rem;
    }

    /* Dropdown Menu Neumorphic Styling */
    .nav-dropdown {
        position: relative;
    }

    .nav-dropdown-toggle {
        display: flex;
        align-items: center;
        gap: 0;
        padding: 0.5rem;
        border: none;
        background: transparent;
        border-radius: 0.75rem;
        color: var(--charcoal-grey, #666);
        font-size: 0.875rem;
        font-weight: 500;
        text-decoration: none;
        cursor: pointer;
        transition: all 0.6s cubic-bezier(0.4, 0, 0.2, 1);
        min-width: 2.5rem;
        height: 2.5rem;
        justify-content: center;
    }

    .nav-dropdown-toggle:hover {
        gap: 0.5rem;
        padding-left: 1rem;
        padding-right: 1rem;
        background: rgba(255, 255, 255, 0.7);
        box-shadow: 
            inset 4px 4px 8px rgba(163, 177, 198, 0.3),
            inset -4px -4px 8px rgba(255, 255, 255, 0.8);
        color: var(--accent-blue, #0066cc);
    }

    .nav-dropdown-toggle span {
        width: 0;
        opacity: 0;
        overflow: hidden;
        white-space: nowrap;
        transition: width 0.6s cubic-bezier(0.4, 0, 0.2, 1), 
                    opacity 0.6s cubic-bezier(0.4, 0, 0.2, 1) 0.1s,
                    margin-left 0.6s cubic-bezier(0.4, 0, 0.2, 1);
        margin-left: 0;
    }

    .nav-dropdown-toggle:hover span {
        width: auto;
        opacity: 1;
        margin-left: 0.25rem;
    }

    .nav-dropdown-menu {
        position: absolute;
        top: calc(100% + 0.5rem);
        left: 0;
        min-width: 200px;
        background: #f0f0f3;
        border-radius: 1rem;
        box-shadow: 
            8px 8px 16px rgba(163, 177, 198, 0.6),
            -8px -8px 16px rgba(255, 255, 255, 0.5),
            0 4px 12px rgba(0, 0, 0, 0.1);
        border: 1px solid rgba(255, 255, 255, 0.18);
        padding: 0.5rem;
        opacity: 0;
        visibility: hidden;
        transform: translateY(-10px);
        transition: all 0.3s ease;
        z-index: 1000;
        margin-top: 0.25rem;
    }
    
    /* Adjust dropdown positioning for user menu (right-aligned) */
    .expandable-tabs-container .nav-dropdown-menu {
        left: auto;
        right: 0;
    }

    .nav-dropdown.active .nav-dropdown-toggle {
        background: rgba(255, 255, 255, 0.8);
        box-shadow: 
            inset 4px 4px 8px rgba(163, 177, 198, 0.3),
            inset -4px -4px 8px rgba(255, 255, 255, 0.8);
        color: var(--accent-blue, #0066cc);
    }

    .nav-dropdown.active .nav-dropdown-menu {
        opacity: 1;
        visibility: visible;
        transform: translateY(0);
    }

    .nav-dropdown-item {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 0.75rem 1rem;
        color: var(--charcoal-grey, #666);
        text-decoration: none;
        border-radius: 0.5rem;
        transition: all 0.2s ease;
        font-size: 0.875rem;
    }

    .nav-dropdown-item:hover {
        background: rgba(255, 255, 255, 0.8);
        color: var(--accent-blue, #0066cc);
        box-shadow: 
            inset 2px 2px 4px rgba(163, 177, 198, 0.2),
            inset -2px -2px 4px rgba(255, 255, 255, 0.6);
    }

    .nav-dropdown-item i {
        width: 1.25rem;
        text-align: center;
        font-size: 0.875rem;
    }

    .nav-dropdown-divider {
        height: 1px;
        background: rgba(163, 177, 198, 0.3);
        margin: 0.5rem 0;
    }

    .nav-dropdown-menu.marketing-nav-menu {
        min-width: 560px;
        max-height: min(72vh, 720px);
        overflow-y: auto;
        display: grid;
        grid-template-columns: repeat(2, minmax(220px, 1fr));
        gap: 0.15rem 0.4rem;
    }

    .marketing-nav-menu .nav-dropdown-heading,
    .marketing-nav-menu .nav-dropdown-divider {
        grid-column: 1 / -1;
    }

    .marketing-nav-menu .nav-dropdown-heading {
        padding: 0.5rem 1rem 0.2rem;
        color: #64748b;
        font-size: 0.68rem;
        font-weight: 800;
        letter-spacing: 0.08em;
        text-transform: uppercase;
    }

    .marketing-nav-plugin-group {
        display: contents;
    }

    .marketing-nav-plugin-heading {
        align-items: center;
        display: flex;
        gap: 0.45rem;
    }

    .marketing-nav-plugin-heading i {
        color: #2563eb;
        font-size: 0.75rem;
    }

    .marketing-nav-menu .marketing-nav-setup-cta {
        background: rgba(239, 246, 255, 0.85);
        border: 1px solid #bfdbfe;
        color: #1d4ed8;
        font-weight: 800;
    }

    .marketing-nav-menu .nav-dropdown-item.manage-only {
        color: #8a3b12;
        background: rgba(254, 243, 199, 0.5);
    }

    .nav-dropdown-menu.marketing-plugin-nav-menu {
        display: block;
        min-width: 280px;
        max-width: 320px;
        max-height: min(68vh, 620px);
    }

    .marketing-plugin-nav-menu .nav-dropdown-divider {
        margin: 0.45rem 0.75rem;
    }

    .marketing-plugin-nav-menu .marketing-nav-advanced-entry {
        background: rgba(239, 246, 255, 0.72);
        color: #1d4ed8;
        font-weight: 750;
    }

    .marketing-nav-advanced {
        grid-column: 1 / -1;
        margin-top: 0.35rem;
        padding-top: 0.35rem;
        border-top: 1px solid rgba(163, 177, 198, 0.3);
    }

    .marketing-nav-advanced summary {
        display: flex;
        align-items: center;
        gap: 0.75rem;
        padding: 0.75rem 1rem;
        color: #334155;
        cursor: pointer;
        border-radius: 0.5rem;
        font-size: 0.875rem;
        font-weight: 800;
    }

    .marketing-nav-advanced summary::-webkit-details-marker {
        display: none;
    }

    .marketing-nav-advanced summary:hover {
        background: rgba(255, 255, 255, 0.8);
        color: var(--accent-blue, #0066cc);
    }

    .marketing-nav-advanced-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(220px, 1fr));
        gap: 0.15rem 0.4rem;
        padding-top: 0.25rem;
    }

    .marketing-nav-advanced-grid .nav-dropdown-heading {
        grid-column: 1 / -1;
    }

    .mobile-nav-section-heading,
    .mobile-nav-subheading {
        padding: var(--spacing-xs) var(--spacing-sm);
        color: var(--charcoal-grey);
        text-transform: uppercase;
    }

    .mobile-nav-section-heading {
        margin-top: var(--spacing-sm);
        font-size: 12px;
        font-weight: 600;
        letter-spacing: 0.5px;
    }

    .mobile-nav-subheading {
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.08em;
    }

    .mobile-marketing-advanced {
        margin: 0.25rem var(--spacing-sm);
        border-top: 1px solid rgba(163, 177, 198, 0.3);
        border-bottom: 1px solid rgba(163, 177, 198, 0.3);
        padding: 0.25rem 0;
    }

    .mobile-marketing-advanced summary {
        cursor: pointer;
        color: var(--charcoal-grey);
        font-size: 0.9rem;
        font-weight: 700;
        padding: 0.65rem 0;
    }

    @media (max-width: 760px) {
        .nav-dropdown-menu.marketing-nav-menu {
            min-width: min(92vw, 360px);
            grid-template-columns: 1fr;
        }

        .marketing-nav-advanced-grid {
            grid-template-columns: 1fr;
        }
    }

    /* Expandable Tabs - Neumorphic Design (User Menu - Horizontal) */
    .expandable-tabs-container {
        display: flex;
        flex-direction: row;
        align-items: center;
        gap: 0.25rem;
        padding: 0.5rem;
        border-radius: 1rem;
        background: #f0f0f3;
        box-shadow: 
            8px 8px 16px rgba(163, 177, 198, 0.6),
            -8px -8px 16px rgba(255, 255, 255, 0.5);
        border: 1px solid rgba(255, 255, 255, 0.18);
    }

    .expandable-tab {
        position: relative;
        display: flex;
        align-items: center;
        gap: 0;
        padding: 0.5rem;
        border: none;
        background: transparent;
        border-radius: 0.75rem;
        color: var(--charcoal-grey, #666);
        font-size: 0.875rem;
        font-weight: 500;
        text-decoration: none;
        cursor: pointer;
        transition: all 0.6s cubic-bezier(0.4, 0, 0.2, 1);
        overflow: visible;
        white-space: nowrap;
        min-width: 2.5rem;
        height: 2.5rem;
        justify-content: center;
    }

    .expandable-tab i {
        font-size: 1rem;
        line-height: 1;
        flex-shrink: 0;
        transition: all 0.3s ease;
    }

    .expandable-tab .tab-label {
        width: 0;
        opacity: 0;
        overflow: hidden;
        white-space: nowrap;
        transition: width 0.6s cubic-bezier(0.4, 0, 0.2, 1), 
                    opacity 0.6s cubic-bezier(0.4, 0, 0.2, 1) 0.1s,
                    margin-left 0.6s cubic-bezier(0.4, 0, 0.2, 1);
        margin-left: 0;
    }

    .expandable-tab:hover {
        gap: 0.5rem;
        padding-left: 1rem;
        padding-right: 1rem;
        background: rgba(255, 255, 255, 0.7);
        box-shadow: 
            inset 4px 4px 8px rgba(163, 177, 198, 0.3),
            inset -4px -4px 8px rgba(255, 255, 255, 0.8);
        color: var(--accent-blue, #0066cc);
    }

    .expandable-tab:hover .tab-label {
        width: auto;
        opacity: 1;
        margin-left: 0.25rem;
    }

    .expandable-tab.active {
        gap: 0.5rem !important;
        padding-left: 1rem !important;
        padding-right: 1rem !important;
        background: rgba(255, 255, 255, 0.8);
        box-shadow: 
            inset 4px 4px 8px rgba(163, 177, 198, 0.3),
            inset -4px -4px 8px rgba(255, 255, 255, 0.8);
        color: var(--accent-blue, #0066cc);
        font-weight: 600;
    }

    .expandable-tab.active .tab-label {
        width: auto !important;
        opacity: 1 !important;
        margin-left: 0.25rem !important;
    }

    .expandable-tab.selected {
        gap: 0.5rem !important;
        padding-left: 1rem !important;
        padding-right: 1rem !important;
    }

    .expandable-tab.selected .tab-label {
        width: auto !important;
        opacity: 1 !important;
        margin-left: 0.25rem !important;
    }

    .expandable-tab.active {
        color: var(--accent-blue, #0066cc);
        font-weight: 600;
    }

    .tab-separator {
        width: 1.2px;
        height: 24px;
        background: rgba(163, 177, 198, 0.4);
        margin: 0 0.25rem;
        flex-shrink: 0;
    }

    .tab-badge {
        position: absolute;
        top: 2px;
        right: 0;
        background: linear-gradient(135deg, #ff4d4f 0%, #d90429 100%);
        color: white;
        border-radius: 999px;
        padding: 2px 6px;
        font-size: 0.72rem;
        font-weight: 700;
        min-width: 20px;
        text-align: center;
        box-shadow: 0 2px 6px rgba(217, 4, 41, 0.45);
        border: 2px solid #ffffff;
        z-index: 10;
        line-height: 1.1;
        letter-spacing: 0.01em;
    }

    .tab-new-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        margin-left: 0.35rem;
        padding: 1px 5px;
        border-radius: 999px;
        background: #ecfdf5;
        color: #047857;
        border: 1px solid rgba(4, 120, 87, 0.18);
        font-size: 0.62rem;
        font-weight: 700;
        line-height: 1.25;
        letter-spacing: 0;
        vertical-align: middle;
    }

    .nav-new-badge {
        position: absolute;
        top: 2px;
        right: 0;
        margin-left: 0;
        border: 2px solid #ffffff;
        box-shadow: 0 2px 6px rgba(4, 120, 87, 0.28);
        z-index: 10;
    }

    .nav-vip-badge {
        position: absolute;
        top: 2px;
        right: 0;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 24px;
        padding: 1px 5px;
        border-radius: 999px;
        background: linear-gradient(135deg, #fef08a 0%, #f59e0b 100%);
        color: #4a2d00;
        border: 2px solid #ffffff;
        box-shadow: 0 2px 8px rgba(245, 158, 11, 0.36);
        font-size: 0.58rem;
        font-weight: 800;
        line-height: 1.25;
        letter-spacing: 0;
        z-index: 10;
    }

    .mobile-menu .tab-new-badge {
        margin-left: 0.5rem;
    }

    .mobile-menu .nav-new-badge {
        position: static;
        top: auto;
        right: auto;
        margin-left: 0.5rem;
        border-width: 1px;
        box-shadow: none;
    }

    .mobile-menu .nav-vip-badge {
        position: static;
        top: auto;
        right: auto;
        margin-left: 0.5rem;
        border-width: 1px;
        box-shadow: none;
        vertical-align: middle;
    }

    .main-nav-tab.active .tab-badge,
    .main-nav-tab:hover .tab-badge,
    .expandable-tab.active .tab-badge,
    .expandable-tab:hover .tab-badge {
        top: 2px;
        right: 0;
    }

    /* Responsive Styles */
    @media (min-width: 769px) and (max-width: 1024px) {
        .desktop-nav .main-nav-container {
            display: flex !important;
        }

        .mobile-menu-toggle {
            display: none !important;
        }
    }

    @media (max-width: 1024px) {
        .main-nav-container {
            gap: 0.25rem;
            padding: 0.4rem;
        }
        
        .main-nav-tab .tab-label,
        .nav-dropdown-toggle span,
        .search-toggle-neu .tab-label {
            display: none !important;
        }
        
        .main-nav-tab:hover,
        .nav-dropdown-toggle:hover,
        .search-toggle-neu:hover,
        .workspace-affordability-nav-button:hover {
            gap: 0;
            padding-left: 0.5rem;
            padding-right: 0.5rem;
        }
    }

    @media (max-width: 1440px) {
        .navbar-shell {
            column-gap: clamp(0.35rem, 1vw, 0.75rem) !important;
        }

        .navbar-brand {
            margin-right: 0.25rem !important;
            padding-left: 0 !important;
            padding-right: 0 !important;
        }

        .desktop-nav {
            min-width: 0;
            flex: 0 1 auto;
            justify-content: center !important;
            gap: clamp(0.35rem, 0.7vw, 0.5rem) !important;
        }

        .main-nav-container,
        .expandable-tabs-container {
            gap: 0.15rem;
            padding: 0.4rem;
        }

        .main-nav-tab,
        .nav-dropdown-toggle,
        .search-toggle-neu,
        .expandable-tab {
            min-width: 2.25rem;
            height: 2.25rem;
            padding: 0.4rem;
        }

        .workspace-affordability-nav-button {
            width: 2.25rem;
            height: 2.25rem;
            min-width: 2.25rem;
            min-height: 2.25rem;
        }

        .main-nav-separator,
        .tab-separator {
            margin-left: 0.1rem;
            margin-right: 0.1rem;
        }
    }

    /*
     * The full navigation strip needs roughly 1,880px once workspace utility
     * controls are present. Below that width, keep those controls visible and
     * move the primary destinations into the existing menu instead of clipping
     * the right side of the header.
     */
    @media (min-width: 769px) and (max-width: 1880px) {
        .navbar-shell {
            column-gap: clamp(0.5rem, 1vw, 0.9rem) !important;
        }

        .navbar-brand-slot {
            gap: clamp(0.5rem, 1vw, 0.75rem);
        }

        .mobile-menu-toggle {
            display: inline-flex !important;
            align-items: center;
            justify-content: center;
            min-width: 44px;
            min-height: 44px;
        }

        .desktop-nav {
            flex: 1 1 auto;
            justify-content: flex-end !important;
            justify-self: stretch;
            max-width: none !important;
            width: 100%;
        }

        .desktop-nav .main-nav-container {
            display: none !important;
        }

        .desktop-nav .expandable-tabs-container {
            flex: 0 0 auto;
            max-width: 100%;
        }

        .mobile-menu {
            display: none !important;
            position: absolute;
            top: calc(100% + 0.35rem);
            left: 0;
            right: 0;
            z-index: 99;
            max-height: 75vh;
            overflow-y: auto;
            padding: var(--spacing-md);
            border: var(--border-width) solid var(--border-color);
            border-radius: 16px;
            background: var(--white);
            box-shadow: var(--shadow-md);
        }

        .mobile-menu.active {
            display: block !important;
        }

        .mobile-menu .nav-link {
            display: flex;
            align-items: center;
            min-height: 44px;
            padding: 12px var(--spacing-sm);
            border-bottom: var(--border-width) solid var(--border-color);
            font-size: 0.9rem;
        }
    }

    @media (max-width: 768px) {
        .navbar-shell {
            display: flex !important;
            flex-direction: column;
            align-items: stretch;
            gap: 1rem !important;
        }

        .navbar-brand-slot {
            justify-content: flex-start;
        }

        .mobile-menu-toggle {
            display: inline-flex !important;
        }

        .desktop-nav {
            flex-wrap: wrap !important;
            grid-column: auto;
            gap: 0.5rem !important;
            justify-content: center !important;
            width: 100%;
        }

        .desktop-nav .expandable-tabs-container {
            flex: 1 1 100%;
            max-width: 100%;
        }

        .main-nav-container {
            width: 100%;
            justify-content: center;
            flex-wrap: wrap;
        }

        .expandable-tabs-container {
            gap: 0.25rem;
            padding: 0.4rem;
            flex-wrap: wrap;
            justify-content: center;
        }
        
        .expandable-tab {
            min-height: 2rem;
            padding: 0.5rem;
            justify-content: center;
            min-width: 2.5rem;
        }
        
        .expandable-tab .tab-label {
            display: none !important;
        }
        
        .expandable-tab.active,
        .expandable-tab:hover {
            gap: 0;
            padding-left: 0.5rem;
            padding-right: 0.5rem;
        }
        
        .tab-separator {
            width: 1.2px;
            height: 20px;
            margin: 0 0.15rem;
        }

        .main-nav-separator {
            height: 20px;
        }
    }
    </style>
</head>
<?php
if ($isProtectedDemoSession) { $layoutBodyClasses[] = 'protected-demo-active'; }
$layoutWorkspaceId = 0;
$layoutIsDefaultWorkspace = false;
try {
    $layoutWorkspaceId = (int) (\CRM\Services\WorkspaceContext::currentWorkspaceId() ?? 0);
    $layoutIsDefaultWorkspace = \CRM\Services\WorkspaceContext::isDefaultWorkspace($layoutWorkspaceId);
} catch (\Throwable $e) {
    $layoutWorkspaceId = 0;
    $layoutIsDefaultWorkspace = false;
}
$layoutBodyClasses[] = $layoutIsDefaultWorkspace ? 'workspace-mode-default' : 'workspace-mode-tenant';
?>
<body class="<?php echo htmlspecialchars(implode(' ', array_unique($layoutBodyClasses)), ENT_QUOTES, 'UTF-8'); ?>" data-workspace-kind="<?php echo $layoutIsDefaultWorkspace ? 'default' : 'tenant'; ?>" data-workspace-id="<?php echo $layoutWorkspaceId; ?>">
    <?php if (!empty($platformImpersonationContext)): ?>
    <div id="workspace-impersonation-banner" style="background:#eef2ff;border-bottom:1px solid rgba(79,70,229,.18);padding:.85rem 1rem;color:#312e81;font-size:.95rem;">
        <div class="container" style="display:flex;gap:1rem;align-items:center;justify-content:space-between;flex-wrap:wrap;">
            <div>
                Impersonating <strong><?php echo htmlspecialchars((string) ($platformImpersonationContext['impersonated_user_email'] ?? 'workspace member')); ?></strong>
                in <strong><?php echo htmlspecialchars((string) ($platformImpersonationContext['impersonated_workspace_name'] ?? 'workspace')); ?></strong>
                as <?php echo htmlspecialchars((string) ($platformImpersonationContext['impersonated_role_slug'] ?? 'viewer')); ?>.
                <?php if (!empty($platformImpersonationContext['reason'])): ?>
                    Reason: <?php echo htmlspecialchars((string) $platformImpersonationContext['reason']); ?>.
                <?php endif; ?>
            </div>
            <form method="POST" action="<?php echo htmlspecialchars(publicUrl('impersonation_exit.php')); ?>" style="display:inline-flex;gap:.5rem;align-items:center;">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(\CRM\Security::getCsrfToken()); ?>">
                <button type="submit" class="btn-premium-secondary">Exit Impersonation</button>
            </form>
        </div>
    </div>
    <?php endif; ?>
    <?php if (!empty($workspaceBillingState['billing_blocked'])): ?>
    <div id="workspace-billing-banner" class="workspace-system-notice-banner" role="status" aria-live="assertive">
        <div class="workspace-system-notice is-warning">
            <div class="workspace-system-notice-copy">
                <span class="workspace-system-notice-dot" aria-hidden="true"></span>
                <span>Workspace package access is <strong><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) ($workspaceBillingState['subscription_status'] ?? 'restricted')))); ?></strong>. Choose a package or retry payment to restore full workspace access.</span>
            </div>
            <div class="workspace-system-notice-actions">
                <a href="<?php echo htmlspecialchars(publicUrl('billing_payment_required.php')); ?>">Review Billing</a>
            </div>
        </div>
    </div>
    <?php elseif (!empty($workspaceAiBillingState)): ?>
    <div id="workspace-ai-wallet-banner" role="status" aria-live="polite">
        <div class="workspace-ai-wallet-notice">
            <div class="workspace-ai-wallet-copy">
                <span class="workspace-ai-wallet-dot" aria-hidden="true"></span>
                <span><strong>AI Credits depleted.</strong> CRM stays available; AI actions need a top-up.</span>
            </div>
            <div class="workspace-ai-wallet-actions">
                <a href="<?php echo htmlspecialchars(publicUrl('billing_payment_required.php?tab=tokens#ai-token-refill')); ?>">Top Up</a>
                <button type="button" id="workspace-ai-wallet-dismiss" aria-label="Dismiss AI Credit notice">
                    <i class="fas fa-times" aria-hidden="true"></i>
                </button>
            </div>
        </div>
    </div>
    <?php elseif (!empty($workspacePlanSummary)): ?>
    <div id="workspace-plan-summary-banner" class="workspace-system-notice-banner" role="status" aria-live="polite" data-workspace-id="<?php echo (int) ($workspacePlanSummary['workspace_id'] ?? 0); ?>">
        <div class="workspace-system-notice workspace-plan-summary-notice">
            <div class="workspace-system-notice-copy workspace-plan-summary-copy">
                <span class="workspace-system-notice-dot" aria-hidden="true"></span>
                <span class="workspace-plan-summary-details">
                    <strong class="workspace-plan-summary-title"><?php echo htmlspecialchars((string) ($workspacePlanSummary['plan_name'] ?? 'Workspace package')); ?></strong>
                    <span class="workspace-plan-summary-note">Plans unlock capability. AI Credits power usage. Keep onboarding nearby.</span>
                    <span class="workspace-plan-summary-detail">AI Credits <strong><?php echo number_format((int) ($workspacePlanSummary['available_credits'] ?? 0)); ?></strong></span>
                    <span class="workspace-plan-summary-detail">Seats <strong><?php echo (int) ($workspacePlanSummary['seat_limit'] ?? 0) > 0 ? number_format((int) ($workspacePlanSummary['seat_limit'] ?? 0)) : 'Unlimited'; ?></strong></span>
                    <span class="workspace-plan-summary-detail">Top-up <strong><?php echo !empty($workspacePlanSummary['can_top_up']) ? 'Available' : 'Upgrade'; ?></strong></span>
                </span>
            </div>
            <div class="workspace-system-notice-actions">
                <a href="<?php echo htmlspecialchars(publicUrl('billing_payment_required.php?tab=packages#workspace-packages')); ?>"><i class="fas fa-arrow-up-right-from-square" aria-hidden="true"></i> Upgrade</a>
                <a href="<?php echo htmlspecialchars(publicUrl('onboarding.php')); ?>"><i class="fa-solid fa-compass" aria-hidden="true"></i> Onboarding</a>
                <?php if (!empty($workspacePlanSummary['can_top_up'])): ?>
                    <a href="<?php echo htmlspecialchars(publicUrl('billing_payment_required.php?tab=tokens#ai-token-refill')); ?>"><i class="fas fa-coins" aria-hidden="true"></i> AI Credits</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php if (!empty($workspaceBillingState['billing_blocked'])): ?>
    <div id="workspace-billing-modal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.55);z-index:12000;align-items:center;justify-content:center;padding:1rem;">
        <div style="width:min(520px,100%);background:#fff;border-radius:18px;padding:1.5rem;box-shadow:0 25px 60px rgba(15,23,42,.25);">
            <h2 style="margin-top:0;color:#0f172a;">Workspace Payment Update Required</h2>
            <p style="color:#475569;line-height:1.6;">This workspace is currently <strong><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) ($workspaceBillingState['subscription_status'] ?? 'restricted')))); ?></strong>.</p>
            <p style="color:#475569;line-height:1.6;">Choose or retry a workspace package to restore full CRM access. Donations support affordability separately, and AI Credit top-ups only affect automation and AI features.</p>
            <div style="display:flex;gap:.75rem;justify-content:flex-end;flex-wrap:wrap;margin-top:1.25rem;">
                <button type="button" id="workspace-billing-dismiss" class="btn-premium-secondary">Later</button>
                <a href="<?php echo htmlspecialchars(publicUrl('billing_payment_required.php?tab=packages#workspace-packages')); ?>" class="btn-premium-primary">See Payment Options</a>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php if (\CRM\Auth::check() && !$isProtectedDemoSession && !$layoutIsDefaultWorkspace): ?>
    <?php
        $workspaceAffordabilityPackageUrl = publicUrl('billing_payment_required.php?tab=packages#workspace-packages');
        $workspaceAffordabilityTokenUrl = publicUrl('billing_payment_required.php?tab=tokens#ai-token-refill');
        $workspaceAffordabilityCanTopUp = !empty($workspaceBillingState['entitlements']['can_top_up']);
        $workspaceAffordabilityOptions = [
            'packages' => [
                'title' => 'Workspace packages',
                'description' => 'Manage CRM access.',
                'badge' => 'CRM access',
                'href' => $workspaceAffordabilityPackageUrl,
                'icon' => 'fas fa-credit-card',
                'active' => true,
            ],
            'tokens' => [
                'title' => 'Buy AI Credits',
                'description' => $workspaceAffordabilityCanTopUp ? 'Top up AI usage.' : 'Available on paid access.',
                'badge' => 'AI only',
                'href' => $workspaceAffordabilityCanTopUp ? $workspaceAffordabilityTokenUrl : null,
                'icon' => 'fas fa-bolt',
                'active' => $workspaceAffordabilityCanTopUp,
            ],
        ];
        if (!empty($workspaceBillingState['billing_blocked'])) {
            $workspaceAffordabilityOrder = ['packages', 'tokens'];
        } elseif (($workspaceAiBillingState['ai_blocked_reason'] ?? null) === 'wallet_depleted' && $workspaceAffordabilityCanTopUp) {
            $workspaceAffordabilityOrder = ['tokens', 'packages'];
        } else {
            $workspaceAffordabilityOrder = ['packages', 'tokens'];
        }
        $workspaceDonationsEnabled = \CRM\Modules\WorkspaceBillingSettings::donationsEnabled();
        $workspaceDonationSystemLegalName = '';
        if ($workspaceDonationsEnabled) {
            $workspaceDonationSystemLegalName = (new \CRM\Services\PlatformLegalIdentityService())->systemLegalName();
            try {
                $workspaceDonationBillingService = isset($workspaceBillingService) && $workspaceBillingService instanceof \CRM\Services\SaaSBillingService
                    ? $workspaceBillingService
                    : new \CRM\Services\SaaSBillingService();
                $workspaceDonationPaymentOptions = $workspaceDonationBillingService->donationPaymentOptions(['KES', 'USD', 'NGN'], true);
            } catch (\Throwable $e) {
                $workspaceDonationPaymentOptions = [
                    'currencies' => [],
                    'modes_by_currency' => [],
                    'default_currency' => '',
                    'has_available_methods' => false,
                ];
            }
        } else {
            $workspaceDonationPaymentOptions = [
                'currencies' => [],
                'modes_by_currency' => [],
                'default_currency' => '',
                'has_available_methods' => false,
            ];
        }
        $workspaceDonationCurrencies = (array) ($workspaceDonationPaymentOptions['currencies'] ?? []);
        $workspaceDonationModesByCurrency = (array) ($workspaceDonationPaymentOptions['modes_by_currency'] ?? []);
        $workspaceDonationDefaultCurrency = (string) ($workspaceDonationPaymentOptions['default_currency'] ?? '');
        $workspaceDonationInitialModes = $workspaceDonationDefaultCurrency !== '' ? (array) ($workspaceDonationModesByCurrency[$workspaceDonationDefaultCurrency] ?? []) : [];
        $workspaceDonationHasAvailableMethods = !empty($workspaceDonationPaymentOptions['has_available_methods']);
        $workspaceDonationModesJson = htmlspecialchars(
            json_encode($workspaceDonationModesByCurrency, JSON_UNESCAPED_SLASHES) ?: '{}',
            ENT_QUOTES,
            'UTF-8'
        );
    ?>
    <div id="workspace-affordability-modal" class="workspace-affordability-modal" hidden role="dialog" aria-modal="true" aria-labelledby="workspace-affordability-title">
        <div class="workspace-affordability-dialog">
            <div class="workspace-affordability-head">
                <div>
                    <p class="workspace-affordability-eyebrow">Workspace affordability</p>
                    <h2 id="workspace-affordability-title">Keep workspace running</h2>
                </div>
                <button type="button" class="workspace-affordability-close" data-workspace-affordability-close aria-label="Close payment options">
                    <i class="fas fa-times" aria-hidden="true"></i>
                </button>
            </div>
            <div class="workspace-affordability-body">
                <p><?php echo $workspaceDonationsEnabled ? 'Choose access, credits, or support.' : 'Choose access or credits.'; ?></p>
                <div class="workspace-affordability-options">
                    <?php foreach ($workspaceAffordabilityOrder as $workspaceAffordabilityKey): ?>
                        <?php $workspaceAffordabilityOption = $workspaceAffordabilityOptions[$workspaceAffordabilityKey]; ?>
                        <?php if (!empty($workspaceAffordabilityOption['active'])): ?>
                            <a class="workspace-affordability-option<?php echo $workspaceAffordabilityKey === 'tokens' ? ' is-token-option is-active' : ''; ?>" href="<?php echo htmlspecialchars((string) $workspaceAffordabilityOption['href']); ?>">
                                <span class="workspace-affordability-option-copy">
                                    <strong>
                                        <?php echo htmlspecialchars((string) $workspaceAffordabilityOption['title']); ?>
                                        <span class="workspace-affordability-badge"><?php echo htmlspecialchars((string) $workspaceAffordabilityOption['badge']); ?></span>
                                    </strong>
                                    <span><?php echo htmlspecialchars((string) $workspaceAffordabilityOption['description']); ?></span>
                                </span>
                                <i class="<?php echo htmlspecialchars((string) $workspaceAffordabilityOption['icon']); ?>" aria-hidden="true"></i>
                            </a>
                        <?php else: ?>
                            <div class="workspace-affordability-option is-token-option is-inactive" aria-disabled="true">
                                <span class="workspace-affordability-option-copy">
                                    <strong>
                                        <?php echo htmlspecialchars((string) $workspaceAffordabilityOption['title']); ?>
                                        <span class="workspace-affordability-badge"><?php echo htmlspecialchars((string) $workspaceAffordabilityOption['badge']); ?></span>
                                    </strong>
                                    <span><?php echo htmlspecialchars((string) $workspaceAffordabilityOption['description']); ?></span>
                                </span>
                                <i class="<?php echo htmlspecialchars((string) $workspaceAffordabilityOption['icon']); ?>" aria-hidden="true"></i>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <?php if ($workspaceDonationsEnabled): ?>
                <div class="workspace-affordability-help">
                    <span>Donations do not change package or credit balance.</span>
                    <button type="button" class="workspace-affordability-donate-link" data-workspace-donation-open>Donate</button>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php if ($workspaceDonationsEnabled): ?>
    <div id="workspace-donation-modal" class="workspace-affordability-modal workspace-donation-modal" hidden role="dialog" aria-modal="true" aria-labelledby="workspace-donation-title">
        <div class="workspace-affordability-dialog workspace-donation-dialog">
            <div class="workspace-affordability-head">
                <div>
                    <p class="workspace-affordability-eyebrow">Alternative funding</p>
                    <h2 id="workspace-donation-title">Support Startup AI Access</h2>
                </div>
                <button type="button" class="workspace-affordability-close" data-workspace-donation-close aria-label="Close donation checkout">
                    <i class="fas fa-times" aria-hidden="true"></i>
                </button>
            </div>
            <div class="workspace-affordability-body">
                <p>Your contribution helps small startups access AI tools without enterprise-sized costs.</p>
                <?php if ($workspaceDonationSystemLegalName !== ''): ?>
                    <p class="workspace-donation-system-legal">System legal name: <strong><?php echo htmlspecialchars($workspaceDonationSystemLegalName); ?></strong></p>
                <?php endif; ?>
                <form class="workspace-donation-form" data-workspace-donation-form action="<?php echo htmlspecialchars(apiUrl('donations/checkout.php')); ?>" method="post">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(\CRM\Security::getCsrfToken()); ?>">
                    <input type="hidden" name="return_to" value="<?php echo htmlspecialchars(publicUrl('billing_payment_required.php')); ?>">
                    <div class="workspace-donation-fields">
                        <label>
                            <span>Amount</span>
                            <input type="number" min="1" step="1" name="amount" placeholder="Enter amount" required>
                        </label>
                        <label>
                            <span>Currency</span>
                            <select name="currency" data-workspace-donation-currency <?php echo $workspaceDonationHasAvailableMethods ? '' : 'disabled'; ?>>
                                <?php if ($workspaceDonationCurrencies !== []): ?>
                                    <?php foreach ($workspaceDonationCurrencies as $currencyOption): ?>
                                        <?php $currencyValue = (string) ($currencyOption['value'] ?? ''); ?>
                                        <option value="<?php echo htmlspecialchars($currencyValue); ?>" <?php echo $currencyValue === $workspaceDonationDefaultCurrency ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars((string) ($currencyOption['label'] ?? $currencyValue)); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <option value="">No currencies available</option>
                                <?php endif; ?>
                            </select>
                        </label>
                        <label>
                            <span>Method</span>
                            <select name="payment_mode" data-workspace-donation-method data-workspace-donation-method-options="<?php echo $workspaceDonationModesJson; ?>" <?php echo $workspaceDonationHasAvailableMethods ? '' : 'disabled'; ?>>
                                <?php if ($workspaceDonationInitialModes !== []): ?>
                                    <?php foreach ($workspaceDonationInitialModes as $mode): ?>
                                        <option value="<?php echo htmlspecialchars((string) ($mode['key'] ?? '')); ?>" data-requires-phone="<?php echo !empty($mode['requires_phone']) ? '1' : '0'; ?>">
                                            <?php echo htmlspecialchars((string) ($mode['label'] ?? $mode['key'] ?? 'Payment method')); ?>
                                        </option>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <option value="">No methods available</option>
                                <?php endif; ?>
                            </select>
                        </label>
                        <label data-workspace-donation-phone-field hidden>
                            <span>M-Pesa phone</span>
                            <input type="tel" name="customer_phone" placeholder="254712345678" data-workspace-donation-phone disabled>
                        </label>
                    </div>
                    <span class="workspace-donation-status<?php echo $workspaceDonationHasAvailableMethods ? '' : ' is-error'; ?>" data-workspace-donation-status aria-live="polite"><?php echo $workspaceDonationHasAvailableMethods ? '' : 'Donation checkout is temporarily unavailable.'; ?></span>
                    <div class="workspace-donation-actions">
                        <button type="button" class="btn-premium-secondary" data-workspace-donation-close>
                            <i class="fas fa-times" aria-hidden="true"></i>
                            <span>Cancel</span>
                        </button>
                        <button class="btn-premium-primary" type="submit" data-workspace-donation-submit <?php echo $workspaceDonationHasAvailableMethods ? '' : 'disabled'; ?>>
                            <i class="fas fa-hand-holding-heart" aria-hidden="true"></i>
                            <span>Support Startup AI Access</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
    <nav class="navbar">
        <div class="container">
            <div class="navbar-shell">
                <div class="navbar-brand-slot">
                    <button class="mobile-menu-toggle" id="mobile-menu-toggle" aria-label="Open navigation menu" aria-expanded="false" style="display: none; background: none; border: none; font-size: 1.2rem; cursor: pointer; color: var(--midnight-black); padding: 5px 8px; border-radius: 4px; transition: all 0.2s ease;" onmouseover="this.style.background='rgba(0,102,204,0.08)'" onmouseout="this.style.background='transparent'">
                        <i class="fas fa-bars" aria-hidden="true"></i>
                    </button>
                    <a href="dashboard.php" class="navbar-brand">
                        <span class="brand-logo">
                            <img src="<?php echo htmlspecialchars($brandLogoUrl); ?>" srcset="<?php echo htmlspecialchars($brandLogoSrcset); ?>" width="84" height="56" alt="<?php echo htmlspecialchars(brandProductName()); ?> logo" class="brand-logo-img" decoding="async" fetchpriority="high">
                        </span>
                    </a>
                </div>
                <div class="desktop-nav">
                    <!-- Main Navigation Container with Expandable Tabs -->
                    <div class="main-nav-container">
                        <!-- Search Toggle Button -->
                        <button id="search-toggle-btn" class="search-toggle-neu" title="Search Workspace" aria-label="Search workspace" onclick="toggleSearchPanel(); return false;">
                            <i class="fas fa-search" aria-hidden="true"></i>
                            <span class="tab-label">Search</span>
                        </button>
                        <div class="main-nav-separator"></div>
                        
                        <?php
                        // Determine current page for active tab highlighting
                        $currentPage = basename($_SERVER['PHP_SELF'] ?? 'dashboard.php');
                        $isDashboard = ($currentPage === 'dashboard.php');
                        $isContacts = ($currentPage === 'contacts.php');
                        $isNurture = in_array($currentPage, ['nurture.php', 'nurture_view.php'], true);
                        $isOrganizationIntelligence = in_array($currentPage, ['hr_analytics.php', 'organization_intelligence_setup.php'], true);
                        $isMarketing = class_exists(\CRM\Services\MarketingUi::class)
                            ? \CRM\Services\MarketingUi::isNavigationPage($currentPage)
                            : in_array($currentPage, ['marketing.php'], true);
                        $isDeals = ($currentPage === 'deals.php');
                        $isFinance = ($currentPage === 'finance.php');
                        $isStartupJourney = ($currentPage === 'startup_journey.php');
                        $isFounderLoop = ($currentPage === 'founder_operating_loop.php');
                        $isInbox = ($currentPage === 'inbox.php');
                        $isTasks = in_array($currentPage, ['tasks.php', 'task_create.php', 'task_edit.php', 'task_view.php'], true);
                        $isTargets = in_array($currentPage, ['targets.php', 'target_create.php', 'target_view.php', 'target_edit.php', 'target_dashboard.php']);
                        $isSalesActive = $isDeals || $isTargets;
                        $isCalendarMeetingsPluginPage = $currentPage === 'workspace_skills.php'
                            && (string) ($_GET['module'] ?? '') === \CRM\Services\WorkspaceSkillCatalogService::PLUGIN_CALENDAR_MEETINGS;
                        $isCalendarMeetingsActive = in_array($currentPage, ['calendar.php', 'meeting_bookings.php'], true)
                            || $isCalendarMeetingsPluginPage;
                        $isMarketplace = ($currentPage === 'workspace_skills.php');
                        $currentUserForNav = \CRM\Auth::user() ?: [];
                        $canViewCustomerServiceNav = \CRM\Authorization::can('nurture.read', $currentUserForNav);
                        $canMarketingRead = \CRM\Authorization::can('marketing.read', $currentUserForNav);
                        $canMarketingWrite = \CRM\Authorization::can('marketing.write', $currentUserForNav);
                        $canMarketingManage = \CRM\Authorization::can('marketing.manage', $currentUserForNav);
                        $canCampaignsManage = \CRM\Authorization::can('campaigns.manage', $currentUserForNav);
                        $canViewMarketplaceNav = \CRM\Authorization::isSuperAdmin($currentUserForNav)
                            || \CRM\Authorization::can('workspace.skills.view', $currentUserForNav)
                            || \CRM\Authorization::can('workspace.skills.manage', $currentUserForNav);
                        $canViewFounderLoopPermission = \CRM\Authorization::isSuperAdmin($currentUserForNav)
                            || \CRM\Authorization::can('founder_loop.view', $currentUserForNav);
                        $workspaceModuleVisible = static function (string $skillKey): bool {
                            try {
                                $workspaceId = (int) (\CRM\Services\WorkspaceContext::currentWorkspaceId() ?? 0);
                                return $workspaceId > 0 && (new \CRM\Services\WorkspaceSkillInstallService())->canExposeRuntimeModule($workspaceId, $skillKey);
                            } catch (\Throwable $e) {
                                return false;
                            }
                        };
                        $workspaceModuleInstalled = static function (string $skillKey): bool {
                            try {
                                $workspaceId = (int) (\CRM\Services\WorkspaceContext::currentWorkspaceId() ?? 0);
                                if ($workspaceId <= 0) {
                                    return false;
                                }
                                $catalog = new \CRM\Services\WorkspaceSkillCatalogService();
                                return !$catalog->isGloballyDeactivated($skillKey)
                                    && (new \CRM\Services\WorkspaceSkillInstallService($catalog))->isInstalled($workspaceId, $skillKey);
                            } catch (\Throwable $e) {
                                return false;
                            }
                        };
                        $navWorkspaceId = 0;
                        try {
                            $navWorkspaceId = (int) (\CRM\Services\WorkspaceContext::currentWorkspaceId() ?? 0);
                        } catch (\Throwable $e) {
                            $navWorkspaceId = 0;
                        }
                        $uiExperience = new \CRM\Services\UIExperienceService();
                        $navExperienceMode = $uiExperience->modeForUser($currentUserForNav, $navWorkspaceId);
                        $navIsBeginner = $navExperienceMode === \CRM\Services\UIExperienceService::MODE_BEGINNER;
                        $marketingNavigationState = [];
                        $marketingMenuPluginInstalled = false;
                        $activeMarketingFeature = '';
                        if (class_exists(\CRM\Services\MarketingMarketplaceGateService::class)) {
                            try {
                                $marketingGate = new \CRM\Services\MarketingMarketplaceGateService();
                                $marketingNavigationState = $marketingGate->navigationStateForUser($currentUserForNav);
                                $marketingMenuPluginInstalled = $marketingGate->hasInstalledVisibleMenuPlugin($navWorkspaceId);
                                if ($isMarketing) {
                                    $activeMarketingFeature = $marketingGate->featureForPage($currentPage);
                                } elseif ($currentPage === 'workspace_skills.php') {
                                    $activeMarketingFeature = match ((string) ($_GET['module'] ?? '')) {
                                        \CRM\Services\WorkspaceSkillCatalogService::PLUGIN_DESIGN => \CRM\Services\MarketingMarketplaceGateService::FEATURE_DESIGN,
                                        \CRM\Services\WorkspaceSkillCatalogService::PLUGIN_SOCIAL_MEDIA => \CRM\Services\MarketingMarketplaceGateService::FEATURE_SOCIAL_MEDIA,
                                        \CRM\Services\WorkspaceSkillCatalogService::PLUGIN_MARKETING_PRO,
                                        \CRM\Services\WorkspaceSkillCatalogService::SKILL_PROFESSIONAL_MARKETER => \CRM\Services\MarketingMarketplaceGateService::FEATURE_MARKETING_PRO,
                                        default => '',
                                    };
                                }
                            } catch (\Throwable $e) {
                                $marketingNavigationState = [];
                                $marketingMenuPluginInstalled = false;
                                $activeMarketingFeature = '';
                            }
                        }
                        $showMarketingProductNav = $canMarketingRead && $marketingMenuPluginInstalled;
                        $showDesignNav = $showMarketingProductNav
                            && !empty($marketingNavigationState[\CRM\Services\MarketingMarketplaceGateService::FEATURE_DESIGN]['menu_visible']);
                        $showSocialMediaNav = $showMarketingProductNav
                            && !empty($marketingNavigationState[\CRM\Services\MarketingMarketplaceGateService::FEATURE_SOCIAL_MEDIA]['menu_visible']);
                        $showMarketingProNav = $showMarketingProductNav
                            && !empty($marketingNavigationState[\CRM\Services\MarketingMarketplaceGateService::FEATURE_MARKETING_PRO]['menu_visible']);
                        if ($activeMarketingFeature !== '') {
                            $isMarketplace = false;
                        }
                        $marketplaceNavLabel = $uiExperience->marketplaceLabel($navExperienceMode);
                        $startupJourneyInstalled = false;
                        $showStartupJourneyNewBadge = false;
                        $startupJourneyCompletedAt = null;
                        if ($navWorkspaceId > 0) {
                            try {
                                $startupJourneyCatalog = new \CRM\Services\WorkspaceSkillCatalogService();
                                $startupJourneyInstaller = new \CRM\Services\WorkspaceSkillInstallService($startupJourneyCatalog);
                                $startupJourneyAccess = (new \CRM\Services\WorkspaceMarketplaceAccessService($startupJourneyCatalog, $startupJourneyInstaller))->accessForModule(
                                    $navWorkspaceId,
                                    (int) ((\CRM\Auth::user() ?: [])['id'] ?? 0),
                                    \CRM\Services\WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS
                                );
                                $startupJourneyInstalled = $startupJourneyInstaller->isInstalled(
                                    $navWorkspaceId,
                                    \CRM\Services\WorkspaceSkillCatalogService::SKILL_LEAN_CANVAS
                                ) && !empty($startupJourneyAccess['can_configure']);
                            } catch (\Throwable $e) {
                                $startupJourneyInstalled = false;
                            }
                        }
                        if ($startupJourneyInstalled && $navWorkspaceId > 0) {
                            try {
                                $startupJourneyCompletedAt = (new \CRM\Services\StartupJourneyService())->setupCompletedAt(
                                    $navWorkspaceId,
                                    (int) ((\CRM\Auth::user() ?: [])['id'] ?? 0)
                                );
                                $completedTs = $startupJourneyCompletedAt !== null ? strtotime($startupJourneyCompletedAt) : false;
                                $showStartupJourneyNewBadge = $completedTs !== false && $completedTs >= strtotime('-3 days');
                            } catch (\Throwable $e) {
                                $startupJourneyCompletedAt = null;
                                $showStartupJourneyNewBadge = false;
                            }
                        }
                        $startupJourneyComplete = $startupJourneyCompletedAt !== null;
                        $canViewStartupJourneyNav = $canViewMarketplaceNav && $startupJourneyInstalled && !$startupJourneyComplete;
                        $canViewFounderLoopNav = $canViewFounderLoopPermission && $startupJourneyInstalled && $startupJourneyComplete;
                        $canUseSmsChannel = $workspaceModuleVisible(\CRM\Services\WorkspaceSkillCatalogService::PLUGIN_SMS_CHANNEL);
                        $canUseWhatsappAssistant = $workspaceModuleVisible(\CRM\Services\WorkspaceSkillCatalogService::PLUGIN_WHATSAPP_ASSISTANT);
                        $canUseCalendarMeetings = $workspaceModuleInstalled(\CRM\Services\WorkspaceSkillCatalogService::PLUGIN_CALENDAR_MEETINGS);
                        $isCalendarMeetingsActive = $canUseCalendarMeetings && $isCalendarMeetingsActive;
                        $isMarketplace = ($currentPage === 'workspace_skills.php')
                            && (!$canUseCalendarMeetings || !$isCalendarMeetingsPluginPage);
                        $canUseAiCoach = $workspaceModuleVisible(\CRM\Services\WorkspaceSkillCatalogService::SKILL_AI_COACH);
                        $canViewCalendarBookingsNav = \CRM\Authorization::can('meeting_bookings.view', $currentUserForNav)
                            || \CRM\Authorization::can('meeting_bookings.manage', $currentUserForNav);
                        $navBadgeIsRecent = static function (?string $timestamp): bool {
                            $timestamp = trim((string) $timestamp);
                            if ($timestamp === '') {
                                return false;
                            }

                            $parsed = strtotime($timestamp);
                            return $parsed !== false && $parsed >= strtotime('-3 days');
                        };
                        $renderNavNewBadge = static function (bool $show): string {
                            return $show ? '<span class="tab-new-badge nav-new-badge">New</span>' : '';
                        };
                        $renderNavVipBadge = static function (): string {
                            return '<span class="nav-vip-badge">VIP</span>';
                        };
                        $workspaceSkillInstalledAt = static function (int $workspaceId, string $skillKey): ?string {
                            if ($workspaceId <= 0 || !\CRM\Database::tableExists('workspace_skill_installs')) {
                                return null;
                            }

                            $row = \CRM\Database::queryOne(
                                "SELECT installed_at
                                 FROM workspace_skill_installs
                                 WHERE workspace_id = ?
                                   AND skill_key = ?
                                   AND status = 'installed'
                                   AND uninstalled_at IS NULL
                                 LIMIT 1",
                                [$workspaceId, $skillKey]
                            );

                            $installedAt = trim((string) ($row['installed_at'] ?? ''));
                            return $installedAt !== '' ? $installedAt : null;
                        };
                        $showCalendarMeetingsNewBadge = $canUseCalendarMeetings
                            && $navBadgeIsRecent($workspaceSkillInstalledAt($navWorkspaceId, \CRM\Services\WorkspaceSkillCatalogService::PLUGIN_CALENDAR_MEETINGS));
                        $financeNavVisibleAt = static function (int $workspaceId) use ($workspaceSkillInstalledAt): ?string {
                            if ($workspaceId <= 0) {
                                return null;
                            }

                            $setupTimestamps = [];
                            try {
                                if (\CRM\Database::tableExists('finance_transactions')) {
                                    $row = \CRM\Database::queryOne(
                                        "SELECT MAX(COALESCE(updated_at, created_at, transaction_date)) AS visible_at
                                         FROM finance_transactions
                                         WHERE workspace_id = ?
                                           AND transaction_type = 'opening_balance'",
                                        [$workspaceId]
                                    );
                                    $visibleAt = trim((string) ($row['visible_at'] ?? ''));
                                    if ($visibleAt !== '') {
                                        $setupTimestamps[] = $visibleAt;
                                    }
                                }
                                if (\CRM\Database::tableExists('finance_owner_equity_profiles')) {
                                    $row = \CRM\Database::queryOne(
                                        "SELECT MAX(COALESCE(updated_at, created_at)) AS visible_at
                                         FROM finance_owner_equity_profiles
                                         WHERE workspace_id = ?
                                           AND is_active = 1",
                                        [$workspaceId]
                                    );
                                    $visibleAt = trim((string) ($row['visible_at'] ?? ''));
                                    if ($visibleAt !== '') {
                                        $setupTimestamps[] = $visibleAt;
                                    }
                                }
                            } catch (\Throwable $e) {
                                $setupTimestamps = [];
                            }

                            if ($setupTimestamps !== []) {
                                usort($setupTimestamps, static function (string $left, string $right): int {
                                    return ((strtotime($right) ?: 0) <=> (strtotime($left) ?: 0));
                                });
                                return $setupTimestamps[0];
                            }

                            return $workspaceSkillInstalledAt($workspaceId, \CRM\Services\WorkspaceSkillCatalogService::PLUGIN_FINANCE);
                        };
                        $financeGateStatus = [];
                        try {
                            $financeGateStatus = (new \CRM\Services\WorkspaceFinanceGateService())->status(
                                (int) (\CRM\Services\WorkspaceContext::currentWorkspaceId() ?? 0),
                                \CRM\Auth::user()
                            );
                        } catch (\Throwable $e) {
                            $financeGateStatus = ['ready' => false];
                        }
                        $financeRuntimeReady = $workspaceModuleVisible(\CRM\Services\WorkspaceSkillCatalogService::PLUGIN_FINANCE);
                        $showFinanceNewBadge = $financeRuntimeReady && $navBadgeIsRecent($financeNavVisibleAt($navWorkspaceId));
                        $communicationGateStatus = [];
                        try {
                            $communicationGateStatus = (new \CRM\Services\WorkspaceCommunicationGateService())->status(
                                (int) (\CRM\Services\WorkspaceContext::currentWorkspaceId() ?? 0),
                                \CRM\Auth::user()
                            );
                        } catch (\Throwable $e) {
                            $communicationGateStatus = ['ready' => false, 'can_manage' => false];
                        }
                        $communicationRuntimeReady = !empty($communicationGateStatus['ready'])
                            || $workspaceModuleVisible(\CRM\Services\WorkspaceSkillCatalogService::PLUGIN_EMAIL)
                            || $workspaceModuleVisible(\CRM\Services\WorkspaceSkillCatalogService::PLUGIN_WHATSAPP);
                        $communicationSetupUrl = function_exists('publicUrl')
                            ? publicUrl((string) ($communicationGateStatus['setup_url'] ?? \CRM\Services\WorkspaceCommunicationGateService::SETUP_URL))
                            : (string) ($communicationGateStatus['setup_url'] ?? \CRM\Services\WorkspaceCommunicationGateService::SETUP_URL);
                        $hrAnalyticsGateStatus = [];
                        try {
                            $hrAnalyticsGateStatus = (new \CRM\Services\WorkspaceHRAnalyticsGateService())->status(
                                (int) (\CRM\Services\WorkspaceContext::currentWorkspaceId() ?? 0),
                                \CRM\Auth::user()
                            );
                        } catch (\Throwable $e) {
                            $hrAnalyticsGateStatus = ['ready' => false, 'can_manage' => false];
                        }
                        $hrAnalyticsRuntimeReady = $workspaceModuleVisible(\CRM\Services\WorkspaceSkillCatalogService::PLUGIN_HR_ANALYTICS_SETUP);
                        $hrAnalyticsNavUrl = $hrAnalyticsRuntimeReady
                            ? \CRM\Services\WorkspaceHRAnalyticsGateService::RUNTIME_URL
                            : (function_exists('publicUrl')
                                ? publicUrl((string) ($hrAnalyticsGateStatus['setup_url'] ?? \CRM\Services\WorkspaceHRAnalyticsGateService::SETUP_URL))
                                : (string) ($hrAnalyticsGateStatus['setup_url'] ?? \CRM\Services\WorkspaceHRAnalyticsGateService::SETUP_URL));
                        if ($isProtectedDemoSession) {
                            $canViewMarketplaceNav = true;
                            $marketplaceNavLabel = 'Plugins';
                            $startupJourneyInstalled = true;
                            $startupJourneyComplete = false;
                            $canViewStartupJourneyNav = true;
                            $canViewFounderLoopNav = false;
                            $financeRuntimeReady = false;
                            $showFinanceNewBadge = false;
                            $communicationRuntimeReady = true;
                            $communicationSetupUrl = 'inbox.php';
                            $canUseSmsChannel = false;
                            $canUseWhatsappAssistant = false;
                            $canUseCalendarMeetings = false;
                            $isCalendarMeetingsActive = false;
                            $isMarketplace = ($currentPage === 'workspace_skills.php');
                            $showCalendarMeetingsNewBadge = false;
                            $hrAnalyticsRuntimeReady = false;
                        }
                        $canViewOrganizationIntelligenceNav = !$isProtectedDemoSession
                            && \CRM\Authorization::can('hr.analytics.view', $currentUserForNav)
                            && ($hrAnalyticsRuntimeReady || !empty($hrAnalyticsGateStatus['can_manage']));
                        $navUserId = (int) ($currentUserForNav['id'] ?? 0);
                        $navContactsNewCount = 0;
                        $navInboxUnreadCount = 0;
                        $navNewTaskCount = 0;
                        $formatNavBadge = static fn(int $count): string => $count > 99 ? '99+' : (string) $count;

                        if ($navUserId > 0) {
                            $preferences = new \CRM\Modules\UserPreferences();
                            try {
                                $lastContactsOpenedAt = $preferences->getContactsLastOpenedAt($navUserId);
                                if ($lastContactsOpenedAt !== null) {
                                    $navContactsNewCount = (new \CRM\Modules\Contacts())->countCreatedSince($lastContactsOpenedAt, 'mine_unassigned', $navUserId);
                                }
                            } catch (\Throwable $e) {
                                $navContactsNewCount = 0;
                            }

                            try {
                                $inboxModule = new \CRM\Modules\UnifiedInbox();
                                $navInboxUnreadCount = $communicationRuntimeReady
                                    ? $inboxModule->getCount([
                                        'status' => 'unread',
                                        'viewer_user_id' => $navUserId,
                                        'can_view_all_conversations' => \CRM\Authorization::can('conversations.view_all', $currentUserForNav),
                                        'owner_scope' => \CRM\Modules\UnifiedInbox::OWNER_SCOPE_MINE_UNASSIGNED,
                                    ])
                                    : 0;
                            } catch (\Throwable $e) {
                                $navInboxUnreadCount = 0;
                            }

                            try {
                                $lastTasksOpenedAt = $preferences->getTasksLastOpenedAt($navUserId);
                                if ($currentPage === 'tasks.php') {
                                    $preferences->markTasksPageOpened($navUserId);
                                    $lastTasksOpenedAt = $preferences->getTasksLastOpenedAt($navUserId);
                                }
                                $navNewTaskCount = (new \CRM\Modules\Tasks())->getNewUnresolvedSinceCount($navUserId, $lastTasksOpenedAt);
                            } catch (\Throwable $e) {
                                $navNewTaskCount = 0;
                            }
                        }
                        ?>
                        
                        <!-- Primary Navigation Items -->
                        <a href="dashboard.php" class="main-nav-tab <?php echo $isDashboard ? 'active' : ''; ?>" data-tab="dashboard">
                            <i class="fas fa-home"></i>
                            <span class="tab-label">Dashboard</span>
                        </a>
                        <a href="contacts.php" class="main-nav-tab <?php echo $isContacts ? 'active' : ''; ?>" data-tab="contacts">
                            <i class="fas fa-users"></i>
                            <?php if ($navContactsNewCount > 0): ?>
                                <span class="tab-badge"><?php echo htmlspecialchars($formatNavBadge($navContactsNewCount)); ?></span>
                            <?php endif; ?>
                            <span class="tab-label">Contacts</span>
                        </a>
                        <?php if ($canViewCustomerServiceNav): ?>
                            <a href="nurture.php" class="main-nav-tab <?php echo $isNurture ? 'active' : ''; ?>" data-tab="customer-service">
                                <i class="fas fa-headset"></i>
                                <span class="tab-label">Customer Service</span>
                            </a>
                        <?php endif; ?>
                        <?php if ($communicationRuntimeReady): ?>
                            <a href="inbox.php" class="main-nav-tab <?php echo $isInbox ? 'active' : ''; ?>" data-tab="inbox">
                                <i class="fas fa-inbox"></i>
                                <?php if ($navInboxUnreadCount > 0): ?>
                                    <span class="tab-badge"><?php echo htmlspecialchars($formatNavBadge($navInboxUnreadCount)); ?></span>
                                <?php endif; ?>
                                <span class="tab-label">Inbox</span>
                            </a>
                        <?php endif; ?>
                        <a href="tasks.php" class="main-nav-tab <?php echo $isTasks ? 'active' : ''; ?>" data-tab="tasks">
                            <i class="fas fa-tasks"></i>
                            <?php if ($navNewTaskCount > 0): ?>
                                <span class="tab-badge"><?php echo htmlspecialchars($formatNavBadge($navNewTaskCount)); ?></span>
                            <?php endif; ?>
                            <span class="tab-label">Tasks</span>
                        </a>
                        <?php if ($canUseCalendarMeetings): ?>
                            <div class="nav-dropdown" id="calendar-meetings-dropdown">
                                <a href="#" class="nav-dropdown-toggle main-nav-tab <?php echo $isCalendarMeetingsActive ? 'active' : ''; ?>" onclick="event.preventDefault(); toggleDropdown('calendar-meetings-dropdown');">
                                    <i class="fas fa-calendar-check"></i>
                                    <?php echo $renderNavNewBadge($showCalendarMeetingsNewBadge); ?>
                                    <span class="tab-label">Calendar &amp; Meetings</span>
                                </a>
                                <div class="nav-dropdown-menu">
                                    <a href="calendar.php" class="nav-dropdown-item"><i class="fas fa-calendar-alt"></i>Calendar Board</a>
                                    <?php if ($canViewCalendarBookingsNav): ?><a href="meeting_bookings.php" class="nav-dropdown-item"><i class="fas fa-calendar-check"></i>Booking Queue</a><?php endif; ?>
                                    <a href="workspace_skills.php?module=calendar_meetings&amp;setup_tab=calendar#setup" class="nav-dropdown-item"><i class="fas fa-sliders-h"></i>Calendar &amp; Meetings Setup</a>
                                    <?php if ($canViewMarketplaceNav): ?><a href="workspace_skills.php?module=calendar_meetings" class="nav-dropdown-item"><i class="fas fa-store"></i>Plugin Overview</a><?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        <?php if ($canViewFounderLoopNav): ?>
                            <a href="founder_operating_loop.php" class="main-nav-tab <?php echo $isFounderLoop ? 'active' : ''; ?>" data-tab="founder-loop">
                                <i class="fas fa-compass"></i>
                                <?php echo $renderNavNewBadge($showStartupJourneyNewBadge); ?>
                                <span class="tab-label">Founder Loop</span>
                            </a>
                        <?php endif; ?>
                        <?php if ($canViewStartupJourneyNav): ?>
                            <a href="startup_journey.php" class="main-nav-tab <?php echo $isStartupJourney ? 'active' : ''; ?>" data-tab="startup-journey">
                                <i class="fas fa-route"></i>
                                <?php echo $renderNavNewBadge($showStartupJourneyNewBadge); ?>
                                <span class="tab-label">Clarity Journey</span>
                            </a>
                        <?php endif; ?>
                        <?php if ($financeRuntimeReady && \CRM\Authorization::can('finance.view', \CRM\Auth::user())): ?>
                            <a href="finance.php" class="main-nav-tab <?php echo $isFinance ? 'active' : ''; ?>" data-tab="finance">
                                <i class="fas fa-wallet"></i>
                                <?php echo $renderNavNewBadge($showFinanceNewBadge); ?>
                                <span class="tab-label">Finance</span>
                            </a>
                        <?php endif; ?>
                        <?php if ($canViewOrganizationIntelligenceNav): ?>
                            <a href="<?php echo htmlspecialchars($hrAnalyticsNavUrl); ?>" class="main-nav-tab <?php echo $isOrganizationIntelligence ? 'active' : ''; ?>" data-tab="organization-intelligence">
                                <i class="fas fa-user-tie"></i>
                                <?php echo $renderNavVipBadge(); ?>
                                <span class="tab-label">Organization Intelligence</span>
                            </a>
                        <?php endif; ?>
                        <?php if ($canViewMarketplaceNav): ?>
                            <a href="workspace_skills.php" class="main-nav-tab <?php echo $isMarketplace ? 'active' : ''; ?>" data-tab="marketplace">
                                <i class="fas fa-store"></i>
                                <span class="tab-label"><?php echo htmlspecialchars($marketplaceNavLabel); ?></span>
                            </a>
                        <?php endif; ?>
                        
                        <div class="main-nav-separator"></div>

                        <?php if ($showDesignNav): ?>
                            <div class="nav-dropdown" id="design-dropdown">
                                <a href="#" class="nav-dropdown-toggle main-nav-tab <?php echo $activeMarketingFeature === \CRM\Services\MarketingMarketplaceGateService::FEATURE_DESIGN ? 'active' : ''; ?>" onclick="event.preventDefault(); toggleDropdown('design-dropdown');">
                                    <i class="fas fa-pen-ruler"></i>
                                    <span class="tab-label">Design</span>
                                </a>
                                <div class="nav-dropdown-menu marketing-nav-menu marketing-plugin-nav-menu">
                                    <?php echo \CRM\Services\MarketingUi::renderDesktopPluginNavigation(
                                        \CRM\Services\MarketingMarketplaceGateService::FEATURE_DESIGN,
                                        $canMarketingWrite,
                                        $canMarketingManage,
                                        $canCampaignsManage,
                                        $canViewCustomerServiceNav,
                                        $navIsBeginner,
                                        $marketingNavigationState
                                    ); ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($showSocialMediaNav): ?>
                            <div class="nav-dropdown" id="social-media-dropdown">
                                <a href="#" class="nav-dropdown-toggle main-nav-tab <?php echo $activeMarketingFeature === \CRM\Services\MarketingMarketplaceGateService::FEATURE_SOCIAL_MEDIA ? 'active' : ''; ?>" onclick="event.preventDefault(); toggleDropdown('social-media-dropdown');">
                                    <i class="fas fa-share-nodes"></i>
                                    <span class="tab-label">Social Media</span>
                                </a>
                                <div class="nav-dropdown-menu marketing-nav-menu marketing-plugin-nav-menu">
                                    <?php echo \CRM\Services\MarketingUi::renderDesktopPluginNavigation(
                                        \CRM\Services\MarketingMarketplaceGateService::FEATURE_SOCIAL_MEDIA,
                                        $canMarketingWrite,
                                        $canMarketingManage,
                                        $canCampaignsManage,
                                        $canViewCustomerServiceNav,
                                        $navIsBeginner,
                                        $marketingNavigationState
                                    ); ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if ($showMarketingProNav): ?>
                            <div class="nav-dropdown" id="marketing-pro-dropdown">
                                <a href="#" class="nav-dropdown-toggle main-nav-tab <?php echo $activeMarketingFeature === \CRM\Services\MarketingMarketplaceGateService::FEATURE_MARKETING_PRO ? 'active' : ''; ?>" onclick="event.preventDefault(); toggleDropdown('marketing-pro-dropdown');">
                                    <i class="fas fa-bullhorn"></i>
                                    <span class="tab-label">Campaign Manager</span>
                                </a>
                                <div class="nav-dropdown-menu marketing-nav-menu marketing-plugin-nav-menu">
                                    <?php echo \CRM\Services\MarketingUi::renderDesktopPluginNavigation(
                                        \CRM\Services\MarketingMarketplaceGateService::FEATURE_MARKETING_PRO,
                                        $canMarketingWrite,
                                        $canMarketingManage,
                                        $canCampaignsManage,
                                        $canViewCustomerServiceNav,
                                        $navIsBeginner,
                                        $marketingNavigationState
                                    ); ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Sales Dropdown -->
                        <div class="nav-dropdown" id="sales-dropdown">
                            <a href="#" class="nav-dropdown-toggle main-nav-tab <?php echo $isSalesActive ? 'active' : ''; ?>" onclick="event.preventDefault(); toggleDropdown('sales-dropdown');">
                                <i class="fas fa-chart-line"></i>
                                <span>Sales</span>
                            </a>
                            <div class="nav-dropdown-menu">
                                <?php if ($isProtectedDemoSession): ?>
                                <a href="contacts.php" class="nav-dropdown-item"><i class="fas fa-users"></i>Contacts</a>
                                <a href="targets.php" class="nav-dropdown-item"><i class="fas fa-bullseye"></i>Targets</a>
                                <a href="tasks.php" class="nav-dropdown-item"><i class="fas fa-tasks"></i>Tasks</a>
                                <?php else: ?>
                                <a href="contacts.php" class="nav-dropdown-item"><i class="fas fa-users"></i>Contacts</a>
                                <a href="companies.php" class="nav-dropdown-item"><i class="fas fa-building"></i>Companies</a>
                                <a href="deals.php" class="nav-dropdown-item"><i class="fas fa-handshake"></i>Deals</a>
                                <?php if (\CRM\Authorization::can('feature.invoices', \CRM\Auth::user())): ?><a href="invoices.php" class="nav-dropdown-item"><i class="fas fa-file-invoice-dollar"></i>Invoices</a><?php endif; ?>
                                <a href="targets.php" class="nav-dropdown-item"><i class="fas fa-bullseye"></i>Targets</a>
                                <a href="tasks.php" class="nav-dropdown-item"><i class="fas fa-tasks"></i>Tasks</a>
                                <a href="activities.php" class="nav-dropdown-item"><i class="fas fa-history"></i>Activities</a>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Communication Dropdown -->
                        <?php if ($communicationRuntimeReady): ?>
                            <div class="nav-dropdown" id="communication-dropdown">
                                <a href="#" class="nav-dropdown-toggle main-nav-tab" onclick="event.preventDefault(); toggleDropdown('communication-dropdown');">
                                    <i class="fas fa-envelope"></i>
                                    <span>Communication</span>
                                </a>
                                <div class="nav-dropdown-menu">
                                    <?php if ($isProtectedDemoSession): ?>
                                    <a href="inbox.php" class="nav-dropdown-item"><i class="fas fa-inbox"></i>Inbox</a>
                                    <?php else: ?>
                                    <a href="emails.php" class="nav-dropdown-item"><i class="fas fa-envelope"></i>Emails</a>
                                    <a href="inbox.php" class="nav-dropdown-item"><i class="fas fa-inbox"></i>Inbox</a>
                                    <a href="email_templates.php" class="nav-dropdown-item"><i class="fas fa-file-alt"></i>Templates</a>
                                    <a href="email_signatures.php" class="nav-dropdown-item"><i class="fas fa-signature"></i>Signatures</a>
                                    <div class="nav-dropdown-divider"></div>
                                    <?php if ($canUseWhatsappAssistant): ?><a href="whatsapp_messages.php" class="nav-dropdown-item"><i class="fab fa-whatsapp"></i>WhatsApp Messages</a><?php endif; ?>
                                    <a href="bulk_email.php" class="nav-dropdown-item"><i class="fas fa-paper-plane"></i>Bulk Email</a>
                                    <?php if ($canUseSmsChannel): ?><a href="bulk_sms.php" class="nav-dropdown-item"><i class="fas fa-sms"></i>Bulk SMS</a><?php endif; ?>
                                    <?php if ($canUseWhatsappAssistant): ?><a href="bulk_whatsapp.php" class="nav-dropdown-item"><i class="fab fa-whatsapp"></i>Bulk WhatsApp</a><?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <!-- Analytics Dropdown -->
                        <?php if (!$isProtectedDemoSession): ?>
                        <div class="nav-dropdown" id="analytics-dropdown">
                            <a href="#" class="nav-dropdown-toggle main-nav-tab" onclick="event.preventDefault(); toggleDropdown('analytics-dropdown');">
                                <i class="fas fa-chart-bar"></i>
                                <span>Analytics</span>
                            </a>
                            <div class="nav-dropdown-menu">
                                <a href="dashboard.php" class="nav-dropdown-item"><i class="fas fa-home"></i>Dashboard</a>
                                <a href="analytics.php" class="nav-dropdown-item"><i class="fas fa-chart-line"></i>Analytics</a>
                                <a href="predictive_analytics.php" class="nav-dropdown-item"><i class="fas fa-brain"></i>Predictive Analytics</a>
                                <a href="reports.php" class="nav-dropdown-item"><i class="fas fa-file-chart-line"></i>Reports</a>
                                <a href="scheduled_reports.php" class="nav-dropdown-item"><i class="fas fa-clock"></i>Scheduled Reports</a>
                                <a href="attribution_reports.php" class="nav-dropdown-item"><i class="fas fa-project-diagram"></i>Attribution Reports</a>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- More Dropdown -->
                        <?php if (!$isProtectedDemoSession): ?>
                        <div class="nav-dropdown" id="more-dropdown">
                            <a href="#" class="nav-dropdown-toggle main-nav-tab" onclick="event.preventDefault(); toggleDropdown('more-dropdown');">
                                <i class="fas fa-ellipsis-h"></i>
                                <span>More</span>
                            </a>
                            <div class="nav-dropdown-menu">
                                <a href="tags.php" class="nav-dropdown-item"><i class="fas fa-tags"></i>Tags</a>
                                <a href="saved_searches.php" class="nav-dropdown-item"><i class="fas fa-bookmark"></i>Saved Searches</a>
                                <a href="webhooks.php" class="nav-dropdown-item"><i class="fas fa-plug"></i>Webhooks</a>
                                <a href="api_keys.php" class="nav-dropdown-item"><i class="fas fa-key"></i>API Keys</a>
                                <?php if (\CRM\Authorization::can('settings.monitoring', \CRM\Auth::user())): ?><a href="monitoring.php" class="nav-dropdown-item"><i class="fas fa-heartbeat"></i>Monitoring</a><?php endif; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Admin Dropdown -->
                        <?php if (!$isProtectedDemoSession && isset($_SESSION['user_id'])):
                            $currentUser = \CRM\Auth::user();
                            $canAdminUsers = \CRM\Authorization::canAccessUsersPage($currentUser);
                            $canAdminRoles = \CRM\Authorization::can('admin.roles.manage', $currentUser);
                            $canAdminDepartments = \CRM\Authorization::can('org.departments.manage', $currentUser);
                            $canLeadScoring = \CRM\Authorization::can('settings.scoring', $currentUser);
                            $canMlDashboard = \CRM\Authorization::can('feature.ml_scoring_dashboard', $currentUser);
                            $canWhatsappMigration = \CRM\Authorization::can('feature.whatsapp_migration', $currentUser);
                            $canPresentationConsole = \CRM\Authorization::canAny(['presentation.workspace.create', 'presentation.workspace.manage', 'presentation.audit'], $currentUser);
                            $canSettings = \CRM\Authorization::isSuperAdmin($currentUser) && \CRM\Authorization::canAny([
                                'settings.general','settings.email','settings.email_assistant','settings.whatsapp','settings.ai','settings.calendar','settings.sms','settings.ai_autoresponder','settings.enrichment','settings.scoring','settings.company','settings.monitoring','settings.deal_automation','settings.meeting_bot','settings.meeting_note_taker','settings.workflow_automation','settings.invoicing'
                            ], $currentUser);
                            if ($canAdminUsers || $canAdminRoles || $canAdminDepartments || $canLeadScoring || $canMlDashboard || $canWhatsappMigration || $canPresentationConsole || $canSettings): ?>
                                <div class="main-nav-separator"></div>
                                <div class="nav-dropdown" id="admin-dropdown">
                                    <a href="#" class="nav-dropdown-toggle main-nav-tab" onclick="event.preventDefault(); toggleDropdown('admin-dropdown');">
                                        <i class="fas fa-cog"></i>
                                        <span>Admin</span>
                                    </a>
                                    <div class="nav-dropdown-menu">
                                        <?php if ($canAdminUsers): ?><a href="users.php" class="nav-dropdown-item"><i class="fas fa-user-friends"></i>Users</a><?php endif; ?>
                                        <?php if ($canAdminDepartments): ?><a href="departments.php" class="nav-dropdown-item"><i class="fas fa-sitemap"></i>Departments</a><?php endif; ?>
                                        <?php if ($canAdminRoles): ?><a href="roles.php" class="nav-dropdown-item"><i class="fas fa-user-shield"></i>Access Profiles</a><?php endif; ?>
                                        <a href="custom_fields.php" class="nav-dropdown-item"><i class="fas fa-list"></i>Custom Fields</a>
                                        <a href="currencies.php" class="nav-dropdown-item"><i class="fas fa-dollar-sign"></i>Currencies</a>
                                        <?php if ($canLeadScoring): ?><a href="lead_scoring.php" class="nav-dropdown-item"><i class="fas fa-star"></i>Engagement Rules</a><?php endif; ?>
                                        <?php if ($canMlDashboard): ?><a href="ml_scoring_dashboard.php" class="nav-dropdown-item"><i class="fas fa-brain"></i>ML Models</a><?php endif; ?>
                                        <a href="workflows.php" class="nav-dropdown-item"><i class="fas fa-project-diagram"></i>Workflows</a>
                                        <a href="campaigns.php" class="nav-dropdown-item"><i class="fas fa-bullhorn"></i>Campaign Automation</a>
                                        <a href="document_categories.php" class="nav-dropdown-item"><i class="fas fa-folder"></i>Document Categories</a>
                                        <?php if ($canPresentationConsole): ?><a href="presentation_workspaces.php" class="nav-dropdown-item"><i class="fas fa-chalkboard-teacher"></i>Presentation Workspaces</a><?php endif; ?>
                                        <?php if ($canWhatsappMigration): ?><a href="whatsapp-migration.php" class="nav-dropdown-item"><i class="fas fa-exchange-alt"></i>WhatsApp Migration</a><?php endif; ?>
                                        <a href="audit_logs.php" class="nav-dropdown-item"><i class="fas fa-clipboard-list"></i>Audit Logs</a>
                                        <div class="nav-dropdown-divider"></div>
                                        <?php if ($canSettings): ?><a href="<?php echo htmlspecialchars($basePath . '/settings.php'); ?>" class="nav-dropdown-item"><i class="fas fa-cog"></i>Settings</a><?php endif; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                    <?php if (\CRM\Auth::check()): 
                        $currentUser = \CRM\Auth::user();
                        // Determine current page for active tab highlighting
                        $currentPage = basename((string) ($_SERVER['PHP_SELF'] ?? ''));
                        if ($currentPage === '') {
                            $currentPage = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
                        }
                        if ($currentPage === '') {
                            $currentPage = basename((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH));
                        }
                        if ($currentPage === '') {
                            $currentPage = 'dashboard.php';
                        }
                        $deferInitialNotificationCount = ($currentPage === 'workspace_skills.php') || $layoutWorkspace2faSetupPending;
                        if ($deferInitialNotificationCount) {
                            $newNotificationCount = 0;
                        } else {
                            try {
                                $notificationsModule = new \CRM\Modules\Notifications();
                                $userPreferences = new \CRM\Modules\UserPreferences();
                                $newNotificationCount = $notificationsModule->getNewSinceCount(
                                    (int) ($currentUser['id'] ?? 0),
                                    $userPreferences->getNotificationsLastOpenedAt((int) ($currentUser['id'] ?? 0))
                                );
                            } catch (\Throwable $e) {
                                $newNotificationCount = 0;
                            }
                        }
                        $isDashboard = ($currentPage === 'dashboard.php');
                        $isNotifications = ($currentPage === 'notifications.php');
                        $isSettings = ($currentPage === 'settings.php');
                        $isNotificationPrefs = ($currentPage === 'notification_preferences.php');
                        $isDocs = ($currentPage === 'docs.php');
                        $isOwnerSupport = in_array($currentPage, ['owner_support.php', 'owner_support_admin.php', 'owner_help_expert.php', 'owner_help_experts_admin.php'], true);
                        $isHelpActive = $isNotificationPrefs || $isDocs || $isOwnerSupport;
                        $navActiveWorkspaceRole = (string) (\CRM\Services\WorkspaceContext::currentRoleSlug() ?? '');
                        $canManageOwnerHelpExperts = \CRM\Authorization::isSuperAdmin($currentUser)
                            && \CRM\Services\WorkspaceContext::isDefaultWorkspace((int) (\CRM\Services\WorkspaceContext::currentWorkspaceId() ?? 0));
                        $canOwnerSettingsAllowlist = $navActiveWorkspaceRole === 'owner';
                        $canSettingsQuickAccess = $canOwnerSettingsAllowlist
                            || in_array($navActiveWorkspaceRole, ['admin'], true)
                            || \CRM\Authorization::isSuperAdmin($currentUser)
                            || \CRM\Authorization::canAny([
                                'settings.general','settings.email','settings.email_assistant','settings.whatsapp','settings.ai','settings.calendar','settings.sms','settings.ai_autoresponder','settings.enrichment','settings.scoring','settings.company','settings.monitoring','settings.deal_automation','settings.meeting_bot','settings.meeting_note_taker','settings.workflow_automation','settings.invoicing','settings.billing',
                                'billing.view','billing.edit','billing.manage',
                            ], $currentUser);
                    ?>
                        <div class="expandable-tabs-container" id="expandable-tabs-container" style="margin-left: var(--spacing-sm);">
                            <a href="notifications.php"
                               class="expandable-tab <?php echo $isNotifications ? 'active' : ''; ?>"
                               data-tab="notifications"
                               data-href="notifications.php"
                               title="<?php echo (int) $newNotificationCount; ?> new notifications since your last visit"
                               aria-label="<?php echo (int) $newNotificationCount; ?> new notifications since your last visit">
                                <i class="fas fa-bell"></i>
                                <?php if ($newNotificationCount > 0): ?>
                                    <span class="tab-badge"><?php echo $newNotificationCount > 99 ? '99+' : $newNotificationCount; ?></span>
                                <?php endif; ?>
                                <span class="tab-label">Notifications</span>
                            </a>
                            <?php if (!$isProtectedDemoSession && $canSettingsQuickAccess): ?>
                            <div class="tab-separator"></div>
                            <a href="<?php echo htmlspecialchars($basePath . '/settings.php'); ?>" class="expandable-tab <?php echo $isSettings ? 'active' : ''; ?>" data-tab="settings" data-href="<?php echo htmlspecialchars($basePath . '/settings.php'); ?>">
                                <i class="fas fa-cog"></i>
                                <span class="tab-label">Settings</span>
                            </a>
                            <?php endif; ?>
                            <!-- Help Dropdown -->
                            <div class="nav-dropdown" id="help-dropdown">
                                <a href="#" class="nav-dropdown-toggle expandable-tab <?php echo $isHelpActive ? 'active' : ''; ?>" onclick="event.preventDefault(); toggleDropdown('help-dropdown');" title="Help & Documentation">
                                    <i class="fas fa-question-circle"></i>
                                    <span class="tab-label">Help</span>
                                </a>
                                <div class="nav-dropdown-menu">
                                    <a href="owner_support.php" class="nav-dropdown-item"><i class="fas fa-life-ring"></i>Help Center</a>
                                    <?php if (!$isProtectedDemoSession && $canManageOwnerHelpExperts): ?>
                                        <a href="owner_help_experts_admin.php" class="nav-dropdown-item"><i class="fas fa-user-check"></i>Expert Profiles</a>
                                    <?php endif; ?>
                                    <a href="docs.php" class="nav-dropdown-item"><i class="fas fa-book"></i>Documentation</a>
                                    <?php if (!$isProtectedDemoSession): ?>
                                    <a href="email_assistant_capabilities.php" class="nav-dropdown-item"><i class="fas fa-envelope"></i>Email Assistant Capabilities</a>
                                    <div class="nav-dropdown-divider"></div>
                                    <a href="notification_preferences.php" class="nav-dropdown-item"><i class="fas fa-bell"></i>Notification Preferences</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <!-- Security Dropdown -->
                            <div class="nav-dropdown" id="security-dropdown">
                                <a href="#" class="nav-dropdown-toggle expandable-tab" onclick="event.preventDefault(); toggleDropdown('security-dropdown');" title="Security & Policies">
                                    <i class="fas fa-shield-alt"></i>
                                    <span class="tab-label">Security</span>
                                </a>
                                <div class="nav-dropdown-menu">
                                    <a href="privacy-policy.php" class="nav-dropdown-item"><i class="fas fa-user-shield"></i>Privacy Policy</a>
                                    <a href="cookie-policy.php" class="nav-dropdown-item"><i class="fas fa-cookie"></i>Cookie Policy</a>
                                    <a href="terms-of-service.php" class="nav-dropdown-item"><i class="fas fa-file-contract"></i>Terms of Service</a>
                                    <?php if (!$isProtectedDemoSession && $canSettingsQuickAccess): ?>
                                    <div class="nav-dropdown-divider"></div>
                                    <a href="<?php echo htmlspecialchars($basePath . '/settings.php'); ?>" class="nav-dropdown-item"><i class="fas fa-cog"></i>Security Settings</a>
                                    <?php endif; ?>
                                    <?php if (!$isProtectedDemoSession): ?>
                                    <a href="settings_2fa.php" class="nav-dropdown-item"><i class="fas fa-key"></i>Two-Factor Authentication</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="tab-separator"></div>
                            <a href="logout.php" class="expandable-tab" data-tab="logout" data-href="logout.php">
                                <i class="fas fa-sign-out-alt"></i>
                                <span class="tab-label">Logout</span>
                            </a>
                        </div>
                        <?php if (!$isProtectedDemoSession && !$isPresentationWorkspaceSession && !$layoutIsDefaultWorkspace): ?>
                        <button type="button" class="workspace-affordability-nav-button" data-workspace-affordability-open aria-haspopup="dialog" aria-controls="workspace-affordability-modal" aria-label="Open workspace payment options" title="Workspace payments">
                            <i class="fas fa-hand-holding-heart" aria-hidden="true"></i>
                        </button>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Mobile Menu -->
            <div class="mobile-menu" id="mobile-menu">
                <?php if ($isProtectedDemoSession): ?>
                <div style="padding: var(--spacing-xs) var(--spacing-sm); color: var(--charcoal-grey); font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Demo Workspace</div>
                <a href="dashboard.php" class="nav-link">Dashboard</a>
                <a href="inbox.php" class="nav-link">Inbox</a>
                <a href="contacts.php" class="nav-link">Contacts</a>
                <a href="tasks.php" class="nav-link">Tasks</a>
                <a href="targets.php" class="nav-link">Targets</a>
                <a href="workspace_skills.php" class="nav-link">Plugins</a>
                <a href="startup_journey.php" class="nav-link">Clarity Journey</a>
                <a href="notifications.php" class="nav-link">Notifications</a>
                <div style="padding: var(--spacing-sm); border-top: 1px solid var(--border-color); margin-top: var(--spacing-sm);">
                    <a href="owner_support.php" class="nav-link">Help Center</a>
                    <a href="docs.php" class="nav-link">Documentation</a>
                    <a href="privacy-policy.php" class="nav-link">Privacy Policy</a>
                    <a href="logout.php" class="nav-link">Logout</a>
                </div>
                <?php else: ?>
                <!-- Main Items -->
                <div style="padding: var(--spacing-xs) var(--spacing-sm); color: var(--charcoal-grey); font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px;">Main</div>
                <a href="dashboard.php" class="nav-link">Dashboard</a>
                <a href="contacts.php" class="nav-link">Contacts</a>
                <?php if ($canViewCustomerServiceNav ?? \CRM\Authorization::can('nurture.read', \CRM\Auth::user())): ?><a href="nurture.php" class="nav-link">Customer Service</a><?php endif; ?>
                <a href="companies.php" class="nav-link">Companies</a>
                <a href="deals.php" class="nav-link">Deals</a>
                <?php if ($canViewFounderLoopNav ?? false): ?><a href="founder_operating_loop.php" class="nav-link">Founder Loop<?php echo $renderNavNewBadge((bool) ($showStartupJourneyNewBadge ?? false)); ?></a><?php endif; ?>
                <?php if ($canViewStartupJourneyNav ?? false): ?><a href="startup_journey.php" class="nav-link">Clarity Journey<?php echo $renderNavNewBadge((bool) ($showStartupJourneyNewBadge ?? false)); ?></a><?php endif; ?>
                <?php if (($financeRuntimeReady ?? false) && \CRM\Authorization::can('finance.view', \CRM\Auth::user())): ?><a href="finance.php" class="nav-link">Finance<?php echo $renderNavNewBadge((bool) ($showFinanceNewBadge ?? false)); ?></a><?php endif; ?>
                <?php if ($canViewOrganizationIntelligenceNav ?? false): ?><a href="<?php echo htmlspecialchars($hrAnalyticsNavUrl ?? 'hr_analytics.php'); ?>" class="nav-link">Organization Intelligence<?php echo $renderNavVipBadge(); ?></a><?php endif; ?>
                <?php if ($canViewMarketplaceNav ?? false): ?><a href="workspace_skills.php" class="nav-link"><?php echo htmlspecialchars($marketplaceNavLabel ?? 'Marketplace'); ?></a><?php endif; ?>
                <?php if ($communicationRuntimeReady ?? false): ?><a href="inbox.php" class="nav-link">Inbox</a><?php endif; ?>
                <a href="targets.php" class="nav-link">Targets</a>

                <?php if ($canUseCalendarMeetings ?? false): ?>
                    <!-- Calendar & Meetings Section -->
                    <div style="padding: var(--spacing-xs) var(--spacing-sm); color: var(--charcoal-grey); font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-top: var(--spacing-sm);">Calendar &amp; Meetings<?php echo $renderNavNewBadge((bool) ($showCalendarMeetingsNewBadge ?? false)); ?></div>
                    <a href="calendar.php" class="nav-link">Calendar Board</a>
                    <?php if ($canViewCalendarBookingsNav ?? false): ?><a href="meeting_bookings.php" class="nav-link">Booking Queue</a><?php endif; ?>
                    <a href="workspace_skills.php?module=calendar_meetings&amp;setup_tab=calendar#setup" class="nav-link">Calendar &amp; Meetings Setup</a>
                    <?php if ($canViewMarketplaceNav ?? false): ?><a href="workspace_skills.php?module=calendar_meetings" class="nav-link">Plugin Overview</a><?php endif; ?>
                <?php endif; ?>

                <?php foreach ([
                    \CRM\Services\MarketingMarketplaceGateService::FEATURE_DESIGN => $showDesignNav ?? false,
                    \CRM\Services\MarketingMarketplaceGateService::FEATURE_SOCIAL_MEDIA => $showSocialMediaNav ?? false,
                    \CRM\Services\MarketingMarketplaceGateService::FEATURE_MARKETING_PRO => $showMarketingProNav ?? false,
                ] as $mobileMarketingFeature => $showMobileMarketingFeature): ?>
                    <?php if ($showMobileMarketingFeature): ?>
                        <?php echo \CRM\Services\MarketingUi::renderMobilePluginNavigation(
                            $mobileMarketingFeature,
                            $canMarketingWrite ?? false,
                            $canMarketingManage ?? false,
                            $canCampaignsManage ?? false,
                            $canViewCustomerServiceNav ?? \CRM\Authorization::can('nurture.read', $currentUserForNav ?? null),
                            $navIsBeginner ?? false,
                            $marketingNavigationState ?? []
                        ); ?>
                    <?php endif; ?>
                <?php endforeach; ?>
                
                <!-- Sales Section -->
                <div style="padding: var(--spacing-xs) var(--spacing-sm); color: var(--charcoal-grey); font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-top: var(--spacing-sm);">Sales</div>
                <a href="contacts.php" class="nav-link">Contacts</a>
                <a href="companies.php" class="nav-link">Companies</a>
                <a href="deals.php" class="nav-link">Deals</a>
                <?php if (\CRM\Authorization::can('feature.invoices', \CRM\Auth::user())): ?><a href="invoices.php" class="nav-link">Invoices</a><?php endif; ?>
                <a href="targets.php" class="nav-link">Targets</a>
                <a href="tasks.php" class="nav-link">Tasks</a>
                <a href="activities.php" class="nav-link">Activities</a>
                
                <?php if ($communicationRuntimeReady ?? false): ?>
                    <!-- Communication Section -->
                    <div style="padding: var(--spacing-xs) var(--spacing-sm); color: var(--charcoal-grey); font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-top: var(--spacing-sm);">Communication</div>
                    <a href="emails.php" class="nav-link">Emails</a>
                    <a href="inbox.php" class="nav-link">Inbox</a>
                    <a href="email_templates.php" class="nav-link">Email Templates</a>
                    <a href="email_signatures.php" class="nav-link">Email Signatures</a>
                    <?php if ($canUseWhatsappAssistant ?? false): ?><a href="whatsapp_messages.php" class="nav-link">WhatsApp Messages</a><?php endif; ?>
                    <a href="bulk_email.php" class="nav-link">Bulk Email</a>
                    <?php if ($canUseSmsChannel ?? false): ?><a href="bulk_sms.php" class="nav-link">Bulk SMS</a><?php endif; ?>
                    <?php if ($canUseWhatsappAssistant ?? false): ?><a href="bulk_whatsapp.php" class="nav-link">Bulk WhatsApp</a><?php endif; ?>
                <?php endif; ?>
                
                <!-- Analytics Section -->
                <div style="padding: var(--spacing-xs) var(--spacing-sm); color: var(--charcoal-grey); font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-top: var(--spacing-sm);">Analytics</div>
                <a href="dashboard.php" class="nav-link">Dashboard</a>
                <a href="analytics.php" class="nav-link">Analytics</a>
                <a href="predictive_analytics.php" class="nav-link">Predictive Analytics</a>
                <a href="reports.php" class="nav-link">Reports</a>
                <a href="scheduled_reports.php" class="nav-link">Scheduled Reports</a>
                <a href="attribution_reports.php" class="nav-link">Attribution Reports</a>
                
                <!-- More Section -->
                <div style="padding: var(--spacing-xs) var(--spacing-sm); color: var(--charcoal-grey); font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-top: var(--spacing-sm);">More</div>
                <a href="tags.php" class="nav-link">Tags</a>
                <a href="saved_searches.php" class="nav-link">Saved Searches</a>
                <a href="webhooks.php" class="nav-link">Webhooks</a>
                <a href="api_keys.php" class="nav-link">API Keys</a>
                <?php if (\CRM\Authorization::can('settings.monitoring', \CRM\Auth::user())): ?><a href="monitoring.php" class="nav-link">Monitoring</a><?php endif; ?>
                
                <!-- Admin Section -->
                <?php if (isset($_SESSION['user_id'])): 
                    $currentUser = \CRM\Auth::user();
                    $canAdminUsers = \CRM\Authorization::canAccessUsersPage($currentUser);
                    $canLeadScoring = \CRM\Authorization::can('settings.scoring', $currentUser);
                    $canMlDashboard = \CRM\Authorization::can('feature.ml_scoring_dashboard', $currentUser);
                    $canPresentationConsole = \CRM\Authorization::canAny(['presentation.workspace.create', 'presentation.workspace.manage', 'presentation.audit'], $currentUser);
                    if ($canAdminUsers || $canPresentationConsole || \CRM\Authorization::canAny(['admin.roles.manage','org.departments.manage','settings.scoring','feature.ml_scoring_dashboard','feature.whatsapp_migration'], $currentUser)): ?>
                        <div style="padding: var(--spacing-xs) var(--spacing-sm); color: var(--charcoal-grey); font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; margin-top: var(--spacing-sm);">Admin</div>
                        <?php if ($canAdminUsers): ?><a href="users.php" class="nav-link">Users</a><?php endif; ?>
                        <?php if (\CRM\Authorization::can('org.departments.manage', $currentUser)): ?><a href="departments.php" class="nav-link">Departments</a><?php endif; ?>
                        <?php if (\CRM\Authorization::can('admin.roles.manage', $currentUser)): ?><a href="roles.php" class="nav-link">Access Profiles</a><?php endif; ?>
                        <a href="custom_fields.php" class="nav-link">Custom Fields</a>
                        <a href="currencies.php" class="nav-link">Currencies</a>
                        <?php if ($canLeadScoring): ?><a href="lead_scoring.php" class="nav-link">Engagement Rules</a><?php endif; ?>
                        <?php if ($canMlDashboard): ?><a href="ml_scoring_dashboard.php" class="nav-link">ML Models</a><?php endif; ?>
                        <a href="workflows.php" class="nav-link">Workflows</a>
                        <a href="campaigns.php" class="nav-link">Campaign Automation</a>
                        <a href="document_categories.php" class="nav-link">Document Categories</a>
                        <?php if ($canPresentationConsole): ?><a href="presentation_workspaces.php" class="nav-link">Presentation Workspaces</a><?php endif; ?>
                        <?php if (\CRM\Authorization::can('feature.whatsapp_migration', $currentUser)): ?><a href="whatsapp-migration.php" class="nav-link">WhatsApp Migration</a><?php endif; ?>
                        <a href="audit_logs.php" class="nav-link">Audit Logs</a>
                        <?php if (\CRM\Authorization::canAny(['settings.general','settings.email','settings.email_assistant','settings.whatsapp','settings.ai','settings.calendar','settings.sms','settings.ai_autoresponder','settings.enrichment','settings.scoring','settings.company','settings.monitoring','settings.deal_automation','settings.meeting_bot','settings.meeting_note_taker','settings.workflow_automation'], $currentUser)): ?><a href="<?php echo htmlspecialchars($basePath . '/settings.php'); ?>" class="nav-link">Settings</a><?php endif; ?>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if (\CRM\Auth::check()): 
                    $currentUser = \CRM\Auth::user();
                ?>
                    <div style="padding: var(--spacing-sm); border-top: 1px solid var(--border-color); margin-top: var(--spacing-sm);">
                        <div style="color: var(--charcoal-grey); margin-bottom: var(--spacing-xs);"><?php echo htmlspecialchars($currentUser['email']); ?></div>
                        <?php if ($canSettingsQuickAccess ?? false): ?><a href="<?php echo htmlspecialchars($basePath . '/settings.php'); ?>" class="nav-link">Settings</a><?php endif; ?>
                        <a href="owner_support.php" class="nav-link">Help Center</a>
                        <?php if ($canManageOwnerHelpExperts ?? false): ?><a href="owner_help_experts_admin.php" class="nav-link">Expert Profiles</a><?php endif; ?>
                        <a href="docs.php" class="nav-link">Documentation</a>
                        <a href="email_assistant_capabilities.php" class="nav-link">Email Assistant Capabilities</a>
                        <a href="notification_preferences.php" class="nav-link">Notification Preferences</a>
                        <a href="settings_2fa.php" class="nav-link">Two-Factor Authentication</a>
                    </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <!-- Search Side Panel -->
    <div id="search-panel" class="search-panel">
        <div class="search-panel-header">
            <h3 style="margin: 0; font-size: 1.25rem; font-weight: 600; color: var(--midnight-black);">
                <i class="fas fa-search" style="margin-right: 8px; color: var(--accent-blue);"></i>Search Workspace
            </h3>
            <button id="search-panel-close" class="search-panel-close" onclick="toggleSearchPanel()" title="Close search panel" aria-label="Close search panel">
                <i class="fas fa-times" aria-hidden="true"></i>
            </button>
        </div>
        <div class="search-panel-content">
            <form method="GET" action="search.php" style="margin-bottom: var(--spacing-md);">
                <div style="position: relative;">
                    <input 
                        type="text" 
                        name="q" 
                        id="global-search"
                        placeholder="Search contacts, deals, emails..."
                        autocomplete="off"
                        class="search-panel-input"
                    >
                    <i class="fas fa-search" aria-hidden="true" style="position: absolute; left: 20px; top: 50%; transform: translateY(-50%); color: var(--accent-blue); pointer-events: none; font-size: 16px; opacity: 0.7;"></i>
                    <button type="submit" class="search-panel-submit" title="Run workspace search" aria-label="Run workspace search">
                        <i class="fas fa-arrow-right" aria-hidden="true"></i>
                    </button>
                </div>
            </form>
            <div id="search-suggestions" class="search-suggestions"></div>
        </div>
    </div>
    
    <!-- Search Panel Overlay -->
    <div id="search-panel-overlay" class="search-panel-overlay" onclick="toggleSearchPanel()"></div>

    <?php
    $demoBannerState = ['is_enabled' => false, 'simulation_only' => true, 'active_run_id' => null];
    if (\CRM\Auth::check()) {
        try {
            $demoBannerState = \CRM\Modules\DemoModeManager::getCachedState();
        } catch (\Throwable $e) {
            $demoBannerState = ['is_enabled' => false, 'simulation_only' => true, 'active_run_id' => null];
        }
    }
    ?>
    <?php if (!empty($demoBannerState['is_enabled'])): ?>
        <div style="background: linear-gradient(90deg, #0f172a, #1e3a8a); color: #fff; padding: 8px 12px; font-size: 13px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1);">
            Demo Mode Active
            <?php if (!empty($demoBannerState['simulation_only'])): ?>
                • External sends are simulated
            <?php endif; ?>
            <?php if (!empty($demoBannerState['active_run_id'])): ?>
                • Run #<?php echo (int) $demoBannerState['active_run_id']; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <?php if (!empty($protectedDemoClientState)): ?>
        <div class="protected-demo-banner" role="status">
            <div>
                <strong>Demo Mode</strong>
                <span>Private sandbox session.</span>
            </div>
            <form method="post" action="<?php echo htmlspecialchars(apiUrl('demo_access/end.php')); ?>" data-protected-demo-end>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(\CRM\Security::getCsrfToken()); ?>">
                <button type="submit">End demo</button>
            </form>
        </div>
    <?php endif; ?>

    <?php
    $layoutPageContentClass = $layoutCurrentPage === 'dashboard.php' ? 'page-content' : 'page-content page-content--wide';
    ?>
    <main class="<?php echo htmlspecialchars($layoutPageContentClass); ?>">
        <div class="container page-content-container">
            <?php
            $layoutRenderedContent = (string) ($content ?? '');
            if (class_exists(\CRM\Services\MarketingPageGuideUi::class)) {
                $layoutRenderedContent = \CRM\Services\MarketingPageGuideUi::decorateContent($layoutCurrentPage, $layoutRenderedContent);
            }
            if (class_exists(\CRM\Services\MarketingUi::class)) {
                $layoutRenderedContent = \CRM\Services\MarketingUi::decorateContent($layoutCurrentPage, $layoutRenderedContent);
            }
            echo $layoutRenderedContent;
            ?>
        </div>
    </main>

    <footer class="footer app-footer" aria-label="Application footer">
        <div class="container app-footer__inner">
            <div class="app-footer__brand">
                <img src="<?php echo htmlspecialchars($brandLogoUrl); ?>" srcset="<?php echo htmlspecialchars($brandLogoSrcset); ?>" width="36" height="28" alt="" class="app-footer__logo" loading="lazy" decoding="async">
                <div class="app-footer__copy">
                    <p class="app-footer__name"><?php echo htmlspecialchars($_ENV['COMPANY_NAME'] ?? brandProductName()); ?></p>
                    <p class="app-footer__meta">&copy; <?php echo date('Y'); ?> All rights reserved.</p>
                </div>
            </div>
            <nav class="app-footer__links" aria-label="Legal and privacy links">
                <?php if (\CRM\Auth::check() && !$isProtectedDemoSession && !$layoutIsDefaultWorkspace): ?>
                <button type="button" class="app-footer__link-button workspace-affordability-footer-link" data-workspace-affordability-open>
                    <i class="fas fa-hand-holding-heart" aria-hidden="true"></i> Workspace payments
                </button>
                <?php endif; ?>
                <a href="privacy-policy.php">Privacy</a>
                <a href="cookie-policy.php">Cookies</a>
                <a href="terms-of-service.php">Terms</a>
                <button type="button" class="app-footer__link-button" onclick="if(window.cookieConsent) window.cookieConsent.showSettings();">Cookie Settings</button>
            </nav>
        </div>
    </footer>

    <!-- Cookie Consent Banner -->
    <div id="cookie-consent-banner" class="cookie-consent-banner">
        <div class="container">
            <div class="content">
                <p>
                    We use cookies to enhance your experience, analyze site usage, and assist in our marketing efforts. 
                    By clicking "Accept All", you consent to our use of cookies. 
                    <a href="cookie-policy.php">Learn more</a>
                </p>
            </div>
            <div class="actions">
                <button id="cookie-accept" class="btn-accept">Accept All</button>
                <button id="cookie-reject" class="btn-reject">Reject Non-Essential</button>
                <button id="cookie-settings" class="btn-settings">Cookie Settings</button>
            </div>
        </div>
    </div>

    <!-- Cookie Settings Modal -->
    <div id="cookie-settings-modal" class="cookie-settings-modal">
        <div class="cookie-settings-content">
            <h2>Cookie Settings</h2>
            <p>Manage your cookie preferences. You can enable or disable different types of cookies below.</p>
            
            <div class="cookie-category">
                <h3>Essential Cookies</h3>
                <p>These cookies are necessary for the website to function and cannot be disabled. They are usually set in response to actions made by you, such as logging in or filling in forms.</p>
                <div class="toggle-switch">
                    <input type="checkbox" id="cookie-essential-toggle" checked disabled>
                    <label for="cookie-essential-toggle">Always Active</label>
                </div>
            </div>
            
            <div class="cookie-category">
                <h3>Analytics Cookies</h3>
                <p>These cookies help us understand how visitors interact with our website by collecting and reporting information anonymously.</p>
                <div class="toggle-switch">
                    <input type="checkbox" id="cookie-analytics-toggle">
                    <label for="cookie-analytics-toggle">Enable Analytics Cookies</label>
                </div>
            </div>
            
            <div class="cookie-category">
                <h3>Functional Cookies</h3>
                <p>These cookies enable enhanced functionality and personalization, such as remembering your preferences.</p>
                <div class="toggle-switch">
                    <input type="checkbox" id="cookie-functional-toggle">
                    <label for="cookie-functional-toggle">Enable Functional Cookies</label>
                </div>
            </div>
            
            <div class="modal-actions">
                <button id="cookie-cancel-settings" class="btn-cancel">Cancel</button>
                <button id="cookie-save-settings" class="btn-save">Save Preferences</button>
            </div>
        </div>
    </div>

    <!-- Notification toast container (fixed top-right) -->
    <div id="notification-toast-container" class="notification-toast-container" aria-live="polite"></div>
    <audio id="notification-sound" preload="none" src="<?php echo htmlspecialchars($assetBase . '/sounds/notification.mp3'); ?>"></audio>

    <?php if (\CRM\Auth::check()): ?>
    <?php
        $openClarityOnLogin = \CRM\Session::get('open_della_on_login') ? '1' : '0';
        \CRM\Session::remove('open_della_on_login');
        \CRM\Session::closeWrite();
        $clarityChatIcon = $assetBase . '/images/clarity-icon.svg?v=1';
        $isSuperAdminForClarity = \CRM\Authorization::isSuperAdmin(\CRM\Auth::user() ?: null) ? '1' : '0';
    ?>
    <!-- Chat bubble - AI help -->
    <div id="chat-bubble-container" class="chat-bubble-container" data-current-page="<?php echo htmlspecialchars(basename($_SERVER['PHP_SELF'] ?? '')); ?>" data-api-base="<?php echo htmlspecialchars(function_exists('getApiBasePath') ? getApiBasePath() : '/crm'); ?>" data-open-on-login="<?php echo $openClarityOnLogin; ?>" data-superadmin="<?php echo $isSuperAdminForClarity; ?>">
        <button type="button" id="chat-bubble-btn" class="chat-bubble-btn" aria-label="Ask <?php echo htmlspecialchars(brandAssistantName()); ?>" aria-expanded="false">
            <img src="<?php echo htmlspecialchars($clarityChatIcon); ?>" alt="" class="chat-bubble-logo" aria-hidden="true">
        </button>
        <div id="chat-bubble-panel" class="chat-bubble-panel" role="dialog" aria-modal="true" aria-hidden="true" aria-label="<?php echo htmlspecialchars(brandAssistantName()); ?>" tabindex="-1">
            <div class="chat-bubble-header">
                <h3>
                    <img src="<?php echo htmlspecialchars($assetBase . '/images/clarity-icon.svg?v=1'); ?>" alt="" class="chat-bubble-header-logo" aria-hidden="true">
                    <?php echo htmlspecialchars(brandAssistantName()); ?>
                </h3>
                <button type="button" id="chat-bubble-close" class="chat-bubble-close" aria-label="Close">
                    <i class="fas fa-times" aria-hidden="true"></i>
                </button>
            </div>
            <div id="chat-bubble-messages" class="chat-bubble-messages"></div>
            <div class="chat-bubble-input-area">
                <input type="text" id="chat-bubble-input" class="chat-bubble-input" placeholder="Ask Clarity about your growth system..." autocomplete="off">
                <button type="button" id="chat-bubble-send" class="chat-bubble-send" aria-label="Send">
                    <i class="fas fa-paper-plane" aria-hidden="true"></i>
                </button>
            </div>
        </div>
    </div>
    <script>
    (function () {
        var aiUiConsistencySrc = <?php echo json_encode($aiUiConsistencyJsUrl); ?>;
        var chatBubbleSrc = <?php echo json_encode($chatBubbleJsUrl); ?>;

        function loadScript(src, done) {
            if (!src) {
                done();
                return;
            }
            if (document.querySelector('script[src="' + src.replace(/"/g, '\\"') + '"]')) {
                done();
                return;
            }
            var script = document.createElement('script');
            script.src = src;
            script.async = false;
            script.onload = done;
            script.onerror = done;
            document.body.appendChild(script);
        }

        function loadDeferredScripts() {
            loadScript(aiUiConsistencySrc, function () {
                loadScript(chatBubbleSrc, function () {});
            });
        }

        function scheduleDeferredScripts() {
            if ('requestIdleCallback' in window) {
                window.requestIdleCallback(loadDeferredScripts, { timeout: 3500 });
                return;
            }
            window.setTimeout(loadDeferredScripts, 1200);
        }

        if (document.readyState === 'complete') {
            scheduleDeferredScripts();
        } else {
            window.addEventListener('load', scheduleDeferredScripts, { once: true });
        }
    })();

    <?php if (!empty($protectedDemoClientState)): ?>
    (function() {
        const config = <?php echo json_encode($protectedDemoClientState, JSON_UNESCAPED_SLASHES); ?>;
        window.protectedDemoClientState = config;
        const runtimeStartedAtMs = Date.now();
        const lastEventStorageKey = protectedDemoStorageKey('last_event_id');
        const completedMomentsStorageKey = protectedDemoStorageKey('completed_moment_keys');
        const initialStoredEventId = readStoredNumber(lastEventStorageKey, 0);
        let lastEventId = initialStoredEventId;
        let polling = false;
        let ticking = false;
        let runtimeStarted = false;
        let runtimeStopped = false;
        let pollIntervalId = null;
        let tickIntervalId = null;
        let lastDemoToastAt = 0;
        let lastDemoSoundAt = 0;
        let videoPauseActive = false;
        let videoPauseUntil = 0;
        let videoResumeTimer = null;
        let lastUserActivityAt = Date.now();
        let cueTickTimer = null;
        let pendingCueEvents = [];
        let pendingVisibleDemoKeys = [];
        let activeDemoEntity = { type: '', id: 0 };
        let lastDirectorState = null;
        let protectedDemoDraftTimers = [];
        let autoOpenAttempted = readBrowserStorage(protectedDemoStorageKey('auto_open_riverside_thread')) === '1';
        const runtimeHealth = window.protectedDemoRuntimeHealth = window.protectedDemoRuntimeHealth || {
            started: false,
            started_at: null,
            last_poll_at: null,
            last_tick_at: null,
            last_error: null,
            last_event_id: 0,
            poll_count: 0,
            tick_count: 0,
            toast_count: 0,
            suppressed_toast_count: 0,
            pulse_count: 0,
            cue_tick_count: 0,
            auto_open_count: 0,
            paused_reason: '',
            last_stored_event_id: initialStoredEventId,
            stopped: false,
            stopped_reason: ''
        };
        const toastSpacingMs = Math.max(18000, parseInt(config.toast_spacing_ms || 18000, 10));
        const soundSpacingMs = 45000;
        const videoTriggerSelector = [
            '[data-dashboard-video-open]',
            '[data-ai-coach-brief-video-open]',
            '[data-startup-journey-video-open]',
            '[data-founder-loop-video-open]',
            '[data-marketplace-setup-video-open]',
            '.marketplace-guide-action',
            'a[href*="youtube.com"]',
            'a[href*="youtu.be"]',
            'a[href*="vimeo.com"]',
            'a[href$=".mp4"]',
            'a[href$=".webm"]',
            'a[href$=".mov"]'
        ].join(',');
        const videoModalSelector = [
            '[data-dashboard-video-modal]',
            '[data-ai-coach-brief-video-modal]',
            '[data-startup-journey-video-modal]',
            '[data-founder-loop-video-modal]',
            '[data-marketplace-setup-video-modal]',
            '.dashboard-video-modal',
            '.founder-loop-video-modal',
            '.journey-video-modal',
            '.marketplace-page-guide-modal',
            '.marketplace-card-video-modal'
        ].join(',');

        function nowIso() {
            return new Date().toISOString();
        }

        function noteRuntimeError(error) {
            runtimeHealth.last_error = error && error.message ? error.message : String(error || 'unknown_error');
        }

        function stopProtectedDemoRuntime(reason) {
            if (runtimeStopped) return;
            runtimeStopped = true;
            runtimeHealth.stopped = true;
            runtimeHealth.stopped_reason = String(reason || 'stopped');
            runtimeHealth.last_error = runtimeHealth.stopped_reason;
            if (pollIntervalId) {
                window.clearInterval(pollIntervalId);
                pollIntervalId = null;
            }
            if (tickIntervalId) {
                window.clearInterval(tickIntervalId);
                tickIntervalId = null;
            }
            dispatchProtectedDemoEvent('demo:runtime-stopped', { reason: runtimeHealth.stopped_reason });
        }

        function protectedDemoStorageKey(name) {
            const sessionKey = String(config.session_uuid || 'session').replace(/[^a-zA-Z0-9_-]/g, '');
            return 'protected_demo:' + sessionKey + ':' + name;
        }

        function readBrowserStorage(key) {
            try {
                if (window.localStorage) {
                    const value = window.localStorage.getItem(key);
                    if (value !== null) return value;
                }
            } catch (error) {}
            try {
                if (window.sessionStorage) {
                    const value = window.sessionStorage.getItem(key);
                    if (value !== null) return value;
                }
            } catch (error) {}
            return '';
        }

        function writeBrowserStorage(key, value) {
            try {
                if (window.localStorage) {
                    window.localStorage.setItem(key, value);
                    return;
                }
            } catch (error) {}
            try {
                if (window.sessionStorage) {
                    window.sessionStorage.setItem(key, value);
                }
            } catch (error) {}
        }

        function readStoredNumber(key, fallback) {
            const parsed = parseInt(readBrowserStorage(key) || '', 10);
            return Number.isFinite(parsed) ? parsed : fallback;
        }

        function persistLastEventId() {
            if (lastEventId <= 0) return;
            writeBrowserStorage(lastEventStorageKey, String(lastEventId));
            runtimeHealth.last_stored_event_id = lastEventId;
        }

        function parseDemoEventId(event) {
            const id = parseInt((event && event.id) || 0, 10);
            return Number.isFinite(id) ? id : 0;
        }

        function isWelcomeDemoToast(event) {
            const payload = (event && event.payload) || {};
            return String(payload.demo_event_key || '') === 'welcome_private_sandbox';
        }

        function toastSeenStorageKey(event) {
            const eventId = parseDemoEventId(event);
            if (eventId > 0) {
                return protectedDemoStorageKey('toast_event_' + eventId);
            }
            const notification = event && event.payload ? event.payload.notification || {} : {};
            const notificationId = parseInt(notification.id || 0, 10);
            if (Number.isFinite(notificationId) && notificationId > 0) {
                return protectedDemoStorageKey('toast_notification_' + notificationId);
            }
            return '';
        }

        function hasToastBeenSeen(event) {
            if (isWelcomeDemoToast(event) && readBrowserStorage(protectedDemoStorageKey('welcome_toast_seen')) === '1') {
                return true;
            }
            const key = toastSeenStorageKey(event);
            return key !== '' && readBrowserStorage(key) === '1';
        }

        function markToastSeen(event) {
            const key = toastSeenStorageKey(event);
            if (key !== '') {
                writeBrowserStorage(key, '1');
            }
            if (isWelcomeDemoToast(event)) {
                writeBrowserStorage(protectedDemoStorageKey('welcome_toast_seen'), '1');
            }
        }

        function isStaleDemoEvent(event) {
            const eventId = parseDemoEventId(event);
            if (eventId > 0 && eventId <= initialStoredEventId) {
                return true;
            }
            const createdAt = Date.parse(String((event && event.created_at) || ''));
            return Number.isFinite(createdAt) && createdAt < (runtimeStartedAtMs - 2000);
        }

        function shouldAllowDemoToast(event) {
            if (!event || event.event_type !== 'notification_created') return false;
            if (config.is_protected_demo && config.demo_toasts_enabled === false) {
                runtimeHealth.suppressed_toast_count += 1;
                return false;
            }
            const payload = event.payload || {};
            if (payload.toast === false) return false;
            if (!payload.notification) return false;
            if (isStaleDemoEvent(event) || hasToastBeenSeen(event)) {
                runtimeHealth.suppressed_toast_count += 1;
                return false;
            }
            return true;
        }

        function dispatchProtectedDemoEvent(name, detail) {
            try {
                if (typeof window.CustomEvent === 'function') {
                    window.dispatchEvent(new CustomEvent(name, { detail: detail }));
                    return;
                }
                const event = document.createEvent('CustomEvent');
                event.initCustomEvent(name, false, false, detail);
                window.dispatchEvent(event);
            } catch (error) {
                noteRuntimeError(error);
            }
        }

        function readCompletedDemoMoments() {
            const raw = readBrowserStorage(completedMomentsStorageKey);
            if (!raw) return [];
            try {
                const parsed = JSON.parse(raw);
                return Array.isArray(parsed) ? parsed.filter(Boolean).map(String) : [];
            } catch (error) {
                return [];
            }
        }

        function writeCompletedDemoMoments(keys) {
            const unique = Array.from(new Set((keys || []).filter(Boolean).map(String)));
            writeBrowserStorage(completedMomentsStorageKey, JSON.stringify(unique));
        }

        function applyDemoStoryProgress(keys) {
            const completed = new Set((keys || readCompletedDemoMoments()).map(String));
            const items = Array.from(document.querySelectorAll('[data-demo-moment-key]'));
            let nextMarked = false;
            items.forEach(function(item) {
                const key = String(item.getAttribute('data-demo-moment-key') || '');
                const isComplete = completed.has(key);
                item.classList.toggle('is-complete', isComplete);
                item.classList.remove('is-next');
                if (!isComplete && !nextMarked) {
                    item.classList.add('is-next');
                    nextMarked = true;
                }
            });
        }

        function markDemoMomentComplete(eventKey) {
            eventKey = String(eventKey || '').trim();
            if (!eventKey) return;
            const keys = readCompletedDemoMoments();
            if (!keys.includes(eventKey)) {
                keys.push(eventKey);
                writeCompletedDemoMoments(keys);
            }
            applyDemoStoryProgress(keys);
        }

        function currentPageName() {
            return (window.location.pathname.split('/').pop() || '').trim();
        }

        function markUserActivity() {
            lastUserActivityAt = Date.now();
        }

        function currentIdleMs() {
            return Math.max(0, Date.now() - lastUserActivityAt);
        }

        function queueDemoCue(cue, detail) {
            if (!config.cue_director_enabled) return;
            cue = String(cue || '').trim();
            if (!cue) return;
            pendingCueEvents.push(cue);
            if (detail && detail.visibleKey) {
                pendingVisibleDemoKeys.push(String(detail.visibleKey));
            }
            if (detail && detail.entityType) {
                activeDemoEntity = {
                    type: String(detail.entityType || ''),
                    id: parseInt(String(detail.entityId || '0'), 10) || 0
                };
            }
            scheduleCueTick(0);
            if (['page_enter', 'dashboard_visible', 'dashboard_charts_revealed', 'dashboard_charts_visible', 'inbox_visible', 'riverside_thread_opened', 'draft_panel_visible', 'tasks_page_visible', 'targets_page_visible', 'contacts_page_visible', 'contact_detail_visible', 'meeting_prep_visible', 'plugins_page_visible', 'notifications_page_visible'].includes(cue)) {
                window.setTimeout(function() {
                    tickDemoExperience({ immediate: true });
                }, 2300);
            }
        }

        function scheduleCueTick(delayMs) {
            if (!config.cue_director_enabled || runtimeStopped) return;
            const delay = Math.max(0, parseInt(String(delayMs ?? config.cue_tick_debounce_ms ?? 500), 10) || 0);
            if (cueTickTimer) {
                window.clearTimeout(cueTickTimer);
            }
            cueTickTimer = window.setTimeout(function() {
                cueTickTimer = null;
                tickDemoExperience({ immediate: true });
            }, delay);
        }

        function uniqueStrings(values) {
            return Array.from(new Set((values || []).filter(Boolean).map(String)));
        }

        function applyDirectorState(state) {
            if (!state || typeof state !== 'object') return;
            lastDirectorState = state;
            if (Array.isArray(state.completed_keys)) {
                writeCompletedDemoMoments(state.completed_keys);
                applyDemoStoryProgress(state.completed_keys);
            }
            if (state.highlight_selector) {
                pulseDemoSelector(state.highlight_selector);
            }
            dispatchProtectedDemoEvent('demo:director-state', state);
        }

        function pulseDemoSelector(selector) {
            selector = String(selector || '').trim();
            if (!selector) return;
            let targets = [];
            try {
                targets = Array.from(document.querySelectorAll(selector));
            } catch (error) {
                return;
            }
            targets.slice(0, 4).forEach(function(element) {
                element.classList.add('protected-demo-cue-pulse');
                window.setTimeout(function() {
                    element.classList.remove('protected-demo-cue-pulse');
                }, 3600);
            });
        }

        function showProtectedDemoAutoCue(label, url, storageName) {
            label = String(label || 'Opening demo record').trim();
            url = String(url || '').trim();
            if (!url || currentPauseReason()) return false;
            if (storageName && readBrowserStorage(protectedDemoStorageKey(storageName)) === '1') return false;
            if (storageName) {
                writeBrowserStorage(protectedDemoStorageKey(storageName), '1');
            }
            runtimeHealth.auto_open_count += 1;
            const cue = document.createElement('div');
            cue.className = 'protected-demo-auto-open-cue';
            cue.setAttribute('role', 'status');
            cue.innerHTML = '<strong></strong><button type="button">Cancel</button>';
            const strong = cue.querySelector('strong');
            if (strong) strong.textContent = label;
            document.body.appendChild(cue);
            let cancelled = false;
            const button = cue.querySelector('button');
            if (button) {
                button.addEventListener('click', function() {
                    cancelled = true;
                    cue.remove();
                }, { once: true });
            }
            window.setTimeout(function() {
                if (cancelled) return;
                cue.remove();
                if (url) {
                    window.location.href = url;
                }
            }, 1400);
            return true;
        }

        function maybeAutoOpenRiversideThread() {
            if (!config.auto_open_enabled || autoOpenAttempted || currentPageName() !== 'inbox.php') return;
            if (currentPauseReason() || currentIdleMs() < Math.max(5000, parseInt(config.auto_open_idle_ms || 8000, 10))) return;
            const row = document.querySelector('[data-demo-riverside-thread="1"] .inbox-row-link[data-conversation-url], .inbox-row-link[data-demo-riverside-thread="1"][data-conversation-url]');
            if (!row) return;
            autoOpenAttempted = true;
            writeBrowserStorage(protectedDemoStorageKey('auto_open_riverside_thread'), '1');
            const url = row.getAttribute('data-conversation-url') || '';
            showProtectedDemoAutoCue('Opening Riverside thread', url, '');
        }

        function showProtectedDemoDraftPreview(text) {
            if (currentPageName() !== 'conversation.php') return;
            text = String(text || '').trim();
            if (!text) return;
            let card = document.querySelector('[data-protected-demo-draft]');
            if (!card) {
                card = document.createElement('section');
                card.className = 'protected-demo-draft-card';
                card.setAttribute('data-protected-demo-draft', '1');
                card.setAttribute('data-demo-cue-key', 'draft_panel_visible');
                card.innerHTML = '<div class="protected-demo-draft-card__kicker">Clarity draft</div><div class="protected-demo-draft-card__body" aria-live="polite"></div><a class="protected-demo-draft-card__action" href="tasks.php">Open Tasks</a>';
                const form = document.getElementById('conversation-reply-form');
                const thread = document.querySelector('.chat-thread');
                if (form && form.parentNode) {
                    form.parentNode.insertBefore(card, form);
                } else if (thread && thread.parentNode) {
                    thread.parentNode.insertBefore(card, thread.nextSibling);
                } else {
                    document.body.appendChild(card);
                }
            }
            const body = card.querySelector('.protected-demo-draft-card__body');
            if (!body) return;
            if (card.getAttribute('data-demo-draft-preview') === text && card.getAttribute('data-demo-draft-complete') === '1') {
                pulseDemoSelector('[data-protected-demo-draft]');
                return;
            }
            protectedDemoDraftTimers.forEach(function(timerId) {
                window.clearTimeout(timerId);
            });
            protectedDemoDraftTimers = [];
            card.setAttribute('data-demo-draft-preview', text);
            card.setAttribute('data-demo-draft-complete', '0');
            body.textContent = '';
            const chunks = text.split(/(?<=\.)\s+/).filter(Boolean);
            let index = 0;
            function writeNextChunk() {
                if (card.getAttribute('data-demo-draft-preview') !== text) return;
                body.textContent += (body.textContent ? ' ' : '') + (chunks[index] || '');
                index += 1;
                if (index < chunks.length) {
                    protectedDemoDraftTimers.push(window.setTimeout(writeNextChunk, 520));
                } else {
                    card.setAttribute('data-demo-draft-complete', '1');
                }
            }
            writeNextChunk();
            pulseDemoSelector('[data-protected-demo-draft]');
            queueDemoCue('draft_panel_visible', { visibleKey: 'conversation_draft' });
        }

        function openProtectedDemoClarity(message, storageName) {
            message = String(message || '').trim();
            if (!message) return;
            if (storageName && readBrowserStorage(protectedDemoStorageKey(storageName)) === '1') return;
            if (!window.ClarityChatBubble) {
                window.setTimeout(function() {
                    openProtectedDemoClarity(message, storageName);
                }, 700);
                return;
            }
            if (storageName) {
                writeBrowserStorage(protectedDemoStorageKey(storageName), '1');
            }
            if (typeof window.ClarityChatBubble.demoMessage === 'function') {
                window.ClarityChatBubble.demoMessage(message);
            } else if (typeof window.ClarityChatBubble.open === 'function') {
                window.ClarityChatBubble.open();
            }
        }

        function handleProtectedDemoScenePayload(payload, event) {
            payload = payload || {};
            const sceneKey = String(payload.scene_key || payload.demo_event_key || '').trim();
            if (sceneKey) {
                dispatchProtectedDemoEvent('protected-demo:scene', {
                    scene_key: sceneKey,
                    scene_step: payload.scene_step || '',
                    payload: payload,
                    event: event || null
                });
            }

            const animationPayload = payload.animation_payload || {};
            if (payload.auto_action === 'open_clarity' || payload.open_clarity) {
                const clarityMessage = animationPayload.clarity_message
                    || (payload.notification && (payload.notification.ai_action || payload.notification.ai_insight))
                    || 'Clarity is ready with the next Riverside move. Open Inbox to watch the private demo story unfold.';
                openProtectedDemoClarity(clarityMessage, 'clarity_scene_' + (sceneKey || payload.demo_event_key || 'once'));
            }

            if ((payload.auto_action === 'type_draft' || sceneKey === 'assistant_draft_typing_started' || sceneKey === 'assistant_draft_ready') && payload.draft_preview) {
                showProtectedDemoDraftPreview(payload.draft_preview);
            }

            const autoActionUrl = String(payload.auto_action_url || '').trim();
            if (!autoActionUrl || !config.auto_open_enabled || currentPauseReason()) return;
            if (payload.auto_action === 'open_riverside_thread' && currentPageName() === 'inbox.php') {
                showProtectedDemoAutoCue('Opening Riverside thread', autoActionUrl, 'auto_action_open_riverside_thread');
            } else if (payload.auto_action === 'open_amina_contact' && currentPageName() === 'contacts.php') {
                showProtectedDemoAutoCue('Opening Amina contact', autoActionUrl, 'auto_action_open_amina_contact');
            } else if (payload.auto_action === 'open_riverside_task' && currentPageName() === 'tasks.php') {
                showProtectedDemoAutoCue('Opening Riverside task', autoActionUrl, 'auto_action_open_riverside_task');
            }
        }

        function handleDemoEvent(event, options) {
            if (!event || !event.event_type) return;
            const allowToast = !(options && options.allowToast === false);
            const payload = event.payload || {};
            if (payload.demo_event_key) {
                markDemoMomentComplete(payload.demo_event_key);
            }
            if (payload.highlight_selector) {
                pulseDemoSelector(payload.highlight_selector);
            }
            handleProtectedDemoScenePayload(payload, event);
            if (payload.next_cue || payload.progress_label || payload.highlight_selector) {
                applyDirectorState({
                    next_cue: payload.next_cue || '',
                    progress_label: payload.progress_label || '',
                    highlight_selector: payload.highlight_selector || '',
                    last_event_key: payload.demo_event_key || '',
                    last_progress_label: payload.progress_label || ''
                });
            }
            if (event.event_type === 'notification_created' && typeof window.updateNotificationBadge === 'function') {
                window.updateNotificationBadge({ silent: true });
                if (allowToast) {
                    showDemoEventToast(event);
                }
            }
            if ((event.event_type === 'communication_created' || event.event_type === 'triage_completed') && window.location.pathname.indexOf('inbox.php') !== -1) {
                dispatchProtectedDemoEvent('demo:inbox-refresh', event);
                if (typeof window.refreshInboxListAsync === 'function') {
                    window.refreshInboxListAsync({ background: true, suppressStatus: true });
                } else if (typeof window.refreshInbox === 'function') {
                    window.refreshInbox();
                }
                window.setTimeout(maybeAutoOpenRiversideThread, 500);
            }
        }

        function pollDemoEvents() {
            if (runtimeStopped || polling || !config.poll_url) return;
            polling = true;
            runtimeHealth.poll_count += 1;
            runtimeHealth.last_poll_at = nowIso();
            const sep = config.poll_url.indexOf('?') === -1 ? '?' : '&';
            fetch(config.poll_url + sep + 'after_id=' + encodeURIComponent(String(lastEventId)), {
                credentials: 'same-origin',
                headers: { 'X-CRM-Session-Passive': '1' }
            })
                .then(function(response) {
                    if (response.status === 401 || response.status === 403 || response.status === 410) {
                        stopProtectedDemoRuntime('demo_session_inactive');
                        return null;
                    }
                    if (!response.ok) {
                        noteRuntimeError('demo_realtime_http_' + response.status);
                        return null;
                    }
                    return response.json();
                })
                .then(function(payload) {
                    if (!payload || !payload.success) return;
                    (payload.events || []).forEach(function(event) {
                        const allowToast = shouldAllowDemoToast(event);
                        lastEventId = Math.max(lastEventId, parseDemoEventId(event));
                        runtimeHealth.last_event_id = lastEventId;
                        persistLastEventId();
                        handleDemoEvent(event, { allowToast: allowToast });
                    });
                })
                .catch(noteRuntimeError)
                .finally(function() { polling = false; });
        }

        function tickDemoExperience(options) {
            if (runtimeStopped || ticking || !config.experience_enabled || !config.experience_tick_url) return;
            ticking = true;
            runtimeHealth.tick_count += 1;
            if (options && options.immediate) {
                runtimeHealth.cue_tick_count += 1;
            }
            runtimeHealth.last_tick_at = nowIso();
            const pauseReason = currentPauseReason();
            runtimeHealth.paused_reason = pauseReason;
            const body = new URLSearchParams();
            body.set('csrf_token', config.csrf_token || '');
            body.set('current_page', currentPageName());
            body.set('client_visibility', document.hidden ? 'hidden' : 'visible');
            const cues = uniqueStrings(pendingCueEvents);
            const visibleKeys = uniqueStrings(pendingVisibleDemoKeys);
            pendingCueEvents = [];
            pendingVisibleDemoKeys = [];
            body.set('cue_events_json', JSON.stringify(cues));
            body.set('visible_demo_keys_json', JSON.stringify(visibleKeys));
            body.set('active_entity_type', activeDemoEntity.type || '');
            body.set('active_entity_id', String(activeDemoEntity.id || 0));
            body.set('idle_ms', String(currentIdleMs()));
            body.set('interaction_state', currentPauseReason() ? 'paused' : (currentIdleMs() >= 8000 ? 'idle' : 'active'));
            body.set('autoplay_allowed', config.auto_open_enabled ? '1' : '0');
            if (pauseReason) {
                body.set('paused_reason', pauseReason);
            }
            fetch(config.experience_tick_url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                    'X-CRM-Session-Passive': '1'
                },
                body: body.toString()
            })
                .then(function(response) {
                    if (response.status === 401 || response.status === 403 || response.status === 410) {
                        stopProtectedDemoRuntime('demo_session_inactive');
                        return null;
                    }
                    if (!response.ok) {
                        noteRuntimeError('demo_experience_http_' + response.status);
                        return null;
                    }
                    return response.json();
                })
                .then(function(payload) {
                    if (!payload || !payload.success) return;
                    if (payload.director_state) {
                        applyDirectorState(payload.director_state);
                    }
                    if ((payload.emitted_count || 0) > 0) {
                        window.setTimeout(pollDemoEvents, 250);
                        window.setTimeout(function() {
                            tickDemoExperience({ immediate: true });
                        }, 1300);
                    }
                    window.setTimeout(maybeAutoOpenRiversideThread, 650);
                })
                .catch(noteRuntimeError)
                .finally(function() { ticking = false; });
        }

        function showDemoEventToast(event) {
            if (config.is_protected_demo && config.demo_toasts_enabled === false) {
                runtimeHealth.suppressed_toast_count += 1;
                markToastSeen(event);
                return;
            }
            if (currentPauseReason()) return;
            const payload = event.payload || {};
            const notification = payload.notification || null;
            if (!notification) return;
            if (hasToastBeenSeen(event)) return;
            if (typeof window.showNotificationToast !== 'function') {
                window.setTimeout(function() { showDemoEventToast(event); }, 500);
                return;
            }

            const now = Date.now();
            if (now - lastDemoToastAt < toastSpacingMs) {
                const wait = toastSpacingMs - (now - lastDemoToastAt);
                window.setTimeout(function() { showDemoEventToast(event); }, wait);
                return;
            }

            const severity = String(notification.severity || '').toLowerCase();
            const role = (severity === 'high' || severity === 'critical') ? 'alert' : 'status';
            const rendered = window.showNotificationToast(notification, {
                maxVisible: config.is_protected_demo ? 1 : 3,
                variant: 'protected-demo',
                role: role,
                label: payload.toast_label || notification.icon || 'Demo',
                actionLabel: payload.toast_action_label || 'Open',
                openUrl: payload.toast_context_url || notification.link || 'notifications.php'
            });
            if (!rendered) return;
            markToastSeen(event);
            lastDemoToastAt = Date.now();
            runtimeHealth.toast_count += 1;

            if (payload.open_clarity && window.ClarityChatBubble) {
                const demoClarityMessage = notification.ai_action
                    || 'Clarity is highlighting the next best demo action. Open Inbox, Tasks, Targets, Plugins, or Contact Intelligence to see how the private workspace story connects.';
                if (config.is_protected_demo && typeof window.ClarityChatBubble.demoMessage === 'function') {
                    window.ClarityChatBubble.demoMessage(demoClarityMessage);
                } else if (typeof window.ClarityChatBubble.ask === 'function') {
                    window.ClarityChatBubble.ask(demoClarityMessage, 'protected_demo');
                }
            }

            const soundUnlocked = typeof window.isNotificationAudioUnlocked !== 'function' || window.isNotificationAudioUnlocked();
            const soundAllowed = !!payload.sound && soundUnlocked && (Date.now() - lastDemoSoundAt >= soundSpacingMs) && !currentPauseReason();
            if (soundAllowed && typeof window.playNotificationSound === 'function') {
                window.playNotificationSound();
                lastDemoSoundAt = Date.now();
            }
        }

        function currentPauseReason() {
            if (document.hidden) return 'document_hidden';
            if (config.video_pause_enabled && (videoPauseActive || Date.now() < videoPauseUntil || hasOpenVideoExperience())) {
                return 'video';
            }
            const active = document.activeElement;
            if (active && active !== document.body && active.matches && active.matches('input, textarea, select, [contenteditable="true"], [contenteditable=""]')) {
                return 'composer_focus';
            }
            return '';
        }

        function hasOpenVideoExperience() {
            const playingVideo = Array.from(document.querySelectorAll('video')).some(function(video) {
                return !video.paused && !video.ended;
            });
            if (playingVideo) return true;

            return Array.from(document.querySelectorAll(videoModalSelector)).some(function(modal) {
                if (!modal || modal.hidden) return false;
                const style = window.getComputedStyle(modal);
                return style.display !== 'none' && style.visibility !== 'hidden';
            });
        }

        function pauseForVideo(durationMs, active) {
            if (!config.video_pause_enabled) return;
            if (active) {
                videoPauseActive = true;
            }
            if (durationMs && durationMs > 0) {
                videoPauseUntil = Math.max(videoPauseUntil, Date.now() + durationMs);
            }
            if (videoResumeTimer) {
                window.clearTimeout(videoResumeTimer);
                videoResumeTimer = null;
            }
        }

        function scheduleVideoResume() {
            if (!config.video_pause_enabled) return;
            if (videoResumeTimer) {
                window.clearTimeout(videoResumeTimer);
            }
            videoResumeTimer = window.setTimeout(function() {
                if (hasOpenVideoExperience()) {
                    scheduleVideoResume();
                    return;
                }
                videoPauseActive = false;
                videoPauseUntil = 0;
            }, 10000);
        }

        function decorateVideoTriggers(root) {
            if (!config.video_pause_enabled) return;
            const scope = root && root.querySelectorAll ? root : document;
            scope.querySelectorAll(videoTriggerSelector).forEach(function(element) {
                if (element.dataset.protectedDemoVideoPulse === '1') return;
                element.dataset.protectedDemoVideoPulse = '1';
                element.classList.add('protected-demo-premium-video-pulse', 'is-fresh');
                runtimeHealth.pulse_count += 1;
                window.setTimeout(function() {
                    element.classList.remove('is-fresh');
                    element.classList.add('is-settled');
                }, 12000);
            });
        }

        function bindVideoPauseRuntime() {
            decorateVideoTriggers(document);

            document.addEventListener('click', function(event) {
                const trigger = event.target && event.target.closest ? event.target.closest(videoTriggerSelector) : null;
                if (trigger) {
                    const isExternalAnchor = trigger.tagName === 'A' && /^https?:\/\//i.test(trigger.getAttribute('href') || '');
                    pauseForVideo(90000, !isExternalAnchor);
                }

                const close = event.target && event.target.closest ? event.target.closest('[data-dashboard-video-close], [data-ai-coach-brief-video-close], [data-startup-journey-video-close], [data-founder-loop-video-close], [data-marketplace-setup-video-close], .dashboard-video-close, .founder-loop-video-close, .journey-video-close, .marketplace-page-guide-close, .marketplace-card-video-close') : null;
                if (close) {
                    scheduleVideoResume();
                }
            }, true);

            document.addEventListener('play', function(event) {
                if (event.target && event.target.tagName === 'VIDEO') {
                    pauseForVideo(0, true);
                }
            }, true);
            document.addEventListener('pause', function(event) {
                if (event.target && event.target.tagName === 'VIDEO') {
                    scheduleVideoResume();
                }
            }, true);
            document.addEventListener('ended', function(event) {
                if (event.target && event.target.tagName === 'VIDEO') {
                    scheduleVideoResume();
                }
            }, true);
            window.addEventListener('focus', scheduleVideoResume);

            if ('MutationObserver' in window) {
                const observer = new MutationObserver(function(mutations) {
                    let shouldDecorate = false;
                    let shouldSyncPause = false;
                    mutations.forEach(function(mutation) {
                        if (mutation.type === 'childList') {
                            shouldDecorate = true;
                        }
                        if (mutation.type === 'attributes' && mutation.target && mutation.target.matches && mutation.target.matches(videoModalSelector)) {
                            shouldSyncPause = true;
                        }
                    });
                    if (shouldDecorate) {
                        decorateVideoTriggers(document);
                    }
                    if (shouldSyncPause) {
                        if (hasOpenVideoExperience()) {
                            pauseForVideo(90000, true);
                        } else {
                            scheduleVideoResume();
                        }
                    }
                });
                observer.observe(document.body, {
                    subtree: true,
                    childList: true,
                    attributes: true,
                    attributeFilter: ['hidden', 'style', 'class']
                });
            }
        }

        function bindCueDirectorRuntime() {
            if (!config.cue_director_enabled) return;

            ['pointerdown', 'keydown', 'scroll', 'touchstart'].forEach(function(name) {
                window.addEventListener(name, markUserActivity, { passive: true });
            });
            document.addEventListener('focusin', markUserActivity, true);

            const page = currentPageName();
            queueDemoCue('page_enter', { visibleKey: page });

            const pageCueMap = {
                'dashboard.php': 'dashboard_visible',
                'inbox.php': 'inbox_visible',
                'conversation.php': 'riverside_thread_opened',
                'tasks.php': 'tasks_page_visible',
                'targets.php': 'targets_page_visible',
                'contacts.php': 'contacts_page_visible',
                'contact_view.php': 'contact_detail_visible',
                'workspace_skills.php': 'plugins_page_visible',
                'notifications.php': 'notifications_page_visible'
            };
            if (pageCueMap[page]) {
                queueDemoCue(pageCueMap[page], { visibleKey: page });
            }

            if (page === 'conversation.php') {
                const params = new URLSearchParams(window.location.search || '');
                activeDemoEntity = {
                    type: 'communication',
                    id: parseInt(params.get('id') || '0', 10) || 0
                };
                window.setTimeout(function() {
                    queueDemoCue('draft_panel_visible', {
                        visibleKey: 'conversation_draft',
                        entityType: 'communication',
                        entityId: activeDemoEntity.id
                    });
                }, 1800);
            }

            document.addEventListener('click', function(event) {
                const row = event.target && event.target.closest ? event.target.closest('[data-demo-riverside-thread="1"]') : null;
                if (row) {
                    activeDemoEntity = {
                        type: 'communication',
                        id: parseInt(row.getAttribute('data-communication-id') || '0', 10) || 0
                    };
                    queueDemoCue('riverside_thread_opened', {
                        visibleKey: 'riverside_thread',
                        entityType: 'communication',
                        entityId: activeDemoEntity.id
                    });
                }
            }, true);

            if ('IntersectionObserver' in window) {
                const cueObserver = new IntersectionObserver(function(entries) {
                    entries.forEach(function(entry) {
                        if (!entry.isIntersecting) return;
                        const key = entry.target.getAttribute('data-demo-cue-key') || '';
                        if (key) {
                            if (key === 'dashboard_charts_visible' || key === 'dashboard_charts_revealed') {
                                entry.target.classList.add('protected-demo-chart-reveal');
                            }
                            queueDemoCue(key, { visibleKey: key });
                        }
                    });
                }, { rootMargin: '220px 0px', threshold: 0.12 });
                document.querySelectorAll('[data-demo-cue-key]').forEach(function(element) {
                    cueObserver.observe(element);
                });
            }

            window.addEventListener('protected-demo:cue', function(event) {
                const detail = event.detail || {};
                queueDemoCue(detail.cue || detail.name || detail.key || '', detail);
            });

            window.addEventListener('demo:inbox-refresh', function() {
                window.setTimeout(function() {
                    pulseDemoSelector('[data-demo-riverside-thread="1"]');
                    maybeAutoOpenRiversideThread();
                }, 700);
            });

            window.setInterval(function() {
                if (currentPageName() === 'inbox.php') {
                    maybeAutoOpenRiversideThread();
                }
                if (currentIdleMs() >= 8000) {
                    queueDemoCue('idle', { visibleKey: currentPageName() });
                }
            }, 2000);
        }

        const endForm = document.querySelector('[data-protected-demo-end]');
        if (endForm) {
            endForm.addEventListener('submit', function(event) {
                event.preventDefault();
                const data = new FormData(endForm);
                fetch(config.end_url || endForm.action, {
                    method: 'POST',
                    credentials: 'same-origin',
                    body: data
                }).finally(function() {
                    window.location.href = <?php echo json_encode(publicUrl('demo.php?ended=1')); ?>;
                });
            });
        }

        function notificationHelpersReady() {
            return typeof window.showNotificationToast === 'function'
                && typeof window.updateNotificationBadge === 'function';
        }

        function startProtectedDemoRuntime() {
            if (runtimeStarted || runtimeStopped) return;
            runtimeStarted = true;
            runtimeHealth.started = true;
            runtimeHealth.started_at = nowIso();
            bindVideoPauseRuntime();
            bindCueDirectorRuntime();
            applyDemoStoryProgress();
            pollDemoEvents();
            pollIntervalId = window.setInterval(pollDemoEvents, 3500);
            window.setTimeout(tickDemoExperience, 1000);
            tickIntervalId = window.setInterval(tickDemoExperience, 5000);
        }

        function scheduleProtectedDemoRuntimeStart(attempt) {
            const tries = parseInt(attempt || 0, 10);
            if (notificationHelpersReady() || tries >= 80) {
                if (!notificationHelpersReady()) {
                    noteRuntimeError('notification_helpers_timeout');
                }
                startProtectedDemoRuntime();
                return;
            }
            window.setTimeout(function() {
                scheduleProtectedDemoRuntimeStart(tries + 1);
            }, 100);
        }

        window.addEventListener('crm:notification-runtime-ready', function() {
            scheduleProtectedDemoRuntimeStart(0);
        });
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function() {
                scheduleProtectedDemoRuntimeStart(0);
            }, { once: true });
        } else {
            scheduleProtectedDemoRuntimeStart(0);
        }
    })();
    <?php endif; ?>
    </script>
    <?php endif; ?>

    <script defer src="<?php echo htmlspecialchars($assetBase . '/js/cookie-consent.js'); ?>"></script>
    <script defer src="<?php echo htmlspecialchars($versionedAssetUrl('js/app.js')); ?>"></script>
    <?php if (\CRM\Auth::check()): ?>
        <script>
            window.CrmSessionUx = <?php echo json_encode([
                'statusUrl' => apiUrl('session/status.php'),
                'loginUrl' => \CRM\Auth::loginUrl(null, true),
                'reauthUrl' => \CRM\Auth::loginUrl(null, false, true),
                'secondsRemaining' => \CRM\Session::secondsUntilIdleExpiry(),
                'lifetimeSeconds' => \CRM\Session::lifetimeSeconds(),
                'warnBeforeSeconds' => 300,
                'csrfToken' => \CRM\Security::getCsrfToken(),
            ], JSON_UNESCAPED_SLASHES); ?>;
        </script>
        <script defer src="<?php echo htmlspecialchars($versionedAssetUrl('js/session-ux.js')); ?>"></script>
    <?php endif; ?>
    <?php if (!empty($guidedDemoClientState)): ?>
        <script>
            window.GuidedFounderDemo = <?php echo json_encode([
                'state' => $guidedDemoClientState,
                'actionUrl' => apiUrl('guided_demo/action.php'),
                'advanceUrl' => apiUrl('guided_demo/advance.php'),
                'endUrl' => apiUrl('guided_demo/end.php'),
                'eventUrl' => apiUrl('guided_demo/event.php'),
                'csrfToken' => \CRM\Security::getCsrfToken(),
            ], JSON_UNESCAPED_SLASHES); ?>;
        </script>
        <script defer src="<?php echo htmlspecialchars($assetBase . '/js/guided-demo.js?v=' . urlencode(APP_VERSION . '-workday-demo-v2')); ?>"></script>
    <?php endif; ?>
    <?php if ($layoutNeedsQuill && !$layoutContentIncludesQuill): ?>
        <script src="https://cdn.quilljs.com/1.3.6/quill.js"></script>
    <?php endif; ?>
    <?php if ($layoutNeedsRichTextEditor): ?>
        <script src="<?php echo htmlspecialchars($assetBase . '/js/rich_text_editor.js?v=richtext-quill-guard-20260520'); ?>"></script>
    <?php endif; ?>
    <?php if (isset($additionalScripts)): ?>
        <?php foreach ($additionalScripts as $script): ?>
            <script src="<?php echo $script; ?>"></script>
        <?php endforeach; ?>
    <?php endif; ?>
    
    <script>
    // Dropdown toggle function
    function toggleDropdown(dropdownId) {
        const dropdown = document.getElementById(dropdownId);
        if (!dropdown) return;
        
        // Close all other dropdowns
        document.querySelectorAll('.nav-dropdown').forEach(function(d) {
            if (d.id !== dropdownId) {
                d.classList.remove('active');
            }
        });
        
        // Toggle current dropdown
        dropdown.classList.toggle('active');
    }
    
    // Close dropdowns when clicking outside
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.nav-dropdown') && !e.target.closest('.mobile-menu-toggle')) {
            document.querySelectorAll('.nav-dropdown').forEach(function(dropdown) {
                dropdown.classList.remove('active');
            });
        }
    });
    
    // Add scroll effect to navbar
    window.addEventListener('scroll', function() {
        const navbar = document.querySelector('.navbar');
        if (!navbar) return;
        
        const currentScroll = window.pageYOffset;
        
        if (currentScroll > 50) {
            navbar.classList.add('scrolled');
        } else {
            navbar.classList.remove('scrolled');
        }
    });
    
    // Mobile menu toggle
    (function() {
        const mobileMenuToggle = document.getElementById('mobile-menu-toggle');
        const mobileMenu = document.getElementById('mobile-menu');
        
        if (mobileMenuToggle && mobileMenu) {
            // Show/hide toggle button based on screen size
            function checkScreenSize() {
                if (window.innerWidth <= 768) {
                    mobileMenuToggle.style.display = 'block';
                } else {
                    mobileMenuToggle.style.display = 'none';
                    mobileMenu.classList.remove('active');
                }
            }
            
            checkScreenSize();
            window.addEventListener('resize', checkScreenSize);
            
            mobileMenuToggle.addEventListener('click', function() {
                mobileMenu.classList.toggle('active');
                mobileMenuToggle.setAttribute('aria-expanded', mobileMenu.classList.contains('active') ? 'true' : 'false');
            });
            
            // Close menu when clicking outside
            document.addEventListener('click', function(e) {
                if (!mobileMenuToggle.contains(e.target) && !mobileMenu.contains(e.target)) {
                    mobileMenu.classList.remove('active');
                    mobileMenuToggle.setAttribute('aria-expanded', 'false');
                }
            });
        }
    })();
    
    // Notification: badge, sound, and toast
    (function() {
        let previousNewCount = null;
        let lastShownNotificationId = null;
        let lastClarityTriggerNotificationId = null;
        let notificationAudioContext = null;
        let notificationAudioUnlocked = false;

        function unlockNotificationAudio() {
            if (notificationAudioUnlocked) return;
            notificationAudioUnlocked = true;

            const audio = document.getElementById('notification-sound');
            if (audio) {
                audio.muted = true;
                const unlockPromise = audio.play();
                if (unlockPromise && typeof unlockPromise.then === 'function') {
                    unlockPromise.then(function() {
                        audio.pause();
                        audio.currentTime = 0;
                        audio.muted = false;
                    }).catch(function() {
                        audio.muted = false;
                    });
                } else {
                    audio.muted = false;
                }
            }

            try {
                const Ctx = window.AudioContext || window.webkitAudioContext;
                if (Ctx) {
                    if (!notificationAudioContext) {
                        notificationAudioContext = new Ctx();
                    }
                    if (notificationAudioContext.state === 'suspended') {
                        notificationAudioContext.resume().catch(function() {});
                    }
                }
            } catch (e) {}
        }

        ['click', 'keydown', 'touchstart'].forEach(function(evt) {
            document.addEventListener(evt, unlockNotificationAudio, { once: true, passive: true });
        });

        function playNotificationSound() {
            const audio = document.getElementById('notification-sound');
            if (audio && audio.src) {
                audio.currentTime = 0;
                audio.play().catch(function() {
                    playNotificationSoundFallback();
                });
            } else {
                playNotificationSoundFallback();
            }
        }
        function playNotificationSoundFallback() {
            try {
                const Ctx = window.AudioContext || window.webkitAudioContext;
                if (!Ctx) return;
                const ctx = notificationAudioContext || new Ctx();
                notificationAudioContext = ctx;
                if (ctx.state === 'suspended') {
                    ctx.resume().catch(function() {});
                }
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.frequency.value = 880;
                osc.type = 'sine';
                gain.gain.setValueAtTime(0.15, ctx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.15);
                osc.start(ctx.currentTime);
                osc.stop(ctx.currentTime + 0.15);
            } catch (e) {}
        }

        function showNotificationToast(notification, options) {
            const notificationId = notification && notification.id ? String(notification.id) : '';
            if (!notification || (notificationId !== '' && lastShownNotificationId === notificationId)) return false;
            const container = document.getElementById('notification-toast-container');
            if (!container) return false;
            const opts = options || {};
            if (notificationId !== '') {
                lastShownNotificationId = notificationId;
            }
            const maxVisible = Math.max(1, parseInt(opts.maxVisible || 3, 10));
            while (container.children.length >= maxVisible && container.firstElementChild) {
                container.removeChild(container.firstElementChild);
            }
            const openUrl = (opts.openUrl && String(opts.openUrl).trim())
                ? String(opts.openUrl)
                : ((notification.link && notification.link.trim()) ? notification.link : 'notifications.php');
            const actionLabel = (opts.actionLabel && String(opts.actionLabel).trim()) ? String(opts.actionLabel) : 'Open';
            const label = (opts.label && String(opts.label).trim()) ? String(opts.label) : '';
            const role = opts.role === 'status' ? 'status' : 'alert';
            const variantClass = opts.variant ? ' notification-toast--' + String(opts.variant).replace(/[^a-z0-9_-]/gi, '') : '';
            const toast = document.createElement('div');
            toast.className = 'notification-toast' + variantClass;
            toast.setAttribute('role', role);
            if (notification.color) toast.style.borderLeftColor = notification.color;
            const title = typeof notification.title === 'string' ? notification.title : 'Notification';
            const message = typeof notification.message === 'string' ? notification.message : '';
            const titlePrefix = (!opts.variant && notification.icon) ? notification.icon + ' ' : '';
            toast.innerHTML =
                (label ? '<div class="notification-toast-kicker">' + escapeHtml(label) + '</div>' : '') +
                '<div class="notification-toast-title">' + escapeHtml(titlePrefix) + escapeHtml(title) + '</div>' +
                (message ? '<div class="notification-toast-message">' + escapeHtml(message) + '</div>' : '') +
                '<div class="notification-toast-actions">' +
                '<a href="' + escapeHtml(openUrl) + '" class="notification-toast-open">' + escapeHtml(actionLabel) + '</a>' +
                '<button type="button" class="notification-toast-dismiss">Dismiss</button>' +
                '</div>';
            function removeToast() {
                if (toast.parentNode) toast.parentNode.removeChild(toast);
            }
            toast.querySelector('.notification-toast-dismiss').addEventListener('click', removeToast);
            toast.querySelector('.notification-toast-open').addEventListener('click', function() { removeToast(); });
            container.appendChild(toast);
            setTimeout(removeToast, 7000);
            return true;
        }
        window.showNotificationToast = showNotificationToast;
        window.playNotificationSound = playNotificationSound;
        window.isNotificationAudioUnlocked = function() {
            return notificationAudioUnlocked;
        };
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function triggerClarityForNotification(notification) {
            if (!notification || lastClarityTriggerNotificationId === notification.id) return;
            const notificationType = notification.type || '';
            const severity = (notification.severity || '').toLowerCase();
            const isImportantAlert = notificationType === 'system_alert' && (severity === 'high' || severity === 'critical');
            const isCoachNudge = notificationType === 'ai_coach_nudge';
            if (!isImportantAlert && !isCoachNudge) return;

            lastClarityTriggerNotificationId = notification.id;
            const detail = isCoachNudge
                ? {
                    reason: 'coach_nudge',
                    id: notification.id,
                    nudge: {
                        insight: notification.ai_insight || notification.title || '',
                        action: notification.ai_action || notification.message || ''
                    }
                }
                : { reason: 'important_alert', id: notification.id, severity: severity };
            window.dispatchEvent(new CustomEvent('della:open', { detail: detail }));
        }

        const notificationCacheKey = 'crm:notification-count:v1:<?php echo (int) ($activeWorkspaceId ?? 0); ?>:<?php echo (int) ($activeUser['id'] ?? 0); ?>';
        const notificationCacheTtlMs = 30000;
        const notificationPollMs = 60000;

        function readCachedNotificationCount() {
            try {
                const cached = JSON.parse(window.sessionStorage.getItem(notificationCacheKey) || 'null');
                if (!cached || !cached.stored_at || (Date.now() - Number(cached.stored_at)) > notificationCacheTtlMs) {
                    return null;
                }
                return cached.data && typeof cached.data === 'object' ? cached.data : null;
            } catch (ignored) {
                return null;
            }
        }

        function cacheNotificationCount(data) {
            try {
                window.sessionStorage.setItem(notificationCacheKey, JSON.stringify({
                    stored_at: Date.now(),
                    data: data
                }));
            } catch (ignored) {}
        }

        function renderNotificationBadge(data, silent) {
            const protectedDemoOwnsToasts = !!(window.protectedDemoClientState && window.protectedDemoClientState.is_protected_demo);
            const notificationsTab = document.querySelector('.expandable-tab[data-tab="notifications"]');
            if (!notificationsTab) return;

            const count = Number(data && data.new_count ? data.new_count : 0);
            const countIncreased = previousNewCount !== null && count > previousNewCount;
            if (countIncreased && !silent && !protectedDemoOwnsToasts) {
                playNotificationSound();
                fetch('../api/notifications.php?action=list&limit=1&unread_only=true', {
                    credentials: 'same-origin',
                    headers: { 'X-CRM-Session-Passive': '1' }
                })
                    .then(function(r) { return r.json(); })
                    .then(function(payload) {
                        const list = payload.notifications || [];
                        if (list.length) {
                            showNotificationToast(list[0]);
                            triggerClarityForNotification(list[0]);
                        }
                    })
                    .catch(function() {});
            }
            previousNewCount = count;

            let badge = notificationsTab.querySelector('.tab-badge');
            const countLabel = count + (count === 1 ? ' new notification since your last visit' : ' new notifications since your last visit');
            notificationsTab.setAttribute('title', countLabel);
            notificationsTab.setAttribute('aria-label', countLabel);
            if (count > 0) {
                if (!badge) {
                    badge = document.createElement('span');
                    badge.className = 'tab-badge';
                    const icon = notificationsTab.querySelector('i');
                    if (icon && icon.nextSibling) {
                        notificationsTab.insertBefore(badge, icon.nextSibling);
                    } else {
                        notificationsTab.appendChild(badge);
                    }
                }
                badge.textContent = count > 99 ? '99+' : count;
                badge.style.display = 'block';
            } else if (badge) {
                badge.style.display = 'none';
            }
        }

        function updateNotificationBadge(options) {
            const forceRefresh = !!(options && options.force);
            const silent = !!(options && options.silent);
            if (document.hidden && !forceRefresh) {
                return Promise.resolve(null);
            }

            const cached = !forceRefresh ? readCachedNotificationCount() : null;
            if (cached) {
                renderNotificationBadge(cached, true);
                return Promise.resolve(cached);
            }

            return fetch('../api/notifications.php?action=count', {
                credentials: 'same-origin',
                headers: { 'X-CRM-Session-Passive': '1' }
            })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    cacheNotificationCount(data);
                    renderNotificationBadge(data, silent);
                    return data;
                })
                .catch(function() {
                    // Notification polling is best-effort; failed refreshes should not mark pages as broken.
                    return null;
                });
        }
        window.updateNotificationBadge = updateNotificationBadge;
        try {
            window.dispatchEvent(new CustomEvent('crm:notification-runtime-ready', {
                detail: { ready: true, timestamp: new Date().toISOString() }
            }));
        } catch (e) {
            try {
                const readyEvent = document.createEvent('CustomEvent');
                readyEvent.initCustomEvent('crm:notification-runtime-ready', false, false, { ready: true });
                window.dispatchEvent(readyEvent);
            } catch (ignored) {}
        }

        function deferUntilAfterCriticalLoad(callback, timeoutMs) {
            function schedule() {
                if ('requestIdleCallback' in window) {
                    window.requestIdleCallback(callback, { timeout: timeoutMs || 2500 });
                    return;
                }
                window.setTimeout(callback, 750);
            }

            if (document.readyState === 'complete') {
                schedule();
                return;
            }

            window.addEventListener('load', schedule, { once: true });
        }

        <?php if (empty($layoutWorkspace2faSetupPending)): ?>
            deferUntilAfterCriticalLoad(function() {
                updateNotificationBadge();
                window.setInterval(function() {
                    updateNotificationBadge();
                }, notificationPollMs);
                document.addEventListener('visibilitychange', function() {
                    if (!document.hidden) {
                        updateNotificationBadge({ force: true, silent: true });
                    }
                });
            }, 2500);
        <?php endif; ?>
    })();
    
    // Expandable Tabs Functionality
    (function() {
        const container = document.getElementById('expandable-tabs-container');
        if (!container) return;
        
        let selectedTab = null;
        const tabs = container.querySelectorAll('.expandable-tab');
        
        // Handle tab click - toggle selection (like React component)
        tabs.forEach(tab => {
            tab.addEventListener('click', function(e) {
                const href = this.getAttribute('data-href');
                if (!href) return;
                
                // Don't prevent navigation for logout
                if (href.includes('logout.php')) {
                    return;
                }
                
                // Toggle selection
                if (selectedTab === this) {
                    // Deselect if clicking the same tab
                    this.classList.remove('selected');
                    selectedTab = null;
                    e.preventDefault();
                } else {
                    // Deselect previous tab
                    if (selectedTab) {
                        selectedTab.classList.remove('selected');
                    }
                    
                    // Select new tab
                    this.classList.add('selected');
                    selectedTab = this;
                    e.preventDefault();
                    
                    // Navigate after animation
                    setTimeout(() => {
                        window.location.href = href;
                    }, 200);
                }
            });
        });
        
        // Click outside to deselect
        document.addEventListener('click', function(e) {
            if (!container.contains(e.target) && selectedTab) {
                selectedTab.classList.remove('selected');
                selectedTab = null;
            }
        });
    })();
    
    // Search Panel Toggle
    function toggleSearchPanel() {
        const panel = document.getElementById('search-panel');
        const overlay = document.getElementById('search-panel-overlay');
        const searchInput = document.getElementById('global-search');
        
        if (!panel || !overlay) return;
        
        const isActive = panel.classList.contains('active');
        
        if (isActive) {
            panel.classList.remove('active');
            overlay.classList.remove('active');
            document.body.style.overflow = '';
        } else {
            panel.classList.add('active');
            overlay.classList.add('active');
            document.body.style.overflow = 'hidden';
            // Focus search input when panel opens
            setTimeout(function() {
                if (searchInput) {
                    searchInput.focus();
                }
            }, 300);
        }
    }
    
    // Close search panel on Escape key
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            const panel = document.getElementById('search-panel');
            if (panel && panel.classList.contains('active')) {
                toggleSearchPanel();
            }
        }
    });
    
    // Global search with autocomplete
    (function() {
        const searchInput = document.getElementById('global-search');
        const suggestionsDiv = document.getElementById('search-suggestions');
        let searchTimeout;
        
        if (!searchInput || !suggestionsDiv) return;
        
        searchInput.addEventListener('input', function() {
            const query = this.value.trim();
            
            clearTimeout(searchTimeout);
            
            if (query.length < 2) {
                suggestionsDiv.innerHTML = '';
                suggestionsDiv.style.display = 'none';
                return;
            }
            
            searchTimeout = setTimeout(function() {
                fetch('../api/search.php?action=suggestions&q=' + encodeURIComponent(query))
                    .then(response => response.json())
                    .then(data => {
                        if (data.suggestions && data.suggestions.length > 0) {
                            let html = '<div style="padding: 4px;">';
                            html += '<div style="font-size: 12px; font-weight: 600; color: var(--charcoal-grey); text-transform: uppercase; letter-spacing: 0.5px; padding: 8px 12px; margin-bottom: 8px;">Results (' + data.suggestions.length + ')</div>';
                            data.suggestions.forEach(function(item, index) {
                                html += '<a href="' + item.url + '" onclick="toggleSearchPanel();" style="display: flex; align-items: center; gap: 14px; padding: 14px 16px; border-radius: 8px; text-decoration: none; color: inherit; transition: all 0.15s ease; margin-bottom: ' + (index < data.suggestions.length - 1 ? '6px' : '0') + '; border: 1px solid rgba(0, 102, 204, 0.08);" onmouseover="this.style.background=\'rgba(0, 102, 204, 0.08)\'; this.style.borderColor=\'rgba(0, 102, 204, 0.2)\'; this.style.transform=\'translateX(4px)\'" onmouseout="this.style.background=\'transparent\'; this.style.borderColor=\'rgba(0, 102, 204, 0.08)\'; this.style.transform=\'translateX(0)\'">';
                                html += '<span style="font-size: 24px; width: 32px; text-align: center; color: var(--accent-blue); opacity: 0.85;">' + item.icon + '</span>';
                                html += '<div style="flex: 1; min-width: 0;">';
                                html += '<div style="font-weight: 600; color: var(--midnight-black); font-size: 15px; line-height: 1.4; margin-bottom: 4px;">' + escapeHtml(item.title) + '</div>';
                                html += '<div style="color: var(--charcoal-grey); font-size: 13px; line-height: 1.4; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical;">' + escapeHtml(item.subtitle) + '</div>';
                                html += '</div>';
                                html += '<i class="fas fa-chevron-right" style="font-size: 12px; color: var(--charcoal-grey); opacity: 0.4;"></i>';
                                html += '</a>';
                            });
                            html += '</div>';
                            suggestionsDiv.innerHTML = html;
                            suggestionsDiv.style.display = 'block';
                        } else {
                            suggestionsDiv.innerHTML = '<div style="padding: var(--spacing-lg); text-align: center; color: var(--charcoal-grey);"><i class="fas fa-search" style="font-size: 48px; opacity: 0.2; margin-bottom: var(--spacing-md);"></i><p>No results found</p><p style="font-size: 13px; margin-top: 4px;">Try a different search term</p></div>';
                            suggestionsDiv.style.display = 'block';
                        }
                    })
                    .catch(error => {
                        console.error('Search error:', error);
                        suggestionsDiv.innerHTML = '';
                        suggestionsDiv.style.display = 'none';
                    });
            }, 300);
        });
        
        // Navigate on Enter
        searchInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                if (this.value.trim()) {
                    this.form.submit();
                }
            }
        });
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    })();

    window.SessionAutomationConfig = {
        enabled: <?php echo $sessionAutomationEnabled ? 'true' : 'false'; ?>,
        endpoint: <?php echo json_encode(apiUrl('session/postload.php')); ?>,
        csrfToken: <?php echo json_encode(\CRM\Security::getCsrfToken()); ?>,
        currentPage: <?php echo json_encode($sessionAutomationPage); ?>
    };

    (function () {
        var config = window.SessionAutomationConfig || {};
        var browserCooldownMs = 60000;
        var storageKey = 'crm:session-automation:last-run';

        if (!config.enabled || !config.endpoint || !config.csrfToken) {
            return;
        }

        function recentlyTriggered() {
            try {
                var lastRun = Number(window.sessionStorage.getItem(storageKey) || '0');
                return lastRun > 0 && (Date.now() - lastRun) < browserCooldownMs;
            } catch (error) {
                return false;
            }
        }

        function markTriggered() {
            try {
                window.sessionStorage.setItem(storageKey, String(Date.now()));
            } catch (error) {
                return;
            }
        }

        function runSessionAutomation() {
            if (recentlyTriggered()) {
                return;
            }

            markTriggered();
            window.fetch(config.endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-Token': config.csrfToken,
                    'X-CRM-Session-Passive': '1'
                },
                body: JSON.stringify({
                    csrf_token: config.csrfToken,
                    current_page: config.currentPage || ''
                }),
                keepalive: true
            }).then(function (response) {
                return response.json().catch(function () {
                    return null;
                });
            }).then(function (payload) {
                if (!payload || !payload.success) {
                    return null;
                }
                window.dispatchEvent(new CustomEvent('crm:session-automation-complete', {
                    detail: payload
                }));
                return payload;
            }).catch(function () {
                return null;
            });
        }

        window.addEventListener('load', function () {
            if ('requestIdleCallback' in window) {
                window.requestIdleCallback(runSessionAutomation, { timeout: 4000 });
                return;
            }
            window.setTimeout(runSessionAutomation, 1000);
        }, { once: true });
    })();

    (function () {
        var affordabilityModal = document.getElementById('workspace-affordability-modal');
        var donationModal = document.getElementById('workspace-donation-modal');
        var donationForm = donationModal ? donationModal.querySelector('[data-workspace-donation-form]') : null;
        var donationStatus = donationModal ? donationModal.querySelector('[data-workspace-donation-status]') : null;
        var donationSubmit = donationForm ? donationForm.querySelector('[data-workspace-donation-submit]') : null;
        var donationCurrency = donationForm ? donationForm.querySelector('[data-workspace-donation-currency]') : null;
        var donationMethod = donationForm ? donationForm.querySelector('[data-workspace-donation-method]') : null;
        var donationPhoneField = donationForm ? donationForm.querySelector('[data-workspace-donation-phone-field]') : null;
        var donationPhoneInput = donationForm ? donationForm.querySelector('[data-workspace-donation-phone]') : null;
        var donationMethodOptions = {};
        if (donationMethod) {
            try {
                donationMethodOptions = JSON.parse(donationMethod.getAttribute('data-workspace-donation-method-options') || '{}') || {};
            } catch (error) {
                donationMethodOptions = {};
            }
        }
        if (!affordabilityModal && !donationModal) {
            return;
        }

        function lockModalScroll() {
            document.body.style.overflow = 'hidden';
        }

        function releaseModalScroll() {
            if ((!affordabilityModal || affordabilityModal.hidden) && (!donationModal || donationModal.hidden)) {
                document.body.style.overflow = '';
            }
        }

        function openAffordabilityModal(event) {
            if (event && typeof event.preventDefault === 'function') {
                event.preventDefault();
            }
            if (!affordabilityModal) {
                return;
            }
            affordabilityModal.hidden = false;
            lockModalScroll();
        }

        function closeAffordabilityModal() {
            if (!affordabilityModal) {
                return;
            }
            affordabilityModal.hidden = true;
            releaseModalScroll();
        }

        function setDonationStatus(message, isError) {
            if (!donationStatus) {
                return;
            }
            donationStatus.textContent = message || '';
            donationStatus.classList.toggle('is-error', !!isError);
        }

        function syncDonationPaymentFields() {
            if (!donationCurrency || !donationMethod) {
                return false;
            }

            var currency = String(donationCurrency.value || '').toUpperCase();
            var modes = Array.isArray(donationMethodOptions[currency]) ? donationMethodOptions[currency] : [];
            var previousValue = donationMethod.value;
            donationMethod.innerHTML = '';

            if (!modes.length) {
                var emptyOption = document.createElement('option');
                emptyOption.value = '';
                emptyOption.textContent = 'No methods available';
                donationMethod.appendChild(emptyOption);
                donationMethod.disabled = true;
                donationCurrency.disabled = true;
                if (donationSubmit && donationSubmit.getAttribute('aria-busy') !== 'true') {
                    donationSubmit.disabled = true;
                }
                setDonationStatus('Donation checkout is temporarily unavailable for the selected currency.', true);
                if (donationPhoneField) {
                    donationPhoneField.hidden = true;
                }
                if (donationPhoneInput) {
                    donationPhoneInput.required = false;
                    donationPhoneInput.disabled = true;
                    donationPhoneInput.value = '';
                }
                return false;
            }

            modes.forEach(function (modeConfig) {
                var option = document.createElement('option');
                option.value = modeConfig.key || '';
                option.textContent = modeConfig.label || modeConfig.key || 'Payment method';
                option.setAttribute('data-requires-phone', modeConfig.requires_phone ? '1' : '0');
                donationMethod.appendChild(option);
            });

            var hasPreviousValue = modes.some(function (modeConfig) {
                return modeConfig && modeConfig.key === previousValue;
            });
            donationMethod.value = hasPreviousValue ? previousValue : (modes[0].key || '');
            donationMethod.disabled = false;
            donationCurrency.disabled = false;
            if (donationSubmit && donationSubmit.getAttribute('aria-busy') !== 'true') {
                donationSubmit.disabled = false;
            }
            if (donationStatus && donationStatus.textContent === 'Donation checkout is temporarily unavailable for the selected currency.') {
                setDonationStatus('', false);
            }

            var selectedMode = modes.find(function (modeConfig) {
                return modeConfig && modeConfig.key === donationMethod.value;
            }) || modes[0];
            var requiresPhone = !!(selectedMode && selectedMode.requires_phone);

            if (donationPhoneField) {
                donationPhoneField.hidden = !requiresPhone;
            }
            if (donationPhoneInput) {
                donationPhoneInput.required = requiresPhone;
                donationPhoneInput.disabled = !requiresPhone;
                if (!requiresPhone) {
                    donationPhoneInput.value = '';
                }
            }

            return true;
        }

        function donationInstructionText(data) {
            var instructions = data && data.instructions && typeof data.instructions === 'object'
                ? data.instructions
                : {};
            var parts = [];
            Object.keys(instructions).forEach(function (key) {
                var value = instructions[key];
                if (value === null || typeof value === 'undefined' || value === '') {
                    return;
                }
                parts.push(key.replace(/_/g, ' ') + ': ' + String(value));
            });

            return parts.length ? parts.join(' | ') : '';
        }

        function openDonationModal(event) {
            if (event && typeof event.preventDefault === 'function') {
                event.preventDefault();
            }
            if (!donationModal) {
                return;
            }
            closeAffordabilityModal();
            setDonationStatus('', false);
            syncDonationPaymentFields();
            donationModal.hidden = false;
            lockModalScroll();
        }

        function closeDonationModal() {
            if (!donationModal) {
                return;
            }
            donationModal.hidden = true;
            releaseModalScroll();
        }

        document.querySelectorAll('[data-workspace-affordability-open]').forEach(function (trigger) {
            trigger.addEventListener('click', openAffordabilityModal);
        });

        document.querySelectorAll('[data-workspace-affordability-close]').forEach(function (trigger) {
            trigger.addEventListener('click', closeAffordabilityModal);
        });

        document.querySelectorAll('[data-workspace-donation-open]').forEach(function (trigger) {
            trigger.addEventListener('click', openDonationModal);
        });

        document.querySelectorAll('[data-workspace-donation-close]').forEach(function (trigger) {
            trigger.addEventListener('click', closeDonationModal);
        });

        if (affordabilityModal) {
            affordabilityModal.addEventListener('click', function (event) {
                if (event.target === affordabilityModal) {
                    closeAffordabilityModal();
                }
            });
        }

        if (donationModal) {
            donationModal.addEventListener('click', function (event) {
                if (event.target === donationModal) {
                    closeDonationModal();
                }
            });
        }

        if (donationCurrency) {
            donationCurrency.addEventListener('change', syncDonationPaymentFields);
        }
        if (donationMethod) {
            donationMethod.addEventListener('change', syncDonationPaymentFields);
        }
        syncDonationPaymentFields();

            if (donationForm && window.fetch) {
            donationForm.addEventListener('submit', function (event) {
                event.preventDefault();
                if (!syncDonationPaymentFields()) {
                    return;
                }
                var selectedOption = donationMethod ? donationMethod.options[donationMethod.selectedIndex] : null;
                var requiresPhone = selectedOption && selectedOption.getAttribute('data-requires-phone') === '1';
                if (requiresPhone && donationPhoneInput && !donationPhoneInput.value.trim()) {
                    setDonationStatus('Enter the M-Pesa phone number to start this donation.', true);
                    donationPhoneInput.focus();
                    return;
                }
                setDonationStatus('Starting checkout...', false);
                if (donationSubmit) {
                    donationSubmit.disabled = true;
                    donationSubmit.setAttribute('aria-busy', 'true');
                }

                window.fetch(donationForm.getAttribute('action') || '', {
                    method: 'POST',
                    body: new FormData(donationForm),
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                }).then(function (response) {
                    return response.json().catch(function () {
                        return { success: false, error: response.ok ? 'Checkout response was empty.' : 'Checkout failed.' };
                    }).then(function (payload) {
                        if (!response.ok || !payload || payload.success === false) {
                            throw new Error((payload && payload.error) || 'Checkout failed.');
                        }
                        return payload;
                    });
                }).then(function (payload) {
                    var data = payload.data || {};
                    var redirectUrl = data.authorization_url || data.checkout_url || '';
                    if (redirectUrl) {
                        setDonationStatus('Opening checkout...', false);
                        window.location.href = redirectUrl;
                        return;
                    }
                    setDonationStatus(data.display_text || 'Donation checkout created. Follow the payment instructions returned by Paystack.', false);
                    var instructionText = donationInstructionText(data);
                    if (instructionText) {
                        setDonationStatus((data.display_text || 'Donation checkout created.') + ' ' + instructionText, false);
                    }
                }).catch(function (error) {
                    setDonationStatus(error && error.message ? error.message : 'Donation checkout failed.', true);
                }).finally(function () {
                    if (donationSubmit) {
                        donationSubmit.removeAttribute('aria-busy');
                    }
                    syncDonationPaymentFields();
                });
            });
        }

        document.addEventListener('keydown', function (event) {
            if (event.key !== 'Escape') {
                return;
            }
            if (affordabilityModal && !affordabilityModal.hidden) {
                closeAffordabilityModal();
            }
            if (donationModal && !donationModal.hidden) {
                closeDonationModal();
            }
        });
    })();

    (function () {
        var walletBanner = document.getElementById('workspace-ai-wallet-banner');
        if (!walletBanner) {
            return;
        }

        var dismissedKey = 'crm:workspace-ai-wallet-banner-dismissed';
        var loginSeenKey = 'crm:workspace-ai-wallet-banner-login-seen-at';
        var autoDismissMs = 12000;

        function hideWalletBanner() {
            walletBanner.classList.add('is-hidden');
            window.setTimeout(function () {
                if (walletBanner && walletBanner.parentNode) {
                    walletBanner.parentNode.removeChild(walletBanner);
                }
            }, 280);
        }

        try {
            if (window.sessionStorage.getItem(dismissedKey) === '1') {
                hideWalletBanner();
                return;
            }

            var firstSeenAt = Number(window.sessionStorage.getItem(loginSeenKey) || '0');
            if (!firstSeenAt) {
                firstSeenAt = Date.now();
                window.sessionStorage.setItem(loginSeenKey, String(firstSeenAt));
            }

            var remainingMs = Math.max(1200, autoDismissMs - (Date.now() - firstSeenAt));
            window.setTimeout(function () {
                try {
                    window.sessionStorage.setItem(dismissedKey, '1');
                } catch (error) {
                    // Ignore storage failures; the visual dismissal still applies.
                }
                hideWalletBanner();
            }, remainingMs);
        } catch (error) {
            window.setTimeout(hideWalletBanner, autoDismissMs);
        }

        document.getElementById('workspace-ai-wallet-dismiss')?.addEventListener('click', function () {
            try {
                window.sessionStorage.setItem(dismissedKey, '1');
            } catch (error) {
                // Ignore storage failures; the visual dismissal still applies.
            }
            hideWalletBanner();
        });
    })();

    (function () {
        var planSummaryBanner = document.getElementById('workspace-plan-summary-banner');
        if (!planSummaryBanner) {
            return;
        }

        var workspaceId = planSummaryBanner.getAttribute('data-workspace-id') || '0';
        var dismissedKey = 'crm:workspace-plan-summary-banner-dismissed:' + workspaceId;
        var firstSeenKey = 'crm:workspace-plan-summary-banner-seen-at:' + workspaceId;
        var autoDismissMs = 30000;

        function hidePlanSummaryBanner() {
            planSummaryBanner.classList.add('is-hidden');
            window.setTimeout(function () {
                if (planSummaryBanner && planSummaryBanner.parentNode) {
                    planSummaryBanner.parentNode.removeChild(planSummaryBanner);
                }
            }, 280);
        }

        try {
            if (window.sessionStorage.getItem(dismissedKey) === '1') {
                hidePlanSummaryBanner();
                return;
            }

            var firstSeenAt = Number(window.sessionStorage.getItem(firstSeenKey) || '0');
            if (!firstSeenAt) {
                firstSeenAt = Date.now();
                window.sessionStorage.setItem(firstSeenKey, String(firstSeenAt));
            }

            var remainingMs = Math.max(1200, autoDismissMs - (Date.now() - firstSeenAt));
            window.setTimeout(function () {
                try {
                    window.sessionStorage.setItem(dismissedKey, '1');
                } catch (error) {
                    // Ignore storage failures; the visual dismissal still applies.
                }
                hidePlanSummaryBanner();
            }, remainingMs);
        } catch (error) {
            window.setTimeout(hidePlanSummaryBanner, autoDismissMs);
        }
    })();

    (function () {
        var modal = document.getElementById('workspace-billing-modal');
        if (!modal) {
            return;
        }

        var storageKey = 'crm:workspace-billing-prompt-shown';
        try {
            if (window.sessionStorage.getItem(storageKey) === '1') {
                return;
            }
            window.sessionStorage.setItem(storageKey, '1');
        } catch (error) {
            // Ignore storage failures and still show the prompt.
        }

        modal.style.display = 'flex';
        document.getElementById('workspace-billing-dismiss')?.addEventListener('click', function () {
            modal.style.display = 'none';
        });
        modal.addEventListener('click', function (event) {
            if (event.target === modal) {
                modal.style.display = 'none';
            }
        });
    })();
    </script>
</body>
</html>
