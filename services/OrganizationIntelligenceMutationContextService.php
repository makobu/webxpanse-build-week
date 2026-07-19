<?php

namespace CRM\Services;

class OrganizationIntelligenceMutationContextService
{
    public const TTL_SECONDS = 172800;

    public function issue(int $workspaceId, int $userId, ?int $snapshotId, int $schemaVersion = 2): array
    {
        $issuedAt = time();
        $payload = [
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'schema_version' => $schemaVersion,
            'snapshot_id' => $snapshotId,
            'issued_at' => $issuedAt,
            'expires_at' => $issuedAt + self::TTL_SECONDS,
        ];
        $encoded = $this->encode(json_encode($payload, JSON_UNESCAPED_SLASHES));
        $signature = $this->encode(hash_hmac('sha256', $encoded, $this->secret(), true));
        return [
            'token' => $encoded . '.' . $signature,
            'expires_at' => date(DATE_ATOM, $payload['expires_at']),
        ];
    }

    public function verify(string $token, int $workspaceId, int $userId): array
    {
        $parts = explode('.', trim($token), 2);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new \RuntimeException('Refresh Organization Intelligence before making changes.');
        }
        [$encoded, $signature] = $parts;
        $expected = $this->encode(hash_hmac('sha256', $encoded, $this->secret(), true));
        if (!hash_equals($expected, $signature)) {
            throw new \RuntimeException('Organization Intelligence context could not be verified.');
        }
        $payload = json_decode($this->decode($encoded), true);
        if (!is_array($payload)
            || (int) ($payload['workspace_id'] ?? 0) !== $workspaceId
            || (int) ($payload['user_id'] ?? 0) !== $userId
            || (int) ($payload['schema_version'] ?? 0) !== 2
            || (int) ($payload['expires_at'] ?? 0) < time()) {
            throw new \RuntimeException('Organization Intelligence context is stale or outside the active scope.');
        }
        return $payload;
    }

    private function secret(): string
    {
        $secret = (string) ($_ENV['APP_KEY'] ?? $_ENV['APP_SECRET'] ?? $_ENV['DB_PASS'] ?? '');
        if ($secret === '') {
            $secret = 'crm-local-organization-intelligence-context';
        }
        return hash('sha256', $secret . '|organization-intelligence-v2');
    }

    private function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function decode(string $value): string
    {
        $padding = strlen($value) % 4;
        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            throw new \RuntimeException('Organization Intelligence context is malformed.');
        }
        return $decoded;
    }
}
