<?php
require_once __DIR__ . '/../vendor/autoload.php';
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) continue;
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if (($value !== '') && ((substr($value, 0, 1) === '"' && substr($value, -1) === '"') || (substr($value, 0, 1) === "'" && substr($value, -1) === "'"))) {
            $value = substr($value, 1, -1);
        }
        $_ENV[$key] = $value;
    }
}
require_once __DIR__ . '/../config/constants.php';
CRM\Database::init(require __DIR__ . '/../config/database.php');
$userId = 1;
$prefs = new CRM\Modules\UserPreferences();
$svc = new CRM\Modules\AITaskAutomationService();
$coach = new CRM\Modules\AICoach();
$control = (new CRM\Services\AIRuntimeControlService())->getEffectiveControl('task_automation');
$rows = CRM\Database::query("SELECT preference_key, preference_value, updated_at FROM user_preferences WHERE user_id = ? ORDER BY preference_key", [$userId]);
echo "PREFS\n";
print_r($rows);
echo "STATE\n";
print_r([
  'coach_enabled' => $prefs->isAICoachEnabled($userId),
  'mode' => $prefs->getEffectiveAIGuidanceMode($userId),
  'should_auto_seed' => $svc->shouldAutoSeedToday($userId),
  'task_control' => $control,
]);
echo "RECS\n";
$recs = $coach->generateRecommendations($userId, $prefs->getEffectiveAIGuidanceMode($userId));
print_r([
  'priorities' => count((array)($recs['priorities'] ?? [])),
  'quick_wins' => count((array)($recs['quick_wins'] ?? [])),
  'foundation_gaps' => count((array)($recs['foundation_gaps'] ?? [])),
  'suppressed' => count((array)($recs['suppressed_recommendations'] ?? [])),
  'diagnostics' => $recs['diagnostics'] ?? [],
]);
echo "AUTO\n";
$result = $svc->autoSeedDailyTasks($userId);
print_r($result);
echo "TASKS\n";
print_r(CRM\Database::query("SELECT id, title, status, created_at FROM tasks WHERE created_by = ? OR assigned_to = ? ORDER BY id DESC LIMIT 10", [$userId, $userId]));
?>
