<?php

namespace CRM\Services;

class AIDiagnosticsReasonMapper
{
    public function map(array $event): array
    {
        $reasonCodes = array_values(array_filter(array_map(
            static fn ($reason): string => trim((string) $reason),
            (array) ($event['reason_codes'] ?? [])
        )));

        $bucket = $this->bucketFromReasons($reasonCodes, $event);
        $severity = $this->severityFromEvent($event, $bucket);

        return [
            'reason_codes' => $reasonCodes,
            'reason_bucket' => $bucket,
            'severity' => $severity,
        ];
    }

    public function bucketFromReasons(array $reasons, array $event = []): string
    {
        if ((string) ($event['source'] ?? '') === 'capability') {
            return 'feature_unready';
        }

        $haystack = strtolower(implode(' ', array_filter(array_map(
            static fn ($value): string => trim((string) $value),
            array_merge(
                $reasons,
                (array) ($event['warnings'] ?? []),
                [(string) ($event['human_summary'] ?? '')]
            )
        ))));

        if ($haystack === '') {
            return match ((string) ($event['source'] ?? '')) {
                'workflow' => 'workflow_error',
                'capability' => 'feature_unready',
                default => 'policy_block',
            };
        }

        $patterns = [
            'missing_context' => ['missing_context', 'insufficient thread context', 'context quality', 'missing linked', 'missing context'],
            'low_confidence' => ['low_confidence', 'confidence below', 'insufficient confidence', 'confidence threshold'],
            'goal_misalignment' => ['goal_misalignment', 'goal relevance', 'goal alignment', 'not relevant to goals'],
            'missing_recipient' => ['missing_recipient', 'recipient is required', 'billing email', 'destination missing'],
            'threshold_breach' => ['threshold_breach', 'discount above threshold', 'total delta', 'revision count', 'threshold'],
            'policy_block' => ['policy_block', 'document type not allowed', 'conversion source status', 'blocked by policy'],
            'feature_unready' => ['feature_unready', 'capability missing', 'capability degraded', 'not configured', 'missing optional table'],
            'retry_exhausted' => ['retry exhausted', 'retry_exhausted', 'delivery retry limit', 'too many retries'],
            'transport_unavailable' => ['transport_unavailable', 'channel transport unavailable', 'transport unavailable', 'service unavailable'],
            'explicit_feedback' => ['explicit_feedback', 'marked useful', 'marked not useful', 'marked already done', 'feedback recorded'],
            'ambiguous_resolution' => ['ambiguous_resolution', 'ambiguous', 'multiple candidate', 'clarification required'],
            'execution_failure' => ['execution_failure', 'execution failed', 'failed to execute', 'mutation failed'],
            'missing_data' => ['missing_data', 'zero total', 'missing billing identity', 'zero price', 'price not set'],
            'workflow_error' => ['workflow_error', 'node failed', 'retry pending', 'workflow failure', 'last_error', 'stale_job', 'job_failed', 'job_running'],
            'stale_context' => ['stale_context', 'stale_context_present'],
            'prompt_overload' => ['prompt_overload', 'overloaded prompt', 'bundle_trimmed'],
            'low_signal_context' => ['low_signal_context', 'empty_context_bundle', 'low signal'],
            'prompt_regression' => ['prompt_regression', 'prompt_version_changed'],
            'incident_alert' => ['assistant_blocked_spike', 'approval_required_spike', 'job_failure', 'job_stale', 'workflow_failure_spike', 'threshold_change_spike', 'autonomous_tuning_disabled', 'calibration_stale'],
        ];

        foreach ($patterns as $bucket => $needles) {
            foreach ($needles as $needle) {
                if ($needle !== '' && str_contains($haystack, strtolower($needle))) {
                    return $bucket;
                }
            }
        }

        return 'policy_block';
    }

    public function severityFromEvent(array $event, ?string $bucket = null): string
    {
        $bucket = $bucket ?? $this->bucketFromReasons((array) ($event['reason_codes'] ?? []), $event);
        $decision = (string) ($event['decision'] ?? '');

        if (in_array($decision, ['blocked', 'failed'], true)) {
            return 'high';
        }

        if (in_array($decision, ['approval_required', 'retry_pending'], true)) {
            return 'medium';
        }

        if (in_array($bucket, ['low_confidence', 'missing_context', 'feature_unready', 'workflow_error'], true)) {
            return 'medium';
        }

        return 'low';
    }
}
