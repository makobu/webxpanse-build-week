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
        if (!$canWriteMarketing) { throw new RuntimeException('You do not have permission to save marketing brand assets.'); }
        if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) { throw new RuntimeException('Invalid CSRF token.'); }
        $action = (string) ($_POST['action'] ?? 'brand_profile');
        $payload = $_POST + ['created_by' => (int) ($user['id'] ?? 0)];
        if ($action === 'template') {
            $marketing->createReusableTemplate($payload);
            header('Location: ' . getBasePath() . '/marketing_brand.php?success=template'); exit;
        }
        $id = (int) ($_POST['id'] ?? 0);
        $id > 0 ? $marketing->updateBrandProfile($id, $payload) : $marketing->createBrandProfile($payload);
        header('Location: ' . getBasePath() . '/marketing_brand.php?success=profile'); exit;
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
$profiles = $marketing->listBrandProfiles();
$templates = $marketing->listReusableTemplates(['status' => 'active'], 12, 0);
$templateSummary = $marketing->getMarketingTemplateSystemSummary();
$options = $marketing->optionData();
$labelize = static fn(string $value): string => ucwords(str_replace(['_', '-'], ' ', $value));
$templateScore = (int) ($templateSummary['score'] ?? 0);
$templateCounts = (array) ($templateSummary['counts'] ?? []);
$templateRecommendations = (array) ($templateSummary['recommendations'] ?? []);
$primaryRecommendation = $templateRecommendations[0] ?? [];
$primaryActionUrl = (string) ($primaryRecommendation['href'] ?? 'marketing_brand.php#add-brand-profile');
$primaryActionLabel = (string) ($primaryRecommendation['label'] ?? 'Add brand profile');
$defaultBrand = (array) ($templateSummary['default_brand'] ?? []);
$missingTypes = array_values(array_map('strval', (array) ($templateSummary['missing_types'] ?? [])));
$priorityTemplateTypes = ['content', 'landing_page', 'email', 'social', 'ad', 'brief'];
$templateTypeIcons = [
    'content' => 'fa-pen-nib',
    'landing_page' => 'fa-window-maximize',
    'email' => 'fa-envelope',
    'social' => 'fa-hashtag',
    'ad' => 'fa-bullhorn',
    'brief' => 'fa-clipboard-list',
];
$templateTypeTooltips = [
    'content' => 'A reusable structure for posts, articles, or campaign copy.',
    'landing_page' => 'A reusable landing-page structure for offers and proof.',
    'email' => 'A repeatable email pattern for controlled manual sends.',
    'social' => 'A reusable structure for short channel posts.',
    'ad' => 'A reusable ad-copy pattern for campaign testing.',
    'brief' => 'A reusable campaign planning structure.',
];
$countsByType = (array) ($templateSummary['counts_by_type'] ?? []);
$pageTitle = 'Marketing Brand - ' . brandProductName();
ob_start();
?>
<link rel="stylesheet" href="assets/css/premium-pages.css">
<div class="page-premium marketing-brand-page"><div class="container">
    <div class="page-header marketing-page-header"><div><h1>Brand Library</h1><p>Keep voice, proof, and reusable campaign patterns ready.</p></div><div class="page-header-actions marketing-page-actions"><a class="btn-premium-secondary" href="marketing_context.php"><i class="fas fa-layer-group"></i> Context</a><a class="btn-premium-primary" href="<?php echo htmlspecialchars($primaryActionUrl); ?>"><i class="fas fa-arrow-right"></i> <?php echo htmlspecialchars($primaryActionLabel); ?></a></div></div>
    <?php if ($error !== ''): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php if (($_GET['success'] ?? '') === 'profile'): ?><div class="alert alert-success">Brand profile saved.</div><?php endif; ?>
    <?php if (($_GET['success'] ?? '') === 'template'): ?><div class="alert alert-success">Reusable marketing template saved.</div><?php endif; ?>
    <section class="marketing-brand-shell" aria-label="Marketing brand library">
        <div class="marketing-founder-summary marketing-brand-summary">
            <a class="marketing-summary-tile primary" href="<?php echo htmlspecialchars($primaryActionUrl); ?>" data-tooltip="The next brand action that improves campaign guidance.">
                <i class="fas fa-arrow-right"></i><span>Next</span><strong><?php echo htmlspecialchars($primaryActionLabel); ?></strong>
            </a>
            <div class="marketing-summary-tile" data-tooltip="Brand and reusable template readiness.">
                <i class="fas fa-gauge-high"></i><span>Readiness</span><strong><?php echo $templateScore; ?>%</strong>
            </div>
            <div class="marketing-summary-tile" data-tooltip="Saved brand profiles available for content and campaigns.">
                <i class="fas fa-palette"></i><span>Profiles</span><strong><?php echo (int) ($templateCounts['brand_profiles'] ?? 0); ?></strong>
            </div>
            <div class="marketing-summary-tile" data-tooltip="Reusable templates available for campaign work.">
                <i class="fas fa-copy"></i><span>Templates</span><strong><?php echo (int) ($templateCounts['templates'] ?? 0); ?></strong>
            </div>
        </div>

        <div class="marketing-brand-type-grid">
            <?php foreach ($priorityTemplateTypes as $templateType): ?>
                <?php
                    $typeCount = (int) ($countsByType[$templateType] ?? 0);
                    $isMissing = in_array($templateType, $missingTypes, true);
                    $typeClass = $isMissing ? 'setup-needed' : 'ready';
                ?>
                <a class="marketing-brand-type-card <?php echo $typeClass; ?>" href="marketing_brand.php#add-template" data-tooltip="<?php echo htmlspecialchars($templateTypeTooltips[$templateType] ?? 'Reusable brand/template pattern.'); ?>" aria-label="Review <?php echo htmlspecialchars($labelize($templateType)); ?> template readiness">
                    <div class="marketing-stage-visual marketing-brand-card-visual">
                        <i class="fas <?php echo htmlspecialchars($templateTypeIcons[$templateType] ?? 'fa-copy'); ?>"></i>
                        <span class="marketing-brand-count"><?php echo $typeCount; ?></span>
                    </div>
                    <div class="marketing-stage-title-row">
                        <h2><?php echo htmlspecialchars($labelize($templateType)); ?></h2>
                        <span class="marketing-stage-badge <?php echo $typeClass; ?>"><?php echo $isMissing ? 'Setup needed' : 'Ready'; ?></span>
                    </div>
                    <span class="marketing-brand-card-action"><?php echo $isMissing ? 'Create' : 'Review'; ?></span>
                </a>
            <?php endforeach; ?>
        </div>

        <div class="marketing-founder-layout marketing-brand-layout">
            <div class="content-card marketing-brand-profiles" id="brand-profiles"><div class="premium-section-header"><div><h2>Brand Profiles</h2><p><?php echo !empty($defaultBrand['name']) ? 'Default: ' . htmlspecialchars((string) $defaultBrand['name']) : 'No default profile yet.'; ?></p></div></div>
                <?php if (empty($profiles)): ?><div class="empty-state"><p>No brand profiles yet.</p></div><?php else: ?><div class="marketing-brand-card-list"><?php foreach ($profiles as $profile): ?>
                    <article class="marketing-brand-card marketing-brand-profile-card"><strong><?php echo htmlspecialchars((string) $profile['name']); ?></strong><?php if ((int) ($profile['is_default'] ?? 0) === 1): ?> <span class="badge">Default</span><?php endif; ?><div class="marketing-meta"><span><?php echo htmlspecialchars((string) ($profile['voice'] ?? 'No voice set')); ?></span><span><?php echo htmlspecialchars((string) ($profile['tone'] ?? 'No tone set')); ?></span></div><div class="marketing-brand-body"><?php echo htmlspecialchars((string) ($profile['value_props'] ?? '')); ?></div></article>
                <?php endforeach; ?></div><?php endif; ?>
            </div>
            <aside class="content-card marketing-brand-form" id="add-brand-profile"><div class="premium-section-header"><div><h2>Add Brand Profile</h2><p>Make the voice reusable.</p></div></div>
                <?php if ($canWriteMarketing): ?><form method="POST"><input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>"><input type="hidden" name="action" value="brand_profile">
                    <div class="form-group"><label>Name</label><input type="text" name="name" required></div>
                    <div class="form-group"><label>Voice</label><input type="text" name="voice"></div>
                    <div class="form-group"><label>Tone</label><input type="text" name="tone"></div>
                    <div class="form-group"><label>Banned Words</label><textarea name="banned_words" rows="3"></textarea></div>
                    <div class="form-group"><label>Value Props</label><textarea name="value_props" rows="4"></textarea></div>
                    <div class="form-group"><label>Proof Points</label><textarea name="proof_points" rows="4"></textarea></div>
                    <div class="form-group"><label>CTA Defaults</label><textarea name="cta_defaults" rows="3"></textarea></div>
                    <label class="marketing-brand-checkbox"><input type="checkbox" name="is_default" value="1"> Default profile</label>
                    <button class="btn-premium-primary" type="submit">Save Profile</button>
                </form><?php else: ?><div class="empty-state"><p>Read-only access.</p></div><?php endif; ?>
            </aside>
        </div>

        <details class="marketing-advanced-tools marketing-brand-tools">
            <summary>More brand tools <i class="fas fa-chevron-down"></i></summary>
            <div class="marketing-brand-tools-body">
                <div class="content-card">
                    <div class="premium-section-header"><div><h2>Reusable Templates</h2><p>Structures for campaign and content drafting.</p></div><span class="template-pill"><?php echo htmlspecialchars($labelize((string) ($templateSummary['status'] ?? 'thin'))); ?></span></div>
                    <div class="template-list">
                        <?php foreach ((array) ($templateSummary['templates'] ?? []) as $template): ?>
                            <div class="marketing-cardlet">
                                <span class="template-pill"><?php echo htmlspecialchars($labelize((string) ($template['template_type'] ?? 'content'))); ?></span>
                                <strong><?php echo htmlspecialchars((string) ($template['name'] ?? 'Template')); ?></strong>
                                <div class="marketing-meta"><span><?php echo htmlspecialchars($labelize((string) ($template['channel'] ?? 'Any channel'))); ?></span><?php if (!empty($template['content_type'])): ?><span><?php echo htmlspecialchars($labelize((string) $template['content_type'])); ?></span><?php endif; ?></div>
                            </div>
                        <?php endforeach; ?>
                        <?php if (empty($templateSummary['templates'])): ?><div class="empty-state"><p>No reusable templates yet.</p></div><?php endif; ?>
                    </div>
                </div>
                <div class="content-card">
                    <div class="premium-section-header"><h2>Next template actions</h2></div>
                    <div class="template-recommendations">
                        <?php foreach ($templateRecommendations as $recommendation): ?>
                            <a class="template-recommendation" href="<?php echo htmlspecialchars((string) ($recommendation['href'] ?? 'marketing_brand.php')); ?>" data-tooltip="<?php echo htmlspecialchars($labelize((string) ($recommendation['priority'] ?? 'normal'))); ?> priority"><strong><?php echo htmlspecialchars((string) ($recommendation['label'] ?? 'Improve template readiness')); ?></strong></a>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="content-card" id="add-template">
                    <div class="premium-section-header"><div><h2>Add Reusable Template</h2><p>Create one reusable structure.</p></div></div>
                    <?php if ($canWriteMarketing): ?>
                        <form method="POST" class="marketing-brand-template-form">
                            <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                            <input type="hidden" name="action" value="template">
                            <div>
                                <div class="form-group"><label>Name</label><input name="name" required placeholder="Launch announcement social post"></div>
                                <div class="form-group"><label>Template Type</label><select name="template_type"><?php foreach (Marketing::REUSABLE_TEMPLATE_TYPES as $type): ?><option value="<?php echo htmlspecialchars($type); ?>"><?php echo htmlspecialchars($labelize($type)); ?></option><?php endforeach; ?></select></div>
                                <div class="form-group"><label>Channel</label><select name="channel"><option value="">Any channel</option><?php foreach (Marketing::CHANNELS as $channel): ?><option value="<?php echo htmlspecialchars($channel); ?>"><?php echo htmlspecialchars($labelize($channel)); ?></option><?php endforeach; ?></select></div>
                                <div class="form-group"><label>Content Type</label><select name="content_type"><option value="">Any content type</option><?php foreach (Marketing::CONTENT_TYPES as $type): ?><option value="<?php echo htmlspecialchars($type); ?>"><?php echo htmlspecialchars($labelize($type)); ?></option><?php endforeach; ?></select></div>
                                <div class="form-group"><label>Brand Profile</label><select name="brand_profile_id"><option value="">Optional</option><?php foreach ($profiles as $profile): ?><option value="<?php echo (int) $profile['id']; ?>"><?php echo htmlspecialchars((string) $profile['name']); ?></option><?php endforeach; ?></select></div>
                                <div class="form-group"><label>Persona</label><select name="persona_id"><option value="">Optional</option><?php foreach ((array) ($options['personas'] ?? []) as $persona): ?><option value="<?php echo (int) $persona['id']; ?>"><?php echo htmlspecialchars((string) $persona['name']); ?></option><?php endforeach; ?></select></div>
                            </div>
                            <div>
                                <div class="form-group"><label>Title Pattern</label><input name="title_pattern" placeholder="{campaign} launch post"></div>
                                <div class="form-group"><label>Objective</label><textarea name="objective" rows="3"></textarea></div>
                                <div class="form-group"><label>Body Template</label><textarea name="body_template" rows="5" placeholder="Hook&#10;Problem&#10;Proof&#10;CTA"></textarea></div>
                                <div class="form-group"><label>CTA Template</label><textarea name="cta_template" rows="2"></textarea></div>
                                <div class="form-group"><label>Checklist</label><textarea name="checklist" rows="3" placeholder="Brand tone checked&#10;CTA included&#10;Media attached"></textarea></div>
                                <button class="btn-premium-primary" type="submit">Save Template</button>
                            </div>
                        </form>
                    <?php else: ?>
                        <div class="empty-state"><p>Read-only access.</p></div>
                    <?php endif; ?>
                </div>
            </div>
        </details>
    </section>
</div></div>
<?php $content = ob_get_clean(); include __DIR__ . '/../views/layouts/base.php';
