<?php
/**
 * Email Templates Library Page
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Load environment
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/constants.php';

use CRM\Database;
use CRM\Session;
use CRM\Auth;
use CRM\Services\EmailTemplates;

header('Location: email_templates.php?library_retired=1');
exit;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

// Require authentication
if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$emailTemplatesService = new EmailTemplates();
$user = Auth::user();

// Get filter parameters
$category = $_GET['category'] ?? '';
$industry = $_GET['industry'] ?? '';
$purpose = $_GET['purpose'] ?? '';
$search = $_GET['search'] ?? '';
$tags = isset($_GET['tags']) ? (is_array($_GET['tags']) ? $_GET['tags'] : explode(',', $_GET['tags'])) : [];
$sortBy = $_GET['sort_by'] ?? 'usage_count';
$sortOrder = $_GET['sort_order'] ?? 'DESC';
$viewMode = $_GET['view'] ?? 'grid';

// Build filters
$filters = [
    'is_library' => true,
    'is_active' => true
];

if ($category) $filters['category'] = $category;
if ($industry) $filters['industry'] = $industry;
if ($purpose) $filters['purpose'] = $purpose;
if ($search) $filters['search'] = $search;
if (!empty($tags)) $filters['tags'] = $tags;
$filters['sort_by'] = $sortBy;
$filters['sort_order'] = $sortOrder;

// Get templates
$templates = $emailTemplatesService->getLibraryTemplates($filters);

// Get filter options
$categories = $emailTemplatesService->getCategories();
$industries = $emailTemplatesService->getIndustries();
$purposes = $emailTemplatesService->getPurposes();
$allTags = $emailTemplatesService->getTags();

$pageTitle = 'Email Template Library - ' . brandProductName();
ob_start();
?>
<link rel="stylesheet" href="assets/css/premium-pages.css">
<link rel="stylesheet" href="assets/css/communications-ui.css">

<?php
if (!function_exists('renderEmailTemplateLibraryCard')) {
    function renderEmailTemplateLibraryCard(array $template, string $viewMode = 'grid'): void
    {
        $templateName = (string) ($template['name'] ?? 'Untitled');
        $tags = $template['tags'] ?? [];
        if (is_string($tags)) {
            $decodedTags = json_decode($tags, true);
            $tags = is_array($decodedTags) ? $decodedTags : [];
        }
        $cardClass = 'comm-template-card' . ($viewMode === 'list' ? ' comm-template-card--list' : '');
        $installNameJson = htmlspecialchars(json_encode($templateName), ENT_QUOTES, 'UTF-8');
        ?>
        <article class="<?php echo $cardClass; ?>">
            <div class="comm-template-card-body">
                <div class="comm-card-header">
                    <div>
                        <h3 class="comm-template-title"><?php echo htmlspecialchars($templateName); ?></h3>
                        <?php if (!empty($template['is_featured'])): ?>
                            <span class="comm-status-badge comm-status-badge--featured">Featured</span>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!empty($template['description'])): ?>
                    <p class="comm-template-description"><?php echo htmlspecialchars($template['description']); ?></p>
                <?php endif; ?>

                <div class="comm-template-meta">
                    <span class="comm-pill"><?php echo htmlspecialchars($template['category'] ?? 'general'); ?></span>
                    <?php if (!empty($template['industry'])): ?>
                        <span class="comm-pill"><?php echo htmlspecialchars($template['industry']); ?></span>
                    <?php endif; ?>
                    <?php if (!empty($template['purpose'])): ?>
                        <span class="comm-pill"><?php echo ucfirst(htmlspecialchars(str_replace('_', ' ', $template['purpose']))); ?></span>
                    <?php endif; ?>
                </div>

                <?php if (!empty($tags) && is_array($tags)): ?>
                    <div class="comm-tag-row">
                        <?php foreach (array_slice($tags, 0, 5) as $tag): ?>
                            <span class="comm-tag"><?php echo htmlspecialchars((string) $tag); ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div class="comm-template-stats">
                    <div><strong>Subject:</strong> <?php echo htmlspecialchars($template['subject'] ?? 'No subject'); ?></div>
                    <?php if (!empty($template['usage_count'])): ?>
                        <div><i class="fas fa-download"></i> <?php echo number_format((float) $template['usage_count']); ?> installs</div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="comm-template-actions">
                <button
                    type="button"
                    class="btn-premium-primary btn-premium-sm"
                    onclick="installTemplate(<?php echo (int) ($template['id'] ?? 0); ?>, <?php echo $installNameJson; ?>)"
                >
                    <i class="fas fa-download"></i> Install
                </button>
                <a href="email_template_view.php?slug=<?php echo htmlspecialchars($template['slug'] ?? ''); ?>" class="btn-premium-secondary btn-premium-sm">
                    <i class="fas fa-eye"></i> Preview
                </a>
            </div>
        </article>
        <?php
    }
}
?>

<div class="page-premium">
    <div class="container comm-workspace">
        <section class="comm-hero">
            <div>
                <div class="comm-kicker">Template library</div>
                <h1>Email Template Library</h1>
                <p>Browse and install professional templates into your workspace.</p>
            </div>
            <div class="comm-hero-actions">
                <a href="email_templates.php" class="btn-premium-secondary">
                    <i class="fas fa-layer-group"></i> My Templates
                </a>
            </div>
        </section>

        <section class="comm-filter-card">
            <form method="GET" action="" id="filterForm" class="comm-filter-form">
                <div class="comm-search-row">
                    <input
                        type="text"
                        name="search"
                        id="searchInput"
                        value="<?php echo htmlspecialchars($search); ?>"
                        placeholder="Search templates by name, subject, or description..."
                        class="comm-filter-input"
                    >
                    <button type="submit" class="btn-premium-primary">
                        <i class="fas fa-search"></i> Search
                    </button>
                    <?php if ($search || $category || $industry || $purpose || !empty($tags)): ?>
                        <a href="email_template_library.php" class="btn-premium-secondary">Clear</a>
                    <?php endif; ?>
                </div>

                <div class="comm-filter-grid">
                    <div class="comm-control">
                        <label for="category">Category</label>
                        <select name="category" id="category" class="comm-filter-select">
                            <option value="">All Categories</option>
                            <?php foreach ($categories as $cat): ?>
                                <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $category === $cat ? 'selected' : ''; ?>>
                                    <?php echo ucfirst(htmlspecialchars($cat)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="comm-control">
                        <label for="industry">Industry</label>
                        <select name="industry" id="industry" class="comm-filter-select">
                            <option value="">All Industries</option>
                            <?php foreach ($industries as $ind): ?>
                                <option value="<?php echo htmlspecialchars($ind); ?>" <?php echo $industry === $ind ? 'selected' : ''; ?>>
                                    <?php echo ucfirst(htmlspecialchars($ind)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="comm-control">
                        <label for="purpose">Purpose</label>
                        <select name="purpose" id="purpose" class="comm-filter-select">
                            <option value="">All Purposes</option>
                            <?php foreach ($purposes as $purp): ?>
                                <option value="<?php echo htmlspecialchars($purp); ?>" <?php echo $purpose === $purp ? 'selected' : ''; ?>>
                                    <?php echo ucfirst(htmlspecialchars(str_replace('_', ' ', $purp))); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="comm-control">
                        <label for="sort_by">Sort By</label>
                        <select name="sort_by" id="sort_by" class="comm-filter-select">
                            <option value="usage_count" <?php echo $sortBy === 'usage_count' ? 'selected' : ''; ?>>Most Popular</option>
                            <option value="name" <?php echo $sortBy === 'name' ? 'selected' : ''; ?>>Name (A-Z)</option>
                            <option value="created_at" <?php echo $sortBy === 'created_at' ? 'selected' : ''; ?>>Newest</option>
                        </select>
                    </div>
                </div>

                <?php if (!empty($allTags)): ?>
                    <div>
                        <div class="comm-field-label">Tags</div>
                        <div class="comm-tag-row">
                            <?php foreach ($allTags as $tag): ?>
                                <label class="comm-tag-filter">
                                    <input
                                        type="checkbox"
                                        name="tags[]"
                                        value="<?php echo htmlspecialchars($tag); ?>"
                                        <?php echo in_array($tag, $tags) ? 'checked' : ''; ?>
                                        onchange="document.getElementById('filterForm').submit();"
                                    >
                                    <span class="comm-tag"><?php echo htmlspecialchars($tag); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <input type="hidden" name="view" value="<?php echo htmlspecialchars($viewMode); ?>">
            </form>
        </section>

        <?php
        $featuredTemplates = array_filter($templates, function($t) {
            return !empty($t['is_featured']);
        });
        if (!empty($featuredTemplates) && empty($search) && empty($category) && empty($industry) && empty($purpose) && empty($tags)):
        ?>
            <section class="comm-panel-stack">
                <div class="comm-section-header">
                    <div>
                        <div class="comm-panel-kicker">Recommended</div>
                        <h2 class="comm-panel-title">Featured Templates</h2>
                    </div>
                </div>
                <div class="comm-template-grid">
                    <?php foreach (array_slice($featuredTemplates, 0, 6) as $template): ?>
                        <?php renderEmailTemplateLibraryCard($template, 'grid'); ?>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <section class="comm-panel-stack">
            <div class="comm-section-header">
                <div>
                    <div class="comm-panel-kicker">Browse</div>
                    <h2 class="comm-panel-title">
                        <?php echo empty($search) && empty($category) && empty($industry) && empty($purpose) && empty($tags) ? 'All Templates' : 'Search Results'; ?>
                        <span class="comm-muted">(<?php echo count($templates); ?>)</span>
                    </h2>
                </div>
                <div class="comm-view-toggle" aria-label="Template view mode">
                    <button type="button" onclick="setViewMode('grid')" class="<?php echo $viewMode === 'grid' ? 'is-active' : ''; ?>" aria-current="<?php echo $viewMode === 'grid' ? 'true' : 'false'; ?>">
                        <i class="fas fa-th"></i>
                    </button>
                    <button type="button" onclick="setViewMode('list')" class="<?php echo $viewMode === 'list' ? 'is-active' : ''; ?>" aria-current="<?php echo $viewMode === 'list' ? 'true' : 'false'; ?>">
                        <i class="fas fa-list"></i>
                    </button>
                </div>
            </div>

            <?php if (empty($templates)): ?>
                <div class="comm-empty">
                    <i class="fas fa-envelope-open-text"></i>
                    <h3>No templates found</h3>
                    <p>Try adjusting your filters or search terms.</p>
                    <a href="email_template_library.php" class="btn-premium-secondary">View All Templates</a>
                </div>
            <?php else: ?>
                <div id="templatesContainer" class="comm-template-grid <?php echo $viewMode === 'list' ? 'comm-template-grid--list' : ''; ?>">
                    <?php foreach ($templates as $template): ?>
                        <?php renderEmailTemplateLibraryCard($template, $viewMode); ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>

<div id="installModal" class="comm-modal" style="display: none;">
    <div class="comm-modal-card comm-modal-card--sm">
        <div class="comm-modal-header">
            <h2 class="comm-modal-title">Install Template</h2>
            <button type="button" onclick="closeInstallModal()" class="comm-modal-close" aria-label="Close install modal">&times;</button>
        </div>
        <form id="installForm" onsubmit="handleInstall(event)" class="comm-panel-stack">
            <input type="hidden" id="installTemplateId" name="template_id">

            <div class="comm-control-wide">
                <label for="installName">Template Name *</label>
                <input type="text" id="installName" name="name" required placeholder="My Custom Template Name">
            </div>

            <div class="comm-control-wide">
                <label for="installSlug">Slug *</label>
                <input type="text" id="installSlug" name="slug" required pattern="[a-z0-9-]+" placeholder="my-custom-template" class="comm-textarea-code">
                <p class="comm-help">Lowercase letters, numbers, and hyphens only.</p>
            </div>

            <div class="comm-control-wide">
                <label for="installCategory">Category</label>
                <select id="installCategory" name="category">
                    <option value="general">General</option>
                    <option value="sales">Sales</option>
                    <option value="marketing">Marketing</option>
                    <option value="support">Support</option>
                    <option value="follow_up">Follow Up</option>
                </select>
            </div>

            <div class="form-actions">
                <button type="button" onclick="closeInstallModal()" class="btn-premium-secondary">Cancel</button>
                <button type="submit" class="btn-premium-primary"><i class="fas fa-download"></i> Install Template</button>
            </div>
        </form>
    </div>
</div>

<script>
function setViewMode(mode) {
    const url = new URL(window.location.href);
    url.searchParams.set('view', mode);
    window.location.href = url.toString();
}

function installTemplate(templateId, templateName) {
    document.getElementById('installTemplateId').value = templateId;
    document.getElementById('installName').value = templateName;
    
    // Generate slug from name
    const slug = templateName.toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '');
    document.getElementById('installSlug').value = slug;
    
    document.getElementById('installModal').style.display = 'flex';
}

function closeInstallModal() {
    document.getElementById('installModal').style.display = 'none';
}

function handleInstall(event) {
    event.preventDefault();
    
    const formData = {
        template_id: parseInt(document.getElementById('installTemplateId').value),
        customizations: {
            name: document.getElementById('installName').value,
            slug: document.getElementById('installSlug').value,
            category: document.getElementById('installCategory').value
        }
    };
    
    fetch('../api/email_templates/library.php/install', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify(formData)
    })
    .then(response => response.json())
    .then(data => {
        if (data.error) {
            alert('Error: ' + data.error);
        } else {
            closeInstallModal();
            window.location.href = 'email_template_edit.php?id=' + data.id + '&installed=1';
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred while installing the template.');
    });
}

// Close modal on outside click
document.getElementById('installModal')?.addEventListener('click', function(e) {
    if (e.target === this) {
        closeInstallModal();
    }
});

// Auto-submit form on filter change
document.getElementById('category')?.addEventListener('change', function() {
    document.getElementById('filterForm').submit();
});
document.getElementById('industry')?.addEventListener('change', function() {
    document.getElementById('filterForm').submit();
});
document.getElementById('purpose')?.addEventListener('change', function() {
    document.getElementById('filterForm').submit();
});
document.getElementById('sort_by')?.addEventListener('change', function() {
    document.getElementById('filterForm').submit();
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
