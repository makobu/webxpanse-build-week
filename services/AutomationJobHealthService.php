<?php

namespace CRM\Services;

use CRM\Database;

class AutomationJobHealthService
{
    private const JOB_RULES = [
        'ai_outcome_reconciliation' => ['stale_after_seconds' => 172800, 'label' => 'AI Outcome Reconciliation'],
        'ai_confidence_calibration' => ['stale_after_seconds' => 172800, 'label' => 'AI Confidence Calibration'],
        'cold_outreach_warmup' => ['stale_after_seconds' => 691200, 'label' => 'Cold Outreach Warmup'],
        'workflow_scheduler' => ['stale_after_seconds' => 300, 'label' => 'Workflow Scheduler'],
        'ai_runtime_control_cleanup' => ['stale_after_seconds' => 172800, 'label' => 'AI Runtime Control Cleanup'],
        'ai_incident_check' => ['stale_after_seconds' => 1800, 'label' => 'AI Incident Check'],
        'smart_template_refresh' => ['stale_after_seconds' => 691200, 'label' => 'Smart Template Refresh'],
        'whatsapp_assistant_session' => ['stale_after_seconds' => 90000, 'label' => 'WhatsApp Assistant Session Worker'],
        'whatsapp_assistant_digest' => ['stale_after_seconds' => 90000, 'label' => 'WhatsApp Assistant Digest Worker'],
        'email_assistant_digest' => ['stale_after_seconds' => 900, 'label' => 'Email Assistant Digest Worker'],
        'email_assistant_inbound' => ['stale_after_seconds' => 900, 'label' => 'Email Assistant Inbound Worker'],
        'organization_intelligence_snapshots' => ['stale_after_seconds' => 172800, 'label' => 'Organization Intelligence Snapshots'],
    ];

    public function markStarted(string $jobKey, array $metadata = []): void
    {
        $this->ensureTableExists();
        $existingMetadata = $this->getExistingMetadata($jobKey);
        $this->upsert($jobKey, 'running', '', null, array_merge($existingMetadata, $metadata));
    }

    public function markSuccess(string $jobKey, string $message = '', ?int $durationMs = null, array $metadata = []): void
    {
        $this->ensureTableExists();
        $existingMetadata = $this->getExistingMetadata($jobKey);
        $mergedMetadata = array_merge($existingMetadata, $metadata);
        Database::execute(
            "INSERT INTO automation_job_health
                (job_key, status, last_run_at, last_success_at, last_duration_ms, last_message, metadata_json)
             VALUES (?, 'ok', NOW(), NOW(), ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                status = 'ok',
                last_run_at = NOW(),
                last_success_at = NOW(),
                last_duration_ms = VALUES(last_duration_ms),
                last_message = VALUES(last_message),
                metadata_json = VALUES(metadata_json)",
            [
                $jobKey,
                $durationMs,
                trim($message),
                json_encode($mergedMetadata),
            ]
        );
    }

    public function markFailure(string $jobKey, string $message, ?int $durationMs = null, array $metadata = []): void
    {
        $this->ensureTableExists();
        $existingMetadata = $this->getExistingMetadata($jobKey);
        $mergedMetadata = array_merge($existingMetadata, $metadata);
        Database::execute(
            "INSERT INTO automation_job_health
                (job_key, status, last_run_at, last_failure_at, last_duration_ms, last_message, metadata_json)
             VALUES (?, 'failed', NOW(), NOW(), ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                status = 'failed',
                last_run_at = NOW(),
                last_failure_at = NOW(),
                last_duration_ms = VALUES(last_duration_ms),
                last_message = VALUES(last_message),
                metadata_json = VALUES(metadata_json)",
            [
                $jobKey,
                $durationMs,
                trim($message),
                json_encode($mergedMetadata),
            ]
        );
    }

    public function getJobs(): array
    {
        if (!$this->tableExists()) {
            return [];
        }

        $rows = Database::query("SELECT * FROM automation_job_health ORDER BY job_key ASC");
        $jobs = [];
        foreach ($rows as $row) {
            $jobs[] = $this->decorateJob($row);
        }
        return $jobs;
    }

    public function getSummary(): array
    {
        $summary = [
            'healthy' => 0,
            'failed' => 0,
            'stale' => 0,
            'running' => 0,
            'total' => 0,
        ];

        foreach ($this->getJobs() as $job) {
            $summary['total']++;
            if (($job['derived_status'] ?? '') === 'stale') {
                $summary['stale']++;
            } elseif (($job['status'] ?? '') === 'failed') {
                $summary['failed']++;
            } elseif (($job['status'] ?? '') === 'running') {
                $summary['running']++;
            } else {
                $summary['healthy']++;
            }
        }

        return $summary;
    }

    public function getJob(string $jobKey): ?array
    {
        if (!$this->tableExists()) {
            return null;
        }

        $row = Database::queryOne("SELECT * FROM automation_job_health WHERE job_key = ?", [$jobKey]);
        return $row ? $this->decorateJob($row) : null;
    }

    private function upsert(string $jobKey, string $status, string $message, ?int $durationMs, array $metadata): void
    {
        Database::execute(
            "INSERT INTO automation_job_health
                (job_key, status, last_run_at, last_duration_ms, last_message, metadata_json)
             VALUES (?, ?, NOW(), ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                last_run_at = NOW(),
                last_duration_ms = VALUES(last_duration_ms),
                last_message = VALUES(last_message),
                metadata_json = VALUES(metadata_json)",
            [$jobKey, $status, $durationMs, trim($message), json_encode($metadata)]
        );
    }

    private function decorateJob(array $row): array
    {
        $jobKey = (string) ($row['job_key'] ?? '');
        $rules = self::JOB_RULES[$jobKey] ?? ['stale_after_seconds' => 86400, 'label' => ucwords(str_replace('_', ' ', $jobKey))];
        $lastRunAt = (string) ($row['last_run_at'] ?? '');
        $derivedStatus = (string) ($row['status'] ?? 'ok');

        if ($lastRunAt !== '') {
            $lastRunTs = strtotime($lastRunAt);
            if ($lastRunTs !== false && (time() - $lastRunTs) > (int) $rules['stale_after_seconds']) {
                $derivedStatus = 'stale';
            }
        } elseif ($derivedStatus !== 'failed') {
            $derivedStatus = 'stale';
        }

        $metadata = json_decode((string) ($row['metadata_json'] ?? '{}'), true);
        $row['metadata'] = is_array($metadata) ? $metadata : [];
        $row['label'] = (string) $rules['label'];
        $row['stale_after_seconds'] = (int) $rules['stale_after_seconds'];
        $row['derived_status'] = $derivedStatus;

        return $row;
    }

    private function getExistingMetadata(string $jobKey): array
    {
        $existing = $this->getJob($jobKey);
        return is_array($existing['metadata'] ?? null) ? $existing['metadata'] : [];
    }

    private function ensureTableExists(): void
    {
        if (!$this->tableExists()) {
            throw new \RuntimeException('automation_job_health table is missing. Apply migration 118_create_automation_job_health.sql.');
        }
    }

    private function tableExists(): bool
    {
        $row = Database::queryOne(
            "SELECT COUNT(*) AS cnt
             FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'automation_job_health'"
        );

        return ((int) ($row['cnt'] ?? 0)) > 0;
    }
}
