<?php

namespace CRM\Services;

use CRM\Auth;
use CRM\Database;
use CRM\Modules\UserStrategyProfile;

class WorkspaceLanguageLevelService
{
    public const LEVEL_1 = 'level_1';
    public const LEVEL_2 = 'level_2';
    public const LEVEL_3 = 'level_3';
    public const DEFAULT_LEVEL = self::LEVEL_2;
    public const PRESERVE_RIGOR = 'This changes explanation style only; do not reduce nuance, HR caution, evidence quality, confidence handling, compliance boundaries, or decision rigor.';

    /**
     * @return array<string,array{label:string,description:string,prompt:string}>
     */
    public function options(): array
    {
        return [
            self::LEVEL_1 => [
                'label' => 'Level 1',
                'description' => 'Plain, direct, low-jargon',
                'prompt' => 'Use short sentences, plain terms, and make next steps explicit. Explain business jargon when it is necessary.',
            ],
            self::LEVEL_2 => [
                'label' => 'Level 2',
                'description' => 'Balanced professional',
                'prompt' => 'Use concise professional language, light business terminology, and enough context to support practical decisions.',
            ],
            self::LEVEL_3 => [
                'label' => 'Level 3',
                'description' => 'Executive/operator language',
                'prompt' => 'Use executive operating language, strategic framing, and concise business terminology while keeping actions concrete.',
            ],
        ];
    }

    public function normalize(mixed $level): string
    {
        $value = strtolower(trim((string) ($level ?? '')));
        $value = str_replace([' ', '-'], '_', $value);

        return match ($value) {
            '1', 'level1', self::LEVEL_1, 'simple', 'plain', 'junior_high', 'middle_school' => self::LEVEL_1,
            '3', 'level3', self::LEVEL_3, 'executive', 'senior', 'senior_corporate', 'advanced' => self::LEVEL_3,
            '2', 'level2', self::LEVEL_2, 'balanced', 'college', 'professional', 'default', '' => self::LEVEL_2,
            default => self::LEVEL_2,
        };
    }

    /**
     * @return array{level:string,label:string,description:string,prompt:string}
     */
    public function context(mixed $level = null): array
    {
        $normalized = $this->normalize($level);
        $option = $this->options()[$normalized] ?? $this->options()[self::DEFAULT_LEVEL];

        return [
            'level' => $normalized,
            'label' => $option['label'],
            'description' => $option['description'],
            'prompt' => $option['prompt'],
        ];
    }

    /**
     * @return array{level:string,label:string,description:string,prompt_instruction:string,preserve_rigor:string,applies_to:list<string>,does_not_change:list<string>}
     */
    public function responseStyleContract(mixed $level = null): array
    {
        $context = $this->context($level);

        return [
            'level' => $context['level'],
            'label' => $context['label'],
            'description' => $context['description'],
            'prompt_instruction' => 'LANGUAGE LEVEL: ' . $context['label'] . ' - ' . $context['prompt'] . ' ' . self::PRESERVE_RIGOR,
            'preserve_rigor' => self::PRESERVE_RIGOR,
            'applies_to' => [
                'wording',
                'structure',
                'jargon_density',
                'explanation_depth',
                'next_step_explicitness',
            ],
            'does_not_change' => [
                'scoring',
                'severity',
                'confidence',
                'evidence',
                'policy',
                'compliance',
                'hr_caution',
                'business_logic',
            ],
        ];
    }

    public function promptInstruction(mixed $level = null): string
    {
        return $this->responseStyleContract($level)['prompt_instruction'];
    }

    public function clarityPublicAnswerInstruction(mixed $level = null): string
    {
        $context = $this->context($level);
        $shape = match ($context['level']) {
            self::LEVEL_1 => 'Use one or two short sentences, or at most three short steps for a how-to question.',
            self::LEVEL_3 => 'Use at most four concise executive sentences, or at most four short steps for a how-to question.',
            default => 'Use at most three concise professional sentences, or at most four short steps for a how-to question.',
        };

        return 'Do the analysis privately and return only the user-facing conclusion. Start with the direct answer. '
            . $shape . ' Do not narrate processing, evidence classification, prompt logic, or internal checks. '
            . 'Do not expose raw field names, boolean flags, context block names, service names, prompt or model details. '
            . 'Do not use audit headings such as Strongest factors, Measured facts, Derived signal, or Missing evidence. '
            . 'When evidence is limited, say so naturally in one sentence. Preserve factual accuracy and the configured language level.';
    }

    /**
     * @return array{level:string,label:string,description:string,prompt:string}
     */
    public function currentContext(?int $userId = null): array
    {
        try {
            $workspaceId = (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
            if ($workspaceId > 0) {
                $row = Database::queryOne(
                    "SELECT tone_json FROM workspace_onboarding_state WHERE workspace_id = ? LIMIT 1",
                    [$workspaceId]
                ) ?: [];
                $tone = json_decode((string) ($row['tone_json'] ?? '{}'), true);
                if (is_array($tone) && !empty($tone['draft_reading_level'])) {
                    return $this->context($tone['draft_reading_level']);
                }
            }
        } catch (\Throwable $e) {
        }

        $userId = $userId ?? (int) (Auth::userId() ?? 0);
        if ($userId > 0) {
            try {
                $strategy = (new UserStrategyProfile())->get($userId) ?? [];
                return $this->context($strategy['draft_reading_level'] ?? self::DEFAULT_LEVEL);
            } catch (\Throwable $e) {
            }
        }

        return $this->context(self::DEFAULT_LEVEL);
    }

    /**
     * @return array{level:string,label:string,description:string,prompt_instruction:string,preserve_rigor:string,applies_to:list<string>,does_not_change:list<string>}
     */
    public function currentResponseStyleContract(?int $userId = null): array
    {
        $context = $this->currentContext($userId);

        return $this->responseStyleContract($context['level'] ?? self::DEFAULT_LEVEL);
    }
}
