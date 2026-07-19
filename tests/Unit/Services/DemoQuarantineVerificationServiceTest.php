<?php

namespace CRM\Tests\Unit\Services;

use CRM\Database;
use CRM\Services\DemoQuarantineVerificationService;
use CRM\Tests\DatabaseTestCase;

class DemoQuarantineVerificationServiceTest extends DatabaseTestCase
{
    public function testReportIncludesProtectedDemoAndQuarantineSummary(): void
    {
        $report = (new DemoQuarantineVerificationService())->verify();
        $summary = (array) ($report['summary'] ?? []);

        $this->assertContains($report['status'] ?? null, ['ok', 'warning', 'critical']);
        $this->assertSame(1, (int) ($summary['default_workspace_id'] ?? 0));
        $this->assertTrue((bool) ($summary['protected_demo_workspace_present'] ?? false));
        $this->assertGreaterThan(0, (int) ($summary['protected_demo_workspace_id'] ?? 0));
        $this->assertArrayHasKey('default_demo_scoped_rows', $summary);
        $this->assertArrayHasKey('default_demo_marker_rows', $summary);
        $this->assertArrayHasKey('findings', $report);
    }

    public function testDefaultWorkspaceDemoScopedRowsAreCritical(): void
    {
        Database::execute(
            "INSERT INTO contacts (workspace_id, demo_visibility, uuid, first_name, last_name, email, created_at, updated_at)
             VALUES (1, 'public_seed', UUID(), 'Scoped', 'Demo', ?, NOW(), NOW())",
            ['scoped-demo-' . bin2hex(random_bytes(4)) . '@example.test']
        );

        $report = (new DemoQuarantineVerificationService())->verify();

        $this->assertSame('critical', $report['status'] ?? null);
        $this->assertContains('default_workspace_demo_scoped_rows', $this->findingRules($report));
        $this->assertGreaterThanOrEqual(1, (int) ($report['summary']['default_demo_scoped_rows'] ?? 0));
    }

    public function testDefaultWorkspaceDemoSeedMarkersAreCritical(): void
    {
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, metadata_json, created_at, updated_at)
             VALUES (1, UUID(), 'Amina', 'Otieno', ?, ?, NOW(), NOW())",
            [
                'amina.' . bin2hex(random_bytes(4)) . '@metrodrive-demo.example',
                json_encode(['source' => 'metrodrive_demo_seed_v1'], JSON_UNESCAPED_SLASHES),
            ]
        );

        $report = (new DemoQuarantineVerificationService())->verify();

        $this->assertSame('critical', $report['status'] ?? null);
        $this->assertContains('default_workspace_demo_marker_rows', $this->findingRules($report));
        $this->assertGreaterThanOrEqual(1, (int) ($report['summary']['default_demo_marker_rows'] ?? 0));
    }

    public function testAllowedDefaultWorkspaceDemoProspectIsNotTreatedAsPollution(): void
    {
        Database::execute(
            "INSERT INTO contacts (workspace_id, uuid, first_name, last_name, email, lead_source, stage, metadata_json, created_at, updated_at)
             VALUES (1, UUID(), 'Demo', 'Visitor', ?, 'other', 'new', ?, NOW(), NOW())",
            [
                'consented.visitor.' . bin2hex(random_bytes(4)) . '@example.test',
                json_encode([
                    'source' => 'default_workspace_demo_visitor',
                    'default_workspace_contact_scope' => 'unqualified_demo_prospect',
                    'email_consent_source' => 'protected_demo',
                ], JSON_UNESCAPED_SLASHES),
            ]
        );

        $report = (new DemoQuarantineVerificationService())->verify();

        $this->assertNotContains('default_workspace_demo_marker_rows', $this->findingRules($report));
        $this->assertSame(0, (int) ($report['summary']['default_demo_marker_rows'] ?? -1));
    }

    public function testDefaultWorkspaceDemoSessionEntityMappingsAreCritical(): void
    {
        Database::execute(
            "INSERT INTO demo_visitor_sessions
                (session_uuid, workspace_id, consent_privacy, status, expires_at, purge_after)
             VALUES (UUID(), 1, 1, 'active', DATE_ADD(NOW(), INTERVAL 1 HOUR), DATE_ADD(NOW(), INTERVAL 1 DAY))"
        );
        $sessionId = (int) Database::lastInsertId();
        Database::execute(
            "INSERT INTO demo_session_entities (demo_session_id, workspace_id, table_name, record_id, visibility)
             VALUES (?, 1, 'contacts', 999999, 'session_private')",
            [$sessionId]
        );

        $report = (new DemoQuarantineVerificationService())->verify();
        $rules = $this->findingRules($report);

        $this->assertSame('critical', $report['status'] ?? null);
        $this->assertContains('default_workspace_demo_sessions', $rules);
        $this->assertContains('default_workspace_demo_session_entities', $rules);
    }

    /**
     * @param array<string,mixed> $report
     * @return array<int,string>
     */
    private function findingRules(array $report): array
    {
        return array_values(array_map(
            static fn(array $finding): string => (string) ($finding['rule'] ?? ''),
            array_filter((array) ($report['findings'] ?? []), 'is_array')
        ));
    }
}
