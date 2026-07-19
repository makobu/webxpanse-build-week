<?php
/**
 * Campaigns Page
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
use CRM\Security;
use CRM\Session;
use CRM\Services\CampaignOrchestrator;
use CRM\Services\MarketplacePageExplainerService;
use CRM\Services\PageGuideVideoUi;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (($_ENV['CAMPAIGNS_ENABLED'] ?? '1') !== '1') {
    http_response_code(404);
    echo 'Campaign automation is disabled.';
    exit;
}

if (!Auth::check()) {
    header('Location: ' . getBasePath() . '/login.php');
    exit;
}
$user = Auth::user();
if (!Authorization::can('campaigns.manage', $user)) {
    header('Location: ' . getBasePath() . '/dashboard.php');
    exit;
}

$orchestrator = new CampaignOrchestrator();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Invalid CSRF token');
        }
        $action = Security::sanitizeInput($_POST['action'] ?? '', 'string');
        if ($action === 'create') {
            $steps = [[
                'step_name' => 'Initial Email',
                'action_type' => 'send_email',
                'channel' => 'email',
                'subject' => Security::sanitizeInput($_POST['subject'] ?? 'Hello from our team', 'string'),
                'content' => $_POST['content'] ?? '',
                'wait_minutes' => 0
            ]];
            $id = $orchestrator->createCampaign([
                'name' => Security::sanitizeInput($_POST['name'] ?? '', 'string'),
                'description' => Security::sanitizeInput($_POST['description'] ?? '', 'string'),
                'objective' => 'outbound',
                'channel_mix' => ['email'],
                'attribution_model_default' => Security::sanitizeInput($_POST['attribution_model_default'] ?? 'last_touch', 'string'),
                'created_by' => Auth::user()['id'] ?? null,
                'steps' => $steps
            ]);
            header('Location: ' . getBasePath() . '/campaign_view.php?id=' . $id . '&success=created');
            exit;
        }

        $campaignId = (int) ($_POST['campaign_id'] ?? 0);
        if ($campaignId <= 0) {
            throw new RuntimeException('Campaign id is required');
        }

        if ($action === 'launch') {
            $filters = [];
            if (!empty($_POST['stage'])) {
                $filters['stage'] = Security::sanitizeInput($_POST['stage'], 'string');
            }
            if (!empty($_POST['tag_id'])) {
                $filters['tag_id'] = (int) $_POST['tag_id'];
            }
            $orchestrator->launchCampaign($campaignId, $filters, true);
        } elseif ($action === 'pause') {
            $orchestrator->pauseCampaign($campaignId);
        } elseif ($action === 'resume') {
            $orchestrator->resumeCampaign($campaignId);
        } elseif ($action === 'delete') {
            $orchestrator->deleteCampaign($campaignId);
        }

        header('Location: ' . getBasePath() . '/campaigns.php?success=' . urlencode($action));
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$campaigns = $orchestrator->listCampaigns();
$statusCounts = ['active' => 0, 'paused' => 0, 'draft' => 0];
$totalEnrollments = 0;
foreach ($campaigns as $campaign) {
    $status = strtolower((string) ($campaign['status'] ?? 'draft'));
    if (!isset($statusCounts[$status])) {
        $status = 'draft';
    }
    $statusCounts[$status]++;
    $totalEnrollments += (int) ($campaign['enrollment_count'] ?? 0);
}
$successType = Security::sanitizeInput($_GET['success'] ?? '', 'string');
$successMessage = '';
if ($successType === 'launch') {
    $successMessage = 'Campaign launched. Matching contacts are now entering this campaign.';
} elseif ($successType === 'pause') {
    $successMessage = 'Campaign paused. New contacts will not be enrolled until you resume.';
} elseif ($successType === 'resume') {
    $successMessage = 'Campaign resumed. New matching contacts can be enrolled again.';
} elseif ($successType === 'delete') {
    $successMessage = 'Campaign deleted successfully.';
}

$postedName = htmlspecialchars((string) ($_POST['name'] ?? ''), ENT_QUOTES, 'UTF-8');
$postedDescription = htmlspecialchars((string) ($_POST['description'] ?? ''), ENT_QUOTES, 'UTF-8');
$postedSubject = htmlspecialchars((string) ($_POST['subject'] ?? 'Quick introduction from [Your Company]'), ENT_QUOTES, 'UTF-8');
$postedContent = htmlspecialchars((string) ($_POST['content'] ?? "Hi [First Name],\n\nI wanted to quickly introduce [Your Company] and how we help teams like yours.\n\nWould you be open to a short call this week?\n\nBest,\n[Your Name]"), ENT_QUOTES, 'UTF-8');
$pageTitle = 'Campaigns - ' . brandProductName();
$campaignsGuideVideoUrl = PageGuideVideoUi::activeVideoUrl(MarketplacePageExplainerService::PAGE_CAMPAIGNS);
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<?php echo PageGuideVideoUi::assets(); ?>

<div class="page-premium marketing-campaigns-page">
    <div class="container">
        <div class="page-header">
            <div>
                <h1>Campaign Automation</h1>
                <p>Create, launch, and monitor outbound campaigns in a few simple steps.</p>
            </div>
            <div class="page-header-actions">
                <?php if ($campaignsGuideVideoUrl !== ''): ?>
                    <?php echo PageGuideVideoUi::button(MarketplacePageExplainerService::PAGE_CAMPAIGNS, 'Campaign Automation page guide', 'btn-premium-secondary'); ?>
                <?php endif; ?>
                <a href="#create-campaign" class="btn-premium-primary">
                    <i class="fas fa-plus"></i>
                    Create Campaign
                </a>
            </div>
        </div>

        <div class="onboarding-card">
            <h2 class="onboarding-title">Quick Start (No technical setup needed)</h2>
            <ol class="simple-steps">
                <li>Create a draft with a clear name and your first email.</li>
                <li>Review details in the campaign page, then click <strong>Launch</strong>.</li>
                <li>Pause anytime if you want to stop new enrollments.</li>
            </ol>
        </div>

        <?php if ($error): ?>
            <div class="campaign-alert campaign-alert-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($successMessage !== ''): ?>
            <div class="campaign-alert campaign-alert-success"><?php echo htmlspecialchars($successMessage); ?></div>
        <?php endif; ?>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Total Campaigns</div>
                <div class="stat-value"><?php echo count($campaigns); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Active</div>
                <div class="stat-value"><?php echo $statusCounts['active']; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Paused</div>
                <div class="stat-value"><?php echo $statusCounts['paused']; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Total Enrollments</div>
                <div class="stat-value"><?php echo $totalEnrollments; ?></div>
            </div>
        </div>

        <div class="content-card" id="create-campaign">
            <h2 class="marketing-legacy-section-title">Create Campaign</h2>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                <input type="hidden" name="action" value="create">
                <div class="campaign-form-grid">
                    <div class="form-group">
                        <label for="campaign_name">Campaign Title</label>
                        <input id="campaign_name" type="text" name="name" placeholder="Example: Q1 Welcome Outreach" value="<?php echo $postedName; ?>" required>
                        <small class="form-hint">Use a simple business label so your team can recognize it quickly.</small>
                    </div>
                    <div class="form-group">
                        <label for="campaign_attribution">How Performance Credit Is Assigned</label>
                        <select id="campaign_attribution" name="attribution_model_default">
                            <option value="last_touch">Last Touch (recommended for most teams)</option>
                            <option value="first_touch">First Touch</option>
                            <option value="linear">Linear (shared evenly)</option>
                            <option value="time_decay">Time Decay (more credit to recent touchpoints)</option>
                            <option value="position_based">Position Based</option>
                        </select>
                        <small class="form-hint">This affects reporting only; it does not change who receives your emails.</small>
                    </div>
                    <div class="form-group">
                        <label for="campaign_description">Purpose (Optional)</label>
                        <input id="campaign_description" type="text" name="description" placeholder="Example: Introduce our service to new leads" value="<?php echo $postedDescription; ?>">
                    </div>
                    <div class="form-group">
                        <label for="campaign_subject">Step 1 Email Subject</label>
                        <input id="campaign_subject" type="text" name="subject" placeholder="What recipients see in their inbox" value="<?php echo $postedSubject; ?>" required>
                        <small class="form-hint">Keep this short and natural. Avoid all-caps and heavy punctuation.</small>
                    </div>
                </div>
                <div class="form-group campaign-message-field">
                    <label for="campaign_body">Step 1 Email Body</label>
                    <textarea id="campaign_body" name="content" placeholder="Write your email body..." required><?php echo $postedContent; ?></textarea>
                    <small class="form-hint">Tip: Start with a short introduction, one key value statement, and one clear call to action.</small>
                </div>
                <div class="campaign-inline-note">
                    Your campaign is created as a draft first. You can review it before launch.
                </div>
                <button class="btn-premium-primary" type="submit">Create Draft Campaign</button>
            </form>
        </div>

        <div class="table-card">
            <div class="premium-section-header">
                <h2>Existing Campaigns</h2>
            </div>
            <?php if (empty($campaigns)): ?>
                <div class="empty-state">
                    <p>No campaigns yet.</p>
                    <a href="#create-campaign">Create your first campaign</a>
                </div>
            <?php else: ?>
                <div class="campaign-list">
                    <?php foreach ($campaigns as $campaign): ?>
                        <?php
                        $status = strtolower((string) ($campaign['status'] ?? 'draft'));
                        $badgeClass = 'badge-default';
                        if ($status === 'active') {
                            $badgeClass = 'badge-success';
                        } elseif ($status === 'paused') {
                            $badgeClass = 'badge-warning';
                        }
                        ?>
                        <div class="campaign-row">
                            <div>
                                <a class="campaign-name" href="campaign_view.php?id=<?php echo (int) $campaign['id']; ?>">
                                    <?php echo htmlspecialchars($campaign['name']); ?>
                                </a>
                                <div class="campaign-meta">
                                    <span class="badge <?php echo $badgeClass; ?>"><?php echo htmlspecialchars($campaign['status']); ?></span>
                                    <span class="campaign-divider">|</span>
                                    <span>Enrollments: <?php echo (int) ($campaign['enrollment_count'] ?? 0); ?></span>
                                </div>
                            </div>
                            <div class="campaign-actions">
                                <a class="btn-premium-secondary" href="campaign_view.php?id=<?php echo (int) $campaign['id']; ?>">View</a>
                                <?php if ($campaign['status'] !== 'active'): ?>
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                        <input type="hidden" name="action" value="launch">
                                        <input type="hidden" name="campaign_id" value="<?php echo (int) $campaign['id']; ?>">
                                        <button type="submit" class="btn-premium-primary" onclick="return confirm('Launch this campaign now? Matching contacts will start enrolling.');">Launch</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($campaign['status'] === 'active'): ?>
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                        <input type="hidden" name="action" value="pause">
                                        <input type="hidden" name="campaign_id" value="<?php echo (int) $campaign['id']; ?>">
                                        <button type="submit" class="btn-premium-secondary" onclick="return confirm('Pause this campaign? New contacts will stop enrolling.');">Pause</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($campaign['status'] === 'paused'): ?>
                                    <form method="POST">
                                        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                        <input type="hidden" name="action" value="resume">
                                        <input type="hidden" name="campaign_id" value="<?php echo (int) $campaign['id']; ?>">
                                        <button type="submit" class="btn-premium-secondary" onclick="return confirm('Resume this campaign? New matching contacts can enroll again.');">Resume</button>
                                    </form>
                                <?php endif; ?>
                                <form method="POST">
                                    <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="campaign_id" value="<?php echo (int) $campaign['id']; ?>">
                                    <button type="submit" class="btn-premium-danger" onclick="return confirm('Delete this campaign permanently? This action cannot be undone.');">Delete</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php echo PageGuideVideoUi::modal(MarketplacePageExplainerService::PAGE_CAMPAIGNS, 'How to use Campaign Automation', $campaignsGuideVideoUrl); ?>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
