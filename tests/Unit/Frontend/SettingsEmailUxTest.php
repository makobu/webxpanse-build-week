<?php

namespace CRM\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

class SettingsEmailUxTest extends TestCase
{
    private string $settingsSource;
    private string $smtpApiSource;

    protected function setUp(): void
    {
        parent::setUp();
        $this->settingsSource = (string) file_get_contents(__DIR__ . '/../../../public/settings.php');
        $this->smtpApiSource = (string) file_get_contents(__DIR__ . '/../../../api/test_smtp.php');
    }

    public function testSettingsEmailTabExplainsSystemMailScopesAndCopyAction(): void
    {
        $this->assertStringContainsString('Super Admin Email Hub', $this->settingsSource);
        $this->assertStringContainsString('System Mail sends workspace invites and platform notifications', $this->settingsSource);
        $this->assertStringContainsString('aria-label="How Email Works"', $this->settingsSource);
        $this->assertStringContainsString('Outreach Email', $this->settingsSource);
        $this->assertStringContainsString('Nurture Email', $this->settingsSource);
        $this->assertStringContainsString('Email Assistant', $this->settingsSource);
        $this->assertStringContainsString('Use active Outreach SMTP for System Mail', $this->settingsSource);
        $this->assertStringContainsString('value="copy_outreach_to_system"', $this->settingsSource);
        $this->assertStringContainsString('Advanced manual SMTP/IMAP', $this->settingsSource);
        $this->assertStringContainsString('Legacy config ignored', $this->settingsSource);
        $this->assertStringContainsString('EMAIL_ASSISTANT_SMTP_HOST', $this->settingsSource);
        $this->assertStringNotContainsString("connect Gmail/Google Workspace", $this->settingsSource);
        $this->assertStringNotContainsString("'cards' => ['main_email']", $this->settingsSource);
        $this->assertStringNotContainsString('Test Main Email Provider', $this->settingsSource);
        $this->assertStringNotContainsString('Manual SMTP Fallback', $this->settingsSource);
    }

    public function testSettingsEmailTabRendersInternalSubTabs(): void
    {
        $this->assertStringContainsString('aria-label="Email settings sections"', $this->settingsSource);
        $this->assertStringContainsString('name="email_section" value="<?php echo htmlspecialchars($emailSettingsSection); ?>"', $this->settingsSource);
        $this->assertStringContainsString('href="?tab=email&amp;email_section=<?php echo urlencode((string) $sectionKey); ?>"', $this->settingsSource);
        $this->assertStringContainsString('data-settings-email-section="<?php echo htmlspecialchars((string) $sectionKey); ?>"', $this->settingsSource);
        $this->assertStringContainsString("'system' => ['label' => 'System Mail'", $this->settingsSource);
        $this->assertStringContainsString("'workspace' => ['label' => 'Workspace Email'", $this->settingsSource);
        $this->assertStringContainsString("'warmup' => ['label' => 'Warmup'", $this->settingsSource);
        $this->assertStringContainsString('data-settings-email-panel="system"', $this->settingsSource);
        $this->assertStringContainsString('data-settings-email-panel="workspace"', $this->settingsSource);
        $this->assertStringContainsString('data-settings-email-panel="warmup"', $this->settingsSource);
    }

    public function testSettingsEmailPanelsSeparateSystemWorkspaceAndWarmupContent(): void
    {
        $systemPanel = $this->panelSource('system');
        $workspacePanel = $this->panelSource('workspace');
        $warmupPanel = $this->panelSource('warmup');

        $this->assertStringContainsString('Super Admin Email Hub', $systemPanel);
        $this->assertStringContainsString('System Mail Source', $systemPanel);
        $this->assertStringContainsString('Legacy `.env` SMTP and workspace email plugin settings are not used', $systemPanel);
        $this->assertStringContainsString('System Mail ignores legacy `.env` keys', $systemPanel);
        $this->assertStringContainsString('Advanced manual SMTP/IMAP', $systemPanel);
        $this->assertStringNotContainsString('name="email_cold_outreach_enabled"', $systemPanel);
        $this->assertStringNotContainsString('workspace_skills.php?module=email&setup_tab=outreach_email#setup', $systemPanel);

        $this->assertStringContainsString('Default Workspace Email', $workspacePanel);
        $this->assertStringContainsString('data-workspace-email-card="<?php echo htmlspecialchars((string) $card[\'key\']); ?>"', $workspacePanel);
        $this->assertStringContainsString('href="<?php echo htmlspecialchars((string) $card[\'url\']); ?>"', $workspacePanel);
        $this->assertStringContainsString("'url' => 'workspace_skills.php?module=email&setup_tab=outreach_email#setup'", $this->settingsSource);
        $this->assertStringContainsString("'url' => 'workspace_skills.php?module=email&setup_tab=nurture_email#setup'", $this->settingsSource);
        $this->assertStringContainsString("'url' => 'workspace_skills.php?module=email_assistant&setup_tab=identity#setup'", $this->settingsSource);
        $this->assertStringNotContainsString('name="smtp_host"', $workspacePanel);

        $this->assertStringContainsString('Foundation Deliverability Guide', $warmupPanel);
        $this->assertStringContainsString('Cold Outreach Warmup', $warmupPanel);
        $this->assertStringContainsString('name="email_cold_outreach_enabled"', $warmupPanel);
        $this->assertStringNotContainsString('name="smtp_host"', $warmupPanel);
    }

