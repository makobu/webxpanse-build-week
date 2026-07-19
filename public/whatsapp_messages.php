<?php
/**
 * WhatsApp Messages View
 * View sent WhatsApp messages and their status
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
use CRM\Security;
use CRM\Services\WorkspaceCommunicationGateService;
use CRM\Services\WorkspaceContext;
use CRM\Services\WhatsAppQueueProcessor;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

// Require authentication
if (!Auth::check()) {
    header('Location: login.php');
    exit;
}
Authorization::requirePermission('whatsapp.messages.view');

(new WorkspaceCommunicationGateService())->enforceWebRuntime((int) (WorkspaceContext::currentWorkspaceId() ?? 0), Auth::user());
$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
if ($workspaceId <= 0) {
    http_response_code(409);
    exit('An active workspace is required.');
}

// Get messages
$actionNotice = !empty($_GET['notice']) ? trim((string) $_GET['notice']) : null;
$limit = (int) ($_GET['limit'] ?? 50);
$offset = (int) ($_GET['offset'] ?? 0);
$contactId = isset($_GET['contact_id']) ? (int) $_GET['contact_id'] : null;
$direction = $_GET['direction'] ?? 'all'; // 'all', 'inbound', 'outbound'

$whereClause = "WHERE wm.workspace_id = ?";
$params = [$workspaceId];
if ($direction !== 'all') {
    $whereClause .= " AND direction = ?";
    $params[] = $direction;
}
if ($contactId) {
    $whereClause .= " AND contact_id = ?";
    $params[] = $contactId;
}

$messages = Database::query(
    "SELECT wm.*, c.first_name, c.last_name, c.phone as contact_phone, u.email as user_email
     FROM whatsapp_messages wm
     LEFT JOIN contacts c ON c.workspace_id = wm.workspace_id AND wm.contact_id = c.id
     LEFT JOIN users u ON wm.user_id = u.id
     {$whereClause}
     ORDER BY wm.created_at DESC
     LIMIT ? OFFSET ?",
    array_merge($params, [$limit, $offset])
);

$totalMessages = Database::queryOne(
    "SELECT COUNT(*) as total FROM whatsapp_messages wm {$whereClause}",
    $params
)['total'] ?? 0;

$pendingCount = 0;
try {
    $processor = new WhatsAppQueueProcessor();
    $pendingCount = $processor->getPendingCount();
} catch (\Throwable $e) {
    // Ignore if queue not available
}

$pageTitle = 'WhatsApp Messages - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">

<div class="page-premium">
    <div class="container">
        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h1>WhatsApp Messages</h1>
                <p>View all WhatsApp messages (sent and received)</p>
            </div>
            <div class="page-header-actions" style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                <?php if ($pendingCount > 0): ?>
                <button type="button" id="btn-send-pending" class="btn-premium-secondary" style="background: #f59e0b; color: white; border: none;">
                    <i class="fas fa-paper-plane"></i>
                    Send all <?php echo $pendingCount; ?> pending
                </button>
                <button type="button" id="btn-clear-pending" class="btn-premium-secondary" style="background: #6b7280; color: white; border: none;">
                    <i class="fas fa-trash-alt"></i>
                    Clear pending
                </button>
                <?php endif; ?>
                <a href="whatsapp_compose.php" class="btn-premium-primary" style="background: #25D366;">
                    <i class="fab fa-whatsapp"></i>
                    Send Message
                </a>
            </div>
        </div>

        <?php if ($actionNotice): ?>
        <div class="alert alert-success" style="margin-bottom:1rem;">
            <?php echo htmlspecialchars($actionNotice); ?>
        </div>
        <?php endif; ?>

        <?php if ($pendingCount > 0): ?>
        <div id="pending-banner" style="background: #fef3c7; border: 1px solid #f59e0b; padding: 1rem; border-radius: 8px; margin-bottom: 1rem; color: #92400e;">
            <strong><?php echo $pendingCount; ?> message<?php echo $pendingCount !== 1 ? 's' : ''; ?> waiting to be sent.</strong>
            Click "Send all" above to deliver them.
        </div>

        <!-- Progress panel (hidden until sending starts) -->
        <div id="progress-panel" style="display: none; background: #f0fdf4; border: 1px solid #22c55e; padding: 1rem 1.25rem; border-radius: 8px; margin-bottom: 1rem;">
            <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.75rem;">
                <div>
                    <div style="font-weight: 600; color: #166534; margin-bottom: 0.25rem;">
                        <i class="fas fa-spinner fa-spin" style="margin-right: 0.5rem;"></i>Sending messages...
                    </div>
                    <div id="progress-text" style="font-size: 0.875rem; color: #15803d;">0 sent, <?php echo $pendingCount; ?> remaining</div>
                    <div style="margin-top: 0.5rem; width: 100%; max-width: 400px; height: 6px; background: #dcfce7; border-radius: 3px; overflow: hidden;">
                        <div id="progress-bar" style="height: 100%; background: #22c55e; width: 0%; transition: width 0.3s ease;"></div>
                    </div>
                </div>
                <button type="button" id="btn-stop-send" style="padding: 0.5rem 1rem; background: #ef4444; color: white; border: none; border-radius: 6px; font-weight: 500; cursor: pointer;">
                    <i class="fas fa-stop"></i> Stop
                </button>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Direction Filter -->
        <div class="stage-stats">
            <a href="?direction=all" class="stage-stat <?php echo $direction === 'all' ? 'active' : ''; ?>">
                All Messages
            </a>
            <a href="?direction=inbound" class="stage-stat <?php echo $direction === 'inbound' ? 'active' : ''; ?>">
                Incoming
            </a>
            <a href="?direction=outbound" class="stage-stat <?php echo $direction === 'outbound' ? 'active' : ''; ?>">
                Sent
            </a>
        </div>

        <div class="table-card">
            <div style="margin-bottom: 1rem; color: #64748b; font-size: 0.875rem;">
                Showing <?php echo count($messages); ?> of <?php echo $totalMessages; ?> messages
            </div>

            <?php if (empty($messages)): ?>
                <div class="empty-state">
                    <p>No WhatsApp messages found.</p>
                    <a href="whatsapp_compose.php">Send your first message →</a>
                </div>
            <?php else: ?>
                <table class="premium-table">
                    <thead>
                        <tr>
                            <th>Contact</th>
                            <th>Phone</th>
                            <th>Type</th>
                            <th>Message</th>
                            <th>Status</th>
                            <th>Message ID</th>
                            <th>Sent</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($messages as $msg): ?>
                            <tr>
                                <td>
                                    <?php if ($msg['contact_id']): ?>
                                        <a href="contact_view.php?id=<?php echo $msg['contact_id']; ?>" style="color: #667eea; text-decoration: none;">
                                            <?php echo htmlspecialchars(($msg['first_name'] ?? '') . ' ' . ($msg['last_name'] ?? '')); ?>
                                        </a>
                                    <?php else: ?>
                                        <span style="color: #64748b;">Unknown</span>
                                    <?php endif; ?>
                                </td>
                                <td style="color: #64748b; font-size: 0.8125rem;">
                                    <?php if ($msg['direction'] === 'inbound'): ?>
                                        <span style="color: #25D366;">From: <?php echo htmlspecialchars($msg['from_number'] ?? 'N/A'); ?></span>
                                    <?php else: ?>
                                        To: <?php echo htmlspecialchars($msg['to_number']); ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge badge-default">
                                        <?php echo htmlspecialchars(ucfirst($msg['message_type'])); ?>
                                    </span>
                                </td>
                                <td style="max-width: 300px;">
                                    <div style="color: #0f172a; font-size: 0.8125rem;">
                                        <?php if ($msg['message_type'] === 'template'): ?>
                                            <strong>Template:</strong> <?php echo htmlspecialchars($msg['template_name'] ?? 'N/A'); ?>
                                        <?php else: ?>
                                            <?php echo htmlspecialchars(substr($msg['message_body'] ?? '', 0, 100)); ?>
                                            <?php if (strlen($msg['message_body'] ?? '') > 100): ?>...<?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <?php
                                    $status = $msg['status'] ?? 'pending';
                                    $statusColors = [
                                        'sent' => '#10b981',
                                        'delivered' => '#3b82f6',
                                        'read' => '#667eea',
                                        'failed' => '#ef4444',
                                        'pending' => '#f59e0b'
                                    ];
                                    $statusColor = $statusColors[$status] ?? '#6b7280';
                                    ?>
                                    <span class="badge" style="background: <?php echo $statusColor; ?>20; color: <?php echo $statusColor; ?>; font-size: 0.75rem;">
                                        <?php echo strtoupper($status); ?>
                                    </span>
                                    <?php if ($msg['error_message']): ?>
                                        <div style="color: #ef4444; font-size: 0.6875rem; margin-top: 0.25rem;">
                                            <?php echo htmlspecialchars(substr($msg['error_message'], 0, 50)); ?>...
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size: 0.6875rem; color: #64748b; font-family: monospace;">
                                    <?php if ($msg['whatsapp_message_id']): ?>
                                        <?php echo htmlspecialchars(substr($msg['whatsapp_message_id'], 0, 30)); ?>...
                                    <?php else: ?>
                                        <span style="color: #64748b;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td style="font-size: 0.75rem; color: #64748b;">
                                    <?php if ($msg['sent_at']): ?>
                                        <?php echo date('M j, Y g:i A', strtotime($msg['sent_at'])); ?>
                                    <?php else: ?>
                                        <span style="color: #64748b;">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($pendingCount > 0): ?>
<script>
(function() {
    const csrfToken = '<?php echo Security::getCsrfToken(); ?>';
    const initialPending = <?php echo $pendingCount; ?>;
    let stopRequested = false;
    let totalSent = 0;

    function updateProgress(sent, remaining) {
        totalSent += sent;
        const total = totalSent + remaining;
        const pct = total > 0 ? Math.round((totalSent / total) * 100) : 100;
        document.getElementById('progress-text').textContent = totalSent + ' sent, ' + remaining + ' remaining';
        document.getElementById('progress-bar').style.width = pct + '%';
    }

    async function processBatch() {
        if (stopRequested) {
            document.getElementById('progress-panel').style.display = 'none';
            document.getElementById('btn-send-pending').disabled = false;
            document.getElementById('btn-send-pending').innerHTML = '<i class="fas fa-paper-plane"></i> Send all ' + (totalSent > 0 ? 'remaining' : initialPending + ' pending');
            alert('Stopped. ' + totalSent + ' message(s) sent.');
            location.reload();
            return;
        }
        try {
            const res = await fetch('../api/process_whatsapp_queue.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'limit=15&csrf_token=' + encodeURIComponent(csrfToken),
                credentials: 'same-origin'
            });
            const data = await res.json();
            if (!data.success) {
                document.getElementById('progress-panel').style.display = 'none';
                document.getElementById('btn-send-pending').disabled = false;
                document.getElementById('btn-send-pending').innerHTML = '<i class="fas fa-paper-plane"></i> Send all pending';
                alert(data.error || 'Failed to process queue');
                return;
            }
            const remaining = data.pending_after || 0;
            updateProgress(data.sent || 0, remaining);
            if (remaining > 0 && !stopRequested) {
                setTimeout(processBatch, 500);
            } else {
                document.getElementById('progress-panel').style.display = 'none';
                document.getElementById('btn-send-pending').disabled = false;
                document.getElementById('btn-send-pending').innerHTML = '<i class="fas fa-paper-plane"></i> Send all pending';
                alert(stopRequested ? 'Stopped. ' + totalSent + ' message(s) sent.' : (data.message || 'Sent ' + totalSent + ' message(s).'));
                location.reload();
            }
        } catch (e) {
            document.getElementById('progress-panel').style.display = 'none';
            document.getElementById('btn-send-pending').disabled = false;
            document.getElementById('btn-send-pending').innerHTML = '<i class="fas fa-paper-plane"></i> Send all pending';
            alert('Error: ' + e.message);
        }
    }

    document.getElementById('btn-send-pending').addEventListener('click', function() {
        const btn = this;
        btn.disabled = true;
        stopRequested = false;
        totalSent = 0;
        document.getElementById('progress-panel').style.display = 'block';
        document.getElementById('progress-text').textContent = '0 sent, ' + initialPending + ' remaining';
        document.getElementById('progress-bar').style.width = '0%';
        processBatch();
    });

    document.getElementById('btn-stop-send').addEventListener('click', function() {
        stopRequested = true;
    });

    document.getElementById('btn-clear-pending').addEventListener('click', async function() {
        if (!confirm('Clear all ' + initialPending + ' pending message(s)? They will not be sent and will be marked as cancelled.')) {
            return;
        }
        const btn = this;
        btn.disabled = true;
        try {
            const formData = new URLSearchParams();
            formData.append('csrf_token', csrfToken);
            const res = await fetch('../api/clear_whatsapp_queue.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData.toString(),
                credentials: 'same-origin'
            });
            const data = await res.json();
            if (data.success) {
                alert(data.message || 'Cleared ' + data.cleared + ' pending message(s).');
                location.reload();
            } else {
                btn.disabled = false;
                alert(data.error || 'Failed to clear queue');
            }
        } catch (e) {
            btn.disabled = false;
            alert('Error: ' + e.message);
        }
    });
})();
</script>
<?php endif; ?>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
