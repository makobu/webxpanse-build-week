<?php
/**
 * Create/edit a marketing journey.
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
    header('Location: ' . getBasePath() . '/marketing_journeys.php');
    exit;
}

$marketing = new Marketing();
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$journey = $id > 0 ? $marketing->getJourney($id) : null;
if ($id > 0 && !$journey) {
    http_response_code(404);
    echo 'Marketing journey not found.';
    exit;
}

$options = $marketing->optionData();
$contentItems = $marketing->listContentItems(['exclude_status' => 'archived'], 100, 0);
$emailRuns = $marketing->listEmailCampaignRuns(['open' => true], 100, 0);
$error = '';
$form = [
    'name' => $journey['name'] ?? '',
    'status' => $journey['status'] ?? 'draft',
    'journey_goal' => $journey['journey_goal'] ?? '',
    'description' => $journey['description'] ?? '',
    'audience_segment_id' => $journey['audience_segment_id'] ?? '',
    'campaign_id' => $journey['campaign_id'] ?? '',
    'owner_user_id' => $journey['owner_user_id'] ?? '',
];
$steps = (array) ($journey['steps'] ?? []);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = array_merge($form, [
        'name' => $_POST['name'] ?? '',
        'status' => $_POST['status'] ?? 'draft',
        'journey_goal' => $_POST['journey_goal'] ?? '',
        'description' => $_POST['description'] ?? '',
        'audience_segment_id' => $_POST['audience_segment_id'] ?? '',
        'campaign_id' => $_POST['campaign_id'] ?? '',
        'owner_user_id' => $_POST['owner_user_id'] ?? '',
    ]);
    $steps = array_values((array) ($_POST['steps'] ?? []));

    try {
        if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Invalid CSRF token.');
        }
        $payload = $form + [
            'steps' => $steps,
            'created_by' => (int) ($user['id'] ?? 0),
            'metadata' => ['source' => 'marketing_journey_planner'],
        ];
        if ($id > 0) {
            $marketing->updateJourney($id, $payload);
        } else {
            $id = $marketing->createJourney($payload);
        }
        header('Location: ' . getBasePath() . '/marketing_journey_view.php?id=' . $id . '&success=saved');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

while (count($steps) < 8) {
    $steps[] = [
        'step_type' => 'email',
        'title' => '',
        'instructions' => '',
        'wait_days' => '',
        'content_item_id' => '',
        'email_run_id' => '',
        'task_id' => '',
        'condition_json' => '',
        'branch_json' => '',
    ];
}

$labelize = static fn(string $value): string => ucwords(str_replace('_', ' ', $value));
$selected = static fn($left, $right): string => (string) $left === (string) $right ? 'selected' : '';
$hasAudience = (int) ($form['audience_segment_id'] ?? 0) > 0;
$hasCampaign = (int) ($form['campaign_id'] ?? 0) > 0;
$namedSteps = count(array_filter($steps, static fn(array $step): bool => trim((string) ($step['title'] ?? '')) !== ''));
$linkedStepCount = count(array_filter($steps, static fn(array $step): bool => (int) ($step['content_item_id'] ?? 0) > 0 || (int) ($step['email_run_id'] ?? 0) > 0 || (int) ($step['task_id'] ?? 0) > 0));
$status = (string) ($form['status'] ?? 'draft');
$badgeClass = static function (string $status): string {
    return match ($status) {
        'ready', 'active', 'completed' => 'badge-success',
        'blocked' => 'badge-danger',
        'warning', 'draft', 'planned', 'paused', 'setup_needed' => 'badge-warning',
        'archived' => 'badge-default',
        default => 'badge-default',
    };
};
$summaryTiles = [
    ['icon' => 'fa-route', 'label' => 'Mode', 'value' => $id > 0 ? 'Edit' : 'New', 'tooltip' => 'Marketing Journey builder mode.'],
    ['icon' => 'fa-users', 'label' => 'Audience', 'value' => $hasAudience ? 'Linked' : 'Open', 'tooltip' => 'Connect an audience so the path is anchored in a real customer group.'],
    ['icon' => 'fa-list-check', 'label' => 'Steps', 'value' => (string) $namedSteps, 'tooltip' => 'Named journey steps that will become the visible customer path.'],
    ['icon' => 'fa-link', 'label' => 'Linked work', 'value' => (string) $linkedStepCount, 'tooltip' => 'Steps connected to content, email runs, or tasks.'],
];
$builderCards = [
    ['label' => 'Name Path', 'icon' => 'fa-pen-nib', 'status' => trim((string) $form['name']) !== '' ? 'ready' : 'setup_needed', 'sentence' => 'Give the journey a clear name.', 'tooltip' => 'Expert view: journey name and status.', 'target' => '#journey-core-fields', 'action' => 'Edit name'],
    ['label' => 'Choose Audience', 'icon' => 'fa-users-viewfinder', 'status' => $hasAudience ? 'ready' : 'setup_needed', 'sentence' => 'Anchor the path to an audience.', 'tooltip' => 'Expert view: audience segment relationship.', 'target' => '#journey-core-fields', 'action' => 'Choose audience'],
    ['label' => 'Set Goal', 'icon' => 'fa-bullseye', 'status' => trim((string) $form['journey_goal']) !== '' ? 'ready' : 'setup_needed', 'sentence' => 'Say what the path should achieve.', 'tooltip' => 'Expert view: journey goal and description.', 'target' => '#journey-core-fields', 'action' => 'Set goal'],
    ['label' => 'Map Steps', 'icon' => 'fa-route', 'status' => $namedSteps > 0 ? 'ready' : 'setup_needed', 'sentence' => 'Add the customer movement.', 'tooltip' => 'Expert view: ordered steps, types, waits, and branches.', 'target' => '#journey-steps', 'action' => 'Map steps'],
    ['label' => 'Connect Work', 'icon' => 'fa-link', 'status' => $linkedStepCount > 0 ? 'ready' : 'setup_needed', 'sentence' => 'Attach content, email, or tasks.', 'tooltip' => 'Expert view: content, email run, task, condition JSON, and branch JSON fields.', 'target' => '#journey-steps', 'action' => 'Connect work'],
];
$pageTitle = ($id > 0 ? 'Edit Marketing Journey' : 'New Marketing Journey') . ' - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">

<div class="page-premium marketing-journey-edit-page">
    <div class="container">
        <div class="page-header">
            <div>
                <h1>Journey Builder</h1>
                <p>Marketing Journey: build the path before content and launch work.</p>
            </div>
            <div class="page-header-actions">
                <a class="btn-premium-secondary" href="<?php echo $id > 0 ? 'marketing_journey_view.php?id=' . (int) $id : 'marketing_journeys.php'; ?>"><i class="fas fa-arrow-left"></i> Back</a>
            </div>
        </div>

        <?php if ($error !== ''): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

        <form method="POST" class="marketing-journey-builder-form">
            <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
            <input type="hidden" name="id" value="<?php echo (int) $id; ?>">

            <section class="marketing-journey-edit-shell">
                <div class="marketing-founder-summary marketing-journey-edit-summary" aria-label="Journey builder summary">
                    <?php foreach ($summaryTiles as $tile): ?>
                        <div class="marketing-summary-tile" tabindex="0" data-tooltip="<?php echo htmlspecialchars((string) $tile['tooltip']); ?>">
                            <i class="fas <?php echo htmlspecialchars((string) $tile['icon']); ?>" aria-hidden="true"></i>
                            <span><?php echo htmlspecialchars((string) $tile['label']); ?></span>
                            <strong><?php echo htmlspecialchars((string) $tile['value']); ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>

                <section class="marketing-journey-builder-grid" aria-label="Journey builder path">
                    <?php foreach ($builderCards as $card): ?>
                        <?php $cardStatus = (string) $card['status']; ?>
                        <article class="marketing-journey-builder-card <?php echo htmlspecialchars($cardStatus); ?>" tabindex="0" data-tooltip="<?php echo htmlspecialchars((string) $card['tooltip']); ?>">
                            <div class="marketing-stage-visual">
                                <i class="fas <?php echo htmlspecialchars((string) $card['icon']); ?>" aria-hidden="true"></i>
                            </div>
                            <div class="marketing-journey-builder-body">
                                <span class="badge <?php echo htmlspecialchars($badgeClass($cardStatus)); ?>"><?php echo htmlspecialchars($labelize($cardStatus)); ?></span>
                                <h2><?php echo htmlspecialchars((string) $card['label']); ?></h2>
                                <p><?php echo htmlspecialchars((string) $card['sentence']); ?></p>
                                <a class="btn-premium-secondary marketing-journey-builder-action" href="<?php echo htmlspecialchars((string) $card['target']); ?>"><?php echo htmlspecialchars((string) $card['action']); ?></a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </section>

                <div class="marketing-journey-edit-layout">
                    <main class="marketing-journey-edit-main">
                        <section class="content-card marketing-journey-core-fields" id="journey-core-fields">
                            <div class="premium-section-header">
                                <div>
                                    <h2>Journey Basics</h2>
                                    <p>Define the path in plain language.</p>
                                </div>
                                <span class="badge <?php echo htmlspecialchars($badgeClass($status)); ?>"><?php echo htmlspecialchars($labelize($status)); ?></span>
                            </div>
                            <div class="marketing-journey-core-grid">
                                <div class="form-group"><label>Name</label><input type="text" name="name" required value="<?php echo htmlspecialchars((string) $form['name']); ?>" placeholder="Trial-to-demo nurture path"></div>
                                <div class="form-group"><label>Status</label><select name="status"><?php foreach (Marketing::JOURNEY_STATUSES as $journeyStatus): ?><option value="<?php echo htmlspecialchars($journeyStatus); ?>" <?php echo $selected($form['status'], $journeyStatus); ?>><?php echo htmlspecialchars($labelize($journeyStatus)); ?></option><?php endforeach; ?></select></div>
                                <div class="form-group"><label>Audience</label><select name="audience_segment_id"><option value="">No audience linked</option><?php foreach ((array) ($options['audience_segments'] ?? []) as $segment): ?><option value="<?php echo (int) $segment['id']; ?>" <?php echo $selected($form['audience_segment_id'], $segment['id']); ?>><?php echo htmlspecialchars((string) $segment['name']); ?></option><?php endforeach; ?></select></div>
                                <div class="form-group"><label>Campaign</label><select name="campaign_id"><option value="">No campaign linked</option><?php foreach ((array) ($options['campaigns'] ?? []) as $campaign): ?><option value="<?php echo (int) $campaign['id']; ?>" <?php echo $selected($form['campaign_id'], $campaign['id']); ?>><?php echo htmlspecialchars((string) $campaign['name']); ?></option><?php endforeach; ?></select></div>
                                <div class="form-group"><label>Owner</label><select name="owner_user_id"><option value="">Unassigned</option><?php foreach ((array) ($options['users'] ?? []) as $owner): ?><option value="<?php echo (int) $owner['id']; ?>" <?php echo $selected($form['owner_user_id'], $owner['id']); ?>><?php echo htmlspecialchars((string) $owner['email']); ?></option><?php endforeach; ?></select></div>
                                <div class="form-group"><label>Goal</label><input type="text" name="journey_goal" value="<?php echo htmlspecialchars((string) $form['journey_goal']); ?>" placeholder="Move qualified leads to demo requests"></div>
                                <div class="form-group marketing-journey-description-field"><label>Description</label><textarea name="description" rows="3"><?php echo htmlspecialchars((string) $form['description']); ?></textarea></div>
                            </div>
                        </section>

                        <section class="content-card marketing-journey-step-builder" id="journey-steps">
                            <div class="premium-section-header">
                                <div>
                                    <h2>Journey Steps</h2>
                                    <p>Leave unused cards blank.</p>
                                </div>
                            </div>
                            <div class="marketing-journey-step-grid">
                                <?php foreach (array_slice($steps, 0, 12) as $index => $step): ?>
                                    <?php $stepType = in_array((string) ($step['step_type'] ?? ''), Marketing::JOURNEY_STEP_TYPES, true) ? (string) $step['step_type'] : 'email'; ?>
                                    <article class="marketing-journey-step-card">
                                        <div class="marketing-journey-step-head">
                                            <span><?php echo str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT); ?></span>
                                            <strong><?php echo htmlspecialchars($labelize($stepType)); ?></strong>
                                        </div>
                                        <div class="marketing-journey-step-core">
                                            <div class="form-group"><label>Title</label><input type="text" name="steps[<?php echo (int) $index; ?>][title]" value="<?php echo htmlspecialchars((string) ($step['title'] ?? '')); ?>" placeholder="Send first message"></div>
                                            <div class="form-group"><label>Wait days</label><input type="number" min="0" name="steps[<?php echo (int) $index; ?>][wait_days]" value="<?php echo htmlspecialchars((string) ($step['wait_days'] ?? '')); ?>"></div>
                                        </div>
                                        <details class="marketing-journey-step-tools">
                                            <summary>More step fields</summary>
                                            <div class="marketing-journey-step-tools-body">
                                                <div class="form-group"><label>Order</label><input type="number" min="1" name="steps[<?php echo (int) $index; ?>][step_order]" value="<?php echo htmlspecialchars((string) ($step['step_order'] ?? ($index + 1))); ?>"></div>
                                                <div class="form-group"><label>Type</label><select name="steps[<?php echo (int) $index; ?>][step_type]"><?php foreach (Marketing::JOURNEY_STEP_TYPES as $type): ?><option value="<?php echo htmlspecialchars($type); ?>" <?php echo $selected($stepType, $type); ?>><?php echo htmlspecialchars($labelize($type)); ?></option><?php endforeach; ?></select></div>
                                                <div class="form-group"><label>Content</label><select name="steps[<?php echo (int) $index; ?>][content_item_id]"><option value="">No content</option><?php foreach ($contentItems as $contentItem): ?><option value="<?php echo (int) $contentItem['id']; ?>" <?php echo $selected($step['content_item_id'] ?? '', $contentItem['id']); ?>><?php echo htmlspecialchars((string) $contentItem['title']); ?></option><?php endforeach; ?></select></div>
                                                <div class="form-group"><label>Email Run</label><select name="steps[<?php echo (int) $index; ?>][email_run_id]"><option value="">No email run</option><?php foreach ($emailRuns as $emailRun): ?><option value="<?php echo (int) $emailRun['id']; ?>" <?php echo $selected($step['email_run_id'] ?? '', $emailRun['id']); ?>><?php echo htmlspecialchars((string) $emailRun['name']); ?></option><?php endforeach; ?></select></div>
                                                <div class="form-group"><label>Task</label><select name="steps[<?php echo (int) $index; ?>][task_id]"><option value="">No task</option><?php foreach ((array) ($options['tasks'] ?? []) as $task): ?><option value="<?php echo (int) $task['id']; ?>" <?php echo $selected($step['task_id'] ?? '', $task['id']); ?>><?php echo htmlspecialchars((string) $task['title']); ?></option><?php endforeach; ?></select></div>
                                                <div class="form-group marketing-journey-step-wide"><label>Instructions</label><textarea name="steps[<?php echo (int) $index; ?>][instructions]" rows="3"><?php echo htmlspecialchars((string) ($step['instructions'] ?? '')); ?></textarea></div>
                                                <div class="form-group"><label>Condition JSON</label><textarea name="steps[<?php echo (int) $index; ?>][condition_json]" rows="3" placeholder='{"field":"opened","operator":"equals","value":"yes"}'><?php echo htmlspecialchars(is_array($step['condition_json'] ?? null) ? json_encode($step['condition_json'], JSON_UNESCAPED_SLASHES) : (string) ($step['condition_json'] ?? '')); ?></textarea></div>
                                                <div class="form-group"><label>Branch JSON</label><textarea name="steps[<?php echo (int) $index; ?>][branch_json]" rows="3" placeholder='{"yes":"sales task","no":"nurture"}'><?php echo htmlspecialchars(is_array($step['branch_json'] ?? null) ? json_encode($step['branch_json'], JSON_UNESCAPED_SLASHES) : (string) ($step['branch_json'] ?? '')); ?></textarea></div>
                                            </div>
                                        </details>
                                    </article>
                                <?php endforeach; ?>
                            </div>
                        </section>
                    </main>

                    <aside class="content-card marketing-journey-builder-today">
                        <div class="premium-section-header">
                            <div>
                                <h2>Today</h2>
                                <p>Save one clear path.</p>
                            </div>
                        </div>
                        <div class="marketing-journey-builder-next-list">
                            <a class="marketing-today-action" href="#journey-core-fields" data-tooltip="Start with name, audience, campaign, and goal."><i class="fas fa-arrow-right" aria-hidden="true"></i><span>Complete basics</span></a>
                            <a class="marketing-today-action" href="#journey-steps" data-tooltip="Add only the steps that are useful now."><i class="fas fa-arrow-right" aria-hidden="true"></i><span>Map first steps</span></a>
                            <a class="marketing-today-action" href="marketing_audience_activation.php" data-tooltip="Make sure the chosen audience is usable before launch work."><i class="fas fa-arrow-right" aria-hidden="true"></i><span>Check audience</span></a>
                        </div>
                        <div class="marketing-journey-builder-save">
                            <a class="btn-premium-secondary" href="marketing_journeys.php">Cancel</a>
                            <button class="btn-premium-primary" type="submit">Save Journey</button>
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
