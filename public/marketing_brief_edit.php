<?php
/**
 * Create/edit marketing campaign brief.
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
    header('Location: ' . getBasePath() . '/marketing_briefs.php');
    exit;
}

$marketing = new Marketing();
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$brief = $id > 0 ? $marketing->getCampaignBrief($id) : null;
if ($id > 0 && !$brief) {
    http_response_code(404);
    echo 'Marketing campaign brief not found.';
    exit;
}

$options = $marketing->optionData();
$error = '';
$notice = '';
$form = [
    'title' => $brief['title'] ?? '',
    'status' => $brief['status'] ?? 'draft',
    'objective' => $brief['objective'] ?? '',
    'audience' => $brief['audience'] ?? '',
    'audience_segment_id' => $brief['audience_segment_id'] ?? '',
    'offer_text' => $brief['offer_text'] ?? '',
    'key_message' => $brief['key_message'] ?? '',
    'channels' => implode(', ', (array) ($brief['channels_json'] ?? [])),
    'channel_plan' => implode("\n", (array) ($brief['channel_plan_json'] ?? [])),
    'launch_timeline' => implode("\n", (array) ($brief['launch_timeline_json'] ?? [])),
    'start_date' => $brief['start_date'] ?? '',
    'end_date' => $brief['end_date'] ?? '',
    'campaign_id' => $brief['campaign_id'] ?? '',
    'campaign_playbook_id' => $brief['campaign_playbook_id'] ?? '',
    'persona_id' => $brief['persona_id'] ?? '',
    'offer_context_item_id' => $brief['offer_context_item_id'] ?? '',
    'content_pillar_context_item_id' => $brief['content_pillar_context_item_id'] ?? '',
    'landing_page_id' => $brief['landing_page_id'] ?? '',
    'success_metrics' => $brief['success_metrics'] ?? '',
    'budget_estimate' => $brief['budget_estimate'] ?? '',
    'owner_user_id' => $brief['owner_user_id'] ?? '',
    'generated_ai_context_json' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = array_merge($form, [
        'title' => $_POST['title'] ?? '',
        'status' => $_POST['status'] ?? 'draft',
        'objective' => $_POST['objective'] ?? '',
        'audience' => $_POST['audience'] ?? '',
        'audience_segment_id' => $_POST['audience_segment_id'] ?? '',
        'offer_text' => $_POST['offer_text'] ?? '',
        'key_message' => $_POST['key_message'] ?? '',
        'channels' => $_POST['channels'] ?? '',
        'channel_plan' => $_POST['channel_plan'] ?? '',
        'launch_timeline' => $_POST['launch_timeline'] ?? '',
        'start_date' => $_POST['start_date'] ?? '',
        'end_date' => $_POST['end_date'] ?? '',
        'campaign_id' => $_POST['campaign_id'] ?? '',
        'campaign_playbook_id' => $_POST['campaign_playbook_id'] ?? '',
        'persona_id' => $_POST['persona_id'] ?? '',
        'offer_context_item_id' => $_POST['offer_context_item_id'] ?? '',
        'content_pillar_context_item_id' => $_POST['content_pillar_context_item_id'] ?? '',
        'landing_page_id' => $_POST['landing_page_id'] ?? '',
        'success_metrics' => $_POST['success_metrics'] ?? '',
        'budget_estimate' => $_POST['budget_estimate'] ?? '',
        'owner_user_id' => $_POST['owner_user_id'] ?? '',
        'generated_ai_context_json' => $_POST['generated_ai_context_json'] ?? '',
    ]);

    try {
        if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Invalid CSRF token.');
        }
        $action = (string) ($_POST['action'] ?? 'save');
        $payload = $form + ['created_by' => (int) ($user['id'] ?? 0)];
        if ($action === 'generate_brief') {
            $draft = $marketing->generateCampaignBriefDraft($payload);
            $form = array_merge($form, (array) ($draft['fields'] ?? []));
            $form['generated_ai_context_json'] = json_encode($draft['ai_context'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $notice = 'AI brief draft generated. Review the fields, adjust anything needed, then save.';
        } else {
            if (trim((string) ($form['generated_ai_context_json'] ?? '')) !== '') {
                $generatedContext = json_decode((string) $form['generated_ai_context_json'], true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($generatedContext)) {
                    $existingMetadata = (array) ($brief['metadata_json'] ?? []);
                    $existingMetadata['ai_brief_generation'] = $generatedContext;
                    $payload['metadata_json'] = $existingMetadata;
                }
            }
            if ($id > 0) {
                $marketing->updateCampaignBrief($id, $payload);
            } else {
                $id = $marketing->createCampaignBrief($payload);
            }
            header('Location: ' . getBasePath() . '/marketing_brief_view.php?id=' . $id . '&success=saved');
            exit;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$labelize = static fn(string $value): string => ucwords(str_replace('_', ' ', $value));
$selected = static fn($left, $right): string => (string) $left === (string) $right ? 'selected' : '';
$isFilled = static fn($value): bool => trim((string) $value) !== '';
$coreDecisions = [
    'title' => $isFilled($form['title']),
    'objective' => $isFilled($form['objective']),
    'audience' => $isFilled($form['audience']) || (int) ($form['audience_segment_id'] ?? 0) > 0,
    'offer' => $isFilled($form['offer_text']) || (int) ($form['offer_context_item_id'] ?? 0) > 0,
    'message' => $isFilled($form['key_message']),
];
$coreReadyCount = count(array_filter($coreDecisions));
$coreScore = (int) round(($coreReadyCount / max(1, count($coreDecisions))) * 100);
$linkedCount = count(array_filter([
    (int) ($form['campaign_id'] ?? 0) > 0,
    (int) ($form['campaign_playbook_id'] ?? 0) > 0,
    (int) ($form['audience_segment_id'] ?? 0) > 0,
    (int) ($form['persona_id'] ?? 0) > 0,
    (int) ($form['offer_context_item_id'] ?? 0) > 0,
    (int) ($form['content_pillar_context_item_id'] ?? 0) > 0,
    (int) ($form['landing_page_id'] ?? 0) > 0,
    (int) ($form['owner_user_id'] ?? 0) > 0,
]));
$scheduleReady = $isFilled($form['start_date']) || $isFilled($form['end_date']) || $isFilled($form['launch_timeline']);
$hasAiContext = $isFilled($form['generated_ai_context_json']) || !empty($brief['metadata_json']['ai_brief_generation']);
$decisionCards = [
    [
        'label' => 'Goal',
        'status' => $coreDecisions['objective'] ? 'ready' : 'setup-needed',
        'icon' => 'fa-bullseye',
        'href' => '#field-objective',
        'tooltip' => 'What result should this campaign produce?',
    ],
    [
        'label' => 'Audience',
        'status' => $coreDecisions['audience'] ? 'ready' : 'setup-needed',
        'icon' => 'fa-users',
        'href' => '#field-audience',
        'tooltip' => 'Who should receive the campaign?',
    ],
    [
        'label' => 'Offer',
        'status' => $coreDecisions['offer'] ? 'ready' : 'setup-needed',
        'icon' => 'fa-gift',
        'href' => '#field-offer',
        'tooltip' => 'What value or promise will move the audience?',
    ],
    [
        'label' => 'Message',
        'status' => $coreDecisions['message'] ? 'ready' : 'setup-needed',
        'icon' => 'fa-message',
        'href' => '#field-message',
        'tooltip' => 'What should the audience understand or do next?',
    ],
    [
        'label' => 'Launch',
        'status' => $scheduleReady ? 'ready' : 'setup-needed',
        'icon' => 'fa-calendar-check',
        'href' => '#brief-advanced-fields',
        'tooltip' => 'Timeline, channel plan, metrics, and handoff details.',
    ],
];
$pageTitle = ($id > 0 ? 'Edit Brief' : 'New Brief') . ' - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<div class="page-premium marketing-brief-edit-page">
    <div class="container">
        <div class="page-header">
            <div>
                <h1><?php echo $id > 0 ? 'Edit Campaign Brief' : 'New Campaign Brief'; ?></h1>
                <p>Make one campaign clear enough to execute.</p>
            </div>
            <div class="page-header-actions">
                <a class="btn-premium-secondary" href="marketing_briefs.php">Campaign Plans</a>
                <?php if ($id > 0): ?><a class="btn-premium-secondary" href="marketing_brief_view.php?id=<?php echo (int) $id; ?>">View brief</a><?php endif; ?>
            </div>
        </div>

        <?php if ($error !== ''): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
        <?php if ($notice !== ''): ?><div class="alert alert-success"><?php echo htmlspecialchars($notice); ?></div><?php endif; ?>

        <section class="marketing-brief-edit-shell" aria-label="Campaign brief builder">
            <div class="marketing-founder-summary marketing-brief-edit-summary">
                <a class="marketing-summary-tile primary" href="#brief-core-fields" data-tooltip="Start with the decisions that make the campaign usable.">
                    <i class="fas fa-arrow-right"></i><span>Next</span><strong><?php echo $coreScore >= 100 ? 'Save brief' : 'Fill core'; ?></strong>
                </a>
                <div class="marketing-summary-tile" data-tooltip="Core decisions: title, goal, audience, offer, and message.">
                    <i class="fas fa-gauge-high"></i><span>Core</span><strong><?php echo $coreScore; ?>%</strong>
                </div>
                <div class="marketing-summary-tile" data-tooltip="Expert records connected to this brief.">
                    <i class="fas fa-link"></i><span>Linked</span><strong><?php echo $linkedCount; ?></strong>
                </div>
                <div class="marketing-summary-tile" data-tooltip="AI can draft fields, but the founder still reviews and saves.">
                    <i class="fas fa-wand-magic-sparkles"></i><span>AI Draft</span><strong><?php echo $hasAiContext ? 'Ready' : 'Optional'; ?></strong>
                </div>
            </div>

            <div class="marketing-brief-builder-grid">
                <?php foreach ($decisionCards as $card): ?>
                    <?php $cardStatus = preg_replace('/[^a-z0-9_-]/i', '', (string) ($card['status'] ?? 'setup-needed')); ?>
                    <a class="marketing-brief-builder-card <?php echo htmlspecialchars($cardStatus); ?>" href="<?php echo htmlspecialchars((string) ($card['href'] ?? '#brief-core-fields')); ?>" data-tooltip="<?php echo htmlspecialchars((string) ($card['tooltip'] ?? 'Campaign brief decision.')); ?>">
                        <div class="marketing-stage-visual">
                            <i class="fas <?php echo htmlspecialchars((string) ($card['icon'] ?? 'fa-clipboard-list')); ?>"></i>
                            <span><?php echo htmlspecialchars($cardStatus === 'ready' ? 'Set' : 'Need'); ?></span>
                        </div>
                        <div class="marketing-stage-title-row">
                            <h2><?php echo htmlspecialchars((string) ($card['label'] ?? 'Decision')); ?></h2>
                            <span class="marketing-stage-badge <?php echo htmlspecialchars($cardStatus); ?>"><?php echo htmlspecialchars($labelize(str_replace('-', '_', $cardStatus))); ?></span>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>

            <form method="POST" class="marketing-brief-edit-form">
                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                <input type="hidden" name="id" value="<?php echo (int) $id; ?>">
                <input type="hidden" name="generated_ai_context_json" value="<?php echo htmlspecialchars((string) $form['generated_ai_context_json']); ?>">

                <div class="marketing-founder-layout marketing-brief-edit-layout">
                    <div class="marketing-brief-edit-main">
                        <div class="content-card marketing-brief-core-fields" id="brief-core-fields">
                            <div class="premium-section-header">
                                <div>
                                    <h2>Core Decisions</h2>
                                    <p><?php echo $coreReadyCount; ?>/<?php echo count($coreDecisions); ?> set</p>
                                </div>
                            </div>
                            <div class="marketing-brief-core-grid">
                                <div class="form-group" id="field-title"><label>Title</label><input type="text" name="title" required value="<?php echo htmlspecialchars((string) $form['title']); ?>"></div>
                                <div class="form-group"><label>Status</label><select name="status"><?php foreach (Marketing::BRIEF_STATUSES as $status): ?><option value="<?php echo htmlspecialchars($status); ?>" <?php echo $selected($form['status'], $status); ?>><?php echo htmlspecialchars($labelize($status)); ?></option><?php endforeach; ?></select></div>
                                <div class="form-group" id="field-objective"><label>Goal</label><input type="text" name="objective" value="<?php echo htmlspecialchars((string) $form['objective']); ?>"></div>
                                <div class="form-group" id="field-audience"><label>Audience</label><input type="text" name="audience" value="<?php echo htmlspecialchars((string) $form['audience']); ?>"></div>
                                <div class="form-group" id="field-offer"><label>Offer</label><input type="text" name="offer_text" value="<?php echo htmlspecialchars((string) $form['offer_text']); ?>"></div>
                                <div class="form-group"><label>Channels</label><input type="text" name="channels" value="<?php echo htmlspecialchars((string) $form['channels']); ?>" placeholder="email, linkedin, blog"></div>
                                <div class="form-group"><label>Start</label><input type="date" name="start_date" value="<?php echo htmlspecialchars((string) $form['start_date']); ?>"></div>
                                <div class="form-group"><label>End</label><input type="date" name="end_date" value="<?php echo htmlspecialchars((string) $form['end_date']); ?>"></div>
                                <div class="form-group marketing-brief-wide-field" id="field-message"><label>Key Message</label><textarea name="key_message" rows="6"><?php echo htmlspecialchars((string) $form['key_message']); ?></textarea></div>
                            </div>
                        </div>

                        <details class="marketing-advanced-tools marketing-brief-edit-tools" id="brief-advanced-fields">
                            <summary>More brief fields <i class="fas fa-chevron-down"></i></summary>
                            <div class="marketing-brief-edit-tools-body">
                                <div class="marketing-brief-core-grid">
                                    <div class="form-group"><label>Linked Campaign</label><select name="campaign_id"><option value="">None</option><?php foreach ($options['campaigns'] as $campaign): ?><option value="<?php echo (int) $campaign['id']; ?>" <?php echo $selected($form['campaign_id'], $campaign['id']); ?>><?php echo htmlspecialchars((string) $campaign['name']); ?></option><?php endforeach; ?></select></div>
                                    <div class="form-group"><label>Campaign Playbook</label><select name="campaign_playbook_id"><option value="">None</option><?php foreach ($options['campaign_playbooks'] as $playbook): ?><option value="<?php echo (int) $playbook['id']; ?>" <?php echo $selected($form['campaign_playbook_id'], $playbook['id']); ?>><?php echo htmlspecialchars((string) $playbook['name']); ?> (<?php echo (int) ($playbook['readiness_score'] ?? 0); ?>)</option><?php endforeach; ?></select></div>
                                    <div class="form-group"><label>Audience Segment</label><select name="audience_segment_id"><option value="">None</option><?php foreach ($options['audience_segments'] as $segment): ?><option value="<?php echo (int) $segment['id']; ?>" <?php echo $selected($form['audience_segment_id'], $segment['id']); ?>><?php echo htmlspecialchars((string) $segment['name']); ?> (<?php echo (int) ($segment['preview_count'] ?? 0); ?>)</option><?php endforeach; ?></select></div>
                                    <div class="form-group"><label>Owner</label><select name="owner_user_id"><option value="">Unassigned</option><?php foreach ($options['users'] as $owner): ?><option value="<?php echo (int) $owner['id']; ?>" <?php echo $selected($form['owner_user_id'], $owner['id']); ?>><?php echo htmlspecialchars((string) $owner['email']); ?></option><?php endforeach; ?></select></div>
                                    <div class="form-group"><label>Persona</label><select name="persona_id"><option value="">None</option><?php foreach ($options['personas'] as $persona): ?><option value="<?php echo (int) $persona['id']; ?>" <?php echo $selected($form['persona_id'], $persona['id']); ?>><?php echo htmlspecialchars((string) $persona['name']); ?></option><?php endforeach; ?></select></div>
                                    <div class="form-group"><label>Strategic Offer</label><select name="offer_context_item_id"><option value="">None</option><?php foreach ($options['context_offers'] as $offer): ?><option value="<?php echo (int) $offer['id']; ?>" <?php echo $selected($form['offer_context_item_id'], $offer['id']); ?>><?php echo htmlspecialchars((string) $offer['title']); ?></option><?php endforeach; ?></select></div>
                                    <div class="form-group"><label>Content Pillar</label><select name="content_pillar_context_item_id"><option value="">None</option><?php foreach ($options['content_pillars'] as $pillar): ?><option value="<?php echo (int) $pillar['id']; ?>" <?php echo $selected($form['content_pillar_context_item_id'], $pillar['id']); ?>><?php echo htmlspecialchars((string) $pillar['title']); ?></option><?php endforeach; ?></select></div>
                                    <div class="form-group"><label>Linked Landing Page</label><select name="landing_page_id"><option value="">None</option><?php foreach ($options['landing_pages'] as $landingPage): ?><option value="<?php echo (int) $landingPage['id']; ?>" <?php echo $selected($form['landing_page_id'], $landingPage['id']); ?>><?php echo htmlspecialchars((string) $landingPage['title']); ?></option><?php endforeach; ?></select></div>
                                    <div class="form-group"><label>Budget Estimate</label><input type="number" step="0.01" min="0" name="budget_estimate" value="<?php echo htmlspecialchars((string) $form['budget_estimate']); ?>"></div>
                                    <div class="form-group marketing-brief-wide-field"><label>Success Metrics</label><textarea name="success_metrics" rows="4"><?php echo htmlspecialchars((string) $form['success_metrics']); ?></textarea></div>
                                    <div class="form-group"><label>Channel Plan</label><textarea name="channel_plan" rows="5" placeholder="Email launch sequence&#10;LinkedIn founder post"><?php echo htmlspecialchars((string) $form['channel_plan']); ?></textarea></div>
                                    <div class="form-group"><label>Launch Timeline</label><textarea name="launch_timeline" rows="5" placeholder="Brief approved by Friday&#10;Landing page ready next week"><?php echo htmlspecialchars((string) $form['launch_timeline']); ?></textarea></div>
                                </div>
                            </div>
                        </details>
                    </div>

                    <aside class="content-card marketing-brief-builder-today">
                        <div class="premium-section-header">
                            <div>
                                <h2>Today</h2>
                                <p>Draft, review, save.</p>
                            </div>
                        </div>
                        <div class="marketing-brief-score-card">
                            <div class="setup-score-ring" data-score="<?php echo $coreScore; ?>"><span><?php echo $coreScore; ?>%</span></div>
                            <strong><?php echo $coreScore >= 100 ? 'Ready to save' : 'Keep filling core decisions'; ?></strong>
                        </div>
                        <div class="marketing-brief-next-list">
                            <button class="btn-premium-secondary" type="submit" name="action" value="generate_brief"><i class="fas fa-wand-magic-sparkles"></i> Generate Draft</button>
                            <button class="btn-premium-primary" type="submit" name="action" value="save">Save Brief</button>
                            <a class="marketing-today-action" href="marketing_briefs.php" data-tooltip="Return to the campaign plan board without saving.">
                                <i class="fas fa-table-cells-large"></i><strong>Plan board</strong><span>Review all campaign plans.</span>
                            </a>
                        </div>
                    </aside>
                </div>
            </form>
        </section>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
