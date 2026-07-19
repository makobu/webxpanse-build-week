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
$userId = (int) ($user['id'] ?? 0);
$workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
$supportService = new DefaultWorkspaceOwnerSupportService();
$expertService = new OwnerHelpExpertService();
$expertId = (int) ($_GET['id'] ?? $_POST['expert_profile_id'] ?? 0);
$notice = trim((string) ($_GET['notice'] ?? ''));
$error = trim((string) ($_GET['error'] ?? ''));

function ownerHelpExpertRedirect(int $expertId, array $params = []): void
{
    $params = array_merge(['id' => $expertId], $params);
    header('Location: owner_help_expert.php?' . http_build_query($params));
    exit;
}

$expert = null;
try {
    $supportService->listOwnerCases($workspaceId, $userId);
    $expert = $expertService->find($expertId);
} catch (Throwable $e) {
    $error = $error !== '' ? $error : $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!Security::validateCSRF((string) ($_POST['csrf_token'] ?? ''))) {
            throw new RuntimeException('Security check failed. Refresh the page and try again.');
        }
        if (!$expert) {
            throw new RuntimeException('This expert profile is not available.');
        }
        $case = $supportService->createHelpRequest(
            $workspaceId,
            $userId,
            'account_manager',
            'Expert help request: ' . (string) ($expert['name'] ?? 'Internal expert'),
            (string) ($_POST['message'] ?? ''),
            [
                'expert_profile_id' => (int) ($expert['id'] ?? 0),
                'preferred_contact_method' => (string) ($_POST['preferred_contact_method'] ?? ''),
                'preferred_time' => (string) ($_POST['preferred_time'] ?? ''),
                'owner_goal' => (string) ($_POST['message'] ?? ''),
            ]
        );
        header('Location: owner_support.php?tab=requests&case_id=' . (int) ($case['id'] ?? 0) . '&notice=' . urlencode('Expert request opened.'));
        exit;
    } catch (Throwable $e) {
        ownerHelpExpertRedirect($expertId, ['error' => $e->getMessage()]);
    }
}

http_response_code($expert ? 200 : 404);

