<?php
/**
 * Lead Scoring Rules Management Page
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
use CRM\Authorization;
use CRM\Modules\LeadScoring;
use CRM\Services\AnalyticsWorkspaceService;
use CRM\Services\MarketplacePageExplainerService;
use CRM\Services\PageGuideVideoUi;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

// Require authentication
if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

// Require admin role
$user = Auth::user();
if (!Authorization::can('settings.scoring', $user)) {
    header('Location: dashboard.php');
    exit;
}
$canMlDashboard = Authorization::can('feature.ml_scoring_dashboard', $user);

$leadScoring = new LeadScoring();
$analyticsWorkspace = new AnalyticsWorkspaceService();
$workspaceId = null;
$error = null;
$scoreStats = [
    'total_contacts' => 0,
    'avg_score' => 0,
    'max_score' => 0,
    'min_score' => 0,
    'hot_leads' => 0,
    'warm_leads' => 0,
    'cold_leads' => 0,
];
$topContacts = [];

try {
    $workspaceId = $analyticsWorkspace->requireAnalyticsWorkspaceId();

    $scoreStats = Database::queryOne(
        "SELECT 
            COUNT(*) as total_contacts,
            AVG(engagement_score) as avg_score,
            MAX(engagement_score) as max_score,
            MIN(engagement_score) as min_score,
            COUNT(CASE WHEN engagement_score >= 70 THEN 1 END) as hot_leads,
            COUNT(CASE WHEN engagement_score >= 40 AND engagement_score < 70 THEN 1 END) as warm_leads,
            COUNT(CASE WHEN engagement_score < 40 THEN 1 END) as cold_leads
         FROM contacts
         WHERE workspace_id = ?",
        [$workspaceId]
    ) ?: $scoreStats;

    $topContacts = Database::query(
        "SELECT id, first_name, last_name, email, engagement_score
         FROM contacts
         WHERE workspace_id = ?
           AND engagement_score > 0
         ORDER BY engagement_score DESC
         LIMIT 10",
        [$workspaceId]
    );
} catch (\Throwable $e) {
    $error = $e->getMessage();
}

// Get contacts by score range
$scoreRanges = [
    'hot' => ['min' => 70, 'max' => 100, 'label' => 'Hot Leads (70-100)', 'class' => 'is-danger'],
    'warm' => ['min' => 40, 'max' => 69, 'label' => 'Warm Leads (40-69)', 'class' => 'is-warning'],
    'cold' => ['min' => 0, 'max' => 39, 'label' => 'Cold Leads (0-39)', 'class' => 'is-info'],
];

$scoreValueClass = static function (int $score): string {
    if ($score >= 70) {
        return 'is-hot';
    }
    if ($score >= 40) {
        return 'is-warm';
    }
    return '';
};

$pageTitle = 'Engagement Rule Scoring - ' . brandProductName();
$leadScoringGuideVideoUrl = PageGuideVideoUi::activeVideoUrl(MarketplacePageExplainerService::PAGE_LEAD_SCORING);
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<?php echo PageGuideVideoUi::assets(); ?>
<div class="page-premium">
    <div class="container">
        <section class="page-header" aria-labelledby="rule-scoring-title">
            <div>
                <h1 id="rule-scoring-title">Engagement Rule Scoring</h1>
                <p>View recent activity scores that feed the composite lead score.</p>
            </div>
            <?php if ($leadScoringGuideVideoUrl !== '' || $canMlDashboard): ?>
                <div class="page-header-actions">
                    <?php if ($leadScoringGuideVideoUrl !== ''): ?>
                        <?php echo PageGuideVideoUi::button(MarketplacePageExplainerService::PAGE_LEAD_SCORING, 'Lead Scoring page guide'); ?>
                    <?php endif; ?>
                    <?php if ($canMlDashboard): ?>
                        <a class="btn-premium-primary" href="ml_scoring_dashboard.php"><i class="fas fa-brain" aria-hidden="true"></i>ML Models</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>

        <?php if ($error !== null): ?>
            <div class="premium-banner premium-banner-error">
                <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <div class="stats-grid" aria-label="Rule scoring summary">
            <div class="stat-card">
                <div class="stat-label">Average Engagement Score</div>
                <div class="stat-value"><?php echo number_format((float) ($scoreStats['avg_score'] ?? 0), 1); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Hot Leads</div>
                <div class="stat-value"><?php echo number_format((int) ($scoreStats['hot_leads'] ?? 0)); ?></div>
                <div class="premium-inline-note">70-100 points</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Warm Leads</div>
                <div class="stat-value"><?php echo number_format((int) ($scoreStats['warm_leads'] ?? 0)); ?></div>
                <div class="premium-inline-note">40-69 points</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Cold Leads</div>
                <div class="stat-value"><?php echo number_format((int) ($scoreStats['cold_leads'] ?? 0)); ?></div>
                <div class="premium-inline-note">0-39 points</div>
            </div>
        </div>

        <div class="premium-dashboard-grid">
            <section class="content-card" aria-labelledby="top-scoring-contacts-title">
                <div class="premium-section-header">
                    <div>
                        <h2 id="top-scoring-contacts-title">Top Scoring Contacts</h2>
                        <p>Highest current engagement/rule scores.</p>
                    </div>
                </div>
                <?php if (empty($topContacts)): ?>
                    <p class="premium-empty-compact">No contacts with scores yet.</p>
                <?php else: ?>
                    <div class="premium-list-card">
                        <?php foreach ($topContacts as $contact): ?>
                            <?php $score = (int) ($contact['engagement_score'] ?? 0); ?>
                            <div class="premium-score-row">
                                <div>
                                    <a class="premium-muted-link" href="contact_view.php?id=<?php echo (int) $contact['id']; ?>">
                                        <?php echo htmlspecialchars(trim((string) (($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? '')))); ?>
                                    </a>
                                    <div class="premium-inline-note"><?php echo htmlspecialchars((string) ($contact['email'] ?? '')); ?></div>
                                </div>
                                <div class="premium-score-value <?php echo $scoreValueClass($score); ?>"><?php echo $score; ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section class="content-card" aria-labelledby="score-distribution-title">
                <div class="premium-section-header">
                    <div>
                        <h2 id="score-distribution-title">Score Distribution</h2>
                        <p>Contacts grouped by scoring bands.</p>
                    </div>
                </div>
                <div class="premium-detail-list">
                    <?php foreach ($scoreRanges as $key => $range): ?>
                        <?php
                        $count = $workspaceId === null
                            ? 0
                            : (Database::queryOne(
                                "SELECT COUNT(*) as count
                                 FROM contacts
                                 WHERE workspace_id = ?
                                   AND engagement_score >= ?
                                   AND engagement_score <= ?",
                                [$workspaceId, $range['min'], $range['max']]
                            )['count'] ?? 0);
                        $total = (int) ($scoreStats['total_contacts'] ?? 1);
                        $percentage = $total > 0 ? ($count / $total) * 100 : 0;
                        ?>
                        <div>
                            <div class="premium-detail-row">
                                <strong><?php echo htmlspecialchars($range['label']); ?></strong>
                                <span><?php echo number_format((int) $count); ?> (<?php echo number_format($percentage, 1); ?>%)</span>
                            </div>
                            <div class="premium-progress" style="--premium-progress-value: <?php echo max(0, min(100, $percentage)); ?>%;">
                                <div class="premium-progress-fill <?php echo htmlspecialchars($range['class']); ?>"></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>

        <section class="content-card" aria-labelledby="scoring-rules-title">
            <div class="premium-section-header">
                <div>
                    <h2 id="scoring-rules-title">Engagement Rules</h2>
                    <p>Rules are calculated from recent engagement and capped at 100 points.</p>
                </div>
            </div>

            <div class="premium-card-grid premium-card-grid--compact">
                <div class="premium-rule-card">
                    <strong>Email Activity</strong>
                    <span>+8 points</span>
                    <div class="premium-inline-note">Decay: 0.9 per day</div>
                </div>
                <div class="premium-rule-card">
                    <strong>Call Logged</strong>
                    <span>+12 points</span>
                    <div class="premium-inline-note">Decay: 0.9 per day</div>
                </div>
                <div class="premium-rule-card">
                    <strong>Meeting Held</strong>
                    <span>+18 points</span>
                    <div class="premium-inline-note">Decay: 0.85 per day</div>
                </div>
                <div class="premium-rule-card">
                    <strong>Note Added</strong>
                    <span>+3 points</span>
                    <div class="premium-inline-note">Decay: 0.95 per day</div>
                </div>
                <div class="premium-rule-card">
                    <strong>Email Opened</strong>
                    <span>+5 points</span>
                    <div class="premium-inline-note">Decay: 0.9 per day</div>
                </div>
                <div class="premium-rule-card">
                    <strong>Link Clicked</strong>
                    <span>+10 points</span>
                    <div class="premium-inline-note">Decay: 0.85 per day</div>
                </div>
                <div class="premium-rule-card">
                    <strong>Form Submitted</strong>
                    <span>+20 points</span>
                    <div class="premium-inline-note">Decay: 0.8 per day; form_submitted is an alias</div>
                </div>
                <div class="premium-rule-card">
                    <strong>Page Visited</strong>
                    <span>+15 points</span>
                    <div class="premium-inline-note">No decay</div>
                </div>
            </div>

            <div class="premium-banner premium-banner-success">
                <strong>Note:</strong> Scores are calculated based on activities from the last 30 days. Scores decay over time to reflect recent engagement.
            </div>

            <?php if ($canMlDashboard): ?>
                <div class="premium-banner">
                    <strong><i class="fas fa-info-circle" aria-hidden="true"></i> AI-Powered Scoring Available:</strong>
                    This page shows engagement/rule inputs. For model-backed scoring with predictive analytics, model training, and advanced insights, visit the
                    <a class="premium-muted-link" href="ml_scoring_dashboard.php">ML Models</a>.
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>

<?php echo PageGuideVideoUi::modal(MarketplacePageExplainerService::PAGE_LEAD_SCORING, 'How to use Lead Scoring', $leadScoringGuideVideoUrl); ?>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
