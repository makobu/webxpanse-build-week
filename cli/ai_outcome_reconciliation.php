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

use CRM\Database;
use CRM\Services\AIDecisionOutcomeService;
use CRM\Services\AIOutcomeClassifier;
use CRM\Services\AutomationJobHealthService;

Database::init(require __DIR__ . '/../config/database.php');

$jobHealth = new AutomationJobHealthService();
$jobHealth->markStarted('ai_outcome_reconciliation');
$startedAt = microtime(true);

$classifier = new AIOutcomeClassifier();
$outcomes = new AIDecisionOutcomeService();

try {
    echo "Reconciling AI outcomes...\n";

    $assistantRuns = Database::query(
        "SELECT r.*
         FROM email_assistant_runs r
         LEFT JOIN ai_decision_outcomes o ON o.assistant_run_id = r.id
         WHERE o.id IS NULL
           AND r.created_at <= ?
           AND r.intent IN ('draft_customer_reply', 'send_customer_reply')
         ORDER BY r.created_at ASC",
        [date('Y-m-d H:i:s', strtotime('-7 days'))]
    );
    $assistantCount = 0;
    foreach ($assistantRuns as $run) {
        $result = json_decode((string) ($run['result_json'] ?? '{}'), true) ?: [];
        $label = !empty($result['draft']) ? 'ignored' : 'failed';
        $outcomes->recordAssistantOutcome($run, [
            'outcome_label' => $label,
            'outcome_score' => $classifier->scoreOutcomeLabel($label),
            'metadata' => ['reason' => 'reconciliation_window_elapsed'],
            'measured_at' => date('Y-m-d H:i:s'),
        ]);
        $assistantCount++;
    }

    $guidanceRuns = Database::query(
        "SELECT g.*
         FROM ai_guidance_runs g
         LEFT JOIN ai_decision_outcomes o ON o.guidance_run_id = g.id
         WHERE o.id IS NULL
           AND g.created_at <= ?
         ORDER BY g.created_at ASC",
        [date('Y-m-d H:i:s', strtotime('-14 days'))]
    );
    $guidanceCount = 0;
    foreach ($guidanceRuns as $run) {
        $task = null;
        if ((string) ($run['surface'] ?? '') === 'coach' && Database::queryOne("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tasks'")) {
            $task = Database::queryOne(
                "SELECT * FROM tasks WHERE JSON_EXTRACT(metadata_json, '$.guidance_run_id') = ? ORDER BY id DESC LIMIT 1",
                [(int) $run['id']]
            );
        }
        $classification = $classifier->classifyCoachOutcome([
            'task_completed' => $task && (string) ($task['status'] ?? '') === 'completed',
            'task_cancelled' => $task && (string) ($task['status'] ?? '') === 'cancelled',
            'task_id' => (int) ($task['id'] ?? 0),
        ]);
        $outcomes->recordGuidanceOutcome($run, $classification + ['measured_at' => date('Y-m-d H:i:s')]);
        $guidanceCount++;
    }

    $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
    $jobHealth->markSuccess(
        'ai_outcome_reconciliation',
        "Recorded {$assistantCount} assistant outcomes and {$guidanceCount} guidance outcomes.",
        $durationMs,
        [
            'assistant_count' => $assistantCount,
            'guidance_count' => $guidanceCount,
        ]
    );

    echo "Recorded {$assistantCount} assistant outcomes and {$guidanceCount} guidance outcomes.\n";
} catch (\Throwable $e) {
    $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
    $jobHealth->markFailure('ai_outcome_reconciliation', $e->getMessage(), $durationMs);
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
