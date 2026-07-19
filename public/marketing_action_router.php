<?php
/**
 * Marketing Action Router.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/constants.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Modules\Marketing;
use CRM\Security;
use CRM\Session;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: ' . getBasePath() . '/login.php');
    exit;
}

$user = Auth::user();
require_once __DIR__ . '/_marketing_marketplace_gate.php';
crm_require_marketing_marketplace_access($user);
if (!Authorization::can('marketing.read', $user)) {
    header('Location: ' . getBasePath() . '/dashboard.php');
    exit;
}

$marketing = new Marketing();
$canWriteMarketing = Authorization::can('marketing.write', $user);
$canManageMarketing = Authorization::can('marketing.manage', $user);
$error = '';
$context = [];
foreach (['contact_id', 'company_id', 'deal_id', 'campaign_id', 'form_id', 'task_id'] as $field) {
    $value = (int) ($_GET[$field] ?? $_POST[$field] ?? 0);
    if ($value > 0) {
        $context[$field] = $value;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Invalid CSRF token.');
        }
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'generate_snapshot') {
            if (!$canWriteMarketing) {
                throw new RuntimeException('You do not have permission to generate Marketing action router recommendations.');
            }
            $marketing->generateMarketingActionRouterSnapshot($context, (int) ($user['id'] ?? 0), 20);
            header('Location: ' . getBasePath() . '/marketing_action_router.php?success=generated');
            exit;
        }
        if ($action === 'update_status') {
            $status = (string) ($_POST['action_status'] ?? 'suggested');
            if ($status === 'archived' && !$canManageMarketing) {
                throw new RuntimeException('Only Marketing managers can archive Action Router items.');
            }
            if (!$canWriteMarketing) {
                throw new RuntimeException('You do not have permission to update Action Router items.');
            }
            $marketing->updateMarketingActionRouterItemStatus(
                (int) ($_POST['item_id'] ?? 0),
                $status,
                (string) ($_POST['status_note'] ?? ''),
                (int) ($user['id'] ?? 0)
            );
            header('Location: ' . getBasePath() . '/marketing_action_router.php?success=updated');
            exit;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$statusFilter = (string) ($_GET['status'] ?? 'open');
$filters = $statusFilter === 'open' ? ['open' => true] : [];
if ($statusFilter !== 'open' && $statusFilter !== 'all') {
    $filters['action_status'] = $statusFilter;
}
$router = $marketing->getMarketingActionRouter((int) ($user['id'] ?? 0), 16, $context);
$integrationCenter = $marketing->getMarketingCrmIntegrationActionCenter($context, (int) ($user['id'] ?? 0), 8);
$items = $statusFilter === 'open'
    ? (array) ($router['items'] ?? [])
    : $marketing->listMarketingActionRouterItems($filters, 100, 0);
$labelize = static fn(string $value): string => ucwords(str_replace('_', ' ', $value));
$priorityClass = static fn(string $priority): string => match ($priority) {
    'high' => 'badge-warning',
    'low' => 'badge-default',
    default => 'badge-info',
};
$statusClass = static fn(string $status): string => match ($status) {
    'resolved' => 'badge-success',
    'dismissed', 'archived' => 'badge-danger',
    'accepted' => 'badge-warning',
    default => 'badge-info',
};
$stageStatusClass = static fn(string $status): string => match ($status) {
    'Ready' => 'badge-success',
    'In use' => 'badge-info',
    'Locked' => 'badge-danger',
    default => 'badge-warning',
};
$stageClass = static fn(string $status): string => strtolower(str_replace(' ', '_', $status));

$counts = (array) ($router['counts'] ?? []);
$activeActions = (int) ($counts['active'] ?? 0);
$highPriorityActions = (int) ($counts['high_priority'] ?? 0);
$storedOpenActions = (int) ($counts['stored_open'] ?? 0);
$liveSignals = (int) ($counts['live_recommendations'] ?? 0);
$integrationScore = (int) ($integrationCenter['score'] ?? 0);
$integrationStatus = (string) ($integrationCenter['status'] ?? 'attention');
$integrationStatusLabel = $labelize($integrationStatus);
$contextCount = count($context);
$guardrailCount = count((array) ($router['guardrails'] ?? []));

$routeStages = [
    [
        'label' => 'Find Next Move',
        'status' => $activeActions > 0 ? 'In use' : 'Setup needed',
        'score' => min(100, max(0, $activeActions * 10)),
        'icon' => 'fa-compass',
        'href' => '#router-queue',
        'action' => 'Review Queue',
        'tooltip' => 'Shows the next Marketing actions already linked to CRM records.',
    ],
    [
        'label' => 'Check Priority',
        'status' => $highPriorityActions > 0 ? 'Ready' : 'Setup needed',
        'score' => min(100, max(0, $highPriorityActions * 25)),
        'icon' => 'fa-signal',
        'href' => '#router-queue',
        'action' => 'Sort Work',
        'tooltip' => 'High-priority items surface when the router sees urgent campaign or CRM evidence.',
    ],
    [
        'label' => 'Open Work',
        'status' => $storedOpenActions > 0 ? 'Ready' : 'Setup needed',
        'score' => min(100, max(0, $storedOpenActions * 8)),
        'icon' => 'fa-arrow-up-right-from-square',
        'href' => '#router-queue',
        'action' => 'Open Target',
        'tooltip' => 'Every recommendation routes to an internal CRM or Marketing page for manual action.',
    ],
    [
        'label' => 'Review System',
        'status' => $integrationStatus === 'ready' ? 'Ready' : 'Setup needed',
        'score' => $integrationScore,
        'icon' => 'fa-diagram-project',
        'href' => '#routing-tools',
        'action' => 'See Tools',
        'tooltip' => 'CRM integration lanes, connected systems, and filters live below the main board.',
    ],
    [
        'label' => 'Keep Safe',
        'status' => $guardrailCount > 0 ? 'Ready' : 'Setup needed',
        'score' => min(100, max(0, $guardrailCount * 20)),
        'icon' => 'fa-shield-halved',
        'href' => '#routing-tools',
        'action' => 'Guardrails',
        'tooltip' => 'Router guardrails keep recommendations manual-first and internal to the CRM.',
    ],
];

$todayActions = [];
if ($canWriteMarketing) {
    $todayActions[] = [
        'label' => 'Refresh Router',
        'source' => 'Action Router',
        'reason' => 'Generate the latest CRM-linked recommendations.',
        'form' => true,
    ];
}
foreach ((array) ($integrationCenter['next_actions'] ?? []) as $nextAction) {
    $todayActions[] = [
        'label' => (string) ($nextAction['label'] ?? 'Open CRM action'),
        'href' => (string) ($nextAction['href'] ?? 'marketing_action_router.php'),
        'source' => (string) ($nextAction['source'] ?? 'Marketing'),
        'reason' => (string) ($nextAction['reason'] ?? 'Open the linked CRM target.'),
    ];
}
foreach ($items as $item) {
    $todayActions[] = [
        'label' => (string) ($item['label'] ?? 'Review action'),
        'href' => (string) ($item['target_href'] ?? 'marketing.php'),
        'source' => (string) ($item['source'] ?? 'Marketing'),
        'reason' => (string) ($item['recommended_action'] ?? $item['reason'] ?? 'Open the target and act manually.'),
    ];
    if (count($todayActions) >= 5) {
        break;
    }
}
if ($todayActions === []) {
    $todayActions[] = [
        'label' => 'Open Marketing',
        'href' => 'marketing.php',
        'source' => 'Command Center',
        'reason' => 'Start with the guided Marketing map.',
    ];
}
$todayActions = array_slice($todayActions, 0, 5);

$systemLinks = [
    'contacts.php' => 'Contacts',
    'companies.php' => 'Companies',
    'deals.php' => 'Deals',
    'campaigns.php' => 'Campaigns',
    'forms.php' => 'Forms',
    'email_templates.php' => 'Email Templates',
    'tasks.php' => 'Tasks',
    'marketing_handoffs.php' => 'Lead Handoffs',
];
$advancedLinks = [
    'marketing.php' => 'Command Center',
    'marketing_system_map.php' => 'System Map',
    'marketing_execution.php' => 'Execution Center',
    'marketing_decisions.php' => 'Decision Center',
    'marketing_operations.php' => 'Operations',
    'marketing_admin.php' => 'Admin Diagnostics',
];

$pageTitle = 'Marketing Action Router - ' . brandProductName();
ob_start();
?>
<link rel="stylesheet" href="assets/css/premium-pages.css">
<div class="page-premium marketing-action-router-page"><div class="container">
    <div class="page-header marketing-page-header">
        <div>
            <h1>Marketing Action Router</h1>
            <p>Choose the next useful Marketing move.</p>
        </div>
        <div class="page-header-actions marketing-page-actions">
            <a class="btn-premium-secondary" href="marketing.php"><i class="fas fa-arrow-left"></i> Marketing</a>
            <?php if ($canWriteMarketing): ?>
                <form method="POST" class="marketing-action-router-refresh-form">
                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                    <input type="hidden" name="action" value="generate_snapshot">
                    <button class="btn-premium-primary" type="submit"><i class="fas fa-rotate"></i> Refresh Next Moves</button>
                </form>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($error !== ''): ?><div class="alert alert-danger"><strong>Action Router update failed.</strong> <?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if (($_GET['success'] ?? '') === 'generated'): ?><div class="alert alert-success">Action Router recommendations refreshed.</div><?php endif; ?>
    <?php if (($_GET['success'] ?? '') === 'updated'): ?><div class="alert alert-success">Action Router item updated.</div><?php endif; ?>

    <div class="marketing-action-router-shell">
        <div class="marketing-action-router-summary marketing-founder-summary">
            <div class="marketing-summary-tile" data-tooltip="Actions that are currently available from router evidence." tabindex="0">
                <i class="fa-solid fa-compass" aria-hidden="true"></i>
                <div><span>Active</span><strong><?php echo $activeActions; ?></strong></div>
            </div>
            <div class="marketing-summary-tile" data-tooltip="Items that should be handled first." tabindex="0">
                <i class="fa-solid fa-signal" aria-hidden="true"></i>
                <div><span>Priority</span><strong><?php echo $highPriorityActions; ?></strong></div>
            </div>
            <div class="marketing-summary-tile" data-tooltip="Saved open router items for team visibility." tabindex="0">
                <i class="fa-solid fa-layer-group" aria-hidden="true"></i>
                <div><span>Open</span><strong><?php echo $storedOpenActions; ?></strong></div>
            </div>
            <div class="marketing-summary-tile" data-tooltip="Live recommendations generated from the current workspace signals." tabindex="0">
                <i class="fa-solid fa-bolt" aria-hidden="true"></i>
                <div><span>Signals</span><strong><?php echo $liveSignals; ?></strong></div>
            </div>
        </div>

        <div class="marketing-action-router-stage-grid">
            <?php foreach ($routeStages as $stage): ?>
                <a class="marketing-action-router-stage-card <?php echo htmlspecialchars($stageClass((string) $stage['status'])); ?>" href="<?php echo htmlspecialchars((string) $stage['href']); ?>" data-tooltip="<?php echo htmlspecialchars((string) $stage['tooltip']); ?>" tabindex="0">
                    <div class="marketing-stage-visual marketing-action-router-stage-visual">
                        <i class="fa-solid <?php echo htmlspecialchars((string) $stage['icon']); ?>" aria-hidden="true"></i>
                        <span class="badge <?php echo htmlspecialchars($stageStatusClass((string) $stage['status'])); ?>"><?php echo htmlspecialchars((string) $stage['status']); ?></span>
                    </div>
                    <div class="marketing-action-router-stage-body">
                        <h2><?php echo htmlspecialchars((string) $stage['label']); ?></h2>
                        <div class="marketing-action-router-stage-metric"><strong><?php echo (int) $stage['score']; ?>%</strong><span>ready</span></div>
                        <span class="btn-premium-secondary marketing-action-router-stage-action"><?php echo htmlspecialchars((string) $stage['action']); ?></span>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="marketing-action-router-layout">
            <div class="content-card marketing-action-router-board" id="router-queue">
                <div class="premium-section-header">
                    <div>
                        <h2>Route Board</h2>
                        <p><?php echo htmlspecialchars($labelize($statusFilter)); ?> view. Open one target and act manually.</p>
                    </div>
                    <span class="badge badge-info"><?php echo count($items); ?> shown</span>
                </div>

                <?php if ($items === []): ?>
                    <div class="empty-state"><p>No router items match this view.</p></div>
                <?php else: ?>
                    <div class="marketing-action-router-list">
                        <?php foreach ($items as $item): ?>
                            <?php
                            $status = (string) ($item['action_status'] ?? 'suggested');
                            $priority = (string) ($item['priority'] ?? 'normal');
                            $tooltip = trim((string) ($item['reason'] ?? $item['recommended_action'] ?? 'Open the linked target and act manually.'));
                            ?>
                            <article class="marketing-action-router-card <?php echo htmlspecialchars($priority); ?>" data-tooltip="<?php echo htmlspecialchars($tooltip); ?>" tabindex="0">
                                <div class="marketing-action-router-card-main">
                                    <div class="marketing-action-router-meta">
                                        <span class="badge <?php echo htmlspecialchars($priorityClass($priority)); ?>"><?php echo htmlspecialchars($labelize($priority)); ?></span>
                                        <span class="badge <?php echo htmlspecialchars($statusClass($status)); ?>"><?php echo htmlspecialchars($labelize($status)); ?></span>
                                        <span><?php echo htmlspecialchars($labelize((string) ($item['action_type'] ?? 'custom'))); ?></span>
                                    </div>
                                    <h3><?php echo htmlspecialchars((string) ($item['label'] ?? 'Review action')); ?></h3>
                                    <span><?php echo htmlspecialchars((string) ($item['source'] ?? 'Marketing')); ?> / <?php echo htmlspecialchars((string) ($item['target_system'] ?? 'marketing')); ?></span>
                                </div>
                                <div class="marketing-action-router-card-actions">
                                    <a class="btn-premium-primary" href="<?php echo htmlspecialchars((string) ($item['target_href'] ?? 'marketing.php')); ?>">Open Target</a>
                                    <?php if (($canWriteMarketing && in_array($status, ['suggested', 'accepted'], true) && !empty($item['id'])) || ($canManageMarketing && !empty($item['id']) && $status !== 'archived')): ?>
                                        <details class="marketing-action-router-card-tools">
                                            <summary>Update state</summary>
                                            <div>
                                                <?php if ($canWriteMarketing && in_array($status, ['suggested', 'accepted'], true) && !empty($item['id'])): ?>
                                                    <?php foreach (['accepted' => 'Accept', 'resolved' => 'Resolve', 'dismissed' => 'Dismiss'] as $nextStatus => $label): ?>
                                                        <form method="POST">
                                                            <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                                            <input type="hidden" name="action" value="update_status">
                                                            <input type="hidden" name="item_id" value="<?php echo (int) $item['id']; ?>">
                                                            <input type="hidden" name="action_status" value="<?php echo htmlspecialchars($nextStatus); ?>">
                                                            <button class="btn-premium-secondary" type="submit"><?php echo htmlspecialchars($label); ?></button>
                                                        </form>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                                <?php if ($canManageMarketing && !empty($item['id']) && $status !== 'archived'): ?>
                                                    <form method="POST">
                                                        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                                        <input type="hidden" name="action" value="update_status">
                                                        <input type="hidden" name="item_id" value="<?php echo (int) $item['id']; ?>">
                                                        <input type="hidden" name="action_status" value="archived">
                                                        <button class="btn-premium-secondary" type="submit">Archive</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </details>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <aside class="content-card marketing-action-router-today">
                <div class="premium-section-header">
                    <div>
                        <h2>Today</h2>
                        <p>Keep the next move small.</p>
                    </div>
                    <span class="badge badge-info"><?php echo count($todayActions); ?>/5</span>
                </div>
                <div class="marketing-action-router-today-list">
                    <?php foreach ($todayActions as $action): ?>
                        <?php if (!empty($action['form'])): ?>
                            <form method="POST" class="marketing-action-router-today-action" data-tooltip="<?php echo htmlspecialchars((string) $action['reason']); ?>" tabindex="0">
                                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                <input type="hidden" name="action" value="generate_snapshot">
                                <button class="btn-premium-primary" type="submit"><?php echo htmlspecialchars((string) ($action['label'] === 'Refresh Router' ? 'Refresh Next Moves' : $action['label'])); ?></button>
                                <span><?php echo htmlspecialchars((string) $action['source']); ?></span>
                            </form>
                        <?php else: ?>
                            <a class="marketing-action-router-today-action" href="<?php echo htmlspecialchars((string) ($action['href'] ?? 'marketing_action_router.php')); ?>" data-tooltip="<?php echo htmlspecialchars((string) $action['reason']); ?>" tabindex="0">
                                <strong><?php echo htmlspecialchars((string) $action['label']); ?></strong>
                                <span><?php echo htmlspecialchars((string) $action['source']); ?></span>
                            </a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
            </aside>
        </div>

        <details class="content-card marketing-action-router-tools" id="routing-tools">
            <summary>More routing tools</summary>
            <div class="marketing-action-router-tools-body">
                <div class="marketing-advanced-tools-grid">
                    <?php foreach ($advancedLinks as $href => $label): ?>
                        <a href="<?php echo htmlspecialchars($href); ?>" data-tooltip="Open the expert Marketing surface." tabindex="0">
                            <strong><?php echo htmlspecialchars($label); ?></strong>
                            <span>Advanced route</span>
                        </a>
                    <?php endforeach; ?>
                </div>

                <section class="marketing-action-router-detail-section">
                    <div class="premium-section-header">
                        <div>
                            <h2>Filter Queue</h2>
                            <p>Change the saved item view.</p>
                        </div>
                    </div>
                    <form class="marketing-action-router-filter" method="GET">
                        <label>Status
                            <select name="status" class="form-control">
                                <?php foreach (['open', 'suggested', 'accepted', 'resolved', 'dismissed', 'archived', 'all'] as $status): ?><option value="<?php echo htmlspecialchars($status); ?>" <?php echo $statusFilter === $status ? 'selected' : ''; ?>><?php echo htmlspecialchars($labelize($status)); ?></option><?php endforeach; ?>
                            </select>
                        </label>
                        <button class="btn-premium-secondary" type="submit">Filter</button>
                    </form>
                </section>

                <section class="marketing-action-router-detail-section">
                    <div class="premium-section-header">
                        <div>
                            <h2>CRM Integration Action Center</h2>
                            <p><?php echo htmlspecialchars((string) ($integrationCenter['scope_label'] ?? 'Workspace lifecycle')); ?></p>
                        </div>
                        <span class="badge <?php echo $integrationStatus === 'ready' ? 'badge-success' : ($integrationStatus === 'missing' ? 'badge-danger' : 'badge-warning'); ?>"><?php echo $integrationScore; ?>% <?php echo htmlspecialchars($integrationStatusLabel); ?></span>
                    </div>
                    <div class="marketing-action-router-lane-grid">
                        <?php foreach ((array) ($integrationCenter['lanes'] ?? []) as $lane): ?>
                            <a class="marketing-action-router-lane-card" href="<?php echo htmlspecialchars((string) ($lane['href'] ?? 'marketing_action_router.php')); ?>" data-tooltip="<?php echo htmlspecialchars((string) ($lane['summary'] ?? 'Review linked CRM evidence.')); ?>" tabindex="0">
                                <strong><?php echo htmlspecialchars((string) ($lane['label'] ?? 'CRM Lane')); ?><span class="badge <?php echo (string) ($lane['status'] ?? '') === 'ready' ? 'badge-success' : 'badge-warning'; ?>"><?php echo (int) ($lane['score'] ?? 0); ?>%</span></strong>
                                <span><?php echo htmlspecialchars((string) ($lane['action'] ?? 'Open this CRM surface.')); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </section>

                <div class="marketing-action-router-detail-grid">
                    <section class="marketing-action-router-detail-section">
                        <div class="premium-section-header"><h2>Connected Systems</h2></div>
                        <div class="marketing-action-router-mini-grid">
                            <?php foreach ($systemLinks as $href => $label): ?>
                                <a class="marketing-action-router-mini-card" href="<?php echo htmlspecialchars($href); ?>" data-tooltip="Open the CRM surface used by router recommendations." tabindex="0">
                                    <strong><?php echo htmlspecialchars($label); ?></strong>
                                    <span>CRM surface</span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </section>

                    <section class="marketing-action-router-detail-section">
                        <div class="premium-section-header"><h2>Router Guardrails</h2></div>
                        <div class="marketing-action-router-mini-grid">
                            <?php foreach ((array) ($router['guardrails'] ?? []) as $key => $value): ?>
                                <div class="marketing-action-router-mini-card">
                                    <strong><?php echo htmlspecialchars($labelize((string) $key)); ?></strong>
                                    <span><?php echo is_bool($value) ? ($value ? 'Enabled' : 'Off') : htmlspecialchars((string) $value); ?></span>
                                </div>
                            <?php endforeach; ?>
                            <?php if (empty($router['guardrails'])): ?>
                                <div class="marketing-action-router-mini-card"><strong>No guardrails yet</strong><span>Refresh the router.</span></div>
                            <?php endif; ?>
                        </div>
                    </section>
                </div>

                <section class="marketing-action-router-detail-section">
                    <div class="premium-section-header">
                        <div>
                            <h2>Product Decision</h2>
                            <p>No recommendation sends, publishes, starts ads, or calls external APIs.</p>
                        </div>
                        <span class="badge badge-info">Context <?php echo $contextCount; ?></span>
                    </div>
                    <p><?php echo htmlspecialchars((string) ($router['recommended_product_decision'] ?? 'Keep Marketing connected to CRM execution.')); ?></p>
                    <span class="marketing-action-router-guardrail">Manual-first routing only.</span>
                </section>
            </div>
        </details>
    </div>
</div></div>
<?php $content = ob_get_clean(); include __DIR__ . '/../views/layouts/base.php';
