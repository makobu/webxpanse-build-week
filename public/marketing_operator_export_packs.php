<?php
/**
 * Marketing operator export packs.
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Invalid CSRF token.');
        }
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'create_pack') {
            if (!$canWriteMarketing) {
                throw new RuntimeException('You do not have permission to create operator export packs.');
            }
            $packId = $marketing->createOperatorExportPack((int) ($_POST['campaign_id'] ?? 0), (int) ($user['id'] ?? 0));
            header('Location: ' . getBasePath() . '/marketing_operator_export_packs.php?id=' . $packId . '&success=created');
            exit;
        }
        if ($action === 'archive_pack') {
            if (!$canManageMarketing) {
                throw new RuntimeException('You do not have permission to archive operator export packs.');
            }
            $packId = (int) ($_POST['pack_id'] ?? 0);
            $marketing->archiveOperatorExportPack($packId, (int) ($user['id'] ?? 0));
            header('Location: ' . getBasePath() . '/marketing_operator_export_packs.php?success=archived');
            exit;
        }
        throw new RuntimeException('Unsupported operator export pack action.');
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$id = (int) ($_GET['id'] ?? 0);
$campaignId = (int) ($_GET['campaign_id'] ?? 0);
$selectedPack = $id > 0 ? $marketing->getOperatorExportPack($id) : null;
$packs = $marketing->listOperatorExportPacks($campaignId > 0 ? ['campaign_id' => $campaignId] : ['open' => true], 100, 0);
$labelize = static fn(string $value): string => ucwords(str_replace('_', ' ', $value));
$h = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$badgeClass = static function (string $status): string {
    return match ($status) {
        'ready' => 'badge-success',
        'blocked' => 'badge-danger',
        'draft', 'warning', 'setup_needed' => 'badge-warning',
        'archived' => 'badge-default',
        default => 'badge-default',
    };
};
$visualStatus = static function (string $status): string {
    return match ($status) {
        'ready' => 'ready',
        'blocked' => 'blocked',
        'archived' => 'locked',
        default => 'setup_needed',
    };
};
$countReady = static fn(array $items): int => count(array_filter($items, static fn(array $item): bool => (string) ($item['status'] ?? '') === 'ready'));
$countHighRisks = static fn(array $items): int => count(array_filter($items, static fn(array $item): bool => (string) ($item['severity'] ?? '') === 'high'));

$selectedPackStatus = (string) ($selectedPack['status'] ?? 'draft');
$exportBundle = (array) ($selectedPack['export_bundle_json'] ?? []);
$riskSnapshot = (array) ($selectedPack['risk_snapshot_json'] ?? []);
$nextSteps = (array) ($selectedPack['next_steps_json'] ?? []);
$integrationLinks = (array) ($selectedPack['integration_links_json'] ?? []);
$guardrails = (array) ($selectedPack['guardrails_json'] ?? []);
$metadata = (array) ($selectedPack['metadata_json'] ?? []);
$readySections = $countReady($exportBundle);
$highRisks = $countHighRisks($riskSnapshot);
$recentReadyPacks = count(array_filter($packs, static fn(array $pack): bool => (string) ($pack['status'] ?? '') === 'ready'));
$recentBlockedPacks = count(array_filter($packs, static fn(array $pack): bool => (string) ($pack['status'] ?? '') === 'blocked'));

$summaryTiles = $selectedPack !== null
    ? [
        ['icon' => 'fa-gauge-high', 'label' => 'Readiness', 'value' => (int) ($selectedPack['readiness_score'] ?? 0) . '%', 'tooltip' => 'Latest readiness score captured when this manual pack was frozen.'],
        ['icon' => 'fa-triangle-exclamation', 'label' => 'Risks', 'value' => (string) count($riskSnapshot), 'tooltip' => 'Open launch risks captured in the pack snapshot.'],
        ['icon' => 'fa-layer-group', 'label' => 'Copy Sections', 'value' => $readySections . '/' . count($exportBundle), 'tooltip' => 'Copy-ready sections available for manual use.'],
        ['icon' => 'fa-list-check', 'label' => 'Next Steps', 'value' => (string) count($nextSteps), 'tooltip' => 'Operator steps to complete before manual publishing.'],
    ]
    : [
        ['icon' => 'fa-boxes-packing', 'label' => 'Open Packs', 'value' => (string) count($packs), 'tooltip' => 'Recent non-archived handoff packs available to review.'],
        ['icon' => 'fa-circle-check', 'label' => 'Ready', 'value' => (string) $recentReadyPacks, 'tooltip' => 'Packs with no major blockers at snapshot time.'],
        ['icon' => 'fa-triangle-exclamation', 'label' => 'Blocked', 'value' => (string) $recentBlockedPacks, 'tooltip' => 'Packs that need attention before manual launch.'],
        ['icon' => 'fa-arrow-right', 'label' => 'Next Action', 'value' => $campaignId > 0 ? 'Create' : 'Choose', 'tooltip' => 'Choose a campaign, then freeze its latest handoff pack.'],
    ];

$stageCards = $selectedPack !== null ? [
    [
        'icon' => 'fa-file-lines',
        'title' => 'Review Copy',
        'status' => $readySections === count($exportBundle) && count($exportBundle) > 0 ? 'ready' : 'setup_needed',
        'sentence' => 'Use the saved campaign copy.',
        'tooltip' => 'Copy-Ready Sections: These are the frozen manual export items from the campaign launch workspace.',
        'href' => '#copy-ready',
        'action' => 'Review Copy',
    ],
    [
        'icon' => 'fa-shield-halved',
        'title' => 'Check Risks',
        'status' => $highRisks > 0 || $selectedPackStatus === 'blocked' ? 'blocked' : 'ready',
        'sentence' => 'Clear blockers before launch.',
        'tooltip' => 'Risk Snapshot: High risks should be resolved before anyone publishes outside the CRM.',
        'href' => '#risk-snapshot',
        'action' => 'Check Risks',
    ],
    [
        'icon' => 'fa-link',
        'title' => 'Use Links',
        'status' => count($integrationLinks) > 0 ? 'ready' : 'setup_needed',
        'sentence' => 'Open the right destinations.',
        'tooltip' => 'Integration links point operators to the supporting campaign, channel, checklist, and execution pages.',
        'href' => '#pack-links',
        'action' => 'View Links',
    ],
    [
        'icon' => 'fa-list-check',
        'title' => 'Follow Steps',
        'status' => count($nextSteps) > 0 ? 'ready' : 'setup_needed',
        'sentence' => 'Do the next safe action.',
        'tooltip' => 'Operator Next Steps: Short actions captured from the launch workspace at pack creation time.',
        'href' => '#operator-next-steps',
        'action' => 'See Steps',
    ],
    [
        'icon' => 'fa-hand',
        'title' => 'Manual Only',
        'status' => !empty($guardrails['manual_first']) ? 'ready' : 'setup_needed',
        'sentence' => 'No automatic publishing.',
        'tooltip' => 'No sending or publishing happens here. This page prepares a safe manual handoff only.',
        'href' => '#pack-guardrails',
        'action' => 'Read Rule',
    ],
] : [
    [
        'icon' => 'fa-magnifying-glass',
        'title' => 'Choose Pack',
        'status' => count($packs) > 0 ? 'ready' : 'setup_needed',
        'sentence' => 'Open the latest handoff.',
        'tooltip' => 'Pick a saved pack to review copy, risks, links, and next steps.',
        'href' => '#recent-packs',
        'action' => 'View Packs',
    ],
    [
        'icon' => 'fa-diagram-project',
        'title' => 'Choose Campaign',
        'status' => $campaignId > 0 ? 'ready' : 'setup_needed',
        'sentence' => 'Start from a campaign.',
        'tooltip' => 'Export packs are created from campaign launch workspace readiness.',
        'href' => 'marketing_campaign_workspace.php',
        'action' => 'Open Campaign',
    ],
    [
        'icon' => 'fa-box-archive',
        'title' => 'Freeze Handoff',
        'status' => $campaignId > 0 && $canWriteMarketing ? 'ready' : 'locked',
        'sentence' => 'Save the current snapshot.',
        'tooltip' => 'Create a pack only after the campaign launch workspace has the latest evidence.',
        'href' => '#create-pack',
        'action' => 'Create Pack',
    ],
];

$todayActions = $selectedPack !== null
    ? array_slice(array_merge(
        array_map(static fn(array $risk): array => [
            'label' => (string) ($risk['label'] ?? 'Review launch risk'),
            'href' => (string) ($risk['href'] ?? 'marketing_campaign_workspace.php?campaign_id=' . (int) ($selectedPack['campaign_id'] ?? 0)),
            'reason' => (string) ($risk['message'] ?? 'Clear this risk before manual launch.'),
            'priority' => (string) ($risk['severity'] ?? 'medium'),
        ], $riskSnapshot),
        array_map(static fn(array $step): array => [
            'label' => (string) ($step['label'] ?? 'Review launch step'),
            'href' => (string) ($step['href'] ?? 'marketing_campaign_workspace.php?campaign_id=' . (int) ($selectedPack['campaign_id'] ?? 0)),
            'reason' => (string) ($step['reason'] ?? 'Review this launch step.'),
            'priority' => (string) ($step['priority'] ?? 'normal'),
        ], $nextSteps)
    ), 0, 5)
    : array_slice(array_map(static fn(array $pack): array => [
        'label' => (string) ($pack['title'] ?? 'Operator Export Pack'),
        'href' => 'marketing_operator_export_packs.php?id=' . (int) ($pack['id'] ?? 0),
        'reason' => (int) ($pack['readiness_score'] ?? 0) . '% readiness',
        'priority' => (string) ($pack['status'] ?? 'draft'),
    ], $packs), 0, 5);

$expertLinks = [
    ['label' => 'Campaign Workspace', 'href' => 'marketing_campaign_workspace.php' . (($selectedPack['campaign_id'] ?? $campaignId) ? '?campaign_id=' . (int) ($selectedPack['campaign_id'] ?? $campaignId) : ''), 'hint' => 'Update campaign readiness and source evidence.'],
    ['label' => 'Channel Exports', 'href' => 'marketing_channel_exports.php', 'hint' => 'Prepare channel-specific files and exports.'],
    ['label' => 'Distribution Queue', 'href' => 'marketing_distribution.php', 'hint' => 'Review manual distribution packages.'],
    ['label' => 'Launch Checklists', 'href' => 'marketing_launch_checklists.php' . (($selectedPack['campaign_id'] ?? $campaignId) ? '?campaign_id=' . (int) ($selectedPack['campaign_id'] ?? $campaignId) : ''), 'hint' => 'Check the human launch checklist.'],
    ['label' => 'Execution Center', 'href' => 'marketing_execution.php', 'hint' => 'Open the operator execution cockpit.'],
    ['label' => 'UTM Links', 'href' => 'marketing_utm_links.php' . (($selectedPack['campaign_id'] ?? $campaignId) ? '?campaign_id=' . (int) ($selectedPack['campaign_id'] ?? $campaignId) : ''), 'hint' => 'Prepare tracking links for manual posts.'],
];

$pageTitle = 'Operator Export Packs - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">

<div class="page-premium marketing-operator-pack-page">
    <div class="container">
        <div class="page-header">
            <div>
                <h1>Operator Export Packs</h1>
                <p>Safe campaign handoffs for manual publishing.</p>
            </div>
            <div class="page-header-actions">
                <a class="btn-premium-secondary" href="marketing_campaign_workspace.php<?php echo $campaignId > 0 ? '?campaign_id=' . (int) $campaignId : ''; ?>"><i class="fas fa-diagram-project"></i> Campaign Workspace</a>
            </div>
        </div>

        <?php if ($error !== ''): ?><div class="alert alert-danger"><?php echo $h($error); ?></div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'created'): ?><div class="alert alert-success">Operator export pack created from the latest campaign readiness snapshot.</div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'archived'): ?><div class="alert alert-success">Operator export pack archived.</div><?php endif; ?>

        <section class="marketing-operator-pack-shell">
            <div class="marketing-founder-summary marketing-operator-pack-summary">
                <?php foreach ($summaryTiles as $tile): ?>
                    <article class="marketing-summary-tile" data-tooltip="<?php echo $h($tile['tooltip']); ?>" tabindex="0">
                        <i class="fas <?php echo $h($tile['icon']); ?>"></i>
                        <div>
                            <span><?php echo $h($tile['label']); ?></span>
                            <strong><?php echo $h($tile['value']); ?></strong>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <div class="marketing-operator-pack-stage-grid">
                <?php foreach ($stageCards as $stage): ?>
                    <article class="marketing-operator-pack-stage-card <?php echo $h($visualStatus((string) $stage['status'])); ?>" data-tooltip="<?php echo $h($stage['tooltip']); ?>" tabindex="0">
                        <div class="marketing-stage-visual"><i class="fas <?php echo $h($stage['icon']); ?>"></i></div>
                        <div class="marketing-operator-pack-stage-body">
                            <span class="badge <?php echo $h($badgeClass((string) $stage['status'])); ?>"><?php echo $h($labelize((string) $stage['status'])); ?></span>
                            <h2><?php echo $h($stage['title']); ?></h2>
                            <p><?php echo $h($stage['sentence']); ?></p>
                        </div>
                        <a class="btn-premium-secondary marketing-operator-pack-stage-action" href="<?php echo $h($stage['href']); ?>"><?php echo $h($stage['action']); ?></a>
                    </article>
                <?php endforeach; ?>
            </div>

            <?php if ($selectedPack !== null): ?>
                <div class="marketing-operator-pack-layout">
                    <main class="marketing-operator-pack-main">
                        <section class="content-card marketing-operator-pack-copy" id="copy-ready">
                            <div class="premium-section-header">
                                <div><h2>Copy-Ready Sections</h2><p>Saved manual export items.</p></div>
                            </div>
                            <?php if (empty($exportBundle)): ?>
                                <div class="empty-state"><p>No copy-ready sections were captured in this pack.</p></div>
                            <?php else: ?>
                                <div class="marketing-operator-pack-section-grid">
                                    <?php foreach ($exportBundle as $section): ?>
                                        <a class="marketing-operator-pack-section-card <?php echo $h($visualStatus((string) ($section['status'] ?? 'warning'))); ?>" href="<?php echo $h($section['href'] ?? '#'); ?>" data-tooltip="<?php echo $h($section['operator_instruction'] ?? $section['detail'] ?? 'Prepare this section before launch.'); ?>" tabindex="0">
                                            <span class="badge <?php echo $h($badgeClass((string) ($section['status'] ?? 'warning'))); ?>"><?php echo $h($labelize((string) ($section['status'] ?? 'warning'))); ?></span>
                                            <strong><?php echo $h($section['label'] ?? 'Export section'); ?></strong>
                                            <span><?php echo $h($section['operator_instruction'] ?? $section['detail'] ?? 'Prepare this section before launch.'); ?></span>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </section>

                        <section class="content-card marketing-operator-pack-risks" id="risk-snapshot">
                            <div class="premium-section-header">
                                <div><h2>Risk Snapshot</h2><p>Resolve blockers before launch.</p></div>
                            </div>
                            <?php if (empty($riskSnapshot)): ?>
                                <div class="empty-state"><p>No risks were captured in this export pack.</p></div>
                            <?php else: ?>
                                <div class="marketing-operator-pack-risk-grid">
                                    <?php foreach ($riskSnapshot as $risk): ?>
                                        <a class="marketing-operator-pack-risk-card <?php echo (string) ($risk['severity'] ?? '') === 'high' ? 'blocked' : 'setup_needed'; ?>" href="<?php echo $h($risk['href'] ?? '#'); ?>" data-tooltip="<?php echo $h($risk['message'] ?? 'Review before launch.'); ?>" tabindex="0">
                                            <span class="badge <?php echo (string) ($risk['severity'] ?? '') === 'high' ? 'badge-danger' : 'badge-warning'; ?>"><?php echo $h($labelize((string) ($risk['severity'] ?? 'medium'))); ?></span>
                                            <strong><?php echo $h($risk['label'] ?? 'Launch risk'); ?></strong>
                                            <span><?php echo $h($risk['source'] ?? 'Launch workspace'); ?></span>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </section>

                        <section class="content-card marketing-operator-pack-next" id="operator-next-steps">
                            <div class="premium-section-header">
                                <div><h2>Operator Next Steps</h2><p>Do these before manual publish.</p></div>
                            </div>
                            <?php if (empty($nextSteps)): ?>
                                <div class="empty-state"><p>No next steps were captured in this pack.</p></div>
                            <?php else: ?>
                                <div class="marketing-operator-pack-step-list">
                                    <?php foreach ($nextSteps as $action): ?>
                                        <a class="marketing-today-action" href="<?php echo $h($action['href'] ?? '#'); ?>">
                                            <span><?php echo $h($action['label'] ?? 'Review launch'); ?></span>
                                            <small><?php echo $h($action['reason'] ?? 'Review this launch step.'); ?></small>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </section>
                    </main>

                    <aside class="content-card marketing-operator-pack-today">
                        <div class="premium-section-header">
                            <div><h2>Today</h2><p>At most five actions.</p></div>
                        </div>
                        <div class="marketing-today-list">
                            <?php if (empty($todayActions)): ?>
                                <div class="empty-state"><p>This pack has no urgent action.</p></div>
                            <?php else: ?>
                                <?php foreach ($todayActions as $action): ?>
                                    <a class="marketing-today-action" href="<?php echo $h($action['href'] ?? '#'); ?>">
                                        <span><?php echo $h($action['label'] ?? 'Review launch'); ?></span>
                                        <small><?php echo $h($action['reason'] ?? 'Check this item.'); ?></small>
                                    </a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <div class="marketing-operator-pack-meta-strip">
                            <span><?php echo $h($selectedPack['campaign_name'] ?? 'Campaign'); ?></span>
                            <span><?php echo $h($selectedPack['prepared_at'] ?? $selectedPack['created_at'] ?? ''); ?></span>
                        </div>
                        <a class="btn-premium-primary marketing-operator-pack-card-action" href="marketing_campaign_workspace.php?campaign_id=<?php echo (int) ($selectedPack['campaign_id'] ?? 0); ?>">Open Campaign</a>
                        <?php if ($canManageMarketing && (string) ($selectedPack['status'] ?? '') !== 'archived'): ?>
                            <form method="POST" class="marketing-operator-pack-archive-form">
                                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                <input type="hidden" name="action" value="archive_pack">
                                <input type="hidden" name="pack_id" value="<?php echo (int) $selectedPack['id']; ?>">
                                <button class="btn-premium-secondary" type="submit">Archive Pack</button>
                            </form>
                        <?php endif; ?>
                    </aside>
                </div>

                <details class="content-card marketing-operator-pack-tools" id="pack-links">
                    <summary>More pack tools</summary>
                    <div class="marketing-operator-pack-tools-body">
                        <section class="content-card marketing-operator-pack-guardrails" id="pack-guardrails">
                            <div class="premium-section-header"><h2>Manual Guardrails</h2></div>
                            <p>No sending or publishing happens here. <?php echo $h($guardrails['message'] ?? 'Manual-first export pack only.'); ?></p>
                        </section>
                        <section class="content-card marketing-operator-pack-metadata" id="pack-metadata">
                            <div class="premium-section-header"><h2>Snapshot Metadata</h2></div>
                            <pre class="marketing-operator-pack-code"><?php echo $h(json_encode([
                                'integration_links' => $integrationLinks,
                                'metadata' => $metadata,
                            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre>
                        </section>
                        <section class="content-card marketing-operator-pack-expert">
                            <div class="premium-section-header"><h2>Advanced Marketing Tools</h2></div>
                            <div class="marketing-advanced-tools-grid">
                                <?php foreach ($expertLinks as $link): ?>
                                    <a href="<?php echo $h($link['href']); ?>" data-tooltip="<?php echo $h($link['hint']); ?>" tabindex="0">
                                        <strong><?php echo $h($link['label']); ?></strong>
                                        <span><?php echo $h($link['hint']); ?></span>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </section>
                    </div>
                </details>
            <?php else: ?>
                <div class="marketing-operator-pack-layout">
                    <main class="content-card marketing-operator-pack-list" id="recent-packs">
                        <div class="premium-section-header">
                            <div><h2>Recent Export Packs</h2><p>Choose one saved handoff.</p></div>
                        </div>
                        <?php if (empty($packs)): ?>
                            <div class="empty-state">
                                <p>No operator export packs are available yet.</p>
                                <a href="marketing_campaign_workspace.php">Open Campaign Workspace</a>
                            </div>
                        <?php else: ?>
                            <div class="marketing-operator-pack-card-grid">
                                <?php foreach ($packs as $pack): ?>
                                    <a class="marketing-operator-pack-row <?php echo $h($visualStatus((string) ($pack['status'] ?? 'draft'))); ?>" href="marketing_operator_export_packs.php?id=<?php echo (int) $pack['id']; ?>" data-tooltip="<?php echo $h((int) ($pack['readiness_score'] ?? 0) . '% readiness for ' . ($pack['campaign_name'] ?? 'Campaign')); ?>" tabindex="0">
                                        <span class="badge <?php echo $h($badgeClass((string) ($pack['status'] ?? 'draft'))); ?>"><?php echo $h($labelize((string) ($pack['status'] ?? 'draft'))); ?></span>
                                        <strong><?php echo $h($pack['title'] ?? 'Operator Export Pack'); ?></strong>
                                        <span><?php echo $h($pack['campaign_name'] ?? 'Campaign'); ?></span>
                                        <small><?php echo $h($pack['prepared_at'] ?? $pack['created_at'] ?? ''); ?></small>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </main>

                    <aside class="content-card marketing-operator-pack-today" id="create-pack">
                        <div class="premium-section-header"><h2>Create Pack</h2></div>
                        <?php if (!$canWriteMarketing): ?>
                            <div class="empty-state"><p>Read-only access. Ask a marketing operator to create an export pack.</p></div>
                        <?php elseif ($campaignId <= 0): ?>
                            <div class="empty-state"><p>Choose a campaign before creating a pack.</p><a href="marketing_campaign_workspace.php">Open Campaign Workspace</a></div>
                        <?php else: ?>
                            <form method="POST" class="marketing-form marketing-operator-pack-create-form">
                                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                <input type="hidden" name="action" value="create_pack">
                                <input type="hidden" name="campaign_id" value="<?php echo (int) $campaignId; ?>">
                                <p>Create a point-in-time handoff from the latest campaign launch workspace.</p>
                                <button class="btn-premium-primary" type="submit">Create Operator Export Pack</button>
                            </form>
                        <?php endif; ?>
                    </aside>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
