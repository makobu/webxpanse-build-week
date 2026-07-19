<?php

namespace CRM\Services;

use CRM\Database;

class AIPromptRegistryService
{
    private AIWorkspaceScopeService $workspaceScope;

    public function __construct(?AIWorkspaceScopeService $workspaceScope = null)
    {
        $this->workspaceScope = $workspaceScope ?? new AIWorkspaceScopeService();
    }

    public function getActivePrompt(string $surface, string $promptKey): ?array
    {
        if (!$this->tableExists()) {
            return $this->getBuiltInDefault($surface, $promptKey);
        }

        $workspaceId = $this->workspaceScope->requireWorkspaceId();

        $row = Database::queryOne(
            "SELECT *
             FROM ai_prompt_registry
             WHERE (workspace_id = ? OR workspace_id IS NULL)
               AND surface = ?
               AND prompt_key = ?
               AND status = 'active'
             ORDER BY CASE WHEN workspace_id = ? THEN 0 ELSE 1 END, version DESC, id DESC
             LIMIT 1",
            [$workspaceId, $surface, $promptKey, $workspaceId]
        );

        if (!$row) {
            return $this->getBuiltInDefault($surface, $promptKey);
        }

        return $this->normalizeRow($row);
    }

    public function registerPrompt(array $data): int
    {
        if (!$this->tableExists()) {
            return 0;
        }

        $surface = trim((string) ($data['surface'] ?? ''));
        $promptKey = trim((string) ($data['prompt_key'] ?? ''));
        $status = trim((string) ($data['status'] ?? 'draft'));
        $systemPrompt = trim((string) ($data['system_prompt_text'] ?? ''));
        $instruction = trim((string) ($data['instruction_text'] ?? ''));
        $workspaceId = $this->workspaceScope->requireWorkspaceId();

        if ($surface === '' || $promptKey === '' || $systemPrompt === '' || $instruction === '') {
            return 0;
        }

        $versionRow = Database::queryOne(
            "SELECT MAX(version) AS max_version
             FROM ai_prompt_registry
             WHERE (workspace_id = ? OR workspace_id IS NULL)
               AND surface = ? AND prompt_key = ?",
            [$workspaceId, $surface, $promptKey]
        );
        $nextVersion = ((int) ($versionRow['max_version'] ?? 0)) + 1;

        if ($status === 'active') {
            Database::execute(
                "UPDATE ai_prompt_registry
                 SET status = 'deprecated'
                 WHERE workspace_id = ? AND surface = ? AND prompt_key = ? AND status = 'active'",
                [$workspaceId, $surface, $promptKey]
            );
        }

        Database::execute(
            "INSERT INTO ai_prompt_registry
                (workspace_id, surface, prompt_key, version, status, system_prompt_text, instruction_text, output_contract_json, metadata_json, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $workspaceId,
                $surface,
                $promptKey,
                $nextVersion,
                in_array($status, ['active', 'draft', 'deprecated'], true) ? $status : 'draft',
                $systemPrompt,
                $instruction,
                json_encode($data['output_contract_json'] ?? null),
                json_encode($data['metadata_json'] ?? []),
                $data['created_by'] ?? null,
            ]
        );

        return (int) Database::lastInsertId();
    }

    public function activatePromptVersion(string $surface, string $promptKey, int $version): bool
    {
        if (!$this->tableExists() || $version <= 0) {
            return false;
        }

        $workspaceId = $this->workspaceScope->requireWorkspaceId();

        $row = Database::queryOne(
            "SELECT id
             FROM ai_prompt_registry
             WHERE workspace_id = ?
               AND surface = ?
               AND prompt_key = ?
               AND version = ?",
            [$workspaceId, $surface, $promptKey, $version]
        );
        if (!$row) {
            return false;
        }

        Database::execute(
            "UPDATE ai_prompt_registry
             SET status = 'deprecated'
             WHERE workspace_id = ? AND surface = ? AND prompt_key = ? AND status = 'active'",
            [$workspaceId, $surface, $promptKey]
        );
        Database::execute(
            "UPDATE ai_prompt_registry
             SET status = 'active'
             WHERE workspace_id = ? AND surface = ? AND prompt_key = ? AND version = ?",
            [$workspaceId, $surface, $promptKey, $version]
        );

        return true;
    }

