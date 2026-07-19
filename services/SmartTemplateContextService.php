<?php

namespace CRM\Services;

use CRM\Modules\CompanyProfile;
use CRM\Modules\IdeaValidationContext;
use CRM\Modules\UserStrategyProfile;

class SmartTemplateContextService
{
    private CompanyProfile $companyProfile;
    private UserStrategyProfile $userStrategyProfile;
    private IdeaValidationContext $ideaValidationContext;

    public function __construct(
        ?CompanyProfile $companyProfile = null,
        ?UserStrategyProfile $userStrategyProfile = null,
        ?IdeaValidationContext $ideaValidationContext = null
    ) {
        $this->companyProfile = $companyProfile ?? new CompanyProfile();
        $this->userStrategyProfile = $userStrategyProfile ?? new UserStrategyProfile();
        $this->ideaValidationContext = $ideaValidationContext ?? new IdeaValidationContext();
    }

    public function getReadiness(int $userId): array
    {
        $company = $this->companyProfile->get() ?? [];
        $strategy = $this->userStrategyProfile->get($userId) ?? [];
        $ideaValidation = $this->ideaValidationContext->get($userId) ?? [];

        $sections = [
            'company_profile' => $this->evaluateSection([
                [
                    'fields' => ['company_name'],
                    'label' => 'Company name',
                ],
                [
                    'fields' => ['company_description', 'company_mission', 'owner_company_context'],
                    'label' => 'Company story or mission',
                ],
                [
                    'fields' => ['company_industry', 'icp_industries', 'icp_pain_points'],
                    'label' => 'Industry or ICP context',
                ],
            ], $company),
            'strategy_profile' => $this->evaluateSection([
                [
                    'fields' => ['target_market_focus'],
                    'label' => 'Target market focus',
                ],
                [
                    'fields' => ['ideal_customer_profile'],
                    'label' => 'Ideal customer profile',
                ],
                [
                    'fields' => ['offer_angle'],
                    'label' => 'Offer angle',
                ],
                [
                    'fields' => ['sales_motion'],
                    'label' => 'Sales motion',
                ],
            ], $strategy),
            'idea_validation' => $this->evaluateSection([
                [
                    'fields' => ['value_proposition'],
                    'label' => 'Value proposition',
                ],
                [
                    'fields' => ['target_market'],
                    'label' => 'Target market',
                ],
                [
                    'fields' => ['pain_points'],
                    'label' => 'Pain points',
                ],
                [
                    'fields' => ['differentiator'],
                    'label' => 'Differentiator',
                ],
            ], $ideaValidation),
        ];

        $snapshot = [
            'company_profile' => $this->normalizeSnapshot([
                'company_name' => $company['company_name'] ?? '',
                'company_description' => $company['company_description'] ?? '',
                'company_mission' => $company['company_mission'] ?? '',
                'owner_company_context' => $company['owner_company_context'] ?? '',
                'company_industry' => $company['company_industry'] ?? '',
                'company_size' => $company['company_size'] ?? '',
                'icp_job_titles' => $company['icp_job_titles'] ?? '',
                'icp_industries' => $company['icp_industries'] ?? '',
                'icp_pain_points' => $company['icp_pain_points'] ?? '',
                'icp_channels' => $company['icp_channels'] ?? '',
            ]),
            'strategy_profile' => $this->normalizeSnapshot([
                'target_market_focus' => $strategy['target_market_focus'] ?? '',
                'ideal_customer_profile' => $strategy['ideal_customer_profile'] ?? '',
                'offer_angle' => $strategy['offer_angle'] ?? '',
                'segment_focus' => $strategy['segment_focus'] ?? '',
                'sales_motion' => $strategy['sales_motion'] ?? '',
                'deal_movement_strategy' => $strategy['deal_movement_strategy'] ?? '',
                'outreach_posture' => $strategy['outreach_posture'] ?? '',
                'positioning_notes' => $strategy['positioning_notes'] ?? '',
            ]),
            'idea_validation' => $this->normalizeSnapshot([
                'value_proposition' => $ideaValidation['value_proposition'] ?? '',
                'target_market' => $ideaValidation['target_market'] ?? '',
                'pain_points' => $ideaValidation['pain_points'] ?? '',
                'assumptions_to_test' => $ideaValidation['assumptions_to_test'] ?? '',
                'competitors' => $ideaValidation['competitors'] ?? '',
                'differentiator' => $ideaValidation['differentiator'] ?? '',
            ]),
        ];

        $missingRequirements = [];
        foreach ($sections as $sectionKey => $section) {
            foreach ($section['missing_fields'] as $missingField) {
                $missingRequirements[] = [
                    'section' => $sectionKey,
                    'field' => $missingField['field'],
                    'label' => $missingField['label'],
                    'message' => 'Add ' . strtolower($missingField['label']) . '.',
                ];
            }
        }

        $contextHash = hash(
            'sha256',
            json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        return [
            'is_ready' => empty($missingRequirements),
            'sections' => $sections,
            'missing_requirements' => $missingRequirements,
            'context_snapshot' => $snapshot,
            'context_hash' => $contextHash,
        ];
    }

    public function buildContextBundle(int $userId, string $promptKey = 'email_pack_generation', ?array $learningBlock = null): array
    {
        $readiness = $this->getReadiness($userId);
        $snapshot = $readiness['context_snapshot'];
        $blocks = [
            [
                'key' => 'company_profile',
                'label' => 'Company Profile',
                'content' => $this->buildTextBlock('COMPANY PROFILE', $snapshot['company_profile']),
                'data' => $snapshot['company_profile'],
            ],
            [
                'key' => 'strategy_profile',
                'label' => 'User Strategy Profile',
                'content' => $this->buildTextBlock('USER STRATEGY PROFILE', $snapshot['strategy_profile']),
                'data' => $snapshot['strategy_profile'],
            ],
            [
                'key' => 'idea_validation',
                'label' => 'Idea Validation',
                'content' => $this->buildTextBlock('IDEA VALIDATION', $snapshot['idea_validation']),
                'data' => $snapshot['idea_validation'],
            ],
        ];

        if ($learningBlock !== null && $learningBlock !== []) {
            $blocks[] = $learningBlock;
        }

        return [
            'surface' => 'smart_templates',
            'prompt_key' => $promptKey,
            'bundle_quality' => [
                'is_ready' => $readiness['is_ready'],
                'missing_sections' => array_values(array_keys(array_filter(
                    $readiness['sections'],
                    static fn(array $section): bool => !$section['is_ready']
                ))),
                'missing_requirements' => $readiness['missing_requirements'],
            ],
            'blocks' => $blocks,
            'context_snapshot' => $snapshot,
            'context_hash' => $readiness['context_hash'],
        ];
    }

    private function evaluateSection(array $requirements, array $source): array
    {
        $missing = [];
        $completed = [];

        foreach ($requirements as $requirement) {
            $matchedField = null;
            foreach ($requirement['fields'] as $field) {
                $value = trim((string) ($source[$field] ?? ''));
                if ($value !== '') {
                    $matchedField = $field;
                    break;
                }
            }

            if ($matchedField === null) {
                $missing[] = [
                    'field' => $requirement['fields'][0],
                    'label' => $requirement['label'],
                ];
                continue;
            }

            $completed[] = [
                'field' => $matchedField,
                'label' => $requirement['label'],
            ];
        }

        return [
            'is_ready' => empty($missing),
            'missing_fields' => $missing,
            'completed_fields' => $completed,
        ];
    }

    private function normalizeSnapshot(array $source): array
    {
        $normalized = [];
        foreach ($source as $key => $value) {
            $normalized[$key] = trim((string) $value);
        }

        return $normalized;
    }

    private function buildTextBlock(string $title, array $data): string
    {
        $lines = [];
        foreach ($data as $field => $value) {
            if ($value === '') {
                continue;
            }

            $lines[] = $this->humanizeField($field) . ': ' . $value;
        }

        return $title . ":\n" . implode("\n", $lines);
    }

    private function humanizeField(string $field): string
    {
        return ucwords(str_replace('_', ' ', $field));
    }
}
