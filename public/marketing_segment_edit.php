<?php
/**
 * Create/edit a marketing audience segment.
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
    header('Location: ' . getBasePath() . '/marketing_segments.php');
    exit;
}

$marketing = new Marketing();
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$segment = $id > 0 ? $marketing->getAudienceSegment($id) : null;
if ($id > 0 && !$segment) {
    http_response_code(404);
    echo 'Marketing audience segment not found.';
    exit;
}

$ruleOptions = $marketing->audienceSegmentRuleOptions();
$error = '';
$form = [
    'name' => $segment['name'] ?? '',
    'description' => $segment['description'] ?? '',
    'status' => $segment['status'] ?? 'draft',
    'source_scope' => $segment['source_scope'] ?? 'contacts',
    'rule_logic' => $segment['rule_logic'] ?? 'all',
];
$rules = (array) ($segment['rules'] ?? []);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = array_merge($form, [
        'name' => $_POST['name'] ?? '',
        'description' => $_POST['description'] ?? '',
        'status' => $_POST['status'] ?? 'draft',
        'source_scope' => $_POST['source_scope'] ?? 'contacts',
        'rule_logic' => $_POST['rule_logic'] ?? 'all',
    ]);
    $rules = array_values((array) ($_POST['rules'] ?? []));

    try {
        if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Invalid CSRF token.');
        }
        $payload = $form + [
            'rules' => $rules,
            'created_by' => (int) ($user['id'] ?? 0),
        ];
        if ($id > 0) {
            $marketing->updateAudienceSegment($id, $payload);
        } else {
            $id = $marketing->createAudienceSegment($payload);
        }
        header('Location: ' . getBasePath() . '/marketing_segment_view.php?id=' . $id . '&success=saved');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

while (count($rules) < 6) {
    $rules[] = [
        'source_type' => 'contact',
        'field_key' => '',
        'operator' => 'equals',
        'value_text' => '',
    ];
}

$labelize = static fn(string $value): string => ucwords(str_replace('_', ' ', $value));
$selected = static fn($left, $right): string => (string) $left === (string) $right ? 'selected' : '';
$activeRules = array_values(array_filter($rules, static fn(array $rule): bool => trim((string) ($rule['field_key'] ?? '')) !== ''));
$activeRuleCount = count($activeRules);
$isEditing = $id > 0;
$pageHeading = $isEditing ? 'Edit Audience Rules' : 'New Audience Rules';
$primaryAction = $isEditing ? 'Save changes' : 'Save segment';
$sourceScopeLabel = $labelize((string) ($form['source_scope'] ?? 'contacts'));
$ruleLogicLabel = (string) ($form['rule_logic'] ?? 'all') === 'all' ? 'Match all' : 'Match any';
$builderSignals = [
    [
        'label' => 'Name',
        'status' => trim((string) $form['name']) !== '' ? 'ready' : 'setup-needed',
        'icon' => 'fa-signature',
        'value' => trim((string) $form['name']) !== '' ? 'Set' : 'Needed',
        'tooltip' => 'Give this audience a plain name a founder can recognize later.',
    ],
    [
        'label' => 'Source',
        'status' => 'ready',
        'icon' => 'fa-database',
        'value' => $sourceScopeLabel,
        'tooltip' => 'The CRM area this audience mostly comes from.',
    ],
    [
        'label' => 'Rules',
        'status' => $activeRuleCount > 0 ? 'ready' : 'setup-needed',
        'icon' => 'fa-filter',
        'value' => (string) $activeRuleCount,
        'tooltip' => 'Rules decide which people belong in this audience.',
    ],
    [
        'label' => 'Logic',
        'status' => 'ready',
        'icon' => 'fa-code-branch',
        'value' => $ruleLogicLabel,
        'tooltip' => 'Match all is narrower. Match any is broader.',
    ],
];
$pageTitle = ($isEditing ? 'Edit Audience Segment' : 'New Audience Segment') . ' - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<div class="page-premium marketing-segment-edit-page">
    <div class="container">
        <div class="page-header marketing-page-header">
            <div>
                <h1><?php echo htmlspecialchars($pageHeading); ?></h1>
                <p>Turn a customer idea into reusable CRM rules.</p>
            </div>
            <div class="page-header-actions marketing-page-actions">
                <a class="btn-premium-secondary" href="<?php echo $isEditing ? 'marketing_segment_view.php?id=' . (int) $id : 'marketing_segments.php'; ?>"><i class="fas fa-table-columns"></i> Audience</a>
                <a class="btn-premium-primary" href="#segment-rules"><i class="fas fa-arrow-right"></i> <?php echo $activeRuleCount > 0 ? 'Review rules' : 'Add rules'; ?></a>
            </div>
        </div>

        <?php if ($error !== ''): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

        <section class="marketing-segment-edit-shell" aria-label="Audience Rule Builder">
            <div class="marketing-founder-summary marketing-segment-edit-summary">
                <a class="marketing-summary-tile primary" href="#segment-details" data-tooltip="Start by naming the audience and choosing the CRM source.">
                    <i class="fas fa-arrow-right"></i><span>Next</span><strong><?php echo trim((string) $form['name']) !== '' ? 'Check rules' : 'Name audience'; ?></strong>
                </a>
                <div class="marketing-summary-tile" data-tooltip="Saved segments can be used in briefs, landing pages, emails, and exports.">
                    <i class="fas fa-users-gear"></i><span>Mode</span><strong><?php echo $isEditing ? 'Edit' : 'Create'; ?></strong>
                </div>
                <div class="marketing-summary-tile" data-tooltip="Rules with a selected field count toward this audience.">
                    <i class="fas fa-filter"></i><span>Rules</span><strong><?php echo $activeRuleCount; ?></strong>
                </div>
                <div class="marketing-summary-tile" data-tooltip="Audience status controls whether this segment is ready for planning.">
                    <i class="fas fa-circle-check"></i><span>Status</span><strong><?php echo htmlspecialchars($labelize((string) $form['status'])); ?></strong>
                </div>
            </div>

            <div class="marketing-segment-builder-grid">
                <?php foreach ($builderSignals as $signal): ?>
                    <?php $signalStatus = preg_replace('/[^a-z0-9_-]/i', '', (string) ($signal['status'] ?? 'setup-needed')); ?>
                    <?php $signalAction = $signalStatus === 'ready' ? 'Review' : 'Add'; ?>
                    <a class="marketing-segment-builder-card <?php echo htmlspecialchars($signalStatus); ?>" href="#segment-details" data-tooltip="<?php echo htmlspecialchars((string) ($signal['tooltip'] ?? 'Audience builder signal.')); ?>">
                        <div class="marketing-segment-builder-visual">
                            <i class="fas <?php echo htmlspecialchars((string) ($signal['icon'] ?? 'fa-filter')); ?>"></i>
                            <span class="marketing-segment-builder-value"><?php echo htmlspecialchars((string) ($signal['value'] ?? '')); ?></span>
                        </div>
                        <div class="marketing-stage-title-row">
                            <h2><?php echo htmlspecialchars((string) ($signal['label'] ?? 'Signal')); ?></h2>
                            <span class="marketing-stage-badge <?php echo htmlspecialchars($signalStatus); ?>"><?php echo htmlspecialchars($labelize(str_replace('-', '_', $signalStatus))); ?></span>
                        </div>
                        <span class="marketing-segment-builder-action"><?php echo htmlspecialchars($signalAction); ?></span>
                    </a>
                <?php endforeach; ?>
            </div>

            <form method="POST" class="marketing-segment-edit-form">
                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                <input type="hidden" name="id" value="<?php echo (int) $id; ?>">

                <div class="marketing-founder-layout marketing-segment-edit-layout">
                    <div class="content-card marketing-segment-details" id="segment-details">
                        <div class="premium-section-header">
                            <div>
                                <h2>Segment Details</h2>
                                <p>Keep it simple enough to reuse.</p>
                            </div>
                        </div>
                        <div class="marketing-segment-detail-grid">
                            <div class="form-group"><label>Name</label><input type="text" name="name" required value="<?php echo htmlspecialchars((string) $form['name']); ?>" placeholder="Qualified inbound founders"></div>
                            <div class="form-group"><label>Status</label><select name="status"><?php foreach (Marketing::AUDIENCE_SEGMENT_STATUSES as $status): ?><option value="<?php echo htmlspecialchars($status); ?>" <?php echo $selected($form['status'], $status); ?>><?php echo htmlspecialchars($labelize($status)); ?></option><?php endforeach; ?></select></div>
                            <div class="form-group"><label>Source</label><select name="source_scope"><?php foreach (Marketing::AUDIENCE_SEGMENT_SOURCE_SCOPES as $scope): ?><option value="<?php echo htmlspecialchars($scope); ?>" <?php echo $selected($form['source_scope'], $scope); ?>><?php echo htmlspecialchars($labelize($scope)); ?></option><?php endforeach; ?></select></div>
                            <div class="form-group"><label>Logic</label><select name="rule_logic"><?php foreach (Marketing::AUDIENCE_SEGMENT_RULE_LOGIC as $logic): ?><option value="<?php echo htmlspecialchars($logic); ?>" <?php echo $selected($form['rule_logic'], $logic); ?>><?php echo htmlspecialchars($logic === 'all' ? 'Match all rules' : 'Match any rule'); ?></option><?php endforeach; ?></select></div>
                        </div>
                        <div class="form-group"><label>Description</label><textarea name="description" rows="3"><?php echo htmlspecialchars((string) $form['description']); ?></textarea></div>
                    </div>

                    <aside class="content-card marketing-segment-builder-next">
                        <div class="premium-section-header">
                            <div>
                                <h2>Today</h2>
                                <p>Build one usable audience.</p>
                            </div>
                        </div>
                        <div class="marketing-segment-next-list">
                            <a class="marketing-today-action" href="#segment-rules" data-tooltip="Use one or two rules before adding complexity.">
                                <i class="fas fa-filter"></i><strong>Set rules</strong><span><?php echo $activeRuleCount; ?> active rule<?php echo $activeRuleCount === 1 ? '' : 's'; ?>.</span>
                            </a>
                            <a class="marketing-today-action" href="marketing_personas.php" data-tooltip="Personas keep audience rules connected to a real customer.">
                                <i class="fas fa-user-tag"></i><strong>Check persona</strong><span>Make sure the audience has a human shape.</span>
                            </a>
                            <a class="marketing-today-action" href="marketing_segments.php" data-tooltip="Return to the visual audience overview.">
                                <i class="fas fa-table-columns"></i><strong>Audience board</strong><span>Review saved segments.</span>
                            </a>
                        </div>
                    </aside>
                </div>

                <div class="content-card marketing-segment-rules" id="segment-rules">
                    <div class="premium-section-header">
                        <div>
                            <h2>Rules</h2>
                            <p>Blank rows stay ignored.</p>
                        </div>
                    </div>
                    <div class="marketing-segment-rule-list">
                        <?php foreach (array_slice($rules, 0, 8) as $index => $rule): ?>
                            <?php
                            $sourceType = (string) ($rule['source_type'] ?? 'contact');
                            if (!isset($ruleOptions['fields'][$sourceType])) {
                                $sourceType = 'contact';
                            }
                            $fields = (array) ($ruleOptions['fields'][$sourceType] ?? []);
                            $operator = (string) ($rule['operator'] ?? 'equals');
                            $ruleActive = trim((string) ($rule['field_key'] ?? '')) !== '';
                            ?>
                            <div class="marketing-segment-rule-card <?php echo $ruleActive ? 'ready' : 'setup-needed'; ?>">
                                <div class="marketing-segment-rule-number"><?php echo (int) $index + 1; ?></div>
                                <div class="form-group">
                                    <label>Source</label>
                                    <select name="rules[<?php echo (int) $index; ?>][source_type]">
                                        <?php foreach ($ruleOptions['source_types'] as $source): ?><option value="<?php echo htmlspecialchars((string) $source); ?>" <?php echo $selected($sourceType, $source); ?>><?php echo htmlspecialchars($labelize((string) $source)); ?></option><?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Field</label>
                                    <select name="rules[<?php echo (int) $index; ?>][field_key]">
                                        <option value="">Ignore row</option>
                                        <?php foreach ($fields as $field): ?><option value="<?php echo htmlspecialchars((string) $field['key']); ?>" <?php echo $selected($rule['field_key'] ?? '', $field['key']); ?>><?php echo htmlspecialchars((string) $field['label']); ?></option><?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Operator</label>
                                    <select name="rules[<?php echo (int) $index; ?>][operator]">
                                        <?php foreach ($ruleOptions['operators'] as $op): ?><option value="<?php echo htmlspecialchars((string) $op); ?>" <?php echo $selected($operator, $op); ?>><?php echo htmlspecialchars($labelize((string) $op)); ?></option><?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label>Value</label>
                                    <input type="text" name="rules[<?php echo (int) $index; ?>][value_text]" value="<?php echo htmlspecialchars((string) ($rule['value_text'] ?? '')); ?>" placeholder="new, referral, 1000">
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <details class="marketing-advanced-tools marketing-segment-edit-tools">
                    <summary>More rule tools <i class="fas fa-chevron-down"></i></summary>
                    <div class="marketing-segment-edit-tools-body">
                        <div class="marketing-advanced-tools-grid">
                            <a href="marketing_segments.php" data-tooltip="Return to the visual audience board after saving."><strong>Audience Builder</strong><span>Segment overview</span></a>
                            <a href="marketing_personas.php" data-tooltip="Use personas to decide which rules matter."><strong>Customer Personas</strong><span>Who this is for</span></a>
                            <a href="marketing_briefs.php" data-tooltip="Use the saved segment in campaign planning."><strong>Campaign Briefs</strong><span>Use audience</span></a>
                        </div>
                        <div class="content-card marketing-segment-tool-card">
                            <div class="premium-section-header"><div><h2>Rule Guidance</h2><p>Use fewer rules until the audience is clear.</p></div></div>
                            <div class="marketing-segment-stat-grid">
                                <div class="marketing-segment-stat"><span>Match All</span><strong>Narrow</strong></div>
                                <div class="marketing-segment-stat"><span>Match Any</span><strong>Broad</strong></div>
                                <div class="marketing-segment-stat"><span>After Save</span><strong>Preview</strong></div>
                            </div>
                        </div>
                    </div>
                </details>

                <div class="marketing-sticky-actions">
                    <a class="btn-premium-secondary" href="marketing_segments.php">Cancel</a>
                    <button class="btn-premium-primary" type="submit"><?php echo htmlspecialchars($primaryAction); ?></button>
                </div>
            </form>
        </section>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
