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
use CRM\Services\AIPromptDryRunService;
use CRM\Services\AIPromptQualityService;
use CRM\Services\AIPromptRegistryService;
use CRM\Services\DefaultWorkspaceProtectedActionService;
use CRM\Services\WorkspaceContext;
use CRM\Session;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

if (!Auth::check()) {
    header('Location: login.php');
    exit;
}

$user = Auth::user();
if (!Authorization::can('ai.prompt_control.manage', $user)) {
    header('Location: dashboard.php');
    exit;
}

$registry = new AIPromptRegistryService();
$quality = new AIPromptQualityService();
$dryRunService = new AIPromptDryRunService();
$csrfToken = Security::getCsrfToken();
$error = null;
$success = null;
$dryRunResult = null;
$isDefaultWorkspacePromptControl = WorkspaceContext::isDefaultWorkspace((int) (WorkspaceContext::currentWorkspaceId() ?? 0));

function buildPromptDiffRows(string $left, string $right): array
{
    $leftLines = preg_split("/\r\n|\n|\r/", $left) ?: [];
    $rightLines = preg_split("/\r\n|\n|\r/", $right) ?: [];
    $max = max(count($leftLines), count($rightLines));
    $rows = [];
    for ($i = 0; $i < $max; $i++) {
        $a = $leftLines[$i] ?? '';
        $b = $rightLines[$i] ?? '';
        $rows[] = [
            'left' => $a,
            'right' => $b,
            'same' => $a === $b,
        ];
    }

    return $rows;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRF($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token.';
    } else {
        $action = trim((string) ($_POST['action'] ?? ''));
        if ($action === 'register_prompt') {
            if ($isDefaultWorkspacePromptControl) {
                (new DefaultWorkspaceProtectedActionService())->authorize(
                    'default_workspace_prompt_override_register',
                    $user,
                    (int) (WorkspaceContext::currentWorkspaceId() ?? 0),
                    (string) ($_POST['default_workspace_reason'] ?? ''),
                    ['surface' => trim((string) ($_POST['surface'] ?? '')), 'prompt_key' => trim((string) ($_POST['prompt_key'] ?? ''))],
                    true
                );
            }
            $id = $registry->registerPrompt([
                'surface' => trim((string) ($_POST['surface'] ?? '')),
                'prompt_key' => trim((string) ($_POST['prompt_key'] ?? '')),
                'status' => trim((string) ($_POST['status'] ?? 'draft')),
                'system_prompt_text' => (string) ($_POST['system_prompt_text'] ?? ''),
                'instruction_text' => (string) ($_POST['instruction_text'] ?? ''),
                'output_contract_json' => json_decode((string) ($_POST['output_contract_json'] ?? 'null'), true),
                'metadata_json' => ['registered_from' => 'ai_prompt_control'],
                'created_by' => (int) ($user['id'] ?? 0),
            ]);
            if ($id > 0) {
                if ($isDefaultWorkspacePromptControl) {
                    (new DefaultWorkspaceProtectedActionService())->auditSuccess(
                        'default_workspace_prompt_override_registered',
                        (int) ($user['id'] ?? 0),
                        (int) (WorkspaceContext::currentWorkspaceId() ?? 0),
                        (string) ($_POST['default_workspace_reason'] ?? ''),
                        [],
                        ['prompt_id' => $id]
                    );
                }
                $success = 'Prompt version registered.';
            } else {
                $error = 'Unable to register prompt version.';
            }
        } elseif ($action === 'activate_prompt') {
            if ($isDefaultWorkspacePromptControl) {
                (new DefaultWorkspaceProtectedActionService())->authorize(
                    'default_workspace_prompt_override_activate',
                    $user,
                    (int) (WorkspaceContext::currentWorkspaceId() ?? 0),
                    (string) ($_POST['default_workspace_reason'] ?? ''),
                    ['surface' => trim((string) ($_POST['surface'] ?? '')), 'prompt_key' => trim((string) ($_POST['prompt_key'] ?? '')), 'version' => (int) ($_POST['version'] ?? 0)],
                    true
                );
            }
            $ok = $registry->activatePromptVersion(
                trim((string) ($_POST['surface'] ?? '')),
                trim((string) ($_POST['prompt_key'] ?? '')),
                (int) ($_POST['version'] ?? 0)
            );
            if ($ok) {
                if ($isDefaultWorkspacePromptControl) {
                    (new DefaultWorkspaceProtectedActionService())->auditSuccess(
                        'default_workspace_prompt_override_activated',
                        (int) ($user['id'] ?? 0),
                        (int) (WorkspaceContext::currentWorkspaceId() ?? 0),
                        (string) ($_POST['default_workspace_reason'] ?? ''),
                        [],
                        ['version' => (int) ($_POST['version'] ?? 0)]
                    );
                }
                $success = 'Prompt version activated.';
            } else {
                $error = 'Unable to activate prompt version.';
            }
        } elseif ($action === 'dry_run_prompt') {
            $sampleInputRaw = (string) ($_POST['sample_input_json'] ?? '{}');
            $sampleInput = json_decode($sampleInputRaw, true);
            if (!is_array($sampleInput)) {
                $error = 'Sample input must be valid JSON.';
            } else {
                $dryRunResult = $dryRunService->dryRun(
                    trim((string) ($_POST['surface'] ?? '')),
                    trim((string) ($_POST['prompt_key'] ?? '')),
                    (int) ($_POST['version'] ?? 0),
                    $sampleInput,
                    (int) ($_POST['max_blocks'] ?? 12),
                    (int) ($_POST['max_chars'] ?? 12000)
                );
                $success = 'Dry run generated.';
            }
        }
    }
}

