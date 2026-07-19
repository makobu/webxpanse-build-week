<?php
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
if (!Auth::check()) { header('Location: ' . getBasePath() . '/login.php'); exit; }
$user = Auth::user();
require_once __DIR__ . '/_marketing_marketplace_gate.php';
crm_require_marketing_marketplace_access($user);
if (!Authorization::can('marketing.read', $user)) { header('Location: ' . getBasePath() . '/dashboard.php'); exit; }

$marketing = new Marketing();
$canWriteMarketing = Authorization::can('marketing.write', $user);
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!$canWriteMarketing) { throw new RuntimeException('You do not have permission to save personas.'); }
        if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) { throw new RuntimeException('Invalid CSRF token.'); }
        $marketing->createPersona($_POST + ['created_by' => (int) ($user['id'] ?? 0)]);
        header('Location: ' . getBasePath() . '/marketing_personas.php?success=1'); exit;
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
$personas = $marketing->listPersonas();
$personaCount = count($personas);
$hasPersonas = $personaCount > 0;
$signalFields = [
    'segment' => ['label' => 'Segment', 'icon' => 'fa-users-viewfinder', 'tooltip' => 'The group this customer belongs to.'],
    'pains' => ['label' => 'Pains', 'icon' => 'fa-triangle-exclamation', 'tooltip' => 'The problem marketing should speak to.'],
    'goals' => ['label' => 'Goals', 'icon' => 'fa-bullseye', 'tooltip' => 'The outcome the customer wants.'],
    'objections' => ['label' => 'Objections', 'icon' => 'fa-shield-halved', 'tooltip' => 'The hesitation content needs to answer.'],
    'preferred_channels' => ['label' => 'Channels', 'icon' => 'fa-share-nodes', 'tooltip' => 'Where this customer is easiest to reach.'],
];
$signalCounts = [];
foreach ($signalFields as $field => $_definition) {
    $signalCounts[$field] = 0;
    foreach ($personas as $persona) {
        $value = $persona[$field] ?? '';
        if (is_array($value)) {
            $value = implode(', ', array_filter(array_map('strval', $value)));
        }
        if (trim((string) $value) !== '') {
            $signalCounts[$field]++;
        }
    }
}
$completeSignals = array_sum(array_map(static fn(int $count): int => $count > 0 ? 1 : 0, $signalCounts));
$personaReadiness = (int) round(($hasPersonas ? 35 : 0) + (($completeSignals / max(1, count($signalFields))) * 65));
$primaryActionUrl = $hasPersonas ? 'marketing_segments.php' : 'marketing_personas.php#add-persona';
$primaryActionLabel = $hasPersonas ? 'Use in segment' : 'Add persona';
$pageTitle = 'Marketing Personas - ' . brandProductName();
ob_start();
?>
<link rel="stylesheet" href="assets/css/premium-pages.css">
<div class="page-premium marketing-personas-page"><div class="container">
    <div class="page-header marketing-page-header"><div><h1>Customer Personas</h1><p>Describe who marketing is trying to persuade.</p></div><div class="page-header-actions marketing-page-actions"><a class="btn-premium-secondary" href="marketing_context.php"><i class="fas fa-layer-group"></i> Context</a><a class="btn-premium-primary" href="<?php echo htmlspecialchars($primaryActionUrl); ?>"><i class="fas fa-arrow-right"></i> <?php echo htmlspecialchars($primaryActionLabel); ?></a></div></div>
    <?php if ($error !== ''): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if (($_GET['success'] ?? '') === '1'): ?><div class="alert alert-success">Persona saved.</div><?php endif; ?>
    <section class="marketing-personas-shell" aria-label="Marketing personas">
        <div class="marketing-founder-summary marketing-personas-summary">
            <a class="marketing-summary-tile primary" href="<?php echo htmlspecialchars($primaryActionUrl); ?>" data-tooltip="The next customer-definition action.">
                <i class="fas fa-arrow-right"></i><span>Next</span><strong><?php echo htmlspecialchars($primaryActionLabel); ?></strong>
            </a>
            <div class="marketing-summary-tile" data-tooltip="Persona readiness is based on having at least one persona and customer signals.">
                <i class="fas fa-gauge-high"></i><span>Readiness</span><strong><?php echo $personaReadiness; ?>%</strong>
            </div>
            <div class="marketing-summary-tile" data-tooltip="Saved customer profiles available for marketing work.">
                <i class="fas fa-user-tag"></i><span>Personas</span><strong><?php echo $personaCount; ?></strong>
            </div>
            <div class="marketing-summary-tile" data-tooltip="How many customer signal types have at least one saved value.">
                <i class="fas fa-layer-group"></i><span>Signals</span><strong><?php echo $completeSignals; ?>/<?php echo count($signalFields); ?></strong>
            </div>
        </div>

        <div class="marketing-persona-signal-grid">
            <?php foreach ($signalFields as $field => $definition): ?>
                <?php
                    $fieldCount = (int) ($signalCounts[$field] ?? 0);
                    $fieldClass = $fieldCount > 0 ? 'ready' : 'setup-needed';
                ?>
                <a class="marketing-persona-signal-card <?php echo $fieldClass; ?>" href="marketing_personas.php#add-persona" data-tooltip="<?php echo htmlspecialchars((string) $definition['tooltip']); ?>" aria-label="<?php echo htmlspecialchars($fieldCount > 0 ? 'Review ' . (string) $definition['label'] . ' signal' : 'Add ' . (string) $definition['label'] . ' signal'); ?>">
                    <div class="marketing-stage-visual marketing-persona-signal-visual">
                        <i class="fas <?php echo htmlspecialchars((string) $definition['icon']); ?>"></i>
                        <span class="marketing-persona-signal-count"><?php echo $fieldCount; ?></span>
                    </div>
                    <div class="marketing-stage-title-row">
                        <h2><?php echo htmlspecialchars((string) $definition['label']); ?></h2>
                        <span class="marketing-stage-badge <?php echo $fieldClass; ?>"><?php echo $fieldCount > 0 ? 'Ready' : 'Setup needed'; ?></span>
                    </div>
                    <span class="marketing-persona-signal-action"><?php echo $fieldCount > 0 ? 'Review' : 'Add'; ?></span>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="marketing-founder-layout marketing-personas-layout">
            <div class="content-card marketing-personas-list" id="persona-library"><div class="premium-section-header"><div><h2>Persona Library</h2><p><?php echo $personaCount; ?> saved</p></div></div>
                <?php if (empty($personas)): ?><div class="empty-state"><p>No personas yet.</p></div><?php else: ?><div class="marketing-persona-card-list"><?php foreach ($personas as $persona): ?>
                    <article class="marketing-persona-card marketing-persona-profile-card"><strong><?php echo htmlspecialchars((string) $persona['name']); ?></strong><div class="marketing-meta"><span><?php echo htmlspecialchars((string) ($persona['segment'] ?? 'No segment')); ?></span><span><?php echo htmlspecialchars((string) ($persona['preferred_channels'] ?? 'No channel set')); ?></span></div><div class="marketing-persona-body"><?php echo htmlspecialchars((string) ($persona['pains'] ?? '')); ?></div></article>
                <?php endforeach; ?></div><?php endif; ?>
            </div>
            <aside class="content-card marketing-personas-form" id="add-persona"><div class="premium-section-header"><div><h2>Add Persona</h2><p>One customer profile at a time.</p></div></div>
                <?php if ($canWriteMarketing): ?><form method="POST"><input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                    <div class="form-group"><label>Name</label><input type="text" name="name" required></div>
                    <div class="form-group"><label>Segment</label><input type="text" name="segment"></div>
                    <div class="form-group"><label>Pains</label><textarea name="pains" rows="3"></textarea></div>
                    <div class="form-group"><label>Goals</label><textarea name="goals" rows="3"></textarea></div>
                    <div class="form-group"><label>Objections</label><textarea name="objections" rows="3"></textarea></div>
                    <div class="form-group"><label>Preferred Channels</label><input type="text" name="preferred_channels" placeholder="email, linkedin, whatsapp"></div>
                    <button class="btn-premium-primary" type="submit">Save Persona</button>
                </form><?php else: ?><div class="empty-state"><p>Read-only access.</p></div><?php endif; ?>
            </aside>
        </div>

        <details class="marketing-advanced-tools marketing-personas-tools">
            <summary>More persona tools <i class="fas fa-chevron-down"></i></summary>
            <div class="marketing-advanced-tools-grid">
                <a href="marketing_context.php" data-tooltip="Store offers, proof, and claims for this audience."><strong>Context Library</strong><span>Facts and proof</span></a>
                <a href="marketing_segments.php" data-tooltip="Turn personas into usable campaign audiences."><strong>Audience Segments</strong><span>Targeting rules</span></a>
                <a href="marketing_persona_offer_matrix.php" data-tooltip="Match each customer profile to the strongest offer."><strong>Persona Offer Matrix</strong><span>Offer fit</span></a>
            </div>
        </details>
    </section>
</div></div>
<?php $content = ob_get_clean(); include __DIR__ . '/../views/layouts/base.php';
