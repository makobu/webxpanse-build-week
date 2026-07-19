<?php

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/constants.php';

use CRM\Auth;
use CRM\Database;
use CRM\Security;
use CRM\Services\DefaultWorkspaceOwnerSupportService;
use CRM\Services\LaunchPackageCatalogService;
use CRM\Services\OwnerHelpExpertService;
use CRM\Services\OwnerHelpOfferingService;
use CRM\Services\OwnerHelpQuoteService;
use CRM\Services\WorkspaceContext;
use CRM\Session;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);
$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
$service = new DefaultWorkspaceOwnerSupportService();
$offeringService = new OwnerHelpOfferingService();
$expertService = new OwnerHelpExpertService();
$quoteService = new OwnerHelpQuoteService($service);
$notice = trim((string) ($_GET['notice'] ?? ''));
$error = trim((string) ($_GET['error'] ?? ''));
$activeTab = trim((string) ($_GET['tab'] ?? 'support'));
$validTabs = ['support', 'setup', 'experts', 'requests'];
$activeTab = in_array($activeTab, $validTabs, true) ? $activeTab : 'support';
$packageSalesPrefill = [];
if (trim((string) ($_GET['prefill'] ?? '')) === 'package_sales') {
    $packageCode = trim((string) ($_GET['package_code'] ?? ''));
    $package = (new LaunchPackageCatalogService())->packageForCode($packageCode);
    if ($package !== null) {
        $packageName = (string) ($package['display_name'] ?? $package['name'] ?? 'Custom package');
        $packageBestFor = trim((string) ($package['best_for'] ?? $package['ideal_customer'] ?? ''));
        $packageSummary = trim((string) ($package['summary'] ?? $package['description'] ?? ''));
        $packagePrice = trim((string) ($package['price_short'] ?? $package['price'] ?? 'Custom pricing'));
        $messageLines = [
            'I would like to discuss the ' . $packageName . ' workspace package.',
            '',
            'Package code: ' . $packageCode,
        ];
        if ($packageBestFor !== '') {
            $messageLines[] = 'Best for: ' . $packageBestFor;
        }
        if ($packageSummary !== '') {
            $messageLines[] = 'Summary: ' . $packageSummary;
        }
        if ($packagePrice !== '') {
            $messageLines[] = 'Listed price: ' . $packagePrice;
        }
        $messageLines[] = '';
        $messageLines[] = 'Please contact me with next steps, pricing, and rollout options.';
        $packageSalesPrefill = [
            'code' => $packageCode,
            'name' => $packageName,
            'subject' => $packageName . ' package request',
            'message' => trim(implode("\n", $messageLines)),
            'lane' => 'account_manager',
        ];
        $activeTab = 'setup';
    }
}

function ownerSupportRedirect(array $params = []): void
{
    header('Location: owner_support.php' . ($params !== [] ? '?' . http_build_query($params) : ''));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!Security::validateCSRF((string) ($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Security check failed. Refresh the page and try again.');
        }
        $action = trim((string) ($_POST['action'] ?? ''));
        if ($action === 'create_help_request') {
            $lane = (string) ($_POST['lane'] ?? 'system_error');
            $case = $service->createHelpRequest(
                $workspaceId,
                $userId,
                $lane,
                (string) ($_POST['subject'] ?? ''),
                (string) ($_POST['message'] ?? ''),
                [
                    'offering_id' => (int) ($_POST['offering_id'] ?? 0),
                    'expert_profile_id' => (int) ($_POST['expert_profile_id'] ?? 0),
                    'preferred_contact_method' => (string) ($_POST['preferred_contact_method'] ?? ''),
                    'preferred_time' => (string) ($_POST['preferred_time'] ?? ''),
                    'owner_goal' => (string) ($_POST['message'] ?? ''),
                ]
            );
            ownerSupportRedirect([
                'tab' => 'requests',
                'case_id' => (int) ($case['id'] ?? 0),
                'notice' => 'Help request opened.',
            ]);
        }
        if ($action === 'reply_owner') {
            $caseId = (int) ($_POST['case_id'] ?? 0);
            $service->addOwnerReply($workspaceId, $userId, $caseId, (string) ($_POST['message'] ?? ''));
            ownerSupportRedirect(['tab' => 'requests', 'case_id' => $caseId, 'notice' => 'Reply sent.']);
        }
        if ($action === 'accept_quote') {
            $caseId = (int) ($_POST['case_id'] ?? 0);
            $quoteId = (int) ($_POST['quote_id'] ?? 0);
            $quoteService->acceptQuote($workspaceId, $userId, $caseId, $quoteId);
            ownerSupportRedirect(['tab' => 'requests', 'case_id' => $caseId, 'notice' => 'Quote accepted.']);
        }
        if ($action === 'request_quote_changes') {
            $caseId = (int) ($_POST['case_id'] ?? 0);
            $quoteId = (int) ($_POST['quote_id'] ?? 0);
            $quoteService->requestChanges($workspaceId, $userId, $caseId, $quoteId, (string) ($_POST['change_note'] ?? ''));
            ownerSupportRedirect(['tab' => 'requests', 'case_id' => $caseId, 'notice' => 'Quote change request sent.']);
        }
        throw new RuntimeException('Unsupported support action.');
    } catch (Throwable $e) {
        $params = ['tab' => $activeTab, 'error' => $e->getMessage()];
        if (!empty($_POST['case_id'])) {
            $params['case_id'] = (int) $_POST['case_id'];
            $params['tab'] = 'requests';
        }
        ownerSupportRedirect($params);
    }
}

