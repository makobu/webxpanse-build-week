<?php
/**
 * Create/edit marketing campaign playbook.
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
if (!Authorization::can('marketing.write', $user)) {
    header('Location: ' . getBasePath() . '/marketing_playbooks.php');
    exit;
}

$marketing = new Marketing();
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$playbook = $id > 0 ? $marketing->getCampaignPlaybook($id) : null;
if ($id > 0 && !$playbook) {
    http_response_code(404);
    echo 'Marketing campaign playbook not found.';
    exit;
}

$options = $marketing->optionData();
$error = '';
$stageLines = [];
foreach ((array) ($playbook['stages'] ?? []) as $stage) {
    $stageLines[] = implode(' | ', [
        (string) ($stage['stage_type'] ?? 'strategy'),
        (string) ($stage['required_record_type'] ?? 'none'),
        (string) ($stage['title'] ?? ''),
        trim((string) ($stage['instructions'] ?? '')),
    ]);
}
$form = [
    'name' => $playbook['name'] ?? '',
    'status' => $playbook['status'] ?? 'draft',
    'description' => $playbook['description'] ?? '',
    'campaign_goal' => $playbook['campaign_goal'] ?? '',
    'target_audience' => $playbook['target_audience'] ?? '',
    'audience_segment_id' => $playbook['audience_segment_id'] ?? '',
    'persona_id' => $playbook['persona_id'] ?? '',
    'offer_context_item_id' => $playbook['offer_context_item_id'] ?? '',
    'landing_page_id' => $playbook['landing_page_id'] ?? '',
    'success_metrics' => implode("\n", (array) ($playbook['success_metrics_json'] ?? [])),
    'channel_plan' => implode("\n", (array) ($playbook['channel_plan_json'] ?? [])),
    'checklist' => implode("\n", (array) ($playbook['checklist_json'] ?? [])),
    'owner_user_id' => $playbook['owner_user_id'] ?? '',
    'stages' => implode("\n", $stageLines),
];

$parseStageLines = static function (string $lines): array {
    $stages = [];
    foreach (preg_split('/\r\n|\r|\n/', $lines) ?: [] as $index => $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $parts = array_map('trim', explode('|', $line, 4));
        $stages[] = [
            'stage_order' => $index + 1,
            'stage_type' => $parts[0] ?? 'strategy',
            'required_record_type' => $parts[1] ?? 'none',
            'title' => $parts[2] ?? $line,
            'instructions' => $parts[3] ?? '',
        ];
    }

    return $stages;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = array_merge($form, [
        'name' => $_POST['name'] ?? '',
        'status' => $_POST['status'] ?? 'draft',
        'description' => $_POST['description'] ?? '',
        'campaign_goal' => $_POST['campaign_goal'] ?? '',
        'target_audience' => $_POST['target_audience'] ?? '',
        'audience_segment_id' => $_POST['audience_segment_id'] ?? '',
        'persona_id' => $_POST['persona_id'] ?? '',
        'offer_context_item_id' => $_POST['offer_context_item_id'] ?? '',
        'landing_page_id' => $_POST['landing_page_id'] ?? '',
        'success_metrics' => $_POST['success_metrics'] ?? '',
        'channel_plan' => $_POST['channel_plan'] ?? '',
        'checklist' => $_POST['checklist'] ?? '',
        'owner_user_id' => $_POST['owner_user_id'] ?? '',
        'stages' => $_POST['stages'] ?? '',
    ]);

    try {
        if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Invalid CSRF token.');
        }
        $payload = array_merge($form, [
            'stages' => $parseStageLines((string) $form['stages']),
            'created_by' => (int) ($user['id'] ?? 0),
        ]);
        if ($id > 0) {
            $marketing->updateCampaignPlaybook($id, $payload);
        } else {
            $id = $marketing->createCampaignPlaybook($payload);
        }
        header('Location: ' . getBasePath() . '/marketing_playbook_view.php?id=' . $id . '&success=saved');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$labelize = static fn(string $value): string => ucwords(str_replace('_', ' ', $value));
$selected = static fn($left, $right): string => (string) $left === (string) $right ? 'selected' : '';
$lineCount = static function (string $value): int {
    return count(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $value) ?: [])));
};
$hasName = trim((string) $form['name']) !== '';
$hasGoal = trim((string) $form['campaign_goal']) !== '';
$hasAudience = trim((string) $form['target_audience']) !== '' || (int) ($form['audience_segment_id'] ?? 0) > 0 || (int) ($form['persona_id'] ?? 0) > 0;
$hasOffer = (int) ($form['offer_context_item_id'] ?? 0) > 0 || (int) ($form['landing_page_id'] ?? 0) > 0;
$metricCount = $lineCount((string) $form['success_metrics']);
$channelCount = $lineCount((string) $form['channel_plan']);
$checklistCount = $lineCount((string) $form['checklist']);
$stageCount = $lineCount((string) $form['stages']);
$status = (string) ($form['status'] ?? 'draft');
$badgeClass = static function (string $status): string {
    return match ($status) {
        'active', 'ready', 'completed' => 'badge-success',
        'blocked', 'needs_foundation' => 'badge-danger',
        'draft', 'planned', 'warning', 'setup_needed' => 'badge-warning',
        'archived' => 'badge-default',
        default => 'badge-default',
    };
};
$summaryTiles = [
    ['icon' => 'fa-layer-group', 'label' => 'Mode', 'value' => $id > 0 ? 'Edit' : 'New', 'tooltip' => 'Campaign Playbook builder mode.'],
    ['icon' => 'fa-bullseye', 'label' => 'Goal', 'value' => $hasGoal ? 'Set' : 'Open', 'tooltip' => 'The campaign outcome this playbook should repeat.'],
    ['icon' => 'fa-users-viewfinder', 'label' => 'Audience', 'value' => $hasAudience ? 'Linked' : 'Open', 'tooltip' => 'Audience, persona, or plain-language customer group for this playbook.'],
    ['icon' => 'fa-list-check', 'label' => 'Stages', 'value' => (string) $stageCount, 'tooltip' => 'Expert stage lines that become the playbook path.'],
];
$builderCards = [
    ['label' => 'Name Pattern', 'icon' => 'fa-pen-nib', 'status' => $hasName ? 'ready' : 'setup_needed', 'sentence' => 'Give the repeatable campaign a name.', 'tooltip' => 'Expert view: playbook name and status.', 'target' => '#playbook-core-fields', 'action' => 'Edit name'],
    ['label' => 'Set Goal', 'icon' => 'fa-bullseye', 'status' => $hasGoal ? 'ready' : 'setup_needed', 'sentence' => 'Say what the campaign should achieve.', 'tooltip' => 'Expert view: campaign goal and description.', 'target' => '#playbook-core-fields', 'action' => 'Set goal'],
    ['label' => 'Choose Audience', 'icon' => 'fa-users-viewfinder', 'status' => $hasAudience ? 'ready' : 'setup_needed', 'sentence' => 'Anchor the plan to customers.', 'tooltip' => 'Expert view: target audience, audience segment, and persona links.', 'target' => '#playbook-foundation-fields', 'action' => 'Choose audience'],
    ['label' => 'Connect Offer', 'icon' => 'fa-link', 'status' => $hasOffer ? 'ready' : 'setup_needed', 'sentence' => 'Tie the pattern to an offer or page.', 'tooltip' => 'Expert view: offer context and landing page relationships.', 'target' => '#playbook-foundation-fields', 'action' => 'Connect offer'],
    ['label' => 'Map Stages', 'icon' => 'fa-diagram-project', 'status' => $stageCount > 0 ? 'ready' : 'setup_needed', 'sentence' => 'Add the reusable campaign steps.', 'tooltip' => 'Expert view: stage type, required record type, title, and instructions.', 'target' => '#playbook-structure-fields', 'action' => 'Map stages'],
];
$pageTitle = ($id > 0 ? 'Edit Campaign Playbook' : 'New Campaign Playbook') . ' - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">

<div class="page-premium marketing-playbook-edit-page">
    <div class="container">
        <div class="page-header">
            <div>
                <h1>Playbook Builder</h1>
                <p>Campaign Playbook: build one repeatable campaign pattern.</p>
            </div>
            <div class="page-header-actions">
                <a class="btn-premium-secondary" href="<?php echo $id > 0 ? 'marketing_playbook_view.php?id=' . (int) $id : 'marketing_playbooks.php'; ?>"><i class="fas fa-arrow-left"></i> Back</a>
            </div>
        </div>

        <?php if ($error !== ''): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

        <form method="POST" class="marketing-playbook-builder-form">
            <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
            <input type="hidden" name="id" value="<?php echo (int) $id; ?>">

            <section class="marketing-playbook-edit-shell">
                <div class="marketing-founder-summary marketing-playbook-edit-summary" aria-label="Playbook builder summary">
                    <?php foreach ($summaryTiles as $tile): ?>
                        <div class="marketing-summary-tile" tabindex="0" data-tooltip="<?php echo htmlspecialchars((string) $tile['tooltip']); ?>">
                            <i class="fas <?php echo htmlspecialchars((string) $tile['icon']); ?>" aria-hidden="true"></i>
                            <span><?php echo htmlspecialchars((string) $tile['label']); ?></span>
                            <strong><?php echo htmlspecialchars((string) $tile['value']); ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>

                <section class="marketing-playbook-builder-grid" aria-label="Playbook builder path">
                    <?php foreach ($builderCards as $card): ?>
                        <?php $cardStatus = (string) $card['status']; ?>
                        <article class="marketing-playbook-builder-card <?php echo htmlspecialchars($cardStatus); ?>" tabindex="0" data-tooltip="<?php echo htmlspecialchars((string) $card['tooltip']); ?>">
                            <div class="marketing-stage-visual">
                                <i class="fas <?php echo htmlspecialchars((string) $card['icon']); ?>" aria-hidden="true"></i>
                            </div>
                            <div class="marketing-playbook-builder-body">
                                <span class="badge <?php echo htmlspecialchars($badgeClass($cardStatus)); ?>"><?php echo htmlspecialchars($labelize($cardStatus)); ?></span>
                                <h2><?php echo htmlspecialchars((string) $card['label']); ?></h2>
                                <p><?php echo htmlspecialchars((string) $card['sentence']); ?></p>
                                <a class="btn-premium-secondary marketing-playbook-builder-action" href="<?php echo htmlspecialchars((string) $card['target']); ?>"><?php echo htmlspecialchars((string) $card['action']); ?></a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </section>

                <div class="marketing-playbook-edit-layout">
                    <main class="marketing-playbook-edit-main">
                        <section class="content-card marketing-playbook-core-fields" id="playbook-core-fields">
                            <div class="premium-section-header">
                                <div>
                                    <h2>Playbook Basics</h2>
                                    <p>Name the pattern and outcome.</p>
                                </div>
                                <span class="badge <?php echo htmlspecialchars($badgeClass($status)); ?>"><?php echo htmlspecialchars($labelize($status)); ?></span>
                            </div>
                            <div class="marketing-playbook-core-grid">
                                <div class="form-group"><label>Name</label><input type="text" name="name" required value="<?php echo htmlspecialchars((string) $form['name']); ?>" placeholder="Webinar-to-demo campaign"></div>
                                <div class="form-group"><label>Status</label><select name="status"><?php foreach (Marketing::CAMPAIGN_PLAYBOOK_STATUSES as $playbookStatus): ?><option value="<?php echo htmlspecialchars($playbookStatus); ?>" <?php echo $selected($form['status'], $playbookStatus); ?>><?php echo htmlspecialchars($labelize($playbookStatus)); ?></option><?php endforeach; ?></select></div>
                                <div class="form-group marketing-playbook-wide-field"><label>Campaign Goal</label><input type="text" name="campaign_goal" value="<?php echo htmlspecialchars((string) $form['campaign_goal']); ?>" placeholder="Increase qualified demo requests"></div>
                                <div class="form-group marketing-playbook-wide-field"><label>Target Audience</label><textarea name="target_audience" rows="3" placeholder="Founder operators who need a clearer launch path"><?php echo htmlspecialchars((string) $form['target_audience']); ?></textarea></div>
                            </div>
                        </section>

                        <section class="content-card marketing-playbook-foundation-fields" id="playbook-foundation-fields">
                            <div class="premium-section-header">
                                <div>
                                    <h2>Campaign Foundation</h2>
                                    <p>Link only what helps this pattern.</p>
                                </div>
                            </div>
                            <div class="marketing-playbook-foundation-grid">
                                <div class="form-group"><label>Audience Segment</label><select name="audience_segment_id"><option value="">No segment linked</option><?php foreach ((array) ($options['audience_segments'] ?? []) as $segment): ?><option value="<?php echo (int) $segment['id']; ?>" <?php echo $selected($form['audience_segment_id'], $segment['id']); ?>><?php echo htmlspecialchars((string) $segment['name']); ?></option><?php endforeach; ?></select></div>
                                <div class="form-group"><label>Persona</label><select name="persona_id"><option value="">No persona linked</option><?php foreach ((array) ($options['personas'] ?? []) as $persona): ?><option value="<?php echo (int) $persona['id']; ?>" <?php echo $selected($form['persona_id'], $persona['id']); ?>><?php echo htmlspecialchars((string) $persona['name']); ?></option><?php endforeach; ?></select></div>
                                <div class="form-group"><label>Offer</label><select name="offer_context_item_id"><option value="">No offer linked</option><?php foreach ((array) ($options['context_offers'] ?? []) as $offer): ?><option value="<?php echo (int) $offer['id']; ?>" <?php echo $selected($form['offer_context_item_id'], $offer['id']); ?>><?php echo htmlspecialchars((string) $offer['title']); ?></option><?php endforeach; ?></select></div>
                                <div class="form-group"><label>Landing Page</label><select name="landing_page_id"><option value="">No page linked</option><?php foreach ((array) ($options['landing_pages'] ?? []) as $landingPage): ?><option value="<?php echo (int) $landingPage['id']; ?>" <?php echo $selected($form['landing_page_id'], $landingPage['id']); ?>><?php echo htmlspecialchars((string) $landingPage['title']); ?></option><?php endforeach; ?></select></div>
                            </div>
                            <details class="marketing-playbook-owner-tools">
                                <summary>More ownership fields</summary>
                                <div class="marketing-playbook-owner-tools-body">
                                    <div class="form-group"><label>Owner</label><select name="owner_user_id"><option value="">Unassigned</option><?php foreach ((array) ($options['users'] ?? []) as $owner): ?><option value="<?php echo (int) $owner['id']; ?>" <?php echo $selected($form['owner_user_id'], $owner['id']); ?>><?php echo htmlspecialchars((string) $owner['email']); ?></option><?php endforeach; ?></select></div>
                                    <div class="form-group"><label>Description</label><textarea name="description" rows="3"><?php echo htmlspecialchars((string) $form['description']); ?></textarea></div>
                                </div>
                            </details>
                        </section>

                        <section class="content-card marketing-playbook-structure-fields" id="playbook-structure-fields">
                            <div class="premium-section-header">
                                <div>
                                    <h2>Playbook Structure</h2>
                                    <p>Keep the visible plan short.</p>
                                </div>
                            </div>
                            <div class="marketing-playbook-structure-grid">
                                <article><span>Metrics</span><strong><?php echo $metricCount; ?></strong></article>
                                <article><span>Channels</span><strong><?php echo $channelCount; ?></strong></article>
                                <article><span>Checklist</span><strong><?php echo $checklistCount; ?></strong></article>
                                <article><span>Stages</span><strong><?php echo $stageCount; ?></strong></article>
                            </div>
                            <details class="marketing-playbook-structure-tools">
                                <summary>More playbook structure</summary>
                                <div class="marketing-playbook-structure-tools-body">
                                    <div class="form-group"><label>Success Metrics</label><textarea name="success_metrics" rows="5" placeholder="One metric per line"><?php echo htmlspecialchars((string) $form['success_metrics']); ?></textarea></div>
                                    <div class="form-group"><label>Channel Plan</label><textarea name="channel_plan" rows="5" placeholder="One channel decision per line"><?php echo htmlspecialchars((string) $form['channel_plan']); ?></textarea></div>
                                    <div class="form-group marketing-playbook-wide-field"><label>Checklist</label><textarea name="checklist" rows="4" placeholder="One checklist item per line"><?php echo htmlspecialchars((string) $form['checklist']); ?></textarea></div>
                                    <div class="form-group marketing-playbook-wide-field">
                                        <label>Stages</label>
                                        <span class="marketing-playbook-stage-help">Format: stage type | required record | title | instructions</span>
                                        <textarea name="stages" rows="8" placeholder="strategy | brief | Lock campaign brief | Confirm audience, offer, and CTA"><?php echo htmlspecialchars((string) $form['stages']); ?></textarea>
                                    </div>
                                </div>
                            </details>
                        </section>
                    </main>

                    <aside class="content-card marketing-playbook-builder-today">
                        <div class="premium-section-header">
                            <div>
                                <h2>Today</h2>
                                <p>Save one reusable pattern.</p>
                            </div>
                        </div>
                        <div class="marketing-playbook-builder-next-list">
                            <a class="marketing-today-action" href="#playbook-core-fields" data-tooltip="Start with name, campaign goal, and audience."><i class="fas fa-arrow-right" aria-hidden="true"></i><span>Complete basics</span></a>
                            <a class="marketing-today-action" href="#playbook-foundation-fields" data-tooltip="Link audience, persona, offer, or page only when useful."><i class="fas fa-arrow-right" aria-hidden="true"></i><span>Link foundation</span></a>
                            <a class="marketing-today-action" href="#playbook-structure-fields" data-tooltip="Open the structure drawer for metrics, channels, checklist, and stages."><i class="fas fa-arrow-right" aria-hidden="true"></i><span>Map structure</span></a>
                        </div>
                        <div class="marketing-playbook-builder-save">
                            <a class="btn-premium-secondary" href="<?php echo $id > 0 ? 'marketing_playbook_view.php?id=' . (int) $id : 'marketing_playbooks.php'; ?>">Cancel</a>
                            <button class="btn-premium-primary" type="submit">Save Playbook</button>
                        </div>
                    </aside>
                </div>
            </section>
        </form>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
