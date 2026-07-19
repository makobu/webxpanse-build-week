<?php

require_once __DIR__ . '/_public_bootstrap.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Security;
use CRM\Session;
use CRM\Services\ManagedWhatsAppProvisioningService;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$user = Auth::user();
if (!Authorization::isSuperAdmin($user)) {
    header('Location: dashboard.php');
    exit;
}

$service = new ManagedWhatsAppProvisioningService();
$notice = trim((string) ($_GET['notice'] ?? ''));
$error = trim((string) ($_GET['error'] ?? ''));

function whatsappManagedAdminRedirect(array $params = []): void
{
    header('Location: whatsapp_managed_admin.php' . ($params !== [] ? '?' . http_build_query($params) : ''));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        whatsappManagedAdminRedirect(['error' => 'Invalid security token. Please try again.']);
    }

    $action = strtolower(trim((string) ($_POST['admin_action'] ?? '')));
    $workspaceId = (int) ($_POST['workspace_id'] ?? 0);
    try {
        if ($action === 'health_check') {
            $result = $service->healthCheck($workspaceId);
            whatsappManagedAdminRedirect(['notice' => 'Health check: ' . (string) ($result['status'] ?? 'checked')]);
        }
    } catch (Throwable $e) {
        whatsappManagedAdminRedirect(['error' => $e->getMessage()]);
    }
}

$summary = $service->adminSummary(100);
$rows = (array) ($summary['rows'] ?? []);
$totals = (array) ($summary['totals'] ?? []);
$readiness = (array) ($summary['readiness'] ?? []);
$failedSends = Database::tableExists('whatsapp_messages')
    ? Database::query(
        "SELECT wm.workspace_id, w.name AS workspace_name, COUNT(*) AS failed_count
         FROM whatsapp_messages wm
         LEFT JOIN workspaces w ON w.id = wm.workspace_id
         WHERE wm.connection_mode = 'platform_managed'
           AND wm.status = 'failed'
           AND wm.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
         GROUP BY wm.workspace_id, w.name
         ORDER BY failed_count DESC
         LIMIT 10"
    )
    : [];
$templateQueue = Database::tableExists('workspace_whatsapp_templates')
    ? Database::query(
        "SELECT workspace_id, status, COUNT(*) AS c
         FROM workspace_whatsapp_templates
         WHERE status IN ('submitted','pending','rejected','sync_failed')
         GROUP BY workspace_id, status
         ORDER BY c DESC
         LIMIT 20"
    )
    : [];

$pageTitle = 'Managed WhatsApp Admin - ' . brandProductName();
ob_start();
?>
<link rel="stylesheet" href="assets/css/premium-pages.css">

