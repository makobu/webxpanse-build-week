<?php
/**
 * Marketing persona-to-offer matrix.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/constants.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Modules\Marketing;
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
$matrix = $marketing->getPersonaOfferMatrix();
$labelize = static fn(string $value): string => ucwords(str_replace('_', ' ', $value));
$personaIds = array_unique(array_filter(array_map(static fn(array $row): int => (int) ($row['persona_id'] ?? 0), $matrix)));
$offerIds = array_unique(array_filter(array_map(static fn(array $row): int => (int) ($row['offer_id'] ?? 0), $matrix)));
$briefTotal = array_sum(array_map(static fn(array $row): int => (int) ($row['brief_count'] ?? 0), $matrix));
$connectedRows = count(array_filter($matrix, static fn(array $row): bool => (int) ($row['brief_count'] ?? 0) > 0));
$gapRows = count(array_filter($matrix, static fn(array $row): bool => (int) ($row['brief_count'] ?? 0) === 0 || (int) ($row['offer_id'] ?? 0) === 0));
$fitScore = count($matrix) > 0 ? (int) round(($connectedRows / count($matrix)) * 100) : 0;
$firstGap = (array) (array_values(array_filter($matrix, static fn(array $row): bool => (int) ($row['brief_count'] ?? 0) === 0 || (int) ($row['offer_id'] ?? 0) === 0))[0] ?? []);
$firstConnected = (array) (array_values(array_filter($matrix, static fn(array $row): bool => (int) ($row['brief_count'] ?? 0) > 0))[0] ?? []);
$badgeClass = static function (string $status): string {
    return match ($status) {
        'ready', 'connected', 'active' => 'badge-success',
        'blocked', 'missing' => 'badge-danger',
        'setup_needed', 'needs_brief', 'warning' => 'badge-warning',
        default => 'badge-default',
    };
};
$summaryTiles = [
    ['icon' => 'fa-user-tag', 'label' => 'Personas', 'value' => (string) count($personaIds), 'tooltip' => 'Customer profiles available for offer matching.'],
    ['icon' => 'fa-box-open', 'label' => 'Offers', 'value' => (string) count($offerIds), 'tooltip' => 'Active offer context items available for campaigns.'],
    ['icon' => 'fa-file-lines', 'label' => 'Briefs', 'value' => (string) $briefTotal, 'tooltip' => 'Campaign briefs already connected to persona-offer pairs.'],
    ['icon' => 'fa-gauge-high', 'label' => 'Fit', 'value' => $fitScore . '%', 'tooltip' => 'Share of visible persona-offer pairs that already have campaign planning evidence.'],
];
$stageCards = [
    [
        'label' => 'Pick Customer',
        'icon' => 'fa-user-check',
        'status' => count($personaIds) > 0 ? 'ready' : 'setup_needed',
        'sentence' => 'Choose who the campaign serves.',
        'tooltip' => 'Expert view: marketing_personas records available in this workspace.',
        'href' => 'marketing_personas.php',
        'action' => 'Open personas',
    ],
    [
        'label' => 'Match Offer',
        'icon' => 'fa-handshake',
        'status' => count($offerIds) > 0 ? 'ready' : 'setup_needed',
        'sentence' => 'Pair the customer with a promise.',
        'tooltip' => 'Expert view: active marketing_context_items where item_type equals offer.',
        'href' => 'marketing_context.php',
        'action' => 'Open offers',
    ],
    [
        'label' => 'Spot Gap',
        'icon' => 'fa-magnifying-glass-chart',
        'status' => $gapRows === 0 && count($matrix) > 0 ? 'ready' : 'setup_needed',
        'sentence' => 'Find unplanned combinations.',
        'tooltip' => 'Expert view: persona-offer rows with zero campaign briefs or no active offer. These are planning gaps, not deleted capability.',
        'href' => $canWriteMarketing ? 'marketing_brief_edit.php' : 'marketing_briefs.php',
        'action' => $canWriteMarketing ? 'Plan gap' : 'Review gaps',
    ],
    [
        'label' => 'Create Brief',
        'icon' => 'fa-pen-to-square',
        'status' => $briefTotal > 0 ? 'ready' : 'setup_needed',
        'sentence' => 'Turn the fit into a campaign.',
        'tooltip' => 'Expert view: campaign brief records connect persona_id and offer_context_item_id.',
        'href' => $canWriteMarketing ? 'marketing_brief_edit.php' : 'marketing_briefs.php',
        'action' => $canWriteMarketing ? 'Create brief' : 'Open briefs',
    ],
    [
        'label' => 'Review Fit',
        'icon' => 'fa-route',
        'status' => $connectedRows > 0 ? 'ready' : 'setup_needed',
        'sentence' => 'Move strong fits into the roadmap.',
        'tooltip' => 'Expert view: connected pairs can move into campaign briefs, roadmap timing, and launch readiness.',
        'href' => 'marketing_roadmap.php',
        'action' => 'Open roadmap',
    ],
];
$todayActions = array_slice(array_values(array_filter([
    $firstGap !== [] ? [
        'label' => 'Plan one gap',
        'href' => $canWriteMarketing ? 'marketing_brief_edit.php' : 'marketing_briefs.php',
        'reason' => 'Choose one unmatched persona-offer pair and turn it into a campaign brief.',
    ] : null,
    $firstConnected !== [] ? [
        'label' => 'Review connected fit',
        'href' => 'marketing_briefs.php',
        'reason' => 'Open briefs and review the pairs already connected to campaign planning.',
    ] : null,
    [
        'label' => 'Open personas',
        'href' => 'marketing_personas.php',
        'reason' => 'Make sure the customer profiles are clear before matching offers.',
    ],
    [
        'label' => 'Open offers',
        'href' => 'marketing_context.php',
        'reason' => 'Keep active offer context ready for campaign planning.',
    ],
    [
        'label' => 'Open roadmap',
        'href' => 'marketing_roadmap.php',
        'reason' => 'Move the strongest fit toward a launch window.',
    ],
])), 0, 5);
$expertLinks = [
    ['label' => 'Marketing', 'href' => 'marketing.php', 'hint' => 'Return to the guided command center.'],
    ['label' => 'Personas', 'href' => 'marketing_personas.php', 'hint' => 'Manage customer profiles.'],
    ['label' => 'Offers', 'href' => 'marketing_context.php', 'hint' => 'Manage active offer context.'],
    ['label' => 'Campaign Briefs', 'href' => 'marketing_briefs.php', 'hint' => 'Connect persona-offer fit to campaign plans.'],
    ['label' => 'Campaign Roadmap', 'href' => 'marketing_roadmap.php', 'hint' => 'Schedule campaign plans into launch windows.'],
    ['label' => 'Launch Readiness', 'href' => 'marketing_launch_readiness.php', 'hint' => 'Check evidence before launching.'],
];
$pageTitle = 'Persona Offer Matrix - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">

<div class="page-premium marketing-persona-offer-page">
    <div class="container">
        <div class="page-header marketing-page-header">
            <div>
                <h1>Persona Offer Matrix</h1>
                <p>Offer Fit: see which customers already have a campaign path.</p>
            </div>
            <div class="page-header-actions">
                <a class="btn-premium-secondary" href="marketing.php"><i class="fas fa-arrow-left"></i> Marketing</a>
                <?php if ($canWriteMarketing): ?><a class="btn-premium-primary" href="marketing_brief_edit.php"><i class="fas fa-plus"></i> New Brief</a><?php endif; ?>
            </div>
        </div>

        <section class="marketing-persona-offer-shell">
            <div class="marketing-founder-summary marketing-persona-offer-summary" aria-label="Persona offer fit summary">
                <?php foreach ($summaryTiles as $tile): ?>
                    <div class="marketing-summary-tile" tabindex="0" data-tooltip="<?php echo htmlspecialchars((string) $tile['tooltip']); ?>">
                        <i class="fas <?php echo htmlspecialchars((string) $tile['icon']); ?>" aria-hidden="true"></i>
                        <span><?php echo htmlspecialchars((string) $tile['label']); ?></span>
                        <strong><?php echo htmlspecialchars((string) $tile['value']); ?></strong>
                    </div>
                <?php endforeach; ?>
            </div>

            <section class="marketing-persona-offer-stage-grid" aria-label="Persona offer path">
                <?php foreach ($stageCards as $stage): ?>
                    <?php $stageStatus = (string) $stage['status']; ?>
                    <article class="marketing-persona-offer-stage-card <?php echo htmlspecialchars($stageStatus); ?>" tabindex="0" data-tooltip="<?php echo htmlspecialchars((string) $stage['tooltip']); ?>">
                        <div class="marketing-stage-visual">
                            <i class="fas <?php echo htmlspecialchars((string) $stage['icon']); ?>" aria-hidden="true"></i>
                        </div>
                        <div class="marketing-persona-offer-stage-body">
                            <span class="badge <?php echo htmlspecialchars($badgeClass($stageStatus)); ?>"><?php echo htmlspecialchars($labelize($stageStatus)); ?></span>
                            <h2><?php echo htmlspecialchars((string) $stage['label']); ?></h2>
                            <p><?php echo htmlspecialchars((string) $stage['sentence']); ?></p>
                            <a class="btn-premium-secondary marketing-persona-offer-stage-action" href="<?php echo htmlspecialchars((string) $stage['href']); ?>"><?php echo htmlspecialchars((string) $stage['action']); ?></a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>

            <div class="marketing-persona-offer-layout">
                <main class="marketing-persona-offer-main">
                    <section class="content-card marketing-persona-offer-board">
                        <div class="premium-section-header">
                            <div>
                                <h2>Offer Fit Board</h2>
                                <p>Choose one fit to plan next.</p>
                            </div>
                            <span class="badge <?php echo $gapRows === 0 && count($matrix) > 0 ? 'badge-success' : 'badge-warning'; ?>"><?php echo $gapRows; ?> gaps</span>
                        </div>

                        <?php if ($matrix === []): ?>
                            <div class="empty-state">
                                <p>Create at least one persona and one active offer to populate this map.</p>
                                <a class="btn-premium-primary" href="marketing_personas.php">Open personas</a>
                            </div>
                        <?php else: ?>
                            <div class="marketing-persona-offer-card-grid">
                                <?php foreach ($matrix as $row): ?>
                                    <?php
                                    $briefCount = (int) ($row['brief_count'] ?? 0);
                                    $hasOffer = (int) ($row['offer_id'] ?? 0) > 0;
                                    $status = $briefCount > 0 ? 'connected' : ($hasOffer ? 'needs_brief' : 'missing');
                                    $tooltip = 'Expert view: persona_id ' . (int) ($row['persona_id'] ?? 0)
                                        . ', offer_id ' . (int) ($row['offer_id'] ?? 0)
                                        . ', brief_count ' . $briefCount . '.';
                                    ?>
                                    <article class="marketing-persona-offer-card <?php echo htmlspecialchars($status); ?>" tabindex="0" data-tooltip="<?php echo htmlspecialchars($tooltip); ?>">
                                        <div class="marketing-persona-offer-card-top">
                                            <div class="marketing-stage-visual">
                                                <i class="fas fa-handshake-angle" aria-hidden="true"></i>
                                            </div>
                                            <span class="badge <?php echo htmlspecialchars($badgeClass($status)); ?>"><?php echo htmlspecialchars($labelize($status)); ?></span>
                                        </div>
                                        <div>
                                            <h3><?php echo htmlspecialchars((string) ($row['persona_name'] ?? 'No persona')); ?></h3>
                                            <div class="marketing-persona-offer-meta">
                                                <span><?php echo htmlspecialchars((string) ($row['offer_title'] ?? 'Offer needed')); ?></span>
                                                <span><?php echo $briefCount; ?> briefs</span>
                                            </div>
                                        </div>
                                        <div class="marketing-persona-offer-fit">
                                            <strong><?php echo $briefCount > 0 ? 'Campaign path exists' : 'Needs a plan'; ?></strong>
                                            <small><?php echo $briefCount > 0 ? 'Use this fit in roadmap planning.' : 'Create a brief when the fit feels strong.'; ?></small>
                                        </div>
                                        <a class="btn-premium-primary marketing-persona-offer-card-action" href="<?php echo $briefCount > 0 ? 'marketing_briefs.php' : ($canWriteMarketing ? 'marketing_brief_edit.php' : 'marketing_briefs.php'); ?>"><?php echo $briefCount > 0 ? 'Open briefs' : ($canWriteMarketing ? 'Create brief' : 'Review briefs'); ?></a>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </section>
                </main>

                <aside class="marketing-persona-offer-today content-card" aria-label="Today">
                    <div class="premium-section-header">
                        <div>
                            <h2>Today</h2>
                            <p>Match one customer to one offer.</p>
                        </div>
                    </div>
                    <div class="marketing-playbook-next-list">
                        <?php foreach ($todayActions as $action): ?>
                            <a class="marketing-today-action" href="<?php echo htmlspecialchars((string) $action['href']); ?>">
                                <span><?php echo htmlspecialchars((string) $action['label']); ?></span>
                                <small><?php echo htmlspecialchars((string) $action['reason']); ?></small>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </aside>
            </div>

            <details class="content-card marketing-persona-offer-tools">
                <summary>More fit tools</summary>
                <div class="marketing-persona-offer-tools-body">
                    <section class="marketing-persona-offer-tool-card">
                        <div class="premium-section-header">
                            <div>
                                <h2>Expert Routes</h2>
                                <p>Keep deeper planning tools nearby without crowding the board.</p>
                            </div>
                        </div>
                        <div class="marketing-advanced-tools-grid">
                            <?php foreach ($expertLinks as $link): ?>
                                <a href="<?php echo htmlspecialchars((string) $link['href']); ?>">
                                    <span><?php echo htmlspecialchars((string) $link['label']); ?></span>
                                    <small><?php echo htmlspecialchars((string) $link['hint']); ?></small>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </section>
                </div>
            </details>
        </section>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
