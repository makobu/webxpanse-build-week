<?php
/**
 * ML Scoring Dashboard
 * Admin dashboard for monitoring ML model performance
 */

require_once __DIR__ . '/../vendor/autoload.php';

// Load environment
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || strpos($line, '#') === 0) continue;
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
use CRM\Modules\MLModelManager;
use CRM\Services\MLOutcomeTracker;
use CRM\Modules\AILeadScoring;
use CRM\Services\AnalyticsWorkspaceService;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

// Require authentication
if (!Auth::check()) {
    header('Location: login.php');
    exit;
}
$currentUser = Auth::user();
if (!Authorization::can('feature.ml_scoring_dashboard', $currentUser)) {
    header('Location: dashboard.php');
    exit;
}
$canLeadScoring = Authorization::can('settings.scoring', $currentUser);
$canMlTraining = Authorization::can('feature.ml_training', $currentUser);

$modelManager = new MLModelManager();
$outcomeTracker = new MLOutcomeTracker();
$scoringService = new AILeadScoring();
$analyticsWorkspace = new AnalyticsWorkspaceService();
$workspaceId = null;
$error = null;
$conversionModel = null;
$churnModel = null;
$engagementModel = null;
$conversionHistory = [];
$churnHistory = [];
$conversionAccuracy = ['overall' => []];
$churnAccuracy = ['overall' => []];
$scoringStats = [
    'total_contacts' => 0,
    'ml_scored_count' => 0,
    'ml_coverage' => 0,
    'average_score' => 0,
    'high_score_count' => 0,
    'medium_score_count' => 0,
    'low_score_count' => 0,
];
$scoreDistribution = [];
$recentModels = [];

try {
    $workspaceId = $analyticsWorkspace->requireAnalyticsWorkspaceId();

    $conversionModel = $modelManager->getActiveModel('conversion');
    $churnModel = $modelManager->getActiveModel('churn');
    $engagementModel = $modelManager->getActiveModel('engagement');

    $conversionHistory = $modelManager->getPerformanceHistory('conversion');
    $churnHistory = $modelManager->getPerformanceHistory('churn');

    $conversionAccuracy = $outcomeTracker->getPredictionAccuracy('conversion');
    $churnAccuracy = $outcomeTracker->getPredictionAccuracy('churn');

    $scoringStats = $scoringService->getScoringStatistics();

    $scoreDistribution = Database::query(
        "SELECT 
            CASE 
                WHEN ml_score >= 70 THEN 'High (70-100)'
                WHEN ml_score >= 50 THEN 'Medium (50-69)'
                WHEN ml_score >= 30 THEN 'Low (30-49)'
                ELSE 'Very Low (0-29)'
            END as bucket,
            COUNT(*) as count,
            AVG(ml_score) as avg_score,
            COUNT(CASE WHEN stage = 'won' THEN 1 END) as converted_count
         FROM contacts
         WHERE workspace_id = ?
           AND ml_score IS NOT NULL
         GROUP BY bucket
         ORDER BY avg_score DESC",
        [$workspaceId]
    );

    $recentModels = array_slice($modelManager->listModels(null, false, $workspaceId), 0, 10);
} catch (\Throwable $e) {
    $error = $e->getMessage();
}

$modelRows = [
    ['title' => 'Conversion Model', 'model' => $conversionModel],
    ['title' => 'Churn Model', 'model' => $churnModel],
];

