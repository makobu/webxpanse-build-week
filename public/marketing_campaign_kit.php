<?php
/**
 * Campaign Kit: coordinated, review-first multi-channel content generation.
 */

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
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
use CRM\Modules\Marketing;
use CRM\Security;
use CRM\Services\MarketingCampaignKitService;
use CRM\Services\MarketingMarketplaceGateService;
use CRM\Session;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: ' . getBasePath() . '/login.php');
    exit;
}

$user = Auth::user();
require_once __DIR__ . '/_marketing_marketplace_gate.php';
crm_require_marketing_marketplace_access($user, MarketingMarketplaceGateService::FEATURE_MARKETING_PRO);
if (!Authorization::can('marketing.read', $user)) {
    header('Location: ' . getBasePath() . '/dashboard.php');
    exit;
}

$marketing = new Marketing();
$campaignKit = new MarketingCampaignKitService($marketing);
$canWriteMarketing = Authorization::can('marketing.write', $user);
$userId = (int) ($user['id'] ?? 0);
$error = '';
$notice = trim((string) ($_GET['notice'] ?? ''));
$runId = (int) ($_GET['run'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!Security::validateCSRF((string) ($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Invalid CSRF token. Refresh this page and try again.');
        }
        if (!$canWriteMarketing) {
            throw new RuntimeException('You do not have permission to generate or accept campaign content.');
        }

        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'generate') {
            $run = $campaignKit->generate($_POST, $userId);
            header('Location: marketing_campaign_kit.php?run=' . (int) $run['id'] . '&notice=' . rawurlencode('Campaign Kit generated. Review every artifact before accepting it.'));
            exit;
        }

        $runId = (int) ($_POST['run_id'] ?? $runId);
        if ($action === 'refresh_outcomes') {
            if ($runId <= 0) {
                throw new InvalidArgumentException('Choose a valid Campaign Kit.');
            }
            $campaignKit->refreshOutcomes($runId, $userId);
            header('Location: marketing_campaign_kit.php?run=' . $runId . '&notice=' . rawurlencode('Outcome evidence refreshed from CRM tracking, attribution, and social provider records.'));
            exit;
        }

        $artifactId = (int) ($_POST['artifact_id'] ?? 0);
        if ($artifactId <= 0 || $runId <= 0) {
            throw new InvalidArgumentException('Choose a valid Campaign Kit artifact.');
        }

        if ($action === 'save_artifact') {
            $campaignKit->updateArtifact($artifactId, $_POST);
            $notice = 'Draft changes saved in the Campaign Kit review ledger.';
        } elseif ($action === 'accept_artifact') {
            $contentId = $campaignKit->acceptArtifact($artifactId, $userId);
            $notice = 'Artifact accepted into Content Studio as draft #' . $contentId . '. Nothing was published.';
        } elseif ($action === 'reject_artifact') {
            $campaignKit->rejectArtifact($artifactId, (string) ($_POST['rejection_reason'] ?? ''));
            $notice = 'Artifact rejected. Its source remains in the kit for audit history.';
        } elseif ($action === 'queue_visual') {
            $requestId = $campaignKit->queueVisualBrief($artifactId, $userId);
            $notice = 'Visual direction sent to Design as advisory request #' . $requestId . '. No image was generated.';
        } elseif ($action === 'prepare_handoff') {
            $campaignKit->prepareLaunchHandoff($artifactId, $_POST, $userId);
            $notice = 'Tracked launch package prepared. Nothing was scheduled or published; choose the owning channel workflow when ready.';
        } else {
            throw new InvalidArgumentException('Unsupported Campaign Kit action.');
        }

        header('Location: marketing_campaign_kit.php?run=' . $runId . '&notice=' . rawurlencode($notice));
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$options = $marketing->optionData();
$recentRuns = $campaignKit->listRuns(10);
$currentRun = $runId > 0 ? $campaignKit->getRun($runId) : null;
if ($runId > 0 && !$currentRun && $error === '') {
    $error = 'The requested Campaign Kit was not found in this workspace.';
}

$channelOptions = [
    'linkedin' => ['LinkedIn', 'Professional campaign post'],
    'instagram' => ['Instagram', 'Visual-first social caption'],
    'facebook' => ['Facebook', 'Community-friendly post'],
    'x' => ['X', 'Concise social post'],
    'email' => ['Email', 'Direct campaign email'],
    'blog' => ['Blog', 'Long-form campaign article'],
    'ads' => ['Ads', 'Paid campaign copy'],
    'whatsapp' => ['WhatsApp', 'Conversational customer message'],
];
$postedChannels = array_map('strval', (array) ($_POST['channels'] ?? ['linkedin', 'instagram', 'email']));
$selected = static fn(string $field, int $id): string => (int) ($_POST[$field] ?? 0) === $id ? ' selected' : '';
$statusLabel = static fn(string $value): string => ucwords(str_replace('_', ' ', $value));
$contextCounts = [
    'Brand profiles' => count((array) ($options['brand_profiles'] ?? [])),
    'Personas' => count((array) ($options['personas'] ?? [])),
    'Campaign briefs' => count((array) ($options['campaign_briefs'] ?? [])),
    'Audience segments' => count((array) ($options['audience_segments'] ?? [])),
];
$contextReadyCount = count(array_filter($contextCounts, static fn(int $count): bool => $count > 0));

$pageTitle = 'Campaign Kit - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<link rel="stylesheet" href="assets/css/marketing-campaign-kit.css">

<div class="page-premium marketing-ui-page campaign-kit-page">
    <div class="container">
        <header class="page-header campaign-kit-header">
            <div>
                <span class="campaign-kit-eyebrow">Campaign workflow / Content-to-revenue</span>
                <h1>Campaign Kit</h1>
                <p>Turn one business objective into coordinated channel drafts and visual directions, then approve only what is worth using.</p>
            </div>
            <div class="page-header-actions">
                <a class="btn-premium-secondary" href="marketing_content.php"><i class="fas fa-layer-group"></i> Content Studio</a>
                <?php if ($currentRun): ?><a class="btn-premium-primary" href="#campaign-kit-builder"><i class="fas fa-wand-magic-sparkles"></i> New Kit</a><?php endif; ?>
            </div>
        </header>

        <?php if ($error !== ''): ?>
            <div class="alert alert-danger"><strong>Campaign Kit could not complete that action.</strong> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($notice !== ''): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($notice); ?></div>
        <?php endif; ?>

        <section class="campaign-kit-value-strip" aria-label="Campaign Kit safeguards">
            <div><i class="fas fa-bullseye"></i><span><strong>One message house</strong>Shared promise, audience, and CTA</span></div>
            <div><i class="fas fa-layer-group"></i><span><strong>Coordinated artifacts</strong>Channel-specific, not copy-pasted</span></div>
            <div><i class="fas fa-user-check"></i><span><strong>Human acceptance</strong>No automatic publishing or overwrite</span></div>
            <div><i class="fas fa-chart-line"></i><span><strong>CRM lineage</strong>Accepted drafts retain source context</span></div>
        </section>

        <?php if ($currentRun): ?>
            <?php $summary = (array) ($currentRun['state_summary'] ?? []); ?>
            <section class="campaign-kit-run" aria-labelledby="campaign-kit-run-title">
                <div class="campaign-kit-run-heading">
                    <div>
                        <span class="campaign-kit-eyebrow">Review canvas</span>
                        <h2 id="campaign-kit-run-title"><?php echo htmlspecialchars((string) $currentRun['title']); ?></h2>
                        <p><?php echo htmlspecialchars((string) $currentRun['objective']); ?></p>
                    </div>
                    <div class="campaign-kit-run-status">
                        <span class="campaign-kit-status is-<?php echo htmlspecialchars((string) $currentRun['status']); ?>"><?php echo htmlspecialchars($statusLabel((string) $currentRun['status'])); ?></span>
                        <span><?php echo (int) ($summary['accepted'] ?? 0); ?> of <?php echo (int) ($summary['total'] ?? 0); ?> accepted</span>
                    </div>
                </div>

                <div class="campaign-kit-message-house">
                    <?php $messageHouse = (array) ($currentRun['message_house_json'] ?? []); ?>
                    <div class="campaign-kit-message-main">
                        <span>Core message</span>
                        <strong><?php echo htmlspecialchars((string) ($messageHouse['core_message'] ?? '')); ?></strong>
                    </div>
                    <div><span>Audience</span><strong><?php echo htmlspecialchars((string) ($messageHouse['audience_need'] ?? '')); ?></strong></div>
                    <div><span>Campaign promise</span><strong><?php echo htmlspecialchars((string) ($messageHouse['campaign_promise'] ?? '')); ?></strong></div>
                    <div><span>Primary CTA</span><strong><?php echo htmlspecialchars((string) ($messageHouse['cta'] ?? '')); ?></strong></div>
                </div>

                <?php
                $runOutcome = (array) ($currentRun['outcome_json'] ?? []);
                $outcomeTotals = (array) ($runOutcome['totals'] ?? []);
                $signalCounts = (array) ($runOutcome['signal_counts'] ?? []);
                ?>
                <section class="campaign-kit-outcomes" aria-labelledby="campaign-kit-outcomes-title">
                    <div class="campaign-kit-outcomes-head">
                        <div>
                            <span class="campaign-kit-eyebrow">Content-to-revenue evidence</span>
                            <h3 id="campaign-kit-outcomes-title">What this kit is producing</h3>
                            <p>Only recorded CRM events, attribution, and latest provider snapshots are counted.</p>
                        </div>
                        <?php if ($canWriteMarketing): ?>
                            <form method="POST">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
                                <input type="hidden" name="action" value="refresh_outcomes">
                                <input type="hidden" name="run_id" value="<?php echo (int) $currentRun['id']; ?>">
                                <button class="btn-premium-secondary" type="submit"><i class="fas fa-rotate"></i> Refresh evidence</button>
                            </form>
                        <?php endif; ?>
                    </div>
                    <?php if ($runOutcome === []): ?>
                        <div class="campaign-kit-outcomes-empty">
                            <i class="fas fa-chart-line"></i>
                            <span><strong>No outcome snapshot yet</strong>Accept an artifact, prepare a tracked launch, then refresh after real activity occurs.</span>
                        </div>
                    <?php else: ?>
                        <div class="campaign-kit-outcome-grid">
                            <div><span>Tracked visitors</span><strong><?php echo number_format((int) ($outcomeTotals['unique_visitors'] ?? 0)); ?></strong></div>
                            <div><span>CTA + social clicks</span><strong><?php echo number_format((int) ($outcomeTotals['cta_clicks'] ?? 0) + (int) ($outcomeTotals['social_clicks'] ?? 0)); ?></strong></div>
                            <div><span>CRM conversions</span><strong><?php echo number_format((int) ($outcomeTotals['conversions'] ?? 0)); ?></strong></div>
                            <div><span>Deals influenced</span><strong><?php echo number_format((int) ($outcomeTotals['deals'] ?? 0)); ?></strong></div>
                            <div><span>Attributed revenue</span><strong><?php echo number_format((float) ($outcomeTotals['revenue'] ?? 0), 2); ?></strong></div>
                            <div><span>Provider engagement</span><strong><?php echo number_format((int) ($outcomeTotals['engagements'] ?? 0)); ?></strong></div>
                        </div>
                        <div class="campaign-kit-signal-summary">
                            <span><?php echo (int) ($signalCounts['revenue_proven'] ?? 0); ?> revenue proven</span>
                            <span><?php echo (int) ($signalCounts['winning'] ?? 0); ?> winning</span>
                            <span><?php echo (int) ($signalCounts['emerging'] ?? 0); ?> emerging</span>
                            <span><?php echo (int) ($signalCounts['underperforming'] ?? 0); ?> needs attention</span>
                            <small>Refreshed <?php echo htmlspecialchars((string) ($currentRun['outcome_refreshed_at'] ?? '')); ?></small>
                        </div>
                    <?php endif; ?>
                </section>

                <div class="campaign-kit-artifacts">
                    <?php foreach ((array) ($currentRun['artifacts'] ?? []) as $artifact): ?>
                        <?php
                        $artifactStatus = (string) ($artifact['status'] ?? 'failed');
                        $quality = (array) ($artifact['quality_json'] ?? []);
                        $creative = (array) ($artifact['creative_direction_json'] ?? []);
                        $acceptedContentId = (int) ($artifact['accepted_content_item_id'] ?? 0);
                        $visualRequestId = (int) ($artifact['visual_request_id'] ?? 0);
                        $distributionId = (int) ($artifact['distribution_post_id'] ?? 0);
                        $utmLinkId = (int) ($artifact['utm_link_id'] ?? 0);
                        $mediaKitId = (int) ($artifact['channel_media_kit_id'] ?? 0);
                        $handoffStatus = (string) ($artifact['handoff_status'] ?? 'not_prepared');
                        $artifactOutcome = (array) ($artifact['last_outcome_json'] ?? []);
                        $trackedUrl = (string) ($artifact['tracked_url'] ?? '');
                        ?>
                        <article class="campaign-kit-artifact is-<?php echo htmlspecialchars($artifactStatus); ?>">
                            <div class="campaign-kit-artifact-head">
                                <div>
                                    <span class="campaign-kit-channel"><i class="fas fa-paper-plane"></i> <?php echo htmlspecialchars($statusLabel((string) $artifact['channel'])); ?></span>
                                    <h3><?php echo htmlspecialchars((string) $artifact['title']); ?></h3>
                                </div>
                                <div class="campaign-kit-artifact-badges">
                                    <span class="campaign-kit-quality"><?php echo (int) ($quality['score'] ?? 0); ?>% preflight</span>
                                    <span class="campaign-kit-status is-<?php echo htmlspecialchars($artifactStatus); ?>"><?php echo htmlspecialchars($statusLabel($artifactStatus)); ?></span>
                                </div>
                            </div>

                            <?php if ($artifactStatus === 'failed'): ?>
                                <div class="alert alert-danger">This channel failed during generation. Create a new kit to retry it. The other artifacts remain available.</div>
                            <?php else: ?>
                                <form method="POST" class="campaign-kit-review-form">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
                                    <input type="hidden" name="run_id" value="<?php echo (int) $currentRun['id']; ?>">
                                    <input type="hidden" name="artifact_id" value="<?php echo (int) $artifact['id']; ?>">

                                    <label>
                                        <span>Artifact title</span>
                                        <input type="text" name="title" maxlength="255" value="<?php echo htmlspecialchars((string) $artifact['title']); ?>" <?php echo $artifactStatus === 'accepted' ? 'readonly' : ''; ?>>
                                    </label>
                                    <label>
                                        <span>Draft copy</span>
                                        <textarea name="body" rows="11" <?php echo $artifactStatus === 'accepted' ? 'readonly' : ''; ?>><?php echo htmlspecialchars((string) $artifact['body']); ?></textarea>
                                    </label>

                                    <details class="campaign-kit-visual-brief">
                                        <summary><span><i class="fas fa-image"></i> Visual direction</span><small><?php echo htmlspecialchars((string) ($creative['aspect_ratio'] ?? '')); ?> brief</small></summary>
                                        <div>
                                            <label>
                                                <span>Design prompt</span>
                                                <textarea name="visual_prompt" rows="5" <?php echo $artifactStatus === 'accepted' ? 'readonly' : ''; ?>><?php echo htmlspecialchars((string) ($creative['prompt'] ?? '')); ?></textarea>
                                            </label>
                                            <p><strong>Honest boundary:</strong> this is a production-ready visual brief. The current Design bridge does not generate pixels yet.</p>
                                        </div>
                                    </details>

                                    <?php if (!empty($quality['warnings'])): ?>
                                        <div class="campaign-kit-warning"><i class="fas fa-triangle-exclamation"></i><span><?php echo htmlspecialchars(implode(' ', (array) $quality['warnings'])); ?></span></div>
                                    <?php endif; ?>

                                    <div class="campaign-kit-artifact-actions">
                                        <?php if ($artifactStatus !== 'accepted'): ?>
                                            <button class="btn-premium-secondary" type="submit" name="action" value="save_artifact"><i class="fas fa-floppy-disk"></i> Save changes</button>
                                            <button class="btn-premium-primary" type="submit" name="action" value="accept_artifact"><i class="fas fa-check"></i> Accept into Content Studio</button>
                                            <button class="campaign-kit-reject" type="submit" name="action" value="reject_artifact"><i class="fas fa-xmark"></i> Reject</button>
                                        <?php else: ?>
                                            <a class="btn-premium-primary" href="marketing_content_view.php?id=<?php echo $acceptedContentId; ?>"><i class="fas fa-arrow-up-right-from-square"></i> Open accepted draft</a>
                                            <a class="btn-premium-secondary" href="marketing_reviews.php?content_item_id=<?php echo $acceptedContentId; ?>">Review & approval</a>
                                        <?php endif; ?>

                                        <?php if ($visualRequestId > 0): ?>
                                            <a class="btn-premium-secondary" href="marketing_creative.php"><i class="fas fa-pen-ruler"></i> Visual brief #<?php echo $visualRequestId; ?></a>
                                        <?php else: ?>
                                            <button class="btn-premium-secondary" type="submit" name="action" value="queue_visual"><i class="fas fa-pen-ruler"></i> Send brief to Design</button>
                                        <?php endif; ?>
                                    </div>
                                </form>

                                <?php if ($artifactStatus === 'accepted'): ?>
                                    <section class="campaign-kit-launch">
                                        <div class="campaign-kit-launch-head">
                                            <div>
                                                <span>Tracked launch</span>
                                                <strong><?php echo $distributionId > 0 ? htmlspecialchars($statusLabel($handoffStatus)) : 'Not prepared'; ?></strong>
                                            </div>
                                            <?php if (!empty($artifactOutcome['signal_type'])): ?>
                                                <span class="campaign-kit-signal is-<?php echo htmlspecialchars((string) $artifactOutcome['signal_type']); ?>"><?php echo htmlspecialchars($statusLabel((string) $artifactOutcome['signal_type'])); ?></span>
                                            <?php endif; ?>
                                        </div>

                                        <?php if ($distributionId <= 0): ?>
                                            <form method="POST" class="campaign-kit-handoff-form">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
                                                <input type="hidden" name="action" value="prepare_handoff">
                                                <input type="hidden" name="run_id" value="<?php echo (int) $currentRun['id']; ?>">
                                                <input type="hidden" name="artifact_id" value="<?php echo (int) $artifact['id']; ?>">
                                                <label>
                                                    <span>Destination URL <em>Required</em></span>
                                                    <input type="url" name="destination_url" required placeholder="https://example.com/offer">
                                                </label>
                                                <label>
                                                    <span>UTM campaign label</span>
                                                    <input type="text" name="utm_campaign" maxlength="160" value="<?php echo htmlspecialchars(strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', (string) $currentRun['title']))); ?>">
                                                </label>
                                                <div>
                                                    <p><i class="fas fa-shield-halved"></i> Creates draft distribution, tracking, and channel-package records only.</p>
                                                    <button class="btn-premium-primary" type="submit"><i class="fas fa-route"></i> Prepare tracked launch</button>
                                                </div>
                                            </form>
                                        <?php else: ?>
                                            <div class="campaign-kit-launch-links">
                                                <a class="btn-premium-primary" href="marketing_distribution_bundle.php?id=<?php echo $distributionId; ?>"><i class="fas fa-box-open"></i> Open launch bundle</a>
                                                <?php if (in_array((string) $artifact['channel'], ['linkedin', 'instagram', 'facebook'], true)): ?>
                                                    <a class="btn-premium-secondary" href="social_media.php?tab=composer&amp;distribution_post_id=<?php echo $distributionId; ?>&amp;content_item_id=<?php echo $acceptedContentId; ?>&amp;utm_link_id=<?php echo $utmLinkId; ?>"><i class="fas fa-share-nodes"></i> Review in Social Media</a>
                                                <?php endif; ?>
                                                <?php if ($mediaKitId > 0): ?><a class="btn-premium-secondary" href="marketing_channel_exports.php"><i class="fas fa-photo-film"></i> Channel package #<?php echo $mediaKitId; ?></a><?php endif; ?>
                                                <?php if ($trackedUrl !== ''): ?><a class="campaign-kit-tracked-link" href="<?php echo htmlspecialchars($trackedUrl); ?>" target="_blank" rel="noopener"><i class="fas fa-link"></i> Test tracked destination</a><?php endif; ?>
                                            </div>
                                            <p class="campaign-kit-launch-guardrail"><i class="fas fa-user-check"></i> Prepared does not mean published. Scheduling remains inside the channel owner workspace.</p>
                                        <?php endif; ?>

                                        <?php if ($artifactOutcome !== []): ?>
                                            <?php $artifactMetrics = (array) ($artifactOutcome['metrics'] ?? []); ?>
                                            <div class="campaign-kit-artifact-outcome">
                                                <div><span>Visitors</span><strong><?php echo number_format((int) ($artifactMetrics['unique_visitors'] ?? 0)); ?></strong></div>
                                                <div><span>Conversions</span><strong><?php echo number_format((int) ($artifactMetrics['conversions'] ?? 0)); ?></strong></div>
                                                <div><span>Revenue</span><strong><?php echo number_format((float) ($artifactMetrics['revenue'] ?? 0), 2); ?></strong></div>
                                                <p><?php echo htmlspecialchars((string) ($artifactOutcome['recommendation'] ?? '')); ?></p>
                                            </div>
                                        <?php endif; ?>
                                    </section>
                                <?php endif; ?>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <section class="campaign-kit-builder-layout" id="campaign-kit-builder" aria-label="Campaign Kit builder">
            <div class="content-card campaign-kit-builder-card">
                <div class="premium-section-header">
                    <div>
                        <span class="campaign-kit-eyebrow">Guided first win</span>
                        <h2><?php echo $currentRun ? 'Build another kit' : 'Build your first campaign kit'; ?></h2>
                        <p>Give the system the business decision once. It will carry the same message into every selected channel.</p>
                    </div>
                    <span class="campaign-kit-step">1 brief · up to 6 artifacts</span>
                </div>

                <?php if ($canWriteMarketing): ?>
                    <form method="POST" class="campaign-kit-builder-form">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>">
                        <input type="hidden" name="action" value="generate">

                        <div class="campaign-kit-form-grid">
                            <label class="campaign-kit-field-wide">
                                <span>Campaign name <em>Required</em></span>
                                <input type="text" name="title" maxlength="255" required placeholder="Example: July customer reactivation" value="<?php echo htmlspecialchars((string) ($_POST['title'] ?? '')); ?>">
                            </label>
                            <label>
                                <span>Business objective <em>Required</em></span>
                                <textarea name="objective" rows="4" required placeholder="What measurable business change should this campaign create?"><?php echo htmlspecialchars((string) ($_POST['objective'] ?? '')); ?></textarea>
                            </label>
                            <label>
                                <span>Target audience <em>Required</em></span>
                                <textarea name="target_audience" rows="4" required placeholder="Who should act, and what situation are they in?"><?php echo htmlspecialchars((string) ($_POST['target_audience'] ?? '')); ?></textarea>
                            </label>
                            <label>
                                <span>Offer</span>
                                <textarea name="offer_text" rows="3" placeholder="What are you offering? Include real terms only."><?php echo htmlspecialchars((string) ($_POST['offer_text'] ?? '')); ?></textarea>
                            </label>
                            <label>
                                <span>Primary CTA</span>
                                <textarea name="cta_text" rows="3" placeholder="One action: book, reply, request a quote, visit..."><?php echo htmlspecialchars((string) ($_POST['cta_text'] ?? '')); ?></textarea>
                            </label>
                            <label>
                                <span>Funnel stage</span>
                                <select name="funnel_stage">
                                    <?php foreach (['awareness' => 'Awareness', 'consideration' => 'Consideration', 'conversion' => 'Conversion', 'retention' => 'Retention'] as $value => $label): ?>
                                        <option value="<?php echo $value; ?>" <?php echo (string) ($_POST['funnel_stage'] ?? 'consideration') === $value ? 'selected' : ''; ?>><?php echo $label; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label>
                                <span>Existing campaign</span>
                                <select name="campaign_id">
                                    <option value="">No campaign link</option>
                                    <?php foreach ((array) ($options['campaigns'] ?? []) as $option): ?><option value="<?php echo (int) $option['id']; ?>"<?php echo $selected('campaign_id', (int) $option['id']); ?>><?php echo htmlspecialchars((string) $option['name']); ?></option><?php endforeach; ?>
                                </select>
                            </label>
                            <label>
                                <span>Campaign brief</span>
                                <select name="campaign_brief_id">
                                    <option value="">Use submitted brief only</option>
                                    <?php foreach ((array) ($options['campaign_briefs'] ?? []) as $option): ?><option value="<?php echo (int) $option['id']; ?>"<?php echo $selected('campaign_brief_id', (int) $option['id']); ?>><?php echo htmlspecialchars((string) $option['title']); ?></option><?php endforeach; ?>
                                </select>
                            </label>
                            <label>
                                <span>Brand profile</span>
                                <select name="brand_profile_id">
                                    <option value="">Use workspace context</option>
                                    <?php foreach ((array) ($options['brand_profiles'] ?? []) as $option): ?><option value="<?php echo (int) $option['id']; ?>"<?php echo $selected('brand_profile_id', (int) $option['id']); ?>><?php echo htmlspecialchars((string) $option['name']); ?></option><?php endforeach; ?>
                                </select>
                            </label>
                            <label>
                                <span>Persona</span>
                                <select name="persona_id">
                                    <option value="">Use target audience text</option>
                                    <?php foreach ((array) ($options['personas'] ?? []) as $option): ?><option value="<?php echo (int) $option['id']; ?>"<?php echo $selected('persona_id', (int) $option['id']); ?>><?php echo htmlspecialchars((string) $option['name']); ?></option><?php endforeach; ?>
                                </select>
                            </label>
                            <label>
                                <span>CRM audience segment</span>
                                <select name="audience_segment_id">
                                    <option value="">No segment link</option>
                                    <?php foreach ((array) ($options['audience_segments'] ?? []) as $option): ?><option value="<?php echo (int) $option['id']; ?>"<?php echo $selected('audience_segment_id', (int) $option['id']); ?>><?php echo htmlspecialchars((string) $option['name']); ?></option><?php endforeach; ?>
                                </select>
                            </label>
                        </div>

                        <fieldset class="campaign-kit-channels">
                            <legend>Choose channels <small>Select up to six</small></legend>
                            <div>
                                <?php foreach ($channelOptions as $value => [$label, $description]): ?>
                                    <label>
                                        <input type="checkbox" name="channels[]" value="<?php echo $value; ?>" <?php echo in_array($value, $postedChannels, true) ? 'checked' : ''; ?>>
                                        <span><strong><?php echo $label; ?></strong><small><?php echo $description; ?></small></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </fieldset>

                        <div class="campaign-kit-submit-row">
                            <p><i class="fas fa-shield-halved"></i> Generation creates staged drafts only. You will accept, reject, or edit each artifact on the next screen.</p>
                            <button type="submit" class="btn-premium-primary"><i class="fas fa-wand-magic-sparkles"></i> Generate Campaign Kit</button>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="alert alert-warning">You can review Campaign Kits, but a marketing writer permission is required to generate or accept artifacts.</div>
                <?php endif; ?>
            </div>

            <aside class="campaign-kit-sidebar">
                <div class="content-card campaign-kit-context-card">
                    <div class="campaign-kit-context-score"><strong><?php echo $contextReadyCount; ?>/<?php echo count($contextCounts); ?></strong><span>business context areas ready</span></div>
                    <?php foreach ($contextCounts as $label => $count): ?>
                        <div><span><?php echo htmlspecialchars($label); ?></span><strong class="<?php echo $count > 0 ? 'is-ready' : 'is-missing'; ?>"><?php echo $count > 0 ? $count . ' ready' : 'Add context'; ?></strong></div>
                    <?php endforeach; ?>
                    <p>Campaign Kit works without every setup record, but stronger business context produces more useful drafts.</p>
                    <a href="marketing_onboarding.php">Improve Business Brain setup <i class="fas fa-arrow-right"></i></a>
                </div>

                <div class="content-card campaign-kit-recent">
                    <div class="premium-section-header"><div><h2>Recent kits</h2><p>Continue review without regenerating.</p></div></div>
                    <?php if ($recentRuns === []): ?>
                        <p class="campaign-kit-empty">No campaign kits yet.</p>
                    <?php else: ?>
                        <?php foreach ($recentRuns as $recent): ?>
                            <a href="marketing_campaign_kit.php?run=<?php echo (int) $recent['id']; ?>" class="campaign-kit-recent-item <?php echo (int) ($currentRun['id'] ?? 0) === (int) $recent['id'] ? 'is-active' : ''; ?>">
                                <span><strong><?php echo htmlspecialchars((string) $recent['title']); ?></strong><small><?php echo (int) ($recent['accepted_count'] ?? 0); ?>/<?php echo (int) ($recent['artifact_count'] ?? 0); ?> accepted</small></span>
                                <span class="campaign-kit-status is-<?php echo htmlspecialchars((string) $recent['status']); ?>"><?php echo htmlspecialchars($statusLabel((string) $recent['status'])); ?></span>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </aside>
        </section>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
