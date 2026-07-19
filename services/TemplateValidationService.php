<?php

namespace CRM\Services;

use CRM\Database;
use PDO;

class TemplateValidationService
{
    /** @var array<int,string> */
    private const REQUIRED_PLATFORM_OPS_EMAIL_TEMPLATE_KEYS = [
        'owner_welcome_setup',
        'onboarding_recovery',
        'billing_follow_up',
        'failed_payment_review',
        'low_token_warning',
        'channel_setup_reminder',
        'workspace_suspension_notice',
        'setup_link_resend',
        'owner_problem_followup',
        'owner_support_resolution',
        'support_escalation',
    ];

    /** @var array<int,string> */
    private const GENERIC_DEFAULT_EMAIL_SLUGS = [
        'welcome',
        'follow_up',
        'thank_you',
        'product-announcement',
        'default-webxpanse-owner-welcome',
        'platform-workspace-payment-nudge',
        'platform-workspace-trial-final-conversion',
        'platform-workspace-trial-welcome',
    ];

    /** @var array<string,string> */
    private const DEMO_LANGUAGE_PATTERNS = [
        'example.test' => 'example.test',
        'demo.local.invalid' => 'demo.local.invalid',
        'lorem ipsum' => 'lorem ipsum',
        'test user' => 'Test User',
        'demo company' => 'Demo Company',
        'acme' => 'Acme',
    ];

    private SystemContextRegistryService $contextRegistry;
    private EmailLinkService $links;

