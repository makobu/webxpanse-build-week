<?php

require_once __DIR__ . '/../vendor/autoload.php';
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) continue;
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}
require_once __DIR__ . '/../config/constants.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Security;
use CRM\Session;
use CRM\Services\WorkspaceContext;
use CRM\Services\WorkspaceSkillCatalogService;
use CRM\Services\WorkspaceSkillInstallService;
use CRM\Services\WorkspaceVoiceConfigService;
use CRM\Services\WorkspaceVoiceEntitlementService;
use CRM\Services\VoiceAgentService;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();
if (!Auth::check()) {
    header('Location: login.php'); exit;
}
$user = Auth::user();
$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
$installer = new WorkspaceSkillInstallService();
$canUse = Authorization::isSuperAdmin($user) || Authorization::can('voice.calls.use', $user);
$voiceSkillKey = WorkspaceSkillCatalogService::PLUGIN_VOICE_CALL_CENTER;
$catalog = new WorkspaceSkillCatalogService();
$entitlements = (new WorkspaceVoiceEntitlementService())->forWorkspace($workspaceId);
// The runtime owns a deliberate not-ready state with disabled call controls.
// Keep it accessible after installation so setup links and controlled tests do
// not bounce users back to the Marketplace without an explanation.
if (!$canUse
    || !$installer->isInstalled($workspaceId, $voiceSkillKey)
    || $catalog->isGloballyDeactivated($voiceSkillKey)
    || empty($entitlements['enabled'])) {
    header('Location: workspace_skills.php?module=voice_call_center'); exit;
}
$agent = (new VoiceAgentService())->findForUser($workspaceId, (int) $user['id'], false);
$readiness = (new WorkspaceVoiceConfigService())->readiness($workspaceId);
$readinessBlockers = array_values(array_filter(array_map('strval', (array) ($readiness['blockers'] ?? []))));
$readinessBlockerSummary = implode(', ', array_slice($readinessBlockers, 0, 3));
if (count($readinessBlockers) > 3) {
    $readinessBlockerSummary .= ' and ' . (count($readinessBlockers) - 3) . ' more. Review setup for the full checklist.';
}
$canViewAllContacts = Authorization::isSuperAdmin($user) || Authorization::can('contacts.view_all', $user);
$contactScope = $canViewAllContacts ? '' : ' AND (assigned_to IS NULL OR assigned_to = ? OR created_by = ?)';
$contactParams = [$workspaceId];
if (!$canViewAllContacts) {
    $contactParams[] = (int) $user['id'];
    $contactParams[] = (int) $user['id'];
}
$contacts = Database::query(
    "SELECT id, first_name, last_name, company, email, phone FROM contacts WHERE workspace_id = ?{$contactScope} AND phone IS NOT NULL AND phone <> '' ORDER BY updated_at DESC LIMIT 150",
    $contactParams
);
$prefillContactId = max(0, (int) ($_GET['contact_id'] ?? 0));
$prefillContact = null;
foreach ($contacts as $contactOption) {
    if ((int) $contactOption['id'] === $prefillContactId) {
        $prefillContact = $contactOption;
        break;
    }
}
$csrfToken = Security::getCsrfToken();
$canViewTranscript = Authorization::isSuperAdmin($user) || Authorization::can('voice.transcripts.view', $user);
$canListenRecording = Authorization::isSuperAdmin($user) || Authorization::can('voice.recordings.listen', $user);
$canReviewInsight = Authorization::isSuperAdmin($user) || Authorization::can('voice.insights.review', $user);
$canDeleteEvidence = Authorization::isSuperAdmin($user) || Authorization::can('voice.settings.manage', $user);
$canReviewCustomerVoice = Authorization::isSuperAdmin($user) || Authorization::can('voice.customer_voice.review', $user);
$pageTitle = 'Call Center - ' . brandProductName();
ob_start();
?>
<link rel="stylesheet" href="assets/css/premium-pages.css">
<link rel="stylesheet" href="assets/css/voice-call-center.css?v=<?= (int) @filemtime(__DIR__ . '/assets/css/voice-call-center.css') ?>">