$pageTitle = 'ML Scoring Models - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<div class="page-premium">
    <div class="container">
        <section class="page-header" aria-labelledby="ml-scoring-title">
            <div>
                <h1 id="ml-scoring-title">ML Scoring Models</h1>
                <p>Monitor and manage AI-powered lead scoring model performance.</p>
            </div>
            <div class="page-header-actions">
                <a class="btn-premium-secondary" href="contacts.php"><i class="fas fa-users" aria-hidden="true"></i>Back to Contacts</a>
                <?php if ($canLeadScoring): ?>
                    <a class="btn-premium-secondary" href="lead_scoring.php"><i class="fas fa-chart-line" aria-hidden="true"></i>Engagement Rules</a>
                <?php endif; ?>
                <?php if ($canMlTraining): ?>
                    <button class="btn-premium-primary" type="button" onclick="showTrainModelModal()"><i class="fas fa-brain" aria-hidden="true"></i>Train New Model</button>
                <?php endif; ?>
            </div>
        </section>

        <?php if ($error !== null): ?>
            <div class="premium-banner premium-banner-error">
                <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <div class="premium-card-grid">
            <?php foreach ($modelRows as $row): ?>
                <?php $model = $row['model']; ?>
                <section class="content-card" aria-label="<?php echo htmlspecialchars($row['title']); ?>">
                    <h2 class="premium-card-title"><?php echo htmlspecialchars($row['title']); ?></h2>
                    <?php if ($model): ?>
                        <div class="premium-detail-list">
                            <div class="premium-detail-row"><span>Version</span><strong class="premium-monospace"><?php echo htmlspecialchars((string) $model['version']); ?></strong></div>
                            <div class="premium-detail-row"><span>Algorithm</span><strong><?php echo htmlspecialchars((string) $model['algorithm']); ?></strong></div>
                            <div class="premium-detail-row"><span>AUC-ROC</span><strong><?php echo number_format((float) ($model['auc_roc'] ?? 0), 3); ?></strong></div>
                            <div class="premium-detail-row"><span>Accuracy</span><strong><?php echo number_format((float) ($model['accuracy'] ?? 0), 3); ?></strong></div>
                            <div class="premium-detail-row"><span>Trained</span><strong><?php echo htmlspecialchars(date('M d, Y', strtotime((string) $model['trained_at']))); ?></strong></div>
                        </div>
                    <?php else: ?>
                        <p class="premium-empty-compact">No active model.</p>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>

            <section class="content-card" aria-labelledby="scoring-statistics-title">
                <h2 class="premium-card-title" id="scoring-statistics-title">Scoring Statistics</h2>
                <div class="premium-detail-list">
                    <div class="premium-detail-row"><span>Total Contacts</span><strong><?php echo number_format((int) ($scoringStats['total_contacts'] ?? 0)); ?></strong></div>
                    <div class="premium-detail-row"><span>ML Scored</span><strong><?php echo number_format((int) ($scoringStats['ml_scored_count'] ?? 0)); ?> (<?php echo htmlspecialchars((string) ($scoringStats['ml_coverage'] ?? 0)); ?>%)</strong></div>
                    <div class="premium-detail-row"><span>Average Score</span><strong><?php echo number_format((float) ($scoringStats['average_score'] ?? $scoringStats['average_consolidated_score'] ?? 0), 1); ?></strong></div>
                </div>
                <div class="premium-inline-actions">
                    <span class="premium-status-badge is-success">High: <?php echo number_format((int) ($scoringStats['high_score_count'] ?? 0)); ?></span>
                    <span class="premium-status-badge is-warning">Medium: <?php echo number_format((int) ($scoringStats['medium_score_count'] ?? 0)); ?></span>
                    <span class="premium-status-badge is-danger">Low: <?php echo number_format((int) ($scoringStats['low_score_count'] ?? 0)); ?></span>
                </div>
            </section>
        </div>

        <div class="premium-dashboard-grid">
            <section class="content-card" aria-labelledby="conversion-accuracy-title">
                <h2 class="premium-card-title" id="conversion-accuracy-title">Conversion Prediction Accuracy</h2>
                <?php if (!empty($conversionAccuracy['overall'])): ?>
                    <div class="premium-mini-metrics">
                        <div class="premium-mini-metric">
                            <div class="premium-mini-metric-label">Accuracy</div>
                            <div class="premium-mini-metric-value"><?php echo number_format((float) $conversionAccuracy['overall']['accuracy'] * 100, 2); ?>%</div>
                        </div>
                        <div class="premium-mini-metric">
                            <div class="premium-mini-metric-label">Validated</div>
                            <div class="premium-mini-metric-value"><?php echo number_format((int) $conversionAccuracy['overall']['total_validated']); ?></div>
                        </div>
                        <div class="premium-mini-metric">
                            <div class="premium-mini-metric-label">MAE</div>
                            <div class="premium-mini-metric-value"><?php echo number_format((float) $conversionAccuracy['overall']['mae'], 4); ?></div>
                        </div>
                        <div class="premium-mini-metric">
                            <div class="premium-mini-metric-label">RMSE</div>
                            <div class="premium-mini-metric-value"><?php echo number_format((float) $conversionAccuracy['overall']['rmse'], 4); ?></div>
                        </div>
                    </div>
                <?php else: ?>
                    <p class="premium-empty-compact">No validation data available.</p>
                <?php endif; ?>
            </section>

            <section class="content-card" aria-labelledby="churn-accuracy-title">
                <h2 class="premium-card-title" id="churn-accuracy-title">Churn Prediction Accuracy</h2>
                <?php if (!empty($churnAccuracy['overall'])): ?>
                    <div class="premium-mini-metrics">
                        <div class="premium-mini-metric">
                            <div class="premium-mini-metric-label">Accuracy</div>
                            <div class="premium-mini-metric-value"><?php echo number_format((float) $churnAccuracy['overall']['accuracy'] * 100, 2); ?>%</div>
                        </div>
                        <div class="premium-mini-metric">
                            <div class="premium-mini-metric-label">Validated</div>
                            <div class="premium-mini-metric-value"><?php echo number_format((int) $churnAccuracy['overall']['total_validated']); ?></div>
                        </div>
                        <div class="premium-mini-metric">
                            <div class="premium-mini-metric-label">MAE</div>
                            <div class="premium-mini-metric-value"><?php echo number_format((float) $churnAccuracy['overall']['mae'], 4); ?></div>
                        </div>
                        <div class="premium-mini-metric">
                            <div class="premium-mini-metric-label">RMSE</div>
                            <div class="premium-mini-metric-value"><?php echo number_format((float) $churnAccuracy['overall']['rmse'], 4); ?></div>
                        </div>
                    </div>
                <?php else: ?>
                    <p class="premium-empty-compact">No validation data available.</p>
                <?php endif; ?>
            </section>
        </div>

        <section class="table-card" aria-labelledby="score-distribution-title">
            <div class="premium-section-header">
                <div>
                    <h2 id="score-distribution-title">Score Distribution</h2>
                    <p>Model score bands, conversion outcomes, and average score by bucket.</p>
                </div>
            </div>
            <?php if (empty($scoreDistribution)): ?>
                <div class="empty-state">
                    <p>No score data available yet.</p>
                </div>
            <?php else: ?>
                <div class="table-card-scroll">
                    <table class="premium-table">
                        <thead>
                            <tr>
                                <th>Score Bucket</th>
                                <th>Count</th>
                                <th>Average Score</th>
                                <th>Converted</th>
                                <th>Conversion Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($scoreDistribution as $bucket): ?>
                                <?php $bucketCount = (int) ($bucket['count'] ?? 0); ?>
                                <tr>
                                    <td><?php echo htmlspecialchars((string) $bucket['bucket']); ?></td>
                                    <td><?php echo number_format($bucketCount); ?></td>
                                    <td><?php echo number_format((float) ($bucket['avg_score'] ?? 0), 1); ?></td>
                                    <td><?php echo number_format((int) ($bucket['converted_count'] ?? 0)); ?></td>
                                    <td><?php echo $bucketCount > 0 ? number_format(((int) $bucket['converted_count'] / $bucketCount) * 100, 1) : 0; ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="table-card" aria-labelledby="recent-training-title">
            <div class="premium-section-header">
                <div>
                    <h2 id="recent-training-title">Recent Model Training</h2>
                    <p>Latest model versions and active status.</p>
                </div>
            </div>
            <?php if (empty($recentModels)): ?>
                <div class="empty-state">
                    <p>No models trained yet.</p>
                </div>
            <?php else: ?>
                <div class="table-card-scroll">
                    <table class="premium-table">
                        <thead>
                            <tr>
                                <th>Type</th>
                                <th>Version</th>
                                <th>Algorithm</th>
                                <th>Accuracy</th>
                                <th>AUC-ROC</th>
                                <th>Trained</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentModels as $model): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string) $model['model_type']))); ?></td>
                                    <td class="premium-monospace"><?php echo htmlspecialchars((string) $model['version']); ?></td>
                                    <td><?php echo htmlspecialchars((string) $model['algorithm']); ?></td>
                                    <td><?php echo number_format((float) ($model['accuracy'] ?? 0), 3); ?></td>
                                    <td><?php echo number_format((float) ($model['auc_roc'] ?? 0), 3); ?></td>
                                    <td><?php echo htmlspecialchars(date('M d, Y H:i', strtotime((string) $model['trained_at']))); ?></td>
                                    <td>
                                        <?php if (!empty($model['is_active'])): ?>
                                            <span class="premium-status-badge is-success">Active</span>
                                        <?php else: ?>
                                            <span class="premium-status-badge">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <?php if ($canMlTraining || $canLeadScoring): ?>
            <section class="content-card" aria-labelledby="ml-actions-title">
                <div class="premium-section-header">
                    <div>
                        <h2 id="ml-actions-title">Actions</h2>
                        <p>Refresh model data or move between scoring views.</p>
                    </div>
                </div>
                <div class="premium-inline-actions">
                    <?php if ($canMlTraining): ?>
                        <button class="btn-premium-primary" type="button" onclick="showTrainModelModal()"><i class="fas fa-brain" aria-hidden="true"></i>Train New Model</button>
                    <?php endif; ?>
                    <?php if ($canLeadScoring): ?>
                        <a class="btn-premium-secondary" href="lead_scoring.php"><i class="fas fa-chart-line" aria-hidden="true"></i>View Engagement Rules</a>
                    <?php endif; ?>
                    <?php if ($canMlTraining): ?>
                        <button class="btn-premium-secondary" type="button" onclick="recalculateAllScores(event)"><i class="fas fa-sync-alt" aria-hidden="true"></i>Recalculate All Scores</button>
                    <?php endif; ?>
                </div>
            </section>
        <?php endif; ?>
    </div>
