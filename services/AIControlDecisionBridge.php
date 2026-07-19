<?php

namespace CRM\Services;

class AIControlDecisionBridge
{
    public function __construct(
        private ?AIRuntimeControlService $controls = null
    ) {
        $this->controls = $this->controls ?? new AIRuntimeControlService();
    }

    public function applyControlToAdviceDecision(string $surface, array $decision): array
    {
        return $this->applyControl($surface, $decision, false);
    }

    public function applyControlToActionDecision(string $surface, array $decision): array
    {
        return $this->applyControl($surface, $decision, true);
    }

    public function applyControls(array $surfaces, array $decision, bool $isAction = false): array
    {
        $effective = $decision;
        foreach (array_values(array_unique(array_filter($surfaces))) as $surface) {
            $effective = $this->applyControl((string) $surface, $effective, $isAction);
        }
        return $effective;
    }

    private function applyControl(string $surface, array $decision, bool $isAction): array
    {
        $control = $this->controls->getEffectiveControl($surface);
        $mode = (string) ($control['control_mode'] ?? 'normal');
        $reason = trim((string) ($control['reason'] ?? ''));
        $reasons = array_values(array_unique(array_filter((array) ($decision['reasons'] ?? []))));
        $warnings = array_values(array_unique(array_filter((array) ($decision['warnings'] ?? []))));

        if ($mode === 'normal') {
            $decision['runtime_control'] = $control;
            return $decision;
        }

        if ($mode === 'suggest_only') {
            $decision['decision'] = 'suggest_only';
            $reasons[] = 'runtime_control_suggest_only';
            if ($reason !== '') {
                $warnings[] = $reason;
            }
        } elseif ($mode === 'paused') {
            $decision['decision'] = 'blocked';
            $reasons[] = 'runtime_control_paused';
            if ($reason !== '') {
                $warnings[] = $reason;
            }
        } elseif ($mode === 'diagnostics_only') {
            $decision['decision'] = $isAction ? 'blocked' : 'suggest_only';
            $reasons[] = 'runtime_control_diagnostics_only';
            if ($reason !== '') {
                $warnings[] = $reason;
            }
        }

        $decision['approval_required'] = $decision['decision'] === 'approval_required';
        $decision['can_execute'] = in_array((string) ($decision['decision'] ?? ''), ['allow', 'allow_with_warning'], true);
        $decision['reasons'] = array_values(array_unique($reasons));
        $decision['warnings'] = array_values(array_unique($warnings));
        $decision['runtime_control'] = $control;

        return $decision;
    }
}
