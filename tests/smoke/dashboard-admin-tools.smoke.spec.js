const { test, expect } = require('@playwright/test');
const {
    assertPageHealthy,
    createPageHealthMonitor,
    gotoAndWait,
    loginAsAdmin
} = require('./helpers');

test.describe('Smoke: Dashboard, Analytics, Settings', () => {
    test('dashboard and analytics render new scoped controls and automation battery', async ({ page }) => {
        const health = createPageHealthMonitor(page);

        await loginAsAdmin(page);

        await gotoAndWait(page, 'dashboard.php');
        await expect(page.locator('.hero-automation-shell')).toBeVisible();
        await expect(page.locator('.hero-automation-score-inside')).toContainText('%');
        await expect(page.locator('body')).toContainText(/Activation Progress|Revenue Momentum/);
        await page.locator('.hero-filter-trigger').click();
        await expect(page.locator('#dashboard_user_scope_filter')).toBeVisible();
        await expect(page.locator('#dashboard_user_scope_filter')).toContainText('My Dashboard');
        await expect(page.locator('#dashboard_user_scope_filter')).toContainText('All Users');

        await gotoAndWait(page, 'analytics.php');
        await expect(page.locator('#timeframe_filter')).toBeVisible();
        await expect(page.locator('#user_scope_filter')).toBeVisible();
        await expect(page.locator('#user_scope_filter')).toContainText('My Analytics');
        await expect(page.locator('#user_scope_filter')).toContainText('All Users');

        await assertPageHealthy(page, health);
    });

    test('settings still render admin navigation', async ({ page }) => {
        const health = createPageHealthMonitor(page);

        await loginAsAdmin(page);

        await gotoAndWait(page, 'settings.php');
        await expect(page.locator('body')).toContainText('Settings');
        await page.getByRole('button', { name: 'Change section' }).click();
        await expect(page.getByRole('link', { name: 'AI Services' })).toBeVisible();
        await expect(page.getByRole('link', { name: 'WhatsApp', exact: true })).toBeVisible();

        await assertPageHealthy(page, health);
    });
});
