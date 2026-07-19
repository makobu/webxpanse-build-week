<?php
/**
 * Email Template View/Preview Page
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
use CRM\Security;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

// Require authentication
if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$emailTemplatesService = new EmailTemplates();
$user = Auth::user();
$slug = $_GET['slug'] ?? '';

if (!$slug) {
    header('Location: email_templates.php');
    exit;
}

// Get template by slug if it is owned by the current user or approved in the workspace.
$template = $emailTemplatesService->getAccessibleTemplateBySlug($slug, (int) ($user['id'] ?? 0));

if (!$template) {
    header('Location: email_templates.php');
    exit;
}

// Get template stats
$stats = $emailTemplatesService->getTemplateStats($template['id']);

// Get variables
$variables = is_string($template['variables'] ?? '') ? json_decode($template['variables'], true) : ($template['variables'] ?? []);
$tags = is_string($template['tags'] ?? '') ? json_decode($template['tags'], true) : ($template['tags'] ?? []);

// Preview with sample data
$sampleData = [];
foreach ($variables as $var) {
    $var = trim($var);
    if ($var === 'first_name') $sampleData[$var] = 'John';
    elseif ($var === 'last_name') $sampleData[$var] = 'Doe';
    elseif ($var === 'email') $sampleData[$var] = 'john.doe@example.com';
    elseif ($var === 'company') $sampleData[$var] = 'Acme Corp';
    else $sampleData[$var] = 'Sample ' . ucfirst(str_replace('_', ' ', $var));
}

$preview = $emailTemplatesService->renderTemplateRecord($template, $sampleData, $slug, false);
$previewSubject = $preview['subject'];
$previewHtml = $preview['body_html'];
$previewText = !empty($preview['body_text']) ? $preview['body_text'] : null;

$pageTitle = 'Email Template: ' . htmlspecialchars($template['name']) . ' - ' . brandProductName();
ob_start();
?>
<link rel="stylesheet" href="assets/css/premium-pages.css">
<link rel="stylesheet" href="assets/css/communications-ui.css">

<div class="page-premium">
    <div class="container comm-workspace">
        <section class="comm-hero">
            <div>
                <div class="comm-kicker">Template preview</div>
                <h1><?php echo htmlspecialchars($template['name']); ?></h1>
                <p>Review the rendered email with sample variables before testing or editing it.</p>
            </div>
            <div class="comm-hero-actions">
                <a href="email_template_edit.php?id=<?php echo (int) $template['id']; ?>" class="btn-premium-primary">
                    <i class="fas fa-pen"></i> Edit Template
                </a>
                <a href="email_templates.php" class="btn-premium-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Templates
                </a>
            </div>
        </section>

        <?php if (isset($_GET['success'])): ?>
            <div class="premium-banner premium-banner-success">
                <i class="fas fa-check-circle"></i>
                Template <?php echo $_GET['success'] === 'created' ? 'created' : 'updated'; ?> successfully!
            </div>
        <?php endif; ?>

        <div class="comm-compose-layout">
            <main class="comm-main-panel">
                <section class="comm-section">
                    <div class="comm-section-header">
                        <div>
                            <div class="comm-panel-kicker">HTML rendering</div>
                            <h2>HTML Preview</h2>
                        </div>
                        <div class="comm-inline-actions">
                            <span class="comm-pill"><?php echo htmlspecialchars($template['category'] ?? 'general'); ?></span>
                            <?php if (!empty($template['is_active'])): ?>
                                <span class="comm-status-badge comm-status-badge--active">Active</span>
                            <?php else: ?>
                                <span class="comm-status-badge comm-status-badge--inactive">Inactive</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="comm-subject-card">
                        <div class="comm-subject-label">Subject</div>
                        <div class="comm-subject-value"><?php echo htmlspecialchars($previewSubject); ?></div>
                    </div>

                    <div class="comm-preview-shell">
                        <iframe srcdoc="<?php echo htmlspecialchars($previewHtml); ?>" class="comm-preview-frame comm-preview-frame--large" title="Email Preview"></iframe>
                    </div>
                </section>

                <?php if ($previewText): ?>
                    <section class="comm-section">
                        <div class="comm-section-header">
                            <div>
                                <div class="comm-panel-kicker">Plain text</div>
                                <h2>Fallback Preview</h2>
                            </div>
                        </div>
                        <div class="comm-code-block"><?php echo htmlspecialchars($previewText); ?></div>
                    </section>
                <?php endif; ?>
            </main>

            <aside class="comm-side-panel">
                <section class="comm-panel">
                    <div class="comm-panel-header">
                        <div>
                            <div class="comm-panel-kicker">Metadata</div>
                            <h2 class="comm-panel-title">Template Information</h2>
                        </div>
                    </div>
                    <div class="comm-metadata-list">
                        <div class="comm-meta-row">
                            <div class="comm-meta-label">Slug</div>
                            <div class="comm-meta-value comm-mono"><?php echo htmlspecialchars($template['slug'] ?? ''); ?></div>
                        </div>
                        <div class="comm-meta-row">
                            <div class="comm-meta-label">Category</div>
                            <div class="comm-meta-value"><?php echo htmlspecialchars($template['category'] ?? 'general'); ?></div>
                        </div>
                        <div class="comm-meta-row">
                            <div class="comm-meta-label">Status</div>
                            <div class="comm-meta-value"><?php echo !empty($template['is_active']) ? 'Active' : 'Inactive'; ?></div>
                        </div>
                        <?php if (!empty($template['description'])): ?>
                            <div class="comm-meta-row">
                                <div class="comm-meta-label">Description</div>
                                <div class="comm-muted"><?php echo htmlspecialchars($template['description']); ?></div>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($template['purpose'])): ?>
                            <div class="comm-meta-row">
                                <div class="comm-meta-label">Purpose</div>
                                <div class="comm-meta-value"><?php echo htmlspecialchars(str_replace('_', ' ', $template['purpose'])); ?></div>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($template['created_at'])): ?>
                            <div class="comm-meta-row">
                                <div class="comm-meta-label">Created At</div>
                                <div class="comm-meta-value"><?php echo date('M d, Y g:i A', strtotime($template['created_at'])); ?></div>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($tags)): ?>
                            <div class="comm-meta-row">
                                <div class="comm-meta-label">Tags</div>
                                <div class="comm-tag-row">
                                    <?php foreach ($tags as $tag): ?>
                                        <span class="comm-tag"><?php echo htmlspecialchars((string) $tag); ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>

                <section class="comm-panel">
                    <div class="comm-panel-header">
                        <div>
                            <div class="comm-panel-kicker">Variables</div>
                            <h2 class="comm-panel-title">Available Variables</h2>
                        </div>
                    </div>
                    <?php if (!empty($variables)): ?>
                        <div class="comm-panel-stack">
                            <?php foreach ($variables as $var): ?>
                                <div class="comm-variable-row">
                                    <span class="comm-variable-name">{<?php echo htmlspecialchars((string) $var); ?>}</span>
                                    <span class="comm-muted"><?php echo htmlspecialchars($sampleData[$var] ?? ''); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="comm-empty">
                            <i class="fas fa-code"></i>
                            <p>No variables defined for this template.</p>
                        </div>
                    <?php endif; ?>
                </section>

                <section class="comm-panel">
                    <div class="comm-panel-header">
                        <div>
                            <div class="comm-panel-kicker">Actions</div>
                            <h2 class="comm-panel-title">Template Actions</h2>
                        </div>
                    </div>
                    <div class="comm-panel-stack">
                        <button type="button" onclick="showTestEmailModal()" class="btn-premium-success">
                            <i class="fas fa-paper-plane"></i> Send Test Email
                        </button>
                        <a href="email_template_edit.php?id=<?php echo (int) $template['id']; ?>" class="btn-premium-primary">
                            <i class="fas fa-pen"></i> Edit Template
                        </a>
                        <a href="email_template_delete.php?id=<?php echo (int) $template['id']; ?>" onclick="return confirm('Are you sure you want to delete this template?');" class="btn-premium-danger">
                            <i class="fas fa-trash"></i> Delete Template
                        </a>
                    </div>
                </section>
            </aside>
        </div>
    </div>
</div>

<div id="test-email-modal" class="comm-modal" style="display: none;">
    <div class="comm-modal-card comm-modal-card--sm">
        <div class="comm-modal-header">
            <h2 class="comm-modal-title">Send Test Email</h2>
            <button type="button" onclick="hideTestEmailModal()" class="comm-modal-close" aria-label="Close test email modal">&times;</button>
        </div>
        <form id="test-email-form" method="POST" action="../api/email_template_test.php" class="comm-panel-stack">
            <input type="hidden" name="template_id" value="<?php echo (int) $template['id']; ?>">
            <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">

            <div class="comm-control-wide">
                <label for="test_email">Test Email Address</label>
                <input
                    type="email"
                    id="test_email"
                    name="test_email"
                    required
                    value="<?php echo htmlspecialchars(Auth::user()['email'] ?? ''); ?>"
                    placeholder="test@example.com"
                >
            </div>

            <div class="form-actions">
                <button type="button" onclick="hideTestEmailModal()" class="btn-premium-secondary">Cancel</button>
                <button type="submit" class="btn-premium-success">Send Test</button>
            </div>
        </form>
    </div>
</div>

<script>
function showTestEmailModal() {
    document.getElementById('test-email-modal').style.display = 'flex';
}

function hideTestEmailModal() {
    document.getElementById('test-email-modal').style.display = 'none';
}

document.getElementById('test-email-modal')?.addEventListener('click', function(e) {
    if (e.target === this) {
        hideTestEmailModal();
    }
});

document.getElementById('test-email-form')?.addEventListener('submit', function(e) {
    e.preventDefault();
    const formData = new FormData(this);

    fetch('../api/email_template_test.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Test email sent successfully!');
            hideTestEmailModal();
        } else {
            alert('Error: ' + (data.error || 'Failed to send test email'));
        }
    })
    .catch(error => {
        alert('Error: ' + error.message);
    });
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