    public function getPromptHistory(string $surface, string $promptKey): array
    {
        if (!$this->tableExists()) {
            $default = $this->getBuiltInDefault($surface, $promptKey);
            return $default ? [$default] : [];
        }

        $workspaceId = $this->workspaceScope->requireWorkspaceId();

        $rows = Database::query(
            "SELECT *
             FROM ai_prompt_registry
             WHERE (workspace_id = ? OR workspace_id IS NULL)
               AND surface = ?
               AND prompt_key = ?
             ORDER BY CASE WHEN workspace_id = ? THEN 0 ELSE 1 END, version DESC, id DESC",
            [$workspaceId, $surface, $promptKey, $workspaceId]
        );

        if (!$rows) {
            $default = $this->getBuiltInDefault($surface, $promptKey);
            return $default ? [$default] : [];
        }

        return array_map(fn(array $row): array => $this->normalizeRow($row), $rows);
    }

    public function getPromptVersion(string $surface, string $promptKey, int $version): ?array
    {
        if ($version <= 0) {
            return $this->getActivePrompt($surface, $promptKey);
        }

        if (!$this->tableExists()) {
            return $this->getBuiltInDefault($surface, $promptKey);
        }

        $workspaceId = $this->workspaceScope->requireWorkspaceId();

        $row = Database::queryOne(
            "SELECT *
             FROM ai_prompt_registry
             WHERE (workspace_id = ? OR workspace_id IS NULL)
               AND surface = ?
               AND prompt_key = ?
               AND version = ?
             ORDER BY CASE WHEN workspace_id = ? THEN 0 ELSE 1 END
             LIMIT 1",
            [$workspaceId, $surface, $promptKey, $version, $workspaceId]
        );

        if (!$row) {
            return $this->getBuiltInDefault($surface, $promptKey);
        }

        return $this->normalizeRow($row);
    }

    public function getAllActivePrompts(): array
    {
        if (!$this->tableExists()) {
            return [];
        }

        $workspaceId = $this->workspaceScope->requireWorkspaceId();

        $rows = Database::query(
            "SELECT *
             FROM ai_prompt_registry
             WHERE (workspace_id = ? OR workspace_id IS NULL)
               AND status = 'active'
             ORDER BY surface ASC, prompt_key ASC, CASE WHEN workspace_id = ? THEN 0 ELSE 1 END, version DESC",
            [$workspaceId, $workspaceId]
        );

        $prompts = [];
        foreach ($rows as $row) {
            $key = (string) ($row['surface'] ?? '') . ':' . (string) ($row['prompt_key'] ?? '');
            if (isset($prompts[$key])) {
                continue;
            }
            $prompts[$key] = $this->normalizeRow($row);
        }

        return array_values($prompts);
    }

