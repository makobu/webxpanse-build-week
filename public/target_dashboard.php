<?php
/**
 * Targets Dashboard Page
 */

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
use CRM\Modules\Targets;
use CRM\Session;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

$basePath = getBasePath();
if (!Auth::check()) {
    header('Location: ' . $basePath . '/login.php');
    exit;
}

$targetsModule = new Targets();
$userId = (int) ((Auth::user()['id'] ?? 0));
$allTargets = $targetsModule->getAll(['user_id' => $userId], 250, 0);
$activeTargets = array_values(array_filter($allTargets, static fn(array $target): bool => (string) ($target['status'] ?? 'active') === 'active'));
$atRiskTargets = array_values(array_filter($allTargets, static function (array $target): bool {
    $band = (string) ($target['status_band'] ?? $target['status_category'] ?? 'on_track');
    return in_array($band, ['at_risk', 'behind', 'blocked', 'missed'], true);
}));

$scopeSummary = [
    'personal' => ['count' => 0, 'value' => 0.0, 'current' => 0.0],
    'team' => ['count' => 0, 'value' => 0.0, 'current' => 0.0],
    'company' => ['count' => 0, 'value' => 0.0, 'current' => 0.0],
];
$forecastScores = [];
$riskCounts = ['on_track' => 0, 'at_risk' => 0, 'behind' => 0, 'blocked' => 0, 'completed' => 0, 'missed' => 0];

foreach ($allTargets as $target) {
    $scope = (string) ($target['scope'] ?? 'personal');
    $band = (string) ($target['status_band'] ?? $target['status_category'] ?? 'on_track');
    if (!isset($scopeSummary[$scope])) {
        $scopeSummary[$scope] = ['count' => 0, 'value' => 0.0, 'current' => 0.0];
    }
    $scopeSummary[$scope]['count']++;
    $scopeSummary[$scope]['value'] += (float) ($target['target_value'] ?? 0);
    $scopeSummary[$scope]['current'] += (float) ($target['current_value'] ?? 0);
    $forecastScores[] = (float) ($target['forecast_score'] ?? 0);
    if (isset($riskCounts[$band])) {
        $riskCounts[$band]++;
    }
}

$completionConfidence = $forecastScores === [] ? 0.0 : array_sum($forecastScores) / count($forecastScores);

function dashboardProgress(array $summary): float
{
    return ((float) ($summary['value'] ?? 0)) > 0 ? min(100, max(0, (((float) ($summary['current'] ?? 0)) / (float) $summary['value']) * 100)) : 0.0;
}

function dashboardBandColor(string $band): string
{
    return match ($band) {
        'completed', 'on_track' => '#10b981',
        'at_risk' => '#f59e0b',
        'blocked' => '#8b5cf6',
        'behind', 'missed' => '#ef4444',
        default => '#64748b',
    };
}

$pageTitle = 'Targets Dashboard - ' . brandProductName();
ob_start();
?>

<link rel="stylesheet" href="<?php echo htmlspecialchars($basePath); ?>/assets/css/premium-pages.css">

