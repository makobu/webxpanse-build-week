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
use CRM\Services\AIAutomationIncidentService;
use CRM\Services\AutomationJobHealthService;
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

$service = new AIAutomationIncidentService();
$jobHealth = new AutomationJobHealthService();
$status = trim((string) ($_GET['status'] ?? ''));
$severity = trim((string) ($_GET['severity'] ?? ''));
$incidentKey = trim((string) ($_GET['incident_key'] ?? ''));
$incidents = $service->getIncidents([
    'status' => $status,
    'severity' => $severity,
    'incident_key' => $incidentKey,
    'limit' => 150,
]);
$rules = $service->getRules();
$activeCount = count(array_filter($incidents, static fn(array $incident): bool => ($incident['status'] ?? '') === 'active'));
$resolvedCount = count(array_filter($incidents, static fn(array $incident): bool => ($incident['status'] ?? '') === 'resolved'));
$incidentJob = $jobHealth->getJob('ai_incident_check');

$pageTitle = 'AI Incidents - ' . brandProductName();
ob_start();
?>
<div class="page-premium">
    <div class="container">
        <div class="page-header">
            <div>
                <h1>AI &amp; Automation Incidents</h1>
                <p>Detected AI and automation incidents routed through the existing alerting system.</p>
            </div>
            <div class="page-header-actions">
                <a href="ai_recovery_workbench.php" class="btn-premium-secondary">Recovery workbench</a>
                <a href="ai_automation_diagnostics.php" class="btn-premium-secondary">Open diagnostics</a>
                <a href="alerts.php" class="btn-premium-secondary">Open alerts</a>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:1rem;margin-bottom:1rem;">
            <div class="content-card"><div style="font-size:12px;color:#64748b;text-transform:uppercase;">Active incidents</div><div style="font-size:28px;font-weight:700;margin-top:.35rem;"><?php echo $activeCount; ?></div></div>
            <div class="content-card"><div style="font-size:12px;color:#64748b;text-transform:uppercase;">Resolved incidents</div><div style="font-size:28px;font-weight:700;margin-top:.35rem;"><?php echo $resolvedCount; ?></div></div>
            <div class="content-card"><div style="font-size:12px;color:#64748b;text-transform:uppercase;">Incident checks enabled</div><div style="font-size:20px;font-weight:700;margin-top:.35rem;"><?php echo !empty($rules['check_enabled']) ? 'Yes' : 'No'; ?></div></div>
            <div class="content-card"><div style="font-size:12px;color:#64748b;text-transform:uppercase;">Last incident check</div><div style="font-size:14px;font-weight:700;margin-top:.35rem;"><?php echo htmlspecialchars((string) ($incidentJob['last_run_at'] ?? 'Never')); ?></div></div>
        </div>

        <div class="content-card" style="margin-bottom:1rem;">
            <form method="GET" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.75rem;align-items:end;">
                <div>
                    <label style="display:block;margin-bottom:.35rem;font-weight:600;">Status</label>
                    <select name="status" style="width:100%;padding:.6rem;border:1px solid var(--border-color);border-radius:8px;">
                        <option value="" <?php echo $status === '' ? 'selected' : ''; ?>>All</option>
                        <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="resolved" <?php echo $status === 'resolved' ? 'selected' : ''; ?>>Resolved</option>
                        <option value="suppressed" <?php echo $status === 'suppressed' ? 'selected' : ''; ?>>Suppressed</option>
                    </select>
                </div>
                <div>
                    <label style="display:block;margin-bottom:.35rem;font-weight:600;">Severity</label>
                    <select name="severity" style="width:100%;padding:.6rem;border:1px solid var(--border-color);border-radius:8px;">
                        <option value="" <?php echo $severity === '' ? 'selected' : ''; ?>>All</option>
                        <option value="medium" <?php echo $severity === 'medium' ? 'selected' : ''; ?>>Medium</option>
                        <option value="high" <?php echo $severity === 'high' ? 'selected' : ''; ?>>High</option>
                        <option value="critical" <?php echo $severity === 'critical' ? 'selected' : ''; ?>>Critical</option>
                    </select>
                </div>
                <div>
                    <label style="display:block;margin-bottom:.35rem;font-weight:600;">Incident key</label>
                    <input type="text" name="incident_key" value="<?php echo htmlspecialchars($incidentKey); ?>" placeholder="assistant_blocked_spike" style="width:100%;padding:.6rem;border:1px solid var(--border-color);border-radius:8px;">
                </div>
                <div style="display:flex;gap:.5rem;">
                    <button type="submit" class="btn-premium-primary">Filter</button>
                    <a href="ai_incidents.php" class="btn-premium-secondary">Reset</a>
                </div>
            </form>
        </div>

        <div style="display:grid;grid-template-columns:minmax(0,1.1fr) minmax(320px,.9fr);gap:1rem;align-items:start;">
            <div class="content-card">
                <h2 style="margin-top:0;">Incidents</h2>
                <?php if ($incidents === []): ?>
                    <p style="color:#64748b;margin:0;">No incidents matched the current filters.</p>
                <?php else: ?>
                    <div style="display:grid;gap:.75rem;">
                        <?php foreach ($incidents as $incident): ?>
                            <?php $metadata = (array) ($incident['metadata'] ?? []); ?>
                            <div style="padding:1rem;border:1px solid var(--border-color);border-radius:12px;background:#fff;">
                                <div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
                                    <div>
                                        <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap;">
                                            <strong><?php echo htmlspecialchars((string) ($incident['incident_key'] ?? 'incident')); ?></strong>
                                            <span style="padding:4px 8px;border-radius:999px;background:#eff6ff;color:#1d4ed8;font-size:12px;font-weight:700;"><?php echo htmlspecialchars((string) ($incident['severity'] ?? 'medium')); ?></span>
                                            <span style="padding:4px 8px;border-radius:999px;background:#f8fafc;color:#334155;font-size:12px;font-weight:700;"><?php echo htmlspecialchars((string) ($incident['status'] ?? 'active')); ?></span>
                                        </div>
                                        <div style="margin-top:.45rem;color:#0f172a;font-weight:600;"><?php echo htmlspecialchars((string) ($metadata['metrics']['job_key'] ?? $metadata['metrics']['prompt_key'] ?? $metadata['metrics']['regression_risk'] ?? $incident['incident_key'] ?? '')); ?></div>
                                        <div style="margin-top:.35rem;color:#64748b;"><?php echo htmlspecialchars((string) ($metadata['metrics']['threshold'] ?? '')); ?><?php echo !empty($metadata['metrics']['threshold']) ? ' threshold' : ''; ?><?php if (!empty($metadata['reason_codes'])): ?> · <?php echo htmlspecialchars(implode(', ', (array) $metadata['reason_codes'])); ?><?php endif; ?></div>
                                        <div style="margin-top:.35rem;color:#94a3b8;font-size:.85rem;">First seen: <?php echo htmlspecialchars((string) ($incident['first_detected_at'] ?? '')); ?> | Last seen: <?php echo htmlspecialchars((string) ($incident['last_detected_at'] ?? '')); ?></div>
                                    </div>
                                    <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:flex-start;">
                                        <a href="<?php echo htmlspecialchars((string) ($metadata['diagnostics_url'] ?? 'ai_automation_diagnostics.php')); ?>" class="btn-premium-secondary">Diagnostics</a>
                                        <a href="alerts.php" class="btn-premium-secondary">Alerts</a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div class="content-card">
                <h2 style="margin-top:0;">Rule summary</h2>
                <div style="display:grid;gap:.75rem;">
                    <div><strong>Alerting enabled:</strong> <?php echo !empty($rules['enabled']) ? 'Yes' : 'No'; ?></div>
                    <div><strong>Check job enabled:</strong> <?php echo !empty($rules['check_enabled']) ? 'Yes' : 'No'; ?></div>
                    <div><strong>Cooldowns:</strong> medium <?php echo (int) (($rules['cooldowns']['medium'] ?? 0)); ?>m, high <?php echo (int) (($rules['cooldowns']['high'] ?? 0)); ?>m, critical <?php echo (int) (($rules['cooldowns']['critical'] ?? 0)); ?>m</div>
                </div>
                <div style="margin-top:1rem;">
                    <?php foreach ((array) ($rules['thresholds'] ?? []) as $key => $rule): ?>
                        <div style="padding:.75rem 0;border-top:1px solid #e2e8f0;">
                            <div style="font-weight:700;"><?php echo htmlspecialchars($key); ?></div>
                            <div style="color:#64748b;font-size:.9rem;">Severity: <?php echo htmlspecialchars((string) ($rule['severity'] ?? 'medium')); ?><?php if (isset($rule['threshold'])): ?> · Threshold: <?php echo htmlspecialchars((string) $rule['threshold']); ?><?php endif; ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../views/layouts/base.php';
