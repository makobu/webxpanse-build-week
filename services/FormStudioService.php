<?php

namespace CRM\Services;

use CRM\Database;
use CRM\Modules\Forms;
use RuntimeException;
use Throwable;

/** Persistence and publication workflow for Form Studio documents. */
final class FormStudioService
{
    private FormDesignService $design;
    private Forms $forms;
    private WorkspaceScopeService $workspaceScope;

    public function __construct()
    {
        $this->design = new FormDesignService();
        $this->forms = new Forms();
        $this->workspaceScope = new WorkspaceScopeService();
    }

    public function create(string $name, string $templateKey, ?int $userId = null): int
    {
        $template = $this->template($templateKey);
        $document = $template['document'];
        $document['content']['title'] = trim($name) !== '' ? trim($name) : (string) $template['name'];
        $document = $this->design->normalizeDocument($document);
        $validation = $this->design->validateDocument($document);
        $id = $this->forms->create([
            'name' => $document['content']['title'],
            'fields' => $this->design->legacyFields($document),
            'success_message' => $document['content']['success_message'],
            'redirect_url' => $document['content']['redirect_url'],
            'settings' => $this->design->legacySettings($document),
            'created_by' => $userId,
        ]);
        if ($id <= 0) {
            throw new RuntimeException('Form Studio could not create the form.');
        }
        $token = $this->token();
        Database::execute(
            "UPDATE forms
             SET design_schema_version = ?, design_document_json = ?, design_revision = 1,
                 design_validation_json = ?, design_status = 'draft', preview_token = ?, design_updated_at = NOW()
             WHERE workspace_id = ? AND id = ?",
            [
                FormDesignService::SCHEMA_VERSION,
                $this->json($document),
                $this->json($validation),
                $token,
                $this->workspaceId(),
                $id,
            ]
        );
        $this->checkpoint($id, 1, 'draft', $document, $validation, $userId, true);
        $this->recordTemplate($id, $templateKey, (string) ($template['version'] ?? '1.0.0'), 'applied', $userId);
        return $id;
    }

    /** @return array<string,mixed> */
    public function editorData(int $id): array
    {
        $form = $this->forms->getById($id);
        if (!$form) {
            throw new RuntimeException('Form not found.');
        }
        $document = $this->design->normalizeDocument($this->arrayValue($form['design_document_json'] ?? []), $form);
        $validation = $this->design->validateDocument($document, $form);
        $token = trim((string) ($form['preview_token'] ?? ''));
        if ($token === '') {
            $token = $this->token();
            Database::execute(
                'UPDATE forms SET preview_token = ? WHERE workspace_id = ? AND id = ?',
                [$token, $this->workspaceId(), $id]
            );
            $form['preview_token'] = $token;
        }
        return [
            'form' => $form,
            'document' => $document,
            'revision' => (int) ($form['design_revision'] ?? 0),
            'validation' => $validation,
            'manifest' => $this->design->editorManifest(),
            'templates' => $this->design->templates(),
            'versions' => $this->versions($id),
            'preview_token' => $token,
            'is_published' => (string) ($form['design_status'] ?? 'published') === 'published',
        ];
    }