<main class="page-premium vcc-page" data-vcc-root data-csrf-token="<?= htmlspecialchars($csrfToken) ?>"
      data-can-view-transcript="<?= $canViewTranscript ? '1' : '0' ?>"
      data-can-listen-recording="<?= $canListenRecording ? '1' : '0' ?>"
      data-can-review-insight="<?= $canReviewInsight ? '1' : '0' ?>"
      data-can-delete-evidence="<?= $canDeleteEvidence ? '1' : '0' ?>">
    <div class="container">
        <header class="page-header vcc-header">
            <div>
                <div class="vcc-eyebrow"><i class="fas fa-headset"></i> AI-first voice operations</div>
                <h1>Call Center</h1>
                <p>Place and receive calls through configured phone or SIP endpoints. Audio stays with the provider; post-call context returns to the CRM.</p>
            </div>
            <div class="page-header-actions vcc-header-actions">
                <label class="vcc-presence-label" for="vcc-presence">Availability</label>
                <select id="vcc-presence" class="vcc-presence" <?= $agent ? '' : 'disabled' ?>>
                    <?php foreach (['available' => 'Available', 'away' => 'Away', 'offline' => 'Offline'] as $value => $label): ?>
                        <option value="<?= $value ?>" <?= (($agent['presence_status'] ?? 'offline') === $value) ? 'selected' : '' ?>><?= $label ?></option>
                    <?php endforeach; ?>
                </select>
                <a class="btn-premium-secondary" href="workspace_skills.php?module=voice_call_center&amp;full_setup=1&amp;setup_tab=overview#setup"><i class="fas fa-sliders-h"></i> Setup</a>
                <?php if ($canReviewCustomerVoice): ?><a class="btn-premium-secondary" href="customer_voice.php"><i class="fas fa-comments"></i> Customer Voice</a><?php endif; ?>
            </div>
        </header>

        <?php if (empty($readiness['ready'])): ?>
            <section class="vcc-alert" role="status">
                <i class="fas fa-shield-alt"></i>
                <div><strong>Voice is not ready yet.</strong><span><?= htmlspecialchars($readinessBlockerSummary) ?></span></div>
                <a href="workspace_skills.php?module=voice_call_center&amp;full_setup=1&amp;setup_tab=overview#setup">Complete setup</a>
            </section>
        <?php endif; ?>

        <?php if (!$agent): ?>
            <section class="vcc-alert vcc-alert-info" role="status">
                <i class="fas fa-headset"></i>
                <div><strong>Your user is not configured as a voice agent.</strong><span>Add a verified phone or SIP endpoint before placing or receiving calls.</span></div>
                <a href="workspace_skills.php?module=voice_call_center&amp;full_setup=1&amp;setup_tab=team#setup">Add agent</a>
            </section>
        <?php endif; ?>

        <section class="vcc-metrics" aria-label="Voice metrics">
            <article><span>Active calls</span><strong id="vcc-active-count">0</strong><small>Live workspace sessions</small></article>
            <article><span>Concurrency</span><strong><span id="vcc-concurrency-used">0</span>/<span id="vcc-concurrency-limit">0</span></strong><small>Package ceiling</small></article>
            <article><span>Agent endpoint</span><strong class="vcc-metric-text"><?= htmlspecialchars((string) ($agent['endpoint_masked'] ?? 'Not configured')) ?></strong><small><?= htmlspecialchars(ucfirst((string) ($agent['endpoint_type'] ?? ''))) ?></small></article>
            <article><span>AI worker</span><strong class="vcc-metric-text" id="vcc-worker-status">Checking</strong><small>Post-call processing</small></article>
        </section>

        <section class="vcc-queue-strip" id="vcc-queue-strip" aria-label="Queue status">
            <article class="vcc-queue-loading"><span>Queue health</span><strong>Checking routing pressure...</strong></article>
        </section>

        <div class="vcc-grid">
            <section class="vcc-panel vcc-dial-panel">
                <div class="vcc-panel-heading"><div><span class="vcc-kicker">New outbound call</span><h2>Dial a customer</h2></div><i class="fas fa-phone-alt"></i></div>
                <form id="vcc-dial-form" autocomplete="off">
                    <label for="vcc-contact-search">Contact or telephone number</label>
                    <input id="vcc-contact-search" name="destination" list="vcc-contact-options" inputmode="tel" placeholder="e.g. +254 700 000 000" value="<?= htmlspecialchars((string) ($prefillContact['phone'] ?? '')) ?>" required>
                    <datalist id="vcc-contact-options">
                        <?php foreach ($contacts as $contact): ?>
                            <option value="<?= htmlspecialchars((string) $contact['phone']) ?>" data-contact-id="<?= (int) $contact['id'] ?>"><?= htmlspecialchars(trim($contact['first_name'] . ' ' . $contact['last_name']) . ($contact['company'] ? ' · ' . $contact['company'] : '')) ?></option>
                        <?php endforeach; ?>
                    </datalist>
                    <input type="hidden" id="vcc-contact-id" name="contact_id" value="<?= (int) ($prefillContact['id'] ?? 0) ?>">
                    <div class="vcc-policy-note"><i class="fas fa-user-shield"></i><span>Your verified endpoint rings first. Kenya destinations are allowed by default; premium numbers are blocked.</span></div>
                    <button class="btn-premium-primary vcc-call-button" type="submit" <?= empty($readiness['ready']) || !$agent ? 'disabled' : '' ?>><i class="fas fa-phone"></i> Queue call</button>
                </form>
                <div id="vcc-action-message" class="vcc-action-message" hidden></div>
            </section>

            <section class="vcc-panel vcc-live-panel">
                <div class="vcc-panel-heading"><div><span class="vcc-kicker">Live operations</span><h2>Current assignments</h2></div><span class="vcc-live-dot is-connecting" id="vcc-poll-status" role="status" aria-live="polite">Connecting</span></div>
                <div id="vcc-live-calls" class="vcc-empty-state"><i class="fas fa-wave-square"></i><p>No active calls.</p></div>
            </section>
        </div>

        <section class="vcc-panel vcc-history-panel">
            <div class="vcc-panel-heading">
                <div><span class="vcc-kicker">Workspace history</span><h2>Recent calls</h2></div>
                <div class="vcc-history-filter-group"><span id="vcc-history-count" class="vcc-history-count" aria-live="polite">Loading</span><div class="vcc-history-filters" aria-label="Filter recent calls"><button type="button" class="is-active" data-vcc-filter="all" aria-pressed="true">All</button><button type="button" data-vcc-filter="missed" aria-pressed="false">Missed</button><button type="button" data-vcc-filter="unmatched" aria-pressed="false">Unmatched</button></div></div>
            </div>
            <p class="vcc-table-hint">Scroll horizontally to review all call details.</p>
            <div class="vcc-table-wrap">
                <table class="vcc-table">
                    <thead><tr><th>Call</th><th>State</th><th>Agent</th><th>AI</th><th>Context</th><th>Duration</th><th>Time</th></tr></thead>
                    <tbody id="vcc-call-rows"><tr><td colspan="7" class="vcc-loading">Loading call history…</td></tr></tbody>
                </table>
            </div>
        </section>
    </div>

    <dialog id="vcc-insight-dialog" class="vcc-dialog" aria-labelledby="vcc-dialog-title">
        <form method="dialog"><button class="vcc-dialog-close" value="cancel" aria-label="Close">×</button></form>
        <span class="vcc-kicker">Evidence-backed call context</span>
        <h2 id="vcc-dialog-title">Call intelligence</h2>
        <div id="vcc-dialog-content" class="vcc-dialog-content"></div>
        <div id="vcc-dialog-actions" class="vcc-dialog-actions"></div>
    </dialog>

    <dialog id="vcc-contact-dialog" class="vcc-dialog vcc-contact-dialog" aria-labelledby="vcc-contact-dialog-title">
        <form method="dialog"><button class="vcc-dialog-close" value="cancel" aria-label="Close">×</button></form>
        <span class="vcc-kicker">Deliberate customer matching</span>
        <h2 id="vcc-contact-dialog-title">Link this call to a contact</h2>
        <p class="vcc-dialog-intro">Unknown callers stay unmatched until you explicitly select or create a contact.</p>
        <section class="vcc-contact-link-section">
            <label for="vcc-link-contact-id">Existing contact</label>
            <div class="vcc-contact-link-row">
                <select id="vcc-link-contact-id">
                    <option value="">Choose a contact…</option>
                    <?php foreach ($contacts as $contact): ?>
                        <option value="<?= (int) $contact['id'] ?>"><?= htmlspecialchars(trim((string) $contact['first_name'] . ' ' . (string) $contact['last_name']) . ((string) $contact['company'] !== '' ? ' · ' . (string) $contact['company'] : '')) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" class="btn-premium-secondary" id="vcc-link-contact-button">Link contact</button>
            </div>
        </section>
        <div class="vcc-contact-divider"><span>or create a contact</span></div>
        <form id="vcc-create-contact-form" class="vcc-contact-create-form">
            <label>First name<input name="first_name" maxlength="100" required></label>
            <label>Last name<input name="last_name" maxlength="100"></label>
            <label class="vcc-wide">Email address<input name="email" type="email" maxlength="255" required></label>
            <div class="vcc-wide vcc-dialog-actions"><button type="submit" class="btn-premium-primary">Create and link</button></div>
        </form>
    </dialog>
</main>
<script src="assets/js/voice-call-center.js?v=<?= (int) @filemtime(__DIR__ . '/assets/js/voice-call-center.js') ?>" defer></script>
<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
