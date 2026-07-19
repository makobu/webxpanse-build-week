const { test, expect } = require('@playwright/test');
const {
    createPageHealthMonitor,
    assertPageHealthy,
    loginAsAdmin
} = require('./helpers');

test.describe('Smoke: Auth and Dashboard', () => {
    test('login succeeds and dashboard loads with working navigation shell', async ({ page }) => {
        const health = createPageHealthMonitor(page);

        await loginAsAdmin(page);
        await expect(page).toHaveURL(/dashboard\.php|index\.php/i);
        await expect(page.locator('.navbar')).toBeVisible();

        const navLinks = page.locator('.navbar a');
        await expect(navLinks.first()).toBeVisible();

        await assertPageHealthy(page, health);
    });
});
