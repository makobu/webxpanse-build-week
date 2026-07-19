<?php

namespace CRM\Services;

use CRM\Database;

class WorkflowWebhookTriggerService
{
    private const REQUIRED_PERMISSION = 'workflows.trigger';
    private const TRIGGER_TYPES = ['webhook_received', 'api_call'];

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $apiKeyAuth
     * @return array{status:int,body:array<string,mixed>}
     */
    public function handle(array $payload, array $apiKeyAuth): array
    {
        if (!$this->hasPermission($apiKeyAuth, self::REQUIRED_PERMISSION)) {
            return $this->error(403, 'forbidden', 'API key does not have workflows.trigger permission.');
        }

        $workspaceId = (int) ($apiKeyAuth['workspace_id'] ?? 0);
        if ($workspaceId <= 0) {
            return $this->error(403, 'workspace_required', 'API key is not scoped to a workspace.');
        }

        $workflowId = isset($payload['workflow_id']) ? (int) $payload['workflow_id'] : 0;
        $triggerKey = trim((string) ($payload['trigger_key'] ?? ''));

        if ($workflowId <= 0 && $triggerKey === '') {
            return $this->error(422, 'workflow_identifier_required', 'workflow_id or trigger_key is required.');
        }

        if ($workflowId > 0) {
            $workflow = $this->findWorkflowById($workspaceId, $workflowId);
        } else {
            $workflow = $this->findWorkflowByTriggerKey($workspaceId, $triggerKey);
            if (($workflow['status'] ?? 200) !== 200) {
                return $workflow;
            }
        }

        if (!$workflow) {
            return $this->error(404, 'workflow_not_found', 'Workflow not found or inactive.');
        }

        $workflowId = (int) $workflow['id'];
        $contactResult = $this->resolveContact($workspaceId, $payload);
        if (($contactResult['status'] ?? 200) !== 200) {
            return $contactResult;
        }

        $contactId = (int) ($contactResult['contact_id'] ?? 0);
        $eventData = $payload;
        $eventData['contact_id'] = $contactId;
        $eventData['workspace_id'] = $workspaceId;
        $eventData['workflow_id'] = $workflowId;

        $queueId = (new WorkflowQueueService())->addToQueue($workflowId, $contactId, $eventData, $workspaceId);

        return [
            'status' => 200,
            'body' => [
                'success' => true,
                'workflow_id' => $workflowId,
                'contact_id' => $contactId,
                'queue_id' => $queueId,
            ],
        ];
    }

    /**
     * Empty API key permissions mean full access.
     *
     * @param array<string,mixed> $apiKeyAuth
     */
    public function hasPermission(array $apiKeyAuth, string $permission): bool
    {
        $permissions = $apiKeyAuth['permissions'] ?? [];
        if (!is_array($permissions)) {
            return false;
        }

        if ($permissions === []) {
            return true;
        }

        return in_array($permission, array_map('strval', $permissions), true);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findWorkflowById(int $workspaceId, int $workflowId): ?array
    {
        return Database::queryOne(
            "SELECT id, workspace_id, trigger_config
             FROM workflows
             WHERE workspace_id = ?
               AND id = ?
               AND is_active = 1
             LIMIT 1",
            [$workspaceId, $workflowId]
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function findWorkflowByTriggerKey(int $workspaceId, string $triggerKey): array
    {
        if ($triggerKey === '') {
            return $this->error(422, 'trigger_key_required', 'trigger_key cannot be empty.');
        }

        $matches = [];
        $workflows = Database::query(
            "SELECT id, workspace_id, trigger_config
             FROM workflows
             WHERE workspace_id = ?
               AND is_active = 1",
            [$workspaceId]
        );

        foreach ($workflows as $workflow) {
            $triggerConfig = json_decode((string) ($workflow['trigger_config'] ?? ''), true);
            if (!is_array($triggerConfig)) {
                continue;
            }

            $type = (string) ($triggerConfig['type'] ?? '');
            $candidateKey = trim((string) ($triggerConfig['trigger_key'] ?? ''));
            if (in_array($type, self::TRIGGER_TYPES, true) && hash_equals($triggerKey, $candidateKey)) {
                $matches[] = $workflow;
            }
        }

        if (count($matches) > 1) {
            return $this->error(409, 'duplicate_trigger_key', 'Multiple active workflows use this trigger_key in the API key workspace.');
        }

        if ($matches === []) {
            return $this->error(404, 'workflow_not_found', 'No active webhook/API workflow was found for this trigger_key.');
        }

        return $matches[0];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function resolveContact(int $workspaceId, array $payload): array
    {
        $contactId = isset($payload['contact_id']) ? (int) $payload['contact_id'] : 0;
        $email = trim((string) ($payload['email'] ?? ''));

        if ($contactId <= 0 && $email === '') {
            return $this->error(422, 'contact_required', 'contact_id or email is required.');
        }

        if ($contactId > 0) {
            $contact = Database::queryOne(
                "SELECT id
                 FROM contacts
                 WHERE workspace_id = ?
                   AND id = ?
                 LIMIT 1",
                [$workspaceId, $contactId]
            );

            if (!$contact) {
                return $this->error(404, 'contact_not_found', 'Contact not found in the API key workspace.');
            }

            return ['status' => 200, 'contact_id' => $contactId];
        }

        $contact = Database::queryOne(
            "SELECT id
             FROM contacts
             WHERE workspace_id = ?
               AND LOWER(email) = LOWER(?)
             ORDER BY id ASC
             LIMIT 1",
            [$workspaceId, $email]
        );

        if (!$contact) {
            return $this->error(404, 'contact_not_found', 'Contact not found in the API key workspace.');
        }

        return ['status' => 200, 'contact_id' => (int) $contact['id']];
    }

    /**
     * @return array{status:int,body:array<string,mixed>}
     */
    private function error(int $status, string $code, string $message): array
    {
        return [
            'status' => $status,
            'body' => [
                'success' => false,
                'error' => $message,
                'code' => $code,
            ],
        ];
    }
}
