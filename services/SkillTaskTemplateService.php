<?php

namespace CRM\Services;

class SkillTaskTemplateService
{
    private const RECOMMENDATION_BUCKETS = ['priorities', 'quick_wins', 'foundation_gaps', 'missing_features'];

    public function readyAdviceSkillContracts(array $operatingContext): array
    {
        return array_values(array_filter(
            (array) ($operatingContext['installed_skill_contracts'] ?? []),
            static function (array $contract): bool {
                if (empty($contract['readiness']['ready'])) {
                    return false;
                }
                if ((string) ($contract['module_type'] ?? 'skill') !== 'skill') {
                    return false;
                }
                if ((string) ($contract['boundary_policy'] ?? 'strict') === 'orchestrator_only') {
                    return false;
                }

                return (string) ($contract['key'] ?? '') !== WorkspaceSkillCatalogService::SKILL_AI_COACH;
            }
        ));
    }

    public function hasReadyAdviceSkill(array $operatingContext): bool
    {
        return $this->readyAdviceSkillContracts($operatingContext) !== [];
    }

    public function applyTaskTemplates(array $recommendations, array $operatingContext): array
    {
        $templates = $this->taskTemplates($operatingContext);
        if ($templates === []) {
            return $recommendations;
        }

        foreach (self::RECOMMENDATION_BUCKETS as $bucket) {
            foreach ((array) ($recommendations[$bucket] ?? []) as $index => $item) {
                if ($this->isMarketplaceSetupRecommendation($item)) {
                    continue;
                }
                if ($this->hasCrmOperationalEvidence($item)) {
                    continue;
                }

                $selected = $this->selectTemplatesForRecommendation($templates, $item);
                if ($selected === []) {
                    $recommendations[$bucket][$index]['suggested_subtasks'] = [];
                    $recommendations[$bucket][$index]['skill_task_template_source'] = [];
                    continue;
                }

                $recommendations[$bucket][$index]['suggested_subtasks'] = array_values(array_map(
                    static fn(array $template): string => trim($template['title'] . ($template['description'] !== '' ? ': ' . $template['description'] : '')),
                    $selected
                ));
                $recommendations[$bucket][$index]['skill_task_template_source'] = array_values(array_unique(array_map(
                    static fn(array $template): string => $template['skill_key'],
                    $selected
                )));
                $recommendations[$bucket][$index]['skill_task_template_target_metric_hints'] = array_values(array_unique(array_filter(array_map(
                    static fn(array $template): string => trim((string) ($template['target_metric_hint'] ?? '')),
                    $selected
                ))));
                $templateEvidenceTypes = [];
                foreach ($selected as $template) {
                    $templateEvidenceTypes = array_merge($templateEvidenceTypes, (array) ($template['completion_evidence_types'] ?? []));
                }
                $existingEvidenceTypes = array_values(array_filter(array_map('strval', (array) ($recommendations[$bucket][$index]['completion_evidence_types'] ?? []))));
                $recommendations[$bucket][$index]['completion_evidence_types'] = array_values(array_unique(array_merge(
                    array_values(array_filter(array_map('strval', $templateEvidenceTypes))),
                    $existingEvidenceTypes
                )));
            }
        }

        return $recommendations;
    }

    public function filterTaskCreationCandidates(array $items): array
    {
        return array_values(array_filter($items, fn(array $item): bool => $this->isTaskCreationAllowed($item)));
    }

    public function isTaskCreationAllowed(array $item): bool
    {
        if ($this->isMarketplaceSetupRecommendation($item) || $this->hasCrmOperationalEvidence($item)) {
            return true;
        }

        return !empty($item['skill_task_template_source']);
    }

    private function taskTemplates(array $operatingContext): array
    {
        $templates = [];
        foreach ($this->readyAdviceSkillContracts($operatingContext) as $contract) {
            foreach ((array) ($contract['task_templates'] ?? []) as $template) {
                $title = trim((string) ($template['title'] ?? ''));
                if ($title === '') {
                    continue;
                }
                $templates[] = [
                    'title' => $title,
                    'description' => trim((string) ($template['description'] ?? '')),
                    'default_priority' => $this->normalizePriority((string) ($template['default_priority'] ?? '')),
                    'due_offset' => trim((string) ($template['due_offset'] ?? '')),
                    'subtasks' => array_values(array_filter(array_map('strval', (array) ($template['subtasks'] ?? [])))),
                    'completion_evidence_types' => array_values(array_filter(array_map('strval', (array) ($template['completion_evidence_types'] ?? [])))),
                    'target_metric_hint' => trim((string) ($template['target_metric_hint'] ?? '')),
                    'skill_key' => (string) ($contract['key'] ?? ''),
                    'domains' => array_values(array_filter(array_map(
                        static fn($domain): string => strtolower(str_replace('_', ' ', trim((string) $domain))),
                        (array) ($contract['advice_domains'] ?? [])
                    ))),
                ];
            }
        }

        return $templates;
    }

    private function selectTemplatesForRecommendation(array $templates, array $item): array
    {
        $text = strtolower(trim((string) ($item['title'] ?? '') . ' ' . (string) ($item['reason'] ?? '') . ' ' . (string) ($item['category'] ?? '')));
        $scored = [];
        foreach ($templates as $template) {
            $score = 0;
            foreach ((array) ($template['domains'] ?? []) as $domain) {
                $domainText = strtolower((string) $domain);
                if ($domainText !== '' && str_contains($text, $domainText)) {
                    $score += 3;
                }
            }
            foreach (preg_split('/\s+/', strtolower((string) ($template['title'] ?? ''))) ?: [] as $term) {
                if (strlen($term) >= 5 && str_contains($text, $term)) {
                    $score++;
                }
            }
            $template['score'] = $score;
            $scored[] = $template;
        }

        usort($scored, static fn(array $left, array $right): int => ((int) ($right['score'] ?? 0)) <=> ((int) ($left['score'] ?? 0)));
        if ((int) ($scored[0]['score'] ?? 0) <= 0) {
            return [];
        }

        return array_slice($scored, 0, 3);
    }

    private function normalizePriority(string $priority): string
    {
        $priority = strtolower(trim($priority));
        return in_array($priority, ['low', 'medium', 'high', 'urgent'], true) ? $priority : '';
    }

    private function isMarketplaceSetupRecommendation(array $item): bool
    {
        return (string) ($item['source_recommendation_type'] ?? '') === 'marketplace_module'
            || trim((string) ($item['marketplace_skill_key'] ?? '')) !== '';
    }

    private function hasCrmOperationalEvidence(array $item): bool
    {
        if (!empty($item['target_id']) || !empty($item['source_goal_id'])) {
            return true;
        }
        $type = strtolower((string) ($item['source_recommendation_type'] ?? $item['category'] ?? ''));
        if (in_array($type, ['crm_operational', 'crm_operation', 'chat_task_command', 'marketplace_module'], true)) {
            return true;
        }
        $text = strtolower((string) ($item['title'] ?? '') . ' ' . (string) ($item['reason'] ?? ''));
        foreach (['invoice', 'deal', 'task', 'contact', 'workflow', 'pipeline', 'calendar', 'email assistant', 'sms channel', 'whatsapp assistant'] as $term) {
            if (str_contains($text, $term)) {
                return true;
            }
        }

        return false;
    }
}
