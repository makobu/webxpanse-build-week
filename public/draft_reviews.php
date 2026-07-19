<?php
/**
 * Draft Reviews Page
 * 
 * List and manage draft reviews
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/index.php';

use CRM\Modules\DraftReview;
use CRM\Auth;
use CRM\Authorization;
use CRM\Security;
use CRM\Services\MarketplacePageExplainerService;
use CRM\Services\PageGuideVideoUi;

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}
Authorization::requirePermission('drafts.manage');

$draftReview = new DraftReview();

// Get filter parameters
$status = $_GET['status'] ?? 'draft';
$limit = 50;
$offset = (int) ($_GET['offset'] ?? 0);

// Get drafts
$drafts = $draftReview->getDraftsForReview($status, $limit, $offset);

$pageTitle = 'Draft Reviews';
$draftReviewsGuideVideoUrl = PageGuideVideoUi::activeVideoUrl(MarketplacePageExplainerService::PAGE_DRAFT_REVIEWS);
ob_start();
?>

<?php echo PageGuideVideoUi::assets(); ?>
<div style="max-width: 1400px; margin: 0 auto; padding: var(--spacing-lg);">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: var(--spacing-lg);">
        <h1 style="color: var(--midnight-black); margin: 0;">Draft Reviews</h1>
        <div style="display: flex; gap: var(--spacing-sm);">
            <?php if ($draftReviewsGuideVideoUrl !== ''): ?>
                <?php echo PageGuideVideoUi::button(MarketplacePageExplainerService::PAGE_DRAFT_REVIEWS, 'Draft Reviews page guide'); ?>
            <?php endif; ?>
            <a href="draft_templates.php" style="background: var(--accent-blue); color: white; padding: var(--spacing-sm) var(--spacing-md); border-radius: 4px; text-decoration: none; font-weight: 500;">
                Manage Templates
            </a>
        </div>
    </div>

    <!-- Status Filter -->
    <div style="background: white; padding: var(--spacing-md); border-radius: 8px; margin-bottom: var(--spacing-md); box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
        <div style="display: flex; gap: var(--spacing-sm);">
            <a href="?status=draft" 
               style="padding: var(--spacing-xs) var(--spacing-md); border-radius: 4px; text-decoration: none; <?php echo $status === 'draft' ? 'background: var(--accent-blue); color: white;' : 'background: var(--light-grey); color: var(--charcoal-grey);'; ?>">
                Drafts (<?php echo count(array_filter($drafts, fn($d) => $d['status'] === 'draft')); ?>)
            </a>
            <a href="?status=reviewed" 
               style="padding: var(--spacing-xs) var(--spacing-md); border-radius: 4px; text-decoration: none; <?php echo $status === 'reviewed' ? 'background: var(--accent-blue); color: white;' : 'background: var(--light-grey); color: var(--charcoal-grey);'; ?>">
                Reviewed
            </a>
            <a href="?status=approved" 
               style="padding: var(--spacing-xs) var(--spacing-md); border-radius: 4px; text-decoration: none; <?php echo $status === 'approved' ? 'background: var(--accent-blue); color: white;' : 'background: var(--light-grey); color: var(--charcoal-grey);'; ?>">
                Approved
            </a>
        </div>
    </div>

    <!-- Drafts List -->
    <?php if (empty($drafts)): ?>
        <div style="background: white; padding: var(--spacing-xl); border-radius: 8px; text-align: center; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
            <p style="color: var(--charcoal-grey); margin: 0;">No drafts found.</p>
        </div>
    <?php else: ?>
        <div style="display: grid; gap: var(--spacing-md);">
            <?php foreach ($drafts as $draft): ?>
                <div style="background: white; padding: var(--spacing-lg); border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: var(--spacing-md);">
                        <div>
                            <h3 style="color: var(--midnight-black); margin: 0 0 var(--spacing-xs) 0;">
                                <?php if ($draft['contact_first_name']): ?>
                                    <a href="contact_view.php?id=<?php echo $draft['contact_id']; ?>" style="color: var(--accent-blue); text-decoration: none;">
                                        <?php echo htmlspecialchars($draft['contact_first_name'] . ' ' . ($draft['contact_last_name'] ?? '')); ?>
                                    </a>
                                <?php else: ?>
                                    Draft #<?php echo $draft['id']; ?>
                                <?php endif; ?>
                            </h3>
                            <div style="display: flex; gap: var(--spacing-sm); margin-top: var(--spacing-xs);">
                                <span style="background: var(--light-grey); padding: 4px 8px; border-radius: 4px; font-size: 12px; color: var(--charcoal-grey);">
                                    <?php echo htmlspecialchars(ucfirst($draft['draft_type'])); ?>
                                </span>
                                <span style="background: var(--light-grey); padding: 4px 8px; border-radius: 4px; font-size: 12px; color: var(--charcoal-grey);">
                                    <?php echo htmlspecialchars(ucfirst($draft['status'])); ?>
                                </span>
                                <span style="background: var(--light-grey); padding: 4px 8px; border-radius: 4px; font-size: 12px; color: var(--charcoal-grey);">
                                    <?php echo htmlspecialchars(ucfirst($draft['tone'])); ?> tone
                                </span>
                            </div>
                        </div>
                        <div style="display: flex; gap: var(--spacing-sm);">
                            <a href="draft_review.php?id=<?php echo $draft['id']; ?>" 
                               style="background: var(--accent-blue); color: white; padding: var(--spacing-xs) var(--spacing-md); border-radius: 4px; text-decoration: none; font-size: 14px;">
                                Review
                            </a>
                        </div>
                    </div>
                    
                    <?php if ($draft['subject']): ?>
                        <div style="margin-bottom: var(--spacing-sm);">
                            <strong style="color: var(--midnight-black);">Subject:</strong>
                            <span style="color: var(--charcoal-grey);"><?php echo htmlspecialchars($draft['subject']); ?></span>
                        </div>
                    <?php endif; ?>
                    
                    <div style="margin-bottom: var(--spacing-sm);">
                        <strong style="color: var(--midnight-black);">Preview:</strong>
                        <p style="color: var(--charcoal-grey); margin: var(--spacing-xs) 0 0 0; max-height: 100px; overflow: hidden;">
                            <?php echo htmlspecialchars(substr(strip_tags($draft['body']), 0, 200)); ?>...
                        </p>
                    </div>
                    
                    <div style="color: var(--charcoal-grey); font-size: 12px; margin-top: var(--spacing-sm);">
                        Created by <?php echo htmlspecialchars($draft['created_by_email'] ?? 'Unknown'); ?> 
                        on <?php echo date('M j, Y g:i A', strtotime($draft['created_at'])); ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php echo PageGuideVideoUi::modal(MarketplacePageExplainerService::PAGE_DRAFT_REVIEWS, 'How to use Draft Reviews', $draftReviewsGuideVideoUrl); ?>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