$prompts = $quality->getActivePromptSummary();
$recentChanges = $quality->getRecentPromptEvents();
$selectedSurface = trim((string) ($_GET['surface'] ?? ($prompts[0]['surface'] ?? 'coach')));
$selectedPromptKey = trim((string) ($_GET['prompt_key'] ?? ($prompts[0]['prompt_key'] ?? 'coach_recommendations')));
$history = $registry->getPromptHistory($selectedSurface, $selectedPromptKey);
$selectedVersion = max(0, (int) ($_GET['version'] ?? ($history[0]['version'] ?? 0)));
$compareVersion = max(0, (int) ($_GET['compare_version'] ?? ($history[1]['version'] ?? 0)));
$selectedPrompt = $selectedVersion > 0 ? $registry->getPromptVersion($selectedSurface, $selectedPromptKey, $selectedVersion) : ($history[0] ?? null);
$comparePrompt = ($compareVersion > 0 && $compareVersion !== $selectedVersion)
    ? $registry->getPromptVersion($selectedSurface, $selectedPromptKey, $compareVersion)
    : null;
$comparison = ($selectedPrompt && $comparePrompt)
    ? $quality->getPromptVersionComparison($selectedSurface, $selectedPromptKey, (int) ($selectedPrompt['version'] ?? 0), (int) ($comparePrompt['version'] ?? 0))
    : null;
$diffRows = ($selectedPrompt && $comparePrompt)
    ? buildPromptDiffRows(
        (string) ($selectedPrompt['system_prompt_text'] ?? '') . "\n\n" . (string) ($selectedPrompt['instruction_text'] ?? ''),
        (string) ($comparePrompt['system_prompt_text'] ?? '') . "\n\n" . (string) ($comparePrompt['instruction_text'] ?? '')
    )
    : [];

