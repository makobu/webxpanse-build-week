<?php

namespace CRM\Services;

use CRM\Modules\CompanyProfile;
use CRM\Modules\Products;
use CRM\Modules\UserStrategyProfile;

class WorkspaceOnboardingContextGapService
{
    /**
     * @return array<string,mixed>
     */
    public function review(array $profile, array $products, array $strategy, array $invoiceSettings, array $row, array $readiness): array
    {
        $primaryProduct = (array) ($products[0] ?? []);
        $questions = [];

        if ($this->isThin($strategy['ideal_customer_profile'] ?? '') && $this->isThin($primaryProduct['target_audience'] ?? '')) {
            $questions[] = [
                'key' => 'ideal_customer',
                'label' => 'Who is the customer you want more of?',
                'placeholder' => 'Example: founders of growing service businesses who need faster follow-up',
                'target' => 'strategy.ideal_customer_profile',
            ];
        }

        if ($this->isThin($strategy['offer_angle'] ?? '') && $this->isThin($strategy['positioning_notes'] ?? '')) {
            $questions[] = [
                'key' => 'why_choose_you',
                'label' => 'Why should they choose you?',
                'placeholder' => 'A short edge, proof point, promise, or difference',
                'target' => 'strategy.offer_angle',
            ];
        }

        if ($this->isThin($profile['owner_company_context'] ?? '') && $this->isThin($strategy['deal_movement_strategy'] ?? '')) {
            $questions[] = [
                'key' => 'customer_outcome',
                'label' => 'What should feel different after working with you?',
                'placeholder' => 'Example: fewer missed leads, clearer pipeline, faster decisions',
                'target' => 'company.owner_company_context',
            ];
        }

        $tone = $this->decodeAssoc($row['tone_json'] ?? null);
        if ($this->isThin($tone['words_to_avoid'] ?? '') && $this->isThin($tone['escalation_preference'] ?? '')) {
            $questions[] = [
                'key' => 'never_promise',
                'label' => 'What should Clarity never promise for you?',
                'placeholder' => 'Example: discounts, delivery dates, refunds, legal advice',
                'target' => 'tone.words_to_avoid',
            ];
        }

        $questions = array_slice($questions, 0, 3);
        $confidenceScore = $this->score($profile, $primaryProduct, $strategy, $tone, $readiness);

        return [
            'score' => $confidenceScore,
            'confidence_label' => $confidenceScore >= 85 ? 'brief_ready' : ($confidenceScore >= 65 ? 'nearly_ready' : 'needs_tidy'),
            'requires_answers' => $questions !== [],
            'questions' => $questions,
            'understands' => $this->understands($profile, $primaryProduct, $strategy),
            'avoid_guessing' => $this->avoidGuessing($tone, $invoiceSettings, $readiness),
            'reviewed_at' => gmdate('c'),
        ];
    }

    /**
     * @param array<string,string> $answers
     */
    public function applyAnswers(int $userId, array $answers, array $questions, array $existingTone = []): array
    {
        $strategyUpdates = [];
        $companyUpdates = [];
        $toneUpdates = $existingTone;
        $strategy = new UserStrategyProfile();
        $existingStrategy = $userId > 0 ? ($strategy->get($userId) ?: []) : [];

        foreach ($questions as $question) {
            $key = (string) ($question['key'] ?? '');
            $answer = trim((string) ($answers[$key] ?? ''));
            if ($key === '' || $answer === '' || strcasecmp($answer, 'not sure yet') === 0) {
                continue;
            }

            switch ((string) ($question['target'] ?? '')) {
                case 'strategy.ideal_customer_profile':
                    $strategyUpdates['ideal_customer_profile'] = $answer;
                    break;
                case 'strategy.offer_angle':
                    $strategyUpdates['offer_angle'] = $answer;
                    $strategyUpdates['positioning_notes'] = $answer;
                    break;
                case 'company.owner_company_context':
                    $companyUpdates['owner_company_context'] = $answer;
                    $strategyUpdates['deal_movement_strategy'] = $answer;
                    break;
                case 'tone.words_to_avoid':
                    $toneUpdates['words_to_avoid'] = $answer;
                    $strategyUpdates['draft_voice_notes'] = $this->appendLine(
                        (string) ($strategyUpdates['draft_voice_notes'] ?? ($existingStrategy['draft_voice_notes'] ?? '')),
                        'Never promise: ' . $answer
                    );
                    break;
                case 'tone.escalation_preference':
                    $toneUpdates['escalation_preference'] = $answer;
                    $strategyUpdates['outreach_posture'] = $this->appendLine(
                        (string) ($strategyUpdates['outreach_posture'] ?? ($existingStrategy['outreach_posture'] ?? '')),
                        'Ask first when: ' . $answer
                    );
                    break;
            }
        }

        if ($strategyUpdates !== []) {
            $strategy->save($userId, array_merge($existingStrategy, $strategyUpdates));
        }

        if ($companyUpdates !== []) {
            (new CompanyProfile())->update($companyUpdates);
        }

        return $toneUpdates;
    }

