<?php

require_once __DIR__ . '/_public_bootstrap.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Security;
use CRM\Session;
use CRM\Services\WhatsAppFeatureGate;
use CRM\Services\WhatsAppTemplateService;
use CRM\Services\WorkspaceConnectService;
use CRM\Services\WorkspaceContext;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$user = Auth::user();
$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
$userId = (int) ($user['id'] ?? 0);
$featureGate = new WhatsAppFeatureGate();
if (!$featureGate->templateCenterEnabled($workspaceId)) {
    header('Location: workspace_skills.php?module=whatsapp&setup_tab=manual&error=' . rawurlencode('WhatsApp Template Center is not enabled for this workspace.') . '#setup');
    exit;
}
$connect = new WorkspaceConnectService();
$canManageWhatsApp = $connect->canManageWhatsAppConnection($user, $workspaceId);
$canManage = $canManageWhatsApp || Authorization::can('whatsapp.templates.manage', $user);
$canSubmit = $canManageWhatsApp || Authorization::can('whatsapp.templates.submit', $user);
$service = new WhatsAppTemplateService();
$notice = trim((string) ($_GET['notice'] ?? ''));
$error = trim((string) ($_GET['error'] ?? ''));
$statusFilter = strtolower(trim((string) ($_GET['status'] ?? '')));

function whatsappTemplatesRedirect(array $params = []): void
{
    $query = $params !== [] ? '?' . http_build_query($params) : '';
    header('Location: whatsapp_templates.php' . $query);
    exit;
}

function whatsappTemplateStatusBadge(string $status): string
{
    $status = strtolower($status);
    return match ($status) {
        'approved' => 'background:#dcfce7;color:#166534;border-color:#bbf7d0;',
        'rejected', 'sync_failed' => 'background:#fee2e2;color:#991b1b;border-color:#fecaca;',
        'pending', 'submitted' => 'background:#eff6ff;color:#1d4ed8;border-color:#bfdbfe;',
        'paused', 'disabled' => 'background:#f1f5f9;color:#475569;border-color:#cbd5e1;',
        default => 'background:#fff7ed;color:#9a3412;border-color:#fed7aa;',
    };
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        whatsappTemplatesRedirect(['error' => 'Invalid security token. Please try again.']);
    }

    $action = strtolower(trim((string) ($_POST['template_action'] ?? '')));
    try {
        if ($action === 'save_draft') {
            if (!$canManage) {
                throw new RuntimeException('Template management permission is required.');
            }
            $service->createOrUpdateDraft($workspaceId, $userId, [
                'template_id' => (int) ($_POST['template_id'] ?? 0),
                'template_name' => (string) ($_POST['template_name'] ?? ''),
                'category' => (string) ($_POST['category'] ?? 'utility'),
                'language_code' => (string) ($_POST['language_code'] ?? 'en_US'),
                'header_type' => (string) ($_POST['header_type'] ?? 'TEXT'),
                'header_text' => (string) ($_POST['header_text'] ?? ''),
                'body_text' => (string) ($_POST['body_text'] ?? ''),
                'footer_text' => (string) ($_POST['footer_text'] ?? ''),
                'buttons_json' => (string) ($_POST['buttons_json'] ?? ''),
                'sample_values_json' => (string) ($_POST['sample_values_json'] ?? ''),
                'media_example_url' => (string) ($_POST['media_example_url'] ?? ''),
            ]);
            whatsappTemplatesRedirect(['notice' => 'Template draft saved.']);
        }

        if ($action === 'submit') {
            if (!$canSubmit) {
                throw new RuntimeException('Template submission permission is required.');
            }
            $result = $service->submitForApproval($workspaceId, (int) ($_POST['template_id'] ?? 0), $userId);
            whatsappTemplatesRedirect([
                ($result['success'] ?? false) ? 'notice' : 'error' => ($result['success'] ?? false)
                    ? 'Template sent for approval.'
                    : (string) ($result['error'] ?? 'Template submission failed.'),
            ]);
        }

        if ($action === 'clone') {
            if (!$canManage) {
                throw new RuntimeException('Template management permission is required.');
            }
            $service->cloneForResubmission($workspaceId, (int) ($_POST['template_id'] ?? 0), $userId);
            whatsappTemplatesRedirect(['notice' => 'Template cloned as a new draft.']);
        }

        if ($action === 'sync') {
            if (!$canManage && !$canSubmit) {
                throw new RuntimeException('Template permission is required.');
            }
            $sync = $service->syncFromProvider($workspaceId);
            whatsappTemplatesRedirect(['notice' => 'Template sync complete: ' . (int) ($sync['synced'] ?? 0) . ' template(s).']);
        }
    } catch (Throwable $e) {
        whatsappTemplatesRedirect(['error' => $e->getMessage()]);
    }
}

