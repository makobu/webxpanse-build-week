<?php
/**
 * Marketing audience segment detail and preview.
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
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$segment = $id > 0 ? $marketing->getAudienceSegment($id) : null;
if (!$segment) {
    http_response_code(404);
    echo 'Marketing audience segment not found.';
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Invalid CSRF token.');
        }
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'snapshot') {
            if (!$canWriteMarketing) {
                throw new RuntimeException('You do not have permission to snapshot audience segments.');
            }
            $marketing->createAudienceSegmentSnapshot($id, (int) ($user['id'] ?? 0));
            header('Location: ' . getBasePath() . '/marketing_segment_view.php?id=' . $id . '&success=snapshot');
            exit;
        }
        if ($action === 'refresh_intelligence') {
            if (!$canWriteMarketing) {
                throw new RuntimeException('You do not have permission to refresh audience intelligence.');
            }
            $marketing->getAudienceSegmentIntelligence($id, true);
            header('Location: ' . getBasePath() . '/marketing_segment_view.php?id=' . $id . '&success=intelligence');
            exit;
        }
        if ($action === 'delete') {
            if (!$canManageMarketing) {
                throw new RuntimeException('You do not have permission to delete audience segments.');
            }
            $marketing->deleteAudienceSegment($id);
            header('Location: ' . getBasePath() . '/marketing_segments.php?success=deleted');
            exit;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$segment = $marketing->getAudienceSegment($id) ?? $segment;
$preview = $marketing->previewAudienceSegment($id, 50, false);
$intelligence = $marketing->getAudienceSegmentIntelligence($id, false);
$snapshots = $marketing->listAudienceSegmentSnapshots($id, 10, 0);
$linkedBriefs = $marketing->listCampaignBriefs(['audience_segment_id' => $id], 8, 0);
$linkedLandingPages = $marketing->listLandingPages(['audience_segment_id' => $id], 8, 0);
$linkedEmailRuns = $marketing->listEmailCampaignRuns(['audience_segment_id' => $id], 8, 0);
$linkedExportBundles = $marketing->listChannelExportBundles(['audience_segment_id' => $id], 8, 0);
$labelize = static fn(string $value): string => ucwords(str_replace('_', ' ', $value));
$formatRule = static function (array $rule) use ($labelize): string {
    $left = $labelize((string) ($rule['source_type'] ?? 'contact')) . ' / ' . $labelize((string) ($rule['field_key'] ?? 'field'));
    $operator = $labelize((string) ($rule['operator'] ?? 'equals'));
    $value = (string) ($rule['value_text'] ?? '');
    return trim($left . ' ' . $operator . ($value !== '' ? ' ' . $value : ''));
};
$previewCount = (int) ($preview['count'] ?? 0);
$rulesApplied = (int) ($preview['rules_applied'] ?? 0);
$snapshotCount = count($snapshots);
$linkedPlanCount = count($linkedBriefs) + count($linkedLandingPages) + count($linkedEmailRuns) + count($linkedExportBundles);
$fitScore = (int) ($intelligence['fit_score'] ?? 0);
$warningCount = count((array) ($intelligence['warnings'] ?? []));
$recommendationCount = count((array) ($intelligence['recommendations'] ?? []));
$sampleContacts = array_slice((array) ($preview['contacts'] ?? []), 0, 12);
$previewSignals = [
    [
        'label' => 'Contacts',
        'value' => number_format($previewCount),
        'status' => $previewCount > 0 ? 'ready' : 'setup-needed',
        'icon' => 'fa-address-card',
        'tooltip' => 'Contacts currently matching this audience.',
    ],
    [
        'label' => 'Rules',
        'value' => (string) $rulesApplied,
        'status' => $rulesApplied > 0 ? 'ready' : 'setup-needed',
        'icon' => 'fa-filter',
        'tooltip' => 'Rules used to decide who belongs in this audience.',
    ],
    [
        'label' => 'Snapshots',
        'value' => (string) $snapshotCount,
        'status' => $snapshotCount > 0 ? 'ready' : 'setup-needed',
        'icon' => 'fa-camera-retro',
        'tooltip' => 'Stable saved audience lists for manual execution.',
    ],
    [
        'label' => 'Linked Work',
        'value' => (string) $linkedPlanCount,
        'status' => $linkedPlanCount > 0 ? 'ready' : 'setup-needed',
        'icon' => 'fa-link',
        'tooltip' => 'Campaign briefs, landing pages, email runs, or exports using this segment.',
    ],
    [
        'label' => 'Warnings',
        'value' => (string) $warningCount,
        'status' => $warningCount > 0 ? 'attention' : 'ready',
        'icon' => 'fa-shield-halved',
        'tooltip' => 'Fit or data warnings found by CRM intelligence.',
    ],
];
$pageTitle = (string) $segment['name'] . ' - Audience Segment - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<div class="page-premium marketing-segment-view-page">
    <div class="container">
        <div class="page-header">
            <div>
                <h1>Audience Preview</h1>
                <p>Check who this audience includes before launch.</p>
            </div>
            <div class="page-header-actions">
                <a class="btn-premium-secondary" href="marketing_segments.php">Audience Builder</a>
                <?php if ($canWriteMarketing): ?><a class="btn-premium-secondary" href="marketing_segment_edit.php?id=<?php echo (int) $id; ?>">Edit rules</a><?php endif; ?>
                <?php if ($canWriteMarketing): ?>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                        <input type="hidden" name="action" value="snapshot">
                        <input type="hidden" name="id" value="<?php echo (int) $id; ?>">
                        <button class="btn-premium-primary" type="submit">Create snapshot</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($error !== ''): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'saved'): ?><div class="alert alert-success">Audience segment saved.</div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'snapshot'): ?><div class="alert alert-success">Audience snapshot created.</div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'intelligence'): ?><div class="alert alert-success">Audience intelligence refreshed.</div><?php endif; ?>

        <section class="marketing-segment-view-shell" aria-label="Audience Preview">
            <div class="marketing-founder-summary marketing-segment-view-summary">
                <a class="marketing-summary-tile primary" href="#contact-preview" data-tooltip="Review the people this audience currently includes.">
                    <i class="fas fa-arrow-right"></i><span>Next</span><strong>Check contacts</strong>
                </a>
                <div class="marketing-summary-tile" data-tooltip="Current lifecycle status for this audience.">
                    <i class="fas fa-circle-check"></i><span>Status</span><strong><?php echo htmlspecialchars($labelize((string) $segment['status'])); ?></strong>
                </div>
                <div class="marketing-summary-tile" data-tooltip="CRM fit is calculated from contact, deal, form, and handoff evidence.">
                    <i class="fas fa-gauge-high"></i><span>Fit</span><strong><?php echo $fitScore; ?></strong>
                </div>
                <div class="marketing-summary-tile" data-tooltip="Plans and manual execution items connected to this audience.">
                    <i class="fas fa-link"></i><span>Linked</span><strong><?php echo $linkedPlanCount; ?></strong>
                </div>
            </div>

            <div class="marketing-segment-preview-grid">
                <?php foreach ($previewSignals as $signal): ?>
                    <?php $signalStatus = preg_replace('/[^a-z0-9_-]/i', '', (string) ($signal['status'] ?? 'setup-needed')); ?>
                    <a class="marketing-segment-preview-card <?php echo htmlspecialchars($signalStatus); ?>" href="#contact-preview" data-tooltip="<?php echo htmlspecialchars((string) ($signal['tooltip'] ?? 'Audience preview signal.')); ?>">
                        <div class="marketing-stage-visual">
                            <i class="fas <?php echo htmlspecialchars((string) ($signal['icon'] ?? 'fa-users')); ?>"></i>
                            <span><?php echo htmlspecialchars((string) ($signal['value'] ?? '')); ?></span>
                        </div>
                        <div class="marketing-stage-title-row">
                            <h2><?php echo htmlspecialchars((string) ($signal['label'] ?? 'Signal')); ?></h2>
                            <span class="marketing-stage-badge <?php echo htmlspecialchars($signalStatus); ?>"><?php echo htmlspecialchars($labelize(str_replace('-', '_', $signalStatus))); ?></span>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>

            <div class="marketing-founder-layout marketing-segment-view-layout">
                <div class="marketing-segment-view-main">
                    <div class="content-card marketing-segment-rules-preview">
                        <div class="premium-section-header">
                            <div>
                                <h2><?php echo htmlspecialchars((string) $segment['name']); ?></h2>
                                <p><?php echo htmlspecialchars((string) ($segment['rule_logic'] === 'any' ? 'Matches any rule' : 'Matches all rules')); ?></p>
                            </div>
                        </div>
                        <?php if (empty($segment['rules'])): ?>
                            <div class="empty-state"><p>No rules are configured.</p><?php if ($canWriteMarketing): ?><a href="marketing_segment_edit.php?id=<?php echo (int) $id; ?>">Add rules</a><?php endif; ?></div>
                        <?php else: ?>
                            <div class="marketing-segment-rule-chip-list">
                                <?php foreach ((array) $segment['rules'] as $rule): ?><span><?php echo htmlspecialchars($formatRule((array) $rule)); ?></span><?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="content-card marketing-segment-contact-preview" id="contact-preview">
                        <div class="premium-section-header">
                            <div>
                                <h2>Contact Preview</h2>
                                <p><?php echo count($sampleContacts); ?> shown</p>
                            </div>
                        </div>
                        <?php if (empty($sampleContacts)): ?>
                            <div class="empty-state"><p>No contacts currently match this segment.</p></div>
                        <?php else: ?>
                            <div class="marketing-segment-contact-list">
                                <?php foreach ($sampleContacts as $contact): ?>
                                    <?php $contactName = trim((string) ($contact['first_name'] ?? '') . ' ' . (string) ($contact['last_name'] ?? '')) ?: 'Unnamed contact'; ?>
                                    <article class="marketing-segment-contact-card">
                                        <strong><?php echo htmlspecialchars($contactName); ?></strong>
                                        <div class="marketing-meta">
                                            <span><?php echo htmlspecialchars((string) ($contact['email'] ?? 'No email')); ?></span>
                                            <span><?php echo htmlspecialchars($labelize((string) ($contact['stage'] ?? 'No stage'))); ?></span>
                                            <span><?php echo htmlspecialchars($labelize((string) ($contact['lead_source'] ?? 'No source'))); ?></span>
                                        </div>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <aside class="content-card marketing-segment-preview-next">
                    <div class="premium-section-header">
                        <div>
                            <h2>Today</h2>
                            <p>Confirm the audience.</p>
                        </div>
                    </div>
                    <div class="marketing-segment-next-list">
                        <?php if ($canWriteMarketing): ?>
                            <a class="marketing-today-action" href="marketing_segment_edit.php?id=<?php echo (int) $id; ?>" data-tooltip="Adjust rules if the preview is too broad or too narrow.">
                                <i class="fas fa-filter"></i><strong>Refine rules</strong><span><?php echo $rulesApplied; ?> rule<?php echo $rulesApplied === 1 ? '' : 's'; ?> applied.</span>
                            </a>
                        <?php endif; ?>
                        <a class="marketing-today-action" href="marketing_briefs.php" data-tooltip="Use this audience in a campaign brief after it looks right.">
                            <i class="fas fa-clipboard-list"></i><strong>Use in brief</strong><span>Connect audience to a campaign plan.</span>
                        </a>
                        <a class="marketing-today-action" href="#preview-tools" data-tooltip="Snapshots and expert intelligence are available below.">
                            <i class="fas fa-camera-retro"></i><strong>Check tools</strong><span><?php echo $snapshotCount; ?> snapshot<?php echo $snapshotCount === 1 ? '' : 's'; ?> saved.</span>
                        </a>
                    </div>
                </aside>
            </div>

            <details class="marketing-advanced-tools marketing-segment-view-tools" id="preview-tools">
                <summary>More preview tools <i class="fas fa-chevron-down"></i></summary>
                <div class="marketing-segment-view-tools-body">
                    <div class="marketing-advanced-tools-grid">
                        <a href="marketing_segments.php" data-tooltip="Return to the visual audience board."><strong>Audience Builder</strong><span>All segments</span></a>
                        <?php if ($canWriteMarketing): ?><a href="marketing_segment_edit.php?id=<?php echo (int) $id; ?>" data-tooltip="Change the audience rules."><strong>Rule Builder</strong><span>Edit audience</span></a><?php endif; ?>
                        <a href="marketing_audience_activation.php" data-tooltip="Prepare the audience for manual launch work."><strong>Audience Activation</strong><span>Launch prep</span></a>
                    </div>

                    <div class="content-card marketing-segment-tool-card">
                        <div class="premium-section-header">
                            <div><h2>CRM Intelligence</h2><p>Evidence and warnings for this audience.</p></div>
                            <span class="audience-intel-pill"><?php echo $fitScore; ?> fit</span>
                        </div>
                        <div class="marketing-segment-stat-grid">
                            <div class="marketing-segment-stat"><span>Deals</span><strong><?php echo (int) ($intelligence['crm_evidence']['deals']['count'] ?? 0); ?></strong></div>
                            <div class="marketing-segment-stat"><span>Pipeline</span><strong><?php echo number_format((float) ($intelligence['crm_evidence']['deals']['pipeline_value'] ?? 0)); ?></strong></div>
                            <div class="marketing-segment-stat"><span>Advice</span><strong><?php echo $recommendationCount; ?></strong></div>
                        </div>
                        <div class="marketing-segment-quality-list">
                            <?php foreach (array_slice((array) ($intelligence['recommendations'] ?? []), 0, 4) as $recommendation): ?>
                                <div class="marketing-segment-quality-item"><span>Advice</span><strong><?php echo htmlspecialchars((string) $recommendation); ?></strong></div>
                            <?php endforeach; ?>
                            <?php foreach (array_slice((array) ($intelligence['warnings'] ?? []), 0, 4) as $warning): ?>
                                <div class="marketing-segment-quality-item warning"><span>Warning</span><strong><?php echo htmlspecialchars((string) $warning); ?></strong></div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="content-card marketing-segment-tool-card">
                        <div class="premium-section-header"><div><h2>Snapshots</h2><p>Stable manual execution lists.</p></div></div>
                        <?php if (empty($snapshots)): ?><div class="empty-state"><p>No snapshots yet.</p></div><?php else: ?><div class="marketing-segment-quality-list"><?php foreach ($snapshots as $snapshot): ?>
                            <div class="marketing-segment-quality-item"><span><?php echo (int) ($snapshot['contact_count'] ?? 0); ?> contacts</span><strong><?php echo htmlspecialchars((string) $snapshot['name']); ?></strong><em><?php echo htmlspecialchars((string) ($snapshot['created_at'] ?? '')); ?></em></div>
                        <?php endforeach; ?></div><?php endif; ?>
                    </div>

                    <div class="content-card marketing-segment-tool-card">
                        <div class="premium-section-header"><div><h2>Linked Marketing Work</h2><p>Where this audience is already used.</p></div></div>
                        <?php
                        $linkedGroups = [
                            'Briefs' => ['rows' => $linkedBriefs, 'url' => 'marketing_brief_view.php', 'label' => 'title'],
                            'Landing Pages' => ['rows' => $linkedLandingPages, 'url' => 'marketing_landing_page_view.php', 'label' => 'title'],
                            'Email Runs' => ['rows' => $linkedEmailRuns, 'url' => 'marketing_email_runs.php', 'label' => 'name'],
                            'Channel Exports' => ['rows' => $linkedExportBundles, 'url' => 'marketing_channel_exports.php', 'label' => 'content_title'],
                        ];
                        ?>
                        <div class="marketing-segment-linked-grid">
                            <?php foreach ($linkedGroups as $heading => $group): ?>
                                <div class="marketing-segment-linked-card">
                                    <span><?php echo htmlspecialchars($heading); ?></span>
                                    <strong><?php echo count((array) $group['rows']); ?></strong>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($canManageMarketing): ?>
                            <form method="POST" onsubmit="return confirm('Delete this audience segment and its snapshots?');">
                                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo (int) $id; ?>">
                                <button class="btn-premium-secondary manage-only" type="submit">Delete Segment</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </details>
        </section>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
