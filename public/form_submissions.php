<?php
/**
 * Form Submissions - View submissions per form
 */

require_once __DIR__ . '/../vendor/autoload.php';

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
use CRM\Authorization;
use CRM\Security;
use CRM\Modules\Forms;
use CRM\Services\MarketingMarketplaceGateService;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: ' . getBasePath() . '/login.php');
    exit;
}

$user = Auth::user() ?: [];
require_once __DIR__ . '/_marketing_marketplace_gate.php';
crm_require_marketing_marketplace_access($user, MarketingMarketplaceGateService::FEATURE_DESIGN);
if (!Authorization::can('marketing.read', $user)) {
    header('Location: ' . getBasePath() . '/dashboard.php');
    exit;
}

function formsSubmissionValue(mixed $value): string
{
    if ($value === null || $value === '') {
        return '-';
    }
    if (is_array($value)) {
        return json_encode($value) ?: '-';
    }
    return (string) $value;
}

$formsModule = new Forms();
$formId = (int) ($_GET['form_id'] ?? 0);
$formUuid = trim((string) ($_GET['form_uuid'] ?? ''));
$form = null;
if ($formUuid !== '') {
    $form = $formsModule->getByUuid($formUuid);
    $formId = (int) ($form['id'] ?? 0);
} elseif ($formId > 0) {
    $form = $formsModule->getById($formId);
    $formUuid = (string) ($form['uuid'] ?? '');
}
$basePath = getBasePath();

if (!$form) {
    header('Location: ' . $basePath . '/forms.php');
    exit;
}

$submissions = $formUuid !== ''
    ? $formsModule->getSubmissionsByUuid($formUuid, 200)
    : $formsModule->getSubmissions($formId, 200);

$keys = [];
$linkedContacts = 0;
$latestSubmittedAt = null;
foreach ($submissions as $submission) {
    if (!empty($submission['contact_id'])) {
        $linkedContacts++;
    }
    $submittedAt = !empty($submission['submitted_at']) ? strtotime((string) $submission['submitted_at']) : false;
    if ($submittedAt !== false && ($latestSubmittedAt === null || $submittedAt > $latestSubmittedAt)) {
        $latestSubmittedAt = $submittedAt;
    }
    $data = is_string($submission['form_data'] ?? '') ? json_decode((string) $submission['form_data'], true) : ($submission['form_data'] ?? []);
    foreach (array_keys($data ?? []) as $key) {
        if (!in_array($key, ['visitor_id', 'form_uuid'], true) && !in_array($key, $keys, true)) {
            $keys[] = $key;
        }
    }
}

$pageTitle = 'Submissions: ' . (string) ($form['name'] ?? 'Form') . ' - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<link rel="stylesheet" href="assets/css/forms.css">

<div class="forms-page">
    <header class="forms-hero">
        <div>
            <h1>Form Submissions</h1>
            <p>
                <a href="<?php echo $basePath; ?>/forms.php" class="forms-table-name">Forms</a>
                <span aria-hidden="true">/</span>
                <?php echo htmlspecialchars((string) ($form['name'] ?? 'Untitled form')); ?>
            </p>
        </div>
        <div class="forms-hero-actions">
            <a href="<?php echo $basePath; ?>/form_edit.php?uuid=<?php echo urlencode((string) ($form['uuid'] ?? '')); ?>" class="btn-premium-secondary">
                <i class="fas fa-pen" aria-hidden="true"></i>
                Edit Form
            </a>
            <a href="<?php echo $basePath; ?>/form.php?uuid=<?php echo urlencode((string) ($form['uuid'] ?? '')); ?>" target="_blank" class="btn-premium-secondary">
                <i class="fas fa-eye" aria-hidden="true"></i>
                Preview
            </a>
        </div>
    </header>

    <section class="forms-submission-summary">
        <div class="forms-stat-card">
            <div class="forms-stat-label">Submissions</div>
            <div class="forms-submission-value"><?php echo number_format(count($submissions)); ?></div>
            <div class="forms-stat-note">Showing latest 200</div>
        </div>
        <div class="forms-stat-card">
            <div class="forms-stat-label">Linked contacts</div>
            <div class="forms-submission-value"><?php echo number_format($linkedContacts); ?></div>
            <div class="forms-stat-note">Ready for CRM follow-up</div>
        </div>
        <div class="forms-stat-card">
            <div class="forms-stat-label">Latest submission</div>
            <div class="forms-submission-value"><?php echo $latestSubmittedAt ? date('M j, g:i A', $latestSubmittedAt) : '-'; ?></div>
            <div class="forms-stat-note"><?php echo count($keys); ?> captured field<?php echo count($keys) === 1 ? '' : 's'; ?></div>
        </div>
    </section>

    <section class="table-card">
        <div class="premium-section-header">
            <div>
                <h2>Responses</h2>
                <p>Review submission details and request AI follow-up suggestions when useful.</p>
            </div>
        </div>

        <?php if (empty($submissions)): ?>
            <div class="forms-empty">
                <h2>No submissions yet</h2>
                <p>Share the public form link or embed it on your website, then new responses will appear here.</p>
                <a href="<?php echo $basePath; ?>/form.php?uuid=<?php echo urlencode((string) ($form['uuid'] ?? '')); ?>" target="_blank" class="btn-premium-secondary">
                    <i class="fas fa-eye" aria-hidden="true"></i>
                    Preview public form
                </a>
            </div>
        <?php else: ?>
            <div class="table-card-scroll">
                <table class="premium-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Contact</th>
                            <?php foreach ($keys as $key): ?>
                                <th><?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', $key))); ?></th>
                            <?php endforeach; ?>
                            <th style="text-align: right;">AI / Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($submissions as $submission): ?>
                            <?php
                            $data = is_string($submission['form_data'] ?? '') ? json_decode((string) $submission['form_data'], true) : ($submission['form_data'] ?? []);
                            $contactName = trim((string) ($submission['first_name'] ?? '') . ' ' . (string) ($submission['last_name'] ?? ''));
                            $submittedAt = !empty($submission['submitted_at']) ? strtotime((string) $submission['submitted_at']) : false;
                            ?>
                            <tr>
                                <td><?php echo $submittedAt ? date('M j, Y g:i A', $submittedAt) : '-'; ?></td>
                                <td>
                                    <?php if (!empty($submission['contact_id'])): ?>
                                        <a href="<?php echo $basePath; ?>/contact_view.php?id=<?php echo (int) $submission['contact_id']; ?>" class="forms-table-name">
                                            <?php echo htmlspecialchars($contactName ?: (string) ($submission['email'] ?? 'Contact')); ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="premium-muted">Unlinked</span>
                                    <?php endif; ?>
                                </td>
                                <?php foreach ($keys as $key): ?>
                                    <td><?php echo htmlspecialchars(formsSubmissionValue($data[$key] ?? null)); ?></td>
                                <?php endforeach; ?>
                                <td style="text-align: right; vertical-align: top;">
                                    <div class="forms-actions" style="justify-content: flex-end;">
                                        <button type="button" class="btn-premium-secondary btn-premium-sm analyze-submission-btn" data-submission-id="<?php echo (int) $submission['id']; ?>">
                                            <i class="fas fa-magic" aria-hidden="true"></i>
                                            Analyze
                                        </button>
                                        <?php if (!empty($submission['contact_id'])): ?>
                                            <a href="<?php echo $basePath; ?>/contact_view.php?id=<?php echo (int) $submission['contact_id']; ?>" class="btn-premium-secondary btn-premium-sm">View Contact</a>
                                        <?php endif; ?>
                                        <div id="ai-result-<?php echo (int) $submission['id']; ?>" class="forms-ai-panel ai-submission-result"></div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</div>

