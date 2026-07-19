<?php
/**
 * Design System Tests
 * 
 * Tests for CSS/JS functionality
 */

namespace CRM\Tests\Unit\Frontend;

use CRM\Tests\TestCase;

class DesignSystemTest extends TestCase
{
    public function testDesignSystemFilesExist(): void
    {
        $this->assertFileExists(__DIR__ . '/../../../public/assets/css/design-system.css');
        $this->assertFileExists(__DIR__ . '/../../../public/assets/css/components.css');
        $this->assertFileExists(__DIR__ . '/../../../public/assets/css/main.css');
        $this->assertFileExists(__DIR__ . '/../../../public/assets/css/settings-ui.css');
        $this->assertFileExists(__DIR__ . '/../../../public/assets/js/app.js');
    }
    
    public function testDesignSystemCSSContainsVariables(): void
    {
        $css = file_get_contents(__DIR__ . '/../../../public/assets/css/design-system.css');
        
        $this->assertStringContainsString('--midnight-black', $css);
        $this->assertStringContainsString('--charcoal-grey', $css);
        $this->assertStringContainsString('--white', $css);
        $this->assertStringContainsString('--accent-blue', $css);
        $this->assertStringContainsString('--font-family', $css);
    }
    
    public function testComponentsCSSContainsClasses(): void
    {
        $css = file_get_contents(__DIR__ . '/../../../public/assets/css/components.css');
        
        $this->assertStringContainsString('.card', $css);
        $this->assertStringContainsString('.btn', $css);
        $this->assertStringContainsString('.form-control', $css);
        $this->assertStringContainsString('.timeline-item', $css);
    }
    
    public function testAppJSContainsFunctions(): void
    {
        $js = file_get_contents(__DIR__ . '/../../../public/assets/js/app.js');
        
        $this->assertStringContainsString('initScrollAnimations', $js);
        $this->assertStringContainsString('initFormValidation', $js);
        $this->assertStringContainsString('initCSRFTokens', $js);
        $this->assertStringContainsString("if (form.method.toLowerCase() !== 'post') return;", $js);
    }

    public function testReportAssistantValidationIsAnnounced(): void
    {
        $reportsPage = file_get_contents(__DIR__ . '/../../../public/reports.php');

        $this->assertNotFalse($reportsPage);
        $this->assertStringContainsString('aria-describedby="nl-report-status"', (string) $reportsPage);
        $this->assertStringContainsString('id="nl-report-status" role="status" aria-live="polite" aria-atomic="true"', (string) $reportsPage);
    }

    public function testAuthenticatedLayoutVersionsCoreApplicationJavascript(): void
    {
        $layout = file_get_contents(__DIR__ . '/../../../views/layouts/base.php');

        $this->assertNotFalse($layout);
        $this->assertStringContainsString("\$versionedAssetUrl('js/app.js')", (string) $layout);
        $this->assertStringNotContainsString("\$assetBase . '/js/app.js'", (string) $layout);
    }

    public function testSettingsUiStylesheetIsLoadedAcrossSettingsPages(): void
    {
        $settingsCss = file_get_contents(__DIR__ . '/../../../public/assets/css/settings-ui.css');
        $settingsPage = file_get_contents(__DIR__ . '/../../../public/settings.php');
        $twoFactorPage = file_get_contents(__DIR__ . '/../../../public/settings_2fa.php');
        $mobilePushPage = file_get_contents(__DIR__ . '/../../../public/settings_mobile_push.php');
        $readinessPage = file_get_contents(__DIR__ . '/../../../public/settings_readiness_capture.php');

        $this->assertNotFalse($settingsCss);
        $this->assertStringContainsString('.settings-page', (string) $settingsCss);
        $this->assertStringContainsString('.settings-nav-shell', (string) $settingsCss);
        $this->assertStringContainsString('.workspace-connect-card', (string) $settingsCss);
        $this->assertStringContainsString('.billing-status-hero', (string) $settingsCss);
        $this->assertStringContainsString('.settings-two-column-grid', (string) $settingsCss);
        $this->assertStringContainsString('.settings-page .content-card', (string) $settingsCss);
        $this->assertStringContainsString('box-sizing: border-box;', (string) $settingsCss);
        $this->assertStringContainsString('assets/css/settings-ui.css?v=20260718-mobile-content-card-sizing', (string) $settingsPage);

        foreach ([$settingsPage, $twoFactorPage, $mobilePushPage, $readinessPage] as $pageSource) {
            $this->assertNotFalse($pageSource);
            $this->assertStringContainsString('assets/css/settings-ui.css', (string) $pageSource);
        }
    }