    private function normalizeRow(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'workspace_id' => isset($row['workspace_id']) ? (int) $row['workspace_id'] : null,
            'surface' => (string) ($row['surface'] ?? ''),
            'prompt_key' => (string) ($row['prompt_key'] ?? ''),
            'version' => (int) ($row['version'] ?? 0),
            'status' => (string) ($row['status'] ?? 'draft'),
            'system_prompt_text' => (string) ($row['system_prompt_text'] ?? ''),
            'instruction_text' => (string) ($row['instruction_text'] ?? ''),
            'output_contract_json' => $this->decodeJson($row['output_contract_json'] ?? null),
            'metadata_json' => $this->decodeJson($row['metadata_json'] ?? null),
            'created_by' => isset($row['created_by']) ? (int) $row['created_by'] : null,
            'created_at' => (string) ($row['created_at'] ?? ''),
        ];
    }

    private function getBuiltInDefault(string $surface, string $promptKey): ?array
    {
        $defaults = [
            'coach:coach_recommendations' => [
                'surface' => 'coach',
                'prompt_key' => 'coach_recommendations',
                'version' => 0,
                'status' => 'active',
                'system_prompt_text' => 'Legacy in-code coach prompt fallback. AI Coach is an orchestrator over ready installed workspace skills, not standalone generic business advice.',
                'instruction_text' => 'Use the built-in coach prompt fallback. Business recommendations and suggested tasks require ready installed skill contracts or explicit CRM operational evidence; otherwise recommend Marketplace/setup.',
                'output_contract_json' => ['type' => 'legacy_inline'],
                'metadata_json' => ['fallback' => true, 'legacy_inline' => true],
                'created_by' => null,
                'created_at' => '',
            ],
            'coach:coach_recommendations_lean_canvas' => [
                'surface' => 'coach',
                'prompt_key' => 'coach_recommendations_lean_canvas',
                'version' => 0,
                'status' => 'active',
                'system_prompt_text' => 'Legacy in-code Lean Canvas coach prompt fallback. Lean Canvas mode requires a ready Lean Canvas skill contract.',
                'instruction_text' => 'Use the built-in Lean Canvas coach prompt fallback. Business-model recommendations require the ready Lean Canvas contract; other business domains require their own ready skills.',
                'output_contract_json' => ['type' => 'legacy_inline'],
                'metadata_json' => ['fallback' => true, 'legacy_inline' => true],
                'created_by' => null,
                'created_at' => '',
            ],
            'clarity_chat:clarity_question_answer' => [
                'surface' => 'clarity_chat',
                'prompt_key' => 'clarity_question_answer',
                'version' => 0,
                'status' => 'active',
                'system_prompt_text' => 'Legacy in-code Clarity prompt fallback. Product help, CRM operations, and evidence-grounded explanations are allowed; business advice requires a ready installed skill contract.',
                'instruction_text' => 'Use the built-in website assistant prompt fallback. Analyze supplied CRM evidence privately, then answer why/how questions with the direct conclusion in natural language. Do not expose processing steps, evidence categories, raw field names, flags, context blocks, or audit-style headings. If evidence is limited, say so naturally in one sentence. If no ready installed skill covers a business-advice domain, block with a Marketplace/setup recommendation.',
                'output_contract_json' => ['type' => 'legacy_inline'],
                'metadata_json' => ['fallback' => true, 'legacy_inline' => true],
                'created_by' => null,
                'created_at' => '',
            ],
            'clarity_chat:clarity_question_answer_lean_canvas' => [
                'surface' => 'clarity_chat',
                'prompt_key' => 'clarity_question_answer_lean_canvas',
                'version' => 0,
                'status' => 'active',
                'system_prompt_text' => 'Legacy in-code Lean Canvas Clarity prompt fallback. Evidence-grounded explanations are allowed; Lean Canvas advice requires a ready Lean Canvas skill contract.',
                'instruction_text' => 'Use the built-in Lean Canvas website assistant prompt fallback. Analyze supplied facts and calculations privately, then answer why/how questions with the direct conclusion in natural language. Do not expose processing steps, evidence categories, raw field names, flags, context blocks, or audit-style headings. If evidence is limited, say so naturally in one sentence. Product/help and CRM operations remain allowed; unsupported business advice must become a Marketplace/setup recommendation.',
                'output_contract_json' => ['type' => 'legacy_inline'],
                'metadata_json' => ['fallback' => true, 'legacy_inline' => true],
                'created_by' => null,
                'created_at' => '',
            ],
            'startup_journey:journey_report' => [
                'surface' => 'startup_journey',
                'prompt_key' => 'journey_report',
                'version' => 0,
                'status' => 'active',
                'system_prompt_text' => 'Legacy in-code Clarity Journey report prompt fallback.',
                'instruction_text' => 'Return strict JSON with executive_summary and SWOT quadrants. Do not invent evidence outside the supplied Journey, CRM, finance, and Founder Loop context.',
                'output_contract_json' => [
                    'type' => 'object',
                    'required' => ['executive_summary', 'swot'],
                    'properties' => [
                        'executive_summary' => ['type' => 'string'],
                        'swot' => [
                            'type' => 'object',
                            'required' => ['strengths', 'weaknesses', 'opportunities', 'threats'],
                        ],
                    ],
                ],
                'metadata_json' => ['fallback' => true, 'legacy_inline' => true],
                'created_by' => null,
                'created_at' => '',
            ],
            'commercial_assistant:assistant_commercial_reply' => [
                'surface' => 'commercial_assistant',
                'prompt_key' => 'assistant_commercial_reply',
                'version' => 0,
                'status' => 'active',
                'system_prompt_text' => 'Legacy commercial reply prompt fallback.',
                'instruction_text' => 'Use the built-in assistant commercial reply prompt fallback.',
                'output_contract_json' => ['type' => 'legacy_inline'],
                'metadata_json' => ['fallback' => true, 'legacy_inline' => true],
                'created_by' => null,
                'created_at' => '',
            ],
            'assistant:assistant_question' => [
                'surface' => 'assistant',
                'prompt_key' => 'assistant_question',
                'version' => 0,
                'status' => 'active',
                'system_prompt_text' => 'Legacy assistant question prompt fallback.',
                'instruction_text' => 'Use the built-in assistant question prompt fallback.',
                'output_contract_json' => ['type' => 'legacy_inline'],
                'metadata_json' => ['fallback' => true, 'legacy_inline' => true],
                'created_by' => null,
                'created_at' => '',
            ],
            'assistant:assistant_customer_reply_goal' => [
                'surface' => 'assistant',
                'prompt_key' => 'assistant_customer_reply_goal',
                'version' => 0,
                'status' => 'active',
                'system_prompt_text' => 'Legacy assistant customer reply goal prompt fallback.',
                'instruction_text' => 'Use the built-in assistant customer reply goal prompt fallback.',
                'output_contract_json' => ['type' => 'legacy_inline'],
                'metadata_json' => ['fallback' => true, 'legacy_inline' => true],
                'created_by' => null,
                'created_at' => '',
            ],
            'assistant:assistant_change_explanation' => [
                'surface' => 'assistant',
                'prompt_key' => 'assistant_change_explanation',
                'version' => 0,
                'status' => 'active',
                'system_prompt_text' => 'Legacy assistant change explanation prompt fallback.',
                'instruction_text' => 'Use the built-in assistant change explanation prompt fallback.',
                'output_contract_json' => ['type' => 'legacy_inline'],
                'metadata_json' => ['fallback' => true, 'legacy_inline' => true],
                'created_by' => null,
                'created_at' => '',
            ],
            'assistant:assistant_ambiguity_summary' => [
                'surface' => 'assistant',
                'prompt_key' => 'assistant_ambiguity_summary',
                'version' => 0,
                'status' => 'active',
                'system_prompt_text' => 'Legacy assistant ambiguity summary prompt fallback.',
                'instruction_text' => 'Use the built-in assistant ambiguity summary prompt fallback.',
                'output_contract_json' => ['type' => 'legacy_inline'],
                'metadata_json' => ['fallback' => true, 'legacy_inline' => true],
                'created_by' => null,
                'created_at' => '',
            ],
        ];

        return $defaults[$surface . ':' . $promptKey] ?? null;
    }

    private function decodeJson($value)
    {
        if (is_array($value) || $value === null) {
            return $value;
        }
        $decoded = json_decode((string) $value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    private function tableExists(): bool
    {
        try {
            return (bool) Database::queryOne(
                "SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_prompt_registry'"
            );
        } catch (\Throwable $e) {
            return false;
        }
    }
}
