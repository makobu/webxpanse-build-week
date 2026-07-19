<?php
/**
 * Email Import Audit Page
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
use CRM\Modules\EmailFetchAudit;
use CRM\Session;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}
if (!Authorization::can('settings.monitoring', Auth::user())) {
    header('Location: dashboard.php');
    exit;
}

$audit = new EmailFetchAudit();
$filters = array_filter([
    'outcome' => $_GET['outcome'] ?? null,
    'sender_email' => trim((string) ($_GET['sender_email'] ?? '')) ?: null,
    'reason_code' => $_GET['reason_code'] ?? null,
    'date_from' => $_GET['date_from'] ?? null,
    'date_to' => $_GET['date_to'] ?? null,
], static fn($value) => $value !== null && $value !== '');

$page = max(1, (int) ($_GET['page'] ?? 1));
$limit = 50;
$offset = ($page - 1) * $limit;

$summary = $audit->getSummary();
$entries = $audit->getEntries($filters, $limit, $offset);
$totalEntries = $audit->getCount($filters);
$totalPages = max(1, (int) ceil($totalEntries / $limit));
$reasonCodes = $audit->getReasonCodes();

$pageTitle = 'Skipped Email Imports';
ob_start();
?>
<link rel="stylesheet" href="assets/css/premium-pages.css">
<link rel="stylesheet" href="assets/css/ops-logs-ui.css">

<div class="page-premium">
    <div class="container ops-workspace">
        <section class="ops-hero">
            <div>
                <div class="ops-kicker">Email fetch audit</div>
                <h1>Skipped Email Imports</h1>
                <p>Operational audit for fetch-time import outcomes. Historical skipped popup counts from before this feature were not persisted.</p>
            </div>
            <div class="ops-hero-actions">
                <a href="monitoring.php" class="btn-premium-secondary"><i class="fas fa-arrow-left"></i> Back to Monitoring</a>
            </div>
        </section>

        <section class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Skipped (24h)</div>
                <div class="stat-value ops-stat-value--warning"><?php echo $summary['skipped_24h']; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Skipped (7d)</div>
                <div class="stat-value ops-stat-value--warning"><?php echo $summary['skipped_7d']; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Auto-created (24h)</div>
                <div class="stat-value ops-stat-value--info"><?php echo $summary['auto_created_24h']; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Auto-created (7d)</div>
                <div class="stat-value ops-stat-value--info"><?php echo $summary['auto_created_7d']; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Failed Imports (7d)</div>
                <div class="stat-value ops-stat-value--danger"><?php echo $summary['failed_7d']; ?></div>
            </div>
        </section>

        <section class="ops-filter-card">
            <form method="GET" class="ops-filter-grid">
                <div class="ops-field">
                    <label for="outcome" class="ops-field-label">Outcome</label>
                    <select id="outcome" name="outcome">
                        <option value="">All outcomes</option>
                        <?php foreach (['processed', 'auto_created', 'skipped', 'error'] as $outcome): ?>
                            <option value="<?php echo $outcome; ?>" <?php echo (($filters['outcome'] ?? '') === $outcome) ? 'selected' : ''; ?>><?php echo ucwords(str_replace('_', ' ', $outcome)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="ops-field">
                    <label for="sender_email" class="ops-field-label">Sender Email</label>
                    <input id="sender_email" name="sender_email" value="<?php echo htmlspecialchars((string) ($filters['sender_email'] ?? '')); ?>" placeholder="name@example.com">
                </div>
                <div class="ops-field">
                    <label for="reason_code" class="ops-field-label">Reason Code</label>
                    <select id="reason_code" name="reason_code">
                        <option value="">All reasons</option>
                        <?php foreach ($reasonCodes as $reason): ?>
                            <option value="<?php echo htmlspecialchars((string) $reason['reason_code']); ?>" <?php echo (($filters['reason_code'] ?? '') === ($reason['reason_code'] ?? '')) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars((string) $reason['reason_code']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="ops-field">
                    <label for="date_from" class="ops-field-label">From Date</label>
                    <input type="date" id="date_from" name="date_from" value="<?php echo htmlspecialchars((string) ($filters['date_from'] ?? '')); ?>">
                </div>
                <div class="ops-field">
                    <label for="date_to" class="ops-field-label">To Date</label>
                    <input type="date" id="date_to" name="date_to" value="<?php echo htmlspecialchars((string) ($filters['date_to'] ?? '')); ?>">
                </div>
                <div class="ops-action-row">
                    <button type="submit" class="btn-premium-primary"><i class="fas fa-filter"></i> Filter</button>
                    <a href="email_import_audit.php" class="btn-premium-secondary">Clear</a>
                </div>
            </form>
        </section>

        <section class="table-card">
            <div class="premium-section-header">
                <h2 class="ops-table-title">Import Outcomes</h2>
                <span class="ops-muted"><?php echo number_format($totalEntries); ?> entries</span>
            </div>

            <?php if (empty($entries)): ?>
                <div class="empty-state">
                    <p>No import audit entries found for these filters.</p>
                </div>
            <?php else: ?>
                <div class="table-card-scroll">
                    <table class="premium-table ops-timeline-table">
                        <thead>
                            <tr>
                                <th>Fetched</th>
                                <th>Sender</th>
                                <th>Subject</th>
                                <th>Outcome</th>
                                <th>Reason</th>
                                <th>Contact</th>
                                <th>Communication</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($entries as $entry): ?>
                                <?php
                                $contactLabel = trim((string) (($entry['contact_first_name'] ?? '') . ' ' . ($entry['contact_last_name'] ?? '')));
                                if ($contactLabel === '') {
                                    $contactLabel = $entry['contact_id'] ? ('Contact #' . (int) $entry['contact_id']) : '-';
                                }
                                $outcome = (string) ($entry['outcome'] ?? 'unknown');
                                $outcomeClass = match ($outcome) {
                                    'auto_created' => 'ops-status-badge--success',
                                    'processed' => 'ops-status-badge--info',
                                    'skipped' => 'ops-status-badge--warning',
                                    default => 'ops-status-badge--danger',
                                };
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime((string) ($entry['created_at'] ?? 'now')))); ?></td>
                                    <td>
                                        <div class="ops-table-cell-main"><?php echo htmlspecialchars((string) ($entry['sender_email'] ?? '-')); ?></div>
                                        <?php if (!empty($entry['sender_name'])): ?>
                                            <div class="ops-table-cell-sub"><?php echo htmlspecialchars((string) $entry['sender_name']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars((string) ($entry['subject_preview'] ?? 'No subject')); ?></td>
                                    <td>
                                        <span class="ops-status-badge <?php echo $outcomeClass; ?>">
                                            <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $outcome))); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="ops-table-cell-sub"><?php echo htmlspecialchars((string) ($entry['reason_code'] ?? '-')); ?></div>
                                        <?php if (!empty($entry['reason_message'])): ?>
                                            <div><?php echo htmlspecialchars((string) $entry['reason_message']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($entry['contact_id'])): ?>
                                            <a href="contact_view.php?id=<?php echo (int) $entry['contact_id']; ?>" class="ops-link">
                                                <?php echo htmlspecialchars($contactLabel); ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="ops-dimmed">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($entry['communication_id'])): ?>
                                            <a href="conversation.php?id=<?php echo (int) $entry['communication_id']; ?>" class="ops-link">
                                                #<?php echo (int) $entry['communication_id']; ?>
                                            </a>
                                            <?php if (!empty($entry['communication_channel'])): ?>
                                                <div class="ops-table-cell-sub"><?php echo htmlspecialchars((string) $entry['communication_channel']); ?></div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="ops-dimmed">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($totalPages > 1): ?>
                    <div class="ops-pagination">
                        <div class="ops-muted">
                            Showing <?php echo $offset + 1; ?>-<?php echo min($offset + $limit, $totalEntries); ?> of <?php echo $totalEntries; ?> entries
                        </div>
                        <div class="ops-pagination-links">
                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                <?php $query = http_build_query(array_merge($filters, ['page' => $i])); ?>
                                <a href="email_import_audit.php?<?php echo htmlspecialchars($query); ?>" class="ops-pagination-link <?php echo $i === $page ? 'is-active' : ''; ?>">
                                    <?php echo $i; ?>
                                </a>
                            <?php endfor; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
