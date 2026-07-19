<?php
/**
 * Founder-guided Marketing launch packet completion.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/constants.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Modules\Marketing;
use CRM\Security;
use CRM\Services\MarketingLaunchPacketCompletionService;
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
$completionService = new MarketingLaunchPacketCompletionService($marketing);
$canWriteMarketing = Authorization::can('marketing.write', $user);
$error = '';
$notice = '';
$userId = (int) ($user['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!$canWriteMarketing) {
            throw new RuntimeException('You do not have permission to complete launch packet steps.');
        }
        if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Invalid CSRF token.');
        }

        $result = $completionService->create((string) ($_POST['action'] ?? ''), $_POST, $userId);
        $target = (string) ($result['redirect_url'] ?? 'marketing_launch_packet.php');
        if (str_starts_with($target, 'marketing_launch_packet.php')) {
            $separator = str_contains($target, '?') ? '&' : '?';
            $target .= $separator . 'success=' . urlencode((string) ($result['created_type'] ?? 'saved'));
        }
        header('Location: ' . getBasePath() . '/' . $target);
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$onboardingStatus = $marketing->getMarketingOnboardingStatus();
$summary = $marketing->getCachedDashboardSummary($onboardingStatus, 60);
$completion = $completionService->build($onboardingStatus, $summary, (string) ($_GET['step'] ?? ''));
$packet = (array) ($completion['packet'] ?? []);
$steps = (array) ($completion['steps'] ?? []);
$form = is_array($completion['form'] ?? null) ? (array) $completion['form'] : null;
$guardrails = (array) ($completion['guardrails'] ?? []);
$missing = (array) ($packet['missing'] ?? []);
$evidence = (array) ($packet['evidence'] ?? []);
$packetUrl = (string) ($packet['packet_url'] ?? '');
$activeStepKey = (string) ($completion['active_step'] ?? '');
$firstMissingStep = (string) ($completion['first_missing_step'] ?? '');
$labelize = static fn(string $value): string => ucwords(str_replace('_', ' ', $value));
$h = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$statusClass = static function (string $status): string {
    return match ($status) {
        'ready', 'published_evidence', 'completed' => 'ready',
        'active', 'building' => 'active',
        'missing', 'not_started' => 'missing',
        default => 'pending',
    };
};
$stepIcon = static function (string $key): string {
    return match ($key) {
        'setup' => 'fa-rocket',
        'audiences' => 'fa-users-viewfinder',
        'campaigns' => 'fa-clipboard-list',
        'content' => 'fa-pen-nib',
        'landing' => 'fa-window-maximize',
        'send' => 'fa-box-open',
        default => 'fa-circle-dot',
    };
};

if (($_GET['success'] ?? '') !== '') {
    $notice = $labelize((string) $_GET['success']) . ' saved. The packet status has been refreshed.';
}

$pageTitle = 'Guided Launch Packet - ' . brandProductName();
ob_start();
?>
<link rel="stylesheet" href="assets/css/premium-pages.css">

<div class="page-premium marketing-launch-packet-page">
    <div class="container">
        <div class="page-header marketing-page-header">
            <div>
                <h1>Guided Launch Packet</h1>
                <p>Complete the next missing piece from one focused flow.</p>
            </div>
            <div class="page-header-actions marketing-page-actions">
                <a class="btn-premium-secondary" href="marketing.php"><i class="fas fa-arrow-left"></i> Campaign Home</a>
                <?php if ($packetUrl !== ''): ?>
                    <a class="btn-premium-primary" href="<?php echo $h($packetUrl); ?>"><i class="fas fa-box-open"></i> Open Packet</a>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($error !== ''): ?><div class="alert alert-danger"><?php echo $h($error); ?></div><?php endif; ?>
        <?php if ($notice !== ''): ?><div class="alert alert-success"><?php echo $h($notice); ?></div><?php endif; ?>

        <section class="marketing-launch-completion-shell" aria-label="Guided packet completion">
            <div class="marketing-founder-summary marketing-launch-completion-summary">
                <div class="marketing-summary-tile primary" tabindex="0" data-tooltip="Readiness is derived from existing setup, audience, campaign, content, landing, and distribution records.">
                    <i class="fas fa-gauge-high" aria-hidden="true"></i>
                    <span>Readiness</span>
                    <strong><?php echo (int) ($packet['score'] ?? 0); ?>%</strong>
                </div>
                <div class="marketing-summary-tile" tabindex="0" data-tooltip="Packet status updates from saved records; this page does not send or publish anything.">
                    <i class="fas fa-flag-checkered" aria-hidden="true"></i>
                    <span>Status</span>
                    <strong><?php echo $h($labelize((string) ($packet['status'] ?? 'not_started'))); ?></strong>
                </div>
                <div class="marketing-summary-tile" tabindex="0" data-tooltip="The first missing primary-path item is selected automatically.">
                    <i class="fas fa-location-dot" aria-hidden="true"></i>
                    <span>Next</span>
                    <strong><?php echo $h($firstMissingStep !== '' ? $labelize($firstMissingStep) : 'Packet ready'); ?></strong>
                </div>
                <div class="marketing-summary-tile" tabindex="0" data-tooltip="This remains a manual launch path with no external execution.">
                    <i class="fas fa-shield-halved" aria-hidden="true"></i>
                    <span>Guardrail</span>
                    <strong>Manual first</strong>
                </div>
            </div>

            <ol class="marketing-launch-completion-steps" aria-label="Packet completion steps">
                <?php foreach ($steps as $step): ?>
                    <?php $stepStatus = $statusClass((string) ($step['status'] ?? 'pending')); ?>
                    <li class="<?php echo $h($stepStatus); ?>">
                        <a href="<?php echo $h((string) ($step['href'] ?? 'marketing_launch_packet.php')); ?>">
                            <i class="fas <?php echo $h($stepIcon((string) ($step['key'] ?? ''))); ?>" aria-hidden="true"></i>
                            <span><?php echo $h((string) ($step['label'] ?? 'Step')); ?></span>
                            <strong><?php echo $h($labelize((string) ($step['status'] ?? 'pending'))); ?></strong>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ol>

            <div class="marketing-launch-completion-layout">
                <main class="content-card marketing-launch-completion-card">
                    <?php if ($missing === [] && $packetUrl !== ''): ?>
                        <div class="premium-section-header">
                            <div>
                                <h2>Manual Launch Packet Ready</h2>
                                <p>Open the package, use it manually outside the CRM, then record proof from Distribution when it is live.</p>
                            </div>
                            <span class="badge badge-success">Ready</span>
                        </div>
                        <div class="marketing-launch-completion-ready">
                            <a class="btn-premium-primary" href="<?php echo $h($packetUrl); ?>"><i class="fas fa-box-open"></i> Open Manual Launch Packet</a>
                            <a class="btn-premium-secondary" href="marketing_distribution.php"><i class="fas fa-square-check"></i> Record Proof</a>
                        </div>
                    <?php elseif ($form !== null): ?>
                        <div class="premium-section-header">
                            <div>
                                <h2><?php echo $h((string) ($form['title'] ?? 'Complete next step')); ?></h2>
                                <p><?php echo $h((string) ($form['description'] ?? 'Save the minimum record needed for this packet.')); ?></p>
                            </div>
                            <span class="badge badge-warning">Next missing step</span>
                        </div>

                        <?php if ($canWriteMarketing): ?>
                            <form method="POST" class="marketing-launch-completion-form">
                                <input type="hidden" name="csrf_token" value="<?php echo $h(Security::getCsrfToken()); ?>">
                                <input type="hidden" name="action" value="<?php echo $h((string) ($form['action'] ?? '')); ?>">

                                <div class="marketing-launch-completion-fields">
                                    <?php foreach ((array) ($form['fields'] ?? []) as $field): ?>
                                        <?php
                                            $fieldName = (string) ($field['name'] ?? '');
                                            $fieldType = (string) ($field['type'] ?? 'text');
                                            $fieldValue = (string) ($field['value'] ?? '');
                                            $fieldRequired = !empty($field['required']);
                                        ?>
                                        <div class="form-group">
                                            <label for="launch-<?php echo $h($fieldName); ?>"><?php echo $h((string) ($field['label'] ?? $labelize($fieldName))); ?></label>
                                            <?php if ($fieldType === 'textarea'): ?>
                                                <textarea id="launch-<?php echo $h($fieldName); ?>" name="<?php echo $h($fieldName); ?>" rows="4" <?php echo $fieldRequired ? 'required' : ''; ?>><?php echo $h($fieldValue); ?></textarea>
                                            <?php elseif ($fieldType === 'select'): ?>
                                                <select id="launch-<?php echo $h($fieldName); ?>" name="<?php echo $h($fieldName); ?>" <?php echo $fieldRequired ? 'required' : ''; ?>>
                                                    <?php foreach ((array) ($field['options'] ?? []) as $option): ?>
                                                        <?php $optionValue = (string) ($option['value'] ?? ''); ?>
                                                        <option value="<?php echo $h($optionValue); ?>" <?php echo $optionValue === $fieldValue ? 'selected' : ''; ?>><?php echo $h((string) ($option['label'] ?? $optionValue)); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            <?php else: ?>
                                                <input id="launch-<?php echo $h($fieldName); ?>" type="text" name="<?php echo $h($fieldName); ?>" value="<?php echo $h($fieldValue); ?>" <?php echo $fieldRequired ? 'required' : ''; ?>>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <div class="marketing-launch-completion-actions">
                                    <button class="btn-premium-primary" type="submit"><i class="fas fa-check"></i> <?php echo $h((string) ($form['submit_label'] ?? 'Save step')); ?></button>
                                    <a class="btn-premium-secondary" href="<?php echo $h((string) ($form['detail_url'] ?? 'marketing.php')); ?>"><i class="fas fa-pen-to-square"></i> Full details</a>
                                </div>
                            </form>
                        <?php else: ?>
                            <div class="marketing-launch-completion-readonly">
                                <strong>Read-only view</strong>
                                <p>You can review what is missing, but a user with Marketing write access must create the next packet record.</p>
                                <a class="btn-premium-secondary" href="<?php echo $h((string) ($form['detail_url'] ?? 'marketing.php')); ?>"><i class="fas fa-up-right-from-square"></i> View full details</a>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="premium-section-header">
                            <div>
                                <h2>Packet Status Updated</h2>
                                <p>No guided form is needed right now.</p>
                            </div>
                        </div>
                    <?php endif; ?>
                </main>

                <aside class="marketing-launch-completion-side">
                    <section class="content-card">
                        <div class="premium-section-header">
                            <div>
                                <h2>Missing</h2>
                                <p>Primary-path gaps in order.</p>
                            </div>
                        </div>
                        <div class="marketing-launch-completion-list">
                            <?php if ($missing === []): ?>
                                <p>No packet blockers remain.</p>
                            <?php else: ?>
                                <?php foreach ($missing as $item): ?>
                                    <a href="marketing_launch_packet.php?step=<?php echo $h((string) ($item['key'] ?? 'setup')); ?>">
                                        <strong><?php echo $h((string) ($item['label'] ?? 'Missing item')); ?></strong>
                                        <span><?php echo $h((string) ($item['detail'] ?? 'Complete this packet item.')); ?></span>
                                    </a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </section>

                    <section class="content-card">
                        <div class="premium-section-header">
                            <div>
                                <h2>Ready Evidence</h2>
                                <p>Records already counted toward the packet.</p>
                            </div>
                        </div>
                        <div class="marketing-launch-completion-list">
                            <?php if ($evidence === []): ?>
                                <p>No ready evidence yet.</p>
                            <?php else: ?>
                                <?php foreach ($evidence as $item): ?>
                                    <a href="<?php echo $h((string) ($item['href'] ?? 'marketing.php')); ?>">
                                        <strong><?php echo $h((string) ($item['label'] ?? 'Evidence')); ?></strong>
                                        <span><?php echo $h((string) ($item['detail'] ?? 'Ready for packet.')); ?></span>
                                    </a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </section>

                    <section class="content-card marketing-launch-completion-guardrails">
                        <div class="premium-section-header">
                            <div>
                                <h2>Manual Guardrails</h2>
                                <p>No live send, publish, or channel API execution.</p>
                            </div>
                        </div>
                        <div class="marketing-launch-completion-list">
                            <?php foreach ($guardrails as $key => $value): ?>
                                <div>
                                    <strong><?php echo $h($labelize((string) $key)); ?></strong>
                                    <span><?php echo $h(is_bool($value) ? ($value ? 'Yes' : 'No') : (string) $value); ?></span>
                                </div>
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