<script>
(function() {
    var basePath = '<?php echo addslashes($basePath); ?>';
    var csrfToken = '<?php echo addslashes(Security::getCsrfToken()); ?>';
    document.querySelectorAll('.analyze-submission-btn').forEach(function(btn) {
        btn.addEventListener('click', async function() {
            var id = this.dataset.submissionId;
            var resultEl = document.getElementById('ai-result-' + id);
            if (!resultEl) return;
            if (resultEl.style.display === 'block' && resultEl.dataset.loaded === '1') {
                resultEl.style.display = 'none';
                return;
            }
            this.disabled = true;
            this.textContent = 'Analyzing...';
            try {
                var r = await fetch(basePath + '/api/form_submissions/analyze.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ submission_id: parseInt(id, 10), action: 'analyze', csrf_token: csrfToken })
                });
                var data = await r.json();
                if (data.success) {
                    var html = '';
                    if (data.tags && data.tags.length) {
                        html += '<strong>Tags:</strong> ' + data.tags.join(', ') + '<br>';
                    }
                    if (data.suggested_stage) html += '<strong>Stage:</strong> ' + data.suggested_stage + '<br>';
                    if (data.follow_up_suggestion) html += '<strong>Follow-up:</strong> ' + data.follow_up_suggestion + '<br>';
                    if (html) {
                        html += '<button type="button" class="btn-premium-primary btn-premium-sm apply-tags-btn" data-submission-id="' + id + '" style="margin-top: 0.5rem;">Apply tags to contact</button>';
                    } else {
                        html = '<em>No suggestions.</em>';
                    }
                    resultEl.innerHTML = html;
                    resultEl.dataset.loaded = '1';
                    resultEl.style.display = 'block';
                    resultEl.querySelector('.apply-tags-btn')?.addEventListener('click', async function() {
                        var applyBtn = this;
                        applyBtn.disabled = true;
                        try {
                            var r2 = await fetch(basePath + '/api/form_submissions/analyze.php', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify({ submission_id: parseInt(id, 10), action: 'apply', csrf_token: csrfToken })
                            });
                            var d = await r2.json();
                            if (d.success) alert('Tags applied.');
                            else alert('Error: ' + (d.error || 'Failed'));
                        } catch (e) { alert('Error: ' + e.message); }
                        applyBtn.disabled = false;
                    });
                } else {
                    resultEl.innerHTML = '<em>Error: ' + (data.error || 'Failed') + '</em>';
                    resultEl.style.display = 'block';
                }
            } catch (e) {
                resultEl.innerHTML = '<em>Error: ' + e.message + '</em>';
                resultEl.style.display = 'block';
            }
            this.disabled = false;
            this.innerHTML = '<i class="fas fa-magic" aria-hidden="true"></i> Analyze';
        });
    });
})();
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
