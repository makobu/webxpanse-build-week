const { test, expect } = require('@playwright/test');
const {
    assertPageHealthy,
    createPageHealthMonitor,
    gotoAndWait,
    loginAsAdmin,
    loginAsUser,
    runFixture,
    TEST_PASSWORD,
} = require('./helpers');

async function loginAsSuperAdmin(page) {
    // Reuse one Settings account so repeated smoke runs do not keep adding
    // disposable members to the default workspace assignee lists.
    const superEmail = 'settings-smoke-super@example.test';
    runFixture('ensure_user', {
        email: superEmail,
        password: TEST_PASSWORD,
        profile_role: 'admin',
        first_name: 'Settings',
        last_name: 'Super'
    });
    runFixture('ensure_user_role', { email: superEmail, role_slug: 'superadmin' });
    await loginAsUser(page, superEmail, TEST_PASSWORD);
    return superEmail;
}

async function openSettingsSectionPickerIfVisible(page) {
    const toggle = page.getByRole('button', { name: 'Change section' });
    if (await toggle.isVisible({ timeout: 1000 }).catch(() => false)) {
        await toggle.click();
    }
}

test.describe('Smoke: Settings', () => {
    test('settings navigation is stable and operational panels live in their tabs', async ({ page }) => {
        const health = createPageHealthMonitor(page);

        await loginAsSuperAdmin(page, 'settings-nav-super');
        await gotoAndWait(page, 'settings.php?tab=general');
        await openSettingsSectionPickerIfVisible(page);

        for (const label of ['General', 'Billing', 'Email', 'Package Settings']) {
            await expect(page.getByRole('link', { name: label, exact: true })).toBeVisible();
        }
        await expect(page.getByRole('link', { name: 'AI Services' })).toBeVisible();
        const whatsappNav = page.getByRole('link', { name: 'WhatsApp', exact: true });
        await expect(page.locator('body')).toContainText(/AI Coach|AI Guidance|Inbox Triage/i);
        await expect(page.locator('body')).not.toContainText('Cold Outreach Warmup');
        await expect(page.locator('body')).not.toContainText('Managed automation');

        await gotoAndWait(page, 'settings.php?tab=ai');
        await expect(page.locator('body')).toContainText('Managed automation');
        if ((await page.locator('body').innerText()).includes('Auto Admin off')) {
            await page.locator('input[name="auto_admin_enabled"]').check();
            await Promise.all([
                page.waitForLoadState('domcontentloaded'),
                page.getByRole('button', { name: 'Enable Auto Admin' }).click()
            ]);
            await gotoAndWait(page, 'settings.php?tab=ai');
        }
        await openSettingsSectionPickerIfVisible(page);
        await expect(page.getByRole('link', { name: 'AI Services' })).toBeVisible();

        await gotoAndWait(page, 'settings.php?tab=email');
        const emailSections = page.getByRole('navigation', { name: 'Email settings sections' });
        await expect(emailSections).toBeVisible();
        await expect(emailSections).toContainText('System Mail');
        await expect(emailSections).toContainText('Workspace Email');
        await expect(emailSections).toContainText('Warmup');
        await expect(page.getByRole('heading', { name: 'System Mail', exact: true })).toBeVisible();

        await gotoAndWait(page, 'settings.php?tab=email&email_section=workspace');
        const workspaceEmailPanel = page.locator('[data-settings-email-panel="workspace"]');
        await expect(workspaceEmailPanel).toBeVisible();
        await expect(workspaceEmailPanel).toContainText('Workspace Email');
        await expect(workspaceEmailPanel).toContainText('Outreach Email');
        await expect(workspaceEmailPanel).toContainText('Nurture Email');
        await expect(workspaceEmailPanel).toContainText('Email Assistant');
        await expect(workspaceEmailPanel).not.toContainText('Gmail client secret');
        await expect(workspaceEmailPanel).not.toContainText('Google Workspace client secret');

        await gotoAndWait(page, 'settings.php?tab=email&email_section=warmup');
        const emailWarmupPanel = page.locator('[data-settings-email-panel="warmup"]');
        await expect(emailWarmupPanel).toBeVisible();
        await expect(emailWarmupPanel).toContainText('Cold Outreach Warmup');
        await expect(emailWarmupPanel).toContainText('Enable email cold outreach cap');

        if (await whatsappNav.count()) {
            await gotoAndWait(page, 'settings.php?tab=whatsapp');
            const whatsappSections = page.getByRole('navigation', { name: 'WhatsApp settings sections' });
            await expect(whatsappSections).toBeVisible();
            await expect(page.getByRole('heading', { name: 'WhatsApp Setup Hub', exact: true })).toBeVisible();
            const whatsappOverview = page.locator('[data-whatsapp-settings-panel="overview"]');
            await expect(whatsappOverview).toBeVisible();
            await expect(whatsappOverview).toContainText('Workspace Connection');
            await expect(whatsappOverview).not.toContainText('WhatsApp Access Token');
            await expect(whatsappOverview).not.toContainText('Meta App Secret');

            await gotoAndWait(page, 'settings.php?tab=whatsapp&whatsapp_tab=guardrails');
            const whatsappGuardrails = page.locator('[data-whatsapp-settings-panel="guardrails"]');
            await expect(whatsappGuardrails).toBeVisible();
            await expect(whatsappGuardrails).toContainText('Cold Outreach Warmup');
            await expect(whatsappGuardrails).toContainText('Enable WhatsApp cold outreach cap');
        }

        await gotoAndWait(page, 'settings.php?tab=calendar');
        await expect(page.getByRole('heading', { name: 'Google Calendar OAuth is managed in Google Services', exact: true })).toBeVisible();
        await expect(page.locator('body')).toContainText('Calendar & Meetings setup');
        await expect(page.locator('body')).toContainText('Outlook calendar OAuth');

        await assertPageHealthy(page, health);
    });

    test('billing tab renders compact admin hub and payment portal links', async ({ page }) => {
        const health = createPageHealthMonitor(page);

        await loginAsSuperAdmin(page, 'settings-billing-super');
        await gotoAndWait(page, 'settings.php?tab=billing');

        await expect(page.locator('body')).toContainText('Billing admin');
        await expect(page.locator('body')).toContainText('Payment portal');
        await expect(page.locator('body')).toContainText('Workspace billing status');
        await expect(page.locator('body')).toContainText('AI usage snapshot');
        await expect(page.locator('body')).toContainText('Platform configuration');
        await expect(page.locator('body')).toContainText(/Open packages|Refill tokens|View activity|Open help/i);
        await expect(page.locator('body')).not.toContainText('Available subscription plans');
        await expect(page.locator('body')).not.toContainText('Prepaid token packs');
        await expect(page.locator('body')).not.toContainText('History: wallet ledger');
        await expect(page.locator('body')).not.toContainText('Recent billing transactions');
        await expect(page.locator('body')).not.toContainText('Recent checkout sessions');

        await assertPageHealthy(page, health);
    });

    test('package settings tab is superadmin-only and renders catalog controls', async ({ page }) => {
        const health = createPageHealthMonitor(page);

        await loginAsAdmin(page);
        await gotoAndWait(page, 'settings.php?tab=package_settings');
        await openSettingsSectionPickerIfVisible(page);
        await expect(page.getByRole('link', { name: 'Package Settings', exact: true })).toHaveCount(0);
        await expect(page.locator('body')).not.toContainText('Subscription Packages');
        await expect(page.locator('body')).not.toContainText('AI Credit Packs');
        await assertPageHealthy(page, health);

        await gotoAndWait(page, 'logout.php');
        await loginAsSuperAdmin(page, 'settings-package-super');
        await gotoAndWait(page, 'settings.php?tab=package_settings');

        await expect(page.locator('body')).toContainText('Package Settings');
        await expect(page.locator('body')).toContainText('Payment Methods');
        await expect(page.locator('body')).toContainText('Packages');
        await expect(page.locator('body')).toContainText('Create Package');
        await expect(page.locator('body')).toContainText(/Clone Package|No subscription packages are configured yet/);
        await expect(page.locator('body')).toContainText(/Archive\/Delete|No subscription packages are configured yet/);
        await expect(page.locator('body')).toContainText(/Customer-facing preview|No subscription packages are configured yet/);
        const archiveConfirmations = page.locator('input[placeholder="Type ARCHIVE"]');
        if (await archiveConfirmations.count()) {
            await expect(archiveConfirmations.first()).toBeVisible();
        } else {
            await expect(page.locator('body')).toContainText('No subscription packages are configured yet');
        }
        await expect(page.locator('body')).toContainText('Package description');
        await expect(page.locator('body')).toContainText('Active');
        await expect(page.locator('body')).toContainText('Default');
        await expect(page.locator('input[name="reason"]').first()).toBeVisible();

        await gotoAndWait(page, 'settings.php?tab=package_settings&package_section=ai_credit_packs');
        await expect(page.locator('#ai-credit-packs')).toContainText('Create AI Credit Pack');

        await gotoAndWait(page, 'settings.php?tab=package_settings&package_section=feature_catalog');
        await expect(page.locator('#feature-catalog')).toContainText('Create or Update Feature');
        await expect(page.locator('#feature-catalog')).toContainText('Save Feature Definition');

        await gotoAndWait(page, 'settings.php?tab=package_settings&package_section=payment_methods');
        await expect(page.locator('#payment-methods')).toContainText('Save Payment Methods');

        await gotoAndWait(page, 'settings.php?tab=package_settings&package_section=catalog_health');
        await expect(page.locator('#catalog-health')).toContainText('Export Catalog Health CSV');

        await gotoAndWait(page, 'settings.php?tab=package_settings&package_section=analytics');
        await expect(page.locator('#package-analytics')).toContainText('Apply Window');
        await expect(page.locator('#package-analytics')).toContainText('Export Analytics CSV');

        await assertPageHealthy(page, health);
    });

    test('super admin billing hub no longer renders package catalog edit forms', async ({ page }) => {
        const health = createPageHealthMonitor(page);

        await loginAsSuperAdmin(page, 'settings-billing-hub-super');
        await gotoAndWait(page, 'super_admin_billing.php');

        await expect(page.locator('body')).toContainText('Package catalog settings moved into Settings');
        await expect(page.locator('body')).toContainText('Workspace Subscriptions');
        await expect(page.locator('body')).toContainText('AI Credit Operations');
        await expect(page.locator('body')).toContainText('Credit Ledger');
        await expect(page.locator('body')).toContainText('Billing Audit');
        await expect(page.locator('body')).not.toContainText('Save Plan');
        await expect(page.locator('body')).not.toContainText('Save Pack');
        await expect(page.locator('body')).not.toContainText('Save Payment Methods');

        await assertPageHealthy(page, health);
    });

    test('reset controls stay reserved for Super Admin settings access', async ({ page }) => {
        const health = createPageHealthMonitor(page);

        await loginAsAdmin(page);
        await gotoAndWait(page, 'settings.php?tab=general');
        await expect(page.locator('body')).toContainText('Application Name');
        await expect(page.locator('body')).not.toContainText('Super Admin Platform Reset');
        await assertPageHealthy(page, health);

        await gotoAndWait(page, 'logout.php');
        await loginAsSuperAdmin(page, 'settings-reset-super');
        await gotoAndWait(page, 'settings.php?tab=general');
        await expect(page.locator('body')).toContainText('Workspace Data Reset');
        await expect(page.locator('body')).toContainText('Super Admin Platform Reset');
        await gotoAndWait(page, 'settings.php?tab=page_videos');
        await expect(page.locator('body')).toContainText('Entrance demo video');
        await expect(page.locator('body')).toContainText('Reusable video library');
        await expect(page.locator('body')).toContainText('Dashboard page guide');
        await assertPageHealthy(page, health);
    });
});
