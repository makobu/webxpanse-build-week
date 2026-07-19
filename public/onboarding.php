<?php

require_once __DIR__ . '/_public_bootstrap.php';

use CRM\Auth;
use CRM\Database;
use CRM\Security;
use CRM\Session;
use CRM\Services\SaaSBillingService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceOnboardingService;

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

$service = new WorkspaceOnboardingService();
$service->createInProgress($workspaceId);
$error = null;
$activateCompassFree = static function () use ($workspaceId, $userId): void {
    try {
        (new SaaSBillingService())->activateCompassFreeSubscription($workspaceId, $userId);
    } catch (\Throwable $e) {
        error_log('Onboarding Compass Free activation failed: ' . $e->getMessage());
    }
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
            throw new \RuntimeException('Invalid security token. Please refresh and try again.');
        }

        $action = (string) ($_POST['action'] ?? 'save_step');
        if ($action === 'quick_start_complete') {
            $service->completeQuickStart($workspaceId, $userId, $_POST);
            $activateCompassFree();
            header('Location: ' . publicUrl('dashboard.php?onboarding=complete'));
            exit;
        }

        $step = max(1, min(WorkspaceOnboardingService::STEP_COUNT, (int) ($_POST['step'] ?? 1)));
        $state = $service->saveStep($workspaceId, $userId, $step, $_POST);
        if (!empty($state['is_completed'])) {
            $activateCompassFree();
            header('Location: ' . publicUrl('dashboard.php?onboarding=complete'));
            exit;
        }

        $nextStep = (int) ($state['current_step'] ?? min(WorkspaceOnboardingService::STEP_COUNT, $step + 1));
        header('Location: onboarding.php?step=' . $nextStep . '&saved=1');
        exit;
    } catch (\Throwable $e) {
        $error = $e->getMessage();
    }
}

$state = $service->getState($workspaceId, $userId);
$readiness = (array) ($state['readiness'] ?? []);
$profile = (array) ($readiness['profile'] ?? []);
$currentStep = 1;
$briefProgress = !empty($readiness['profile_ready']) ? 100 : 0;

