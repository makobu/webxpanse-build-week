<?php

namespace CRM\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

class OrganizationIntelligenceLanguageLevelUiTest extends TestCase
{
    public function testVoiceSettingsUseNeutralLanguageLevelLabels(): void
    {
        $settings = file_get_contents(__DIR__ . '/../../../public/settings.php');

        $this->assertNotFalse($settings);
        $this->assertStringContainsString('Language level', (string) $settings);
        $this->assertStringContainsString('WorkspaceLanguageLevelService::DEFAULT_LEVEL', (string) $settings);
        $this->assertStringContainsString('Controls jargon density and explanation depth across Clarity and plugin AI outputs.', (string) $settings);
        $this->assertStringNotContainsString('>Reading level<', (string) $settings);
        $this->assertStringNotContainsString('>College<', (string) $settings);
        $this->assertStringNotContainsString('>Simple<', (string) $settings);
    }

    public function testOrganizationIntelligenceShowsActiveLanguageContext(): void
    {
        $analytics = file_get_contents(__DIR__ . '/../../../public/hr_analytics.php');

        $this->assertNotFalse($analytics);
        $this->assertStringContainsString('WorkspaceLanguageLevelService', (string) $analytics);
        $this->assertStringContainsString('hr-language-readout', (string) $analytics);
        $this->assertStringContainsString('$languageLevelContext[\'label\']', (string) $analytics);
    }

    public function testOrganizationIntelligenceUsesExecutiveRooms(): void
    {
        $analytics = file_get_contents(__DIR__ . '/../../../public/hr_analytics.php');
        $styles = file_get_contents(__DIR__ . '/../../../public/assets/css/hr-analytics.css');

        $this->assertNotFalse($analytics);
        $this->assertNotFalse($styles);
        $this->assertStringContainsString('Organization Intelligence', (string) $analytics);
        $this->assertStringContainsString('Executive Brief', (string) $analytics);
        $this->assertStringContainsString('People & HR', (string) $analytics);
        $this->assertStringContainsString('Executive Analytics', (string) $analytics);
        $this->assertStringContainsString('Direction & Priorities', (string) $analytics);
        $this->assertStringContainsString('$requestInput[\'priority_view\'] ?? \'leadership\'', (string) $analytics);
        $this->assertStringContainsString('hr-priority-subtabs', (string) $analytics);
        $this->assertStringContainsString('hr-priority-view-leadership', (string) $analytics);
        $this->assertStringContainsString('hr-priority-view-action-plan', (string) $analytics);
        $this->assertStringContainsString('hr-priority-view-tasks', (string) $analytics);
        $this->assertStringContainsString('hr-priority-view-access', (string) $analytics);
        $this->assertStringContainsString('hr-priority-view-diagnostics', (string) $analytics);
        $this->assertStringContainsString('$renderHiddenState(\'priorities\', \'action_plan\')', (string) $analytics);
        $this->assertStringContainsString('$renderHiddenState(\'priorities\', \'tasks\')', (string) $analytics);
        $this->assertStringContainsString('$renderHiddenState(\'priorities\', \'access\')', (string) $analytics);
        $this->assertStringContainsString('$canManageUsers && $assignableRoles !== []', (string) $analytics);
        $this->assertStringNotContainsString('hr-function-context-band', (string) $analytics);
        $this->assertStringContainsString('hr-executive-header', (string) $analytics);
        $this->assertStringContainsString('hr-scope-drawer', (string) $analytics);
        $this->assertStringContainsString('hr-trend-panel', (string) $analytics);
        $this->assertStringContainsString('Trend Analysis', (string) $analytics);
        $this->assertStringContainsString('Conversion Funnel', (string) $analytics);
        $this->assertStringContainsString('No conversion data captured for this scope', (string) $analytics);
        $this->assertStringContainsString('System active time', (string) $analytics);
        $this->assertStringContainsString('$formatHoursFromMinutes', (string) $analytics);
        $this->assertStringNotContainsString('Time in system', (string) $analytics);
        $this->assertStringNotContainsString('active min', (string) $analytics);
        $this->assertStringNotContainsString('active minutes', (string) $analytics);
        $this->assertStringContainsString('hr-priority-table-row', (string) $analytics);
        $this->assertStringContainsString('organization-intelligence-dark-shell', (string) $analytics);
        $this->assertStringContainsString('body.organization-intelligence-dark-shell .navbar', (string) $styles);
        $this->assertStringContainsString('body.organization-intelligence-dark-shell .app-footer', (string) $styles);
        $this->assertStringContainsString('body.organization-intelligence-dark-shell .hr-actions-draft', (string) $styles);
        $this->assertStringContainsString('body.organization-intelligence-dark-shell .hr-actions-context', (string) $styles);
        $this->assertStringContainsString('body.organization-intelligence-dark-shell .hr-priority-subtabs', (string) $styles);
        $this->assertStringContainsString('body.organization-intelligence-dark-shell .hr-priority-tab.is-active', (string) $styles);
        $this->assertStringContainsString('body.organization-intelligence-dark-shell .hr-strategy-context', (string) $styles);
        $this->assertStringContainsString('body.organization-intelligence-dark-shell .hr-swot-mini-signal', (string) $styles);
        $this->assertStringContainsString('body.organization-intelligence-dark-shell .chat-bubble-btn', (string) $styles);
        $this->assertStringContainsString('body.organization-intelligence-dark-shell .chat-bubble-panel', (string) $styles);
        $this->assertStringContainsString('chat-bubble-container[data-chat-mode="executive-suite"]', (string) $styles);
        $this->assertStringNotContainsString('class="hr-filter-panel hr-scope-bar"', (string) $analytics);
        $this->assertStringNotContainsString('class="hr-context-strip hr-executive-scope"', (string) $analytics);
        $this->assertStringNotContainsString('Organization Intelligence Center', (string) $analytics);
        $this->assertStringNotContainsString('$activeTab === \'swot\'', (string) $analytics);
        $this->assertStringNotContainsString('Organization SWOT', (string) $analytics);
    }

    public function testManagerBriefUsesFormattedCopyPanel(): void
    {
        $analytics = file_get_contents(__DIR__ . '/../../../public/hr_analytics.php');
        $styles = file_get_contents(__DIR__ . '/../../../public/assets/css/hr-analytics.css');

        $this->assertNotFalse($analytics);
        $this->assertNotFalse($styles);
        $this->assertStringContainsString('class="hr-manager-brief-head"', (string) $analytics);
        $this->assertStringContainsString('class="hr-manager-brief-body"', (string) $analytics);
        $this->assertStringContainsString('id="hr-manager-brief-copy"', (string) $analytics);
        $this->assertStringContainsString('data-copy-label', (string) $analytics);
        $this->assertStringContainsString('typeof target.value === \'string\' ? target.value : (target.textContent || \'\')', (string) $analytics);
        $this->assertStringNotContainsString('<textarea id="hr-manager-brief-copy"', (string) $analytics);
        $this->assertStringContainsString('.hr-manager-brief-head', (string) $styles);
        $this->assertStringContainsString('.hr-manager-brief-body', (string) $styles);
        $this->assertStringContainsString('white-space: pre-line', (string) $styles);
        $this->assertStringContainsString('body.organization-intelligence-dark-shell .hr-manager-brief-body', (string) $styles);
    }
}
