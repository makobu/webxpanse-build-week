<?php
/**
 * Email Templates List Page
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
use CRM\Services\MarketplacePageExplainerService;
use CRM\Services\PageGuideVideoUi;
use CRM\Services\SmartTemplateGenerationService;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

// Require authentication
if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$emailTemplatesService = new EmailTemplates();
$smartTemplatesService = new SmartTemplateGenerationService();
$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);
$smartTemplateStatus = $smartTemplatesService->getStatus($userId);

// Get filter parameters
$category = $_GET['category'] ?? '';
$search = $_GET['search'] ?? '';
$showLibrary = false;
$libraryRetired = isset($_GET['library_retired']) && $_GET['library_retired'] === '1';

// Get templates
$templates = $emailTemplatesService->getUserTemplates($userId);

// Apply category filter
if ($category) {
    $templates = array_filter($templates, function($template) use ($category) {
        return ($template['category'] ?? 'general') === $category;
    });
}

// Apply search filter
if ($search) {
    $searchLower = strtolower($search);
    $templates = array_filter($templates, function($template) use ($searchLower) {
        return strpos(strtolower($template['name'] ?? ''), $searchLower) !== false ||
               strpos(strtolower($template['subject'] ?? ''), $searchLower) !== false ||
               strpos(strtolower($template['slug'] ?? ''), $searchLower) !== false;
    });
}

// Get unique categories (from all templates for filter dropdown)
$allTemplates = $emailTemplatesService->getUserTemplates($userId);
$categories = array_unique(array_column($allTemplates, 'category'));
$categories = array_filter($categories);
sort($categories);

$pageTitle = 'Email Templates - ' . brandProductName();
$emailTemplatesGuideVideoUrl = PageGuideVideoUi::activeVideoUrl(MarketplacePageExplainerService::PAGE_EMAIL_TEMPLATES);
$learningState = (string) ($smartTemplateStatus['learning_state'] ?? 'learning');
$learningMetrics = (array) ($smartTemplateStatus['learning_readiness']['metrics'] ?? []);
$learningThresholds = (array) ($smartTemplateStatus['learning_readiness']['thresholds'] ?? []);
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<?php echo PageGuideVideoUi::assets(); ?>

<div class="page-premium">
    <div class="container">
        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h1>Email Templates</h1>
                <p>Manage your email templates</p>
            </div>
            <div class="page-header-actions">
                <?php if ($emailTemplatesGuideVideoUrl !== ''): ?>
                    <?php echo PageGuideVideoUi::button(MarketplacePageExplainerService::PAGE_EMAIL_TEMPLATES, 'Email Templates page guide', 'btn-premium-secondary'); ?>
                <?php endif; ?>
                <a href="email_template_create.php" class="btn-premium-primary">
                    <i class="fas fa-plus"></i>
                    New Template
                </a>
            </div>
        </div>

        <!-- Tabs -->
        <div class="filters-card">
            <?php if ($libraryRetired): ?>
                <div class="premium-banner premium-banner-info" style="margin-bottom: 1rem;">
                    Generic template libraries have been retired. Use AI drafting until learned templates are ready.
                </div>
            <?php endif; ?>
            <?php if (!$showLibrary): ?>
                <div class="content-card" style="margin-bottom: 1rem; border: 1px solid rgba(102, 126, 234, 0.2); background: linear-gradient(135deg, rgba(102,126,234,0.08), rgba(99,102,241,0.04));">
                    <div style="display: flex; justify-content: space-between; gap: 1rem; align-items: flex-start; flex-wrap: wrap;">
                        <div style="max-width: 760px;">
                            <div style="font-size: 0.75rem; letter-spacing: 0.08em; text-transform: uppercase; color: #667eea; font-weight: 700; margin-bottom: 0.5rem;">Learned Templates</div>
                            <h3 style="margin: 0 0 0.5rem 0; color: #0f172a;">AI templates are generated only after enough real email learning evidence exists</h3>
                            <?php if (!empty($smartTemplateStatus['has_candidate_set'])): ?>
                                <p style="margin: 0; color: #475569;">
                                    A learned template update is ready for review with <?php echo (int) ($smartTemplateStatus['candidate_set']['email_template_count'] ?? 0); ?> email templates.
                                </p>
                            <?php elseif ($learningState === 'active' || $learningState === 'refresh_due'): ?>
                                <?php if (!empty($smartTemplateStatus['has_active_set'])): ?>
                                    <p style="margin: 0; color: #475569;">
                                        Your approved learned pack is active with <?php echo (int) ($smartTemplateStatus['active_set']['email_template_count'] ?? 0); ?> email templates.
                                        <?php if ($learningState === 'refresh_due'): ?>Enough new learning evidence exists to prepare a review candidate.<?php endif; ?>
                                    </p>
                                <?php else: ?>
                                    <p style="margin: 0; color: #475569;">
                                        Learning is ready. Create the first learned template candidate for review.
                                    </p>
                                <?php endif; ?>
                            <?php elseif ($smartTemplateStatus['is_ready']): ?>
                                <p style="margin: 0; color: #475569;">
                                    Learning is ready. Create the first learned template candidate for review.
                                </p>
                            <?php else: ?>
                                <p style="margin: 0; color: #475569;">
                                    Before templates can be generated, keep using AI drafting and sending real emails. Remaining:
                                    <?php echo htmlspecialchars(implode(', ', array_map(static fn(array $item): string => $item['label'], $smartTemplateStatus['readiness']['missing_requirements'] ?? []))); ?>.
                                </p>
                            <?php endif; ?>
                            <p style="margin: 0.75rem 0 0; color: #64748b; font-size: 0.875rem;">
                                Sent <?php echo (int) ($learningMetrics['sent_email_count'] ?? 0); ?>/<?php echo (int) ($learningThresholds['min_sent_emails'] ?? 25); ?>,
                                AI drafts <?php echo (int) ($learningMetrics['ai_draft_count'] ?? 0); ?>/<?php echo (int) ($learningThresholds['min_ai_drafts'] ?? 10); ?>,
                                positive signals <?php echo (int) ($learningMetrics['positive_engagement_count'] ?? 0); ?>/<?php echo (int) ($learningThresholds['min_positive_engagements'] ?? 3); ?>.
                            </p>
                        </div>
                        <div style="display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: center;">
                            <?php if (!empty($smartTemplateStatus['has_candidate_set'])): ?>
                                <button type="button" id="smart-template-approve-candidate" class="btn-premium-primary" data-set-id="<?php echo (int) ($smartTemplateStatus['candidate_set']['id'] ?? 0); ?>">Approve Candidate</button>
                                <button type="button" id="smart-template-reject-candidate" class="btn-premium-secondary" data-set-id="<?php echo (int) ($smartTemplateStatus['candidate_set']['id'] ?? 0); ?>">Reject</button>
                            <?php else: ?>
                                <button
                                    type="button"
                                    id="smart-template-generate-email"
                                    class="btn-premium-primary"
                                    <?php echo empty($smartTemplateStatus['can_generate']) && empty($smartTemplateStatus['refresh_due']) ? 'disabled' : ''; ?>
                                >
                                    Create Review Candidate
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div id="smart-template-email-status" style="margin-top: 0.75rem; color: #475569; font-size: 0.875rem;"></div>
                </div>
            <?php endif; ?>

            <!-- Filters -->
            <form method="GET" action="" class="filters-form">
                <div class="filter-group">
                    <label for="search">Search</label>
                    <input 
                        type="text" 
                        id="search" 
                        name="search" 
                        value="<?php echo htmlspecialchars($search); ?>"
                        placeholder="Search templates..."
                    >
                </div>
                
                <div class="filter-group">
                    <label for="category">Category</label>
                    <select id="category" name="category">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $cat): ?>
                            <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $category === $cat ? 'selected' : ''; ?>>
                                <?php echo ucfirst(htmlspecialchars($cat)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-actions">
                    <button type="submit" class="btn-premium-primary">
                        <i class="fas fa-filter"></i>
                        Filter
                    </button>
                    <?php if ($search || $category): ?>
                        <a href="email_templates.php" class="btn-premium-secondary">
                            Clear
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Templates Grid -->
        <?php if (empty($templates)): ?>
            <div class="empty-state">
                <div style="font-size: 3rem; margin-bottom: 1rem;"><i class="fas fa-envelope-open-text"></i></div>
                <p style="font-size: 1.125rem; margin-bottom: 0.5rem;">No templates found</p>
                <a href="email_template_create.php">
                    Create your first template
                </a>
            </div>
        <?php else: ?>
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1rem;">
                <?php foreach ($templates as $template): ?>
                    <div class="content-card" style="display: flex; flex-direction: column;">
                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 1rem;">
                            <div style="flex: 1;">
                                <h3 style="color: #0f172a; font-size: 1.125rem; margin: 0 0 0.5rem 0; font-weight: 600;">
                                    <?php echo htmlspecialchars($template['name'] ?? 'Untitled'); ?>
                                </h3>
                                <div style="display: flex; gap: 0.5rem; align-items: center; margin-bottom: 0.5rem;">
                                    <span class="badge badge-default">
                                        <?php echo htmlspecialchars(ucfirst($template['category'] ?? 'general')); ?>
                                    </span>
                                    <?php if (!empty($template['is_active'])): ?>
                                        <span class="badge" style="background: #10b98120; color: #10b981;">
                                            Active
                                        </span>
                                    <?php else: ?>
                                        <span class="badge" style="background: #6b728020; color: #6b7280;">
                                            Inactive
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <div style="margin-bottom: 1rem; flex: 1;">
                            <div style="color: #64748b; font-size: 0.75rem; margin-bottom: 0.25rem; font-weight: 500;">Subject</div>
                            <div style="color: #0f172a; font-size: 0.875rem; line-height: 1.4;">
                                <?php echo htmlspecialchars($template['subject'] ?? 'No subject'); ?>
                            </div>
                        </div>
                        
                        <?php if (!empty($template['variables'])): 
                            $variables = is_string($template['variables']) ? json_decode($template['variables'], true) : $template['variables'];
                        ?>
                            <div style="margin-bottom: 1rem;">
                                <div style="color: #64748b; font-size: 0.75rem; margin-bottom: 0.25rem; font-weight: 500;">Variables</div>
                                <div style="display: flex; flex-wrap: wrap; gap: 0.25rem;">
                                    <?php foreach ($variables as $var): ?>
                                        <span class="badge badge-default" style="font-family: monospace; font-size: 0.6875rem;">
                                            {<?php echo htmlspecialchars($var); ?>}
                                        </span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        
                        <div style="display: flex; gap: 0.75rem; margin-top: auto; padding-top: 1rem; border-top: 1px solid rgba(0, 0, 0, 0.1);">
                            <a href="email_template_view.php?slug=<?php echo htmlspecialchars($template['slug'] ?? ''); ?>" style="color: #667eea; text-decoration: none; font-size: 0.875rem; font-weight: 500;">
                                Preview
                            </a>
                            <a href="email_template_edit.php?id=<?php echo $template['id']; ?>" style="color: #64748b; text-decoration: none; font-size: 0.875rem;">
                                Edit
                            </a>
                            <a href="email_template_delete.php?id=<?php echo $template['id']; ?>" onclick="return confirm('Are you sure you want to delete this template?');" style="color: #ef4444; text-decoration: none; font-size: 0.875rem;">
                                Delete
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php echo PageGuideVideoUi::modal(MarketplacePageExplainerService::PAGE_EMAIL_TEMPLATES, 'How to use Email Templates', $emailTemplatesGuideVideoUrl); ?>

<?php if (!$showLibrary): ?>
<script>
(function() {
    var btn = document.getElementById('smart-template-generate-email');
    var approveBtn = document.getElementById('smart-template-approve-candidate');
    var rejectBtn = document.getElementById('smart-template-reject-candidate');
    var statusEl = document.getElementById('smart-template-email-status');
    var csrfToken = <?php echo json_encode(CRM\Security::getCsrfToken()); ?>;

    if (btn) {
        btn.addEventListener('click', function () {
        btn.disabled = true;
        if (statusEl) statusEl.textContent = 'Generating learned templates from email evidence...';

        fetch('../api/smart_templates/generate.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({
                csrf_token: csrfToken
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
            if (statusEl) statusEl.textContent = 'Candidate pack generated. Reloading for review...';
            window.location.reload();
        })
        .catch(function (error) {
            btn.disabled = false;
            if (statusEl) statusEl.textContent = error.message;
        });
    });

    }

    function reviewCandidate(action, setId, clickedBtn) {
        if (!setId) return;
        if (clickedBtn) clickedBtn.disabled = true;
        if (statusEl) statusEl.textContent = action === 'approve'
            ? 'Approving learned template candidate...'
            : 'Rejecting learned template candidate...';

        fetch('../api/smart_templates/review.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({
                csrf_token: csrfToken,
                action: action,
                smart_template_set_id: setId
            })
        })
        .then(function (response) {
            return response.json().then(function (data) {
                return { ok: response.ok, data: data };
            });
        })
        .then(function (result) {
            if (!result.ok || !result.data.success) {
                throw new Error(result.data.error || 'Failed to review candidate.');
            }
            if (statusEl) statusEl.textContent = 'Candidate reviewed. Reloading...';
            window.location.reload();
        })
        .catch(function (error) {
            if (clickedBtn) clickedBtn.disabled = false;
            if (statusEl) statusEl.textContent = error.message;
        });
    }

    if (approveBtn) {
        approveBtn.addEventListener('click', function () {
            reviewCandidate('approve', parseInt(approveBtn.getAttribute('data-set-id') || '0', 10), approveBtn);
        });
    }
    if (rejectBtn) {
        rejectBtn.addEventListener('click', function () {
            reviewCandidate('reject', parseInt(rejectBtn.getAttribute('data-set-id') || '0', 10), rejectBtn);
        });
    }
})();
</script>
<?php endif; ?>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
