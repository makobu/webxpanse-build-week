<?php
/**
 * Deal Automation Checklist Rules Engine
 *
 * Evaluates admin-defined checklist against evidence payload.
 * Enforces: per-transition min confidence, terminal evidence, cooldown, anti-flap, no multi-stage jumps.
 * Returns decision: reject, suggest_only, auto_apply.
 */

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\DealAutomationConfig;

class DealAutomationRulesEngine
{
    private DealAutomationConfig $config;

    public function __construct()
    {
        $this->config = new DealAutomationConfig();
    }

    /**
     * Evaluate and return decision.
     *
     * @param array $evidence Evidence from DealAutomationEvidenceBuilder
     * @param array $suggestion AI suggestion { suggested_stage, confidence, reasoning, evidence_flags }
     * @param int|null $lastStageChangeDealId For cooldown check - get last change for this deal
     * @return array { decision: 'reject'|'suggest_only'|'auto_apply', reason: string, checklist_results: array }
     */
    public function evaluate(array $evidence, array $suggestion, ?int $dealId = null): array
    {
        $cfg = $this->config->get();
        $fromStage = $evidence['current_stage'] ?? 'prospecting';
        $toStage = $suggestion['suggested_stage'] ?? null;
        $confidence = (float) ($suggestion['confidence'] ?? 0);

        if (!$toStage || !in_array($toStage, DealAutomationConfig::getAllowedStages())) {
            return [
                'decision' => 'reject',
                'reason' => 'No valid suggested stage.',
                'checklist_results' => [],
            ];
        }

        $checklistResults = [];
        $transition = $this->findTransition($cfg['transitions'], $fromStage, $toStage);

        if (!$transition || empty($transition['enabled'])) {
            return [
                'decision' => 'reject',
                'reason' => 'No enabled transition rule for ' . $fromStage . ' -> ' . $toStage,
                'checklist_results' => [],
            ];
        }

        $minConf = (float) ($transition['min_confidence'] ?? $cfg['min_confidence']);
        $isTerminal = !empty($transition['terminal']);
        $requireApprovalTerminal = $cfg['require_approval_terminal'] ?? true;
        $minTerminalConf = (float) ($cfg['min_terminal_confidence'] ?? 0.92);
        $allowMultiJump = !empty($cfg['allow_multi_stage_jump']);

        // Multi-stage jump check
        if (!$allowMultiJump && !$this->isAdjacentStage($fromStage, $toStage)) {
            return [
                'decision' => 'reject',
                'reason' => 'Multi-stage jump not allowed.',
                'checklist_results' => ['multi_stage_jump' => false],
            ];
        }

        // Cooldown check
        if ($dealId) {
            $lastChange = $this->getLastStageChangeAt($dealId);
            $cooldownHours = (int) ($cfg['cooldown_hours'] ?? 24);
            if ($lastChange && $cooldownHours > 0) {
                $elapsed = (time() - strtotime($lastChange)) / 3600;
                if ($elapsed < $cooldownHours) {
                    return [
                        'decision' => 'reject',
                        'reason' => "Cooldown: last change {$elapsed}h ago, need {$cooldownHours}h.",
                        'checklist_results' => ['cooldown' => false],
                    ];
                }
            }
        }

        // Confidence check
        if ($confidence < $minConf) {
            return [
                'decision' => 'suggest_only',
                'reason' => "Confidence {$confidence} below transition minimum {$minConf}.",
                'checklist_results' => ['confidence' => false, 'actual' => $confidence, 'required' => $minConf],
            ];
        }

        if ($isTerminal && $confidence < $minTerminalConf) {
            return [
                'decision' => 'suggest_only',
                'reason' => "Terminal transition requires min confidence {$minTerminalConf}.",
                'checklist_results' => ['terminal_confidence' => false],
            ];
        }

        if ($isTerminal && $requireApprovalTerminal) {
            return [
                'decision' => 'suggest_only',
                'reason' => 'Terminal transitions require manual approval.',
                'checklist_results' => ['require_approval_terminal' => true],
            ];
        }

        // Evaluate rules
        $requireAll = !empty($transition['require_all']);
        $rules = $transition['rules'] ?? [];
        $passed = 0;
        $failed = 0;

        foreach ($rules as $rule) {
            $result = $this->evaluateRule($rule, $evidence, $cfg);
            $checklistResults[$rule['type'] ?? 'unknown'] = $result;
            if ($result['passed']) {
                $passed++;
            } else {
                $failed++;
            }
        }

        $rulesPass = $requireAll ? ($failed === 0) : ($passed > 0);

        if (!$rulesPass) {
            return [
                'decision' => 'suggest_only',
                'reason' => 'Checklist rules not satisfied.',
                'checklist_results' => $checklistResults,
            ];
        }

        $mode = $cfg['mode'] ?? 'suggest_only';
        $dryRun = !empty($cfg['dry_run']);

        if ($dryRun) {
            return [
                'decision' => 'suggest_only',
                'reason' => 'Dry run mode - no auto-apply.',
                'checklist_results' => $checklistResults,
            ];
        }

        $autoApply = in_array($mode, ['auto_safe', 'full_auto']);
        if ($isTerminal && $mode === 'auto_safe') {
            $autoApply = false;
        }

        return [
            'decision' => $autoApply ? 'auto_apply' : 'suggest_only',
            'reason' => $autoApply ? 'Checklist passed, auto-apply.' : 'Mode is suggest_only.',
            'checklist_results' => $checklistResults,
        ];
    }

