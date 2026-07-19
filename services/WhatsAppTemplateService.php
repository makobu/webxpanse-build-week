<?php

namespace CRM\Services;

use CRM\Database;

class WhatsAppTemplateService
{
    public function listTemplates(int $workspaceId, array $filters = []): array
    {
        if (!Database::tableExists('workspace_whatsapp_templates') || $workspaceId <= 0) {
            return [];
        }

        $where = ['workspace_id = ?'];
        $params = [$workspaceId];
        if (!empty($filters['status'])) {
            $where[] = 'status = ?';
            $params[] = (string) $filters['status'];
        }
        if (!empty($filters['category'])) {
            $where[] = 'category = ?';
            $params[] = (string) $filters['category'];
        }

        return Database::query(
            "SELECT *
             FROM workspace_whatsapp_templates
             WHERE " . implode(' AND ', $where) . "
             ORDER BY FIELD(status, 'draft','rejected','sync_failed','pending','submitted','approved','paused','disabled'),
                      updated_at DESC,
                      template_name ASC",
            $params
        );
    }

    public function approvedForSending(int $workspaceId): array
    {
        $local = $this->listTemplates($workspaceId, ['status' => 'approved']);
        if ($local === []) {
            return [];
        }

        return array_map([$this, 'formatForComposer'], $local);
    }

