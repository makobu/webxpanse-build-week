<?php
/**
 * Campaign View Page
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
use CRM\Database;
use CRM\Session;
use CRM\Services\CampaignOrchestrator;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: ' . getBasePath() . '/login.php');
    exit;
}

$campaignId = (int) ($_GET['id'] ?? 0);
$orchestrator = new CampaignOrchestrator();
$campaign = $campaignId > 0 ? $orchestrator->getCampaignById($campaignId) : null;
if (!$campaign) {
    header('Location: ' . getBasePath() . '/campaigns.php');
    exit;
}

$executions = Database::query(
    "SELECT cse.*, ce.contact_id, c.first_name, c.last_name, c.email, cs.step_name
     FROM campaign_step_executions cse
     JOIN campaign_enrollments ce ON ce.id = cse.enrollment_id
     JOIN contacts c ON c.id = ce.contact_id
     JOIN campaign_steps cs ON cs.id = cse.step_id
     WHERE cse.campaign_id = ?
     ORDER BY cse.created_at DESC
     LIMIT 200",
    [$campaignId]
);

$enrollments = Database::query(
    "SELECT ce.*, c.first_name, c.last_name, c.email
     FROM campaign_enrollments ce
     JOIN contacts c ON c.id = ce.contact_id
     WHERE ce.campaign_id = ?
     ORDER BY ce.entered_at DESC
     LIMIT 200",
    [$campaignId]
);

$pageTitle = 'Campaign View - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<div class="page-premium marketing-campaign-view-page">
    <div class="container">
        <div class="page-header">
            <div>
                <h1><?php echo htmlspecialchars($campaign['name']); ?></h1>
                <p>Status: <?php echo htmlspecialchars($campaign['status']); ?> | Model: <?php echo htmlspecialchars($campaign['attribution_model_default']); ?></p>
            </div>
            <div class="page-header-actions">
                <a class="btn-premium-secondary" href="campaigns.php">Back to Campaigns</a>
            </div>
        </div>

        <div class="content-card">
            <h2 class="marketing-legacy-section-title">Sequence</h2>
            <?php if (empty($campaign['steps'])): ?>
                <div class="empty-state campaign-sequence-state">
                    <p>No sequence steps are configured for this campaign yet.</p>
                </div>
            <?php else: ?>
                <ol class="campaign-sequence-list campaign-sequence-state">
                    <?php foreach ($campaign['steps'] as $step): ?>
                        <li>
                            <strong><?php echo htmlspecialchars($step['step_name']); ?></strong>
                            <span class="marketing-legacy-muted">(<?php echo htmlspecialchars($step['action_type']); ?><?php if ((int) ($step['wait_minutes'] ?? 0) > 0): ?>, wait <?php echo (int) $step['wait_minutes']; ?>m<?php endif; ?>)</span>
                        </li>
                    <?php endforeach; ?>
                </ol>
            <?php endif; ?>
        </div>

        <div class="table-card marketing-legacy-section-spaced">
            <div class="premium-section-header"><h2>Enrollments</h2></div>
            <div class="marketing-legacy-list">
                <?php if (empty($enrollments)): ?>
                    <div class="empty-state"><p>No contacts are enrolled in this campaign yet.</p></div>
                <?php else: ?>
                    <?php foreach ($enrollments as $row): ?>
                        <div class="marketing-legacy-row">
                            <?php echo htmlspecialchars(trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? '')) ?: ($row['email'] ?? 'Contact #' . $row['contact_id'])); ?>
                            - <span class="marketing-legacy-muted"><?php echo htmlspecialchars($row['status']); ?></span>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="table-card marketing-legacy-section-spaced">
            <div class="premium-section-header"><h2>Recent Step Executions</h2></div>
            <div class="marketing-legacy-list">
                <?php if (empty($executions)): ?>
                    <div class="empty-state"><p>No campaign steps have executed yet.</p></div>
                <?php else: ?>
                    <?php foreach ($executions as $row): ?>
                        <div class="marketing-legacy-row marketing-legacy-row--split">
                            <div>
                                <strong><?php echo htmlspecialchars($row['step_name']); ?></strong> -
                                <?php echo htmlspecialchars(trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''))); ?>
                                <div class="marketing-legacy-error-detail"><?php echo htmlspecialchars((string) ($row['error_message'] ?? '')); ?></div>
                            </div>
                            <div class="marketing-legacy-muted"><?php echo htmlspecialchars($row['status']); ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
