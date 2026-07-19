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
use CRM\Services\AIRuntimeControlService;
use CRM\Services\AutoAdminService;
use CRM\Services\MarketplacePageExplainerService;
use CRM\Services\PageGuideVideoUi;
use CRM\Services\WorkspaceContext;
use CRM\Session;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$user = Auth::user();
if (!Authorization::can('ai.operations.manage', $user)) {
    header('Location: dashboard.php');
    exit;
}

$service = new AIRuntimeControlService();
$autoAdminService = new AutoAdminService();
$activeWorkspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
$autoAdminEnabled = $autoAdminService->isEnabledForWorkspace($activeWorkspaceId);
$autoAdminDisabledAttr = $activeWorkspaceId <= 0 ? 'disabled' : '';
$csrfToken = Security::getCsrfToken();
$message = null;
$error = null;
$userId = (int) ($user['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token.';
    } elseif ($activeWorkspaceId <= 0) {
        $error = 'An active workspace is required for runtime controls.';
    } else {
        $action = trim((string) ($_POST['action'] ?? ''));
        $reason = trim((string) ($_POST['reason'] ?? ''));
        try {
            if ($action === 'set_control') {
                $surface = trim((string) ($_POST['surface'] ?? ''));
                $mode = trim((string) ($_POST['mode'] ?? 'normal'));
                $expiresAt = trim((string) ($_POST['expires_at'] ?? ''));
                if ($mode === 'normal') {
                    $service->clearControl($surface, $userId, $reason !== '' ? $reason : 'Restored from control center', $activeWorkspaceId);
                } else {
                    $service->setControl(
                        $surface,
                        $mode,
                        $userId,
                        $reason !== '' ? $reason : 'Updated from control center',
                        $expiresAt !== '' ? $expiresAt : null,
                        ['source' => 'ai_control_center'],
                        $activeWorkspaceId
                    );
                }
                $message = 'Runtime control updated.';
            } elseif ($action === 'apply_preset') {
                $preset = trim((string) ($_POST['preset'] ?? ''));
                $presetMap = [
                    'pause_all_execution' => [
                        'assistant' => 'paused',
                        'customer_thread' => 'paused',
                        'commercial_assistant' => 'paused',
                        'workflow' => 'paused',
                        'task_automation' => 'paused',
                        'autonomous_tuning' => 'paused',
                    ],
                    'safe_mode' => [
                        'coach' => 'suggest_only',
                        'clarity_chat' => 'suggest_only',
                        'assistant' => 'suggest_only',
                        'customer_thread' => 'suggest_only',
                        'commercial_assistant' => 'suggest_only',
                        'workflow' => 'suggest_only',
                        'task_automation' => 'paused',
                        'autonomous_tuning' => 'diagnostics_only',
                    ],
                ];

                if ($preset === 'restore_normal') {
                    foreach ($service->listSurfaces() as $surface) {
                        $service->clearControl($surface, $userId, $reason !== '' ? $reason : 'Restore normal preset', $activeWorkspaceId);
                    }
                } elseif (isset($presetMap[$preset])) {
                    foreach ($presetMap[$preset] as $surface => $mode) {
                        $service->setControl(
                            $surface,
                            $mode,
                            $userId,
                            $reason !== '' ? $reason : ('Applied preset: ' . $preset),
                            null,
                            ['source' => 'ai_control_center', 'preset' => $preset],
                            $activeWorkspaceId
                        );
                    }
                } else {
                    throw new InvalidArgumentException('Unknown preset.');
                }

                $message = 'Preset applied.';
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$controls = $service->getAllEffectiveControls($activeWorkspaceId);
$configuredControls = [];
foreach ($service->getConfiguredControls($activeWorkspaceId) as $row) {
    $configuredControls[(string) ($row['surface'] ?? '')] = $row;
}
$log = $service->getRecentLog(30, $activeWorkspaceId);
$activeControlCount = count(array_filter($controls, static fn(array $control): bool => (string) ($control['control_mode'] ?? 'normal') !== 'normal'));

function aiControlModeColor(string $mode): string
{
    return match ($mode) {
        'paused' => '#b91c1c',
        'suggest_only' => '#b45309',
        'diagnostics_only' => '#475569',
        default => '#0f766e',
    };
}

$pageTitle = 'AI Control Center - ' . brandProductName();
$aiControlCenterGuideVideoUrl = PageGuideVideoUi::activeVideoUrl(MarketplacePageExplainerService::PAGE_AI_CONTROL_CENTER);
ob_start();
?>
<?php echo PageGuideVideoUi::assets(); ?>
<div class="page-premium">
    <div class="container">
        <div class="page-header">
            <div>
                <h1>AI Control Center</h1>
                <p>Apply live runtime controls to AI surfaces without changing persistent policy or diagnostics behavior.</p>
            </div>
            <div class="page-header-actions">
                <?php if ($aiControlCenterGuideVideoUrl !== ''): ?>
                    <?php echo PageGuideVideoUi::button(MarketplacePageExplainerService::PAGE_AI_CONTROL_CENTER, 'AI Control Center page guide', 'btn-premium-secondary'); ?>
                <?php endif; ?>
                <a href="ai_learning_review.php" class="btn-premium-secondary">Learning review</a>
                <a href="ai_cross_domain_orchestrator.php" class="btn-premium-secondary">Cross-domain orchestration</a>
                <a href="ai_recovery_workbench.php" class="btn-premium-secondary">Recovery workbench</a>
                <a href="ai_automation_diagnostics.php" class="btn-premium-secondary">Open diagnostics</a>
                <a href="settings.php?tab=general" class="btn-premium-secondary">Settings</a>
            </div>
        </div>

        <?php if ($message): ?><div class="alert alert-success" style="margin-bottom:1rem;"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error" style="margin-bottom:1rem;"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
        <?php if ($autoAdminEnabled): ?>
            <div class="alert alert-info" style="margin-bottom:1rem;">
                Auto Admin is active for this workspace. Runtime controls remain available as operator safety overrides and can cap managed automation immediately.
            </div>
        <?php endif; ?>
        <?php if ($activeWorkspaceId <= 0): ?>
            <div class="alert alert-error" style="margin-bottom:1rem;">
                Select an active workspace before changing runtime controls.
            </div>
        <?php endif; ?>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;margin-bottom:1rem;">
            <div class="content-card">
                <div style="font-size:12px;color:#64748b;text-transform:uppercase;">Active controls</div>
                <div style="font-size:28px;font-weight:700;margin-top:.35rem;"><?php echo $activeControlCount; ?></div>
            </div>
            <div class="content-card">
                <div style="font-size:12px;color:#64748b;text-transform:uppercase;">Paused surfaces</div>
                <div style="font-size:28px;font-weight:700;margin-top:.35rem;"><?php echo count(array_filter($controls, static fn(array $control): bool => ($control['control_mode'] ?? '') === 'paused')); ?></div>
            </div>
            <div class="content-card">
                <div style="font-size:12px;color:#64748b;text-transform:uppercase;">Suggest-only surfaces</div>
                <div style="font-size:28px;font-weight:700;margin-top:.35rem;"><?php echo count(array_filter($controls, static fn(array $control): bool => ($control['control_mode'] ?? '') === 'suggest_only')); ?></div>
            </div>
            <div class="content-card">
                <div style="font-size:12px;color:#64748b;text-transform:uppercase;">Tuning mode</div>
                <div style="font-size:20px;font-weight:700;margin-top:.35rem;"><?php echo htmlspecialchars((string) ($controls['autonomous_tuning']['control_mode'] ?? 'normal')); ?></div>
            </div>
        </div>

        <div class="content-card" style="margin-bottom:1rem;">
            <h2 style="margin-top:0;">Emergency presets</h2>
            <div style="display:flex;gap:.75rem;flex-wrap:wrap;">
                <?php foreach ([
                    'pause_all_execution' => 'Pause all execution',
                    'safe_mode' => 'Safe mode',
                    'restore_normal' => 'Restore normal',
                ] as $preset => $label): ?>
                    <form method="POST" style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                        <input type="hidden" name="action" value="apply_preset">
                        <input type="hidden" name="preset" value="<?php echo htmlspecialchars($preset); ?>">
                        <input type="text" name="reason" placeholder="Reason" <?php echo $autoAdminDisabledAttr; ?> style="padding:.65rem;border:1px solid var(--border-color);border-radius:8px;min-width:200px;">
                        <button type="submit" class="btn-premium-secondary" <?php echo $autoAdminDisabledAttr; ?>><?php echo htmlspecialchars($label); ?></button>
                    </form>
                <?php endforeach; ?>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:minmax(0,1.1fr) minmax(360px,.9fr);gap:1rem;align-items:start;">
            <div class="content-card">
                <h2 style="margin-top:0;">Surface controls</h2>
                <div style="display:grid;gap:.75rem;">
                    <?php foreach ($service->listSurfaces() as $surface): ?>
                        <?php
                        $control = $controls[$surface] ?? ['control_mode' => 'normal'];
                        $configured = $configuredControls[$surface] ?? null;
                        $mode = (string) ($control['control_mode'] ?? 'normal');
                        ?>
                        <div style="padding:1rem;border:1px solid var(--border-color);border-radius:12px;background:#fff;">
                            <div style="display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;flex-wrap:wrap;">
                                <div>
                                    <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
                                        <strong><?php echo htmlspecialchars($surface); ?></strong>
                                        <span style="display:inline-block;padding:4px 8px;border-radius:999px;background:<?php echo aiControlModeColor($mode); ?>15;color:<?php echo aiControlModeColor($mode); ?>;font-size:12px;font-weight:700;"><?php echo htmlspecialchars($mode); ?></span>
                                    </div>
                                    <div style="margin-top:.4rem;color:#64748b;font-size:.9rem;">
                                        <?php echo htmlspecialchars((string) ($control['reason'] ?? 'No override active.')); ?>
                                    </div>
                                    <div style="margin-top:.35rem;color:#94a3b8;font-size:.8rem;">
                                        Set at: <?php echo htmlspecialchars((string) ($configured['set_at'] ?? $control['set_at'] ?? 'n/a')); ?>
                                        <?php if (!empty($configured['expires_at'] ?? $control['expires_at'] ?? '')): ?>
                                            | Expires: <?php echo htmlspecialchars((string) ($configured['expires_at'] ?? $control['expires_at'])); ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <form method="POST" style="display:grid;grid-template-columns:minmax(150px,180px) minmax(0,1fr) minmax(0,200px) auto;gap:.6rem;align-items:end;margin-top:.85rem;">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                <input type="hidden" name="action" value="set_control">
                                <input type="hidden" name="surface" value="<?php echo htmlspecialchars($surface); ?>">
                                <div>
                                    <label style="display:block;margin-bottom:.35rem;font-weight:600;">Mode</label>
                                    <select name="mode" <?php echo $autoAdminDisabledAttr; ?> style="width:100%;padding:.65rem;border:1px solid var(--border-color);border-radius:8px;">
                                        <?php foreach ($service->listModes() as $candidateMode): ?>
                                            <option value="<?php echo htmlspecialchars($candidateMode); ?>" <?php echo $candidateMode === $mode ? 'selected' : ''; ?>><?php echo htmlspecialchars($candidateMode); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div>
                                    <label style="display:block;margin-bottom:.35rem;font-weight:600;">Reason</label>
                                    <input type="text" name="reason" placeholder="Why are you changing this surface?" <?php echo $autoAdminDisabledAttr; ?> style="width:100%;padding:.65rem;border:1px solid var(--border-color);border-radius:8px;">
                                </div>
                                <div>
                                    <label style="display:block;margin-bottom:.35rem;font-weight:600;">Expires at</label>
                                    <input type="datetime-local" name="expires_at" <?php echo $autoAdminDisabledAttr; ?> style="width:100%;padding:.65rem;border:1px solid var(--border-color);border-radius:8px;">
                                </div>
                                <button type="submit" class="btn-premium-primary" <?php echo $autoAdminDisabledAttr; ?>>Apply</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="content-card">
                <h2 style="margin-top:0;">Recent control changes</h2>
                <?php if (!$log): ?>
                    <p style="margin:0;color:#64748b;">No control changes have been logged yet.</p>
                <?php else: ?>
                    <div style="display:grid;gap:.75rem;">
                        <?php foreach ($log as $entry): ?>
                            <div style="padding:.9rem;border:1px solid var(--border-color);border-radius:10px;background:#fff;">
                                <div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
                                    <div>
                                        <div style="font-weight:700;"><?php echo htmlspecialchars((string) ($entry['surface'] ?? 'global')); ?></div>
                                        <div style="margin-top:.25rem;color:#475569;font-size:.9rem;">
                                            <?php echo htmlspecialchars((string) ($entry['previous_mode'] ?? 'normal')); ?> → <?php echo htmlspecialchars((string) ($entry['new_mode'] ?? 'normal')); ?>
                                        </div>
                                        <div style="margin-top:.35rem;color:#64748b;font-size:.85rem;"><?php echo htmlspecialchars((string) ($entry['reason'] ?? '')); ?></div>
                                    </div>
                                    <div style="text-align:right;color:#64748b;font-size:.85rem;">
                                        <div><?php echo htmlspecialchars((string) ($entry['set_at'] ?? '')); ?></div>
                                        <?php if (!empty($entry['expires_at'])): ?><div style="margin-top:.25rem;">Expires <?php echo htmlspecialchars((string) $entry['expires_at']); ?></div><?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php echo PageGuideVideoUi::modal(MarketplacePageExplainerService::PAGE_AI_CONTROL_CENTER, 'How to use AI Control Center', $aiControlCenterGuideVideoUrl); ?>
<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