$filters = [];
if ($statusFilter !== '') {
    $filters['status'] = $statusFilter;
}
$templates = $service->listTemplates($workspaceId, $filters);
$connection = $connect->getActiveWhatsAppIntegration($workspaceId) ?: [];
$connectionMode = (string) ($connection['connection_mode'] ?? 'self_managed');

$pageTitle = 'WhatsApp Templates - ' . brandProductName();
ob_start();
?>
<link rel="stylesheet" href="assets/css/premium-pages.css">

<div class="page-premium">
    <div class="container">
        <div class="page-header">
            <div>
                <h1>WhatsApp Templates</h1>
                <p>Create, submit, sync, and reuse approved WhatsApp templates.</p>
            </div>
            <div class="page-header-actions">
                <a href="workspace_skills.php?module=whatsapp&setup_tab=manual#setup" class="btn-premium-secondary"><i class="fas fa-plug"></i> WhatsApp Setup</a>
                <form method="POST" style="margin:0;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
                    <button class="btn-premium-secondary" type="submit" name="template_action" value="sync" <?php echo ($canManage || $canSubmit) ? '' : 'disabled'; ?>><i class="fas fa-sync"></i> Sync</button>
                </form>
            </div>
        </div>

        <?php if ($notice !== ''): ?><div class="premium-banner premium-banner-success"><?php echo htmlspecialchars($notice); ?></div><?php endif; ?>
        <?php if ($error !== ''): ?><div class="premium-banner premium-banner-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

        <div class="content-card" style="margin-bottom:1rem;">
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.75rem;">
                <div><strong>Connection mode</strong><br><span style="color:#64748b;"><?php echo htmlspecialchars($connectionMode === 'platform_managed' ? 'Managed WhatsApp' : 'Self-managed Meta account'); ?></span></div>
                <div><strong>WABA</strong><br><span style="color:#64748b;"><?php echo htmlspecialchars((string) ($connection['whatsapp_business_account_id'] ?? 'Not connected')); ?></span></div>
                <div><strong>Submit route</strong><br><span style="color:#64748b;"><?php echo htmlspecialchars($connectionMode === 'platform_managed' ? 'Managed/provider credentials' : 'Workspace WABA credentials'); ?></span></div>
            </div>
        </div>

        <div class="content-card" style="margin-bottom:1rem;">
            <h2 style="font-size:1.1rem;margin:0 0 1rem;color:#0f172a;">Draft Template</h2>
            <form method="POST" class="filters-form" style="display:grid;gap:1rem;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));align-items:start;">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
                <input type="hidden" name="template_action" value="save_draft">
                <div class="filter-group"><label>Name</label><input type="text" name="template_name" placeholder="renewal_reminder" pattern="[A-Za-z0-9_]+" required></div>
                <div class="filter-group"><label>Category</label><select name="category"><option value="utility">Utility</option><option value="marketing">Marketing</option><option value="authentication">Authentication</option></select></div>
                <div class="filter-group"><label>Language</label><input type="text" name="language_code" value="en_US" required></div>
                <div class="filter-group"><label>Header Type</label><select name="header_type"><option value="TEXT">Text</option><option value="IMAGE">Image</option><option value="VIDEO">Video</option><option value="DOCUMENT">Document</option></select></div>
                <div class="filter-group" style="grid-column:1/-1;"><label>Header Text</label><input type="text" name="header_text" placeholder="Optional header"></div>
                <div class="filter-group" style="grid-column:1/-1;"><label>Body</label><textarea name="body_text" rows="5" placeholder="Hello {{1}}, your appointment is on {{2}}." required></textarea></div>
                <div class="filter-group" style="grid-column:1/-1;"><label>Footer</label><input type="text" name="footer_text" placeholder="Optional footer"></div>
                <div class="filter-group" style="grid-column:1/-1;"><label>Sample Values JSON</label><textarea name="sample_values_json" rows="3" placeholder='["Alex","Friday"]'></textarea></div>
                <div class="filter-group" style="grid-column:1/-1;"><label>Buttons JSON</label><textarea name="buttons_json" rows="3" placeholder='[{"type":"QUICK_REPLY","text":"Confirm"}]'></textarea></div>
                <div style="grid-column:1/-1;"><button class="btn-premium-primary" type="submit" <?php echo $canManage ? '' : 'disabled'; ?>><i class="fas fa-save"></i> Save Draft</button></div>
            </form>
        </div>

        <div class="filters-card">
            <form method="GET" class="filters-form">
                <div class="filter-group">
                    <label>Status</label>
                    <select name="status">
                        <option value="">All</option>
                        <?php foreach (['draft','pending','approved','rejected','paused','disabled','sync_failed'] as $status): ?>
                            <option value="<?php echo htmlspecialchars($status); ?>" <?php echo $statusFilter === $status ? 'selected' : ''; ?>><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $status))); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-actions"><button class="btn-premium-secondary" type="submit"><i class="fas fa-filter"></i> Filter</button></div>
            </form>
        </div>

        <div class="content-card">
            <?php if ($templates === []): ?>
                <div class="empty-state"><p>No WhatsApp templates found.</p></div>
            <?php else: ?>
                <div style="overflow-x:auto;">
                    <table class="premium-table">
                        <thead><tr><th>Name</th><th>Category</th><th>Language</th><th>Status</th><th>Updated</th><th>Actions</th></tr></thead>
                        <tbody>
                            <?php foreach ($templates as $template): ?>
                                <?php $templateStatus = strtolower((string) ($template['status'] ?? 'draft')); ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars((string) ($template['template_name'] ?? '')); ?></strong><br><span style="color:#64748b;"><?php echo htmlspecialchars(mb_substr((string) ($template['body_text'] ?? ''), 0, 120)); ?></span></td>
                                    <td><?php echo htmlspecialchars(ucfirst((string) ($template['category'] ?? 'utility'))); ?></td>
                                    <td><?php echo htmlspecialchars((string) ($template['language_code'] ?? 'en_US')); ?></td>
                                    <td><span style="display:inline-block;border:1px solid;border-radius:999px;padding:.2rem .55rem;font-size:.78rem;font-weight:800;<?php echo whatsappTemplateStatusBadge($templateStatus); ?>"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $templateStatus))); ?></span></td>
                                    <td><?php echo htmlspecialchars((string) ($template['updated_at'] ?? '')); ?></td>
                                    <td>
                                        <div style="display:flex;gap:.4rem;flex-wrap:wrap;">
                                            <?php if (in_array($templateStatus, ['draft','rejected','sync_failed'], true)): ?>
                                                <form method="POST" style="margin:0;">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
                                                    <input type="hidden" name="template_id" value="<?php echo (int) $template['id']; ?>">
                                                    <button class="btn-premium-secondary" type="submit" name="template_action" value="submit" <?php echo $canSubmit ? '' : 'disabled'; ?>>Submit</button>
                                                </form>
                                            <?php endif; ?>
                                            <?php if (in_array($templateStatus, ['rejected','sync_failed','approved'], true)): ?>
                                                <form method="POST" style="margin:0;">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
                                                    <input type="hidden" name="template_id" value="<?php echo (int) $template['id']; ?>">
                                                    <button class="btn-premium-secondary" type="submit" name="template_action" value="clone" <?php echo $canManage ? '' : 'disabled'; ?>>Clone</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!empty($template['rejection_reason'])): ?><small style="display:block;color:#991b1b;margin-top:.35rem;"><?php echo htmlspecialchars((string) $template['rejection_reason']); ?></small><?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
