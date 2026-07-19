<?php
/**
 * Idea Validation Context Module
 *
 * Manages user-provided context for idea validation (Foundation Mode).
 */

namespace CRM\Modules;

use CRM\Database;

class IdeaValidationContext
{
    private const MAX_FIELD_LENGTH = 2000;

    /**
     * Get idea validation context for a user.
     */
    public function get(int $userId): ?array
    {
        $workspaceId = $this->currentWorkspaceId();
        if (!$this->hasWorkspaceColumn()) {
            return $this->getLegacy($userId);
        }

        $row = Database::queryOne(
            "SELECT id, workspace_id, user_id, value_proposition, target_market, pain_points,
                    assumptions_to_test, competitors, differentiator, created_at, updated_at
             FROM idea_validation_context
             WHERE user_id = ?
               AND workspace_id IN (?, 0)
             ORDER BY CASE WHEN workspace_id = ? THEN 0 ELSE 1 END, id DESC
             LIMIT 1",
            [$userId, $workspaceId, $workspaceId]
        );
        return $row ?: null;
    }

    private function getLegacy(int $userId): ?array
    {
        $row = Database::queryOne(
            "SELECT id, user_id, value_proposition, target_market, pain_points,
                    assumptions_to_test, competitors, differentiator, created_at, updated_at
             FROM idea_validation_context WHERE user_id = ?",
            [$userId]
        );
        return $row ?: null;
    }

    /**
     * Save or update idea validation context.
     * Fields are optional; at least value_proposition and target_market recommended.
     */
    public function save(int $userId, array $data): bool
    {
        $workspaceId = $this->currentWorkspaceId();

        $valueProposition = $this->sanitizeText($data['value_proposition'] ?? '');
        $targetMarket = $this->sanitizeText($data['target_market'] ?? '');
        $painPoints = $this->sanitizeText($data['pain_points'] ?? '');
        $assumptionsToTest = $this->sanitizeText($data['assumptions_to_test'] ?? '');
        $competitors = $this->sanitizeText($data['competitors'] ?? '');
        $differentiator = $this->sanitizeText($data['differentiator'] ?? '');

        if (!$this->hasWorkspaceColumn()) {
            $existing = $this->getLegacy($userId);
            if ($existing) {
                Database::execute(
                    "UPDATE idea_validation_context SET
                        value_proposition = ?, target_market = ?, pain_points = ?,
                        assumptions_to_test = ?, competitors = ?, differentiator = ?
                     WHERE user_id = ?",
                    [$valueProposition, $targetMarket, $painPoints, $assumptionsToTest, $competitors, $differentiator, $userId]
                );
            } else {
                Database::execute(
                    "INSERT INTO idea_validation_context (user_id, value_proposition, target_market, pain_points, assumptions_to_test, competitors, differentiator)
                     VALUES (?, ?, ?, ?, ?, ?, ?)",
                    [$userId, $valueProposition, $targetMarket, $painPoints, $assumptionsToTest, $competitors, $differentiator]
                );
            }
            return true;
        }

        $existing = Database::queryOne(
            "SELECT id FROM idea_validation_context WHERE user_id = ? AND workspace_id = ? LIMIT 1",
            [$userId, $workspaceId]
        );

        if ($existing) {
            Database::execute(
                "UPDATE idea_validation_context SET
                    value_proposition = ?, target_market = ?, pain_points = ?,
                    assumptions_to_test = ?, competitors = ?, differentiator = ?
                 WHERE id = ?",
                [$valueProposition, $targetMarket, $painPoints, $assumptionsToTest, $competitors, $differentiator, (int) ($existing['id'] ?? 0)]
            );
        } else {
            Database::execute(
                "INSERT INTO idea_validation_context (workspace_id, user_id, value_proposition, target_market, pain_points, assumptions_to_test, competitors, differentiator)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [$workspaceId, $userId, $valueProposition, $targetMarket, $painPoints, $assumptionsToTest, $competitors, $differentiator]
            );
        }

        return true;
    }

    /**
     * Get context as a string for AI prompt (non-empty fields only).
     */
    public function getContextForPrompt(int $userId): string
    {
        $ctx = $this->get($userId);
        if (!$ctx) {
            return '';
        }

        $lines = [];
        if (!empty(trim($ctx['value_proposition'] ?? ''))) {
            $lines[] = 'Value proposition: ' . trim($ctx['value_proposition']);
        }
        if (!empty(trim($ctx['target_market'] ?? ''))) {
            $lines[] = 'Target market: ' . trim($ctx['target_market']);
        }
        if (!empty(trim($ctx['pain_points'] ?? ''))) {
            $lines[] = 'Pain points: ' . trim($ctx['pain_points']);
        }
        if (!empty(trim($ctx['assumptions_to_test'] ?? ''))) {
            $lines[] = 'Assumptions to test: ' . trim($ctx['assumptions_to_test']);
        }
        if (!empty(trim($ctx['competitors'] ?? ''))) {
            $lines[] = 'Competitors: ' . trim($ctx['competitors']);
        }
        if (!empty(trim($ctx['differentiator'] ?? ''))) {
            $lines[] = 'Differentiator: ' . trim($ctx['differentiator']);
        }

        return empty($lines) ? '' : "IDEA VALIDATION CONTEXT:\n" . implode("\n", $lines);
    }

    private function currentWorkspaceId(): int
    {
        return max(0, (int) (\CRM\Services\WorkspaceContext::currentWorkspaceId() ?? 0));
    }

    private function hasWorkspaceColumn(): bool
    {
        return Database::columnExists('idea_validation_context', 'workspace_id');
    }

    private function sanitizeText(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        $trimmed = trim((string) $value);
        if (strlen($trimmed) > self::MAX_FIELD_LENGTH) {
            $trimmed = substr($trimmed, 0, self::MAX_FIELD_LENGTH);
        }
        return $trimmed;
    }
}
