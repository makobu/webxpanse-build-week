<?php

namespace CRM\Services;

use CRM\Database;

class WorkspaceService
{
    public function createWorkspace(string $name, string $slug, ?int $createdBy = null, int $trialDays = 14): int
    {
        $name = trim($name);
        $slug = $this->generateUniqueSlug($name);

        if ($name === '') {
            throw new \RuntimeException('Workspace name is required.');
        }

        if ($slug === '') {
            throw new \RuntimeException('Workspace slug is required.');
        }

        if ($this->getBySlug($slug)) {
            throw new \RuntimeException('Workspace slug is already taken.');
        }

        $uuid = $this->generateUuid();

        Database::execute(
            "INSERT INTO workspaces (uuid, name, slug, status, plan_status, created_by)
             VALUES (?, ?, ?, 'active', 'active', ?)",
            [$uuid, $name, $slug, $createdBy]
        );

        $workspaceId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO workspace_slugs (workspace_id, slug, is_primary) VALUES (?, ?, 1)",
            [$workspaceId, $slug]
        );

        return $workspaceId;
    }

    public function getById(int $workspaceId): ?array
    {
        if ($workspaceId <= 0) {
            return null;
        }

        return Database::queryOne(
            "SELECT * FROM workspaces WHERE id = ? LIMIT 1",
            [$workspaceId]
        );
    }

    public function getBySlug(string $slug): ?array
    {
        $slug = trim($slug);
        if ($slug === '') {
            return null;
        }

        return Database::queryOne(
            "SELECT * FROM workspaces WHERE slug = ? LIMIT 1",
            [$slug]
        );
    }

    public function listForUser(int $userId): array
    {
        return (new WorkspaceMembershipService())->listForUser($userId);
    }

    public function normalizeSlug(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = preg_replace('/[^a-z0-9]+/', '-', $normalized) ?? '';
        $normalized = trim($normalized, '-');

        return substr($normalized, 0, 120);
    }

    private function generateUniqueSlug(string $name): string
    {
        $base = $this->normalizeSlug($name);
        if ($base === '') {
            throw new \RuntimeException('Workspace slug is required.');
        }

        $base = substr($base, 0, 110);
        $slug = $base;
        $suffix = 2;

        while ($this->getBySlug($slug) !== null) {
            $suffixText = '-' . $suffix;
            $slug = substr($base, 0, 120 - strlen($suffixText)) . $suffixText;
            $suffix++;
        }

        return $slug;
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
