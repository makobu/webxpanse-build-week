<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Modules\AlertingSystem;
use CRM\Modules\UserPreferences;
use CRM\Services\AIAutomationDiagnosticsService;
use CRM\Services\AIAutomationIncidentService;
use CRM\Services\AIConfidenceCalibrationService;
use CRM\Services\AIPromptQualityService;
use CRM\Services\AIRuntimeControlService;
use CRM\Services\AutomationJobHealthService;
use CRM\Tests\DatabaseTestCase;

class AIAutomationIncidentServiceTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Database::execute(
            "INSERT INTO users (id, email, password_hash, role, created_at) VALUES (1, ?, ?, 'admin', NOW())",
            ['admin@example.com', password_hash('secret', PASSWORD_DEFAULT)]
        );

        $prefs = [
            'ai_incident_alerts_enabled' => '1',
            'ai_incident_check_enabled' => '1',
            'ai_incident_medium_cooldown_minutes' => '360',
            'ai_incident_high_cooldown_minutes' => '240',
            'ai_incident_critical_cooldown_minutes' => '120',
            'ai_autonomous_threshold_tuning_enabled' => '1',
        ];

        foreach ($prefs as $key => $value) {
            Database::execute(
                "INSERT INTO user_preferences (user_id, preference_key, preference_value) VALUES (1, ?, ?)",
                [$key, $value]
            );
        }
    }

    public function testBlockedAssistantSpikeCreatesAlertAndIncidentState(): void
    {
        $service = $this->buildService([
            'events' => array_map(
                static fn(int $i): array => [
                    'id' => 'assistant:' . $i,
                    'source' => 'assistant',
                    'decision' => 'blocked',
                    'reason_codes' => ['missing_context'],
                    'payload' => [],
                ],
                range(1, 12)
            ),
        ]);

        $result = $service->evaluateAndAlert();

        $this->assertCount(1, $result['alerts_created']);
        $this->assertSame('assistant_blocked_spike', $result['incidents'][0]['incident_key']);
        $state = Database::queryOne("SELECT * FROM ai_incident_state WHERE incident_key = 'assistant_blocked_spike'");
        $this->assertNotNull($state);
        $this->assertSame('active', $state['status']);
        $alert = Database::queryOne("SELECT * FROM alerts WHERE alert_type = 'ai_automation_incident' ORDER BY id DESC LIMIT 1");
        $this->assertSame('high', $alert['severity']);
    }

    public function testPromptRegressionCreatesIncident(): void
    {
        $service = $this->buildService([
            'prompt_summary' => [[
                'surface' => 'clarity_chat',
                'prompt_key' => 'clarity_question_answer',
                'version' => 3,
            ]],
            'prompt_comparisons' => [[
                'surface' => 'clarity_chat',
                'prompt_key' => 'clarity_question_answer',
                'primary_version' => 3,
                'compare_version' => 2,
                'regression_risk' => 'high',
                'regression_signals' => ['higher_blocked_rate'],
                'deltas' => ['quality_delta' => -0.18],
            ]],
        ]);

        $result = $service->evaluateAndAlert();

        $this->assertSame('prompt_regression', $result['incidents'][0]['incident_key']);
        $this->assertNotEmpty($result['alerts_created']);
    }

    public function testDedupeSuppressesDuplicateAlertWithinCooldownAndLaterResolves(): void
    {
        $service = $this->buildService([
            'events' => array_map(
                static fn(int $i): array => [
                    'id' => 'assistant:' . $i,
                    'source' => 'assistant',
                    'decision' => 'blocked',
                    'reason_codes' => ['missing_context'],
                    'payload' => [],
                ],
                range(1, 12)
            ),
        ]);

        $first = $service->evaluateAndAlert();
        $second = $service->evaluateAndAlert();

        $this->assertCount(1, $first['alerts_created']);
        $this->assertCount(0, $second['alerts_created']);

        $healthyService = $this->buildService();
        $healthyService->evaluateAndAlert();
        $healthyService->evaluateAndAlert();

        $state = Database::queryOne("SELECT * FROM ai_incident_state WHERE incident_key = 'assistant_blocked_spike' ORDER BY id DESC LIMIT 1");
        $this->assertSame('resolved', $state['status']);
    }

    public function testDisabledIncidentAlertingSuppressesAlertCreation(): void
    {
        (new UserPreferences())->setAIIncidentAlertsEnabled(1, false);

        $service = $this->buildService([
            'events' => array_map(
                static fn(int $i): array => [
                    'id' => 'assistant:' . $i,
                    'source' => 'assistant',
                    'decision' => 'blocked',
                    'reason_codes' => ['missing_context'],
                    'payload' => [],
                ],
                range(1, 12)
            ),
        ]);

        $result = $service->evaluateAndAlert();

        $this->assertSame([], $result['alerts_created']);
        $state = Database::queryOne("SELECT * FROM ai_incident_state WHERE incident_key = 'assistant_blocked_spike' ORDER BY id DESC LIMIT 1");
        $this->assertSame('suppressed', $state['status']);
    }

    private function buildService(array $overrides = []): AIAutomationIncidentService
    {
        $diagnostics = new class($overrides) extends AIAutomationDiagnosticsService {
            public function __construct(private array $overrides) {}
            public function getRecentEvents(array $filters = []): array { return $this->overrides['events'] ?? []; }
            public function getSummary(array $filters = []): array {
                return array_merge([
                    'blocked_ai_actions' => 0,
                    'approval_required' => 0,
                    'workflow_failures' => 0,
                    'workflow_retries_pending' => 0,
                    'degraded_capabilities' => 0,
                    'tasks_auto_completed' => 0,
                ], $this->overrides['summary'] ?? []);
            }
            public function getTopBlockers(array $filters = []): array { return $this->overrides['top_blockers'] ?? []; }
            public function getContextHealth(array $filters = []): array { return $this->overrides['context_health'] ?? ['ready' => 0, 'degraded' => 0, 'missing' => 0]; }
        };

        $promptQuality = new class($overrides) extends AIPromptQualityService {
            public function __construct(private array $overrides) {}
            public function getActivePromptSummary(): array { return $this->overrides['prompt_summary'] ?? []; }
            public function getPromptVersionComparison(string $surface, string $promptKey, int $primaryVersion, int $compareVersion, array $filters = []): array
            {
                foreach (($this->overrides['prompt_comparisons'] ?? []) as $comparison) {
                    if (($comparison['surface'] ?? '') === $surface && ($comparison['prompt_key'] ?? '') === $promptKey) {
                        return $comparison;
                    }
                }
                return ['surface' => $surface, 'prompt_key' => $promptKey, 'primary_version' => $primaryVersion, 'compare_version' => $compareVersion, 'regression_risk' => 'low', 'regression_signals' => [], 'deltas' => ['quality_delta' => 0.0]];
            }
        };

        $jobHealth = new class($overrides) extends AutomationJobHealthService {
            public function __construct(private array $overrides) {}
            public function getSummary(): array { return $this->overrides['job_health_summary'] ?? ['healthy' => 1, 'failed' => 0, 'stale' => 0, 'running' => 0, 'total' => 1]; }
            public function getJobs(): array { return $this->overrides['job_health'] ?? [['job_key' => 'ai_confidence_calibration', 'status' => 'ok', 'derived_status' => 'ok']]; }
            public function getJob(string $jobKey): ?array
            {
                foreach (($this->overrides['job_health'] ?? [['job_key' => 'ai_confidence_calibration', 'status' => 'ok', 'derived_status' => 'ok']]) as $job) {
                    if (($job['job_key'] ?? '') === $jobKey) {
                        return $job;
                    }
                }
                return null;
            }
        };

        $runtime = new class($overrides) extends AIRuntimeControlService {
            public function __construct(private array $overrides) {}
            public function getAllEffectiveControls(): array
            {
                return $this->overrides['runtime_controls'] ?? [
                    'global' => ['control_mode' => 'normal'],
                    'autonomous_tuning' => ['control_mode' => 'normal'],
                ];
            }
            public function getEffectiveControl(string $surface): array
            {
                return ($this->getAllEffectiveControls()[$surface] ?? ['control_mode' => 'normal']) + ['effective_surface' => $surface];
            }
        };

        $calibration = new class($overrides) extends AIConfidenceCalibrationService {
            public function __construct(private array $overrides) {}
            public function getCalibrationSummary(array $filters = []): array
            {
                return $this->overrides['calibration_summary'] ?? ['summary' => [], 'recent_changes' => []];
            }
        };

        $alerting = new class extends AlertingSystem {
            public function __construct() {}
            public function createAlert(string $alertType, string $severity, string $title, string $message, array $metadata = []): int
            {
                Database::execute(
                    "INSERT INTO alerts (alert_type, severity, title, message, metadata, status, created_at) VALUES (?, ?, ?, ?, ?, 'active', NOW())",
                    [$alertType, $severity, $title, $message, json_encode($metadata)]
                );
                return (int) Database::lastInsertId();
            }
        };

        return new AIAutomationIncidentService($diagnostics, $promptQuality, $jobHealth, $runtime, $calibration, $alerting, new UserPreferences());
    }
}