</div>

<?php if ($canMlTraining): ?>
<div id="trainModelModal" class="premium-modal-overlay">
    <div class="premium-modal">
        <div class="premium-modal-header">
            <h3>Train New ML Model</h3>
            <button class="premium-icon-button" type="button" onclick="closeTrainModelModal()" aria-label="Close train model modal">
                <i class="fas fa-times" aria-hidden="true"></i>
            </button>
        </div>
        <form id="trainModelForm" onsubmit="trainModel(event)">
            <div class="form-group">
                <label for="model_type">Model Type</label>
                <select id="model_type" name="model_type" required>
                    <option value="conversion">Conversion</option>
                    <option value="churn">Churn</option>
                    <option value="engagement">Engagement</option>
                </select>
            </div>
            <div class="form-group">
                <label for="algorithm">Algorithm</label>
                <select id="algorithm" name="algorithm" required>
                    <option value="logistic_regression">Logistic Regression</option>
                    <option value="random_forest">Random Forest</option>
                    <option value="gradient_boosting">Gradient Boosting</option>
                </select>
            </div>
            <div class="form-group">
                <label for="days_back">Days of Historical Data</label>
                <input id="days_back" type="number" name="days_back" value="90" min="30" max="365" required>
                <div class="premium-inline-note">Number of days of historical data to use for training.</div>
            </div>
            <div class="form-actions">
                <button class="btn-premium-secondary" type="button" onclick="closeTrainModelModal()">Cancel</button>
                <button class="btn-premium-primary" type="submit">Start Training</button>
            </div>
        </form>
        <div id="trainModelStatus" class="premium-status-note" aria-live="polite"></div>
    </div>
