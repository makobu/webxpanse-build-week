<?php

namespace CRM\Services;

use CRM\Database;
use RuntimeException;

/**
 * Workspace-scoped read model for the Design Marketplace plugin.
 *
 * The existing Marketing and Forms modules remain the writers for their
 * respective records. This service gives the installed Design plugin one
 * coherent, workspace-safe runtime surface across those tools.
 */
class DesignService
{
    private int $workspaceId;

    public function __construct(?int $workspaceId = null)
    {
        $this->workspaceId = $workspaceId ?? (int) (WorkspaceContext::currentWorkspaceId() ?? 0);
        if ($this->workspaceId <= 0) {
            throw new RuntimeException('Select a workspace before using Design.');
        }
    }

    public function assertInstalled(): void
    {
        if (!(new WorkspaceSkillInstallService())->isInstalled($this->workspaceId, WorkspaceSkillCatalogService::PLUGIN_DESIGN)) {
            throw new RuntimeException('Install the Design plugin before using landing pages, forms, and creative tools.');
        }
    }

    /**
     * @return array<string,int>
     */
    public function dashboardSummary(): array
    {
        $pagesWithForms = Database::tableExists('marketing_landing_pages')
            && Database::columnExists('marketing_landing_pages', 'form_id')
            ? $this->countRows('marketing_landing_pages', "status <> 'archived' AND form_id IS NOT NULL AND form_id > 0")
            : 0;

        return [
            'landing_pages' => $this->countRows('marketing_landing_pages', "status <> 'archived'"),
            'published_pages' => $this->countRows('marketing_landing_page_publications', "status = 'published'"),
            'forms' => $this->countRows('forms'),
            'pages_with_forms' => $pagesWithForms,
            'submissions' => $this->countRows('form_submissions'),
            'creative_assets' => $this->countRows('marketing_media_files') + $this->countRows('marketing_assets'),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function recentLandingPages(int $limit = 8): array
    {
        if (!Database::tableExists('marketing_landing_pages')) {
            return [];
        }

        $limit = min(max($limit, 1), 25);
        $publicationJoin = Database::tableExists('marketing_landing_page_publications')
            ? "LEFT JOIN marketing_landing_page_publications pub ON pub.workspace_id = lp.workspace_id AND pub.landing_page_id = lp.id"
            : '';
        $publicationColumns = $publicationJoin !== ''
            ? ", COALESCE(pub.status, 'unpublished') AS publication_status, COALESCE(pub.readiness_score, 0) AS readiness_score"
            : ", 'unpublished' AS publication_status, 0 AS readiness_score";

        return Database::query(
            "SELECT lp.id, lp.title, lp.slug, lp.status, lp.updated_at{$publicationColumns}
             FROM marketing_landing_pages lp
             {$publicationJoin}
             WHERE lp.workspace_id = ? AND lp.status <> 'archived'
             ORDER BY lp.updated_at DESC, lp.id DESC
             LIMIT {$limit}",
            [$this->workspaceId]
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function recentForms(int $limit = 8): array
    {
        if (!Database::tableExists('forms')) {
            return [];
        }

        $limit = min(max($limit, 1), 25);
        $submissionColumn = Database::tableExists('form_submissions')
            ? ", (SELECT COUNT(*) FROM form_submissions fs WHERE fs.workspace_id = f.workspace_id AND (fs.form_id = f.uuid OR fs.form_definition_id = f.id)) AS submission_count"
            : ', 0 AS submission_count';

        return Database::query(
            "SELECT f.id, f.uuid, f.name, f.updated_at{$submissionColumn}
             FROM forms f
             WHERE f.workspace_id = ?
             ORDER BY f.updated_at DESC, f.id DESC
             LIMIT {$limit}",
            [$this->workspaceId]
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function readiness(int $userId): array
    {
        return (new WorkspaceSkillInstallService())->buildReadinessForModule(
            $this->workspaceId,
            $userId,
            WorkspaceSkillCatalogService::PLUGIN_DESIGN
        );
    }

    private function countRows(string $table, string $condition = '1=1'): int
    {
        if (!Database::tableExists($table) || !Database::columnExists($table, 'workspace_id')) {
            return 0;
        }

        $row = Database::queryOne(
            "SELECT COUNT(*) AS count FROM {$table} WHERE workspace_id = ? AND ({$condition})",
            [$this->workspaceId]
        );

        return (int) ($row['count'] ?? 0);
    }
}
