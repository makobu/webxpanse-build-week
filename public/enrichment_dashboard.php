<?php
/**
 * Enrichment Dashboard
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
use CRM\Services\MarketplacePageExplainerService;
use CRM\Services\PageGuideVideoUi;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

// Require authentication
if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

// Get enrichment statistics
$stats = Database::queryOne(
    "SELECT 
        COUNT(*) as total_contacts,
        COUNT(CASE WHEN enrichment_score > 0 THEN 1 END) as enriched_contacts,
        AVG(enrichment_score) as avg_score,
        SUM(CASE WHEN enrichment_score >= 70 THEN 1 END) as high_score_count,
        SUM(CASE WHEN enrichment_score < 30 THEN 1 END) as low_score_count
     FROM contacts"
);

$totalEnrichments = Database::queryOne(
    "SELECT COUNT(*) as total FROM enrichment_history WHERE status = 'success'"
);

$totalCost = Database::queryOne(
    "SELECT SUM(cost) as total_cost FROM enrichment_history"
);

$recentEnrichments = Database::query(
    "SELECT eh.*, c.first_name, c.last_name, c.email 
     FROM enrichment_history eh
     JOIN contacts c ON eh.contact_id = c.id
     ORDER BY eh.created_at DESC
     LIMIT 20"
);

$contactsNeedingEnrichment = Database::query(
    "SELECT id, first_name, last_name, email, company, enrichment_score, last_enriched_at
     FROM contacts
     WHERE (enrichment_score IS NULL OR enrichment_score < 50)
     AND (last_enriched_at IS NULL OR last_enriched_at < DATE_SUB(NOW(), INTERVAL 30 DAY))
     ORDER BY enrichment_score ASC, created_at DESC
     LIMIT 50"
);

$pageTitle = 'Data Enrichment - ' . brandProductName();
$enrichmentDashboardGuideVideoUrl = PageGuideVideoUi::activeVideoUrl(MarketplacePageExplainerService::PAGE_ENRICHMENT_DASHBOARD);
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<?php echo PageGuideVideoUi::assets(); ?>
<div class="page-premium">
    <div class="container">
        <section class="page-header" aria-labelledby="data-enrichment-title">
            <div>
                <h1 id="data-enrichment-title">Data Enrichment</h1>
                <p>Monitor verified field updates and AI-generated contact context.</p>
            </div>
            <div class="page-header-actions">
                <?php if ($enrichmentDashboardGuideVideoUrl !== ''): ?>
                    <?php echo PageGuideVideoUi::button(MarketplacePageExplainerService::PAGE_ENRICHMENT_DASHBOARD, 'Enrichment Dashboard page guide'); ?>
                <?php endif; ?>
                <a class="btn-premium-secondary" href="contacts.php"><i class="fas fa-users" aria-hidden="true"></i>Back to Contacts</a>
            </div>
        </section>

        <div class="stats-grid" aria-label="Data enrichment summary">
            <div class="stat-card">
                <div class="stat-label">Total Contacts</div>
                <div class="stat-value"><?php echo number_format((int) ($stats['total_contacts'] ?? 0)); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Enriched Contacts</div>
                <div class="stat-value"><?php echo number_format((int) ($stats['enriched_contacts'] ?? 0)); ?></div>
                <div class="premium-inline-note">
                    <?php echo ($stats['total_contacts'] ?? 0) > 0 ? round(((int) $stats['enriched_contacts'] / (int) $stats['total_contacts']) * 100, 1) : 0; ?>% of total
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Average Score</div>
                <div class="stat-value"><?php echo round((float) ($stats['avg_score'] ?? 0), 0); ?>%</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Total Enrichments</div>
                <div class="stat-value"><?php echo number_format((int) ($totalEnrichments['total'] ?? 0)); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Total Cost</div>
                <div class="stat-value">$<?php echo number_format((float) ($totalCost['total_cost'] ?? 0), 2); ?></div>
            </div>
        </div>

        <section class="table-card" aria-labelledby="contacts-needing-enrichment-title">
            <div class="premium-section-header">
                <div>
                    <h2 id="contacts-needing-enrichment-title">Contacts Needing Enrichment</h2>
                    <p>Contacts below the freshness or confidence threshold.</p>
                </div>
            </div>
            <?php if (empty($contactsNeedingEnrichment)): ?>
                <div class="empty-state">
                    <p>All contacts are up to date.</p>
                </div>
            <?php else: ?>
                <div class="table-card-scroll">
                    <table class="premium-table">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Company</th>
                                <th>Score</th>
                                <th>Last Enriched</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($contactsNeedingEnrichment as $contact): ?>
                                <?php
                                $enrichmentScore = (int) ($contact['enrichment_score'] ?? 0);
                                $scoreClass = $enrichmentScore >= 50 ? 'is-success' : 'is-warning';
                                ?>
                                <tr>
                                    <td>
                                        <a class="premium-muted-link" href="contact_view.php?id=<?php echo (int) $contact['id']; ?>">
                                            <?php echo htmlspecialchars(trim((string) (($contact['first_name'] ?? '') . ' ' . ($contact['last_name'] ?? '')))); ?>
                                        </a>
                                    </td>
                                    <td><?php echo htmlspecialchars((string) ($contact['email'] ?? '-')); ?></td>
                                    <td><?php echo htmlspecialchars((string) ($contact['company'] ?? '-')); ?></td>
                                    <td><span class="premium-status-badge <?php echo $scoreClass; ?>"><?php echo $enrichmentScore; ?>%</span></td>
                                    <td><?php echo $contact['last_enriched_at'] ? htmlspecialchars(date('M j, Y', strtotime($contact['last_enriched_at']))) : 'Never'; ?></td>
                                    <td>
                                        <button class="btn-premium-info btn-premium-sm" type="button" onclick="enrichContact(<?php echo (int) $contact['id']; ?>)">
                                            <i class="fas fa-wand-magic-sparkles" aria-hidden="true"></i>Enrich
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="table-card" aria-labelledby="recent-enrichment-title">
            <div class="premium-section-header">
                <div>
                    <h2 id="recent-enrichment-title">Recent Enrichment Activity</h2>
                    <p>Latest trusted data and AI context refreshes.</p>
                </div>
            </div>
            <?php if (empty($recentEnrichments)): ?>
                <div class="empty-state">
                    <p>No enrichment activity yet.</p>
                </div>
            <?php else: ?>
                <div class="table-card-scroll">
                    <table class="premium-table">
                        <thead>
                            <tr>
                                <th>Contact</th>
                                <th>Type</th>
                                <th>Status</th>
                                <th>Outcome</th>
                                <th>Cost</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentEnrichments as $enrichment): ?>
                                <?php $statusClass = ($enrichment['status'] ?? '') === 'success' ? 'is-success' : 'is-danger'; ?>
                                <tr>
                                    <td>
                                        <a class="premium-muted-link" href="contact_view.php?id=<?php echo (int) $enrichment['contact_id']; ?>">
                                            <?php echo htmlspecialchars(trim((string) (($enrichment['first_name'] ?? '') . ' ' . ($enrichment['last_name'] ?? '')))); ?>
                                        </a>
                                    </td>
                                    <td><?php echo htmlspecialchars((string) ($enrichment['enrichment_type'] ?? '')); ?></td>
                                    <td><span class="premium-status-badge <?php echo $statusClass; ?>"><?php echo htmlspecialchars((string) ($enrichment['status'] ?? 'unknown')); ?></span></td>
                                    <td>
                                        <?php
                                        $response = json_decode((string) ($enrichment['ai_response'] ?? ''), true);
                                        $verifiedFields = is_array($response['verified_fields_updated'] ?? null) ? $response['verified_fields_updated'] : [];
                                        $contextGenerated = !empty($response['context_generated']) || !empty($response['ai_context']);
                                        if (!empty($verifiedFields)) {
                                            echo htmlspecialchars(count($verifiedFields) . ' verified field' . (count($verifiedFields) === 1 ? '' : 's') . ' updated');
                                        } elseif ($contextGenerated) {
                                            echo 'Context enriched';
                                        } else {
                                            echo 'No field changes';
                                        }
                                        ?>
                                    </td>
                                    <td>$<?php echo number_format((float) ($enrichment['cost'] ?? 0), 4); ?></td>
                                    <td><?php echo htmlspecialchars(date('M j, Y H:i', strtotime((string) $enrichment['created_at']))); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>

<script>
async function enrichContact(contactId) {
    if (!confirm('Refresh trusted provider data and AI context for this contact now?')) {
        return;
    }
    
    try {
        const response = await fetch('../api/enrichment/enrich.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
                body: JSON.stringify({
                    contact_id: contactId,
                    options: {
                    use_third_party: true,
                    extract_web: false,
                    extract_email: true,
                    extract_social: false,
                    discover_linkedin: false,
                    infer_fields: true,
                    validate_data: true,
                    sources: ['third_party', 'email', 'inference']
                }
            })
        });
        
        const result = await response.json();
        
        if (result.status === 'success') {
            alert('Verified fields/context refreshed successfully.');
            location.reload();
        } else {
            alert('Error: ' + (result.message || 'Enrichment failed'));
        }
    } catch (error) {
        alert('Error: ' + error.message);
    }
}
</script>

<?php echo PageGuideVideoUi::modal(MarketplacePageExplainerService::PAGE_ENRICHMENT_DASHBOARD, 'How to use Enrichment Dashboard', $enrichmentDashboardGuideVideoUrl); ?>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
