<?php

namespace CRM\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

class SettingsPageVideosTest extends TestCase
{
    public function testSettingsPageVideosTabIncludesDashboardGuideUploadControls(): void
    {
        $settings = file_get_contents(__DIR__ . '/../../../public/settings.php');
        $service = file_get_contents(__DIR__ . '/../../../services/MarketplacePageExplainerService.php');
        $settingsStyles = file_get_contents(__DIR__ . '/../../../public/assets/css/settings-ui.css');

        $this->assertNotFalse($settings);
        $this->assertNotFalse($service);
        $this->assertNotFalse($settingsStyles);
        $this->assertStringContainsString("'page_videos' => 'settings.general'", (string) $settings);
        $this->assertStringContainsString("'page_videos' => 'Videos'", (string) $settings);
        $this->assertStringContainsString('settings.php?tab=page_videos', (string) $settings);
        $this->assertStringContainsString('settingsPageDefinitions()', (string) $settings);
        $this->assertStringContainsString('foreach ($pageVideoDefinitions as $pageVideoKey => $pageVideoDefinition)', (string) $settings);
        $this->assertStringContainsString('MarketplaceVideoAssetService', (string) $settings);
        $this->assertStringContainsString('$pageVideoAssets->uploadManyFromFiles', (string) $settings);
        $this->assertStringContainsString('$pageVideoAssets->assignToPage', (string) $settings);
        $this->assertStringContainsString('$pageVideoAssets->deleteAsset', (string) $settings);
        $this->assertStringContainsString('page_video_action', (string) $settings);
        $this->assertStringContainsString('bulk_upload', (string) $settings);
        $this->assertStringContainsString('save_assignments', (string) $settings);
        $this->assertStringContainsString('class="premium-table page-videos-table"', (string) $settings);
        $this->assertStringContainsString('page-video-library-table', (string) $settings);
        $this->assertStringContainsString('Reusable video library', (string) $settings);
        $this->assertStringContainsString('Entrance demo video', (string) $settings);
        $this->assertStringContainsString('16:9 MP4 is recommended', (string) $settings);
        $this->assertStringContainsString('<th scope="col">Placement</th>', (string) $settings);
        $this->assertStringContainsString('<th scope="col">Library video</th>', (string) $settings);
        $this->assertStringContainsString('<th scope="col">Visibility</th>', (string) $settings);
        $this->assertStringContainsString('<th scope="col">Current video</th>', (string) $settings);
        $this->assertStringContainsString('<th scope="col">Usage</th>', (string) $settings);
        $this->assertStringContainsString('page-video-current-link', (string) $settings);
        $this->assertStringContainsString('page-video-empty">No video', (string) $settings);
        $this->assertStringContainsString('No videos in the library yet.', (string) $settings);
        $this->assertStringNotContainsString('class="page-video-card"', (string) $settings);
        $this->assertStringContainsString('$pageVideoDescription', (string) $settings);
        $this->assertStringContainsString('name="page_video_library_files[]"', (string) $settings);
        $this->assertStringContainsString('multiple data-page-video-file-input', (string) $settings);
        $this->assertStringContainsString('_page_video_asset_id', (string) $settings);
        $this->assertStringContainsString('accept="video/mp4,video/webm,video/quicktime,video/x-m4v"', (string) $settings);
        $this->assertStringContainsString('class="page-video-file-picker"', (string) $settings);
        $this->assertStringContainsString('data-page-video-file-name', (string) $settings);
        $this->assertStringContainsString('data-page-video-file-input', (string) $settings);
        $this->assertStringContainsString("input.files[0].name", (string) $settings);
        $this->assertStringContainsString("videos selected", (string) $settings);
        $this->assertStringContainsString('50 MB max', (string) $settings);
        $this->assertStringContainsString('$pageGuideVideoName = basename($pageGuideVideoPath)', (string) $settings);
        $this->assertStringContainsString('title="<?php echo htmlspecialchars($pageGuideVideoName); ?>"', (string) $settings);
        $this->assertStringContainsString('data-page-video-preview-open', (string) $settings);
        $this->assertStringContainsString('data-page-video-preview-modal', (string) $settings);
        $this->assertStringContainsString('data-page-video-preview-player', (string) $settings);
        $this->assertStringContainsString('openPageVideoPreview', (string) $settings);
        $this->assertStringNotContainsString('target="_blank" rel="noopener noreferrer" aria-label="Open current <?php echo htmlspecialchars($pageVideoLabel); ?> video"', (string) $settings);
        $this->assertStringContainsString('_page_explainer_active', (string) $settings);
        $this->assertStringNotContainsString('_page_explainer_remove_video', (string) $settings);
        $this->assertStringContainsString('Delete blocked while attached to pages.', (string) $settings);
        $this->assertStringContainsString('uploads/marketplace/page_video_library', file_get_contents(__DIR__ . '/../../../services/MarketplaceVideoAssetService.php'));
        $this->assertMatchesRegularExpression('/\.page-videos-form\s*\{[^}]*box-sizing:\s*border-box;/s', (string) $settingsStyles);
        $this->assertStringContainsString('Dashboard page guide', (string) $service);
        $this->assertStringContainsString('PAGE_ENTRANCE', (string) $service);
        $this->assertStringContainsString('Entrance landing demo', (string) $service);
        $this->assertStringContainsString('Tasks page guide', (string) $service);
        $this->assertStringContainsString('Inbox page guide', (string) $service);
        $this->assertStringContainsString('Contacts page guide', (string) $service);
        $this->assertStringContainsString('Marketplace page guide', (string) $service);
        $this->assertStringContainsString('Customer Nurture page guide', (string) $service);
        $this->assertStringContainsString('Deals page guide', (string) $service);
        $this->assertStringContainsString('Invoices & Quotes page guide', (string) $service);
        $this->assertStringContainsString('Companies page guide', (string) $service);
        $this->assertStringContainsString('Email Signatures page guide', (string) $service);
        $this->assertStringContainsString('Forms page guide', (string) $service);
        $this->assertStringContainsString('Email Templates page guide', (string) $service);
        $this->assertStringContainsString('Tags page guide', (string) $service);
        $this->assertStringContainsString('Analytics page guide', (string) $service);
        $this->assertStringContainsString('Targets page guide', (string) $service);
        $this->assertStringContainsString('Workflows page guide', (string) $service);
        $this->assertStringContainsString('Calendar page guide', (string) $service);
        $this->assertStringContainsString('Campaign Automation page guide', (string) $service);
        $this->assertStringContainsString('Reports page guide', (string) $service);
        $this->assertStringContainsString('Feature Documentation page guide', (string) $service);
        $this->assertStringContainsString('Users page guide', (string) $service);
        $this->assertStringContainsString('Access Profiles page guide', (string) $service);
        $this->assertStringContainsString('PAGE_WORKSPACE_TEAM', (string) $service);
        $this->assertStringContainsString('Workspace Team onboarding guide', (string) $service);
        $this->assertStringContainsString('AI Control Center page guide', (string) $service);
        $this->assertStringContainsString('Organization Intelligence page guide', (string) $service);
        $this->assertStringContainsString('Lead Scoring page guide', (string) $service);
        $this->assertStringContainsString('Enrichment Dashboard page guide', (string) $service);
        $this->assertStringContainsString('Predictive Analytics page guide', (string) $service);
        $this->assertStringContainsString('Workflow Analytics page guide', (string) $service);
        $this->assertStringContainsString('Attribution Reports page guide', (string) $service);
        $this->assertStringContainsString('Draft Reviews page guide', (string) $service);
        $this->assertStringContainsString('Email Assistant Runs page guide', (string) $service);
        $this->assertStringContainsString('Commercial Approvals page guide', (string) $service);
        $this->assertStringContainsString('Marketing Command Center page guide', (string) $service);
        $this->assertStringContainsString('Marketing Setup page guide', (string) $service);
        $this->assertStringContainsString('Content Studio page guide', (string) $service);
        $this->assertStringContainsString('Marketing Context Hub page guide', (string) $service);
        $this->assertStringContainsString('Marketing Admin Diagnostics page guide', (string) $service);
        $this->assertStringContainsString('Campaign Briefs page guide', (string) $service);
        $this->assertStringContainsString('Audience Segments page guide', (string) $service);
        $this->assertStringContainsString('Marketing Journeys page guide', (string) $service);
        $this->assertStringContainsString('Marketing Calendar page guide', (string) $service);
        $this->assertStringContainsString('Marketing Approval Workbench page guide', (string) $service);
        $this->assertStringContainsString('Landing Page Plans page guide', (string) $service);
        $this->assertStringContainsString('Marketing Assistants page guide', (string) $service);
        $this->assertStringContainsString('Marketing Operations page guide', (string) $service);
        $this->assertStringContainsString('Marketing Execution Control Center page guide', (string) $service);
        $this->assertStringContainsString('Campaign Launch Checklists page guide', (string) $service);
    }
}