    private function findTransition(array $transitions, string $fromStage, string $toStage): ?array
    {
        foreach ($transitions as $t) {
            $from = $t['from'] ?? '';
            $to = $t['to'] ?? '';
            if ($from === 'any_open' && in_array($fromStage, ['prospecting', 'qualification', 'proposal', 'negotiation'])) {
                if ($to === $toStage) {
                    return $t;
                }
            }
            if ($from === $fromStage && $to === $toStage) {
                return $t;
            }
        }
        return null;
    }

    private function isAdjacentStage(string $from, string $to): bool
    {
        $order = ['prospecting', 'qualification', 'proposal', 'negotiation', 'closed_won', 'closed_lost'];
        $i = array_search($from, $order);
        $j = array_search($to, $order);
        if ($i === false || $j === false) {
            return false;
        }
        return abs($j - $i) <= 1;
    }

    private function getLastStageChangeAt(int $dealId): ?string
    {
        $row = Database::queryOne(
            "SELECT created_at FROM deal_automation_audit
             WHERE deal_id = ? AND applied = 1
             ORDER BY created_at DESC LIMIT 1",
            [$dealId]
        );
        return $row['created_at'] ?? null;
    }

    private function evaluateRule(array $rule, array $evidence, array $config): array
    {
        $type = $rule['type'] ?? '';
        $passed = false;
        $detail = '';

        switch ($type) {
            case 'intent_count':
                $intent = $rule['intent'] ?? 'purchase';
                $min = (int) ($rule['min'] ?? 1);
                $counts = $evidence['intent_counts'] ?? [];
                $count = (int) ($counts[$intent] ?? 0);
                $passed = $count >= $min;
                $detail = "intent {$intent}: {$count} >= {$min}";
                break;

            case 'proposal_sent':
                $passed = !empty($evidence['proposal_sent']);
                $detail = $passed ? 'proposal sent' : 'no proposal sent';
                break;

            case 'lead_score_min':
                $minVal = (int) ($rule['value'] ?? 0);
                $score = (int) ($evidence['lead_score'] ?? 0);
                $passed = $score >= $minVal;
                $detail = "lead_score {$score} >= {$minVal}";
                break;

            case 'no_negative_trend':
                $sentiment = $evidence['sentiment_summary'] ?? [];
                $passed = empty($sentiment['has_negative_trend']);
                $detail = $passed ? 'no negative trend' : 'negative trend detected';
                break;

            case 'no_negative_in_window':
                $sentiment = $evidence['sentiment_summary'] ?? [];
                $passed = ($sentiment['negative_count'] ?? 0) === 0;
                $detail = $passed ? 'no negative in window' : 'negative in window';
                break;

            case 'no_positive_in_window':
                $sentiment = $evidence['sentiment_summary'] ?? [];
                $passed = ($sentiment['positive_count'] ?? 0) === 0;
                $detail = $passed ? 'no positive in window' : 'positive in window';
                break;

            case 'bidirectional_exchange':
                $minMsg = (int) ($rule['min_messages'] ?? 2);
                $comms = $evidence['communications'] ?? [];
                $inbound = 0;
                $outbound = 0;
                foreach ($comms as $c) {
                    if (($c['direction'] ?? '') === 'inbound') $inbound++;
                    elseif (($c['direction'] ?? '') === 'outbound') $outbound++;
                }
                $passed = $inbound >= 1 && $outbound >= 1 && count($comms) >= $minMsg;
                $detail = "bidirectional: {$inbound} in, {$outbound} out, total " . count($comms);
                break;

            case 'explicit_acceptance':
                $passed = $this->hasExplicitAcceptance($evidence);
                $detail = $passed ? 'explicit acceptance found' : 'no explicit acceptance';
                break;

            case 'explicit_rejection':
                $passed = $this->hasExplicitRejection($evidence);
                $detail = $passed ? 'explicit rejection found' : 'no explicit rejection';
                break;

            case 'inactivity_timeout':
                $days = (int) ($rule['days'] ?? $config['inactivity_days_for_loss'] ?? 14);
                $lastAt = $evidence['last_activity_at'] ?? null;
                $inactivityDays = 999;
                if ($lastAt) {
                    $inactivityDays = max(0, (int) ((time() - strtotime($lastAt)) / 86400));
                }
                $passed = $inactivityDays >= $days;
                $detail = "inactivity {$inactivityDays} days >= {$days}";
                break;

            default:
                $passed = false;
                $detail = "unknown rule type: {$type}";
        }

        return [
            'passed' => $passed,
            'detail' => $detail,
            'rule_type' => $type,
        ];
    }

