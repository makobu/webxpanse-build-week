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

$userId = (int) ((Auth::user() ?: [])['id'] ?? 0);
$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
if ($workspaceId <= 0 || $userId <= 0) {
    header('Location: dashboard.php');
    exit;
}

$service = new GuidedDemoSessionService();
$state = $service->state($workspaceId, $userId);
if ($state === null) {
    header('Location: ' . publicUrl('dashboard.php'));
    exit;
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!Security::validateCSRF((string) ($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Invalid security token. Please refresh and try again.');
        }
        $reason = (string) ($_POST['reason'] ?? 'completed');
        $payload = $service->finish($workspaceId, $userId, $reason === 'exited' ? 'exited' : 'completed');
        header('Location: ' . (string) ($payload['redirect_url'] ?? publicUrl('dashboard.php?demo=complete')));
        exit;
    } catch (Throwable $e) {
        $message = $e->getMessage();
        $error = stripos($message, 'security token') !== false
            ? $message
            : 'Could not open your dashboard yet. Try again.';
    }
}

$csrf = Security::getCsrfToken();
$pageTitle = 'Dashboard Ready - ' . brandProductName();
$guidedDemoPageStyles = true;

ob_start();
?>
<section class="guided-demo-wrap" data-guided-demo-target="guided-demo-wrap-up">
    <p class="guided-demo-wrap__kicker">Demo complete</p>
    <h1>Your dashboard is ready</h1>
    <p>You have seen a normal CRM workday: contact, message, reply, task, money step, module tour, and weekly review. The next step is opening your workspace dashboard.</p>
    <?php if ($error): ?>
        <div class="guided-demo-wrap__alert"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>
    <div class="guided-demo-wrap__actions">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
            <input type="hidden" name="reason" value="completed">
            <button type="submit" class="btn-premium-primary">Open Dashboard</button>
        </form>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf); ?>">
            <input type="hidden" name="reason" value="exited">
            <button type="submit" class="btn-premium-secondary">Exit Demo</button>
        </form>
    </div>
</section>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
