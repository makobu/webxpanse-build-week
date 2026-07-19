<?php

declare(strict_types=1);

namespace CRM\Services;

use CRM\Database;
use CRM\Security;

class DefaultWorkspaceDemoVisitorContactService
{
    private const SOURCE = 'default_workspace_demo_visitor';

    private DefaultWorkspaceService $defaultWorkspace;

    public function __construct(?DefaultWorkspaceService $defaultWorkspace = null)
    {
        $this->defaultWorkspace = $defaultWorkspace ?? new DefaultWorkspaceService();
    }

    /**
     * @param array{name?:string,email?:string,phone?:string,consent_contact?:bool} $visitor
     * @return array<string,mixed>
     */
    public function capture(array $visitor, string $sessionUuid, int $demoSessionId, int $demoWorkspaceId): array
    {
        if (empty($visitor['consent_contact'])) {
            return ['status' => 'skipped', 'reason' => 'no_contact_consent'];
        }

        $email = strtolower(trim((string) ($visitor['email'] ?? '')));
        if ($email === '' || !Security::validateEmail($email)) {
            return ['status' => 'skipped', 'reason' => 'invalid_email'];
        }

        $defaultWorkspaceId = $this->defaultWorkspace->id();
        $phone = trim((string) ($visitor['phone'] ?? ''));
        $metadata = $this->buildProspectMetadata($sessionUuid, $demoSessionId, $demoWorkspaceId, $phone);

        $existing = $this->findByEmail($defaultWorkspaceId, $email);
        if ($existing) {
            $this->mergeExistingContact($defaultWorkspaceId, $existing, [
                'phone' => $phone,
                'metadata' => $metadata,
            ]);

            return [
                'status' => 'duplicate',
                'reason' => 'email_exists',
                'workspace_id' => $defaultWorkspaceId,
                'contact_id' => (int) ($existing['id'] ?? 0),
                'metadata_merged' => true,
            ];
        }

        [$firstName, $lastName] = $this->nameParts((string) ($visitor['name'] ?? ''), $email);
        $assigneeId = $this->resolveDefaultAssignee($defaultWorkspaceId);

        Database::execute(
            "INSERT INTO contacts
                (workspace_id, uuid, first_name, last_name, email, phone, company, lead_source, stage, assigned_to, created_by, metadata_json, created_at, updated_at)
             VALUES
                (?, ?, ?, ?, ?, ?, NULL, 'other', 'new', ?, ?, ?, NOW(), NOW())",
            [
                $defaultWorkspaceId,
                $this->uuid(),
                $firstName,
                $lastName,
                $email,
                $phone !== '' ? $phone : null,
                $assigneeId,
                $assigneeId,
                json_encode($metadata, JSON_UNESCAPED_SLASHES),
            ]
        );

        return [
            'status' => 'created',
            'workspace_id' => $defaultWorkspaceId,
            'contact_id' => (int) Database::lastInsertId(),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findByEmail(int $workspaceId, string $email): ?array
    {
        return Database::queryOne(
            "SELECT id, email, phone, stage, metadata_json
             FROM contacts
             WHERE workspace_id = ?
               AND LOWER(TRIM(email)) = LOWER(TRIM(?))
             ORDER BY id ASC
             LIMIT 1",
            [$workspaceId, $email]
        ) ?: null;
    }

    /**
     * @return array<string,mixed>
     */
    private function buildProspectMetadata(string $sessionUuid, int $demoSessionId, int $demoWorkspaceId, string $phone): array
    {
        return [
            'source' => self::SOURCE,
            'relationship' => 'demo_visitor',
            'default_workspace_use' => 'demo_email_follow_up',
            'default_workspace_contact_scope' => 'unqualified_demo_prospect',
            'default_workspace_nurture_qualified' => false,
            'prospect_state' => 'unqualified',
            'email_follow_up_allowed' => true,
            'email_consent_source' => 'protected_demo',
            'marketing_conversion_allowed' => true,
            'demo_session_uuid' => $sessionUuid,
            'demo_session_id' => $demoSessionId > 0 ? $demoSessionId : null,
            'demo_workspace_id' => $demoWorkspaceId > 0 ? $demoWorkspaceId : null,
            'consent_contact' => true,
            'phone_provided' => $phone !== '',
            'captured_at' => gmdate('c'),
        ];
    }

    /**
     * @param array<string,mixed> $existing
     * @param array{phone:string,metadata:array<string,mixed>} $payload
     */
    private function mergeExistingContact(int $defaultWorkspaceId, array $existing, array $payload): void
    {
        $contactId = (int) ($existing['id'] ?? 0);
        if ($contactId <= 0) {
            return;
        }

        $existingMetadata = $this->decodeJson($existing['metadata_json'] ?? null);
        $prospectMetadata = $payload['metadata'];
        $isQualified = $this->isQualifiedDefaultContact((string) ($existing['stage'] ?? ''), $existingMetadata);
        $mergedMetadata = $existingMetadata;

        if ($isQualified) {
            $mergedMetadata['email_follow_up_allowed'] = true;
            $mergedMetadata['latest_demo_session_uuid'] = $prospectMetadata['demo_session_uuid'] ?? null;
            $mergedMetadata['latest_demo_session_id'] = $prospectMetadata['demo_session_id'] ?? null;
            $mergedMetadata['latest_demo_workspace_id'] = $prospectMetadata['demo_workspace_id'] ?? null;
            $mergedMetadata['demo_contact_reconsented_at'] = gmdate('c');
        } else {
            $mergedMetadata = array_merge($existingMetadata, $prospectMetadata);
        }

        $sets = ['metadata_json = ?', 'updated_at = NOW()'];
        $params = [json_encode($mergedMetadata, JSON_UNESCAPED_SLASHES)];
        $phone = trim((string) ($payload['phone'] ?? ''));
        if ($phone !== '' && trim((string) ($existing['phone'] ?? '')) === '') {
            $sets[] = 'phone = ?';
            $params[] = mb_substr($phone, 0, 50);
        }

        if (!$isQualified && trim((string) ($existing['stage'] ?? '')) === '') {
            $sets[] = "stage = 'new'";
        }

        $params[] = $defaultWorkspaceId;
        $params[] = $contactId;
        Database::execute(
            "UPDATE contacts SET " . implode(', ', $sets) . " WHERE workspace_id = ? AND id = ?",
            $params
        );
    }

    /**
     * @param array<string,mixed> $metadata
     */
    private function isQualifiedDefaultContact(string $stage, array $metadata): bool
    {
        $stage = strtolower(trim($stage));
        $scope = (string) ($metadata['default_workspace_contact_scope'] ?? '');

        return in_array($stage, ['qualified', 'proposal', 'negotiation', 'won'], true)
            || in_array($scope, ['qualified_workspace_lead', 'current_paying_customer'], true)
            || (string) ($metadata['source'] ?? '') === 'default_workspace_owner_contact'
            || !empty($metadata['owner_user_id'])
            || !empty($metadata['current_paying_customer']);
    }

    /**
     * @return array{0:string,1:string}
     */
    private function nameParts(string $name, string $email): array
    {
        $normalized = trim((string) preg_replace('/\s+/', ' ', $name));
        if ($normalized === '') {
            $normalized = trim(strstr($email, '@', true) ?: 'Demo Visitor');
        }

        $parts = preg_split('/\s+/', $normalized, 2) ?: [];
        $firstName = Security::sanitizeInput((string) ($parts[0] ?? 'Demo'), 'string') ?: 'Demo';
        $lastName = Security::sanitizeInput((string) ($parts[1] ?? 'Visitor'), 'string') ?: 'Visitor';

        return [
            mb_substr($firstName, 0, 100),
            mb_substr($lastName, 0, 100),
        ];
    }

    private function resolveDefaultAssignee(int $defaultWorkspaceId): ?int
    {
        $owner = Database::queryOne(
            "SELECT user_id
             FROM workspace_memberships
             WHERE workspace_id = ?
               AND membership_status = 'active'
             ORDER BY is_owner DESC, FIELD(role_slug, 'superadmin', 'owner', 'admin', 'accountant', 'expert', 'viewer'), id ASC
             LIMIT 1",
            [$defaultWorkspaceId]
        );

        return !empty($owner['user_id']) ? (int) $owner['user_id'] : null;
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeJson(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
