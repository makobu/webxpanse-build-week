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
use CRM\Authorization;
use CRM\Database;
use CRM\Session;
use CRM\Services\WorkspaceContext;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$user = Auth::user();
if (!Authorization::can('admin.roles.manage', $user)) {
    header('Location: dashboard.php');
    exit;
}

$activeWorkspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
$missingProfiles = Authorization::isRbacAvailable()
    ? Database::query(
        "SELECT u.id, u.email, u.role
         FROM users u
         LEFT JOIN user_roles ur ON ur.user_id = u.id
         WHERE ur.user_id IS NULL
         ORDER BY u.created_at DESC, u.id DESC"
    )
    : [];

$legacyRoleChecks = [];
$scanRoot = realpath(__DIR__);
if ($scanRoot !== false) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($scanRoot));
    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile() || strtolower($fileInfo->getExtension()) !== 'php') {
            continue;
        }

        $path = $fileInfo->getPathname();
        if (basename($path) === 'rbac_audit.php') {
            continue;
        }
        $contents = @file($path, FILE_IGNORE_NEW_LINES);
        if (!is_array($contents)) {
            continue;
        }

        foreach ($contents as $index => $line) {
            if (strpos($line, "['role']") === false || strpos($line, 'admin') === false) {
                continue;
            }
            $legacyRoleChecks[] = [
                'path' => str_replace(str_replace('\\', '/', realpath(__DIR__ . '/..')) . '/', '', str_replace('\\', '/', $path)),
                'line' => $index + 1,
                'snippet' => trim($line),
            ];
        }
    }
}

$pageTitle = 'RBAC Audit - ' . brandProductName();
ob_start();
?>
<link rel="stylesheet" href="assets/css/premium-pages.css">
<link rel="stylesheet" href="assets/css/ops-logs-ui.css">

<div class="page-premium">
    <div class="container ops-workspace">
        <section class="ops-hero">
            <div>
                <div class="ops-kicker">Access control audit</div>
                <h1>RBAC Audit</h1>
                <p>Review users missing an access profile and detect remaining legacy admin checks in public pages.</p>
            </div>
            <div class="ops-hero-actions">
                <a href="users.php" class="btn-premium-secondary"><i class="fas fa-users"></i> Back to Users</a>
                <a href="roles.php" class="btn-premium-secondary"><i class="fas fa-user-shield"></i> Manage Roles</a>
            </div>
        </section>

        <section class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Users Without Access Profile</div>
                <div class="stat-value"><?php echo count($missingProfiles); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Legacy Admin Checks Found</div>
                <div class="stat-value"><?php echo count($legacyRoleChecks); ?></div>
            </div>
        </section>

        <section class="table-card">
            <div class="premium-section-header">
                <h2 class="ops-table-title">Users Missing an Access Profile</h2>
            </div>
            <?php if (empty($missingProfiles)): ?>
                <div class="empty-state">
                    <p>All users have an assigned RBAC access profile.</p>
                </div>
            <?php else: ?>
                <div class="table-card-scroll">
                    <table class="premium-table">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Profile Role</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($missingProfiles as $row): ?>
                                <?php
                                    $editParams = ['id' => (int) $row['id']];
                                    if ($activeWorkspaceId > 0) {
                                        $editParams['workspace_id'] = $activeWorkspaceId;
                                    }
                                ?>
                                <tr>
                                    <td>
                                        <a href="user_edit.php?<?php echo htmlspecialchars(http_build_query($editParams)); ?>" class="ops-link">
                                            <?php echo htmlspecialchars((string) $row['email']); ?>
                                        </a>
                                    </td>
                                    <td>
                                        <span class="ops-status-badge ops-status-badge--warning">
                                            <?php echo htmlspecialchars((string) $row['role']); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="content-card">
            <div class="ops-card-header">
                <h2 class="ops-table-title">Legacy Admin Checks</h2>
                <span class="ops-muted"><?php echo count($legacyRoleChecks); ?> findings</span>
            </div>
            <?php if (empty($legacyRoleChecks)): ?>
                <div class="empty-state">
                    <p>No remaining direct legacy admin checks were found in public/.</p>
                </div>
            <?php else: ?>
                <div class="ops-finding-list">
                    <?php foreach ($legacyRoleChecks as $finding): ?>
                        <article class="ops-finding-card">
                            <div class="ops-table-cell-main">
                                <?php echo htmlspecialchars($finding['path']); ?>:<?php echo (int) $finding['line']; ?>
                            </div>
                            <code class="ops-code-block"><?php echo htmlspecialchars($finding['snippet']); ?></code>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