    /**
     * @return array<string,mixed>
     */
    public function welcome(array $launchSummary, array $review, array $answers, array $starterKit): array
    {
        return [
            'headline' => 'Welcome to Clarity.',
            'message' => 'Your workspace now has enough context to start with useful judgment.',
            'operating_brief' => $launchSummary,
            'context_review' => $review,
            'answers' => $answers,
            'starter_kit' => $starterKit,
            'ready_to_enter' => true,
            'generated_at' => gmdate('c'),
        ];
    }

    private function score(array $profile, array $primaryProduct, array $strategy, array $tone, array $readiness): int
    {
        $checks = [
            !$this->isThin($profile['company_name'] ?? ''),
            !$this->isThin($profile['company_description'] ?? ''),
            !$this->isThin($primaryProduct['name'] ?? ''),
            !$this->isThin($strategy['ideal_customer_profile'] ?? $primaryProduct['target_audience'] ?? ''),
            !$this->isThin($strategy['offer_angle'] ?? $strategy['positioning_notes'] ?? ''),
            !$this->isThin($strategy['draft_tone_preset'] ?? ''),
            !$this->isThin($tone['relationship_style'] ?? ''),
            !$this->isThin($tone['words_to_avoid'] ?? $tone['escalation_preference'] ?? ''),
            !empty($readiness['autopilot_ready']),
        ];

        return (int) round((count(array_filter($checks)) / count($checks)) * 100);
    }

    private function understands(array $profile, array $primaryProduct, array $strategy): array
    {
        return array_values(array_filter([
            !$this->isThin($profile['company_name'] ?? '') ? 'who you are' : '',
            !$this->isThin($primaryProduct['name'] ?? '') ? 'what you sell' : '',
            !$this->isThin($strategy['ideal_customer_profile'] ?? $primaryProduct['target_audience'] ?? '') ? 'who you want more of' : '',
            !$this->isThin($strategy['draft_tone_preset'] ?? '') ? 'how you want to sound' : '',
        ]));
    }

    private function avoidGuessing(array $tone, array $invoiceSettings, array $readiness): array
    {
        $items = [];
        if (!$this->isThin($tone['words_to_avoid'] ?? '')) {
            $items[] = 'promises you ruled out';
        }
        if (!$this->isThin($tone['escalation_preference'] ?? '')) {
            $items[] = 'moments that need human approval';
        }
        if (empty($invoiceSettings['bank_instructions'])) {
            $items[] = 'payment instructions not saved yet';
        }
        if (empty($readiness['channel_ready'])) {
            $items[] = 'customer-channel facts until a channel is connected later';
        }
        return $items;
    }

    private function isThin(mixed $value): bool
    {
        $text = trim((string) $value);
        return $text === '' || mb_strlen($text) < 12 || in_array(strtolower($text), ['n/a', 'none', 'not sure', 'not sure yet', 'tbd'], true);
    }

    private function decodeAssoc(mixed $json): array
    {
        if (is_array($json)) {
            return $json;
        }
        $decoded = is_string($json) ? json_decode($json, true) : [];
        return is_array($decoded) ? $decoded : [];
    }

    private function appendLine(string $existing, string $line): string
    {
        $existing = trim($existing);
        $line = trim($line);
        if ($line === '') {
            return $existing;
        }
        if ($existing === '') {
            return $line;
        }
        return $existing . "\n" . $line;
    }
}