$cases = [];
$selectedCase = null;
$messages = [];
$offerings = [];
$experts = [];
$activeQuote = null;
try {
    $cases = $service->listOwnerCases($workspaceId, $userId);
    $offerings = $offeringService->activeOfferings();
    $experts = $expertService->activeExperts();
    $selectedCaseId = (int) ($_GET['case_id'] ?? 0);
    if ($selectedCaseId <= 0 && $cases !== []) {
        $selectedCaseId = (int) ($cases[0]['id'] ?? 0);
    }
    if ($selectedCaseId > 0) {
        $selectedCase = $service->ownerCase($workspaceId, $userId, $selectedCaseId);
        $messages = $service->messagesForOwner($workspaceId, $userId, $selectedCaseId);
        $activeQuote = $quoteService->activeQuoteForOwnerCase($workspaceId, $userId, $selectedCaseId);
    }
} catch (Throwable $e) {
    $error = $error !== '' ? $error : $e->getMessage();
}

$csrfToken = Security::getCsrfToken();
$h = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$statusLabel = static fn(string $status): string => ucwords(str_replace('_', ' ', $status));
$laneLabel = static fn(string $lane): string => match ($lane) {
    'billing_access' => 'Billing access',
    'account_access' => 'Account access',
    'setup_help' => 'Setup help',
    'installation_help' => 'Installation help',
    'strategy_mentor' => 'Strategy mentor',
    'account_manager' => 'Account manager',
    default => 'System issue',
};
$pricingLabel = static fn(string $state): string => ucwords(str_replace('_', ' ', $state));
$quoteStatusLabel = static fn(string $state): string => ucwords(str_replace('_', ' ', $state));
$formatDate = static function (?string $value): string {
    if ($value === null || trim($value) === '') {
        return 'Not yet';
    }
    $ts = strtotime($value);
    return $ts === false ? $value : date('M j, Y H:i', $ts);
};
$formatDateOnly = static function (?string $value): string {
    if ($value === null || trim($value) === '') {
        return 'No expiry set';
    }
    $ts = strtotime($value);
    return $ts === false ? $value : date('M j, Y', $ts);
};
$formatMoney = static fn(string $currency, float $amount): string => trim($currency) . ' ' . number_format($amount, 2);
$ownerHelpMediaUrl = static function (?string $path): string {
    $path = trim((string) $path);
    if ($path === '') {
        return '';
    }
    if (preg_match('/^https?:\/\//i', $path)) {
        return $path;
    }
    if (str_starts_with($path, 'uploads/')) {
        return '../' . $path;
    }
    return assetUrl($path);
};
$ownerHelpInitials = static function (string $name): string {
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $letters = '';
    foreach ($parts as $part) {
        if ($part !== '') {
            $letters .= strtoupper(substr($part, 0, 1));
        }
        if (strlen($letters) >= 2) {
            break;
        }
    }
    return $letters !== '' ? $letters : 'EX';
};
$requestTypeClass = static fn(string $commercial): string => $commercial === 'free' ? 'is-free' : 'is-paid';

