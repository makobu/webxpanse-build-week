<?php

require_once __DIR__ . '/_public_bootstrap.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Security;
use CRM\Session;
use CRM\Services\CustomerVoiceAggregationService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceSkillCatalogService;
use CRM\Services\WorkspaceSkillInstallService;
use CRM\Services\WorkspaceVoiceConfigService;
use CRM\Services\WorkspaceVoiceEntitlementService;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();
if (!Auth::check()) {
    header('Location: login.php');
    exit;
}
$user = Auth::user();
if (!Authorization::isSuperAdmin($user) && !Authorization::can('voice.customer_voice.review', $user)) {
    http_response_code(403);
    echo 'Access denied: Customer Voice review permission is required.';
    exit;
}
$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
$installer = new WorkspaceSkillInstallService();
if (!$installer->canExposeRuntimeModule($workspaceId, WorkspaceSkillCatalogService::PLUGIN_VOICE_CALL_CENTER)) {
    header('Location: workspace_skills.php?module=voice_call_center');
    exit;
}
$service = new CustomerVoiceAggregationService();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!Security::validateCSRF((string) ($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Invalid security token. Refresh and try again.');
        }
        $service->review($workspaceId, max(1, (int) ($_POST['insight_id'] ?? 0)), (int) ($user['id'] ?? 0), (string) ($_POST['status'] ?? ''));
        header('Location: customer_voice.php?reviewed=1');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
$config = (new WorkspaceVoiceConfigService())->get($workspaceId, false);
$entitlements = (new WorkspaceVoiceEntitlementService())->forWorkspace($workspaceId);
$enabled = !empty($config['customer_voice_enabled']) && !empty($entitlements['customer_voice']);
$insights = $service->reviewable($workspaceId, 200);
$pending = count(array_filter($insights, static fn(array $item): bool => (string) ($item['review_status'] ?? '') === 'pending'));
$accepted = count(array_filter($insights, static fn(array $item): bool => (string) ($item['review_status'] ?? '') === 'accepted'));
$pageTitle = 'Customer Voice - ' . brandProductName();
ob_start();
?>
<link rel="stylesheet" href="assets/css/premium-pages.css">
<link rel="stylesheet" href="assets/css/voice-call-center.css?v=<?= (int) @filemtime(__DIR__ . '/assets/css/voice-call-center.css') ?>">
<main class="page-premium vcc-page vcc-customer-voice-page"><div class="container">
    <header class="page-header vcc-header"><div><div class="vcc-eyebrow"><i class="fas fa-comments"></i> Anonymized multi-customer evidence</div><h1>Customer Voice</h1><p>Review recurring themes only after at least three distinct contacts support them. Raw transcripts and direct identifiers are never shown here.</p></div><div class="page-header-actions"><a class="btn-premium-secondary" href="marketing_context.php"><i class="fas fa-layer-group"></i> Marketing Context</a><?php if (Authorization::isSuperAdmin($user) || Authorization::can('voice.calls.use', $user)): ?><a class="btn-premium-secondary" href="call_center.php"><i class="fas fa-headset"></i> Call Center</a><?php endif; ?></div></header>
    <?php if ($error !== ''): ?><div class="vcc-alert"><i class="fas fa-triangle-exclamation"></i><div><strong>Review could not be saved.</strong><span><?php echo htmlspecialchars($error); ?></span></div></div><?php endif; ?>
    <?php if (($_GET['reviewed'] ?? '') === '1'): ?><div class="vcc-health-ok"><i class="fas fa-check-circle"></i> Review saved. Accepted themes become draft marketing context and still require normal marketing review.</div><?php endif; ?>
    <?php if (!$enabled): ?><div class="vcc-alert"><i class="fas fa-lock"></i><div><strong>Customer Voice is not active.</strong><span>The workspace switch and package entitlement must both be enabled before new themes are aggregated.</span></div></div><?php endif; ?>
    <section class="vcc-metrics" aria-label="Customer Voice metrics"><article><span>Reviewable themes</span><strong><?php echo count($insights); ?></strong><small>Three or more contacts</small></article><article><span>Pending review</span><strong><?php echo $pending; ?></strong><small>Human decision required</small></article><article><span>Accepted drafts</span><strong><?php echo $accepted; ?></strong><small>No automatic publishing</small></article><article><span>Privacy floor</span><strong>3</strong><small>Distinct contacts per theme</small></article></section>
    <section class="vcc-panel"><div class="vcc-panel-heading"><div><span class="vcc-kicker">Evidence review</span><h2>Recurring customer themes</h2></div></div>
        <?php if ($insights === []): ?><div class="vcc-empty-state"><i class="fas fa-shield-heart"></i><p>No anonymized theme has reached the three-contact evidence floor yet.</p></div><?php else: ?><div class="vcc-customer-voice-grid">
            <?php foreach ($insights as $insight): $status = (string) ($insight['review_status'] ?? 'pending'); ?>
                <article class="vcc-customer-voice-card">
                    <div><span class="vcc-status-pill" data-state="<?php echo htmlspecialchars($status); ?>"><?php echo htmlspecialchars(ucfirst($status)); ?></span><span class="vcc-kicker"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) $insight['category']))); ?></span></div>
                    <h3><?php echo htmlspecialchars((string) $insight['topic_label']); ?></h3>
                    <p><?php echo htmlspecialchars((string) ($insight['summary'] ?: $insight['topic_label'])); ?></p>
                    <dl><div><dt>Evidence</dt><dd><?php echo (int) $insight['evidence_count']; ?> calls</dd></div><div><dt>Distinct contacts</dt><dd><?php echo (int) $insight['distinct_contact_count']; ?></dd></div><div><dt>Confidence</dt><dd><?php echo number_format((float) $insight['confidence'] * 100, 0); ?>%</dd></div></dl>
                    <?php if ($status === 'pending'): ?><form method="POST" class="vcc-customer-voice-actions"><input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars(Security::getCsrfToken()); ?>"><input type="hidden" name="insight_id" value="<?php echo (int) $insight['id']; ?>"><button class="btn-premium-secondary" name="status" value="dismissed">Dismiss</button><button class="btn-premium-primary" name="status" value="accepted">Accept as draft context</button></form><?php elseif ($status === 'accepted' && !empty($insight['accepted_target_id'])): ?><a class="btn-premium-secondary" href="marketing_context.php">Review draft context #<?php echo (int) $insight['accepted_target_id']; ?></a><?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div><?php endif; ?>
    </section>
</div></main>
<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