    public function testBillingSettingsUiContractAndAdminHubArePresent(): void
    {
        $settingsCss = file_get_contents(__DIR__ . '/../../../public/assets/css/settings-ui.css');
        $billingPartial = file_get_contents(__DIR__ . '/../../../views/partials/settings_billing_saas.php');

        $this->assertNotFalse($settingsCss);
        $this->assertNotFalse($billingPartial);

        foreach ([
            '.billing-page-shell',
            '.billing-title-row',
            '.billing-status-chip',
            '.billing-notice',
            '.billing-notice--info',
            '.billing-notice--warning',
            '.billing-notice--success',
            '.billing-section',
            '.billing-section-head',
            '.billing-detail-grid',
            '.billing-detail-card',
            '.billing-notice--action',
            '.billing-notice-action-link',
            '.billing-hub-links',
            '.billing-hub-link',
            '.billing-hub-link.is-primary',
            '.billing-admin-diagnostics',
            '.billing-admin-advanced',
        ] as $className) {
            $this->assertStringContainsString($className, (string) $settingsCss);
        }

        $this->assertStringContainsString('class="billing-ui billing-page-shell"', (string) $billingPartial);
        $this->assertStringContainsString('Billing admin', (string) $billingPartial);
        $this->assertStringContainsString('Top up the workspace wallet to resume AI actions.', (string) $billingPartial);
        $this->assertStringContainsString('Review current limits, upgrades, and plugin unlocks.', (string) $billingPartial);
        $this->assertStringContainsString('Included AI Credits', (string) $billingPartial);
        $this->assertStringContainsString('Package capability:', (string) $billingPartial);
        $this->assertStringContainsString('Top-up packs are separate from recurring package AI Credits.', (string) $billingPartial);
        $this->assertStringContainsString('billing-notice-action-link', (string) $billingPartial);
        $this->assertStringContainsString('billing_payment_required.php?tab=packages#workspace-packages', (string) $billingPartial);
        $this->assertStringContainsString('billing_payment_required.php?tab=tokens#ai-token-refill', (string) $billingPartial);
        $this->assertStringContainsString('billing_payment_required.php?tab=activity', (string) $billingPartial);
        $this->assertStringContainsString('billing_payment_required.php?tab=help', (string) $billingPartial);
        $this->assertStringNotContainsString('billing_start_payment.php', (string) $billingPartial);
        $this->assertStringNotContainsString('name="billing_plan_price_id"', (string) $billingPartial);
        $this->assertStringNotContainsString('name="token_pack_price_id"', (string) $billingPartial);
        $this->assertStringNotContainsString('name="payment_mode"', (string) $billingPartial);
        $this->assertStringNotContainsString('name="customer_phone"', (string) $billingPartial);
        $this->assertStringNotContainsString('name="billing_action" value="grant_trial"', (string) $billingPartial);
        $this->assertStringNotContainsString('Trial controls', (string) $billingPartial);
        $this->assertStringNotContainsString('.billing-trial-advanced', (string) $settingsCss);
        $this->assertStringContainsString('name="billing_action" value="save_settings"', (string) $billingPartial);
        $this->assertStringContainsString('<details class="billing-history billing-admin-advanced">', (string) $billingPartial);
    }

    public function testCompassFreePageStatesFreePackageLimits(): void
    {
        $compassFreePage = file_get_contents(__DIR__ . '/../../../public/compass_free.php');

        $this->assertNotFalse($compassFreePage);
        $this->assertStringContainsString('50,000 one-time onboarding AI Credits', (string) $compassFreePage);
        $this->assertStringContainsString('Top-ups, Business Intelligence, and personal API keys stay locked until the workspace upgrades.', (string) $compassFreePage);
        $this->assertStringContainsString('<span>Top-ups</span><strong><?php echo $canTopUp ? \'Available\' : \'Locked\'; ?></strong>', (string) $compassFreePage);
        $this->assertStringContainsString('<span>BI Plugin</span><strong><?php echo $businessIntelligenceEnabled ? \'Included\' : \'Locked\'; ?></strong>', (string) $compassFreePage);
        $this->assertStringContainsString('<span>Personal API Key</span><strong><?php echo $personalApiKeyEnabled ? \'Included\' : \'Locked\'; ?></strong>', (string) $compassFreePage);
    }

    public function testSignupPageShowsCompassFreeCreditNoticeInsteadOfStarterCheckoutOption(): void
    {
        $signupPage = file_get_contents(__DIR__ . '/../../../public/signup.php');

        $this->assertNotFalse($signupPage);
        $this->assertStringContainsString('Your workspace starts on Compass Free with 50,000 onboarding AI Credits.', (string) $signupPage);
        $this->assertStringContainsString('No checkout is required during setup.', (string) $signupPage);
        $this->assertStringContainsString('class="signup-notice"', (string) $signupPage);
        $this->assertStringNotContainsString('name="starter_token_pack"', (string) $signupPage);
        $this->assertStringNotContainsString('Prepare an AI starter pack checkout', (string) $signupPage);
        $this->assertStringNotContainsString('Starter top-up prepared:', (string) $signupPage);
    }

    public function testPricingPageUsesAiCreditPackageModel(): void
    {
        $pricingPage = file_get_contents(__DIR__ . '/../../../public/pricing.php');

        $this->assertNotFalse($pricingPage);
        $this->assertStringContainsString('Plans unlock business capability. AI Credits control usage. Plugins expand access as the workspace matures.', (string) $pricingPage);
        $this->assertStringContainsString('Compass Free starts every workspace', (string) $pricingPage);
        $this->assertStringContainsString('AI Credit top-up packs', (string) $pricingPage);
        $this->assertStringContainsString('Business Intelligence unlocks on Founder Plus.', (string) $pricingPage);
        $this->assertStringContainsString('Personal API key access unlocks on Growth Studio and Scale Custom.', (string) $pricingPage);
        $this->assertStringContainsString('billing_payment_required.php?tab=packages#workspace-packages', (string) $pricingPage);
    }
}
