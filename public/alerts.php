<?php
/**
 * System Alerts Page
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/index.php';

use CRM\Modules\AlertingSystem;
use CRM\Auth;
use CRM\Authorization;
use CRM\Security;

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}
Authorization::requirePermission('settings.monitoring');

$alertingSystem = new AlertingSystem();

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        try {
            $action = $_POST['action'] ?? '';
            $alertId = (int) ($_POST['alert_id'] ?? 0);
            
            if ($action === 'acknowledge' && $alertId) {
                $alertingSystem->acknowledgeAlert($alertId, \CRM\Auth::userId());
                $success = 'Alert acknowledged.';
            } elseif ($action === 'resolve' && $alertId) {
                $alertingSystem->resolveAlert($alertId);
                $success = 'Alert resolved.';
            }
        } catch (\Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// Get alerts
$alerts = $alertingSystem->getActiveAlerts(100);
$alertStats = $alertingSystem->getAlertStats(7);

$pageTitle = 'System Alerts';
ob_start();
?>

<div style="max-width: 1400px; margin: 0 auto; padding: var(--spacing-lg);">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--spacing-lg);">
        <h1 style="color: var(--midnight-black); margin: 0;">System Alerts</h1>
        <a href="monitoring.php" style="color: var(--charcoal-grey); text-decoration: none;">
            ← Back to Monitoring
        </a>
    </div>

    <?php if (isset($error)): ?>
        <div style="background: #fee; border: 1px solid #fcc; color: #c33; padding: var(--spacing-md); border-radius: 4px; margin-bottom: var(--spacing-md);">
            <?php echo htmlspecialchars($error); ?>
        </div>
    <?php endif; ?>

    <?php if (isset($success)): ?>
        <div style="background: #efe; border: 1px solid #cfc; color: #3c3; padding: var(--spacing-md); border-radius: 4px; margin-bottom: var(--spacing-md);">
            <?php echo htmlspecialchars($success); ?>
        </div>
    <?php endif; ?>

    <!-- Statistics -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: var(--spacing-md); margin-bottom: var(--spacing-lg);">
        <div style="background: white; padding: var(--spacing-lg); border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); text-align: center;">
            <div style="font-size: 12px; color: var(--charcoal-grey); margin-bottom: var(--spacing-xs);">Active Alerts</div>
            <div style="font-size: 32px; font-weight: 600; color: var(--midnight-black);"><?php echo $alertStats['active_alerts']; ?></div>
        </div>
        <div style="background: white; padding: var(--spacing-lg); border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); text-align: center;">
            <div style="font-size: 12px; color: var(--charcoal-grey); margin-bottom: var(--spacing-xs);">Critical</div>
            <div style="font-size: 32px; font-weight: 600; color: #EF4444;"><?php echo $alertStats['critical_alerts']; ?></div>
        </div>
        <div style="background: white; padding: var(--spacing-lg); border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); text-align: center;">
            <div style="font-size: 12px; color: var(--charcoal-grey); margin-bottom: var(--spacing-xs);">High Priority</div>
            <div style="font-size: 32px; font-weight: 600; color: #FFA500;"><?php echo $alertStats['high_alerts']; ?></div>
        </div>
        <div style="background: white; padding: var(--spacing-lg); border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); text-align: center;">
            <div style="font-size: 12px; color: var(--charcoal-grey); margin-bottom: var(--spacing-xs);">Total (7d)</div>
            <div style="font-size: 32px; font-weight: 600; color: var(--midnight-black);"><?php echo $alertStats['total_alerts']; ?></div>
        </div>
    </div>

    <!-- Alerts List -->
    <?php if (empty($alerts)): ?>
        <div style="background: white; padding: var(--spacing-xl); border-radius: 8px; text-align: center; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
            <p style="color: var(--charcoal-grey); margin: 0;">No active alerts.</p>
        </div>
    <?php else: ?>
        <div style="display: grid; gap: var(--spacing-md);">
            <?php foreach ($alerts as $alert): ?>
                <?php
                $severityColors = [
                    'critical' => '#EF4444',
                    'high' => '#FFA500',
                    'medium' => '#FCD34D',
                    'low' => '#6B7280'
                ];
                $severityColor = $severityColors[$alert['severity']] ?? '#6B7280';
                ?>
                <div style="background: white; padding: var(--spacing-lg); border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); border-left: 4px solid <?php echo $severityColor; ?>;">
                    <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: var(--spacing-md);">
                        <div style="flex: 1;">
                            <div style="display: flex; gap: var(--spacing-sm); align-items: center; margin-bottom: var(--spacing-xs);">
                                <h3 style="color: var(--midnight-black); margin: 0; font-size: 16px;">
                                    <?php echo htmlspecialchars($alert['title']); ?>
                                </h3>
                                <span style="background: <?php echo $severityColor; ?>; color: white; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 500; text-transform: uppercase;">
                                    <?php echo htmlspecialchars($alert['severity']); ?>
                                </span>
                                <span style="background: var(--light-grey); color: var(--charcoal-grey); padding: 2px 8px; border-radius: 4px; font-size: 11px;">
                                    <?php echo htmlspecialchars($alert['alert_type']); ?>
                                </span>
                            </div>
                            <p style="color: var(--charcoal-grey); margin: var(--spacing-xs) 0 0 0; font-size: 14px;">
                                <?php echo htmlspecialchars($alert['message']); ?>
                            </p>
                            <div style="font-size: 12px; color: var(--charcoal-grey); margin-top: var(--spacing-sm);">
                                <strong>Created:</strong> <?php echo date('M j, Y g:i A', strtotime($alert['created_at'])); ?>
                            </div>
                        </div>
                        <div style="display: flex; gap: var(--spacing-sm);">
                            <form method="POST" style="margin: 0;">
                                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                <input type="hidden" name="alert_id" value="<?php echo $alert['id']; ?>">
                                <input type="hidden" name="action" value="acknowledge">
                                <button type="submit" style="background: var(--accent-blue); color: white; padding: var(--spacing-xs) var(--spacing-md); border: none; border-radius: 4px; cursor: pointer; font-size: 14px;">
                                    Acknowledge
                                </button>
                            </form>
                            <form method="POST" style="margin: 0;">
                                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                <input type="hidden" name="alert_id" value="<?php echo $alert['id']; ?>">
                                <input type="hidden" name="action" value="resolve">
                                <button type="submit" style="background: var(--green); color: white; padding: var(--spacing-xs) var(--spacing-md); border: none; border-radius: 4px; cursor: pointer; font-size: 14px;">
                                    Resolve
                                </button>
                            </form>
                        </div>
                    </div>
                    
                    <?php if (!empty($alert['metadata'])): ?>
                        <?php $metadata = json_decode($alert['metadata'], true); ?>
                        <?php if ($metadata): ?>
                            <details style="margin-top: var(--spacing-sm);">
                                <summary style="cursor: pointer; color: var(--accent-blue); font-size: 14px; font-weight: 500;">View Details</summary>
                                <pre style="background: var(--light-grey); padding: var(--spacing-sm); border-radius: 4px; margin-top: var(--spacing-sm); font-size: 12px; overflow-x: auto;"><?php echo htmlspecialchars(json_encode($metadata, JSON_PRETTY_PRINT)); ?></pre>
                            </details>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