if (!function_exists('onboardingValue')) {
    function onboardingValue(array $source, string $key, string $fallback = ''): string
    {
        return htmlspecialchars((string) ($source[$key] ?? $fallback), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('onboardingChecked')) {
    function onboardingChecked(bool $condition): string
    {
        return $condition ? 'checked' : '';
    }
}

if (!function_exists('onboardingSelected')) {
    function onboardingSelected(string $value, string $expected): string
    {
        return $value === $expected ? 'selected' : '';
    }
}

if (!function_exists('onboardingStatusClass')) {
    function onboardingStatusClass(bool $ready): string
    {
        return $ready ? 'is-done' : '';
    }
}

if (!function_exists('onboardingAutomationLabel')) {
    function onboardingAutomationLabel(string $value): string
    {
        return [
            'guide_me' => 'Guide me',
            'work_with_me' => 'Work with me',
            'run_quietly' => 'Run quietly',
        ][$value] ?? 'Not chosen yet';
    }
}

if (!function_exists('onboardingHelp')) {
    function onboardingHelp(string $text, string $label = 'More context'): string
    {
        $safeLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
        $safeText = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        return '<span class="onboarding-help" tabindex="0" aria-label="' . $safeLabel . '"><span aria-hidden="true">?</span><span class="onboarding-tooltip" role="tooltip">' . $safeText . '</span></span>';
    }
}

$csrf = Security::getCsrfToken();
$pageTitle = 'Business Setup - ' . brandProductName();
$companyName = trim((string) ($profile['company_name'] ?? ''));
$showSignupCelebration = !empty($_GET['signup_success']);
$heroKicker = $showSignupCelebration ? 'Workspace created' : 'Workspace setup';
$heroTitle = $showSignupCelebration
    ? 'Your new workspace is ready'
    : (!empty($state['is_completed']) ? 'Your workspace is ready' : 'Start with the basics');
$heroCopy = $showSignupCelebration
    ? 'Business setup is intentionally light. Add the company identity now, then start using the CRM while deeper setup waits in Settings and AI Coach.'
    : 'Add the company identity now. Products, voice, channels, and AI context can wait until Settings or AI Coach asks for them.';

ob_start();
?>

<link rel="stylesheet" href="<?php echo htmlspecialchars(assetUrl('css/onboarding.css') . '?v=' . urlencode(APP_VERSION . '-brief-card-cleanup-overflow')); ?>">

<div class="onboarding-shell onboarding-brief-shell <?php echo $showSignupCelebration ? 'onboarding-signup-celebration' : ''; ?>" data-onboarding-step="<?php echo (int) $currentStep; ?>">
    <section class="onboarding-hero onboarding-brief-hero <?php echo $showSignupCelebration ? 'is-celebrating' : ''; ?>">
        <div>
            <p class="onboarding-kicker"><?php echo htmlspecialchars($heroKicker); ?></p>
            <h1 class="onboarding-title"><?php echo htmlspecialchars($heroTitle); ?></h1>
            <p class="onboarding-copy"><?php echo htmlspecialchars($heroCopy); ?></p>
            <?php if ($showSignupCelebration): ?>
                <div class="onboarding-celebration-meta" aria-label="Business setup">
                    <span>Business setup</span>
                    <span>Company basics only</span>
                    <span>Everything else can wait</span>
                </div>
                <div class="onboarding-alert onboarding-alert-spaced onboarding-celebration-alert">Workspace created. Start by giving Clarity the business context.</div>
            <?php endif; ?>
            <?php if (!empty($_GET['saved'])): ?><div class="onboarding-alert onboarding-alert-spaced">Saved. You can enter the workspace.</div><?php endif; ?>
            <?php if ($error): ?><div class="onboarding-alert error onboarding-alert-spaced"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
        </div>
    </section>

    <section class="onboarding-brief-layout">
        <aside class="onboarding-brief-sidebar">
            <section class="onboarding-panel onboarding-progress onboarding-brief-score" aria-label="Setup progress">
                <p class="onboarding-kicker">Required now</p>
                <h2><?php echo max(0, min(100, $briefProgress)); ?>%</h2>
                <div class="onboarding-meter"><span style="width: <?php echo max(0, min(100, $briefProgress)); ?>%;"></span></div>
                <div class="onboarding-status-list">
                    <div class="onboarding-status-item"><span class="onboarding-dot <?php echo onboardingStatusClass(!empty($readiness['profile_ready'])); ?>"></span><span>Company name</span></div>
                    <div class="onboarding-status-item"><span class="onboarding-dot is-done"></span><span>Products can wait</span></div>
                    <div class="onboarding-status-item"><span class="onboarding-dot is-done"></span><span>Voice can wait</span></div>
                    <div class="onboarding-status-item"><span class="onboarding-dot is-done"></span><span>AI setup can wait</span></div>
                </div>
            </section>
        </aside>

        <main class="onboarding-panel onboarding-brief-main">
            <p class="onboarding-kicker">Company basics</p>
            <div class="onboarding-heading-row"><h2>Set up the workspace identity</h2><?php echo onboardingHelp('These basics make the CRM usable. Deeper context can be refined later from Settings.'); ?></div>
            <p class="onboarding-microcopy">Only the company name is required.</p>
            <form method="POST" class="onboarding-brief-form">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                <input type="hidden" name="action" value="quick_start_complete">
                <div class="onboarding-form-grid">
                    <div class="onboarding-field"><label>Company name</label><input name="company_name" value="<?php echo onboardingValue($profile, 'company_name'); ?>" required></div>
                    <div class="onboarding-field"><label>Country or location</label><input name="company_location" value="<?php echo onboardingValue($profile, 'company_location'); ?>"></div>
                    <div class="onboarding-field"><label>Website</label><input name="company_website" value="<?php echo onboardingValue($profile, 'company_website'); ?>"></div>
                    <div class="onboarding-field"><label>Industry</label><input name="company_industry" value="<?php echo onboardingValue($profile, 'company_industry'); ?>"></div>
                    <div class="onboarding-field onboarding-field-full"><label>Short company description <?php echo onboardingHelp('Optional. A plain-language sentence helps summaries and drafts later, but you can leave it blank now.'); ?></label><textarea class="textarea-standard" name="company_description"><?php echo onboardingValue($profile, 'company_description'); ?></textarea></div>
                </div>

                <div class="onboarding-actions">
                    <button class="onboarding-primary" type="submit">Enter your new workspace</button>
                    <a class="onboarding-secondary" href="settings.php?tab=company">Open company settings</a>
                </div>
            </form>
        </main>
    </section>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
