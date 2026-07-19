<?php
/**
 * Webhook Logs Page
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/index.php';

use CRM\Modules\Webhooks;
use CRM\Auth;
use CRM\Authorization;

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}
Authorization::requirePermission('settings.webhooks');

$webhooksModule = new Webhooks();

$webhookId = (int) ($_GET['id'] ?? 0);
if (!$webhookId) {
    header('Location: webhooks.php');
    exit;
}

$webhook = $webhooksModule->getById($webhookId);
if (!$webhook) {
    header('Location: webhooks.php?error=not_found');
    exit;
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = 50;
$offset = ($page - 1) * $limit;

$logs = $webhooksModule->getLogs($webhookId, $limit, $offset);

$pageTitle = 'Webhook Logs';
ob_start();
?>
<link rel="stylesheet" href="assets/css/premium-pages.css">
<link rel="stylesheet" href="assets/css/ops-logs-ui.css">

<div class="page-premium">
    <div class="container ops-workspace">
        <section class="ops-hero">
            <div>
                <div class="ops-kicker">Webhook delivery</div>
                <h1>Webhook Logs</h1>
                <p>
                    <strong><?php echo htmlspecialchars($webhook['name']); ?></strong>
                    <span class="ops-muted"> - <?php echo htmlspecialchars($webhook['url']); ?></span>
                </p>
            </div>
            <div class="ops-hero-actions">
                <a href="webhooks.php" class="btn-premium-secondary"><i class="fas fa-arrow-left"></i> Back to Webhooks</a>
            </div>
        </section>

        <section class="table-card">
            <div class="premium-section-header">
                <h2 class="ops-table-title">Delivery Timeline</h2>
            </div>

            <?php if (empty($logs)): ?>
                <div class="empty-state">
                    <p>No webhook logs found yet.</p>
                </div>
            <?php else: ?>
                <div class="table-card-scroll">
                    <table class="premium-table ops-timeline-table">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Event</th>
                                <th>Status</th>
                                <th>Response</th>
                                <th>Error</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log): ?>
                                <?php
                                $status = (int) ($log['response_status'] ?? 0);
                                if ($status >= 200 && $status < 300) {
                                    $statusClass = 'ops-status-badge--success';
                                    $statusLabel = $status . ' OK';
                                } elseif ($status >= 400) {
                                    $statusClass = 'ops-status-badge--danger';
                                    $statusLabel = $status . ' Error';
                                } elseif ($status > 0) {
                                    $statusClass = 'ops-status-badge--warning';
                                    $statusLabel = (string) $status;
                                } elseif (!empty($log['error_message'])) {
                                    $statusClass = 'ops-status-badge--danger';
                                    $statusLabel = 'Failed';
                                } else {
                                    $statusClass = 'ops-status-badge--pending';
                                    $statusLabel = 'Pending';
                                }
                                ?>
                                <tr>
                                    <td><?php echo date('M j, Y g:i:s A', strtotime($log['executed_at'])); ?></td>
                                    <td><span class="ops-status-badge ops-status-badge--neutral"><?php echo htmlspecialchars($log['event_type']); ?></span></td>
                                    <td><span class="ops-status-badge <?php echo $statusClass; ?>"><?php echo htmlspecialchars($statusLabel); ?></span></td>
                                    <td>
                                        <?php if ($log['response_body']): ?>
                                            <?php echo htmlspecialchars(substr($log['response_body'], 0, 100)); ?><?php if (strlen($log['response_body']) > 100): ?>...<?php endif; ?>
                                        <?php else: ?>
                                            <span class="ops-dimmed">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($log['error_message']): ?>
                                            <span class="ops-status-badge ops-status-badge--danger">
                                                <?php echo htmlspecialchars(substr($log['error_message'], 0, 100)); ?><?php if (strlen($log['error_message']) > 100): ?>...<?php endif; ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="ops-dimmed">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button type="button" onclick="viewLogDetails(<?php echo $log['id']; ?>)" class="btn-premium-primary btn-premium-sm">
                                            View Details
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>

<!-- Log Details Modal -->
<div id="log-modal" class="ops-modal" style="display: none;">
    <div class="ops-modal-card">
        <div class="ops-modal-header">
            <h2 class="ops-modal-title">Webhook Log Details</h2>
            <button type="button" onclick="document.getElementById('log-modal').style.display='none'" class="ops-modal-close" aria-label="Close log details">
                &times;
            </button>
        </div>
        <div id="log-details-content" class="ops-modal-body"></div>
    </div>
</div>

<script>
const logData = <?php echo json_encode($logs); ?>;

function viewLogDetails(logId) {
    const log = logData.find(l => l.id == logId);
    if (!log) return;

    const content = document.getElementById('log-details-content');
    content.innerHTML = `
        <div class="ops-modal-item">
            <strong class="ops-modal-item-label">Event Type:</strong>
            <div class="ops-modal-value">${escapeHtml(log.event_type)}</div>
        </div>

        <div class="ops-modal-item">
            <strong class="ops-modal-item-label">Executed At:</strong>
            <div class="ops-modal-value">${escapeHtml(log.executed_at)}</div>
        </div>

        <div class="ops-modal-item">
            <strong class="ops-modal-item-label">Response Status:</strong>
            <div class="ops-modal-value">${log.response_status || 'N/A'}</div>
        </div>

        ${log.error_message ? `
        <div class="ops-modal-item">
            <strong class="ops-modal-item-label">Error Message:</strong>
            <div class="ops-modal-value ops-modal-value--danger">${escapeHtml(log.error_message)}</div>
        </div>
        ` : ''}

        <div class="ops-modal-item">
            <strong class="ops-modal-item-label">Payload:</strong>
            <pre class="ops-code-block ops-code-block--scroll">${escapeHtml(JSON.stringify(log.payload || {}, null, 2))}</pre>
        </div>

        ${log.response_body ? `
        <div class="ops-modal-item">
            <strong class="ops-modal-item-label">Response Body:</strong>
            <pre class="ops-code-block ops-code-block--scroll">${escapeHtml(log.response_body)}</pre>
        </div>
        ` : ''}
    `;

    document.getElementById('log-modal').style.display = 'flex';
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