$pageTitle = 'Help Center - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<style>
    .owner-help-page { background:#f6f8fb; min-height:calc(100vh - 80px); }
    .owner-help-header { display:flex; justify-content:space-between; gap:1rem; align-items:flex-start; flex-wrap:wrap; }
    .owner-help-boundary { max-width:760px; margin:.45rem 0 0; color:#475569; line-height:1.55; }
    .owner-help-tabs { display:flex; gap:.45rem; flex-wrap:wrap; margin:1rem 0; }
    .owner-help-tab { display:inline-flex; align-items:center; gap:.45rem; min-height:2.45rem; border:1px solid #cbd5e1; border-radius:999px; background:#fff; color:#334155; padding:.52rem .85rem; font-weight:800; text-decoration:none; }
    .owner-help-tab.is-active { border-color:#2563eb; background:#eff6ff; color:#1d4ed8; }
    .owner-help-grid { display:grid; grid-template-columns:minmax(0, 1fr) minmax(280px, 360px); gap:1rem; align-items:start; }
    .owner-help-panel, .owner-help-card { background:#fff; border:1px solid #e2e8f0; border-radius:8px; box-shadow:0 1px 2px rgba(15,23,42,.04); }
    .owner-help-panel { padding:1rem; }
    .owner-help-panel h2, .owner-help-panel h3 { margin:0; color:#0f172a; letter-spacing:0; }
    .owner-help-panel p { color:#475569; line-height:1.55; }
    .owner-help-choice-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(min(100%,240px),1fr)); gap:.8rem; margin-top:1rem; }
    .owner-help-card { padding:1rem; display:grid; gap:.7rem; }
    .owner-help-card h3 { font-size:1rem; }
    .owner-help-chip-row { display:flex; flex-wrap:wrap; gap:.4rem; align-items:center; }
    .owner-help-chip { display:inline-flex; align-items:center; border:1px solid #dbe4f0; border-radius:999px; background:#f8fafc; color:#334155; padding:.24rem .55rem; font-size:.76rem; font-weight:800; line-height:1.2; }
    .owner-help-chip.is-free { border-color:#bbf7d0; background:#f0fdf4; color:#166534; }
    .owner-help-chip.is-paid { border-color:#fed7aa; background:#fff7ed; color:#9a3412; }
    .owner-help-form { display:grid; gap:.85rem; margin-top:.9rem; }
    .owner-help-form-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(min(100%,240px),1fr)); gap:.85rem; }
    .owner-help-field { display:grid; gap:.35rem; color:#334155; font-size:.86rem; font-weight:760; }
    .owner-help-field input, .owner-help-field select, .owner-help-field textarea { box-sizing:border-box; width:100%; border:1px solid #cbd5e1; border-radius:8px; padding:.72rem .78rem; color:#0f172a; font-size:.92rem; }
    .owner-help-field textarea { min-height:7.5rem; resize:vertical; line-height:1.5; }
    .owner-help-alert { padding:.75rem; border-radius:8px; margin-bottom:1rem; }
    .owner-help-alert.notice { background:#ecfdf5; color:#166534; border:1px solid #bbf7d0; }
    .owner-help-alert.error { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; }
    .owner-help-request-list { display:grid; gap:.55rem; }
    .owner-help-request-link { display:block; padding:.75rem; border:1px solid #e2e8f0; border-radius:8px; color:#0f172a; text-decoration:none; background:#fff; }
    .owner-help-request-link.active { border-color:#2563eb; background:#eff6ff; }
    .owner-help-meta { display:flex; gap:.4rem; flex-wrap:wrap; color:#64748b; font-size:.82rem; margin-top:.35rem; }
    .owner-help-thread { display:grid; gap:.75rem; margin:1rem 0; }
    .owner-help-message { border:1px solid #e2e8f0; border-radius:8px; padding:.75rem; max-width:760px; }
    .owner-help-message.inbound { background:#f8fafc; }
    .owner-help-message.outbound { background:#ecfdf5; margin-left:auto; }
    .owner-help-message-head { color:#475569; font-size:.78rem; margin-bottom:.35rem; display:flex; justify-content:space-between; gap:.75rem; }
    .owner-help-expert-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(min(100%,300px),1fr)); gap:1rem; margin-top:1rem; }
    .owner-help-expert-card { padding:0; overflow:hidden; display:grid; gap:0; }
    .owner-help-expert-media { position:relative; min-height:168px; background:linear-gradient(135deg,#e0f2fe,#f8fafc 55%,#dcfce7); display:flex; align-items:flex-end; justify-content:flex-start; padding:1rem; }
    .owner-help-expert-photo { width:132px; height:132px; border-radius:18px; object-fit:cover; box-shadow:0 18px 38px rgba(15,23,42,.18); border:4px solid rgba(255,255,255,.8); background:#fff; }
    .owner-help-expert-initials { width:132px; height:132px; border-radius:18px; display:grid; place-items:center; background:#0f172a; color:#f8fafc; font-size:2rem; font-weight:900; box-shadow:0 18px 38px rgba(15,23,42,.18); border:4px solid rgba(255,255,255,.8); }
    .owner-help-expert-body { padding:1rem; display:grid; gap:.75rem; }
    .owner-help-expert-body h3 { font-size:1.08rem; margin:0; }
    .owner-help-expert-headline { margin:0; color:#334155; line-height:1.5; min-height:3rem; }
    .owner-help-expert-areas { color:#64748b; font-size:.84rem; line-height:1.45; }
    .owner-help-quote-card { border:1px solid #bfdbfe; background:#eff6ff; border-radius:8px; padding:1rem; margin-top:1rem; display:grid; gap:.8rem; }
    .owner-help-quote-head { display:flex; justify-content:space-between; gap:1rem; flex-wrap:wrap; align-items:flex-start; }
    .owner-help-quote-total { color:#0f172a; font-size:1.3rem; font-weight:900; }
    .owner-help-quote-items { display:grid; gap:.5rem; }
    .owner-help-quote-item { display:grid; grid-template-columns:minmax(0,1fr) auto; gap:.75rem; border-top:1px solid #cfe3ff; padding-top:.5rem; color:#334155; }
    .owner-help-quote-actions { display:flex; gap:.6rem; flex-wrap:wrap; align-items:flex-end; }
    .owner-help-empty { border:1px dashed #cbd5e1; border-radius:8px; padding:1rem; background:#f8fafc; color:#64748b; }
    @media (max-width: 920px) { .owner-help-grid { grid-template-columns:1fr; } }
    @media (max-width: 560px) {
        .owner-help-tab, .owner-help-form .btn-premium-primary, .owner-help-form .btn-premium-secondary { width:100%; justify-content:center; text-align:center; }
        .owner-help-message { max-width:100%; }
        .owner-help-expert-media { min-height:150px; }
        .owner-help-expert-photo, .owner-help-expert-initials { width:112px; height:112px; }
        .owner-help-quote-item { grid-template-columns:1fr; }
        .owner-help-quote-actions { display:grid; }
    }
</style>

<div class="page-premium owner-help-page">
    <div class="container">
        <div class="page-header owner-help-header">
            <div>
                <h1>Help Center</h1>
                <p class="owner-help-boundary">System errors, broken access, and data problems are free support. Setup, installation, strategy, and account-manager help are paid services reviewed by the team before any quote is accepted.</p>
            </div>
            <div class="page-header-actions">
                <a href="docs.php" class="btn-premium-secondary"><i class="fas fa-book" aria-hidden="true"></i> Documentation</a>
            </div>
        </div>

        <?php if ($notice !== ''): ?><div class="owner-help-alert notice"><?php echo $h($notice); ?></div><?php endif; ?>
        <?php if ($error !== ''): ?><div class="owner-help-alert error"><?php echo $h($error); ?></div><?php endif; ?>

        <nav class="owner-help-tabs" aria-label="Help Center sections">
            <?php foreach ([
                'support' => ['System issue', 'fa-exclamation-triangle'],
                'setup' => ['Setup help', 'fa-tools'],
                'experts' => ['Hire an expert', 'fa-user-tie'],
                'requests' => ['My requests', 'fa-inbox'],
            ] as $tabKey => [$tabLabel, $tabIcon]): ?>
                <a class="owner-help-tab <?php echo $activeTab === $tabKey ? 'is-active' : ''; ?>" href="owner_support.php?tab=<?php echo $h($tabKey); ?>">
                    <i class="fas <?php echo $h($tabIcon); ?>" aria-hidden="true"></i>
                    <span><?php echo $h($tabLabel); ?></span>
                </a>
            <?php endforeach; ?>
        </nav>

        <?php if ($activeTab === 'support'): ?>
            <div class="owner-help-grid">
                <main class="owner-help-panel">
                    <h2>Report a system issue</h2>
                    <p>Use this for errors, broken features, login or billing access problems, and unexpected data behavior.</p>
                    <div class="owner-help-chip-row">
                        <span class="owner-help-chip is-free">Free support</span>
                        <span class="owner-help-chip">System not working as intended</span>
                    </div>
                    <form method="POST" class="owner-help-form">
                        <input type="hidden" name="csrf_token" value="<?php echo $h($csrfToken); ?>">
                        <input type="hidden" name="action" value="create_help_request">
                        <div class="owner-help-form-grid">
                            <label class="owner-help-field">Issue type
                                <select name="lane">
                                    <option value="system_error">System error or broken feature</option>
                                    <option value="billing_access">Billing or payment access issue</option>
                                    <option value="account_access">Login, owner, or account access issue</option>
                                </select>
                            </label>
                            <label class="owner-help-field">Preferred contact
                                <input type="text" name="preferred_contact_method" placeholder="Email, WhatsApp, or phone">
                            </label>
                        </div>
                        <label class="owner-help-field">Subject
                            <input type="text" name="subject" maxlength="255" required placeholder="What is broken?">
                        </label>
                        <label class="owner-help-field">What happened?
                            <textarea name="message" required placeholder="Tell us the page, action, expected result, and what happened instead."></textarea>
                        </label>
                        <button type="submit" class="btn-premium-primary">Send free support request</button>
                    </form>
                </main>
                <aside class="owner-help-panel">
                    <h3>Recent requests</h3>
                    <div class="owner-help-request-list" style="margin-top:.75rem;">
                        <?php foreach (array_slice($cases, 0, 5) as $case): ?>
                            <?php $help = (array) ($case['help_request'] ?? []); ?>
                            <a class="owner-help-request-link" href="owner_support.php?tab=requests&case_id=<?php echo (int) ($case['id'] ?? 0); ?>">
                                <strong><?php echo $h($case['owner_subject'] ?? 'Help request'); ?></strong>
                                <span class="owner-help-meta">
                                    <span><?php echo $h($laneLabel((string) ($help['lane'] ?? 'system_error'))); ?></span>
                                    <span>/</span>
                                    <span><?php echo $h($statusLabel((string) ($case['status'] ?? 'open'))); ?></span>
                                </span>
                            </a>
                        <?php endforeach; ?>
                        <?php if ($cases === []): ?><div class="owner-help-empty">No help requests yet.</div><?php endif; ?>
                    </div>
                </aside>
            </div>
        <?php elseif ($activeTab === 'setup'): ?>
            <?php
            $isPackageSalesPrefill = $packageSalesPrefill !== [];
            $setupLane = $isPackageSalesPrefill ? (string) ($packageSalesPrefill['lane'] ?? 'account_manager') : 'setup_help';
            $setupSubject = $isPackageSalesPrefill ? (string) ($packageSalesPrefill['subject'] ?? '') : '';
            $setupMessage = $isPackageSalesPrefill ? (string) ($packageSalesPrefill['message'] ?? '') : '';
            ?>
            <main class="owner-help-panel">
                <h2><?php echo $isPackageSalesPrefill ? 'Contact sales' : 'Get setup help'; ?></h2>
                <p><?php echo $isPackageSalesPrefill ? 'Send this request to the internal team so they can follow up inside the system.' : 'Use this for configuration, installation, imports, integrations, automations, finance setup, messaging setup, and launch readiness.'; ?></p>
                <div class="owner-help-chip-row">
                    <span class="owner-help-chip is-paid"><?php echo $isPackageSalesPrefill ? 'Sales handoff' : 'Paid setup help'; ?></span>
                    <span class="owner-help-chip">Quote required before work starts</span>
                    <?php if ($isPackageSalesPrefill): ?><span class="owner-help-chip"><?php echo $h($packageSalesPrefill['name'] ?? 'Custom package'); ?></span><?php endif; ?>
                </div>
                <form method="POST" class="owner-help-form">
                    <input type="hidden" name="csrf_token" value="<?php echo $h($csrfToken); ?>">
                    <input type="hidden" name="action" value="create_help_request">
                    <input type="hidden" name="lane" value="<?php echo $h($setupLane); ?>">
                    <div class="owner-help-form-grid">
                        <?php if ($isPackageSalesPrefill): ?>
                            <input type="hidden" name="offering_id" value="0">
                            <label class="owner-help-field">Preferred contact
                                <input type="text" name="preferred_contact_method" placeholder="Email, WhatsApp, or phone">
                            </label>
                        <?php else: ?>
                            <label class="owner-help-field">Setup package
                                <select name="offering_id">
                                    <?php foreach ($offerings as $offering): ?>
                                        <option value="<?php echo (int) ($offering['id'] ?? 0); ?>"><?php echo $h($offering['label'] ?? 'Setup help'); ?> - <?php echo $h($offering['pricing_label'] ?? 'Quote required'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                        <?php endif; ?>
                        <label class="owner-help-field">Preferred time
                            <input type="text" name="preferred_time" placeholder="Morning, afternoon, this week, etc.">
                        </label>
                    </div>
                    <label class="owner-help-field">Subject
                        <input type="text" name="subject" maxlength="255" required placeholder="<?php echo $isPackageSalesPrefill ? 'Which package are you interested in?' : 'What setup do you need completed?'; ?>" value="<?php echo $h($setupSubject); ?>">
                    </label>
                    <label class="owner-help-field"><?php echo $isPackageSalesPrefill ? 'Sales request' : 'Setup goal'; ?>
                        <textarea name="message" required placeholder="<?php echo $isPackageSalesPrefill ? 'Share team size, rollout needs, procurement timing, or any questions.' : 'Describe what you want configured, installed, tested, imported, or reviewed.'; ?>"><?php echo $h($setupMessage); ?></textarea>
                    </label>
                    <button type="submit" class="btn-premium-primary"><?php echo $isPackageSalesPrefill ? 'Request sales follow-up' : 'Request setup quote'; ?></button>
                </form>
            </main>
        <?php elseif ($activeTab === 'experts'): ?>
            <main class="owner-help-panel">
                <h2>Hire an expert</h2>
                <p>Choose an internal verified specialist for strategy, installation, setup, or account-manager support.</p>
                <div class="owner-help-chip-row">
                    <span class="owner-help-chip is-paid">Paid expert help</span>
                    <span class="owner-help-chip">Internal verified team</span>
                </div>
                <div class="owner-help-expert-grid">
                    <?php foreach ($experts as $expert): ?>
                        <article class="owner-help-card owner-help-expert-card">
                            <?php
                                $expertName = (string) ($expert['name'] ?? 'Internal expert');
                                $photoUrl = $ownerHelpMediaUrl((string) ($expert['profile_photo_path'] ?? ''));
                                $verifiedSkills = array_values(array_filter((array) ($expert['skills'] ?? []), static fn($skill): bool => !empty($skill['is_verified'])));
                            ?>
                            <div class="owner-help-expert-media" aria-hidden="true">
                                <?php if ($photoUrl !== ''): ?>
                                    <img class="owner-help-expert-photo" src="<?php echo $h($photoUrl); ?>" alt="">
                                <?php else: ?>
                                    <div class="owner-help-expert-initials"><?php echo $h($ownerHelpInitials($expertName)); ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="owner-help-expert-body">
                                <div>
                                    <h3><?php echo $h($expertName); ?></h3>
                                    <div class="owner-help-meta">
                                        <span><?php echo $h($expert['role_label'] ?? 'Setup Specialist'); ?></span>
                                        <?php if (!empty($expert['timezone'])): ?><span>/</span><span><?php echo $h($expert['timezone']); ?></span><?php endif; ?>
                                    </div>
                                </div>
                                <p class="owner-help-expert-headline"><?php echo $h($expert['headline'] ?? 'Internal verified CRM setup and strategy support.'); ?></p>
                                <div class="owner-help-chip-row">
                                    <?php foreach (array_slice($verifiedSkills, 0, 4) as $skill): ?>
                                        <span class="owner-help-chip is-free"><?php echo $h($skill['skill_label'] ?? 'Skill'); ?> verified</span>
                                    <?php endforeach; ?>
                                    <?php if ($verifiedSkills === []): ?><span class="owner-help-chip">Profile under review</span><?php endif; ?>
                                </div>
                                <?php if (!empty($expert['setup_areas']) || !empty($expert['availability_summary'])): ?>
                                    <div class="owner-help-expert-areas">
                                        <?php if (!empty($expert['setup_areas'])): ?><div><?php echo $h(implode(', ', array_slice((array) $expert['setup_areas'], 0, 3))); ?></div><?php endif; ?>
                                        <?php if (!empty($expert['availability_summary'])): ?><div><?php echo $h($expert['availability_summary']); ?></div><?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <a class="btn-premium-primary" href="owner_help_expert.php?id=<?php echo (int) ($expert['id'] ?? 0); ?>">View full profile</a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                    <?php if ($experts === []): ?><div class="owner-help-empty">No internal experts are listed yet. Use setup help and the support team will match you manually.</div><?php endif; ?>
                </div>
            </main>
        <?php else: ?>
            <div class="owner-help-grid">
                <aside class="owner-help-panel">
                    <h2>My requests</h2>
                    <div class="owner-help-request-list" style="margin-top:.75rem;">
                        <?php foreach ($cases as $case): ?>
                            <?php $help = (array) ($case['help_request'] ?? []); $isActive = $selectedCase && (int) ($selectedCase['id'] ?? 0) === (int) ($case['id'] ?? 0); ?>
                            <a class="owner-help-request-link <?php echo $isActive ? 'active' : ''; ?>" href="owner_support.php?tab=requests&case_id=<?php echo (int) ($case['id'] ?? 0); ?>">
                                <strong><?php echo $h($case['owner_subject'] ?? 'Help request'); ?></strong>
                                <span class="owner-help-meta">
                                    <span><?php echo $h($laneLabel((string) ($help['lane'] ?? 'system_error'))); ?></span>
                                    <span>/</span>
                                    <span><?php echo $h($pricingLabel((string) ($help['pricing_state'] ?? 'free'))); ?></span>
                                    <span>/</span>
                                    <span><?php echo $h($statusLabel((string) ($case['status'] ?? 'open'))); ?></span>
                                </span>
                            </a>
                        <?php endforeach; ?>
                        <?php if ($cases === []): ?><div class="owner-help-empty">No help requests yet.</div><?php endif; ?>
                    </div>
                </aside>
                <main class="owner-help-panel">
                    <?php if ($selectedCase === null): ?>
                        <h2>Select or open a request</h2>
                        <p>Your request thread will appear here.</p>
                    <?php else: ?>
                        <?php $help = (array) ($selectedCase['help_request'] ?? []); ?>
                        <div style="display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;flex-wrap:wrap;">
                            <div>
                                <h2><?php echo $h($selectedCase['owner_subject'] ?? 'Owner help request'); ?></h2>
                                <div class="owner-help-meta">
                                    <span><?php echo $h($laneLabel((string) ($help['lane'] ?? 'system_error'))); ?></span>
                                    <span>/</span>
                                    <span><?php echo $h($pricingLabel((string) ($help['pricing_state'] ?? 'free'))); ?></span>
                                    <span>/</span>
                                    <span><?php echo $h($statusLabel((string) ($selectedCase['status'] ?? 'open'))); ?></span>
                                    <span>/</span>
                                    <span>Last update <?php echo $h($formatDate($selectedCase['last_seen_at'] ?? null)); ?></span>
                                </div>
                            </div>
                            <span class="owner-help-chip <?php echo $h($requestTypeClass((string) ($help['commercial_type'] ?? 'free'))); ?>"><?php echo (string) ($help['commercial_type'] ?? 'free') === 'free' ? 'Free' : 'Paid'; ?></span>
                        </div>
                        <?php if (!empty($help['offering_label']) || !empty($help['expert_name']) || !empty($help['quote_notes'])): ?>
                            <div class="owner-help-card" style="margin-top:1rem;">
                                <?php if (!empty($help['offering_label'])): ?><p style="margin:0;"><strong>Offering:</strong> <?php echo $h($help['offering_label']); ?></p><?php endif; ?>
                                <?php if (!empty($help['expert_name'])): ?><p style="margin:0;"><strong>Expert:</strong> <?php echo $h($help['expert_name']); ?> <?php echo !empty($help['expert_role_label']) ? '(' . $h($help['expert_role_label']) . ')' : ''; ?></p><?php endif; ?>
                                <?php if (!empty($help['quote_notes']) && $activeQuote === null): ?><p style="margin:0;"><strong>Quote notes:</strong> <?php echo nl2br($h($help['quote_notes'])); ?></p><?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($activeQuote !== null): ?>
                            <section class="owner-help-quote-card" aria-label="Active quote">
                                <div class="owner-help-quote-head">
                                    <div>
                                        <div class="owner-help-chip-row">
                                            <span class="owner-help-chip"><?php echo $h($activeQuote['quote_number'] ?? 'Quote'); ?></span>
                                            <span class="owner-help-chip <?php echo (string) ($activeQuote['status'] ?? '') === 'accepted' ? 'is-free' : 'is-paid'; ?>"><?php echo $h($quoteStatusLabel((string) ($activeQuote['status'] ?? 'sent'))); ?></span>
                                        </div>
                                        <h3 style="margin:.55rem 0 .2rem;"><?php echo $h($activeQuote['title'] ?? 'Support service quote'); ?></h3>
                                        <div class="owner-help-meta">
                                            <span>Expires <?php echo $h($formatDateOnly($activeQuote['valid_until'] ?? null)); ?></span>
                                        </div>
                                    </div>
                                    <div class="owner-help-quote-total"><?php echo $h($formatMoney((string) ($activeQuote['currency'] ?? 'KES'), (float) ($activeQuote['total_amount'] ?? 0))); ?></div>
                                </div>
                                <?php if (!empty($activeQuote['scope_summary'])): ?><p style="margin:0;color:#334155;"><?php echo nl2br($h($activeQuote['scope_summary'])); ?></p><?php endif; ?>
                                <div class="owner-help-quote-items">
                                    <?php foreach ((array) ($activeQuote['items'] ?? []) as $item): ?>
                                        <div class="owner-help-quote-item">
                                            <div>
                                                <strong><?php echo $h($item['item_label'] ?? 'Service item'); ?></strong>
                                                <?php if (!empty($item['item_description'])): ?><div><?php echo nl2br($h($item['item_description'])); ?></div><?php endif; ?>
                                            </div>
                                            <div><?php echo $h($formatMoney((string) ($activeQuote['currency'] ?? 'KES'), (float) ($item['line_total'] ?? 0))); ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                                <?php if (!empty($activeQuote['owner_visible_notes'])): ?><p style="margin:0;color:#334155;"><strong>Notes:</strong> <?php echo nl2br($h($activeQuote['owner_visible_notes'])); ?></p><?php endif; ?>
                                <?php if (!empty($activeQuote['terms'])): ?><p style="margin:0;color:#334155;"><strong>Terms:</strong> <?php echo nl2br($h($activeQuote['terms'])); ?></p><?php endif; ?>
                                <?php if (in_array((string) ($activeQuote['status'] ?? ''), ['sent', 'changes_requested'], true)): ?>
                                    <div class="owner-help-quote-actions">
                                        <form method="POST">
                                            <input type="hidden" name="csrf_token" value="<?php echo $h($csrfToken); ?>">
                                            <input type="hidden" name="action" value="accept_quote">
                                            <input type="hidden" name="case_id" value="<?php echo (int) ($selectedCase['id'] ?? 0); ?>">
                                            <input type="hidden" name="quote_id" value="<?php echo (int) ($activeQuote['id'] ?? 0); ?>">
                                            <button type="submit" class="btn-premium-primary">Accept quote</button>
                                        </form>
                                        <form method="POST" class="owner-help-form" style="margin:0;flex:1;min-width:min(100%,280px);">
                                            <input type="hidden" name="csrf_token" value="<?php echo $h($csrfToken); ?>">
                                            <input type="hidden" name="action" value="request_quote_changes">
                                            <input type="hidden" name="case_id" value="<?php echo (int) ($selectedCase['id'] ?? 0); ?>">
                                            <input type="hidden" name="quote_id" value="<?php echo (int) ($activeQuote['id'] ?? 0); ?>">
                                            <label class="owner-help-field" style="margin:0;">Request changes
                                                <textarea name="change_note" rows="2" required placeholder="What should change in this quote?"></textarea>
                                            </label>
                                            <button type="submit" class="btn-premium-secondary">Request changes</button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            </section>
                        <?php endif; ?>

                        <div class="owner-help-thread">
                            <?php foreach ($messages as $message): ?>
                                <?php $direction = (string) ($message['direction'] ?? 'inbound'); ?>
                                <article class="owner-help-message <?php echo $direction === 'outbound' ? 'outbound' : 'inbound'; ?>">
                                    <div class="owner-help-message-head">
                                        <span><?php echo $direction === 'outbound' ? 'Support' : 'You'; ?></span>
                                        <span><?php echo $h($formatDate($message['created_at'] ?? null)); ?></span>
                                    </div>
                                    <div><?php echo nl2br($h($message['body'] ?? '')); ?></div>
                                </article>
                            <?php endforeach; ?>
                        </div>

                        <form method="POST" class="owner-help-form">
                            <input type="hidden" name="csrf_token" value="<?php echo $h($csrfToken); ?>">
                            <input type="hidden" name="action" value="reply_owner">
                            <input type="hidden" name="case_id" value="<?php echo (int) ($selectedCase['id'] ?? 0); ?>">
                            <label class="owner-help-field">Reply
                                <textarea name="message" rows="4" required></textarea>
                            </label>
                            <button type="submit" class="btn-premium-primary">Send Reply</button>
                        </form>
                    <?php endif; ?>
                </main>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