<div class="page-premium">
    <div class="container">
        <div class="page-header">
            <div>
                <h1>Managed WhatsApp Admin</h1>
                <p>Provisioning, credits, webhook health, template queue, and provider error visibility.</p>
            </div>
            <div class="page-header-actions">
                <a href="super_admin_billing.php" class="btn-premium-secondary"><i class="fas fa-receipt"></i> Billing Admin</a>
                <a href="workspace_skills.php?module=whatsapp" class="btn-premium-secondary"><i class="fab fa-whatsapp"></i> Workspace Setup</a>
            </div>
        </div>

        <?php if ($notice !== ''): ?><div class="premium-banner premium-banner-success"><?php echo htmlspecialchars($notice); ?></div><?php endif; ?>
        <?php if ($error !== ''): ?><div class="premium-banner premium-banner-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

        <?php if (empty($readiness['available'])): ?>
            <div class="premium-banner premium-banner-warning">Managed WhatsApp is not available yet. Missing: <?php echo htmlspecialchars(implode(', ', (array) ($readiness['missing'] ?? []))); ?></div>
        <?php endif; ?>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:1rem;margin-bottom:1rem;">
            <div class="content-card"><strong style="font-size:1.6rem;"><?php echo (int) ($totals['managed_count'] ?? 0); ?></strong><br><span>Managed workspaces</span></div>
            <div class="content-card"><strong style="font-size:1.6rem;"><?php echo number_format((float) ($totals['credit_liability'] ?? 0), 2); ?></strong><br><span>Credit liability</span></div>
            <div class="content-card"><strong style="font-size:1.6rem;"><?php echo number_format((float) ($totals['reserved_liability'] ?? 0), 2); ?></strong><br><span>Reserved credits</span></div>
            <div class="content-card"><strong style="font-size:1.6rem;"><?php echo empty($failedSends) ? 0 : array_sum(array_map(static fn(array $row): int => (int) ($row['failed_count'] ?? 0), $failedSends)); ?></strong><br><span>Failed sends 7d</span></div>
        </div>

        <div class="content-card" style="margin-bottom:1rem;">
            <h2 style="font-size:1.1rem;margin:0 0 1rem;color:#0f172a;">Provisioning Queue</h2>
            <?php if ($rows === []): ?>
                <div class="empty-state"><p>No managed WhatsApp workspaces yet.</p></div>
            <?php else: ?>
                <div style="overflow-x:auto;">
                    <table class="premium-table">
                        <thead><tr><th>Workspace</th><th>Phone / WABA</th><th>Provisioning</th><th>Webhook</th><th>Credits</th><th>Provider Error</th><th>Actions</th></tr></thead>
                        <tbody>
                            <?php foreach ($rows as $row): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars((string) ($row['workspace_name'] ?? ('Workspace #' . ($row['workspace_id'] ?? '')))); ?></strong><br><span style="color:#64748b;">#<?php echo (int) ($row['workspace_id'] ?? 0); ?></span></td>
                                    <td><?php echo htmlspecialchars((string) ($row['display_phone_number'] ?? $row['phone_number_id'] ?? '')); ?><br><span style="color:#64748b;"><?php echo htmlspecialchars((string) ($row['whatsapp_business_account_id'] ?? '')); ?></span></td>
                                    <td><?php echo htmlspecialchars((string) ($row['managed_status'] ?? '')); ?><br><span style="color:#64748b;"><?php echo htmlspecialchars((string) ($row['managed_last_health_status'] ?? '')); ?></span></td>
                                    <td><?php echo htmlspecialchars((string) (($row['webhook_last_status'] ?? '') ?: 'No event')); ?><br><span style="color:#64748b;"><?php echo htmlspecialchars((string) ($row['webhook_last_event_at'] ?? '')); ?></span></td>
                                    <td><?php echo htmlspecialchars((string) ($row['managed_currency'] ?? 'KES')); ?> <?php echo number_format((float) ($row['credit_balance'] ?? 0), 2); ?><br><span style="color:#64748b;"><?php echo number_format((float) ($row['reserved_credits'] ?? 0), 2); ?> reserved</span></td>
                                    <td style="max-width:260px;"><?php echo htmlspecialchars((string) (($row['managed_last_health_error'] ?? '') ?: ($row['last_error'] ?? ''))); ?></td>
                                    <td>
                                        <form method="POST" style="margin:0;">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
                                            <input type="hidden" name="workspace_id" value="<?php echo (int) ($row['workspace_id'] ?? 0); ?>">
                                            <button class="btn-premium-secondary" type="submit" name="admin_action" value="health_check">Health</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:1rem;">
            <div class="content-card">
                <h2 style="font-size:1.1rem;margin:0 0 1rem;color:#0f172a;">Failed Sends</h2>
                <?php foreach ($failedSends as $row): ?>
                    <div style="display:flex;justify-content:space-between;border-bottom:1px solid #e2e8f0;padding:.55rem 0;">
                        <span><?php echo htmlspecialchars((string) ($row['workspace_name'] ?? ('Workspace #' . $row['workspace_id']))); ?></span>
                        <strong><?php echo (int) ($row['failed_count'] ?? 0); ?></strong>
                    </div>
                <?php endforeach; ?>
                <?php if ($failedSends === []): ?><p style="color:#64748b;margin:0;">No failed managed sends in the last 7 days.</p><?php endif; ?>
            </div>
            <div class="content-card">
                <h2 style="font-size:1.1rem;margin:0 0 1rem;color:#0f172a;">Template Queue</h2>
                <?php foreach ($templateQueue as $row): ?>
                    <div style="display:flex;justify-content:space-between;border-bottom:1px solid #e2e8f0;padding:.55rem 0;">
                        <span>Workspace #<?php echo (int) ($row['workspace_id'] ?? 0); ?> · <?php echo htmlspecialchars((string) ($row['status'] ?? '')); ?></span>
                        <strong><?php echo (int) ($row['c'] ?? 0); ?></strong>
                    </div>
                <?php endforeach; ?>
                <?php if ($templateQueue === []): ?><p style="color:#64748b;margin:0;">No template queue exceptions.</p><?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
