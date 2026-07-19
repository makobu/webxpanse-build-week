<?php
/**
 * Activity View Page
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
use CRM\Modules\Activities;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

// Require authentication
if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$activitiesModule = new Activities();
$activityId = (int) ($_GET['id'] ?? 0);

if (!$activityId) {
    header('Location: activities.php');
    exit;
}

$activity = $activitiesModule->getById($activityId);

if (!$activity) {
    header('Location: activities.php');
    exit;
}

$metadata = !empty($activity['metadata']) ? json_decode($activity['metadata'], true) : [];

$pageTitle = 'Activity Details - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">

<div class="page-premium">
    <div class="container">
        <div class="page-header">
            <div>
                <h1>Activity Details</h1>
                <p>Review a logged contact touchpoint.</p>
            </div>
            <div class="page-header-actions">
                <a href="contact_view.php?id=<?php echo (int) $activity['contact_id']; ?>" class="btn-premium-primary">
                    <i class="fas fa-user"></i>
                    View Contact
                </a>
                <a href="activities.php" class="btn-premium-secondary">
                    <i class="fas fa-arrow-left"></i>
                    Activities
                </a>
            </div>
        </div>

        <div class="activity-detail-grid">
            <div class="activity-detail-card">
                <div class="premium-section-header activity-card-header">
                    <div>
                        <h2>Activity Information</h2>
                        <p><?php echo htmlspecialchars(Activities::formatTypeLabel($activity['activity_type'] ?? '')); ?> logged on <?php echo htmlspecialchars(date('M j, Y', strtotime($activity['created_at']))); ?>.</p>
                    </div>
                </div>

                <div class="activity-detail-list">
                    <div>
                        <div class="activity-detail-label">Type</div>
                        <div class="activity-detail-value">
                            <span class="badge badge-default">
                                <?php echo htmlspecialchars(Activities::formatTypeLabel($activity['activity_type'] ?? '')); ?>
                            </span>
                        </div>
                    </div>

                    <div>
                        <div class="activity-detail-label">Contact</div>
                        <div class="activity-detail-value">
                            <a href="contact_view.php?id=<?php echo (int) $activity['contact_id']; ?>" class="premium-list-title">
                                <?php echo htmlspecialchars(trim(($activity['first_name'] ?? '') . ' ' . ($activity['last_name'] ?? '')) ?: ($activity['contact_email'] ?? 'Contact #' . (int) $activity['contact_id'])); ?>
                            </a>
                        </div>
                        <?php if (!empty($activity['contact_email'])): ?>
                            <div class="premium-list-meta"><?php echo htmlspecialchars($activity['contact_email']); ?></div>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($activity['description'])): ?>
                        <div>
                            <div class="activity-detail-label">Description</div>
                            <div class="activity-detail-value activity-detail-description">
                                <?php echo htmlspecialchars($activity['description']); ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div>
                        <div class="activity-detail-label">Created</div>
                        <div class="activity-detail-value">
                            <?php echo htmlspecialchars(date('F j, Y g:i A', strtotime($activity['created_at']))); ?>
                        </div>
                    </div>

                    <?php if (!empty($activity['user_email'])): ?>
                        <div>
                            <div class="activity-detail-label">Created By</div>
                            <div class="activity-detail-value">
                                <?php echo htmlspecialchars($activity['user_email']); ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($metadata)): ?>
                <div class="activity-detail-card">
                    <div class="premium-section-header activity-card-header">
                        <div>
                            <h2>Additional Information</h2>
                            <p>Structured metadata captured with the activity.</p>
                        </div>
                    </div>
                    <pre class="activity-metadata-pre"><?php echo htmlspecialchars(json_encode($metadata, JSON_PRETTY_PRINT)); ?></pre>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
