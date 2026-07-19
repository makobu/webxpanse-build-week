<?php

namespace CRM\Services;

/**
 * Normalizes workspace-owned voice automation controls and produces one
 * consistent decision contract for workers, reviews, and AI context assembly.
 * It deliberately contains no country-specific legal rules.
 */
class VoicePolicyDecisionService
{
    public const POLICY_VERSION = 1;

    private const DEFAULTS = [
        'call_summary' => 'automatic',
        'contact_context' => 'approval_required',
        'follow_up_tasks' => 'approval_required',
        'deal_stage' => 'suggest',
        'customer_voice' => 'approval_required',
        'follow_up_messages' => 'off',
    ];

    private const ALLOWED = [
        'call_summary' => ['off', 'automatic'],
        'contact_context' => ['off', 'suggest', 'approval_required', 'automatic_safe'],
        'follow_up_tasks' => ['off', 'suggest', 'approval_required', 'automatic_safe'],
        'deal_stage' => ['off', 'suggest', 'approval_required'],
        'customer_voice' => ['off', 'suggest', 'approval_required'],
        'follow_up_messages' => ['off', 'suggest', 'approval_required'],
    ];

    /** @return array<string,mixed> */
    public function normalize(array $policy): array
    {
        $normalized = [
            'version' => self::POLICY_VERSION,
            'minimum_confidence' => max(0.5, min(1.0, (float) ($policy['minimum_confidence'] ?? 0.80))),
        ];

        foreach (self::DEFAULTS as $capability => $defaultMode) {
            $mode = trim((string) ($policy[$capability] ?? $defaultMode));
            $normalized[$capability] = in_array($mode, self::ALLOWED[$capability], true)
                ? $mode
                : $defaultMode;
        }

        return $normalized;
    }

    /** @return array<string,mixed> */
    public function forConfig(array $config): array
    {
        $settings = (array) ($config['settings'] ?? []);
        $policy = (array) ($config['automation_policy'] ?? $settings['automation_policy'] ?? []);
        return $this->normalize($policy);
    }

    /**
     * @return array{capability:string,mode:string,decision:string,apply:bool,reason:string,policy_version:int,minimum_confidence:float}
     */
    public function decide(
        array $config,
        string $capability,
        bool $approved = false,
        ?float $confidence = null
    ): array {
        if (!array_key_exists($capability, self::DEFAULTS)) {
            throw new \InvalidArgumentException('Unknown voice automation capability: ' . $capability);
        }

        $policy = $this->forConfig($config);
        $mode = (string) $policy[$capability];
        $decision = 'block';
        $reason = 'capability_disabled';

        if (empty($config['ai_application_enabled'])) {
            $reason = 'workspace_ai_application_disabled';
        } elseif ($capability === 'customer_voice' && empty($config['customer_voice_enabled'])) {
            $reason = 'workspace_customer_voice_disabled';
        } elseif ($mode === 'suggest') {
            $decision = 'suggest';
            $reason = 'workspace_suggest_only';
        } elseif ($mode === 'approval_required') {
            $decision = $approved ? 'allow' : 'require_approval';
            $reason = $approved ? 'workspace_approval_recorded' : 'workspace_approval_required';
        } elseif ($mode === 'automatic_safe') {
            $score = $confidence ?? 0.0;
            if ($approved) {
                $decision = 'allow';
                $reason = 'workspace_approval_recorded';
            } elseif ($score >= (float) $policy['minimum_confidence']) {
                $decision = 'allow';
                $reason = 'workspace_automatic_safe_threshold_met';
            } else {
                $decision = 'require_approval';
                $reason = 'automatic_safe_confidence_below_threshold';
            }
        } elseif ($mode === 'automatic') {
            $decision = 'allow';
            $reason = 'workspace_automatic_enabled';
        }

        return [
            'capability' => $capability,
            'mode' => $mode,
            'decision' => $decision,
            'apply' => $decision === 'allow',
            'reason' => $reason,
            'policy_version' => self::POLICY_VERSION,
            'minimum_confidence' => (float) $policy['minimum_confidence'],
        ];
    }

    public function structuredContextMayBeUsed(array $config, string $reviewStatus, float $confidence): bool
    {
        $policy = $this->forConfig($config);
        $mode = (string) $policy['contact_context'];
        if (empty($config['ai_application_enabled']) || $mode === 'off' || $mode === 'suggest') {
            return false;
        }
        if (in_array($reviewStatus, ['applied'], true)) {
            return true;
        }
        return $mode === 'automatic_safe' && $confidence >= (float) $policy['minimum_confidence'];
    }

    /** @return array<string,list<string>> */
    public function availableModes(): array
    {
        return self::ALLOWED;
    }
}
