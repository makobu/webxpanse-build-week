<?php
/**
 * Workflow Recommendation Service
 * AI-powered workflow recommendations based on CRM context
 */

namespace CRM\Modules;

use CRM\CacheManager;
use CRM\Database;
use CRM\Services\AIService;
use CRM\Services\EmailTemplateMatcherService;
use CRM\Services\WorkspaceScopeService;

class WorkflowRecommendationService
{
    private const CACHE_KEY_PREFIX = 'workflow_recommendations_';
    private const CACHE_TTL = 3600;

    private AIService $aiService;
    private WorkflowTemplates $templates;
    private AnalyticsDashboard $analytics;
    private CacheManager $cache;
    private WorkspaceScopeService $workspaceScope;
    private EmailTemplateMatcherService $templateMatcher;

    public function __construct()
    {
        $this->aiService = new AIService();
        $this->templates = new WorkflowTemplates();
        $this->analytics = new AnalyticsDashboard();
        $this->cache = new CacheManager();
        $this->workspaceScope = new WorkspaceScopeService();
        $this->templateMatcher = new EmailTemplateMatcherService();
    }

    /**
     * Get AI-powered workflow recommendations for user
     */
    public function getRecommendations(int $userId): array
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId();
        $cacheKey = self::CACHE_KEY_PREFIX . $workspaceId . '_' . $userId;
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $context = $this->buildContext($userId);
        $templates = $this->templates->getPublicTemplates();
        $deterministic = $this->buildDeterministicRecommendations($context, $templates);

