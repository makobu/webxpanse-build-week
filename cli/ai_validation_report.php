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
use CRM\Modules\UserPreferences;
use CRM\Services\AIConfidenceCalibrationService;
use CRM\Services\AIAutomationDiagnosticsService;
use CRM\Services\AIOperatingContextService;
use CRM\Services\AIProviderProbeService;
use CRM\Services\AIPromptQualityService;

Database::init(require __DIR__ . '/../config/database.php');

$args = $argv ?? [];
$userId = 0;
$json = false;
$liveProbe = false;
foreach ($args as $arg) {
    if (str_starts_with((string) $arg, '--user-id=')) {
        $userId = (int) substr((string) $arg, 10);
    } elseif ($arg === '--json') {
        $json = true;
    } elseif ($arg === '--live-probe') {
        $liveProbe = true;
    }
}

if ($userId <= 0) {
    $userId = (int) (Database::queryOne(
        "SELECT id FROM users ORDER BY CASE WHEN role = 'admin' THEN 0 ELSE 1 END, id ASC LIMIT 1"
    )['id'] ?? 0);
}

if ($userId <= 0) {
    fwrite(STDERR, "No user found for AI validation.\n");
    exit(1);
}

$preferences = new UserPreferences();
$calibration = new AIConfidenceCalibrationService();
$operatingContext = new AIOperatingContextService();
$promptQuality = new AIPromptQualityService();
$diagnostics = new AIAutomationDiagnosticsService();
$probeService = new AIProviderProbeService();

$context = $operatingContext->buildForSurface($userId, 'validation_report');
$summary = $calibration->getCalibrationSummary();
$promptSummary = $promptQuality->getActivePromptSummary();
$diagnosticSummary = $diagnostics->getSummary([
    'date_from' => date('Y-m-d', strtotime('-14 days')),
    'date_to' => date('Y-m-d'),
]);
$runtimeReadiness = $probeService->getReadiness();
$liveProbeResult = $liveProbe ? $probeService->probe(true) : $probeService->probe(false);

$minSampleSize = (int) ($preferences->getPreference(1, 'ai_calibration_min_sample_size') ?? 30);
$surfaceChecks = [];
foreach ((array) ($summary['summary'] ?? []) as $surface => $metricsByAction) {
    foreach ((array) $metricsByAction as $actionType => $metrics) {
        $sampleSize = (int) ($metrics['sample_size'] ?? 0);
        $precision = (float) ($metrics['precision_at_current_threshold'] ?? 0.0);
        $reversalRate = (float) ($metrics['reversal_rate'] ?? 0.0);
        $failureRate = (float) ($metrics['failure_rate'] ?? 0.0);

        $status = 'ok';
        $issues = [];
        if ($sampleSize < $minSampleSize) {
            $status = 'insufficient_data';
            $issues[] = 'sample_size_below_calibration_minimum';
        }
        if ($precision > 0 && $precision < 0.85) {
            $status = 'attention';
            $issues[] = 'precision_below_target';
        }
        if ($reversalRate > 0.05) {
            $status = 'attention';
            $issues[] = 'reversal_rate_above_guardrail';
        }
        if ($failureRate > 0.08) {
            $status = 'attention';
            $issues[] = 'failure_rate_above_guardrail';
        }

        $surfaceChecks[] = [
            'surface' => (string) $surface,
            'action_type' => (string) $actionType,
            'current_threshold' => (float) ($metrics['current_threshold'] ?? 0.0),
            'sample_size' => $sampleSize,
            'precision_at_current_threshold' => $precision,
            'acceptance_rate' => (float) ($metrics['acceptance_rate'] ?? 0.0),
            'reversal_rate' => $reversalRate,
            'failure_rate' => $failureRate,
            'status' => $status,
            'issues' => $issues,
        ];
    }
}

