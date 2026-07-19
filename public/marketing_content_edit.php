<?php
/**
 * Create/edit Marketing Content Studio item.
 */

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/constants.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Modules\Marketing;
use CRM\Security;
use CRM\Session;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: ' . getBasePath() . '/login.php');
    exit;
}

$user = Auth::user();
require_once __DIR__ . '/_marketing_marketplace_gate.php';
crm_require_marketing_marketplace_access($user);
if (!Authorization::can('marketing.write', $user)) {
    header('Location: ' . getBasePath() . '/marketing_content.php');
    exit;
}

$marketing = new Marketing();
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$item = $id > 0 ? $marketing->getContentItem($id) : null;
if ($id > 0 && !$item) {
    http_response_code(404);
    echo 'Marketing content item not found.';
    exit;
}

$error = '';
$notice = '';
$generated = false;
$options = $marketing->contentEditorOptionData();

$form = [
    'title' => $item['title'] ?? '',
    'content_type' => $item['content_type'] ?? 'social_post',
    'channel' => $item['channel'] ?? 'other',
    'status' => $item['status'] ?? 'idea',
    'production_stage' => $item['production_stage'] ?? 'idea',
    'production_due_at' => !empty($item['production_due_at']) ? str_replace(' ', 'T', substr((string) $item['production_due_at'], 0, 16)) : '',
    'production_started_at' => !empty($item['production_started_at']) ? str_replace(' ', 'T', substr((string) $item['production_started_at'], 0, 16)) : '',
    'production_completed_at' => !empty($item['production_completed_at']) ? str_replace(' ', 'T', substr((string) $item['production_completed_at'], 0, 16)) : '',
    'dependency_status' => $item['dependency_status'] ?? 'clear',
    'dependency_notes' => $item['dependency_notes'] ?? '',
    'production_checklist' => implode("\n", (array) ($item['production_checklist_json'] ?? [])),
    'funnel_stage' => $item['funnel_stage'] ?? '',
    'objective' => $item['objective'] ?? '',
    'target_audience' => $item['target_audience'] ?? '',
    'campaign_brief_id' => $item['campaign_brief_id'] ?? ($_GET['campaign_brief_id'] ?? ''),
    'brand_profile_id' => $item['brand_profile_id'] ?? '',
    'persona_id' => $item['persona_id'] ?? '',
    'seo_topic_id' => $item['seo_topic_id'] ?? '',
    'landing_page_id' => $item['landing_page_id'] ?? '',
    'campaign_id' => $item['campaign_id'] ?? '',
    'form_id' => $item['form_id'] ?? '',
    'email_template_id' => $item['email_template_id'] ?? '',
    'task_id' => $item['task_id'] ?? '',
    'scheduled_at' => !empty($item['scheduled_at']) ? str_replace(' ', 'T', substr((string) $item['scheduled_at'], 0, 16)) : '',
    'published_at' => !empty($item['published_at']) ? str_replace(' ', 'T', substr((string) $item['published_at'], 0, 16)) : '',
    'draft_body' => $item['draft_body'] ?? '',
    'owner_user_id' => $item['owner_user_id'] ?? '',
    'reviewer_user_id' => $item['reviewer_user_id'] ?? '',
    'review_due_at' => !empty($item['review_due_at']) ? str_replace(' ', 'T', substr((string) $item['review_due_at'], 0, 16)) : '',
    'blocked_reason' => $item['blocked_reason'] ?? '',
    'approval_checklist' => implode("\n", (array) ($item['approval_checklist_json'] ?? [])),
    'media_attachments' => implode("\n", array_map(static fn(array $attachment): string => trim((string) ($attachment['role'] ?? 'attachment') . ' | ' . (string) ($attachment['media_file_id'] ?? '') . ' | ' . (string) ($attachment['caption'] ?? '') . ' | ' . (string) ($attachment['alt_text_override'] ?? '') . ' | ' . (string) ($attachment['placement_notes'] ?? '') . ' | ' . (string) ($attachment['crop_guidance'] ?? '') . ' | ' . (string) ($attachment['channel'] ?? '')), (array) ($item['_media'] ?? []))),
    'generated_ai_context_json' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form = array_merge($form, [
        'title' => $_POST['title'] ?? '',
        'content_type' => $_POST['content_type'] ?? 'social_post',
        'channel' => $_POST['channel'] ?? 'other',
        'status' => $_POST['status'] ?? 'idea',
        'production_stage' => $_POST['production_stage'] ?? 'idea',
        'production_due_at' => $_POST['production_due_at'] ?? '',
        'production_started_at' => $_POST['production_started_at'] ?? '',
        'production_completed_at' => $_POST['production_completed_at'] ?? '',
        'dependency_status' => $_POST['dependency_status'] ?? 'clear',
        'dependency_notes' => $_POST['dependency_notes'] ?? '',
        'production_checklist' => $_POST['production_checklist'] ?? '',
        'funnel_stage' => $_POST['funnel_stage'] ?? '',
        'objective' => $_POST['objective'] ?? '',
        'target_audience' => $_POST['target_audience'] ?? '',
        'campaign_brief_id' => $_POST['campaign_brief_id'] ?? '',
        'brand_profile_id' => $_POST['brand_profile_id'] ?? '',
        'persona_id' => $_POST['persona_id'] ?? '',
        'seo_topic_id' => $_POST['seo_topic_id'] ?? '',
        'landing_page_id' => $_POST['landing_page_id'] ?? '',
        'campaign_id' => $_POST['campaign_id'] ?? '',
        'form_id' => $_POST['form_id'] ?? '',
        'email_template_id' => $_POST['email_template_id'] ?? '',
        'task_id' => $_POST['task_id'] ?? '',
        'scheduled_at' => $_POST['scheduled_at'] ?? '',
        'published_at' => $_POST['published_at'] ?? '',
        'draft_body' => $_POST['draft_body'] ?? '',
        'owner_user_id' => $_POST['owner_user_id'] ?? '',
        'reviewer_user_id' => $_POST['reviewer_user_id'] ?? '',
        'review_due_at' => $_POST['review_due_at'] ?? '',
        'blocked_reason' => $_POST['blocked_reason'] ?? '',
        'approval_checklist' => $_POST['approval_checklist'] ?? '',
        'media_attachments' => $_POST['media_attachments'] ?? '',
        'generated_ai_context_json' => $_POST['generated_ai_context_json'] ?? '',
    ]);

    try {
        if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
            throw new RuntimeException('Invalid CSRF token.');
        }

        $action = (string) ($_POST['action'] ?? 'save');
        $payload = $form + ['created_by' => (int) ($user['id'] ?? 0)];

        if ($action === 'generate') {
            $draft = $marketing->generateDraft($payload);
            $form['draft_body'] = $draft['body'];
            $form['generated_ai_context_json'] = json_encode($draft['ai_context'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $notice = 'Draft suggestion generated. Review and save it when it is ready for the working draft.';
            $generated = true;
        } else {
            if (trim((string) ($form['generated_ai_context_json'] ?? '')) !== '') {
                $generatedContext = json_decode((string) $form['generated_ai_context_json'], true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($generatedContext)) {
                    $payload['ai_context_json'] = $generatedContext;
                }
            }

            if ($id > 0) {
                $marketing->updateContentItem($id, $payload);
            } else {
                $id = $marketing->createContentItem($payload);
            }
            $marketing->replaceContentMedia($id, $form['media_attachments'], (int) ($user['id'] ?? 0));
            header('Location: ' . getBasePath() . '/marketing_content_view.php?id=' . $id . '&success=saved');
            exit;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

if (($_GET['generated'] ?? '') === '1') {
    $notice = 'Draft suggestion generated. Review and save it before scheduling or publishing.';
    $generated = true;
    $item = $marketing->getContentItem($id);
    if ($item) {
        $form['draft_body'] = $item['draft_body'] ?? '';
    }
}

$labelize = static fn(string $value): string => ucwords(str_replace('_', ' ', $value));
$selected = static fn($left, $right): string => (string) $left === (string) $right ? 'selected' : '';
$mediaPlacementLine = static fn(array $media, string $role = 'inline'): string => $role . ' | ' . (int) ($media['id'] ?? 0) . ' | ' . (string) ($media['caption'] ?? '') . ' | ' . (string) ($media['alt_text'] ?? '') . ' | Placement notes | Crop guidance | ' . (string) ($form['channel'] ?? '');
$mediaHealth = static function (array $media) use ($labelize): array {
    $issues = [];
    $type = (string) ($media['media_type'] ?? '');
    if (in_array($type, ['image', 'logo', 'thumbnail', 'banner'], true) && trim((string) ($media['alt_text'] ?? '')) === '') {
        $issues[] = 'Alt text missing';
    }
    if ($type === 'video' && trim((string) ($media['caption'] ?? $media['transcript'] ?? '')) === '') {
        $issues[] = 'Caption or transcript recommended';
    }
    if (in_array((string) ($media['approval_status'] ?? ''), ['blocked', 'archived', 'rejected'], true)) {
        $issues[] = 'Approval: ' . $labelize((string) ($media['approval_status'] ?? ''));
    }
    if (in_array((string) ($media['license_status'] ?? ''), ['expired', 'restricted'], true)) {
        $issues[] = 'Rights: ' . $labelize((string) ($media['license_status'] ?? ''));
    }

    return $issues;
};
$contentMediaGuidance = $marketing->getContentMediaPlacementGuidance([
    'content_item_id' => $id,
    'content_type' => $form['content_type'],
    'channel' => $form['channel'],
    'objective' => $form['objective'],
    'target_audience' => $form['target_audience'],
    'media_attachments' => $form['media_attachments'],
]);
$lineCount = static function (string $value): int {
    return count(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $value) ?: [])));
};
$statusTone = static function (string $status): string {
    return match ($status) {
        'approved', 'published', 'completed', 'scheduled', 'ready', 'clear' => 'ready',
        'blocked', 'rejected', 'needs_revision', 'overdue' => 'blocked',
        default => 'attention',
    };
};
$hasTitle = trim((string) $form['title']) !== '';
$hasDraft = trim((string) $form['draft_body']) !== '';
$hasObjective = trim((string) $form['objective']) !== '';
$hasAudience = trim((string) $form['target_audience']) !== '' || (int) ($form['persona_id'] ?? 0) > 0;
$hasSchedule = trim((string) $form['production_due_at']) !== '' || trim((string) $form['scheduled_at']) !== '' || trim((string) $form['review_due_at']) !== '';
$hasReview = $lineCount((string) $form['approval_checklist']) > 0 || (int) ($form['reviewer_user_id'] ?? 0) > 0;
$attachedMediaCount = (int) ($contentMediaGuidance['context']['attachment_count'] ?? $lineCount((string) $form['media_attachments']));
$approvedMediaCount = (int) ($contentMediaGuidance['context']['approved_media_count'] ?? 0);
$linkedCount = count(array_filter([
    (int) ($form['campaign_brief_id'] ?? 0) > 0,
    (int) ($form['brand_profile_id'] ?? 0) > 0,
    (int) ($form['persona_id'] ?? 0) > 0,
    (int) ($form['seo_topic_id'] ?? 0) > 0,
    (int) ($form['landing_page_id'] ?? 0) > 0,
    (int) ($form['campaign_id'] ?? 0) > 0,
    (int) ($form['form_id'] ?? 0) > 0,
    (int) ($form['email_template_id'] ?? 0) > 0,
    (int) ($form['task_id'] ?? 0) > 0,
]));
$summaryTiles = [
    ['icon' => 'fa-file-lines', 'label' => 'Mode', 'value' => $id > 0 ? 'Edit' : 'New', 'tooltip' => 'Whether this is a new content item or an existing draft.'],
    ['icon' => 'fa-pen-nib', 'label' => 'Draft', 'value' => $hasDraft ? 'Set' : 'Open', 'tooltip' => 'The working copy that will be reviewed before use.'],
    ['icon' => 'fa-bullseye', 'label' => 'Goal', 'value' => $hasObjective ? 'Set' : 'Open', 'tooltip' => 'The business outcome for this content item.'],
    ['icon' => 'fa-photo-film', 'label' => 'Media', 'value' => (string) $attachedMediaCount, 'tooltip' => 'Visual assets attached to this content item.'],
    ['icon' => 'fa-link', 'label' => 'Links', 'value' => (string) $linkedCount, 'tooltip' => 'Campaign, persona, SEO, landing page, form, email, and task records connected to this content.'],
];
$builderCards = [
    ['label' => 'Name Content', 'icon' => 'fa-pen-nib', 'status' => $hasTitle ? 'ready' : 'attention', 'sentence' => 'Give the draft a usable name.', 'tooltip' => 'Expert view: title, status, content type, channel, and funnel stage.', 'href' => '#content-basics', 'action' => 'Edit basics'],
    ['label' => 'Set Goal', 'icon' => 'fa-bullseye', 'status' => $hasObjective ? 'ready' : 'attention', 'sentence' => 'Say what this content should do.', 'tooltip' => 'Expert view: objective, target audience, persona, campaign brief, and campaign links.', 'href' => '#content-basics', 'action' => 'Set goal'],
    ['label' => 'Write Draft', 'icon' => 'fa-file-pen', 'status' => $hasDraft ? 'ready' : 'attention', 'sentence' => 'Create or generate the working copy.', 'tooltip' => 'Expert view: draft body and generated AI context.', 'href' => '#content-draft', 'action' => 'Write draft'],
    ['label' => 'Add Visuals', 'icon' => 'fa-photo-film', 'status' => $attachedMediaCount > 0 ? 'ready' : ($approvedMediaCount > 0 ? 'attention' : 'blocked'), 'sentence' => 'Attach only the visuals this piece needs.', 'tooltip' => 'Expert view: media attachment lines, roles, captions, alt text, placement notes, crop guidance, and channel-specific snippets.', 'href' => '#content-media-tools', 'action' => 'Add media'],
    ['label' => 'Plan Review', 'icon' => 'fa-clipboard-check', 'status' => $hasReview ? 'ready' : 'attention', 'sentence' => 'Add approval and dependency checks.', 'tooltip' => 'Expert view: reviewer, review due date, approval checklist, dependency health, blocked reason, and dependency notes.', 'href' => '#content-review-tools', 'action' => 'Plan review'],
    ['label' => 'Schedule Work', 'icon' => 'fa-calendar-check', 'status' => $hasSchedule ? 'ready' : 'attention', 'sentence' => 'Set production or publish timing.', 'tooltip' => 'Expert view: production stage, production due, started, completed, scheduled, published, owner, and linked task.', 'href' => '#content-production-tools', 'action' => 'Schedule'],
];
$todayActions = array_slice(array_values(array_filter([
    !$hasTitle ? ['label' => 'Name the content', 'href' => '#content-basics', 'reason' => 'A clear title keeps the draft easy to find.'] : null,
    !$hasObjective || !$hasAudience ? ['label' => 'Set goal and audience', 'href' => '#content-basics', 'reason' => 'Anchor the draft before writing or generating.'] : null,
    !$hasDraft ? ['label' => 'Write the draft', 'href' => '#content-draft', 'reason' => 'Use the draft field or generate a starting point.'] : null,
    $attachedMediaCount === 0 ? ['label' => 'Attach media', 'href' => '#content-media-tools', 'reason' => 'Add approved visuals only when they help the content.'] : null,
    ['label' => 'Generate draft', 'href' => '#content-actions', 'reason' => 'Use AI to fill founder knowledge gaps, then review before saving.'],
    ['label' => 'Save content', 'href' => '#content-actions', 'reason' => 'Save once the draft is clear enough for review.'],
])), 0, 5);
$pageTitle = ($id > 0 ? 'Edit Marketing Content' : 'New Marketing Content') . ' - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<div class="page-premium marketing-content-edit-page">
    <div class="container">
        <div class="page-header">
            <div>
                <h1>Content Builder</h1>
                <p>Marketing Content: create one clear piece before production.</p>
            </div>
            <div class="page-header-actions">
                <a class="btn-premium-secondary" href="<?php echo $id > 0 ? 'marketing_content_view.php?id=' . (int) $id : 'marketing_content.php'; ?>"><i class="fas fa-arrow-left"></i> Back</a>
            </div>
        </div>

        <?php if ($error !== ''): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
        <?php if ($notice !== ''): ?><div class="alert alert-success"><?php echo htmlspecialchars($notice); ?></div><?php endif; ?>

        <form method="POST" class="marketing-content-edit-form">
                <input type="hidden" name="csrf_token" value="<?php echo Security::getCsrfToken(); ?>">
                <input type="hidden" name="id" value="<?php echo (int) $id; ?>">
                <input type="hidden" name="generated_ai_context_json" value="<?php echo htmlspecialchars((string) $form['generated_ai_context_json']); ?>">

            <section class="marketing-content-edit-shell">
                <div class="marketing-founder-summary marketing-content-edit-summary" aria-label="Content builder summary">
                    <?php foreach ($summaryTiles as $tile): ?><div class="marketing-summary-tile" tabindex="0" data-tooltip="<?php echo htmlspecialchars((string) $tile['tooltip']); ?>"><i class="fas <?php echo htmlspecialchars((string) $tile['icon']); ?>" aria-hidden="true"></i><span><?php echo htmlspecialchars((string) $tile['label']); ?></span><strong><?php echo htmlspecialchars((string) $tile['value']); ?></strong></div><?php endforeach; ?>
                </div>

                <section class="marketing-content-edit-stage-grid" aria-label="Content builder path">
                    <?php foreach ($builderCards as $card): ?>
                        <article class="marketing-content-edit-card <?php echo htmlspecialchars((string) $card['status']); ?>" tabindex="0" data-tooltip="<?php echo htmlspecialchars((string) $card['tooltip']); ?>">
                            <div class="marketing-content-edit-visual"><i class="fas <?php echo htmlspecialchars((string) $card['icon']); ?>" aria-hidden="true"></i></div>
                            <div class="marketing-content-edit-card-body"><div class="marketing-content-edit-card-title"><strong><?php echo htmlspecialchars((string) $card['label']); ?></strong><span class="marketing-content-edit-status <?php echo htmlspecialchars((string) $card['status']); ?>"><?php echo htmlspecialchars($labelize((string) $card['status'])); ?></span></div><span><?php echo htmlspecialchars((string) $card['sentence']); ?></span></div>
                            <a class="btn-premium-secondary marketing-content-edit-card-action" href="<?php echo htmlspecialchars((string) $card['href']); ?>"><?php echo htmlspecialchars((string) $card['action']); ?></a>
                        </article>
                    <?php endforeach; ?>
                </section>

                <section class="marketing-content-edit-layout">
                    <main class="marketing-content-edit-main">
                        <section class="content-card marketing-content-edit-core" id="content-basics">
                            <div class="premium-section-header"><div><h2>Content Basics</h2><p>Name the piece and anchor the goal.</p></div><span class="marketing-content-edit-status <?php echo htmlspecialchars($statusTone((string) $form['status'])); ?>"><?php echo htmlspecialchars($labelize((string) $form['status'])); ?></span></div>
                            <div class="marketing-content-edit-grid">
                                <div class="form-group"><label>Title</label><input type="text" name="title" required value="<?php echo htmlspecialchars((string) $form['title']); ?>" placeholder="Example: LinkedIn post for launch week"></div>
                                <div class="form-group"><label>Status</label><select name="status"><?php foreach (Marketing::STATUSES as $status): ?><option value="<?php echo htmlspecialchars($status); ?>" <?php echo $selected($form['status'], $status); ?>><?php echo htmlspecialchars($labelize($status)); ?></option><?php endforeach; ?></select></div>
                                <div class="form-group"><label>Content Type</label><select name="content_type"><?php foreach (Marketing::CONTENT_TYPES as $type): ?><option value="<?php echo htmlspecialchars($type); ?>" <?php echo $selected($form['content_type'], $type); ?>><?php echo htmlspecialchars($labelize($type)); ?></option><?php endforeach; ?></select></div>
                                <div class="form-group"><label>Channel</label><select name="channel"><?php foreach (Marketing::CHANNELS as $channel): ?><option value="<?php echo htmlspecialchars($channel); ?>" <?php echo $selected($form['channel'], $channel); ?>><?php echo htmlspecialchars($labelize($channel)); ?></option><?php endforeach; ?></select></div>
                                <div class="form-group"><label>Funnel Stage</label><select name="funnel_stage"><option value="">Not set</option><?php foreach (Marketing::FUNNEL_STAGES as $stage): ?><option value="<?php echo htmlspecialchars($stage); ?>" <?php echo $selected($form['funnel_stage'], $stage); ?>><?php echo htmlspecialchars($labelize($stage)); ?></option><?php endforeach; ?></select></div>
                                <div class="form-group"><label>Objective</label><input type="text" name="objective" value="<?php echo htmlspecialchars((string) $form['objective']); ?>" placeholder="Example: Drive demo bookings"></div>
                                <div class="form-group marketing-content-edit-wide"><label>Target Audience</label><input type="text" name="target_audience" value="<?php echo htmlspecialchars((string) $form['target_audience']); ?>" placeholder="Example: Operations managers at SMBs"></div>
                            </div>
                        </section>

                        <section class="content-card marketing-content-edit-draft" id="content-draft">
                            <div class="premium-section-header"><div><h2>Working Draft</h2><p>Review any AI help before saving.</p></div></div>
                            <div class="form-group"><label>Draft Body</label><textarea name="draft_body" rows="16" placeholder="Write or generate the working draft here."><?php echo htmlspecialchars((string) $form['draft_body']); ?></textarea><?php if ($generated): ?><small class="marketing-content-edit-note">AI generation is assistive. Review claims, tone, and compliance before publishing.</small><?php endif; ?></div>
                        </section>
                    </main>

                    <aside class="content-card marketing-content-edit-today" aria-label="Today">
                        <div class="premium-section-header"><div><h2>Today</h2><p>Keep one content piece moving.</p></div></div>
                        <div class="marketing-content-edit-next-list"><?php foreach ($todayActions as $action): ?><a class="marketing-content-edit-today-action" href="<?php echo htmlspecialchars((string) $action['href']); ?>" data-tooltip="<?php echo htmlspecialchars((string) $action['reason']); ?>"><i class="fas fa-arrow-right" aria-hidden="true"></i><span><?php echo htmlspecialchars((string) $action['label']); ?></span></a><?php endforeach; ?></div>
                        <div class="marketing-content-edit-actions" id="content-actions"><button class="btn-premium-secondary" type="submit" name="action" value="generate"><i class="fas fa-wand-magic-sparkles"></i> Generate Draft</button><button class="btn-premium-primary" type="submit" name="action" value="save">Save Content</button></div>
                    </aside>
                </section>

                <details class="content-card marketing-content-edit-tools" id="content-production-tools">
                    <summary>More production details</summary>
                    <div class="marketing-content-edit-tools-body marketing-content-edit-grid">
                        <div class="form-group"><label>Production Stage</label><select name="production_stage"><?php foreach (Marketing::CONTENT_PRODUCTION_STAGES as $stage): ?><option value="<?php echo htmlspecialchars($stage); ?>" <?php echo $selected($form['production_stage'], $stage); ?>><?php echo htmlspecialchars($labelize($stage)); ?></option><?php endforeach; ?></select></div>
                        <div class="form-group"><label>Production Due</label><input type="datetime-local" name="production_due_at" value="<?php echo htmlspecialchars((string) $form['production_due_at']); ?>"></div>
                        <div class="form-group"><label>Started At</label><input type="datetime-local" name="production_started_at" value="<?php echo htmlspecialchars((string) $form['production_started_at']); ?>"></div>
                        <div class="form-group"><label>Completed At</label><input type="datetime-local" name="production_completed_at" value="<?php echo htmlspecialchars((string) $form['production_completed_at']); ?>"></div>
                        <div class="form-group"><label>Owner</label><select name="owner_user_id"><option value="">Unassigned</option><?php foreach ($options['users'] as $owner): ?><option value="<?php echo (int) $owner['id']; ?>" <?php echo $selected($form['owner_user_id'], $owner['id']); ?>><?php echo htmlspecialchars((string) $owner['email']); ?></option><?php endforeach; ?></select></div>
                        <div class="form-group"><label>Scheduled At</label><input type="datetime-local" name="scheduled_at" value="<?php echo htmlspecialchars((string) $form['scheduled_at']); ?>"></div>
                        <div class="form-group"><label>Published At</label><input type="datetime-local" name="published_at" value="<?php echo htmlspecialchars((string) $form['published_at']); ?>"></div>
                        <div class="form-group"><label>Linked Task</label><select name="task_id"><option value="">None</option><?php foreach ($options['tasks'] as $task): ?><option value="<?php echo (int) $task['id']; ?>" <?php echo $selected($form['task_id'], $task['id']); ?>><?php echo htmlspecialchars((string) $task['title']); ?></option><?php endforeach; ?></select></div>
                    </div>
                </details>

                <details class="content-card marketing-content-edit-tools" id="content-link-tools">
                    <summary>More linked records</summary>
                    <div class="marketing-content-edit-tools-body marketing-content-edit-grid">
                        <div class="form-group"><label>Campaign Brief</label><select name="campaign_brief_id"><option value="">None</option><?php foreach ($options['campaign_briefs'] as $brief): ?><option value="<?php echo (int) $brief['id']; ?>" <?php echo $selected($form['campaign_brief_id'], $brief['id']); ?>><?php echo htmlspecialchars((string) $brief['title']); ?></option><?php endforeach; ?></select></div>
                        <div class="form-group"><label>Brand Profile</label><select name="brand_profile_id"><option value="">None</option><?php foreach ($options['brand_profiles'] as $profile): ?><option value="<?php echo (int) $profile['id']; ?>" <?php echo $selected($form['brand_profile_id'], $profile['id']); ?>><?php echo htmlspecialchars((string) $profile['name']); ?></option><?php endforeach; ?></select></div>
                        <div class="form-group"><label>Persona</label><select name="persona_id"><option value="">None</option><?php foreach ($options['personas'] as $persona): ?><option value="<?php echo (int) $persona['id']; ?>" <?php echo $selected($form['persona_id'], $persona['id']); ?>><?php echo htmlspecialchars((string) $persona['name']); ?></option><?php endforeach; ?></select></div>
                        <div class="form-group"><label>SEO Topic</label><select name="seo_topic_id"><option value="">None</option><?php foreach ($options['seo_topics'] as $topic): ?><option value="<?php echo (int) $topic['id']; ?>" <?php echo $selected($form['seo_topic_id'], $topic['id']); ?>><?php echo htmlspecialchars((string) $topic['keyword']); ?></option><?php endforeach; ?></select></div>
                        <div class="form-group"><label>Landing Page</label><select name="landing_page_id"><option value="">None</option><?php foreach ($options['landing_pages'] as $landingPage): ?><option value="<?php echo (int) $landingPage['id']; ?>" <?php echo $selected($form['landing_page_id'], $landingPage['id']); ?>><?php echo htmlspecialchars((string) $landingPage['title']); ?></option><?php endforeach; ?></select></div>
                        <div class="form-group"><label>Linked Campaign</label><select name="campaign_id"><option value="">None</option><?php foreach ($options['campaigns'] as $campaign): ?><option value="<?php echo (int) $campaign['id']; ?>" <?php echo $selected($form['campaign_id'], $campaign['id']); ?>><?php echo htmlspecialchars((string) $campaign['name']); ?></option><?php endforeach; ?></select></div>
                        <div class="form-group"><label>Linked Form</label><select name="form_id"><option value="">None</option><?php foreach ($options['forms'] as $linkedForm): ?><option value="<?php echo (int) $linkedForm['id']; ?>" <?php echo $selected($form['form_id'], $linkedForm['id']); ?>><?php echo htmlspecialchars((string) $linkedForm['name']); ?></option><?php endforeach; ?></select></div>
                        <div class="form-group"><label>Email Template</label><select name="email_template_id"><option value="">None</option><?php foreach ($options['email_templates'] as $template): ?><option value="<?php echo (int) $template['id']; ?>" <?php echo $selected($form['email_template_id'], $template['id']); ?>><?php echo htmlspecialchars((string) $template['name']); ?></option><?php endforeach; ?></select></div>
                    </div>
                </details>

                <details class="content-card marketing-content-edit-tools" id="content-review-tools">
                    <summary>More review checks</summary>
                    <div class="marketing-content-edit-tools-body marketing-content-edit-grid">
                        <div class="form-group"><label>Reviewer</label><select name="reviewer_user_id"><option value="">Unassigned</option><?php foreach ($options['users'] as $reviewer): ?><option value="<?php echo (int) $reviewer['id']; ?>" <?php echo $selected($form['reviewer_user_id'], $reviewer['id']); ?>><?php echo htmlspecialchars((string) $reviewer['email']); ?></option><?php endforeach; ?></select></div>
                        <div class="form-group"><label>Review Due</label><input type="datetime-local" name="review_due_at" value="<?php echo htmlspecialchars((string) $form['review_due_at']); ?>"></div>
                        <div class="form-group"><label>Dependency Health</label><select name="dependency_status"><?php foreach (Marketing::CONTENT_DEPENDENCY_HEALTH_STATUSES as $status): ?><option value="<?php echo htmlspecialchars($status); ?>" <?php echo $selected($form['dependency_status'], $status); ?>><?php echo htmlspecialchars($labelize($status)); ?></option><?php endforeach; ?></select></div>
                        <div class="form-group"><label>Approval Checklist</label><textarea name="approval_checklist" rows="5" placeholder="Claim verified&#10;CTA matches offer&#10;Brand tone checked"><?php echo htmlspecialchars((string) $form['approval_checklist']); ?></textarea></div>
                        <div class="form-group"><label>Production Checklist</label><textarea name="production_checklist" rows="5" placeholder="Brief locked&#10;Draft finished&#10;Media attached&#10;Review requested"><?php echo htmlspecialchars((string) $form['production_checklist']); ?></textarea></div>
                        <div class="form-group"><label>Blocked Reason</label><textarea name="blocked_reason" rows="5" placeholder="Explain what is blocking this content, if anything."><?php echo htmlspecialchars((string) $form['blocked_reason']); ?></textarea></div>
                        <div class="form-group marketing-content-edit-wide"><label>Dependency Notes</label><textarea name="dependency_notes" rows="5" placeholder="Capture production dependencies, handoff notes, or waiting-on details."><?php echo htmlspecialchars((string) $form['dependency_notes']); ?></textarea></div>
                    </div>
                </details>

                <details class="content-card marketing-content-edit-tools" id="content-media-tools">
                    <summary>More media placement</summary>
                    <div class="marketing-content-edit-tools-body">
                        <div class="form-group">
                            <label>Media Attachments</label>
                            <textarea id="media_attachments" name="media_attachments" rows="5" placeholder="featured | 12 | Caption | Alt override | Above intro | 16:9 crop | linkedin"><?php echo htmlspecialchars((string) $form['media_attachments']); ?></textarea>
                            <div class="marketing-content-edit-note">One attachment per line: role | media ID | caption | alt override | placement notes | crop guidance | optional channel.</div>
                        </div>
                        <div class="media-guidance-panel">
                            <strong>AI-ready media placement guidance</strong>
                            <div class="media-guidance-metrics">
                                <span class="media-guidance-metric"><?php echo (int) ($contentMediaGuidance['context']['attachment_count'] ?? 0); ?> attached</span>
                                <span class="media-guidance-metric"><?php echo (int) ($contentMediaGuidance['context']['approved_media_count'] ?? 0); ?> approved available</span>
                                <span class="media-guidance-metric">Roles: <?php echo htmlspecialchars(implode(', ', (array) ($contentMediaGuidance['recommended_roles'] ?? []))); ?></span>
                            </div>
                            <ul>
                                <?php foreach (array_slice((array) ($contentMediaGuidance['recommendations'] ?? []), 0, 4) as $recommendation): ?><li><?php echo htmlspecialchars((string) $recommendation); ?></li><?php endforeach; ?>
                            </ul>
                        </div>
                        <?php if (!empty($options['media_files'])): ?>
                            <div class="premium-section-header marketing-content-edit-section-header"><div><h2>Media Placement Guide</h2><p>Attach approved visuals without leaving the editor.</p></div><a class="btn-premium-secondary" href="marketing_creative.php">AI Visual Help</a></div>
                            <div class="media-placement-grid">
                                <?php foreach (array_slice($options['media_files'], 0, 8) as $media): $mediaUrl = $marketing->mediaDisplayUrl($media); $issues = $mediaHealth($media); $defaultRole = in_array((string) ($form['content_type'] ?? ''), ['blog_post', 'case_study'], true) ? 'featured' : (in_array((string) ($media['media_type'] ?? ''), ['banner', 'thumbnail'], true) ? (string) $media['media_type'] : ((string) ($form['content_type'] ?? '') === 'video_script' ? 'reference' : 'inline')); ?>
                                    <div class="media-placement-card">
                                        <div class="media-placement-preview">
                                            <?php if ($mediaUrl !== '' && in_array((string) ($media['media_type'] ?? ''), ['image', 'logo', 'thumbnail', 'banner'], true)): ?><img src="<?php echo htmlspecialchars($mediaUrl); ?>" alt="<?php echo htmlspecialchars((string) ($media['alt_text'] ?? $media['title'])); ?>">
                                            <?php elseif ($mediaUrl !== '' && (string) ($media['media_type'] ?? '') === 'video'): ?><video src="<?php echo htmlspecialchars($mediaUrl); ?>" muted preload="metadata"></video>
                                            <?php else: ?><span><?php echo htmlspecialchars($labelize((string) ($media['media_type'] ?? 'media'))); ?></span><?php endif; ?>
                                        </div>
                                        <strong>#<?php echo (int) $media['id']; ?> <?php echo htmlspecialchars((string) $media['title']); ?></strong>
                                        <div class="media-placement-badges">
                                            <span class="media-placement-badge"><?php echo htmlspecialchars($labelize((string) ($media['media_type'] ?? 'media'))); ?></span>
                                            <span class="media-placement-badge"><?php echo htmlspecialchars($labelize((string) ($media['approval_status'] ?? 'pending'))); ?></span>
                                            <span class="media-placement-badge"><?php echo htmlspecialchars($labelize((string) ($media['license_status'] ?? 'unknown'))); ?></span>
                                            <?php foreach ($issues as $issue): ?><span class="media-placement-badge media-placement-warning"><?php echo htmlspecialchars($issue); ?></span><?php endforeach; ?>
                                        </div>
                                        <div class="media-placement-line"><?php echo htmlspecialchars($mediaPlacementLine($media, $defaultRole)); ?></div>
                                        <div class="media-placement-actions">
                                            <button class="btn-premium-secondary" type="button" data-media-attach-line="<?php echo htmlspecialchars($mediaPlacementLine($media, $defaultRole)); ?>" data-media-button-label="Add as <?php echo htmlspecialchars($labelize($defaultRole)); ?>">Add as <?php echo htmlspecialchars($labelize($defaultRole)); ?></button>
                                            <button class="btn-premium-secondary" type="button" data-media-attach-line="<?php echo htmlspecialchars($mediaPlacementLine($media, 'reference')); ?>" data-media-button-label="Add Reference">Add Reference</button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <div class="marketing-content-edit-note">Add media in Marketing Assets first, then attach it here. <a href="marketing_assets.php">Open media library</a></div>
                        <?php endif; ?>
                    </div>
                </details>
            </section>
        </form>
    </div>
</div>

<script>
document.addEventListener('click', function (event) {
    var button = event.target.closest('[data-media-attach-line]');
    if (!button) {
        return;
    }
    var target = document.getElementById('media_attachments');
    if (!target) {
        return;
    }
    var line = button.getAttribute('data-media-attach-line') || '';
    var current = target.value.trim();
    if (current.indexOf(line) === -1) {
        target.value = current ? current + "\n" + line : line;
    }
    target.focus();
    button.textContent = 'Added';
    window.setTimeout(function () {
        button.textContent = button.getAttribute('data-media-button-label') || 'Add Media';
    }, 1200);
});
document.addEventListener('DOMContentLoaded', function () {
    var openHashDrawer = function () {
        var hash = window.location.hash.replace('#', '');
        if (hash === '') {
            return;
        }
        var target = document.getElementById(hash);
        var drawer = target ? target.closest('details.marketing-content-edit-tools') : null;
        if (drawer) {
            drawer.open = true;
        }
    };
    document.querySelectorAll('.marketing-content-edit-card-action[href^="#"], .marketing-content-edit-today-action[href^="#"]').forEach(function (link) {
        link.addEventListener('click', function () { window.setTimeout(openHashDrawer, 0); });
    });
    window.addEventListener('hashchange', openHashDrawer);
    openHashDrawer();
});
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