        try {
            $response = $this->aiService->process('workflow_recommendations', [
                'context' => array_merge($context, [
                    'templates' => array_map(function ($t) {
                        return [
                            'id' => (int) $t['id'],
                            'name' => $t['name'] ?? '',
                            'category' => $t['category'] ?? '',
                            'description' => $t['description'] ?? '',
                        ];
                    }, $templates),
                ]),
            ]);

            $parsed = $this->parseRecommendationsResponse($response, $templates);
            $parsed = $this->mergeRecommendations($deterministic, $parsed);
            $this->cache->set($cacheKey, $parsed, self::CACHE_TTL);
            return $parsed;
        } catch (\Throwable $e) {
            error_log('WorkflowRecommendationService::getRecommendations error: ' . $e->getMessage());
            return $this->mergeRecommendations($deterministic, $this->getFallbackRecommendations($templates));
        }
    }

    /**
     * Get recommended templates with AI reasons
     */
    public function getRecommendedTemplates(int $userId): array
    {
        $data = $this->getRecommendations($userId);
        return $data['recommended_templates'] ?? [];
    }

    private function buildContext(int $userId): array
    {
        $today = $this->analytics->getRealTimeMetrics('today');
        $week = $this->analytics->getRealTimeMetrics('week');
        $month = $this->analytics->getRealTimeMetrics('month');

        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId();
        $workflowCount = (int) (Database::queryOne("SELECT COUNT(*) as count FROM workflows WHERE workspace_id = ? AND is_active = 1", [$workspaceId])['count'] ?? 0);
        $contactCount = (int) (Database::queryOne("SELECT COUNT(*) as count FROM contacts WHERE workspace_id = ?", [$workspaceId])['count'] ?? 0);
        $dealCount = (int) (Database::queryOne("SELECT COUNT(*) as count FROM deals WHERE workspace_id = ?", [$workspaceId])['count'] ?? 0);
        $dealStages = array_values(array_filter(array_map(
            static fn(array $row): string => strtolower((string) ($row['stage'] ?? '')),
            Database::query("SELECT DISTINCT stage FROM deals WHERE workspace_id = ? AND stage IS NOT NULL AND stage != ''", [$workspaceId])
        )));
        $emailTemplateCount = (int) (Database::queryOne(
            "SELECT COUNT(*) AS count FROM email_templates WHERE is_active = 1 AND workspace_id = ? AND is_library = 0",
            [$workspaceId]
        )['count'] ?? 0);

        $industry = 'general';
        try {
            $industryColumn = Database::columnExists('company_profile', 'company_industry')
                ? 'company_industry'
                : (Database::columnExists('company_profile', 'industry') ? 'industry' : '');
            $profile = $industryColumn !== '' ? Database::queryOne("SELECT {$industryColumn} AS industry FROM company_profile LIMIT 1") : null;
            if (!empty($profile['industry'])) {
                $industry = (string) $profile['industry'];
            }
        } catch (\Throwable $e) {
            // Table may not exist
        }

        return [
            'metrics' => [
                'leads_today' => $today['leads'] ?? 0,
                'emails_sent_week' => $week['emails_sent'] ?? 0,
                'form_submissions_month' => $month['form_submissions'] ?? 0,
            ],
            'feature_usage' => [
                'contact_count' => $contactCount,
                'deals_used' => $dealCount > 0,
                'workflows_used' => $workflowCount > 0,
                'workflow_count' => $workflowCount,
                'deal_count' => $dealCount,
                'deal_stages' => $dealStages,
                'email_template_count' => $emailTemplateCount,
            ],
            'industry' => $industry,
        ];
    }

    private function parseRecommendationsResponse(string $response, array $templates): array
    {
        $result = [
            'recommended_templates' => [],
            'recommended_workflows' => [],
            'ai_suggestions' => [],
            'popular_templates' => [],
        ];

        // Extract JSON from response (handle markdown code blocks)
        $json = preg_replace('/^```(?:json)?\s*|\s*```$/s', '', trim($response));
        $data = json_decode($json, true);
        if (!$data) {
            return $this->getFallbackRecommendations($templates);
        }

        $recommendations = $data['recommendations'] ?? [];
        $templateById = [];
        foreach ($templates as $t) {
            $templateById[(int) $t['id']] = $t;
        }

        foreach ($recommendations as $rec) {
            $templateId = (int) ($rec['template_id'] ?? 0);
            $template = $templateById[$templateId] ?? null;
            if ($template) {
                $result['recommended_templates'][] = [
                    'template' => $template,
                    'reason' => $rec['reason'] ?? 'Recommended for your CRM',
                    'impact' => $rec['impact'] ?? 'medium',
                    'effort' => $rec['effort'] ?? 'low',
                ];
            }
        }

        if (!empty($data['suggested_workflow'])) {
            $result['ai_suggestions'][] = $data['suggested_workflow'];
        }

        // Popular templates (by usage_count)
        $popular = array_slice(
            array_filter($templates, fn($t) => ($t['usage_count'] ?? 0) > 0),
            0,
            5
        );
        usort($popular, fn($a, $b) => ($b['usage_count'] ?? 0) <=> ($a['usage_count'] ?? 0));
        $result['popular_templates'] = array_slice($popular, 0, 5);

        return $result;
    }

    private function buildDeterministicRecommendations(array $context, array $templates): array
    {
        $featureUsage = (array) ($context['feature_usage'] ?? []);
        $contacts = (int) ($featureUsage['contact_count'] ?? 0);
        $deals = (int) ($featureUsage['deal_count'] ?? 0);
        $workflowCount = (int) ($featureUsage['workflow_count'] ?? 0);
        $dealStages = (array) ($featureUsage['deal_stages'] ?? []);

        $wanted = [];
        if ($workflowCount === 0 || $contacts > 0) {
            $wanted[] = ['new_lead_nurture', 'Lead Nurture Sequence', 'You have contacts and can benefit from a first-touch nurture workflow.', 'high'];
            $wanted[] = ['speed_to_lead', 'Speed-to-Lead Qualification Sprint', 'Fast first response helps new leads get routed and qualified.', 'high'];
        }
        if ($deals > 0 || in_array('proposal', $dealStages, true)) {
            $wanted[] = ['proposal_stall_recovery', 'Deal Stall Recovery Sequence', 'Proposal-stage deals need structured recovery before momentum fades.', 'high'];
            $wanted[] = ['deal_stage_follow_up', 'Deal Stage Follow-up', 'Deal stage movement should create a next step and a relevant follow-up.', 'medium'];
        }
        if ($contacts > 5) {
            $wanted[] = ['silent_lead_reengagement', 'Silent Lead Re-Engagement (30-60)', 'Quiet leads should get a low-pressure re-engagement path.', 'medium'];
        }

        $byName = [];
        foreach ($templates as $template) {
            $byName[(string) ($template['name'] ?? '')] = $template;
        }

        $recommended = [];
        foreach ($wanted as [$intentKey, $name, $reason, $impact]) {
            $template = $byName[$name] ?? $this->findTemplateByIntent($templates, $intentKey);
            if (!$template) {
                continue;
            }
            $recommended[] = $this->buildRecommendationItem($template, $reason, $impact, $intentKey);
            if (count($recommended) >= 5) {
                break;
            }
        }

        return [
            'recommended_templates' => $recommended,
            'recommended_workflows' => $recommended,
            'ai_suggestions' => [],
            'popular_templates' => [],
        ];
    }

    private function findTemplateByIntent(array $templates, string $intentKey): ?array
    {
        foreach ($templates as $template) {
            $metadata = json_decode((string) ($template['recipe_metadata_json'] ?? ''), true);
            if (is_array($metadata) && (string) ($metadata['intent_key'] ?? '') === $intentKey) {
                return $template;
            }
        }

        return null;
    }

    private function buildRecommendationItem(array $template, string $reason, string $impact, string $intentKey = ''): array
    {
        $metadata = json_decode((string) ($template['recipe_metadata_json'] ?? ''), true);
        $metadata = is_array($metadata) ? $metadata : [];
        $actionQuery = $this->firstSendEmailTemplateQuery($template);
        $match = $actionQuery !== []
            ? $this->templateMatcher->matchForWorkflowAction(['type' => 'send_email', 'template_query' => $actionQuery])
            : ['template_id' => 0, 'template_name' => '', 'confidence' => 'none', 'score' => 0, 'fallback_used' => true];

        return [
            'template' => $template,
            'template_id' => (int) ($template['id'] ?? 0),
            'name' => (string) ($template['name'] ?? 'Recommended workflow'),
            'category' => (string) ($template['category'] ?? ''),
            'intent_key' => $intentKey ?: (string) ($metadata['intent_key'] ?? ''),
            'reason' => $reason,
            'impact' => $impact,
            'effort' => 'low',
            'recipe_metadata' => $metadata,
            'template_match' => [
                'template_id' => (int) ($match['template_id'] ?? 0),
                'template_name' => (string) ($match['template_name'] ?? ''),
                'confidence' => (string) ($match['confidence'] ?? 'none'),
                'score' => (int) ($match['score'] ?? 0),
                'fallback_used' => !empty($match['fallback_used']),
            ],
        ];
    }

    private function firstSendEmailTemplateQuery(array $template): array
    {
        $actions = json_decode((string) ($template['actions'] ?? '[]'), true);
        if (!is_array($actions)) {
            return [];
        }

        foreach ($actions as $action) {
            if (($action['type'] ?? '') === 'send_email' && is_array($action['template_query'] ?? null)) {
                return $action['template_query'];
            }
        }

        $metadata = json_decode((string) ($template['recipe_metadata_json'] ?? ''), true);
        return is_array($metadata['recommended_template_query'] ?? null)
            ? $metadata['recommended_template_query']
            : [];
    }

    private function mergeRecommendations(array $deterministic, array $secondary): array
    {
        $mergedTemplates = [];
        foreach (array_merge($deterministic['recommended_templates'] ?? [], $secondary['recommended_templates'] ?? []) as $item) {
            $id = (int) (($item['template']['id'] ?? null) ?: ($item['template_id'] ?? 0));
            if ($id > 0 && !isset($mergedTemplates[$id])) {
                $mergedTemplates[$id] = $item;
            }
        }

        $mergedWorkflows = [];
        foreach (array_merge($deterministic['recommended_workflows'] ?? [], $secondary['recommended_workflows'] ?? []) as $item) {
            $id = (int) (($item['template']['id'] ?? null) ?: ($item['template_id'] ?? 0));
            if ($id > 0 && !isset($mergedWorkflows[$id])) {
                $mergedWorkflows[$id] = $item;
            }
        }

        return [
            'recommended_templates' => array_slice(array_values($mergedTemplates), 0, 5),
            'recommended_workflows' => array_slice(array_values($mergedWorkflows), 0, 5),
            'ai_suggestions' => array_values(array_merge($deterministic['ai_suggestions'] ?? [], $secondary['ai_suggestions'] ?? [])),
            'popular_templates' => $secondary['popular_templates'] ?? $deterministic['popular_templates'] ?? [],
        ];
    }

    private function getFallbackRecommendations(array $templates): array
    {
        $recommended = [];
        $priorityCategories = ['onboarding', 'nurturing', 'sales', 're-engagement'];
        foreach ($priorityCategories as $cat) {
            foreach ($templates as $t) {
                if (($t['category'] ?? '') === $cat) {
                    $recommended[] = [
                        'template' => $t,
                        'reason' => 'Common workflow for ' . $cat . ' automation',
                        'impact' => 'high',
                        'effort' => 'low',
                    ];
                    if (count($recommended) >= 5) {
                        break 2;
                    }
                }
            }
        }
        if (empty($recommended)) {
            $recommended = array_slice(array_map(fn($t) => [
                'template' => $t,
                'reason' => 'Pre-built workflow template',
                'impact' => 'medium',
                'effort' => 'low',
            ], $templates), 0, 5);
        }

        return [
            'recommended_templates' => $recommended,
            'recommended_workflows' => array_values(array_map(function (array $item): array {
                $template = (array) ($item['template'] ?? []);
                return $this->buildRecommendationItem($template, (string) ($item['reason'] ?? 'Pre-built workflow template'), (string) ($item['impact'] ?? 'medium'));
            }, $recommended)),
            'ai_suggestions' => [],
            'popular_templates' => array_slice(array_filter($templates, fn($t) => ($t['usage_count'] ?? 0) > 0), 0, 5),
        ];
    }
}