    public function testSettingsEmailSectionStateSurvivesGetAndPost(): void
    {
        $this->assertStringContainsString('$normalizeEmailSettingsSection = static function (?string $section): string', $this->settingsSource);
        $this->assertStringContainsString("in_array(\$section, ['system', 'workspace', 'warmup'], true) ? \$section : 'system'", $this->settingsSource);
        $this->assertStringContainsString("\$_POST['email_section'] ?? \$_GET['email_section'] ?? 'system'", $this->settingsSource);
        $this->assertStringContainsString(": (string) (\$_GET['email_section'] ?? 'system')", $this->settingsSource);
        $this->assertStringContainsString('<?php if ($emailSettingsSection !== \'workspace\'): ?>', $this->settingsSource);
        $this->assertStringContainsString('<?php if ($isSuperAdmin && $emailSettingsSection === \'system\'): ?>', $this->settingsSource);
    }

    public function testSettingsEmailTabUsesStrictOutreachReadinessForCopyButton(): void
    {
        $this->assertStringContainsString('$strictOutreachOutboundReady = $emailIntegrationService->isStrictRoleOutboundReady', $this->settingsSource);
        $this->assertStringContainsString('$strictNurtureOutboundReady = $emailIntegrationService->isStrictRoleOutboundReady', $this->settingsSource);
        $this->assertStringContainsString('$showCopyOutreachToSystem = $isSuperAdmin && !$systemMailOutboundReady && $strictOutreachOutboundReady', $this->settingsSource);
        $this->assertStringContainsString("getStrictManualSmtpConfigForRole(\n                        'outreach'", $this->settingsSource);
        $this->assertStringContainsString("EmailIntegrationService::SCOPE_MAIN_EMAIL", $this->settingsSource);
        $this->assertStringContainsString('Outreach Email SMTP is not ready yet', $this->settingsSource);
    }

    public function testSmtpTestModalShowsFriendlyDiagnosticsWithTechnicalDetails(): void
    {
        $this->assertStringContainsString('Testing System Mail and sending a test email', $this->settingsSource);
        $this->assertStringContainsString('result.diagnostic_title', $this->settingsSource);
        $this->assertStringContainsString('result.recommended_action', $this->settingsSource);
        $this->assertStringContainsString('Technical details', $this->settingsSource);
        $this->assertStringContainsString('result.technical_error', $this->settingsSource);
        $this->assertStringContainsString('escapeHtml(technical)', $this->settingsSource);
    }

    public function testSmtpApiAddsBackwardCompatibleDiagnosticFields(): void
    {
        $this->assertStringContainsString('EmailProviderDiagnosticService', $this->smtpApiSource);
        $this->assertStringContainsString('provider_summary', $this->smtpApiSource);
        $this->assertStringContainsString('legacy_assistant_env_settings', $this->smtpApiSource);
        $this->assertStringContainsString('diagnostic_title', $this->smtpApiSource);
        $this->assertStringContainsString('$diagnosticService->diagnose', $this->smtpApiSource);
        $this->assertStringContainsString('] + $diagnostic', $this->smtpApiSource);
        $this->assertStringContainsString('System Mail test email sent successfully', $this->smtpApiSource);
    }

    private function panelSource(string $panel): string
    {
        preg_match_all(
            '/<section data-settings-email-panel="' . preg_quote($panel, '/') . '".*?<\/section>/s',
            $this->settingsSource,
            $matches
        );

        return implode("\n", $matches[0] ?? []);
    }
}
