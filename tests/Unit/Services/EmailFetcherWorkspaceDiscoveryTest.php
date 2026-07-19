<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\EmailFetcher;
use CRM\Tests\DatabaseTestCase;

class EmailFetcherWorkspaceDiscoveryTest extends DatabaseTestCase
{
    public function testAssistantWorkspaceDiscoveryUsesEnabledWorkspacePluginConfig(): void
    {
        Database::execute('DELETE FROM workspace_assistant_configs');
        Database::execute(
            "INSERT INTO workspaces (id, uuid, name, slug, status, plan_status, created_at, updated_at)
             VALUES (2, '00000000-0000-4000-8000-000000000002', 'Second Workspace', 'second-assistant-workspace', 'active', 'active', NOW(), NOW()),
                    (3, '00000000-0000-4000-8000-000000000003', 'Archived Workspace', 'archived-assistant-workspace', 'archived', 'inactive', NOW(), NOW())
             ON DUPLICATE KEY UPDATE status = VALUES(status), updated_at = NOW()"
        );

        foreach ([1 => true, 2 => false, 3 => true] as $workspaceId => $imapEnabled) {
            Database::execute(
                "INSERT INTO workspace_assistant_configs (workspace_id, assistant_type, enabled, settings_json)
                 VALUES (?, 'email', 1, ?)",
                [$workspaceId, json_encode(['imap_enabled' => $imapEnabled])]
            );
        }

        $this->assertSame([1], EmailFetcher::getAssistantEnabledWorkspaceIds());
    }
}