$report = [
    'user_id' => $userId,
    'generated_at' => date('c'),
    'configured_ai_settings' => (array) ($context['ai_settings'] ?? []),
    'qualification_state' => (array) ($context['qualification_state'] ?? []),
    'deal_automation_state' => (array) ($context['deal_automation_state'] ?? []),
    'target_state' => (array) ($context['target_state'] ?? []),
    'calibration_min_sample_size' => $minSampleSize,
    'runtime_readiness' => $runtimeReadiness,
    'provider_probe' => $liveProbeResult,
    'surface_checks' => $surfaceChecks,
    'prompt_summary' => array_map(static function (array $prompt): array {
        return [
            'surface' => (string) ($prompt['surface'] ?? ''),
            'prompt_key' => (string) ($prompt['prompt_key'] ?? ''),
            'version' => (int) ($prompt['version'] ?? 0),
            'recent_quality_score' => (float) ($prompt['recent_quality_score'] ?? 0.0),
            'accepted_rate' => (float) ($prompt['accepted_rate'] ?? 0.0),
            'blocked_rate' => (float) ($prompt['blocked_rate'] ?? 0.0),
            'fallback_rate' => (float) ($prompt['fallback_rate'] ?? 0.0),
            'recent_run_count' => (int) ($prompt['recent_run_count'] ?? 0),
        ];
    }, $promptSummary),
    'diagnostic_summary' => $diagnosticSummary,
];

if ($json) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

echo "AI Validation Report\n";
echo "User ID: {$report['user_id']}\n";
echo "Generated: {$report['generated_at']}\n\n";

echo "Configured AI Settings\n";
foreach ($report['configured_ai_settings'] as $key => $value) {
    if (is_scalar($value) || $value === null) {
        echo sprintf("- %s: %s\n", $key, var_export($value, true));
    }
}

echo "\nSurface Checks\n";
if ($surfaceChecks === []) {
    echo "- No AI decision outcomes available for calibration comparison.\n";
} else {
    foreach ($surfaceChecks as $check) {
        echo sprintf(
            "- %s / %s: threshold=%.2f sample=%d precision=%.2f acceptance=%.2f reversal=%.2f failure=%.2f status=%s%s\n",
            $check['surface'],
            $check['action_type'],
            $check['current_threshold'],
            $check['sample_size'],
            $check['precision_at_current_threshold'],
            $check['acceptance_rate'],
            $check['reversal_rate'],
            $check['failure_rate'],
            $check['status'],
            $check['issues'] ? ' [' . implode(', ', $check['issues']) . ']' : ''
        );
    }
}

echo "\nRuntime Readiness\n";
echo sprintf("- Core AI provider ready: %s\n", $report['runtime_readiness']['core_ai_provider_ready'] ? 'yes' : 'no');
echo sprintf("- Email assistant outbound ready: %s\n", $report['runtime_readiness']['email_assistant_outbound_ready'] ? 'yes' : 'no');
echo sprintf("- Email assistant inbound ready: %s\n", $report['runtime_readiness']['email_assistant_inbound_ready'] ? 'yes' : 'no');
echo sprintf("- Fallback-only mode: %s\n", $report['runtime_readiness']['fallback_only_mode'] ? 'yes' : 'no');
echo sprintf("- Message: %s\n", (string) $report['runtime_readiness']['message']);

echo "\nProvider Probe\n";
echo sprintf("- Live probe attempted: %s\n", $report['provider_probe']['live_probe_attempted'] ? 'yes' : 'no');
echo sprintf("- Success: %s\n", $report['provider_probe']['success'] ? 'yes' : 'no');
echo sprintf("- HTTP code: %s\n", (string) ($report['provider_probe']['http_code'] ?? 0));
echo sprintf("- Message: %s\n", (string) ($report['provider_probe']['message'] ?? ''));

echo "\nPrompt Quality\n";
if ($report['prompt_summary'] === []) {
    echo "- No active prompt metrics available.\n";
} else {
    foreach ($report['prompt_summary'] as $prompt) {
        echo sprintf(
            "- %s / %s v%d: quality=%.2f accepted=%.2f blocked=%.2f fallback=%.2f runs=%d\n",
            $prompt['surface'],
            $prompt['prompt_key'],
            $prompt['version'],
            $prompt['recent_quality_score'],
            $prompt['accepted_rate'],
            $prompt['blocked_rate'],
            $prompt['fallback_rate'],
            $prompt['recent_run_count']
        );
    }
}

echo "\nDiagnostics Summary\n";
foreach ((array) $diagnosticSummary as $key => $value) {
    if (is_scalar($value) || $value === null) {
        echo sprintf("- %s: %s\n", $key, var_export($value, true));
    }
}
