<?php

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
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
use CRM\Security;
use CRM\Services\DefaultWorkspaceOwnerSupportService;
use CRM\Services\OwnerHelpExpertService;
use CRM\Services\WorkspaceContext;
use CRM\Session;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$user = Auth::user();
if (!Authorization::isSuperAdmin($user) || !WorkspaceContext::isDefaultWorkspace((int) (WorkspaceContext::currentWorkspaceId() ?? 0))) {
    header('Location: dashboard.php');
    exit;
}

$userId = (int) ($user['id'] ?? 0);
$supportService = new DefaultWorkspaceOwnerSupportService();
$expertService = new OwnerHelpExpertService();
$notice = trim((string) ($_GET['notice'] ?? ''));
$error = trim((string) ($_GET['error'] ?? ''));

function ownerHelpExpertsAdminRedirect(array $params = []): void
{
    header('Location: owner_help_experts_admin.php' . ($params !== [] ? '?' . http_build_query($params) : ''));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $profileId = (int) ($_POST['profile_id'] ?? 0);
    try {
        if (!Security::validateCSRF((string) ($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Security check failed. Refresh the page and try again.');
        }
        $action = trim((string) ($_POST['action'] ?? ''));
        if ($action === 'save_profile') {
            $profileId = $expertService->saveProfile($_POST, $userId);
            $photo = $_FILES['profile_photo'] ?? null;
            if (is_array($photo) && (int) ($photo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $expertService->storeProfilePhoto($profileId, $photo);
            }
            ownerHelpExpertsAdminRedirect(['profile_id' => $profileId, 'notice' => 'Expert profile saved.']);
        }
        if ($action === 'set_approval') {
            $expertService->updateApproval(
                $profileId,
                (string) ($_POST['approval_status'] ?? 'pending'),
                $userId,
                (string) ($_POST['rejection_note'] ?? '')
            );
            ownerHelpExpertsAdminRedirect(['profile_id' => $profileId, 'notice' => 'Approval status updated.']);
        }
        if ($action === 'save_skill') {
            $expertService->saveSkill($profileId, $_POST);
            ownerHelpExpertsAdminRedirect(['profile_id' => $profileId, 'notice' => 'Expert skill saved.']);
        }
        if ($action === 'delete_skill') {
            $expertService->deleteSkill($profileId, (int) ($_POST['skill_id'] ?? 0));
            ownerHelpExpertsAdminRedirect(['profile_id' => $profileId, 'notice' => 'Expert skill removed.']);
        }
        if ($action === 'set_profile_status') {
            $nextStatus = (string) ($_POST['profile_status'] ?? 'hidden');
            $expertService->updateProfileStatus($profileId, $nextStatus);
            ownerHelpExpertsAdminRedirect([
                'profile_id' => $profileId,
                'notice' => $nextStatus === 'active' ? 'Expert profile is visible again.' : 'Expert profile hidden.',
            ]);
        }
        if ($action === 'delete_profile') {
            $expertService->deleteProfile($profileId);
            ownerHelpExpertsAdminRedirect(['notice' => 'Expert profile deleted.']);
        }
        throw new RuntimeException('Unsupported expert admin action.');
    } catch (Throwable $e) {
        $params = ['error' => $e->getMessage()];
        if ($profileId > 0) {
            $params['profile_id'] = $profileId;
        }
        ownerHelpExpertsAdminRedirect($params);
    }
}

$profiles = [];
$selectedProfile = null;
$selectedActiveRequestCount = 0;
$members = [];
try {
    $profiles = $expertService->adminProfiles();
    $selectedProfileId = (int) ($_GET['profile_id'] ?? 0);
    if ($selectedProfileId <= 0 && $profiles !== []) {
        $selectedProfileId = (int) ($profiles[0]['id'] ?? 0);
    }
    if ($selectedProfileId > 0) {
        $selectedProfile = $expertService->adminProfile($selectedProfileId);
        if ($selectedProfile) {
            $selectedActiveRequestCount = $expertService->activeRequestCount((int) ($selectedProfile['id'] ?? 0));
        }
    }
    $members = $supportService->defaultWorkspaceMembers();
} catch (Throwable $e) {
    $error = $error !== '' ? $error : $e->getMessage();
}

$csrfToken = Security::getCsrfToken();
$h = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
$listLines = static fn(array $values): string => implode("\n", array_map('strval', $values));
$mediaUrl = static function (?string $path): string {
    $path = trim((string) $path);
    if ($path === '') {
        return '';
    }
    if (preg_match('/^https?:\/\//i', $path)) {
        return $path;
    }
    if (str_starts_with($path, 'uploads/')) {
        return '../' . $path;
    }
    return assetUrl($path);
};
$initials = static function (string $name): string {
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $letters = '';
    foreach ($parts as $part) {
        if ($part !== '') {
            $letters .= strtoupper(substr($part, 0, 1));
        }
        if (strlen($letters) >= 2) {
            break;
        }
    }
    return $letters !== '' ? $letters : 'EX';
};
$profileName = $selectedProfile ? (string) ($selectedProfile['name'] ?? 'Internal expert') : 'New expert profile';
$photoUrl = $selectedProfile ? $mediaUrl((string) ($selectedProfile['profile_photo_path'] ?? '')) : '';

$pageTitle = 'Expert Profiles Admin - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<style>
    .expert-admin-page { background:#f6f8fb; min-height:calc(100vh - 80px); }
    .expert-admin-shell { display:grid; grid-template-columns:minmax(290px, 380px) minmax(0,1fr); gap:1rem; align-items:start; }
    .expert-admin-panel, .expert-admin-card { background:#fff; border:1px solid #e2e8f0; border-radius:8px; box-shadow:0 1px 2px rgba(15,23,42,.04); }
    .expert-admin-panel { padding:1rem; }
    .expert-admin-panel h2, .expert-admin-panel h3 { margin:0; color:#0f172a; letter-spacing:0; }
    .expert-admin-list { display:grid; gap:.55rem; margin-top:.75rem; }
    .expert-admin-link { display:block; padding:.75rem; border:1px solid #e2e8f0; border-radius:8px; color:#0f172a; text-decoration:none; background:#fff; }
    .expert-admin-link.active { border-color:#2563eb; background:#eff6ff; }
    .expert-admin-meta { display:flex; gap:.4rem; flex-wrap:wrap; color:#64748b; font-size:.82rem; margin-top:.35rem; }
    .expert-admin-chip-row { display:flex; gap:.4rem; flex-wrap:wrap; }
    .expert-admin-chip { display:inline-flex; align-items:center; border:1px solid #dbe4f0; border-radius:999px; background:#f8fafc; color:#334155; padding:.24rem .55rem; font-size:.76rem; font-weight:800; line-height:1.2; }
    .expert-admin-chip.approved { border-color:#bbf7d0; background:#f0fdf4; color:#166534; }
    .expert-admin-chip.pending, .expert-admin-chip.draft { border-color:#fed7aa; background:#fff7ed; color:#9a3412; }
    .expert-admin-chip.rejected { border-color:#fecaca; background:#fef2f2; color:#991b1b; }
    .expert-admin-form { display:grid; gap:.85rem; }
    .expert-admin-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(min(100%,230px),1fr)); gap:.8rem; }
    .expert-admin-field { display:grid; gap:.35rem; color:#334155; font-size:.84rem; font-weight:760; }
    .expert-admin-field input, .expert-admin-field select, .expert-admin-field textarea { box-sizing:border-box; width:100%; border:1px solid #cbd5e1; border-radius:8px; padding:.68rem; color:#0f172a; }
    .expert-admin-field textarea { min-height:5.5rem; resize:vertical; line-height:1.45; }
    .expert-admin-profile-head { display:grid; grid-template-columns:126px minmax(0,1fr); gap:1rem; align-items:center; margin-bottom:1rem; }
    .expert-admin-photo, .expert-admin-initials { width:126px; height:126px; border-radius:18px; border:1px solid #e2e8f0; background:#f8fafc; object-fit:cover; }
    .expert-admin-initials { display:grid; place-items:center; background:#0f172a; color:#fff; font-size:1.8rem; font-weight:900; }
    .expert-admin-skill-row { border:1px solid #e2e8f0; border-radius:8px; padding:.75rem; display:grid; gap:.65rem; background:#fff; }
    .expert-admin-actions { display:flex; gap:.55rem; flex-wrap:wrap; margin-top:.85rem; }
    .expert-admin-danger { border-color:#fecaca !important; color:#991b1b !important; background:#fff !important; }
    .expert-admin-danger[disabled] { opacity:.55; cursor:not-allowed; }
    .expert-admin-alert { padding:.75rem; border-radius:8px; margin-bottom:1rem; }
    .expert-admin-alert.notice { background:#ecfdf5; color:#166534; border:1px solid #bbf7d0; }
    .expert-admin-alert.error { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; }
    .expert-admin-empty { border:1px dashed #cbd5e1; border-radius:8px; padding:1rem; background:#f8fafc; color:#64748b; }
    @media (max-width: 1000px) { .expert-admin-shell { grid-template-columns:1fr; } }
    @media (max-width: 560px) { .expert-admin-profile-head { grid-template-columns:1fr; } .expert-admin-form .btn-premium-primary, .expert-admin-form .btn-premium-secondary { width:100%; justify-content:center; } }
</style>

<div class="page-premium expert-admin-page">
    <div class="container">
        <div class="page-header">
            <div>
                <h1>Expert Profiles</h1>
                <p>Manage internal expert profiles, photos, approval state, and verified skill badges shown to owners.</p>
            </div>
            <div class="page-header-actions">
                <a href="owner_support_admin.php" class="btn-premium-secondary">Support Admin</a>
                <a href="owner_help_experts_admin.php?profile_id=0" class="btn-premium-primary">New profile</a>
            </div>
        </div>

        <?php if ($notice !== ''): ?><div class="expert-admin-alert notice"><?php echo $h($notice); ?></div><?php endif; ?>
        <?php if ($error !== ''): ?><div class="expert-admin-alert error"><?php echo $h($error); ?></div><?php endif; ?>

        <div class="expert-admin-shell">
            <aside class="expert-admin-panel">
                <h2>Profiles</h2>
                <div class="expert-admin-list">
                    <?php foreach ($profiles as $profile): ?>
                        <?php $isActive = $selectedProfile && (int) ($selectedProfile['id'] ?? 0) === (int) ($profile['id'] ?? 0); ?>
                        <a class="expert-admin-link <?php echo $isActive ? 'active' : ''; ?>" href="owner_help_experts_admin.php?profile_id=<?php echo (int) ($profile['id'] ?? 0); ?>">
                            <strong><?php echo $h($profile['name'] ?? 'Internal expert'); ?></strong>
                            <div class="expert-admin-meta">
                                <span><?php echo $h($profile['role_label'] ?? 'Setup Specialist'); ?></span>
                                <span>/</span>
                                <span><?php echo $h(ucwords((string) ($profile['profile_status'] ?? 'active'))); ?></span>
                            </div>
                            <div class="expert-admin-chip-row" style="margin-top:.45rem;">
                                <span class="expert-admin-chip <?php echo $h((string) ($profile['approval_status'] ?? 'pending')); ?>"><?php echo $h(ucwords((string) ($profile['approval_status'] ?? 'pending'))); ?></span>
                                <span class="expert-admin-chip">Sort <?php echo (int) ($profile['sort_order'] ?? 100); ?></span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                    <?php if ($profiles === []): ?><div class="expert-admin-empty">No expert profiles yet.</div><?php endif; ?>
                </div>
            </aside>

            <main class="expert-admin-panel">
                <div class="expert-admin-profile-head">
                    <?php if ($photoUrl !== ''): ?>
                        <img class="expert-admin-photo" src="<?php echo $h($photoUrl); ?>" alt="">
                    <?php else: ?>
                        <div class="expert-admin-initials" aria-hidden="true"><?php echo $h($initials($profileName)); ?></div>
                    <?php endif; ?>
                    <div>
                        <h2><?php echo $h($profileName); ?></h2>
                        <div class="expert-admin-chip-row" style="margin-top:.45rem;">
                            <span class="expert-admin-chip <?php echo $h((string) ($selectedProfile['approval_status'] ?? 'draft')); ?>"><?php echo $h(ucwords((string) ($selectedProfile['approval_status'] ?? 'Draft'))); ?></span>
                            <span class="expert-admin-chip"><?php echo $h(ucwords((string) ($selectedProfile['profile_status'] ?? 'active'))); ?></span>
                        </div>
                    </div>
                </div>

                <form method="POST" enctype="multipart/form-data" class="expert-admin-form">
                    <input type="hidden" name="csrf_token" value="<?php echo $h($csrfToken); ?>">
                    <input type="hidden" name="action" value="save_profile">
                    <input type="hidden" name="profile_id" value="<?php echo (int) ($selectedProfile['id'] ?? 0); ?>">
                    <div class="expert-admin-grid">
                        <label class="expert-admin-field">Default workspace user
                            <select name="user_id" required>
                                <option value="">Choose a workspace user</option>
                                <?php foreach ($members as $member): ?>
                                    <option value="<?php echo (int) ($member['id'] ?? 0); ?>" <?php echo (int) ($selectedProfile['user_id'] ?? 0) === (int) ($member['id'] ?? 0) ? 'selected' : ''; ?>>
                                        <?php echo $h($member['email'] ?? 'Member'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="expert-admin-field">Role label
                            <input type="text" name="role_label" value="<?php echo $h($selectedProfile['role_label'] ?? 'Setup Specialist'); ?>">
                        </label>
                        <label class="expert-admin-field">Profile status
                            <select name="profile_status">
                                <?php foreach (['active' => 'Active', 'hidden' => 'Hidden'] as $value => $label): ?>
                                    <option value="<?php echo $h($value); ?>" <?php echo (string) ($selectedProfile['profile_status'] ?? 'active') === $value ? 'selected' : ''; ?>><?php echo $h($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="expert-admin-field">Approval
                            <select name="approval_status">
                                <?php foreach (['draft' => 'Draft', 'pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'] as $value => $label): ?>
                                    <option value="<?php echo $h($value); ?>" <?php echo (string) ($selectedProfile['approval_status'] ?? 'pending') === $value ? 'selected' : ''; ?>><?php echo $h($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="expert-admin-field">Sort order
                            <input type="number" name="sort_order" value="<?php echo $h($selectedProfile['sort_order'] ?? 100); ?>">
                        </label>
                        <label class="expert-admin-field">Public slug
                            <input type="text" name="public_slug" value="<?php echo $h($selectedProfile['public_slug'] ?? ''); ?>" placeholder="optional-public-slug">
                        </label>
                    </div>
                    <label class="expert-admin-field">Headline
                        <input type="text" name="headline" value="<?php echo $h($selectedProfile['headline'] ?? ''); ?>">
                    </label>
                    <label class="expert-admin-field">Bio
                        <textarea name="bio"><?php echo $h($selectedProfile['bio'] ?? ''); ?></textarea>
                    </label>
                    <label class="expert-admin-field">CV / portfolio summary
                        <textarea name="cv_summary"><?php echo $h($selectedProfile['cv_summary'] ?? ''); ?></textarea>
                    </label>
                    <div class="expert-admin-grid">
                        <label class="expert-admin-field">Setup areas
                            <textarea name="setup_areas" placeholder="One per line"><?php echo $h($listLines((array) ($selectedProfile['setup_areas'] ?? []))); ?></textarea>
                        </label>
                        <label class="expert-admin-field">Industries
                            <textarea name="industries" placeholder="One per line"><?php echo $h($listLines((array) ($selectedProfile['industries'] ?? []))); ?></textarea>
                        </label>
                        <label class="expert-admin-field">Languages
                            <textarea name="languages" placeholder="One per line"><?php echo $h($listLines((array) ($selectedProfile['languages'] ?? []))); ?></textarea>
                        </label>
                    </div>
                    <div class="expert-admin-grid">
                        <label class="expert-admin-field">Timezone
                            <input type="text" name="timezone" value="<?php echo $h($selectedProfile['timezone'] ?? ''); ?>">
                        </label>
                        <label class="expert-admin-field">Availability summary
                            <input type="text" name="availability_summary" value="<?php echo $h($selectedProfile['availability_summary'] ?? ''); ?>">
                        </label>
                        <label class="expert-admin-field">Profile photo
                            <input type="file" name="profile_photo" accept="image/jpeg,image/png,image/webp">
                        </label>
                    </div>
                    <label class="expert-admin-field">Rejection note
                        <textarea name="rejection_note"><?php echo $h($selectedProfile['rejection_note'] ?? ''); ?></textarea>
                    </label>
                    <button type="submit" class="btn-premium-primary">Save profile</button>
                </form>

                <?php if (!empty($selectedProfile['id'])): ?>
                    <div class="expert-admin-actions">
                        <form method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo $h($csrfToken); ?>">
                            <input type="hidden" name="action" value="set_profile_status">
                            <input type="hidden" name="profile_id" value="<?php echo (int) ($selectedProfile['id'] ?? 0); ?>">
                            <?php $nextProfileStatus = (string) ($selectedProfile['profile_status'] ?? 'active') === 'hidden' ? 'active' : 'hidden'; ?>
                            <input type="hidden" name="profile_status" value="<?php echo $h($nextProfileStatus); ?>">
                            <button type="submit" class="btn-premium-secondary"><?php echo $nextProfileStatus === 'active' ? 'Show profile' : 'Hide profile'; ?></button>
                        </form>
                        <form method="POST" onsubmit="return confirm('Delete this expert profile? This cannot be undone.');">
                            <input type="hidden" name="csrf_token" value="<?php echo $h($csrfToken); ?>">
                            <input type="hidden" name="action" value="delete_profile">
                            <input type="hidden" name="profile_id" value="<?php echo (int) ($selectedProfile['id'] ?? 0); ?>">
                            <button type="submit" class="btn-premium-secondary expert-admin-danger" <?php echo $selectedActiveRequestCount > 0 ? 'disabled' : ''; ?>>Delete profile</button>
                        </form>
                        <?php if ($selectedActiveRequestCount > 0): ?>
                            <span class="expert-admin-chip pending"><?php echo (int) $selectedActiveRequestCount; ?> active request(s)</span>
                        <?php endif; ?>
                    </div>

                    <form method="POST" class="expert-admin-form" style="margin-top:1rem;border:1px solid #e2e8f0;border-radius:8px;padding:1rem;background:#f8fafc;">
                        <input type="hidden" name="csrf_token" value="<?php echo $h($csrfToken); ?>">
                        <input type="hidden" name="action" value="set_approval">
                        <input type="hidden" name="profile_id" value="<?php echo (int) ($selectedProfile['id'] ?? 0); ?>">
                        <div class="expert-admin-grid">
                            <label class="expert-admin-field">Approval action
                                <select name="approval_status">
                                    <option value="approved">Approve</option>
                                    <option value="pending">Move to pending</option>
                                    <option value="rejected">Reject</option>
                                    <option value="draft">Move to draft</option>
                                </select>
                            </label>
                            <label class="expert-admin-field">Rejection note
                                <input type="text" name="rejection_note" placeholder="Required only when rejecting">
                            </label>
                        </div>
                        <button type="submit" class="btn-premium-secondary">Update approval</button>
                    </form>

                    <section style="margin-top:1rem;display:grid;gap:.75rem;">
                        <h3>Skills</h3>
                        <?php foreach ((array) ($selectedProfile['skills'] ?? []) as $skill): ?>
                            <form method="POST" class="expert-admin-skill-row">
                                <input type="hidden" name="csrf_token" value="<?php echo $h($csrfToken); ?>">
                                <input type="hidden" name="action" value="save_skill">
                                <input type="hidden" name="profile_id" value="<?php echo (int) ($selectedProfile['id'] ?? 0); ?>">
                                <input type="hidden" name="skill_id" value="<?php echo (int) ($skill['id'] ?? 0); ?>">
                                <div class="expert-admin-grid">
                                    <label class="expert-admin-field">Skill label
                                        <input type="text" name="skill_label" value="<?php echo $h($skill['skill_label'] ?? ''); ?>">
                                    </label>
                                    <label class="expert-admin-field">Skill key
                                        <input type="text" name="skill_key" value="<?php echo $h($skill['skill_key'] ?? ''); ?>">
                                    </label>
                                    <label class="expert-admin-field">Verification
                                        <select name="verification_level">
                                            <?php foreach (['self_declared' => 'Self declared', 'platform_verified' => 'Platform verified', 'system_verified' => 'System verified'] as $value => $label): ?>
                                                <option value="<?php echo $h($value); ?>" <?php echo (string) ($skill['verification_level'] ?? '') === $value ? 'selected' : ''; ?>><?php echo $h($label); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>
                                    <label class="expert-admin-field">Sort
                                        <input type="number" name="sort_order" value="<?php echo $h($skill['sort_order'] ?? 100); ?>">
                                    </label>
                                </div>
                                <label class="expert-admin-field">Evidence label
                                    <input type="text" name="evidence_label" value="<?php echo $h($skill['evidence_label'] ?? ''); ?>">
                                </label>
                                <div style="display:flex;gap:.55rem;flex-wrap:wrap;">
                                    <button type="submit" class="btn-premium-secondary">Save skill</button>
                                </div>
                            </form>
                            <form method="POST" style="margin-top:-.45rem;">
                                <input type="hidden" name="csrf_token" value="<?php echo $h($csrfToken); ?>">
                                <input type="hidden" name="action" value="delete_skill">
                                <input type="hidden" name="profile_id" value="<?php echo (int) ($selectedProfile['id'] ?? 0); ?>">
                                <input type="hidden" name="skill_id" value="<?php echo (int) ($skill['id'] ?? 0); ?>">
                                <button type="submit" class="btn-premium-secondary">Remove skill</button>
                            </form>
                        <?php endforeach; ?>

                        <form method="POST" class="expert-admin-skill-row">
                            <input type="hidden" name="csrf_token" value="<?php echo $h($csrfToken); ?>">
                            <input type="hidden" name="action" value="save_skill">
                            <input type="hidden" name="profile_id" value="<?php echo (int) ($selectedProfile['id'] ?? 0); ?>">
                            <h3>Add skill</h3>
                            <div class="expert-admin-grid">
                                <label class="expert-admin-field">Skill label
                                    <input type="text" name="skill_label" placeholder="Email setup">
                                </label>
                                <label class="expert-admin-field">Skill key
                                    <input type="text" name="skill_key" placeholder="email_setup">
                                </label>
                                <label class="expert-admin-field">Verification
                                    <select name="verification_level">
                                        <option value="self_declared">Self declared</option>
                                        <option value="platform_verified">Platform verified</option>
                                        <option value="system_verified">System verified</option>
                                    </select>
                                </label>
                                <label class="expert-admin-field">Sort
                                    <input type="number" name="sort_order" value="100">
                                </label>
                            </div>
                            <label class="expert-admin-field">Evidence label
                                <input type="text" name="evidence_label" placeholder="Reviewed by platform ops">
                            </label>
                            <button type="submit" class="btn-premium-primary">Add skill</button>
                        </form>
                    </section>
                <?php endif; ?>
            </main>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
