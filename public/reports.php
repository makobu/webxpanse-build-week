<?php
/**
 * Reports List Page
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
use CRM\Modules\Reports;
use CRM\Services\MarketplacePageExplainerService;
use CRM\Services\PageGuideVideoUi;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$reportsModule = new Reports();
$user = Auth::user();
$userId = (int) ($user['id'] ?? 0);
Session::closeWrite();
$successKey = trim((string) ($_GET['success'] ?? ''));
$errorKey = trim((string) ($_GET['error'] ?? ''));

$reports = $reportsModule->getAll($userId, true);
$templates = $reportsModule->getTemplates();

$pageTitle = 'Reports - ' . brandProductName();
$reportsGuideVideoUrl = PageGuideVideoUi::activeVideoUrl(MarketplacePageExplainerService::PAGE_REPORTS);
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<?php echo PageGuideVideoUi::assets(); ?>

<div class="page-premium">
    <div class="container">
        <div class="page-header">
            <div>
                <h1>Reports</h1>
                <p>Create and manage custom reports</p>
            </div>
            <div class="page-header-actions">
                <?php if ($reportsGuideVideoUrl !== ''): ?>
                    <?php echo PageGuideVideoUi::button(MarketplacePageExplainerService::PAGE_REPORTS, 'Reports page guide', 'btn-premium-secondary'); ?>
                <?php endif; ?>
                <a href="report_create.php" class="btn-premium-primary">
                    <i class="fas fa-plus"></i>
                    New Report
                </a>
            </div>
        </div>

        <?php if ($successKey !== ''): ?>
            <div class="report-banner report-banner-success">
                <?php
                $successMessages = [
                    'deleted' => 'Report deleted successfully.',
                ];
                echo htmlspecialchars($successMessages[$successKey] ?? $successKey);
                ?>
            </div>
        <?php endif; ?>

        <?php if ($errorKey !== ''): ?>
            <div class="report-banner report-banner-error">
                <?php
                $errorMessages = [
                    'invalid_token' => 'Your security token was invalid. Please try again.',
                    'access_denied' => 'You do not have permission to access that report.',
                ];
                echo htmlspecialchars($errorMessages[$errorKey] ?? $errorKey);
                ?>
            </div>
        <?php endif; ?>

        <div class="content-card">
            <h2 style="color: #0f172a; font-size: 1.25rem; margin: 0 0 1rem 0; font-weight: 600;">Ask a Question</h2>
            <p style="color: #64748b; font-size: 0.875rem; margin-bottom: 1rem;">Ask questions in plain English, for example "Top 10 leads this month" or "Deals stuck in negotiation."</p>
            <div style="display: flex; gap: 0.75rem; margin-bottom: 1rem; flex-wrap: wrap;">
                <label for="nl-report-question" class="sr-only">Question for report assistant</label>
                <input type="text" id="nl-report-question" aria-describedby="nl-report-status" placeholder="How many deals did we close this month?" style="flex: 1; min-width: 200px; padding: 0.5rem 0.75rem; border: 1px solid rgba(0,0,0,0.15); border-radius: 6px; font-size: 0.9375rem;">
                <button type="button" id="nl-report-btn" class="btn-premium-primary" style="white-space: nowrap;">
                    <i class="fas fa-search"></i>
                    Ask
                </button>
            </div>
            <div id="nl-report-status" role="status" aria-live="polite" aria-atomic="true" style="display: none; margin-bottom: 1rem; font-size: 0.875rem;"></div>
            <div id="nl-report-result" style="display: none; padding: 1rem; background: #f0f7ff; border-radius: 6px; border: 1px solid rgba(102, 126, 234, 0.3); font-size: 0.9375rem; line-height: 1.5; white-space: pre-wrap;"></div>
            <div id="nl-report-loading" style="display: none; padding: 1rem; color: #64748b;"><i class="fas fa-spinner fa-spin"></i> Generating answer...</div>
        </div>

        <div class="content-card">
            <h2 style="color: #0f172a; font-size: 1.25rem; margin: 0 0 1rem 0; font-weight: 600;">Report Templates</h2>
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 1rem;">
                <?php foreach ($templates as $template): ?>
                    <div style="padding: 1rem; border: 1px solid rgba(0, 0, 0, 0.1); border-radius: var(--border-radius-sm); background: #f8f9fa;">
                        <div style="font-weight: 600; color: #0f172a; margin-bottom: 0.5rem;">
                            <?php echo htmlspecialchars($template['name']); ?>
                        </div>
                        <div style="color: #64748b; font-size: 0.875rem; margin-bottom: 0.75rem;">
                            <?php echo htmlspecialchars($template['description']); ?>
                        </div>
                        <div style="display: flex; gap: 0.75rem;">
                            <span class="badge badge-primary" style="font-size: 0.6875rem;">
                                <?php echo htmlspecialchars(ucfirst($template['report_type'])); ?>
                            </span>
                        </div>
                        <div style="margin-top: 0.75rem;">
                            <a href="report_create.php?template=<?php echo urlencode($template['name']); ?>" class="btn-premium-secondary btn-premium-sm">
                                Use Template
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="table-card">
            <div style="padding: 1.5rem; border-bottom: 1px solid rgba(0, 0, 0, 0.1); background: #f8f9fa;">
                <h2 style="color: #0f172a; font-size: 1.25rem; margin: 0; font-weight: 600;">Saved Reports</h2>
            </div>

            <?php if (empty($reports)): ?>
                <div class="empty-state">
                    <p>No reports found.</p>
                    <a href="report_create.php">Create your first report</a>
                </div>
            <?php else: ?>
                <div style="display: flex; flex-direction: column;">
                    <?php foreach ($reports as $report): ?>
                        <div style="padding: 1.5rem; border-bottom: 1px solid rgba(0, 0, 0, 0.1); display: flex; justify-content: space-between; align-items: start; gap: 1rem; transition: background 0.2s ease;">
                            <div style="flex: 1;">
                                <div style="display: flex; align-items: center; gap: 1rem; margin-bottom: 0.75rem; flex-wrap: wrap;">
                                    <h3 style="color: #0f172a; font-size: 1.125rem; margin: 0;">
                                        <a href="report_view.php?id=<?php echo (int) $report['id']; ?>" style="color: #0f172a; text-decoration: none;">
                                            <?php echo htmlspecialchars($report['name']); ?>
                                        </a>
                                    </h3>
                                    <?php if ((int) ($report['is_public'] ?? 0) === 1): ?>
                                        <span class="badge badge-success">Public</span>
                                    <?php endif; ?>
                                </div>

                                <?php if (!empty($report['description'])): ?>
                                    <div style="color: #64748b; font-size: 0.875rem; margin-bottom: 0.75rem;">
                                        <?php echo htmlspecialchars($report['description']); ?>
                                    </div>
                                <?php endif; ?>

                                <div style="display: flex; gap: 1.5rem; flex-wrap: wrap; color: #64748b; font-size: 0.875rem;">
                                    <div>
                                        <span style="font-weight: 500;">Type:</span>
                                        <span style="text-transform: capitalize;"><?php echo htmlspecialchars((string) ($report['report_type'] ?? '')); ?></span>
                                    </div>
                                    <div>
                                        <span style="font-weight: 500;">Created by:</span>
                                        <?php echo htmlspecialchars((string) ($report['created_by_email'] ?? 'Unknown')); ?>
                                    </div>
                                    <div>
                                        <span style="font-weight: 500;">Executions:</span>
                                        <?php echo number_format((int) ($report['execution_count'] ?? 0)); ?>
                                    </div>
                                    <div>
                                        <span style="font-weight: 500;">Created:</span>
                                        <?php echo date('M d, Y', strtotime((string) $report['created_at'])); ?>
                                    </div>
                                </div>
                            </div>

                            <div class="report-action-group">
                                <a href="report_view.php?id=<?php echo (int) $report['id']; ?>" class="btn-premium-primary btn-premium-sm">View</a>
                                <?php if ($reportsModule->canUserEdit($report, $userId)): ?>
                                    <a href="report_edit.php?id=<?php echo (int) $report['id']; ?>" class="btn-premium-secondary btn-premium-sm">Edit</a>
                                    <a href="report_delete.php?id=<?php echo (int) $report['id']; ?>" class="btn-premium-danger btn-premium-sm">Delete</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
(function() {
    var btn = document.getElementById('nl-report-btn');
    var input = document.getElementById('nl-report-question');
    var result = document.getElementById('nl-report-result');
    var loading = document.getElementById('nl-report-loading');
    var status = document.getElementById('nl-report-status');
    if (!btn || !input || !result || !loading || !status) return;

    function setStatus(message, type) {
        status.style.display = message ? 'block' : 'none';
        status.textContent = message || '';
        status.style.color = type === 'error' ? '#b91c1c' : '#475569';
    }

    function resetResultStyles() {
        result.style.background = '#f0f7ff';
        result.style.borderColor = 'rgba(102, 126, 234, 0.3)';
        input.style.borderColor = 'rgba(0,0,0,0.15)';
    }

    function runQuestion() {
        var q = (input.value || '').trim();
        resetResultStyles();
        result.style.display = 'none';

        if (!q) {
            setStatus('Enter a reporting question before asking.', 'error');
            input.style.borderColor = '#dc2626';
            input.focus();
            return;
        }

        setStatus('Preparing your report answer...', 'info');
        loading.style.display = 'block';
        btn.disabled = true;
        input.disabled = true;

        fetch('../api/reports/nl.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ question: q })
        }).then(function(r) {
            return r.json();
        }).then(function(data) {
            loading.style.display = 'none';
            btn.disabled = false;
            input.disabled = false;

            if (data.success && data.answer) {
                result.textContent = data.answer;
                result.style.display = 'block';
                setStatus('Answer ready.', 'info');
                return;
            }

            result.textContent = data.error || 'Failed to generate answer.';
            result.style.display = 'block';
            result.style.background = '#fef2f2';
            result.style.borderColor = 'rgba(239,68,68,0.3)';
            setStatus('The report question could not be answered.', 'error');
        }).catch(function() {
            loading.style.display = 'none';
            btn.disabled = false;
            input.disabled = false;
            result.textContent = 'Request failed. Please try again.';
            result.style.display = 'block';
            result.style.background = '#fef2f2';
            result.style.borderColor = 'rgba(239,68,68,0.3)';
            setStatus('The request failed. Please try again.', 'error');
        });
    }

    btn.addEventListener('click', runQuestion);
    input.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            runQuestion();
        }
    });
})();
</script>

<?php echo PageGuideVideoUi::modal(MarketplacePageExplainerService::PAGE_REPORTS, 'How to use Reports', $reportsGuideVideoUrl); ?>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