    private function hasExplicitAcceptance(array $evidence): bool
    {
        $comms = $evidence['communications'] ?? [];
        $acceptKeywords = ['accept', 'accepted', 'agree', 'agreed', 'approved', 'sign', 'signed', 'yes we\'ll', 'let\'s proceed', 'go ahead'];
        foreach ($comms as $c) {
            $text = strtolower(($c['subject'] ?? '') . ' ' . ($c['body_preview'] ?? ''));
            foreach ($acceptKeywords as $kw) {
                if (strpos($text, $kw) !== false) {
                    $sentiment = strtolower($c['sentiment'] ?? 'neutral');
                    if ($sentiment !== 'negative') {
                        return true;
                    }
                }
            }
            if (($c['intent'] ?? '') === 'purchase' && (float) ($c['sentiment_score'] ?? 0) > 0.3) {
                return true;
            }
        }
        return false;
    }

    private function hasExplicitRejection(array $evidence): bool
    {
        $comms = $evidence['communications'] ?? [];
        $rejectKeywords = ['reject', 'decline', 'not interested', 'no thanks', 'pass', 'won\'t proceed', 'cancel', 'cancellation'];
        foreach ($comms as $c) {
            $text = strtolower(($c['subject'] ?? '') . ' ' . ($c['body_preview'] ?? ''));
            foreach ($rejectKeywords as $kw) {
                if (strpos($text, $kw) !== false) {
                    return true;
                }
            }
            if (in_array($c['intent'] ?? '', ['cancellation', 'complaint']) && (float) ($c['sentiment_score'] ?? 0) < -0.2) {
                return true;
            }
        }
        return false;
    }
}