$pageTitle = 'AI Prompt Control - ' . brandProductName();
ob_start();
?>
<div class="page-premium">
    <div class="container">
        <div class="page-header">
            <div>
                <h1>AI Prompt Control</h1>
                <p>Inspect active prompt versions, retrieval quality, and prompt history. Roll back safely by activating an older version.</p>
            </div>
        </div>

        <?php if ($success): ?><div class="alert alert-success" style="margin-bottom:1rem;"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error" style="margin-bottom:1rem;"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

        <div style="display:grid;grid-template-columns:1.1fr .9fr;gap:1rem;align-items:start;">
            <div class="content-card">
                <h2 style="margin-top:0;">Active Prompts</h2>
                <?php if (!$prompts): ?>
                    <p style="color:#64748b;">No active prompt registry rows found.</p>
                <?php else: ?>
                    <div style="display:grid;gap:.75rem;">
                        <?php foreach ($prompts as $prompt): ?>
                            <a href="?surface=<?php echo urlencode((string) $prompt['surface']); ?>&prompt_key=<?php echo urlencode((string) $prompt['prompt_key']); ?>" style="display:block;padding:1rem;border:1px solid var(--border-color);border-radius:12px;text-decoration:none;color:inherit;">
                                <div style="display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;">
                                    <div>
                                        <div style="font-weight:700;"><?php echo htmlspecialchars((string) $prompt['surface']); ?> / <?php echo htmlspecialchars((string) $prompt['prompt_key']); ?></div>
                                        <div style="color:#64748b;font-size:.92rem;margin-top:.3rem;">Version <?php echo (int) $prompt['version']; ?> | Quality <?php echo number_format((float) ($prompt['recent_quality_score'] ?? 0), 2); ?></div>
                                    </div>
                                    <span style="display:inline-block;padding:4px 8px;border-radius:999px;background:#eff6ff;color:#1d4ed8;font-size:12px;font-weight:700;"><?php echo htmlspecialchars((string) $prompt['status']); ?></span>
                                </div>
                                <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.55rem;color:#64748b;font-size:.85rem;">
                                    <span>Runs <?php echo (int) ($prompt['recent_run_count'] ?? 0); ?></span>
                                    <span>Stale <?php echo number_format((float) ($prompt['stale_context_rate'] ?? 0) * 100, 1); ?>%</span>
                                    <span>Overload <?php echo number_format((float) ($prompt['overload_rate'] ?? 0) * 100, 1); ?>%</span>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="content-card">
                <h2 style="margin-top:0;">Register Prompt Version</h2>
                <form method="POST" style="display:grid;gap:.75rem;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="action" value="register_prompt">
                    <div><label style="display:block;margin-bottom:.35rem;">Surface</label><input type="text" name="surface" required style="width:100%;padding:.65rem;border:1px solid var(--border-color);border-radius:8px;"></div>
                    <div><label style="display:block;margin-bottom:.35rem;">Prompt key</label><input type="text" name="prompt_key" required style="width:100%;padding:.65rem;border:1px solid var(--border-color);border-radius:8px;"></div>
                    <div><label style="display:block;margin-bottom:.35rem;">Status</label><select name="status" style="width:100%;padding:.65rem;border:1px solid var(--border-color);border-radius:8px;"><option value="draft">Draft</option><option value="active">Active</option></select></div>
                    <div><label style="display:block;margin-bottom:.35rem;">System prompt</label><textarea name="system_prompt_text" rows="4" required style="width:100%;padding:.65rem;border:1px solid var(--border-color);border-radius:8px;"></textarea></div>
                    <div><label style="display:block;margin-bottom:.35rem;">Instructions</label><textarea name="instruction_text" rows="6" required style="width:100%;padding:.65rem;border:1px solid var(--border-color);border-radius:8px;"></textarea></div>
                    <div><label style="display:block;margin-bottom:.35rem;">Output contract JSON</label><textarea name="output_contract_json" rows="4" style="width:100%;padding:.65rem;border:1px solid var(--border-color);border-radius:8px;">{"type":"text"}</textarea></div>
                    <?php if ($isDefaultWorkspacePromptControl): ?>
                        <div><label style="display:block;margin-bottom:.35rem;">Default workspace edit reason</label><input type="text" name="default_workspace_reason" required value="Register Platform Ops prompt override" style="width:100%;padding:.65rem;border:1px solid var(--border-color);border-radius:8px;"></div>
                    <?php endif; ?>
                    <button type="submit" class="btn-premium-primary">Create Prompt Version</button>
                </form>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-top:1rem;align-items:start;">
            <div class="content-card">
                <h2 style="margin-top:0;">Prompt History</h2>
                <p style="color:#64748b;">Selected: <?php echo htmlspecialchars($selectedSurface . ' / ' . $selectedPromptKey); ?></p>
                <form method="GET" style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin-bottom:1rem;">
                    <input type="hidden" name="surface" value="<?php echo htmlspecialchars($selectedSurface); ?>">
                    <input type="hidden" name="prompt_key" value="<?php echo htmlspecialchars($selectedPromptKey); ?>">
                    <div>
                        <label style="display:block;margin-bottom:.35rem;">Primary version</label>
                        <select name="version" style="width:100%;padding:.65rem;border:1px solid var(--border-color);border-radius:8px;">
                            <?php foreach ($history as $prompt): ?>
                                <option value="<?php echo (int) $prompt['version']; ?>" <?php echo $selectedVersion === (int) $prompt['version'] ? 'selected' : ''; ?>>
                                    Version <?php echo (int) $prompt['version']; ?> (<?php echo htmlspecialchars((string) $prompt['status']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label style="display:block;margin-bottom:.35rem;">Compare against</label>
                        <select name="compare_version" style="width:100%;padding:.65rem;border:1px solid var(--border-color);border-radius:8px;">
                            <option value="0">None</option>
                            <?php foreach ($history as $prompt): ?>
                                <option value="<?php echo (int) $prompt['version']; ?>" <?php echo $compareVersion === (int) $prompt['version'] ? 'selected' : ''; ?>>
                                    Version <?php echo (int) $prompt['version']; ?> (<?php echo htmlspecialchars((string) $prompt['status']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="grid-column:1 / -1;">
                        <button type="submit" class="btn-premium-secondary">Compare Versions</button>
                    </div>
                </form>
                <?php foreach ($history as $prompt): ?>
                    <div style="padding:1rem;border:1px solid var(--border-color);border-radius:12px;margin-bottom:.75rem;">
                        <div style="display:flex;justify-content:space-between;gap:1rem;align-items:center;">
                            <div style="font-weight:700;">Version <?php echo (int) $prompt['version']; ?></div>
                            <span style="display:inline-block;padding:4px 8px;border-radius:999px;background:#f8fafc;border:1px solid #e2e8f0;font-size:12px;"><?php echo htmlspecialchars((string) $prompt['status']); ?></span>
                        </div>
                        <div style="margin-top:.5rem;color:#475569;font-size:.92rem;"><?php echo nl2br(htmlspecialchars((string) $prompt['system_prompt_text'])); ?></div>
                        <details style="margin-top:.5rem;">
                            <summary style="cursor:pointer;font-weight:600;">Instructions and contract</summary>
                            <pre style="white-space:pre-wrap;background:#111827;color:#e5e7eb;padding:12px;border-radius:8px;overflow:auto;"><?php echo htmlspecialchars((string) $prompt['instruction_text'] . "\n\n" . json_encode($prompt['output_contract_json'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre>
                        </details>
                        <?php if ((string) $prompt['status'] !== 'active'): ?>
                            <form method="POST" style="margin-top:.75rem;">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                <input type="hidden" name="action" value="activate_prompt">
                                <input type="hidden" name="surface" value="<?php echo htmlspecialchars((string) $prompt['surface']); ?>">
                                <input type="hidden" name="prompt_key" value="<?php echo htmlspecialchars((string) $prompt['prompt_key']); ?>">
                                <input type="hidden" name="version" value="<?php echo (int) $prompt['version']; ?>">
                                <?php if ($isDefaultWorkspacePromptControl): ?>
                                    <input type="text" name="default_workspace_reason" required value="Activate Platform Ops prompt override" style="width:100%;padding:.55rem;border:1px solid var(--border-color);border-radius:8px;margin-bottom:.5rem;">
                                <?php endif; ?>
                                <button type="submit" class="btn-premium-secondary">Activate / Roll Back to This Version</button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="content-card">
                <h2 style="margin-top:0;">Recent Prompt Changes</h2>
                <?php foreach ($recentChanges as $change): ?>
                    <div style="padding:.85rem 0;border-bottom:1px solid #e2e8f0;">
                        <div style="font-weight:700;"><?php echo htmlspecialchars($change['surface'] . ' / ' . $change['prompt_key']); ?></div>
                        <div style="color:#64748b;font-size:.9rem;">Version <?php echo (int) $change['version']; ?> | <?php echo htmlspecialchars($change['status']); ?> | <?php echo htmlspecialchars($change['created_at']); ?></div>
                    </div>
                <?php endforeach; ?>
                <div style="margin-top:1rem;">
                    <a href="ai_automation_diagnostics.php" class="btn-premium-secondary">Open Diagnostics</a>
                </div>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-top:1rem;align-items:start;">
            <div class="content-card">
                <h2 style="margin-top:0;">Prompt Dry Run</h2>
                <form method="POST" style="display:grid;gap:.75rem;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="action" value="dry_run_prompt">
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;">
                        <div><label style="display:block;margin-bottom:.35rem;">Surface</label><input type="text" name="surface" value="<?php echo htmlspecialchars($selectedSurface); ?>" required style="width:100%;padding:.65rem;border:1px solid var(--border-color);border-radius:8px;"></div>
                        <div><label style="display:block;margin-bottom:.35rem;">Prompt key</label><input type="text" name="prompt_key" value="<?php echo htmlspecialchars($selectedPromptKey); ?>" required style="width:100%;padding:.65rem;border:1px solid var(--border-color);border-radius:8px;"></div>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:.75rem;">
                        <div><label style="display:block;margin-bottom:.35rem;">Version</label><input type="number" min="0" name="version" value="<?php echo (int) $selectedVersion; ?>" style="width:100%;padding:.65rem;border:1px solid var(--border-color);border-radius:8px;"></div>
                        <div><label style="display:block;margin-bottom:.35rem;">Max blocks</label><input type="number" min="1" name="max_blocks" value="12" style="width:100%;padding:.65rem;border:1px solid var(--border-color);border-radius:8px;"></div>
                        <div><label style="display:block;margin-bottom:.35rem;">Max chars</label><input type="number" min="500" name="max_chars" value="12000" style="width:100%;padding:.65rem;border:1px solid var(--border-color);border-radius:8px;"></div>
                    </div>
                    <div>
                        <label style="display:block;margin-bottom:.35rem;">Sample input JSON</label>
                        <textarea name="sample_input_json" rows="12" style="width:100%;padding:.65rem;border:1px solid var(--border-color);border-radius:8px;"><?php
                            echo htmlspecialchars(json_encode([
                                'user_id' => (int) ($user['id'] ?? 0),
                                'question' => 'What is the safest next step?',
                                'current_page' => 'dashboard',
                                'thread_summary' => ['summary' => 'Customer asked for pricing details.'],
                                'latest_inbound_message' => 'Please resend the quote with the latest terms.',
                                'contact' => ['first_name' => 'Amina', 'email' => 'amina@example.com'],
                                'deal' => ['title' => 'Enterprise renewal', 'stage' => 'proposal'],
                                'invoice' => ['invoice_number' => 'Q-123'],
                            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                        ?></textarea>
                    </div>
                    <button type="submit" class="btn-premium-primary">Run Dry Preview</button>
                </form>
                <?php if ($dryRunResult): ?>
                    <div style="margin-top:1rem;padding:1rem;border:1px solid var(--border-color);border-radius:12px;background:#f8fafc;">
                        <div style="font-weight:700;">Rendered prompt: <?php echo htmlspecialchars($dryRunResult['surface'] . ' / ' . $dryRunResult['prompt_key']); ?> v<?php echo (int) $dryRunResult['prompt_version']; ?></div>
                        <div style="display:flex;gap:.75rem;flex-wrap:wrap;color:#64748b;font-size:.9rem;margin-top:.5rem;">
                            <span>Blocks <?php echo (int) ($dryRunResult['block_count'] ?? 0); ?></span>
                            <span>Quality <?php echo number_format((float) (($dryRunResult['context_bundle_quality']['context_quality_score'] ?? 0)), 2); ?></span>
                            <span>Warnings <?php echo count((array) ($dryRunResult['retrieval_warnings'] ?? [])); ?></span>
                        </div>
                        <details style="margin-top:.75rem;" open>
                            <summary style="cursor:pointer;font-weight:600;">Rendered prompt</summary>
                            <pre style="white-space:pre-wrap;background:#111827;color:#e5e7eb;padding:12px;border-radius:8px;overflow:auto;"><?php echo htmlspecialchars((string) ($dryRunResult['rendered_prompt'] ?? '')); ?></pre>
                        </details>
                        <details style="margin-top:.75rem;">
                            <summary style="cursor:pointer;font-weight:600;">Context bundle summary</summary>
                            <pre style="white-space:pre-wrap;background:#111827;color:#e5e7eb;padding:12px;border-radius:8px;overflow:auto;"><?php echo htmlspecialchars(json_encode($dryRunResult['context_bundle_summary'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre>
                        </details>
                        <details style="margin-top:.75rem;">
                            <summary style="cursor:pointer;font-weight:600;">Output contract</summary>
                            <pre style="white-space:pre-wrap;background:#111827;color:#e5e7eb;padding:12px;border-radius:8px;overflow:auto;"><?php echo htmlspecialchars(json_encode($dryRunResult['output_contract'] ?? null, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></pre>
                        </details>
                    </div>
                <?php endif; ?>
            </div>

            <div class="content-card">
                <h2 style="margin-top:0;">Version Diff</h2>
                <?php if (!$selectedPrompt || !$comparePrompt): ?>
                    <p style="color:#64748b;">Choose two versions above to compare their system prompt and instruction text side by side.</p>
                <?php else: ?>
                    <?php
                    $regressionRisk = (string) ($comparison['regression_risk'] ?? 'low');
                    $riskColor = match ($regressionRisk) {
                        'high' => '#b91c1c',
                        'medium' => '#b45309',
                        default => '#0f766e',
                    };
                    ?>
                    <div style="display:flex;gap:1rem;flex-wrap:wrap;color:#64748b;font-size:.9rem;margin-bottom:.75rem;">
                        <span>Primary v<?php echo (int) ($selectedPrompt['version'] ?? 0); ?></span>
                        <span>Compare v<?php echo (int) ($comparePrompt['version'] ?? 0); ?></span>
                        <span style="display:inline-block;padding:4px 8px;border-radius:999px;background:<?php echo $riskColor; ?>15;color:<?php echo $riskColor; ?>;font-size:12px;font-weight:700;">Regression risk <?php echo htmlspecialchars($regressionRisk); ?></span>
                    </div>
                    <?php if ($comparison): ?>
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.75rem;margin-bottom:1rem;">
                            <div style="padding:.85rem;border:1px solid var(--border-color);border-radius:10px;background:#fff;">
                                <div style="font-size:12px;color:#64748b;text-transform:uppercase;">Quality delta</div>
                                <div style="font-size:22px;font-weight:700;margin-top:.35rem;"><?php echo number_format((float) ($comparison['deltas']['quality_delta'] ?? 0), 2); ?></div>
                            </div>
                            <div style="padding:.85rem;border:1px solid var(--border-color);border-radius:10px;background:#fff;">
                                <div style="font-size:12px;color:#64748b;text-transform:uppercase;">Blocked delta</div>
                                <div style="font-size:22px;font-weight:700;margin-top:.35rem;"><?php echo number_format(((float) ($comparison['deltas']['blocked_rate_delta'] ?? 0)) * 100, 1); ?>%</div>
                            </div>
                            <div style="padding:.85rem;border:1px solid var(--border-color);border-radius:10px;background:#fff;">
                                <div style="font-size:12px;color:#64748b;text-transform:uppercase;">Accepted delta</div>
                                <div style="font-size:22px;font-weight:700;margin-top:.35rem;"><?php echo number_format(((float) ($comparison['deltas']['accepted_rate_delta'] ?? 0)) * 100, 1); ?>%</div>
                            </div>
                            <div style="padding:.85rem;border:1px solid var(--border-color);border-radius:10px;background:#fff;">
                                <div style="font-size:12px;color:#64748b;text-transform:uppercase;">Rejected delta</div>
                                <div style="font-size:22px;font-weight:700;margin-top:.35rem;"><?php echo number_format(((float) ($comparison['deltas']['rejected_rate_delta'] ?? 0)) * 100, 1); ?>%</div>
                            </div>
                        </div>
                        <div style="margin-bottom:1rem;padding:.9rem;border:1px solid var(--border-color);border-radius:10px;background:#f8fafc;">
                            <div style="font-weight:700;color:#0f172a;margin-bottom:.4rem;">Version metrics</div>
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.75rem;color:#475569;font-size:.9rem;">
                                <div>
                                    <strong>Primary v<?php echo (int) ($comparison['primary_version'] ?? 0); ?></strong><br>
                                    Runs <?php echo (int) ($comparison['primary']['recent_run_count'] ?? 0); ?><br>
                                    Outcomes <?php echo (int) ($comparison['primary']['outcome_sample_size'] ?? 0); ?><br>
                                    Quality <?php echo number_format((float) ($comparison['primary']['recent_quality_score'] ?? 0), 2); ?><br>
                                    Accepted <?php echo number_format(((float) ($comparison['primary']['accepted_rate'] ?? 0)) * 100, 1); ?>%<br>
                                    Rejected <?php echo number_format(((float) ($comparison['primary']['rejected_rate'] ?? 0)) * 100, 1); ?>%
                                </div>
                                <div>
                                    <strong>Compare v<?php echo (int) ($comparison['compare_version'] ?? 0); ?></strong><br>
                                    Runs <?php echo (int) ($comparison['compare']['recent_run_count'] ?? 0); ?><br>
                                    Outcomes <?php echo (int) ($comparison['compare']['outcome_sample_size'] ?? 0); ?><br>
                                    Quality <?php echo number_format((float) ($comparison['compare']['recent_quality_score'] ?? 0), 2); ?><br>
                                    Accepted <?php echo number_format(((float) ($comparison['compare']['accepted_rate'] ?? 0)) * 100, 1); ?>%<br>
                                    Rejected <?php echo number_format(((float) ($comparison['compare']['rejected_rate'] ?? 0)) * 100, 1); ?>%
                                </div>
                            </div>
                            <?php if (!empty($comparison['regression_signals'])): ?>
                                <div style="margin-top:.75rem;color:#92400e;font-size:.9rem;">
                                    Signals: <?php echo htmlspecialchars(implode(', ', (array) $comparison['regression_signals'])); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <div style="max-height:620px;overflow:auto;border:1px solid var(--border-color);border-radius:12px;">
                        <table style="width:100%;border-collapse:collapse;font-size:.9rem;">
                            <thead style="position:sticky;top:0;background:#e2e8f0;">
                                <tr>
                                    <th style="text-align:left;padding:.6rem;border-bottom:1px solid var(--border-color);width:50%;">Primary</th>
                                    <th style="text-align:left;padding:.6rem;border-bottom:1px solid var(--border-color);width:50%;">Compare</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($diffRows as $row): ?>
                                    <tr style="background:<?php echo $row['same'] ? '#ffffff' : '#fef3c7'; ?>;">
                                        <td style="vertical-align:top;padding:.5rem;border-bottom:1px solid #e2e8f0;white-space:pre-wrap;"><?php echo htmlspecialchars($row['left']); ?></td>
                                        <td style="vertical-align:top;padding:.5rem;border-bottom:1px solid #e2e8f0;white-space:pre-wrap;"><?php echo htmlspecialchars($row['right']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
