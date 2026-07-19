<?php
/**
 * Dashboard tasks API.
 */

require_once __DIR__ . '/../vendor/autoload.php';

$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        if (strpos($line, '=') === false) {
            continue;
        }
        list($key, $value) = explode('=', $line, 2);
        $_ENV[trim($key)] = trim($value);
    }
}

require_once __DIR__ . '/../config/constants.php';

use CRM\Auth;
use CRM\Authorization;
use CRM\Database;
use CRM\Session;
use CRM\Modules\Tasks;

Database::init(require __DIR__ . '/../config/database.php');
Session::start();

header('Content-Type: application/json');
$perfEnabled = isset($_GET['perf_debug']) || (
    (($_ENV['APP_ENV'] ?? 'production') === 'development')
    || filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOL)
    || filter_var($_ENV['DEBUG'] ?? false, FILTER_VALIDATE_BOOL)
);
$perfStartedAt = microtime(true);
$perfMarks = [];
$markPerf = static function (string $label) use (&$perfMarks, $perfEnabled): void {
    if ($perfEnabled) {
        $perfMarks[] = [$label, microtime(true)];
    }
};
$flushPerf = static function () use (&$perfMarks, $perfEnabled, $perfStartedAt): void {
    if (!$perfEnabled || headers_sent()) {
        return;
    }
    $parts = [];
    $previous = $perfStartedAt;
    foreach ($perfMarks as [$label, $markedAt]) {
        $parts[] = 'dashboard_tasks_' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $label)
            . ';dur=' . number_format(max(0, ($markedAt - $previous) * 1000), 1, '.', '');
        $previous = $markedAt;
    }
    $parts[] = 'dashboard_tasks_total;dur=' . number_format(max(0, (microtime(true) - $perfStartedAt) * 1000), 1, '.', '');
    header('Server-Timing: ' . implode(', ', $parts));
};

if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$user = Auth::user();
if (!Authorization::can('tasks.read', $user)) {
    http_response_code(403);
    echo json_encode([
        'error' => 'Forbidden',
        'html' => renderDashboardTasksUnavailable(),
    ]);
    exit;
}

$userId = (int) ($user['id'] ?? 0);
Session::closeWrite();
$tasksModule = new Tasks();
$recentTasks = $tasksModule->getUserTasks($userId, ['status' => 'pending'], 5);
$markPerf('recent');
$aiStarterTasks = $tasksModule->getAiStarterTasks($userId, 5);
$markPerf('starter');
$flushPerf();

echo json_encode([
    'html' => renderDashboardTasksPanel($aiStarterTasks, $recentTasks),
    'counts' => [
        'starter' => count($aiStarterTasks),
        'recent' => count($recentTasks),
    ],
]);

function renderDashboardTasksUnavailable(): string
{
    return '<div class="empty-state"><div class="empty-state-icon"><i class="fas fa-lock"></i></div><p>Task access is not available for this role.</p></div>';
}

function renderDashboardTasksPanel(array $aiStarterTasks, array $recentTasks): string
{
    ob_start();
    ?>
    <?php if (!empty($aiStarterTasks)): ?>
        <div style="margin-bottom: 1rem; padding: 0.9rem 1rem; border: 1px solid #bfdbfe; border-radius: 14px; background: linear-gradient(135deg, #eff6ff, #f8fbff);">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:0.75rem;flex-wrap:wrap;margin-bottom:0.6rem;">
                <div>
                    <div style="font-size:0.78rem;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:#1d4ed8;">Starter Tasks</div>
                    <div style="font-size:0.9rem;color:#334155;">Clarity seeded these launch tasks so they stay visible in your task queue.</div>
                </div>
                <a href="tasks.php" style="font-size:0.85rem;font-weight:600;color:#2563eb;text-decoration:none;">Open task list &rarr;</a>
            </div>
            <div style="display:grid;gap:0.55rem;">
                <?php foreach ($aiStarterTasks as $starterTask): ?>
                    <a href="task_view.php?id=<?php echo (int) $starterTask['id']; ?>" style="display:flex;justify-content:space-between;align-items:center;gap:0.75rem;padding:0.75rem 0.85rem;border-radius:12px;background:#ffffff;border:1px solid #dbeafe;color:#0f172a;text-decoration:none;">
                        <div>
                            <div style="font-weight:600;"><?php echo htmlspecialchars((string) $starterTask['title']); ?></div>
                            <div style="font-size:0.82rem;color:#64748b;">
                                <?php if (!empty($starterTask['due_date'])): ?>
                                    Due <?php echo htmlspecialchars(date('M d, Y', strtotime((string) $starterTask['due_date']))); ?>
                                <?php else: ?>
                                    Ready to start
                                <?php endif; ?>
                            </div>
                        </div>
                        <span class="badge badge-priority-medium">AI Starter</span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
    <?php if (empty($recentTasks)): ?>
        <div class="empty-state">
            <div class="empty-state-icon"><i class="fas fa-check-circle"></i></div>
            <p>No pending tasks.</p>
        </div>
    <?php else: ?>
        <div>
            <?php foreach ($recentTasks as $task): ?>
                <?php
                $isOverdue = !empty($task['due_date']) && strtotime((string) $task['due_date']) < time();
                $priorityBadges = [
                    'urgent' => 'badge-priority-urgent',
                    'high' => 'badge-priority-high',
                    'medium' => 'badge-priority-medium',
                    'low' => 'badge-priority-low',
                ];
                $badgeClass = $priorityBadges[(string) ($task['priority'] ?? '')] ?? 'badge-priority-low';
                ?>
                <div class="list-item" style="<?php echo $isOverdue ? 'background: #fee2e2; border-left: 3px solid #dc2626;' : ''; ?>">
                    <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 0.5rem;">
                        <div style="font-weight: 600; color: #0f172a; flex: 1;">
                            <a href="task_view.php?id=<?php echo (int) $task['id']; ?>" style="color: #0f172a; text-decoration: none;">
                                <?php echo htmlspecialchars((string) $task['title']); ?>
                            </a>
                        </div>
                        <span class="badge <?php echo $badgeClass; ?>">
                            <?php echo htmlspecialchars((string) $task['priority']); ?>
                        </span>
                    </div>
                    <div style="color: #64748b; font-size: 0.875rem;">
                        <?php if (!empty($task['due_date'])): ?>
                            <span style="<?php echo $isOverdue ? 'color: #dc2626; font-weight: 600;' : ''; ?>">
                                Due: <?php echo htmlspecialchars(date('M d, Y', strtotime((string) $task['due_date']))); ?>
                            </span>
                        <?php endif; ?>
                        <?php if (!empty($task['contact_id'])): ?>
                            <span> &bull; </span>
                            <a href="contact_view.php?id=<?php echo (int) $task['contact_id']; ?>" style="color: #667eea; text-decoration: none;">
                                <?php echo htmlspecialchars(trim(((string) ($task['contact_first_name'] ?? '')) . ' ' . ((string) ($task['contact_last_name'] ?? '')))); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php

    return trim((string) ob_get_clean());
}
