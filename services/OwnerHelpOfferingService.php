<?php

namespace CRM\Services;

use CRM\Database;

class OwnerHelpOfferingService
{
    /**
     * @return array<int,array<string,mixed>>
     */
    public function activeOfferings(?string $lane = null): array
    {
        if (!Database::tableExists('owner_help_offerings')) {
            return $this->fallbackOfferings($lane);
        }

        $where = ['is_active = 1'];
        $params = [];
        $lane = trim((string) $lane);
        if ($lane !== '') {
            $where[] = 'lane = ?';
            $params[] = $lane;
        }

        $rows = Database::query(
            "SELECT *
             FROM owner_help_offerings
             WHERE " . implode(' AND ', $where) . "
             ORDER BY sort_order ASC, label ASC",
            $params
        );

        return array_map(fn(array $row): array => $this->normalize($row), $rows);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find(int $offeringId): ?array
    {
        if ($offeringId <= 0 || !Database::tableExists('owner_help_offerings')) {
            return null;
        }

        $row = Database::queryOne(
            "SELECT *
             FROM owner_help_offerings
             WHERE id = ? AND is_active = 1
             LIMIT 1",
            [$offeringId]
        );

        return $row ? $this->normalize($row) : null;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function normalize(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'offering_key' => (string) ($row['offering_key'] ?? ''),
            'lane' => (string) ($row['lane'] ?? 'setup_help'),
            'label' => (string) ($row['label'] ?? 'Setup help'),
            'summary' => (string) ($row['summary'] ?? ''),
            'pricing_label' => (string) (($row['pricing_label'] ?? '') ?: 'Quote required'),
            'sort_order' => (int) ($row['sort_order'] ?? 100),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fallbackOfferings(?string $lane): array
    {
        $offerings = [
            ['id' => 0, 'offering_key' => 'workspace_setup_audit', 'lane' => 'setup_help', 'label' => 'Workspace Setup Audit', 'summary' => 'Review setup and produce a practical checklist.', 'pricing_label' => 'Quote required', 'sort_order' => 10],
            ['id' => 0, 'offering_key' => 'email_whatsapp_setup', 'lane' => 'setup_help', 'label' => 'Email / WhatsApp Setup', 'summary' => 'Configure messaging channels and readiness checks.', 'pricing_label' => 'Quote required', 'sort_order' => 20],
            ['id' => 0, 'offering_key' => 'automation_setup', 'lane' => 'setup_help', 'label' => 'Automation Setup', 'summary' => 'Set up workflow automation with owner-safe boundaries.', 'pricing_label' => 'Quote required', 'sort_order' => 40],
            ['id' => 0, 'offering_key' => 'launch_readiness_review', 'lane' => 'installation_help', 'label' => 'Launch Readiness Review', 'summary' => 'Review final launch gaps before going live.', 'pricing_label' => 'Quote required', 'sort_order' => 60],
        ];

        if ($lane === null || trim($lane) === '') {
            return $offerings;
        }

        return array_values(array_filter($offerings, static fn(array $offering): bool => (string) $offering['lane'] === $lane));
    }
}