    /** @param array<string,mixed> $document @param array<string,mixed> $formFields @return array<string,mixed> */
    public function save(int $id, array $document, array $formFields, int $expectedRevision, ?int $userId = null): array
    {
        $form = $this->forms->getById($id);
        if (!$form) {
            throw new RuntimeException('Form not found.');
        }
        $currentRevision = (int) ($form['design_revision'] ?? 0);
        if ($currentRevision !== $expectedRevision) {
            throw new RuntimeException('This form changed in another session. Reload the latest revision before saving.');
        }
        if (isset($formFields['name']) && trim((string) $formFields['name']) !== '') {
            $document['content']['title'] = (string) $formFields['name'];
        }
        $document = $this->design->normalizeDocument($document, $form);
        $validation = $this->design->validateDocument($document, $form);
        $legacyFields = $this->design->legacyFields($document);
        $settings = $this->design->legacySettings($document);
        $nextRevision = $currentRevision + 1;
        $previousTemplate = (string) (($this->arrayValue($form['design_document_json'] ?? [])['metadata']['template_key'] ?? ''));

        Database::beginTransaction();
        try {
            $changed = Database::execute(
                "UPDATE forms
                 SET name = ?, fields = ?, success_message = ?, redirect_url = ?, settings = ?,
                     design_schema_version = ?, design_document_json = ?, design_validation_json = ?,
                     design_revision = design_revision + 1, design_updated_at = NOW()
                 WHERE workspace_id = ? AND id = ? AND design_revision = ?",
                [
                    $document['content']['title'],
                    $this->json($legacyFields),
                    $document['content']['success_message'],
                    $document['content']['redirect_url'] !== '' ? $document['content']['redirect_url'] : null,
                    $this->json($settings),
                    FormDesignService::SCHEMA_VERSION,
                    $this->json($document),
                    $this->json($validation),
                    $this->workspaceId(),
                    $id,
                    $expectedRevision,
                ]
            );
            if ($changed !== 1) {
                throw new RuntimeException('This form changed in another session. Reload the latest revision before saving.');
            }
            $this->checkpoint($id, $nextRevision, 'draft', $document, $validation, $userId, false);
            $templateKey = (string) ($document['metadata']['template_key'] ?? '');
            if ($templateKey !== '' && $templateKey !== 'legacy_migration' && $templateKey !== $previousTemplate) {
                $this->recordTemplate($id, $templateKey, (string) ($document['metadata']['template_version'] ?? '1.0.0'), 'applied', $userId);
            }
            Database::commit();
        } catch (Throwable $e) {
            Database::rollBack();
            throw $e;
        }
        return $this->editorData($id);
    }

    /** @return array<string,mixed> */
    public function publish(int $id, ?int $userId = null): array
    {
        $form = $this->forms->getById($id);
        if (!$form) {
            throw new RuntimeException('Form not found.');
        }
        $document = $this->design->normalizeDocument($this->arrayValue($form['design_document_json'] ?? []), $form);
        $validation = $this->design->validateDocument($document, $form);
        if (empty($validation['valid'])) {
            throw new RuntimeException('Form is not ready to publish: ' . implode(' ', (array) ($validation['errors'] ?? [])));
        }
        $revision = (int) ($form['design_revision'] ?? 0);
        Database::beginTransaction();
        try {
            Database::execute(
                "UPDATE forms
                 SET design_status = 'published', published_document_json = ?, published_revision = ?,
                     published_at = NOW(), design_validation_json = ?
                 WHERE workspace_id = ? AND id = ?",
                [$this->json($document), $revision, $this->json($validation), $this->workspaceId(), $id]
            );
            $this->checkpoint($id, $revision, 'published', $document, $validation, $userId, true);
            $templateKey = (string) ($document['metadata']['template_key'] ?? '');
            if ($templateKey !== '' && $templateKey !== 'legacy_migration') {
                $this->recordTemplate($id, $templateKey, (string) ($document['metadata']['template_version'] ?? '1.0.0'), 'published', $userId);
            }
            Database::commit();
        } catch (Throwable $e) {
            Database::rollBack();
            throw $e;
        }
        return $this->editorData($id);
    }

    /** @return array<string,mixed> */
    public function restore(int $formId, int $versionId, int $expectedRevision, ?int $userId = null): array
    {
        $version = Database::queryOne(
            'SELECT * FROM form_design_versions WHERE workspace_id = ? AND form_id = ? AND id = ? LIMIT 1',
            [$this->workspaceId(), $formId, $versionId]
        );
        if (!$version) {
            throw new RuntimeException('Form version not found.');
        }
        $document = $this->arrayValue($version['document_json'] ?? []);
        $saved = $this->save($formId, $document, [], $expectedRevision, $userId);
        $this->checkpoint(
            $formId,
            (int) ($saved['revision'] ?? 0),
            'restored',
            (array) ($saved['document'] ?? []),
            (array) ($saved['validation'] ?? []),
            $userId,
            true
        );
        return $this->editorData($formId);
    }

