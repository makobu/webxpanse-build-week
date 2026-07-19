<?php
/**
 * Emails List Page
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
use CRM\Services\DemoSessionScopeService;
use CRM\Services\EmailService;
use CRM\Services\WorkspaceCommunicationGateService;
use CRM\Services\WorkspaceScopeService;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

// Require authentication
if (!Auth::check()) {
    header('Location: ' . publicUrl('login.php'));
    exit;
}

$workspaceId = (new WorkspaceScopeService())->requireActiveWorkspaceId();
(new WorkspaceCommunicationGateService())->enforceWebRuntime($workspaceId, Auth::user());

$demoScope = new DemoSessionScopeService();
$emailScopeWhere = ['e.workspace_id = ?'];
$emailScopeParams = [$workspaceId];
$emailVisibility = $demoScope->visibilityClause('e', $workspaceId);
if ((string) ($emailVisibility['sql'] ?? '') !== '') {
    $emailScopeWhere[] = '(' . (string) $emailVisibility['sql'] . ')';
    $emailScopeParams = array_merge($emailScopeParams, (array) ($emailVisibility['params'] ?? []));
}

$actionError = null;
$actionSuccess = !empty($_GET['notice']) ? trim((string) $_GET['notice']) : null;
$canManageEmailAssistant = Authorization::can('settings.email_assistant');

// Server-side fallback actions (works even if JS/api path has issues).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManageEmailAssistant) {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $actionError = 'Invalid security token. Please refresh and try again.';
    } else {
        $emailService = new EmailService();
        try {
            if (isset($_POST['send_single'])) {
                $emailId = (int) ($_POST['email_id'] ?? 0);
                $emailUuid = trim((string) ($_POST['email_uuid'] ?? ''));
                $lastError = null;

                if ($emailId > 0) {
                    $selectedWhere = array_merge($emailScopeWhere, ['e.id = ?', "e.status = 'pending'"]);
                    $selectedParams = array_merge($emailScopeParams, [$emailId]);
                    $selectedEmail = Database::queryOne(
                        'SELECT e.id, e.uuid FROM emails e WHERE ' . implode(' AND ', $selectedWhere) . ' LIMIT 1',
                        $selectedParams
                    );
                    $ok = $selectedEmail
                        ? $emailService->processEmail((int) $selectedEmail['id'], $lastError, [], $workspaceId)
                        : false;
                    if (!$selectedEmail) {
                        $lastError = 'Email not found in the active workspace or already processed.';
                    }
                } elseif ($emailUuid !== '') {
                    $selectedWhere = array_merge($emailScopeWhere, ['e.uuid = ?', "e.status = 'pending'"]);
                    $selectedParams = array_merge($emailScopeParams, [$emailUuid]);
                    $selectedEmail = Database::queryOne(
                        'SELECT e.id, e.uuid FROM emails e WHERE ' . implode(' AND ', $selectedWhere) . ' LIMIT 1',
                        $selectedParams
                    );
                    $ok = $selectedEmail
                        ? $emailService->processEmailByUuid((string) $selectedEmail['uuid'], $lastError, [], $workspaceId)
                        : false;
                    if (!$selectedEmail) {
                        $lastError = 'Email not found in the active workspace or already processed.';
                    }
                } else {
                    $ok = false;
                    $lastError = 'Invalid email selected.';
                }

                if ($ok) {
                    $actionSuccess = 'Email sent successfully.';
                } else {
                    $actionError = $lastError ?: 'Failed to send email.';
                }
            } elseif (isset($_POST['send_all'])) {
                $limit = max(1, min(200, (int) ($_POST['limit'] ?? 50)));
                // Process all pending emails regardless of queue rows.
                // Queue metadata can drift after imports/migrations and should not block manual "Send All".
                $rows = Database::query(
                    "SELECT e.id, e.uuid
                     FROM emails e
                     WHERE " . implode(' AND ', array_merge($emailScopeWhere, ["e.status = 'pending'"])) . "
                     ORDER BY e.created_at DESC, e.id DESC
                     LIMIT ?",
                    array_merge($emailScopeParams, [$limit])
                );

                $processed = 0;
                $failed = 0;
                foreach ($rows as $row) {
                    $emailId = (int) ($row['id'] ?? 0);
                    $emailUuid = trim((string) ($row['uuid'] ?? ''));
                    if ($emailId <= 0 && $emailUuid === '') {
                        $failed++;
                        continue;
                    }
                    $lastError = null;
                    $ok = $emailId > 0
                        ? $emailService->processEmail($emailId, $lastError, [], $workspaceId)
                        : $emailService->processEmailByUuid($emailUuid, $lastError, [], $workspaceId);
                    if ($ok) {
                        $processed++;
                    } else {
                        $failed++;
                    }
                }

                $actionSuccess = "Processed {$processed} email(s)." . ($failed > 0 ? " {$failed} failed." : '');
            }
        } catch (\Throwable $e) {
            $actionError = $e->getMessage();
        }
    }
}

// Handle filters
$status = $_GET['status'] ?? '';
$contactId = (int) ($_GET['contact_id'] ?? 0);
$search = $_GET['search'] ?? '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = 30;
$offset = ($page - 1) * $limit;

// Build query
$where = $emailScopeWhere;
$params = $emailScopeParams;

if ($status) {
    $where[] = "e.status = ?";
    $params[] = $status;
}

if ($contactId) {
    $where[] = "e.contact_id = ?";
    $params[] = $contactId;
}

if ($search) {
    $where[] = "(e.subject LIKE ? OR e.to_email LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ?)";
    $searchTerm = '%' . $search . '%';
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

$whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

// Get emails
$emails = Database::query(
    "SELECT e.*, c.first_name, c.last_name, c.email as contact_email
     FROM emails e
     LEFT JOIN contacts c ON e.contact_id = c.id AND c.workspace_id = e.workspace_id
     $whereClause
     ORDER BY e.created_at DESC
     LIMIT ? OFFSET ?",
    array_merge($params, [$limit, $offset])
);

// Get total count
$totalRow = Database::queryOne(
    "SELECT COUNT(*) as count
     FROM emails e
     LEFT JOIN contacts c ON e.contact_id = c.id AND c.workspace_id = e.workspace_id
     $whereClause",
    $params
);
$totalEmails = (int) ($totalRow['count'] ?? 0);

$totalPages = ceil($totalEmails / $limit);

// Get status counts
$statusCounts = Database::query(
    "SELECT e.status, COUNT(*) as count
     FROM emails e
     WHERE " . implode(' AND ', $emailScopeWhere) . "
     GROUP BY e.status",
    $emailScopeParams
);
$statusStats = [];
foreach ($statusCounts as $stat) {
    $statusStats[$stat['status']] = $stat['count'];
}

// Get contacts for filter
$contacts = Database::query(
    "SELECT id, first_name, last_name, email
     FROM contacts
     WHERE workspace_id = ?
     ORDER BY first_name, last_name
     LIMIT 100",
    [$workspaceId]
);

$pageTitle = 'Emails - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="<?php echo assetUrl('css/premium-pages.css'); ?>">

<div class="page-premium">
    <div class="container">
        <!-- Page Header -->
        <div class="page-header">
            <div>
                <h1>Emails</h1>
                <p>Manage your email communications</p>
            </div>
            <div class="page-header-actions">
                <?php if (($statusStats['pending'] ?? 0) > 0 && $canManageEmailAssistant): ?>
                    <form method="POST" style="margin: 0;">
                        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                        <input type="hidden" name="limit" value="100">
                        <button 
                            type="submit"
                            name="send_all"
                            class="btn-premium-primary"
                            style="background: #10b981;"
                            id="process-all-btn"
                            onclick="return confirm('Send all pending emails now?');"
                        >
                            <i class="fas fa-paper-plane"></i>
                            Send All Pending (<?php echo $statusStats['pending']; ?>)
                        </button>
                    </form>
                <?php endif; ?>
                <a href="<?php echo publicUrl('email_compose.php'); ?>" class="btn-premium-primary">
                    <i class="fas fa-plus"></i>
                    Compose Email
                </a>
            </div>
        </div>

        <?php if ($actionError): ?>
            <div style="background: #fee; border: 1px solid #fcc; color: #c33; padding: 12px 14px; border-radius: 8px; margin-bottom: 16px;">
                <?php echo htmlspecialchars($actionError); ?>
            </div>
        <?php endif; ?>
        <?php if ($actionSuccess): ?>
            <div style="background: #ecfdf5; border: 1px solid #10b98155; color: #065f46; padding: 12px 14px; border-radius: 8px; margin-bottom: 16px;">
                <?php echo htmlspecialchars($actionSuccess); ?>
            </div>
        <?php endif; ?>

        <!-- Search and Filters -->
        <div class="filters-card">
            <form method="GET" action="" class="filters-form">
                <div class="filter-group">
                    <label for="search">Search</label>
                    <input 
                        type="text" 
                        id="search" 
                        name="search" 
                        value="<?php echo htmlspecialchars($search); ?>"
                        placeholder="Search by subject, email, or contact..."
                    >
                </div>
                <div class="filter-group">
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <option value="">All Statuses</option>
                        <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>>Pending</option>
                        <option value="sent" <?php echo $status === 'sent' ? 'selected' : ''; ?>>Sent</option>
                        <option value="delivered" <?php echo $status === 'delivered' ? 'selected' : ''; ?>>Delivered</option>
                        <option value="opened" <?php echo $status === 'opened' ? 'selected' : ''; ?>>Opened</option>
                        <option value="clicked" <?php echo $status === 'clicked' ? 'selected' : ''; ?>>Clicked</option>
                        <option value="bounced" <?php echo $status === 'bounced' ? 'selected' : ''; ?>>Bounced</option>
                        <option value="failed" <?php echo $status === 'failed' ? 'selected' : ''; ?>>Failed</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="contact_id">Contact</label>
                    <select id="contact_id" name="contact_id">
                        <option value="">All Contacts</option>
                        <?php foreach ($contacts as $contact): ?>
                            <option value="<?php echo $contact['id']; ?>" <?php echo $contactId === (int) $contact['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($contact['first_name'] . ' ' . $contact['last_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-actions">
                    <button type="submit" class="btn-premium-primary">
                        <i class="fas fa-filter"></i>
                        Filter
                    </button>
                    <?php if ($search || $status || $contactId): ?>
                        <a href="<?php echo publicUrl('emails.php'); ?>" class="btn-premium-secondary">
                            Clear
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <!-- Status Stats -->
        <div class="status-stats">
            <?php 
            $statuses = ['pending', 'sent', 'delivered', 'opened', 'clicked', 'bounced', 'failed'];
            $statusLabels = ['Pending', 'Sent', 'Delivered', 'Opened', 'Clicked', 'Bounced', 'Failed'];
            foreach ($statuses as $index => $statusName): 
                $count = $statusStats[$statusName] ?? 0;
                $isActive = $status === $statusName;
            ?>
                <a href="?status=<?php echo $statusName; ?>" class="status-stat <?php echo $isActive ? 'active' : ''; ?>">
                    <?php echo $statusLabels[$index]; ?> (<?php echo $count; ?>)
                </a>
            <?php endforeach; ?>
        </div>

        <!-- Emails List -->
        <div class="table-card">
            <?php if (empty($emails)): ?>
                <div class="empty-state">
                    <p>No emails found.</p>
                    <a href="<?php echo publicUrl('email_compose.php'); ?>">
                        Send your first email →
                    </a>
                </div>
            <?php else: ?>
                <div style="display: flex; flex-direction: column;">
                    <?php foreach ($emails as $email): ?>
                        <div style="padding: 1rem; border-bottom: 1px solid rgba(0, 0, 0, 0.1); display: flex; gap: 1rem; align-items: start; transition: background 0.2s ease;">
                            <div style="flex: 1;">
                                <div style="display: flex; align-items: center; gap: 0.75rem; margin-bottom: 0.5rem; flex-wrap: wrap;">
                                    <span class="badge badge-default">
                                        <?php echo htmlspecialchars(ucfirst($email['status'])); ?>
                                    </span>
                                    <a href="<?php echo publicUrl('contact_view.php'); ?>?id=<?php echo $email['contact_id']; ?>" style="color: #667eea; text-decoration: none; font-weight: 500;">
                                        <?php echo htmlspecialchars(($email['first_name'] ?? '') . ' ' . ($email['last_name'] ?? '')); ?>
                                    </a>
                                    <span style="color: #64748b;">•</span>
                                    <span style="color: #64748b; font-size: 0.875rem;">
                                        <?php echo htmlspecialchars($email['to_email']); ?>
                                    </span>
                                </div>
                                <div style="font-weight: 500; color: #0f172a; margin-bottom: 0.5rem;">
                                    <?php echo htmlspecialchars($email['subject']); ?>
                                </div>
                                <?php if ($email['body']): ?>
                                    <div style="color: #64748b; font-size: 0.875rem; margin-bottom: 0.5rem; max-height: 60px; overflow: hidden;">
                                        <?php echo htmlspecialchars(substr(strip_tags($email['body']), 0, 150)); ?>...
                                    </div>
                                <?php endif; ?>
                                <div style="color: #64748b; font-size: 0.75rem;">
                                    <?php echo date('M j, Y g:i A', strtotime($email['created_at'])); ?>
                                    <?php if ($email['sent_at']): ?>
                                        • Sent: <?php echo date('M j, Y g:i A', strtotime($email['sent_at'])); ?>
                                    <?php endif; ?>
                                    <?php if ($email['opened_at']): ?>
                                        • Opened: <?php echo date('M j, Y g:i A', strtotime($email['opened_at'])); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div style="display: flex; gap: 0.75rem; align-items: center;">
                                <?php if ($email['status'] === 'pending' && $canManageEmailAssistant): ?>
                                    <form method="POST" style="margin: 0;">
                                        <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                                        <input type="hidden" name="email_id" value="<?php echo (int) $email['id']; ?>">
                                        <input type="hidden" name="email_uuid" value="<?php echo htmlspecialchars((string) ($email['uuid'] ?? '')); ?>">
                                        <button 
                                            type="submit"
                                            name="send_single"
                                            value="1"
                                            class="btn-premium-primary"
                                            style="padding: 0.5rem 0.75rem; font-size: 0.8125rem;"
                                        >
                                            Send Now
                                        </button>
                                    </form>
                                <?php endif; ?>
                                <a href="<?php echo publicUrl('email_view.php'); ?>?id=<?php echo $email['id']; ?>" style="color: #667eea; text-decoration: none; font-size: 0.875rem;">View</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <!-- Pagination -->
                <?php if ($totalPages > 1): ?>
                    <div class="pagination">
                        <div class="pagination-info">
                            Showing <?php echo $offset + 1; ?>-<?php echo min($offset + $limit, $totalEmails); ?> of <?php echo $totalEmails; ?> emails
                        </div>
                        <div class="pagination-controls">
                            <?php if ($page > 1): ?>
                                <a href="?page=<?php echo $page - 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $status ? '&status=' . urlencode($status) : ''; ?><?php echo $contactId ? '&contact_id=' . $contactId : ''; ?>" class="pagination-link">Previous</a>
                            <?php endif; ?>
                            
                            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                                <a href="?page=<?php echo $i; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $status ? '&status=' . urlencode($status) : ''; ?><?php echo $contactId ? '&contact_id=' . $contactId : ''; ?>" class="pagination-link <?php echo $i === $page ? 'active' : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>
                            
                            <?php if ($page < $totalPages): ?>
                                <a href="?page=<?php echo $page + 1; ?><?php echo $search ? '&search=' . urlencode($search) : ''; ?><?php echo $status ? '&status=' . urlencode($status) : ''; ?><?php echo $contactId ? '&contact_id=' . $contactId : ''; ?>" class="pagination-link">Next</a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
var processEmailsUrl = '<?php echo apiUrl('process_emails.php'); ?>';
var csrfToken = '<?php echo addslashes(\CRM\Security::getCsrfToken()); ?>';

async function sendEmail(event, emailId) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }
    const btn = document.getElementById('send-btn-' + emailId);
    if (!btn) return;
    
    const originalText = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Sending...';
    
    try {
        const formData = new FormData();
        formData.append('email_id', emailId);
        formData.append('csrf_token', csrfToken);
        
        const response = await fetch(processEmailsUrl, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: {
                'X-CSRF-Token': csrfToken,
                'X-Requested-With': 'XMLHttpRequest'
            }
        });

        const contentType = response.headers.get('content-type') || '';
        if (!contentType.includes('application/json')) {
            const text = await response.text();
            throw new Error('Invalid server response: ' + text.substring(0, 160));
        }

        const result = await response.json();
        
        if (result.success) {
            btn.textContent = 'Sent!';
            btn.style.background = '#28a745';
            setTimeout(() => {
                location.reload();
            }, 1000);
        } else {
            alert('Error: ' + (result.error || 'Failed to send email'));
            btn.disabled = false;
            btn.textContent = originalText;
        }
    } catch (error) {
        alert('Error: ' + error.message);
        btn.disabled = false;
        btn.textContent = originalText;
    }
    return false;
}

async function processAllPending(event) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }
    const btn = document.getElementById('process-all-btn');
    if (!btn) return;
    
    if (!confirm('Send all pending emails? This may take a moment.')) {
        return false;
    }
    
    const originalText = btn.textContent;
    btn.disabled = true;
    btn.textContent = 'Processing...';
    
    try {
        const formData = new FormData();
        formData.append('limit', '50');
        formData.append('csrf_token', csrfToken);
        
        const response = await fetch(processEmailsUrl, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: {
                'X-CSRF-Token': csrfToken,
                'X-Requested-With': 'XMLHttpRequest'
            }
        });

        const contentType = response.headers.get('content-type') || '';
        if (!contentType.includes('application/json')) {
            const text = await response.text();
            throw new Error('Invalid server response: ' + text.substring(0, 160));
        }

        const result = await response.json();
        
        if (result.success) {
            alert(`Processed ${result.processed} email(s). ${result.failed > 0 ? result.failed + ' failed.' : ''}`);
            location.reload();
        } else {
            alert('Error: ' + (result.error || 'Failed to process emails'));
            btn.disabled = false;
            btn.textContent = originalText;
        }
    } catch (error) {
        alert('Error: ' + error.message);
        btn.disabled = false;
        btn.textContent = originalText;
    }
    return false;
}
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
