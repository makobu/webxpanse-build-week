<?php

namespace CRM\Services;

use CRM\Database;

class EmailTemplateMatcherService
{
    private WorkspaceScopeService $workspaceScope;

    public function __construct(?WorkspaceScopeService $workspaceScope = null)
    {
        $this->workspaceScope = $workspaceScope ?? new WorkspaceScopeService();
    }

    /**
     * @return array<string,mixed>
     */
    public function matchForWorkflowAction(array $action, array $context = []): array
    {
        $query = $this->normalizeQuery(array_merge(
            (array) ($action['template_query'] ?? []),
            (array) ($context['template_query'] ?? [])
        ));

        if ($query === []) {
            return $this->emptyMatch('No template query was provided.');
        }

        $templates = $this->loadTemplates();
        if ($templates === []) {
            return $this->emptyMatch('No active email templates are available.');
        }

        $matches = [];
        foreach ($templates as $template) {
            $score = $this->scoreTemplate($template, $query);
            $matches[] = [
                'template' => $template,
                'score' => $score,
                'explanation' => $this->explainMatch($template, $query, $score),
            ];
        }

        usort($matches, static function (array $left, array $right): int {
            return ($right['score'] <=> $left['score'])
                ?: ((int) ($left['template']['is_library'] ?? 0) <=> (int) ($right['template']['is_library'] ?? 0))
                ?: strcmp((string) ($left['template']['name'] ?? ''), (string) ($right['template']['name'] ?? ''));
        });

        $best = $matches[0] ?? null;
        if (!$best || (int) $best['score'] < 45) {
            return $this->emptyMatch('No active template matched the workflow intent strongly enough.');
        }

        $template = $best['template'];
        $score = (int) $best['score'];

        return [
            'template_id' => (int) ($template['id'] ?? 0),
            'template_name' => (string) ($template['name'] ?? ''),
            'score' => $score,
            'confidence' => $this->confidenceForScore($score),
            'reason' => (string) ($best['explanation']['reason'] ?? 'Matched from workflow template query.'),
            'fallback_used' => $score < 70,
            'query' => $query,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function explainMatch(array $template, array $query, int $score): array
    {
        $metadata = $this->decodeJson($template['match_metadata_json'] ?? null);
        $tags = $this->decodeList($template['tags'] ?? null);
        $reasons = [];

        if ($this->matchesOne($query['preferred_template_key'] ?? '', [$template['template_key'] ?? '', $template['slug'] ?? ''])) {
            $reasons[] = 'preferred template key';
        }
        if ($this->matchesOne($query['purpose'] ?? '', [$template['purpose'] ?? '', $template['category'] ?? '', ...$this->listFromMetadata($metadata, 'purposes')])) {
            $reasons[] = 'purpose';
        }
        if ($this->matchesOne($query['intent_key'] ?? '', [$template['template_key'] ?? '', ...$this->listFromMetadata($metadata, 'workflow_intents')])) {
            $reasons[] = 'workflow intent';
        }
        if ($this->matchesOne($query['lifecycle_stage'] ?? '', $this->listFromMetadata($metadata, 'lifecycle_stages'))) {
            $reasons[] = 'lifecycle stage';
        }
        if ($this->matchesOne($query['tone'] ?? '', $this->listFromMetadata($metadata, 'tones'))) {
            $reasons[] = 'tone';
        }
        if ($this->intersection($query['tags'] ?? [], $tags) !== []) {
            $reasons[] = 'tags';
        }

        $reason = $reasons !== []
            ? 'Matched on ' . implode(', ', array_slice($reasons, 0, 4)) . '.'
            : 'Best available template for the requested workflow context.';

        return [
            'score' => $score,
            'confidence' => $this->confidenceForScore($score),
            'reason' => $reason,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function loadTemplates(): array
    {
        $workspaceId = $this->workspaceScope->requireActiveWorkspaceId();

        return Database::query(
            "SELECT id, workspace_id, name, slug, subject, body_text, body_html, category, variables,
                    is_active, is_library, description, tags, industry, purpose, usage_count,
                    is_ai_generated, template_key, match_metadata_json
             FROM email_templates
             WHERE is_active = 1
               AND workspace_id = ?
               AND is_library = 0
             ORDER BY CASE WHEN workspace_id = ? AND is_library = 0 THEN 0 ELSE 1 END,
                      is_ai_generated DESC,
                      usage_count DESC,
                      name ASC",
            [$workspaceId, $workspaceId]
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function normalizeQuery(array $query): array
    {
        $normalized = [];
        foreach ([
            'intent_key',
            'preferred_template_key',
            'purpose',
            'category',
            'tone',
            'lifecycle_stage',
            'audience',
            'industry',
        ] as $key) {
            $value = $this->normalizeToken((string) ($query[$key] ?? ''));
            if ($value !== '') {
                $normalized[$key] = $value;
            }
        }

        foreach (['required_variables', 'tags'] as $key) {
            $values = array_values(array_filter(array_map(
                fn($value): string => $this->normalizeToken((string) $value),
                (array) ($query[$key] ?? [])
            )));
            if ($values !== []) {
                $normalized[$key] = $values;
            }
        }

        return $normalized;
    }

    private function scoreTemplate(array $template, array $query): int
    {
        $metadata = $this->decodeJson($template['match_metadata_json'] ?? null);
        $tags = $this->decodeList($template['tags'] ?? null);
        $variables = $this->decodeList($template['variables'] ?? null);
        $score = 0;

        if ((int) ($template['is_library'] ?? 0) === 0) {
            $score += 20;
        }

        if ($this->matchesOne($query['preferred_template_key'] ?? '', [$template['template_key'] ?? '', $template['slug'] ?? ''])) {
            $score += 55;
        }

        if ($this->matchesOne($query['intent_key'] ?? '', [$template['template_key'] ?? '', ...$this->listFromMetadata($metadata, 'workflow_intents')])) {
            $score += 40;
        } elseif (!empty($template['is_ai_generated']) && !empty($query['intent_key'])) {
            $score -= 10;
        }

        if ($this->matchesOne($query['purpose'] ?? '', [$template['purpose'] ?? '', $template['category'] ?? '', ...$this->listFromMetadata($metadata, 'purposes')])) {
            $score += 34;
        }
        if ($this->matchesOne($query['category'] ?? '', [$template['category'] ?? '', $template['purpose'] ?? ''])) {
            $score += 18;
        }
        if ($this->matchesOne($query['lifecycle_stage'] ?? '', $this->listFromMetadata($metadata, 'lifecycle_stages'))) {
            $score += 22;
        }
        if ($this->matchesOne($query['audience'] ?? '', $this->listFromMetadata($metadata, 'audiences'))) {
            $score += 16;
        }
        if ($this->matchesOne($query['tone'] ?? '', $this->listFromMetadata($metadata, 'tones'))) {
            $score += 16;
        }
        if ($this->matchesOne($query['industry'] ?? '', [$template['industry'] ?? ''])) {
            $score += 10;
        }

        $score += count($this->intersection((array) ($query['tags'] ?? []), $tags)) * 8;

        $requiredVariables = (array) ($query['required_variables'] ?? []);
        $matchedVariables = $this->intersection($requiredVariables, $variables);
        $score += count($matchedVariables) * 3;
        if ($requiredVariables !== [] && count($matchedVariables) === count($requiredVariables)) {
            $score += 10;
        }

        $queryTerms = array_filter([
            $query['intent_key'] ?? '',
            $query['purpose'] ?? '',
            $query['category'] ?? '',
            $query['audience'] ?? '',
            $query['lifecycle_stage'] ?? '',
        ]);
        if ($this->intersection($queryTerms, $this->listFromMetadata($metadata, 'disqualifiers')) !== []) {
            $score -= 60;
        }

        return max(0, $score);
    }

    private function confidenceForScore(int $score): string
    {
        if ($score >= 110) {
            return 'high';
        }

        if ($score >= 70) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyMatch(string $reason): array
    {
        return [
            'template_id' => 0,
            'template_name' => '',
            'score' => 0,
            'confidence' => 'none',
            'reason' => $reason,
            'fallback_used' => true,
            'query' => [],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode((string) ($value ?? ''), true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<int,string>
     */
    private function decodeList(mixed $value): array
    {
        $decoded = $this->decodeJson($value);
        return array_values(array_filter(array_map(
            fn($item): string => $this->normalizeToken((string) $item),
            $decoded
        )));
    }

    /**
     * @return array<int,string>
     */
    private function listFromMetadata(array $metadata, string $key): array
    {
        return array_values(array_filter(array_map(
            fn($item): string => $this->normalizeToken((string) $item),
            (array) ($metadata[$key] ?? [])
        )));
    }

    private function matchesOne(mixed $needle, array $haystack): bool
    {
        $needle = $this->normalizeToken((string) $needle);
        if ($needle === '') {
            return false;
        }

        foreach ($haystack as $item) {
            $token = $this->normalizeToken((string) $item);
            if ($token === '') {
                continue;
            }
            if ($token === $needle || str_contains($token, $needle) || str_contains($needle, $token)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int,string> $left
     * @param array<int,string> $right
     * @return array<int,string>
     */
    private function intersection(array $left, array $right): array
    {
        $rightLookup = array_flip(array_map(fn($item): string => $this->normalizeToken((string) $item), $right));
        $matches = [];
        foreach ($left as $item) {
            $token = $this->normalizeToken((string) $item);
            if ($token !== '' && isset($rightLookup[$token])) {
                $matches[] = $token;
            }
        }

        return array_values(array_unique($matches));
    }

    private function normalizeToken(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? $value;
        return trim($value, '_');
    }
}