    public function createOrUpdateDraft(int $workspaceId, int $userId, array $data): array
    {
        if (!Database::tableExists('workspace_whatsapp_templates')) {
            throw new \RuntimeException('WhatsApp Template Center tables are missing. Run migrations first.');
        }

        $name = $this->normalizeTemplateName((string) ($data['template_name'] ?? $data['name'] ?? ''));
        $language = trim((string) ($data['language_code'] ?? 'en_US')) ?: 'en_US';
        $category = $this->normalizeCategory((string) ($data['category'] ?? 'utility'));
        $body = trim((string) ($data['body_text'] ?? $data['body'] ?? ''));
        if ($workspaceId <= 0 || $name === '' || $body === '') {
            throw new \RuntimeException('Template name and body are required.');
        }

        $connection = (new WhatsAppConnectionResolver())->resolve($workspaceId);
        $variables = $this->extractVariables($body . "\n" . (string) ($data['header_text'] ?? ''));
        $sampleValues = $this->normalizeJsonish($data['sample_values'] ?? $data['sample_values_json'] ?? []);
        $buttons = $this->normalizeJsonish($data['buttons'] ?? $data['buttons_json'] ?? []);

        Database::execute(
            "INSERT INTO workspace_whatsapp_templates
                (workspace_id, integration_id, connection_mode, template_name, language_code, category, status,
                 header_type, header_text, body_text, footer_text, buttons_json, variables_json, sample_values_json,
                 media_example_url, created_by_user_id, updated_by_user_id, metadata_json)
             VALUES (?, ?, ?, ?, ?, ?, 'draft', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                integration_id = VALUES(integration_id),
                connection_mode = VALUES(connection_mode),
                category = VALUES(category),
                status = CASE WHEN status = 'approved' THEN 'draft' ELSE status END,
                header_type = VALUES(header_type),
                header_text = VALUES(header_text),
                body_text = VALUES(body_text),
                footer_text = VALUES(footer_text),
                buttons_json = VALUES(buttons_json),
                variables_json = VALUES(variables_json),
                sample_values_json = VALUES(sample_values_json),
                media_example_url = VALUES(media_example_url),
                updated_by_user_id = VALUES(updated_by_user_id),
                metadata_json = VALUES(metadata_json),
                updated_at = NOW()",
            [
                $workspaceId,
                (int) ($connection['integration_id'] ?? 0) ?: null,
                (string) ($connection['connection_mode'] ?? WhatsAppConnectionResolver::MODE_SELF_MANAGED),
                $name,
                $language,
                $category,
                trim((string) ($data['header_type'] ?? 'TEXT')) ?: null,
                trim((string) ($data['header_text'] ?? '')) ?: null,
                $body,
                trim((string) ($data['footer_text'] ?? '')) ?: null,
                $buttons !== [] ? json_encode($buttons, JSON_UNESCAPED_SLASHES) : null,
                json_encode($variables, JSON_UNESCAPED_SLASHES),
                $sampleValues !== [] ? json_encode($sampleValues, JSON_UNESCAPED_SLASHES) : null,
                trim((string) ($data['media_example_url'] ?? '')) ?: null,
                $userId > 0 ? $userId : null,
                $userId > 0 ? $userId : null,
                json_encode(['source' => 'template_center', 'last_editor' => $userId, 'saved_at' => gmdate('c')], JSON_UNESCAPED_SLASHES),
            ]
        );

        $template = $this->findByName($workspaceId, $name, $language);
        if (!$template) {
            throw new \RuntimeException('Template draft could not be saved.');
        }
        $this->createVersion((int) $template['id'], $workspaceId, $userId, $template);

        return $template;
    }

    public function submitForApproval(int $workspaceId, int $templateId, int $userId): array
    {
        $template = $this->loadTemplate($workspaceId, $templateId);
        if (!$template) {
            throw new \RuntimeException('Template not found.');
        }

        $connection = (new WhatsAppConnectionResolver())->resolve($workspaceId);
        $wabaId = trim((string) ($connection['whatsapp_business_account_id'] ?? ''));
        $accessToken = trim((string) ($connection['access_token'] ?? ''));
        if ($wabaId === '' || $accessToken === '') {
            throw new \RuntimeException('A connected WABA and access token are required before submitting templates.');
        }

        $payload = $this->buildProviderPayload($template);
        $response = [];
        $submissionStatus = 'submitted';
        $providerTemplateId = null;
        $error = null;
        try {
            $response = $this->graphPost($wabaId . '/message_templates', $accessToken, $payload);
            $providerTemplateId = (string) ($response['id'] ?? $response['message_template_id'] ?? '');
            $submissionStatus = 'pending';
        } catch (\Throwable $e) {
            $submissionStatus = 'failed';
            $error = $e->getMessage();
            $response = ['error' => $error];
        }

        $version = $this->createVersion((int) $template['id'], $workspaceId, $userId, array_merge($template, [
            'provider_payload' => $payload,
            'provider_response' => $response,
            'status' => $submissionStatus === 'failed' ? 'sync_failed' : 'submitted',
        ]));

        Database::execute(
            "INSERT INTO workspace_whatsapp_template_submissions
                (workspace_id, template_id, version_id, submitted_by_user_id, connection_mode, provider_submission_id,
                 submission_status, request_payload_json, response_payload_json, rejection_reason, submitted_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
            [
                $workspaceId,
                (int) $template['id'],
                (int) ($version['id'] ?? 0) ?: null,
                $userId > 0 ? $userId : null,
                (string) ($connection['connection_mode'] ?? WhatsAppConnectionResolver::MODE_SELF_MANAGED),
                $providerTemplateId !== '' ? $providerTemplateId : null,
                $submissionStatus,
                json_encode($payload, JSON_UNESCAPED_SLASHES),
                json_encode($response, JSON_UNESCAPED_SLASHES),
                $error,
            ]
        );

        Database::execute(
            "UPDATE workspace_whatsapp_templates
             SET provider_template_id = COALESCE(NULLIF(?, ''), provider_template_id),
                 status = ?,
                 rejection_reason = ?,
                 last_submitted_at = NOW(),
                 updated_by_user_id = ?,
                 updated_at = NOW()
             WHERE workspace_id = ? AND id = ?",
            [
                $providerTemplateId,
                $submissionStatus === 'failed' ? 'sync_failed' : 'pending',
                $error,
                $userId > 0 ? $userId : null,
                $workspaceId,
                (int) $template['id'],
            ]
        );

        return [
            'success' => $submissionStatus !== 'failed',
            'status' => $submissionStatus,
            'template' => $this->loadTemplate($workspaceId, (int) $template['id']),
            'provider_response' => $response,
            'error' => $error,
        ];
    }

    public function syncFromProvider(int $workspaceId): array
    {
        $connection = (new WhatsAppConnectionResolver())->resolve($workspaceId);
        if (empty($connection['available'])) {
            return ['synced' => 0, 'error' => 'WhatsApp is not connected.'];
        }

        $providerTemplates = $this->providerTemplateCatalog();
        $synced = 0;
        foreach ($providerTemplates as $providerTemplate) {
            $name = $this->normalizeTemplateName((string) ($providerTemplate['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $language = (string) ($providerTemplate['language'] ?? 'en_US');
            $category = $this->normalizeCategory((string) ($providerTemplate['category'] ?? 'utility'));
            $body = trim((string) ($providerTemplate['body_text'] ?? ''));
            $status = strtolower((string) ($providerTemplate['status'] ?? 'approved'));
            if (!in_array($status, ['approved', 'rejected', 'paused', 'disabled', 'pending'], true)) {
                $status = 'approved';
            }
            $rejectionReason = $this->providerRejectionReason($providerTemplate);
            $providerPayload = $providerTemplate['provider_payload'] ?? $providerTemplate;

            Database::execute(
                "INSERT INTO workspace_whatsapp_templates
                    (workspace_id, integration_id, connection_mode, provider_template_id, template_name, language_code, category, status,
                     body_text, variables_json, sample_values_json, rejection_reason, metadata_json, last_synced_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE
                    integration_id = VALUES(integration_id),
                    connection_mode = VALUES(connection_mode),
                    provider_template_id = COALESCE(VALUES(provider_template_id), provider_template_id),
                    category = VALUES(category),
                    status = VALUES(status),
                    rejection_reason = VALUES(rejection_reason),
                    body_text = CASE WHEN VALUES(body_text) <> '' THEN VALUES(body_text) ELSE body_text END,
                    variables_json = VALUES(variables_json),
                    sample_values_json = VALUES(sample_values_json),
                    metadata_json = VALUES(metadata_json),
                    last_synced_at = NOW(),
                    updated_at = NOW()",
                [
                    $workspaceId,
                    (int) ($connection['integration_id'] ?? 0) ?: null,
                    (string) ($connection['connection_mode'] ?? WhatsAppConnectionResolver::MODE_SELF_MANAGED),
                    (string) ($providerTemplate['id'] ?? '') ?: null,
                    $name,
                    $language,
                    $category,
                    $status,
                    $body,
                    json_encode($providerTemplate['parameter_schema'] ?? [], JSON_UNESCAPED_SLASHES),
                    null,
                    $rejectionReason !== '' ? $rejectionReason : null,
                    json_encode(['provider_template' => $providerPayload, 'provider_status' => $status], JSON_UNESCAPED_SLASHES),
                ]
            );

            Database::execute(
                "INSERT INTO workspace_whatsapp_template_sync_snapshots
                    (workspace_id, integration_id, provider_template_id, template_name, language_code, provider_status, provider_category, provider_payload_json, synced_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())",
                [
                    $workspaceId,
                    (int) ($connection['integration_id'] ?? 0) ?: null,
                    (string) ($providerTemplate['id'] ?? '') ?: null,
                    $name,
                    $language,
                    strtoupper($status),
                    strtoupper($category),
                    json_encode($providerPayload, JSON_UNESCAPED_SLASHES),
                ]
            );
            $this->syncLatestSubmissionStatus(
                $workspaceId,
                $name,
                $language,
                $status,
                $rejectionReason,
                $providerPayload
            );
            $synced++;
        }

        return ['synced' => $synced];
    }

    public function cloneForResubmission(int $workspaceId, int $templateId, int $userId): array
    {
        $template = $this->loadTemplate($workspaceId, $templateId);
        if (!$template) {
            throw new \RuntimeException('Template not found.');
        }
        $newName = substr($this->normalizeTemplateName((string) $template['template_name']) . '_v' . date('ymdHi'), 0, 191);
        $template['template_name'] = $newName;
        $template['status'] = 'draft';
        return $this->createOrUpdateDraft($workspaceId, $userId, $template);
    }

    private function loadTemplate(int $workspaceId, int $templateId): ?array
    {
        return Database::queryOne(
            "SELECT * FROM workspace_whatsapp_templates WHERE workspace_id = ? AND id = ? LIMIT 1",
            [$workspaceId, $templateId]
        );
    }

    private function findByName(int $workspaceId, string $name, string $language): ?array
    {
        return Database::queryOne(
            "SELECT * FROM workspace_whatsapp_templates WHERE workspace_id = ? AND template_name = ? AND language_code = ? LIMIT 1",
            [$workspaceId, $name, $language]
        );
    }

    private function providerRejectionReason(array $providerTemplate): string
    {
        foreach (['rejection_reason', 'rejected_reason', 'reason', 'status_reason'] as $key) {
            $value = trim((string) ($providerTemplate[$key] ?? ''));
            if ($value !== '') {
                return substr($value, 0, 500);
            }
        }

        $payload = $providerTemplate['provider_payload'] ?? [];
        if (is_array($payload)) {
            foreach (['rejection_reason', 'rejected_reason', 'reason', 'status_reason'] as $key) {
                $value = trim((string) ($payload[$key] ?? ''));
                if ($value !== '') {
                    return substr($value, 0, 500);
                }
            }
        }

        return '';
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function providerTemplateCatalog(): array
    {
        return (new WhatsAppService())->getTemplateCatalog();
    }

    private function syncLatestSubmissionStatus(int $workspaceId, string $name, string $language, string $status, string $rejectionReason, array $providerPayload): void
    {
        if (!Database::tableExists('workspace_whatsapp_template_submissions')) {
            return;
        }

        $template = $this->findByName($workspaceId, $name, $language);
        if (!$template) {
            return;
        }

        Database::execute(
            "UPDATE workspace_whatsapp_template_submissions
             SET submission_status = ?,
                 rejection_reason = ?,
                 response_payload_json = ?,
                 resolved_at = CASE WHEN ? IN ('approved', 'rejected') THEN NOW() ELSE resolved_at END
             WHERE workspace_id = ?
               AND template_id = ?
             ORDER BY id DESC
             LIMIT 1",
            [
                $status,
                $rejectionReason !== '' ? $rejectionReason : null,
                json_encode($providerPayload, JSON_UNESCAPED_SLASHES),
                $status,
                $workspaceId,
                (int) $template['id'],
            ]
        );
    }

    private function createVersion(int $templateId, int $workspaceId, int $userId, array $template): array
    {
        $row = Database::queryOne(
            "SELECT COALESCE(MAX(version_number), 0) + 1 AS next_version
             FROM workspace_whatsapp_template_versions
             WHERE template_id = ?",
            [$templateId]
        );
        $version = (int) ($row['next_version'] ?? 1);
        Database::execute(
            "INSERT INTO workspace_whatsapp_template_versions
                (template_id, workspace_id, version_number, status, payload_json, provider_response_json, submitted_at, synced_at, created_by_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $templateId,
                $workspaceId,
                $version,
                (string) ($template['status'] ?? 'draft'),
                json_encode($template, JSON_UNESCAPED_SLASHES),
                isset($template['provider_response']) ? json_encode($template['provider_response'], JSON_UNESCAPED_SLASHES) : null,
                in_array((string) ($template['status'] ?? ''), ['submitted', 'pending'], true) ? date('Y-m-d H:i:s') : null,
                (string) ($template['status'] ?? '') === 'approved' ? date('Y-m-d H:i:s') : null,
                $userId > 0 ? $userId : null,
            ]
        );

        return ['id' => (int) Database::lastInsertId(), 'version_number' => $version];
    }

    private function buildProviderPayload(array $template): array
    {
        $components = [];
        $headerText = trim((string) ($template['header_text'] ?? ''));
        if ($headerText !== '') {
            $components[] = [
                'type' => 'HEADER',
                'format' => strtoupper((string) ($template['header_type'] ?? 'TEXT')) ?: 'TEXT',
                'text' => $headerText,
            ];
        }
        $components[] = [
            'type' => 'BODY',
            'text' => (string) ($template['body_text'] ?? ''),
        ];
        $footerText = trim((string) ($template['footer_text'] ?? ''));
        if ($footerText !== '') {
            $components[] = ['type' => 'FOOTER', 'text' => $footerText];
        }
        $buttons = json_decode((string) ($template['buttons_json'] ?? '[]'), true);
        if (is_array($buttons) && $buttons !== []) {
            $components[] = ['type' => 'BUTTONS', 'buttons' => $buttons];
        }

        $sampleValues = json_decode((string) ($template['sample_values_json'] ?? '[]'), true);
        if (is_array($sampleValues) && $sampleValues !== []) {
            foreach ($components as &$component) {
                if (($component['type'] ?? '') === 'BODY') {
                    $component['example'] = ['body_text' => [array_values($sampleValues)]];
                }
            }
            unset($component);
        }

        return [
            'name' => (string) ($template['template_name'] ?? ''),
            'language' => (string) ($template['language_code'] ?? 'en_US'),
            'category' => strtoupper((string) ($template['category'] ?? 'utility')),
            'components' => $components,
        ];
    }

    private function graphPost(string $endpoint, string $accessToken, array $payload): array
    {
        $url = 'https://graph.facebook.com/v24.0/' . ltrim($endpoint, '/');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException('Template submission failed: ' . ($curlError ?: 'Unknown cURL error'));
        }
        $decoded = json_decode((string) $response, true);
        $decoded = is_array($decoded) ? $decoded : [];
        if ($httpCode >= 400) {
            throw new \RuntimeException((string) ($decoded['error']['message'] ?? 'Template submission failed.'));
        }

        return $decoded;
    }

    private function formatForComposer(array $template): array
    {
        $variables = json_decode((string) ($template['variables_json'] ?? '[]'), true);
        $variables = is_array($variables) ? $variables : [];
        return [
            'id' => (string) ($template['provider_template_id'] ?? $template['id'] ?? ''),
            'local_id' => (int) ($template['id'] ?? 0),
            'name' => (string) ($template['template_name'] ?? ''),
            'status' => strtoupper((string) ($template['status'] ?? 'approved')),
            'language' => (string) ($template['language_code'] ?? 'en_US'),
            'category' => strtoupper((string) ($template['category'] ?? 'utility')),
            'body_text' => (string) ($template['body_text'] ?? ''),
            'components' => [],
            'parameter_schema' => $variables,
            'parameter_count' => [
                'body' => count($variables['body'] ?? $variables),
                'header' => count($variables['header'] ?? []),
            ],
        ];
    }

    private function normalizeTemplateName(string $name): string
    {
        $name = strtolower(trim($name));
        $name = preg_replace('/[^a-z0-9_]+/', '_', $name) ?? $name;
        $name = trim($name, '_');
        return substr($name, 0, 191);
    }

    private function normalizeCategory(string $category): string
    {
        $category = strtolower(trim($category));
        return in_array($category, ['marketing', 'utility', 'authentication'], true) ? $category : 'utility';
    }

    private function extractVariables(string $text): array
    {
        preg_match_all('/\{\{\s*([a-zA-Z0-9_.]+|\d+)\s*\}\}/', $text, $matches);
        $vars = array_values(array_unique($matches[1] ?? []));
        return ['body' => array_map(static fn(string $v): array => ['key' => $v, 'type' => 'text'], $vars)];
    }

    private function normalizeJsonish(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $raw = trim((string) $value);
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $raw) ?: [])));
        return $lines;
    }
}
