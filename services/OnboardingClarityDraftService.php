<?php
/**
 * Field-ready Clarity drafts for workspace onboarding.
 */

namespace CRM\Services;

use CRM\Modules\CompanyProfile;
use CRM\Modules\InvoiceSettings;
use CRM\Modules\Products;
use CRM\Modules\UserStrategyProfile;

class OnboardingClarityDraftService
{
    private AIService $aiService;

    /**
     * @var array<string,array{step:int,label:string,instruction:string,max:int}>
     */
    private array $fieldMap = [
        'company_description' => [
            'step' => 1,
            'label' => 'Company description',
            'instruction' => 'Draft a clear 2-3 sentence description of what this business does, who it helps, and the practical outcome it creates.',
            'max' => 420,
        ],
        'success_outcome' => [
            'step' => 1,
            'label' => 'Success outcome',
            'instruction' => 'Draft one concise outcome statement describing what should feel better once this CRM is working.',
            'max' => 240,
        ],
        'draft_voice_notes' => [
            'step' => 3,
            'label' => 'Voice notes',
            'instruction' => 'Draft practical voice notes Clarity can follow when writing replies for this company.',
            'max' => 360,
        ],
        'words_to_avoid' => [
            'step' => 3,
            'label' => 'Words to avoid',
            'instruction' => 'Draft a short comma-separated list of risky, overused, or off-brand words and promises to avoid.',
            'max' => 180,
        ],
        'escalation_preference' => [
            'step' => 3,
            'label' => 'Escalation preference',
            'instruction' => 'Draft a short rule for when customer conversations should be escalated to a human.',
            'max' => 220,
        ],
        'product_description' => [
            'step' => 4,
            'label' => 'Product or service details',
            'instruction' => 'Draft a practical product/service description that connects the offer to the ideal customer and result.',
            'max' => 420,
        ],
        'ideal_customer_profile' => [
            'step' => 4,
            'label' => 'Ideal customer',
            'instruction' => 'Draft a specific ideal customer profile in one sentence.',
            'max' => 220,
        ],
        'icp_pain_points' => [
            'step' => 4,
            'label' => 'Pain points',
            'instruction' => 'Draft a short comma-separated list of customer pain points this offer solves.',
            'max' => 240,
        ],
        'offer_angle' => [
            'step' => 4,
            'label' => 'Offer angle',
            'instruction' => 'Draft one concise offer angle that would make the service easy to position in sales conversations.',
            'max' => 220,
        ],
        'objections' => [
            'step' => 4,
            'label' => 'Common objections',
            'instruction' => 'Draft a short list of likely sales objections, separated by commas.',
            'max' => 260,
        ],
        'positioning_notes' => [
            'step' => 4,
            'label' => 'Positioning notes',
            'instruction' => 'Draft positioning notes that explain the value, differentiation, and best sales framing.',
            'max' => 520,
        ],
        'lean_problem' => ['step' => 5, 'label' => 'Lean Canvas problem', 'instruction' => 'Draft the key customer problems for the Lean Canvas.', 'max' => 260],
        'lean_customer_segments' => ['step' => 5, 'label' => 'Lean Canvas customers', 'instruction' => 'Draft the customer segments for the Lean Canvas.', 'max' => 260],
        'lean_unique_value_proposition' => ['step' => 5, 'label' => 'Lean Canvas unique promise', 'instruction' => 'Draft a unique value proposition for the Lean Canvas.', 'max' => 260],
        'lean_solution' => ['step' => 5, 'label' => 'Lean Canvas solution', 'instruction' => 'Draft the solution block for the Lean Canvas.', 'max' => 260],
        'lean_channels' => ['step' => 5, 'label' => 'Lean Canvas channels', 'instruction' => 'Draft likely acquisition and communication channels for the Lean Canvas.', 'max' => 240],
        'lean_revenue_streams' => ['step' => 5, 'label' => 'Lean Canvas revenue', 'instruction' => 'Draft likely revenue streams for the Lean Canvas.', 'max' => 240],
        'lean_cost_structure' => ['step' => 5, 'label' => 'Lean Canvas costs', 'instruction' => 'Draft a simple cost structure for the Lean Canvas.', 'max' => 240],
        'lean_key_metrics' => ['step' => 5, 'label' => 'Lean Canvas metrics', 'instruction' => 'Draft useful key metrics for this business model.', 'max' => 240],
        'lean_unfair_advantage' => ['step' => 5, 'label' => 'Lean Canvas edge', 'instruction' => 'Draft a realistic unfair advantage or defensible edge.', 'max' => 240],
        'bank_instructions' => [
            'step' => 6,
            'label' => 'Payment instructions',
            'instruction' => 'Draft clear customer-facing payment instructions for invoices.',
            'max' => 320,
        ],
        'default_notes' => [
            'step' => 6,
            'label' => 'Invoice notes',
            'instruction' => 'Draft a short default invoice note that feels professional and helpful.',
            'max' => 260,
        ],
        'default_payment_terms_days' => [
            'step' => 6,
            'label' => 'Payment terms',
            'instruction' => 'Recommend a simple payment terms value in days. Return only the number.',
            'max' => 12,
        ],
        'technical_level' => [
            'step' => 7,
            'label' => 'Automation comfort',
            'instruction' => 'Explain the selected automation comfort level in one reassuring sentence.',
            'max' => 220,
        ],
    ];

