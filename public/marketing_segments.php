<?php
/**
 * Marketing audience segments list.
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
        $segmentId = (int) ($_POST['id'] ?? 0);
        if ($action === 'snapshot') {
            if (!$canWriteMarketing) {
                throw new RuntimeException('You do not have permission to snapshot audience segments.');
            }
            $marketing->createAudienceSegmentSnapshot($segmentId, (int) ($user['id'] ?? 0));
            header('Location: ' . getBasePath() . '/marketing_segments.php?success=snapshot');
            exit;
        }
        if ($action === 'refresh_intelligence') {
            if (!$canWriteMarketing) {
                throw new RuntimeException('You do not have permission to refresh audience intelligence.');
            }
            $marketing->getAudienceSegmentIntelligence($segmentId, true);
            header('Location: ' . getBasePath() . '/marketing_segments.php?success=intelligence');
            exit;
        }
        if ($action === 'suggest_audiences') {
            if (!$canWriteMarketing) {
                throw new RuntimeException('You do not have permission to generate audience suggestions.');
            }
            $marketing->suggestAudienceSegmentsFromCrm(8, (int) ($user['id'] ?? 0));
            header('Location: ' . getBasePath() . '/marketing_segments.php?success=suggestions');
            exit;
        }
        if ($action === 'delete') {
            if (!$canManageMarketing) {
                throw new RuntimeException('You do not have permission to delete audience segments.');
            }
            $marketing->deleteAudienceSegment($segmentId);
            header('Location: ' . getBasePath() . '/marketing_segments.php?success=deleted');
            exit;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$filters = [];
$status = trim((string) ($_GET['status'] ?? ''));
$sourceScope = trim((string) ($_GET['source_scope'] ?? ''));
if ($status !== '') {
    $filters['status'] = $status;
}
if ($sourceScope !== '') {
    $filters['source_scope'] = $sourceScope;
}

$segments = $marketing->listAudienceSegments($filters, 100, 0);
$audienceSummary = $marketing->getCrmAudienceIntelligenceSummary();
$activationQuality = $marketing->getAudienceActivationQualitySummary();
$audienceJourneyCenter = $marketing->getAudienceJourneyUsabilityCenter((int) ($user['id'] ?? 0), 8);
$recommendations = $marketing->listAudienceRecommendations(['status' => 'suggested'], 6, 0);
$labelize = static fn(string $value): string => ucwords(str_replace('_', ' ', $value));
$selected = static fn($left, $right): string => (string) $left === (string) $right ? 'selected' : '';
$segmentCount = count($segments);
$activeSegmentCount = count(array_filter($segments, static fn(array $segment): bool => (string) ($segment['status'] ?? '') === 'active'));
$totalPreviewContacts = array_sum(array_map(static fn(array $segment): int => (int) ($segment['preview_count'] ?? 0), $segments));
$snapshotCount = (int) ($audienceSummary['counts']['snapshots'] ?? 0);
$readinessScore = (int) ($audienceSummary['readiness_score'] ?? 0);
$activationScore = (int) ($activationQuality['score'] ?? 0);
$qualityWarnings = (int) ($activationQuality['counts']['warning'] ?? 0);
$qualityBlocked = (int) ($activationQuality['counts']['blocked'] ?? 0);
$qualityGaps = $qualityWarnings + $qualityBlocked;
$ideaCount = count($recommendations);
$audienceJourneyStatus = preg_replace('/[^a-z0-9_-]/i', '', (string) ($audienceJourneyCenter['status'] ?? 'needs_foundation'));
$audienceJourneyCounts = (array) ($audienceJourneyCenter['counts'] ?? []);
$audienceJourneySegmentCounts = (array) ($audienceJourneyCounts['segments'] ?? []);
$audienceJourneyJourneyCounts = (array) ($audienceJourneyCounts['journeys'] ?? []);
$primaryActionUrl = $canWriteMarketing ? 'marketing_segment_edit.php' : 'marketing_segments.php#segment-library';
$primaryActionLabel = $segmentCount > 0 ? 'Review audience' : 'Create segment';
$nextActionUrl = $segmentCount > 0 ? 'marketing_briefs.php' : ($canWriteMarketing ? 'marketing_segment_edit.php' : 'marketing_onboarding.php');
$nextActionLabel = $segmentCount > 0 ? 'Use in brief' : 'Create segment';
$audienceSignals = [
    [
        'label' => 'CRM Signals',
        'count' => (int) ($audienceSummary['counts']['contacts'] ?? 0),
        'status' => (int) ($audienceSummary['counts']['contacts'] ?? 0) > 0 ? 'ready' : 'setup-needed',
        'icon' => 'fa-address-book',
        'href' => 'contacts.php',
        'tooltip' => 'Contacts and CRM data available for audience rules.',
    ],
    [
        'label' => 'Segments',
        'count' => $segmentCount,
        'status' => $segmentCount > 0 ? 'ready' : 'setup-needed',
        'icon' => 'fa-users-gear',
        'href' => $canWriteMarketing ? 'marketing_segment_edit.php' : 'marketing_segments.php#segment-library',
        'tooltip' => 'Saved campaign audiences built from CRM rules.',
    ],
    [
        'label' => 'Snapshots',
        'count' => $snapshotCount,
        'status' => $snapshotCount > 0 ? 'ready' : 'setup-needed',
        'icon' => 'fa-camera-retro',
        'href' => 'marketing_segments.php#segment-library',
        'tooltip' => 'Frozen audience previews that make launch planning safer.',
    ],
    [
        'label' => 'Quality Gaps',
        'count' => $qualityGaps,
        'status' => $qualityGaps > 0 ? 'attention' : 'ready',
        'icon' => 'fa-shield-halved',
        'href' => 'marketing_segments.php#audience-tools',
        'tooltip' => 'Consent, suppression, freshness, and activation blockers.',
    ],
    [
        'label' => 'Ideas',
        'count' => $ideaCount,
        'status' => $ideaCount > 0 ? 'ready' : 'setup-needed',
        'icon' => 'fa-lightbulb',
        'href' => 'marketing_segments.php#audience-tools',
        'tooltip' => 'AI-assisted audience ideas suggested from CRM patterns.',
    ],
];
$pageTitle = 'Audience Segments - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<div class="page-premium marketing-segments-page">
    <div class="container">
        <div class="page-header marketing-page-header">
            <div>
                <h1>Audience Builder</h1>
                <p>Choose who this campaign is for.</p>
            </div>
            <div class="page-header-actions marketing-page-actions">
                <a class="btn-premium-secondary" href="marketing_onboarding.php"><i class="fas fa-arrow-left"></i> Setup</a>
                <?php if ($canWriteMarketing): ?><a class="btn-premium-primary" href="<?php echo htmlspecialchars($primaryActionUrl); ?>"><i class="fas fa-arrow-right"></i> <?php echo htmlspecialchars($primaryActionLabel); ?></a><?php endif; ?>
            </div>
        </div>

        <?php if ($error !== ''): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'snapshot'): ?><div class="alert alert-success">Audience snapshot created.</div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'intelligence'): ?><div class="alert alert-success">Audience intelligence refreshed.</div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'suggestions'): ?><div class="alert alert-success">CRM audience ideas refreshed.</div><?php endif; ?>
        <?php if (($_GET['success'] ?? '') === 'deleted'): ?><div class="alert alert-success">Audience segment deleted.</div><?php endif; ?>

        <section class="marketing-segments-shell" aria-label="Audience builder">
            <div class="marketing-founder-summary marketing-segments-summary">
                <a class="marketing-summary-tile primary" href="<?php echo htmlspecialchars($nextActionUrl); ?>" data-tooltip="The next audience action in the campaign path.">
                    <i class="fas fa-arrow-right"></i><span>Next</span><strong><?php echo htmlspecialchars($nextActionLabel); ?></strong>
                </a>
                <div class="marketing-summary-tile" data-tooltip="Audience readiness is based on CRM signals and segment coverage.">
                    <i class="fas fa-gauge-high"></i><span>Readiness</span><strong><?php echo $readinessScore; ?>%</strong>
                </div>
                <div class="marketing-summary-tile" data-tooltip="Active segments available for campaign planning.">
                    <i class="fas fa-users"></i><span>Active</span><strong><?php echo $activeSegmentCount; ?></strong>
                </div>
                <div class="marketing-summary-tile" data-tooltip="Estimated contacts currently visible through saved segment previews.">
                    <i class="fas fa-address-card"></i><span>Preview</span><strong><?php echo number_format($totalPreviewContacts); ?></strong>
                </div>
            </div>

            <div class="marketing-segment-signal-grid">
                <?php foreach ($audienceSignals as $signal): ?>
                    <?php $signalStatus = preg_replace('/[^a-z0-9_-]/i', '', (string) ($signal['status'] ?? 'setup-needed')); ?>
                    <?php $signalStatusLabel = $labelize(str_replace('-', '_', $signalStatus)); ?>
                    <?php $signalAction = (int) ($signal['count'] ?? 0) > 0 || $signalStatus === 'attention' ? 'Review' : 'Add'; ?>
                    <a class="marketing-segment-signal-card <?php echo htmlspecialchars($signalStatus); ?>" href="<?php echo htmlspecialchars((string) ($signal['href'] ?? 'marketing_segments.php')); ?>" data-tooltip="<?php echo htmlspecialchars((string) ($signal['tooltip'] ?? 'Audience signal.')); ?>">
                        <div class="marketing-segment-signal-visual">
                            <i class="fas <?php echo htmlspecialchars((string) ($signal['icon'] ?? 'fa-users')); ?>"></i>
                            <span class="marketing-segment-signal-count"><?php echo number_format((int) ($signal['count'] ?? 0)); ?></span>
                        </div>
                        <div class="marketing-stage-title-row">
                            <h2><?php echo htmlspecialchars((string) ($signal['label'] ?? 'Signal')); ?></h2>
                            <span class="marketing-stage-badge <?php echo htmlspecialchars($signalStatus); ?>"><?php echo htmlspecialchars($signalStatusLabel); ?></span>
                        </div>
                        <span class="marketing-segment-signal-action"><?php echo htmlspecialchars($signalAction); ?></span>
                    </a>
                <?php endforeach; ?>
            </div>

            <div class="marketing-founder-layout marketing-segments-layout">
                <div class="content-card marketing-segments-list" id="segment-library">
                    <div class="premium-section-header">
                        <div>
                            <h2>Audience Segments</h2>
                            <p><?php echo $segmentCount; ?> saved</p>
                        </div>
                    </div>

                    <form class="marketing-segment-filter" method="GET">
                        <div class="form-group">
                            <label>Status</label>
                            <select name="status">
                                <option value="">All</option>
                                <?php foreach (Marketing::AUDIENCE_SEGMENT_STATUSES as $segmentStatus): ?><option value="<?php echo htmlspecialchars($segmentStatus); ?>" <?php echo $selected($status, $segmentStatus); ?>><?php echo htmlspecialchars($labelize($segmentStatus)); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Source</label>
                            <select name="source_scope">
                                <option value="">All</option>
                                <?php foreach (Marketing::AUDIENCE_SEGMENT_SOURCE_SCOPES as $scope): ?><option value="<?php echo htmlspecialchars($scope); ?>" <?php echo $selected($sourceScope, $scope); ?>><?php echo htmlspecialchars($labelize($scope)); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <button class="btn-premium-secondary" type="submit">Filter</button>
                        <a class="btn-premium-secondary" href="marketing_segments.php">Reset</a>
                    </form>

                    <?php if (empty($segments)): ?>
                        <div class="empty-state marketing-segment-empty">
                            <p>No audience segments match this view.</p>
                            <?php if ($canWriteMarketing): ?><a class="btn-premium-primary" href="marketing_segment_edit.php">Create an audience segment</a><?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="marketing-segment-card-list">
                            <?php foreach ($segments as $segment): ?>
                                <?php $segmentStatus = preg_replace('/[^a-z0-9_-]/i', '', (string) ($segment['status'] ?? 'draft')); ?>
                                <article class="marketing-segment-card marketing-segment-profile-card">
                                    <div>
                                        <a class="segment-title" href="marketing_segment_view.php?id=<?php echo (int) $segment['id']; ?>"><?php echo htmlspecialchars((string) $segment['name']); ?></a>
                                        <div class="marketing-meta">
                                            <span><?php echo htmlspecialchars($labelize((string) ($segment['status'] ?? 'draft'))); ?></span>
                                            <span><?php echo htmlspecialchars($labelize((string) ($segment['source_scope'] ?? 'mixed'))); ?></span>
                                            <span><?php echo (int) ($segment['preview_count'] ?? 0); ?> contacts</span>
                                            <span><?php echo (int) ($segment['snapshot_count'] ?? 0); ?> snapshots</span>
                                            <span><?php echo (int) ($segment['fit_score'] ?? 0); ?> fit</span>
                                        </div>
                                        <?php if (!empty($segment['stale_reason'])): ?><div class="marketing-segment-warning"><?php echo htmlspecialchars((string) $segment['stale_reason']); ?></div><?php endif; ?>
                                        <?php if (!empty($segment['description'])): ?><div class="marketing-segment-body"><?php echo htmlspecialchars((string) $segment['description']); ?></div><?php endif; ?>
                                    </div>
                                    <div class="marketing-segment-actions">
                                        <a class="btn-premium-secondary" href="marketing_segment_view.php?id=<?php echo (int) $segment['id']; ?>">Preview</a>
                                        <details class="marketing-segment-row-tools">
                                            <summary>More</summary>
                                            <div>
                                                <?php if ($canWriteMarketing): ?>
                                                    <a class="btn-premium-secondary" href="marketing_segment_edit.php?id=<?php echo (int) $segment['id']; ?>">Edit</a>
                                                    <form method="POST">
                                                        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                                        <input type="hidden" name="action" value="snapshot">
                                                        <input type="hidden" name="id" value="<?php echo (int) $segment['id']; ?>">
                                                        <button class="btn-premium-secondary" type="submit">Snapshot</button>
                                                    </form>
                                                    <form method="POST">
                                                        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                                        <input type="hidden" name="action" value="refresh_intelligence">
                                                        <input type="hidden" name="id" value="<?php echo (int) $segment['id']; ?>">
                                                        <button class="btn-premium-secondary" type="submit">Refresh Fit</button>
                                                    </form>
                                                <?php endif; ?>
                                                <?php if ($canManageMarketing): ?>
                                                    <form method="POST" onsubmit="return confirm('Delete this audience segment and its snapshots?');">
                                                        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="id" value="<?php echo (int) $segment['id']; ?>">
                                                        <button class="btn-premium-secondary manage-only" type="submit">Delete</button>
                                                    </form>
                                                <?php endif; ?>
                                            </div>
                                        </details>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <aside class="content-card marketing-segments-next">
                    <div class="premium-section-header">
                        <div>
                            <h2>Today</h2>
                            <p>Keep the audience path moving.</p>
                        </div>
                    </div>
                    <div class="marketing-segment-next-list">
                        <a class="marketing-today-action" href="<?php echo htmlspecialchars($nextActionUrl); ?>" data-tooltip="One procedural next step for the founder path.">
                            <i class="fas fa-arrow-right"></i><strong><?php echo htmlspecialchars($nextActionLabel); ?></strong><span><?php echo $segmentCount > 0 ? 'Attach a clear audience to the campaign plan.' : 'Start with one simple CRM-derived audience.'; ?></span>
                        </a>
                        <a class="marketing-today-action" href="marketing_briefs.php" data-tooltip="Turn the selected audience into a simple campaign plan.">
                            <i class="fas fa-clipboard-list"></i><strong>Plan campaign</strong><span>Move from audience to one campaign brief.</span>
                        </a>
                        <a class="marketing-today-action" href="marketing_segments.php#audience-tools" data-tooltip="Quality checks stay available without crowding the first screen.">
                            <i class="fas fa-shield-halved"></i><strong>Review quality</strong><span><?php echo $qualityGaps; ?> gap<?php echo $qualityGaps === 1 ? '' : 's'; ?> visible.</span>
                        </a>
                    </div>
                </aside>
            </div>

            <details class="marketing-advanced-tools marketing-segments-tools" id="audience-tools">
                <summary>More audience tools <i class="fas fa-chevron-down"></i></summary>
                <div class="marketing-segments-tools-body">
                    <div class="marketing-advanced-tools-grid">
                        <a href="marketing_campaign_workspace.php" data-tooltip="Use segments with briefs, journeys, and campaign planning."><strong>Campaign Workspace</strong><span>Plan with audience</span></a>
                        <a href="marketing_personas.php" data-tooltip="Personas clarify why the audience should care."><strong>Personas</strong><span>Customer detail</span></a>
                        <a href="marketing_journeys.php" data-tooltip="Turn audience segments into manual journey paths."><strong>Journey Planner</strong><span>Manual follow-up</span></a>
                        <a href="marketing_audience_activation.php" data-tooltip="Connect a campaign to an activation-ready audience."><strong>Audience Activation</strong><span>Launch prep</span></a>
                    </div>

                    <div class="content-card marketing-segment-tool-card">
                        <div class="premium-section-header">
                            <div><h2>Audience Journey</h2><p>Compact readiness for segments and journeys.</p></div>
                            <span class="audience-intel-pill"><?php echo htmlspecialchars($labelize($audienceJourneyStatus)); ?></span>
                        </div>
                        <div class="marketing-segment-stat-grid">
                            <div class="marketing-segment-stat"><span>Journey Score</span><strong><?php echo (int) ($audienceJourneyCenter['score'] ?? 0); ?>%</strong></div>
                            <div class="marketing-segment-stat"><span>Ready Segments</span><strong><?php echo (int) ($audienceJourneySegmentCounts['ready'] ?? 0); ?>/<?php echo (int) ($audienceJourneySegmentCounts['total'] ?? 0); ?></strong></div>
                            <div class="marketing-segment-stat"><span>Ready Journeys</span><strong><?php echo (int) ($audienceJourneyJourneyCounts['ready'] ?? 0); ?>/<?php echo (int) ($audienceJourneyJourneyCounts['total'] ?? 0); ?></strong></div>
                        </div>
                    </div>

                    <div class="content-card marketing-segment-tool-card">
                        <div class="premium-section-header">
                            <div><h2>Audience Quality</h2><p>Consent, freshness, activation, and fit checks.</p></div>
                            <span class="audience-intel-pill"><?php echo htmlspecialchars($labelize((string) ($activationQuality['status'] ?? 'blocked'))); ?></span>
                        </div>
                        <div class="marketing-segment-stat-grid">
                            <div class="marketing-segment-stat"><span>Quality</span><strong><?php echo $activationScore; ?>/100</strong></div>
                            <div class="marketing-segment-stat"><span>Warnings</span><strong><?php echo $qualityWarnings; ?></strong></div>
                            <div class="marketing-segment-stat"><span>Blocked</span><strong><?php echo $qualityBlocked; ?></strong></div>
                        </div>
                        <div class="marketing-segment-quality-list">
                            <?php foreach (array_slice((array) ($activationQuality['quality_queue'] ?? []), 0, 4) as $item): ?>
                                <?php $qualityStatus = preg_replace('/[^a-z0-9_-]/i', '', (string) ($item['status'] ?? 'warning')); ?>
                                <a class="marketing-segment-quality-item <?php echo htmlspecialchars($qualityStatus); ?>" href="<?php echo htmlspecialchars((string) ($item['href'] ?? 'marketing_segments.php')); ?>">
                                    <span><?php echo htmlspecialchars($labelize($qualityStatus)); ?></span>
                                    <strong><?php echo htmlspecialchars((string) ($item['title'] ?? 'Audience segment')); ?></strong>
                                    <em><?php echo htmlspecialchars((string) ($item['next_action'] ?? 'Review audience quality.')); ?></em>
                                </a>
                            <?php endforeach; ?>
                            <?php if (empty($activationQuality['quality_queue'])): ?><div class="empty-state"><p>No activation quality blockers found.</p></div><?php endif; ?>
                        </div>
                    </div>

                    <div class="content-card marketing-segment-tool-card">
                        <div class="premium-section-header">
                            <div><h2>CRM Intelligence</h2><p>Signal mix and suggested audiences.</p></div>
                            <?php if ($canWriteMarketing): ?>
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                    <input type="hidden" name="action" value="suggest_audiences">
                                    <button class="btn-premium-secondary" type="submit">Find Ideas</button>
                                </form>
                            <?php endif; ?>
                        </div>
                        <div class="marketing-segment-stat-grid">
                            <div class="marketing-segment-stat"><span>Contacts</span><strong><?php echo (int) ($audienceSummary['counts']['contacts'] ?? 0); ?></strong></div>
                            <div class="marketing-segment-stat"><span>Segments</span><strong><?php echo (int) ($audienceSummary['counts']['segments'] ?? 0); ?></strong></div>
                            <div class="marketing-segment-stat"><span>Pipeline</span><strong><?php echo number_format((float) ($audienceSummary['counts']['pipeline_value'] ?? 0)); ?></strong></div>
                        </div>
                        <?php if (!empty($recommendations)): ?>
                            <div class="marketing-segment-idea-grid">
                                <?php foreach ($recommendations as $recommendation): ?>
                                    <div class="marketing-segment-idea-card">
                                        <strong><?php echo htmlspecialchars((string) $recommendation['title']); ?></strong>
                                        <span><?php echo (int) ($recommendation['fit_score'] ?? 0); ?> fit</span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
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