</div>
<?php endif; ?>

<script>
function showTrainModelModal() {
    const modal = document.getElementById('trainModelModal');
    if (!modal) return;
    modal.classList.add('show');
    document.body.style.overflow = 'hidden';
    modal.scrollTop = 0;
}

function closeTrainModelModal() {
    const modal = document.getElementById('trainModelModal');
    if (!modal) return;
    modal.classList.remove('show');
    const statusDiv = document.getElementById('trainModelStatus');
    if (statusDiv) {
        statusDiv.className = 'premium-status-note';
        statusDiv.innerHTML = '';
    }
    const form = document.getElementById('trainModelForm');
    if (form) {
        form.reset();
    }
    document.body.style.overflow = '';
}

function setModelStatus(type, message) {
    const statusDiv = document.getElementById('trainModelStatus');
    if (!statusDiv) return;
    const tone = type === 'success' ? 'premium-banner-success' : (type === 'error' ? 'premium-banner-error' : '');
    statusDiv.className = 'premium-status-note premium-banner is-visible ' + tone;
    statusDiv.innerHTML = message;
}

async function trainModel(event) {
    event.preventDefault();
    const form = event.target;
    const formData = new FormData(form);
    const data = {
        model_type: formData.get('model_type'),
        algorithm: formData.get('algorithm'),
        days_back: parseInt(formData.get('days_back'))
    };
    
    setModelStatus('info', '<i class="fas fa-spinner fa-spin"></i> Training model... This may take several minutes.');
    
    const submitBtn = form.querySelector('button[type="submit"]');
    submitBtn.disabled = true;
    submitBtn.textContent = 'Training...';
    
    try {
        const response = await fetch('../api/ml-scoring/train.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(data)
        });
        
        const result = await response.json();
        
        if (result.success) {
            setModelStatus('success', '<i class="fas fa-check-circle"></i> Model training started successfully! Version: ' + result.data.version);
            setTimeout(() => {
                closeTrainModelModal();
                location.reload();
            }, 2000);
        } else {
            setModelStatus('error', '<i class="fas fa-exclamation-circle"></i> Error: ' + (result.error || 'Training failed'));
            submitBtn.disabled = false;
            submitBtn.textContent = 'Start Training';
        }
    } catch (error) {
        setModelStatus('error', '<i class="fas fa-exclamation-circle"></i> Error: ' + error.message);
        submitBtn.disabled = false;
        submitBtn.textContent = 'Start Training';
    }
}

async function recalculateAllScores(event) {
    if (!confirm('This will recalculate ML scores for all contacts. This may take a while. Continue?')) {
        return;
    }
    
    const btn = event ? event.currentTarget : null;
    const originalText = btn ? btn.innerHTML : '';
    if (btn) {
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>Recalculating...';
    }
    
    try {
        const response = await fetch('../api/ml-scoring/recalculate.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            }
        });
        
        const result = await response.json();
        
        if (result.success) {
            alert('Successfully recalculated scores for ' + result.data.processed + ' contacts.');
            location.reload();
        } else {
            alert('Error: ' + (result.error || 'Recalculation failed'));
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = originalText;
            }
        }
    } catch (error) {
        alert('Error: ' + error.message);
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = originalText;
        }
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('trainModelModal');
    if (modal) {
        if (modal.parentElement !== document.body) {
            document.body.appendChild(modal);
        }

        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                closeTrainModelModal();
            }
        });
    }
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
