const { test, expect } = require('@playwright/test');
const {
    assertPageHealthy,
    createPageHealthMonitor,
    gotoAndWait,
    loginAsUser,
    runFixture,
    TEST_PASSWORD,
    uniqueSuffix
} = require('./helpers');

const TARGET_PAGES = [
    { path: 'companies.php', heading: 'Companies', primary: '.filters-card' },
    { path: 'activities.php', heading: 'Activities', primary: '.filters-card' },
    { path: 'calendar.php', heading: 'Calendar', primary: '.filters-card' },
    { path: 'analytics.php', heading: 'Analytics Dashboard', primary: '#timeframe_filter' },
    { path: 'attribution_reports.php', heading: 'Attribution Reports', primary: '.filters-card' },
    { path: 'currencies.php', heading: 'Currency Management', primary: '.table-card' },
    { path: 'settings_2fa.php', heading: 'Two-Factor Authentication', primary: '.premium-status-card' },
    { path: 'workspace_skills.php?module=calendar_meetings&setup_tab=calendar#setup', heading: 'Calendar & Meetings', primary: '[data-cm-calendar-meetings-setup], .cm-shell' },
    { path: 'email_assistant_capabilities.php', heading: 'Email Assistant Capabilities', primary: '.premium-doc-content' },
];

const SETTINGS_AUX_PAGES = [
    { path: 'settings_mobile_push.php', heading: 'Mobile Push Notifications', primary: '.card' },
    { path: 'settings_readiness_capture.php', heading: 'Business AI Readiness Capture', primary: '.settings-two-column-grid' },
];

const VIEWPORTS = [
    { width: 1280, height: 720 },
    { width: 900, height: 900 },
    { width: 390, height: 844 },
];

function assertNoEncodingArtifacts(text) {
    expect(text).not.toMatch(/[\u00e2\u00f0\u00c2\u00c3\ufffd]/);
}

test.describe('Smoke: Target page UX pass', () => {
    test('settings auxiliary pages render cleanly across responsive widths', async ({ page }) => {
        const health = createPageHealthMonitor(page);
        const superEmail = `${uniqueSuffix('target-pages-settings-super')}@example.test`;
        runFixture('ensure_user', {
            email: superEmail,
            password: TEST_PASSWORD,
            profile_role: 'admin',
            first_name: 'Target',
            last_name: 'Settings'
        });
        runFixture('ensure_user_role', { email: superEmail, role_slug: 'superadmin' });

        await loginAsUser(page, superEmail, TEST_PASSWORD);

        for (const viewport of VIEWPORTS) {
            await page.setViewportSize(viewport);

            for (const target of SETTINGS_AUX_PAGES) {
                await gotoAndWait(page, target.path);
                await expect(page.getByRole('heading', { name: target.heading }).first()).toBeVisible();
                await expect(page.locator(target.primary).first()).toBeVisible();
                assertNoEncodingArtifacts(await page.locator('body').innerText());
            }
        }

        await assertPageHealthy(page, health);
    });

    test('target pages render cleanly across responsive widths', async ({ page }) => {
        const health = createPageHealthMonitor(page);
        const superEmail = `${uniqueSuffix('target-pages-super')}@example.test`;
        runFixture('ensure_user', {
            email: superEmail,
            password: TEST_PASSWORD,
            profile_role: 'admin',
            first_name: 'Target',
            last_name: 'Reviewer'
        });
        runFixture('ensure_user_role', { email: superEmail, role_slug: 'superadmin' });

        await loginAsUser(page, superEmail, TEST_PASSWORD);

        for (const viewport of VIEWPORTS) {
            await page.setViewportSize(viewport);

            for (const target of TARGET_PAGES) {
                await gotoAndWait(page, target.path);
                await expect(page.getByRole('heading', { name: target.heading }).first()).toBeVisible();
                await expect(page.locator(target.primary).first()).toBeVisible();
                assertNoEncodingArtifacts(await page.locator('body').innerText());
            }
        }

        await assertPageHealthy(page, health);
    });

    test('target page primary controls remain usable', async ({ page }) => {
        const health = createPageHealthMonitor(page);
        const superEmail = `${uniqueSuffix('target-pages-controls')}@example.test`;
        runFixture('ensure_user', {
            email: superEmail,
            password: TEST_PASSWORD,
            profile_role: 'admin',
            first_name: 'Target',
            last_name: 'Controls'
        });
        runFixture('ensure_user_role', { email: superEmail, role_slug: 'superadmin' });

        await loginAsUser(page, superEmail, TEST_PASSWORD);

        await gotoAndWait(page, 'companies.php');
        await page.fill('#company_search', 'NoSuchCompanyForUxSmoke');
        await Promise.all([
            page.waitForURL(/companies\.php\?search=/),
            page.locator('.filters-card button[type="submit"]').click()
        ]);
        await expect(page.locator('body')).toContainText('No companies found');
        await expect(page.getByRole('link', { name: 'Clear' })).toBeVisible();

        await gotoAndWait(page, 'activities.php');
        await expect(page.locator('#type')).toBeVisible();
        await expect(page.locator('.activity-type-chips')).toBeVisible();

        await gotoAndWait(page, 'calendar.php');
        await expect(page.getByRole('link', { name: 'Month', exact: true })).toBeVisible();
        await expect(page.getByRole('link', { name: 'Week', exact: true })).toBeVisible();
        await expect(page.getByRole('link', { name: 'Day', exact: true })).toBeVisible();

        await gotoAndWait(page, 'analytics.php');
        await expect(page.locator('#timeframe_filter')).toBeVisible();
        await expect(page.locator('#user_scope_filter')).toBeVisible();

        await gotoAndWait(page, 'attribution_reports.php');
        await expect(page.locator('#attribution_model')).toBeVisible();
        await expect(page.locator('#date_from')).toBeVisible();
        await expect(page.locator('#date_to')).toBeVisible();

        await gotoAndWait(page, 'currencies.php');
        await page.getByRole('button', { name: 'Add Currency' }).click();
        await expect(page.locator('#create-currency-modal')).toBeVisible();
        await expect(page.locator('#currency-form')).toBeVisible();
        await page.getByRole('button', { name: 'Cancel' }).click();

        await gotoAndWait(page, 'settings_2fa.php');
        await expect(page.getByRole('button', { name: 'Enable Two-Factor Authentication' })).toBeVisible();

        await gotoAndWait(page, 'settings_mobile_push.php');
        await expect(page.getByRole('heading', { name: 'Mobile Push Notifications' })).toBeVisible();

        await gotoAndWait(page, 'settings_readiness_capture.php');
        await expect(page.getByRole('button', { name: 'Save Connector' })).toBeVisible();

        await gotoAndWait(page, 'workspace_skills.php?module=calendar_meetings&setup_tab=calendar#setup');
        await expect(page.locator('[data-cm-calendar-meetings-setup], .cm-shell')).toBeVisible();

        await gotoAndWait(page, 'email_assistant_capabilities.php');
        await expect(page.locator('.premium-doc-content')).toBeVisible();
        await expect(page.locator('.premium-doc-content h1, .premium-doc-content h2, .premium-doc-content h3').first()).toBeVisible();

        await assertPageHealthy(page, health);
    });
});
