<?php
/**
 * Workflow Templates Page
 */

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/constants.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Modules\WorkflowTemplates;
use CRM\Services\SmartTemplateGenerationService;
use CRM\Session;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$user = Auth::user();
if (!Authorization::can('workflows.manage', $user)) {
    header('Location: dashboard.php');
    exit;
}

$templatesModule = new WorkflowTemplates();
$smartTemplatesService = new SmartTemplateGenerationService();
$category = $_GET['category'] ?? null;
$templates = $templatesModule->getPublicTemplates($category);
$categories = $templatesModule->getCategories();
$mySmartTemplates = $templatesModule->getUserSmartTemplates((int) ($user['id'] ?? 0));
$smartTemplateStatus = $smartTemplatesService->getStatus((int) ($user['id'] ?? 0));
$activeCategoryLabel = $category ? ucwords(str_replace('_', ' ', (string) $category)) : 'All Categories';

$pageTitle = 'Workflow Templates - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<style>
  .workflow-template-page .container {
    max-width: 1460px;
  }

  .workflow-hero {
    display: grid;
    grid-template-columns: minmax(0, 1.25fr) minmax(320px, 0.75fr);
    gap: 1.5rem;
    margin-bottom: 1.5rem;
  }

  .workflow-hero-card,
  .workflow-smart-card,
  .workflow-section-card {
    background: white;
    border-radius: 22px;
    box-shadow: 0 18px 55px rgba(15, 23, 42, 0.08);
    border: 1px solid rgba(148, 163, 184, 0.18);
  }

  .workflow-hero-card {
    padding: 2rem;
    background:
      radial-gradient(circle at top left, rgba(14, 165, 233, 0.18), transparent 42%),
      radial-gradient(circle at 85% 12%, rgba(245, 158, 11, 0.16), transparent 32%),
      linear-gradient(135deg, #ffffff, #f8fbff 55%, #fffaf0);
  }

  .workflow-hero-eyebrow {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.45rem 0.8rem;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.85);
    color: #2563eb;
    font-size: 0.75rem;
    font-weight: 700;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    box-shadow: 0 10px 25px rgba(37, 99, 235, 0.08);
  }

  .workflow-hero-title {
    margin: 1rem 0 0.75rem;
    font-size: clamp(2rem, 4vw, 3rem);
    line-height: 1.02;
    letter-spacing: -0.04em;
    color: #0f172a;
  }

  .workflow-hero-copy {
    max-width: 60ch;
    color: #475569;
    font-size: 1rem;
    line-height: 1.7;
    margin: 0;
  }

  .workflow-hero-stats {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 0.85rem;
    margin-top: 1.5rem;
  }

  .workflow-stat {
    padding: 1rem 1rem 0.95rem;
    border-radius: 18px;
    background: rgba(255, 255, 255, 0.76);
    border: 1px solid rgba(148, 163, 184, 0.18);
  }

  .workflow-stat-label {
    display: block;
    color: #64748b;
    font-size: 0.75rem;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    margin-bottom: 0.45rem;
    font-weight: 700;
  }

  .workflow-stat-value {
    color: #0f172a;
    font-size: 1.65rem;
    font-weight: 700;
    letter-spacing: -0.04em;
  }

  .workflow-stat-note {
    display: block;
    margin-top: 0.3rem;
    color: #64748b;
    font-size: 0.82rem;
  }

  .workflow-smart-card {
    padding: 1.4rem;
    background:
      linear-gradient(180deg, rgba(14, 165, 233, 0.1), rgba(255,255,255,0.95) 28%),
      linear-gradient(135deg, #ffffff, #f8fbff);
    display: flex;
    flex-direction: column;
    gap: 1rem;
  }

  .workflow-smart-card h3 {
    margin: 0.2rem 0 0.4rem;
    color: #0f172a;
    font-size: 1.3rem;
    letter-spacing: -0.03em;
  }

  .workflow-smart-card p {
    margin: 0;
    color: #475569;
    line-height: 1.65;
  }

  .workflow-smart-checklist {
    display: flex;
    flex-wrap: wrap;
    gap: 0.55rem;
  }

  .workflow-smart-item {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    padding: 0.55rem 0.85rem;
    border-radius: 999px;
    font-size: 0.82rem;
    font-weight: 600;
  }

  .workflow-smart-item.ready {
    background: rgba(16, 185, 129, 0.12);
    color: #047857;
  }

  .workflow-smart-item.missing {
    background: rgba(245, 158, 11, 0.12);
    color: #b45309;
  }

  .workflow-smart-actions {
    display: flex;
    gap: 0.75rem;
    flex-wrap: wrap;
  }

  .workflow-smart-status {
    min-height: 1.25rem;
    color: #475569;
    font-size: 0.875rem;
  }

  .workflow-chip-row {
    display: flex;
    flex-wrap: wrap;
    gap: 0.65rem;
    margin-bottom: 1.4rem;
  }

  .workflow-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    padding: 0.75rem 1rem;
    border-radius: 999px;
    background: rgba(255, 255, 255, 0.85);
    border: 1px solid rgba(148, 163, 184, 0.18);
    color: #334155;
    text-decoration: none;
    font-weight: 600;
    transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
  }

  .workflow-chip:hover {
    transform: translateY(-1px);
    box-shadow: 0 10px 25px rgba(15, 23, 42, 0.08);
    color: #0f172a;
  }

  .workflow-chip.active {
    background: linear-gradient(135deg, #2563eb, #0ea5e9);
    color: white;
    border-color: transparent;
    box-shadow: 0 16px 35px rgba(37, 99, 235, 0.22);
  }

  .workflow-section-card {
    padding: 1.5rem;
    margin-bottom: 1.5rem;
  }

  .workflow-section-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    gap: 1rem;
    flex-wrap: wrap;
    margin-bottom: 1.2rem;
  }

  .workflow-section-title {
    margin: 0;
    color: #0f172a;
    font-size: 1.35rem;
    letter-spacing: -0.03em;
  }

  .workflow-section-copy {
    margin: 0.35rem 0 0;
    color: #64748b;
    line-height: 1.6;
  }

  .workflow-section-meta {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.55rem 0.85rem;
    border-radius: 999px;
    background: #eff6ff;
    color: #1d4ed8;
    font-size: 0.84rem;
    font-weight: 700;
  }

  .workflow-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 1rem;
  }

  .workflow-card {
    position: relative;
    display: flex;
    flex-direction: column;
    min-height: 260px;
    padding: 1.35rem;
    border-radius: 20px;
    border: 1px solid rgba(148, 163, 184, 0.16);
    background:
      linear-gradient(180deg, rgba(248, 250, 252, 0.95), #ffffff 55%),
      white;
    box-shadow: 0 14px 30px rgba(15, 23, 42, 0.06);
    overflow: hidden;
  }

  .workflow-card::after {
    content: '';
    position: absolute;
    inset: auto -15% -55% auto;
    width: 180px;
    height: 180px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(14, 165, 233, 0.12), transparent 68%);
    pointer-events: none;
  }

  .workflow-card.smart::after {
    background: radial-gradient(circle, rgba(37, 99, 235, 0.14), transparent 70%);
  }

  .workflow-card-top {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 0.9rem;
    margin-bottom: 0.85rem;
  }

  .workflow-card-title {
    margin: 0;
    color: #0f172a;
    font-size: 1.25rem;
    line-height: 1.3;
    letter-spacing: -0.03em;
  }

  .workflow-card-badge {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.4rem 0.7rem;
    border-radius: 999px;
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    white-space: nowrap;
  }

  .workflow-card-badge.recommended {
    background: rgba(37, 99, 235, 0.14);
    color: #1d4ed8;
  }

  .workflow-card-badge.smart {
    background: rgba(14, 165, 233, 0.14);
    color: #0369a1;
  }

  .workflow-card-copy {
    color: #475569;
    font-size: 0.95rem;
    line-height: 1.65;
    margin: 0 0 1rem;
  }

  .workflow-card-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-bottom: 1rem;
  }

  .workflow-card-pill {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.42rem 0.7rem;
    border-radius: 999px;
    background: #f8fafc;
    color: #475569;
    font-size: 0.78rem;
    font-weight: 600;
  }

  .workflow-card-footer {
    margin-top: auto;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 0.75rem;
    flex-wrap: wrap;
  }

  .workflow-card-link {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    padding: 0.8rem 1rem;
    border-radius: 14px;
    background: linear-gradient(135deg, #2563eb, #0ea5e9);
    color: white;
    text-decoration: none;
    font-weight: 700;
    box-shadow: 0 16px 32px rgba(37, 99, 235, 0.2);
  }

  .workflow-card-link:hover {
    color: white;
    transform: translateY(-1px);
  }

  .workflow-card-usage {
    color: #64748b;
    font-size: 0.82rem;
    font-weight: 600;
  }

  .workflow-empty-state {
    padding: 2rem;
    border-radius: 18px;
    background: linear-gradient(180deg, #ffffff, #f8fafc);
    border: 1px dashed rgba(148, 163, 184, 0.45);
    text-align: center;
    color: #64748b;
  }

  @media (max-width: 1100px) {
    .workflow-hero {
      grid-template-columns: 1fr;
    }
  }

  @media (max-width: 720px) {
    .workflow-template-page {
      padding: 1rem;
    }

    .workflow-hero-card,
    .workflow-smart-card,
    .workflow-section-card {
      border-radius: 18px;
    }

    .workflow-hero-card,
    .workflow-smart-card,
    .workflow-section-card {
      padding: 1.2rem;
    }

    .workflow-hero-stats {
      grid-template-columns: 1fr;
    }
  }
</style>

<div class="page-premium workflow-template-page">
    <div class="container">
        <section class="workflow-hero">
            <div class="workflow-hero-card">
                <span class="workflow-hero-eyebrow">
                    <i class="fas fa-bolt"></i>
                    Workflow Template Library
                </span>
                <h1 class="workflow-hero-title">Build faster with sharper automations.</h1>
                <p class="workflow-hero-copy">
                    Start from curated templates, layer on your personal smart pack, and move from setup to execution without rebuilding the same workflow logic every time.
                </p>

                <div class="workflow-hero-stats">
                    <div class="workflow-stat">
                        <span class="workflow-stat-label">Library Pack</span>
                        <span class="workflow-stat-value"><?php echo count($templates); ?></span>
                        <span class="workflow-stat-note"><?php echo htmlspecialchars($activeCategoryLabel); ?></span>
                    </div>
                    <div class="workflow-stat">
                        <span class="workflow-stat-label">Smart Pack</span>
                        <span class="workflow-stat-value"><?php echo count($mySmartTemplates); ?></span>
                        <span class="workflow-stat-note">Personal workflows</span>
                    </div>
                    <div class="workflow-stat">
                        <span class="workflow-stat-label">Categories</span>
                        <span class="workflow-stat-value"><?php echo count($categories); ?></span>
                        <span class="workflow-stat-note">Ready to filter</span>
                    </div>
                </div>
            </div>

            <aside class="workflow-smart-card">
                <div>
                    <span class="workflow-hero-eyebrow" style="background: rgba(255,255,255,0.72);">
                        <i class="fas fa-sparkles"></i>
                        Smart Pack
                    </span>
                    <h3>Personal workflows linked to your generated email pack</h3>
                    <?php if ($smartTemplateStatus['is_ready']): ?>
                        <?php if (!empty($smartTemplateStatus['has_active_set'])): ?>
                            <p>Your smart pack is active with <?php echo (int) ($smartTemplateStatus['active_set']['workflow_template_count'] ?? 0); ?> workflow templates and <?php echo (int) ($smartTemplateStatus['active_set']['email_template_count'] ?? 0); ?> connected email templates.</p>
                        <?php else: ?>
                            <p>Your context is ready. Generate a tailored workflow pack that already knows your strategy, positioning, and validated idea.</p>
                        <?php endif; ?>
                    <?php else: ?>
                        <p>Finish the missing context below, then generate a workflow pack tuned to your company profile, strategy, and idea validation.</p>
                    <?php endif; ?>
                </div>

                <div class="workflow-smart-checklist">
                    <?php
                    $sectionLabels = [
                        'company_profile' => 'Company Profile',
                        'strategy_profile' => 'Strategy',
                        'idea_validation' => 'Idea Validation',
                    ];
                    foreach (($smartTemplateStatus['readiness']['sections'] ?? []) as $sectionKey => $section):
                    ?>
                        <span class="workflow-smart-item <?php echo !empty($section['is_ready']) ? 'ready' : 'missing'; ?>">
                            <i class="fas <?php echo !empty($section['is_ready']) ? 'fa-check-circle' : 'fa-hourglass-half'; ?>"></i>
                            <?php echo htmlspecialchars($sectionLabels[$sectionKey] ?? ucwords(str_replace('_', ' ', (string) $sectionKey))); ?>
                        </span>
                    <?php endforeach; ?>
                </div>

                <?php if (!$smartTemplateStatus['is_ready'] && !empty($smartTemplateStatus['readiness']['missing_requirements'])): ?>
                    <div class="workflow-chip-row" style="margin: 0;">
                        <?php foreach ($smartTemplateStatus['readiness']['missing_requirements'] as $item): ?>
                            <span class="workflow-chip" style="padding:0.55rem 0.8rem; background: rgba(245, 158, 11, 0.08); border-color: rgba(245, 158, 11, 0.18);">
                                <i class="fas fa-arrow-right"></i>
                                <?php echo htmlspecialchars($item['label']); ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="workflow-smart-actions">
                    <a href="email_templates.php" class="btn-premium-secondary">
                        <i class="fas fa-envelope-open-text"></i>
                        View Email Pack
                    </a>
                    <button
                        type="button"
                        id="smart-template-generate-workflow"
                        class="btn-premium-primary"
                        <?php echo !$smartTemplateStatus['is_ready'] ? 'disabled' : ''; ?>
                    >
                        <i class="fas fa-wand-magic-sparkles"></i>
                        <?php echo !empty($smartTemplateStatus['has_active_set']) ? 'Regenerate Pack' : 'Generate Pack'; ?>
                    </button>
                </div>
                <div id="smart-template-workflow-status" class="workflow-smart-status"></div>
            </aside>
        </section>

        <div class="workflow-chip-row">
            <a href="?category=" class="workflow-chip <?php echo !$category ? 'active' : ''; ?>">
                <i class="fas fa-border-all"></i>
                All Templates
            </a>
            <?php foreach ($categories as $cat): ?>
                <a href="?category=<?php echo urlencode($cat); ?>" class="workflow-chip <?php echo $category === $cat ? 'active' : ''; ?>">
                    <i class="fas fa-circle-dot"></i>
                    <?php echo htmlspecialchars(ucfirst($cat)); ?>
                </a>
            <?php endforeach; ?>
            <?php if ($category && !in_array($category, $categories, true)): ?>
                <span class="workflow-chip active">
                    <i class="fas fa-filter"></i>
                    <?php echo htmlspecialchars($activeCategoryLabel); ?>
                </span>
            <?php endif; ?>
        </div>

        <?php if (!empty($mySmartTemplates)): ?>
            <section class="workflow-section-card">
                <div class="workflow-section-head">
                    <div>
                        <h2 class="workflow-section-title">My Smart Workflow Templates</h2>
                        <p class="workflow-section-copy">Personal templates generated from your business context and kept separate from the shared workspace library.</p>
                    </div>
                    <span class="workflow-section-meta">
                        <i class="fas fa-user-cog"></i>
                        <?php echo count($mySmartTemplates); ?> personal templates
                    </span>
                </div>

                <div class="workflow-grid">
                    <?php foreach ($mySmartTemplates as $template): ?>
                        <article class="workflow-card smart">
                            <div class="workflow-card-top">
                                <h3 class="workflow-card-title"><?php echo htmlspecialchars($template['name']); ?></h3>
                                <span class="workflow-card-badge smart">
                                    <i class="fas fa-sparkles"></i>
                                    Smart
                                </span>
                            </div>
                            <p class="workflow-card-copy"><?php echo htmlspecialchars($template['description'] ?? ''); ?></p>
                            <div class="workflow-card-meta">
                                <span class="workflow-card-pill">
                                    <i class="fas fa-layer-group"></i>
                                    <?php echo htmlspecialchars(ucfirst((string) ($template['category'] ?? 'general'))); ?>
                                </span>
                                <span class="workflow-card-pill">
                                    <i class="fas fa-link"></i>
                                    Linked email actions
                                </span>
                            </div>
                            <div class="workflow-card-footer">
                                <span class="workflow-card-usage">Personal pack</span>
                                <a href="workflow_create.php?template_id=<?php echo (int) $template['id']; ?>" class="workflow-card-link">
                                    Use Template
                                    <i class="fas fa-arrow-right"></i>
                                </a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <section id="workflow-templates-recommended" class="workflow-section-card" style="display:none;">
            <div class="workflow-section-head">
                <div>
                    <h2 class="workflow-section-title">Recommended For You</h2>
                    <p class="workflow-section-copy">A smaller shortlist chosen from the library to match your current workflow habits and focus area.</p>
                </div>
                <span class="workflow-section-meta">
                    <i class="fas fa-compass"></i>
                    AI shortlist
                </span>
            </div>
            <div id="workflow-templates-recommended-list" class="workflow-grid"></div>
        </section>

        <section class="workflow-section-card">
            <div class="workflow-section-head">
                <div>
                    <h2 class="workflow-section-title">Shared Workflow Library</h2>
                    <p class="workflow-section-copy">Reusable templates for common contact, sales, nurture, and re-engagement flows across the workspace.</p>
                </div>
                <span class="workflow-section-meta">
                    <i class="fas fa-folder-tree"></i>
                    <?php echo count($templates); ?> shown in <?php echo htmlspecialchars($activeCategoryLabel); ?>
                </span>
            </div>

            <?php if (!empty($templates)): ?>
                <div class="workflow-grid">
                    <?php foreach ($templates as $template): ?>
                        <?php
                            $recipeMetadata = json_decode((string) ($template['recipe_metadata_json'] ?? ''), true);
                            $recipeMetadata = is_array($recipeMetadata) ? $recipeMetadata : [];
                            $recipeIntent = (string) ($recipeMetadata['intent_key'] ?? '');
                            $recipeOutcome = (string) ($recipeMetadata['expected_outcome'] ?? '');
                        ?>
                        <article class="workflow-card workflow-template-card" data-template-id="<?php echo (int) $template['id']; ?>">
                            <div class="workflow-card-top">
                                <h3 class="workflow-card-title"><?php echo htmlspecialchars($template['name']); ?></h3>
                                <span class="workflow-card-badge ai-recommended-badge recommended" style="display:none;">
                                    <i class="fas fa-stars"></i>
                                    Recommended
                                </span>
                            </div>
                            <p class="workflow-card-copy"><?php echo htmlspecialchars($template['description'] ?? ''); ?></p>
                            <?php if ($recipeOutcome !== ''): ?>
                                <p class="workflow-card-copy" style="font-size:.88rem;color:#334155;"><?php echo htmlspecialchars(ucfirst($recipeOutcome)); ?></p>
                            <?php endif; ?>
                            <div class="workflow-card-meta">
                                <span class="workflow-card-pill">
                                    <i class="fas fa-layer-group"></i>
                                    <?php echo htmlspecialchars(ucfirst((string) ($template['category'] ?? 'general'))); ?>
                                </span>
                                <span class="workflow-card-pill">
                                    <i class="fas fa-repeat"></i>
                                    Ready to customize
                                </span>
                                <?php if ($recipeIntent !== ''): ?>
                                    <span class="workflow-card-pill">
                                        <i class="fas fa-bullseye"></i>
                                        <?php echo htmlspecialchars(str_replace('_', ' ', $recipeIntent)); ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <div class="workflow-card-footer">
                                <span class="workflow-card-usage">
                                    <?php if ((int) ($template['usage_count'] ?? 0) > 0): ?>
                                        Used <?php echo (int) $template['usage_count']; ?> time(s)
                                    <?php else: ?>
                                        Fresh library template
                                    <?php endif; ?>
                                </span>
                                <a href="workflow_create.php?template_id=<?php echo (int) $template['id']; ?>" class="workflow-card-link">
                                    Use Template
                                    <i class="fas fa-arrow-right"></i>
                                </a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="workflow-empty-state">
                    <p style="font-size:1.05rem; margin-bottom:0.6rem;">No templates found for <?php echo htmlspecialchars($activeCategoryLabel); ?>.</p>
                    <p style="margin:0;">Switch filters or generate your personal smart pack to create a tailored starting point.</p>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>

<script>
(function() {
    fetch('../api/workflows/recommendations.php', { credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            var recs = data.recommended_workflows || data.recommended_templates || [];
            var recIds = recs.map(function(r) {
                return (r.template && r.template.id) ? String(r.template.id) : (r.template_id ? String(r.template_id) : '');
            }).filter(Boolean);

            recIds.forEach(function(id) {
                var card = document.querySelector('.workflow-template-card[data-template-id="' + id + '"]');
                if (card) {
                    var badge = card.querySelector('.ai-recommended-badge');
                    if (badge) badge.style.display = 'inline-flex';
                }
            });

            var container = document.getElementById('workflow-templates-recommended');
            var list = document.getElementById('workflow-templates-recommended-list');
            if (recs.length > 0 && list && container) {
                list.innerHTML = recs.slice(0, 5).map(function(r) {
                    var t = r.template || {};
                    var tid = t.id || r.template_id || '';
                    var name = (r.name || t.name || '').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                    var reason = (r.reason || '').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                    var category = (r.category || t.category || 'general').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                    var match = r.template_match || {};
                    var matchLabel = match.template_name ? ('Email match: ' + match.template_name + ' (' + (match.confidence || 'unknown') + ')') : 'Intent matched';
                    matchLabel = matchLabel.replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                    return '' +
                        '<article class="workflow-card">' +
                            '<div class="workflow-card-top">' +
                                '<h3 class="workflow-card-title">' + name + '</h3>' +
                                '<span class="workflow-card-badge recommended"><i class="fas fa-stars"></i>Recommended</span>' +
                            '</div>' +
                            '<p class="workflow-card-copy">' + reason + '</p>' +
                            '<div class="workflow-card-meta">' +
                                '<span class="workflow-card-pill"><i class="fas fa-layer-group"></i>' + category.charAt(0).toUpperCase() + category.slice(1) + '</span>' +
                                '<span class="workflow-card-pill"><i class="fas fa-bullseye"></i>Suggested next</span>' +
                            '</div>' +
                            '<div class="workflow-card-footer">' +
                                '<span class="workflow-card-usage">' + matchLabel + '</span>' +
                                '<a href="workflow_create.php?template_id=' + tid + '" class="workflow-card-link">Use Template<i class="fas fa-arrow-right"></i></a>' +
                            '</div>' +
                        '</article>';
                }).join('');
                container.style.display = 'block';
            }
        })
        .catch(function() {});
})();
</script>
<script>
(function() {
    var btn = document.getElementById('smart-template-generate-workflow');
    var statusEl = document.getElementById('smart-template-workflow-status');
    if (!btn) return;

    btn.addEventListener('click', function () {
        btn.disabled = true;
        if (statusEl) statusEl.textContent = 'Generating your smart template pack...';

        fetch('../api/smart_templates/generate.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({
                csrf_token: <?php echo json_encode(CRM\Security::getCsrfToken()); ?>
            })
        })
        .then(function (response) {
            return response.json().then(function (data) {
                return { ok: response.ok, data: data };
            });
        })
        .then(function (result) {
            if (!result.ok || !result.data.success) {
                throw new Error(result.data.error || 'Failed to generate smart templates.');
            }
            if (statusEl) statusEl.textContent = 'Smart workflow templates generated. Reloading...';
            window.location.reload();
        })
        .catch(function (error) {
            btn.disabled = false;
            if (statusEl) statusEl.textContent = error.message;
        });
    });
})();
</script>
<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