    public function __construct(?AIService $aiService = null)
    {
        $this->aiService = $aiService ?: new AIService();
    }

    public function supportedFields(): array
    {
        return array_keys($this->fieldMap);
    }

    public function draft(int $workspaceId, int $userId, int $step, string $field, string $currentValue, array $formContext = []): array
    {
        $field = trim($field);
        if (!isset($this->fieldMap[$field])) {
            throw new \InvalidArgumentException('Unsupported onboarding draft field.');
        }

        $config = $this->fieldMap[$field];
        if ($step > 0 && (int) $config['step'] !== $step) {
            throw new \InvalidArgumentException('This field does not belong to the requested onboarding step.');
        }

        $context = $this->buildContext($workspaceId, $userId, $formContext);
        $hasUsefulContext = $this->hasUsefulContext($context, $currentValue);
        $sourceNotes = $this->sourceNotes($context);

        if (!$hasUsefulContext) {
            return [
                'success' => true,
                'field' => $field,
                'draft' => '',
                'source_notes' => 'I need a bit more context. Add a website or one sentence about the business.',
                'confidence' => 'low',
            ];
        }

        $draft = '';
        try {
            $prompt = $this->buildPrompt($field, $config, $currentValue, $context);
            $resolvedPrompt = $this->aiService->buildPromptFromRegistry('onboarding_clarity_draft', 'onboarding_field_draft', [
                'surface' => 'onboarding_clarity_draft',
                'prompt_key' => 'onboarding_field_draft',
                'blocks' => [
                    ['key' => 'field', 'content' => $config],
                    ['key' => 'context', 'content' => $context],
                ],
            ], [
                'legacy_prompt' => $prompt,
                'field' => $field,
            ]);
            $draft = $this->cleanDraft($this->aiService->processWithPrompt('onboarding_clarity_draft', $resolvedPrompt), (int) $config['max']);
        } catch (\Throwable $e) {
            $draft = '';
        }

        if ($draft === '') {
            $draft = $this->fallbackDraft($field, $context, $currentValue);
        }

        return [
            'success' => true,
            'field' => $field,
            'draft' => $this->cleanDraft($draft, (int) $config['max']),
            'source_notes' => $sourceNotes,
            'confidence' => $this->confidence($context),
        ];
    }

    public function buildContext(int $workspaceId, int $userId, array $formContext = []): array
    {
        $profile = (new CompanyProfile())->get() ?: [];
        $products = (new Products())->list();
        $strategy = $userId > 0 ? ((new UserStrategyProfile())->get($userId) ?: []) : [];
        $invoice = (new InvoiceSettings())->get();

        return [
            'workspace_id' => $workspaceId,
            'saved' => [
                'company' => $profile,
                'products' => array_slice($products, 0, 3),
                'strategy' => $strategy,
                'invoice' => $invoice,
            ],
            'form' => $this->sanitizeContext($formContext),
        ];
    }

    private function buildPrompt(string $field, array $config, string $currentValue, array $context): string
    {
        return "You are Clarity helping a user complete CRM onboarding.\n"
            . "Draft ONLY the replacement text for the requested field. Do not add labels, markdown, quotes, or explanations.\n"
            . "Use the website URL as context only; do not claim you browsed it.\n"
            . "If context is thin, make a useful but conservative draft and avoid unverifiable claims.\n\n"
            . "FIELD: {$field} ({$config['label']})\n"
            . "INSTRUCTION: {$config['instruction']}\n"
            . "MAX CHARACTERS: {$config['max']}\n"
            . "CURRENT VALUE:\n{$currentValue}\n\n"
            . "CONTEXT:\n" . json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }

    private function cleanDraft(string $draft, int $max): string
    {
        $draft = trim($draft);
        $draft = preg_replace('/^["\']|["\']$/', '', $draft) ?? $draft;
        $draft = preg_replace('/^\s*(draft|answer|field-ready text)\s*:\s*/i', '', $draft) ?? $draft;
        $draft = trim($draft);
        if ($max > 0 && mb_strlen($draft) > $max) {
            $draft = rtrim(mb_substr($draft, 0, $max - 1)) . '...';
        }
        return $draft;
    }