<div class="page-premium">
    <div class="container">
        <div class="page-header">
            <div>
                <h1>Targets Dashboard</h1>
                <p>Monitor personal, team, and company target health from one view.</p>
            </div>
            <div class="page-header-actions">
                <a href="<?php echo htmlspecialchars($basePath); ?>/target_create.php" class="btn-premium-primary">
                    <i class="fas fa-plus"></i>
                    New Target
                </a>
                <a href="<?php echo htmlspecialchars($basePath); ?>/targets.php" class="btn-premium-secondary">
                    <i class="fas fa-list"></i>
                    View All
                </a>
            </div>
        </div>

        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Active Targets</div>
                <div class="stat-value"><?php echo count($activeTargets); ?></div>
                <div style="font-size:.85rem;color:#64748b;margin-top:6px;">Currently in progress</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">At Risk</div>
                <div class="stat-value" style="color:#f59e0b;"><?php echo count($atRiskTargets); ?></div>
                <div style="font-size:.85rem;color:#64748b;margin-top:6px;">Require intervention</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Completion Confidence</div>
                <div class="stat-value" style="color:#2563eb;"><?php echo number_format($completionConfidence * 100, 0); ?>%</div>
                <div style="font-size:.85rem;color:#64748b;margin-top:6px;">Average forecast score</div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Completed</div>
                <div class="stat-value" style="color:#10b981;"><?php echo (int) ($riskCounts['completed'] ?? 0); ?></div>
                <div style="font-size:.85rem;color:#64748b;margin-top:6px;">Targets achieved</div>
            </div>
        </div>

        <div style="display:grid;grid-template-columns:1.25fr .95fr;gap:20px;align-items:start;">
            <section style="background:white;padding:24px;border:1px solid var(--border-color);border-radius:16px;">
                <h2 style="margin:0 0 18px 0;color:#0f172a;">Scope Rollups</h2>
                <div style="display:grid;gap:16px;">
                    <?php foreach ($scopeSummary as $scope => $summary): ?>
                        <?php $progress = dashboardProgress($summary); ?>
                        <div style="border:1px solid var(--border-color);border-radius:14px;padding:16px;background:#f8fafc;">
                            <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:8px;">
                                <div>
                                    <div style="font-weight:700;color:#0f172a;"><?php echo htmlspecialchars(ucfirst($scope)); ?></div>
                                    <div style="font-size:.85rem;color:#64748b;"><?php echo (int) ($summary['count'] ?? 0); ?> targets</div>
                                </div>
                                <strong style="color:#2563eb;"><?php echo number_format($progress, 1); ?>%</strong>
                            </div>
                            <div style="width:100%;height:12px;background:#e2e8f0;border-radius:999px;overflow:hidden;margin-bottom:10px;">
                                <div style="width:<?php echo min(100, $progress); ?>%;height:100%;background:#2563eb;"></div>
                            </div>
                            <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;color:#475569;font-size:.9rem;">
                                <span><?php echo number_format((float) ($summary['current'] ?? 0), 2); ?> current</span>
                                <span><?php echo number_format((float) ($summary['value'] ?? 0), 2); ?> target</span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>

            <section style="background:white;padding:24px;border:1px solid var(--border-color);border-radius:16px;">
                <h2 style="margin:0 0 18px 0;color:#0f172a;">Risk Distribution</h2>
                <div style="display:grid;gap:12px;">
                    <?php foreach (['on_track', 'at_risk', 'behind', 'blocked', 'completed', 'missed'] as $band): ?>
                        <div style="display:flex;justify-content:space-between;align-items:center;padding:14px 16px;border:1px solid var(--border-color);border-radius:12px;">
                            <span style="display:inline-flex;align-items:center;gap:8px;color:#0f172a;">
                                <span style="width:10px;height:10px;border-radius:50%;display:inline-block;background:<?php echo htmlspecialchars(dashboardBandColor($band)); ?>;"></span>
                                <?php echo htmlspecialchars(str_replace('_', ' ', ucfirst($band))); ?>
                            </span>
                            <strong style="color:#0f172a;"><?php echo (int) ($riskCounts[$band] ?? 0); ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-top:20px;align-items:start;">
            <section style="background:white;padding:24px;border:1px solid var(--border-color);border-radius:16px;">
                <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:16px;">
                    <h2 style="margin:0;color:#0f172a;">Most At-Risk Targets</h2>
                    <a href="<?php echo htmlspecialchars($basePath); ?>/targets.php?status_band=at_risk" style="color:#2563eb;text-decoration:none;">View all</a>
                </div>
                <?php if ($atRiskTargets === []): ?>
                    <p style="margin:0;color:#64748b;">No targets are currently flagged as at risk.</p>
                <?php else: ?>
                    <div style="display:grid;gap:12px;">
                        <?php foreach (array_slice($atRiskTargets, 0, 6) as $target): ?>
                            <?php $band = (string) ($target['status_band'] ?? $target['status_category'] ?? 'at_risk'); ?>
                            <a href="<?php echo htmlspecialchars($basePath); ?>/target_view.php?id=<?php echo (int) $target['id']; ?>" style="text-decoration:none;color:inherit;border:1px solid var(--border-color);border-radius:12px;padding:14px 16px;display:block;">
                                <div style="display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:6px;">
                                    <strong style="color:#0f172a;"><?php echo htmlspecialchars((string) ($target['title'] ?? 'Untitled target')); ?></strong>
                                    <span class="badge" style="background:<?php echo htmlspecialchars(dashboardBandColor($band)); ?>;color:white;"><?php echo htmlspecialchars(str_replace('_', ' ', ucfirst($band))); ?></span>
                                </div>
                                <div style="font-size:.9rem;color:#64748b;"><?php echo htmlspecialchars((string) ($target['pace_summary'] ?? 'No pace summary available.')); ?></div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>

            <section style="background:white;padding:24px;border:1px solid var(--border-color);border-radius:16px;">
                <div style="display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:16px;">
                    <h2 style="margin:0;color:#0f172a;">Recommended Actions</h2>
                    <a href="<?php echo htmlspecialchars($basePath); ?>/targets.php" style="color:#2563eb;text-decoration:none;">Open targets</a>
                </div>
                <div style="display:grid;gap:12px;">
                    <?php
                    $recommended = [];
                    foreach ($activeTargets as $target) {
                        foreach ((array) ($target['next_best_actions'] ?? []) as $action) {
                            $recommended[] = [
                                'target_id' => (int) ($target['id'] ?? 0),
                                'title' => (string) ($target['title'] ?? 'Untitled target'),
                                'action' => (string) $action,
                            ];
                            if (count($recommended) >= 6) {
                                break 2;
                            }
                        }
                    }
                    ?>
                    <?php if ($recommended === []): ?>
                        <p style="margin:0;color:#64748b;">No recommended actions yet. Add auto-rollup or milestone-backed targets to unlock more guidance.</p>
                    <?php else: ?>
                        <?php foreach ($recommended as $item): ?>
                            <a href="<?php echo htmlspecialchars($basePath); ?>/target_view.php?id=<?php echo (int) $item['target_id']; ?>" style="display:block;text-decoration:none;color:inherit;border:1px solid var(--border-color);border-radius:12px;padding:14px 16px;">
                                <div style="font-size:.82rem;color:#64748b;margin-bottom:4px;"><?php echo htmlspecialchars($item['title']); ?></div>
                                <div style="color:#0f172a;font-weight:600;"><?php echo htmlspecialchars($item['action']); ?></div>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/../views/layouts/base.php';
?>
