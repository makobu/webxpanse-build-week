<?php

namespace CRM\Tests\Unit\Documentation;

use PHPUnit\Framework\TestCase;

class MarketingReleaseDocsTest extends TestCase
{
    private function doc(string $filename): string
    {
        $path = __DIR__ . '/../../../docs/' . $filename;
        $contents = file_get_contents($path);

        $this->assertNotFalse($contents, $filename . ' should exist.');

        return str_replace(["\r\n", "\r"], "\n", (string) $contents);
    }

    public function testMarketingReleaseRunbookDocumentsGateAndControlledLiveBoundary(): void
    {
        $runbook = $this->doc('marketing-release-validation-runbook.md');

        $this->assertStringContainsString('407_create_marketing_live_proof_packs.sql', $runbook);
        $this->assertStringContainsString('php database\\migrations\\migrate.php', $runbook);
        $this->assertStringContainsString('vendor\\bin\\phpunit tests\\Unit\\Frontend\\MainNavigationTest.php tests\\Unit\\Modules\\MarketingTest.php', $runbook);
        $this->assertStringContainsString('vendor\\bin\\phpunit tests\\Unit\\Modules\\MarketingTest.php --filter Live', $runbook);
        $this->assertStringContainsString('marketing_admin.php', $runbook);
        $this->assertStringContainsString('marketing_execution.php', $runbook);
        $this->assertStringContainsString('Marketing AI Readiness', $runbook);
        $this->assertStringContainsString('four operating-loop prompt entries', $runbook);
        $this->assertStringContainsString('Controlled live execution requires: live policy enabled, ready connector, verified secret reference, passed preflight, consent/suppression clearance, manager approval, exact final confirmation, active worker/scheduler evidence, and manager-level permission.', $runbook);
        $this->assertStringContainsString('Controlled live email, SMS, WhatsApp, and webhook execution is allowed only when every live gate passes.', $runbook);
        $this->assertStringContainsString('Live Proof Packs', $runbook);
        $this->assertStringContainsString('Proof packs never include raw secrets, tokens, passwords, raw confirmation text, stack traces, full private payloads, or raw recipient values.', $runbook);
        $this->assertStringContainsString('Content tool suggestions are saved in tool-run history before a user applies one to a draft.', $runbook);
        $this->assertStringContainsString('Campaign brief generation fills reviewable fields only', $runbook);
        $this->assertStringContainsString('Landing page copy generation fills reviewable fields only', $runbook);
        $this->assertStringContainsString('Quality checks never rewrite drafts', $runbook);
        $this->assertStringContainsString('Assistant side effects remain draft-side only', $runbook);
        $this->assertStringContainsString('Strategy gap analysis creates planning queue suggestions only', $runbook);
        $this->assertStringContainsString('Campaign planner, performance analysis, and integration readiness reviews create planning queue suggestions only', $runbook);
        $this->assertStringContainsString('Phase 8 is pre-integration readiness, not connector delivery', $runbook);
        $this->assertStringContainsString('No live execution without policy, connector readiness, verified secret reference, preflight, consent/suppression clearance, manager approval, exact confirmation, scheduler/worker evidence, and manager-level permission.', $runbook);
        $this->assertStringContainsString('No external social publishing.', $runbook);
        $this->assertStringContainsString('No live social, ad, SEO, ranking, or public-hosting connector delivery in this release.', $runbook);
        $this->assertStringContainsString('No automatic replacement of user drafts by AI quality or assistant tools.', $runbook);
    }

    public function testMarketingAdminChecklistDocumentsRoleAndCleanupExpectations(): void
    {
        $checklist = $this->doc('marketing-admin-release-checklist.md');

        $this->assertStringContainsString('Viewer users can open Marketing pages but cannot create, edit, approve, archive, delete, or run cleanup.', $checklist);
        $this->assertStringContainsString('Starter data is opt-in only.', $checklist);
        $this->assertStringContainsString('source=marketing_onboarding_starter_pack', $checklist);
        $this->assertStringContainsString('Cleanup archives demo-supported records and hides demo-only brand/persona context from normal pickers.', $checklist);
        $this->assertStringContainsString('407_create_marketing_live_proof_packs.sql', $checklist);
        $this->assertStringContainsString('Marketing AI Readiness shows six core `marketing` prompts, four operating-loop prompts', $checklist);
        $this->assertStringContainsString('Email, SMS, WhatsApp, and allowlisted webhook execution can run live only through the controlled live gates', $checklist);
        $this->assertStringContainsString('Live proof packs are generated only by managers/admins and must exclude raw secrets', $checklist);
        $this->assertStringContainsString('AI content tool suggestions are saved before apply', $checklist);
        $this->assertStringContainsString('AI brief and landing copy generation fill reviewable fields only', $checklist);
        $this->assertStringContainsString('AI quality checks never rewrite drafts', $checklist);
        $this->assertStringContainsString('AI assistant side effects remain draft-side only', $checklist);
        $this->assertStringContainsString('AI strategy gap analysis saves planning queue suggestions only', $checklist);
        $this->assertStringContainsString('AI campaign planner, performance analysis, and integration readiness review save planning queue suggestions only', $checklist);
        $this->assertStringContainsString('External social publishing APIs.', $checklist);
        $this->assertStringContainsString('Live social, ad, SEO, ranking, or public-hosting connector delivery.', $checklist);
        $this->assertStringContainsString('Any live execution path that bypasses live policy', $checklist);
    }
}
