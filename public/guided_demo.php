<?php

declare(strict_types=1);

require_once __DIR__ . '/_public_bootstrap.php';

use CRM\Auth;
use CRM\Database;
use CRM\Security;
use CRM\Session;
use CRM\Services\GuidedDemoSessionService;
use CRM\Services\WorkspaceContext;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$user = Auth::user() ?: [];
$userId = (int) ($user['id'] ?? 0);
$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
if ($workspaceId <= 0 || $userId <= 0) {
    header('Location: dashboard.php');
    exit;
}

$service = new GuidedDemoSessionService();
$error = null;
$notice = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!Security::validateCSRF((string) ($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Invalid security token. Please refresh and try again.');
        }

        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'start_demo') {
            $payload = $service->start($workspaceId, $userId);
            header('Location: ' . (string) ($payload['redirect_url'] ?? publicUrl('dashboard.php?guided_demo=1')));
            exit;
        }
        if ($action === 'choose_package') {
            (new \CRM\Services\SaaSBillingService())->activateCompassFreeSubscription($workspaceId, $userId);
            header('Location: ' . publicUrl('dashboard.php'));
            exit;
        }
        if ($action === 'retry_cleanup') {
            $payload = $service->finish($workspaceId, $userId, 'exited');
            header('Location: ' . (string) ($payload['redirect_url'] ?? publicUrl('dashboard.php?demo=exited')));
            exit;
        }
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $error = stripos($message, 'security token') !== false
            ? $message
            : 'Could not open the dashboard yet. Try again.';
    }
}

$active = $service->state($workspaceId, $userId);
if ($active !== null && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . (string) ($active['redirect_url'] ?? publicUrl('dashboard.php?guided_demo=1')));
    exit;
}

$latest = $service->latestSession($workspaceId, $userId);
$showCleanupRecovery = (string) ($_GET['cleanup'] ?? '') === 'failed'
    || ($latest !== null && (((string) ($latest['status'] ?? '') === 'cleanup_failed') || ((string) ($latest['cleanup_status'] ?? '') === 'failed')));
if ($latest !== null && in_array((string) ($latest['status'] ?? ''), ['completed', 'exited'], true) && (string) ($latest['cleanup_status'] ?? '') === 'succeeded') {
    (new \CRM\Services\SaaSBillingService())->activateCompassFreeSubscription($workspaceId, $userId);
    $demoStatus = (string) ($latest['status'] ?? '') === 'exited' ? 'exited' : 'complete';
    header('Location: ' . publicUrl('dashboard.php?demo=' . $demoStatus));
    exit;
}
if (!$showCleanupRecovery && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . publicUrl('dashboard.php'));
    exit;
}
if (($latest['status'] ?? '') === 'cleanup_failed' || ($latest['cleanup_status'] ?? '') === 'failed') {
    $notice = 'We need one more pass before the dashboard can close out the demo cleanup.';
}

$csrf = Security::getCsrfToken();
$pageTitle = 'Guided Demo - ' . brandProductName();
$guidedDemoPageStyles = true;

ob_start();
?>
<section class="guided-demo-consent" data-guided-demo-consent>
    <div class="guided-demo-consent__content">
        <p class="guided-demo-consent__kicker">Cleanup recovery</p>
        <h1>Finish the product demo cleanup</h1>
        <p>The demo data needs one more cleanup pass before this workspace fully settles back on the dashboard. Nothing new is created here. We just clear the temporary walkthrough records and return you to the dashboard.</p>
        <?php if ($error): ?>
            <div class="guided-demo-consent__alert is-error"><?php echo htmlspecialchars($error); ?></div>
        <?php elseif ($notice): ?>
            <div class="guided-demo-consent__alert"><?php echo htmlspecialchars($notice); ?></div>
        <?php endif; ?>
        <div class="guided-demo-consent__actions">
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
                <input type="hidden" name="action" value="retry_cleanup">
                <button type="submit" class="btn-premium-primary">Retry demo cleanup</button>
            </form>
            <a class="btn-premium-secondary" href="<?php echo htmlspecialchars(publicUrl('dashboard.php')); ?>">Back to Dashboard</a>
        </div>
    </div>
    <div class="guided-demo-consent__panel" aria-label="Demo cleanup steps">
        <div><strong>1</strong><span>Remove temporary demo contacts</span></div>
        <div><strong>2</strong><span>Clear simulated replies and tasks</span></div>
        <div><strong>3</strong><span>Restore the workspace modules</span></div>
        <div><strong>4</strong><span>Return to the real dashboard</span></div>
    </div>
</section>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