$csrfToken = Security::getCsrfToken();
$h = static fn(mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
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
$expertName = $expert ? (string) ($expert['name'] ?? 'Internal expert') : 'Expert profile';
$photoUrl = $expert ? $mediaUrl((string) ($expert['profile_photo_path'] ?? '')) : '';
$verifiedSkills = $expert ? array_values(array_filter((array) ($expert['skills'] ?? []), static fn($skill): bool => !empty($skill['is_verified']))) : [];
$unverifiedSkills = $expert ? array_values(array_filter((array) ($expert['skills'] ?? []), static fn($skill): bool => empty($skill['is_verified']))) : [];

$pageTitle = $expertName . ' - Expert Profile - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="assets/css/premium-pages.css">
<style>
    .expert-profile-page { background:#f6f8fb; min-height:calc(100vh - 80px); }
    .expert-profile-shell { display:grid; grid-template-columns:minmax(280px, 420px) minmax(0,1fr); gap:1rem; align-items:start; }
    .expert-profile-card, .expert-profile-panel { background:#fff; border:1px solid #e2e8f0; border-radius:8px; box-shadow:0 1px 2px rgba(15,23,42,.04); }
    .expert-profile-card { overflow:hidden; }
    .expert-profile-portrait { min-height:250px; background:linear-gradient(135deg,#e0f2fe,#f8fafc 55%,#dcfce7); display:flex; align-items:flex-end; padding:1.25rem; }
    .expert-profile-photo { width:190px; height:190px; border-radius:22px; object-fit:cover; border:5px solid rgba(255,255,255,.84); box-shadow:0 22px 48px rgba(15,23,42,.18); background:#fff; }
    .expert-profile-initials { width:190px; height:190px; border-radius:22px; display:grid; place-items:center; background:#0f172a; color:#f8fafc; font-size:2.7rem; font-weight:900; border:5px solid rgba(255,255,255,.84); box-shadow:0 22px 48px rgba(15,23,42,.18); }
    .expert-profile-card-body { padding:1rem; display:grid; gap:.75rem; }
    .expert-profile-card-body h1 { margin:0; color:#0f172a; letter-spacing:0; font-size:1.55rem; }
    .expert-profile-meta { display:flex; gap:.4rem; flex-wrap:wrap; color:#64748b; font-size:.86rem; }
    .expert-profile-chip-row { display:flex; flex-wrap:wrap; gap:.4rem; }
    .expert-profile-chip { display:inline-flex; align-items:center; border:1px solid #dbe4f0; border-radius:999px; background:#f8fafc; color:#334155; padding:.25rem .58rem; font-size:.78rem; font-weight:820; line-height:1.2; }
    .expert-profile-chip.is-verified { border-color:#bbf7d0; background:#f0fdf4; color:#166534; }
    .expert-profile-panel { padding:1rem; display:grid; gap:1rem; }
    .expert-profile-panel h2, .expert-profile-panel h3 { margin:0; color:#0f172a; letter-spacing:0; }
    .expert-profile-section { display:grid; gap:.55rem; }
    .expert-profile-section p { margin:0; color:#475569; line-height:1.55; }
    .expert-profile-list { display:flex; flex-wrap:wrap; gap:.45rem; }
    .expert-profile-form { display:grid; gap:.8rem; border:1px solid #e2e8f0; background:#f8fafc; border-radius:8px; padding:1rem; }
    .expert-profile-form-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(min(100%,230px),1fr)); gap:.8rem; }
    .expert-profile-field { display:grid; gap:.35rem; color:#334155; font-size:.86rem; font-weight:760; }
    .expert-profile-field input, .expert-profile-field textarea { box-sizing:border-box; width:100%; border:1px solid #cbd5e1; border-radius:8px; padding:.7rem .78rem; color:#0f172a; font-size:.92rem; }
    .expert-profile-field textarea { min-height:7.5rem; resize:vertical; line-height:1.5; }
    .expert-profile-alert { padding:.75rem; border-radius:8px; margin-bottom:1rem; }
    .expert-profile-alert.notice { background:#ecfdf5; color:#166534; border:1px solid #bbf7d0; }
    .expert-profile-alert.error { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; }
    .expert-profile-empty { border:1px dashed #cbd5e1; border-radius:8px; padding:1rem; background:#f8fafc; color:#64748b; }
    @media (max-width: 900px) { .expert-profile-shell { grid-template-columns:1fr; } }
    @media (max-width: 560px) {
        .expert-profile-photo, .expert-profile-initials { width:142px; height:142px; }
        .expert-profile-portrait { min-height:190px; }
        .expert-profile-form .btn-premium-primary, .expert-profile-form .btn-premium-secondary { width:100%; justify-content:center; }
    }
</style>

<div class="page-premium expert-profile-page">
    <div class="container">
        <div class="page-header">
            <div>
                <h1>Expert Profile</h1>
                <p>Review the profile, verified skills, availability, and request a quote for expert help.</p>
            </div>
            <div class="page-header-actions">
                <a href="owner_support.php?tab=experts" class="btn-premium-secondary"><i class="fas fa-arrow-left" aria-hidden="true"></i> Experts</a>
            </div>
        </div>

        <?php if ($notice !== ''): ?><div class="expert-profile-alert notice"><?php echo $h($notice); ?></div><?php endif; ?>
        <?php if ($error !== ''): ?><div class="expert-profile-alert error"><?php echo $h($error); ?></div><?php endif; ?>

        <?php if (!$expert): ?>
            <div class="expert-profile-empty">This expert profile is not available.</div>
        <?php else: ?>
            <div class="expert-profile-shell">
                <aside class="expert-profile-card">
                    <div class="expert-profile-portrait" aria-hidden="true">
                        <?php if ($photoUrl !== ''): ?>
                            <img class="expert-profile-photo" src="<?php echo $h($photoUrl); ?>" alt="">
                        <?php else: ?>
                            <div class="expert-profile-initials"><?php echo $h($initials($expertName)); ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="expert-profile-card-body">
                        <div>
                            <h1><?php echo $h($expertName); ?></h1>
                            <div class="expert-profile-meta">
                                <span><?php echo $h($expert['role_label'] ?? 'Setup Specialist'); ?></span>
                                <?php if (!empty($expert['timezone'])): ?><span>/</span><span><?php echo $h($expert['timezone']); ?></span><?php endif; ?>
                            </div>
                        </div>
                        <p style="margin:0;color:#334155;line-height:1.5;"><?php echo $h($expert['headline'] ?? 'Internal verified CRM expert.'); ?></p>
                        <div class="expert-profile-chip-row">
                            <?php foreach (array_slice($verifiedSkills, 0, 5) as $skill): ?>
                                <span class="expert-profile-chip is-verified"><?php echo $h($skill['skill_label'] ?? 'Skill'); ?> verified</span>
                            <?php endforeach; ?>
                            <?php if ($verifiedSkills === []): ?><span class="expert-profile-chip">Verified profile pending</span><?php endif; ?>
                        </div>
                        <?php if (!empty($expert['availability_summary'])): ?>
                            <div class="expert-profile-section">
                                <h3>Availability</h3>
                                <p><?php echo $h($expert['availability_summary']); ?></p>
                            </div>
                        <?php endif; ?>
                    </div>
                </aside>

                <main class="expert-profile-panel">
                    <section class="expert-profile-section">
                        <h2>Profile</h2>
                        <?php if (!empty($expert['bio'])): ?><p><?php echo nl2br($h($expert['bio'])); ?></p><?php endif; ?>
                        <?php if (!empty($expert['cv_summary'])): ?><p><?php echo nl2br($h($expert['cv_summary'])); ?></p><?php endif; ?>
                    </section>

                    <?php foreach ([
                        'Setup areas' => (array) ($expert['setup_areas'] ?? []),
                        'Industry experience' => (array) ($expert['industries'] ?? []),
                        'Languages' => (array) ($expert['languages'] ?? []),
                    ] as $label => $values): ?>
                        <?php if ($values !== []): ?>
                            <section class="expert-profile-section">
                                <h3><?php echo $h($label); ?></h3>
                                <div class="expert-profile-list">
                                    <?php foreach ($values as $value): ?><span class="expert-profile-chip"><?php echo $h($value); ?></span><?php endforeach; ?>
                                </div>
                            </section>
                        <?php endif; ?>
                    <?php endforeach; ?>

                    <section class="expert-profile-section">
                        <h3>Skills</h3>
                        <div class="expert-profile-list">
                            <?php foreach ($verifiedSkills as $skill): ?>
                                <span class="expert-profile-chip is-verified"><?php echo $h($skill['skill_label'] ?? 'Skill'); ?> verified</span>
                            <?php endforeach; ?>
                            <?php foreach ($unverifiedSkills as $skill): ?>
                                <span class="expert-profile-chip"><?php echo $h($skill['skill_label'] ?? 'Skill'); ?></span>
                            <?php endforeach; ?>
                        </div>
                    </section>

                    <form method="POST" class="expert-profile-form">
                        <input type="hidden" name="csrf_token" value="<?php echo $h($csrfToken); ?>">
                        <input type="hidden" name="expert_profile_id" value="<?php echo (int) ($expert['id'] ?? 0); ?>">
                        <h2>Request this expert</h2>
                        <div class="expert-profile-form-grid">
                            <label class="expert-profile-field">Preferred contact
                                <input type="text" name="preferred_contact_method" placeholder="Email, WhatsApp, or phone">
                            </label>
                            <label class="expert-profile-field">Preferred time
                                <input type="text" name="preferred_time" placeholder="This week, mornings, etc.">
                            </label>
                        </div>
                        <label class="expert-profile-field">What should they help with?
                            <textarea name="message" required placeholder="Describe the setup, strategy, installation, or account-management help you need."></textarea>
                        </label>
                        <button type="submit" class="btn-premium-primary">Request expert quote</button>
                    </form>
                </main>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