    public function __construct(
        private ?PDO $pdo = null,
        ?SystemContextRegistryService $contextRegistry = null,
        ?EmailLinkService $links = null
    )
    {
        $this->contextRegistry = $contextRegistry ?? new SystemContextRegistryService();
        $this->links = $links ?? new EmailLinkService();
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function validate(array $options = []): array
    {
        $includeActiveEmailTemplates = (bool) ($options['include_active_email_templates'] ?? true);
        $includeActivePublicWorkflows = (bool) ($options['include_active_public_workflows'] ?? true);
        $findings = [];
        $summary = [
            'email_templates_checked' => 0,
            'workflow_templates_checked' => 0,
            'findings' => 0,
            'critical' => 0,
            'warning' => 0,
            'missing_required_templates' => 0,
            'inactive_required_templates' => 0,
            'by_rule' => [],
        ];

        $this->validateRequiredEmailTemplates($findings, $summary);
        $this->validateRequiredWorkflowTemplates($findings, $summary);

        foreach ($this->productionEmailTemplates($includeActiveEmailTemplates) as $row) {
            $summary['email_templates_checked']++;
            $this->validateEmailTemplate($row, $findings);
        }

        foreach ($this->productionWorkflowTemplates($includeActivePublicWorkflows) as $row) {
            $summary['workflow_templates_checked']++;
            $this->validateWorkflowTemplate($row, $findings);
        }

        foreach ($findings as $finding) {
            $severity = (string) ($finding['severity'] ?? 'warning');
            if (!isset($summary[$severity])) {
                $severity = 'warning';
            }
            $summary[$severity]++;
            $summary['findings']++;
            $rule = (string) ($finding['rule'] ?? 'unknown');
            $summary['by_rule'][$rule] = (int) ($summary['by_rule'][$rule] ?? 0) + 1;
        }

        return [
            'status' => $summary['critical'] > 0 ? 'critical' : ($summary['warning'] > 0 ? 'warning' : 'ok'),
            'summary' => $summary,
            'findings' => $findings,
            'checked_at' => date('c'),
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $findings
     * @param array<string,mixed> $summary
     */
    private function validateRequiredEmailTemplates(array &$findings, array &$summary): void
    {
        if (!$this->tableExists('email_templates') || !$this->columnExists('email_templates', 'slug')) {
            $this->addFinding($findings, 'critical', 'template_table_missing', 'The email_templates table is missing or incomplete.', [
                'template_type' => 'email',
                'table' => 'email_templates',
            ]);
            return;
        }

        foreach (self::REQUIRED_PLATFORM_OPS_EMAIL_TEMPLATE_KEYS as $key) {
            $conditions = ["slug = ?"];
            $params = ['platform-ops-' . $key];
            if ($this->columnExists('email_templates', 'workspace_id')) {
                $conditions[] = '(workspace_id = 1 OR workspace_id IS NULL)';
            }
            $row = $this->queryOne(
                'SELECT id, slug, is_active FROM email_templates WHERE ' . implode(' AND ', $conditions) . ' LIMIT 1',
                $params
            );
            if ($row === null) {
                $summary['missing_required_templates']++;
                $this->addFinding($findings, 'critical', 'missing_required_production_template', 'A required Platform Ops email template is missing.', [
                    'template_type' => 'email',
                    'template_key' => $key,
                    'slug' => 'platform-ops-' . $key,
                    'workspace_id' => 1,
                ]);
                continue;
            }
            if ($this->columnExists('email_templates', 'is_active') && empty($row['is_active'])) {
                $summary['inactive_required_templates']++;
                $this->addFinding($findings, 'critical', 'inactive_required_production_template', 'A required Platform Ops email template is inactive.', [
                    'template_type' => 'email',
                    'template_id' => (int) ($row['id'] ?? 0),
                    'template_key' => $key,
                    'slug' => (string) ($row['slug'] ?? ''),
                    'workspace_id' => 1,
                ]);
            }
        }
    }

    /**
     * @param array<int,array<string,mixed>> $findings
     * @param array<string,mixed> $summary
     */
    private function validateRequiredWorkflowTemplates(array &$findings, array &$summary): void
    {
        if (!$this->tableExists('workflow_templates') || !$this->columnExists('workflow_templates', 'template_key')) {
            $this->addFinding($findings, 'critical', 'template_table_missing', 'The workflow_templates table is missing or incomplete.', [
                'template_type' => 'workflow',
                'table' => 'workflow_templates',
            ]);
            return;
        }

        $baseline = $this->contextRegistry->defaultWorkspaceTemplateBaseline();
        $requiredKeys = array_values(array_filter(array_map('strval', (array) ($baseline['required_platform_ops_workflow_template_keys'] ?? []))));
        foreach ($requiredKeys as $key) {
            $row = $this->queryOne('SELECT id, template_key, is_active FROM workflow_templates WHERE template_key = ? LIMIT 1', [$key]);
            if ($row === null) {
                $summary['missing_required_templates']++;
                $this->addFinding($findings, 'critical', 'missing_required_production_template', 'A required Platform Ops workflow template is missing.', [
                    'template_type' => 'workflow',
                    'template_key' => $key,
                    'workspace_id' => 1,
                ]);
                continue;
            }
            if ($this->columnExists('workflow_templates', 'is_active') && empty($row['is_active'])) {
                $summary['inactive_required_templates']++;
                $this->addFinding($findings, 'critical', 'inactive_required_production_template', 'A required Platform Ops workflow template is inactive.', [
                    'template_type' => 'workflow',
                    'template_id' => (int) ($row['id'] ?? 0),
                    'template_key' => (string) ($row['template_key'] ?? ''),
                    'workspace_id' => 1,
                ]);
            }
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function productionEmailTemplates(bool $includeActiveEmailTemplates): array
    {
        if (!$this->tableExists('email_templates')) {
            return [];
        }

        $conditions = [];
        if ($this->columnExists('email_templates', 'slug')) {
            $conditions[] = "slug LIKE 'platform-ops-%'";
        }
        if ($this->columnExists('email_templates', 'category')) {
            $conditions[] = "category = 'platform_ops'";
        }
        if ($this->columnExists('email_templates', 'purpose')) {
            $conditions[] = "purpose = 'platform_ops'";
        }
        if ($this->columnExists('email_templates', 'tags')) {
            $conditions[] = "LOWER(COALESCE(tags, '')) LIKE '%platform_ops%'";
        }
        if ($includeActiveEmailTemplates && $this->columnExists('email_templates', 'is_active')) {
            $conditions[] = 'is_active = 1';
        }
        if ($conditions === []) {
            return [];
        }

        $select = $this->selectList('email_templates', [
            'id',
            'workspace_id',
            'slug',
            'template_key',
            'name',
            'subject',
            'body_html',
            'body_text',
            'variables',
            'is_active',
            'category',
            'purpose',
            'tags',
            'match_metadata_json',
            'seed_metadata_json',
        ]);

        return $this->query(
            'SELECT ' . $select . ' FROM email_templates WHERE ' . implode(' OR ', array_map(static fn(string $condition): string => '(' . $condition . ')', $conditions)) . ' ORDER BY id ASC'
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function productionWorkflowTemplates(bool $includeActivePublicWorkflows): array
    {
        if (!$this->tableExists('workflow_templates')) {
            return [];
        }

        $conditions = [];
        if ($this->columnExists('workflow_templates', 'category')) {
            $conditions[] = "category = 'platform_ops'";
        }
        if ($this->columnExists('workflow_templates', 'template_key')) {
            $baseline = $this->contextRegistry->defaultWorkspaceTemplateBaseline();
            $requiredKeys = array_values(array_filter(array_map('strval', (array) ($baseline['required_platform_ops_workflow_template_keys'] ?? []))));
            if ($requiredKeys !== []) {
                $quoted = implode(',', array_map(fn(string $key): string => $this->quote($key), $requiredKeys));
                $conditions[] = "template_key IN ({$quoted})";
            }
        }
        if ($includeActivePublicWorkflows && $this->columnExists('workflow_templates', 'is_active') && $this->columnExists('workflow_templates', 'is_public')) {
            $conditions[] = '(is_active = 1 AND is_public = 1)';
        }
        if ($conditions === []) {
            return [];
        }

        $select = $this->selectList('workflow_templates', [
            'id',
            'template_key',
            'name',
            'description',
            'category',
            'trigger_config',
            'conditions',
            'actions',
            'variables',
            'is_public',
            'is_active',
            'recipe_metadata_json',
            'seed_metadata_json',
        ]);

        return $this->query(
            'SELECT ' . $select . ' FROM workflow_templates WHERE ' . implode(' OR ', array_map(static fn(string $condition): string => '(' . $condition . ')', $conditions)) . ' ORDER BY id ASC'
        );
    }

    /**
     * @param array<string,mixed> $row
     * @param array<int,array<string,mixed>> $findings
     */
    private function validateEmailTemplate(array $row, array &$findings): void
    {
        $subject = trim((string) ($row['subject'] ?? ''));
        $bodyHtml = trim((string) ($row['body_html'] ?? ''));
        $bodyText = trim((string) ($row['body_text'] ?? ''));
        $body = trim(strip_tags($bodyHtml) . "\n" . $bodyText);
        $identity = $this->templateIdentity('email', $row);

        if ($subject === '') {
            $this->addFinding($findings, 'critical', 'empty_email_subject', 'Email template subject is empty.', $identity);
        }
        if ($body === '') {
            $this->addFinding($findings, 'critical', 'empty_email_body', 'Email template body is empty.', $identity);
        }

        $variables = $this->stringList($this->decodeJsonList($row['variables'] ?? null, $findings, $identity, 'variables'));
        $metadata = $this->decodeJsonObject($row['match_metadata_json'] ?? null, $findings, $identity, 'match_metadata_json');
        $matchingVariables = $this->stringList((array) ($metadata['required_variables'] ?? []));
        $required = $this->stringList((array) ($metadata['required_placeholders'] ?? $metadata['required_template_placeholders'] ?? []));
        $placeholders = $this->extractPlaceholders($subject . "\n" . $bodyHtml . "\n" . $bodyText);
        $declared = array_values(array_unique(array_merge($variables, $matchingVariables, $required)));

        foreach (array_diff($required, $placeholders) as $missing) {
            $this->addFinding($findings, 'critical', 'required_placeholder_missing', 'Required placeholder is missing from an email template.', $identity + [
                'placeholder' => $missing,
            ]);
        }

        if ($declared !== []) {
            foreach (array_diff($placeholders, $declared) as $placeholder) {
                $this->addFinding($findings, 'warning', 'undeclared_placeholder', 'Email template uses a placeholder that is not declared.', $identity + [
                    'placeholder' => $placeholder,
                ]);
            }
            foreach (array_diff($variables, $placeholders, $matchingVariables) as $unused) {
                $this->addFinding($findings, 'warning', 'declared_variable_unused', 'Email template declares a variable that is not used.', $identity + [
                    'placeholder' => $unused,
                ]);
            }
        }

        foreach ($this->demoLanguageMatches($subject . "\n" . $bodyHtml . "\n" . $bodyText . "\n" . (string) ($row['name'] ?? '')) as $match) {
            $this->addFinding($findings, 'warning', 'demo_language_detected', 'Template contains concrete demo/test placeholder language.', $identity + [
                'match' => $match,
            ]);
        }

        foreach ($this->links->inspectHtmlLinks($bodyHtml) as $link) {
            if (($link['status'] ?? '') === 'placeholder' || ($link['status'] ?? '') === 'ok') {
                continue;
            }
            if (($link['status'] ?? '') === 'broken') {
                $this->addFinding($findings, 'critical', 'broken_email_link', 'Template contains an empty, unresolved, or unsafe email link.', $identity + [
                    'href' => (string) ($link['href'] ?? ''),
                ]);
                continue;
            }
            $this->addFinding($findings, 'warning', 'email_link_requires_normalization', 'Template contains a local, legacy, or relative link that will be normalized before delivery.', $identity + [
                'href' => (string) ($link['href'] ?? ''),
                'normalized_href' => (string) ($link['normalized'] ?? ''),
            ]);
        }

        if (!empty($row['is_active']) && in_array((string) ($row['slug'] ?? ''), self::GENERIC_DEFAULT_EMAIL_SLUGS, true)) {
            $this->addFinding($findings, 'warning', 'active_generic_default_template', 'A generic default email template is active and should stay inactive in production.', $identity);
        }
    }

    /**
     * @param array<string,mixed> $row
     * @param array<int,array<string,mixed>> $findings
     */
    private function validateWorkflowTemplate(array $row, array &$findings): void
    {
        $identity = $this->templateIdentity('workflow', $row);
        $this->decodeJsonObject($row['trigger_config'] ?? null, $findings, $identity, 'trigger_config');
        $this->decodeJsonValue($row['conditions'] ?? null, $findings, $identity, 'conditions');
        $actions = $this->decodeJsonList($row['actions'] ?? null, $findings, $identity, 'actions');
        $variables = $this->stringList($this->decodeJsonList($row['variables'] ?? null, $findings, $identity, 'variables'));
        $this->decodeJsonObject($row['recipe_metadata_json'] ?? null, $findings, $identity, 'recipe_metadata_json');

        if ($actions === []) {
            $this->addFinding($findings, 'critical', 'workflow_actions_empty', 'Workflow template has no usable actions.', $identity);
            return;
        }

        foreach ($actions as $index => $action) {
            if (!is_array($action) || (string) ($action['type'] ?? '') !== 'send_email') {
                continue;
            }

            $actionIdentity = $identity + ['action_index' => $index];
            $subject = trim((string) ($action['subject'] ?? ''));
            $body = trim((string) ($action['body'] ?? $action['body_text'] ?? $action['body_html'] ?? ''));
            if ($subject === '') {
                $this->addFinding($findings, 'critical', 'workflow_send_email_subject_empty', 'Workflow send-email action subject is empty.', $actionIdentity);
            }
            if ($body === '') {
                $this->addFinding($findings, 'critical', 'workflow_send_email_body_empty', 'Workflow send-email action body is empty.', $actionIdentity);
            }

            $templateQuery = is_array($action['template_query'] ?? null) ? $action['template_query'] : [];
            $templateQueryVariables = $this->stringList((array) ($templateQuery['required_variables'] ?? []));
            $required = $this->stringList((array) ($templateQuery['required_placeholders'] ?? $templateQuery['required_template_placeholders'] ?? []));
            if ($required === [] && $this->isPlatformOpsWorkflow($row)) {
                $required = $templateQueryVariables;
            }
            $placeholders = $this->extractPlaceholders($subject . "\n" . $body);
            $declared = array_values(array_unique(array_merge($variables, $templateQueryVariables, $required)));

            foreach (array_diff($required, $placeholders) as $missing) {
                $this->addFinding($findings, 'critical', 'workflow_required_placeholder_missing', 'Workflow send-email action is missing a required placeholder.', $actionIdentity + [
                    'placeholder' => $missing,
                ]);
            }
            if ($declared !== []) {
                foreach (array_diff($placeholders, $declared) as $placeholder) {
                    $this->addFinding($findings, 'warning', 'workflow_undeclared_placeholder', 'Workflow send-email action uses a placeholder that is not declared.', $actionIdentity + [
                        'placeholder' => $placeholder,
                    ]);
                }
            }
            foreach ($this->demoLanguageMatches($subject . "\n" . $body . "\n" . (string) ($row['name'] ?? '')) as $match) {
                $this->addFinding($findings, 'warning', 'demo_language_detected', 'Workflow template contains concrete demo/test placeholder language.', $actionIdentity + [
                    'match' => $match,
                ]);
            }
        }
    }

    /**
     * @param array<int,array<string,mixed>> $findings
     * @param array<string,mixed> $context
     */
    private function addFinding(array &$findings, string $severity, string $rule, string $message, array $context): void
    {
        $findings[] = [
            'severity' => $severity,
            'rule' => $rule,
            'message' => $message,
        ] + $context;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function templateIdentity(string $type, array $row): array
    {
        return [
            'template_type' => $type,
            'template_id' => (int) ($row['id'] ?? 0),
            'workspace_id' => isset($row['workspace_id']) && $row['workspace_id'] !== null ? (int) $row['workspace_id'] : null,
            'template_key' => (string) ($row['template_key'] ?? ''),
            'slug' => (string) ($row['slug'] ?? ''),
            'name' => (string) ($row['name'] ?? ''),
        ];
    }

    /**
     * @param array<string,mixed> $row
     */
    private function isPlatformOpsWorkflow(array $row): bool
    {
        return (string) ($row['category'] ?? '') === 'platform_ops'
            || str_starts_with((string) ($row['template_key'] ?? ''), 'platform_ops_');
    }

    /**
     * @return array<int,string>
     */
    private function extractPlaceholders(string $text): array
    {
        preg_match_all('/\{([A-Za-z_][A-Za-z0-9_.-]*)\}/', $text, $matches);
        return array_values(array_unique(array_map('strval', $matches[1] ?? [])));
    }

    /**
     * @return array<int,string>
     */
    private function demoLanguageMatches(string $text): array
    {
        $lower = strtolower($text);
        $matches = [];
        foreach (self::DEMO_LANGUAGE_PATTERNS as $needle => $label) {
            if ($needle === 'acme') {
                if (preg_match('/\bacme\b/i', $text) === 1) {
                    $matches[] = $label;
                }
                continue;
            }
            if (str_contains($lower, $needle)) {
                $matches[] = $label;
            }
        }
        return array_values(array_unique($matches));
    }

    /**
     * @param array<int,array<string,mixed>> $findings
     * @param array<string,mixed> $identity
     * @return array<int,mixed>
     */
    private function decodeJsonList(mixed $value, array &$findings, array $identity, string $column): array
    {
        $decoded = $this->decodeJsonValue($value, $findings, $identity, $column);
        if ($decoded === []) {
            return [];
        }
        if (!is_array($decoded)) {
            return [];
        }
        return array_values($decoded);
    }

    /**
     * @param array<int,array<string,mixed>> $findings
     * @param array<string,mixed> $identity
     * @return array<string,mixed>
     */
    private function decodeJsonObject(mixed $value, array &$findings, array $identity, string $column): array
    {
        $decoded = $this->decodeJsonValue($value, $findings, $identity, $column);
        if (!is_array($decoded)) {
            return [];
        }
        return $decoded;
    }

    /**
     * @param array<int,array<string,mixed>> $findings
     * @param array<string,mixed> $identity
     */
    private function decodeJsonValue(mixed $value, array &$findings, array $identity, string $column): mixed
    {
        if (is_array($value)) {
            return $value;
        }
        if ($value === null || trim((string) $value) === '') {
            return [];
        }
        $decoded = json_decode((string) $value, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->addFinding($findings, 'critical', 'invalid_template_json', 'Template JSON is invalid.', $identity + [
                'column' => $column,
                'json_error' => json_last_error_msg(),
            ]);
            return [];
        }
        return $decoded;
    }

    /**
     * @param array<int|string,mixed> $items
     * @return array<int,string>
     */
    private function stringList(array $items): array
    {
        $strings = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                continue;
            }
            $value = trim((string) $item);
            if ($value !== '') {
                $strings[$value] = $value;
            }
        }
        return array_values($strings);
    }

    /**
     * @param array<int,string> $columns
     */
    private function selectList(string $table, array $columns): string
    {
        $select = [];
        foreach ($columns as $column) {
            $select[] = $this->columnExists($table, $column)
                ? $this->quoteIdentifier($column)
                : 'NULL AS ' . $this->quoteIdentifier($column);
        }
        return implode(', ', $select);
    }

    /**
     * @param array<int,mixed> $params
     * @return array<int,array<string,mixed>>
     */
    private function query(string $sql, array $params = []): array
    {
        if ($this->pdo instanceof PDO) {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
        return Database::query($sql, $params);
    }

    /**
     * @param array<int,mixed> $params
     * @return array<string,mixed>|null
     */
    private function queryOne(string $sql, array $params = []): ?array
    {
        if ($this->pdo instanceof PDO) {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        }
        return Database::queryOne($sql, $params);
    }

    private function tableExists(string $table): bool
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
            return false;
        }
        if ($this->pdo instanceof PDO) {
            $stmt = $this->pdo->query('SHOW TABLES LIKE ' . $this->quote($table));
            return (bool) ($stmt && $stmt->fetch(PDO::FETCH_NUM));
        }
        return Database::tableExists($table);
    }

    private function columnExists(string $table, string $column): bool
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
            return false;
        }
        if ($this->pdo instanceof PDO) {
            $stmt = $this->pdo->query('SHOW COLUMNS FROM ' . $this->quoteIdentifier($table) . ' WHERE Field = ' . $this->quote($column));
            return (bool) ($stmt && $stmt->fetch(PDO::FETCH_ASSOC));
        }
        return Database::columnExists($table, $column);
    }

    private function quote(string $value): string
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo->quote($value);
        }
        return Database::getInstance()->quote($value);
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