    private function hasUsefulContext(array $context, string $currentValue): bool
    {
        if (trim($currentValue) !== '') {
            return true;
        }

        $form = (array) ($context['form'] ?? []);
        $savedCompany = (array) ($context['saved']['company'] ?? []);
        foreach (['company_website', 'company_description', 'company_name', 'product_name', 'ideal_customer_profile'] as $key) {
            if ($this->meaningfulContextValue($form[$key] ?? '')) {
                return true;
            }
        }
        foreach (['company_website', 'company_description', 'company_name', 'company_industry'] as $key) {
            if ($this->meaningfulContextValue($savedCompany[$key] ?? '')) {
                return true;
            }
        }

        return !empty($context['saved']['products']);
    }

    private function meaningfulContextValue(mixed $value): bool
    {
        $value = trim((string) $value);
        if ($value === '') {
            return false;
        }

        return !in_array(strtolower($value), [
            'your company name',
            'thank you for your business.',
            'payment due within the stated terms.',
        ], true);
    }

    private function sourceNotes(array $context): string
    {
        $website = trim((string) (($context['form']['company_website'] ?? '') ?: ($context['saved']['company']['company_website'] ?? '')));
        return $website !== ''
            ? 'Drafted from website URL and onboarding context.'
            : 'Drafted from what you have entered so far.';
    }

    private function confidence(array $context): string
    {
        $form = (array) ($context['form'] ?? []);
        $savedCompany = (array) ($context['saved']['company'] ?? []);
        if (trim((string) (($form['company_website'] ?? '') ?: ($savedCompany['company_website'] ?? ''))) !== '') {
            return 'medium';
        }
        if (trim((string) (($form['company_description'] ?? '') ?: ($savedCompany['company_description'] ?? ''))) !== '') {
            return 'medium';
        }
        return 'low';
    }

    private function fallbackDraft(string $field, array $context, string $currentValue): string
    {
        $form = (array) ($context['form'] ?? []);
        $company = trim((string) (($form['company_name'] ?? '') ?: ($context['saved']['company']['company_name'] ?? 'your company')));
        $industry = trim((string) (($form['company_industry'] ?? '') ?: ($context['saved']['company']['company_industry'] ?? 'your market')));
        $description = trim((string) (($form['company_description'] ?? '') ?: ($context['saved']['company']['company_description'] ?? $currentValue)));
        $product = trim((string) (($form['product_name'] ?? '') ?: ($context['saved']['products'][0]['name'] ?? 'your core offer')));

        return match ($field) {
            'company_description' => "{$company} helps customers in {$industry} solve important operational problems with practical, reliable support.",
            'success_outcome' => 'Fewer missed opportunities, clearer follow-up, and a workspace that helps the team know what to do next.',
            'draft_voice_notes' => 'Use clear, warm, practical language. Be specific about next steps and avoid overpromising.',
            'words_to_avoid' => 'guaranteed, revolutionary, cheapest, instant results',
            'escalation_preference' => 'Escalate complaints, refunds, legal questions, angry messages, and any request involving sensitive payment decisions.',
            'product_description' => "{$product} helps the right customers get a clearer path from interest to action.",
            'ideal_customer_profile' => "Growing teams in {$industry} that need clearer follow-up, better customer context, and more consistent execution.",
            'icp_pain_points' => 'missed follow-up, scattered customer context, slow replies, unclear next steps',
            'offer_angle' => "Turn {$product} into a clearer, easier-to-act-on customer journey.",
            'objections' => 'price, timing, switching effort, trust, unclear ROI',
            'positioning_notes' => trim($description) !== '' ? $description : "{$company} should be positioned around practical outcomes, trust, and clearer execution.",
            'bank_instructions' => 'Please make payment by bank transfer using the invoice number as the payment reference.',
            'default_notes' => 'Thank you for your business. Please contact us if you have any questions about this invoice.',
            'default_payment_terms_days' => '14',
            'technical_level' => 'This mode keeps automation helpful while preserving human review for sensitive customer, deal, and money decisions.',
            default => "{$company} should keep this concise, practical, and specific to the customer outcome.",
        };
    }

    private function sanitizeContext(array $context): array
    {
        $clean = [];
        foreach ($context as $key => $value) {
            if (!is_scalar($value)) {
                continue;
            }
            $key = trim((string) $key);
            if ($key === '') {
                continue;
            }
            $clean[$key] = mb_substr(trim((string) $value), 0, 1200);
        }

        return $clean;
    }
}