    /** @param array<string,mixed> $form */
    public function publicDocument(array $form, string $previewToken = ''): ?array
    {
        $storedToken = (string) ($form['preview_token'] ?? '');
        if ($previewToken !== '' && $storedToken !== '' && hash_equals($storedToken, $previewToken)) {
            return $this->design->normalizeDocument($this->arrayValue($form['design_document_json'] ?? []), $form);
        }
        if ((string) ($form['design_status'] ?? 'published') !== 'published') {
            return null;
        }
        $published = $this->arrayValue($form['published_document_json'] ?? []);
        return $this->design->normalizeDocument($published !== [] ? $published : $this->arrayValue($form['design_document_json'] ?? []), $form);
    }

    /** @return array<int,array<string,mixed>> */
    private function versions(int $id): array
    {
        $rows = Database::query(
            "SELECT v.id, v.revision, v.version_type, v.created_at, u.email AS created_by_email
             FROM form_design_versions v
             LEFT JOIN users u ON u.id = v.created_by
             WHERE v.workspace_id = ? AND v.form_id = ?
             ORDER BY v.created_at DESC, v.id DESC LIMIT 30",
            [$this->workspaceId(), $id]
        );
        return array_map(static fn(array $row): array => [
            'id' => (int) ($row['id'] ?? 0),
            'revision' => (int) ($row['revision'] ?? 0),
            'type' => (string) ($row['version_type'] ?? 'draft'),
            'created_at' => (string) ($row['created_at'] ?? ''),
            'created_by_email' => (string) ($row['created_by_email'] ?? ''),
        ], $rows);
    }

    /** @param array<string,mixed> $document @param array<string,mixed> $validation */
    private function checkpoint(int $formId, int $revision, string $type, array $document, array $validation, ?int $userId, bool $force): void
    {
        if (!$force && $type === 'draft') {
            $recent = Database::queryOne(
                "SELECT id FROM form_design_versions
                 WHERE workspace_id = ? AND form_id = ? AND version_type = 'draft'
                   AND created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                 ORDER BY id DESC LIMIT 1",
                [$this->workspaceId(), $formId]
            );
            if ($recent) {
                return;
            }
        }
        Database::execute(
            'INSERT INTO form_design_versions (workspace_id, form_id, revision, version_type, document_json, validation_json, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$this->workspaceId(), $formId, $revision, $type, $this->json($document), $this->json($validation), $userId ?: null]
        );
    }

    private function recordTemplate(int $formId, string $key, string $version, string $event, ?int $userId): void
    {
        if (!Database::tableExists('form_design_template_events')) {
            return;
        }
        Database::execute(
            'INSERT INTO form_design_template_events (workspace_id, form_id, template_key, template_version, event_type, metadata_json, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$this->workspaceId(), $formId, $key, $version, $event, $this->json(['source' => 'form_studio']), $userId ?: null]
        );
    }

    /** @return array<string,mixed> */
    private function template(string $key): array
    {
        foreach ($this->design->templates() as $template) {
            if ((string) $template['key'] === $key) {
                return $template;
            }
        }
        throw new RuntimeException('Choose an approved Form Studio template.');
    }

    /** @return array<string,mixed> */
    private function arrayValue(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    private function json(mixed $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            throw new RuntimeException('Form Studio data could not be encoded.');
        }
        return $json;
    }

    private function token(): string
    {
        return bin2hex(random_bytes(24));
    }

    private function workspaceId(): int
    {
        return $this->workspaceScope->requireActiveWorkspaceId();
    }
}
