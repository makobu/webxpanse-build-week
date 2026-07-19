<?php
/**
 * Founder-guided manual launch proof and first results.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/constants.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Modules\Marketing;
use CRM\Security;
use CRM\Services\MarketingLaunchProofService;
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
$proofService = new MarketingLaunchProofService($marketing);
$canWriteMarketing = Authorization::can('marketing.write', $user);
$error = '';
$notice = '';
$postId = (int) ($_GET['id'] ?? $_POST['post_id'] ?? 0);
$userId = (int) ($user['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!$canWriteMarketing) {
            throw new RuntimeException('You do not have permission to record manual launch proof.');
        }
        if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Invalid CSRF token.');
        }
        if ((string) ($_POST['action'] ?? '') !== 'record_manual_publish_proof') {
            throw new RuntimeException('Unknown launch proof action.');
        }

        $result = $proofService->recordManualPublishProof($postId, $_POST, $userId);
        header('Location: ' . getBasePath() . '/' . (string) ($result['redirect_url'] ?? 'marketing_launch_proof.php'));
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$proof = $proofService->build($postId);
$packet = (array) ($proof['packet'] ?? []);
$post = is_array($proof['distribution_post'] ?? null) ? (array) $proof['distribution_post'] : null;
$form = is_array($proof['form'] ?? null) ? (array) $proof['form'] : null;
$results = (array) ($proof['first_results'] ?? []);
$guardrails = (array) ($proof['guardrails'] ?? []);
$proofStatus = (string) ($proof['proof_status'] ?? 'no_packet');
$packetUrl = (string) ($packet['packet_url'] ?? '');
$labelize = static fn(string $value): string => ucwords(str_replace('_', ' ', $value));
$h = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$money = static fn(float $value): string => number_format($value, 2);

if (($_GET['success'] ?? '') === 'proof') {
    $notice = 'Manual publish proof recorded. First results are ready to review.';
}

$pageTitle = 'Manual Launch Proof - ' . brandProductName();
ob_start();
?>
<link rel="stylesheet" href="assets/css/premium-pages.css">

<div class="page-premium marketing-launch-proof-page">
    <div class="container">
        <div class="page-header marketing-page-header">
            <div>
                <h1>Manual Launch Proof</h1>
                <p>Record what was published manually, then review the first signals.</p>
            </div>
            <div class="page-header-actions marketing-page-actions">
                <a class="btn-premium-secondary" href="marketing.php"><i class="fas fa-arrow-left"></i> Campaign Home</a>
                <?php if ($packetUrl !== ''): ?><a class="btn-premium-secondary" href="<?php echo $h($packetUrl); ?>"><i class="fas fa-box-open"></i> Packet</a><?php endif; ?>
                <a class="btn-premium-primary" href="marketing_performance.php"><i class="fas fa-chart-line"></i> Results</a>
            </div>
        </div>

        <?php if ($error !== ''): ?><div class="alert alert-danger"><?php echo $h($error); ?></div><?php endif; ?>
        <?php if ($notice !== ''): ?><div class="alert alert-success"><?php echo $h($notice); ?></div><?php endif; ?>

        <section class="marketing-launch-proof-shell" aria-label="Manual launch proof">
            <div class="marketing-founder-summary marketing-launch-proof-summary">
                <div class="marketing-summary-tile primary" tabindex="0" data-tooltip="Proof status is derived from the distribution post published URL and timestamp.">
                    <i class="fas fa-square-check" aria-hidden="true"></i>
                    <span>Proof</span>
                    <strong><?php echo $h($labelize($proofStatus)); ?></strong>
                </div>
                <div class="marketing-summary-tile" tabindex="0" data-tooltip="The channel prepared in the manual launch packet.">
                    <i class="fas fa-broadcast-tower" aria-hidden="true"></i>
                    <span>Channel</span>
                    <strong><?php echo $h($labelize((string) ($post['channel'] ?? 'none'))); ?></strong>
                </div>
                <div class="marketing-summary-tile" tabindex="0" data-tooltip="Tracked conversions from existing Marketing performance data.">
                    <i class="fas fa-bullseye" aria-hidden="true"></i>
                    <span>Conversions</span>
                    <strong><?php echo (int) ($results['conversions'] ?? 0); ?></strong>
                </div>
                <div class="marketing-summary-tile" tabindex="0" data-tooltip="Revenue currently connected to marketing attribution evidence.">
                    <i class="fas fa-dollar-sign" aria-hidden="true"></i>
                    <span>Revenue</span>
                    <strong>$<?php echo $money((float) ($results['attributed_revenue'] ?? 0)); ?></strong>
                </div>
            </div>

            <div class="marketing-launch-proof-layout">
                <main class="content-card marketing-launch-proof-card">
                    <?php if ($post === null): ?>
                        <div class="premium-section-header">
                            <div>
                                <h2>Complete The Packet First</h2>
                                <p>No manual launch packet is available yet. Build the packet before recording proof.</p>
                            </div>
                            <span class="badge badge-warning">No packet</span>
                        </div>
                        <a class="btn-premium-primary" href="marketing_launch_packet.php"><i class="fas fa-arrow-right"></i> Complete packet</a>
                    <?php elseif ($proofStatus === 'published'): ?>
                        <div class="premium-section-header">
                            <div>
                                <h2>Proof Recorded</h2>
                                <p>The manual launch proof is saved. Use Results to review what happened and choose the next test.</p>
                            </div>
                            <span class="badge badge-success">Published evidence</span>
                        </div>
                        <div class="marketing-launch-proof-ready">
                            <?php if (!empty($post['published_url'])): ?><a class="btn-premium-secondary" href="<?php echo $h($post['published_url']); ?>" target="_blank" rel="noopener"><i class="fas fa-up-right-from-square"></i> Published URL</a><?php endif; ?>
                            <a class="btn-premium-primary" href="marketing_performance.php"><i class="fas fa-chart-line"></i> View first results</a>
                        </div>
                    <?php elseif ($canWriteMarketing && $form !== null): ?>
                        <div class="premium-section-header">
                            <div>
                                <h2>Record Manual Publish Proof</h2>
                                <p>Paste the public URL you created outside the CRM. This only records evidence.</p>
                            </div>
                            <span class="badge badge-warning">Manual proof needed</span>
                        </div>
                        <form method="POST" class="marketing-launch-proof-form">
                            <input type="hidden" name="csrf_token" value="<?php echo $h(Security::getCsrfToken()); ?>">
                            <input type="hidden" name="action" value="record_manual_publish_proof">
                            <input type="hidden" name="post_id" value="<?php echo (int) ($form['post_id'] ?? 0); ?>">
                            <div class="marketing-launch-proof-fields">
                                <?php foreach ((array) ($form['fields'] ?? []) as $field): ?>
                                    <?php
                                        $fieldName = (string) ($field['name'] ?? '');
                                        $fieldType = (string) ($field['type'] ?? 'text');
                                    ?>
                                    <div class="form-group">
                                        <label for="proof-<?php echo $h($fieldName); ?>"><?php echo $h((string) ($field['label'] ?? $labelize($fieldName))); ?></label>
                                        <input id="proof-<?php echo $h($fieldName); ?>" type="<?php echo $h($fieldType); ?>" name="<?php echo $h($fieldName); ?>" value="<?php echo $h((string) ($field['value'] ?? '')); ?>" <?php echo !empty($field['required']) ? 'required' : ''; ?>>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="marketing-launch-proof-actions">
                                <button class="btn-premium-primary" type="submit"><i class="fas fa-check"></i> <?php echo $h((string) ($form['submit_label'] ?? 'Record proof')); ?></button>
                                <?php if ($packetUrl !== ''): ?><a class="btn-premium-secondary" href="<?php echo $h($packetUrl); ?>"><i class="fas fa-box-open"></i> Review packet</a><?php endif; ?>
                            </div>
                        </form>
                    <?php else: ?>
                        <div class="marketing-launch-proof-readonly">
                            <strong>Read-only view</strong>
                            <p>You can review packet proof status, but a user with Marketing write access must record the published URL.</p>
                            <?php if ($packetUrl !== ''): ?><a class="btn-premium-secondary" href="<?php echo $h($packetUrl); ?>"><i class="fas fa-box-open"></i> Review packet</a><?php endif; ?>
                        </div>
                    <?php endif; ?>
                </main>

                <aside class="marketing-launch-proof-side">
                    <section class="content-card">
                        <div class="premium-section-header">
                            <div>
                                <h2>First Results</h2>
                                <p>Signals from existing Marketing performance data.</p>
                            </div>
                        </div>
                        <div class="marketing-launch-proof-results">
                            <div><strong><?php echo (int) ($results['loop_score'] ?? 0); ?>%</strong><span>Loop score</span></div>
                            <div><strong><?php echo (int) ($results['page_views'] ?? 0); ?></strong><span>Page views</span></div>
                            <div><strong><?php echo (int) ($results['cta_clicks'] ?? 0); ?></strong><span>CTA clicks</span></div>
                            <div><strong><?php echo (int) ($results['touchpoints'] ?? 0); ?></strong><span>Touchpoints</span></div>
                            <div><strong><?php echo (int) ($results['open_handoffs'] ?? 0); ?></strong><span>Open handoffs</span></div>
                            <div><strong><?php echo ($results['roi_percent'] ?? null) === null ? 'N/A' : $h((string) $results['roi_percent']) . '%'; ?></strong><span>ROI</span></div>
                        </div>
                    </section>

                    <section class="content-card marketing-launch-proof-guardrails">
                        <div class="premium-section-header">
                            <div>
                                <h2>Manual Guardrails</h2>
                                <p>No live send, publish, or channel API execution.</p>
                            </div>
                        </div>
                        <div class="marketing-launch-proof-list">
                            <?php foreach ($guardrails as $key => $value): ?>
                                <div><strong><?php echo $h($labelize((string) $key)); ?></strong><span><?php echo $h(is_bool($value) ? ($value ? 'Yes' : 'No') : (string) $value); ?></span></div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                </aside>
            </div>
        </section>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
